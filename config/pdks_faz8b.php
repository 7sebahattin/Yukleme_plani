<?php
// =========================================================
// config/pdks_faz8b.php — Faz 8B Mesai Değerlendirme + Ücretlendirme
//
// ⚠ Faz 9C / H-02 KAPANIŞI (iş kuralı KÖKTEN değişti — kullanıcının açık
// talimatı): SABİT 08:00 başlangıç / 17:00 planlı bitiş YOKTUR, hiçbir
// saat-kilidi (clock-of-day boundary) YOKTUR. İşçi 08:00'de de 10:00'da da
// başlayabilir — önemli olan GEÇEN SÜRENİN çavuşun ANLAŞMALI NORMAL GÜNLÜK
// ÇALIŞMA SÜRESİYLE (foremen.normal_work_minutes → oturum açılırken
// daily_work_sessions.normal_work_minutes_snapshot'a DONAR, bkz. config/
// pdks_gunluk.php) karşılaştırılmasıdır. Bu süre ÇAVUŞ bazlıdır, işçi
// tipinden (KADIN/ERKEK) BAĞIMSIZDIR.
//
// İş kuralı (YENİ):
//   - Otomatik Tam: geçen süre (dk) >= oturumun normal_work_minutes_snapshot
//     değeri. 08:00–17:00, 09:00–18:00, 10:00–19:00 (9h anlaşma) HEPSİ Tam —
//     hiçbiri saat DEĞİL, SÜRE eşleşiyor diye.
//   - Bunun altındaki süreler muhasebe Tam/Yarım kararına düşer. ÇIKIŞ asla
//     engellenmez. Geç giriş cezası YOKTUR — anlaşmalı süre tamamlanınca FM
//     hesabı aynı kalır.
//   - Fazla mesai yalnız normal süre TAMAMLANDIKTAN sonra ölçülür (PLANLI
//     bir saatten DEĞİL). İlk 15 dk tolerans: normali aşan 15. dakikaya
//     kadar FM yok; sonrasında başlayan her 60 dk'lık dilim 1 saat sayılır
//     (16–75 dk = 1 saat, 76–135 dk = 2 saat, ...).
//     Her FM ADAYI muhasebe onayına düşer; muhasebe HESAPLANAN adayın
//     ALTINDA bir "Onaylanan FM Saati" belirleyebilir (Faz 9C / UX-03),
//     üstüne ÇIKAMAZ.
//   - Bir kart aynı gün BİRDEN FAZLA ardışık döneme sahip olabilir — HER
//     dönem KENDİ geçen süresinden değerlendirilir; ikinci (geç saatli) kısa
//     bir dönem salt SAATİ GEÇ diye FM SAYILMAZ.
//   - Çavuş ücretinde Tam, Yarım ve FM ücreti ayrı tanımlanır. FM tipi
//     hourly (saatlik) veya fixed (sabit toplam) olabilir — bu fiyat mimarisi
//     Faz 9C'de DEĞİŞMEDİ, yalnız FM SÜRESİNİN nasıl hesaplandığı değişti.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_hakedis.php';

defined('PDKS_FAZ8B_AKTIF') || define('PDKS_FAZ8B_AKTIF', true);
// ⚠ Faz 9C: bu artık yalnız "şema/foreman ayarı hiç yoksa" düşülecek SON
// ÇARE varsayılandır (bkz. pdks_faz8b_donem_finans_durumu) — OTORİTER kaynak
// oturumun normal_work_minutes_snapshot'ıdır, bu sabit DEĞİL.
defined('PDKS_FAZ8B_NORMAL_DK') || define('PDKS_FAZ8B_NORMAL_DK', 540);
defined('PDKS_FAZ8B_TOLERANS_DK') || define('PDKS_FAZ8B_TOLERANS_DK', 15);
// Normal süre için makul işletme aralığı (Faz 9C madde 4): 1-24 saat.
defined('PDKS_FAZ8B_SURE_MIN_DK') || define('PDKS_FAZ8B_SURE_MIN_DK', 60);
defined('PDKS_FAZ8B_SURE_MAX_DK') || define('PDKS_FAZ8B_SURE_MAX_DK', 1440);

// =========================================================
// ŞEMA / MİGRASYON
// =========================================================

function pdks_faz8b_tablo_var(PDO $pdo, string $tablo): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function pdks_faz8b_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    return pdks_gunluk_kolon_var($pdo, $tablo, $kolon);
}

function pdks_faz8b_kolon_ekle(
    PDO $pdo,
    string $tablo,
    string $kolon,
    string $tanim,
    ?string $after = null
): array {
    if (!pdks_faz8b_tablo_var($pdo, $tablo)) {
        return ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => 'Tablo bulunamadı.'];
    }
    if (pdks_faz8b_kolon_var($pdo, $tablo, $kolon)) {
        return ['adim' => "$tablo.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
    }

    $sql = "ALTER TABLE `{$tablo}` ADD COLUMN `{$kolon}` {$tanim}";
    if ($after !== null && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= " AFTER `{$after}`";
    }

    try {
        $pdo->exec($sql);
        pdks_gunluk_kolon_onbellek_temizle($pdo, $tablo);
        return ['adim' => "$tablo.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
    } catch (PDOException $e) {
        error_log('[pdks_faz8b_migrate] ' . $tablo . '.' . $kolon . ': ' . $e->getMessage());
        return ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => $e->getMessage(), 'sql' => $sql];
    }
}

function pdks_faz8b_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $spec = [
        ['foreman_worker_rates', 'half_day_rate', 'DECIMAL(12,2) NULL DEFAULT NULL', 'daily_rate'],
        ['foreman_worker_rates', 'overtime_mode', 'VARCHAR(10) NULL DEFAULT NULL', 'half_day_rate'],
        ['foreman_worker_rates', 'overtime_rate', 'DECIMAL(12,2) NULL DEFAULT NULL', 'overtime_mode'],

        ['daily_worker_work_periods', 'approved_by_user_id', 'INT NULL DEFAULT NULL', 'approved_attendance_class'],
        ['daily_worker_work_periods', 'approved_at', 'DATETIME NULL DEFAULT NULL', 'approved_by_user_id'],
        ['daily_worker_work_periods', 'overtime_approved', 'TINYINT(1) NULL DEFAULT NULL', 'approved_at'],
        ['daily_worker_work_periods', 'overtime_approved_by_user_id', 'INT NULL DEFAULT NULL', 'overtime_approved'],
        ['daily_worker_work_periods', 'overtime_approved_at', 'DATETIME NULL DEFAULT NULL', 'overtime_approved_by_user_id'],
        // Faz 9C / UX-03: muhasebe artık HESAPLANAN FM adayının altında bir
        // "Onaylanan FM Saati" belirleyebilir (yalnız hepsini onayla/reddet
        // DEĞİL) — bkz. pdks_faz8b_degerlendirme_kaydet().
        ['daily_worker_work_periods', 'overtime_approved_hours', 'INT NULL DEFAULT NULL', 'overtime_approved_at'],

        ['foreman_daily_entitlements', 'needs_recalculation', 'TINYINT(1) NOT NULL DEFAULT 0', 'total_amount'],

        ['foreman_daily_entitlement_lines', 'work_period_id', 'INT NULL DEFAULT NULL', 'entitlement_id'],
        ['foreman_daily_entitlement_lines', 'attendance_class_snapshot', "VARCHAR(10) NOT NULL DEFAULT 'tam'", 'worker_type_name_snapshot'],
        ['foreman_daily_entitlement_lines', 'overtime_hours', 'INT NOT NULL DEFAULT 0', 'unit_rate'],
        ['foreman_daily_entitlement_lines', 'overtime_mode_snapshot', 'VARCHAR(10) NULL DEFAULT NULL', 'overtime_hours'],
        ['foreman_daily_entitlement_lines', 'overtime_unit_rate', 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'overtime_mode_snapshot'],
        ['foreman_daily_entitlement_lines', 'overtime_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'overtime_unit_rate'],

        // ⚠ Faz 9C / H-02: foremen.normal_work_minutes + daily_work_sessions.
        // normal_work_minutes_snapshot config/pdks_gunluk.php'nin KENDİ CREATE
        // TABLE'ında da tanımlıdır (SIFIRDAN kurulum için) — burada AYNI
        // kolonlar ÜRETİMDEKİ mevcut (Faz 9C öncesi) tablolara ALTER ile
        // eklenir, aynı desen worker_type_id/worker_type_id_snapshot'ın
        // Faz 8A'da izlediği yol (bkz. pdks_gunluk_faz8a_migrate). DEFAULT
        // 540 (9 saat) — eski sabit vardiya varsayımıyla AYNI, hiçbir çavuş/
        // oturum sessizce farklı bir normal süreye geçmez.
        ['foremen', 'normal_work_minutes', 'INT NOT NULL DEFAULT 540', 'notes'],
        ['daily_work_sessions', 'normal_work_minutes_snapshot', 'INT NOT NULL DEFAULT 540', 'foreman_code_snapshot'],
    ];

    $rapor = [];
    foreach ($spec as [$tablo, $kolon, $tanim, $after]) {
        $rapor[] = pdks_faz8b_kolon_ekle($pdo, $tablo, $kolon, $tanim, $after);
    }
    return $rapor;
}

function pdks_faz8b_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    $gerekli = [
        'foreman_worker_rates' => ['half_day_rate', 'overtime_mode', 'overtime_rate'],
        'daily_worker_work_periods' => [
            'approved_attendance_class', 'approved_by_user_id', 'approved_at',
            'overtime_approved', 'overtime_approved_by_user_id', 'overtime_approved_at',
            'overtime_approved_hours',
        ],
        'foreman_daily_entitlements' => ['needs_recalculation'],
        'foreman_daily_entitlement_lines' => [
            'work_period_id', 'attendance_class_snapshot', 'overtime_hours',
            'overtime_mode_snapshot', 'overtime_unit_rate', 'overtime_total',
        ],
        // Faz 9C / H-02: süre-tabanlı Tam/FM modeli bu iki kolon olmadan
        // OTORİTER hesaplanamaz — şema hazır sayılmaz, sayfa kapısı eski
        // (sabit vardiya) davranışa SESSİZCE geri DÜŞMEZ.
        'foremen' => ['normal_work_minutes'],
        'daily_work_sessions' => ['normal_work_minutes_snapshot'],
    ];

    foreach ($gerekli as $tablo => $kolonlar) {
        if (!pdks_faz8b_tablo_var($pdo, $tablo)) return false;
        foreach ($kolonlar as $kolon) {
            if (!pdks_faz8b_kolon_var($pdo, $tablo, $kolon)) return false;
        }
    }
    return true;
}

function pdks_faz8b_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_faz8b_sema_hazir($pdo)) return;

    $mesaj = 'Faz 8B şeması henüz hazır değil. Yönetici migrate.php (Şema Migrasyon) ekranından migrasyonu çalıştırmalıdır.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Faz 8B');
    if (function_exists('render_flash')) render_flash();
    elseif (function_exists('h')) echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    else echo $mesaj;
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// FİYAT DÖNEMİ SAATLERİ + ÇİFT YEVMİYE (v299)
// foreman_worker_rates'e 5 nullable kolon. KENDİ migrate/hazır çifti —
// pdks_faz8b_sema_hazir()'e BİLEREK EKLENMEZ (kolonlar yokken TÜM Faz 8B
// kilitlenirdi). Kolon yoksa / değer NULL ise davranış BUGÜNKÜYLE BİREBİR
// aynıdır: Tam eşiği = mesainin normal_work_minutes_snapshot'ı, FM tam
// eşikten başlar, çift yevmiye YOK.
// =========================================================

/** [kolon, tanım] — yalnız ADD COLUMN, başka ALTER YOK. */
function pdks_faz8b_saat_kolonlari(): array
{
    return [
        ['full_day_minutes',       'INT NULL DEFAULT NULL'],            // Tam yevmiye saati
        ['half_day_max_minutes',   'INT NULL DEFAULT NULL'],            // Yarım saati — YALNIZ BİLGİ
        ['overtime_start_minutes', 'INT NULL DEFAULT NULL'],            // FM başlangıcı (NULL = Tam saati)
        ['double_day_minutes',     'INT NULL DEFAULT NULL'],            // Çift eşiği
        ['double_day_rate',        'DECIMAL(12,2) NULL DEFAULT NULL'],  // Çift ücret
    ];
}

function pdks_faz8b_saat_kolonlari_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_faz8b_saat_kolonlari() as [$kolon, $tanim]) {
        $rapor[] = pdks_faz8b_kolon_ekle($pdo, 'foreman_worker_rates', $kolon, $tanim, null);
    }
    return $rapor;
}

function pdks_faz8b_saat_kolonlari_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_tablo_var($pdo, 'foreman_worker_rates')) return false;
    foreach (pdks_faz8b_saat_kolonlari() as [$kolon]) {
        if (!pdks_faz8b_kolon_var($pdo, 'foreman_worker_rates', $kolon)) return false;
    }
    return true;
}

/**
 * Saat girdisi → dakika. "9" → 540, "9:30" / "09.30" → 570. Boş → null.
 * Geçersiz → -1 (çağıran hata mesajı üretir). Aralık denetimi ÇAĞIRANDA.
 */
function pdks_faz8b_saat_girdi_dk($ham): ?int
{
    $s = trim((string)($ham ?? ''));
    if ($s === '') return null;
    if (preg_match('/^(\d{1,2})$/', $s, $m)) return (int)$m[1] * 60;
    // v320: tek haneli ondalık = ondalık saat ("9,5" / "9.5" → 9 sa 30 dk, "10.0" → 10 sa);
    // iki haneli ayraç (9:30 / 9.30) eskisi gibi dakika.
    if (preg_match('/^(\d{1,2})[.,](\d)$/', $s, $m)) return (int)$m[1] * 60 + (int)$m[2] * 6;
    if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $s, $m)) {
        if ((int)$m[2] > 59) return -1;
        return (int)$m[1] * 60 + (int)$m[2];
    }
    return -1;
}

