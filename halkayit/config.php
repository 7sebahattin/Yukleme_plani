<?php
// =============================================================================
// HKS PANEL - YAPILANDIRMA
// Bu dosyayı kendi sunucu bilgilerinizle doldurun.
// ENTEGRASYON NOTU (diğer AI için): Eğer ana paneliniz zaten bir PDO/MySQLi
// bağlantısı kuruyorsa, aşağıdaki DB sabitleri yerine mevcut bağlantınızı
// db.php içindeki hks_db() fonksiyonuna verebilirsiniz.
// =============================================================================

// --- MySQL bağlantı bilgileri ---
define('HKS_DB_HOST', 'localhost');
define('HKS_DB_NAME', 'VERITABANI_ADI');       // <-- doldurun
define('HKS_DB_USER', 'VERITABANI_KULLANICI');  // <-- doldurun
define('HKS_DB_PASS', 'VERITABANI_SIFRE');      // <-- doldurun
define('HKS_DB_CHARSET', 'utf8mb4');

// Tablo ön eki (mevcut tablolarınızla çakışmasın diye). İsterseniz değiştirin.
define('HKS_TABLO_ON', 'hks_');

// --- Şifreleme anahtarı ---
// Firma HKS şifreleri veritabanına AES-256 ile ŞİFRELİ yazılır.
// Aşağıdaki anahtarı MUTLAKA değiştirin (rastgele 32+ karakter).
// Değiştirdikten sonra daha önce kaydedilmiş firmalar okunamaz; yeniden girin.
define('HKS_SIFRELEME_ANAHTARI', 'BURAYA-RASTGELE-UZUN-BIR-ANAHTAR-YAZIN-32+');

// --- Panel giriş koruması (opsiyonel) ---
// Ana paneliniz zaten oturum (login) kontrolü yapıyorsa burayı boş bırakın;
// panel sizin oturumunuzla korunur. Bağımsız çalıştıracaksanız true yapıp
// aşağıdaki kullanıcı/şifreyi doldurun (HTTP Basic Auth).
define('HKS_BASIT_GIRIS', false);
define('HKS_GIRIS_KULLANICI', 'admin');
define('HKS_GIRIS_SIFRE', 'degistirin');
