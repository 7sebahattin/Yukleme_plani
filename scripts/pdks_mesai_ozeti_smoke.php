<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_mesai_ozeti_smoke.php — v313 Günlük Puantaj "Mesai Özeti" testi.
//
// pdks_faz8b_gun_mesai_ozeti() (config/pdks_faz8b.php) ve
// pdks_servis_toplamlar_toplu() (config/pdks_servis.php).
// Bellek içi SQLite + GERÇEK DDL çevirici (servis tabloları) + gerçek fonksiyonlar
// (pdks_cift_yovmiye_smoke.php / pdks_servis_smoke.php harness'i); canlı DB'ye dokunmaz.
//
//   php scripts/pdks_mesai_ozeti_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function okmo(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
require_once $root . '/config/pdks_servis.php';

// ── MySQL DDL → SQLite çevirici (pdks_servis_smoke.php ile aynı, kasıtlı kopya) ──
function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    $tablo = $m[1]; $govde = $m[2];
    $parcalar = []; $buf = ''; $derinlik = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $derinlik++;
        if ($c === ')') $derinlik--;
        if ($c === ',' && $derinlik === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/\s+/', ' ', trim($mm[2])) . ")";
            continue;
        }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) {
            $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . preg_replace('/\s+/', ' ', trim($mm[2])) . ")";
            continue;
        }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}

// ── Şema (pdks_cift_yovmiye_smoke.php ile aynı temel) ──
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek'), ('KARISIK','Karışık')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)$db->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$karisik = (int)$db->query("SELECT id FROM worker_types WHERE code='KARISIK'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1'), (2,'C2','Çavuş 2')");

$day = date('Y-m-d', strtotime('-3 days'));

function moSession(int $snap = 540, int $foreman = 1): int {
    global $db, $day;
    $db->prepare("INSERT INTO daily_work_sessions (foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,'Depo A','closed')")
       ->execute([$foreman, 'Çavuş ' . $foreman, 'C' . $foreman, $snap, $day]);
    return (int)$db->lastInsertId();
}
/** Dönem ekler. $dk null → çıkışsız (açık) dönem. $fm = onaylı FM saati (null = onaysız). */
function moDonem(int $sid, int $tip, ?int $dk, ?int $fm = null, ?string $sinifOnay = null, bool $voided = false): int {
    global $db, $day, $kadin, $erkek;
    static $n = 0; $n++;
    $db->prepare("INSERT INTO worker_cards (card_no) VALUES (?)")->execute(['M' . $n]);
    $kart = (int)$db->lastInsertId();
    $ad = $tip === $kadin ? 'Kadın' : ($tip === $erkek ? 'Erkek' : 'Karışık');
    $g = strtotime("{$day} 06:00:00");
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,status,overtime_approved_hours,approved_attendance_class,is_voided) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$sid, $kart, $tip, $ad, date('Y-m-d H:i:s', $g), $dk === null ? null : date('Y-m-d H:i:s', $g + $dk * 60),
                  $dk === null ? 'open' : 'closed', $fm, $sinifOnay, $voided ? 1 : 0]);
    return (int)$db->lastInsertId();
}
function moServis(int $sid, string $tur, int $adet, bool $voided = false): void {
    global $db, $day;
    static $n = 0; $n++;
    $db->prepare("INSERT INTO daily_session_services (session_id,foreman_id,work_date,depo,service_type,quantity,istek_id,is_voided) VALUES (?,1,?,'Depo A',?,?,?,?)")
       ->execute([$sid, $day, $tur, $adet, 'mo' . $n, $voided ? 1 : 0]);
}
function moOzet(array $sids): array {
    $o = pdks_faz8b_gun_mesai_ozeti($sids, db());
    return $o ?? ['__null' => true];
}

// =========================================================
echo "=== A. Servis tablosu yokken / boş liste ===\n";
okmo('A1) servis tablosu yok → toplamlar sıfır iskelet', pdks_servis_toplamlar_toplu([1, 2], $db) === ['BUYUK' => 0, 'KUCUK' => 0]);
foreach (pdks_servis_tablolar() as $sql) {
    [$create, $ix] = pdks_ddl_sqlite($sql);
    $db->exec($create);
    foreach ($ix as $x) $db->exec($x);
}
okmo('A2) servis tabloları gerçek DDL ile kuruldu', pdks_servis_tablo_var($db, 'daily_session_services'));

