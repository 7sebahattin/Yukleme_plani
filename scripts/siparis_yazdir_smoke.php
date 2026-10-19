<?php
// =========================================================
// scripts/siparis_yazdir_smoke.php — "Açık Sevkiyatlar" yazdırma (v382): ortak filtre, sütun verisi, uç (GET JSON)
//   php scripts/siparis_yazdir_smoke.php                  testler
//   php scripts/siparis_yazdir_smoke.php --json           tarayıcı testi için küçük JSON fixture'ı (stdout)
//   php scripts/siparis_yazdir_smoke.php --json-buyuk     300 satırlık JSON fixture'ı (stdout)
//   php scripts/siparis_yazdir_smoke.php --json-wp        WP Paylaş kartı (v394) JSON fixture'ı (stdout)
// Bellek içi SQLite + stub auth + SENTETİK veri (34TST…, TC9000…, @ornek.test; kişisel veri YOK). Canlı DB'ye HİÇ dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/siparis_yazdir.php';
require_once $ROOT . '/config/siparis_wp.php';

$MOD = in_array('--json', $argv ?? [], true) ? 'json' : (in_array('--json-buyuk', $argv ?? [], true) ? 'buyuk' : (in_array('--json-wp', $argv ?? [], true) ? 'wp' : 'test'));
$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);

