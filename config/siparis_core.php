<?php
// =========================================================
// config/siparis_core.php — Siparişler modülü çekirdeği (v371)
//
// İçerik: şema (tablolar/migrate/sema_hazir), sayfa kapısı + varlıklar,
// durum makinesi, olay (zaman çizelgesi) kaydı, okuma yardımcıları,
// dosya deposu (doğrulama/kaydetme/yol), sezon sayaçları, plaka biçimi,
// üretici/kalıntı profilleri.
//
// Kurallar:
//  • Tablolar YALNIZ migrate.php kartından kurulur (siparis_migrate()); hiçbir
//    sayfa isteği şema değiştirmez. Tablo yoksa sayfalar "kurulmadı" kutusu
//    gösterir (siparis_kurulmadi_html()). db.php / helpers IIFE'ye EKLENMEZ.
//  • Yetki kapısı can_siparis() (config/helpers.php) — burada TEKRAR yazılmaz.
//  • Kişisel veri (üretici adı, GGN, lab, dosya içeriği) audit'e YAZILMAZ;
//    audit yalnız id / order_ref / alan adı / durum / sayı taşır.
//  • SQL MySQL 5.7/8 ve SQLite (testler) ile çalışır: NOW() yerine PHP saati,
//    ON DUPLICATE KEY yerine SELECT → INSERT (23000 yakala), FOR UPDATE yalnız
//    MySQL'de (siparis_kilit_sql()). Aynı adlı parametre iki kez KULLANILMAZ.
//  • Bağımlılık: config/db.php + config/auth.php + config/helpers.php önceden yüklü.
// =========================================================
declare(strict_types=1);

const SIPARIS_MAX_DOSYA = 15 * 1024 * 1024;   const SIPARIS_MAX_DOSYA_ADET = 10;
const SIPARIS_BELGE_NO_ONEK = 'AsyaDoc';      const SIPARIS_BELGE_NO_HANE = 3;
const SIPARIS_SEZON_AY = 7;                   // ay >= 7 → sezon = yıl, değilse yıl-1
const SIPARIS_VARSAYILAN_TEDARIKCI = '1102720';
const SIPARIS_PAKETEVI_AD = 'ASYA FRESH';     const SIPARIS_PAKETEVI_GLN = '8683071468713';
const SIPARIS_DARA_KG = 90;                   // brüt − net varsayılanı
const SIPARIS_PER_PAGE = 50;
if (!defined('SIPARIS_DEPO_DIR'))   define('SIPARIS_DEPO_DIR', dirname(__DIR__) . '/storage/siparis/');
if (!defined('SIPARIS_PKL_SABLON')) define('SIPARIS_PKL_SABLON', dirname(__DIR__) . '/templates/excel/pkl_v14_blank.xlsx');

/** Dosya deposunun .htaccess'i — silinirse siparis_depo_hazirla() yeniden yazar. */
const SIPARIS_DEPO_HTACCESS = "Options -Indexes\nRequire all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

/** Olay (zaman çizelgesi) türleri — siparis_olay() yalnız bunları kabul eder. */
const SIPARIS_OLAY_TURLERI = ['olusturuldu', 'belge_eklendi', 'belge_eski_surum', 'belge_silindi', 'alan_guncellendi',
    'alan_elle', 'kilit_kaldirildi', 'catisma', 'catisma_kabul', 'catisma_red', 'durum', 'pkl_taslak', 'pkl_uretildi',
    'pkl_yuklendi', 'mail_gonderildi', 'evrak_gonderildi', 'beyan_baglandi', 'beyan_kaldirildi', 'geri_getirildi', 'silindi', 'uyari', 'invoice_uretildi'];

// ── ŞEMA ────────────────────────────────────────────────────────────────

