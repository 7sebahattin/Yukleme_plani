<?php
// =========================================================
// scripts/migrate_mysql_smoke.php — Gerçek MariaDB/MySQL üzerinde 2x idempotent migrasyon testi (B-10)
//   ASYA_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=asya_test;charset=utf8mb4' \
//   ASYA_TEST_MYSQL_USER=root ASYA_TEST_MYSQL_PASS=test \
//     php scripts/migrate_mysql_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$dsn  = (string)(getenv('ASYA_TEST_MYSQL_DSN') ?: getenv('MAIL_TEST_MYSQL_DSN') ?: '');
$user = (string)(getenv('ASYA_TEST_MYSQL_USER') ?: getenv('MAIL_TEST_MYSQL_USER') ?: 'root');
$pass = (string)(getenv('ASYA_TEST_MYSQL_PASS') !== false ? getenv('ASYA_TEST_MYSQL_PASS') : (getenv('MAIL_TEST_MYSQL_PASS') ?: ''));

if ($dsn === '') {
    echo "ATLANDI (MySQL DSN tanımlı değil; ASYA_TEST_MYSQL_DSN ayarlayın)\n";
    exit(0);
}

$ROOT = dirname(__DIR__);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

// Stub db() & audit_log_event() so core modules use our MariaDB test PDO
function db(): PDO { global $pdo; return $pdo; }
function audit_log_event(string $action, string $module, $record_id = null, $old = null, $new = null): void {}
function active_depot(): ?string { return 'TEST_DEPO'; }

require_once $ROOT . '/config/cekirdek_migrate.php';
require_once $ROOT . '/config/depo_core.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/fatura_core.php';
require_once $ROOT . '/config/kasa_core.php';
require_once $ROOT . '/config/doviz_core.php';
require_once $ROOT . '/config/ceksenet_core.php';
require_once $ROOT . '/config/kullanici_tercih.php';
require_once $ROOT . '/config/ajanda_core.php';
require_once $ROOT . '/config/palet_fisleri_core.php';
require_once $ROOT . '/config/kantar_alanlari_core.php';
require_once $ROOT . '/config/muhasebe_core.php';
require_once $ROOT . '/config/edonusum_core.php';
require_once $ROOT . '/config/erp_mutabakat_core.php';
require_once $ROOT . '/config/wms_stok_core.php';
require_once $ROOT . '/config/yukleme_v2_core.php';
require_once $ROOT . '/config/satinalma_core.php';
require_once $ROOT . '/config/uretim_bom_core.php';
require_once $ROOT . '/config/onay_core.php';
require_once $ROOT . '/config/banka_core.php';
require_once $ROOT . '/config/ik_bordro_core.php';

$passCnt = 0; $failCnt = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $passCnt, $failCnt;
    if ($kosul) $passCnt++; else $failCnt++;
    echo sprintf("%-72s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu);
}