class SipExit extends RuntimeException {}
$SIPT = ['h' => [], 'kod' => 200];
function sipt_header(string $h, bool $replace = true, int $code = 0): void
{
    global $SIPT;
    if (str_contains($h, ':')) { [$a, $b] = explode(':', $h, 2); $SIPT['h'][strtolower(trim($a))] = trim($b); }
    if ($code > 0) $SIPT['kod'] = $code;
}
function sipt_code(?int $c = null) { global $SIPT; if ($c !== null) $SIPT['kod'] = $c; return $SIPT['kod']; }
function sip_calistir(string $tmp): void { include $tmp; }
/** Uç dosyasını çalıştırır (header/exit yakalanır). @return array{govde:string,json:?array,kod:int,h:array,hata:?string} */
function uc(string $dosya, array $get = [], string $yontem = 'GET'): array
{
    global $ROOT, $SIPT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'sipt_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'sipt_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new SipExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_yzd_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $SIPT = ['h' => [], 'kod' => 200];
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = $yontem; $_SERVER['PHP_SELF'] = '/' . $dosya;
    ob_start();
    $hata = null;
    try { sip_calistir($tmp); }
    catch (SipExit $e) { /* normal çıkış */ }
    catch (Throwable $e) { $hata = $e->getMessage(); }
    $govde = (string)ob_get_clean();
    @unlink($tmp);
    $j = json_decode($govde, true);
    return ['govde' => $govde, 'json' => is_array($j) ? $j : null, 'kod' => $SIPT['kod'], 'h' => $SIPT['h'], 'hata' => $hata];
}
function rol(array $perms, bool $admin = false, int $uid = 1, ?array $depolar = null): void
{
    global $PERMS, $IS_ADMIN, $UID, $DEPOLAR;
    $PERMS = $perms; $IS_ADMIN = $admin; $UID = $uid; $DEPOLAR = $depolar;
}
function sev(PDO $db, array $a): int
{
    $v = $a + ['tedarikci_no' => '', 'durum' => 'yola_cikti', 'depo' => 'DEPO1', 'var_po' => 1];
    $db->prepare('INSERT INTO siparis_sevkiyatlar (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
    return (int)$db->lastInsertId();
}
function kalem(PDO $db, int $sid, array $a): void
{
    $v = $a + ['sevkiyat_id' => $sid, 'ian' => '', 'sira' => 1];
    $db->prepare('INSERT INTO siparis_kalemler (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
}
$dun = date('Y-m-d', strtotime('-1 day')); $bugun = date('Y-m-d'); $yarin = date('Y-m-d', strtotime('+1 day')); $eski = date('Y-m-d', strtotime('-9 day'));

// ── tohum (sentetik; şoför kolonları YOK — önce kolonsuz hâli sınanır) ──
$ID = [];
$ID['yarin'] = sev($db, ['order_ref' => 'TC900103.10', 'yukleme_tarihi' => $yarin, 'alici_adi' => 'LIDL DE', 'navlun_no' => '6100000103', 'nakliyeci' => 'Test Lojistik C', 'plakalar' => '34TST103', 'toplam_palet' => 5, 'toplam_koli' => 500]);
kalem($db, $ID['yarin'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'pf' => 100, 'po_palet' => 5, 'etkin_palet' => 5, 'etkin_koli' => 500]);
$ID['bugun'] = sev($db, ['order_ref' => 'TC900102.10', 'yukleme_tarihi' => $bugun, 'alici_kodu' => '1038512', 'alici_adi' => 'LIDL GB', 'navlun_no' => '6100000102', 'nakliyeci' => 'Test Lojistik B', 'plakalar' => '34TST102-34TST202', 'toplam_palet' => 7, 'toplam_koli' => 1000]);
kalem($db, $ID['bugun'], ['kalibre' => '12', 'sira' => 2, 'aciklama' => 'Testfruit 12 PC', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'pf' => 100, 'po_palet' => 3, 'etkin_palet' => 3, 'etkin_koli' => 450]);
kalem($db, $ID['bugun'], ['kalibre' => '10', 'sira' => 1, 'aciklama' => 'Testfruit 10 PC', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'pf' => 100, 'po_palet' => 4, 'etkin_palet' => 4, 'etkin_koli' => 550]);
kalem($db, $ID['bugun'], ['kalibre' => '14', 'sira' => 3, 'aciklama' => 'Testfruit 14 PC', 'po_palet' => 0, 'etkin_palet' => 0, 'etkin_koli' => 0]);   // sıfır palet+koli: atlanır
$ID['gecmis'] = sev($db, ['order_ref' => 'TC900101.10', 'yukleme_tarihi' => $dun, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'navlun_no' => '6100000101', 'nakliyeci' => 'Test Lojistik A', 'plakalar' => '34TST101-34TST201',
    'planlanan_nakliyeci' => 'PLAN LOJISTIK', 'planlanan_plakalar' => '06PLN001', 'toplam_palet' => 23, 'toplam_koli' => 3680]);
kalem($db, $ID['gecmis'], ['kalibre' => '14', 'aciklama' => 'Pomegranate 14 PC LOSE (TR)', 'cesit' => 'HICAZ', 'koli_icerik' => '3,5 KG', 'pf' => 160, 'po_palet' => 23, 'etkin_palet' => 23, 'etkin_koli' => 3680]);
$ID['eski'] = sev($db, ['order_ref' => 'TC900100.10', 'yukleme_tarihi' => $eski, 'alici_kodu' => '999999', 'alici_adi' => 'BILINMEYEN ALICI', 'toplam_palet' => 2, 'toplam_koli' => 0, 'durum' => 'hazirlik']);
$ID['bos_tarih'] = sev($db, ['order_ref' => 'TC900104.10', 'alici_adi' => 'LIDL FR', 'planlanan_nakliyeci' => 'SADECE PLAN', 'planlanan_plakalar' => '06PLN002', 'durum' => 'siparis_alindi', 'var_po' => 0, 'depo' => '']);
$ID['kapali'] = sev($db, ['order_ref' => 'TC900105.10', 'yukleme_tarihi' => $dun, 'durum' => 'mail_gonderildi', 'toplam_palet' => 99]);
$ID['depo2'] = sev($db, ['order_ref' => 'TC900106.10', 'yukleme_tarihi' => $dun, 'depo' => 'DEPO2', 'toplam_palet' => 88]);
$ID['silinmis'] = sev($db, ['order_ref' => 'TC900107.10', 'yukleme_tarihi' => $dun, 'deleted_at' => '2026-10-01 10:00:00', 'toplam_palet' => 77]);
// beyan: iki aktif bağ (parti no "/" ile birleşir), biri silinmiş beyan (atlanır)
$db->exec("INSERT INTO customs_declarations (status, party_no, created_at) VALUES ('taslak', 'P-61/225', '2026-10-01 10:00:00'), ('taslak', 'P-61/226', '2026-10-01 10:00:00')");
$bk = (int)$db->lastInsertId();
foreach ([$bk - 1, $bk] as $b) $db->exec("INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, durum, created_by, created_at) VALUES ({$ID['gecmis']}, $b, 'elle', 'aktif', 1, '2026-10-02 10:00:00')");

$F = fn(array $g = []) => siparis_liste_filtre($g);
$ADMIN = ['siparis.read', 'siparis.write', 'beyan.read'];

if ($MOD !== 'test') {   // ── tarayıcı fixture'ı ──
    rol($ADMIN, true);
    siparis_sofor_migrate($db);
    $db->exec("UPDATE siparis_sevkiyatlar SET sofor_adi = 'Test Sürücü Bir', sofor_telefon = '+90 532 000 00 01' WHERE id = {$ID['gecmis']}");
    $db->exec("UPDATE siparis_sevkiyatlar SET sofor_adi = 'Test Sürücü İki' WHERE id = {$ID['bugun']}");
    if ($MOD === 'wp') {
        siparis_yurtici_gumruk_migrate($db);
        $db->exec("UPDATE siparis_sevkiyatlar SET yurtici_gumruk = 'Test Gümrük İdaresi' WHERE id = {$ID['gecmis']}");
        echo json_encode(siparis_wp_veri(siparis_wp_oku($db, $ID['gecmis']), ['parti_yetki' => true]), JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($MOD === 'buyuk') {
        for ($i = 0; $i < 300; $i++) {
            $sid = sev($db, ['order_ref' => sprintf('TC9100%02d.10', $i % 100) . $i, 'yukleme_tarihi' => date('Y-m-d', strtotime('+' . ($i % 20 - 10) . ' day')), 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'navlun_no' => '61000' . (1000 + $i),
                'nakliyeci' => 'Test Lojistik ' . ($i % 7), 'plakalar' => '34TST' . (100 + $i) . '-34TST' . (500 + $i), 'toplam_palet' => 20 + $i % 4, 'toplam_koli' => 3000 + $i, 'sofor_adi' => 'Test Sürücü ' . $i, 'sofor_telefon' => '+90 532 000 ' . (1000 + $i)]);
            kalem($db, $sid, ['kalibre' => (string)(10 + $i % 3 * 2), 'aciklama' => 'Testfruit ' . (10 + $i % 3 * 2) . ' PC LOSE (TR)', 'cesit' => 'TESTVAR', 'koli_icerik' => '3,5 KG', 'pf' => 160, 'po_palet' => 20, 'etkin_palet' => 20, 'etkin_koli' => 3000]);
        }
    }
    $o = siparis_yazdir_oku($db, $F());
    $v = siparis_yazdir_veri($o, ['bugun' => $bugun, 'basim' => date('d.m.Y H:i'), 'depo' => 'DEPO1', 'baslik' => 'Açık Sevkiyatlar', 'filtre_ozet' => '', 'parti_yetki' => true]);
    echo json_encode($v, JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo "=== 1. siparis_liste_filtre (siparisler.php'nin ESKİ WHERE/param dizgileriyle birebir) ===\n";
rol($ADMIN, true);
$a = $F([]);
ok('boş GET: WHERE yalnız silinmemiş, param yok, iki bölüm açık', $a['temelWhere'] === 's.deleted_at IS NULL' && $a['temelPar'] === [] && $a['bolumAcik'] && $a['bolumKapali'] && !$a['tek'] && !$a['filtreVar'] && !$a['detayFiltre'] && $a['detayOzet'] === []);
ok('acikSql / kapaliSql sabit', $a['acikSql'] === "s.durum NOT IN ('mail_gonderildi','iptal')" && $a['kapaliSql'] === "s.durum IN ('mail_gonderildi','iptal')", $a['acikSql']);
$b = $F(['q' => ' ab ']);
ok('q: 6 kolon LIKE :q1..:q6, değerler %ab%', $b['temelWhere'] === 's.deleted_at IS NULL AND (s.order_ref LIKE :q1 OR s.tur_no LIKE :q2 OR s.navlun_no LIKE :q3 OR s.plakalar LIKE :q4 OR s.alici_adi LIKE :q5 OR s.nakliyeci LIKE :q6)'
    && $b['temelPar'] === [':q1' => '%ab%', ':q2' => '%ab%', ':q3' => '%ab%', ':q4' => '%ab%', ':q5' => '%ab%', ':q6' => '%ab%'] && $b['q'] === 'ab' && $b['filtreVar'] && !$b['detayFiltre']);
$c = $F(['cw' => '43.2026', 'alici' => '1038529', 'eksik' => 'pkl', 'catisma' => '1', 'po' => '7', 'tarih_bas' => '2026-10-01', 'tarih_bit' => '2026-10-31']);
ok('cw + alici + eksik + catisma + po + tarih: WHERE sırası ve param adları', $c['temelWhere'] === 's.deleted_at IS NULL AND s.cw_hafta = :cwh AND s.cw_yil = :cwy AND s.alici_kodu = :alici AND s.var_pkl = 0 AND s.acik_catisma > 0 AND s.po_belge_id = :po AND s.yukleme_tarihi >= :tb AND s.yukleme_tarihi <= :tt'
    && $c['temelPar'] === [':cwh' => 43, ':cwy' => 2026, ':alici' => '1038529', ':po' => 7, ':tb' => '2026-10-01', ':tt' => '2026-10-31'] && $c['detayFiltre'] && $c['filtreVar']);
ok('detayOzet metinleri', $c['detayOzet'] === ['CW 43.2026', 'PKL eksik', 'Çatışmalı', 'PO #7', 'Alıcı 1038529', 'Tarih aralığı'], json_encode($c['detayOzet'], JSON_UNESCAPED_UNICODE));
$d = $F(['durum' => 'zzz', 'eksik' => 'xx', 'cw' => '43', 'tarih_bas' => 'dün', 'tarih_bit' => '31.31.2026']);
ok('geçersiz değerler elenir (durum/eksik/cw/tarih)', $d['f_durum'] === '' && $d['f_eksik'] === '' && $d['f_cw'] === '' && $d['t_bas'] === '' && $d['t_bit'] === '' && $d['temelWhere'] === 's.deleted_at IS NULL' && !$d['filtreVar']);
$e = $F(['durum' => 'acik']); $k = $F(['durum' => 'kapali']); $ab = $F(['durum' => 'arac_bekleyen']); $yc = $F(['durum' => 'yola_cikti']);
ok('durum=acik: yalnız açık bölüm, tek değil, filtreVar (detay değil)', $e['bolumAcik'] && !$e['bolumKapali'] && !$e['tek'] && $e['filtreVar'] && !$e['detayFiltre']);
ok('durum=kapali: yalnız kapanan bölüm', !$k['bolumAcik'] && $k['bolumKapali'] && !$k['tek']);
ok('durum=arac_bekleyen: tek + IN (siparis_alindi, hazirlik) + özet "Araç bekleyen"', $ab['tek'] && $ab['tekSql'] === "s.durum IN ('siparis_alindi','hazirlik')" && $ab['detayOzet'] === ['Araç bekleyen'] && $ab['detayFiltre']);
ok('durum=yola_cikti: tek + durum = ... + etiket özeti', $yc['tek'] && $yc['tekSql'] === "s.durum = 'yola_cikti'" && $yc['detayOzet'] === [siparis_durumlar()['yola_cikti']['etiket']]);
rol($ADMIN, false, 1, ['DEPO1']);
$g = $F(['q' => 'x']);
ok('depo kısıtı: dsql + :udm0 param temelPar içinde; dprm ayrıca', str_contains($g['dsql'], ':udm0') && $g['temelPar'][':udm0'] === 'DEPO1' && $g['dprm'] === [':udm0' => 'DEPO1'] && str_ends_with($g['temelWhere'], $g['dsql']));
rol($ADMIN, true);

echo "\n=== 2. Ülke haritası ===\n";
$h = siparis_yazdir_ulke_haritasi();
ok('12 kayıt, kodlar benzersiz', count($h) === 12 && count(array_unique(array_column($h, 'kod'))) === 12);
$bek = ['1038500' => 'ALMANYA', '1038508' => 'HOLLANDA', '1038521' => 'POLONYA', '1038512' => 'İNGİLTERE', '1038519' => 'BULGARİSTAN', '1038504' => 'ROMANYA', '1038529' => 'SIRBİSTAN', '1038515' => 'HIRVATİSTAN', '1038505' => 'MACARİSTAN', '1038524' => 'FRANSA', '1038514' => 'SLOVAKYA', '1038501' => 'ÇEKYA'];
$hepsi = true; foreach ($bek as $id => $ad) $hepsi = $hepsi && siparis_yazdir_ulke($id, null) === $ad && siparis_yazdir_ulke(' ' . $id . ' ', 'x') === $ad;
ok('12 mal alıcı ID → Türkçe ad', $hepsi);
ok('kod yoksa alici_adi sonundaki ISO kodu ("LIDL RS")', siparis_yazdir_ulke(null, 'LIDL RS') === 'SIRBİSTAN' && siparis_yazdir_ulke('', 'LIDL GB') === 'İNGİLTERE' && siparis_yazdir_ulke(null, ' LIDL CZ ') === 'ÇEKYA');
ok('bilinen kod adı yedeğe baskındır', siparis_yazdir_ulke('1038500', 'LIDL RS') === 'ALMANYA');
ok('bilinmeyen kod + ISO değil → boş', siparis_yazdir_ulke('999999', 'LIDL SRBIJA KD') === '' && siparis_yazdir_ulke(null, null) === '' && siparis_yazdir_ulke('999999', 'LIDL XX') === '' && siparis_yazdir_ulke(null, 'lidl rs') === '');
ok('ISO kodu kelime sonunda olmalı ("LIDL RSX" tutmaz)', siparis_yazdir_ulke(null, 'LIDL RSX') === '');

echo "\n=== 3. Gramaj + süzgeç ortaklığı ===\n";
ok('[14,10,12] → "10--12--14" (sayısal artan)', siparis_yazdir_gramaj([['kalibre' => '14', 'po_palet' => 1], ['kalibre' => '10', 'po_palet' => 1], ['kalibre' => '12', 'po_palet' => 1]]) === '10--12--14');
ok('sayısal sıra metin sırası değil ("9","10","100")', siparis_yazdir_gramaj([['kalibre' => '100', 'po_palet' => 1], ['kalibre' => '9', 'po_palet' => 1], ['kalibre' => '10', 'po_palet' => 1]]) === '9--10--100');
ok('tekil + boş kalibre atlanır', siparis_yazdir_gramaj([['kalibre' => '12', 'po_palet' => 1], ['kalibre' => '12', 'po_palet' => 2], ['kalibre' => '', 'po_palet' => 1], ['kalibre' => ' 14 ', 'po_palet' => 1]]) === '12--14');
ok('sıfır palet + sıfır/boş koli atlanır; etkin_palet baskın; palet bilinmiyorsa (null) atlanmaz', siparis_yazdir_gramaj([['kalibre' => '10', 'po_palet' => 0, 'etkin_palet' => 0, 'etkin_koli' => 0], ['kalibre' => '12', 'po_palet' => 0, 'etkin_palet' => 3], ['kalibre' => '14', 'po_palet' => 0, 'etkin_koli' => 40], ['kalibre' => '16']]) === '12--14--16');
ok('kalem yoksa boş', siparis_yazdir_gramaj([]) === '');
ok('sayısal olmayan kalibre doğal sırayla (A2 < A10)', siparis_yazdir_gramaj([['kalibre' => 'A10', 'po_palet' => 1], ['kalibre' => 'A2', 'po_palet' => 1]]) === 'A2--A10');
// ⑭ aynı kalem dizisinde iki süzgeç aynı kalemleri atar
$kal = [];
foreach ([[0, 0, 0], [0, null, 0], [0, null, 5], [3, null, 0], [null, 0, 0], [null, null, null], ['0', '', ''], [0, 0, ''], [2, 0, 0], [0, 4, 0]] as $i => [$po, $et, $ek]) {
    $kal[] = ['kalibre' => (string)(10 + $i), 'aciklama' => 'K' . $i, 'po_palet' => $po, 'etkin_palet' => $et, 'etkin_koli' => $ek];
}
$ayni = true;
foreach ($kal as $k1) $ayni = $ayni && ((sipui_kalem_satirlari([$k1], 10)['satirlar'] !== []) === (siparis_yazdir_gramaj([$k1]) !== ''));
ok('gramaj süzgeci = sipui_kalem_satirlari süzgeci (10 kombinasyon)', $ayni);
$ayni2 = true;
foreach ($kal as $k1) $ayni2 = $ayni2 && ((sipui_kalem_satirlari([$k1], 10)['satirlar'] !== []) === (sipui_kalem_coz($k1) !== null));
ok('sipui_kalem_coz = liste süzgeci (10 kombinasyon)', $ayni2);
$kc = sipui_kalem_coz(['kalibre' => '14', 'aciklama' => 'Pomegranate 14 PC LOSE (TR)', 'cesit' => 'HICAZ', 'koli_icerik' => '3,5 KG', 'pf' => 160, 'po_palet' => 23, 'etkin_palet' => 23, 'etkin_koli' => 3680]);
ok('sipui_kalem_coz: urun/kalibre/palet/koli/meta', $kc !== null && $kc['urun'] === 'Pomegranate 14 PC LOSE (TR) · HICAZ · 3,5 KG' && $kc['kalibre'] === '14' && $kc['palet'] == 23 && $kc['koli'] == 3680 && $kc['meta'] === 'Pal. 23 · PF 160'
    && $kc['ad'] === 'Pomegranate 14 PC LOSE (TR) · HICAZ · 3,5 KG', json_encode($kc, JSON_UNESCAPED_UNICODE));
$kc2 = sipui_kalem_coz(['kalibre' => '12', 'urun' => 'Nar', 'po_palet' => 1]);
ok('sipui_kalem_coz: açıklama yoksa urun (kalibre ürüne EKLENMEZ, liste metninde eklenir)', $kc2 !== null && $kc2['urun'] === 'Nar' && $kc2['ad'] === 'Nar 12' && $kc2['kalibre'] === '12');
ok('sipui_kalem_coz: tamamen boş kalem → null; etkin_palet boşsa po_palet', sipui_kalem_coz([]) === null && (sipui_kalem_coz(['aciklama' => 'X', 'etkin_palet' => '', 'po_palet' => 2])['palet'] ?? null) == 2);

echo "\n=== 4. Okuma + veri (yetkili, şoför kolonları YOK) ===\n";
rol($ADMIN, true);
$o = siparis_yazdir_oku($db, $F());
ok('kapsam açık; kapalı + silinmiş çıkmaz; depo kısıtsız admin DEPO2 satırını da görür', $o['kapsam'] === 'acik' && !$o['kesildi'] && !in_array($ID['kapali'], array_column($o['rows'], 'id')) && !in_array($ID['silinmis'], array_column($o['rows'], 'id')) && in_array($ID['depo2'], array_column($o['rows'], 'id')));
ok('şoför kolonları kurulu değil: sofor=false, SQL hata vermedi', $o['sofor'] === false && !array_key_exists('sofor_adi', $o['rows'][0]));
$sira = array_map('intval', array_column($o['rows'], 'id'));
ok('sıra = ekran sırası: tarih artan, NULL tarih SONDA, aynı tarihte id', $sira === [$ID['eski'], $ID['gecmis'], $ID['depo2'], $ID['bugun'], $ID['yarin'], $ID['bos_tarih']] || $sira === [$ID['eski'], $ID['depo2'], $ID['gecmis'], $ID['bugun'], $ID['yarin'], $ID['bos_tarih']], json_encode($sira));
$ctx = ['bugun' => $bugun, 'basim' => '19.10.2026 14:32', 'depo' => 'DEPO1', 'baslik' => 'Açık Sevkiyatlar', 'filtre_ozet' => '', 'parti_yetki' => true];
$v = siparis_yazdir_veri($o, $ctx);
$satir = []; foreach ($v['satirlar'] as $s) $satir[$s['h'][3]] = $s;
$gec = $satir['TC900101.10']; $bug = $satir['TC900102.10']; $yar = $satir['TC900103.10']; $bos = $satir['TC900104.10']; $esk = $satir['TC900100.10'];
ok('13 sütun, sahibin sırası ve başlıkları', array_column($v['kolonlar'], 'l') === ['ÇIKIŞ TARİHİ', 'PARTİ NO', 'NAVLUN NO', 'SİPARİŞ NO', 'ŞOFÖR ADI', 'TEL', 'PLAKA', 'LOJİSTİK FİRMA', 'ÜLKE', 'ÜRÜN BİLGİSİ', 'PALET SAYISI', 'KASA SAYISI', 'GRAMAJ']
    && array_column($v['kolonlar'], 'k') === ['tarih', 'parti', 'navlun', 'siparis', 'sofor', 'tel', 'plaka', 'lojistik', 'ulke', 'urun', 'palet', 'kasa', 'gramaj'] && abs(array_sum(array_column($v['kolonlar'], 'w')) - 100) < 0.001);
ok('her satırda 13 hücre', count(array_unique(array_map(fn($s) => count($s['h']), $v['satirlar']))) === 1 && count($gec['h']) === 13);
ok('sahibin örnek satırı: tarih+✓, parti, navlun, sipariş, plaka, firma, ülke, ürün, palet, kasa, gramaj', $gec['h'] === [sipui_tarih($dun) . ' ✓', 'P-61/225/P-61/226', '6100000101', 'TC900101.10', '', '', '34TST101-34TST201', 'Test Lojistik A', 'SIRBİSTAN',
    'Pomegranate 14 PC LOSE (TR) · HICAZ · 3,5 KG · Pal. 23 · PF 160', '23', '3.680', '14'], json_encode($gec['h'], JSON_UNESCAPED_UNICODE));
ok('birden çok beyan parti no "/" ile birleşir', $gec['h'][1] === 'P-61/225/P-61/226' && $bug['h'][1] === '');
ok('boş hücre BOŞ (tire YOK): şoför, tel, parti', $gec['h'][4] === '' && $gec['h'][5] === '' && !in_array('—', $bos['h'], true) && !in_array('-', $bos['h'], true));
ok('planlanan plaka / planlanan nakliyeci BASILMAZ', $bos['h'][6] === '' && $bos['h'][7] === '' && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), 'SADECE PLAN') && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), 'PLAN LOJISTIK') && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), '06PLN'));
ok('sarı: dün → gecmis + "✓" son eki; bugün → bugun (✓ yok); yarın / boş → düz', $gec['gecmis'] && str_ends_with($gec['h'][0], ' ✓') && !$gec['bugun'] && $esk['gecmis'] && $bug['bugun'] && !$bug['gecmis'] && $bug['h'][0] === sipui_tarih($bugun) && !$yar['gecmis'] && !$yar['bugun'] && $yar['h'][0] === sipui_tarih($yarin)
    && !$bos['gecmis'] && !$bos['bugun'] && $bos['h'][0] === '' && $bos['gun'] === null && $gec['gun'] === $dun);
