-- HKS (Hal Kayıt Sistemi) veritabanı tabloları
-- Bu dosyayı doğrudan çalıştırabilir ya da hks/index.php auto-migration kullanabilirsiniz.

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

-- hks_notifications: HKS bildirimleri
CREATE TABLE IF NOT EXISTS `hks_notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `notification_type` VARCHAR(50) NOT NULL DEFAULT '',
  `product_name` VARCHAR(200) NOT NULL DEFAULT '',
  `product_code` VARCHAR(50) DEFAULT NULL,
  `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
  `package_type` VARCHAR(50) NOT NULL DEFAULT '',
  `supplier_name` VARCHAR(200) DEFAULT NULL,
  `buyer_name` VARCHAR(200) DEFAULT NULL,
  `shipment_date` DATE NOT NULL,
  `vehicle_plate` VARCHAR(20) DEFAULT NULL,
  `driver_name` VARCHAR(100) DEFAULT NULL,
  `origin_place` VARCHAR(200) DEFAULT NULL,
  `destination_place` VARCHAR(200) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `status` ENUM('draft','sent','error') NOT NULL DEFAULT 'draft',
  `hks_notification_no` VARCHAR(50) DEFAULT NULL,
  `hks_tag_no` VARCHAR(50) DEFAULT NULL,
  `last_error` TEXT DEFAULT NULL,
  `request_xml` LONGTEXT DEFAULT NULL,
  `response_xml` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- hks_logs: İşlem logları
CREATE TABLE IF NOT EXISTS `hks_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `notification_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL DEFAULT '',
  `message` TEXT NOT NULL DEFAULT '',
  `request_payload` LONGTEXT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notification_id` (`notification_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
