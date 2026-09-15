<?php
// =========================================================
// config/pdks_rapor.php — YÖNETİM RAPORLAMA MERKEZİ (Günlük İşçi, Faz 6) ÇEKİRDEĞİ
//
// ⚠ MİMARİ İLKE (kullanıcının açık talimatı): "Phase 1-5 are the
// authoritative operational and financial sources. Phase 6 must be
// READ-ONLY reporting." Bu dosya HİÇBİR TABLO/KOLON oluşturmaz — yalnız
// Faz 2-5'in ZATEN VAR OLAN, otoriter tablolarını (daily_work_sessions,
// daily_worker_card_events, foreman_daily_entitlements, foreman_payments,
// worker_types, foremen) toplu/agregat SORGULARLA okur. Yeni bir yoklama
// hesabı, yeni bir hakediş hesabı, ikinci bir bakiye defteri — HİÇBİRİ YOK.
//
// ⚠ BU DOSYA config/db.php / config/helpers.php TARAFINDAN YÜKLENMEZ —
//    diğer pdks_*.php modülleriyle AYNI gerekçe. Yalnız raporlar.php'den
//    require edilir. Migrasyon YOK (kendiliğinden veya kontrollü) — çünkü
//    YENİ hiçbir tablo/index YOK (bkz. dosya sonu, "MİGRASYON GEREKMİYOR").
//
// ⚠ SERT BAĞIMLILIK (TEK YÖNLÜ): config/pdks_gunluk.php + config/pdks_hakedis.php
//    + config/pdks_cari.php'yi hard-require eder — Faz 6 dört fazın ÜZERİNDE
//    oturur, hiçbiri Faz 6'ya bağımlı DEĞİLDİR (ters yön yok, tek yönlü
//    bağımlılık zinciri Faz 1→2→3→4→5→6 ile tutarlı).
//
// ⚠ PARA ARİTMETİĞİ (kullanıcının açık talimatı: "Reuse Phase 5 balance
// helpers. Do not reimplement accounting math."): TÜM para toplamları Faz
// 4/5'in AYNI TAM SAYI KURUŞ stratejisiyle (pdks_hakedis_tl_kurus() /
// pdks_hakedis_kurus_tl()) PHP TARAFINDA yapılır — SQL SUM(decimal_kolonu)
// BİLEREK KULLANILMAZ. Sebep: DECIMAL kolonlarının PHP tarafında STRING
// olarak gelmesi (MySQL PDO) production'da SUM() dahi olsa kesin olurdu,
// ama bu proje test altyapısı GERÇEK MySQL DDL'ini SQLite'a çevirip
// koşuyor ve SQLite DECIMAL(...) sütunlara NUMERIC affinity uygular —
// tam sayı olmayan değerleri REAL (IEEE754 float) olarak saklar; SQLite'ın
// kendi SUM()'ı bu durumda ARTIK KESİN DEĞİLDİR (Faz 4 test turunda
// belgelenen, bilinen bir farklılık). pdks_cari_bakiye()/pdks_cari_ekstre()
// AYNI nedenle SQL SUM() KULLANMAZ — bu dosya o kanıtlanmış deseni TEK
// TİP OLARAK sürdürür: HER YERDE ham (currency, tutar) satırları çekilir,
// kuruşa çevrilip PHP'de TOPLANIR. N+1 DEĞİLDİR — sorgu sayısı O(1)'dir
// (aralık başına tek SELECT), yalnız satır sayısı bir SUM()'a göre daha
// fazladır; bu değiş tokuş kesinlik için BİLİNÇLİ olarak kabul edilmiştir.
//
// ⚠ TARİH ANLAMBİLİMİ (kullanıcının açık talimatı, görev madde 17):
//   Yoklama  → work_date / work_date_snapshot (session'ın GERÇEK iş günü)
//   Hakediş  → work_date (dönemsel operasyonel raporlama İÇİN — calculated_at/
//              finalized_at DEĞİL; o alanlar yalnız Faz 5'in ekstre SIRALAMASI
//              için kullanılır, "hangi döneme ait" sorusu İÇİN DEĞİL)
//   Ödeme    → payment_date
//   Güncel bakiye → TÜM ZAMANLARIN KESİN hakedişi - TÜM ZAMANLARIN GEÇERLİ
//              ödemesi, ŞU AN itibarıyla — seçili dönemden BAĞIMSIZDIR.
//
// ⚠ TARİHSEL BÜTÜNLÜK (görev madde 16): tüm sorgular Faz 2-5'in KENDİ
// snapshot sütunlarını (worker_type_name_snapshot, foreman_name_snapshot,
// depo_snapshot, work_date_snapshot) okur — worker_types/foremen'in GÜNCEL
// adına asla geri dönmez. Çavuş/tip sonradan yeniden adlandırılsa bile
// GEÇMİŞ rapor satırları değişmez.
// =========================================================

declare(strict_types=1);

defined('PDKS_RAPOR_AKTIF') || define('PDKS_RAPOR_AKTIF', true);

// =========================================================
// YETKİ KAPISI — sayfa seviyesi: attendance.management_reports.
// Finansal BÖLÜMLER için AYRICA attendance.foreman_accounts gerekir (Faz 5'in
// KENDİ izni, kullanıcının açık talimatı — YENİ bir finansal izin İCAT
// EDİLMEDİ). Bölüm-seviyesi uygulama: raporlar.php finansal blokları
// pdks_rapor_can('financial') ile SARAR, ayrı bir "ik-görmez" sayfa
// KOPYASI AÇMAZ (görev talimatı: "Prefer section-level permission
// enforcement rather than duplicating pages.").
// =========================================================

function pdks_rapor_can(string $eylem): bool
{
    if (!function_exists('can')) return false;
    if (function_exists('is_admin') && is_admin()) return true;

    return match ($eylem) {
        'view'      => can('attendance.management_reports'),
        'financial' => can('attendance.foreman_accounts'),
        default     => false,
    };
}

