<?php
// =========================================================
// scripts/pdks_fis_xlsx_smoke.php — "Fiş Excel'i" GERÇEK xlsx turu (v446).
//
// Şablon üret → dosyaya yaz → geri oku; AI çıktısı gibi karışık tipli dolu dosya yaz → doğrula → oku →
// normalize; başlık esnekliği; yükleme güvenliği (zip bombası, makro, dış bağlantı, uzantı…).
//   php scripts/pdks_fis_xlsx_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);

$PDO = new PDO('sqlite::memory:');
$PDO->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO; return $PDO; }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $ROOT . '/config/pdks_fis.php';
xlsx_motor_yukle();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as XDate;

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }
$TMP = [];
function tmpdosya(string $ek = '.xlsx'): string { global $TMP; $f = tempnam(sys_get_temp_dir(), 'fis'); @unlink($f); $f .= $ek; $TMP[] = $f; return $f; }
register_shutdown_function(function () { global $TMP; foreach ($TMP as $f) @unlink($f); });
/** $_FILES benzeri: yükleme denetimi test için serbest. */
function dosyaGirdi(string $yol, string $ad = 'fisler.xlsx'): array { return ['name' => $ad, 'type' => 'application/octet-stream', 'tmp_name' => $yol, 'error' => UPLOAD_ERR_OK, 'size' => (int)@filesize($yol)]; }
function dogrula(string $yol, string $ad = 'fisler.xlsx'): array { return pdks_fis_dosya_dogrula(dosyaGirdi($yol, $ad), fn() => true); }
function kaydet(Spreadsheet $ss, ?string $yol = null): string { $yol ??= tmpdosya(); IOFactory::createWriter($ss, 'Xlsx')->save($yol); $ss->disconnectWorksheets(); return $yol; }

// Çavuş tablosu (yükleme ekranındaki çavuş seçimi)
db()->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, normal_work_minutes INTEGER DEFAULT 540, is_active INTEGER DEFAULT 1)");
db()->exec("INSERT INTO foremen (code, name, is_active) VALUES ('C006','Pasif Çavuş',0), ('C005','Test - ÇAVUŞ',1), ('C001','Ali Veli',1), ('C002','=HYPERLINK(\"http://x\")',1)");
$cavuslar = pdks_fis_cavuslar(db());
$cav = $cavuslar[0];   // normalize'a verilen SEÇİLEN çavuş (yükleme ekranındaki seçim)
$gec3 = date('Y-m-d', strtotime('-3 day'));

