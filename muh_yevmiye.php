<?php
// =========================================================
// muh_yevmiye.php — Yevmiye Fişleri, Ters Fiş İptali, Oto-Mahsup & Mali Dönemler. YALNIZ GET; yazmalar muh_kaydet.php'de.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/muhasebe_core.php';
require_once __DIR__ . '/config/muhasebe_ui.php';

$auth_user = require_login();
if (!muhasebe_sayfa_can('muh_yevmiye')) { forbidden(); }
muh_kapi('read');
muh_no_store();
$pdo = db();
muh_sayfa_kapisi($pdo);

$bas = (string)($_GET['bas'] ?? date('Y-01-01'));
$bit = (string)($_GET['bit'] ?? date('Y-m-d'));
if (!muh_tarih_gecerli($bas)) $bas = date('Y-01-01');
if (!muh_tarih_gecerli($bit)) $bit = date('Y-m-d');
$tur = trim((string)($_GET['tur'] ?? ''));
$filtre = ['bas' => $bas, 'bit' => $bit, 'tur' => $tur];

$fisId = (int)($_GET['fis_id'] ?? 0);
$detayFis = $fisId > 0 ? muh_fis_getir($pdo, $fisId) : null;
$fisler = muh_fis_listesi($pdo, $filtre);
$hareketHesaplari = muh_hesap_listesi($pdo, ['aktif' => 1, 'hareket' => 1]);
$donemler = muh_donem_listesi($pdo);

$yazabilir = muh_can('create');
$silebilir = muh_can('delete');

render_header('Yevmiye Fişleri');
muh_head_varlik();
render_flash();
echo muh_yevmiye_render($fisler, $detayFis, $hareketHesaplari, $donemler, $filtre, $yazabilir, $silebilir);
muh_scripts();
render_footer();
