<?php
// =========================================================
// record_durum.php - Kayıt durum güncelleme (AJAX POST)
// Kilitleme: durum=yuklendi → locked_at/locked_by set edilir.
// Kilit açma: yuklendi→diğer yalnızca records.unlock yetkisiyle,
//             revision_reason zorunludur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

header('Content-Type: application/json');

$id    = (int)($_POST['id']   ?? 0);
$durum = trim($_POST['durum'] ?? '');
$csrf  = $_POST['csrf'] ?? null;
$tarih_new = trim($_POST['tarih'] ?? '');

// csrf_check() JSON-aware: başarısızsa kendisi JSON 403 döner ve exit eder.
csrf_check($csrf);

if ($id <= 0 || !in_array($durum, ['islendi', 'yuklendi', ''], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz istek.']);
    exit;
}

// Mevcut kaydı çek
$st = db()->prepare("SELECT durum, locked_at FROM loading_records WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'msg' => 'Kayıt bulunamadı.']);
    exit;
}

$_old_durum = (string)($row['durum'] ?? '');
$_locked    = !empty($row['locked_at']);

// Kilitli kayıt veya yuklendi durumundan çıkış → records.unlock gerekli
if ($durum !== 'yuklendi' && ($_old_durum === 'yuklendi' || $_locked)) {
    if (!can('records.unlock')) {
        echo json_encode(['ok' => false, 'msg' => 'Bu işlem için kilit açma yetkisi gereklidir.']);
        exit;
    }
    $revision_reason = trim($_POST['revision_reason'] ?? '');
    if ($revision_reason === '') {
        echo json_encode(['ok' => false, 'msg' => 'Revizyon nedeni zorunludur.', 'need_reason' => true]);
        exit;
    }
}

// yuklendi için önce islendi şartı
if ($durum === 'yuklendi' && $_old_durum !== 'islendi') {
    echo json_encode(['ok' => false, 'msg' => 'Önce İşlendi yapılmalı.']);
    exit;
}

$user_id = (int)($auth_user['id'] ?? 0);
$pdo     = db();

if ($durum === 'yuklendi') {
    $tarih_ok = ($tarih_new !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih_new) && strtotime($tarih_new));
    if ($tarih_ok) {
        $pdo->prepare("UPDATE loading_records SET durum = ?, locked_at = NOW(), locked_by = ?, tarih = ? WHERE id = ?")
            ->execute([$durum, $user_id, $tarih_new, $id]);
    } else {
        $pdo->prepare("UPDATE loading_records SET durum = ?, locked_at = NOW(), locked_by = ? WHERE id = ?")
            ->execute([$durum, $user_id, $id]);
    }
    audit_log_event('lock', 'records', $id, ['durum' => $_old_durum], ['durum' => $durum, 'locked_by' => $user_id, 'tarih' => $tarih_ok ? $tarih_new : null]);
} elseif ($_old_durum === 'yuklendi' || $_locked) {
    $revision_reason = trim($_POST['revision_reason'] ?? '');
    $pdo->prepare("UPDATE loading_records SET durum = ?, locked_at = NULL, locked_by = NULL, unlocked_at = NOW(), unlocked_by = ?, revision_reason = ? WHERE id = ?")
        ->execute([$durum, $user_id, $revision_reason, $id]);
    audit_log_event('revision_open', 'records', $id, ['durum' => $_old_durum], ['durum' => $durum, 'revision_reason' => $revision_reason, 'unlocked_by' => $user_id]);
} else {
    $pdo->prepare("UPDATE loading_records SET durum = ? WHERE id = ?")
        ->execute([$durum, $id]);
    audit_log_event('status_change', 'records', $id, ['durum' => $_old_durum], ['durum' => $durum]);
}

echo json_encode(['ok' => true, 'durum' => $durum]);
