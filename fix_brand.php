<?php
// fix_brand.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// brand kolonu config/db.php açılışta ekler; migrate.php'de de var. Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: migrate.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Bu onarım aracı kaldırıldı. Marka kolonu otomatik eklenir. <a href="migrate.php">Şema Migrasyon</a></p>';
