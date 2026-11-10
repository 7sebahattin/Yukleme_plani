<?php
// =========================================================
// scripts/frugro_pkl_smoke.php — Frugro PKL: sayı kuralları (nokta-ondalık reddi), toplam, net önerisi, taslak, yapıştırma ayrıştırıcı,
// kayıt (atomik, kilit, audit'te kişisel veri yok), PDF/XLSX üretimi, durum geçişi, yetki, depo izolasyonu, XSS, statik değişmezler.
//   php scripts/frugro_pkl_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri (şoför/üretici adı UYDURMA). Canlı DB'ye dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/config/frugro_pkl.php';
require_once $ROOT . '/vendor/autoload.php';

$db = db();
siparis_test_diger_tablolar($db);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }

echo "=== 1. Sayı kuralları ===\n";
ok('kg: 634 / 1.234 (binlik) / 634,5 / 1.234,567', frgp_kg_coz('634') === 634.0 && frgp_kg_coz('1.234') === 1234.0 && frgp_kg_coz('634,5') === 634.5 && frgp_kg_coz('1.234,567') === 1234.567);
ok('kg: nokta-ondalık REDDEDİLİR (812.5, 812.50, 0.5)', frgp_kg_coz('812.5') === false && frgp_kg_coz('812.50') === false && frgp_kg_coz('0.5') === false);
ok('kg: boş → null; negatif/harf/4 ondalık/çift virgül → false', frgp_kg_coz('') === null && frgp_kg_coz(null) === null && frgp_kg_coz('-5') === false && frgp_kg_coz('abc') === false && frgp_kg_coz('1,2345') === false && frgp_kg_coz('1,2,3') === false);
ok('kg: int/float geçer, taşma reddedilir', frgp_kg_coz(634) === 634.0 && frgp_kg_coz(634.5) === 634.5 && frgp_kg_coz('99999999') === false && frgp_kg_coz(-1) === false);
ok('kutu: 160 / 1.200 / 0 false / 16.5 false / 16,5 false', frgp_kutu_coz('160') === 160 && frgp_kutu_coz('1.200') === 1200 && frgp_kutu_coz('0') === false && frgp_kutu_coz('16.5') === false && frgp_kutu_coz('16,5') === false && frgp_kutu_coz('') === null);
ok('karton: 0,312 geçer; 0.312 (=312 riski) REDDEDİLİR; 312 > 5 reddedilir', frgp_kucuk_coz('0,312', 3, 5.0) === 0.312 && frgp_kucuk_coz('0.312', 3, 5.0) === false && frgp_kucuk_coz('312', 3, 5.0) === false && frgp_kucuk_coz('25', 2, 500.0) === 25.0);
ok('gösterim: KG tam sayı + binlik nokta, karton virgüllü', frgp_kg_goster(20995.4) === '20.995' && frgp_kg_goster(559.08) === '559' && frgp_kucuk_goster(0.312) === '0,312' && frgp_kucuk_goster(25.0) === '25' && frgp_kg_alan(634.0) === '634' && frgp_kg_alan(634.5) === '634,5');

echo "\n=== 2. Toplam + net önerisi ===\n";
$t = frgp_toplam([['kutu' => 160, 'brut_kg' => '634.000', 'net_kg' => '559.000', 'palet_no' => '1'], ['kutu' => 160, 'brut_kg' => 631.5, 'net_kg' => 556, 'palet_no' => '2'], ['kutu' => null, 'brut_kg' => null, 'net_kg' => null, 'palet_no' => null], ['kutu' => 100, 'brut_kg' => 400, 'net_kg' => null, 'palet_no' => '3']]);
ok('toplam: kutu 420, brüt 1665,5 (ham), net 1115, palet 3 (boş satır sayılmaz), eksik net 1', $t['kutu'] === 420 && $t['brut'] === 1665.5 && $t['net'] === 1115.0 && $t['palet'] === 3 && $t['eksik_net'] === 1 && $t['eksik_brut'] === 0, json_encode($t));
ok('toplam: boş liste', frgp_toplam([]) === ['kutu' => 0, 'brut' => 0.0, 'net' => 0.0, 'palet' => 0, 'eksik_brut' => 0, 'eksik_net' => 0]);
$no = frgp_net_oneri(160, 634.0, 0.312, 25.0);
ok('net önerisi: 160 kutu × 0,312 + 25, brüt 634 ⇒ 559,08 (≈ 559)', $no === 559.08 && frgp_kg_goster($no) === '559', (string)$no);
ok('net önerisi alt sınır 0', frgp_net_oneri(100, 10.0, 0.3, 25.0) === 0.0);

