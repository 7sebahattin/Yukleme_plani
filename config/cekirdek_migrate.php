<?php
// =========================================================
// config/cekirdek_migrate.php — Çekirdek şema migrasyonu (B-01) &
// Mal Kabul fis_no UNIQUE migrasyonu (B-23)
//
// Bu dosya YALNIZCA migrate.php (admin şema paneli) tarafından yüklenir.
// Normal HTTP isteklerinde HİÇBİR DDL / SHOW COLUMNS / migration UPDATE çalışmaz.
// =========================================================
declare(strict_types=1);

if (!function_exists('cekirdek_tablo_var')) {
    function cekirdek_tablo_var(PDO $pdo, string $tablo): bool
    {
        try {
            $pdo->query("SELECT 1 FROM `$tablo` LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    function cekirdek_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $cols = [];
                foreach ($pdo->query("PRAGMA table_info(`$tablo`)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cols[] = (string)$r['name'];
                }
                return in_array($kolon, $cols, true);
            }
            $cols = $pdo->query("SHOW COLUMNS FROM `$tablo`")->fetchAll(PDO::FETCH_COLUMN);
            return in_array($kolon, $cols, true);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Çekirdek şema hazır mı? (Salt okunur denetim — DDL çalıştırmaz.)
     * @return array{hazir:bool,eksikler:list<string>}
     */
    function cekirdek_sema_durum(PDO $pdo): array
    {
        $gerekliTablolar = [
            'account_transactions', 'account_files', 'daily_reports', 'daily_report_items',
            'database_backups', 'kantar_fisleri', 'kantar_gruplar', 'kantar_kasa_palet_satir',
            'dev_notes', 'material_stock_movements', 'stock_counts',
            'users', 'user_sessions', 'roles', 'role_permissions', 'user_roles',
            'user_depolar', 'audit_log', 'customs_declarations', 'beyan_hks_bildirim', 'hks_eslesme',
        ];
        $eksik = [];
        foreach ($gerekliTablolar as $t) {
            if (!cekirdek_tablo_var($pdo, $t)) $eksik[] = "tablo:$t";
        }
        $kolonKontrol = [
            ['loading_records', 'reported_at'],
            ['loading_records', 'ulasim'],
            ['loading_records', 'gidecek_ulke'],
            ['loading_records', 'locked_at'],
            ['kantar_fisleri', 'reported_at'],
            ['kantar_fisleri', 'report_id'],
            ['kantar_fisleri', 'kasa_dara_total'],
            ['account_transactions', 'status'],
            ['customs_declarations', 'hks_ulke_ad'],
        ];
        foreach ($kolonKontrol as [$t, $c]) {
            if (cekirdek_tablo_var($pdo, $t) && !cekirdek_kolon_var($pdo, $t, $c)) {
                $eksik[] = "kolon:$t.$c";
            }
        }
        return ['hazir' => $eksik === [], 'eksikler' => $eksik];
    }

    /**
     * Eskiden config/db.php içinde her istekte çalışan çekirdek tablo/kolon/seed/indeks
     * migrasyonları. Artık yalnız admin tarafından migrate.php › Çekirdek Şema kartından çalışır.
     * @return list<array{adim:string,durum:string,mesaj:string}>
     */
    function cekirdek_db_migrate(PDO $pdo): array
    {
        $rapor = [];
        $ekle = static function (string $adim, string $durum, string $mesaj) use (&$rapor): void {
            $rapor[] = ['adim' => $adim, 'durum' => $durum, 'mesaj' => $mesaj];
        };

        $table_exists = cekirdek_tablo_var($pdo, 'loading_records');
        if ($table_exists) {
            $lrCols = [
                'type'           => "ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'",
                'brand'          => "ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL",
                'reported_at'    => "ALTER TABLE `loading_records` ADD COLUMN `reported_at` DATETIME NULL, ADD COLUMN `reported_by` INT NULL",
                'report_id'      => "ALTER TABLE `loading_records` ADD COLUMN `report_id` INT NULL",
                'urun_sahibi_id' => "ALTER TABLE `loading_records` ADD COLUMN `urun_sahibi_id` INT NULL DEFAULT NULL",
                'ulasim'         => "ALTER TABLE `loading_records` ADD COLUMN `ulasim` VARCHAR(100) NOT NULL DEFAULT ''",
                'gidecek_ulke'   => "ALTER TABLE `loading_records` ADD COLUMN `gidecek_ulke` VARCHAR(100) NOT NULL DEFAULT ''",
            ];
            foreach ($lrCols as $col => $sql) {
                if (!cekirdek_kolon_var($pdo, 'loading_records', $col)) {
                    try { $pdo->exec($sql); $ekle("loading_records.$col", 'eklendi', 'Kolon eklendi.'); }
                    catch (PDOException $e) { $ekle("loading_records.$col", 'hata', $e->getMessage()); }
                }
            }
        }

        if (cekirdek_tablo_var($pdo, 'material_definitions')) {
            if (!cekirdek_kolon_var($pdo, 'material_definitions', 'color')) {
                try { $pdo->exec("ALTER TABLE `material_definitions` ADD COLUMN `color` VARCHAR(7) NULL"); $ekle('material_definitions.color', 'eklendi', 'Kolon eklendi.'); }
                catch (PDOException $e) { $ekle('material_definitions.color', 'hata', $e->getMessage()); }
            }
            $has_maxpc = cekirdek_kolon_var($pdo, 'material_definitions', 'max_pallet_count');
            if (!$has_maxpc) {
                try {
                    $pdo->exec("ALTER TABLE `material_definitions` ADD COLUMN `max_pallet_count` INT NULL");
                    $has_maxpc = true;
                    $ekle('material_definitions.max_pallet_count', 'eklendi', 'Kolon eklendi.');
                } catch (PDOException $e) { $ekle('material_definitions.max_pallet_count', 'hata', $e->getMessage()); }
            }
            if ($has_maxpc) {
                try {
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
                } catch (PDOException $e) {}
            }
            try {
                $brand_count = (int)$pdo->query("SELECT COUNT(*) FROM material_definitions WHERE type='marka'")->fetchColumn();
                if ($brand_count === 0) {
                    $ins_brand = $pdo->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES ('marka', ?, 0, 1)");
                    foreach (['ASYA', 'URAL', 'URAS', 'AGRO'] as $_b) $ins_brand->execute([$_b]);
                }
            } catch (PDOException $e) {}
            try {
                $cn_count = (int)$pdo->query("SELECT COUNT(*) FROM material_definitions WHERE type='cikis_nedeni'")->fetchColumn();
                if ($cn_count === 0) {
                    $ins_cn = $pdo->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES ('cikis_nedeni', ?, 0, 1)");
                    foreach (['ÇIKMA', 'KÜÇÜK BOY (2.)', 'MEYSU', 'Fire', 'Kötü Ürün', 'Çürük', 'Iskarta', 'Numune', 'İç Kullanım', 'Düzeltme', 'Diğer'] as $_cn) {
                        $ins_cn->execute([$_cn]);
                    }
                }
            } catch (PDOException $e) {}
        }

        // Hesap modülü tabloları (B-01: TEXT NOT NULL DEFAULT '' → TEXT NULL, strict-mode uyumlu)
        if (!cekirdek_tablo_var($pdo, 'account_transactions')) {
            try {
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
                    `description` TEXT NULL,
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
                    `notes` TEXT NULL,
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
                $ekle('account_transactions', 'olusturuldu', 'Tablo oluşturuldu.');
            } catch (PDOException $e) { $ekle('account_transactions', 'hata', $e->getMessage()); }
        }
        if (!cekirdek_tablo_var($pdo, 'account_files')) {
            try {
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
                $ekle('account_files', 'olusturuldu', 'Tablo oluşturuldu.');
            } catch (PDOException $e) { $ekle('account_files', 'hata', $e->getMessage()); }
        }

        // Kantar grubu firma adı normalizasyonu
        if (cekirdek_tablo_var($pdo, 'kantar_gruplar')) {
            try {
                $pdo->exec("UPDATE `kantar_gruplar` SET grup_adi = 'Asya Fresh' WHERE LOWER(grup_adi) = 'asya'");
                $pdo->exec("UPDATE `kantar_gruplar` SET grup_adi = 'Cihat Karaköse' WHERE LOWER(grup_adi) IN ('ck', 'cihat')");
            } catch (PDOException $e) {}
        }

        if (cekirdek_tablo_var($pdo, 'kantar_fisleri')) {
            if (!cekirdek_kolon_var($pdo, 'kantar_fisleri', 'reported_at')) {
                try { $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `reported_at` DATETIME NULL, ADD COLUMN `reported_by` INT NULL"); $ekle('kantar_fisleri.reported_at', 'eklendi', 'Kolonlar eklendi.'); }
                catch (PDOException $e) { $ekle('kantar_fisleri.reported_at', 'hata', $e->getMessage()); }
            }
            if (!cekirdek_kolon_var($pdo, 'kantar_fisleri', 'report_id')) {
                try { $pdo->exec("ALTER TABLE `kantar_fisleri` ADD COLUMN `report_id` INT NULL"); $ekle('kantar_fisleri.report_id', 'eklendi', 'Kolon eklendi.'); }
                catch (PDOException $e) { $ekle('kantar_fisleri.report_id', 'hata', $e->getMessage()); }
            }
        }

        // daily_reports + daily_report_items + database_backups
        $tablolar = [
            'daily_reports' => "CREATE TABLE IF NOT EXISTS `daily_reports` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'daily_report_items' => "CREATE TABLE IF NOT EXISTS `daily_report_items` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'database_backups' => "CREATE TABLE IF NOT EXISTS `database_backups` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tablolar as $tAd => $ddl) {
            if (!cekirdek_tablo_var($pdo, $tAd)) {
                try { $pdo->exec($ddl); $ekle($tAd, 'olusturuldu', 'Tablo oluşturuldu.'); }
                catch (PDOException $e) { $ekle($tAd, 'hata', $e->getMessage()); }
            }
        }

        if (cekirdek_tablo_var($pdo, 'loading_pallets') && !cekirdek_kolon_var($pdo, 'loading_pallets', 'reported_at')) {
            try {
                $pdo->exec("ALTER TABLE `loading_pallets` ADD COLUMN `reported_at` DATETIME NULL, ADD COLUMN `reported_by` INT NULL, ADD COLUMN `report_id` INT NULL");
                $ekle('loading_pallets.reported_at', 'eklendi', 'Kolonlar eklendi.');
            } catch (PDOException $e) { $ekle('loading_pallets.reported_at', 'hata', $e->getMessage()); }
        }

        if (cekirdek_tablo_var($pdo, 'cost_sheets') && !cekirdek_kolon_var($pdo, 'cost_sheets', 'record_id')) {
            try {
                $pdo->exec("ALTER TABLE `cost_sheets` ADD COLUMN `record_id` INT NULL, ADD COLUMN `brut_kg` DECIMAL(16,3) NOT NULL DEFAULT 0, ADD COLUMN `linked_at` DATETIME NULL, ADD INDEX `idx_cs_record` (`record_id`)");
                $ekle('cost_sheets.record_id', 'eklendi', 'Kolonlar eklendi.');
            } catch (PDOException $e) { $ekle('cost_sheets.record_id', 'hata', $e->getMessage()); }
        }

        foreach ([
            "ALTER TABLE `loading_records` ADD INDEX `idx_type` (`type`)",
            "ALTER TABLE `loading_records` ADD INDEX `idx_tarih_type` (`tarih`, `type`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_source` (`source_type`, `source_id`)",
            "ALTER TABLE `material_stock_movements` ADD INDEX `idx_material_id` (`material_id`)",
        ] as $_idx_sql) {
            try { $pdo->exec($_idx_sql); } catch (PDOException $e) { /* var veya tablo yok */ }
        }

        return $rapor;
    }

    /** Çekirdek + helpers + hesap migrasyonlarını tek adımda çalıştırır (yalnız migrate.php). */
    function cekirdek_sema_calistir(PDO $pdo): array
    {
        $rapor = cekirdek_db_migrate($pdo);
        if (function_exists('cekirdek_helpers_migrate')) {
            $rapor = array_merge($rapor, cekirdek_helpers_migrate($pdo));
        }
        if (function_exists('hesap_migrate')) {
            $rapor = array_merge($rapor, hesap_migrate(true));
        }
        if ($rapor === []) {
            $rapor[] = ['adim' => 'cekirdek_sema', 'durum' => 'var', 'mesaj' => 'Tüm çekirdek tablolar ve kolonlar zaten mevcut.'];
        }
        return $rapor;
    }

    /**
     * B-23: kantar_fisleri.fis_no UNIQUE indeks durumu (salt okunur).
     * @return array{tablo_var:bool,indeks_var:bool,bos_say:int,mukerrer_say:int,ornekler:list<string>}
     */
    function kantar_fisno_uniq_durum(PDO $pdo): array
    {
        $out = ['tablo_var' => false, 'indeks_var' => false, 'bos_say' => 0, 'mukerrer_say' => 0, 'ornekler' => []];
        if (!cekirdek_tablo_var($pdo, 'kantar_fisleri')) return $out;
        $out['tablo_var'] = true;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query("PRAGMA index_list('kantar_fisleri')")->fetchAll(PDO::FETCH_ASSOC) as $ix) {
                    if (!empty($ix['unique']) && (string)$ix['name'] === 'uq_kf_fis_no') {
                        $out['indeks_var'] = true;
                        break;
                    }
                }
            } else {
                $st = $pdo->prepare("SHOW INDEX FROM `kantar_fisleri` WHERE Key_name = 'uq_kf_fis_no' AND Non_unique = 0");
                $st->execute();
                $out['indeks_var'] = $st->rowCount() > 0;
            }
            $out['bos_say'] = (int)$pdo->query("SELECT COUNT(*) FROM `kantar_fisleri` WHERE fis_no IS NULL OR TRIM(fis_no) = ''")->fetchColumn();
            $dup = $pdo->query("SELECT fis_no, COUNT(*) AS n FROM `kantar_fisleri` WHERE fis_no IS NOT NULL AND TRIM(fis_no) <> '' GROUP BY fis_no HAVING COUNT(*) > 1 LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $out['mukerrer_say'] = count($dup);
            foreach ($dup as $r) $out['ornekler'][] = (string)$r['fis_no'] . ' (' . (int)$r['n'] . '×)';
        } catch (PDOException $e) {
            error_log('[kantar_fisno_uniq_durum] ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * B-23: kantar_fisleri.fis_no UNIQUE indeks ekler (yalnız boş/mükerrer kayıt yoksa; veri değiştirmez).
     * @return array{ok:bool,durum:string,mesaj:string}
     */
    function kantar_fisno_uniq_migrate(PDO $pdo): array
    {
        $d = kantar_fisno_uniq_durum($pdo);
        if (!$d['tablo_var']) return ['ok' => false, 'durum' => 'hata', 'mesaj' => 'kantar_fisleri tablosu yok.'];
        if ($d['indeks_var']) return ['ok' => true, 'durum' => 'var', 'mesaj' => 'uq_kf_fis_no UNIQUE indeksi zaten mevcut.'];
        if ($d['bos_say'] > 0 || $d['mukerrer_say'] > 0) {
            return [
                'ok' => false,
                'durum' => 'engel',
                'mesaj' => 'İndeks eklenemedi — önce mevcut verideki '
                    . $d['bos_say'] . ' boş ve ' . $d['mukerrer_say'] . ' mükerrer fis_no kaydını düzeltin'
                    . ($d['ornekler'] ? ' (' . implode(', ', $d['ornekler']) . ')' : '') . '.',
            ];
        }
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $pdo->exec('CREATE UNIQUE INDEX `uq_kf_fis_no` ON `kantar_fisleri` (`fis_no`)');
            } else {
                $pdo->exec('ALTER TABLE `kantar_fisleri` ADD UNIQUE KEY `uq_kf_fis_no` (`fis_no`)');
            }
            return ['ok' => true, 'durum' => 'olusturuldu', 'mesaj' => 'uq_kf_fis_no UNIQUE indeksi eklendi.'];
        } catch (PDOException $e) {
            error_log('[kantar_fisno_uniq_migrate] ' . $e->getMessage());
            return ['ok' => false, 'durum' => 'hata', 'mesaj' => 'İndeks eklenemedi: ' . $e->getMessage()];
        }
    }
}
