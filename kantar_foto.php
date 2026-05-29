<?php
// =========================================================
// kantar_foto.php - Kantar fişi fotoğrafını DB'den sun
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit; }

$st = db()->prepare("SELECT foto_data FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row || empty($row['foto_data'])) { http_response_code(404); exit; }

$data = $row['foto_data'];
if (!preg_match('#^data:(image/[a-zA-Z+]+);base64,(.+)$#s', $data, $m)) {
    http_response_code(404); exit;
}

$mime = $m[1];
$raw  = base64_decode($m[2], true);
if ($raw === false) { http_response_code(500); exit; }

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . strlen($raw));
echo $raw;
