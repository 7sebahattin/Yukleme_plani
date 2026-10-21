<?php
// =========================================================
// siparis_eml.php — Evrak takımı mail taslağı (.eml, X-Unsent; EK YOK) indirir (GET; siparis.read + depo) — v411
//
// ?sid=N. Yalnız "Mail gönderildi" / "Evrak takımı" durumundaki sevkiyat. Kime = Sipariş ayarları › Evrak takımı mail
// alıcıları (hks_kv) + PO'daki packinglist… olmayan adres; konu = PKL dosya adı uzantısız. Evrak takımını kullanıcı ekler.
// Ad TEK alt çizgili ek (sw.js bypass regex'i kapsar). Audit yalnız alıcı SAYISI.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_pkl.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();

$metinHata = function (int $kod, string $mesaj): void {
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $mesaj;
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') $metinHata(405, 'Yalnız GET.');
$pdo = db();
if (!siparis_sema_hazir($pdo)) $metinHata(503, 'Sipariş modülü henüz kurulmadı.');
$sev = siparis_sevkiyat($pdo, (int)($_GET['sid'] ?? 0));
if ($sev === null) $metinHata(404, 'Sevkiyat bulunamadı.');
if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
if (!in_array((string)$sev['durum'], ['mail_gonderildi', 'evrak_gonderildi'], true)) $metinHata(409, 'Evrak takımı taslağı yalnız mail gönderildikten sonra kurulur.');

$pk = $pdo->prepare('SELECT dosya_adi FROM siparis_pkl WHERE sevkiyat_id = ?');
$pk->execute([(int)$sev['id']]);
$pklAd = (string)($pk->fetchColumn() ?: '');
$taslak = siparis_evrak_mail_taslak(siparis_evrak_mail_ayar_oku($pdo)['kime'], siparis_po_pkl_epostalar($pdo, $sev), $pklAd, (string)$sev['order_ref'],
    (string)($auth_user['display_name'] ?: $auth_user['username']));
$eml = siparis_evrak_eml($taslak);
audit_log_event('siparis_evrak_eml', 'siparis', (int)$sev['id'], null, ['alici' => count($taslak['kime'])]);
$emlAd = ($taslak['konu'] !== '' ? $taslak['konu'] : 'evrak-takimi') . '.eml';
$emlAscii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $emlAd), '_') ?: 'evrak-takimi.eml';
session_write_close();
header('Content-Type: message/rfc822');
header('Content-Disposition: attachment; filename="' . $emlAscii . '"; filename*=UTF-8\'\'' . rawurlencode($emlAd));
header('Content-Length: ' . strlen($eml));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: sandbox; default-src 'none'");
echo $eml;
exit;
