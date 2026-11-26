<?php
// =========================================================
// scripts/faz4_ci_mimari_smoke.php — Faz 4 CI/CD, Mimari ve Dokümantasyon Smoke Testi
// (B-10, B-37, B-41)
// SADECE CLI. Bellek içi SQLite kullanır; canlı DB'ye dokunmaz.
//   php scripts/faz4_ci_mimari_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $pass, $fail;
    if ($kosul) $pass++; else $fail++;
    fwrite(STDOUT, sprintf("%-76s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu));
}
function oku(string $rel): string {
    global $ROOT;
    return (string)file_get_contents($ROOT . '/' . $rel);
}

// ── 1) B-10: CI/CD MariaDB job, php-lint, tum_testler.sh PHP ATLANDI ──────
$yml = oku('.github/workflows/testler.yml');
$sh  = oku('scripts/tum_testler.sh');
ok('B-10a testler.yml mariadb:10.11 mysql-integration + php-lint jobları var',
    str_contains($yml, 'mariadb:10.11')
    && str_contains($yml, 'mysql-integration:')
    && str_contains($yml, 'php-lint:')
    && str_contains($yml, 'migrate_mysql_smoke.php')
    && str_contains($yml, 'mysql_finans_race_smoke.php')
    && str_contains($yml, 'mail_race_mysql_smoke.php')
);
ok('B-10b tum_testler.sh PHP ATLANDI çıktılarını da yakalar',
    str_contains($sh, '(^ATLANDI\\b|')
);
ok('B-10c MariaDB entegrasyon betikleri DSN yokken güvenle ATLANDI döner',
    str_contains(trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/scripts/migrate_mysql_smoke.php'))), 'ATLANDI')
    && str_contains(trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/scripts/mysql_finans_race_smoke.php'))), 'ATLANDI')
);

// ── 2) B-37: Çekirdek config override, tek PDO, db_tx / db_table_exists ────
$dbSrc   = oku('config/db.php');
$hkDbSrc = oku('halkayit/db.php');
$hlpSrc  = oku('config/helpers.php');
ok('B-37a config/db.php DB_LOCAL_* / ASYA_DB_* ezme + const DB_* uyumu korunur',
    str_contains($dbSrc, 'function db_config_resolve(')
    && str_contains($dbSrc, 'DB_LOCAL_HOST')
    && str_contains($dbSrc, 'ASYA_DB_HOST')
    && str_contains($dbSrc, "const DB_HOST    = 'localhost';")
);
ok('B-37b halkayit/db.php hks_db() aynı DB hedefinde db() PDO bağlantısını paylaşır',
    str_contains($hkDbSrc, "function_exists('db')") && str_contains($hkDbSrc, '$pdo = db();')
);
ok('B-37c config/helpers.php modüler db_tx_helpers ve cache_export_helpers yükler',
    is_file($ROOT . '/config/db_tx_helpers.php')
    && is_file($ROOT . '/config/cache_export_helpers.php')
    && str_contains($hlpSrc, "require_once __DIR__ . '/db_tx_helpers.php';")
    && str_contains($hlpSrc, "require_once __DIR__ . '/cache_export_helpers.php';")
);

require_once $ROOT . '/config/db_tx_helpers.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE t_tx (id INTEGER PRIMARY KEY, val TEXT)');
ok('B-37d db_table_exists() mevcut/yok/geçersiz adlarda doğru döner',
    db_table_exists($pdo, 't_tx') === true
    && db_table_exists($pdo, 'olmayan_tablo') === false
    && db_table_exists($pdo, 'bad;drop') === false
);
$res = db_tx($pdo, static function (PDO $p): string {
    $p->exec("INSERT INTO t_tx (val) VALUES ('dis')");
    return db_tx($p, static function (PDO $p2): string {
        $p2->exec("INSERT INTO t_tx (val) VALUES ('ic')");
        return 'ok';
    });
});
ok('B-37e db_tx() iç içe çağrıda atomik commit yapar',
    $res === 'ok' && (int)$pdo->query('SELECT COUNT(*) FROM t_tx')->fetchColumn() === 2
);
$caught = false;
try {
    db_tx($pdo, static function (PDO $p): void {
        $p->exec("INSERT INTO t_tx (val) VALUES ('geri')");
        throw new RuntimeException('iptal');
    });
} catch (RuntimeException $e) {
    $caught = true;
}
ok('B-37f db_tx() hata durumunda rollback yapar',
    $caught && (int)$pdo->query('SELECT COUNT(*) FROM t_tx')->fetchColumn() === 2
);
ok('B-37g db_for_update() SQLite üzerinde boş dize döner', db_for_update($pdo) === '');

// ── 3) B-41: Dokümantasyon senkronizasyonu ──────────────────────────────────
$readme = oku('README.md');
$arch   = oku('docs/ARCHITECTURE.md');
$claude = oku('CLAUDE.md');
ok('B-41a README.md güncel (auth yok uyarısı kalktı, asya.scai.tr / test.scai.tr / halkayit / migrate.php var)',
    !str_contains($readme, 'Auth/login yok')
    && str_contains($readme, 'asya.scai.tr')
    && str_contains($readme, 'test.scai.tr')
    && str_contains($readme, 'halkayit/')
    && str_contains($readme, 'migrate.php')
);
ok('B-41b docs/ARCHITECTURE.md güncel (asya_session, halkayit/, 44 kart, sw.js allowlist, MariaDB CI)',
    str_contains($arch, 'asya_session')
    && str_contains($arch, 'halkayit/')
    && str_contains($arch, '44 idempotent kart')
    && str_contains($arch, 'allowlist')
    && str_contains($arch, 'MariaDB 10.11')
    && !str_contains($arch, 'yukleme-plani-v8')
);
ok('B-41c CLAUDE.md güncel (eski branch adı kalktı, test.scai.tr ve v586 eşit)',
    !str_contains($claude, 'claude/fix-records-print-mobile-WuKdT')
    && str_contains($claude, 'test.scai.tr')
    && str_contains($claude, 'yukleme-plani-v586')
);

fwrite(STDOUT, "\nÖZET: $pass geçti, $fail kaldı\n");
exit($fail > 0 ? 1 : 0);