echo "\n=== 1. Şablon üret → dosyaya yaz → geri oku ===\n";
$ss = pdks_fis_sablon_uret(db());
$yol = kaydet($ss);
ok('1) dosya üretildi (>0 bayt) ve doğrulayıcıdan geçer', (int)filesize($yol) > 1000 && dogrula($yol)['ok'] === true, j(dogrula($yol)));
$wb = IOFactory::load($yol);
ok('1) sayfalar [Fişler, Açıklama, Örnek] (Çavuşlar sayfası YOK — çavuş yükleme ekranında seçilir)', $wb->getSheetNames() === ['Fişler', 'Açıklama', 'Örnek'], j($wb->getSheetNames()));
ok('1) ilk (aktif) sayfa Fişler', $wb->getActiveSheetIndex() === 0);
$ws = $wb->getSheetByName('Fişler');
$sut = pdks_fis_sutunlar();
ok('1) 26 sütun, A..Z (v456: + Şeritçi Tam/Yarım); İşletme / Çavuş sütunu YOK', count($sut) === 26 && Coordinate::stringFromColumnIndex(26) === 'Z' && !array_intersect(array_column($sut, 'anahtar'), ['isletme', 'cavus_kodu', 'cavus_adi']));
$basl = []; foreach ($sut as $i => $c) $basl[] = (string)$ws->getCell([$i + 1, 1])->getValue();
ok('1) Fişler başlıkları pdks_fis_sutunlar() ile birebir', $basl === array_column($sut, 'baslik'), j($basl));
ok('1) AA1 boş (fazladan sütun yok)', (string)$ws->getCell('AA1')->getValue() === '');
ok('1) v463: Rampacı Kişi / Rampacı Yevmiye (eski Rampacı Tam/Yarım KALKTI) + Şeritçi Tam / Yarım sütunları; şablon sürümü 3', array_slice(array_column($sut, 'baslik'), 6, 4) === ['Rampacı Kişi', 'Rampacı Yevmiye', 'Şeritçi Tam', 'Şeritçi Yarım'] && PDKS_FIS_SABLON_SURUMU === 3 && !in_array('Rampacı Tam', $basl, true) && !in_array('Rampacı Yarım', $basl, true), j(array_slice(array_column($sut, 'baslik'), 6, 4)));
ok('1) başlık satırı dondurulmuş (B2)', $ws->getFreezePane() === 'B2', (string)$ws->getFreezePane());
ok('1) Fiş No sütunu METİN biçimi (A2 ve A151)', $ws->getStyle('A2')->getNumberFormat()->getFormatCode() === '@' && $ws->getStyle('A151')->getNumberFormat()->getFormatCode() === '@');
ok('1) Tarih sütunu tarih biçimi', $ws->getStyle('B2')->getNumberFormat()->getFormatCode() === 'dd.mm.yyyy');
// Okuyucu aralık doğrulamalarını hücre hücre açar: ilk ve son hücreyi sorgula.
$dvAra = function (string $aralik) use ($ws) {
    [$ilk, $son] = explode(':', $aralik);
    $a = $ws->getDataValidation($ilk); $b = $ws->getDataValidation($son);
    return ($a->getType() !== DataValidation::TYPE_NONE || $a->getShowInputMessage()) && $a->getType() === $b->getType() && $a->getFormula1() === $b->getFormula1() ? $a : null;
};
$sheetXml = (function () use ($yol) { $z = new ZipArchive(); $z->open($yol); $x = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->close(); return $x; })();
$harfOf = function (string $anahtar) use ($sut) { foreach ($sut as $i => $c) if ($c['anahtar'] === $anahtar) return Coordinate::stringFromColumnIndex($i + 1); return null; };
$fh = $harfOf('fm_tipi'); $ft = $dvAra($fh . '2:' . $fh . '151');
ok('1) FM Tipi: LİSTE Tümü,Kadın,Erkek,Rampacı,Şeritçi,Erkek+Rampacı,Erkek+Şeritçi (≤255 karakter)', $ft && $ft->getType() === DataValidation::TYPE_LIST && str_contains($ft->getFormula1(), 'Erkek+Rampacı') && str_contains($ft->getFormula1(), 'Erkek+Şeritçi') && str_contains($ft->getFormula1(), ',Şeritçi,') && str_contains($ft->getFormula1(), 'Tümü') && strlen($ft->getFormula1()) <= 255, $ft ? $ft->getFormula1() : 'yok');
foreach ($sut as $ci => $c) {
    if ($c['tip'] !== 'tam' || $c['maks'] === null) continue;
    $harf = Coordinate::stringFromColumnIndex($ci + 1); $ust = $c['maks'];
    $v = $dvAra($harf . '2:' . $harf . '151');
    ok("1) $harf ({$c['baslik']}): tam sayı 0–$ust doğrulaması", $v && $v->getType() === DataValidation::TYPE_WHOLE && $v->getFormula1() === '0' && $v->getFormula2() === (string)$ust && $v->getShowErrorMessage() && $v->getError() !== '', $v ? $v->getFormula2() : 'yok');
}
// v456: «Tam» yorum sütunları (Kadın/Erkek/Şeritçi Tam) — «12+1» / «33,5» metin yazılabilsin: STOP tam sayı doğrulaması YOK, yalnız bilgi balonu; biçim General.
$yorumSut = array_values(array_filter($sut, fn($c) => $c['tip'] === 'tam_yorum'));
ok('1) tam_yorum sütunları = Kadın Tam, Erkek Tam, Şeritçi Tam (Rampacı Kişi/Yevmiye DEĞİL)', array_column($yorumSut, 'anahtar') === ['kadin_tam', 'erkek_tam', 'seritci_tam'], j(array_column($yorumSut, 'anahtar')));
foreach ($yorumSut as $c) {
    $harf = $harfOf($c['anahtar']);
    $v = $dvAra($harf . '2:' . $harf . '151');
    ok("1) $harf ({$c['baslik']}): STOP tam sayı doğrulaması YOK (yalnız bilgi balonu), biçim General", $v && $v->getType() === DataValidation::TYPE_NONE && $v->getShowInputMessage() && $v->getPrompt() !== '' && $ws->getStyle($harf . '2')->getNumberFormat()->getFormatCode() === 'General', $v ? $v->getType() : 'yok');
}
foreach (['rampaci_kisi', 'rampaci_yevmiye'] as $ra) {
    $rt = $dvAra($harfOf($ra) . '2:' . $harfOf($ra) . '151');
    ok("1) v463: $ra tam sayı STOP doğrulamalı (Rampacı alanında + / .5 yok)", $rt && $rt->getType() === DataValidation::TYPE_WHOLE);
}
ok('1) v463: eski Rampacı Tam / Yarım sütun harfi YOK', $harfOf('rampaci_tam') === null && $harfOf('rampaci_yarim') === null);
$bd = $dvAra('B2:B151');
ok('1) Tarih sütunu: tarih doğrulaması (≥ 01.01.2020)', $bd && $bd->getType() === DataValidation::TYPE_DATE, $bd ? $bd->getType() : 'yok');
$ac = $wb->getSheetByName('Açıklama');
$komutHucre = false;
foreach ($ac->getCoordinates() as $co) if ((string)$ac->getCell($co)->getValue() === pdks_fis_ocr_komutu()) $komutHucre = true;
ok('1) Açıklama: OCR komutu TEK hücrede, pdks_fis_ocr_komutu() ile birebir', $komutHucre);
$aciklamaMetni = ''; foreach ($ac->getCoordinates() as $co) $aciklamaMetni .= (string)$ac->getCell($co)->getValue() . "\n";
ok('1) Açıklama: şablon sürümü satırı + tüm sütun başlıkları', str_contains($aciklamaMetni, 'Şablon sürümü: ' . PDKS_FIS_SABLON_SURUMU) && !array_filter(array_column($sut, 'baslik'), fn($b) => !str_contains($aciklamaMetni, $b)));
$ork = $wb->getSheetByName('Örnek');
$ornekNo = []; for ($r = 2; $r <= 5; $r++) $ornekNo[] = (string)$ork->getCell([1, $r])->getValue();
ok('1) Örnek: 4 satır, ORNEK-1..4', $ornekNo === ['ORNEK-1', 'ORNEK-2', 'ORNEK-3', 'ORNEK-4'], j($ornekNo));
$ekH = $harfOf('erkek_tam'); $kdH = $harfOf('kadin_tam');
ok('1) Örnek: ORNEK-1 Erkek Tam «12+1» ve ORNEK-4 Kadın Tam «33,5» METİN hücresi (v456 yeni yorum örnekleri)', $ork->getCell($ekH . '2')->getValue() === '12+1' && $ork->getCell($ekH . '2')->getDataType() === DataType::TYPE_STRING && $ork->getCell($kdH . '5')->getValue() === '33,5', j([$ork->getCell($ekH . '2')->getValue(), $ork->getCell($kdH . '5')->getValue()]));
ok('1) Örnek hücreler ("12+1", "33,5") yeni kurala UYAR (say_parca hatasız)', pdks_fis_say_parca('12+1', 250, 'ERKEK')['hata'] === null && pdks_fis_say_parca('33,5', 250, 'KADIN')['hata'] === null);
ok('1) Açıklama: yeni kurallar (A+B, .5, Şeritçi, FM Tipi, toplam FM saati) anlatılmış', str_contains($aciklamaMetni, '12+1') && str_contains($aciklamaMetni, '33,5') && str_contains($aciklamaMetni, 'Şeritçi') && str_contains($aciklamaMetni, 'Toplam FM Saati') && str_contains($aciklamaMetni, 'KALKTI'));
ok('1) OCR komutu: «+» yalnız Erkek Tam / .5 Tam / Şeritçi / toplam FM saati yolu açıkça yazılı', str_contains(pdks_fis_ocr_komutu(), '"Erkek Tam" hücresine OLDUĞU GİBİ yaz') && str_contains(pdks_fis_ocr_komutu(), '33,5') && str_contains(pdks_fis_ocr_komutu(), 'Şeritçi Tam') && str_contains(pdks_fis_ocr_komutu(), 'FM kova sütunlarını (FM 1..6 Sa) BOŞ bırak') && !str_contains(pdks_fis_ocr_komutu(), 'B -> Yarım'));
$ornekBasl = []; foreach ($sut as $i => $c) $ornekBasl[] = (string)$ork->getCell([$i + 1, 1])->getValue();
ok('1) Örnek başlıkları Fişler ile aynı', $ornekBasl === $basl);
$formul = 0; foreach ($wb->getAllSheets() as $sh) foreach ($sh->getCoordinates() as $co) if ($sh->getCell($co)->getDataType() === DataType::TYPE_FORMULA) $formul++;
ok('1) HİÇBİR hücre formül değil', $formul === 0, (string)$formul);
// Örnek sayfası okunan sayfaya sızmaz; şablonun kendisi (Fişler boş) okunur
$ok1 = pdks_fis_oku($yol);
ok('1) boş şablon: okuma ok + 0 satır (Örnek sayfası SIZMAZ)', $ok1['ok'] === true && $ok1['satirlar'] === [], j([$ok1['ok'], $ok1['hata'] ?? null, count($ok1['satirlar'])]));
$n0 = pdks_fis_normalize($ok1, $cav);
ok('1) boş şablon normalize: genel hata (fiş yok)', $n0['fisler'] === [] && $n0['genel_hatalar'] !== []);
$wb->disconnectWorksheets();

