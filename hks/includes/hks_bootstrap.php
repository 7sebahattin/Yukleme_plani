<?php
declare(strict_types=1);
// HKS modülü bootstrap — tüm HKS sayfaları bu dosyayı include eder

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/hks_config.php';
require_once __DIR__ . '/hks_security.php';
require_once __DIR__ . '/hks_helpers.php';
require_once __DIR__ . '/hks_payload_builder.php';
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

    // hks_settings — firma_vkn kolonu
    try {
        $pdo->query("SELECT firma_vkn FROM `hks_settings` LIMIT 0");
    } catch (PDOException) {
        try {
            $pdo->exec("ALTER TABLE `hks_settings` ADD COLUMN `firma_vkn` VARCHAR(20) NULL COMMENT 'Firma TC/VKN — Referans Künye sorgularında kullanılır'");
        } catch (PDOException) { /* zaten var */ }
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

    // hks_notifications — ÇEKİRDEK kolonlar (local_no/status/zaman damgaları).
    // Çok eski şemalı sunucularda bu kolonlar olmayabilir ve CREATE TABLE IF NOT EXISTS
    // mevcut tabloyu değiştirmediği için eksik kalır → createNotification "Unknown column
    // 'local_no'" hatası verir. Bunları idempotent olarak ekle.
    // Bu kolonlar hiçbir probe'a dahil değildi (local_no/status/zaman damgaları) veya yalnız
    // eski batch'in ADD listesinde olup probe'da yoktu (source_type/source_id/created_by) →
    // drift etmiş tabloda eksik kalabilir. Hepsini tek probe + idempotent ADD ile garanti et.
    try {
        $pdo->query("SELECT local_no, status, created_at, updated_at, source_type, source_id, created_by
                     FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe_core) {
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `local_no`    VARCHAR(30) NOT NULL DEFAULT '' AFTER `id`",
            "ALTER TABLE `hks_notifications` ADD COLUMN `status`      ENUM('draft','ready','checked','send_pending','sent','failed','cancelled') NOT NULL DEFAULT 'draft'",
            "ALTER TABLE `hks_notifications` ADD COLUMN `source_type` VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `source_id`   INT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `created_by`  INT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE `hks_notifications` ADD COLUMN `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ] as $_core_sql) {
            try { $pdo->exec($_core_sql); } catch (PDOException) { /* zaten var */ }
        }
        try { $pdo->exec("ALTER TABLE `hks_notifications` ADD INDEX `idx_hksn_local_no` (`local_no`)"); } catch (PDOException) { /* index zaten var */ }
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

    // hks_queries.query_type → VARCHAR(50) (ENUM'den genişletme)
    try {
        $pdo->exec("ALTER TABLE `hks_queries` MODIFY COLUMN `query_type` VARCHAR(50) NOT NULL DEFAULT ''");
    } catch (PDOException) { /* already varchar or doesn't exist */ }

    // hks_notifications — yeni e-Bildirim 3-adım form alanları (Sprint HKS-UX-Reset-01)
    // Resmî HKS e-Bildirim ekranındaki tüm alanlar için eksik kolonları idempotent ekle.
    try {
        $pdo->query("SELECT malin_niteligi, malin_turu, birim_fiyat, para_birimi,
                            analize_gonder, gidecek_yer, ihracat_ulke, belge_tipi,
                            gsm, dogum_tarihi, eposta, karsi_sifat, bildirimci_tc_vkn,
                            gidecek_sahibi_tc, gidecek_kayitli_degil, yurt_disi
                     FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe) {
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `malin_niteligi`        VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `malin_turu`            VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `birim_fiyat`           DECIMAL(14,2) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `para_birimi`           VARCHAR(10) NULL DEFAULT 'TL'",
            "ALTER TABLE `hks_notifications` ADD COLUMN `analize_gonder`        TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_yer`           VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `ihracat_ulke`          VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `belge_tipi`            VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gsm`                   VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `dogum_tarihi`          DATE NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `eposta`                VARCHAR(200) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_sifat`           VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `bildirimci_tc_vkn`     VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_sahibi_tc`     VARCHAR(20) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_kayitli_degil` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `yurt_disi`             TINYINT(1) NOT NULL DEFAULT 0",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
    }

    // hks_notifications — Mapping raporundaki eksik koşullu alanlar (Sprint HKS-eBildirim-MissingFields-01)
    //   gelen_ulke         → GelenUlkeId (İthal malda zorunlu)
    //   gidecek_yer_il     → GidecekYerIlId (kayıtsız yurt içi gidecek yerde zorunlu)
    //   gidecek_yer_ilce   → GidecekYerIlceId
    //   gidecek_yer_belde  → GidecekYerBeldeId
    try {
        $pdo->query("SELECT gelen_ulke, gidecek_yer_il, gidecek_yer_ilce, gidecek_yer_belde
                     FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe) {
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `gelen_ulke`        VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_yer_il`    VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_yer_ilce`  VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_yer_belde` VARCHAR(100) NULL",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
    }

    // hks_notifications — gidecek yer işyeri/depo/şube seçimi (Sprint HKS-GidecekIsyeri-Flow-01)
    //   gidecek_yer_isletme_turu → GidecekYerIsletmeTuruId (işletme türü)
    //   gidecek_isyeri_id        → GidecekIsyeriId (HKS işyeri Id'si, depo/şube/hal içi kaynağından)
    //   gidecek_isyeri_tipi      → kaynak tipi (depo|sube|hal_ici_isyeri) — gösterim/teşhis
    //   gidecek_isyeri_adi       → işyeri adı (kullanıcı gösterimi)
    try {
        $pdo->query("SELECT gidecek_yer_isletme_turu, gidecek_isyeri_id, gidecek_isyeri_tipi, gidecek_isyeri_adi
                     FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe) {
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_yer_isletme_turu` VARCHAR(120) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_isyeri_id`        VARCHAR(50)  NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_isyeri_tipi`      VARCHAR(30)  NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `gidecek_isyeri_adi`       VARCHAR(250) NULL",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
    }

    // hks_notifications — HKS kişi/künye doğrulama durumları (Sprint HKS-Workflow-Validation-01)
    //   Karşı taraf TC/VKN, BildirimServisKayitliKisiSorgu ile doğrulanır (KayitliKisiMi + Sifatlari).
    //   NOT: KayitliKisiSorgu ad/ünvan DÖNDÜRMEZ; unvan kolonu yalnız ileride başka kaynak için durur.
    //   Referans künye, BildirimServisReferansKunyeler ile doğrulanır (kalan miktar + ürün bilgileri).
    try {
        $pdo->query("SELECT karsi_kisi_verified, reference_kunye_verified FROM `hks_notifications` LIMIT 0");
    } catch (PDOException $_probe) {
        foreach ([
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_verified`           TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_verified_at`        DATETIME NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_query_json`         TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_hks_unvan`          VARCHAR(250) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_hks_id`             VARCHAR(50) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_hks_sifatlari_json` TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `karsi_kisi_kayitli_degil`      TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_verified`      TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_verified_at`   DATETIME NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_query_json`    TEXT NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_kalan_miktar`  DECIMAL(14,3) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_urun`          VARCHAR(200) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_urun_cinsi`    VARCHAR(100) NULL",
            "ALTER TABLE `hks_notifications` ADD COLUMN `reference_kunye_birim`         VARCHAR(20) NULL",
        ] as $_col_sql) {
            try { $pdo->exec($_col_sql); } catch (PDOException $_ce) { /* zaten var */ }
        }
    }
})();
