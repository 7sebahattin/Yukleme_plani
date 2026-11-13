<?php
// =========================================================
// config/stok_core.php — Stok (muhasebe) modülü çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 5 tablo: stok_kartlar · stok_tanimlar (grup / giriş nedeni / çıkış nedeni) · stok_fisler · stok_fis_satirlar ·
//          stok_hareketler (TEK defter; INSERT-only — UPDATE/DELETE YOK).
// Bu defter Ürün Stoğu (stok.php: kantar − yükleme türetimi) ve Malzeme Stok (material_stock_movements) ekranlarından
// BAĞIMSIZDIR; onları okumaz, onlar da bunu okumaz (kantar için tek istisna: Parça B'de salt okunur ön-doldurma).
// Şema YALNIZ migrate.php "Stok (muhasebe)" kartından kurulur (stok_migrate; ALTER ve FK YOK, GO kuralı).
// Miktar PHP'de "milli" TAM SAYI (ana birim × 1000), DB'de DECIMAL(14,3). Para PHP'de kuruş TAM SAYI, DB'de DECIMAL(15,2).
// Maliyet: hareketli ağırlıklı ortalama, ÜRÜN bazında (depolar ortak), TRY, KDV hariç (stok_maliyet_uygula SAF motor).
//   Motor içinde ort = kuruş / ana birim (float); DB'de ort_sonra = TL / ana birim, q_sonra = ana birim, v_sonra = TL.
// Yazan fonksiyonlar yetki KONTROL ETMEZ (uçların işi: stok_kaydet.php → stok_kapi); hepsi audit_log_event yazar.
// Depo filtresi yalnız depo_sql_in (stok_depo_filtre) üzerinden; PDO'da yalnız pozisyonel `?`.
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/cari_core.php';                // cari_tx / cari_hata / cari_kurus / cari_tutar_* / cari_fold (yan etkisiz)
require_once __DIR__ . '/material_stock_helpers.php';   // yalnız ms_summary_types (içe aktar önizlemesi)

const STOK_MIKTAR_MAX_MILLI = 999999999999;   // 999.999.999,999 ana birim

// ── Şema / kapı ─────────────────────────────────────────────────────────────

