<?php
// Beyan formu: yazılan ürün adı bir HKS ürünüyle eşleşiyor mu? (v347) Salt okunur; HKS'e gitmez, yazmaz.
// bb_tahmin() = öğrenilen eşleme → tam ad → benzer ad (tek aday). id ASLA uydurulmaz.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_login();
if (!can_beyan('hks.read')) { http_response_code(403); echo json_encode(['ok' => false, 'id' => ''], JSON_UNESCAPED_UNICODE); exit; }
$ad = mb_substr(trim((string)($_GET['ad'] ?? '')), 0, 150, 'UTF-8');
$katalog = bb_katalog();
$t = ($ad !== '' && $katalog) ? bb_tahmin('urun', $ad, $katalog['urunler']) : ['id' => '', 'ad' => '', 'kaynak' => 'yok'];
echo json_encode(['ok' => true, 'id' => (string)$t['id'], 'ad' => (string)$t['ad'], 'kaynak' => (string)$t['kaynak']], JSON_UNESCAPED_UNICODE);
