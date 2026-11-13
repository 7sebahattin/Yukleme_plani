<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_toplu_dokum_cift_smoke.php — Çavuş Toplu Döküm + Çavuş Ekstresi: çift yevmiyeli kişiler.
//
// Gerçek bayrak (pdks_cift_kolon_migrate + pdks_cift_yevmiye_yaz) + gerçek hakediş hesabı/kesinleştirme +
// GERÇEK rapor fonksiyonu (dosya tabanlı SQLite) ve gerçek sayfalar ALT SÜREÇTE: Toplu Döküm ekranı, yazdırma,
// XLSX, kart dökümü detayı (+yazdırma), Çavuş Ekstresi ekranı + XLSX (Yarım / Çift sütunları).
// Kural: Toplam İşçi çiftleri İÇERİR (AYNEN); Çift sütunu "bunların kaçı çift" bilgisidir, toplanmaz.
//   php scripts/pdks_toplu_dokum_cift_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function okc(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$dbDosya = sys_get_temp_dir() . '/td_cift_' . getmypid() . '.sqlite';
@unlink($dbDosya);
$db = new PDO('sqlite:' . $dbDosya);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $db; return $db; }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $rid = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8b_cavus_b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_rapor.php';

// MySQL DDL → SQLite çevirici (pdks_toplu_dokum_servis_smoke.php ile aynı kasıtlı kopya)
function pdks_ddl_sqlite(string $mysql): array {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) throw new RuntimeException('DDL');
    $tablo = $m[1]; $govde = $m[2]; $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $ind = [];
    $liste = fn(string $h) => preg_replace('/\s+/', ' ', trim(preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $h)));
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ind[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ind[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $ind];
}
function ddlKur(PDO $db, array $tablolar): void {
    foreach ($tablolar as $sql) { [$c, $ix] = pdks_ddl_sqlite($sql); $db->exec($c); foreach ($ix as $x) $db->exec($x); }
}

$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 1)");
$db->prepare("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek'), (?, ?)")->execute([PDKS_GUNLUK_RAMPACI_KOD, PDKS_GUNLUK_RAMPACI_AD]);
$tip = [];
foreach ($db->query("SELECT id, code FROM worker_types")->fetchAll() as $t) $tip[$t['code']] = (int)$t['id'];
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, uid_decimal TEXT, canonical_uid TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT, notes TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', source TEXT, is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test')");
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1'), (2,'C2','Çavuş 2')");
ddlKur($db, pdks_faz8b_cavus_ucret_tablolar());
ddlKur($db, pdks_cari_tablolar());
pdks_yevmiye_kolon_migrate($db);

$day = date('Y-m-d', strtotime('-3 days')); $ay = substr($day, 0, 7);
foreach ([1, 2] as $f) {
    pdks_faz8b_oran_ekle($f, $tip['KADIN'], '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
    pdks_faz8b_oran_ekle($f, $tip['ERKEK'], '1600', '800', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
    pdks_faz8b_oran_ekle($f, $tip[PDKS_GUNLUK_RAMPACI_KOD], '1400', '700', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
}
function oturum(int $sid, int $fid, string $gun): void {
    global $db;
    $db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$sid, $fid, 'Çavuş ' . $fid, 'C' . $fid, $gun, 'Depo A']);
}
function donem(int $sid, string $kod, string $ad, string $gun, string $giris = '08:00', string $cikis = '17:00', ?string $onay = null): int {
    global $db, $tip;
    static $kart = 0; $kart++;
    $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$kart, 'K' . $kart]);
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class,approved_attendance_class,status) VALUES (?,?,?,?,?,?,'auto',?,'closed')")
       ->execute([$sid, $kart, $tip[$kod], $ad, "$gun $giris:00", "$gun $cikis:00", $onay]);
    return (int)$db->lastInsertId();
}
// S1 (Çavuş 1): 3 Kadın (2 çift) · 3 Erkek (1 çift, 1 Yarım) · 1 Rampacı yevmiyeli + bayrak (SAYILMAZ) · 1 iptal Kadın + bayrak (SAYILMAZ)
oturum(1, 1, $day);
$k1 = donem(1, 'KADIN', 'Kadın', $day); $k2 = donem(1, 'KADIN', 'Kadın', $day); donem(1, 'KADIN', 'Kadın', $day);
$e1 = donem(1, 'ERKEK', 'Erkek', $day); donem(1, 'ERKEK', 'Erkek', $day); donem(1, 'ERKEK', 'Erkek', $day, '08:00', '13:00', 'yarim');
$r1 = donem(1, PDKS_GUNLUK_RAMPACI_KOD, PDKS_GUNLUK_RAMPACI_AD, $day);
$kIptal = donem(1, 'KADIN', 'Kadın', $day);
$db->prepare("UPDATE daily_worker_work_periods SET " . PDKS_YEVMIYE_KOLON . " = 1 WHERE id = ?")->execute([$r1]);
$db->prepare("UPDATE daily_worker_work_periods SET is_voided = 1, void_reason = 'test' WHERE id = ?")->execute([$kIptal]);
// S2 (Çavuş 2): 1 Kadın, çiftsiz
oturum(2, 2, $day); donem(2, 'KADIN', 'Kadın', $day);
// Çiftsiz ay
oturum(90, 2, '2020-05-05'); donem(90, 'KADIN', 'Kadın', '2020-05-05');

