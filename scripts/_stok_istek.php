<?php
// =========================================================
// scripts/_stok_istek.php — Stok testleri için TEK İSTEK çalıştırıcı (SADECE CLI; alt süreç olarak çalışır). _cari_istek.php aynası.
// Gerçek sayfa/uç dosyasını GERÇEK exit/http_response_code ile çalıştırır (header() kaydedilir); çıktıyı JSON olarak basar.
// Girdi: ortam değişkeni STOK_T_SPEC = base64(JSON {dosya, get, post, server, admin, perms, uid, db, sid?}). DB: dosya tabanlı SQLite (üst süreç hazırlar).
// Çıktı: stdout'un sonunda "@@STOK@@" + JSON {govde, kod, basliklar, flash, hata}.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';

$spec = json_decode((string)base64_decode((string)getenv('STOK_T_SPEC'), true), true);
if (!is_array($spec)) { fwrite(STDERR, "STOK_T_SPEC çözülemedi\n"); exit(2); }
$PDO_TEST = new PDO('sqlite:' . $spec['db']);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!function_exists('require_perm')) { function require_perm(string $p): void { if (!can($p)) forbidden('yetki: ' . $p); } }
$PERMS = $spec['perms'] ?? []; $IS_ADMIN = (bool)($spec['admin'] ?? true); $UID = (int)($spec['uid'] ?? 1);
if (array_key_exists('depolar', $spec)) $DEPOLAR = $spec['depolar'];
$AKTIF_DEPO = (string)($spec['aktif_depo'] ?? 'Merkez Depo');                       // stok örnek verisinin depoları
$DEPO_SECENEK = $spec['depo_secenek'] ?? ['Merkez Depo', 'Şube Depo'];

ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
if (!empty($spec['sid'])) session_id(preg_replace('/[^A-Za-z0-9]/', '', (string)$spec['sid']));
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
    fwrite(STDOUT, "\n@@STOK@@" . json_encode(['govde' => $govde, 'kod' => (int)(http_response_code() ?: 200), 'basliklar' => $GLOBALS['STOK_T_BASLIK'] ?? [], 'flash' => $_SESSION['flash'] ?? null, 'hata' => $HATA], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
});
chdir($ROOT);
function stok_t_header(string $h, bool $replace = true, int $code = 0): void { $GLOBALS['STOK_T_BASLIK'][] = $h; if ($code > 0) http_response_code($code); }
$STOK_T_BASLIK = [];
$donustur = static function (string $yol, string $dizin): string {
    $k = (string)file_get_contents($yol);
    $k = preg_replace("/^\\s*require_once __DIR__ \\. '\\/config\\/(db|auth|cari_core|stok_core)\\.php';[^\\n]*$/m", '', $k);
    $k = preg_replace("/^\\s*require_once __DIR__ \\. '\\/cari_core\\.php';[^\\n]*$/m", '', (string)$k);   // stok_core → cari_core (önceden yüklenir)
    $k = preg_replace('/(?<![\\w>$])header\\(/', 'stok_t_header(', (string)$k);
    return str_replace('__DIR__', var_export($dizin, true), (string)$k);
};
$gecici = [];
foreach (['cari_core', 'stok_core'] as $c) {
    $g = sys_get_temp_dir() . '/stok_t_' . $c . '_' . getmypid() . '.php';
    file_put_contents($g, $donustur($ROOT . '/config/' . $c . '.php', $ROOT . '/config'));
    $gecici[] = $g;
}
$sayfa = sys_get_temp_dir() . '/stok_t_sayfa_' . getmypid() . '_' . basename($spec['dosya']);
file_put_contents($sayfa, $donustur($ROOT . '/' . $spec['dosya'], $ROOT));
$gecici[] = $sayfa;
register_shutdown_function(static function () use ($gecici): void { foreach ($gecici as $g) @unlink($g); });
try {
    foreach (array_slice($gecici, 0, 2) as $g) require_once $g;
    (function () use ($sayfa): void { include $sayfa; })();
} catch (Throwable $e) {
    $HATA = get_class($e) . ': ' . $e->getMessage();
}
