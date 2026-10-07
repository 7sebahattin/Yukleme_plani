<?php
// =========================================================
// scripts/hks_tekrar_gonder_smoke.php — Gönderilenler "↻ Tekrar gönder" (v305)
//
// SADECE CLI. Canlı DB'ye DOKUNMAZ, ağ çağrısı YAPMAZ: bellek içi SQLite +
// halkayit/tekrar_gonder_lib.php'nin GERÇEK fonksiyonları. Kişi Havuzu tablosu
// lib'in kendi hks_kisi_tablo_hazirla()'sından gelir. hks_kv_oku aynı SQLite'ın
// hks_kv tablosu üzerinden taklit edilir; hks_tr_normalize taslak_lib.php'den
// (config → MySQL bağlantısı istediği için require edilemez) KAYNAKTAN ayıklanıp
// çalıştırılır — kopya değil, gerçek gövde. api.php akış kuralları kaynakta
// (statik) denetlenir. Örneklerdeki ad/TC'ler sentetiktir.
//
// Kapsam: kopya beyaz listesi (kaynak/gidecekAdres/ikinciDogumTarihi/ikinciCep/
// eskiTaslakId girmez, satır yalnız kunyeNo+miktar, çöp → null), kopyadan tohum
// (referanslı → plan, plan aynen, referanssız satır korunur, doğum/cep havuzdan
// TC ile), eski kayıttan tohum (katalog TAM ad, havuzda TEK ad eşleşmesi, belirsiz/
// yok → boş, yurt dışı ülke + plan, NULL tür önekten), firma değişince firmaya
// özgü alanlar boşalır; api.php: INSERT'te kopya, sıra, salt okunur uç, izolasyon.
//
//   php scripts/hks_tekrar_gonder_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);

