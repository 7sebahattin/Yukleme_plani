<?php
// =========================================================
// wms_stok_yonetim.php — ERP-11b Ürün & Malzeme Stok Ayrımı (Seçenek A) + WMS (Lot/SKT/Raf/FIFO). YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/wms_stok_core.php';
require_once __DIR__ . '/config/erp_op_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('wms_stok_yonetim')) { forbidden(); }
erp_op_kapi('read');
erp_op_no_store();
$pdo = db();
wms_stok_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'urun');
if (!in_array($sekme, ['urun', 'malzeme', 'lotlar', 'raflar', 'alarmlar'], true)) $sekme = 'urun';
$ara = trim((string)($_GET['ara'] ?? ''));

$veri = [
    'tum_stoklar' => wms_stok_listesi($pdo),
    'raflar'      => wms_raf_listesi($pdo),
    'alarmlar'    => wms_alarmlar($pdo),
];
if ($sekme === 'urun' || $sekme === 'malzeme') {
    $veri['liste'] = wms_stok_listesi($pdo, $sekme, $ara);
} elseif ($sekme === 'lotlar') {
    $veri['lotlar'] = wms_lot_listesi($pdo);
}
$yazabilir = erp_op_can('create') || erp_op_can('edit');

render_header('Ürün & Malzeme Stok / WMS');
erp_op_head_varlik();
render_flash();
echo wms_stok_render($sekme, $veri, $yazabilir);
erp_op_scripts();
render_footer();
