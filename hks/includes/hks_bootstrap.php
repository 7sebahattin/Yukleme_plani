<?php
declare(strict_types=1);
// HKS modülü bootstrap — tüm HKS sayfaları bu dosyayı include eder

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/hks_config.php';
require_once __DIR__ . '/hks_security.php';
require_once __DIR__ . '/hks_helpers.php';
require_once __DIR__ . '/hks_repository.php';
require_once __DIR__ . '/hks_client.php';
require_once __DIR__ . '/../../config/auth.php';

// HKS tablolarını garantile — config/db.php'nin eski olduğu ortamlarda da çalışır
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    // Eksik tabloları CREATE TABLE IF NOT EXISTS ile oluştur (idempotent)
    $tables = [
        'hks_stock' => "CREATE TABLE IF NOT EXISTS `hks_stock` (
            `id`                     INT AUTO_INCREMENT PRIMARY KEY,
            `stock_key`              VARCHAR(32) NOT NULL DEFAULT '',
            `urun`                   VARCHAR(200) NOT NULL DEFAULT '',
            `urun_code`              VARCHAR(50) NULL,
            `depo`                   VARCHAR(100) NULL,
            `reference_kunye_no`     VARCHAR(100) NULL,
            `hks_kunye_no`           VARCHAR(100) NULL,
            `giris_miktar`           DECIMAL(14,3) NOT NULL DEFAULT 0,
            `cikis_miktar`           DECIMAL(14,3) NOT NULL DEFAULT 0,
            `kalan_miktar`           DECIMAL(14,3) NOT NULL DEFAULT 0,
            `birim`                  VARCHAR(20) NOT NULL DEFAULT 'KG',
            `source_notification_id` INT NULL,
            `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_stock_key` (`stock_key`),
            INDEX `idx_hkss_urun` (`urun`(50)),
            INDEX `idx_hkss_depo` (`depo`(50))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'hks_queries' => "CREATE TABLE IF NOT EXISTS `hks_queries` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `query_type`    ENUM('kunye','bildirim','referans_kunye') NOT NULL,
            `query_value`   VARCHAR(200) NOT NULL DEFAULT '',
            `result_status` VARCHAR(50) NOT NULL DEFAULT '',
            `result_json`   TEXT NULL,
            `created_by`    INT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_hksq_type` (`query_type`),
            INDEX `idx_hksq_date` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'hks_service_logs' => "CREATE TABLE IF NOT EXISTS `hks_service_logs` (
            `id`                 INT AUTO_INCREMENT PRIMARY KEY,
            `service_name`       VARCHAR(100) NOT NULL DEFAULT '',
            `method_name`        VARCHAR(100) NOT NULL DEFAULT '',
            `environment`        VARCHAR(10) NOT NULL DEFAULT 'test',
            `request_safe_json`  TEXT NULL,
            `response_json`      TEXT NULL,
            `is_success`         TINYINT(1) NOT NULL DEFAULT 0,
            `error_code`         VARCHAR(100) NULL,
            `error_message`      TEXT NULL,
            `duration_ms`        INT NOT NULL DEFAULT 0,
            `created_by`         INT NULL,
            `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_hkslog_date`    (`created_at`),
            INDEX `idx_hkslog_success` (`is_success`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $_name => $_sql) {
        try {
            // Tablonun var olup olmadığını hızlıca kontrol et
            $pdo->query("SELECT 1 FROM `{$_name}` LIMIT 0");
        } catch (PDOException $_e) {
            // Tablo yok — oluştur
            try { $pdo->exec($_sql); } catch (PDOException $_ce) {
                error_log("[HKS bootstrap mig {$_name}] " . $_ce->getMessage());
            }
        }
    }
})();