$GLOBALS['__DB'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['__DB']->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');

// db.php'deki hks_kv_oku / hks_kv_yaz'ın bellek içi taklidi (aynı SQLite).
function hks_kv_oku($anahtar, $varsayilan = null) {
    $st = $GLOBALS['__DB']->prepare('SELECT deger FROM hks_kv WHERE anahtar = ?');
    $st->execute([$anahtar]);
    $r = $st->fetchColumn();
    return $r === false ? $varsayilan : json_decode((string)$r, true);
}
function hks_kv_yaz($anahtar, $veri) {
    $GLOBALS['__DB']->prepare('DELETE FROM hks_kv WHERE anahtar = ?')->execute([$anahtar]);
    $GLOBALS['__DB']->prepare('INSERT INTO hks_kv (anahtar, deger) VALUES (?, ?)')
        ->execute([$anahtar, json_encode($veri, JSON_UNESCAPED_UNICODE)]);
}

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// GERÇEK hks_tr_normalize — taslak_lib.php'den ayıklanır.
$taslakSrc = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
if (!preg_match('/^function hks_tr_normalize\(.*?^\}/ms', $taslakSrc, $m)) {
    fwrite(STDERR, "taslak_lib.php içinde hks_tr_normalize bulunamadı\n");
    exit(1);
}
$__tmp = tempnam(sys_get_temp_dir(), 'hkstrn');
file_put_contents($__tmp, "<?php\n" . $m[0] . "\n");
require $__tmp;
@unlink($__tmp);

require_once "$KOK/halkayit/tekrar_gonder_lib.php";

$db = $GLOBALS['__DB'];
hks_kisi_tablo_hazirla($db);

// Algoritmayı geçen TC üret — örneklerde gerçek kişi yok.
function tc_uret(string $govde9): string {
    $d = array_map('intval', str_split($govde9));
    $tek = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
    $cift = $d[1] + $d[3] + $d[5] + $d[7];
    $d10 = ((($tek * 7) - $cift) % 10 + 10) % 10;
    $d11 = (array_sum($d) + $d10) % 10;
    return $govde9 . $d10 . $d11;
}
$TC_A = tc_uret('123456789');
$TC_B = tc_uret('234567891');
$TC_C = tc_uret('345678912');
$TC_YOK = tc_uret('456789123');   // havuzda yok
$VKN = '1234567890';

// ── Sentetik katalog + havuz ────────────────────────────────────────────────
$KATALOG = [
    'sifatlar' => [['id' => 5, 'ad' => 'İhracat'], ['id' => 7, 'ad' => 'Üretici'], ['id' => 9, 'ad' => 'Komisyoncu']],
    'bildirimTurleri' => [
        ['id' => 191, 'ad' => 'Satış'], ['id' => 192, 'ad' => 'Sevk Etme'],
        ['id' => 193, 'ad' => 'Satın Alım'], ['id' => 194, 'ad' => 'Üreticiden Sevk Alım'],
    ],
    'urunler' => [
        ['id' => 40, 'ad' => 'DOMATES'], ['id' => 41, 'ad' => 'Biber'], ['id' => 42, 'ad' => 'İNCİR'],
        ['id' => 43, 'ad' => 'KAVUN'], ['id' => 44, 'ad' => 'Kavun'],   // belirsiz (iki kayıt)
    ],
    'ulkeler' => [['id' => 100, 'ad' => 'Rusya'], ['id' => 101, 'ad' => 'Irak']],
];
hks_kv_yaz('listeler_cache', $KATALOG);

hks_kisi_upsert($db, ['tc' => $TC_A, 'ad' => 'Örnek Müstahsil Bir', 'cep' => '05001112233', 'dogum' => '1970-03-15', 'sifatId' => 7]);
hks_kisi_upsert($db, ['tc' => $TC_B, 'ad' => 'Aynı Ad Soyad', 'cep' => '05002223344', 'dogum' => '1980-05-20', 'sifatId' => 7]);
hks_kisi_upsert($db, ['tc' => $TC_C, 'ad' => 'aynı ad  soyad', 'cep' => '', 'dogum' => '', 'sifatId' => 7]);
hks_kisi_upsert($db, ['tc' => $VKN, 'ad' => 'Deneme Tarım Ltd', 'sifatId' => 9]);

// Gönderilenler satırı (hks_gonderilenler kolonları).
function satir(array $alan): array {
    return $alan + [
        'id' => 'g1', 'zaman' => '2026-10-01 10:00:00', 'firma_id' => 'f1', 'firma_ad' => 'Firma Bir',
        'plaka' => '34TST001', 'belge_no' => '', 'ulke_ad' => '', 'urun_ad' => '', 'adet' => 1,
        'toplam_kg' => 0, 'fiyat' => 0, 'rusum' => 0, 'hata_sayisi' => 0, 'genel_hata' => null,
        'bildirim_turu' => null, 'veri' => json_encode(['yeniKunyeler' => ['2610000000999'], 'sonuclar' => []]),
    ];
}
// taslak_gonder'in yazdığı veri JSON'u: {yeniKunyeler, sonuclar, kopya}.
function kopyali_veri(array $taslakVeri): string {
    return json_encode(['yeniKunyeler' => ['2610000000999'], 'sonuclar' => [],
        'kopya' => hks_gonderim_kopyasi($taslakVeri)], JSON_UNESCAPED_UNICODE);
}
function not_var(array $t, string $parca): bool {
    foreach ($t['notlar'] as $n) if (strpos($n, $parca) !== false) return true;
    return false;
}
const NOT_PLAN  = 'plan taslağı olarak açıldı';
const NOT_URUN  = 'Ürün seçip künyeleri yeniden getirin.';
const NOT_ESKI  = 'Eski kayıt: ürün cinsi, üretim yeri, işyeri gibi alanları kontrol edip seçin.';
const NOT_FIRMA = 'Firma değişti: bildirimci sıfatı ve işyeri/depo yeniden seçilmeli.';

echo "── Kopya beyaz listesi ──\n";
$taslakYD = [
    'satirlar' => [
        ['kunyeNo' => '2610000000001', 'miktar' => 1200.5, 'kalan' => 9999, 'uniqueId' => 'u1'],
        'bozuk',
        ['kunyeNo' => '2610000000002', 'miktar' => '300'],
        ['kunyeNo' => ['dizi'], 'miktar' => 5],
        ['kunyeNo' => '2610000000003', 'miktar' => 'abc'],
    ],
    'ortak' => [
        'sifatId' => '5', 'bildirimTuruId' => '191', 'turAd' => 'Satış', 'urunId' => '40', 'urunAd' => 'DOMATES',
        'plaka' => '34TST001', 'belgeNo' => '', 'belgeTipiId' => 0, 'fiyat' => 25, 'isletmeTuruId' => '3',
        'ulkeId' => '100', 'ulkeAd' => 'Rusya',
        'kaynak' => ['tip' => 'beyan', 'beyanId' => 12],
        'gidecekAdres' => ['ilId' => 1, 'ilceId' => 2, 'beldeId' => 3],
        'eskiTaslakId' => 't1700000000000',
        'ikinciDogumTarihi' => '1970-03-15', 'ikinciCep' => '05001112233',
        'uniqueId' => 'x-1', 'yeniKunyeNo' => '2610000000999', 'sonuclar' => [['hataKodu' => 0]],
        'fiyatGonder' => ['dizi'],                 // skaler değil → girmez
        'analiz' => new stdClass(),                // nesne → girmez
        'planSorgu' => ['urunId' => '40', 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan', 'gizli' => 'x', 'ic' => ['a']],
    ],
];
$k = hks_gonderim_kopyasi($taslakYD);
ok('kopya üretildi, sürüm v=1', is_array($k) && ($k['v'] ?? null) === 1);
foreach (['kaynak', 'gidecekAdres', 'eskiTaslakId', 'ikinciDogumTarihi', 'ikinciCep', 'uniqueId', 'yeniKunyeNo', 'sonuclar'] as $yasak) {
    ok("ortak.$yasak kopyaya GİRMEZ", !array_key_exists($yasak, $k['ortak']));
}
$kSk = hks_gonderim_kopyasi(['ortak' => ['plaka' => 'X', 'kaynak' => 'beyan-12', 'gidecekAdres' => 'metin', 'eskiTaslakId' => 't1']]);
ok('SKALER olsa da kaynak/gidecekAdres/eskiTaslakId girmez (beyaz liste)', is_array($kSk)
    && array_keys($kSk['ortak']) === ['plaka'], json_encode($kSk));
ok('skaler olmayan beyaz liste değeri düşer (fiyatGonder dizi, analiz nesne)',
    !array_key_exists('fiyatGonder', $k['ortak']) && !array_key_exists('analiz', $k['ortak']));
ok('beyaz listedeki alanlar aynen (sifatId/urunId/ulkeId/fiyat/plaka)',
    $k['ortak']['sifatId'] === '5' && $k['ortak']['urunId'] === '40' && $k['ortak']['ulkeId'] === '100'
    && $k['ortak']['fiyat'] === 25 && $k['ortak']['plaka'] === '34TST001');
ok('planSorgu yalnız urunId/aySayisi/isletmeTuruId/sirala',
    $k['ortak']['planSorgu'] === ['urunId' => '40', 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan'],
    json_encode($k['ortak']['planSorgu']));
ok('satırlar yalnız {kunyeNo, miktar}; bozuk satırlar atlanır',
    $k['satirlar'] === [['kunyeNo' => '2610000000001', 'miktar' => 1200.5], ['kunyeNo' => '2610000000002', 'miktar' => 300.0]],
    json_encode($k['satirlar']));
$kJson = json_encode($k, JSON_UNESCAPED_UNICODE);
ok('kopya JSON\'unda doğum/cep/beyan izi yok', strpos($kJson, '1970-03-15') === false
    && strpos($kJson, '05001112233') === false && strpos($kJson, 'beyanId') === false);
ok('çöp: boş dizi → null', hks_gonderim_kopyasi([]) === null);
ok('çöp: ortak metin → null', hks_gonderim_kopyasi(['ortak' => 'x', 'satirlar' => []]) === null);
ok('çöp: ortak boş → null', hks_gonderim_kopyasi(['ortak' => [], 'satirlar' => [['kunyeNo' => '1', 'miktar' => 1]]]) === null);
ok('çöp: yalnız yasak alanlar → null', hks_gonderim_kopyasi(['ortak' => ['kaynak' => ['tip' => 'beyan'], 'ikinciCep' => '0500']]) === null);
$k2 = hks_gonderim_kopyasi(['ortak' => ['plaka' => 'X'], 'satirlar' => 'bozuk']);
ok('satirlar dizi değilse boş satırla kopya', is_array($k2) && $k2['satirlar'] === []);
$istisna = null;
try { $k3 = hks_gonderim_kopyasi(['ortak' => ['plaka' => fopen('php://memory', 'r')], 'satirlar' => [null, 1, true]]); }
catch (Throwable $e) { $istisna = $e->getMessage(); }
ok('kaynak (resource) / tuhaf satırlar istisna FIRLATMAZ', $istisna === null && $k3 === null, (string)$istisna);

echo "\n── Ad eşleşmesi (Kişi Havuzu, TAM + TEK) ──\n";
$r = hks_kisi_ad_ile_tek($db, 'Örnek Müstahsil Bir');
ok('tek eşleşme → satır', is_array($r) && $r['tc'] === $TC_A);
$r = hks_kisi_ad_ile_tek($db, '  ÖRNEK   MÜSTAHSİL BİR ');
ok('büyük harf (Türkçe İ) + fazla boşluk → aynı kişi', is_array($r) && $r['tc'] === $TC_A);
ok('aynı adda iki kişi → null (belirsiz)', hks_kisi_ad_ile_tek($db, 'Aynı Ad Soyad') === null);
ok('olmayan ad → null', hks_kisi_ad_ile_tek($db, 'Hiç Olmayan Kişi') === null);
ok('parça ad → null (bulanık eşleşme YOK)', hks_kisi_ad_ile_tek($db, 'Örnek Müstahsil') === null);
ok('boş ad → null', hks_kisi_ad_ile_tek($db, '   ') === null);

echo "\n── Kopyadan tohum ──\n";
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($taslakYD)]), null, false);
ok('kaynak = kopya', $t['kaynak'] === 'kopya');
ok('referanslı + künyeli → PLAN (plana=true, satır yok)', $t['plana'] === true && $t['tohum']['satirlar'] === []);
ok('planKg = Σmiktar (1500,5)', ($t['tohum']['ortak']['planKg'] ?? null) === 1500.5, json_encode($t['tohum']['ortak']['planKg'] ?? null));
ok('planSorgu = {urunId, 12, 0, azalan}',
    ($t['tohum']['ortak']['planSorgu'] ?? null) === ['urunId' => '40', 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan']);
ok('plan notu', not_var($t, NOT_PLAN));
ok('tohumda beyan izi (kaynak) yok', !array_key_exists('kaynak', $t['tohum']['ortak']));
ok('yurt dışı alanlar korunur (ulkeId, fiyat, sifatId)', $t['tohum']['ortak']['ulkeId'] === '100'
    && $t['tohum']['ortak']['fiyat'] === 25 && $t['tohum']['ortak']['sifatId'] === '5');
ok('tohum JSON\'a çevrilebilir', json_encode($t, JSON_UNESCAPED_UNICODE) !== false);

$urunsuz = $taslakYD; $urunsuz['ortak']['urunId'] = 0; unset($urunsuz['ortak']['planSorgu']);
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($urunsuz)]), null, false);
ok('ürünsüz referanslı → plana=false, satır yok, planKg yok',
    $t['plana'] === false && $t['tohum']['satirlar'] === [] && !isset($t['tohum']['ortak']['planKg'])
    && !isset($t['tohum']['ortak']['planSorgu']));
