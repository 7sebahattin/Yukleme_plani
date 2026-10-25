<?php
// =========================================================
// scripts/hks_gelen_bildirim_test.php — "Bana Yapılan Bildirimler" (v432)
//   php scripts/hks_gelen_bildirim_test.php   → çıkış 0 = geçti
// SADECE CLI, AĞSIZ: hks_soap_cagir() hiç çağrılmaz (orkestrasyon $cagir ile
// sahte pencere işlevi alır). Kapsam: DTO ayıklama, pencere bölme, istek XML'i
// (KalanMiktariSifirdanBuyukOlanlar=false), tekilleştirme/sıralama, kalan=0
// satırların ELENMEMESİ, kısmi hata, tarih doğrulama, Kişi Havuzu ad eşlemesi,
// api.php kapı kaydı.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../halkayit/hks_soap.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = '') {
    global $fail;
    if (!$c) $fail++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
function dto(array $a): string {
    $ic = '';
    foreach ($a as $k => $v) $ic .= "<a:{$k}>{$v}</a:{$k}>";
    return "<a:BildirimSorguDTO>{$ic}</a:BildirimSorguDTO>";
}
function zarf(string $kod, string $govde): string {
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
        . '<a:BaseResponseMessageOf_BildirimSorguCevap xmlns:a="urn:x"><a:IslemKodu>' . $kod . '</a:IslemKodu>'
        . '<a:Sonuc><a:Bildirimler>' . $govde . '</a:Bildirimler></a:Sonuc></a:BaseResponseMessageOf_BildirimSorguCevap>'
        . '</s:Body></s:Envelope>';
}

echo "── İstek XML ──\n";
$x = hks_gelen_istek_xml(new DateTime('2026-10-01 00:00:00'), new DateTime('2026-10-25 23:59:59'));
ok('KalanMiktariSifirdanBuyukOlanlar=false', strpos($x, '<a:KalanMiktariSifirdanBuyukOlanlar>false</a:KalanMiktariSifirdanBuyukOlanlar>') !== false);
ok('KunyeTuru=1, KunyeNo=0', strpos($x, '<a:KunyeTuru>1</a:KunyeTuru>') !== false && strpos($x, '<a:KunyeNo>0</a:KunyeNo>') !== false);
ok('alfabetik sıra: Baslangic < Bitis < Kalan < KunyeNo < KunyeTuru',
    preg_match('/Baslangic.*Bitis.*KalanMiktari.*KunyeNo.*KunyeTuru/s', $x) === 1);
ok('tarih biçimi', strpos($x, '2026-10-01T00:00:00') !== false && strpos($x, '2026-10-25T23:59:59') !== false);
$kaynak = (string)file_get_contents(__DIR__ . '/../halkayit/hks_soap.php');
ok('gelen pencere GELEN metodunu çağırır',
    strpos($kaynak, "'BildirimServisBildirimciyeYapilanBildirimListesi',\n    hks_taban_istek('BaseRequestMessageOf_BildirimSorguIstek', hks_gelen_istek_xml") !== false);

echo "\n── DTO ayıklama (kalan=0 elenmez) ──\n";
$govde = dto(['KunyeNo' => '111', 'BildirimTarihi' => '2026-10-24T09:30:15', 'MalinAdi' => 'KİRAZ', 'MalinCinsi' => 'DİĞER',
        'MalinMiktari' => '5000.000', 'KalanMiktar' => '0.000', 'MiktarBirimiAd' => 'KG', 'AracPlakaNo' => '34ABC12',
        'BelgeNo' => '', 'BildirimciTcKimlikVergiNo' => '1111111111', 'MalinSahibiTcKimlikVergiNo' => '2222222222'])
    . dto(['KunyeNo' => '222', 'BildirimTarihi' => '2026-10-25T08:00:00', 'MalinAdi' => 'KAYISI', 'MalinMiktari' => '300', 'KalanMiktar' => '120',
        'BelgeNo' => 'B-9', 'BildirimciTcKimlikVergiNo' => '3333333333'])
    . dto(['KunyeNo' => '', 'MalinAdi' => 'BOŞ KÜNYE']);
