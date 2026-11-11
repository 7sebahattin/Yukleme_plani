<?php
// =========================================================
// frugro_zip.php?id= — Frugro "Evrak takımı (ZIP)": PKL PDF + (üretildiyse) Invoice PDF, her istekte TAZE üretilip ZIP'lenir.
//
// GET, siparis.read + aktif depo (başka depo / olmayan / silinmiş → AYNI 404), no-store, nosniff. Yan etkisiz (durum/olay yazmaz).
// KALICI dosya YOK: ZipArchive geçici dosyası sys_get_temp_dir()'de açılır ve finally'de silinir. Gelen Order Log xlsx SAKLANMADIĞI için
// ZIP'e girmez; Excel PKL de girmez (evrak takımı = PDF'ler). PKL palet satırı yoksa 409. Audit `frugro_zip` yalnız dosya SAYISI.
// Ad TEK alt çizgili (sw.js bypass regex'i frugro(_[a-z]+)?.php kapsar).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_detay.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
header('X-Content-Type-Options: nosniff');

$hata = static function (int $kod, string $m): void {
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    echo $m;
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); $hata(405, 'Geçersiz istek.'); }
if (!class_exists(ZipArchive::class)) $hata(500, 'ZIP desteği (php-zip) sunucuda yok.');
$pdo = db();
if (!frg_hazir($pdo)) $hata(409, 'Frugro Siparişler henüz kurulmadı.');
$id = (int)($_GET['id'] ?? 0);
$s = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) $hata(404, 'Sipariş bulunamadı.');

$pkl = frgd_pkl_pdf($s, $pdo);
if ($pkl === null) $hata(409, 'Evrak takımı için önce Packing List palet satırlarını tamamlayın.');
$dosyalar = [frgp_dosya_adi($s, 'pdf') => $pkl];
$inv = frgd_invoice_pdf($pdo, $id);
if ($inv !== null) $dosyalar[$inv['dosya_adi']] = $inv['pdf'];

$tmp = tempnam(sys_get_temp_dir(), 'frgzip');
if ($tmp === false) $hata(500, 'Geçici dosya açılamadı.');
$icerik = '';
try {
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('zip açılamadı');
    foreach ($dosyalar as $ad => $veri) $z->addFromString($ad, $veri);
    $z->close();
    $icerik = (string)file_get_contents($tmp);
} catch (Throwable $e) {
    error_log('[frugro_zip] ' . get_class($e));
} finally {
    @unlink($tmp);
}
if ($icerik === '') $hata(500, 'ZIP oluşturulamadı.');
audit_log_event('frugro_zip', 'frugro_siparis', $id, null, ['dosya' => count($dosyalar)]);

$po = trim((string)preg_replace('/[^A-Za-z0-9.-]+/', '', (string)$s['po_no']), '.-');
while (ob_get_level() > 0) { ob_end_clean(); }
session_write_close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="Frugro-' . ($po !== '' ? $po : 'PO') . '-Evrak.zip"');
header('Content-Length: ' . strlen($icerik));
echo $icerik;
exit;
