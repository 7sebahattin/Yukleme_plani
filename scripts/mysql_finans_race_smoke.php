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
function depot_options(): array { return ['RACE_DEPO']; }
function user_allowed_depots(?array $u = null): ?array { return null; }
function depo_sql_in(string $col, ?array $depots = null): array { return ['', []]; }
function depot_visible_to_user(?string $d): bool { return true; }
function depo_gecis_aktif(PDO $p): bool { return true; }   // v611: stok fişi yalnız yeni depo sistemi açıkken (stok_depo_gecis_acik)
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }

require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/stok_core.php';
require_once $ROOT . '/config/fatura_core.php';
require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';

$passCnt = 0; $failCnt = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $passCnt, $failCnt;
    if ($kosul) $passCnt++; else $failCnt++;
    echo sprintf("%-74s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu);
}

function yaris_calistir(int $n, string $dsn, string $user, string $pass, callable $fn): array {
    // pcntl_fork() öncesi ana PDO kapatılmazsa çocuk süreçler aynı TCP soketini miras alır
    // ve çocukta $GLOBALS['pdo'] ezildiğinde COM_QUIT göndererek ana bağlantıyı düşürür (2006).
    $GLOBALS['pdo'] = null;
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
    $GLOBALS['pdo'] = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $out;
}

$bugun = date('Y-m-d');

// ── 1) B-05: Stok negatif yarış testi ──
cari_migrate($pdo);
stok_migrate($pdo);
fatura_migrate($pdo);

$nedenBul = static function (PDO $p, string $tur, string $ad): int {
    foreach (stok_tanim_listesi($p, $tur) as $t) {
        if (($t['ad'] ?? '') === $ad) return (int)$t['id'];
    }
    $hepsi = stok_tanim_listesi($p, $tur);
    return !empty($hepsi) ? (int)$hepsi[0]['id'] : 0;
};
$alisNeden  = $nedenBul($pdo, 'giris_neden', 'Alış');
$satisNeden = $nedenBul($pdo, 'cikis_neden', 'Satış/Sevk');

$kod = 'RACE-STOK-' . bin2hex(random_bytes(3));
$kart = stok_kart_kaydet($pdo, ['kod' => $kod, 'ad' => 'Yarış Ürünü', 'ana_birim' => 'kg', 'kdv_orani' => '1'], 1);
$kartId = (int)($kart['id'] ?? 0);
$girisSonuc = stok_fis_kaydet($pdo, [
    'tur' => 'giris', 'tarih' => $bugun, 'depo' => 'RACE_DEPO', 'neden_id' => (string)$alisNeden,
    'kart_id' => [(string)$kartId], 'miktar' => ['100'], 'birim_fiyat' => ['10'],
    'yon' => [''], 'birim' => [''], 'kdv_orani' => ['1'], 'lot' => [''], 'kart_ara' => [''],
], 1);
ok('B-05 MariaDB stok başlangıç girişi (100 kg)', !empty($girisSonuc['ok']), json_encode($girisSonuc, JSON_UNESCAPED_UNICODE));

$resStok = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($kartId, $bugun, $satisNeden): array {
    return stok_fis_kaydet($c, [
        'tur' => 'cikis', 'tarih' => $bugun, 'depo' => 'RACE_DEPO', 'neden_id' => (string)$satisNeden,
        'kart_id' => [(string)$kartId], 'miktar' => ['60'], 'birim_fiyat' => [''],
        'yon' => [''], 'birim' => [''], 'kdv_orani' => [''], 'lot' => [''], 'kart_ara' => [''],
    ], 1);
});
$stokOk = count(array_filter($resStok, static fn(array $r): bool => !empty($r['ok'])));
$sonDurum = stok_maliyet_durumu($pdo, $kartId);
ok('B-05 MariaDB stok yarışı: 6x60 çıkıştan tam 1 başarılı', $stokOk === 1, 'ok=' . $stokOk . ' ' . json_encode($resStok, JSON_UNESCAPED_UNICODE));
ok('B-05 MariaDB stok miktarı negatif değil (= 40 kg / 40000 milli)', (int)($sonDurum['q'] ?? -1) === 40000, json_encode($sonDurum));

