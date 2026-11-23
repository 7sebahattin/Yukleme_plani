<?php
// stok_donusum_fisleri.php — KALDIRILDI (v555, sahip kararı: stok dönüşümü kullanılmayacak).
// Deploy repodan silinen dosyayı sunucudan SİLMEZ → 410 tombstone. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: stok_kartlari.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Stok Dönüşüm Fişleri sayfası kaldırıldı. <a href="stok_kartlari.php">Stok Kartları</a></p>';