/** Dakika → form değeri ("9" / "9:30"). */
function pdks_faz8b_dk_girdi(?int $dk): string
{
    if ($dk === null || $dk <= 0) return '';
    $s = intdiv($dk, 60);
    $d = $dk % 60;
    return $d === 0 ? (string)$s : sprintf('%d:%02d', $s, $d);
}

/**
 * Bir dönemin eşikleri — $normalDk mesainin DONMUŞ snapshot'ı, $oran iş
 * tarihinde geçerli fiyat dönemi (pdks_hakedis_oran_gecerli) ya da null.
 * Çift YALNIZ eşik VE ücret ikisi de doluysa ve eşik FM başından büyükse etkin.
 */
function pdks_faz8b_esikler(int $normalDk, ?array $oran): array
{
    $dk = static function (string $k) use ($oran): ?int {
        $v = $oran[$k] ?? null;
        if ($v === null || $v === '') return null;
        $v = (int)$v;
        return $v > 0 ? $v : null;
    };
    if ($normalDk <= 0) $normalDk = PDKS_FAZ8B_NORMAL_DK;
    $tamFiyat = $dk('full_day_minutes');
    $tam = $tamFiyat ?? $normalDk;
    $fmBas = $dk('overtime_start_minutes') ?? $tam;
    if ($fmBas < $tam) $fmBas = $tam;   // savunma — doğrulama zaten engeller

    $cift = $dk('double_day_minutes');
    $ciftUcret = $oran['double_day_rate'] ?? null;
    $ciftEtkin = $cift !== null && $ciftUcret !== null && $ciftUcret !== ''
        && (float)$ciftUcret > 0 && $cift > $fmBas;

    return [
        'tam_dk' => $tam,
        'tam_kaynak' => $tamFiyat !== null ? 'fiyat' : 'mesai',
        'fm_bas_dk' => $fmBas,
        'cift_dk' => $ciftEtkin ? $cift : null,
        'cift_ucret' => $ciftEtkin ? (string)$ciftUcret : null,
        'yarim_dk' => $dk('half_day_max_minutes'),
        'fm_modu' => isset($oran['overtime_mode']) ? trim((string)$oran['overtime_mode']) : null,
    ];
}

/** Eşiği aşan süreden FM saati: ilk 15 dk tolerans, sonra başlayan her saat. */
function pdks_faz8b_fm_saat(int $fazlaDk): int
{
    if ($fazlaDk <= PDKS_FAZ8B_TOLERANS_DK) return 0;
    return intdiv($fazlaDk - PDKS_FAZ8B_TOLERANS_DK + 59, 60);
}

// =========================================================
// SÜRE / TOLERANS POLİTİKASI
// =========================================================

/**
 * v315/v317 — SAAT BAŞI TOLERANSI (sahip kararı): süre hesabından ÖNCE giriş ve çıkış,
 * tam saate PDKS_FAZ8B_TOLERANS_DK (15) dk ya da daha YAKINSA (önce de sonra da) o tam saate
 * çekilir: 07:57 → 08:00 · 08:14 → 08:00 · 16:46 → 17:00 · 17:13 → 17:00. 15 dk'dan uzaksa
 * (07:44, 08:16, 16:44, 17:16) dokunulmaz. Saat KİLİDİ değildir (08-17, 09-18, 12-21 aynı
 * çalışır). Ham kayıtlar DEĞİŞMEZ; yalnız hesap bu etkin saatleri kullanır.
 * Döner: [etkin giriş ts, etkin çıkış ts] (çıkış girişten önce düşmez).
 */
function pdks_faz8b_saat_basi(int $ts): int
{
    $tol = PDKS_FAZ8B_TOLERANS_DK * 60;
    $gecen = (int)date('i', $ts) * 60 + (int)date('s', $ts);   // tam saatten geçen sn
    if ($gecen === 0) return $ts;
    if ($gecen <= $tol) return $ts - $gecen;                    // 17:13 → 17:00 · 08:14 → 08:00
    if (3600 - $gecen <= $tol) return $ts + (3600 - $gecen);    // 07:57 → 08:00 · 16:46 → 17:00
    return $ts;
}

function pdks_faz8b_etkin_saatler(int $g, int $c): array
{
    $gE = pdks_faz8b_saat_basi($g);
    return [$gE, max($gE, pdks_faz8b_saat_basi($c))];
}

/**
 * Faz 9C / H-02: SÜRE-TABANLI karar — saat-kilidi (clock-of-day boundary)
 * YOKTUR. Yalnız GEÇEN SÜRE (dk), çağıranın verdiği $normalDk (oturumun
 * normal_work_minutes_snapshot'ı) ile karşılaştırılır.
 *
 * v315: "geçen süre" = saat başı toleranslı ETKİN süre (pdks_faz8b_etkin_saatler —
 * v317: iki uç da ±15 dk'da en yakın tam saate — 08:14 → 08:00, 16:46 → 17:00); ham süre
 * `ham_dk` olarak ayrıca döner.
 *
 * Otomatik Tam: geçen süre (dk) >= $normalDk. 08:00–17:00, 09:00–18:00,
 * 10:00–19:00 (9 saatlik anlaşma) HEPSİ Tam — SAAT değil SÜRE eşleşiyor
 * diye. $normalDk'nın altında kalan dönemler muhasebe Tam/Yarım kararına
 * düşer.
 *
 * Fazla mesai yalnız $normalDk TAMAMLANDIKTAN SONRAKİ süreden hesaplanır
 * (planlı bir SAATTEN değil). İlk 15 dk toleranstır. Sonrasında başlayan
 * her saat yukarı yuvarlanır:
 *   normali 16–75 dk aşan => 1 saat
 *   normali 76–135 dk aşan => 2 saat
 *
 * Gün ötesi (23:00–08:00 ertesi gün gibi) veya ikinci/kısa dönemler için
 * ÖZEL bir durum YOKTUR — giriş/çıkış DATETIME'ları zaten doğru günü taşır,
 * hesap yalnız ikisi arasındaki farka bakar.
 */
function pdks_faz8b_sure_karari(?string $giris, ?string $cikis, int $normalDk = PDKS_FAZ8B_NORMAL_DK, ?int $fmBasDk = null): array
{
    // v299: $fmBasDk = fiyat dönemindeki FM başlangıcı (NULL → $normalDk, yani
    // bugünkü davranış). $normalDk burada "Tam eşiği"dir.
    $fmBasDk = ($fmBasDk === null || $fmBasDk < $normalDk) ? $normalDk : $fmBasDk;
    $bos = [
        'toplam_dk' => null,
        'ham_dk' => null,
        'giris_etkin' => null,
        'cikis_etkin' => null,
        'normal_dk' => $normalDk,
        'fm_bas_dk' => $fmBasDk,
        'otomatik_sinif' => null,
        'sinif_onayi_gerekli' => true,
        'fazla_dk' => 0,
        'fazla_mesai_saat' => 0,
        'fazla_mesai_onayi_gerekli' => false,
    ];
    if (!$giris || !$cikis) return $bos;

    $g = strtotime($giris);
    $c = strtotime($cikis);
    if ($g === false || $c === false || $c < $g) return $bos;

    // v315: süre saat başı toleransıyla (07:57 giriş → 08:00, 17:13 çıkış → 17:00).
    $hamDk = intdiv($c - $g, 60);
    [$gE, $cE] = pdks_faz8b_etkin_saatler($g, $c);
    $toplamDk = intdiv($cE - $gE, 60);
    $otomatikTam = $toplamDk >= $normalDk;

    $fazlaDk = $toplamDk > $fmBasDk ? ($toplamDk - $fmBasDk) : 0;
    // 15 dk toleransı çıkar; kalan her başlayan saat yukarı yuvarlanır.
    $fmSaat = pdks_faz8b_fm_saat($fazlaDk);

    return [
        'toplam_dk' => $toplamDk,
        'ham_dk' => $hamDk,
        'giris_etkin' => date('Y-m-d H:i:s', $gE),
        'cikis_etkin' => date('Y-m-d H:i:s', $cE),
        'normal_dk' => $normalDk,
        'fm_bas_dk' => $fmBasDk,
        'otomatik_sinif' => $otomatikTam ? 'tam' : null,
        'sinif_onayi_gerekli' => !$otomatikTam,
        'fazla_dk' => $fazlaDk,
        'fazla_mesai_saat' => $fmSaat,
        'fazla_mesai_onayi_gerekli' => $fmSaat > 0,
    ];
}

/**
 * v299 — TEK SINIFLANDIRICI. Mesai Değerlendirme ekranı, tekli/toplu
 * değerlendirme kaydı, hakediş motoru ve Mesai Detayı "Mesai Tanımı" sütunu
 * HEPSİ bunu kullanır; süre/sınıf/FM/çift hesabını başka yerde YAZMA.
 *
 * $donem — daily_worker_work_periods satırı, TERCİHEN oturumun
 * normal_work_minutes_snapshot'ını da taşımalıdır (bkz.
 * pdks_faz8b_oturum_donemleri()'nin JOIN'i). Anahtar yoksa/boşsa
 * PDKS_FAZ8B_NORMAL_DK'ya (540 dk) düşülür — eski davranışla AYNI son çare.
 * $oran — iş tarihinde geçerli fiyat dönemi (saat eşikleri + çift ücret +
 * FM modu); null ise eşikler bugünküyle birebir aynıdır.
 *
 * ÇİFT YEVMİYE (sahip kararı — "çift Tam'ın yerine + sonrası FM"):
 *   toplam ≥ çift eşiği (toleranssız) VE onaylı süre (FM başı + onaylanan FM
 *   saati × 60) ≥ çift eşiği → sınıf 'cift', temel = çift ücret; ödenecek FM
 *   = çift eşiğinden SONRAKİ onaylı saatler (aynı 15 dk tolerans). Tam ile çift
 *   arasındaki saatler AYRICA ödenmez. Sabit (fixed) FM modunda çift gününe
 *   FM EKLENMEZ (çift zaten ödüyor). FM reddi → Tam; FM bekliyor → hazır değil.
 */
