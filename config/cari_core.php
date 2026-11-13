<?php
// =========================================================
// config/cari_core.php — Cari İşlemler çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 3 tablo: cari_kartlar · cari_hareketler (TEK ledger) · cari_tanimlar (grup / ödeme şekli).
// Şema YALNIZ migrate.php "Cari İşlemler" kartından kurulur (cari_migrate; ALTER ve FK YOK, GO kuralı).
// Yön kuralı (her kart tipinde aynı): hareket yon = B (borç) / A (alacak); bakiye = ΣB − ΣA.
//   >0 Borçlu (cari bize borçlu) · <0 Alacaklı (biz ona borçluyuz) · 0 Denk. Para birimleri ASLA toplanmaz.
// Ledger DEĞİŞMEZ: hareket satırı silinmez; UPDATE yalnız (a) insert sonrası belge_no/bagli_id, (b) iptal alanları.
// Tutarlar kuruş TAM SAYI olarak taşınır (float toplama yok). Yazan fonksiyonlar yetki KONTROL ETMEZ (uçların işi).
// Hareket tablosuna INSERT/UPDATE yazan BAŞKA dosya olmaz.
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/hesap_calc.php';   // yalnız hesap_parse_amount (yan etkisiz)

const CARI_TUTAR_MAX_KURUS = 99999999999999;   // 999.999.999.999,99

// ── Şema / kapı ─────────────────────────────────────────────────────────────