ok('ürünsüz → "Ürün seçip künyeleri yeniden getirin."', not_var($t, NOT_URUN) && !not_var($t, NOT_PLAN));

$plan = ['satirlar' => [], 'ortak' => ['sifatId' => '5', 'bildirimTuruId' => '191', 'urunId' => '41', 'urunAd' => 'Biber',
    'plaka' => '34TST002', 'fiyat' => 18, 'ulkeId' => '101', 'ulkeAd' => 'Irak', 'planKg' => 800,
    'planSorgu' => ['urunId' => '41', 'aySayisi' => 6, 'isletmeTuruId' => 2, 'sirala' => 'artan']]];
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($plan)]), null, false);
ok('plan kopyası aynen (planKg 800, kendi planSorgu)', $t['tohum']['satirlar'] === []
    && $t['tohum']['ortak']['planKg'] === 800
    && $t['tohum']['ortak']['planSorgu'] === ['urunId' => '41', 'aySayisi' => 6, 'isletmeTuruId' => 2, 'sirala' => 'artan']);
ok('plan kopyası "çevrildi" sayılmaz (plana=false, plan notu yok)', $t['plana'] === false && !not_var($t, NOT_PLAN));

$satin = ['satirlar' => [['kunyeNo' => '0', 'miktar' => 950]], 'ortak' => [
    'yurtIci' => true, 'referanssiz' => true, 'kayitZorunlu' => false, 'uretSevk' => false, 'ikinciKayitsiz' => true,
    'fiyatGonder' => true, 'fiyat' => 12.5, 'sifatId' => '5', 'bildirimTuruId' => '193', 'turAd' => 'Satın Alım',
    'malinNiteligi' => '1', 'malinKodNo' => '40', 'malinCinsiId' => '400', 'uretimSekli' => '1', 'miktarBirimId' => '74',
    'uretimIlId' => '7', 'uretimIlceId' => '70', 'uretimBeldeId' => '700', 'ithalat' => false, 'gelenUlkeId' => 0,
    'analiz' => false, 'urunAd' => 'DOMATES', 'ikinciTc' => $TC_A, 'ikinciSifatId' => '7', 'ikinciAd' => 'Örnek Müstahsil Bir',
    'plaka' => '07TST003', 'belgeNo' => '', 'belgeTipiId' => 0, 'isletmeTuruId' => '3',
    'gidecekIsyeriId' => '77', 'gidecekIsyeriAd' => 'Merkez Depo', 'ulkeAd' => 'Satın Alım ← Örnek Müstahsil Bir',
    'ikinciCep' => '05009998877', 'ikinciDogumTarihi' => '1970-03-15',
    'kaynak' => ['tip' => 'beyan', 'beyanId' => 3],
]];
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($satin)]), null, false);
$o = $t['tohum']['ortak'];
ok('referanssız kopya: satır {0, 950} korunur, plana=false',
    $t['tohum']['satirlar'] === [['kunyeNo' => '0', 'miktar' => 950.0]] && $t['plana'] === false && !isset($o['planKg']));
