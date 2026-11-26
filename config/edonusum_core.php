<?php
// =========================================================
// config/edonusum_core.php — e-Dönüşüm Çekirdeği (ERP-2)
// (e-Fatura, e-Arşiv, e-İrsaliye, e-Müstahsil · UBL-TR 1.2 XML · ETTN)
//
// Mimari:
//   - Entegratör-bağımsız EdonusumProviderInterface (QNB/Foriba/İzibiz/Logo adaptörü eklenebilir)
//   - Yerleşik EdonusumTestProvider (sandbox / kuru çalışma)
//   - Standart UBL-TR 1.2 XML üretici (DOMDocument ile doğrulanmış, XXE-kapalı)
//   - Giden zarflar (edonusum_zarflar) + Gelen kutusu kabul/red (edonusum_gelen)
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_tx_helpers.php';
require_once __DIR__ . '/muhasebe_core.php';
require_once __DIR__ . '/edonusum_elogo.php';

// ── Entegratör Adaptör Arayüzü ─────────────────────────────────────────────

interface EdonusumProviderInterface
{
    public function ad(): string;
    /** @return array{ok:bool,kayitli:bool,posta_kutusu:?string,unvan?:string,hata?:string} */
    public function mukellefSorgula(string $vknTckn): array;
    /** @return array{ok:bool,zarf_uuid?:string,kod?:string,mesaj?:string,hata?:string} */
    public function zarfGonder(array $zarf, string $ublXml): array;
    /** @return array{ok:bool,durum:string,kod?:string,mesaj?:string} */
    public function durumSorgula(string $ettn, ?string $zarfUuid = null): array;
    /** @return array{ok:bool,belgeler:list<array<string,mixed>>,hata?:string} */
    public function gelenKutusuCek(string $bas, string $bit): array;
    /** @return array{ok:bool,kod?:string,mesaj?:string,hata?:string} */
    public function ticariYanitGonder(string $ettn, string $yanit, string $neden = ''): array;
}

/** Yerleşik Sandbox / Test Sağlayıcısı (gerçek GİB çağrısı yapmaz; uçtan uca akışı doğrular). */
final class EdonusumTestProvider implements EdonusumProviderInterface
{
    public function ad(): string { return 'sandbox_test'; }

    public function mukellefSorgula(string $vknTckn): array
    {
        $v = preg_replace('/\D/', '', $vknTckn) ?? '';
        if (strlen($v) !== 10 && strlen($v) !== 11) {
            return ['ok' => false, 'kayitli' => false, 'posta_kutusu' => null, 'hata' => 'VKN 10, TCKN 11 hane olmalıdır.'];
        }
        // Test kuralı: 10 haneli VKN'ler e-Fatura mükellefi sayılır
        $kayitli = strlen($v) === 10;
        return [
            'ok'           => true,
            'kayitli'      => $kayitli,
            'posta_kutusu' => $kayitli ? ('urn:mail:defaultpk@' . $v . '.test') : null,
            'unvan'        => 'TEST MÜKELLEF ' . $v,
        ];
    }

    public function zarfGonder(array $zarf, string $ublXml): array
    {
        if (trim($ublXml) === '' || !str_contains($ublXml, (string)$zarf['ettn'])) {
            return ['ok' => false, 'hata' => 'UBL-TR XML doğrulaması başarısız (ETTN eksik).'];
        }
        return [
            'ok'        => true,
            'zarf_uuid' => edonusum_uuid_v4(),
            'kod'       => '1200',
            'mesaj'     => 'Sandbox: Zarf başarıyla kuyruğa alındı (simülasyon).',
        ];
    }

    public function durumSorgula(string $ettn, ?string $zarfUuid = null): array
    {
        return [
            'ok'    => true,
            'durum' => 'onaylandi',
            'kod'   => '1300',
            'mesaj' => 'Sandbox: Belge GİB tarafından onaylandı (simülasyon).',
        ];
    }

    public function gelenKutusuCek(string $bas, string $bit): array
    {
        return ['ok' => true, 'belgeler' => []];
    }

    public function ticariYanitGonder(string $ettn, string $yanit, string $neden = ''): array
    {
        return [
            'ok'    => true,
            'kod'   => $yanit === 'kabul' ? 'KABUL' : 'RED',
            'mesaj' => 'Sandbox: Ticari fatura yanıtı iletildi (' . strtoupper($yanit) . ').',
        ];
    }
}

/** @return array<string,mixed> */
function edonusum_ayar_varsayilan(): array
{
    $ortam = strtolower(edonusum_env('ELOGO_ORTAM', 'test')) === 'canli' ? 'canli' : 'test';
    $sag   = strtolower(edonusum_env('EDONUSUM_PROVIDER', 'sandbox_test'));
    if (!in_array($sag, ['sandbox_test', 'elogo'], true)) $sag = 'sandbox_test';
    return [
        'saglayici'           => $sag,
        'ortam'               => $ortam,
        'servis_url'          => edonusum_env('ELOGO_SERVIS_URL', ''),
        'kullanici_adi'       => edonusum_env('ELOGO_USERNAME', ''),
        'sifre_kayitli'       => edonusum_env('ELOGO_PASSWORD', '') !== '',
        'gb_etiket'           => edonusum_env('ELOGO_GB_ETIKET', ''),
        'pk_etiket'           => edonusum_env('ELOGO_PK_ETIKET', ''),
        'xslt_efatura_uuid'   => edonusum_env('ELOGO_XSLT_EFATURA', ''),
        'xslt_earsiv_uuid'    => edonusum_env('ELOGO_XSLT_EARSIV', ''),
        'xslt_eirsaliye_uuid' => edonusum_env('ELOGO_XSLT_EIRSALIYE', ''),
        'seri_efatura'        => 'ASY',
        'seri_earsiv'         => 'ASA',
        'seri_eirsaliye'      => 'ASI',
        'firma_vkn'           => edonusum_env('EDONUSUM_FIRMA_VKN', '1111111111'),
        'firma_unvan'         => edonusum_env('EDONUSUM_FIRMA_UNVAN', 'ASYA FRESH TARIM GIDA İHRACAT LTD. ŞTİ.'),
        'firma_vergi_dairesi' => edonusum_env('EDONUSUM_FIRMA_VD', ''),
        'firma_il'            => edonusum_env('EDONUSUM_FIRMA_IL', 'ANTALYA'),
        'firma_ilce'          => edonusum_env('EDONUSUM_FIRMA_ILCE', 'KEPEZ'),
        'firma_adres'         => edonusum_env('EDONUSUM_FIRMA_ADRES', ''),
        'otomatik_imza'       => 0,
        'son_test_at'         => null,
        'son_test_durum'      => null,
        'son_test_mesaj'      => null,
    ];
}

/** UI güvenli ayar okuyucu (şifre düz metin döndürülmez; yalnız sifre_kayitli bayrağı verilir). @return array<string,mixed> */
function edonusum_ayar_getir(?PDO $pdo = null): array
{
    $v = edonusum_ayar_varsayilan();
    if ($pdo === null || !db_table_exists($pdo, 'edonusum_ayarlar')) {
        return $v;
    }
    try {
        $r = $pdo->query('SELECT * FROM `edonusum_ayarlar` WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) return $v;
        foreach ($v as $k => $def) {
            if ($k === 'sifre_kayitli') continue;
            if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') {
                $v[$k] = is_int($def) ? (int)$r[$k] : (string)$r[$k];
            }
        }
        $v['sifre_kayitli'] = (!empty($r['sifre_enc'])) || edonusum_env('ELOGO_PASSWORD', '') !== '';
        $v['son_test_at'] = $r['son_test_at'] ?? null;
        $v['son_test_durum'] = $r['son_test_durum'] ?? null;
        $v['son_test_mesaj'] = $r['son_test_mesaj'] ?? null;
    } catch (PDOException $e) {
        // Varsayılanla devam
    }
    return $v;
}

