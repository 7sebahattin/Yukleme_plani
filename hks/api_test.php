<?php
// =========================================================
// hks/api_test.php — AJAX: HKS bağlantı testi
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

csrf_check($_POST['csrf'] ?? null);

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$result = $client->testConnection();

echo json_encode($result);