ok('referanssız kopya: mal tanımı korunur (malinKodNo/cins/üretim yeri)', $o['malinKodNo'] === '40'
    && $o['malinCinsiId'] === '400' && $o['uretimBeldeId'] === '700' && $o['miktarBirimId'] === '74');
ok('doğum tarihi Kişi Havuzu\'ndan TC ile (YYYY-AA-GG)', ($o['ikinciDogumTarihi'] ?? '') === '1970-03-15');
ok('cep Kişi Havuzu\'ndan (taslaktaki değil, havuzdaki)', ($o['ikinciCep'] ?? '') === '05001112233');
ok('firma aynı → sıfat + işyeri korunur', $o['sifatId'] === '5' && $o['gidecekIsyeriId'] === '77' && $o['gidecekIsyeriAd'] === 'Merkez Depo');
ok('tohumda kaynak yok (beyan bağı taşınmaz)', !array_key_exists('kaynak', $o));
ok('kopyada not yok (plan/eski/firma)', $t['notlar'] === [], json_encode($t['notlar'], JSON_UNESCAPED_UNICODE));

$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($satin)]), 'f2', true);
$o = $t['tohum']['ortak'];
ok('firma değişti → sifatId/gidecekIsyeriId/gidecekIsyeriAd boş',
    !isset($o['sifatId']) && !isset($o['gidecekIsyeriId']) && !isset($o['gidecekIsyeriAd']));
ok('firma değişti → karşı taraf ve mal tanımı korunur', $o['ikinciTc'] === $TC_A && $o['malinKodNo'] === '40'
    && $o['isletmeTuruId'] === '3');
