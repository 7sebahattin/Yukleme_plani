<?php
// =========================================================
// scripts/_pdks_fis_harness.php — "Fiş Excel'i" testlerinin ORTAK SQLite iskeleti.
//
// Bellek içi SQLite + GERÇEK DDL çevirici + gerçek fonksiyonlar (canlı DB'ye dokunmaz).
// pdks_toplu_ekle_smoke.php (Faz 8J/8A tabloları) + pdks_mesai_ozeti_smoke.php (Faz 8B
// fiyat/hakediş tabloları, servis tabloları) harness'lerinin birleşimidir.
// Başında alt çizgi: tum_testler.sh yalnız *_smoke.php / *_test.php çalıştırır.
//
// Kullanım (test dosyası): require __DIR__ . '/_pdks_fis_harness.php';  → db(), ok(), say(),
// sayimlar(), cavus/tip kimlikleri ($kadin,$erkek,$rampaci), $cavus['A'..'H'] hazır olur.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$AKTIF_DEPO = 'Depo A';
$ADMIN = true;
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function active_depot(): ?string { global $AKTIF_DEPO; return $AKTIF_DEPO; }
function current_user(): ?array { return ['id' => 1]; }
function can(string $p): bool { return true; }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null, ?int $u = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id]; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_servis.php';
require_once $ROOT . '/config/pdks_rapor.php';

function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    [$tablo, $govde] = [$m[1], $m[2]];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kol = []; $ix = [];
    $liste = fn(string $s) => preg_replace('/\s+/', ' ', trim(preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $s)));
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kol[] = $p;
    }
    return ["CREATE TABLE `$tablo` (\n  " . implode(",\n  ", $kol) . "\n)", $ix];
}
function fis_ddl_kur(PDO $pdo, string $mysql): void {
    [$c, $ix] = pdks_ddl_sqlite($mysql); $pdo->exec($c); foreach ($ix as $x) $pdo->exec($x);
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Yönetici')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    fis_ddl_kur(db(), $sql);
}
foreach (pdks_gunluk_tablolar() as $sql) fis_ddl_kur(db(), $sql);
pdks_gunluk_migrate(db());
fis_ddl_kur(db(), pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());
// Faz 8B onay kolonları + fiyat/hakediş tabloları
foreach (['approved_attendance_class TEXT', 'approved_by_user_id INTEGER', 'approved_at TEXT', 'overtime_approved INTEGER', 'overtime_approved_hours INTEGER', 'overtime_approved_by_user_id INTEGER', 'overtime_approved_at TEXT'] as $k) {
    $kol = explode(' ', $k)[0];
    $var = false;
    foreach (db()->query('PRAGMA table_info(daily_worker_work_periods)')->fetchAll() as $c) if ($c['name'] === $kol) $var = true;
    if (!$var) db()->exec("ALTER TABLE daily_worker_work_periods ADD COLUMN $k");
}
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
db()->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER DEFAULT 1, foreman_id INTEGER, worker_type_id INTEGER, daily_rate TEXT, half_day_rate TEXT, overtime_mode TEXT, overtime_rate TEXT, currency TEXT, valid_from TEXT, valid_to TEXT, created_by_user_id INTEGER)");
db()->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, foreman_id INTEGER, foreman_name_snapshot TEXT, foreman_code_snapshot TEXT, work_date TEXT, depo TEXT, status TEXT, currency TEXT, total_amount TEXT, needs_recalculation INTEGER DEFAULT 0, calculated_at TEXT, calculated_by_user_id INTEGER, finalized_at TEXT, finalized_by_user_id INTEGER, missing_exit_ack INTEGER, notes TEXT, updated_at TEXT)");
db()->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INTEGER, work_period_id INTEGER, worker_type_id INTEGER, worker_type_code_snapshot TEXT, worker_type_name_snapshot TEXT, attendance_class_snapshot TEXT, worker_count INTEGER, unit_rate TEXT, overtime_hours INTEGER, overtime_mode_snapshot TEXT, overtime_unit_rate TEXT, overtime_total TEXT, line_total TEXT)");
foreach (pdks_servis_tablolar() as $sql) fis_ddl_kur(db(), $sql);
pdks_faz8b_saat_kolonlari_migrate(db());

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
function say(string $t): int { return (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn(); }
function sayimlar(): array {
    global $AUDIT;
    return ['kart' => say('worker_cards'), 'mesai' => say('daily_work_sessions'), 'olay' => say('daily_worker_card_events'),
            'donem' => say('daily_worker_work_periods'), 'audit' => say('audit_log'),
            'hak' => say('foreman_daily_entitlements'), 'servis' => say('daily_session_services'),
            'iptal' => (int)db()->query('SELECT COUNT(*) FROM daily_worker_work_periods WHERE is_voided=1')->fetchColumn()];
}
function fis_audit_say(string $action): int {
    $st = db()->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ?'); $st->execute([$action]); return (int)$st->fetchColumn();
}

pdks_gunluk_rampaci_tip_garanti(db());
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$rampaci = (int)db()->query("SELECT id FROM worker_types WHERE code='RAMPACI'")->fetchColumn();
$cavus = [];
foreach (['A','B','C','D','E','F','G','H'] as $i => $h) {
    $cavus[$h] = (int)pdks_gunluk_cavus_olustur(['code' => 'C00' . ($i + 1), 'name' => 'Çavuş ' . $h], 1, db())['id'];
}
$bugun = date('Y-m-d');
$dun = date('Y-m-d', strtotime('-1 day'));
$dun2 = date('Y-m-d', strtotime('-2 day'));
$dun3 = date('Y-m-d', strtotime('-3 day'));
