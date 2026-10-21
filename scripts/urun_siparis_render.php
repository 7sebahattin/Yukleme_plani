<?php
// =========================================================
// scripts/urun_siparis_render.php — Ürün Siparişleri sayfasını TARAYICI testi için HTML dosyalarına basar.
//   URUN_SIPARIS_OUT=/tmp/usip php scripts/urun_siparis_render.php
//   URUN_SIPARIS_OUT=/tmp/usip node scripts/urun_siparis_smoke.js
// Bellek içi SQLite + stub auth + SENTETİK veri. Üretim style.css satır içi, usip.css/usip.js/siparis_yazdir.js file:// ile yüklenir.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/urun_siparis.php';
session_start();
$_SESSION['csrf'] = 'x';

$OUT = getenv('URUN_SIPARIS_OUT') ?: (sys_get_temp_dir() . '/usip-test');
@mkdir($OUT, 0777, true);
$OUT = realpath($OUT);
$db = db();
foreach (usip_tablolar() as $ddl) { [$t, $idx] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($idx as $i) $db->exec($i); }
$db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, unit_dara_kg REAL DEFAULT 0, is_active INTEGER DEFAULT 1)");
foreach ([['palet_tipi', '100x120 EPAL Palet'], ['palet_tipi', '80x120 EPAL Palet'], ['kasa_cinsi', '30x40x11 Plastik Kasa'], ['kasa_cinsi', '40x60x20 Plastik Kasa']] as [$t, $n])
    $db->prepare('INSERT INTO material_definitions (type, name) VALUES (?, ?)')->execute([$t, $n]);

$ornek = function (array $o): array { [$t, $e] = usip_dogrula($o); if ($e) throw new RuntimeException(implode(' ', $e)); return $t; };
$gun = fn(int $n) => date('Y-m-d', strtotime("$n day"));
$ids = [];
$ids[] = usip_kaydet($db, $ornek(['firma' => 'Bereket Tarım A.Ş.', 'urun' => 'Nar', 'teslimat_tarihi' => $gun(-3), 'kalibreler' => [['kalibre' => '9', 'palet' => 8], ['kalibre' => '7', 'palet' => 1]],
    'palet_cinsi' => '100x120 EPAL Palet', 'kasa_cinsi' => '30x40x11 Plastik Kasa', 'palet_kasa' => 50, 'birim_agirlik' => '10,0', 'birim_fiyat' => '28,50',
    'nakliyeci' => 'Test Nakliyat', 'cekici_plaka' => '34TST001', 'dorse_plaka' => '34TST002', 'sofor_adi' => 'Test Sürücü', 'sofor_telefon' => '+90 532 000 00 01']), 1, null, 'DEPO1');
$ids[] = usip_kaydet($db, $ornek(['firma' => 'Doruk Gıda Ltd.', 'urun' => 'Limon', 'teslimat_tarihi' => $gun(0), 'kalibreler' => [['kalibre' => '4', 'palet' => 6]],
    'palet_kasa' => 60, 'birim_agirlik' => '15', 'birim_fiyat' => '']), 1, null, 'DEPO1');
$ids[] = usip_kaydet($db, $ornek(['firma' => 'Anadolu Meyve San.', 'urun' => 'Portakal', 'teslimat_tarihi' => $gun(9), 'kalibreler' => [['kalibre' => '56', 'palet' => 10], ['kalibre' => '60', 'palet' => 5]],
    'palet_kasa' => 48, 'birim_agirlik' => '15', 'birim_fiyat' => '14,2']), 1, null, 'DEPO1');
$fixture = usip_getir($db, $ids[0]);

if (!class_exists('UsExit')) { class UsExit extends RuntimeException {} }
function ust_header($h, $r = true, $c = 0): void {}
function sayfa_bas(array $perms, bool $admin, array $get = []): string
{
    global $ROOT, $PERMS, $IS_ADMIN, $UID, $DEPOLAR, $AKTIF_DEPO;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = 1; $DEPOLAR = ['DEPO1']; $AKTIF_DEPO = 'DEPO1';
    $src = (string)file_get_contents($ROOT . '/urun_siparis.php');
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'ust_header(', $src);
    $src = preg_replace('/\bexit;/', 'throw new UsExit();', $src);
    $tmp = sys_get_temp_dir() . '/usip_render_' . getmypid() . '.php'; file_put_contents($tmp, $src);
    $_GET = $get; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/urun_siparis.php';
    ob_start();
    try { include $tmp; } catch (UsExit $e) { } catch (Throwable $e) { ob_end_clean(); throw $e; } finally { @unlink($tmp); }
    $html = (string)ob_get_clean();
    $css = (string)file_get_contents($ROOT . '/assets/style.css');
    $html = preg_replace_callback('#<link rel="stylesheet" href="[^"]*assets/style\.css\?v=\d+">#', fn() => "<style>\n$css\n</style>", $html, 1, $n);
    if ($n !== 1) throw new RuntimeException('style.css bağlantısı bulunamadı');
    $fr = 'file://' . $ROOT . '/';
    return str_replace(['src="assets/', 'href="assets/', 'href="manifest.json"'], ['src="' . $fr . 'assets/', 'href="' . $fr . 'assets/', 'href="' . $fr . 'manifest.json"'], $html);
}
file_put_contents("$OUT/liste.html", sayfa_bas(['siparis.read', 'siparis.write', 'siparis.delete'], true));
file_put_contents("$OUT/liste_viewer.html", sayfa_bas(['siparis.read'], false));
file_put_contents("$OUT/liste_bos.html", sayfa_bas(['siparis.read', 'siparis.write'], false, ['q' => 'yok-boyle-bir-sey']));
file_put_contents("$OUT/manifest.json", json_encode(['ids' => $ids, 'fixture' => $fixture], JSON_UNESCAPED_UNICODE));
echo "OK $OUT\n";
