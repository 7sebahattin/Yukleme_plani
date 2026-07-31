<?php
// =========================================================
// hesap_durum.php — Durum geçişi uç noktası (JSON)
// Tekil veya toplu geçiş. Yetki, geçiş kuralı ve gerekçe zorunluluğu
// hesap_transition() içinde sunucu tarafında doğrulanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

csrf_check($_POST['csrf'] ?? null);

$durum = trim($_POST['durum'] ?? '');
$not   = trim($_POST['not'] ?? '');

if (!hesap_status_valid($durum)) {
    echo json_encode(['ok' => false, 'msg' => 'Geçersiz durum.']);
    exit;
}

// Tekil (id) veya toplu (ids[])
$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = array_values(array_filter(array_map('intval', $_POST['ids']), fn($v) => $v > 0));
} elseif ((int)($_POST['id'] ?? 0) > 0) {
    $ids = [(int)$_POST['id']];
}

if (empty($ids)) {
    echo json_encode(['ok' => false, 'msg' => 'Kayıt seçilmedi.']);
    exit;
}

$ok = 0;
$hatalar = [];
foreach ($ids as $id) {
    $res = hesap_transition($id, $durum, $not);
    if (!empty($res['ok'])) { $ok++; }
    else { $hatalar[] = '#' . $id . ': ' . $res['msg']; }
}

if ($ok === 0) {
    echo json_encode(['ok' => false, 'msg' => implode(' · ', array_slice($hatalar, 0, 3))]);
    exit;
}

$msg = $ok . ' kayıt ' . hesap_status_label($durum) . ' olarak işaretlendi.';
if (!empty($hatalar)) {
    $msg .= ' (' . count($hatalar) . ' kayıt atlandı)';
}
echo json_encode(['ok' => true, 'msg' => $msg, 'basarili' => $ok, 'atlanan' => count($hatalar)]);
