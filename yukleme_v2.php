<?php
// =========================================================
// yukleme_v2.php — Yeni Araç Yükleme Fişleri (Temiz Sıfırdan Modül · Palet/Dara/Net + Kilit/Revizyon + WMS). YALNIZ GET.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/wms_stok_core.php';
require_once __DIR__ . '/config/yukleme_v2_core.php';
require_once __DIR__ . '/config/erp_op_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('yukleme_v2')) { forbidden(); }
erp_op_kapi('read');
erp_op_no_store();
$pdo = db();
yukleme_v2_sayfa_kapisi($pdo);

$id = (int)($_GET['id'] ?? 0);
$detay = $id > 0 ? yukleme_v2_fis_getir($pdo, $id) : null;
$fisler = yukleme_v2_fis_listesi($pdo);
$stoklar = wms_stok_hazir($pdo) ? wms_stok_listesi($pdo, 'urun') : [];
$yazabilir = erp_op_can('create') || erp_op_can('edit');

render_header('Yeni Araç Yükleme Fişleri (v2)');
erp_op_head_varlik();
render_flash();
echo yukleme_v2_render($fisler, $detay, $stoklar, $yazabilir);
erp_op_scripts();
render_footer();
