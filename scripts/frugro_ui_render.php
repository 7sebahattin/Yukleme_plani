<?php
// scripts/frugro_ui_render.php — Frugro sayfalarını TARAYICI kontrolü için HTML'e basar (bellek içi SQLite, stub auth).
//   FRUGRO_UI_OUT=/tmp/frg php scripts/frugro_ui_render.php   (FRUGRO_ORNEK_DIR=<xlsx klasörü> ile gerçek örnekler)
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/vendor/autoload.php';
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();
$OUT = getenv('FRUGRO_UI_OUT') ?: sys_get_temp_dir() . '/frg-ui'; @mkdir($OUT, 0777, true);
$db = db(); siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
class FrgExit extends RuntimeException {}
function frgt_header($h, $r = true, $c = 0): void {} function frgt_code($c = null) { return 200; }
function bas(string $dosya, array $get, string $ad): void {
    global $ROOT, $OUT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'frgt_header(', $src); $src = preg_replace('/\bhttp_response_code\(/', 'frgt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new FrgExit();', $src);
    $tmp = sys_get_temp_dir() . '/frg_r_' . getmypid() . '_' . basename($dosya); file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya; $_SERVER['REQUEST_URI'] = '/' . $dosya;
    ob_start(); try { (function () use ($tmp) { include $tmp; })(); } catch (FrgExit $e) {} catch (Throwable $e) { echo '<pre>HATA: ' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>'; }
    $html = (string)ob_get_clean(); @unlink($tmp);
    $html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', $html);
    file_put_contents("$OUT/$ad.html", $html); echo "$ad.html " . strlen($html) . "\n";
}
$PERMS = ['siparis.read', 'siparis.write', 'siparis.delete', 'siparis.match']; $IS_ADMIN = true; $UID = 1;
$dir = getenv('FRUGRO_ORNEK_DIR'); $dosyalar = $dir ? glob($dir . '/*.xlsx') : [$ROOT . '/scripts/fixtures/frugro/order_log_ornek.xlsx'];
foreach ($dosyalar as $f) { $o = frg_xlsx_oku($f); if ($o['ok']) frg_toplu_kaydet($db, $o, ['user_id' => 1, 'kaynak' => 'yukleme', 'dosya_sha' => $o['dosya_sha']]); }
$ids = $db->query('SELECT id FROM frugro_siparisler ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$pal = []; for ($i = 1; $i <= 26; $i++) $pal[] = ['palet_no' => (string)$i, 'kalibre' => '14', 'lot' => '26-1622-44080', 'ggn_no' => '4000000000001', 'ggn_adi' => 'ORNEK URETICI', 'kutu' => 200, 'brut_kg' => 780 + $i, 'net_kg' => 680 + $i];
frg_palet_kaydet($db, (int)$ids[0], $pal);
bas('frugro_siparis.php', [], 'liste');
bas('frugro_view.php', ['id' => $ids[0]], 'detay');
bas('frugro_pkl.php', ['id' => $ids[0]], 'pkl');
bas('frugro_invoice.php', ['id' => $ids[0]], 'invoice');
bas('frugro_ayarlar.php', [], 'ayarlar');