function require_pdks_rapor(): void
{
    if (!PDKS_RAPOR_AKTIF) {
        if (function_exists('forbidden')) forbidden('Raporlama merkezi şu anda kapalıdır.');
        http_response_code(503);
        exit('Raporlama merkezi kapalı.');
    }
    if (function_exists('current_user') && current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    if (function_exists('enforce_active_depot')) enforce_active_depot();
    if (!pdks_rapor_can('view')) {
        forbidden('Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: attendance.management_reports)');
    }
}

/**
 * Bu sayfanın dayandığı Faz 2/4/5 tabloları hazır mı? YENİ bir "sayfa
 * kapısı" şeması YOK (Faz 6 kendi tablosunu tanımlamıyor) — ama Faz 4/5
 * migrasyonu henüz çalıştırılmamış bir ortamda finansal sorguların "tablo
 * yok" PDOException'ıyla ÇÖKMESİNİ önlemek için modül bazında AYRI AYRI
 * kontrol edilir; raporlar.php buna göre finansal bölümleri GÜVENLE
 * "henüz kullanılamıyor" notuyla ATLAR (sayfanın TAMAMI ENGELLENMEZ —
 * operasyonel bölümler Faz 4/5'ten bağımsızdır).
 */
function pdks_rapor_sema_durumu(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    return [
        'gunluk'   => function_exists('pdks_gunluk_sema_hazir') && pdks_gunluk_sema_hazir($pdo),
        'hakedis'  => function_exists('pdks_hakedis_sema_hazir') && pdks_hakedis_sema_hazir($pdo),
        'cari'     => function_exists('pdks_cari_sema_hazir') && pdks_cari_sema_hazir($pdo),
    ];
}

// =========================================================
// TARİH ARALIĞI PRESETLERİ (görev madde 1) — sunucu-yetkili date(), hafta
// PAZARTESİ'nden başlar (TR takvim konvansiyonu). "Bu Hafta"/"Bu Ay" TAM
// takvim aralığıdır (gelecek günler dahil olsa da sorgu doğal olarak boş
// döner — zararsız).
// =========================================================

function pdks_rapor_presetler(): array
{
    return [
        'bugun'       => 'Bugün',
        'dun'         => 'Dün',
        'bu_hafta'    => 'Bu Hafta',
        'gecen_hafta' => 'Geçen Hafta',
        'bu_ay'       => 'Bu Ay',
        'gecen_ay'    => 'Geçen Ay',
        'ozel'        => 'Özel Tarih Aralığı',
    ];
}

function pdks_rapor_tarih_araligi(string $preset, ?string $start = null, ?string $end = null): array
{
    $presetler = pdks_rapor_presetler();
    if (!isset($presetler[$preset])) $preset = 'bugun';
    $bugun = date('Y-m-d');

    switch ($preset) {
        case 'dun': {
            $d = date('Y-m-d', strtotime($bugun . ' -1 day'));
            $s = $e = $d;
            break;
        }
        case 'bu_hafta': {
            $dow = (int)date('N', strtotime($bugun));   // 1=Pzt..7=Paz
            $s = date('Y-m-d', strtotime($bugun . ' -' . ($dow - 1) . ' days'));
            $e = date('Y-m-d', strtotime($s . ' +6 days'));
            break;
        }
        case 'gecen_hafta': {
            $dow = (int)date('N', strtotime($bugun));
            $buHaftaPzt = date('Y-m-d', strtotime($bugun . ' -' . ($dow - 1) . ' days'));
            $s = date('Y-m-d', strtotime($buHaftaPzt . ' -7 days'));
            $e = date('Y-m-d', strtotime($s . ' +6 days'));
            break;
        }
        case 'bu_ay': {
            $s = date('Y-m-01', strtotime($bugun));
            $e = date('Y-m-t', strtotime($bugun));
            break;
        }
        case 'gecen_ay': {
            $ref = date('Y-m-d', strtotime(date('Y-m-01', strtotime($bugun)) . ' -1 day'));
            $s = date('Y-m-01', strtotime($ref));
            $e = date('Y-m-t', strtotime($ref));
            break;
        }
        case 'ozel': {
            $s = ($start !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && strtotime($start)) ? $start : $bugun;
            $e = ($end   !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)   && strtotime($end))   ? $end   : $bugun;
            if (strtotime($e) < strtotime($s)) { [$s, $e] = [$e, $s]; }
            break;
        }
        case 'bugun':
        default: {
            $s = $e = $bugun;
            break;
        }
    }

    return ['preset' => $preset, 'start' => $s, 'end' => $e, 'label' => $presetler[$preset]];
}

/**
 * KARŞILAŞTIRMA için "önceki dönem" (görev madde 10). Ay presetleri (bu_ay/
 * gecen_ay) GERÇEK takvim ayı öncesine kayar (kullanıcının "This Month vs
 * Previous Month" örneğiyle BİREBİR); diğer TÜM presetler (hafta/gün/özel)
 * AYNI UZUNLUKTA, doğrudan öncesindeki aralığa kayar — bu formül hafta
 * presetleri için de zaten doğru takvim haftasını verir (7 gün sabit).
 */
function pdks_rapor_onceki_donem(string $preset, string $start, string $end): array
{
    if ($preset === 'bu_ay' || $preset === 'gecen_ay') {
        $ref = date('Y-m-d', strtotime($start . ' -1 day'));
        return ['start' => date('Y-m-01', strtotime($ref)), 'end' => date('Y-m-t', strtotime($ref))];
    }
    $gunSayisi = (int)round((strtotime($end) - strtotime($start)) / 86400) + 1;
    $oncekiEnd = date('Y-m-d', strtotime($start . ' -1 day'));
    $oncekiStart = date('Y-m-d', strtotime($oncekiEnd . ' -' . ($gunSayisi - 1) . ' days'));
    return ['start' => $oncekiStart, 'end' => $oncekiEnd];
}

/**
 * Görüntüleme-amaçlı yüzde değişim (görev madde: "Percentage is
 * display-only... Financial calculations must still never use float.").
 * Girdiler HER ZAMAN tam sayıdır (kuruş VEYA baş sayısı) — yalnız BURADA,
 * son adımda, ekranda gösterilecek YÜZDEYİ üretmek için ondalık bölme
 * yapılır; bu sonuç hiçbir yerde finansal hesaba GERİ BESLENMEZ.
 * Önceki değer 0 ise bölme YAPILMAZ (kullanıcının açık talimatı: "Avoid
 * misleading percentage when previous value is zero") — 'yeni_mi' bayrağı
 * döner, sayfa "Yeni" ya da "-" yazar.
 */
function pdks_rapor_yuzde_degisim(int $eski, int $yeni): array
{
    $fark = $yeni - $eski;
    if ($eski === 0) {
        return ['fark' => $fark, 'yuzde' => null, 'yeni_mi' => $yeni !== 0];
    }
    return ['fark' => $fark, 'yuzde' => ($fark / $eski) * 100, 'yeni_mi' => false];
}

// =========================================================
// OPERASYONEL KPI (görev madde 2) — TEK GEÇİŞTE toplu sorgular, N+1 YOK.
// =========================================================

/**
 * "Toplam Çalışan" = BENZERSİZ (işçi kartı, iş günü) çifti — GİRİŞ+ÇIKIŞ
 * İKİ işçi SAYILMAZ (görev talimatı), aynı kart FARKLI günlerde AYRI AYRI
 * sayılır (görev talimatı, test #2). Çok-sütunlu COUNT(DISTINCT a,b) MySQL'e
 * ÖZGÜDÜR (SQLite kabul ETMEZ, test altyapısı KIRILIR) — bu yüzden HER
 * YERDE taşınabilir "iç sorguda DISTINCT çifti seç, dışarıda say" deseni
 * kullanılır.
 */
