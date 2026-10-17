<?php
// =========================================================
// scripts/siparis_pkl_smoke.php — PKL v1.4 doğrudan XML doldurma (config/siparis_pkl.php)
//   php scripts/siparis_pkl_smoke.php
//   SIPARIS_ORNEK_DIR=<klasör> php scripts/siparis_pkl_smoke.php   (gerçek örnek PKL'leri yeniden üretip hücre hücre karşılaştırır)
// Şablon (templates/excel/pkl_v14_blank.xlsx) 26 SENTETİK satırla doldurulur; çıktı açılıp şablonla
// karşılaştırılır: zip girdileri/sırası, değişmeyen parçalar bayt bayt, sayfa yapısı, HER hücrenin stili,
// yazılan hücrelerin tip/değeri, özel karakter / formül enjeksiyonu, öncelik kuralları, okuma fonksiyonları.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
ini_set('memory_limit', '1024M');

/** sheet1 hücreleri: koordinat → [s, t, v(çözülmüş), kapali] — boş (kendinden kapanan) dahil. */
function pkl_t_hucreler(string $sheet, array $sst): array
{
    preg_match_all('#<c r="([A-Z]+\d+)"([^>]*?)(?:/>|>(.*?)</c>)#s', $sheet, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as $c) {
        $s = preg_match('/\bs="(\d+)"/', $c[2], $x) ? $x[1] : '';
        $t = preg_match('/\bt="(\w+)"/', $c[2], $x) ? $x[1] : '';
        $v = null; $ic = $c[3] ?? '';
        if (preg_match('#<v>(.*?)</v>#s', $ic, $x)) $v = html_entity_decode($x[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        if ($t === 's' && $v !== null) $v = $sst[(int)$v] ?? null;
        $o[$c[1]] = ['s' => $s, 't' => $t, 'v' => $v, 'f' => str_contains($ic, '<f'), 'bos' => !isset($c[3])];
    }
    return $o;
}
function pkl_t_zip(string $yol): array
{
    $z = new ZipArchive(); $z->open($yol);
    $o = [];
    for ($i = 0; $i < $z->numFiles; $i++) $o[$z->getNameIndex($i)] = $z->getFromIndex($i);
    $z->close();
    return $o;
}
function pkl_t_blok(string $sheet, string $etiket): string
{
    return preg_match('#<' . $etiket . '\b.*?(</' . $etiket . '>|/>)#s', $sheet, $m) ? $m[0] : '';
}

echo "=== 1. Statik + şablon ===\n";
$kaynak = (string)file_get_contents($ROOT . '/config/siparis_pkl.php');
ok('siparis_pkl.php PhpOffice / PhpSpreadsheet sembolü yok', !str_contains($kaynak, 'PhpOffice') && !preg_match('/IOFactory|Spreadsheet\s*\(/', $kaynak));
ok('siparis_pkl.php exec/shell/eval yok', !preg_match('/\b(shell_exec|passthru|proc_open|popen|eval)\s*\(|(?<!->)\bexec\s*\(/', $kaynak));
$tz = pkl_t_zip(SIPARIS_PKL_SABLON);
$tsst = siparis_pkl_sst_oku($tz['xl/sharedStrings.xml']);
ok('şablon sharedStrings yalnız 85 başlık metni', count($tsst) === 85 && $tsst[1] === 'PKL Version 1.4');
ok('şablon yazarı "Asya Fresh"', str_contains($tz['docProps/core.xml'], '<dc:creator>Asya Fresh</dc:creator>'));
ok('şablonda yol/revizyon izi yok', !str_contains($tz['xl/workbook.xml'], 'absPath') && !str_contains($tz['xl/workbook.xml'], 'revisionPtr'));
$tSheet = $tz['xl/worksheets/sheet1.xml'];
$tH = pkl_t_hucreler($tSheet, $tsst);
ok('şablon veri alanı boş (17. satırdan itibaren değer yok)', count(array_filter($tH, fn($c, $k) => (int)preg_replace('/\D/', '', $k) >= 17 && $c['v'] !== null, ARRAY_FILTER_USE_BOTH)) === 0);

echo "\n=== 2. Saf yardımcılar ===\n";
ok('lot 2026-10-16 → L 42 - 05', siparis_pkl_lot('2026-10-16') === 'L 42 - 05');
ok('lot 2027-01-04 → L 01 - 01', siparis_pkl_lot('2027-01-04') === 'L 01 - 01');
ok('lot 2026-10-02 → L 40 - 05', siparis_pkl_lot('2026-10-02') === 'L 40 - 05');
ok('dosya adı', siparis_pkl_dosya_adi('TC426865.10', 'AsyaDoc004', '2026-10-16') === 'PKL-TC426865.10-AsyaDoc004-16.10.2026.xlsx');
ok('dosya adı temizlenir, sonda _ yok', siparis_pkl_dosya_adi('TC1/../x_', 'Asya Doc_', '2026-10-16') === 'PKL-TC1..x-AsyaDoc-16.10.2026.xlsx');
ok('seri gün 2026-10-16 = 46311', siparis_pkl_serial('2026-10-16') === 46311 && siparis_pkl_serial_tarih(46311) === '2026-10-16');
ok('GS1 kontrol basamağı', siparis_pkl_gs1_ok('8683071468713') && !siparis_pkl_gs1_ok('8683071468714') && !siparis_pkl_gs1_ok('123'));
ok('sayı: TR biçimi + kanonik', siparis_pkl_sayi('1.234') === '1234' && siparis_pkl_sayi('3,5') === '3.5' && siparis_pkl_sayi(801) === '801' && siparis_pkl_sayi('abc') === null && siparis_pkl_sayi('') === null);
ok('esc denetim karakterini atar', siparis_pkl_esc("a\x01b<&>") === 'ab&lt;&amp;&gt;');

echo "\n=== 3. Veri → satırlar (öncelik) ===\n";
$sev = ['id' => 1, 'order_ref' => 'TC900001.10', 'yukleme_tarihi' => '2026-10-16', 'plakalar' => '34TST001-34TST002', 'navlun_no' => '6100000001',
        'alici_kodu' => '1000001', 'alici_adi' => 'LIDL XX', 'toplam_palet' => 26];
$kal = [
    ['id' => 1, 'kalibre' => '10', 'artikel_no' => '10000010', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'ambalaj' => 'Carton, 40 x 30 x 11 CM', 'pf' => 200, 'urun' => 'Testfruit', 'etkin_palet' => 12, 'kaynaklar' => null],
    ['id' => 2, 'kalibre' => '12', 'artikel_no' => '10000012', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'ambalaj' => 'Carton', 'pf' => 200, 'urun' => 'Testfruit', 'etkin_palet' => 14, 'kaynaklar' => null],
    ['id' => 3, 'kalibre' => '14', 'artikel_no' => '10000014', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'ambalaj' => 'Carton', 'pf' => 200, 'urun' => 'Testfruit', 'etkin_palet' => 0, 'kaynaklar' => null],
];
$profil = ['id' => 7, 'uretici_adi' => 'URETICI TEST', 'ggn' => '4000000000006', 'tarla_kodu' => '', 'izlenebilirlik_kodu' => 'TRC-TEST-1', 'kalinti_rapor_no' => 'TR-TEST-1', 'kalinti_tarihi' => '2026-10-02', 'lab_adi' => 'LAB TEST'];
$veri = siparis_pkl_varsayilan($sev, $kal, $profil);
ok('varsayılan: etkin_palet>0 kalemler (14 yok), kalibre artan', array_column($veri['bloklar'], 'kalibre') === ['10', '12'] && $veri['bloklar'][0]['palet'] === 12);
ok('varsayılan: D/F/H/T, K sezon, O = P − 1, R/S sabit', $veri['bloklar'][0]['D'] === '10000010' && $veri['bloklar'][0]['H'] === '3,5 kg' && $veri['bloklar'][0]['T'] === 200
    && $veri['varsayilan']['K'] === 2026 && $veri['varsayilan']['P'] === '2026-10-16' && $veri['varsayilan']['O'] === '2026-10-15' && $veri['varsayilan']['S'] === '8683071468713');
$post = $veri;
$post['varsayilan']['M'] = '812'; $post['varsayilan']['N'] = '722';
$post['varsayilan']['J'] = '=HYPERLINK(1)';
$post['varsayilan']['L'] = 'A&B <x> "q"';
$post['bloklar'][1]['M'] = '900';
$post['satir'] = ['0' => ['M' => '805', 'X' => "ctl\x01char"], '13' => ['M' => '950', 'O' => '14.10.2026'], 'kotu' => ['M' => '1'], '25' => ['Q' => 'X']];
$post['bloklar'][] = ['kalem_id' => 999, 'palet' => 5];   // başka sevkiyatın kalemi → atılır
$veri2 = siparis_pkl_girdi_temizle($post, $kal);
ok('girdi temizle: yabancı kalem bloğu atıldı, sayılar kanonik', count($veri2['bloklar']) === 2 && $veri2['varsayilan']['M'] === '812' && $veri2['bloklar'][1]['M'] === '900');
ok('girdi temizle: satır anahtarı beyaz liste, Q gibi türetilen sütun kabul edilmez', array_map('strval', array_keys($veri2['satir'])) === ['0', '13'] && !isset($veri2['satir']['25']) && $veri2['satir']['13']['O'] === '2026-10-14');
$rows = siparis_pkl_satirlar($sev, $kal, $veri2, 75);
$v = fn(int $i, string $c) => $rows[$i][$c][1] ?? null;
ok('26 satır, C = 75..100', count($rows) === 26 && $v(0, 'C') === '75' && $v(25, 'C') === '100');
ok('türetilen: B, E büyük, G metin, I CARTON BOXES', $v(0, 'B') === 'TC900001.10' && $v(0, 'E') === 'TESTFRUIT' && $rows[0]['G'] === ['s', '10'] && $rows[12]['G'] === ['s', '12'] && $v(0, 'I') === 'CARTON BOXES');
ok('öncelik M: satır > blok > varsayılan', $v(0, 'M') === '805' && $v(1, 'M') === '812' && $v(12, 'M') === '900' && $v(13, 'M') === '950');
ok('O = P − 1 (satır O önceliği), Q = lot(P), U = T', $v(0, 'O') === '2026-10-15' && $v(13, 'O') === '2026-10-14' && $v(0, 'Q') === 'L 42 - 05' && $v(5, 'U') === '200' && $v(5, 'T') === '200');
ok('profil V..AB, boş X atlanır', $v(3, 'V') === 'URETICI TEST' && $rows[3]['W'] === ['n', '4000000000006'] && $rows[3]['X'] === null && $rows[3]['AA'] === ['d', '2026-10-02']);
$hdr = siparis_pkl_baslik($sev, ['belge_tarihi' => null, 'eta' => null], $veri2, 'AsyaDoc004');
ok('başlık: C4/C5/C8/C9/C10/C11/C12, C13 boş', $hdr['C4'] === ['s', 'AsyaDoc004'] && $hdr['C5'] === ['d', '2026-10-16'] && $hdr['C9'] === ['n', '6100000001'] && $hdr['C13'] === null && $hdr['C11'] === ['s', 'LIDL XX']);
$kon = siparis_pkl_on_kontrol($sev, $kal, $veri2, $hdr, $rows);
ok('ön kontrol: hata yok', array_filter($kon, fn($x) => $x['seviye'] === 'hata') === [], json_encode($kon));
$eks = siparis_pkl_eksikler($hdr, $rows);
ok('eksikler: yalnız X (isteğe bağlı) boş → 0', $eks['toplam'] === 0, json_encode($eks));
$sevKotu = $sev; $sevKotu['navlun_no'] = null; $sevKotu['plakalar'] = '34 TST'; $sevKotu['alici_kodu'] = '12';
$hk = siparis_pkl_baslik($sevKotu, [], $veri2, 'AsyaDoc004');
$kodlar = array_column(siparis_pkl_on_kontrol($sevKotu, $kal, array_merge($veri2, ['bloklar' => [$veri2['bloklar'][0]]]), $hk, $rows), 'kod');
ok('ön kontrol: navlun yok = hata, plaka/alıcı/palet toplamı uyarı', in_array('navlun_yok', $kodlar, true) && in_array('plaka_bicim', $kodlar, true) && in_array('alici_kodu_bicim', $kodlar, true) && in_array('palet_toplam_farkli', $kodlar, true), implode(',', $kodlar));
$kalF = $kal; $kalF[0]['kaynaklar'] = json_encode(['cesit' => ['po' => ['v' => 'WONDERFUL']]]);
ok('ön kontrol: blok çeşidi PO çeşidinden farklı → uyarı', in_array('cesit_farkli', array_column(siparis_pkl_on_kontrol($sev, $kalF, $veri2, $hdr, $rows), 'kod'), true));
$eks2 = siparis_pkl_eksikler(['C4' => null] + $hdr, array_map(fn($r) => ['M' => null] + $r, $rows));
ok('eksikler: alan bazlı sayım', $eks2['alanlar']['C4'] === 1 && $eks2['alanlar']['M'] === 26 && $eks2['toplam'] === 27);

echo "\n=== 4. Şablonu doldur ===\n";
$cikti = $SIPARIS_TEST_DIR . '/pkl_test.xlsx';
$t0 = microtime(true);
siparis_pkl_doldur(SIPARIS_PKL_SABLON, $hdr, $rows, $cikti);
$sure = microtime(true) - $t0;
ok('dosya üretildi, .part kalmadı', is_file($cikti) && !is_file($cikti . '.part'));
echo sprintf("   (%.2f sn, tepe bellek %.0f MB)\n", $sure, memory_get_peak_usage(true) / 1048576);
$oz = pkl_t_zip($cikti);
ok('(1) zip girdi listesi ve SIRASI şablonla aynı', array_keys($oz) === array_keys($tz));
$farkli = [];
foreach ($tz as $ad => $ic) if (!in_array($ad, ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'], true) && $oz[$ad] !== $ic) $farkli[] = $ad;
ok('(2) diğer tüm parçalar bayt bayt aynı', $farkli === [], implode(',', $farkli));
$oSheet = $oz['xl/worksheets/sheet1.xml'];
ok('(3) 10 000 <row>, cols / dataValidations / sheetViews / mergeCells / pageSetup / pageMargins aynı',
    substr_count($oSheet, '<row ') === 10000 && substr_count($tSheet, '<row ') === 10000
    && pkl_t_blok($oSheet, 'cols') === pkl_t_blok($tSheet, 'cols') && pkl_t_blok($oSheet, 'dataValidations') === pkl_t_blok($tSheet, 'dataValidations')
    && pkl_t_blok($oSheet, 'sheetViews') === pkl_t_blok($tSheet, 'sheetViews') && pkl_t_blok($oSheet, 'mergeCells') === pkl_t_blok($tSheet, 'mergeCells')
    && pkl_t_blok($oSheet, 'pageSetup') === pkl_t_blok($tSheet, 'pageSetup') && pkl_t_blok($oSheet, 'pageMargins') === pkl_t_blok($tSheet, 'pageMargins')
    && pkl_t_blok($tSheet, 'dataValidations') !== '');
$osst = siparis_pkl_sst_oku($oz['xl/sharedStrings.xml']);
$oH = pkl_t_hucreler($oSheet, $osst);
$stilFark = 0;
foreach ($tH as $k => $c) if (!isset($oH[$k]) || $oH[$k]['s'] !== $c['s']) $stilFark++;
ok('(4) HER hücrenin stili şablonla aynı (' . count($tH) . ' hücre), yeni hücre yok', $stilFark === 0 && count($oH) === count($tH), "fark=$stilFark");
ok('(4b) şablon metinleri ilk 85 sırada korunur', array_slice($osst, 0, 85) === $tsst);
ok('(5) B17 paylaşılan metin, C17 sayı, P17 tarih seri 46311, G17 metin "10"', $oH['B17']['t'] === 's' && $oH['B17']['v'] === 'TC900001.10' && $oH['C17']['t'] === '' && $oH['C17']['v'] === '75'
    && $oH['P17']['v'] === '46311' && $oH['G17']['t'] === 's' && $oH['G17']['v'] === '10' && $oH['S17']['v'] === '8683071468713');
ok('(5) başlık C4 metin, C5 seri, C9 sayı; sabit C2/C3 korunur', $oH['C4']['v'] === 'AsyaDoc004' && $oH['C5']['v'] === '46311' && $oH['C9']['t'] === '' && $oH['C9']['v'] === '6100000001'
    && $oH['C2']['v'] === '1102720' && $oH['C3']['v'] === 'ASYA FRESH');
ok('(5) yazılmayan hücreler kendinden kapanan kalır (A17, X18, AC17, B43, C13)', $oH['A17']['bos'] && $oH['X18']['bos'] && $oH['AC17']['bos'] && $oH['B43']['bos'] && $oH['C13']['bos'] && !$oH['B42']['bos']);
ok('(5) M satır önceliği dosyada: M17=805, M30=950, M29=900, M18=812', $oH['M17']['v'] === '805' && $oH['M30']['v'] === '950' && $oH['M29']['v'] === '900' && $oH['M18']['v'] === '812');
ok('(6) özel karakterli metin aynen döner', $oH['L17']['v'] === 'A&B <x> "q"');
ok('(6) "=HYPERLINK(1)" METİN (formül değil)', $oH['J17']['v'] === '=HYPERLINK(1)' && $oH['J17']['t'] === 's' && !$oH['J17']['f'] && !str_contains($oSheet, '<f>'));
ok('(6) denetim karakteri atıldı', $oH['X17']['bos'] === false ? $oH['X17']['v'] === 'ctlchar' : false);
$hata = '';
try { siparis_pkl_doldur(SIPARIS_PKL_SABLON, ['C2' => ['n', '1']], [], $SIPARIS_TEST_DIR . '/x.xlsx'); } catch (RuntimeException $e) { $hata = $e->getMessage(); }
ok('dolu şablon hücresine yazmak RuntimeException', str_contains($hata, 'C2') && !is_file($SIPARIS_TEST_DIR . '/x.xlsx'));
siparis_pkl_doldur(SIPARIS_PKL_SABLON, ['C9' => ['n', '61-00'], 'C10' => ['n', '=1+1']], [], $SIPARIS_TEST_DIR . '/n.xlsx');
$nH = pkl_t_hucreler(pkl_t_zip($SIPARIS_TEST_DIR . '/n.xlsx')['xl/worksheets/sheet1.xml'], siparis_pkl_sst_oku(pkl_t_zip($SIPARIS_TEST_DIR . '/n.xlsx')['xl/sharedStrings.xml']));
ok("'n' tipli sayısal olmayan değer metin olarak yazılır", $nH['C9']['t'] === 's' && $nH['C9']['v'] === '61-00' && $nH['C10']['t'] === 's' && $nH['C10']['v'] === '=1+1');

echo "\n=== 5. Okuma (siparis_pkl_oku / siparis_xlsx_onizleme) ===\n";
$ok = siparis_pkl_oku($cikti);
ok('pkl_oku: PKL tanındı, belge no, order_refs, palet aralığı, satır', $ok['ok'] && $ok['pkl'] && $ok['belge_no'] === 'AsyaDoc004' && $ok['order_refs'] === ['TC900001.10']
    && $ok['palet_min'] === 75 && $ok['palet_max'] === 100 && $ok['satir'] === 26, json_encode($ok));
ok('pkl_oku: başlık tarihleri gg.aa.yyyy', ($ok['baslik']['C5'] ?? '') === '16.10.2026' && ($ok['baslik']['C2'] ?? '') === '1102720');
$on = siparis_xlsx_onizleme($cikti, 5);
ok('önizleme: başlık etiketleri, sütun etiketleri, 5 satır / toplam 26', ($on['baslik']['Delivery Document No.'] ?? '') === 'AsyaDoc004' && ($on['baslik']['Delivery Document Date'] ?? '') === '16.10.2026'
    && $on['sutunlar'][0] === 'Order Reference' && count($on['sutunlar']) === 27 && count($on['satirlar']) === 5 && $on['satir_sayisi'] === 26);
ok('önizleme: tarih sütunları biçimli, metin kalibre', $on['satirlar'][0]['Packing Date'] === '16.10.2026' && $on['satirlar'][0]['Harvesting Date'] === '15.10.2026' && $on['satirlar'][0]['Calibre'] === '10');
$bosOz = siparis_pkl_oku(SIPARIS_PKL_SABLON);
ok('boş şablon: PKL ama satır yok', $bosOz['ok'] && $bosOz['pkl'] && $bosOz['satir'] === 0 && $bosOz['belge_no'] === null);
ok('bozuk dosya: ok=false', siparis_pkl_oku(__FILE__)['ok'] === false && siparis_xlsx_onizleme(__FILE__)['satir_sayisi'] === 0);

echo "\n=== 6. PhpSpreadsheet ile salt-okuma doğrulaması ===\n";
if (is_file($ROOT . '/vendor/autoload.php')) {
    require_once $ROOT . '/vendor/autoload.php';
    $rd = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $rd->setReadDataOnly(true);
    $rd->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function readCell($c, $r, $w = ''): bool { return $r <= 20; }
    });
    $ss = $rd->load($cikti);
    $sh = $ss->getSheet(0);
    ok('(8) IOFactory okur: sayfa adı, C4, B17, J17 metin', $sh->getTitle() === 'Packing List' && $sh->getCell('C4')->getValue() === 'AsyaDoc004'
        && $sh->getCell('B17')->getValue() === 'TC900001.10' && $sh->getCell('J17')->getDataType() === 's');
    $ss->disconnectWorksheets();
} else {
    echo "ATLANDI (vendor yok)\n";
}

echo "\n=== 7. Gerçek örnek PKL'leri yeniden üretme ===\n";
$orn = siparis_test_ornekler();
if ($orn === null) {
    echo "ATLANDI (SIPARIS_ORNEK_DIR yok)\n";
} else {
    $tarihHucre = ['C5', 'C12', 'C13'];
    $tarihSutun = ['O', 'P', 'AA'];
    foreach (['1', '2'] as $set) {
        $yol = $orn[$set]['pkl'] ?? null;
        if ($yol === null) { ok("set $set: örnek PKL bulundu", false); continue; }
        $sz = pkl_t_zip($yol);
        $ssst = siparis_pkl_sst_oku($sz['xl/sharedStrings.xml']);
        $sH = pkl_t_hucreler($sz['xl/worksheets/sheet1.xml'], $ssst);
        // pkl_to_spec portu: örnekten başlık + satır hücrelerini [tip, değer] olarak çıkar
        $conv = function (string $k, array $c) use ($tarihHucre, $tarihSutun): ?array {
            if ($c['v'] === null || $c['v'] === '') return null;
            if (in_array($c['t'], ['s', 'str', 'inlineStr'], true)) return ['s', $c['v']];
            $col = preg_replace('/\d/', '', $k);
            if (in_array($k, $tarihHucre, true) || (in_array($col, $tarihSutun, true) && (int)preg_replace('/\D/', '', $k) >= 17)) return ['d', siparis_pkl_serial_tarih((float)$c['v'])];
            return ['n', $c['v']];
        };
        $hdrS = [];
        for ($r = 2; $r <= 13; $r++) { $k = 'C' . $r; if (in_array($k, SIPARIS_PKL_HDR_KEEP, true) || !isset($sH[$k])) continue; $x = $conv($k, $sH[$k]); if ($x) $hdrS[$k] = $x; }
        $rowsS = [];
        for ($r = 17; $r < 10000; $r++) {
            $row = [];
            foreach (array_keys(SIPARIS_PKL_SUTUN_TIP) as $col) if (isset($sH[$col . $r])) { $x = $conv($col . $r, $sH[$col . $r]); if ($x) $row[$col] = $x; }
            if (!$row) break;
            $rowsS[] = $row;
        }
        $hedef = $SIPARIS_TEST_DIR . "/regen_$set.xlsx";
        siparis_pkl_doldur(SIPARIS_PKL_SABLON, $hdrS, $rowsS, $hedef);
        $rz = pkl_t_zip($hedef);
        $rH = pkl_t_hucreler($rz['xl/worksheets/sheet1.xml'], siparis_pkl_sst_oku($rz['xl/sharedStrings.xml']));
        $degerFark = []; $stilFark = [];
        foreach ($sH as $k => $c) {
            $o = $rH[$k] ?? null;
            if ($o === null) { $degerFark[] = $k; continue; }
            if ((string)$o['v'] !== (string)$c['v'] || ($o['t'] === 's') !== ($c['t'] === 's')) $degerFark[] = $k;
            if ($o['s'] !== $c['s']) $stilFark[] = $k;
        }
        foreach ($rH as $k => $o) if (!isset($sH[$k]) && $o['v'] !== null) $degerFark[] = $k;
        $istisna = $set === '1' ? ['Z17', 'AA17', 'AB17'] : [];   // set 1 örneğinde kullanıcı ilk veri satırını elle biçimlemiş (belgelendi)
        $stilFark = array_values(array_diff($stilFark, $istisna));
        ok("set $set: " . count($rowsS) . ' satır yeniden üretildi, ' . count($sH) . ' hücre: 0 değer farkı', $degerFark === [], implode(',', array_slice($degerFark, 0, 10)));
        ok("set $set: 0 stil farkı" . ($istisna ? ' (Z17/AA17/AB17 istisnası belgeli)' : ''), $stilFark === [], implode(',', array_slice($stilFark, 0, 10)));
        $oo = siparis_pkl_oku($yol);
        ok("set $set: örnek pkl_oku", $oo['ok'] && $oo['pkl'] && $oo['satir'] === count($rowsS) && $oo['belge_no'] === ($set === '1' ? 'AsyaDoc004' : 'AsyaDoc001')
            && $oo['palet_min'] === ($set === '1' ? 75 : 1), json_encode([$oo['belge_no'], $oo['satir'], $oo['palet_min'], $oo['palet_max']]));
    }
}

siparis_test_bitir();
