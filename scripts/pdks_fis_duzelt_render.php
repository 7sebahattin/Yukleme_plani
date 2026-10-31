<?php
// =========================================================
// scripts/pdks_fis_duzelt_render.php — Fiş düzeltme sayfalarının (üstüne yaz · fiş yüklemelerini geri al · etkilenen fişler)
// TARAYICI testi için DOSYA tabanlı SQLite DB + örnek xlsx üretir (pdks_fis_e2e_setup.php deseni).
//   php scripts/pdks_fis_duzelt_render.php <db.sqlite> <fisler.xlsx>   → stdout: JSON {ok, db, xlsx, cavus, sifre, ...}
// Sayfalar GERÇEK php -S sunucusunda çalışır (scripts/pdks_fis_e2e_router.php). Canlı DB'ye dokunmaz.
// Fixture: Çavuş A için eski kuralla yazılmış 4 günlük fiş (bir günü KESİN hakedişli, bir günde fiş-dışı kayıt var);
// xlsx = AYNI «D-1» fişinin yeni kuralla (Erkek «12+1») hâli. Giriş şifresi (kullanıcı 1): bkz. çıktıdaki "sifre".
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$dbYol = (string)($argv[1] ?? ''); $xlsxYol = (string)($argv[2] ?? '');
if ($dbYol === '' || $xlsxYol === '') { fwrite(STDERR, "Kullanım: php pdks_fis_duzelt_render.php <db.sqlite> <fisler.xlsx>\n"); exit(2); }
@unlink($dbYol);
putenv('PDKS_FIS_HARNESS_DB=' . $dbYol);
ob_start();
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_fis.php';
ob_end_clean();

$SIFRE = 'Test-Sifre-1';
db()->exec('ALTER TABLE users ADD COLUMN password_hash TEXT');
db()->prepare('UPDATE users SET password_hash = ? WHERE id = 1')->execute([password_hash($SIFRE, PASSWORD_DEFAULT)]);
db()->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
foreach (['A', 'B'] as $h) {
    foreach ([$kadin, $erkek, $rampaci, $seritci] as $tid) {
        $r = pdks_faz8b_oran_ekle($cavus[$h], $tid, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, db());
        if (!$r['ok']) { fwrite(STDERR, 'oran: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    }
    pdks_servis_ucret_ekle($cavus[$h], '400', '250', '2020-01-01', 'TRY', 1, db());
}
$gun = fn(int $n) => date('Y-m-d', strtotime("-$n day"));
$sec = ['onay_yarim' => true, 'onay_fm' => true];
$cA = $cavus['A'];
function fis(array $say, array $o): array {
    static $n = 0; $n++;
    $s = ['KADIN' => ['tam' => 0, 'yarim' => 0], 'ERKEK' => ['tam' => 0, 'yarim' => 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0], 'SERITCI' => ['tam' => 0, 'yarim' => 0]];
    foreach ($say as $k => [$t, $y]) $s[$k] = ['tam' => $t, 'yarim' => $y];
    $cv = $o['cavus']; unset($o['cavus']);
    return $o + ['satir' => 1 + $n, 'fis_no_ham_sayi' => false, 'isletme' => '', 'cavus' => ['id' => $cv, 'kod' => '', 'ad' => '', 'yontem' => 'kod'], 'say' => $s,
                 'fm_kova' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0], 'fm_toplam' => null, 'fm_kisi' => null, 'fm_tipi' => null, 'servis' => ['buyuk' => 0, 'kucuk' => 0],
                 'giris' => '08:00', 'giris_varsayilan' => true, 'rev' => 0, 'not' => '', 'ham' => '', 'kontrol' => '', 'cakisma_dosya' => false, 'cakisma_sira' => 0, 'hatalar' => [], 'uyarilar' => []];
}
function yaz(array $f, array $sec): array {
    $m = pdks_fis_degerlendir(db(), $f, $sec, 1);
    $r = pdks_fis_yaz(db(), [$f], $sec, 1, str_repeat('a', 64), [$f['satir']], [$f['satir'] => $m['hash']])['sonuclar'][0];
    if (!in_array($r['durum'], ['yazildi', 'devam_tamamlandi'], true)) { fwrite(STDERR, 'fixture: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    return $r;
}
$r1 = yaz(fis(['ERKEK' => [12, 1]], ['cavus' => $cA, 'fis_no' => 'D-1', 'tarih' => $gun(3), 'servis' => ['buyuk' => 0, 'kucuk' => 1]]), $sec);
$r2 = yaz(fis(['KADIN' => [5, 1]], ['cavus' => $cA, 'fis_no' => 'D-2', 'tarih' => $gun(4)]), $sec);
$r3 = yaz(fis(['ERKEK' => [3, 0]], ['cavus' => $cA, 'fis_no' => 'D-3', 'tarih' => $gun(5)]), $sec);
$r4 = yaz(fis(['ERKEK' => [2, 0]], ['cavus' => $cA, 'fis_no' => 'D-4', 'tarih' => $gun(6)]), $sec);
// fiş-dışı (elle) 1 Kadın kaydı: D-4 gününde
$elle = pdks_faz8j_toplu_ekle(['foreman_id' => $cA, 'work_date' => $gun(6), 'depo' => 'Depo A', 'reason' => 'elle', 'istek_id' => bin2hex(random_bytes(16)),
    'gruplar' => [['worker_type_id' => $kadin, 'entry_clock' => '08:00', 'exit_date' => $gun(6), 'exit_clock' => '17:00', 'kartsiz_adet' => 1]]], 1, db());
if (empty($elle['ok'])) { fwrite(STDERR, 'elle: ' . json_encode($elle, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
// D-3 günü KESİN hakediş
db()->prepare("INSERT INTO foreman_daily_entitlements (session_id, foreman_id, work_date, depo, status, needs_recalculation) VALUES (?, ?, ?, 'Depo A', 'final', 0)")->execute([(int)$r3['session_id'], $cA, $gun(5)]);
// başka çavuşun (B) fişi — dokunulmaması gerekir
$rB = yaz(fis(['ERKEK' => [2, 1]], ['cavus' => $cavus['B'], 'fis_no' => 'D-B1', 'tarih' => $gun(4)]), $sec);

// xlsx: D-1 fişinin YENİ kuralla hâli (Erkek «12+1» = 12 Erkek + 1 Şeritçi)
$ss = pdks_fis_sablon_uret(db());
$ws = $ss->getSheetByName('Fişler');
$kol = [];
foreach (pdks_fis_sutunlar() as $i => $c) $kol[$c['anahtar']] = $i + 1;
$satir = ['fis_no' => 'D-1', 'tarih' => date('d.m.Y', strtotime('-3 day')), 'erkek_tam' => '12+1', 'servis_kucuk' => 1, 'ham' => 'Erkek 12 + 1'];
foreach ($satir as $anahtar => $v) {
    $hucre = [$kol[$anahtar], 2];
    if (is_int($v)) $ws->setCellValue($hucre, $v);
    else $ws->setCellValueExplicit($hucre, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
}
\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save($xlsxYol);
echo json_encode(['ok' => true, 'db' => $dbYol, 'xlsx' => $xlsxYol, 'cavus' => $cavus, 'sifre' => $SIFRE, 'gun3' => $gun(3), 'gun6' => $gun(6),
                  'sid' => ['D1' => (int)$r1['session_id'], 'D2' => (int)$r2['session_id'], 'D3' => (int)$r3['session_id'], 'D4' => (int)$r4['session_id'], 'B1' => (int)$rB['session_id']]], JSON_UNESCAPED_UNICODE) . "\n";