function pdks_rapor_operasyonel_kpi(string $start, string $end, ?string $depo, ?int $foremanId, ?int $workerTypeId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    $whereEv = ["event_type = 'GIRIS'", 'work_date_snapshot BETWEEN ? AND ?'];
    $parEv = [$start, $end];
    $joinEv = '';
    if ($depo !== null && $depo !== '') { $whereEv[] = 'depo_snapshot = ?'; $parEv[] = $depo; }
    if ($workerTypeId !== null) { $whereEv[] = 'worker_type_id_snapshot = ?'; $parEv[] = $workerTypeId; }
    if ($foremanId !== null) {
        $joinEv = ' JOIN daily_work_sessions s ON s.id = daily_worker_card_events.session_id';
        $whereEv[] = 's.foreman_id = ?'; $parEv[] = $foremanId;
    }

    // Toplam çalışan (benzersiz kart × gün).
    $stToplam = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT worker_card_id, work_date_snapshot
              FROM daily_worker_card_events" . $joinEv . "
             WHERE " . implode(' AND ', $whereEv) . "
         ) x"
    );
    $stToplam->execute($parEv);
    $toplamCalisan = (int)$stToplam->fetchColumn();

    // İşçi tipi kırılımı (benzersiz kart × gün × tip).
    $stTip = $pdo->prepare(
        "SELECT ad, COUNT(*) AS n FROM (
            SELECT DISTINCT worker_card_id, work_date_snapshot, worker_type_name_snapshot AS ad
              FROM daily_worker_card_events" . $joinEv . "
             WHERE " . implode(' AND ', $whereEv) . "
         ) x GROUP BY ad"
    );
    $stTip->execute($parEv);
    $tipDagilimi = [];
    foreach ($stTip->fetchAll() as $r) $tipDagilimi[(string)$r['ad']] = (int)$r['n'];

    // Oturum bazlı sayaçlar (session/mesai düzeyinde — çavuş/tamamlanan/açık/eksik).
    $whereS = ['work_date BETWEEN ? AND ?']; $parS = [$start, $end];
    if ($depo !== null && $depo !== '') { $whereS[] = 'depo = ?'; $parS[] = $depo; }
    if ($foremanId !== null) { $whereS[] = 'foreman_id = ?'; $parS[] = $foremanId; }
    $whereSql = implode(' AND ', $whereS);

    $stCavus = $pdo->prepare("SELECT COUNT(DISTINCT foreman_id) FROM daily_work_sessions WHERE $whereSql");
    $stCavus->execute($parS);
    $aktifCavus = (int)$stCavus->fetchColumn();

    $stDurum = $pdo->prepare("SELECT status, COUNT(*) AS n FROM daily_work_sessions WHERE $whereSql GROUP BY status");
    $stDurum->execute($parS);
    $durumSayim = ['open' => 0, 'closed' => 0];
    foreach ($stDurum->fetchAll() as $r) $durumSayim[(string)$r['status']] = (int)$r['n'];

    // KAPALI oturumlar arasında en az bir eksik-çıkış kartı olanlar (Faz 3
    // ilkesi: yalnız KAPANDIKTAN sonra "Eksik Çıkış" sayılır — bkz. dosya
    // başlığı ve pdks_gunluk_oturum_durumu()).
    $stEksikMesai = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.id) FROM daily_work_sessions s
          WHERE s.status = 'closed' AND s.work_date BETWEEN ? AND ?" .
            ($depo !== null && $depo !== '' ? ' AND s.depo = ?' : '') .
            ($foremanId !== null ? ' AND s.foreman_id = ?' : '') . "
            AND EXISTS (
                 SELECT 1 FROM daily_worker_card_events g
                  WHERE g.session_id = s.id AND g.event_type = 'GIRIS'
                    AND NOT EXISTS (
                         SELECT 1 FROM daily_worker_card_events c
                          WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
                    )
            )"
    );
    $parEksikMesai = [$start, $end];
    if ($depo !== null && $depo !== '') $parEksikMesai[] = $depo;
    if ($foremanId !== null) $parEksikMesai[] = $foremanId;
    $stEksikMesai->execute($parEksikMesai);
    $eksikCikisMesai = (int)$stEksikMesai->fetchColumn();

    return [
        'toplam_calisan'   => $toplamCalisan,
        'tip_dagilimi'     => $tipDagilimi,
        'aktif_cavus'      => $aktifCavus,
        'acik_mesai'       => $durumSayim['open'],
        'eksik_cikis_mesai'=> $eksikCikisMesai,
        'tamamlanan_mesai' => max(0, $durumSayim['closed'] - $eksikCikisMesai),
    ];
}

/**
 * İŞÇİ TİPİ DAĞILIMI (görev madde 7) — worker_types'tan DİNAMİK, yalnız
 * KADIN/ERKEK hardcode EDİLMEZ. sort_order'a göre sıralanır; seçili
 * dönemde HİÇ katılımı olmayan tip bile 0 ile GÖRÜNÜR (yönetim için
 * "bu tip hiç kullanılmadı" bilgisi de anlamlıdır, sessizce GİZLENMEZ).
 */
function pdks_rapor_isci_tipi_dagilimi(string $start, string $end, ?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $kpi = pdks_rapor_operasyonel_kpi($start, $end, $depo, $foremanId, null, $pdo);
    $tipDagilimi = $kpi['tip_dagilimi'];
    $toplam = $kpi['toplam_calisan'];

    $tipler = $pdo->query("SELECT id, code, name FROM worker_types ORDER BY sort_order ASC, name ASC")->fetchAll();
    $sonuc = [];
    foreach ($tipler as $t) {
        $ad = (string)$t['name'];
        $adet = $tipDagilimi[$ad] ?? 0;
        $sonuc[] = [
            'id' => (int)$t['id'], 'kod' => (string)$t['code'], 'ad' => $ad,
            'adet' => $adet, 'yuzde' => $toplam > 0 ? ($adet / $toplam) * 100 : 0.0,
        ];
        unset($tipDagilimi[$ad]);
    }
    // worker_types'ta artık bulunmayan (silinmiş/yeniden adlandırılmış) ama
    // geçmiş olay snapshot'larında hâlâ görünen bir ad varsa (tarihsel
    // bütünlük — görev madde 16) sessizce KAYBOLMAZ, listenin sonuna eklenir.
    foreach ($tipDagilimi as $ad => $adet) {
        $sonuc[] = ['id' => null, 'kod' => '', 'ad' => (string)$ad, 'adet' => $adet, 'yuzde' => $toplam > 0 ? ($adet / $toplam) * 100 : 0.0];
    }
    return $sonuc;
}

