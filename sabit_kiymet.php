<?php
// =========================================================
// sabit_kiymet.php — ERP-9 Sabit Kıymet, Amortisman (770/257) & Periyodik Bakım. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/crm_core.php';
require_once __DIR__ . '/config/sabit_kiymet_core.php';
require_once __DIR__ . '/config/erp_planlama_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('sabit_kiymet')) { forbidden(); }
erp_plan_kapi('read');
erp_plan_no_store();
$pdo = db();
sabit_kiymet_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'kartlar');
if (!in_array($sekme, ['kartlar', 'bakim'], true)) $sekme = 'kartlar';

$veri = [
    'kartlar' => sk_kart_listesi($pdo),
];
if ($sekme === 'bakim') {
    $veri['bakimlar'] = sk_bakim_listesi($pdo);
}
$yazabilir = erp_plan_can('create') || erp_plan_can('edit');

render_header('Sabit Kıymet & Bakım');
erp_plan_head_varlik();
render_flash();
echo sabit_kiymet_render($sekme, $veri, $yazabilir);
erp_plan_scripts();
render_footer();
