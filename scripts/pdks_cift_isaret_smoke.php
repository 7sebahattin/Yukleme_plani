<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_cift_isaret_smoke.php — v503: kişi başı ÇİFT YEVMİYE bayrağı (Tam × 2), Yarım minimum 3 saat, FM tek ücret.
//
//   php scripts/pdks_cift_isaret_smoke.php
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


$ekle = fn(int $f, int $tip, string $tam, string $yarim, string $mod, string $fm) => pdks_faz8b_oran_ekle($f, $tip, $tam, $yarim, $mod, $fm, '2020-01-01', 'TRY', 1, $db);
$ekle(1, $kadin, '1000', '600', 'hourly', '150');

echo "=== A. Kolon yokken (fail-closed) ===\n";
okcy('A1) cift kolonu yok → hazır değil', pdks_cift_hazir($db) === false);
[$pid, $sid] = mesaiKur(1, 600);
okcy('A2) pdks_cift_yevmiye_yaz kolon yokken false (yazmaz)', pdks_cift_yevmiye_yaz($db, $pid, true) === false);
[$f, $h] = hesapla($pid, $sid);
okcy('A3) bayraksız 10 s → Tam 1000 + FM', tutar($h) === '1150.00', tutar($h));

echo "\n=== B. Migrate (yalnız ADD COLUMN, idempotent) ===\n";
okcy('B1) tanım: cift_yevmiye TINYINT UNSIGNED NULL', pdks_cift_kolon_tanimi() === [['cift_yevmiye', 'TINYINT UNSIGNED NULL DEFAULT NULL']] && PDKS_CIFT_KOLON === 'cift_yevmiye');
pdks_faz8b_saat_kolonlari_migrate($db);
$m1 = pdks_cift_kolon_migrate($db);
okcy('B2) kolon eklendi', count(array_filter($m1, fn($x) => $x['durum'] === 'eklendi')) === 1, json_encode($m1, JSON_UNESCAPED_UNICODE));
$m2 = pdks_cift_kolon_migrate($db);
okcy('B3) ikinci çalıştırma var', count(array_filter($m2, fn($x) => $x['durum'] === 'var')) === 1 && pdks_cift_hazir($db));

echo "\n=== C. Çift yevmiye = Tam × 2 ===\n";
foreach ([['8 s (kısa; normalde karar bekler)', 480], ['9 s', 540], ['13 s (FM adayı)', 780]] as [$ad, $dk]) {
    [$pid, $sid] = mesaiKur(1, $dk);
    okcy("C1) bayrak yaz ($ad)", pdks_cift_yevmiye_yaz($db, $pid, true) === true);
    $f = sinif($pid);
    okcy("C2) $ad: sınıf cift, cift_isaret, FM 0, finans hazır, onay gerekmez", $f['etkin_sinif'] === 'cift' && $f['cift_isaret'] === true && $f['cift'] === true
        && $f['odenecek_fm_saat'] === 0 && $f['finans_hazir'] === true && $f['sinif_onayi_gerekli'] === false, json_encode($f['etkin_sinif']));
    $h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
    $s = satir($h);
    okcy("C3) $ad: hakediş 2000 (1000 × 2), FM yok, sınıf cift, 1 kişi", tutar($h) === '2000.00' && ($s['attendance_class_snapshot'] ?? '') === 'cift'
        && $s['unit_rate'] === '2000.00' && (int)$s['overtime_hours'] === 0 && (int)$s['worker_count'] === 1, json_encode($s, JSON_UNESCAPED_UNICODE));
    okcy("C4) $ad: Mesai Tanımı 'Çift'", pdks_faz8b_mesai_tanimi_etiketi($f, false, false) === 'Çift', pdks_faz8b_mesai_tanimi_etiketi($f, false, false));
}
[$pid, $sid] = mesaiKur(1, 540);
pdks_cift_yevmiye_yaz($db, $pid, true); pdks_cift_yevmiye_yaz($db, $pid, false);
okcy('C5) bayrak kaldırılınca (NULL) normal Tam 1000', tutar(pdks_faz8b_hakedis_hesapla($sid, 1, $db)) === '1000.00' && sinif($pid)['cift_isaret'] === false);
okcy('C6) süre bazlı çift devre dışı: esikler cift_dk/cift_ucret her zaman null',
    pdks_faz8b_esikler(540, ['double_day_minutes' => 720, 'double_day_rate' => '2000'])['cift_dk'] === null
    && pdks_faz8b_esikler(540, ['double_day_minutes' => 720, 'double_day_rate' => '2000'])['cift_ucret'] === null);