function pdks_faz8b_donem_siniflandir(array $donem, ?array $oran = null): array
{
    $normalDk = (int)($donem['normal_work_minutes_snapshot'] ?? PDKS_FAZ8B_NORMAL_DK);
    if ($normalDk <= 0) $normalDk = PDKS_FAZ8B_NORMAL_DK;
    $e = pdks_faz8b_esikler($normalDk, $oran);
    $sure = pdks_faz8b_sure_karari($donem['entry_time'] ?? null, $donem['exit_time'] ?? null, $e['tam_dk'], $e['fm_bas_dk']);

    $onayliSinif = trim((string)($donem['approved_attendance_class'] ?? ''));
    $sinif = $sure['otomatik_sinif'];
    $sinifKaynak = 'otomatik';
    if ($sinif === null) {
        $sinif = in_array($onayliSinif, ['tam', 'yarim'], true) ? $onayliSinif : null;
        $sinifKaynak = $sinif !== null ? 'muhasebe' : 'bekliyor';
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    // Faz 9C / UX-03: OTORİTER FM onayı SAAT SAYISIdır (overtime_approved_hours);
    // eski `overtime_approved` bayrağı yalnız rozet amaçlı türetilir.
    $fmOnaySaatHam = $donem['overtime_approved_hours'] ?? null;
    $fmOnaySaat = ($fmOnaySaatHam === '' || $fmOnaySaatHam === null) ? null : (int)$fmOnaySaatHam;
    // v299: eşik değişince onaylı saat adaydan büyük kalırsa adaya KIRPILIR
    // (yeni değerlendirme istenmez).
    $fmKirpildi = false;
    if ($fmSaat > 0 && $fmOnaySaat !== null && $fmOnaySaat > $fmSaat) {
        $fmOnaySaat = $fmSaat;
        $fmKirpildi = true;
    }

    $fmDurum = 'yok';
    if ($fmSaat > 0) {
        $fmDurum = $fmOnaySaat === null ? 'bekliyor' : ($fmOnaySaat > 0 ? 'onayli' : 'reddedildi');
    }
    $odenecekFm = $fmDurum === 'onayli' ? (int)$fmOnaySaat : 0;

    // Çift yevmiye
    $toplamDk = $sure['toplam_dk'];
    $ciftAday = $e['cift_dk'] !== null && $toplamDk !== null && $toplamDk >= $e['cift_dk'];
    $cift = false;
    $ciftFmAday = 0;
    if ($ciftAday) {
        $ciftFmAday = pdks_faz8b_fm_saat((int)$toplamDk - (int)$e['cift_dk']);
        if ($fmDurum === 'onayli') {
            $onayliSure = $e['fm_bas_dk'] + 60 * (int)$fmOnaySaat;
            if ($onayliSure >= $e['cift_dk']) {
                $cift = true;
                $odenecekFm = min($ciftFmAday, intdiv($onayliSure - (int)$e['cift_dk'] + 59, 60));
                if ($e['fm_modu'] === 'fixed') $odenecekFm = 0;   // sabit FM çift gününe eklenmez
            }
        }
    }
    if ($cift) {
        $sinif = 'cift';
        $sinifKaynak = 'fm_onayi';
    }

    return $sure + [
        'tam_dk' => $e['tam_dk'],
        'tam_kaynak' => $e['tam_kaynak'],
        'yarim_dk' => $e['yarim_dk'],
        // YALNIZ BİLGİ — sınıflandırmayı DEĞİŞTİRMEZ (otomatik Yarım YOK).
        'yarim_alti' => $e['yarim_dk'] !== null && $toplamDk !== null && $toplamDk < $e['yarim_dk'],
        'cift_dk' => $e['cift_dk'],
        'cift_ucret' => $e['cift_ucret'],
        'cift_aday' => $ciftAday,
        'cift' => $cift,
        'cift_fm_aday' => $ciftFmAday,
        'fm_modu' => $e['fm_modu'],
        'etkin_sinif' => $sinif,
        'sinif_kaynak' => $sinifKaynak,
        'fazla_mesai_durum' => $fmDurum,
        'fazla_mesai_onay_saat' => $fmOnaySaat,
        'fazla_mesai_onay_kirpildi' => $fmKirpildi,
        // Hakedişte ödenecek FM saati (Tam: onaylı saat; Çift: çift sonrası).
        'odenecek_fm_saat' => $odenecekFm,
        'finans_hazir' => $sinif !== null && ($fmSaat === 0 || $fmOnaySaat !== null),
    ];
}

/**
 * Geriye uyumlu sarmalayıcı. Satır `_faz8b_oran` taşıyorsa (bkz.
 * pdks_faz8b_donem_orani_ekle) fiyat dönemi eşikleri uygulanır; taşımıyorsa
 * bugünkü davranış.
 */
function pdks_faz8b_donem_finans_durumu(array $donem): array
{
    return pdks_faz8b_donem_siniflandir($donem, $donem['_faz8b_oran'] ?? null);
}

/**
 * Dönem satırlarına iş tarihinde geçerli fiyat dönemini `_faz8b_oran` olarak
 * ekler ve `faz8b` sınıflandırmasını hesaplar. Satırlar `_s_foreman_id` +
 * `_s_work_date` taşımalıdır (oturum JOIN'i). Saat kolonları yoksa fiyat
 * SORGULANMAZ (eşikler zaten NULL → bugünkü davranış).
 */
function pdks_faz8b_donemleri_siniflandir(array $rows, PDO $pdo): array
{
    $saatHazir = pdks_faz8b_saat_kolonlari_hazir($pdo);
    $onbellek = [];
    foreach ($rows as &$r) {
        $oran = null;
        $tip = $r['worker_type_id_snapshot'] ?? null;
        if ($saatHazir && $tip !== null && $tip !== '' && !empty($r['_s_foreman_id']) && !empty($r['_s_work_date'])) {
            $k = (int)$r['_s_foreman_id'] . ':' . (int)$tip . ':' . $r['_s_work_date'];
            if (!array_key_exists($k, $onbellek)) {
                $onbellek[$k] = pdks_hakedis_oran_gecerli((int)$r['_s_foreman_id'], (int)$tip, (string)$r['_s_work_date'], $pdo);
            }
            $oran = $onbellek[$k];
        }
        $r['_faz8b_oran'] = $oran;
        $r['faz8b'] = pdks_faz8b_donem_siniflandir($r, $oran);
    }
    unset($r);
    return $rows;
}

/** Dönem + oturum JOIN'i (TEK SQL kaynağı). $kosul pozisyonel `?` taşır. */
function pdks_faz8b_donem_sorgu(PDO $pdo, string $kosul, array $param): array
{
    $st = $pdo->prepare(
        "SELECT p.*, w.card_no, s.normal_work_minutes_snapshot,
                s.foreman_id AS _s_foreman_id, s.work_date AS _s_work_date, s.status AS _s_status
           FROM daily_worker_work_periods p
           JOIN worker_cards w ON w.id = p.worker_card_id
           JOIN daily_work_sessions s ON s.id = p.session_id
          WHERE {$kosul} AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
          ORDER BY p.entry_time ASC, p.id ASC"
    );
    $st->execute($param);
    return pdks_faz8b_donemleri_siniflandir($st->fetchAll(), $pdo);
}

/** Tek dönem (+ isteğe bağlı oturum kısıtı — IDOR). Yoksa null. */
function pdks_faz8b_donem_getir(int $periodId, ?PDO $pdo = null, ?int $sessionId = null): ?array
{
    $pdo = $pdo ?? db();
    $rows = $sessionId === null
        ? pdks_faz8b_donem_sorgu($pdo, 'p.id = ?', [$periodId])
        : pdks_faz8b_donem_sorgu($pdo, 'p.id = ? AND p.session_id = ?', [$periodId, $sessionId]);
    return $rows[0] ?? null;
}

function pdks_faz8b_oturum_donemleri(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    // ⚠ Faz 9C / H-02: her dönem KENDİ oturumunun DONMUŞ normal süresiyle
    // değerlendirilir; v299: + iş tarihindeki fiyat döneminin saat eşikleri.
    return pdks_faz8b_donem_sorgu($pdo, 'p.session_id = ?', [$sessionId]);
}

function pdks_faz8b_oturum_ozeti(int $sessionId, ?PDO $pdo = null): array
{
    $rows = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
    $bekleyenSinif = 0;
    $bekleyenFm = 0;
    $hazir = 0;

    foreach ($rows as $r) {
        if ($r['faz8b']['etkin_sinif'] === null) $bekleyenSinif++;
        if ($r['faz8b']['fazla_mesai_durum'] === 'bekliyor') $bekleyenFm++;
        if ($r['faz8b']['finans_hazir']) $hazir++;
    }

    return [
        'toplam' => count($rows),
        'hazir' => $hazir,
        'bekleyen_sinif' => $bekleyenSinif,
        'bekleyen_fazla_mesai' => $bekleyenFm,
        'tam_hazir' => count($rows) > 0 && $hazir === count($rows),
    ];
}

if (!defined('PDKS_FAZ8B_OZET_FM_SUTUN')) define('PDKS_FAZ8B_OZET_FM_SUTUN', 5);   // son sütun = 5 ve üzeri

/**
 * v313/v314 — Mesai Detayı "Mesai Özeti": verilen mesailerin (oturum id'leri) toplu özeti.
 * SALT OKUNUR; süre/sınıf/FM hesabını YAPMAZ — her dönem için TEK sınıflandırıcıyı
 * (pdks_faz8b_donem_siniflandir) kullanır. Servis adetleri pdks_servis_toplamlar_toplu().
 *
 *  tanim[tip adı] = [tam, yarim, cift, fm => [1..5], bekliyor (sınıf kararı yok), suruyor (çıkışsız, mesai AÇIK = içeride), eksik (çıkışsız, mesai KAPALI = eksik çıkış), toplam]
 *      — yalnız sistem tipleri (Kadın/Erkek/Rampacı); sıfır olsa da satır vardır.
 *      fm[n] = fazla mesaisi n saat olan işçi sayısı (5 = 5 ve üzeri). Saat = çavuşun mesai
 *      süresi (ör. 9 sa) aşıldıktan sonra 15 dk tolerans, başlayan her saat yukarı: 10:11 → 1,
 *      12:00 → 3. Reddedilen FM sayılmaz; Çift günde çift eşiğinden SONRAKİ onaylı saat.
 *  karisik   — atanmamış Karışık dönem sayısı (sınıfsız, süreye KATILMAZ)
 *  calisma_dk   — TOLERANSLI toplam çalışma: Σ [min(süre, FM başlangıcı) + FM saati × 60]
 *                 (FM = 15 dk tolerans sonrası, başlayan saat yukarı; bkz. pdks_faz8b_fm_saat)
 *  ham_dk       — Σ gerçek (çıkış − giriş) süre, toleranssız
 *  fm_saat      — toplam fazla mesai saati (= Σ fm dağılımı)   fm_onayli / fm_bekleyen / fm_red
 *  sureli_kisi  — süresi hesaplanan (çıkışı olan, Karışık olmayan) dönem sayısı
 *  fm_bas_dk    — kullanılan FM başlangıç eşikleri (dk, benzersiz, artan; ör. [540])
 *  fm_kaynak    — eşiğin kaynağı (benzersiz): 'fm_fiyat' (fiyat döneminde "Fazla mesai başlangıç saati"),
 *                 'tam_fiyat' (fiyat döneminde "Tam yevmiye saati"), 'mesai' (çavuşun normal çalışma süresi)
 *  servis       — ['BUYUK' => n, 'KUCUK' => n]
 * Şema hazır değilse null.
 */
function pdks_faz8b_gun_mesai_ozeti(array $sessionIds, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) return null;
    $sessionIds = array_values(array_unique(array_filter(array_map('intval', $sessionIds), static fn($i) => $i > 0)));

    $tanim = [];
    foreach (pdks_gunluk_tip_sistem_sutunlari() as $tc) {
        $tanim[$tc['ad']] = ['tam' => 0, 'yarim' => 0, 'cift' => 0, 'fm' => array_fill(1, PDKS_FAZ8B_OZET_FM_SUTUN, 0),
                             'bekliyor' => 0, 'suruyor' => 0, 'eksik' => 0, 'toplam' => 0];
    }
    $o = [
        'tanim' => $tanim, 'karisik' => 0, 'diger' => 0,
        'calisma_dk' => 0, 'ham_dk' => 0, 'sureli_kisi' => 0, 'fm_bas_dk' => [], 'fm_kaynak' => [],
        'fm_saat' => 0, 'fm_onayli' => 0, 'fm_bekleyen' => 0, 'fm_red' => 0,
        'servis' => array_fill_keys(array_keys(pdks_servis_turleri()), 0),
    ];
    if (!$sessionIds) return $o;

    $ph = implode(',', array_fill(0, count($sessionIds), '?'));
    foreach (pdks_faz8b_donem_sorgu($pdo, "p.session_id IN ($ph)", $sessionIds) as $d) {
        $ad = (string)($d['worker_type_name_snapshot'] ?? '');
        if ($ad === PDKS_GUNLUK_KARISIK_AD) { $o['karisik']++; continue; }
        if (!isset($o['tanim'][$ad])) { $o['diger']++; continue; }
        $f = $d['faz8b'];
        $t = &$o['tanim'][$ad];
        $t['toplam']++;
        if ($f['toplam_dk'] === null) { $t[(($d['_s_status'] ?? '') === 'closed') ? 'eksik' : 'suruyor']++; unset($t); continue; }
        $sinif = $f['etkin_sinif'];
        if ($sinif === 'tam' || $sinif === 'yarim' || $sinif === 'cift') $t[$sinif]++; else $t['bekliyor']++;

        $fm = (int)$f['fazla_mesai_saat'];
        // Gösterilen FM: reddedilen sayılmaz; Çift günde çift eşiğinden sonraki onaylı saat.
        $fmGoster = $sinif === 'cift' ? (int)$f['odenecek_fm_saat'] : ($f['fazla_mesai_durum'] === 'reddedildi' ? 0 : $fm);
        if ($fmGoster > 0) $t['fm'][min($fmGoster, PDKS_FAZ8B_OZET_FM_SUTUN)]++;
        unset($t);

        $o['sureli_kisi']++;
        $o['fm_bas_dk'][(int)$f['fm_bas_dk']] = (int)$f['fm_bas_dk'];
        $kay = (int)$f['fm_bas_dk'] > (int)$f['tam_dk'] ? 'fm_fiyat' : (($f['tam_kaynak'] ?? '') === 'fiyat' ? 'tam_fiyat' : 'mesai');
        $o['fm_kaynak'][$kay] = $kay;
        $o['ham_dk'] += (int)($f['ham_dk'] ?? $f['toplam_dk']);
        $o['calisma_dk'] += min((int)$f['toplam_dk'], (int)$f['fm_bas_dk']) + $fm * 60;
        $o['fm_saat'] += $fmGoster;
        if ($fm > 0) {
            $durum = (string)$f['fazla_mesai_durum'];
            if ($durum === 'onayli') $o['fm_onayli'] += (int)$f['odenecek_fm_saat'];
            elseif ($durum === 'bekliyor') $o['fm_bekleyen'] += $fm;
            elseif ($durum === 'reddedildi') $o['fm_red'] += $fm;
        }
    }
    ksort($o['fm_bas_dk']);
    $o['fm_bas_dk'] = array_values($o['fm_bas_dk']);
    $o['fm_kaynak'] = array_values($o['fm_kaynak']);
    $o['servis'] = pdks_servis_toplamlar_toplu($sessionIds, $pdo);
    return $o;
}

// =========================================================
// ÇAVUŞ — NORMAL GÜNLÜK ÇALIŞMA SÜRESİ (Faz 9C / H-02)
// =========================================================

/** Bir dakika değerini "X saat" / "Xs Ydk" biçiminde okunur etikete çevirir. */
function pdks_faz8b_dakika_etiket(int $dk): string
{
    if ($dk <= 0) return '0 dk';
    $saat = intdiv($dk, 60);
    $kalanDk = $dk % 60;
    if ($kalanDk === 0) return $saat . ' saat';
    return $saat . 's ' . $kalanDk . 'dk';
}

/**
 * v299: Mesai Detayı "Mesai Tanımı" sütunu metni — YALNIZ sınıflandırıcı
 * çıktısını ($f = pdks_faz8b_donem_siniflandir) metne çevirir, hesap YAPMAZ.
 * "Tam" / "Yarım" / "Çift" + "· FM N s" ("(bekliyor)" / "FM reddedildi");
 * sınıf yoksa "Karar bekliyor"; mesai açıkken çıkışsız dönem "⏳ Sürüyor";
 * Karışık tip ya da sınıflandırma yoksa "—".
 */
function pdks_faz8b_mesai_tanimi_etiketi(?array $f, bool $suruyor, bool $karisik): string
{
    if ($karisik || $f === null) return '—';
    if ($suruyor) return '⏳ Sürüyor';
    if (($f['etkin_sinif'] ?? null) === null) return 'Karar bekliyor';
    $m = pdks_faz8b_sinif_etiketi((string)$f['etkin_sinif']);
    $aday = (int)($f['fazla_mesai_saat'] ?? 0);
    if ($aday > 0) {
        $durum = (string)($f['fazla_mesai_durum'] ?? '');
        if ($durum === 'bekliyor') $m .= ' · FM ' . $aday . ' s (bekliyor)';
        elseif ($durum === 'reddedildi') $m .= ' · FM reddedildi';
        elseif ((int)($f['odenecek_fm_saat'] ?? 0) > 0) $m .= ' · FM ' . (int)$f['odenecek_fm_saat'] . ' s';
    }
    return $m;
}

