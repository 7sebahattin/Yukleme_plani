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
if (!can_beyan('write')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Yetersiz yetki.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Yalnızca POST desteklenir.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
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

$user_id = (int)($auth_user['id'] ?? 0);
$pdo     = db();
$ids     = [];

$pdo->beginTransaction();
try {
    foreach ($declarations as $d) {
        if (!is_array($d)) continue;
        // En az bir anlamlı alan olmalı (boş satırları atla)
        $has = trim((string)($d['party_no']     ?? '')) !== ''
            || trim((string)($d['product_name'] ?? '')) !== ''
            || trim((string)($d['raw_text']     ?? '')) !== '';
        if (!$has) continue;
        $ids[] = beyan_insert($d, $user_id);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Kayıt hatası: ' . $e->getMessage()]);
    exit;
}

if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'Geçerli beyan bulunamadı.']);
    exit;
}

audit_log_event('beyan_bulk_create', 'declarations', null, null, [
    'created' => count($ids),
    'ids'     => $ids,
]);

echo json_encode(['ok' => true, 'created' => count($ids), 'ids' => $ids]);
