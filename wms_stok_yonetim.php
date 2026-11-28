<?php
// wms_stok_yonetim.php — KALDIRILDI (v609, sahip kararı: ERP Faz 2/3/4 modülleri kaldırıldı).
// Deploy repodan silinen dosyayı sunucudan SİLMEZ → 410 tombstone. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: index.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Ürün & Malzeme Stok / WMS sayfası kaldırıldı. <a href="index.php">Ana Sayfa</a></p>';