// =========================================================
// FİNANSAL KPI (görev madde 3/4) — PARA BİRİMİ ASLA TOPLANMAZ.
// =========================================================

/**
 * DÖNEM finansal özeti: work_date/payment_date aralığa göre filtrelenir.
 * "Güncel Cari Bakiye" burada YOKTUR — bkz. pdks_rapor_bakiye_toplu()
 * (kasıtlı olarak AYRI fonksiyon: dönem hareketi ile güncel borç FARKLI
 * kavramlardır, kullanıcının açık talimatı — karıştırılmasın diye kod
 * seviyesinde de AYRIŞTIRILDI).
 */
function pdks_rapor_finansal_kpi(string $start, string $end, ?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sonuc = [];   // currency => ['hakedis_kurus'=>,'odeme_kurus'=>]

    $whereH = ["status = 'final'", 'work_date BETWEEN ? AND ?']; $parH = [$start, $end];
    if ($depo !== null && $depo !== '') { $whereH[] = 'depo = ?'; $parH[] = $depo; }
    if ($foremanId !== null) { $whereH[] = 'foreman_id = ?'; $parH[] = $foremanId; }
    $stH = $pdo->prepare("SELECT currency, total_amount FROM foreman_daily_entitlements WHERE " . implode(' AND ', $whereH));
    $stH->execute($parH);
    foreach ($stH->fetchAll() as $r) {
        $cur = (string)$r['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$cur]['hakedis_kurus'] += pdks_hakedis_tl_kurus((string)$r['total_amount']);
    }

    // ⚠ foreman_payments'ta DEPO KOLONU YOK (Faz 5 şeması — bkz. dosya
    // başlığı, "Do not redesign existing modules"). Depo filtresi bu
    // yüzden yalnız hakediş tarafına uygulanır; ödeme tarafı depodan
    // bağımsız TÜM ödemeleri kapsar. Bu, Faz 5'in KENDİ, mevcut şema
    // sınırlamasıdır — Faz 6 onu GENİŞLETMEZ/DEĞİŞTİRMEZ.
    $whereP = ["status = 'valid'", 'payment_date BETWEEN ? AND ?']; $parP = [$start, $end];
    if ($foremanId !== null) { $whereP[] = 'foreman_id = ?'; $parP[] = $foremanId; }
    $stP = $pdo->prepare("SELECT currency, amount FROM foreman_payments WHERE " . implode(' AND ', $whereP));
    $stP->execute($parP);
    foreach ($stP->fetchAll() as $r) {
        $cur = (string)$r['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$cur]['odeme_kurus'] += pdks_hakedis_tl_kurus((string)$r['amount']);
    }

    foreach ($sonuc as $cur => &$s) {
        $s['net_kurus'] = $s['hakedis_kurus'] - $s['odeme_kurus'];
        $s['hakedis'] = pdks_hakedis_kurus_tl($s['hakedis_kurus']);
        $s['odeme']   = pdks_hakedis_kurus_tl($s['odeme_kurus']);
        $s['net']     = pdks_hakedis_kurus_tl($s['net_kurus']);
        $s['currency'] = $cur;
    }
    unset($s);
    return $sonuc;
}

/**
 * GÜNCEL (ŞU AN itibarıyla, TÜM ZAMANLARIN) cari bakiyesi — dönem
 * FİLTRESİNDEN BAĞIMSIZ. pdks_cari_bakiye()'NİN AYNI FORMÜLÜ (Σ KESİN
 * hakediş - Σ GEÇERLİ ödeme, para birimi başına) — yalnız TEK bir çavuş
 * yerine, seçili foreman/depo kapsamındaki TÜM hakediş/ödeme satırları
 * ÜZERİNDEN, TEK SORGUYLA (dashboard için N+1 pdks_cari_bakiye() çağrısı
 * YOK — görev talimatı madde 33).
 */
function pdks_rapor_bakiye_toplu(?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sonuc = [];

    $whereH = ["status = 'final'"]; $parH = [];
    if ($depo !== null && $depo !== '') { $whereH[] = 'depo = ?'; $parH[] = $depo; }
    if ($foremanId !== null) { $whereH[] = 'foreman_id = ?'; $parH[] = $foremanId; }
    $sqlH = "SELECT currency, total_amount FROM foreman_daily_entitlements" . ($whereH ? ' WHERE ' . implode(' AND ', $whereH) : '');
    $stH = $pdo->prepare($sqlH);
    $stH->execute($parH);
    foreach ($stH->fetchAll() as $r) {
        $cur = (string)$r['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$cur]['hakedis_kurus'] += pdks_hakedis_tl_kurus((string)$r['total_amount']);
    }

    $whereP = ["status = 'valid'"]; $parP = [];
    if ($foremanId !== null) { $whereP[] = 'foreman_id = ?'; $parP[] = $foremanId; }
    $sqlP = "SELECT currency, amount FROM foreman_payments" . ($whereP ? ' WHERE ' . implode(' AND ', $whereP) : '');
    $stP = $pdo->prepare($sqlP);
    $stP->execute($parP);
    foreach ($stP->fetchAll() as $r) {
        $cur = (string)$r['currency'];
        if (!isset($sonuc[$cur])) $sonuc[$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$cur]['odeme_kurus'] += pdks_hakedis_tl_kurus((string)$r['amount']);
    }

    foreach ($sonuc as $cur => &$s) {
        $s['bakiye_kurus'] = $s['hakedis_kurus'] - $s['odeme_kurus'];
        $s['hakedis'] = pdks_hakedis_kurus_tl($s['hakedis_kurus']);
        $s['odeme']   = pdks_hakedis_kurus_tl($s['odeme_kurus']);
        $s['bakiye']  = pdks_hakedis_kurus_tl($s['bakiye_kurus']);
        $s['durum'] = $s['bakiye_kurus'] > 0 ? 'borc' : ($s['bakiye_kurus'] < 0 ? 'avans' : 'kapali');
        $s['durum_etiket'] = match ($s['durum']) {
            'borc'  => 'Çavuşa Borcumuz',
            'avans' => 'Çavuş Avansı / Fazla Ödeme',
            default => 'Hesap Kapalı',
        };
        $s['currency'] = $cur;
    }
    unset($s);
    return $sonuc;
}

/**
 * TÜM (veya belirli) çavuşların TÜM-ZAMAN bakiyeleri — TEK GEÇİŞTE, foreman_id
 * başına AYRI pdks_cari_bakiye() ÇAĞRISI YOK (görev talimatı madde 33: "no
 * N+1 calls to balance helpers inside large loops"). Aynı Σhakediş-Σödeme
 * formülü, yalnız GROUP BY foreman_id ile.
 */
function pdks_rapor_cavus_bakiye_toplu(?array $foremanIds = null, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sonuc = [];   // foreman_id => currency => ['hakedis_kurus'=>,'odeme_kurus'=>]

    $ph = '';
    if ($foremanIds !== null) {
        if ($foremanIds === []) return [];
        $ph = ' AND foreman_id IN (' . implode(',', array_fill(0, count($foremanIds), '?')) . ')';
    }

    $stH = $pdo->prepare("SELECT foreman_id, currency, total_amount FROM foreman_daily_entitlements WHERE status = 'final'" . $ph);
    $stH->execute($foremanIds ?? []);
    foreach ($stH->fetchAll() as $r) {
        $fid = (int)$r['foreman_id']; $cur = (string)$r['currency'];
        if (!isset($sonuc[$fid][$cur])) $sonuc[$fid][$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$fid][$cur]['hakedis_kurus'] += pdks_hakedis_tl_kurus((string)$r['total_amount']);
    }

    $stP = $pdo->prepare("SELECT foreman_id, currency, amount FROM foreman_payments WHERE status = 'valid'" . $ph);
    $stP->execute($foremanIds ?? []);
    foreach ($stP->fetchAll() as $r) {
        $fid = (int)$r['foreman_id']; $cur = (string)$r['currency'];
        if (!isset($sonuc[$fid][$cur])) $sonuc[$fid][$cur] = ['hakedis_kurus' => 0, 'odeme_kurus' => 0];
        $sonuc[$fid][$cur]['odeme_kurus'] += pdks_hakedis_tl_kurus((string)$r['amount']);
    }

    foreach ($sonuc as $fid => &$curMap) {
        foreach ($curMap as $cur => &$s) {
            $s['bakiye_kurus'] = $s['hakedis_kurus'] - $s['odeme_kurus'];
            $s['hakedis'] = pdks_hakedis_kurus_tl($s['hakedis_kurus']);
            $s['odeme']   = pdks_hakedis_kurus_tl($s['odeme_kurus']);
            $s['bakiye']  = pdks_hakedis_kurus_tl($s['bakiye_kurus']);
            $s['durum'] = $s['bakiye_kurus'] > 0 ? 'borc' : ($s['bakiye_kurus'] < 0 ? 'avans' : 'kapali');
            $s['durum_etiket'] = match ($s['durum']) {
                'borc'  => 'Çavuşa Borcumuz',
                'avans' => 'Çavuş Avansı / Fazla Ödeme',
                default => 'Hesap Kapalı',
            };
            $s['currency'] = $cur;
        }
        unset($s);
    }
    unset($curMap);
    return $sonuc;
}

// =========================================================
// GÜNLÜK TREND (görev madde 5) — aralıktaki HER gün, boş günler de dahil
// (sessiz bir gün hata gibi GÖRÜNMEMELİ — görev madde 19).
// =========================================================

function pdks_rapor_gunluk_trend(string $start, string $end, ?string $depo, ?int $foremanId, ?int $workerTypeId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $gunler = [];
    $cursor = $start;
    while (strtotime($cursor) <= strtotime($end)) {
        $gunler[$cursor] = [
            'tarih' => $cursor, 'toplam_calisan' => 0, 'aktif_cavus' => 0, 'eksik_cikis' => 0,
            'hakedis' => [], 'odeme' => [], 'net' => [],
        ];
        $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
    }

    $whereEv = ["event_type = 'GIRIS'", 'work_date_snapshot BETWEEN ? AND ?']; $parEv = [$start, $end];
    $joinEv = '';
    if ($depo !== null && $depo !== '') { $whereEv[] = 'depo_snapshot = ?'; $parEv[] = $depo; }
    if ($workerTypeId !== null) { $whereEv[] = 'worker_type_id_snapshot = ?'; $parEv[] = $workerTypeId; }
    if ($foremanId !== null) {
        $joinEv = ' JOIN daily_work_sessions s ON s.id = daily_worker_card_events.session_id';
        $whereEv[] = 's.foreman_id = ?'; $parEv[] = $foremanId;
    }
    $stGun = $pdo->prepare(
        "SELECT work_date_snapshot AS gun, COUNT(*) AS n FROM (
            SELECT DISTINCT worker_card_id, work_date_snapshot
              FROM daily_worker_card_events" . $joinEv . "
             WHERE " . implode(' AND ', $whereEv) . "
         ) x GROUP BY work_date_snapshot"
    );
    $stGun->execute($parEv);
    foreach ($stGun->fetchAll() as $r) {
        if (isset($gunler[$r['gun']])) $gunler[$r['gun']]['toplam_calisan'] = (int)$r['n'];
    }

    $whereEksik = ["g.event_type = 'GIRIS'", 'g.work_date_snapshot BETWEEN ? AND ?']; $parEksik = [$start, $end];
    $joinEksik = '';
    if ($depo !== null && $depo !== '') { $whereEksik[] = 'g.depo_snapshot = ?'; $parEksik[] = $depo; }
    if ($workerTypeId !== null) { $whereEksik[] = 'g.worker_type_id_snapshot = ?'; $parEksik[] = $workerTypeId; }
    if ($foremanId !== null) {
        $joinEksik = ' JOIN daily_work_sessions s ON s.id = g.session_id';
        $whereEksik[] = 's.foreman_id = ?'; $parEksik[] = $foremanId;
    }
    $stEksik = $pdo->prepare(
        "SELECT work_date_snapshot AS gun, COUNT(*) AS n FROM (
            SELECT DISTINCT g.worker_card_id, g.work_date_snapshot
              FROM daily_worker_card_events g" . $joinEksik . "
             WHERE " . implode(' AND ', $whereEksik) . "
               AND NOT EXISTS (
                    SELECT 1 FROM daily_worker_card_events c
                     WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
               )
         ) x GROUP BY work_date_snapshot"
    );
    $stEksik->execute($parEksik);
    foreach ($stEksik->fetchAll() as $r) {
        if (isset($gunler[$r['gun']])) $gunler[$r['gun']]['eksik_cikis'] = (int)$r['n'];
    }

    $whereS = ['work_date BETWEEN ? AND ?']; $parS = [$start, $end];
    if ($depo !== null && $depo !== '') { $whereS[] = 'depo = ?'; $parS[] = $depo; }
    if ($foremanId !== null) { $whereS[] = 'foreman_id = ?'; $parS[] = $foremanId; }
    $stCavus = $pdo->prepare("SELECT work_date AS gun, COUNT(DISTINCT foreman_id) AS n FROM daily_work_sessions WHERE " . implode(' AND ', $whereS) . " GROUP BY work_date");
    $stCavus->execute($parS);
    foreach ($stCavus->fetchAll() as $r) {
        if (isset($gunler[$r['gun']])) $gunler[$r['gun']]['aktif_cavus'] = (int)$r['n'];
    }

    $whereH = ["status = 'final'", 'work_date BETWEEN ? AND ?']; $parH = [$start, $end];
    if ($depo !== null && $depo !== '') { $whereH[] = 'depo = ?'; $parH[] = $depo; }
    if ($foremanId !== null) { $whereH[] = 'foreman_id = ?'; $parH[] = $foremanId; }
    $stH = $pdo->prepare("SELECT work_date AS gun, currency, total_amount FROM foreman_daily_entitlements WHERE " . implode(' AND ', $whereH));
    $stH->execute($parH);
    $hakedisKurusGunCur = [];
    foreach ($stH->fetchAll() as $r) {
        $hakedisKurusGunCur[$r['gun']][$r['currency']] = ($hakedisKurusGunCur[$r['gun']][$r['currency']] ?? 0) + pdks_hakedis_tl_kurus((string)$r['total_amount']);
    }

    // ⚠ Depo filtresi ödeme tarafına uygulanmaz (bkz. pdks_rapor_finansal_kpi() notu — foreman_payments'ta depo kolonu yok).
    $whereP = ["status = 'valid'", 'payment_date BETWEEN ? AND ?']; $parP = [$start, $end];
    if ($foremanId !== null) { $whereP[] = 'foreman_id = ?'; $parP[] = $foremanId; }
    $stP = $pdo->prepare("SELECT payment_date AS gun, currency, amount FROM foreman_payments WHERE " . implode(' AND ', $whereP));
    $stP->execute($parP);
    $odemeKurusGunCur = [];
    foreach ($stP->fetchAll() as $r) {
        $odemeKurusGunCur[$r['gun']][$r['currency']] = ($odemeKurusGunCur[$r['gun']][$r['currency']] ?? 0) + pdks_hakedis_tl_kurus((string)$r['amount']);
    }

    foreach ($gunler as $gun => &$g) {
        $paraBirimleri = array_unique(array_merge(array_keys($hakedisKurusGunCur[$gun] ?? []), array_keys($odemeKurusGunCur[$gun] ?? [])));
        foreach ($paraBirimleri as $cur) {
            $hk = $hakedisKurusGunCur[$gun][$cur] ?? 0;
            $ok = $odemeKurusGunCur[$gun][$cur] ?? 0;
            $g['hakedis'][$cur] = pdks_hakedis_kurus_tl($hk);
            $g['odeme'][$cur]   = pdks_hakedis_kurus_tl($ok);
            $g['net'][$cur]     = pdks_hakedis_kurus_tl($hk - $ok);
        }
    }
    unset($g);

    return array_values($gunler);
}

// =========================================================
// ÇAVUŞ BAZLI ÖZET (görev madde 6) — N+1 YOK, TEK GEÇİŞTE toplu sorgular.
// =========================================================

function pdks_rapor_cavus_ozeti(string $start, string $end, ?string $depo, ?int $foremanId, ?int $workerTypeId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    $whereS = ['work_date BETWEEN ? AND ?']; $parS = [$start, $end];
    if ($depo !== null && $depo !== '') { $whereS[] = 'depo = ?'; $parS[] = $depo; }
    if ($foremanId !== null) { $whereS[] = 'foreman_id = ?'; $parS[] = $foremanId; }
    $stS = $pdo->prepare("SELECT id, foreman_id FROM daily_work_sessions WHERE " . implode(' AND ', $whereS));
    $stS->execute($parS);
    $sessionRows = $stS->fetchAll();
    if (!$sessionRows) return [];

    $sessionIds = array_map(fn($r) => (int)$r['id'], $sessionRows);
    $sidToFid = [];
    foreach ($sessionRows as $r) $sidToFid[(int)$r['id']] = (int)$r['foreman_id'];
    $ph = implode(',', array_fill(0, count($sessionIds), '?'));

    // Çalışılan gün (benzersiz work_date, session üzerinden çavuşa bağlı).
    $calisilanGun = [];   // foreman_id => set(work_date)
    $stCg = $pdo->prepare("SELECT foreman_id, work_date FROM daily_work_sessions WHERE id IN ($ph)");
    $stCg->execute($sessionIds);
    foreach ($stCg->fetchAll() as $r) $calisilanGun[(int)$r['foreman_id']][(string)$r['work_date']] = true;

    // İşçi/tip kırılımı + toplam (benzersiz kart × gün, session_id → foreman_id eşlemesiyle).
    $whereEv = ["event_type = 'GIRIS'", "session_id IN ($ph)"]; $parEv = $sessionIds;
    if ($workerTypeId !== null) { $whereEv[] = 'worker_type_id_snapshot = ?'; $parEv[] = $workerTypeId; }
    $stEv = $pdo->prepare(
        "SELECT session_id, worker_card_id, work_date_snapshot, worker_type_name_snapshot AS tip
           FROM daily_worker_card_events WHERE " . implode(' AND ', $whereEv)
    );
    $stEv->execute($parEv);
    $gorulenKartGun = [];   // foreman_id => "cardid|gun" => tip  (DISTINCT güvencesi)
    foreach ($stEv->fetchAll() as $r) {
        $fid = $sidToFid[(int)$r['session_id']] ?? null;
        if ($fid === null) continue;
        $anahtar = $r['worker_card_id'] . '|' . $r['work_date_snapshot'];
        $gorulenKartGun[$fid][$anahtar] = (string)$r['tip'];
    }
    $toplamIsci = []; $tipKirilimi = [];
    foreach ($gorulenKartGun as $fid => $liste) {
        $toplamIsci[$fid] = count($liste);
        foreach ($liste as $tip) $tipKirilimi[$fid][$tip] = ($tipKirilimi[$fid][$tip] ?? 0) + 1;
    }

    // Eksik çıkış (benzersiz kart × gün, GİRİŞ var ÇIKIŞ yok).
    $stEksik = $pdo->prepare(
        "SELECT g.session_id, COUNT(*) AS n FROM (
            SELECT DISTINCT g.session_id, g.worker_card_id, g.work_date_snapshot
              FROM daily_worker_card_events g
             WHERE g.event_type = 'GIRIS' AND g.session_id IN ($ph)
               AND NOT EXISTS (
                    SELECT 1 FROM daily_worker_card_events c
                     WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
               )
         ) g GROUP BY g.session_id"
    );
    $stEksik->execute($sessionIds);
    $eksikCikis = [];   // foreman_id => n
    foreach ($stEksik->fetchAll() as $r) {
        $fid = $sidToFid[(int)$r['session_id']] ?? null;
        if ($fid === null) continue;
        $eksikCikis[$fid] = ($eksikCikis[$fid] ?? 0) + (int)$r['n'];
    }

    $foremanIds = array_values(array_unique(array_map(fn($r) => (int)$r['foreman_id'], $sessionRows)));

    // Dönem hakediş/ödeme — foreman bazlı, para birimi ayrı.
    $donemHakedis = []; $donemOdeme = [];
    $fph = implode(',', array_fill(0, count($foremanIds), '?'));
    $whereH = ["status = 'final'", 'work_date BETWEEN ? AND ?', "foreman_id IN ($fph)"];
    $parH = array_merge([$start, $end], $foremanIds);
    if ($depo !== null && $depo !== '') { $whereH[] = 'depo = ?'; $parH[] = $depo; }
    $stH = $pdo->prepare("SELECT foreman_id, currency, total_amount FROM foreman_daily_entitlements WHERE " . implode(' AND ', $whereH));
    $stH->execute($parH);
    foreach ($stH->fetchAll() as $r) {
        $fid = (int)$r['foreman_id']; $cur = (string)$r['currency'];
        $donemHakedis[$fid][$cur] = ($donemHakedis[$fid][$cur] ?? 0) + pdks_hakedis_tl_kurus((string)$r['total_amount']);
    }
    $stP = $pdo->prepare("SELECT foreman_id, currency, amount FROM foreman_payments WHERE status = 'valid' AND payment_date BETWEEN ? AND ? AND foreman_id IN ($fph)");
    $stP->execute(array_merge([$start, $end], $foremanIds));
    foreach ($stP->fetchAll() as $r) {
        $fid = (int)$r['foreman_id']; $cur = (string)$r['currency'];
        $donemOdeme[$fid][$cur] = ($donemOdeme[$fid][$cur] ?? 0) + pdks_hakedis_tl_kurus((string)$r['amount']);
    }

    $guncelBakiye = pdks_rapor_cavus_bakiye_toplu($foremanIds, $pdo);

    $stF = $pdo->prepare("SELECT id, code, name, is_active FROM foremen WHERE id IN ($fph) ORDER BY name ASC");
    $stF->execute($foremanIds);
    $foremenler = $stF->fetchAll();

    $sonuc = [];
    foreach ($foremenler as $f) {
        $fid = (int)$f['id'];
        $hkArr = []; foreach (($donemHakedis[$fid] ?? []) as $cur => $k) $hkArr[$cur] = pdks_hakedis_kurus_tl($k);
        $odArr = []; foreach (($donemOdeme[$fid] ?? []) as $cur => $k) $odArr[$cur] = pdks_hakedis_kurus_tl($k);
        $sonuc[] = [
            'foreman' => $f,
            'calisilan_gun' => count($calisilanGun[$fid] ?? []),
            'toplam_isci' => $toplamIsci[$fid] ?? 0,
            'tip_dagilimi' => $tipKirilimi[$fid] ?? [],
            'eksik_cikis' => $eksikCikis[$fid] ?? 0,
            'donem_hakedis' => $hkArr,
            'donem_odeme' => $odArr,
            'guncel_bakiye' => $guncelBakiye[$fid] ?? [],
        ];
    }
    usort($sonuc, fn($a, $b) => strcmp((string)$a['foreman']['name'], (string)$b['foreman']['name']));
    return $sonuc;
}

