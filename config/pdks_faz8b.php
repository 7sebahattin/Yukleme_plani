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

    $mesaj = 'Faz 8B şeması henüz hazır değil. Yönetici faz8b_migrate.php sayfasından migrasyonu çalıştırmalıdır.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Faz 8B');
    if (function_exists('render_flash')) render_flash();
    elseif (function_exists('h')) echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    else echo $mesaj;
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// SÜRE / TOLERANS POLİTİKASI
// =========================================================

/**
 * Faz 9C / H-02: SÜRE-TABANLI karar — saat-kilidi (clock-of-day boundary)
 * YOKTUR. Yalnız GEÇEN SÜRE (dk), çağıranın verdiği $normalDk (oturumun
 * normal_work_minutes_snapshot'ı) ile karşılaştırılır.
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
function pdks_faz8b_sure_karari(?string $giris, ?string $cikis, int $normalDk = PDKS_FAZ8B_NORMAL_DK): array
{
    $bos = [
        'toplam_dk' => null,
        'normal_dk' => $normalDk,
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

    $toplamDk = intdiv($c - $g, 60);
    $otomatikTam = $toplamDk >= $normalDk;

    $fazlaDk = $toplamDk > $normalDk ? ($toplamDk - $normalDk) : 0;
    $fmSaat = 0;
    if ($fazlaDk > PDKS_FAZ8B_TOLERANS_DK) {
        // 15 dk toleransı çıkar; kalan her başlayan saat yukarı yuvarlanır.
        $ucretDk = $fazlaDk - PDKS_FAZ8B_TOLERANS_DK;
        $fmSaat = intdiv($ucretDk + 59, 60);
    }

    return [
        'toplam_dk' => $toplamDk,
        'normal_dk' => $normalDk,
        'otomatik_sinif' => $otomatikTam ? 'tam' : null,
        'sinif_onayi_gerekli' => !$otomatikTam,
        'fazla_dk' => $fazlaDk,
        'fazla_mesai_saat' => $fmSaat,
        'fazla_mesai_onayi_gerekli' => $fmSaat > 0,
    ];
}

/**
 * $donem — daily_worker_work_periods satırı, TERCİHEN oturumun
 * normal_work_minutes_snapshot'ını da taşımalıdır (bkz.
 * pdks_faz8b_oturum_donemleri()'nin JOIN'i). Anahtar yoksa/boşsa (ör.
 * çağıran ham bir satır geçiyorsa) PDKS_FAZ8B_NORMAL_DK'ya (540 dk) düşülür
 * — eski davranışla AYNI son çare, hiçbir yerde HATA vermez.
 */