/** @return array<string,string> tablo adı → CREATE TABLE (yalnız ekleyici, ALTER yok, FK yok). */
function siparis_tablolar(): array
{
    $t = [];
    // ROW_FORMAT=DYNAMIC AÇIKÇA: geniş VARCHAR/LONGTEXT sütunlu tablolar COMPACT biçimde "1118 Row size too large" verebilir.
    $son = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";

    // 1 satır = 1 sevkiyat = 1 order_ref = 1 tır
    $t['siparis_sevkiyatlar'] = "CREATE TABLE IF NOT EXISTS `siparis_sevkiyatlar` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `tedarikci_no`        VARCHAR(20)  NOT NULL DEFAULT '',
        `order_ref`           VARCHAR(30)  NOT NULL,
        `tur_no`              VARCHAR(30)  NULL DEFAULT NULL,
        `po_belge_id`         INT          NULL DEFAULT NULL,
        `cw_hafta`            TINYINT      NULL DEFAULT NULL,
        `cw_yil`              SMALLINT     NULL DEFAULT NULL,
        `alici_kodu`          VARCHAR(20)  NULL DEFAULT NULL,
        `alici_adi`           VARCHAR(100) NULL DEFAULT NULL,
        `sold_to`             VARCHAR(150) NULL DEFAULT NULL,
        `yukleme_tarihi`      DATE         NULL DEFAULT NULL,
        `yukleme_yeri`        VARCHAR(150) NULL DEFAULT NULL,
        `bosaltma_tarihi`     DATE         NULL DEFAULT NULL,
        `bosaltma_yerleri`    VARCHAR(255) NULL DEFAULT NULL,
        `gumruk`              VARCHAR(150) NULL DEFAULT NULL,
        `kdv_no`              VARCHAR(40)  NULL DEFAULT NULL,
        `incoterm`            VARCHAR(10)  NULL DEFAULT NULL,
        `planlanan_nakliyeci` VARCHAR(150) NULL DEFAULT NULL,
        `planlanan_plakalar`  VARCHAR(60)  NULL DEFAULT NULL,
        `nakliyeci`           VARCHAR(150) NULL DEFAULT NULL,
        `plakalar`            VARCHAR(60)  NULL DEFAULT NULL,
        `navlun_no`           VARCHAR(30)  NULL DEFAULT NULL,
        `toplam_palet`        INT          NULL DEFAULT NULL,
        `toplam_koli`         INT          NULL DEFAULT NULL,
        `var_po`              TINYINT(1)   NOT NULL DEFAULT 0,
        `var_ll`              TINYINT(1)   NOT NULL DEFAULT 0,
        `var_pc`              TINYINT(1)   NOT NULL DEFAULT 0,
        `var_pkl`             TINYINT(1)   NOT NULL DEFAULT 0,
        `acik_catisma`        INT          NOT NULL DEFAULT 0,
        `uyari_sayisi`        INT          NOT NULL DEFAULT 0,
        `durum`               VARCHAR(20)  NOT NULL DEFAULT 'siparis_alindi',
        `durum_at`            DATETIME     NULL DEFAULT NULL,
        `yola_cikis_at`       DATETIME     NULL DEFAULT NULL,
        `mail_gonderildi_at`  DATETIME     NULL DEFAULT NULL,
        `mail_gonderildi_by`  INT          NULL DEFAULT NULL,
        `iptal_nedeni`        VARCHAR(255) NULL DEFAULT NULL,
        `elle_alanlar`        LONGTEXT     NULL,
        `kaynaklar`           LONGTEXT     NULL,
        `uyarilar_json`       LONGTEXT     NULL,
        `notlar`              TEXT         NULL,
        `depo`                VARCHAR(150) NOT NULL DEFAULT '',
        `created_by`          INT          NULL DEFAULT NULL,
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_by`          INT          NULL DEFAULT NULL,
        `updated_at`          DATETIME     NULL DEFAULT NULL,
        `deleted_at`          DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_ss_ref` (`tedarikci_no`, `order_ref`),
        INDEX `idx_ss_tur` (`tur_no`),
        INDEX `idx_ss_durum` (`durum`, `yukleme_tarihi`),
        INDEX `idx_ss_po` (`po_belge_id`),
        INDEX `idx_ss_cw` (`cw_yil`, `cw_hafta`),
        INDEX `idx_ss_depo` (`depo`),
        INDEX `idx_ss_navlun` (`navlun_no`)
    )" . $son;

    // kalem anahtarı = (ian, kalibre = koli başına adet)
    $t['siparis_kalemler'] = "CREATE TABLE IF NOT EXISTS `siparis_kalemler` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `sevkiyat_id`  INT          NOT NULL,
        `ian`          VARCHAR(20)  NOT NULL DEFAULT '',
        `kalibre`      VARCHAR(10)  NOT NULL DEFAULT '',
        `sira`         INT          NOT NULL DEFAULT 0,
        `urun`         VARCHAR(80)  NULL DEFAULT NULL,
        `aciklama`     VARCHAR(150) NULL DEFAULT NULL,
        `cesit`        VARCHAR(60)  NULL DEFAULT NULL,
        `artikel_no`   VARCHAR(20)  NULL DEFAULT NULL,
        `koli_icerik`  VARCHAR(20)  NULL DEFAULT NULL,
        `ambalaj`      VARCHAR(100) NULL DEFAULT NULL,
        `palet_tipi`   VARCHAR(10)  NULL DEFAULT NULL,
        `pf`           INT          NULL DEFAULT NULL,
        `po_palet`     INT          NULL DEFAULT NULL,
        `po_koli`      INT          NULL DEFAULT NULL,
        `ll_palet`     INT          NULL DEFAULT NULL,
        `ll_koli`      INT          NULL DEFAULT NULL,
        `pc_koli`      INT          NULL DEFAULT NULL,
        `etkin_palet`  INT          NULL DEFAULT NULL,
        `etkin_koli`   INT          NULL DEFAULT NULL,
        `etkin_kaynak` VARCHAR(10)  NULL DEFAULT NULL,
        `fiyat`        DECIMAL(12,4) NULL DEFAULT NULL,
        `para_birimi`  VARCHAR(3)   NULL DEFAULT NULL,
        `fiyat_birim`  VARCHAR(10)  NULL DEFAULT NULL,
        `fiyat_miras`  TINYINT(1)   NOT NULL DEFAULT 0,
        `fiyat_tarihi` DATE         NULL DEFAULT NULL,
        `yerler_json`  LONGTEXT     NULL,
        `elle_alanlar` LONGTEXT     NULL,
        `kaynaklar`    LONGTEXT     NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_sk` (`sevkiyat_id`, `ian`, `kalibre`),
        INDEX `idx_sk_ian` (`ian`)
    )" . $son;

    $t['siparis_belgeler'] = "CREATE TABLE IF NOT EXISTS `siparis_belgeler` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `tur`           VARCHAR(10)  NOT NULL DEFAULT 'bilinmiyor',
        `kaynak`        VARCHAR(10)  NOT NULL DEFAULT 'yukleme',
        `durum`         VARCHAR(12)  NOT NULL DEFAULT 'yuklendi',
        `sha256`        CHAR(64)     NOT NULL,
        `disk_adi`      VARCHAR(40)  NOT NULL,
        `orijinal_ad`   VARCHAR(255) NOT NULL,
        `mime`          VARCHAR(100) NOT NULL,
        `boyut`         INT          NOT NULL DEFAULT 0,
        `belge_tarihi`  DATETIME     NULL DEFAULT NULL,
        `pdf_olusturma` DATETIME     NULL DEFAULT NULL,
        `cw_hafta`      TINYINT      NULL DEFAULT NULL,
        `cw_yil`        SMALLINT     NULL DEFAULT NULL,
        `tedarikci_no`  VARCHAR(20)  NULL DEFAULT NULL,
        `ozet`          VARCHAR(255) NULL DEFAULT NULL,
        `parser_surum`  VARCHAR(10)  NULL DEFAULT NULL,
        `parse_json`    LONGTEXT     NULL,
        `uyari_sayisi`  INT          NOT NULL DEFAULT 0,
        `hata_mesaj`    VARCHAR(255) NULL DEFAULT NULL,
        `depo`          VARCHAR(150) NOT NULL DEFAULT '',
        `yukleyen`      INT          NULL DEFAULT NULL,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at`    DATETIME     NULL DEFAULT NULL,
        `deleted_by`    INT          NULL DEFAULT NULL,
        UNIQUE KEY `uq_sb_sha` (`sha256`),
        INDEX `idx_sb_tur` (`tur`, `created_at`)
    )" . $son;

    // belge ↔ sevkiyat (bir PO = birçok sevkiyat); gecerli=0 → aynı türün eski revizyonu
    $t['siparis_belge_bag'] = "CREATE TABLE IF NOT EXISTS `siparis_belge_bag` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `belge_id`    INT        NOT NULL,
        `sevkiyat_id` INT        NOT NULL,
        `gecerli`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_sbb` (`belge_id`, `sevkiyat_id`),
        INDEX `idx_sbb_sev` (`sevkiyat_id`, `gecerli`)
    )" . $son;

    $t['siparis_catismalar'] = "CREATE TABLE IF NOT EXISTS `siparis_catismalar` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `sevkiyat_id`   INT          NOT NULL,
        `kalem_id`      INT          NULL DEFAULT NULL,
        `alan`          VARCHAR(40)  NOT NULL,
        `mevcut_deger`  VARCHAR(255) NULL DEFAULT NULL,
        `mevcut_kaynak` VARCHAR(10)  NULL DEFAULT NULL,
        `gelen_deger`   VARCHAR(255) NULL DEFAULT NULL,
        `gelen_kaynak`  VARCHAR(10)  NOT NULL,
        `belge_id`      INT          NULL DEFAULT NULL,
        `durum`         VARCHAR(10)  NOT NULL DEFAULT 'acik',
        `cozen`         INT          NULL DEFAULT NULL,
        `cozuldu_at`    DATETIME     NULL DEFAULT NULL,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_sc_sev` (`sevkiyat_id`, `durum`)
    )" . $son;

    // zaman çizelgesi (audit_log güvenlik günlüğü olarak ayrı kalır)
    $t['siparis_olaylar'] = "CREATE TABLE IF NOT EXISTS `siparis_olaylar` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `sevkiyat_id`  INT          NOT NULL,
        `tur`          VARCHAR(30)  NOT NULL,
        `eski`         VARCHAR(255) NULL DEFAULT NULL,
        `yeni`         VARCHAR(255) NULL DEFAULT NULL,
        `aciklama`     VARCHAR(255) NULL DEFAULT NULL,
        `belge_id`     INT          NULL DEFAULT NULL,
        `kullanici_id` INT          NULL DEFAULT NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_so_sev` (`sevkiyat_id`, `id`)
    )" . $son;

    // sevkiyat başına tek PKL
    $t['siparis_pkl'] = "CREATE TABLE IF NOT EXISTS `siparis_pkl` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `sevkiyat_id`    INT          NOT NULL,
        `durum`          VARCHAR(10)  NOT NULL DEFAULT 'taslak',
        `sezon`          VARCHAR(9)   NOT NULL DEFAULT '',
        `belge_no`       VARCHAR(20)  NULL DEFAULT NULL,
        `belge_no_sayi`  INT          NULL DEFAULT NULL,
        `palet_bas`      INT          NULL DEFAULT NULL,
        `palet_bit`      INT          NULL DEFAULT NULL,
        `belge_tarihi`   DATE         NULL DEFAULT NULL,
        `etd`            DATE         NULL DEFAULT NULL,
        `eta`            DATE         NULL DEFAULT NULL,
        `plakalar`       VARCHAR(60)  NULL DEFAULT NULL,
        `navlun_no`      VARCHAR(30)  NULL DEFAULT NULL,
        `alici_kodu`     VARCHAR(20)  NULL DEFAULT NULL,
        `alici_adi`      VARCHAR(100) NULL DEFAULT NULL,
        `veri_json`      LONGTEXT     NULL,
        `profil_id`      INT          NULL DEFAULT NULL,
        `dosya_belge_id` INT          NULL DEFAULT NULL,
        `dosya_adi`      VARCHAR(150) NULL DEFAULT NULL,
        `eksik_hucre`    INT          NOT NULL DEFAULT 0,
        `uretim_sayisi`  INT          NOT NULL DEFAULT 0,
        `uretildi_at`    DATETIME     NULL DEFAULT NULL,
        `uretildi_by`    INT          NULL DEFAULT NULL,
        `created_by`     INT          NULL DEFAULT NULL,
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_by`     INT          NULL DEFAULT NULL,
        `updated_at`     DATETIME     NULL DEFAULT NULL,
        UNIQUE KEY `uq_sp_sev` (`sevkiyat_id`),
        UNIQUE KEY `uq_sp_no` (`sezon`, `belge_no`),
        INDEX `idx_sp_palet` (`sezon`, `palet_bas`)
    )" . $son;

    // 'palet_<sezon>', 'belge_no_<sezon>'
    $t['siparis_sayaclar'] = "CREATE TABLE IF NOT EXISTS `siparis_sayaclar` (
        `ad`         VARCHAR(40) NOT NULL PRIMARY KEY,
        `son_deger`  INT         NOT NULL DEFAULT 0,
        `updated_by` INT         NULL DEFAULT NULL,
        `updated_at` DATETIME    NULL DEFAULT NULL
    )" . $son;

    // üretici / kalıntı profili → PKL V, W, X, Y, Z, AA, AB
    $t['siparis_profiller'] = "CREATE TABLE IF NOT EXISTS `siparis_profiller` (
        `id`                  INT AUTO_INCREMENT PRIMARY KEY,
        `ad`                  VARCHAR(100) NOT NULL,
        `uretici_adi`         VARCHAR(150) NULL DEFAULT NULL,
        `ggn`                 VARCHAR(20)  NULL DEFAULT NULL,
        `tarla_kodu`          VARCHAR(50)  NULL DEFAULT NULL,
        `izlenebilirlik_kodu` VARCHAR(60)  NULL DEFAULT NULL,
        `kalinti_rapor_no`    VARCHAR(60)  NULL DEFAULT NULL,
        `kalinti_tarihi`      DATE         NULL DEFAULT NULL,
        `lab_adi`             VARCHAR(150) NULL DEFAULT NULL,
        `aktif`               TINYINT(1)   NOT NULL DEFAULT 1,
        `created_by`          INT          NULL DEFAULT NULL,
        `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME     NULL DEFAULT NULL,
        `deleted_at`          DATETIME     NULL DEFAULT NULL,
        INDEX `idx_spr_ad` (`ad`)
    )" . $son;

    // bağ + geçmiş; "tek aktif bağ" kodda FOR UPDATE altında (UNIQUE yok)
    $t['siparis_beyan'] = "CREATE TABLE IF NOT EXISTS `siparis_beyan` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `sevkiyat_id`   INT          NOT NULL,
        `beyan_id`      INT          NOT NULL,
        `tur`           VARCHAR(10)  NOT NULL DEFAULT 'elle',
        `gerekce`       VARCHAR(255) NULL DEFAULT NULL,
        `durum`         VARCHAR(12)  NOT NULL DEFAULT 'aktif',
        `created_by`    INT          NULL DEFAULT NULL,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `kaldiran`      INT          NULL DEFAULT NULL,
        `kaldirildi_at` DATETIME     NULL DEFAULT NULL,
        INDEX `idx_sbe_sev` (`sevkiyat_id`, `durum`),
        INDEX `idx_sbe_bey` (`beyan_id`, `durum`)
    )" . $son;

    return $t;
}

/** Tablo var mı — MySQL + SQLite'ta çalışır, istek içinde yalnız "var" önbelleklenir. */
function siparis_tablo_var(PDO $pdo, string $tablo): bool
{
    static $var = [];
    $k = spl_object_id($pdo) . ':' . $tablo;
    if (isset($var[$k])) return true;
    if (!preg_match('/^[a-z_]+$/', $tablo)) return false;
    try {
        $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tüm sipariş tabloları kurulu mu. */
function siparis_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    foreach (array_keys(siparis_tablolar()) as $ad) {
        if (!siparis_tablo_var($pdo, $ad)) return false;
    }
    return true;
}

/**
 * İDEMPOTENT, yalnız CREATE TABLE. YALNIZ migrate.php (admin, CSRF, audit) çağırır.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function siparis_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (siparis_tablolar() as $ad => $sql) {
        if (siparis_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = siparis_tablo_var($pdo, $ad)
                ? ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']
                : ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'CREATE çalıştı ama tablo görünmüyor.'];
        } catch (PDOException $e) {
            error_log('[siparis_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata',
                        'mesaj' => 'İşlem tamamlanamadı. Teknik ayrıntılar sunucu günlüğüne kaydedildi.'];
        }
    }
    return $rapor;
}

// ── İÇ YARDIMCILAR (DB taşınabilirliği, JSON, transaction) ──────────────

/** ' FOR UPDATE' yalnız MySQL'de (SQLite sözdizimi tanımaz; testte tek bağlantı zaten seri). */
function siparis_kilit_sql(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

/** Şimdiki an (PHP saati; SQL NOW() SQLite'ta yok). */
function siparis_simdi(): string { return date('Y-m-d H:i:s'); }

/** LONGTEXT JSON yazımı — her yerde aynı bayraklar. */
function siparis_json(mixed $v): string
{
    return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** LONGTEXT JSON okuma — bozuk/boş → []. */
function siparis_json_coz(mixed $s): array
{
    if (!is_string($s) || $s === '') return [];
    $v = json_decode($s, true);
    return is_array($v) ? $v : [];
}

/** UNIQUE ihlali mi (MySQL 23000/1062, SQLite 23000). */
function siparis_tekrar_mi(PDOException $e): bool
{
    return (string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint') || str_contains($e->getMessage(), 'Duplicate entry');
}

/**
 * Transaction sarmalayıcı: dışarıda transaction varsa ona KATILIR (erken commit yok),
 * yoksa açar, başarıda commit, istisnada rollback + yeniden fırlatır.
 * @template T @param callable():T $fn @return T
 */
function siparis_tx(PDO $pdo, callable $fn): mixed
{
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Metni en çok $n karaktere kırpar (mb güvenli); null → null. */
function siparis_kirp(?string $s, int $n = 255): ?string
{
    if ($s === null) return null;
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') : $s;
}

/** audit_log_event sarmalayıcısı — modül 'siparis', eylem ≤ 40 karakter. */
function siparis_audit(string $eylem, ?int $kayitId, ?array $eski, ?array $yeni, ?int $uid): void
{
    if (!function_exists('audit_log_event')) return;
    audit_log_event(substr($eylem, 0, 40), 'siparis', $kayitId, $eski, $yeni, $uid);
}

// ── SAYFA KAPISI + VARLIKLAR ────────────────────────────────────────────

/**
 * Sipariş sayfası/uç kapısı: oturum → aktif depo → can_siparis($perm). Başarısızsa
 * 403 (forbidden() AJAX-aware). Sidebar/bottomnav/index kartı AYNI can_siparis()'i
 * çağırdığı için "görünür ama 403" oluşmaz.
 */
function require_siparis(string $perm, string $page = 'ogl'): void
{
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_active_depot();
    if (!can_siparis($perm) || !role_matrix_page_can('siparis',$page,'read') || ($perm === 'delete' && !role_matrix_page_can('siparis',$page,'delete'))) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: siparis.{$perm})");
    }
}

/** Modül CSS'i — render_header()'dan SONRA çağrılır. */
function siparis_assets(): void
{
    $f = dirname(__DIR__) . '/assets/siparis.css';
    echo '<link rel="stylesheet" href="assets/siparis.css?v=' . (int)@filemtime($f) . '">' . "\n";
}

/** Modül JS'i — render_footer()'dan ÖNCE çağrılır. */
function siparis_scripts(): void
{
    $f = dirname(__DIR__) . '/assets/siparis.js';
    echo '<script src="assets/siparis.js?v=' . (int)@filemtime($f) . '" defer></script>' . "\n";
}

/** Sipariş belgesi/verisi taşıyan yanıt önbelleğe YAZILMAZ (sw.js de siparis*.php'yi atlar). */
function siparis_no_store(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** Tablolar yokken sayfaların gösterdiği kutu (yönetici migrate.php kartına yönlendirilir). */
function siparis_kurulmadi_html(): string
{
    $adm = function_exists('is_admin') && is_admin();
    $h = '<div class="card sip-kurulmadi" role="status"><h2>📦 Sipariş modülü henüz kurulmadı</h2>'
       . '<p class="muted">Veritabanı tabloları oluşturulmadan bu ekran kullanılamaz.</p>';
    if ($adm) $h .= '<p><a class="btn btn-primary" href="migrate.php#siparis">Şema panelinde kur</a></p>';
    else $h .= '<p class="muted">Kurulum için sistem yöneticisine başvurun.</p>';
    return $h . '</div>';
}

// ── DURUMLAR ────────────────────────────────────────────────────────────

/** @return array<string,array{etiket:string,ikon:string,css:string,sira:int}> akış sırası */
function siparis_durumlar(): array
{
    return [
        'siparis_alindi'  => ['etiket' => 'Sipariş alındı',  'ikon' => '📥', 'css' => 'alindi',   'sira' => 1],
        'hazirlik'        => ['etiket' => 'Hazırlık',        'ikon' => '🧺', 'css' => 'hazirlik', 'sira' => 2],
        'arac_atandi'     => ['etiket' => 'Araç atandı',     'ikon' => '🚚', 'css' => 'arac',     'sira' => 3],
        'yola_cikti'      => ['etiket' => 'Yola çıktı',      'ikon' => '🛣', 'css' => 'yolda',    'sira' => 4],
        'pkl_hazir'       => ['etiket' => 'PKL hazır',       'ikon' => '📄', 'css' => 'pkl',      'sira' => 5],
        'mail_gonderildi' => ['etiket' => 'Mail gönderildi', 'ikon' => '✅', 'css' => 'mail',     'sira' => 6],
        'evrak_gonderildi' => ['etiket' => 'Evrak takımı',   'ikon' => '📦', 'css' => 'evrak',    'sira' => 7],
        'iptal'           => ['etiket' => 'İptal',           'ikon' => '✖',  'css' => 'iptal',    'sira' => 0],
    ];
}

/**
 * @return list<string> süreci kapatan durumlar (v400: "Mail gönderildi" artık kapatmaz — son adım "Evrak takımı").
 * Kapalı/kapanmamış ayrımı YALNIZ buradan ve siparis_kapanmamis_sql()'den okunur; durum adlarını başka yere sabit yazma.
 */
function siparis_kapali_durumlar(): array { return ['evrak_gonderildi', 'iptal']; }

/** @return list<string> v400 — PKL maili gönderilmiş, evrak takımı bekleyen durumlar (açık değil, kapanmış da değil). */
function siparis_evrak_bekleyen_durumlar(): array { return ['mail_gonderildi']; }

/** v400 — "süreci kapanmamış" SQL koşulu ($kolon beyaz liste/sabit olmalı; kullanıcı girdisi DEĞİL). */
function siparis_kapanmamis_sql(string $kolon = 's.durum'): string
{
    return $kolon . " NOT IN ('" . implode("','", siparis_kapali_durumlar()) . "')";
}

/** Durum rozeti — HER ZAMAN ikon + metin (renge tek başına güvenilmez). */
function siparis_durum_badge(string $durum): string
{
    $d = siparis_durumlar()[$durum] ?? null;
    if ($d === null) return '<span class="sip-badge sip-badge--bilinmiyor">' . h($durum) . '</span>';
    return '<span class="sip-badge sip-badge--' . h($d['css']) . '">' . $d['ikon'] . ' ' . h($d['etiket']) . '</span>';
}

/** @return array{adim:int,toplam:int,yuzde:int} — iptal → adim 0. */
function siparis_ilerleme(array $sev): array
{
    $sira = siparis_durumlar()[(string)($sev['durum'] ?? '')]['sira'] ?? 0;
    $toplam = max(array_column(siparis_durumlar(), 'sira'));
    return ['adim' => $sira, 'toplam' => $toplam, 'yuzde' => (int)round($sira / $toplam * 100)];
}

/** @return list<'po'|'ll'|'pc'|'pkl'> var_* bayrağı 0 olan belgeler */
function siparis_eksik_belgeler(array $sev): array
{
    $e = [];
    foreach (['po', 'll', 'pc', 'pkl'] as $k) if ((int)($sev['var_' . $k] ?? 0) !== 1) $e[] = $k;
    return $e;
}

/**
 * OTOMATİK, yalnız İLERİ: kapalı/iptal durumda ya da mevcut sıra ≥ hedef ise hiçbir şey yapmaz.
 * 'durum' olayı yazar, audit YAZMAZ (otomatik akış). @return bool değişti mi
 */
function siparis_durum_ilerlet(PDO $pdo, int $sid, string $hedef, ?int $uid, string $neden, ?int $belgeId = null): bool
{
    $tum = siparis_durumlar();
    if (!isset($tum[$hedef]) || in_array($hedef, ['iptal', 'mail_gonderildi', 'evrak_gonderildi'], true)) return false;   // bu üçü yalnız ELLE
    $st = $pdo->prepare('SELECT durum FROM siparis_sevkiyatlar WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$sid]);
    $mevcut = $st->fetchColumn();
    if ($mevcut === false) return false;
    $mevcut = (string)$mevcut;
    if (in_array($mevcut, siparis_kapali_durumlar(), true)) return false;
    if (($tum[$mevcut]['sira'] ?? 0) >= $tum[$hedef]['sira']) return false;
    $simdi = siparis_simdi();
    $pdo->prepare('UPDATE siparis_sevkiyatlar SET durum = ?, durum_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$hedef, $simdi, $simdi, $sid]);
    siparis_olay($pdo, $sid, 'durum', $mevcut, $hedef, $neden, $belgeId, $uid);
    return true;
}

/**
 * ELLE durum değişikliği (beyaz liste). iptal ve kapalı durumdan çıkış gerekçe (≥3) ister;
 * yola_cikti → yola_cikis_at; mail_gonderildi → var_pkl=1 şart, kısmi PKL'de ($eksik_hucre>0)
 * ayrıca $kismiOnay; iptal → iptal_nedeni. Olay + audit yazar.
 * v400: evrak_gonderildi YALNIZ mail_gonderildi'den işaretlenir (mail_gonderildi_at'e dokunmaz); evrak → mail_gonderildi
 * geri alma (gerekçeli) PKL kontrolü yapmaz ve mail_gonderildi_at/_by'yi DEĞİŞTİRMEZ. "Mail gönderildi" audit'i
 * new_values'ta `evrak_akisi:1` taşır — migrate.php toplu geçiş kartı v400 ÖNCESİ kapanmışları bu işaretten ayırır.
 * @return array{ok:bool,hata?:string}
 */
function siparis_durum_degistir(PDO $pdo, int $sid, string $hedef, int $uid, string $gerekce = '', bool $kismiOnay = false): array
{
    $tum = siparis_durumlar();
    if (!isset($tum[$hedef])) return ['ok' => false, 'hata' => 'Geçersiz durum.'];
    $gerekce = trim($gerekce);
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $hedef, $uid, $gerekce, $kismiOnay, $tum): array {
            $st = $pdo->prepare('SELECT * FROM siparis_sevkiyatlar WHERE id = ? AND deleted_at IS NULL' . siparis_kilit_sql($pdo));
            $st->execute([$sid]);
            $s = $st->fetch(PDO::FETCH_ASSOC);
            if (!$s) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
            $eski = (string)$s['durum'];
            if ($eski === $hedef) return ['ok' => false, 'hata' => 'Sevkiyat zaten bu durumda.'];
            if ($hedef === 'evrak_gonderildi' && $eski !== 'mail_gonderildi') {
                return ['ok' => false, 'hata' => 'Evrak takımı yalnız "Mail gönderildi" adımındaki sevkiyatta işaretlenebilir.'];
            }
            $geriAl = $hedef === 'mail_gonderildi' && $eski === 'evrak_gonderildi';   // evrak takımını geri al
            $kapalidan = in_array($eski, siparis_kapali_durumlar(), true);
            if (($hedef === 'iptal' || $kapalidan) && mb_strlen($gerekce, 'UTF-8') < 3) {
                return ['ok' => false, 'hata' => $hedef === 'iptal'
                    ? 'İptal için gerekçe yazın (en az 3 karakter).'
                    : 'Kapanmış bir sevkiyatı yeniden açmak için gerekçe yazın (en az 3 karakter).'];
            }
            $simdi = siparis_simdi();
            $set = ['durum = ?', 'durum_at = ?', 'updated_at = ?', 'updated_by = ?'];
            $par = [$hedef, $simdi, $simdi, $uid];
            if ($hedef === 'mail_gonderildi' && !$geriAl) {
                if ((int)$s['var_pkl'] !== 1) return ['ok' => false, 'hata' => 'Önce PKL oluşturun ya da yükleyin.'];
                $pk = $pdo->prepare('SELECT eksik_hucre FROM siparis_pkl WHERE sevkiyat_id = ?');
                $pk->execute([$sid]);
                $eksik = (int)($pk->fetchColumn() ?: 0);
                if ($eksik > 0 && !$kismiOnay) {
                    return ['ok' => false, 'hata' => "PKL'de {$eksik} boş hücre var — Excel'de tamamladığınızı onaylayın."];
                }
                $set[] = 'mail_gonderildi_at = ?'; $par[] = $simdi;
                $set[] = 'mail_gonderildi_by = ?'; $par[] = $uid;
            }
            if ($hedef === 'yola_cikti') { $set[] = 'yola_cikis_at = ?'; $par[] = $simdi; }
            if ($hedef === 'iptal') { $set[] = 'iptal_nedeni = ?'; $par[] = siparis_kirp($gerekce); }
            elseif ($eski === 'iptal') { $set[] = 'iptal_nedeni = NULL'; }
            $par[] = $sid;
            $pdo->prepare('UPDATE siparis_sevkiyatlar SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            if ($hedef === 'mail_gonderildi' && !$geriAl) {
                siparis_olay($pdo, $sid, 'mail_gonderildi', $eski, $hedef, $gerekce !== '' ? $gerekce : 'PKL müşteriye gönderildi (elle işaret)', null, $uid);
                siparis_audit('siparis_mail_gonderildi', $sid, ['durum' => $eski], ['durum' => $hedef, 'order_ref' => $s['order_ref'], 'kismi' => $kismiOnay ? 1 : 0, 'evrak_akisi' => 1], $uid);
            } elseif ($hedef === 'evrak_gonderildi') {
                siparis_olay($pdo, $sid, 'evrak_gonderildi', $eski, $hedef, $gerekce !== '' ? $gerekce : 'Evrak takımı müşteriye gönderildi (elle işaret)', null, $uid);
                siparis_audit('siparis_evrak_gonderildi', $sid, ['durum' => $eski], ['durum' => $hedef, 'order_ref' => $s['order_ref']], $uid);
            } else {
                siparis_olay($pdo, $sid, 'durum', $eski, $hedef, $gerekce !== '' ? $gerekce : 'Elle değiştirildi', null, $uid);
                siparis_audit('siparis_durum', $sid, ['durum' => $eski], ['durum' => $hedef, 'order_ref' => $s['order_ref']], $uid);
            }
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_durum_degistir] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Durum kaydedilemedi.'];
    }
}

// ── OLAYLAR + OKUMA ─────────────────────────────────────────────────────

/** Zaman çizelgesine satır yazar; metinler 255 karakterde kırpılır. Bilinmeyen tür = programlama hatası. */
function siparis_olay(PDO $pdo, int $sid, string $tur, ?string $eski, ?string $yeni, string $aciklama = '', ?int $belgeId = null, ?int $uid = null): void
{
    if (!in_array($tur, SIPARIS_OLAY_TURLERI, true)) throw new InvalidArgumentException('Bilinmeyen olay türü: ' . $tur);
    $pdo->prepare('INSERT INTO siparis_olaylar (sevkiyat_id, tur, eski, yeni, aciklama, belge_id, kullanici_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sid, $tur, siparis_kirp($eski), siparis_kirp($yeni), siparis_kirp($aciklama === '' ? null : $aciklama),
                   $belgeId, $uid, siparis_simdi()]);
}

/** @return list<array> en yeni önce, kullanici_adi ile */
function siparis_olaylar(PDO $pdo, int $sid, int $limit = 200): array
{
    $limit = max(1, min(5000, $limit));
    $st = $pdo->prepare('SELECT o.*, COALESCE(u.display_name, u.username) AS kullanici_adi
        FROM siparis_olaylar o LEFT JOIN users u ON u.id = o.kullanici_id
        WHERE o.sevkiyat_id = ? ORDER BY o.id DESC LIMIT ' . $limit);
    $st->execute([$sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Silinmemiş sevkiyat + çözülmüş _elle / _kaynak / _uyarilar; yoksa null. */
function siparis_sevkiyat(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM siparis_sevkiyatlar WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['_elle'] = siparis_json_coz($r['elle_alanlar']);
    $r['_kaynak'] = siparis_json_coz($r['kaynaklar']);
    $r['_uyarilar'] = siparis_json_coz($r['uyarilar_json']);
    return $r;
}

/** Aktif depo kuralı: boş depo her depoda görünür. */
function siparis_gorunur(array $sev): bool
{
    return depot_visible_to_user((string)($sev['depo'] ?? ''));
}

/** @return list<array> sira, sonra kalibre (sayısal) sırasıyla; _elle/_kaynak/_yerler çözülmüş */
function siparis_kalemler(PDO $pdo, int $sid): array
{
    $st = $pdo->prepare('SELECT * FROM siparis_kalemler WHERE sevkiyat_id = ? ORDER BY sira, (kalibre + 0), kalibre, id');
    $st->execute([$sid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['_elle'] = siparis_json_coz($r['elle_alanlar']);
        $r['_kaynak'] = siparis_json_coz($r['kaynaklar']);
        $r['_yerler'] = siparis_json_coz($r['yerler_json']);
        $out[] = $r;
    }
    return $out;
}

/** @return list<array> sevkiyata bağlı (silinmemiş) belgeler + gecerli; $eskiDahil=false → yalnız geçerli revizyonlar */
function siparis_belgeler(PDO $pdo, int $sid, bool $eskiDahil = true): array
{
    $st = $pdo->prepare('SELECT b.*, bb.gecerli FROM siparis_belge_bag bb JOIN siparis_belgeler b ON b.id = bb.belge_id
        WHERE bb.sevkiyat_id = ? AND b.deleted_at IS NULL' . ($eskiDahil ? '' : ' AND bb.gecerli = 1') . '
        ORDER BY b.tur, bb.gecerli DESC, b.belge_tarihi DESC, b.id DESC');
    $st->execute([$sid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array> çatışmalar ($durum null → hepsi), en yeni önce */
function siparis_catismalar(PDO $pdo, int $sid, ?string $durum = 'acik'): array
{
    if ($durum === null) {
        $st = $pdo->prepare('SELECT * FROM siparis_catismalar WHERE sevkiyat_id = ? ORDER BY id DESC');
        $st->execute([$sid]);
    } else {
        $st = $pdo->prepare('SELECT * FROM siparis_catismalar WHERE sevkiyat_id = ? AND durum = ? ORDER BY id DESC');
        $st->execute([$sid, $durum]);
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Belge bu kullanıcıya görünür mü: silinmemiş ve GÖRÜNÜR en az bir sevkiyata bağlıysa evet;
 * hiç bağı yoksa belgenin kendi deposuna bakılır (boş depo = her depoda).
 */
function siparis_belge_gorunur(PDO $pdo, array $belge): bool
{
    $st = $pdo->prepare('SELECT s.depo FROM siparis_belge_bag bb JOIN siparis_sevkiyatlar s ON s.id = bb.sevkiyat_id
        WHERE bb.belge_id = ? AND s.deleted_at IS NULL');
    $st->execute([(int)($belge['id'] ?? 0)]);
    $depolar = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$depolar) return depot_visible_to_user((string)($belge['depo'] ?? ''));
    foreach ($depolar as $d) if (siparis_gorunur(['depo' => (string)$d])) return true;
    return false;
}

// ── DOSYA DEPOSU ────────────────────────────────────────────────────────

/** Klasörü (0750) kurar, eksik ya da eski (Require içermeyen) .htaccess'i yeniden yazar. */
function siparis_depo_hazirla(): bool
{
    $dir = rtrim(SIPARIS_DEPO_DIR, '/') . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $ht = $dir . '.htaccess';
    $mevcut = is_file($ht) ? (string)@file_get_contents($ht) : '';
    if (!str_contains($mevcut, 'Require all denied')) {
        if (@file_put_contents($ht, SIPARIS_DEPO_HTACCESS) === false) return false;
    }
    return is_writable($dir);
}

/** @return array<string,string> izinli uzantı → cins */
function siparis_dosya_uzantilari(): array
{
    return ['pdf' => 'pdf', 'xlsx' => 'xlsx', 'jpg' => 'resim', 'jpeg' => 'resim', 'png' => 'resim', 'webp' => 'resim'];
}

/** @return array<string,list<string>> cins → izinli finfo MIME'ları (xlsx: libmagic sürümüne göre zip/octet-stream da raporlanır; yapı ayrıca denetlenir) */
function siparis_dosya_mimeleri(): array
{
    return [
        'pdf'   => ['application/pdf'],
        'xlsx'  => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'resim' => ['image/jpeg', 'image/png', 'image/webp'],
    ];
}

/**
 * Yüklenen dosyayı doğrular (yükleme hatası, boyut, uzantı, finfo MIME, sihirli bayt,
 * xlsx zip-bomba sınırları, görsel okunabilirliği).
 * @return array{ok:bool,hata?:string,ext?:string,mime?:string,cins?:'pdf'|'xlsx'|'resim'}
 */
function siparis_dosya_dogrula(array $file): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $m = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya çok büyük (en çok ' . (int)(SIPARIS_MAX_DOSYA / 1048576) . ' MB).',
            UPLOAD_ERR_PARTIAL    => 'Dosya yarım yüklendi — yeniden deneyin.',
            UPLOAD_ERR_NO_FILE    => 'Dosya seçilmedi.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayı geçici olarak kaydedemedi.',
            UPLOAD_ERR_EXTENSION  => 'Dosya sunucu tarafından engellendi.',
            default               => 'Dosya yüklenemedi.',
        };
        return ['ok' => false, 'hata' => $m];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return ['ok' => false, 'hata' => 'Dosya okunamadı.'];
    $boyut = (int)@filesize($tmp);
    if ($boyut < 1) return ['ok' => false, 'hata' => 'Dosya boş.'];
    if ($boyut > SIPARIS_MAX_DOSYA) return ['ok' => false, 'hata' => 'Dosya çok büyük (en çok ' . (int)(SIPARIS_MAX_DOSYA / 1048576) . ' MB).'];

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $cins = siparis_dosya_uzantilari()[$ext] ?? null;
    if ($cins === null) return ['ok' => false, 'hata' => 'Yalnız PDF, XLSX, JPG, PNG ya da WEBP yüklenebilir.'];

    $mime = '';
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($tmp);
    }
    if (!in_array($mime, siparis_dosya_mimeleri()[$cins], true)) {
        return ['ok' => false, 'hata' => 'Dosya içeriği uzantısıyla uyuşmuyor.'];
    }
    $bas = (string)@file_get_contents($tmp, false, null, 0, 8);
    if ($cins === 'pdf') {
        if (strncmp($bas, '%PDF-', 5) !== 0) return ['ok' => false, 'hata' => 'Geçerli bir PDF değil.'];
    } elseif ($cins === 'xlsx') {
        if (strncmp($bas, "PK\x03\x04", 4) !== 0 || !class_exists('ZipArchive')) return ['ok' => false, 'hata' => 'Geçerli bir Excel (xlsx) dosyası değil.'];
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) return ['ok' => false, 'hata' => 'Excel dosyası açılamadı.'];
        $sorun = null;
        if ($z->numFiles >= 200) $sorun = 'Excel dosyası beklenmedik sayıda parça içeriyor.';
        elseif ($z->locateName('[Content_Types].xml') === false || $z->locateName('xl/workbook.xml') === false) $sorun = 'Geçerli bir Excel (xlsx) dosyası değil.';
        else {
            $s1 = $z->statName('xl/worksheets/sheet1.xml');
            if ($s1 === false) $sorun = 'Excel dosyasında çalışma sayfası yok.';
            elseif ((int)$s1['size'] > 40 * 1024 * 1024) $sorun = 'Excel sayfası çok büyük.';
        }
        $z->close();
        if ($sorun !== null) return ['ok' => false, 'hata' => $sorun];
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
        if (@getimagesize($tmp) === false) return ['ok' => false, 'hata' => 'Görsel okunamadı.'];
    }
    return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'cins' => $cins];
}

/**
 * Dosyayı depoya yazar ve belge satırını açar (tur='bilinmiyor', durum='yuklendi').
 * Aynı sha256 varsa YENİ satır açılmaz: silinmişse geri getirilir, yeni=false döner.
 * $mover varsayılanı move_uploaded_file; testler copy enjekte eder.
 * $kaynak (v378): belgenin kökeni — yalnız beyaz liste ['yukleme','mail']; bilinmeyen değer 'yukleme' sayılır.
 * @return array{ok:bool,belge_id?:int,yeni?:bool,hata?:string}
 */
function siparis_dosya_kaydet(PDO $pdo, array $file, int $uid, string $depo, ?callable $mover = null, string $kaynak = 'yukleme'): array
{
    if (!in_array($kaynak, ['yukleme', 'mail'], true)) $kaynak = 'yukleme';
    $d = siparis_dosya_dogrula($file);
    if (!$d['ok']) return ['ok' => false, 'hata' => $d['hata'] ?? 'Dosya geçersiz.'];
    $tmp = (string)$file['tmp_name'];
    $sha = (string)hash_file('sha256', $tmp);
    try {
        $st = $pdo->prepare('SELECT id, deleted_at FROM siparis_belgeler WHERE sha256 = ?');
        $st->execute([$sha]);
        $var = $st->fetch(PDO::FETCH_ASSOC);
        if ($var) {
            if ($var['deleted_at'] !== null) {
                $pdo->prepare('UPDATE siparis_belgeler SET deleted_at = NULL, deleted_by = NULL WHERE id = ?')->execute([(int)$var['id']]);
            }
            return ['ok' => true, 'belge_id' => (int)$var['id'], 'yeni' => false];
        }
        if (!siparis_depo_hazirla()) return ['ok' => false, 'hata' => 'Belge klasörü hazırlanamadı.'];
        $ad = bin2hex(random_bytes(16)) . '.' . $d['ext'];
        $hedef = rtrim(SIPARIS_DEPO_DIR, '/') . '/' . $ad;
        $mover = $mover ?? 'move_uploaded_file';
        if (!$mover($tmp, $hedef) || !is_file($hedef)) return ['ok' => false, 'hata' => 'Dosya kaydedilemedi.'];
        @chmod($hedef, 0640);
        $orj = basename(str_replace('\\', '/', (string)($file['name'] ?? 'belge')));
        $orj = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $orj);
        $orj = siparis_kirp($orj !== '' ? $orj : 'belge.' . $d['ext']);
        try {
            $pdo->prepare('INSERT INTO siparis_belgeler (tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, boyut, depo, yukleyen, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['bilinmiyor', $kaynak, 'yuklendi', $sha, $ad, $orj, (string)$d['mime'], (int)filesize($hedef), $depo, $uid, siparis_simdi()]);
        } catch (PDOException $e) {
            @unlink($hedef);
            if (!siparis_tekrar_mi($e)) throw $e;
            $st->execute([$sha]);                       // eşzamanlı aynı dosya: diğerinin satırını kullan
            $id = (int)$st->fetchColumn();
            return $id > 0 ? ['ok' => true, 'belge_id' => $id, 'yeni' => false] : ['ok' => false, 'hata' => 'Belge kaydedilemedi.'];
        }
        return ['ok' => true, 'belge_id' => (int)$pdo->lastInsertId(), 'yeni' => true];
    } catch (PDOException $e) {
        error_log('[siparis_dosya_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Belge kaydedilemedi.'];
    }
}

/** Belgenin disk yolu — disk_adi beyaz liste regex'i + realpath klasör içi denetimi; uymazsa null. */
function siparis_dosya_yolu(array $belge): ?string
{
    $ad = (string)($belge['disk_adi'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}\.(pdf|xlsx|jpg|jpeg|png|webp)$/', $ad)) return null;
    $kok = realpath(SIPARIS_DEPO_DIR);
    if ($kok === false) return null;
    $yol = realpath($kok . '/' . $ad);
    if ($yol === false || !is_file($yol)) return null;
    if (!str_starts_with($yol, rtrim($kok, '/\\') . DIRECTORY_SEPARATOR)) return null;
    return $yol;
}

// ── SEZON SAYAÇLARI ─────────────────────────────────────────────────────

/** Sezon: ay ≥ SIPARIS_SEZON_AY → o yıl, değilse önceki yıl ("2026"). $ymd null → bugün. */
function siparis_sezon(?string $ymd): string
{
    $t = ($ymd !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $ymd)) ? strtotime(substr($ymd, 0, 10)) : time();
    if ($t === false) $t = time();
    $y = (int)date('Y', $t);
    return (string)((int)date('n', $t) >= SIPARIS_SEZON_AY ? $y : $y - 1);
}

/** Sayacın son değeri (yoksa 0). */
function siparis_sayac_oku(PDO $pdo, string $ad): int
{
    $st = $pdo->prepare('SELECT son_deger FROM siparis_sayaclar WHERE ad = ?');
    $st->execute([$ad]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Sayaç satırını kilitler (MySQL FOR UPDATE; yoksa 0 ile açar) ve son değeri döner. Transaction İÇİNDE çağrılır. */
function siparis_sayac_kilitle(PDO $pdo, string $ad): int
{
    $st = $pdo->prepare('SELECT son_deger FROM siparis_sayaclar WHERE ad = ?' . siparis_kilit_sql($pdo));
    $st->execute([$ad]);
    $v = $st->fetchColumn();
    if ($v !== false) return (int)$v;
    try {
        $pdo->prepare('INSERT INTO siparis_sayaclar (ad, son_deger, updated_at) VALUES (?, 0, ?)')->execute([$ad, siparis_simdi()]);
    } catch (PDOException $e) {
        if (!siparis_tekrar_mi($e)) throw $e;
    }
    $st->execute([$ad]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Sayaçtan [bas, bit] aralığı ayırır. Transaction İÇİNDE çağrılmalıdır.
 * bas = $istenenBas ?? son+1; aynı sezonda başka bir PKL'in aralığıyla (palet_*) / numarasıyla
 * (belge_no_*) çakışırsa RuntimeException (Türkçe). son = max(son, bit).
 * @return array{bas:int,bit:int}
 */
function siparis_sayac_rezerve(PDO $pdo, string $ad, int $adet, ?int $istenenBas, string $sezon, ?int $haricPklId): array
{
    if (!$pdo->inTransaction()) throw new LogicException('siparis_sayac_rezerve transaction içinde çağrılmalı.');
    if ($adet < 1) throw new RuntimeException('Ayrılacak adet en az 1 olmalı.');
    $son = siparis_sayac_kilitle($pdo, $ad);
    $bas = $istenenBas ?? ($son + 1);
    if ($bas < 1) throw new RuntimeException('Başlangıç numarası en az 1 olmalı.');
    $bit = $bas + $adet - 1;
    siparis_aralik_cakisma_denetle($pdo, $ad, $bas, $bit, $sezon, $haricPklId);
    $pdo->prepare('UPDATE siparis_sayaclar SET son_deger = ?, updated_at = ? WHERE ad = ?')
        ->execute([max($son, $bit), siparis_simdi(), $ad]);
    return ['bas' => $bas, 'bit' => $bit];
}

/** [bas,bit] aynı sezonda başka PKL'le çakışıyorsa RuntimeException. palet_* → palet aralığı, belge_no_* → belge numarası. */
function siparis_aralik_cakisma_denetle(PDO $pdo, string $ad, int $bas, int $bit, string $sezon, ?int $haricPklId): void
{
    $harc = $haricPklId ?? 0;
    if (str_starts_with($ad, 'palet_')) {
        $st = $pdo->prepare('SELECT belge_no, palet_bas, palet_bit FROM siparis_pkl WHERE sezon = ? AND id <> ?
            AND palet_bas IS NOT NULL AND palet_bit IS NOT NULL AND palet_bas <= ? AND palet_bit >= ? LIMIT 1');
        $st->execute([$sezon, $harc, $bit, $bas]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) throw new RuntimeException("Palet aralığı {$bas}–{$bit}, " . ($c['belge_no'] ?: 'başka bir PKL') . " ({$c['palet_bas']}–{$c['palet_bit']}) ile çakışıyor.");
    } elseif (str_starts_with($ad, 'belge_no_')) {
        $st = $pdo->prepare('SELECT belge_no FROM siparis_pkl WHERE sezon = ? AND id <> ? AND belge_no_sayi IS NOT NULL
            AND belge_no_sayi >= ? AND belge_no_sayi <= ? LIMIT 1');
        $st->execute([$sezon, $harc, $bas, $bit]);
        $c = $st->fetchColumn();
        if ($c !== false) throw new RuntimeException('Belge numarası ' . siparis_belge_no_bicim($bas) . ' zaten kullanılıyor (' . $c . ').');
    }
}

/** Yönetici düzeltmesi (Ayarlar, siparis.delete) — audit siparis_sayac_duzelt. */
function siparis_sayac_ayarla(PDO $pdo, string $ad, int $deger, int $uid): void
{
    if (!preg_match('/^(palet|belge_no)_\d{4}$/', $ad)) throw new InvalidArgumentException('Geçersiz sayaç adı.');
    if ($deger < 0) throw new InvalidArgumentException('Sayaç değeri negatif olamaz.');
    siparis_tx($pdo, function () use ($pdo, $ad, $deger, $uid): void {
        $eski = siparis_sayac_kilitle($pdo, $ad);
        $pdo->prepare('UPDATE siparis_sayaclar SET son_deger = ?, updated_by = ?, updated_at = ? WHERE ad = ?')
            ->execute([$deger, $uid, siparis_simdi(), $ad]);
        siparis_audit('siparis_sayac_duzelt', null, ['ad' => $ad, 'deger' => $eski], ['ad' => $ad, 'deger' => $deger], $uid);
    });
}

/** "AsyaDoc005" */
function siparis_belge_no_bicim(int $n): string
{
    return SIPARIS_BELGE_NO_ONEK . str_pad((string)$n, SIPARIS_BELGE_NO_HANE, '0', STR_PAD_LEFT);
}

// ── PLAKA ───────────────────────────────────────────────────────────────

/**
 * Plaka dizisi biçimi: "Çekici-Dorse" (boşluksuz, büyük harf, '-' ile). Ayraçlar - / , ; |
 * ya da iki plaka arasında boşluk. Türk plakası kalıbına uymayan parça YALNIZ uyarı üretir
 * (yabancı plakaya izin var). @return array{deger:?string,uyari:?string}
 */
function siparis_plaka_normalize(?string $s): array
{
    $s = trim((string)$s);
    if ($s === '') return ['deger' => null, 'uyari' => null];
    $kalip = '/^\d{2}[A-Z]{1,3}\d{2,5}$/';
    $parcalar = [];
    foreach (preg_split('/[\-\/,;|]+/', $s) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $n = beyan_plaka_normalize($p);
        if (!preg_match($kalip, $n) && preg_match('/\s/u', $p)) {
            // "34 ABC 123  34 DEF 45" (2+ boşluk) ya da "33AHU899 33AHU910" (tam plakalar arası tek boşluk)
            foreach (['/\s{2,}/u', '/\s+/u'] as $ayrac) {
                $alt = array_values(array_filter(array_map('beyan_plaka_normalize', preg_split($ayrac, $p) ?: []), fn($x) => $x !== ''));
                if (count($alt) > 1 && count(array_filter($alt, fn($x) => preg_match($kalip, $x) === 1)) === count($alt)) {
                    foreach ($alt as $a) $parcalar[] = $a;
                    continue 2;
                }
            }
        }
        if ($n !== '') $parcalar[] = $n;
    }
    if (!$parcalar) return ['deger' => null, 'uyari' => null];
    $kotu = array_values(array_filter($parcalar, fn($x) => preg_match($kalip, $x) !== 1));
    $deger = siparis_kirp(implode('-', $parcalar), 60);
    return ['deger' => $deger, 'uyari' => $kotu ? 'Plaka biçimi beklenenden farklı: ' . implode(', ', $kotu) . ' (yabancı plaka olabilir).' : null];
}

// ── URL ─────────────────────────────────────────────────────────────────

/**
 * v382 — Liste / yazdırma ORTAK filtresi (siparisler.php + siparis_yazdir.php AYNI fonksiyonu çağırır; kopya YOK).
 * Saf: yalnız doğrulanmış filtre değerleri + SQL parçası + parametre üretir (DB'ye dokunmaz; aktif depo süzgeci depo_sql_column()'dan).
 * Parametre adları: :q1..:q6 · :cwh/:cwy · :alici · :po · :tb · :tt · :udm (named — pozisyonel ? ile KARIŞTIRMA, HY093).
 * `acik_page` / `evrak_page` / `kapali_page` burada DEĞİL (yalnız sayfada).
 * v400: üç bölüm — açık (acikSql) · evrak bekleyen (evrakSql) · kapalı (kapaliSql); durum=evrak_bekleyen yalnız evrak bölümünü gösterir.
 * @return array{q:string,f_durum:string,f_cw:string,f_alici:string,f_eksik:string,f_catisma:bool,f_po:int,t_bas:string,t_bit:string,
 *   kapaliSql:string,acikSql:string,evrakSql:string,temelWhere:string,temelPar:array,dprm:array,dsql:string,
 *   tek:bool,tekSql:string,bolumAcik:bool,bolumEvrak:bool,bolumKapali:bool,detayFiltre:bool,filtreVar:bool,detayOzet:list<string>}
 */
function siparis_liste_filtre(array $get): array
{
    $q          = trim((string)($get['q'] ?? ''));
    $f_durum    = trim((string)($get['durum'] ?? ''));
    $f_cw       = trim((string)($get['cw'] ?? ''));
    $f_alici    = trim((string)($get['alici'] ?? ''));
    $f_eksik    = trim((string)($get['eksik'] ?? ''));
    $f_catisma  = (string)($get['catisma'] ?? '') === '1';
    $f_po       = (int)($get['po'] ?? 0);
    $t_bas      = trim((string)($get['tarih_bas'] ?? ''));
    $t_bit      = trim((string)($get['tarih_bit'] ?? ''));
    $durumlar   = siparis_durumlar();
    $kapaliDur  = siparis_kapali_durumlar();
    $evrakDur   = siparis_evrak_bekleyen_durumlar();
    $gecerliDurum = array_merge(['', 'acik', 'evrak_bekleyen', 'kapali', 'arac_bekleyen'], array_keys($durumlar));
    if (!in_array($f_durum, $gecerliDurum, true)) $f_durum = '';
    if (!in_array($f_eksik, ['', 'po', 'll', 'pc', 'pkl'], true)) $f_eksik = '';
    if ($f_cw !== '' && !preg_match('/^\d{1,2}\.\d{4}$/', $f_cw)) $f_cw = '';
    if ($t_bas !== '' && siparis_tarih_coz($t_bas) === null) $t_bas = '';
    if ($t_bit !== '' && siparis_tarih_coz($t_bit) === null) $t_bit = '';

    $where = ['s.deleted_at IS NULL']; $par = [];
    [$dsql, $dprm] = depo_sql_column('s.depo', ':udm');
    if ($q !== '') {
        $kolonlar = ['s.order_ref', 's.tur_no', 's.navlun_no', 's.plakalar', 's.alici_adi', 's.nakliyeci'];
        $parca = [];
        foreach ($kolonlar as $i => $k) { $parca[] = $k . ' LIKE :q' . ($i + 1); $par[':q' . ($i + 1)] = '%' . $q . '%'; }
        $where[] = '(' . implode(' OR ', $parca) . ')';
    }
    if ($f_cw !== '') { [$cwh, $cwy] = explode('.', $f_cw); $where[] = 's.cw_hafta = :cwh AND s.cw_yil = :cwy'; $par[':cwh'] = (int)$cwh; $par[':cwy'] = (int)$cwy; }
    if ($f_alici !== '') { $where[] = 's.alici_kodu = :alici'; $par[':alici'] = $f_alici; }
    if ($f_eksik !== '') $where[] = 's.var_' . $f_eksik . ' = 0';   // beyaz listeden geldi
    if ($f_catisma) $where[] = 's.acik_catisma > 0';
    if ($f_po > 0) { $where[] = 's.po_belge_id = :po'; $par[':po'] = $f_po; }
    if ($t_bas !== '') { $where[] = 's.yukleme_tarihi >= :tb'; $par[':tb'] = $t_bas; }
    if ($t_bit !== '') { $where[] = 's.yukleme_tarihi <= :tt'; $par[':tt'] = $t_bit; }
    $kapaliSql = "s.durum IN ('" . implode("','", $kapaliDur) . "')";
    $evrakSql  = "s.durum IN ('" . implode("','", $evrakDur) . "')";
    $acikSql   = "s.durum NOT IN ('" . implode("','", array_merge($kapaliDur, $evrakDur)) . "')";
    $temelWhere = implode(' AND ', $where) . $dsql;
    $temelPar   = $par + $dprm;

    $tek = false;             // belirli durum filtresi: tek "Sonuçlar" bölümü
    $bolumAcik = true; $bolumEvrak = true; $bolumKapali = true; $tekSql = '';
    if ($f_durum === 'acik') { $bolumEvrak = false; $bolumKapali = false; }
    elseif ($f_durum === 'evrak_bekleyen') { $bolumAcik = false; $bolumKapali = false; }
    elseif ($f_durum === 'kapali') { $bolumAcik = false; $bolumEvrak = false; }
    elseif ($f_durum === 'arac_bekleyen') { $tek = true; $tekSql = "s.durum IN ('siparis_alindi','hazirlik')"; }
    elseif ($f_durum !== '') { $tek = true; $tekSql = "s.durum = '" . $f_durum . "'"; }   // f_durum beyaz listeden

    $detayFiltre = $f_cw !== '' || $f_alici !== '' || $f_eksik !== '' || $f_catisma || $f_po > 0 || $t_bas !== '' || $t_bit !== '' || ($f_durum !== '' && !in_array($f_durum, ['acik', 'evrak_bekleyen', 'kapali'], true));
    $filtreVar = $detayFiltre || $q !== '' || in_array($f_durum, ['acik', 'evrak_bekleyen', 'kapali'], true);
    $detayOzet = [];
    if ($f_durum !== '' && !in_array($f_durum, ['acik', 'evrak_bekleyen', 'kapali'], true)) $detayOzet[] = $f_durum === 'arac_bekleyen' ? 'Araç bekleyen' : ($durumlar[$f_durum]['etiket'] ?? $f_durum);
    if ($f_cw !== '') $detayOzet[] = 'CW ' . $f_cw;
    if ($f_eksik !== '') $detayOzet[] = strtoupper($f_eksik) . ' eksik';
    if ($f_catisma) $detayOzet[] = 'Çatışmalı';
    if ($f_po > 0) $detayOzet[] = 'PO #' . $f_po;
    if ($f_alici !== '') $detayOzet[] = 'Alıcı ' . $f_alici;
    if ($t_bas !== '' || $t_bit !== '') $detayOzet[] = 'Tarih aralığı';

    return ['q' => $q, 'f_durum' => $f_durum, 'f_cw' => $f_cw, 'f_alici' => $f_alici, 'f_eksik' => $f_eksik, 'f_catisma' => $f_catisma, 'f_po' => $f_po, 't_bas' => $t_bas, 't_bit' => $t_bit,
        'kapaliSql' => $kapaliSql, 'acikSql' => $acikSql, 'evrakSql' => $evrakSql, 'temelWhere' => $temelWhere, 'temelPar' => $temelPar, 'dprm' => $dprm, 'dsql' => $dsql,
        'tek' => $tek, 'tekSql' => $tekSql, 'bolumAcik' => $bolumAcik, 'bolumEvrak' => $bolumEvrak, 'bolumKapali' => $bolumKapali, 'detayFiltre' => $detayFiltre, 'filtreVar' => $filtreVar, 'detayOzet' => $detayOzet];
}

/**
 * Liste URL'i: geçerli GET filtreleri (beyaz liste) + $override; boş değerler atılır.
 * v382: $sayfa ile aynı filtre anahtarları başka bir uca taşınabilir (siparis_yazdir.php) — anahtar beyaz listesi TEK yerde.
 */
function siparis_url(array $override, string $sayfa = 'siparisler.php'): string
{
    $anahtarlar = ['q', 'durum', 'cw', 'alici', 'eksik', 'catisma', 'po', 'tarih_bas', 'tarih_bit', 'acik_page', 'evrak_page', 'kapali_page'];
    $p = [];
    foreach ($anahtarlar as $k) {
        $v = $_GET[$k] ?? '';
        if (is_string($v)) $p[$k] = trim($v);
    }
    foreach ($override as $k => $v) $p[(string)$k] = $v === null ? '' : (string)$v;
    $p = array_filter($p, fn($v) => $v !== '');
    $qs = http_build_query($p);
    return $sayfa . ($qs !== '' ? '?' . $qs : '');
}

// ── PROFİLLER (PKL V..AB) ───────────────────────────────────────────────

/** Profil alanları: girdi anahtarı → [azami uzunluk, tip]. */
function siparis_profil_alanlari(): array
{
    return [
        'ad'                  => [100, 'metin'],
        'uretici_adi'         => [150, 'metin'],
        'ggn'                 => [20,  'ggn'],
        'tarla_kodu'          => [50,  'metin'],
        'izlenebilirlik_kodu' => [60,  'metin'],
        'kalinti_rapor_no'    => [60,  'metin'],
        'kalinti_tarihi'      => [10,  'tarih'],
        'lab_adi'             => [150, 'metin'],
    ];
}

/** @return list<array> ada göre */
function siparis_profiller(PDO $pdo, bool $yalnizAktif = true): array
{
    $st = $pdo->query('SELECT * FROM siparis_profiller WHERE deleted_at IS NULL' . ($yalnizAktif ? ' AND aktif = 1' : '') . ' ORDER BY ad, id');
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Profil oluştur/güncelle. Audit YALNIZ id + değişen alan ADLARI (değerler kişisel veri).
 * @return array{ok:bool,hata?:string,id?:int}
 */
function siparis_profil_kaydet(PDO $pdo, ?int $id, array $girdi, int $uid): array
{
    $v = [];
    foreach (siparis_profil_alanlari() as $k => [$max, $tip]) {
        $x = trim((string)preg_replace('/\s+/u', ' ', (string)($girdi[$k] ?? '')));
        if ($x === '') { $v[$k] = null; continue; }
        if ($tip === 'tarih') {
            $x = siparis_tarih_coz($x);
            if ($x === null) return ['ok' => false, 'hata' => 'Kalıntı analiz tarihi geçersiz.'];
        } elseif ($tip === 'ggn') {
            $x = (string)preg_replace('/\D/', '', $x);
            if (strlen($x) !== 13) return ['ok' => false, 'hata' => 'GGN 13 haneli olmalıdır.'];
        }
        if (mb_strlen($x, 'UTF-8') > $max) return ['ok' => false, 'hata' => 'Bir alan çok uzun (en çok ' . $max . ' karakter).'];
        $v[$k] = $x;
    }
    if ($v['ad'] === null) return ['ok' => false, 'hata' => 'Profil adı zorunludur.'];
    $aktif = array_key_exists('aktif', $girdi) ? ((int)(bool)$girdi['aktif']) : 1;
    try {
        return siparis_tx($pdo, function () use ($pdo, $id, $v, $aktif, $uid): array {
            $simdi = siparis_simdi();
            if ($id === null) {
                $kol = array_keys($v);
                $pdo->prepare('INSERT INTO siparis_profiller (' . implode(', ', $kol) . ', aktif, created_by, created_at) VALUES ('
                    . implode(', ', array_fill(0, count($kol) + 3, '?')) . ')')
                    ->execute(array_merge(array_values($v), [$aktif, $uid, $simdi]));
                $yeni = (int)$pdo->lastInsertId();
                siparis_audit('siparis_profil_kaydet', $yeni, null, ['id' => $yeni, 'alanlar' => array_keys(array_filter($v, fn($x) => $x !== null))], $uid);
                return ['ok' => true, 'id' => $yeni];
            }
            $st = $pdo->prepare('SELECT * FROM siparis_profiller WHERE id = ? AND deleted_at IS NULL');
            $st->execute([$id]);
            $eski = $st->fetch(PDO::FETCH_ASSOC);
            if (!$eski) return ['ok' => false, 'hata' => 'Profil bulunamadı.'];
            $degisen = [];
            foreach ($v as $k => $x) if ((string)($eski[$k] ?? '') !== (string)($x ?? '')) $degisen[] = $k;
            if ((int)$eski['aktif'] !== $aktif) $degisen[] = 'aktif';
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
            $pdo->prepare("UPDATE siparis_profiller SET $set, aktif = ?, updated_at = ? WHERE id = ?")
                ->execute(array_merge(array_values($v), [$aktif, $simdi, $id]));
            siparis_audit('siparis_profil_kaydet', $id, null, ['id' => $id, 'alanlar' => $degisen], $uid);
            return ['ok' => true, 'id' => $id];
        });
    } catch (PDOException $e) {
        error_log('[siparis_profil_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Profil kaydedilemedi.'];
    }
}

/** Yumuşak silme; audit siparis_profil_sil. */
function siparis_profil_sil(PDO $pdo, int $id, int $uid): bool
{
    try {
        $st = $pdo->prepare('UPDATE siparis_profiller SET deleted_at = ?, aktif = 0 WHERE id = ? AND deleted_at IS NULL');
        $st->execute([siparis_simdi(), $id]);
        if ($st->rowCount() < 1) return false;
        siparis_audit('siparis_profil_sil', $id, null, ['id' => $id], $uid);
        return true;
    } catch (PDOException $e) {
        error_log('[siparis_profil_sil] ' . $e->getMessage());
        return false;
    }
}

/** "2026-10-16" ya da "16.10.2026" → Y-m-d; geçersiz → null. */
function siparis_tarih_coz(mixed $v): ?string
{
    if (!is_string($v) && !is_int($v)) return null;
    $v = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

// v377: şoför alanları (ekleyici migrasyon) — kendi dosyasında; bu dosyada ALTER YOK değişmezi korunur
require_once __DIR__ . '/siparis_sofor.php';
// v387: yurt içi gümrük (ekleyici migrasyon) — kendi dosyasında
require_once __DIR__ . '/siparis_yurtici_gumruk.php';
// v400: evrak takımı adımı — tek seferlik veri geçişi (şema DEĞİŞMEZ) kendi dosyasında
require_once __DIR__ . '/siparis_evrak.php';
require_once __DIR__ . '/siparis_odeme.php';