// ---- AI çıktısı gibi dolu dosya kurucu ----
/** @param list<string> $basliklar */
function doluDosya(array $basliklar, array $satirlar, int $basSatir = 1, string $sayfa = 'Fişler', ?callable $son = null): string {
    $ss = new Spreadsheet(); $ws = $ss->getActiveSheet(); $ws->setTitle($sayfa);
    foreach ($basliklar as $i => $b) $ws->setCellValueExplicit([$i + 1, $basSatir], $b, DataType::TYPE_STRING);
    foreach ($satirlar as $ri => $satir) {
        foreach ($satir as $ci => $v) {
            if ($v === null) continue;
            if (is_array($v) && ($v['t'] ?? '') === 'formul') $ws->setCellValueExplicit([$ci + 1, $basSatir + 1 + $ri], $v['f'], DataType::TYPE_FORMULA);
            elseif (is_array($v) && ($v['t'] ?? '') === 'tarih') { $ws->setCellValue([$ci + 1, $basSatir + 1 + $ri], $v['v']); $ws->getStyle([$ci + 1, $basSatir + 1 + $ri])->getNumberFormat()->setFormatCode('dd.mm.yyyy'); }
            elseif (is_string($v)) $ws->setCellValueExplicit([$ci + 1, $basSatir + 1 + $ri], $v, DataType::TYPE_STRING);
            else $ws->setCellValue([$ci + 1, $basSatir + 1 + $ri], $v);
        }
    }
    if ($son) $son($ss);
    return kaydet($ss);
}
$B = array_column($sut, 'baslik');
/** Anahtar → değer ile standart sırada satır. */
function satir(array $kv): array { $out = []; foreach (pdks_fis_sutunlar() as $i => $c) $out[$i] = $kv[$c['anahtar']] ?? null; return $out; }
function ham_oku(string $yol): array { $d = dogrula($yol); if (!$d['ok']) return ['dogrula' => $d]; return pdks_fis_oku($yol); }
$serial = XDate::PHPToExcel(new DateTimeImmutable($gec3));