ok('gecmis_say = geçmiş tarihli satır sayısı', $v['gecmis_say'] === count(array_filter($v['satirlar'], fn($s) => $s['gecmis'])) && $v['gecmis_say'] >= 3);
ok('ülke: kod → ad, kod yoksa alici_adi sonu, bilinmeyen boş', $bug['h'][8] === 'İNGİLTERE' && $yar['h'][8] === 'ALMANYA' && $esk['h'][8] === '' && $bos['h'][8] === 'FRANSA');
ok('ürün: çok kalibre çok satır (sıra: kalibre 10, 12), sıfır palet kalemi (14) YOK', $bug['h'][9] === "Testfruit 10 PC · TESTVAR · 3,5 KG · Pal. 4 · PF 100\nTestfruit 12 PC · TESTVAR · 3,5 KG · Pal. 3 · PF 100", json_encode($bug['h'][9], JSON_UNESCAPED_UNICODE));
ok('gramaj: "10--12" (14 sıfır palet atlandı)', $bug['h'][12] === '10--12' && $yar['h'][12] === '12' && $bos['h'][12] === '' && $bos['h'][9] === '');
ok('palet/kasa: NULL → boş, 0 → "0"', $bos['h'][10] === '' && $bos['h'][11] === '' && $esk['h'][10] === '2' && $esk['h'][11] === '0');
$tp = 0; $tk = 0; foreach ($o['rows'] as $r) { $tp += (int)$r['toplam_palet']; $tk += (int)$r['toplam_koli']; }
ok('toplam = Σ palet / kasa (NULL hariç, string biçimli)', $v['toplam'] === ['palet' => sipui_sayi($tp), 'kasa' => sipui_sayi($tk)] && $tp === 2 + 23 + 88 + 7 + 5 && $v['adet'] === 6);
$ob = siparis_yazdir_oku($db, $F(['q' => 'TC900104']));
$vb = siparis_yazdir_veri($ob, $ctx);
ok('hiç palet/kasa değeri yoksa toplam boş', $vb['adet'] === 1 && $vb['toplam'] === ['palet' => '', 'kasa' => '']);
ok('başlık / dosya / meta / limit', $v['baslik'] === 'Açık Sevkiyatlar' && $v['dosya'] === 'Acik-Sevkiyatlar-19.10.2026' && $v['basim'] === '19.10.2026 14:32' && $v['depo'] === 'DEPO1' && $v['limit'] === 500 && $v['kesildi'] === false && $v['ok'] === true);
ok('notlar: şoför kurulmadı + kişisel veri uyarısı (SON)', in_array('Şoför alanları kurulmadı.', $v['notlar'], true) && str_contains(end($v['notlar']), 'Kişisel veri içerir') && !in_array('Parti no için beyan okuma yetkisi gerekir.', $v['notlar'], true));
ok('sevkiyatlar arası gün değişimi için gun alanı her satırda (yoksa null)', count(array_filter($v['satirlar'], fn($s) => array_key_exists('gun', $s))) === count($v['satirlar']));

