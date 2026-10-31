<?php
// =========================================================
// scripts/pdks_fis_e2e_setup.php — Fiş Excel'i uçtan uca (tarayıcı) testi için DOSYA tabanlı SQLite DB + örnek xlsx üretir.
//   php scripts/pdks_fis_e2e_setup.php <db.sqlite> <fisler.xlsx>   → stdout: JSON {ok, db, xlsx, cavus:{...}}
// Harness (scripts/_pdks_fis_harness.php) şemayı gerçek DDL çeviriciyle kurar; canlı DB'ye dokunmaz.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$dbYol = (string)($argv[1] ?? ''); $xlsxYol = (string)($argv[2] ?? '');
if ($dbYol === '' || $xlsxYol === '') { fwrite(STDERR, "Kullanım: php pdks_fis_e2e_setup.php <db.sqlite> <fisler.xlsx>\n"); exit(2); }
@unlink($dbYol);
putenv('PDKS_FIS_HARNESS_DB=' . $dbYol);
ob_start();
require __DIR__ . '/_pdks_fis_harness.php';
require_once $ROOT . '/config/pdks_fis.php';
ob_end_clean();

db()->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');   // v447: önizleme yedeği
foreach (['A', 'B', 'C', 'D'] as $h) {
    foreach ([$kadin, $erkek, $rampaci, $seritci] as $tid) {   // v456: Şeritçi fiyatı da tanımlı (Erkek «12+1» → Şeritçi)
        $r = pdks_faz8b_oran_ekle($cavus[$h], $tid, '1500', '900', 'hourly', '200', '2020-01-01', 'TRY', 1, db());
        if (!$r['ok']) { fwrite(STDERR, 'oran: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    }
    pdks_servis_ucret_ekle($cavus[$h], '400', '250', '2020-01-01', 'TRY', 1, db());
}
$ss = pdks_fis_sablon_uret(db());
$ws = $ss->getSheetByName('Fişler');
$kol = [];
foreach (pdks_fis_sutunlar() as $i => $c) $kol[$c['anahtar']] = $i + 1;
// İşletme/Depo/Çavuş dosyada YOK (yükleme ekranında seçilir): üç fiş AYNI çavuşun üç ayrı gününe aittir.
$gun = date('d.m.Y', strtotime('-3 day')); $gun2 = date('d.m.Y', strtotime('-4 day')); $gun3 = date('d.m.Y', strtotime('-5 day')); $gun4 = date('d.m.Y', strtotime('-6 day'));
$satirlar = [
    ['fis_no' => 'E2E-1', 'tarih' => $gun, 'kadin_tam' => 8, 'erkek_tam' => 12, 'erkek_yarim' => 1, 'fm2' => 5, 'fm_tipi' => 'Erkek', 'servis_kucuk' => 1,
     'not' => 'Rampa', 'ham' => 'Bayan 8 | Erkek 12 | Erkek yarım 1 | Toplam Mesai: 5 kişi 2 saat | <script>window.__xss=1</script>', 'kontrol' => '"><img src=x onerror="window.__xss=2">'],
    ['fis_no' => 'E2E-2', 'tarih' => $gun2, 'kadin_tam' => 8, 'erkek_tam' => 12, 'rampaci_tam' => 2, 'rampaci_yarim' => 1, 'fm_toplam' => 25, 'servis_buyuk' => 1, 'giris' => '09:00'],
    // v456: Kadın alanında «+» belirsiz → hücre HATASI (tahmin yok); «+» yalnız Erkek Tam'da Şeritçi anlamına gelir
    ['fis_no' => 'E2E-3', 'tarih' => $gun3, 'kadin_tam' => '12 + 1', 'not' => '=HYPERLINK("http://x","y")'],
    // v456: yeni yorum kuralları — Kadın «33,5» = 33 Tam + 1 Yarım, Erkek «12+1» = 12 Erkek + 1 Şeritçi (uyarılı ama yazılabilir)
    ['fis_no' => 'E2E-4', 'tarih' => $gun4, 'kadin_tam' => '33,5', 'erkek_tam' => '12+1', 'ham' => 'Bayan 33,5 | Erkek 12 + 1'],
];
foreach ($satirlar as $ri => $sat) {
    foreach ($sat as $anahtar => $v) {
        $hucre = [$kol[$anahtar], 2 + $ri];
        if (is_int($v)) $ws->setCellValue($hucre, $v);
        else $ws->setCellValueExplicit($hucre, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
}
\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save($xlsxYol);
echo json_encode(['ok' => true, 'db' => $dbYol, 'xlsx' => $xlsxYol, 'cavus' => $cavus, 'gun' => $gun], JSON_UNESCAPED_UNICODE) . "\n";
