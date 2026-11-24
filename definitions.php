<?php
// definitions.php — KALDIRILDI (v574, sahip kararı: Tanımlar eski sistemin parçası).
// Depo yönetimi artık Yönetim › Depo Tanımları (depo_tanimlari.php). Eski tanım verisi (material_definitions) tabloda kalır.
// Deploy repodan silinen dosyayı sunucudan SİLMEZ → 410 tombstone. DB/oturum/kabuk erişimi YOK.
// Kalıcı yol: index.php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo '<!doctype html><meta charset="utf-8"><title>Kaldırıldı</title>'
    . '<p>Tanımlar sayfası kaldırıldı. Depo yönetimi: Yönetim › Depo Tanımları. <a href="index.php">Ana Sayfa</a></p>';