// =========================================================
// ÇAVUŞ CARİ SIRALAMASI (görev madde 9) — GÜNCEL (tüm-zaman) bakiye,
// para birimi başına AYRI liste, pozitif borç AZALAN sırada.
// =========================================================

function pdks_rapor_cavus_bakiye_siralamasi(?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();

    // Hangi çavuşların en az bir hakediş/ödeme hareketi var? (depo filtresi
    // yalnız hakediş tarafına — foreman_payments'ta depo kolonu YOK, bkz.
    // pdks_rapor_finansal_kpi() notu.)
    $whereH = ["e.status = 'final'"]; $parH = [];
    if ($depo !== null && $depo !== '') { $whereH[] = 'e.depo = ?'; $parH[] = $depo; }
    if ($foremanId !== null) { $whereH[] = 'e.foreman_id = ?'; $parH[] = $foremanId; }
    $sqlIdsH = "SELECT DISTINCT e.foreman_id FROM foreman_daily_entitlements e WHERE " . implode(' AND ', $whereH);

    $wp = "p.status = 'valid'" . ($foremanId !== null ? ' AND p.foreman_id = ?' : '');
    $parP = $foremanId !== null ? [$foremanId] : [];
    $sqlIdsP = "SELECT DISTINCT p.foreman_id FROM foreman_payments p WHERE $wp";

    $stIds = $pdo->prepare("$sqlIdsH UNION $sqlIdsP");
    $stIds->execute(array_merge($parH, $parP));
    $foremanIds = array_map('intval', array_column($stIds->fetchAll(), 'foreman_id'));
    if (!$foremanIds) return [];

    $bakiyeler = pdks_rapor_cavus_bakiye_toplu($foremanIds, $pdo);
    // ⚠ depo filtresi TÜM-ZAMAN bakiyesine de uygulanmalı (yalnız listeleme
    // kapsamına DEĞİL) — pdks_rapor_cavus_bakiye_toplu() depo bilmez
    // (tasarım gereği, foreman bazlı toplu), bu yüzden depo filtreliyken
    // bakiye AYRICA pdks_rapor_bakiye_toplu(depo, foremanId) ile TEK TEK
    // değil, foreman başına YİNE toplu biçimde yeniden hesaplanır.
    if ($depo !== null && $depo !== '') {
        $bakiyeler = [];
        foreach ($foremanIds as $fid) {
            $bakiyeler[$fid] = pdks_rapor_bakiye_toplu($depo, $fid, $pdo);
        }
    }

    $ph = implode(',', array_fill(0, count($foremanIds), '?'));
    $stF = $pdo->prepare("SELECT id, code, name, is_active FROM foremen WHERE id IN ($ph)");
    $stF->execute($foremanIds);
    $foremenMap = [];
    foreach ($stF->fetchAll() as $f) $foremenMap[(int)$f['id']] = $f;

    $paraBazinda = [];   // currency => [ ['foreman'=>,'hakedis'=>,'odeme'=>,'bakiye'=>,'bakiye_kurus'=>,'durum'=>,'durum_etiket'=>], ... ]
    foreach ($foremanIds as $fid) {
        if (!isset($foremenMap[$fid])) continue;
        foreach (($bakiyeler[$fid] ?? []) as $cur => $b) {
            $paraBazinda[$cur][] = array_merge($b, ['foreman' => $foremenMap[$fid]]);
        }
    }
    foreach ($paraBazinda as $cur => &$liste) {
        usort($liste, fn($a, $b) => $b['bakiye_kurus'] <=> $a['bakiye_kurus']);
    }
    unset($liste);
    return $paraBazinda;
}