/**
 * v315: sınıflandırıcı çıktısındaki süre metni — HESAPLANAN süre (saat başı toleransı
 * uygulanmış) "9s 00dk"; ham süre farklıysa " (ham 9s 16dk)" eklenir. Düz metin (h() ile bas).
 */
function pdks_faz8b_sure_metni(?array $f): string
{
    if ($f === null || ($f['toplam_dk'] ?? null) === null) return '—';
    $m = fn(int $dk): string => sprintf('%ds %02ddk', intdiv($dk, 60), $dk % 60);
    $t = $m((int)$f['toplam_dk']);
    $ham = $f['ham_dk'] ?? null;
    return ($ham !== null && (int)$ham !== (int)$f['toplam_dk']) ? $t . ' (ham ' . $m((int)$ham) . ')' : $t;
}

/** v299: sınıf kodu → ekran etiketi ('cift' → Çift). Bilinmeyen/boş → Tam. */
function pdks_faz8b_sinif_etiketi(?string $sinif): string
{
    return $sinif === 'yarim' ? 'Yarım' : ($sinif === 'cift' ? 'Çift' : 'Tam');
}

/**
 * Bir çavuşun O ANKİ (canlı) normal günlük çalışma süresi. Şema henüz
 * migrate edilmemişse veya kayıt yoksa PDKS_FAZ8B_NORMAL_DK'ya (540 dk)
 * düşülür — hiçbir yerde HATA vermez.
 *
 * ⚠ Bu CANLI değerdir — GEÇMİŞ oturumların hesabı İÇİN KULLANILMAZ (onlar
 * kendi normal_work_minutes_snapshot'larını kullanır). Yalnız YENİ oturum
 * açılırken (config/pdks_gunluk.php) ve bu ekranda GÜNCEL değeri göstermek
 * için kullanılır.
 */
function pdks_faz8b_cavus_normal_sure_dk(int $foremanId, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_kolon_var($pdo, 'foremen', 'normal_work_minutes')) {
        return PDKS_FAZ8B_NORMAL_DK;
    }
    $st = $pdo->prepare("SELECT normal_work_minutes FROM foremen WHERE id = ?");
    $st->execute([$foremanId]);
    $v = $st->fetchColumn();
    return ($v !== false && $v !== null) ? (int)$v : PDKS_FAZ8B_NORMAL_DK;
}

/**
 * Çavuşun normal günlük çalışma süresini GÜNCELLER. Yalnız CANLI ayarı
 * değiştirir — zaten AÇILMIŞ oturumların normal_work_minutes_snapshot'ı
 * (ve onlara bağlı kesinleşmiş hakedişler) BU ÇAĞRIDAN ASLA etkilenmez
 * (Faz 9C madde 5 — KRİTİK tarihsel güvenlik).
 */
function pdks_faz8b_cavus_normal_sure_guncelle(int $foremanId, int $dakika, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_kolon_var($pdo, 'foremen', 'normal_work_minutes')) {
        return ['ok' => false, 'hata' => 'Faz 8B şeması henüz hazır değil.'];
    }
    if ($dakika < PDKS_FAZ8B_SURE_MIN_DK || $dakika > PDKS_FAZ8B_SURE_MAX_DK) {
        return ['ok' => false, 'hata' => 'Normal günlük çalışma süresi 1-24 saat aralığında olmalıdır.'];
    }

    $st = $pdo->prepare("SELECT id, normal_work_minutes FROM foremen WHERE id = ?");
    $st->execute([$foremanId]);
    $eski = $st->fetch();
    if (!$eski) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    $pdo->prepare("UPDATE foremen SET normal_work_minutes = ? WHERE id = ?")->execute([$dakika, $foremanId]);

    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'foremen', $foremanId,
            ['normal_work_minutes' => $eski['normal_work_minutes'] ?? null],
            ['normal_work_minutes' => $dakika]);
    }
    return ['ok' => true];
}

// =========================================================
// MUHASEBE DEĞERLENDİRMESİ
// =========================================================

/**
 * @param int|null $overtimeApprovedHours Faz 9C / UX-03: HESAPLANAN FM
 *        adayının (fazla_mesai_saat) 0..adayı arasında muhasebenin
 *        ONAYLADIĞI saat sayısı. Adayın ÜSTÜNE ÇIKAMAZ (reddedilir).
 *        0 = tamamen reddet, aday değeri = tamamen onayla, arası = KISMİ
 *        onay. FM adayı yoksa (fazla_mesai_saat === 0) YOK SAYILIR.
 */
function pdks_faz8b_degerlendirme_kaydet(
    int $periodId,
    ?string $attendanceDecision,
    ?int $overtimeApprovedHours,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Faz 8B şeması hazır değil.'];
    }

    // v299: dönem + sınıflandırma TEK kaynaktan (pdks_faz8b_donem_siniflandir);
    // süre/FM burada YENİDEN HESAPLANMAZ.
    $p = pdks_faz8b_donem_getir($periodId, $pdo);
    if (!$p) return ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı.'];

    $stFinal = $pdo->prepare(
        "SELECT id FROM foreman_daily_entitlements
          WHERE session_id = ? AND status = 'final' LIMIT 1"
    );
    $stFinal->execute([(int)$p['session_id']]);
    if ($stFinal->fetchColumn()) {
        return ['ok' => false, 'hata' => 'Bu oturumun hakedişi KESİN. Önce yönetici kontrollü olarak hakedişi yeniden açmalıdır.'];
    }

    // Faz 9C / H-02: normal süre oturumun donmuş snapshot'ı; v299: + fiyat
    // döneminin saat eşikleri — ikisi de sınıflandırıcının içinde.
    $sure = $p['faz8b'];
    $attendanceDecision = $attendanceDecision !== null ? trim($attendanceDecision) : null;
    $simdi = date('Y-m-d H:i:s');

    if ($sure['sinif_onayi_gerekli']) {
        if (!in_array($attendanceDecision, ['tam', 'yarim'], true)) {
            return ['ok' => false, 'hata' => 'Kısa / çıkışı belirsiz mesai için muhasebe Tam veya Yarım kararı vermelidir.'];
        }
        $sinif = $attendanceDecision;
        $sinifUser = $userId;
        $sinifAt = $simdi;
    } else {
        // Otomatik Tam. İnsan onayı ile karıştırmamak için approved_* boş kalır.
        $sinif = null;
        $sinifUser = null;
        $sinifAt = null;
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    if ($fmSaat > 0) {
        if ($overtimeApprovedHours === null || $overtimeApprovedHours < 0 || $overtimeApprovedHours > $fmSaat) {
            return ['ok' => false, 'hata' => "Fazla mesai adayı için 0 ile {$fmSaat} saat arasında onaylanan saat girilmelidir."];
        }
        $fmOnaySaat = $overtimeApprovedHours;
        // Eski ikili bayrak (rozet/geriye dönük uyumluluk) — OTORİTE DEĞİL,
        // yalnız 'overtime_approved_hours' saati TÜRETİLİR.
        $fmOnay = $fmOnaySaat > 0 ? 1 : 0;
        $fmUser = $userId;
        $fmAt = $simdi;
    } else {
        $fmOnaySaat = null;
        $fmOnay = null;
        $fmUser = null;
        $fmAt = null;
    }

    $before = [
        'approved_attendance_class' => $p['approved_attendance_class'] ?? null,
        'overtime_approved' => $p['overtime_approved'] ?? null,
        'overtime_approved_hours' => $p['overtime_approved_hours'] ?? null,
    ];

    $upd = $pdo->prepare(
        "UPDATE daily_worker_work_periods
            SET approved_attendance_class = ?, approved_by_user_id = ?, approved_at = ?,
                overtime_approved = ?, overtime_approved_hours = ?, overtime_approved_by_user_id = ?, overtime_approved_at = ?
          WHERE id = ?"
    );
    $upd->execute([$sinif, $sinifUser, $sinifAt, $fmOnay, $fmOnaySaat, $fmUser, $fmAt, $periodId]);

    // Daha önce hesaplanmış taslak artık finansal olarak bayattır.
    $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET needs_recalculation = 1
          WHERE session_id = ? AND status = 'draft'"
    )->execute([(int)$p['session_id']]);

    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'daily_worker_work_periods', $periodId, $before, [
            'approved_attendance_class' => $sinif,
            'overtime_approved' => $fmOnay,
            'overtime_approved_hours' => $fmOnaySaat,
            'fazla_mesai_saat_adayi' => $fmSaat,
        ]);
    }

    return ['ok' => true];
}

/**
 * Mesai Değerlendirme → TOPLU İŞLEM (Sprint Toplu-Degerlendirme-01):
 * kullanıcı birden çok "Tam/Yarım seç" bekleyen dönemi işaretleyip TEK bir
 * Tam/Yarım kararını hepsine birden uygular.
 *
 * ⚠ Kapsam BİLEREK dar tutulur — kullanıcıyla netleştirildi:
 * - Yalnız `sinif_onayi_gerekli` (kısa/eksik-çıkışlı, "karar bekliyor")
 *   dönemler işlenir. Zaten "Otomatik Tam" olan bir dönem GÖNDERİLSE bile
 *   burada SESSİZCE ATLANIR (aday listesine hiç girmez) — üzerine yazma
 *   YOK. Aynı ihtiyat: dönem BAŞKA bir oturuma aitse de atlanır (IDOR).
 * - Fazla mesai (FM) saatine BURADA HİÇ DOKUNULMAZ (her zaman null geçilir) —
 *   İCAT EDİLMİŞ bir kısıtlama değil, pdks_faz8b_sure_karari()'nin YAPISAL
 *   kuralı: `fazla_mesai_saat` yalnız toplam süre normali AŞTIĞINDA hesaplanır,
 *   bu ise otomatik_sinif='tam' (sinif_onayi_gerekli=false) demektir. Yani
 *   "karar bekleyen" bir dönemde FM adayı MATEMATİKSEL OLARAK asla olamaz —
 *   yukarıdaki skip zaten bu satırlara hiç ulaşılmamasını garanti eder.
 * - GERÇEK yazma yolu YİNE pdks_faz8b_degerlendirme_kaydet()'tir — İKİNCİ
 *   bir UPDATE yolu AÇILMAZ, bu fonksiyon yalnız "hangi dönem" sorusunu
 *   döngüyle çözüp tek tek ona devreder.
 *
 * @param int[] $periodIds
 * @return array{ok:bool, basarili:int, atlandi:int, hatalar:string[]}
 */
function pdks_faz8b_toplu_degerlendirme_kaydet(
    array $periodIds,
    string $attendanceDecision,
    int $sessionId,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!in_array($attendanceDecision, ['tam', 'yarim'], true)) {
        return ['ok' => false, 'basarili' => 0, 'atlandi' => 0, 'hatalar' => ['Tam veya Yarım seçilmelidir.']];
    }

    $basarili = 0; $atlandi = 0; $hatalar = [];
    foreach (array_unique($periodIds) as $periodId) {
        $periodId = (int)$periodId;
        if ($periodId <= 0) { $atlandi++; continue; }

        // ⚠ session_id = ? SATIRDA — başka oturumun dönemi id tahmin edilerek
        // buraya karıştırılamaz (aynı IDOR ihtiyatı sayfanın kendisiyle AYNI).
        $donem = pdks_faz8b_donem_getir($periodId, $pdo, $sessionId);
        if (!$donem) { $atlandi++; continue; }

        // v299: TEK sınıflandırıcı (pdks_faz8b_donem_siniflandir) — süre burada hesaplanmaz.
        if (!$donem['faz8b']['sinif_onayi_gerekli']) { $atlandi++; continue; }   // zaten Otomatik Tam — üzerine YAZILMAZ

        $sonuc = pdks_faz8b_degerlendirme_kaydet($periodId, $attendanceDecision, null, $userId, $pdo);
        if ($sonuc['ok']) { $basarili++; }
        else { $hatalar[] = "Dönem #{$periodId}: " . ($sonuc['hata'] ?? 'kaydedilemedi'); }
    }

    return ['ok' => $basarili > 0 && !$hatalar, 'basarili' => $basarili, 'atlandi' => $atlandi, 'hatalar' => $hatalar];
}

// =========================================================
// ÇAVUŞ FİYATLARI — TAM / YARIM / FAZLA MESAİ
// =========================================================

