-- =========================================================
-- YÜKLEME PLANI - Veritabanı şeması
-- MySQL 5.7+ / MariaDB 10.2+
-- =========================================================

CREATE DATABASE IF NOT EXISTS yukleme_plani
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE yukleme_plani;

-- ---------------------------------------------------------
-- 1) Genel kayıt bilgileri
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS loading_records (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    firma           VARCHAR(150) NOT NULL DEFAULT '',
    bolge           VARCHAR(150) NOT NULL DEFAULT '',
    parti_no        VARCHAR(80)  NOT NULL DEFAULT '',
    gumruk          VARCHAR(150) NOT NULL DEFAULT '',
    nakliye_bedeli  DECIMAL(12,2) NOT NULL DEFAULT 0,
    avans           DECIMAL(12,2) NOT NULL DEFAULT 0,
    sofor_adi       VARCHAR(150) NOT NULL DEFAULT '',
    fatura_no       VARCHAR(80)  NOT NULL DEFAULT '',
    casus_no        VARCHAR(80)  NOT NULL DEFAULT '',
    on_plaka        VARCHAR(30)  NOT NULL DEFAULT '',
    arka_plaka      VARCHAR(30)  NOT NULL DEFAULT '',
    nakliye_sirketi VARCHAR(150) NOT NULL DEFAULT '',
    telefon         VARCHAR(40)  NOT NULL DEFAULT '',
    tarih           DATE         NULL,
    alici           VARCHAR(150) NOT NULL DEFAULT '',
    urun            VARCHAR(150) NOT NULL DEFAULT '',
    etiket          VARCHAR(255) NOT NULL DEFAULT '',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tarih (tarih),
    INDEX idx_firma (firma),
    INDEX idx_parti (parti_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 2) Malzeme / dara tanımları
-- type değerleri: kasa_cinsi, palet_tipi, sapka, kosebent, serit,
--                 casus, kasa_etiketi, minti, kenar_kartonu, taban_kagidi,
--                 sale, viyol, kose_karton, kraft_kagit, file, diger
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS material_definitions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    type            VARCHAR(40)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    unit_dara_kg    DECIMAL(10,3) NOT NULL DEFAULT 0,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 3) Palet satırları
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS loading_pallets (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    loading_record_id  INT NOT NULL,
    palet_no           VARCHAR(40)  NOT NULL DEFAULT '',
    kasa_adeti         INT          NOT NULL DEFAULT 0,
    size               VARCHAR(60)  NOT NULL DEFAULT '',
    brut_kg            DECIMAL(10,3) NOT NULL DEFAULT 0,
    dara_kg            DECIMAL(10,3) NOT NULL DEFAULT 0,
    net_kg             DECIMAL(10,3) NOT NULL DEFAULT 0,
    kasa_cinsi_id      INT NULL,
    palet_tipi_id      INT NULL,
    urun_cinsi         VARCHAR(150) NOT NULL DEFAULT '',
    depo               VARCHAR(150) NOT NULL DEFAULT '',
    sira_no            INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pallet_record FOREIGN KEY (loading_record_id)
        REFERENCES loading_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_pallet_kasa FOREIGN KEY (kasa_cinsi_id)
        REFERENCES material_definitions(id) ON DELETE SET NULL,
    CONSTRAINT fk_pallet_palet FOREIGN KEY (palet_tipi_id)
        REFERENCES material_definitions(id) ON DELETE SET NULL,
    INDEX idx_pallet_record (loading_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- 4) Bir palete eklenen ekstra malzemeler
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS pallet_materials (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    loading_pallet_id  INT NOT NULL,
    material_id        INT NOT NULL,
    quantity           DECIMAL(10,3) NOT NULL DEFAULT 1,
    total_dara_kg      DECIMAL(10,3) NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pm_pallet FOREIGN KEY (loading_pallet_id)
        REFERENCES loading_pallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_material FOREIGN KEY (material_id)
        REFERENCES material_definitions(id) ON DELETE CASCADE,
    INDEX idx_pm_pallet (loading_pallet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ÖRNEK / BAŞLANGIÇ TANIMLARI
-- =========================================================
INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES
-- Kasa cinsleri
('kasa_cinsi',     'Plastik Kasa (Standart)',   1.800, 1),
('kasa_cinsi',     'Plastik Kasa (Büyük)',      2.100, 1),
('kasa_cinsi',     'Karton Kasa',               0.350, 1),
('kasa_cinsi',     'Tahta Kasa',                2.500, 1),
-- Palet tipleri
('palet_tipi',     'Euro Palet',                25.000, 1),
('palet_tipi',     'Standart Palet',            22.000, 1),
('palet_tipi',     'Tek Kullanımlık Palet',     18.000, 1),
-- Diğer dara kalemleri
('sapka',          'Karton Şapka',              0.300, 1),
('kosebent',       'Plastik Köşebent (4 lü)',   0.400, 1),
('serit',          'PP Şerit',                  0.150, 1),
('casus',          'Casus Tel',                 0.200, 1),
('kasa_etiketi',   'Standart Etiket',           0.050, 1),
('minti',          'Minti',                     0.100, 1),
('kenar_kartonu',  'Kenar Kartonu',             0.250, 1),
('taban_kagidi',   'Taban Kağıdı',              0.150, 1),
('sale',           'Şale',                      0.200, 1),
('viyol',          'Viyol',                     0.300, 1),
('kose_karton',    'Köşe Karton',               0.180, 1),
('kraft_kagit',    'Kraft Kağıt',               0.120, 1),
('file',           'File',                      0.080, 1);
