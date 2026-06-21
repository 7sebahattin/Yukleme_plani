<?php
declare(strict_types=1);
// HKS e-Bildirim SOAP payload builder
// ───────────────────────────────────────────────────────────────────────────
// Bir hks_notifications kaydını HKS BildirimServisBildirimKaydet methoduna
// gönderilecek iş verisi (Istek gövdesi) formatına çevirir.
//
// ÖNEMLİ — ALAN ADI GÜVENLİĞİ:
//   HKS WSDL'indeki BildirimKayitIstek tip alan adları bu kod tabanında
//   doğrulanmamıştır (mevcut HksClient yalnızca sorgu metodlarını kullanıyor;
//   BildirimServisBildirimKaydet için inner tip tanımı yok). Bu yüzden burada
//   kullanılan HKS alan adları SAĞLAMA ALINMAMIŞ kabul edilir ve
//   HKS_PAYLOAD_FIELDS_CONFIRMED sabiti false olduğu sürece payload "gönderime
//   hazır" sayılmaz. Bu, sprintin "mapping kesin olmayan alanlarda gönderimi
//   bloke et" kuralının uygulamasıdır.
//
//   Kimlik bilgileri (UserName/Password/ServicePassword) bu builder tarafından
//   ASLA eklenmez; gerçek gönderim anında HksClient::buildRequest() ekler.
// ───────────────────────────────────────────────────────────────────────────

// MANUEL master override. Normalde dokunulmaz — alan doğrulaması artık canlı
// WSDL introspeksiyonundan (hks/wsdl_tipleri.php) elde edilen ve
// hks_reference_cache'e yazılan gerçek alan adlarına göre DİNAMİK hesaplanır
// (bkz. hks_payload_fields_confirmed()). Bu sabit yalnızca acil durum kilidi/açması.
if (!defined('HKS_PAYLOAD_FIELDS_CONFIRMED')) {
    define('HKS_PAYLOAD_FIELDS_CONFIRMED', false);
}

// ── WSDL struct parser ───────────────────────────────────
// __getTypes() çıktısındaki "struct Name { type field; ... }" bloğunu ayrıştırır.
// Parse edilemezse 'fields' boş döner, 'raw' ham metni taşır.
function hks_parse_wsdl_struct_fields(string $typeString): array {
    $typeString = trim($typeString);
    $name = '';
    $fields = [];
    if (preg_match('/struct\s+([A-Za-z0-9_]+)\s*\{(.*)\}/s', $typeString, $m)) {
        $name = $m[1];
        $body = $m[2];
        foreach (preg_split('/;/', $body) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // "type fieldName" (type birden çok kelime/namespace içerebilir)
            if (preg_match('/^(.*\S)\s+([A-Za-z0-9_]+)$/', $line, $fm)) {
                $fields[] = ['type' => trim($fm[1]), 'name' => $fm[2]];
            } else {
                $fields[] = ['type' => '', 'name' => $line];
            }
        }
    }
    return [
        'type_name' => $name,
        'fields'    => $fields,
        'raw'       => $typeString,
    ];
}

// __getTypes() dizisindeki bir tip adına ait struct bloğunu bulur.
function hks_find_wsdl_type(array $types, string $typeName): ?string {
    foreach ($types as $t) {
        if (preg_match('/struct\s+' . preg_quote($typeName, '/') . '\s*\{/', $t)) {
            return $t;
        }
    }
    return null;
}

// ── Doğrulanmış WSDL alanlarını sakla / oku (hks_reference_cache) ──
// Her tip için alan adlarını ref_type='wsdl_field:<TypeName>' altında tutar.
// Böylece payload builder ve detay ekranı, introspeksiyon çalıştırıldıktan sonra
// hangi HKS alanının WSDL'de gerçekten var olduğunu bilir. DB'siz ortamda sessizce geçer.
function hks_wsdl_store_confirmed_fields(string $typeName, array $fields): void {
    try {
        $pdo = db();
        // Önce bu tipin eski kayıtlarını pasifleştir
        $pdo->prepare("UPDATE hks_reference_cache SET is_active=0 WHERE ref_type=?")
            ->execute(['wsdl_field:' . $typeName]);
        $st = $pdo->prepare(
            "INSERT INTO hks_reference_cache (ref_type, ref_code, ref_name, ref_parent_code, raw_json, synced_at, is_active)
             VALUES (?,?,?,?,?,NOW(),1)
             ON DUPLICATE KEY UPDATE ref_name=VALUES(ref_name), synced_at=NOW(), is_active=1"
        );
        foreach ($fields as $f) {
            $fname = is_array($f) ? (string)($f['name'] ?? '') : (string)$f;
            $ftype = is_array($f) ? (string)($f['type'] ?? '') : '';
            if ($fname === '') continue;
            $st->execute(['wsdl_field:' . $typeName, $fname, $ftype, $typeName, null]);
        }
    } catch (Throwable) {
        /* DB yok / yazılamadı — sessiz geç */
    }
}

// Tüm doğrulanmış WSDL alan adlarını (tüm tipler) küçük harfli set olarak döndürür.
function hks_wsdl_all_confirmed_field_names(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $set = [];
    try {
        // __MAPPING_APPROVED__ bir kontrol bayrağıdır, gerçek bir alan adı değildir → hariç tut.
        $st = db()->query("SELECT ref_code FROM hks_reference_cache
                           WHERE ref_type LIKE 'wsdl_field:%'
                             AND ref_type <> 'wsdl_field:__MAPPING_APPROVED__'
                             AND is_active=1");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $set[mb_strtolower((string)$name)] = true;
        }
    } catch (Throwable) {
        $set = [];
    }
    return $cache = $set;
}

// Bir HKS alan adı canlı WSDL'den doğrulandı mı?
function hks_payload_field_is_confirmed(string $hksField): bool {
    $set = hks_wsdl_all_confirmed_field_names();
    if (empty($set)) return false;
    return isset($set[mb_strtolower($hksField)]);
}

// Mapping KULLANICI ONAYI verildi mi? (PDF + WSDL doğrulaması sonrası, ayrı sprint)
// Onay açık bir DB bayrağıyla (hks_reference_cache: wsdl_field:__MAPPING_APPROVED__=1)
// VEYA HKS_PAYLOAD_FIELDS_CONFIRMED sabitiyle verilir. WSDL alan adlarının cache'te
// bulunması TEK BAŞINA onay sayılmaz — isim benzerliği ile otomatik açılma engellenir.
function hks_mapping_approved(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $st = db()->prepare("SELECT ref_code FROM hks_reference_cache
                             WHERE ref_type='wsdl_field:__MAPPING_APPROVED__' AND is_active=1 LIMIT 1");
        $st->execute();
        $cache = ((string)$st->fetchColumn() === '1');
    } catch (Throwable) {
        $cache = false;
    }
    return $cache;
}

// Gönderim alan-mapping kilidi açık mı?
// KESİN KURAL: yalnızca açık onay (sabit override VEYA __MAPPING_APPROVED__ bayrağı)
// kilidi açar. WSDL introspeksiyonu yalnızca rapora veri sağlar, otomatik açmaz.
function hks_payload_fields_confirmed(): bool {
    if (HKS_PAYLOAD_FIELDS_CONFIRMED) return true;          // manuel/acil override
    return hks_mapping_approved();                          // açık kullanıcı onayı
}

