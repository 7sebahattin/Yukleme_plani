<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_cift_yovmiye_smoke.php — v299 Fiyat dönemi saatleri + Çift Yevmiye.
//
// Bellek içi SQLite + GERÇEK fonksiyonlar (pdks_cavus_ucret_smoke.php harness'i);
// canlı DB'ye dokunmaz. Kapsam: geriye uyum (kolon yok / NULL → bugünkü tutarlar
// birebir), 8 / 9 / 10,5 / 11 / 12 / 13 saat (saatlik + sabit FM), FM bekliyor /
// reddedildi / kısmi onay, onaylı FM kırpma, Tam saati fiyat döneminden vs mesai
// snapshot'ından, Yarım saati yalnız bilgi, doğrulama hataları, tek sınıflandırıcı
// statik denetimi, Yöntem B kişi-gün, hakediş 'cift' satırı, Mesai Tanımı metni.
//
//   php scripts/pdks_cift_yovmiye_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function okcy(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function can(string $p): bool { return true; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {
    global $AUDIT; $AUDIT[] = ['action' => $a, 'module' => $m, 'record_id' => $rid, 'new' => $n];
}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';

// ── Şema (pdks_cavus_ucret_smoke.php ile aynı temel) ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$karisik = (int)$db->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");

$day = date('Y-m-d', strtotime('-3 days'));
for ($f = 1; $f <= 7; $f++) $db->exec("INSERT INTO foremen (id,code,name) VALUES ({$f},'C{$f}','Çavuş {$f}')");

/** Tek dönemli yeni mesai: 06:00'dan $dk dakika. Dönem id + mesai id döner. */
function mesaiKur(int $foremanId, int $dk, int $snap = 540, ?int $tip = null): array {
    global $db, $day, $kadin;
    static $n = 0; $n++;
    $tip = $tip ?? $kadin;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,'Depo A','closed')")
       ->execute([$foremanId, 'Çavuş ' . $foremanId, 'C' . $foremanId, $snap, $day]);
    $sid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['K' . $n]);
    $kart = (int)$db->lastInsertId();
    $g = strtotime("{$day} 06:00:00");
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$sid, $kart, $tip, $tip === $kadin ? 'Kadın' : 'Karışık', date('Y-m-d H:i:s', $g), date('Y-m-d H:i:s', $g + $dk * 60)]);
    return [(int)$db->lastInsertId(), $sid];
}
function sinif(int $pid): array { global $db; return pdks_faz8b_donem_getir($pid, $db)['faz8b']; }
/** Değerlendirme (gerekirse) + hakediş; [faz8b, hakedis] döner. $fm: null = adayın tamamı, 'yok' = gönderme. */
function hesapla(int $pid, int $sid, $fm = null, ?string $sinifKarar = null): array {
    global $db;
    $f = sinif($pid);
    if ($f['sinif_onayi_gerekli'] || (int)$f['fazla_mesai_saat'] > 0) {
        if ($fm !== 'yok') {
            $saat = (int)$f['fazla_mesai_saat'] > 0 ? ($fm === null ? (int)$f['fazla_mesai_saat'] : (int)$fm) : null;
            $r = pdks_faz8b_degerlendirme_kaydet($pid, $f['sinif_onayi_gerekli'] ? ($sinifKarar ?? 'tam') : null, $saat, 1, $db);
            if (!$r['ok']) return [$f, ['ok' => false, 'hata' => 'değerlendirme: ' . $r['hata']]];
        }
    }
    return [sinif($pid), pdks_faz8b_hakedis_hesapla($sid, 1, $db)];
}
function tutar(array $h): string { return ($h['ok'] ?? false) ? (string)$h['total_amount'] : 'HATA:' . ($h['hata'] ?? '?'); }
function satir(array $h): array { return ($h['lines'] ?? [])[0] ?? []; }

$SENARYO_DK = ['8' => 480, '9' => 540, '10.5' => 630, '11' => 660, '12' => 720, '13' => 780];
// Bugünkü (v298) kural: Tam 1000 + ceil((dk-540-15)/60) × 150; 8 s → muhasebe Tam.
$ESKI = ['8' => '1000.00', '9' => '1000.00', '10.5' => '1300.00', '11' => '1300.00', '12' => '1450.00', '13' => '1600.00'];

