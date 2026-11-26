<?php
// =========================================================
// config/ceksenet_core.php — Çek-Senet modülü çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 3 tablo: ceksenet_kayitlar (evrakın kendisi: alınan/verilen çek + alacak/borç senedi) · ceksenet_hareketler (INSERT-only GEÇMİŞ: her durum geçişi) ·
// ceksenet_tanimlar (bankalar + çek karneleri). Sınıf (cek|senet) × yön (alinan|verilen) tek şemada.
// Durum makinesi TEK kaynaktan: ceksenet_islemler() (izinli önceki durumlar, yeni durum, cari/para etkileri). Motor tablo güdümlüdür.
// Para birimleri ASLA toplanmaz (TRY/USD/EUR ayrı); tutar kuruş tam sayı (DECIMAL(14,2) yalnız saklama).
// Şema YALNIZ migrate.php "Çek-Senet" kartından kurulur (ceksenet_migrate; ALTER ve FK YOK, GO kuralı). Yazan fonksiyonlar yetki KONTROL ETMEZ
// (uç: cek_kaydet.php → ceksenet_kapi); hepsi audit_log_event(…, 'ceksenet', …) yazar. PDO'da yalnız pozisyonel `?`.
// Cari'ye YALNIZ cari_core fonksiyonlarıyla (cari_hareket_ekle(…, ['modul'=>'ceksenet','id'=>hareket_id]) · cari_kaynakli_iptal — bir hareket İKİ dekont
//   yazabilir (karşılıksız + ciro geri alma; ikisi de aynı kaynak id'yi taşır, cari_kaynakli_iptal ikisini birden iptal eder));
// Kasa'ya YALNIZ kasa_core fonksiyonlarıyla (kasa_hareket_ekle(…, ['modul'=>'ceksenet','id'=>hareket_id]) tur kaynak_giris|kaynak_cikis · kasa_kaynakli_iptal).
//   cari_* / kasa_* tablolarına bu dosyada HİÇ SQL yok (okuma dahil); unvanlar cari_kart_oku / cari_kart_listesi, kasa adları kasa_kart_oku ile çözülür.
// Defter DEĞİŞMEZ: ceksenet_hareketler'de DELETE yok; ceksenet_kayitlar'da DELETE yok. UPDATE beyaz listeleri (gate smoke birebir denetler):
//   kayıt:   portfoy_no = ?, bordro_no = ?   ·   durum = ?, yer_cari_id = ?, yer_kasa_id = ?, updated_at = ?, updated_by = ?
//   hareket: belge_no = ?, bordro_no = ?, cari_hareket_id = ?, cari_hareket2_id = ?, kasa_hareket_id = ?   ·   durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?
// Düzeltme = iptal (neden 3–500) + yeni kayıt; YALNIZ kaydın SON geçerli hareketi iptal edilir. Taslak YOK. istek_anahtari UNIQUE (çift gönderim → tekrar).
// Depo: kayıt/hareket depo = active_depot() damgası; listeler depo_sql_in ile aktif depo + boş depo (stok_depo_filtre kalıbı).
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/cari_core.php';   // cari_* yardımcıları (kuruş, tutar/tarih ayrıştırma, tx, hata, json) + cari bağı
require_once __DIR__ . '/kasa_core.php';   // kasa_* (kaynaklı hareket / iptal, kart okuma)

const CEKSENET_IC_HATA = 4646;     // transaction'ı geri alan iç hata kodu (RuntimeException)
const CEKSENET_SATIR_MAX = 30;     // bordro başına en çok satır (max_input_vars ≈ 30 × 12 alan)
const CEKSENET_TOPLU_MAX = 100;    // tek toplu işlemde en çok kayıt

// ── Şema / kapı ─────────────────────────────────────────────────────────────

