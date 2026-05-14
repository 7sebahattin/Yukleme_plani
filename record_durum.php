<?php
// =========================================================
// record_durum.php - Kayıt durum güncelleme (AJAX POST)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

$id    = (int)($_POST['id']   ?? 0);
$durum = trim($_POST['durum'] ?? '');
$csrf  = $_POST['csrf'] ?? null;

try {
    csrf_check($csrf);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => 'Güvenlik hatası.']);
    exit;
}

if ($id <= 0 || !in_array($durum, ['islendi', 'yuklendi', ''], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz istek.']);
    exit;
}

if ($durum === 'yuklendi') {
    $st = db()->prepare("SELECT durum FROM loading_records WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['durum'] !== 'islendi') {
        echo json_encode(['ok' => false, 'msg' => 'Önce İşlendi yapılmalı.']);
        exit;
    }
}

db()->prepare("UPDATE loading_records SET durum = ? WHERE id = ?")->execute([$durum, $id]);
echo json_encode(['ok' => true, 'durum' => $durum]);
