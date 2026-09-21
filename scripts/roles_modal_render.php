<?php
// =========================================================
// scripts/roles_modal_render.php — roles.php'yi GERÇEK CSS ile tek bir
// HTML dosyasına basar. Tek amacı tarayıcı testine (roles_modal_smoke.js)
// girdi üretmektir; canlı DB'ye dokunmaz (bellek içi SQLite).
//
//   php scripts/roles_modal_render.php > _test_roles.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

function current_user(): ?array { return ['id'=>1,'username'=>'test','display_name'=>'Test']; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function forbidden($m=''): void { throw new RuntimeException('forbidden'); }
function base_url(): string { return ''; }
function audit_log_event(...$a): void {}
function render_header(string $t, bool $p=false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>" . h($t) . "</title>"
       . "<link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p=false): void { echo "</main></body></html>"; }

// GERÇEK yetki kataloğu + korumalı roller (auth.php'den al — 51 yetkinin
// tamamı basılmalı ki gerçek yükseklik ölçülsün)
$asrc = file_get_contents($ROOT . '/config/auth.php');
foreach (['permission_catalog', 'protected_role_slugs'] as $fn) {
    $a = strpos($asrc, "function $fn(): array {");
    $b = strpos($asrc, "\n}\n", $a);
    eval(substr($asrc, $a, $b - $a + 3));
}
function any_active_user_has_permission(string $p): bool { return true; }

db()->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, label TEXT, created_at TEXT DEFAULT '2026-01-01')");
db()->exec("CREATE TABLE role_permissions (role_id INT, permission TEXT, PRIMARY KEY(role_id, permission))");
db()->exec("CREATE TABLE user_roles (user_id INT, role_id INT, PRIMARY KEY(user_id, role_id))");
$insR = db()->prepare("INSERT INTO roles (id, slug, label) VALUES (?, ?, ?)");
$insR->execute([1, 'admin', 'Sistem Yöneticisi']);
$insR->execute([2, 'operator', 'Operatör']);
$insR->execute([137629, 'ik', 'İnsan Kaynakları']);   // canlıdaki gibi 6 haneli id
$insP = db()->prepare("INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)");
foreach (array_keys(array_merge(...array_values(permission_catalog()))) as $p) $insP->execute([1, $p]);
$insP->execute([2, 'records.read']);

$_POST = []; $_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/roles.php';
$_SERVER['PHP_SELF'] = '/roles.php';

$src = file_get_contents($ROOT . '/roles.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|auth)\.php\';\s*$/m', '', $src);
$src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
$src = preg_replace('/^\s*require_perm\([^)]*\);\s*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
// Test için modali AÇIK bas (hidden özniteliğini kaldır)
$src = str_replace('id="rolCreateModal" class="pm-overlay" hidden', 'id="rolCreateModal" class="pm-overlay"', $src);

$tmp = sys_get_temp_dir() . '/roles_render_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
include $tmp;
@unlink($tmp);