$db->exec("UPDATE foreman_worker_rates SET double_day_minutes = 720, double_day_rate = '5000'");
[$pid, $sid] = mesaiKur(1, 780);
[$f, $h] = hesapla($pid, $sid);
okcy('C7) eski double_day_* verisi dolu olsa da 13 s → Tam + 4 FM = 1600 (çift yok)', tutar($h) === '1600.00' && $f['cift'] === false, tutar($h));
[$pid, $sid] = mesaiKur(1, 600);
pdks_cift_yevmiye_yaz($db, $pid, true);
okcy('C8) eski double_day_rate (5000) KULLANILMAZ: çift = Tam × 2 = 2000', tutar(pdks_faz8b_hakedis_hesapla($sid, 1, $db)) === '2000.00');
$kg = (int)$db->query("SELECT SUM(worker_count) FROM foreman_daily_entitlement_lines WHERE attendance_class_snapshot = 'cift' AND worker_type_id IS NOT NULL")->fetchColumn();
$cs = (int)$db->query("SELECT COUNT(*) FROM foreman_daily_entitlement_lines WHERE attendance_class_snapshot = 'cift'")->fetchColumn();
okcy('C9) çift satırı = 1 kişi-gün (Yöntem B sayımı)', $cs > 0 && $kg === $cs);
// K5: Tam fiyatı olmayan çavuşta bayraklı kişi → eksik mesajı aranan fiyatı (Tam) söyler, "Çift Mesai" demez
$db->exec("INSERT INTO foreman_worker_rates (is_active,foreman_id,worker_type_id,daily_rate,half_day_rate,overtime_mode,overtime_rate,currency,valid_from) VALUES (1,7,{$kadin},NULL,'600','hourly','150','TRY','2020-01-01')");
[$pid, $sid] = mesaiKur(7, 540);
pdks_cift_yevmiye_yaz($db, $pid, true);
$h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
$eks = implode(' | ', $h['eksikler'] ?? []);
okcy('C10) Tam fiyatı yok + çift bayrak → hesap durur, eksik "Tam Mesai fiyatı yok (çift yevmiye Tam × 2 ödenir)", "Çift Mesai" YOK',
    ($h['ok'] ?? true) === false && str_contains($eks, 'Tam Mesai fiyatı yok (çift yevmiye Tam × 2 ödenir)') && !str_contains($eks, 'Çift Mesai')
    && !str_contains((string)($h['hata'] ?? ''), 'Çift Mesai'), $eks . ' / ' . ($h['hata'] ?? ''));