function pdks_faz8b_oran_ekle(
    int $foremanId,
    int $workerTypeId,
    string $tamUcretHam,
    string $yarimUcretHam,
    string $fazlaMesaiModu,
    string $fazlaMesaiUcretHam,
    string $validFrom,
    ?string $currency,
    int $userId,
    ?PDO $pdo = null,
    ?array $saatler = null
): array {
    $pdo = $pdo ?? db();
    $currency = trim((string)$currency) ?: 'TRY';
    $fazlaMesaiModu = trim($fazlaMesaiModu);

    if (!in_array($fazlaMesaiModu, ['hourly', 'fixed'], true)) {
        return ['ok' => false, 'hata' => 'Fazla mesai tipi Saatlik veya Sabit Toplam olmalıdır.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Geçerlilik başlangıç tarihi geçersiz.'];
    }

    $tamKurus = pdks_hakedis_girdi_kurus($tamUcretHam);
    $yarimKurus = pdks_hakedis_girdi_kurus($yarimUcretHam);
    $fmKurus = pdks_hakedis_girdi_kurus($fazlaMesaiUcretHam);
    if ($tamKurus === null || $tamKurus <= 0) return ['ok' => false, 'hata' => 'Tam Mesai ücreti geçersiz.'];
    if ($yarimKurus === null || $yarimKurus <= 0) return ['ok' => false, 'hata' => 'Yarım Mesai ücreti geçersiz.'];
    if ($fmKurus === null || $fmKurus <= 0) return ['ok' => false, 'hata' => 'Fazla Mesai ücreti geçersiz.'];

    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    // v299: saat eşikleri + çift yevmiye (hepsi opsiyonel; boş = NULL = bugünkü davranış).
    $saatSonuc = pdks_faz8b_saat_girdileri_dogrula($saatler ?? [], $foremanId, $pdo);
    if (!$saatSonuc['ok']) return ['ok' => false, 'hata' => $saatSonuc['hata']];
    $saatDeger = $saatSonuc['degerler'];   // [] ya da 5 kolonun tamamı

    // ⚠ Faz 9B / H-01: YENİ oran tanımlama açılır listesi (cavus_fiyatlari.php)
    // TEK paylaşılan politikayı (pdks_gunluk_desteklenen_tip_listele()) kullanır
    // — yalnız KADIN/ERKEK SUNAR. Bu HAM fonksiyon BİLEREK sert bir kod
    // kısıtı EKLEMEZ (mevcut worker_types.id kontrolü YETERLİDİR): görev
    // talimatı §8 yalnız SEÇİM arayüzünün kısıtlanmasını ister (§4/§5'in
    // tarama/düzeltme için istediği "crafted POST'a karşı bağımsız kapı"
    // İLE AYNI KESİNLİKTE DEĞİL) — ayrıca scripts/pdks_takip_ui_smoke.php
    // gibi başka amaçlı (raporlama mutabakatı) testler BİLEREK desteklenmeyen
    // bir tip için oran tanımlıyor; bu HAM birincil işlemi kısıtlamak o
    // testleri KIRARDI. Sunucu tarafı zorlaması gereken tek yer — kullanıcı
    // girdisinden GELEN GİRİŞ/düzeltme akışları — §4/§5'te AYRICA yapılır.
    $stT = $pdo->prepare("SELECT id FROM worker_types WHERE id = ?");
    $stT->execute([$workerTypeId]);
    if (!$stT->fetchColumn()) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];
    // v295: KARISIK (Karışık) ASLA fiyatlanmaz — kiosk girişindeki geçici tiptir,
    // Otomatik Ata ile KADIN/ERKEK'e dağıtılır (bkz. pdks_hakedis_karisik_oran_engeli()).
    if (($tipEngel = pdks_hakedis_karisik_oran_engeli($pdo, $workerTypeId)) !== null) {
        return ['ok' => false, 'hata' => $tipEngel];
    }

    $stMevcut = $pdo->prepare(
        "SELECT * FROM foreman_worker_rates
          WHERE foreman_id = ? AND worker_type_id = ? AND is_active = 1
          ORDER BY valid_from DESC, id DESC LIMIT 1"
    );
    $stMevcut->execute([$foremanId, $workerTypeId]);
    $mevcut = $stMevcut->fetch();

    if ($mevcut && strtotime((string)$mevcut['valid_from']) >= strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Yeni başlangıç tarihi mevcut en son fiyat döneminden sonra olmalıdır.'];
    }

    $pdo->beginTransaction();
    try {
        if ($mevcut && (($mevcut['valid_to'] ?? null) === null || strtotime((string)$mevcut['valid_to']) >= strtotime($validFrom))) {
            $bitis = date('Y-m-d', strtotime($validFrom . ' -1 day'));
            $pdo->prepare("UPDATE foreman_worker_rates SET valid_to = ? WHERE id = ?")
                ->execute([$bitis, (int)$mevcut['id']]);
        }

        // v299: saat kolonları YALNIZ girildiyse yazılır (yoksa INSERT bugünküyle aynı).
        $ekKolon = $saatDeger ? ', ' . implode(', ', array_keys($saatDeger)) : '';
        $ekYer = $saatDeger ? str_repeat(',?', count($saatDeger)) : '';
        $ins = $pdo->prepare(
            "INSERT INTO foreman_worker_rates
                (foreman_id, worker_type_id, daily_rate, half_day_rate,
                 overtime_mode, overtime_rate, currency, valid_from, valid_to,
                 is_active, created_by_user_id{$ekKolon})
             VALUES (?,?,?,?,?,?,?,?,NULL,1,?{$ekYer})"
        );
        $ins->execute(array_merge([
            $foremanId,
            $workerTypeId,
            pdks_hakedis_kurus_tl($tamKurus),
            pdks_hakedis_kurus_tl($yarimKurus),
            $fazlaMesaiModu,
            pdks_hakedis_kurus_tl($fmKurus),
            $currency,
            $validFrom,
            $userId,
        ], array_values($saatDeger)));
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Fiyat dönemi kaydedilemedi: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_worker_rates', $id, null, [
            'foreman_id' => $foremanId,
            'worker_type_id' => $workerTypeId,
            'daily_rate' => pdks_hakedis_kurus_tl($tamKurus),
            'half_day_rate' => pdks_hakedis_kurus_tl($yarimKurus),
            'overtime_mode' => $fazlaMesaiModu,
            'overtime_rate' => pdks_hakedis_kurus_tl($fmKurus),
            'currency' => $currency,
            'valid_from' => $validFrom,
        ] + $saatDeger);
    }

    return ['ok' => true, 'id' => $id];
}

/**
 * v320 — GEÇMİŞ fiyat döneminin SAAT ayarlarını düzeltir (Tam / Yarım / FM başlangıcı / Çift eşiği + ücreti).
 * Sahip şikâyeti: 10 saat girilmiş dönem, sonradan 9 girilince yeni dönem yalnız İLERİYE geçerli olduğu için
 * eski günler 10 saatle hesaplanmaya devam ediyordu ve düzeltme yolu yoktu.
 * KAPILAR: saat kolonları kurulu · dönem var · gerekçe zorunlu (≤500) · dönemin tarih aralığında bu çavuşun
 * KESİNLEŞMİŞ (final) hakedişi varsa RED (önce yeniden açılmalı — kesin hakediş asla sessizce değişmez).
 * Doğrulama oran_ekle ile AYNI (`pdks_faz8b_saat_girdileri_dogrula`); boş alan = NULL (= mesainin kendi süresi).
 * Etki: tek UPDATE (yalnız 5 saat kolonu, ücretlere dokunmaz) + aralıktaki TASLAK hakedişler
 * `needs_recalculation` · audit `saat_duzelt` (eski/yeni + gerekçe). Tek transaction.
 */
function pdks_faz8b_oran_saat_duzelt(int $rateId, array $saatler, string $gerekce, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_saat_kolonlari_hazir($pdo)) return ['ok' => false, 'hata' => 'Saat kolonları kurulu değil (migrate.php).'];
    $gerekce = trim($gerekce);
    if ($gerekce === '' || mb_strlen($gerekce) > 500) return ['ok' => false, 'hata' => 'Düzeltme gerekçesi zorunludur (en fazla 500 karakter).'];
    $st = $pdo->prepare("SELECT * FROM foreman_worker_rates WHERE id = ?");
    $st->execute([$rateId]);
    $o = $st->fetch();
    if (!$o) return ['ok' => false, 'hata' => 'Fiyat dönemi bulunamadı.'];
    $foremanId = (int)$o['foreman_id'];

    $v = pdks_faz8b_saat_girdileri_dogrula($saatler, $foremanId, $pdo);
    if (!$v['ok']) return ['ok' => false, 'hata' => $v['hata']];
    $kolonlar = ['full_day_minutes', 'half_day_max_minutes', 'overtime_start_minutes', 'double_day_minutes', 'double_day_rate'];
    $yeni = array_fill_keys($kolonlar, null);
    foreach ($v['degerler'] as $k => $d) if (array_key_exists($k, $yeni)) $yeni[$k] = $d;
    $eski = array_intersect_key($o, $yeni);

    $tarihKosul = 's.foreman_id = ? AND s.work_date >= ?' . (!empty($o['valid_to']) ? ' AND s.work_date <= ?' : '');
    $tarihPar = array_merge([$foremanId, (string)$o['valid_from']], !empty($o['valid_to']) ? [(string)$o['valid_to']] : []);
    $hakVar = pdks_faz8b_kolon_var($pdo, 'foreman_daily_entitlements', 'status');
    try {
        $pdo->beginTransaction();
        if ($hakVar) {
            $stF = $pdo->prepare("SELECT COUNT(*) FROM foreman_daily_entitlements e JOIN daily_work_sessions s ON s.id = e.session_id
                                   WHERE $tarihKosul AND e.status = 'final'");
            $stF->execute($tarihPar);
            if ((int)$stF->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'hata' => 'Bu dönemde kesinleşmiş hakediş var — önce o günlerin hakedişini yeniden açın, sonra saatleri düzeltin.'];
            }
        }
        $set = implode(', ', array_map(fn($k) => "$k = ?", $kolonlar));
        $pdo->prepare("UPDATE foreman_worker_rates SET $set WHERE id = ?")->execute(array_merge(array_values($yeni), [$rateId]));
        $isaret = 0;
        if ($hakVar && pdks_faz8b_kolon_var($pdo, 'foreman_daily_entitlements', 'needs_recalculation')) {
            $stD = $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1
                                   WHERE status = 'draft' AND session_id IN (SELECT s.id FROM daily_work_sessions s WHERE $tarihKosul)");
            $stD->execute($tarihPar);
            $isaret = $stD->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Saatler düzeltilemedi: ' . $e->getMessage()];
    }
    if (function_exists('audit_log_event')) {
        audit_log_event('saat_duzelt', 'foreman_worker_rates', $rateId, $eski, $yeni + ['gerekce' => $gerekce, 'isaretlenen_taslak' => $isaret]);
    }
    return ['ok' => true, 'isaretlenen' => $isaret];
}

/** v299: fiyat geçmişi için kısa saat özeti — ['saatler' => 'Tam 9 saat · FM 10 saat · Yarım 5 saat', 'cift' => '2.000,00 TRY · 12 saat']. */
function pdks_faz8b_oran_saat_ozeti(array $o): array
{
    $e = static function ($v): string {
        return ($v === null || $v === '' || (int)$v <= 0) ? '' : pdks_faz8b_dakika_etiket((int)$v);
    };
    $p = [];
    if (($t = $e($o['full_day_minutes'] ?? null)) !== '') $p[] = 'Tam ' . $t;
    if (($t = $e($o['overtime_start_minutes'] ?? null)) !== '') $p[] = 'FM ' . $t;
    if (($t = $e($o['half_day_max_minutes'] ?? null)) !== '') $p[] = 'Yarım ' . $t . ' (bilgi)';
    $cift = '';
    $cd = $e($o['double_day_minutes'] ?? null);
    $cr = $o['double_day_rate'] ?? null;
    if ($cd !== '' && $cr !== null && $cr !== '') {
        $cift = number_format((float)$cr, 2, ',', '.') . ' ' . (string)($o['currency'] ?? '') . ' · ' . $cd;
    }
    return ['saatler' => implode(' · ', $p), 'cift' => $cift];
}

/**
 * v299 — fiyat dönemi saat girdilerini doğrular. $ham anahtarları:
 * full_day / half_day / overtime_start / double_day (saat "9" ya da "9:30")
 * ve double_day_rate (ücret). Hepsi boşsa ['ok'=>true,'degerler'=>[]].
 * Doluysa 5 kolonun tamamını (boşlar NULL) döner. Kural: saatler 60–1440 dk;
 * çift eşiği + çift ücret birlikte; tam ≤ FM başı < çift; yarım < tam; çift
 * ücret > 0. Tam boşsa karşılaştırma için çavuşun güncel normal süresi kullanılır.
 */
function pdks_faz8b_saat_girdileri_dogrula(array $ham, int $foremanId, PDO $pdo): array
{
    $alanlar = [
        'full_day' => ['full_day_minutes', 'Tam yevmiye saati'],
        'half_day' => ['half_day_max_minutes', 'Yarım yevmiye saati'],
        'overtime_start' => ['overtime_start_minutes', 'Fazla mesai başlangıç saati'],
        'double_day' => ['double_day_minutes', 'Çift yevmiye eşik saati'],
    ];
    $dk = [];
    $herhangi = false;
    foreach ($alanlar as $k => [$kolon, $etiket]) {
        $v = pdks_faz8b_saat_girdi_dk($ham[$k] ?? null);
        if ($v === -1) return ['ok' => false, 'hata' => $etiket . ' geçersiz — "9" ya da "9:30" biçiminde girin.'];
        if ($v !== null) {
            if ($v < PDKS_FAZ8B_SURE_MIN_DK || $v > PDKS_FAZ8B_SURE_MAX_DK) {
                return ['ok' => false, 'hata' => $etiket . ' 1–24 saat aralığında olmalıdır.'];
            }
            $herhangi = true;
        }
        $dk[$kolon] = $v;
    }
    $ciftHam = trim((string)($ham['double_day_rate'] ?? ''));
    $ciftKurus = null;
    if ($ciftHam !== '') {
        $ciftKurus = pdks_hakedis_girdi_kurus($ciftHam);
        if ($ciftKurus === null || $ciftKurus <= 0) return ['ok' => false, 'hata' => 'Çift Yevmiye ücreti geçersiz.'];
        $herhangi = true;
    }
    if (!$herhangi) return ['ok' => true, 'degerler' => []];

    if (!pdks_faz8b_saat_kolonlari_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Saat / Çift Yevmiye alanları için önce migrate.php\'den "Fiyat Dönemi Saatleri" kolonlarını kurun.'];
    }
    if (($dk['double_day_minutes'] === null) !== ($ciftKurus === null)) {
        return ['ok' => false, 'hata' => 'Çift Yevmiye için eşik saati ve ücret birlikte girilmeli (ya da ikisi de boş bırakılmalı).'];
    }
    $refTam = $dk['full_day_minutes'] ?? pdks_faz8b_cavus_normal_sure_dk($foremanId, $pdo);
    $refFm = $dk['overtime_start_minutes'] ?? $refTam;
    if ($dk['overtime_start_minutes'] !== null && $refFm < $refTam) {
        return ['ok' => false, 'hata' => 'Fazla mesai başlangıcı Tam yevmiye saatinden önce olamaz.'];
    }
    if ($dk['double_day_minutes'] !== null && $dk['double_day_minutes'] <= $refFm) {
        return ['ok' => false, 'hata' => 'Çift Yevmiye eşiği fazla mesai başlangıcından (' . pdks_faz8b_dakika_etiket($refFm) . ') büyük olmalıdır.'];
    }
    if ($dk['half_day_max_minutes'] !== null && $dk['half_day_max_minutes'] >= $refTam) {
        return ['ok' => false, 'hata' => 'Yarım yevmiye saati Tam yevmiye saatinden (' . pdks_faz8b_dakika_etiket($refTam) . ') küçük olmalıdır.'];
    }
    $dk['double_day_rate'] = $ciftKurus !== null ? pdks_hakedis_kurus_tl($ciftKurus) : null;
    return ['ok' => true, 'degerler' => $dk];
}

