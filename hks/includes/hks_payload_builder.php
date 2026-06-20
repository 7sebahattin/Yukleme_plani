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
        $st = db()->query("SELECT ref_code FROM hks_reference_cache WHERE ref_type LIKE 'wsdl_field:%' AND is_active=1");
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

// Tüm KRİTİK (zorunlu) alanlar WSDL'den doğrulandı mı? Manuel override de kabul edilir.
function hks_payload_fields_confirmed(): bool {
    if (HKS_PAYLOAD_FIELDS_CONFIRMED) return true;          // acil durum override
    $set = hks_wsdl_all_confirmed_field_names();
    if (empty($set)) return false;                          // introspeksiyon hiç çalışmadı
    foreach (hks_payload_field_map() as [$local, $hks, $required, $ref, $hard]) {
        if ($required && !isset($set[mb_strtolower($hks)])) return false;
    }
    return true;
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
// Her satır: local alan, provizyonel HKS alanı, zorunlu mu, kod gerektiriyor mu,
// ref türü, kod zorunluluğu sert mi (true=eksikse hata, false=uyarı).
function hks_payload_field_map(): array {
    return [
        // local key,            HKS alanı (PROVİZYON),       zorunlu, ref türü,        sert-kod
        ['notification_type',    'BildirimTuru',              true,    'bildirim_turu', false],
        ['sifat',                'BildirimciSifat',           true,    'sifat',         false],
        ['bildirimci_tc_vkn',    'BildirimciTcVkn',           true,    null,            false],
        ['firma',                'BildirimciUnvan',           true,    null,            false],
        ['alici_tc_vkn',         'KarsiTarafTcVkn',           true,    null,            false],
        ['alici_ad',             'KarsiTarafUnvan',           true,    null,            false],
        ['karsi_sifat',          'KarsiTarafSifat',           false,   'sifat',         false],
        ['gsm',                  'KarsiTarafGsm',             false,   null,            false],
        ['eposta',               'KarsiTarafEposta',          false,   null,            false],
        ['reference_kunye_no',   'ReferansKunyeNo',           false,   null,            false],
        ['malin_niteligi',       'MalinNiteligi',             true,    'malin_niteligi',false],
        ['malin_turu',           'MalinTuru',                 true,    'uretim_sekli',  false],
        ['urun',                 'UrunKodu',                  true,    'urun',          true],
        ['urun_cinsi',           'UrunCinsKodu',              true,    'urun_cins',     true],
        ['birim',                'BirimKodu',                 true,    'urun_birim',    true],
        ['miktar',               'Miktar',                    true,    null,            false],
        ['birim_fiyat',          'BirimFiyat',                false,   null,            false],
        ['uretici_tc_vkn',       'UreticiTcVkn',              false,   null,            false],
        ['uretici_ad',           'UreticiUnvan',              false,   null,            false],
        ['gidecek_yer',          'GidecekYerTuru',            true,    null,            false],
        ['ihracat_ulke',         'IhracatUlke',               false,   'ulke',          false],
        ['gidecek_sahibi_tc',    'GidecekYerSahibiTcVkn',     false,   null,            false],
        ['arac_plaka',           'AracPlaka',                 true,    null,            false],
        ['belge_no',             'BelgeNo',                   false,   null,            false],
        ['belge_tipi',           'BelgeTipi',                 false,   'belge_tipi',    false],
        ['il',                   'UretimIlKodu',              true,    'il',            true],
        ['ilce',                 'UretimIlceKodu',            false,   'ilce',          false],
        ['belde',                'UretimBeldeKodu',           false,   'belde',         false],
        ['sevk_tarihi',          'SevkTarihi',                true,    null,            false],
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

    // Alan adı belirsizliği — kritik alanlar WSDL'den doğrulanana kadar gönderim bloke
    $fields_confirmed = hks_payload_fields_confirmed();
    if (!$fields_confirmed) {
        if (empty(hks_wsdl_all_confirmed_field_names())) {
            $warnings[] = 'HKS WSDL alan adları (BildirimKayitIstek) henüz introspeksiyonla '
                        . 'doğrulanmadı. HKS Teknik → WSDL Tipleri ekranından çalıştırın. Gönderim kapalı.';
        } else {
            $warnings[] = 'Bazı kritik HKS alan adları WSDL\'de bulunamadı; payload mapping güncellenene '
                        . 'kadar gönderim kapalı.';
        }
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
// DB kaydını HKS Istek gövdesine çevirir. Credentials İÇERMEZ.
// Not: HKS alan adları provizyondur (HKS_PAYLOAD_FIELDS_CONFIRMED bkz.).
function hks_build_bildirim_kaydet_payload(array $notification, array $settings = []): array {
    $code = static fn(string $type, string $key): ?string
        => hks_resolve_reference_code($type, (string)($notification[$key] ?? ''));

    $bildirimci = [
        'BildirimTuru'      => $code('bildirim_turu', 'notification_type') ?? hks_clean_identifier($notification['notification_type'] ?? ''),
        'BildirimciSifat'   => $code('sifat', 'sifat') ?? hks_clean_identifier($notification['sifat'] ?? ''),
        'BildirimciTcVkn'   => hks_clean_identifier($notification['bildirimci_tc_vkn'] ?? ''),
        'BildirimciUnvan'   => hks_clean_identifier($notification['firma'] ?? ''),
    ];

    $karsi = [
        'KarsiTarafTcVkn'   => hks_clean_identifier($notification['alici_tc_vkn'] ?? ''),
        'KarsiTarafUnvan'   => hks_clean_identifier($notification['alici_ad'] ?? ''),
        'KarsiTarafSifat'   => $code('sifat', 'karsi_sifat') ?? hks_clean_identifier($notification['karsi_sifat'] ?? ''),
        'KarsiTarafGsm'     => hks_clean_identifier($notification['gsm'] ?? ''),
        'KarsiTarafEposta'  => hks_clean_identifier($notification['eposta'] ?? ''),
        'YurtDisi'          => (int)($notification['yurt_disi'] ?? 0) === 1,
    ];
    if (!empty($notification['dogum_tarihi'])) {
        $karsi['KarsiTarafDogumTarihi'] = hks_format_service_date((string)$notification['dogum_tarihi']);
    }

    $mal = [
        'MalinNiteligi' => $code('malin_niteligi', 'malin_niteligi') ?? hks_clean_identifier($notification['malin_niteligi'] ?? ''),
        'MalinTuru'     => $code('uretim_sekli', 'malin_turu') ?? hks_clean_identifier($notification['malin_turu'] ?? ''),
        'UrunKodu'      => $code('urun', 'urun') ?? '',
        'UrunCinsKodu'  => $code('urun_cins', 'urun_cinsi') ?? '',
        'BirimKodu'     => $code('urun_birim', 'birim') ?? '',
        'Miktar'        => hks_normalize_decimal($notification['miktar'] ?? null),
        'UreticiTcVkn'  => hks_clean_identifier($notification['uretici_tc_vkn'] ?? ''),
        'UreticiUnvan'  => hks_clean_identifier($notification['uretici_ad'] ?? ''),
        'AnalizeGonder' => (int)($notification['analize_gonder'] ?? 0) === 1,
    ];
    $fiyat = hks_normalize_decimal($notification['birim_fiyat'] ?? null);
    if ($fiyat !== null) {
        $mal['BirimFiyat']  = $fiyat;
        $mal['ParaBirimi']  = hks_clean_identifier($notification['para_birimi'] ?? 'TL') ?: 'TL';
    }

    $gidecek = [
        'GidecekYerTuru'        => hks_clean_identifier($notification['gidecek_yer'] ?? ''),
        'IhracatUlke'           => $code('ulke', 'ihracat_ulke') ?? hks_clean_identifier($notification['ihracat_ulke'] ?? ''),
        'GidecekYerSahibiTcVkn' => hks_clean_identifier($notification['gidecek_sahibi_tc'] ?? ''),
        'GidecekYerKayitliDegil'=> (int)($notification['gidecek_kayitli_degil'] ?? 0) === 1,
        'AracPlaka'             => hks_clean_identifier($notification['arac_plaka'] ?? ''),
        'BelgeNo'               => hks_clean_identifier($notification['belge_no'] ?? ''),
        'BelgeTipi'             => $code('belge_tipi', 'belge_tipi') ?? hks_clean_identifier($notification['belge_tipi'] ?? ''),
        'Depo'                  => hks_resolve_depo($notification, $settings),
        'UretimIlKodu'          => $code('il', 'il') ?? '',
        'UretimIlceKodu'        => $code('ilce', 'ilce') ?? hks_clean_identifier($notification['ilce'] ?? ''),
        'UretimBeldeKodu'       => $code('belde', 'belde') ?? hks_clean_identifier($notification['belde'] ?? ''),
        'SevkTarihi'            => hks_format_service_date((string)($notification['sevk_tarihi'] ?? '')),
    ];

    // Referans künye — boşsa hiç gönderme (WSDL'de 0/null ayrımı doğrulanmadı).
    $kayit = [
        'Bildirimci' => $bildirimci,
        'KarsiTaraf' => $karsi,
        'Mal'        => $mal,
        'GidecekYer' => $gidecek,
    ];
    $ref = hks_clean_identifier($notification['reference_kunye_no'] ?? '');
    if ($ref !== '') {
        $kayit['ReferansKunyeNo'] = $ref;
    }

    // BildirimKayitIstek listesi (tek kayıt)
    return [
        'BildirimKayitIstek' => [$kayit],
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
