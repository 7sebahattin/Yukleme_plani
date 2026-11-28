<?php
// =========================================================
// config/hizli_kart.php — Ortak "Hızlı Cari / Stok Ekle" çekirdeği (v611 Faz 2; include-only; yalnız fonksiyon bildirir)
//
// TEK uç api_hizli_kart.php bu dosyayı kullanır; pencere işaretlemesi config/hizli_kart_ui.php, istemci assets/hizli_kart.js|css.
// Bu dosya modül çekirdeklerini YÜKLEMEZ (çağıran yükler): cari için config/cari_core.php, stok için config/stok_core.php.
//   Yüklenmeyen modülün türü "kullanılamaz" sayılır (function_exists). Böylece Cari Kartları sayfası stok_core yüklemeden bileşeni basabilir.
// Yazma YALNIZ Faz 1 çekirdek fonksiyonlarıyla: cari_kart_hizli_ekle() / stok_kart_hizli_ekle() — burada SQL YAZILMAZ.
// İzin: modüllerin MEVCUT kapısı (cari_can / stok_can) — cari_kaydet.php / stok_kaydet.php kart ekleme dalıyla AYNI eylem ('create');
//   seçenek listeleri 'read'. Yeni yetki anahtarı YOK.
// Şema: cari_hazir + cari_ek_hazir / stok_hazir + stok_ek_hazir false iken tür kullanılamaz (uç `kod: sema_yok`, düğme/pencere çizilmez).
// Kişisel veri: yanıta ve seçeneklere telefon/TCKN/VKN GİRMEZ; audit çekirdekte (cari_kart_audit_veri TCKN/telefonu bayrağa çevirir).
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** @return array<string,string> tür → etiket (beyaz liste) */
function hizli_kart_turler(): array
{
    return ['cari' => 'Cari', 'stok' => 'Stok'];
}

/** Türün çekirdeği yüklü mü? */
function hizli_kart_cekirdek_var(string $tur): bool
{
    if ($tur === 'cari') return function_exists('cari_kart_hizli_ekle') && function_exists('cari_ek_hazir');
    if ($tur === 'stok') return function_exists('stok_kart_hizli_ekle') && function_exists('stok_ek_hazir');
    return false;
}

/** Tablolar + v611 ek şema hazır mı? (Faz 1 kartları: migrate.php › cari-ek / stok-ek) */
function hizli_kart_sema_hazir(PDO $pdo, string $tur): bool
{
    if (!hizli_kart_cekirdek_var($tur)) return false;
    if ($tur === 'cari') return cari_hazir($pdo) && cari_ek_hazir($pdo);
    return stok_hazir($pdo) && stok_ek_hazir($pdo);
}

/** Modül kapısı. $eylem: create (kart ekleme — cari_kaydet/stok_kaydet kart_kaydet yeni kart dalı) | read (seçenek listeleri). */
function hizli_kart_izin(string $tur, string $eylem): bool
{
    if (!in_array($eylem, ['create', 'read'], true) || !hizli_kart_cekirdek_var($tur)) return false;
    return $tur === 'cari' ? cari_can($eylem) : stok_can($eylem);
}

/** Düğme/pencere çizilsin mi: oluşturma izni + şema hazır. */
function hizli_kart_kullanilabilir(PDO $pdo, string $tur): bool
{
    return hizli_kart_izin($tur, 'create') && hizli_kart_sema_hazir($pdo, $tur);
}

/** Yanıtlarda kullanılan şema mesajı (yönetici için kart adı). */
function hizli_kart_sema_mesaj(string $tur): string
{
    return $tur === 'cari'
        ? 'Hızlı cari ekleme için yönetici: Şema Migrasyon › "Cari — Tipler · TCKN/Doğum tarihi · Hızlı ekleme" kartını çalıştırmalı.'
        : 'Hızlı stok ekleme için yönetici: Şema Migrasyon › "Stok — Ambalaj tipi · Dara · HKS ürün · Hızlı ekleme" kartını çalıştırmalı.';
}

