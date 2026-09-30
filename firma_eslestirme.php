<?php
// firma_eslestirme.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// Tek seferlik tedarikçi eşleştirme tamamlandı. Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: index.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Tedarikçi eşleştirme aracı kaldırıldı. <a href="index.php">Ana Sayfa</a></p>';
