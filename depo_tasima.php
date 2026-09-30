<?php
// depo_tasima.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// Depo geçişi tamamlandı; ad eşitleme definitions.php'de otomatik (sync_depot_name_in_data). Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: index.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Depo taşıma aracı kaldırıldı. Depo adı değişikliği Tanımlar ekranında otomatik yayılır. <a href="index.php">Ana Sayfa</a></p>';
