-- ============================================================================
-- ASYA WHATSAPP WEB BRIDGE (Aşama 1) — Bağımsız Veritabanı Şeması
-- Mevcut WhatsApp tablolarına (whatsapp_*) ve customs_declarations tablosuna
-- HİÇBİR DEĞİŞİKLİK YAPMAZ. Tüm tablolar cw_bridge_ önekli ve bağımsızdır.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `cw_bridge_pairings` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT NOT NULL,
    `code_hash`  CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cwb_pair_hash` (`code_hash`),
    INDEX `idx_cwb_pair_user` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cw_bridge_tokens` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT NOT NULL,
    `token_hash`   CHAR(64) NOT NULL,
    `device_label` VARCHAR(120) NULL DEFAULT NULL,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    `revoked_at`   DATETIME NULL DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cwb_tok_hash` (`token_hash`),
    INDEX `idx_cwb_tok_user` (`user_id`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cw_bridge_messages` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`           INT NOT NULL,
    `token_id`          INT UNSIGNED NULL DEFAULT NULL,
    `chat_name`         VARCHAR(180) NOT NULL,
    `chat_type`         VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `source_message_id` VARCHAR(180) NULL DEFAULT NULL,
    `sender_name`       VARCHAR(150) NULL DEFAULT NULL,
    `message_timestamp` VARCHAR(64) NULL DEFAULT NULL,
    `raw_text`          LONGTEXT NOT NULL,
    `normalized_hash`   CHAR(64) NOT NULL,
    `text_hash`         CHAR(64) NOT NULL,
    `reference_key`     VARCHAR(120) NULL DEFAULT NULL,
    `status`            VARCHAR(30) NOT NULL DEFAULT 'parsed',
    `ai_provider`       VARCHAR(30) NULL DEFAULT NULL,
    `ai_model`          VARCHAR(100) NULL DEFAULT NULL,
    `ai_confidence`     INT NULL DEFAULT NULL,
    `ai_result_json`    LONGTEXT NULL DEFAULT NULL,
    `declaration_id`    INT NULL DEFAULT NULL,
    `error_message`     VARCHAR(500) NULL DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cwb_msg_source` (`chat_name`(120), `source_message_id`(120)),
    INDEX `idx_cwb_msg_hash` (`chat_name`(120), `normalized_hash`),
    INDEX `idx_cwb_msg_texthash` (`chat_name`(120), `text_hash`),
    INDEX `idx_cwb_msg_ref` (`reference_key`(80)),
    INDEX `idx_cwb_msg_decl` (`declaration_id`),
    INDEX `idx_cwb_msg_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