// =========================================================
// ÇAVUŞ ÜCRETİ — çavuşun kendi günlük çalışma ücreti (Faz 8B eki)
// foreman_worker_rates'in worker_type_id'siz eşdeğeri, ZORUNLU DEĞİL.
// AYRI tablo/AYRI hazır-mı kontrolü BİLEREK pdks_faz8b_sema_hazir()'e
// EKLENMEZ — bkz. plan §0: aksi hâlde bu opsiyonel migrasyonu henüz
// çalıştırmamış her kurulumda TÜM Faz 8B kilitlenirdi.
// =========================================================

function pdks_faz8b_cavus_ucret_tablolar(): array
{
    $t = [];
    $t['foreman_daily_rates'] = "CREATE TABLE IF NOT EXISTS `foreman_daily_rates` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT           NOT NULL,
        `daily_rate`         DECIMAL(12,2) NOT NULL,
        `currency`           VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `valid_from`         DATE          NOT NULL,
        `valid_to`           DATE          NULL DEFAULT NULL,
        `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
        `created_by_user_id` INT           NULL DEFAULT NULL,
        `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_fdr_foreman_from` (`foreman_id`, `valid_from`),
        INDEX `idx_fdr_active` (`is_active`),
        CONSTRAINT `fk_fdr_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $t;
}

/** pdks_faz9d_tablo_var() ile AYNI desen. */
function pdks_faz8b_cavus_ucret_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

function pdks_faz8b_cavus_ucret_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_faz8b_cavus_ucret_tablolar() as $ad => $sql) {
        if (pdks_faz8b_cavus_ucret_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[pdks_faz8b_cavus_ucret_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

function pdks_faz8b_cavus_ucret_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    return pdks_faz8b_cavus_ucret_tablo_var($pdo, 'foreman_daily_rates');
}

function pdks_faz8b_cavus_ucret_ekle(
    int $foremanId, string $ucretHam, string $validFrom, ?string $currency,
    int $userId, ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    $currency = trim((string)$currency) ?: 'TRY';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Geçerlilik başlangıç tarihi geçersiz.'];
    }
    $kurus = pdks_hakedis_girdi_kurus($ucretHam);
    if ($kurus === null || $kurus <= 0) return ['ok' => false, 'hata' => 'Günlük ücret geçersiz.'];

    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    $stMevcut = $pdo->prepare(
        "SELECT * FROM foreman_daily_rates WHERE foreman_id = ? AND is_active = 1
          ORDER BY valid_from DESC, id DESC LIMIT 1"
    );
    $stMevcut->execute([$foremanId]);
    $mevcut = $stMevcut->fetch();
    if ($mevcut && strtotime((string)$mevcut['valid_from']) >= strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Yeni başlangıç tarihi mevcut en son ücret döneminden sonra olmalıdır.'];
    }

    $pdo->beginTransaction();
    try {
        if ($mevcut && (($mevcut['valid_to'] ?? null) === null || strtotime((string)$mevcut['valid_to']) >= strtotime($validFrom))) {
            $bitis = date('Y-m-d', strtotime($validFrom . ' -1 day'));
            $pdo->prepare("UPDATE foreman_daily_rates SET valid_to = ? WHERE id = ?")
                ->execute([$bitis, (int)$mevcut['id']]);
        }
        $ins = $pdo->prepare(
            "INSERT INTO foreman_daily_rates
                (foreman_id, daily_rate, currency, valid_from, valid_to, is_active, created_by_user_id)
             VALUES (?,?,?,?,NULL,1,?)"
        );
        $ins->execute([$foremanId, pdks_hakedis_kurus_tl($kurus), $currency, $validFrom, $userId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Ücret dönemi kaydedilemedi: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_daily_rates', $id, null, [
            'foreman_id' => $foremanId, 'daily_rate' => pdks_hakedis_kurus_tl($kurus),
            'currency' => $currency, 'valid_from' => $validFrom,
        ]);
    }
    return ['ok' => true, 'id' => $id];
}

function pdks_faz8b_cavus_ucret_gecerli(int $foremanId, string $tarih, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_sema_hazir($pdo)) return null;
    $st = $pdo->prepare(
        "SELECT * FROM foreman_daily_rates
          WHERE foreman_id = ? AND is_active = 1
            AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
          ORDER BY valid_from DESC LIMIT 1"
    );
    $st->execute([$foremanId, $tarih, $tarih]);
    return $st->fetch() ?: null;
}

/** cavus_fiyatlari.php için tüm geçmiş — pdks_hakedis_oran_gecmisi() İLE AYNI ilke. */
function pdks_faz8b_cavus_ucret_gecmisi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM foreman_daily_rates WHERE foreman_id = ? ORDER BY valid_from DESC");
    $st->execute([$foremanId]);
    return $st->fetchAll();
}

/** Bugüne kadar hiçbir final oturum bu günün ücretini içermiyor mu? */
function pdks_faz8b_cavus_ucret_baska_final_var_mi(int $foremanId, string $workDate, int $haricSessionId, ?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT 1 FROM foreman_daily_entitlements e
           JOIN foreman_daily_entitlement_lines l ON l.entitlement_id = e.id
                AND l.worker_type_id IS NULL AND l.work_period_id IS NULL
                AND l.worker_type_code_snapshot = ''   -- v299: servis satırı (SERVIS_*) Çavuş Ücreti DEĞİL
          WHERE e.foreman_id = ? AND e.work_date = ? AND e.status = 'final' AND e.session_id <> ?
          LIMIT 1"
    );
    $st->execute([$foremanId, $workDate, $haricSessionId]);
    return (bool)$st->fetchColumn();
}

/** O gün, o çavuş için EN KÜÇÜK id'li, İŞLENMİŞ (voided olmayan) dönemi olan oturum. */
function pdks_faz8b_cavus_ucret_ankor_session_id(int $foremanId, string $workDate, ?PDO $pdo = null): ?int
{
    $pdo = $pdo ?? db();
    $kosul = pdks_gunluk_faz8j_etkin_kosul($pdo, 'p');
    $st = $pdo->prepare(
        "SELECT MIN(s.id) FROM daily_work_sessions s
          WHERE s.foreman_id = ? AND s.work_date = ?
            AND EXISTS (SELECT 1 FROM daily_worker_work_periods p WHERE p.session_id = s.id AND $kosul)"
    );
    $st->execute([$foremanId, $workDate]);
    $v = $st->fetchColumn();
    return ($v !== false && $v !== null) ? (int)$v : null;
}

/**
 * Bir oturumun dönemi değişince (ekleme/void/düzeltme/manuel çıkış) AYNI
 * çavuş+gün'deki KARDEŞ oturumun taslağı da "yeniden hesapla" işareti
 * almalı — B kesinleşince A'nın artık ankor olabileceği fark edilsin.
 * foreman_daily_entitlements zaten foreman_id + work_date taşır — session
 * JOIN'e gerek yok.
 */
function pdks_faz8b_cavus_ucret_kardes_isaretle(int $sessionId, ?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_sema_hazir($pdo)) return;
    $st = $pdo->prepare("SELECT foreman_id, work_date FROM foreman_daily_entitlements WHERE session_id = ?");
    $st->execute([$sessionId]);
    $r = $st->fetch();
    if (!$r) {
        // Henüz entitlement yoksa oturumdan oku.
        $st2 = $pdo->prepare("SELECT foreman_id, work_date FROM daily_work_sessions WHERE id = ?");
        $st2->execute([$sessionId]);
        $r = $st2->fetch();
        if (!$r) return;
    }
    $pdo->prepare(
        "UPDATE foreman_daily_entitlements SET needs_recalculation = 1
          WHERE status = 'draft' AND foreman_id = ? AND work_date = ? AND session_id <> ?"
    )->execute([(int)$r['foreman_id'], (string)$r['work_date'], $sessionId]);
}

// =========================================================
// ÇAVUŞ ÜCRETİ — YÖNTEM SEÇİMİ (A/B) + YÖNTEM B TABLOLARI
// pdks_faz8b_sema_hazir()'e BİLEREK EKLENMEZ.
//
// Yöntem A (mevcut, varsayılan): günlük sabit ücret — yukarıdaki blok.
// Yöntem B (YENİ): çavuşun altında çalışan kişi-gün toplamı her N'de
// 1 hakediş kazandırır (N = ÇAVUŞ BAZINDA birim, v297: yöntem geçmişindeki
// `unit_size`; NULL = PDKS_FAZ8B_CAVUS_B_BIRIM = 25); dönem kapanışı ödeme
// kaydında OTOMATİK yapılır (bkz. config/pdks_faz8b_cavus_b.php). Yöntem
// seçimi ÇAVUŞ BAZINDA, zaman damgalı bir GEÇMİŞ olarak tutulur
// (foreman_rate_method_log) — hangi yöntemin hangi tarihten itibaren
// geçerli olduğu ASLA kaybolmaz, geriye dönük hesaplar bozulmaz.
// =========================================================

defined('PDKS_FAZ8B_CAVUS_B_BIRIM') || define('PDKS_FAZ8B_CAVUS_B_BIRIM', 25);
// v297: çavuş bazında ayarlanabilir birim — geçerli aralık (sunucu doğrulaması).
defined('PDKS_FAZ8B_CAVUS_B_BIRIM_MIN') || define('PDKS_FAZ8B_CAVUS_B_BIRIM_MIN', 1);
defined('PDKS_FAZ8B_CAVUS_B_BIRIM_MAX') || define('PDKS_FAZ8B_CAVUS_B_BIRIM_MAX', 1000);

function pdks_faz8b_cavus_ucret_b_tablolar(): array
{
    $t = [];
    $t['foreman_rate_method_log'] = "CREATE TABLE IF NOT EXISTS `foreman_rate_method_log` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT          NOT NULL,
        `method`             VARCHAR(1)   NOT NULL,
        `unit_size`          INT          NULL DEFAULT NULL,
        `effective_at`       DATETIME     NOT NULL,
        `created_by_user_id` INT          NULL DEFAULT NULL,
        `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_frml_foreman` (`foreman_id`, `effective_at`),
        CONSTRAINT `fk_frml_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $t['foreman_period_closures'] = "CREATE TABLE IF NOT EXISTS `foreman_period_closures` (
        `id`                    INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`            INT           NOT NULL,
        `foreman_name_snapshot` VARCHAR(150)  NOT NULL DEFAULT '',
        `payment_id`            INT           NOT NULL,
        `closure_date`          DATE          NOT NULL,
        `prev_closure_id`       INT           NULL DEFAULT NULL,
        `chain_key`             VARCHAR(40)   NULL DEFAULT NULL,
        `unit_size`             INT           NOT NULL DEFAULT 25,
        `carry_in`              INT           NOT NULL DEFAULT 0,
        `period_person_days`    INT           NOT NULL DEFAULT 0,
        `total_person_days`     INT           NOT NULL DEFAULT 0,
        `earned_units`          INT           NOT NULL DEFAULT 0,
        `carry_out`             INT           NOT NULL DEFAULT 0,
        `rate_id`               INT           NULL DEFAULT NULL,
        `unit_rate`             DECIMAL(12,2) NOT NULL,
        `currency`              VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `amount`                DECIMAL(14,2) NOT NULL,
        `status`                VARCHAR(20)   NOT NULL DEFAULT 'valid',
        `created_by_user_id`    INT           NULL DEFAULT NULL,
        `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `cancelled_at`          DATETIME      NULL DEFAULT NULL,
        `cancelled_by_user_id`  INT           NULL DEFAULT NULL,
        `cancellation_reason`   TEXT          NULL DEFAULT NULL,
        UNIQUE KEY `uq_fpc_payment` (`payment_id`),
        UNIQUE KEY `uq_fpc_chain` (`chain_key`),
        INDEX `idx_fpc_foreman` (`foreman_id`, `status`),
        INDEX `idx_fpc_date` (`closure_date`),
        CONSTRAINT `fk_fpc_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fpc_payment` FOREIGN KEY (`payment_id`)
            REFERENCES `foreman_payments`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $t['foreman_period_closure_items'] = "CREATE TABLE IF NOT EXISTS `foreman_period_closure_items` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `closure_id`     INT          NOT NULL,
        `entitlement_id` INT          NOT NULL,
        `work_date`      DATE         NOT NULL,
        `depo`           VARCHAR(150) NOT NULL DEFAULT '',
        `person_days`    INT          NOT NULL DEFAULT 0,
        INDEX `idx_fpci_closure` (`closure_id`),
        INDEX `idx_fpci_entitlement` (`entitlement_id`),
        CONSTRAINT `fk_fpci_closure` FOREIGN KEY (`closure_id`)
            REFERENCES `foreman_period_closures`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_fpci_entitlement` FOREIGN KEY (`entitlement_id`)
            REFERENCES `foreman_daily_entitlements`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $t;
}

function pdks_faz8b_cavus_ucret_b_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

