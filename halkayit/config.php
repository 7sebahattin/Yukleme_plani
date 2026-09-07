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

// --- Kayıtsız ikinci kişide DogumTarihi: KONUM + BİÇİM ---
//
// GTB, 12.03.2025 duyurusuyla kayıtsız kişi bildirimlerinde TC ile birlikte
// `DogumTarihi` göndermeyi zorunlu kıldı ve alanı ~2016 tarihli bir WCF
// sözleşmesine ekledi. Alanın XML'deki KONUMU ya da BİÇİMİ tutmazsa istek
// SESSİZCE başarısız olur: `DataContractSerializer` beklediği konumda olmayan
// elemanı hata vermeden ATLAR, sunucu alanı boş görür.
//
// BU DEĞERLER ARTIK "KANIT" DEĞİL, YALNIZCA BAŞLANGIÇ TAHMİNİDİR.
// 05.09.2026'da 'son' + 'gtb' canlıda künye üretti; 07.09.2026'da AYNI kod,
// AYNI kişi için "... doğum tarihi girilmelidir" aldı. Yani doğru kombinasyon
// bizim kontrolümüz dışında değişebiliyor ve tek bir sabite yazmak kırılgan.
// Bu yüzden çalışan kombinasyon ÖĞRENİLİR (hks_kv.dogum_varyant) ve teslim
// edilemediğinde merdiven diğerlerini dener — bkz. hks_soap.php
// hks_bildirim_kaydet(). Buradaki sabitler yalnız HENÜZ BİR ŞEY ÖĞRENİLMEDİYSE
// kullanılır.
//
//   HKS_DOGUM_KONUM:  'son'       → ... KisiSifat, TcKimlikVergiNo, YurtDisiMi, DogumTarihi
//                     'alfabetik' → AdSoyad, CepTel, DogumTarihi, KisiSifat, ...
//   HKS_DOGUM_BICIMI: 'gtb'       → 01.01.1980 00:00:00   (GTB Ornek_Request.txt)
//                     'iso'       → 1980-01-01T00:00:00
define('HKS_DOGUM_KONUM', 'son');
define('HKS_DOGUM_BICIMI', 'gtb');

// --- Doğum tarihi teslim merdiveni ---
// true  → doğum tarihi gönderildiği hâlde HKS "girilmelidir" derse (istek TÜMDEN
//         reddedilmiş, HİÇ künye oluşmamış, rüsum doğmamıştır) diğer konum/biçim
//         kombinasyonları sırayla denenir ve teslim edileni ÖĞRENİLİR.
// false → tek deneme; eski davranış.
//
// MÜKERRER GÖNDERİM RİSKİ YOK: merdiven yalnızca HKS'ten TEK BİR satır cevabı
// bile dönmediğinde ilerler (hks_dogum_okunmadi_mi). Satır cevabı varsa künye
// oluşmuş olabilir ve merdiven ORADA DURUR. Ayrıca "Mernis'te bulunamadı"
// hatasında da durur — o hata alanın ULAŞTIĞINI, DEĞERİN yanlış olduğunu söyler.
define('HKS_DOGUM_DENEME', true);

// --- Panel giriş koruması ---
// Ana panel oturumu (asya_session) api.php ve index.php başında kontrol edilir;
// bu yüzden HTTP Basic Auth kapalı kalır.
define('HKS_BASIT_GIRIS', false);
define('HKS_GIRIS_KULLANICI', 'admin');
define('HKS_GIRIS_SIFRE', 'degistirin');
