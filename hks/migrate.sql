-- HKS (Hal Kayıt Sistemi) veritabanı tabloları v2
-- Referans künye bazlı stok hareketi mimarisi

-- hks_settings: HKS servis bağlantı ayarları
CREATE TABLE IF NOT EXISTS `hks_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `password_enc` TEXT NOT NULL DEFAULT '',
  `service_password_enc` TEXT NOT NULL DEFAULT '',
  `security_word_enc` TEXT DEFAULT NULL,
  `environment` ENUM('test','live') NOT NULL DEFAULT 'test',
  `genel_wsdl_url` VARCHAR(500) NOT NULL DEFAULT '',
  `bildirim_wsdl_url` VARCHAR(500) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hks_reference_stock: Referans künye stok takibi (alış bildiriminden oluşur)
CREATE TABLE IF NOT EXISTS `hks_reference_stock` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `kunye_no` VARCHAR(100) NOT NULL DEFAULT '',
  `product_name` VARCHAR(200) NOT NULL DEFAULT '',
  `incoming_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `used_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `remaining_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
  `source_notification_type` VARCHAR(50) NOT NULL DEFAULT '',
  `supplier_name` VARCHAR(200) DEFAULT NULL,
  `header_id` INT UNSIGNED DEFAULT NULL COMMENT 'Oluşturan hks_headers.id',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_kunye_no` (`kunye_no`),
  INDEX `idx_remaining` (`remaining_quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hks_headers: Bildirim başlıkları (bir sevkiyat = bir header)
CREATE TABLE IF NOT EXISTS `hks_headers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `type` ENUM('alis','satis','ihracat','transfer','iade') NOT NULL DEFAULT 'alis',
  `buyer_name` VARCHAR(200) NOT NULL DEFAULT '',
  `vehicle_plate` VARCHAR(20) DEFAULT NULL,
  `driver_name` VARCHAR(100) DEFAULT NULL,
  `shipment_date` DATE NOT NULL,
  `origin_place` VARCHAR(200) DEFAULT NULL,
  `destination_place` VARCHAR(200) DEFAULT NULL,
  `supplier_name` VARCHAR(200) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `status` ENUM('draft','sent','error') NOT NULL DEFAULT 'draft',
  `hks_notification_no` VARCHAR(50) DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `request_xml` LONGTEXT DEFAULT NULL,
  `response_xml` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hks_items: Bildirim satır kalemleri (header'a bağlı, künye bazlı)
CREATE TABLE IF NOT EXISTS `hks_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `header_id` INT UNSIGNED NOT NULL,
  `reference_kunye_no` VARCHAR(100) NOT NULL DEFAULT '',
  `product_name` VARCHAR(200) NOT NULL DEFAULT '',
  `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
  `unit_price` DECIMAL(12,4) DEFAULT NULL,
  `stock_id` INT UNSIGNED DEFAULT NULL COMMENT 'hks_reference_stock.id referansı',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_header_id` (`header_id`),
  INDEX `idx_kunye_no` (`reference_kunye_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hks_logs: İşlem logları
CREATE TABLE IF NOT EXISTS `hks_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `header_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL DEFAULT '',
  `message` TEXT NOT NULL DEFAULT '',
  `request_payload` LONGTEXT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_header_id` (`header_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
