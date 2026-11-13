<?php
// =========================================================
// scripts/frugro_core_smoke.php — Frugro Siparişler çekirdeği: şema, Excel okuyucu, şablon satırı yok sayma, uyarılar,
// upsert / çift mail, depo izolasyonu, tutar hesabı, statik değişmezler.
//   php scripts/frugro_core_smoke.php
//   FRUGRO_ORNEK_DIR=<klasör> php scripts/frugro_core_smoke.php   (gerçek örnekler; repoda YOK; 1622/1623/1624 doğrulanır)
// Bellek içi SQLite + stub auth + SENTETİK fixture (scripts/fixtures/frugro/order_log_ornek.xlsx). Canlı DB'ye dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/frugro_core.php';
require_once $ROOT . '/vendor/autoload.php';

$db = db();
siparis_test_diger_tablolar($db);
$FIX = $ROOT . '/scripts/fixtures/frugro/order_log_ornek.xlsx';

echo "=== 1. Şema ===\n";
ok('frg_hazir tablo yokken false', frg_hazir($db) === false);
$r = frg_migrate($db);
ok('frg_migrate SQLite\'ta çalışmaz (MySQL DDL) ama 3 satır raporlar', count($r) === 3);
foreach (frg_tablolar() as $ddl) { [$t, $ix] = siparis_ddl_sqlite($ddl); $db->exec($t); foreach ($ix as $i) $db->exec($i); }
ok('frg_hazir tablolar varken true', frg_hazir($db) === true);
$mr = frg_migrate($db);
ok('frg_migrate ikinci kez: hepsi "var" (idempotent)', count($mr) === 3 && array_unique(array_column($mr, 'durum')) === ['var']);
$sutun = fn(string $t) => array_column($db->query("PRAGMA table_info($t)")->fetchAll(), 'name');
ok('frugro_palet: ggn_no/ggn_adi/palet_no/konum/kalibre/lot/kutu/brut_kg/net_kg', !array_diff(['palet_no', 'konum', 'kalibre', 'lot', 'ggn_no', 'ggn_adi', 'kutu', 'brut_kg', 'net_kg', 'siparis_id', 'sira'], $sutun('frugro_palet')));
ok('frugro_siparisler: PKL başlık alanları + para_birimi/durum/depo/kaynak/ham_json/dosya_sha', !array_diff(['parti_no', 'plaka', 'sofor_adi', 'sofor_telefon', 'nakliyeci', 'logger_id', 'sicaklik', 'karton_kg', 'palet_diger_kg', 'bolge', 'hasat_yili', 'fatura_no', 'para_birimi', 'durum', 'depo', 'kaynak', 'kaynak_mail_id', 'ham_json', 'dosya_sha', 'deleted_at'], $sutun('frugro_siparisler')));
ok('frugro_olaylar kolonları', !array_diff(['siparis_id', 'tur', 'metin', 'created_at', 'created_by'], $sutun('frugro_olaylar')));

echo "\n=== 2. Okuyucu (fixture) ===\n";
$o = frg_xlsx_oku($FIX);
ok('okundu, 1 PO (şablon satırları yok sayıldı)', $o['ok'] && count($o['siparisler']) === 1 && $o['atlanan'] === 2, json_encode([$o['ok'], count($o['siparisler']), $o['atlanan']]));
$s = $o['siparisler'][0] ?? [];
ok('PO / çıkış / varış (=B4+8 formülü)', ($s['po_no'] ?? '') === '26-1622-44080' && $s['cikis_tarihi'] === '2026-11-10' && $s['varis_tarihi'] === '2026-11-18', json_encode($s));
ok('liman / müşteri / ürün / çeşit / boy / incoterm', $s['giris_limani'] === 'FELIXSTOWE PORT' && $s['son_musteri'] === 'Aldi-UK' && $s['urun'] === 'NAR' && $s['cesit'] === 'HICAZ' && $s['boy'] === 'x14' && $s['incoterm'] === 'FCA');
ok('kutu 5200 = 200 × 26; fiyat 4,6 → 46000', $s['kutu'] === 5200 && $s['kutu_palet'] === 200 && $s['palet'] === 26 && $s['fiyat_kutu_birim'] === 46000);
ok('şablon örnek satırlarındaki alan sızmadı (EXPORTER NAME / Teyit Edilecek yok)', !str_contains(json_encode($o, JSON_UNESCAPED_UNICODE), 'EXPORTER NAME') && !str_contains(json_encode($s), 'Teyit'));
ok('uyarı yok (toplam satırı tutarlı)', $o['uyarilar'] === [], json_encode($o['uyarilar']));
ok('dosya sha256 = hash_file', $o['dosya_sha'] === hash_file('sha256', $FIX));
ok('notlar alındı', count($o['notlar']) === 2);
ok('olmayan dosya / xlsx olmayan → ok=false', frg_xlsx_oku('/yok/x.xlsx')['ok'] === false && (function () use ($ROOT) { $t = tempnam(sys_get_temp_dir(), 'frg'); file_put_contents($t, 'duz metin'); $r = frg_xlsx_oku($t); @unlink($t); return $r['ok'] === false; })());