ok('firma değişti notu', not_var($t, NOT_FIRMA));

$yok = $satin; $yok['ortak']['ikinciTc'] = $TC_YOK;
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri($yok)]), null, false);
ok('havuzda olmayan TC → doğum/cep EKLENMEZ', !isset($t['tohum']['ortak']['ikinciDogumTarihi']) && !isset($t['tohum']['ortak']['ikinciCep']));
$t = hks_gonderim_tohumu($db, satir(['veri' => kopyali_veri(['satirlar' => [['kunyeNo' => '0', 'miktar' => 10]],
    'ortak' => ['yurtIci' => true, 'referanssiz' => true, 'ikinciTc' => $TC_C]])]), null, false);
ok('havuzda doğum/cep BOŞ → alan eklenmez', !isset($t['tohum']['ortak']['ikinciDogumTarihi']) && !isset($t['tohum']['ortak']['ikinciCep']));

$bozuk = json_decode(kopyali_veri($satin), true);
$bozuk['kopya']['ortak']['kaynak'] = ['tip' => 'beyan', 'beyanId' => 99];
$bozuk['kopya']['ortak']['gidecekAdres'] = ['ilId' => 1];
$bozuk['kopya']['satirlar'][0]['gizli'] = 'x';
$t = hks_gonderim_tohumu($db, satir(['veri' => json_encode($bozuk)]), null, false);
ok('saklı kopya okumada YENİDEN beyaz listeden geçer', $t['kaynak'] === 'kopya'
    && !isset($t['tohum']['ortak']['kaynak']) && !isset($t['tohum']['ortak']['gidecekAdres'])
    && $t['tohum']['satirlar'] === [['kunyeNo' => '0', 'miktar' => 950.0]]);
$v2 = json_decode(kopyali_veri($satin), true);
$v2['kopya']['v'] = 2;
$t = hks_gonderim_tohumu($db, satir(['veri' => json_encode($v2), 'bildirim_turu' => 'SATIN_ALIM',
    'ulke_ad' => 'Satın Alım ← Örnek Müstahsil Bir', 'urun_ad' => 'DOMATES', 'toplam_kg' => 950]), null, false);
ok('tanınmayan kopya sürümü → eski kayıt yolu', $t['kaynak'] === 'eski');
$t = hks_gonderim_tohumu($db, satir(['veri' => 'bozuk-json{']), null, false);
ok('bozuk veri JSON\'u → eski kayıt yolu, istisna yok', $t['kaynak'] === 'eski');

echo "\n── Eski kayıttan tohum (kopya yok) ──\n";
$eskiSatin = satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => 'Satın Alım ← Örnek Müstahsil Bir',
    'urun_ad' => 'Domates', 'toplam_kg' => 950, 'fiyat' => 12.5, 'plaka' => '07TST003', 'belge_no' => 'B-1']);
$t = hks_gonderim_tohumu($db, $eskiSatin, null, false);
$o = $t['tohum']['ortak'];
ok('kaynak = eski, plana=false', $t['kaynak'] === 'eski' && $t['plana'] === false);
ok('tür katalogdan TAM ad: Satın Alım → 193', ($o['bildirimTuruId'] ?? null) === 193 && ($o['turAd'] ?? '') === 'Satın Alım');
ok('referanssız + yurt içi + fiyatlı bayraklar', $o['referanssiz'] === true && $o['yurtIci'] === true
    && $o['uretSevk'] === false && $o['fiyatGonder'] === true && $o['fiyat'] === 12.5 && $o['kayitZorunlu'] === false);
ok('malinKodNo = ürün kataloğu TAM ad (Domates ↔ DOMATES → 40)', ($o['malinKodNo'] ?? null) === 40);
ok('satır = [{0, toplam_kg}]', $t['tohum']['satirlar'] === [['kunyeNo' => '0', 'miktar' => 950.0]]);
ok('plaka + belge no kolonlardan', $o['plaka'] === '07TST003' && $o['belgeNo'] === 'B-1');
ok('müstahsil ad ile TEK eşleşme → ikinciTc/ikinciAd/ikinciSifatId', ($o['ikinciTc'] ?? '') === $TC_A
    && ($o['ikinciAd'] ?? '') === 'Örnek Müstahsil Bir' && ($o['ikinciSifatId'] ?? null) === 7);
ok('doğum/cep havuzdan TC ile', ($o['ikinciDogumTarihi'] ?? '') === '1970-03-15' && ($o['ikinciCep'] ?? '') === '05001112233');
ok('eski kayıt notu', not_var($t, NOT_ESKI));
ok('eski kayıtta sıfat/işyeri UYDURULMAZ', !isset($o['sifatId']) && !isset($o['gidecekIsyeriId']));

$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => 'Satın Alım ← ÖRNEK MÜSTAHSİL BİR',
    'urun_ad' => 'DOMATES', 'toplam_kg' => 10]), null, false);
ok('büyük harfli eski ad da eşleşir (Türkçe İ)', ($t['tohum']['ortak']['ikinciTc'] ?? '') === $TC_A);

