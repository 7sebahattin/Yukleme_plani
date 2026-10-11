<?php
// =========================================================
// scripts/hks_uretici_cep_test.php — Karşı taraf sıfatı "Üretici" → CEP ZORUNLU (v310)
//
// Canlı HKS, ikinci kişi sıfatı "Üretici" iken — kişi/firma GTB'de KAYITLI olsa da —
// cep telefonu yoksa satırı HataKodu 263 ("Lütfen üreticinin GSM numarasını doğru
// giriniz") ile reddediyor. Bu test GERÇEK hks_bildirim_dogrula()'yı (taslak_lib.php)
// ve GERÇEK hks_bildirim_xml()'i (hks_soap.php) çalıştırır. Ağ/DB YOK: taslak_lib'in
// config/db require'ları geçici kopyada çıkarılır, hks_kv_oku bellek içi taklittir.
//
//   php scripts/hks_uretici_cep_test.php   → çıkış 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$KOK = dirname(__DIR__);

$GLOBALS['__KV'] = ['listeler_cache' => ['sifatlar' => [
    ['id' => 2, 'ad' => 'İhracat'], ['id' => 4, 'ad' => 'Üretici'], ['id' => 7, 'ad' => 'Komisyoncu'],
    ['id' => 9, 'ad' => 'Üretici Örgütü'],
]]];
function hks_kv_oku($a, $v = null) { return $GLOBALS['__KV'][$a] ?? $v; }
function hks_kv_yaz($a, $v) { $GLOBALS['__KV'][$a] = $v; }

require_once "$KOK/halkayit/hks_soap.php";
$src = (string)file_get_contents("$KOK/halkayit/taslak_lib.php");
$src = preg_replace("#^require_once __DIR__ \. '/(config|db|hks_soap|dogum_deney_lib)\.php';.*$#m", '', $src);
$tmp = tempnam(sys_get_temp_dir(), 'hkstl');
file_put_contents($tmp, $src);
require $tmp;
@unlink($tmp);

$fail = 0;
function ok(string $ad, bool $c, string $ip = ''): void {
    global $fail; if (!$c) $fail++;
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ip);
}

// Kayıtlı firma (VKN, sentetik) — Satın Alım, karşı taraf sıfatı Üretici.
$satin = [
    'yurtIci' => true, 'referanssiz' => true, 'kayitZorunlu' => false, 'ikinciKayitsiz' => false,
    'uretSevk' => false, 'gidecekIsyeriId' => 42, 'isletmeTuruId' => 6,
    'sifatId' => 2, 'bildirimTuruId' => 195, 'turAd' => 'Satın Alım',
    'ikinciSifatId' => 4, 'ikinciTc' => '1234567890',
    'fiyatGonder' => true, 'fiyat' => 25, 'plaka' => '07ABC123',
    'malinNiteligi' => 1, 'malinKodNo' => 415, 'malinCinsiId' => 1760, 'uretimSekli' => 28,
    'miktarBirimId' => 74, 'uretimIlId' => 7, 'uretimIlceId' => 487, 'uretimBeldeId' => 2319,
];
$satir = [['kunyeNo' => '0', 'miktar' => 16700]];

echo "── Kural: hks_uretici_cep_gerekli ──\n";
ok('yurt içi + sıfat TAM "Üretici" → cep gerekli', hks_uretici_cep_gerekli($satin));
ok('"Üretici Örgütü" (farklı sıfat) → gerekmez', !hks_uretici_cep_gerekli(['ikinciSifatId' => 9] + $satin));
ok('başka sıfat (Komisyoncu) → gerekmez', !hks_uretici_cep_gerekli(['ikinciSifatId' => 7] + $satin));
ok('yurt dışı → gerekmez', !hks_uretici_cep_gerekli(['yurtIci' => false] + $satin));
$__kv = $GLOBALS['__KV']; $GLOBALS['__KV'] = [];
ok('katalog yoksa yeni engel YOK (HKS yine söyler)', !hks_uretici_cep_gerekli($satin));
$GLOBALS['__KV'] = $__kv;

echo "\n── Doğrulama (GERÇEK hks_bildirim_dogrula) ──\n";
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => $satin]);
ok('KAYITLI firma + Üretici + cep YOK → reddedilir (HataKodu 263 önlenir)', is_string($h) && str_contains($h, 'Üretici'), (string)$h);
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciCep' => '5395025214'] + $satin]);
ok('KAYITLI firma + Üretici + cep VAR (ad/doğum YOK) → geçer', $h === null, (string)$h);
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciCep' => '123'] + $satin]);
ok('geçersiz cep (3 rakam) → reddedilir', is_string($h));
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciSifatId' => 7] + $satin]);
ok('KAYITLI + başka sıfat + cep yok → eskisi gibi geçer', $h === null, (string)$h);
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciCep' => '5395025214', 'ikinciAd' => 'X'] + $satin]);
ok('kayıtlıda AD + cep ama doğum yok → kimlik bütünlüğü kuralı yine çalışır', is_string($h), (string)$h);
// Kayıtsız akış değişmedi: ad+cep+doğum zorunlu
$kayitsiz = ['ikinciKayitsiz' => true, 'ikinciTc' => '21352723454', 'ikinciSifatId' => 4] + $satin;
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciCep' => '05551112233'] + $kayitsiz]);
ok('KAYITSIZ + yalnız cep (ad/doğum yok) → hâlâ reddedilir', is_string($h), (string)$h);
$h = hks_bildirim_dogrula(['satirlar' => $satir, 'ortak' => ['ikinciAd' => 'Örnek Satıcı', 'ikinciCep' => '05551112233', 'ikinciDogumTarihi' => '1975-06-15'] + $kayitsiz]);
ok('KAYITSIZ + ad/cep/doğum tam → geçer', $h === null, (string)$h);

echo "\n── İstek XML'i (GERÇEK hks_bildirim_xml) ──\n";
$xml = hks_bildirim_xml($satir, ['ikinciCep' => '5395025214'] + $satin);
ok('kayıtlı Üretici: CepTel GİDİYOR', str_contains($xml, '<b:CepTel>5395025214</b:CepTel>'));
ok('kayıtlı Üretici: AdSoyad / DogumTarihi GİTMİYOR', !str_contains($xml, 'AdSoyad') && !str_contains($xml, 'DogumTarihi'));
ok('alfabetik sıra: CepTel < KisiSifat < TcKimlikVergiNo',
    strpos($xml, '<b:CepTel>') < strpos($xml, '<b:KisiSifat>4') && strpos($xml, '<b:KisiSifat>4') < strpos($xml, '<b:TcKimlikVergiNo>'));

echo "\n── api.php: gönderimde cep Kişi Havuzu'ndan tamamlanır (kaynak denetimi) ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
$pDoldur = strpos($api, "if (hks_uretici_cep_gerekli(\$ortak) && hks_cep_rakam(\$ortak['ikinciCep'] ?? '') === '')");
$pReval = strpos($api, '$__revalHata = hks_bildirim_dogrula(');
ok('havuzdan tamamlama var ve yeniden doğrulamadan ÖNCE', $pDoldur !== false && $pReval !== false && $pDoldur < $pReval);
ok('tamamlama hks_kisi_tc_ile ile (TC eşleşmesi)', str_contains(substr($api, (int)$pDoldur, 500), 'hks_kisi_tc_ile($db'));

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
