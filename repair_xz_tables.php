<?php
// repair_xz_tables.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// daily_reports/items config/db.php açılışta CREATE IF NOT EXISTS. Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: reports.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Bu onarım aracı kaldırıldı. Rapor arşiv tabloları sayfa açılışında otomatik oluşturulur. <a href="reports.php">Raporlar</a></p>';
