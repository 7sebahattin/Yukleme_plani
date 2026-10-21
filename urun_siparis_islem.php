<?php
// =========================================================
// urun_siparis_islem.php — Ürün Siparişleri: tek sipariş getir (GET ?id=) · kaydet · sil (POST JSON)
//
// GET  ?id=N                          siparis.read   → siparişin TÜM alanları (şoför/telefon dahil; liste HTML'ine girmez)
// POST {islem:'kaydet', id?, …}       siparis.write  → id yoksa yeni, varsa düzeltme (sunucu yeniden hesaplar)
// POST {islem:'sil', id}              siparis.delete → soft silme
// Her POST'ta CSRF (JSON-aware). Başka depoya ait kayıt "bulunamadı" der (varlığı sızdırmaz).
// Ad TEK alt çizgili ek içerir: sw.js bypass regex'i (urun_siparis(_[a-z]+)?.php) bunu önbelleğe almaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/urun_siparis.php';

$auth_user = require_login();
siparis_no_store();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$yanit = function (int $kod, array $veri): void {
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$yontem = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!can_siparis('read')) $yanit(403, ['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.']);
try { enforce_active_depot(); } catch (Throwable $e) { $yanit(403, ['ok' => false, 'hata' => 'Aktif depo seçilmeli.']); }

$pdo = db();
if (!usip_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Ürün siparişleri henüz kurulmadı (Şema Migrasyon Paneli).']);

$goster = function (?array $r) use ($yanit): array {
    // Başka depoya ait kayıt, olmayan kayıtla AYNI yanıtı verir
    if ($r === null || !depot_visible_to_user((string)($r['depo'] ?? ''))) $yanit(404, ['ok' => false, 'hata' => 'Sipariş bulunamadı.']);
    return $r;
};

if ($yontem === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    session_write_close();
    $r = $goster($id > 0 ? usip_getir($pdo, $id) : null);
    $yanit(200, ['ok' => true, 'siparis' => $r]);
}
if ($yontem !== 'POST') { header('Allow: GET, POST'); $yanit(405, ['ok' => false, 'hata' => 'Geçersiz istek.']); }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
csrf_check($in['csrf'] ?? null);   // JSON-aware: hatada 403 + JSON
$islem = (string)($in['islem'] ?? '');
$uid = (int)$auth_user['id'];
$id = isset($in['id']) ? (int)$in['id'] : 0;

if ($islem === 'sil') {
    if (!can_siparis('delete')) $yanit(403, ['ok' => false, 'hata' => 'Silme yetkiniz yok.']);
    $eski = $goster($id > 0 ? usip_getir($pdo, $id) : null);
    if (!usip_sil($pdo, $id, $uid)) $yanit(404, ['ok' => false, 'hata' => 'Sipariş bulunamadı.']);
    audit_log_event('delete', 'urun_siparis', $id, ['firma' => $eski['firma'], 'urun' => $eski['urun'], 'teslimat_tarihi' => $eski['teslimat_tarihi'], 'toplam_palet' => (int)$eski['toplam_palet']], null);
    $yanit(200, ['ok' => true, 'mesaj' => 'Sipariş silindi.']);
}

if ($islem !== 'kaydet') $yanit(400, ['ok' => false, 'hata' => 'Geçersiz işlem.']);
if (!can_siparis('write')) $yanit(403, ['ok' => false, 'hata' => 'Kayıt yetkiniz yok.']);
$eski = null;
if ($id > 0) $eski = $goster(usip_getir($pdo, $id));
[$temiz, $hatalar] = usip_dogrula($in);
if ($hatalar) $yanit(422, ['ok' => false, 'hata' => implode(' ', $hatalar), 'hatalar' => $hatalar]);

try {
    $yeniId = usip_kaydet($pdo, $temiz, $uid, $eski ? $id : null, (string)(active_depot() ?? ''));
} catch (PDOException $e) {
    error_log('[urun_siparis_islem] ' . $e->getMessage());
    $yanit(500, ['ok' => false, 'hata' => 'Kaydedilemedi, tekrar deneyin.']);
}
$hs = usip_hesap($temiz['kalibreler'], $temiz['palet_kasa'], $temiz['birim_agirlik'], $temiz['birim_fiyat']);
// Audit: şoför adı/telefonu YAZILMAZ (kişisel veri)
audit_log_event($eski ? 'update' : 'create', 'urun_siparis', $yeniId,
    $eski ? ['firma' => $eski['firma'], 'urun' => $eski['urun'], 'teslimat_tarihi' => $eski['teslimat_tarihi'], 'toplam_palet' => (int)$eski['toplam_palet'], 'tutar' => $eski['tutar']] : null,
    ['firma' => $temiz['firma'], 'urun' => $temiz['urun'], 'teslimat_tarihi' => $temiz['teslimat_tarihi'], 'toplam_palet' => $hs['palet'], 'toplam_kg' => $hs['kg'], 'tutar' => $hs['tutar']]);
$yanit(200, ['ok' => true, 'id' => $yeniId, 'mesaj' => $eski ? 'Sipariş güncellendi.' : 'Sipariş kaydedildi.']);
