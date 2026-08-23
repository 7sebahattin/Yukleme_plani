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
            // Sprint Malzeme-XX: max_pallet_count — bir malzemenin toplu ekleme/şablonda
            // bir seferde en fazla kaç palete uygulanacağını sınırlar (NULL = sınırsız,
            // seçilen tüm paletlere eklenir).
            try {
                $has_maxpc = (bool)$pdo->query("SHOW COLUMNS FROM `material_definitions` LIKE 'max_pallet_count'")->fetchColumn();
                if (!$has_maxpc) {
                    $pdo->exec("ALTER TABLE `material_definitions` ADD COLUMN `max_pallet_count` INT NULL");
                    $has_maxpc = true;
                }
                if ($has_maxpc) {
                    // Eskiden "casus" ismi geçen malzemeler kod içinde hardcoded olarak
                    // yalnız kaydın ilk paletine eklenirdi (api_bulk_material.php). Bu davranış
                    // artık max_pallet_count=1 ile birebir aynı şekilde genel sistemden sağlanır.
                    // Kolonda HİÇ değer girilmemişse (hiçbir satırda NOT NULL yoksa) bir kereliğine
                    // seed edilir — böylece kolon ALTER yetkisizlik yüzünden ilk seferde eklenemeyip
                    // migrate.php'den elle eklense bile seed kaçırılmaz; sonradan admin bir malzemeyi
                    // bilinçli "sınırsız" (NULL) yaparsa bir daha dokunulmaz (o zaman en az bir satırda
                    // değer olacağı için koşul artık tetiklenmez).
                    $any_maxpc_set = (int)$pdo->query("SELECT COUNT(*) FROM material_definitions WHERE max_pallet_count IS NOT NULL")->fetchColumn();
                    if ($any_maxpc_set === 0) {
                        $casus_rows = $pdo->query("SELECT id, name FROM material_definitions")->fetchAll(PDO::FETCH_ASSOC);
                        $upd_maxpc = $pdo->prepare("UPDATE material_definitions SET max_pallet_count=1 WHERE id=?");
                        foreach ($casus_rows as $cr) {
                            $n = mb_strtolower(trim((string)$cr['name']), 'UTF-8');
                            $n = str_replace("\xCC\x87", '', $n);
                            $n = strtr($n, ['ı'=>'i','ş'=>'s','ç'=>'c','ğ'=>'g','ü'=>'u','ö'=>'o']);
                            if (str_contains($n, 'casus')) $upd_maxpc->execute([$cr['id']]);
                        }
                    }
                }
            } catch (PDOException $mig_e) { /* material_definitions yoksa veya ALTER yetkisi yok — sessizce geç, definitions.php elle tekrar dener */ }
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
            // Çıkış nedenlerini seed et — eski koddaki sabit liste tanımlara taşınır
            // (yalnızca henüz hiç cikis_nedeni tanımı yoksa; sırası korunur)
            try {
                $cn_count = (int)$pdo->query("SELECT COUNT(*) FROM material_definitions WHERE type='cikis_nedeni'")->fetchColumn();
                if ($cn_count === 0) {
                    $ins_cn = $pdo->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES ('cikis_nedeni', ?, 0, 1)");
                    foreach (['ÇIKMA', 'KÜÇÜK BOY (2.)', 'MEYSU', 'Fire', 'Kötü Ürün', 'Çürük', 'Iskarta', 'Numune', 'İç Kullanım', 'Düzeltme', 'Diğer'] as $_cn) {
                        $ins_cn->execute([$_cn]);
                    }
                }
            } catch (PDOException $mig_e) { /* material_definitions yoksa sessiz geç */ }
        }

        // Hesap modülü tabloları
        try { $pdo->query("SELECT 1 FROM `account_transactions` LIMIT 0"); }
        catch (PDOException $_e) {
            $pdo->exec("CREATE TABLE `account_transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL,
                `created_by` INT NULL,
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
                `status` VARCHAR(20) NOT NULL DEFAULT 'submitted',
                `submitted_at` DATETIME NULL,
                `reviewed_by` INT NULL,
                `reviewed_at` DATETIME NULL,
                `review_note` VARCHAR(500) NOT NULL DEFAULT '',
                `paid_at` DATETIME NULL,
                `depo` VARCHAR(150) NOT NULL DEFAULT '',
                `notes` TEXT NOT NULL DEFAULT '',
                `has_files` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_date` (`transaction_date`),
                INDEX `idx_type` (`type`),
                INDEX `idx_accountant` (`is_given_to_accountant`),
                INDEX `idx_at_user` (`user_id`),
                INDEX `idx_at_status` (`status`),
                INDEX `idx_at_depo` (`depo`(80))
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

        // Gideceği Ülke: loading_records'a gidecek_ulke kolonu ekle (idempotent) — Ulaşım'ın yanında, aynı desen
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'gidecek_ulke'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `gidecek_ulke` VARCHAR(100) NOT NULL DEFAULT ''");
            }
        } catch (PDOException $_gum) {
            error_log("[MIGRATION gidecek_ulke] " . $_gum->getMessage());
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

        // ── Eski HKS modülü temizliği ────────────────────────────────────────────
        // Eski "hks/" paneli kaldırıldı; yerine "halkayit/" SPA'sı geldi. Aşağıdaki
        // eski tablolar ve dead permission'lar artık kullanılmıyor — kalıcı silinir.
        // YENİ panelin tabloları (hks_firmalar / hks_taslaklar / hks_gonderilenler /
        // hks_kv) BU LİSTEDE YOKTUR ve korunur; onları halkayit/db.php oluşturur.
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach ([
                'hks_notification_items',
                'hks_notifications',
                'hks_settings',
                'hks_reference_cache',
                'hks_stock',
                'hks_queries',
                'hks_service_logs',
                'hks_mobile_contacts',
                'hks_notification_tokens',
            ] as $_eski_hks) {
                try { $pdo->exec("DROP TABLE IF EXISTS `{$_eski_hks}`"); }
                catch (PDOException $_e) { /* yoksa geç */ }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        } catch (PDOException $_hkse) { error_log('[HKS temizlik] ' . $_hkse->getMessage()); }

        // Eski hks.* yetkileri artık kullanılmıyor (yeni panel records.write ile korunur).
        try {
            $pdo->exec("DELETE FROM role_permissions
                        WHERE permission IN ('hks.read','hks.write','hks.settings','hks.send','hks.export')");
        } catch (PDOException $_e) { /* role_permissions yoksa sessizce geç */ }
        // ── Eski HKS temizliği sonu ──────────────────────────────────────────────

        // Maliyet-Yükleme-01: cost_sheets'e record_id + brut_kg + linked_at ekle (idempotent)
        // record_id: kaynak loading_records bağlantısı (UNIQUE değil — alternatif senaryo/revizyon serbest)
        // linked_at: yalnız ilk oluşturmada NOW() yazılır, asla UPDATE edilmez (bkz. config/cost_link.php)
        try {
            $pdo->query("SELECT 1 FROM `cost_sheets` LIMIT 0");
            if (!(bool)$pdo->query("SHOW COLUMNS FROM `cost_sheets` LIKE 'record_id'")->fetchColumn()) {
                $pdo->exec("ALTER TABLE `cost_sheets`
                    ADD COLUMN `record_id` INT NULL,
                    ADD COLUMN `brut_kg`   DECIMAL(16,3) NOT NULL DEFAULT 0,
                    ADD COLUMN `linked_at` DATETIME NULL,
                    ADD INDEX  `idx_cs_record` (`record_id`)");
            }
        } catch (PDOException $_csm) { /* cost_sheets yoksa (maliyet modülü henüz açılmamış) — sessizce geç */ }

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
