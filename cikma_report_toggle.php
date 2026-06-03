<?php
// =========================================================
// cikma_report_toggle.php — Çıkma kaydı raporlandı toggle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cikmalar.php'); exit;
}

csrf_check($_POST['csrf'] ?? null);

$id     = (int)($_POST['id']    ?? 0);
$action = trim($_POST['action'] ?? '');

if ($id <= 0 || !in_array($action, ['mark', 'unmark'], true)) {
    set_flash('error', 'Geçersiz istek.');
    header('Location: cikmalar.php'); exit;
}

// Kolon kontrolü — db.php migration fallback
$col_ok = false;
try {
    $col_ok = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn();
} catch (PDOException $_ce) {}
if (!$col_ok) {
    try {
        db()->exec("ALTER TABLE `loading_records`
            ADD COLUMN `reported_at` DATETIME NULL,
            ADD COLUMN `reported_by` INT NULL");
        $col_ok = true;
    } catch (PDOException $_ae) {
        set_flash('error', 'Veritabanı güncellenemedi.');
        header('Location: cikmalar.php'); exit;
    }
}

$st = db()->prepare("SELECT id, type, reported_at FROM loading_records WHERE id = ?");
$st->execute([$id]);
$rec = $st->fetch();
if (!$rec || $rec['type'] !== 'cikma') {
    set_flash('error', 'Kayıt bulunamadı veya bu işlem yalnızca çıkma kayıtları için geçerlidir.');
    header('Location: cikmalar.php'); exit;
}

if ($action === 'mark') {
    $now   = date('Y-m-d H:i:s');
    $by_id = (int)($auth_user['id'] ?? 0) ?: null;
    db()->prepare("UPDATE loading_records SET reported_at=?, reported_by=? WHERE id=?")
        ->execute([$now, $by_id, $id]);
    audit_log_event('cikma_report_mark', 'loading_records', $id,
        ['reported_at' => null],
        ['reported_at' => $now, 'reported_by' => $by_id]);
    set_flash('success', 'Çıkma kaydı raporlandı olarak işaretlendi.');
} else {
    $old_ra = $rec['reported_at'];
    db()->prepare("UPDATE loading_records SET reported_at=NULL, reported_by=NULL WHERE id=?")
        ->execute([$id]);
    audit_log_event('cikma_report_unmark', 'loading_records', $id,
        ['reported_at' => $old_ra],
        ['reported_at' => null]);
    set_flash('success', 'Raporlandı işareti geri alındı.');
}

header('Location: cikmalar.php'); exit;
