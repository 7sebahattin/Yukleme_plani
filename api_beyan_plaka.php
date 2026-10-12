<?php
// =============================================================================
// api_beyan_plaka.php — Beyan önizlemesinden HIZLI plaka girişi (JSON, v340)
// =============================================================================
// Yalnız `vehicle_plate` yazar; başka alana dokunmaz (tam düzenleme gerekmesin).
// Plaka normalizasyonu TEK kaynaktan: beyan_plaka_normalize() (boşluksuz + büyük harf).
// HKS'e HİÇBİR ŞEY göndermez. Kapı: beyan.write + hks.write (plaka Hal Bildirim alanıdır).
// =============================================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');

function beyan_plaka_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth_user = function_exists('current_user') ? current_user() : null;
if ($auth_user === null) beyan_plaka_json(['ok' => false, 'error' => 'Oturum gerekli. Lütfen tekrar giriş yapın.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') beyan_plaka_json(['ok' => false, 'error' => 'Geçersiz istek.'], 405);
if (!(can_beyan('write') && can_beyan('hks.write'))) beyan_plaka_json(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.'], 403);

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 2000) beyan_plaka_json(['ok' => false, 'error' => 'İstek çok uzun.'], 413);
$in = json_decode($raw, true);
if (!is_array($in)) beyan_plaka_json(['ok' => false, 'error' => 'Geçersiz istek.'], 400);
csrf_check($in['csrf'] ?? null);

$id = (int)($in['id'] ?? 0);
$plaka = beyan_plaka_normalize(is_string($in['plaka'] ?? null) ? $in['plaka'] : '');
if ($id <= 0) beyan_plaka_json(['ok' => false, 'error' => 'Geçersiz beyan.'], 422);
if ($plaka === '') beyan_plaka_json(['ok' => false, 'error' => 'Plaka girin.'], 422);
if (!preg_match('/^[\p{L}\p{N}\-]{3,20}$/u', $plaka)) {
    beyan_plaka_json(['ok' => false, 'error' => 'Plaka 3-20 karakter olmalı; yalnız harf, rakam ve tire içerebilir.'], 422);
}

try {
    $pdo = db();
    $st = $pdo->prepare("SELECT id, vehicle_plate, deleted_at FROM customs_declarations WHERE id = ?");
    $st->execute([$id]);
    $b = $st->fetch();
    if (!$b) beyan_plaka_json(['ok' => false, 'error' => 'Beyan bulunamadı.'], 404);
    if (!empty($b['deleted_at'])) beyan_plaka_json(['ok' => false, 'error' => 'Arşivlenmiş beyan düzenlenemez.'], 409);

    $up = $pdo->prepare("UPDATE customs_declarations SET vehicle_plate = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
    $up->execute([$plaka, (int)($auth_user['id'] ?? 0), $id]);
    audit_log_event('beyan_plaka_hizli', 'declarations', $id,
        ['vehicle_plate' => (string)($b['vehicle_plate'] ?? '')], ['vehicle_plate' => $plaka]);
    beyan_plaka_json(['ok' => true, 'plaka' => $plaka]);
} catch (Throwable $e) {
    error_log('[api_beyan_plaka] ' . $e->getMessage());
    beyan_plaka_json(['ok' => false, 'error' => 'Plaka kaydedilemedi. Tekrar deneyin.'], 500);
}
