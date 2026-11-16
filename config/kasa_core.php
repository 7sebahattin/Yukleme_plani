<?php
// =========================================================
// config/kasa_core.php — Kasa modülü çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 3 tablo: kasa_kartlar (nakit kasa + banka hesabı: tip='banka') · kasa_hareketler (INSERT-only DEFTER) · kasa_tanimlar (gelir/gider kalemleri).
// Bakiye = Σ giriş (G) − Σ çıkış (C), yalnız durum='gecerli'. Para birimleri ASLA toplanmaz (kasa tek para birimlidir; hareket pb'si kasadan gelir).
// Şema YALNIZ migrate.php "Kasa" kartından kurulur (kasa_migrate; ALTER ve FK YOK, GO kuralı). Yazan fonksiyonlar yetki KONTROL ETMEZ
// (uç: kasa_kaydet.php → kasa_kapi); hepsi audit_log_event(…, 'kasa', …) yazar. PDO'da yalnız pozisyonel `?`.
// Cari'ye YALNIZ cari_core fonksiyonlarıyla (cari_hareket_ekle(..., ['modul'=>'kasa','id'=>…]) · cari_kaynakli_iptal); cari_* tablolarına
//   bu dosyada HİÇ SQL yok (okuma dahil). Cari unvanları cari_kart_oku / cari_kart_listesi ile çözülür.
// Defter DEĞİŞMEZ: kasa_hareketler satırında DELETE yok; UPDATE beyaz listesi (gate smoke birebir denetler):
//   belge_no = ?, cari_hareket_id = ?   ·   belge_no = ?, bagli_id = ?   ·   durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ?
// Düzeltme = iptal (neden 3–500) + yeni kayıt. Taslak YOK. istek_anahtari UNIQUE (çift gönderim → tekrar).
// Depo: kasa KARTI deposu boş = tüm depolar; kart / liste / seçenek okumaları kart deposu üzerinden aktif depo + boş depo süzgeçlidir
//   (kasa bakiyesi ve yürüyen bakiye kasanın BÜTÜNÜDÜR — hareket.depo yalnız damga). Yazılan hareket depo = active_depot().
// Başka modüller kasaya YALNIZ kasa_hareket_ekle(..., $kaynak) / kasa_kaynakli_iptal ile bağlanır; bu dosya onları BİLMEZ (bağımlılık tek yönlü).
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/cari_core.php';   // cari_* yardımcıları (kuruş, tutar/kur/tarih ayrıştırma, tx, hata, json) + cari bağı

const KASA_IC_HATA = 4545;                    // hareket transaction'ını geri alan iç hata kodu (RuntimeException)
const KASA_TEVSIK_SINIRI_KURUS = 3000000;     // 30.000,00 TL — VUK GT 575 (30.11.2024): nakit cari tahsilat/ödeme için uyarı eşiği

// ── Şema / kapı ─────────────────────────────────────────────────────────────

