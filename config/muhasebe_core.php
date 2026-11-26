<?php
// =========================================================
// config/muhasebe_core.php — Genel Muhasebe (TDHP) Çekirdeği (ERP-1)
//
// Kapsam:
//   - Tek Düzen Hesap Planı (100–799) ağaç & standart tohum
//   - Mali Dönemler (açık / kapalı yıl kilidi)
//   - Yevmiye Fişleri & Satırları (INSERT-only, Borç = Alacak zorunlu, ters fişle iptal)
//   - Mizan, Muavin/Kebir, Gelir Tablosu, Bilanço, KDV Özeti
//   - Mali Yıl Kapanışı (6xx → 690 → 590/591)
//   - Alt Defter Otomatik Mahsup Köprüsü (Fatura / Kasa / Çek-Senet → Yevmiye, idempotent)
//
// Mimari Değişmezler:
//   - Doğrudan HTTP erişimi kapalı (404)
//   - Şema yalnız migrate.php (#genel-muhasebe) üzerinden kurulur; çalışma zamanında DDL yok
//   - Tüm yazmalar db_tx() + FOR UPDATE + audit_log_event() ile korunur
//   - Yalnız pozisyonel (?) parametreler kullanılır (SQLite + MySQL uyumlu)
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_tx_helpers.php';
require_once __DIR__ . '/muhasebe_roller.php';

// ── Yetki ve HTTP Yardımcıları ─────────────────────────────────────────────

function muh_can(string $eylem = 'read'): bool
{
    return muhasebe_modul_can('genel_muhasebe', $eylem);
}

