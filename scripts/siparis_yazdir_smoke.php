<?php
// =========================================================
// scripts/siparis_yazdir_smoke.php — sevkiyat yazdırma (v382, v403: üç bölüm + kalibre sütunları): ortak filtre, sütun verisi, uç (GET JSON)
//   php scripts/siparis_yazdir_smoke.php                  testler
//   php scripts/siparis_yazdir_smoke.php --json           tarayıcı testi için küçük JSON fixture'ı (stdout; --bolum=evrak|kapali ile o bölüm)
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
$ID['kapali'] = sev($db, ['order_ref' => 'TC900105.10', 'yukleme_tarihi' => $dun, 'durum' => 'evrak_gonderildi', 'toplam_palet' => 99]);   // v400: kapanan = evrak takımı gönderildi
$ID['evrak'] = sev($db, ['order_ref' => 'TC900110.10', 'yukleme_tarihi' => $dun, 'durum' => 'mail_gonderildi', 'toplam_palet' => 55]);      // v400: PKL maili gitti, evrak takımı bekliyor
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
    siparis_yurtici_gumruk_migrate($db);
    $db->exec("UPDATE siparis_sevkiyatlar SET yurtici_gumruk = 'Test Gümrük İdaresi' WHERE id = {$ID['gecmis']}");
    // v403: üç kalibresi dolu satır (sahibin örneği: 14=10, 12=4, 10=4, toplam 18); 'bugun' satırında 14 eksik → "-"
    $uc3 = sev($db, ['order_ref' => 'TC900111.10', 'yukleme_tarihi' => $yarin, 'alici_kodu' => '1038500', 'alici_adi' => 'LIDL DE', 'plakalar' => '34TST111', 'toplam_palet' => 18, 'toplam_koli' => 3280, 'sofor_telefon' => '+90 532 000 00 11', 'yurtici_gumruk' => 'Test Gümrük İki']);
    kalem($db, $uc3, ['kalibre' => '14', 'sira' => 1, 'urun' => 'Nar', 'aciklama' => 'Pomegranate 14 PC', 'po_palet' => 10, 'etkin_palet' => 10, 'etkin_koli' => 1800]);
    kalem($db, $uc3, ['kalibre' => '12', 'sira' => 2, 'urun' => 'Nar', 'aciklama' => 'Pomegranate 12 PC', 'po_palet' => 4, 'etkin_palet' => 4, 'etkin_koli' => 740]);
    kalem($db, $uc3, ['kalibre' => '10', 'sira' => 3, 'urun' => 'NAR', 'aciklama' => 'Pomegranate 10 PC', 'po_palet' => 4, 'etkin_palet' => 4, 'etkin_koli' => 740]);
    if ($MOD === 'wp') {
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
    $fxBolum = 'acik';
    foreach ($argv ?? [] as $__a) if (preg_match('/^--bolum=(acik|evrak|kapali)$/', $__a, $__m)) $fxBolum = $__m[1];
    $o = siparis_yazdir_oku($db, $F(), $fxBolum);
    $v = siparis_yazdir_veri($o, ['bugun' => $bugun, 'basim' => date('d.m.Y H:i'), 'depo' => 'DEPO1', 'baslik' => siparis_yazdir_baslik($F(), $o['kapsam']), 'filtre_ozet' => '', 'parti_yetki' => true]);
    echo json_encode($v, JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo "=== 1. siparis_liste_filtre (siparisler.php'nin ESKİ WHERE/param dizgileriyle birebir) ===\n";
rol($ADMIN, true);
$a = $F([]);
ok('boş GET: WHERE yalnız silinmemiş, param yok, iki bölüm açık', $a['temelWhere'] === 's.deleted_at IS NULL' && $a['temelPar'] === [] && $a['bolumAcik'] && $a['bolumKapali'] && !$a['tek'] && !$a['filtreVar'] && !$a['detayFiltre'] && $a['detayOzet'] === []);
ok('v400: üç bölüm açık; acikSql / evrakSql / kapaliSql sabit', $a['bolumEvrak'] && $a['acikSql'] === "s.durum NOT IN ('evrak_gonderildi','iptal','mail_gonderildi')" && $a['evrakSql'] === "s.durum IN ('mail_gonderildi')" && $a['kapaliSql'] === "s.durum IN ('evrak_gonderildi','iptal')", $a['acikSql'] . ' | ' . $a['evrakSql'] . ' | ' . $a['kapaliSql']);
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
ok('durum=acik: yalnız açık bölüm, tek değil, filtreVar (detay değil)', $e['bolumAcik'] && !$e['bolumKapali'] && !$e['bolumEvrak'] && !$e['tek'] && $e['filtreVar'] && !$e['detayFiltre']);
ok('durum=kapali: yalnız kapanan bölüm', !$k['bolumAcik'] && !$k['bolumEvrak'] && $k['bolumKapali'] && !$k['tek']);
$ev = $F(['durum' => 'evrak_bekleyen']);
ok('v400 durum=evrak_bekleyen: yalnız evrak bölümü, tek değil, filtreVar, detay filtre değil, özet boş', $ev['bolumEvrak'] && !$ev['bolumAcik'] && !$ev['bolumKapali'] && !$ev['tek'] && $ev['filtreVar'] && !$ev['detayFiltre'] && $ev['detayOzet'] === [] && $ev['f_durum'] === 'evrak_bekleyen');
$mg = $F(['durum' => 'mail_gonderildi']);
ok('v400 durum=mail_gonderildi: tek durum (Sonuçlar), etiket özeti', $mg['tek'] && $mg['tekSql'] === "s.durum = 'mail_gonderildi'" && $mg['detayOzet'] === ['Mail gönderildi']);
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

echo "\n=== 3. Kalibre sütunları + ürün adları + süzgeç ortaklığı (v403) ===\n";
$kk = fn(array $harita) => array_map('strval', array_keys(siparis_yazdir_kalibreler($harita)));
ok('kalibre kümesi: kalem yokken de HER ZAMAN 14, 12, 10 (azalan)', $kk([]) === ['14', '12', '10'] && array_values(siparis_yazdir_kalibreler([])) === ['kal_14', 'kal_12', 'kal_10']);
ok('ekstra 16 + 8 gelince sayısal AZALAN sıra (16, 14, 12, 10, 8)', $kk([1 => [['kalibre' => '8', 'po_palet' => 1]], 2 => [['kalibre' => '16', 'po_palet' => 2]]]) === ['16', '14', '12', '10', '8']);
ok('sayısal olmayanlar sonda doğal sıra (A2 < A10); boş kalibre atlanır; süzgeçte düşen kalem kalibre eklemez', $kk([1 => [['kalibre' => 'A10', 'po_palet' => 1], ['kalibre' => 'A2', 'po_palet' => 1], ['kalibre' => '', 'po_palet' => 1], ['kalibre' => '18', 'po_palet' => 0, 'etkin_koli' => 0]]]) === ['14', '12', '10', 'A2', 'A10']);
ok('sayısal sıra metin sırası değil ("9","100")', $kk([1 => [['kalibre' => '9', 'po_palet' => 1], ['kalibre' => '100', 'po_palet' => 1]]]) === ['100', '14', '12', '10', '9']);
ok('anahtar güvenli: "4/5" → kal_45, "-" → kal_x…, çakışma _2 eki alır', siparis_yazdir_kalibre_anahtar('4/5') === 'kal_45' && preg_match('/^kal_x[0-9a-f]{8}$/', siparis_yazdir_kalibre_anahtar('-')) === 1
    && array_values(siparis_yazdir_kalibreler([1 => [['kalibre' => '1/4', 'po_palet' => 1], ['kalibre' => '14', 'po_palet' => 1]]])) === ['kal_14', 'kal_12', 'kal_10', 'kal_14_2']);
ok('kalibre paleti: aynı kalibrede iki kalem TOPLANIR, etkin_palet baskın, palet bilinmiyorsa (null) yok, sıfır kalem atlanır', siparis_yazdir_kalibre_palet([['kalibre' => '12', 'po_palet' => 3], ['kalibre' => '12', 'po_palet' => 9, 'etkin_palet' => 2], ['kalibre' => '14', 'aciklama' => 'X'], ['kalibre' => '10', 'po_palet' => 0, 'etkin_koli' => 0]]) === ['12' => 5.0]);
ok('ürün adları: önce urun, boşsa aciklama; büyük/küçük harf duyarsız TEKİL; ilk yazım korunur; ", " ile tek satır', siparis_yazdir_urunler([['urun' => 'Nar', 'aciklama' => 'P 14', 'po_palet' => 1], ['urun' => 'NAR', 'po_palet' => 1], ['urun' => '', 'aciklama' => 'Testfruit 10 PC', 'po_palet' => 1], ['urun' => 'nar', 'po_palet' => 2], ['urun' => 'Elma', 'po_palet' => 0, 'etkin_koli' => 0]]) === 'Nar, Testfruit 10 PC' && siparis_yazdir_urunler([]) === '');
$kolo = siparis_yazdir_kolonlari(siparis_yazdir_kalibreler([1 => [['kalibre' => '16', 'po_palet' => 1]]]));
ok('kolonlar: kalibreler Ürün ile Toplam palet ARASINDA, "Kalibre <X> adet", tür sayi; genişlik toplamı 100', array_column($kolo, 'k') === ['tarih', 'parti', 'siparis', 'ulke', 'tel', 'plaka', 'gumruk', 'urun', 'kal_16', 'kal_14', 'kal_12', 'kal_10', 'palet', 'kasa']
    && $kolo[8]['l'] === 'Kalibre 16 adet' && $kolo[8]['t'] === 'sayi' && abs(array_sum(array_column($kolo, 'w')) - 100) < 0.05 && $kolo[7]['w'] >= 10);
$kolC = siparis_yazdir_kolonlari(siparis_yazdir_kalibreler([1 => array_map(fn($i) => ['kalibre' => (string)(20 + $i), 'po_palet' => 1], range(0, 9))]));
ok('çok kalibrede (13) toplam yine 100, kalibre sütunu ≥ %2,5, Ürün kalibre sütunundan geniş', abs(array_sum(array_column($kolC, 'w')) - 100) < 0.05 && $kolC[8]['w'] >= 2.5 && $kolC[7]['w'] > $kolC[8]['w'] * 2, json_encode(array_column($kolC, 'w')));
// ⑭ aynı kalem dizisinde iki süzgeç aynı kalemleri atar
$kal = [];
foreach ([[0, 0, 0], [0, null, 0], [0, null, 5], [3, null, 0], [null, 0, 0], [null, null, null], ['0', '', ''], [0, 0, ''], [2, 0, 0], [0, 4, 0]] as $i => [$po, $et, $ek]) {
    $kal[] = ['kalibre' => (string)(10 + $i), 'aciklama' => 'K' . $i, 'po_palet' => $po, 'etkin_palet' => $et, 'etkin_koli' => $ek];
}
$ayni = true;
foreach ($kal as $k1) $ayni = $ayni && ((sipui_kalem_satirlari([$k1], 10)['satirlar'] !== []) === (siparis_yazdir_urunler([$k1]) !== ''));
ok('yazdırma ürün süzgeci = sipui_kalem_satirlari süzgeci (10 kombinasyon)', $ayni);
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
ok('kapsam açık; kapalı + silinmiş çıkmaz; depo kısıtsız admin DEPO2 satırını da görür', $o['kapsam'] === 'acik' && !$o['kesildi'] && !in_array($ID['kapali'], array_column($o['rows'], 'id')) && !in_array($ID['evrak'], array_column($o['rows'], 'id')) && !in_array($ID['silinmis'], array_column($o['rows'], 'id')) && in_array($ID['depo2'], array_column($o['rows'], 'id')));
ok('şoför kolonları kurulu değil: sofor=false, SQL hata vermedi', $o['sofor'] === false && !array_key_exists('sofor_adi', $o['rows'][0]));
$sira = array_map('intval', array_column($o['rows'], 'id'));
ok('sıra = ekran sırası: tarih artan, NULL tarih SONDA, aynı tarihte id', $sira === [$ID['eski'], $ID['gecmis'], $ID['depo2'], $ID['bugun'], $ID['yarin'], $ID['bos_tarih']] || $sira === [$ID['eski'], $ID['depo2'], $ID['gecmis'], $ID['bugun'], $ID['yarin'], $ID['bos_tarih']], json_encode($sira));
$ctx = ['bugun' => $bugun, 'basim' => '19.10.2026 14:32', 'depo' => 'DEPO1', 'baslik' => 'İşlem Bekleyen Sevkiyatlar', 'filtre_ozet' => '', 'parti_yetki' => true];
$v = siparis_yazdir_veri($o, $ctx);
$satir = []; foreach ($v['satirlar'] as $s) $satir[$s['h'][2]] = $s;
$gec = $satir['TC900101.10']; $bug = $satir['TC900102.10']; $yar = $satir['TC900103.10']; $bos = $satir['TC900104.10']; $esk = $satir['TC900100.10'];
ok('v403 sütunlar: sahibin sırası ve başlıkları (14/12/10 kalibre sütunları Ürün ile Toplam palet arasında)', array_column($v['kolonlar'], 'l') === ['Yükleme tarihi', 'Parti no', 'Sipariş no', 'Alıcı ülke', 'Şoför Tel', 'Plaka', 'Gümrük', 'Ürün bilgileri', 'Kalibre 14 adet', 'Kalibre 12 adet', 'Kalibre 10 adet', 'Toplam palet', 'Toplam kasa']
    && array_column($v['kolonlar'], 'k') === ['tarih', 'parti', 'siparis', 'ulke', 'tel', 'plaka', 'gumruk', 'urun', 'kal_14', 'kal_12', 'kal_10', 'palet', 'kasa'] && abs(array_sum(array_column($v['kolonlar'], 'w')) - 100) < 0.05
    && array_column($v['kolonlar'], 't') === ['metin', 'metin', 'metin', 'metin', 'metin', 'metin', 'metin', 'metin', 'sayi', 'sayi', 'sayi', 'sayi', 'sayi'], json_encode(array_column($v['kolonlar'], 'l'), JSON_UNESCAPED_UNICODE));
ok('v403: şoför ADI, navlun, lojistik firma, gramaj sütunları YOK', !array_intersect(['sofor', 'navlun', 'lojistik', 'gramaj'], array_column($v['kolonlar'], 'k')));
ok('her satırda kolon sayısı kadar hücre', count(array_unique(array_map(fn($s) => count($s['h']), $v['satirlar']))) === 1 && count($gec['h']) === count($v['kolonlar']));
ok('örnek satır: tarih+✓, parti, sipariş, ülke, tel (kurulmadı → boş), plaka, gümrük (kurulmadı → boş), ürün, kalibreler (yalnız 14), palet, kasa', $gec['h'] === [sipui_tarih($dun) . ' ✓', 'P-61/225/P-61/226', 'TC900101.10', 'SIRBİSTAN', '', '34TST101-34TST201', '', 'Pomegranate 14 PC LOSE (TR)', '23', '-', '-', '23', '3.680'], json_encode($gec['h'], JSON_UNESCAPED_UNICODE));
ok('birden çok beyan parti no "/" ile birleşir', $gec['h'][1] === 'P-61/225/P-61/226' && $bug['h'][1] === '');
ok('kalibre dışı boş hücre BOŞ (tire YOK); kalibre hücresi değer yoksa "-"', $gec['h'][4] === '' && $gec['h'][6] === '' && !in_array('—', $bos['h'], true) && array_slice($bos['h'], 0, 8) === ['', '', 'TC900104.10', 'FRANSA', '', '', '', ''] && array_slice($bos['h'], 8, 3) === ['-', '-', '-']);
ok('planlanan plaka / planlanan nakliyeci BASILMAZ', $bos['h'][5] === '' && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), 'SADECE PLAN') && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), 'PLAN LOJISTIK') && !str_contains(json_encode($v, JSON_UNESCAPED_UNICODE), '06PLN'));
ok('sarı: dün → gecmis + "✓" son eki; bugün → bugun (✓ yok); yarın / boş → düz', $gec['gecmis'] && str_ends_with($gec['h'][0], ' ✓') && !$gec['bugun'] && $esk['gecmis'] && $bug['bugun'] && !$bug['gecmis'] && $bug['h'][0] === sipui_tarih($bugun) && !$yar['gecmis'] && !$yar['bugun'] && $yar['h'][0] === sipui_tarih($yarin)
    && !$bos['gecmis'] && !$bos['bugun'] && $bos['h'][0] === '' && $bos['gun'] === null && $gec['gun'] === $dun);
