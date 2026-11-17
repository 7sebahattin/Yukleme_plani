<?php
// =========================================================
// scripts/_kasa_test_lib.php — Kasa testleri/render için ORTAK harness (include-only; yalnız CLI).
//   _siparis_test_lib.php (stub auth + gerçek helpers) + gerçek config/cari_core.php + config/kasa_core.php (+ varsa config/kasa_ui.php — Aşama 2).
//   DB: DOSYA tabanlı geçici SQLite (alt süreçler — scripts/_kasa_istek.php — aynı DB'yi açar); çıkışta silinir.
//   KASA_E2E_DB verilirse (tarayıcı köprüsü) o dosya kullanılır ve SİLİNMEZ.
//   kasa_t_istek_cagri(['dosya'=>'kasa_kaydet.php','post'=>[…],'server'=>[…],'admin'=>true,'db'?]) → ['govde','kod','basliklar','flash','hata'] (alt süreç).
//   kasa_t_sema_kur(): cari + kasa şeması (gerçek *_migrate; SQLite çeviricisi). kasa_t_ornek_veri(): 2 cari + 4 kasa (uydurma; kişisel veri YOK).
//   kasa_t_hareket(): hareket_ekle POST biçimi kısayolu.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/cari_core.php';
require_once $ROOT . '/config/kasa_core.php';
if (is_file($ROOT . '/config/kasa_ui.php')) require_once $ROOT . '/config/kasa_ui.php';

$KASA_T_DB = (string)(getenv('KASA_E2E_DB') ?: '');
if ($KASA_T_DB === '') {
    $KASA_T_DB = sys_get_temp_dir() . '/kasa_t_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.sqlite';
    register_shutdown_function(static function () use ($KASA_T_DB): void { @unlink($KASA_T_DB); });
}
$KASA_T_MEVCUT = is_file($KASA_T_DB) && filesize($KASA_T_DB) > 0;
$AKTIF_DEPO = 'Merkez Depo';
$DEPO_SECENEK = ['Merkez Depo', 'Şube Depo'];
$PDO_TEST = new PDO('sqlite:' . $KASA_T_DB);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PDO_TEST->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
if (!$KASA_T_MEVCUT) {
    siparis_test_diger_tablolar($PDO_TEST);   // users + audit_log
    $PDO_TEST->exec('CREATE TABLE material_definitions (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, is_active INT DEFAULT 1)');
}

/** Alt süreçte tek istek (gerçek exit/http_response_code). spec: dosya, get, post, server, admin, perms, uid, db?, aktif_depo?, depolar?, depo_secenek?, sid? */
function kasa_t_istek_cagri(array $spec): array
{
    global $KASA_T_DB, $ROOT;
    $spec += ['get' => [], 'post' => [], 'server' => [], 'admin' => true, 'perms' => ['reports.export', 'records.read'], 'uid' => 1];
    $spec['db'] ??= $KASA_T_DB;
    $cmd = 'KASA_T_SPEC=' . escapeshellarg(base64_encode((string)json_encode($spec, JSON_UNESCAPED_UNICODE))) . ' php ' . escapeshellarg($ROOT . '/scripts/_kasa_istek.php') . ' 2>&1';
    $cikti = (string)shell_exec($cmd);
    $p = strrpos($cikti, "\n@@KASA@@");
    if ($p === false) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç çıktısı çözülemedi'];
    $r = json_decode(substr($cikti, $p + 9), true);
    if (!is_array($r)) return ['govde' => $cikti, 'kod' => 0, 'basliklar' => [], 'flash' => null, 'hata' => 'alt süreç JSON bozuk'];
    $r['govde'] = substr($cikti, 0, $p) . (string)$r['govde'];
    return $r;
}

/** SQLite DDL kancaları (cari_migrate + kasa_migrate testi). */
function kasa_t_kanca(): void
{
    $k = function (PDO $p, string $ad, string $ddl): void {
        [$c, $ix] = siparis_ddl_sqlite($ddl);
        $p->exec($c);
        foreach ($ix as $x) $p->exec($x);
    };
    $GLOBALS['_cari_ddl_calistir'] = $k;
    $GLOBALS['_kasa_ddl_calistir'] = $k;
}

/** Cari + kasa şemaları (gerçek migrate fonksiyonları). @return list<array> kasa_migrate raporu */
function kasa_t_sema_kur(PDO $db): array
{
    kasa_t_kanca();
    cari_migrate($db);
    return kasa_migrate($db);
}

/** hareket_ekle POST biçimi: kasa, tur, tutar metni + ek alanlar (tarih varsayılanı bugün). */
function kasa_t_hareket(int $kasaId, string $tur, string $tutar, array $ek = []): array
{
    return array_merge(['kasa_id' => (string)$kasaId, 'tur' => $tur, 'tutar' => $tutar, 'tarih' => date('Y-m-d')], $ek);   // $ek varsayılanları ezebilir
}

/**
 * Uydurma örnek veri (kasa_t_sema_kur'dan SONRA): 2 cari (müşteri + tedarikçi, TRY) ve 4 kasa — Merkez Kasa (nakit TRY, Merkez Depo), Örnek Banka TL (banka TRY, tüm depolar),
 * Döviz Kasası (nakit USD), Şube Kasası (nakit TRY, Şube Depo). Hiç hareket YOK.
 * @return array{cari:array<string,int>,kasa:array<string,int>}
 */
function kasa_t_ornek_veri(PDO $db): array
{
    global $UID, $IS_ADMIN;
    $UID = 1; $IS_ADMIN = true;
    $c = [];
    foreach ([['ÖRNEK TİCARET A.Ş.', 'musteri'], ['ÖRNEK TEDARİK LTD.', 'tedarikci']] as [$u, $t]) {
        $r = cari_kart_kaydet($db, ['unvan' => $u, 'tip' => $t, 'para_birimi' => 'TRY', 'aktif' => '1'], 1);
        if (!$r['ok']) throw new RuntimeException('örnek cari: ' . $r['hata']);
        $c[$u] = (int)$r['id'];
    }
    $k = [];
    foreach ([
        ['Merkez Kasa', 'nakit', 'TRY', 'Merkez Depo', []],
        ['Örnek Banka TL', 'banka', 'TRY', '', ['banka_adi' => 'Örnek Banka', 'sube' => 'Merkez Şube', 'iban' => 'TR330006100519786457841326']],
        ['Döviz Kasası', 'nakit', 'USD', '', []],
        ['Şube Kasası', 'nakit', 'TRY', 'Şube Depo', []],
    ] as [$ad, $tip, $pb, $depo, $ek]) {
        $r = kasa_kart_kaydet($db, ['ad' => $ad, 'tip' => $tip, 'para_birimi' => $pb, 'depo' => $depo, 'aktif' => '1'] + $ek, 1);
        if (!$r['ok']) throw new RuntimeException('örnek kasa: ' . $r['hata']);
        $k[$ad] = (int)$r['id'];
    }
    return ['cari' => $c, 'kasa' => $k];
}