/**
 * Pencerenin ihtiyaç duyduğu listeler (kişisel veri YOK). Yalnız okuma izni olan ve çekirdeği yüklü türler döner.
 * cari: tipler (yalnız aktif; kod+ad) · stok: kart_tipleri · birimler · ambalaj_tipleri (aktif; id+kod+ad) · gruplar (aktif; id+ad) · hks {var, urunler[id,ad]}.
 * Her tür: hazir (şema) + izin (oluşturma). Şema hazır değilse liste anahtarları boş gelir.
 * @param list<string> $turler
 * @return array<string,array>
 */
function hizli_kart_secenekler(PDO $pdo, array $turler = ['cari', 'stok']): array
{
    $o = [];
    foreach ($turler as $tur) {
        if (!isset(hizli_kart_turler()[$tur]) || !hizli_kart_izin($tur, 'read')) continue;
        $hazir = hizli_kart_sema_hazir($pdo, $tur);
        $s = ['hazir' => $hazir, 'izin' => hizli_kart_izin($tur, 'create')];
        if ($tur === 'cari') {
            $s['tipler'] = [];
            if ($hazir) foreach (cari_tipler($pdo) as $kod => $ad) $s['tipler'][] = ['kod' => (string)$kod, 'ad' => (string)$ad];
            $s['mustahsil'] = 'mustahsil';
        } else {
            $s += ['kart_tipleri' => [], 'birimler' => [], 'ambalaj_tipleri' => [], 'gruplar' => [], 'hks' => ['var' => false, 'urunler' => []]];
            if ($hazir) {
                foreach (stok_kart_tipleri() as $kod => $ad) $s['kart_tipleri'][] = ['kod' => (string)$kod, 'ad' => (string)$ad];
                foreach (stok_birimler() as $kod => $ad) $s['birimler'][] = ['kod' => (string)$kod, 'ad' => (string)$ad];
                foreach (stok_tanim_listesi($pdo, 'ambalaj_tip', true) as $t) $s['ambalaj_tipleri'][] = ['id' => (int)$t['id'], 'kod' => (string)($t['kod'] ?? ''), 'ad' => (string)$t['ad']];
                foreach (stok_tanim_listesi($pdo, 'grup', true) as $t) $s['gruplar'][] = ['id' => (int)$t['id'], 'ad' => (string)$t['ad']];
                $kat = stok_hks_urunler();
                if ($kat !== null) $s['hks'] = ['var' => true, 'urunler' => array_map(static fn(array $u): array => ['id' => (string)$u['id'], 'ad' => (string)$u['ad']], $kat)];
            }
        }
        $o[$tur] = $s;
    }
    return $o;
}

/** Girdiden skalar metin (dizi/nesne/bool → ''), kırpılmış, en çok $max karakter. */
function hizli_kart_metin(array $g, string $k, int $max = 300): string
{
    $v = $g[$k] ?? '';
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    return mb_substr(trim((string)$v), 0, $max);
}

/** İstemci istek anahtarı: boş = yok; aksi ^[A-Za-z0-9_-]{8,64}$ (false = geçersiz). */
function hizli_kart_istek_id(array $g): string|false
{
    $v = hizli_kart_metin($g, 'istek_id', 80);
    if ($v === '') return '';
    return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $v) === 1 ? $v : false;
}

/**
 * Yinelenen tıklama koruması: istek anahtarı → başarılı yanıt, OTURUMDA (kart tablolarında istek_anahtari kolonu yok; şema değişmez).
 * Son 20 kayıt, 30 dk. Aynı anahtar + aynı tür + aynı kullanıcı → saklanan yanıt (tekrar = true); yazma yapılmaz.
 */
function hizli_kart_istek_bul(string $anahtar, string $tur, int $uid): ?array
{
    if ($anahtar === '' || session_status() !== PHP_SESSION_ACTIVE) return null;
    $k = $_SESSION['hizli_kart_istek'][$anahtar] ?? null;
    if (!is_array($k) || ($k['tur'] ?? '') !== $tur || (int)($k['uid'] ?? 0) !== $uid || (int)($k['t'] ?? 0) < time() - 1800) return null;
    return is_array($k['yanit'] ?? null) ? $k['yanit'] + ['tekrar' => true] : null;
}