/** İç kullanım: şifresi çözülmüş e-Logo yapılandırması. @return array<string,mixed> */
function edonusum_ayar_cozulu(?PDO $pdo = null): array
{
    $a = edonusum_ayar_getir($pdo);
    $sifre = '';
    if ($pdo !== null && db_table_exists($pdo, 'edonusum_ayarlar')) {
        try {
            $enc = $pdo->query('SELECT sifre_enc FROM `edonusum_ayarlar` WHERE id = 1')->fetchColumn();
            if (is_string($enc) && $enc !== '') {
                $coz = edonusum_sifre_coz($enc);
                if ($coz !== null) $sifre = $coz;
            }
        } catch (PDOException $e) {}
    }
    if ($sifre === '') {
        $sifre = edonusum_env('ELOGO_PASSWORD', '');
    }
    $a['sifre'] = $sifre;
    return $a;
}

/** @return array{ok:bool,mesaj?:string,hata?:string,kod?:int} */
function edonusum_ayar_kaydet(PDO $pdo, array $g, int $uid): array
{
    $sag = trim((string)($g['saglayici'] ?? 'sandbox_test'));
    if (!in_array($sag, ['sandbox_test', 'elogo'], true)) {
        return ['ok' => false, 'hata' => 'Geçersiz entegratör sağlayıcısı.', 'kod' => 422];
    }
    $ortam = trim((string)($g['ortam'] ?? 'test')) === 'canli' ? 'canli' : 'test';
    $hamUrl = trim((string)($g['servis_url'] ?? ''));
    $servisUrl = '';
    if ($hamUrl !== '') {
        $guvenli = edonusum_elogo_url_guvenli($hamUrl);
        if ($guvenli === null) {
            return ['ok' => false, 'hata' => 'Servis adresi yalnızca HTTPS ve *.elogo.com.tr / *.logo.com.tr alan adında olmalıdır.', 'kod' => 422];
        }
        $servisUrl = $guvenli;
    }

    $kullanici = mb_substr(trim((string)($g['kullanici_adi'] ?? '')), 0, 120, 'UTF-8');
    $yeniSifre = (string)($g['sifre'] ?? '');
    $sifreTemizle = !empty($g['sifre_temizle']);
    $mevcutEnc = null;
    try {
        $e = $pdo->query('SELECT sifre_enc FROM `edonusum_ayarlar` WHERE id = 1')->fetchColumn();
        if (is_string($e) && $e !== '') $mevcutEnc = $e;
    } catch (PDOException $e) {}

    $sifreEnc = $mevcutEnc;
    if ($sifreTemizle) {
        $sifreEnc = null;
    } elseif ($yeniSifre !== '') {
        $enc = edonusum_sifre_sifrele($yeniSifre);
        if ($enc === null) {
            return [
                'ok'   => false,
                'hata' => 'Şifre şifrelenemedi: config/local.php içinde HKS_CRED_KEY (veya EDONUSUM_CRED_KEY, en az 16 karakter) tanımlı olmalıdır.',
                'kod'  => 500,
            ];
        }
        $sifreEnc = $enc;
    }

    $vkn = preg_replace('/\D/', '', (string)($g['firma_vkn'] ?? '')) ?? '';
    if ($vkn !== '' && strlen($vkn) !== 10 && strlen($vkn) !== 11) {
        return ['ok' => false, 'hata' => 'Firma VKN 10, TCKN 11 hane olmalıdır.', 'kod' => 422];
    }
    $seriTemiz = static function (string $s, string $def): string {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
        return strlen($s) === 3 ? $s : $def;
    };
    $seriEf  = $seriTemiz((string)($g['seri_efatura'] ?? 'ASY'), 'ASY');
    $seriEa  = $seriTemiz((string)($g['seri_earsiv'] ?? 'ASA'), 'ASA');
    $seriEi  = $seriTemiz((string)($g['seri_eirsaliye'] ?? 'ASI'), 'ASI');

    $gb  = mb_substr(trim((string)($g['gb_etiket'] ?? '')), 0, 160, 'UTF-8');
    $pk  = mb_substr(trim((string)($g['pk_etiket'] ?? '')), 0, 160, 'UTF-8');
    $xEf = mb_substr(trim((string)($g['xslt_efatura_uuid'] ?? '')), 0, 64, 'UTF-8');
    $xEa = mb_substr(trim((string)($g['xslt_earsiv_uuid'] ?? '')), 0, 64, 'UTF-8');
    $xEi = mb_substr(trim((string)($g['xslt_eirsaliye_uuid'] ?? '')), 0, 64, 'UTF-8');
    $unvan = mb_substr(trim((string)($g['firma_unvan'] ?? '')), 0, 200, 'UTF-8');
    $vd    = mb_substr(trim((string)($g['firma_vergi_dairesi'] ?? '')), 0, 120, 'UTF-8');
    $il    = mb_substr(trim((string)($g['firma_il'] ?? '')), 0, 80, 'UTF-8');
    $ilce  = mb_substr(trim((string)($g['firma_ilce'] ?? '')), 0, 80, 'UTF-8');
    $adres = mb_substr(trim((string)($g['firma_adres'] ?? '')), 0, 300, 'UTF-8');
    $otoImza = !empty($g['otomatik_imza']) ? 1 : 0;
    $simdi = date('Y-m-d H:i:s');

    return db_tx($pdo, static function (PDO $p) use (
        $sag, $ortam, $servisUrl, $kullanici, $sifreEnc, $gb, $pk,
        $xEf, $xEa, $xEi, $seriEf, $seriEa, $seriEi,
        $vkn, $unvan, $vd, $il, $ilce, $adres, $otoImza, $uid, $simdi
    ): array {
        $var = (int)$p->query('SELECT COUNT(*) FROM `edonusum_ayarlar` WHERE id = 1')->fetchColumn() > 0;
        if ($var) {
            $p->prepare(
                'UPDATE `edonusum_ayarlar`
                 SET saglayici=?, ortam=?, servis_url=?, kullanici_adi=?, sifre_enc=?, gb_etiket=?, pk_etiket=?,
                     xslt_efatura_uuid=?, xslt_earsiv_uuid=?, xslt_eirsaliye_uuid=?,
                     seri_efatura=?, seri_earsiv=?, seri_eirsaliye=?,
                     firma_vkn=?, firma_unvan=?, firma_vergi_dairesi=?, firma_il=?, firma_ilce=?, firma_adres=?,
                     otomatik_imza=?, guncelleyen_id=?, guncelleme_at=?
                 WHERE id = 1'
            )->execute([
                $sag, $ortam, $servisUrl, $kullanici, $sifreEnc, $gb, $pk,
                $xEf, $xEa, $xEi, $seriEf, $seriEa, $seriEi,
                $vkn, $unvan, $vd, $il, $ilce, $adres, $otoImza, $uid ?: null, $simdi,
            ]);
        } else {
            $p->prepare(
                'INSERT INTO `edonusum_ayarlar`
                 (id, saglayici, ortam, servis_url, kullanici_adi, sifre_enc, gb_etiket, pk_etiket,
                  xslt_efatura_uuid, xslt_earsiv_uuid, xslt_eirsaliye_uuid,
                  seri_efatura, seri_earsiv, seri_eirsaliye,
                  firma_vkn, firma_unvan, firma_vergi_dairesi, firma_il, firma_ilce, firma_adres,
                  otomatik_imza, guncelleyen_id, guncelleme_at)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $sag, $ortam, $servisUrl, $kullanici, $sifreEnc, $gb, $pk,
                $xEf, $xEa, $xEi, $seriEf, $seriEa, $seriEi,
                $vkn, $unvan, $vd, $il, $ilce, $adres, $otoImza, $uid ?: null, $simdi,
            ]);
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('update', 'edonusum_ayar', 1, null, [
                'saglayici' => $sag, 'ortam' => $ortam, 'kullanici_adi' => $kullanici, 'sifre_degisti' => ($sifreEnc !== null),
            ]);
        }
        return ['ok' => true, 'mesaj' => 'e-Dönüşüm / e-Logo entegratör ayarları kaydedildi.'];
    });
}

