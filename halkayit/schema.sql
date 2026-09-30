-- =============================================================================
-- HKS PANEL - VERİTABANI ŞEMASI
-- Tablolar api.php ilk çalıştığında OTOMATİK oluşturulur (hks_tablolari_hazirla).
-- Bu dosya, tabloları elle kurmak veya incelemek isteyenler içindir.
-- Tablo ön eki config.php'deki HKS_TABLO_ON ile aynı olmalıdır (varsayılan: hks_).
-- =============================================================================

CREATE TABLE IF NOT EXISTS hks_firmalar (
  id VARCHAR(40) PRIMARY KEY,
  ad VARCHAR(200) NOT NULL,
  renk VARCHAR(20) DEFAULT 'teal',
  user_name VARCHAR(200) NOT NULL,
  password_enc TEXT NOT NULL,              -- AES-256 şifreli HKS şifresi
  service_password_enc TEXT NOT NULL,      -- AES-256 şifreli web servis şifresi
  vergi_no VARCHAR(20) NOT NULL,
  olusturma DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hks_taslaklar (
  id VARCHAR(40) PRIMARY KEY,
  zaman DATETIME NOT NULL,
  firma_id VARCHAR(40) NOT NULL,
  firma_ad VARCHAR(200) NOT NULL,
  veri MEDIUMTEXT NOT NULL                 -- JSON: {satirlar, ortak}
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hks_gonderilenler (
  id VARCHAR(40) PRIMARY KEY,
  zaman DATETIME NOT NULL,
  firma_id VARCHAR(40),
  firma_ad VARCHAR(200),
  plaka VARCHAR(30),
  belge_no VARCHAR(60),
  ulke_ad VARCHAR(120),
  urun_ad VARCHAR(120),
  adet INT,
  toplam_kg DOUBLE,
  fiyat DOUBLE,
  rusum DOUBLE,
  hata_sayisi INT,
  genel_hata TEXT,
  bildirim_turu VARCHAR(30),                -- URETICIDEN_SEVK_ALIM | SATIN_ALIM | SEVK_ETME | SATIS | NULL (legacy)
  veri MEDIUMTEXT                          -- JSON: {yeniKunyeler, sonuclar}
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Son kullanılan plaka/ülke/ürün ve referans liste önbelleği (anahtar-değer)
CREATE TABLE IF NOT EXISTS hks_kv (
  anahtar VARCHAR(60) PRIMARY KEY,
  deger MEDIUMTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kişi Havuzu: karşı taraf kişileri (müstahsil / firma). GLOBALDİR — tüm firmalar
-- aynı listeyi görür. Eski firma bazlı hks_kv 'sonlar_<firma>'.karsiTaraflar
-- listesinin yerini alır (ilk açılışta bir kez içe aktarılır, eski veri silinmez).
-- Otomatik kurulum: halkayit/kisi_havuzu_lib.php hks_kisi_tablo_hazirla().
CREATE TABLE IF NOT EXISTS hks_kisiler (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tc VARCHAR(11) NOT NULL,                 -- yalnız rakam: 11 hane TC ya da 10 hane VKN
  ad VARCHAR(200) NOT NULL DEFAULT '',
  cep VARCHAR(20) NOT NULL DEFAULT '',     -- yalnız rakam
  dogum DATE NULL,
  sifat_id INT NULL,                       -- karşı taraf sıfatı (HKS katalog id); v282 tablosuna ALTER ile eklenir
  kullanim_sayisi INT NOT NULL DEFAULT 0,  -- yalnız gerçek gönderimde artar
  son_kullanim DATETIME NULL,
  olusturma DATETIME NOT NULL,
  guncelleme DATETIME NULL,
  olusturan_id INT NULL,
  UNIQUE KEY uq_kisi_tc (tc),
  KEY ix_kisi_son (son_kullanim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
