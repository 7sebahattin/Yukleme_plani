<?php
// =========================================================
// maliyet_sil.php — Maliyet hesabını sil (soft delete)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: maliyet.php'); exit; }
csrf_check($_POST['csrf'] ?? null);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: maliyet.php'); exit; }

$st = db()->prepare("SELECT * FROM cost_sheets WHERE id=? AND deleted_at IS NULL");
$st->execute([$id]);
$sheet = $st->fetch();
if (!$sheet) { set_flash('error', 'Kayıt bulunamadı.'); header('Location: maliyet.php'); exit; }

if (!depot_visible_to_user((string)$sheet['depo'])) {
    forbidden('Bu maliyet hesabı başka bir depoya ait.');
}
if ($sheet['status'] === 'kesin' && !can_maliyet('unlock')) {
    set_flash('error', 'Kesinleşmiş hesap silinemez — önce kilidi açılmalı.');
    header('Location: maliyet_view.php?id=' . $id);
    exit;
}

try {
    db()->prepare("UPDATE cost_sheets SET deleted_at=NOW(), updated_by=? WHERE id=?")
        ->execute([(int)$auth_user['id'], $id]);

    audit_log_event('delete', 'maliyet', $id,
        ['sheet_no' => $sheet['sheet_no'], 'product' => $sheet['product'],
         'net_kg' => $sheet['net_kg'], 'status' => $sheet['status']],
        null);

    set_flash('success', 'Maliyet hesabı silindi.');
} catch (PDOException $e) {
    error_log('[maliyet_sil] ' . $e->getMessage());
    set_flash('error', 'Silme sırasında hata oluştu.');
}

header('Location: maliyet.php');
exit;