echo "\n=== 2. TUR: karışık tipli AI çıktısı → doğrula → oku → normalize ===\n";
$yol = doluDosya($B, [
    satir(['fis_no' => '01410', 'tarih' => ['t' => 'tarih', 'v' => $serial], 'kadin_tam' => 8, 'erkek_tam' => '12,0', 'erkek_yarim' => '1',
           'fm2' => 5, 'fm_tipi' => 'Erkek', 'giris' => 0.3333333, 'servis_kucuk' => 1, 'not' => 'Rampa', 'ham' => 'Bayan 8 | Erkek 12 + 1 | Toplam Mesai: 5 kişi 2 saat', 'kontrol' => 'Erkek 12 mi 17 mi?']),
    satir(['fis_no' => 1411, 'tarih' => date('d.m.Y', strtotime('-3 day')), 'erkek_tam' => '12 + 1', 'kadin_tam' => ['t' => 'formul', 'f' => '=1+1'], 'not' => '=HYPERLINK("http://x","y")']),
    satir(['fis_no' => 'A-7', 'tarih' => $gec3, 'rampaci_kisi' => 3, 'rampaci_yevmiye' => 5, 'fm_toplam' => '25', 'servis_buyuk' => 2, 'giris' => '09:00']),
    satir([]),   // tamamen boş satır atlanır
]);
// Yazıcı formülün hesaplanmış değerini (<v>) de yazar; "önbelleksiz formül" (Excel dışı araçlar) için <v>'yi sil.
(function () use ($yol) { $z = new ZipArchive(); $z->open($yol); $x = (string)$z->getFromName('xl/worksheets/sheet1.xml'); $z->addFromString('xl/worksheets/sheet1.xml', (string)preg_replace('#(<f>[^<]*</f>)<v>[^<]*</v>#', '$1', $x)); $z->close(); })();
$d = dogrula($yol);
ok('2) doğrulayıcıdan geçti', $d['ok'] === true && strlen((string)$d['sha256']) === 64, j($d));
$o = pdks_fis_oku($yol);
ok('2) okundu: 3 satır (boş satır atlandı), numara 2/3/4', $o['ok'] === true && count($o['satirlar']) === 3 && array_column($o['satirlar'], 'satir') === [2, 3, 4], j([$o['ok'], $o['hata'] ?? null, array_column($o['satirlar'] ?? [], 'satir')]));
$h1 = $o['satirlar'][0]['hucreler'] ?? [];
ok('2) hücre tipleri: Fiş No metin (\'01410\' korunur), sayı sayı', ($h1['fis_no']['v'] ?? null) === '01410' && ($h1['fis_no']['t'] ?? '') === 's' && ($h1['kadin_tam']['v'] ?? null) === 8 && ($h1['kadin_tam']['t'] ?? '') === 'n', j($h1['fis_no'] ?? null));
$nr = pdks_fis_normalize($o, $cav, 'Depo A');
[$f1, $f2, $f3] = $nr['fisler'];
ok('2) fiş 1: hata YOK, tarih Excel serisinden', $f1['hatalar'] === [] && $f1['tarih'] === $gec3, j([$f1['hatalar'], $f1['tarih']]));
ok('2) fiş 1: Fiş No 01410 (sayı değil), çavuş C005, Kadın 8, Erkek 12+1 ("12,0"/"1" metin → sayı)', $f1['fis_no'] === '01410' && !$f1['fis_no_ham_sayi'] && $f1['cavus']['id'] === (int)$cav['id'] && $f1['isletme'] === 'Depo A' && $f1['say']['KADIN']['tam'] === 8 && $f1['say']['ERKEK'] === ['tam' => 12, 'yarim' => 1], j($f1['say']));
ok('2) fiş 1: FM2=5, FM Tipi Erkek, giriş 0.3333333 → 08:00, servis küçük 1, not', $f1['fm_kova'][2] === 5 && $f1['fm_tipi'] === ['ERKEK'] && $f1['giris'] === '08:00' && $f1['servis'] === ['buyuk' => 0, 'kucuk' => 1] && $f1['not'] === 'Rampa', j($f1));
ok('2) fiş 1: Ham Metin + Kontrol Notu okundu (yalnız ekran için)', str_contains($f1['ham'], 'Bayan 8') && $f1['kontrol'] === 'Erkek 12 mi 17 mi?');
ok('2) fiş 2: sayı olarak gelen Fiş No 1411 → uyarı (baştaki sıfır)', $f2['fis_no'] === '1411' && $f2['fis_no_ham_sayi'] === true && (bool)array_filter($f2['uyarilar'], fn($x) => str_contains($x, 'sıfır')), j($f2['uyarilar']));
ok('2) fiş 2: Erkek Tam "12 + 1" → HATA DEĞİL (v456): 12 Erkek + 1 Şeritçi, yorum uyarısı', !array_filter($f2['hatalar'], fn($x) => str_contains($x, '«Erkek Tam»')) && $f2['say']['ERKEK'] === ['tam' => 12, 'yarim' => 0] && $f2['say']['SERITCI'] === ['tam' => 1, 'yarim' => 0] && (bool)array_filter($f2['uyarilar'], fn($x) => str_starts_with($x, 'Satır 3, «Erkek Tam»:') && str_contains($x, '12 Erkek + 1 Şeritçi')), j([$f2['hatalar'], $f2['uyarilar'], $f2['say']]));
ok('2) fiş 2: formül hücresi (önbelleksiz) → «Kadın Tam» hata', (bool)array_filter($f2['hatalar'], fn($x) => str_starts_with($x, 'Satır 3, «Kadın Tam»:') && str_contains($x, 'Formül')), j($f2['hatalar']));
ok('2) fiş 2: =HYPERLINK metin hücresi formül OLMADI, \' önekli kaldı', str_starts_with($f2['not'], "'=HYPERLINK"), $f2['not']);
ok('2) tüm fişler SEÇİLEN çavuşun (dosyada çavuş sütunu yok)', $f2['cavus']['id'] === (int)$cav['id'] && $f3['cavus']['id'] === (int)$cav['id'] && $f2['cavus']['yontem'] === 'secim', j($f2['cavus']));
ok('2) fiş 3: d.m.Y olmayan Y-m-d metin tarih, giriş "09:00", Toplam FM "25" (metin), servis büyük 2', $f3['hatalar'] === [] && $f3['giris'] === '09:00' && $f3['fm_toplam'] === 25 && $f3['servis']['buyuk'] === 2 && $f3['say']['RAMPACI'] === ['tam' => 0, 'yarim' => 0, 'kisi' => 3, 'yevmiye' => 5], j($f3));

// önbellekli formül (Excel'den kaydedilmiş dosya): değer okunur + UYARI (formül ÇALIŞTIRILMAZ)
$yol = doluDosya($B, [satir(['fis_no' => 'F1', 'tarih' => $gec3, 'kadin_tam' => ['t' => 'formul', 'f' => '=1+1']])]);
$o = ham_oku($yol);
$nr = pdks_fis_normalize($o, $cav, 'Depo A');
ok('2) önbellekli formül: değer (2) okunur + "önbellek" uyarısı, hata YOK', $nr['fisler'][0]['hatalar'] === [] && $nr['fisler'][0]['say']['KADIN']['tam'] === 2 && (bool)array_filter($nr['fisler'][0]['uyarilar'], fn($x) => str_contains($x, 'önbellek')), j([$nr['fisler'][0]['hatalar'], $nr['fisler'][0]['uyarilar']]));