ok('gecmis_say = geçmiş tarihli satır sayısı', $v['gecmis_say'] === count(array_filter($v['satirlar'], fn($s) => $s['gecmis'])) && $v['gecmis_say'] >= 3);
ok('ülke: kod → ad, kod yoksa alici_adi sonu, bilinmeyen boş', $bug['h'][3] === 'İNGİLTERE' && $yar['h'][3] === 'ALMANYA' && $esk['h'][3] === '' && $bos['h'][3] === 'FRANSA');
ok('ürün: adlar tekil, ", " ile TEK satır (sıfır palet kalemi 14 YOK)', $bug['h'][7] === 'Testfruit 10 PC, Testfruit 12 PC' && !str_contains($bug['h'][7], "\n") && $bos['h'][7] === '', json_encode($bug['h'][7], JSON_UNESCAPED_UNICODE));
ok('kalibre hücreleri: bugün 14 "-" (sıfır palet kalemi atlandı), 12 = 3, 10 = 4; yarın yalnız 12 = 5', array_slice($bug['h'], 8, 3) === ['-', '3', '4'] && array_slice($yar['h'], 8, 3) === ['-', '5', '-']);
ok('palet/kasa: NULL → boş, 0 → "0"', $bos['h'][11] === '' && $bos['h'][12] === '' && $esk['h'][11] === '2' && $esk['h'][12] === '0');
$tp = 0; $tk = 0; foreach ($o['rows'] as $r) { $tp += (int)$r['toplam_palet']; $tk += (int)$r['toplam_koli']; }
ok('toplam = Σ palet / kasa + her kal_* toplamı (değer yoksa boş)', $v['toplam'] === ['palet' => sipui_sayi($tp), 'kasa' => sipui_sayi($tk), 'kal_14' => '23', 'kal_12' => '8', 'kal_10' => '4'] && $tp === 2 + 23 + 88 + 7 + 5 && $v['adet'] === 6, json_encode($v['toplam']));
$ob = siparis_yazdir_oku($db, $F(['q' => 'TC900104']));
$vb = siparis_yazdir_veri($ob, $ctx);
ok('hiç palet/kasa/kalibre değeri yoksa toplam boş', $vb['adet'] === 1 && $vb['toplam'] === ['palet' => '', 'kasa' => '', 'kal_14' => '', 'kal_12' => '', 'kal_10' => '']);
ok('başlık / dosya / kapsam / meta / limit', $v['baslik'] === 'İşlem Bekleyen Sevkiyatlar' && $v['dosya'] === 'Islem-Bekleyen-Sevkiyatlar-19.10.2026' && $v['kapsam'] === 'acik' && $v['basim'] === '19.10.2026 14:32' && $v['depo'] === 'DEPO1' && $v['limit'] === 500 && $v['kesildi'] === false && $v['ok'] === true);
ok('notlar: şoför + gümrük kurulmadı, kişisel veri notu YOK', in_array('Şoför alanları kurulmadı.', $v['notlar'], true) && in_array('Gümrük alanı kurulmadı.', $v['notlar'], true) && !in_array('Kişisel veri içerir (şoför telefonu) — yalnız ilgili kişilerle paylaşın.', $v['notlar'], true) && !in_array('Parti no için beyan okuma yetkisi gerekir.', $v['notlar'], true));
ok('sevkiyatlar arası gün değişimi için gun alanı her satırda (yoksa null)', count(array_filter($v['satirlar'], fn($s) => array_key_exists('gun', $s))) === count($v['satirlar']));
// v403: iki kalemli kalibre toplamı + ekstra kalibre (16) sütunu satır verisinde
$vs = siparis_yazdir_veri(['kapsam' => 'acik', 'kesildi' => false, 'sofor' => true, 'gumruk' => true, 'parti' => [],
    'rows' => [['id' => 1, 'order_ref' => 'X1', 'yukleme_tarihi' => $yarin, 'plakalar' => '', 'toplam_palet' => 18, 'toplam_koli' => 3280, 'sofor_telefon' => ' +90 5 ', 'yurtici_gumruk' => ' G1 '],
               ['id' => 2, 'order_ref' => 'X2', 'yukleme_tarihi' => $yarin, 'plakalar' => '', 'toplam_palet' => 6, 'toplam_koli' => 100]],
    'kalem' => [1 => [['kalibre' => '14', 'urun' => 'Nar', 'po_palet' => 10], ['kalibre' => '12', 'urun' => 'nar', 'po_palet' => 1], ['kalibre' => '12', 'urun' => 'Nar', 'po_palet' => 3], ['kalibre' => '10', 'aciklama' => 'Elma', 'po_palet' => 4]],
                2 => [['kalibre' => '16', 'urun' => 'Nar', 'po_palet' => 6]]]], $ctx);