echo "\n=== 3. Okuyucu: başlık adı eşleme, sütun sırası, uyarılar ===\n";
// Başlıkları sütun konumundan bağımsız (ters sırada) + hatalı veri ile sentetik xlsx üret
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
function frg_test_xlsx(array $basliklar, array $satirlar, string $sayfa = 'Order Log', ?array $toplam = null): string
{
    $x = new Spreadsheet(); $ws = $x->getActiveSheet(); $ws->setTitle($sayfa);
    $ws->setCellValue('A1', 'SENTETIK');
    foreach ($basliklar as $i => $b) { $ws->setCellValueExplicit([$i + 1, 2], $b, 's'); $ws->setCellValueExplicit([$i + 1, 3], 'tr' . $i, 's'); }
    $r = 4;
    foreach ($satirlar as $sat) { foreach (array_values($sat) as $i => $v) if ($v !== null) $ws->setCellValue([$i + 1, $r], $v); $r++; }
    if ($toplam) { $ws->setCellValue([1, $r], 'Total:'); foreach ($toplam as $c => $f) $ws->setCellValue($c . $r, $f); }
    $t = sys_get_temp_dir() . '/frg_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.xlsx';
    (new Xlsx($x))->save($t);
    return $t;
}
$bas = ['Invoice Price x Box', 'Pallets', 'Cases per Pallet', 'Volume', 'Incoterm (2020)', 'Customer', 'AWB & Entry Port', 'Arrival Date', 'Departure Date', 'Product', '(PO) Order Number'];
// A..K ; sıra: fiyat, palet, kutu/palet, kutu, incoterm, müşteri, liman, varış, çıkış, ürün, PO
$t1 = frg_test_xlsx($bas, [
    [3.5, 10, 100, 900, 'DAP', 'Musteri A', 'HARWICH', '2026-11-05', '2026-11-12', 'NAR', '26-1700-50001'],   // kutu≠palet×kpal, çıkış>varış
    [null, null, 100, null, null, null, null, null, null, 'NAR', '26-1701'],                                  // geçersiz PO biçimi → uyarı, alınmaz
], 'Order Log', ['A' => null]);
$o1 = frg_xlsx_oku($t1); @unlink($t1);
$s1 = $o1['siparisler'][0] ?? [];
ok('ters sıralı başlıklar ada göre eşlendi', $o1['ok'] && ($s1['po_no'] ?? '') === '26-1700-50001' && $s1['urun'] === 'NAR' && $s1['giris_limani'] === 'HARWICH' && $s1['fiyat_kutu_birim'] === 35000, json_encode($o1));
ok('tarihler metin (Y-m-d) olarak okunur', $s1['cikis_tarihi'] === '2026-11-12' && $s1['varis_tarihi'] === '2026-11-05');
$u = implode(' | ', $o1['uyarilar']);
ok('uyarı: kutu ≠ palet × kutu/palet', str_contains($u, 'kutu (900)') && str_contains($u, '1000'), $u);
ok('uyarı: çıkış > varış', str_contains($u, 'çıkış tarihi varış tarihinden sonra'), $u);
ok('uyarı: PO biçimi geçersiz satır yok sayıldı', str_contains($u, '26-1701') && count($o1['siparisler']) === 1, $u);
ok('uyarı: eksik zorunlu alan (müşteri/ürün dışı vs.) — çeşit zorunlu değil, giriş limanı var', !str_contains($u, 'zorunlu alan eksik (giriş limanı)'));
$bas2 = ['(PO) Order Number', 'Product', 'Cases per Pallet', 'Pallets', 'Volume', 'Customer', 'Departure Date', 'Arrival Date', 'AWB & Entry Port', 'Incoterm (2020)', 'Invoice Price x Box'];
$t2 = frg_test_xlsx($bas2, [['26-1702-50002', 'NAR', 100, 5, '=C4*D4', null, 46340, 46345, 'X PORT', 'FCA', 4.6]], 'Order Log', ['E' => '=SUM(E4:E4)', 'D' => 999]);
$o2 = frg_xlsx_oku($t2); @unlink($t2);
$u2 = implode(' | ', $o2['uyarilar']);
ok('uyarı: eksik zorunlu alan (müşteri)', str_contains($u2, 'zorunlu alan eksik (müşteri)'), $u2);
ok('uyarı: toplam satırı uyuşmazlığı (palet 999 ≠ 5)', str_contains($u2, 'Toplam satırı uyuşmuyor (palet)'), $u2);
ok('formül kutu (=C*D) çarpımdan 500', ($o2['siparisler'][0]['kutu'] ?? 0) === 500);
$t3 = frg_test_xlsx(['Foo', 'Bar'], [['a', 'b']]); $o3 = frg_xlsx_oku($t3); @unlink($t3);
ok('başlıksız dosya → ok=false + hata', $o3['ok'] === false && $o3['hata'] !== null);
$t4 = frg_test_xlsx($bas2, [['26-1703-50003', 'NAR', 10, 1, 10, 'M', 46340, 46345, 'P', 'FCA', '4,6']], 'Sheet1'); $o4 = frg_xlsx_oku($t4); @unlink($t4);
ok('sayfa adı "Order Log" değilse ilk sayfa + virgüllü metin fiyat 46000', ($o4['siparisler'][0]['fiyat_kutu_birim'] ?? 0) === 46000);
ok('frg_fiyat_birim yuvarlama: 4.6 → 46000, 4.5833 → 45833, "abc" → null', frg_fiyat_birim(4.6) === 46000 && frg_fiyat_birim(4.5833) === 45833 && frg_fiyat_birim('abc') === null);
ok('frg_fiyat_metin: 46000 → 4,60; 45833 → 4,5833', frg_fiyat_metin(46000) === '4,60' && frg_fiyat_metin(45833) === '4,5833');

