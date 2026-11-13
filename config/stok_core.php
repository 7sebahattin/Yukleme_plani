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
            'satir_hatalari' => (object)($s['satir_hatalari'] ?? []), 'aktarilan' => $s['aktarilan'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok ? $okMesaj . (!empty($s['fis_no']) ? ' · ' . $s['fis_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '') : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    if (!$ok && !empty($s['eksikler']) && is_array($s['eksikler'])) {   // klasik form: eksik listesi flash'a
        $msg .= ' ' . implode('; ', array_map(static fn(array $e): string => $e['kod'] . ' (' . ($e['depo'] !== '' ? $e['depo'] : '—') . '): mevcut ' . stok_miktar_bicim((int)$e['mevcut_milli'], (string)$e['birim']) . ', istenen ' . stok_miktar_bicim((int)$e['istenen_milli'], (string)$e['birim']) . ' ' . $e['birim'], $s['eksikler']));
    }
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

// ═════════════════════════════════════════════════════════════════════════════
// Parça B — Fişler (giriş / çıkış / transfer / dönüşüm), defter yazımı, iptal, cari bağı, kantardan doldur
// Fiş sonrası DEĞİŞMEZ: düzeltme = iptal (ters satırlar) + "Kopyala". Taslak YOK. stok_fisler UPDATE beyaz listesi:
//   fis_no = ?, cari_hareket_id = ?   ·   durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?
// Cari'ye YALNIZ cari_core fonksiyonlarıyla: cari_hareket_ekle(..., $kaynak) · cari_kaynakli_iptal(...). Kantar YALNIZ okunur (SELECT).
// ═════════════════════════════════════════════════════════════════════════════

const STOK_FIS_SATIR_MAX = 200;
const STOK_IC_HATA = 4242;   // fiş transaction'ını geri alan iç hata kodu (RuntimeException)

/** MySQL: fişteki kart satırlarını FOR UPDATE ile kilitler (eşzamanlı fişler ortalamayı bozmasın). SQLite: no-op. @param list<int> $ids */
function stok_kart_kilitle(PDO $pdo, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    if (!$ids || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    sort($ids);
    $pdo->prepare('SELECT id FROM stok_kartlar WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE')->execute($ids);
}

/**
 * stok_hareketler'e TEK INSERT noktası (maliyet anlık görüntüsüyle). $durumlar: kart_id → maliyet durumu (istek içi; yoksa DB'den okunur, sonra güncellenir).
 * $h: fis_id, satir_id?, kart_id, tarih, depo, q (milli, işaretli), deger (?int kuruş TRY; null = ortalamadan), ters_id?
 * @return array{id:int,deger:int}
 */
function stok_hareket_yaz(PDO $pdo, array &$durumlar, array $h, int $uid): array
{
    $kart = (int)$h['kart_id'];
    if (!isset($durumlar[$kart])) $durumlar[$kart] = stok_maliyet_durumu($pdo, $kart);
    $r = stok_maliyet_uygula($durumlar[$kart], (int)$h['q'], isset($h['deger']) ? (int)$h['deger'] : null);
    $pdo->prepare('INSERT INTO stok_hareketler (fis_id, satir_id, kart_id, tarih, depo, miktar, deger, q_sonra, v_sonra, ort_sonra, ters_id, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$h['fis_id'], isset($h['satir_id']) ? (int)$h['satir_id'] : null, $kart, (string)$h['tarih'], (string)$h['depo'], stok_milli_db((int)$h['q']),
            number_format($r['deger'] / 100, 2, '.', ''), stok_milli_db($r['d']['q']), number_format($r['d']['v'] / 100, 2, '.', ''), number_format($r['d']['ort'] / 100, 6, '.', ''),
            isset($h['ters_id']) ? (int)$h['ters_id'] : null, date('Y-m-d H:i:s'), $uid]);
    $durumlar[$kart] = $r['d'];
    return ['id' => (int)$pdo->lastInsertId(), 'deger' => $r['deger']];
}

/** Birim fiyat → on-binde tam sayı (₺ × 10000; en çok 4 ondalık; TR yazımı). ≤0 / geçersiz → null. */
function stok_fiyat_coz(mixed $ham): ?int
{
    if (is_array($ham) || is_object($ham) || is_bool($ham)) return null;
    $s = preg_replace('/[\s\x{00A0}₺$€]+/u', '', trim((string)$ham)) ?? '';
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    if (!preg_match('/^(\d{1,11})(?:\.(\d{1,4}))?$/', $s, $m)) return null;
    $v = (int)$m[1] * 10000 + (int)str_pad($m[2] ?? '', 4, '0');
    return $v > 0 ? $v : null;
}

/** on-binde fiyat → "14,20" / "14,2050" (en az 2, en çok 4 ondalık). */
function stok_fiyat_bicim(int $f4): string
{
    $k = str_pad((string)($f4 % 10000), 4, '0', STR_PAD_LEFT);
    $k = rtrim($k, '0'); if (strlen($k) < 2) $k = str_pad($k, 2, '0');
    return number_format((float)intdiv($f4, 10000), 0, ',', '.') . ',' . $k;
}

/** Tarih: Y-m-d ya da d.m.Y → Y-m-d (geçersiz → null). */
function stok_tarih_coz(mixed $ham): ?string
{
    $t = trim(is_scalar($ham) ? (string)$ham : '');
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $t, $m)) $t = "$m[3]-$m[2]-$m[1]";
    return cari_tarih_gecerli($t) ? $t : null;
}

/**
 * POST paralel dizileri (kart_id[] kart_ara[] miktar[] birim[] birim_fiyat[] kdv_orani[] lot[] yon[] satir_aciklama[]) ya da `satir` listesi → satır listesi.
 * Tamamen boş satırlar (kart yok, kart metni yok, miktar yok) atılır; `i` = formdaki sıra (hata eşlemesi için).
 * @return list<array<string,string|int>>
 */
function stok_fis_satir_topla(array $g): array
{
    $alanlar = ['kart_id', 'kart_ara', 'miktar', 'birim', 'birim_fiyat', 'kdv_orani', 'lot', 'yon', 'satir_aciklama'];
    if (isset($g['satir']) && is_array($g['satir'])) {
        $ham = [];
        foreach (array_values($g['satir']) as $r) if (is_array($r)) $ham[] = $r + (isset($r['aciklama']) ? ['satir_aciklama' => $r['aciklama']] : []);
    } else {
        $sutun = []; $n = 0;
        foreach ($alanlar as $a) { $sutun[$a] = is_array($g[$a] ?? null) ? array_values($g[$a]) : []; $n = max($n, count($sutun[$a])); }
        $ham = [];
        for ($i = 0; $i < min($n, 1000); $i++) { $r = []; foreach ($alanlar as $a) $r[$a] = $sutun[$a][$i] ?? ''; $ham[] = $r; }
    }
    $out = [];
    foreach ($ham as $i => $r) {
        $t = [];
        foreach ($alanlar as $a) $t[$a] = is_scalar($r[$a] ?? '') ? trim((string)($r[$a] ?? '')) : '';
        if (($t['kart_id'] === '' || $t['kart_id'] === '0') && $t['kart_ara'] === '' && $t['miktar'] === '') continue;
        $t['i'] = $i;
        $out[] = $t;
    }
    return $out;
}