ok('aynı kalibrede iki kalem toplanır (12 = 1+3 = 4); sahibin örneği 14=10, 12=4, 10=4, toplam 18', $vs['satirlar'][0]['h'] === ['', '', 'X1', '', '+90 5', '', 'G1', 'Nar, Elma', '-', '10', '4', '4', '18', '3.280'] || $vs['satirlar'][0]['h'] === [sipui_tarih($yarin), '', 'X1', '', '+90 5', '', 'G1', 'Nar, Elma', '-', '10', '4', '4', '18', '3.280'], json_encode($vs['satirlar'][0]['h'], JSON_UNESCAPED_UNICODE));
ok('ekstra kalibre 16 ilk kalibre sütunu (azalan); 16 olmayan satırda "-"; toplam kal_16/14/12/10', array_column($vs['kolonlar'], 'k') === ['tarih', 'parti', 'siparis', 'ulke', 'tel', 'plaka', 'gumruk', 'urun', 'kal_16', 'kal_14', 'kal_12', 'kal_10', 'palet', 'kasa']
    && array_slice($vs['satirlar'][1]['h'], 8, 4) === ['6', '-', '-', '-'] && $vs['toplam'] === ['palet' => '24', 'kasa' => '3.380', 'kal_16' => '6', 'kal_14' => '10', 'kal_12' => '4', 'kal_10' => '4'] && !in_array('Gümrük alanı kurulmadı.', $vs['notlar'], true), json_encode($vs['toplam']));

