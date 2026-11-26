<?php
// =========================================================
// health.php — Hafif sistem sağlık kontrolü (B-32)
// Erişim: config/local.php içindeki HEALTH_CHECK_TOKEN (Bearer veya ?token=)
//         ya da oturumlu yönetici (is_admin()).
// Hassas bilgi (DB şifresi, dosya yolu, yığın izi) ASLA döndürülmez.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$authorized = false;
$configured = (defined('HEALTH_CHECK_TOKEN') && is_string(HEALTH_CHECK_TOKEN) && strlen(HEALTH_CHECK_TOKEN) >= 16)
    ? HEALTH_CHECK_TOKEN
    : '';

if ($configured !== '') {
    $provided = '';
    $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $hdr, $m)) {
        $provided = $m[1];
    } elseif (isset($_GET['token']) && is_string($_GET['token'])) {
        $provided = $_GET['token'];
    }
    if ($provided !== '' && hash_equals($configured, $provided)) {
        $authorized = true;
    }
}

if (!$authorized) {
    $u = current_user();
    if ($u !== null && is_admin()) {
        $authorized = true;
    }
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Yetkisiz erişim.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dbOk = false;
try {
    $dbOk = ((int)db()->query('SELECT 1')->fetchColumn() === 1);
} catch (Throwable $e) {
    error_log('[health] ' . $e->getMessage());
}

http_response_code($dbOk ? 200 : 503);
echo json_encode([
    'ok'      => $dbOk,
    'version' => defined('APP_SURUM') ? APP_SURUM : 'unknown',
    'db'      => $dbOk ? 'ok' : 'error',
    'time'    => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