echo "=== 0. Kolon YOKKEN (kurulmamış şema) ===\n";
okc('0a) çift kolonu yok', pdks_cift_hazir($db) === false);
$sat = pdks_rapor_cavus_toplu_dokum($ay, null, null, true, $db);
okc('0b) satırlar cift=0, hata yok', $sat && array_sum(array_column($sat, 'cift')) === 0 && ($sat[0]['cift_tipler'] ?? null) === []);
okc('0c) helper [] döner', pdks_rapor_cift_oturum_toplu([1, 2], [], $db) === []);
$toplamOnce = []; foreach ($sat as $x) $toplamOnce[$x['session_id']] = $x['toplam_isci'];

// ── Alt süreç: gerçek sayfalar ──
$ALT = <<<'PHPKOD'
<?php
declare(strict_types=1);
$db = new PDO('sqlite:' . getenv('TD_DB'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$_izinler = array_filter(explode(',', (string)getenv('TD_IZIN')));
function db(): PDO { global $db; return $db; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test']; }
function can(string $p): bool { global $_izinler; return in_array($p, $_izinler, true); }
function require_perm(string $p): void { if (!can($p)) forbidden(); }
function is_admin(): bool { return false; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function set_flash($a, $b): void {}
function render_flash(): void {}
function forbidden($m = ''): void { http_response_code(403); exit('forbidden'); }
function base_url(): string { return '/'; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header($t, $p = false): void { echo "HEADER\n"; }
function render_footer($p = false): void { echo "FOOTER\n"; }
require_once __ROOT__ . '/config/pdks_gunluk.php';
require_once __ROOT__ . '/config/pdks_hakedis.php';
require_once __ROOT__ . '/config/pdks_cari.php';
require_once __ROOT__ . '/config/pdks_rapor.php';
require_once __ROOT__ . '/config/pdks_faz8b.php';
$_GET = json_decode((string)getenv('TD_GET'), true) ?: [];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/x.php';
$pageSrc = file_get_contents(__ROOT__ . '/' . getenv('TD_SAYFA'));
$pageSrc = preg_replace('/^\s*require_once __DIR__ \. \'\/(config\/db|config\/pdks_gunluk|config\/pdks_hakedis|config\/pdks_cari|config\/pdks_rapor|config\/auth)\.php\';.*$/m', '', $pageSrc);
$pageSrc = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $pageSrc);
foreach (['xlsx_export', 'pdks_faz8b_cavus_b', 'pdks_faz8e', 'print_helpers'] as $_m) $pageSrc = str_replace("__DIR__ . '/config/$_m.php'", var_export(__ROOT__ . "/config/$_m.php", true), $pageSrc);
$pageSrc = preg_replace('/^<\?php\s*$/m', '', $pageSrc, 1);
$pageSrc = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $pageSrc);
eval($pageSrc);
PHPKOD;
$ALT = str_replace('__ROOT__', var_export($ROOT, true), $ALT);
function sayfa(string $kod, string $sayfa, array $get, string $izin, string $et): array {
    global $dbDosya;
    $tmp = sys_get_temp_dir() . '/td_cf_' . $et . '_' . getmypid();
    file_put_contents("$tmp.php", $kod);
    exec('TD_DB=' . escapeshellarg($dbDosya) . ' TD_SAYFA=' . escapeshellarg($sayfa) . ' TD_GET=' . escapeshellarg(json_encode($get))
        . ' TD_IZIN=' . escapeshellarg($izin) . ' php ' . escapeshellarg("$tmp.php") . ' > ' . escapeshellarg("$tmp.out") . ' 2> ' . escapeshellarg("$tmp.err"), $_, $rc);
    $o = [$rc, (string)file_get_contents("$tmp.out"), (string)file_get_contents("$tmp.err"), "$tmp.out"];
    @unlink("$tmp.php"); @unlink("$tmp.err");
    return $o;
}
$hataYok = fn(array $o) => $o[0] === 0 && !preg_match('/Fatal|Warning|Notice|Deprecated/', $o[2] . $o[1]);
$izinF = 'attendance.management_reports,attendance.foreman_accounts,reports.export';
$izinN = 'attendance.management_reports,reports.export';
$temiz = fn(string $s) => trim(preg_replace('/\s+/', ' ', strip_tags($s)));

$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay], $izinF, 'yok');
okc('0d) kolon yokken ekran çalışır, Çift sütunu yok', $hataYok($o) && !str_contains($o[1], 'Çift Yevmiye'), $o[2] . substr($o[1], 0, 300));

echo "\n=== 1. Kurulum: migrate + gerçek bayrak yazıcı + hakediş ===\n";
pdks_cift_kolon_migrate($db);
okc('1a) çift kolonu kuruldu', pdks_cift_hazir($db) === true);
foreach ([$k1, $k2, $e1, $r1, $kIptal] as $pid) okc("1b) bayrak yaz #$pid", pdks_cift_yevmiye_yaz($db, $pid, true) === true);
$h = pdks_faz8b_hakedis_hesapla(1, 1, $db);
okc('1c) S1 hakediş hesaplandı', $h['ok'] === true, json_encode($h, JSON_UNESCAPED_UNICODE));
okc('1d) S2 hakediş hesaplandı', pdks_faz8b_hakedis_hesapla(2, 1, $db)['ok'] === true);
// Beklenen: Kadın 2×3000 + 1500 = 7500 · Erkek 3200 + 1600 + 800 (Yarım) = 5600 · Rampacı 1 yevmiye × 1400 = 1400 → 14500
$tutar = (string)$db->query("SELECT total_amount FROM foreman_daily_entitlements WHERE session_id = 1")->fetchColumn();
okc('1e) hakediş toplamı Tam×2 içeriyor (14500.00)', abs((float)$tutar - 14500) < 0.001, $tutar);
$ciftSatir = $db->query("SELECT worker_type_code_snapshot AS kod, worker_count, line_total FROM foreman_daily_entitlement_lines l JOIN foreman_daily_entitlements e ON e.id = l.entitlement_id WHERE e.session_id = 1 AND attendance_class_snapshot = 'cift' ORDER BY l.id")->fetchAll();
okc('1f) 3 çift satırı (worker_count=1, Kadın 3000 / Erkek 3200)', count($ciftSatir) === 3
    && array_sum(array_column($ciftSatir, 'worker_count')) === 3
    && abs((float)$ciftSatir[0]['line_total'] - 3000) < 0.001 && abs((float)$ciftSatir[2]['line_total'] - 3200) < 0.001, json_encode($ciftSatir));

echo "\n=== 2. Rapor fonksiyonu ===\n";
$sat = pdks_rapor_cavus_toplu_dokum($ay, null, null, true, $db);
$by = []; foreach ($sat as $x) $by[$x['session_id']] = $x;
okc('2a) S1 çift = 3 (2 Kadın + 1 Erkek)', $by[1]['cift'] === 3, json_encode([$by[1]['cift'], $by[1]['cift_tipler']]));
okc('2b) S1 tip kırılımı kadin 2 · erkek 1 (Rampacı yok)', $by[1]['cift_tipler'] === ['kadin' => 2, 'erkek' => 1], json_encode($by[1]['cift_tipler']));
okc('2c) yevmiyeli Rampacı + bayrak sayılmaz, iptal dönem sayılmaz', !isset($by[1]['cift_tipler']['rampaci']) && $by[1]['cift'] === 3);
okc('2d) Toplam İşçi DEĞİŞMEZ (7, çiftleri içerir; iptal hariç)', $by[1]['toplam_isci'] === 7 && $toplamOnce[1] === 7 && $by[2]['toplam_isci'] === $toplamOnce[2]);
okc('2e) S2 çiftsiz: cift 0 / tipler []', $by[2]['cift'] === 0 && $by[2]['cift_tipler'] === []);
$satN = pdks_rapor_cavus_toplu_dokum($ay, null, null, false, $db);
$byN = []; foreach ($satN as $x) $byN[$x['session_id']] = $x;
okc('2f) finansal olmayan: çift adedi aynı', $byN[1]['cift'] === 3 && $byN[1]['cift_tipler'] === ['kadin' => 2, 'erkek' => 1]);
okc('2g) tip metni "Kadın 2 · Erkek 1"; tek tipte boş', pdks_rapor_cift_tip_metni($by[1]['cift_tipler']) === 'Kadın 2 · Erkek 1' && pdks_rapor_cift_tip_metni(['kadin' => 4]) === '');
$sat0 = pdks_rapor_cavus_toplu_dokum('2020-05', null, null, true, $db);
okc('2h) çiftsiz ay satırı cift 0', count($sat0) === 1 && $sat0[0]['cift'] === 0);
okc('2i) faz8b şeması yoksa helper [] (kapı)', (function () use ($db) {
    $db->exec("ALTER TABLE foreman_daily_entitlements RENAME TO fde_x");
    try { return pdks_rapor_cift_oturum_toplu([1], [], $db) === []; }
    finally { $db->exec("ALTER TABLE fde_x RENAME TO foreman_daily_entitlements"); }
})());

echo "\n=== 3. Ekran (cavus_toplu_dokum.php) ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay], $izinF, 'ekran');
okc('3a) çalıştı, Fatal/Warning/Notice yok', $hataYok($o), $o[2] . substr($o[1], 0, 300));
okc('3b) "Çift Yevmiye" başlığı Toplam İşçi\'den sonra, Servis/Hakediş\'ten önce', (bool)preg_match('~<th>Toplam İşçi</th>\s*<th class="num">Çift Yevmiye</th>\s*<th>Hakediş</th>~', $o[1]));
okc('3c) özet kutusu Çift Yevmiye 3', (bool)preg_match('~Çift Yevmiye</div>\s*<div class="val">3</div>~', $o[1]));
okc('3d) satırda 3 + muted kırılım "Kadın 2 · Erkek 1"', (bool)preg_match('~ctd-cift"><strong>3</strong><div class="muted"[^>]*>Kadın 2 · Erkek 1</div>~', $o[1]));
okc('3e) özet Toplam İşçi 8 (S1 7 + S2 1; çift EKLENMEDİ)', (bool)preg_match('~Toplam İşçi</div>\s*<div class="val">8</div>~', $o[1]));
if (getenv('TD_DUMP')) {   // tarayıcı ölçümü için (Playwright)
    $css = '<meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="file://' . $ROOT . '/assets/style.css"><link rel="stylesheet" href="file://' . $ROOT . '/assets/pdks.css"><body class="">';
    file_put_contents((string)getenv('TD_DUMP'), $css . str_replace(["HEADER\n", "FOOTER\n"], '', $o[1]));
}
$on = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay], $izinN, 'ekranN');
okc('3f) finansal yetkisiz: çift adedi görünür', $hataYok($on) && (bool)preg_match('~ctd-cift"><strong>3</strong>~', $on[1]));
$os = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => '2020-05'], $izinF, 'ekranS');
okc('3g) çiftsiz ayda Çift sütunu/kutusu yok', $hataYok($os) && !str_contains($os[1], 'Çift Yevmiye'));