echo "\n=== 5. Kapsam (v403: bolum = acik | evrak | kapali) ===\n";
$thr = false; try { siparis_yazdir_oku($db, $F(['durum' => 'kapali'])); } catch (RuntimeException $e) { $thr = $e->getMessage() === 'kapsam_yok'; }
ok('durum=kapali + bolum=acik (varsayılan) → kapsam_yok (açık bölüm görünmüyor)', $thr);
$thr = false; try { siparis_yazdir_oku($db, $F(['durum' => 'acik']), 'kapali'); } catch (RuntimeException $e) { $thr = $e->getMessage() === 'kapsam_yok'; }
ok('durum=acik + bolum=kapali → kapsam_yok', $thr);
$thr = false; try { siparis_yazdir_oku($db, $F(['durum' => 'evrak_bekleyen'])); } catch (RuntimeException $e) { $thr = $e->getMessage() === 'kapsam_yok'; }
ok('durum=evrak_bekleyen + bolum=acik → kapsam_yok', $thr);
$oe = siparis_yazdir_oku($db, $F(), 'evrak');
ok('bolum=evrak → yalnız evrak bekleyen (mail_gonderildi) satır, kapsam evrak', $oe['kapsam'] === 'evrak' && array_map('intval', array_column($oe['rows'], 'id')) === [$ID['evrak']]);
ok('durum=evrak_bekleyen + bolum=evrak → aynı küme', array_column(siparis_yazdir_oku($db, $F(['durum' => 'evrak_bekleyen']), 'evrak')['rows'], 'id') === array_column($oe['rows'], 'id'));
$ve = siparis_yazdir_veri($oe, array_merge($ctx, ['baslik' => siparis_yazdir_baslik($F(), 'evrak')]));
ok('evrak: geçmiş tarih olsa da gecmis=false, ✓ YOK; başlık / dosya', $ve['satirlar'][0]['gecmis'] === false && !str_contains($ve['satirlar'][0]['h'][0], '✓') && $ve['satirlar'][0]['h'][0] === sipui_tarih($dun) && $ve['gecmis_say'] === 0
    && $ve['baslik'] === 'Evrak Takımı Bekleyen Sevkiyatlar' && $ve['dosya'] === 'Evrak-Bekleyen-Sevkiyatlar-19.10.2026' && $ve['kapsam'] === 'evrak');