/** Üç tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım (cari_tablolar kalıbı). @return array<string,string> */
function kasa_tablolar(): array
{
    return [
        'kasa_kartlar' => "CREATE TABLE IF NOT EXISTS `kasa_kartlar` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `kod`         VARCHAR(30)   NOT NULL,
  `ad`          VARCHAR(150)  NOT NULL,
  `tip`         VARCHAR(10)   NOT NULL DEFAULT 'nakit',
  `para_birimi` VARCHAR(3)    NOT NULL DEFAULT 'TRY',
  `depo`        VARCHAR(150)  NOT NULL DEFAULT '',
  `banka_adi`   VARCHAR(100)  NULL DEFAULT NULL,
  `sube`        VARCHAR(100)  NULL DEFAULT NULL,
  `iban`        VARCHAR(40)   NULL DEFAULT NULL,
  `eksi_izin`   TINYINT(1)    NOT NULL DEFAULT 0,
  `aciklama`    VARCHAR(500)  NULL DEFAULT NULL,
  `aktif`       TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`  INT           NULL DEFAULT NULL,
  `updated_at`  DATETIME      NULL DEFAULT NULL,
  `updated_by`  INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_kasa_kod` (`kod`),
  KEY `idx_kasa_tip` (`tip`, `aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'kasa_tanimlar' => "CREATE TABLE IF NOT EXISTS `kasa_tanimlar` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `tur`        VARCHAR(10)   NOT NULL,
  `ad`         VARCHAR(100)  NOT NULL,
  `sira`       INT           NOT NULL DEFAULT 0,
  `aktif`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT           NULL DEFAULT NULL,
  `updated_at` DATETIME      NULL DEFAULT NULL,
  `updated_by` INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_kasat_tur_ad` (`tur`, `ad`),
  KEY `idx_kasat_tur` (`tur`, `aktif`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'kasa_hareketler' => "CREATE TABLE IF NOT EXISTS `kasa_hareketler` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `kasa_id`         INT            NOT NULL,
  `tarih`           DATE           NOT NULL,
  `tur`             VARCHAR(20)    NOT NULL,
  `yon`             CHAR(1)        NOT NULL,
  `tutar`           DECIMAL(14,2)  NOT NULL,
  `para_birimi`     VARCHAR(3)     NOT NULL DEFAULT 'TRY',
  `kur`             DECIMAL(18,6)  NULL DEFAULT NULL,
  `belge_no`        VARCHAR(30)    NOT NULL DEFAULT '',
  `evrak_no`        VARCHAR(60)    NULL DEFAULT NULL,
  `cari_id`         INT            NULL DEFAULT NULL,
  `cari_hareket_id` INT            NULL DEFAULT NULL,
  `kalem_id`        INT            NULL DEFAULT NULL,
  `kalem_ad`        VARCHAR(100)   NULL DEFAULT NULL,
  `aciklama`        VARCHAR(500)   NOT NULL DEFAULT '',
  `depo`            VARCHAR(150)   NOT NULL DEFAULT '',
  `bagli_id`        INT            NULL DEFAULT NULL,
  `kaynak_modul`    VARCHAR(30)    NULL DEFAULT NULL,
  `kaynak_id`       INT            NULL DEFAULT NULL,
  `istek_anahtari`  VARCHAR(40)    NULL DEFAULT NULL,
  `durum`           VARCHAR(10)    NOT NULL DEFAULT 'gecerli',
  `iptal_at`        DATETIME       NULL DEFAULT NULL,
  `iptal_by`        INT            NULL DEFAULT NULL,
  `iptal_neden`     VARCHAR(500)   NULL DEFAULT NULL,
  `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`      INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_kasah_istek` (`istek_anahtari`),
  KEY `idx_kasah_kasa` (`kasa_id`, `tarih`, `id`),
  KEY `idx_kasah_kaynak` (`kaynak_modul`, `kaynak_id`),
  KEY `idx_kasah_cari` (`cari_id`),
  KEY `idx_kasah_belge` (`belge_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Üç tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function kasa_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(kasa_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Tohum: gelir / gider kalemleri (yalnız kasa_tanimlar YENİ kurulduysa, bir kez). @return list<array{tur:string,ad:string}> */
function kasa_tanim_tohumu(): array
{
    $o = [];
    foreach (['Diğer Gelir', 'Faiz Geliri'] as $ad) $o[] = ['tur' => 'gelir', 'ad' => $ad];
    foreach (['Genel Gider', 'Yakıt', 'Yemek', 'Nakliye', 'Kırtasiye', 'Banka Masrafı'] as $ad) $o[] = ['tur' => 'gider', 'ad' => $ad];
    return $o;
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + kalem tohumu (kasa_tanimlar YENİ kurulduysa). Yalnız migrate.php kartından çağrılır.
 * TEST KANCASI: $GLOBALS['_kasa_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function kasa_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_kasa_ddl_calistir'] ?? null;   // YALNIZ TEST KANCASI
    foreach (kasa_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'kasa_tanimlar' && (int)$pdo->query('SELECT COUNT(*) FROM `kasa_tanimlar`')->fetchColumn() === 0) {
                $ins = $pdo->prepare('INSERT INTO `kasa_tanimlar` (tur, ad, sira, aktif, created_at) VALUES (?, ?, ?, 1, ?)');
                $sira = [];
                foreach (kasa_tanim_tohumu() as $r) {
                    $sira[$r['tur']] = ($sira[$r['tur']] ?? 0) + 10;
                    $ins->execute([$r['tur'], $r['ad'], $sira[$r['tur']], date('Y-m-d H:i:s')]);
                }
            }
        } catch (PDOException $e) {
            error_log('[kasa_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** JSON (fetch) isteği mi? (cari ile aynı sözleşme) */
function kasa_json_mi(): bool { return cari_json_mi(); }

/** Eksik şemalar (Kasa ya da Cari). @return list<array{ad:string,kart:string}> */
function kasa_eksik_semalar(PDO $pdo): array
{
    $e = [];
    if (!cari_hazir($pdo)) $e[] = ['ad' => 'Cari İşlemler', 'kart' => 'cari'];
    if (!kasa_hazir($pdo)) $e[] = ['ad' => 'Kasa', 'kart' => 'kasa'];
    return $e;
}

/** Kasa + Cari tabloları yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function kasa_sayfa_kapisi(PDO $pdo): void
{
    $eksik = kasa_eksik_semalar($pdo);
    if (!$eksik) return;
    $adlar = implode(', ', array_column($eksik, 'ad'));
    if (kasa_json_mi()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Kasa için gerekli tablolar kurulmamış: ' . $adlar . '. Yönetici: Şema Migrasyon.', 'eksikler' => array_column($eksik, 'kart')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render_header('Kasa');
    if (function_exists('kasa_assets')) kasa_assets();
    if (function_exists('kasa_sema_uyari')) {
        echo kasa_sema_uyari($eksik);
    } else {
        $lnk = '';
        foreach ($eksik as $e) $lnk .= ' <a class="btn btn-primary" href="migrate.php#' . h($e['kart']) . '">Şema Migrasyon › ' . h($e['ad']) . '</a>';
        echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
            . '<h2 style="margin-top:0;">Kasa</h2>'
            . '<p>Kasa için gerekli tablolar kurulmamış: ' . h($adlar) . '. Yönetici: Şema Migrasyon kartlarını (önce Cari İşlemler) çalıştırın.</p>'
            . '<p>' . $lnk . '</p>'
            . '</div></div>';
    }
    render_footer();
    exit;
}

/** Faz 1: hepsi is_admin(). TEK değişim noktası (izin kataloğuna BAĞLANMADI). $eylem: read|write|delete|admin */
function kasa_can(string $eylem): bool
{
    return in_array($eylem, ['read', 'write', 'delete', 'admin'], true) && is_admin();
}

function kasa_kapi(string $eylem): void
{
    if (kasa_can($eylem)) return;
    if (kasa_json_mi()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forbidden('Bu işlem için yetkiniz yok.');
}

function kasa_no_store(): void { cari_no_store(); }

// ── Sabitler / meta ─────────────────────────────────────────────────────────

/** @return array<string,string> */
function kasa_tipler(): array { return ['nakit' => 'Nakit Kasa', 'banka' => 'Banka Hesabı']; }

/** @return array<string,string> */
function kasa_tanim_turleri(): array { return ['gelir' => 'Gelir Kalemi', 'gider' => 'Gider Kalemi']; }

/**
 * POST'tan girilebilen hareket türleri. yon G (giriş) | C (çıkış) | cift (virman); onek belge no öneki;
 * cari: cari defterine yazılan tür ('tahsilat' → Alacak, 'odeme' → Borç) ya da null; kalem: gelir/gider kalemi türü ya da null.
 * @return array<string,array{etiket:string,yon:string,onek:string,cari:?string,kalem:?string}>
 */
function kasa_hareket_turleri(): array
{
    return [
        'cari_tahsilat' => ['etiket' => 'Cari Tahsilat',        'yon' => 'G',    'onek' => 'KTH', 'cari' => 'tahsilat', 'kalem' => null],
        'cari_odeme'    => ['etiket' => 'Cari Ödeme (Tediye)',  'yon' => 'C',    'onek' => 'KOD', 'cari' => 'odeme',    'kalem' => null],
        'gelir'         => ['etiket' => 'Gelir Fişi',           'yon' => 'G',    'onek' => 'KGL', 'cari' => null,       'kalem' => 'gelir'],
        'gider'         => ['etiket' => 'Gider Fişi',           'yon' => 'C',    'onek' => 'KGD', 'cari' => null,       'kalem' => 'gider'],
        'acilis'        => ['etiket' => 'Açılış / Devir',       'yon' => 'G',    'onek' => 'KAC', 'cari' => null,       'kalem' => null],
        'virman'        => ['etiket' => 'Kasalar Arası Virman', 'yon' => 'cift', 'onek' => 'KVR', 'cari' => null,       'kalem' => null],
    ];
}

/** DAHİLİ türler: yalnız kasa_hareket_ekle(..., $kaynak) ile (başka modülün kendi çekirdeğinden) yazılır; POST yolundan REDDEDİLİR. */
function kasa_dahili_turler(): array
{
    return [
        'kaynak_giris' => ['etiket' => 'Kaynak Belge Girişi', 'yon' => 'G', 'onek' => 'KKG', 'cari' => null, 'kalem' => null],
        'kaynak_cikis' => ['etiket' => 'Kaynak Belge Çıkışı', 'yon' => 'C', 'onek' => 'KKC', 'cari' => null, 'kalem' => null],
    ];
}

/** Tür bilgisi; $dahili=true iken kaynak_giris / kaynak_cikis de çözülür (liste/etiket için her zaman true verin). */
function kasa_tur_bilgi(string $tur, bool $dahili = false): ?array
{
    return kasa_hareket_turleri()[$tur] ?? ($dahili ? (kasa_dahili_turler()[$tur] ?? null) : null);
}

/** KTH-2026-000123 */
function kasa_belge_no(string $tur, string $tarih, int $id): string
{
    $onek = kasa_tur_bilgi($tur, true)['onek'] ?? 'KHR';
    return $onek . '-' . substr($tarih, 0, 4) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** `geri` yalnız yerel kasa sayfası (açık yönlendirme yok). */
function kasa_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^kasa_(fisleri|tanimlari)\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'kasa_fisleri.php';
}

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function kasa_tx(PDO $pdo, callable $fn): mixed { return cari_tx($pdo, $fn); }

function kasa_hata(string $msg, array $ek = []): array { return cari_hata($msg, $ek); }

/** 123456 (kuruş) → "1234.56" (DECIMAL yazımı). */
function kasa_para_db(int $kurus): string { return number_format($kurus / 100, 2, '.', ''); }

/**
 * Sonucu JSON ya da PRG olarak gönderir. 409: yetersiz_bakiye · kaynakli · hareketli · kullanimda; kod sunucu_hatasi → 500; diğer hata 422.
 */
function kasa_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && ($s['kod'] ?? '') === 'sunucu_hatasi') http_response_code(500);
        elseif (!$ok && in_array((string)($s['kod'] ?? ''), ['yetersiz_bakiye', 'kaynakli', 'hareketli', 'kullanimda'], true)) http_response_code(409);
        elseif (!$ok) http_response_code(422);
        echo json_encode([
            'ok' => $ok, 'id' => $s['id'] ?? null, 'id2' => $s['id2'] ?? null, 'belge_no' => $s['belge_no'] ?? null, 'cari_belge_no' => $s['cari_belge_no'] ?? null,
            'hata' => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'), 'alanlar' => (object)($s['alanlar'] ?? []), 'uyari' => $s['uyari'] ?? null,
            'yonlendir' => $geri, 'tekrar' => !empty($s['tekrar']), 'kod' => $s['kod'] ?? null,
            'bakiye' => $s['bakiye'] ?? null, 'eksik' => $s['eksik'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok
        ? $okMesaj . (!empty($s['belge_no']) ? ' · ' . $s['belge_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '')
        : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

/** d.m.Y ya da Y-m-d → Y-m-d (geçerli aralıkta değilse null). */
function kasa_tarih_coz(mixed $ham): ?string
{
    $t = trim(is_scalar($ham) ? (string)$ham : '');
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $t, $m)) $t = "$m[3]-$m[2]-$m[1]";
    return cari_tarih_gecerli($t) ? $t : null;
}

function kasa_onay(mixed $v): bool { return !empty($v) && (string)$v !== '0'; }

/** MySQL: kasa kartı satırlarını FOR UPDATE ile kilitler (bakiye kontrolü yarışı). SQLite: no-op. @param list<int> $ids */
function kasa_kart_kilitle(PDO $pdo, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    if (!$ids || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
    sort($ids);
    $pdo->prepare('SELECT id FROM kasa_kartlar WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE')->execute($ids);
}

/** Kasa aktif depoda görünür mü? (kart deposu boşsa görünür) */
function kasa_gorunur(array $kart): bool { return depot_visible_to_user((string)($kart['depo'] ?? '')); }

/** Görünmeyen (başka gerçek depoya ait) kasa için ret cevabı; görünürse null. */
function kasa_depo_ret(?array $kart): ?array
{
    if ($kart === null || kasa_gorunur($kart)) return null;
    return kasa_hata('Bu kasa "' . (string)$kart['depo'] . '" deposuna ait; aktif deponuzda görünmüyor.', ['kod' => 'depo']);
}

/** Kart deposuna göre aktif depo süzgeci (boş depo daima görünür). @return array{0:string,1:array} */
function kasa_depo_filtre(string $col): array
{
    [$w, $p] = depo_sql_in($col);
    return $w === '' ? ['1=1', []] : [$w, $p];
}

// ── Kart ────────────────────────────────────────────────────────────────────

const KASA_KART_ALANLAR = ['kod', 'ad', 'tip', 'para_birimi', 'depo', 'banka_adi', 'sube', 'iban', 'eksi_izin', 'aciklama', 'aktif'];

function kasa_kart_oku(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM kasa_kartlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** KS-001 …: en büyük KS-\d+ + 1. */
function kasa_kod_oner(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT kod FROM kasa_kartlar WHERE kod LIKE 'KS-%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        if (preg_match('/^KS-(\d+)$/', (string)$k, $m)) $max = max($max, (int)$m[1]);
    }
    return 'KS-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}

/** @return array{ok:bool,veri?:array,hatalar?:array<string,string>} */
function kasa_kart_dogrula(PDO $pdo, array $g, ?int $id, ?array $eski = null): array
{
    $h = []; $v = [];
    $ad = trim((string)($g['ad'] ?? ''));
    if (mb_strlen($ad) < 2) $h['ad'] = 'Ad en az 2 karakter olmalı.';
    elseif (mb_strlen($ad) > 150) $h['ad'] = 'Ad en çok 150 karakter.';
    else $v['ad'] = $ad;

    $kod = cari_kod_normalize((string)($g['kod'] ?? ''));
    if ($kod === '') {
        $kod = $eski !== null && (string)($eski['kod'] ?? '') !== '' ? (string)$eski['kod'] : kasa_kod_oner($pdo);   // düzenlemede boş kod = ESKİ kod korunur
    } elseif (!preg_match('/^[A-Z0-9._-]{1,30}$/', $kod)) {
        $h['kod'] = 'Kod 1-30 karakter; yalnız harf, rakam, . _ -';
    }
    if (!isset($h['kod'])) {
        $st = $pdo->prepare('SELECT id FROM kasa_kartlar WHERE kod = ?');
        $st->execute([$kod]);
        $var = $st->fetchColumn();
        if ($var !== false && ($id === null || (int)$var !== $id)) $h['kod'] = 'Bu kod başka bir kasada kullanılıyor.';
        else $v['kod'] = $kod;
    }

    $tip = (string)(($g['tip'] ?? '') !== '' ? $g['tip'] : ($eski['tip'] ?? 'nakit'));   // gelmezse eski değer korunur
    if (!isset(kasa_tipler()[$tip])) $h['tip'] = 'Geçersiz kasa tipi.'; else $v['tip'] = $tip;
    $pb = (string)(($g['para_birimi'] ?? '') !== '' ? $g['para_birimi'] : ($eski['para_birimi'] ?? 'TRY'));
    if (!in_array($pb, cari_para_birimleri(), true)) $h['para_birimi'] = 'Geçersiz para birimi.'; else $v['para_birimi'] = $pb;

    $depo = trim((string)($g['depo'] ?? ''));
    if ($depo === '') {
        $v['depo'] = '';
    } elseif (mb_strlen($depo) > 150) {
        $h['depo'] = 'Depo adı en çok 150 karakter.';
    } else {
        $bulundu = $eski !== null && depo_fold((string)$eski['depo']) === depo_fold($depo) ? (string)$eski['depo'] : null;   // mevcut değeri koru (liste dışı kalsa da)
        if ($bulundu === null && function_exists('depot_options')) {
            foreach (depot_options() as $o) if (depo_fold((string)$o) === depo_fold($depo)) { $bulundu = (string)$o; break; }
        }
        if ($bulundu === null) $h['depo'] = 'Geçersiz depo.'; else $v['depo'] = $bulundu;
    }

    $metin = static function (string $alan, int $max) use (&$h, &$v, $g, $tip): void {
        $s = trim((string)($g[$alan] ?? ''));
        if ($s === '' || ($tip !== 'banka' && $alan !== 'aciklama')) { $v[$alan] = null; return; }   // banka alanları yalnız banka tipinde tutulur
        if (mb_strlen($s) > $max) { $h[$alan] = "En çok $max karakter."; return; }
        $v[$alan] = $s;
    };
    $metin('banka_adi', 100); $metin('sube', 100); $metin('aciklama', 500);

    $ib = strtoupper(preg_replace('/\s+/', '', (string)($g['iban'] ?? '')) ?? '');
    if ($ib === '' || $tip !== 'banka') $v['iban'] = null;
    elseif (!preg_match('/^TR\d{24}$/', $ib) && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $ib)) $h['iban'] = 'Geçerli bir IBAN girin.';
    else $v['iban'] = $ib;

    if (array_key_exists('eksi_izin', $g)) $v['eksi_izin'] = kasa_onay($g['eksi_izin']) ? 1 : 0;
    if (array_key_exists('aktif', $g)) $v['aktif'] = kasa_onay($g['aktif']) ? 1 : 0;

    return $h ? ['ok' => false, 'hatalar' => $h] : ['ok' => true, 'veri' => $v];
}

/** Kartın (iptaller dahil) hareket satırı sayısı. */
function kasa_kart_hareket_say(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM kasa_hareketler WHERE kasa_id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/**
 * Kart ekle / güncelle. Para birimi hareket varken DEĞİŞMEZ. Dönüş: ok, hata, id, alanlar?, kod? (kod çakışması → kod=kullanimda).
 * @return array{ok:bool,hata:?string,id:?int,alanlar?:array,kod?:string}
 */
function kasa_kart_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array
{
    $eski = null;
    if ($id !== null) {
        $eski = kasa_kart_oku($pdo, $id);
        if ($eski === null) return kasa_hata('Kasa bulunamadı.');
        if (($r = kasa_depo_ret($eski)) !== null) return $r;
    }
    $d = kasa_kart_dogrula($pdo, $g, $id, $eski);
    if (!$d['ok']) {
        $ek = ['alanlar' => $d['hatalar']];
        if (isset($d['hatalar']['kod']) && str_contains($d['hatalar']['kod'], 'kullanılıyor')) $ek['kod'] = 'kullanimda';
        return kasa_hata((string)reset($d['hatalar']), $ek);
    }
    $v = $d['veri'];
    if ($eski !== null && isset($v['para_birimi']) && $v['para_birimi'] !== (string)$eski['para_birimi'] && kasa_kart_hareket_say($pdo, (int)$id) > 0) {
        return kasa_hata('Hareketi olan kasanın para birimi değiştirilemez.', ['alanlar' => ['para_birimi' => 'Hareket var; değiştirilemez.'], 'kod' => 'hareketli']);
    }
    $simdi = date('Y-m-d H:i:s');

    if ($id === null) {
        $v += ['aktif' => 1, 'eksi_izin' => 0];
        $kol = array_keys($v);
        try {
            $pdo->prepare('INSERT INTO kasa_kartlar (' . implode(',', $kol) . ', created_at, created_by) VALUES (' . implode(',', array_fill(0, count($kol), '?')) . ', ?, ?)')
                ->execute([...array_values($v), $simdi, $uid]);
        } catch (PDOException $e) {
            return kasa_hata('Kasa kaydedilemedi (kod çakışması olabilir).', ['kod' => 'kullanimda', 'alanlar' => ['kod' => 'Kod çakışması.']]);
        }
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'kasa', $yid, null, ['kayit' => 'kart'] + $v);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }

    $fark = []; $eskiFark = [];
    foreach ($v as $k => $nv) {
        if (($eski[$k] ?? null) != $nv || (($eski[$k] ?? null) === null) !== ($nv === null)) { $fark[$k] = $nv; $eskiFark[$k] = $eski[$k] ?? null; }
    }
    if ($fark) {
        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($fark)));
        try {
            $pdo->prepare("UPDATE kasa_kartlar SET $set, updated_at = ?, updated_by = ? WHERE id = ?")->execute([...array_values($fark), $simdi, $uid, $id]);
        } catch (PDOException $e) {
            return kasa_hata('Kasa güncellenemedi (kod çakışması olabilir).', ['kod' => 'kullanimda', 'alanlar' => ['kod' => 'Kod çakışması.']]);
        }
        audit_log_event('update', 'kasa', $id, ['kayit' => 'kart'] + $eskiFark, ['kayit' => 'kart'] + $fark);
    }
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Pasife alırken bakiye sıfırdan farklıysa yine izin verir, `uyari` döner. Pasif kasa yeni hareket almaz. */
function kasa_kart_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $k = kasa_kart_oku($pdo, $id);
    if ($k === null) return kasa_hata('Kasa bulunamadı.');
    if (($r = kasa_depo_ret($k)) !== null) return $r;
    $pdo->prepare('UPDATE kasa_kartlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    $uyari = null;
    if (!$aktif && kasa_bakiye($pdo, $id) !== 0) $uyari = 'Kasa bakiyesi sıfır değil; pasif kasa yeni hareket almaz.';
    audit_log_event($aktif ? 'activate' : 'deactivate', 'kasa', $id, ['kayit' => 'kart', 'aktif' => (int)$k['aktif']], ['kayit' => 'kart', 'aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id, 'uyari' => $uyari];
}

/** Fiziksel silme YALNIZ hiç hareket satırı (iptaller dahil) yoksa; aksi 409 hareketli. */
function kasa_kart_sil(PDO $pdo, int $id, int $uid): array
{
    $k = kasa_kart_oku($pdo, $id);
    if ($k === null) return kasa_hata('Kasa bulunamadı.');
    if (($r = kasa_depo_ret($k)) !== null) return $r;
    if (kasa_kart_hareket_say($pdo, $id) > 0) return kasa_hata('Hareketi olan kasa silinemez; pasifleştirin.', ['kod' => 'hareketli']);
    $pdo->prepare('DELETE FROM kasa_kartlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'kasa', $id, ['kayit' => 'kart'] + $k, null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/**
 * Filtre: ara (kod/ad/banka/iban), tip, aktif (1/0), pb. Kart deposu aktif depo + boş depo süzgeçli. En çok 500 satır.
 * Her satırda bakiye (kuruş, kasanın bütünü) ve hareket_say (iptal dahil).
 * @return array{satirlar:list<array>,kesildi:bool}
 */
function kasa_kart_listesi(PDO $pdo, array $f = []): array
{
    $w = ['1=1']; $p = [];
    [$dw, $dp] = kasa_depo_filtre('k.depo');
    $w[] = $dw; array_push($p, ...$dp);
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $w[] = '(k.kod LIKE ? OR k.ad LIKE ? OR k.banka_adi LIKE ? OR k.iban LIKE ?)';
        $l = '%' . $q . '%';
        array_push($p, $l, $l, $l, $l);
    }
    if (!empty($f['tip'])) { $w[] = 'k.tip = ?'; $p[] = (string)$f['tip']; }
    if (!empty($f['pb'])) { $w[] = 'k.para_birimi = ?'; $p[] = (string)$f['pb']; }
    if (isset($f['aktif']) && $f['aktif'] !== '' && $f['aktif'] !== null) { $w[] = 'k.aktif = ?'; $p[] = (int)$f['aktif']; }
    $st = $pdo->prepare('SELECT k.* FROM kasa_kartlar k WHERE ' . implode(' AND ', $w) . ' ORDER BY k.aktif DESC, k.tip, k.ad, k.id LIMIT 501');
    $st->execute($p);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    $kesildi = count($satirlar) > 500;
    if ($kesildi) $satirlar = array_slice($satirlar, 0, 500);
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $satirlar);
    $bak = kasa_bakiyeler_toplu($pdo, $ids);
    $say = [];
    if ($ids) {
        $st = $pdo->prepare('SELECT kasa_id, COUNT(*) AS n FROM kasa_hareketler WHERE kasa_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY kasa_id');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $say[(int)$r['kasa_id']] = (int)$r['n'];
    }
    foreach ($satirlar as &$r) { $r['bakiye'] = $bak[(int)$r['id']] ?? 0; $r['hareket_say'] = $say[(int)$r['id']] ?? 0; }
    unset($r);
    return ['satirlar' => $satirlar, 'kesildi' => $kesildi];
}

/**
 * Çek-senet / form seçicileri için kasa listesi (kart deposu süzgeçli; varsayılan yalnız aktif).
 * @return list<array{id:int,kod:string,ad:string,tip:string,pb:string,eksi_izin:int,banka_adi:?string,depo:string}>
 */
function kasa_secenekleri(PDO $pdo, ?string $pb = null, bool $yalnizAktif = true): array
{
    $w = ['1=1']; $p = [];
    [$dw, $dp] = kasa_depo_filtre('k.depo');
    $w[] = $dw; array_push($p, ...$dp);
    if ($pb !== null && $pb !== '') { $w[] = 'k.para_birimi = ?'; $p[] = $pb; }
    if ($yalnizAktif) $w[] = 'k.aktif = 1';
    $st = $pdo->prepare('SELECT k.id, k.kod, k.ad, k.tip, k.para_birimi, k.eksi_izin, k.banka_adi, k.depo FROM kasa_kartlar k WHERE ' . implode(' AND ', $w) . ' ORDER BY k.tip, k.ad, k.id');
    $st->execute($p);
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'kod' => (string)$r['kod'], 'ad' => (string)$r['ad'], 'tip' => (string)$r['tip'], 'pb' => (string)$r['para_birimi'],
        'eksi_izin' => (int)$r['eksi_izin'], 'banka_adi' => $r['banka_adi'], 'depo' => (string)$r['depo']], $st->fetchAll(PDO::FETCH_ASSOC));
}

// ── Bakiye ──────────────────────────────────────────────────────────────────

/** Kasa bakiyesi (kuruş; Σ giriş − Σ çıkış, yalnız geçerli; $bitis dahil). Depo filtresi YOK — kasa bakiyesi bütündür. */
function kasa_bakiye(PDO $pdo, int $kasaId, ?string $bitis = null): int
{
    $sql = "SELECT SUM(CASE WHEN yon = 'G' THEN tutar ELSE 0 END) AS g, SUM(CASE WHEN yon = 'C' THEN tutar ELSE 0 END) AS c FROM kasa_hareketler WHERE kasa_id = ? AND durum = 'gecerli'";
    $p = [$kasaId];
    if ($bitis !== null && $bitis !== '') { $sql .= ' AND tarih <= ?'; $p[] = $bitis; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? 0 : cari_kurus($r['g']) - cari_kurus($r['c']);
}

/**
 * Kilitli bakiye (tx içinde, kasa kartı kilidinden sonra): MySQL'de FOR UPDATE ile en son İŞLENMİŞ veriyi okur — REPEATABLE READ görüntüsü eskimez. SQLite: düz okuma.
 */
function kasa_bakiye_kilitli(PDO $pdo, int $kasaId): int
{
    $sql = "SELECT SUM(CASE WHEN yon = 'G' THEN tutar ELSE 0 END) AS g, SUM(CASE WHEN yon = 'C' THEN tutar ELSE 0 END) AS c FROM kasa_hareketler WHERE kasa_id = ? AND durum = 'gecerli'"
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$kasaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? 0 : cari_kurus($r['g']) - cari_kurus($r['c']);
}

/** @param list<int> $ids @return array<int,int> kasa id → bakiye (kuruş) */
function kasa_bakiyeler_toplu(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $x): bool => $x > 0)));
    if (!$ids) return [];
    $st = $pdo->prepare("SELECT kasa_id, SUM(CASE WHEN yon = 'G' THEN tutar ELSE 0 END) AS g, SUM(CASE WHEN yon = 'C' THEN tutar ELSE 0 END) AS c
        FROM kasa_hareketler WHERE durum = 'gecerli' AND kasa_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY kasa_id');
    $st->execute($ids);
    $out = array_fill_keys($ids, 0);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['kasa_id']] = cari_kurus($r['g']) - cari_kurus($r['c']);
    return $out;
}

/**
 * Görünür AKTİF kasaların para birimi başına özeti (para birimleri ayrı).
 * @return array<string,array{toplam:int,nakit:int,banka:int,kasa_say:int}>
 */
function kasa_genel_ozet(PDO $pdo): array
{
    $l = kasa_kart_listesi($pdo, ['aktif' => 1]);
    $out = [];
    foreach ($l['satirlar'] as $k) {
        $pb = (string)$k['para_birimi'];
        $out[$pb] ??= ['toplam' => 0, 'nakit' => 0, 'banka' => 0, 'kasa_say' => 0];
        $out[$pb]['toplam'] += (int)$k['bakiye'];
        $out[$pb][(string)$k['tip'] === 'banka' ? 'banka' : 'nakit'] += (int)$k['bakiye'];
        $out[$pb]['kasa_say']++;
    }
    return cari_pb_sirala($out);
}

// ── Hareket (defter) ────────────────────────────────────────────────────────

function kasa_hareket_oku(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT h.*, k.kod AS kasa_kod, k.ad AS kasa_ad, k.tip AS kasa_tip FROM kasa_hareketler h LEFT JOIN kasa_kartlar k ON k.id = h.kasa_id WHERE h.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function kasa_istek_bul(PDO $pdo, string $anahtar): ?array
{
    $st = $pdo->prepare('SELECT id, belge_no, kasa_id FROM kasa_hareketler WHERE istek_anahtari = ?');
    $st->execute([$anahtar]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Çıkış kontrolü: kasa bakiyesi tutardan azsa ve kasa eksiye izin vermiyorsa eksik kuruşu döner (aksi null). */
function kasa_eksik_hesapla(PDO $pdo, array $kasa, int $kurus): ?array
{
    $bak = kasa_bakiye_kilitli($pdo, (int)$kasa['id']);   // kilitli okuma (çağıran kasa kartını zaten kilitlemiştir)
    return $bak < $kurus ? ['bakiye' => $bak, 'eksik' => $kurus - $bak] : null;
}

/**
 * Kasa ve onu kullanan modüller için TEK tutar ayrıştırıcı sarmalayıcı. Ortak ayrıştırıcıya (cari_tutar_coz → hesap_parse_amount) dokunmaz.
 * Nokta İÇERMEYEN ve tam `^[1-9]\d{0,2},\d{3}$` kalıbındaki girdi ("1,500") BELİRSİZdir: ortak ayrıştırıcı bunu 1.500,00 sayar, oysa
 * Türk kullanıcı "bir buçuk" kastedebilir (1000 kat hata). "0,500" belirsiz DEĞİL (0,50). Diğer girdiler ortak ayrıştırıcıya aynen gider.
 * assets/kasa.js `kurusCoz` (ve diğer modüllerin JS ön izlemeleri) bununla BİREBİR aynı sonucu verir (ui smoke JS ↔ PHP eşitliğini sabitler).
 * @return array{belirsiz:true,mesaj:string}|int|null  int = kuruş; null = geçersiz/boş; dizi = belirsiz
 */
function kasa_tutar_coz(mixed $ham): array|int|null
{
    if (is_string($ham) || is_int($ham) || is_float($ham)) {
        $s = str_ireplace(['₺', '$', '€', 'TRY', 'TL', 'USD', 'EUR', 'AED'], '', trim((string)$ham));
        $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $s) ?? '';
        if (preg_match('/^([1-9]\d{0,2}),(\d{3})$/', $s, $m)) {
            $a = $m[1] . '.' . $m[2];
            $ek = $a === '1.500' ? 'bir buçuk için 1,50 yazın.' : 'ondalık için ' . $m[1] . ',50 yazın.';
            return ['belirsiz' => true, 'mesaj' => "Tutar belirsiz: $a için nokta kullanın ($a), $ek"];
        }
    }
    return cari_tutar_coz($ham);
}

/**
 * CSV formül enjeksiyonu koruması: METİN hücresi `=`, `+`, `-`, `@`, TAB ya da CR ile başlıyorsa başına `'` konur (Excel/Calc formül saymasın).
 * YALNIZ metin hücrelerine uygulanır — sayısal tutar/bakiye (eksi dahil) ve tarih hücrelerine DEĞİL.
 */
function kasa_csv_metin(string $s): string
{
    return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
}

/** kasa_tutar_coz sonucundan alan hata metni (geçerliyse null). $varsayilan = geçersiz tutar mesajı. */
function kasa_tutar_hata(array|int|null $r, string $varsayilan): ?string
{
    if (is_array($r)) return (string)$r['mesaj'];
    return $r === null ? $varsayilan : null;
}

/** Ortak: tarih / tutar / kur / açıklama / evrak / istek anahtarı. @return array{0:?array,1:array<string,string>} */
function kasa_hareket_ortak(array $g, string $pb, bool $aciklamaZorunlu): array
{
    $h = [];
    $kurus = kasa_tutar_coz($g['tutar'] ?? '');
    $th = kasa_tutar_hata($kurus, 'Geçerli bir tutar girin (> 0, en çok 2 ondalık).');
    if ($th !== null) { $h['tutar'] = $th; $kurus = null; }
    $kur = cari_kur_coz($g['kur'] ?? '', $pb);
    if ($kur === false) $h['kur'] = $pb === 'TRY' ? 'TL için kur girilmez.' : 'Geçerli bir kur girin (en çok 6 ondalık).';
    $tarih = kasa_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) $h['tarih'] = 'Geçerli bir tarih girin.';
    $ac = trim((string)($g['aciklama'] ?? ''));
    if ($aciklamaZorunlu && mb_strlen($ac) < 3) $h['aciklama'] = 'Açıklama en az 3 karakter olmalı.';
    elseif (mb_strlen($ac) > 500) $h['aciklama'] = 'Açıklama en çok 500 karakter.';
    $evrak = trim((string)($g['evrak_no'] ?? ''));
    if (mb_strlen($evrak) > 60) $h['evrak_no'] = 'Evrak no en çok 60 karakter.';
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    if ($h) return [null, $h];
    return [['kurus' => $kurus, 'kur' => $kur, 'tarih' => $tarih, 'aciklama' => $ac, 'evrak' => $evrak === '' ? null : $evrak, 'ik' => $ik === '' ? null : $ik], []];
}

const KASA_HAREKET_SUTUNLAR = 'kasa_id, tarih, tur, yon, tutar, para_birimi, kur, belge_no, evrak_no, cari_id, cari_hareket_id, kalem_id, kalem_ad, aciklama, depo, bagli_id, kaynak_modul, kaynak_id, istek_anahtari, durum, created_at, created_by';

/**
 * Tek kasa hareketi (virman HARİÇ). Para birimi KASADAN gelir. Pasif / görünmeyen kasaya hareket yok.
 * $g: kasa_id, tur, tarih, tutar, kur?, evrak_no?, cari_id? (cari_tahsilat/cari_odeme), kalem_id? (gelir/gider), aciklama?, istek_anahtari?, eksiye_izin?
 * $kaynak: başka modülün kendi çekirdeğinden verdiği bağ ['modul' => ^[a-z_]{2,30}$, 'id' => int > 0]. YALNIZ bu durumda dahili türler
 *   (kaynak_giris / kaynak_cikis) kabul edilir: cari / kalem istenmez, cari dekontu YAZILMAZ (kaynak modül yazar), açıklama zorunlu.
 *   kasa_kaydet.php bu parametreyi hiç geçmez → POST yolundan dahili türler REDDEDİLİR. Kaynaklı satır kasa ekranından iptal edilemez (409 kaynakli).
 * Çıkışta bakiye < tutar ve kasa.eksi_izin = 0 ve eksiye_izin yoksa → ok=false, kod yetersiz_bakiye, bakiye, eksik (HİÇ yazılmaz);
 *   eksiye_izin=1 ile kayıt + uyari. Nakit kasada TRY cari tahsilat/ödeme ≥ KASA_TEVSIK_SINIRI_KURUS → kayıt yapılır + uyari (VUK GT 575).
 * Cari bağı tek transaction: kasa satırı → cari_hareket_ekle(…, ['modul'=>'kasa','id'=>$hid]); iç hata → KASA_IC_HATA → HEPSİ geri.
 * @return array{ok:bool,hata:?string,id:?int,belge_no?:string,tekrar?:bool,uyari?:?string,cari_belge_no?:?string,cari_hareket_id?:?int,kod?:string,bakiye?:int,eksik?:int,alanlar?:array}
 */
function kasa_hareket_ekle(PDO $pdo, array $g, int $uid, ?array $kaynak = null): array
{
    $kMod = null; $kId = null;
    if ($kaynak !== null) {
        $kMod = (string)($kaynak['modul'] ?? '');
        $kId = (int)($kaynak['id'] ?? 0);
        if (!preg_match('/^[a-z_]{2,30}$/', $kMod) || $kId <= 0) return kasa_hata('Geçersiz kaynak bağı.');
    }
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = kasa_istek_bul($pdo, $ik)) !== null) {
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
    }
    $tur = (string)($g['tur'] ?? '');
    $bilgi = kasa_tur_bilgi($tur, $kaynak !== null);
    if ($bilgi === null || $tur === 'virman') return kasa_hata('Geçersiz hareket türü.', ['alanlar' => ['tur' => 'Geçersiz hareket türü.']]);
    $dahili = isset(kasa_dahili_turler()[$tur]);   // kasa_tur_bilgi dahili türü yalnız $kaynak verildiğinde çözer

    $kasaId = (int)($g['kasa_id'] ?? 0);
    $kasa = $kasaId > 0 ? kasa_kart_oku($pdo, $kasaId) : null;
    if ($kasa === null) return kasa_hata('Kasa seçin.', ['alanlar' => ['kasa_id' => 'Kasa seçin.']]);
    if ((int)$kasa['aktif'] !== 1) return kasa_hata('Pasif kasaya yeni hareket girilemez.', ['alanlar' => ['kasa_id' => 'Pasif kasa.']]);
    if (!kasa_gorunur($kasa)) return kasa_hata('Bu kasa aktif deponuzda görünmüyor.', ['alanlar' => ['kasa_id' => 'Aktif depoda görünmüyor.']]);
    $pb = (string)$kasa['para_birimi'];

    [$o, $h] = kasa_hareket_ortak($g, $pb, $dahili);

    $cariId = null;
    if ($bilgi['cari'] !== null) {
        $cid = (int)($g['cari_id'] ?? 0);
        $kart = $cid > 0 ? cari_kart_oku($pdo, $cid) : null;
        if ($kart === null) $h['cari_id'] = 'Cari seçin.';
        elseif ((int)$kart['aktif'] !== 1) $h['cari_id'] = 'Pasif cariye yeni hareket girilemez.';
        else $cariId = $cid;
    }
    $kalemId = null; $kalemAd = null;
    if ($bilgi['kalem'] !== null) {
        $kid = (int)($g['kalem_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, ad, aktif FROM kasa_tanimlar WHERE id = ? AND tur = ?');
        $st->execute([$kid, $bilgi['kalem']]);
        $kal = $kid > 0 ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($kal === false) $h['kalem_id'] = ($bilgi['kalem'] === 'gelir' ? 'Gelir' : 'Gider') . ' kalemi seçin.';
        elseif ((int)$kal['aktif'] !== 1) $h['kalem_id'] = 'Pasif kalem seçilemez.';
        else { $kalemId = (int)$kal['id']; $kalemAd = (string)$kal['ad']; }
    }
    if ($h || $o === null) return kasa_hata((string)reset($h), ['alanlar' => $h]);

    $yonH = $bilgi['yon'];
    $eksiyeIzin = kasa_onay($g['eksiye_izin'] ?? '');
    $depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $simdi = date('Y-m-d H:i:s');
    $ic = null;
    try {
        $s = kasa_tx($pdo, function () use ($pdo, $kasa, $kasaId, $tur, $bilgi, $yonH, $o, $pb, $cariId, $kalemId, $kalemAd, $depo, $simdi, $uid, $kMod, $kId, $eksiyeIzin, &$ic): array {
            kasa_kart_kilitle($pdo, [$kasaId]);
            if ($o['ik'] !== null && ($var = kasa_istek_bul($pdo, $o['ik'])) !== null) {   // kilit beklerken aynı istek tamamlanmış olabilir
                return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
            }
            $taze = kasa_kart_oku($pdo, $kasaId);
            if ($taze === null || (int)$taze['aktif'] !== 1) return kasa_hata('Pasif kasaya yeni hareket girilemez.', ['alanlar' => ['kasa_id' => 'Pasif kasa.']]);
            $uyarilar = [];
            if ($yonH === 'C') {
                $ek = kasa_eksik_hesapla($pdo, $taze, $o['kurus']);
                if ($ek !== null && (int)$taze['eksi_izin'] !== 1) {
                    if (!$eksiyeIzin) return kasa_hata('Kasa bakiyesi yetersiz: ' . cari_tutar_bicim($ek['bakiye'], $pb) . ' mevcut, ' . cari_tutar_bicim($o['kurus'], $pb) . ' çıkış.',
                        ['kod' => 'yetersiz_bakiye', 'bakiye' => $ek['bakiye'], 'eksik' => $ek['eksik']]);
                    $uyarilar[] = 'Kasa bakiyesi eksiye düştü (' . cari_tutar_bicim($ek['bakiye'] - $o['kurus'], $pb) . ').';
                }
            }
            if ($bilgi['cari'] !== null && (string)$taze['tip'] === 'nakit' && $pb === 'TRY' && $o['kurus'] >= KASA_TEVSIK_SINIRI_KURUS) {
                $uyarilar[] = '30.000 TL ve üzeri tahsilat/ödemeler banka aracılığıyla yapılmalıdır (VUK GT 575).';
            }
            $pdo->prepare('INSERT INTO kasa_hareketler (' . KASA_HAREKET_SUTUNLAR . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$kasaId, $o['tarih'], $tur, $yonH, kasa_para_db($o['kurus']), $pb, $o['kur'], '', $o['evrak'], $cariId, null, $kalemId, $kalemAd, $o['aciklama'], $depo, null, $kMod, $kId, $o['ik'], 'gecerli', $simdi, $uid]);
            $hid = (int)$pdo->lastInsertId();
            $belgeNo = kasa_belge_no($tur, $o['tarih'], $hid);
            $cariHid = null; $cariNo = null;
            if ($bilgi['cari'] !== null) {
                $cr = cari_hareket_ekle($pdo, ['tur' => (string)$bilgi['cari'], 'cari_id' => $cariId, 'tarih' => $o['tarih'], 'tutar' => kasa_para_db($o['kurus']),
                    'para_birimi' => $pb, 'kur' => $o['kur'] ?? '', 'odeme_sekli' => '', 'evrak_no' => (string)($o['evrak'] ?? ''),
                    'aciklama' => mb_substr($bilgi['etiket'] . ' ' . $belgeNo . ' · ' . $taze['ad'] . ($o['aciklama'] !== '' ? ' · ' . $o['aciklama'] : ''), 0, 500)], $uid, ['modul' => 'kasa', 'id' => $hid]);
                if (empty($cr['ok'])) { $ic = $cr; throw new RuntimeException('Cari hareketi yazılamadı: ' . (string)($cr['hata'] ?? 'bilinmeyen hata'), KASA_IC_HATA); }
                $cariHid = (int)$cr['id']; $cariNo = (string)$cr['belge_no'];
            }
            $pdo->prepare('UPDATE kasa_hareketler SET belge_no = ?, cari_hareket_id = ? WHERE id = ?')->execute([$belgeNo, $cariHid, $hid]);
            return ['ok' => true, 'hata' => null, 'id' => $hid, 'belge_no' => $belgeNo, 'tekrar' => false, 'uyari' => $uyarilar ? implode(' ', $uyarilar) : null,
                'cari_hareket_id' => $cariHid, 'cari_belge_no' => $cariNo];
        });
    } catch (PDOException $e) {
        if ($o['ik'] !== null && ($var = kasa_istek_bul($pdo, $o['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
        }
        error_log('[kasa_hareket_ekle] ' . $e->getMessage());
        return kasa_hata('Hareket kaydedilemedi.');
    } catch (RuntimeException $e) {
        if ($e->getCode() !== KASA_IC_HATA) throw $e;
        $ek = [];
        if (is_array($ic) && !empty($ic['alanlar'])) $ek['alanlar'] = $ic['alanlar'];
        return kasa_hata($e->getMessage(), $ek);
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('create', 'kasa', (int)$s['id'], null, ['kayit' => 'hareket', 'kasa_id' => $kasaId, 'tur' => $tur, 'yon' => $yonH, 'tutar' => $o['kurus'] / 100, 'pb' => $pb, 'belge_no' => $s['belge_no'],
        'cari_id' => $cariId, 'cari_hareket_id' => $s['cari_hareket_id'], 'kalem_id' => $kalemId, 'uyari' => $s['uyari']] + ($kMod !== null ? ['kaynak_modul' => $kMod, 'kaynak_id' => $kId] : []));
    return $s;
}

/**
 * Kasalar arası virman: iki satır (kaynak C + hedef G), AYNI para birimi, farklı kasa; ortak belge_no, bagli_id çapraz; tek transaction.
 * $g: kaynak_kasa_id, hedef_kasa_id, tarih, tutar, aciklama?, istek_anahtari?, eksiye_izin?. Kaynakta yetersiz bakiye → 409 yetersiz_bakiye (eksiye_izin ile geçer).
 * @return array{ok:bool,hata:?string,id:?int,id2?:int,belge_no?:string,tekrar?:bool,uyari?:?string,kod?:string,bakiye?:int,eksik?:int,alanlar?:array}
 */
function kasa_virman_ekle(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = kasa_istek_bul($pdo, $ik)) !== null) {
        $b = kasa_hareket_oku($pdo, (int)$var['id']);
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'id2' => $b !== null && $b['bagli_id'] !== null ? (int)$b['bagli_id'] : null, 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
    }
    $kid = (int)($g['kaynak_kasa_id'] ?? 0); $hid = (int)($g['hedef_kasa_id'] ?? 0);
    if ($kid <= 0 || $hid <= 0) return kasa_hata('Kaynak ve hedef kasayı seçin.', ['alanlar' => ['kaynak_kasa_id' => 'Seçin.', 'hedef_kasa_id' => 'Seçin.']]);
    if ($kid === $hid) return kasa_hata('Kaynak ve hedef kasa aynı olamaz.', ['alanlar' => ['hedef_kasa_id' => 'Kaynaktan farklı olmalı.']]);
    $kk = kasa_kart_oku($pdo, $kid); $hk = kasa_kart_oku($pdo, $hid);
    if ($kk === null || $hk === null) return kasa_hata('Kasa bulunamadı.');
    if ((int)$kk['aktif'] !== 1 || (int)$hk['aktif'] !== 1) return kasa_hata('Pasif kasaya yeni hareket girilemez.');
    if (!kasa_gorunur($kk) || !kasa_gorunur($hk)) return kasa_hata('Kasa aktif deponuzda görünmüyor.');
    if ((string)$kk['para_birimi'] !== (string)$hk['para_birimi']) return kasa_hata('Virman yalnız aynı para birimli kasalar arasında yapılır.', ['alanlar' => ['hedef_kasa_id' => 'Para birimi farklı.']]);
    $pb = (string)$kk['para_birimi'];
    [$o, $h] = kasa_hareket_ortak($g, $pb, false);
    if ($o === null) return kasa_hata((string)reset($h), ['alanlar' => $h]);

    $eksiyeIzin = kasa_onay($g['eksiye_izin'] ?? '');
    $depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $simdi = date('Y-m-d H:i:s');
    $ac = $o['aciklama'] !== '' ? $o['aciklama'] : mb_substr('Virman: ' . $kk['ad'] . ' → ' . $hk['ad'], 0, 500);
    $ins = 'INSERT INTO kasa_hareketler (' . KASA_HAREKET_SUTUNLAR . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    try {
        $s = kasa_tx($pdo, function () use ($pdo, $kid, $hid, $o, $pb, $ac, $depo, $simdi, $uid, $eksiyeIzin, $ins): array {
            kasa_kart_kilitle($pdo, [$kid, $hid]);
            if ($o['ik'] !== null && ($var = kasa_istek_bul($pdo, $o['ik'])) !== null) {
                $b = kasa_hareket_oku($pdo, (int)$var['id']);
                return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'id2' => $b !== null && $b['bagli_id'] !== null ? (int)$b['bagli_id'] : null, 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
            }
            $kt = kasa_kart_oku($pdo, $kid); $ht = kasa_kart_oku($pdo, $hid);
            if ($kt === null || $ht === null || (int)$kt['aktif'] !== 1 || (int)$ht['aktif'] !== 1) return kasa_hata('Pasif kasaya yeni hareket girilemez.');
            $uyari = null;
            $ek = kasa_eksik_hesapla($pdo, $kt, $o['kurus']);
            if ($ek !== null && (int)$kt['eksi_izin'] !== 1) {
                if (!$eksiyeIzin) return kasa_hata('Kaynak kasa bakiyesi yetersiz: ' . cari_tutar_bicim($ek['bakiye'], $pb) . ' mevcut, ' . cari_tutar_bicim($o['kurus'], $pb) . ' çıkış.',
                    ['kod' => 'yetersiz_bakiye', 'bakiye' => $ek['bakiye'], 'eksik' => $ek['eksik']]);
                $uyari = 'Kaynak kasa bakiyesi eksiye düştü (' . cari_tutar_bicim($ek['bakiye'] - $o['kurus'], $pb) . ').';
            }
            $st = $pdo->prepare($ins);
            $st->execute([$kid, $o['tarih'], 'virman', 'C', kasa_para_db($o['kurus']), $pb, $o['kur'], '', $o['evrak'], null, null, null, null, $ac, $depo, null, null, null, $o['ik'], 'gecerli', $simdi, $uid]);
            $id1 = (int)$pdo->lastInsertId();
            $belge = kasa_belge_no('virman', $o['tarih'], $id1);
            $st->execute([$hid, $o['tarih'], 'virman', 'G', kasa_para_db($o['kurus']), $pb, $o['kur'], $belge, $o['evrak'], null, null, null, null, $ac, $depo, $id1, null, null, null, 'gecerli', $simdi, $uid]);
            $id2 = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE kasa_hareketler SET belge_no = ?, bagli_id = ? WHERE id = ?')->execute([$belge, $id2, $id1]);
            return ['ok' => true, 'hata' => null, 'id' => $id1, 'id2' => $id2, 'belge_no' => $belge, 'tekrar' => false, 'uyari' => $uyari];
        });
    } catch (PDOException $e) {
        if ($o['ik'] !== null && ($var = kasa_istek_bul($pdo, $o['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
        }
        error_log('[kasa_virman_ekle] ' . $e->getMessage());
        return kasa_hata('Virman kaydedilemedi.');
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('create', 'kasa', (int)$s['id'], null, ['kayit' => 'hareket', 'tur' => 'virman', 'kaynak_kasa_id' => $kid, 'hedef_kasa_id' => $hid, 'satirlar' => [$s['id'], $s['id2']],
        'tutar' => $o['kurus'] / 100, 'pb' => $pb, 'belge_no' => $s['belge_no'], 'uyari' => $s['uyari']]);
    return $s;
}

/** Bakiyesi eksiye düşen (eksi_izin=0) kasalar için uyarı metni (iptal sonrası; iptal HER ZAMAN yapılır). */
function kasa_eksi_uyarisi(PDO $pdo, array $kasaIdler): ?string
{
    $m = [];
    foreach (array_unique($kasaIdler) as $kid) {
        $k = kasa_kart_oku($pdo, (int)$kid);
        if ($k === null || (int)$k['eksi_izin'] === 1) continue;
        $b = kasa_bakiye($pdo, (int)$kid);
        if ($b < 0) $m[] = $k['ad'] . ' (' . cari_tutar_bicim($b, (string)$k['para_birimi']) . ')';
    }
    return $m ? 'Kasa bakiyesi eksiye düştü: ' . implode(', ', $m) . '.' : null;
}

/**
 * İptal (düzeltme = iptal + yeniden gir). Kaynaklı satır → 409 kaynakli (kaynak belgesinden iptal edilir). Virman iki bacak birlikte;
 * cari bağı cari_kaynakli_iptal($pdo,'kasa',$id,…). Giriş iptali kasayı eksiye düşürürse yine yapılır + uyari.
 */
function kasa_hareket_iptal(PDO $pdo, int $id, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (mb_strlen($neden) < 3) return kasa_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]);
    if (mb_strlen($neden) > 500) return kasa_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
    $r = kasa_hareket_oku($pdo, $id);
    if ($r === null) return kasa_hata('Hareket bulunamadı.');
    if ($r['durum'] === 'iptal') return kasa_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
    if (!empty($r['kaynak_modul'])) return kasa_hata('Bu hareket bir kaynak belgeye bağlı; kaynak belgesinden iptal edin.', ['kod' => 'kaynakli']);
    if (($dr = kasa_depo_ret(kasa_kart_oku($pdo, (int)$r['kasa_id']))) !== null) return $dr;   // başka gerçek depoya ait kasa (boş depo serbest)

    $idler = [$id]; $kasalar = [(int)$r['kasa_id']];
    if ($r['tur'] === 'virman' && !empty($r['bagli_id'])) {
        $b = kasa_hareket_oku($pdo, (int)$r['bagli_id']);
        if ($b !== null && $b['durum'] !== 'iptal') { $idler[] = (int)$b['id']; $kasalar[] = (int)$b['kasa_id']; }
    }
    $sebep = mb_substr('Kasa iptali: ' . $neden, 0, 500);
    try {
        $s = kasa_tx($pdo, function () use ($pdo, $r, $id, $idler, $kasalar, $neden, $sebep, $uid): array {
            kasa_kart_kilitle($pdo, $kasalar);
            $up = $pdo->prepare("UPDATE kasa_hareketler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'");
            $simdi = date('Y-m-d H:i:s');
            $up->execute([$simdi, $uid, $neden, $id]);
            if ($up->rowCount() !== 1) return kasa_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
            foreach (array_slice($idler, 1) as $i) $up->execute([$simdi, $uid, $neden, $i]);
            $cariIptal = [];
            if (!empty($r['cari_hareket_id'])) {
                $cr = cari_kaynakli_iptal($pdo, 'kasa', $id, $sebep, $uid);
                if (empty($cr['ok'])) throw new RuntimeException('Bağlı cari hareketi iptal edilemedi: ' . (string)($cr['hata'] ?? ''), KASA_IC_HATA);
                $cariIptal = $cr['iptal_idler'];
            }
            return ['ok' => true, 'hata' => null, 'id' => $id, 'belge_no' => (string)$r['belge_no'], 'iptal_idler' => $idler, 'cari_iptal' => $cariIptal, 'uyari' => kasa_eksi_uyarisi($pdo, $kasalar)];
        });
    } catch (RuntimeException $e) {
        if ($e->getCode() !== KASA_IC_HATA) throw $e;
        return kasa_hata($e->getMessage());
    }
    if (empty($s['ok'])) return $s;
    audit_log_event('cancel', 'kasa', $id, ['kayit' => 'hareket', 'durum' => 'gecerli', 'belge_no' => $r['belge_no']], ['durum' => 'iptal', 'neden' => $neden, 'satirlar' => $s['iptal_idler'], 'cari_iptal' => $s['cari_iptal'], 'uyari' => $s['uyari']]);
    return $s;
}

/**
 * Kaynak modülün (ör. başka bir belge iptali) kendi çekirdeğinden çağırdığı iptal: o kaynağa bağlı TÜM geçerli kasa satırlarını iptal eder.
 * Yetki KONTROL ETMEZ (çağıran uç denetler); idempotent (iptal edilecek satır yoksa ok + boş liste). Dış transaction varsa onun içinde çalışır.
 * @return array{ok:bool,hata:?string,id:?int,iptal_idler:list<int>,uyari?:?string}
 */
function kasa_kaynakli_iptal(PDO $pdo, string $modul, int $kaynakId, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (!preg_match('/^[a-z_]{2,30}$/', $modul) || $kaynakId <= 0) return kasa_hata('Geçersiz kaynak bağı.') + ['iptal_idler' => []];
    if (mb_strlen($neden) < 3) return kasa_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu (en az 3 karakter).']]) + ['iptal_idler' => []];
    if (mb_strlen($neden) > 500) return kasa_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]) + ['iptal_idler' => []];
    $st = $pdo->prepare("SELECT id, kasa_id, belge_no FROM kasa_hareketler WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'gecerli' ORDER BY id");
    $st->execute([$modul, $kaynakId]);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$satirlar) return ['ok' => true, 'hata' => null, 'id' => null, 'iptal_idler' => [], 'uyari' => null];
    $kasalar = array_map(static fn(array $r): int => (int)$r['kasa_id'], $satirlar);
    $s = kasa_tx($pdo, function () use ($pdo, $modul, $kaynakId, $neden, $uid, $kasalar): array {
        kasa_kart_kilitle($pdo, $kasalar);
        $pdo->prepare("UPDATE kasa_hareketler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'gecerli'")
            ->execute([date('Y-m-d H:i:s'), $uid, $neden, $modul, $kaynakId]);
        return ['uyari' => kasa_eksi_uyarisi($pdo, $kasalar)];
    });
    $idler = array_map(static fn(array $r): int => (int)$r['id'], $satirlar);
    audit_log_event('cancel', 'kasa', $idler[0], ['kayit' => 'hareket', 'durum' => 'gecerli', 'belge_no' => $satirlar[0]['belge_no']],
        ['durum' => 'iptal', 'neden' => $neden, 'satirlar' => $idler, 'kaynak_modul' => $modul, 'kaynak_id' => $kaynakId, 'uyari' => $s['uyari']]);
    return ['ok' => true, 'hata' => null, 'id' => $idler[0], 'iptal_idler' => $idler, 'uyari' => $s['uyari']];
}

// ── Liste / defter ──────────────────────────────────────────────────────────

/** Dönem süzgeci → [bas, bit] (Y-m-d ya da ''). tumu (varsayılan) | bugun | 30g | bu_ay | gecen_ay | ozel (bas/bit). */
function kasa_donem_araligi(array $f): array
{
    $d = (string)($f['donem'] ?? 'tumu');
    $bas = ''; $bit = '';
    if ($d === 'bugun') { $bas = date('Y-m-d'); $bit = $bas; }
    elseif ($d === '30g') $bas = date('Y-m-d', strtotime('-30 days'));
    elseif ($d === 'bu_ay') $bas = date('Y-m-01');
    elseif ($d === 'gecen_ay') { $bas = date('Y-m-01', strtotime('first day of last month')); $bit = date('Y-m-t', strtotime('first day of last month')); }
    elseif ($d === 'ozel' || $d === 'tumu') {
        $bas = kasa_tarih_coz($f['bas'] ?? '') ?? '';
        $bit = kasa_tarih_coz($f['bit'] ?? '') ?? '';
    }
    return [$bas, $bit];
}

/** Cari unvan/kodu (istek içi önbellek; cari_kart_oku ile — cari tablosuna SQL yok). @return array{unvan:string,kod:string}|null */
function kasa_cari_ad(PDO $pdo, int $cariId): ?array
{
    static $onb = [];
    $k = spl_object_id($pdo) . ':' . $cariId;
    if (!array_key_exists($k, $onb)) {
        $c = $cariId > 0 ? cari_kart_oku($pdo, $cariId) : null;
        $onb[$k] = $c === null ? null : ['unvan' => (string)$c['unvan'], 'kod' => (string)$c['kod']];
    }
    return $onb[$k];
}

/** Liste filtresi → [WHERE, parametreler]. Kasa kartı deposu aktif depo + boş depo süzgeçlidir (hareket.depo damgadır). */
function kasa_hareket_where(PDO $pdo, array $f, string $durum): array
{
    $w = ['1=1']; $p = [];
    [$dw, $dp] = kasa_depo_filtre('k.depo');
    $w[] = $dw; array_push($p, ...$dp);
    if (!empty($f['kasa_id'])) { $w[] = 'h.kasa_id = ?'; $p[] = (int)$f['kasa_id']; }
    if (!empty($f['tur'])) { $w[] = 'h.tur = ?'; $p[] = (string)$f['tur']; }
    if (!empty($f['cari_id'])) { $w[] = 'h.cari_id = ?'; $p[] = (int)$f['cari_id']; }
    [$bas, $bit] = kasa_donem_araligi($f);
    if ($bas !== '') { $w[] = 'h.tarih >= ?'; $p[] = $bas; }
    if ($bit !== '') { $w[] = 'h.tarih <= ?'; $p[] = $bit; }
    if ($durum === 'gecerli' || $durum === 'iptal') { $w[] = 'h.durum = ?'; $p[] = $durum; }
    $q = trim((string)($f['ara'] ?? ''));
    if ($q !== '') {
        $l = '%' . $q . '%';
        $kosul = 'h.belge_no LIKE ? OR h.evrak_no LIKE ? OR h.aciklama LIKE ? OR h.kalem_ad LIKE ? OR k.ad LIKE ?';
        array_push($p, $l, $l, $l, $l, $l);
        $cids = array_map(static fn(array $c): int => (int)$c['id'], array_slice(cari_kart_listesi($pdo, ['q' => $q])['satirlar'], 0, 200));   // cari unvanı: cari_core fonksiyonuyla çözülür
        if ($cids) { $kosul .= ' OR h.cari_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ')'; array_push($p, ...$cids); }
        $w[] = '(' . $kosul . ')';
    }
    return [implode(' AND ', $w), $p];
}

/** @param list<array> $satirlar cari_id'li satırlara cari_unvan / cari_kod ekler (istek içi önbellekli). @return list<array> */
function kasa_satir_zenginlestir(PDO $pdo, array $satirlar): array
{
    foreach ($satirlar as &$r) {
        $c = !empty($r['cari_id']) ? kasa_cari_ad($pdo, (int)$r['cari_id']) : null;
        $r['cari_unvan'] = $c['unvan'] ?? null; $r['cari_kod'] = $c['kod'] ?? null;
        $r['tutar_kurus'] = cari_kurus($r['tutar']);
        $r['imzali_kurus'] = $r['yon'] === 'G' ? $r['tutar_kurus'] : -$r['tutar_kurus'];
    }
    unset($r);
    return $satirlar;
}

/**
 * Hareket listesi (EN YENİ ÖNCE: tarih, id azalan). f: kasa_id, tur, durum tumu|gecerli|iptal (varsayılan tumu), donem tumu|bugun|30g|bu_ay|gecen_ay|ozel (+ bas, bit), ara, cari_id.
 * Satırda kasa_kod/kasa_ad/kasa_tip, cari_unvan/cari_kod, tutar_kurus, imzali_kurus, karsi_kasa_ad/karsi_kasa_kod (virman eşinin kasası) ve — yalnız TEK kasa + durum=gecerli +
 * tur/ara/cari süzgeci yokken — yurume (satırdan SONRAKİ kasa bakiyesi, kuruş; kasanın tüm geçerli satırlarına göre) bulunur.
 * ozet: durum=iptal → İPTAL satırlarının pb başına toplamı (ozet_durum='iptal'); gecerli/tumu → yalnız GEÇERLİ satırların toplamı (ozet_durum='gecerli').
 * @return array{satirlar:list<array>,toplam_satir:int,ozet:array<string,array{giris:int,cikis:int,adet:int}>,ozet_durum:string,yurume_var:bool}
 */
function kasa_hareket_listesi(PDO $pdo, array $f, int $limit = 100, int $offset = 0): array
{
    $durum = (string)($f['durum'] ?? 'tumu');
    if (!in_array($durum, ['gecerli', 'iptal', 'tumu'], true)) $durum = 'tumu';
    $join = 'FROM kasa_hareketler h JOIN kasa_kartlar k ON k.id = h.kasa_id';
    [$w, $p] = kasa_hareket_where($pdo, $f, $durum);
    $st = $pdo->prepare("SELECT COUNT(*) $join WHERE $w");
    $st->execute($p);
    $toplam = (int)$st->fetchColumn();

    $limit = max(1, min(1000, $limit)); $offset = max(0, $offset);
    $st = $pdo->prepare("SELECT h.*, k.kod AS kasa_kod, k.ad AS kasa_ad, k.tip AS kasa_tip, bk.ad AS karsi_kasa_ad, bk.kod AS karsi_kasa_kod
        $join LEFT JOIN kasa_hareketler b ON b.id = h.bagli_id LEFT JOIN kasa_kartlar bk ON bk.id = b.kasa_id
        WHERE $w ORDER BY h.tarih DESC, h.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $satirlar = kasa_satir_zenginlestir($pdo, $st->fetchAll(PDO::FETCH_ASSOC));

    $yurumeVar = !empty($f['kasa_id']) && $durum === 'gecerli' && empty($f['tur']) && empty($f['ara']) && empty($f['cari_id']) && $satirlar;
    if ($yurumeVar) {
        $ust = $satirlar[0];
        $st = $pdo->prepare("SELECT SUM(CASE WHEN yon = 'G' THEN tutar ELSE 0 END) AS g, SUM(CASE WHEN yon = 'C' THEN tutar ELSE 0 END) AS c FROM kasa_hareketler
            WHERE kasa_id = ? AND durum = 'gecerli' AND (tarih < ? OR (tarih = ? AND id <= ?))");
        $st->execute([(int)$f['kasa_id'], $ust['tarih'], $ust['tarih'], (int)$ust['id']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $bak = cari_kurus($r['g'] ?? 0) - cari_kurus($r['c'] ?? 0);
        foreach ($satirlar as &$s) { $s['yurume'] = $bak; $bak -= $s['imzali_kurus']; }
        unset($s);
    }

    // Özet listelenen süzgeçle TUTARLI: durum=iptal → iptal satırlarının toplamı; gecerli/tumu → yalnız geçerliler (iptaller toplanmaz)
    [$w2, $p2] = kasa_hareket_where($pdo, $f, $durum === 'iptal' ? 'iptal' : 'gecerli');
    $st = $pdo->prepare("SELECT h.para_birimi AS pb, COUNT(*) AS adet, SUM(CASE WHEN h.yon = 'G' THEN h.tutar ELSE 0 END) AS g, SUM(CASE WHEN h.yon = 'C' THEN h.tutar ELSE 0 END) AS c $join WHERE $w2 GROUP BY h.para_birimi");
    $st->execute($p2);
    $ozet = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ozet[(string)$r['pb']] = ['giris' => cari_kurus($r['g']), 'cikis' => cari_kurus($r['c']), 'adet' => (int)$r['adet']];
    return ['satirlar' => $satirlar, 'toplam_satir' => $toplam, 'ozet' => cari_pb_sirala($ozet), 'ozet_durum' => $durum === 'iptal' ? 'iptal' : 'gecerli', 'yurume_var' => (bool)$yurumeVar];
}

/**
 * Kasa defteri: devir (bas'tan ÖNCEKİ geçerli bakiye) + dönem satırları (tarih, id artan; yalnız geçerli) + yürüyen bakiye + dönem giriş/çıkış toplamı + kapanış.
 * bas boşsa devir 0; bit boşsa sınırsız. Kasa yoksa ya da aktif depoda görünmüyorsa null.
 * @return array{kasa:array,pb:string,bas:string,bit:string,devir:int,satirlar:list<array>,donem_giris:int,donem_cikis:int,kapanis:int}|null
 */
function kasa_defter(PDO $pdo, int $kasaId, ?string $bas, ?string $bit): ?array
{
    $kasa = kasa_kart_oku($pdo, $kasaId);
    if ($kasa === null || !kasa_gorunur($kasa)) return null;
    $bas = (string)($bas ?? ''); $bit = (string)($bit ?? '');
    $devir = 0;
    if ($bas !== '') {
        $st = $pdo->prepare("SELECT SUM(CASE WHEN yon = 'G' THEN tutar ELSE 0 END) AS g, SUM(CASE WHEN yon = 'C' THEN tutar ELSE 0 END) AS c FROM kasa_hareketler WHERE kasa_id = ? AND durum = 'gecerli' AND tarih < ?");
        $st->execute([$kasaId, $bas]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $devir = cari_kurus($r['g'] ?? 0) - cari_kurus($r['c'] ?? 0);
    }
    $w = "kasa_id = ? AND durum = 'gecerli'"; $p = [$kasaId];
    if ($bas !== '') { $w .= ' AND tarih >= ?'; $p[] = $bas; }
    if ($bit !== '') { $w .= ' AND tarih <= ?'; $p[] = $bit; }
    $st = $pdo->prepare("SELECT * FROM kasa_hareketler WHERE $w ORDER BY tarih, id");
    $st->execute($p);
    $satirlar = kasa_satir_zenginlestir($pdo, $st->fetchAll(PDO::FETCH_ASSOC));
    $yur = $devir; $gir = 0; $cik = 0;
    foreach ($satirlar as &$s) {
        $yur += $s['imzali_kurus'];
        if ($s['yon'] === 'G') $gir += $s['tutar_kurus']; else $cik += $s['tutar_kurus'];
        $s['yurume'] = $yur;
    }
    unset($s);
    return ['kasa' => $kasa, 'pb' => (string)$kasa['para_birimi'], 'bas' => $bas, 'bit' => $bit, 'devir' => $devir, 'satirlar' => $satirlar,
        'donem_giris' => $gir, 'donem_cikis' => $cik, 'kapanis' => $devir + $gir - $cik];
}

// ── Tanımlar (gelir / gider kalemleri) ──────────────────────────────────────

/** Kullanım sayısıyla (kalemi kullanan hareket, iptal dahil). @return list<array> */
function kasa_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array
{
    if (!isset(kasa_tanim_turleri()[$tur])) return [];
    $st = $pdo->prepare('SELECT t.*, (SELECT COUNT(*) FROM kasa_hareketler h WHERE h.kalem_id = t.id) AS kullanim FROM kasa_tanimlar t WHERE t.tur = ?'
        . ($yalnizAktif ? ' AND t.aktif = 1' : '') . ' ORDER BY t.sira, t.ad, t.id');
    $st->execute([$tur]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kasa_tanim_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM kasa_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Ad tür içinde TR-duyarsız tekil; düzenlemede tür değişmez. */
function kasa_tanim_kaydet(PDO $pdo, string $tur, string $ad, int $sira, int $uid, ?int $id = null): array
{
    $eski = null;
    if ($id !== null) {
        $eski = kasa_tanim_bul($pdo, $id);
        if ($eski === null) return kasa_hata('Tanım bulunamadı.');
        $tur = (string)$eski['tur'];
    }
    if (!isset(kasa_tanim_turleri()[$tur])) return kasa_hata('Geçersiz tanım türü.');
    $ad = trim($ad);
    if ($ad === '' || mb_strlen($ad) > 100) return kasa_hata('Ad 1-100 karakter olmalı.', ['alanlar' => ['ad' => 'Ad 1-100 karakter olmalı.']]);
    $st = $pdo->prepare('SELECT id, ad FROM kasa_tanimlar WHERE tur = ?');
    $st->execute([$tur]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($id !== null && (int)$r['id'] === $id) continue;
        if (cari_fold((string)$r['ad']) === cari_fold($ad)) return kasa_hata('Bu ad zaten tanımlı.', ['alanlar' => ['ad' => 'Bu ad zaten tanımlı.']]);
    }
    $simdi = date('Y-m-d H:i:s');
    if ($id === null) {
        try {
            $pdo->prepare('INSERT INTO kasa_tanimlar (tur, ad, sira, aktif, created_at, created_by) VALUES (?, ?, ?, 1, ?, ?)')->execute([$tur, $ad, $sira, $simdi, $uid]);
        } catch (PDOException $e) {
            return kasa_hata('Bu ad zaten tanımlı.', ['kod' => 'kullanimda', 'alanlar' => ['ad' => 'Bu ad zaten tanımlı.']]);
        }
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'kasa', $yid, null, ['kayit' => 'tanim', 'tur' => $tur, 'ad' => $ad, 'sira' => $sira]);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }
    try {
        $pdo->prepare('UPDATE kasa_tanimlar SET ad = ?, sira = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$ad, $sira, $simdi, $uid, $id]);
    } catch (PDOException $e) {
        return kasa_hata('Bu ad zaten tanımlı.', ['kod' => 'kullanimda', 'alanlar' => ['ad' => 'Bu ad zaten tanımlı.']]);
    }
    audit_log_event('update', 'kasa', $id, ['kayit' => 'tanim', 'tur' => $tur, 'ad' => $eski['ad']], ['kayit' => 'tanim', 'tur' => $tur, 'ad' => $ad, 'sira' => $sira]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

function kasa_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $t = kasa_tanim_bul($pdo, $id);
    if ($t === null) return kasa_hata('Tanım bulunamadı.');
    $pdo->prepare('UPDATE kasa_tanimlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    audit_log_event($aktif ? 'activate' : 'deactivate', 'kasa', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad'], 'aktif' => (int)$t['aktif']], ['kayit' => 'tanim', 'aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Kullanılan kalem silinemez (409 kullanimda; pasifleştirin). Kullanılmayan serbest silinir. */
function kasa_tanim_sil(PDO $pdo, int $id, int $uid): array
{
    $t = kasa_tanim_bul($pdo, $id);
    if ($t === null) return kasa_hata('Tanım bulunamadı.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM kasa_hareketler WHERE kalem_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) return kasa_hata('Bu kalem hareketlerde kullanılıyor; silmek yerine pasifleştirin.', ['kod' => 'kullanimda']);
    $pdo->prepare('DELETE FROM kasa_tanimlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'kasa', $id, ['kayit' => 'tanim', 'tur' => $t['tur'], 'ad' => $t['ad']], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}
