<?php
// record_new.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// Yetim sayfa, hiçbir yerden link yok. Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: records.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Yeni kayıt Yüklemeler / Çıkmalar listesindeki butondan açılır. <a href="records.php">Yüklemeler</a></p>';
