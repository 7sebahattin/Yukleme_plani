<?php
declare(strict_types=1);
// HKS modülü yapılandırma sabitleri — web'den direkt erişilememeli

const HKS_MODULE_VERSION = '1.0.0';

// WSDL URL'leri — HKS resmi servis adresleri (kılavuz doğrulanmalı)
const HKS_WSDL_TEST_GENEL    = 'https://hkstest.hal.gov.tr/HKSService/GenelService.svc?wsdl';
const HKS_WSDL_TEST_BILDIRIM = 'https://hkstest.hal.gov.tr/HKSService/BildirimService.svc?wsdl';
const HKS_WSDL_LIVE_GENEL    = 'https://hks.hal.gov.tr/HKSService/GenelService.svc?wsdl';
const HKS_WSDL_LIVE_BILDIRIM = 'https://hks.hal.gov.tr/HKSService/BildirimService.svc?wsdl';

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
    'isletme_turu', 'urun', 'urun_birim', 'urun_cins',
    'malin_niteligi', 'uretim_sekli', 'bildirim_turu',
    'sifat', 'referans_kunye',
];
