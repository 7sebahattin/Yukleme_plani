<?php
// =========================================================
// diagnostik.php - Çıkma kayıt veritabanı durumu
// WEB ERİŞİMİ KAPALI — sadece CLI ile çalıştırın:
//   php scripts/diagnostik.php
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();

$total_cikma   = (int)$pdo->query("SELECT COUNT(*) FROM loading_records WHERE type='cikma'")->fetchColumn();
$total_yukleme = (int)$pdo->query("SELECT COUNT(*) FROM loading_records WHERE type='yukleme'")->fetchColumn();

$no_pallet_cikma = (int)$pdo->query(
    "SELECT COUNT(*) FROM loading_records r
     WHERE r.type='cikma'
       AND NOT EXISTS (SELECT 1 FROM loading_pallets p WHERE p.loading_record_id = r.id)"
)->fetchColumn();

$net_zero_cikma = (int)$pdo->query(
    "SELECT COUNT(*) FROM loading_records r
     WHERE r.type='cikma'
       AND (SELECT COALESCE(SUM(p.net_kg),0) FROM loading_pallets p WHERE p.loading_record_id = r.id) = 0"
)->fetchColumn();

$pal_stats = $pdo->query(
    "SELECT COUNT(*) AS cnt,
            ROUND(SUM(p.net_kg),2) AS tot_net
     FROM loading_pallets p
     JOIN loading_records r ON r.id = p.loading_record_id
     WHERE r.type = 'cikma'"
)->fetch();

echo "=== Çıkma Kayıtları Diagnostik ===" . PHP_EOL;
echo "Toplam çıkma kaydı:  $total_cikma" . PHP_EOL;
echo "Toplam yükleme:      $total_yukleme" . PHP_EOL;
echo "Paletsiz çıkma:      $no_pallet_cikma" . PHP_EOL;
echo "Net=0 çıkma kaydı:  $net_zero_cikma" . PHP_EOL;
echo "Toplam palet:        {$pal_stats['cnt']}" . PHP_EOL;
echo "Toplam net kg:       {$pal_stats['tot_net']}" . PHP_EOL;
