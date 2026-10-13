<?php
// Beyan formu: yazılan ürün adı bir HKS ürünüyle eşleşiyor mu? (v347) Salt okunur; HKS'e gitmez, yazmaz.
// id = KİLİTLEYEN eşleşme (yalnız katalog tam ad / benzer ad — beyan_hks_urun_otomatik); öğrenilen eşleşme
// KİLİTLEMEZ, yalnız açık alan için 'oneri' olarak döner (v349). id ASLA uydurulmaz.
declare(strict_types=1);
ob_start();                       // ara çıktı (uyarı/bildirim) JSON'u bozmasın — sonda atılır
ini_set('display_errors', '0');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
if (!can_beyan('hks.read')) { http_response_code(403); echo json_encode(['ok' => false, 'id' => ''], JSON_UNESCAPED_UNICODE); exit; }
$ad = mb_substr(trim((string)($_GET['ad'] ?? '')), 0, 150, 'UTF-8');
$katalog = bb_katalog();
$t = $katalog ? beyan_hks_urun_otomatik($ad, $katalog['urunler']) : ['id' => '', 'ad' => '', 'kaynak' => 'yok'];
$oneri = '';
if ($t['id'] === '' && $ad !== '' && $katalog) { $o = hks_eslesme_bul('urun', $ad); if ($o) $oneri = (string)$o['hks_id']; }
echo json_encode(['ok' => true, 'id' => (string)$t['id'], 'ad' => (string)$t['ad'], 'kaynak' => (string)$t['kaynak'], 'oneri' => $oneri], JSON_UNESCAPED_UNICODE);