echo "\n=== 4. Yazdırma ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum_yazdir.php', ['ay' => $ay], $izinF, 'yaz');
okc('4a) çalıştı, hata yok', $hataYok($o), $o[2] . substr($o[1], 0, 300));
okc('4b) başlık + kırılım', str_contains($o[1], '<th>Toplam İşçi</th>') && (bool)preg_match('~<th>Toplam İşçi</th>\s*<th>Çift Yevmiye</th>~', $o[1]) && str_contains($o[1], '<small>Kadın 2 · Erkek 1</small>'));
okc('4c) tfoot: Toplam İşçi 8 → Çift 3', (bool)preg_match('~<tfoot>.*?<td class="num">8</td>\s*<td class="num">3</td>~s', $o[1]));
okc('4d) özet kutusu', str_contains($o[1], '<div class="psb-label">Çift Yevmiye</div><div class="psb-value">3</div>'));
$os = sayfa($ALT, 'cavus_toplu_dokum_yazdir.php', ['ay' => '2020-05'], $izinF, 'yazS');
okc('4e) çiftsiz ayda yazdırmada sütun yok', $hataYok($os) && !str_contains($os[1], 'Çift Yevmiye'));

echo "\n=== 5. XLSX ===\n";
require_once $ROOT . '/scripts/_xlsx_altsurec.php';
$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay, 'xlsx' => '1'], $izinN, 'xl');
okc('5a) geçerli zip', $o[0] === 0 && str_starts_with($o[1], "PK\x03\x04") && !str_contains($o[2], 'Fatal'), $o[2] . substr($o[1], 0, 200));
$k = \PhpOffice\PhpSpreadsheet\IOFactory::load($o[3]); @unlink($o[3]); $sh = $k->getActiveSheet();
$bs = xlsx_satir_bul($sh, 'Tarih');
$kC = xlsx_sutun_bul($sh, $bs, 'Çift Yevmiye (kişi)'); $kT = xlsx_sutun_bul($sh, $bs, 'Toplam İşçi'); $kodS = xlsx_sutun_bul($sh, $bs, 'Çavuş Kodu');
okc('5b) "Çift Yevmiye (kişi)" sütunu Toplam İşçi\'nin hemen sağında', $kC !== '' && $kT !== '' && ord($kC) === ord($kT) + 1, "$kT/$kC");
$s1 = 0; $tp = 0;
for ($i = $bs + 1; $i < $bs + 8; $i++) { if ($sh->getCell($kodS . $i)->getValue() === 'C1') $s1 = $i; if ($sh->getCell('A' . $i)->getValue() === 'TOPLAM') $tp = $i; }
okc('5c) S1 hücresi 3 (sayı)', $s1 > 0 && $sh->getCell($kC . $s1)->getDataType() === 'n' && (int)$sh->getCell($kC . $s1)->getValue() === 3);
okc('5d) TOPLAM SUBTOTAL', $tp > 0 && str_starts_with((string)$sh->getCell($kC . $tp)->getValue(), '=SUBTOTAL(9,'));
$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => '2020-05', 'xlsx' => '1'], $izinN, 'xlS');
$k = \PhpOffice\PhpSpreadsheet\IOFactory::load($o[3]); @unlink($o[3]); $sh = $k->getActiveSheet(); $bs = xlsx_satir_bul($sh, 'Tarih');
okc('5e) çiftsiz ayda XLSX sütunu yok', xlsx_sutun_bul($sh, $bs, 'Çift Yevmiye (kişi)') === '');