// =========================================================
echo "=== A. KOLONLAR YOKKEN (bugünkü davranış) ===\n";
okcy('A1) saat kolonları henüz yok', pdks_faz8b_saat_kolonlari_hazir($db) === false);
okcy('A2) Faz 8B şeması saat kolonları OLMADAN hazır', pdks_faz8b_sema_hazir($db) === true);
$r = pdks_faz8b_oran_ekle(1, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db);
okcy('A3) saatsiz fiyat dönemi eklenir (eski imza)', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = pdks_faz8b_oran_ekle(2, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db, ['full_day' => '9']);
okcy('A4) kolon yokken saat girilirse "migrate.php" hatası', !$r['ok'] && str_contains((string)$r['hata'], 'migrate.php'), json_encode($r, JSON_UNESCAPED_UNICODE));
$oncekiTutar = [];
foreach ($SENARYO_DK as $ad => $dk) {
    [$pid, $sid] = mesaiKur(1, $dk);
    [$f, $h] = hesapla($pid, $sid);
    $oncekiTutar[$ad] = tutar($h);
    okcy("A5) kolon yok · {$ad} s → {$ESKI[$ad]}", tutar($h) === $ESKI[$ad], tutar($h));
}

// =========================================================
echo "\n=== B. MİGRASYON (yalnız ADD COLUMN, idempotent) ===\n";
$m1 = pdks_faz8b_saat_kolonlari_migrate($db);
okcy('B1) 5 kolon eklendi', count(array_filter($m1, fn($x) => $x['durum'] === 'eklendi')) === 5, json_encode($m1, JSON_UNESCAPED_UNICODE));
$m2 = pdks_faz8b_saat_kolonlari_migrate($db);
okcy('B2) ikinci çalıştırma "var" (idempotent)', count(array_filter($m2, fn($x) => $x['durum'] === 'var')) === 5);
okcy('B3) saat kolonları hazır', pdks_faz8b_saat_kolonlari_hazir($db) === true);

// =========================================================
echo "\n=== C. GERİYE UYUM — kolonlar NULL ===\n";
foreach ($SENARYO_DK as $ad => $dk) {
    [$pid, $sid] = mesaiKur(1, $dk);
    [$f, $h] = hesapla($pid, $sid);
    okcy("C1) NULL saat · {$ad} s tutar kolon-yok ile birebir ({$oncekiTutar[$ad]})", tutar($h) === $oncekiTutar[$ad], tutar($h));
    $eski = pdks_faz8b_sure_karari($f['toplam_dk'] !== null ? "{$day} 06:00:00" : null, date('Y-m-d H:i:s', strtotime("{$day} 06:00:00") + $dk * 60), 540);
    okcy("C2) NULL saat · {$ad} s otomatik sınıf/FM adayı eski karar ile aynı",
        $eski['otomatik_sinif'] === $f['otomatik_sinif'] && $eski['fazla_mesai_saat'] === $f['fazla_mesai_saat'] && $f['cift'] === false && $f['tam_kaynak'] === 'mesai');
}

// =========================================================
echo "\n=== D. ÇİFT YEVMİYE — saatlik FM (Tam 9 s, Çift 12 s / 2000) ===\n";
$r = pdks_faz8b_oran_ekle(2, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db,
    ['full_day' => '9', 'half_day' => '5', 'double_day' => '12', 'double_day_rate' => '2000']);
okcy('D0) saatli + çiftli fiyat dönemi eklenir', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$oran2 = $db->query("SELECT * FROM foreman_worker_rates WHERE foreman_id = 2")->fetch();
okcy('D0b) kolonlara dakika/ücret yazıldı', (int)$oran2['full_day_minutes'] === 540 && (int)$oran2['half_day_max_minutes'] === 300
    && $oran2['overtime_start_minutes'] === null && (int)$oran2['double_day_minutes'] === 720 && (float)$oran2['double_day_rate'] === 2000.0, json_encode($oran2));
$BEK_D = ['8' => '1000.00', '9' => '1000.00', '10.5' => '1300.00', '11' => '1300.00', '12' => '2000.00', '13' => '2150.00'];
foreach ($SENARYO_DK as $ad => $dk) {
    [$pid, $sid] = mesaiKur(2, $dk);
    [$f, $h] = hesapla($pid, $sid);
    okcy("D1) saatlik · {$ad} s → {$BEK_D[$ad]}", tutar($h) === $BEK_D[$ad], tutar($h) . ' ' . json_encode(satir($h), JSON_UNESCAPED_UNICODE));
    if ($ad === '13') {
        $s = satir($h);
        okcy("D2) 13 s satırı: sınıf 'cift', birim 2000, FM 1 saat × 150, worker_count 1",
            ($s['attendance_class_snapshot'] ?? '') === 'cift' && $s['unit_rate'] === '2000.00' && (int)$s['overtime_hours'] === 1
            && $s['overtime_total'] === '150.00' && (int)$s['worker_count'] === 1 && (int)$s['worker_type_id'] === $kadin, json_encode($s, JSON_UNESCAPED_UNICODE));
        okcy('D3) 13 s sınıflandırıcı: cift=true, etkin_sinif cift, ödenecek FM 1', $f['cift'] === true && $f['etkin_sinif'] === 'cift' && $f['odenecek_fm_saat'] === 1);
        okcy('D4) Mesai Tanımı metni "Çift · FM 1 s"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Çift · FM 1 s', pdks_faz8b_mesai_tanimi_etiketi($f, false, false));
    }
    if ($ad === '12') okcy('D5) 12 s: FM 0 (tam ile çift arası ayrıca ödenmez)', (int)(satir($h)['overtime_hours'] ?? -1) === 0);
    if ($ad === '11') {
        okcy('D6) 11 s çift adayı DEĞİL (eşik toleranssız ≥ 12 s)', $f['cift_aday'] === false && $f['etkin_sinif'] === 'tam');
        okcy('D7) Mesai Tanımı "Tam · FM 2 s"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Tam · FM 2 s');
    }
}
// 11 s 50 dk: FM aday 3 (onaylı süre 12 s) ama toplam < 12 s → Tam + 3 FM.
[$pid, $sid] = mesaiKur(2, 710);
[$f, $h] = hesapla($pid, $sid);
okcy('D8) 11 s 50 dk → Tam + 3 FM = 1450 (çalışılan süre çift eşiğine ulaşmadı)', tutar($h) === '1450.00' && $f['cift'] === false, tutar($h));

// =========================================================
echo "\n=== E. FM onay durumları (13 s) ===\n";
[$pid, $sid] = mesaiKur(2, 780);
$f = sinif($pid);
okcy('E1) FM onayı BEKLİYOR → finans hazır değil', $f['finans_hazir'] === false && $f['fazla_mesai_durum'] === 'bekliyor' && $f['cift_aday'] === true && $f['cift'] === false);
okcy('E1b) Mesai Tanımı "Tam · FM 4 s (bekliyor)"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Tam · FM 4 s (bekliyor)', pdks_faz8b_mesai_tanimi_etiketi($f, false, false));
$h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
okcy('E2) FM bekliyorken hakediş hesaplanmaz', ($h['ok'] ?? true) === false && ($h['kod'] ?? '') === 'faz8b_degerlendirme_gerekli', json_encode($h, JSON_UNESCAPED_UNICODE));
[$f, $h] = hesapla($pid, $sid, 0);
okcy('E3) FM REDDEDİLDİ → Tam 1000', tutar($h) === '1000.00' && $f['etkin_sinif'] === 'tam', tutar($h));
okcy('E3b) Mesai Tanımı "Tam · FM reddedildi"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Tam · FM reddedildi');
[$f, $h] = hesapla($pid, $sid, 3);
okcy('E4) kısmi onay 3 s (onaylı süre = 12 s) → Çift 2000, FM 0', tutar($h) === '2000.00' && $f['cift'] === true && $f['odenecek_fm_saat'] === 0, tutar($h));
[$f, $h] = hesapla($pid, $sid, 2);
okcy('E5) kısmi onay 2 s (onaylı süre 11 s) → Tam + 2 FM = 1300', tutar($h) === '1300.00' && $f['cift'] === false, tutar($h));

// =========================================================
echo "\n=== F. Sabit (fixed) FM — çift gününe FM eklenmez ===\n";
$r = pdks_faz8b_oran_ekle(3, $kadin, '1000', '600', 'fixed', '300', '2020-01-01', 'TRY', 1, $db,
    ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']);
okcy('F0) sabit FM + çift fiyat dönemi', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$BEK_F = ['8' => '1000.00', '9' => '1000.00', '10.5' => '1300.00', '11' => '1300.00', '12' => '2000.00', '13' => '2000.00'];
foreach ($SENARYO_DK as $ad => $dk) {
    [$pid, $sid] = mesaiKur(3, $dk);
    [$f, $h] = hesapla($pid, $sid);
    okcy("F1) sabit · {$ad} s → {$BEK_F[$ad]}", tutar($h) === $BEK_F[$ad], tutar($h));
    if ($ad === '13') {
        $s = satir($h);
        okcy('F2) sabit FM çift gününde: satır cift, FM 0 / mod yok', ($s['attendance_class_snapshot'] ?? '') === 'cift' && (int)$s['overtime_hours'] === 0 && $s['overtime_total'] === '0.00' && $s['overtime_mode_snapshot'] === null, json_encode($s, JSON_UNESCAPED_UNICODE));
    }
}

// =========================================================
echo "\n=== G. Onaylı FM kırpma (eşik değişince) ===\n";
$r = pdks_faz8b_oran_ekle(4, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db,
    ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']);
[$pid, $sid] = mesaiKur(4, 780);
[$f, $h] = hesapla($pid, $sid);   // aday 4 onaylandı → 2150
okcy('G0) önce 13 s → 2150 (onay 4 s)', tutar($h) === '2150.00', tutar($h));
$db->exec("UPDATE foreman_worker_rates SET overtime_start_minutes = 600 WHERE foreman_id = 4");
$f = sinif($pid);
okcy('G1) FM başı 10 s olunca aday 3, onaylı 4 → 3\'e KIRPILDI', $f['fazla_mesai_saat'] === 3 && $f['fazla_mesai_onay_saat'] === 3 && $f['fazla_mesai_onay_kirpildi'] === true, json_encode([$f['fazla_mesai_saat'], $f['fazla_mesai_onay_saat']]));
okcy('G2) kırpma yeni değerlendirme istemez (finans hazır)', $f['finans_hazir'] === true && $f['fazla_mesai_durum'] === 'onayli');
$h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
okcy('G3) kırpılmış onayla 13 s → Çift 2000 + 1 FM = 2150', tutar($h) === '2150.00', tutar($h));
$r = pdks_faz8b_degerlendirme_kaydet($pid, null, 4, 1, $db);
okcy('G4) kırpılmış adaydan fazla (4) yeni onay REDDEDİLİR', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== H. Tam saati: fiyat dönemi vs mesai snapshot'ı; Yarım yalnız bilgi ===\n";
$r = pdks_faz8b_oran_ekle(5, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db, ['full_day' => '8']);
okcy('H0) Tam 8 s fiyat dönemi', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
[$pid, $sid] = mesaiKur(5, 480, 540);
$f = sinif($pid);
okcy('H1) 8 s, snapshot 9 s ama fiyat Tam 8 s → OTOMATİK Tam (kaynak fiyat)', $f['otomatik_sinif'] === 'tam' && $f['tam_kaynak'] === 'fiyat' && $f['tam_dk'] === 480);
[$pid, $sid] = mesaiKur(1, 480, 540);
$f = sinif($pid);
okcy('H2) aynı 8 s, fiyatta saat yok → snapshot (9 s) → karar bekliyor', $f['otomatik_sinif'] === null && $f['tam_kaynak'] === 'mesai' && $f['sinif_onayi_gerekli'] === true);
okcy('H2b) Mesai Tanımı "Karar bekliyor"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Karar bekliyor');
[$pid, $sid] = mesaiKur(1, 480, 480);
okcy('H3) snapshot 8 s (fiyatta saat yok) → otomatik Tam', sinif($pid)['otomatik_sinif'] === 'tam');
[$pid, $sid] = mesaiKur(2, 240);
$f = sinif($pid);
okcy('H4) 4 s, Yarım saati 5 s: yarim_alti ipucu VAR ama sınıf otomatik Yarım DEĞİL', $f['yarim_alti'] === true && $f['otomatik_sinif'] === null && $f['etkin_sinif'] === null && $f['sinif_onayi_gerekli'] === true);
[$f, $h] = hesapla($pid, $sid, null, 'yarim');
okcy('H5) muhasebe Yarım kararı → 600', tutar($h) === '600.00' && $f['etkin_sinif'] === 'yarim', tutar($h));
okcy('H6) Mesai Tanımı "Yarım"', pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Yarım');
// toplu değerlendirme tek sınıflandırıcıdan
[$pid, $sid] = mesaiKur(5, 300);
[$pid2] = [mesaiKur(5, 600)[0]];
$tp = pdks_faz8b_toplu_degerlendirme_kaydet([$pid], 'tam', $sid, 1, $db);
okcy('H7) toplu değerlendirme karar bekleyen dönemi işler', $tp['basarili'] === 1 && $tp['atlandi'] === 0, json_encode($tp, JSON_UNESCAPED_UNICODE));
$tp = pdks_faz8b_toplu_degerlendirme_kaydet([$pid2], 'tam', $sid, 1, $db);
okcy('H8) toplu: başka mesainin dönemi ATLANIR (IDOR)', $tp['basarili'] === 0 && $tp['atlandi'] === 1);

// =========================================================
echo "\n=== I. DOĞRULAMA ===\n";
$hatali = [
    'I1) Tam > FM başı'              => ['full_day' => '9', 'overtime_start' => '8'],
    'I2) çift ≤ FM başı'             => ['full_day' => '9', 'double_day' => '9', 'double_day_rate' => '2000'],
    'I3) çift eşiği var, ücret yok'   => ['double_day' => '12'],
    'I4) çift ücreti var, eşik yok'   => ['double_day_rate' => '2000'],
    'I5) çift ücreti 0'               => ['double_day' => '12', 'double_day_rate' => '0'],
    'I6) yarım ≥ tam'                => ['full_day' => '9', 'half_day' => '9'],
    'I7) saat < 1'                   => ['full_day' => '0:30'],
    'I8) saat > 24'                  => ['double_day' => '25', 'double_day_rate' => '2000'],
    'I9) geçersiz biçim (9:75)'      => ['full_day' => '9:75'],
    'I10) geçersiz biçim (abc)'      => ['full_day' => 'abc'],
    'I11) çift ≤ snapshot (Tam boş, çavuş 9 s)' => ['double_day' => '8', 'double_day_rate' => '2000'],
];
$tarihSay = 0;
foreach ($hatali as $ad => $saat) {
    $r = pdks_faz8b_oran_ekle(6, $kadin, '1000', '600', 'hourly', '150', date('Y-m-d', strtotime('2021-01-01 +' . (++$tarihSay) . ' days')), 'TRY', 1, $db, $saat);
    okcy($ad . ' reddedilir', $r['ok'] === false, json_encode($r, JSON_UNESCAPED_UNICODE));
}
okcy('I12) reddedilenlerin hiçbiri yazılmadı', (int)$db->query("SELECT COUNT(*) FROM foreman_worker_rates WHERE foreman_id = 6")->fetchColumn() === 0);
$r = pdks_faz8b_oran_ekle(6, $kadin, '1000', '600', 'hourly', '150', '2022-01-01', 'TRY', 1, $db,
    ['full_day' => '9:30', 'overtime_start' => '10', 'double_day' => '12.30', 'double_day_rate' => '2000,50']);
$o6 = $db->query("SELECT * FROM foreman_worker_rates WHERE foreman_id = 6")->fetch();
okcy('I13) "9:30" / "12.30" / "2000,50" kabul, dakikaya çevrildi', $r['ok'] === true && (int)$o6['full_day_minutes'] === 570
    && (int)$o6['overtime_start_minutes'] === 600 && (int)$o6['double_day_minutes'] === 750 && (float)$o6['double_day_rate'] === 2000.5, json_encode([$r, $o6], JSON_UNESCAPED_UNICODE));
$r = pdks_faz8b_oran_ekle(6, $karisik, '1000', '600', 'hourly', '150', '2022-01-01', 'TRY', 1, $db, ['full_day' => '9']);
okcy('I14) Karışık tipe fiyat (saatli de olsa) REDDEDİLİR', $r['ok'] === false && str_contains((string)$r['hata'], 'Karışık'), json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('I15) saat girdisi ayrıştırıcı', pdks_faz8b_saat_girdi_dk('9') === 540 && pdks_faz8b_saat_girdi_dk(' 9:30 ') === 570
    && pdks_faz8b_saat_girdi_dk('') === null && pdks_faz8b_saat_girdi_dk('9,5') === -1 && pdks_faz8b_dk_girdi(570) === '9:30' && pdks_faz8b_dk_girdi(540) === '9');

// =========================================================
echo "\n=== J. Yöntem B kişi-gün + Mesai Tanımı özel durumlar ===\n";
$kg = (int)$db->query("SELECT SUM(l.worker_count) FROM foreman_daily_entitlement_lines l
    JOIN foreman_daily_entitlements e ON e.id = l.entitlement_id
    WHERE l.attendance_class_snapshot = 'cift' AND l.worker_type_id IS NOT NULL")->fetchColumn();
$cs = (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlement_lines WHERE attendance_class_snapshot = 'cift'")->fetchColumn();
okcy('J1) çift satırı = 1 kişi-gün (Yöntem B sayım kuralı SUM(worker_count) WHERE worker_type_id IS NOT NULL)', $cs > 0 && $kg === $cs, "$kg / $cs");
okcy('J2) Mesai Tanımı: açık mesaide çıkışsız dönem "⏳ Sürüyor"', pdks_faz8b_mesai_tanimi_etiketi(['etkin_sinif' => null], true, false) === '⏳ Sürüyor');
okcy('J3) Mesai Tanımı: Karışık "—", sınıflandırma yok "—"', pdks_faz8b_mesai_tanimi_etiketi(['etkin_sinif' => 'tam'], false, true) === '—' && pdks_faz8b_mesai_tanimi_etiketi(null, false, false) === '—');
okcy('J4) sınıf etiketi', pdks_faz8b_sinif_etiketi('cift') === 'Çift' && pdks_faz8b_sinif_etiketi('yarim') === 'Yarım' && pdks_faz8b_sinif_etiketi('tam') === 'Tam');

// =========================================================
echo "\n=== K. STATİK — tek sınıflandırıcı / şema kapısı / sayfa kuralları ===\n";
$src = file_get_contents($root . '/config/pdks_faz8b.php');
$govde = function (string $fn) use ($src): string {
    $a = strpos($src, "function {$fn}(");
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b === false ? null : $b - $a);
};
foreach (['pdks_faz8b_degerlendirme_kaydet', 'pdks_faz8b_toplu_degerlendirme_kaydet', 'pdks_faz8b_hakedis_hesapla'] as $fn) {
    $g = $govde($fn);
    okcy("K1) {$fn} süreyi KENDİSİ hesaplamaz (sure_karari / snapshot sorgusu yok)",
        $g !== '' && !str_contains($g, 'pdks_faz8b_sure_karari(') && !str_contains($g, 'normal_work_minutes_snapshot FROM'));
}
okcy('K2) degerlendirme_kaydet + toplu dönemi pdks_faz8b_donem_getir ile okur',
    str_contains($govde('pdks_faz8b_degerlendirme_kaydet'), 'pdks_faz8b_donem_getir(') && str_contains($govde('pdks_faz8b_toplu_degerlendirme_kaydet'), 'pdks_faz8b_donem_getir('));
okcy('K3) sure_karari yalnız sınıflandırıcıdan çağrılır', preg_match_all('/(?<!function )pdks_faz8b_sure_karari\(\$/', $src) === 1
    && str_contains($govde('pdks_faz8b_donem_siniflandir'), 'pdks_faz8b_sure_karari('));
okcy('K4) pdks_faz8b_sema_hazir saat kolonlarını İSTEMEZ', !preg_match('/full_day_minutes|double_day/', $govde('pdks_faz8b_sema_hazir')));
okcy('K5) hakediş ödenecek FM\'i sınıflandırıcıdan alır', str_contains($govde('pdks_faz8b_hakedis_hesapla'), "odenecek_fm_saat"));
$sayfa = file_get_contents($root . '/cavus_fiyatlari.php');
okcy('K6) cavus_fiyatlari.php saat migrasyonunu ÇAĞIRMAZ', !str_contains($sayfa, 'pdks_faz8b_saat_kolonlari_migrate('));
foreach (['daily_rate', 'half_day_rate', 'overtime_mode', 'overtime_rate', 'currency', 'valid_from', 'worker_type_id',
          'full_day_saat', 'half_day_saat', 'overtime_start_saat', 'double_day_saat', 'double_day_rate'] as $alan) {
    if (!str_contains($sayfa, 'name="' . $alan . '"')) okcy("K7) form alanı {$alan}", false);
}
okcy('K7) fiyat formu eski + yeni alan adları yerinde', true);
okcy('K8) migrate.php saat kartı', str_contains(file_get_contents($root . '/migrate.php'), 'pdks_faz8b_saat_kolonlari_migrate('));
okcy('K9) config/pdks_gunluk.php faz8b\'yi require ETMEZ', !preg_match('/require(_once)?[^;]*pdks_faz8b/', file_get_contents($root . '/config/pdks_gunluk.php')));
$detay = file_get_contents($root . '/gunluk_isci_puantaj_detay.php');
okcy('K10) Mesai Detayı Mesai Tanımı sütunu tek sınıflandırıcıdan', str_contains($detay, 'pdks_faz8b_oturum_donemleri(') && str_contains($detay, 'pdks_faz8b_mesai_tanimi_etiketi(') && (bool)preg_match('#<th[^>]*>\s*<button[^>]*>Mesai Tanımı<#u', $detay));   // v299: başlık sıralama düğmesi içinde
okcy('K11) Faz 4 motoru (pdks_hakedis.php) çift bilmez — dokunulmadı', !str_contains(file_get_contents($root . '/config/pdks_hakedis.php'), 'double_day'));
okcy('K12) DURUM etiketi "✅ Çıkış yapıldı" (yanıltıcı "✅ Tam" yok)', pdks_gunluk_faz8a_donem_durumu('closed')['etiket'] === '✅ Çıkış yapıldı' && pdks_gunluk_faz8a_donem_durumu('closed')['kod'] === 'tam');
foreach (['cavus_hakedis_detay.php', 'cavus_hakedis_yazdir.php'] as $sf) {
    okcy("K13) {$sf} 'cift' sınıfını Çift gösterir", str_contains(file_get_contents($root . '/' . $sf), "=== 'cift' ? 'Çift"));
}

// =========================================================
echo "\n=== L. Mesai Detayı render (PUANTAJ_FAZ8B=1) ===\n";
$cmd = 'PUANTAJ_FAZ8B=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/pdks_puantaj_dialog_render.php') . ' 2>&1';
$html = (string)shell_exec($cmd);
okcy('L1) sayfa render edildi, PHP uyarısı yok', str_contains($html, 'Kart Hareketleri') && !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html), substr($html, 0, 300));
okcy('L2) "Mesai Tanımı" başlığı DURUM\'un yanında', (bool)preg_match('#<th[^>]*>\s*<button[^>]*>Durum<.*?</th>\s*<th[^>]*>\s*<button[^>]*>Mesai Tanımı<#us', $html));   // v299: başlıklar sıralama düğmesi içinde
okcy('L3) K001 (13 s, FM 4 onaylı) → "Çift · FM 1 s"', str_contains($html, 'data-mesai-tanim>Çift · FM 1 s<'));
okcy('L4) açık mesaide çıkışsız → "⏳ Sürüyor", Karışık → "—"', str_contains($html, 'data-mesai-tanim>⏳ Sürüyor<') && str_contains($html, 'data-mesai-tanim>—<'));
okcy('L5) mobil kartta da Mesai Tanımı', str_contains($html, 'Mesai Tanımı: <strong>'));
okcy('L6) DURUM "✅ Çıkış yapıldı"', str_contains($html, '✅ Çıkış yapıldı'));
$html0 = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/pdks_puantaj_dialog_render.php') . ' 2>&1');
okcy('L7) Faz 8B şeması yokken sütun GİZLİ', str_contains($html0, 'Kart Hareketleri') && !str_contains($html0, 'Mesai Tanımı'));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
