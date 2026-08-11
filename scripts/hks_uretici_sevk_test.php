<?php
// =========================================================
// scripts/hks_uretici_sevk_test.php — "Üreticiden Sevk Alım" kılavuz 0.1.14
// uyum düzeltmesi testleri (P0/P1/P2).
//
// SADECE CLI. Canlı HKS servisine HİÇBİR AĞ ÇAĞRISI yapmaz, canlı DB'ye
// DOKUNMAZ — hks_kunye_detay_test.php ile AYNI desen: yalnız hks_soap.php
// require edilir (config.php / api.php DEĞİL — onlar gerçek MySQL bağlantısı
// ister). hks_soap.php'deki SAF (ağsız) fonksiyonlar DOĞRUDAN, gerçek üretim
// kod yoluyla test edilir: hks_kayitlimi_durumu, hks_kayitlikisi_dto_ayikla,
// hks_tc_normalize/hks_tc_esit, hks_uret_sevk_mi, hks_bildirim_xml.
//
// api.php'deki DB'ye bağımlı mantık (hks_kv_oku üzerinden çalışan
// hks_uretici_sifat_id, hks_tr_normalize, ve hks_bildirim_dogrula içindeki saf
// alt-kontroller) DB'siz test edilemediği için, hks_kunye_detay_test.php'nin
// zaten kullandığı "SAF fonksiyonun BİREBİR KOPYASI izole çalıştırılır" deseniyle
// yerel senkron kopyalar üzerinden doğrulanır — her kopyanın üstünde hangi
// api.php fonksiyonuyla senkron tutulması gerektiği açıkça yazılıdır.
//
//   php scripts/hks_uretici_sevk_test.php   → çıkış kodu 0 = tüm testler geçti
//
// KAPSAM DIŞI: hks_kayit_durumu()'nun SOAP/ağ hatası → UNKNOWN dalı ile
// hks_kayitli_kisi_sorgu()'nun gerçek cURL çağrısı gerçek network mock'lama
// olmadan test edilemez (hks_kunye_detaylari_getir orkestrasyonunun aynı
// gerekçeyle KAPSAM DIŞI bırakılmasıyla aynı durum). Bu istisna kod
// incelemesiyle doğrulanır: hks_kayit_durumu() içindeki try/catch bloğu
// hks_kayitli_kisi_sorgu()'nun fırlattığı HER istisnayı 'UNKNOWN' + sebep
// 'soap_hata' olarak yakalar (bkz. hks_soap.php).
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../halkayit/hks_soap.php';

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = '') {
    global $fail;
    if (!$c) $fail++;
    printf("%-66s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}

// ── Yardımcılar: gerçek HKS yanıtlarındaki gibi ad alanı önekli (a:) XML üretir ──
function fakeKkDto(array $alanlar, array $sifatlar = []): string {
    $ic = '';
    foreach ($alanlar as $etiket => $deger) {
        $ic .= "<a:{$etiket}>{$deger}</a:{$etiket}>";
    }
    $sifatXml = '';
    foreach ($sifatlar as $s) $sifatXml .= "<a:int>{$s}</a:int>";
    $ic .= "<a:Sifatlari>{$sifatXml}</a:Sifatlari>";
    return "<a:KayitliKisiSorguDTO>{$ic}</a:KayitliKisiSorguDTO>";
}
function fakeEnvelope(string $islemKodu, string $govde): string {
    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
         . '<a:BaseResponseMessageOf_KayitliKisiSorguCevap xmlns:a="urn:x">'
         . "<a:IslemKodu>{$islemKodu}</a:IslemKodu>"
         . '<a:Sonuc><a:TcKimlikVergiNolar>' . $govde . '</a:TcKimlikVergiNolar></a:Sonuc>'
         . '</a:BaseResponseMessageOf_KayitliKisiSorguCevap>'
         . '</s:Body></s:Envelope>';
}
// hks_kayitli_kisi_sorgu()'nun gerçek üretim kod yolunun BİREBİR AYNISI:
// zarf → hks_sadelestir → hks_bloklar('KayitliKisiSorguDTO') → hks_kayitlikisi_dto_ayikla.
function parseKkListe(string $govde): array {
    $duz = hks_sadelestir(fakeEnvelope('GTBWSRV0000001', $govde));
    $bloklar = hks_bloklar($duz, 'KayitliKisiSorguDTO');
    return array_map('hks_kayitlikisi_dto_ayikla', $bloklar);
}

// hks_kayit_durumu()'nun TC eşleştirme + UNKNOWN-varsayılan karar mantığının
// SENKRON KOPYASI (ağsız test edilebilmesi için — gerçek fonksiyon
// hks_kayitli_kisi_sorgu() üzerinden SOAP çağrısı yapar). hks_soap.php'deki
// hks_kayit_durumu() ile SENKRON TUTULMALI: TC eşleşen satır bulunursa onun
// 'durum'u, bulunamazsa 'UNKNOWN' döner.
function durumKarariTest(array $liste, string $tc): string {
    foreach ($liste as $row) {
        if ($row['tcVkn'] !== '' && hks_tc_esit($row['tcVkn'], $tc)) return $row['durum'];
    }
    return HKS_DURUM_UNKNOWN;
}

echo "── P0: KayitliKisiMi → tri-state (hks_kayitlimi_durumu, gerçek fonksiyon) ──\n";
ok('true → REGISTERED',           hks_kayitlimi_durumu('true')  === HKS_DURUM_REGISTERED);
ok('True (baş harf büyük) → REGISTERED', hks_kayitlimi_durumu('True') === HKS_DURUM_REGISTERED);
ok('false → NOT_REGISTERED',      hks_kayitlimi_durumu('false') === HKS_DURUM_NOT_REGISTERED);
ok('FALSE → NOT_REGISTERED',      hks_kayitlimi_durumu('FALSE') === HKS_DURUM_NOT_REGISTERED);
ok('null → UNKNOWN',              hks_kayitlimi_durumu(null)    === HKS_DURUM_UNKNOWN);
ok('boş dize → UNKNOWN',          hks_kayitlimi_durumu('')      === HKS_DURUM_UNKNOWN);
ok('"1" → UNKNOWN (boolean değil, TAHMİN yapılmaz)', hks_kayitlimi_durumu('1') === HKS_DURUM_UNKNOWN);
ok('bozuk XML parçası → UNKNOWN', hks_kayitlimi_durumu('<foo/>') === HKS_DURUM_UNKNOWN);

echo "\n── Senaryo 1: KayitliKisiMi=false, Sifatlari=null (kılavuz örneği) ──\n";
$govde1 = fakeKkDto(['TcKimlikVergiNo' => '11111111110', 'KayitliKisiMi' => 'false']);
$liste1 = parseKkListe($govde1);
ok('tek DTO bulundu', count($liste1) === 1);
ok('durum NOT_REGISTERED',        $liste1[0]['durum'] === HKS_DURUM_NOT_REGISTERED);
ok('kayitliMi=false',             $liste1[0]['kayitliMi'] === false);
ok('sifatlar boş (Sifatlari null/etiketsiz)', $liste1[0]['sifatlar'] === []);
ok('durumKararı: NOT_REGISTERED', durumKarariTest($liste1, '11111111110') === HKS_DURUM_NOT_REGISTERED);

echo "\n── Senaryo 2: KayitliKisiMi=true ──\n";
$govde2 = fakeKkDto(['TcKimlikVergiNo' => '22222222220', 'KayitliKisiMi' => 'true'], [5, 12]);
$liste2 = parseKkListe($govde2);
ok('durum REGISTERED',            $liste2[0]['durum'] === HKS_DURUM_REGISTERED);
ok('kayitliMi=true',              $liste2[0]['kayitliMi'] === true);
ok('sifatlar doğru ayrıştırıldı', $liste2[0]['sifatlar'] === [5, 12]);
ok('durumKararı: REGISTERED (Üreticiden Sevk Alım burada REDDEDİLMELİ)',
    durumKarariTest($liste2, '22222222220') === HKS_DURUM_REGISTERED);

echo "\n── Senaryo 3: Başarılı IslemKodu + BOŞ DTO listesi → UNKNOWN (P0 KRİTİK) ──\n";
$listeBos = parseKkListe('');   // hiç <a:KayitliKisiSorguDTO> yok
ok('liste boş döndü',             count($listeBos) === 0);
ok('durumKararı: UNKNOWN (ASLA NOT_REGISTERED değil — eski hata buydu)',
    durumKarariTest($listeBos, '33333333330') === HKS_DURUM_UNKNOWN);

echo "\n── Senaryo 4: Yanıt dolu ama istenen TC eşleşmiyor → UNKNOWN ──\n";
$govde4 = fakeKkDto(['TcKimlikVergiNo' => '99999999990', 'KayitliKisiMi' => 'false']);
$liste4 = parseKkListe($govde4);
ok('durumKararı (yanlış TC ile sorgu): UNKNOWN',
    durumKarariTest($liste4, '11111111111') === HKS_DURUM_UNKNOWN);
ok('durumKararı (doğru TC ile sorgu): NOT_REGISTERED',
    durumKarariTest($liste4, '99999999990') === HKS_DURUM_NOT_REGISTERED);

echo "\n── Senaryo 5: KayitliKisiMi alanı EKSİK → UNKNOWN ──\n";
$govde5 = fakeKkDto(['TcKimlikVergiNo' => '44444444440']);   // KayitliKisiMi hiç yok
$liste5 = parseKkListe($govde5);
ok('durum UNKNOWN (alan eksik)',  $liste5[0]['durum'] === HKS_DURUM_UNKNOWN);
ok('kayitliMi=false (yalnız kolaylık alanı — durum otorite)', $liste5[0]['kayitliMi'] === false);

echo "\n── Senaryo 6: Servis hatası → hks_islem_kontrol yakalar (UNKNOWN'a giden yol) ──\n";
$xmlHata = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
    . '<a:Cevap xmlns:a="urn:x"><a:IslemKodu>GTBGLB00000001</a:IslemKodu>'
    . '<a:HataKodlari><a:ErrorModel><a:HataKodu>1</a:HataKodu><a:Mesaj>GTBGLB00000001</a:Mesaj></a:ErrorModel></a:HataKodlari>'
    . '</a:Cevap></s:Body></s:Envelope>';
$duzHata = hks_sadelestir($xmlHata);
ok('IslemKodu başarısız algılanıyor (hks_kayit_durumu bunu catch→UNKNOWN yapar)',
    hks_islem_kontrol($duzHata) !== null);
// NOT: hks_kayitli_kisi_sorgu() bu durumda İSTİSNA fırlatır (throw new Exception),
// hks_kayit_durumu() bunu try/catch ile UNKNOWN'a çevirir — gerçek SOAP çağrısı
// gerektirdiği için burada ayrıca simüle edilmiyor (bkz. dosya başı KAPSAM DIŞI notu).

echo "\n── P2: TC/VKN normalizasyonu ve eşleşmesi (yalnız format, fuzzy DEĞİL) ──\n";
ok('aynı TC eşleşir',                 hks_tc_esit('12345678901', '12345678901'));
ok('boşluklu/tireli TC normalize edilip eşleşir', hks_tc_esit('123 456 789-01', '12345678901'));
ok('farklı TC eşleşmez (fuzzy YOK)',  !hks_tc_esit('12345678901', '12345678902'));
ok('boş TC hiçbir şeyle eşleşmez',    !hks_tc_esit('', ''));
ok('normalize yalnız rakam bırakır',  hks_tc_normalize('12-345 678.90') === '1234567890');

echo "\n── Regresyon: hks_uret_sevk_mi() mantığı değişmedi (api.php ile SENKRON KOPYA) ──\n";
// hks_uret_sevk_mi() api.php'de tanımlı (DB'ye bağımlı config.php zincirini
// çeker) — burada BİREBİR KOPYASI izole test edilir (hks_kunye_detay_test.php
// içindeki hks_liste_ad_test() ile AYNI desen).
function uretSevkMiTest($o) {
    if (!empty($o['uretSevk'])) return true;
    $ad = trim((string)($o['turAd'] ?? ''));
    return $ad !== '' && mb_stripos($ad, 'üretici', 0, 'UTF-8') !== false;
}
ok('uretSevk bayrağı ile true',       uretSevkMiTest(['uretSevk' => true]));
ok('turAd "Üreticiden Sevk Alım" ile true (eski taslak uyumu)',
    uretSevkMiTest(['turAd' => 'Üreticiden Sevk Alım']));
ok('Satın Alım ile false',            !uretSevkMiTest(['turAd' => 'Satın Alım']));
ok('Sevk Etme ile false (üretici geçmiyor)', !uretSevkMiTest(['turAd' => 'Sevk Etme']));

echo "\n── P1: Üretici sıfatı — TAM eşleşme (api.php ile SENKRON KOPYA) ──\n";
// SENKRON KOPYA — api.php: hks_tr_normalize() + hks_uretici_sifat_id(). Gerçek
// fonksiyonlar hks_kv_oku() (DB) kullandığından burada ağsız/DB'siz simüle edilir.
function trNormTest($s) {
    $s = str_replace(['İ', 'I'], ['i', 'ı'], (string)$s);
    return mb_strtolower($s, 'UTF-8');
}
function ureticiSifatIdTest(array $sifatlar) {
    foreach ($sifatlar as $s) {
        if (trNormTest($s['ad'] ?? '') === 'üretici') return (int)($s['id'] ?? 0);
    }
    return null;
}
$katalogTam = [['id' => 7, 'ad' => 'Üretici'], ['id' => 8, 'ad' => 'Üretici Birliği']];
$katalogSadeceBenzer = [['id' => 8, 'ad' => 'Üretici Birliği'], ['id' => 9, 'ad' => 'Üretici Örgütü']];
ok('tam "Üretici" bulunur (id 7)',        ureticiSifatIdTest($katalogTam) === 7);
ok('yalnız "Üretici Birliği/Örgütü" varsa null (P1 — artık kabul EDİLMEZ)',
    ureticiSifatIdTest($katalogSadeceBenzer) === null);
ok('boş katalogda null',                  ureticiSifatIdTest([]) === null);
// Gönderim kararı: yalnız TAM eşleşen ID kabul edilir.
$ureticiId = ureticiSifatIdTest($katalogTam);
ok('"Üretici" (id 7) gönderilirse KABUL',      $ureticiId !== null && 7 === $ureticiId);
ok('"Üretici Birliği" (id 8) gönderilirse RET', $ureticiId !== null && 8 !== $ureticiId);

echo "\n── P1: Referans künye — Üreticiden Sevk Alım'da '0' zorunlu (api.php ile SENKRON KOPYA) ──\n";
// SENKRON KOPYA — api.php: hks_bildirim_dogrula() içindeki referans künye döngüsü.
function referanslıMı(array $satirlar): bool {
    foreach ($satirlar as $s) {
        if (ltrim(preg_replace('/\D/', '', (string)($s['kunyeNo'] ?? '0')), '0') !== '') return true;
    }
    return false;
}
ok('kunyeNo="0" → referanssız (KABUL)',      !referanslıMı([['kunyeNo' => '0']]));
ok('kunyeNo="" → referanssız (KABUL)',       !referanslıMı([['kunyeNo' => '']]));
ok('kunyeNo="000" → referanssız (KABUL)',    !referanslıMı([['kunyeNo' => '000']]));
ok('kunyeNo="123456" → referanslı (RET)',    referanslıMı([['kunyeNo' => '123456']]));

echo "\n── P1/9: Kayıtsız üretici için gidecek yer payload'ı — hks_bildirim_xml() GERÇEK fonksiyon ──\n";
$ortakUretici = [
    'yurtIci' => true, 'referanssiz' => true, 'hedefAdres' => true,
    'ilId' => 6, 'ilceId' => 60, 'beldeId' => 600, 'isletmeTuruId' => 5,
    'sifatId' => 1, 'bildirimTuruId' => 4,
    'ikinciSifatId' => 7, 'ikinciTc' => '11111111110',
    'ikinciAd' => 'Test Üretici', 'ikinciCep' => '05551112233',
    'fiyatGonder' => true, 'fiyat' => 12.5, 'plaka' => '34ABC34',
    // Referanssız mal tanımı (docx "Referanssız Bildirimlerde") — bu testin
    // odağı değil, yalnız hks_bildirim_xml()'in o dalını hatasız çalıştırmak için.
    'malinNiteligi' => 1, 'malinKodNo' => 5, 'malinCinsiId' => 2,
    'uretimSekli' => 1, 'miktarBirimId' => 1,
    'uretimIlId' => 6, 'uretimIlceId' => 60, 'uretimBeldeId' => 600,
];
$xmlUretici = hks_bildirim_xml([['kunyeNo' => '0', 'miktar' => 100]], $ortakUretici);
ok('GidecekYerIlId=6 gönderiliyor',       str_contains($xmlUretici, '<b:GidecekYerIlId>6</b:GidecekYerIlId>'));
ok('GidecekYerIlceId=60 gönderiliyor',    str_contains($xmlUretici, '<b:GidecekYerIlceId>60</b:GidecekYerIlceId>'));
ok('GidecekYerBeldeId=600 gönderiliyor',  str_contains($xmlUretici, '<b:GidecekYerBeldeId>600</b:GidecekYerBeldeId>'));
ok('GidecekYerIsletmeTuruId=5 gönderiliyor', str_contains($xmlUretici, '<b:GidecekYerIsletmeTuruId>5</b:GidecekYerIsletmeTuruId>'));
ok('GidecekIsyeriId ASLA gönderilmiyor (P1 kritik düzeltme)',
    !str_contains($xmlUretici, 'GidecekIsyeriId'));
ok('ReferansBildirimKunyeNo=0 (referanssız)', str_contains($xmlUretici, '<a:ReferansBildirimKunyeNo>0</a:ReferansBildirimKunyeNo>'));

echo "\n── P1/10: Kayıtsız ikinci kişi iletişim bilgileri — Eposta ASLA, diğerleri VAR ──\n";
ok('AdSoyad gönderiliyor',    str_contains($xmlUretici, '<b:AdSoyad>Test Üretici</b:AdSoyad>'));
ok('CepTel yalnız rakam gönderiliyor', str_contains($xmlUretici, '<b:CepTel>05551112233</b:CepTel>'));
ok('YurtDisiMi=false gönderiliyor',    str_contains($xmlUretici, '<b:YurtDisiMi>false</b:YurtDisiMi>'));
ok('Eposta HİÇBİR ZAMAN gönderilmiyor (kılavuz: zorunlu değil)',
    !str_contains($xmlUretici, 'Eposta'));

echo "\n── Regresyon: kayıtlı ikinci kişi (Satın Alım) hâlâ GidecekIsyeriId kullanıyor ──\n";
$ortakSatin = [
    'yurtIci' => true, 'referanssiz' => true,   // hedefAdres YOK → kayıtlı dalı
    'gidecekIsyeriId' => 42, 'isletmeTuruId' => 5,
    'sifatId' => 1, 'bildirimTuruId' => 3,
    'ikinciSifatId' => 3, 'ikinciTc' => '22222222220',
    'fiyatGonder' => true, 'fiyat' => 8, 'plaka' => '06XYZ06',
    'malinNiteligi' => 1, 'malinKodNo' => 5, 'malinCinsiId' => 2,
    'uretimSekli' => 1, 'miktarBirimId' => 1,
    'uretimIlId' => 6, 'uretimIlceId' => 60, 'uretimBeldeId' => 600,
];
$xmlSatin = hks_bildirim_xml([['kunyeNo' => '0', 'miktar' => 50]], $ortakSatin);
ok('GidecekIsyeriId=42 gönderiliyor (DEĞİŞMEDİ)', str_contains($xmlSatin, '<b:GidecekIsyeriId>42</b:GidecekIsyeriId>'));
ok('GidecekYerIlId gönderilmiyor (kayıtlı dalı)', !str_contains($xmlSatin, 'GidecekYerIlId'));
ok('AdSoyad/CepTel gönderilmiyor (kayıtlıda opsiyonel, girilmedi)',
    !str_contains($xmlSatin, 'AdSoyad') && !str_contains($xmlSatin, 'CepTel'));

echo "\n── Regresyon: yurt dışı Satış (mevcut export) davranışı DEĞİŞMEDİ ──\n";
$ortakIhracat = [
    'sifatId' => 2, 'bildirimTuruId' => 1, 'isletmeTuruId' => 5,
    'ulkeId' => 9, 'fiyat' => 100, 'plaka' => '35AA35',
];
$xmlIhracat = hks_bildirim_xml([['kunyeNo' => '123456', 'miktar' => 500]], $ortakIhracat);
ok('YurtDisiMi=true gönderiliyor',   str_contains($xmlIhracat, '<b:YurtDisiMi>true</b:YurtDisiMi>'));
ok('GidecekUlkeId=9 gönderiliyor',   str_contains($xmlIhracat, '<b:GidecekUlkeId>9</b:GidecekUlkeId>'));
ok('ReferansBildirimKunyeNo=123456 (referanslı, DEĞİŞMEDİ)',
    str_contains($xmlIhracat, '<a:ReferansBildirimKunyeNo>123456</a:ReferansBildirimKunyeNo>'));

echo $fail === 0 ? "\n>>> TÜM TESTLER GEÇTİ\n" : "\n>>> $fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
