<?php
// =========================================================
// beyan_bulk_save.php — Toplu beyan kaydı (Sprint Beyan-Toplu)
// AJAX endpoint: POST JSON {csrf, declarations:[{...}, ...]}
//   → JSON {ok, created, ids}
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$auth_user = require_login();
if (!can_beyan('create')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Yetersiz yetki.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Yalnızca POST desteklenir.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Geçerli bir JSON nesnesi gönderin.']);
    exit;
}
csrf_check($body['csrf'] ?? null);

$declarations = $body['declarations'] ?? [];
if (!is_array($declarations) || count($declarations) === 0) {
    echo json_encode(['ok' => false, 'error' => 'Kaydedilecek beyan bulunamadı.']);
    exit;
}
if (count($declarations) > 100) {
    echo json_encode(['ok' => false, 'error' => 'Tek seferde en fazla 100 beyan eklenebilir.']);
    exit;
}

// PHP oturum kilidi aynı isteğin paralel tekrarlarını seri hale getirir.
// Yanıt kaybolursa aynı kimlik/payload yeniden gönderilir; ikinci INSERT yok.
$request_id = (string)($body['request_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $request_id)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Geçerli bir toplu kayıt istek kimliği gerekli.']);
    exit;
}
$payload_hash = hash('sha256', json_encode($declarations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$history = $_SESSION['beyan_bulk_requests'] ?? [];
if (isset($history[$request_id])) {
    if (!hash_equals($history[$request_id]['hash'], $payload_hash)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Bu istek kimliği farklı beyanlar için kullanılmış. Önizlemeyi yeniden açın.']);
    } else {
        echo json_encode($history[$request_id]['response']);
    }
    exit;
}

$declarations = array_map(static fn($d) => is_array($d) ? beyan_protect_input($d) : $d, $declarations);
$row_errors = [];
foreach (array_values($declarations) as $i => $d) {
    $errors = is_array($d) ? beyan_veri_dogrula($d)['errors'] : ['Geçerli bir beyan satırı gerekli.'];
    if ($errors) $row_errors[] = ['row' => $i + 1, 'errors' => $errors];
}
if ($row_errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Hatalı satırları düzeltin veya seçimden çıkarın.', 'rows' => $row_errors], JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)($auth_user['id'] ?? 0);
$pdo     = db();
$ids     = [];

$pdo->beginTransaction();
try {
    foreach ($declarations as $d) {
        $ids[] = beyan_insert($d, $user_id);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('Beyan toplu kayıt: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Beyanlar kaydedilemedi. Aynı isteği yeniden deneyebilirsiniz.']);
    exit;
}

if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'Geçerli beyan bulunamadı.']);
    exit;
}

$response = ['ok' => true, 'created' => count($ids), 'ids' => $ids];
$history[$request_id] = ['hash' => $payload_hash, 'response' => $response];
$_SESSION['beyan_bulk_requests'] = array_slice($history, -20, null, true);

audit_log_event('beyan_bulk_create', 'declarations', null, null, [
    'created' => count($ids),
    'ids'     => $ids,
]);

echo json_encode($response);
