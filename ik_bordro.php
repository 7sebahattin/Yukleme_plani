<?php
// =========================================================
// ik_bordro.php — ERP-7 İK Özlük, 4857 Yıllık İzin & Resmi SGK/GV/DV Bordro. YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/onay_core.php';
require_once __DIR__ . '/config/ik_bordro_core.php';
require_once __DIR__ . '/config/erp_yonetim_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('ik_bordro')) { forbidden(); }
erp_yon_kapi('read');
erp_yon_no_store();
$pdo = db();
ik_bordro_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'ozluk');
if (!in_array($sekme, ['ozluk', 'izin', 'bordro'], true)) $sekme = 'ozluk';

$veri = [
    'ozlukler' => ik_ozluk_listesi($pdo),
];
if ($sekme === 'izin') {
    $veri['izinler'] = ik_izin_listesi($pdo);
} elseif ($sekme === 'bordro') {
    $donemler = ik_bordro_donem_listesi($pdo);
    $veri['donemler'] = $donemler;
    $secId = (int)($_GET['donem_id'] ?? 0);
    if ($secId <= 0 && $donemler !== []) {
        $secId = (int)$donemler[0]['id'];
    }
    $veri['sec_satirlar'] = $secId > 0 ? ik_bordro_satir_listesi($pdo, $secId) : [];
}
$yazabilir = erp_yon_can('create') || erp_yon_can('edit');

render_header('İK Özlük, İzin & Resmi Bordro');
erp_yon_head_varlik();
render_flash();
echo ik_bordro_render($sekme, $veri, $yazabilir);
erp_yon_scripts();
render_footer();
