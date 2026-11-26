<?php
// =========================================================
// edonusum_belgeler.php — e-Dönüşüm (e-Fatura / e-Arşiv / e-İrsaliye / e-Müstahsil · UBL-TR 1.2). YALNIZ GET; yazmalar muh_kaydet.php'de.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/edonusum_core.php';
require_once __DIR__ . '/config/muhasebe_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('edonusum_belgeler')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
edonusum_sayfa_kapisi($pdo);

$sekme = (string)($_GET['sekme'] ?? 'giden');
if (!in_array($sekme, ['giden', 'gelen', 'ayarlar'], true)) $sekme = 'giden';
$filtre = [
    'tur'   => trim((string)($_GET['tur'] ?? '')),
    'durum' => trim((string)($_GET['durum'] ?? '')),
];
$zarflar = edonusum_zarf_listesi($pdo, $filtre);
$gelenler = edonusum_gelen_listesi($pdo);
$faturalar = edonusum_son_faturalar($pdo);
$ayar = edonusum_ayar_getir($pdo);
$aktifSaglayici = edonusum_provider(null, $pdo)->ad();
$yazabilir = muh_can('create') || muh_can('edit');
$adminMi = is_admin();

$mukellefSonuc = null;
$sorguVkn = preg_replace('/\D/', '', (string)($_GET['sorgu_vkn'] ?? '')) ?? '';
$sorguTur = trim((string)($_GET['sorgu_tur'] ?? 'efatura'));
if ($sekme === 'ayarlar' && $sorguVkn !== '') {
    $mukellefSonuc = edonusum_mukellef_sorgula($pdo, $sorguVkn, $sorguTur) + ['vkn' => $sorguVkn, 'tur' => $sorguTur];
}

render_header('e-Dönüşüm Belgeleri');
muh_head_varlik();
render_flash();
echo edonusum_render($sekme, $zarflar, $gelenler, $faturalar, $filtre, $yazabilir, $ayar, $aktifSaglayici, $mukellefSonuc, $adminMi);
muh_scripts();
render_footer();
