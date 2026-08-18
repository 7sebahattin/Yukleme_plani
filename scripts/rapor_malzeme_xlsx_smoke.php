<?php
// =========================================================
// scripts/rapor_malzeme_xlsx_smoke.php
// Malzeme Kullanım Raporu → "Excel İndir" (iki sayfalı XLSX) duman testi.
//
// SADECE CLI. Veritabanına DOKUNMAZ, HTTP isteği yapmaz. rapor_malzeme.php'nin
// XLSX bloğunda kullanılan PhpSpreadsheet API'lerini SAHTE veriyle birebir aynı
// sırayla çalıştırır, dosyayı diske yazar ve GERİ OKUYARAK doğrular.
//
//   php scripts/rapor_malzeme_xlsx_smoke.php   → çıkış kodu 0 = tüm testler geçti
//
// NEDEN GEREKLİ: Asıl kırılma riski PhpSpreadsheet sürüm farkıdır. Örneğin
// setCellValueByColumnAndRow() 1.x'te vardı, 2.x'te KALDIRILDI; böyle bir çağrı
// yalnızca kullanıcı indirme düğmesine bastığında patlar. Bu test, kullanılan
// metotların mevcut sürümde var olduğunu ve çıktının açılabilir olduğunu
// önceden kanıtlar.
//
// KAPSAM DIŞI: SQL sorguları ve filtre mantığı (DB gerektirir). Bu test yalnız
// "Excel üretimi çalışıyor mu, biçimlendirme uygulanıyor mu" sorusunu yanıtlar.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Bu betik yalnız komut satırından çalıştırılabilir.\n"); exit(1); }

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) { fwrite(STDERR, "vendor/autoload.php yok — composer install gerekli.\n"); exit(1); }
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\{Fill, Alignment, Border};

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = '') {
    global $fail;
    if (!$c) $fail++;
    printf("%-62s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

echo "── PhpSpreadsheet API uyumu (sürüm kırılmalarını erken yakalar) ──\n";
// rapor_malzeme.php'nin XLSX bloğunda çağrılan Worksheet metotları.
// Bu liste, o bloktaki çağrılarla SENKRON tutulmalıdır.
$wsMetotlar = ['fromArray', 'getStyle', 'getRowDimension', 'getColumnDimension',
               'calculateColumnWidths', 'freezePane', 'setAutoFilter', 'setCellValue',
               'setTitle', 'calculateWorksheetDimension'];
$ws = new ReflectionClass(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::class);
foreach ($wsMetotlar as $m) {
    ok('Worksheet::' . $m . '() mevcut', $ws->hasMethod($m));
}
// KALDIRILMIŞ API kontrolü: varsa kod yanlışlıkla eski imzayı kullanıyor olabilir.
// Yalnız GERÇEK ÇAĞRI (`->metot(`) aranır — metot adı açıklama yorumlarında da
// geçtiği için düz metin araması yanlış pozitif verir.
ok('setCellValueByColumnAndRow() ÇAĞRILMIYOR (2.x\'te kaldırıldı)',
    !str_contains((string)file_get_contents(__DIR__ . '/../rapor_malzeme.php'), '->setCellValueByColumnAndRow('),
    'rapor_malzeme.php bu kaldırılmış metodu çağırıyor');

echo "\n── Dosya üretimi (rapor_malzeme.php XLSX bloğunun aynadı) ──\n";
$BASLIK_DOLGU = 'FFE8EEF4';
$KENAR        = 'FFB8C4CF';

$baslik_yaz = function ($sh, array $b, int $satir) use ($BASLIK_DOLGU, $KENAR) {
    $sh->fromArray($b, null, 'A' . $satir);
    $son = Coordinate::stringFromColumnIndex(count($b));
    $st  = $sh->getStyle('A' . $satir . ':' . $son . $satir);
    $st->getFont()->setBold(true);
    $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($BASLIK_DOLGU);
    $st->getAlignment()->setWrapText(true)
       ->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $st->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB($KENAR);
    $sh->getRowDimension($satir)->setRowHeight(30);
    return $son;
};
$genislik_ayarla = function ($sh, string $sonSutun, float $min = 10, float $max = 42) {
    $sonIdx = Coordinate::columnIndexFromString($sonSutun);
    for ($i = 1; $i <= $sonIdx; $i++) $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    $sh->calculateColumnWidths();
    for ($i = 1; $i <= $sonIdx; $i++) {
        $h = Coordinate::stringFromColumnIndex($i);
        $dim = $sh->getColumnDimension($h);
        $w = $dim->getWidth();
        if ($w < 0) $w = $min;
        $dim->setAutoSize(false)->setWidth(max($min, min($max, $w + 2)));
    }
};

$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$ss->getProperties()->setTitle('Malzeme Raporu')->setCreator('Asya Fresh');

// Sayfa 1 — kullanım pivotu
$sh1 = $ss->getActiveSheet();
$sh1->setTitle('Malzeme Kullanımı');
$sh1->setCellValue('A1', 'Malzeme Kullanım Raporu');
$sh1->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sh1->setCellValue('A2', 'Tarih: 21.07.2026 - 19.08.2026');
$date_cols = ['2026-07-21', '2026-07-22', '2026-07-23'];
$b = ['Malzeme', 'Tür', 'Toplam'];
foreach ($date_cols as $d) $b[] = date('d.m.Y', strtotime($d));
$son1 = $baslik_yaz($sh1, $b, 4);
$sh1->fromArray(['Parti No', '', '', 'P-1', 'P-2', ''], null, 'A5');
$UZUN_AD = 'ÇOK UZUN MALZEME ADI TESTİ İÇİN KASA';
$r = 6;
foreach ([[$UZUN_AD, 'Kasa', 1234, [10, 20, 30]], ['Palet 80x120', 'Palet', 56, [1, 2, 3]]] as $row) {
    $sh1->setCellValue('A' . $r, $row[0]);
    $sh1->setCellValue('B' . $r, $row[1]);
    $sh1->setCellValue('C' . $r, $row[2]);
    $c = 4;
    foreach ($row[3] as $v) { $sh1->setCellValue(Coordinate::stringFromColumnIndex($c) . $r, $v); $c++; }
    $r++;
}
$sh1->getStyle('C6:' . $son1 . ($r - 1))->getNumberFormat()->setFormatCode('#,##0');
$sh1->freezePane('D6');
$sh1->setAutoFilter('A4:' . $son1 . '4');
$genislik_ayarla($sh1, $son1);

// Sayfa 2 — stok özeti
$sh2 = $ss->createSheet();
$sh2->setTitle('Stok Özeti');
$sh2->setCellValue('A1', 'Malzeme Stok Özeti (güncel durum)');
$son2 = $baslik_yaz($sh2, ['Kategori', 'Tür', 'Malzeme', 'Depo', 'Giriş', 'Çıkış', 'Kalan', 'Birim'], 4);
$r2 = 5;
foreach ([['Ambalaj', 'Kasa Cinsi', 'Kasa A', 'ÇİVRİL', 1000.0, 250.5, 749.5, 'adet'],
          ['Ambalaj', 'Palet Tipi', 'Palet B', 'ÇİVRİL', 10.0, 25.0, -15.0, 'kg']] as $x) {
    $harfler = ['A','B','C','D','E','F','G','H'];
    foreach ($x as $i => $v) $sh2->setCellValue($harfler[$i] . $r2, $v);
    $sh2->getStyle('E' . $r2 . ':G' . $r2)->getNumberFormat()
        ->setFormatCode($x[7] === 'adet' ? '#,##0' : '#,##0.000');
    if ($x[6] < 0) $sh2->getStyle('G' . $r2)->getFont()->setBold(true)->getColor()->setARGB('FFC0392B');
    $r2++;
}
$sh2->freezePane('A5');
$sh2->setAutoFilter('A4:' . $son2 . '4');
$genislik_ayarla($sh2, $son2);

$ss->setActiveSheetIndex(0);
$dosya = sys_get_temp_dir() . '/rapor_malzeme_smoke_' . getmypid() . '.xlsx';
\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx')->save($dosya);
$ss->disconnectWorksheets();
ok('XLSX dosyası yazıldı', is_file($dosya) && filesize($dosya) > 0);

echo "\n── Geri okuma: dosya açılabiliyor ve biçim korunuyor ──\n";
$ok2 = \PhpOffice\PhpSpreadsheet\IOFactory::load($dosya);
ok('iki sayfa var', $ok2->getSheetCount() === 2, (string)$ok2->getSheetCount());
ok('1. sayfa adı doğru', $ok2->getSheet(0)->getTitle() === 'Malzeme Kullanımı');
ok('2. sayfa adı doğru', $ok2->getSheet(1)->getTitle() === 'Stok Özeti');

$s1 = $ok2->getSheet(0);
ok('başlık satırı KALIN',            $s1->getStyle('A4')->getFont()->getBold() === true);
ok('başlıkta metin KAYDIRMA açık',   $s1->getStyle('A4')->getAlignment()->getWrapText() === true);
ok('başlık dolgu rengi uygulanmış',  $s1->getStyle('A4')->getFill()->getStartColor()->getARGB() === $BASLIK_DOLGU);
ok('başlık satırı SABİT (freeze)',   $s1->getFreezePane() === 'D6', (string)$s1->getFreezePane());
ok('otomatik filtre var',            $s1->getAutoFilter()->getRange() !== '');
// "Okunur" olmanın somut ölçütü: uzun ad taban genişlikte SIKIŞMAMALI.
$genA = $s1->getColumnDimension('A')->getWidth();
ok('uzun malzeme adı için A sütunu genişletilmiş (>20)', $genA > 20, 'A=' . $genA);
ok('kısa sütun gereksiz genişlememiş (B<=20)', $s1->getColumnDimension('B')->getWidth() <= 20);
// Sayılar METİN değil SAYI olmalı ki Excel'de toplanabilsin.
ok('Toplam hücresi SAYI tipinde',    is_numeric($s1->getCell('C6')->getValue()));
ok('tarih hücresi SAYI tipinde',     is_numeric($s1->getCell('D6')->getValue()));

$s2r = $ok2->getSheet(1);
ok('stok: giriş SAYI tipinde',       is_numeric($s2r->getCell('E5')->getValue()));
ok('stok: negatif kalan kırmızı',    $s2r->getStyle('G6')->getFont()->getColor()->getARGB() === 'FFC0392B');
ok('stok: negatif değer korunmuş',   (float)$s2r->getCell('G6')->getValue() === -15.0);
$ok2->disconnectWorksheets();
@unlink($dosya);

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