/** Kart seçimi: id ya da "KOD — Ad" / kod / barkod / tam ad metni. @param array<int,array> $kartlar id → kart */
function stok_kart_coz(array $kartlar, string $id, string $metin): ?array
{
    if ($id !== '' && ctype_digit($id)) return $kartlar[(int)$id] ?? null;
    $metin = trim($metin);
    if ($metin === '') return null;
    $kodAday = cari_fold(trim(explode(' — ', $metin)[0]));
    foreach ($kartlar as $k) if (cari_fold((string)$k['kod']) === $kodAday) return $k;
    foreach ($kartlar as $k) if ($k['barkod'] !== null && (string)$k['barkod'] === $metin) return $k;
    $bul = null;
    foreach ($kartlar as $k) if (cari_fold((string)$k['ad']) === cari_fold($metin)) { if ($bul !== null) return null; $bul = $k; }
    return $bul;
}

/** Depo seçeneklerinde var mı (TR-duyarsız) → seçenekteki yazım; yoksa null. */
function stok_depo_secenek(string $depo): ?string
{
    $depo = trim($depo);
    if ($depo === '') return null;
    foreach (depot_options() as $o) if ((string)$o === $depo) return (string)$o;
    foreach (depot_options() as $o) if (depo_fold((string)$o) === depo_fold($depo)) return (string)$o;
    return null;
}

/** Kantar fişi (salt okunur; tablo yoksa null). */
function stok_kantar_oku(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT id, fis_no, giris_tarih, firma_adi, malin_cinsi, depo, net_kg, kasa_sayisi, kasa_dara, palet_sayisi, palet_dara FROM kantar_fisleri WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    } catch (PDOException $e) {
        return null;
    }
}

