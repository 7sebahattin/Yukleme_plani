<?php
// =========================================================
// onay_merkezi.php — ERP-5 Merkezi Onay Akışları (Limit/Kademe/Vekalet). YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/onay_core.php';
require_once __DIR__ . '/config/erp_yonetim_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('onay_merkezi')) { forbidden(); }
erp_yon_kapi('read');
erp_yon_no_store();
$pdo = db();
onay_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'talepler');
if (!in_array($sekme, ['talepler', 'kurallar', 'vekalet'], true)) $sekme = 'talepler';

$veri = [];
if ($sekme === 'talepler') {
    $veri['talepler'] = onay_talep_listesi($pdo);
} elseif ($sekme === 'kurallar') {
    $veri['kurallar'] = onay_kural_listesi($pdo);
} else {
    $veri['vekaletler'] = onay_vekalet_listesi($pdo);
}
$yazabilir = erp_yon_can('create') || erp_yon_can('edit');

render_header('Onay Akışları Merkezi');
erp_yon_head_varlik();
render_flash();
echo onay_merkezi_render($sekme, $veri, $yazabilir);
erp_yon_scripts();
render_footer();
