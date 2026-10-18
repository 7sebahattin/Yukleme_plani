<?php
// =========================================================
// scripts/siparis_ui_render.php — Siparişler sayfalarını TARAYICI testi için HTML dosyalarına basar.
//   SIPARIS_UI_OUT=/tmp/sip-ui php scripts/siparis_ui_render.php
//   SIPARIS_UI_OUT=/tmp/sip-ui node scripts/siparis_ui_smoke.js
// Bellek içi SQLite + stub auth + SENTETİK fixture'lar (kişisel veri yok); canlı DB'ye ve storage/siparis'e dokunmaz.
// Üretim style.css + siparis.css satır içi, JS dosyaları file:// ile yüklenir (app.js + siparis.js gerçekten çalışır).
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';

/** Ortak tohum: tam akış (PO → LL → çatışma → PKL → PC → mail) + ikinci depo + beyan. @return array<string,int> */
function siparis_ui_tohum(PDO $db): array
{
    siparis_test_diger_tablolar($db);
    siparis_test_sema_kur($db);
    $db->exec("CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, type TEXT, is_active INT DEFAULT 1, color TEXT)");
    $db->exec("INSERT INTO material_definitions (name, type, color) VALUES ('DEPO1', 'depo', '#16a34a'), ('DEPO2', 'depo', '#d97706')");
    $id = [];
    $r = siparis_test_isle($db, siparis_test_fixture('po_iki_sevkiyat'), 'po1');
    $id['po1'] = (int)$r['kayit']['belge_id'];
    $r = siparis_test_isle($db, siparis_test_fixture('ll_plakali'), 'll1');
    $id['ll1'] = (int)$r['kayit']['belge_id'];
    $s1 = siparis_test_sev($db, 'TC900001.10'); $id['s1'] = (int)$s1['id'];
    $id['s2'] = (int)siparis_test_sev($db, 'TC900002.10')['id'];
    siparis_alan_elle($db, 'sevkiyat', $id['s1'], 'plakalar', '34 tst 777 / 34TST778', 2);
    $yeni = siparis_test_fixture('ll_plakali');
    $yeni['header']['plates'] = ['34TST003', '34TST004']; $yeni['meta']['created'] = '2026-12-03 07:00:00';
    siparis_test_isle($db, $yeni, 'll3');                                  // elle kilitli plakaya çatışma
    $db->exec("INSERT INTO customs_declarations (status, party_no, vehicle_plate, tractor_plate, net_kg, pallet_count, product_name, created_at)
        VALUES ('beyan_acildi', 'TC900001.10-A', '34TST778', '34TST777', 14400, 20, 'NAR', '2026-12-02 09:00:00'), ('taslak', 'DIGER-5', '06XYZ99', '06XYZ98', 800, 3, 'NAR', '2026-12-02 10:00:00')");
    // gerçek sipariş akışının ikinci yarısı
    $r = siparis_test_isle($db, siparis_test_fixture('po_uc_kalibre'), 'po3'); $id['po3'] = (int)$r['kayit']['belge_id'];
    siparis_test_isle($db, siparis_test_fixture('ll_plakasiz'), 'll2');
    $r = siparis_test_isle($db, siparis_test_fixture('pc_bolum_farkli'), 'pc1'); $id['pc1'] = (int)$r['kayit']['belge_id'];
    $id['s11'] = (int)siparis_test_sev($db, 'TC900011.10')['id'];
    $id['s12'] = (int)siparis_test_sev($db, 'TC900012.10')['id'];
    $id['s99'] = (int)siparis_test_sev($db, 'TC900099.10')['id'];
    siparis_alan_elle($db, 'sevkiyat', $id['s11'], 'plakalar', '34 TST 111 / 34 TST 112', 1);
    siparis_pkl_uret($db, $id['s11'], 1, true);                             // kısmi PKL (M/N boş)
    siparis_durum_degistir($db, $id['s11'], 'mail_gonderildi', 1, '', true);    // kapanan
    siparis_durum_degistir($db, $id['s12'], 'iptal', 1, 'müşteri iptal etti');
    siparis_pkl_uret($db, $id['s1'], 1, true);                              // PKL hazır (açık, çatışmalı)
    // ikinci depo: başka depoda görünmeyecek sevkiyat
    $d2 = siparis_test_fixture('po_iki_sevkiyat');
    $d2['loadings'][0]['order_ref'] = 'TC900031.10'; $d2['loadings'][1]['order_ref'] = 'TC900032.10';
    $d2['loadings'][0]['tour'] = '9000000031'; $d2['loadings'][1]['tour'] = '9000000032'; $d2['meta']['created'] = '2026-12-02 10:00:00';
    $r = siparis_test_isle($db, $d2, 'po_depo2', 1, null, 'DEPO2');
    $id['po_d2'] = (int)$r['kayit']['belge_id'];
    $id['s31'] = (int)siparis_test_sev($db, 'TC900031.10')['id'];
    $pk = $db->query("SELECT dosya_belge_id FROM siparis_pkl WHERE sevkiyat_id = " . $id['s1'])->fetchColumn();
    $id['pkl1'] = (int)$pk;
    return $id;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) return;     // smoke require ederse yalnız fonksiyon tanımı

$OUT = getenv('SIPARIS_UI_OUT') ?: (sys_get_temp_dir() . '/sip-ui-test');
@mkdir($OUT, 0777, true);
$OUT = realpath($OUT);
ini_set('session.save_path', sys_get_temp_dir());
ini_set('session.use_cookies', '0');
session_start();
$db = db();
$ID = siparis_ui_tohum($db);
// liste görünümü (v372): aktif beyan bağı (parti no) + 5 kalemli sevkiyat ("+N daha")
$db->exec("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, durum, created_by, created_at) VALUES (" . (int)$ID['s2'] . ", 2, 'elle', 'aktif', 1, '2026-12-04 10:00:00')");
// s1: birden çok bağlı beyan → parti no'lar alt alta (görsel doğrulama)
$db->exec("INSERT INTO customs_declarations (status, party_no, created_at) VALUES ('taslak', '61/225', '2026-12-02 12:00:00'), ('taslak', '61/226', '2026-12-02 12:00:00'), ('taslak', '61/227', '2026-12-02 12:00:00')");
$__pk = (int)$db->lastInsertId();
foreach ([$__pk - 2, $__pk - 1, $__pk] as $__b) $db->exec("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, durum, created_by, created_at) VALUES (" . (int)$ID['s1'] . ", " . (int)$__b . ", 'elle', 'aktif', 1, '2026-12-04 10:00:00')");
foreach ([16, 18] as $i => $kal) {
    $db->prepare("INSERT INTO siparis_kalemler (sevkiyat_id, ian, kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli) VALUES (?, '', ?, ?, 'Testfruit', ?, 'TESTVAR', '3,5 KG', 200, 2, 2, 400)")
        ->execute([$ID['s12'], (string)$kal, 3 + $i, "Testfruit $kal PC LOSE (TR)"]);
}
$cssBase = file_get_contents($ROOT . '/assets/style.css');
$cssSip = file_get_contents($ROOT . '/assets/siparis.css');
$fileRoot = 'file://' . $ROOT . '/';

if (!class_exists('SipExit')) { class SipExit extends RuntimeException {} }
if (!function_exists('sipt_header')) { function sipt_header($h, $r = true, $c = 0): void {} function sipt_code($c = null) { return 200; } }

function basla_sayfa(string $dosya, array $get, string $ad, array $perms = [], bool $yonetici = true, int $uid = 1): string {
    global $ROOT, $cssBase, $cssSip, $fileRoot, $PERMS, $IS_ADMIN, $UID, $DEPOLAR;
    $PERMS = $perms; $IS_ADMIN = $yonetici; $UID = $uid;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_ui_render_' . getmypid() . '.php';
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    ob_start();
    try { include $tmp; } catch (SipExit $e) { /* sayfa kendini bitirdi */ } catch (Throwable $e) { ob_end_clean(); throw $e; } finally { @unlink($tmp); }
    $html = (string)ob_get_clean();
    $html = preg_replace_callback('#<link rel="stylesheet" href="[^"]*assets/style\.css\?v=\d+">#', fn() => "<style data-kaynak=\"style.css\">\n$cssBase\n</style>", $html, 1, $n1);
    $html = preg_replace_callback('#<link rel="stylesheet" href="assets/siparis\.css\?v=\d+">#', fn() => "<style data-kaynak=\"siparis.css\">\n$cssSip\n</style>", $html, 1, $n2);
    if ($n1 !== 1 || $n2 !== 1) throw new RuntimeException("CSS bağlantısı bulunamadı ($ad): style=$n1 siparis=$n2");
    return str_replace(['src="assets/', 'href="assets/', '"/assets/', '"../assets/', 'href="manifest.json"'],
        ['src="' . $fileRoot . 'assets/', 'href="' . $fileRoot . 'assets/', '"' . $fileRoot . 'assets/', '"' . $fileRoot . 'assets/', 'href="' . $fileRoot . 'manifest.json"'], $html);
}

$yaz = ['siparis.read', 'siparis.write', 'siparis.match', 'beyan.read'];
$sayfalar = [
    'liste'          => ['siparisler.php', []],
    'liste_filtre'   => ['siparisler.php', ['durum' => 'yola_cikti', 'eksik' => 'pkl', 'catisma' => '1']],
    'liste_bos'      => ['siparisler.php', ['q' => 'yok-boyle-bir-sevkiyat']],
    'liste_viewer'   => ['siparisler.php', [], ['siparis.read'], false],
    'detay'          => ['siparis_view.php', ['id' => $ID['s1']]],                              // çatışma + PKL + beyan önerisi
    'detay_kapali'   => ['siparis_view.php', ['id' => $ID['s11']]],                             // mail gönderildi, 3 kaynak, bölüm farkı
    'detay_stub'     => ['siparis_view.php', ['id' => $ID['s99']]],                             // PC ile açılan taslak
    'detay_iptal'    => ['siparis_view.php', ['id' => $ID['s12']]],
    'detay_viewer'   => ['siparis_view.php', ['id' => $ID['s1']], ['siparis.read'], false],
    'detay_operator' => ['siparis_view.php', ['id' => $ID['s2']], $yaz, false],
    'pkl'            => ['siparis_pkl.php', ['id' => $ID['s1']]],
    'pkl_viewer'     => ['siparis_pkl.php', ['id' => $ID['s1']], ['siparis.read'], false],
    'pkl_taslak'     => ['siparis_pkl.php', ['id' => $ID['s2']]],                               // ayrılmamış palet aralığı: Palet ID düzenlenebilir (v372)
    'belge_pdf'      => ['siparis_belge.php', ['id' => $ID['po1']]],
    'belge_xlsx'     => ['siparis_belge.php', ['id' => $ID['pkl1']]],
    'ayarlar'        => ['siparis_ayarlar.php', []],
];
$manifest = ['sayfalar' => array_keys($sayfalar), 'id' => $ID];
foreach ($sayfalar as $ad => $s) {
    $html = basla_sayfa($s[0], $s[1], $ad, $s[2] ?? [], $s[3] ?? true);
    file_put_contents("$OUT/$ad.html", $html);
}
file_put_contents("$OUT/manifest.json", json_encode($manifest));
echo count($sayfalar) . " sayfa yazıldı → $OUT\n";