function muh_kapi(string $eylem = 'read'): void
{
    if (!muh_can($eylem)) {
        if (function_exists('forbidden')) forbidden();
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function muh_no_store(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function muh_json_istek(): bool
{
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $acc = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $xhr || str_contains($acc, 'application/json');
}

function muh_geri_guvenli(string $geri, string $varsayilan = 'muh_yevmiye.php'): string
{
    $geri = trim($geri);
    if ($geri === '') return $varsayilan;
    $yol = explode('?', $geri, 2)[0];
    if (!preg_match('/^(muh_[a-z_]+|edonusum_[a-z_]+)\.php$/', $yol)) {
        return $varsayilan;
    }
    return $geri;
}

function muh_cevap(array $sonuc, string $geri, string $basariMesaj, bool $json): never
{
    $ok = !empty($sonuc['ok']);
    $kod = (int)($sonuc['kod'] ?? ($ok ? 200 : 422));
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        if ($ok && empty($sonuc['mesaj']) && $basariMesaj !== '') {
            $sonuc['mesaj'] = $basariMesaj;
        }
        echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (function_exists('set_flash')) {
        set_flash($ok ? 'success' : 'error', $ok ? ($sonuc['mesaj'] ?? $basariMesaj) : ($sonuc['hata'] ?? 'İşlem başarısız.'));
    }
    header('Location: ' . muh_geri_guvenli($geri));
    exit;
}

// ── Şema ve Migrasyon (Yalnız migrate.php) ─────────────────────────────────

/** @return list<string> */
function muh_tablolar(): array
{
    return ['muh_hesap_plani', 'muh_mali_donemler', 'muh_fisler', 'muh_fis_satirlar'];
}

/** @return list<string> */
function muh_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (muh_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $eksik[] = $t;
    }
    return $eksik;
}

function muh_hazir(PDO $pdo): bool
{
    return muh_eksik_tablolar($pdo) === [];
}

function muh_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = muh_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'hata'  => 'Genel Muhasebe tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#genel-muhasebe kartını çalıştırın.',
            'eksik' => $eksik,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('muh_sema_uyari')) {
        render_header('Genel Muhasebe');
        echo muh_sema_uyari($eksik, 'genel-muhasebe', 'Genel Muhasebe (TDHP)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Genel Muhasebe şeması kurulmamış.');
}

/**
 * Standart Türkiye Tek Düzen Hesap Planı (TDHP) tohum listesi.
 * [kod, ad, ust_kod, sinif, tip, bakiye_yonu, hareket_gorebilir]
 * @return list<array{0:string,1:string,2:?string,3:int,4:string,5:string,6:int}>
 */
function muh_tdhp_tohum(): array
{
    return [
        // 1 - DÖNEN VARLIKLAR
        ['100', 'KASA', null, 1, 'aktif', 'B', 1],
        ['100.01', 'TL Kasa', '100', 1, 'aktif', 'B', 1],
        ['100.02', 'Döviz Kasası', '100', 1, 'aktif', 'B', 1],
        ['101', 'ALINAN ÇEKLER', null, 1, 'aktif', 'B', 1],
        ['102', 'BANKALAR', null, 1, 'aktif', 'B', 1],
        ['102.01', 'TL Banka Hesapları', '102', 1, 'aktif', 'B', 1],
        ['102.02', 'Döviz Banka Hesapları', '102', 1, 'aktif', 'B', 1],
        ['103', 'VERİLEN ÇEKLER VE ÖDEME EMİRLERİ (-)', null, 1, 'aktif', 'A', 1],
        ['108', 'DİĞER HAZIR DEĞERLER (POS / K.KARTI)', null, 1, 'aktif', 'B', 1],
        ['120', 'ALICILAR', null, 1, 'aktif', 'B', 1],
        ['120.01', 'Yurtiçi Alıcılar', '120', 1, 'aktif', 'B', 1],
        ['120.02', 'Yurtdışı Alıcılar (İhracat)', '120', 1, 'aktif', 'B', 1],
        ['121', 'ALACAK SENETLERİ', null, 1, 'aktif', 'B', 1],
        ['126', 'VERİLEN DEPOZİTO VE TEMİNATLAR', null, 1, 'aktif', 'B', 1],
        ['131', 'ORTAKLARDAN ALACAKLAR', null, 1, 'aktif', 'B', 1],
        ['135', 'PERSONELDEN ALACAKLAR', null, 1, 'aktif', 'B', 1],
        ['150', 'İLK MADDE VE MALZEME (AMBALAJ)', null, 1, 'aktif', 'B', 1],
        ['151', 'YARI MAMULLER - ÜRETİM', null, 1, 'aktif', 'B', 1],
        ['152', 'MAMULLER', null, 1, 'aktif', 'B', 1],
        ['153', 'TİCARİ MALLAR', null, 1, 'aktif', 'B', 1],
        ['159', 'VERİLEN SİPARİŞ AVANSLARI', null, 1, 'aktif', 'B', 1],
        ['180', 'GELECEK AYLARA AİT GİDERLER', null, 1, 'aktif', 'B', 1],
        ['190', 'DEVREDEN KDV', null, 1, 'aktif', 'B', 1],
        ['191', 'İNDİRİLECEK KDV', null, 1, 'aktif', 'B', 1],
        ['191.01', 'İndirilecek KDV %1', '191', 1, 'aktif', 'B', 1],
        ['191.10', 'İndirilecek KDV %10', '191', 1, 'aktif', 'B', 1],
        ['191.20', 'İndirilecek KDV %20', '191', 1, 'aktif', 'B', 1],
        ['192', 'DİĞER KDV (İHRACAT İADE)', null, 1, 'aktif', 'B', 1],
        ['193', 'PEŞİN ÖDENEN VERGİLER VE FONLAR', null, 1, 'aktif', 'B', 1],
        ['195', 'İŞ AVANSLARI', null, 1, 'aktif', 'B', 1],
        ['196', 'PERSONEL AVANSLARI', null, 1, 'aktif', 'B', 1],

        // 2 - DURAN VARLIKLAR
        ['252', 'BİNALAR', null, 2, 'aktif', 'B', 1],
        ['253', 'TESİS, MAKİNE VE CİHAZLAR', null, 2, 'aktif', 'B', 1],
        ['254', 'TAŞITLAR', null, 2, 'aktif', 'B', 1],
        ['255', 'DEMİRBAŞLAR', null, 2, 'aktif', 'B', 1],
        ['257', 'BİRİKMİŞ AMORTİSMANLAR (-)', null, 2, 'aktif', 'A', 1],
        ['260', 'HAKLAR', null, 2, 'aktif', 'B', 1],
        ['268', 'BİRİKMİŞ AMORTİSMANLAR (-)', null, 2, 'aktif', 'A', 1],

        // 3 - KISA VADELİ YABANCI KAYNAKLAR
        ['300', 'BANKA KREDİLERİ', null, 3, 'pasif', 'A', 1],
        ['309', 'DİĞER MALİ BORÇLAR (KREDİ KARTLARI)', null, 3, 'pasif', 'A', 1],
        ['320', 'SATICILAR', null, 3, 'pasif', 'A', 1],
        ['320.01', 'Yurtiçi Satıcılar / Müstahsiller', '320', 3, 'pasif', 'A', 1],
        ['320.02', 'Nakliye & Lojistik Tedarikçileri', '320', 3, 'pasif', 'A', 1],
        ['321', 'BORÇ SENETLERİ', null, 3, 'pasif', 'A', 1],
        ['326', 'ALINAN DEPOZİTO VE TEMİNATLAR', null, 3, 'pasif', 'A', 1],
        ['331', 'ORTAKLARA BORÇLAR', null, 3, 'pasif', 'A', 1],
        ['335', 'PERSONELE BORÇLAR', null, 3, 'pasif', 'A', 1],
        ['340', 'ALINAN SİPARİŞ AVANSLARI', null, 3, 'pasif', 'A', 1],
        ['360', 'ÖDENECEK VERGİ VE FONLAR', null, 3, 'pasif', 'A', 1],
        ['360.01', 'Ödenecek KDV', '360', 3, 'pasif', 'A', 1],
        ['360.02', 'Ödenecek Muhtasar / Stopaj', '360', 3, 'pasif', 'A', 1],
        ['361', 'ÖDENECEK SOSYAL GÜVENLİK KESİNTİLERİ', null, 3, 'pasif', 'A', 1],
        ['391', 'HESAPLANAN KDV', null, 3, 'pasif', 'A', 1],
        ['391.01', 'Hesaplanan KDV %1', '391', 3, 'pasif', 'A', 1],
        ['391.10', 'Hesaplanan KDV %10', '391', 3, 'pasif', 'A', 1],
        ['391.20', 'Hesaplanan KDV %20', '391', 3, 'pasif', 'A', 1],

        // 4 - UZUN VADELİ YABANCI KAYNAKLAR
        ['400', 'UZUN VADELİ BANKA KREDİLERİ', null, 4, 'pasif', 'A', 1],
        ['420', 'UZUN VADELİ SATICILAR', null, 4, 'pasif', 'A', 1],

        // 5 - ÖZ KAYNAKLAR
        ['500', 'SERMAYE', null, 5, 'pasif', 'A', 1],
        ['540', 'YASAL YEDEKLER', null, 5, 'pasif', 'A', 1],
        ['570', 'GEÇMİŞ YILLAR KÂRLARI', null, 5, 'pasif', 'A', 1],
        ['580', 'GEÇMİŞ YILLAR ZARARLARI (-)', null, 5, 'pasif', 'B', 1],
        ['590', 'DÖNEM NET KÂRI', null, 5, 'pasif', 'A', 1],
        ['591', 'DÖNEM NET ZARARI (-)', null, 5, 'pasif', 'B', 1],

        // 6 - GELİR TABLOSU HESAPLARI
        ['600', 'YURTİÇİ SATIŞLAR', null, 6, 'gelir', 'A', 1],
        ['601', 'YURTDIŞI SATIŞLAR (İHRACAT)', null, 6, 'gelir', 'A', 1],
        ['602', 'DİĞER GELİRLER', null, 6, 'gelir', 'A', 1],
        ['610', 'SATIŞTAN İADELER (-)', null, 6, 'gider', 'B', 1],
        ['611', 'SATIŞ İSKONTOLARI (-)', null, 6, 'gider', 'B', 1],
        ['612', 'DİĞER İNDİRİMLER (CLAIM / FİRE) (-)', null, 6, 'gider', 'B', 1],
        ['620', 'SATILAN MAMULLER MALİYETİ (-)', null, 6, 'gider', 'B', 1],
        ['621', 'SATILAN TİCARİ MALLAR MALİYETİ (-)', null, 6, 'gider', 'B', 1],
        ['622', 'SATILAN HİZMET MALİYETİ (-)', null, 6, 'gider', 'B', 1],
        ['631', 'PAZARLAMA SATIŞ VE DAĞITIM GİDERLERİ (-)', null, 6, 'gider', 'B', 1],
        ['632', 'GENEL YÖNETİM GİDERLERİ (-)', null, 6, 'gider', 'B', 1],
        ['642', 'FAİZ GELİRLERİ', null, 6, 'gelir', 'A', 1],
        ['646', 'KAMBİYO KÂRLARI', null, 6, 'gelir', 'A', 1],
        ['649', 'DİĞER OLAĞAN GELİR VE KÂRLAR', null, 6, 'gelir', 'A', 1],
        ['653', 'KOMİSYON GİDERLERİ (-)', null, 6, 'gider', 'B', 1],
        ['656', 'KAMBİYO ZARARLARI (-)', null, 6, 'gider', 'B', 1],
        ['659', 'DİĞER OLAĞAN GİDER VE ZARARLAR (-)', null, 6, 'gider', 'B', 1],
        ['660', 'KISA VADELİ BORÇLANMA GİDERLERİ (-)', null, 6, 'gider', 'B', 1],
        ['679', 'DİĞER OLAĞANDIŞI GELİR VE KÂRLAR', null, 6, 'gelir', 'A', 1],
        ['689', 'DİĞER OLAĞANDIŞI GİDER VE ZARARLAR (-)', null, 6, 'gider', 'B', 1],
        ['690', 'DÖNEM KÂRI VEYA ZARARI', null, 6, 'gelir', 'A', 1],
        ['692', 'DÖNEM NET KÂRI VEYA ZARARI', null, 6, 'gelir', 'A', 1],

        // 7 - MALİYET HESAPLARI (7/A)
        ['710', 'DİREKT İLK MADDE VE MALZEME GİDERLERİ', null, 7, 'maliyet', 'B', 1],
        ['720', 'DİREKT İŞÇİLİK GİDERLERİ', null, 7, 'maliyet', 'B', 1],
        ['730', 'GENEL ÜRETİM GİDERLERİ', null, 7, 'maliyet', 'B', 1],
        ['760', 'PAZARLAMA SATIŞ VE DAĞITIM GİDERLERİ', null, 7, 'maliyet', 'B', 1],
        ['770', 'GENEL YÖNETİM GİDERLERİ', null, 7, 'maliyet', 'B', 1],
        ['771', 'GENEL YÖNETİM GİDERLERİ YANSITMA', null, 7, 'maliyet', 'A', 1],
        ['780', 'FİNANSMAN GİDERLERİ', null, 7, 'maliyet', 'B', 1],
    ];
}

/**
 * Genel Muhasebe (TDHP) şemasını ve standart TDHP hesap planını idempotent kurar.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function muhasebe_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'muh_hesap_plani' => "CREATE TABLE IF NOT EXISTS `muh_hesap_plani` (
            `id` $ai,
            `kod` VARCHAR(32) NOT NULL UNIQUE,
            `ad` VARCHAR(180) NOT NULL,
            `ust_kod` VARCHAR(32) NULL,
            `sinif` TINYINT NOT NULL DEFAULT 1,
            `tip` VARCHAR(20) NOT NULL DEFAULT 'aktif',
            `bakiye_yonu` CHAR(1) NOT NULL DEFAULT 'B',
            `hareket_gorebilir` TINYINT NOT NULL DEFAULT 1,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `sistem` TINYINT NOT NULL DEFAULT 0,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'muh_mali_donemler' => "CREATE TABLE IF NOT EXISTS `muh_mali_donemler` (
            `id` $ai,
            `yil` INT NOT NULL UNIQUE,
            `baslangic` DATE NOT NULL,
            `bitis` DATE NOT NULL,
            `durum` VARCHAR(16) NOT NULL DEFAULT 'acik',
            `kapanis_fis_id` INT NULL,
            `kapatan_id` INT NULL,
            `kapandi_at` DATETIME NULL,
            `notlar` VARCHAR(500) NOT NULL DEFAULT '',
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'muh_fisler' => "CREATE TABLE IF NOT EXISTS `muh_fisler` (
            `id` $ai,
            `fis_no` VARCHAR(40) NOT NULL UNIQUE,
            `yevmiye_no` INT NOT NULL DEFAULT 0,
            `tarih` DATE NOT NULL,
            `tur` VARCHAR(20) NOT NULL DEFAULT 'mahsup',
            `aciklama` VARCHAR(500) NOT NULL DEFAULT '',
            `kaynak_modul` VARCHAR(40) NULL,
            `kaynak_id` INT NULL,
            `ters_fis_id` INT NULL,
            `durum` VARCHAR(16) NOT NULL DEFAULT 'onayli',
            `toplam_borc` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `toplam_alacak` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `depo` VARCHAR(150) NOT NULL DEFAULT '',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `iptal_eden_id` INT NULL,
            `iptal_at` DATETIME NULL,
            `iptal_neden` VARCHAR(500) NULL
        )$eng",
        'muh_fis_satirlar' => "CREATE TABLE IF NOT EXISTS `muh_fis_satirlar` (
            `id` $ai,
            `fis_id` INT NOT NULL,
            `sira_no` INT NOT NULL DEFAULT 1,
            `hesap_id` INT NOT NULL,
            `hesap_kod` VARCHAR(32) NOT NULL,
            `cari_id` INT NULL,
            `belge_no` VARCHAR(80) NOT NULL DEFAULT '',
            `aciklama` VARCHAR(400) NOT NULL DEFAULT '',
            `borc` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `alacak` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `kur` DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
            `doviz_tutar` DECIMAL(16,2) NOT NULL DEFAULT 0.00
        )$eng",
    ];

    $rapor = [];
    foreach ($ddl as $tablo => $sql) {
        $var = db_table_exists($pdo, $tablo);
        $pdo->exec($sql);
        $rapor[] = [
            'tablo' => $tablo,
            'durum' => $var ? 'var' : 'olusturuldu',
            'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.',
        ];
    }

    // İndeksler (idempotent)
    $indeksler = [
        ['muh_fisler',       'idx_muh_fis_tarih_durum', 'tarih, durum'],
        ['muh_fisler',       'idx_muh_fis_kaynak',      'kaynak_modul, kaynak_id'],
        ['muh_fis_satirlar', 'idx_muh_satir_fis',       'fis_id, sira_no'],
        ['muh_fis_satirlar', 'idx_muh_satir_hesap',     'hesap_kod, fis_id'],
        ['muh_hesap_plani',  'idx_muh_hp_sinif',        'sinif, aktif'],
    ];
    foreach ($indeksler as [$t, $ix, $kol]) {
        try {
            if ($sqlite) {
                $pdo->exec("CREATE INDEX IF NOT EXISTS `$ix` ON `$t` ($kol)");
            } else {
                $st = $pdo->prepare("SHOW INDEX FROM `$t` WHERE Key_name = ?");
                $st->execute([$ix]);
                if ($st->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE `$t` ADD INDEX `$ix` ($kol)");
                }
            }
        } catch (PDOException $e) {}
    }

    // TDHP standart hesap planı tohumu (idempotent)
    $eklenenHesap = 0;
    $insSql = $sqlite
        ? 'INSERT OR IGNORE INTO `muh_hesap_plani` (kod, ad, ust_kod, sinif, tip, bakiye_yonu, hareket_gorebilir, sistem, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)'
        : 'INSERT IGNORE INTO `muh_hesap_plani` (kod, ad, ust_kod, sinif, tip, bakiye_yonu, hareket_gorebilir, sistem, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)';
    $stIns = $pdo->prepare($insSql);
    foreach (muh_tdhp_tohum() as $h) {
        $stIns->execute([$h[0], $h[1], $h[2], $h[3], $h[4], $h[5], $h[6]]);
        $eklenenHesap += $stIns->rowCount();
    }
    $rapor[] = [
        'tablo' => 'tdhp_tohum',
        'durum' => $eklenenHesap > 0 ? 'eklendi' : 'var',
        'mesaj' => $eklenenHesap > 0 ? ($eklenenHesap . ' standart TDHP hesabı eklendi.') : 'Standart TDHP hesapları zaten yüklü.',
    ];

    // İçinde bulunulan mali yıl açık dönem olarak tohumlanır
    $yil = (int)date('Y');
    $donSql = $sqlite
        ? "INSERT OR IGNORE INTO `muh_mali_donemler` (yil, baslangic, bitis, durum, notlar) VALUES (?, ?, ?, 'acik', 'Otomatik başlangıç dönemi')"
        : "INSERT IGNORE INTO `muh_mali_donemler` (yil, baslangic, bitis, durum, notlar) VALUES (?, ?, ?, 'acik', 'Otomatik başlangıç dönemi')";
    $stDon = $pdo->prepare($donSql);
    $stDon->execute([$yil, $yil . '-01-01', $yil . '-12-31']);

    return $rapor;
}

// ── Yardımcılar ────────────────────────────────────────────────────────────

function muh_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $t);
    return $d !== false && $d->format('Y-m-d') === $t;
}

function muh_tutar_coz($v): float
{
    if (is_int($v) || is_float($v)) return round((float)$v, 2);
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_replace([' ', "\xc2\xa0"], '', $s);
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? round((float)$s, 2) : 0.0;
}

function muh_fis_turleri(): array
{
    return [
        'mahsup'    => 'Mahsup Fişi',
        'tahsil'    => 'Tahsil Fişi',
        'tediye'    => 'Tediye Fişi',
        'acilis'    => 'Açılış Fişi',
        'yansitma'  => 'Yansıtma Fişi',
        'kur_farki' => 'Kur Farkı Fişi',
        'kapanis'   => 'Kapanış Fişi',
    ];
}

function muh_hesap_tipleri(): array
{
    return [
        'aktif'   => 'Aktif (Varlık)',
        'pasif'   => 'Pasif (Kaynak)',
        'gelir'   => 'Gelir',
        'gider'   => 'Gider',
        'maliyet' => 'Maliyet (7/A)',
        'nazim'   => 'Nazım',
    ];
}

// ── Hesap Planı İşlemleri ──────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function muh_hesap_listesi(PDO $pdo, array $filtre = []): array
{
    $w = ['1=1'];
    $p = [];
    if (!empty($filtre['yalniz_aktif'])) {
        $w[] = 'aktif = 1';
    }
    if (!empty($filtre['yalniz_hareket'])) {
        $w[] = 'hareket_gorebilir = 1';
    }
    if (!empty($filtre['sinif']) && (int)$filtre['sinif'] >= 1 && (int)$filtre['sinif'] <= 9) {
        $w[] = 'sinif = ?';
        $p[] = (int)$filtre['sinif'];
    }
    $q = trim((string)($filtre['ara'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = '(kod LIKE ? OR ad LIKE ?)';
        $p[] = $like;
        $p[] = $like;
    }
    $st = $pdo->prepare('SELECT * FROM `muh_hesap_plani` WHERE ' . implode(' AND ', $w) . ' ORDER BY kod ASC');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{ok:bool,id?:int,hata?:string,kod?:int} */
function muh_hesap_kaydet(PDO $pdo, array $g, int $uid): array
{
    $id  = max(0, (int)($g['id'] ?? 0));
    $kod = trim((string)($g['kod'] ?? ''));
    $ad  = mb_substr(trim((string)($g['ad'] ?? '')), 0, 180, 'UTF-8');
    $ust = trim((string)($g['ust_kod'] ?? ''));
    $pb  = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $hareket = isset($g['hareket_gorebilir']) ? ((int)$g['hareket_gorebilir'] ? 1 : 0) : 1;

    if (!preg_match('/^[1-9]\d{2}(?:\.[0-9A-Za-z]{1,8}){0,4}$/', $kod)) {
        return ['ok' => false, 'hata' => 'Hesap kodu TDHP biçiminde olmalı (örn. 100, 120.01, 600.01.001).', 'kod' => 422];
    }
    if ($ad === '') {
        return ['ok' => false, 'hata' => 'Hesap adı zorunludur.', 'kod' => 422];
    }

    $sinif = (int)$kod[0];
    $varsTip = match (true) {
        $sinif <= 2 => 'aktif',
        $sinif <= 5 => 'pasif',
        $sinif === 6 => 'gelir',
        $sinif === 7 => 'maliyet',
        default => 'nazim',
    };
    $tip = (string)($g['tip'] ?? $varsTip);
    if (!isset(muh_hesap_tipleri()[$tip])) $tip = $varsTip;
    $yon = strtoupper((string)($g['bakiye_yonu'] ?? (in_array($tip, ['aktif', 'gider', 'maliyet'], true) ? 'B' : 'A')));
    if ($yon !== 'B' && $yon !== 'A') $yon = 'B';

    if ($ust === '' && str_contains($kod, '.')) {
        $ust = substr($kod, 0, (int)strrpos($kod, '.'));
    }
    $ustVal = $ust !== '' ? $ust : null;

    return db_tx($pdo, static function (PDO $p) use ($id, $kod, $ad, $ustVal, $sinif, $tip, $yon, $hareket, $pb, $uid): array {
        if ($id > 0) {
            $stE = $p->prepare('SELECT * FROM `muh_hesap_plani` WHERE id = ?' . db_for_update($p));
            $stE->execute([$id]);
            $eski = $stE->fetch(PDO::FETCH_ASSOC);
            if (!$eski) return ['ok' => false, 'hata' => 'Hesap kartı bulunamadı.', 'kod' => 404];
            if ((string)$eski['kod'] !== $kod) {
                $stC = $p->prepare('SELECT COUNT(*) FROM `muh_fis_satirlar` WHERE hesap_id = ?');
                $stC->execute([$id]);
                if ((int)$stC->fetchColumn() > 0) {
                    return ['ok' => false, 'hata' => 'Hareket görmüş hesabın kodu değiştirilemez.', 'kod' => 409];
                }
            }
            $stDup = $p->prepare('SELECT id FROM `muh_hesap_plani` WHERE kod = ? AND id <> ?');
            $stDup->execute([$kod, $id]);
            if ($stDup->fetchColumn()) return ['ok' => false, 'hata' => 'Bu hesap kodu zaten tanımlı.', 'kod' => 409];

            $p->prepare('UPDATE `muh_hesap_plani` SET kod=?, ad=?, ust_kod=?, sinif=?, tip=?, bakiye_yonu=?, hareket_gorebilir=?, para_birimi=? WHERE id=?')
                ->execute([$kod, $ad, $ustVal, $sinif, $tip, $yon, $hareket, $pb, $id]);
            if (function_exists('audit_log_event')) {
                audit_log_event('update', 'genel_muhasebe_hesap', $id, $eski, ['kod' => $kod, 'ad' => $ad, 'uid' => $uid]);
            }
            return ['ok' => true, 'id' => $id, 'mesaj' => 'Hesap kartı güncellendi.'];
        }

        $stDup = $p->prepare('SELECT id FROM `muh_hesap_plani` WHERE kod = ?');
        $stDup->execute([$kod]);
        if ($stDup->fetchColumn()) {
            return ['ok' => false, 'hata' => 'Bu hesap kodu zaten tanımlı: ' . $kod, 'kod' => 409];
        }
        $p->prepare('INSERT INTO `muh_hesap_plani` (kod, ad, ust_kod, sinif, tip, bakiye_yonu, hareket_gorebilir, para_birimi, sistem, aktif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)')
            ->execute([$kod, $ad, $ustVal, $sinif, $tip, $yon, $hareket, $pb]);
        $yeniId = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'genel_muhasebe_hesap', $yeniId, null, ['kod' => $kod, 'ad' => $ad, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $yeniId, 'mesaj' => 'Yeni hesap eklendi: ' . $kod];
    });
}

/** @return array{ok:bool,hata?:string,kod?:int} */
function muh_hesap_durum(PDO $pdo, int $id, int $aktif, int $uid): array
{
    $aktif = $aktif ? 1 : 0;
    return db_tx($pdo, static function (PDO $p) use ($id, $aktif, $uid): array {
        $st = $p->prepare('SELECT * FROM `muh_hesap_plani` WHERE id = ?' . db_for_update($p));
        $st->execute([$id]);
        $eski = $st->fetch(PDO::FETCH_ASSOC);
        if (!$eski) return ['ok' => false, 'hata' => 'Hesap bulunamadı.', 'kod' => 404];
        $p->prepare('UPDATE `muh_hesap_plani` SET aktif = ? WHERE id = ?')->execute([$aktif, $id]);
        if (function_exists('audit_log_event')) {
            audit_log_event('update', 'genel_muhasebe_hesap', $id, ['aktif' => $eski['aktif']], ['aktif' => $aktif, 'uid' => $uid]);
        }
        return ['ok' => true, 'mesaj' => $aktif ? 'Hesap aktifleştirildi.' : 'Hesap pasife alındı.'];
    });
}

// ── Mali Dönem Yönetimi ────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function muh_donem_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `muh_mali_donemler` ORDER BY yil DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function muh_donem_kapali_mi(PDO $pdo, string $tarih): bool
{
    $yil = (int)substr($tarih, 0, 4);
    if ($yil < 2000) return false;
    $st = $pdo->prepare("SELECT durum FROM `muh_mali_donemler` WHERE yil = ?");
    $st->execute([$yil]);
    return (string)$st->fetchColumn() === 'kapali';
}

/** @return array{ok:bool,id?:int,hata?:string} */
function muh_donem_ac(PDO $pdo, int $yil, int $uid): array
{
    if ($yil < 2000 || $yil > 2100) return ['ok' => false, 'hata' => 'Geçersiz mali yıl.'];
    return db_tx($pdo, static function (PDO $p) use ($yil, $uid): array {
        $st = $p->prepare('SELECT * FROM `muh_mali_donemler` WHERE yil = ?' . db_for_update($p));
        $st->execute([$yil]);
        $mevcut = $st->fetch(PDO::FETCH_ASSOC);
        if ($mevcut) {
            if ((string)$mevcut['durum'] === 'kapali') {
                return ['ok' => false, 'hata' => $yil . ' mali dönemi kapalıdır.', 'kod' => 409];
            }
            return ['ok' => true, 'id' => (int)$mevcut['id'], 'mesaj' => $yil . ' mali dönemi zaten açık.'];
        }
        $p->prepare("INSERT INTO `muh_mali_donemler` (yil, baslangic, bitis, durum, notlar) VALUES (?, ?, ?, 'acik', ?)")
            ->execute([$yil, $yil . '-01-01', $yil . '-12-31', 'Kullanıcı #' . $uid . ' tarafından açıldı']);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'genel_muhasebe_donem', $id, null, ['yil' => $yil]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => $yil . ' mali dönemi açıldı.'];
    });
}