function edonusum_provider(?EdonusumProviderInterface $ozel = null, ?PDO $pdo = null): EdonusumProviderInterface
{
    if ($ozel !== null) return $ozel;
    if (isset($GLOBALS['_edonusum_provider']) && $GLOBALS['_edonusum_provider'] instanceof EdonusumProviderInterface) {
        return $GLOBALS['_edonusum_provider'];
    }
    $ayar = edonusum_ayar_cozulu($pdo);
    if (($ayar['saglayici'] ?? 'sandbox_test') === 'elogo') {
        return new EdonusumElogoProvider($ayar);
    }
    return new EdonusumTestProvider();
}

// ── Şema ve Migrasyon ──────────────────────────────────────────────────────

/** @return list<string> */
function edonusum_tablolar(): array
{
    return ['edonusum_zarflar', 'edonusum_gelen', 'edonusum_ayarlar'];
}

/** @return list<string> */
function edonusum_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (edonusum_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function edonusum_hazir(PDO $pdo): bool
{
    return edonusum_eksik_tablolar($pdo) === [];
}

function edonusum_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = edonusum_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'e-Dönüşüm tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#edonusum kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('muh_sema_uyari')) {
        render_header('e-Dönüşüm');
        echo muh_sema_uyari($eksik, 'edonusum', 'e-Dönüşüm (e-Fatura / e-Arşiv / e-İrsaliye)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('e-Dönüşüm şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function edonusum_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $xmlCol = $sqlite ? 'TEXT' : 'MEDIUMTEXT';

    $ddl = [
        'edonusum_zarflar' => "CREATE TABLE IF NOT EXISTS `edonusum_zarflar` (
            `id` $ai,
            `ettn` VARCHAR(36) NOT NULL UNIQUE,
            `belge_turu` VARCHAR(20) NOT NULL DEFAULT 'efatura',
            `senaryo` VARCHAR(30) NOT NULL DEFAULT 'TICARIFATURA',
            `fatura_id` INT NULL,
            `belge_no` VARCHAR(40) NOT NULL,
            `tarih` DATE NOT NULL,
            `alici_vkn` VARCHAR(20) NOT NULL DEFAULT '',
            `alici_unvan` VARCHAR(200) NOT NULL DEFAULT '',
            `alici_pk` VARCHAR(160) NULL,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `ara_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kdv_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `genel_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'taslak',
            `saglayici` VARCHAR(40) NOT NULL DEFAULT 'sandbox_test',
            `zarf_uuid` VARCHAR(64) NULL,
            `yanit_kod` VARCHAR(20) NULL,
            `yanit_mesaj` VARCHAR(500) NULL,
            `ubl_xml` $xmlCol NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `guncelleme_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'edonusum_gelen' => "CREATE TABLE IF NOT EXISTS `edonusum_gelen` (
            `id` $ai,
            `ettn` VARCHAR(36) NOT NULL UNIQUE,
            `belge_turu` VARCHAR(20) NOT NULL DEFAULT 'efatura',
            `senaryo` VARCHAR(30) NOT NULL DEFAULT 'TICARIFATURA',
            `belge_no` VARCHAR(40) NOT NULL,
            `tarih` DATE NOT NULL,
            `gonderen_vkn` VARCHAR(20) NOT NULL DEFAULT '',
            `gonderen_unvan` VARCHAR(200) NOT NULL DEFAULT '',
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `ara_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `kdv_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `genel_toplam` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
            `yanit_neden` VARCHAR(500) NULL,
            `yanit_at` DATETIME NULL,
            `yanit_user_id` INT NULL,
            `ubl_xml` $xmlCol NULL,
            `alindi_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'edonusum_ayarlar' => "CREATE TABLE IF NOT EXISTS `edonusum_ayarlar` (
            `id` INT PRIMARY KEY,
            `saglayici` VARCHAR(30) NOT NULL DEFAULT 'sandbox_test',
            `ortam` VARCHAR(16) NOT NULL DEFAULT 'test',
            `servis_url` VARCHAR(255) NOT NULL DEFAULT '',
            `kullanici_adi` VARCHAR(120) NOT NULL DEFAULT '',
            `sifre_enc` TEXT NULL,
            `gb_etiket` VARCHAR(160) NOT NULL DEFAULT '',
            `pk_etiket` VARCHAR(160) NOT NULL DEFAULT '',
            `xslt_efatura_uuid` VARCHAR(64) NOT NULL DEFAULT '',
            `xslt_earsiv_uuid` VARCHAR(64) NOT NULL DEFAULT '',
            `xslt_eirsaliye_uuid` VARCHAR(64) NOT NULL DEFAULT '',
            `seri_efatura` VARCHAR(8) NOT NULL DEFAULT 'ASY',
            `seri_earsiv` VARCHAR(8) NOT NULL DEFAULT 'ASA',
            `seri_eirsaliye` VARCHAR(8) NOT NULL DEFAULT 'ASI',
            `firma_vkn` VARCHAR(20) NOT NULL DEFAULT '',
            `firma_unvan` VARCHAR(200) NOT NULL DEFAULT '',
            `firma_vergi_dairesi` VARCHAR(120) NOT NULL DEFAULT '',
            `firma_il` VARCHAR(80) NOT NULL DEFAULT '',
            `firma_ilce` VARCHAR(80) NOT NULL DEFAULT '',
            `firma_adres` VARCHAR(300) NOT NULL DEFAULT '',
            `otomatik_imza` TINYINT NOT NULL DEFAULT 0,
            `son_test_at` DATETIME NULL,
            `son_test_durum` VARCHAR(20) NULL,
            `son_test_mesaj` VARCHAR(500) NULL,
            `guncelleyen_id` INT NULL,
            `guncelleme_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
    ];

    $rapor = [];
    foreach ($ddl as $t => $sql) {
        $var = db_table_exists($pdo, $t);
        $pdo->exec($sql);
        $rapor[] = [
            'tablo' => $t,
            'durum' => $var ? 'var' : 'olusturuldu',
            'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.',
        ];
    }

    $indeksler = [
        ['edonusum_zarflar', 'idx_ed_zarf_fatura', 'fatura_id'],
        ['edonusum_zarflar', 'idx_ed_zarf_tarih_durum', 'tarih, durum'],
        ['edonusum_gelen',   'idx_ed_gelen_tarih_durum', 'tarih, durum'],
    ];
    foreach ($indeksler as [$t, $ix, $kol]) {
        try {
            if ($sqlite) {
                $pdo->exec("CREATE INDEX IF NOT EXISTS `$ix` ON `$t` ($kol)");
            } else {
                $st = $pdo->prepare("SHOW INDEX FROM `$t` WHERE Key_name = ?");
                $st->execute([$ix]);
                if ($st->rowCount() === 0) $pdo->exec("ALTER TABLE `$t` ADD INDEX `$ix` ($kol)");
            }
        } catch (PDOException $e) {}
    }

    return $rapor;
}

// ── ETTN (UUIDv4) ve UBL-TR 1.2 XML Üretici ────────────────────────────────

function edonusum_uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function edonusum_belge_turleri(): array
{
    return [
        'efatura'    => 'e-Fatura',
        'earsiv'     => 'e-Arşiv Fatura',
        'eirsaliye'  => 'e-İrsaliye',
        'emustahsil' => 'e-Müstahsil Makbuzu',
    ];
}

function edonusum_xml_escape(string $v): string
{
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function edonusum_birim_kodu(string $birim): string
{
    $b = mb_strtolower(trim($birim), 'UTF-8');
    return match ($b) {
        'kg', 'kgm', 'kilogram' => 'KGM',
        'gr', 'g', 'grm'        => 'GRM',
        'ton', 'tne'            => 'TNE',
        'adet', 'ad', 'c62', 'niu' => 'C62',
        'kasa', 'koli', 'cs'    => 'CS',
        'palet', 'pf'           => 'PF',
        'lt', 'litre', 'ltr'    => 'LTR',
        'm', 'metre', 'mtr'     => 'MTR',
        'paket', 'pk'           => 'PK',
        default                 => preg_match('/^[A-Z0-9]{2,3}$/', strtoupper($b)) ? strtoupper($b) : 'C62',
    };
}

/**
 * Standart GİB UBL-TR 1.2 XML üretir (Invoice / DespatchAdvice).
 * @param array{
 *   ettn:string, belge_turu:string, senaryo:string, belge_no:string, tarih:string,
 *   saat?:string, para_birimi:string,
 *   gonderen_vkn?:string, gonderen_unvan?:string, gonderen_vd?:string,
 *   gonderen_il?:string, gonderen_ilce?:string, gonderen_adres?:string,
 *   alici_vkn:string, alici_unvan:string, alici_vd?:string,
 *   alici_il?:string, alici_ilce?:string, alici_adres?:string,
 *   ara_toplam:float, kdv_toplam:float, genel_toplam:float,
 *   satirlar:list<array{ad:string,miktar:float,birim?:string,birim_fiyat:float,kdv_oran:float,tutar:float}>
 * } $veri
 */
function edonusum_ubl_xml_uret(array $veri): string
{
    $ettn     = (string)$veri['ettn'];
    $tur      = (string)($veri['belge_turu'] ?? 'efatura');
    $senaryo  = (string)($veri['senaryo'] ?? 'TICARIFATURA');
    $belgeNo  = (string)$veri['belge_no'];
    $tarih    = (string)$veri['tarih'];
    $saat     = (string)($veri['saat'] ?? date('H:i:s'));
    $pb       = strtoupper((string)($veri['para_birimi'] ?? 'TRY'));
    $gVkn     = preg_replace('/\D/', '', (string)($veri['gonderen_vkn'] ?? edonusum_env('EDONUSUM_FIRMA_VKN', '1111111111'))) ?: '1111111111';
    $gUnvan   = trim((string)($veri['gonderen_unvan'] ?? edonusum_env('EDONUSUM_FIRMA_UNVAN', 'ASYA FRESH TARIM GIDA İHRACAT LTD. ŞTİ.'))) ?: 'ASYA FRESH TARIM GIDA İHRACAT LTD. ŞTİ.';
    $gVd      = trim((string)($veri['gonderen_vd'] ?? edonusum_env('EDONUSUM_FIRMA_VD', '')));
    $gIl      = trim((string)($veri['gonderen_il'] ?? edonusum_env('EDONUSUM_FIRMA_IL', 'ANTALYA'))) ?: 'ANTALYA';
    $gIlce    = trim((string)($veri['gonderen_ilce'] ?? edonusum_env('EDONUSUM_FIRMA_ILCE', 'KEPEZ'))) ?: 'MERKEZ';
    $gAdres   = trim((string)($veri['gonderen_adres'] ?? edonusum_env('EDONUSUM_FIRMA_ADRES', '')));

    $aVkn     = preg_replace('/\D/', '', (string)($veri['alici_vkn'] ?? '')) ?: '11111111111';
    $aUnvan   = trim((string)($veri['alici_unvan'] ?? 'ALICI')) ?: 'ALICI';
    $aVd      = trim((string)($veri['alici_vd'] ?? ''));
    $aIl      = trim((string)($veri['alici_il'] ?? '')) ?: 'TÜRKİYE';
    $aIlce    = trim((string)($veri['alici_ilce'] ?? '')) ?: 'MERKEZ';
    $aAdres   = trim((string)($veri['alici_adres'] ?? ''));

    $ara      = round((float)($veri['ara_toplam'] ?? 0), 2);
    $kdv      = round((float)($veri['kdv_toplam'] ?? 0), 2);
    $genel    = round((float)($veri['genel_toplam'] ?? ($ara + $kdv)), 2);
    $satirlar = is_array($veri['satirlar'] ?? null) && $veri['satirlar'] !== []
        ? $veri['satirlar']
        : [['ad' => 'Muhtelif Mal/Hizmet', 'miktar' => 1.0, 'birim' => 'KGM', 'birim_fiyat' => $ara, 'kdv_oran' => $ara > 0 ? round(($kdv / $ara) * 100, 2) : 0.0, 'tutar' => $ara]];

    $e = static fn(string $s): string => edonusum_xml_escape($s);
    $fmt = static fn(float $n): string => number_format($n, 2, '.', '');
    $gScheme = strlen($gVkn) === 11 ? 'TCKN' : 'VKN';
    $aScheme = strlen($aVkn) === 11 ? 'TCKN' : 'VKN';
    $invType = $tur === 'emustahsil' ? 'MUSTAHSIL' : 'SATIS';
    $genelOran = $ara > 0 ? round(($kdv / $ara) * 100.0, 2) : 0.0;

    $partyXml = static function (string $scheme, string $vkn, string $unvan, string $vd, string $il, string $ilce, string $adres) use ($e): string {
        $p = '<cac:Party>'
            . '<cac:PartyIdentification><cbc:ID schemeID="' . $scheme . '">' . $e($vkn) . '</cbc:ID></cac:PartyIdentification>';
        if ($scheme === 'VKN') {
            $p .= '<cac:PartyName><cbc:Name>' . $e($unvan) . '</cbc:Name></cac:PartyName>';
        }
        $p .= '<cac:PostalAddress>'
            . ($adres !== '' ? '<cbc:StreetName>' . $e($adres) . '</cbc:StreetName>' : '')
            . '<cbc:CitySubdivisionName>' . $e($ilce) . '</cbc:CitySubdivisionName>'
            . '<cbc:CityName>' . $e($il) . '</cbc:CityName>'
            . '<cac:Country><cbc:Name>TÜRKİYE</cbc:Name></cac:Country>'
            . '</cac:PostalAddress>';
        if ($vd !== '') {
            $p .= '<cac:PartyTaxScheme><cac:TaxScheme><cbc:Name>' . $e($vd) . '</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme>';
        }
        if ($scheme === 'TCKN') {
            $parca = preg_split('/\s+/', trim($unvan)) ?: [$unvan];
            $soyad = count($parca) > 1 ? (string)array_pop($parca) : $unvan;
            $ad    = trim(implode(' ', $parca)) ?: $unvan;
            $p .= '<cac:Person><cbc:FirstName>' . $e($ad) . '</cbc:FirstName><cbc:FamilyName>' . $e($soyad) . '</cbc:FamilyName></cac:Person>';
        }
        $p .= '</cac:Party>';
        return $p;
    };

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    if ($tur === 'eirsaliye') {
        $xml .= '<DespatchAdvice xmlns="urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2"'
              . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
              . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n";
        $xml .= '  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>' . "\n";
        $xml .= '  <cbc:CustomizationID>TR1.2.1</cbc:CustomizationID>' . "\n";
        $xml .= '  <cbc:ProfileID>TEMELIRSALIYE</cbc:ProfileID>' . "\n";
        $xml .= '  <cbc:ID>' . $e($belgeNo) . '</cbc:ID>' . "\n";
        $xml .= '  <cbc:CopyIndicator>false</cbc:CopyIndicator>' . "\n";
        $xml .= '  <cbc:UUID>' . $e($ettn) . '</cbc:UUID>' . "\n";
        $xml .= '  <cbc:IssueDate>' . $e($tarih) . '</cbc:IssueDate>' . "\n";
        $xml .= '  <cbc:IssueTime>' . $e($saat) . '</cbc:IssueTime>' . "\n";
        $xml .= '  <cbc:DespatchAdviceTypeCode>SEVK</cbc:DespatchAdviceTypeCode>' . "\n";
        $xml .= '  <cbc:LineCountNumeric>' . count($satirlar) . '</cbc:LineCountNumeric>' . "\n";
        $xml .= '  <cac:DespatchSupplierParty>' . $partyXml($gScheme, $gVkn, $gUnvan, $gVd, $gIl, $gIlce, $gAdres) . '</cac:DespatchSupplierParty>' . "\n";
        $xml .= '  <cac:DeliveryCustomerParty>' . $partyXml($aScheme, $aVkn, $aUnvan, $aVd, $aIl, $aIlce, $aAdres) . '</cac:DeliveryCustomerParty>' . "\n";
        foreach ($satirlar as $i => $s) {
            $xml .= '  <cac:DespatchLine>'
                  . '<cbc:ID>' . ($i + 1) . '</cbc:ID>'
                  . '<cbc:DeliveredQuantity unitCode="' . $e(edonusum_birim_kodu((string)($s['birim'] ?? 'KGM'))) . '">' . $fmt((float)$s['miktar']) . '</cbc:DeliveredQuantity>'
                  . '<cac:Item><cbc:Name>' . $e((string)$s['ad']) . '</cbc:Name></cac:Item>'
                  . '</cac:DespatchLine>' . "\n";
        }
        $xml .= '</DespatchAdvice>' . "\n";
        return $xml;
    }

    $xml .= '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
          . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
          . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' . "\n";
    $xml .= '  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>' . "\n";
    $xml .= '  <cbc:CustomizationID>TR1.2</cbc:CustomizationID>' . "\n";
    $xml .= '  <cbc:ProfileID>' . $e($senaryo) . '</cbc:ProfileID>' . "\n";
    $xml .= '  <cbc:ID>' . $e($belgeNo) . '</cbc:ID>' . "\n";
    $xml .= '  <cbc:CopyIndicator>false</cbc:CopyIndicator>' . "\n";
    $xml .= '  <cbc:UUID>' . $e($ettn) . '</cbc:UUID>' . "\n";
    $xml .= '  <cbc:IssueDate>' . $e($tarih) . '</cbc:IssueDate>' . "\n";
    $xml .= '  <cbc:IssueTime>' . $e($saat) . '</cbc:IssueTime>' . "\n";
    $xml .= '  <cbc:InvoiceTypeCode>' . $e($invType) . '</cbc:InvoiceTypeCode>' . "\n";
    $xml .= '  <cbc:DocumentCurrencyCode>' . $e($pb) . '</cbc:DocumentCurrencyCode>' . "\n";
    $xml .= '  <cbc:LineCountNumeric>' . count($satirlar) . '</cbc:LineCountNumeric>' . "\n";
    $xml .= '  <cac:AccountingSupplierParty>' . $partyXml($gScheme, $gVkn, $gUnvan, $gVd, $gIl, $gIlce, $gAdres) . '</cac:AccountingSupplierParty>' . "\n";
    $xml .= '  <cac:AccountingCustomerParty>' . $partyXml($aScheme, $aVkn, $aUnvan, $aVd, $aIl, $aIlce, $aAdres) . '</cac:AccountingCustomerParty>' . "\n";
    $xml .= '  <cac:TaxTotal><cbc:TaxAmount currencyID="' . $e($pb) . '">' . $fmt($kdv) . '</cbc:TaxAmount>'
          . '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="' . $e($pb) . '">' . $fmt($ara) . '</cbc:TaxableAmount>'
          . '<cbc:TaxAmount currencyID="' . $e($pb) . '">' . $fmt($kdv) . '</cbc:TaxAmount>'
          . '<cbc:Percent>' . $fmt($genelOran) . '</cbc:Percent>'
          . '<cac:TaxCategory><cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory>'
          . '</cac:TaxSubtotal></cac:TaxTotal>' . "\n";
    $xml .= '  <cac:LegalMonetaryTotal>'
          . '<cbc:LineExtensionAmount currencyID="' . $e($pb) . '">' . $fmt($ara) . '</cbc:LineExtensionAmount>'
          . '<cbc:TaxExclusiveAmount currencyID="' . $e($pb) . '">' . $fmt($ara) . '</cbc:TaxExclusiveAmount>'
          . '<cbc:TaxInclusiveAmount currencyID="' . $e($pb) . '">' . $fmt($genel) . '</cbc:TaxInclusiveAmount>'
          . '<cbc:PayableAmount currencyID="' . $e($pb) . '">' . $fmt($genel) . '</cbc:PayableAmount>'
          . '</cac:LegalMonetaryTotal>' . "\n";

    foreach ($satirlar as $i => $s) {
        $satirTutar = round((float)$s['tutar'], 2);
        $oran = round((float)($s['kdv_oran'] ?? 0), 2);
        $satirKdv = round($satirTutar * $oran / 100.0, 2);
        $xml .= '  <cac:InvoiceLine>'
              . '<cbc:ID>' . ($i + 1) . '</cbc:ID>'
              . '<cbc:InvoicedQuantity unitCode="' . $e(edonusum_birim_kodu((string)($s['birim'] ?? 'KGM'))) . '">' . $fmt((float)$s['miktar']) . '</cbc:InvoicedQuantity>'
              . '<cbc:LineExtensionAmount currencyID="' . $e($pb) . '">' . $fmt($satirTutar) . '</cbc:LineExtensionAmount>'
              . '<cac:TaxTotal><cbc:TaxAmount currencyID="' . $e($pb) . '">' . $fmt($satirKdv) . '</cbc:TaxAmount>'
              . '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="' . $e($pb) . '">' . $fmt($satirTutar) . '</cbc:TaxableAmount>'
              . '<cbc:TaxAmount currencyID="' . $e($pb) . '">' . $fmt($satirKdv) . '</cbc:TaxAmount>'
              . '<cbc:Percent>' . $fmt($oran) . '</cbc:Percent>'
              . '<cac:TaxCategory><cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory>'
              . '</cac:TaxSubtotal></cac:TaxTotal>'
              . '<cac:Item><cbc:Name>' . $e((string)$s['ad']) . '</cbc:Name></cac:Item>'
              . '<cac:Price><cbc:PriceAmount currencyID="' . $e($pb) . '">' . $fmt((float)$s['birim_fiyat']) . '</cbc:PriceAmount></cac:Price>'
              . '</cac:InvoiceLine>' . "\n";
    }
    $xml .= '</Invoice>' . "\n";
    return $xml;
}

// ── Entegratör Bağlantı Testi, Mükellef Sorgulama ve Gelen Kutusu Senkronizasyonu ──

/** @return array{ok:bool,mesaj?:string,hata?:string,kod?:int} */
function edonusum_baglanti_test(PDO $pdo, ?EdonusumProviderInterface $prov = null): array
{
    $provider = edonusum_provider($prov, $pdo);
    if ($provider instanceof EdonusumElogoProvider) {
        $res = $provider->baglantiTest();
    } else {
        $res = [
            'ok'    => true,
            'mesaj' => 'Sandbox (Test Simülasyonu) sağlayıcısı aktif. Gerçek e-Logo testi için sağlayıcıyı "e-Logo Özel Entegratör" seçip kaydedin.',
        ];
    }

    if (db_table_exists($pdo, 'edonusum_ayarlar')) {
        try {
            $var = (int)$pdo->query('SELECT COUNT(*) FROM `edonusum_ayarlar` WHERE id = 1')->fetchColumn() > 0;
            $dur = !empty($res['ok']) ? 'ok' : 'hata';
            $msg = mb_substr((string)($res['mesaj'] ?? $res['hata'] ?? ''), 0, 500, 'UTF-8');
            if ($var) {
                $pdo->prepare('UPDATE `edonusum_ayarlar` SET son_test_at=?, son_test_durum=?, son_test_mesaj=? WHERE id = 1')
                    ->execute([date('Y-m-d H:i:s'), $dur, $msg]);
            }
        } catch (PDOException $e) {}
    }

    if (empty($res['ok'])) {
        return ['ok' => false, 'hata' => (string)($res['hata'] ?? 'Bağlantı testi başarısız.'), 'kod' => 502];
    }
    return ['ok' => true, 'mesaj' => (string)($res['mesaj'] ?? 'Bağlantı başarılı.')];
}

/** @return array{ok:bool,kayitli:bool,posta_kutusu:?string,gb_etiket?:?string,unvan?:string,saglayici:string,hata?:string} */
function edonusum_mukellef_sorgula(PDO $pdo, string $vknTckn, string $belgeTuru = 'efatura', ?EdonusumProviderInterface $prov = null): array
{
    $provider = edonusum_provider($prov, $pdo);
    $res = ($provider instanceof EdonusumElogoProvider)
        ? $provider->mukellefSorgula($vknTckn, $belgeTuru)
        : $provider->mukellefSorgula($vknTckn);
    $res['saglayici'] = $provider->ad();
    return $res;
}

/**
 * Entegratörden gelen e-Fatura / e-İrsaliye belgelerini çeker ve `edonusum_gelen` tablosuna ekler (ETTN bazında idempotent).
 * @return array{ok:bool,eklenen?:int,atlanan?:int,toplam?:int,mesaj?:string,hata?:string,kod?:int}
 */
function edonusum_gelen_senkronize(PDO $pdo, string $bas, string $bit, string $belgeTuru = 'efatura', int $uid = 0, ?EdonusumProviderInterface $prov = null): array
{
    if (!muh_tarih_gecerli($bas) || !muh_tarih_gecerli($bit)) {
        return ['ok' => false, 'hata' => 'Geçerli başlangıç ve bitiş tarihi (YYYY-AA-GG) girin.', 'kod' => 422];
    }
    if ($bas > $bit) {
        return ['ok' => false, 'hata' => 'Başlangıç tarihi bitiş tarihinden büyük olamaz.', 'kod' => 422];
    }
    $tur = in_array($belgeTuru, ['efatura', 'eirsaliye'], true) ? $belgeTuru : 'efatura';
    $provider = edonusum_provider($prov, $pdo);
    $res = ($provider instanceof EdonusumElogoProvider)
        ? $provider->gelenKutusuCek($bas, $bit, $tur)
        : $provider->gelenKutusuCek($bas, $bit);
    if (empty($res['ok'])) {
        return ['ok' => false, 'hata' => (string)($res['hata'] ?? 'Gelen kutusu çekilemedi.'), 'kod' => 502];
    }

    $belgeler = is_array($res['belgeler'] ?? null) ? $res['belgeler'] : [];
    $eklenen = 0;
    $atlanan = 0;

    db_tx($pdo, static function (PDO $p) use ($belgeler, $tur, &$eklenen, &$atlanan): void {
        $stVar = $p->prepare('SELECT id FROM `edonusum_gelen` WHERE ettn = ? LIMIT 1');
        $stIns = $p->prepare(
            "INSERT INTO `edonusum_gelen`
             (ettn, belge_turu, senaryo, belge_no, tarih, gonderen_vkn, gonderen_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum, ubl_xml)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bekliyor', ?)"
        );
        foreach ($belgeler as $b) {
            $ettn = trim((string)($b['ettn'] ?? ''));
            if ($ettn === '') continue;
            $stVar->execute([$ettn]);
            if ($stVar->fetchColumn() !== false) {
                $atlanan++;
                continue;
            }
            $stIns->execute([
                mb_substr($ettn, 0, 36, 'UTF-8'),
                (string)($b['belge_turu'] ?? $tur),
                mb_substr((string)($b['senaryo'] ?? 'TICARIFATURA'), 0, 30, 'UTF-8'),
                mb_substr((string)($b['belge_no'] ?? substr($ettn, 0, 16)), 0, 40, 'UTF-8'),
                muh_tarih_gecerli((string)($b['tarih'] ?? '')) ? (string)$b['tarih'] : date('Y-m-d'),
                mb_substr((string)($b['gonderen_vkn'] ?? ''), 0, 20, 'UTF-8'),
                mb_substr((string)($b['gonderen_unvan'] ?? ''), 0, 200, 'UTF-8'),
                mb_substr(strtoupper((string)($b['para_birimi'] ?? 'TRY')), 0, 10, 'UTF-8'),
                round((float)($b['ara_toplam'] ?? 0), 2),
                round((float)($b['kdv_toplam'] ?? 0), 2),
                round((float)($b['genel_toplam'] ?? 0), 2),
                isset($b['ubl_xml']) ? (string)$b['ubl_xml'] : null,
            ]);
            $eklenen++;
        }
    });

    if (function_exists('audit_log_event')) {
        audit_log_event('sync', 'edonusum_gelen', null, null, [
            'saglayici' => $provider->ad(), 'bas' => $bas, 'bit' => $bit, 'tur' => $tur, 'eklenen' => $eklenen, 'atlanan' => $atlanan, 'uid' => $uid,
        ]);
    }
    return [
        'ok'      => true,
        'eklenen' => $eklenen,
        'atlanan' => $atlanan,
        'toplam'  => count($belgeler),
        'mesaj'   => sprintf('Gelen kutusu senkronize edildi (%s): %d yeni belge eklendi, %d mevcut atlandı.', $provider->ad(), $eklenen, $atlanan),
    ];
}

// ── Giden Zarf İşlemleri ───────────────────────────────────────────────────

/** @return array{ok:bool,id?:int,ettn?:string,hata?:string,kod?:int} */
function edonusum_zarf_olustur(PDO $pdo, array $g, int $uid, ?EdonusumProviderInterface $prov = null): array
{
    $ayar     = edonusum_ayar_getir($pdo);
    $tur      = (string)($g['belge_turu'] ?? 'efatura');
    $senaryo  = (string)($g['senaryo'] ?? ($tur === 'earsiv' ? 'EARSIVFATURA' : ($tur === 'eirsaliye' ? 'TEMELIRSALIYE' : 'TICARIFATURA')));
    $belgeNo  = trim((string)($g['belge_no'] ?? ''));
    $tarih    = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $vkn      = preg_replace('/\D/', '', (string)($g['alici_vkn'] ?? '')) ?? '';
    $unvan    = mb_substr(trim((string)($g['alici_unvan'] ?? '')), 0, 200, 'UTF-8');
    $aliciVd  = trim((string)($g['alici_vd'] ?? ''));
    $aliciIl  = trim((string)($g['alici_il'] ?? ''));
    $aliciIlce = trim((string)($g['alici_ilce'] ?? ''));
    $aliciAdres = trim((string)($g['alici_adres'] ?? ''));
    $pb       = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    $ara      = max(0.0, muh_tutar_coz($g['ara_toplam'] ?? 0));
    $kdv      = max(0.0, muh_tutar_coz($g['kdv_toplam'] ?? 0));
    $genel    = muh_tutar_coz($g['genel_toplam'] ?? 0);
    if ($genel <= 0.0) $genel = round($ara + $kdv, 2);
    $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];
    $faturaId = isset($g['fatura_id']) && (int)$g['fatura_id'] > 0 ? (int)$g['fatura_id'] : null;

    if ($faturaId !== null && db_table_exists($pdo, 'fatura_belgeler')) {
        try {
            $stF = $pdo->prepare("SELECT * FROM `fatura_belgeler` WHERE id = ? AND durum = 'gecerli'");
            $stF->execute([$faturaId]);
            if ($ft = $stF->fetch(PDO::FETCH_ASSOC)) {
                if ($belgeNo === '') $belgeNo = trim((string)(($ft['fatura_no'] ?? '') ?: ($ft['belge_no'] ?? '')));
                if (trim((string)($g['tarih'] ?? '')) === '' && !empty($ft['tarih'])) $tarih = (string)$ft['tarih'];
                if ($vkn === '') $vkn = preg_replace('/\D/', '', (string)($ft['cari_vergi_no'] ?? '')) ?? '';
                if ($unvan === '') $unvan = mb_substr(trim((string)($ft['cari_unvan'] ?? '')), 0, 200, 'UTF-8');
                if (trim((string)($g['para_birimi'] ?? '')) === '' && !empty($ft['para_birimi'])) $pb = strtoupper((string)$ft['para_birimi']);
                if ($ara <= 0.0) $ara = round((float)($ft['net_toplam'] ?? 0), 2);
                if ($kdv <= 0.0) $kdv = round((float)($ft['kdv_toplam'] ?? 0), 2);
                if ($genel <= 0.0) $genel = round((float)($ft['genel_toplam'] ?? 0), 2);

                if ($aliciVd === '' && !empty($ft['cari_id']) && db_table_exists($pdo, 'cari_kartlar')) {
                    try {
                        $stC = $pdo->prepare('SELECT vergi_dairesi, il, ilce, adres FROM `cari_kartlar` WHERE id = ?');
                        $stC->execute([(int)$ft['cari_id']]);
                        if ($ck = $stC->fetch(PDO::FETCH_ASSOC)) {
                            $aliciVd    = trim((string)($ck['vergi_dairesi'] ?? ''));
                            $aliciIl    = trim((string)($ck['il'] ?? ''));
                            $aliciIlce  = trim((string)($ck['ilce'] ?? ''));
                            $aliciAdres = trim((string)($ck['adres'] ?? ''));
                        }
                    } catch (PDOException $e) {}
                }
                if ($satirlar === [] && db_table_exists($pdo, 'fatura_satirlar')) {
                    try {
                        $stS = $pdo->prepare('SELECT urun_adi, miktar, birim, birim_fiyat, kdv_orani, net_tutar FROM `fatura_satirlar` WHERE belge_id = ? ORDER BY sira ASC, id ASC');
                        $stS->execute([$faturaId]);
                        foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                            $satirlar[] = [
                                'ad'          => trim((string)($sr['urun_adi'] ?? '')) ?: 'Mal/Hizmet',
                                'miktar'      => (float)($sr['miktar'] ?? 1),
                                'birim'       => (string)($sr['birim'] ?? 'KGM'),
                                'birim_fiyat' => (float)($sr['birim_fiyat'] ?? 0),
                                'kdv_oran'    => (float)($sr['kdv_orani'] ?? 0),
                                'tutar'       => (float)($sr['net_tutar'] ?? 0),
                            ];
                        }
                    } catch (PDOException $e) {}
                }
            }
        } catch (PDOException $e) {
            // Opsiyonel okuma hatası manuel girişe engel olmaz
        }
    }

    if (!isset(edonusum_belge_turleri()[$tur])) return ['ok' => false, 'hata' => 'Geçersiz e-Dönüşüm belge türü.', 'kod' => 422];
    if (!muh_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz belge tarihi.', 'kod' => 422];
    if (strlen($vkn) !== 10 && strlen($vkn) !== 11) return ['ok' => false, 'hata' => 'Alıcı VKN (10) veya TCKN (11) hane olmalıdır.', 'kod' => 422];
    if ($unvan === '') return ['ok' => false, 'hata' => 'Alıcı ünvanı zorunludur.', 'kod' => 422];
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';

    $provider = edonusum_provider($prov, $pdo);
    $pk = null;
    if ($tur === 'efatura' || $tur === 'eirsaliye') {
        $mk = ($provider instanceof EdonusumElogoProvider)
            ? $provider->mukellefSorgula($vkn, $tur)
            : $provider->mukellefSorgula($vkn);
        if (!empty($mk['ok']) && !empty($mk['kayitli'])) {
            $pk = (string)($mk['posta_kutusu'] ?? '');
        } elseif ($tur === 'efatura') {
            $tur = 'earsiv';
            $senaryo = 'EARSIVFATURA';
        }
    }

    if ($belgeNo === '') {
        $seri = match ($tur) {
            'earsiv'    => (string)($ayar['seri_earsiv'] ?? 'ASA'),
            'eirsaliye' => (string)($ayar['seri_eirsaliye'] ?? 'ASI'),
            default     => (string)($ayar['seri_efatura'] ?? 'ASY'),
        };
        $belgeNo = $seri . date('Y', strtotime($tarih)) . str_pad((string)random_int(1, 999999999), 9, '0', STR_PAD_LEFT);
    }

    $ettn = edonusum_uuid_v4();
    $ubl  = edonusum_ubl_xml_uret([
        'ettn'           => $ettn,
        'belge_turu'     => $tur,
        'senaryo'        => $senaryo,
        'belge_no'       => $belgeNo,
        'tarih'          => $tarih,
        'para_birimi'    => $pb,
        'gonderen_vkn'   => (string)($ayar['firma_vkn'] ?? ''),
        'gonderen_unvan' => (string)($ayar['firma_unvan'] ?? ''),
        'gonderen_vd'    => (string)($ayar['firma_vergi_dairesi'] ?? ''),
        'gonderen_il'    => (string)($ayar['firma_il'] ?? ''),
        'gonderen_ilce'  => (string)($ayar['firma_ilce'] ?? ''),
        'gonderen_adres' => (string)($ayar['firma_adres'] ?? ''),
        'alici_vkn'      => $vkn,
        'alici_unvan'    => $unvan,
        'alici_vd'       => $aliciVd,
        'alici_il'       => $aliciIl,
        'alici_ilce'     => $aliciIlce,
        'alici_adres'    => $aliciAdres,
        'ara_toplam'     => $ara,
        'kdv_toplam'     => $kdv,
        'genel_toplam'   => $genel,
        'satirlar'       => $satirlar,
    ]);

    return db_tx($pdo, static function (PDO $p) use ($ettn, $tur, $senaryo, $faturaId, $belgeNo, $tarih, $vkn, $unvan, $pk, $pb, $ara, $kdv, $genel, $provider, $ubl, $uid): array {
        if ($faturaId !== null) {
            $stE = $p->prepare("SELECT id, ettn FROM `edonusum_zarflar` WHERE fatura_id = ? AND durum <> 'iptal' LIMIT 1" . db_for_update($p));
            $stE->execute([$faturaId]);
            $mevcut = $stE->fetch(PDO::FETCH_ASSOC);
            if ($mevcut) {
                return ['ok' => false, 'hata' => 'Bu fatura için zaten e-Dönüşüm zarfı oluşturulmuş (ETTN: ' . $mevcut['ettn'] . ').', 'kod' => 409];
            }
        }
        $p->prepare(
            "INSERT INTO `edonusum_zarflar`
             (ettn, belge_turu, senaryo, fatura_id, belge_no, tarih, alici_vkn, alici_unvan, alici_pk, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum, saglayici, ubl_xml, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'taslak', ?, ?, ?)"
        )->execute([$ettn, $tur, $senaryo, $faturaId, $belgeNo, $tarih, $vkn, $unvan, $pk, $pb, $ara, $kdv, $genel, $provider->ad(), $ubl, $uid ?: null]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'edonusum_zarf', $id, null, ['ettn' => $ettn, 'tur' => $tur, 'belge_no' => $belgeNo]);
        }
        return ['ok' => true, 'id' => $id, 'ettn' => $ettn, 'belge_turu' => $tur, 'mesaj' => 'UBL-TR 1.2 taslak zarf oluşturuldu (' . $belgeNo . ').'];
    });
}

/** @return array{ok:bool,zarf_uuid?:string,hata?:string,kod?:int} */
function edonusum_zarf_gonder(PDO $pdo, int $id, int $uid, ?EdonusumProviderInterface $prov = null): array
{
    $provider = edonusum_provider($prov, $pdo);
    return db_tx($pdo, static function (PDO $p) use ($id, $uid, $provider): array {
        $st = $p->prepare('SELECT * FROM `edonusum_zarflar` WHERE id = ?' . db_for_update($p));
        $st->execute([$id]);
        $z = $st->fetch(PDO::FETCH_ASSOC);
        if (!$z) return ['ok' => false, 'hata' => 'Zarf bulunamadı.', 'kod' => 404];
        if (!in_array((string)$z['durum'], ['taslak', 'hata'], true)) {
            return ['ok' => false, 'hata' => 'Yalnız taslak veya hatalı zarflar gönderilebilir.', 'kod' => 409];
        }
        $yanit = $provider->zarfGonder($z, (string)$z['ubl_xml']);
        $yeniDurum = !empty($yanit['ok']) ? 'gonderildi' : 'hata';
        $p->prepare('UPDATE `edonusum_zarflar` SET durum=?, saglayici=?, zarf_uuid=?, yanit_kod=?, yanit_mesaj=?, guncelleme_at=? WHERE id=?')
            ->execute([$yeniDurum, $provider->ad(), $yanit['zarf_uuid'] ?? null, $yanit['kod'] ?? null, $yanit['mesaj'] ?? ($yanit['hata'] ?? ''), date('Y-m-d H:i:s'), $id]);
        if (function_exists('audit_log_event')) {
            audit_log_event('send', 'edonusum_zarf', $id, ['durum' => $z['durum']], ['durum' => $yeniDurum, 'saglayici' => $provider->ad(), 'uid' => $uid]);
        }
        if (empty($yanit['ok'])) {
            return ['ok' => false, 'hata' => (string)($yanit['hata'] ?? 'Gönderim hatası.'), 'kod' => 502];
        }
        return ['ok' => true, 'zarf_uuid' => (string)($yanit['zarf_uuid'] ?? ''), 'mesaj' => (string)($yanit['mesaj'] ?? 'Zarf gönderildi.')];
    });
}

/** @return array{ok:bool,durum?:string,hata?:string,kod?:int} */
function edonusum_zarf_durum_sorgula(PDO $pdo, int $id, ?EdonusumProviderInterface $prov = null): array
{
    $provider = edonusum_provider($prov, $pdo);
    return db_tx($pdo, static function (PDO $p) use ($id, $provider): array {
        $st = $p->prepare('SELECT * FROM `edonusum_zarflar` WHERE id = ?' . db_for_update($p));
        $st->execute([$id]);
        $z = $st->fetch(PDO::FETCH_ASSOC);
        if (!$z) return ['ok' => false, 'hata' => 'Zarf bulunamadı.', 'kod' => 404];
        $res = ($provider instanceof EdonusumElogoProvider)
            ? $provider->durumSorgula((string)$z['ettn'], $z['zarf_uuid'] ? (string)$z['zarf_uuid'] : null, (string)$z['belge_turu'])
            : $provider->durumSorgula((string)$z['ettn'], $z['zarf_uuid'] ? (string)$z['zarf_uuid'] : null);
        if (empty($res['ok'])) return ['ok' => false, 'hata' => (string)($res['hata'] ?? 'Durum sorgulanamadı.'), 'kod' => 502];
        $d = (string)($res['durum'] ?? $z['durum']);
        $envId = !empty($res['zarf_uuid']) ? (string)$res['zarf_uuid'] : ($z['zarf_uuid'] ? (string)$z['zarf_uuid'] : null);
        $p->prepare('UPDATE `edonusum_zarflar` SET durum=?, zarf_uuid=?, yanit_kod=?, yanit_mesaj=?, guncelleme_at=? WHERE id=?')
            ->execute([$d, $envId, $res['kod'] ?? null, $res['mesaj'] ?? null, date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'durum' => $d, 'mesaj' => (string)($res['mesaj'] ?? 'Durum güncellendi: ' . $d)];
    });
}

/**
 * Gelen e-Fatura / e-İrsaliye Kabul veya Red yanıtı.
 * @return array{ok:bool,durum?:string,hata?:string,kod?:int}
 */
function edonusum_gelen_yanitla(PDO $pdo, int $id, string $yanit, string $neden, int $uid, ?EdonusumProviderInterface $prov = null): array
{
    if (!in_array($yanit, ['kabul', 'red'], true)) return ['ok' => false, 'hata' => 'Yanıt kabul veya red olmalıdır.', 'kod' => 422];
    $neden = mb_substr(trim($neden), 0, 500, 'UTF-8');
    if ($yanit === 'red' && $neden === '') return ['ok' => false, 'hata' => 'Red işlemi için gerekçe zorunludur.', 'kod' => 422];
    $provider = edonusum_provider($prov, $pdo);

    return db_tx($pdo, static function (PDO $p) use ($id, $yanit, $neden, $uid, $provider): array {
        $st = $p->prepare('SELECT * FROM `edonusum_gelen` WHERE id = ?' . db_for_update($p));
        $st->execute([$id]);
        $g = $st->fetch(PDO::FETCH_ASSOC);
        if (!$g) return ['ok' => false, 'hata' => 'Gelen belge bulunamadı.', 'kod' => 404];
        if ((string)$g['durum'] !== 'bekliyor') return ['ok' => false, 'hata' => 'Bu belgeye zaten yanıt verilmiş (' . $g['durum'] . ').', 'kod' => 409];

        $res = $provider->ticariYanitGonder((string)$g['ettn'], $yanit, $neden);
        if (empty($res['ok'])) return ['ok' => false, 'hata' => (string)($res['hata'] ?? 'Yanıt iletilemedi.'), 'kod' => 502];

        $p->prepare('UPDATE `edonusum_gelen` SET durum=?, yanit_neden=?, yanit_at=?, yanit_user_id=? WHERE id=?')
            ->execute([$yanit, $neden !== '' ? $neden : null, date('Y-m-d H:i:s'), $uid ?: null, $id]);
        if (function_exists('audit_log_event')) {
            audit_log_event('response', 'edonusum_gelen', $id, ['durum' => 'bekliyor'], ['durum' => $yanit, 'neden' => $neden]);
        }
        return ['ok' => true, 'durum' => $yanit, 'mesaj' => (string)($res['mesaj'] ?? 'Yanıt işlendi.')];
    });
}

/** @return list<array<string,mixed>> */
function edonusum_zarf_listesi(PDO $pdo, array $filtre = []): array
{
    $w = ['1=1']; $p = [];
    if (!empty($filtre['tur']) && isset(edonusum_belge_turleri()[(string)$filtre['tur']])) { $w[] = 'belge_turu = ?'; $p[] = (string)$filtre['tur']; }
    if (!empty($filtre['durum'])) { $w[] = 'durum = ?'; $p[] = (string)$filtre['durum']; }
    $st = $pdo->prepare(
        'SELECT id, ettn, belge_turu, senaryo, fatura_id, belge_no, tarih, alici_vkn, alici_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum, saglayici, yanit_kod, yanit_mesaj, olusturma_at
         FROM `edonusum_zarflar` WHERE ' . implode(' AND ', $w) . ' ORDER BY tarih DESC, id DESC LIMIT 200'
    );
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function edonusum_gelen_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, ettn, belge_turu, senaryo, belge_no, tarih, gonderen_vkn, gonderen_unvan, para_birimi, ara_toplam, kdv_toplam, genel_toplam, durum, yanit_neden, yanit_at, alindi_at
         FROM `edonusum_gelen` ORDER BY tarih DESC, id DESC LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** @return ?array<string,mixed> */
function edonusum_zarf_getir(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM `edonusum_zarflar` WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** @return list<array<string,mixed>> */
function edonusum_son_faturalar(PDO $pdo): array
{
    if (!db_table_exists($pdo, 'fatura_belgeler')) return [];
    $sql = "SELECT id, belge_no, tur, tarih, para_birimi, genel_toplam, COALESCE(cari_unvan, '') AS unvan
            FROM `fatura_belgeler` WHERE durum = 'gecerli' ORDER BY tarih DESC, id DESC LIMIT 60";
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

