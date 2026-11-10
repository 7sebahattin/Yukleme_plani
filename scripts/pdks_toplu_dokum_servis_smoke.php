<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_toplu_dokum_servis_smoke.php — v512 Çavuş Toplu Döküm: Servis — Büyük / Küçük.
//
// Gerçek servis girişi + gerçek hakediş hesabı + GERÇEK rapor fonksiyonu (dosya tabanlı SQLite) ve
// gerçek sayfalar ALT SÜREÇTE: ekran (cavus_toplu_dokum.php), yazdırma, XLSX, kart dökümü detayı (+yazdırma).
// Kural: rapor Servis tutarı hakedişin dondurulmuş SERVIS_* satırlarıdır; hakediş toplamı = işçi + servis.
//   php scripts/pdks_toplu_dokum_servis_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/vendor/autoload.php';

$pass = 0; $fail = 0;
function oksv(string $name, bool $value, string $detay = ''): void {
    global $pass, $fail;
    if ($value) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}

$dbDosya = sys_get_temp_dir() . '/td_servis_' . getmypid() . '.sqlite';
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
require_once $ROOT . '/config/pdks_servis.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_rapor.php';

// MySQL DDL → SQLite çevirici (pdks_servis_smoke.php ile aynı kasıtlı kopya)
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
$db->exec("INSERT INTO worker_types (code,name) VALUES ('KADIN','Kadın'), ('ERKEK','Erkek')");
$kadin = (int)$db->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER NOT NULL DEFAULT 540, is_active INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no TEXT, uid_decimal TEXT, canonical_uid TEXT, worker_type_id INTEGER NULL, status TEXT DEFAULT 'available')");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, normal_work_minutes_snapshot INTEGER NOT NULL DEFAULT 540, work_date TEXT, depo TEXT, status TEXT, notes TEXT)");
$db->exec("CREATE TABLE daily_worker_card_events (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, event_type TEXT, source TEXT)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, worker_card_id INTEGER, worker_type_id_snapshot INTEGER, worker_type_name_snapshot TEXT, entry_time TEXT, exit_time TEXT, declared_attendance_class TEXT DEFAULT 'tam', approved_attendance_class TEXT, approved_by_user_id INTEGER, approved_at TEXT, overtime_approved INTEGER, overtime_approved_by_user_id INTEGER, overtime_approved_at TEXT, overtime_approved_hours INTEGER, exit_event_id INTEGER, status TEXT DEFAULT 'closed', source TEXT, is_voided INTEGER NOT NULL DEFAULT 0, voided_at TEXT, voided_by_user_id INTEGER, void_reason TEXT)");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
$db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE foreman_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INTEGER, currency TEXT, amount TEXT, payment_date TEXT, status TEXT DEFAULT 'valid')");
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INTEGER DEFAULT 1)");
$db->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test')");
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C1','Çavuş 1'), (2,'C2','Çavuş 2'), (3,'C3','Çavuş 3')");
ddlKur($db, pdks_faz8b_cavus_ucret_tablolar());

