<?php
// sube_degistirme.php — KALDIRILDI (v548, menü sadeleştirme). Yer tutucu sayfaydı; içerik yoktu.
// Deploy repodan silinen dosyayı sunucudan SİLMEZ → 410 tombstone. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: depo_sec.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Şube Değiştirme sayfası kaldırıldı. <a href="depo_sec.php">Depo seçimi</a></p>';
