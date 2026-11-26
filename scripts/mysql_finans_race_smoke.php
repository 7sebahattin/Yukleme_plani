<?php
// =========================================================
// scripts/mysql_finans_race_smoke.php — Gerçek MariaDB/MySQL eşzamanlı yarış testi (B-10)
//   ASYA_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=asya_test;charset=utf8mb4' \
//   ASYA_TEST_MYSQL_USER=root ASYA_TEST_MYSQL_PASS=test \
//     php scripts/mysql_finans_race_smoke.php
// Kanıtlanan (REPEATABLE READ + FOR UPDATE):
//   1) stok_fis_kaydet: 100 kg stoktan 6 süreç aynı anda 60 kg çıkış dener → en fazla 1 başarılı, stok asla negatif olmaz (B-05).
//   2) fatura_kaydet: aynı cari+belge_no için 6 süreç aynı anda kaydeder → tam 1 başarılı, 5 mükerrer red (B-23).
//   3) pdks_hakedis_finalize: aynı taslak hakediş için 6 süreç aynı anda kesinleştirir → tam 1 başarılı (B-08).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$dsn  = (string)(getenv('ASYA_TEST_MYSQL_DSN') ?: getenv('MAIL_TEST_MYSQL_DSN') ?: '');
$user = (string)(getenv('ASYA_TEST_MYSQL_USER') ?: getenv('MAIL_TEST_MYSQL_USER') ?: 'root');
$pass = (string)(getenv('ASYA_TEST_MYSQL_PASS') !== false ? getenv('ASYA_TEST_MYSQL_PASS') : (getenv('MAIL_TEST_MYSQL_PASS') ?: ''));

if ($dsn === '' || !function_exists('pcntl_fork')) {
    echo "ATLANDI (MySQL DSN ya da pcntl_fork yok)\n";
    exit(0);
}

$ROOT = dirname(__DIR__);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

function db(): PDO { global $pdo; return $pdo; }
function audit_log_event(string $action, string $module, $record_id = null, $old = null, $new = null): void {}
function active_depot(): ?string { return 'RACE_DEPO'; }
function user_allowed_depots(?array $u = null): ?array { return null; }
function depo_sql_in(string $col, ?array $depots = null): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }

require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/fatura_core.php';
require_once $ROOT . '/config/pdks_hakedis.php';

$passCnt = 0; $failCnt = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $passCnt, $failCnt;
    if ($kosul) $passCnt++; else $failCnt++;
    echo sprintf("%-74s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu);
}

function yaris_calistir(int $n, string $dsn, string $user, string $pass, callable $fn): array {
    $ana = getmypid();
    $go = sys_get_temp_dir() . '/asya_frace_go_' . $ana;
    @unlink($go);
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $c = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $GLOBALS['pdo'] = $c;
            while (!file_exists($go)) usleep(300);
            try { $r = $fn($c, $i); }
            catch (Throwable $e) { $r = ['ok' => false, 'err' => get_class($e) . ': ' . $e->getMessage()]; }
            file_put_contents(sys_get_temp_dir() . '/asya_frace_res_' . $ana . '_' . $i, json_encode($r));
            posix_kill(getmypid(), SIGKILL);
        }
        $pids[$i] = $pid;
    }
    usleep(250000);
    touch($go);
    $out = [];
    foreach ($pids as $i => $pid) {
        pcntl_waitpid($pid, $st);
        $f = sys_get_temp_dir() . '/asya_frace_res_' . $ana . '_' . $i;
        $out[] = json_decode((string)@file_get_contents($f), true) ?: ['ok' => false, 'err' => 'sonuç yok'];
        @unlink($f);
    }
    @unlink($go);
    return $out;
}

// ── 1) B-05: Stok negatif yarış testi ──
cari_migrate($pdo);
stok_migrate($pdo);
fatura_migrate($pdo);

$kod = 'RACE-STOK-' . bin2hex(random_bytes(3));
$kart = stok_kart_kaydet($pdo, ['kod' => $kod, 'ad' => 'Yarış Ürünü', 'birim' => 'kg'], 1);
$kartId = (int)($kart['id'] ?? 0);
stok_fis_kaydet($pdo, [
    'tur' => 'giris', 'tarih' => date('Y-m-d'), 'depo' => 'RACE_DEPO',
    'satirlar' => [['kart_id' => $kartId, 'miktar' => 100, 'birim_fiyat' => 10]],
], 1);

$resStok = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($kartId): array {
    return stok_fis_kaydet($c, [
        'tur' => 'cikis', 'tarih' => date('Y-m-d'), 'depo' => 'RACE_DEPO',
        'satirlar' => [['kart_id' => $kartId, 'miktar' => 60]],
    ], 1);
});
$stokOk = count(array_filter($resStok, static fn(array $r): bool => !empty($r['ok'])));
$sonDurum = stok_maliyet_durumu($pdo, $kartId, 'RACE_DEPO');
ok('B-05 MariaDB stok yarışı: 6x60 çıkıştan tam 1 başarılı', $stokOk === 1, 'ok=' . $stokOk . ' ' . json_encode($resStok));
ok('B-05 MariaDB stok miktarı negatif değil (= 40)', abs((float)$sonDurum['miktar'] - 40.0) < 0.001, json_encode($sonDurum));

// ── 2) B-23: Fatura mükerrer belge no yarış testi ──
$cari = cari_kart_kaydet($pdo, ['unvan' => 'Yarış Cari ' . bin2hex(random_bytes(3)), 'tip' => 'musteri'], 1);
$cariId = (int)($cari['id'] ?? 0);
$belgeNo = 'RACE-FAT-' . bin2hex(random_bytes(4));
$resFat = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($cariId, $kartId, $belgeNo): array {
    return fatura_kaydet($c, [
        'tur' => 'alis_fatura', 'belge_no' => $belgeNo, 'tarih' => date('Y-m-d'),
        'cari_id' => $cariId, 'depo' => 'RACE_DEPO', 'para_birimi' => 'TRY', 'kur' => 1,
        'satirlar' => [['stok_kart_id' => $kartId, 'aciklama' => 'Test', 'miktar' => 5, 'birim_fiyat' => 20, 'kdv_oran' => 0]],
    ], 1);
});
$fatOk = count(array_filter($resFat, static fn(array $r): bool => !empty($r['ok'])));
ok('B-23 MariaDB fatura mükerrer yarışı: 6 süreçten tam 1 başarılı', $fatOk === 1, 'ok=' . $fatOk . ' ' . json_encode($resFat));

// ── 3) B-08: PDKS hakediş kesinleştirme yarış testi ──
$pdo->exec("CREATE TABLE IF NOT EXISTS foreman_daily_entitlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    foreman_id INT NOT NULL,
    work_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    finalized_by INT NULL,
    finalized_at DATETIME NULL,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->prepare("INSERT INTO foreman_daily_entitlements (foreman_id, work_date, status) VALUES (99, ?, 'draft')")->execute([date('Y-m-d')]);
$entId = (int)$pdo->lastInsertId();

$resFin = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($entId): array {
    return pdks_hakedis_finalize($c, $entId, 1);
});
$finOk = count(array_filter($resFin, static fn(array $r): bool => !empty($r['ok'])));
ok('B-08 MariaDB hakediş finalize yarışı: 6 süreçten tam 1 başarılı', $finOk === 1, 'ok=' . $finOk . ' ' . json_encode($resFin));

echo "\nÖZET: $passCnt geçti, $failCnt kaldı\n";
exit($failCnt > 0 ? 1 : 0);
