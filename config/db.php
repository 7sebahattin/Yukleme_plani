<?php
// =========================================================
// config/db.php  –  SADECE veritabanı bağlantısı
// Diğer yardımcılar config/helpers.php içindedir.
// =========================================================

declare(strict_types=1);

date_default_timezone_set('Europe/Istanbul');

// Yerel ortam sabitleri (HKS_CRED_KEY vb.) — .gitignore'da
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

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
            // Sprint Depo-02: depo rengi — material_definitions.color (opsiyonel, NULL = otomatik palet)
            try {
                $has_color = (bool)$pdo->query("SHOW COLUMNS FROM `material_definitions` LIKE 'color'")->fetchColumn();
                if (!$has_color) {
                    $pdo->exec("ALTER TABLE `material_definitions` ADD COLUMN `color` VARCHAR(7) NULL");
                }
            } catch (PDOException $mig_e) { /* material_definitions yoksa — sessizce geç */ }
            // Başlangıç marka tanımlarını seed et (yalnızca henüz hiç marka tanımı yoksa)
            try {
                $brand_count = (int)$pdo->query("SELECT COUNT(*) FROM material_definitions WHERE type='marka'")->fetchColumn();
                if ($brand_count === 0) {
                    $ins_brand = $pdo->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES ('marka', ?, 0, 1)");
                    foreach (['ASYA', 'URAL', 'URAS', 'AGRO'] as $_b) {
                        $ins_brand->execute([$_b]);
                    }
                }
            } catch (PDOException $mig_e) { /* material_definitions yoksa sessiz geç */ }
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

        // Sprint Kantar-01: kantar_fisleri'ne reported_at + reported_by ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `kantar_fisleri` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `kantar_fisleri` LIKE 'reported_at'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `kantar_fisleri`
                    ADD COLUMN `reported_at` DATETIME NULL,
                    ADD COLUMN `reported_by` INT NULL");
            }
        } catch (PDOException $_km) { /* kantar_fisleri yoksa — sessizce geç */ }

        // Sprint Günlük-03: loading_records'a reported_at + reported_by ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_records`
                    ADD COLUMN `reported_at` DATETIME NULL,
                    ADD COLUMN `reported_by` INT NULL");
            }
        } catch (PDOException $_lrm) { /* loading_records yoksa — sessizce geç */ }

        // Sprint XZ-01 (fixed XZ-03): daily_reports — CREATE TABLE IF NOT EXISTS (idempotent + hata loglanır)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `daily_reports` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `report_type`   VARCHAR(5) NOT NULL,
                `report_date`   DATE NULL,
                `date_from`     DATE NULL,
                `date_to`       DATE NULL,
                `title`         VARCHAR(200) NOT NULL DEFAULT '',
                `note`          TEXT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by`    INT NULL,
                `closed_at`     DATETIME NULL,
                `status`        VARCHAR(20) NOT NULL DEFAULT 'final',
                `snapshot_json` LONGTEXT NULL,
                `pdf_path`      VARCHAR(500) NULL,
                INDEX `idx_dr_type`    (`report_type`),
                INDEX `idx_dr_date`    (`report_date`),
                INDEX `idx_dr_created` (`created_at`),
                INDEX `idx_dr_by`      (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $_xz_dr) {
            error_log("[XZ MIGRATION] daily_reports CREATE TABLE failed: " . $_xz_dr->getMessage());
        }

        // Sprint XZ-01 (fixed XZ-03): daily_report_items — CREATE TABLE IF NOT EXISTS
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `daily_report_items` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `report_id`        INT NOT NULL,
                `item_type`        VARCHAR(30) NOT NULL,
                `source_table`     VARCHAR(60) NOT NULL,
                `source_id`        INT NOT NULL,
                `source_detail_id` INT NULL,
                `snapshot_json`    LONGTEXT NULL,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_dri_report` (`report_id`),
                INDEX `idx_dri_type`   (`item_type`),
                INDEX `idx_dri_source` (`source_table`, `source_id`),
                INDEX `idx_dri_detail` (`source_detail_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $_xz_dri) {
            error_log("[XZ MIGRATION] daily_report_items CREATE TABLE failed: " . $_xz_dri->getMessage());
        }

        // Sprint XZ-01: kantar_fisleri'ne report_id ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `kantar_fisleri` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `kantar_fisleri` LIKE 'report_id'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `report_id` INT NULL");
            }
        } catch (PDOException $_km2) { /* kantar_fisleri yoksa — sessizce geç */ }

        // Sprint XZ-01: loading_records'a report_id ekle — type='cikma' için (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'report_id'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `report_id` INT NULL");
            }
        } catch (PDOException $_lrm2) { /* loading_records yoksa — sessizce geç */ }

        // Sprint ÜrünSahibi-01: loading_records'a urun_sahibi_id ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'urun_sahibi_id'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `urun_sahibi_id` INT NULL DEFAULT NULL");
            }
        } catch (PDOException $_usm) { /* loading_records yoksa — sessizce geç */ }

        // Ulaşım-01: loading_records'a ulasim kolonu ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'ulasim'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `ulasim` VARCHAR(100) NOT NULL DEFAULT ''");
            }
        } catch (PDOException $_ulm) {
            error_log("[MIGRATION ulasim] " . $_ulm->getMessage());
        }

        // Sprint XZ-01: loading_pallets'e reported_at + reported_by + report_id ekle (idempotent)
        try {
            $pdo->query("SELECT 1 FROM `loading_pallets` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_pallets` LIKE 'reported_at'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_pallets`
                    ADD COLUMN `reported_at` DATETIME NULL,
                    ADD COLUMN `reported_by` INT NULL,
                    ADD COLUMN `report_id`   INT NULL");
            }
        } catch (PDOException $_lpm) { /* loading_pallets yoksa — sessizce geç */ }

        // Performans indexleri — idempotent (duplicate key veya tablo yok → sessizce geçilir)
        foreach ([
            "ALTER TABLE `loading_records` ADD INDEX `idx_type` (`type`)",
            "ALTER TABLE `loading_records` ADD INDEX `idx_tarih_type` (`tarih`, `type`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_source` (`source_type`, `source_id`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_material_id` (`material_id`)",
        ] as $_idx_sql) {
            try { $pdo->exec($_idx_sql); } catch (PDOException $_e) { /* var veya tablo yok */ }
        }

        // ── HKS-Core-01: Hal Bildirimi Modülü Tabloları ──────────────────────────
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_settings` (
                `id`                    INT AUTO_INCREMENT PRIMARY KEY,
                `environment`           ENUM('test','live') NOT NULL DEFAULT 'test',
                `username`              VARCHAR(100) NOT NULL DEFAULT '',
                `password_enc`          TEXT NOT NULL DEFAULT '',
                `service_password_enc`  TEXT NOT NULL DEFAULT '',
                `security_word_enc`     TEXT NULL,
                `sender_name`           VARCHAR(200) NULL,
                `default_depo`          VARCHAR(100) NULL,
                `default_il`            VARCHAR(100) NULL,
                `default_ilce`          VARCHAR(100) NULL,
                `timeout_seconds`       INT NOT NULL DEFAULT 30,
                `live_send_enabled`     TINYINT(1) NOT NULL DEFAULT 0,
                `genel_wsdl_url`        VARCHAR(500) NOT NULL DEFAULT '',
                `bildirim_wsdl_url`     VARCHAR(500) NOT NULL DEFAULT '',
                `last_test_at`          DATETIME NULL,
                `last_test_ok`          TINYINT(1) NULL,
                `last_test_message`     VARCHAR(500) NULL,
                `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Eski hks_settings tablosuna yeni kolonları ekle (idempotent)
            foreach ([
                "ALTER TABLE `hks_settings` ADD COLUMN `sender_name` VARCHAR(200) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `default_depo` VARCHAR(100) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `default_il` VARCHAR(100) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `default_ilce` VARCHAR(100) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `timeout_seconds` INT NOT NULL DEFAULT 30",
                "ALTER TABLE `hks_settings` ADD COLUMN `live_send_enabled` TINYINT(1) NOT NULL DEFAULT 0",
                "ALTER TABLE `hks_settings` ADD COLUMN `genel_wsdl_url` VARCHAR(500) NOT NULL DEFAULT ''",
                "ALTER TABLE `hks_settings` ADD COLUMN `bildirim_wsdl_url` VARCHAR(500) NOT NULL DEFAULT ''",
                "ALTER TABLE `hks_settings` ADD COLUMN `last_test_at` DATETIME NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `last_test_ok` TINYINT(1) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `last_test_message` VARCHAR(500) NULL",
                "ALTER TABLE `hks_settings` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            ] as $_hkscol) {
                try { $pdo->exec($_hkscol); } catch (PDOException $_e) { /* zaten var */ }
            }
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_settings] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_reference_cache` (
                `id`              INT AUTO_INCREMENT PRIMARY KEY,
                `ref_type`        VARCHAR(50) NOT NULL,
                `ref_code`        VARCHAR(100) NOT NULL,
                `ref_name`        VARCHAR(300) NOT NULL DEFAULT '',
                `ref_parent_code` VARCHAR(100) NULL,
                `raw_json`        TEXT NULL,
                `synced_at`       DATETIME NULL,
                `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY `uq_ref_type_code` (`ref_type`, `ref_code`),
                INDEX `idx_ref_type`   (`ref_type`),
                INDEX `idx_ref_parent` (`ref_parent_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_reference_cache] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_notifications` (
                `id`                     INT AUTO_INCREMENT PRIMARY KEY,
                `local_no`               VARCHAR(30) NOT NULL DEFAULT '',
                `source_type`            VARCHAR(50) NULL,
                `source_id`              INT NULL,
                `direction`              VARCHAR(20) NULL,
                `notification_type`      VARCHAR(100) NULL,
                `sifat`                  VARCHAR(100) NULL,
                `firma`                  VARCHAR(200) NOT NULL DEFAULT '',
                `urun`                   VARCHAR(200) NOT NULL DEFAULT '',
                `urun_cinsi`             VARCHAR(100) NULL,
                `miktar`                 DECIMAL(14,3) NOT NULL DEFAULT 0,
                `birim`                  VARCHAR(20) NOT NULL DEFAULT 'KG',
                `depo`                   VARCHAR(100) NULL,
                `il`                     VARCHAR(100) NULL,
                `ilce`                   VARCHAR(100) NULL,
                `belde`                  VARCHAR(100) NULL,
                `uretici_ad`             VARCHAR(200) NULL,
                `uretici_tc_vkn`         VARCHAR(20) NULL,
                `alici_ad`               VARCHAR(200) NULL,
                `alici_tc_vkn`           VARCHAR(20) NULL,
                `sevk_tarihi`            DATE NULL,
                `arac_plaka`             VARCHAR(50) NULL,
                `belge_no`               VARCHAR(100) NULL,
                `reference_kunye_no`     VARCHAR(100) NULL,
                `hks_bildirim_no`        VARCHAR(100) NULL,
                `hks_kunye_no`           VARCHAR(100) NULL,
                `status`                 ENUM('draft','ready','checked','send_pending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
                `validation_errors_json` TEXT NULL,
                `request_json`           TEXT NULL,
                `response_json`          TEXT NULL,
                `last_error`             TEXT NULL,
                `checked_at`             DATETIME NULL,
                `checked_by`             INT NULL,
                `send_attempt_count`     INT NOT NULL DEFAULT 0,
                `sent_at`                DATETIME NULL,
                `created_by`             INT NULL,
                `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_hksn_status`   (`status`),
                INDEX `idx_hksn_firma`    (`firma`(50)),
                INDEX `idx_hksn_local_no` (`local_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // HKS-Safety-01: durum akışı genişletme + kontrol kolonları (idempotent)
            try {
                $pdo->exec("ALTER TABLE `hks_notifications`
                    MODIFY COLUMN `status`
                    ENUM('draft','ready','checked','send_pending','sent','failed','cancelled')
                    NOT NULL DEFAULT 'draft'");
            } catch (PDOException $_e) { /* zaten genişletilmiş */ }
            foreach ([
                "ALTER TABLE `hks_notifications` ADD COLUMN `sifat` VARCHAR(100) NULL",
                "ALTER TABLE `hks_notifications` ADD COLUMN `checked_at` DATETIME NULL",
                "ALTER TABLE `hks_notifications` ADD COLUMN `checked_by` INT NULL",
                "ALTER TABLE `hks_notifications` ADD COLUMN `send_attempt_count` INT NOT NULL DEFAULT 0",
            ] as $_hkscol) {
                try { $pdo->exec($_hkscol); } catch (PDOException $_e) { /* zaten var */ }
            }
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_notifications] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_notification_items` (
                `id`              INT AUTO_INCREMENT PRIMARY KEY,
                `notification_id` INT NOT NULL,
                `urun_code`       VARCHAR(50) NULL,
                `urun_name`       VARCHAR(200) NOT NULL DEFAULT '',
                `miktar`          DECIMAL(14,3) NOT NULL DEFAULT 0,
                `birim_code`      VARCHAR(20) NULL,
                `birim_name`      VARCHAR(50) NULL,
                `ambalaj`         VARCHAR(100) NULL,
                `kalite`          VARCHAR(100) NULL,
                `raw_json`        TEXT NULL,
                INDEX `idx_hksni_notif` (`notification_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_notification_items] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_stock` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_stock] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_queries` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `query_type`    ENUM('kunye','bildirim','referans_kunye') NOT NULL,
                `query_value`   VARCHAR(200) NOT NULL DEFAULT '',
                `result_status` VARCHAR(50) NOT NULL DEFAULT '',
                `result_json`   TEXT NULL,
                `created_by`    INT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_hksq_type` (`query_type`),
                INDEX `idx_hksq_date` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_queries] ' . $_hkse->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_service_logs` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_hkse) { error_log('[HKS mig hks_service_logs] ' . $_hkse->getMessage()); }

        // HKS yetkileri — admin ve operator rolleri için (idempotent)
        foreach (['hks.read','hks.write','hks.settings','hks.send','hks.export'] as $_hksperm) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO role_permissions (role_id, permission)
                     SELECT r.id, ? FROM roles r WHERE r.slug IN ('admin')"
                )->execute([$_hksperm]);
            } catch (PDOException $_e) { /* role_permissions yok veya zaten var */ }
        }
        foreach (['hks.read','hks.write','hks.export'] as $_hksperm) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO role_permissions (role_id, permission)
                     SELECT r.id, ? FROM roles r WHERE r.slug = 'operator'"
                )->execute([$_hksperm]);
            } catch (PDOException $_e) { /* sessizce geç */ }
        }
        // hks.settings ve hks.send sadece admin — operator varsa sil (idempotent)
        try {
            $pdo->exec("DELETE rp FROM role_permissions rp
                        INNER JOIN roles r ON r.id = rp.role_id
                        WHERE r.slug = 'operator'
                        AND rp.permission IN ('hks.settings','hks.send')");
        } catch (PDOException $_e) { /* role_permissions yoksa sessizce geç */ }
        // ── HKS tabloları sonu ──────────────────────────────────────────────────

        // DB-Backup-01: database_backups — yedek takip tablosu (idempotent)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `database_backups` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `backup_date`   DATE NOT NULL,
                `filename`      VARCHAR(255) NOT NULL,
                `file_path`     TEXT NOT NULL,
                `file_size`     BIGINT NULL,
                `method`        VARCHAR(50) NULL,
                `status`        ENUM('success','failed') NOT NULL DEFAULT 'success',
                `error_message` TEXT NULL,
                `created_by`    INT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `downloaded_at` DATETIME NULL,
                `downloaded_by` INT NULL,
                INDEX `idx_bkp_date`   (`backup_date`),
                INDEX `idx_bkp_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_bkpe) { /* sessizce geç */ }
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
