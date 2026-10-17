<?php
// =========================================================
// siparis_dosya.php — Belge dosyası sunumu (GET; siparis.read + depo görünürlüğü)
//
// Dosyalar storage/siparis/ altında web'e KAPALI; yalnız buradan akar. ?dl=1 → her tür indirilir (audit).
// Başlıklar: nosniff, private+no-store, Content-Length, RFC 5987 dosya adı; PDF/görsel satır içi
// (CSP ile kısıtlı), xlsx HER ZAMAN indirme. MIME finfo ile yeniden denetlenir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
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