echo "\n=== D. Yarım: minimum 3 saat ===\n";
[$pid, $sid] = mesaiKur(1, 150);   // 2 s 30 dk
$r = pdks_faz8b_degerlendirme_kaydet($pid, 'yarim', null, 1, $db);
okcy('D1) 2 s 30 dk → Yarım onayı REDDEDİLİR (mesaj 3 saat der)', $r['ok'] === false && str_contains((string)$r['hata'], '3 saat'), json_encode($r, JSON_UNESCAPED_UNICODE));
// eski onay DB'de varsa ücret yazılmaz
$db->prepare("UPDATE daily_worker_work_periods SET approved_attendance_class = 'yarim' WHERE id = ?")->execute([$pid]);
$f = sinif($pid);
okcy('D2) eski Yarım onaylı 2 s 30 dk → yarim_min_ihlal', $f['etkin_sinif'] === 'yarim' && $f['yarim_min_ihlal'] === true);
$h = pdks_faz8b_hakedis_hesapla($sid, 1, $db);
okcy('D3) ücret YOK: satır 0,00 (unit_rate 0), toplam 0', tutar($h) === '0.00' && satir($h)['unit_rate'] === '0.00', json_encode($h['lines'] ?? $h, JSON_UNESCAPED_UNICODE));
[$pid, $sid] = mesaiKur(1, 180);   // tam 3 s
$r = pdks_faz8b_degerlendirme_kaydet($pid, 'yarim', null, 1, $db);
okcy('D4) tam 3 saat → Yarım onaylanır', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
okcy('D5) 3 saat Yarım → Yarım ücreti 600', tutar(pdks_faz8b_hakedis_hesapla($sid, 1, $db)) === '600.00' && sinif($pid)['yarim_min_ihlal'] === false);
[$pid, $sid] = mesaiKur(1, 300);
$r = pdks_faz8b_degerlendirme_kaydet($pid, 'yarim', null, 1, $db);
okcy('D6) 5 saat → Yarım 600', $r['ok'] === true && tutar(pdks_faz8b_hakedis_hesapla($sid, 1, $db)) === '600.00');
okcy('D7) sabit PDKS_FAZ8B_YARIM_MIN_DK = 180', PDKS_FAZ8B_YARIM_MIN_DK === 180);

echo "\n=== E. FM tek ücret ===\n";
$db->exec("INSERT INTO worker_types (code,name) VALUES ('ERKEK','Erkek')");
$erkek = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$ekle(2, $kadin, '1000', '600', 'hourly', '150');
$ekle(2, $erkek, '1200', '700', 'hourly', '200');
$r = pdks_faz8b_oran_ekle(3, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db);
$ekle(3, $erkek, '1200', '700', 'hourly', '200');
// çavuş 2: Kadın için yeni dönem FM 175 → Erkek güncel satırı da 175 olur
$r = pdks_faz8b_oran_ekle(2, $kadin, '1000', '600', 'fixed', '175', date('Y-m-d', strtotime('-1 day')), 'TRY', 1, $db);
okcy('E1) oran_ekle ok + diğer tip eşitlendi (1 satır)', $r['ok'] === true && count($r['fm_esitlenen']) === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$er = $db->query("SELECT overtime_mode, overtime_rate, daily_rate, half_day_rate FROM foreman_worker_rates WHERE foreman_id = 2 AND worker_type_id = $erkek")->fetch();
okcy('E2) Erkek FM = fixed 175; Tam/Yarım DEĞİŞMEDİ (1200/700)', $er['overtime_mode'] === 'fixed' && (float)$er['overtime_rate'] === 175.0 && (float)$er['daily_rate'] === 1200.0 && (float)$er['half_day_rate'] === 700.0, json_encode($er));
$er3 = $db->query("SELECT overtime_rate FROM foreman_worker_rates WHERE foreman_id = 3 AND worker_type_id = $erkek")->fetch();
okcy('E3) başka çavuşun satırı etkilenmedi (200)', (float)$er3['overtime_rate'] === 200.0);
$r = pdks_faz8b_fm_tek_esitle(2, $kadin, 'fixed', '175.00', 1, $db);
okcy('E4) aynı değerle tekrar → güncelleme yok (idempotent)', $r === []);
$kaynak = file_get_contents($root . '/config/pdks_faz8b.php');
okcy('E5) oran_ekle fm_tek_esitle çağırır', str_contains($kaynak, 'pdks_faz8b_fm_tek_esitle($foremanId, $workerTypeId'));

echo "\n=== F. Statik ===\n";
$j = file_get_contents($root . '/config/pdks_faz8j.php');
okcy('F1) pdks_faz8j_duzelt_satir cift bayrağını pdks_cift_yevmiye_yaz ile yazar (ikinci UPDATE yolu yok)', str_contains($j, 'pdks_cift_yevmiye_yaz($pdo,$pid,$cift)') && !str_contains($j, 'SET cift_yevmiye'));
okcy('F2) pdks_faz8b_sema_hazir cift kolonunu İSTEMEZ', !str_contains(substr($kaynak, strpos($kaynak, 'function pdks_faz8b_sema_hazir'), 900), 'cift_yevmiye'));
okcy('F3) migrate.php cift kartı + POST dalı + csrf', str_contains(file_get_contents($root . '/migrate.php'), "mg_kart_ac('cift-yevmiye')") && (bool)preg_match("/'pdks_cift'\)\s*\{\s*csrf_check/", file_get_contents($root . '/migrate.php')));
okcy('F4) ekranlarda cift migrate ÇAĞRILMAZ', (function () use ($root) { foreach (glob($root . '/*.php') as $f) { if (basename($f) === 'migrate.php') continue; if (str_contains((string)file_get_contents($f), 'pdks_cift_kolon_migrate')) return false; } return true; })());
okcy('F5) Mesai Detayı düzenleme penceresinde çift kutusu', str_contains(file_get_contents($root . '/gunluk_isci_puantaj_detay.php'), 'name="cift_yevmiye"'));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
