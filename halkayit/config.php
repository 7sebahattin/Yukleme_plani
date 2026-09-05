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

// --- Kayıtsız ikinci kişide DogumTarihi biçimi ---
// CANLI GÖZLEM (05.09.2026): kayıtsız kişiye yapılan Satın Alım bildirimleri
// HER SEFERİNDE "Tc kimlik numarası Mernis sisteminde bulunamadı" ile
// reddedildi — TC, ad ve doğum tarihi doğru olmasına rağmen. Aynı kişi HKS'in
// KENDİ sitesinden bildirilince (kişi böylece sisteme kaydolur) bizim
// panelden sonraki gönderim SORUNSUZ geçti. Bu, doğum tarihinin karşı tarafa
// ULAŞMADIĞINI gösteriyor: alan okunmayınca KPS yalnız TC ile sorgulanıyor ve
// kayıtsız kişide tam olarak bu hata dönüyor.
//
// İki olası sebep vardı: (a) alan yalnız YENİ endpoint şemasında var,
// (b) biçim yanlış. (a) şu an DENENEMİYOR — endpoint_test.php yeni adrese
// TCP bağlantısı bile kuramıyor (ws.gtb.gov.tr:8443 kapalı). Geriye (b) kalıyor:
// GTB'nin 12.03.2025 duyurusunun ekindeki Ornek_Request.txt doğum tarihini
// "01.01.1980 00:00:00" biçiminde yazıyor — yani alan büyük olasılıkla DateTime
// değil METİN ve Türkçe biçim bekliyor. ISO 8601 ("1980-01-01T00:00:00")
// gönderdiğimizde ayrıştırılamayıp sessizce boş kabul ediliyor olabilir.
//
//   'gtb' → 01.01.1980 00:00:00   (GTB'nin kendi örneği — VARSAYILAN)
//   'iso' → 1980-01-01T00:00:00   (önceki davranış)
//
// RİSK DAR: bu alan YALNIZCA kayıtsız ikinci kişide gönderilir. Kayıtlı kişi,
// yurt dışı Satış ve Sevk Etme akışlarında alan hiç eklenmez — onlar bu
// ayardan HİÇ etkilenmez. Yani değişiklik yalnız hâlihazırda ÇALIŞMAYAN akışı
// etkiler. Sonuç alınamazsa 'iso' yapıp geri dönün, kod değişikliği gerekmez.
define('HKS_DOGUM_BICIMI', 'gtb');

// --- Panel giriş koruması ---
// Ana panel oturumu (asya_session) api.php ve index.php başında kontrol edilir;
// bu yüzden HTTP Basic Auth kapalı kalır.
define('HKS_BASIT_GIRIS', false);
define('HKS_GIRIS_KULLANICI', 'admin');
define('HKS_GIRIS_SIFRE', 'degistirin');