// ── 2) B-23: Fatura mükerrer fatura_no yarış testi ──
$cari = cari_kart_kaydet($pdo, ['unvan' => 'Yarış Cari ' . bin2hex(random_bytes(3)), 'tip' => 'tedarikci', 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
$cariId = (int)($cari['id'] ?? 0);
$faturaNo = 'RACE-FAT-' . bin2hex(random_bytes(4));
$resFat = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($cariId, $kartId, $faturaNo, $bugun): array {
    return fatura_kaydet($c, [
        'tur' => 'alis', 'fatura_no' => $faturaNo, 'tarih' => $bugun,
        'cari_id' => (string)$cariId, 'depo' => 'RACE_DEPO', 'para_birimi' => 'TRY', 'kur' => '',
        'kart_id' => [(string)$kartId], 'kart_ara' => [''], 'urun_adi' => ['Yarış Ürünü'],
        'miktar' => ['5'], 'birim' => [''], 'birim_fiyat' => ['20'], 'iskonto' => [''], 'kdv_orani' => ['1'],
        'gtip' => [''], 'satir_brut_kg' => [''], 'satir_net_kg' => [''], 'satir_kap' => [''],
        'lot' => [''], 'satir_aciklama' => [''], 'kaynak_satir_id' => [''],
    ], 1);
});
$fatOk = count(array_filter($resFat, static fn(array $r): bool => !empty($r['ok'])));
ok('B-23 MariaDB fatura mükerrer yarışı: 6 süreçten tam 1 başarılı', $fatOk === 1, 'ok=' . $fatOk . ' ' . json_encode($resFat, JSON_UNESCAPED_UNICODE));

// ── 3) B-08: PDKS hakediş kesinleştirme yarış testi ──
pdks_migrate($pdo);
pdks_gunluk_migrate($pdo);
pdks_hakedis_migrate($pdo);

$kadinId = (int)$pdo->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$rnd = bin2hex(random_bytes(3));
$cavus = pdks_gunluk_cavus_olustur(['code' => 'RC' . strtoupper($rnd), 'name' => 'Yarış Çavuş ' . $rnd], 1, $pdo);
$cavusId = (int)($cavus['id'] ?? 0);
$oturum = pdks_gunluk_oturum_ac_veya_getir($cavusId, 1, $pdo);
$sid = (int)($oturum['session']['id'] ?? 0);
$kartUid = (string)random_int(700000000, 799999999);
pdks_gunluk_kart_olustur(['card_no' => 'RK' . strtoupper($rnd), 'worker_type_id' => $kadinId, 'ham_uid' => $kartUid, 'kaynak' => 'usb_decimal'], 1, $pdo);
pdks_gunluk_oturum_kaydet($kartUid, 'usb_decimal', $sid, 'GIRIS', 1, $pdo);
pdks_gunluk_oturum_kaydet($kartUid, 'usb_decimal', $sid, 'CIKIS', 1, $pdo);
pdks_gunluk_oturum_kapat($sid, null, 1, $pdo);
pdks_hakedis_oran_ekle($cavusId, $kadinId, '1000', '2020-01-01', 'TRY', 1, $pdo);

$resFin = yaris_calistir(6, $dsn, $user, $pass, static function (PDO $c) use ($sid): array {
    return pdks_hakedis_finalize($sid, 1, false, $c);
});
$finOk = count(array_filter($resFin, static fn(array $r): bool => !empty($r['ok'])));
ok('B-08 MariaDB hakediş finalize yarışı: 6 süreçten tam 1 başarılı', $finOk === 1, 'ok=' . $finOk . ' ' . json_encode($resFin, JSON_UNESCAPED_UNICODE));

echo "\nÖZET: $passCnt geçti, $failCnt kaldı\n";
exit($failCnt > 0 ? 1 : 0);