$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => 'Satın Alım ← Aynı Ad Soyad',
    'urun_ad' => 'DOMATES', 'toplam_kg' => 10]), null, false);
$o = $t['tohum']['ortak'];
ok('belirsiz ad (iki kişi) → karşı taraf BOŞ', !isset($o['ikinciTc']) && !isset($o['ikinciAd']) && !isset($o['ikinciSifatId'])
    && !isset($o['ikinciDogumTarihi']));
ok('belirsiz ad → karşı taraf notu', not_var($t, 'tek kayıtla eşleşmedi'));
$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => 'Satın Alım ← Hiç Olmayan Kişi',
    'urun_ad' => 'DOMATES', 'toplam_kg' => 10]), null, false);
ok('eşleşmeyen ad → karşı taraf BOŞ', !isset($t['tohum']['ortak']['ikinciTc']) && !isset($t['tohum']['ortak']['ikinciAd']));
$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => "Satın Alım ← $TC_A",
    'urun_ad' => 'DOMATES', 'toplam_kg' => 10]), null, false);
$o = $t['tohum']['ortak'];
ok('ad yerine TC yazılmış (11 hane) → ikinciTc, ad UYDURULMAZ', ($o['ikinciTc'] ?? '') === $TC_A && !isset($o['ikinciAd'])
    && ($o['ikinciDogumTarihi'] ?? '') === '1970-03-15');
$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIN_ALIM', 'ulke_ad' => 'Satın Alım ← Örnek',
    'urun_ad' => 'KAVUN', 'toplam_kg' => 10]), null, false);
ok('katalogda belirsiz ürün adı (KAVUN/Kavun) → malinKodNo BOŞ', !isset($t['tohum']['ortak']['malinKodNo']));
ok('ürün adı yine gösterim için taşınır', ($t['tohum']['ortak']['urunAd'] ?? '') === 'KAVUN');

$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIS', 'ulke_ad' => 'Rusya', 'urun_ad' => 'Biber',
    'toplam_kg' => 2000, 'fiyat' => 30]), null, false);
$o = $t['tohum']['ortak'];
ok('yurt dışı: ülke TAM ad → ulkeId 100, ulkeAd korunur', ($o['ulkeId'] ?? null) === 100 && ($o['ulkeAd'] ?? '') === 'Rusya');
ok('yurt dışı: tür Satış → 191, yurtIci yok', ($o['bildirimTuruId'] ?? null) === 191 && empty($o['yurtIci']));
ok('yurt dışı referanslı → PLAN (urunId 41, planKg 2000)', $t['plana'] === true && $t['tohum']['satirlar'] === []
    && ($o['urunId'] ?? null) === 41 && ($o['planKg'] ?? null) === 2000.0
    && ($o['planSorgu'] ?? null) === ['urunId' => 41, 'aySayisi' => 12, 'isletmeTuruId' => 0, 'sirala' => 'azalan']);
ok('yurt dışı: fiyat kolondan, karşı taraf yok', $o['fiyat'] === 30.0 && !isset($o['ikinciTc']));
ok('yurt dışı eski: plan + eski kayıt notu', not_var($t, NOT_PLAN) && not_var($t, NOT_ESKI));

$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SATIS', 'ulke_ad' => 'Rusya', 'urun_ad' => 'Olmayan Ürün',
    'toplam_kg' => 500, 'fiyat' => 30]), null, false);
ok('eski referanslı, ürün katalogda yok → plan YOK, ürün notu', $t['plana'] === false
    && !isset($t['tohum']['ortak']['planKg']) && !isset($t['tohum']['ortak']['urunId']) && not_var($t, NOT_URUN));

echo "\n── Eski kayıt: bildirim_turu NULL → önekten ──\n";
$t = hks_gonderim_tohumu($db, satir(['ulke_ad' => 'Üreticiden Sevk Alım ← Örnek Müstahsil Bir (Antalya/Kumluca)',
    'urun_ad' => 'İncir', 'toplam_kg' => 300, 'fiyat' => 40]), null, false);
$o = $t['tohum']['ortak'];
ok('NULL + "Üreticiden Sevk Alım ←" → tür 194, uretSevk, referanssız', ($o['bildirimTuruId'] ?? null) === 194
    && $o['uretSevk'] === true && $o['referanssiz'] === true && ($o['hedefAdres'] ?? false) === true);
ok('" (İl/İlçe)" eki atılıp ad eşleşir → ikinciTc', ($o['ikinciTc'] ?? '') === $TC_A);
ok('İncir ↔ İNCİR (Türkçe İ) → malinKodNo 42', ($o['malinKodNo'] ?? null) === 42);

$t = hks_gonderim_tohumu($db, satir(['ulke_ad' => 'Satın Alım ← Örnek Müstahsil Bir', 'urun_ad' => 'DOMATES',
    'toplam_kg' => 100, 'fiyat' => 5]), null, false);
ok('NULL + "Satın Alım ←" → 193', ($t['tohum']['ortak']['bildirimTuruId'] ?? null) === 193
    && $t['tohum']['ortak']['referanssiz'] === true && $t['tohum']['ortak']['uretSevk'] === false);