function hizli_kart_istek_sakla(string $anahtar, string $tur, int $uid, array $yanit): void
{
    if ($anahtar === '' || session_status() !== PHP_SESSION_ACTIVE) return;
    $l = is_array($_SESSION['hizli_kart_istek'] ?? null) ? $_SESSION['hizli_kart_istek'] : [];
    $l[$anahtar] = ['tur' => $tur, 'uid' => $uid, 't' => time(), 'yanit' => $yanit];
    uasort($l, static fn(array $a, array $b): int => (int)($b['t'] ?? 0) <=> (int)($a['t'] ?? 0));
    $_SESSION['hizli_kart_istek'] = array_slice($l, 0, 20, true);
}

/** Hata yanıtı (http: 422 doğrulama · 409 kayıt çakışması). */
function hizli_kart_hata(string $hata, string $kod, array $alanlar = [], int $http = 422): array
{
    $r = ['ok' => false, 'hata' => $hata, 'kod' => $kod, 'http' => $http];
    if ($alanlar) $r['alanlar'] = $alanlar;
    return $r;
}

/**
 * Hızlı ekleme — TEK yol. Çağıran: login + CSRF + tür beyaz listesi + izin (hizli_kart_izin create) + şema (hizli_kart_sema_hazir).
 * cari girdi: unvan*, tip*, telefon, vergi_no, tckn → cari_kart_hizli_ekle.
 * stok girdi: ad*, tip* (urun|malzeme|diger), ana_birim*, grup_id, ambalaj_tip_id, birim_dara_kg (ambalaj seçiliyse ZORUNLU, > 0; stok_dara_coz),
 *             hks_urun_id ya da hks_urun_ad (yalnız tip=urun; katalogdan doğrulanır) → stok_kart_hizli_ekle.
 * @return array başarı: {ok:true, tur, id, kod, ad, hizli:1, eksikler[etiket], eksik_alanlar[anahtar], …} · hata: {ok:false, hata, kod, alanlar?, http}
 */