/**
 * Önkoşul: foremen, foreman_daily_entitlements, foreman_payments — bunlardan
 * biri eksikse HİÇ exec çalıştırılmaz (bu tabloların FK'ları o tablolara bağlı).
 */
function pdks_faz8b_cavus_ucret_b_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $onkosullar = ['foremen', 'foreman_daily_entitlements', 'foreman_payments'];
    $eksik = [];
    foreach ($onkosullar as $o) {
        if (!pdks_faz8b_cavus_ucret_b_tablo_var($pdo, $o)) $eksik[] = $o;
    }
    $rapor = [];
    if ($eksik) {
        foreach (pdks_faz8b_cavus_ucret_b_tablolar() as $ad => $sql) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'atlandi',
                'mesaj' => 'Önkoşul tablo eksik: ' . implode(', ', $eksik) . ' (önce Hakediş / Cari Hesap migrasyonunu çalıştırın).'];
        }
        return $rapor;
    }
    foreach (pdks_faz8b_cavus_ucret_b_tablolar() as $ad => $sql) {
        if (pdks_faz8b_cavus_ucret_b_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[pdks_faz8b_cavus_ucret_b_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    // v297: eski kurulumlarda yöntem geçmişine çavuş bazında birim kolonu
    // (NULL = varsayılan 25). Kolon varsa dokunulmaz (idempotent).
    if (pdks_faz8b_cavus_ucret_b_tablo_var($pdo, 'foreman_rate_method_log')) {
        pdks_gunluk_kolon_onbellek_temizle($pdo, 'foreman_rate_method_log');
        $k = pdks_faz8b_kolon_ekle($pdo, 'foreman_rate_method_log', 'unit_size', 'INT NULL DEFAULT NULL', 'method');
        $rapor[] = ['tablo' => 'foreman_rate_method_log.unit_size', 'durum' => $k['durum'], 'mesaj' => $k['mesaj']];
    }
    return $rapor;
}

/** v297: yöntem geçmişinde çavuş bazında birim kolonu var mı? (eski kurulum = yok) */
function pdks_faz8b_cavus_ucret_b_birim_kolonu_var(PDO $pdo): bool
{
    return pdks_faz8b_cavus_ucret_b_tablo_var($pdo, 'foreman_rate_method_log')
        && pdks_faz8b_kolon_var($pdo, 'foreman_rate_method_log', 'unit_size');
}

/**
 * v297: "Kaç kişi-gün = 1 hakediş" girdisini doğrular. null/boş → varsayılan
 * (25). Tam sayı değilse ya da 1–1000 dışındaysa null döner (çağıran reddeder).
 */
function pdks_faz8b_cavus_ucret_b_birim_dogrula($ham): ?int
{
    if ($ham === null) return PDKS_FAZ8B_CAVUS_B_BIRIM;
    if (is_int($ham)) {
        $n = $ham;
    } else {
        $t = trim((string)$ham);
        if ($t === '') return PDKS_FAZ8B_CAVUS_B_BIRIM;
        if (!preg_match('/^\d{1,6}$/', $t)) return null;
        $n = (int)$t;
    }
    if ($n < PDKS_FAZ8B_CAVUS_B_BIRIM_MIN || $n > PDKS_FAZ8B_CAVUS_B_BIRIM_MAX) return null;
    return $n;
}

/** v297: bir yöntem geçmişi satırının birimi (satır yok / NULL / bozuk → 25). */
function pdks_faz8b_cavus_ucret_b_satir_birimi(?array $satir): int
{
    $v = $satir['unit_size'] ?? null;
    if ($v === null || $v === '') return PDKS_FAZ8B_CAVUS_B_BIRIM;
    $n = (int)$v;
    return ($n >= PDKS_FAZ8B_CAVUS_B_BIRIM_MIN && $n <= PDKS_FAZ8B_CAVUS_B_BIRIM_MAX) ? $n : PDKS_FAZ8B_CAVUS_B_BIRIM;
}

function pdks_faz8b_cavus_ucret_b_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(pdks_faz8b_cavus_ucret_b_tablolar()) as $ad) {
        if (!pdks_faz8b_cavus_ucret_b_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/** cavus_fiyatlari.php için — bir çavuşun TÜM yöntem değişim geçmişi, eskiden yeniye. */
function pdks_faz8b_cavus_ucret_yontem_gecmisi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_tablo_var($pdo, 'foreman_rate_method_log')) return [];
    $st = $pdo->prepare(
        "SELECT id, foreman_id, method, effective_at, created_by_user_id, created_at"
        . (pdks_faz8b_cavus_ucret_b_birim_kolonu_var($pdo) ? ", unit_size" : ", NULL AS unit_size")
        . " FROM foreman_rate_method_log WHERE foreman_id = ? ORDER BY effective_at ASC, id ASC"
    );
    $st->execute([$foremanId]);
    return $st->fetchAll();
}

/**
 * SAF fonksiyon: verilen bir $gecmis (artan effective_at) dizisinde, $zaman
 * anında geçerli yöntemi döner. Kayıt yoksa/hiçbiri henüz geçerli değilse 'A'.
 */
function pdks_faz8b_cavus_ucret_yontem_anda(array $gecmis, string $zaman): string
{
    $kopya = $gecmis;
    usort($kopya, function ($a, $b) {
        $c = strcmp((string)$a['effective_at'], (string)$b['effective_at']);
        return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
    });
    $sonuc = 'A';
    foreach ($kopya as $k) {
        if ((string)$k['effective_at'] <= $zaman) {
            $sonuc = ((string)$k['method'] === 'B') ? 'B' : 'A';
        }
    }
    return $sonuc;
}

/**
 * v297 — SAF: $zaman anında geçerli geçmiş satırı (yontem_anda() ile AYNI
 * zaman kuralı: effective_at <= $zaman olan en son satır). Yoksa null.
 */
function pdks_faz8b_cavus_ucret_satir_anda(array $gecmis, string $zaman): ?array
{
    $kopya = $gecmis;
    usort($kopya, function ($a, $b) {
        $c = strcmp((string)$a['effective_at'], (string)$b['effective_at']);
        return $c !== 0 ? $c : ((int)$a['id'] <=> (int)$b['id']);
    });
    $sonuc = null;
    foreach ($kopya as $k) {
        if ((string)$k['effective_at'] <= $zaman) $sonuc = $k;
    }
    return $sonuc;
}

/**
 * v297 — SAF: $zaman anında geçerli Yöntem B birimi ("kaç kişi-gün = 1
 * hakediş"). Geçerli satır yoksa / unit_size NULL ise varsayılan (25).
 */
function pdks_faz8b_cavus_ucret_birim_anda(array $gecmis, string $zaman): int
{
    return pdks_faz8b_cavus_ucret_b_satir_birimi(pdks_faz8b_cavus_ucret_satir_anda($gecmis, $zaman));
}

/** v297: çavuşun ŞU AN geçerli Yöntem B birimi (kapanış, önizleme, ekran). */
function pdks_faz8b_cavus_ucret_birim(int $foremanId, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_birim_kolonu_var($pdo)) return PDKS_FAZ8B_CAVUS_B_BIRIM;
    return pdks_faz8b_cavus_ucret_birim_anda(
        pdks_faz8b_cavus_ucret_yontem_gecmisi($foremanId, $pdo), date('Y-m-d H:i:s')
    );
}

function pdks_faz8b_cavus_ucret_yontem(int $foremanId, ?PDO $pdo = null): string
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) return 'A';
    $st = $pdo->prepare(
        "SELECT method FROM foreman_rate_method_log WHERE foreman_id = ?
          ORDER BY effective_at DESC, id DESC LIMIT 1"
    );
    $st->execute([$foremanId]);
    $v = $st->fetchColumn();
    return ($v === 'B') ? 'B' : 'A';
}

/**
 * v297: $birimHam yalnız Yöntem B'de anlamlıdır ("kaç kişi-gün = 1 hakediş",
 * 1–1000; null/boş = varsayılan 25). B seçiliyken YALNIZ birimin değişmesi de
 * YENİ, zaman damgalı bir geçmiş satırı yazar — geriye dönük DEĞİLDİR; mevcut
 * kapanışlar (kendi unit_size'ları donmuş) hiç değişmez.
 */
function pdks_faz8b_cavus_ucret_yontem_degistir(int $foremanId, string $yontem, int $userId, ?PDO $pdo = null, $birimHam = null): array
{
    $pdo = $pdo ?? db();
    $yontem = strtoupper(trim($yontem));
    if (!in_array($yontem, ['A', 'B'], true)) {
        return ['ok' => false, 'kod' => 'gecersiz_yontem', 'hata' => 'Geçersiz hesaplama yöntemi.'];
    }
    $birim = null;
    if ($yontem === 'B') {
        $birim = pdks_faz8b_cavus_ucret_b_birim_dogrula($birimHam);
        if ($birim === null) {
            return ['ok' => false, 'kod' => 'gecersiz_birim', 'hata' => sprintf(
                '"Kaç kişi-gün = 1 hakediş" %d ile %d arasında bir tam sayı olmalıdır.',
                PDKS_FAZ8B_CAVUS_B_BIRIM_MIN, PDKS_FAZ8B_CAVUS_B_BIRIM_MAX
            )];
        }
    }
    if (!pdks_faz8b_cavus_ucret_b_sema_hazir($pdo)) {
        return ['ok' => false, 'kod' => 'sema_yok', 'hata' => 'Yöntem B tabloları henüz oluşturulmamış.'];
    }
    $birimKolonu = pdks_faz8b_cavus_ucret_b_birim_kolonu_var($pdo);
    if ($yontem === 'B' && !$birimKolonu && $birim !== PDKS_FAZ8B_CAVUS_B_BIRIM) {
        return ['ok' => false, 'kod' => 'birim_kolonu_yok',
            'hata' => 'Birim kolonu henüz oluşturulmamış — yönetici migrate.php → Yöntem B tablolarını çalıştırmalı.'];
    }
    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'kod' => 'cavus_yok', 'hata' => 'Çavuş bulunamadı.'];

    $eski = pdks_faz8b_cavus_ucret_yontem($foremanId, $pdo);
    $eskiBirim = $eski === 'B' ? pdks_faz8b_cavus_ucret_birim($foremanId, $pdo) : null;
    if ($eski === $yontem && ($yontem === 'A' || $eskiBirim === $birim)) {
        return ['ok' => true, 'degisti' => false, 'yontem' => $yontem, 'birim' => $birim];
    }

    $kendiTx = !$pdo->inTransaction();
    if ($kendiTx) $pdo->beginTransaction();
    try {
        $simdi = date('Y-m-d H:i:s');
        if ($birimKolonu) {
            $ins = $pdo->prepare(
                "INSERT INTO foreman_rate_method_log (foreman_id, method, unit_size, effective_at, created_by_user_id, created_at)
                 VALUES (?,?,?,?,?,?)"
            );
            $ins->execute([$foremanId, $yontem, $birim, $simdi, $userId, $simdi]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO foreman_rate_method_log (foreman_id, method, effective_at, created_by_user_id, created_at)
                 VALUES (?,?,?,?,?)"
            );
            $ins->execute([$foremanId, $yontem, $simdi, $userId, $simdi]);
        }
        $id = (int)$pdo->lastInsertId();

        // Yalnız birim değiştiyse (B→B) günlük hakediş satırları etkilenmez —
        // yeniden hesap bayrağı yalnız YÖNTEM değiştiğinde konur.
        if ($eski !== $yontem && pdks_faz8b_kolon_var($pdo, 'foreman_daily_entitlements', 'needs_recalculation')) {
            $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1 WHERE foreman_id = ? AND status = 'draft'")
                ->execute([$foremanId]);
        }
        if ($kendiTx) $pdo->commit();
    } catch (Throwable $e) {
        if ($kendiTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Yöntem kaydedilemedi: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'foreman_rate_method_log', $id,
            ['foreman_id' => $foremanId, 'method' => $eski, 'unit_size' => $eskiBirim],
            ['foreman_id' => $foremanId, 'method' => $yontem, 'unit_size' => $birim, 'effective_at' => $simdi]);
    }
    return ['ok' => true, 'degisti' => true, 'id' => $id, 'eski' => $eski, 'eski_birim' => $eskiBirim,
        'yontem' => $yontem, 'birim' => $birim];
}

/** $birim: Yöntem B'nin "kaç kişi-gün = 1 hakediş" değeri (null = varsayılan 25). */
function pdks_faz8b_cavus_ucret_yontem_etiketi(string $yontem, ?int $birim = null): string
{
    return $yontem === 'B'
        ? 'Yöntem B — ' . ($birim ?? PDKS_FAZ8B_CAVUS_B_BIRIM) . ' kişi-gün = 1 hakediş'
        : 'Yöntem A — Günlük sabit ücret';
}

// =========================================================
// HAKEDİŞ — FAZ 8B OTORİTER HESAP
// =========================================================