$day = date('Y-m-d', strtotime('-3 days')); $ay = substr($day, 0, 7);
function mesai(int $sid, int $fid, string $day): void {
    global $db, $kadin;
    $db->prepare("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (?,?,?,?,?,?,'closed')")
       ->execute([$sid, $fid, 'Çavuş ' . $fid, 'C' . $fid, $day, 'Depo A']);
    $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$sid, 'K' . $sid]);
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class,status) VALUES (?,?,?,?,?,?,'tam','closed')")
       ->execute([$sid, $sid, $kadin, 'Kadın', "$day 08:00:00", "$day 17:00:00"]);
}
function istek(): string { return bin2hex(random_bytes(16)); }
foreach ([1, 2, 3] as $f) pdks_faz8b_oran_ekle($f, $kadin, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, $db);
ddlKur($db, pdks_servis_tablolar());
pdks_servis_ucret_ekle(1, '500', '250,50', '2020-01-01', 'TRY', 1, $db);   // Çavuş 1: Büyük 500 / Küçük 250,50
pdks_servis_ucret_ekle(3, '500', '250,50', '2020-01-01', 'TRY', 1, $db);   // Çavuş 3
mesai(1, 1, $day); mesai(2, 2, $day); mesai(3, 3, $day);
$r = pdks_servis_ekle(1, 2, 3, '', istek(), 1, $db);                       // S1: Büyük 2 + Küçük 3
oksv('0a) servis girişi (S1: 2 Büyük + 3 Küçük)', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$r = pdks_servis_ekle(3, 1, 4, '', istek(), 1, $db);                       // S3: Büyük 1 + Küçük 4 → Küçük iptal
oksv('0b) servis girişi (S3)', $r['ok'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
$kId = (int)$db->query("SELECT id FROM daily_session_services WHERE session_id=3 AND service_type='KUCUK'")->fetchColumn();
$ip = pdks_servis_iptal($kId, 3, 'test iptali', 1, $db);
oksv('0c) S3 Küçük iptal (soft)', $ip['ok'] === true, json_encode($ip, JSON_UNESCAPED_UNICODE));
foreach ([1, 2, 3] as $sid) { $h = pdks_faz8b_hakedis_hesapla($sid, 1, $db); oksv("0d) hakediş S$sid", $h['ok'] === true, json_encode($h, JSON_UNESCAPED_UNICODE)); }

echo "\n=== 1. Rapor fonksiyonu (finansal) ===\n";
$sat = pdks_rapor_cavus_toplu_dokum($ay, null, null, true, $db);
$by = []; foreach ($sat as $x) $by[$x['session_id']] = $x;
oksv('1a) S1 adetler 2 Büyük / 3 Küçük', $by[1]['servis_buyuk'] === 2 && $by[1]['servis_kucuk'] === 3, json_encode($by[1]));
oksv('1b) S1 tutarlar 1000.00 / 751.50', $by[1]['servis_buyuk_tutar'] === '1000.00' && $by[1]['servis_kucuk_tutar'] === '751.50', json_encode([$by[1]['servis_buyuk_tutar'], $by[1]['servis_kucuk_tutar']]));
oksv('1c) S1 hakediş toplamı = işçi 1500 + servis 1751.50', $by[1]['hakedis']['total_amount'] === '3251.50', (string)$by[1]['hakedis']['total_amount']);
oksv('1d) S1: servis tutarları + işçi = hakediş (rapor toplamı = hakediş toplamı)',
    abs((float)$by[1]['servis_buyuk_tutar'] + (float)$by[1]['servis_kucuk_tutar'] + 1500 - (float)$by[1]['hakedis']['total_amount']) < 0.001);
oksv('1e) S2 servis yok: 0 / 0 / tutar null', $by[2]['servis_buyuk'] === 0 && $by[2]['servis_kucuk'] === 0 && $by[2]['servis_buyuk_tutar'] === null && $by[2]['servis_kucuk_tutar'] === null);
oksv('1f) S3: iptal edilen Küçük sayılmaz (1 Büyük / 0 Küçük)', $by[3]['servis_buyuk'] === 1 && $by[3]['servis_kucuk'] === 0 && $by[3]['servis_buyuk_tutar'] === '500.00' && $by[3]['servis_kucuk_tutar'] === null, json_encode($by[3]));
oksv('1g) Toplam İşçi servisi İÇERMEZ (her oturumda 1)', $by[1]['toplam_isci'] === 1 && $by[3]['toplam_isci'] === 1);
$topB = array_sum(array_column($sat, 'servis_buyuk')); $topK = array_sum(array_column($sat, 'servis_kucuk'));
$tek = pdks_servis_toplamlar_toplu([1, 2, 3], $db);
oksv('1h) ay toplamı = mevcut toplamlar_toplu (tek kaynak adet kuralı)', $topB === $tek['BUYUK'] && $topK === $tek['KUCUK'], "$topB/$topK " . json_encode($tek));

echo "\n=== 2. Finansal olmayan: adet var, tutar yok ===\n";
$satN = pdks_rapor_cavus_toplu_dokum($ay, null, null, false, $db);
$byN = []; foreach ($satN as $x) $byN[$x['session_id']] = $x;
oksv('2a) adetler aynı', $byN[1]['servis_buyuk'] === 2 && $byN[1]['servis_kucuk'] === 3);
oksv('2b) tutar null', $byN[1]['servis_buyuk_tutar'] === null && $byN[1]['servis_kucuk_tutar'] === null);

echo "\n=== 3. Servis tabloları yok → alanlar 0 (eski davranış) ===\n";
$db->exec("ALTER TABLE daily_session_services RENAME TO daily_session_services_x");
$satY = pdks_rapor_cavus_toplu_dokum($ay, null, null, true, $db);
oksv('3a) tablo yokken satırlar 0/null, hata yok', $satY && $satY[0]['servis_buyuk'] === 0 && $satY[0]['servis_kucuk_tutar'] === null);
$db->exec("ALTER TABLE daily_session_services_x RENAME TO daily_session_services");

echo "\n=== 4. Servis OLMAYAN ay ===\n";
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status) VALUES (90,2,'Çavuş 2','C2','2020-05-05','Depo A','closed')");
$sat0 = pdks_rapor_cavus_toplu_dokum('2020-05', null, null, true, $db);
oksv('4a) servissiz ay satırı 0', count($sat0) === 1 && $sat0[0]['servis_buyuk'] === 0);

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
    $tmp = sys_get_temp_dir() . '/td_sv_' . $et . '_' . getmypid();
    file_put_contents("$tmp.php", $kod);
    exec('TD_DB=' . escapeshellarg($dbDosya) . ' TD_SAYFA=' . escapeshellarg($sayfa) . ' TD_GET=' . escapeshellarg(json_encode($get))
        . ' TD_IZIN=' . escapeshellarg($izin) . ' php ' . escapeshellarg("$tmp.php") . ' > ' . escapeshellarg("$tmp.out") . ' 2> ' . escapeshellarg("$tmp.err"), $_, $rc);
    $o = [$rc, (string)file_get_contents("$tmp.out"), (string)file_get_contents("$tmp.err"), "$tmp.out"];
    @unlink("$tmp.php"); @unlink("$tmp.err");
    return $o;
}
$izinF = 'attendance.management_reports,attendance.foreman_accounts,reports.export';
$izinN = 'attendance.management_reports,reports.export';
$temiz = fn(string $s) => trim(preg_replace('/\s+/', ' ', strip_tags($s)));

echo "\n=== 5. Ekran (cavus_toplu_dokum.php) ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay], $izinF, 'ekran');
oksv('5a) çalıştı, Fatal/Warning/Notice yok', $o[0] === 0 && !preg_match('/Fatal|Warning|Notice|Deprecated/', $o[2] . $o[1]), $o[2] . substr($o[1], 0, 300));
oksv('5b) başlıklar Servis — Büyük / Küçük', str_contains($o[1], '<th class="num">Servis — Büyük</th>') && str_contains($o[1], 'Servis — Küçük</th>'));
oksv('5c) özet kutuları (Büyük 3, Küçük 3)', (bool)preg_match('~Servis — Büyük</div>\s*<div class="val">3</div>~', $o[1]) && (bool)preg_match('~Servis — Küçük</div>\s*<div class="val">3</div>~', $o[1]));
oksv('5d) S1 satırında 1.000,00 TRY ve 751,50 TRY tutarları', str_contains($temiz($o[1]), '1.000,00 TRY') && str_contains($temiz($o[1]), '751,50 TRY'));
oksv('5e) Hakediş 3.251,50 TRY (rapor = hakediş)', str_contains($temiz($o[1]), '3.251,50'));
if (getenv('TD_DUMP')) {   // tarayıcı ölçümü için (Playwright): ekran çıktısı + stil bağlantıları
    $css = '<meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="file://' . $ROOT . '/assets/style.css"><link rel="stylesheet" href="file://' . $ROOT . '/assets/pdks.css"><body class="">';
    file_put_contents((string)getenv('TD_DUMP'), $css . str_replace(["HEADER\n", "FOOTER\n"], '', $o[1]));
}
$on = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay], $izinN, 'ekranN');
oksv('5f) finansal olmayan: adet var, tutar yok', str_contains($on[1], 'Servis — Büyük</th>') && !str_contains($on[1], '751,50') && !str_contains($on[1], '1.000,00'));
$os = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => '2020-05'], $izinF, 'ekranS');
oksv('5g) servissiz ayda Servis sütunu yok', !str_contains($os[1], 'Servis — Büyük'));

