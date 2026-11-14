<?php
// =========================================================
// config/fatura_core.php — Fatura modülü çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 3 tablo: fatura_belgeler (8 belge türü tek başlık tablosunda, `tur`) · fatura_satirlar (INSERT-only) · fatura_tanimlar.
// Belge türleri: satis · satis_ihracat · satis_iade · alis · alis_mustahsil · alis_iade · irsaliye · siparis (fatura_turleri()).
// TASLAK YOK: kaydet = kesinleşir; düzeltme = iptal (neden 3–500) + "Kopyala". Ödeme durumu YOK (cari dekont + ekstre bağlantısı).
// Cari'ye YALNIZ cari_core fonksiyonlarıyla (cari_hareket_ekle(..., ['modul'=>'fatura','id'=>…]) · cari_kaynakli_iptal), stok'a YALNIZ
//   stok_core fonksiyonlarıyla (stok_fis_kaydet(..., $kaynak) cari_id BOŞ · stok_fis_iptal(..., 'fatura')). cari_* / stok_* tablolarına
//   bu dosyada HİÇ SQL yok (okuma dahil). Dekontu yalnız fatura yazar (çift cari satırı yok).
// Kalan miktar (dönüşüm) KOLON DEĞİL türetilmiş sorgu: iptal edilen belge sayaçtan kendiliğinden düşer.
// fatura_belgeler UPDATE beyaz listesi (gate smoke birebir denetler):
//   belge_no = ?, cari_hareket_id = ?, stok_fis_id = ?  ·  durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?
//   kapali = ?, kapali_at = ?, kapali_by = ?, kapali_neden = ?
// Para PHP'de kuruş TAM SAYI (DECIMAL(15,2)); miktar milli (DECIMAL(14,3)); fiyat on-binde (DECIMAL(15,4)); oran: KDV yüzde, diğerleri baz puan.
// Şema YALNIZ migrate.php "Fatura" kartından (fatura_migrate; ALTER/FK YOK, GO kuralı). Yazan fonksiyonlar yetki KONTROL ETMEZ
// (uç: fatura_kaydet.php → fatura_kapi); hepsi audit_log_event(…, 'fatura', …) yazar. PDO'da yalnız pozisyonel `?`.
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/stok_core.php';   // stok_* yardımcıları + (onun içinden) cari_core

const FATURA_IC_HATA = 4343;    // belge transaction'ını geri alan iç hata kodu (RuntimeException)
const FATURA_SATIR_MAX = 60;    // max_input_vars (varsayılan 1000) ≈ 60 satır × 15 alan (yönetici kararı #8)

// ── Şema / kapı ─────────────────────────────────────────────────────────────