echo "\n=== 5. Kapsam ===\n";
$thr = false; try { siparis_yazdir_oku($db, $F(['durum' => 'kapali'])); } catch (RuntimeException $e) { $thr = $e->getMessage() === 'kapsam_yok'; }
ok('durum=kapali → kapsam_yok', $thr);
$ot = siparis_yazdir_oku($db, $F(['durum' => 'yola_cikti']));
ok('durum=yola_cikti → tek kapsam, yalnız yola_cikti satırları', $ot['kapsam'] === 'tek' && count($ot['rows']) > 0 && !in_array($ID['eski'], array_column($ot['rows'], 'id')) && !in_array($ID['bos_tarih'], array_column($ot['rows'], 'id')));
$oa = siparis_yazdir_oku($db, $F(['durum' => 'arac_bekleyen']));
ok('durum=arac_bekleyen → siparis_alindi + hazirlik', $oa['kapsam'] === 'tek' && array_map('intval', array_column($oa['rows'], 'id')) === [$ID['eski'], $ID['bos_tarih']]);
$vt = siparis_yazdir_veri($ot, array_merge($ctx, ['baslik' => siparis_yazdir_baslik($F(['durum' => 'yola_cikti']))]));
ok('tek kapsam başlığı "Sevkiyatlar — <etiket>", dosya adı Acik- ile başlamaz', $vt['baslik'] === 'Sevkiyatlar — ' . siparis_durumlar()['yola_cikti']['etiket'] && str_starts_with($vt['dosya'], 'Sevkiyatlar-') && siparis_yazdir_baslik($F(['durum' => 'arac_bekleyen'])) === 'Sevkiyatlar — Araç bekleyen' && siparis_yazdir_baslik($F()) === 'Açık Sevkiyatlar');
$oq = siparis_yazdir_oku($db, $F(['q' => 'TC9001', 'tarih_bas' => $dun, 'alici' => '1038529']));
ok('arama + tarih + alıcı filtresi ekranla aynı süzer', array_map('intval', array_column($oq['rows'], 'id')) === [$ID['gecmis']]);
rol($ADMIN, false, 1, ['DEPO1']);
$od = siparis_yazdir_oku($db, $F());
ok('depo kısıtı: başka depo satırı YOK, boş depolu satır VAR', !in_array($ID['depo2'], array_column($od['rows'], 'id')) && in_array($ID['bos_tarih'], array_column($od['rows'], 'id')) && in_array($ID['gecmis'], array_column($od['rows'], 'id')));
rol($ADMIN, true);

