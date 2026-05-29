<?php
// =========================================================
// note_save.php - Dev notlarını listele / ekle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = db()->query(
        "SELECT id, page_url, page_name, note, done, created_at
         FROM dev_notes ORDER BY done ASC, id DESC LIMIT 50"
    )->fetchAll();
    echo json_encode(['ok' => true, 'notes' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($data)) {
        $data = [
            'page_url'  => trim((string)($_POST['page_url']  ?? '')),
            'page_name' => trim((string)($_POST['page_name'] ?? '')),
            'note'      => trim((string)($_POST['note']      ?? '')),
            'csrf'      => $_POST['csrf'] ?? null,
        ];
    }
    csrf_check($data['csrf'] ?? null);

    if (!can('records.write')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Bu işlem için records.write yetkisi gereklidir.']);
        exit;
    }

    $note = trim((string)($data['note'] ?? ''));
    if ($note === '') {
        echo json_encode(['ok' => false, 'msg' => 'Not boş olamaz.']);
        exit;
    }

    $page_url  = substr(trim((string)($data['page_url']  ?? '')), 0, 255);
    $page_name = substr(trim((string)($data['page_name'] ?? '')), 0, 100);

    $st = db()->prepare(
        "INSERT INTO dev_notes (page_url, page_name, note, done) VALUES (?, ?, ?, 0)"
    );
    $st->execute([$page_url, $page_name, $note]);
    $id = (int)db()->lastInsertId();

    $row = db()->prepare("SELECT id, page_url, page_name, note, done, created_at FROM dev_notes WHERE id = ?");
    $row->execute([$id]);

    echo json_encode(['ok' => true, 'note' => $row->fetch()]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Geçersiz istek.']);
