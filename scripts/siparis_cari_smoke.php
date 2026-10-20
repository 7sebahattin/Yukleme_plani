<?php
// =========================================================
// scripts/siparis_cari_smoke.php — "Sipariş Cari Dökümü" (v397): saf hesaplar, veri okuma/sınıflama, sayfa, kapı, audit
//   php scripts/siparis_cari_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri (34TST…, TC8000…; kişisel veri YOK). Canlı DB'ye HİÇ dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/print_helpers.php';
require_once $ROOT . '/config/siparis_cari.php';

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
    $tmp = sys_get_temp_dir() . '/sip_cari_' . getmypid() . '_' . basename($dosya);
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
$dun = date('Y-m-d', strtotime('-1 day')); $bugun = date('Y-m-d'); $yarin = date('Y-m-d', strtotime('+1 day')); $cokEski = date('Y-m-d', strtotime('-200 day'));
$ADMIN = ['siparis.read', 'reports.export', 'beyan.read'];

// ── tohum (sentetik) ──
$ID = [];
$ID['s1'] = sev($db, ['order_ref' => 'TC800001.10', 'yukleme_tarihi' => $dun, 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'plakalar' => '34TST001', 'nakliyeci' => 'Test Lojistik A']);
kalem($db, $ID['s1'], ['kalibre' => '14', 'sira' => 1, 'aciklama' => 'Testfruit 14 PC', 'koli_icerik' => '3,5 KG', 'po_palet' => 23, 'etkin_palet' => 23, 'etkin_koli' => 3680, 'pc_koli' => 3680, 'fiyat' => 4.11, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR', 'fiyat_tarihi' => $dun]);
kalem($db, $ID['s1'], ['kalibre' => '10', 'sira' => 2, 'aciklama' => 'Testfruit 10 PC', 'koli_icerik' => '3.5kg', 'po_palet' => 1, 'etkin_palet' => 1, 'etkin_koli' => 170, 'pc_koli' => 160, 'fiyat' => 4.5, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR', 'fiyat_tarihi' => $bugun]);
$ID['s2'] = sev($db, ['order_ref' => 'TC800002.10', 'durum' => 'arac_atandi', 'yukleme_tarihi' => $yarin, 'alici_kodu' => '1038512', 'alici_adi' => 'LIDL GB']);
kalem($db, $ID['s2'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'po_palet' => 1, 'etkin_palet' => 1, 'etkin_koli' => 100]);
$ID['s3'] = sev($db, ['order_ref' => 'TC800003.10', 'durum' => 'hazirlik', 'yukleme_tarihi' => $dun, 'plakalar' => '34TST003']);
kalem($db, $ID['s3'], ['kalibre' => '16', 'aciklama' => 'Testfruit 16 PC', 'koli_icerik' => '4 KG', 'po_palet' => 1, 'etkin_palet' => 1, 'etkin_koli' => 200]);
$ID['s4'] = sev($db, ['order_ref' => 'TC800004.10', 'durum' => 'siparis_alindi', 'var_pkl' => 1, 'yukleme_tarihi' => $yarin]);
kalem($db, $ID['s4'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'koli_icerik' => '3,5 KG', 'po_palet' => 1, 'etkin_palet' => 1, 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 1.2, 'para_birimi' => 'USD', 'fiyat_birim' => 'KG']);
$ID['iptal'] = sev($db, ['order_ref' => 'TC800005.10', 'durum' => 'iptal', 'yukleme_tarihi' => $dun]);
kalem($db, $ID['iptal'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'po_palet' => 1, 'etkin_koli' => 50, 'fiyat' => 3.0, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR']);
$ID['silinmis'] = sev($db, ['order_ref' => 'TC800006.10', 'yukleme_tarihi' => $dun, 'deleted_at' => '2026-10-01 10:00:00']);
kalem($db, $ID['silinmis'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'po_palet' => 1, 'etkin_koli' => 50, 'fiyat' => 3.0, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR']);
$ID['depo2'] = sev($db, ['order_ref' => 'TC800007.10', 'yukleme_tarihi' => $dun, 'depo' => 'DEPO2']);
kalem($db, $ID['depo2'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'po_palet' => 1, 'etkin_koli' => 50, 'fiyat' => 3.0, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR']);
$ID['tarihsiz'] = sev($db, ['order_ref' => 'TC800008.10', 'durum' => 'siparis_alindi', 'depo' => '']);
kalem($db, $ID['tarihsiz'], ['kalibre' => '12', 'po_palet' => 2]);
$ID['eski'] = sev($db, ['order_ref' => 'TC800009.10', 'yukleme_tarihi' => $cokEski]);
kalem($db, $ID['eski'], ['kalibre' => '12', 'po_palet' => 1, 'etkin_koli' => 10]);
$ID['xss'] = sev($db, ['order_ref' => '<script>alert(1)</script>', 'durum' => 'hazirlik', 'yukleme_tarihi' => $yarin, 'nakliyeci' => '<b>X</b>']);
kalem($db, $ID['xss'], ['kalibre' => '12', 'aciklama' => '<img src=x onerror=alert(2)>', 'po_palet' => 1, 'etkin_koli' => 5]);
$ID['s11'] = sev($db, ['order_ref' => 'TC800010.10', 'durum' => 'hazirlik', 'yukleme_tarihi' => $yarin]);
kalem($db, $ID['s11'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'koli_icerik' => '5 KG', 'po_palet' => 1, 'etkin_palet' => 1, 'etkin_koli' => 10, 'fiyat' => 2.0, 'para_birimi' => 'EUR', 'fiyat_birim' => 'CAR']);

$refs = static fn(array $liste): array => array_map(static fn(array $s): string => $s['order_ref'], $liste);
$bul = static function (array $liste, string $ref): ?array { foreach ($liste as $s) if ($s['order_ref'] === $ref) return $s; return null; };

echo "=== 1. Saf yardımcılar ===\n";
foreach ([['3,5 KG', 3.5], ['3.5kg', 3.5], [' 10 KG', 10.0], ['2,25 kg', 2.25], ['99,9', 99.9], [null, null], ['', null], ['KG', null], ['0 KG', null], ['150 KG', null]] as [$g, $b]) {
    ok('koli_kg(' . var_export($g, true) . ')', siparis_cari_koli_kg($g) === $b);
}
$B = '2026-10-20';
ok('yuklendi_mi: yola_cikti / pkl_hazir / mail_gonderildi', siparis_cari_yuklendi_mi(['durum' => 'yola_cikti'], $B) && siparis_cari_yuklendi_mi(['durum' => 'pkl_hazir'], $B) && siparis_cari_yuklendi_mi(['durum' => 'mail_gonderildi'], $B));
ok('yuklendi_mi: iptal hiçbir koşulda yüklenmiş değil', !siparis_cari_yuklendi_mi(['durum' => 'iptal', 'var_pkl' => 1, 'yukleme_tarihi' => '2026-10-19'], $B));
ok('yuklendi_mi: tarih dünden önce → evet; bugün/yarın → hayır', siparis_cari_yuklendi_mi(['durum' => 'hazirlik', 'yukleme_tarihi' => '2026-10-19'], $B)
    && !siparis_cari_yuklendi_mi(['durum' => 'hazirlik', 'yukleme_tarihi' => '2026-10-20'], $B) && !siparis_cari_yuklendi_mi(['durum' => 'arac_atandi', 'yukleme_tarihi' => '2026-10-21'], $B));
ok('yuklendi_mi: var_pkl \'1\' → evet; tarihsiz → hayır', siparis_cari_yuklendi_mi(['durum' => 'siparis_alindi', 'var_pkl' => '1'], $B) && !siparis_cari_yuklendi_mi(['durum' => 'siparis_alindi'], $B));
ok('satir_tutar: koli bazlı (3680 × 4,11 / 3,5 kg)', siparis_cari_satir_tutar(3680, 4.11, 3500, 'CAR') === ['baz' => 'koli', 'fiyat_koli' => 41100, 'fiyat_kg' => 11743, 'tutar' => 1512480]);
ok('satir_tutar: birim null / car / BOX / KOLI → koli bazlı', array_reduce([null, 'car', 'BOX', 'KOLI'], static fn(bool $a, ?string $b): bool => $a && siparis_cari_satir_tutar(10, 1.0, 3500, $b)['baz'] === 'koli', true));
ok('satir_tutar: KG bazlı (100 koli × 3,5 kg × 1,2)', siparis_cari_satir_tutar(100, 1.2, 3500, 'KG') === ['baz' => 'kg', 'fiyat_koli' => 42000, 'fiyat_kg' => 12000, 'tutar' => 42000] && siparis_cari_satir_tutar(100, 1.2, 3500, 'kg')['baz'] === 'kg');
$t3 = 0; for ($i = 0; $i < 3; $i++) $t3 += siparis_cari_satir_tutar(1, 0.005, 3500, 'CAR')['tutar'];
ok('yuvarlama: satır başına yarım yukarı, toplam yuvarlanmış satırların toplamı (3 × 0,005 → 3)', $t3 === 3 && siparis_cari_satir_tutar(1, 0.0049, 3500, 'CAR')['tutar'] === 0);
ok('kalem_koli: pc > 0 öncelikli; pc 0 / null → etkin', siparis_cari_kalem_koli(['pc_koli' => 50, 'etkin_koli' => 60]) === 50 && siparis_cari_kalem_koli(['pc_koli' => 0, 'etkin_koli' => 60]) === 60
    && siparis_cari_kalem_koli(['pc_koli' => null, 'etkin_koli' => null]) === 0 && siparis_cari_kalem_koli(['pc_koli' => '', 'etkin_koli' => '7']) === 7);
ok('para biçimi', siparis_cari_para(1584480) === '15.844,80' && siparis_cari_para(5) === '0,05' && siparis_cari_para(0) === '0,00' && siparis_cari_para(-123456) === '-1.234,56');
ok('fiyat4 biçimi', siparis_cari_fiyat4(41100) === '4,1100' && siparis_cari_fiyat4(11743) === '1,1743' && siparis_cari_fiyat4(123456789) === '12.345,6789');
ok('kg biçimi (tam sayı) + koli kg metni', siparis_cari_kg(12880000) === '12.880' && siparis_cari_kg(1500) === '2' && siparis_cari_kg_metin(3.5) === '3,5' && siparis_cari_kg_metin(10.0) === '10' && siparis_cari_kg_metin(2.25) === '2,25');
ok('pb: büyük harf 3 harf, aksi \'?\'', siparis_cari_pb('eur ') === 'EUR' && siparis_cari_pb('') === '?' && siparis_cari_pb('EURO') === '?' && siparis_cari_pb(null) === '?');
$kal = static fn(array $kk): array => array_map(static fn(array $k): string => $k['kalibre'], siparis_cari_kalem_sirala(array_map(static fn(string $x): array => ['kalibre' => $x], $kk)));
ok('kalem sırası: sayısal artan, sayısal değilse doğal', $kal(['14', '10', '100', '9']) === ['9', '10', '14', '100'] && $kal(['A10', 'A2']) === ['A2', 'A10']);
$fa = siparis_cari_filtre([], '2026-10-20');
ok('filtre: varsayılan bas = 2 ay önceki ayın 1\'i, bit/alici boş', $fa['bas'] === '2026-08-01' && $fa['bit'] === '' && $fa['alici'] === '');
ok('filtre: varsayılan bas ay sonu/yıl geçişinde', siparis_cari_filtre([], '2026-03-31')['bas'] === '2026-01-01' && siparis_cari_filtre([], '2026-01-15')['bas'] === '2025-11-01');
ok('filtre: bas boş → sınırsız; geçersiz → varsayılan', siparis_cari_filtre(['bas' => ''], '2026-10-20')['bas'] === '' && siparis_cari_filtre(['bas' => 'xx'], '2026-10-20')['bas'] === '2026-08-01');
$fs = siparis_cari_filtre(['bas' => '2026-10-31', 'bit' => '2026-10-01'], '2026-10-20');
ok('filtre: bas > bit yer değiştirir', $fs['bas'] === '2026-10-01' && $fs['bit'] === '2026-10-31');
ok('filtre: bit gg.aa.yyyy kabul', siparis_cari_filtre(['bit' => '31.10.2026'], '2026-10-20')['bit'] === '2026-10-31');
ok('filtre: alıcı beyaz liste (enjeksiyon / dizi → boş)', siparis_cari_filtre(['alici' => "1038529' OR 1=1"], '2026-10-20')['alici'] === '' && siparis_cari_filtre(['alici' => ['x']], '2026-10-20')['alici'] === '' && siparis_cari_filtre(['alici' => '1038529'], '2026-10-20')['alici'] === '1038529');
ok('filtre: özet alıcı adını yazar', in_array('Alıcı: SIRBİSTAN (1038529)', siparis_cari_filtre(['alici' => '1038529'], '2026-10-20')['ozet'], true));
ok('tahsilat kancası: şimdilik []', siparis_cari_tahsilat_toplam($db, []) === []);

echo "\n=== 2. Veri: okuma + sınıflama + hesap ===\n";
rol($ADMIN, false, 1, ['DEPO1']);
$v = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre([], $bugun)), ['bugun' => $bugun, 'tahsilat' => []]);
ok('yüklenmemiş listesi (tarih, tarihsiz sonda)', $refs($v['yuklenmemis']) === ['TC800002.10', '<script>alert(1)</script>', 'TC800010.10', 'TC800008.10'], json_encode($refs($v['yuklenmemis'])));
ok('yüklenen listesi', $refs($v['yuklenen']) === ['TC800001.10', 'TC800003.10', 'TC800004.10'], json_encode($refs($v['yuklenen'])));
ok('fiyatlı listesi (yüklenmemiş fiyatlı dahil)', $refs($v['fiyatli']) === ['TC800001.10', 'TC800004.10', 'TC800010.10'], json_encode($refs($v['fiyatli'])));
$tum = array_merge($refs($v['yuklenmemis']), $refs($v['yuklenen']), $refs($v['fiyatli']));
ok('iptal / silinmiş / başka depo / çok eski hiçbir bölümde yok', !array_intersect($tum, ['TC800005.10', 'TC800006.10', 'TC800007.10', 'TC800009.10']));
ok('cari: para birimleri ayrı, toplanmaz', $v['cari'] === ['EUR' => ['faturalanan' => 1586480, 'tahsilat' => 0, 'bakiye' => 1586480], 'USD' => ['faturalanan' => 42000, 'tahsilat' => 0, 'bakiye' => 42000]], json_encode($v['cari']));
$s1 = $bul($v['yuklenen'], 'TC800001.10');
ok('s1: kalibre sırası 10, 14; pc_koli 160 esas; fiyat_tarihi = en yeni; tutar EUR 1584480', array_map(static fn(array $l): string => $l['kalibre'], $s1['satirlar']) === ['10', '14'] && $s1['satirlar'][0]['koli'] === 160
    && $s1['fiyat_tarihi'] === $bugun && $s1['tutar'] === ['EUR' => 1584480] && $s1['ulke'] === 'SIRBİSTAN' && $s1['fiyatli'] === true && $s1['yuklendi'] === true);
$s4 = $bul($v['yuklenen'], 'TC800004.10');
ok('s4: KG bazlı satır (fiyat_koli 42000, gram 350000)', $s4['satirlar'][0]['baz'] === 'kg' && $s4['satirlar'][0]['fiyat_koli'] === 42000 && $s4['satirlar'][0]['gram'] === 350000);
$s2 = $bul($v['yuklenmemis'], 'TC800002.10');
ok('s2: koli içeriği yok → 3,5 kg varsayılan + işaret', $s2['satirlar'][0]['kg_varsayilan'] === true && $s2['satirlar'][0]['koli_kg'] === 3.5 && $v['varsayilan_kg'] === true && $s2['ulke'] === 'İNGİLTERE');
ok('özet.bekleyen: fiyatsız yüklenen 1 sevkiyat, 200 koli, 800000 g', $v['ozet']['bekleyen'] === ['sevkiyat' => 1, 'koli' => 200, 'gram' => 800000], json_encode($v['ozet']['bekleyen']));
ok('özet.yüklenen', $v['ozet']['yuklenen'] === ['sevkiyat' => 3, 'koli' => 4140, 'gram' => 14590000], json_encode($v['ozet']['yuklenen']));
ok('özet.yüklenmemiş', $v['ozet']['yuklenmemis'] === ['sevkiyat' => 4, 'palet' => 5, 'koli' => 115, 'gram' => 417500], json_encode($v['ozet']['yuklenmemis']));
$s11 = $bul($v['fiyatli'], 'TC800010.10');
ok('s11: yüklenmemiş ama fiyatlı', $s11['yuklendi'] === false && $s11['fiyatli'] === true);
$vt = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre([], $bugun)), ['bugun' => $bugun, 'tahsilat' => ['EUR' => 86480, 'TRY' => 1000]]);
ok('tahsilat: bakiye = faturalanan − tahsilat; yalnız tahsilatlı para birimi de listelenir', array_keys($vt['cari']) === ['EUR', 'TRY', 'USD'] && $vt['cari']['EUR']['bakiye'] === 1500000
    && $vt['cari']['TRY'] === ['faturalanan' => 0, 'tahsilat' => 1000, 'bakiye' => -1000]);
$va = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre(['alici' => '1038529'], $bugun)), ['bugun' => $bugun, 'tahsilat' => []]);
ok('alıcı filtresi: yalnız 1038529', $refs($va['yuklenen']) === ['TC800001.10'] && $va['yuklenmemis'] === []);
$vd = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre(['bas' => $dun, 'bit' => $dun], $bugun)), ['bugun' => $bugun, 'tahsilat' => []]);
ok('tarih aralığı: yalnız o gün + tarihsiz HER ZAMAN dahil', $refs($vd['yuklenen']) === ['TC800001.10', 'TC800003.10'] && $refs($vd['yuklenmemis']) === ['TC800008.10'], json_encode([$refs($vd['yuklenen']), $refs($vd['yuklenmemis'])]));
$vb = siparis_cari_veri(siparis_cari_oku($db, siparis_cari_filtre(['bas' => ''], $bugun)), ['bugun' => $bugun, 'tahsilat' => []]);
ok('bas boş → sınırsız: çok eski sevkiyat da gelir (yüklenen)', in_array('TC800009.10', $refs($vb['yuklenen']), true));
rol($ADMIN, false, 1, ['DEPO2']);
$v2 = siparis_cari_oku($db, siparis_cari_filtre([], $bugun));
$r2 = array_map(static fn(array $r): string => $r['order_ref'], $v2['rows']);
ok('depo süzgeci: DEPO2 → TC800007 + atanmamış TC800008; DEPO1 verisi yok', in_array('TC800007.10', $r2, true) && in_array('TC800008.10', $r2, true) && !in_array('TC800001.10', $r2, true));
rol($ADMIN, false, 1, ['DEPOL']);
$db->beginTransaction();
$son = 0;
for ($i = 1; $i <= 1001; $i++) { $son = sev($db, ['order_ref' => sprintf('TC9300%03d.10', $i), 'durum' => 'hazirlik', 'yukleme_tarihi' => $yarin, 'depo' => 'DEPOL']); if ($i === 1000) { $id1000 = $son; kalem($db, $id1000, ['kalibre' => '12', 'po_palet' => 1, 'etkin_koli' => 5]); } }
$db->commit();
$vl = siparis_cari_oku($db, siparis_cari_filtre([], $bugun));
ok('limit: en çok 1000 sevkiyat, kesildi bayrağı, 1000. sevkiyatın kalemi geldi', SIPARIS_CARI_LIMIT === 1000 && count($vl['rows']) === 1000 && $vl['kesildi'] === true && isset($vl['kalem'][$id1000]));

echo "\n=== 3. Sayfa (siparis_cari.php) ===\n";
rol(['siparis.read', 'reports.export'], false, 3, ['DEPO1']);
$r = uc('siparis_cari.php');
$g = $r['govde'];
if (($out = getenv('SIPARIS_CARI_OUT')) !== false && $out !== '') file_put_contents($out, $g);   // tarayıcı/görsel kontrol için isteğe bağlı döküm
ok('muhasebe benzeri rol açar: 200, hata yok', $r['hata'] === null && $r['kod'] === 200, (string)$r['hata']);
foreach (['<title>Sipariş Cari Dökümü</title>', 'A) Gelen — Yüklenmemiş Siparişler', 'B) Yüklenen Sevkiyatlar', 'C) Fiyatlandırılmış Sevkiyatlar', '15.864,80 EUR', '420,00 USD',
    '4,1100 EUR / koli (3,5 kg)', '1,1743 EUR / kg', '15.124,80 EUR', '4,2000 USD / koli (3,5 kg)', '1,2000 USD / kg', '✓ Fiyatlı', 'Fiyat bekleniyor', 'Yüklenmedi',
    'Ödeme girişi 2. etapta eklenecek', 'Bakiye = müşterinin bize borcu', '3,5 kg varsayıldı', 'tutara dahil değil', 'print_pdks.css', 'A4 landscape', 'window.print()', 'data-sc-yazdir'] as $parca) {
    ok('sayfa içerir: ' . $parca, str_contains($g, $parca));
}
ok('sayfa: D) Cari Özet tek kez; tfoot yok; onclick yok', substr_count($g, 'D) Cari Özet') === 1 && !str_contains($g, '<tfoot') && !str_contains($g, 'onclick='));
ok('sayfa: iptal / silinmiş / başka depo sevkiyatı yok', !str_contains($g, 'TC800005.10') && !str_contains($g, 'TC800006.10') && !str_contains($g, 'TC800007.10'));
ok('sayfa: kullanıcı verisi kaçırılır (XSS)', !str_contains($g, '<script>alert(1)') && str_contains($g, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($g, '&lt;img src=x onerror=alert(2)&gt;'));
$au = $db->query("SELECT new_values FROM audit_log WHERE action = 'siparis_cari_goruntule' ORDER BY id DESC LIMIT 1")->fetchColumn();
$auJ = is_string($au) ? json_decode($au, true) : null;
ok('audit: siparis_cari_goruntule yalnız sayılar', is_array($auJ) && array_keys($auJ) === ['yuklenmemis', 'yuklenen', 'fiyatli', 'kesildi'], (string)$au);
ok('audit: sevkiyat no / plaka / nakliyeci YAZILMAZ', is_string($au) && !str_contains($au, 'TC8000') && !str_contains($au, '34TST') && !str_contains($au, 'Lojistik'));
$audSay = (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_cari_goruntule'")->fetchColumn();
rol(['dashboard.read', 'records.read', 'kantar.read', 'stok.read', 'defs.read', 'reports.read', 'beyan.read', 'hesap.read', 'siparis.read'], false, 5, ['DEPO1']);
$r = uc('siparis_cari.php');
ok('izleyici (reports.read var, reports.export yok): 403, gövde boş, audit yok', $r['hata'] !== null && str_contains($r['hata'], 'forbidden') && str_contains($r['hata'], 'reports.export') && $r['govde'] === ''
    && (int)$db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'siparis_cari_goruntule'")->fetchColumn() === $audSay, (string)$r['hata']);
rol(['reports.export'], false, 6, ['DEPO1']);
$r = uc('siparis_cari.php');
ok('siparis.read yoksa 403', $r['hata'] !== null && str_contains($r['hata'], 'siparis.read') && $r['govde'] === '', (string)$r['hata']);
rol(['siparis.read', 'reports.export'], false, 3, ['DEPO1']);
$r = uc('siparis_cari.php', ['alici' => '1038529']);
ok('sayfa alıcı filtresi: TC800001 var, TC800003 yok, seçenek seçili', str_contains($r['govde'], 'TC800001.10') && !str_contains($r['govde'], 'TC800003.10') && str_contains($r['govde'], '<option value="1038529" selected>'));

echo "\n=== 4. Doğrudan istek koruması (ayrı süreç) ===\n";
$__kos = function (string $script) use ($ROOT): string {
    $kod = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($script, true) . '; function h($s){return htmlspecialchars((string)$s);}'
         . ' require ' . var_export($ROOT . '/config/siparis_core.php', true) . '; require ' . var_export($ROOT . '/_siparis_ortak.php', true) . ';'
         . ' require ' . var_export($ROOT . '/config/siparis_cari.php', true) . '; echo "YUKLENDI";';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($kod) . ' 2>&1');
};
ok('sayfa (kök siparis_cari.php) çalışırken config/siparis_cari.php YÜKLENİR', str_contains($__kos($ROOT . '/siparis_cari.php'), 'YUKLENDI'));
ok('config/siparis_cari.php doğrudan istenirse durur', !str_contains($__kos($ROOT . '/config/siparis_cari.php'), 'YUKLENDI'));

echo "\n=== 5. Statik ===\n";
$cfgHam = (string)file_get_contents($ROOT . '/config/siparis_cari.php');
$sayfa = (string)file_get_contents($ROOT . '/siparis_cari.php');
ok('config: yalnız SELECT (yazan SQL yok)', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\s/', $cfgHam));
ok('sayfa: fonksiyon tanımlamaz, csrf_check yok', !preg_match('/^\s*function\s/m', $sayfa) && !str_contains($sayfa, 'csrf_check('));

siparis_test_bitir();