// ── Yevmiye Fişleri (INSERT-Only, Borç = Alacak, Ters Fiş) ─────────────────

/**
 * Dengeli yevmiye fişi kaydeder.
 * @param array{tarih:string,tur?:string,aciklama?:string,depo?:string,kaynak_modul?:?string,kaynak_id?:?int,satirlar:list<array<string,mixed>>} $g
 * @return array{ok:bool,id?:int,fis_no?:string,yevmiye_no?:int,mukerrer?:bool,hata?:string,kod?:int}
 */
function muh_fis_kaydet(PDO $pdo, array $g, int $uid, bool $kapanisIzni = false): array
{
    $tarih    = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $tur      = trim((string)($g['tur'] ?? 'mahsup'));
    $aciklama = mb_substr(trim((string)($g['aciklama'] ?? '')), 0, 500, 'UTF-8');
    $depo     = trim((string)($g['depo'] ?? (function_exists('active_depot') ? (active_depot() ?? '') : '')));
    $kayMod   = isset($g['kaynak_modul']) && trim((string)$g['kaynak_modul']) !== '' ? mb_substr(trim((string)$g['kaynak_modul']), 0, 40, 'UTF-8') : null;
    $kayId    = isset($g['kaynak_id']) && (int)$g['kaynak_id'] > 0 ? (int)$g['kaynak_id'] : null;
    $satirlar = is_array($g['satirlar'] ?? null) ? $g['satirlar'] : [];

    if (!muh_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçerli bir fiş tarihi girin (YYYY-AA-GG).', 'kod' => 422];
    if (!isset(muh_fis_turleri()[$tur])) return ['ok' => false, 'hata' => 'Geçersiz fiş türü.', 'kod' => 422];
    if (count($satirlar) < 2) return ['ok' => false, 'hata' => 'Yevmiye fişinde en az 2 satır (borç ve alacak) bulunmalıdır.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($tarih, $tur, $aciklama, $depo, $kayMod, $kayId, $satirlar, $uid, $kapanisIzni): array {
        $yil = (int)substr($tarih, 0, 4);
        // Mali dönem kilidi kontrolü (FOR UPDATE)
        $stDon = $p->prepare('SELECT * FROM `muh_mali_donemler` WHERE yil = ?' . db_for_update($p));
        $stDon->execute([$yil]);
        $donem = $stDon->fetch(PDO::FETCH_ASSOC);
        if ($donem && (string)$donem['durum'] === 'kapali' && !$kapanisIzni) {
            return ['ok' => false, 'hata' => $yil . ' mali dönemi kapalıdır; bu tarihe fiş işlenemez.', 'kod' => 409];
        }

        // Alt defter kaynak idempotency kontrolü
        if ($kayMod !== null && $kayId !== null) {
            $stKay = $p->prepare("SELECT id, fis_no, yevmiye_no FROM `muh_fisler` WHERE kaynak_modul = ? AND kaynak_id = ? AND durum = 'onayli' LIMIT 1" . db_for_update($p));
            $stKay->execute([$kayMod, $kayId]);
            $var = $stKay->fetch(PDO::FETCH_ASSOC);
            if ($var) {
                return ['ok' => true, 'id' => (int)$var['id'], 'fis_no' => (string)$var['fis_no'], 'yevmiye_no' => (int)$var['yevmiye_no'], 'mukerrer' => true, 'mesaj' => 'Kaynak zaten muhasebeleştirilmiş (' . $var['fis_no'] . ').'];
            }
        }

        // Satırları doğrula ve hesapları çöz
        $temizSatirlar = [];
        $topBorc = 0.0;
        $topAlacak = 0.0;
        $stHp = $p->prepare('SELECT id, kod, ad, aktif, hareket_gorebilir FROM `muh_hesap_plani` WHERE kod = ?');

        foreach ($satirlar as $idx => $s) {
            $hkod = trim((string)($s['hesap_kod'] ?? ''));
            $borc = max(0.0, muh_tutar_coz($s['borc'] ?? 0));
            $alac = max(0.0, muh_tutar_coz($s['alacak'] ?? 0));
            if ($hkod === '' && $borc === 0.0 && $alac === 0.0) continue; // boş satır atla

            if ($hkod === '') {
                return ['ok' => false, 'hata' => ($idx + 1) . '. satırda hesap kodu eksik.', 'kod' => 422];
            }
            if (($borc > 0 && $alac > 0) || ($borc <= 0 && $alac <= 0)) {
                return ['ok' => false, 'hata' => ($idx + 1) . '. satırda yalnızca Borç veya yalnızca Alacak tutarı (> 0) girilmelidir.', 'kod' => 422];
            }
            $stHp->execute([$hkod]);
            $hp = $stHp->fetch(PDO::FETCH_ASSOC);
            if (!$hp) {
                return ['ok' => false, 'hata' => 'Hesap planında bulunamadı: ' . $hkod, 'kod' => 422];
            }
            if (!(int)$hp['aktif']) {
                return ['ok' => false, 'hata' => 'Pasif hesaba kayıt yapılamaz: ' . $hkod, 'kod' => 422];
            }
            if (!(int)$hp['hareket_gorebilir']) {
                return ['ok' => false, 'hata' => 'Ana/grup hesabına doğrudan hareket işlenemez: ' . $hkod, 'kod' => 422];
            }

            $pb = strtoupper(trim((string)($s['para_birimi'] ?? 'TRY')));
            if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
            $kur = max(0.000001, (float)($s['kur'] ?? 1.0));
            $dt  = muh_tutar_coz($s['doviz_tutar'] ?? 0);

            $topBorc   = round($topBorc + $borc, 2);
            $topAlacak = round($topAlacak + $alac, 2);
            $temizSatirlar[] = [
                'hesap_id'    => (int)$hp['id'],
                'hesap_kod'   => (string)$hp['kod'],
                'cari_id'     => isset($s['cari_id']) && (int)$s['cari_id'] > 0 ? (int)$s['cari_id'] : null,
                'belge_no'    => mb_substr(trim((string)($s['belge_no'] ?? '')), 0, 80, 'UTF-8'),
                'aciklama'    => mb_substr(trim((string)($s['aciklama'] ?? $aciklama)), 0, 400, 'UTF-8'),
                'borc'        => $borc,
                'alacak'      => $alac,
                'para_birimi' => $pb,
                'kur'         => $kur,
                'doviz_tutar' => $dt,
            ];
        }

        if (count($temizSatirlar) < 2) {
            return ['ok' => false, 'hata' => 'En az 2 geçerli hareket satırı gereklidir.', 'kod' => 422];
        }
        if ($topBorc <= 0.0 || abs($topBorc - $topAlacak) >= 0.005) {
            return [
                'ok'   => false,
                'hata' => sprintf('Fiş dengesiz! Toplam Borç (%.2f) ile Toplam Alacak (%.2f) eşit olmalıdır.', $topBorc, $topAlacak),
                'kod'  => 422,
            ];
        }

        // Yıl bazlı sıralı yevmiye no ve fiş no üret
        $stMax = $p->prepare("SELECT COALESCE(MAX(yevmiye_no), 0) FROM `muh_fisler` WHERE tarih >= ? AND tarih <= ?" . db_for_update($p));
        $stMax->execute([$yil . '-01-01', $yil . '-12-31']);
        $yevNo = (int)$stMax->fetchColumn() + 1;
        $fisNo = sprintf('YEV-%d-%05d', $yil, $yevNo);

        $p->prepare(
            "INSERT INTO `muh_fisler`
             (fis_no, yevmiye_no, tarih, tur, aciklama, kaynak_modul, kaynak_id, durum, toplam_borc, toplam_alacak, depo, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'onayli', ?, ?, ?, ?)"
        )->execute([$fisNo, $yevNo, $tarih, $tur, $aciklama, $kayMod, $kayId, $topBorc, $topAlacak, $depo, $uid ?: null]);
        $fisId = (int)$p->lastInsertId();

        $stSat = $p->prepare(
            'INSERT INTO `muh_fis_satirlar`
             (fis_id, sira_no, hesap_id, hesap_kod, cari_id, belge_no, aciklama, borc, alacak, para_birimi, kur, doviz_tutar)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($temizSatirlar as $i => $ts) {
            $stSat->execute([
                $fisId, $i + 1, $ts['hesap_id'], $ts['hesap_kod'], $ts['cari_id'],
                $ts['belge_no'], $ts['aciklama'], $ts['borc'], $ts['alacak'],
                $ts['para_birimi'], $ts['kur'], $ts['doviz_tutar'],
            ]);
        }

        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'genel_muhasebe_fis', $fisId, null, [
                'fis_no' => $fisNo, 'tarih' => $tarih, 'tur' => $tur, 'tutar' => $topBorc, 'satir' => count($temizSatirlar),
            ]);
        }
        return ['ok' => true, 'id' => $fisId, 'fis_no' => $fisNo, 'yevmiye_no' => $yevNo, 'mesaj' => 'Yevmiye fişi kaydedildi: ' . $fisNo];
    });
}

