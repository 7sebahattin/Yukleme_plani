<?php
// =========================================================
// finans_planlama.php — ERP-10 Bütçe & Sapma, Vade Yaşlandırma (30/60/90/120+) & Nakit Akış. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/crm_core.php';
require_once __DIR__ . '/config/finans_plan_core.php';
require_once __DIR__ . '/config/erp_planlama_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('finans_planlama')) { forbidden(); }
erp_plan_kapi('read');
erp_plan_no_store();
$pdo = db();
finans_plan_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'aging');
if (!in_array($sekme, ['aging', 'nakit', 'butce'], true)) $sekme = 'aging';
$yil = (int)($_GET['yil'] ?? date('Y'));
if ($yil < 2000 || $yil > 2099) $yil = (int)date('Y');

$veri = [];
if ($sekme === 'aging') {
    $veri['aging'] = fp_vade_yaslandirma($pdo);
} elseif ($sekme === 'nakit') {
    $veri['projeksiyon'] = fp_nakit_akis_projeksiyon($pdo, date('Y-m-d'), 90);
} else {
    $veri['butceler'] = fp_butce_raporu($pdo, $yil);
}
$yazabilir = erp_plan_can('create') || erp_plan_can('edit');

render_header('Bütçe, Aging & Nakit Akış');
erp_plan_head_varlik();
render_flash();
echo finans_planlama_render($sekme, $veri, $yazabilir);
erp_plan_scripts();
render_footer();