/** Beş tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım. @return array<string,string> */
function stok_tablolar(): array
{
    return [
        'stok_kartlar' => "CREATE TABLE IF NOT EXISTS `stok_kartlar` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `kod`            VARCHAR(30)    NOT NULL,
  `ad`             VARCHAR(200)   NOT NULL,
  `tip`            VARCHAR(20)    NOT NULL DEFAULT 'urun',
  `grup_id`        INT            NULL DEFAULT NULL,
  `ana_birim`      VARCHAR(10)    NOT NULL DEFAULT 'kg',
  `cevrim_birim`   VARCHAR(10)    NULL DEFAULT NULL,
  `cevrim_katsayi` DECIMAL(12,3)  NULL DEFAULT NULL,
  `kdv_orani`      TINYINT        NOT NULL DEFAULT 1,
  `barkod`         VARCHAR(60)    NULL DEFAULT NULL,
  `kritik_seviye`  DECIMAL(14,3)  NULL DEFAULT NULL,
  `notlar`         TEXT           NULL,
  `aktif`          TINYINT(1)     NOT NULL DEFAULT 1,
  `md_id`          INT            NULL DEFAULT NULL,
  `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`     INT            NULL DEFAULT NULL,
  `updated_at`     DATETIME       NULL DEFAULT NULL,
  `updated_by`     INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_stk_kod` (`kod`),
  KEY `idx_stk_ad` (`ad`),
  KEY `idx_stk_tip_aktif` (`tip`, `aktif`),
  KEY `idx_stk_grup` (`grup_id`),
  KEY `idx_stk_md` (`md_id`),
  KEY `idx_stk_barkod` (`barkod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'stok_tanimlar' => "CREATE TABLE IF NOT EXISTS `stok_tanimlar` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `tur`        VARCHAR(20)   NOT NULL,
  `ad`         VARCHAR(100)  NOT NULL,
  `kod`        VARCHAR(20)   NULL DEFAULT NULL,
  `sira`       INT           NOT NULL DEFAULT 0,
  `aktif`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_stkt_tur_ad` (`tur`, `ad`),
  KEY `idx_stkt_tur` (`tur`, `aktif`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'stok_fisler' => "CREATE TABLE IF NOT EXISTS `stok_fisler` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `tur`             VARCHAR(10)    NOT NULL,
  `fis_no`          VARCHAR(20)    NOT NULL DEFAULT '',
  `tarih`           DATE           NOT NULL,
  `depo`            VARCHAR(150)   NOT NULL,
  `hedef_depo`      VARCHAR(150)   NULL DEFAULT NULL,
  `neden_id`        INT            NULL DEFAULT NULL,
  `neden_ad`        VARCHAR(100)   NULL DEFAULT NULL,
  `cari_id`         INT            NULL DEFAULT NULL,
  `cari_hareket_id` INT            NULL DEFAULT NULL,
  `para_birimi`     VARCHAR(3)     NOT NULL DEFAULT 'TRY',
  `kur`             DECIMAL(15,6)  NULL DEFAULT NULL,
  `toplam_miktar`   DECIMAL(16,3)  NOT NULL DEFAULT 0,
  `toplam_tutar`    DECIMAL(15,2)  NULL DEFAULT NULL,
  `toplam_kdv`      DECIMAL(15,2)  NULL DEFAULT NULL,
  `evrak_no`        VARCHAR(60)    NULL DEFAULT NULL,
  `aciklama`        VARCHAR(500)   NULL DEFAULT NULL,
  `uyari`           VARCHAR(255)   NULL DEFAULT NULL,
  `kaynak_modul`    VARCHAR(30)    NULL DEFAULT NULL,
  `kaynak_id`       INT            NULL DEFAULT NULL,
  `istek_anahtari`  VARCHAR(40)    NULL DEFAULT NULL,
  `durum`           VARCHAR(10)    NOT NULL DEFAULT 'gecerli',
  `iptal_at`        DATETIME       NULL DEFAULT NULL,
  `iptal_by`        INT            NULL DEFAULT NULL,
  `iptal_neden`     VARCHAR(500)   NULL DEFAULT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`      INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_stkf_istek` (`istek_anahtari`),
  KEY `idx_stkf_tur_tarih` (`tur`, `tarih`),
  KEY `idx_stkf_depo` (`depo`),
  KEY `idx_stkf_hedef` (`hedef_depo`),
  KEY `idx_stkf_cari` (`cari_id`),
  KEY `idx_stkf_kaynak` (`kaynak_modul`, `kaynak_id`),
  KEY `idx_stkf_durum` (`durum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'stok_fis_satirlar' => "CREATE TABLE IF NOT EXISTS `stok_fis_satirlar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `fis_id`      INT            NOT NULL,
  `sira`        INT            NOT NULL DEFAULT 0,
  `kart_id`     INT            NOT NULL,
  `yon`         CHAR(1)        NOT NULL,
  `miktar`      DECIMAL(14,3)  NOT NULL,
  `birim_fiyat` DECIMAL(15,4)  NULL DEFAULT NULL,
  `kdv_orani`   TINYINT        NULL DEFAULT NULL,
  `tutar`       DECIMAL(15,2)  NULL DEFAULT NULL,
  `kdv_tutar`   DECIMAL(15,2)  NULL DEFAULT NULL,
  `lot`         VARCHAR(60)    NULL DEFAULT NULL,
  `aciklama`    VARCHAR(200)   NULL DEFAULT NULL,
  KEY `idx_stks_fis` (`fis_id`),
  KEY `idx_stks_kart` (`kart_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'stok_hareketler' => "CREATE TABLE IF NOT EXISTS `stok_hareketler` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `fis_id`     INT            NOT NULL,
  `satir_id`   INT            NULL DEFAULT NULL,
  `kart_id`    INT            NOT NULL,
  `tarih`      DATE           NOT NULL,
  `depo`       VARCHAR(150)   NOT NULL DEFAULT '',
  `miktar`     DECIMAL(14,3)  NOT NULL,
  `deger`      DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `q_sonra`    DECIMAL(16,3)  NOT NULL DEFAULT 0,
  `v_sonra`    DECIMAL(17,2)  NOT NULL DEFAULT 0,
  `ort_sonra`  DECIMAL(18,6)  NOT NULL DEFAULT 0,
  `ters_id`    INT            NULL DEFAULT NULL,
  `created_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT            NULL DEFAULT NULL,
  KEY `idx_stkh_kart` (`kart_id`, `id`),
  KEY `idx_stkh_kart_depo` (`kart_id`, `depo`),
  KEY `idx_stkh_fis` (`fis_id`),
  KEY `idx_stkh_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Beş tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function stok_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(stok_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tohum: stok_tanimlar YENİ kurulduysa (boşsa) bir kez. kod dolu = sistem nedeni (silinemez, adı kilitli). @return list<array{0:string,1:string,2:?string}> */
function stok_tanim_tohumu(): array
{
    return [
        ['grup', 'Genel', null],
        ['giris_neden', 'Alış', 'alis'], ['giris_neden', 'Açılış/Devir', 'acilis'], ['giris_neden', 'Sayım Fazlası', 'sayim_fazla'], ['giris_neden', 'Diğer Giriş', 'diger'],
        ['cikis_neden', 'Satış/Sevk', 'satis'], ['cikis_neden', 'Sarf', 'sarf'], ['cikis_neden', 'Fire', 'fire'], ['cikis_neden', 'Sayım Eksiği', 'sayim_eksik'],
        ['cikis_neden', 'Numune', 'numune'], ['cikis_neden', 'Diğer Çıkış', 'diger'],
    ];
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + tanım tohumu (stok_tanimlar YENİ kurulduysa).
 * Yalnız migrate.php kartından çağrılır. TEST KANCASI: $GLOBALS['_stok_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function stok_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_stok_ddl_calistir'] ?? null;   // YALNIZ TEST KANCASI
    foreach (stok_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'stok_tanimlar' && (int)$pdo->query('SELECT COUNT(*) FROM `stok_tanimlar`')->fetchColumn() === 0) {
                $ins = $pdo->prepare('INSERT INTO `stok_tanimlar` (tur, ad, kod, sira, aktif, created_at) VALUES (?, ?, ?, ?, 1, ?)');
                $sira = [];
                foreach (stok_tanim_tohumu() as [$tur, $tad, $kod]) {
                    $sira[$tur] = ($sira[$tur] ?? 0) + 10;
                    $ins->execute([$tur, $tad, $kod, $sira[$tur], date('Y-m-d H:i:s')]);
                }
            }
        } catch (PDOException $e) {
            error_log('[stok_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** JSON (fetch) isteği mi? (cari ile aynı sözleşme) */
function stok_json_mi(): bool { return cari_json_mi(); }

/** Tablolar yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function stok_sayfa_kapisi(PDO $pdo): void
{
    if (stok_hazir($pdo)) return;
    if (stok_json_mi()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Stok tabloları kurulmamış. Yönetici: Şema Migrasyon → Stok (muhasebe) kartı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render_header('Stok');
    if (function_exists('stok_assets')) stok_assets();
    if (function_exists('stok_sema_uyari')) {
        echo stok_sema_uyari();
    } else {
        echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
            . '<h2 style="margin-top:0;">Stok</h2>'
            . '<p>Stok tabloları kurulmamış. Yönetici: Şema Migrasyon → Stok (muhasebe) kartı.</p>'
            . '<p><a class="btn btn-primary" href="migrate.php#stok">Şema Migrasyon › Stok</a></p>'
            . '</div></div>';
    }
    render_footer();
    exit;
}

/** Faz 1: hepsi is_admin(). TEK değişim noktası. $eylem: read|write|delete|admin */
function stok_can(string $eylem): bool
{
    return in_array($eylem, ['read', 'write', 'delete', 'admin'], true) && is_admin();
}

function stok_kapi(string $eylem): void
{
    if (stok_can($eylem)) return;
    if (stok_json_mi()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forbidden('Bu işlem için yetkiniz yok.');
}

function stok_no_store(): void { cari_no_store(); }

// ── Sabitler ────────────────────────────────────────────────────────────────

/** @return array<string,string> birim kodu → etiket */
function stok_birimler(): array
{
    return ['kg' => 'kg', 'adet' => 'adet', 'kasa' => 'kasa', 'palet' => 'palet', 'paket' => 'paket', 'koli' => 'koli', 'lt' => 'lt', 'm' => 'm'];
}

/** Kesir kabul etmeyen (tam sayı) birim mi? lt ve m dışındakiler tam sayıdır (kg dahil: ekranda tam sayı kuralı). */
function stok_birim_tam_mi(string $birim): bool { return !in_array($birim, ['lt', 'm'], true); }

/** @return array<string,string> */
function stok_kart_tipleri(): array { return ['urun' => 'Ürün', 'malzeme' => 'Malzeme', 'diger' => 'Diğer']; }

/** @return array<string,string> */
function stok_tanim_turleri(): array { return ['grup' => 'Stok Grubu', 'giris_neden' => 'Giriş Nedeni', 'cikis_neden' => 'Çıkış Nedeni']; }

/** @return list<int> */
function stok_kdv_oranlari(): array { return [0, 1, 10, 20]; }

/** @return array<string,array{etiket:string,onek:string,sayfa:string}> */
function stok_fis_turleri(): array
{
    return [
        'giris'    => ['etiket' => 'Giriş Fişi',    'onek' => 'GRS', 'sayfa' => 'stok_giris_fisleri.php'],
        'cikis'    => ['etiket' => 'Çıkış Fişi',    'onek' => 'CKS', 'sayfa' => 'stok_cikis_fisleri.php'],
        'transfer' => ['etiket' => 'Transfer Fişi', 'onek' => 'TRF', 'sayfa' => 'transfer_fisleri.php'],
        'donusum'  => ['etiket' => 'Dönüşüm Fişi',  'onek' => 'DNS', 'sayfa' => 'stok_donusum_fisleri.php'],
    ];
}

/** GRS-2026-000123 */
function stok_fis_no(string $tur, string $tarih, int $id): string
{
    $onek = stok_fis_turleri()[$tur]['onek'] ?? 'STK';
    return $onek . '-' . substr($tarih, 0, 4) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

// ── Biçim / ayrıştırma ──────────────────────────────────────────────────────

/** DECIMAL/string/float (ana birim) → milli tam sayı. */
function stok_milli(string|int|float|null $v): int
{
    if ($v === null || $v === '') return 0;
    return (int)round((float)$v * 1000);
}

/** milli → DB DECIMAL metni ("1250.500"). */
function stok_milli_db(int $milli): string
{
    $m = abs($milli);
    return ($milli < 0 ? '-' : '') . intdiv($m, 1000) . '.' . str_pad((string)($m % 1000), 3, '0', STR_PAD_LEFT);
}

/**
 * Kullanıcı miktarı → milli (ana birim × 1000). TR yazımları: "1.250" (binlik) · "1250,5" · "1.250,5" · "12.5" (ondalık nokta).
 * Tam sayı biriminde kesir → null (sessiz yuvarlama YOK). ≤0, en çok 3 ondalık aşımı, üst sınır → null.
 */
function stok_miktar_coz(mixed $ham, string $birim): ?int
{
    if (is_array($ham) || is_object($ham) || is_bool($ham)) return null;
    if (is_int($ham)) $s = (string)$ham;
    elseif (is_float($ham)) $s = rtrim(rtrim(number_format($ham, 3, '.', ''), '0'), '.');
    else $s = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$ham)) ?? '';
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);   // "1.250" / "12.500.000" = binlik
    if (!preg_match('/^(\d{1,12})(?:\.(\d{1,3}))?$/', $s, $m)) return null;
    $kesir = (int)str_pad($m[2] ?? '', 3, '0');
    $milli = (int)$m[1] * 1000 + $kesir;
    if ($milli <= 0 || $milli > STOK_MIKTAR_MAX_MILLI) return null;
    if ($kesir !== 0 && stok_birim_tam_mi($birim)) return null;
    return $milli;
}

/** milli → "18.450" (tam birim) / "1.250,5" (lt, m; en çok 3 ondalık, sondaki sıfırlar atılır). Eksi işaret başta. */
function stok_miktar_bicim(int $milli, string $birim = 'kg'): string
{
    $isaret = $milli < 0 ? '-' : '';
    $m = abs($milli);
    if (stok_birim_tam_mi($birim)) return $isaret . number_format((float)intdiv($m + 500, 1000), 0, ',', '.');
    $tam = number_format((float)intdiv($m, 1000), 0, ',', '.');
    $k = rtrim(str_pad((string)($m % 1000), 3, '0', STR_PAD_LEFT), '0');
    return $isaret . $tam . ($k !== '' ? ',' . $k : '');
}

/** Ondalık katsayı/eşik metni → "8.000" (DB DECIMAL(12,3) metni) ya da null (geçersiz). */
function stok_katsayi_coz(mixed $ham): ?string
{
    $m = stok_miktar_coz($ham, 'lt');   // kesirli birim kuralıyla
    return $m === null ? null : stok_milli_db($m);
}

/** `geri` yalnız yerel stok_*.php / transfer_fisleri.php adresi (açık yönlendirme yok). */
function stok_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^(stok_[a-z_]+|transfer_fisleri)\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'stok_kartlari.php';
}

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function stok_tx(PDO $pdo, callable $fn): mixed { return cari_tx($pdo, $fn); }

function stok_hata(string $msg, array $ek = []): array { return cari_hata($msg, $ek); }

/** Sonucu JSON ya da PRG olarak gönderir. 409: yetersiz_stok · kantar_aktarilmis · hareketli · kullanimda; diğer hata 422. */
function stok_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && in_array((string)($s['kod'] ?? ''), ['yetersiz_stok', 'kantar_aktarilmis', 'hareketli', 'kullanimda'], true)) http_response_code(409);
        elseif (!$ok) http_response_code(422);
        echo json_encode([
            'ok' => $ok, 'id' => $s['id'] ?? null, 'fis_no' => $s['fis_no'] ?? null, 'hata' => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'),
            'alanlar' => (object)($s['alanlar'] ?? []), 'uyari' => $s['uyari'] ?? null, 'yonlendir' => $geri, 'tekrar' => !empty($s['tekrar']),
            'kod' => $s['kod'] ?? null, 'eksikler' => $s['eksikler'] ?? null, 'eklenen' => $s['eklenen'] ?? null, 'atlanan' => $s['atlanan'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok ? $okMesaj . (!empty($s['fis_no']) ? ' · ' . $s['fis_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '') : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

// ── Kart ────────────────────────────────────────────────────────────────────

const STOK_KART_ALANLAR = ['kod', 'ad', 'tip', 'grup_id', 'ana_birim', 'cevrim_birim', 'cevrim_katsayi', 'kdv_orani', 'barkod', 'kritik_seviye', 'notlar', 'aktif'];

/** STK-0001 …: en büyük STK-\d+ + 1. */
function stok_kod_oner(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT kod FROM stok_kartlar WHERE kod LIKE 'STK-%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        if (preg_match('/^STK-(\d+)$/', (string)$k, $m)) $max = max($max, (int)$m[1]);
    }
    return 'STK-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function stok_kod_normalize(string $k): string { return mb_strtoupper(trim(strtr($k, ['i' => 'İ'])), 'UTF-8'); }

/** @return array{ok:bool,veri?:array,hatalar?:array<string,string>} */
function stok_kart_dogrula(PDO $pdo, array $g, ?int $id): array
{
    $h = []; $v = [];
    $ad = trim((string)($g['ad'] ?? ''));
    if (mb_strlen($ad) < 2) $h['ad'] = 'Ad en az 2 karakter olmalı.';
    elseif (mb_strlen($ad) > 200) $h['ad'] = 'Ad en çok 200 karakter.';
    else $v['ad'] = $ad;

    $kod = stok_kod_normalize((string)($g['kod'] ?? ''));
    if ($kod === '') $kod = stok_kod_oner($pdo);
    elseif (!preg_match('/^[A-Z0-9İĞÜŞÖÇ._-]{1,30}$/u', $kod)) $h['kod'] = 'Kod 1-30 karakter; yalnız harf, rakam, . _ -';
    if (!isset($h['kod'])) {
        $cak = false;
        foreach ($pdo->query('SELECT id, kod FROM stok_kartlar')->fetchAll(PDO::FETCH_ASSOC) as $r) {   // TR-duyarsız tekillik
            if (($id === null || (int)$r['id'] !== $id) && cari_fold((string)$r['kod']) === cari_fold($kod)) { $cak = true; break; }
        }
        if ($cak) $h['kod'] = 'Bu kod başka bir stok kartında kullanılıyor.'; else $v['kod'] = $kod;
    }

    $tip = (string)($g['tip'] ?? 'urun');
    if (!isset(stok_kart_tipleri()[$tip])) $h['tip'] = 'Geçersiz kart tipi.'; else $v['tip'] = $tip;

    $grup = $g['grup_id'] ?? null;
    if ($grup === null || $grup === '' || $grup === 0 || $grup === '0') {
        $v['grup_id'] = null;
    } else {
        $st = $pdo->prepare("SELECT 1 FROM stok_tanimlar WHERE id = ? AND tur = 'grup'");
        $st->execute([(int)$grup]);
        if ($st->fetchColumn() === false) $h['grup_id'] = 'Geçersiz stok grubu.'; else $v['grup_id'] = (int)$grup;
    }

    $birim = (string)($g['ana_birim'] ?? 'kg');
    if (!isset(stok_birimler()[$birim])) $h['ana_birim'] = 'Geçersiz ana birim.'; else $v['ana_birim'] = $birim;

    $cb = trim((string)($g['cevrim_birim'] ?? ''));
    $ck = trim((string)($g['cevrim_katsayi'] ?? ''));
    if ($cb === '') {
        $v['cevrim_birim'] = null; $v['cevrim_katsayi'] = null;
        if ($ck !== '') $h['cevrim_birim'] = 'Katsayı için çevrim birimini seçin.';
    } elseif (!isset(stok_birimler()[$cb])) {
        $h['cevrim_birim'] = 'Geçersiz çevrim birimi.';
    } elseif ($cb === $birim) {
        $h['cevrim_birim'] = 'Çevrim birimi ana birimden farklı olmalı.';
    } else {
        $kat = stok_katsayi_coz($ck);
        if ($kat === null) $h['cevrim_katsayi'] = 'Geçerli bir katsayı girin (> 0, en çok 3 ondalık).';
        else { $v['cevrim_birim'] = $cb; $v['cevrim_katsayi'] = $kat; }
    }

    $kdv = $g['kdv_orani'] ?? 1;
    if (!preg_match('/^\d{1,2}$/', trim((string)$kdv)) || !in_array((int)$kdv, stok_kdv_oranlari(), true)) $h['kdv_orani'] = 'KDV oranı 0, 1, 10 ya da 20 olmalı.';
    else $v['kdv_orani'] = (int)$kdv;

    $bk = preg_replace('/\s+/', '', (string)($g['barkod'] ?? '')) ?? '';
    if ($bk === '') $v['barkod'] = null;
    elseif (!preg_match('/^[0-9A-Za-z._-]{1,60}$/', $bk)) $h['barkod'] = 'Barkod 1-60 karakter; harf, rakam, . _ -';
    else $v['barkod'] = $bk;

    $ks = trim((string)($g['kritik_seviye'] ?? ''));
    if ($ks === '') $v['kritik_seviye'] = null;
    else {
        $km = stok_miktar_coz($ks, $v['ana_birim'] ?? $birim);
        if ($km === null) $h['kritik_seviye'] = 'Geçerli bir kritik seviye girin (> 0' . (stok_birim_tam_mi($birim) ? ', tam sayı' : '') . ').';
        else $v['kritik_seviye'] = stok_milli_db($km);
    }

    $not = trim((string)($g['notlar'] ?? ''));
    if (mb_strlen($not) > 5000) $h['notlar'] = 'En çok 5000 karakter.'; else $v['notlar'] = $not === '' ? null : $not;

    if (array_key_exists('aktif', $g)) $v['aktif'] = !empty($g['aktif']) && (string)$g['aktif'] !== '0' ? 1 : 0;

    return $h ? ['ok' => false, 'hatalar' => $h] : ['ok' => true, 'veri' => $v];
}

function stok_kart_oku(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT k.*, t.ad AS grup_adi FROM stok_kartlar k LEFT JOIN stok_tanimlar t ON t.id = k.grup_id WHERE k.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Kartın fiş satırı + defter satırı sayısı (iptaller dahil) — Sil ve ana birim kilidi için. */
function stok_kart_hareket_say(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM stok_hareketler WHERE kart_id = ?) + (SELECT COUNT(*) FROM stok_fis_satirlar WHERE kart_id = ?)');
    $st->execute([$id, $id]);
    return (int)$st->fetchColumn();
}

/** @return array{ok:bool,hata:?string,id:?int,alanlar?:array} */
function stok_kart_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array
{
    $eski = null;
    if ($id !== null) {
        $eski = stok_kart_oku($pdo, $id);
        if ($eski === null) return stok_hata('Stok kartı bulunamadı.');
    }
    $d = stok_kart_dogrula($pdo, $g, $id);
    if (!$d['ok']) return stok_hata((string)reset($d['hatalar']), ['alanlar' => $d['hatalar']]);
    $v = $d['veri'];
    if ($eski !== null && (string)$eski['ana_birim'] !== (string)$v['ana_birim'] && stok_kart_hareket_say($pdo, $id) > 0) {
        return stok_hata('Hareketi olan kartın ana birimi değiştirilemez.', ['alanlar' => ['ana_birim' => 'Hareketli kartta ana birim kilitli.']]);
    }
    $simdi = date('Y-m-d H:i:s');

    if ($id === null) {
        $v += ['aktif' => 1];
        $kol = array_keys($v);
        $sql = 'INSERT INTO stok_kartlar (' . implode(',', $kol) . ', created_at, created_by) VALUES (' . implode(',', array_fill(0, count($kol), '?')) . ', ?, ?)';
        try {
            $pdo->prepare($sql)->execute([...array_values($v), $simdi, $uid]);
        } catch (PDOException $e) {
            return stok_hata('Stok kartı kaydedilemedi (kod çakışması olabilir).');
        }
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'stok', $yid, null, ['kart' => $v]);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }

    $fark = []; $eskiFark = [];
    foreach ($v as $k => $nv) {
        $ev = $eski[$k] ?? null;
        $esit = ($ev === null && $nv === null) || ($ev !== null && $nv !== null && (in_array($k, ['cevrim_katsayi', 'kritik_seviye'], true) ? abs((float)$ev - (float)$nv) < 0.0005 : (string)$ev === (string)$nv));
        if (!$esit) { $fark[$k] = $nv; $eskiFark[$k] = $ev; }
    }
    if ($fark) {
        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($fark)));
        try {
            $pdo->prepare("UPDATE stok_kartlar SET $set, updated_at = ?, updated_by = ? WHERE id = ?")->execute([...array_values($fark), $simdi, $uid, $id]);
        } catch (PDOException $e) {
            return stok_hata('Stok kartı güncellenemedi (kod çakışması olabilir).');
        }
        audit_log_event('update', 'stok', $id, ['kart' => $eskiFark], ['kart' => $fark]);
    }
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Pasife alırken bakiye sıfırdan farklıysa yine izin verir, `uyari` döner. */
function stok_kart_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $k = stok_kart_oku($pdo, $id);
    if ($k === null) return stok_hata('Stok kartı bulunamadı.');
    $pdo->prepare('UPDATE stok_kartlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    $uyari = null;
    if (!$aktif) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(miktar), 0) FROM stok_hareketler WHERE kart_id = ?');
        $st->execute([$id]);
        if (stok_milli($st->fetchColumn()) !== 0) $uyari = 'Kartın stok bakiyesi sıfır değil; pasif kart yeni fişte seçilemez.';
    }
    audit_log_event($aktif ? 'activate' : 'deactivate', 'stok', $id, ['aktif' => (int)$k['aktif']], ['aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id, 'uyari' => $uyari];
}

/** Fiziksel silme YALNIZ hiç fiş satırı / defter satırı (iptaller dahil) yoksa. */
function stok_kart_sil(PDO $pdo, int $id, int $uid): array
{
    $k = stok_kart_oku($pdo, $id);
    if ($k === null) return stok_hata('Stok kartı bulunamadı.');
    if (stok_kart_hareket_say($pdo, $id) > 0) return stok_hata('Hareketi olan stok kartı silinemez; pasifleştirin.', ['kod' => 'hareketli']);
    $pdo->prepare('DELETE FROM stok_kartlar WHERE id = ?')->execute([$id]);
    unset($k['grup_adi']);
    audit_log_event('delete', 'stok', $id, ['kart' => $k], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

// ── Okuyucular (bakiye / maliyet / hareket) ─────────────────────────────────

/** Aktif depo filtresi (yalnız depo_sql_in). @return array{0:string,1:list<mixed>}  kısıtsızsa ['1=1', []] */
function stok_depo_filtre(string $col): array
{
    [$w, $p] = depo_sql_in($col);
    return $w === '' ? ['1=1', []] : [$w, $p];
}

/**
 * Filtre: ara (kod/ad/barkod), grup_id, tip, aktif (1/0), kritik=1 (kritik seviye tanımlı ve bakiye ≤ seviye). En çok 500 satır.
 * Her satırda bakiye_milli (aktif depo filtreli), kritik_milli (?int), kritik (bool).
 * @return array{satirlar:list<array>,kesildi:bool}
 */
function stok_kart_listesi(PDO $pdo, array $f = []): array
{
    $w = ['1=1']; $p = [];
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $w[] = '(k.kod LIKE ? OR k.ad LIKE ? OR k.barkod LIKE ?)';
        $l = '%' . $q . '%';
        array_push($p, $l, $l, $l);
    }
    if (!empty($f['grup_id'])) { $w[] = 'k.grup_id = ?'; $p[] = (int)$f['grup_id']; }
    if (!empty($f['tip'])) { $w[] = 'k.tip = ?'; $p[] = (string)$f['tip']; }
    if (isset($f['aktif']) && $f['aktif'] !== '' && $f['aktif'] !== null) { $w[] = 'k.aktif = ?'; $p[] = (int)$f['aktif']; }
    $st = $pdo->prepare('SELECT k.*, t.ad AS grup_adi FROM stok_kartlar k LEFT JOIN stok_tanimlar t ON t.id = k.grup_id WHERE ' . implode(' AND ', $w) . ' ORDER BY k.ad, k.id');
    $st->execute($p);
    $kartlar = $st->fetchAll(PDO::FETCH_ASSOC);
    $bak = stok_bakiyeler_toplu($pdo, array_map(static fn(array $k): int => (int)$k['id'], $kartlar));
    $out = [];
    foreach ($kartlar as $k) {
        $b = $bak[(int)$k['id']] ?? 0;
        $km = $k['kritik_seviye'] !== null && $k['kritik_seviye'] !== '' ? stok_milli($k['kritik_seviye']) : null;
        $k['bakiye_milli'] = $b;
        $k['kritik_milli'] = $km;
        $k['kritik'] = $km !== null && $b <= $km;
        if (!empty($f['kritik']) && !$k['kritik']) continue;
        $out[] = $k;
    }
    $kesildi = count($out) > 500;
    if ($kesildi) $out = array_slice($out, 0, 500);
    return ['satirlar' => $out, 'kesildi' => $kesildi];
}

/** @param list<int> $ids @return array<int,int> kart_id → bakiye milli (aktif depo filtreli) */
function stok_bakiyeler_toplu(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    [$dw, $dp] = stok_depo_filtre('depo');
    $out = [];
    foreach (array_chunk($ids, 500) as $parca) {
        $st = $pdo->prepare('SELECT kart_id, SUM(miktar) AS m FROM stok_hareketler WHERE kart_id IN (' . implode(',', array_fill(0, count($parca), '?')) . ") AND $dw GROUP BY kart_id");
        $st->execute([...$parca, ...$dp]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['kart_id']] = stok_milli($r['m']);
    }
    return $out;
}

/** Kartın son defter satırındaki maliyet durumu (depo FİLTRESİZ; ürün bazında ortak). @return array{q:int,v:int,ort:float} q milli, v kuruş, ort kuruş/birim */
function stok_maliyet_durumu(PDO $pdo, int $kartId): array
{
    $st = $pdo->prepare('SELECT q_sonra, v_sonra, ort_sonra FROM stok_hareketler WHERE kart_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$kartId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) return ['q' => 0, 'v' => 0, 'ort' => 0.0];
    return ['q' => stok_milli($r['q_sonra']), 'v' => cari_kurus($r['v_sonra']), 'ort' => round((float)$r['ort_sonra'] * 100, 4)];
}

/** @return array{toplam_milli:int,depolar:list<array{depo:string,milli:int}>,ort:float,deger_kurus:int}  depolar aktif depo filtreli; ort kuruş/birim */
function stok_bakiye(PDO $pdo, int $kartId): array
{
    [$dw, $dp] = stok_depo_filtre('depo');
    $st = $pdo->prepare("SELECT depo, SUM(miktar) AS m FROM stok_hareketler WHERE kart_id = ? AND $dw GROUP BY depo ORDER BY depo");
    $st->execute([$kartId, ...$dp]);
    $depolar = []; $top = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $m = stok_milli($r['m']);
        $depolar[] = ['depo' => (string)$r['depo'], 'milli' => $m];
        $top += $m;
    }
    $md = stok_maliyet_durumu($pdo, $kartId);
    return ['toplam_milli' => $top, 'depolar' => $depolar, 'ort' => $md['ort'], 'deger_kurus' => (int)round($top / 1000 * $md['ort'])];
}

/**
 * Kartın son $limit defter satırı (EN YENİ ÖNCE), aktif depo filtreli. Yürüyen bakiye PHP'de: pencere öncesi toplam + sıralı birikim.
 * f: depo (tek depo süzgeci, isteğe bağlı). Satır: id, tarih, fis_id, fis_no, fis_tur, depo, miktar_milli, deger_kurus, yuruyen_milli, iptal (fiş iptal), ters (ters satır), neden_ad.
 * @return list<array>
 */
function stok_kart_hareketleri(PDO $pdo, int $kartId, array $f = [], int $limit = 200): array
{
    [$dw, $dp] = stok_depo_filtre('h.depo');
    $w = "h.kart_id = ? AND $dw"; $p = [$kartId, ...$dp];
    if (!empty($f['depo'])) { $w .= ' AND h.depo = ?'; $p[] = (string)$f['depo']; }
    $limit = max(1, min(1000, $limit));
    $st = $pdo->prepare("SELECT h.*, f.fis_no, f.tur AS fis_tur, f.durum AS fis_durum, f.neden_ad FROM stok_hareketler h LEFT JOIN stok_fisler f ON f.id = h.fis_id
        WHERE $w ORDER BY h.id DESC LIMIT $limit");
    $st->execute($p);
    $satirlar = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $yur = 0;
    if ($satirlar) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(h.miktar), 0) FROM stok_hareketler h WHERE $w AND h.id < ?");
        $st->execute([...$p, (int)$satirlar[0]['id']]);
        $yur = stok_milli($st->fetchColumn());
    }
    $out = [];
    foreach ($satirlar as $r) {
        $m = stok_milli($r['miktar']);
        $yur += $m;
        $out[] = [
            'id' => (int)$r['id'], 'tarih' => (string)$r['tarih'], 'fis_id' => (int)$r['fis_id'], 'fis_no' => (string)($r['fis_no'] ?? ''), 'fis_tur' => (string)($r['fis_tur'] ?? ''),
            'depo' => (string)$r['depo'], 'miktar_milli' => $m, 'deger_kurus' => cari_kurus($r['deger']), 'yuruyen_milli' => $yur,
            'iptal' => ($r['fis_durum'] ?? '') === 'iptal', 'ters' => $r['ters_id'] !== null && $r['ters_id'] !== '', 'neden_ad' => (string)($r['neden_ad'] ?? ''),
        ];
    }
    return array_reverse($out);
}

/** Aktif kartlar (fiş formu JS'i için). @return list<array> */
function stok_kart_secenekleri(PDO $pdo): array
{
    $o = [];
    foreach ($pdo->query('SELECT id, kod, ad, tip, ana_birim, cevrim_birim, cevrim_katsayi, kdv_orani, barkod FROM stok_kartlar WHERE aktif = 1 ORDER BY ad, id')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $o[] = ['id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'ad' => (string)$k['ad'], 'tip' => (string)$k['tip'], 'birim' => (string)$k['ana_birim'],
            'cevrim_birim' => $k['cevrim_birim'], 'cevrim_katsayi' => $k['cevrim_katsayi'] !== null ? (float)$k['cevrim_katsayi'] : null,
            'kdv' => (int)$k['kdv_orani'], 'barkod' => $k['barkod']];
    }
    return $o;
}

// ── Tanım ───────────────────────────────────────────────────────────────────

/** Kullanım sayısıyla (grup: kart sayısı; neden: fiş sayısı). @return list<array> */
function stok_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array
{
    if (!isset(stok_tanim_turleri()[$tur])) return [];
    $kul = $tur === 'grup'
        ? '(SELECT COUNT(*) FROM stok_kartlar k WHERE k.grup_id = t.id)'
        : '(SELECT COUNT(*) FROM stok_fisler f WHERE f.neden_id = t.id)';
    $st = $pdo->prepare("SELECT t.*, $kul AS kullanim FROM stok_tanimlar t WHERE t.tur = ?" . ($yalnizAktif ? ' AND t.aktif = 1' : '') . ' ORDER BY t.sira, t.ad, t.id');
    $st->execute([$tur]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function stok_tanim_bul(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM stok_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Ad TR-duyarsız tekil (tür içinde). Sistem tanımının (kod dolu) adı değişmez; sırası değişebilir. */
function stok_tanim_kaydet(PDO $pdo, string $tur, string $ad, int $sira, int $uid, ?int $id = null): array
{
    if (!isset(stok_tanim_turleri()[$tur])) return stok_hata('Geçersiz tanım türü.');
    $ad = trim($ad);
    if ($ad === '' || mb_strlen($ad) > 100) return stok_hata('Ad 1-100 karakter olmalı.', ['alanlar' => ['ad' => 'Ad 1-100 karakter olmalı.']]);
    if ($sira < 0 || $sira > 99999) return stok_hata('Sıra 0-99999 olmalı.', ['alanlar' => ['sira' => 'Sıra 0-99999 olmalı.']]);
    $st = $pdo->prepare('SELECT id, ad, kod, sira, tur FROM stok_tanimlar WHERE tur = ?');
    $st->execute([$tur]);
    $eski = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($id !== null && (int)$r['id'] === $id) $eski = $r;
        elseif (cari_fold((string)$r['ad']) === cari_fold($ad)) return stok_hata('Bu ad zaten tanımlı.', ['alanlar' => ['ad' => 'Bu ad zaten tanımlı.']]);
    }
    if ($id !== null && $eski === null) return stok_hata('Tanım bulunamadı.');
    if ($id === null) {
        $pdo->prepare('INSERT INTO stok_tanimlar (tur, ad, kod, sira, aktif, created_at) VALUES (?, ?, ?, ?, 1, ?)')->execute([$tur, $ad, null, $sira, date('Y-m-d H:i:s')]);
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'stok', $yid, null, ['tanim' => $tur, 'ad' => $ad, 'sira' => $sira]);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }
    if (!empty($eski['kod']) && $eski['ad'] !== $ad) {
        return stok_hata('Sistem tanımının adı değiştirilemez (yalnız sırası).', ['alanlar' => ['ad' => 'Sistem tanımı: ad kilitli.'], 'kod' => 'sistem']);
    }
    $pdo->prepare('UPDATE stok_tanimlar SET ad = ?, sira = ? WHERE id = ?')->execute([$ad, $sira, $id]);
    audit_log_event('update', 'stok', $id, ['tanim' => $tur, 'ad' => $eski['ad'], 'sira' => (int)$eski['sira']], ['tanim' => $tur, 'ad' => $ad, 'sira' => $sira]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

function stok_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $t = stok_tanim_bul($pdo, $id);
    if ($t === null) return stok_hata('Tanım bulunamadı.');
    $pdo->prepare('UPDATE stok_tanimlar SET aktif = ? WHERE id = ?')->execute([$aktif ? 1 : 0, $id]);
    audit_log_event($aktif ? 'activate' : 'deactivate', 'stok', $id, ['tanim' => $t['tur'], 'ad' => $t['ad'], 'aktif' => (int)$t['aktif']], ['aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Sistem tanımı (kod dolu) ya da kullanımdaki tanım silinmez (kod `kullanimda`); pasifleştirilir. */
function stok_tanim_sil(PDO $pdo, int $id, int $uid): array
{
    $t = stok_tanim_bul($pdo, $id);
    if ($t === null) return stok_hata('Tanım bulunamadı.');
    if (!empty($t['kod'])) return stok_hata('Sistem tanımı silinemez; pasifleştirebilirsiniz.', ['kod' => 'kullanimda']);
    $s = $pdo->prepare($t['tur'] === 'grup' ? 'SELECT COUNT(*) FROM stok_kartlar WHERE grup_id = ?' : 'SELECT COUNT(*) FROM stok_fisler WHERE neden_id = ?');
    $s->execute([$id]);
    if ((int)$s->fetchColumn() > 0) return stok_hata('Bu tanım kullanılıyor; silmek yerine pasifleştirin.', ['kod' => 'kullanimda']);
    $pdo->prepare('DELETE FROM stok_tanimlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'stok', $id, ['tanim' => $t['tur'], 'ad' => $t['ad']], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

// ── İçe aktarım (material_definitions → stok kartı; isteğe bağlı, idempotent; md_id bağı, sonraki ad değişikliği YAYILMAZ) ──

/**
 * type='urun' → tip urun, kg · ms_summary_types() malzeme türleri → tip malzeme, adet. Yalnız is_active=1.
 * @return list<array{ad:string,kaynak_tur:string,hedef_tip:string,ana_birim:string,durum:string,md_id:int}>  durum: yeni | var
 */
function stok_ice_aktar_onizle(PDO $pdo): array
{
    $mlz = function_exists('ms_summary_types') ? ms_summary_types() : [];
    $turler = array_values(array_unique(array_merge(['urun'], $mlz)));
    $st = $pdo->prepare('SELECT id, name, type FROM material_definitions WHERE is_active = 1 AND type IN (' . implode(',', array_fill(0, count($turler), '?')) . ') ORDER BY id');
    $st->execute($turler);
    $mdVar = []; $adVar = [];
    foreach ($pdo->query('SELECT md_id, ad FROM stok_kartlar')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        if ($k['md_id'] !== null) $mdVar[(int)$k['md_id']] = true;
        $adVar[cari_fold((string)$k['ad'])] = true;
    }
    $etiket = function_exists('definition_types') ? definition_types() : [];
    $gorulen = []; $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ad = trim((string)$r['name']);
        if ($ad === '') continue;
        $k = cari_fold($ad);
        if (isset($gorulen[$k])) continue;
        $gorulen[$k] = true;
        $urun = (string)$r['type'] === 'urun';
        $out[] = ['ad' => $ad, 'kaynak_tur' => (string)($etiket[(string)$r['type']] ?? $r['type']), 'hedef_tip' => $urun ? 'urun' : 'malzeme', 'ana_birim' => $urun ? 'kg' : 'adet',
            'durum' => (isset($mdVar[(int)$r['id']]) || isset($adVar[$k])) ? 'var' : 'yeni', 'md_id' => (int)$r['id']];
    }
    return $out;
}

/** Yalnız `yeni` olanları ekler; tek transaction; tek özet audit. @return array{ok:bool,hata:?string,id:?int,eklenen:int,atlanan:int} */
function stok_ice_aktar(PDO $pdo, int $uid): array
{
    $onizle = stok_ice_aktar_onizle($pdo);
    $eklenen = 0; $atlanan = 0;
    stok_tx($pdo, function () use ($pdo, $onizle, $uid, &$eklenen, &$atlanan): void {
        $ins = $pdo->prepare('INSERT INTO stok_kartlar (kod, ad, tip, ana_birim, kdv_orani, aktif, md_id, created_at, created_by) VALUES (?, ?, ?, ?, 1, 1, ?, ?, ?)');
        foreach ($onizle as $o) {
            if ($o['durum'] !== 'yeni' || mb_strlen($o['ad']) > 200 || mb_strlen($o['ad']) < 2) { $atlanan++; continue; }
            $ins->execute([stok_kod_oner($pdo), $o['ad'], $o['hedef_tip'], $o['ana_birim'], $o['md_id'], date('Y-m-d H:i:s'), $uid]);
            $eklenen++;
        }
    });
    audit_log_event('create', 'stok', null, null, ['ice_aktar' => 'material_definitions', 'eklenen' => $eklenen, 'atlanan' => $atlanan]);
    return ['ok' => true, 'hata' => null, 'id' => null, 'eklenen' => $eklenen, 'atlanan' => $atlanan];
}

// ── Maliyet motoru (SAF: DB yok, yan etki yok) ─────────────────────────────

/**
 * Hareketli ağırlıklı ortalama. $d = {q: milli (işaretli), v: kuruş, ort: kuruş/birim}; $q = hareket milli (işaretli);
 * $deger = hareket değeri kuruş (işaretli) ya da null (= ortalamadan değerle).
 *  - Giriş (q>0) değerli: Q≤0 ise ort' = deger/q ve V' = Q'·ort'; aksi V' = V+deger, ort' = V'/Q'.
 *  - Değersiz hareket (deger=null; çıkış ya da fiyatsız giriş): deger = q·ort; ort DEĞİŞMEZ; Q>0 ve Q'>0 ise V' = V+deger, aksi V' = Q'·ort.
 *  - Değerli çıkış (q<0; ters satır: orijinal girişin değeri eksi işaretle): Q'>0 ise V' = V+deger, ort' = V'/Q'; aksi V' = Q'·ort, ort değişmez.
 *  Q'=0 → V'=0. V'<0 ve Q'>0 → V'=0. ort ≥ 0.
 * @return array{d:array{q:int,v:int,ort:float},deger:int}
 */
function stok_maliyet_uygula(array $d, int $q, ?int $deger): array
{
    $Q = (int)($d['q'] ?? 0); $V = (int)($d['v'] ?? 0); $ort = max(0.0, (float)($d['ort'] ?? 0));
    if ($q === 0) return ['d' => ['q' => $Q, 'v' => $V, 'ort' => $ort], 'deger' => 0];
    $Q2 = $Q + $q;
    if ($deger === null) {
        $deger = (int)round($q / 1000 * $ort);
        $V2 = ($Q > 0 && $Q2 > 0) ? $V + $deger : (int)round($Q2 / 1000 * $ort);
        $ort2 = $ort;
    } elseif ($q > 0) {
        if ($Q <= 0) {
            $ort2 = $deger / ($q / 1000);
            $V2 = (int)round($Q2 / 1000 * $ort2);
        } else {
            $V2 = $V + $deger;
            $ort2 = $Q2 > 0 ? $V2 / ($Q2 / 1000) : $ort;
        }
    } else {
        if ($Q2 > 0) {
            $V2 = max(0, $V + $deger);
            $ort2 = $V2 / ($Q2 / 1000);
        } else {
            $V2 = (int)round($Q2 / 1000 * $ort);
            $ort2 = $ort;
        }
    }
    if ($Q2 === 0) $V2 = 0;
    if ($Q2 > 0 && $V2 < 0) $V2 = 0;
    $ort2 = max(0.0, round((float)$ort2, 4));
    return ['d' => ['q' => $Q2, 'v' => (int)$V2, 'ort' => $ort2], 'deger' => (int)$deger];
}
