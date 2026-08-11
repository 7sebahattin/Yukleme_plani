<?php
// =========================================================
// scripts/hks_kunye_detay_test.php — Künye detay zenginleştirmesi testi
//
// SADECE CLI. Canlı HKS servisine HİÇBİR AĞ ÇAĞRISI yapmaz — hks_soap_cagir()
// (gerçek cURL) hiç çağrılmaz. Bunun yerine kılavuz 0.1.14'te belgelenen
// BildirimSorguDTO şemasına birebir uyan ELLE KURULMUŞ SOAP yanıtları test eder;
// hks_sadelestir() + hks_bloklar() + hks_bildirimsorgu_dto_ayikla() (SAF, ağsız
// fonksiyonlar) üzerinden gerçek üretim kod yolunu çalıştırır.
//
//   php scripts/hks_kunye_detay_test.php   → çıkış kodu 0 = tüm testler geçti
//
// Kapsam: alan adı çıkarımı (docx→kod transkripsiyon hatası asıl risktir),
// çok kayıtlı yanıtta blok sınırları karışmıyor, eksik/boş alanlarda çökmüyor,
// namespace önekleri doğru soyuluyor, hata modeli (ErrorModel) doğru okunuyor.
//
// KAPSAM DIŞI: hks_kunye_detaylari_getir()'in pencereleme/birleştirme orkestrasyonu
// test edilmez — hks_soap_cagir() gerçek cURL yaptığından mock'lanamaz (PHP aynı
// isimle fonksiyon yeniden tanımlamaya izin vermez). Bu orkestrasyon, ZATEN
// kanıtlanmış hks_stok_ozet()'in birebir aynı desenidir (hks_guvenli_pencereler +
// KunyeNo ile birleştirme) — yalnız try/catch ile pencere başına hataya
// dayanıklılık eklenmiştir. Canlıda doğrulama: README'nin "İlk test önerisi"
// adımlarıyla küçük bir tarih aralığında tek seferlik manuel deneme önerilir.
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../halkayit/hks_soap.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = '') {
    global $fail;
    if (!$c) $fail++;
    printf("%-58s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// ── Yardımcı: gerçek HKS yanıtlarındaki gibi ad alanı önekli (a:) XML üretir ──
function fakeDto(array $alanlar): string {
    $ic = '';
    foreach ($alanlar as $etiket => $deger) {
        $ic .= "<a:{$etiket}>{$deger}</a:{$etiket}>";
    }
    return "<a:BildirimSorguDTO>{$ic}</a:BildirimSorguDTO>";
}

function fakeEnvelope(string $islemKodu, string $govde): string {
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
         . '<a:BaseResponseMessageOf_BildirimSorguCevap xmlns:a="urn:x">'
         . "<a:IslemKodu>{$islemKodu}</a:IslemKodu>"
         . '<a:Sonuc><a:Bildirimler>' . $govde . '</a:Bildirimler></a:Sonuc>'
         . '</a:BaseResponseMessageOf_BildirimSorguCevap>'
         . '</s:Body></s:Envelope>';
}

// Üretim kod yolunun BİREBİR AYNISI: zarf → hks_sadelestir (a: önekleri soyulur)
// → hks_bloklar (yalnız İÇ içerik, sarmalayıcı etiketler DIŞARIDA kalır) → ayıkla.
// hks_bildirimsorgu_dto_ayikla() ÖNEKSİZ ve sarmalayıcısız içerik BEKLER — testte
// bu adımları atlayıp fakeDto() çıktısını doğrudan vermek yanlış pozitif/negatife
// yol açar (önekli "<a:KunyeNo>" asla "<KunyeNo>" regex'ine eşleşmez).
function parseTekDto(array $alanlar): array {
    $govde = fakeDto($alanlar);
    $duz = hks_sadelestir(fakeEnvelope('GTBWSRV0000001', $govde));
    $bloklar = hks_bloklar($duz, 'BildirimSorguDTO');
    if (count($bloklar) !== 1) throw new RuntimeException('beklenen 1 blok, bulunan: ' . count($bloklar));
    return hks_bildirimsorgu_dto_ayikla($bloklar[0]);
}

echo "── Tek DTO — tüm alanlar dolu (docx 0.1.14 BildirimSorguDTO şeması) ──\n";
$blok = fakeDto([
    'KunyeNo'                    => '1203829260020605791',
    'BildirimTarihi'             => '2026-08-06T12:04:41',
    'KalanMiktar'                => '11501.000',
    'MalinKodNo'                 => '5',
    'MalinAdi'                   => 'KİRAZ',
    'MalinCinsKodNo'             => '2',
    'MalinCinsi'                 => 'DİĞER',
    'MalinMiktari'               => '11501.000',
    'MalinSatisFiyati'           => '168.45',
    'MalinTuruKodNo'             => '1',
    'MalinTuru'                  => 'Geleneksel(Konvansiyonel)',
    'MiktarBirimId'              => '1',
    'MiktarBirimiAd'             => 'KG',
    'RusumMiktari'                => '23.87',
    'BildirimciTcKimlikVergiNo'  => '1111111111',
    'MalinSahibiTcKimlikVergiNo' => '2222222222',
    'UreticiTcKimlikVergiNo'     => '3333333333',
    'BildirimTuru'               => '4',
    'AracPlakaNo'                => '31DE681',
    'BelgeNo'                    => '',
    'BelgeTipi'                  => '0',
    'AnalizStatus'               => 'false',
]);
$xml = fakeEnvelope('GTBWSRV0000001', $blok);
$duz = hks_sadelestir($xml);

ok('IslemKodu başarı olarak okunuyor', hks_islem_kontrol($duz) === null);
$bloklar = hks_bloklar($duz, 'BildirimSorguDTO');
ok('tek blok bulundu', count($bloklar) === 1);

$d = hks_bildirimsorgu_dto_ayikla($bloklar[0]);
ok('kunyeNo doğru çıkarıldı',        $d['kunyeNo'] === '1203829260020605791');
ok('fiyat (MalinSatisFiyati) doğru', abs($d['fiyat'] - 168.45) < 0.001, (string)$d['fiyat']);
ok('rusum (RusumMiktari) doğru',     abs($d['rusum'] - 23.87) < 0.001, (string)$d['rusum']);
ok('aracPlaka doğru',                $d['aracPlaka'] === '31DE681');
ok('belgeNo boş (plaka doluyken)',   $d['belgeNo'] === '');
ok('belgeTipiId 0',                  $d['belgeTipiId'] === 0);
ok('bildirimTuruId doğru',           $d['bildirimTuruId'] === 4);
ok('malinSahibiTc doğru',            $d['malinSahibiTc'] === '2222222222');
ok('bildirimciTc doğru',             $d['bildirimciTc'] === '1111111111');
ok('ureticiTc doğru',                $d['ureticiTc'] === '3333333333');
ok('tarihSaat SAAT DAHİL (kırpılmamış)', $d['tarihSaat'] === '2026-08-06T12:04:41',
    'hks_kunye_penceresi bunu 10 karaktere kırpıyor, BURASI KIRPMAMALI');
ok('analiz false doğru okundu',      $d['analiz'] === false);

echo "\n── Plaka yerine belge no dolu (alternatif yol) ──\n";
$d2 = parseTekDto([
    'KunyeNo' => '999', 'MalinSatisFiyati' => '10', 'RusumMiktari' => '1',
    'AracPlakaNo' => '', 'BelgeNo' => 'IRS-2026-00042', 'BelgeTipi' => '2',
    'BildirimTuru' => '1', 'AnalizStatus' => 'true',
]);
ok('aracPlaka boş',        $d2['aracPlaka'] === '');
ok('belgeNo doğru',        $d2['belgeNo'] === 'IRS-2026-00042');
ok('belgeTipiId doğru',    $d2['belgeTipiId'] === 2);
ok('analiz true doğru',    $d2['analiz'] === true);

echo "\n── Eksik/boş alanlarda çökmüyor (kısmi HKS yanıtı) ──\n";
$dEksik = parseTekDto(['KunyeNo' => '555']);
ok('eksik alanlarda hata fırlatmıyor', $dEksik['kunyeNo'] === '555');
ok('eksik fiyat 0.0 olarak geliyor',   $dEksik['fiyat'] === 0.0);
ok('eksik rusum 0.0 olarak geliyor',   $dEksik['rusum'] === 0.0);
ok('eksik tarihSaat boş dize',         $dEksik['tarihSaat'] === '');

echo "\n── Çok kayıtlı yanıt — blok sınırları karışmıyor ──\n";
$govde = fakeDto(['KunyeNo' => 'A1', 'MalinSatisFiyati' => '10', 'MalinAdi' => 'ELMA'])
       . fakeDto(['KunyeNo' => 'A2', 'MalinSatisFiyati' => '20', 'MalinAdi' => 'ARMUT'])
       . fakeDto(['KunyeNo' => 'A3', 'MalinSatisFiyati' => '30', 'MalinAdi' => 'KAYISI']);
$xmlCok = fakeEnvelope('GTBWSRV0000001', $govde);
$duzCok = hks_sadelestir($xmlCok);
$bloklarCok = hks_bloklar($duzCok, 'BildirimSorguDTO');
ok('3 blok da ayrı ayrı bulundu', count($bloklarCok) === 3);
$sonuclar = array_map('hks_bildirimsorgu_dto_ayikla', $bloklarCok);
ok('1. kayıt doğru (A1/10)', $sonuclar[0]['kunyeNo'] === 'A1' && abs($sonuclar[0]['fiyat'] - 10) < 0.001);
ok('2. kayıt doğru (A2/20)', $sonuclar[1]['kunyeNo'] === 'A2' && abs($sonuclar[1]['fiyat'] - 20) < 0.001);
ok('3. kayıt doğru (A3/30)', $sonuclar[2]['kunyeNo'] === 'A3' && abs($sonuclar[2]['fiyat'] - 30) < 0.001);
ok('kayıtlar birbirine karışmadı (çapraz bulaşma yok)',
    !str_contains($duzCok, 'ELMAARMUT') && !str_contains($duzCok, 'ARMUTKAYISI'));

echo "\n── Hata modeli (ErrorModel) doğru okunuyor ──\n";
$xmlHata = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
    . '<a:Cevap xmlns:a="urn:x"><a:IslemKodu>GTBGLB00000011</a:IslemKodu>'
    . '<a:HataKodlari><a:ErrorModel><a:HataKodu>11</a:HataKodu><a:Mesaj>GTBGLB00000011</a:Mesaj></a:ErrorModel></a:HataKodlari>'
    . '</a:Cevap></s:Body></s:Envelope>';
$duzHata = hks_sadelestir($xmlHata);
$hataMsg = hks_islem_kontrol($duzHata);
ok('hata mesajı null DEĞİL',           $hataMsg !== null);
ok('hata mesajı açıklamayı içeriyor',  str_contains((string)$hataMsg, 'Kullanıcı bilgileri yanlış'));

echo "\n── api.php id→ad çözümleme (hks_liste_ad) ──\n";
// api.php'nin tamamını include ETMİYORUZ: hem oturum guard'ı + hks_govde() (STDIN
// bekler, CLI'de çalışmaz) hem de config.php gerçek MySQL bağlantısı ister (bu
// betik canlı DB'ye dokunmaz). Test edilecek SAF fonksiyonun BİREBİR KOPYASI
// izole çalıştırılır — api.php'deki hks_liste_ad() ile senkron tutulmalı.
function hks_liste_ad_test($cache, $listeAnahtari, $id) {
    if ($id === null || $id === '' || (int)$id <= 0) return null;
    if (!$cache || empty($cache[$listeAnahtari])) return null;
    foreach ($cache[$listeAnahtari] as $s) {
        if ((string)($s['id'] ?? '') === (string)$id) return (string)($s['ad'] ?? '');
    }
    return null;
}
$cacheOrnek = ['bildirimTurleri' => [['id' => 4, 'ad' => 'Satış'], ['id' => 5, 'ad' => 'Sevk Etme']]];
ok('bilinen id doğru ada çözülüyor',   hks_liste_ad_test($cacheOrnek, 'bildirimTurleri', 4) === 'Satış');
ok('bilinmeyen id null döner',         hks_liste_ad_test($cacheOrnek, 'bildirimTurleri', 999) === null);
ok('id=0 null döner (boş/atanmamış)',  hks_liste_ad_test($cacheOrnek, 'bildirimTurleri', 0) === null);
ok('önbellek yoksa çökmüyor',          hks_liste_ad_test(null, 'bildirimTurleri', 4) === null);
ok('liste anahtarı eksikse çökmüyor',  hks_liste_ad_test(['baska' => []], 'bildirimTurleri', 4) === null);

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
