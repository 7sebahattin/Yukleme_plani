<?php
// =============================================================================
// ekran_kilit.php — Masaüstü ekran kilidi ucu (JSON, v454)
// =============================================================================
// POST {csrf, islem: 'kilitle' | 'ac', sifre?}. Mantık: config/ekran_kilit.php.
// Şifre ASLA audit'e/log'a yazılmaz. Oturum yoksa 401 (istemci login'e yönlendirir).
// =============================================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ekran_kilit_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$kullanici = current_user();
if ($kullanici === null) ekran_kilit_json(['ok' => false, 'error' => 'Oturum sona ermiş. Lütfen tekrar giriş yapın.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ekran_kilit_json(['ok' => false, 'error' => 'Geçersiz istek.'], 405);

$ham = (string)file_get_contents('php://input');
if (strlen($ham) > 2000) ekran_kilit_json(['ok' => false, 'error' => 'İstek çok uzun.'], 413);
$in = json_decode($ham, true);
if (!is_array($in)) ekran_kilit_json(['ok' => false, 'error' => 'Geçersiz istek.'], 400);
csrf_check(is_string($in['csrf'] ?? null) ? $in['csrf'] : null);

$islem = (string)($in['islem'] ?? '');
if ($islem === 'kilitle') {
    ekran_kilitle();
    audit_log_event('ekran_kilit', 'auth', null, null, ['neden' => 'hareketsizlik']);
    ekran_kilit_json(['ok' => true]);
}
if ($islem === 'ac') {
    $s = ekran_kilit_ac(db(), (int)$kullanici['id'], is_string($in['sifre'] ?? null) ? $in['sifre'] : '');
    audit_log_event($s['ok'] ? 'ekran_kilit_ac' : 'ekran_kilit_basarisiz', 'auth');
    if ($s['ok']) ekran_kilit_json(['ok' => true]);
    ekran_kilit_json(['ok' => false, 'error' => $s['mesaj'], 'bekle' => $s['bekle']], $s['bekle'] > 0 ? 429 : 422);
}
ekran_kilit_json(['ok' => false, 'error' => 'Geçersiz istek.'], 400);
