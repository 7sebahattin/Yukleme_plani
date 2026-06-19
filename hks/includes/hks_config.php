<?php
declare(strict_types=1);
// HKS modülü yapılandırma sabitleri — web'den direkt erişilememeli

const HKS_MODULE_VERSION = '1.0.0';

// WSDL URL'leri — HKS Geliştirici Kılavuzu'ndaki resmi servis adresleri.
// ÖNEMLİ — TÜM ortamlar canlı domain (hks.hal.gov.tr) üzerinden çalışır:
//  • IP tabanlı test adresi (95.0.51.130) bu sunucudan ERİŞİLEMİYOR
//    (TCP:443 timeout — firewall/whitelist). cURL testinde doğrulandı.
//  • hks.hal.gov.tr bu sunucudan HTTP 200 ile başarıyla erişiliyor (doğrulandı).
//  • Bu nedenle IP adresleri koddan tamamen kaldırıldı; "test" ortamı da
//    canlı domaine yönlendirildi.
//  • Servis yolu '/WebServices/' şeklindedir ('/HKSService/' DEĞİL).
//  • Gerçek bir test endpoint'i sağlanırsa Ayarlar → Gelişmiş Ayarlar'daki
//    WSDL alanlarından override edilebilir.
// Kılavuza göre ÜÇ ayrı servis vardır: GenelService, UrunService, BildirimService.
const HKS_WSDL_LIVE_GENEL    = 'https://hks.hal.gov.tr/WebServices/GenelService.svc?wsdl';
const HKS_WSDL_LIVE_URUN     = 'https://hks.hal.gov.tr/WebServices/UrunService.svc?wsdl';
const HKS_WSDL_LIVE_BILDIRIM = 'https://hks.hal.gov.tr/WebServices/BildirimService.svc?wsdl';
// Test ortamı da canlı domaine yönlendirildi (IP erişilemiyor).
const HKS_WSDL_TEST_GENEL    = HKS_WSDL_LIVE_GENEL;
const HKS_WSDL_TEST_URUN     = HKS_WSDL_LIVE_URUN;
const HKS_WSDL_TEST_BILDIRIM = HKS_WSDL_LIVE_BILDIRIM;

// ServicePassword test ortamı varsayılan değeri (kılavuz: bölüm "Genel Açıklamalar")
const HKS_TEST_SERVICE_PASSWORD = '!1QAZWSX';

const HKS_DEFAULT_TIMEOUT = 30;

// Bildirim durum sabitleri
const HKS_STATUS_DRAFT     = 'draft';
const HKS_STATUS_READY     = 'ready';
const HKS_STATUS_SENT      = 'sent';
const HKS_STATUS_FAILED    = 'failed';
const HKS_STATUS_CANCELLED = 'cancelled';

// Desteklenen referans tipleri
const HKS_REF_TYPES = [
    'ulke', 'il', 'ilce', 'belde', 'depo', 'sube',
    'isletme_turu', 'hal_ici_isyeri',
    'urun', 'urun_birim', 'urun_cins',
    'malin_niteligi', 'uretim_sekli', 'bildirim_turu',
    'sifat', 'referans_kunye',
];