// =========================================================
echo "\n=== 6. Boş session listesi → sıfırlı iskelet ===\n";
$o = pdks_faz8b_gun_mesai_ozeti([], $db);
okmo('6a) null değil (şema hazır)', is_array($o));
$bosBeklenen = ['calisma_dk', 'ham_dk', 'sureli_kisi', 'fm_saat', 'fm_onayli', 'fm_bekleyen', 'fm_red', 'karisik', 'diger'];
$hepsiSifir = is_array($o);
foreach ($bosBeklenen as $k) if (!isset($o[$k]) || $o[$k] !== 0) $hepsiSifir = false;
okmo('6b) sayaçların hepsi 0', $hepsiSifir, json_encode($o, JSON_UNESCAPED_UNICODE));
okmo('6c) servis sıfırlı', ($o['servis'] ?? null) === ['BUYUK' => 0, 'KUCUK' => 0], json_encode($o['servis'] ?? null));
okmo('6d) Kadın/Erkek satırları sıfır olsa da var',
    isset($o['tanim']['Kadın'], $o['tanim']['Erkek'])
    && $o['tanim']['Kadın'] === ['tam' => 0, 'yarim' => 0, 'cift' => 0, 'fm' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], 'bekliyor' => 0, 'suruyor' => 0, 'toplam' => 0],
    json_encode($o['tanim'] ?? null, JSON_UNESCAPED_UNICODE));
$o2 = pdks_faz8b_gun_mesai_ozeti([0, -5], $db);
okmo('6e) geçersiz id\'ler (0, -5) → aynı iskelet', $o2 === $o);

// =========================================================
echo "\n=== 1. Kadın + Erkek dönemleri ===\n";
$s1 = moSession();
moDonem($s1, $kadin, 540);                 // tam gün
moDonem($s1, $kadin, 480);                 // 8 sa, sınıf kararı yok → bekliyor
moDonem($s1, $kadin, 620);                 // 10s20dk → FM aday 2, onaysız
moDonem($s1, $kadin, 620, 2);              // FM onaylı 2
moDonem($s1, $kadin, null);                // çıkışsız → sürüyor
moDonem($s1, $erkek, 540);
$o = moOzet([$s1]);
$k = $o['tanim']['Kadın'] ?? [];
okmo('1a) Kadın: tam 3 (540 + iki 620)', ($k['tam'] ?? -1) === 3, json_encode($k));
okmo('1b) Kadın: 8 saatlik → bekliyor 1', ($k['bekliyor'] ?? -1) === 1, json_encode($k));
okmo('1c) Kadın: çıkışsız → suruyor 1', ($k['suruyor'] ?? -1) === 1, json_encode($k));
okmo('1d) Kadın: toplam 5, yarim/cift 0', ($k['toplam'] ?? -1) === 5 && $k['yarim'] === 0 && $k['cift'] === 0, json_encode($k));
okmo('1e) Erkek: tam 1 / toplam 1', ($o['tanim']['Erkek']['tam'] ?? -1) === 1 && ($o['tanim']['Erkek']['toplam'] ?? -1) === 1);
okmo('1f) fm_bekleyen 2 (onaysız 10s20dk)', $o['fm_bekleyen'] === 2, (string)$o['fm_bekleyen']);
okmo('1g) fm_onayli 2 (onaylı 10s20dk)', $o['fm_onayli'] === 2, (string)$o['fm_onayli']);
okmo('1h) fm_red 0, fm_saat 4 (Σ gösterilen FM)', $o['fm_red'] === 0 && $o['fm_saat'] === 4, json_encode([$o['fm_red'], $o['fm_saat']]));
okmo('1i) sureli_kisi 5 (çıkışsız hariç)', $o['sureli_kisi'] === 5, (string)$o['sureli_kisi']);
okmo('1j) ham_dk = 540+480+620+620+540 = 2800 (çıkışsız süreye katılmaz)', $o['ham_dk'] === 2800, (string)$o['ham_dk']);
okmo('1k) calisma_dk = 480+540*4+4*60 = 2880', $o['calisma_dk'] === 2880, (string)$o['calisma_dk']);
okmo('1l) karisik 0, diger 0', $o['karisik'] === 0 && $o['diger'] === 0);

