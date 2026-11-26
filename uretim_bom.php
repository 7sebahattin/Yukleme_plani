<?php
// =========================================================
// uretim_bom.php — ERP-4 Üretim Reçetesi (BOM), İş Emirleri, Fire/Randıman, Lot-to-Lot & MRP. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/wms_stok_core.php';
require_once __DIR__ . '/config/uretim_bom_core.php';
require_once __DIR__ . '/config/erp_op_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('uretim_bom')) { forbidden(); }
erp_op_kapi('read');
erp_op_no_store();
$pdo = db();
uretim_bom_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'bom');
if (!in_array($sekme, ['bom', 'emirler', 'iz', 'mrp'], true)) $sekme = 'bom';

$veri = ['stoklar' => wms_stok_hazir($pdo) ? wms_stok_listesi($pdo) : []];
if ($sekme === 'bom') {
    $veri['bomlar'] = uretim_bom_listesi($pdo);
} elseif ($sekme === 'emirler') {
    $veri['bomlar']  = uretim_bom_listesi($pdo);
    $veri['emirler'] = uretim_is_emri_listesi($pdo);
} elseif ($sekme === 'iz') {
    $lotAra = trim((string)($_GET['lot'] ?? ''));
    $veri['lot_ara'] = $lotAra;
    $veri['izler']   = uretim_lot_iz_listesi($pdo, $lotAra);
} else {
    $veri['mrp'] = uretim_mrp_hesapla($pdo);
}
$yazabilir = erp_op_can('create') || erp_op_can('edit');

render_header('Üretim BOM, İş Emirleri & MRP');
erp_op_head_varlik();
render_flash();
echo uretim_bom_render($sekme, $veri, $yazabilir);
erp_op_scripts();
render_footer();
