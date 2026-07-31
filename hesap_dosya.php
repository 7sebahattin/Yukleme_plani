<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');

$fn = trim($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp|pdf)$/i', $fn)) {
    http_response_code(400);
    exit('Geçersiz dosya adı');
}

$path = HESAP_UPLOAD_DIR . $fn;
if (!file_exists($path)) {
    http_response_code(404);
    exit('Dosya bulunamadı');
}

// B5: dosyanın DB'de olması yetmez — bağlı olduğu kaydın bu kullanıcıya
// görünür olması da gerekir (sahiplik + depo). Aksi hâlde fiş fotoğrafları
// dosya adını bilen her oturumdan okunabiliyordu.
$fx = hesap_file_with_tx($fn);
if ($fx === null || !hesap_row_visible($fx['tx'])) {
    http_response_code(403);
    exit('Erişim reddedildi');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path);
if (!in_array($mime, HESAP_ALLOWED_MIME, true)) {
    http_response_code(403);
    exit('Geçersiz içerik');
}

$download = isset($_GET['download']);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
if ($download) {
    header('Content-Disposition: attachment; filename="' . $fn . '"');
} else {
    header('Content-Disposition: inline; filename="' . $fn . '"');
}
header('Cache-Control: private, max-age=3600');
readfile($path);
