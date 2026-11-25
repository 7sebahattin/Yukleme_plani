<?php
// =========================================================
// config/doviz_core.php — Döviz & Günlük Kurlar (TCMB) çekirdeği (include-only; yalnız fonksiyon bildirir)
//
// 2 tablo: doviz_tanimlar (para birimi kartları) · doviz_kurlar (günlük TCMB/manuel kur kayıtları).
// Şema YALNIZ migrate.php "Döviz & Kurlar" kartından kurulur (doviz_migrate; ALTER ve FK YOK).
// Yazan fonksiyonlar yetki KONTROL ETMEZ (uç: doviz_kaydet.php → doviz_kapi); hepsi audit_log_event(…, 'doviz', …) yazar.
// PDO'da yalnız pozisyonel `?`. XML çözümü libxml_use_internal_errors(true) + LIBXML_NONET ile güvenlidir.
// =========================================================
declare(strict_types=1);

// Doğrudan istek koruması (tam yol; basename DEĞİL)
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// ── Şema / Migrasyon ────────────────────────────────────────────────────────

/** İki tablonun DDL'i (ad → CREATE TABLE IF NOT EXISTS). SQLite çeviricisiyle uyumlu yazım. @return array<string,string> */
function doviz_tablolar(): array
{
    return [
        'doviz_tanimlar' => "CREATE TABLE IF NOT EXISTS `doviz_tanimlar` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `kod`                 VARCHAR(10)   NOT NULL,
  `ad`                  VARCHAR(100)  NOT NULL,
  `sembol`              VARCHAR(10)   NOT NULL DEFAULT '',
  `birim`               INT           NOT NULL DEFAULT 1,
  `ondalik`             TINYINT(1)    NOT NULL DEFAULT 4,
  `tcmb_aktif`          TINYINT(1)    NOT NULL DEFAULT 1,
  `aktif`               TINYINT(1)    NOT NULL DEFAULT 1,
  `varsayilan_kur_tipi` VARCHAR(20)   NOT NULL DEFAULT 'doviz_satis',
  `sira`                INT           NOT NULL DEFAULT 0,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`          INT           NULL DEFAULT NULL,
  `updated_at`          DATETIME      NULL DEFAULT NULL,
  `updated_by`          INT           NULL DEFAULT NULL,
  UNIQUE KEY `uq_dvzt_kod` (`kod`),
  KEY `idx_dvzt_aktif` (`aktif`, `sira`, `kod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",

        'doviz_kurlar' => "CREATE TABLE IF NOT EXISTS `doviz_kurlar` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `tarih`         DATE           NOT NULL,
  `kod`           VARCHAR(10)    NOT NULL,
  `birim`         INT            NOT NULL DEFAULT 1,
  `doviz_alis`    DECIMAL(18,6)  NULL DEFAULT NULL,
  `doviz_satis`   DECIMAL(18,6)  NULL DEFAULT NULL,
  `efektif_alis`  DECIMAL(18,6)  NULL DEFAULT NULL,
  `efektif_satis` DECIMAL(18,6)  NULL DEFAULT NULL,
  `capraz_usd`    DECIMAL(18,6)  NULL DEFAULT NULL,
  `capraz_diger`  DECIMAL(18,6)  NULL DEFAULT NULL,
  `bulten_no`     VARCHAR(30)    NULL DEFAULT NULL,
  `kaynak`        VARCHAR(20)    NOT NULL DEFAULT 'tcmb',
  `created_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`    INT            NULL DEFAULT NULL,
  `updated_at`    DATETIME       NULL DEFAULT NULL,
  `updated_by`    INT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_dvzk_tarih_kod` (`tarih`, `kod`),
  KEY `idx_dvzk_kod_tarih` (`kod`, `tarih`),
  KEY `idx_dvzk_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** İki tablo da var mı? İstek içi önbellek (yalnız olumlu sonuç; PDO başına). */
function doviz_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(doviz_tablolar()) as $t) {
            $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        }
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Eksik tablo adlarını döndürür. @return list<string> */
function doviz_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (array_keys(doviz_tablolar()) as $t) {
        try {
            $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        } catch (PDOException $e) {
            $eksik[] = $t;
        }
    }
    return $eksik;
}