// Temel çekirdek tabloları oluştur (database.sql asgari taban)
$baseSql = [
    "CREATE TABLE IF NOT EXISTS loading_records (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL DEFAULT 'yukleme', durum VARCHAR(30) DEFAULT '', tarih DATE NULL, firma VARCHAR(150) DEFAULT '', parti_no VARCHAR(100) DEFAULT '', urun VARCHAR(150) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS loading_pallets (id INT AUTO_INCREMENT PRIMARY KEY, loading_record_id INT NOT NULL, palet_no VARCHAR(50) DEFAULT '', kasa_adeti INT DEFAULT 0, size VARCHAR(50) DEFAULT '', brut_kg DOUBLE DEFAULT 0, dara_kg DOUBLE DEFAULT 0, net_kg DOUBLE DEFAULT 0, kasa_cinsi_id INT NULL, palet_tipi_id INT NULL, urun_cinsi VARCHAR(150) DEFAULT '', depo VARCHAR(150) DEFAULT '', sira_no INT DEFAULT 0, islendi TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS material_definitions (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL, unit_dara_kg DOUBLE DEFAULT 0, is_active TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS kantar_fisleri (id INT AUTO_INCREMENT PRIMARY KEY, fis_no VARCHAR(50) NOT NULL DEFAULT '', giris_tarih DATE NULL, plaka VARCHAR(30) DEFAULT '', firma_adi VARCHAR(150) DEFAULT '', malin_cinsi VARCHAR(150) DEFAULT '', geldigi_yer VARCHAR(150) DEFAULT '', depo VARCHAR(150) DEFAULT '', palet_sayisi INT DEFAULT 0, palet_cinsi VARCHAR(100) DEFAULT '', kasa_sayisi INT DEFAULT 0, kasa_cinsi VARCHAR(100) DEFAULT '', tartim1 DOUBLE DEFAULT 0, tartim2 DOUBLE DEFAULT 0, kasa_dara DOUBLE DEFAULT 0, palet_dara DOUBLE DEFAULT 0, kasa_dara_total DOUBLE DEFAULT 0, palet_dara_total DOUBLE DEFAULT 0, aciklama TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS audit_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, action VARCHAR(50) NOT NULL, module VARCHAR(60) NOT NULL, record_id INT NULL, old_values LONGTEXT NULL, new_values LONGTEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS account_transactions (id INT AUTO_INCREMENT PRIMARY KEY, type VARCHAR(20) NOT NULL, amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($baseSql as $sql) $pdo->exec($sql);

$adimlar = [
    'cekirdek_db_migrate'           => static fn() => cekirdek_db_migrate($pdo),
    'depo_migrate'                  => static fn() => depo_migrate($pdo),
    'cari_migrate'                  => static fn() => cari_migrate($pdo),
    'stok_migrate'                  => static fn() => stok_migrate($pdo),
    'fatura_migrate'                => static fn() => fatura_migrate($pdo),
    'kasa_migrate'                  => static fn() => kasa_migrate($pdo),
    'doviz_migrate'                 => static fn() => doviz_migrate($pdo),
    'ceksenet_migrate'              => static fn() => ceksenet_migrate($pdo),
    'kt_migrate'                    => static fn() => kt_migrate($pdo),
    'ajanda_migrate'                => static fn() => ajanda_migrate($pdo),
    'palet_fisleri_migrate'         => static fn() => palet_fisleri_migrate($pdo),
    'kantar_alanlari_migrate'       => static fn() => kantar_alanlari_migrate($pdo),
    'kantar_fisno_uniq_migrate'     => static fn() => kantar_fisno_uniq_migrate($pdo),
    'cekirdek_perf_indeksler'       => static fn() => cekirdek_perf_indeksler_migrate($pdo),
    'muhasebe_migrate'              => static fn() => muhasebe_migrate($pdo),
    'edonusum_migrate'              => static fn() => edonusum_migrate($pdo),
    'erp_mutabakat_migrate'         => static fn() => erp_mutabakat_migrate($pdo),
    'wms_stok_migrate'              => static fn() => wms_stok_migrate($pdo),
    'yukleme_v2_migrate'            => static fn() => yukleme_v2_migrate($pdo),
    'satinalma_migrate'             => static fn() => satinalma_migrate($pdo),
    'uretim_bom_migrate'            => static fn() => uretim_bom_migrate($pdo),
    'onay_migrate'                  => static fn() => onay_migrate($pdo),
    'banka_migrate'                 => static fn() => banka_migrate($pdo),
    'ik_bordro_migrate'             => static fn() => ik_bordro_migrate($pdo),
];

// 1. tur + 2. tur (2x idempotency)
foreach ([1, 2] as $tur) {
    foreach ($adimlar as $ad => $fn) {
        $hata = null;
        try { $fn(); } catch (Throwable $e) { $hata = $e->getMessage(); }
        ok("Tur $tur — $ad hatasız tamamlandı", $hata === null, (string)$hata);
    }
}

$pidx = cekirdek_perf_indeksler_durum($pdo);
ok('B-29 tüm performans composite indeksleri MariaDB üzerinde hazır', $pidx['hazir'], json_encode($pidx));
ok('Muhasebe & ERP Faz 1-3 şemaları hazır',
    cari_hazir($pdo) && stok_hazir($pdo) && fatura_hazir($pdo) && kasa_hazir($pdo) && doviz_hazir($pdo) && ceksenet_hazir($pdo)
    && muh_hazir($pdo) && edonusum_hazir($pdo) && erp_mutabakat_hazir($pdo)
    && wms_stok_hazir($pdo) && yukleme_v2_hazir($pdo) && satinalma_hazir($pdo) && uretim_bom_hazir($pdo)
    && onay_hazir($pdo) && banka_hazir($pdo) && ik_bordro_hazir($pdo)
);

echo "\nÖZET: $passCnt geçti, $failCnt kaldı\n";
exit($failCnt > 0 ? 1 : 0);