$rows = hks_gelen_yanit_ayikla(hks_sadelestir(zarf('GTBWSRV0000001', $govde)));
ok('kunyesiz blok atlanır, 2 satır', count($rows) === 2, (string)count($rows));
ok('kalan=0 satır ELENMEDİ', $rows[0]['kunyeNo'] === '111' && $rows[0]['kalan'] === 0.0 && $rows[0]['miktar'] === 5000.0);
ok('tarih/saat ayrıştı', $rows[0]['tarih'] === '2026-10-24' && $rows[0]['saat'] === '09:30');
ok('ürün/cins/birim/plaka', $rows[0]['urun'] === 'KİRAZ' && $rows[0]['cins'] === 'DİĞER' && $rows[0]['birim'] === 'KG' && $rows[0]['plaka'] === '34ABC12');
ok('gönderen = bildirimci TC, mal sahibi ayrı', $rows[0]['bildirimciTc'] === '1111111111' && $rows[0]['malinSahibiTc'] === '2222222222');
ok('birim yoksa Kg, belge no', $rows[1]['birim'] === 'Kg' && $rows[1]['belgeNo'] === 'B-9');
$hata = false;
try { hks_gelen_yanit_ayikla(hks_sadelestir('<s:Envelope xmlns:s="x"><s:Body><a:R xmlns:a="u"><a:IslemKodu>GTBGLB0001</a:IslemKodu><a:HataKodlari><a:ErrorModel><a:HataKodu>11</a:HataKodu><a:Mesaj>GTBGLB00000011</a:Mesaj></a:ErrorModel></a:HataKodlari></a:R></s:Body></s:Envelope>')); }
catch (Exception $e) { $hata = true; }
ok('hata modeli exception', $hata);

echo "\n── Pencere bölme ──\n";
$simdi = new DateTime('2026-10-25 14:00:00');
$p1 = hks_gelen_pencereler(new DateTime('2026-10-25'), new DateTime('2026-10-25'), $simdi);
ok('bugün: tek pencere, bitiş = şimdi', count($p1) === 1 && $p1[0][0]->format('Y-m-d H:i:s') === '2026-10-25 00:00:00' && $p1[0][1]->format('H:i:s') === '14:00:00');
$p2 = hks_gelen_pencereler(new DateTime('2026-04-23'), new DateTime('2026-10-25'), $simdi); // 186 gün
ok('186 gün = 7 pencere', count($p2) === 7, (string)count($p2));
$buyuk = 0; $bosluk = false;
foreach ($p2 as $i => [$b, $e]) {
    $buyuk = max($buyuk, (int)$b->diff($e)->days + 1);
    if ($i > 0 && $p2[$i - 1][1]->modify('+1 second')->format('Y-m-d H:i:s') !== $b->format('Y-m-d H:i:s')) $bosluk = true;
}
ok('her pencere ≤ 27 gün', $buyuk <= 27, (string)$buyuk);
ok('pencereler boşluksuz/çakışmasız', !$bosluk);
ok('ilk pencere başlangıç = istenen başlangıç', $p2[0][0]->format('Y-m-d') === '2026-04-23');
ok('son pencere bitiş = şimdi', end($p2)[1]->format('Y-m-d H:i:s') === '2026-10-25 14:00:00');
$p3 = hks_gelen_pencereler(new DateTime('2026-10-01'), new DateTime('2026-10-27'), new DateTime('2026-12-01'));
ok('tam 27 gün = 1 pencere', count($p3) === 1);
$p4 = hks_gelen_pencereler(new DateTime('2026-10-01'), new DateTime('2026-10-28'), new DateTime('2026-12-01'));
ok('28 gün = 2 pencere', count($p4) === 2);

echo "\n── Birleştirme / sıralama / kısmi hata ──\n";
$cagrilar = 0;
$sahte = function (DateTime $b, DateTime $e) use (&$cagrilar) {
    $cagrilar++;
    if ($cagrilar === 2) throw new Exception('HKS pencere hatası');
    return [
        ['kunyeNo' => 'A', 'tarihSaat' => $b->format('Y-m-d') . 'T10:00:00', 'bildirimciTc' => '1'],
        ['kunyeNo' => 'DUP', 'tarihSaat' => '2026-10-01T00:00:00', 'bildirimciTc' => '1'],
    ];
};
$bas = new DateTime('2026-07-01'); $bit = new DateTime('2026-08-31'); // 62 gün → 3 pencere
$ham = null;
$s = hks_gelen_bildirimler([], $bas, $bit, $ham, $sahte);
ok('3 pencere çağrıldı, 1 kısmi hata', $cagrilar === 3 && $s['kismiHata'] === 1 && $s['pencere'] === 3);
ok('künyeNo ile tekilleştirildi (DUP tek)', count(array_filter($s['bildirimler'], fn($r) => $r['kunyeNo'] === 'DUP')) === 1);
$tarihler = array_column($s['bildirimler'], 'tarihSaat');
$sirali = $tarihler; rsort($sirali, SORT_STRING);
ok('yeni → eski sıralı', $tarihler === $sirali, implode(',', $tarihler));
$hepsiHata = false;
try { hks_gelen_bildirimler([], $bas, $bit, $ham, function () { throw new Exception('Servis kapalı'); }); }
catch (Exception $e) { $hepsiHata = $e->getMessage() === 'Servis kapalı'; }
ok('hiç pencere başarılı değilse hata fırlatır', $hepsiHata);

