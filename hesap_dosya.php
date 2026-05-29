<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

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

// DB'de varlığını doğrula
$st = db()->prepare("SELECT id FROM account_files WHERE file_name=? LIMIT 1");
$st->execute([$fn]);
if (!$st->fetch()) {
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