/**
 * INSERT-only ters fiş ile iptal: asıl fiş silinmez; ters kayıt üretilir ve asıl fiş 'iptal' işaretlenir.
 * @return array{ok:bool,ters_fis_id?:int,ters_fis_no?:string,hata?:string,kod?:int}
 */
function muh_fis_ters_iptal(PDO $pdo, int $fisId, string $neden, int $uid): array
{
    $neden = mb_substr(trim($neden), 0, 500, 'UTF-8');
    if ($neden === '') return ['ok' => false, 'hata' => 'İptal nedeni zorunludur.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($fisId, $neden, $uid): array {
        $st = $p->prepare('SELECT * FROM `muh_fisler` WHERE id = ?' . db_for_update($p));
        $st->execute([$fisId]);
        $fis = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fis) return ['ok' => false, 'hata' => 'Yevmiye fişi bulunamadı.', 'kod' => 404];
        if ((string)$fis['durum'] !== 'onayli') {
            return ['ok' => false, 'hata' => 'Bu fiş zaten iptal edilmiş.', 'kod' => 409];
        }
        if (muh_donem_kapali_mi($p, (string)$fis['tarih'])) {
            return ['ok' => false, 'hata' => 'Kapalı mali dönemdeki fiş iptal edilemez.', 'kod' => 409];
        }

        $stS = $p->prepare('SELECT * FROM `muh_fis_satirlar` WHERE fis_id = ? ORDER BY sira_no ASC');
        $stS->execute([$fisId]);
        $satirlar = $stS->fetchAll(PDO::FETCH_ASSOC);

        $tersSatirlar = [];
        foreach ($satirlar as $s) {
            $tersSatirlar[] = [
                'hesap_kod'   => (string)$s['hesap_kod'],
                'cari_id'     => $s['cari_id'],
                'belge_no'    => (string)$s['belge_no'],
                'aciklama'    => 'TERS İPTAL (' . $fis['fis_no'] . '): ' . $neden,
                'borc'        => (float)$s['alacak'],
                'alacak'      => (float)$s['borc'],
                'para_birimi' => (string)$s['para_birimi'],
                'kur'         => (float)$s['kur'],
                'doviz_tutar' => (float)$s['doviz_tutar'],
            ];
        }

        $ters = muh_fis_kaydet($p, [
            'tarih'    => (string)$fis['tarih'],
            'tur'      => 'mahsup',
            'aciklama' => 'Ters İptal — ' . $fis['fis_no'] . ' (' . $neden . ')',
            'depo'     => (string)$fis['depo'],
            'satirlar' => $tersSatirlar,
        ], $uid);
        if (empty($ters['ok'])) return $ters;

        $p->prepare("UPDATE `muh_fisler` SET durum='iptal', ters_fis_id=?, iptal_eden_id=?, iptal_at=?, iptal_neden=? WHERE id=?")
            ->execute([(int)$ters['id'], $uid ?: null, date('Y-m-d H:i:s'), $neden, $fisId]);

        if (function_exists('audit_log_event')) {
            audit_log_event('cancel', 'genel_muhasebe_fis', $fisId, ['durum' => 'onayli'], [
                'durum' => 'iptal', 'ters_fis_id' => $ters['id'], 'neden' => $neden,
            ]);
        }
        return [
            'ok'          => true,
            'ters_fis_id' => (int)$ters['id'],
            'ters_fis_no' => (string)$ters['fis_no'],
            'mesaj'       => $fis['fis_no'] . ' iptal edildi; ters fiş: ' . $ters['fis_no'],
        ];
    });
}

