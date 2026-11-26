<?php
// =========================================================
// satinalma_yonetim.php — ERP-3 Satın Alma Talebi, Teklif Karşılaştırma, Sipariş, Mal Kabul & 3-Way Match. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/wms_stok_core.php';
require_once __DIR__ . '/config/satinalma_core.php';
require_once __DIR__ . '/config/erp_op_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('satinalma_yonetim')) { forbidden(); }
erp_op_kapi('read');
erp_op_no_store();
$pdo = db();
satinalma_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'talepler');
if (!in_array($sekme, ['talepler', 'siparisler', 'mal_kabul', 'eslestirme'], true)) $sekme = 'talepler';

$veri = [];
if ($sekme === 'talepler') {
    $veri['talepler'] = sa_talep_listesi($pdo);
} elseif ($sekme === 'siparisler') {
    $veri['siparisler'] = sa_siparis_listesi($pdo);
} elseif ($sekme === 'mal_kabul') {
    $veri['kabuller'] = sa_mal_kabul_listesi($pdo);
} else {
    $veri['kabuller']      = sa_mal_kabul_listesi($pdo);
    $veri['eslestirmeler'] = sa_eslestirme_listesi($pdo);
    $veri['performans']    = sa_tedarikci_performans($pdo);
}
$yazabilir = erp_op_can('create') || erp_op_can('edit');

render_header('Satın Alma & 3-Way Match');
erp_op_head_varlik();
render_flash();
echo satinalma_render($sekme, $veri, $yazabilir);
erp_op_scripts();
render_footer();
