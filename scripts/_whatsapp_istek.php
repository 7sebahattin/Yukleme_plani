<?php
// =========================================================
// scripts/_whatsapp_istek.php — WhatsApp testleri için TEK İSTEK çalıştırıcı (SADECE CLI; alt süreç). _kasa_istek.php'nin sadeleşmiş aynası.
// Gerçek sayfa/uç dosyasını (whatsapp_yonetim.php | api_whatsapp_webhook.php) GERÇEK exit/http_response_code ile çalıştırır.
// Girdi: ortam değişkeni WA_T_SPEC = base64(JSON {dosya, get, post, server, admin, uid, db, body?}).
// Çıktı: stdout'un sonunda "@@WA@@" + JSON {govde, kod, basliklar, flash, hata}.
// Sayfa GEÇİCİ KOPYA olarak yüklenir: db/auth require satırları silinir (stub'lar harness'te), header() kaydedilir,
// webhook'ta php://input yerine WA_T_BODY dosyası okunur.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/whatsapp_core.php';   // sayfanın require_once'u (aynı gerçek yol) etkisiz kalır

$spec = json_decode((string)base64_decode((string)getenv('WA_T_SPEC'), true), true);
if (!is_array($spec)) { fwrite(STDERR, "WA_T_SPEC çözülemedi\n"); exit(2); }
$PDO_TEST = new PDO('sqlite:' . $spec['db']);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!function_exists('require_perm')) { function require_perm(string $p): void { if (!can($p)) forbidden('yetki: ' . $p); } }
$IS_ADMIN = (bool)($spec['admin'] ?? true); $UID = (int)($spec['uid'] ?? 1);

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
    fwrite(STDOUT, "\n@@WA@@" . json_encode(['govde' => $govde, 'kod' => (int)(http_response_code() ?: 200), 'basliklar' => $GLOBALS['WA_T_BASLIK'] ?? [], 'flash' => $_SESSION['flash'] ?? null, 'hata' => $HATA], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
});
chdir($ROOT);
function wa_t_header(string $h, bool $replace = true, int $code = 0): void { $GLOBALS['WA_T_BASLIK'][] = $h; if ($code > 0) http_response_code($code); }
$WA_T_BASLIK = [];

$k = (string)file_get_contents($ROOT . '/' . $spec['dosya']);
$k = preg_replace("/^\\s*require_once __DIR__ \\. '\\/config\\/(db|auth)\\.php';[^\\n]*$/m", '', $k);
$k = preg_replace('/(?<![\\w>$])header\\(/', 'wa_t_header(', (string)$k);
$k = str_replace("fopen('php://input', 'rb')", "fopen(getenv('WA_T_BODY') ?: 'php://memory', 'rb')", (string)$k);
$k = str_replace('__DIR__', var_export($ROOT, true), $k);
$sayfa = sys_get_temp_dir() . '/wa_t_sayfa_' . getmypid() . '_' . basename($spec['dosya']);
file_put_contents($sayfa, $k);
register_shutdown_function(static function () use ($sayfa): void { @unlink($sayfa); });
try {
    (function () use ($sayfa): void { include $sayfa; })();
} catch (Throwable $e) {
    $HATA = get_class($e) . ': ' . $e->getMessage();
}
