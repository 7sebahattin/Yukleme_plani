<?php
// =========================================================
// scripts/users_ozet_render.php — users.php'yi GERÇEK CSS ile tek HTML dosyasına basar
// (bellek içi SQLite; canlı DB'ye dokunmaz). Girdi: users_ozet_smoke.js (v507 yetki özeti).
//
//   php scripts/users_ozet_render.php > _test_users.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/role_permission_matrix.php';
require_once $ROOT . '/config/role_data_scope.php';
require_once $ROOT . '/config/beyan_permissions.php';
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

function current_user(): ?array { return ['id' => 1, 'username' => 'yonetici', 'display_name' => 'Test Yönetici']; }
function require_login(): array { return current_user(); }
function require_perm(string $p): void {}
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden'); }
function base_url(): string { return ''; }
function audit_log_event(...$a): void {}
function any_active_user_has_permission(string $p): bool { return true; }
$menuSource = str_replace("\r\n", "\n", file_get_contents($ROOT . '/config/helpers.php'));
$menuStart = strpos($menuSource, 'function nav_muhasebe_gruplari(): array {');
$menuEnd = strpos($menuSource, "\n}\n", $menuStart);
eval(substr($menuSource, $menuStart, $menuEnd - $menuStart + 3));
unset($menuSource);
$asrc = str_replace("\r\n", "\n", file_get_contents($ROOT . '/config/auth.php'));
foreach (['permission_catalog', 'protected_role_slugs'] as $fn) {
    $a = strpos($asrc, "function $fn(): array {");
    $b = strpos($asrc, "\n}\n", $a);
    eval(substr($asrc, $a, $b - $a + 3));
}
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>" . h($t) . "</title>"
       . "<link rel=\"stylesheet\" href=\"" . h('file:///' . str_replace('\\', '/', $GLOBALS['ROOT']) . '/assets/style.css') . "\"></head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

db()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, email TEXT, password_hash TEXT, is_active INT DEFAULT 1, last_login_at TEXT, created_at TEXT DEFAULT '2026-01-01 00:00:00')");
db()->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, label TEXT)");
db()->exec("CREATE TABLE role_permissions (role_id INT, permission TEXT, PRIMARY KEY(role_id, permission))");
db()->exec("CREATE TABLE user_roles (user_id INT, role_id INT, PRIMARY KEY(user_id, role_id))");
db()->exec("CREATE TABLE user_depolar (user_id INT, depo TEXT)");
db()->exec("CREATE TABLE material_definitions (type TEXT, name TEXT, is_active INT DEFAULT 1)");
db()->exec("INSERT INTO material_definitions (type, name) VALUES ('depo','Örnek Depo A')");
db()->exec("INSERT INTO roles VALUES (1,'admin','Sistem Yöneticisi'), (2,'operator','Operatör'), (3,'yukleme','Yükleme'), (4,'bos','Boş Rol')");
$ins = db()->prepare("INSERT INTO role_permissions VALUES (?, ?)");
foreach (['dashboard.read', 'records.read', 'records.write', 'beyan.read', 'beyan.write', 'siparis.read', 'siparis.write'] as $p) $ins->execute([2, $p]);   // eski (işaretleyicisiz) rol
// Yeni düzenleyiciden kaydedilmiş rol: OGL yalnız İşlem Bekleyen kartını GÖRÜR, mail al kapalı
foreach (['role.permissions.detailed', 'beyan.permissions.detailed', 'role.sections.detailed', 'role.catalog.' . ROLE_CATALOG_VERSION,
          'dashboard.read', 'siparis.ogl.read', 'siparis.ogl_acik.read', 'ui.siparis.kpi', 'siparis.urun.read', 'siparis.urun.create', 'siparis.urun.edit', 'siparis.urun.delete'] as $p) $ins->execute([3, $p]);
db()->exec("INSERT INTO users (id, username, display_name, email) VALUES (1,'yonetici','Test Yönetici',''), (2,'operator1','Örnek Operatör',''), (3,'yukleme1','Örnek Yükleme',''), (4,'coklu','Çok Rollü',''), (5,'bos1','Rolsüz Yetki','')");
db()->exec("INSERT INTO user_roles VALUES (1,1), (2,2), (3,3), (4,2), (4,3), (5,4)");

$_POST = []; $_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/users.php'; $_SERVER['PHP_SELF'] = '/users.php';
$src = file_get_contents($ROOT . '/users.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[a-z_]+\.php\';\s*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
// SQLite: MySQL GROUP_CONCAT(... ORDER BY ... SEPARATOR ...) söz diziminin test karşılığı (yalnız bu test kopyasında)
$src = preg_replace("/GROUP_CONCAT\((r\.\w+)\s+ORDER BY r\.slug SEPARATOR '([^']*)'\)/", "GROUP_CONCAT(\\1, '\\2')", $src);
$tmp = sys_get_temp_dir() . '/users_render_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; $html = ob_get_clean(); } finally { @unlink($tmp); }
echo $html;