/** Kantar fişine bağlı GEÇERLİ stok fişi (çift aktarım koruması). */
function stok_kantar_bagli_fis(PDO $pdo, int $kantarId): ?array
{
    $st = $pdo->prepare("SELECT id, fis_no FROM stok_fisler WHERE kaynak_modul = 'kantar' AND kaynak_id = ? AND durum = 'gecerli' ORDER BY id LIMIT 1");
    $st->execute([$kantarId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/**
 * Fiş doğrulaması (yazmaz). g: tur, tarih, depo, hedef_depo?, neden_id?, cari_id? | cari_ara?, para_birimi?, kur?, evrak_no?, aciklama?,
 *   satir (liste) ya da paralel diziler (stok_fis_satir_topla), istek_anahtari?, eksiye_izin?, kaynak_modul? ('kantar'), kaynak_id?.
 * @return array{ok:bool,hata?:string,veri?:array,alanlar?:array<string,string>,satir_hatalari?:array<int,array<string,string>>,kod?:string}
 */
function stok_fis_dogrula(PDO $pdo, array $g): array
{
    $h = []; $sh = []; $v = [];
    $tur = (string)($g['tur'] ?? '');
    if (!isset(stok_fis_turleri()[$tur])) return ['ok' => false, 'hata' => 'Geçersiz fiş türü.', 'alanlar' => ['tur' => 'Geçersiz fiş türü.']];
    $v['tur'] = $tur;

    $tarih = stok_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) $h['tarih'] = 'Geçerli bir tarih girin.'; else $v['tarih'] = $tarih;

    $depo = stok_depo_secenek((string)($g['depo'] ?? ''));
    if ($depo === null) $h['depo'] = 'Depo seçin.';
    elseif (!depot_visible_to_user($depo)) $h['depo'] = 'Bu depo aktif deponuzda değil; önce depo değiştirin.';
    else $v['depo'] = $depo;

    $v['hedef_depo'] = null;
    if ($tur === 'transfer') {
        $hd = stok_depo_secenek((string)($g['hedef_depo'] ?? ''));
        if ($hd === null) $h['hedef_depo'] = 'Hedef depoyu seçin.';
        elseif ($depo !== null && depo_fold($hd) === depo_fold($depo)) $h['hedef_depo'] = 'Hedef depo kaynak depodan farklı olmalı.';
        else $v['hedef_depo'] = $hd;
    }

    $v['neden_id'] = null; $v['neden_ad'] = null;
    if ($tur === 'giris' || $tur === 'cikis') {
        $nid = (int)($g['neden_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, ad FROM stok_tanimlar WHERE id = ? AND tur = ? AND aktif = 1');
        $st->execute([$nid, $tur === 'giris' ? 'giris_neden' : 'cikis_neden']);
        $n = $st->fetch(PDO::FETCH_ASSOC);
        if ($n === false) $h['neden_id'] = 'Neden seçin.'; else { $v['neden_id'] = (int)$n['id']; $v['neden_ad'] = (string)$n['ad']; }
    }

    // Cari (yalnız giriş/çıkış; transfer/dönüşümde YASAK)
    $v['cari_id'] = null;
    $cariHam = trim((string)($g['cari_id'] ?? ''));
    $cariMetin = trim((string)($g['cari_ara'] ?? ''));
    if ($cariHam !== '' && $cariHam !== '0' || $cariMetin !== '') {
        if ($tur === 'transfer' || $tur === 'donusum') $h['cari_id'] = 'Transfer/dönüşüm fişinde cari seçilmez.';
        elseif (!cari_hazir($pdo)) $h['cari_id'] = 'Cari tabloları kurulmamış.';
        else {
            $ck = null;
            if ($cariHam !== '' && ctype_digit($cariHam)) $ck = cari_kart_oku($pdo, (int)$cariHam);
            else {
                $kod = cari_fold(trim(explode(' — ', $cariMetin)[0]));
                foreach (stok_cari_secenekleri($pdo) as $c) if (cari_fold($c['kod']) === $kod || cari_fold($c['unvan']) === cari_fold($cariMetin)) { $ck = cari_kart_oku($pdo, $c['id']); break; }
            }
            if ($ck === null) $h['cari_id'] = 'Cari bulunamadı.';
            elseif ((int)$ck['aktif'] !== 1) $h['cari_id'] = 'Pasif cari seçilemez.';
            else $v['cari_id'] = (int)$ck['id'];
        }
    }

    $pb = (string)($g['para_birimi'] ?? 'TRY');
    if ($pb === '') $pb = 'TRY';
    $v['pb'] = 'TRY'; $v['kur'] = null; $kurF = 1.0;
    if ($tur === 'giris' || $tur === 'cikis') {
        if (!in_array($pb, cari_para_birimleri(), true)) $h['para_birimi'] = 'Geçersiz para birimi.';
        else {
            $v['pb'] = $pb;
            if ($pb !== 'TRY') {
                $kur = cari_kur_coz($g['kur'] ?? '', $pb);
                if ($kur === null || $kur === false) $h['kur'] = 'TL dışı para biriminde kur zorunlu (en çok 6 ondalık).';
                else { $v['kur'] = $kur; $kurF = (float)$kur; }
            }
        }
    }

    $ev = trim((string)($g['evrak_no'] ?? ''));
    if (mb_strlen($ev) > 60) $h['evrak_no'] = 'Evrak no en çok 60 karakter.'; else $v['evrak_no'] = $ev === '' ? null : $ev;
    $ac = trim((string)($g['aciklama'] ?? ''));
    if (mb_strlen($ac) > 500) $h['aciklama'] = 'Açıklama en çok 500 karakter.'; else $v['aciklama'] = $ac === '' ? null : $ac;
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    $v['ik'] = $ik === '' ? null : $ik;
    $v['eksiye_izin'] = !empty($g['eksiye_izin']) && (string)$g['eksiye_izin'] !== '0';

    $v['kaynak_modul'] = null; $v['kaynak_id'] = null;
    $km = trim((string)($g['kaynak_modul'] ?? ''));
    $kod = null;
    if ($km !== '') {
        $kid = (int)($g['kaynak_id'] ?? 0);
        if ($km !== 'kantar' || $tur !== 'giris') $h['kaynak_modul'] = 'Geçersiz kaynak.';
        elseif (($kf = stok_kantar_oku($pdo, $kid)) === null) $h['kaynak_modul'] = 'Kantar fişi bulunamadı.';
        elseif (!depot_visible_to_user((string)$kf['depo'])) $h['kaynak_modul'] = 'Kantar fişi aktif deponuzda değil.';
        elseif (($b = stok_kantar_bagli_fis($pdo, $kid)) !== null) { $h['kaynak_modul'] = 'Bu kantar fişi zaten ' . $b['fis_no'] . ' ile aktarılmış.'; $kod = 'kantar_aktarilmis'; }
        else { $v['kaynak_modul'] = 'kantar'; $v['kaynak_id'] = $kid; }
    }

    // Satırlar
    $ham = stok_fis_satir_topla($g);
    if (!$ham) $h['satir'] = 'En az bir satır girin.';
    elseif (count($ham) > STOK_FIS_SATIR_MAX) $h['satir'] = 'Bir fişte en çok ' . STOK_FIS_SATIR_MAX . ' satır olabilir.';
    $kartlar = [];
    foreach ($pdo->query('SELECT id, kod, ad, ana_birim, cevrim_birim, cevrim_katsayi, kdv_orani, barkod, aktif FROM stok_kartlar')->fetchAll(PDO::FETCH_ASSOC) as $k) $kartlar[(int)$k['id']] = $k;
    $fiyatli = $tur === 'giris' || $tur === 'cikis';
    $satirlar = []; $topMil = 0; $topTut = 0; $topKdv = 0; $fiyatVar = false; $yonSay = ['G' => 0, 'C' => 0];
    foreach ($ham as $sira => $r) {
        $i = (int)$r['i']; $e = [];
        $k = stok_kart_coz($kartlar, (string)$r['kart_id'], (string)$r['kart_ara']);
        if ($k === null) $e['kart_id'] = 'Stok kartı seçin.';
        elseif ((int)$k['aktif'] !== 1) $e['kart_id'] = 'Pasif kart seçilemez.';
        $yon = match ($tur) { 'giris' => 'G', 'cikis', 'transfer' => 'C', default => strtoupper((string)$r['yon']) };
        if (!in_array($yon, ['G', 'C'], true)) { $e['yon'] = 'Girdi/çıktı yönü geçersiz.'; $yon = 'C'; }
        $milli = null;
        if ($k !== null) {
            $ana = (string)$k['ana_birim'];
            $birim = (string)$r['birim'];
            if ($birim === '' || $birim === $ana) {
                $milli = stok_miktar_coz($r['miktar'], $ana);
                if ($milli === null) $e['miktar'] = 'Geçerli bir miktar girin (> 0' . (stok_birim_tam_mi($ana) ? ', tam sayı' : '') . ').';
            } elseif ($k['cevrim_birim'] !== null && $birim === (string)$k['cevrim_birim']) {
                $cm = stok_miktar_coz($r['miktar'], $birim);
                $kat = stok_milli($k['cevrim_katsayi']);
                if ($cm === null || $kat <= 0) $e['miktar'] = 'Geçerli bir miktar girin (> 0' . (stok_birim_tam_mi($birim) ? ', tam sayı' : '') . ').';
                else {
                    $milli = (int)round($cm * $kat / 1000);
                    if (stok_birim_tam_mi($ana) && $milli % 1000 !== 0) { $e['miktar'] = 'Çevrim sonucu ' . $ana . ' tam sayı değil; ' . $ana . ' olarak girin.'; $milli = null; }
                    elseif ($milli <= 0 || $milli > STOK_MIKTAR_MAX_MILLI) { $e['miktar'] = 'Miktar sınır dışı.'; $milli = null; }
                }
            } else $e['birim'] = 'Bu kart için geçersiz birim.';
        }
        $f4 = null; $kdv = null; $tut = null; $kdvT = null;
        $fHam = (string)$r['birim_fiyat'];
        if ($fHam !== '') {
            if (!$fiyatli) $e['birim_fiyat'] = 'Bu fiş türünde fiyat girilmez (maliyet otomatik).';
            elseif (($f4 = stok_fiyat_coz($fHam)) === null) $e['birim_fiyat'] = 'Geçerli bir birim fiyat girin (> 0, en çok 4 ondalık).';
        } elseif ($v['cari_id'] !== null) $e['birim_fiyat'] = 'Cari seçiliyken birim fiyat zorunlu.';
        if ($fiyatli && $f4 !== null) {
            $kh = (string)$r['kdv_orani'];
            $kdv = $kh === '' ? (int)($k['kdv_orani'] ?? 0) : (int)$kh;
            if (($kh !== '' && !preg_match('/^\d{1,2}$/', $kh)) || !in_array($kdv, stok_kdv_oranlari(), true)) { $e['kdv_orani'] = 'KDV 0, 1, 10 ya da 20.'; $kdv = null; }
            if ($milli !== null) {
                $tut = (int)round($milli * $f4 / 100000);
                if ($tut <= 0 || $tut > CARI_TUTAR_MAX_KURUS) $e['birim_fiyat'] = 'Satır tutarı sınır dışı.';
                $kdvT = $kdv !== null ? (int)round($tut * $kdv / 100) : null;
            }
        }
        $lot = (string)$r['lot'];
        if (mb_strlen($lot) > 60) $e['lot'] = 'Lot en çok 60 karakter.';
        $sa = (string)$r['satir_aciklama'];
        if (mb_strlen($sa) > 200) $e['aciklama'] = 'Satır açıklaması en çok 200 karakter.';
        if ($e) { $sh[$i] = $e; continue; }
        $yonSay[$yon]++;
        $satirlar[] = ['sira' => $sira + 1, 'i' => $i, 'kart_id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'ad' => (string)$k['ad'], 'birim' => (string)$k['ana_birim'], 'yon' => $yon, 'milli' => $milli,
            'fiyat4' => $f4, 'kdv' => $f4 !== null ? $kdv : null, 'tutar' => $tut, 'kdv_tutar' => $kdvT, 'lot' => $lot === '' ? null : $lot, 'aciklama' => $sa === '' ? null : $sa,
            'deger_try' => ($tur === 'giris' && $tut !== null) ? (int)round($tut * $kurF) : null];
        if ($tur !== 'donusum' || $yon === 'C') $topMil += $milli;
        if ($tut !== null) { $fiyatVar = true; $topTut += $tut; $topKdv += (int)$kdvT; }
    }
    if ($tur === 'donusum' && !$sh && $satirlar && ($yonSay['G'] === 0 || $yonSay['C'] === 0)) $h['satir'] = 'Dönüşüm fişinde en az bir girdi ve bir çıktı satırı olmalı.';
    if ($fiyatVar && $v['pb'] !== 'TRY' && $v['kur'] === null && !isset($h['kur'])) $h['kur'] = 'TL dışı para biriminde kur zorunlu.';
    if ($v['cari_id'] !== null && $topTut + $topKdv > CARI_TUTAR_MAX_KURUS) $h['satir'] = 'Fiş toplamı sınır dışı.';
    if ($h || $sh) {
        $ilk = $h ? (string)reset($h) : 'Satır ' . (array_key_first($sh) + 1) . ': ' . (string)reset($sh[array_key_first($sh)]);
        return ['ok' => false, 'hata' => $ilk, 'alanlar' => $h, 'satir_hatalari' => $sh] + ($kod !== null ? ['kod' => $kod] : []);
    }
    $v['satirlar'] = $satirlar;
    $v['toplam_milli'] = $topMil;
    $v['toplam_tutar'] = $fiyatVar ? $topTut : null;
    $v['toplam_kdv'] = $fiyatVar ? $topKdv : null;
    return ['ok' => true, 'veri' => $v];
}

function stok_fis_istek_bul(PDO $pdo, string $anahtar): ?array
{
    $st = $pdo->prepare('SELECT id, fis_no, tur FROM stok_fisler WHERE istek_anahtari = ?');
    $st->execute([$anahtar]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/**
 * Fiş bu stok düşüşünü karşılıyor mu? Depo bazında (kart, depo) net azalış > mevcut ise eksik. Okuma depo filtresiz (aynı depo, doğrudan).
 * @return list<array{kart_id:int,kod:string,ad:string,depo:string,birim:string,istenen_milli:int,mevcut_milli:int,eksik_milli:int}>
 */
function stok_fis_eksikler(PDO $pdo, array $v): array
{
    $delta = []; $bilgi = [];
    foreach ($v['satirlar'] as $s) {
        $isle = static function (string $depo, int $q) use (&$delta, &$bilgi, $s): void {
            $k = $s['kart_id'] . "\x1f" . $depo;
            $delta[$k] = ($delta[$k] ?? 0) + $q;
            $bilgi[$k] = ['kart_id' => $s['kart_id'], 'kod' => $s['kod'], 'ad' => $s['ad'], 'depo' => $depo, 'birim' => $s['birim']];
        };
        if ($v['tur'] === 'giris') $isle($v['depo'], $s['milli']);
        elseif ($v['tur'] === 'cikis') $isle($v['depo'], -$s['milli']);
        elseif ($v['tur'] === 'transfer') { $isle($v['depo'], -$s['milli']); $isle((string)$v['hedef_depo'], $s['milli']); }
        else $isle($v['depo'], $s['yon'] === 'G' ? $s['milli'] : -$s['milli']);
    }
    $out = [];
    $st = $pdo->prepare('SELECT COALESCE(SUM(miktar), 0) FROM stok_hareketler WHERE kart_id = ? AND depo = ?');
    foreach ($delta as $k => $d) {
        if ($d >= 0) continue;
        $b = $bilgi[$k];
        $st->execute([$b['kart_id'], $b['depo']]);
        $mevcut = stok_milli($st->fetchColumn());
        if ($mevcut + $d < 0) $out[] = $b + ['istenen_milli' => -$d, 'mevcut_milli' => $mevcut, 'eksik_milli' => -($mevcut + $d)];
    }
    return $out;
}

/** Eksik listesi → fiş `uyari` metni (≤255). */
function stok_eksik_uyari(array $eksikler, string $onek = 'Eksiye düşürüldü'): string
{
    $p = array_map(static fn(array $e): string => $e['kod'] . ' (' . ($e['depo'] !== '' ? $e['depo'] : '—') . ') −' . stok_miktar_bicim((int)$e['eksik_milli'], (string)$e['birim']) . ' ' . $e['birim'], $eksikler);
    return mb_substr($onek . ': ' . implode('; ', $p), 0, 255);
}

/**
 * Fişi kaydeder (TEK transaction): kilit → (kantar çift aktarım + negatif stok kontrolü) → fiş → satırlar → defter → (cari dekontu) → fis_no/cari bağı.
 * Aynı istek_anahtari → mevcut fiş (tekrar=true). Negatif stok: varsayılan RED (kod yetersiz_stok + eksikler); eksiye_izin=1 ile kayıt + fiş uyarısı.
 * Cari: giriş → alacak_dekont, çıkış → borc_dekont; tutar = KDV hariç toplam + KDV, fiş para birimiyle; hata → HEPSİ geri alınır.
 * @return array{ok:bool,hata:?string,id:?int,fis_no?:string,uyari?:?string,tekrar?:bool,kod?:string,eksikler?:list<array>,alanlar?:array,satir_hatalari?:array}
 */
function stok_fis_kaydet(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = stok_fis_istek_bul($pdo, $ik)) !== null) {
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'fis_no' => (string)$var['fis_no'], 'tekrar' => true];
    }
    $d = stok_fis_dogrula($pdo, $g);
    if (!$d['ok']) return stok_hata((string)$d['hata'], ['alanlar' => $d['alanlar'] ?? [], 'satir_hatalari' => $d['satir_hatalari'] ?? []] + (isset($d['kod']) ? ['kod' => $d['kod']] : []));
    $v = $d['veri'];
    $etiket = stok_fis_turleri()[$v['tur']]['etiket'];
    try {
        $s = stok_tx($pdo, function () use ($pdo, $v, $uid, $etiket): array {
            stok_kart_kilitle($pdo, array_column($v['satirlar'], 'kart_id'));
            if ($v['kaynak_modul'] === 'kantar' && ($b = stok_kantar_bagli_fis($pdo, (int)$v['kaynak_id'])) !== null) {
                return stok_hata('Bu kantar fişi zaten ' . $b['fis_no'] . ' ile aktarılmış.', ['kod' => 'kantar_aktarilmis', 'alanlar' => ['kaynak_modul' => 'Kantar fişi aktarılmış.']]);
            }
            $eksik = stok_fis_eksikler($pdo, $v);
            if ($eksik && !$v['eksiye_izin']) return stok_hata('Yetersiz stok: bu kayıt stoğu eksiye düşürecek.', ['kod' => 'yetersiz_stok', 'eksikler' => $eksik]);
            $uyari = $eksik ? stok_eksik_uyari($eksik) : null;
            $simdi = date('Y-m-d H:i:s');
            $para = static fn(?int $k): ?string => $k === null ? null : number_format($k / 100, 2, '.', '');
            $pdo->prepare('INSERT INTO stok_fisler (tur, fis_no, tarih, depo, hedef_depo, neden_id, neden_ad, cari_id, para_birimi, kur, toplam_miktar, toplam_tutar, toplam_kdv, evrak_no, aciklama, uyari, kaynak_modul, kaynak_id, istek_anahtari, durum, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$v['tur'], '', $v['tarih'], $v['depo'], $v['hedef_depo'], $v['neden_id'], $v['neden_ad'], $v['cari_id'], $v['pb'], $v['kur'], stok_milli_db($v['toplam_milli']),
                    $para($v['toplam_tutar']), $para($v['toplam_kdv']), $v['evrak_no'], $v['aciklama'], $uyari, $v['kaynak_modul'], $v['kaynak_id'], $v['ik'], 'gecerli', $simdi, $uid]);
            $fid = (int)$pdo->lastInsertId();
            $fisNo = stok_fis_no($v['tur'], $v['tarih'], $fid);
            $ins = $pdo->prepare('INSERT INTO stok_fis_satirlar (fis_id, sira, kart_id, yon, miktar, birim_fiyat, kdv_orani, tutar, kdv_tutar, lot, aciklama) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $satirIds = [];
            foreach ($v['satirlar'] as $n => $sat) {
                $ins->execute([$fid, $n + 1, $sat['kart_id'], $sat['yon'], stok_milli_db($sat['milli']), $sat['fiyat4'] !== null ? number_format($sat['fiyat4'] / 10000, 4, '.', '') : null,
                    $sat['kdv'], $para($sat['tutar']), $para($sat['kdv_tutar']), $sat['lot'], $sat['aciklama']]);
                $satirIds[$n] = (int)$pdo->lastInsertId();
            }
            // Defter
            $dur = [];
            $hb = static fn(int $n, array $sat, string $depo, int $q, ?int $deger): array => ['fis_id' => $fid, 'satir_id' => $satirIds[$n], 'kart_id' => $sat['kart_id'], 'tarih' => $v['tarih'], 'depo' => $depo, 'q' => $q, 'deger' => $deger];
            if ($v['tur'] === 'donusum') {
                $girdiDeger = 0; $ciktiMil = 0; $cikti = [];
                foreach ($v['satirlar'] as $n => $sat) {
                    if ($sat['yon'] === 'C') $girdiDeger += -stok_hareket_yaz($pdo, $dur, $hb($n, $sat, $v['depo'], -$sat['milli'], null), $uid)['deger'];
                    else { $ciktiMil += $sat['milli']; $cikti[] = $n; }
                }
                $dagitilan = 0;
                foreach ($cikti as $j => $n) {
                    $sat = $v['satirlar'][$n];
                    $pay = $j === count($cikti) - 1 ? $girdiDeger - $dagitilan : (int)round($girdiDeger * $sat['milli'] / $ciktiMil);
                    $dagitilan += $pay;
                    stok_hareket_yaz($pdo, $dur, $hb($n, $sat, $v['depo'], $sat['milli'], max(0, $pay)), $uid);
                }
            } else {
                foreach ($v['satirlar'] as $n => $sat) {
                    if ($v['tur'] === 'giris') stok_hareket_yaz($pdo, $dur, $hb($n, $sat, $v['depo'], $sat['milli'], $sat['deger_try']), $uid);
                    elseif ($v['tur'] === 'cikis') stok_hareket_yaz($pdo, $dur, $hb($n, $sat, $v['depo'], -$sat['milli'], null), $uid);
                    else {   // transfer: kaynak ortalamadan çıkar, hedefe AYNI değerle girer (ürün ortalaması değişmez)
                        $c = stok_hareket_yaz($pdo, $dur, $hb($n, $sat, $v['depo'], -$sat['milli'], null), $uid);
                        stok_hareket_yaz($pdo, $dur, $hb($n, $sat, (string)$v['hedef_depo'], $sat['milli'], -$c['deger']), $uid);
                    }
                }
            }
            // Cari dekontu (yalnız cari_core fonksiyonuyla; kaynak bağı çekirdekten verilir)
            $cariHid = null;
            if ($v['cari_id'] !== null) {
                $tutar = (int)$v['toplam_tutar'] + (int)$v['toplam_kdv'];
                $cr = cari_hareket_ekle($pdo, [
                    'tur' => $v['tur'] === 'giris' ? 'alacak_dekont' : 'borc_dekont', 'cari_id' => $v['cari_id'], 'tarih' => $v['tarih'],
                    'tutar' => number_format($tutar / 100, 2, '.', ''), 'para_birimi' => $v['pb'], 'kur' => $v['kur'] ?? '',
                    'aciklama' => 'Stok ' . $etiket . ' ' . $fisNo, 'evrak_no' => $v['evrak_no'] ?? $fisNo,
                ], $uid, ['modul' => 'stok', 'id' => $fid]);
                if (empty($cr['ok'])) throw new RuntimeException('Cari hareketi yazılamadı: ' . (string)($cr['hata'] ?? 'bilinmeyen hata'), STOK_IC_HATA);
                $cariHid = (int)$cr['id'];
            }
            $pdo->prepare('UPDATE stok_fisler SET fis_no = ?, cari_hareket_id = ? WHERE id = ?')->execute([$fisNo, $cariHid, $fid]);
            return ['ok' => true, 'hata' => null, 'id' => $fid, 'fis_no' => $fisNo, 'uyari' => $uyari, 'cari_hareket_id' => $cariHid, 'tekrar' => false];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== STOK_IC_HATA) throw $e;
        return stok_hata($e->getMessage());
    } catch (PDOException $e) {
        if ($v['ik'] !== null && ($var = stok_fis_istek_bul($pdo, $v['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'fis_no' => (string)$var['fis_no'], 'tekrar' => true];
        }
        error_log('[stok_fis_kaydet] ' . $e->getMessage());
        return stok_hata('Fiş kaydedilemedi.');
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('create', 'stok', (int)$s['id'], null, ['fis' => $v['tur'], 'fis_no' => $s['fis_no'], 'tarih' => $v['tarih'], 'depo' => $v['depo'], 'hedef_depo' => $v['hedef_depo'],
        'satir' => count($v['satirlar']), 'toplam_miktar' => $v['toplam_milli'] / 1000, 'cari_id' => $v['cari_id'], 'cari_hareket_id' => $s['cari_hareket_id'],
        'kaynak' => $v['kaynak_modul'] !== null ? $v['kaynak_modul'] . ':' . $v['kaynak_id'] : null, 'uyari' => $s['uyari']]);
    return $s;
}

/** Fiş başlığı (ham satır). */
function stok_fis_ham(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM stok_fisler WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Fiş aktif depoda görünür mü? (kaynak ya da hedef depo; depo boşsa görünür) */
function stok_fis_gorunur(array $f): bool
{
    return depot_visible_to_user((string)$f['depo']) || (!empty($f['hedef_depo']) && depot_visible_to_user((string)$f['hedef_depo']));
}

/**
 * İptal: durum + ters defter satırları (INSERT, ters_id; ters sırayla) + bağlı cari hareketi (cari_kaynakli_iptal). Her zaman yapılabilir;
 * iptal stoğu eksiye düşürürse `uyari` döner. Neden 3–500 karakter; zaten iptal → kod zaten_iptal.
 */
function stok_fis_iptal(PDO $pdo, int $id, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (mb_strlen($neden) < 3) return stok_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]);
    if (mb_strlen($neden) > 500) return stok_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
    $f = stok_fis_ham($pdo, $id);
    if ($f === null) return stok_hata('Fiş bulunamadı.');
    if (!stok_fis_gorunur($f)) return stok_hata('Bu fiş aktif deponuzda görünmüyor.');
    if ($f['durum'] === 'iptal') return stok_hata('Fiş zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
    try {
        $s = stok_tx($pdo, function () use ($pdo, $f, $id, $neden, $uid): array {
            $st = $pdo->prepare('SELECT * FROM stok_hareketler WHERE fis_id = ? AND ters_id IS NULL ORDER BY id DESC');
            $st->execute([$id]);
            $har = $st->fetchAll(PDO::FETCH_ASSOC);
            stok_kart_kilitle($pdo, array_map(static fn(array $h): int => (int)$h['kart_id'], $har));
            $up = $pdo->prepare("UPDATE stok_fisler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'");
            $up->execute([date('Y-m-d H:i:s'), $uid, $neden, $id]);
            if ($up->rowCount() !== 1) return stok_hata('Fiş zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
            $dur = []; $etki = [];
            foreach ($har as $h) {
                $q = -stok_milli($h['miktar']);
                stok_hareket_yaz($pdo, $dur, ['fis_id' => $id, 'satir_id' => $h['satir_id'] !== null ? (int)$h['satir_id'] : null, 'kart_id' => (int)$h['kart_id'], 'tarih' => (string)$h['tarih'],
                    'depo' => (string)$h['depo'], 'q' => $q, 'deger' => -cari_kurus($h['deger']), 'ters_id' => (int)$h['id']], $uid);
                if ($q < 0) $etki[(int)$h['kart_id'] . "\x1f" . $h['depo']] = [(int)$h['kart_id'], (string)$h['depo']];
            }
            $cariIptal = [];
            if (!empty($f['cari_hareket_id'])) {
                $cr = cari_kaynakli_iptal($pdo, 'stok', $id, 'Stok fişi iptali: ' . $neden, $uid);
                if (empty($cr['ok'])) throw new RuntimeException('Bağlı cari hareketi iptal edilemedi: ' . (string)($cr['hata'] ?? ''), STOK_IC_HATA);
                $cariIptal = $cr['iptal_idler'];
            }
            $eksik = [];
            if ($etki) {
                $bs = $pdo->prepare('SELECT COALESCE(SUM(h.miktar), 0) AS m, k.kod, k.ad, k.ana_birim FROM stok_hareketler h JOIN stok_kartlar k ON k.id = h.kart_id WHERE h.kart_id = ? AND h.depo = ? GROUP BY k.kod, k.ad, k.ana_birim');
                foreach ($etki as [$kart, $depo]) {
                    $bs->execute([$kart, $depo]);
                    $r = $bs->fetch(PDO::FETCH_ASSOC);
                    if ($r !== false && stok_milli($r['m']) < 0) $eksik[] = ['kart_id' => $kart, 'kod' => (string)$r['kod'], 'ad' => (string)$r['ad'], 'depo' => $depo, 'birim' => (string)$r['ana_birim'], 'eksik_milli' => -stok_milli($r['m'])];
                }
            }
            return ['ok' => true, 'hata' => null, 'id' => $id, 'fis_no' => (string)$f['fis_no'], 'uyari' => $eksik ? stok_eksik_uyari($eksik, 'İptal sonrası eksi bakiye') : null, 'ters' => count($har), 'cari_iptal' => $cariIptal];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== STOK_IC_HATA) throw $e;
        return stok_hata($e->getMessage());
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('cancel', 'stok', $id, ['durum' => 'gecerli', 'fis_no' => $f['fis_no']], ['durum' => 'iptal', 'neden' => $neden, 'ters_satir' => $s['ters'], 'cari_iptal' => $s['cari_iptal'], 'uyari' => $s['uyari']]);
    return $s;
}

/** Cari kartı (cari_core okuyucusuyla; tablo yoksa null). İstek içi önbellek. */
function stok_cari_oku(PDO $pdo, ?int $id): ?array
{
    static $on = [];
    if ($id === null || $id <= 0 || !cari_hazir($pdo)) return null;
    $k = spl_object_id($pdo) . ':' . $id;
    if (!array_key_exists($k, $on)) $on[$k] = cari_kart_oku($pdo, $id);
    return $on[$k];
}

/**
 * Fiş + satırlar (kart kodu/adı/birimi, satır maliyeti = defterdeki ilk geçerli satırın değeri) + defter satırları + cari/kantar bilgisi.
 * Görünürlük denetimi ÇAĞIRANDA (stok_fis_gorunur). @return ?array
 */
function stok_fis_oku(PDO $pdo, int $id): ?array
{
    $f = stok_fis_ham($pdo, $id);
    if ($f === null) return null;
    $st = $pdo->prepare('SELECT s.*, k.kod, k.ad, k.ana_birim FROM stok_fis_satirlar s LEFT JOIN stok_kartlar k ON k.id = s.kart_id WHERE s.fis_id = ? ORDER BY s.sira, s.id');
    $st->execute([$id]);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT * FROM stok_hareketler WHERE fis_id = ? ORDER BY id');
    $st->execute([$id]);
    $har = [];
    $maliyet = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $r = ['id' => (int)$h['id'], 'satir_id' => $h['satir_id'] !== null ? (int)$h['satir_id'] : null, 'kart_id' => (int)$h['kart_id'], 'depo' => (string)$h['depo'],
            'miktar_milli' => stok_milli($h['miktar']), 'deger_kurus' => cari_kurus($h['deger']), 'ters' => $h['ters_id'] !== null];
        $har[] = $r;
        if (!$r['ters'] && $r['satir_id'] !== null && !isset($maliyet[$r['satir_id']])) $maliyet[$r['satir_id']] = abs($r['deger_kurus']);
    }
    foreach ($satirlar as &$s) {
        $s['milli'] = stok_milli($s['miktar']);
        $s['maliyet_kurus'] = $maliyet[(int)$s['id']] ?? null;
    }
    unset($s);
    $f['satirlar'] = $satirlar;
    $f['hareketler'] = $har;
    $f['cari'] = stok_cari_oku($pdo, $f['cari_id'] !== null ? (int)$f['cari_id'] : null);
    $f['cari_hareket'] = !empty($f['cari_hareket_id']) && cari_hazir($pdo) ? cari_hareket_oku($pdo, (int)$f['cari_hareket_id']) : null;
    $f['kantar'] = $f['kaynak_modul'] === 'kantar' ? stok_kantar_oku($pdo, (int)$f['kaynak_id']) : null;
    return $f;
}

/**
 * Fiş listesi (EN YENİ ÖNCE). f: bas, bit (Y-m-d; `bas` anahtarı hiç verilmezse son 30 gün), durum (gecerli|iptal|tumu), neden_id, ara (fiş no / evrak / açıklama / cari / ürün).
 * Depo: aktif depo filtreli; transfer'de kaynak YA DA hedef depo. @return array{satirlar:list<array>,toplam_satir:int}
 */
function stok_fis_listesi(PDO $pdo, string $tur, array $f = [], int $limit = 100, int $offset = 0): array
{
    $w = ['f.tur = ?']; $p = [$tur];
    if ($tur === 'transfer') {
        [$w1, $p1] = stok_depo_filtre('f.depo');
        [$w2, $p2] = stok_depo_filtre('f.hedef_depo');
        $w[] = "($w1 OR $w2)"; array_push($p, ...$p1, ...$p2);
    } else {
        [$w1, $p1] = stok_depo_filtre('f.depo');
        $w[] = $w1; array_push($p, ...$p1);
    }
    $bas = array_key_exists('bas', $f) ? (string)$f['bas'] : date('Y-m-d', strtotime('-30 days'));
    if ($bas !== '') { $w[] = 'f.tarih >= ?'; $p[] = $bas; }
    if (!empty($f['bit'])) { $w[] = 'f.tarih <= ?'; $p[] = (string)$f['bit']; }
    $durum = (string)($f['durum'] ?? 'tumu');
    if ($durum === 'gecerli' || $durum === 'iptal') { $w[] = 'f.durum = ?'; $p[] = $durum; }
    if (!empty($f['neden_id'])) { $w[] = 'f.neden_id = ?'; $p[] = (int)$f['neden_id']; }
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $l = '%' . $q . '%';
        $or = ['f.fis_no LIKE ?', 'f.evrak_no LIKE ?', 'f.aciklama LIKE ?', 'EXISTS (SELECT 1 FROM stok_fis_satirlar s JOIN stok_kartlar k ON k.id = s.kart_id WHERE s.fis_id = f.id AND (k.ad LIKE ? OR k.kod LIKE ?))'];
        array_push($p, $l, $l, $l, $l, $l);
        if (cari_hazir($pdo)) {
            $ids = array_slice(array_map(static fn(array $k): int => (int)$k['id'], cari_kart_listesi($pdo, ['q' => $q])['satirlar']), 0, 500);
            if ($ids) { $or[] = 'f.cari_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; array_push($p, ...$ids); }
        }
        $w[] = '(' . implode(' OR ', $or) . ')';
    }
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM stok_fisler f WHERE $where");
    $st->execute($p);
    $toplam = (int)$st->fetchColumn();
    $limit = max(1, min(500, $limit)); $offset = max(0, $offset);
    $st = $pdo->prepare("SELECT f.*,
            (SELECT COUNT(*) FROM stok_fis_satirlar s WHERE s.fis_id = f.id) AS satir_say,
            (SELECT k.ad FROM stok_fis_satirlar s JOIN stok_kartlar k ON k.id = s.kart_id WHERE s.fis_id = f.id ORDER BY s.sira, s.id LIMIT 1) AS ilk_kart,
            (SELECT MIN(k.ana_birim) FROM stok_fis_satirlar s JOIN stok_kartlar k ON k.id = s.kart_id WHERE s.fis_id = f.id) AS birim_min,
            (SELECT MAX(k.ana_birim) FROM stok_fis_satirlar s JOIN stok_kartlar k ON k.id = s.kart_id WHERE s.fis_id = f.id) AS birim_max
        FROM stok_fisler f WHERE $where ORDER BY f.tarih DESC, f.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = stok_cari_oku($pdo, $r['cari_id'] !== null ? (int)$r['cari_id'] : null);
        $r['cari_unvan'] = $c !== null ? (string)$c['unvan'] : '';
        $r['birim'] = $r['birim_min'] !== null && $r['birim_min'] === $r['birim_max'] ? (string)$r['birim_min'] : '';
        $r['toplam_milli'] = stok_milli($r['toplam_miktar']);
        $out[] = $r;
    }
    return ['satirlar' => $out, 'toplam_satir' => $toplam];
}

/** "Kopyala": yeni fiş formu ön değerleri (tarih bugün, evrak/istek boş). Satırlarda miktar/fiyat ekran biçiminde. */
function stok_fis_kopya(array $f): array
{
    $sat = [];
    foreach ($f['satirlar'] ?? [] as $s) {
        $birim = (string)($s['ana_birim'] ?? 'kg');
        $sat[] = ['kart_id' => (int)$s['kart_id'], 'yon' => (string)$s['yon'], 'miktar' => stok_miktar_bicim(stok_milli($s['miktar']), $birim), 'birim' => '',
            'birim_fiyat' => $s['birim_fiyat'] !== null && $s['birim_fiyat'] !== '' ? stok_fiyat_bicim((int)round((float)$s['birim_fiyat'] * 10000)) : '',
            'kdv_orani' => $s['kdv_orani'] !== null ? (string)(int)$s['kdv_orani'] : '', 'lot' => (string)($s['lot'] ?? ''), 'satir_aciklama' => (string)($s['aciklama'] ?? '')];
    }
    return ['tur' => (string)$f['tur'], 'tarih' => date('Y-m-d'), 'depo' => (string)$f['depo'], 'hedef_depo' => (string)($f['hedef_depo'] ?? ''), 'neden_id' => $f['neden_id'] !== null ? (int)$f['neden_id'] : null,
        'cari_id' => $f['cari_id'] !== null ? (int)$f['cari_id'] : null, 'para_birimi' => (string)$f['para_birimi'], 'kur' => $f['kur'] !== null ? rtrim(rtrim((string)$f['kur'], '0'), '.') : '',
        'evrak_no' => '', 'aciklama' => (string)($f['aciklama'] ?? ''), 'satirlar' => $sat, 'kopya_no' => (string)$f['fis_no']];
}

/** Aktif cariler (fiş formu için; cari tabloları yoksa []). @return list<array{id:int,kod:string,unvan:string,pb:string}> */
function stok_cari_secenekleri(PDO $pdo): array
{
    if (!cari_hazir($pdo)) return [];
    $o = [];
    foreach (cari_kart_listesi($pdo, ['aktif' => 1])['satirlar'] as $k) $o[] = ['id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'unvan' => (string)$k['unvan'], 'pb' => (string)($k['para_birimi'] ?? 'TRY')];
    return $o;
}

/** Kart × depo bakiye haritası (aktif depo filtreli). @return array<int,array<string,int>> kart_id → depo → milli */
function stok_bakiye_haritasi(PDO $pdo): array
{
    [$dw, $dp] = stok_depo_filtre('depo');
    $st = $pdo->prepare("SELECT kart_id, depo, SUM(miktar) AS m FROM stok_hareketler WHERE $dw GROUP BY kart_id, depo");
    $st->execute($dp);
    $o = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o[(int)$r['kart_id']][(string)$r['depo']] = stok_milli($r['m']);
    return $o;
}

/** Kartların güncel ortalama maliyeti (kuruş/birim; depo filtresiz). @return array<int,float> */
function stok_ortlar_toplu(PDO $pdo): array
{
    $o = [];
    foreach ($pdo->query('SELECT h.kart_id, h.ort_sonra FROM stok_hareketler h WHERE h.id IN (SELECT MAX(id) FROM stok_hareketler GROUP BY kart_id)')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o[(int)$r['kart_id']] = round((float)$r['ort_sonra'] * 100, 4);
    }
    return $o;
}

/** Kantar net kg (stok.php gruplanmamış formülü: net − kasa·dara − palet·dara, ≥0) → milli, KG TAM SAYI (yuvarlanır). */
function stok_kantar_net_milli(array $k): int
{
    $net = (float)($k['net_kg'] ?? 0) - (float)($k['kasa_sayisi'] ?? 0) * (float)($k['kasa_dara'] ?? 0) - (float)($k['palet_sayisi'] ?? 0) * (float)($k['palet_dara'] ?? 0);
    return max(0, (int)round($net)) * 1000;
}

/** Kantar satırı → form önerisi. @param array<string,int> $kartFold ad/kod fold → kart id  @param array<string,int> $cariFold unvan fold → cari id */
function stok_kantar_satir_oneri(array $r, array $kartFold, array $cariFold): array
{
    $ham = substr(trim((string)$r['giris_tarih']), 0, 10);
    $tarih = stok_tarih_coz($ham) ?? date('Y-m-d');
    $depo = stok_depo_secenek((string)$r['depo']) ?? (function_exists('active_depot') ? (string)(active_depot() ?? '') : '');
    $urun = trim((string)$r['malin_cinsi']); $firma = trim((string)$r['firma_adi']);
    return ['kantar_id' => (int)$r['id'], 'kantar_no' => (string)$r['fis_no'], 'tarih' => $tarih, 'tarih_ham' => (string)$r['giris_tarih'], 'depo' => $depo,
        'miktar_milli' => stok_kantar_net_milli($r), 'firma' => $firma, 'urun' => $urun,
        'kart_id' => $kartFold[cari_fold($urun)] ?? null, 'cari_id' => $cariFold[cari_fold($firma)] ?? null];
}

/** @return array{0:array<string,int>,1:array<string,int>} kart / cari eşleme haritaları (TR-duyarsız) */
function stok_kantar_haritalar(PDO $pdo): array
{
    $kf = [];
    foreach ($pdo->query('SELECT id, kod, ad FROM stok_kartlar WHERE aktif = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        $kf[cari_fold((string)$k['ad'])] ??= (int)$k['id'];
        $kf[cari_fold((string)$k['kod'])] ??= (int)$k['id'];
    }
    $cf = [];
    foreach (stok_cari_secenekleri($pdo) as $c) $cf[cari_fold($c['unvan'])] ??= $c['id'];
    return [$kf, $cf];
}

/**
 * "Kantardan doldur" adayları: SALT SELECT kantar_fisleri (aktif depo filtreli), en yeni önce. Zaten GEÇERLİ bir stok fişine aktarılmış olanlar
 * `aktarildi` = {id, fis_no} ile işaretlenir (seçilemez). Tablo yoksa []. @return list<array>
 */
function stok_kantar_adaylari(PDO $pdo, int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    try {
        [$dw, $dp] = stok_depo_filtre('depo');
        $st = $pdo->prepare("SELECT id, fis_no, giris_tarih, firma_adi, malin_cinsi, depo, net_kg, kasa_sayisi, kasa_dara, palet_sayisi, palet_dara FROM kantar_fisleri WHERE $dw ORDER BY id DESC LIMIT $limit");
        $st->execute($dp);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    if (!$rows) return [];
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $bag = [];
    $st = $pdo->prepare("SELECT kaynak_id, id, fis_no FROM stok_fisler WHERE kaynak_modul = 'kantar' AND durum = 'gecerli' AND kaynak_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) $bag[(int)$b['kaynak_id']] ??= ['id' => (int)$b['id'], 'fis_no' => (string)$b['fis_no']];
    [$kf, $cf] = stok_kantar_haritalar($pdo);
    $out = [];
    foreach ($rows as $r) $out[] = stok_kantar_satir_oneri($r, $kf, $cf) + ['aktarildi' => $bag[(int)$r['id']] ?? null];
    return $out;
}

/** Tek kantar fişi önerisi (görünür değilse null). */
function stok_kantar_oneri(PDO $pdo, int $id): ?array
{
    $r = stok_kantar_oku($pdo, $id);
    if ($r === null || !depot_visible_to_user((string)$r['depo'])) return null;
    [$kf, $cf] = stok_kantar_haritalar($pdo);
    return stok_kantar_satir_oneri($r, $kf, $cf) + ['aktarildi' => stok_kantar_bagli_fis($pdo, $id)];
}