function pdks_faz8b_hakedis_hesapla(int $sessionId, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) {
        return ['ok' => false, 'kod' => 'faz8b_sema_yok', 'hata' => 'Faz 8B şeması hazır değil.'];
    }

    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    $stE = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE session_id = ?");
    $stE->execute([$sessionId]);
    $mevcut = $stE->fetch();
    if ($mevcut && ($mevcut['status'] ?? '') === 'final') {
        return ['ok' => false, 'kod' => 'zaten_kesinlesmis', 'hata' => 'Bu mesainin hakedişi zaten KESİNLEŞMİŞ.'];
    }

    // v295: atanmamış Karışık dönem varken hakediş HESAPLANMAZ — "geçerli fiyat
    // yok" yerine açık mesaj; finalize da bu fonksiyondan geçtiği için kesinleşemez.
    if (($karisikEngel = pdks_hakedis_karisik_engeli($sessionId, $pdo)) !== null) {
        return $karisikEngel;
    }

    $donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
    if (!$donemler) {
        return ['ok' => false, 'kod' => 'kart_yok', 'hata' => 'Bu mesaide hesaplanacak işçi dönemi yok.'];
    }

    $satirlar = [];
    $eksikler = [];
    $paraBirimleri = [];
    $toplamKurus = 0;
    $stKod = $pdo->prepare("SELECT code FROM worker_types WHERE id = ?");

    foreach ($donemler as $d) {
        $f = $d['faz8b'];
        if (!$f['finans_hazir']) {
            $eksikler[] = ($d['card_no'] ?? ('#' . $d['id'])) . ' — muhasebe değerlendirmesi bekliyor';
            continue;
        }

        $tipId = $d['worker_type_id_snapshot'] !== null ? (int)$d['worker_type_id_snapshot'] : null;
        if (!$tipId) {
            $eksikler[] = ($d['card_no'] ?? ('#' . $d['id'])) . ' — işçi tipi eksik';
            continue;
        }

        $oran = pdks_hakedis_oran_gecerli(
            (int)$oturum['foreman_id'],
            $tipId,
            (string)$oturum['work_date'],
            $pdo
        );
        if (!$oran) {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçerli fiyat yok';
            continue;
        }

        // v299: sınıf + ödenecek FM TEK sınıflandırıcıdan (pdks_faz8b_donem_siniflandir);
        // dönem satırı aynı fiyat dönemiyle sınıflandırıldı. 'cift' → çift ücret.
        $sinif = (string)$f['etkin_sinif'];
        $sinifEtiket = pdks_faz8b_sinif_etiketi($sinif);
        $baseRaw = $sinif === 'yarim' ? ($oran['half_day_rate'] ?? null)
            : ($sinif === 'cift' ? ($oran['double_day_rate'] ?? null) : ($oran['daily_rate'] ?? null));
        if ($baseRaw === null || $baseRaw === '') {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — ' . $sinifEtiket . ' Mesai fiyatı yok';
            continue;
        }
        try {
            $baseKurus = pdks_hakedis_tl_kurus((string)$baseRaw);
        } catch (Throwable $e) {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçersiz temel fiyat';
            continue;
        }

        // Faz 9C / UX-03: hakediş OTORİTER olarak ONAYLANAN saati kullanır
        // (fazla_mesai_onay_saat), HESAPLANAN adayı (fazla_mesai_saat)
        // DEĞİL — muhasebe adayın altında kısmi onay vermiş olabilir.
        // v299: Tam'da = onaylı saat (adaya kırpılmış); Çift'te = çift eşiğinden
        // SONRAKİ onaylı saat (sabit FM modunda 0 — çift zaten ödüyor).
        $fmOnaySaat = (int)($f['odenecek_fm_saat'] ?? 0);
        $fmOnayli = $fmOnaySaat > 0;
        $fmMode = null;
        $fmBirimKurus = 0;
        $fmToplamKurus = 0;

        if ($fmOnayli) {
            $fmMode = trim((string)($oran['overtime_mode'] ?? ''));
            $fmRaw = $oran['overtime_rate'] ?? null;
            if (!in_array($fmMode, ['hourly', 'fixed'], true) || $fmRaw === null || $fmRaw === '') {
                $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — Fazla Mesai fiyatı/tipi yok';
                continue;
            }
            try {
                $fmBirimKurus = pdks_hakedis_tl_kurus((string)$fmRaw);
            } catch (Throwable $e) {
                $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçersiz Fazla Mesai fiyatı';
                continue;
            }
            $fmToplamKurus = $fmMode === 'fixed'
                ? $fmBirimKurus
                : ($fmBirimKurus * $fmOnaySaat);
        }

        $para = trim((string)($oran['currency'] ?? 'TRY')) ?: 'TRY';
        $paraBirimleri[$para] = true;
        $stKod->execute([$tipId]);
        $kod = (string)($stKod->fetchColumn() ?: '');

        $lineKurus = $baseKurus + $fmToplamKurus;
        $toplamKurus += $lineKurus;
        $satirlar[] = [
            'work_period_id' => (int)$d['id'],
            'worker_type_id' => $tipId,
            'worker_type_code_snapshot' => $kod,
            'worker_type_name_snapshot' => (string)$d['worker_type_name_snapshot'],
            'attendance_class_snapshot' => $sinif,
            'worker_count' => 1,
            'unit_rate' => pdks_hakedis_kurus_tl($baseKurus),
            'overtime_hours' => $fmOnaySaat,
            'overtime_mode_snapshot' => $fmOnayli ? $fmMode : null,
            'overtime_unit_rate' => pdks_hakedis_kurus_tl($fmBirimKurus),
            'overtime_total' => pdks_hakedis_kurus_tl($fmToplamKurus),
            'line_total' => pdks_hakedis_kurus_tl($lineKurus),
        ];
    }

    // Çavuş Ücreti (Faz 8B eki): ZORUNLU DEĞİL — ücret tanımlıysa VE bu
    // oturum o gün+çavuş için ANKOR (en küçük id'li, işlenmiş dönemi olan)
    // oturumsa VE ücret başka hiçbir FİNAL oturumda zaten YOKSA 1 satır eklenir.
    // Yöntem B seçili çavuşa satır EKLENMEZ — B ücreti dönem kapanışında
    // (pdks_faz8b_cavus_b.php) tahakkuk eder.
    if (pdks_faz8b_cavus_ucret_sema_hazir($pdo) && pdks_faz8b_cavus_ucret_yontem((int)$oturum['foreman_id'], $pdo) === 'A') {
        $workDate = (string)$oturum['work_date'];
        $foremanId = (int)$oturum['foreman_id'];
        $zatenBaskaFinaldeVar = pdks_faz8b_cavus_ucret_baska_final_var_mi($foremanId, $workDate, $sessionId, $pdo);
        $ankor = $zatenBaskaFinaldeVar ? null : pdks_faz8b_cavus_ucret_ankor_session_id($foremanId, $workDate, $pdo);
        if (!$zatenBaskaFinaldeVar && $ankor === $sessionId) {
            $ucret = pdks_faz8b_cavus_ucret_gecerli($foremanId, $workDate, $pdo);
            if ($ucret) {
                $paraCavus = trim((string)($ucret['currency'] ?? 'TRY')) ?: 'TRY';
                $paraBirimleri[$paraCavus] = true;   // ⚠ mevcut "karisik_para_birimi" guard'ına KATILIR
                $ucretKurus = pdks_hakedis_tl_kurus((string)$ucret['daily_rate']);
                $toplamKurus += $ucretKurus;
                $satirlar[] = [
                    'work_period_id' => null,
                    'worker_type_id' => null,
                    'worker_type_code_snapshot' => '',
                    'worker_type_name_snapshot' => 'Çavuş Ücreti',
                    'attendance_class_snapshot' => 'tam',
                    'worker_count' => 1,
                    'unit_rate' => pdks_hakedis_kurus_tl($ucretKurus),
                    'overtime_hours' => 0,
                    'overtime_mode_snapshot' => null,
                    'overtime_unit_rate' => '0.00',
                    'overtime_total' => '0.00',
                    'line_total' => pdks_hakedis_kurus_tl($ucretKurus),
                ];
            }
        }
    }

    // v299 Servis Ücreti: mesainin iptal edilmemiş servisleri tür başına 1 satır
    // (worker_type_id/work_period_id NULL, kod SERVIS_BUYUK|SERVIS_KUCUK — Çavuş
    // Ücreti dedektörleri kod '' ister). Yöntem A/B fark etmez. Fiyat yoksa
    // eksik → hesap DURUR; para birimi karışık para kapısına KATILIR.
    // Tablo yoksa boş döner (özellik kurulmamış = eski davranış).
    $servis = pdks_servis_hakedis_satirlari($oturum, $pdo);
    foreach ($servis['eksikler'] as $se) $eksikler[] = $se;
    if ($servis['satirlar']) {
        $paraBirimleri[(string)$servis['para']] = true;
        $toplamKurus += (int)$servis['toplam_kurus'];
        foreach ($servis['satirlar'] as $ss) $satirlar[] = $ss;
    }

    // Validate-first: hiçbir finansal satır değiştirilmeden önce tüm kararlar
    // ve fiyatlar tamam olmalıdır.
    if ($eksikler) {
        return [
            'ok' => false,
            'kod' => 'faz8b_degerlendirme_gerekli',
            'hata' => 'Hakediş hesaplanamadı: ' . implode('; ', array_slice($eksikler, 0, 4)),
            'eksikler' => $eksikler,
        ];
    }
    if (count($paraBirimleri) > 1) {
        return ['ok' => false, 'kod' => 'karisik_para_birimi', 'hata' => 'Bu mesai için farklı para birimleri karışıyor.'];
    }
    if (!$satirlar) {
        return ['ok' => false, 'kod' => 'satir_yok', 'hata' => 'Hakediş için finansal satır oluşmadı.'];
    }

    $paraBirimi = (string)array_key_first($paraBirimleri);
    $simdi = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($mevcut) {
            $entId = (int)$mevcut['id'];
            $pdo->prepare("DELETE FROM foreman_daily_entitlement_lines WHERE entitlement_id = ?")
                ->execute([$entId]);
            $pdo->prepare(
                "UPDATE foreman_daily_entitlements
                    SET status = 'draft', currency = ?, total_amount = ?, needs_recalculation = 0,
                        calculated_at = ?, calculated_by_user_id = ?, updated_at = ?
                  WHERE id = ?"
            )->execute([
                $paraBirimi,
                pdks_hakedis_kurus_tl($toplamKurus),
                $simdi,
                $userId,
                $simdi,
                $entId,
            ]);
        } else {
            $insE = $pdo->prepare(
                "INSERT INTO foreman_daily_entitlements
                    (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot,
                     work_date, depo, status, currency, total_amount, needs_recalculation,
                     calculated_at, calculated_by_user_id)
                 VALUES (?,?,?,?,?,?,'draft',?,?,0,?,?)"
            );
            $insE->execute([
                $sessionId,
                (int)$oturum['foreman_id'],
                (string)($oturum['foreman_name_snapshot'] ?? ''),
                (string)($oturum['foreman_code_snapshot'] ?? ''),
                (string)$oturum['work_date'],
                (string)($oturum['depo'] ?? ''),
                $paraBirimi,
                pdks_hakedis_kurus_tl($toplamKurus),
                $simdi,
                $userId,
            ]);
            $entId = (int)$pdo->lastInsertId();
        }

        $insL = $pdo->prepare(
            "INSERT INTO foreman_daily_entitlement_lines
                (entitlement_id, work_period_id, worker_type_id,
                 worker_type_code_snapshot, worker_type_name_snapshot,
                 attendance_class_snapshot, worker_count, unit_rate,
                 overtime_hours, overtime_mode_snapshot, overtime_unit_rate,
                 overtime_total, line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($satirlar as $sl) {
            $insL->execute([
                $entId,
                $sl['work_period_id'],
                $sl['worker_type_id'],
                $sl['worker_type_code_snapshot'],
                $sl['worker_type_name_snapshot'],
                $sl['attendance_class_snapshot'],
                $sl['worker_count'],
                $sl['unit_rate'],
                $sl['overtime_hours'],
                $sl['overtime_mode_snapshot'],
                $sl['overtime_unit_rate'],
                $sl['overtime_total'],
                $sl['line_total'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Hakediş yazılamadı: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('calculate', 'foreman_daily_entitlements', $entId, null, [
            'session_id' => $sessionId,
            'faz' => '8B',
            'total_amount' => pdks_hakedis_kurus_tl($toplamKurus),
        ]);
    }

    return [
        'ok' => true,
        'entitlement_id' => $entId,
        'status' => 'draft',
        'total_amount' => pdks_hakedis_kurus_tl($toplamKurus),
        'lines' => $satirlar,
    ];
}

function pdks_faz8b_hakedis_finalize(
    int $sessionId,
    int $userId,
    bool $eksikCikisOnayi,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();

    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    if ((string)$oturum['status'] !== 'closed') {
        return ['ok' => false, 'kod' => 'oturum_acik', 'hata' => 'Mesai AÇIK — hakediş yalnız KAPALI mesai için kesinleştirilebilir.'];
    }

    $ozet = pdks_gunluk_oturum_ozet($sessionId, $pdo);
    $eksikToplam = (int)($ozet['eksik_toplam'] ?? 0);
    if ($eksikToplam > 0 && !$eksikCikisOnayi) {
        return ['ok' => false, 'kod' => 'eksik_cikis_onay_gerekli', 'hata' => 'Eksik çıkış var. Devam etmek için açık onay gerekir.'];
    }

    $hesap = pdks_faz8b_hakedis_hesapla($sessionId, $userId, $pdo);
    if (!$hesap['ok']) return $hesap;

    $simdi = date('Y-m-d H:i:s');
    $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET status = 'final', finalized_at = ?, finalized_by_user_id = ?,
                missing_exit_ack = ?, needs_recalculation = 0, updated_at = ?
          WHERE id = ?"
    )->execute([
        $simdi,
        $userId,
        $eksikToplam > 0 ? 1 : 0,
        $simdi,
        (int)$hesap['entitlement_id'],
    ]);

    if (function_exists('audit_log_event')) {
        audit_log_event('finalize', 'foreman_daily_entitlements', (int)$hesap['entitlement_id'], null, [
            'session_id' => $sessionId,
            'faz' => '8B',
            'total_amount' => $hesap['total_amount'],
        ]);
    }

    return [
        'ok' => true,
        'entitlement_id' => (int)$hesap['entitlement_id'],
        'status' => 'final',
        'total_amount' => $hesap['total_amount'],
    ];
}

// v299 Servis Ücreti — hakediş motoru pdks_servis_hakedis_satirlari()'nı çağırır.
// pdks_servis.php bu dosyayı require_once eder (döngü güvenli: yalnız fonksiyon tanımları).
require_once __DIR__ . '/pdks_servis.php';