// Mapping onay bayrağını yaz/kaldır (hks_reference_cache: wsdl_field:__MAPPING_APPROVED__).
// Yalnızca açık kullanıcı eylemiyle (Mapping Raporu onay butonu) çağrılmalıdır.
// Bu fonksiyon yetki/önkoşul KONTROLÜ YAPMAZ — çağıran katman (mapping_raporu.php)
// admin + CSRF + approval_ready=true önkoşullarını garanti eder.
function hks_mapping_set_approved(bool $approved): void {
    $pdo = db();
    if ($approved) {
        $pdo->prepare(
            "INSERT INTO hks_reference_cache (ref_type, ref_code, ref_name, synced_at, is_active)
             VALUES ('wsdl_field:__MAPPING_APPROVED__','1','approved',NOW(),1)
             ON DUPLICATE KEY UPDATE ref_name='approved', synced_at=NOW(), is_active=1"
        )->execute();
    } else {
        $pdo->prepare(
            "UPDATE hks_reference_cache SET is_active=0
             WHERE ref_type='wsdl_field:__MAPPING_APPROVED__'"
        )->execute();
    }
}

// ── Format yardımcıları ──────────────────────────────────

// Tek tarih helper'ı — HKS servis tarihi. WSDL kesin format isteyene kadar
// xsd:dateTime varsayımıyla ISO-8601 döner. Çözümlenemezse null.
if (!function_exists('hks_format_service_date')) {
    function hks_format_service_date(?string $date): ?string {
        $date = trim((string)$date);
        if ($date === '') return null;
        try {
            $d = new DateTime($date);
            return $d->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}

// Decimal normalizasyonu — Türkçe virgül noktaya çevrilir, binlik ayraç temizlenir.
// Geçerli sayı değilse null döner. "25,5" → "25.5", "1.000,00" → "1000.00".
function hks_normalize_decimal(mixed $v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    // Hem nokta hem virgül varsa: nokta binlik, virgül ondalık kabul et.
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) return null;
    // Gereksiz sondaki sıfırları koru ama düz sayı string'i döndür.
    return (string)(0 + (float)$s) === $s ? $s : rtrim(rtrim(number_format((float)$s, 6, '.', ''), '0'), '.');
}

// TC/VKN ve telefon gibi alanlar string korunur — baştaki sıfır silinmez,
// numeric'e çevrilmez. Sadece görünür boşluk temizlenir.
function hks_clean_identifier(mixed $v): string {
    return trim((string)($v ?? ''));
}

// ── Referans kod çözümleme ───────────────────────────────

// Bir ref türünde hiç aktif kayıt senkronlanmış mı?
function hks_reference_type_synced(string $type): bool {
    try {
        $st = db()->prepare("SELECT 1 FROM hks_reference_cache WHERE ref_type=? AND is_active=1 LIMIT 1");
        $st->execute([$type]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

// Local değer (kod veya label) → HKS kodu. Bulunamazsa null.
function hks_resolve_reference_code(string $type, mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $pdo = db();
        // 1) Zaten kod mu?
        $st = $pdo->prepare("SELECT ref_code FROM hks_reference_cache WHERE ref_type=? AND ref_code=? AND is_active=1 LIMIT 1");
        $st->execute([$type, $value]);
        $c = $st->fetchColumn();
        if ($c !== false) return (string)$c;
        // 2) Label eşleşmesi (büyük/küçük harf duyarsız)
        $st = $pdo->prepare("SELECT ref_code FROM hks_reference_cache WHERE ref_type=? AND LOWER(ref_name)=LOWER(?) AND is_active=1 LIMIT 1");
        $st->execute([$type, $value]);
        $c = $st->fetchColumn();
        return $c !== false ? (string)$c : null;
    } catch (Throwable) {
        return null;
    }
}

// HKS kodu → okunabilir label. Bulunamazsa null.
function hks_resolve_reference_label(string $type, mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT ref_name FROM hks_reference_cache WHERE ref_type=? AND ref_code=? AND is_active=1 LIMIT 1");
        $st->execute([$type, $value]);
        $c = $st->fetchColumn();
        if ($c !== false) return (string)$c;
        // Zaten label olabilir
        $st = $pdo->prepare("SELECT ref_name FROM hks_reference_cache WHERE ref_type=? AND LOWER(ref_name)=LOWER(?) AND is_active=1 LIMIT 1");
        $st->execute([$type, $value]);
        $c = $st->fetchColumn();
        return $c !== false ? (string)$c : null;
    } catch (Throwable) {
        return null;
    }
}

// ── Depo / şube çözümleme ────────────────────────────────
// Form ayrı depo alanı taşımıyor. Payload için ayar (hks_settings) üzerinden
// varsayılan depo/şube alınır. gidecek_yer'den depo TAHMİN EDİLMEZ.
function hks_resolve_depo(array $notification, array $settings): ?string {
    foreach (['default_depo', 'depo', 'depo_id', 'sube', 'default_sube'] as $k) {
        $v = trim((string)($settings[$k] ?? ''));
        if ($v !== '') return $v;
    }
    // Kayıtta DB depo alanı doluysa (eski türetilmiş) son çare olarak kullanılmaz —
    // HKS için ayar bazlı resmi depo şarttır. null döndür.
    return null;
}

// ── Alan mapping tanımı ──────────────────────────────────
// Her satır: local alan, GERÇEK HKS alanı (WSDL), zorunlu mu, ref türü, kod zorunluluğu sert mi.
//
// GERÇEK WSDL YAPISI (BildirimServisBildirimKaydet → BildirimKayitIstek):
//   BildirimKayitIstek {
//     int                          BildirimTuru;              (top-level)
//     BildirimMalBilgileriDTO      BildirimMalBilgileri;
//     BildirimciBilgileriDTO       BildirimciBilgileri;       { int KisiSifat; }
//     IkinciKisiBilgileriDTO       IkinciKisiBilgileri;
//     MalinGidecekYerBilgileriDTO  MalinGidecekYerBilgileri;
//     long                         ReferansBildirimKunyeNo;   (top-level)
//     string                       UniqueId;                  (idempotency)
//   }
//
// NOT — payload'a GİRMEYEN local alanlar (haritada yok, gönderimi bloke etmez):
//   bildirimci_tc_vkn, firma  → HKS hesabı (UserName) üzerinden örtük gelir, istekte yok.
//   uretici_tc_vkn, uretici_ad → BildirimKayitIstek'te üretici alanı yok (cevapta var).
//   gidecek_sahibi_tc          → istekte TC değil GidecekIsyeriId (id) ile gider.
//   sevk_tarihi                → istekte tarih alanı yok; HKS kayıt anında KayitTarihi atar.
function hks_payload_field_map(): array {
    return [
        // local key,            HKS alanı (GERÇEK WSDL),     zorunlu, ref türü,        sert-kod
        ['notification_type',    'BildirimTuru',              true,    'bildirim_turu', false],
        ['sifat',                'KisiSifat',                 true,    'sifat',         false],  // BildirimciBilgileri.KisiSifat
        ['alici_tc_vkn',         'TcKimlikVergiNo',           true,    null,            false],  // IkinciKisi
        ['alici_ad',             'AdSoyad',                   true,    null,            false],  // IkinciKisi
        ['karsi_sifat',          'KisiSifat',                 false,   'sifat',         false],  // IkinciKisi.KisiSifat
        ['gsm',                  'CepTel',                    false,   null,            false],  // IkinciKisi
        ['eposta',               'Eposta',                    false,   null,            false],  // IkinciKisi
        ['malin_niteligi',       'MalinNiteligi',             true,    'malin_niteligi',false],  // Mal
        ['malin_turu',           'UretimSekli',               true,    'uretim_sekli',  false],  // Mal
        ['urun',                 'MalinKodNo',                true,    'urun',          true],   // Mal
        ['urun_cinsi',           'MalinCinsiId',              true,    'urun_cins',     true],   // Mal
        ['birim',                'MiktarBirimId',             true,    'urun_birim',    true],   // Mal
        ['miktar',               'MalinMiktari',              true,    null,            false],  // Mal
        ['birim_fiyat',          'MalinSatisFiyat',           false,   null,            false],  // Mal
        ['gelen_ulke',           'GelenUlkeId',               false,   'ulke',          false],  // Mal (İthalde koşullu zorunlu)
        ['il',                   'UretimIlId',                true,    'il',            true],   // Mal
        ['ilce',                 'UretimIlceId',              false,   'ilce',          false],  // Mal
        ['belde',                'UretimBeldeId',             false,   'belde',         false],  // Mal
        ['gidecek_yer_isletme_turu','GidecekYerIsletmeTuruId', true,   'isletme_turu',  false],  // GidecekYer (= gidecek_yer)
        ['gidecek_isyeri_id',    'GidecekIsyeriId',           false,   null,            false],  // GidecekYer (zaten HKS Id)
        ['ihracat_ulke',         'GidecekUlkeId',             false,   'ulke',          false],  // GidecekYer
        ['gidecek_yer_il',       'GidecekYerIlId',            false,   'il',            false],  // GidecekYer (kayıtsız yurt içinde koşullu)
        ['gidecek_yer_ilce',     'GidecekYerIlceId',          false,   'ilce',          false],  // GidecekYer
        ['gidecek_yer_belde',    'GidecekYerBeldeId',         false,   'belde',         false],  // GidecekYer
        ['arac_plaka',           'AracPlakaNo',               true,    null,            false],  // GidecekYer
        ['belge_no',             'BelgeNo',                   false,   null,            false],  // GidecekYer
        ['belge_tipi',           'BelgeTipi',                 false,   'belge_tipi',    false],  // GidecekYer
        ['reference_kunye_no',   'ReferansBildirimKunyeNo',   false,   null,            false],  // top-level
    ];
}

// ── Resmi mapping spesifikasyonu (PDF + WSDL) ────────────
// KAYNAK: GTB Hal Kayıt Sistemi Web Servisleri Geliştirici Kılavuzu (Sürüm 0.1.7)
//         §4.4 BildirimKaydet + §5 çalışma prensipleri  ·  canlı WSDL __getTypes().
// Her alan tahminle DEĞİL, iki kaynaktan doğrulanır. Otomatik onay YOK.
//
// Sütunlar:
//   local      → bizim notification alan adı ('' = formda karşılığı yok)
//   dto        → ait olduğu HKS DTO'su (yapı)
//   pdf_field  → resmi kılavuzdaki HKS alan adı ('' = kılavuzda yok)
//   wsdl_field → canlı WSDL'de görülen HKS alan adı ('' = WSDL'de yok)
//   hks_type   → HKS tipi (int/long/double/bool/string/DTO)
//   helper     → Id'nin alınacağı yardımcı servis ('' = yok)
//   ref_type   → hks_reference_cache ref türü (label→Id çözümü için, null=yok)
//   transform  → dönüştürme yöntemi
//   required   → çalışma prensiplerine göre (koşullu) zorunlu mu
//   note       → koşul/uyarı
//
// pdf_field & wsdl_field karşılaştırması → durum (PDF+WSDL / PDF / WSDL / Belirsiz / Eşleşmedi)
// runtime'da hks_payload_mapping_status_report() tarafından hesaplanır.
function hks_payload_field_spec(): array {
    return [
        // ── BildirimKayitIstek (üst seviye) ──
        ['local'=>'',                  'dto'=>'BildirimKayitIstek', 'pdf_field'=>'UniqueId',                'wsdl_field'=>'UniqueId',                'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'AF-<id> üret (idempotency)',        'required'=>false, 'note'=>'Takip numarası; her bildirime özgü.'],
        ['local'=>'notification_type', 'dto'=>'BildirimKayitIstek', 'pdf_field'=>'BildirimTuru',            'wsdl_field'=>'BildirimTuru',            'hks_type'=>'int',    'helper'=>'BildirimServisBildirimTurleri','ref_type'=>'bildirim_turu', 'transform'=>'label→Id (cache)',                  'required'=>true,  'note'=>'PDF helper\'ı yanlışlıkla SifatListesi yazıyor; doğrusu BildirimTurleri (WSDL).'],
        ['local'=>'reference_kunye_no','dto'=>'BildirimKayitIstek', 'pdf_field'=>'ReferansBildirimKunyeNo', 'wsdl_field'=>'ReferansBildirimKunyeNo', 'hks_type'=>'long',   'helper'=>'BildirimServisReferansKunyeler','ref_type'=>null,           'transform'=>'sayı; referanssız=0',               'required'=>false, 'note'=>'Referanssız bildirimde 0 gönderilir.'],

        // ── BildirimciBilgileriDTO ──
        ['local'=>'sifat',             'dto'=>'BildirimciBilgileri','pdf_field'=>'KisiSifat',               'wsdl_field'=>'KisiSifat',               'hks_type'=>'int',    'helper'=>'BildirimServisSifatListesi',   'ref_type'=>'sifat',         'transform'=>'label→Id (cache)',                  'required'=>true,  'note'=>'Bildirimci sıfat id\'si.'],

        // ── IkinciKisiBilgileriDTO ──
        ['local'=>'karsi_sifat',       'dto'=>'IkinciKisiBilgileri','pdf_field'=>'KisiSifat',               'wsdl_field'=>'KisiSifat',               'hks_type'=>'int',    'helper'=>'BildirimServisSifatListesi',   'ref_type'=>'sifat',         'transform'=>'label→Id (cache)',                  'required'=>false, 'note'=>'İkinci kişi sıfat id\'si (DTO içinde tek zorunlu alan).'],
        ['local'=>'alici_tc_vkn',      'dto'=>'IkinciKisiBilgileri','pdf_field'=>'TcKimlikVergiNo',         'wsdl_field'=>'TcKimlikVergiNo',         'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string (baştaki 0 korunur)',        'required'=>false, 'note'=>'GTB\'de kayıtlıysa diğer alanlar zorunlu değil.'],
        ['local'=>'alici_ad',          'dto'=>'IkinciKisiBilgileri','pdf_field'=>'AdSoyad',                 'wsdl_field'=>'AdSoyad',                 'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>false, 'note'=>'Kayıtsız ikinci kişide zorunlu.'],
        ['local'=>'eposta',            'dto'=>'IkinciKisiBilgileri','pdf_field'=>'Eposta',                  'wsdl_field'=>'Eposta',                  'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>false, 'note'=>''],
        ['local'=>'gsm',               'dto'=>'IkinciKisiBilgileri','pdf_field'=>'CepTel',                  'wsdl_field'=>'CepTel',                  'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>false, 'note'=>''],
        ['local'=>'yurt_disi',         'dto'=>'IkinciKisiBilgileri','pdf_field'=>'YurtDisiMi',              'wsdl_field'=>'YurtDisiMi',              'hks_type'=>'bool',   'helper'=>'',                          'ref_type'=>null,            'transform'=>'0/1 → bool',                        'required'=>false, 'note'=>'true ise referanssız bildirim yapılamaz.'],
        ['local'=>'dogum_tarihi',      'dto'=>'IkinciKisiBilgileri','pdf_field'=>'',                        'wsdl_field'=>'DogumTarihi',             'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>false, 'note'=>'WSDL\'de var, kılavuzda açıklaması yok → belirsiz.'],

        // ── BildirimMalBilgileriDTO ──
        ['local'=>'il',                'dto'=>'BildirimMalBilgileri','pdf_field'=>'UretimIlId',             'wsdl_field'=>'UretimIlId',              'hks_type'=>'int',    'helper'=>'GenelServisIller',             'ref_type'=>'il',            'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'Referanssızda 0 olamaz.'],
        ['local'=>'ilce',              'dto'=>'BildirimMalBilgileri','pdf_field'=>'UretimIlceId',           'wsdl_field'=>'UretimIlceId',            'hks_type'=>'int',    'helper'=>'GenelServisIlceler',           'ref_type'=>'ilce',          'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'Referanssızda 0 olamaz.'],
        ['local'=>'belde',             'dto'=>'BildirimMalBilgileri','pdf_field'=>'UretimBeldeId',          'wsdl_field'=>'UretimBeldeId',           'hks_type'=>'int',    'helper'=>'GenelServisBeldeler',          'ref_type'=>'belde',         'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'Referanssızda 0 olamaz.'],
        ['local'=>'malin_niteligi',    'dto'=>'BildirimMalBilgileri','pdf_field'=>'MalinNiteligi',         'wsdl_field'=>'MalinNiteligi',           'hks_type'=>'int',    'helper'=>'UrunServiceMalinNiteligi',     'ref_type'=>'malin_niteligi','transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'İthalat sıfatıyla tutarlı olmalı.'],
        ['local'=>'urun',              'dto'=>'BildirimMalBilgileri','pdf_field'=>'MalinKodNo',             'wsdl_field'=>'MalinKodNo',              'hks_type'=>'int',    'helper'=>'UrunServiceUrunler',           'ref_type'=>'urun',          'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'Referanssızda 0 olamaz.'],
        ['local'=>'malin_turu',        'dto'=>'BildirimMalBilgileri','pdf_field'=>'UretimSekli',           'wsdl_field'=>'UretimSekli',             'hks_type'=>'int',    'helper'=>'UrunServiceUretimSekilleri',   'ref_type'=>'uretim_sekli',  'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'KARAR: malin_turu alanı = UretimSekli. Form "Malın Türü" seçenekleri (Konvansiyonel/Organik/İyi Tarım) üretim şekilleridir; ayrı uretim_sekli alanı EKLENMEDİ. Referanssızda 0 olamaz; İthalat/Toplama mal → Konvansiyonel.'],
        ['local'=>'urun_cinsi',        'dto'=>'BildirimMalBilgileri','pdf_field'=>'MalinCinsiId',          'wsdl_field'=>'MalinCinsiId',            'hks_type'=>'int',    'helper'=>'UrunServiceUrunCinsleri',      'ref_type'=>'urun_cins',     'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>'Referanssızda 0 olamaz (kılavuz: UrunCinsi).'],
        ['local'=>'birim',             'dto'=>'BildirimMalBilgileri','pdf_field'=>'MiktarBirimId',          'wsdl_field'=>'MiktarBirimId',           'hks_type'=>'int',    'helper'=>'UrunServiceUrunBirimleri',     'ref_type'=>'urun_birim',    'transform'=>'label→Id (cache); boş=0',           'required'=>true,  'note'=>''],
        ['local'=>'miktar',            'dto'=>'BildirimMalBilgileri','pdf_field'=>'MalinMiktari',           'wsdl_field'=>'MalinMiktari',            'hks_type'=>'double', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'TR ondalık → double',               'required'=>true,  'note'=>''],
        ['local'=>'birim_fiyat',       'dto'=>'BildirimMalBilgileri','pdf_field'=>'MalinSatisFiyat',        'wsdl_field'=>'MalinSatisFiyat',         'hks_type'=>'double', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'TR ondalık → double',               'required'=>false, 'note'=>'Satış/Satın almada gerekir.'],
        ['local'=>'gelen_ulke',        'dto'=>'BildirimMalBilgileri','pdf_field'=>'GelenUlkeId',            'wsdl_field'=>'GelenUlkeId',             'hks_type'=>'int',    'helper'=>'GenelServisUlkeler',           'ref_type'=>'ulke',          'transform'=>'label→Id (cache); boş=0',           'required'=>false, 'note'=>'Malın niteliği "İthal" ise zorunlu (form alanı eklendi).'],
        ['local'=>'analize_gonder',    'dto'=>'BildirimMalBilgileri','pdf_field'=>'AnalizeGonderilecekMi', 'wsdl_field'=>'AnalizeGonderilecekMi',   'hks_type'=>'bool',   'helper'=>'',                          'ref_type'=>null,            'transform'=>'0/1 → bool',                        'required'=>false, 'note'=>'Sevk referanslı bildirimde analize gönderilemez.'],

        // ── MalinGidecekYerBilgileriDTO ──
        ['local'=>'gidecek_yer_isletme_turu','dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekYerIsletmeTuruId','wsdl_field'=>'GidecekYerIsletmeTuruId','hks_type'=>'int', 'helper'=>'GenelServisIsletmeTurleri', 'ref_type'=>'isletme_turu',  'transform'=>'label→Id (cache); boş olamaz',      'required'=>true,  'note'=>'= Gideceği Yer seçimi (gidecek_yer). Her zaman 0 olamaz.'],
        ['local'=>'gidecek_isyeri_id', 'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekIsyeriId',    'wsdl_field'=>'GidecekIsyeriId',         'hks_type'=>'int',    'helper'=>'HalIciIsyeri/Depolar/Subeler', 'ref_type'=>null,            'transform'=>'doğrudan HKS Id (form seçimi); boşsa ayar depo',  'required'=>false, 'note'=>'İşletme türüne göre kaynak (kılavuz §5): Hal İçi İşyeri→HalIciIsyeri; Hal Dışı İşyeri/Sınai/Perakende→Subeler; Hal İçi/Dışı Deposu→Depolar. Kayıtlı yurt içinde zorunlu.'],
        ['local'=>'ihracat_ulke',      'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekUlkeId',     'wsdl_field'=>'GidecekUlkeId',           'hks_type'=>'int',    'helper'=>'GenelServisUlkeler',           'ref_type'=>'ulke',          'transform'=>'label→Id (cache); boş=0',           'required'=>false, 'note'=>'Gideceği yer Yurt Dışı ise 0 olamaz.'],
        ['local'=>'gidecek_yer_il',    'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekYerIlId',     'wsdl_field'=>'GidecekYerIlId',          'hks_type'=>'int',    'helper'=>'GenelServisIller',             'ref_type'=>'il',            'transform'=>'label→Id (cache); boş=0',           'required'=>false, 'note'=>'Kayıtsız yurt içi gidecek yerde zorunlu (form alanı eklendi).'],
        ['local'=>'gidecek_yer_ilce',  'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekYerIlceId',   'wsdl_field'=>'GidecekYerIlceId',        'hks_type'=>'int',    'helper'=>'GenelServisIlceler',           'ref_type'=>'ilce',          'transform'=>'label→Id (cache); boş=0',           'required'=>false, 'note'=>'Kayıtsız yurt içi gidecek yerde zorunlu (form alanı eklendi).'],
        ['local'=>'gidecek_yer_belde', 'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'GidecekYerBeldeId',  'wsdl_field'=>'GidecekYerBeldeId',       'hks_type'=>'int',    'helper'=>'GenelServisBeldeler',          'ref_type'=>'belde',         'transform'=>'label→Id (cache); boş=0',           'required'=>false, 'note'=>'Kılavuz "0 olamaz" der; pratikte belde olmayabilir → form bloke etmez, koşullu not.'],
        ['local'=>'arac_plaka',        'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'AracPlakaNo',        'wsdl_field'=>'AracPlakaNo',             'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>true,  'note'=>'Her zaman boş olamaz (çalışma prensipleri).'],
        ['local'=>'belge_no',          'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'',                   'wsdl_field'=>'BelgeNo',                 'hks_type'=>'string', 'helper'=>'',                          'ref_type'=>null,            'transform'=>'string',                            'required'=>false, 'note'=>'WSDL\'de var, kılavuzda yok → belirsiz.'],
        ['local'=>'belge_tipi',        'dto'=>'MalinGidecekYerBilgileri','pdf_field'=>'',                   'wsdl_field'=>'BelgeTipi',               'hks_type'=>'int',    'helper'=>'BildirimServisBelgeTipleriListesi','ref_type'=>'belge_tipi','transform'=>'label→Id (cache)',                  'required'=>false, 'note'=>'WSDL\'de var, kılavuz alan tablosunda yok → belirsiz.'],
    ];
}

// Bir spec satırının PDF↔WSDL doğrulama durumunu döndürür.
//   'pdf_wsdl' | 'pdf' | 'wsdl' | 'belirsiz' | 'eslesmedi'
function hks_payload_field_match_status(array $spec_row): string {
    $pdf  = trim((string)($spec_row['pdf_field'] ?? '')) !== '';
    $wsdl_name = trim((string)($spec_row['wsdl_field'] ?? ''));
    // WSDL doğrulaması: cache'te (introspeksiyonla) gerçekten görülmüş mü?
    $wsdl_seen = $wsdl_name !== '' && hks_payload_field_is_confirmed($wsdl_name);
    if ($pdf && $wsdl_seen)  return 'pdf_wsdl';
    if ($pdf && $wsdl_name !== '') return 'pdf';       // PDF + WSDL tipinde var ama introspeksiyon yok
    if ($pdf && !$wsdl_seen)  return 'pdf';
    if (!$pdf && $wsdl_name !== '') return 'belirsiz'; // WSDL'de var, PDF açıklaması yok
    return 'eslesmedi';
}

// ── Bilerek dışarıda bırakılan alanlar (BildirimKayitIstek'e GİRMEYEN) ──
// Her satır gönderim dışıdır; kararın gerekçesi PDF/WSDL kanıtıyla belgelidir.
// 'durum' => 'disarida' (kasıtlı), gönderimi bloke ETMEZ.
function hks_payload_excluded_fields(): array {
    return [
        [
            'name'   => 'Halicimi',
            'kaynak' => 'GenelServisDepolar → DepoDTO (çıktı niteliği)',
            'pdf'    => true,   // PDF'de var: DepoDTO + çalışma prensipleri §5
            'wsdl_request' => false, // BildirimKayitIstek/MalinGidecekYerBilgileriDTO'da YOK
            'durum'  => 'disarida',
            'note'   => 'Halicimi, DEPO LİSTESİ cevabının (DepoDTO) bool alanıdır (True=hal içi, '
                      . 'False=hal dışı). BildirimKayitIstek\'te BU AD YOK (PDF alan tablosu + WSDL doğruladı). '
                      . 'Hal içi/dışı ayrımı zaten GidecekYerIsletmeTuruId ("Hal İçi Deposu"/"Hal Dışı Deposu") '
                      . 'ile taşınır. Bu yüzden gönderime ayrı alan eklenmedi; yalnız depo seçiminde etiket olarak kullanılır.',
        ],
        [
            'name'   => 'BildirimciTcVkn / Unvan',
            'kaynak' => 'HKS hesabı (UserName) — örtük',
            'pdf'    => true, 'wsdl_request' => false, 'durum' => 'disarida',
            'note'   => 'Bildirimci kimliği istek gövdesinde değil, kimlik bilgileri üzerinden gelir.',
        ],
        [
            'name'   => 'UreticiTcKimlikVergiNo',
            'kaynak' => 'Çalışma prensipleri (senaryo kuralı)',
            'pdf'    => true, 'wsdl_request' => false, 'durum' => 'disarida',
            'note'   => 'Ayrı üretici alanı istek DTO\'sunda yok; "Üreticiden Sevk Alım"da IkinciKisi.TcKimlikVergiNo ile karşılanır.',
        ],
        [
            'name'   => 'SevkTarihi',
            'kaynak' => '—',
            'pdf'    => false, 'wsdl_request' => false, 'durum' => 'disarida',
            'note'   => 'İstek DTO\'sunda tarih alanı yok; HKS kayıt anında KayitTarihi atar.',
        ],
    ];
}

// ── Onaya hazırlık raporu (SALT RAPOR — kilidi AÇMAZ) ──
// approval_ready true olsa bile HKS_PAYLOAD_FIELDS_CONFIRMED true YAPILMAZ.
// Onay ayrı bir sprintte, kullanıcı kararıyla verilir.
function hks_payload_approval_readiness_report(): array {
    $spec = hks_payload_field_spec();
    $introspected = !empty(hks_wsdl_all_confirmed_field_names());

    $confirmed = 0; $uncertain = 0; $missing = 0;
    $blocking  = []; $warnings = [];

    foreach ($spec as $row) {
        $status = hks_payload_field_match_status($row);   // pdf_wsdl|pdf|wsdl|belirsiz|eslesmedi
        $local  = trim((string)$row['local']);
        $req    = !empty($row['required']);
        $label  = ($row['pdf_field'] !== '' ? $row['pdf_field'] : $row['wsdl_field']);

        if ($status === 'pdf_wsdl') $confirmed++;
        if ($status === 'belirsiz') {
            $uncertain++;
            $warnings[] = $label . ' — WSDL\'de var, PDF açıklaması belirsiz (gönderim için kullanılmıyor).';
        }
        // Zorunlu alanda form/DB karşılığı yoksa → eksik (bloke)
        if ($req && $local === '') {
            $missing++;
            $blocking[] = $label . ' — zorunlu alan için form/DB karşılığı yok.';
            continue;
        }
        // Zorunlu alan PDF+WSDL ile kesinleşmemişse → bloke
        if ($req && $status !== 'pdf_wsdl') {
            $blocking[] = $label . ' — zorunlu alan PDF+WSDL ile doğrulanmadı (mevcut durum: ' . $status . ').';
        }
    }

    // WSDL introspeksiyonu hiç çalışmadıysa onay verilemez.
    if (!$introspected) {
        array_unshift($blocking,
            'WSDL introspeksiyonu çalıştırılmadı — alan adları canlı WSDL ile teyit edilmeli (HKS Teknik → WSDL Tipleri → WSDL Oku → Doğrula ve Kaydet).');
    }

    $blocking = array_values(array_unique($blocking));
    $warnings = array_values(array_unique($warnings));

    return [
        'approval_ready'         => empty($blocking),
        'blocking_items'         => $blocking,
        'warnings'               => $warnings,
        'confirmed_fields_count' => $confirmed,
        'uncertain_fields_count' => $uncertain,
        'missing_fields_count'   => $missing,
        'total_fields'           => count($spec),
        'introspected'           => $introspected,
        // Kesin güvence: bu rapor kilidi AÇMAZ.
        'gate_open'              => hks_payload_fields_confirmed(),
    ];
}

// ── Mapping raporu ───────────────────────────────────────
// Her local alan için: HKS alanı, değer, kod (varsa), durum, kesinlik.
function hks_payload_mapping_report(array $notification, array $settings = []): array {
    $rows = [];
    $introspected = !empty(hks_wsdl_all_confirmed_field_names());  // introspeksiyon çalıştı mı?
    foreach (hks_payload_field_map() as [$local, $hks_field, $required, $ref_type, $hard]) {
        $raw = trim((string)($notification[$local] ?? ''));
        $out_value = $raw;
        $status = 'ok';

        // 1) Değer/kod seviyesi durumu
        if ($raw === '') {
            $status = $required ? 'missing' : 'empty';
        } elseif ($ref_type !== null) {
            $code = hks_resolve_reference_code($ref_type, $raw);
            if ($code !== null) {
                $out_value = $code . ' (' . $raw . ')';
                $status = 'ok';
            } elseif (!hks_reference_type_synced($ref_type)) {
                $status = 'ref_unsynced';   // liste senkron değil → doğrulanamadı
            } else {
                $status = 'code_missing';   // liste var ama kod yok
            }
        } elseif ($local === 'miktar') {
            $dec = hks_normalize_decimal($raw);
            if ($dec === null || (float)$dec <= 0) $status = 'invalid';
            else $out_value = $dec;
        } elseif ($local === 'birim_fiyat') {
            $dec = hks_normalize_decimal($raw);
            if ($dec === null) $status = 'invalid';
            else $out_value = $dec;
        } elseif ($local === 'sevk_tarihi') {
            $iso = hks_format_service_date($raw);
            if ($iso === null) $status = 'invalid';
            else $out_value = $iso;
        }

        // 2) Alan adı (WSDL) seviyesi — yalnız değer/kod sorunu yoksa baskın olur
        $certain = hks_payload_field_is_confirmed($hks_field);
        if (in_array($status, ['ok', 'empty'], true) && $introspected) {
            $status = $certain ? 'wsdl_ok' : 'wsdl_not_found';
        }

        $rows[] = [
            'local'    => $local,
            'hks_field'=> $hks_field,
            'value'    => $out_value,
            'required' => $required,
            'certain'  => $certain,         // alan adı canlı WSDL'den doğrulandı mı?
            'status'   => $status,
        ];
    }
    return $rows;
}

// ── Payload mapping doğrulama ────────────────────────────
// ['ready','errors','warnings','missing','mapping'] döner.
function hks_validate_bildirim_payload_mapping(array $notification, array $settings = []): array {
    $errors   = [];
    $warnings = [];
    $missing  = [];
    $mapping  = hks_payload_mapping_report($notification, $settings);

    $get = static fn(string $k): string => trim((string)($notification[$k] ?? ''));

    // Mapping satırlarından kullanıcı dostu eksik/uyumsuz mesajları üret.
    $labels = [
        'notification_type' => 'Bildirim türü', 'sifat' => 'Bildirimci sıfatı',
        'bildirimci_tc_vkn' => 'Bildirimci TC/VKN', 'firma' => 'Bildirimci ünvanı',
        'alici_tc_vkn' => 'Karşı taraf TC/VKN', 'alici_ad' => 'Karşı taraf ünvanı',
        'karsi_sifat' => 'Karşı taraf sıfatı', 'malin_niteligi' => 'Malın niteliği',
        'malin_turu' => 'Malın türü', 'urun' => 'Ürün', 'urun_cinsi' => 'Ürün cinsi',
        'birim' => 'Birim', 'miktar' => 'Miktar', 'birim_fiyat' => 'Birim fiyat',
        'gidecek_yer' => 'Gideceği yer', 'ihracat_ulke' => 'İhracat ülkesi',
        'arac_plaka' => 'Araç plaka', 'belge_tipi' => 'Belge tipi',
        'il' => 'Üretim ili', 'ilce' => 'İlçe', 'belde' => 'Belde',
        'sevk_tarihi' => 'Sevk tarihi',
        'gelen_ulke' => 'Gelen ülke', 'gidecek_yer_il' => 'Gidecek yer ili',
        'gidecek_yer_ilce' => 'Gidecek yer ilçesi', 'gidecek_yer_belde' => 'Gidecek yer beldesi',
        'gidecek_yer_isletme_turu' => 'Gidecek yer işletme türü', 'gidecek_isyeri_id' => 'Gidecek işyeri',
    ];
    foreach ($mapping as $row) {
        $lbl = $labels[$row['local']] ?? $row['local'];
        switch ($row['status']) {
            case 'missing':
                $missing[] = $lbl;
                $errors[]  = $lbl . ' boş — HKS gönderimi için gereklidir.';
                break;
            case 'code_missing':
                $errors[]  = $lbl . ' için HKS kodu bulunamadı. Referansları güncelleyin veya tekrar seçin.';
                break;
            case 'ref_unsynced':
                $warnings[] = $lbl . ' için HKS kod listesi senkronlanmamış; kod doğrulanamadı.';
                break;
            case 'invalid':
                if ($row['local'] === 'miktar')      $errors[] = 'Miktar 0\'dan büyük geçerli bir sayı olmalıdır.';
                elseif ($row['local'] === 'birim_fiyat') $errors[] = 'Birim fiyat geçerli bir sayı olmalıdır.';
                elseif ($row['local'] === 'sevk_tarihi') $errors[] = 'Sevk tarihi HKS tarih formatına çevrilemedi.';
                else $errors[] = $lbl . ' geçersiz.';
                break;
            case 'wsdl_not_found':
                // İntrospeksiyon çalıştı ama bu alan adı WSDL'de bulunamadı.
                if ($row['required']) {
                    $errors[] = $lbl . ' alanı (HKS: ' . $row['hks_field'] . ') WSDL\'de bulunamadı — mapping düzeltilmeli.';
                } else {
                    $warnings[] = $lbl . ' alanı (HKS: ' . $row['hks_field'] . ') WSDL\'de bulunamadı.';
                }
                break;
        }
    }

    // Koşullu kurallar
    if ($get('belge_no') !== '' && $get('belge_tipi') === '') {
        $errors[] = 'Belge no girildiği için belge tipi seçilmelidir.';
    }
    $yurt_disi = (int)($notification['yurt_disi'] ?? 0) === 1
        || mb_strtolower($get('gidecek_yer')) === mb_strtolower('Yurt Dışı');
    if ($yurt_disi && $get('ihracat_ulke') === '') {
        $errors[] = 'Yurt dışı bildirimi için ihracat yapılan ülke seçilmelidir.';
    }

    // Depo / şube — ayar bazlı zorunlu
    if (hks_resolve_depo($notification, $settings) === null) {
        $errors[] = 'HKS gönderimi için varsayılan depo/şube bilgisi ayarlanmalıdır.';
    }

    // Mapping onayı — kritik kural: alan eşleştirmesi PDF + WSDL ile doğrulanıp
    // KULLANICI ONAYI verilene kadar gönderim bloke. WSDL introspeksiyonu tek başına açmaz.
    $fields_confirmed = hks_payload_fields_confirmed();
    if (!$fields_confirmed) {
        if (!hks_mapping_approved()) {
            $warnings[] = 'BildirimKaydet alan eşleştirmesi henüz onaylanmadı. '
                        . 'HKS Teknik → Mapping Raporu ekranını inceleyin; onay ayrı sprintte verilecek. Gönderim kapalı.';
        }
        if (empty(hks_wsdl_all_confirmed_field_names())) {
            $warnings[] = 'WSDL alan adları henüz introspeksiyonla doğrulanmadı '
                        . '(HKS Teknik → WSDL Tipleri → WSDL Oku). Rapor canlı doğrulama olmadan eksik kalır.';
        }
    }

    // HKS çalışma mantığı — kişi/künye doğrulaması (Workflow-Validation-01).
    $turu_pm = trim((string)($notification['notification_type'] ?? ''));
    if (function_exists('hks_karsi_taraf_required') && hks_karsi_taraf_required($turu_pm)) {
        if ((int)($notification['karsi_kisi_kayitli_degil'] ?? 0) === 1) {
            if (!hks_karsi_kayitsiz_allowed($turu_pm)) {
                $errors[] = 'Bu bildirim türünde kayıtlı olmayan kişiyle gönderim yapılamaz.';
            }
        } elseif (!hks_karsi_kisi_is_verified($notification)) {
            $errors[] = 'Karşı taraf HKS\'de doğrulanmadı.';
        }
    }
    if (trim((string)($notification['reference_kunye_no'] ?? '')) !== ''
        && function_exists('hks_reference_kunye_is_verified')
        && !hks_reference_kunye_is_verified($notification)) {
        $errors[] = 'Referans künye HKS\'de doğrulanmadı.';
    }

    $ready = empty($errors) && $fields_confirmed;

    return [
        'ready'    => $ready,
        'errors'   => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings)),
        'missing'  => array_values(array_unique($missing)),
        'mapping'  => $mapping,
    ];
}

// ── Payload builder ──────────────────────────────────────
// DB kaydını HKS BildirimKayitIstek gövdesine çevirir. Credentials İÇERMEZ
// (UserName/Password/ServicePassword gönderim anında HksClient ekler).
//
// Yapı GERÇEK WSDL tiplerine birebir uyar (bkz. hks_payload_field_map() notu):
//   BildirimMalBilgileri / BildirimciBilgileri / IkinciKisiBilgileri /
//   MalinGidecekYerBilgileri alt-DTO'ları + BildirimTuru/ReferansBildirimKunyeNo/UniqueId.
// Tip dönüşümleri WSDL'e göre: int/long → tamsayı, double → float, boolean → bool, string → metin.
function hks_build_bildirim_kaydet_payload(array $notification, array $settings = []): array {
    $n = $notification;

    // Ref kodunu çöz; çözülemezse ham değeri kullan (boşsa '').
    $codeStr = static fn(string $type, string $key): string
        => hks_resolve_reference_code($type, (string)($n[$key] ?? '')) ?? hks_clean_identifier($n[$key] ?? '');
    // int alanlar — kod çözülür, tamsayıya çevrilir (geçersiz/boş → 0).
    $codeInt = static fn(string $type, string $key): int
        => (int)(hks_resolve_reference_code($type, (string)($n[$key] ?? '')) ?? ($n[$key] ?? 0));
    // double alanlar — Türkçe ondalık normalize edilir.
    $dec = static fn(string $key): float => (float)(hks_normalize_decimal($n[$key] ?? null) ?? 0);

    // BildirimMalBilgileriDTO
    $mal = [
        'AnalizeGonderilecekMi' => (int)($n['analize_gonder'] ?? 0) === 1,
        'GelenUlkeId'   => $codeInt('ulke', 'gelen_ulke'),       // ithalatta dolu; yoksa 0
        'MalinCinsiId'  => $codeInt('urun_cins', 'urun_cinsi'),
        'MalinKodNo'    => $codeInt('urun', 'urun'),
        'MalinMiktari'  => $dec('miktar'),
        'MalinNiteligi' => $codeInt('malin_niteligi', 'malin_niteligi'),
        'MalinSatisFiyat' => $dec('birim_fiyat'),
        'MiktarBirimId' => $codeInt('urun_birim', 'birim'),
        'UretimBeldeId' => $codeInt('belde', 'belde'),
        'UretimIlId'    => $codeInt('il', 'il'),
        'UretimIlceId'  => $codeInt('ilce', 'ilce'),
        'UretimSekli'   => $codeInt('uretim_sekli', 'malin_turu'),
    ];

    // BildirimciBilgileriDTO — yalnız sıfat (TC/ünvan hesaptan örtük).
    $bildirimci = [
        'KisiSifat' => $codeInt('sifat', 'sifat'),
    ];

    // IkinciKisiBilgileriDTO — karşı taraf.
    $ikinci = [
        'AdSoyad'         => hks_clean_identifier($n['alici_ad'] ?? ''),
        'CepTel'          => hks_clean_identifier($n['gsm'] ?? ''),
        'DogumTarihi'     => hks_clean_identifier($n['dogum_tarihi'] ?? ''),
        'Eposta'          => hks_clean_identifier($n['eposta'] ?? ''),
        'KisiSifat'       => $codeInt('sifat', 'karsi_sifat'),
        'TcKimlikVergiNo' => hks_clean_identifier($n['alici_tc_vkn'] ?? ''),
        'YurtDisiMi'      => (int)($n['yurt_disi'] ?? 0) === 1,
    ];

    // MalinGidecekYerBilgileriDTO
    // GidecekIsyeriId — kullanıcının seçtiği işyeri Id'si (depo/şube/hal içi referansından,
    // zaten HKS kodu). Boşsa ayar bazlı varsayılan depoya düşülür.
    $isyeri_id = hks_clean_identifier($n['gidecek_isyeri_id'] ?? '');
    $gidecek_isyeri = $isyeri_id !== '' ? (int)$isyeri_id : (int)(hks_resolve_depo($n, $settings) ?? 0);
    // İşletme türü — kanonik alan gidecek_yer_isletme_turu; yoksa eski gidecek_yer'e düş.
    $isletme_turu_val = hks_clean_identifier($n['gidecek_yer_isletme_turu'] ?? '');
    if ($isletme_turu_val === '') $isletme_turu_val = hks_clean_identifier($n['gidecek_yer'] ?? '');
    $gidecek = [
        'AracPlakaNo'             => hks_clean_identifier($n['arac_plaka'] ?? ''),
        'BelgeNo'                 => hks_clean_identifier($n['belge_no'] ?? ''),
        'BelgeTipi'               => $codeInt('belge_tipi', 'belge_tipi'),
        'GidecekIsyeriId'         => $gidecek_isyeri,
        'GidecekUlkeId'           => $codeInt('ulke', 'ihracat_ulke'),
        'GidecekYerBeldeId'       => $codeInt('belde', 'gidecek_yer_belde'),
        'GidecekYerIlId'          => $codeInt('il', 'gidecek_yer_il'),
        'GidecekYerIlceId'        => $codeInt('ilce', 'gidecek_yer_ilce'),
        'GidecekYerIsletmeTuruId' => (int)(hks_resolve_reference_code('isletme_turu', $isletme_turu_val) ?? 0),
    ];

    // BildirimKayitIstek — alanlar WSDL sırasına yakın.
    $istek = [
        'BildirimMalBilgileri'     => $mal,
        'BildirimTuru'             => $codeInt('bildirim_turu', 'notification_type'),
        'BildirimciBilgileri'      => $bildirimci,
        'IkinciKisiBilgileri'      => $ikinci,
        'MalinGidecekYerBilgileri' => $gidecek,
        'UniqueId'                 => hks_payload_unique_id($n),
    ];
    // ReferansBildirimKunyeNo — long; boşsa 0 göndermek yerine yalnız doluysa ekle.
    $ref = hks_clean_identifier($n['reference_kunye_no'] ?? '');
    if ($ref !== '' && ctype_digit($ref)) {
        $istek['ReferansBildirimKunyeNo'] = (int)$ref;
    }

    // ArrayOfBildirimKayitIstek → Istek listesi (tek kayıt).
    return [
        'BildirimKayitIstek' => [$istek],
    ];
}

// UniqueId — idempotency anahtarı. Kayıt id'sinden türetilir; yoksa hash.
function hks_payload_unique_id(array $n): string {
    $id = hks_clean_identifier($n['id'] ?? '');
    if ($id !== '') return 'AF-' . $id;
    $seed = ($n['notification_type'] ?? '') . '|' . ($n['arac_plaka'] ?? '') . '|' . ($n['created_at'] ?? '');
    return 'AF-' . substr(sha1($seed), 0, 16);
}

// Payload (Istek gövdesi) içinde kimlik bilgisi anahtarı var mı? — çift-zarf koruması.
// Builder credentials EKLEMEMELİDİR; bunlar yalnız HksClient::buildRequest()'te eklenir.
function hks_payload_has_credentials(mixed $node): bool {
    if (!is_array($node)) return false;
    $creds = ['username', 'password', 'servicepassword'];
    foreach ($node as $k => $v) {
        if (is_string($k) && in_array(mb_strtolower($k), $creds, true)) return true;
        if (is_array($v) && hks_payload_has_credentials($v)) return true;
    }
    return false;
}

// ── Gönderim Hazırlık Kontrolü (DRY-RUN) ──────────────────
// Gerçek SOAP çağrısı YAPMADAN gönderim hattını test eder:
//   - payload builder'ı çalıştırır (nested BildirimKayitIstek)
//   - blocker'ları kontrol eder
//   - final request ŞEKLİNİ (credentials maskeli) kurar
//   - çift-zarf (credentials payload içinde mi) ve tekil-Istek kontrolü yapar
// callService / BildirimServisBildirimKaydet KESİNLİKLE çağrılmaz.
function hks_prepare_bildirim_send_request(int $notificationId): array {
    $repo = new HksRepository(db());
    $n = $repo->getNotification($notificationId);
    if (!$n) {
        return ['ready' => false, 'blockers' => ['Kayıt bulunamadı.'], 'payload' => null, 'request_shape_ok' => false];
    }
    $settings = $repo->getSettings() ?: [];
    $blockers = hks_send_blockers($n, $settings);

    // Asıl gönderim gövdesi (ArrayOfBildirimKayitIstek). Credentials İÇERMEZ.
    $istek_body = hks_build_bildirim_kaydet_payload($n, $settings);

    // Final request ŞEKLİ — HksClient::buildRequest() ile birebir; credentials MASKELİ.
    // Gerçek gönderimde UserName/Password/ServicePassword burada DEĞİL, HksClient'te eklenir.
    $request_shape = [
        'UserName'        => '***',
        'Password'        => '***',
        'ServicePassword' => '***',
        'Istek'           => $istek_body,   // { BildirimKayitIstek: [ {...} ] }
    ];

    // Şekil doğrulaması: tam olarak bir BildirimKayitIstek listesi + builder'da creds YOK +
    // zarf alanları tekil (yapı gereği buildRequest her birini bir kez ekler).
    $has_list = isset($istek_body['BildirimKayitIstek'])
        && is_array($istek_body['BildirimKayitIstek'])
        && count($istek_body['BildirimKayitIstek']) >= 1;
    $no_creds_in_body = !hks_payload_has_credentials($istek_body);
    $envelope_singular = (count(array_intersect(array_keys($request_shape), ['UserName','Password','ServicePassword','Istek'])) === 4);
    $request_shape_ok = $has_list && $no_creds_in_body && $envelope_singular;

    return [
        'ready'            => empty($blockers),
        'blockers'         => $blockers,
        'payload'          => hks_mask_payload_sensitive_fields($request_shape),
        'request_shape_ok' => $request_shape_ok,
        'istek_count'      => $has_list ? count($istek_body['BildirimKayitIstek']) : 0,
    ];
}

// ── Hassas alan maskeleme ────────────────────────────────
// Önizlemede UserName/Password/ServicePassword vb. asla görünmemeli.
// Builder bunları eklemez; bu fonksiyon defansif olarak yine de maskeler.
function hks_mask_payload_sensitive_fields(array $payload): array {
    $sensitive = ['username', 'password', 'servicepassword', 'sifre', 'servissifre',
                  'securityword', 'guvenlikkelimesi', 'token', 'csrf'];
    $walker = function ($node) use (&$walker, $sensitive) {
        if (!is_array($node)) return $node;
        $out = [];
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array(mb_strtolower($k), $sensitive, true)) {
                $out[$k] = '***';
                continue;
            }
            $out[$k] = is_array($v) ? $walker($v) : $v;
        }
        return $out;
    };
    return $walker($payload);
}