/** Üç tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım. @return array<string,string> */
function ceksenet_tablolar(): array
{
    return [
        'ceksenet_kayitlar' => "CREATE TABLE IF NOT EXISTS `ceksenet_kayitlar` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `sinif`            VARCHAR(5)     NOT NULL,
  `yon`              VARCHAR(7)     NOT NULL,
  `portfoy_no`       VARCHAR(30)    NOT NULL DEFAULT '',
  `seri_no`          VARCHAR(40)    NOT NULL,
  `banka_ad`         VARCHAR(100)   NULL DEFAULT NULL,
  `sube`             VARCHAR(100)   NULL DEFAULT NULL,
  `hesap_no`         VARCHAR(40)    NULL DEFAULT NULL,
  `borclu`           VARCHAR(150)   NULL DEFAULT NULL,
  `kefil`            VARCHAR(150)   NULL DEFAULT NULL,
  `yer_adi`          VARCHAR(100)   NULL DEFAULT NULL,
  `duzenleme_tarihi` DATE           NULL DEFAULT NULL,
  `vade`             DATE           NOT NULL,
  `tutar`            DECIMAL(14,2)  NOT NULL,
  `para_birimi`      VARCHAR(3)     NOT NULL DEFAULT 'TRY',
  `cari_id`          INT            NOT NULL,
  `durum`            VARCHAR(12)    NOT NULL,
  `yer_cari_id`      INT            NULL DEFAULT NULL,
  `yer_kasa_id`      INT            NULL DEFAULT NULL,
  `karne_id`         INT            NULL DEFAULT NULL,
  `aciklama`         VARCHAR(500)   NULL DEFAULT NULL,
  `depo`             VARCHAR(150)   NOT NULL DEFAULT '',
  `bordro_no`        VARCHAR(30)    NOT NULL DEFAULT '',
  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`       INT            NULL DEFAULT NULL,
  `updated_at`       DATETIME       NULL DEFAULT NULL,
  `updated_by`       INT            NULL DEFAULT NULL,
  KEY `idx_cs_liste` (`sinif`, `yon`, `durum`, `vade`),
  KEY `idx_cs_cari` (`cari_id`),
  KEY `idx_cs_seri` (`seri_no`),
  KEY `idx_cs_portfoy` (`portfoy_no`),
  KEY `idx_cs_bordro` (`bordro_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'ceksenet_hareketler' => "CREATE TABLE IF NOT EXISTS `ceksenet_hareketler` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `kayit_id`           INT           NOT NULL,
  `islem`              VARCHAR(20)   NOT NULL,
  `tarih`              DATE          NOT NULL,
  `onceki_durum`       VARCHAR(12)   NULL DEFAULT NULL,
  `yeni_durum`         VARCHAR(12)   NOT NULL,
  `cari_id`            INT           NULL DEFAULT NULL,
  `kasa_id`            INT           NULL DEFAULT NULL,
  `onceki_yer_cari_id` INT           NULL DEFAULT NULL,
  `onceki_yer_kasa_id` INT           NULL DEFAULT NULL,
  `cari_hareket_id`    INT           NULL DEFAULT NULL,
  `cari_hareket2_id`   INT           NULL DEFAULT NULL,
  `kasa_hareket_id`    INT           NULL DEFAULT NULL,
  `belge_no`           VARCHAR(30)   NOT NULL DEFAULT '',
  `bordro_no`          VARCHAR(30)   NOT NULL DEFAULT '',
  `aciklama`           VARCHAR(500)  NOT NULL DEFAULT '',
  `depo`               VARCHAR(150)  NOT NULL DEFAULT '',
  `istek_anahtari`     VARCHAR(40)   NULL DEFAULT NULL,
  `durum`              VARCHAR(10)   NOT NULL DEFAULT 'gecerli',
  `iptal_at`           DATETIME      NULL DEFAULT NULL,
  `iptal_by`           INT           NULL DEFAULT NULL,
  `iptal_neden`        VARCHAR(500)  NULL DEFAULT NULL,
  `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`         INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_csh_istek` (`istek_anahtari`),
  KEY `idx_csh_kayit` (`kayit_id`, `durum`, `id`),
  KEY `idx_csh_bordro` (`bordro_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'ceksenet_tanimlar' => "CREATE TABLE IF NOT EXISTS `ceksenet_tanimlar` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `tur`        VARCHAR(10)   NOT NULL,
  `ad`         VARCHAR(100)  NOT NULL,
  `sube`       VARCHAR(100)  NULL DEFAULT NULL,
  `kasa_id`    INT           NULL DEFAULT NULL,
  `ilk_no`     VARCHAR(20)   NULL DEFAULT NULL,
  `yaprak`     INT           NULL DEFAULT NULL,
  `sira`       INT           NOT NULL DEFAULT 0,
  `aktif`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT           NULL DEFAULT NULL,
  `updated_at` DATETIME      NULL DEFAULT NULL,
  `updated_by` INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_cst_tur_ad` (`tur`, `ad`),
  KEY `idx_cst_tur` (`tur`, `aktif`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Üç tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function ceksenet_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(ceksenet_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tohum: bankalar (yalnız ceksenet_tanimlar YENİ kurulduysa, bir kez). @return list<array{tur:string,ad:string}> */
function ceksenet_tanim_tohumu(): array
{
    $o = [];
    foreach (['Ziraat Bankası', 'Halkbank', 'VakıfBank', 'İş Bankası', 'Garanti BBVA', 'Akbank', 'Yapı Kredi', 'QNB', 'DenizBank', 'TEB'] as $ad) $o[] = ['tur' => 'banka', 'ad' => $ad];
    return $o;
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + banka tohumu (ceksenet_tanimlar YENİ kurulduysa). Yalnız migrate.php kartından çağrılır.
 * TEST KANCASI: $GLOBALS['_ceksenet_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function ceksenet_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_ceksenet_ddl_calistir'] ?? null;   // YALNIZ TEST KANCASI
    foreach (ceksenet_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'ceksenet_tanimlar' && (int)$pdo->query('SELECT COUNT(*) FROM `ceksenet_tanimlar`')->fetchColumn() === 0) {
                $ins = $pdo->prepare('INSERT INTO `ceksenet_tanimlar` (tur, ad, sira, aktif, created_at) VALUES (?, ?, ?, 1, ?)');
                $sira = 0;
                foreach (ceksenet_tanim_tohumu() as $r) { $sira += 10; $ins->execute([$r['tur'], $r['ad'], $sira, date('Y-m-d H:i:s')]); }
            }
        } catch (PDOException $e) {
            error_log('[ceksenet_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** JSON (fetch) isteği mi? (cari ile aynı sözleşme) */
function ceksenet_json_mi(): bool { return cari_json_mi(); }

/** Eksik şemalar (Cari, Kasa ya da Çek-Senet). @return list<array{ad:string,kart:string}> */
function ceksenet_eksik_semalar(PDO $pdo): array
{
    $e = [];
    if (!cari_hazir($pdo)) $e[] = ['ad' => 'Cari İşlemler', 'kart' => 'cari'];
    if (!kasa_hazir($pdo)) $e[] = ['ad' => 'Kasa', 'kart' => 'kasa'];
    if (!ceksenet_hazir($pdo)) $e[] = ['ad' => 'Çek-Senet', 'kart' => 'ceksenet'];
    return $e;
}

/** Çek-Senet + Cari + Kasa tabloları yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function ceksenet_sayfa_kapisi(PDO $pdo): void
{
    $eksik = ceksenet_eksik_semalar($pdo);
    if (!$eksik) return;
    $adlar = implode(', ', array_column($eksik, 'ad'));
    if (ceksenet_json_mi()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Çek-Senet için gerekli tablolar kurulmamış: ' . $adlar . '. Yönetici: Şema Migrasyon.', 'eksikler' => array_column($eksik, 'kart')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render_header('Çek-Senet');
    if (function_exists('ceksenet_assets')) ceksenet_assets();
    if (function_exists('ceksenet_sema_uyari')) {
        echo ceksenet_sema_uyari($eksik);
    } else {
        $lnk = '';
        foreach ($eksik as $e) $lnk .= ' <a class="btn btn-primary" href="migrate.php#' . h($e['kart']) . '">Şema Migrasyon › ' . h($e['ad']) . '</a>';
        echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
            . '<h2 style="margin-top:0;">Çek-Senet</h2>'
            . '<p>Çek-Senet için gerekli tablolar kurulmamış: ' . h($adlar) . '. Yönetici: Şema Migrasyon kartlarını (önce Cari İşlemler ve Kasa) çalıştırın.</p>'
            . '<p>' . $lnk . '</p>'
            . '</div></div>';
    }
    render_footer();
    exit;
}

/**
 * v550: yönetici her zaman; diğerleri Roller'den (config/muhasebe_roller.php, VARSAYILAN KAPALI).
 * $eylem: read (gruptaki herhangi bir sayfa Gör) | create | edit | delete | write (= create VE edit, belirsiz işlemlerde fail-closed)
 * | tanim (yalnız arayüz ipucu: Tanımlar'da yazma düğmeleri) | admin (yalnız is_admin()). Bilinmeyen eylem false.
 * TEK değişim noktası.
 */
function ceksenet_can(string $eylem): bool
{
    return muhasebe_modul_can('cek_senet', $eylem);
}

function ceksenet_kapi(string $eylem): void
{
    if (ceksenet_can($eylem)) return;
    if (ceksenet_json_mi()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forbidden('Bu işlem için yetkiniz yok.');
}

function ceksenet_no_store(): void { cari_no_store(); }

// ── Sabitler / meta ─────────────────────────────────────────────────────────

/** @return array<string,array{etiket:string,cogul:string,onek_alinan:string,onek_verilen:string,sayfa:string}> */
function ceksenet_siniflar(): array
{
    return [
        'cek'   => ['etiket' => 'Çek',   'cogul' => 'Çekler',   'onek_alinan' => 'ÇA', 'onek_verilen' => 'ÇV', 'sayfa' => 'cek_dekontu.php'],
        'senet' => ['etiket' => 'Senet', 'cogul' => 'Senetler', 'onek_alinan' => 'SA', 'onek_verilen' => 'SV', 'sayfa' => 'senet_dekontu.php'],
    ];
}

/** @return array<string,string> */
function ceksenet_yonler(): array { return ['alinan' => 'Alınan', 'verilen' => 'Verilen']; }

/** @return array<string,string> */
function ceksenet_tanim_turleri(): array { return ['banka' => 'Banka', 'karne' => 'Çek Karnesi']; }

/**
 * Durumlar: etiket, sinif (renk sınıfı), yon (hangi yönde geçerli: alinan | verilen | her), acik (vadesi izlenen açık durum mu).
 * 'karsiliksiz' senette "Protesto" diye gösterilir (ceksenet_durum_etiketi).
 * @return array<string,array{etiket:string,sinif:string,yon:string,acik:bool}>
 */
function ceksenet_durumlar(): array
{
    return [
        'portfoy'    => ['etiket' => 'Portföyde',        'sinif' => 'cs-d-portfoy',    'yon' => 'alinan',  'acik' => true],
        'ciro'       => ['etiket' => 'Ciro Edildi',      'sinif' => 'cs-d-ciro',       'yon' => 'alinan',  'acik' => false],
        'tahsilde'   => ['etiket' => 'Tahsilde',         'sinif' => 'cs-d-tahsilde',   'yon' => 'alinan',  'acik' => true],
        'teminatta'  => ['etiket' => 'Teminatta',        'sinif' => 'cs-d-teminatta',  'yon' => 'alinan',  'acik' => true],
        'tahsil'     => ['etiket' => 'Tahsil Edildi',    'sinif' => 'cs-d-tahsil',     'yon' => 'alinan',  'acik' => false],
        'karsiliksiz' => ['etiket' => 'Karşılıksız',     'sinif' => 'cs-d-karsiliksiz', 'yon' => 'alinan', 'acik' => false],
        'iade'       => ['etiket' => 'İade',             'sinif' => 'cs-d-iade',       'yon' => 'her',     'acik' => false],
        'verildi'    => ['etiket' => 'Verildi',          'sinif' => 'cs-d-verildi',    'yon' => 'verilen', 'acik' => true],
        'odendi'     => ['etiket' => 'Ödendi',           'sinif' => 'cs-d-odendi',     'yon' => 'verilen', 'acik' => false],
        'iptal'      => ['etiket' => 'İptal',            'sinif' => 'cs-d-iptal',      'yon' => 'her',     'acik' => false],
    ];
}

function ceksenet_durum_etiketi(string $durum, string $sinif = 'cek'): string
{
    if ($durum === 'karsiliksiz' && $sinif === 'senet') return 'Protesto';
    return ceksenet_durumlar()[$durum]['etiket'] ?? $durum;
}

/** Vadesi izlenen (açık) durumlar. @return list<string> */
function ceksenet_acik_durumlar(): array { return array_keys(array_filter(ceksenet_durumlar(), static fn(array $d): bool => $d['acik'])); }

/**
 * Durum makinesi — TEK KAYNAK. Her işlem:
 *   etiket (+ etiket_senet) · yon (alinan|verilen) · onceki (izinli önceki durumlar; boş = oluşturma) · yeni · dekont (cari dekont açıklama öneki; {S} = Çek|Senet) ·
 *   cari: cari dekontları [hedef kayit|yer_cari|secilen, yon alacak|borc, onceki? (yalnız bu önceki durumlarda)] (en çok 2: cari_hareket_id, cari_hareket2_id) ·
 *   cari_zorunlu (hedef cari seçimi şart) · kasa_sec (kasa seçimi: zorunlu, tip? banka kısıtı; kayıt para birimiyle AYNI olmalı) · para (kasa para hareketi:
 *   yon giris|cikis, kasa secilen|yer) · yer (yer_cari_id / yer_kasa_id güncellemesi: cari|kasa|temizle|koru) · etki (kullanıcıya gösterilecek özet) · toplu (toplu işlem menüsünde var mı).
 * @return array<string,array<string,mixed>>
 */
function ceksenet_islemler(): array
{
    return [
        'giris' => ['etiket' => 'Giriş', 'yon' => 'alinan', 'onceki' => [], 'yeni' => 'portfoy', 'dekont' => '{S} girişi', 'cari' => [['hedef' => 'kayit', 'yon' => 'alacak']],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'temizle', 'etki' => 'Cari: kayıt carisine Alacak dekontu.', 'toplu' => false],
        'ciro' => ['etiket' => 'Ciro Et', 'yon' => 'alinan', 'onceki' => ['portfoy'], 'yeni' => 'ciro', 'dekont' => '{S} ciro', 'cari' => [['hedef' => 'secilen', 'yon' => 'borc']],
            'cari_zorunlu' => true, 'kasa_sec' => null, 'para' => null, 'yer' => 'cari', 'etki' => 'Cari: ciro edilen cariye Borç dekontu.', 'toplu' => true],
        'tahsile_ver' => ['etiket' => 'Tahsile Ver', 'yon' => 'alinan', 'onceki' => ['portfoy'], 'yeni' => 'tahsilde', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => ['zorunlu' => true, 'tip' => 'banka'], 'para' => null, 'yer' => 'kasa', 'etki' => 'Banka hesabına tahsile verilir; para hareketi yok.', 'toplu' => true],
        'teminata_ver' => ['etiket' => 'Teminata Ver', 'yon' => 'alinan', 'onceki' => ['portfoy'], 'yeni' => 'teminatta', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => ['zorunlu' => false, 'tip' => 'banka'], 'para' => null, 'yer' => 'kasa', 'etki' => 'Teminata verilir (banka isteğe bağlı); para hareketi yok.', 'toplu' => true],
        'portfoye_al' => ['etiket' => 'Portföye Al', 'yon' => 'alinan', 'onceki' => ['tahsilde', 'teminatta'], 'yeni' => 'portfoy', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'temizle', 'etki' => 'Portföye geri alınır; para hareketi yok.', 'toplu' => true],
        'tahsil_kasa' => ['etiket' => 'Kasadan Tahsil', 'yon' => 'alinan', 'onceki' => ['portfoy'], 'yeni' => 'tahsil', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => ['zorunlu' => true, 'tip' => null], 'para' => ['yon' => 'giris', 'kasa' => 'secilen'], 'yer' => 'kasa', 'etki' => 'Seçilen kasa/bankaya para girişi.', 'toplu' => true],
        'tahsil_banka' => ['etiket' => 'Bankadan Tahsil', 'yon' => 'alinan', 'onceki' => ['tahsilde'], 'yeni' => 'tahsil', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => ['yon' => 'giris', 'kasa' => 'yer'], 'yer' => 'koru', 'etki' => 'Tahsilat bankasına para girişi.', 'toplu' => true],
        'karsiliksiz' => ['etiket' => 'Karşılıksız', 'etiket_senet' => 'Protesto', 'yon' => 'alinan', 'onceki' => ['portfoy', 'tahsilde', 'ciro'], 'yeni' => 'karsiliksiz',
            'dekont' => '{S} karşılıksız', 'dekont_senet' => 'Senet protestosu', 'cari' => [['hedef' => 'kayit', 'yon' => 'borc'], ['hedef' => 'yer_cari', 'yon' => 'alacak', 'onceki' => ['ciro']]],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'koru',
            'etki' => 'Cari: kayıt carisine Borç dekontu (geri borç); ciro edilmişse ciro carisine Alacak dekontu.', 'toplu' => true],
        'iade' => ['etiket' => 'İade', 'yon' => 'alinan', 'onceki' => ['portfoy', 'karsiliksiz'], 'yeni' => 'iade', 'dekont' => '{S} iadesi',
            'cari' => [['hedef' => 'kayit', 'yon' => 'borc', 'onceki' => ['portfoy']]], 'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'koru',
            'etki' => 'Portföyden iadede cari: kayıt carisine Borç dekontu; karşılıksızdan iadede cari etkisi yok.', 'toplu' => true],
        'cikis' => ['etiket' => 'Çıkış', 'yon' => 'verilen', 'onceki' => [], 'yeni' => 'verildi', 'dekont' => '{S} çıkışı', 'cari' => [['hedef' => 'kayit', 'yon' => 'borc']],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'temizle', 'etki' => 'Cari: kayıt carisine Borç dekontu.', 'toplu' => false],
        'odendi' => ['etiket' => 'Ödendi', 'yon' => 'verilen', 'onceki' => ['verildi'], 'yeni' => 'odendi', 'dekont' => '', 'cari' => [],
            'cari_zorunlu' => false, 'kasa_sec' => ['zorunlu' => true, 'tip' => null], 'para' => ['yon' => 'cikis', 'kasa' => 'secilen'], 'yer' => 'kasa', 'etki' => 'Seçilen kasa/bankadan para çıkışı.', 'toplu' => true],
        'iade_alindi' => ['etiket' => 'İade Alındı', 'yon' => 'verilen', 'onceki' => ['verildi'], 'yeni' => 'iade', 'dekont' => '{S} iade alındı', 'cari' => [['hedef' => 'kayit', 'yon' => 'alacak']],
            'cari_zorunlu' => false, 'kasa_sec' => null, 'para' => null, 'yer' => 'koru', 'etki' => 'Cari: kayıt carisine Alacak dekontu.', 'toplu' => true],
    ];
}

/** İşlem etiketi (senette karşılıksız = Protesto). */
function ceksenet_islem_etiketi(string $islem, string $sinif = 'cek'): string
{
    $t = ceksenet_islemler()[$islem] ?? null;
    if ($t === null) return $islem;
    return $sinif === 'senet' && isset($t['etiket_senet']) ? (string)$t['etiket_senet'] : (string)$t['etiket'];
}

/**
 * Kaydın durumundan yapılabilecek geçişler (oluşturma ve iptal HARİÇ), ceksenet_islemler() sırasıyla. İptal edilmiş kayıtta boş.
 * @return list<string>
 */
function ceksenet_izinli_islemler(array $kayit): array
{
    $durum = (string)($kayit['durum'] ?? '');
    if ($durum === '' || $durum === 'iptal') return [];
    $out = [];
    foreach (ceksenet_islemler() as $ad => $t) {
        if (!$t['onceki'] || $t['yon'] !== (string)($kayit['yon'] ?? '')) continue;
        if (in_array($durum, $t['onceki'], true)) $out[] = $ad;
    }
    return $out;
}

/** PORTFÖY NO: ÇA-2026-000123 (id tabanlı). */
function ceksenet_portfoy_no(string $sinif, string $yon, string $tarih, int $id): string
{
    $s = ceksenet_siniflar()[$sinif] ?? ceksenet_siniflar()['cek'];
    return ($yon === 'verilen' ? $s['onek_verilen'] : $s['onek_alinan']) . '-' . substr($tarih, 0, 4) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** BORDRO NO: BRD-2026-000123 (bordronun ilk hareket id'si tabanlı). */
function ceksenet_bordro_no(string $tarih, int $ilkHareketId): string { return 'BRD-' . substr($tarih, 0, 4) . '-' . str_pad((string)$ilkHareketId, 6, '0', STR_PAD_LEFT); }

/** HAREKET BELGE NO: CSH-2026-000123 (hareket id tabanlı). */
function ceksenet_belge_no(string $tarih, int $hareketId): string { return 'CSH-' . substr($tarih, 0, 4) . '-' . str_pad((string)$hareketId, 6, '0', STR_PAD_LEFT); }

/** `geri` yalnız yerel çek-senet sayfası (açık yönlendirme yok). */
function ceksenet_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^(cek_dekontu|senet_dekontu|cek_tanimlari)\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'cek_dekontu.php';
}

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function ceksenet_tx(PDO $pdo, callable $fn): mixed { return cari_tx($pdo, $fn); }

function ceksenet_hata(string $msg, array $ek = []): array { return cari_hata($msg, $ek); }

/** 123456 (kuruş) → "1234.56" (DECIMAL yazımı). */
function ceksenet_para_db(int $kurus): string { return number_format($kurus / 100, 2, '.', ''); }

/** d.m.Y ya da Y-m-d → Y-m-d (cari tarih aralığında değilse null): bordro / işlem tarihi. */
function ceksenet_tarih_coz(mixed $ham): ?string
{
    $t = trim(is_scalar($ham) ? (string)$ham : '');
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $t, $m)) $t = "$m[3]-$m[2]-$m[1]";
    return cari_tarih_gecerli($t) ? $t : null;
}

/** d.m.Y ya da Y-m-d → Y-m-d; vade / düzenleme tarihi için geniş aralık (2000 … bugün + 10 yıl). */
function ceksenet_vade_coz(mixed $ham): ?string
{
    $t = trim(is_scalar($ham) ? (string)$ham : '');
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $t, $m)) $t = "$m[3]-$m[2]-$m[1]";
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return $t >= '2000-01-01' && $t <= date('Y-m-d', strtotime('+3650 days')) ? $t : null;
}

function ceksenet_onay(mixed $v): bool { return !empty($v) && (string)$v !== '0'; }

/** MySQL: kayıt satırlarını FOR UPDATE ile kilitleyerek okur (sabit sıra: id artan). SQLite: düz okuma. @param list<int> $ids @return array<int,array> id → satır */
function ceksenet_kayitlari_kilitle_oku(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    if (!$ids) return [];
    sort($ids);
    $st = $pdo->prepare('SELECT * FROM ceksenet_kayitlar WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/** Kayıt aktif depoda görünür mü? (depo boşsa görünür) */
function ceksenet_gorunur(array $kayit): bool { return depot_visible_to_user((string)($kayit['depo'] ?? '')); }

function ceksenet_depo_damgasi(): string { return function_exists('active_depot') ? (string)(active_depot() ?? '') : ''; }

/** @return array{0:string,1:array} depo süzgeci (kayıt deposu aktif depo + boş depo) */
function ceksenet_depo_filtre(string $col): array
{
    [$w, $p] = depo_sql_in($col);
    return $w === '' ? ['1=1', []] : [$w, $p];
}

/**
 * Sonucu JSON ya da PRG olarak gönderir. 409: yetersiz_bakiye · mukerrer · gecersiz_gecis · son_degil · kullanimda; kod sunucu_hatasi → 500; diğer hata 422.
 */
function ceksenet_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && ($s['kod'] ?? '') === 'sunucu_hatasi') http_response_code(500);
        elseif (!$ok && in_array((string)($s['kod'] ?? ''), ['yetersiz_bakiye', 'mukerrer', 'gecersiz_gecis', 'son_degil', 'kullanimda'], true)) http_response_code(409);
        elseif (!$ok) http_response_code(422);
        echo json_encode([
            'ok' => $ok, 'id' => $s['id'] ?? null, 'kayit_id' => $s['kayit_id'] ?? null, 'idler' => $s['idler'] ?? [], 'bordro_no' => $s['bordro_no'] ?? null, 'belge_no' => $s['belge_no'] ?? null,
            'hata' => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'), 'alanlar' => (object)($s['alanlar'] ?? []), 'satir_hatalari' => (object)($s['satir_hatalari'] ?? []),
            'kayitlar' => $s['kayitlar'] ?? [], 'uyari' => $s['uyari'] ?? null, 'yonlendir' => $geri, 'tekrar' => !empty($s['tekrar']), 'kod' => $s['kod'] ?? null,
            'bakiye' => $s['bakiye'] ?? null, 'eksik' => $s['eksik'] ?? null, 'toplamlar' => (object)($s['toplamlar'] ?? []), 'ortalama_vade' => (object)($s['ortalama_vade'] ?? []),
            'yeni_durum' => $s['yeni_durum'] ?? null, 'sinif' => $s['sinif'] ?? null, 'yon' => $s['yon'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok
        ? $okMesaj . (!empty($s['bordro_no']) ? ' · ' . $s['bordro_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '')
        : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

// ── Küçük okuyucular ────────────────────────────────────────────────────────

function ceksenet_kayit_oku(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM ceksenet_kayitlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function ceksenet_hareket_oku(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM ceksenet_hareketler WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function ceksenet_istek_bul(PDO $pdo, string $anahtar): ?array
{
    $st = $pdo->prepare('SELECT * FROM ceksenet_hareketler WHERE istek_anahtari = ?');
    $st->execute([$anahtar]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Cari unvan/kodu (istek içi önbellek; cari_kart_oku ile — cari tablosuna SQL yok). @return array{unvan:string,kod:string,aktif:int}|null */
function ceksenet_cari_ad(PDO $pdo, int $cariId): ?array
{
    static $onb = [];
    $k = spl_object_id($pdo) . ':' . $cariId;
    if (!array_key_exists($k, $onb)) {
        $c = $cariId > 0 ? cari_kart_oku($pdo, $cariId) : null;
        $onb[$k] = $c === null ? null : ['unvan' => (string)$c['unvan'], 'kod' => (string)$c['kod'], 'aktif' => (int)$c['aktif']];
    }
    return $onb[$k];
}

/** Bordronun ya da işlem grubunun tekrar yanıtı (istek anahtarı daha önce işlenmişse). */
function ceksenet_tekrar_sonucu(PDO $pdo, array $h): array
{
    $idler = [];
    if ((string)$h['bordro_no'] !== '') {
        $st = $pdo->prepare('SELECT kayit_id FROM ceksenet_hareketler WHERE bordro_no = ? ORDER BY id');
        $st->execute([(string)$h['bordro_no']]);
        $idler = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$idler) $idler = [(int)$h['kayit_id']];
    $k = ceksenet_kayit_oku($pdo, (int)$h['kayit_id']);
    return ['ok' => true, 'hata' => null, 'id' => (int)$idler[0], 'idler' => $idler, 'bordro_no' => (string)$h['bordro_no'], 'belge_no' => (string)$h['belge_no'], 'tekrar' => true,
        'sinif' => $k['sinif'] ?? null, 'yon' => $k['yon'] ?? null];
}

/** Cari dekont / kasa açıklamasında kullanılan evrak özeti: "Ziraat Bankası 1234567". */
function ceksenet_evrak_ozet(array $k): string
{
    return trim((string)($k['banka_ad'] ?? '') !== '' ? ((string)$k['banka_ad'] . ' ' . (string)$k['seri_no']) : (((string)($k['borclu'] ?? '') !== '' ? (string)$k['borclu'] . ' ' : '') . (string)$k['seri_no']));
}

function ceksenet_vade_bicim(string $vade): string { return date('d.m.Y', strtotime($vade) ?: 0); }

// ── Ortalama vade (SAF) ─────────────────────────────────────────────────────

/**
 * Ağırlıklı ortalama vade: gün = round(Σ(kuruş × gün) / Σ kuruş), gün = vade − baz tarih; tarih = baz + gün. Para birimi başına AYRI (karıştırılmaz).
 * Satır: ['vade' => 'Y-m-d', 'tutar' => DECIMAL/metin ya da 'kurus' => int, 'para_birimi' => 'TRY' (yoksa TRY)]. Geçersiz vade satırı atlanır.
 * Hiç geçerli satır / geçersiz baz tarih → null. Yuvarlama yarım sıfırdan uzağa (tam sayı aritmetiği; float kaybı yok).
 * @return array<string,array{gun:int,tarih:string,toplam_kurus:int,adet:int}>|null
 */
function ceksenet_ortalama_vade(array $satirlar, string $bazTarih): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $bazTarih, $bm) || !checkdate((int)$bm[2], (int)$bm[3], (int)$bm[1])) return null;
    $baz = (int)(strtotime($bazTarih . ' 00:00:00 UTC') / 86400);
    $acc = [];
    foreach ($satirlar as $s) {
        $v = (string)($s['vade'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $vm) || !checkdate((int)$vm[2], (int)$vm[3], (int)$vm[1])) continue;
        $kurus = isset($s['kurus']) ? (int)$s['kurus'] : cari_kurus(isset($s['tutar']) && is_scalar($s['tutar']) ? (string)$s['tutar'] : null);
        if ($kurus <= 0) continue;
        $pb = (string)($s['para_birimi'] ?? 'TRY'); if ($pb === '') $pb = 'TRY';
        $gun = (int)(strtotime($v . ' 00:00:00 UTC') / 86400) - $baz;
        $acc[$pb] ??= ['s' => 0, 't' => 0, 'n' => 0, 'bs' => '0', 'bt' => '0'];
        $acc[$pb]['s'] += $kurus * $gun; $acc[$pb]['t'] += $kurus; $acc[$pb]['n']++;   // taşarsa PHP float'a geçer (aşağıda ele alınır)
        if (function_exists('bcadd')) { $acc[$pb]['bs'] = bcadd($acc[$pb]['bs'], bcmul((string)$kurus, (string)$gun, 0), 0); $acc[$pb]['bt'] = bcadd($acc[$pb]['bt'], (string)$kurus, 0); }
    }
    if (!$acc) return null;
    $out = [];
    foreach ($acc as $pb => $a) {
        $S = $a['s']; $T = $a['t'];
        if (is_int($S) && is_int($T) && abs($S) < intdiv(PHP_INT_MAX, 4) && $T < intdiv(PHP_INT_MAX, 4)) {
            $g = $S >= 0 ? intdiv(2 * $S + $T, 2 * $T) : -intdiv(-2 * $S + $T, 2 * $T);   // tam sayı yolu (kayıpsız)
        } elseif (function_exists('bcadd') && bccomp($a['bt'], '0', 0) > 0) {            // taşma: bcmath ile kayıpsız
            $neg = bccomp($a['bs'], '0', 0) < 0;
            $abs = $neg ? substr($a['bs'], 1) : $a['bs'];
            $q = (int)bcdiv(bcadd(bcmul('2', $abs, 0), $a['bt'], 0), bcmul('2', $a['bt'], 0), 0);
            $g = $neg ? -$q : $q;
        } else {
            $q = (float)$S / max(1.0, (float)$T);                                            // bcmath yok: float (yarım sıfırdan uzağa yuvarlama)
            $g = (int)($q >= 0 ? floor($q + 0.5) : -floor(-$q + 0.5));
        }
        $out[$pb] = ['gun' => $g, 'tarih' => gmdate('Y-m-d', ($baz + $g) * 86400), 'toplam_kurus' => is_int($T) ? $T : PHP_INT_MAX, 'adet' => $a['n']];
    }
    return cari_pb_sirala($out);
}

// ── Tanımlar (bankalar + çek karneleri) ─────────────────────────────────────

function ceksenet_tanim_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM ceksenet_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Karnedeki kullanılan yaprak sayısı (iptal edilen kayıtlar sayılmaz). */
function ceksenet_karne_kullanim(PDO $pdo, int $karneId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM ceksenet_kayitlar WHERE karne_id = ? AND durum <> 'iptal'");
    $st->execute([$karneId]);
    return (int)$st->fetchColumn();
}

/**
 * Karnedeki sıradaki çek numarası ÖNERİSİ (kayıtta doğrulanmaz): kullanılan en büyük sayısal numara + 1 (yoksa ilk_no), ilk_no uzunluğunda sıfır dolgulu.
 * Karne bitmişse / tanımsızsa null. @return array{no:string,kalan:int,ilk_no:string,son_no:string}|null
 */
function ceksenet_karne_siradaki(PDO $pdo, int $karneId): ?array
{
    $t = ceksenet_tanim_bul($pdo, $karneId);
    if ($t === null || (string)$t['tur'] !== 'karne' || !preg_match('/^\d{1,20}$/', (string)$t['ilk_no']) || (int)$t['yaprak'] <= 0) return null;
    $ilk = (int)$t['ilk_no']; $son = $ilk + (int)$t['yaprak'] - 1; $uz = strlen((string)$t['ilk_no']);
    $st = $pdo->prepare("SELECT seri_no FROM ceksenet_kayitlar WHERE karne_id = ? AND durum <> 'iptal'");
    $st->execute([$karneId]);
    $max = $ilk - 1; $kul = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) { $kul++; if (preg_match('/^\d{1,18}$/', (string)$s) && (int)$s >= $ilk && (int)$s <= $son) $max = max($max, (int)$s); }
    $siradaki = $max + 1;
    $kalan = max(0, (int)$t['yaprak'] - $kul);
    if ($siradaki > $son) return null;
    return ['no' => str_pad((string)$siradaki, $uz, '0', STR_PAD_LEFT), 'kalan' => $kalan, 'ilk_no' => (string)$t['ilk_no'], 'son_no' => str_pad((string)$son, $uz, '0', STR_PAD_LEFT)];
}

/**
 * Tanım listesi. banka: ad, sube. karne: kasa_id (banka hesabı), kasa_ad, kasa_pb, ilk_no, yaprak, kullanim, kalan, siradaki (önerilen no ya da null). Her satırda kullanim (silinebilir mi).
 * @return list<array>
 */
function ceksenet_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array
{
    if (!isset(ceksenet_tanim_turleri()[$tur])) return [];
    $st = $pdo->prepare('SELECT * FROM ceksenet_tanimlar WHERE tur = ?' . ($yalnizAktif ? ' AND aktif = 1' : '') . ' ORDER BY sira, ad, id');
    $st->execute([$tur]);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($satirlar as &$r) {
        if ($tur === 'karne') {
            $kasa = !empty($r['kasa_id']) ? kasa_kart_oku($pdo, (int)$r['kasa_id']) : null;
            $r['kasa_ad'] = $kasa['ad'] ?? null; $r['kasa_pb'] = $kasa['para_birimi'] ?? null;
            $r['kullanim'] = ceksenet_karne_kullanim($pdo, (int)$r['id']);
            $r['kalan'] = max(0, (int)$r['yaprak'] - $r['kullanim']);
            $sr = ceksenet_karne_siradaki($pdo, (int)$r['id']);
            $r['siradaki'] = $sr['no'] ?? null;
        } else {
            $c = $pdo->prepare('SELECT COUNT(*) FROM ceksenet_kayitlar WHERE banka_ad = ?');
            $c->execute([(string)$r['ad']]);
            $r['kullanim'] = (int)$c->fetchColumn();
        }
    }
    unset($r);
    return $satirlar;
}

/** Form seçenekleri: aktif banka adları (datalist). @return list<string> */
function ceksenet_banka_secenekleri(PDO $pdo): array { return array_map(static fn(array $r): string => (string)$r['ad'], ceksenet_tanim_listesi($pdo, 'banka', true)); }

/** Form seçenekleri: aktif karneler (kasa banka hesabı + sıradaki no). @return list<array> */
function ceksenet_karne_secenekleri(PDO $pdo): array { return ceksenet_tanim_listesi($pdo, 'karne', true); }

/** @return array{ok:bool,veri?:array,hatalar?:array<string,string>} */
function ceksenet_tanim_dogrula(PDO $pdo, array $g, ?int $id, ?array $eski): array
{
    $h = []; $v = [];
    $tur = $eski !== null ? (string)$eski['tur'] : (string)($g['tur'] ?? '');
    if (!isset(ceksenet_tanim_turleri()[$tur])) return ['ok' => false, 'hatalar' => ['tur' => 'Geçersiz tanım türü.']];
    $v['tur'] = $tur;
    $ad = trim((string)($g['ad'] ?? ''));
    if ($ad === '' || mb_strlen($ad) > 100) $h['ad'] = 'Ad 1-100 karakter olmalı.';
    else {
        $st = $pdo->prepare('SELECT id, ad FROM ceksenet_tanimlar WHERE tur = ?');
        $st->execute([$tur]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($id !== null && (int)$r['id'] === $id) continue;
            if (cari_fold((string)$r['ad']) === cari_fold($ad)) { $h['ad'] = 'Bu ad zaten tanımlı.'; break; }
        }
        $v['ad'] = $ad;
    }
    $v['sira'] = (int)($g['sira'] ?? 0);
    $v['sube'] = null; $v['kasa_id'] = null; $v['ilk_no'] = null; $v['yaprak'] = null;
    if ($tur === 'banka') {
        $sb = trim((string)($g['sube'] ?? ''));
        if (mb_strlen($sb) > 100) $h['sube'] = 'Şube en çok 100 karakter.'; else $v['sube'] = $sb === '' ? null : $sb;
    } else {
        $kid = (int)($g['kasa_id'] ?? 0);
        $kasa = $kid > 0 ? kasa_kart_oku($pdo, $kid) : null;
        if ($kasa === null) $h['kasa_id'] = 'Banka hesabı seçin.';
        elseif ((string)$kasa['tip'] !== 'banka') $h['kasa_id'] = 'Yalnız banka hesabı seçilebilir.';
        elseif (!($eski !== null && (int)($eski['kasa_id'] ?? 0) === $kid) && (int)$kasa['aktif'] !== 1) $h['kasa_id'] = 'Pasif banka hesabı seçilemez.';
        elseif (!($eski !== null && (int)($eski['kasa_id'] ?? 0) === $kid) && !kasa_gorunur($kasa)) $h['kasa_id'] = 'Bu banka hesabı aktif deponuzda görünmüyor.';
        else $v['kasa_id'] = $kid;
        $ilk = trim((string)($g['ilk_no'] ?? ''));
        if (!preg_match('/^\d{1,20}$/', $ilk)) $h['ilk_no'] = 'İlk çek numarası yalnız rakam (en çok 20 hane).'; else $v['ilk_no'] = $ilk;
        $yp = trim((string)($g['yaprak'] ?? ''));
        if (!preg_match('/^\d{1,4}$/', $yp) || (int)$yp < 1 || (int)$yp > 1000) $h['yaprak'] = 'Yaprak sayısı 1-1000 olmalı.'; else $v['yaprak'] = (int)$yp;
    }
    return $h ? ['ok' => false, 'hatalar' => $h] : ['ok' => true, 'veri' => $v];
}

/** Tanım ekle / güncelle. $g: tur (yalnız yeni), ad, sube (banka) | kasa_id, ilk_no, yaprak (karne), sira. Düzenlemede tür değişmez. */
function ceksenet_tanim_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array
{
    $eski = null;
    if ($id !== null) {
        $eski = ceksenet_tanim_bul($pdo, $id);
        if ($eski === null) return ceksenet_hata('Tanım bulunamadı.');
    }
    $d = ceksenet_tanim_dogrula($pdo, $g, $id, $eski);
    if (!$d['ok']) return ceksenet_hata((string)reset($d['hatalar']), ['alanlar' => $d['hatalar']]);
    $v = $d['veri'];
    $simdi = date('Y-m-d H:i:s');
    try {
        if ($id === null) {
            $pdo->prepare('INSERT INTO ceksenet_tanimlar (tur, ad, sube, kasa_id, ilk_no, yaprak, sira, aktif, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([$v['tur'], $v['ad'], $v['sube'], $v['kasa_id'], $v['ilk_no'], $v['yaprak'], $v['sira'], $simdi, $uid]);
            $yid = (int)$pdo->lastInsertId();
            audit_log_event('create', 'ceksenet', $yid, null, ['kayit' => 'tanim'] + $v);
            return ['ok' => true, 'hata' => null, 'id' => $yid];
        }
        $pdo->prepare('UPDATE ceksenet_tanimlar SET ad = ?, sube = ?, kasa_id = ?, ilk_no = ?, yaprak = ?, sira = ?, updated_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$v['ad'], $v['sube'], $v['kasa_id'], $v['ilk_no'], $v['yaprak'], $v['sira'], $simdi, $uid, $id]);
    } catch (PDOException $e) {
        error_log('[ceksenet_tanim_kaydet] ' . $e->getMessage());
        return ceksenet_hata('Tanım kaydedilemedi (ad çakışması olabilir).', ['alanlar' => ['ad' => 'Çakışma.']]);
    }
    audit_log_event('update', 'ceksenet', $id, ['kayit' => 'tanim'] + array_intersect_key($eski, $v), ['kayit' => 'tanim'] + $v);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

function ceksenet_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $t = ceksenet_tanim_bul($pdo, $id);
    if ($t === null) return ceksenet_hata('Tanım bulunamadı.');
    $pdo->prepare('UPDATE ceksenet_tanimlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    audit_log_event($aktif ? 'activate' : 'deactivate', 'ceksenet', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad'], 'aktif' => (int)$t['aktif']], ['kayit' => 'tanim', 'aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Kullanılan tanım silinemez (409 kullanimda; pasifleştirin): banka kayıtlarda adıyla, karne kayıtlarda karne_id ile geçiyorsa. */
function ceksenet_tanim_sil(PDO $pdo, int $id, int $uid): array
{
    $t = ceksenet_tanim_bul($pdo, $id);
    if ($t === null) return ceksenet_hata('Tanım bulunamadı.');
    if ((string)$t['tur'] === 'karne') { $st = $pdo->prepare('SELECT COUNT(*) FROM ceksenet_kayitlar WHERE karne_id = ?'); $st->execute([$id]); }
    else { $st = $pdo->prepare('SELECT COUNT(*) FROM ceksenet_kayitlar WHERE banka_ad = ?'); $st->execute([(string)$t['ad']]); }
    if ((int)$st->fetchColumn() > 0) return ceksenet_hata('Bu tanım kayıtlarda kullanılıyor; silmek yerine pasifleştirin.', ['kod' => 'kullanimda']);
    $pdo->prepare('DELETE FROM ceksenet_tanimlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'ceksenet', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad']], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

// ── Bordro (giriş / çıkış) ──────────────────────────────────────────────────

/** Paralel diziler (ya da `satir` dizisi) → temiz satırlar; tümüyle boş satır atılır. İlk gelen indeks ($i) korunur. @return list<array<string,string>> */
function ceksenet_satir_topla(array $g): array
{
    $alanlar = ['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar', 'para_birimi', 'karne_id'];
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
        $bos = true;
        foreach (['seri_no', 'banka_ad', 'sube', 'hesap_no', 'borclu', 'kefil', 'yer_adi', 'duzenleme_tarihi', 'vade', 'tutar'] as $a) if ($t[$a] !== '') { $bos = false; break; }
        if ($bos) continue;
        $t['i'] = (string)$i;
        $out[] = $t;
    }
    return $out;
}

/** Hesap kimliği normalizasyonu: boşluk / tire atılır, harf-duyarsız (cari_fold). IBAN / hesap no karşılaştırması içindir. */
function ceksenet_hesap_norm(string $hesap): string
{
    return cari_fold(preg_replace('/[\s\-]+/u', '', $hesap) ?? '');
}

/** Karnenin banka kasasının id'si (karne yoksa / kasa bağı yoksa null). Mükerrer kuralında karneli satırın "hesabı" bu kasadır. */
function ceksenet_karne_kasa_id(PDO $pdo, ?int $karneId): ?int
{
    if ($karneId === null || $karneId <= 0) return null;
    $kt = ceksenet_tanim_bul($pdo, $karneId);
    return $kt !== null && !empty($kt['kasa_id']) ? (int)$kt['kasa_id'] : null;
}

/**
 * Mükerrer kuralı — TEK yardımcı (alınan + verilen çek; bordro içi ve DB karşılaştırması bunu kullanır). Senette kontrol YOK (false).
 * İki çek mükerrer ⇔ banka (cari_fold) eşit VE seri (cari_fold) eşit VE (hesaplardan biri boş YA DA hesaplar eşit).
 * Hesap: karnesiz çekte hesap_no (boşluk/tire temizlenmiş, fold); karneli verilen çekte karnenin banka KASASI — iki karneli çek yalnız AYNI kasaysa aynı hesaptır
 * (iki farklı banka-kasalı karnede aynı seri meşru); karneli ↔ karnesizde karnesizin hesabı boşsa mükerrer, doluysa kasanın IBAN'ı ile kıyaslanır (IBAN boşsa mükerrer).
 * Aynı bankada iki FARKLI hesabın karnelerinde aynı seri no böylece kabul edilir.
 * @param array{banka:string,hesap:string,seri:string,kasa:?int} $a  @param array{banka:string,hesap:string,seri:string,kasa:?int} $b
 */
function ceksenet_mukerrer_mu(string $sinif, array $a, array $b): bool
{
    if ($sinif !== 'cek') return false;
    if (cari_fold((string)$a['banka']) !== cari_fold((string)$b['banka'])) return false;
    if (cari_fold((string)$a['seri']) !== cari_fold((string)$b['seri'])) return false;
    if (!empty($a['kasa']) && !empty($b['kasa'])) return (int)$a['kasa'] === (int)$b['kasa'];
    $ha = ceksenet_hesap_norm((string)$a['hesap']); $hb = ceksenet_hesap_norm((string)$b['hesap']);
    return $ha === '' || $hb === '' || $ha === $hb;
}

/** Mükerrer evrak (bkz. ceksenet_mukerrer_mu); iade / iptal edilenler sayılmaz. Senette kontrol yok. $kilitle=true: MySQL tx içinde FOR UPDATE ile seri_no yarışını kapatır (B-23). */
function ceksenet_mukerrer_bul(PDO $pdo, string $sinif, string $yon, string $bankaAd, string $hesapNo, string $seriNo, ?int $karneId, bool $kilitle = false): ?array
{
    if ($sinif !== 'cek') return null;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nocase = $driver === 'sqlite' ? ' COLLATE NOCASE' : '';   // MySQL karşılaştırması zaten harf-duyarsız
    $lock = ($kilitle && $driver === 'mysql') ? ' FOR UPDATE' : '';
    $st = $pdo->prepare("SELECT * FROM ceksenet_kayitlar WHERE sinif = 'cek' AND yon = ? AND seri_no = ?$nocase AND durum NOT IN ('iade', 'iptal') ORDER BY id$lock");
    $st->execute([$yon, $seriNo]);
    $a = ['banka' => $bankaAd, 'hesap' => $hesapNo, 'seri' => $seriNo, 'kasa' => ceksenet_karne_kasa_id($pdo, $karneId)];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = ['banka' => (string)$r['banka_ad'], 'hesap' => (string)$r['hesap_no'], 'seri' => (string)$r['seri_no'], 'kasa' => ceksenet_karne_kasa_id($pdo, isset($r['karne_id']) ? (int)$r['karne_id'] : null)];
        if (ceksenet_mukerrer_mu('cek', $a, $b)) return $r;
    }
    return null;
}

/**
 * Bordro doğrulaması (YAZMAZ). Başlık: sinif, yon, cari_id, tarih, aciklama, istek_anahtari. Satırlar paralel dizilerle (ceksenet_satir_topla).
 * @return array{ok:bool,hata?:string,veri?:array,alanlar?:array<string,string>,satir_hatalari?:array<int,array<string,string>>,kod?:string}
 */
function ceksenet_bordro_dogrula(PDO $pdo, array $g): array
{
    $h = []; $sh = []; $v = [];
    $sinif = (string)($g['sinif'] ?? '');
    if (!isset(ceksenet_siniflar()[$sinif])) return ['ok' => false, 'hata' => 'Geçersiz sınıf (çek / senet).', 'alanlar' => ['sinif' => 'Geçersiz sınıf.']];
    $yon = (string)($g['yon'] ?? '');
    if (!isset(ceksenet_yonler()[$yon])) return ['ok' => false, 'hata' => 'Geçersiz yön (alınan / verilen).', 'alanlar' => ['yon' => 'Geçersiz yön.']];
    $v['sinif'] = $sinif; $v['yon'] = $yon;

    $tarih = ceksenet_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) $h['tarih'] = 'Geçerli bir tarih girin.';
    $v['tarih'] = $tarih;

    $cid = (int)($g['cari_id'] ?? 0);
    $cari = $cid > 0 ? cari_kart_oku($pdo, $cid) : null;
    if ($cari === null) $h['cari_id'] = 'Cari seçin.';
    elseif ((int)$cari['aktif'] !== 1) $h['cari_id'] = 'Pasif cariye yeni belge girilemez.';
    $v['cari'] = $cari;

    $ac = trim((string)($g['aciklama'] ?? ''));
    if (mb_strlen($ac) > 500) $h['aciklama'] = 'Açıklama en çok 500 karakter.';
    $v['aciklama'] = $ac;
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    $v['ik'] = $ik === '' ? null : $ik;

    $satirlar = ceksenet_satir_topla($g);
    if (!$satirlar) $h['satir'] = 'En az bir ' . mb_strtolower(ceksenet_siniflar()[$sinif]['etiket'], 'UTF-8') . ' satırı girin.';
    elseif (count($satirlar) > CEKSENET_SATIR_MAX) $h['satir'] = 'Bir bordroda en çok ' . CEKSENET_SATIR_MAX . ' satır girilebilir.';

    $out = []; $gorulen = []; $uyarilar = [];
    foreach ($satirlar as $r) {
        if (count($out) + count($sh) >= CEKSENET_SATIR_MAX) break;
        $i = (int)$r['i']; $e = [];
        $seri = $r['seri_no'];
        if ($seri === '') $e['seri_no'] = ($sinif === 'cek' ? 'Çek' : 'Senet') . ' numarası girin.';
        elseif (mb_strlen($seri) > 40) $e['seri_no'] = 'En çok 40 karakter.';
        $kurus = kasa_tutar_coz($r['tutar']);
        $th = kasa_tutar_hata($kurus, 'Geçerli bir tutar girin.');
        if ($th !== null) { $e['tutar'] = $th; $kurus = null; }
        $pb = $r['para_birimi'] === '' ? 'TRY' : $r['para_birimi'];
        if (!in_array($pb, cari_para_birimleri(), true)) $e['para_birimi'] = 'Geçersiz para birimi.';
        $vade = ceksenet_vade_coz($r['vade']);
        if ($vade === null) $e['vade'] = 'Geçerli bir vade tarihi girin.';
        $duz = null;
        if ($r['duzenleme_tarihi'] !== '') {
            $duz = ceksenet_vade_coz($r['duzenleme_tarihi']);
            if ($duz === null) $e['duzenleme_tarihi'] = 'Geçerli bir tarih girin.';
            elseif ($vade !== null && $duz > $vade) $e['duzenleme_tarihi'] = 'Düzenleme tarihi vadeden sonra olamaz.';
        }
        $alanSinir = ['sube' => 100, 'hesap_no' => 40, 'borclu' => 150, 'kefil' => 150, 'yer_adi' => 100, 'banka_ad' => 100];
        foreach ($alanSinir as $a => $max) if (mb_strlen($r[$a]) > $max) $e[$a] = "En çok $max karakter.";

        $karne = null; $banka = $r['banka_ad']; $sube = $r['sube']; $hesap = $r['hesap_no'];
        if ($sinif === 'cek' && $yon === 'verilen' && $r['karne_id'] !== '' && $r['karne_id'] !== '0') {
            $kt = ctype_digit($r['karne_id']) ? ceksenet_tanim_bul($pdo, (int)$r['karne_id']) : null;
            if ($kt === null || (string)$kt['tur'] !== 'karne') $e['karne_id'] = 'Karne bulunamadı.';
            elseif ((int)$kt['aktif'] !== 1) $e['karne_id'] = 'Pasif karne seçilemez.';
            else {
                $ks = !empty($kt['kasa_id']) ? kasa_kart_oku($pdo, (int)$kt['kasa_id']) : null;
                if ($ks === null) $e['karne_id'] = 'Karnenin banka hesabı bulunamadı.';
                elseif ((int)$ks['aktif'] !== 1) $e['karne_id'] = 'Karnenin banka hesabı pasif.';
                elseif ((string)$ks['tip'] !== 'banka') $e['karne_id'] = 'Karnenin hesabı banka hesabı değil.';
                elseif ($pb !== (string)$ks['para_birimi']) $e['para_birimi'] = 'Para birimi karnenin banka hesabıyla (' . $ks['para_birimi'] . ') aynı olmalı.';
                else {
                    $karne = $kt;
                    $banka = (string)($ks['banka_adi'] ?? '') !== '' ? (string)$ks['banka_adi'] : (string)$ks['ad'];
                    $sube = (string)($ks['sube'] ?? ''); $hesap = (string)($ks['iban'] ?? '');
                    if ($seri !== '' && preg_match('/^\d{1,18}$/', $seri) && preg_match('/^\d{1,20}$/', (string)$kt['ilk_no'])) {
                        $ilk = (int)$kt['ilk_no'];
                        if ((int)$seri < $ilk || (int)$seri > $ilk + (int)$kt['yaprak'] - 1) $uyarilar[] = $seri . ' numarası "' . $kt['ad'] . '" karnesinin aralığında değil (' . $kt['ilk_no'] . ' – ' . ($ilk + (int)$kt['yaprak'] - 1) . ').';
                    }
                }
            }
        }
        if ($sinif === 'cek' && $karne === null && $banka === '') $e['banka_ad'] = 'Banka adı girin.';
        if ($sinif === 'senet') { $banka = ''; $sube = ''; $hesap = ''; }
        if ($sinif === 'cek') { $r['kefil'] = ''; }

        if (!$e && $sinif === 'cek') {
            $aday = ['banka' => $banka, 'hesap' => $hesap, 'seri' => $seri, 'kasa' => $karne !== null && !empty($karne['kasa_id']) ? (int)$karne['kasa_id'] : null];
            $tekrar = false;
            foreach ($gorulen as $onceki) if (ceksenet_mukerrer_mu($sinif, $aday, $onceki)) { $tekrar = true; break; }   // bordro içi: DB ile AYNI kural
            if ($tekrar) { $e['seri_no'] = 'Bu bordroda aynı numara tekrarlanıyor.'; $kod = 'mukerrer'; }
            else {
                $gorulen[] = $aday;
                if (($m = ceksenet_mukerrer_bul($pdo, $sinif, $yon, $banka, $hesap, $seri, $karne !== null ? (int)$karne['id'] : null)) !== null) {
                    $e['seri_no'] = 'Bu numara zaten kayıtlı: ' . $m['portfoy_no'] . '.'; $kod = 'mukerrer';
                }
            }
        }
        if ($e) { $sh[$i] = $e; continue; }
        $out[] = ['i' => $i, 'seri_no' => $seri, 'banka_ad' => $banka === '' ? null : $banka, 'sube' => $sube === '' ? null : $sube, 'hesap_no' => $hesap === '' ? null : $hesap,
            'borclu' => $r['borclu'] === '' ? null : $r['borclu'], 'kefil' => $r['kefil'] === '' ? null : $r['kefil'], 'yer_adi' => $r['yer_adi'] === '' ? null : $r['yer_adi'],
            'duzenleme_tarihi' => $duz, 'vade' => $vade, 'kurus' => $kurus, 'pb' => $pb, 'karne_id' => $karne !== null ? (int)$karne['id'] : null];
    }
    if ($h || $sh) {
        $ilk = $h ? (string)reset($h) : 'Satır ' . (array_key_first($sh) + 1) . ': ' . (string)reset($sh[array_key_first($sh)]);
        return ['ok' => false, 'hata' => $ilk, 'alanlar' => $h, 'satir_hatalari' => $sh] + (isset($kod) ? ['kod' => $kod] : []);
    }
    $v['satirlar'] = $out; $v['uyarilar'] = $uyarilar;
    return ['ok' => true, 'veri' => $v];
}

/**
 * Giriş (alınan) / çıkış (verilen) bordrosu. $g: sinif, yon, cari_id, tarih, aciklama?, istek_anahtari? + paralel diziler
 * (seri_no[] banka_ad[] sube[] hesap_no[] borclu[] kefil[] yer_adi[] duzenleme_tarihi[] vade[] tutar[] para_birimi[] karne_id[]; boş satır atılır; en çok CEKSENET_SATIR_MAX).
 * Her satır = kayıt + hareket (giris|cikis) + cari dekontu (alınan: Alacak, verilen: Borç); hepsi TEK transaction (iç hata → CEKSENET_IC_HATA → HEPSİ geri). Ortak bordro_no.
 * @return array{ok:bool,hata:?string,id:?int,idler?:list<int>,bordro_no?:string,tekrar?:bool,ortalama_vade?:array,toplamlar?:array,uyari?:?string,kod?:string,alanlar?:array,satir_hatalari?:array,sinif?:string,yon?:string}
 */
function ceksenet_bordro_kaydet(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = ceksenet_istek_bul($pdo, $ik)) !== null) return ceksenet_tekrar_sonucu($pdo, $var);
    $d = ceksenet_bordro_dogrula($pdo, $g);
    if (!$d['ok']) return ceksenet_hata((string)$d['hata'], ['alanlar' => $d['alanlar'] ?? [], 'satir_hatalari' => $d['satir_hatalari'] ?? []] + (isset($d['kod']) ? ['kod' => $d['kod']] : []));
    $v = $d['veri'];
    $islem = $v['yon'] === 'alinan' ? 'giris' : 'cikis';
    $tn = ceksenet_islemler()[$islem];
    $depo = ceksenet_depo_damgasi();
    $simdi = date('Y-m-d H:i:s');
    $ic = null; $sonuclar = []; $mk = null;
    try {
        $s = ceksenet_tx($pdo, function () use ($pdo, $v, $islem, $tn, $depo, $simdi, $uid, &$ic, &$sonuclar, &$mk): array {
            // Aynı cariye eşzamanlı iki bordroyu sıraya sokar (MySQL; SQLite no-op) + kilit sonrası istek anahtarı yeniden denenir (çift gönderim → tekrar)
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $pdo->prepare('SELECT id FROM cari_kartlar WHERE id = ? FOR UPDATE')->execute([(int)$v['cari']['id']]);
            if ($v['ik'] !== null && ($var = ceksenet_istek_bul($pdo, $v['ik'])) !== null) return ceksenet_tekrar_sonucu($pdo, $var);
            $S = ceksenet_siniflar()[$v['sinif']]['etiket'];
            $kayitIns = $pdo->prepare('INSERT INTO ceksenet_kayitlar (sinif, yon, portfoy_no, seri_no, banka_ad, sube, hesap_no, borclu, kefil, yer_adi, duzenleme_tarihi, vade, tutar, para_birimi, cari_id, durum, yer_cari_id, yer_kasa_id, karne_id, aciklama, depo, bordro_no, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $harIns = $pdo->prepare('INSERT INTO ceksenet_hareketler (kayit_id, islem, tarih, onceki_durum, yeni_durum, cari_id, kasa_id, onceki_yer_cari_id, onceki_yer_kasa_id, cari_hareket_id, cari_hareket2_id, kasa_hareket_id, belge_no, bordro_no, aciklama, depo, istek_anahtari, durum, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ilkHid = null; $kayitlar = [];
            foreach ($v['satirlar'] as $n => $r) {
                // Son kontrol (yazma sırasında; aynı bordroda önceki satırlar zaten yazıldı → onlar da yakalanır). MySQL'de FOR UPDATE ile seri_no yarışı kapatılır (B-23).
                // Hata → HEPSİ geri alınır (closure'dan `return hata` YOK: cari_tx commit ederdi → yarım bordro).
                if (($m = ceksenet_mukerrer_bul($pdo, $v['sinif'], $v['yon'], (string)$r['banka_ad'], (string)$r['hesap_no'], $r['seri_no'], $r['karne_id'], true)) !== null) {
                    $mk = ['kod' => 'mukerrer', 'satir_hatalari' => [$r['i'] => ['seri_no' => 'Zaten kayıtlı: ' . $m['portfoy_no'] . '.']]];
                    throw new RuntimeException('Satır ' . ($r['i'] + 1) . ': bu numara zaten kayıtlı (' . $m['portfoy_no'] . ').', CEKSENET_IC_HATA);
                }
                $kayitIns->execute([$v['sinif'], $v['yon'], '', $r['seri_no'], $r['banka_ad'], $r['sube'], $r['hesap_no'], $r['borclu'], $r['kefil'], $r['yer_adi'], $r['duzenleme_tarihi'], $r['vade'],
                    ceksenet_para_db($r['kurus']), $r['pb'], (int)$v['cari']['id'], $tn['yeni'], null, null, $r['karne_id'], $v['aciklama'] === '' ? null : $v['aciklama'], $depo, '', $simdi, $uid]);
                $kid = (int)$pdo->lastInsertId();
                $portfoy = ceksenet_portfoy_no($v['sinif'], $v['yon'], $v['tarih'], $kid);
                $harIns->execute([$kid, $islem, $v['tarih'], null, $tn['yeni'], (int)$v['cari']['id'], null, null, null, null, null, null, '', '', $v['aciklama'], $depo, $n === 0 ? $v['ik'] : null, 'gecerli', $simdi, $uid]);
                $hid = (int)$pdo->lastInsertId();
                $ilkHid ??= $hid;
                $krow = ['sinif' => $v['sinif'], 'portfoy_no' => $portfoy, 'seri_no' => $r['seri_no'], 'banka_ad' => $r['banka_ad'], 'borclu' => $r['borclu'], 'vade' => $r['vade']];
                $dk = ceksenet_dekont_yaz($pdo, $tn, $v['sinif'], $krow, ['kayit' => (int)$v['cari']['id']], $hid, $v['tarih'], $r['kurus'], $r['pb'], $uid, $ic, null);
                if ($dk['hata'] !== null) throw new RuntimeException('Satır ' . ($r['i'] + 1) . ': ' . $dk['hata'], CEKSENET_IC_HATA);
                $kayitlar[] = ['kid' => $kid, 'hid' => $hid, 'portfoy' => $portfoy, 'cari_hid' => $dk['ids'][0] ?? null, 'cari_no' => $dk['nolar'][0] ?? null, 'kurus' => $r['kurus'], 'pb' => $r['pb'], 'vade' => $r['vade'], 'seri_no' => $r['seri_no'], 'i' => $r['i']];
            }
            $bordro = ceksenet_bordro_no($v['tarih'], (int)$ilkHid);
            $upK = $pdo->prepare('UPDATE ceksenet_kayitlar SET portfoy_no = ?, bordro_no = ? WHERE id = ?');
            $upH = $pdo->prepare('UPDATE ceksenet_hareketler SET belge_no = ?, bordro_no = ?, cari_hareket_id = ?, cari_hareket2_id = ?, kasa_hareket_id = ? WHERE id = ?');
            foreach ($kayitlar as $k) {
                $upK->execute([$k['portfoy'], $bordro, $k['kid']]);
                $upH->execute([ceksenet_belge_no($v['tarih'], $k['hid']), $bordro, $k['cari_hid'], null, null, $k['hid']]);
            }
            return ['ok' => true, 'hata' => null, 'id' => (int)$kayitlar[0]['kid'], 'idler' => array_column($kayitlar, 'kid'), 'bordro_no' => $bordro, 'tekrar' => false,
                'hareket_idler' => array_column($kayitlar, 'hid'), 'kayitlar' => $kayitlar, 'sinif' => $v['sinif'], 'yon' => $v['yon'], 'yeni_durum' => $tn['yeni']];
        });
    } catch (PDOException $e) {
        if ($v['ik'] !== null && ($var = ceksenet_istek_bul($pdo, $v['ik'])) !== null) return ceksenet_tekrar_sonucu($pdo, $var);
        error_log('[ceksenet_bordro_kaydet] ' . $e->getMessage());
        return ceksenet_hata('Bordro kaydedilemedi.');
    } catch (RuntimeException $e) {
        if ($e->getCode() !== CEKSENET_IC_HATA) throw $e;
        $ek = [];
        if (is_array($mk)) $ek += $mk;                                   // kod=mukerrer + satir_hatalari (tx geri alındı)
        elseif (is_array($ic) && !empty($ic['alanlar'])) $ek['alanlar'] = $ic['alanlar'];
        return ceksenet_hata($e->getMessage(), $ek);
    }
    if (empty($s['ok']) || !empty($s['tekrar'])) return $s;
    // COMMIT sonrası: hiçbir hesap istisna fırlatıp kaydı 500'e çevirmez (kayıt yazıldı; audit her koşulda yazılır)
    $tpl = []; $s['toplamlar'] = []; $s['ortalama_vade'] = [];
    try {
        $satirOz = array_map(static fn(array $k): array => ['vade' => $k['vade'], 'kurus' => $k['kurus'], 'para_birimi' => $k['pb']], $s['kayitlar']);
        foreach ($s['kayitlar'] as $k) { $tpl[$k['pb']]['kurus'] = ($tpl[$k['pb']]['kurus'] ?? 0) + $k['kurus']; $tpl[$k['pb']]['adet'] = ($tpl[$k['pb']]['adet'] ?? 0) + 1; }
        $s['toplamlar'] = cari_pb_sirala($tpl);
        $s['ortalama_vade'] = ceksenet_ortalama_vade($satirOz, $v['tarih']) ?? [];
    } catch (Throwable $e) {
        error_log('[ceksenet_bordro_kaydet:hesap] ' . $e->getMessage());
    }
    $s['uyari'] = $v['uyarilar'] ? implode(' ', $v['uyarilar']) : null;
    $s['belge_no'] = $s['bordro_no'];
    unset($s['kayitlar']);
    audit_log_event('create', 'ceksenet', (int)$s['id'], null, ['kayit' => 'bordro', 'islem' => $islem, 'sinif' => $v['sinif'], 'yon' => $v['yon'], 'bordro_no' => $s['bordro_no'], 'cari_id' => (int)$v['cari']['id'],
        'idler' => $s['idler'], 'adet' => count($s['idler']), 'toplamlar' => $tpl, 'tarih' => $v['tarih']]);
    return $s;
}

/**
 * Cari dekontlarını yazar (işlem tanımındaki 'cari' etkilerine göre; dış tx içinde). $hedefler: ['kayit' => cari_id, 'yer_cari' => cari_id|null, 'secilen' => cari_id|null].
 * @return array{hata:?string,ids:list<int>,nolar:list<string>,cariler:list<int>}  İlk hata mesajı ($ic'ye ham sonuç) — çağıran RuntimeException(CEKSENET_IC_HATA) fırlatır.
 */
function ceksenet_dekont_yaz(PDO $pdo, array $tn, string $sinif, array $k, array $hedefler, int $hid, string $tarih, int $kurus, string $pb, int $uid, mixed &$ic, ?string $oncekiDurum): array
{
    $out = ['hata' => null, 'ids' => [], 'nolar' => [], 'cariler' => []];
    $S = ceksenet_siniflar()[$sinif]['etiket'];
    $onek = $sinif === 'senet' && isset($tn['dekont_senet']) ? (string)$tn['dekont_senet'] : str_replace('{S}', $S, (string)$tn['dekont']);
    foreach ($tn['cari'] as $e) {
        if (!empty($e['onceki']) && !in_array((string)$oncekiDurum, $e['onceki'], true)) continue;
        $cid = (int)($hedefler[$e['hedef']] ?? 0);
        if ($cid <= 0) { $out['hata'] = 'Cari dekontu için hedef cari bulunamadı (' . $e['hedef'] . ').'; return $out; }
        $ac = mb_substr($onek . ' ' . $k['portfoy_no'] . ' · Vade ' . ceksenet_vade_bicim((string)$k['vade']) . ' · ' . ceksenet_evrak_ozet($k) . ($e['hedef'] === 'yer_cari' ? ' (ciro geri alma)' : ''), 0, 500);
        $cr = cari_hareket_ekle($pdo, ['tur' => $e['yon'] === 'alacak' ? 'alacak_dekont' : 'borc_dekont', 'cari_id' => $cid, 'tarih' => $tarih, 'tutar' => ceksenet_para_db($kurus),
            'para_birimi' => $pb, 'kur' => '', 'evrak_no' => mb_substr((string)$k['seri_no'], 0, 60), 'aciklama' => $ac], $uid, ['modul' => 'ceksenet', 'id' => $hid]);
        if (empty($cr['ok'])) { $ic = $cr; $out['hata'] = 'Cari hareketi yazılamadı: ' . (string)($cr['hata'] ?? 'bilinmeyen hata'); return $out; }
        $out['ids'][] = (int)$cr['id']; $out['nolar'][] = (string)$cr['belge_no']; $out['cariler'][] = $cid;
    }
    return $out;
}

// ── Geçiş (durum değiştirme) ────────────────────────────────────────────────

/**
 * Kasa çözümü (YAZMAZ): işlemin kasa_sec / para kuralına göre kasa satırı ya da hata. @return array{0:?array,1:?string,2:?string} [kasa|null, hata|null, alan]
 */
function ceksenet_kasa_coz(PDO $pdo, array $tn, array $kayit, int $seciliKasaId): array
{
    $id = 0; $karneden = false;
    if (($tn['para']['kasa'] ?? '') === 'yer') $id = (int)($kayit['yer_kasa_id'] ?? 0);
    elseif ($tn['kasa_sec'] !== null) {
        $id = $seciliKasaId;
        if ($id <= 0 && $tn['yeni'] === 'odendi' && !empty($kayit['karne_id'])) {   // verilen çek: karnenin banka hesabı varsayılan
            $kt = ceksenet_tanim_bul($pdo, (int)$kayit['karne_id']);
            $id = (int)($kt['kasa_id'] ?? 0); $karneden = true;
        }
    } else {
        return [null, null, null];
    }
    $zorunlu = ($tn['para']['kasa'] ?? '') === 'yer' || !empty($tn['kasa_sec']['zorunlu']);
    if ($id <= 0) return $zorunlu ? [null, ($tn['para']['kasa'] ?? '') === 'yer' ? 'Kayıtta tahsilat bankası bulunamadı.' : 'Kasa / banka hesabı seçin.', 'kasa_id'] : [null, null, null];
    $kasa = kasa_kart_oku($pdo, $id);
    if ($kasa === null) return [null, $karneden ? 'Karnenin banka hesabı bulunamadı; kasa / banka hesabını elle seçin.' : 'Kasa bulunamadı.', 'kasa_id'];
    if ((int)$kasa['aktif'] !== 1) return [null, $karneden ? 'Karnenin banka hesabı pasif; kasa / banka hesabını elle seçin.' : 'Pasif kasa / banka hesabı seçilemez.', 'kasa_id'];
    if (!kasa_gorunur($kasa)) return [null, 'Bu kasa aktif deponuzda görünmüyor.', 'kasa_id'];
    if ((string)$kasa['para_birimi'] !== (string)$kayit['para_birimi']) return [null, 'Kasa para birimi (' . $kasa['para_birimi'] . ') evrakın para biriminden (' . $kayit['para_birimi'] . ') farklı.', 'kasa_id'];
    $tip = $tn['kasa_sec']['tip'] ?? null;
    if ($tip !== null && (string)$kasa['tip'] !== $tip) return [null, 'Yalnız banka hesabı seçilebilir.', 'kasa_id'];
    return [$kasa, null, null];
}

/**
 * Durum geçişi (tek ya da toplu). $g: ids[] (ya da id), gecis (işlem adı; ya da islem — `islem` uçta yönlendirme anahtarıdır, bu yüzden uç `gecis` gönderir),
 * tarih, cari_id? (ciro), kasa_id? (tahsile_ver / teminata_ver / tahsil_kasa / odendi), aciklama?, istek_anahtari?, eksiye_izin?.
 * Toplu: hepsi aynı sınıf/yönde ve işlem için izinli olmalı; biri uymazsa HİÇBİRİ yazılmaz → 409 gecersiz_gecis + kayitlar[] (hangileri, neden).
 * Her kayıt için hareket + (varsa) cari dekontları + (varsa) kasa hareketi + kayıt güncellemesi; TEK transaction (iç hata → hepsi geri). Ortak bordro_no.
 * Yetersiz kasa bakiyesi (odendi) → 409 yetersiz_bakiye (+ bakiye, eksik); eksiye_izin=1 + aynı istek_anahtari ile yeniden.
 * @return array{ok:bool,hata:?string,id:?int,idler?:list<int>,bordro_no?:string,tekrar?:bool,uyari?:?string,kod?:string,kayitlar?:list<array>,sonuclar?:list<array>,yeni_durum?:string,sinif?:string,yon?:string}
 */
function ceksenet_islem_uygula(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = ceksenet_istek_bul($pdo, $ik)) !== null) return ceksenet_tekrar_sonucu($pdo, $var);
    $islem = (string)($g['gecis'] ?? $g['islem'] ?? '');
    $tn = ceksenet_islemler()[$islem] ?? null;
    if ($tn === null || !$tn['onceki']) return ceksenet_hata('Geçersiz işlem.', ['alanlar' => ['gecis' => 'Geçersiz işlem.']]);
    $h = [];
    $tarih = ceksenet_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) $h['tarih'] = 'Geçerli bir tarih girin.';
    $ac = trim((string)($g['aciklama'] ?? ''));
    if (mb_strlen($ac) > 500) $h['aciklama'] = 'Açıklama en çok 500 karakter.';
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    $ham = is_array($g['ids'] ?? null) ? $g['ids'] : (isset($g['id']) ? [$g['id']] : []);
    $ids = [];
    foreach ($ham as $x) if (is_scalar($x) && ctype_digit((string)$x) && (int)$x > 0) $ids[(int)$x] = (int)$x;
    $ids = array_values($ids);
    if (!$ids) $h['ids'] = 'En az bir kayıt seçin.';
    elseif (count($ids) > CEKSENET_TOPLU_MAX) $h['ids'] = 'Tek seferde en çok ' . CEKSENET_TOPLU_MAX . ' kayıt işlenebilir.';
    $cari = null;
    if ($tn['cari_zorunlu']) {
        $cid = (int)($g['cari_id'] ?? 0);
        $cari = $cid > 0 ? cari_kart_oku($pdo, $cid) : null;
        if ($cari === null) $h['cari_id'] = 'Cari seçin.';
        elseif ((int)$cari['aktif'] !== 1) $h['cari_id'] = 'Pasif cariye işlem yapılamaz.';
    }
    if ($h) return ceksenet_hata((string)reset($h), ['alanlar' => $h]);
    $seciliKasa = (int)($g['kasa_id'] ?? 0);
    $eksiyeIzin = ceksenet_onay($g['eksiye_izin'] ?? '');
    $depo = ceksenet_depo_damgasi();
    $simdi = date('Y-m-d H:i:s');
    $ic = null;
    try {
        $s = ceksenet_tx($pdo, function () use ($pdo, $tn, $islem, $ids, $tarih, $ac, $cari, $seciliKasa, $eksiyeIzin, $depo, $simdi, $uid, $ik, &$ic): array {
            $rows = ceksenet_kayitlari_kilitle_oku($pdo, $ids);   // İLK ifade: kilitli okuma (kilitsiz okuma MySQL RR görüntüsünü sabitlerdi)
            if ($ik !== '' && ($var = ceksenet_istek_bul($pdo, $ik)) !== null) return ceksenet_tekrar_sonucu($pdo, $var);   // kilit beklerken aynı istek tamamlanmış olabilir
            $gecersiz = [];
            foreach ($ids as $i) {
                if (!isset($rows[$i])) { $gecersiz[] = ['id' => $i, 'portfoy_no' => null, 'durum' => null, 'neden' => 'Kayıt bulunamadı.']; continue; }
                $k = $rows[$i];
                $neden = null;
                if (!ceksenet_gorunur($k)) $neden = 'Aktif deponuzda görünmüyor.';
                elseif ((string)$k['yon'] !== $tn['yon']) $neden = 'Bu işlem ' . ceksenet_yonler()[$tn['yon']] . ' evraklara uygulanır.';
                elseif (!in_array((string)$k['durum'], $tn['onceki'], true)) $neden = '"' . ceksenet_durum_etiketi((string)$k['durum'], (string)$k['sinif']) . '" durumundaki evrak için "' . ceksenet_islem_etiketi($islem, (string)$k['sinif']) . '" yapılamaz.';
                if ($neden !== null) $gecersiz[] = ['id' => $i, 'portfoy_no' => $k['portfoy_no'], 'durum' => $k['durum'], 'neden' => $neden];
            }
            $siniflar = array_unique(array_map(static fn(array $k): string => $k['sinif'] . '|' . $k['yon'], $rows));
            if (!$gecersiz && count($siniflar) > 1) {
                foreach ($rows as $k) $gecersiz[] = ['id' => (int)$k['id'], 'portfoy_no' => $k['portfoy_no'], 'durum' => $k['durum'], 'neden' => 'Toplu işlemde evraklar aynı sınıf ve yönde olmalı.'];
            }
            if ($gecersiz) {
                return ceksenet_hata('İşlem yapılamadı: ' . $gecersiz[0]['neden'] . (count($gecersiz) > 1 ? ' (+' . (count($gecersiz) - 1) . ' kayıt daha)' : '') . ' Hiçbir kayıt değiştirilmedi.',
                    ['kod' => 'gecersiz_gecis', 'kayitlar' => $gecersiz]);
            }
            // Kasa çözümü / doğrulaması (yazma öncesi; kayıt başına)
            $kasalar = [];
            foreach ($ids as $i) {
                [$kasa, $kh, $alan] = ceksenet_kasa_coz($pdo, $tn, $rows[$i], $seciliKasa);
                if ($kh !== null) return ceksenet_hata($rows[$i]['portfoy_no'] . ': ' . $kh, ['alanlar' => [$alan => $kh]]);
                $kasalar[$i] = $kasa;
            }
            $harIns = $pdo->prepare('INSERT INTO ceksenet_hareketler (kayit_id, islem, tarih, onceki_durum, yeni_durum, cari_id, kasa_id, onceki_yer_cari_id, onceki_yer_kasa_id, cari_hareket_id, cari_hareket2_id, kasa_hareket_id, belge_no, bordro_no, aciklama, depo, istek_anahtari, durum, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $upK = $pdo->prepare('UPDATE ceksenet_kayitlar SET durum = ?, yer_cari_id = ?, yer_kasa_id = ?, updated_at = ?, updated_by = ? WHERE id = ? AND durum = ?');
            $sonuclar = []; $uyarilar = []; $ilkHid = null;
            foreach ($ids as $n => $i) {
                $k = $rows[$i]; $kasa = $kasalar[$i];
                $kurus = cari_kurus($k['tutar']); $pb = (string)$k['para_birimi'];
                $yc = $k['yer_cari_id'] !== null ? (int)$k['yer_cari_id'] : null; $yk = $k['yer_kasa_id'] !== null ? (int)$k['yer_kasa_id'] : null;
                $oncekiDurum = (string)$k['durum'];
                // hareket (önce satır — cari / kasa kaynak id'si olarak hareket id'si gerekir)
                $birincilCari = $tn['cari'] ? (($tn['cari'][0]['hedef'] === 'secilen') ? (int)($cari['id'] ?? 0) : (int)$k['cari_id']) : null;
                $harIns->execute([$i, $islem, $tarih, $oncekiDurum, $tn['yeni'], $birincilCari ?: null, $kasa !== null ? (int)$kasa['id'] : null, $yc, $yk, null, null, null, '', '', $ac, $depo, $n === 0 && $ik !== '' ? $ik : null, 'gecerli', $simdi, $uid]);
                $hid = (int)$pdo->lastInsertId();
                $ilkHid ??= $hid;
                $dk = ceksenet_dekont_yaz($pdo, $tn, (string)$k['sinif'], $k, ['kayit' => (int)$k['cari_id'], 'yer_cari' => $yc, 'secilen' => (int)($cari['id'] ?? 0)], $hid, $tarih, $kurus, $pb, $uid, $ic, $oncekiDurum);
                if ($dk['hata'] !== null) throw new RuntimeException($k['portfoy_no'] . ': ' . $dk['hata'], CEKSENET_IC_HATA);
                $kasaHid = null; $kasaNo = null;
                if ($tn['para'] !== null) {
                    $S = ceksenet_siniflar()[$k['sinif']]['etiket'];
                    $kr = kasa_hareket_ekle($pdo, ['kasa_id' => (string)$kasa['id'], 'tur' => $tn['para']['yon'] === 'giris' ? 'kaynak_giris' : 'kaynak_cikis', 'tarih' => $tarih, 'tutar' => ceksenet_para_db($kurus),
                        'aciklama' => mb_substr($S . ($tn['para']['yon'] === 'giris' ? ' tahsilatı ' : ' ödemesi ') . $k['portfoy_no'] . ' · Vade ' . ceksenet_vade_bicim((string)$k['vade']) . ' · ' . ceksenet_evrak_ozet($k) . ($ac !== '' ? ' · ' . $ac : ''), 0, 500),
                        'eksiye_izin' => $eksiyeIzin ? '1' : '0'], $uid, ['modul' => 'ceksenet', 'id' => $hid]);
                    if (empty($kr['ok'])) { $ic = $kr + ['portfoy_no' => $k['portfoy_no']]; throw new RuntimeException($k['portfoy_no'] . ': ' . (string)($kr['hata'] ?? 'Kasa hareketi yazılamadı.'), CEKSENET_IC_HATA); }
                    $kasaHid = (int)$kr['id']; $kasaNo = (string)$kr['belge_no'];
                    if (!empty($kr['uyari'])) $uyarilar[] = (string)$kr['uyari'];
                }
                switch ($tn['yer']) {
                    case 'cari': $yc = (int)$cari['id']; $yk = null; break;
                    case 'kasa': $yc = null; $yk = $kasa !== null ? (int)$kasa['id'] : null; break;
                    case 'temizle': $yc = null; $yk = null; break;
                }
                $upK->execute([$tn['yeni'], $yc, $yk, $simdi, $uid, $i, $oncekiDurum]);
                if ($upK->rowCount() !== 1) throw new RuntimeException($k['portfoy_no'] . ': kayıt başka bir işlemle değişmiş; yeniden deneyin.', CEKSENET_IC_HATA);
                $sonuclar[] = ['id' => $i, 'portfoy_no' => $k['portfoy_no'], 'hareket_id' => $hid, 'onceki_durum' => $oncekiDurum, 'cari_hareket_id' => $dk['ids'][0] ?? null, 'cari_belge_no' => $dk['nolar'][0] ?? null,
                    'cari_hareket2_id' => $dk['ids'][1] ?? null, 'cari_belge_no2' => $dk['nolar'][1] ?? null, 'kasa_hareket_id' => $kasaHid, 'kasa_belge_no' => $kasaNo];
            }
            $bordro = ceksenet_bordro_no($tarih, (int)$ilkHid);
            $upH = $pdo->prepare('UPDATE ceksenet_hareketler SET belge_no = ?, bordro_no = ?, cari_hareket_id = ?, cari_hareket2_id = ?, kasa_hareket_id = ? WHERE id = ?');
            foreach ($sonuclar as $r) $upH->execute([ceksenet_belge_no($tarih, $r['hareket_id']), $bordro, $r['cari_hareket_id'], $r['cari_hareket2_id'], $r['kasa_hareket_id'], $r['hareket_id']]);
            $ilk = $rows[$ids[0]];
            return ['ok' => true, 'hata' => null, 'id' => $ids[0], 'idler' => $ids, 'bordro_no' => $bordro, 'belge_no' => ceksenet_belge_no($tarih, (int)$sonuclar[0]['hareket_id']), 'tekrar' => false,
                'sonuclar' => $sonuclar, 'hareket_idler' => array_column($sonuclar, 'hareket_id'), 'yeni_durum' => $tn['yeni'], 'sinif' => $ilk['sinif'], 'yon' => $ilk['yon'],
                'uyari' => $uyarilar ? implode(' ', array_unique($uyarilar)) : null];
        });
    } catch (PDOException $e) {
        if ($ik !== '' && ($var = ceksenet_istek_bul($pdo, $ik)) !== null) return ceksenet_tekrar_sonucu($pdo, $var);
        error_log('[ceksenet_islem_uygula] ' . $e->getMessage());
        return ceksenet_hata('İşlem kaydedilemedi.');
    } catch (RuntimeException $e) {
        if ($e->getCode() !== CEKSENET_IC_HATA) throw $e;
        $ek = [];
        if (is_array($ic)) {
            if (isset($ic['kod'])) $ek['kod'] = $ic['kod'];
            if (isset($ic['bakiye'])) $ek['bakiye'] = $ic['bakiye'];
            if (isset($ic['eksik'])) $ek['eksik'] = $ic['eksik'];
            if (!empty($ic['alanlar'])) $ek['alanlar'] = $ic['alanlar'];
        }
        return ceksenet_hata($e->getMessage(), $ek);
    }
    if (empty($s['ok']) || !empty($s['tekrar'])) return $s;
    audit_log_event('update', 'ceksenet', (int)$s['id'], ['kayit' => 'islem', 'onceki_durumlar' => array_column($s['sonuclar'], 'onceki_durum', 'id')],
        ['islem' => $islem, 'yeni_durum' => $tn['yeni'], 'idler' => $s['idler'], 'bordro_no' => $s['bordro_no'], 'tarih' => $tarih, 'cari_id' => $cari['id'] ?? null, 'kasa_id' => $seciliKasa ?: null,
            'hareketler' => $s['hareket_idler'], 'uyari' => $s['uyari']]);
    return $s;
}

/**
 * Hareket iptali (düzeltme = iptal + yeniden gir). YALNIZ kaydın SON geçerli hareketi iptal edilir (aksi 409 son_degil). Durum + yer önceki değerlere döner;
 * giriş / çıkış iptali = kayıt durumu `iptal`. Bağlı cari dekontları (en çok 2) cari_kaynakli_iptal ile, kasa hareketi kasa_kaynakli_iptal ile iptal edilir (tek tx).
 * @return array{ok:bool,hata:?string,id:?int,kayit_id?:int,belge_no?:string,yeni_durum?:string,uyari?:?string,cari_iptal?:list<int>,kasa_iptal?:list<int>,kod?:string}
 */
function ceksenet_hareket_iptal(PDO $pdo, int $hid, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (mb_strlen($neden) < 3) return ceksenet_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]);
    if (mb_strlen($neden) > 500) return ceksenet_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
    $h = ceksenet_hareket_oku($pdo, $hid);
    if ($h === null) return ceksenet_hata('Hareket bulunamadı.');
    if ($h['durum'] === 'iptal') return ceksenet_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
    $k0 = ceksenet_kayit_oku($pdo, (int)$h['kayit_id']);
    if ($k0 === null) return ceksenet_hata('Kayıt bulunamadı.');
    if (!ceksenet_gorunur($k0)) return ceksenet_hata('Bu kayıt aktif deponuzda görünmüyor.');
    $sebep = mb_substr('Çek-senet iptali: ' . $neden, 0, 500);
    $ic = null;
    try {
        $kayitId = (int)$h['kayit_id'];   // tx DIŞINDA okundu: tx'in İLK ifadesi kayıt satırı kilidi olsun (MySQL RR görüntüsü kilitten sonra alınsın)
        $s = ceksenet_tx($pdo, function () use ($pdo, $hid, $kayitId, $neden, $sebep, $uid, &$ic): array {
            $rows = ceksenet_kayitlari_kilitle_oku($pdo, [$kayitId]);
            $h = ceksenet_hareket_oku($pdo, $hid);
            if ($h === null || !$rows) return ceksenet_hata('Hareket bulunamadı.');
            if ($h['durum'] === 'iptal') return ceksenet_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
            $k = $rows[(int)$h['kayit_id']];
            $st = $pdo->prepare("SELECT id, belge_no FROM ceksenet_hareketler WHERE kayit_id = ? AND durum = 'gecerli' ORDER BY id DESC LIMIT 1");
            $st->execute([(int)$h['kayit_id']]);
            $son = $st->fetch(PDO::FETCH_ASSOC);
            if ($son === false || (int)$son['id'] !== $hid) {
                return ceksenet_hata('Yalnız kaydın son hareketi iptal edilebilir; önce ' . ($son['belge_no'] ?? 'sonraki hareketi') . ' iptal edin.', ['kod' => 'son_degil']);
            }
            $up = $pdo->prepare("UPDATE ceksenet_hareketler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'");
            $up->execute([date('Y-m-d H:i:s'), $uid, $neden, $hid]);
            if ($up->rowCount() !== 1) return ceksenet_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
            $cariIptal = []; $kasaIptal = []; $uyari = null;
            // HER ZAMAN çağrılır (ikisi de idempotent): hareket satırındaki *_hareket_id boş kalmış olsa da (eski yarım yazım) kaynaklı dekont / kasa satırı temizlenir
            $cr = cari_kaynakli_iptal($pdo, 'ceksenet', $hid, $sebep, $uid);   // iki dekontu birden iptal eder (aynı kaynak id)
            if (empty($cr['ok'])) throw new RuntimeException('Bağlı cari hareketi iptal edilemedi: ' . (string)($cr['hata'] ?? ''), CEKSENET_IC_HATA);
            $cariIptal = $cr['iptal_idler'];
            $kr = kasa_kaynakli_iptal($pdo, 'ceksenet', $hid, $sebep, $uid);
            if (empty($kr['ok'])) throw new RuntimeException('Bağlı kasa hareketi iptal edilemedi: ' . (string)($kr['hata'] ?? ''), CEKSENET_IC_HATA);
            $kasaIptal = $kr['iptal_idler']; $uyari = $kr['uyari'] ?? null;
            $olusturma = in_array((string)$h['islem'], ['giris', 'cikis'], true);
            $yeni = $olusturma ? 'iptal' : (string)$h['onceki_durum'];
            $upK = $pdo->prepare('UPDATE ceksenet_kayitlar SET durum = ?, yer_cari_id = ?, yer_kasa_id = ?, updated_at = ?, updated_by = ? WHERE id = ? AND durum = ?');
            $upK->execute([$yeni, $olusturma ? null : $h['onceki_yer_cari_id'], $olusturma ? null : $h['onceki_yer_kasa_id'], date('Y-m-d H:i:s'), $uid, (int)$h['kayit_id'], (string)$h['yeni_durum']]);
            if ($upK->rowCount() !== 1) {   // kayıt, bu hareketin bıraktığı durumda değil (başka işlem araya girdi) → HEPSİ geri
                $ic = ['kod' => 'son_degil'];
                throw new RuntimeException('Kayıt başka bir işlemle değişmiş; yalnız son hareket iptal edilebilir. Yeniden deneyin.', CEKSENET_IC_HATA);
            }
            return ['ok' => true, 'hata' => null, 'id' => $hid, 'kayit_id' => (int)$h['kayit_id'], 'belge_no' => (string)$h['belge_no'], 'bordro_no' => (string)$h['bordro_no'],
                'yeni_durum' => $yeni, 'onceki_durum' => (string)$k['durum'], 'islem' => (string)$h['islem'], 'cari_iptal' => $cariIptal, 'kasa_iptal' => $kasaIptal, 'uyari' => $uyari,
                'sinif' => (string)$k['sinif'], 'yon' => (string)$k['yon']];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== CEKSENET_IC_HATA) throw $e;
        return ceksenet_hata($e->getMessage(), is_array($ic) && isset($ic['kod']) ? ['kod' => $ic['kod']] : []);
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('cancel', 'ceksenet', $hid, ['kayit' => 'hareket', 'durum' => 'gecerli', 'islem' => $s['islem'], 'kayit_id' => $s['kayit_id'], 'belge_no' => $s['belge_no'], 'kayit_durumu' => $s['onceki_durum']],
        ['durum' => 'iptal', 'neden' => $neden, 'kayit_durumu' => $s['yeni_durum'], 'cari_iptal' => $s['cari_iptal'], 'kasa_iptal' => $s['kasa_iptal'], 'uyari' => $s['uyari']]);
    return $s;
}

// ── Okuma: kayıt, liste, özet ───────────────────────────────────────────────

/**
 * Kayıt + hareket geçmişi (zaman sırası). Aktif depoda görünmüyorsa / yoksa null.
 * kayit: tüm kolonlar + cari_unvan/cari_kod, yer_cari_unvan, yer_kasa_ad, durum_etiket, durum_sinif, tutar_kurus, izinli (ceksenet_izinli_islemler).
 * hareketler[]: satır + islem_etiket, cari_unvan, kasa_ad, cari_belge_no, cari_belge_no2, kasa_belge_no, son (iptal edilebilir son geçerli hareket mi).
 * @return array{kayit:array,hareketler:list<array>}|null
 */
function ceksenet_oku(PDO $pdo, int $id): ?array
{
    $k = ceksenet_kayit_oku($pdo, $id);
    if ($k === null || !ceksenet_gorunur($k)) return null;
    $k = ceksenet_satir_zenginlestir($pdo, [$k])[0];
    $st = $pdo->prepare('SELECT * FROM ceksenet_hareketler WHERE kayit_id = ? ORDER BY id');
    $st->execute([$id]);
    $har = $st->fetchAll(PDO::FETCH_ASSOC);
    $sonId = 0;
    foreach ($har as $h) if ($h['durum'] === 'gecerli') $sonId = (int)$h['id'];
    foreach ($har as &$h) {
        $c = !empty($h['cari_id']) ? ceksenet_cari_ad($pdo, (int)$h['cari_id']) : null;
        $kasa = !empty($h['kasa_id']) ? kasa_kart_oku($pdo, (int)$h['kasa_id']) : null;
        $ch = !empty($h['cari_hareket_id']) ? cari_hareket_oku($pdo, (int)$h['cari_hareket_id']) : null;
        $ch2 = !empty($h['cari_hareket2_id']) ? cari_hareket_oku($pdo, (int)$h['cari_hareket2_id']) : null;
        $kh = !empty($h['kasa_hareket_id']) ? kasa_hareket_oku($pdo, (int)$h['kasa_hareket_id']) : null;
        $h['islem_etiket'] = ceksenet_islem_etiketi((string)$h['islem'], (string)$k['sinif']);
        $h['cari_unvan'] = $c['unvan'] ?? null; $h['kasa_ad'] = $kasa['ad'] ?? null;
        $h['cari_belge_no'] = $ch['belge_no'] ?? null; $h['cari_belge_no2'] = $ch2['belge_no'] ?? null; $h['kasa_belge_no'] = $kh['belge_no'] ?? null;
        $h['onceki_durum_etiket'] = $h['onceki_durum'] !== null ? ceksenet_durum_etiketi((string)$h['onceki_durum'], (string)$k['sinif']) : null;
        $h['yeni_durum_etiket'] = ceksenet_durum_etiketi((string)$h['yeni_durum'], (string)$k['sinif']);
        $h['son'] = (int)$h['id'] === $sonId && $h['durum'] === 'gecerli';
    }
    unset($h);
    return ['kayit' => $k, 'hareketler' => $har];
}

/** @param list<array> $satirlar Kayıt satırlarına cari/kasa adları, durum etiketi, kuruş, izinli işlemler, vade durumu ekler. @return list<array> */
function ceksenet_satir_zenginlestir(PDO $pdo, array $satirlar, ?string $bugun = null): array
{
    $bugun ??= date('Y-m-d');
    $b = (int)(strtotime($bugun . ' 00:00:00 UTC') / 86400);
    $acik = ceksenet_acik_durumlar();
    foreach ($satirlar as &$r) {
        $c = ceksenet_cari_ad($pdo, (int)$r['cari_id']);
        $yc = !empty($r['yer_cari_id']) ? ceksenet_cari_ad($pdo, (int)$r['yer_cari_id']) : null;
        $yk = !empty($r['yer_kasa_id']) ? kasa_kart_oku($pdo, (int)$r['yer_kasa_id']) : null;
        $r['cari_unvan'] = $c['unvan'] ?? null; $r['cari_kod'] = $c['kod'] ?? null; $r['yer_cari_unvan'] = $yc['unvan'] ?? null; $r['yer_kasa_ad'] = $yk['ad'] ?? null;
        $r['tutar_kurus'] = cari_kurus($r['tutar']);
        $r['durum_etiket'] = ceksenet_durum_etiketi((string)$r['durum'], (string)$r['sinif']);
        $r['durum_sinif'] = ceksenet_durumlar()[$r['durum']]['sinif'] ?? 'cs-d-iptal';
        $r['vade_gun'] = (int)(strtotime($r['vade'] . ' 00:00:00 UTC') / 86400) - $b;   // vadeye kalan gün (negatif = geçmiş)
        $r['vade_gecmis'] = in_array($r['durum'], $acik, true) && $r['vade_gun'] < 0;
        $r['vade_yakin'] = in_array($r['durum'], $acik, true) && $r['vade_gun'] >= 0 && $r['vade_gun'] <= 7;
        $r['izinli'] = ceksenet_izinli_islemler($r);
    }
    unset($r);
    return $satirlar;
}

/** Vade dönemi → [bas, bit] (Y-m-d ya da ''). tumu | gecmis | 7g | 30g | bu_ay | ozel (bas / bit). */
function ceksenet_vade_araligi(array $f, ?string $bugun = null): array
{
    $bugun ??= date('Y-m-d');
    $d = (string)($f['donem'] ?? 'tumu');
    $bas = ''; $bit = '';
    if ($d === 'gecmis') $bit = date('Y-m-d', strtotime($bugun . ' -1 day'));
    elseif ($d === '7g') { $bas = $bugun; $bit = date('Y-m-d', strtotime($bugun . ' +7 days')); }
    elseif ($d === '30g') { $bas = $bugun; $bit = date('Y-m-d', strtotime($bugun . ' +30 days')); }
    elseif ($d === 'bu_ay') { $bas = date('Y-m-01', strtotime($bugun)); $bit = date('Y-m-t', strtotime($bugun)); }
    elseif ($d === 'ozel') { $bas = ceksenet_vade_coz($f['bas'] ?? '') ?? ''; $bit = ceksenet_vade_coz($f['bit'] ?? '') ?? ''; }
    return [$bas, $bit];
}

/** Liste filtresi → [WHERE, parametreler]. Kayıt deposu aktif depo + boş depo süzgeçlidir. */
function ceksenet_liste_where(PDO $pdo, string $sinif, array $f): array
{
    $w = ['k.sinif = ?']; $p = [$sinif];
    [$dw, $dp] = ceksenet_depo_filtre('k.depo');
    $w[] = $dw; array_push($p, ...$dp);
    if (!empty($f['yon']) && isset(ceksenet_yonler()[(string)$f['yon']])) { $w[] = 'k.yon = ?'; $p[] = (string)$f['yon']; }
    $d = (string)($f['durum'] ?? '');
    if ($d === 'acik') { $a = ceksenet_acik_durumlar(); $w[] = 'k.durum IN (' . implode(',', array_fill(0, count($a), '?')) . ')'; array_push($p, ...$a); }
    elseif ($d !== '' && $d !== 'tumu' && isset(ceksenet_durumlar()[$d])) { $w[] = 'k.durum = ?'; $p[] = $d; }
    if (!empty($f['cari'])) { $w[] = 'k.cari_id = ?'; $p[] = (int)$f['cari']; }
    [$bas, $bit] = ceksenet_vade_araligi($f, isset($f['bugun']) ? (ceksenet_vade_coz($f['bugun']) ?? null) : null);
    if ($bas !== '') { $w[] = 'k.vade >= ?'; $p[] = $bas; }
    if ($bit !== '') { $w[] = 'k.vade <= ?'; $p[] = $bit; }
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $l = '%' . $q . '%';
        $kosul = 'k.seri_no LIKE ? OR k.portfoy_no LIKE ? OR k.banka_ad LIKE ? OR k.borclu LIKE ? OR k.kefil LIKE ? OR k.bordro_no LIKE ? OR k.aciklama LIKE ?';
        array_push($p, $l, $l, $l, $l, $l, $l, $l);
        $cids = array_map(static fn(array $c): int => (int)$c['id'], array_slice(cari_kart_listesi($pdo, ['q' => $q])['satirlar'], 0, 200));   // cari unvanı: cari_core fonksiyonuyla çözülür
        if ($cids) { $kosul .= ' OR k.cari_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ')'; array_push($p, ...$cids); }
        $w[] = '(' . $kosul . ')';
    }
    return [implode(' AND ', $w), $p];
}

/**
 * Liste (vadeye göre artan: vade, id). $f: yon, durum (tek durum | acik | tumu), cari, ara, donem (tumu|gecmis|7g|30g|bu_ay|ozel + bas, bit; vade üzerinden), bugun? (test).
 * Satırlar ceksenet_satir_zenginlestir ile (cari_unvan, durum_etiket, vade_gun, vade_gecmis, vade_yakin, izinli…). ozet: süzgeçteki İPTAL OLMAYAN kayıtların (durum=iptal süzgecinde İPTAL edilenlerin, ozet_iptal=true) para birimi başına adet + kuruş.
 * @return array{satirlar:list<array>,toplam_satir:int,ozet:array<string,array{adet:int,kurus:int}>}
 */
function ceksenet_listesi(PDO $pdo, string $sinif, array $f = [], int $limit = 100, int $offset = 0): array
{
    if (!isset(ceksenet_siniflar()[$sinif])) return ['satirlar' => [], 'toplam_satir' => 0, 'ozet' => []];
    [$w, $p] = ceksenet_liste_where($pdo, $sinif, $f);
    $st = $pdo->prepare("SELECT COUNT(*) FROM ceksenet_kayitlar k WHERE $w");
    $st->execute($p);
    $toplam = (int)$st->fetchColumn();
    $limit = max(1, min(1000, $limit)); $offset = max(0, $offset);
    $st = $pdo->prepare("SELECT k.* FROM ceksenet_kayitlar k WHERE $w ORDER BY k.vade ASC, k.id ASC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $satirlar = ceksenet_satir_zenginlestir($pdo, $st->fetchAll(PDO::FETCH_ASSOC), isset($f['bugun']) ? (ceksenet_vade_coz($f['bugun']) ?? null) : null);
    $iptalSuz = (string)($f['durum'] ?? '') === 'iptal';   // durum=iptal süzgecinde özet de iptal edilenlerin toplamı (liste ile tutarlı)
    $st = $pdo->prepare("SELECT k.para_birimi AS pb, COUNT(*) AS adet, SUM(k.tutar) AS t FROM ceksenet_kayitlar k WHERE $w" . ($iptalSuz ? '' : " AND k.durum <> 'iptal'") . ' GROUP BY k.para_birimi');
    $st->execute($p);
    $ozet = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ozet[(string)$r['pb']] = ['adet' => (int)$r['adet'], 'kurus' => cari_kurus($r['t'])];
    return ['satirlar' => $satirlar, 'toplam_satir' => $toplam, 'ozet' => cari_pb_sirala($ozet), 'ozet_iptal' => $iptalSuz];
}

/**
 * Sınıf özeti (iptaller HARİÇ; kayıt deposu aktif depo + boş depo): durum × para birimi toplamları + açık durumlar toplamı + vadesi geçmiş açık adet/tutar.
 * $yon verilirse yalnız o yön. $bugun test içindir.
 * @return array{durum:array<string,array<string,array{adet:int,kurus:int}>>,acik:array<string,array{adet:int,kurus:int}>,vadesi_gecmis:array<string,array{adet:int,kurus:int}>}
 */
function ceksenet_ozet(PDO $pdo, string $sinif, ?string $yon = null, ?string $bugun = null): array
{
    $bos = ['durum' => [], 'acik' => [], 'vadesi_gecmis' => []];
    if (!isset(ceksenet_siniflar()[$sinif])) return $bos;
    $bugun ??= date('Y-m-d');
    $w = ["k.sinif = ?", "k.durum <> 'iptal'"]; $p = [$sinif];
    [$dw, $dp] = ceksenet_depo_filtre('k.depo');
    $w[] = $dw; array_push($p, ...$dp);
    if ($yon !== null && isset(ceksenet_yonler()[$yon])) { $w[] = 'k.yon = ?'; $p[] = $yon; }
    $acik = ceksenet_acik_durumlar();
    $ph = implode(',', array_fill(0, count($acik), '?'));
    $st = $pdo->prepare('SELECT k.durum, k.para_birimi AS pb, COUNT(*) AS adet, SUM(k.tutar) AS t FROM ceksenet_kayitlar k WHERE ' . implode(' AND ', $w) . ' GROUP BY k.durum, k.para_birimi');
    $st->execute($p);
    $o = $bos;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $x = ['adet' => (int)$r['adet'], 'kurus' => cari_kurus($r['t'])];
        $o['durum'][(string)$r['durum']][(string)$r['pb']] = $x;
        if (in_array((string)$r['durum'], $acik, true)) {
            $o['acik'][(string)$r['pb']] ??= ['adet' => 0, 'kurus' => 0];
            $o['acik'][(string)$r['pb']]['adet'] += $x['adet']; $o['acik'][(string)$r['pb']]['kurus'] += $x['kurus'];
        }
    }
    $st = $pdo->prepare('SELECT k.para_birimi AS pb, COUNT(*) AS adet, SUM(k.tutar) AS t FROM ceksenet_kayitlar k WHERE ' . implode(' AND ', $w) . " AND k.durum IN ($ph) AND k.vade < ? GROUP BY k.para_birimi");
    $st->execute([...$p, ...$acik, $bugun]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $o['vadesi_gecmis'][(string)$r['pb']] = ['adet' => (int)$r['adet'], 'kurus' => cari_kurus($r['t'])];
    foreach (['durum'] as $a) foreach ($o[$a] as $d => $m) $o[$a][$d] = cari_pb_sirala($m);
    $o['acik'] = cari_pb_sirala($o['acik']); $o['vadesi_gecmis'] = cari_pb_sirala($o['vadesi_gecmis']);
    return $o;
}

/**
 * Seçili kayıtların özeti (satır seçimi → alt çubuk): adet, para birimi başına toplam, ağırlıklı ortalama vade (baz = $baz ya da bugün). Görünmeyen / iptal kayıtlar atlanır.
 * @param list<int> $ids @return array{adet:int,toplamlar:array<string,array{adet:int,kurus:int}>,ortalama_vade:array}
 */
function ceksenet_secim_ozeti(PDO $pdo, array $ids, ?string $baz = null): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    $satirlar = []; $tpl = [];
    if ($ids) {
        $st = $pdo->prepare('SELECT * FROM ceksenet_kayitlar WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['durum'] === 'iptal' || !ceksenet_gorunur($r)) continue;
            $k = cari_kurus($r['tutar']);
            $satirlar[] = ['vade' => $r['vade'], 'kurus' => $k, 'para_birimi' => $r['para_birimi']];
            $tpl[$r['para_birimi']]['adet'] = ($tpl[$r['para_birimi']]['adet'] ?? 0) + 1; $tpl[$r['para_birimi']]['kurus'] = ($tpl[$r['para_birimi']]['kurus'] ?? 0) + $k;
        }
    }
    return ['adet' => count($satirlar), 'toplamlar' => cari_pb_sirala($tpl), 'ortalama_vade' => ceksenet_ortalama_vade($satirlar, $baz ?? date('Y-m-d')) ?? []];
}

/**
 * Carinin açık çek-senet özeti (cari kartı parçası): sinif × yon × para birimi — açık (portföy/tahsil/teminat/verildi) adet + kuruş ve vadesi geçmiş adet.
 * @return list<array{sinif:string,yon:string,pb:string,adet:int,kurus:int,gecmis_adet:int}>
 */
function ceksenet_cari_ozet(PDO $pdo, int $cariId, ?string $bugun = null): array
{
    $bugun ??= date('Y-m-d');
    $acik = ceksenet_acik_durumlar();
    $ph = implode(',', array_fill(0, count($acik), '?'));
    [$dw, $dp] = ceksenet_depo_filtre('k.depo');
    $st = $pdo->prepare("SELECT k.sinif, k.yon, k.para_birimi AS pb, COUNT(*) AS adet, SUM(k.tutar) AS t, SUM(CASE WHEN k.vade < ? THEN 1 ELSE 0 END) AS gecmis
        FROM ceksenet_kayitlar k WHERE k.cari_id = ? AND k.durum IN ($ph) AND $dw GROUP BY k.sinif, k.yon, k.para_birimi ORDER BY k.sinif, k.yon, k.para_birimi");
    $st->execute([$bugun, $cariId, ...$acik, ...$dp]);
    return array_map(static fn(array $r): array => ['sinif' => (string)$r['sinif'], 'yon' => (string)$r['yon'], 'pb' => (string)$r['pb'], 'adet' => (int)$r['adet'],
        'kurus' => cari_kurus($r['t']), 'gecmis_adet' => (int)$r['gecmis']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Bordro (BRD-…) çıktısı için: bordroya bağlı hareketler + kayıtlar (yazdır). Aktif depoda görünmeyen kayıtlar atlanır; hiçbiri yoksa null.
 * @return array{bordro_no:string,tarih:string,islem:string,islem_etiket:string,sinif:string,yon:string,cari_id:?int,cari_unvan:?string,satirlar:list<array>,toplamlar:array,ortalama_vade:array,iptal_say:int}|null
 */
function ceksenet_bordro_oku(PDO $pdo, string $bordroNo): ?array
{
    if (!preg_match('/^BRD-\d{4}-\d{6}$/', $bordroNo)) return null;
    $st = $pdo->prepare('SELECT * FROM ceksenet_hareketler WHERE bordro_no = ? ORDER BY id');
    $st->execute([$bordroNo]);
    $har = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$har) return null;
    $satirlar = []; $tpl = []; $ov = []; $iptal = 0; $sinif = null; $yon = null;
    foreach ($har as $h) {
        $k = ceksenet_kayit_oku($pdo, (int)$h['kayit_id']);
        if ($k === null || !ceksenet_gorunur($k)) continue;
        $k = ceksenet_satir_zenginlestir($pdo, [$k])[0];
        $sinif ??= (string)$k['sinif']; $yon ??= (string)$k['yon'];
        $k['hareket_durum'] = $h['durum']; $k['hareket_belge_no'] = $h['belge_no'];
        $satirlar[] = $k;
        if ($h['durum'] === 'iptal') { $iptal++; continue; }
        $tpl[$k['para_birimi']]['adet'] = ($tpl[$k['para_birimi']]['adet'] ?? 0) + 1; $tpl[$k['para_birimi']]['kurus'] = ($tpl[$k['para_birimi']]['kurus'] ?? 0) + $k['tutar_kurus'];
        $ov[] = ['vade' => $k['vade'], 'kurus' => $k['tutar_kurus'], 'para_birimi' => $k['para_birimi']];
    }
    if (!$satirlar) return null;
    $ilk = $har[0];
    $cid = !empty($ilk['cari_id']) ? (int)$ilk['cari_id'] : null;
    return ['bordro_no' => $bordroNo, 'tarih' => (string)$ilk['tarih'], 'islem' => (string)$ilk['islem'], 'islem_etiket' => ceksenet_islem_etiketi((string)$ilk['islem'], (string)$sinif), 'sinif' => (string)$sinif, 'yon' => (string)$yon,
        'cari_id' => $cid, 'cari_unvan' => $cid !== null ? (ceksenet_cari_ad($pdo, $cid)['unvan'] ?? null) : null, 'satirlar' => $satirlar,
        'toplamlar' => cari_pb_sirala($tpl), 'ortalama_vade' => ceksenet_ortalama_vade($ov, (string)$ilk['tarih']) ?? [], 'iptal_say' => $iptal];
}