// v456: gerçek xlsx turu — «33,5» METİN hücresi, gerçek float 33.5 hücresi, Erkek «12+1», Şeritçi sütunları
$yol = doluDosya($B, [
    satir(['fis_no' => 'Y1', 'tarih' => $gec3, 'kadin_tam' => '33,5', 'erkek_tam' => '12+1', 'seritci_tam' => null, 'seritci_yarim' => 2]),
    satir(['fis_no' => 'Y2', 'tarih' => $gec3, 'kadin_tam' => 33.5, 'erkek_tam' => '+2', 'seritci_tam' => null]),
    satir(['fis_no' => 'Y3', 'tarih' => $gec3, 'seritci_tam' => 4, 'seritci_yarim' => 1, 'rampaci_kisi' => 3, 'rampaci_yevmiye' => 5]),
    satir(['fis_no' => 'Y4', 'tarih' => $gec3, 'kadin_tam' => '12+1', 'seritci_tam' => '3+1']),
    satir(['fis_no' => 'Y5', 'tarih' => $gec3, 'erkek_tam' => '12+1', 'seritci_tam' => 3]),
    satir(['fis_no' => 'Y6', 'tarih' => $gec3, 'kadin_tam' => '33,5', 'kadin_yarim' => 1]),
]);
$o = ham_oku($yol);
$nr = pdks_fis_normalize($o, $cav, 'Depo A');
[$y1, $y2, $y3, $y4, $y5, $y6] = $nr['fisler'];
ok('2b) Y1: Kadın "33,5" (METİN hücre) → 33 Tam + 1 Yarım; Erkek "12+1" → 12 + Şeritçi 1; Şeritçi Yarım 2', $y1['hatalar'] === [] && $y1['say']['KADIN'] === ['tam' => 33, 'yarim' => 1] && $y1['say']['ERKEK'] === ['tam' => 12, 'yarim' => 0] && $y1['say']['SERITCI'] === ['tam' => 1, 'yarim' => 2], j([$y1['hatalar'], $y1['say']]));
ok('2b) Y2: Kadın gerçek float 33.5 hücresi → 33 + 1 Yarım; Erkek "+2" → 2 Şeritçi', $y2['hatalar'] === [] && $y2['say']['KADIN'] === ['tam' => 33, 'yarim' => 1] && $y2['say']['ERKEK']['tam'] === 0 && $y2['say']['SERITCI'] === ['tam' => 2, 'yarim' => 0], j([$y2['hatalar'], $y2['say']]));
ok('2b) Y3: Şeritçi Tam/Yarım + Rampacı Kişi/Yevmiye (3 kişi 5 yevmiye) sütunları okunur', $y3['hatalar'] === [] && $y3['say']['SERITCI'] === ['tam' => 4, 'yarim' => 1] && $y3['say']['RAMPACI'] === ['tam' => 0, 'yarim' => 0, 'kisi' => 3, 'yevmiye' => 5], j([$y3['hatalar'], $y3['say']]));
ok('2b) Y4: Kadın "12+1" ve Şeritçi "3+1" → HATA (tahmin yok)', count(array_filter($y4['hatalar'], fn($x) => str_contains($x, 'yalnız Erkek alanında'))) === 2, j($y4['hatalar']));
ok('2b) Y5: Erkek "12+1" + Şeritçi Tam 3 → çift sayım HATASI', (bool)array_filter($y5['hatalar'], fn($x) => str_contains($x, 'çift sayım')), j($y5['hatalar']));
ok('2b) Y6: Kadın "33,5" + Kadın Yarım dolu → HATA', (bool)array_filter($y6['hatalar'], fn($x) => str_contains($x, 'birlikte kullanılamaz')), j($y6['hatalar']));
// eski şablon (24 sütun, Şeritçi sütunsuz) hâlâ okunur
$eski = array_values(array_filter($B, fn($b) => !in_array($b, ['Şeritçi Tam', 'Şeritçi Yarım'], true)));
$yol = doluDosya($eski, [array_values(array_filter(satir(['fis_no' => 'E1', 'tarih' => $gec3, 'kadin_tam' => 5, 'erkek_tam' => 3, 'erkek_yarim' => 1]), fn($x, $i) => !in_array($i, [8, 9], true), ARRAY_FILTER_USE_BOTH))]);
$o = ham_oku($yol); $ne = pdks_fis_normalize($o, $cav, 'Depo A');
ok('2b) ESKİ şablon (Şeritçi sütunsuz) hâlâ okunur: Erkek Tam 3 + Yarım 1, Şeritçi 0', ($o['ok'] ?? false) && $ne['fisler'][0]['hatalar'] === [] && $ne['fisler'][0]['say']['ERKEK'] === ['tam' => 3, 'yarim' => 1] && $ne['fisler'][0]['say']['SERITCI'] === ['tam' => 0, 'yarim' => 0], j([$o['hata'] ?? null, $ne['fisler'][0] ?? null]));