// =========================================================
// İSTİSNALAR (görev madde 8) — aralık bazlı, GÜNDE BİR N+1 sorgu YOK
// (pdks_gunluk_eksik_cikislar() TEK GÜN alır; burada TÜM aralık TEK
// sorguda — AYNI iş kuralı/JOIN deseni, yalnız work_date yerine BETWEEN).
// =========================================================

function pdks_rapor_eksik_cikislar_araligi(string $start, string $end, ?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    return pdks_rapor_istisna_satirlari($start, $end, $depo, $foremanId, 'closed', $pdo);
}

function pdks_rapor_acik_mesailer_araligi(string $start, string $end, ?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    return pdks_rapor_istisna_satirlari($start, $end, $depo, $foremanId, 'open', $pdo);
}

/**
 * Ortak gövde — 'closed' → Faz 3'ün "Eksik Çıkış" istisnası (yalnız
 * KAPANMIŞ oturumlarda anlamlıdır), 'open' → hâlâ İÇERİDE olan (GİRİŞ var,
 * ÇIKIŞ yok) kartlar, yalnız AÇIK oturumlarda (bu normaldir, istisna değil
 * — yönetim için "kim hâlâ sahada" listesidir). UYDURMA ÇIKIŞ YOK, oturum
 * OTOMATİK KAPATILMAZ (görev talimatı).
 */