/** @return list<array<string,mixed>> */
function muh_fis_listesi(PDO $pdo, array $filtre = []): array
{
    $w = ['1=1'];
    $p = [];
    if (!empty($filtre['bas']) && muh_tarih_gecerli((string)$filtre['bas'])) { $w[] = 'f.tarih >= ?'; $p[] = (string)$filtre['bas']; }
    if (!empty($filtre['bit']) && muh_tarih_gecerli((string)$filtre['bit'])) { $w[] = 'f.tarih <= ?'; $p[] = (string)$filtre['bit']; }
    if (!empty($filtre['tur']) && isset(muh_fis_turleri()[(string)$filtre['tur']])) { $w[] = 'f.tur = ?'; $p[] = (string)$filtre['tur']; }
    if (!empty($filtre['durum']) && in_array((string)$filtre['durum'], ['onayli', 'iptal'], true)) { $w[] = 'f.durum = ?'; $p[] = (string)$filtre['durum']; }
    $q = trim((string)($filtre['ara'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $w[] = '(f.fis_no LIKE ? OR f.aciklama LIKE ?)';
        $p[] = $like;
        $p[] = $like;
    }
    $lim = max(1, min(500, (int)($filtre['limit'] ?? 100)));
    $st = $pdo->prepare(
        "SELECT f.*, (SELECT COUNT(*) FROM `muh_fis_satirlar` s WHERE s.fis_id = f.id) AS satir_sayisi
         FROM `muh_fisler` f
         WHERE " . implode(' AND ', $w) . "
         ORDER BY f.tarih DESC, f.yevmiye_no DESC, f.id DESC
         LIMIT $lim"
    );
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return ?array<string,mixed> */
function muh_fis_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM `muh_fisler` WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return null;
    $stS = $pdo->prepare(
        'SELECT s.*, h.ad AS hesap_ad
         FROM `muh_fis_satirlar` s
         LEFT JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
         WHERE s.fis_id = ?
         ORDER BY s.sira_no ASC, s.id ASC'
    );
    $stS->execute([$id]);
    $f['satirlar'] = $stS->fetchAll(PDO::FETCH_ASSOC);
    return $f;
}

// ── Mali Tablolar & Defterler (Mizan, Muavin, Gelir Tablosu, Bilanço) ──────

/**
 * Mizan (Trial Balance) — tüm aktif (onaylı + tersi dahil net) hareketler.
 * Not: Ters iptal fişi de muh_fisler'de 'onayli' olduğu ve asıl fişin tersini içerdiği için
 *      tüm fişler dahil edildiğinde iptal edilen kayıtlar otomatik sıfırlanır (resmi defter kuralı).
 * @return array{satirlar:list<array<string,mixed>>,toplam:array{borc:float,alacak:float,borc_bakiye:float,alacak_bakiye:float,dengeli:bool}}
 */
function muh_mizan(PDO $pdo, string $bas, string $bit, ?int $sinif = null): array
{
    $w = ['f.tarih >= ?', 'f.tarih <= ?'];
    $p = [$bas, $bit];
    if ($sinif !== null && $sinif >= 1 && $sinif <= 9) {
        $w[] = 'h.sinif = ?';
        $p[] = $sinif;
    }
    $st = $pdo->prepare(
        "SELECT h.kod, h.ad, h.sinif, h.tip, h.bakiye_yonu,
                ROUND(SUM(s.borc), 2) AS toplam_borc,
                ROUND(SUM(s.alacak), 2) AS toplam_alacak
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         INNER JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
         WHERE " . implode(' AND ', $w) . "
         GROUP BY h.id, h.kod, h.ad, h.sinif, h.tip, h.bakiye_yonu
         ORDER BY h.kod ASC"
    );
    $st->execute($p);
    $satirlar = [];
    $tb = 0.0; $ta = 0.0; $tbb = 0.0; $tab = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = round((float)$r['toplam_borc'], 2);
        $a = round((float)$r['toplam_alacak'], 2);
        $fark = round($b - $a, 2);
        $bb = $fark > 0 ? $fark : 0.0;
        $ab = $fark < 0 ? abs($fark) : 0.0;
        $satirlar[] = [
            'kod'           => (string)$r['kod'],
            'ad'            => (string)$r['ad'],
            'sinif'         => (int)$r['sinif'],
            'tip'           => (string)$r['tip'],
            'borc'          => $b,
            'alacak'        => $a,
            'borc_bakiye'   => $bb,
            'alacak_bakiye' => $ab,
        ];
        $tb  = round($tb + $b, 2);
        $ta  = round($ta + $a, 2);
        $tbb = round($tbb + $bb, 2);
        $tab = round($tab + $ab, 2);
    }
    return [
        'satirlar' => $satirlar,
        'toplam'   => [
            'borc'          => $tb,
            'alacak'        => $ta,
            'borc_bakiye'   => $tbb,
            'alacak_bakiye' => $tab,
            'dengeli'       => abs($tb - $ta) < 0.01 && abs($tbb - $tab) < 0.01,
        ],
    ];
}

/**
 * Muavin / Kebir Defteri — belirli bir hesap (veya ön ek, örn. '120' -> '120%' alt hesaplar dahil).
 * @return array{hesap_kod:string,devir_borc:float,devir_alacak:float,devir_bakiye:float,hareketler:list<array<string,mixed>>,son_bakiye:float}
 */
function muh_muavin(PDO $pdo, string $hesapKod, string $bas, string $bit, ?int $cariId = null): array
{
    $hesapKod = trim($hesapKod);
    $like = $hesapKod . '.%';
    $cariW = $cariId !== null && $cariId > 0 ? ' AND s.cari_id = ?' : '';
    $cariP = $cariId !== null && $cariId > 0 ? [$cariId] : [];

    // Devir (baslangic tarihinden önceki hareketler)
    $stDev = $pdo->prepare(
        "SELECT COALESCE(SUM(s.borc), 0) AS b, COALESCE(SUM(s.alacak), 0) AS a
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         WHERE (s.hesap_kod = ? OR s.hesap_kod LIKE ?) AND f.tarih < ?$cariW"
    );
    $stDev->execute([$hesapKod, $like, $bas, ...$cariP]);
    $dev = $stDev->fetch(PDO::FETCH_ASSOC) ?: ['b' => 0, 'a' => 0];
    $devB = round((float)$dev['b'], 2);
    $devA = round((float)$dev['a'], 2);
    $bakiye = round($devB - $devA, 2);

    $st = $pdo->prepare(
        "SELECT f.id AS fis_id, f.fis_no, f.yevmiye_no, f.tarih, f.tur, f.durum,
                s.hesap_kod, h.ad AS hesap_ad, s.belge_no, s.aciklama, s.borc, s.alacak
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         LEFT JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
         WHERE (s.hesap_kod = ? OR s.hesap_kod LIKE ?) AND f.tarih >= ? AND f.tarih <= ?$cariW
         ORDER BY f.tarih ASC, f.yevmiye_no ASC, s.sira_no ASC, s.id ASC"
    );
    $st->execute([$hesapKod, $like, $bas, $bit, ...$cariP]);
    $hareketler = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = round((float)$r['borc'], 2);
        $a = round((float)$r['alacak'], 2);
        $bakiye = round($bakiye + $b - $a, 2);
        $r['borc'] = $b;
        $r['alacak'] = $a;
        $r['yuruyen_bakiye'] = $bakiye;
        $hareketler[] = $r;
    }

    return [
        'hesap_kod'    => $hesapKod,
        'devir_borc'   => $devB,
        'devir_alacak' => $devA,
        'devir_bakiye' => round($devB - $devA, 2),
        'hareketler'   => $hareketler,
        'son_bakiye'   => $bakiye,
    ];
}