echo "\n=== 6. Kart dökümü detayı (+yazdırma) ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum_detay.php', ['session_id' => 1, 'ay' => $ay], $izinN, 'det');
okc('6a) detay: Çift Yevmiye 3 kutusu', $hataYok($o) && (bool)preg_match('~Çift Yevmiye</div><div class="val">3</div>~', $o[1]), $o[2] . substr($o[1], 0, 200));
$o2 = sayfa($ALT, 'cavus_toplu_dokum_detay.php', ['session_id' => 2, 'ay' => $ay], $izinN, 'det2');
okc('6b) çiftsiz oturumda kutu yok', $hataYok($o2) && !str_contains($o2[1], 'Çift Yevmiye'));
$o = sayfa($ALT, 'cavus_toplu_dokum_detay_yazdir.php', ['session_id' => 1, 'ay' => $ay], $izinN, 'detyaz');
okc('6c) detay yazdırma: Çift Yevmiye 3', $hataYok($o) && str_contains($o[1], '<div class="psb-label">Çift Yevmiye</div><div class="psb-value">3</div>'), $o[2] . substr($o[1], 0, 200));

echo "\n=== 7. Çavuş Ekstresi (kesin hakediş) ===\n";
$fin = pdks_faz8b_hakedis_finalize(1, 1, false, $db);
okc('7a) S1 kesinleşti', $fin['ok'] === true, json_encode($fin, JSON_UNESCAPED_UNICODE));
$eks = pdks_cari_ekstre(1, null, null, $db);
$hk = null; foreach ($eks['TRY'] ?? [] as $r) if ($r['tip'] === 'HAKEDIS') $hk = $r;
okc('7b) kırılım: Kadın adet 3 / çift 2, Erkek adet 3 / yarım 1 / çift 1, Rampacı çift 0',
    ($hk['kirilim']['tipler']['Kadın']['cift'] ?? null) === 2 && ($hk['kirilim']['tipler']['Kadın']['adet'] ?? null) === 3
    && ($hk['kirilim']['tipler']['Erkek']['cift'] ?? null) === 1 && ($hk['kirilim']['tipler']['Erkek']['yarim'] ?? null) === 1
    && ($hk['kirilim']['tipler'][PDKS_GUNLUK_RAMPACI_AD]['cift'] ?? null) === 0, json_encode($hk['kirilim'] ?? null, JSON_UNESCAPED_UNICODE));
