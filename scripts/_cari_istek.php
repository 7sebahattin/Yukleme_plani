<?php
// =========================================================
// scripts/_cari_istek.php — Cari testleri için TEK İSTEK çalıştırıcı (SADECE CLI; include-only değil, alt süreç olarak çalışır).
// Gerçek sayfa/uç dosyasını GERÇEK exit/header/http_response_code ile çalıştırır; çıktıyı JSON olarak basar.
// Girdi: ortam değişkeni CARI_T_SPEC = base64(JSON {dosya, get, post, server, admin, perms, uid, db}). DB: dosya tabanlı SQLite (üst süreç hazırlar).
// `govde_dosya` verilirse ham gövde o dosyaya yazılır (XLSX gibi ikili çıktılar için).
// Çıktı: stdout'un sonunda "@@CARI@@" + JSON {govde, kod, basliklar, flash, hata}.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';

$spec = json_decode((string)base64_decode((string)getenv('CARI_T_SPEC'), true), true);
if (!is_array($spec)) { fwrite(STDERR, "CARI_T_SPEC çözülemedi\n"); exit(2); }
$PDO_TEST = new PDO('sqlite:' . $spec['db']);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!function_exists('require_perm')) { function require_perm(string $p): void { if (!can($p)) forbidden('yetki: ' . $p); } }   // auth.php yerine stub
$PERMS = $spec['perms'] ?? []; $IS_ADMIN = (bool)($spec['admin'] ?? true); $UID = (int)($spec['uid'] ?? 1);

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
session_start();
$_SESSION['csrf'] = 'test-csrf-belirteci';

$_GET = $spec['get'] ?? []; $_POST = $spec['post'] ?? []; $_REQUEST = array_merge($_GET, $_POST);
$_SERVER['REQUEST_METHOD'] = $spec['server']['REQUEST_METHOD'] ?? ($_POST ? 'POST' : 'GET');
$_SERVER['SCRIPT_FILENAME'] = $ROOT . '/' . $spec['dosya'];
$_SERVER['PHP_SELF'] = '/' . $spec['dosya']; $_SERVER['REQUEST_URI'] = '/' . $spec['dosya'];
foreach (($spec['server'] ?? []) as $k => $v) $_SERVER[$k] = $v;

$HATA = null;
ob_start();
register_shutdown_function(function () use (&$HATA): void {
    $govde = '';
    while (ob_get_level() > 0) $govde = (string)ob_get_clean() . $govde;
    $flash = $_SESSION['flash'] ?? null;
    if (!empty($GLOBALS['spec']['govde_dosya'])) { file_put_contents($GLOBALS['spec']['govde_dosya'], $govde); $govde = ''; }   // ikili çıktı (XLSX) dosyaya
    fwrite(STDOUT, "\n@@CARI@@" . json_encode(['govde' => $govde, 'kod' => (int)(http_response_code() ?: 200), 'basliklar' => array_merge($GLOBALS['CARI_T_BASLIK'] ?? [], headers_list()), 'flash' => $flash, 'hata' => $HATA], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
});
chdir($ROOT);
// CLI'de headers_list() boştur → header() çağrıları kaynakta cari_t_header() ile değiştirilip KAYDEDİLİR (exit/http_response_code GERÇEK kalır).
// Sayfanın db.php/auth.php yüklemesi atlanır (stub'lar _siparis_test_lib.php'de); çekirdek de aynı dönüşümle önceden yüklenir.
function cari_t_header(string $h, bool $replace = true, int $code = 0): void { $GLOBALS['CARI_T_BASLIK'][] = $h; if ($code > 0) http_response_code($code); }
$CARI_T_BASLIK = [];
$donustur = static function (string $yol, string $dizin) use ($ROOT): string {
    $k = (string)file_get_contents($yol);
    $k = preg_replace("/^\\s*require_once __DIR__ \\. '\\/config\\/(db|auth|cari_core)\\.php';\\s*$/m", '', $k);
    $k = preg_replace('/(?<![\\w>$])header\\(/', 'cari_t_header(', (string)$k);
    return str_replace('__DIR__', var_export($dizin, true), (string)$k);
};
$gecici_core = sys_get_temp_dir() . '/cari_t_core_' . getmypid() . '.php';
file_put_contents($gecici_core, $donustur($ROOT . '/config/cari_core.php', $ROOT . '/config'));
$gecici = sys_get_temp_dir() . '/cari_t_sayfa_' . getmypid() . '_' . basename($spec['dosya']);
file_put_contents($gecici, $donustur($ROOT . '/' . $spec['dosya'], $ROOT));
register_shutdown_function(static function () use ($gecici, $gecici_core): void { @unlink($gecici); @unlink($gecici_core); });
try {
    require_once $gecici_core;
    (function () use ($gecici): void { include $gecici; })();
} catch (Throwable $e) {
    $HATA = get_class($e) . ': ' . $e->getMessage();
}
