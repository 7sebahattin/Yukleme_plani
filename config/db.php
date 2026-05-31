<?php
// =========================================================
// config/db.php  –  SADECE veritabanı bağlantısı
// Diğer yardımcılar config/helpers.php içindedir.
// =========================================================

declare(strict_types=1);

date_default_timezone_set('Europe/Istanbul');

// --- DB AYARLARI ---
const DB_HOST    = 'localhost';
const DB_NAME    = 'yukleme_plani';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Veritabanı bağlantı hatası: ' . htmlspecialchars($e->getMessage()));
        }

        // Auto-migration: loading_records tablosu varsa type kolonu eksikse ekle
        $table_exists = false;
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            $table_exists = true;
        } catch (PDOException $e) { /* tablo yok, fresh install */ }

        if ($table_exists) {
            $has_col = (bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'type'")->fetchColumn();
            if (!$has_col) {
                try {
                    $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'");
                } catch (PDOException $mig_e) {
                    http_response_code(500);
                    die(
                        '<p><b>Veritabanı güncelleme hatası:</b> ' . htmlspecialchars($mig_e->getMessage()) . '</p>'
                        . '<p>Lütfen veritabanı yönetim panelinden (phpMyAdmin vb.) şu SQL\'i çalıştırın:</p>'
                        . '<pre>ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT \'yukleme\';</pre>'
                    );
                }
            }
            // Sprint 34: marka (brand) kolonu — opsiyonel, eski kayıtlar NULL kalır
            $has_brand = (bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'brand'")->fetchColumn();
            if (!$has_brand) {
                try {
                    $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL");
                } catch (PDOException $mig_e) { /* eklenememişse view/form defansif çalışır */ }
            }
        }

        // Hesap modülü tabloları
        try { $pdo->query("SELECT 1 FROM `account_transactions` LIMIT 0"); }
        catch (PDOException $_e) {
            $pdo->exec("CREATE TABLE `account_transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `transaction_date` DATE NOT NULL,
                `transaction_time` TIME NOT NULL DEFAULT '00:00:00',
                `type` ENUM('gelir','gider','havale','nakit') NOT NULL,
                `category` VARCHAR(100) NOT NULL DEFAULT '',
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `currency` VARCHAR(5) NOT NULL DEFAULT 'TRY',
                `payment_method` VARCHAR(30) NOT NULL DEFAULT 'nakit',
                `person_company` VARCHAR(200) NOT NULL DEFAULT '',
                `description` TEXT NOT NULL DEFAULT '',
                `document_no` VARCHAR(100) NOT NULL DEFAULT '',
                `has_invoice` TINYINT(1) NOT NULL DEFAULT 0,
                `is_for_company` TINYINT(1) NOT NULL DEFAULT 1,
                `is_given_to_accountant` TINYINT(1) NOT NULL DEFAULT 0,
                `notes` TEXT NOT NULL DEFAULT '',
                `has_files` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_date` (`transaction_date`),
                INDEX `idx_type` (`type`),
                INDEX `idx_accountant` (`is_given_to_accountant`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE `account_files` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `transaction_id` INT NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `original_name` VARCHAR(255) NOT NULL DEFAULT '',
                `file_type` VARCHAR(50) NOT NULL DEFAULT '',
                `file_size` INT NOT NULL DEFAULT 0,
                `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_tid` (`transaction_id`),
                CONSTRAINT `fk_af_tid` FOREIGN KEY (`transaction_id`)
                    REFERENCES `account_transactions`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // Kantar grubu firma adı migrasyonu (bilinen alias → canonical)
        try {
            $pdo->query("SELECT 1 FROM `kantar_gruplar` LIMIT 0");
            // asya → Asya Fresh
            $pdo->exec("UPDATE `kantar_gruplar` SET grup_adi = 'Asya Fresh' WHERE LOWER(grup_adi) = 'asya'");
            // ck, cihat → Cihat Karaköse
            $pdo->exec("UPDATE `kantar_gruplar` SET grup_adi = 'Cihat Karaköse' WHERE LOWER(grup_adi) IN ('ck', 'cihat')");
        } catch (PDOException $_gm) { /* kantar_gruplar yoksa veya hata — sessizce geç */ }

        // Performans indexleri — idempotent (duplicate key veya tablo yok → sessizce geçilir)
        foreach ([
            "ALTER TABLE `loading_records` ADD INDEX `idx_type` (`type`)",
            "ALTER TABLE `loading_records` ADD INDEX `idx_tarih_type` (`tarih`, `type`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_source` (`source_type`, `source_id`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_material_id` (`material_id`)",
        ] as $_idx_sql) {
            try { $pdo->exec($_idx_sql); } catch (PDOException $_e) { /* var veya tablo yok */ }
        }
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