$t = hks_gonderim_tohumu($db, satir(['ulke_ad' => 'Yurt içi → Deneme Tarım Ltd', 'urun_ad' => 'DOMATES',
    'toplam_kg' => 700, 'fiyat' => 0]), null, false);
$o = $t['tohum']['ortak'];
ok('NULL + "Yurt içi →" + fiyat 0 → Sevk Etme (192), fiyatsız, kayıtlı zorunlu',
    ($o['bildirimTuruId'] ?? null) === 192 && $o['fiyatGonder'] === false && $o['kayitZorunlu'] === true && $o['fiyat'] === 0);
ok('Sevk Etme: VKN ad ile → ikinciTc + sıfat 9', ($o['ikinciTc'] ?? '') === $VKN && ($o['ikinciSifatId'] ?? null) === 9);
ok('Sevk Etme referanslı → plan', $t['plana'] === true && ($o['planKg'] ?? null) === 700.0);

$t = hks_gonderim_tohumu($db, satir(['ulke_ad' => 'Yurt içi → Deneme Tarım Ltd (İzmir/Bornova)', 'urun_ad' => 'DOMATES',
    'toplam_kg' => 50, 'fiyat' => 20]), null, false);
$o = $t['tohum']['ortak'];
ok('NULL + "Yurt içi →" + fiyat > 0 → yurt içi Satış (191)', ($o['bildirimTuruId'] ?? null) === 191
    && $o['yurtIci'] === true && $o['fiyatGonder'] === true && $o['kayitZorunlu'] === false);
ok('yurt içi: adres ekli ad → eksiz adla eşleşir', ($o['ikinciTc'] ?? '') === $VKN);

$t = hks_gonderim_tohumu($db, satir(['ulke_ad' => 'Irak', 'urun_ad' => 'Biber', 'toplam_kg' => 10, 'fiyat' => 3]), null, false);
ok('NULL + önek yok → yurt dışı Satış, ülke Irak → 101', ($t['tohum']['ortak']['bildirimTuruId'] ?? null) === 191
    && ($t['tohum']['ortak']['ulkeId'] ?? null) === 101 && empty($t['tohum']['ortak']['yurtIci']));

$t = hks_gonderim_tohumu($db, satir(['bildirim_turu' => 'SEVK_ETME', 'ulke_ad' => 'Yurt içi → Deneme Tarım Ltd',
    'urun_ad' => 'DOMATES', 'toplam_kg' => 10, 'fiyat' => 0]), null, false);
ok('kod SEVK_ETME → 192', ($t['tohum']['ortak']['bildirimTuruId'] ?? null) === 192);

echo "\n── Eski kayıt: firma değişimi / katalog yok ──\n";
$t = hks_gonderim_tohumu($db, $eskiSatin, 'f2', true);
ok('eski + firma değişti → not + sıfat/işyeri yok', not_var($t, NOT_FIRMA) && !isset($t['tohum']['ortak']['sifatId'])
    && !isset($t['tohum']['ortak']['gidecekIsyeriId']));
$GLOBALS['__DB']->exec("DELETE FROM hks_kv WHERE anahtar = 'listeler_cache'");
$t = hks_gonderim_tohumu($db, $eskiSatin, null, false);
$o = $t['tohum']['ortak'];
ok('katalog yok → tür/ürün id BOŞ (uydurulmaz)', !isset($o['bildirimTuruId']) && !isset($o['malinKodNo']));
ok('katalog yok → yine de karşı taraf havuzdan', ($o['ikinciTc'] ?? '') === $TC_A);
ok('katalog yok notu', not_var($t, 'Referans listeleri yüklü değil'));
hks_kv_yaz('listeler_cache', $KATALOG);

echo "\n── api.php / .htaccess (kaynak denetimi) ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pTaslakLib = strpos($api, "require_once __DIR__ . '/taslak_lib.php';");
$pLib = strpos($api, "require_once __DIR__ . '/tekrar_gonder_lib.php';");
ok('api.php tekrar_gonder_lib.php\'yi taslak_lib\'ten SONRA yükler', $pTaslakLib !== false && $pLib !== false && $pLib > $pTaslakLib);
ok("INSERT veri JSON'u kopyayı taşır", strpos($api,
    "json_encode(['yeniKunyeler' => \$yeniKunyeler, 'sonuclar' => \$sonuc['sonuclar'], 'kopya' => \$__kopya], JSON_UNESCAPED_UNICODE)") !== false);
$pCase = strpos($api, "case 'taslak_gonder':");
$pVeri = strpos($api, "\$veri = json_decode(\$t['veri'], true);", (int)$pCase);
$pKopya = strpos($api, '$__kopya = hks_gonderim_kopyasi(is_array($veri) ? $veri : []);', (int)$pCase);
$pPlan = strpos($api, '$__plan = hks_plan_kunye_coz($cfg, $ortak);', (int)$pCase);
$pAdres = strpos($api, "\$ortak['gidecekAdres'] = \$__adres;", (int)$pCase);
$pGonder = strpos($api, '$sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak, $__secenek);', (int)$pCase);
$pInsert = strpos($api, "INSERT INTO ' . hks_tablo('gonderilenler')", (int)$pCase);
ok('kopya taslak_gonder içinde, json_decode\'dan HEMEN sonra', $pCase !== false && $pVeri !== false && $pKopya !== false
    && $pKopya > $pVeri && $pKopya - $pVeri < 600);