function hizli_kart_ekle(PDO $pdo, string $tur, array $g, int $uid): array
{
    if ($tur === 'cari') {
        $girdi = ['unvan' => hizli_kart_metin($g, 'unvan', 250), 'tip' => hizli_kart_metin($g, 'tip', 30)];
        foreach (['telefon' => 60, 'vergi_no' => 30, 'tckn' => 30] as $a => $m) { $x = hizli_kart_metin($g, $a, $m); if ($x !== '') $girdi[$a] = $x; }
        $on = [];
        if ($girdi['unvan'] === '') $on['unvan'] = 'Zorunlu alan.';
        if ($girdi['tip'] === '') $on['tip'] = 'Cari tipini seçin.';
        if ($on) return hizli_kart_hata((string)reset($on), 'dogrulama', $on);
        $s = cari_kart_hizli_ekle($pdo, $girdi, $uid);
        if (empty($s['ok'])) {
            $al = is_array($s['alanlar'] ?? null) ? $s['alanlar'] : [];
            return hizli_kart_hata((string)($s['hata'] ?? 'Cari kart kaydedilemedi.'), $al ? 'dogrulama' : 'kayit', $al, $al ? 422 : 409);
        }
        $k = cari_kart_oku($pdo, (int)$s['id']) ?? [];
        return ['ok' => true, 'tur' => 'cari', 'id' => (int)$s['id'], 'kod' => (string)($s['kod'] ?? ($k['kod'] ?? '')), 'ad' => (string)($s['unvan'] ?? ($k['unvan'] ?? '')),
            'unvan' => (string)($s['unvan'] ?? ($k['unvan'] ?? '')), 'tip' => (string)($k['tip'] ?? $girdi['tip']), 'hizli' => (int)($k['hizli'] ?? 1),
            'eksikler' => array_values((array)($s['eksikler'] ?? [])), 'eksik_alanlar' => array_values((array)($s['eksik_alanlar'] ?? []))];
    }

    // ── stok ──
    $tip = hizli_kart_metin($g, 'tip', 20);
    $girdi = ['ad' => hizli_kart_metin($g, 'ad', 250), 'tip' => $tip, 'ana_birim' => hizli_kart_metin($g, 'ana_birim', 20),
        'grup_id' => hizli_kart_metin($g, 'grup_id', 12), 'ambalaj_tip_id' => hizli_kart_metin($g, 'ambalaj_tip_id', 12), 'birim_dara_kg' => hizli_kart_metin($g, 'birim_dara_kg', 20)];
    if ($tip === 'urun') {
        $girdi['hks_urun_id'] = hizli_kart_metin($g, 'hks_urun_id', 40);
        $girdi['hks_urun_ad'] = $girdi['hks_urun_id'] === '' ? hizli_kart_metin($g, 'hks_urun_ad', 160) : '';
    }
    $on = [];
    if ($girdi['ad'] === '') $on['ad'] = 'Zorunlu alan.';
    if ($tip === '') $on['tip'] = 'Kart tipini seçin.';
    if ($girdi['ana_birim'] === '') $on['ana_birim'] = 'Ana birimi seçin.';
    // Birim dara: sunucu stok_dara_coz kuralıyla doğrular; ambalaj tipi seçiliyse ZORUNLU ve > 0 (hızlı eklemede de — sahip kararı, Faz 2)
    if ($girdi['birim_dara_kg'] !== '' && stok_dara_coz($girdi['birim_dara_kg']) === null) {
        $on['birim_dara_kg'] = 'Birim dara 0–1.000 kg arası, en çok 3 ondalık olmalı (ör. 1,8).';
    } elseif ($girdi['ambalaj_tip_id'] !== '' && $girdi['ambalaj_tip_id'] !== '0' && (int)stok_dara_coz($girdi['birim_dara_kg']) <= 0) {
        $on['birim_dara_kg'] = 'Ambalaj tipi seçiliyse birim dara zorunlu (0 kg\'dan büyük).';
    }
    if ($on) return hizli_kart_hata((string)reset($on), 'dogrulama', $on);
    $s = stok_kart_hizli_ekle($pdo, $girdi, $uid);
    if (empty($s['ok'])) {
        $al = [];
        foreach ((array)($s['alanlar'] ?? []) as $a => $m) $al[in_array($a, ['hks_urun_ara', 'hks_urun_id'], true) ? 'hks_urun' : (string)$a] = (string)$m;
        return hizli_kart_hata((string)($s['hata'] ?? 'Stok kartı kaydedilemedi.'), $al ? 'dogrulama' : 'kayit', $al, $al ? 422 : 409);
    }
    $k = stok_kart_oku($pdo, (int)$s['id']) ?? [];
    $etk = stok_kart_eksik_etiketleri();
    $eksik = array_values((array)($s['eksik_alanlar'] ?? []));
    $daraG = stok_milli(is_scalar($k['birim_dara_kg'] ?? null) ? $k['birim_dara_kg'] : null);
    return ['ok' => true, 'tur' => 'stok', 'id' => (int)$s['id'], 'kod' => (string)($s['stok_kod'] ?? ($k['kod'] ?? '')), 'ad' => (string)($k['ad'] ?? $girdi['ad']),
        'tip' => (string)($k['tip'] ?? $tip), 'ana_birim' => (string)($k['ana_birim'] ?? $girdi['ana_birim']),
        'ambalaj_tip_id' => isset($k['ambalaj_tip_id']) ? (int)$k['ambalaj_tip_id'] : null, 'birim_dara_kg' => $daraG > 0 ? stok_dara_bicim($daraG) : null,
        'hks_urun_id' => ($k['hks_urun_id'] ?? null) !== null ? (string)$k['hks_urun_id'] : null, 'hks_urun_ad' => ($k['hks_urun_ad'] ?? null) !== null ? (string)$k['hks_urun_ad'] : null,
        'hizli' => (int)($k['hizli'] ?? 1), 'eksikler' => array_map(static fn(string $a): string => $etk[$a] ?? $a, $eksik), 'eksik_alanlar' => $eksik];
}
