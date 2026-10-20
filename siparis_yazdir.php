<?php
// =========================================================
// siparis_yazdir.php — Sevkiyat yazdırma / PDF / WP resmi verisi (GET JSON; siparis.read; v382, v403: ?bolum=acik|evrak|kapali)
//
// Ekrandaki filtre/arama ile AYNI küme (siparis_liste_filtre), sayfalamasız, en çok 500 satır. Her tıkta taze okunur (istemcide
// önbellek YOK): şoför adı/telefonu bu yanıtla yalnız o anda yetkili olana gider ve HTML listeye girmez. Salt okunur —
// audit dışında hiçbir yazma yok; audit'e ad/telefon/arama metni YAZILMAZ (yalnız sayılar). Ad TEK alt çizgilidir: sw.js
// bypass regex'i (/siparis(ler|_[a-z]+)?\.php/) bunu önbelleğe almaz; iki ekli bir ad (siparis_acik_yazdir.php) kapsam dışı kalırdı.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_beyan.php';
require_once __DIR__ . '/_siparis_ortak.php';
require_once __DIR__ . '/config/siparis_yazdir.php';
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
if (!siparis_sema_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Sipariş modülü henüz kurulmadı.']);

$F = siparis_liste_filtre($_GET);
$bolum = (string)($_GET['bolum'] ?? 'acik');
if (!isset(siparis_yazdir_bolumler()[$bolum])) $bolum = 'acik';
try {
    $okunan = siparis_yazdir_oku($pdo, $F, $bolum);
} catch (PDOException $e) {   // PDOException RuntimeException'ın alt sınıfıdır — ÖNCE gelmeli
    error_log('[siparis_yazdir] ' . $e->getMessage());   // kullanıcı verisi mesaja girmez (bağlı parametre)
    $yanit(500, ['ok' => false, 'hata' => 'Liste okunamadı.']);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'kapsam_yok') $yanit(400, ['ok' => false, 'kod' => 'kapsam_yok', 'hata' => 'Bu bölüm bu filtreyle yazdırılamaz.']);
    throw $e;
}

$ozet = $F['detayOzet'];
if ($F['q'] !== '') $ozet[] = 'Arama: ' . $F['q'];     // yalnız ekranda/çıktıda — audit'e YAZILMAZ
$veri = siparis_yazdir_veri($okunan, [
    'bugun' => date('Y-m-d'),
    'basim' => date('d.m.Y H:i'),
    'depo' => (string)(active_depot() ?? ''),
    'baslik' => siparis_yazdir_baslik($F, $okunan['kapsam']),
    'filtre_ozet' => implode(' · ', $ozet),
    'parti_yetki' => function_exists('can_beyan') && can_beyan('read'),
]);

$amac = (string)($_GET['amac'] ?? 'yazdir');
if (!in_array($amac, ['yazdir', 'pdf', 'resim'], true)) $amac = 'yazdir';
siparis_audit('siparis_yazdir', null, null, ['adet' => $veri['adet'], 'amac' => $amac, 'kapsam' => $okunan['kapsam'], 'kesildi' => $veri['kesildi'] ? 1 : 0, 'arama' => $F['q'] !== '' ? 1 : 0], (int)$auth_user['id']);
$yanit(200, $veri);