// v463 — Rampacı sütunları: ESKİ «Rampacı Tam / Yarım» (şablondan kalktı) tanınır; DOLUYSA net HATA, boş/0 ise sessiz; yeni Kişi/Yevmiye kuralları
$ebas = ['Fiş No', 'Tarih', 'Erkek Tam', 'Rampacı Tam', 'Rampacı Yarım'];
$yol = doluDosya($ebas, [['R1', $gec3, 3, 2, null], ['R2', $gec3, 3, null, 1], ['R3', $gec3, 3, 0, 0], ['R4', $gec3, 3, null, null]]);
$o = ham_oku($yol); $ne = pdks_fis_normalize($o, $cav, 'Depo A');
ok('2c) v463: eski «Rampacı Tam/Yarım» başlıkları TANINIR (tanınmayan sütun uyarısına düşmez)', ($o['ok'] ?? false) && ($o['bilinmeyen'] ?? ['x']) === [] && $o['uyarilar'] === [], j([$o['bilinmeyen'] ?? null, $o['uyarilar'] ?? null]));
[$r1, $r2, $r3, $r4] = $ne['fisler'];
ok('2c) eski «Rampacı Tam» DOLU → net HATA (yeni şablon Kişi/Yevmiye)', (bool)array_filter($r1['hatalar'], fn($x) => str_starts_with($x, 'Satır 2, «Rampacı Tam»:') && str_contains($x, 'eski şablona aittir') && str_contains($x, 'Rampacı Kişi') && str_contains($x, 'Rampacı Yevmiye')), j($r1['hatalar']));
ok('2c) eski «Rampacı Yarım» DOLU → net HATA', (bool)array_filter($r2['hatalar'], fn($x) => str_starts_with($x, 'Satır 3, «Rampacı Yarım»:') && str_contains($x, 'eski şablona aittir')), j($r2['hatalar']));
ok('2c) eski sütunlar 0 / boş → sessiz (para yazmaz), hata yok', $r3['hatalar'] === [] && $r4['hatalar'] === [], j([$r3['hatalar'], $r4['hatalar']]));
$yol = doluDosya(['Fiş No', 'Tarih', 'Rampacı Kişi', 'Rampacı Yevmiye', 'Rampaci Tam'], [['K1', $gec3, 3, 5, null], ['K2', $gec3, 3, null, null], ['K3', $gec3, null, 5, null], ['K4', $gec3, 3, 2, null], ['K5', $gec3, 1, 11, null], ['K6', $gec3, '3+1', 5, null], ['K7', $gec3, 3, '4,5', null]]);
$o = ham_oku($yol); $nk = pdks_fis_normalize($o, $cav, 'Depo A');
[$k1, $k2, $k3, $k4, $k5, $k6, $k7] = $nk['fisler'];
ok('2c) K1: 3 kişi 5 yevmiye → hata yok, say RAMPACI kisi=3 yevmiye=5, Tam/Yarım 0', $k1['hatalar'] === [] && $k1['say']['RAMPACI'] === ['tam' => 0, 'yarim' => 0, 'kisi' => 3, 'yevmiye' => 5], j([$k1['hatalar'], $k1['say']]));
ok('2c) K2: kişi var, yevmiye YOK → HATA (yevmiye adedi yok)', (bool)array_filter($k2['hatalar'], fn($x) => str_contains($x, 'yevmiye adedi yok')), j($k2['hatalar']));
ok('2c) K3: yevmiye var, kişi YOK → HATA', (bool)array_filter($k3['hatalar'], fn($x) => str_contains($x, 'kişi sayısı yok')), j($k3['hatalar']));
ok('2c) K4: yevmiye < kişi → HATA', (bool)array_filter($k4['hatalar'], fn($x) => str_contains($x, 'kişi sayısından') && str_contains($x, 'az olamaz')), j($k4['hatalar']));
ok('2c) K5: 1 kişi 11 yevmiye → HATA (OCR hatası şüphesi)', (bool)array_filter($k5['hatalar'], fn($x) => str_contains($x, 'OCR hatası şüphesi')), j($k5['hatalar']));
ok('2c) K6/K7: Rampacı «3+1» ve «4,5» → pdks_fis_say hatası (tahmin YOK)', (bool)array_filter($k6['hatalar'], fn($x) => str_starts_with($x, 'Satır 7, «Rampacı Kişi»:')) && (bool)array_filter($k7['hatalar'], fn($x) => str_starts_with($x, 'Satır 8, «Rampacı Yevmiye»:')), j([$k6['hatalar'], $k7['hatalar']]));

