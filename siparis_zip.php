<?php
// =========================================================
// siparis_zip.php — Sevkiyatın evrak takımını ZIP olarak indirir (GET; siparis.read + depo)
//
// ZipStream ile akıtılır (geçici dosya yok). belge[]=ID seçili belgeler; eski=1 eski sürümleri de katar
// (eski/ klasörüne). Her belge için siparis_belge_gorunur() denetlenir; görünmeyenler sessizce atlanır.
// Girdi adları: <TÜR>-<temiz ad>, [A-Za-z0-9._-], ≤100, çakışma "-2"; üretilmiş PKL kendi adını korur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();

$pdo = db();
if (!siparis_sema_hazir($pdo)) { set_flash('error', 'Sipariş modülü henüz kurulmadı.'); header('Location: siparisler.php'); exit; }
$sid = (int)($_GET['id'] ?? 0);
$sev = siparis_sevkiyat($pdo, $sid);
if ($sev === null) { set_flash('error', 'Sevkiyat bulunamadı.'); header('Location: siparisler.php'); exit; }
if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
$geri = 'siparis_view.php?id=' . $sid . '#belgeler';

$eskiDahil = ($_GET['eski'] ?? '') === '1';
$secili = [];
if (isset($_GET['belge']) && is_array($_GET['belge'])) foreach ($_GET['belge'] as $x) if (is_scalar($x) && (int)$x > 0) $secili[(int)$x] = true;

$girdiler = [];       // [yol, ad, store]
$kullanilan = [];
$temizle = function (string $s): string {
    $s = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $s), '._-');
    return $s !== '' ? $s : 'belge';
};
foreach (siparis_belgeler($pdo, $sid, $eskiDahil) as $b) {
    if ($secili && !isset($secili[(int)$b['id']])) continue;
    if (!siparis_belge_gorunur($pdo, $b)) continue;
    $yol = siparis_dosya_yolu($b);
    if ($yol === null) continue;
    $ext = strtolower(pathinfo((string)$b['disk_adi'], PATHINFO_EXTENSION));
    $kok = $temizle(pathinfo((string)$b['orijinal_ad'], PATHINFO_FILENAME));
    $uretilmisPkl = (string)$b['tur'] === 'pkl' && (string)$b['kaynak'] === 'uretim';
    $on = $uretilmisPkl ? '' : strtoupper((string)$b['tur']) . '-';
    $ad = substr($on . $kok, 0, 90);
    $klasor = (int)$b['gecerli'] === 0 ? 'eski/' : '';
    $tam = $klasor . $ad . '.' . $ext;
    for ($n = 2; isset($kullanilan[strtolower($tam)]); $n++) $tam = $klasor . $ad . '-' . $n . '.' . $ext;
    $kullanilan[strtolower($tam)] = true;
    $girdiler[] = [$yol, $tam, $ext !== 'pdf'];
}
if (!$girdiler) { set_flash('error', 'İndirilecek belge yok.'); header('Location: ' . $geri); exit; }
if (!is_file(__DIR__ . '/vendor/autoload.php')) { set_flash('error', 'ZIP bileşeni sunucuda yok.'); header('Location: ' . $geri); exit; }
require_once __DIR__ . '/vendor/autoload.php';

audit_log_event('siparis_zip_indir', 'siparis', $sid, null, ['sevkiyat_id' => $sid, 'order_ref' => (string)$sev['order_ref'], 'adet' => count($girdiler), 'eski' => $eskiDahil ? 1 : 0]);

session_write_close();
@ini_set('zlib.output_compression', '0');
@set_time_limit(0);
if (PHP_SAPI !== 'cli') { while (ob_get_level() > 0) @ob_end_clean(); }
$zipAd = 'Evrak-' . $temizle((string)$sev['order_ref']) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipAd . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
$zip = new ZipStream\ZipStream(sendHttpHeaders: false);
foreach ($girdiler as [$yol, $tam, $store]) {
    $zip->addFileFromPath(fileName: $tam, path: $yol,
        compressionMethod: $store ? ZipStream\CompressionMethod::STORE : ZipStream\CompressionMethod::DEFLATE);
}
$zip->finish();
exit;
