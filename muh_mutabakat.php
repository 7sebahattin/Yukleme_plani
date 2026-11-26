<?php
// =========================================================
// muh_mutabakat.php — ERP-11a Cari Risk Limiti, Form BA/BS & Dönem Sonu Döviz Kur Farkı Değerleme. YALNIZ GET; yazmalar muh_kaydet.php'de.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/erp_mutabakat_core.php';
require_once __DIR__ . '/config/muhasebe_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('muh_mutabakat')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
muh_sayfa_kapisi($pdo);
erp_mutabakat_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'risk');
if (!in_array($sekme, ['risk', 'babs', 'kur_farki'], true)) $sekme = 'risk';

$veri = [];
if ($sekme === 'risk') {
    $veri['cariler'] = erp_cari_secenekleri($pdo);
    $veri['limitler'] = cari_risk_limit_listesi($pdo);
} elseif ($sekme === 'babs') {
    $yil = max(2000, min(2099, (int)($_GET['yil'] ?? date('Y'))));
    $ay  = max(1, min(12, (int)($_GET['ay'] ?? date('n'))));
    $esik = max(0.0, muh_tutar_coz($_GET['esik'] ?? 5000.0));
    $veri['babs'] = cari_babs_hesapla($pdo, $yil, $ay, $esik);
} else {
    $tarih = (string)($_GET['tarih'] ?? date('Y-m-d'));
    if (!muh_tarih_gecerli($tarih)) $tarih = date('Y-m-d');
    $veri['tarih'] = $tarih;
    $veri['onizleme'] = erp_kur_farki_hesapla($pdo, $tarih);
    $veri['gecmis'] = erp_kur_farki_listesi($pdo);
}

$yazabilir = muh_can('create') || muh_can('edit');

render_header('Risk, BA/BS & Kur Farkı');
muh_head_varlik();
render_flash();
echo muh_mutabakat_render($sekme, $veri, $yazabilir);
muh_scripts();
render_footer();