function pdks_faz8b_donem_finans_durumu(array $donem): array
{
    $normalDk = (int)($donem['normal_work_minutes_snapshot'] ?? PDKS_FAZ8B_NORMAL_DK);
    if ($normalDk <= 0) $normalDk = PDKS_FAZ8B_NORMAL_DK;
    $sure = pdks_faz8b_sure_karari($donem['entry_time'] ?? null, $donem['exit_time'] ?? null, $normalDk);

    $onayliSinif = trim((string)($donem['approved_attendance_class'] ?? ''));
    $sinif = $sure['otomatik_sinif'];
    $sinifKaynak = 'otomatik';
    if ($sinif === null) {
        $sinif = in_array($onayliSinif, ['tam', 'yarim'], true) ? $onayliSinif : null;
        $sinifKaynak = $sinif !== null ? 'muhasebe' : 'bekliyor';
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    // Faz 9C / UX-03: OTORİTER FM onayı artık SAAT SAYISIdır (yalnız
    // onayla/reddet ikili bayrağı DEĞİL) — 0..$fmSaat aralığında, muhasebe
    // hesaplanan adayın altına inebilir. Eski `overtime_approved` bayrağı
    // yalnız GERİYE DÖNÜK/rozet amaçlı türetilir (0 saat => reddedildi,
    // >0 saat => onaylı), OTORİTE bu satırda DEĞİL, overtime_approved_hours'ta.
    $fmOnaySaatHam = $donem['overtime_approved_hours'] ?? null;
    $fmOnaySaat = ($fmOnaySaatHam === '' || $fmOnaySaatHam === null) ? null : (int)$fmOnaySaatHam;

    $fmDurum = 'yok';
    if ($fmSaat > 0) {
        $fmDurum = $fmOnaySaat === null ? 'bekliyor' : ($fmOnaySaat > 0 ? 'onayli' : 'reddedildi');
    }

    return $sure + [
        'etkin_sinif' => $sinif,
        'sinif_kaynak' => $sinifKaynak,
        'fazla_mesai_durum' => $fmDurum,
        'fazla_mesai_onay_saat' => $fmOnaySaat,
        'finans_hazir' => $sinif !== null && ($fmSaat === 0 || $fmOnaySaat !== null),
    ];
}

function pdks_faz8b_oturum_donemleri(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    // ⚠ Faz 9C / H-02: s.normal_work_minutes_snapshot EKLENDİ — her dönem
    // KENDİ oturumunun DONMUŞ normal süresiyle değerlendirilir (bkz.
    // pdks_faz8b_donem_finans_durumu). p.* önce geldiği için s.'nin
    // normal_work_minutes_snapshot'ı p tarafında aynı adlı bir kolon YOKSA
    // (ki yok) çakışmaz.
    $st = $pdo->prepare(
        "SELECT p.*, w.card_no, s.normal_work_minutes_snapshot
           FROM daily_worker_work_periods p
           JOIN worker_cards w ON w.id = p.worker_card_id
           JOIN daily_work_sessions s ON s.id = p.session_id
          WHERE p.session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
          ORDER BY p.entry_time ASC, p.id ASC"
    );
    $st->execute([$sessionId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['faz8b'] = pdks_faz8b_donem_finans_durumu($r);
    unset($r);
    return $rows;
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

    $st = $pdo->prepare("SELECT * FROM daily_worker_work_periods WHERE id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo));
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı.'];

    $stFinal = $pdo->prepare(
        "SELECT id FROM foreman_daily_entitlements
          WHERE session_id = ? AND status = 'final' LIMIT 1"
    );
    $stFinal->execute([(int)$p['session_id']]);
    if ($stFinal->fetchColumn()) {
        return ['ok' => false, 'hata' => 'Bu oturumun hakedişi KESİN. Önce yönetici kontrollü olarak hakedişi yeniden açmalıdır.'];
    }

    // Faz 9C / H-02: bu dönemin OTORİTER normal süresi kendi oturumunun
    // donmuş anlık görüntüsüdür — canlı çavuş ayarından DEĞİL.
    $stSess = $pdo->prepare("SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id = ?");
    $stSess->execute([(int)$p['session_id']]);
    $normalDk = (int)($stSess->fetchColumn() ?: PDKS_FAZ8B_NORMAL_DK);
    if ($normalDk <= 0) $normalDk = PDKS_FAZ8B_NORMAL_DK;

    $sure = pdks_faz8b_sure_karari($p['entry_time'] ?? null, $p['exit_time'] ?? null, $normalDk);
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

    $stSess = $pdo->prepare("SELECT normal_work_minutes_snapshot FROM daily_work_sessions WHERE id = ?");
    $stSess->execute([$sessionId]);
    $normalDk = (int)($stSess->fetchColumn() ?: PDKS_FAZ8B_NORMAL_DK);
    if ($normalDk <= 0) $normalDk = PDKS_FAZ8B_NORMAL_DK;

    $basarili = 0; $atlandi = 0; $hatalar = [];
    foreach (array_unique($periodIds) as $periodId) {
        $periodId = (int)$periodId;
        if ($periodId <= 0) { $atlandi++; continue; }

        // ⚠ session_id = ? SATIRDA — başka oturumun dönemi id tahmin edilerek
        // buraya karıştırılamaz (aynı IDOR ihtiyatı sayfanın kendisiyle AYNI).
        $st = $pdo->prepare(
            "SELECT entry_time, exit_time FROM daily_worker_work_periods
              WHERE id = ? AND session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo)
        );
        $st->execute([$periodId, $sessionId]);
        $donem = $st->fetch();
        if (!$donem) { $atlandi++; continue; }

        $sure = pdks_faz8b_sure_karari($donem['entry_time'] ?? null, $donem['exit_time'] ?? null, $normalDk);
        if (!$sure['sinif_onayi_gerekli']) { $atlandi++; continue; }   // zaten Otomatik Tam — üzerine YAZILMAZ

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
    ?PDO $pdo = null
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

        $ins = $pdo->prepare(
            "INSERT INTO foreman_worker_rates
                (foreman_id, worker_type_id, daily_rate, half_day_rate,
                 overtime_mode, overtime_rate, currency, valid_from, valid_to,
                 is_active, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,NULL,1,?)"
        );
        $ins->execute([
            $foremanId,
            $workerTypeId,
            pdks_hakedis_kurus_tl($tamKurus),
            pdks_hakedis_kurus_tl($yarimKurus),
            $fazlaMesaiModu,
            pdks_hakedis_kurus_tl($fmKurus),
            $currency,
            $validFrom,
            $userId,
        ]);
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
        ]);
    }

    return ['ok' => true, 'id' => $id];
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

        $sinif = (string)$f['etkin_sinif'];
        $baseRaw = $sinif === 'yarim' ? ($oran['half_day_rate'] ?? null) : ($oran['daily_rate'] ?? null);
        if ($baseRaw === null || $baseRaw === '') {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — ' . ($sinif === 'yarim' ? 'Yarım' : 'Tam') . ' Mesai fiyatı yok';
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
        $fmSaat = (int)$f['fazla_mesai_saat'];
        $fmOnaySaat = $f['fazla_mesai_onay_saat'] ?? null;
        $fmOnayli = $fmSaat > 0 && $fmOnaySaat !== null && (int)$fmOnaySaat > 0;
        $fmOnaySaat = $fmOnayli ? (int)$fmOnaySaat : 0;
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