echo "\n=== 6. Yetki: parti no ===\n";
rol(['siparis.read'], false);
$op = siparis_yazdir_oku($db, $F(['q' => 'TC900101']));
$vp = siparis_yazdir_veri($op, array_merge($ctx, ['parti_yetki' => function_exists('can_beyan') && can_beyan('read')]));
ok('beyan.read yok: parti sütunu BOŞ + not', $vp['satirlar'][0]['h'][1] === '' && in_array('Parti no için beyan okuma yetkisi gerekir.', $vp['notlar'], true));
rol($ADMIN, true);

echo "\n=== 6b. WP Paylaş — şoför / gümrük kolonları YOK (v394) ===\n";
rol($ADMIN, true);
$w = siparis_wp_oku($db, $ID['gecmis']);
ok('siparis_wp_oku: dizi, sofor=false, gumruk=false (kolonsuz SQL hatası yok)', is_array($w) && $w['sofor'] === false && $w['gumruk'] === false);
$v = siparis_wp_veri($w, ['parti_yetki' => true]);
ok('10 alan: anahtarlar ve etiketler sahibin sırasında', array_column($v['alanlar'], 'k') === ['parti', 'tarih', 'siparis', 'navlun', 'nakliyeci', 'plaka', 'sofor', 'tel', 'gumruk', 'ulke']
    && array_column($v['alanlar'], 'etiket') === ['Parti No', 'Yükleme tarihi', 'Sipariş No', 'Navlun No', 'Nakliyeci', 'Plakalar', 'Şoför adı', 'Şoför telefonu', 'Yurt içi gümrük', 'Alıcı Ülke']);
$dv = array_column($v['alanlar'], 'deger', 'k');
ok('değerler: parti, tarih (✓ YOK), sipariş, navlun, gerçek nakliyeci/plaka (planlı DEĞİL), şoför/tel/gümrük "—"', $dv === ['parti' => 'P-61/225 / P-61/226', 'tarih' => sipui_tarih($dun), 'siparis' => 'TC900101.10', 'navlun' => '6100000101', 'nakliyeci' => 'Test Lojistik A',
    'plaka' => '34TST101-34TST201', 'sofor' => '—', 'tel' => '—', 'gumruk' => '—', 'ulke' => 'SIRBİSTAN'], json_encode($dv, JSON_UNESCAPED_UNICODE));
ok('kalem tablosu + toplam', $v['kalem_basliklar'] === ['Ürün', 'Kalibre', 'Palet/Koli'] && $v['kalemler'] === [['urun' => 'Pomegranate 14 PC LOSE (TR) · HICAZ · 3,5 KG', 'kalibre' => '14', 'palet_koli' => '23 / 3.680']]
    && $v['kalem_say'] === 1 && $v['toplam'] === ['etiket' => 'Toplam', 'palet_koli' => '23 / 3.680'], json_encode($v['kalemler'], JSON_UNESCAPED_UNICODE));
ok('notlar: şoför + gümrük kurulmadı, parti notu YOK; dosya adı / id', in_array('Şoför alanları kurulmadı.', $v['notlar'], true) && in_array('Yurt içi gümrük alanı kurulmadı.', $v['notlar'], true) && !in_array('Parti no için beyan okuma yetkisi gerekir.', $v['notlar'], true)
    && $v['dosya'] === 'WP-TC900101.10' && $v['id'] === $ID['gecmis'] && $v['ok'] === true);
$vb = siparis_wp_veri(siparis_wp_oku($db, $ID['bugun']), ['parti_yetki' => true]);
ok('bugun: kalemler sira sırasında (10, 12), sıfır palet+koli kalemi (14) ATLANDI, toplam sevkiyattan', $vb['kalemler'] === [['urun' => 'Testfruit 10 PC · TESTVAR · 3,5 KG', 'kalibre' => '10', 'palet_koli' => '4 / 550'], ['urun' => 'Testfruit 12 PC · TESTVAR · 3,5 KG', 'kalibre' => '12', 'palet_koli' => '3 / 450']]
    && $vb['toplam']['palet_koli'] === '7 / 1.000', json_encode($vb['kalemler'], JSON_UNESCAPED_UNICODE));
$esit = true;
foreach (['yarin', 'bugun', 'gecmis', 'eski', 'bos_tarih', 'kapali'] as $ad) {
    $wk = siparis_wp_oku($db, $ID[$ad]);
    $kq = $db->prepare('SELECT * FROM siparis_kalemler WHERE sevkiyat_id = ? ORDER BY sira, id'); $kq->execute([$ID[$ad]]);
    $esit = $esit && siparis_wp_veri($wk, ['parti_yetki' => true])['kalem_say'] === count(sipui_kalem_satirlari($kq->fetchAll(PDO::FETCH_ASSOC), 1000)['satirlar']);
}
ok('WP kalem sayısı = liste süzgeci (tüm tohum sevkiyatları)', $esit);
$vbt = siparis_wp_veri(siparis_wp_oku($db, $ID['bos_tarih']), ['parti_yetki' => true]);
$dbt = array_column($vbt['alanlar'], 'deger', 'k');
ok('bos_tarih: tarih/nakliyeci/plaka/parti/navlun "—" (planlı değerler basılmaz); kalem yok → tek "—" satırı, toplam "—"', $dbt['tarih'] === '—' && $dbt['nakliyeci'] === '—' && $dbt['plaka'] === '—' && $dbt['parti'] === '—' && $dbt['navlun'] === '—'
    && $vbt['kalemler'] === [['urun' => '—', 'kalibre' => '—', 'palet_koli' => '—']] && $vbt['kalem_say'] === 0 && $vbt['toplam']['palet_koli'] === '—' && !str_contains(json_encode($vbt, JSON_UNESCAPED_UNICODE), 'PLN'));
