<?php
// =========================================================
// scripts/_mail_test_lib.php — Mail Merkezi testlerinin ORTAK harness'i
// (include-only; CLI dışında çalışmaz). Bellek içi SQLite + auth stub'ları +
// GERÇEK config/helpers.php ve config/mail_core.php. Canlı DB'ye HİÇ dokunmaz.
// Her test dosyası bunu require eder (stub'lar global — bir süreçte tek dosya).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Europe/Istanbul');   // config/db.php ile aynı

$ROOT = dirname(__DIR__);
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/mail_test_' . getmypid() . '.log');

$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

$PERMS = []; $IS_ADMIN = false; $UID = 1;
function current_user(): ?array { global $UID; return $UID ? ['id' => $UID, 'username' => 'u' . $UID, 'display_name' => 'Kullanıcı ' . $UID] : null; }
function can(string $p): bool { global $PERMS; return in_array($p, $PERMS, true); }
function is_admin(): bool { global $IS_ADMIN; return $IS_ADMIN; }
function require_login(): array { return current_user(); }
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function active_depot(): ?string { return 'DEPO'; }
function enforce_active_depot(): void {}
function depot_options(): array { return ['DEPO']; }
function depot_visible_to_user(?string $d): bool { return true; }
function user_allowed_depots(): array { return ['DEPO']; }
function depo_sql_records(string $a = 'r'): array { return ['', []]; }
function depo_sql_records_in(string $a = 'r'): array { return ['', []]; }
function depo_sql_column(string $c): array { return ['', []]; }
function depo_sql_in(string $c): array { return ['', []]; }
function user_primary_role(): ?array { return ['slug' => 'operator', 'label' => 'Operatör']; }

require_once $ROOT . '/config/helpers.php';
require_once $ROOT . '/config/mail_core.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $v, string $detay = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detay !== '' ? " :: $detay" : '') . "\n"; }
}
function mail_test_bitir(): never {
    global $pass, $fail;
    echo "\n$pass geçti, $fail kaldı\n";
    exit($fail > 0 ? 1 : 0);
}

// ── MySQL DDL → SQLite çevirici (pdks_cavus_b_smoke.php'nin sadeleştirilmişi) ──
function mail_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı');
    }
    $tablo = $m[1]; $govde = $m[2];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kolonlar = []; $indeksler = [];
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $indeksler[] = "CREATE INDEX `{$mm[1]}` ON `{$tablo}` ({$mm[2]})"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $kolonlar[] = $p;
    }
    return ["CREATE TABLE `{$tablo}` (\n  " . implode(",\n  ", $kolonlar) . "\n)", $indeksler];
}
/** mail_migrate() gerçek akışını SQLite'ta çalıştırmak için: DDL'i çevirip uygular. */
function mail_test_sema_kur(PDO $db): void
{
    foreach (mail_tablolar() as $sql) {
        [$create, $ix] = mail_ddl_sqlite($sql);
        $db->exec($create);
        foreach ($ix as $x) $db->exec($x);
    }
}
function mail_test_diger_tablolar(PDO $db): void
{
    $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, display_name TEXT, is_active INT DEFAULT 1)");
    $db->exec("INSERT INTO users (username, display_name) VALUES ('u1','Kullanıcı 1'),('u2','Kullanıcı 2'),('u3','Kullanıcı 3')");
    $db->exec("CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, action TEXT, module TEXT, record_id INT, old_values TEXT, new_values TEXT, ip TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
}

/** Yalnız test: sahte (üretilmiş) anahtar; gerçek anahtar ASLA kullanılmaz. */
function mail_test_anahtar_kur(): string
{
    $b64 = base64_encode(str_repeat("\x07", 16) . random_bytes(16));
    putenv('MAIL_MASTER_KEY=' . $b64);
    return $b64;
}

/** auth.php'yi yüklemeden (stub çakışması) GERÇEK permission_catalog()'u kaynaktan çıkarır. */
function mail_test_katalog(): array
{
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/config/auth.php');
    $a = strpos($src, 'function permission_catalog(): array {');
    $b = strpos($src, "\n}\n", $a);
    if ($a === false || $b === false) throw new RuntimeException('permission_catalog bulunamadı');
    if (!function_exists('permission_catalog')) eval(substr($src, $a, $b - $a + 3));
    return permission_catalog();
}
