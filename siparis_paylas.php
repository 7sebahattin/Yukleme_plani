<?php
// =========================================================
// siparis_paylas.php — Siparişler › ⋮ "WP Paylaş": TEK sevkiyatın kart verisi (GET JSON; siparis.read; v394)
//
// Kapalı / iptal sevkiyat da paylaşılır; silinmiş, olmayan ya da başka depodaki sevkiyat aynı 404'ü alır (varlık sızmaz).
// Her tıkta taze okunur: şoför adı/telefonu liste HTML'ine girmez, yalnız bu yanıtla o anda yetkili olana gider.
// Salt okunur — audit dışında hiçbir yazma yok; audit'e ad/telefon/plaka/firma YAZILMAZ (yalnız kalem sayısı).
// Ad TEK alt çizgilidir: sw.js bypass regex'i bunu önbelleğe almaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_beyan.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_wp.php';
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
if (!siparis_sema_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Sipariş modülü henüz kurulmadı.']);

$sid = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : 0;
try {
    $okunan = siparis_wp_oku($pdo, $sid);
} catch (PDOException $e) {
    error_log('[siparis_paylas] ' . $e->getMessage());
    $yanit(500, ['ok' => false, 'hata' => 'Sevkiyat okunamadı.']);
}
if ($okunan === null) $yanit(404, ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.']);

$veri = siparis_wp_veri($okunan, ['parti_yetki' => function_exists('can_beyan') && can_beyan('read')]);
siparis_audit('siparis_wp_paylas', $sid, null, ['kalem' => $veri['kalem_say']], (int)$auth_user['id']);
$yanit(200, $veri);