/**
 * TDHP Gelir Tablosu (6xx sınıfı — kapanış fişleri hariç gerçek faaliyet sonuçları).
 * @return array{kalemler:array<string,float>,brut_satislar:float,net_satislar:float,brut_kar:float,faaliyet_kari:float,donem_kari:float}
 */
function muh_gelir_tablosu(PDO $pdo, string $bas, string $bit): array
{
    $st = $pdo->prepare(
        "SELECT SUBSTR(s.hesap_kod, 1, 3) AS ana_kod,
                ROUND(SUM(s.borc), 2) AS b,
                ROUND(SUM(s.alacak), 2) AS a
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         WHERE f.tarih >= ? AND f.tarih <= ? AND f.tur <> 'kapanis' AND SUBSTR(s.hesap_kod, 1, 1) = '6'
         GROUP BY SUBSTR(s.hesap_kod, 1, 3)"
    );
    $st->execute([$bas, $bit]);
    $alacakNet = static fn(array $r): float => round((float)$r['a'] - (float)$r['b'], 2);
    $borcNet   = static fn(array $r): float => round((float)$r['b'] - (float)$r['a'], 2);

    $brutSatis = 0.0; $indirim = 0.0; $satisMaliyet = 0.0; $faaliyetGider = 0.0;
    $digerGelir = 0.0; $digerGider = 0.0; $finansman = 0.0; $olagandisiGelir = 0.0; $olagandisiGider = 0.0;
    $detay = [];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (int)$r['ana_kod'];
        if ($k >= 600 && $k <= 609) { $v = $alacakNet($r); $brutSatis = round($brutSatis + $v, 2); $detay[(string)$k] = $v; }
        elseif ($k >= 610 && $k <= 619) { $v = $borcNet($r); $indirim = round($indirim + $v, 2); $detay[(string)$k] = -$v; }
        elseif ($k >= 620 && $k <= 629) { $v = $borcNet($r); $satisMaliyet = round($satisMaliyet + $v, 2); $detay[(string)$k] = -$v; }
        elseif ($k >= 630 && $k <= 639) { $v = $borcNet($r); $faaliyetGider = round($faaliyetGider + $v, 2); $detay[(string)$k] = -$v; }
        elseif ($k >= 640 && $k <= 649) { $v = $alacakNet($r); $digerGelir = round($digerGelir + $v, 2); $detay[(string)$k] = $v; }
        elseif ($k >= 650 && $k <= 659) { $v = $borcNet($r); $digerGider = round($digerGider + $v, 2); $detay[(string)$k] = -$v; }
        elseif ($k >= 660 && $k <= 669) { $v = $borcNet($r); $finansman = round($finansman + $v, 2); $detay[(string)$k] = -$v; }
        elseif ($k >= 670 && $k <= 679) { $v = $alacakNet($r); $olagandisiGelir = round($olagandisiGelir + $v, 2); $detay[(string)$k] = $v; }
        elseif ($k >= 680 && $k <= 689) { $v = $borcNet($r); $olagandisiGider = round($olagandisiGider + $v, 2); $detay[(string)$k] = -$v; }
    }

    $netSatis    = round($brutSatis - $indirim, 2);
    $brutKar     = round($netSatis - $satisMaliyet, 2);
    $faaliyetKar = round($brutKar - $faaliyetGider, 2);
    $olaganKar   = round($faaliyetKar + $digerGelir - $digerGider - $finansman, 2);
    $donemKar    = round($olaganKar + $olagandisiGelir - $olagandisiGider, 2);

    return [
        'kalemler'        => [
            'brut_satislar'     => $brutSatis,
            'satis_indirimleri' => $indirim,
            'net_satislar'      => $netSatis,
            'satis_maliyeti'    => $satisMaliyet,
            'brut_kar'          => $brutKar,
            'faaliyet_gideri'   => $faaliyetGider,
            'faaliyet_kari'     => $faaliyetKar,
            'diger_gelirler'    => $digerGelir,
            'diger_giderler'    => $digerGider,
            'finansman_gideri'  => $finansman,
            'olagandisi_gelir'  => $olagandisiGelir,
            'olagandisi_gider'  => $olagandisiGider,
            'donem_kari'        => $donemKar,
        ],
        'detay'           => $detay,
        'brut_satislar'   => $brutSatis,
        'net_satislar'    => $netSatis,
        'brut_kar'        => $brutKar,
        'faaliyet_kari'   => $faaliyetKar,
        'donem_kari'      => $donemKar,
    ];
}

/**
 * TDHP Bilanço (1-5 sınıfları + kapanmamış 6/7 net sonucu dönem kâr/zararı olarak yansıtılır).
 * @return array{aktif:array<string,mixed>,pasif:array<string,mixed>,toplam_aktif:float,toplam_pasif:float,dengeli:bool}
 */