$kap2 = sev($db, ['order_ref' => 'TC900112.10', 'yukleme_tarihi' => $eski, 'durum' => 'iptal', 'toplam_palet' => 1]);
$kap3 = sev($db, ['order_ref' => 'TC900113.10', 'yukleme_tarihi' => $dun, 'durum' => 'evrak_gonderildi', 'toplam_palet' => 2]);
$ok3 = siparis_yazdir_oku($db, $F(), 'kapali');
ok('bolum=kapali → kapanan (evrak_gonderildi + iptal), sıra tarih AZALAN, aynı tarihte id AZALAN', $ok3['kapsam'] === 'kapali' && array_map('intval', array_column($ok3['rows'], 'id')) === [$kap3, $ID['kapali'], $kap2], json_encode(array_column($ok3['rows'], 'id')));
ok('durum=kapali + bolum=kapali → aynı küme', array_column(siparis_yazdir_oku($db, $F(['durum' => 'kapali']), 'kapali')['rows'], 'id') === array_column($ok3['rows'], 'id'));
$vk = siparis_yazdir_veri($ok3, array_merge($ctx, ['baslik' => siparis_yazdir_baslik($F(), 'kapali')]));
ok('kapali: gecmis=false, ✓ YOK; başlık / dosya', $vk['gecmis_say'] === 0 && !array_filter($vk['satirlar'], fn($s) => $s['gecmis'] || str_contains($s['h'][0], '✓')) && $vk['baslik'] === 'Kapanan Sevkiyatlar' && $vk['dosya'] === 'Kapanan-Sevkiyatlar-19.10.2026');
$db->exec("UPDATE siparis_sevkiyatlar SET deleted_at = '2026-10-01 10:00:00' WHERE id IN ($kap2, $kap3)");   // sonraki bölümlerin kümelerini bozmasın
ok('bilinmeyen bolum → acik', siparis_yazdir_oku($db, $F(), 'zzz')['kapsam'] === 'acik');
$om = siparis_yazdir_oku($db, $F(['durum' => 'mail_gonderildi']), 'kapali');
ok('tek durum filtresi varsa bolum YOK SAYILIR: durum=mail_gonderildi + bolum=kapali → tek kapsam; başlık "Sevkiyatlar — Mail gönderildi"', $om['kapsam'] === 'tek' && array_column($om['rows'], 'id') === [$ID['evrak']] && siparis_yazdir_baslik($F(['durum' => 'mail_gonderildi']), 'tek') === 'Sevkiyatlar — Mail gönderildi');
$vom = siparis_yazdir_veri($om, $ctx);
ok('tek kapsamda geçmiş tarih ✓ alır', $vom['satirlar'][0]['gecmis'] === true && str_ends_with($vom['satirlar'][0]['h'][0], ' ✓'));
$ot = siparis_yazdir_oku($db, $F(['durum' => 'yola_cikti']));
ok('durum=yola_cikti → tek kapsam, yalnız yola_cikti satırları', $ot['kapsam'] === 'tek' && count($ot['rows']) > 0 && !in_array($ID['eski'], array_column($ot['rows'], 'id')) && !in_array($ID['bos_tarih'], array_column($ot['rows'], 'id')));
$oa = siparis_yazdir_oku($db, $F(['durum' => 'arac_bekleyen']));
ok('durum=arac_bekleyen → siparis_alindi + hazirlik', $oa['kapsam'] === 'tek' && array_map('intval', array_column($oa['rows'], 'id')) === [$ID['eski'], $ID['bos_tarih']]);
$vt = siparis_yazdir_veri($ot, array_merge($ctx, ['baslik' => siparis_yazdir_baslik($F(['durum' => 'yola_cikti']))]));
ok('tek kapsam başlığı "Sevkiyatlar — <etiket>", dosya "Sevkiyatlar-"; bölüm başlıkları', $vt['baslik'] === 'Sevkiyatlar — ' . siparis_durumlar()['yola_cikti']['etiket'] && str_starts_with($vt['dosya'], 'Sevkiyatlar-') && $vt['kapsam'] === 'tek' && siparis_yazdir_baslik($F(['durum' => 'arac_bekleyen'])) === 'Sevkiyatlar — Araç bekleyen'
    && siparis_yazdir_baslik($F()) === 'İşlem Bekleyen Sevkiyatlar' && siparis_yazdir_baslik($F(), 'evrak') === 'Evrak Takımı Bekleyen Sevkiyatlar' && siparis_yazdir_baslik($F(), 'kapali') === 'Kapanan Sevkiyatlar');
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
    && array_column($v['alanlar'], 'etiket') === ['Parti No', 'Yükleme tarihi', 'Sipariş No', 'Navlun No', 'Nakliyeci', 'Plakalar', 'Şoför adı', 'Şoför telefonu', 'Gümrük', 'Alıcı Ülke']);
