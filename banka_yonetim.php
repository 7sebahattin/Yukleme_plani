<?php
// =========================================================
// banka_yonetim.php — ERP-6 Banka Entegrasyonu, MT940/CSV Mutabakat, Kredi & POS. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/onay_core.php';
require_once __DIR__ . '/config/banka_core.php';
require_once __DIR__ . '/config/erp_yonetim_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('banka_yonetim')) { forbidden(); }
erp_yon_kapi('read');
erp_yon_no_store();
$pdo = db();
banka_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'hesaplar');
if (!in_array($sekme, ['hesaplar', 'ekstre', 'kredi', 'pos'], true)) $sekme = 'hesaplar';

$veri = [
    'hesaplar' => banka_hesap_listesi($pdo),
];
if ($sekme === 'ekstre') {
    $veri['ekstreler'] = banka_ekstre_listesi($pdo);
} elseif ($sekme === 'kredi') {
    $veri['krediler'] = banka_kredi_listesi($pdo);
} elseif ($sekme === 'pos') {
    $veri['poslar'] = banka_pos_listesi($pdo);
}
$yazabilir = erp_yon_can('create') || erp_yon_can('edit');

render_header('Banka Mutabakat, Kredi & POS');
erp_yon_head_varlik();
render_flash();
echo banka_yonetim_render($sekme, $veri, $yazabilir);
erp_yon_scripts();
render_footer();
