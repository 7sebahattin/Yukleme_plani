<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
$auth_user = require_login();
require_perm('records.write');
// Örnek palet şablonu — GERÇEK Excel (.xlsx) olarak indir.
//
// Eskiden HTML tablosu .xls uzantısıyla gönderiliyordu: Excel "biçim ile uzantı
// eşleşmiyor" uyarısı veriyor, kullanıcı kaydedince dosya HTML olarak kalıyordu.
//
// İÇE AKTARMA SÖZLEŞMESİ (assets/app.js parseWorkbook): başlık İLK sayfanın
// 1. SATIRINDA olmalı; sütunlar ada göre eşlenir (Palet No / Kasa Adeti /
// Brüt KG). Bu yüzden ortak xlsx_olustur() düzeni (A1'de rapor başlığı)
// burada KULLANILMAZ — açıklamalar ikinci sayfadadır.
// Veri içermez (sabit örnek) → dışa aktarım yetkisi/audit gerekmez.
xlsx_motor_yukle();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$ss = new Spreadsheet();
$ss->getProperties()->setCreator('Asya Fresh')->setTitle('Örnek Palet Şablonu');

$sh = $ss->getActiveSheet();
$sh->setTitle('Paletler');
$sh->fromArray([
    ['Palet No', 'Kasa Adeti', 'Brüt KG'],
    [1, 120, 2400],
    [2, 120, 2385],
    [3, 100, 2000],
], null, 'A1');
$b = $sh->getStyle('A1:C1');
$b->getFont()->setBold(true);
$b->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(XLSX_RENK_BASLIK_DOLGU);
$b->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(XLSX_RENK_KENAR);
$sh->getStyle('B2:B500')->getNumberFormat()->setFormatCode('#,##0');
$sh->getStyle('C2:C500')->getNumberFormat()->setFormatCode('#,##0.###');
foreach (['A' => 12, 'B' => 14, 'C' => 14] as $col => $w) $sh->getColumnDimension($col)->setWidth($w);
$sh->freezePane('A2');

$ac = $ss->createSheet();
$ac->setTitle('Açıklama');
$ac->fromArray([
    ['Nasıl kullanılır?'],
    ['1. "Paletler" sayfasındaki örnek satırları silip kendi paletlerinizi yazın.'],
    ['2. İlk satırdaki başlıkları (Palet No, Kasa Adeti, Brüt KG) değiştirmeyin.'],
    ['3. Dosyayı kaydedip yükleme formundaki "📥 Excel Yükle" ile seçin.'],
    ['İsteğe bağlı: "Size" başlıklı bir sütun eklenirse o da okunur.'],
], null, 'A1');
$ac->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$ac->getColumnDimension('A')->setWidth(80);
$ss->setActiveSheetIndex(0);

xlsx_gonder($ss, 'ornek_palet_sablonu.xlsx');