/** Üç tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım kuralları geçerlidir. @return array<string,string> */
function cari_tablolar(): array
{
    return [
        'cari_kartlar' => "CREATE TABLE IF NOT EXISTS `cari_kartlar` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `kod`           VARCHAR(30)   NOT NULL,
  `unvan`         VARCHAR(200)  NOT NULL,
  `tip`           VARCHAR(20)   NOT NULL DEFAULT 'musteri',
  `grup_id`       INT           NULL DEFAULT NULL,
  `vergi_dairesi` VARCHAR(100)  NULL DEFAULT NULL,
  `vergi_no`      VARCHAR(20)   NULL DEFAULT NULL,
  `yetkili`       VARCHAR(150)  NULL DEFAULT NULL,
  `telefon`       VARCHAR(40)   NULL DEFAULT NULL,
  `eposta`        VARCHAR(150)  NULL DEFAULT NULL,
  `adres`         VARCHAR(500)  NULL DEFAULT NULL,
  `il`            VARCHAR(60)   NULL DEFAULT NULL,
  `ulke`          VARCHAR(60)   NULL DEFAULT NULL,
  `iban`          VARCHAR(40)   NULL DEFAULT NULL,
  `para_birimi`   VARCHAR(3)    NOT NULL DEFAULT 'TRY',
  `vade_gun`      INT           NULL DEFAULT NULL,
  `notlar`        TEXT          NULL,
  `aktif`         TINYINT(1)    NOT NULL DEFAULT 1,
  `md_id`         INT           NULL DEFAULT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`    INT           NULL DEFAULT NULL,
  `updated_at`    DATETIME      NULL DEFAULT NULL,
  `updated_by`    INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_cari_kod` (`kod`),
  KEY `idx_cari_unvan` (`unvan`),
  KEY `idx_cari_tip_aktif` (`tip`, `aktif`),
  KEY `idx_cari_grup` (`grup_id`),
  KEY `idx_cari_md` (`md_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'cari_hareketler' => "CREATE TABLE IF NOT EXISTS `cari_hareketler` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `cari_id`        INT            NOT NULL,
  `tarih`          DATE           NOT NULL,
  `tur`            VARCHAR(20)    NOT NULL,
  `yon`            CHAR(1)        NOT NULL,
  `tutar`          DECIMAL(15,2)  NOT NULL,
  `para_birimi`    VARCHAR(3)     NOT NULL DEFAULT 'TRY',
  `kur`            DECIMAL(18,6)  NULL DEFAULT NULL,
  `belge_no`       VARCHAR(30)    NOT NULL DEFAULT '',
  `evrak_no`       VARCHAR(60)    NULL DEFAULT NULL,
  `odeme_sekli`    VARCHAR(60)    NULL DEFAULT NULL,
  `aciklama`       VARCHAR(500)   NOT NULL DEFAULT '',
  `bagli_id`       INT            NULL DEFAULT NULL,
  `depo`           VARCHAR(150)   NOT NULL DEFAULT '',
  `kaynak_modul`   VARCHAR(30)    NULL DEFAULT NULL,
  `kaynak_id`      INT            NULL DEFAULT NULL,
  `istek_anahtari` VARCHAR(40)    NULL DEFAULT NULL,
  `durum`          VARCHAR(10)    NOT NULL DEFAULT 'gecerli',
  `iptal_at`       DATETIME       NULL DEFAULT NULL,
  `iptal_by`       INT            NULL DEFAULT NULL,
  `iptal_neden`    VARCHAR(500)   NULL DEFAULT NULL,
  `created_at`     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`     INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_carih_istek` (`istek_anahtari`),
  KEY `idx_carih_cari` (`cari_id`, `durum`, `para_birimi`, `tarih`),
  KEY `idx_carih_tarih` (`tarih`),
  KEY `idx_carih_tur` (`tur`, `tarih`),
  KEY `idx_carih_belge` (`belge_no`),
  KEY `idx_carih_kaynak` (`kaynak_modul`, `kaynak_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'cari_tanimlar' => "CREATE TABLE IF NOT EXISTS `cari_tanimlar` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `tur`        VARCHAR(20)   NOT NULL,
  `ad`         VARCHAR(100)  NOT NULL,
  `sira`       INT           NOT NULL DEFAULT 0,
  `aktif`      TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_carit_tur_ad` (`tur`, `ad`),
  KEY `idx_carit_tur` (`tur`, `aktif`, `sira`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Üç tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function cari_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(cari_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + ödeme şekli tohumu (cari_tanimlar YENİ kurulduysa).
 * Yalnız migrate.php kartından çağrılır. TEST KANCASI: $GLOBALS['_cari_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function cari_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_cari_ddl_calistir'] ?? null;   // YALNIZ TEST KANCASI
    foreach (cari_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (PDOException $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'cari_tanimlar') {
                $say = (int)$pdo->query('SELECT COUNT(*) FROM `cari_tanimlar`')->fetchColumn();
                if ($say === 0) {
                    $ins = $pdo->prepare('INSERT INTO `cari_tanimlar` (tur, ad, sira, aktif, created_at) VALUES (?, ?, ?, 1, ?)');
                    foreach (['Nakit', 'Havale/EFT', 'Çek', 'Senet', 'Kredi Kartı', 'Mahsup/Diğer'] as $i => $o) {
                        $ins->execute(['odeme_sekli', $o, ($i + 1) * 10, date('Y-m-d H:i:s')]);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('[cari_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** JSON (fetch) isteği mi? */
function cari_json_mi(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' || ($_REQUEST['ajax'] ?? '') === '1';
}

/** Tablolar yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function cari_sayfa_kapisi(PDO $pdo): void
{
    if (cari_hazir($pdo)) return;
    if (cari_json_mi()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Cari tabloları kurulmamış. Yönetici: Şema Migrasyon → Cari İşlemler kartı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render_header('Cari İşlemler');
    if (function_exists('cari_assets')) cari_assets();
    if (function_exists('cari_sema_uyari')) {
        echo cari_sema_uyari();
    } else {
        echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
            . '<h2 style="margin-top:0;">Cari İşlemler</h2>'
            . '<p>Cari tabloları kurulmamış. Yönetici: Şema Migrasyon → Cari İşlemler kartı.</p>'
            . '<p><a class="btn btn-primary" href="migrate.php#cari">Şema Migrasyon › Cari İşlemler</a></p>'
            . '</div></div>';
    }
    render_footer();
    exit;
}

/** Faz 1: hepsi is_admin(). Faz 2'nin TEK değişim noktası. $eylem: read|write|delete|admin */
function cari_can(string $eylem): bool
{
    return in_array($eylem, ['read', 'write', 'delete', 'admin'], true) && is_admin();
}

function cari_kapi(string $eylem): void
{
    if (cari_can($eylem)) return;
    if (cari_json_mi()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    forbidden('Bu işlem için yetkiniz yok.');
}

function cari_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
    }
}

// ── Sabitler / biçim ────────────────────────────────────────────────────────

/** @return array<string,string> */
function cari_tipler(): array
{
    return ['musteri' => 'Müşteri', 'tedarikci' => 'Tedarikçi', 'ikisi' => 'Müşteri+Tedarikçi', 'personel' => 'Personel/Çavuş', 'diger' => 'Diğer'];
}

/** @return list<string> */
function cari_para_birimleri(): array { return ['TRY', 'USD', 'EUR']; }

/** Para birimi anahtarlı diziyi TRY, USD, EUR sırasına dizer (bilinmeyenler sona, alfabetik). */
function cari_pb_sirala(array $d): array
{
    $sira = array_flip(cari_para_birimleri());
    uksort($d, static fn($a, $b): int => [$sira[$a] ?? 99, (string)$a] <=> [$sira[$b] ?? 99, (string)$b]);
    return $d;
}

/**
 * tur → etiket, sinif (fis|dekont), yon (B | A | 'sec' = kullanıcı seçer | 'cift' = virman), onek.
 * @return array<string,array{etiket:string,sinif:string,yon:string,onek:string}>
 */
function cari_hareket_turleri(): array
{
    return [
        'tahsilat'      => ['etiket' => 'Tahsilat Fişi',        'sinif' => 'fis',    'yon' => 'A',    'onek' => 'TAH'],
        'odeme'         => ['etiket' => 'Ödeme (Tediye) Fişi',  'sinif' => 'fis',    'yon' => 'B',    'onek' => 'ODE'],
        'acilis'        => ['etiket' => 'Açılış/Devir Fişi',    'sinif' => 'fis',    'yon' => 'sec',  'onek' => 'ACL'],
        'borc_dekont'   => ['etiket' => 'Borç Dekontu',         'sinif' => 'dekont', 'yon' => 'B',    'onek' => 'BDK'],
        'alacak_dekont' => ['etiket' => 'Alacak Dekontu',       'sinif' => 'dekont', 'yon' => 'A',    'onek' => 'ADK'],
        'virman'        => ['etiket' => 'Cariler Arası Virman', 'sinif' => 'dekont', 'yon' => 'cift', 'onek' => 'VRM'],
    ];
}

function cari_tur_bilgi(string $tur): ?array { return cari_hareket_turleri()[$tur] ?? null; }

/** @return array<string,string> */
function cari_tanim_turleri(): array { return ['grup' => 'Cari Grubu', 'odeme_sekli' => 'Ödeme Şekli']; }

/** DECIMAL/string/float → kuruş (yuvarlanmış tam sayı). */
function cari_kurus(string|int|float|null $v): int
{
    if ($v === null || $v === '') return 0;
    return (int)round((float)$v * 100);
}

/** 123456 → "1.234,56 ₺" (iki hane daima; eksi işaret başta). */
function cari_tutar_bicim(int $kurus, string $pb, bool $sembol = true): string
{
    $mut = abs($kurus);
    $s = ($kurus < 0 ? '-' : '') . number_format((float)intdiv($mut, 100), 0, ',', '.') . ',' . str_pad((string)($mut % 100), 2, '0', STR_PAD_LEFT);
    if (!$sembol) return $s;
    $sym = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€'][$pb] ?? $pb;
    return $s . ' ' . $sym;
}

/** @return array{metin:string,sinif:string} */
function cari_bakiye_etiketi(int $kurus): array
{
    if ($kurus > 0) return ['metin' => 'Borçlu', 'sinif' => 'cr-b'];
    if ($kurus < 0) return ['metin' => 'Alacaklı', 'sinif' => 'cr-a'];
    return ['metin' => 'Denk', 'sinif' => 'cr-0'];
}

/** TAH-2026-000123 */
function cari_belge_no(string $tur, string $tarih, int $id): string
{
    $onek = cari_tur_bilgi($tur)['onek'] ?? 'HRK';
    return $onek . '-' . substr($tarih, 0, 4) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

/** TR-duyarsız karşılaştırma anahtarı (İ/I/ı hepsi "i": "FIRMA" == "Firma" == "Fırma"). */
function cari_fold(string $s): string
{
    return mb_strtolower(trim(strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i'])), 'UTF-8');
}

// ── Doğrulama ───────────────────────────────────────────────────────────────

/** Tutar → kuruş. ≤0, sayı değil, üst sınır, 2'den fazla ondalık → null (sessiz yuvarlama YOK). */
function cari_tutar_coz(mixed $ham): ?int
{
    if (is_array($ham) || is_object($ham) || is_bool($ham)) return null;
    $v = hesap_parse_amount($ham);
    if (!is_finite($v) || $v <= 0) return null;
    $k = (int)round($v * 100);
    if ($k <= 0 || $k > CARI_TUTAR_MAX_KURUS) return null;
    if (abs($v * 100 - $k) > 1e-6) return null;
    return $k;
}

/** @return string|null|false  boş → null; TRY'de dolu → false; geçersiz → false; aksi normalize string ("32.451200") */
function cari_kur_coz(mixed $ham, string $pb): string|null|false
{
    if (is_array($ham) || is_object($ham)) return false;
    $s = preg_replace('/[\s\x{00A0}]+/u', '', trim((string)$ham)) ?? '';
    if ($s === '') return null;
    if ($pb === 'TRY') return false;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    if (!preg_match('/^\d{1,9}(\.\d{1,6})?$/', $s)) return false;
    if ((float)$s <= 0) return false;
    return number_format((float)$s, 6, '.', '');
}

function cari_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return false;
    return $t >= '2000-01-01' && $t <= date('Y-m-d', strtotime('+366 days'));
}

function cari_kod_normalize(string $k): string { return mb_strtoupper(trim($k), 'UTF-8'); }

/** C + 5 hane, en büyük C\d+ + 1. */
function cari_kod_oner(PDO $pdo): string
{
    $max = 0;
    foreach ($pdo->query("SELECT kod FROM cari_kartlar WHERE kod LIKE 'C%'")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        if (preg_match('/^C(\d+)$/', (string)$k, $m)) $max = max($max, (int)$m[1]);
    }
    return 'C' . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

/** @return array{ok:bool,veri?:array,hatalar?:array<string,string>} */
function cari_kart_dogrula(PDO $pdo, array $g, ?int $id): array
{
    $h = []; $v = [];
    $metin = static function (string $alan, int $max, bool $zorunlu = false) use (&$h, &$v, $g): void {
        $s = trim((string)($g[$alan] ?? ''));
        if ($s === '') { if ($zorunlu) $h[$alan] = 'Zorunlu alan.'; $v[$alan] = null; return; }
        if (mb_strlen($s) > $max) { $h[$alan] = "En çok $max karakter."; return; }
        $v[$alan] = $s;
    };

    $unvan = trim((string)($g['unvan'] ?? ''));
    if (mb_strlen($unvan) < 2) $h['unvan'] = 'Unvan en az 2 karakter olmalı.';
    elseif (mb_strlen($unvan) > 200) $h['unvan'] = 'Unvan en çok 200 karakter.';
    else $v['unvan'] = $unvan;

    $kod = cari_kod_normalize((string)($g['kod'] ?? ''));
    if ($kod === '') {
        $kod = cari_kod_oner($pdo);
    } elseif (!preg_match('/^[A-Z0-9._-]{1,30}$/', $kod)) {
        $h['kod'] = 'Kod 1-30 karakter; yalnız harf, rakam, . _ -';
    }
    if (!isset($h['kod'])) {
        $st = $pdo->prepare('SELECT id FROM cari_kartlar WHERE kod = ?');
        $st->execute([$kod]);
        $var = $st->fetchColumn();
        if ($var !== false && ($id === null || (int)$var !== $id)) $h['kod'] = 'Bu kod başka bir cari kartta kullanılıyor.';
        else $v['kod'] = $kod;
    }

    $tip = (string)($g['tip'] ?? 'musteri');
    if (!isset(cari_tipler()[$tip])) $h['tip'] = 'Geçersiz cari tipi.'; else $v['tip'] = $tip;
    $pb = (string)($g['para_birimi'] ?? 'TRY');
    if (!in_array($pb, cari_para_birimleri(), true)) $h['para_birimi'] = 'Geçersiz para birimi.'; else $v['para_birimi'] = $pb;

    $grup = $g['grup_id'] ?? null;
    if ($grup === null || $grup === '' || $grup === 0 || $grup === '0') {
        $v['grup_id'] = null;
    } else {
        $gid = (int)$grup;
        $st = $pdo->prepare("SELECT 1 FROM cari_tanimlar WHERE id = ? AND tur = 'grup'");
        $st->execute([$gid]);
        if ($st->fetchColumn() === false) $h['grup_id'] = 'Geçersiz cari grubu.'; else $v['grup_id'] = $gid;
    }

    $metin('vergi_dairesi', 100); $metin('yetkili', 150); $metin('telefon', 40); $metin('adres', 500);
    $metin('il', 60); $metin('ulke', 60); $metin('notlar', 5000);

    $vn = preg_replace('/\s+/', '', (string)($g['vergi_no'] ?? '')) ?? '';
    if ($vn === '') $v['vergi_no'] = null;
    elseif (!preg_match('/^\d{10}$|^\d{11}$/', $vn)) $h['vergi_no'] = 'Vergi no 10 ya da 11 rakam olmalı.';
    else $v['vergi_no'] = $vn;

    $ep = trim((string)($g['eposta'] ?? ''));
    if ($ep === '') $v['eposta'] = null;
    elseif (mb_strlen($ep) > 150 || !filter_var($ep, FILTER_VALIDATE_EMAIL)) $h['eposta'] = 'Geçerli bir e-posta girin.';
    else $v['eposta'] = $ep;

    $ib = strtoupper(preg_replace('/\s+/', '', (string)($g['iban'] ?? '')) ?? '');
    if ($ib === '') $v['iban'] = null;
    elseif (!preg_match('/^TR\d{24}$/', $ib) && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $ib)) $h['iban'] = 'Geçerli bir IBAN girin.';
    else $v['iban'] = $ib;

    $vg = $g['vade_gun'] ?? null;
    if ($vg === null || trim((string)$vg) === '') $v['vade_gun'] = null;
    elseif (!preg_match('/^\d{1,3}$/', trim((string)$vg)) || (int)trim((string)$vg) > 365) $h['vade_gun'] = 'Vade 0-365 gün olmalı.';
    else $v['vade_gun'] = (int)trim((string)$vg);

    if (array_key_exists('aktif', $g)) $v['aktif'] = !empty($g['aktif']) && (string)$g['aktif'] !== '0' ? 1 : 0;

    return $h ? ['ok' => false, 'hatalar' => $h] : ['ok' => true, 'veri' => $v];
}

// ── Küçük yardımcılar (iç) ──────────────────────────────────────────────────

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function cari_tx(PDO $pdo, callable $fn): mixed
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

function cari_hata(string $msg, array $ek = []): array
{
    return ['ok' => false, 'hata' => $msg, 'id' => null] + $ek;
}

// ── Kart ────────────────────────────────────────────────────────────────────

const CARI_KART_ALANLAR = ['kod', 'unvan', 'tip', 'grup_id', 'vergi_dairesi', 'vergi_no', 'yetkili', 'telefon', 'eposta', 'adres', 'il', 'ulke', 'iban', 'para_birimi', 'vade_gun', 'notlar', 'aktif'];

function cari_kart_oku(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT k.*, t.ad AS grup_adi FROM cari_kartlar k LEFT JOIN cari_tanimlar t ON t.id = k.grup_id WHERE k.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** @return array{ok:bool,hata:?string,id:?int,alanlar?:array} */
function cari_kart_kaydet(PDO $pdo, array $g, int $uid, ?int $id = null): array
{
    $eski = null;
    if ($id !== null) {
        $eski = cari_kart_oku($pdo, $id);
        if ($eski === null) return cari_hata('Cari kart bulunamadı.');
    }
    $d = cari_kart_dogrula($pdo, $g, $id);
    if (!$d['ok']) return cari_hata((string)reset($d['hatalar']), ['alanlar' => $d['hatalar']]);
    $v = $d['veri'];
    $simdi = date('Y-m-d H:i:s');

    if ($id === null) {
        $v += ['aktif' => 1];
        $kol = array_keys($v);
        $sql = 'INSERT INTO cari_kartlar (' . implode(',', $kol) . ', created_at, created_by) VALUES (' . implode(',', array_fill(0, count($kol), '?')) . ', ?, ?)';
        try {
            $pdo->prepare($sql)->execute([...array_values($v), $simdi, $uid]);
        } catch (PDOException $e) {
            return cari_hata('Cari kart kaydedilemedi (kod çakışması olabilir).');
        }
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'cari', $yid, null, $v);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }

    $fark = []; $eskiFark = [];
    foreach ($v as $k => $nv) {
        if (($eski[$k] ?? null) != $nv || (($eski[$k] ?? null) === null) !== ($nv === null)) { $fark[$k] = $nv; $eskiFark[$k] = $eski[$k] ?? null; }
    }
    if ($fark) {
        $set = implode(', ', array_map(static fn(string $k): string => "$k = ?", array_keys($fark)));
        try {
            $pdo->prepare("UPDATE cari_kartlar SET $set, updated_at = ?, updated_by = ? WHERE id = ?")->execute([...array_values($fark), $simdi, $uid, $id]);
        } catch (PDOException $e) {
            return cari_hata('Cari kart güncellenemedi (kod çakışması olabilir).');
        }
        audit_log_event('update', 'cari', $id, $eskiFark, $fark);
    }
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Pasife alırken bakiye sıfırdan farklıysa yine izin verir, `uyari` döner. */
function cari_kart_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $k = cari_kart_oku($pdo, $id);
    if ($k === null) return cari_hata('Cari kart bulunamadı.');
    $pdo->prepare('UPDATE cari_kartlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')
        ->execute([$aktif ? 1 : 0, date('Y-m-d H:i:s'), $uid, $id]);
    $uyari = null;
    if (!$aktif) {
        foreach (cari_bakiye($pdo, $id) as $pb => $b) {
            if ($b['bakiye'] !== 0) { $uyari = 'Kartın bakiyesi sıfır değil; pasif kart yeni hareket almaz.'; break; }
        }
    }
    audit_log_event($aktif ? 'activate' : 'deactivate', 'cari', $id, ['aktif' => (int)$k['aktif']], ['aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id, 'uyari' => $uyari];
}

/** Fiziksel silme YALNIZ hiç hareket satırı (iptaller dahil) yoksa. */
function cari_kart_sil(PDO $pdo, int $id, int $uid): array
{
    $k = cari_kart_oku($pdo, $id);
    if ($k === null) return cari_hata('Cari kart bulunamadı.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM cari_hareketler WHERE cari_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        return cari_hata('Hareketi olan cari silinemez; pasifleştirin.', ['kod' => 'hareketli']);
    }
    $pdo->prepare('DELETE FROM cari_kartlar WHERE id = ?')->execute([$id]);
    unset($k['grup_adi']);
    audit_log_event('delete', 'cari', $id, $k, null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/**
 * Filtre: q, tip, grup_id, aktif (1/0/null), bakiye (borclu|alacakli|sifir|null).
 * @return array{satirlar:list<array>,kesildi:bool}  her satırda `bakiyeler` (pb → kuruş)
 */
function cari_kart_listesi(PDO $pdo, array $f = []): array
{
    $w = ['1=1']; $p = [];
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(k.kod LIKE ? OR k.unvan LIKE ? OR k.vergi_no LIKE ? OR k.telefon LIKE ?)';
        $l = '%' . $q . '%';
        array_push($p, $l, $l, $l, $l);
    }
    if (!empty($f['tip'])) { $w[] = 'k.tip = ?'; $p[] = (string)$f['tip']; }
    if (!empty($f['grup_id'])) { $w[] = 'k.grup_id = ?'; $p[] = (int)$f['grup_id']; }
    if (isset($f['aktif']) && $f['aktif'] !== '' && $f['aktif'] !== null) { $w[] = 'k.aktif = ?'; $p[] = (int)$f['aktif']; }
    $st = $pdo->prepare('SELECT k.*, t.ad AS grup_adi FROM cari_kartlar k LEFT JOIN cari_tanimlar t ON t.id = k.grup_id WHERE ' . implode(' AND ', $w) . ' ORDER BY k.unvan, k.id');
    $st->execute($p);
    $kartlar = $st->fetchAll(PDO::FETCH_ASSOC);

    // Tek GROUP BY cari_id, para_birimi sorgusu
    $bak = [];
    foreach ($pdo->query("SELECT cari_id, para_birimi, SUM(CASE WHEN yon = 'B' THEN tutar ELSE 0 END) AS b, SUM(CASE WHEN yon = 'A' THEN tutar ELSE 0 END) AS a FROM cari_hareketler WHERE durum = 'gecerli' GROUP BY cari_id, para_birimi")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bak[(int)$r['cari_id']][(string)$r['para_birimi']] = cari_kurus($r['b']) - cari_kurus($r['a']);
    }
    $bf = (string)($f['bakiye'] ?? '');
    $out = [];
    foreach ($kartlar as $k) {
        $b = $bak[(int)$k['id']] ?? [];
        if ($bf === 'borclu' && !array_filter($b, static fn(int $x): bool => $x > 0)) continue;
        if ($bf === 'alacakli' && !array_filter($b, static fn(int $x): bool => $x < 0)) continue;
        if ($bf === 'sifir' && array_filter($b, static fn(int $x): bool => $x !== 0)) continue;
        $k['bakiyeler'] = $b;
        $out[] = $k;
    }
    $kesildi = count($out) > 2000;
    if ($kesildi) $out = array_slice($out, 0, 2000);
    return ['satirlar' => $out, 'kesildi' => $kesildi];
}

// ── Hareket (ledger) ────────────────────────────────────────────────────────

function cari_hareket_oku(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT h.*, k.unvan, k.kod FROM cari_hareketler h LEFT JOIN cari_kartlar k ON k.id = h.cari_id WHERE h.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function cari_istek_bul(PDO $pdo, string $anahtar): ?array
{
    $st = $pdo->prepare('SELECT id, belge_no FROM cari_hareketler WHERE istek_anahtari = ?');
    $st->execute([$anahtar]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Ortak alan doğrulaması (tutar/kur/tarih/pb/açıklama). @return array{0:?array,1:array<string,string>} */
function cari_hareket_ortak(array $g, string $sinif, bool $aciklamaZorunlu): array
{
    $h = [];
    $pb = (string)($g['para_birimi'] ?? 'TRY');
    if (!in_array($pb, cari_para_birimleri(), true)) $h['para_birimi'] = 'Geçersiz para birimi.';
    $kurus = cari_tutar_coz($g['tutar'] ?? '');
    if ($kurus === null) $h['tutar'] = 'Geçerli bir tutar girin (> 0, en çok 2 ondalık).';
    $kur = cari_kur_coz($g['kur'] ?? '', $pb);
    if ($kur === false) $h['kur'] = $pb === 'TRY' ? 'TL için kur girilmez.' : 'Geçerli bir kur girin (en çok 6 ondalık).';
    $tarih = (string)($g['tarih'] ?? '');
    if (!cari_tarih_gecerli($tarih)) $h['tarih'] = 'Geçerli bir tarih girin.';
    $ac = trim((string)($g['aciklama'] ?? ''));
    if ($aciklamaZorunlu && mb_strlen($ac) < 3) $h['aciklama'] = 'Açıklama en az 3 karakter olmalı.';
    elseif (mb_strlen($ac) > 500) $h['aciklama'] = 'Açıklama en çok 500 karakter.';
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik)) $h['istek_anahtari'] = 'Geçersiz istek anahtarı.';
    if ($h) return [null, $h];
    return [['pb' => $pb, 'kurus' => $kurus, 'kur' => $kur, 'tarih' => $tarih, 'aciklama' => $ac, 'ik' => $ik === '' ? null : $ik], []];
}

/**
 * Virman HARİÇ tek satırlık hareket (tahsilat/ödeme/açılış/dekont).
 * $kaynak: başka bir modülün (ör. stok fişi) kendi çekirdeğinden verdiği bağ ['modul' => ^[a-z_]{2,30}$, 'id' => int > 0].
 *   YALNIZ çağıran çekirdek verir; $g'den (POST) kaynak_modul/kaynak_id OKUNMAZ — cari_kaydet.php bu parametreyi hiç geçmez.
 *   Kaynaklı satır cari ekranından iptal edilemez (cari_hareket_iptal → 409 kaynakli); kaynağı cari_kaynakli_iptal ile iptal eder.
 * @return array{ok:bool,hata:?string,id:?int,belge_no?:string,tekrar?:bool,alanlar?:array}
 */
function cari_hareket_ekle(PDO $pdo, array $g, int $uid, ?array $kaynak = null): array
{
    $kMod = null; $kId = null;
    if ($kaynak !== null) {
        $kMod = (string)($kaynak['modul'] ?? '');
        $kId = (int)($kaynak['id'] ?? 0);
        if (!preg_match('/^[a-z_]{2,30}$/', $kMod) || $kId <= 0) return cari_hata('Geçersiz kaynak bağı.');
    }
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = cari_istek_bul($pdo, $ik)) !== null) {
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
    }
    $tur = (string)($g['tur'] ?? '');
    $bilgi = cari_tur_bilgi($tur);
    if ($bilgi === null || $tur === 'virman') return cari_hata('Geçersiz hareket türü.', ['alanlar' => ['tur' => 'Geçersiz hareket türü.']]);

    $yon = $bilgi['yon'];
    if ($yon === 'sec') {
        $yon = (string)($g['yon'] ?? '');
        if (!in_array($yon, ['B', 'A'], true)) return cari_hata('Açılış fişinde borç/alacak yönü seçin.', ['alanlar' => ['yon' => 'Yön seçin.']]);
    }
    $cariId = (int)($g['cari_id'] ?? 0);
    $kart = $cariId > 0 ? cari_kart_oku($pdo, $cariId) : null;
    if ($kart === null) return cari_hata('Cari seçin.', ['alanlar' => ['cari_id' => 'Cari seçin.']]);
    if ((int)$kart['aktif'] !== 1) return cari_hata('Pasif karta yeni hareket girilemez.', ['alanlar' => ['cari_id' => 'Pasif cari.']]);

    [$o, $h] = cari_hareket_ortak($g, $bilgi['sinif'], $bilgi['sinif'] === 'dekont' || $tur === 'acilis');
    $evrak = trim((string)($g['evrak_no'] ?? ''));
    if (mb_strlen($evrak) > 60) $h['evrak_no'] = 'Evrak no en çok 60 karakter.';
    $odeme = null;
    if ($bilgi['sinif'] === 'fis') {
        $os = trim((string)($g['odeme_sekli'] ?? ''));
        if ($os !== '') {
            $st = $pdo->prepare("SELECT ad FROM cari_tanimlar WHERE tur = 'odeme_sekli' AND aktif = 1 AND ad = ?");
            $st->execute([$os]);
            $ad = $st->fetchColumn();
            if ($ad === false) $h['odeme_sekli'] = 'Geçersiz ödeme şekli.'; else $odeme = (string)$ad;
        }
    }
    if ($h || $o === null) return cari_hata((string)reset($h), ['alanlar' => $h]);

    $depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $simdi = date('Y-m-d H:i:s');
    try {
        $id = (int)cari_tx($pdo, function () use ($pdo, $o, $tur, $yon, $cariId, $evrak, $odeme, $depo, $uid, $simdi, $kMod, $kId): int {
            $pdo->prepare('INSERT INTO cari_hareketler (cari_id, tarih, tur, yon, tutar, para_birimi, kur, belge_no, evrak_no, odeme_sekli, aciklama, depo, kaynak_modul, kaynak_id, istek_anahtari, durum, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cariId, $o['tarih'], $tur, $yon, number_format($o['kurus'] / 100, 2, '.', ''), $o['pb'], $o['kur'], '', $evrak === '' ? null : $evrak, $odeme, $o['aciklama'], $depo, $kMod, $kId, $o['ik'], 'gecerli', $simdi, $uid]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE cari_hareketler SET belge_no = ? WHERE id = ?')->execute([cari_belge_no($tur, $o['tarih'], $id), $id]);
            return $id;
        });
    } catch (PDOException $e) {
        if ($o['ik'] !== null && ($var = cari_istek_bul($pdo, $o['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
        }
        error_log('[cari_hareket_ekle] ' . $e->getMessage());
        return cari_hata('Hareket kaydedilemedi.');
    }
    $belge = cari_belge_no($tur, $o['tarih'], $id);
    audit_log_event('create', 'cari', $id, null, ['cari_id' => $cariId, 'tur' => $tur, 'yon' => $yon, 'tutar' => $o['kurus'] / 100, 'pb' => $o['pb'], 'belge_no' => $belge] + ($kMod !== null ? ['kaynak_modul' => $kMod, 'kaynak_id' => $kId] : []));
    return ['ok' => true, 'hata' => null, 'id' => $id, 'belge_no' => $belge, 'tekrar' => false];
}

/** İki satır: kaynak A, hedef B; ortak belge_no, bagli_id çapraz; tek transaction. g: kaynak_id, hedef_id, tarih, tutar, para_birimi, kur?, aciklama, istek_anahtari? */
function cari_virman_ekle(PDO $pdo, array $g, int $uid): array
{
    $ik = trim((string)($g['istek_anahtari'] ?? ''));
    if ($ik !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ik) && ($var = cari_istek_bul($pdo, $ik)) !== null) {
        return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
    }
    $kid = (int)($g['kaynak_id'] ?? 0); $hid = (int)($g['hedef_id'] ?? 0);
    if ($kid <= 0 || $hid <= 0) return cari_hata('Kaynak ve hedef cariyi seçin.', ['alanlar' => ['kaynak_id' => 'Seçin.', 'hedef_id' => 'Seçin.']]);
    if ($kid === $hid) return cari_hata('Kaynak ve hedef cari aynı olamaz.', ['alanlar' => ['hedef_id' => 'Kaynaktan farklı olmalı.']]);
    $kk = cari_kart_oku($pdo, $kid); $hk = cari_kart_oku($pdo, $hid);
    if ($kk === null || $hk === null) return cari_hata('Cari kart bulunamadı.');
    if ((int)$kk['aktif'] !== 1 || (int)$hk['aktif'] !== 1) return cari_hata('Pasif karta yeni hareket girilemez.');
    [$o, $h] = cari_hareket_ortak($g, 'dekont', true);
    if ($o === null) return cari_hata((string)reset($h), ['alanlar' => $h]);

    $depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
    $simdi = date('Y-m-d H:i:s');
    $tutar = number_format($o['kurus'] / 100, 2, '.', '');
    $sql = 'INSERT INTO cari_hareketler (cari_id, tarih, tur, yon, tutar, para_birimi, kur, belge_no, aciklama, bagli_id, depo, istek_anahtari, durum, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    try {
        [$id1, $id2, $belge] = cari_tx($pdo, function () use ($pdo, $sql, $kid, $hid, $o, $tutar, $depo, $simdi, $uid): array {
            $ins = $pdo->prepare($sql);
            $ins->execute([$kid, $o['tarih'], 'virman', 'A', $tutar, $o['pb'], $o['kur'], '', $o['aciklama'], null, $depo, $o['ik'], 'gecerli', $simdi, $uid]);
            $id1 = (int)$pdo->lastInsertId();
            $belge = cari_belge_no('virman', $o['tarih'], $id1);
            $ins->execute([$hid, $o['tarih'], 'virman', 'B', $tutar, $o['pb'], $o['kur'], $belge, $o['aciklama'], $id1, $depo, null, 'gecerli', $simdi, $uid]);
            $id2 = (int)$pdo->lastInsertId();
            $up = $pdo->prepare('UPDATE cari_hareketler SET belge_no = ?, bagli_id = ? WHERE id = ?');
            $up->execute([$belge, $id2, $id1]);
            return [$id1, $id2, $belge];
        });
    } catch (PDOException $e) {
        if ($o['ik'] !== null && ($var = cari_istek_bul($pdo, $o['ik'])) !== null) {
            return ['ok' => true, 'hata' => null, 'id' => (int)$var['id'], 'belge_no' => (string)$var['belge_no'], 'tekrar' => true];
        }
        error_log('[cari_virman_ekle] ' . $e->getMessage());
        return cari_hata('Virman kaydedilemedi.');
    }
    audit_log_event('create', 'cari', $id1, null, ['tur' => 'virman', 'kaynak_id' => $kid, 'hedef_id' => $hid, 'satirlar' => [$id1, $id2], 'tutar' => $o['kurus'] / 100, 'pb' => $o['pb'], 'belge_no' => $belge]);
    return ['ok' => true, 'hata' => null, 'id' => $id1, 'id2' => $id2, 'belge_no' => $belge, 'tekrar' => false];
}

/** İptal (düzeltme = iptal + yeniden gir). Virmanda bağlı satır birlikte iptal edilir. */
function cari_hareket_iptal(PDO $pdo, int $id, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (mb_strlen($neden) < 3) return cari_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu.']]);
    if (mb_strlen($neden) > 500) return cari_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]);
    $r = cari_hareket_oku($pdo, $id);
    if ($r === null) return cari_hata('Hareket bulunamadı.');
    if ($r['durum'] === 'iptal') return cari_hata('Hareket zaten iptal edilmiş.', ['kod' => 'zaten_iptal']);
    if (!empty($r['kaynak_modul'])) {
        return cari_hata($r['kaynak_modul'] === 'stok' ? 'Bu hareket bir stok fişine bağlı; stok fişinden iptal edin.' : 'Bu hareketi kaynak modülden iptal edin.', ['kod' => 'kaynakli']);
    }

    $idler = [$id];
    if ($r['tur'] === 'virman' && !empty($r['bagli_id'])) {
        $b = cari_hareket_oku($pdo, (int)$r['bagli_id']);
        if ($b !== null && $b['durum'] !== 'iptal') $idler[] = (int)$b['id'];
    }
    $simdi = date('Y-m-d H:i:s');
    cari_tx($pdo, function () use ($pdo, $idler, $simdi, $uid, $neden): void {
        $up = $pdo->prepare("UPDATE cari_hareketler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE id = ? AND durum = 'gecerli'");
        foreach ($idler as $i) $up->execute([$simdi, $uid, $neden, $i]);
    });
    audit_log_event('cancel', 'cari', $id, ['durum' => 'gecerli', 'belge_no' => $r['belge_no']], ['durum' => 'iptal', 'neden' => $neden, 'satirlar' => $idler]);
    return ['ok' => true, 'hata' => null, 'id' => $id, 'iptal_idler' => $idler];
}

/**
 * Kaynak modülün (ör. stok fişi iptali) kendi çekirdeğinden çağırdığı iptal: o kaynağa bağlı TÜM geçerli satırları iptal eder.
 * Yetki KONTROL ETMEZ (çağıran uç denetler); idempotent (iptal edilecek satır yoksa ok + boş liste). Dış transaction varsa onun içinde çalışır.
 * @return array{ok:bool,hata:?string,id:?int,iptal_idler:list<int>}
 */
function cari_kaynakli_iptal(PDO $pdo, string $modul, int $kaynakId, string $neden, int $uid): array
{
    $neden = trim($neden);
    if (!preg_match('/^[a-z_]{2,30}$/', $modul) || $kaynakId <= 0) return cari_hata('Geçersiz kaynak bağı.') + ['iptal_idler' => []];
    if (mb_strlen($neden) < 3) return cari_hata('İptal nedeni en az 3 karakter olmalı.', ['alanlar' => ['neden' => 'Neden zorunlu.']]) + ['iptal_idler' => []];
    if (mb_strlen($neden) > 500) return cari_hata('İptal nedeni en çok 500 karakter.', ['alanlar' => ['neden' => 'Çok uzun.']]) + ['iptal_idler' => []];
    $st = $pdo->prepare("SELECT id, belge_no FROM cari_hareketler WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'gecerli' ORDER BY id");
    $st->execute([$modul, $kaynakId]);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$satirlar) return ['ok' => true, 'hata' => null, 'id' => null, 'iptal_idler' => []];
    $simdi = date('Y-m-d H:i:s');
    cari_tx($pdo, function () use ($pdo, $modul, $kaynakId, $simdi, $uid, $neden): void {
        $pdo->prepare("UPDATE cari_hareketler SET durum = 'iptal', iptal_at = ?, iptal_by = ?, iptal_neden = ? WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'gecerli'")
            ->execute([$simdi, $uid, $neden, $modul, $kaynakId]);
    });
    $idler = array_map(static fn(array $r): int => (int)$r['id'], $satirlar);
    audit_log_event('cancel', 'cari', $idler[0], ['durum' => 'gecerli', 'belge_no' => $satirlar[0]['belge_no']], ['durum' => 'iptal', 'neden' => $neden, 'satirlar' => $idler, 'kaynak_modul' => $modul, 'kaynak_id' => $kaynakId]);
    return ['ok' => true, 'hata' => null, 'id' => $idler[0], 'iptal_idler' => $idler];
}

/** Filtre WHERE (pozisyonel). */
function cari_hareket_where(array $f, string $durum): array
{
    $w = ['1=1']; $p = [];
    $turSiniflar = ['fis' => [], 'dekont' => []];
    foreach (cari_hareket_turleri() as $t => $b) $turSiniflar[$b['sinif']][] = $t;
    $sinif = (string)($f['sinif'] ?? '');
    if (isset($turSiniflar[$sinif])) { $w[] = 'h.tur IN (' . implode(',', array_fill(0, count($turSiniflar[$sinif]), '?')) . ')'; array_push($p, ...$turSiniflar[$sinif]); }
    if (!empty($f['tur'])) { $w[] = 'h.tur = ?'; $p[] = (string)$f['tur']; }
    if (!empty($f['cari_id'])) { $w[] = 'h.cari_id = ?'; $p[] = (int)$f['cari_id']; }
    $cq = trim((string)($f['cari_q'] ?? ''));
    if ($cq !== '') { $w[] = '(k.unvan LIKE ? OR k.kod LIKE ?)'; array_push($p, '%' . $cq . '%', '%' . $cq . '%'); }
    if (!empty($f['virman_tek'])) $w[] = "NOT (h.tur = 'virman' AND h.yon = 'B')";   // virman çifti listede TEK satır (kaynak/A satırı)
    if (!empty($f['pb'])) { $w[] = 'h.para_birimi = ?'; $p[] = (string)$f['pb']; }
    if (!empty($f['bas'])) { $w[] = 'h.tarih >= ?'; $p[] = (string)$f['bas']; }
    if (!empty($f['bit'])) { $w[] = 'h.tarih <= ?'; $p[] = (string)$f['bit']; }
    if ($durum === 'gecerli' || $durum === 'iptal') { $w[] = 'h.durum = ?'; $p[] = $durum; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $w[] = '(h.belge_no LIKE ? OR h.evrak_no LIKE ? OR h.aciklama LIKE ? OR k.unvan LIKE ?)';
        $l = '%' . $q . '%';
        array_push($p, $l, $l, $l, $l);
    }
    return [implode(' AND ', $w), $p];
}

/** @return array{satirlar:list<array>,toplam_satir:int,ozet:array<string,array<string,array{adet:int,kurus:int}>>} */
function cari_hareket_listesi(PDO $pdo, array $f, int $limit = 100, int $offset = 0): array
{
    $durum = (string)($f['durum'] ?? 'gecerli');
    if (!in_array($durum, ['gecerli', 'iptal', 'tumu'], true)) $durum = 'gecerli';
    $join = 'FROM cari_hareketler h LEFT JOIN cari_kartlar k ON k.id = h.cari_id';
    [$w, $p] = cari_hareket_where($f, $durum);

    $st = $pdo->prepare("SELECT COUNT(*) $join WHERE $w");
    $st->execute($p);
    $toplam = (int)$st->fetchColumn();

    $limit = max(1, min(1000, $limit)); $offset = max(0, $offset);
    $st = $pdo->prepare("SELECT h.*, k.unvan, k.kod AS cari_kod, bk.unvan AS bagli_cari_unvan, b.cari_id AS bagli_cari_id
        $join LEFT JOIN cari_hareketler b ON b.id = h.bagli_id LEFT JOIN cari_kartlar bk ON bk.id = b.cari_id
        WHERE $w ORDER BY h.tarih DESC, h.id DESC LIMIT $limit OFFSET $offset");
    $st->execute($p);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);

    [$w2, $p2] = cari_hareket_where($f, 'gecerli');
    $st = $pdo->prepare("SELECT h.para_birimi AS pb, h.tur, COUNT(*) AS adet, SUM(h.tutar) AS toplam $join WHERE $w2 GROUP BY h.para_birimi, h.tur");
    $st->execute($p2);
    $ozet = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ozet[(string)$r['pb']][(string)$r['tur']] = ['adet' => (int)$r['adet'], 'kurus' => cari_kurus($r['toplam'])];
    }
    return ['satirlar' => $satirlar, 'toplam_satir' => $toplam, 'ozet' => $ozet];
}

// ── Bakiye / ekstre ─────────────────────────────────────────────────────────

/** @return array<string,array{borc:int,alacak:int,bakiye:int}>  yalnız geçerli satırlar; bitiş dahil */
function cari_bakiye(PDO $pdo, int $cari_id, ?string $bitis = null): array
{
    $sql = "SELECT para_birimi, SUM(CASE WHEN yon = 'B' THEN tutar ELSE 0 END) AS b, SUM(CASE WHEN yon = 'A' THEN tutar ELSE 0 END) AS a
            FROM cari_hareketler WHERE cari_id = ? AND durum = 'gecerli'";
    $p = [$cari_id];
    if ($bitis !== null && $bitis !== '') { $sql .= ' AND tarih <= ?'; $p[] = $bitis; }
    $st = $pdo->prepare($sql . ' GROUP BY para_birimi');
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = cari_kurus($r['b']); $a = cari_kurus($r['a']);
        $out[(string)$r['para_birimi']] = ['borc' => $b, 'alacak' => $a, 'bakiye' => $b - $a];
    }
    return $out;
}

/** Kartın (iptaller dahil) hareket satırı sayısı — "Sil" düğmesinin etkinliği için. */
function cari_kart_hareket_say(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM cari_hareketler WHERE cari_id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/** Kartta hareketi (iptal dahil) olan para birimleri (ekstre segmenti). @return list<string> */
function cari_kart_pbler(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT DISTINCT para_birimi FROM cari_hareketler WHERE cari_id = ? ORDER BY para_birimi');
    $st->execute([$id]);
    return array_keys(cari_pb_sirala(array_fill_keys(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)), 1)));
}

/** @param list<int> $ids @return array<int,array<string,int>> id → pb → bakiye */
function cari_bakiyeler_toplu(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $st = $pdo->prepare("SELECT cari_id, para_birimi, SUM(CASE WHEN yon = 'B' THEN tutar ELSE 0 END) AS b, SUM(CASE WHEN yon = 'A' THEN tutar ELSE 0 END) AS a
        FROM cari_hareketler WHERE durum = 'gecerli' AND cari_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY cari_id, para_birimi');
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['cari_id']][(string)$r['para_birimi']] = cari_kurus($r['b']) - cari_kurus($r['a']);
    return $out;
}

/**
 * Yalnız AKTİF kartlar. pb → borclu_toplam (pozitif bakiyelerin toplamı), alacakli_toplam (negatiflerin mutlak toplamı),
 * kart_sayisi (o para biriminde bakiyesi sıfır olmayan kart).
 * @return array<string,array{borclu_toplam:int,alacakli_toplam:int,kart_sayisi:int}>
 */
function cari_genel_ozet(PDO $pdo): array
{
    $st = $pdo->query("SELECT h.cari_id, h.para_birimi, SUM(CASE WHEN h.yon = 'B' THEN h.tutar ELSE 0 END) AS b, SUM(CASE WHEN h.yon = 'A' THEN h.tutar ELSE 0 END) AS a
        FROM cari_hareketler h JOIN cari_kartlar k ON k.id = h.cari_id WHERE h.durum = 'gecerli' AND k.aktif = 1 GROUP BY h.cari_id, h.para_birimi");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $net = cari_kurus($r['b']) - cari_kurus($r['a']);
        $pb = (string)$r['para_birimi'];
        $out[$pb] ??= ['borclu_toplam' => 0, 'alacakli_toplam' => 0, 'kart_sayisi' => 0];
        if ($net > 0) $out[$pb]['borclu_toplam'] += $net;
        elseif ($net < 0) $out[$pb]['alacakli_toplam'] += -$net;
        if ($net !== 0) $out[$pb]['kart_sayisi']++;
    }
    return $out;
}

/**
 * Ekstre (para birimi başına ayrı). f: bas, bit, pb, tur, iptaller_gorunsun.
 * `tur` filtresi yalnız GÖSTERİLEN satırları süzer; devir/yürüyen/dönem toplamları tüm geçerli satırlardan hesaplanır.
 * İptal satırları (gösterilirse) yürüyen bakiyeye ve toplamlara girmez.
 * @return array<string,array{devir:int,satirlar:list<array>,donem_borc:int,donem_alacak:int,kapanis:int}>
 */
function cari_ekstre(PDO $pdo, int $cari_id, array $f): array
{
    $bas = (string)($f['bas'] ?? ''); $bit = (string)($f['bit'] ?? '');
    $w = 'h.cari_id = ?'; $p = [$cari_id];
    if (!empty($f['pb'])) { $w .= ' AND h.para_birimi = ?'; $p[] = (string)$f['pb']; }
    if ($bit !== '') { $w .= ' AND h.tarih <= ?'; $p[] = $bit; }
    if (empty($f['iptaller_gorunsun'])) $w .= " AND h.durum = 'gecerli'";
    $st = $pdo->prepare("SELECT h.*, bk.unvan AS bagli_cari_unvan, b.cari_id AS bagli_cari_id FROM cari_hareketler h
        LEFT JOIN cari_hareketler b ON b.id = h.bagli_id LEFT JOIN cari_kartlar bk ON bk.id = b.cari_id
        WHERE $w ORDER BY h.para_birimi, h.tarih, h.id");
    $st->execute($p);
    $turFiltre = (string)($f['tur'] ?? '');
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pb = (string)$r['para_birimi'];
        $out[$pb] ??= ['devir' => 0, 'satirlar' => [], 'donem_borc' => 0, 'donem_alacak' => 0, 'kapanis' => 0, '_yur' => 0];
        $gecerli = $r['durum'] === 'gecerli';
        $kurus = cari_kurus($r['tutar']);
        $borc = $r['yon'] === 'B' ? $kurus : 0;
        $alacak = $r['yon'] === 'A' ? $kurus : 0;
        if ($bas !== '' && (string)$r['tarih'] < $bas) {          // dönem öncesi: yalnız devire girer
            if ($gecerli) { $out[$pb]['devir'] += $borc - $alacak; $out[$pb]['_yur'] += $borc - $alacak; }
            continue;
        }
        if ($gecerli) {
            $out[$pb]['_yur'] += $borc - $alacak;
            $out[$pb]['donem_borc'] += $borc;
            $out[$pb]['donem_alacak'] += $alacak;
        }
        if ($turFiltre !== '' && $r['tur'] !== $turFiltre) continue;
        $kur = $r['kur'] !== null && $r['kur'] !== '' ? (string)$r['kur'] : null;
        $out[$pb]['satirlar'][] = [
            'id' => (int)$r['id'], 'tarih' => (string)$r['tarih'], 'belge_no' => (string)$r['belge_no'],
            'tur' => (string)$r['tur'], 'tur_etiket' => cari_tur_bilgi((string)$r['tur'])['etiket'] ?? (string)$r['tur'],
            'evrak_no' => $r['evrak_no'], 'aciklama' => (string)$r['aciklama'], 'odeme_sekli' => $r['odeme_sekli'],
            'borc' => $borc, 'alacak' => $alacak, 'yuruyen' => $gecerli ? $out[$pb]['_yur'] : null,
            'kur' => $kur, 'tl_karsilik' => ($kur !== null && $pb !== 'TRY') ? (int)round($kurus * (float)$kur) : null,
            'durum' => (string)$r['durum'], 'iptal_neden' => $r['iptal_neden'], 'bagli_cari_unvan' => $r['bagli_cari_unvan'],
            'bagli_cari_id' => $r['bagli_cari_id'] !== null ? (int)$r['bagli_cari_id'] : null, 'kaynak_modul' => $r['kaynak_modul'], 'yon' => (string)$r['yon'],
        ];
    }
    foreach ($out as $pb => $x) { $out[$pb]['kapanis'] = $x['_yur']; unset($out[$pb]['_yur']); }
    return cari_pb_sirala($out);
}

// ── Tanım ───────────────────────────────────────────────────────────────────

/** Kullanım sayısıyla (grup: kart sayısı; ödeme şekli: hareket sayısı). @return list<array> */
function cari_tanim_listesi(PDO $pdo, string $tur, bool $yalnizAktif = false): array
{
    if (!isset(cari_tanim_turleri()[$tur])) return [];
    $kul = $tur === 'grup'
        ? '(SELECT COUNT(*) FROM cari_kartlar k WHERE k.grup_id = t.id)'
        : '(SELECT COUNT(*) FROM cari_hareketler h WHERE h.odeme_sekli = t.ad)';
    $st = $pdo->prepare("SELECT t.*, $kul AS kullanim FROM cari_tanimlar t WHERE t.tur = ?" . ($yalnizAktif ? ' AND t.aktif = 1' : '') . ' ORDER BY t.sira, t.ad, t.id');
    $st->execute([$tur]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cari_tanim_kaydet(PDO $pdo, string $tur, string $ad, int $sira, int $uid, ?int $id = null): array
{
    if (!isset(cari_tanim_turleri()[$tur])) return cari_hata('Geçersiz tanım türü.');
    $ad = trim($ad);
    if ($ad === '' || mb_strlen($ad) > 100) return cari_hata('Ad 1-100 karakter olmalı.', ['alanlar' => ['ad' => 'Ad 1-100 karakter olmalı.']]);
    $st = $pdo->prepare('SELECT id, ad FROM cari_tanimlar WHERE tur = ?');
    $st->execute([$tur]);
    $eski = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($id !== null && (int)$r['id'] === $id) $eski = $r;
        elseif (cari_fold((string)$r['ad']) === cari_fold($ad)) return cari_hata('Bu ad zaten tanımlı.', ['alanlar' => ['ad' => 'Bu ad zaten tanımlı.']]);
    }
    if ($id !== null && $eski === null) return cari_hata('Tanım bulunamadı.');
    if ($id === null) {
        $pdo->prepare('INSERT INTO cari_tanimlar (tur, ad, sira, aktif, created_at, created_by) VALUES (?, ?, ?, 1, ?, ?)')
            ->execute([$tur, $ad, $sira, date('Y-m-d H:i:s'), $uid]);
        $yid = (int)$pdo->lastInsertId();
        audit_log_event('create', 'cari', $yid, null, ['tanim' => $tur, 'ad' => $ad, 'sira' => $sira]);
        return ['ok' => true, 'hata' => null, 'id' => $yid];
    }
    $pdo->prepare('UPDATE cari_tanimlar SET ad = ?, sira = ? WHERE id = ?')->execute([$ad, $sira, $id]);
    audit_log_event('update', 'cari', $id, ['tanim' => $tur, 'ad' => $eski['ad']], ['tanim' => $tur, 'ad' => $ad, 'sira' => $sira]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

function cari_tanim_durum(PDO $pdo, int $id, bool $aktif, int $uid): array
{
    $st = $pdo->prepare('SELECT * FROM cari_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t === false) return cari_hata('Tanım bulunamadı.');
    $pdo->prepare('UPDATE cari_tanimlar SET aktif = ? WHERE id = ?')->execute([$aktif ? 1 : 0, $id]);
    audit_log_event($aktif ? 'activate' : 'deactivate', 'cari', $id, ['tanim' => $t['tur'], 'ad' => $t['ad'], 'aktif' => (int)$t['aktif']], ['aktif' => $aktif ? 1 : 0]);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

/** Grup bir kartta kullanılıyorsa silinemez (pasif önerilir); ödeme şekli serbest silinir (hareket adı anlık görüntü taşır). */
function cari_tanim_sil(PDO $pdo, int $id, int $uid): array
{
    $st = $pdo->prepare('SELECT * FROM cari_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if ($t === false) return cari_hata('Tanım bulunamadı.');
    if ($t['tur'] === 'grup') {
        $s = $pdo->prepare('SELECT COUNT(*) FROM cari_kartlar WHERE grup_id = ?');
        $s->execute([$id]);
        if ((int)$s->fetchColumn() > 0) return cari_hata('Bu grup carilerde kullanılıyor; silmek yerine pasifleştirin.', ['kod' => 'kullanimda']);
    }
    $pdo->prepare('DELETE FROM cari_tanimlar WHERE id = ?')->execute([$id]);
    audit_log_event('delete', 'cari', $id, ['tanim' => $t['tur'], 'ad' => $t['ad']], null);
    return ['ok' => true, 'hata' => null, 'id' => $id];
}

// ── İçe aktarım (material_definitions firma/tedarikci → kart; tek seferlik, idempotent) ──

/**
 * @return list<array{ad:string,kaynak_turler:list<string>,hedef_tip:string,durum:string,md_id:int}>
 *   durum: yeni | var_md (md_id ile kart var) | var_unvan (aynı unvanlı kart var)
 */
function cari_ice_aktar_onizle(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, name, type FROM material_definitions WHERE type IN ('firma', 'tedarikci') AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $grup = [];
    foreach ($rows as $r) {
        $ad = trim((string)$r['name']);
        if ($ad === '') continue;
        $k = cari_fold($ad);
        $grup[$k] ??= ['ad' => $ad, 'turler' => [], 'idler' => []];
        $grup[$k]['turler'][(string)$r['type']] = true;
        $grup[$k]['idler'][] = (int)$r['id'];
    }
    $mdVar = []; $unvanVar = [];
    foreach ($pdo->query('SELECT md_id, unvan FROM cari_kartlar')->fetchAll(PDO::FETCH_ASSOC) as $k) {
        if ($k['md_id'] !== null) $mdVar[(int)$k['md_id']] = true;
        $unvanVar[cari_fold((string)$k['unvan'])] = true;
    }
    $out = [];
    foreach ($grup as $k => $x) {
        $durum = 'yeni';
        foreach ($x['idler'] as $i) if (isset($mdVar[$i])) { $durum = 'var_md'; break; }
        if ($durum === 'yeni' && isset($unvanVar[$k])) $durum = 'var_unvan';
        $t = array_keys($x['turler']);
        $out[] = ['ad' => $x['ad'], 'kaynak_turler' => $t, 'hedef_tip' => count($t) > 1 ? 'ikisi' : ($t[0] === 'firma' ? 'musteri' : 'tedarikci'), 'durum' => $durum, 'md_id' => $x['idler'][0]];
    }
    return $out;
}

/** Yalnız `yeni` olanları ekler; tek transaction; tek özet audit. @return array{ok:bool,hata:?string,id:?int,eklenen:int,atlanan:int} */
function cari_ice_aktar(PDO $pdo, int $uid): array
{
    $onizle = cari_ice_aktar_onizle($pdo);
    $eklenen = 0; $atlanan = 0;
    cari_tx($pdo, function () use ($pdo, $onizle, $uid, &$eklenen, &$atlanan): void {
        $ins = $pdo->prepare('INSERT INTO cari_kartlar (kod, unvan, tip, para_birimi, aktif, md_id, created_at, created_by) VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
        foreach ($onizle as $o) {
            if ($o['durum'] !== 'yeni' || mb_strlen($o['ad']) > 200 || mb_strlen($o['ad']) < 2) { $atlanan++; continue; }
            $ins->execute([cari_kod_oner($pdo), $o['ad'], $o['hedef_tip'], 'TRY', $o['md_id'], date('Y-m-d H:i:s'), $uid]);
            $eklenen++;
        }
    });
    audit_log_event('cari_ice_aktar', 'cari', null, null, ['eklenen' => $eklenen, 'atlanan' => $atlanan]);
    return ['ok' => true, 'hata' => null, 'id' => null, 'eklenen' => $eklenen, 'atlanan' => $atlanan];
}

// ── Uç yardımcıları (cari_kaydet.php / cari_ekstre.php) ──────────────────────

/** `geri` yalnız yerel cari_*.php adresi (açık yönlendirme yok). */
function cari_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^cari_[a-z]+\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'cari_kartlari.php';
}

/** Sonucu JSON ya da PRG olarak gönderir. */
function cari_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && ($s['kod'] ?? '') === 'kaynakli') http_response_code(409);
        elseif (!$ok) http_response_code(422);
        echo json_encode([
            'ok' => $ok, 'id' => $s['id'] ?? null, 'belge_no' => $s['belge_no'] ?? null, 'hata' => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'),
            'alanlar' => (object)($s['alanlar'] ?? []), 'uyari' => $s['uyari'] ?? null, 'yonlendir' => $geri, 'tekrar' => !empty($s['tekrar']),
            'kod' => $s['kod'] ?? null, 'eklenen' => $s['eklenen'] ?? null, 'atlanan' => $s['atlanan'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok ? $okMesaj . (!empty($s['belge_no']) ? ' · ' . $s['belge_no'] : '') . (!empty($s['uyari']) ? ' — ' . $s['uyari'] : '') : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

function cari_csv_para(int $kurus): string { return number_format($kurus / 100, 2, ',', ''); }   // binlik ayraçsız — aktarımda güvenli
function cari_csv_ac(string $ad): mixed
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $ad . '"');
    header('Cache-Control: no-cache');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    return $o;
}
