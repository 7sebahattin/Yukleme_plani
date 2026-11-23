<?php
// =========================================================
// scripts/_ceksenet_test_lib.php — Çek-Senet testleri için ORTAK harness (include-only; yalnız CLI). _kasa_test_lib.php aynası.
//   stub auth + gerçek helpers + gerçek cari_core / kasa_core / ceksenet_core (+ varsa ceksenet_ui). DB: DOSYA tabanlı geçici SQLite (alt süreç aynı DB'yi açar).
//   CEKSENET_E2E_DB verilirse o dosya kullanılır ve SİLİNMEZ.
//   ceksenet_t_istek_cagri(['dosya'=>'cek_kaydet.php','post'=>[…],'server'=>[…],'admin'=>true]) → ['govde','kod','basliklar','flash','hata'].
//   ceksenet_t_sema_kur(): cari + kasa + çek-senet şeması (gerçek *_migrate). ceksenet_t_ornek_veri(): 3 cari + 4 kasa (uydurma; kişisel veri YOK).
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/kasa_core.php';
require_once $ROOT . '/config/ceksenet_core.php';
if (is_file($ROOT . '/config/ceksenet_ui.php')) require_once $ROOT . '/config/ceksenet_ui.php';

$CEKSENET_T_DB = (string)(getenv('CEKSENET_E2E_DB') ?: '');
if ($CEKSENET_T_DB === '') {
    $CEKSENET_T_DB = sys_get_temp_dir() . '/ceksenet_t_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    register_shutdown_function(static function () use ($CEKSENET_T_DB): void { @unlink($CEKSENET_T_DB); });
}
$CEKSENET_T_MEVCUT = is_file($CEKSENET_T_DB) && filesize($CEKSENET_T_DB) > 0;
$AKTIF_DEPO = 'Merkez Depo';
$DEPO_SECENEK = ['Merkez Depo', 'Şube Depo'];
$PDO_TEST = new PDO('sqlite:' . $CEKSENET_T_DB);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!$CEKSENET_T_MEVCUT) {
    siparis_test_diger_tablolar($PDO_TEST);   // users + audit_log
    $PDO_TEST->exec('CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
}

/** Alt süreçte tek istek (gerçek exit/http_response_code). spec: dosya, get, post, server, admin, perms, uid, db?, aktif_depo?, depolar?, depo_secenek?, sid? */
function ceksenet_t_istek_cagri(array $spec): array
{
    global $CEKSENET_T_DB, $ROOT;
    $spec += ['get' => [], 'post' => [], 'server' => [], 'admin' => true, 'perms' => ['reports.export', 'records.read'], 'uid' => 1];
    $spec['db'] ??= $CEKSENET_T_DB;
    $cmd = 'CEKSENET_T_SPEC=' . escapeshellarg(base64_encode((string)json_encode($spec, JSON_UNESCAPED_UNICODE))) . ' php ' . escapeshellarg($ROOT . '/scripts/_ceksenet_istek.php') . ' 2>&1';
    $cikti = (string)shell_exec($cmd);
    $p = strrpos($cikti, "\n@@CEKSENET@@");
    if ($p === false) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç çıktısı çözülemedi'];
    $r = json_decode(substr($cikti, $p + 13), true);
    if (!is_array($r)) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç JSON bozuk'];
    $r['govde'] = substr($cikti, 0, $p) . (string)$r['govde'];
    return $r;
}

/** SQLite DDL kancaları (cari / kasa / çek-senet migrate testi). */
function ceksenet_t_kanca(): void
{
    $k = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
    $GLOBALS['_cari_ddl_calistir'] = $k;
    $GLOBALS['_kasa_ddl_calistir'] = $k;
    $GLOBALS['_ceksenet_ddl_calistir'] = $k;
}

/** Cari + kasa + çek-senet şemaları (gerçek migrate fonksiyonları). @return list<array> ceksenet_migrate raporu */
function ceksenet_t_sema_kur(PDO $db): array
{
    ceksenet_t_kanca();
    cari_migrate($db);
    kasa_migrate($db);
    return ceksenet_migrate($db);
}

/**
 * Uydurma örnek veri (ceksenet_t_sema_kur'dan SONRA): 3 cari (müşteri, tedarikçi, ikinci tedarikçi; TRY) ve 4 kasa — Merkez Kasa (nakit TRY), Örnek Banka TL (banka TRY),
 * Döviz Kasası (nakit USD), Örnek Banka USD (banka USD). Hiç hareket YOK.
 * @return array{cari:array<string,int>,kasa:array<string,int>}
 */
function ceksenet_t_ornek_veri(PDO $db): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    $c = [];
    foreach ([['ÖRNEK TİCARET A.Ş.', 'musteri'], ['ÖRNEK TEDARİK LTD.', 'tedarikci'], ['ÖRNEK NAKLİYAT LTD.', 'tedarikci']] as [$u, $t]) {
        $r = cari_kart_kaydet($db, ['unvan' => $u, 'tip' => $t, 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
        if (!$r['ok']) throw new RuntimeException('örnek cari: ' . $r['hata']);
        $c[$u] = (int)$r['id'];
    }
    $k = [];
    foreach ([
        ['Merkez Kasa', 'nakit', 'TRY', []],
        ['Örnek Banka TL', 'banka', 'TRY', ['banka_adi' => 'Örnek Banka', 'sube' => 'Merkez Şube', 'iban' => 'TR330006100519786457841326']],
        ['Döviz Kasası', 'nakit', 'USD', []],
        ['Örnek Banka USD', 'banka', 'USD', ['banka_adi' => 'Örnek Banka', 'sube' => 'Merkez Şube']],
    ] as [$ad, $tip, $pb, $ek]) {
        $r = kasa_kart_kaydet($db, ['ad' => $ad, 'tip' => $tip, 'para_birimi' => $pb, 'depo' => '', 'aktif' => '1'] + $ek, 1);
        if (!$r['ok']) throw new RuntimeException('örnek kasa: ' . $r['hata']);
        $k[$ad] = (int)$r['id'];
    }
    return ['cari' => $c, 'kasa' => $k];
}
