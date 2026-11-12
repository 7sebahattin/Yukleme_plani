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
$AYAR = ['Uretici A' => '4063651214000', 'Uretici B' => '4069453419551'];
$v = frgp_satirlari_dogrula([
    ['palet_no' => '1-DOOR', 'ggn_no' => '9999', 'ggn_adi' => 'Uretici A', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559'],
    ['palet_no' => '', 'ggn_no' => '', 'kutu' => '', 'brut_kg' => '', 'net_kg' => ''],
    ['palet_no' => '77', 'kutu' => '160', 'brut_kg' => '631,5', 'net_kg' => ''],
], ['ggn' => $AYAR]);
ok('geçerli: 2 satır (boş atlandı), hata yok, net boş null', count($v['satirlar']) === 2 && $v['hatalar'] === [] && $v['satirlar'][1]['net_kg'] === null && $v['satirlar'][1]['brut_kg'] === 631.5, json_encode($v));
ok('palet no POST\'tan ALINMAZ: sunucu 1..N yazar ("1-DOOR" ve "77" yok sayılır)', $v['satirlar'][0]['palet_no'] === '1' && $v['satirlar'][1]['palet_no'] === '2');
ok('GGN no ayardan gelir (POST\'taki 9999 yok sayılır)', $v['satirlar'][0]['ggn_no'] === '4063651214000' && $v['satirlar'][0]['ggn_adi'] === 'Uretici A');
$h = frgp_satirlari_dogrula([['kutu' => '0', 'brut_kg' => '600', 'net_kg' => '500'], ['kutu' => '160', 'brut_kg' => '812.5', 'net_kg' => '500'], ['kutu' => '160', 'brut_kg' => '500', 'net_kg' => '600'], ['kutu' => '', 'brut_kg' => '500', 'net_kg' => '400']]);
$hm = implode(' | ', $h['hatalar']);
ok('hata: kutu 0, nokta-ondalık brüt, brüt<net, kutu boş', str_contains($hm, 'Satır 1: kutu') && str_contains($hm, 'Satır 2: brüt kg geçersiz') && str_contains($hm, 'Satır 3: brüt kg') && str_contains($hm, 'Satır 4: kutu adedi boş'), $hm);
ok('"palet no" hatası/uyarısı artık YOK (sunucu atar)', !str_contains($hm, 'palet no'));
$w = frgp_satirlari_dogrula([['kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559'], ['kutu' => '1', 'brut_kg' => '634', 'net_kg' => '559']]);
ok('uyarı (engellemez): kutu başına brüt anormal; tekrar uyarısı yok', $w['hatalar'] === [] && count($w['uyarilar']) === 1 && !str_contains(implode('', $w['uyarilar']), 'tekrar'), json_encode($w['uyarilar']));
$wp = frgp_satirlari_dogrula([['kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['palet' => 3]);
ok('uyarı: satır sayısı ≠ siparişteki palet sayısı (engellemez)', $wp['hatalar'] === [] && str_contains(implode('', $wp['uyarilar']), 'palet sayısından'));
ok('brüt=net serbest', frgp_satirlari_dogrula([['kutu' => '10', 'brut_kg' => '50', 'net_kg' => '50']])['hatalar'] === []);
ok('GGN no kontrol: harf/rakam/tire dışı reddedilir', frgp_satirlari_dogrula([['ggn_no' => "40'; DROP", 'kutu' => '1', 'brut_kg' => '5', 'net_kg' => '4']])['hatalar'] !== []);
$cok = array_fill(0, 205, ['kutu' => '1', 'brut_kg' => '5', 'net_kg' => '4']);
ok('en çok 200 satır', frgp_satirlari_dogrula($cok)['hatalar'] !== []);
$kz = frgp_satirlari_dogrula([['kutu' => '5', 'brut_kg' => '3000', 'net_kg' => '2800'], ['kutu' => '', 'brut_kg' => '3000', 'net_kg' => '2800']], ['kutu_palet' => 200]);
ok('kutu siparişten: kutu_palet>0 ise POST yok sayılır, her satır 200 (boş kutu da hata değil)', $kz['hatalar'] === [] && $kz['satirlar'][0]['kutu'] === 200 && $kz['satirlar'][1]['kutu'] === 200, json_encode($kz['hatalar']));
$ky = frgp_satirlari_dogrula([['kutu' => '5', 'brut_kg' => '3000', 'net_kg' => '2800']]);
ok('kutu_palet yoksa kutu elle girilen değer', $ky['satirlar'][0]['kutu'] === 5);
// GGN kuralları
$g1 = frgp_satirlari_dogrula([['ggn_adi' => 'Uretici B', 'ggn_no' => '', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR]);
ok('GGN: ayarda olan ad → no ayardan (4069453419551)', $g1['hatalar'] === [] && $g1['satirlar'][0]['ggn_no'] === '4069453419551');
$g2 = frgp_satirlari_dogrula([['ggn_adi' => 'Yeni Uretici', 'ggn_no' => '1234', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR]);
ok('GGN: ayarda olmayan YENİ ad → hata', $g2['hatalar'] !== [] && str_contains($g2['hatalar'][0], 'ayarlarda yok'), json_encode($g2['hatalar']));
$g3 = frgp_satirlari_dogrula([['ggn_adi' => 'Eski Uretici', 'ggn_no' => '777', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR, 'eski' => ["Eski Uretici\x1f777" => true]]);
ok('GGN: ayarda olmayan ama ESKİ kayıtlı çift korunur', $g3['hatalar'] === [] && $g3['satirlar'][0]['ggn_adi'] === 'Eski Uretici' && $g3['satirlar'][0]['ggn_no'] === '777');
$g4 = frgp_satirlari_dogrula([['ggn_adi' => 'Uretici A', 'ggn_no' => '555', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR, 'eski' => ["Uretici A\x1f555" => true]]);
ok('GGN: ayar değişmiş, satır eski no ile kayıtlı → eski no korunur + uyarı', $g4['hatalar'] === [] && $g4['satirlar'][0]['ggn_no'] === '555' && str_contains(implode('', $g4['uyarilar']), 'ayardaki GGN farklı'));
$g5 = frgp_satirlari_dogrula([['ggn_adi' => '', 'ggn_no' => '321', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR, 'eski' => ["\x1f321" => true]]);
$g6 = frgp_satirlari_dogrula([['ggn_adi' => '', 'ggn_no' => '321', 'kutu' => '160', 'brut_kg' => '634', 'net_kg' => '559']], ['ggn' => $AYAR]);
ok('GGN: boş ad + eski no korunur; boş ad + yeni no hata', $g5['hatalar'] === [] && $g5['satirlar'][0]['ggn_no'] === '321' && $g6['hatalar'] !== []);

echo "\n=== 4. Taslak ===\n";
$po = ['po_no' => '26-1622-44080', 'kutu_palet' => 200, 'palet' => 26, 'kutu' => 5200, 'boy' => 'x14'];
$tk = frgp_taslak_satirlari($po);
ok('26 palet × 200 kutu, kalibre 14 (x14), lot=PO no, palet no 1..26', count($tk) === 26 && $tk[0]['kutu'] === 200 && $tk[25]['kutu'] === 200 && $tk[0]['kalibre'] === '14' && $tk[0]['lot'] === '26-1622-44080' && $tk[25]['palet_no'] === '26' && $tk[0]['brut_kg'] === null);
$tk2 = frgp_taslak_satirlari(['po_no' => '26-1-1', 'kutu_palet' => 200, 'palet' => 3, 'kutu' => 500, 'boy' => '']);
ok('kutu siparişten: 3 palet × 200 kutu (toplam 500 olsa da SON satır da 200); kalibre boş', array_column($tk2, 'kutu') === [200, 200, 200] && $tk2[0]['kalibre'] === null);
ok('palet yoksa ⌈kutu/kutu_palet⌉; bilgi yoksa boş', count(frgp_taslak_satirlari(['kutu' => 450, 'kutu_palet' => 200])) === 3 && frgp_taslak_satirlari(['po_no' => 'x']) === []);

echo "\n=== 5. Yapıştırma ayrıştırıcı (yalnız Brüt / Net) ===\n";
$y1 = frgp_yapistir_coz("634\r\n631,5\r\n\r\n1.250\r\n");
ok('1 sütun (brüt): CRLF + boş satır + binlik', $y1['ok'] && $y1['mod'] === 'tek' && count($y1['satirlar']) === 3 && $y1['satirlar'][2]['brut_kg'] === '1.250' && $y1['satirlar'][0]['net_kg'] === '');
$yn1 = frgp_yapistir_coz("559\n556", 'net_kg');
ok('hedef Net, 1 sütun → net_kg dolar, brüt boş', $yn1['ok'] && $yn1['satirlar'][0]['net_kg'] === '559' && $yn1['satirlar'][0]['brut_kg'] === '' && $yn1['satirlar'][1]['net_kg'] === '556');
ok('hedef Net, 2 sütun → REDDEDİLİR', !frgp_yapistir_coz("634\t559", 'net_kg')['ok']);
$y2 = frgp_yapistir_coz("634\t559\n631\t556\t\n", 'brut_kg');
ok('hedef Brüt, 2 sütun (brüt|net): sondaki sekme tolere', $y2['ok'] && $y2['mod'] === 'brut_net' && $y2['satirlar'][1]['net_kg'] === '556' && $y2['satirlar'][1]['brut_kg'] === '631');
ok('6 sütun modu KALKTI → reddedilir', !frgp_yapistir_coz("33\t4063651214000\tUretici A\t160\t634\t559")['ok']);
$yn = frgp_yapistir_coz("812.5\n634");
ok('nokta-ondalık REDDEDİLİR, hep-ya-hiç (hiç satır dönmez)', !$yn['ok'] && $yn['satirlar'] === [] && str_contains($yn['hatalar'][0], 'Satır 1'));
ok('farklı sütun sayısı reddedilir', !frgp_yapistir_coz("634\t559\n631")['ok']);
ok('3/4/5 sütun desteklenmez', !frgp_yapistir_coz("1\t2\t3")['ok'] && !frgp_yapistir_coz("1\t2\t3\t4")['ok']);
ok('boş girdi reddedilir', !frgp_yapistir_coz("  \n\t\n")['ok']);
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
$db->exec('CREATE TABLE IF NOT EXISTS hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
$gk = frgp_ggn_kaydet($db, [['ad' => 'Uretici X', 'no' => '4049929000000'], ['ad' => '', 'no' => ''], ['ad' => 'Uretici Y', 'no' => '4049929000999']], 1);
ok('GGN ayarı kaydedilir (boş satır atlanır), liste/harita okunur', $gk['ok'] && count(frgp_ggn_liste($db)) === 2 && frgp_ggn_harita(frgp_ggn_liste($db)) === ['Uretici X' => '4049929000000', 'Uretici Y' => '4049929000999'], json_encode($gk));
ok('GGN ayarı: aynı ad ikinci kez (harf duyarsız) → ret', !frgp_ggn_kaydet($db, [['ad' => 'A Uretici', 'no' => '1'], ['ad' => 'a uretici', 'no' => '2']], 1)['ok'] && count(frgp_ggn_liste($db)) === 2);
ok('GGN ayarı: geçersiz GGN no / adsız satır → ret', !frgp_ggn_kaydet($db, [['ad' => 'X', 'no' => "1'; DROP"]], 1)['ok'] && !frgp_ggn_kaydet($db, [['ad' => '', 'no' => '123']], 1)['ok']);
$auG = (string)$db->query("SELECT group_concat(new_values) FROM audit_log WHERE action='frugro_ggn_ayar'")->fetchColumn();
ok('GGN audit: yalnız sayı; ad ve GGN DEĞERİ yazılmaz', $auG !== '' && str_contains($auG, 'sayi') && !preg_match('/Uretici|4049929/', $auG), $auG);
$post = ['b' => ['parti_no' => '61/236', 'plaka' => '34 TST 123', 'sofor_adi' => 'Test Sofor', 'sofor_telefon' => '+90 555 000 00 00', 'nakliyeci' => 'TEST NAKLIYE', 'logger_id' => 'LG1', 'logger_palet' => '2', 'sicaklik' => '+6', 'karton_kg' => '0,312', 'palet_diger_kg' => '25', 'bolge' => 'FINIKE'],
    'p' => [['palet_no' => '1-DOOR', 'ggn_no' => 'x', 'ggn_adi' => 'Uretici X', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '1', 'brut_kg' => '634', 'net_kg' => '559'],
            ['palet_no' => '9', 'ggn_adi' => 'Uretici Y', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '1', 'brut_kg' => '631,5', 'net_kg' => '556'],
            ['ggn_adi' => 'Uretici X', 'kalibre' => '10', 'lot' => '26-1710-60001', 'kutu' => '1', 'brut_kg' => '627', 'net_kg' => '552']]];
$k = frgp_kaydet($db, $s, $post, 1);
$s = frg_getir($db, $sid);
ok('kaydet ok; başlık + 3 palet yazıldı; ondalık brüt 631,5', $k['ok'] && $s['parti_no'] === '61/236' && (float)$s['karton_kg'] === 0.312 && (float)$s['palet_diger_kg'] === 25.0 && count($s['paletler']) === 3 && (float)$s['paletler'][1]['brut_kg'] === 631.5 && $s['paletler'][0]['palet_no'] === '1' && $s['paletler'][1]['palet_no'] === '2' && $s['paletler'][2]['palet_no'] === '3', json_encode($k));
ok('kaydet: palet no 1..N sunucuda atandı (POST \'1-DOOR\'/\'9\' yok sayıldı); kutu siparişten (160); GGN no ayardan', $s['paletler'][0]['ggn_no'] === '4049929000000' && $s['paletler'][1]['ggn_no'] === '4049929000999' && (int)$s['paletler'][0]['kutu'] === 160 && (int)$s['paletler'][2]['kutu'] === 160);
ok('kaydet: logger palet eki yazıldı (2); antet varsayılan true', frgp_ek_oku($db, $sid) === ['logger_palet' => '2', 'antet' => true]);
$kLg = $post; $kLg['b']['logger_palet'] = '9';
ok('logger palet: satırlar arasında olmayan numara → ret; harf → ret', !frgp_kaydet($db, $s, $kLg, 1)['ok'] && count(frgp_baslik_dogrula(['logger_palet' => 'x'])['hatalar']) === 1 && frgp_baslik_dogrula(['logger_palet' => ''])['hatalar'] === []);
ok('logger palet audit\'te yalnız bayrak (değer yok)', str_contains((string)$db->query("SELECT group_concat(new_values) FROM audit_log WHERE action='frugro_pkl_kaydet'")->fetchColumn(), 'logger_palet_degisti'));
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
ok('PDF üretildi: %PDF başlığı, ad PO <kısa>-PCK. LIST-ASYA FRESH-FRUGRO.pdf, tip', $pdf['ok'] && str_starts_with($pdf['veri'], '%PDF') && $pdf['ad'] === 'PO 26-1710-PCK. LIST-ASYA FRESH-FRUGRO.pdf' && $pdf['tip'] === 'application/pdf', json_encode([$pdf['ok'], $pdf['hatalar'], $pdf['ad']]));
$s2 = frg_getir($db, $sid);
ok('durum yeni → pkl_hazir + olay pkl_uretildi', $s2['durum'] === 'pkl_hazir' && in_array('pkl_uretildi', array_column(frg_olaylar($db, $sid), 'tur'), true));
$xl = frgp_uret($db, $s2, 'xlsx', '', 1);
ok('XLSX üretildi: ZIP (PK) başlığı + .xlsx adı', $xl['ok'] && str_starts_with($xl['veri'], 'PK') && $xl['ad'] === 'PO 26-1710-PCK. LIST-ASYA FRESH-FRUGRO.xlsx', json_encode($xl['hatalar']));
$n1 = count(array_filter(frg_olaylar($db, $sid), fn($o) => $o['tur'] === 'pkl_uretildi'));
ok('yeniden üretim: her seferinde pkl_uretildi olayı (2), durum pkl_hazir kalır', $n1 === 2 && frg_getir($db, $sid)['durum'] === 'pkl_hazir');
$db->exec("UPDATE frugro_siparisler SET durum='invoice_hazir' WHERE id=$sid");
$s3 = frg_getir($db, $sid);
ok('invoice_hazir: PKL kilitli (kaydet reddedilir) ama üretim serbest ve durumu GERİ ALMAZ', !frgp_kaydet($db, $s3, $post, 1)['ok'] && frgp_uret($db, $s3, 'pdf', '', 1)['ok'] && frg_getir($db, $sid)['durum'] === 'invoice_hazir' && !frgp_taslak_olustur($db, $s3, true, 1)['ok']);
$tmp = tempnam(sys_get_temp_dir(), 'pkl'); file_put_contents($tmp, $pdf['veri']);
$txt = (string)@shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
if (trim($txt) === '') echo "ATLANDI (pdftotext yok): PDF metin doğrulaması\n";
else ok('PDF metni: PACKING LIST başlığı YOK, (PALET YOK, DATE : sağ blokta, REF 26-1710, PARTI NO, 1-DOOR, POMEGRANATE, CALIBER 10, toplam 480 / 1.893 / 1.667', !str_contains($txt, 'PACKING LIST') && !str_contains($txt, '(PALET') && preg_match('/DATE\s+:\s*12\.11\.2026/', $txt) && preg_match('/REF\.\s+:\s*26-1710/', $txt) && str_contains($txt, '26-1710') && str_contains($txt, '61/236') && str_contains($txt, 'POMEGRANATE, CALIBER 10') && str_contains($txt, '1.893') && str_contains($txt, '1.667') && str_contains($txt, '18:00') && !str_contains($txt, '"') && substr_count($txt, '26-1710-60001') === 3 && str_contains($txt, 'Website: www.asyafresh.com') && str_contains($txt, 'Address:') && str_contains($txt, 'LG1'), $txt);
@unlink($tmp);
// antet + logger + ek
$ekV = frgp_ek_oku($db, 99999);
ok('ek: kayıt yoksa antet varsayılan TRUE, logger boş', $ekV === ['logger_palet' => '', 'antet' => true]);
$sL = frg_getir($db, $sid);
$hA = frgp_pdf_html(frgp_belge_verisi($sL, '', ['logger_palet' => '2', 'antet' => true]));
$hB = frgp_pdf_html(frgp_belge_verisi($sL, '', ['logger_palet' => '2', 'antet' => false]));
ok('HTML antetli: logo (data:image/png), adres/telefon/web, logger satırı class="lg" (yalnız 1), ID LOGGER sarı', str_contains($hA, 'data:image/png;base64') && str_contains($hA, 'Website: www.asyafresh.com') && str_contains($hA, 'E-mail: contact@asyafresh.com') && substr_count($hA, 'class="lg"') === 1 && str_contains($hA, 'background:#ffeb3b;padding:0 3pt') && str_contains($hA, '>LG1</span>') && !str_contains($hA, '(PALET') && !str_contains($hA, 'PACKING LIST') && !str_contains($hB, 'PACKING LIST') && !str_contains($hB, '(PALET') && str_contains($hA, '<td>: 12.11.2026</td>'));
ok('HTML antetsiz: logo/adres/web YOK; logger sarısı yine var', !str_contains($hB, 'data:image/png') && !str_contains($hB, 'Website:') && !str_contains($hB, 'Address:') && substr_count($hB, 'class="lg"') === 1);
ok('HTML: lot her satırda (tekrar işareti ditto YOK)', substr_count($hA, '<td class="orta">26-1710-60001</td>') === 3 && !str_contains($hA, '&quot;'));
ok('HTML: logger palet yoksa sarı satır yok', substr_count(frgp_pdf_html(frgp_belge_verisi($sL, '', ['logger_palet' => '', 'antet' => true])), 'class="lg"') === 0 && !str_contains(frgp_pdf_html(frgp_belge_verisi($sL)), 'class="lg"'));
ok('PDF HTML: uzak kaynak yok (logo base64)', !preg_match('#(src|href)\s*=\s*["\']?https?:#i', $hA));
$sZ = frg_getir($db, $sid);
$db->exec("UPDATE frugro_siparisler SET durum='invoice_hazir' WHERE id=$sid");
$sZ = frg_getir($db, $sid);
$u1 = frgp_uret($db, $sZ, 'pdf', '', 1, false);
ok('antet=false: KİLİTLİYKEN de kv\'ye yazılır (net/brüt değildir); sonraki GET/ZIP aynı değeri okur', $u1['ok'] && frgp_ek_oku($db, $sid)['antet'] === false && frgp_ek_oku($db, $sid)['logger_palet'] === '2');
$u2 = frgp_uret($db, $sZ, 'pdf', '', 1);
ok('antet verilmezse (null) kayıtlı değer korunur; true verilince geri açılır', $u2['ok'] && frgp_ek_oku($db, $sid)['antet'] === false && frgp_uret($db, $sZ, 'pdf', '', 1, true)['ok'] && frgp_ek_oku($db, $sid)['antet'] === true);
$db->exec("UPDATE frugro_siparisler SET durum='pkl_hazir' WHERE id=$sid");
$tmp2 = tempnam(sys_get_temp_dir(), 'pk2'); file_put_contents($tmp2, (string)frgp_pdf(frgp_belge_verisi(frg_getir($db, $sid), '', ['logger_palet' => '2', 'antet' => false])));
$txt2 = (string)@shell_exec('pdftotext -layout ' . escapeshellarg($tmp2) . ' - 2>/dev/null'); @unlink($tmp2);
if (trim($txt2) !== '') ok('PDF antetsiz: "Website:" yok, "ASYA FRESH · PKL" alt yazısı var', !str_contains($txt2, 'Website:') && str_contains($txt2, 'ASYA FRESH'));
$xz = frgp_xlsx(frgp_belge_verisi(frg_getir($db, $sid), '', ['logger_palet' => '2', 'antet' => true]));
$tx2 = tempnam(sys_get_temp_dir(), 'pk3'); file_put_contents($tx2, $xz);
$za2 = new ZipArchive(); $za2->open($tx2); $ss2 = (string)$za2->getFromName('xl/sharedStrings.xml'); $sh2 = (string)$za2->getFromName('xl/worksheets/sheet1.xml'); $za2->close(); @unlink($tx2);
ok('XLSX: ID LOGGER PALET satırı + lot her satırda (3 kez)', str_contains($ss2, 'ID LOGGER PALET') && substr_count($sh2, '<c ') > 0 && preg_match_all('/26-1710-60001/', $ss2) >= 1);
$st = $db->prepare('SELECT COUNT(*) FROM hks_kv WHERE anahtar = ?'); $st->execute(['frugro_pkl_ek_' . $sid]);
ok('ek kaydı hks_kv\'de, anahtar ≤ 60 karakter', (int)$st->fetchColumn() === 1 && strlen(FRGP_EK_KV_ON . '9999999') <= 60);
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
ok('dosya adı temizlenir (yol/enjeksiyon; yalnız [A-Za-z0-9 .-])', preg_match('/^PO [A-Za-z0-9.\-]+-PCK\. LIST-ASYA FRESH-FRUGRO\.pdf$/', frgp_dosya_adi(['po_no' => '../a"b;c', 'cikis_tarihi' => '2026-01-05'], 'pdf')) === 1 && frgp_dosya_adi(['po_no' => '25-2023-35529'], 'pdf') === 'PO 25-2023-PCK. LIST-ASYA FRESH-FRUGRO.pdf');

echo "\n=== 9. Yetki + depo izolasyonu ===\n";
global $PERMS, $IS_ADMIN, $AKTIF_DEPO, $DEPOLAR;
$deneme = function (string $perm): string { try { require_siparis($perm, 'frugro'); return 'gecti'; } catch (RuntimeException $e) { return 'forbidden'; } };
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
ok("sayfa: require_login + require_siparis('read') + no-store + frg_gorunur", $pos('require_login()') !== false && $pos("require_siparis('read', 'frugro')") !== false && $pos('siparis_no_store()') !== false && $pos('frg_gorunur($s)') !== false);
ok("sayfa: POST dalı require_siparis('write') + csrf_check, ikisi de ilk mutasyondan ÖNCE", ($w = $pos("require_siparis('write', 'frugro')")) !== false && ($c = $pos('csrf_check(')) !== false && $w < $c && $c < $pos('frgp_kaydet(') && $c < $pos('frgp_taslak_olustur(') && $c < $pos('frgp_uret('));
ok('sayfa: mutasyon yalnız REQUEST_METHOD === POST dalında; kimlik doğrulama sonrası depo kapısı POST\'tan önce', (bool)preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) === 'POST'/", $pgk) && $pos('frg_gorunur($s)') < $pos("=== 'POST'"));
ok('sayfa: PRG (Location + exit) + CSRF alanı', str_contains($pgk, "header('Location: '") && str_contains($pgk, 'sipui_csrf()'));
ok('sayfa: satır içi onclick/onchange YOK; <table> .table-wrap içinde; native dialog.pm-dialog; fonksiyon tanımı YOK', !preg_match('/\son(click|change|input|submit)\s*=/i', $pg) && str_contains($pg, '<div class="table-wrap"><table') && str_contains($pg, '<dialog id="frpYapistirDlg" class="pm-dialog') && !preg_match('/^\s*function\s+\w+\s*\(/m', $pgk));
ok('sayfa: dosya diske yazılmıyor (file_put_contents/fopen yok)', !preg_match('/\b(file_put_contents|fopen|move_uploaded_file)\s*\(/', $pgk) && !preg_match('/\b(file_put_contents|fopen|move_uploaded_file)\s*\(/', $kod));
ok('dialog:not([open]) kuralı style.css\'te duruyor (silinmemiş)', str_contains((string)file_get_contents($ROOT . '/assets/style.css'), 'dialog.pm-dialog:not([open]) { display: none; }'));
ok('CSS: .pm-dialog display\'i ezilmiyor; input 16px mobil', !preg_match('/\.pm-dialog[^{]*\{[^}]*display\s*:/', $css) && str_contains($css, 'max-width: 767px') && str_contains($css, 'font-size: 16px'));
ok('config: include-only koruma; PDF güvenlik seçenekleri; ALTER/DROP yok; getCalculatedValue yok', str_contains($kod, "=== __FILE__") && str_contains($kod, "'isRemoteEnabled', false") && str_contains($kod, "'isPhpEnabled', false") && str_contains($kod, 'DejaVu Sans') && !preg_match('/\b(ALTER|DROP)\s+TABLE\b/i', $kod) && !str_contains($kod, 'getCalculatedValue'));
ok('config: yalnız frugro_siparisler başlık UPDATE\'i; yazma yolu frg_palet_kaydet', substr_count($kod, 'UPDATE frugro_siparisler') === 1 && !preg_match('/UPDATE\s+frugro_siparisler\s+SET\s+durum/i', $kod) && str_contains($kod, 'frg_palet_kaydet(') && !preg_match('/\bINSERT\s+INTO\s+frugro_palet\b/i', $kod));
ok('JS: kg/kutu/karton regex\'leri PHP ile aynı (nokta=binlik, 4 ondalık yok)', str_contains($js, '/^\d{1,3}(\.\d{3})+$/') && str_contains($js, '/^(\d+|\d{1,3}(\.\d{3})+),\d{1,3}$/') && str_contains($js, '/^\d{1,4}(,\d{1,3})?$/') && str_contains($kod, '\d{1,3}(\.\d{3})+'));
ok('JS: form Enter ile gönderilmez; satır içi eval/innerHTML kullanıcı verisi yok', str_contains($js, 'e.preventDefault()') && !str_contains($js, 'eval(') && !str_contains($js, 'document.write'));

$ayar = (string)file_get_contents($ROOT . '/frugro_ayarlar.php');
$zip = (string)file_get_contents($ROOT . '/frugro_zip.php'); $eml = (string)file_get_contents($ROOT . '/frugro_eml.php');
ok('JS: 6 sütun modu ("tam") kalktı; ↓ alttakilere uygula + hücreye yapıştırma (paste) var', !str_contains($js, "'tam'") && str_contains($js, 'data-frp-asagi') && str_contains($js, "addEventListener('paste'") && str_contains($js, 'yapistirCoz(metin, hedef)'));
ok('sayfa: GGN adı <select>, GGN no readonly, palet no gizli+salt okunur, antet onay kutusu (checked varsayılan), logger palet alanı', str_contains($pg, '<select name="p[__I__][ggn_adi]"') && str_contains($pg, 'readonly tabindex="-1"') && str_contains($pg, 'name="antet" value="1"') && str_contains($pg, 'Üst başlık / alt bilgi (AsyaFresh logosu)') && str_contains($pg, "\$ek['antet'] ? ' checked'") && str_contains($pg, 'b[logger_palet]'));
ok('sayfa: antet POST\'ta yalnız "1" ise true; frgp_uret\'e geçer', str_contains($pg, "(\$_POST['antet'] ?? '') === '1'") && str_contains($pg, '$uid, $antet)'));
ok('ZIP / .eml / detay: kayıtlı antet için frgd_pkl_pdf($s, $pdo)', str_contains($zip, 'frgd_pkl_pdf($s, $pdo)') && str_contains($eml, 'frgd_pkl_pdf($s, $pdo)'));
ok('logo dosyası var, PNG', is_file($ROOT . '/' . FRGP_LOGO) && str_starts_with((string)file_get_contents($ROOT . '/' . FRGP_LOGO, false, null, 0, 8), "\x89PNG") && frgp_logo_uri() !== null);
$pk = ''; foreach (token_get_all($ayar) as $tk) { if (is_array($tk)) { if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) continue; $pk .= $tk[1]; } else $pk .= $tk; }
$pG = strpos($pk, "'islem'] ?? '') === 'ggn'"); $pGen = strpos($pk, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
ok('ayarlar: GGN POST dalı csrf_check + require_siparis(\'delete\') + frgp_ggn_kaydet, genel POST dalından ÖNCE; #ggn kartı $pklYk kapısında', $pG !== false && $pGen !== false && $pG < $pGen && preg_match("/'ggn'\) \{\s*csrf_check\([^;]+;\s*require_siparis\('delete', 'frugro'\);[^}]*frgp_ggn_kaydet\(/s", $pk) && str_contains($ayar, 'id="ggn"') && (bool)preg_match('/<\?php if \(\$pklYk\): \?>\s*<section class="card" id="ggn"/', $ayar));
ok('ayarlar: mevcut #fatura / #pkl-mail dalları duruyor', str_contains($pk, "'fatura_ayar'") && str_contains($pk, "'pkl_mail'") && str_contains($ayar, 'id="fatura"') && str_contains($ayar, 'id="pkl-mail"'));
ok('config: yeni kv anahtarları sabit; ayar DB şeması değişmedi (CREATE/ALTER yok)', str_contains($kod, "'frugro_ggn_uretici'") && str_contains($kod, "'frugro_pkl_ek_'") && !preg_match('/\b(CREATE|ALTER)\s+TABLE\b/i', $kod));

siparis_test_bitir();
