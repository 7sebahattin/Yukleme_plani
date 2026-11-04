<?php
// =========================================================
// scripts/hks_gonderim_rapor_render.php — halkayit/gonderim_rapor.php sayfasını
// bellek içi SQLite + stub auth ile GERÇEKTEN çalıştırıp HTML'i stdout'a basar.
//   GR_QS='bas=2026-10-01&bit=2026-10-20' GR_PERMS='hks.access,reports.export' php scripts/hks_gonderim_rapor_render.php
//   GR_INLINE_CSS=1 → style.css satır içi (tarayıcı testi için)
// Canlı DB'ye dokunmaz, ağ çağrısı yapmaz. Veri SENTETİK (_hks_gonderim_rapor_fixture.php).
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';    // db() = SQLite, auth stub'ları, helpers.php
require_once __DIR__ . '/_hks_gonderim_rapor_fixture.php';
session_start();
$_SESSION['csrf'] = 'x';

function require_perm(string $p): void { if (!can($p)) throw new RuntimeException('forbidden: ' . $p); }
function hks_db(): PDO { return db(); }
function hks_tablo($ad) { return 'hks_' . $ad; }
function hks_tablolari_hazirla(): void {}
if (!class_exists('GrExit')) { class GrExit extends RuntimeException {} }
function gr_header($h, $r = true, $c = 0): void { $GLOBALS['GR_HEADERS'][] = $h; }

hks_grapor_test_kur(db());
$PERMS = array_values(array_filter(explode(',', (string)(getenv('GR_PERMS') ?: 'hks.access,reports.export'))));
$IS_ADMIN = false; $UID = 1; $DEPOLAR = ['DEPO1']; $AKTIF_DEPO = 'DEPO1';

$H = $ROOT . '/halkayit';
$src = (string)file_get_contents($H . '/gonderim_rapor.php');
$src = preg_replace("#^\s*require_once __DIR__ \. '/(config|db|\.\./config/auth)\.php';.*$#m", '', $src, -1, $n);
if ($n !== 3) { fwrite(STDERR, "require satırları bulunamadı ($n)\n"); exit(2); }
$src = str_replace('__DIR__', var_export($H, true), $src);
$src = preg_replace('/\bheader\(/', 'gr_header(', $src);
$src = preg_replace('/\bexit\(/', 'throw new GrExit(', $src);
parse_str((string)getenv('GR_QS'), $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/halkayit/gonderim_rapor.php';
$tmp = sys_get_temp_dir() . '/gr_render_' . getmypid() . '.php';
file_put_contents($tmp, $src);
ob_start();
try { include $tmp; } catch (GrExit $e) { } finally { @unlink($tmp); }
$html = (string)ob_get_clean();
if (getenv('GR_INLINE_CSS')) {
    $css = (string)file_get_contents($ROOT . '/assets/style.css');
    $html = preg_replace_callback('#<link rel="stylesheet" href="[^"]*assets/style\.css\?v=\d+">#', fn() => "<style>\n$css\n</style>", $html, 1);
}
echo $html;
echo "\n<!-- GR_HEADERS:" . json_encode($GLOBALS['GR_HEADERS'] ?? []) . " -->\n";
