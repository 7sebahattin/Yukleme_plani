<?php
// =========================================================
// crm_yonetim.php — ERP-8 CRM Pipeline, Fiyat Listeleri, Teklif→Sipariş & Kalite Claim. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/crm_core.php';
require_once __DIR__ . '/config/erp_planlama_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('crm_yonetim')) { forbidden(); }
erp_plan_kapi('read');
erp_plan_no_store();
$pdo = db();
crm_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'pipeline');
if (!in_array($sekme, ['pipeline', 'fiyat', 'teklif', 'claim'], true)) $sekme = 'pipeline';

$veri = [];
if ($sekme === 'pipeline') {
    $veri['firsatlar'] = crm_firsat_listesi($pdo);
} elseif ($sekme === 'fiyat') {
    $veri['fiyat_listeleri'] = crm_fiyat_listesi_tumu($pdo);
} elseif ($sekme === 'teklif') {
    $veri['fiyat_listeleri'] = crm_fiyat_listesi_tumu($pdo);
    $veri['teklifler'] = crm_teklif_listesi($pdo);
} else {
    $veri['claimler'] = crm_claim_listesi($pdo);
}
$yazabilir = erp_plan_can('create') || erp_plan_can('edit');

render_header('CRM & Fiyat Listeleri');
erp_plan_head_varlik();
render_flash();
echo crm_yonetim_render($sekme, $veri, $yazabilir);
erp_plan_scripts();
render_footer();