ok('kopya plan çözümünden (hks_plan_kunye_coz) ÖNCE', $pKopya !== false && $pPlan !== false && $pKopya < $pPlan);
ok('kopya gidecekAdres eklenmesinden ÖNCE', $pKopya !== false && $pAdres !== false && $pKopya < $pAdres);
ok('kopya gönderimden ve INSERT\'ten ÖNCE', $pKopya < $pGonder && $pKopya < $pInsert);
ok('taslak_gonder kopya için başka değişiklik yapmaz (tek atama, tek kullanım)',
    substr_count($api, '$__kopya') === 2, (string)substr_count($api, '$__kopya'));

$pListe = strpos($api, "case 'gonderilenler':");
$pListeSon = strpos($api, "case 'gonderilen_tohum':");
$liste = ($pListe !== false && $pListeSon !== false) ? substr($api, $pListe, $pListeSon - $pListe) : '';
ok("liste ucu satır başına 'kopyaVar' döner", strpos($liste, "'kopyaVar' => !empty(\$veri['kopya'])") !== false);
ok('liste ucu kopyanın kendisini DÖNMEZ', !preg_match("/'kopya'\s*=>/", $liste));

$pTohum = strpos($api, "case 'gonderilen_tohum':");
$pTohumSon = $pTohum !== false ? strpos($api, "\n    case '", $pTohum + 10) : false;
$tohum = ($pTohum !== false && $pTohumSon !== false) ? substr($api, $pTohum, $pTohumSon - $pTohum) : '';
ok("'gonderilen_tohum' ucu var", $tohum !== '');
ok('tohum ucu yazma sorgusu içermez (INSERT/UPDATE/DELETE/REPLACE)', $tohum !== '' && !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $tohum));
ok('tohum ucu taslak yazmaz / göndermez / SOAP çağırmaz', $tohum !== ''
    && !preg_match('/hks_taslak_olustur|hks_bildirim_kaydet|hks_soap|hks_kv_yaz|hks_kisi_upsert|beyan_hks_/', $tohum));
ok('tohum ucu liste ile AYNI firma izolasyonu WHERE\'i', strpos($tohum,
    "WHERE id = ? AND (firma_id = ? OR (COALESCE(firma_id, \\'\\') = \\'\\' AND firma_ad = ?))") !== false
    && strpos($tohum, '$st->execute([$gid, $fid, $fad]);') !== false);
ok('liste ucunun izolasyon koşulu da aynı', strpos($liste, "WHERE firma_id = ? OR (COALESCE(firma_id, \\'\\') = \\'\\' AND firma_ad = ?)") !== false);
ok('bulunamazsa 404 + sözleşme mesajı', strpos($tohum,
    "hks_json_cikti(['hata' => 'Gönderim bulunamadı (veya bu firmaya ait değil).'], 404);") !== false);
ok('hedef firma hks_firma_bul ile doğrulanır (yoksa 400)', (bool)preg_match(
    "/if \(\\\$firmaDegisti && !hks_firma_bul\(\\\$hedef\)\) hks_json_cikti\(\[[^\]]*\], 400\);/", $tohum));
ok('tohum ucu hks_gonderim_tohumu çağırır', strpos($tohum, 'hks_json_cikti(hks_gonderim_tohumu($db, $row,') !== false);

// Lib denetimi YORUMSUZ kod üzerinde yapılır (yorumlar kuralı anlatırken bu
// adları anıyor — "taslak yazmanın TEK yolu hks_taslak_olustur" gibi).
$libHam = (string)file_get_contents("$KOK/halkayit/tekrar_gonder_lib.php");
$lib = '';
foreach (token_get_all($libHam) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $lib .= is_array($tok) ? $tok[1] : $tok;
}
ok('lib yazma sorgusu içermez', !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $lib));
ok('lib taslak yazmaz / göndermez / kv yazmaz', !preg_match('/hks_taslak_olustur\(|hks_bildirim_kaydet|hks_soap_cagir|hks_kv_yaz|hks_kisi_upsert\(|beyan_hks_/', $lib));
ok('lib çıktı basmaz / exit etmez', !preg_match('/\b(echo|print|exit|die)\b|header\(/', $lib));
ok('lib taslak_lib.php\'yi require ETMEZ (test edilebilirlik)', !preg_match('/require[^;]*taslak_lib/', $lib)
    && strpos($lib, "require_once __DIR__ . '/kisi_havuzu_lib.php';") !== false);

$ht = (string)file_get_contents("$KOK/halkayit/.htaccess");
ok('.htaccess tekrar_gonder_lib.php\'yi iki sözdiziminde kapatır',
    preg_match_all('/<FilesMatch "\^\([^"]*\btekrar_gonder_lib\b[^"]*\)\\\\\.php\$">/', $ht) === 2);

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