$dv = array_column($v['alanlar'], 'deger', 'k');
ok('değerler: parti, tarih (✓ YOK), sipariş, navlun, gerçek nakliyeci/plaka (planlı DEĞİL), şoför/tel/gümrük "—"', $dv === ['parti' => 'P-61/225 / P-61/226', 'tarih' => sipui_tarih($dun), 'siparis' => 'TC900101.10', 'navlun' => '6100000101', 'nakliyeci' => 'Test Lojistik A',
    'plaka' => '34TST101-34TST201', 'sofor' => '—', 'tel' => '—', 'gumruk' => '—', 'ulke' => 'SIRBİSTAN'], json_encode($dv, JSON_UNESCAPED_UNICODE));
ok('kalem tablosu + toplam', $v['kalem_basliklar'] === ['Ürün', 'Kalibre', 'PF', 'Palet/Koli'] && $v['kalemler'] === [['urun' => 'Pomegranate 14 PC LOSE (TR) · HICAZ · 3,5 KG', 'kalibre' => '14', 'pf' => '160', 'palet_koli' => '23 / 3.680']]
    && $v['kalem_say'] === 1 && $v['toplam'] === ['etiket' => 'Toplam', 'palet_koli' => '23 / 3.680'], json_encode($v['kalemler'], JSON_UNESCAPED_UNICODE));
ok('notlar: şoför + gümrük kurulmadı, parti notu YOK; dosya adı / id', in_array('Şoför alanları kurulmadı.', $v['notlar'], true) && in_array('Yurt içi gümrük alanı kurulmadı.', $v['notlar'], true) && !in_array('Parti no için beyan okuma yetkisi gerekir.', $v['notlar'], true)
    && $v['dosya'] === 'WP-TC900101.10' && $v['id'] === $ID['gecmis'] && $v['ok'] === true);
$vb = siparis_wp_veri(siparis_wp_oku($db, $ID['bugun']), ['parti_yetki' => true]);
ok('bugun: kalemler sira sırasında (10, 12), sıfır palet+koli kalemi (14) ATLANDI, toplam sevkiyattan', $vb['kalemler'] === [['urun' => 'Testfruit 10 PC · TESTVAR · 3,5 KG', 'kalibre' => '10', 'pf' => '100', 'palet_koli' => '4 / 550'], ['urun' => 'Testfruit 12 PC · TESTVAR · 3,5 KG', 'kalibre' => '12', 'pf' => '100', 'palet_koli' => '3 / 450']]
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
    && $vbt['kalemler'] === [['urun' => '—', 'kalibre' => '—', 'pf' => '—', 'palet_koli' => '—']] && $vbt['kalem_say'] === 0 && $vbt['toplam']['palet_koli'] === '—' && !str_contains(json_encode($vbt, JSON_UNESCAPED_UNICODE), 'PLN'));