$kol = pdks_cari_kirilim_kolonlar($eks['TRY']);
$deg = pdks_cari_kirilim_degerleri($hk, $kol);
okc('7c) hücre metinleri "3 (2 çift)" ve "3 (1 yarım, 1 çift)"', pdks_cari_kirilim_hucre_metni($deg[0], 'adet') === '3 (2 çift)' && pdks_cari_kirilim_hucre_metni($deg[2], 'adet') === '3 (1 yarım, 1 çift)',
    pdks_cari_kirilim_hucre_metni($deg[0], 'adet') . ' / ' . pdks_cari_kirilim_hucre_metni($deg[2], 'adet'));
$sb = pdks_cari_kirilim_sinif_basliklar($kol);
okc('7d) XLSX sınıf başlıkları Yarım (kişi) + Çift Yevmiye (kişi)', array_column($sb, 'ad') === ['Yarım (kişi)', 'Çift Yevmiye (kişi)']);
okc('7e) sınıf değerleri [1, 3]; kırılımsız satırda null', pdks_cari_kirilim_sinif_degerleri($hk, $sb) === [1, 3] && pdks_cari_kirilim_sinif_degerleri(['kirilim' => null], $sb) === [null, null]);
$o = sayfa($ALT, 'cavus_ekstre.php', ['foreman_id' => '1'], $izinF, 'eks');
okc('7f) ekstre ekranı: "3 (2 çift)" notu', $hataYok($o) && str_contains($o[1], '3 (2 çift)') && str_contains($o[1], '3 (1 yarım, 1 çift)'), $o[2] . substr($o[1], 0, 300));
$o = sayfa($ALT, 'cavus_ekstre.php', ['foreman_id' => '1', 'xlsx' => '1'], $izinF, 'eksxl');
okc('7g) ekstre XLSX geçerli zip', $o[0] === 0 && str_starts_with($o[1], "PK\x03\x04") && !str_contains($o[2], 'Fatal'), $o[2] . substr($o[1], 0, 300));
if (str_starts_with($o[1], "PK\x03\x04")) {
    $k = \PhpOffice\PhpSpreadsheet\IOFactory::load($o[3]); $sh = $k->getSheetByName('Ekstre TRY');
    $bs = $sh ? xlsx_satir_bul($sh, 'Alacak') : 0;
    $kY = $bs ? xlsx_sutun_bul($sh, $bs, 'Yarım (kişi)') : ''; $kC = $bs ? xlsx_sutun_bul($sh, $bs, 'Çift Yevmiye (kişi)') : '';
    $kA = $bs ? xlsx_sutun_bul($sh, $bs, 'Alacak') : '';
    okc('7h) Yarım / Çift AYRI sütunlar, Alacak\'tan önce', $kY !== '' && $kC !== '' && ord($kC) === ord($kY) + 1 && ord($kA) === ord($kC) + 1, "$kY/$kC/$kA");
    $hs = $bs ? xlsx_satir_bul($sh, 'HAKEDİŞ') : 0;
    okc('7i) hakediş satırı Yarım 1 / Çift 3 (sayı)', $hs > 0 && (int)$sh->getCell($kY . $hs)->getValue() === 1 && (int)$sh->getCell($kC . $hs)->getValue() === 3 && $sh->getCell($kC . $hs)->getDataType() === 'n', "satir=$hs");
    $tp = 0; for ($i = $bs + 1; $i < $bs + 10; $i++) if ($sh->getCell('A' . $i)->getValue() === 'TOPLAM') $tp = $i;
    okc('7j) TOPLAM satırında SUBTOTAL', $tp > 0 && str_starts_with((string)$sh->getCell($kC . $tp)->getValue(), '=SUBTOTAL(9,'));
}
@unlink($o[3]);
okc('7k0) S2 (çiftsiz) kesinleşti', pdks_faz8b_hakedis_finalize(2, 1, false, $db)['ok'] === true);
$o = sayfa($ALT, 'cavus_ekstre.php', ['foreman_id' => '2', 'xlsx' => '1'], $izinF, 'eksxl2');
$sinifYok = false;
if ($o[0] === 0 && str_starts_with($o[1], "PK\x03\x04")) {
    $sinifYok = true;
    foreach (\PhpOffice\PhpSpreadsheet\IOFactory::load($o[3])->getAllSheets() as $shx) {
        foreach ($shx->toArray() as $satirx) if (in_array('Yarım (kişi)', $satirx, true) || in_array('Çift Yevmiye (kişi)', $satirx, true)) $sinifYok = false;
    }
}
okc('7k) çiftsiz/yarımsız çavuşta ekstre XLSX çalışır, sınıf sütunu yok', $sinifYok, $o[2]);
@unlink($o[3]);