echo "\n=== 6. Yazdırma ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum_yazdir.php', ['ay' => $ay], $izinF, 'yaz');
oksv('6a) çalıştı, hata yok', $o[0] === 0 && !preg_match('/Fatal|Warning|Notice|Deprecated/', $o[2] . $o[1]), $o[2] . substr($o[1], 0, 300));
oksv('6b) başlıklar + tutarlar', str_contains($o[1], '<th>Servis — Büyük</th>') && str_contains($o[1], '<th>Servis — Küçük</th>') && str_contains($temiz($o[1]), '1.000,00 TRY') && str_contains($temiz($o[1]), '751,50 TRY'));
oksv('6c) tfoot toplamı 3 / 3', (bool)preg_match('~<td class="num">3</td>\s*<td class="num">3</td>~', $o[1]));
$on = sayfa($ALT, 'cavus_toplu_dokum_yazdir.php', ['ay' => $ay], $izinN, 'yazN');
oksv('6d) finansal olmayan: tutar yok', !str_contains($on[1], '751,50') && str_contains($on[1], 'Servis — Büyük'));

echo "\n=== 7. XLSX ===\n";
require_once $ROOT . '/scripts/_xlsx_altsurec.php';
$o = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay, 'xlsx' => '1'], $izinF, 'xl');
oksv('7a) geçerli zip', $o[0] === 0 && str_starts_with($o[1], "PK\x03\x04") && !str_contains($o[2], 'Fatal'), $o[2] . substr($o[1], 0, 200));
$k = \PhpOffice\PhpSpreadsheet\IOFactory::load($o[3]); @unlink($o[3]); $sh = $k->getActiveSheet();
$bs = xlsx_satir_bul($sh, 'Tarih');
$kB = xlsx_sutun_bul($sh, $bs, 'Servis — Büyük (adet)'); $kK = xlsx_sutun_bul($sh, $bs, 'Servis — Küçük (adet)');
$tB = xlsx_sutun_bul($sh, $bs, 'Servis — Büyük Tutar'); $tK = xlsx_sutun_bul($sh, $bs, 'Servis — Küçük Tutar');
oksv('7b) 4 servis sütunu var', $kB !== '' && $kK !== '' && $tB !== '' && $tK !== '');
$kodS = xlsx_sutun_bul($sh, $bs, 'Çavuş Kodu');
$satir1 = 0; for ($i = $bs + 1; $i < $bs + 8; $i++) if ($sh->getCell($kodS . $i)->getValue() === 'C1') $satir1 = $i;
oksv('7c) S1: 2 / 3 adet, 1000 / 751,5 tutar (sayı hücresi)', $satir1 > 0 && (int)$sh->getCell($kB . $satir1)->getValue() === 2 && (int)$sh->getCell($kK . $satir1)->getValue() === 3
    && abs((float)$sh->getCell($tB . $satir1)->getValue() - 1000) < 0.001 && abs((float)$sh->getCell($tK . $satir1)->getValue() - 751.5) < 0.001);