// FM reddi (onaylı saat 0) → fm_red
$s1b = moSession();
moDonem($s1b, $kadin, 620, 0);
$ob = moOzet([$s1b]);
okmo('1m) FM reddedildi (0 saat) → fm_red 2, onaylı/bekleyen 0, fm_saat 0 (reddedilen gösterilmez)', $ob['fm_red'] === 2 && $ob['fm_onayli'] === 0 && $ob['fm_bekleyen'] === 0 && $ob['fm_saat'] === 0, json_encode($ob, JSON_UNESCAPED_UNICODE));

// Muhasebe kararıyla yarım
$s1c = moSession();
moDonem($s1c, $kadin, 480, null, 'yarim');
$oc = moOzet([$s1c]);
okmo('1n) onaylı Yarım karar → yarim 1, bekliyor 0', ($oc['tanim']['Kadın']['yarim'] ?? -1) === 1 && $oc['tanim']['Kadın']['bekliyor'] === 0, json_encode($oc['tanim']['Kadın'] ?? null));

// Çok mesai birlikte toplanır (s1 + s1b)
$ok2 = moOzet([$s1, $s1b, $s1]);
okmo('1p) Kadın fm dağılımı: iki işçi 2 saatlik FM → fm[2]=2, diğerleri 0', ($o['tanim']['Kadın']['fm'] ?? null) === [1 => 0, 2 => 2, 3 => 0, 4 => 0, 5 => 0], json_encode($o['tanim']['Kadın']['fm'] ?? null));
okmo('1o) iki mesai (tekrarlı id tekilleşir) → sureli_kisi 6, fm_red 2, fm_saat 4 (reddedilen hariç)',
    $ok2['sureli_kisi'] === 6 && $ok2['fm_red'] === 2 && $ok2['fm_saat'] === 4, json_encode($ok2, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== 2. 15 dk tolerans sınırı ===\n";
$s2a = moSession(); moDonem($s2a, $kadin, 540 + 15);
$a = moOzet([$s2a]);
okmo('2a) normal+15 dk → FM 0', $a['fm_saat'] === 0 && $a['fm_bekleyen'] === 0, json_encode($a['fm_saat']));
okmo('2b) normal+15 dk → calisma_dk = min(555,540)+0 = 540', $a['calisma_dk'] === 540 && $a['ham_dk'] === 555, "{$a['calisma_dk']}/{$a['ham_dk']}");
$s2b = moSession(); moDonem($s2b, $kadin, 540 + 16);
$b = moOzet([$s2b]);
okmo('2c) normal+16 dk → FM 1 (bekleyen)', $b['fm_saat'] === 1 && $b['fm_bekleyen'] === 1, json_encode([$b['fm_saat'], $b['fm_bekleyen']]));
okmo('2d) normal+16 dk → calisma_dk = 540+60 = 600, ham 556', $b['calisma_dk'] === 600 && $b['ham_dk'] === 556, "{$b['calisma_dk']}/{$b['ham_dk']}");
$s2c = moSession(); moDonem($s2c, $kadin, 540 + 75);
$c = moOzet([$s2c]);
$s2f = moSession(); moDonem($s2f, $kadin, 540 + 76);
okmo('2e) normal+75 dk → FM 1; normal+76 dk → FM 2', $c['fm_saat'] === 1 && moOzet([$s2f])['fm_saat'] === 2);
$s2d = moSession(); moDonem($s2d, $kadin, 400);
$d = moOzet([$s2d]);
okmo('2f) normalden kısa (400 dk) → calisma_dk = ham = 400, FM 0', $d['calisma_dk'] === 400 && $d['ham_dk'] === 400 && $d['fm_saat'] === 0);
// Oturum snapshot'ı 480 ise eşik 480
$s2e = moSession(480); moDonem($s2e, $kadin, 480 + 16);
$e = moOzet([$s2e]);
okmo('2g) mesai snapshot 480 → 496 dk: tam, FM 1, calisma 540', ($e['tanim']['Kadın']['tam'] ?? 0) === 1 && $e['fm_saat'] === 1 && $e['calisma_dk'] === 540, json_encode([$e['tanim']['Kadın'] ?? null, $e['fm_saat'], $e['calisma_dk']]));

// =========================================================
echo "\n=== 3. Karışık tip ===\n";
$s3 = moSession();
moDonem($s3, $karisik, 700);
moDonem($s3, $karisik, null);
moDonem($s3, $kadin, 540);
$o3 = moOzet([$s3]);
okmo('3a) karisik sayacı 2', $o3['karisik'] === 2, (string)$o3['karisik']);
okmo('3b) Karışık tanıma girmez (Kadın toplam 1)', ($o3['tanim']['Kadın']['toplam'] ?? -1) === 1 && !isset($o3['tanim']['Karışık']), json_encode($o3['tanim'], JSON_UNESCAPED_UNICODE));
okmo('3c) Karışık süreye/FM\'ye katılmaz (sureli 1, ham 540, calisma 540, fm 0)',
    $o3['sureli_kisi'] === 1 && $o3['ham_dk'] === 540 && $o3['calisma_dk'] === 540 && $o3['fm_saat'] === 0, json_encode($o3, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== 4. İptal edilmiş (voided) dönem ===\n";
$s4 = moSession();
moDonem($s4, $kadin, 540);
moDonem($s4, $kadin, 700, null, null, true);
moDonem($s4, $karisik, 600, null, null, true);
$o4 = moOzet([$s4]);
okmo('4a) iptal dönem sayılmaz (Kadın toplam 1)', ($o4['tanim']['Kadın']['toplam'] ?? -1) === 1, json_encode($o4['tanim']['Kadın'] ?? null));
okmo('4b) iptal dönem süre/FM/karışık sayacına girmez', $o4['ham_dk'] === 540 && $o4['fm_saat'] === 0 && $o4['karisik'] === 0 && $o4['sureli_kisi'] === 1, json_encode($o4, JSON_UNESCAPED_UNICODE));

// =========================================================
echo "\n=== 5. Servis adetleri ===\n";
$s5a = moSession(); $s5b = moSession(); $s5c = moSession();
moServis($s5a, 'BUYUK', 2);
moServis($s5a, 'BUYUK', 3);
moServis($s5a, 'KUCUK', 1);
moServis($s5a, 'BUYUK', 7, true);      // iptal
moServis($s5a, 'KUCUK', 9, true);      // iptal
moServis($s5b, 'BUYUK', 1);
moServis($s5b, 'KUCUK', 4);
moServis($s5c, 'BUYUK', 50);           // listede yok
okmo('5a) tek mesai: iptal hariç BUYUK 5 / KUCUK 1', pdks_servis_toplamlar_toplu([$s5a], $db) === ['BUYUK' => 5, 'KUCUK' => 1], json_encode(pdks_servis_toplamlar_toplu([$s5a], $db)));
okmo('5b) çok mesai toplanır: BUYUK 6 / KUCUK 5', pdks_servis_toplamlar_toplu([$s5a, $s5b], $db) === ['BUYUK' => 6, 'KUCUK' => 5], json_encode(pdks_servis_toplamlar_toplu([$s5a, $s5b], $db)));
okmo('5c) listede olmayan mesai dahil edilmez', pdks_servis_toplamlar_toplu([$s5b], $db)['BUYUK'] === 1);
okmo('5d) toplu = tekil toplamların toplamı',
    pdks_servis_toplamlar_toplu([$s5a, $s5b], $db) === array_map(
        fn($t) => pdks_servis_toplamlar($s5a, $db)[$t] + pdks_servis_toplamlar($s5b, $db)[$t], ['BUYUK' => 'BUYUK', 'KUCUK' => 'KUCUK']));
okmo('5e) yalnız iptal edilmiş servis → sıfır', (function () use ($db) {
    $s = moSession(); moServis($s, 'BUYUK', 3, true);
    return pdks_servis_toplamlar_toplu([$s], $db) === ['BUYUK' => 0, 'KUCUK' => 0];
})());
$o5 = moOzet([$s5a, $s5b]);
okmo('5f) mesai özetinin servis alanı toplamlarla aynı', ($o5['servis'] ?? null) === ['BUYUK' => 6, 'KUCUK' => 5], json_encode($o5['servis'] ?? null));
okmo('5g) boş liste / geçersiz id → sıfır', pdks_servis_toplamlar_toplu([], $db) === ['BUYUK' => 0, 'KUCUK' => 0] && pdks_servis_toplamlar_toplu([0], $db) === ['BUYUK' => 0, 'KUCUK' => 0]);


// =========================================================
echo "\n=== 8. FM dağılımı (fm[1..5]) ===\n";
$fz = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
function fmBeklenen(int $n, int $c): array { $a = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]; $a[$n] = $c; return $a; }
function fmDagilim(array $o, string $tip = 'Kadın'): array { return $o['tanim'][$tip]['fm'] ?? ['__yok']; }
function fmTek(int $dk, int $snap = 540, ?int $onay = null): array {
    global $kadin;
    $s = moSession($snap); moDonem($s, $kadin, $dk, $onay);
    return moOzet([$s]);
}
$z = fmTek(611);
okmo('8a) 9 sa mesai, 10s11dk → Tam + fm[1]=1, fm_saat 1', $z['tanim']['Kadın']['tam'] === 1 && fmDagilim($z) === fmBeklenen(1, 1) && $z['fm_saat'] === 1, json_encode($z['tanim']['Kadın']));
$z = fmTek(720);
okmo('8b) 12s00dk → fm[3]=1, fm_saat 3', fmDagilim($z) === fmBeklenen(3, 1) && $z['fm_saat'] === 3, json_encode(fmDagilim($z)));
$z = fmTek(555);
okmo('8c) 9s15dk → FM yok (dağılım sıfır, fm_saat 0)', fmDagilim($z) === $fz && $z['fm_saat'] === 0 && $z['tanim']['Kadın']['tam'] === 1, json_encode(fmDagilim($z)));
$z = fmTek(556);
okmo('8d) 9s16dk → fm[1]', fmDagilim($z) === fmBeklenen(1, 1), json_encode(fmDagilim($z)));
$z = fmTek(736);
okmo('8e) 12s16dk → fm[4]', fmDagilim($z) === fmBeklenen(4, 1) && $z['fm_saat'] === 4, json_encode(fmDagilim($z)));
$z = fmTek(840);
okmo('8f) 14 sa → 5 saat FM → fm[5]', fmDagilim($z) === fmBeklenen(5, 1) && $z['fm_saat'] === 5, json_encode(fmDagilim($z)));
$z = fmTek(900);
okmo('8g) 15 sa → 6 saat FM → fm[5] (5 ve üzeri), fm_saat 6', fmDagilim($z) === fmBeklenen(5, 1) && $z['fm_saat'] === 6, json_encode([fmDagilim($z), $z['fm_saat']]));
$z = fmTek(551, 480);
okmo('8h) snapshot 480: 9s11dk → fm[1]', fmDagilim($z) === fmBeklenen(1, 1) && $z['tanim']['Kadın']['tam'] === 1, json_encode(fmDagilim($z)));
$z = fmTek(620, 540, 0);
okmo('8i) reddedilen FM (0 saat) → dağılıma/fm_saat\'e girmez ama Tam sayılır',
    fmDagilim($z) === $fz && $z['fm_saat'] === 0 && $z['tanim']['Kadın']['tam'] === 1 && $z['fm_red'] === 2, json_encode($z));
$z = fmTek(620, 540, 2);
okmo('8j) onaylı FM → dağılıma girer (fm[2])', fmDagilim($z) === fmBeklenen(2, 1) && $z['fm_onayli'] === 2);

// Kadın / Erkek ayrı satırlar; çıkışsız ve Karışık dağılıma girmez
$s8 = moSession();
moDonem($s8, $kadin, 611);
moDonem($s8, $erkek, 720);
moDonem($s8, $erkek, 720);
moDonem($s8, $kadin, null);
moDonem($s8, $karisik, 720);
moDonem($s8, $karisik, null);
$z = moOzet([$s8]);
okmo('8k) Kadın fm[1]=1, Erkek fm[3]=2 (ayrı satırlar)', fmDagilim($z, 'Kadın') === fmBeklenen(1, 1) && fmDagilim($z, 'Erkek') === fmBeklenen(3, 2), json_encode([fmDagilim($z, 'Kadın'), fmDagilim($z, 'Erkek')]));
okmo('8l) çıkışsız + Karışık dağılıma girmez; fm_saat = 1 + 3 + 3 = 7', $z['karisik'] === 2 && $z['tanim']['Kadın']['suruyor'] === 1 && fmDagilim($z, 'Rampacı') === $fz && $z['fm_saat'] === 7, json_encode($z, JSON_UNESCAPED_UNICODE));
okmo('8m) Σ fm dağılımı (saat ağırlıklı, 5+ için ≥) ile fm_saat uyumlu (üst sınır yok)', array_sum(array_map(fn($t) => array_sum(array_map(fn($n, $c) => $n * $c, array_keys($t['fm']), $t['fm'])), $z['tanim'])) === $z['fm_saat']);

// ── Çift yevmiye (foreman 2: Tam 9 sa, Çift 12 sa / 2000, saatlik FM) ──
echo "\n=== 9. Çift yevmiye ===\n";
pdks_faz8b_saat_kolonlari_migrate($db);
$r = pdks_faz8b_oran_ekle(2, $kadin, '1000', '600', 'hourly', '150', '2020-01-01', 'TRY', 1, $db,
    ['full_day' => '9', 'double_day' => '12', 'double_day_rate' => '2000']);
okmo('9a) çift fiyat dönemi eklendi', ($r['ok'] ?? false) === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$sc = moSession(540, 2); moDonem($sc, $kadin, 780, 4);
$z = moOzet([$sc]);
okmo('9b) 13 sa, FM onaylı 4 → Çift; ödenecek FM 1 → fm[1]=1, fm_saat 1 (aday 4 DEĞİL)',
    $z['tanim']['Kadın']['cift'] === 1 && $z['tanim']['Kadın']['tam'] === 0 && fmDagilim($z) === fmBeklenen(1, 1) && $z['fm_saat'] === 1, json_encode($z['tanim']['Kadın']));
$sc2 = moSession(540, 2); moDonem($sc2, $kadin, 780, 3);
$z = moOzet([$sc2]);
okmo('9c) 13 sa, FM onaylı 3 (çift eşiği = onaylı 12 sa) → Çift, ödenecek FM 0 → dağılım boş, fm_saat 0',
    $z['tanim']['Kadın']['cift'] === 1 && fmDagilim($z) === $fz && $z['fm_saat'] === 0, json_encode($z['tanim']['Kadın']));
$sc3 = moSession(540, 2); moDonem($sc3, $kadin, 780, 0);
$z = moOzet([$sc3]);
okmo('9d) 13 sa, FM reddedildi → Tam, dağılım boş, fm_saat 0', $z['tanim']['Kadın']['tam'] === 1 && $z['tanim']['Kadın']['cift'] === 0 && fmDagilim($z) === $fz && $z['fm_saat'] === 0, json_encode($z['tanim']['Kadın']));


// ── fm_bas_dk: kullanılan FM başlangıç eşikleri ──
echo "\n=== 10. fm_bas_dk ===\n";
okmo('10a) boş liste → fm_bas_dk []', (pdks_faz8b_gun_mesai_ozeti([], $db)['fm_bas_dk'] ?? null) === []);
$sa = moSession(540); moDonem($sa, $kadin, 611); moDonem($sa, $kadin, 540); moDonem($sa, $erkek, null);
okmo('10b) 9 sa mesai → [540]', (moOzet([$sa])['fm_bas_dk'] ?? null) === [540], json_encode(moOzet([$sa])['fm_bas_dk'] ?? null));
$sb = moSession(480); moDonem($sb, $kadin, 551);
okmo('10c) 8 sa + 9 sa mesai karışınca [480,540] (artan, benzersiz)', (moOzet([$sa, $sb])['fm_bas_dk'] ?? null) === [480, 540], json_encode(moOzet([$sa, $sb])['fm_bas_dk'] ?? null));
$sc4 = moSession(480); moDonem($sc4, $kadin, null);
okmo('10d) yalnız çıkışsız dönem → []', (moOzet([$sc4])['fm_bas_dk'] ?? null) === []);

// =========================================================
echo "\n=== 7. Statik denetim ===\n";
$src = file_get_contents($root . '/config/pdks_faz8b.php');
okmo('7a) fonksiyon kaynakta bulundu', (bool)preg_match('/function pdks_faz8b_gun_mesai_ozeti\b.*?\n\}\n/s', $src, $mm));
$govde = $mm[0] ?? '';
okmo('7b) gövdede pdks_faz8b_sure_karari( YOK', $govde !== '' && !str_contains($govde, 'pdks_faz8b_sure_karari('));
okmo('7c) gövdede intdiv( YOK (kendi süre hesabı yok)', $govde !== '' && !str_contains($govde, 'intdiv('));
okmo('7d) gövde pdks_faz8b_donem_sorgu( kullanıyor', str_contains($govde, 'pdks_faz8b_donem_sorgu('));
okmo('7e) gövde servisi pdks_servis_toplamlar_toplu( ile alıyor', str_contains($govde, 'pdks_servis_toplamlar_toplu('));
okmo('7f) gövdede yazma (INSERT/UPDATE/DELETE) YOK', !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $govde));

echo "\nSONUÇ: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