function muh_bilanco(PDO $pdo, string $tarih): array
{
    $st = $pdo->prepare(
        "SELECT h.kod, h.ad, h.sinif, h.bakiye_yonu,
                ROUND(SUM(s.borc), 2) AS b,
                ROUND(SUM(s.alacak), 2) AS a
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         INNER JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
         WHERE f.tarih <= ?
         GROUP BY h.id, h.kod, h.ad, h.sinif, h.bakiye_yonu
         ORDER BY h.kod ASC"
    );
    $st->execute([$tarih]);

    $gruplar = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []];
    $grupTop = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0];
    $kapanmamisSonuc = 0.0; // 6xx ve 7xx kapanmamış net alacak - borç (pozitif = kâr, pasif 5'e eklenir)

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sinif = (int)$r['sinif'];
        $b = round((float)$r['b'], 2);
        $a = round((float)$r['a'], 2);
        if ($sinif === 1 || $sinif === 2) {
            $tutar = round($b - $a, 2); // düzenleyici aktifler (103, 257) negatif görünür
            if (abs($tutar) < 0.005) continue;
            $gruplar[$sinif][] = ['kod' => (string)$r['kod'], 'ad' => (string)$r['ad'], 'tutar' => $tutar];
            $grupTop[$sinif] = round($grupTop[$sinif] + $tutar, 2);
        } elseif ($sinif >= 3 && $sinif <= 5) {
            $tutar = round($a - $b, 2); // düzenleyici öz kaynaklar (580, 591) negatif görünür
            if (abs($tutar) < 0.005) continue;
            $gruplar[$sinif][] = ['kod' => (string)$r['kod'], 'ad' => (string)$r['ad'], 'tutar' => $tutar];
            $grupTop[$sinif] = round($grupTop[$sinif] + $tutar, 2);
        } elseif ($sinif === 6 || $sinif === 7) {
            $kapanmamisSonuc = round($kapanmamisSonuc + ($a - $b), 2);
        }
    }

    if (abs($kapanmamisSonuc) >= 0.01) {
        $gruplar[5][] = [
            'kod'   => $kapanmamisSonuc >= 0 ? '590*' : '591*',
            'ad'    => $kapanmamisSonuc >= 0 ? 'Dönem Net Kârı (Kapanmamış)' : 'Dönem Net Zararı (Kapanmamış)',
            'tutar' => $kapanmamisSonuc,
        ];
        $grupTop[5] = round($grupTop[5] + $kapanmamisSonuc, 2);
    }

    $topAktif = round($grupTop[1] + $grupTop[2], 2);
    $topPasif = round($grupTop[3] + $grupTop[4] + $grupTop[5], 2);

    return [
        'aktif' => [
            'donen'        => ['ad' => 'I. Dönen Varlıklar', 'satirlar' => $gruplar[1], 'toplam' => $grupTop[1]],
            'duran'        => ['ad' => 'II. Duran Varlıklar', 'satirlar' => $gruplar[2], 'toplam' => $grupTop[2]],
        ],
        'pasif' => [
            'kv_yabanci'   => ['ad' => 'III. Kısa Vadeli Yabancı Kaynaklar', 'satirlar' => $gruplar[3], 'toplam' => $grupTop[3]],
            'uv_yabanci'   => ['ad' => 'IV. Uzun Vadeli Yabancı Kaynaklar', 'satirlar' => $gruplar[4], 'toplam' => $grupTop[4]],
            'oz_kaynaklar' => ['ad' => 'V. Öz Kaynaklar', 'satirlar' => $gruplar[5], 'toplam' => $grupTop[5]],
        ],
        'toplam_aktif' => $topAktif,
        'toplam_pasif' => $topPasif,
        'dengeli'      => abs($topAktif - $topPasif) < 0.01,
    ];
}

/**
 * KDV Beyanname Ön Hazırlık Özeti (191 İndirilecek vs 391 Hesaplanan KDV).
 * @return array{indirilecek:float,hesaplanan:float,odenecek:float,devreden:float}
 */
function muh_kdv_ozeti(PDO $pdo, string $bas, string $bit): array
{
    $st = $pdo->prepare(
        "SELECT SUBSTR(s.hesap_kod, 1, 3) AS ana, ROUND(SUM(s.borc), 2) AS b, ROUND(SUM(s.alacak), 2) AS a
         FROM `muh_fis_satirlar` s
         INNER JOIN `muh_fisler` f ON f.id = s.fis_id
         WHERE f.tarih >= ? AND f.tarih <= ? AND SUBSTR(s.hesap_kod, 1, 3) IN ('190', '191', '391')
         GROUP BY SUBSTR(s.hesap_kod, 1, 3)"
    );
    $st->execute([$bas, $bit]);
    $ind = 0.0; $hes = 0.0; $devOnce = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((string)$r['ana'] === '190') $devOnce = round((float)$r['b'] - (float)$r['a'], 2);
        if ((string)$r['ana'] === '191') $ind     = round((float)$r['b'] - (float)$r['a'], 2);
        if ((string)$r['ana'] === '391') $hes     = round((float)$r['a'] - (float)$r['b'], 2);
    }
    $fark = round($hes - ($ind + max(0.0, $devOnce)), 2);
    return [
        'onceki_devir' => max(0.0, $devOnce),
        'indirilecek'  => $ind,
        'hesaplanan'   => $hes,
        'odenecek'     => $fark > 0 ? $fark : 0.0,
        'devreden'     => $fark < 0 ? abs($fark) : 0.0,
    ];
}

// ── Mali Yıl Kapanışı (6xx → 590/591 + Dönem Kilidi) ──────────────────────

/** @return array{ok:bool,fis_id?:int,fis_no?:string,net_sonuc?:float,hata?:string,kod?:int} */
function muh_donem_kapat(PDO $pdo, int $yil, int $uid): array
{
    if ($yil < 2000 || $yil > 2100) return ['ok' => false, 'hata' => 'Geçersiz mali yıl.', 'kod' => 422];
    $bas = $yil . '-01-01';
    $bit = $yil . '-12-31';

    return db_tx($pdo, static function (PDO $p) use ($yil, $bas, $bit, $uid): array {
        $stD = $p->prepare('SELECT * FROM `muh_mali_donemler` WHERE yil = ?' . db_for_update($p));
        $stD->execute([$yil]);
        $donem = $stD->fetch(PDO::FETCH_ASSOC);
        if ($donem && (string)$donem['durum'] === 'kapali') {
            return ['ok' => false, 'hata' => $yil . ' mali dönemi zaten kapatılmış.', 'kod' => 409];
        }
        if (!$donem) {
            $p->prepare("INSERT INTO `muh_mali_donemler` (yil, baslangic, bitis, durum, notlar) VALUES (?, ?, ?, 'acik', '')")
                ->execute([$yil, $bas, $bit]);
        }

        // 6xx ve 7xx hesap bakiyelerini sıfırla ve net sonucu 590/591'e aktar
        $st = $p->prepare(
            "SELECT h.kod, ROUND(SUM(s.borc), 2) AS b, ROUND(SUM(s.alacak), 2) AS a
             FROM `muh_fis_satirlar` s
             INNER JOIN `muh_fisler` f ON f.id = s.fis_id
             INNER JOIN `muh_hesap_plani` h ON h.id = s.hesap_id
             WHERE f.tarih >= ? AND f.tarih <= ? AND h.sinif IN (6, 7)
             GROUP BY h.id, h.kod
             ORDER BY h.kod ASC"
        );
        $st->execute([$bas, $bit]);
        $satirlar = [];
        $netSonuc = 0.0; // alacak - borç (>0 kâr, <0 zarar)
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fark = round((float)$r['a'] - (float)$r['b'], 2);
            if (abs($fark) < 0.01) continue;
            $netSonuc = round($netSonuc + $fark, 2);
            if ($fark > 0) {
                // Alacak bakiyeli gelir hesabı → borçlandırarak kapat
                $satirlar[] = ['hesap_kod' => (string)$r['kod'], 'borc' => $fark, 'alacak' => 0.0, 'aciklama' => $yil . ' dönem sonu kapanış'];
            } else {
                // Borç bakiyeli gider/maliyet hesabı → alacaklandırarak kapat
                $satirlar[] = ['hesap_kod' => (string)$r['kod'], 'borc' => 0.0, 'alacak' => abs($fark), 'aciklama' => $yil . ' dönem sonu kapanış'];
            }
        }

        $kapanisFisId = null;
        $kapanisFisNo = null;
        if ($satirlar !== []) {
            if ($netSonuc > 0) {
                $satirlar[] = ['hesap_kod' => '590', 'borc' => 0.0, 'alacak' => $netSonuc, 'aciklama' => $yil . ' Dönem Net Kârı'];
            } elseif ($netSonuc < 0) {
                $satirlar[] = ['hesap_kod' => '591', 'borc' => abs($netSonuc), 'alacak' => 0.0, 'aciklama' => $yil . ' Dönem Net Zararı'];
            }
            $fisRes = muh_fis_kaydet($p, [
                'tarih'    => $bit,
                'tur'      => 'kapanis',
                'aciklama' => $yil . ' Mali Yıl Sonuç Hesapları Kapanış Fişi',
                'satirlar' => $satirlar,
            ], $uid, true);
            if (empty($fisRes['ok'])) return $fisRes;
            $kapanisFisId = (int)$fisRes['id'];
            $kapanisFisNo = (string)$fisRes['fis_no'];
        }

        $p->prepare("UPDATE `muh_mali_donemler` SET durum='kapali', kapanis_fis_id=?, kapatan_id=?, kapandi_at=? WHERE yil=?")
            ->execute([$kapanisFisId, $uid ?: null, date('Y-m-d H:i:s'), $yil]);

        if (function_exists('audit_log_event')) {
            audit_log_event('close_period', 'genel_muhasebe_donem', $yil, ['durum' => 'acik'], [
                'durum' => 'kapali', 'kapanis_fis_id' => $kapanisFisId, 'net_sonuc' => $netSonuc,
            ]);
        }
        return [
            'ok'        => true,
            'fis_id'    => $kapanisFisId,
            'fis_no'    => $kapanisFisNo,
            'net_sonuc' => $netSonuc,
            'mesaj'     => $yil . ' mali dönemi kapatıldı' . ($kapanisFisNo ? ' (' . $kapanisFisNo . ')' : '') . '.',
        ];
    });
}