echo "\n=== 4. Hesap ===\n";
$h = frg_hesap($s);
ok('5200 × 4,6 = 23.920,00 → 2392000 kuruş (TAM SAYI)', $h['tutar_kurus'] === 2392000 && is_int($h['tutar_kurus']) && $h['kutu'] === 5200 && $h['palet'] === 26);
ok('para birimi yok → GBP varsayılan, tutar hesaplanır', $h['para_birimi'] === 'GBP' && $h['fiyat_var']);
ok('kutu boşsa palet × kutu/palet', frg_hesap(['kutu_palet' => 200, 'palet' => 26, 'fiyat_kutu_birim' => 46000])['kutu'] === 5200);
ok('fiyat yoksa tutar 0, fiyat_var false', frg_hesap(['kutu' => 10])['tutar_kurus'] === 0 && frg_hesap(['kutu' => 10])['fiyat_var'] === false);
ok('kuruşa yarım yukarı: 3 kutu × 0,3333 = 0,9999 → 100 kuruş', frg_hesap(['kutu' => 3, 'fiyat_kutu_birim' => 3333])['tutar_kurus'] === 100);

echo "\n=== 5. Kaydet / upsert / çift mail ===\n";
$ctx = ['user_id' => 1, 'kaynak' => 'yukleme'];
$k1 = frg_toplu_kaydet($db, $o, $ctx);
ok('ilk kayıt: olustu', ($k1['sayac']['olustu'] ?? 0) === 1 && $k1['sonuclar'][0]['id'] > 0, json_encode($k1));
$id = (int)$k1['sonuclar'][0]['id'];
$row = frg_getir($db, $id);
ok('satır: durum yeni, depo = aktif depo, sha, ham_json, para_birimi GBP (varsayılan)', $row['durum'] === 'yeni' && $row['depo'] === 'DEPO1' && $row['dosya_sha'] === $o['dosya_sha'] && $row['para_birimi'] === 'GBP' && str_contains((string)$row['ham_json'], '26-1622-44080'));
ok('değerler: kutu 5200 / fiyat 46000 / tarih', (int)$row['kutu'] === 5200 && (int)$row['fiyat_kutu_birim'] === 46000 && $row['varis_tarihi'] === '2026-11-18');
$k2 = frg_toplu_kaydet($db, $o, $ctx);
ok('aynı dosya tekrar → zaten, satır açılmaz', ($k2['sayac']['zaten'] ?? 0) === 1 && (int)$db->query('SELECT COUNT(*) FROM frugro_siparisler')->fetchColumn() === 1);
// Geri gönderim çifti: farklı dosya (sha), aynı PO, bir alan farklı
$o5 = $o; $o5['dosya_sha'] = str_repeat('a', 64); $o5['siparisler'][0]['palet'] = 24; $o5['siparisler'][0]['kutu'] = 4800;
$k3 = frg_toplu_kaydet($db, $o5, ['kaynak' => 'mail', 'kaynak_mail_id' => 7, 'user_id' => 1]);
ok('farklı dosya, durum yeni: yeni satır AÇILMAZ, alanlar güncellenir (guncellendi) + olay', ($k3['sayac']['guncellendi'] ?? 0) === 1 && (int)$db->query('SELECT COUNT(*) FROM frugro_siparisler')->fetchColumn() === 1 && (int)frg_getir($db, $id)['palet'] === 24);
$ol = frg_olaylar($db, $id);
ok('olaylar: olustu + guncellendi (mailden, fark metni)', count($ol) === 2 && str_contains(implode('|', array_column($ol, 'metin')), 'palet: 26 → 24') && str_contains(implode('|', array_column($ol, 'metin')), '(mailden)'));
// kullanıcı durumu ilerletti + elle düzeltme → ezme
$db->exec("UPDATE frugro_siparisler SET durum='pkl_hazir', palet=30 WHERE id=$id");
$o6 = $o; $o6['dosya_sha'] = str_repeat('b', 64); $o6['siparisler'][0]['palet'] = 26; $o6['siparisler'][0]['urun'] = 'NAR2';
$k4 = frg_toplu_kaydet($db, $o6, ['kaynak' => 'mail', 'user_id' => 1]);
$rw = frg_getir($db, $id);
ok('durum yeni değil: HİÇBİR alan ezilmez (palet 30 / urun NAR korunur), yalnız olay (fark)', ($k4['sayac']['fark'] ?? 0) === 1 && (int)$rw['palet'] === 30 && $rw['urun'] === 'NAR' && count(frg_olaylar($db, $id)) === 3);
ok('fark listesi alan/eski/yeni taşır', in_array('urun', array_column($k4['sonuclar'][0]['farklar'], 'alan'), true));
$k5 = frg_toplu_kaydet($db, $o6, ['kaynak' => 'mail', 'user_id' => 1]);
ok('aynı fark yeniden gelince sha değişmediği için fark olayı bir kez daha yazılır, alan yine ezilmez', $k5['sonuclar'][0]['durum'] === 'fark' && (int)frg_getir($db, $id)['palet'] === 30);
ok('geçersiz PO → hata', frg_siparis_kaydet($db, ['po_no' => 'x'], $ctx)['durum'] === 'hata');
$a = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module='frugro_siparis'")->fetchColumn();
ok('audit_log_event yazıldı (create/update/frugro_fark)', $a >= 4, (string)$a);
ok('audit içinde hassas/ham veri yok (adres/ham_json sızmaz)', (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE module='frugro_siparis' AND (new_values LIKE '%Ornek Cad%' OR new_values LIKE '%ham_json%')")->fetchColumn() === 0);
// PKL başlığı alanları yeni kayıtta kabul edilir
$o7 = ['po_no' => '26-1710-60001', 'urun' => 'NAR', 'kutu_palet' => 100, 'palet' => 2, 'fiyat_kutu_birim' => 40000, 'parti_no' => '61/236', 'plaka' => '34 ABC 123', 'karton_kg' => 0.312, 'palet_diger_kg' => 25, 'bolge' => 'FINIKE - ANTALYA', 'hasat_yili' => '2026', 'logger_id' => 'L1', 'fatura_no' => 'F1'];
$r7 = frg_siparis_kaydet($db, $o7, $ctx);
$g7 = frg_getir($db, (int)$r7['id']);
ok('PKL başlık alanları (parti_no, plaka, karton_kg 0,312, palet_diger_kg 25, bolge, hasat_yili…) yazılır', $g7['parti_no'] === '61/236' && (float)$g7['karton_kg'] === 0.312 && (float)$g7['palet_diger_kg'] === 25.0 && $g7['bolge'] === 'FINIKE - ANTALYA' && $g7['fatura_no'] === 'F1');
$n = frg_palet_kaydet($db, (int)$r7['id'], [['palet_no' => '1-DOOR', 'konum' => '1-DOOR', 'kalibre' => '14', 'lot' => '26-1710-60001', 'ggn_no' => '4049929000000', 'ggn_adi' => 'Uretici X', 'kutu' => 100, 'brut_kg' => 412.5, 'net_kg' => 350.25], ['palet_no' => 2, 'konum' => 'SAĞ', 'kutu' => 100]]);
$g7 = frg_getir($db, (int)$r7['id']);
ok('palet satırları: palet_no metin ("1-DOOR"), ggn alanları, decimal', $n === 2 && $g7['paletler'][0]['palet_no'] === '1-DOOR' && $g7['paletler'][0]['ggn_no'] === '4049929000000' && (float)$g7['paletler'][0]['net_kg'] === 350.25 && $g7['paletler'][1]['palet_no'] === '2' && $g7['paletler'][1]['konum'] === 'SAĞ');
// soft silme: geri getirilmez, listede yok
$db->exec("UPDATE frugro_siparisler SET deleted_at='2026-11-10 10:00:00' WHERE id=" . (int)$r7['id']);
ok('soft silinmiş PO: yeniden gelirse geri GETİRİLMEZ (silinmis), frg_getir null', frg_siparis_kaydet($db, $o7, $ctx)['durum'] === 'silinmis' && frg_getir($db, (int)$r7['id']) === null);

echo "\n=== 6. Liste / arama / depo izolasyonu ===\n";
global $AKTIF_DEPO, $DEPOLAR;
$AKTIF_DEPO = 'DEPO2'; $DEPOLAR = ['DEPO2'];
$od = $o; $od['dosya_sha'] = str_repeat('c', 64); $od['siparisler'][0]['po_no'] = '26-1800-70001'; $od['siparisler'][0]['son_musteri'] = 'Lidl-UK';
frg_toplu_kaydet($db, $od, ['user_id' => 1]);
$AKTIF_DEPO = 'DEPO1'; $DEPOLAR = ['DEPO1'];
$l = frg_liste($db, []);
ok('DEPO1: yalnız kendi + silinmemiş kayıt (DEPO2 ve silinmiş görünmez)', count($l) === 1 && $l[0]['po_no'] === '26-1622-44080', json_encode(array_column($l, 'po_no')));
$DEPOLAR = ['DEPO2'];
ok('DEPO2: yalnız 26-1800-70001', array_column(frg_liste($db, []), 'po_no') === ['26-1800-70001']);
$db->exec("INSERT INTO frugro_siparisler (po_no, durum, depo, created_at) VALUES ('26-1900-80001','yeni','',datetime('now'))");
ok('Deposu BOŞ kayıt tüm depolarda görünür (atanmamış veri kuralı)', in_array('26-1900-80001', array_column(frg_liste($db, []), 'po_no'), true));
$DEPOLAR = null;
ok('kısıtsız kullanıcı hepsini görür (3 kayıt)', count(frg_liste($db, [])) === 3);
ok('arama: PO parçası', array_column(frg_liste($db, ['q' => '1800']), 'po_no') === ['26-1800-70001']);
ok('arama: müşteri', array_column(frg_liste($db, ['q' => 'lidl']), 'po_no') === ['26-1800-70001']);
ok('arama: LIKE özel karakter (%) literal', frg_liste($db, ['q' => '%']) === []);
ok('durum filtresi', count(frg_liste($db, ['durum' => 'pkl_hazir'])) === 1 && frg_liste($db, ['durum' => 'bilinmeyen']) !== null);
$DEPOLAR = ['DEPO2'];
$g = frg_getir($db, $id);
ok('frg_gorunur: başka depo kaydı kapalı, kendi depo açık', frg_gorunur($g) === false && frg_gorunur(['depo' => 'DEPO2']) === true && frg_gorunur(['depo' => '']) === true);
$DEPOLAR = null; $AKTIF_DEPO = 'DEPO1';

echo "\n=== 7b. Durum akışı (v515: 7 adım, tek yazma yolu) ===\n";
$yeniSip = function (string $po, string $durum, array $ek = []) use ($db): array {
    $db->prepare("INSERT INTO frugro_siparisler (po_no, durum, depo, created_at) VALUES (?,?,'',datetime('now'))")->execute([$po, $durum]);
    $i = (int)$db->lastInsertId();
    if (!empty($ek['palet'])) $db->exec("INSERT INTO frugro_palet (siparis_id, sira, palet_no, kalibre, kutu, brut_kg, net_kg) VALUES ($i, 1, '1', '14', 100, 500, 450)");
    if (!empty($ek['fatura_no'])) $db->exec("UPDATE frugro_siparisler SET fatura_no='IHR1' WHERE id=$i");
    return frg_getir($db, $i);
};
$tan = frg_durum_tanimlari();
ok('tanımlar: 7 adım + iptal(0) + eski kapali(7); sıra 1..7', array_column($tan, 'sira', 'etiket') !== [] && $tan['yeni']['sira'] === 1 && $tan['evrak_gonderildi']['sira'] === 7 && $tan['iptal']['sira'] === 0 && $tan['kapali']['sira'] === 7 && count($tan) === 9);
ok('tanımlar: yola çıktı, PKL ve Invoice\'tan SONRA (sahip kararı)', $tan['yola_cikti']['sira'] > $tan['invoice_hazir']['sira'] && $tan['mail_gonderildi']['sira'] > $tan['yola_cikti']['sira']);
ok('frg_durumlar(): aynı kodlar, kod → etiket, eski kapali takma adı durur', array_keys(frg_durumlar()) === array_keys($tan) && frg_durumlar()['kapali'] === 'Kapalı (eski)');
ok('kapalı = evrak_gonderildi + kapali + iptal; evrak bekleyen = mail_gonderildi', frg_kapali_durumlar() === ['evrak_gonderildi', 'kapali', 'iptal'] && frg_evrak_bekleyen_durumlar() === ['mail_gonderildi']);
ok('frg_ilerleme: yeni 1/7, pkl 3/7 (43), kapali 7/7 (100), iptal 0', frg_ilerleme(['durum' => 'yeni']) === ['adim' => 1, 'toplam' => 7, 'yuzde' => 14] && frg_ilerleme(['durum' => 'pkl_hazir'])['yuzde'] === 43 && frg_ilerleme(['durum' => 'kapali']) === ['adim' => 7, 'toplam' => 7, 'yuzde' => 100] && frg_ilerleme(['durum' => 'iptal'])['adim'] === 0);
// bölüm / kapanmamış SQL (SQLite'ta gerçekten çalışır)
foreach (array_keys($tan) as $k) $yeniSip('27-0000-' . str_pad((string)(100 + (int)array_search($k, array_keys($tan), true)), 5, '0', STR_PAD_LEFT), $k);
$say = fn(string $w) => (int)$db->query("SELECT COUNT(*) FROM frugro_siparisler WHERE po_no LIKE '27-0000-%' AND $w")->fetchColumn();
ok('frg_kapanmamis_sql: kapalı üçü hariç (6 açık+evrak bekleyen)', $say(frg_kapanmamis_sql()) === 6 && frg_kapanmamis_sql('s.durum') === "s.durum NOT IN ('evrak_gonderildi','kapali','iptal')");
ok('frg_bolum_sql: acik 5 (mail bekleyen hariç) · evrak 1 · kapali 3; bilinmeyen → boş', $say(frg_bolum_sql('acik')) === 5 && $say(frg_bolum_sql('evrak')) === 1 && $say(frg_bolum_sql('kapali')) === 3 && frg_bolum_sql('x') === '');
ok('frg_sql_kolon: enjeksiyon denemesi "durum"a düşer', frg_kapanmamis_sql("durum; DROP TABLE x") === "durum NOT IN ('evrak_gonderildi','kapali','iptal')");
$DEPOLAR = null;
ok('frg_liste bolum=kapali: eski kapali kaydı Kapanan bölümünde', in_array('27-0000-00108', array_column(frg_liste($db, ['bolum' => 'kapali', 'q' => '27-0000']), 'po_no'), true) && count(frg_liste($db, ['bolum' => 'kapali', 'q' => '27-0000'])) === 3);
ok('frg_liste_say = frg_liste (bölüm + q) ve sayfalama', frg_liste_say($db, ['bolum' => 'acik', 'q' => '27-0000']) === 5 && count(frg_liste($db, ['q' => '27-0000', 'adet' => 4, 'sayfa' => 1])) === 4 && count(frg_liste($db, ['q' => '27-0000', 'adet' => 4, 'sayfa' => 3])) === 1);
$db->exec("UPDATE frugro_siparisler SET parti_no='P-77', plaka='34ABC123', nakliyeci='Aras Nak', son_musteri='Mus X', giris_limani='LIM Y' WHERE po_no='27-0000-00100'");
ok('arama: parti no / plaka / nakliyeci + müşteri & liman tam eşleşme filtresi', count(frg_liste($db, ['q' => 'P-77'])) === 1 && count(frg_liste($db, ['q' => '34abc'])) === 1 && count(frg_liste($db, ['q' => 'aras'])) === 1 && count(frg_liste($db, ['musteri' => 'Mus X'])) === 1 && count(frg_liste($db, ['liman' => 'LIM Y'])) === 1 && frg_liste($db, ['liman' => 'LIM']) === []);
$ar = array_column(frg_liste($db, ['bolum' => 'acik', 'q' => '27-0000', 'sira' => 'artan']), 'po_no'); ok('sira=artan çalışır (hata yok)', count($ar) === 5);

// geçiş matrisi
ok('matris: yeni→hazirlik/iptal; yola_cikti yalnız pkl/invoice\'tan; mail pkl..yola\'dan; evrak yalnız mail\'den', frg_gecis_izinli('yeni', 'hazirlik') && frg_gecis_izinli('yeni', 'iptal') && !frg_gecis_izinli('yeni', 'yola_cikti') && frg_gecis_izinli('pkl_hazir', 'yola_cikti') && frg_gecis_izinli('invoice_hazir', 'yola_cikti') && !frg_gecis_izinli('hazirlik', 'yola_cikti') && frg_gecis_izinli('yola_cikti', 'mail_gonderildi') && frg_gecis_izinli('pkl_hazir', 'mail_gonderildi') && !frg_gecis_izinli('yeni', 'mail_gonderildi'));
ok('matris: evrak_gonderildi YALNIZ mail_gonderildi\'den; kapalılardan çıkış yok', frg_gecis_izinli('mail_gonderildi', 'evrak_gonderildi') && !frg_gecis_izinli('yola_cikti', 'evrak_gonderildi') && !frg_gecis_izinli('invoice_hazir', 'evrak_gonderildi') && !frg_gecis_izinli('evrak_gonderildi', 'iptal') && !frg_gecis_izinli('kapali', 'hazirlik') && !frg_gecis_izinli('iptal', 'yeni'));
ok('otomatik geçişler: yeni|hazirlik→pkl_hazir, pkl_hazir→invoice_hazir; başka yok', frg_otomatik_gecis_mi('yeni', 'pkl_hazir') && frg_otomatik_gecis_mi('hazirlik', 'pkl_hazir') && frg_otomatik_gecis_mi('pkl_hazir', 'invoice_hazir') && !frg_otomatik_gecis_mi('yola_cikti', 'invoice_hazir') && !frg_otomatik_gecis_mi('yeni', 'invoice_hazir'));

// frg_durum_degistir
$durumOf = fn(int $i): string => (string)$db->query("SELECT durum FROM frugro_siparisler WHERE id=$i")->fetchColumn();
$A = $yeniSip('27-1000-00001', 'yeni');
$r1 = frg_durum_degistir($db, $A, 'hazirlik', '', 5);
ok('yeni → hazirlik: ok, olay "yeni → hazirlik" (kod biçimi), audit frugro_durum', $r1['ok'] && $r1['durum'] === 'hazirlik' && $durumOf((int)$A['id']) === 'hazirlik'
    && (string)$db->query("SELECT metin FROM frugro_olaylar WHERE siparis_id={$A['id']} AND tur='durum'")->fetchColumn() === 'yeni → hazirlik'
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action='frugro_durum'")->fetchColumn() >= 1);
$A = frg_getir($db, (int)$A['id']);
$rr = frg_durum_degistir($db, $A, 'yola_cikti', '', 5); ok('hazirlik → yola_cikti reddedilir (422)', !$rr['ok'] && $rr['kod'] === 422 && $durumOf((int)$A['id']) === 'hazirlik');
$rr = frg_durum_degistir($db, $A, 'kapali', '', 5); ok('eski "kapali" hedefi kabul edilmez (400)', !$rr['ok'] && $rr['kod'] === 400);
$rr = frg_durum_degistir($db, $A, 'bilinmeyen', '', 5); ok('bilinmeyen hedef 400', !$rr['ok'] && $rr['kod'] === 400);
$rr = frg_durum_degistir($db, $A, 'iptal', 'x', 5); ok('iptal: gerekçe < 3 karakter → 422', !$rr['ok'] && $rr['kod'] === 422);
$rr = frg_durum_degistir($db, $A, 'pkl_hazir', '', 5); ok('elle pkl_hazir YASAK (yalnız otomatik)', !$rr['ok'] && $durumOf((int)$A['id']) === 'hazirlik');
$rr = frg_durum_degistir($db, $A, 'pkl_hazir', '', 5, 'otomatik'); ok('otomatik: hazirlik → pkl_hazir ok (pkl_uret hazirlik\'ten de ilerletir)', $rr['ok'] && $durumOf((int)$A['id']) === 'pkl_hazir');
$A = frg_getir($db, (int)$A['id']);
$rr = frg_durum_degistir($db, $A, 'mail_gonderildi', '', 5); ok('mail_gonderildi paletsiz siparişte 422', !$rr['ok'] && $rr['kod'] === 422 && str_contains((string)$rr['hata'], 'palet'));
$rr = frg_durum_degistir($db, $A, 'yola_cikti', '', 5); ok('yola_cikti paletsiz 422', !$rr['ok'] && $rr['kod'] === 422);
$B = $yeniSip('27-1000-00002', 'pkl_hazir', ['palet' => 1]);
$rr = frg_durum_degistir($db, $B, 'evrak_gonderildi', '', 5); ok('evrak_gonderildi pkl_hazir\'dan 422', !$rr['ok'] && $rr['kod'] === 422);
ok('yola_cikti paletli pkl_hazir\'dan ok', frg_durum_degistir($db, $B, 'yola_cikti', '', 5)['ok'] && $durumOf((int)$B['id']) === 'yola_cikti');
$B = frg_getir($db, (int)$B['id']);
$rr = frg_durum_degistir($db, $B, 'yola_cikti', '', 5); ok('aynı duruma geçiş 422', !$rr['ok'] && $rr['kod'] === 422);
ok('mail_gonderildi ok → evrak_gonderildi ok (kapalı sayılır)', frg_durum_degistir($db, $B, 'mail_gonderildi', '', 5)['ok'] && frg_durum_degistir($db, frg_getir($db, (int)$B['id']), 'evrak_gonderildi', '', 5)['ok'] && in_array($durumOf((int)$B['id']), frg_kapali_durumlar(), true));
$B = frg_getir($db, (int)$B['id']);
$rr = frg_durum_degistir($db, $B, 'iptal', 'neden var', 5); ok('kapalı (evrak) sipariş iptal edilemez', !$rr['ok'] && $rr['kod'] === 422);
$rr = frg_durum_degistir($db, $B, 'geri', '', 5); ok('evrak → mail geri alma: gerekçe ZORUNLU', !$rr['ok'] && $rr['kod'] === 422);
$rr = frg_durum_degistir($db, $B, 'geri', 'yanlış işaretlendi', 5); ok('evrak → geri = mail_gonderildi', $rr['ok'] && $rr['durum'] === 'mail_gonderildi');
// zamanlar
$z = frg_adim_zamanlari($db, (int)$B['id']);
ok('frg_adim_zamanlari: yola_cikti, mail_gonderildi, evrak_gonderildi zamanları var; PKL yok', isset($z['yola_cikti'], $z['mail_gonderildi'], $z['evrak_gonderildi']) && !isset($z['pkl_hazir']));
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$B['id'], 'durum', 'Yeni → PKL hazır', '2026-01-02 03:04:05']);
$db->prepare("INSERT INTO frugro_olaylar (siparis_id, tur, metin, created_at) VALUES (?,?,?,?)")->execute([$B['id'], 'invoice_uretildi', 'IHR1 · £ 1', '2026-01-03 00:00:00']);
$z = frg_adim_zamanlari($db, (int)$B['id']);
ok('eski etiket biçimi ("Yeni → PKL hazır") ve invoice_uretildi yedeği okunur', ($z['pkl_hazir'] ?? '') === '2026-01-02 03:04:05' && ($z['invoice_hazir'] ?? '') === '2026-01-03 00:00:00');
$h = frg_adim_zaman_haritasi($db, [(int)$A['id'], (int)$B['id'], 999999], ['mail_gonderildi']);
ok('frg_adim_zaman_haritasi: tek sorgu, çok sipariş, kod süzgeci, olmayan id boş', isset($h[(int)$B['id']]['mail_gonderildi']) && count($h[(int)$B['id']]) === 1 && $h[(int)$A['id']] === [] && $h[999999] === []);
// geri_durum
ok('frg_geri_durum: açık adımlar bir önceki; kapali/iptal belgelere göre, mail yaşandıysa mail', frg_geri_durum(['durum' => 'invoice_hazir']) === 'pkl_hazir' && frg_geri_durum(['durum' => 'pkl_hazir']) === 'yeni' && frg_geri_durum(['durum' => 'yeni']) === ''
    && frg_geri_durum(['durum' => 'kapali', 'fatura_no' => 'X'], []) === 'invoice_hazir' && frg_geri_durum(['durum' => 'iptal', 'paletler' => [1]], []) === 'pkl_hazir' && frg_geri_durum(['durum' => 'iptal'], []) === 'yeni'
    && frg_geri_durum(['durum' => 'kapali'], ['mail_gonderildi' => 'x']) === 'mail_gonderildi' && frg_geri_durum(['durum' => 'yola_cikti', 'fatura_no' => 'X']) === 'invoice_hazir' && frg_geri_durum(['durum' => 'mail_gonderildi'], ['yola_cikti' => 'x']) === 'yola_cikti');
// eski kapali: yeniden aç
$K = $yeniSip('27-1000-00003', 'kapali', ['palet' => 1]);
$rr = frg_durum_degistir($db, $K, 'yeniden_ac', '', 5); ok('eski kapali yeniden açılabilir (gerekçesiz) → pkl_hazir (paletli)', $rr['ok'] && $rr['durum'] === 'pkl_hazir');
$Y = $yeniSip('27-1000-00004', 'yeni'); $rr = frg_durum_degistir($db, $Y, 'yeniden_ac', '', 5); ok('yeniden_ac açık siparişte 422', !$rr['ok'] && $rr['kod'] === 422);
$I = $yeniSip('27-1000-00005', 'iptal'); $rr = frg_durum_degistir($db, $I, 'geri', '', 5); ok('iptal → geri gerekçesiz ok (yeni)', $rr['ok'] && $rr['durum'] === 'yeni');
// yarış: bayat $s → 409, durum DEĞİŞMEZ
$Q = $yeniSip('27-1000-00006', 'yeni'); $db->exec("UPDATE frugro_siparisler SET durum='hazirlik' WHERE id={$Q['id']}");
$rr = frg_durum_degistir($db, $Q, 'hazirlik', '', 5); ok('bayat durum: 422/409, kayıt bozulmaz', !$rr['ok']);
$Q2 = $yeniSip('27-1000-00007', 'yeni'); $bayat = $Q2; $db->exec("UPDATE frugro_siparisler SET durum='hazirlik' WHERE id={$Q2['id']}");
$rr = frg_durum_degistir($db, $bayat, 'iptal', 'yarış testi', 5); ok('yarış: koşullu UPDATE 409 döner, durum olayı yazılmaz', !$rr['ok'] && $rr['kod'] === 409 && $durumOf((int)$Q2['id']) === 'hazirlik' && (int)$db->query("SELECT COUNT(*) FROM frugro_olaylar WHERE siparis_id={$Q2['id']} AND tur='durum'")->fetchColumn() === 0);
// kaynak denetimi
$kc = (string)file_get_contents($ROOT . '/config/frugro_core.php');
ok('durum olayı TEK biçim: frg_durum_olay_yaz "<eski> → <yeni>"', str_contains($kc, "\$eski . ' → ' . \$yeni"));

echo "\n=== 7. Gerçek örnekler (FRUGRO_ORNEK_DIR) ===\n";
$dir = (string)getenv('FRUGRO_ORNEK_DIR');
if ($dir === '' || !is_dir($dir)) {
    echo "ATLANDI (FRUGRO_ORNEK_DIR yok)\n";
} else {
    $beklenen = ['26-1622-44080' => '2026-11-10', '26-1623-44081' => '2026-11-12', '26-1624-44082' => '2026-11-14'];
    $bulunan = [];
    foreach (glob(rtrim($dir, '/') . '/*.xlsx') ?: [] as $f) {
        $rr = frg_xlsx_oku($f);
        foreach ($rr['siparisler'] as $sp) $bulunan[$sp['po_no']] = $sp;
        ok(basename($f) . ': tek geçerli PO, uyarı yok', $rr['ok'] && count($rr['siparisler']) === 1 && $rr['uyarilar'] === [], json_encode($rr['uyarilar']));
    }
    foreach ($beklenen as $po => $cikis) {
        $sp = $bulunan[$po] ?? null;
        ok("$po: çıkış $cikis, varış +8 gün, 5200 kutu, 26 palet, FCA, 4,6 € (46000)", $sp && $sp['cikis_tarihi'] === $cikis && $sp['varis_tarihi'] === gmdate('Y-m-d', strtotime($cikis . ' UTC') + 8 * 86400) && $sp['kutu'] === 5200 && $sp['palet'] === 26 && $sp['incoterm'] === 'FCA' && $sp['fiyat_kutu_birim'] === 46000 && frg_hesap($sp)['tutar_kurus'] === 2392000);
    }
}

echo "\n=== 8. Statik değişmezler ===\n";
$kod = (string)file_get_contents($ROOT . '/config/frugro_core.php');
ok('include-only koruma: realpath(SCRIPT_FILENAME) === __FILE__', str_contains($kod, "realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__"));
ok('ALTER / DROP TABLE yok; CREATE yalnız frg_tablolar içinde', !preg_match('/\b(ALTER|DROP)\s+TABLE\b/i', $kod));
ok('getCalculatedValue / diske yazma / e-posta literali yok', !str_contains($kod, 'getCalculatedValue') && !preg_match('/\b(file_put_contents|move_uploaded_file|fopen)\s*\(/', $kod) && !preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}/', $kod));
ok('ReadDataOnly kullanılıyor', str_contains($kod, 'setReadDataOnly(true)'));
$mg = (string)file_get_contents($ROOT . '/migrate.php');
ok("migrate.php: ne=frugro POST dalı (csrf_check) + mg_kart_ac('frugro') + frg_migrate çağrısı yalnız burada", (bool)preg_match("/'ne'\] \?\? ''\) === 'frugro'\) \{\s*csrf_check\(/", $mg) && str_contains($mg, "mg_kart_ac('frugro')") && str_contains($mg, 'frg_migrate($pdo)'));
$cagri = [];
foreach (array_merge(glob($ROOT . '/*.php') ?: [], glob($ROOT . '/config/*.php') ?: []) as $f) {
    if (basename($f) === 'frugro_core.php') continue;
    if (preg_match('/\bfrg_migrate\s*\(/', (string)file_get_contents($f))) $cagri[] = basename($f);
}
ok('frg_migrate() yalnız migrate.php\'den çağrılır (otomatik çalıştırma yok)', $cagri === ['migrate.php'], implode(',', $cagri));
ok('yeni yetki yok: permission_catalog\'a frugro eklenmedi', !str_contains((string)file_get_contents($ROOT . '/config/helpers.php'), "'frugro."));

siparis_test_bitir();
