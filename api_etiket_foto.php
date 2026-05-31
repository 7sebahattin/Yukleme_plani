<?php
// =========================================================
// api_etiket_foto.php — Etiket fotoğrafını DB'ye kaydet/sil
// POST JSON: { csrf, id, data } → kaydet (data: "data:image/jpeg;base64,...")
//            { csrf, id, clear:1 } → sil
// Cihazlar arası görünürlük için base64 loading_records.etiket_foto'da tutulur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
require_perm('records.write');

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
csrf_check($body['csrf'] ?? null);

$id = (int)($body['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Geçersiz kayıt']); exit; }

if (!db_has_column('loading_records', 'etiket_foto')) {
    echo json_encode(['ok' => false, 'error' => 'etiket_foto kolonu yok. Yöneticiyle iletişime geçin.']); exit;
}

try {
    if (!empty($body['clear'])) {
        db()->prepare("UPDATE loading_records SET etiket_foto=NULL WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true, 'cleared' => true]);
        exit;
    }

    $data = (string)($body['data'] ?? '');
    // Yalnız data:image/...;base64,... kabul et
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=\s]+$#', $data)) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz görsel verisi']); exit;
    }
    // ~12MB üst sınır (MEDIUMTEXT 16MB)
    if (strlen($data) > 12 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Fotoğraf çok büyük']); exit;
    }
    db()->prepare("UPDATE loading_records SET etiket_foto=? WHERE id=?")->execute([$data, $id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