$trn = sev($db, ['order_ref' => 'TC900109.10', 'tur_no' => '9009009', 'yukleme_tarihi' => $yarin]);
$vtr = siparis_wp_veri(siparis_wp_oku($db, $trn), ['parti_yetki' => true]);
ok('parti yoksa Tur no\'ya DÜŞÜLMEZ', array_column($vtr['alanlar'], 'deger', 'k')['parti'] === '—' && !str_contains(json_encode($vtr, JSON_UNESCAPED_UNICODE), '9009009'));
ok('kapalı (mail_gonderildi) sevkiyat paylaşılabilir; silinmiş / olmayan / 0 / negatif id → null', is_array(siparis_wp_oku($db, $ID['kapali'])) && siparis_wp_oku($db, $ID['silinmis']) === null && siparis_wp_oku($db, 999999) === null && siparis_wp_oku($db, 0) === null && siparis_wp_oku($db, -3) === null);
rol($ADMIN, false, 1, ['DEPO1']);
ok('depo kapısı (DEPO1): DEPO2 sevkiyatı null, boş depolu + DEPO1 dizi', siparis_wp_oku($db, $ID['depo2']) === null && is_array(siparis_wp_oku($db, $ID['bos_tarih'])) && is_array(siparis_wp_oku($db, $ID['gecmis'])));
rol($ADMIN, false, 1, ['DEPO2']);
ok('depo kapısı (DEPO2): DEPO2 sevkiyatı dizi, DEPO1 sevkiyatı null', is_array(siparis_wp_oku($db, $ID['depo2'])) && siparis_wp_oku($db, $ID['gecmis']) === null);
rol(['siparis.read'], false);
$vy = siparis_wp_veri(siparis_wp_oku($db, $ID['gecmis']), ['parti_yetki' => function_exists('can_beyan') && can_beyan('read')]);
ok('beyan.read yok: parti "—" + not', array_column($vy['alanlar'], 'deger', 'k')['parti'] === '—' && in_array('Parti no için beyan okuma yetkisi gerekir.', $vy['notlar'], true));
rol($ADMIN, true);
ok('siparis_wp_palet_koli biçimleri', siparis_wp_palet_koli(3, 450) === '3 / 450' && siparis_wp_palet_koli(null, null) === '—' && siparis_wp_palet_koli('', '') === '—' && siparis_wp_palet_koli(3, null) === '3 / —'
    && siparis_wp_palet_koli(null, 40) === '— / 40' && siparis_wp_palet_koli(1234, 56789) === '1.234 / 56.789' && siparis_wp_palet_koli(0, 0) === '0 / 0');
ok('siparis_wp_dosya_adi: yalnız güvenli karakter, boş / noktalama → sevkiyat-<id>', preg_match('/^WP-[A-Za-z0-9._-]+$/', siparis_wp_dosya_adi('TC 9/01..10 ', 5)) === 1 && siparis_wp_dosya_adi('', 5) === 'WP-sevkiyat-5' && siparis_wp_dosya_adi('///', 7) === 'WP-sevkiyat-7');

echo "\n=== 7. Şoför kolonları kuruldu ===\n";
siparis_sofor_migrate($db);
$db->exec("UPDATE siparis_sevkiyatlar SET sofor_adi = 'Test Sürücü Bir', sofor_telefon = '+90 532 000 00 01' WHERE id = {$ID['gecmis']}");
$o2 = siparis_yazdir_oku($db, $F());
$v2 = siparis_yazdir_veri($o2, $ctx);
$g2 = null; foreach ($v2['satirlar'] as $s) if ($s['h'][3] === 'TC900101.10') $g2 = $s;
ok('şoför adı + telefonu dolu sütunlarda', $o2['sofor'] === true && $g2['h'][4] === 'Test Sürücü Bir' && $g2['h'][5] === '+90 532 000 00 01');
ok('şoför kurulu: "kurulmadı" notu yok', !in_array('Şoför alanları kurulmadı.', $v2['notlar'], true));

echo "\n=== 7b. Doğrudan istek koruması uçla çakışmaz (v385) ===\n";
// Uç kökte, yardımcı config/ altında AYNI ADI taşır. Eski basename koruması ucu 404 + boş gövdeyle bitiriyordu (canlıda
// "Oturum sona ermiş olabilir"). Harness SCRIPT_FILENAME'i ayarlamadığı için bu AYRI süreçte sınanır.
$__kos = function (string $script) use ($ROOT): string {
    $kod = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($script, true) . '; function h($s){return htmlspecialchars((string)$s);}'
         . ' require ' . var_export($ROOT . '/config/siparis_core.php', true) . '; require ' . var_export($ROOT . '/_siparis_ortak.php', true) . ';'
         . ' require ' . var_export($ROOT . '/config/siparis_yazdir.php', true) . '; echo "YUKLENDI";';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($kod) . ' 2>&1');
};
ok('uç (kök siparis_yazdir.php) çalışırken config/siparis_yazdir.php YÜKLENİR (404/exit yok)', str_contains($__kos($ROOT . '/siparis_yazdir.php'), 'YUKLENDI'));
ok('config/siparis_yazdir.php doğrudan istenirse yine durur', !str_contains($__kos($ROOT . '/config/siparis_yazdir.php'), 'YUKLENDI'));
$__kosWp = function (string $script) use ($ROOT): string {
    $kod = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($script, true) . '; function h($s){return htmlspecialchars((string)$s);}'
         . ' require ' . var_export($ROOT . '/config/siparis_core.php', true) . '; require ' . var_export($ROOT . '/_siparis_ortak.php', true) . ';'
         . ' require ' . var_export($ROOT . '/config/siparis_beyan.php', true) . '; require ' . var_export($ROOT . '/config/siparis_wp.php', true) . '; echo "YUKLENDI";';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($kod) . ' 2>&1');
};
ok('v394: uç (kök siparis_paylas.php) çalışırken config/siparis_wp.php YÜKLENİR; doğrudan istenirse durur', str_contains($__kosWp($ROOT . '/siparis_paylas.php'), 'YUKLENDI') && !str_contains($__kosWp($ROOT . '/config/siparis_wp.php'), 'YUKLENDI'));
$__cak = [];
foreach (array_merge(glob($ROOT . '/*.php') ?: [], glob($ROOT . '/config/*.php') ?: [], glob($ROOT . '/halkayit/*.php') ?: []) as $__f) {
    if (!str_contains((string)file_get_contents($__f), "basename((string)(\$_SERVER['SCRIPT_FILENAME']")) continue;
    foreach ([$ROOT, $ROOT . '/config', $ROOT . '/halkayit'] as $__d) if (realpath($__d . '/' . basename($__f)) !== false && realpath($__d . '/' . basename($__f)) !== realpath($__f)) $__cak[] = basename($__f);
}
ok('basename korumalı include-only dosyanın adı başka bir sayfayla çakışmıyor', $__cak === [], implode(', ', $__cak));

