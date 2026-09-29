<?php
// admin_db_backup_download.php — KALDIRILDI (Sprint DB-Backup-02)
// Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski geçici araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı ekran: admin_db_backups.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Bu geçici araç kaldırıldı. Yedekler için: <a href="admin_db_backups.php">Veritabanı Yedekleri</a></p>';