function pdks_rapor_istisna_satirlari(string $start, string $end, ?string $depo, ?int $foremanId, string $oturumDurumu, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $where = ['g.event_type = \'GIRIS\'', 'g.work_date_snapshot BETWEEN ? AND ?', 's.status = ?'];
    $params = [$start, $end, $oturumDurumu];
    if ($depo !== null && $depo !== '') { $where[] = 'g.depo_snapshot = ?'; $params[] = $depo; }
    if ($foremanId !== null) { $where[] = 's.foreman_id = ?'; $params[] = $foremanId; }

    $st = $pdo->prepare(
        "SELECT g.session_id, g.worker_card_id, w.card_no, g.worker_type_name_snapshot AS tip,
                g.server_event_time AS giris_saat, g.work_date_snapshot AS tarih, g.depo_snapshot AS depo,
                s.status AS oturum_durumu, s.notes AS kapanis_notu, s.foreman_name_snapshot AS cavus_adi
           FROM daily_worker_card_events g
           JOIN daily_work_sessions s ON s.id = g.session_id
           JOIN worker_cards w ON w.id = g.worker_card_id
          WHERE " . implode(' AND ', $where) . "
            AND NOT EXISTS (
                 SELECT 1 FROM daily_worker_card_events c
                  WHERE c.session_id = g.session_id AND c.worker_card_id = g.worker_card_id AND c.event_type = 'CIKIS'
            )
          ORDER BY g.work_date_snapshot DESC, g.server_event_time ASC"
    );
    $st->execute($params);
    $satirlar = $st->fetchAll();
    foreach ($satirlar as &$r) {
        $r['oturum_kapali_mesaji'] = ($r['oturum_durumu'] === 'closed') ? 'Mesai eksik çıkışla kapatıldı.' : null;
    }
    unset($r);
    return $satirlar;
}