/** Üç tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım (stok_tablolar kalıbı). @return array<string,string> */
function fatura_tablolar(): array
{
    return [
        'fatura_belgeler' => "CREATE TABLE IF NOT EXISTS `fatura_belgeler` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `tur`                VARCHAR(20)    NOT NULL,
  `belge_no`           VARCHAR(20)    NOT NULL DEFAULT '',
  `fatura_no`          VARCHAR(60)    NULL DEFAULT NULL,
  `seri_id`            INT            NULL DEFAULT NULL,
  `seri_yil`           SMALLINT       NULL DEFAULT NULL,
  `seri_sira`          INT            NULL DEFAULT NULL,
  `tarih`              DATE           NOT NULL,
  `vade_tarihi`        DATE           NULL DEFAULT NULL,
  `termin_tarihi`      DATE           NULL DEFAULT NULL,
  `depo`               VARCHAR(150)   NOT NULL,
  `cari_id`            INT            NOT NULL,
  `cari_kod`           VARCHAR(30)    NOT NULL DEFAULT '',
  `cari_unvan`         VARCHAR(200)   NOT NULL DEFAULT '',
  `cari_vergi_dairesi` VARCHAR(100)   NULL DEFAULT NULL,
  `cari_vergi_no`      VARCHAR(20)    NULL DEFAULT NULL,
  `cari_adres`         VARCHAR(500)   NULL DEFAULT NULL,
  `cari_il`            VARCHAR(60)    NULL DEFAULT NULL,
  `cari_ulke`          VARCHAR(60)    NULL DEFAULT NULL,
  `para_birimi`        VARCHAR(3)     NOT NULL DEFAULT 'TRY',
  `kur`                DECIMAL(15,6)  NULL DEFAULT NULL,
  `brut_toplam`        DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `iskonto_toplam`     DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `net_toplam`         DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `kdv_toplam`         DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `genel_toplam`       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `tevkifat_kod`       VARCHAR(20)    NULL DEFAULT NULL,
  `tevkifat_ad`        VARCHAR(150)   NULL DEFAULT NULL,
  `tevkifat_pay`       TINYINT        NULL DEFAULT NULL,
  `tevkifat_payda`     TINYINT        NULL DEFAULT NULL,
  `tevkifat_tutar`     DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `stopaj_ad`          VARCHAR(150)   NULL DEFAULT NULL,
  `stopaj_bp`          INT            NULL DEFAULT NULL,
  `stopaj_tutar`       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `bagkur_ad`          VARCHAR(150)   NULL DEFAULT NULL,
  `bagkur_bp`          INT            NULL DEFAULT NULL,
  `bagkur_tutar`       DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `cari_tutar`         DECIMAL(15,2)  NOT NULL DEFAULT 0,
  `stok_etkisi`        TINYINT(1)     NOT NULL DEFAULT 0,
  `stok_fis_id`        INT            NULL DEFAULT NULL,
  `cari_hareket_id`    INT            NULL DEFAULT NULL,
  `istisna_kod`        VARCHAR(20)    NULL DEFAULT NULL,
  `istisna_ad`         VARCHAR(200)   NULL DEFAULT NULL,
  `odeme_sekli`        VARCHAR(60)    NULL DEFAULT NULL,
  `teslim_sekli`       VARCHAR(10)    NULL DEFAULT NULL,
  `teslim_yeri`        VARCHAR(150)   NULL DEFAULT NULL,
  `brut_kg`            DECIMAL(14,3)  NULL DEFAULT NULL,
  `net_kg`             DECIMAL(14,3)  NULL DEFAULT NULL,
  `kap_adedi`          INT            NULL DEFAULT NULL,
  `beyanname_no`       VARCHAR(60)    NULL DEFAULT NULL,
  `plaka`              VARCHAR(60)    NULL DEFAULT NULL,
  `sofor_adi`          VARCHAR(100)   NULL DEFAULT NULL,
  `sevk_adresi`        VARCHAR(500)   NULL DEFAULT NULL,
  `aciklama`           VARCHAR(1000)  NULL DEFAULT NULL,
  `uyari`              VARCHAR(255)   NULL DEFAULT NULL,
  `kapali`             TINYINT(1)     NOT NULL DEFAULT 0,
  `kapali_at`          DATETIME       NULL DEFAULT NULL,
  `kapali_by`          INT            NULL DEFAULT NULL,
  `kapali_neden`       VARCHAR(500)   NULL DEFAULT NULL,
  `ettn`               VARCHAR(36)    NULL DEFAULT NULL,
  `gib_senaryo`        VARCHAR(20)    NULL DEFAULT NULL,
  `gib_durum`          VARCHAR(20)    NULL DEFAULT NULL,
  `istek_anahtari`     VARCHAR(40)    NULL DEFAULT NULL,
  `durum`              VARCHAR(10)    NOT NULL DEFAULT 'gecerli',
  `iptal_at`           DATETIME       NULL DEFAULT NULL,
  `iptal_by`           INT            NULL DEFAULT NULL,
  `iptal_neden`        VARCHAR(500)   NULL DEFAULT NULL,
  `created_at`         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`         INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_ftb_istek` (`istek_anahtari`),
  UNIQUE KEY `uq_ftb_seri` (`seri_id`, `seri_yil`, `seri_sira`),
  KEY `idx_ftb_tur_tarih` (`tur`, `tarih`),
  KEY `idx_ftb_cari` (`cari_id`, `tur`),
  KEY `idx_ftb_depo` (`depo`),
  KEY `idx_ftb_durum` (`durum`),
  KEY `idx_ftb_belge_no` (`belge_no`),
  KEY `idx_ftb_fatura_no` (`fatura_no`),
  KEY `idx_ftb_stok` (`stok_fis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'fatura_satirlar' => "CREATE TABLE IF NOT EXISTS `fatura_satirlar` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `belge_id`        INT            NOT NULL,
  `sira`            INT            NOT NULL DEFAULT 0,
  `kart_id`         INT            NULL DEFAULT NULL,
  `kart_kod`        VARCHAR(30)    NULL DEFAULT NULL,
  `urun_adi`        VARCHAR(200)   NOT NULL,
  `birim`           VARCHAR(10)    NOT NULL,
  `miktar`          DECIMAL(14,3)  NOT NULL,
  `birim_fiyat`     DECIMAL(15,4)  NULL DEFAULT NULL,
  `iskonto_bp`      INT            NOT NULL DEFAULT 0,
  `brut_tutar`      DECIMAL(15,2)  NULL DEFAULT NULL,
  `iskonto_tutar`   DECIMAL(15,2)  NULL DEFAULT NULL,
  `tutar`           DECIMAL(15,2)  NULL DEFAULT NULL,
  `kdv_orani`       TINYINT        NULL DEFAULT NULL,
  `kdv_tutar`       DECIMAL(15,2)  NULL DEFAULT NULL,
  `gtip`            VARCHAR(12)    NULL DEFAULT NULL,
  `brut_kg`         DECIMAL(14,3)  NULL DEFAULT NULL,
  `net_kg`          DECIMAL(14,3)  NULL DEFAULT NULL,
  `kap_adedi`       INT            NULL DEFAULT NULL,
  `lot`             VARCHAR(60)    NULL DEFAULT NULL,
  `aciklama`        VARCHAR(200)   NULL DEFAULT NULL,
  `kaynak_satir_id` INT            NULL DEFAULT NULL,
  `stoklu`          TINYINT(1)     NOT NULL DEFAULT 0,
  KEY `idx_fts_belge` (`belge_id`),
  KEY `idx_fts_kart` (`kart_id`),
  KEY `idx_fts_kaynak` (`kaynak_satir_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'fatura_tanimlar' => "CREATE TABLE IF NOT EXISTS `fatura_tanimlar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `tur`         VARCHAR(20)    NOT NULL,
  `ad`          VARCHAR(150)   NOT NULL,
  `kod`         VARCHAR(20)    NULL DEFAULT NULL,
  `deger1`      INT            NULL DEFAULT NULL,
  `deger2`      INT            NULL DEFAULT NULL,
  `metin`       VARCHAR(500)   NULL DEFAULT NULL,
  `belge_turu`  VARCHAR(20)    NULL DEFAULT NULL,
  `varsayilan`  TINYINT(1)     NOT NULL DEFAULT 0,
  `sira`        INT            NOT NULL DEFAULT 0,
  `aktif`       TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`  INT            NULL DEFAULT NULL,
  `updated_at`  DATETIME       NULL DEFAULT NULL,
  `updated_by`  INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_ftt_tur_ad` (`tur`, `ad`),
  UNIQUE KEY `uq_ftt_tur_kod` (`tur`, `kod`),
  KEY `idx_ftt_tur` (`tur`, `aktif`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Üç tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function fatura_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(fatura_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Tohum (yalnız fatura_tanimlar YENİ kurulduysa, bir kez). Oranlar/kodlar mali müşavirle doğrulanmalı (UI notu).
 * @return list<array{tur:string,ad:string,kod:?string,deger1:?int,deger2:?int,metin:?string,belge_turu:?string,varsayilan:int}>
 */
function fatura_tanim_tohumu(): array
{
    $t = static fn(string $tur, string $ad, ?string $kod = null, ?int $d1 = null, ?int $d2 = null, ?string $metin = null, ?string $bt = null, int $vs = 0): array
        => ['tur' => $tur, 'ad' => $ad, 'kod' => $kod, 'deger1' => $d1, 'deger2' => $d2, 'metin' => $metin, 'belge_turu' => $bt, 'varsayilan' => $vs];
    $o = [
        $t('kdv', 'KDV %0', null, 0), $t('kdv', 'KDV %1', null, 1, null, null, null, 1), $t('kdv', 'KDV %10', null, 10), $t('kdv', 'KDV %20', null, 20),
        $t('tevkifat', 'Tevkifat 2/10', null, 2, 10), $t('tevkifat', 'Tevkifat 5/10', null, 5, 10), $t('tevkifat', 'Tevkifat 7/10', null, 7, 10), $t('tevkifat', 'Tevkifat 9/10', null, 9, 10),
        $t('stopaj', 'Müstahsil GV stopajı %2', null, 200, null, null, null, 1),
        $t('bagkur', 'Bağ-Kur kesintisi %1', null, 100, null, null, null, 1),
        $t('istisna', '301 — KDV 11/1-a Mal ihracatı', '301', null, null, null, null, 1),
        $t('seri', 'FTR — Fatura serisi', 'FTR', null, null, null, 'fatura', 1),
        $t('seri', 'IRS — İrsaliye serisi', 'IRS', null, null, null, 'irsaliye', 1),
        $t('seri', 'MMK — Müstahsil makbuzu serisi', 'MMK', null, null, null, 'mustahsil', 1),
    ];
    foreach (['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'] as $inc) $o[] = $t('teslim', $inc, $inc);
    $o[] = $t('sablon', 'İhracat KDV istisnası notu', null, null, null, "KDV Kanunu 11/1-a maddesi gereğince KDV'den istisnadır.", 'satis_ihracat');
    return $o;
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + tanım tohumu (fatura_tanimlar YENİ kurulduysa).
 * Yalnız migrate.php kartından çağrılır. TEST KANCASI: $GLOBALS['_fatura_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function fatura_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_fatura_ddl_calistir'] ?? null;   // YALNIZ TEST KANCASI
    foreach (fatura_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'fatura_tanimlar' && (int)$pdo->query('SELECT COUNT(*) FROM `fatura_tanimlar`')->fetchColumn() === 0) {
                $ins = $pdo->prepare('INSERT INTO `fatura_tanimlar` (tur, ad, kod, deger1, deger2, metin, belge_turu, varsayilan, sira, aktif, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
                $sira = [];
                foreach (fatura_tanim_tohumu() as $r) {
                    $sira[$r['tur']] = ($sira[$r['tur']] ?? 0) + 10;
                    $ins->execute([$r['tur'], $r['ad'], $r['kod'], $r['deger1'], $r['deger2'], $r['metin'], $r['belge_turu'], $r['varsayilan'], $sira[$r['tur']], date('Y-m-d H:i:s')]);
                }
            }
        } catch (PDOException $e) {
            error_log('[fatura_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** JSON (fetch) isteği mi? (cari ile aynı sözleşme) */
function fatura_json_mi(): bool { return cari_json_mi(); }

/** Eksik şemalar (Fatura, Cari ya da Stok). @return list<array{ad:string,kart:string}> */
function fatura_eksik_semalar(PDO $pdo): array
{
    $e = [];
    if (!cari_hazir($pdo)) $e[] = ['ad' => 'Cari İşlemler', 'kart' => 'cari'];
    if (!stok_hazir($pdo)) $e[] = ['ad' => 'Stok (muhasebe)', 'kart' => 'stok'];
    if (!fatura_hazir($pdo)) $e[] = ['ad' => 'Fatura', 'kart' => 'fatura'];
    return $e;
}

/** Fatura + Cari + Stok tabloları yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function fatura_sayfa_kapisi(PDO $pdo): void
{
    $eksik = fatura_eksik_semalar($pdo);
    if (!$eksik) return;
    $adlar = implode(', ', array_column($eksik, 'ad'));
    if (fatura_json_mi()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Fatura için gerekli tablolar kurulmamış: ' . $adlar . '. Yönetici: Şema Migrasyon.', 'eksikler' => array_column($eksik, 'kart')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render_header('Fatura');
    if (function_exists('fatura_assets')) fatura_assets();
    if (function_exists('fatura_sema_uyari')) {
        echo fatura_sema_uyari($eksik);
    } else {
        $lnk = '';
        foreach ($eksik as $e) $lnk .= ' <a class="btn btn-primary" href="migrate.php#' . h($e['kart']) . '">Şema Migrasyon › ' . h($e['ad']) . '</a>';
        echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
            . '<h2 style="margin-top:0;">Fatura</h2>'
            . '<p>Fatura için gerekli tablolar kurulmamış: ' . h($adlar) . '. Yönetici: Şema Migrasyon kartlarını (önce Cari ve Stok) çalıştırın.</p>'
            . '<p>' . $lnk . '</p>'
            . '</div></div>';
    }
    render_footer();
    exit;
}

/** Faz 1: hepsi is_admin(). TEK değişim noktası. $eylem: read|write|delete|admin */
function fatura_can(string $eylem): bool
{
    return in_array($eylem, ['read', 'write', 'delete', 'admin'], true) && is_admin();
}

function fatura_kapi(string $eylem): void
{
    if (fatura_can($eylem)) return;
    if (fatura_json_mi()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forbidden('Bu işlem için yetkiniz yok.');
}

function fatura_no_store(): void { cari_no_store(); }

// ── Sabitler / meta ─────────────────────────────────────────────────────────

/**
 * Belge türleri. sinif = sayfa ailesi; duzenleyen biz (seriden 16 hane resmî no) | karsi (fatura_no elle);
 * cari: dekont türü (null = cari etkisi yok); stok: giris|cikis|null; stok_neden: stok nedeni sistem kodu (yoksa 'diger').
 * @return array<string,array<string,mixed>>
 */
function fatura_turleri(): array
{
    $b = ['duzenleyen' => 'biz', 'seri_grubu' => 'fatura', 'cari' => null, 'stok' => null, 'stok_neden' => null, 'stok_zorunlu' => false,
        'fiyat_zorunlu' => true, 'kdv_sifir' => false, 'ihracat' => false, 'mustahsil' => false, 'tevkifat_izin' => false, 'iade' => false];
    $sat = ['sinif' => 'satis', 'sayfa' => 'satis_faturalari.php'];
    $al = ['sinif' => 'alis', 'sayfa' => 'alis_faturalari.php'];
    return [
        'satis'          => ['etiket' => 'Satış Faturası', 'onek' => 'STF', 'cari' => 'borc_dekont', 'stok' => 'cikis', 'stok_neden' => 'satis', 'tevkifat_izin' => true] + $sat + $b,
        'satis_ihracat'  => ['etiket' => 'İhracat Faturası', 'onek' => 'IHF', 'cari' => 'borc_dekont', 'stok' => 'cikis', 'stok_neden' => 'satis', 'kdv_sifir' => true, 'ihracat' => true] + $sat + $b,
        'satis_iade'     => ['etiket' => 'Satış İade Faturası', 'onek' => 'SIF', 'duzenleyen' => 'karsi', 'seri_grubu' => null, 'cari' => 'alacak_dekont', 'stok' => 'giris', 'stok_neden' => 'diger', 'iade' => true] + $sat + $b,
        'alis'           => ['etiket' => 'Alış Faturası', 'onek' => 'ALF', 'duzenleyen' => 'karsi', 'seri_grubu' => null, 'cari' => 'alacak_dekont', 'stok' => 'giris', 'stok_neden' => 'alis', 'tevkifat_izin' => true] + $al + $b,
        'alis_mustahsil' => ['etiket' => 'Müstahsil Makbuzu', 'onek' => 'MMK', 'seri_grubu' => 'mustahsil', 'cari' => 'alacak_dekont', 'stok' => 'giris', 'stok_neden' => 'alis', 'kdv_sifir' => true, 'mustahsil' => true] + $al + $b,
        'alis_iade'      => ['etiket' => 'Alış İade Faturası', 'onek' => 'AIF', 'cari' => 'borc_dekont', 'stok' => 'cikis', 'stok_neden' => 'diger', 'tevkifat_izin' => true, 'iade' => true] + $al + $b,
        'irsaliye'       => ['etiket' => 'Sevk İrsaliyesi', 'onek' => 'IRS', 'sinif' => 'irsaliye', 'sayfa' => 'sevk_irsaliyeleri.php', 'seri_grubu' => 'irsaliye', 'stok' => 'cikis', 'stok_neden' => 'satis', 'stok_zorunlu' => true, 'fiyat_zorunlu' => false] + $b,
        'siparis'        => ['etiket' => 'Alınan Sipariş Fişi', 'onek' => 'ASF', 'sinif' => 'siparis', 'sayfa' => 'alinan_siparis_fisleri.php', 'duzenleyen' => 'karsi', 'seri_grubu' => null, 'fiyat_zorunlu' => false] + $b,
    ];
}

function fatura_tur_bilgi(string $tur): ?array { return fatura_turleri()[$tur] ?? null; }

/** @return list<string> sınıftaki türler (fatura_turleri sırasıyla) */
function fatura_sinif_turleri(string $sinif): array
{
    return array_keys(array_filter(fatura_turleri(), static fn(array $b): bool => $b['sinif'] === $sinif));
}

/** @return array<string,string> sınıf → sayfa */
function fatura_sinif_sayfalari(): array
{
    return ['satis' => 'satis_faturalari.php', 'alis' => 'alis_faturalari.php', 'irsaliye' => 'sevk_irsaliyeleri.php', 'siparis' => 'alinan_siparis_fisleri.php'];
}

/** Seri grupları (fatura_tanimlar tur=seri belge_turu). @return array<string,string> */
function fatura_seri_gruplari(): array { return ['fatura' => 'Fatura', 'irsaliye' => 'İrsaliye', 'mustahsil' => 'Müstahsil Makbuzu']; }

/**
 * İzinli dönüşümler: hedef tür → kaynak türleri. Kalan(kaynak satırı) = miktar − Σ geçerli hedef satırları (tek sayaç;
 * siparişte irsaliye + fatura birlikte sayılır). @return array<string,list<string>>
 */
function fatura_gecisler(): array
{
    return [
        'irsaliye'      => ['siparis'],
        'satis'         => ['siparis', 'irsaliye'],
        'satis_ihracat' => ['siparis', 'irsaliye'],
        'satis_iade'    => ['satis', 'satis_ihracat'],
        'alis_iade'     => ['alis', 'alis_mustahsil'],
    ];
}

/** STF-2026-000123 */
function fatura_belge_no(string $tur, string $tarih, int $id): string
{
    $onek = fatura_tur_bilgi($tur)['onek'] ?? 'FTB';
    return $onek . '-' . substr($tarih, 0, 4) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** Resmî seri numarası: 3 harf + yıl + 9 hane = 16 karakter ("FTR2026000000012"). */
function fatura_seri_no(string $onek, int $yil, int $sira): string
{
    return $onek . str_pad((string)$yil, 4, '0', STR_PAD_LEFT) . str_pad((string)$sira, 9, '0', STR_PAD_LEFT);
}

/** Yüzde metni → baz puan (%×100). "2,5" → 250 · "%10" → 1000 · "0" → 0. 0 ≤ bp < 10000, en çok 2 ondalık; boş/geçersiz → null. */
function fatura_bp_coz(mixed $ham): ?int
{
    if (is_int($ham)) $ham = (string)$ham;
    if (!is_string($ham)) return null;
    $s = preg_replace('/[\s\x{00A0}%]+/u', '', trim($ham)) ?? '';
    if ($s === '' || !preg_match('/^(\d{1,2})(?:[.,](\d{1,2}))?$/', $s, $m)) return null;
    $bp = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    return $bp < 10000 ? $bp : null;
}

/** 250 → "2,5" · 200 → "2" · 1234 → "12,34" */
function fatura_bp_bicim(int $bp): string
{
    $k = rtrim(str_pad((string)($bp % 100), 2, '0', STR_PAD_LEFT), '0');
    return intdiv($bp, 100) . ($k !== '' ? ',' . $k : '');
}

/** Tanım türleri → etiket + formda kullanılan alanlar. @return array<string,array{etiket:string,alanlar:list<string>}> */
function fatura_tanim_turleri(): array
{
    return [
        'kdv'      => ['etiket' => 'KDV Oranları',        'alanlar' => ['ad', 'deger1']],
        'tevkifat' => ['etiket' => 'Tevkifat',            'alanlar' => ['ad', 'kod', 'deger1', 'deger2']],
        'stopaj'   => ['etiket' => 'Stopaj',              'alanlar' => ['ad', 'deger1']],
        'bagkur'   => ['etiket' => 'Bağ-Kur',             'alanlar' => ['ad', 'deger1']],
        'istisna'  => ['etiket' => 'KDV İstisnaları',     'alanlar' => ['ad', 'kod']],
        'seri'     => ['etiket' => 'Belge Serileri',      'alanlar' => ['ad', 'kod', 'belge_turu']],
        'teslim'   => ['etiket' => 'Teslim Şekilleri',    'alanlar' => ['ad', 'kod']],
        'sablon'   => ['etiket' => 'Açıklama Şablonları', 'alanlar' => ['ad', 'metin', 'belge_turu']],
    ];
}

/** `geri` yalnız yerel fatura sayfası (açık yönlendirme yok). */
function fatura_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^(alis_faturalari|satis_faturalari|sevk_irsaliyeleri|alinan_siparis_fisleri|fatura_tanimlari)\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'satis_faturalari.php';
}

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function fatura_tx(PDO $pdo, callable $fn): mixed { return cari_tx($pdo, $fn); }

function fatura_hata(string $msg, array $ek = []): array { return cari_hata($msg, $ek); }

/** Sonucu JSON ya da PRG olarak gönderir. 409: yetersiz_stok · mukerrer · kalan_asimi · bagli_belge · kullanimda · kaynakli; kod sunucu_hatasi → 500; diğer hata 422. */
function fatura_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && ($s['kod'] ?? '') === 'sunucu_hatasi') http_response_code(500);
        elseif (!$ok && in_array((string)($s['kod'] ?? ''), ['yetersiz_stok', 'mukerrer', 'kalan_asimi', 'bagli_belge', 'kullanimda', 'kaynakli'], true)) http_response_code(409);
        elseif (!$ok) http_response_code(422);
        echo json_encode([
            'ok' => $ok, 'id' => $s['id'] ?? null, 'belge_no' => $s['belge_no'] ?? null, 'fatura_no' => $s['fatura_no'] ?? null,
            'hata' => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'), 'alanlar' => (object)($s['alanlar'] ?? []),
            'satir_hatalari' => (object)($s['satir_hatalari'] ?? []), 'uyari' => $s['uyari'] ?? null, 'yonlendir' => $geri, 'tekrar' => !empty($s['tekrar']),
            'kod' => $s['kod'] ?? null, 'eksikler' => $s['eksikler'] ?? null, 'stok_fis_no' => $s['stok_fis_no'] ?? null, 'cari_belge_no' => $s['cari_belge_no'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok
        ? $okMesaj . (!empty($s['belge_no']) ? ' · ' . $s['belge_no'] : '') . (!empty($s['fatura_no']) ? ' · ' . $s['fatura_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '')
        : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    if (!$ok && !empty($s['eksikler']) && is_array($s['eksikler'])) {   // klasik form: eksik listesi flash'a
        $msg .= ' ' . implode('; ', array_map(static fn(array $e): string => $e['kod'] . ' (' . ($e['depo'] !== '' ? $e['depo'] : '—') . '): mevcut ' . stok_miktar_bicim((int)$e['mevcut_milli'], (string)$e['birim']) . ', istenen ' . stok_miktar_bicim((int)$e['istenen_milli'], (string)$e['birim']) . ' ' . $e['birim'], $s['eksikler']));
    }
    set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

// ── Hesap (SAF: DB yok, yan etki yok; JS yalnız önizleme) ───────────────────

/**
 * Satır: brut = round(milli·f4/100000) · isk = round(brut·bp/10000) · net = brut − isk · kdv = round(net·oran/100).
 * Fiyatsız satırda hepsi null (kdv oranı null ise kdv tutarı null). Bütün tutarlar POZİTİF (iade yönle ayrılır).
 * @return array{brut:?int,isk:?int,net:?int,kdv:?int}
 */
function fatura_satir_hesapla(int $milli, ?int $f4, int $bp, ?int $kdv): array
{
    if ($f4 === null) return ['brut' => null, 'isk' => null, 'net' => null, 'kdv' => null];
    $brut = (int)round($milli * $f4 / 100000);
    $isk = (int)round($brut * $bp / 10000);
    $net = $brut - $isk;
    return ['brut' => $brut, 'isk' => $isk, 'net' => $net, 'kdv' => $kdv === null ? null : (int)round($net * $kdv / 100)];
}

/**
 * Başlık toplamları. $satirlar: [{brut,isk,net,kdv,kdv_orani}] (fiyatsız satır null alanlarla sayılmaz).
 * $kesinti: {tev_pay, tev_payda, stopaj_bp, bagkur_bp} (yoksa null/0). tevkifat = round(kdv·pay/payda); stopaj/bağkur = round(net·bp/10000).
 * cari = genel − tevkifat − stopaj − bagkur. kdv_gruplari: oran → {net, kdv} (yalnız gösterim).
 * @return array{brut:int,isk:int,net:int,kdv:int,genel:int,tevkifat:int,stopaj:int,bagkur:int,cari:int,kdv_gruplari:array<int,array{net:int,kdv:int}>}
 */
function fatura_toplam_hesapla(array $satirlar, array $kesinti): array
{
    $t = ['brut' => 0, 'isk' => 0, 'net' => 0, 'kdv' => 0];
    $gr = [];
    foreach ($satirlar as $s) {
        if (!isset($s['net']) || $s['net'] === null) continue;
        $t['brut'] += (int)$s['brut']; $t['isk'] += (int)$s['isk']; $t['net'] += (int)$s['net']; $t['kdv'] += (int)($s['kdv'] ?? 0);
        $o = (int)($s['kdv_orani'] ?? 0);
        $gr[$o] ??= ['net' => 0, 'kdv' => 0];
        $gr[$o]['net'] += (int)$s['net']; $gr[$o]['kdv'] += (int)($s['kdv'] ?? 0);
    }
    ksort($gr);
    $pay = (int)($kesinti['tev_pay'] ?? 0); $payda = (int)($kesinti['tev_payda'] ?? 0);
    $tev = ($pay > 0 && $payda > 0) ? (int)round($t['kdv'] * $pay / $payda) : 0;
    $sto = (int)round($t['net'] * (int)($kesinti['stopaj_bp'] ?? 0) / 10000);
    $bag = (int)round($t['net'] * (int)($kesinti['bagkur_bp'] ?? 0) / 10000);
    $genel = $t['net'] + $t['kdv'];
    return $t + ['genel' => $genel, 'tevkifat' => $tev, 'stopaj' => $sto, 'bagkur' => $bag, 'cari' => $genel - $tev - $sto - $bag, 'kdv_gruplari' => $gr];
}

/**
 * Stok fişine gidecek birim fiyat (on-binde, belge para biriminde): iskontosuz satırda f4 AYNEN; iskontoluda net birim fiyat
 * f4n = round(net·100000/milli) (stok değeri = tutar×kur; sapma ≤ milli/200000 kuruş). Sonuç ≥ 1.
 */
function fatura_stok_fiyat4(int $net, int $milli, int $bp, int $f4): int
{
    if ($bp === 0 || $milli <= 0) return $f4;
    return max(1, (int)round($net * 100000 / $milli));
}

// ── Tanımlar ────────────────────────────────────────────────────────────────

/** Kullanım sayısıyla (seri: belge sayısı; diğerleri belgede snapshot taşır → 0). @return list<array> */
function fatura_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array
{
    if (!isset(fatura_tanim_turleri()[$tur])) return [];
    $kul = $tur === 'seri' ? '(SELECT COUNT(*) FROM fatura_belgeler b WHERE b.seri_id = t.id)' : '0';
    $st = $pdo->prepare("SELECT t.*, $kul AS kullanim FROM fatura_tanimlar t WHERE t.tur = ?" . ($yalnizAktif ? ' AND t.aktif = 1' : '') . ' ORDER BY t.sira, t.ad, t.id');
    $st->execute([$tur]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fatura_tanim_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM fatura_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Seri kullanımda mı (belgesi var mı)? */
function fatura_seri_kullanim(PDO $pdo, int $seriId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM fatura_belgeler WHERE seri_id = ?');
    $st->execute([$seriId]);
    return (int)$st->fetchColumn();
}

/**
 * Tanım doğrulaması (yazmaz). g: tur, ad, kod, deger1, deger2, metin, belge_turu, varsayilan, sira.
 * kdv: deger1 yüzde 0–100 (tam sayı) · tevkifat: deger1 pay 1–9, deger2 payda 10 (boş → 10), kod opsiyonel ·
 * stopaj/bagkur: deger1 yüzde metni ("2" / "2,5") → baz puan · istisna: kod zorunlu · seri: kod ^[A-Z]{3}$ + belge_turu (fatura|irsaliye|mustahsil) ·
 * teslim: kod ^[A-Z]{3}$ · sablon: metin zorunlu ≤500, belge_turu tür kodu ya da boş (hepsi). Ad tür içinde TR-duyarsız tekil; kod tür içinde tekil.
 * @return array{ok:bool,veri?:array,hata?:string,alanlar?:array<string,string>,kod?:string}
 */
function fatura_tanim_dogrula(PDO $pdo, array $g, ?int $id): array
{
    $h = []; $v = [];
    $eski = null;
    if ($id !== null) {
        $eski = fatura_tanim_bul($pdo, $id);
        if ($eski === null) return ['ok' => false, 'hata' => 'Tanım bulunamadı.', 'alanlar' => []];
    }
    $tur = $eski !== null ? (string)$eski['tur'] : (string)($g['tur'] ?? '');
    if (!isset(fatura_tanim_turleri()[$tur])) return ['ok' => false, 'hata' => 'Geçersiz tanım türü.', 'alanlar' => ['tur' => 'Geçersiz tanım türü.']];
    $v['tur'] = $tur;
    $ad = trim((string)($g['ad'] ?? ''));
    if ($ad === '') $h['ad'] = 'Ad zorunlu.';
    elseif (mb_strlen($ad) > 150) $h['ad'] = 'Ad en çok 150 karakter.';
    $v['ad'] = $ad;
    $mevcut = fatura_tanim_listesi($pdo, $tur);
    if (!isset($h['ad'])) foreach ($mevcut as $m) if ((int)$m['id'] !== (int)$id && cari_fold((string)$m['ad']) === cari_fold($ad)) { $h['ad'] = 'Bu ad bu türde zaten var.'; break; }

    $kodHam = trim((string)($g['kod'] ?? ''));
    $v['kod'] = null; $v['deger1'] = null; $v['deger2'] = null; $v['metin'] = null; $v['belge_turu'] = null;
    $d1 = trim((string)($g['deger1'] ?? '')); $d2 = trim((string)($g['deger2'] ?? ''));
    switch ($tur) {
        case 'kdv':
            if (!preg_match('/^\d{1,3}$/', $d1) || (int)$d1 > 100) $h['deger1'] = 'KDV oranı 0–100 arası tam sayı olmalı.';
            else {
                $v['deger1'] = (int)$d1;
                foreach ($mevcut as $m) if ((int)$m['id'] !== (int)$id && (int)$m['deger1'] === $v['deger1']) { $h['deger1'] = 'Bu KDV oranı zaten tanımlı.'; break; }
            }
            break;
        case 'tevkifat':
            if (!preg_match('/^[1-9]$/', $d1)) $h['deger1'] = 'Tevkifat payı 1–9 olmalı.'; else $v['deger1'] = (int)$d1;
            if ($d2 === '') $d2 = '10';
            if ($d2 !== '10') $h['deger2'] = 'Tevkifat paydası 10 olmalı.'; else $v['deger2'] = 10;
            if ($kodHam !== '') { if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $kodHam)) $h['kod'] = 'Kod en çok 20 karakter (harf, rakam, . _ -).'; else $v['kod'] = $kodHam; }
            break;
        case 'stopaj':
        case 'bagkur':
            $bp = fatura_bp_coz($d1);
            if ($bp === null) $h['deger1'] = 'Oran %0–99,99 olmalı (en çok 2 ondalık).'; else $v['deger1'] = $bp;
            break;
        case 'istisna':
            if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $kodHam)) $h['kod'] = 'İstisna kodu zorunlu (en çok 20 karakter).'; else $v['kod'] = $kodHam;
            break;
        case 'seri':
        case 'teslim':
            $kd = strtoupper($kodHam);
            if (!preg_match('/^[A-Z]{3}$/', $kd)) $h['kod'] = 'Kod 3 büyük harf olmalı (ör. ' . ($tur === 'seri' ? 'FTR' : 'FOB') . ').'; else $v['kod'] = $kd;
            if ($tur === 'seri') {
                $bt = (string)($g['belge_turu'] ?? '');
                if (!isset(fatura_seri_gruplari()[$bt])) $h['belge_turu'] = 'Seri grubu seçin (fatura / irsaliye / müstahsil).'; else $v['belge_turu'] = $bt;
            }
            break;
        case 'sablon':
            $mt = trim((string)($g['metin'] ?? ''));
            if ($mt === '') $h['metin'] = 'Şablon metni zorunlu.'; elseif (mb_strlen($mt) > 500) $h['metin'] = 'Şablon metni en çok 500 karakter.'; else $v['metin'] = $mt;
            $bt = trim((string)($g['belge_turu'] ?? ''));
            if ($bt !== '' && !isset(fatura_turleri()[$bt])) $h['belge_turu'] = 'Geçersiz belge türü.'; else $v['belge_turu'] = $bt === '' ? null : $bt;
            break;
    }
    if ($v['kod'] !== null && !isset($h['kod'])) foreach ($mevcut as $m) if ((int)$m['id'] !== (int)$id && $m['kod'] !== null && strtoupper((string)$m['kod']) === strtoupper($v['kod'])) { $h['kod'] = 'Bu kod bu türde zaten var.'; break; }
    $v['varsayilan'] = !empty($g['varsayilan']) && (string)$g['varsayilan'] !== '0' ? 1 : 0;
    $sira = trim((string)($g['sira'] ?? ''));
    $v['sira'] = $sira === '' ? ($eski !== null ? (int)$eski['sira'] : (count($mevcut) + 1) * 10) : (int)$sira;
    if ($h) return ['ok' => false, 'hata' => (string)reset($h), 'alanlar' => $h];
    if ($eski !== null && $tur === 'seri' && ($v['kod'] !== (string)$eski['kod'] || $v['belge_turu'] !== (string)$eski['belge_turu']) && fatura_seri_kullanim($pdo, (int)$id) > 0) {
        return ['ok' => false, 'hata' => 'Kullanılan serinin kodu / grubu değiştirilemez; yeni seri tanımlayın.', 'alanlar' => ['kod' => 'Seri kullanımda.'], 'kod' => 'kullanimda'];
    }
    return ['ok' => true, 'veri' => $v];
}

/** Tanım ekle / güncelle. varsayilan=1 → aynı türün (seride aynı grubun) diğer varsayılanı kalkar. */
function fatura_tanim_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array
{
    $d = fatura_tanim_dogrula($pdo, $g, $id);
    if (!$d['ok']) return fatura_hata((string)$d['hata'], ['alanlar' => $d['alanlar'] ?? []] + (isset($d['kod']) ? ['kod' => $d['kod']] : []));
    $v = $d['veri'];
    $eski = $id !== null ? fatura_tanim_bul($pdo, $id) : null;
    $simdi = date('Y-m-d H:i:s');
    try {
        $yid = (int)fatura_tx($pdo, function () use ($pdo, $v, $uid, $id, $simdi): int {
            if ($id === null) {
                $pdo->prepare('INSERT INTO fatura_tanimlar (tur, ad, kod, deger1, deger2, metin, belge_turu, varsayilan, sira, aktif, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                    ->execute([$v['tur'], $v['ad'], $v['kod'], $v['deger1'], $v['deger2'], $v['metin'], $v['belge_turu'], $v['varsayilan'], $v['sira'], $simdi, $uid]);
                $yid = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE fatura_tanimlar SET ad = ?, kod = ?, deger1 = ?, deger2 = ?, metin = ?, belge_turu = ?, varsayilan = ?, sira = ?, updated_at = ?, updated_by = ? WHERE id = ?')
                    ->execute([$v['ad'], $v['kod'], $v['deger1'], $v['deger2'], $v['metin'], $v['belge_turu'], $v['varsayilan'], $v['sira'], $simdi, $uid, $id]);
                $yid = $id;
            }
            if ($v['varsayilan'] === 1) {
                if ($v['tur'] === 'seri') $pdo->prepare('UPDATE fatura_tanimlar SET varsayilan = 0 WHERE tur = ? AND belge_turu = ? AND id <> ?')->execute([$v['tur'], $v['belge_turu'], $yid]);
                else $pdo->prepare('UPDATE fatura_tanimlar SET varsayilan = 0 WHERE tur = ? AND id <> ?')->execute([$v['tur'], $yid]);
            }
            return $yid;
        });
    } catch (PDOException $e) {
        error_log('[fatura_tanim_kaydet] ' . $e->getMessage());
        return fatura_hata('Tanım kaydedilemedi (ad ya da kod çakışması olabilir).');
    }
    $iz = static fn(?array $x): ?array => $x === null ? null : ['tur' => $x['tur'], 'ad' => $x['ad'], 'kod' => $x['kod'], 'deger1' => $x['deger1'] !== null ? (int)$x['deger1'] : null, 'deger2' => $x['deger2'] !== null ? (int)$x['deger2'] : null];
    audit_log_event($id === null ? 'create' : 'update', 'fatura', $yid, $iz($eski), $iz($v) + ['kayit' => 'tanim', 'varsayilan' => $v['varsayilan']]);
    return ['ok' => true, 'hata' => null, 'id' => $yid];
}

function fatura_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $t = fatura_tanim_bul($pdo, $id);
    if ($t === null) return fatura_hata('Tanım bulunamadı.');
    $pdo->prepare('UPDATE fatura_tanimlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    audit_log_event('update', 'fatura', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad'], 'aktif' => (int)$t['aktif']], ['kayit' => 'tanim', 'aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Silme: kullanılan seri silinemez (409 kullanimda; pasifleştirin). Diğerleri serbest (belgeler snapshot taşır). */
function fatura_tanim_sil(PDO $pdo, int $id, int $uid): array
{
    $t = fatura_tanim_bul($pdo, $id);
    if ($t === null) return fatura_hata('Tanım bulunamadı.');
    if ($t['tur'] === 'seri' && fatura_seri_kullanim($pdo, $id) > 0) return fatura_hata('Kullanılan seri silinemez; pasifleştirin.', ['kod' => 'kullanimda']);
    $pdo->prepare('DELETE FROM fatura_tanimlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'fatura', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad'], 'kod' => $t['kod'], 'deger1' => $t['deger1'] !== null ? (int)$t['deger1'] : null, 'deger2' => $t['deger2'] !== null ? (int)$t['deger2'] : null], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Form JSON'u için tüm AKTİF tanımlar (tür → liste) + cari ödeme şekilleri (tek kaynak: cari_tanimlar). */
function fatura_tanimlar_paket(PDO $pdo): array
{
    $o = [];
    foreach (array_keys(fatura_tanim_turleri()) as $tur) {
        $o[$tur] = array_map(static fn(array $t): array => ['id' => (int)$t['id'], 'ad' => (string)$t['ad'], 'kod' => $t['kod'], 'deger1' => $t['deger1'] !== null ? (int)$t['deger1'] : null,
            'deger2' => $t['deger2'] !== null ? (int)$t['deger2'] : null, 'metin' => $t['metin'], 'belge_turu' => $t['belge_turu'], 'varsayilan' => (int)$t['varsayilan'] === 1], fatura_tanim_listesi($pdo, $tur, true));
    }
    $o['odeme_sekli'] = cari_hazir($pdo) ? array_map(static fn(array $t): string => (string)$t['ad'], cari_tanim_listesi($pdo, 'odeme_sekli', true)) : [];
    return $o;
}

/** Aktif KDV oranları (tanımlardan) + varsayılan. @return array{oranlar:list<int>,varsayilan:?int} */
function fatura_kdv_oranlari(PDO $pdo): array
{
    $or = []; $vs = null;
    foreach (fatura_tanim_listesi($pdo, 'kdv', true) as $t) {
        $or[] = (int)$t['deger1'];
        if ((int)$t['varsayilan'] === 1) $vs = (int)$t['deger1'];
    }
    return ['oranlar' => $or, 'varsayilan' => $vs ?? ($or[0] ?? null)];
}

/** Grubun kullanılacak serisi: verilen id (aktif + grup uyumlu) ya da grubun varsayılanı / ilki. */
function fatura_seri_sec(PDO $pdo, string $grup, int $id): ?array
{
    $liste = array_values(array_filter(fatura_tanim_listesi($pdo, 'seri', true), static fn(array $t): bool => (string)$t['belge_turu'] === $grup));
    if ($id > 0) { foreach ($liste as $t) if ((int)$t['id'] === $id) return $t; return null; }
    foreach ($liste as $t) if ((int)$t['varsayilan'] === 1) return $t;
    return $liste[0] ?? null;
}

/** Serinin o yıldaki son sırası ve sonraki numara önizlemesi (Fatura Tanımları ekranı). @return array{son_sira:int,sonraki:string} */
function fatura_seri_durum(PDO $pdo, array $seri, int $yil): array
{
    $st = $pdo->prepare('SELECT COALESCE(MAX(seri_sira), 0) FROM fatura_belgeler WHERE seri_id = ? AND seri_yil = ?');
    $st->execute([(int)$seri['id'], $yil]);
    $son = (int)$st->fetchColumn();
    return ['son_sira' => $son, 'sonraki' => fatura_seri_no((string)$seri['kod'], $yil, $son + 1)];
}

// ── Seçenekler / okuyucular ─────────────────────────────────────────────────

/** Aktif cariler (form için; cari tabloları yoksa []). @return list<array{id:int,kod:string,unvan:string,pb:string,vade_gun:?int}> */
function fatura_cari_secenekleri(PDO $pdo): array
{
    if (!cari_hazir($pdo)) return [];
    $o = [];
    foreach (cari_kart_listesi($pdo, ['aktif' => 1])['satirlar'] as $k) {
        $o[] = ['id' => (int)$k['id'], 'kod' => (string)$k['kod'], 'unvan' => (string)$k['unvan'], 'pb' => (string)($k['para_birimi'] ?? 'TRY'),
            'vade_gun' => $k['vade_gun'] !== null && $k['vade_gun'] !== '' ? (int)$k['vade_gun'] : null];
    }
    return $o;
}

/** Stok nedeni id'si: $yon giris|cikis, sistem kodu (yoksa 'diger'); ikisi de aktif değilse null. */
function fatura_stok_neden_id(PDO $pdo, string $yon, string $kod): ?int
{
    $liste = stok_tanim_listesi($pdo, $yon === 'giris' ? 'giris_neden' : 'cikis_neden', true);
    foreach ([$kod, 'diger'] as $k) foreach ($liste as $t) if ((string)($t['kod'] ?? '') === $k) return (int)$t['id'];
    return null;
}

/** Belge başlığı (ham satır). */
function fatura_ham(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM fatura_belgeler WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Belge aktif depoda görünür mü? (depo boşsa görünür) */
function fatura_gorunur(array $b): bool { return depot_visible_to_user((string)$b['depo']); }

function fatura_istek_bul(PDO $pdo, string $anahtar): ?array
{
    $st = $pdo->prepare('SELECT id, belge_no, fatura_no, tur FROM fatura_belgeler WHERE istek_anahtari = ?');
    $st->execute([$anahtar]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** MySQL: belge satırlarını FOR UPDATE ile kilitler (dönüşüm kalanı / iptal yarışı). SQLite: no-op. @param list<int> $ids */
function fatura_belge_kilitle(PDO $pdo, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    if (!$ids || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    sort($ids);
    $pdo->prepare('SELECT id FROM fatura_belgeler WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE')->execute($ids);
}

/** Seri satırını kilitler (MySQL FOR UPDATE) ve o yılın SONRAKİ sırasını kilitli okumayla döner. UNIQUE (seri_id, seri_yil, seri_sira) son savunma. */
function fatura_seri_kilitle(PDO $pdo, int $seriId, int $yil): int
{
    $my = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($my) $pdo->prepare('SELECT id FROM fatura_tanimlar WHERE id = ? FOR UPDATE')->execute([$seriId]);
    $st = $pdo->prepare('SELECT COALESCE(MAX(seri_sira), 0) FROM fatura_belgeler WHERE seri_id = ? AND seri_yil = ?' . ($my ? ' FOR UPDATE' : ''));
    $st->execute([$seriId, $yil]);
    return (int)$st->fetchColumn() + 1;
}

/** Seride o yılın en ileri tarihi (geçerli + iptal). */
function fatura_seri_son_tarih(PDO $pdo, int $seriId, int $yil): ?string
{
    $st = $pdo->prepare('SELECT MAX(tarih) FROM fatura_belgeler WHERE seri_id = ? AND seri_yil = ?');
    $st->execute([$seriId, $yil]);
    $t = $st->fetchColumn();
    return $t === false || $t === null ? null : substr((string)$t, 0, 10);
}

/** Aynı cari + aynı sınıf + aynı (elle) fatura_no'lu GEÇERLİ belge (mükerrer koruması). */
function fatura_mukerrer_bul(PDO $pdo, string $sinif, int $cariId, string $faturaNo): ?array
{
    $tl = fatura_sinif_turleri($sinif);
    $st = $pdo->prepare("SELECT id, belge_no FROM fatura_belgeler WHERE cari_id = ? AND fatura_no = ? AND durum = 'gecerli' AND tur IN (" . implode(',', array_fill(0, count($tl), '?')) . ') ORDER BY id LIMIT 1');
    $st->execute([$cariId, $faturaNo, ...$tl]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/**
 * Kaynak satırların kalan miktarı (milli): miktar − Σ GEÇERLİ hedef satırları. $hedefSinif doluysa yalnız o sınıfın hedefleri sayılır
 * (bilgi amaçlı; engelleyen kalan için boş bırakın — geçiş kuralları hedefi zaten tekilleştirir). @param list<int> $ids @return array<int,int>
 */
function fatura_kalanlar(PDO $pdo, array $kaynakSatirIdler, string $hedefSinif = ''): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $kaynakSatirIdler), static fn(int $x): bool => $x > 0)));
    if (!$ids) return [];
    $tl = $hedefSinif !== '' ? fatura_sinif_turleri($hedefSinif) : [];
    $ek = $tl ? ' AND tb.tur IN (' . implode(',', array_fill(0, count($tl), '?')) . ')' : '';
    $out = [];
    foreach (array_chunk($ids, 500) as $parca) {
        $st = $pdo->prepare("SELECT s.id, s.miktar, (SELECT COALESCE(SUM(t.miktar), 0) FROM fatura_satirlar t JOIN fatura_belgeler tb ON tb.id = t.belge_id
                WHERE t.kaynak_satir_id = s.id AND tb.durum = 'gecerli'$ek) AS kul
            FROM fatura_satirlar s WHERE s.id IN (" . implode(',', array_fill(0, count($parca), '?')) . ')');
        $st->execute([...$tl, ...$parca]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = stok_milli($r['miktar']) - stok_milli($r['kul']);
    }
    return $out;
}

/** Bu belgenin satırlarına bağlı (kaynak_satir_id) GEÇERLİ aşağı akış belgeleri. @return list<array{id:int,belge_no:string,tur:string}> */
function fatura_bagli_belgeler(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT DISTINCT b.id, b.belge_no, b.tur FROM fatura_satirlar t JOIN fatura_belgeler b ON b.id = t.belge_id JOIN fatura_satirlar s ON s.id = t.kaynak_satir_id
        WHERE s.belge_id = ? AND b.durum = 'gecerli' ORDER BY b.id");
    $st->execute([$id]);
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'belge_no' => (string)$r['belge_no'], 'tur' => (string)$r['tur']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Akış durumu: iptal > kapali (sipariş) > tam (kullanılan ≥ toplam) > kismi > yok. */
function fatura_akis_durum(array $b, int $toplamMilli, int $kulMilli): string
{
    if (($b['durum'] ?? '') === 'iptal') return 'iptal';
    if (($b['tur'] ?? '') === 'siparis' && (int)($b['kapali'] ?? 0) === 1) return 'kapali';
    if ($kulMilli <= 0) return 'yok';
    return $kulMilli >= $toplamMilli ? 'tam' : 'kismi';
}

/** DB satırı → milli/kuruş/f4 alanlarıyla zenginleştirilmiş satır. */
function fatura_satir_norm(array $s): array
{
    $s['milli'] = stok_milli($s['miktar']);
    $s['f4'] = $s['birim_fiyat'] !== null && $s['birim_fiyat'] !== '' ? (int)round((float)$s['birim_fiyat'] * 10000) : null;
    foreach (['brut_tutar', 'iskonto_tutar', 'tutar', 'kdv_tutar'] as $a) $s[$a . '_kurus'] = $s[$a] !== null && $s[$a] !== '' ? cari_kurus($s[$a]) : null;
    $s['kdv_orani'] = $s['kdv_orani'] !== null && $s['kdv_orani'] !== '' ? (int)$s['kdv_orani'] : null;
    $s['iskonto_bp'] = (int)$s['iskonto_bp'];
    $s['kart_id'] = $s['kart_id'] !== null && $s['kart_id'] !== '' ? (int)$s['kart_id'] : null;
    $s['kaynak_satir_id'] = $s['kaynak_satir_id'] !== null && $s['kaynak_satir_id'] !== '' ? (int)$s['kaynak_satir_id'] : null;
    return $s;
}

/**
 * Belge + satırlar (her satırda milli/f4/kuruş + sayaçlar: kullanilan_milli, kalan_milli, irsaliyelenen_milli, faturalanan_milli, iade_milli)
 * + bağlı stok fişi (stok_fis_ham) + cari dekont (cari_hareket_oku) + kaynak belgeler + hedef (bağlı) belgeler + akis_durum.
 * Görünürlük denetimi ÇAĞIRANDA (fatura_gorunur). @return ?array
 */
function fatura_oku(PDO $pdo, int $id): ?array
{
    $b = fatura_ham($pdo, $id);
    if ($b === null) return null;
    $st = $pdo->prepare('SELECT * FROM fatura_satirlar WHERE belge_id = ? ORDER BY sira, id');
    $st->execute([$id]);
    $satirlar = array_map('fatura_satir_norm', $st->fetchAll(PDO::FETCH_ASSOC));
    // Doğrudan hedef satırları (tür bazında)
    $st = $pdo->prepare("SELECT t.kaynak_satir_id AS kid, tb.tur, SUM(t.miktar) AS m FROM fatura_satirlar t JOIN fatura_belgeler tb ON tb.id = t.belge_id
        JOIN fatura_satirlar s ON s.id = t.kaynak_satir_id WHERE s.belge_id = ? AND tb.durum = 'gecerli' GROUP BY t.kaynak_satir_id, tb.tur");
    $st->execute([$id]);
    $say = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $say[(int)$r['kid']][(string)$r['tur']] = stok_milli($r['m']);
    // Sipariş: irsaliye üzerinden faturalanan (2 seviye)
    $iki = [];
    if ($b['tur'] === 'siparis') {
        $st = $pdo->prepare("SELECT i.kaynak_satir_id AS kid, SUM(f.miktar) AS m FROM fatura_satirlar f JOIN fatura_belgeler fb ON fb.id = f.belge_id
            JOIN fatura_satirlar i ON i.id = f.kaynak_satir_id JOIN fatura_belgeler ib ON ib.id = i.belge_id JOIN fatura_satirlar s ON s.id = i.kaynak_satir_id
            WHERE s.belge_id = ? AND fb.durum = 'gecerli' AND ib.durum = 'gecerli' AND ib.tur = 'irsaliye' GROUP BY i.kaynak_satir_id");
        $st->execute([$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $iki[(int)$r['kid']] = stok_milli($r['m']);
    }
    $topMil = 0; $topKul = 0;
    foreach ($satirlar as &$s) {
        $x = $say[(int)$s['id']] ?? [];
        $kul = array_sum($x);
        $s['kullanilan_milli'] = $kul;
        $s['kalan_milli'] = $s['milli'] - $kul;
        $s['irsaliyelenen_milli'] = (int)($x['irsaliye'] ?? 0);
        $s['faturalanan_milli'] = (int)($x['satis'] ?? 0) + (int)($x['satis_ihracat'] ?? 0) + (int)($iki[(int)$s['id']] ?? 0);
        $s['iade_milli'] = (int)($x['satis_iade'] ?? 0) + (int)($x['alis_iade'] ?? 0);
        $topMil += $s['milli']; $topKul += $kul;
    }
    unset($s);
    $b['satirlar'] = $satirlar;
    $b['bilgi'] = fatura_tur_bilgi((string)$b['tur']);
    $b['akis_durum'] = fatura_akis_durum($b, $topMil, $topKul);
    $b['stok_fis'] = !empty($b['stok_fis_id']) ? stok_fis_ham($pdo, (int)$b['stok_fis_id']) : null;
    $b['cari_hareket'] = !empty($b['cari_hareket_id']) && cari_hazir($pdo) ? cari_hareket_oku($pdo, (int)$b['cari_hareket_id']) : null;
    $st = $pdo->prepare('SELECT DISTINCT b.id, b.tur, b.belge_no, b.fatura_no, b.tarih, b.durum FROM fatura_satirlar t JOIN fatura_satirlar s ON s.id = t.kaynak_satir_id
        JOIN fatura_belgeler b ON b.id = s.belge_id WHERE t.belge_id = ? ORDER BY b.id');
    $st->execute([$id]);
    $b['kaynaklar'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT DISTINCT b.id, b.tur, b.belge_no, b.fatura_no, b.tarih, b.durum FROM fatura_satirlar s JOIN fatura_satirlar t ON t.kaynak_satir_id = s.id
        JOIN fatura_belgeler b ON b.id = t.belge_id WHERE s.belge_id = ? ORDER BY b.id');
    $st->execute([$id]);
    $b['hedefler'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $b;
}

/** Liste dönem süzgeci → [bas, bit] (Y-m-d ya da ''). tumu (varsayılan) | 30g | bu_ay | gecen_ay | ozel (bas/bit). */
function fatura_donem_araligi(array $f): array
{
    $d = (string)($f['donem'] ?? 'tumu');
    $bas = ''; $bit = '';
    if ($d === '30g') $bas = date('Y-m-d', strtotime('-30 days'));
    elseif ($d === 'bu_ay') $bas = date('Y-m-01');
    elseif ($d === 'gecen_ay') { $bas = date('Y-m-01', strtotime('first day of last month')); $bit = date('Y-m-t', strtotime('first day of last month')); }
    if ($d === 'ozel' || $d === 'tumu') {
        $bas = stok_tarih_coz($f['bas'] ?? '') ?? '';
        $bit = stok_tarih_coz($f['bit'] ?? '') ?? '';
    }
    return [$bas, $bit];
}

/**
 * Sınıf listesi (EN YENİ ÖNCE). f: ara (belge no / fatura no / cari kod-unvan / ürün), tur (sınıf içi), durum tumu|gecerli|iptal,
 * donem tumu|30g|bu_ay|gecen_ay|ozel (+ bas, bit), cari_id, sd (akış süzgeci: acik|bekleyen|yok · kismi · tamam|tam · kapali).
 * Depo: aktif depo filtreli (stok_depo_filtre). Her satırda toplam_milli, kullanilan_milli, akis_durum, satir_say, ilk_urun.
 * @return array{satirlar:list<array>,toplam_satir:int,toplamlar_pb:array<string,array{adet:int,net:int,kdv:int,genel:int,cari:int}>}
 */
function fatura_listesi(PDO $pdo, string $sinif, array $f = [], int $limit = 100, int $offset = 0): array
{
    $turler = fatura_sinif_turleri($sinif);
    if (!$turler) return ['satirlar' => [], 'toplam_satir' => 0, 'toplamlar_pb' => []];
    $tur = (string)($f['tur'] ?? '');
    if ($tur !== '' && in_array($tur, $turler, true)) $turler = [$tur];
    $w = ['b.tur IN (' . implode(',', array_fill(0, count($turler), '?')) . ')']; $p = $turler;
    [$dw, $dp] = stok_depo_filtre('b.depo');
    $w[] = $dw; array_push($p, ...$dp);
    [$bas, $bit] = fatura_donem_araligi($f);
    if ($bas !== '') { $w[] = 'b.tarih >= ?'; $p[] = $bas; }
    if ($bit !== '') { $w[] = 'b.tarih <= ?'; $p[] = $bit; }
    $durum = (string)($f['durum'] ?? 'tumu');
    if ($durum === 'gecerli' || $durum === 'iptal') { $w[] = 'b.durum = ?'; $p[] = $durum; }
    if (!empty($f['cari_id'])) { $w[] = 'b.cari_id = ?'; $p[] = (int)$f['cari_id']; }
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $l = '%' . $q . '%';
        $w[] = '(b.belge_no LIKE ? OR b.fatura_no LIKE ? OR b.cari_unvan LIKE ? OR b.cari_kod LIKE ? OR EXISTS (SELECT 1 FROM fatura_satirlar x WHERE x.belge_id = b.id AND (x.urun_adi LIKE ? OR x.kart_kod LIKE ?)))';
        array_push($p, $l, $l, $l, $l, $l, $l);
    }
    $top = '(SELECT COALESCE(SUM(s2.miktar), 0) FROM fatura_satirlar s2 WHERE s2.belge_id = b.id)';
    $kul = "(SELECT COALESCE(SUM(t.miktar), 0) FROM fatura_satirlar t JOIN fatura_belgeler tb ON tb.id = t.belge_id JOIN fatura_satirlar s ON s.id = t.kaynak_satir_id WHERE s.belge_id = b.id AND tb.durum = 'gecerli')";
    $sd = (string)($f['sd'] ?? ($f['siparis_durum'] ?? ($f['fatura_durum'] ?? '')));
    $kapaliKosul = $sinif === 'siparis' ? ' AND b.kapali = 0' : '';
    if (in_array($sd, ['acik', 'bekleyen', 'yok'], true)) $w[] = "b.durum = 'gecerli'$kapaliKosul AND $kul < 0.0005";
    elseif ($sd === 'kismi') $w[] = "b.durum = 'gecerli'$kapaliKosul AND $kul >= 0.0005 AND $kul < $top - 0.0005";
    elseif ($sd === 'tamam' || $sd === 'tam') $w[] = "b.durum = 'gecerli'$kapaliKosul AND $kul >= $top - 0.0005";
    elseif ($sd === 'kapali') $w[] = "b.durum = 'gecerli' AND b.kapali = 1";
    $where = implode(' AND ', $w);
    $st = $pdo->prepare("SELECT COUNT(*) FROM fatura_belgeler b WHERE $where");
    $st->execute($p);
    $toplam = (int)$st->fetchColumn();
    $limit = max(1, min(500, $limit)); $offset = max(0, $offset);
    $st = $pdo->prepare("SELECT b.*, $top AS toplam_miktar, $kul AS kul_miktar,
            (SELECT COUNT(*) FROM fatura_satirlar s3 WHERE s3.belge_id = b.id) AS satir_say,
            (SELECT s4.urun_adi FROM fatura_satirlar s4 WHERE s4.belge_id = b.id ORDER BY s4.sira, s4.id LIMIT 1) AS ilk_urun
        FROM fatura_belgeler b WHERE $where ORDER BY b.tarih DESC, b.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['toplam_milli'] = stok_milli($r['toplam_miktar']);
        $r['kullanilan_milli'] = stok_milli($r['kul_miktar']);
        $r['akis_durum'] = fatura_akis_durum($r, $r['toplam_milli'], $r['kullanilan_milli']);
        foreach (['net_toplam', 'kdv_toplam', 'genel_toplam', 'cari_tutar'] as $a) $r[$a . '_kurus'] = cari_kurus($r[$a]);
        $out[] = $r;
    }
    $st = $pdo->prepare("SELECT b.para_birimi AS pb, COUNT(*) AS adet, SUM(b.net_toplam) AS net, SUM(b.kdv_toplam) AS kdv, SUM(b.genel_toplam) AS genel, SUM(b.cari_tutar) AS cari
        FROM fatura_belgeler b WHERE $where AND b.durum = 'gecerli' GROUP BY b.para_birimi");
    $st->execute($p);
    $pbt = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pbt[(string)$r['pb']] = ['adet' => (int)$r['adet'], 'net' => cari_kurus($r['net']), 'kdv' => cari_kurus($r['kdv']), 'genel' => cari_kurus($r['genel']), 'cari' => cari_kurus($r['cari'])];
    return ['satirlar' => $out, 'toplam_satir' => $toplam, 'toplamlar_pb' => cari_pb_sirala($pbt)];
}

/**
 * "Kopyala": yeni belge formu ön değerleri (fatura_oku sonucundan). Tarih bugün; belge_no / fatura_no / seri sırası / istek anahtarı /
 * kaynak bağları DÜŞER. Tanım seçimleri snapshot ADLARIYLA döner (tevkifat_ad, stopaj_ad, bagkur_ad, istisna_kod, teslim_sekli) —
 * form bunları aktif tanım listesinde eşler. Satırlarda miktar/fiyat/iskonto ekran biçiminde.
 */
function fatura_kopya(array $b): array
{
    $sat = [];
    foreach ($b['satirlar'] ?? [] as $s) {
        $s = isset($s['milli']) ? $s : fatura_satir_norm($s);
        $sat[] = ['kart_id' => $s['kart_id'], 'urun_adi' => (string)$s['urun_adi'], 'miktar' => stok_miktar_bicim((int)$s['milli'], (string)$s['birim']), 'birim' => $s['kart_id'] === null ? (string)$s['birim'] : '',
            'birim_fiyat' => $s['f4'] !== null ? stok_fiyat_bicim((int)$s['f4']) : '', 'iskonto' => (int)$s['iskonto_bp'] > 0 ? fatura_bp_bicim((int)$s['iskonto_bp']) : '',
            'kdv_orani' => $s['kdv_orani'] !== null ? (string)$s['kdv_orani'] : '', 'gtip' => (string)($s['gtip'] ?? ''),
            'satir_brut_kg' => $s['brut_kg'] !== null && $s['brut_kg'] !== '' ? stok_miktar_bicim(stok_milli($s['brut_kg']), 'kg') : '',
            'satir_net_kg' => $s['net_kg'] !== null && $s['net_kg'] !== '' ? stok_miktar_bicim(stok_milli($s['net_kg']), 'kg') : '',
            'satir_kap' => $s['kap_adedi'] !== null && $s['kap_adedi'] !== '' ? (string)(int)$s['kap_adedi'] : '', 'lot' => (string)($s['lot'] ?? ''), 'satir_aciklama' => (string)($s['aciklama'] ?? '')];
    }
    $kg = static fn($x): string => $x !== null && $x !== '' ? stok_miktar_bicim(stok_milli($x), 'kg') : '';
    return ['tur' => (string)$b['tur'], 'tarih' => date('Y-m-d'), 'depo' => (string)$b['depo'], 'cari_id' => (int)$b['cari_id'], 'para_birimi' => (string)$b['para_birimi'],
        'kur' => $b['kur'] !== null && $b['kur'] !== '' ? rtrim(rtrim((string)$b['kur'], '0'), '.') : '', 'seri_id' => $b['seri_id'] !== null ? (int)$b['seri_id'] : null,
        'stok_etkisi' => (int)$b['stok_etkisi'], 'odeme_sekli' => (string)($b['odeme_sekli'] ?? ''), 'tevkifat_ad' => (string)($b['tevkifat_ad'] ?? ''), 'stopaj_ad' => (string)($b['stopaj_ad'] ?? ''),
        'bagkur_ad' => (string)($b['bagkur_ad'] ?? ''), 'istisna_kod' => (string)($b['istisna_kod'] ?? ''), 'teslim_sekli' => (string)($b['teslim_sekli'] ?? ''),
        'teslim_yeri' => (string)($b['teslim_yeri'] ?? ''), 'brut_kg' => $kg($b['brut_kg'] ?? null), 'net_kg' => $kg($b['net_kg'] ?? null), 'kap_adedi' => $b['kap_adedi'] !== null && $b['kap_adedi'] !== '' ? (string)(int)$b['kap_adedi'] : '',
        'plaka' => (string)($b['plaka'] ?? ''), 'sofor_adi' => (string)($b['sofor_adi'] ?? ''), 'sevk_adresi' => (string)($b['sevk_adresi'] ?? ''), 'aciklama' => (string)($b['aciklama'] ?? ''),
        'satirlar' => $sat, 'kopya_no' => (string)$b['belge_no']];
}

/**
 * Cari seçilince form özeti: bakiyeler (pb → kuruş, cari_bakiye) · son_fiyatlar (kart_id → {f4, pb, tarih, belge_no}; aynı sınıftaki
 * son GEÇERLİ fiyatlı satır) · acik_kaynak_say (sınıfın ana hedef türü için dönüşüm adayı sayısı).
 */
function fatura_cari_ozet(PDO $pdo, int $cariId, string $sinif): array
{
    $bak = [];
    if (cari_hazir($pdo)) foreach (cari_bakiye($pdo, $cariId) as $pb => $x) $bak[$pb] = (int)$x['bakiye'];
    $son = [];
    $tl = fatura_sinif_turleri($sinif);
    if ($tl) {
        $st = $pdo->prepare("SELECT s.kart_id, s.birim_fiyat, b.para_birimi, b.tarih, b.belge_no FROM fatura_satirlar s JOIN fatura_belgeler b ON b.id = s.belge_id
            WHERE b.cari_id = ? AND b.durum = 'gecerli' AND s.kart_id IS NOT NULL AND s.birim_fiyat IS NOT NULL AND b.tur IN (" . implode(',', array_fill(0, count($tl), '?')) . ')
            ORDER BY b.tarih DESC, b.id DESC, s.id DESC LIMIT 2000');
        $st->execute([$cariId, ...$tl]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (int)$r['kart_id'];
            if (isset($son[$k])) continue;
            $son[$k] = ['f4' => (int)round((float)$r['birim_fiyat'] * 10000), 'pb' => (string)$r['para_birimi'], 'tarih' => (string)$r['tarih'], 'belge_no' => (string)$r['belge_no']];
        }
    }
    $hedef = ['satis' => 'satis', 'irsaliye' => 'irsaliye'][$sinif] ?? null;
    return ['bakiyeler' => cari_pb_sirala($bak), 'son_fiyatlar' => $son, 'acik_kaynak_say' => $hedef !== null ? count(fatura_kaynak_adaylari($pdo, $hedef, $cariId)) : 0];
}

// ── Dönüşüm ─────────────────────────────────────────────────────────────────

/**
 * Hedef türe göre kaynak belge adayları (aynı cari): geçerli, sipariş kapalı değil, depo görünür, kalanı > 0. En yeni önce, ≤200.
 * @return list<array{id:int,tur:string,belge_no:string,fatura_no:?string,tarih:string,para_birimi:string,depo:string,toplam_milli:int,kalan_milli:int,satir_say:int}>
 */
function fatura_kaynak_adaylari(PDO $pdo, string $hedefTur, int $cariId): array
{
    $kt = fatura_gecisler()[$hedefTur] ?? [];
    if (!$kt || $cariId <= 0) return [];
    [$dw, $dp] = stok_depo_filtre('b.depo');
    $top = '(SELECT COALESCE(SUM(s2.miktar), 0) FROM fatura_satirlar s2 WHERE s2.belge_id = b.id)';
    $kul = "(SELECT COALESCE(SUM(t.miktar), 0) FROM fatura_satirlar t JOIN fatura_belgeler tb ON tb.id = t.belge_id JOIN fatura_satirlar s ON s.id = t.kaynak_satir_id WHERE s.belge_id = b.id AND tb.durum = 'gecerli')";
    $st = $pdo->prepare("SELECT b.id, b.tur, b.belge_no, b.fatura_no, b.tarih, b.para_birimi, b.depo, $top AS top_m, $kul AS kul_m, (SELECT COUNT(*) FROM fatura_satirlar s3 WHERE s3.belge_id = b.id) AS satir_say
        FROM fatura_belgeler b WHERE b.cari_id = ? AND b.durum = 'gecerli' AND b.kapali = 0 AND b.tur IN (" . implode(',', array_fill(0, count($kt), '?')) . ") AND $dw
        AND $kul < $top - 0.0005 ORDER BY b.tarih DESC, b.id DESC LIMIT 200");
    $st->execute([$cariId, ...$kt, ...$dp]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'tur' => (string)$r['tur'], 'belge_no' => (string)$r['belge_no'], 'fatura_no' => $r['fatura_no'], 'tarih' => (string)$r['tarih'],
            'para_birimi' => (string)$r['para_birimi'], 'depo' => (string)$r['depo'], 'toplam_milli' => stok_milli($r['top_m']), 'kalan_milli' => stok_milli($r['top_m']) - stok_milli($r['kul_m']), 'satir_say' => (int)$r['satir_say']];
    }
    return $out;
}

/**
 * Forma doldurulacak kaynak satırları (kalanı > 0 olanlar; ekran biçiminde): kaynak_satir_id, kaynak_belge_id, kaynak_belge_no, kart_id, kart_kod,
 * urun_adi, birim, miktar (kalan), kalan_milli, birim_fiyat, iskonto, kdv_orani (hedef KDV %0 türündeyse '0'), gtip, lot, satir_aciklama.
 * Geçersiz / kapalı / görünmeyen / geçişi izinsiz belgeler atlanır. @param list<int> $belgeIdler @return list<array>
 */
function fatura_kaynak_satirlari(PDO $pdo, array $belgeIdler, string $hedefTur): array
{
    $kt = fatura_gecisler()[$hedefTur] ?? [];
    $hb = fatura_tur_bilgi($hedefTur);
    $out = [];
    foreach (array_values(array_unique(array_map('intval', $belgeIdler))) as $bid) {
        $b = $bid > 0 ? fatura_ham($pdo, $bid) : null;
        if ($b === null || $b['durum'] !== 'gecerli' || (int)$b['kapali'] === 1 || !in_array((string)$b['tur'], $kt, true) || !fatura_gorunur($b)) continue;
        $st = $pdo->prepare('SELECT * FROM fatura_satirlar WHERE belge_id = ? ORDER BY sira, id');
        $st->execute([$bid]);
        $sat = array_map('fatura_satir_norm', $st->fetchAll(PDO::FETCH_ASSOC));
        $kal = fatura_kalanlar($pdo, array_column($sat, 'id'));
        foreach ($sat as $s) {
            $k = $kal[(int)$s['id']] ?? 0;
            if ($k <= 0) continue;
            $out[] = ['kaynak_satir_id' => (int)$s['id'], 'kaynak_belge_id' => $bid, 'kaynak_belge_no' => (string)$b['belge_no'], 'kart_id' => $s['kart_id'], 'kart_kod' => (string)($s['kart_kod'] ?? ''),
                'urun_adi' => (string)$s['urun_adi'], 'birim' => (string)$s['birim'], 'miktar' => stok_miktar_bicim($k, (string)$s['birim']), 'kalan_milli' => $k,
                'birim_fiyat' => $s['f4'] !== null ? stok_fiyat_bicim((int)$s['f4']) : '', 'iskonto' => $s['iskonto_bp'] > 0 ? fatura_bp_bicim($s['iskonto_bp']) : '',
                'kdv_orani' => !empty($hb['kdv_sifir']) ? '0' : ($s['kdv_orani'] !== null ? (string)$s['kdv_orani'] : ''), 'gtip' => (string)($s['gtip'] ?? ''),
                'lot' => (string)($s['lot'] ?? ''), 'satir_aciklama' => (string)($s['aciklama'] ?? '')];
        }
    }
    return $out;
}

/**
 * Kaynak bağı denetimi (dönüşüm). Doğrulamada (ön) ve transaction içinde kilitlerden SONRA (kesin) çağrılır; YAZMAZ.
 * $satirlar: [{i, kart_id, milli, kaynak_satir_id}] · @return array{sh:array<int,array<string,string>>,h:array<string,string>,kod:?string,turler:array<int,string>,belgeler:list<int>}
 */
function fatura_kaynak_kontrol(PDO $pdo, string $hedefTur, int $cariId, string $pb, array $satirlar): array
{
    $sh = []; $h = []; $kod = null; $turler = []; $belgeler = [];
    $ids = array_values(array_unique(array_filter(array_map(static fn(array $s): int => (int)($s['kaynak_satir_id'] ?? 0), $satirlar), static fn(int $x): bool => $x > 0)));
    if (!$ids) return ['sh' => [], 'h' => [], 'kod' => null, 'turler' => [], 'belgeler' => []];
    $izinli = fatura_gecisler()[$hedefTur] ?? [];
    $faturaHedef = fatura_tur_bilgi($hedefTur)['cari'] ?? null;
    $st = $pdo->prepare('SELECT s.id, s.kart_id, s.belge_id, b.tur AS b_tur, b.durum AS b_durum, b.kapali AS b_kapali, b.cari_id AS b_cari, b.para_birimi AS b_pb, b.depo AS b_depo, b.belge_no AS b_no
        FROM fatura_satirlar s JOIN fatura_belgeler b ON b.id = s.belge_id WHERE s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    $ks = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ks[(int)$r['id']] = $r;
    $kal = fatura_kalanlar($pdo, $ids);
    $istenen = [];
    foreach ($satirlar as $s) {
        $kid = (int)($s['kaynak_satir_id'] ?? 0);
        if ($kid <= 0) continue;
        $i = (int)$s['i'];
        $r = $ks[$kid] ?? null;
        if ($r === null) { $sh[$i]['kaynak_satir_id'] = 'Kaynak satırı bulunamadı.'; continue; }
        if ($r['b_durum'] !== 'gecerli') { $sh[$i]['kaynak_satir_id'] = 'Kaynak belge (' . $r['b_no'] . ') iptal edilmiş.'; continue; }
        if ((int)$r['b_kapali'] === 1) { $sh[$i]['kaynak_satir_id'] = 'Kaynak sipariş (' . $r['b_no'] . ') kapatılmış.'; continue; }
        if (!in_array((string)$r['b_tur'], $izinli, true)) { $sh[$i]['kaynak_satir_id'] = 'Bu kaynak belgeden bu türe dönüşüm yapılamaz.'; continue; }
        if (!depot_visible_to_user((string)$r['b_depo'])) { $sh[$i]['kaynak_satir_id'] = 'Kaynak belge aktif deponuzda görünmüyor.'; continue; }
        if ((int)$r['b_cari'] !== $cariId) { $h['cari_id'] = 'Kaynak belgeler aynı cariye ait olmalı.'; continue; }
        if ($faturaHedef !== null && (string)$r['b_pb'] !== $pb) { $h['para_birimi'] = 'Kaynak belgenin para birimi (' . $r['b_pb'] . ') ile aynı olmalı.'; continue; }
        $kk = $r['kart_id'] !== null && $r['kart_id'] !== '' ? (int)$r['kart_id'] : null;
        if ($kk !== ($s['kart_id'] ?? null)) { $sh[$i]['kart_id'] = 'Kaynak satırdaki stok kartı değiştirilemez.'; continue; }
        $istenen[$kid] = ($istenen[$kid] ?? 0) + (int)$s['milli'];
        $turler[$kid] = (string)$r['b_tur'];
        $belgeler[(int)$r['belge_id']] = true;
    }
    foreach ($satirlar as $s) {
        $kid = (int)($s['kaynak_satir_id'] ?? 0);
        if ($kid <= 0 || !isset($istenen[$kid]) || isset($sh[(int)$s['i']])) continue;
        $k = $kal[$kid] ?? 0;
        if ($istenen[$kid] > $k) { $sh[(int)$s['i']]['miktar'] = 'Kalan miktarı aşıyor (kalan: ' . stok_miktar_bicim(max(0, $k), 'lt') . ').'; $kod = 'kalan_asimi'; }
    }
    return ['sh' => $sh, 'h' => $h, 'kod' => $kod, 'turler' => $turler, 'belgeler' => array_keys($belgeler)];
}

// ── Doğrulama / yazanlar ────────────────────────────────────────────────────

/** POST paralel dizileri (ya da `satir` listesi) → satır listesi; tamamen boş satırlar atılır; `i` = formdaki (DOM) sıra. @return list<array<string,string|int>> */
function fatura_satir_topla(array $g): array
{
    $alanlar = ['kart_id', 'kart_ara', 'urun_adi', 'miktar', 'birim', 'birim_fiyat', 'iskonto', 'kdv_orani', 'gtip', 'satir_brut_kg', 'satir_net_kg', 'satir_kap', 'lot', 'satir_aciklama', 'kaynak_satir_id'];
    if (isset($g['satir']) && is_array($g['satir'])) {
        $ham = [];
        foreach (array_values($g['satir']) as $r) if (is_array($r)) $ham[] = $r;
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
        if (($t['kart_id'] === '' || $t['kart_id'] === '0') && $t['kart_ara'] === '' && $t['urun_adi'] === '' && $t['miktar'] === '') continue;
        $t['i'] = $i;
        $out[] = $t;
    }
    return $out;
}

/**
 * Belge doğrulaması (YAZMAZ). Başlık: tur, tarih, depo, cari_id | cari_ara, para_birimi, kur, vade_tarihi, termin_tarihi, seri_id, fatura_no,
 * stok_etkisi, tevkifat_id, stopaj_id, bagkur_id, istisna_id, teslim_id, odeme_sekli, teslim_yeri, brut_kg, net_kg, kap_adedi, beyanname_no,
 * plaka, sofor_adi, sevk_adresi, aciklama, istek_anahtari, eksiye_izin. Satırlar paralel dizilerle (fatura_satir_topla).
 * @return array{ok:bool,hata?:string,veri?:array,alanlar?:array<string,string>,satir_hatalari?:array<int,array<string,string>>,kod?:string}
 */
function fatura_dogrula(PDO $pdo, array $g): array
{
    $h = []; $sh = []; $v = []; $kod = null;
    $tur = (string)($g['tur'] ?? '');
    $bilgi = fatura_tur_bilgi($tur);
    if ($bilgi === null) return ['ok' => false, 'hata' => 'Geçersiz belge türü.', 'alanlar' => ['tur' => 'Geçersiz belge türü.']];
    $v['tur'] = $tur; $v['bilgi'] = $bilgi;
    $faturaTuru = $bilgi['cari'] !== null;

    $tarih = stok_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) $h['tarih'] = 'Geçerli bir tarih girin.';
    $v['tarih'] = $tarih;

    $depo = stok_depo_secenek((string)($g['depo'] ?? ''));
    if ($depo === null) $h['depo'] = 'Depo seçin.';
    elseif (!depot_visible_to_user($depo)) $h['depo'] = 'Bu depo aktif deponuzda değil; önce depo değiştirin.';
    $v['depo'] = $depo;

    // Cari (zorunlu; id ya da "KOD — Unvan" metni; aktif)
    $v['cari_id'] = 0; $v['cari'] = null;
    $cariHam = trim((string)($g['cari_id'] ?? ''));
    $cariMetin = trim((string)($g['cari_ara'] ?? ''));
    if (!cari_hazir($pdo)) $h['cari_id'] = 'Cari tabloları kurulmamış.';
    elseif (($cariHam === '' || $cariHam === '0') && $cariMetin === '') $h['cari_id'] = 'Cari seçin.';
    else {
        $ck = null;
        if ($cariHam !== '' && ctype_digit($cariHam)) $ck = cari_kart_oku($pdo, (int)$cariHam);
        else {
            $kd = cari_fold(trim(explode(' — ', $cariMetin)[0]));
            foreach (fatura_cari_secenekleri($pdo) as $c) if (cari_fold($c['kod']) === $kd || cari_fold($c['unvan']) === cari_fold($cariMetin)) { $ck = cari_kart_oku($pdo, $c['id']); break; }
        }
        if ($ck === null) $h['cari_id'] = 'Cari bulunamadı.';
        elseif ((int)$ck['aktif'] !== 1) $h['cari_id'] = 'Pasif cari seçilemez.';
        else { $v['cari_id'] = (int)$ck['id']; $v['cari'] = $ck; }
    }

    $pb = trim((string)($g['para_birimi'] ?? ''));
    if ($pb === '') $pb = 'TRY';
    $v['pb'] = $pb; $v['kur'] = null;
    if (!in_array($pb, cari_para_birimleri(), true)) $h['para_birimi'] = 'Geçersiz para birimi.';
    else {
        $kur = cari_kur_coz($g['kur'] ?? '', $pb);
        if ($kur === false) $h['kur'] = $pb === 'TRY' ? 'TL için kur girilmez.' : 'Geçerli bir kur girin (en çok 6 ondalık).';
        elseif ($kur === null && $pb !== 'TRY' && $faturaTuru) $h['kur'] = 'TL dışı para biriminde kur zorunlu (en çok 6 ondalık).';
        else $v['kur'] = $kur;
    }

    $v['vade'] = null;
    $vh = trim((string)($g['vade_tarihi'] ?? ''));
    if ($vh !== '') {
        $vt = stok_tarih_coz($vh);
        if ($vt === null) $h['vade_tarihi'] = 'Geçerli bir vade tarihi girin.';
        elseif ($tarih !== null && $vt < $tarih) $h['vade_tarihi'] = 'Vade tarihi belge tarihinden önce olamaz.';
        else $v['vade'] = $vt;
    } elseif ($faturaTuru && $tarih !== null && $v['cari'] !== null && $v['cari']['vade_gun'] !== null && $v['cari']['vade_gun'] !== '') {
        $v['vade'] = date('Y-m-d', strtotime($tarih . ' +' . (int)$v['cari']['vade_gun'] . ' days'));
    }
    $v['termin'] = null;
    if ($tur === 'siparis' && ($th = trim((string)($g['termin_tarihi'] ?? ''))) !== '') {
        $tt = stok_tarih_coz($th);
        if ($tt === null) $h['termin_tarihi'] = 'Geçerli bir termin tarihi girin.'; else $v['termin'] = $tt;
    }

    // Numara: biz → seri (fatura_no istemciden ALINMAZ); karşı → fatura_no elle
    $v['seri'] = null; $v['seri_yil'] = null; $v['fatura_no'] = null;
    if ($bilgi['duzenleyen'] === 'biz') {
        $seri = fatura_seri_sec($pdo, (string)$bilgi['seri_grubu'], (int)($g['seri_id'] ?? 0));
        if ($seri === null) $h['seri_id'] = (int)($g['seri_id'] ?? 0) > 0 ? 'Seri bu belge türüne uygun değil ya da pasif.' : "Fatura Tanımları'nda '" . fatura_seri_gruplari()[(string)$bilgi['seri_grubu']] . "' grubu için aktif seri tanımlayın.";
        else { $v['seri'] = ['id' => (int)$seri['id'], 'kod' => (string)$seri['kod'], 'ad' => (string)$seri['ad']]; $v['seri_yil'] = $tarih !== null ? (int)substr($tarih, 0, 4) : null; }
    } else {
        $fn = trim((string)($g['fatura_no'] ?? ''));
        if (mb_strlen($fn) > 60) $h['fatura_no'] = 'Belge numarası en çok 60 karakter.';
        elseif ($fn === '' && $tur !== 'siparis') $h['fatura_no'] = 'Karşı tarafın belge numarasını girin.';
        else $v['fatura_no'] = $fn === '' ? null : $fn;
    }

    // Stok etkisi
    $v['stok_etkisi'] = match (true) {
        $tur === 'irsaliye' => 1,
        $bilgi['stok'] === null => 0,
        default => !empty($g['stok_etkisi']) && (string)$g['stok_etkisi'] !== '0' ? 1 : 0,
    };

    // Tanım seçimleri (aktif; türe izinli; snapshot kopyalanır)
    $tanim = static function (string $alan, string $ttur, bool $izin, string $izinMsg) use ($pdo, $g, &$h): ?array {
        $ham = trim((string)($g[$alan] ?? ''));
        if ($ham === '' || $ham === '0') return null;
        if (!$izin) { $h[$alan] = $izinMsg; return null; }
        $t = ctype_digit($ham) ? fatura_tanim_bul($pdo, (int)$ham) : null;
        if ($t === null || $t['tur'] !== $ttur || (int)$t['aktif'] !== 1) { $h[$alan] = 'Geçersiz seçim.'; return null; }
        return $t;
    };
    $tev = $tanim('tevkifat_id', 'tevkifat', (bool)$bilgi['tevkifat_izin'], 'Bu belge türünde tevkifat uygulanmaz.');
    $sto = $tanim('stopaj_id', 'stopaj', (bool)$bilgi['mustahsil'], 'Stopaj yalnız müstahsil makbuzunda.');
    $bag = $tanim('bagkur_id', 'bagkur', (bool)$bilgi['mustahsil'], 'Bağ-Kur kesintisi yalnız müstahsil makbuzunda.');
    $ist = $tanim('istisna_id', 'istisna', $faturaTuru, 'İstisna yalnız faturalarda seçilir.');
    $tes = $tanim('teslim_id', 'teslim', true, '');
    if (!empty($bilgi['ihracat']) && $ist === null && !isset($h['istisna_id'])) $h['istisna_id'] = 'İhracat faturasında KDV istisna kodu zorunlu.';
    $v['tevkifat'] = $tev === null ? null : ['kod' => $tev['kod'], 'ad' => (string)$tev['ad'], 'pay' => (int)$tev['deger1'], 'payda' => (int)$tev['deger2']];
    $v['stopaj'] = $sto === null ? null : ['ad' => (string)$sto['ad'], 'bp' => (int)$sto['deger1']];
    $v['bagkur'] = $bag === null ? null : ['ad' => (string)$bag['ad'], 'bp' => (int)$bag['deger1']];
    $v['istisna'] = $ist === null ? null : ['kod' => (string)$ist['kod'], 'ad' => (string)$ist['ad']];
    $v['teslim'] = $tes === null ? null : (string)$tes['kod'];

    $os = trim((string)($g['odeme_sekli'] ?? ''));
    $v['odeme_sekli'] = null;
    if ($os !== '') {
        $adlar = cari_hazir($pdo) ? array_map(static fn(array $t): string => (string)$t['ad'], cari_tanim_listesi($pdo, 'odeme_sekli', true)) : [];
        if (!in_array($os, $adlar, true)) $h['odeme_sekli'] = 'Geçersiz ödeme şekli (Cari Tanımları).'; else $v['odeme_sekli'] = $os;
    }
    foreach (['teslim_yeri' => 150, 'beyanname_no' => 60, 'plaka' => 60, 'sofor_adi' => 100, 'sevk_adresi' => 500, 'aciklama' => 1000] as $a => $max) {
        $s = trim((string)($g[$a] ?? ''));
        if (mb_strlen($s) > $max) $h[$a] = "En çok $max karakter."; $v[$a] = $s === '' ? null : $s;
    }
    foreach (['brut_kg', 'net_kg'] as $a) {
        $s = trim((string)($g[$a] ?? ''));
        $v[$a] = null;
        if ($s !== '') { $m = stok_miktar_coz($s, 'kg'); if ($m === null) $h[$a] = 'Geçerli bir kg girin (tam sayı).'; else $v[$a] = $m; }
    }
    $kap = trim((string)($g['kap_adedi'] ?? ''));
    $v['kap_adedi'] = null;
    if ($kap !== '') { if (!preg_match('/^\d{1,6}$/', $kap)) $h['kap_adedi'] = 'Kap adedi 0–999999.'; else $v['kap_adedi'] = (int)$kap; }
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    $v['ik'] = $ik === '' ? null : $ik;
    $v['eksiye_izin'] = !empty($g['eksiye_izin']) && (string)$g['eksiye_izin'] !== '0';

    // Satırlar
    $ham = fatura_satir_topla($g);
    if (!$ham) $h['satir'] = 'En az bir satır girin.';
    elseif (count($ham) > FATURA_SATIR_MAX) $h['satir'] = 'Bir belgede en çok ' . FATURA_SATIR_MAX . ' satır olabilir.';
    $kartlar = [];
    foreach (stok_kart_secenekleri($pdo) as $k) $kartlar[$k['id']] = ['id' => $k['id'], 'kod' => $k['kod'], 'ad' => $k['ad'], 'barkod' => $k['barkod'], 'ana_birim' => $k['birim'],
        'cevrim_birim' => $k['cevrim_birim'], 'cevrim_katsayi' => $k['cevrim_katsayi'], 'kdv_orani' => $k['kdv']];
    $kdvT = fatura_kdv_oranlari($pdo);
    $satirlar = [];
    foreach ($ham as $sira => $r) {
        if (count($ham) > FATURA_SATIR_MAX) break;
        $i = (int)$r['i']; $e = [];
        $k = null; $serbest = false;
        $kid = (string)$r['kart_id'];
        if ($kid !== '' && $kid !== '0') {
            $k = ctype_digit($kid) ? ($kartlar[(int)$kid] ?? null) : null;
            if ($k === null) $e['kart_id'] = ctype_digit($kid) && stok_kart_oku($pdo, (int)$kid) !== null ? 'Pasif kart seçilemez.' : 'Stok kartı bulunamadı.';
        } elseif ((string)$r['kart_ara'] !== '') {
            $k = stok_kart_coz($kartlar, '', (string)$r['kart_ara']);
            if ($k === null) $e['kart_id'] = 'Stok kartı bulunamadı.';
        } elseif ((string)$r['urun_adi'] !== '') {
            if (!empty($bilgi['stok_zorunlu'])) $e['kart_id'] = 'Sevk irsaliyesinde her satırda stok kartı zorunlu.';
            else $serbest = true;
        } else $e['kart_id'] = 'Stok kartı ya da ürün/hizmet adı girin.';
        $urun = $k !== null ? (string)$k['ad'] : (string)$r['urun_adi'];
        if (mb_strlen($urun) > 200) $e['urun_adi'] = 'Ürün adı en çok 200 karakter.';
        // Miktar / birim (stok kuralları: lt/m dışı tam sayı; çevrim biriminden ana birime)
        $milli = null; $birim = '';
        if ($k !== null) {
            $ana = (string)$k['ana_birim']; $birim = $ana;
            $bh = (string)$r['birim'];
            if ($bh === '' || $bh === $ana) {
                $milli = stok_miktar_coz($r['miktar'], $ana);
                if ($milli === null) $e['miktar'] = 'Geçerli bir miktar girin (> 0' . (stok_birim_tam_mi($ana) ? ', tam sayı' : '') . ').';
            } elseif ($k['cevrim_birim'] !== null && $bh === (string)$k['cevrim_birim']) {
                $cm = stok_miktar_coz($r['miktar'], $bh);
                $kat = stok_milli($k['cevrim_katsayi']);
                if ($cm === null || $kat <= 0) $e['miktar'] = 'Geçerli bir miktar girin (> 0' . (stok_birim_tam_mi($bh) ? ', tam sayı' : '') . ').';
                else {
                    $milli = (int)round($cm * $kat / 1000);
                    if (stok_birim_tam_mi($ana) && $milli % 1000 !== 0) { $e['miktar'] = 'Çevrim sonucu ' . $ana . ' tam sayı değil; ' . $ana . ' olarak girin.'; $milli = null; }
                    elseif ($milli <= 0 || $milli > STOK_MIKTAR_MAX_MILLI) { $e['miktar'] = 'Miktar sınır dışı.'; $milli = null; }
                }
            } else $e['birim'] = 'Bu kart için geçersiz birim.';
        } elseif ($serbest) {
            $birim = (string)$r['birim'] !== '' ? (string)$r['birim'] : 'adet';
            if (!isset(stok_birimler()[$birim])) { $e['birim'] = 'Geçersiz birim.'; $birim = 'adet'; }
            else { $milli = stok_miktar_coz($r['miktar'], $birim); if ($milli === null) $e['miktar'] = 'Geçerli bir miktar girin (> 0' . (stok_birim_tam_mi($birim) ? ', tam sayı' : '') . ').'; }
        }
        // Fiyat / iskonto / KDV
        $f4 = null;
        $fh = (string)$r['birim_fiyat'];
        if ($fh !== '') { if (($f4 = stok_fiyat_coz($fh)) === null) $e['birim_fiyat'] = 'Geçerli bir birim fiyat girin (> 0, en çok 4 ondalık).'; }
        elseif (!empty($bilgi['fiyat_zorunlu'])) $e['birim_fiyat'] = 'Birim fiyat zorunlu.';
        $bp = 0;
        $ih = (string)$r['iskonto'];
        if ($ih !== '') {
            $bp = fatura_bp_coz($ih);
            if ($bp === null) { $e['iskonto'] = 'İskonto %0–99,99 olmalı (en çok 2 ondalık).'; $bp = 0; }
            elseif ($bp > 0 && $f4 === null && $fh === '') { $e['iskonto'] = 'Fiyatsız satırda iskonto olmaz.'; $bp = 0; }
        }
        $kh = (string)$r['kdv_orani'];
        $kdv = null;
        if (!empty($bilgi['kdv_sifir'])) {
            if ($kh !== '' && (!preg_match('/^\d{1,3}$/', $kh) || (int)$kh !== 0)) $e['kdv_orani'] = 'Bu belge türünde KDV %0 olmalı.';
            $kdv = 0;
        } elseif ($kh === '') {
            $kv = $k !== null ? (int)$k['kdv_orani'] : null;
            $kdv = $kv !== null && in_array($kv, $kdvT['oranlar'], true) ? $kv : $kdvT['varsayilan'];
            if ($kdv === null) $e['kdv_orani'] = "Fatura Tanımları'nda aktif KDV oranı yok.";
        } elseif (!preg_match('/^\d{1,3}$/', $kh) || !in_array((int)$kh, $kdvT['oranlar'], true)) {
            $e['kdv_orani'] = 'KDV oranı tanımlı oranlardan biri olmalı (' . implode(', ', array_map(static fn(int $x): string => '%' . $x, $kdvT['oranlar'])) . ').';
        } else $kdv = (int)$kh;
        $gt = (string)$r['gtip'];
        if (!preg_match('/^\d{0,12}$/', $gt)) $e['gtip'] = 'GTİP en çok 12 rakam.';
        $skg = [];
        foreach (['satir_brut_kg' => 'brut_kg', 'satir_net_kg' => 'net_kg'] as $a => $hd) {
            $skg[$hd] = null;
            if ((string)$r[$a] !== '') { $m = stok_miktar_coz($r[$a], 'kg'); if ($m === null) $e[$hd] = 'Geçerli bir kg girin (tam sayı).'; else $skg[$hd] = $m; }
        }
        $sk = (string)$r['satir_kap'];
        $kapS = null;
        if ($sk !== '') { if (!preg_match('/^\d{1,6}$/', $sk)) $e['kap_adedi'] = 'Kap adedi 0–999999.'; else $kapS = (int)$sk; }
        $lot = (string)$r['lot'];
        if (mb_strlen($lot) > 60) $e['lot'] = 'Lot en çok 60 karakter.';
        $sa = (string)$r['satir_aciklama'];
        if (mb_strlen($sa) > 200) $e['aciklama'] = 'Satır açıklaması en çok 200 karakter.';
        $ksid = (string)$r['kaynak_satir_id'];
        if ($ksid !== '' && !ctype_digit($ksid)) $e['kaynak_satir_id'] = 'Geçersiz kaynak satırı.';
        $hs = null;
        if ($milli !== null) {
            $hs = fatura_satir_hesapla($milli, $f4, $bp, $kdv);
            if ($hs['brut'] !== null && ($hs['brut'] <= 0 || $hs['brut'] > CARI_TUTAR_MAX_KURUS)) $e['birim_fiyat'] = 'Satır tutarı sınır dışı.';
        }
        if ($e) { $sh[$i] = $e; continue; }
        $satirlar[] = ['i' => $i, 'sira' => $sira + 1, 'kart_id' => $k !== null ? (int)$k['id'] : null, 'kart_kod' => $k !== null ? (string)$k['kod'] : null, 'urun_adi' => $urun,
            'birim' => $birim, 'milli' => $milli, 'f4' => $f4, 'bp' => $bp, 'kdv_orani' => $kdv, 'brut' => $hs['brut'], 'isk' => $hs['isk'], 'net' => $hs['net'], 'kdv' => $hs['kdv'],
            'gtip' => $gt === '' ? null : $gt, 'brut_kg' => $skg['brut_kg'], 'net_kg' => $skg['net_kg'], 'kap' => $kapS, 'lot' => $lot === '' ? null : $lot,
            'aciklama' => $sa === '' ? null : $sa, 'kaynak_satir_id' => $ksid === '' ? null : (int)$ksid, 'kaynak_tur' => null, 'stoklu' => 0];
    }

    // Dönüşüm (ön kontrol; kesin kontrol transaction içinde kilit altında)
    $v['kaynak_belgeler'] = [];
    if ($satirlar && $v['cari_id'] > 0 && !isset($h['para_birimi'])) {
        $kk = fatura_kaynak_kontrol($pdo, $tur, $v['cari_id'], $pb, $satirlar);
        foreach ($kk['sh'] as $i => $e) $sh[$i] = ($sh[$i] ?? []) + $e;
        $h += $kk['h'];
        if ($kk['kod'] !== null) $kod = $kk['kod'];
        foreach ($satirlar as &$s) if ($s['kaynak_satir_id'] !== null) $s['kaynak_tur'] = $kk['turler'][$s['kaynak_satir_id']] ?? null;
        unset($s);
        $v['kaynak_belgeler'] = $kk['belgeler'];
    } elseif (array_filter($satirlar, static fn(array $s): bool => $s['kaynak_satir_id'] !== null) && $v['cari_id'] <= 0) {
        $h['cari_id'] ??= 'Cari seçin.';
    }

    // Stoklu satırlar: etki açık + kartlı + irsaliye satırından gelmiyor (çift sayım yok)
    $stoklu = 0;
    foreach ($satirlar as &$s) {
        $s['stoklu'] = ($v['stok_etkisi'] === 1 && $bilgi['stok'] !== null && $s['kart_id'] !== null && $s['kaynak_tur'] !== 'irsaliye') ? 1 : 0;
        $stoklu += $s['stoklu'];
    }
    unset($s);
    $v['neden_id'] = null;
    if ($stoklu > 0) {
        $v['neden_id'] = fatura_stok_neden_id($pdo, (string)$bilgi['stok'], (string)$bilgi['stok_neden']);
        if ($v['neden_id'] === null) $h['stok_etkisi'] = "Stok Tanımları'nda '" . ($bilgi['stok'] === 'giris' ? 'Giriş' : 'Çıkış') . "' nedeni (" . $bilgi['stok_neden'] . ' ya da diger) aktif olmalı.';
    }

    // Mükerrer (karşı tarafın numarası; iptal edilmiş belgenin numarası yeniden girilebilir)
    if ($bilgi['duzenleyen'] === 'karsi' && $v['fatura_no'] !== null && $v['cari_id'] > 0 && ($m = fatura_mukerrer_bul($pdo, (string)$bilgi['sinif'], $v['cari_id'], $v['fatura_no'])) !== null) {
        $h['fatura_no'] = 'Bu cari için aynı numaralı belge zaten var: ' . $m['belge_no'] . '.'; $kod = 'mukerrer';
    }
    // Seri tarih sırası (geriye tarihli numara verilemez)
    if ($v['seri'] !== null && $tarih !== null && ($son = fatura_seri_son_tarih($pdo, $v['seri']['id'], (int)$v['seri_yil'])) !== null && $son > $tarih) {
        $h['tarih'] = 'Bu seride ' . date('d.m.Y', strtotime($son)) . ' tarihli belge var; geriye tarihli numara verilemez.';
    }

    $tp = fatura_toplam_hesapla($satirlar, ['tev_pay' => $v['tevkifat']['pay'] ?? 0, 'tev_payda' => $v['tevkifat']['payda'] ?? 0, 'stopaj_bp' => $v['stopaj']['bp'] ?? 0, 'bagkur_bp' => $v['bagkur']['bp'] ?? 0]);
    if ($faturaTuru && !$sh && $satirlar && ($tp['cari'] <= 0 || $tp['cari'] > CARI_TUTAR_MAX_KURUS || $tp['genel'] > CARI_TUTAR_MAX_KURUS)) $h['satir'] = 'Belge tutarı sıfır/sınır dışı.';

    if ($h || $sh) {
        ksort($sh);
        $ilk = $h ? (string)reset($h) : 'Satır ' . (array_key_first($sh) + 1) . ': ' . (string)reset($sh[array_key_first($sh)]);
        return ['ok' => false, 'hata' => $ilk, 'alanlar' => $h, 'satir_hatalari' => $sh] + ($kod !== null ? ['kod' => $kod] : []);
    }
    $v['satirlar'] = $satirlar;
    $v['toplam'] = $tp;
    return ['ok' => true, 'veri' => $v];
}

/**
 * Belgeyi kaydeder (TEK transaction; stok_fis_kaydet aynası). Kilit sırası SABİT: kartlar → kaynak belgeler → seri.
 * Kilit altında kesin kontroller (kalan / kaynak durumu / mükerrer / seri tarihi) → başlık → satırlar → (stok fişi, cari_id BOŞ, kaynak fatura:id)
 * → (cari dekontu, kaynak fatura:id) → belge_no / cari_hareket_id / stok_fis_id. İç çağrı !ok → RuntimeException(FATURA_IC_HATA) → HEPSİ geri.
 * Aynı istek_anahtari → mevcut belge (tekrar=true). Yetersiz stok → kod yetersiz_stok + eksikler (HİÇ yazılmaz); eksiye_izin=1 ile kayıt + uyari (yalnız yanıt + audit).
 * @return array{ok:bool,hata:?string,id:?int,belge_no?:string,fatura_no?:?string,uyari?:?string,stok_fis_no?:?string,cari_belge_no?:?string,tekrar?:bool,kod?:string,eksikler?:list<array>,alanlar?:array,satir_hatalari?:array}
 */
function fatura_kaydet(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = fatura_istek_bul($pdo, $ik)) !== null) {
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'fatura_no' => $var['fatura_no'], 'tekrar' => true];
    }
    $d = fatura_dogrula($pdo, $g);
    if (!$d['ok']) return fatura_hata((string)$d['hata'], ['alanlar' => $d['alanlar'] ?? [], 'satir_hatalari' => $d['satir_hatalari'] ?? []] + (isset($d['kod']) ? ['kod' => $d['kod']] : []));
    $v = $d['veri'];
    $bilgi = $v['bilgi'];
    $ic = null; $stokMap = [];
    try {
        $s = fatura_tx($pdo, function () use ($pdo, $v, $bilgi, $uid, &$ic, &$stokMap): array {
            // (a) kartlar → (b) kaynak belgeler → (c) seri  (sabit kilit sırası; kilitlerden önce düz SELECT yok)
            stok_kart_kilitle($pdo, array_values(array_filter(array_column($v['satirlar'], 'kart_id'))));
            fatura_belge_kilitle($pdo, $v['kaynak_belgeler']);
            $sira = null; $faturaNo = $v['fatura_no'];
            if ($v['seri'] !== null) {
                $sira = fatura_seri_kilitle($pdo, $v['seri']['id'], (int)$v['seri_yil']);
                $faturaNo = fatura_seri_no($v['seri']['kod'], (int)$v['seri_yil'], $sira);
            }
            // Kilit altında kesin kontroller (henüz yazma yok)
            $kk = fatura_kaynak_kontrol($pdo, $v['tur'], $v['cari_id'], $v['pb'], $v['satirlar']);
            if ($kk['sh'] || $kk['h']) {
                $ilk = $kk['h'] ? (string)reset($kk['h']) : 'Satır ' . (array_key_first($kk['sh']) + 1) . ': ' . (string)reset($kk['sh'][array_key_first($kk['sh'])]);
                return fatura_hata($ilk, ['alanlar' => $kk['h'], 'satir_hatalari' => $kk['sh']] + ($kk['kod'] !== null ? ['kod' => $kk['kod']] : []));
            }
            if ($bilgi['duzenleyen'] === 'karsi' && $faturaNo !== null && ($m = fatura_mukerrer_bul($pdo, (string)$bilgi['sinif'], $v['cari_id'], $faturaNo)) !== null) {
                return fatura_hata('Bu cari için aynı numaralı belge zaten var: ' . $m['belge_no'] . '.', ['kod' => 'mukerrer', 'alanlar' => ['fatura_no' => 'Mükerrer belge numarası.']]);
            }
            if ($v['seri'] !== null && ($son = fatura_seri_son_tarih($pdo, $v['seri']['id'], (int)$v['seri_yil'])) !== null && $son > $v['tarih']) {
                return fatura_hata('Bu seride ' . date('d.m.Y', strtotime($son)) . ' tarihli belge var; geriye tarihli numara verilemez.', ['alanlar' => ['tarih' => 'Geriye tarihli seri numarası.']]);
            }
            $simdi = date('Y-m-d H:i:s');
            $para = static fn(?int $k): ?string => $k === null ? null : number_format($k / 100, 2, '.', '');
            $c = $v['cari']; $t = $v['toplam'];
            $pdo->prepare('INSERT INTO fatura_belgeler (tur, belge_no, fatura_no, seri_id, seri_yil, seri_sira, tarih, vade_tarihi, termin_tarihi, depo, cari_id, cari_kod, cari_unvan, cari_vergi_dairesi, cari_vergi_no, cari_adres, cari_il, cari_ulke,
                    para_birimi, kur, brut_toplam, iskonto_toplam, net_toplam, kdv_toplam, genel_toplam, tevkifat_kod, tevkifat_ad, tevkifat_pay, tevkifat_payda, tevkifat_tutar, stopaj_ad, stopaj_bp, stopaj_tutar,
                    bagkur_ad, bagkur_bp, bagkur_tutar, cari_tutar, stok_etkisi, istisna_kod, istisna_ad, odeme_sekli, teslim_sekli, teslim_yeri, brut_kg, net_kg, kap_adedi, beyanname_no, plaka, sofor_adi, sevk_adresi,
                    aciklama, istek_anahtari, durum, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$v['tur'], '', $faturaNo, $v['seri']['id'] ?? null, $v['seri'] !== null ? (int)$v['seri_yil'] : null, $sira, $v['tarih'], $v['vade'], $v['termin'], $v['depo'],
                    $v['cari_id'], (string)$c['kod'], (string)$c['unvan'], $c['vergi_dairesi'], $c['vergi_no'], $c['adres'], $c['il'], $c['ulke'],
                    $v['pb'], $v['kur'], $para($t['brut']), $para($t['isk']), $para($t['net']), $para($t['kdv']), $para($t['genel']),
                    $v['tevkifat']['kod'] ?? null, $v['tevkifat']['ad'] ?? null, $v['tevkifat']['pay'] ?? null, $v['tevkifat']['payda'] ?? null, $para($t['tevkifat']),
                    $v['stopaj']['ad'] ?? null, $v['stopaj']['bp'] ?? null, $para($t['stopaj']), $v['bagkur']['ad'] ?? null, $v['bagkur']['bp'] ?? null, $para($t['bagkur']),
                    $para($bilgi['cari'] !== null ? $t['cari'] : 0), $v['stok_etkisi'], $v['istisna']['kod'] ?? null, $v['istisna']['ad'] ?? null, $v['odeme_sekli'], $v['teslim'], $v['teslim_yeri'],
                    $v['brut_kg'] !== null ? stok_milli_db($v['brut_kg']) : null, $v['net_kg'] !== null ? stok_milli_db($v['net_kg']) : null, $v['kap_adedi'], $v['beyanname_no'], $v['plaka'], $v['sofor_adi'], $v['sevk_adresi'],
                    $v['aciklama'], $v['ik'], 'gecerli', $simdi, $uid]);
            $fid = (int)$pdo->lastInsertId();
            $belgeNo = fatura_belge_no($v['tur'], $v['tarih'], $fid);
            $ins = $pdo->prepare('INSERT INTO fatura_satirlar (belge_id, sira, kart_id, kart_kod, urun_adi, birim, miktar, birim_fiyat, iskonto_bp, brut_tutar, iskonto_tutar, tutar, kdv_orani, kdv_tutar, gtip, brut_kg, net_kg, kap_adedi, lot, aciklama, kaynak_satir_id, stoklu)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($v['satirlar'] as $n => $sat) {
                $ins->execute([$fid, $n + 1, $sat['kart_id'], $sat['kart_kod'], $sat['urun_adi'], $sat['birim'], stok_milli_db($sat['milli']), $sat['f4'] !== null ? number_format($sat['f4'] / 10000, 4, '.', '') : null,
                    $sat['bp'], $para($sat['brut']), $para($sat['isk']), $para($sat['net']), $sat['kdv_orani'], $para($sat['kdv']), $sat['gtip'],
                    $sat['brut_kg'] !== null ? stok_milli_db($sat['brut_kg']) : null, $sat['net_kg'] !== null ? stok_milli_db($sat['net_kg']) : null, $sat['kap'], $sat['lot'], $sat['aciklama'], $sat['kaynak_satir_id'], $sat['stoklu']]);
            }
            // Stok fişi (yalnız stok_core fonksiyonuyla; cari_id BOŞ — dekontu yalnız fatura yazar; kaynak damgası fatura:id)
            $stokFisId = null; $stokFisNo = null; $uyari = null;
            $stoklu = array_values(array_filter($v['satirlar'], static fn(array $x): bool => $x['stoklu'] === 1));
            if ($stoklu) {
                $fiyatli = $bilgi['stok'] === 'giris' && $bilgi['iade'] === false;   // alış / müstahsil: değer = net × kur; çıkış ve satış iadesi ortalamadan
                $sg = ['tur' => (string)$bilgi['stok'], 'tarih' => $v['tarih'], 'depo' => $v['depo'], 'neden_id' => (string)$v['neden_id'], 'cari_id' => '',
                    'para_birimi' => $fiyatli ? $v['pb'] : 'TRY', 'kur' => $fiyatli ? (string)($v['kur'] ?? '') : '',
                    'evrak_no' => mb_substr($faturaNo ?? $belgeNo, 0, 60), 'aciklama' => 'Otomatik: ' . $bilgi['etiket'] . ' ' . $belgeNo, 'eksiye_izin' => $v['eksiye_izin'] ? '1' : '0'];
                foreach ($stoklu as $n => $sat) {
                    $stokMap[$n] = (int)$sat['i'];
                    $sg['kart_id'][] = (string)$sat['kart_id']; $sg['kart_ara'][] = ''; $sg['miktar'][] = stok_miktar_bicim((int)$sat['milli'], (string)$sat['birim']); $sg['birim'][] = '';
                    $sg['birim_fiyat'][] = $fiyatli ? stok_fiyat_bicim(fatura_stok_fiyat4((int)$sat['net'], (int)$sat['milli'], (int)$sat['bp'], (int)$sat['f4'])) : '';
                    $sg['kdv_orani'][] = $fiyatli && in_array((int)$sat['kdv_orani'], stok_kdv_oranlari(), true) ? (string)(int)$sat['kdv_orani'] : '0';
                    $sg['lot'][] = (string)($sat['lot'] ?? ''); $sg['satir_aciklama'][] = (string)($sat['aciklama'] ?? ''); $sg['yon'][] = '';
                }
                $sr = stok_fis_kaydet($pdo, $sg, $uid, ['modul' => 'fatura', 'id' => $fid]);
                if (empty($sr['ok'])) { $ic = $sr; throw new RuntimeException((string)($sr['hata'] ?? 'Stok fişi yazılamadı.'), FATURA_IC_HATA); }
                $stokFisId = (int)$sr['id']; $stokFisNo = (string)$sr['fis_no']; $uyari = $sr['uyari'] ?? null;
            }
            // Cari dekontu (yalnız cari_core fonksiyonuyla; kaynak bağı çekirdekten verilir)
            $cariHid = null; $cariNo = null;
            if ($bilgi['cari'] !== null) {
                $cr = cari_hareket_ekle($pdo, ['tur' => (string)$bilgi['cari'], 'cari_id' => $v['cari_id'], 'tarih' => $v['tarih'], 'tutar' => number_format($t['cari'] / 100, 2, '.', ''),
                    'para_birimi' => $v['pb'], 'kur' => $v['kur'] ?? '', 'aciklama' => mb_substr($bilgi['etiket'] . ' ' . $belgeNo . ($faturaNo !== null ? ' · ' . $faturaNo : ''), 0, 500),
                    'evrak_no' => mb_substr($faturaNo ?? $belgeNo, 0, 60)], $uid, ['modul' => 'fatura', 'id' => $fid]);
                if (empty($cr['ok'])) throw new RuntimeException('Cari hareketi yazılamadı: ' . (string)($cr['hata'] ?? 'bilinmeyen hata'), FATURA_IC_HATA);
                $cariHid = (int)$cr['id']; $cariNo = (string)$cr['belge_no'];
            }
            $pdo->prepare('UPDATE fatura_belgeler SET belge_no = ?, cari_hareket_id = ?, stok_fis_id = ? WHERE id = ?')->execute([$belgeNo, $cariHid, $stokFisId, $fid]);
            return ['ok' => true, 'hata' => null, 'id' => $fid, 'belge_no' => $belgeNo, 'fatura_no' => $faturaNo, 'uyari' => $uyari, 'stok_fis_id' => $stokFisId, 'stok_fis_no' => $stokFisNo,
                'cari_hareket_id' => $cariHid, 'cari_belge_no' => $cariNo, 'tekrar' => false];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== FATURA_IC_HATA) throw $e;
        $ek = [];
        if (is_array($ic)) {
            if (isset($ic['kod'])) $ek['kod'] = $ic['kod'];
            if (!empty($ic['eksikler'])) $ek['eksikler'] = $ic['eksikler'];
            if (!empty($ic['satir_hatalari'])) { $ek['satir_hatalari'] = []; foreach ($ic['satir_hatalari'] as $n => $x) $ek['satir_hatalari'][$stokMap[(int)$n] ?? (int)$n] = $x; }
            if (!empty($ic['alanlar'])) $ek['alanlar'] = $ic['alanlar'];
        }
        return fatura_hata($e->getMessage(), $ek);
    } catch (PDOException $e) {
        if ($v['ik'] !== null && ($var = fatura_istek_bul($pdo, $v['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'fatura_no' => $var['fatura_no'], 'tekrar' => true];
        }
        $m = $e->getMessage();
        if (str_contains($m, 'uq_ftb_seri') || str_contains($m, 'fatura_belgeler.seri_id')) return fatura_hata('Numara çakışması; yeniden kaydedin.', ['alanlar' => ['seri_id' => 'Numara çakışması.']]);
        error_log('[fatura_kaydet] ' . $m);
        return fatura_hata('Belge kaydedilemedi.');
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('create', 'fatura', (int)$s['id'], null, ['tur' => $v['tur'], 'belge_no' => $s['belge_no'], 'fatura_no' => $s['fatura_no'], 'cari_id' => $v['cari_id'], 'depo' => $v['depo'],
        'pb' => $v['pb'], 'cari_tutar' => $bilgi['cari'] !== null ? $v['toplam']['cari'] / 100 : null, 'satir' => count($v['satirlar']), 'stok_fis_id' => $s['stok_fis_id'],
        'cari_hareket_id' => $s['cari_hareket_id'], 'kaynak_belgeler' => $v['kaynak_belgeler'], 'uyari' => $s['uyari']]);
    return $s;
}

/**
 * İptal: neden 3–500 · görünür · aşağı akışta GEÇERLİ bağlı belge varsa 409 bagli_belge (iptal sırası alttan yukarı).
 * Tek transaction: kart + belge kilidi → bağlı belge kontrolü (kilit altında tekrar) → durum UPDATE → stok_fis_iptal(…, 'fatura') → cari_kaynakli_iptal.
 * İptal HER ZAMAN yapılır (stok eksiye düşerse `uyari`). İptal edilen belgenin satırları kalan sayaçlarından kendiliğinden düşer.
 */
function fatura_iptal(PDO $pdo, int $id, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (mb_strlen($neden) < 3) return fatura_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]);
    if (mb_strlen($neden) > 500) return fatura_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
    $b = fatura_ham($pdo, $id);
    if ($b === null) return fatura_hata('Belge bulunamadı.');
    if (!fatura_gorunur($b)) return fatura_hata('Bu belge aktif deponuzda görünmüyor.');
    if ($b['durum'] === 'iptal') return fatura_hata('Belge zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
    $bagliMsg = static fn(array $bl): string => 'Önce şu belgeleri iptal edin: ' . implode(', ', array_column($bl, 'belge_no')) . '.';
    if ($bl = fatura_bagli_belgeler($pdo, $id)) return fatura_hata($bagliMsg($bl), ['kod' => 'bagli_belge', 'bagli' => $bl]);
    $st = $pdo->prepare('SELECT kart_id FROM fatura_satirlar WHERE belge_id = ? AND kart_id IS NOT NULL');
    $st->execute([$id]);
    $kartlar = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $sebep = mb_substr('Fatura iptali: ' . $neden, 0, 500);
    try {
        $s = fatura_tx($pdo, function () use ($pdo, $b, $id, $neden, $uid, $kartlar, $sebep, $bagliMsg): array {
            stok_kart_kilitle($pdo, $kartlar);
            fatura_belge_kilitle($pdo, [$id]);
            if ($bl = fatura_bagli_belgeler($pdo, $id)) return fatura_hata($bagliMsg($bl), ['kod' => 'bagli_belge', 'bagli' => $bl]);
            $up = $pdo->prepare("UPDATE fatura_belgeler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'");
            $up->execute([date('Y-m-d H:i:s'), $uid, $neden, $id]);
            if ($up->rowCount() !== 1) return fatura_hata('Belge zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
            $stokIptal = null; $uyari = null;
            if (!empty($b['stok_fis_id'])) {
                $sr = stok_fis_iptal($pdo, (int)$b['stok_fis_id'], $sebep, $uid, 'fatura');
                if (empty($sr['ok']) && ($sr['kod'] ?? '') !== 'zaten_iptal') throw new RuntimeException('Bağlı stok fişi iptal edilemedi: ' . (string)($sr['hata'] ?? ''), FATURA_IC_HATA);
                $stokIptal = !empty($sr['ok']) ? (int)$b['stok_fis_id'] : null;
                $uyari = $sr['uyari'] ?? null;
            }
            $cariIptal = [];
            if (!empty($b['cari_hareket_id'])) {
                $cr = cari_kaynakli_iptal($pdo, 'fatura', $id, $sebep, $uid);
                if (empty($cr['ok'])) throw new RuntimeException('Bağlı cari hareketi iptal edilemedi: ' . (string)($cr['hata'] ?? ''), FATURA_IC_HATA);
                $cariIptal = $cr['iptal_idler'];
            }
            return ['ok' => true, 'hata' => null, 'id' => $id, 'belge_no' => (string)$b['belge_no'], 'uyari' => $uyari, 'stok_iptal' => $stokIptal, 'cari_iptal' => $cariIptal];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== FATURA_IC_HATA) throw $e;
        return fatura_hata($e->getMessage());
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('cancel', 'fatura', $id, ['durum' => 'gecerli', 'belge_no' => $b['belge_no']], ['durum' => 'iptal', 'neden' => $neden, 'stok_iptal' => $s['stok_iptal'], 'cari_iptal' => $s['cari_iptal'], 'uyari' => $s['uyari']]);
    return $s;
}

/** Sipariş kapat (neden 3–500) / yeniden aç. Yalnız tur=siparis, geçerli, görünür. Kapalı siparişten dönüşüm yapılamaz; mevcut bağlı belgeler etkilenmez. */
function fatura_siparis_kapat(PDO $pdo, int $id, bool $kapat, string $neden, int $uid): array
{
    $b = fatura_ham($pdo, $id);
    if ($b === null || $b['tur'] !== 'siparis') return fatura_hata('Sipariş fişi bulunamadı.');
    if (!fatura_gorunur($b)) return fatura_hata('Bu belge aktif deponuzda görünmüyor.');
    if ($b['durum'] !== 'gecerli') return fatura_hata('İptal edilmiş sipariş kapatılamaz / açılamaz.', ['kod' => 'zaten_iptal']);
    $neden = trim($neden);
    if ($kapat) {
        if (mb_strlen($neden) < 3) return fatura_hata('Kapatma nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]);
        if (mb_strlen($neden) > 500) return fatura_hata('Kapatma nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
        $deg = [1, date('Y-m-d H:i:s'), $uid, $neden];
    } else {
        $deg = [0, null, null, null];
    }
    $up = $pdo->prepare("UPDATE fatura_belgeler SET kapali = ?, kapali_at = ?, kapali_by = ?, kapali_neden = ? WHERE id = ? AND durum = 'gecerli'");
    $up->execute([...$deg, $id]);
    audit_log_event('update', 'fatura', $id, ['kapali' => (int)$b['kapali']], ['kapali' => $kapat ? 1 : 0, 'neden' => $kapat ? $neden : null]);
    return ['ok' => true, 'hata' => null, 'id' => $id, 'belge_no' => (string)$b['belge_no']];
}
