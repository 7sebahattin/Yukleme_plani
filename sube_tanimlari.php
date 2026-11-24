<?php
// sube_tanimlari.php — KALDIRILDI (v574, sahip kararı: şube kavramı yok; yalnız depo).
// Deploy repodan silinen dosyayı sunucudan SİLMEZ → 410 tombstone. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: depo_tanimlari.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Şube Tanımları kaldırıldı (şube kavramı yok; yalnız depo). <a href="depo_tanimlari.php">Depo Tanımları</a></p>';