echo "\n=== 8. Kaynak taraması ===\n";
$rk = (string)file_get_contents($ROOT . '/config/pdks_rapor.php');
foreach (['cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'cavus_toplu_dokum_detay.php', 'cavus_toplu_dokum_detay_yazdir.php', 'cavus_ekstre.php'] as $f) {
    $kod = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', (string)file_get_contents($ROOT . '/' . $f));
    // (detay sayfasının manuel çıkış audit sorgusu daily_worker_work_periods MODÜL adını taşır — çiftle ilgisiz)
    okc("8a) $f: çift hesabı/SQL yok", !preg_match('/cift_yevmiye|PDKS_CIFT_KOLON|attendance_class_snapshot|pdks_faz8b_donem_|pdks_cift_hazir|JOIN daily_worker_work_periods|FROM daily_worker_work_periods/', $kod));
}
okc('8b) helper tek tanım; sınıflandırıcı döngü DIŞINDA tek çağrı', substr_count($rk, 'function pdks_rapor_cift_oturum_toplu') === 1
    && preg_match('/function pdks_rapor_cift_oturum_toplu.*?\n}\n/s', $rk, $mm) === 1 && substr_count($mm[0], 'pdks_faz8b_donem_sorgu(') === 1
    && str_contains($mm[0], "['cift'] ?? false) !== true") && !str_contains($mm[0], 'yevmiye'));
okc('8c) toplu döküm helper\'ı array_column ile tek kez çağırır', preg_match('/function pdks_rapor_cavus_toplu_dokum.*?pdks_rapor_cift_oturum_toplu\(array_column/s', $rk) === 1);

@unlink($dbDosya);
echo "\n$pass geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