// =========================================================
// DÖNEM KARŞILAŞTIRMASI (görev madde 10)
// =========================================================

function pdks_rapor_karsilastirma(string $preset, string $start, string $end, ?string $depo, ?int $foremanId, ?int $workerTypeId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $onceki = pdks_rapor_onceki_donem($preset, $start, $end);

    $simdiKpi = pdks_rapor_operasyonel_kpi($start, $end, $depo, $foremanId, $workerTypeId, $pdo);
    $oncekiKpi = pdks_rapor_operasyonel_kpi($onceki['start'], $onceki['end'], $depo, $foremanId, $workerTypeId, $pdo);
    $simdiFin = pdks_rapor_finansal_kpi($start, $end, $depo, $foremanId, $pdo);
    $oncekiFin = pdks_rapor_finansal_kpi($onceki['start'], $onceki['end'], $depo, $foremanId, $pdo);

    $calisanDegisim = pdks_rapor_yuzde_degisim($oncekiKpi['toplam_calisan'], $simdiKpi['toplam_calisan']);

    $paraBirimleri = array_values(array_unique(array_merge(array_keys($simdiFin), array_keys($oncekiFin))));
    $hakedisDegisim = []; $odemeDegisim = [];
    foreach ($paraBirimleri as $cur) {
        $hakedisDegisim[$cur] = pdks_rapor_yuzde_degisim($oncekiFin[$cur]['hakedis_kurus'] ?? 0, $simdiFin[$cur]['hakedis_kurus'] ?? 0);
        $odemeDegisim[$cur]   = pdks_rapor_yuzde_degisim($oncekiFin[$cur]['odeme_kurus'] ?? 0, $simdiFin[$cur]['odeme_kurus'] ?? 0);
    }

    return [
        'onceki_araligi' => $onceki,
        'simdi_calisan' => $simdiKpi['toplam_calisan'], 'onceki_calisan' => $oncekiKpi['toplam_calisan'],
        'calisan_degisim' => $calisanDegisim,
        'simdi_finansal' => $simdiFin, 'onceki_finansal' => $oncekiFin,
        'hakedis_degisim' => $hakedisDegisim, 'odeme_degisim' => $odemeDegisim,
    ];
}

/**
 * DECIMAL-string tutarı TR biçimine çevirir — raporlar.php'nin KENDİ bare
 * fonksiyonu OLARAK TANIMLANMADI (pdks_gunluk_kullanici_adi()'nin AYNI
 * gerekçesi: sayfa test harness'inde birden çok GET/POST kombinasyonuyla
 * art arda include edilebiliyor, page-local bare fonksiyon "Cannot
 * redeclare" fatal'ına düşer — paylaşılan modül fonksiyonu TEK sefer
 * require_once ile yüklenir, bu sorunu YAŞAMAZ).
 */
function pdks_rapor_para_formatla($tl): string
{
    return number_format((float)$tl, 2, ',', '.');
}

// =========================================================
// MİGRASYON GEREKMİYOR (görev madde 22 talimatının açık kaçış yolu):
// Bu dosya HİÇBİR CREATE TABLE / ALTER TABLE / CREATE INDEX İÇERMEZ.
// Mevcut indeksler (idx_dwce_workdate_depo_type, idx_dwce_session,
// idx_dws_date, idx_fde_date, idx_fde_status, idx_fp_date, idx_fp_status)
// bu dosyanın TÜM sorgularını zaten karşılıyor — EXPLAIN ile doğrulanan
// (bkz. Faz 6 raporu) hiçbir sorgu YENİ, spekülatif bir indeks GEREKTİRMEDİ.
// =========================================================
