<?php
// =========================================================
// frugro_paylas.php — Frugro Siparişler › ⋮ "WP Paylaş": TEK siparişin kart verisi (GET JSON; siparis.read; v515)
//
// Kapalı / iptal sipariş de paylaşılır; silinmiş, olmayan ya da başka depodaki sipariş aynı 404'ü alır (varlık sızmaz).
// Her tıkta taze okunur. Salt okunur — audit dışında yazma yok; audit'e ad/telefon/plaka/firma YAZILMAZ (yalnız kalem sayısı).
// Ad TEK alt çizgilidir: sw.js bypass regex'i bunu önbelleğe almaz. Mantık config/frugro_wp.php (frgw_*).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/frugro_wp.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$yanit = function (int $kod, array $veri): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    $yanit(405, ['ok' => false, 'hata' => 'Yalnız GET.']);
}
session_write_close();

$pdo = db();
if (!frg_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Frugro Siparişler henüz kurulmadı.']);

$sid = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
try {
    $s = $sid > 0 ? frg_getir($pdo, $sid) : null;
    $ozet = $s !== null && frg_gorunur($s) ? (frg_palet_ozet_haritasi($pdo, [$sid])[$sid] ?? []) : [];
} catch (PDOException $e) {
    error_log('[frugro_paylas] ' . $e->getMessage());
    $yanit(500, ['ok' => false, 'hata' => 'Sipariş okunamadı.']);
}
if ($s === null || !frg_gorunur($s)) $yanit(404, ['ok' => false, 'hata' => 'Sipariş bulunamadı.']);

$veri = frgw_veri($s, $ozet);
if (function_exists('audit_log_event')) audit_log_event('frugro_wp_paylas', 'frugro_siparis', $sid, null, ['kalem' => $veri['kalem_say']], (int)$auth_user['id']);
$yanit(200, $veri);
