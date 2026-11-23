<?php
// =========================================================
// scripts/hks_rusum_borc_render.php — halkayit/rusum_borc.php sayfasını bellek içi
// SQLite + stub auth ile GERÇEKTEN çalıştırıp HTML'i stdout'a basar. HKS çağrısı SAHTE
// (ağ yok), veri SENTETİK; hks_kv bellek içi.
//   RB_QS='firma=f1&sorgula=1' RB_PERMS='hks.access,hks.read,hks.edit' php scripts/hks_rusum_borc_render.php
//   RB_POST='firma=f1&grup=...&odendi=1' RB_AJAX=1  → POST kolu (ödendi işareti)
//   RB_INLINE_CSS=1 → style.css satır içi (tarayıcı testi için)
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';    // db() = SQLite, auth stub'ları, helpers.php
session_start();
$_SESSION['csrf'] = 'x';
$_SESSION['csrf_token'] = 'x';

function require_perm(string $p): void { if (!can($p)) throw new RuntimeException('forbidden: ' . $p); }
function hks_db(): PDO { return db(); }
function hks_tablo($ad) { return 'hks_' . $ad; }
function hks_tablolari_hazirla(): void {
    static $audit = false; if (!$audit) { $audit = true; siparis_test_diger_tablolar(db()); }
    db()->exec("CREATE TABLE IF NOT EXISTS hks_firmalar (id TEXT PRIMARY KEY, ad TEXT)");
    if (!db()->query("SELECT COUNT(*) FROM hks_firmalar")->fetchColumn()) {
        db()->exec("INSERT INTO hks_firmalar (id, ad) VALUES ('f1', 'ÖRNEK FİRMA A.Ş.'), ('f2', 'DİĞER FİRMA')");
    }
}
$GLOBALS['KV'] = [];
function hks_kv_oku($a, $v = null) { return $GLOBALS['KV'][$a] ?? $v; }
function hks_kv_yaz($a, $d) { $GLOBALS['KV'][$a] = json_decode(json_encode($d), true); }
function hks_firma_bul($id) { return $id === 'f1' ? ['id' => 'f1', 'ad' => 'ÖRNEK FİRMA A.Ş.', 'vergiNo' => '0091880872'] : null; }
if (!class_exists('RbExit')) { class RbExit extends RuntimeException {} }
function rb_header($h, $r = true, $c = 0): void { $GLOBALS['RB_HEADERS'][] = $h; }

$PERMS = array_values(array_filter(explode(',', (string)(getenv('RB_PERMS') ?: 'hks.access,hks.read,hks.edit'))));
$IS_ADMIN = false; $UID = 1; $DEPOLAR = ['DEPO1']; $AKTIF_DEPO = 'DEPO1';

// Sahte HKS verisi: 3 gün rüsum (biri ödendi işaretli olacak), bir gün rüsümsüz.
$GLOBALS['RB_SATIRLAR'] = [
    ['kunyeNo' => 'A1', 'tarih' => date('Y-m-d', strtotime('-20 days')), 'rusum' => 1900.4],
    ['kunyeNo' => 'A2', 'tarih' => date('Y-m-d', strtotime('-20 days')), 'rusum' => 100.0],
    ['kunyeNo' => 'B1', 'tarih' => date('Y-m-d', strtotime('-10 days')), 'rusum' => 51304.2],
    ['kunyeNo' => 'C1', 'tarih' => date('Y-m-d', strtotime('-1 days')), 'rusum' => 2108.4],
    ['kunyeNo' => 'D1', 'tarih' => date('Y-m-d', strtotime('-2 days')), 'rusum' => 0.0],
];
require_once dirname(__DIR__) . '/halkayit/rusum_borc_lib.php';
function rb_sahte_cek($cfg, $b, $e) { return ['satirlar' => $GLOBALS['RB_SATIRLAR'], 'kismiHata' => 0, 'cagri' => 2]; }

$H = $ROOT . '/halkayit';
$src = (string)file_get_contents($H . '/rusum_borc.php');
$src = preg_replace("#^\s*require_once __DIR__ \. '/(config|db|\.\./config/auth|taslak_lib|rusum_borc_lib)\.php';.*$#m", '', $src, -1, $n);
if ($n !== 5) { fwrite(STDERR, "require satırları bulunamadı ($n)\n"); exit(2); }
$src = str_replace('hks_rusum_tahakkuk_cek(', 'rb_sahte_cek(', $src);
$src = preg_replace('/\bheader\(/', 'rb_header(', $src);
$src = preg_replace('/\bexit\(/', 'throw new RbExit(', $src);
$src = preg_replace('/\bexit;/', 'throw new RbExit();', $src);
$src = str_replace('__DIR__', var_export($H, true), $src);
parse_str((string)getenv('RB_QS'), $_GET);
if (getenv('RB_POST') !== false && getenv('RB_POST') !== '') {
    parse_str((string)getenv('RB_POST'), $_POST);
    $_POST['csrf'] = $_SESSION['csrf'] ?? 'x';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    if (getenv('RB_AJAX')) $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
} else {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
// Önceden ödendi işareti
if (getenv('RB_ODENDI')) hks_rusum_odeme_yaz('f1', (string)getenv('RB_ODENDI'), true, 'Test', date('Y-m-d'));
$tmp = sys_get_temp_dir() . '/rb_render_' . getmypid() . '.php';
file_put_contents($tmp, $src);
ob_start();
try { include $tmp; } catch (RbExit $e) { } finally { @unlink($tmp); }
$html = (string)ob_get_clean();
if (getenv('RB_INLINE_CSS')) {
    $css = (string)file_get_contents($ROOT . '/assets/style.css');
    $html = preg_replace_callback('#<link rel="stylesheet" href="[^"]*assets/style\.css\?v=\d+">#', fn() => "<style>\n$css\n</style>", $html, 1);
}
echo $html;
$__audit = [];
try { $__audit = db()->query("SELECT action, module, new_values FROM audit_log")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
echo "\n<!-- RB_HEADERS:" . json_encode($GLOBALS['RB_HEADERS'] ?? []) . " AUDIT:" . json_encode($__audit, JSON_UNESCAPED_UNICODE) . " KV:" . json_encode($GLOBALS['KV'], JSON_UNESCAPED_UNICODE) . " -->\n";