/** Başlangıç para birimi tanımları (yalnız doviz_tanimlar YENİ kurulduysa tohumlanır). @return list<array<string,mixed>> */
function doviz_tanim_tohumu(): array
{
    return [
        ['kod' => 'TRY', 'ad' => 'Türk Lirası',          'sembol' => '₺',   'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 0, 'aktif' => 1, 'sira' => 10],
        ['kod' => 'USD', 'ad' => 'ABD Doları',           'sembol' => '$',   'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 1, 'sira' => 20],
        ['kod' => 'EUR', 'ad' => 'Euro',                 'sembol' => '€',   'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 1, 'sira' => 30],
        ['kod' => 'GBP', 'ad' => 'İngiliz Sterlini',     'sembol' => '£',   'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 1, 'sira' => 40],
        ['kod' => 'CHF', 'ad' => 'İsviçre Frangı',       'sembol' => 'CHF', 'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 0, 'sira' => 50],
        ['kod' => 'RUB', 'ad' => 'Rus Rublesi',          'sembol' => '₽',   'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 0, 'sira' => 60],
        ['kod' => 'AED', 'ad' => 'BAE Dirhemi',          'sembol' => 'AED', 'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 0, 'sira' => 70],
        ['kod' => 'SAR', 'ad' => 'Suudi Arabistan Riyali', 'sembol' => 'SAR', 'birim' => 1, 'ondalik' => 4, 'tcmb_aktif' => 1, 'aktif' => 0, 'sira' => 80],
    ];
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS (ALTER yok) + tanım tohumu (doviz_tanimlar YENİ kurulduysa). Yalnız migrate.php kartından çağrılır.
 * TEST KANCASI: $GLOBALS['_doviz_ddl_calistir'] = fn(PDO, string $ad, string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function doviz_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_doviz_ddl_calistir'] ?? null; // YALNIZ TEST KANCASI
    foreach (doviz_tablolar() as $ad => $ddl) {
        $var = false;
        try {
            $pdo->query("SELECT 1 FROM `$ad` LIMIT 0");
            $var = true;
        } catch (PDOException $e) {
            $var = false;
        }
        if ($var) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            if (is_callable($kanca)) {
                $kanca($pdo, $ad, $ddl);
            } else {
                $pdo->exec($ddl);
            }
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
            if ($ad === 'doviz_tanimlar' && (int)$pdo->query('SELECT COUNT(*) FROM `doviz_tanimlar`')->fetchColumn() === 0) {
                $ins = $pdo->prepare(
                    'INSERT INTO `doviz_tanimlar` (kod, ad, sembol, birim, ondalik, tcmb_aktif, aktif, varsayilan_kur_tipi, sira, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $simdi = date('Y-m-d H:i:s');
                foreach (doviz_tanim_tohumu() as $r) {
                    $ins->execute([
                        $r['kod'],
                        $r['ad'],
                        $r['sembol'],
                        $r['birim'],
                        $r['ondalik'],
                        $r['tcmb_aktif'],
                        $r['aktif'],
                        'doviz_satis',
                        $r['sira'],
                        $simdi,
                    ]);
                }
            }
        } catch (PDOException $e) {
            error_log('[doviz_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

// ── Yetki, HTTP ve Yardımcılar ──────────────────────────────────────────────

/** Kasa modülü yetki kapısını paylaşır (muhasebe_modul_can yoksa is_admin fallback). */
function doviz_can(string $eylem = 'read'): bool
{
    if (function_exists('muhasebe_modul_can')) {
        return muhasebe_modul_can('kasa', $eylem);
    }
    return function_exists('is_admin') ? is_admin() : false;
}

/** Yetkisiz isteği JSON'da 403, sayfada forbidden() ile keser. */
function doviz_kapi(string $eylem): void
{
    if (doviz_can($eylem)) return;
    if (doviz_json_istek()) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('forbidden')) {
        forbidden('Bu işlem için yetkiniz yok.');
    }
    http_response_code(403);
    echo 'Bu işlem için yetkiniz yok.';
    exit;
}

/** @return array<string,string> */
function doviz_kur_tipleri(): array
{
    return [
        'doviz_satis'   => 'Döviz Satış',
        'doviz_alis'    => 'Döviz Alış',
        'efektif_satis' => 'Efektif Satış',
        'efektif_alis'  => 'Efektif Alış',
    ];
}

/** Standart TCMB para birimleri kataloğu. @return array<string,array{ad:string,sembol:string,birim:int}> */
function doviz_tcmb_katalog(): array
{
    return [
        'USD' => ['ad' => 'ABD Doları',             'sembol' => '$',   'birim' => 1],
        'EUR' => ['ad' => 'Euro',                   'sembol' => '€',   'birim' => 1],
        'GBP' => ['ad' => 'İngiliz Sterlini',       'sembol' => '£',   'birim' => 1],
        'CHF' => ['ad' => 'İsviçre Frangı',         'sembol' => 'CHF', 'birim' => 1],
        'AUD' => ['ad' => 'Avustralya Doları',      'sembol' => 'A$',  'birim' => 1],
        'DKK' => ['ad' => 'Danimarka Kronu',        'sembol' => 'kr',  'birim' => 1],
        'SEK' => ['ad' => 'İsveç Kronu',            'sembol' => 'kr',  'birim' => 1],
        'CAD' => ['ad' => 'Kanada Doları',          'sembol' => 'C$',  'birim' => 1],
        'KWD' => ['ad' => 'Kuveyt Dinarı',          'sembol' => 'KWD', 'birim' => 1],
        'NOK' => ['ad' => 'Norveç Kronu',           'sembol' => 'kr',  'birim' => 1],
        'SAR' => ['ad' => 'Suudi Arabistan Riyali', 'sembol' => 'SAR', 'birim' => 1],
        'JPY' => ['ad' => 'Japon Yeni',             'sembol' => '¥',   'birim' => 100],
        'RON' => ['ad' => 'Rumen Leyi',             'sembol' => 'RON', 'birim' => 1],
        'RUB' => ['ad' => 'Rus Rublesi',            'sembol' => '₽',   'birim' => 1],
        'CNY' => ['ad' => 'Çin Yuanı',              'sembol' => '¥',   'birim' => 1],
        'PKR' => ['ad' => 'Pakistan Rupisi',        'sembol' => '₨',   'birim' => 1],
        'QAR' => ['ad' => 'Katar Riyali',           'sembol' => 'QAR', 'birim' => 1],
        'KRW' => ['ad' => 'Güney Kore Wonu',        'sembol' => '₩',   'birim' => 1],
        'AZN' => ['ad' => 'Azerbaycan Manatı',      'sembol' => '₼',   'birim' => 1],
        'AED' => ['ad' => 'BAE Dirhemi',            'sembol' => 'AED', 'birim' => 1],
        'KZT' => ['ad' => 'Kazak Tengesi',          'sembol' => '₸',   'birim' => 1],
        'XDR' => ['ad' => 'Özel Çekme Hakkı (SDR)', 'sembol' => 'SDR', 'birim' => 1],
    ];
}

/** TR veya noktalı ondalık sayıyı çözer; <= 0 veya geçersiz ise null, geçerliyse 6 ondalıklı noktalı metin döndürür. */
function doviz_sayi_coz(mixed $ham): ?string
{
    if ($ham === null || is_bool($ham) || is_array($ham) || is_object($ham)) return null;
    $s = trim((string)$ham);
    if ($s === '') return null;
    $s = str_replace([' ', "\xc2\xa0"], '', $s);

    $virgul = strrpos($s, ',');
    $nokta  = strrpos($s, '.');
    if ($virgul !== false && $nokta !== false) {
        if ($virgul > $nokta) {
            // 1.234,5678
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // 1,234.5678
            $s = str_replace(',', '', $s);
        }
    } elseif ($virgul !== false) {
        if (substr_count($s, ',') > 1) return null;
        $s = str_replace(',', '.', $s);
    } elseif (substr_count($s, '.') > 1) {
        return null;
    }

    if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) return null;
    $v = (float)$s;
    if (!is_finite($v) || $v <= 0.0) return null;
    return number_format($v, 6, '.', '');
}

/** Noktalı ondalık metni TR virgüllü biçime çevirir (boşsa "—"). */
function doviz_bicim(?string $deger, int $ondalik = 4): string
{
    if ($deger === null || trim($deger) === '' || !is_numeric($deger)) return '—';
    $ond = max(0, min(6, $ondalik));
    return number_format((float)$deger, $ond, ',', '.');
}

/** YYYY-MM-DD tarih geçerli mi? */
function doviz_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) return false;
    $y = (int)$m[1];
    $a = (int)$m[2];
    $g = (int)$m[3];
    return $y >= 2000 && $y <= 2100 && checkdate($a, $g, $y);
}

/** d.m.Y ya da Y-m-d → Y-m-d (geçersizse null). */
function doviz_tarih_coz(mixed $ham): ?string
{
    $t = trim(is_scalar($ham) ? (string)$ham : '');
    if ($t === '') return null;
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $t, $m)) {
        $t = "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return doviz_tarih_gecerli($t) ? $t : null;
}

function doviz_no_store(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/** Fetch / XHR JSON isteği mi? */
function doviz_json_istek(): bool
{
    $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($xrw === 'fetch' || $xrw === 'xmlhttprequest') return true;
    if ((string)($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1') return true;
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

/** Tablolar yoksa "şema kurulmamış" kartı basar ve çıkar (JSON'da 503). */
function doviz_sayfa_kapisi(PDO $pdo): void
{
    if (doviz_hazir($pdo)) return;
    if (doviz_json_istek()) {
        http_response_code(503);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => false,
            'hata'     => 'Döviz & Kurlar tabloları kurulmamış. Yönetici: Şema Migrasyon.',
            'eksikler' => ['doviz'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header')) render_header('Döviz & Günlük Kurlar');
    $esc = fn(string $s): string => function_exists('h') ? h($s) : htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<div class="container"><div class="card" style="margin:16px 0;padding:16px;">'
        . '<h2 style="margin-top:0;">Döviz &amp; Günlük Kurlar</h2>'
        . '<p>Döviz &amp; Kurlar tabloları (<code>doviz_tanimlar</code>, <code>doviz_kurlar</code>) henüz kurulmamış. Yönetici: Şema Migrasyon kartını çalıştırın.</p>'
        . '<p><a class="btn btn-primary" href="migrate.php#doviz">' . $esc('Şema Migrasyon › Döviz & Kurlar') . '</a></p>'
        . '</div></div>';
    if (function_exists('render_footer')) render_footer();
    exit;
}

/** `geri` yalnız yerel döviz sayfası (açık yönlendirme yok). */
function doviz_geri_guvenli(mixed $g): string
{
    $g = is_string($g) ? $g : '';
    return preg_match('/^(gunluk_kurlar|doviz_tanimlari)\.php(\?[^\r\n]*)?$/', $g) === 1 ? $g : 'gunluk_kurlar.php';
}

/** Sonucu JSON ya da PRG (flash + 303) olarak gönderir. */
function doviz_cevap(array $s, string $geri, string $okMesaj, bool $json): never
{
    $ok = !empty($s['ok']);
    if ($json) {
        if (!$ok && ($s['kod'] ?? '') === 'sunucu_hatasi') {
            http_response_code(500);
        } elseif (!$ok && ($s['kod'] ?? '') === 'kullanimda') {
            http_response_code(409);
        } elseif (!$ok) {
            http_response_code(422);
        }
        $payload = array_merge($s, [
            'ok'        => $ok,
            'hata'      => $ok ? null : (string)($s['hata'] ?? 'İşlem yapılamadı.'),
            'alanlar'   => (object)($s['alanlar'] ?? []),
            'yonlendir' => $geri,
            'mesaj'     => $ok ? $okMesaj : null,
        ]);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg = $ok ? $okMesaj : (string)($s['hata'] ?? 'İşlem yapılamadı.');
    if (function_exists('set_flash')) set_flash($ok ? 'success' : 'error', $msg);
    header('Location: ' . $geri, true, 303);
    exit;
}

/** İç denetim kaydı yardımcısı. */
function doviz_audit(string $eylem, ?int $kayitId, ?array $eski, ?array $yeni): void
{
    if (function_exists('audit_log_event')) {
        audit_log_event($eylem, 'doviz', $kayitId, $eski, $yeni);
    }
}

/** Kendi transaction'ı içinde çalıştırır; dış transaction varsa yalnız çağırır. */
function doviz_tx(PDO $pdo, callable $fn): mixed
{
    if ($pdo->inTransaction()) return $fn($pdo);
    $pdo->beginTransaction();
    try {
        $sonuc = $fn($pdo);
        $pdo->commit();
        return $sonuc;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ── TCMB XML İstemci ve Çözümleyici ─────────────────────────────────────────

/** TCMB XML URL'i: bugün veya gelecek/boş → today.xml; geçmiş → YYYYMM/DDMMYYYY.xml. */
/** Belirli bir tarih için doğrudan TCMB arşiv XML URL'si (today.xml CDN önbelleğini atlamak için). */
function doviz_tcmb_tarih_url(string $tarih): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tarih, $m)) {
        return 'https://www.tcmb.gov.tr/kurlar/today.xml';
    }
    return sprintf('https://www.tcmb.gov.tr/kurlar/%s%s/%s%s%s.xml', $m[1], $m[2], $m[3], $m[2], $m[1]);
}

function doviz_tcmb_url(?string $tarih = null): string
{
    $bugun = date('Y-m-d');
    if ($tarih === null || $tarih === '' || $tarih >= $bugun) {
        return 'https://www.tcmb.gov.tr/kurlar/today.xml';
    }
    return doviz_tcmb_tarih_url($tarih);
}

/**
 * HTTP GET istemcisi.
 * TEST KANCASI: $GLOBALS['_doviz_http_get'] = fn(string $url): array{ok:bool,status:int,body:string,hata:string}.
 * @return array{ok:bool,status:int,body:string,hata:string}
 */
function doviz_http_get(string $url): array
{
    $kanca = $GLOBALS['_doviz_http_get'] ?? null;
    if (is_callable($kanca)) {
        $r = $kanca($url);
        return [
            'ok'     => !empty($r['ok']),
            'status' => (int)($r['status'] ?? (!empty($r['ok']) ? 200 : 0)),
            'body'   => (string)($r['body'] ?? ''),
            'hata'   => (string)($r['hata'] ?? ''),
        ];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'hata' => 'cURL başlatılamadı.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'AsyaFresh-ERP/1.0 (+TCMB-Kur)',
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
        ]);
        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $err !== '') {
            return ['ok' => false, 'status' => $status, 'body' => '', 'hata' => $err !== '' ? $err : 'Bağlantı hatası.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string)$body, 'hata' => 'HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string)$body, 'hata' => ''];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 8,
            'ignore_errors' => true,
            'header'        => "User-Agent: AsyaFresh-ERP/1.0 (+TCMB-Kur)\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $hdr, $m)) {
                $status = (int)$m[1];
            }
        }
    }
    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'hata' => 'TCMB sunucusuna ulaşılamadı.'];
    }
    if ($status !== 0 && ($status < 200 || $status >= 300)) {
        return ['ok' => false, 'status' => $status, 'body' => (string)$body, 'hata' => 'HTTP ' . $status];
    }
    return ['ok' => true, 'status' => $status ?: 200, 'body' => (string)$body, 'hata' => ''];
}

/**
 * TCMB XML içeriğini güvenle (LIBXML_NONET) ayrıştırır.
 * @return array{ok:bool,tarih?:string,bulten_no?:string,kurlar?:list<array<string,mixed>>,hata?:string}
 */
function doviz_tcmb_xml_coz(string $xml): array
{
    $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
    if ($xml === '' || !str_starts_with($xml, '<')) {
        return ['ok' => false, 'hata' => 'Geçersiz TCMB XML yanıtı.'];
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    try {
        $root = new SimpleXMLElement($xml, LIBXML_NONET);
    } catch (Throwable $e) {
        return ['ok' => false, 'hata' => 'TCMB XML ayrıştırılamadı.'];
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    $tarihHam = trim((string)($root['Tarih'] ?? ''));
    $tarih    = doviz_tarih_coz($tarihHam);
    if ($tarih === null) {
        // MM/DD/YYYY Date özniteliği yedeği
        $dateAttr = trim((string)($root['Date'] ?? ''));
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateAttr, $m)) {
            $aday = "{$m[3]}-{$m[1]}-{$m[2]}";
            if (doviz_tarih_gecerli($aday)) $tarih = $aday;
        }
    }
    if ($tarih === null) {
        return ['ok' => false, 'hata' => 'TCMB XML içinde geçerli bülten tarihi bulunamadı.'];
    }

    $bultenNo = trim((string)($root['Bulten_No'] ?? ''));
    $kurlar   = [];
    foreach ($root->Currency as $c) {
        $kod = strtoupper(trim((string)($c['Kod'] ?? $c['CurrencyCode'] ?? '')));
        if ($kod === '' || !preg_match('/^[A-Z]{3,10}$/', $kod)) continue;

        $birim = max(1, min(10000, (int)trim((string)$c->Unit)));
        $da    = doviz_sayi_coz((string)$c->ForexBuying);
        $ds    = doviz_sayi_coz((string)$c->ForexSelling);
        $ea    = doviz_sayi_coz((string)$c->BanknoteBuying);
        $es    = doviz_sayi_coz((string)$c->BanknoteSelling);
        $cu    = doviz_sayi_coz((string)$c->CrossRateUSD);
        $cd    = doviz_sayi_coz((string)$c->CrossRateOther);

        if ($da === null && $ds === null && $ea === null && $es === null && $cu === null && $cd === null) {
            continue;
        }

        $kurlar[] = [
            'kod'           => $kod,
            'birim'         => $birim,
            'isim'          => trim((string)$c->Isim),
            'currency_name' => trim((string)$c->CurrencyName),
            'doviz_alis'    => $da,
            'doviz_satis'   => $ds,
            'efektif_alis'  => $ea,
            'efektif_satis' => $es,
            'capraz_usd'    => $cu,
            'capraz_diger'  => $cd,
        ];
    }

    if (!$kurlar) {
        return ['ok' => false, 'hata' => 'TCMB XML içinde kur kaydı bulunamadı.'];
    }

    return [
        'ok'        => true,
        'tarih'     => $tarih,
        'bulten_no' => $bultenNo,
        'kurlar'    => $kurlar,
    ];
}

/**
 * TCMB XML'ini çeker ve doviz_kurlar tablosuna yazar (MySQL + SQLite uyumlu SELECT + INSERT/UPDATE).
 * Tatil/hafta sonu 404 durumunda $geriAra=true ise en fazla 5 gün geriye bakar.
 * @return array<string,mixed>
 */
function doviz_tcmb_senkron(PDO $pdo, ?string $tarih = null, ?int $uid = null, bool $uzerineYaz = false, bool $geriAra = true): array
{
    $istenen = null;
    if ($tarih !== null && trim($tarih) !== '') {
        $istenen = doviz_tarih_coz($tarih);
        if ($istenen === null) {
            return ['ok' => false, 'hata' => 'Geçersiz tarih formatı.'];
        }
    }

    $bugun   = date('Y-m-d');
    $res     = null;
    $sonHata = 'TCMB kurları alınamadı.';

    if ($istenen === null || $istenen >= $bugun) {
        $res = doviz_http_get(doviz_tcmb_url(null));
        if (!$res['ok']) {
            // today.xml hata verirse doğrudan bugünün tarihli arşiv dosyasını dene
            $resArsiv = doviz_http_get(doviz_tcmb_tarih_url($bugun));
            if ($resArsiv['ok']) {
                $res = $resArsiv;
            } else {
                return ['ok' => false, 'hata' => 'TCMB bağlantısı başarısız: ' . ($res['hata'] ?: 'HTTP ' . $res['status'])];
            }
        }
    } else {
        $maxDeneme = $geriAra ? 6 : 1; // 0..5 gün geri
        $ts        = strtotime($istenen . ' 12:00:00');
        for ($i = 0; $i < $maxDeneme; $i++) {
            $aday = date('Y-m-d', $ts - ($i * 86400));
            $r    = doviz_http_get(doviz_tcmb_url($aday));
            if ($r['ok']) {
                $res = $r;
                break;
            }
            $sonHata = $r['hata'] !== '' ? $r['hata'] : ('HTTP ' . $r['status']);
            // 404 dışındaki ağ hatalarında yine de geri arama yalnız 404 ise anlamlıdır
            if ($r['status'] !== 404 && $r['status'] !== 0) {
                break;
            }
        }
        if ($res === null || !$res['ok']) {
            return ['ok' => false, 'hata' => 'TCMB bülteni bulunamadı (' . $sonHata . ').'];
        }
    }

    $parsed = doviz_tcmb_xml_coz($res['body']);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'hata' => (string)($parsed['hata'] ?? 'XML çözümlenemedi.')];
    }

    // Bugün istenip today.xml CDN önbelleğinden eski bülten döndüyse doğrudan bugünün tarihli XML'ini de kontrol et
    if (($istenen === null || $istenen >= $bugun) && (string)$parsed['tarih'] < $bugun) {
        $resBugun = doviz_http_get(doviz_tcmb_tarih_url($bugun));
        if ($resBugun['ok']) {
            $parsedBugun = doviz_tcmb_xml_coz($resBugun['body']);
            if (!empty($parsedBugun['ok']) && (string)$parsedBugun['tarih'] > (string)$parsed['tarih']) {
                $parsed = $parsedBugun;
            }
        }
    }

    $bultenTarih = (string)$parsed['tarih'];
    $bultenNo    = (string)$parsed['bulten_no'];
    $kurlar      = $parsed['kurlar'];
    $simdi       = date('Y-m-d H:i:s');
    $eklenen     = 0;
    $guncellenen = 0;
    $atlanan     = 0;

    doviz_tx($pdo, function (PDO $db) use (
        $bultenTarih, $bultenNo, $kurlar, $uid, $uzerineYaz, $simdi,
        &$eklenen, &$guncellenen, &$atlanan
    ): void {
        $sel = $db->prepare('SELECT id, kaynak FROM doviz_kurlar WHERE tarih = ? AND kod = ?');
        $ins = $db->prepare(
            'INSERT INTO doviz_kurlar (tarih, kod, birim, doviz_alis, doviz_satis, efektif_alis, efektif_satis, capraz_usd, capraz_diger, bulten_no, kaynak, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $upd = $db->prepare(
            'UPDATE doviz_kurlar
             SET birim = ?, doviz_alis = ?, doviz_satis = ?, efektif_alis = ?, efektif_satis = ?, capraz_usd = ?, capraz_diger = ?, bulten_no = ?, kaynak = ?, updated_at = ?, updated_by = ?
             WHERE id = ?'
        );

        foreach ($kurlar as $k) {
            $sel->execute([$bultenTarih, $k['kod']]);
            $mevcut = $sel->fetch(PDO::FETCH_ASSOC);
            if ($mevcut !== false) {
                if ((string)$mevcut['kaynak'] === 'manuel' && !$uzerineYaz) {
                    $atlanan++;
                    continue;
                }
                $upd->execute([
                    $k['birim'],
                    $k['doviz_alis'],
                    $k['doviz_satis'],
                    $k['efektif_alis'],
                    $k['efektif_satis'],
                    $k['capraz_usd'],
                    $k['capraz_diger'],
                    $bultenNo !== '' ? $bultenNo : null,
                    'tcmb',
                    $simdi,
                    $uid,
                    (int)$mevcut['id'],
                ]);
                $guncellenen++;
            } else {
                $ins->execute([
                    $bultenTarih,
                    $k['kod'],
                    $k['birim'],
                    $k['doviz_alis'],
                    $k['doviz_satis'],
                    $k['efektif_alis'],
                    $k['efektif_satis'],
                    $k['capraz_usd'],
                    $k['capraz_diger'],
                    $bultenNo !== '' ? $bultenNo : null,
                    'tcmb',
                    $simdi,
                    $uid,
                ]);
                $eklenen++;
            }
        }
    });

    doviz_audit('tcmb_senkron', null, null, [
        'tarih'       => $bultenTarih,
        'bulten_no'   => $bultenNo,
        'eklenen'     => $eklenen,
        'guncellenen' => $guncellenen,
        'atlanan'     => $atlanan,
        'toplam'      => count($kurlar),
    ]);

    return [
        'ok'          => true,
        'tarih'       => $bultenTarih,
        'bulten_no'   => $bultenNo,
        'eklenen'     => $eklenen,
        'guncellenen' => $guncellenen,
        'atlanan'     => $atlanan,
        'toplam'      => count($kurlar),
    ];
}

/**
 * Son N iş gününün (en fazla 15, hafta sonu hariç) eksik olanlarını TCMB'den çeker.
 * @return array<string,mixed>
 */
function doviz_tcmb_aralik_senkron(PDO $pdo, int $gunSayisi = 7, ?int $uid = null): array
{
    $hedef = max(1, min(15, $gunSayisi));
    $ts    = strtotime(date('Y-m-d') . ' 12:00:00');

    $isGunleri = [];
    for ($i = 0; $i < 30 && count($isGunleri) < $hedef; $i++) {
        $d = $ts - ($i * 86400);
        if ((int)date('N', $d) <= 5) {
            $isGunleri[] = date('Y-m-d', $d);
        }
    }

    $sel           = $pdo->prepare('SELECT 1 FROM doviz_kurlar WHERE tarih = ? LIMIT 1');
    $senkronGun    = 0;
    $mevcutGun     = 0;
    $atlananGun    = 0;
    $toplamEklenen = 0;
    $tarihler      = [];

    foreach ($isGunleri as $gun) {
        $sel->execute([$gun]);
        if ($sel->fetchColumn() !== false) {
            $mevcutGun++;
            continue;
        }
        $s = doviz_tcmb_senkron($pdo, $gun, $uid, false, false);
        if (!empty($s['ok'])) {
            $senkronGun++;
            $toplamEklenen += (int)($s['eklenen'] ?? 0);
            $tarihler[] = (string)$s['tarih'];
        } else {
            $atlananGun++;
        }
    }

    return [
        'ok'             => true,
        'istenen_gun'    => $hedef,
        'senkron_gun'    => $senkronGun,
        'mevcut_gun'     => $mevcutGun,
        'atlanan_gun'    => $atlananGun,
        'toplam_eklenen' => $toplamEklenen,
        'tarihler'       => $tarihler,
    ];
}

// ── Para Birimi Tanımları CRUD ──────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function doviz_tanim_listesi(PDO $pdo, bool $yalnizAktif = false): array
{
    $sql = 'SELECT t.*, (SELECT COUNT(*) FROM doviz_kurlar k WHERE k.kod = t.kod) AS kur_sayisi FROM doviz_tanimlar t';
    if ($yalnizAktif) $sql .= ' WHERE t.aktif = 1';
    $sql .= ' ORDER BY t.sira ASC, t.kod ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function doviz_tanim_oku(PDO $pdo, string $kod): ?array
{
    $kod = strtoupper(trim($kod));
    if ($kod === '') return null;
    $st = $pdo->prepare('SELECT * FROM doviz_tanimlar WHERE kod = ?');
    $st->execute([$kod]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

function doviz_tanim_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM doviz_tanimlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r === false ? null : $r;
}

/** Para birimi tanımı ekler veya günceller. */
function doviz_tanim_kaydet(PDO $pdo, array $g, ?int $uid): array
{
    $id   = (int)($g['id'] ?? 0);
    $eski = null;
    if ($id > 0) {
        $eski = doviz_tanim_bul($pdo, $id);
        if ($eski === null) return ['ok' => false, 'hata' => 'Para birimi tanımı bulunamadı.'];
    }

    $kod = strtoupper(trim((string)($g['kod'] ?? ($eski['kod'] ?? ''))));
    if ($eski !== null && (string)$eski['kod'] === 'TRY') {
        $kod = 'TRY';
    }
    if (!preg_match('/^[A-Z]{3,10}$/', $kod)) {
        return ['ok' => false, 'hata' => 'Para birimi kodu 3-10 büyük Latin harf olmalı (örn. USD).', 'alanlar' => ['kod' => 'Geçersiz kod.']];
    }

    $ad = trim((string)($g['ad'] ?? ''));
    if ($ad === '' || mb_strlen($ad) > 100) {
        return ['ok' => false, 'hata' => 'Para birimi adı 1-100 karakter olmalı.', 'alanlar' => ['ad' => 'Ad 1-100 karakter olmalı.']];
    }

    $sembol = trim((string)($g['sembol'] ?? ''));
    if (mb_strlen($sembol) > 10) {
        return ['ok' => false, 'hata' => 'Sembol en fazla 10 karakter olabilir.', 'alanlar' => ['sembol' => 'En fazla 10 karakter.']];
    }

    $birim   = (int)($g['birim'] ?? 1);
    $ondalik = (int)($g['ondalik'] ?? 4);
    if ($birim < 1 || $birim > 10000) {
        return ['ok' => false, 'hata' => 'Birim 1 ile 10000 arasında olmalı.', 'alanlar' => ['birim' => '1-10000 arası olmalı.']];
    }
    if ($ondalik < 2 || $ondalik > 6) {
        return ['ok' => false, 'hata' => 'Ondalık basamak 2 ile 6 arasında olmalı.', 'alanlar' => ['ondalik' => '2-6 arası olmalı.']];
    }

    $tip = trim((string)($g['varsayilan_kur_tipi'] ?? 'doviz_satis'));
    if (!isset(doviz_kur_tipleri()[$tip])) {
        return ['ok' => false, 'hata' => 'Geçersiz varsayılan kur tipi.', 'alanlar' => ['varsayilan_kur_tipi' => 'Geçersiz kur tipi.']];
    }

    $tcmbAktif = !empty($g['tcmb_aktif']) && (string)$g['tcmb_aktif'] !== '0' ? 1 : 0;
    $aktif     = !empty($g['aktif']) && (string)$g['aktif'] !== '0' ? 1 : 0;
    $sira      = (int)($g['sira'] ?? 0);

    if ($kod === 'TRY') {
        if ($aktif === 0) {
            return ['ok' => false, 'hata' => 'TRY (ana para birimi) pasifleştirilemez.'];
        }
        $tcmbAktif = 0;
    }

    // Benzersizlik kontrolü
    $st = $pdo->prepare('SELECT id FROM doviz_tanimlar WHERE kod = ?');
    $st->execute([$kod]);
    $cakisan = $st->fetchColumn();
    if ($cakisan !== false && (int)$cakisan !== $id) {
        return ['ok' => false, 'hata' => $kod . ' kodu zaten tanımlı.', 'kod' => 'kullanimda', 'alanlar' => ['kod' => 'Bu kod zaten tanımlı.']];
    }

    $simdi = date('Y-m-d H:i:s');
    if ($id <= 0) {
        $pdo->prepare(
            'INSERT INTO doviz_tanimlar (kod, ad, sembol, birim, ondalik, tcmb_aktif, aktif, varsayilan_kur_tipi, sira, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$kod, $ad, $sembol, $birim, $ondalik, $tcmbAktif, $aktif, $tip, $sira, $simdi, $uid]);
        $yid = (int)$pdo->lastInsertId();
        doviz_audit('create', $yid, null, ['kayit' => 'tanim', 'kod' => $kod, 'ad' => $ad, 'aktif' => $aktif]);
        return ['ok' => true, 'id' => $yid, 'kod' => $kod];
    }

    $pdo->prepare(
        'UPDATE doviz_tanimlar
         SET kod = ?, ad = ?, sembol = ?, birim = ?, ondalik = ?, tcmb_aktif = ?, aktif = ?, varsayilan_kur_tipi = ?, sira = ?, updated_at = ?, updated_by = ?
         WHERE id = ?'
    )->execute([$kod, $ad, $sembol, $birim, $ondalik, $tcmbAktif, $aktif, $tip, $sira, $simdi, $uid, $id]);

    doviz_audit('update', $id, ['kayit' => 'tanim', 'kod' => $eski['kod'], 'ad' => $eski['ad']], ['kayit' => 'tanim', 'kod' => $kod, 'ad' => $ad, 'aktif' => $aktif]);
    return ['ok' => true, 'id' => $id, 'kod' => $kod];
}

/** Para biriminin aktif veya tcmb_aktif alanını değiştirir. */
function doviz_tanim_durum(PDO $pdo, int $id, string $alan, int $deger, ?int $uid): array
{
    if (!in_array($alan, ['aktif', 'tcmb_aktif'], true)) {
        return ['ok' => false, 'hata' => 'Geçersiz durum alanı.'];
    }
    $t = doviz_tanim_bul($pdo, $id);
    if ($t === null) return ['ok' => false, 'hata' => 'Para birimi tanımı bulunamadı.'];

    $v = $deger ? 1 : 0;
    if ((string)$t['kod'] === 'TRY' && $alan === 'aktif' && $v === 0) {
        return ['ok' => false, 'hata' => 'TRY (ana para birimi) pasifleştirilemez.'];
    }
    if ((string)$t['kod'] === 'TRY' && $alan === 'tcmb_aktif' && $v === 1) {
        return ['ok' => false, 'hata' => 'TRY için TCMB kur çekimi aktif edilemez.'];
    }

    $sql = $alan === 'aktif'
        ? 'UPDATE doviz_tanimlar SET aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?'
        : 'UPDATE doviz_tanimlar SET tcmb_aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?';
    $pdo->prepare($sql)->execute([$v, date('Y-m-d H:i:s'), $uid, $id]);

    doviz_audit('update', $id, ['kayit' => 'tanim', 'kod' => $t['kod'], $alan => (int)$t[$alan]], ['kayit' => 'tanim', 'kod' => $t['kod'], $alan => $v]);
    return ['ok' => true, 'id' => $id];
}

/** Para birimi tanımını siler (TRY ve kur kaydı olanlar silinemez → 409 kullanimda). */
function doviz_tanim_sil(PDO $pdo, int $id, ?int $uid): array
{
    $t = doviz_tanim_bul($pdo, $id);
    if ($t === null) return ['ok' => false, 'hata' => 'Para birimi tanımı bulunamadı.'];
    if ((string)$t['kod'] === 'TRY') {
        return ['ok' => false, 'hata' => 'TRY (ana para birimi) silinemez.', 'kod' => 'kullanimda'];
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM doviz_kurlar WHERE kod = ?');
    $st->execute([$t['kod']]);
    if ((int)$st->fetchColumn() > 0) {
        return ['ok' => false, 'hata' => 'Bu para birimine ait kur kayıtları var; silmek yerine pasifleştirin.', 'kod' => 'kullanimda'];
    }
    $pdo->prepare('DELETE FROM doviz_tanimlar WHERE id = ?')->execute([$id]);
    doviz_audit('delete', $id, ['kayit' => 'tanim', 'kod' => $t['kod'], 'ad' => $t['ad']], null);
    return ['ok' => true, 'id' => $id];
}

// ── Günlük Kurlar CRUD ve Sorgular ──────────────────────────────────────────

/** Manuel kur ekler veya günceller (tarih + kod bazında upsert, kaynak='manuel'). */
function doviz_kur_kaydet(PDO $pdo, array $g, ?int $uid): array
{
    $tarih = doviz_tarih_coz($g['tarih'] ?? '');
    if ($tarih === null) {
        return ['ok' => false, 'hata' => 'Geçerli bir tarih giriniz.', 'alanlar' => ['tarih' => 'Geçersiz tarih.']];
    }

    $kod = strtoupper(trim((string)($g['kod'] ?? '')));
    if (!preg_match('/^[A-Z]{3,10}$/', $kod) || $kod === 'TRY') {
        return ['ok' => false, 'hata' => 'Geçerli bir döviz kodu seçiniz (TRY hariç).', 'alanlar' => ['kod' => 'Geçersiz kod.']];
    }

    $alanAdlari = ['doviz_alis', 'doviz_satis', 'efektif_alis', 'efektif_satis', 'capraz_usd', 'capraz_diger'];
    $degerler   = [];
    $hatalar    = [];
    foreach ($alanAdlari as $a) {
        $ham = trim((string)($g[$a] ?? ''));
        if ($ham === '') {
            $degerler[$a] = null;
        } else {
            $coz = doviz_sayi_coz($ham);
            if ($coz === null) {
                $hatalar[$a] = 'Pozitif bir sayı giriniz.';
            } else {
                $degerler[$a] = $coz;
            }
        }
    }
    if ($hatalar) {
        return ['ok' => false, 'hata' => 'Kur değerleri geçersiz.', 'alanlar' => $hatalar];
    }
    if (
        $degerler['doviz_alis'] === null &&
        $degerler['doviz_satis'] === null &&
        $degerler['efektif_alis'] === null &&
        $degerler['efektif_satis'] === null
    ) {
        return ['ok' => false, 'hata' => 'En az bir kur alanı (Döviz Alış/Satış veya Efektif Alış/Satış) girilmelidir.'];
    }

    $tanim    = doviz_tanim_oku($pdo, $kod);
    $birim    = isset($g['birim']) && (int)$g['birim'] > 0 ? (int)$g['birim'] : (int)($tanim['birim'] ?? 1);
    $birim    = max(1, min(10000, $birim));
    $bultenNo = trim((string)($g['bulten_no'] ?? ''));
    $bultenNo = $bultenNo !== '' ? mb_substr($bultenNo, 0, 30) : null;
    $simdi    = date('Y-m-d H:i:s');

    $st = $pdo->prepare('SELECT * FROM doviz_kurlar WHERE tarih = ? AND kod = ?');
    $st->execute([$tarih, $kod]);
    $mevcut = $st->fetch(PDO::FETCH_ASSOC);

    if ($mevcut !== false) {
        $id = (int)$mevcut['id'];
        $pdo->prepare(
            'UPDATE doviz_kurlar
             SET birim = ?, doviz_alis = ?, doviz_satis = ?, efektif_alis = ?, efektif_satis = ?, capraz_usd = ?, capraz_diger = ?, bulten_no = ?, kaynak = ?, updated_at = ?, updated_by = ?
             WHERE id = ?'
        )->execute([
            $birim,
            $degerler['doviz_alis'],
            $degerler['doviz_satis'],
            $degerler['efektif_alis'],
            $degerler['efektif_satis'],
            $degerler['capraz_usd'],
            $degerler['capraz_diger'],
            $bultenNo,
            'manuel',
            $simdi,
            $uid,
            $id,
        ]);
        doviz_audit('update', $id, [
            'kayit' => 'kur', 'tarih' => $tarih, 'kod' => $kod,
            'doviz_alis' => $mevcut['doviz_alis'], 'doviz_satis' => $mevcut['doviz_satis'],
        ], [
            'kayit' => 'kur', 'tarih' => $tarih, 'kod' => $kod,
            'doviz_alis' => $degerler['doviz_alis'], 'doviz_satis' => $degerler['doviz_satis'], 'kaynak' => 'manuel',
        ]);
        return ['ok' => true, 'id' => $id, 'tarih' => $tarih, 'kod' => $kod];
    }

    $pdo->prepare(
        'INSERT INTO doviz_kurlar (tarih, kod, birim, doviz_alis, doviz_satis, efektif_alis, efektif_satis, capraz_usd, capraz_diger, bulten_no, kaynak, created_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $tarih,
        $kod,
        $birim,
        $degerler['doviz_alis'],
        $degerler['doviz_satis'],
        $degerler['efektif_alis'],
        $degerler['efektif_satis'],
        $degerler['capraz_usd'],
        $degerler['capraz_diger'],
        $bultenNo,
        'manuel',
        $simdi,
        $uid,
    ]);
    $yid = (int)$pdo->lastInsertId();
    doviz_audit('create', $yid, null, [
        'kayit' => 'kur', 'tarih' => $tarih, 'kod' => $kod,
        'doviz_alis' => $degerler['doviz_alis'], 'doviz_satis' => $degerler['doviz_satis'], 'kaynak' => 'manuel',
    ]);
    return ['ok' => true, 'id' => $yid, 'tarih' => $tarih, 'kod' => $kod];
}

/** Günlük kur satırını siler. */
function doviz_kur_sil(PDO $pdo, int $id, ?int $uid): array
{
    if ($id <= 0) return ['ok' => false, 'hata' => 'Geçersiz kur kaydı.'];
    $st = $pdo->prepare('SELECT * FROM doviz_kurlar WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r === false) return ['ok' => false, 'hata' => 'Kur kaydı bulunamadı.'];

    $pdo->prepare('DELETE FROM doviz_kurlar WHERE id = ?')->execute([$id]);
    doviz_audit('delete', $id, ['kayit' => 'kur', 'tarih' => $r['tarih'], 'kod' => $r['kod'], 'doviz_satis' => $r['doviz_satis']], null);
    return ['ok' => true, 'id' => $id];
}

/**
 * Belirli bir tarihin kurlarını tanım bilgileri ve bir önceki iş gününe göre günlük değişim yüzdesiyle döndürür.
 * @return list<array<string,mixed>>
 */
function doviz_gunluk_liste(PDO $pdo, string $tarih, bool $yalnizAktif = false): array
{
    if (!doviz_tarih_gecerli($tarih)) return [];
    $sql = 'SELECT k.*, t.ad AS tanim_ad, t.sembol AS tanim_sembol, t.ondalik AS tanim_ondalik,
                   t.varsayilan_kur_tipi, t.aktif AS tanim_aktif, t.sira AS tanim_sira
            FROM doviz_kurlar k
            LEFT JOIN doviz_tanimlar t ON t.kod = k.kod
            WHERE k.tarih = ?';
    if ($yalnizAktif) {
        $sql .= ' AND t.aktif = 1';
    }
    $sql .= ' ORDER BY CASE WHEN t.sira IS NULL THEN 9999 ELSE t.sira END ASC, k.kod ASC';

    $st = $pdo->prepare($sql);
    $st->execute([$tarih]);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$satirlar) return [];

    // Önceki iş günü kurları (günlük değişim hesabı için)
    $stPrevTarih = $pdo->prepare('SELECT MAX(tarih) FROM doviz_kurlar WHERE tarih < ?');
    $stPrevTarih->execute([$tarih]);
    $oncekiTarih = $stPrevTarih->fetchColumn();
    $oncekiler   = [];
    if (is_string($oncekiTarih) && $oncekiTarih !== '') {
        $stPrev = $pdo->prepare('SELECT kod, doviz_satis, doviz_alis FROM doviz_kurlar WHERE tarih = ?');
        $stPrev->execute([$oncekiTarih]);
        foreach ($stPrev->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $oncekiler[(string)$pr['kod']] = $pr;
        }
    }

    $katalog = doviz_tcmb_katalog();
    foreach ($satirlar as &$r) {
        $kod = (string)$r['kod'];
        $kat = $katalog[$kod] ?? null;
        $r['ad']      = $r['tanim_ad'] !== null && $r['tanim_ad'] !== '' ? (string)$r['tanim_ad'] : ($kat['ad'] ?? $kod);
        $r['sembol']  = $r['tanim_sembol'] !== null && $r['tanim_sembol'] !== '' ? (string)$r['tanim_sembol'] : ($kat['sembol'] ?? $kod);
        $r['ondalik'] = $r['tanim_ondalik'] !== null ? (int)$r['tanim_ondalik'] : 4;

        $prevRow = $oncekiler[$kod] ?? null;
        $oncekiSatis = $prevRow !== null ? ($prevRow['doviz_satis'] ?? $prevRow['doviz_alis'] ?? null) : null;
        $guncelSatis = $r['doviz_satis'] ?? $r['doviz_alis'] ?? null;

        $r['onceki_tarih']       = is_string($oncekiTarih) && $oncekiTarih !== '' ? $oncekiTarih : null;
        $r['onceki_doviz_satis'] = $oncekiSatis !== null ? (string)$oncekiSatis : null;
        if ($oncekiSatis !== null && $guncelSatis !== null && (float)$oncekiSatis > 0.0) {
            $r['degisim_yuzde'] = round((((float)$guncelSatis - (float)$oncekiSatis) / (float)$oncekiSatis) * 100.0, 4);
        } else {
            $r['degisim_yuzde'] = null;
        }
    }
    unset($r);
    return $satirlar;
}

/** Kayıtlı en son kur tarihini döndürür. */
function doviz_son_tarih(PDO $pdo): ?string
{
    $v = $pdo->query('SELECT MAX(tarih) FROM doviz_kurlar')->fetchColumn();
    return is_string($v) && $v !== '' ? $v : null;
}

/** Kayıtlı bülten tarihlerini azalan sırada döndürür. @return list<array{tarih:string,bulten_no:?string,kur_sayisi:int}> */
function doviz_mevcut_tarihler(PDO $pdo, int $limit = 60): array
{
    $lim = max(1, min(365, $limit));
    $sql = "SELECT tarih, MAX(bulten_no) AS bulten_no, COUNT(*) AS kur_sayisi
            FROM doviz_kurlar
            GROUP BY tarih
            ORDER BY tarih DESC
            LIMIT {$lim}";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Tarih ve para birimi filtresine göre geçmiş kur arşivi. @return list<array<string,mixed>> */
function doviz_gecmis_liste(PDO $pdo, string $kod = '', string $bas = '', string $bit = '', int $limit = 120): array
{
    $where = [];
    $params = [];

    $kod = strtoupper(trim($kod));
    if ($kod !== '' && preg_match('/^[A-Z]{3,10}$/', $kod)) {
        $where[]  = 'k.kod = ?';
        $params[] = $kod;
    }
    if ($bas !== '' && ($t = doviz_tarih_coz($bas)) !== null) {
        $where[]  = 'k.tarih >= ?';
        $params[] = $t;
    }
    if ($bit !== '' && ($t = doviz_tarih_coz($bit)) !== null) {
        $where[]  = 'k.tarih <= ?';
        $params[] = $t;
    }

    $lim = max(1, min(500, $limit));
    $sql = 'SELECT k.*, t.ad AS tanim_ad, t.sembol AS tanim_sembol, t.ondalik AS tanim_ondalik
            FROM doviz_kurlar k
            LEFT JOIN doviz_tanimlar t ON t.kod = k.kod'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY k.tarih DESC, k.kod ASC LIMIT {$lim}";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Otonom kur çözücü (Fatura / Kasa / Çek-Senet ve api_doviz_kur.php için).
 * İstenen tarihte kayıt yoksa ve $otoCek=true ise 10 dk oturum bekleme süresiyle TCMB'den çeker,
 * en yakın geçmiş iş günü kurunu bulur ve birim'e bölerek 1 birimlik kur döndürür.
 * @return array<string,mixed>
 */
function doviz_kur_bul(PDO $pdo, string $kod, ?string $tarih = null, ?string $tip = null, bool $otoCek = true): array
{
    $kod = strtoupper(trim($kod));
    if (!preg_match('/^[A-Z]{3,10}$/', $kod)) {
        return ['ok' => false, 'hata' => 'Geçersiz para birimi kodu.'];
    }

    if ($tarih !== null && trim($tarih) !== '') {
        $istenenTarih = doviz_tarih_coz($tarih);
        if ($istenenTarih === null) {
            return ['ok' => false, 'hata' => 'Geçersiz tarih formatı.'];
        }
    } else {
        $istenenTarih = date('Y-m-d');
    }

    if ($kod === 'TRY') {
        return [
            'ok'            => true,
            'kod'           => 'TRY',
            'tarih'         => $istenenTarih,
            'istenen_tarih' => $istenenTarih,
            'kur'           => '1,0000',
            'kur_nokta'     => '1.000000',
            'tip'           => 'sabit',
            'tip_etiket'    => 'Sabit',
            'doviz_alis'    => '1.000000',
            'doviz_satis'   => '1.000000',
            'efektif_alis'  => '1.000000',
            'efektif_satis' => '1.000000',
            'birim'         => 1,
            'kaynak'        => 'sabit',
            'bulten_no'     => null,
            'etiket'        => 'TRY (Sabit 1,0000)',
        ];
    }

    // Tam tarihte kur var mı? Yoksa ve otoCek açıksa (10 dk soğuma ile) TCMB'den çek
    $stTam = $pdo->prepare('SELECT 1 FROM doviz_kurlar WHERE kod = ? AND tarih = ? LIMIT 1');
    $stTam->execute([$kod, $istenenTarih]);
    if ($stTam->fetchColumn() === false && $otoCek) {
        $simdiTs   = time();
        $sonDeneme = (int)($_SESSION['_dvz_oto_deneme'][$istenenTarih] ?? 0);
        if ($simdiTs - $sonDeneme >= 600) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_dvz_oto_deneme'][$istenenTarih] = $simdiTs;
            }
            doviz_tcmb_senkron($pdo, $istenenTarih, null, false, true);
        }
    }

    // Tam tarih veya en yakın önceki iş günü
    $st = $pdo->prepare('SELECT * FROM doviz_kurlar WHERE kod = ? AND tarih <= ? ORDER BY tarih DESC LIMIT 1');
    $st->execute([$kod, $istenenTarih]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return [
            'ok'            => false,
            'kod'           => $kod,
            'istenen_tarih' => $istenenTarih,
            'hata'          => $kod . ' için ' . date('d.m.Y', strtotime($istenenTarih)) . ' veya öncesinde kayıtlı kur bulunamadı.',
        ];
    }

    $tanim     = doviz_tanim_oku($pdo, $kod);
    $kurTipleri = doviz_kur_tipleri();
    $seciliTip = ($tip !== null && isset($kurTipleri[$tip]))
        ? $tip
        : (string)($tanim['varsayilan_kur_tipi'] ?? 'doviz_satis');
    if (!isset($kurTipleri[$seciliTip])) {
        $seciliTip = 'doviz_satis';
    }

    $hamDeger = $row[$seciliTip] ?? null;
    if ($hamDeger === null || (float)$hamDeger <= 0.0) {
        foreach (['doviz_satis', 'doviz_alis', 'efektif_satis', 'efektif_alis'] as $yedekTip) {
            if (isset($row[$yedekTip]) && $row[$yedekTip] !== null && (float)$row[$yedekTip] > 0.0) {
                $hamDeger  = $row[$yedekTip];
                $seciliTip = $yedekTip;
                break;
            }
        }
    }
    if ($hamDeger === null || (float)$hamDeger <= 0.0) {
        return [
            'ok'            => false,
            'kod'           => $kod,
            'istenen_tarih' => $istenenTarih,
            'hata'          => $kod . ' için geçerli kur değeri bulunamadı.',
        ];
    }

    $birim     = max(1, (int)($row['birim'] ?? 1));
    $birimKur  = (float)$hamDeger / $birim;
    $ondalik   = max(2, min(6, (int)($tanim['ondalik'] ?? 4)));
    $kurNokta  = number_format($birimKur, 6, '.', '');
    $kurTr     = number_format($birimKur, $ondalik, ',', '.');
    $tipEtiket = $kurTipleri[$seciliTip] ?? 'Döviz Satış';
    $kaynak    = (string)($row['kaynak'] ?? 'tcmb');
    $kaynakAd  = $kaynak === 'manuel' ? 'Manuel' : 'TCMB';
    $trTarih   = date('d.m.Y', strtotime((string)$row['tarih']));

    return [
        'ok'            => true,
        'kod'           => $kod,
        'tarih'         => (string)$row['tarih'],
        'istenen_tarih' => $istenenTarih,
        'kur'           => $kurTr,
        'kur_nokta'     => $kurNokta,
        'tip'           => $seciliTip,
        'tip_etiket'    => $tipEtiket,
        'doviz_alis'    => $row['doviz_alis'] !== null ? (string)$row['doviz_alis'] : null,
        'doviz_satis'   => $row['doviz_satis'] !== null ? (string)$row['doviz_satis'] : null,
        'efektif_alis'  => $row['efektif_alis'] !== null ? (string)$row['efektif_alis'] : null,
        'efektif_satis' => $row['efektif_satis'] !== null ? (string)$row['efektif_satis'] : null,
        'birim'         => $birim,
        'kaynak'        => $kaynak,
        'bulten_no'     => $row['bulten_no'] !== null ? (string)$row['bulten_no'] : null,
        'etiket'        => $kaynakAd . ' ' . $trTarih . ' ' . $tipEtiket,
    ];
}
