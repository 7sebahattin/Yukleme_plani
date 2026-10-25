<?php
// =========================================================
// urun_siparis_yazdir.php — Ürün Siparişleri yazdırma / PDF / WP resmi verisi (GET JSON; siparis.read)
//
// OGL siparis_yazdir.php ile AYNI sözleşme (assets/siparis_yazdir.js değişmeden kullanılır): ekrandaki arama
// filtresiyle aynı küme (usip_filtre), sayfalamasız, en çok 500 satır, her tıkta taze okunur. Salt okunur.
// Ad TEK alt çizgili ek içerir: sw.js bypass regex'i (urun_siparis(_[a-z]+)?.php) bunu önbelleğe almaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/urun_siparis.php';
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
if (!usip_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Ürün siparişleri henüz kurulmadı.']);

$F = usip_filtre($_GET, $pdo);
try {
    $veri = usip_yazdir_veri($pdo, $F, [
        'bugun' => date('Y-m-d'),
        'basim' => date('d.m.Y H:i'),
        'depo' => (string)(active_depot() ?? ''),
        'baslik' => 'Ürün Siparişleri',
        'filtre' => $F['q'] !== '' ? 'Arama: ' . $F['q'] : '',
    ]);
} catch (PDOException $e) {
    error_log('[urun_siparis_yazdir] ' . $e->getMessage());
    $yanit(500, ['ok' => false, 'hata' => 'Liste okunamadı.']);
}
$amac = (string)($_GET['amac'] ?? 'yazdir');
if (!in_array($amac, ['yazdir', 'pdf', 'resim'], true)) $amac = 'yazdir';
// Audit: yalnız sayılar — şoför adı/telefonu ve arama metni YAZILMAZ
audit_log_event('urun_siparis_yazdir', 'urun_siparis', null, null, ['adet' => $veri['adet'], 'amac' => $amac, 'kesildi' => $veri['kesildi'] ? 1 : 0, 'arama' => $F['q'] !== '' ? 1 : 0], (int)$auth_user['id']);
$yanit(200, $veri);
