<?php
// =============================================================================
// HKS PANEL - VERİTABANI KATMANI (PDO / MySQL)
// - Bağlantı
// - Tabloların otomatik oluşturulması (ilk çalıştırmada)
// - Firma şifreleri için AES-256 şifreleme/çözme
// =============================================================================

require_once __DIR__ . '/config.php';

// --- PDO bağlantısı (tekil) ---
function hks_db() {
  static $pdo = null;
  if ($pdo !== null) return $pdo;
  $dsn = 'mysql:host=' . HKS_DB_HOST . ';dbname=' . HKS_DB_NAME . ';charset=' . HKS_DB_CHARSET;
  $pdo = new PDO($dsn, HKS_DB_USER, HKS_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}

function hks_tablo($ad) { return HKS_TABLO_ON . $ad; }

// --- Tabloları oluştur (yoksa) ---
// schema.sql ile aynı yapıyı üretir; ilk istekte otomatik çağrılır.
function hks_tablolari_hazirla() {
  $db = hks_db();
  $t = HKS_TABLO_ON;

  $db->exec("CREATE TABLE IF NOT EXISTS {$t}firmalar (
    id VARCHAR(40) PRIMARY KEY,
    ad VARCHAR(200) NOT NULL,
    renk VARCHAR(20) DEFAULT 'teal',
    user_name VARCHAR(200) NOT NULL,
    password_enc TEXT NOT NULL,
    service_password_enc TEXT NOT NULL,
    vergi_no VARCHAR(20) NOT NULL,
    olusturma DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->exec("CREATE TABLE IF NOT EXISTS {$t}taslaklar (
    id VARCHAR(40) PRIMARY KEY,
    zaman DATETIME NOT NULL,
    firma_id VARCHAR(40) NOT NULL,
    firma_ad VARCHAR(200) NOT NULL,
    veri MEDIUMTEXT NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->exec("CREATE TABLE IF NOT EXISTS {$t}gonderilenler (
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
    veri MEDIUMTEXT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Eski kurulumlarda firma_id kolonu eksik olabilir (idempotent ekleme).
  try { $db->exec("ALTER TABLE {$t}gonderilenler ADD COLUMN firma_id VARCHAR(40) AFTER zaman"); }
  catch (PDOException $e) { /* zaten var */ }

  // P3: Bildirim türü — önceden yalnız `ulke_ad` serbest metnine gömülü bir
  // önek ile ayırt edilebiliyordu (raporlama/denetim için kırılgan). Yeni
  // gönderimler bu kolonu YAPISAL olarak doldurur (bkz. api.php taslak_gonder).
  // Legacy satırlar NULL kalır — güvenilir biçimde belirlenemediği için TAHMİNE
  // DAYALI backfill YAPILMAZ.
  try { $db->exec("ALTER TABLE {$t}gonderilenler ADD COLUMN bildirim_turu VARCHAR(30) DEFAULT NULL AFTER genel_hata"); }
  catch (PDOException $e) { /* zaten var */ }

  // Son kullanılanlar ve liste önbelleği: basit anahtar-değer
  $db->exec("CREATE TABLE IF NOT EXISTS {$t}kv (
    anahtar VARCHAR(60) PRIMARY KEY,
    deger MEDIUMTEXT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// --- Anahtar-değer yardımcıları (sonlar, liste cache için) ---
function hks_kv_oku($anahtar, $varsayilan = null) {
  $st = hks_db()->prepare('SELECT deger FROM ' . hks_tablo('kv') . ' WHERE anahtar = ?');
  $st->execute([$anahtar]);
  $r = $st->fetchColumn();
  return $r === false ? $varsayilan : json_decode($r, true);
}
function hks_kv_yaz($anahtar, $veri) {
  $st = hks_db()->prepare('REPLACE INTO ' . hks_tablo('kv') . ' (anahtar, deger) VALUES (?, ?)');
  $st->execute([$anahtar, json_encode($veri, JSON_UNESCAPED_UNICODE)]);
}

// --- Şifreleme (AES-256-CBC) ---
function hks_sifrele($duz) {
  $anahtar = hash('sha256', HKS_SIFRELEME_ANAHTARI, true);
  $iv = openssl_random_pseudo_bytes(16);
  $sifreli = openssl_encrypt($duz, 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv);
  return base64_encode($iv . $sifreli);
}
function hks_coz($sifreli) {
  $anahtar = hash('sha256', HKS_SIFRELEME_ANAHTARI, true);
  $ham = base64_decode($sifreli);
  if ($ham === false || strlen($ham) < 17) return '';
  $iv = substr($ham, 0, 16);
  $govde = substr($ham, 16);
  $duz = openssl_decrypt($govde, 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv);
  return $duz === false ? '' : $duz;
}
