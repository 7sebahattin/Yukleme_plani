-- =============================================================================
-- docs/sql/whatsapp_migration.sql — WhatsApp Yükleme Planı modülü şeması (MySQL 5.7+/MariaDB 10.3+)
--
-- ÇALIŞTIRMA: yalnız sahibin açık "GO"su ile (phpMyAdmin › SQL). İdempotent: iki kez çalıştırmak zararsızdır.
-- Mevcut tablolara DOKUNMAZ (ALTER yok). Yükleme planına yazılan kayıtlar mevcut `loading_records`
-- tablosunda durum='taslak' olarak durur; bunun için şema değişikliği gerekmez.
-- =============================================================================

-- 1) Gelen WhatsApp grup mesajları + AI çıktısı
CREATE TABLE IF NOT EXISTS `whatsapp_mesaj_loglari` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`        VARCHAR(120) NOT NULL,                 -- WhatsApp mesaj kimliği: mükerrer webhook'u engeller
  `remote_jid`        VARCHAR(100) NOT NULL,                 -- grup (…@g.us) ya da kişi JID'i
  `is_group`          TINYINT(1)   NOT NULL DEFAULT 0,
  `sender_name`       VARCHAR(150) NOT NULL DEFAULT '',
  `sender_phone`      VARCHAR(50)  NOT NULL DEFAULT '',
  `raw_message`       TEXT         NOT NULL,
  `parsed_data`       LONGTEXT     NULL,                     -- AI JSON çıktısı (+ _meta: sağlayıcı/model/eşik)
  `durum`             VARCHAR(30)  NOT NULL DEFAULT 'eksik_bilgi',  -- isleniyor|ai_hata|alakasiz|eksik_bilgi|hazir|aktarildi
  -- Panel/mükerrer kontrolü için ayrıştırılmış alanlar (parsed_data'nın özeti)
  `plaka`             VARCHAR(30)  NULL,
  `yukleme_tarihi`    DATE         NULL,
  `guven_skoru`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `loading_record_id` INT          NULL,                     -- aktarılan loading_records.id (taslak/plan)
  `hata_mesaji`       VARCHAR(255) NULL,                     -- ai_hata nedeni
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_message_id` (`message_id`),
  KEY `idx_wa_durum_id` (`durum`, `id`),
  KEY `idx_wa_jid` (`remote_jid`),
  KEY `idx_wa_plaka_tarih` (`plaka`, `yukleme_tarihi`),
  KEY `idx_wa_record` (`loading_record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Tek satırlık (id=1) modül ayarı
CREATE TABLE IF NOT EXISTS `whatsapp_ayarlar` (
  `id`                TINYINT UNSIGNED NOT NULL,
  `instance_adi`      VARCHAR(80)  NOT NULL DEFAULT '',        -- boş = config sabiti EVOLUTION_INSTANCE_NAME
  `secili_gruplar`    TEXT         NULL,                       -- JSON: [{"jid":"1203…@g.us","ad":"Grup adı"}]; boş = tüm gruplar
  `ai_saglayici`      VARCHAR(20)  NOT NULL DEFAULT 'groq',    -- groq | gemini
  `otomatik_taslak`   TINYINT(1)   NOT NULL DEFAULT 1,         -- 1: güveni yüksek plan otomatik taslak olur
  `evolution_url`     VARCHAR(255) NOT NULL DEFAULT '',        -- boş = config sabiti EVOLUTION_API_URL
  `evolution_api_key` VARCHAR(255) NOT NULL DEFAULT '',        -- boş = config sabiti EVOLUTION_API_KEY
  `webhook_secret`    VARCHAR(255) NOT NULL DEFAULT '',        -- boş = config sabiti WEBHOOK_SECRET_TOKEN
  `ai_api_key`        VARCHAR(255) NOT NULL DEFAULT '',        -- boş = config sabiti AI_API_KEY
  `guncelleyen_id`    INT          NULL,
  `updated_at`        DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `whatsapp_ayarlar` (`id`, `instance_adi`, `secili_gruplar`, `ai_saglayici`, `otomatik_taslak`)
VALUES (1, '', '[]', 'groq', 1);
