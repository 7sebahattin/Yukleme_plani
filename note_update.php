<?php
// =========================================================
// note_update.php - Dev notu tamamla / sil
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST gerekli.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($data)) {
    $data = [
        'action' => $_POST['action'] ?? '',
        'id'     => $_POST['id']     ?? 0,
        'csrf'   => $_POST['csrf']   ?? null,
    ];
}
csrf_check($data['csrf'] ?? null);

$action = (string)($data['action'] ?? '');
$id     = (int)($data['id'] ?? 0);

if ($action === 'delete_all_done') {
    $cnt = (int)db()->query("SELECT COUNT(*) FROM dev_notes WHERE done=1")->fetchColumn();
    db()->exec("DELETE FROM dev_notes WHERE done=1");
    echo json_encode(['ok' => true, 'deleted' => $cnt]);
    exit;
}

if ($id <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz ID.']);
    exit;
}

if ($action === 'toggle') {
    $st = db()->prepare("SELECT done FROM dev_notes WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Bulunamadı.']); exit; }
    $new_done = $row['done'] ? 0 : 1;
    db()->prepare("UPDATE dev_notes SET done = ? WHERE id = ?")->execute([$new_done, $id]);
    echo json_encode(['ok' => true, 'done' => $new_done]);
    exit;
}

if ($action === 'delete') {
    db()->prepare("DELETE FROM dev_notes WHERE id = ?")->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Bilinmeyen işlem.']);
