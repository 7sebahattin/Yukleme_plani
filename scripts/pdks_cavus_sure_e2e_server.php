<?php
declare(strict_types=1);
// Local E2E router: actual page/functions with an isolated SQLite database.
// Never include production db.php or auth.php; never serve this through production.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit(1);
$root = dirname(__DIR__);
$testDb = getenv('CAVUS_SURE_TEST_DB');
if (!$testDb) throw new RuntimeException('CAVUS_SURE_TEST_DB is required.');
date_default_timezone_set('Europe/Istanbul');
$pdoTest = new PDO('sqlite:' . $testDb);
$pdoTest->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdoTest->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $pdoTest; return $pdoTest; }
function can(string $p): bool { return empty($_COOKIE['deny']); }
function is_admin(): bool { return can('admin'); }
function active_depot(): ?string { return 'Depo A'; }
function current_user(): array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcısı']; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function base_url(): string { return '/'; }
function csrf_token(): string { return 'test-csrf'; }
function csrf_check($token): void { if ($token !== csrf_token()) { http_response_code(403); exit('CSRF'); } }
function forbidden($m = ''): void { http_response_code(403); exit('Forbidden'); }
function set_flash($a, $b): void {}
function render_flash(): void {}
function audit_log_event(string $a, string $m, ?int $id = null, ?array $old = null, ?array $new = null, ?int $u = null): void {
    db()->prepare('INSERT INTO audit_log (user_id,action,module,record_id,old_values,new_values) VALUES (?,?,?,?,?,?)')
        ->execute([$u ?? 1, $a, $m, $id, json_encode($old), json_encode($new)]);
}
function render_header(string $title): void {
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container">';
}
function render_footer(): void { echo '</main></body></html>'; }
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === '--sql') {
        $st = db()->query($argv[2]);
        echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
    if (($argv[1] ?? '') !== '--kur') exit(1);
    // Only this process's temporary fixture is reset.
    foreach (db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        db()->exec('DROP TABLE "' . str_replace('"', '""', $name) . '"');
    }
    db()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT)');
    db()->exec("INSERT INTO users VALUES (1,'test','Test Kullanıcısı')");
    db()->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,action TEXT,module TEXT,record_id INTEGER,old_values TEXT,new_values TEXT,ip TEXT,user_agent TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    // Translate the same production table definitions used by the existing render harness.
    $harness = file_get_contents($root . '/scripts/pdks_cavus_fiyat_render.php');
    $from = strpos($harness, 'function pdks_ddl_sqlite(');
    $to = strpos($harness, 'db()->exec("CREATE TABLE `users`', $from);
    if ($from === false || $to === false) throw new RuntimeException('DDL fixture helper was not found.');
    eval(substr($harness, $from, $to - $from));
    ddl_kur(pdks_gunluk_tablolar());
    ddl_kur(pdks_hakedis_tablolar());
    ddl_kur([pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']]);
    pdks_faz8b_migrate(db());
    pdks_faz8b_saat_kolonlari_migrate(db());
    db()->exec("INSERT INTO foremen (id,code,name,normal_work_minutes,is_active) VALUES (1,'C003','Test Çavuş',600,1)");
    db()->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,normal_work_minutes_snapshot,work_date,depo,status,opened_at,opened_by_user_id) VALUES
        (1,1,'Test Çavuş','C003',600,'2026-01-10','Depo A','closed','2026-01-10 06:00:00',1),
        (2,1,'Test Çavuş','C003',600,'2026-01-16','Depo B','closed','2026-01-16 06:00:00',1)");
    echo 'fixture ready';
    exit;
}
$urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($urlPath, '/assets/')) return false;
if ($urlPath !== '/cavus_form.php') { http_response_code(404); exit; }
$src = file_get_contents($root . '/cavus_form.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[a-z0-9_]+\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($root, true), $src);
eval($src);
