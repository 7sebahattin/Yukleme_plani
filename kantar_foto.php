<?php
// =========================================================
// kantar_foto.php - Mal kabul fişi fotoğrafını DB'den sun
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.read');

$id = (int)($_GET['id'] ?? 0);
role_require_kantar_scope($id);
if ($id <= 0) { http_response_code(404); exit; }

$st = db()->prepare("SELECT foto_data FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row || empty($row['foto_data'])) { http_response_code(404); exit; }

$parsed = kantar_foto_dogrula((string)$row['foto_data']);
if ($parsed === null) { http_response_code(415); exit; }

header('Content-Type: ' . $parsed['mime']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'");
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . strlen($parsed['raw']));
echo $parsed['raw'];
