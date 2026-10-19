<?php
// =========================================================
// siparis_dosya.php — Belge dosyası sunumu (GET; siparis.read + depo görünürlüğü)
//
// Dosyalar storage/siparis/ altında web'e KAPALI; yalnız buradan akar. ?dl=1 → her tür indirilir (audit).
// ?eml=1&sid=N (v383) → bu belge o sevkiyatın PKL'iyse PKL ekli, gönderilmemiş mail taslağı (.eml, X-Unsent) indirilir.
// Başlıklar: nosniff, private+no-store, Content-Length, RFC 5987 dosya adı; PDF/görsel satır içi
// (CSP ile kısıtlı), xlsx HER ZAMAN indirme. MIME finfo ile yeniden denetlenir.
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

$pdo = db();
if (!siparis_sema_hazir($pdo)) $metinHata(503, 'Sipariş modülü henüz kurulmadı.');
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
$st->execute([$id]);
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b) $metinHata(404, 'Belge bulunamadı.');
if (!siparis_belge_gorunur($pdo, $b)) forbidden('Bu belge aktif depoda görünmüyor.');
$yol = siparis_dosya_yolu($b);
if ($yol === null) $metinHata(404, 'Belge dosyası bulunamadı.');

if (($_GET['eml'] ?? '') === '1') {
    // Ekli mail taslağı: belge, istenen sevkiyatın GÜNCEL PKL dosyası olmalı (başka belgeyle taslak kurulamaz)
    $sev = siparis_sevkiyat($pdo, (int)($_GET['sid'] ?? 0));
    if ($sev === null) $metinHata(404, 'Sevkiyat bulunamadı.');
    if (!siparis_gorunur($sev)) forbidden('Bu sevkiyat aktif depoda görünmüyor.');
    $pk = $pdo->prepare('SELECT dosya_belge_id, dosya_adi FROM siparis_pkl WHERE sevkiyat_id = ?');
    $pk->execute([(int)$sev['id']]);
    $pkl = $pk->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pkl || (int)$pkl['dosya_belge_id'] !== (int)$b['id']) $metinHata(404, 'Bu belge sevkiyatın güncel PKL dosyası değil.');
    $pklAd = (string)($pkl['dosya_adi'] ?: $b['orijinal_ad']);
    $taslak = siparis_pkl_mail_taslak(siparis_po_pkl_epostalar($pdo, $sev), $pklAd, (string)$sev['order_ref'],
        (string)($auth_user['display_name'] ?: $auth_user['username']));
    if (!$taslak['kime']) $metinHata(409, "PO'da Packing List e-posta adresi yok; taslak kurulamadı.");
    $icerik = (string)file_get_contents($yol);
    $eml = siparis_pkl_eml($taslak, $pklAd, $icerik);
    audit_log_event('siparis_pkl_eml', 'siparis', (int)$sev['id'], null, ['belge_id' => (int)$b['id'], 'alici' => count($taslak['kime']) + count($taslak['bilgi'])]);
    $emlAd = $taslak['konu'] . '.eml';
    $emlAscii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $emlAd), '_') ?: 'packing-list.eml';
    session_write_close();
    header('Content-Type: message/rfc822');
    header('Content-Disposition: attachment; filename="' . $emlAscii . '"; filename*=UTF-8\'\'' . rawurlencode($emlAd));
    header('Content-Length: ' . strlen($eml));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    echo $eml;
    exit;
}

$ext = strtolower(pathinfo((string)$b['disk_adi'], PATHINFO_EXTENSION));
$cins = siparis_dosya_uzantilari()[$ext] ?? 'bilinmiyor';
$mime = class_exists('finfo') ? (string)(new finfo(FILEINFO_MIME_TYPE))->file($yol) : '';
$beklenen = $cins === 'resim' ? ['image/jpeg', 'image/png', 'image/webp'] : ($cins === 'pdf' ? ['application/pdf'] : ($cins === 'xlsx' ? siparis_dosya_mimeleri()['xlsx'] : []));
$guvenli = $cins !== 'bilinmiyor' && in_array($mime, $beklenen, true);
if (!$guvenli) { $mime = 'application/octet-stream'; }
elseif ($cins === 'xlsx') { $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; }

$indir = (($_GET['dl'] ?? '') === '1') || !$guvenli || $cins === 'xlsx';
if (($_GET['dl'] ?? '') === '1') {
    audit_log_event('siparis_dosya_indir', 'siparis', (int)$b['id'], null, ['belge_id' => (int)$b['id'], 'tur' => (string)$b['tur']]);
}
$ad = (string)$b['orijinal_ad'];
$ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $ad), '_') ?: 'belge.' . $ext;

session_write_close();   // büyük dosya akarken oturum kilidini tutma
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($indir ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($ad));
header('Content-Length: ' . (int)filesize($yol));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($indir) header("Content-Security-Policy: sandbox; default-src 'none'");
elseif ($cins === 'pdf') header("Content-Security-Policy: frame-ancestors 'self'");   // tarayıcı PDF görüntüleyicileri sandbox/default-src 'none' altında boş kalabilir
else header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'");
readfile($yol);
exit;