$trn = sev($db, ['order_ref' => 'TC900109.10', 'tur_no' => '9009009', 'yukleme_tarihi' => $yarin]);
$vtr = siparis_wp_veri(siparis_wp_oku($db, $trn), ['parti_yetki' => true]);
ok('parti yoksa Tur no\'ya DÜŞÜLMEZ', array_column($vtr['alanlar'], 'deger', 'k')['parti'] === '—' && !str_contains(json_encode($vtr, JSON_UNESCAPED_UNICODE), '9009009'));
ok('kapalı (evrak_gonderildi) + evrak bekleyen (mail_gonderildi) sevkiyat paylaşılabilir; silinmiş / olmayan / 0 / negatif id → null', is_array(siparis_wp_oku($db, $ID['kapali'])) && is_array(siparis_wp_oku($db, $ID['evrak'])) && siparis_wp_oku($db, $ID['silinmis']) === null && siparis_wp_oku($db, 999999) === null && siparis_wp_oku($db, 0) === null && siparis_wp_oku($db, -3) === null);
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
$g2 = null; foreach ($v2['satirlar'] as $s) if ($s['h'][2] === 'TC900101.10') $g2 = $s;
ok('şoför telefonu dolu sütunda; şoför ADI çıktıda YOK (v403)', $o2['sofor'] === true && $g2['h'][4] === '+90 532 000 00 01' && !str_contains(json_encode($v2, JSON_UNESCAPED_UNICODE), 'Test Sürücü Bir') && !array_key_exists('sofor_adi', $o2['rows'][0]));
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
ok('izleyici (yalnız siparis.read) açar: 200 + geçerli JSON + 13 sütun (3 kalibre) + kapsam acik', $r['hata'] === null && $r['kod'] === 200 && $r['json'] !== null && ($r['json']['ok'] ?? false) === true && count($r['json']['kolonlar']) === 13 && ($r['json']['kapsam'] ?? '') === 'acik' && $r['json']['baslik'] === 'İşlem Bekleyen Sevkiyatlar', (string)$r['hata'] . substr($r['govde'], 0, 200));
ok('başlıklar: JSON utf-8 + nosniff (no-store: statik bölümde; CLI\'de headers_sent() sonrası siparis_no_store() atlanır)', str_contains(strtolower($r['h']['content-type'] ?? ''), 'application/json') && ($r['h']['x-content-type-options'] ?? '') === 'nosniff');
ok('izleyici: şoför telefonu siparis.read ile görünür (siparis_view ile aynı kural), şoför adı YOK, parti no yok', $r['json']['satirlar'][0]['h'][4] === '+90 532 000 00 01' && !str_contains($r['govde'], 'Sürücü') && $r['json']['satirlar'][0]['h'][1] === '' && in_array('Parti no için beyan okuma yetkisi gerekir.', $r['json']['notlar'], true));
ok('filtre özeti: arama metni çıktıda', $r['json']['filtre'] === 'Arama: TC900101' && $r['json']['adet'] === 1);
$aud = $db->query("SELECT action, record_id, new_values FROM audit_log WHERE action = 'siparis_yazdir'")->fetchAll(PDO::FETCH_ASSOC);
ok('audit: 1 satır siparis_yazdir, yeni = adet/amac/kapsam/kesildi/arama', count($aud) === 1 && json_decode($aud[0]['new_values'], true) === ['adet' => 1, 'amac' => 'pdf', 'kapsam' => 'acik', 'kesildi' => 0, 'arama' => 1], json_encode($aud));
ok('audit: şoför adı / telefon / arama metni YAZILMAZ', !str_contains($aud[0]['new_values'], 'Sürücü') && !str_contains($aud[0]['new_values'], '532') && !str_contains($aud[0]['new_values'], 'TC900101'));
$r = uc('siparis_yazdir.php', ['amac' => 'saçma']);
ok('amac beyaz liste: bilinmeyen → yazdir', json_decode($db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_yazdir' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['amac'] === 'yazdir');
$r = uc('siparis_yazdir.php', [], 'POST');
ok('POST → 405 JSON (Allow: GET), audit yazılmadı', $r['kod'] === 405 && ($r['json']['ok'] ?? null) === false && ($r['h']['allow'] ?? '') === 'GET' && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_yazdir'")->fetchColumn() === 2);
$n1 = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_yazdir'")->fetchColumn();
$r = uc('siparis_yazdir.php', ['durum' => 'kapali']);
ok('durum=kapali (bolum yok = acik) → 400 + kapsam_yok + sabit mesaj', $r['kod'] === 400 && $r['json'] === ['ok' => false, 'kod' => 'kapsam_yok', 'hata' => 'Bu bölüm bu filtreyle yazdırılamaz.']);
$r = uc('siparis_yazdir.php', ['durum' => 'acik', 'bolum' => 'kapali']);
ok('v403 bolum=kapali + durum=acik → 400 + kapsam_yok', $r['kod'] === 400 && ($r['json']['kod'] ?? '') === 'kapsam_yok');
$r = uc('siparis_yazdir.php', ['durum' => 'evrak_bekleyen']);
ok('durum=evrak_bekleyen (bolum=acik) → 400 + kapsam_yok; 400\'lerde audit yazılmadı', $r['kod'] === 400 && ($r['json']['kod'] ?? '') === 'kapsam_yok' && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_yazdir'")->fetchColumn() === $n1);
$r = uc('siparis_yazdir.php', ['durum' => 'evrak_bekleyen', 'bolum' => 'evrak', 'amac' => 'resim']);
$sonAud = fn() => json_decode((string)$db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_yazdir' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
ok('v403 bolum=evrak → 200, kapsam evrak, yalnız TC900110; amac=resim kabul (audit amac resim, kapsam evrak)', $r['kod'] === 200 && ($r['json']['kapsam'] ?? '') === 'evrak' && array_column(array_column($r['json']['satirlar'], 'h'), 2) === ['TC900110.10'] && $r['json']['baslik'] === 'Evrak Takımı Bekleyen Sevkiyatlar'
    && $sonAud() === ['adet' => 1, 'amac' => 'resim', 'kapsam' => 'evrak', 'kesildi' => 0, 'arama' => 0]);
$r = uc('siparis_yazdir.php', ['bolum' => 'kapali']);
ok('v403 bolum=kapali → 200, kapsam kapali, TC900105 var, açık satır yok, ✓ yok', $r['kod'] === 200 && ($r['json']['kapsam'] ?? '') === 'kapali' && in_array('TC900105.10', array_column(array_column($r['json']['satirlar'], 'h'), 2), true) && !in_array('TC900101.10', array_column(array_column($r['json']['satirlar'], 'h'), 2), true)
    && !str_contains($r['govde'], '✓') && $sonAud()['kapsam'] === 'kapali');
$r = uc('siparis_yazdir.php', ['durum' => 'mail_gonderildi', 'bolum' => 'kapali']);
ok('v403 tek durum + bolum=kapali → bolum yok sayılır, kapsam tek', $r['kod'] === 200 && ($r['json']['kapsam'] ?? '') === 'tek' && $sonAud()['kapsam'] === 'tek');
rol([], false, 7);
$r = uc('siparis_yazdir.php');
ok('yetkisiz (siparis.read yok): forbidden, JSON verisi dönmez', $r['hata'] !== null && str_contains($r['hata'], 'siparis.read') && $r['json'] === null);
rol(['beyan.read', 'records.write'], false, 7);
ok('köprü yok: beyan.read / records.write kapıyı açmaz', uc('siparis_yazdir.php')['hata'] !== null);
rol($ADMIN, false, 9, ['DEPO2']);
$r = uc('siparis_yazdir.php');
$refs = array_column(array_column($r['json']['satirlar'], 'h'), 2);
ok('uç depo izolasyonu: DEPO2 kullanıcısı yalnız DEPO2 + boş depolu satırları görür', in_array('TC900106.10', $refs, true) && !in_array('TC900101.10', $refs, true) && !in_array('TC900102.10', $refs, true) && in_array('TC900104.10', $refs, true));
rol($ADMIN, true);

echo "\n=== 9. Ham metin + limit ===\n";
$xs = sev($db, ['order_ref' => 'TC900108.10', 'yukleme_tarihi' => $yarin, 'nakliyeci' => '<b>X</b> & "Y"', 'plakalar' => "34TST<script>alert(1)</script>"]);
kalem($db, $xs, ['kalibre' => '10', 'aciklama' => '<script>alert(2)</script> 10 PC', 'po_palet' => 1]);
$r = uc('siparis_yazdir.php', ['q' => 'TC900108']);
ok('JSON hücreleri HAM kalır (kaçış istemcide): <script aynen (JSON çözülünce)', $r['json'] !== null && str_contains($r['json']['satirlar'][0]['h'][5], '<script>alert(1)</script>') && str_contains($r['json']['satirlar'][0]['h'][7], '<script>alert(2)</script>'));
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
rol($ADMIN, true);
$og = siparis_yazdir_oku($db, $F(['q' => 'TC900101']));
$vg = siparis_yazdir_veri($og, $ctx);
ok('v403 yazdırma: yurt içi gümrük kurulunca Gümrük sütunu dolu, "kurulmadı" notu yok', $og['gumruk'] === true && $vg['satirlar'][0]['h'][6] === 'Test Gümrük İdaresi' && !in_array('Gümrük alanı kurulmadı.', $vg['notlar'], true));
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
ok('şoför telefonu + yurt içi gümrük yalnız *_hazir() ile SELECT\'e girer; şoför adı / navlun / nakliyeci / PO gümrüğü SELECT\'te YOK (v403)', str_contains($cfg, 'siparis_sofor_hazir($pdo)') && str_contains($cfg, 'siparis_yurtici_gumruk_hazir($pdo)') && substr_count($cfg, 's.sofor_telefon') === 1 && substr_count($cfg, 's.yurtici_gumruk') === 1
    && !str_contains($cfg, 's.sofor_adi') && !str_contains($cfg, 's.navlun_no') && !str_contains($cfg, 's.nakliyeci') && !preg_match('/\bs\.gumruk\b/', $cfg) && !str_contains($cfg, 'planlanan_'));
ok('v403 uç: bolum beyaz listesi siparis_yazdir_bolumler(), amac beyaz listesi yazdir|pdf|resim, PDOException RuntimeException\'dan ÖNCE yakalanır', str_contains($u, 'siparis_yazdir_bolumler()[$bolum]') && str_contains($u, "['yazdir', 'pdf', 'resim']") && strpos($u, 'catch (PDOException') < strpos($u, 'catch (RuntimeException'));
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
