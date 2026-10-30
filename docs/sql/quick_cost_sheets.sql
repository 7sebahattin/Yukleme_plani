-- Hızlı Maliyet v1 — yalnız yeni ve izole tablo.
-- Uygulama tarafından otomatik çalıştırılmaz. Mevcut/veri tabanında uygulama
-- yalnız somut teslim sonrası kullanıcının son açık migration onayıyla yapılır.
-- cost_sheets / loading_* / stok / cari tablolarına DDL veya DML YOKTUR.
CREATE TABLE IF NOT EXISTS `quick_cost_sheets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product` VARCHAR(150) NOT NULL,
    `sheet_date` DATE NOT NULL,
    `operation_type` VARCHAR(12) NOT NULL,
    `method` VARCHAR(12) NOT NULL,
    `net_kg` DECIMAL(14,4) NOT NULL,
    `document_json` LONGTEXT NOT NULL,
    `total` DECIMAL(14,2) NOT NULL,
    `unit_cost` DECIMAL(22,6) NOT NULL,
    `notes` TEXT NOT NULL,
    `depo` VARCHAR(150) NOT NULL,
    `created_by` INT NOT NULL,
    `updated_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_quick_cost_depot_date` (`depo`, `sheet_date`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
