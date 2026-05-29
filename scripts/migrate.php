<?php
// =========================================================
// migrate.php - Tek seferlik veritabanı güncelleme
// WEB ERİŞİMİ KAPALI — sadece CLI ile çalıştırın:
//   php scripts/migrate.php
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();
$log = [];

// 1. type kolonu
try {
    $has = $pdo->query("SHOW COLUMNS FROM loading_records LIKE 'type'")->fetchColumn();
    if (!$has) {
        $pdo->exec("ALTER TABLE loading_records ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'");
        $log[] = '✅ type kolonu eklendi.';
    } else {
        $log[] = 'ℹ️ type kolonu zaten mevcut.';
    }
} catch (PDOException $e) {
    $log[] = '❌ type kolonu hatası: ' . $e->getMessage();
}

// 2. material_templates tablosu
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS material_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = '✅ material_templates tablosu hazır.';
} catch (PDOException $e) {
    $log[] = '❌ material_templates hatası: ' . $e->getMessage();
}

// 3. material_template_items tablosu
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS material_template_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NOT NULL,
        material_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        FOREIGN KEY (template_id) REFERENCES material_templates(id) ON DELETE CASCADE,
        FOREIGN KEY (material_id) REFERENCES material_definitions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $log[] = '✅ material_template_items tablosu hazır.';
} catch (PDOException $e) {
    $log[] = '❌ material_template_items hatası: ' . $e->getMessage();
}

foreach ($log as $line) {
    echo $line . PHP_EOL;
}
echo 'Tamamlandı.' . PHP_EOL;
