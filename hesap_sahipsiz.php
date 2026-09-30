<?php
// hesap_sahipsiz.php — KALDIRILDI (v281 temizlik, 2026-09-30)
// Tüm sahipsiz kayıtlar atandı (canlı sayı 0). Deploy repodan silinen dosyayı sunucudan SİLMEZ; eski araç canlıda
// kalmasın diye içerik bilerek boşaltıldı. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: hesap.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Sahipsiz Kayıtlar ekranı kaldırıldı. Tek kaydın sahibini yönetici, kaydı düzenlerken "Kayıt sahibi" alanından değiştirir. <a href="hesap.php">Hesabım</a></p>';
