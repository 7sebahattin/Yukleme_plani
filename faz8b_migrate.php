<?php
// faz8b_migrate.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// Faz 8B migrasyonu migrate.php içinde (pdks_faz8b_migrate). Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: migrate.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Faz 8B migrasyonu artık Şema Migrasyon ekranındadır. <a href="migrate.php">Şema Migrasyon</a></p>';
