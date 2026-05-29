<?php
// =========================================================
// hks/api_send.php — AJAX: HKS bildirim gönder
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';
require_once __DIR__ . '/HksClient.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Yalnızca POST desteklenir.']);
    exit;
}

// JSON body oku
$body = (string)file_get_contents('php://input');
$input = json_decode($body, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Geçersiz JSON gövdesi.']);
    exit;
}

// CSRF doğrulama
csrf_check($input['csrf'] ?? null);

$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Geçersiz bildirim ID.']);
    exit;
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$result = $client->sendNotification($id);

// Audit — dış sistem bildirimi özeti (tam payload, token, API key loglanmaz)
audit_log_event(
    ($result['ok'] ?? false) ? 'hks_send' : 'hks_send_failed',
    'hks',
    $id,
    null,
    array_filter([
        'ok'              => $result['ok'] ?? false,
        'notification_no' => $result['notification_no'] ?? null,
        'tag_no'          => $result['tag_no'] ?? null,
        'status'          => ($result['ok'] ?? false) ? 'sent' : 'error',
        'error'           => !($result['ok'] ?? false) ? ($result['message'] ?? null) : null,
    ], fn($v) => $v !== null)
);

echo json_encode($result);
