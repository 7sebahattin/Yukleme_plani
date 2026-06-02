<?php
// =========================================================
// kantar_report_toggle.php — Kantar fişi raporlandı toggle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.write');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: kantar.php'); exit;
}

csrf_check($_POST['csrf'] ?? null);

$id     = (int)($_POST['id']    ?? 0);
$action = trim($_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['mark', 'unmark'], true)) {
    set_flash('error', 'Geçersiz istek.');
    header('Location: kantar.php'); exit;
}

$st = db()->prepare("SELECT id, reported_at FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

if ($action === 'mark') {
    $now   = date('Y-m-d H:i:s');
    $by_id = (int)($auth_user['id'] ?? 0) ?: null;
    db()->prepare("UPDATE kantar_fisleri SET reported_at=?, reported_by=? WHERE id=?")
        ->execute([$now, $by_id, $id]);
    audit_log_event('kantar_report_mark', 'kantar_fisleri', $id,
        ['reported_at' => null],
        ['reported_at' => $now, 'reported_by' => $by_id]);
    set_flash('success', 'Kantar fişi raporlandı olarak işaretlendi.');
} else {
    $old_ra = $fis['reported_at'];
    db()->prepare("UPDATE kantar_fisleri SET reported_at=NULL, reported_by=NULL WHERE id=?")
        ->execute([$id]);
    audit_log_event('kantar_report_unmark', 'kantar_fisleri', $id,
        ['reported_at' => $old_ra],
        ['reported_at' => null]);
    set_flash('success', 'Raporlandı işareti geri alındı.');
}

$back = $_POST['back'] ?? '';
header('Location: ' . (preg_match('/^(kantar\.php|kantar_view\.php)/', $back) ? $back : 'kantar.php'));
exit;