echo "\n── Tarih doğrulama ──\n";
$bugun = new DateTime('2026-10-25');
function dogrula($b, $e, $bugun): string {
    try { [$x, $y] = hks_gelen_aralik_dogrula($b, $e, $bugun); return $x->format('Y-m-d') . '..' . $y->format('Y-m-d'); }
    catch (InvalidArgumentException $ex) { return 'HATA:' . $ex->getMessage(); }
}
ok('geçerli aralık', dogrula('2026-10-20', '2026-10-25', $bugun) === '2026-10-20..2026-10-25');
ok('bitiş gelecekte → bugüne kırpılır', dogrula('2026-10-20', '2026-12-31', $bugun) === '2026-10-20..2026-10-25');
ok('bozuk tarih', strpos(dogrula('2026-13-40', '2026-10-25', $bugun), 'HATA:Başlangıç tarihi geçersiz') === 0);
ok('boş bitiş', strpos(dogrula('2026-10-20', '', $bugun), 'HATA:Bitiş tarihi geçersiz') === 0);
ok('biçim dışı (gg.aa.yyyy)', strpos(dogrula('20.10.2026', '2026-10-25', $bugun), 'HATA:') === 0);
ok('başlangıç > bitiş', strpos(dogrula('2026-10-25', '2026-10-20', $bugun), 'HATA:Başlangıç tarihi bitiş') === 0);
ok('başlangıç gelecekte', strpos(dogrula('2026-11-01', '2026-11-05', $bugun), 'HATA:') === 0);
ok('186 gün serbest', strpos(dogrula('2026-04-23', '2026-10-25', $bugun), 'HATA') === false);
ok('187 gün reddedilir', strpos(dogrula('2026-04-22', '2026-10-25', $bugun), 'HATA:Tarih aralığı en çok 186') === 0);

echo "\n── Kişi Havuzu ad eşlemesi + api.php kaydı ──\n";
if (extension_loaded('pdo_sqlite')) {
    require_once __DIR__ . '/../halkayit/kisi_havuzu_lib.php';
    require_once __DIR__ . '/../halkayit/tekrar_gonder_lib.php';
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ' . hks_kisi_tablo() . ' (tc TEXT PRIMARY KEY, ad TEXT)');
    $db->exec("INSERT INTO " . hks_kisi_tablo() . " VALUES ('1111111111','Örnek Firma'),('3333333333','')");
    $h = hks_gelen_kisi_adlari($db, ['1111111111', '3333333333', '9999999999', '']);
    ok('adı olan eşlenir, boş/yok eşlenmez', $h === ['1111111111' => 'Örnek Firma'], json_encode($h));
    ok('boş liste → []', hks_gelen_kisi_adlari($db, []) === []);
    $db->exec('DROP TABLE ' . hks_kisi_tablo());
    ok('tablo yoksa hata YUTULUR → []', hks_gelen_kisi_adlari($db, ['1111111111']) === []);
} else {
    echo "pdo_sqlite yok — ad eşleme testi ATLANDI\n";
}
$api = (string)file_get_contents(__DIR__ . '/../halkayit/api.php');
ok("api.php: 'gelen_sorgu' muaf listede (salt okunur)", preg_match("/hks_csrf_muaf_eylemler\(\): array \{.*?'gelen_sorgu'.*?\n\}/s", $api) === 1);
ok("api.php: case 'gelen_sorgu' var", strpos($api, "case 'gelen_sorgu':") !== false);
ok('api.php: tarih doğrulama 400 ile döner', strpos($api, 'hks_gelen_aralik_dogrula($g[') !== false);

echo $fail ? "\n$fail TEST BAŞARISIZ\n" : "\nTüm testler geçti.\n";
exit($fail ? 1 : 0);