echo "\n=== 8. Uç (siparis_yazdir.php) ===\n";
rol(['siparis.read'], false, 5);   // izleyici
$r = uc('siparis_yazdir.php', ['amac' => 'pdf', 'q' => 'TC900101']);
ok('izleyici (yalnız siparis.read) açar: 200 + geçerli JSON + 13 sütun', $r['hata'] === null && $r['kod'] === 200 && $r['json'] !== null && ($r['json']['ok'] ?? false) === true && count($r['json']['kolonlar']) === 13, (string)$r['hata'] . substr($r['govde'], 0, 200));
ok('başlıklar: JSON utf-8 + nosniff (no-store: statik bölümde; CLI\'de headers_sent() sonrası siparis_no_store() atlanır)', str_contains(strtolower($r['h']['content-type'] ?? ''), 'application/json') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff');
ok('izleyici: şoför adı/telefonu siparis.read ile görünür (siparis_view ile aynı kural), parti no yok', $r['json']['satirlar'][0]['h'][4] === 'Test Sürücü Bir' && $r['json']['satirlar'][0]['h'][1] === '' && in_array('Parti no için beyan okuma yetkisi gerekir.', $r['json']['notlar'], true));
ok('filtre özeti: arama metni çıktıda', $r['json']['filtre'] === 'Arama: TC900101' && $r['json']['adet'] === 1);
$aud = $db->query("SELECT action, record_id, new_values FROM audit_log WHERE action = 'siparis_yazdir'")->fetchAll(PDO::FETCH_ASSOC);
ok('audit: 1 satır siparis_yazdir, yeni = adet/amac/kapsam/kesildi/arama', count($aud) === 1 && json_decode($aud[0]['new_values'], true) === ['adet' => 1, 'amac' => 'pdf', 'kapsam' => 'acik', 'kesildi' => 0, 'arama' => 1], json_encode($aud));
ok('audit: şoför adı / telefon / arama metni YAZILMAZ', !str_contains($aud[0]['new_values'], 'Sürücü') && !str_contains($aud[0]['new_values'], '532') && !str_contains($aud[0]['new_values'], 'TC900101'));
$r = uc('siparis_yazdir.php', ['amac' => 'saçma']);
ok('amac beyaz liste: bilinmeyen → yazdir', json_decode($db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_yazdir' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['amac'] === 'yazdir');
$r = uc('siparis_yazdir.php', [], 'POST');
ok('POST → 405 JSON (Allow: GET), audit yazılmadı', $r['kod'] === 405 && ($r['json']['ok'] ?? null) === false && ($r['h']['allow'] ?? '') === 'GET' && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_yazdir'")->fetchColumn() === 2);
$r = uc('siparis_yazdir.php', ['durum' => 'kapali']);
ok('durum=kapali → 400 + kapsam_yok', $r['kod'] === 400 && ($r['json']['kod'] ?? '') === 'kapsam_yok');
rol([], false, 7);
$r = uc('siparis_yazdir.php');
ok('yetkisiz (siparis.read yok): forbidden, JSON verisi dönmez', $r['hata'] !== null && str_contains($r['hata'], 'siparis.read') && $r['json'] === null);
rol(['beyan.read', 'records.write'], false, 7);
ok('köprü yok: beyan.read / records.write kapıyı açmaz', uc('siparis_yazdir.php')['hata'] !== null);
rol($ADMIN, false, 9, ['DEPO2']);
$r = uc('siparis_yazdir.php');
$refs = array_column(array_column($r['json']['satirlar'], 'h'), 3);
ok('uç depo izolasyonu: DEPO2 kullanıcısı yalnız DEPO2 + boş depolu satırları görür', in_array('TC900106.10', $refs, true) && !in_array('TC900101.10', $refs, true) && !in_array('TC900102.10', $refs, true) && in_array('TC900104.10', $refs, true));
rol($ADMIN, true);

echo "\n=== 9. Ham metin + limit ===\n";
$xs = sev($db, ['order_ref' => 'TC900108.10', 'yukleme_tarihi' => $yarin, 'nakliyeci' => '<b>X</b> & "Y"', 'plakalar' => "34TST<script>alert(1)</script>"]);
kalem($db, $xs, ['kalibre' => '10', 'aciklama' => '<script>alert(2)</script> 10 PC', 'po_palet' => 1]);
$r = uc('siparis_yazdir.php', ['q' => 'TC900108']);
ok('JSON hücreleri HAM kalır (kaçış istemcide): <script, <b>, & aynen (JSON çözülünce)', $r['json'] !== null && str_contains($r['json']['satirlar'][0]['h'][6], '<script>alert(1)</script>') && $r['json']['satirlar'][0]['h'][7] === '<b>X</b> & "Y"' && str_contains($r['json']['satirlar'][0]['h'][9], '<script>alert(2)</script>'));
ok('Content-Type JSON (HTML olarak yorumlanamaz) + JSON_INVALID_UTF8_SUBSTITUTE ile bozuk bayt uçtan taşmaz', str_contains(strtolower($r['h']['content-type'] ?? ''), 'application/json'));
$db->beginTransaction();
$ins = $db->prepare("INSERT INTO siparis_sevkiyatlar (tedarikci_no, order_ref, yukleme_tarihi, durum, depo) VALUES ('', ?, ?, 'hazirlik', 'DEPOL')");
for ($i = 0; $i < 501; $i++) $ins->execute(['TC9200' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.10', date('Y-m-d', strtotime('+' . (2 + $i % 5) . ' day'))]);
$db->commit();
rol($ADMIN, false, 1, ['DEPOL']);
$ol = siparis_yazdir_oku($db, $F());
$vl = siparis_yazdir_veri($ol, $ctx);
ok('limit: 501+ satırda 500 satır + kesildi + not', count($ol['rows']) === 500 && $ol['kesildi'] === true && $vl['adet'] === 500 && $vl['kesildi'] === true && in_array('İlk 500 sevkiyat basıldı — filtreyi daraltın.', $vl['notlar'], true));
ok('SIPARIS_YAZDIR_LIMIT = 500', SIPARIS_YAZDIR_LIMIT === 500);
rol($ADMIN, true);

echo "\n=== 11. WP Paylaş ucu (siparis_paylas.php, v394) ===\n";
siparis_yurtici_gumruk_migrate($db);
$db->exec("UPDATE siparis_sevkiyatlar SET yurtici_gumruk = 'Test Gümrük İdaresi' WHERE id = {$ID['gecmis']}");
$wpAud = fn() => $db->query("SELECT record_id, new_values FROM audit_log WHERE action = 'siparis_wp_paylas' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
rol(['siparis.read'], false, 5);   // izleyici
$r = uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']]);
$j = $r['json'] ?? []; $jd = array_column($j['alanlar'] ?? [], 'deger', 'k');
ok('izleyici: 200 + JSON + 10 alan; şoför, telefon, gümrük dolu; parti "—" + not; kurulum notları YOK', $r['hata'] === null && $r['kod'] === 200 && ($j['ok'] ?? false) === true && count($j['alanlar'] ?? []) === 10 && ($jd['sofor'] ?? '') === 'Test Sürücü Bir' && ($jd['tel'] ?? '') === '+90 532 000 00 01'
    && ($jd['gumruk'] ?? '') === 'Test Gümrük İdaresi' && ($jd['parti'] ?? '') === '—' && in_array('Parti no için beyan okuma yetkisi gerekir.', $j['notlar'] ?? [], true) && !in_array('Şoför alanları kurulmadı.', $j['notlar'] ?? [], true)
    && !in_array('Yurt içi gümrük alanı kurulmadı.', $j['notlar'] ?? [], true), (string)$r['hata'] . substr($r['govde'], 0, 300));
ok('başlıklar: JSON utf-8 + nosniff', str_contains(strtolower($r['h']['content-type'] ?? ''), 'application/json') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff');
$wa = $wpAud();
ok('audit: 1 satır, record_id = sevkiyat, yeni = {"kalem":1}; ad / telefon / plaka / firma / gümrük YOK', count($wa) === 1 && (int)$wa[0]['record_id'] === $ID['gecmis'] && json_decode($wa[0]['new_values'], true) === ['kalem' => 1]
    && !preg_match('/Sürücü|532|34TST|Lojistik|Gümrük/u', $wa[0]['new_values']), json_encode($wa));
rol($ADMIN, true);
$r = uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']]);
ok('yönetici (beyan.read): parti no dolu', (array_column($r['json']['alanlar'] ?? [], 'deger', 'k')['parti'] ?? '') === 'P-61/225 / P-61/226');
$n0 = count($wpAud());
$r = uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']], 'POST');
ok('POST → 405 JSON (Allow: GET), audit yazılmadı', $r['kod'] === 405 && ($r['json']['ok'] ?? null) === false && ($r['h']['allow'] ?? '') === 'GET' && count($wpAud()) === $n0);
$yok = uc('siparis_paylas.php', ['id' => '999999']);
$kotu = true;
foreach ([[], ['id' => '0'], ['id' => 'abc'], ['id' => '12abc'], ['id' => '999999'], ['id' => (string)$ID['silinmis']]] as $q) {
    $x = uc('siparis_paylas.php', $q);
    $kotu = $kotu && $x['kod'] === 404 && $x['json'] === ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
}
ok('id yok / 0 / abc / 12abc / olmayan / silinmiş → 404 + sabit gövde', $kotu && count($wpAud()) === $n0);
$r = uc('siparis_paylas.php', ['id' => (string)$ID['kapali']]);
ok('kapalı sevkiyat: 200 (audit +1)', $r['kod'] === 200 && ($r['json']['ok'] ?? false) === true && count($wpAud()) === $n0 + 1);
rol($ADMIN, false, 9, ['DEPO2']);
$g404 = uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']]);
ok('depo izolasyonu: başka depo = olmayan id ile BİREBİR aynı 404 gövdesi; DEPO2 + boş depolu 200', $g404['kod'] === 404 && $g404['govde'] === $yok['govde'] && uc('siparis_paylas.php', ['id' => (string)$ID['depo2']])['kod'] === 200
    && uc('siparis_paylas.php', ['id' => (string)$ID['bos_tarih']])['kod'] === 200);
rol($ADMIN, true);
rol([], false, 7);
$r = uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']]);
ok('yetkisiz (siparis.read yok): forbidden, JSON dönmez', $r['hata'] !== null && str_contains($r['hata'], 'siparis.read') && $r['json'] === null);
rol(['beyan.read', 'records.write'], false, 7);
ok('köprü yok: beyan.read / records.write kapıyı açmaz', uc('siparis_paylas.php', ['id' => (string)$ID['gecmis']])['hata'] !== null);
rol($ADMIN, true);
$r = uc('siparis_paylas.php', ['id' => (string)$xs]);
$dx = array_column($r['json']['alanlar'] ?? [], 'deger', 'k');
ok('ham metin: HTML / script aynen JSON\'da (kaçış istemcide), Content-Type JSON', ($dx['nakliyeci'] ?? '') === '<b>X</b> & "Y"' && str_contains($dx['plaka'] ?? '', '<script>alert(1)</script>') && str_contains(strtolower($r['h']['content-type'] ?? ''), 'application/json')
    && str_contains($r['json']['kalemler'][0]['urun'] ?? '', '<script>alert(2)</script>'));

echo "\n=== 10. Statik ===\n";
$u = (string)file_get_contents($ROOT . '/siparis_yazdir.php'); $cfg = (string)file_get_contents($ROOT . '/config/siparis_yazdir.php');
ok('uç: GET dışı 405, require_siparis(read), no-store, session_write_close, yazan SQL yok', str_contains($u, "require_siparis('read');") && str_contains($u, 'siparis_no_store();') && str_contains($u, 'session_write_close();') && str_contains($u, '405') && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $u));
ok('config: yalnız SELECT (INSERT/UPDATE/DELETE/ALTER/CREATE yok), çıktı/exit yok', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE)\s/', $cfg) && !preg_match('/\b(echo|print|exit|die)\b/', preg_replace('~//.*|/\*.*?\*/~s', '', $cfg)));
ok('uç + config e-posta literali yok', !preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $u . $cfg));
ok('şoför kolonları yalnız siparis_sofor_hazir() ile SELECT\'e girer', str_contains($cfg, 'siparis_sofor_hazir($pdo)') && substr_count($cfg, 's.sofor_adi') === 1);
ok('audit\'e kişisel veri / arama metni yazan kod yok (q yalnız `!== \'\'` karşılaştırması)', !preg_match('/siparis_audit\([^;]*(sofor|telefon)/i', $u) && !preg_match('/siparis_audit\([^;]*\$F\[.q.\](?!\s*!==)/', $u) && str_contains($u, "'arama' => \$F['q'] !== ''"));
$pu = (string)file_get_contents($ROOT . '/siparis_paylas.php'); $pc = (string)file_get_contents($ROOT . '/config/siparis_wp.php'); $kod = static function (string $src): string {
    $o = ''; foreach (token_get_all($src) as $t) { if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $o .= $t[1]; } else $o .= $t; } return $o;
};
$pcK = $kod($pc); $pcK2 = str_replace("if (realpath((string)(\$_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }", '', $pcK);
ok('v394 uç: require_siparis(read), no-store, session_write_close, 405, yazan SQL yok, csrf_check yok (yalnız GET)', str_contains($pu, "require_siparis('read');") && str_contains($pu, 'siparis_no_store();') && str_contains($pu, 'session_write_close();') && str_contains($pu, '405')
    && !preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $pu) && !str_contains($pu, 'csrf_check('));
ok('v394 uç audit: yalnız kalem sayısı; kişisel alan adı geçen siparis_audit argümanı yok', str_contains($pu, "siparis_audit('siparis_wp_paylas', \$sid, null, ['kalem' => ") && !preg_match('/siparis_audit\([^;]*(sofor|telefon|plaka|nakliyeci|gumruk)/i', $pu));
ok('v394 config: yalnız SELECT; çıktı/exit/die yok (doğrudan-istek koruma satırı hariç); planlanan_, tur no, beyan driver_*, s.gumruk YOK', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\s/', $pc) && !preg_match('/\b(echo|print|exit|die)\b/', $pcK2)
    && !str_contains($pcK, 'planlanan_') && !str_contains($pcK, 'tur_no') && !str_contains($pcK, 'driver_') && !preg_match('/\bs\.gumruk\b/', $pcK));
ok('v394 config: şoför + gümrük kolonları yalnız *_hazir() ile SELECT\'e girer; v385 tam yol koruması; e-posta literali yok', str_contains($pc, 'siparis_sofor_hazir($pdo)') && str_contains($pc, 'siparis_yurtici_gumruk_hazir($pdo)') && substr_count($pc, 's.sofor_adi') === 1
    && str_contains($pc, "realpath((string)(\$_SERVER['SCRIPT_FILENAME']") && !preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $pu . $pc));
$__ort = (string)file_get_contents($ROOT . '/_siparis_ortak.php');
ok('v394: kalem süzgeci TEK yerde ("(int)$pal === 0" yalnız _siparis_ortak.php); yazdırma + WP sipui_kalem_coz çağırır', substr_count($__ort . $cfg . $pc, '(int)$pal === 0') === 1 && substr_count($__ort, '(int)$pal === 0') === 1 && str_contains($cfg, 'sipui_kalem_coz(') && str_contains($pc, 'sipui_kalem_coz('));

siparis_test_bitir();
