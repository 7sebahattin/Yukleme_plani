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

// --- Kayıtsız ikinci kişide DogumTarihi: BİÇİM ---
//
// Canlı WSDL (eski ve yeni uç, 01/02.10.2026): DogumTarihi xs:STRING ve
// ALFABETİK konumda (CepTel ile KisiSifat arası). Konum artık ayar DEĞİL —
// hks_bildirim_xml() hep alfabetik yazar; "sona koymak" alanı sunucuda düşürür.
//
// KESİN SONUÇ (05.10.2026, canlı deneme): HKS tarihi SAATSİZ "GG.AA.YYYY"
// (örn. 11.02.1959) bekliyor. GTB'nin örneğindeki "01.01.1980 00:00:00" biçimi
// ile "00:00:00"/"12:00:00" ekli ve ISO biçimler kayıtsız kişide Mernis
// "bulunamadı" (HataKodu 21) verdi; yalnız gtb_tarih künye üretti. Aynı kişi
// beş biçimle üç dakika içinde denendi (docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md §11).
// Sitedeki Sorgula, tarihi saatsiz gönderdiği için bıraktığı geçici sonuç
// "00:00:00"lı gönderimi de geçirip yanıltıyordu.
// Yönetici halkayit/dogum_deney.php'den tek kullanımlık deney kurabilir ya da
// bir biçimi "kalıcı" yapabilir (otomatik öğrenme YOK).
// Beyaz liste: gtb · gtb_oglen · gtb_tarih · iso · iso_oglen · iso_tarih
// (bkz. hks_soap.php hks_dogum_bicimleri).
define('HKS_DOGUM_BICIMI', 'gtb_tarih');

// --- Panel giriş koruması ---
// Ana panel oturumu (asya_session) api.php ve index.php başında kontrol edilir;
// bu yüzden HTTP Basic Auth kapalı kalır.
define('HKS_BASIT_GIRIS', false);
define('HKS_GIRIS_KULLANICI', 'admin');
define('HKS_GIRIS_SIFRE', 'degistirin');
