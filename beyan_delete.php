<?php
// =========================================================
// beyan_delete.php — Soft delete (Sprint Beyan-01)
// Sadece POST kabul eder; GET ile doğrudan erişim reddedilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('delete')) forbidden();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: beyanlar.php');
    exit;
}

csrf_check($_POST['csrf'] ?? null);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz beyan ID.');
    header('Location: beyanlar.php');
    exit;
}

$st = db()->prepare("SELECT id, party_no, status, deleted_at FROM customs_declarations WHERE id = ?");
$st->execute([$id]);
$beyan = $st->fetch();

if (!$beyan) {
    set_flash('error', 'Beyan bulunamadı.');
    header('Location: beyanlar.php');
    exit;
}

if (!empty($beyan['deleted_at'])) {
    set_flash('error', 'Bu beyan zaten silinmiş.');
    header('Location: beyanlar.php');
    exit;
}

$user_id = (int)($auth_user['id'] ?? 0);

$st = db()->prepare("UPDATE customs_declarations SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?");
$st->execute([$user_id, $id]);

audit_log_event('beyan_delete', 'declarations', $id, [
    'party_no' => $beyan['party_no'],
    'status'   => $beyan['status'],
], null);

set_flash('success', 'Beyan arşivlendi (silindi).');
header('Location: beyanlar.php');
exit;
