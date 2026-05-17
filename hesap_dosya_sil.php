<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok'=>false,'msg'=>'Method not allowed']);
    exit;
}

csrf_check($_POST['csrf'] ?? null);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'Geçersiz ID']);
    exit;
}

$st = db()->prepare("SELECT * FROM account_files WHERE id=?");
$st->execute([$id]);
$f = $st->fetch();
if (!$f) {
    echo json_encode(['ok'=>false,'msg'=>'Dosya bulunamadı']);
    exit;
}

$path = HESAP_UPLOAD_DIR . $f['file_name'];
if (file_exists($path)) @unlink($path);

db()->prepare("DELETE FROM account_files WHERE id=?")->execute([$id]);

// has_files güncelle
$cnt = (int)db()->prepare("SELECT COUNT(*) FROM account_files WHERE transaction_id=?")->execute([$f['transaction_id']]) &&
       db()->prepare("SELECT COUNT(*) FROM account_files WHERE transaction_id=?")->execute([$f['transaction_id']]) ?:0;
$cnt_st = db()->prepare("SELECT COUNT(*) FROM account_files WHERE transaction_id=?");
$cnt_st->execute([$f['transaction_id']]);
$cnt = (int)$cnt_st->fetchColumn();
db()->prepare("UPDATE account_transactions SET has_files=? WHERE id=?")->execute([$cnt > 0 ? 1 : 0, $f['transaction_id']]);

echo json_encode(['ok'=>true]);
