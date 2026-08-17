<?php
// =============================================================================
// HKS PANEL - YAPILANDIRMA (Asya Fresh paneline entegre edilmiş sürüm)
// Ana panelin config/db.php bağlantısı yeniden kullanılır; buradaki HKS_DB_*
// sabitleri yalnızca yedek (fallback) olarak panelin DB_* değerlerinden türetilir.
// =============================================================================

// Ana panel altyapısı: DB_* sabitleri + db() + config/local.php (HKS_CRED_KEY)
require_once __DIR__ . '/../config/db.php';

// --- MySQL bağlantı bilgileri (panelden devralınır) ---
define('HKS_DB_HOST', DB_HOST);
define('HKS_DB_NAME', DB_NAME);
define('HKS_DB_USER', DB_USER);
define('HKS_DB_PASS', DB_PASS);
define('HKS_DB_CHARSET', DB_CHARSET);

// Tablo ön eki (mevcut tablolarınızla çakışmasın diye). İsterseniz değiştirin.
define('HKS_TABLO_ON', 'hks_');

// --- Şifreleme anahtarı ---
// Firma HKS şifreleri veritabanına AES-256 ile ŞİFRELİ yazılır.
// Öncelik: sunucudaki config/local.php içindeki HKS_CRED_KEY (git dışında).
// O yoksa aşağıdaki sabit kullanılır. Anahtar sonradan değişirse daha önce
// kaydedilmiş firma şifreleri çözülemez; firmaları yeniden girmeniz gerekir.
define('HKS_SIFRELEME_ANAHTARI', defined('HKS_CRED_KEY')
    ? HKS_CRED_KEY
    : 'AsyaFresh-HKS-2026-vAq7kTz3RmNe9XuB4pWcJdH6yLgS8fKo');

// --- HKS web servis endpoint'i ---
// GTB, 12.03.2025 duyurusuyla yeni endpoint adresleri yayımladı ve "27 Mart 2025
// tarihine kadar mevcut endpointler ve yeni endpointler birlikte kullanılabilecektir"
// dedi. Kayıtsız ikinci kişide zorunlu olan "DogumTarihi" alanı büyük olasılıkla
// YALNIZ yeni endpoint şemasında bulunuyor.
//
// GEÇİŞ NASIL YAPILIR:
//   1) Önce salt-okunur teşhis: php scripts/hks_endpoint_test.php <firmaId>
//      (yalnız Ülkeler listesi çeker — HKS'te KAYIT OLUŞTURMAZ, rüsum doğurmaz.)
//   2) Yeni endpoint OK dönerse aşağıdaki sabiti true yapın.
//   3) Sorun çıkarsa false'a geri alın — kod değişikliği gerekmez.
//
// Varsayılan false: mevcut/çalışan davranış korunur, geçiş bilinçli bir karar olur.
define('HKS_YENI_ENDPOINT', false);

// Eski (klasik WCF .svc) ve yeni (gateway) adres kalıpları. %s = servis adı
// (Bildirim / Genel / Urun).
define('HKS_ENDPOINT_ESKI', 'https://hks.hal.gov.tr/WebServices/%sService.svc');
define('HKS_ENDPOINT_YENI', 'https://ws.gtb.gov.tr:8443/HKS%sService');

// --- Panel giriş koruması ---
// Ana panel oturumu (asya_session) api.php ve index.php başında kontrol edilir;
// bu yüzden HTTP Basic Auth kapalı kalır.
define('HKS_BASIT_GIRIS', false);
define('HKS_GIRIS_KULLANICI', 'admin');
define('HKS_GIRIS_SIFRE', 'degistirin');