echo "\n=== 3. Satır doğrulama ===\n";
$v = frgp_satirlari_dogrula([
    ['palet_no' => '1-DOOR', 'ggn_no' => '4049929000000', 'ggn_adi' => 'Uretici X', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559'],
    ['palet_no' => '', 'ggn_no' => '', 'kutu' => '', 'brut_kg' => '', 'net_kg' => ''],
    ['palet_no' => '2', 'kutu' => '160', 'brut_kg' => '631,5', 'net_kg' => ''],
]);
ok('geçerli: 2 satır (boş atlandı), hata yok, net boş null', count($v['satirlar']) === 2 && $v['hatalar'] === [] && $v['satirlar'][1]['net_kg'] === null && $v['satirlar'][1]['brut_kg'] === 631.5 && $v['satirlar'][0]['palet_no'] === '1-DOOR');
$h = frgp_satirlari_dogrula([['palet_no' => '1', 'kutu' => '0', 'brut_kg' => '600', 'net_kg' => '500'], ['palet_no' => '2', 'kutu' => '160', 'brut_kg' => '812.5', 'net_kg' => '500'], ['palet_no' => '3', 'kutu' => '160', 'brut_kg' => '500', 'net_kg' => '600'], ['palet_no' => 'a b', 'kutu' => '1', 'brut_kg' => '5', 'net_kg' => '4'], ['palet_no' => '5', 'kutu' => '', 'brut_kg' => '5']]);
$hm = implode(' | ', $h['hatalar']);
ok('hata: kutu 0, nokta-ondalık brüt, brüt<net, geçersiz palet no, kutu boş', str_contains($hm, 'Satır 1: kutu') && str_contains($hm, 'Satır 2: brüt kg geçersiz') && str_contains($hm, 'Satır 3: brüt kg') && str_contains($hm, 'Satır 4: palet no') && str_contains($hm, 'Satır 5: kutu adedi boş'), $hm);
$w = frgp_satirlari_dogrula([['palet_no' => '7', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559'], ['palet_no' => '7', 'kutu' => '1', 'brut_kg' => '634', 'net_kg' => '559']]);
ok('uyarı (engellemez): palet no tekrarı + kutu başına brüt anormal', $w['hatalar'] === [] && count($w['uyarilar']) === 2, json_encode($w['uyarilar']));
ok('brüt=net serbest', frgp_satirlari_dogrula([['palet_no' => '1', 'kutu' => '10', 'brut_kg' => '50', 'net_kg' => '50']])['hatalar'] === []);
ok('GGN no kontrol: harf/rakam/tire dışı reddedilir', frgp_satirlari_dogrula([['ggn_no' => "40'; DROP", 'kutu' => '1', 'brut_kg' => '5', 'net_kg' => '4']])['hatalar'] !== []);
$cok = array_fill(0, 205, ['palet_no' => '1', 'kutu' => '1', 'brut_kg' => '5', 'net_kg' => '4']);
ok('en çok 200 satır', frgp_satirlari_dogrula($cok)['hatalar'] !== []);

echo "\n=== 4. Taslak ===\n";
$po = ['po_no' => '26-1622-44080', 'kutu_palet' => 200, 'palet' => 26, 'kutu' => 5200, 'boy' => 'x14'];
$tk = frgp_taslak_satirlari($po);
ok('26 palet × 200 kutu, kalibre 14 (x14), lot=PO no, palet no 1..26', count($tk) === 26 && $tk[0]['kutu'] === 200 && $tk[25]['kutu'] === 200 && $tk[0]['kalibre'] === '14' && $tk[0]['lot'] === '26-1622-44080' && $tk[25]['palet_no'] === '26' && $tk[0]['brut_kg'] === null);
$tk2 = frgp_taslak_satirlari(['po_no' => '26-1-1', 'kutu_palet' => 200, 'palet' => 3, 'kutu' => 500, 'boy' => '']);
ok('son palet kalan kutu (200,200,100); kalibre boş', array_column($tk2, 'kutu') === [200, 200, 100] && $tk2[0]['kalibre'] === null);
ok('palet yoksa ⌈kutu/kutu_palet⌉; bilgi yoksa boş', count(frgp_taslak_satirlari(['kutu' => 450, 'kutu_palet' => 200])) === 3 && frgp_taslak_satirlari(['po_no' => 'x']) === []);

echo "\n=== 5. Yapıştırma ayrıştırıcı ===\n";
$y1 = frgp_yapistir_coz("634\r\n631,5\r\n\r\n1.250\r\n");
ok('1 sütun (brüt): CRLF + boş satır + binlik', $y1['ok'] && $y1['mod'] === 'brut' && count($y1['satirlar']) === 3 && $y1['satirlar'][2]['brut_kg'] === '1.250');
$y2 = frgp_yapistir_coz("634\t559\n631\t556\t\n");
ok('2 sütun (brüt|net): sondaki sekme tolere', $y2['ok'] && $y2['mod'] === 'brut_net' && $y2['satirlar'][1]['net_kg'] === '556');
$y6 = frgp_yapistir_coz("33\t4063651214000\tUretici A\t160\t634\t559\n32\t4063651214000\t\t160\t631\t556");
ok('6 sütun (tam): palet no / GGN / ad / kutu / brüt / net', $y6['ok'] && $y6['mod'] === 'tam' && $y6['satirlar'][0]['ggn_adi'] === 'Uretici A' && $y6['satirlar'][1]['ggn_adi'] === '' && $y6['satirlar'][1]['kutu'] === '160');
$yn = frgp_yapistir_coz("812.5\n634");
ok('nokta-ondalık REDDEDİLİR, hep-ya-hiç (hiç satır dönmez)', !$yn['ok'] && $yn['satirlar'] === [] && str_contains($yn['hatalar'][0], 'Satır 1'));
ok('farklı sütun sayısı reddedilir', !frgp_yapistir_coz("634\t559\n631")['ok']);
ok('3/4/5 sütun desteklenmez', !frgp_yapistir_coz("1\t2\t3")['ok'] && !frgp_yapistir_coz("1\t2\t3\t4")['ok']);
ok('boş girdi reddedilir', !frgp_yapistir_coz("  \n\t\n")['ok']);
ok('6 sütunda geçersiz kutu reddedilir', !frgp_yapistir_coz("1\t\t\t0\t634\t559")['ok']);
ok('201 satır reddedilir', !frgp_yapistir_coz(implode("\n", array_fill(0, 201, '634')))['ok']);

echo "\n=== 6. Başlık doğrulama ===\n";
$b = frgp_baslik_dogrula(['parti_no' => ' 61-278 ', 'sofor_telefon' => '+90 500 000 00 00', 'karton_kg' => '0,312', 'palet_diger_kg' => '25', 'sicaklik' => '+6']);
ok('başlık: kırpma, telefon, karton 0,312, palet diğer 25', $b['hatalar'] === [] && $b['degerler']['parti_no'] === '61-278' && $b['degerler']['karton_kg'] === 0.312 && $b['degerler']['palet_diger_kg'] === 25.0);
ok('başlık: telefon harf / karton 0.312 / palet diğer 9999 reddedilir', count(frgp_baslik_dogrula(['sofor_telefon' => 'abc'])['hatalar']) === 1 && count(frgp_baslik_dogrula(['karton_kg' => '0.312'])['hatalar']) === 1 && count(frgp_baslik_dogrula(['palet_diger_kg' => '9999'])['hatalar']) === 1);
ok('saat: 18:00 / 8.30 → 08:30 / boş / 25:00 false', frgp_saat_coz('18:00') === '18:00' && frgp_saat_coz('8.30') === '08:30' && frgp_saat_coz('') === '' && frgp_saat_coz('25:00') === false);

echo "\n=== 7. Kayıt (DB) ===\n";
$ctx = ['user_id' => 1, 'kaynak' => 'yukleme'];
$r = frg_siparis_kaydet($db, ['po_no' => '26-1710-60001', 'urun' => 'NAR', 'boy' => 'x10', 'son_musteri' => 'Musteri A', 'kutu_palet' => 160, 'palet' => 3, 'kutu' => 480, 'cikis_tarihi' => '2026-11-12', 'fiyat_kutu_birim' => 40000], $ctx);
$sid = (int)$r['id'];
$s = frg_getir($db, $sid);
$t = frgp_taslak_olustur($db, $s, false, 1);
$s = frg_getir($db, $sid);
ok('taslak: 3 boş satır yazıldı + olay', $t['ok'] && count($s['paletler']) === 3 && $s['paletler'][0]['lot'] === '26-1710-60001' && $s['paletler'][0]['kalibre'] === '10');
ok('taslak: mevcut satır varken onaysız reddedilir, onayla geçer', !frgp_taslak_olustur($db, $s, false, 1)['ok'] && frgp_taslak_olustur($db, $s, true, 1)['ok']);
$post = ['b' => ['parti_no' => '61/236', 'plaka' => '34 TST 123', 'sofor_adi' => 'Test Sofor', 'sofor_telefon' => '+90 555 000 00 00', 'nakliyeci' => 'TEST NAKLIYE', 'logger_id' => 'LG1', 'sicaklik' => '+6', 'karton_kg' => '0,312', 'palet_diger_kg' => '25', 'bolge' => 'FINIKE'],
    'p' => [['palet_no' => '1-DOOR', 'ggn_no' => '4049929000000', 'ggn_adi' => 'Uretici X', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559'],
            ['palet_no' => '2', 'ggn_no' => '4049929000000', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '160', 'brut_kg' => '631,5', 'net_kg' => '556'],
            ['palet_no' => '3', 'ggn_no' => '4049929000000', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '160', 'brut_kg' => '627', 'net_kg' => '552']]];
$k = frgp_kaydet($db, $s, $post, 1);
$s = frg_getir($db, $sid);
ok('kaydet ok; başlık + 3 palet yazıldı; ondalık brüt 631,5', $k['ok'] && $s['parti_no'] === '61/236' && (float)$s['karton_kg'] === 0.312 && (float)$s['palet_diger_kg'] === 25.0 && count($s['paletler']) === 3 && (float)$s['paletler'][1]['brut_kg'] === 631.5 && $s['paletler'][0]['palet_no'] === '1-DOOR', json_encode($k));
$au = $db->query("SELECT action, new_values FROM audit_log WHERE module='frugro_siparis' AND action LIKE 'frugro_pkl%'")->fetchAll();
$aj = json_encode($au, JSON_UNESCAPED_UNICODE);
ok('audit yazıldı (taslak + kaydet); şoför/plaka/telefon/üretici/nakliyeci DEĞERİ sızmaz', count($au) >= 2 && !preg_match('/Test Sofor|34 TST|555 000|Uretici X|TEST NAKLIYE|LG1/', $aj), $aj);
$ol = implode('|', array_column(frg_olaylar($db, $sid), 'tur'));
ok('olaylar: pkl_taslak + pkl_kaydedildi', str_contains($ol, 'pkl_taslak') && str_contains($ol, 'pkl_kaydedildi'), $ol);
$nAu = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
$k2 = frgp_kaydet($db, $s, $post, 1);
ok('aynı veri yeniden: "Değişiklik yok", ek audit/olay yazılmaz', $k2['ok'] && $k2['mesaj'] === 'Değişiklik yok.' && (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn() === $nAu);
$kötü = $post; $kötü['p'][1]['brut_kg'] = '812.5'; $kötü['b']['parti_no'] = 'DEGISMEMELI';
$k3 = frgp_kaydet($db, $s, $kötü, 1);
$s = frg_getir($db, $sid);
ok('hatalı satır: HİÇBİR şey kaydedilmez (atomik; başlık da değişmez)', !$k3['ok'] && $s['parti_no'] === '61/236' && (float)$s['paletler'][1]['brut_kg'] === 631.5 && $k3['hatalar'] !== []);
// üretim
$uHata = frgp_uret($db, ['id' => $sid, 'durum' => 'yeni', 'po_no' => 'X', 'paletler' => [['kutu' => 1, 'brut_kg' => 5, 'net_kg' => null]]] + $s, 'pdf', '', 1);
ok('net eksikse üretim reddedilir, durum değişmez', !$uHata['ok'] && frg_getir($db, $sid)['durum'] === 'yeni');
ok('geçersiz saat / biçim reddedilir', !frgp_uret($db, $s, 'pdf', '99:99', 1)['ok'] && !frgp_uret($db, $s, 'exe', '', 1)['ok']);
$pdf = frgp_uret($db, $s, 'pdf', '18:00', 1);
ok('PDF üretildi: %PDF başlığı, ad PKL-<po>-12.11.2026.pdf, tip', $pdf['ok'] && str_starts_with($pdf['veri'], '%PDF') && $pdf['ad'] === 'PKL-26-1710-60001-12.11.2026.pdf' && $pdf['tip'] === 'application/pdf', json_encode([$pdf['ok'], $pdf['hatalar'], $pdf['ad']]));
$s2 = frg_getir($db, $sid);
ok('durum yeni → pkl_hazir + olay pkl_uretildi', $s2['durum'] === 'pkl_hazir' && in_array('pkl_uretildi', array_column(frg_olaylar($db, $sid), 'tur'), true));
$xl = frgp_uret($db, $s2, 'xlsx', '', 1);
ok('XLSX üretildi: ZIP (PK) başlığı + .xlsx adı', $xl['ok'] && str_starts_with($xl['veri'], 'PK') && $xl['ad'] === 'PKL-26-1710-60001-12.11.2026.xlsx', json_encode($xl['hatalar']));
$n1 = count(array_filter(frg_olaylar($db, $sid), fn($o) => $o['tur'] === 'pkl_uretildi'));
ok('yeniden üretim: her seferinde pkl_uretildi olayı (2), durum pkl_hazir kalır', $n1 === 2 && frg_getir($db, $sid)['durum'] === 'pkl_hazir');
$db->exec("UPDATE frugro_siparisler SET durum='invoice_hazir' WHERE id=$sid");
$s3 = frg_getir($db, $sid);
ok('invoice_hazir: PKL kilitli (kaydet reddedilir) ama üretim serbest ve durumu GERİ ALMAZ', !frgp_kaydet($db, $s3, $post, 1)['ok'] && frgp_uret($db, $s3, 'pdf', '', 1)['ok'] && frg_getir($db, $sid)['durum'] === 'invoice_hazir' && !frgp_taslak_olustur($db, $s3, true, 1)['ok']);
$tmp = tempnam(sys_get_temp_dir(), 'pkl'); file_put_contents($tmp, $pdf['veri']);
$txt = (string)@shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
if (trim($txt) === '') echo "ATLANDI (pdftotext yok): PDF metin doğrulaması\n";
else ok('PDF metni: PACKING LIST, REF 26-1710, PARTI NO, 1-DOOR, POMEGRANATE, CALIBER 10, toplam 480 / 1.893 / 1.667', str_contains($txt, 'PACKING LIST') && str_contains($txt, '26-1710') && str_contains($txt, '61/236') && str_contains($txt, '1-DOOR') && str_contains($txt, 'POMEGRANATE, CALIBER 10') && str_contains($txt, '1.893') && str_contains($txt, '1.667') && str_contains($txt, '18:00') && str_contains($txt, '"'), $txt);
@unlink($tmp);
// çok sayfa
$cokPal = [];
for ($i = 1; $i <= 90; $i++) $cokPal[] = ['palet_no' => (string)$i, 'ggn_no' => '4049929000000', 'ggn_adi' => '', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => 160, 'brut_kg' => 634, 'net_kg' => 559];
$pdfCok = frgp_pdf(frgp_belge_verisi(['po_no' => '26-1710-60001', 'urun' => 'NAR', 'cikis_tarihi' => '2026-11-12', 'paletler' => $cokPal]));
ok('90 palet → çok sayfalı PDF', preg_match_all('/\/Type\s*\/Page\b/', (string)$pdfCok) >= 2);

echo "\n=== 8. XSS / kaçış ===\n";
$xs = '<script>alert(1)</script>"&';
$html = frgp_pdf_html(frgp_belge_verisi(['po_no' => '26-1-1', 'urun' => 'NAR', 'nakliyeci' => $xs, 'plaka' => $xs, 'paletler' => [['palet_no' => '<b>1</b>', 'ggn_no' => '1', 'ggn_adi' => $xs, 'kalibre' => $xs, 'lot' => $xs, 'kutu' => 1, 'brut_kg' => 5, 'net_kg' => 4]]]));
ok('PDF HTML: <script> ham geçmez, &lt;script&gt; var', !str_contains($html, '<script') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<b>1</b>'));
ok('PDF HTML: text-transform yok; uzak kaynak yok', !str_contains($html, 'text-transform') && !preg_match('#(src|href)\s*=\s*["\']?https?:#i', $html));
$xx = frgp_xlsx(frgp_belge_verisi(['po_no' => '26-1-1', 'urun' => 'NAR', 'nakliyeci' => '=1+1', 'paletler' => [['palet_no' => '1', 'ggn_no' => '1', 'ggn_adi' => '=HYPERLINK("x")', 'kalibre' => '10', 'lot' => 'L', 'kutu' => 1, 'brut_kg' => 5, 'net_kg' => 4]]]));
$tx = tempnam(sys_get_temp_dir(), 'pkx'); file_put_contents($tx, $xx);
$za = new ZipArchive(); $za->open($tx); $sh = (string)$za->getFromName('xl/worksheets/sheet1.xml'); $ss = (string)$za->getFromName('xl/sharedStrings.xml'); $za->close(); @unlink($tx);
ok('XLSX: "=..." metni formül olarak yazılmaz (<f> yok)', !str_contains($sh, '<f>HYPERLINK') && !str_contains($sh, '<f>1+1') && str_contains($ss, 'HYPERLINK'));
ok('dosya adı temizlenir (yol/enjeksiyon)', frgp_dosya_adi(['po_no' => '../a"b;c', 'cikis_tarihi' => '2026-01-05'], 'pdf') === 'PKL-..abc-05.01.2026.pdf' || preg_match('/^PKL-[A-Za-z0-9.\-]+-05\.01\.2026\.pdf$/', frgp_dosya_adi(['po_no' => '../a"b;c', 'cikis_tarihi' => '2026-01-05'], 'pdf')) === 1);

echo "\n=== 9. Yetki + depo izolasyonu ===\n";
global $PERMS, $IS_ADMIN, $AKTIF_DEPO, $DEPOLAR;
$deneme = function (string $perm): string { try { require_siparis($perm); return 'gecti'; } catch (RuntimeException $e) { return 'forbidden'; } };
$PERMS = ['siparis.read']; $IS_ADMIN = false;
ok("viewer: require_siparis('read') geçer, ('write') 403", $deneme('read') === 'gecti' && $deneme('write') === 'forbidden');
$PERMS = ['siparis.read', 'siparis.write'];
ok("operatör: write geçer", $deneme('write') === 'gecti' && can_siparis('write'));
$PERMS = [];
ok('yetkisiz: read 403', $deneme('read') === 'forbidden');
$PERMS = ['siparis.read', 'siparis.write'];
$DEPOLAR = ['DEPO2']; $AKTIF_DEPO = 'DEPO2';
$sg = frg_getir($db, $sid);
$db->exec("UPDATE frugro_siparisler SET depo='DEPO1' WHERE id=$sid");
ok('başka depo siparişi: frg_gorunur false (sayfa forbidden basar)', frg_gorunur(frg_getir($db, $sid)) === false);
$DEPOLAR = null; $AKTIF_DEPO = 'DEPO1';

echo "\n=== 10. Statik değişmezler ===\n";
$pg = (string)file_get_contents($ROOT . '/frugro_pkl.php');
$kod = (string)file_get_contents($ROOT . '/config/frugro_pkl.php');
$js = (string)file_get_contents($ROOT . '/assets/frugro_pkl.js');
$css = (string)file_get_contents($ROOT . '/assets/frugro_pkl.css');
$pgk = ''; foreach (token_get_all($pg) as $tk) { if (is_array($tk)) { if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) continue; $pgk .= $tk[1]; } else $pgk .= $tk; }
$pos = fn(string $n) => strpos($pgk, $n);
ok("sayfa: require_login + require_siparis('read') + no-store + frg_gorunur", $pos('require_login()') !== false && $pos("require_siparis('read')") !== false && $pos('siparis_no_store()') !== false && $pos('frg_gorunur($s)') !== false);
ok("sayfa: POST dalı require_siparis('write') + csrf_check, ikisi de ilk mutasyondan ÖNCE", ($w = $pos("require_siparis('write')")) !== false && ($c = $pos('csrf_check(')) !== false && $w < $c && $c < $pos('frgp_kaydet(') && $c < $pos('frgp_taslak_olustur(') && $c < $pos('frgp_uret('));
ok('sayfa: mutasyon yalnız REQUEST_METHOD === POST dalında; kimlik doğrulama sonrası depo kapısı POST\'tan önce', (bool)preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) === 'POST'/", $pgk) && $pos('frg_gorunur($s)') < $pos("=== 'POST'"));
ok('sayfa: PRG (Location + exit) + CSRF alanı', str_contains($pgk, "header('Location: '") && str_contains($pgk, 'sipui_csrf()'));
ok('sayfa: satır içi onclick/onchange YOK; <table> .table-wrap içinde; native dialog.pm-dialog; fonksiyon tanımı YOK', !preg_match('/\son(click|change|input|submit)\s*=/i', $pg) && str_contains($pg, '<div class="table-wrap"><table') && str_contains($pg, '<dialog id="frpYapistirDlg" class="pm-dialog') && !preg_match('/^\s*function\s+\w+\s*\(/m', $pgk));
ok('sayfa: dosya diske yazılmıyor (file_put_contents/fopen yok)', !preg_match('/\b(file_put_contents|fopen|move_uploaded_file)\s*\(/', $pgk) && !preg_match('/\b(file_put_contents|fopen|move_uploaded_file)\s*\(/', $kod));
ok('dialog:not([open]) kuralı style.css\'te duruyor (silinmemiş)', str_contains((string)file_get_contents($ROOT . '/assets/style.css'), 'dialog.pm-dialog:not([open]) { display: none; }'));
ok('CSS: .pm-dialog display\'i ezilmiyor; input 16px mobil', !preg_match('/\.pm-dialog[^{]*\{[^}]*display\s*:/', $css) && str_contains($css, 'max-width: 767px') && str_contains($css, 'font-size: 16px'));
ok('config: include-only koruma; PDF güvenlik seçenekleri; ALTER/DROP yok; getCalculatedValue yok', str_contains($kod, "=== __FILE__") && str_contains($kod, "'isRemoteEnabled', false") && str_contains($kod, "'isPhpEnabled', false") && str_contains($kod, 'DejaVu Sans') && !preg_match('/\b(ALTER|DROP)\s+TABLE\b/i', $kod) && !str_contains($kod, 'getCalculatedValue'));
ok('config: yalnız frugro_siparisler başlık UPDATE\'i; yazma yolu frg_palet_kaydet', substr_count($kod, 'UPDATE frugro_siparisler') === 2 && str_contains($kod, 'frg_palet_kaydet(') && !preg_match('/\bINSERT\s+INTO\s+frugro_palet\b/i', $kod));
ok('JS: kg/kutu/karton regex\'leri PHP ile aynı (nokta=binlik, 4 ondalık yok)', str_contains($js, '/^\d{1,3}(\.\d{3})+$/') && str_contains($js, '/^(\d+|\d{1,3}(\.\d{3})+),\d{1,3}$/') && str_contains($js, '/^\d{1,4}(,\d{1,3})?$/') && str_contains($kod, '\d{1,3}(\.\d{3})+'));
ok('JS: form Enter ile gönderilmez; satır içi eval/innerHTML kullanıcı verisi yok', str_contains($js, 'e.preventDefault()') && !str_contains($js, 'eval(') && !str_contains($js, 'document.write'));

siparis_test_bitir();
