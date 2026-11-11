<?php
// =========================================================
// frugro_yazdir.php — Frugro Siparişler yazdırma / PDF / WP resmi verisi (GET JSON; siparis.read; v515)
//
// Ekrandaki süzgeç/arama ile AYNI küme (frgui_filtre), sayfalamasız, en çok 500 satır; ?bolum=acik|evrak|kapali (tek durum süzgecinde bolum yok sayılır).
// Her tıkta taze okunur (istemcide önbellek YOK). Salt okunur — audit dışında yazma yok; audit'e arama metni / ad / telefon YAZILMAZ (yalnız sayılar).
// JSON sözleşmesi OGL siparis_yazdir.php ile aynı (assets/siparis_yazdir.js AYNEN kullanılır) + birim {adet:'sipariş', kasa:'kutu'}.
// Ad TEK alt çizgilidir: sw.js bypass regex'i (/frugro(_[a-z]+)?\.php/) bunu önbelleğe almaz. Mantık config/frugro_yazdir.php (frgy_*).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/frugro_yazdir.php';
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
session_write_close();   // uzun okuma oturumu kilitlemesin

$pdo = db();
if (!frg_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Frugro Siparişler henüz kurulmadı.']);

$F = frgui_filtre($_GET);
$bolum = is_scalar($_GET['bolum'] ?? null) ? (string)$_GET['bolum'] : 'acik';
if (!isset(frgy_bolumler()[$bolum])) $bolum = 'acik';
try {
    $okunan = frgy_oku($pdo, $F, $bolum);
} catch (PDOException $e) {   // PDOException RuntimeException'ın alt sınıfıdır — ÖNCE gelmeli
    error_log('[frugro_yazdir] ' . $e->getMessage());
    $yanit(500, ['ok' => false, 'hata' => 'Liste okunamadı.']);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'kapsam_yok') $yanit(400, ['ok' => false, 'kod' => 'kapsam_yok', 'hata' => 'Bu bölüm bu süzgeçle yazdırılamaz.']);
    throw $e;
}

$ozet = $F['detayOzet'];
if ($F['q'] !== '') $ozet[] = 'Arama: ' . $F['q'];     // yalnız ekranda/çıktıda — audit'e YAZILMAZ
$veri = frgy_bicimle($okunan, [
    'bugun' => date('Y-m-d'),
    'basim' => date('d.m.Y H:i'),
    'depo' => (string)(active_depot() ?? ''),
    'baslik' => frgy_baslik($F, $okunan['kapsam']),
    'filtre_ozet' => implode(' · ', $ozet),
]);

$amac = is_scalar($_GET['amac'] ?? null) ? (string)$_GET['amac'] : 'yazdir';
if (!in_array($amac, ['yazdir', 'pdf', 'resim'], true)) $amac = 'yazdir';
if (function_exists('audit_log_event')) {
    audit_log_event('frugro_yazdir', 'frugro_siparis', null, null, ['adet' => $veri['adet'], 'amac' => $amac, 'kapsam' => $okunan['kapsam'], 'kesildi' => $veri['kesildi'] ? 1 : 0, 'arama' => $F['q'] !== '' ? 1 : 0], (int)$auth_user['id']);
}
$yanit(200, $veri);
