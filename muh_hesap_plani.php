<?php
// =========================================================
// muh_hesap_plani.php — Tek Düzen Hesap Planı (TDHP · 100–799). YALNIZ GET; yazmalar muh_kaydet.php'de.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/muhasebe_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('muh_hesap_plani')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
muh_sayfa_kapisi($pdo);

$filtre = [
    'sinif' => isset($_GET['sinif']) && $_GET['sinif'] !== '' ? (int)$_GET['sinif'] : null,
    'ara'   => trim((string)($_GET['ara'] ?? '')),
];
$hesaplar = muh_hesap_listesi($pdo, $filtre);
$yazabilir = muh_can('create') || muh_can('edit');

render_header('TDHP Hesap Planı');
muh_head_varlik();
render_flash();
echo muh_hesap_plani_render($hesaplar, $filtre, $yazabilir);
muh_scripts();
render_footer();