echo "\n=== 3. Başlık esnekliği / dosya düzeyi ===\n";
// karıştırılmış sıra + takma adlar + fazladan sütun
$bas = ['Bayan Tam', 'Fis No', 'Tarih', 'Gizli Sütun', 'Erkek Tam'];
$yol = doluDosya($bas, [[8, 'X1', $gec3, 'abc', 4]]);
$o = ham_oku($yol); $n = pdks_fis_normalize($o, $cav, 'Depo A');
ok('3) karışık sıra + takma ad ("Fis No","Bayan Tam"): alanlar doğru', ($o['ok'] ?? false) && $n['fisler'][0]['fis_no'] === 'X1' && $n['fisler'][0]['say']['KADIN']['tam'] === 8 && $n['fisler'][0]['say']['ERKEK']['tam'] === 4 && $n['fisler'][0]['hatalar'] === [], j([$o['hata'] ?? null, $n['fisler'][0] ?? null]));
ok('3) fazladan sütun: UYARI + bilinmeyen listesi, hata değil', ($o['bilinmeyen'] ?? []) === ['Gizli Sütun'] && (bool)array_filter($o['uyarilar'] ?? [], fn($x) => str_contains($x, 'Gizli Sütun')), j([$o['bilinmeyen'] ?? null, $o['uyarilar'] ?? null]));
// zorunlu sütun yok
$yol = doluDosya(['Fiş No', 'Erkek Tam'], [['X1', 3]]);
$o = ham_oku($yol);
ok('3) zorunlu sütun (Tarih) yok → hata', ($o['ok'] ?? true) === false && str_contains((string)($o['hata'] ?? ''), 'Tarih'), j($o['hata'] ?? null));
// eski şablon (İşletme / Çavuş sütunlu) hâlâ okunur: bu sütunlar yok sayılır (uyarı), seçilen çavuş esastır
$yol = doluDosya(['Fiş No', 'Tarih', 'İşletme', 'Çavuş Kodu', 'Çavuş Adı', 'Erkek Tam'], [['X1', $gec3, 'Depo A', 'C001', 'Ali Veli', 3]]);
$o = ham_oku($yol); $n = pdks_fis_normalize($o, $cav, 'Depo A');
ok('3) eski şablon (İşletme/Çavuş sütunlu): sütunlar yok sayılır + uyarı, fiş seçilen çavuşa yazılır', ($o['ok'] ?? false) && count($o['bilinmeyen'] ?? []) === 3 && $n['fisler'][0]['hatalar'] === [] && $n['fisler'][0]['cavus']['id'] === (int)$cav['id'], j([$o['bilinmeyen'] ?? null, $n['fisler'][0] ?? null]));
// yinelenen başlık
$yol = doluDosya(['Fiş No', 'Tarih', 'Erkek Tam', 'ERKEK TAM'], [['X1', $gec3, 3, 4]]);
$o = ham_oku($yol);
ok('3) yinelenen başlık → hata', ($o['ok'] ?? true) === false && str_contains((string)($o['hata'] ?? ''), 'iki kez'), j($o['hata'] ?? null));
// başlık 3. satırda
$yol = doluDosya($B, [satir(['fis_no' => 'Y1', 'tarih' => $gec3, 'erkek_tam' => 2])], 3, 'Fişler', function (Spreadsheet $ss) { $ss->getActiveSheet()->setCellValueExplicit('A1', 'Fiş Excel dosyası — başlık altta', DataType::TYPE_STRING); });
$o = ham_oku($yol); $n = pdks_fis_normalize($o, $cav, 'Depo A');
ok('3) başlık 3. satırda → kabul, veri satırı 4', ($o['ok'] ?? false) && ($o['satirlar'][0]['satir'] ?? 0) === 4 && $n['fisler'][0]['fis_no'] === 'Y1', j([$o['hata'] ?? null, array_column($o['satirlar'] ?? [], 'satir')]));
// başlık satırı hiç yok
$yol = doluDosya(['a', 'b'], [[1, 2]]);
$o = ham_oku($yol);
ok('3) başlık satırı bulunamadı → hata', ($o['ok'] ?? true) === false && str_contains((string)($o['hata'] ?? ''), 'Başlık'), j($o['hata'] ?? null));
// Fişler sayfası yok
$yol = doluDosya($B, [satir(['fis_no' => 'Z', 'tarih' => $gec3, 'erkek_tam' => 1])], 1, 'Baska');
$o = ham_oku($yol);
ok('3) «Fişler» sayfası yok → hata', ($o['ok'] ?? true) === false && str_contains((string)($o['hata'] ?? ''), 'Fişler'), j($o['hata'] ?? null));
$yol = doluDosya($B, [satir(['fis_no' => 'Z', 'tarih' => $gec3, 'erkek_tam' => 1])], 1, 'FISLER');
$o = ham_oku($yol);
ok('3) sayfa adı TR-katlı eşleşir (FISLER ≡ Fişler)', ($o['ok'] ?? false) === true, j($o['hata'] ?? null));
// ORNEK-1 + aynı fiş no iki satır
$yol = doluDosya($B, [satir(['fis_no' => 'ORNEK-1', 'tarih' => $gec3, 'erkek_tam' => 1]), satir(['fis_no' => 'D1', 'tarih' => $gec3, 'erkek_tam' => 1]), satir(['fis_no' => 'd1', 'tarih' => $gec3, 'erkek_tam' => 1])]);
$n = pdks_fis_normalize(ham_oku($yol), $cav, 'Depo A');
ok('3) ORNEK-1 fiş no → hata', (bool)array_filter($n['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'Örnek')));
ok('3) aynı Fiş No iki satırda (D1/d1) → İKİ satıra da hata', count(array_filter([$n['fisler'][1], $n['fisler'][2]], fn($f) => (bool)array_filter($f['hatalar'], fn($x) => str_contains($x, 'aynı Fiş No')))) === 2);
// 151 / 150 fiş
$mk = function (int $kac) use ($B, $gec3) { $sat = []; for ($i = 1; $i <= $kac; $i++) $sat[] = satir(['fis_no' => 'N' . $i, 'tarih' => $gec3, 'erkek_tam' => 1, 'ham' => str_repeat('x', 200)]); return doluDosya($B, $sat); };
$yol151 = $mk(151); $o = ham_oku($yol151); $n = pdks_fis_normalize($o, $cav, 'Depo A');
ok('3) 151 fiş → genel hata (en çok 150)', $n['fisler'] === [] && $n['genel_hatalar'] !== [] && str_contains($n['genel_hatalar'][0], '150'), j([$n['genel_hatalar'], count($o['satirlar'] ?? [])]));
$yol150 = $mk(150); $t0 = microtime(true); $m0 = memory_get_peak_usage(true);
$o = ham_oku($yol150); $n = pdks_fis_normalize($o, $cav, 'Depo A');
$sure = round((microtime(true) - $t0) * 1000); $mem = round((memory_get_peak_usage(true) - $m0) / 1048576, 1);
ok("3) 150 fiş kabul (okuma+normalize {$sure} ms, +{$mem} MB tepe bellek)", count($n['fisler']) === 150 && $n['genel_hatalar'] === [] && !array_filter($n['fisler'], fn($f) => $f['hatalar'] !== [] && !str_contains(implode('', $f['hatalar']), 'aynı çavuş')), j([count($n['fisler']), $n['genel_hatalar']]));
ok('3) 150 fiş: süre < 15 sn ve bellek artışı < 120 MB', $sure < 15000 && $mem < 120, "$sure ms / $mem MB");
// boş dosya
$yol = doluDosya($B, []);
$n = pdks_fis_normalize(ham_oku($yol), $cav, 'Depo A');
ok('3) yalnız başlık (veri yok) → genel hata', $n['fisler'] === [] && $n['genel_hatalar'] !== []);
// kesinlikle aynı çavuş+gün
$yol = doluDosya($B, [satir(['fis_no' => 'K1', 'tarih' => $gec3, 'erkek_tam' => 1]), satir(['fis_no' => 'K2', 'tarih' => $gec3, 'erkek_tam' => 1])]);
$n = pdks_fis_normalize(ham_oku($yol), $cav, 'Depo A');
ok('3) aynı çavuş+gün iki fiş: hata YOK, cakisma_dosya', $n['fisler'][0]['hatalar'] === [] && $n['fisler'][1]['hatalar'] === [] && $n['fisler'][1]['cakisma_dosya'] === true);

echo "\n=== 4. Yükleme güvenliği (PhpSpreadsheet'e GİRMEDEN reddedilir) ===\n";
$gecerli = doluDosya($B, [satir(['fis_no' => 'S1', 'tarih' => $gec3, 'erkek_tam' => 1])]);
$kop = function (string $ek, string $ad = 'a') use ($gecerli) { $y = tmpdosya($ek); copy($gecerli, $y); return $y; };
foreach (['.xls' => 'eski.xls', '.csv' => 'a.csv', '.xlsm' => 'makro.xlsm', '.txt' => 'a.txt', '.php' => 'a.php'] as $ek => $ad) {
    $y = $kop('.xlsx'); $r = dogrula($y, $ad);
    ok("4) uzantı $ad → RED" . ($ek === '.xlsm' ? ' (Excel Çalışma Kitabı yönlendirmesi)' : ''), $r['ok'] === false && str_contains((string)$r['hata'], '.xlsx'), j($r));
}
$y = tmpdosya(); file_put_contents($y, "id,ad\n1,x\n"); $r = dogrula($y);
ok('4) PK olmayan içerik (.xlsx adıyla) → RED', $r['ok'] === false, j($r));
$y = tmpdosya(); file_put_contents($y, "PK\x03\x04" . random_bytes(200)); $r = dogrula($y);
ok('4) bozuk zip (PK başlığı + çöp) → RED', $r['ok'] === false, j($r));
$y = tmpdosya(); file_put_contents($y, ''); $r = dogrula($y);
ok('4) boş dosya → RED', $r['ok'] === false && str_contains((string)$r['hata'], 'boş'), j($r));
$zipEkle = function (string $ad, string $icerik) use ($gecerli) { $y = tmpdosya(); copy($gecerli, $y); $z = new ZipArchive(); $z->open($y); $z->addFromString($ad, $icerik); $z->close(); return $y; };
$r = dogrula($zipEkle('xl/vbaProject.bin', 'VBA'));
ok('4) xl/vbaProject.bin (makro) → RED', $r['ok'] === false && str_contains((string)$r['hata'], 'Makro'), j($r));
$r = dogrula($zipEkle('xl/externalLinks/externalLink1.xml', '<x/>'));
ok('4) xl/externalLinks/ (dış bağlantı) → RED', $r['ok'] === false && str_contains((string)$r['hata'], 'dış bağlantı'), j($r));
$r = dogrula($zipEkle('xl/embeddings/oleObject1.bin', 'OLE'));
ok('4) xl/embeddings/ → RED', $r['ok'] === false, j($r));
$r = dogrula($zipEkle('xl/activeX/activeX1.xml', '<x/>'));
ok('4) xl/activeX/ → RED', $r['ok'] === false, j($r));
$r = dogrula($zipEkle('../evil.txt', 'x'));
ok('4) zip girdisinde ".." → RED', $r['ok'] === false, j($r));
$bomba = $zipEkle('xl/worksheets/bomba.xml', str_repeat("\0", 30 * 1048576));
$bomba_boyut = (int)filesize($bomba);
$r = dogrula($bomba);
ok("4) zip bombası (30 MB'a açılan parça, dosya sadece " . round($bomba_boyut / 1024) . ' KB) → RED', $bomba_boyut < PDKS_FIS_MAX_BAYT && $r['ok'] === false, j([$bomba_boyut, $r]));
$y = tmpdosya(); $z = new ZipArchive(); $z->open($y, ZipArchive::CREATE);
$z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('xl/workbook.xml', '<x/>');
for ($i = 0; $i < 120; $i++) $z->addFromString("xl/worksheets/s$i.xml", '<x/>');
$z->close();
$r = dogrula($y);
ok('4) 100\'den fazla parça → RED', $r['ok'] === false, j($r));
$y = tmpdosya(); $z = new ZipArchive(); $z->open($y, ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('xl/workbook.xml', '<x/>'); $z->close();
$r = dogrula($y);
ok('4) çalışma sayfası olmayan zip (workbook var, sheet yok) kabul EDİLMEZ ya da okunamaz', $r['ok'] === false || (pdks_fis_oku($y)['ok'] ?? true) === false);
$y = tmpdosya(); $f = fopen($y, 'wb'); fwrite($f, "PK\x03\x04"); fwrite($f, str_repeat('A', PDKS_FIS_MAX_BAYT + 10)); fclose($f);
$r = dogrula($y);
ok('4) 2 MB üstü → RED (boyut)', $r['ok'] === false && str_contains((string)$r['hata'], '2 MB'), j($r));
foreach ([UPLOAD_ERR_INI_SIZE => 'çok büyük', UPLOAD_ERR_FORM_SIZE => 'çok büyük', UPLOAD_ERR_PARTIAL => 'yarım', UPLOAD_ERR_NO_FILE => 'seçilmedi', UPLOAD_ERR_NO_TMP_DIR => 'kaydedemedi', UPLOAD_ERR_CANT_WRITE => 'kaydedemedi', UPLOAD_ERR_EXTENSION => 'engellendi'] as $kod => $parca) {
    $r = pdks_fis_dosya_dogrula(['name' => 'a.xlsx', 'tmp_name' => '', 'error' => $kod, 'size' => 0], fn() => true);
    ok("4) UPLOAD_ERR $kod → Türkçe mesaj ($parca)", $r['ok'] === false && str_contains(mb_strtolower((string)$r['hata']), $parca), j($r));
}
$r = pdks_fis_dosya_dogrula([], fn() => true);
ok('4) $_FILES girdisi yok → RED', $r['ok'] === false);
$r = pdks_fis_dosya_dogrula(dosyaGirdi($gecerli));
ok('4) varsayılan denetim: is_uploaded_file(CLI dosyası) false → RED', $r['ok'] === false && str_contains((string)$r['hata'], 'yükle'), j($r));
$r = dogrula($gecerli);
ok('4) geçerli dosya → ok + sha256 + bayt + yol', $r['ok'] === true && $r['sha256'] === hash_file('sha256', $gecerli) && $r['bayt'] === filesize($gecerli) && $r['yol'] === $gecerli, j($r));
$dosyaDurumu = ['mevcut' => is_file($gecerli)];
ok('4) doğrulama dosyayı SİLMEZ/taşımaz (diske kalıcı yazım yok)', $dosyaDurumu['mevcut'] === true);
// okuma: bozuk ama doğrulayıcıyı geçen dosya → Türkçe hata (istisna sızmaz)
$y = tmpdosya(); $z = new ZipArchive(); $z->open($y, ZipArchive::CREATE); $z->addFromString('[Content_Types].xml', '<x/>'); $z->addFromString('xl/workbook.xml', 'bozuk'); $z->addFromString('xl/worksheets/sheet1.xml', 'bozuk'); $z->close();
$r = pdks_fis_oku($y);
ok('4) bozuk xlsx okuma → ok=false + "Dosya okunamadı" (istisna sızmaz)', $r['ok'] === false && str_contains((string)$r['hata'], 'Dosya okunamadı'), j($r['hata'] ?? null));

echo "\n=== 5. Statik ===\n";
$kaynak = file_get_contents($ROOT . '/config/pdks_fis.php');
$temiz = preg_replace('#//[^\n]*|/\*.*?\*/|^\s*\*[^\n]*#sm', '', $kaynak);
ok('5) getCalculatedValue kaynakta YOK (formül motoru çalışmaz)', !str_contains($temiz, 'getCalculatedValue'));
ok('5) okuma setReadDataOnly(true) + setLoadSheetsOnly', str_contains($kaynak, 'setReadDataOnly(true)') && str_contains($kaynak, 'setLoadSheetsOnly'));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