// ── Alt Defter Otomatik Mahsup Köprüsü (Fatura / Kasa / Çek-Senet → Yevmiye) ─

/**
 * Alt defter kayıtlarından (fatura_belgeler, kasa_hareketler) idempotent yevmiye fişi üretir.
 * Aynı (kaynak_modul, kaynak_id) daha önce muhasebeleştirilmişse atlanır.
 * @return array{ok:bool,olusturulan:int,atlanan:int,hatalar:list<string>,mesaj:string}
 */
function muh_alt_defter_mahsup(PDO $pdo, string $bas, string $bit, int $uid): array
{
    if (!muh_tarih_gecerli($bas) || !muh_tarih_gecerli($bit)) {
        return ['ok' => false, 'olusturulan' => 0, 'atlanan' => 0, 'hatalar' => ['Geçersiz tarih aralığı.'], 'mesaj' => 'Geçersiz tarih aralığı.'];
    }
    $olusturulan = 0; $atlanan = 0; $hatalar = [];

    // 1) Faturalar (fatura_belgeler: satis/satis_ihracat/alis/alis_mustahsil, durum='gecerli', KDV hariç=net_toplam)
    if (db_table_exists($pdo, 'fatura_belgeler')) {
        $st = $pdo->prepare(
            "SELECT id, tur, belge_no, tarih, cari_id, para_birimi, kur, net_toplam, kdv_toplam, genel_toplam, depo
             FROM `fatura_belgeler`
             WHERE durum = 'gecerli' AND tur IN ('satis', 'satis_ihracat', 'alis', 'alis_mustahsil') AND tarih >= ? AND tarih <= ?
             ORDER BY tarih ASC, id ASC LIMIT 500"
        );
        try {
            $st->execute([$bas, $bit]);
            $fRows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $fRows = [];
        }
        foreach ($fRows as $f) {
            $kur = (float)($f['kur'] ?? 0);
            if ($kur <= 0.0) $kur = 1.0;
            $pb  = strtoupper((string)($f['para_birimi'] ?? 'TRY'));
            $kdv = round((float)$f['kdv_toplam'] * $kur, 2);
            $gen = round((float)$f['genel_toplam'] * $kur, 2);
            if ($gen <= 0.0) continue;
            // Kur yuvarlama farkını net toplama yedir (Borç = Alacak kesinliği)
            $ara = round($gen - $kdv, 2);
            if ($ara <= 0.0) { $ara = $gen; $kdv = 0.0; }

            $satirlar = [];
            $tur = (string)$f['tur'];
            if ($tur === 'satis' || $tur === 'satis_ihracat') {
                $gelirKod = ($tur === 'satis_ihracat' || $pb !== 'TRY') ? '601' : '600';
                $aliciKod = ($tur === 'satis_ihracat' || $pb !== 'TRY') ? '120.02' : '120.01';
                $satirlar[] = ['hesap_kod' => $aliciKod, 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => $gen, 'alacak' => 0.0, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$f['genel_toplam']];
                $satirlar[] = ['hesap_kod' => $gelirKod, 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => 0.0, 'alacak' => $ara, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$f['net_toplam']];
                if ($kdv > 0) {
                    $satirlar[] = ['hesap_kod' => '391', 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => 0.0, 'alacak' => $kdv];
                }
            } else {
                $satirlar[] = ['hesap_kod' => '153', 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => $ara, 'alacak' => 0.0, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$f['net_toplam']];
                if ($kdv > 0) {
                    $satirlar[] = ['hesap_kod' => '191', 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => $kdv, 'alacak' => 0.0];
                }
                $satirlar[] = ['hesap_kod' => '320.01', 'cari_id' => (int)$f['cari_id'], 'belge_no' => (string)$f['belge_no'], 'borc' => 0.0, 'alacak' => $gen, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$f['genel_toplam']];
            }

            $r = muh_fis_kaydet($pdo, [
                'tarih'        => (string)$f['tarih'],
                'tur'          => 'mahsup',
                'aciklama'     => 'Oto Mahsup — Fatura ' . $f['belge_no'],
                'depo'         => (string)($f['depo'] ?? ''),
                'kaynak_modul' => 'fatura',
                'kaynak_id'    => (int)$f['id'],
                'satirlar'     => $satirlar,
            ], $uid);
            if (!empty($r['mukerrer'])) $atlanan++;
            elseif (!empty($r['ok'])) $olusturulan++;
            else $hatalar[] = 'Fatura #' . $f['id'] . ': ' . ($r['hata'] ?? 'hata');
        }
    }

    // 2) Kasa Hareketleri (kasa_hareketler: yon='G'/'C', durum='gecerli')
    if (db_table_exists($pdo, 'kasa_hareketler')) {
        $st = $pdo->prepare(
            "SELECT id, kasa_id, cari_id, tarih, yon, tutar, kur, para_birimi, belge_no, aciklama, depo
             FROM `kasa_hareketler`
             WHERE durum = 'gecerli' AND yon IN ('G', 'C') AND tarih >= ? AND tarih <= ?
             ORDER BY tarih ASC, id ASC LIMIT 500"
        );
        try {
            $st->execute([$bas, $bit]);
            $kRows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $kRows = [];
        }
        foreach ($kRows as $k) {
            $kur = (float)($k['kur'] ?? 0);
            if ($kur <= 0.0) $kur = 1.0;
            $pb  = strtoupper((string)($k['para_birimi'] ?? 'TRY'));
            $tl  = round((float)$k['tutar'] * $kur, 2);
            if ($tl <= 0.0) continue;
            $kasaKod = $pb !== 'TRY' ? '100.02' : '100.01';
            $cid = isset($k['cari_id']) && (int)$k['cari_id'] > 0 ? (int)$k['cari_id'] : null;
            if ((string)$k['yon'] === 'G') {
                $karsiKod = $cid ? '120' : '649';
                $satirlar = [
                    ['hesap_kod' => $kasaKod, 'cari_id' => $cid, 'belge_no' => (string)($k['belge_no'] ?? ''), 'borc' => $tl, 'alacak' => 0.0, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$k['tutar']],
                    ['hesap_kod' => $karsiKod, 'cari_id' => $cid, 'belge_no' => (string)($k['belge_no'] ?? ''), 'borc' => 0.0, 'alacak' => $tl, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$k['tutar']],
                ];
                $tur = 'tahsil';
            } else {
                $karsiKod = $cid ? '320' : '770';
                $satirlar = [
                    ['hesap_kod' => $karsiKod, 'cari_id' => $cid, 'belge_no' => (string)($k['belge_no'] ?? ''), 'borc' => $tl, 'alacak' => 0.0, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$k['tutar']],
                    ['hesap_kod' => $kasaKod, 'cari_id' => $cid, 'belge_no' => (string)($k['belge_no'] ?? ''), 'borc' => 0.0, 'alacak' => $tl, 'para_birimi' => $pb, 'kur' => $kur, 'doviz_tutar' => (float)$k['tutar']],
                ];
                $tur = 'tediye';
            }
            $r = muh_fis_kaydet($pdo, [
                'tarih'        => (string)$k['tarih'],
                'tur'          => $tur,
                'aciklama'     => 'Oto Mahsup — Kasa #' . $k['id'] . ' ' . mb_substr((string)($k['aciklama'] ?? ''), 0, 120, 'UTF-8'),
                'depo'         => (string)($k['depo'] ?? ''),
                'kaynak_modul' => 'kasa',
                'kaynak_id'    => (int)$k['id'],
                'satirlar'     => $satirlar,
            ], $uid);
            if (!empty($r['mukerrer'])) $atlanan++;
            elseif (!empty($r['ok'])) $olusturulan++;
            else $hatalar[] = 'Kasa #' . $k['id'] . ': ' . ($r['hata'] ?? 'hata');
        }
    }

    return [
        'ok'          => true,
        'olusturulan' => $olusturulan,
        'atlanan'     => $atlanan,
        'hatalar'     => $hatalar,
        'mesaj'       => sprintf('Alt defter mahsubu tamamlandı: %d yeni fiş oluşturuldu, %d zaten muhasebeleştirilmişti.', $olusturulan, $atlanan),
    ];
}
