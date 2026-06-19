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

// PHP oturumunu erken başlat — HKS modülü firma seçimini $_SESSION'da tutar.
// Uygulamanın kendi auth çerezi (asya_session) ayrıdır; PHP session yalnızca
// csrf/firma seçimi için lazy başlatılıyordu. Firma seçimini $_SESSION'a yazıp
// okumadan ÖNCE session'ın aktif olması şart (yoksa veri kalıcı olmaz).
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

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

        'hks_reference_cache' => "CREATE TABLE IF NOT EXISTS `hks_reference_cache` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `ref_type`        VARCHAR(50)  NOT NULL DEFAULT '',
            `ref_code`        VARCHAR(100) NOT NULL DEFAULT '',
            `ref_name`        VARCHAR(300) NOT NULL DEFAULT '',
            `ref_parent_code` VARCHAR(100) NULL,
            `raw_json`        TEXT NULL,
            `synced_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY `uq_ref_type_code` (`ref_type`, `ref_code`),
            INDEX `idx_hksrc_type`   (`ref_type`),
            INDEX `idx_hksrc_parent` (`ref_parent_code`(50)),
            INDEX `idx_hksrc_active` (`is_active`)
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

    // hks_settings kolon migrasyonu — live sunucuda eski şema varsa eksik kolonları ekle.
    try {
        $pdo->query("SELECT sender_name, default_depo, default_il, default_ilce,
                            timeout_seconds, live_send_enabled,
                            genel_wsdl_url, bildirim_wsdl_url,
                            last_test_at, last_test_ok, last_test_message, updated_at
                     FROM `hks_settings` LIMIT 0");
    } catch (PDOException $_probe) {
        foreach ([
            "ALTER TABLE `hks_settings` ADD COLUMN `sender_name`         VARCHAR(200) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_depo`        VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_il`          VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_ilce`        VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `timeout_seconds`     INT NOT NULL DEFAULT 30",
            "ALTER TABLE `hks_settings` ADD COLUMN `live_send_enabled`   TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_settings` ADD COLUMN `genel_wsdl_url`      VARCHAR(500) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_settings` ADD COLUMN `bildirim_wsdl_url`   VARCHAR(500) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_at`        DATETIME NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_ok`        TINYINT(1) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_message`   VARCHAR(500) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
    }

    // hks_settings çok-firma desteği — firma_adi kolonu
    try {
        $pdo->query("SELECT firma_adi FROM `hks_settings` LIMIT 0");
    } catch (PDOException) {
        try {
            $pdo->exec("ALTER TABLE `hks_settings` ADD COLUMN `firma_adi` VARCHAR(200) NOT NULL DEFAULT 'Firma 1' AFTER `id`");
        } catch (PDOException) { /* zaten var */ }
    }

    // hks_notifications kolon migrasyonu — eski şema ile deploy edilmiş sunucularda eksik kolonlar olabilir.
    try {
        $pdo->query("SELECT firma, direction, notification_type, sifat, urun_cinsi,
                            depo, il, ilce, belde, uretici_ad, uretici_tc_vkn,
                            alici_ad, alici_tc_vkn, sevk_tarihi, arac_plaka, belge_no,
                            reference_kunye_no, hks_bildirim_no, hks_kunye_no,
                            validation_errors_json, request_json, response_json, last_error,
                            checked_at, checked_by, send_attempt_count, sent_at
                     FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe) {
        // Bazı kolonlar eksik — hepsini tek tek ekle (zaten varsa hata sessizce geçilir)
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `firma`                  VARCHAR(200) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_notifications` ADD COLUMN `urun`                   VARCHAR(200) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_notifications` ADD COLUMN `miktar`                 DECIMAL(14,3) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `birim`                  VARCHAR(20) NOT NULL DEFAULT 'KG'",
            "ALTER TABLE `hks_notifications` ADD COLUMN `direction`              VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `notification_type`      VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `sifat`                  VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `urun_cinsi`             VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `depo`                   VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `il`                     VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `ilce`                   VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `belde`                  VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `uretici_ad`             VARCHAR(200) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `uretici_tc_vkn`         VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `alici_ad`               VARCHAR(200) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `alici_tc_vkn`           VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `sevk_tarihi`            DATE NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `arac_plaka`             VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `belge_no`               VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_no`     VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `hks_bildirim_no`        VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `hks_kunye_no`           VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `validation_errors_json` TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `request_json`           TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `response_json`          TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `last_error`             TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `checked_at`             DATETIME NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `checked_by`             INT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `send_attempt_count`     INT NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `sent_at`                DATETIME NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `source_type`            VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `source_id`              INT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `created_by`             INT NULL",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
        // Status ENUM genişletme — checked ve send_pending ekle (idempotent)
        try {
            $pdo->exec("ALTER TABLE `hks_notifications`
                MODIFY COLUMN `status`
                ENUM('draft','ready','checked','send_pending','sent','failed','cancelled')
                NOT NULL DEFAULT 'draft'");
        } catch (PDOException $_ce) { /* zaten genişletilmiş */ }
    }
})();