$tp = 0; for ($i = $bs + 1; $i < $bs + 8; $i++) if ($sh->getCell('A' . $i)->getValue() === 'TOPLAM') $tp = $i;
oksv('7d) TOPLAM: adet SUBTOTAL, tutar TOPLANMAZ', $tp > 0 && str_starts_with((string)$sh->getCell($kB . $tp)->getValue(), '=SUBTOTAL(9,') && ($sh->getCell($tB . $tp)->getValue() === null || $sh->getCell($tB . $tp)->getValue() === ''));
$on = sayfa($ALT, 'cavus_toplu_dokum.php', ['ay' => $ay, 'xlsx' => '1'], $izinN, 'xlN');
$k = \PhpOffice\PhpSpreadsheet\IOFactory::load($on[3]); @unlink($on[3]); $sh = $k->getActiveSheet(); $bs = xlsx_satir_bul($sh, 'Tarih');
oksv('7e) finansal olmayan XLSX: adet sütunları var, tutar yok', xlsx_sutun_bul($sh, $bs, 'Servis — Büyük (adet)') !== '' && xlsx_sutun_bul($sh, $bs, 'Servis — Büyük Tutar') === '');

echo "\n=== 8. Kart dökümü detayı (+yazdırma) ===\n";
$o = sayfa($ALT, 'cavus_toplu_dokum_detay.php', ['session_id' => 1, 'ay' => $ay], $izinF, 'det');
oksv('8a) detay: Servis kutuları 2 / 3', $o[0] === 0 && !str_contains($o[2], 'Fatal') && (bool)preg_match('~Servis — Büyük</div>\s*<div class="val">2</div>~', $o[1]) && (bool)preg_match('~Servis — Küçük</div>\s*<div class="val">3</div>~', $o[1]), $o[2] . substr($o[1], 0, 200));
$o2 = sayfa($ALT, 'cavus_toplu_dokum_detay.php', ['session_id' => 2, 'ay' => $ay], $izinF, 'det2');
oksv('8b) servissiz oturumda kutu yok', !str_contains($o2[1], 'Servis — Büyük'));
$o = sayfa($ALT, 'cavus_toplu_dokum_detay_yazdir.php', ['session_id' => 1, 'ay' => $ay], $izinF, 'detyaz');
oksv('8c) detay yazdırma: Servis kutuları', $o[0] === 0 && !str_contains($o[2], 'Fatal') && (bool)preg_match('~Servis — Büyük</div><div class="psb-value">2</div>~', $o[1]) && (bool)preg_match('~Servis — Küçük</div><div class="psb-value">3</div>~', $o[1]), $o[2] . substr($o[1], 0, 200));

echo "\n=== 9. Kaynak taraması ===\n";
$rk = (string)file_get_contents($ROOT . '/config/pdks_rapor.php');
foreach (['cavus_toplu_dokum.php', 'cavus_toplu_dokum_yazdir.php', 'cavus_toplu_dokum_detay.php', 'cavus_toplu_dokum_detay_yazdir.php'] as $f) {
    $s = (string)file_get_contents($ROOT . '/' . $f);
    $kod = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $s);
    oksv("9a) $f: servis hesabı yok (yalnız rapor alanları / helper)", !preg_match('/daily_session_services|foreman_daily_entitlement_lines|SERVIS_BUYUK|pdks_servis_/', $kod));
}
oksv('9b) helper tek sorgu/kaynak (döngüde sorgu yok)', substr_count($rk, 'function pdks_rapor_servis_oturum_toplu') === 1
    && preg_match('/function pdks_rapor_cavus_toplu_dokum.*?pdks_rapor_servis_oturum_toplu\(array_column/s', $rk) === 1);

@unlink($dbDosya);
echo "\n$pass geçti, $fail kaldı\n";
exit($fail ? 1 : 0);
