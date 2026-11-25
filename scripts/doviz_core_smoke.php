<?php
// =========================================================
// scripts/doviz_core_smoke.php — Döviz & Günlük Kurlar çekirdek (config/doviz_core.php) testleri
//   php scripts/doviz_core_smoke.php   → çıkış kodu 0 = hepsi geçti
// Bellek içi SQLite + çevrimdışı TCMB XML kancası; canlı DB/ağ kullanmaz.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
ini_set('error_log', '/dev/null');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/doviz_core.php';

$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $n\n"; }
    else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; }
}

// SQLite bellek içi PDO + MySQL→SQLite DDL çeviricisi
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$GLOBALS['_doviz_ddl_calistir'] = static function (PDO $pdo, string $ad, string $ddl): void {
    $sql = preg_replace('/\s+ENGINE=.*$/s', '', $ddl) ?? $ddl;
    $sql = preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql) ?? $sql;
    $sql = preg_replace('/,\s*(UNIQUE\s+)?KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $sql) ?? $sql;
    $pdo->exec($sql);
    if (preg_match_all('/UNIQUE\s+KEY\s+`([^`]+)`\s*\(([^)]+)\)/i', $ddl, $um, PREG_SET_ORDER)) {
        foreach ($um as $m) {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS `{$m[1]}` ON `$ad` ({$m[2]})");
        }
    }
};

// Kullanıcı tarafından verilen gerçek TCMB 24.11.2026 XML örneği
$XML_24 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Tarih_Date Tarih="24.11.2026" Date="11/24/2026" Bulten_No="2026/221">
<Currency CrossOrder="0" Kod="USD" CurrencyCode="USD">
<Unit>1</Unit>
<Isim>ABD DOLARI</Isim>
<CurrencyName>US DOLLAR</CurrencyName>
<ForexBuying>50.5376</ForexBuying>
<ForexSelling>50.6286</ForexSelling>
<BanknoteBuying>50.5022</BanknoteBuying>
<BanknoteSelling>50.7046</BanknoteSelling>
<CrossRateUSD/>
<CrossRateOther/>
</Currency>
<Currency CrossOrder="9" Kod="EUR" CurrencyCode="EUR">
<Unit>1</Unit>
<Isim>EURO</Isim>
<CurrencyName>EURO</CurrencyName>
<ForexBuying>55.2190</ForexBuying>
<ForexSelling>55.3185</ForexSelling>
<BanknoteBuying>55.1804</BanknoteBuying>
<BanknoteSelling>55.4015</BanknoteSelling>
<CrossRateUSD/>
<CrossRateOther>1.0926</CrossRateOther>
</Currency>
<Currency CrossOrder="10" Kod="GBP" CurrencyCode="GBP">
<Unit>1</Unit>
<Isim>İNGİLİZ STERLİNİ</Isim>
<CurrencyName>POUND STERLING</CurrencyName>
<ForexBuying>65.8856</ForexBuying>
<ForexSelling>66.2290</ForexSelling>
<BanknoteBuying>65.8394</BanknoteBuying>
<BanknoteSelling>66.3284</BanknoteSelling>
<CrossRateUSD/>
<CrossRateOther>1.3059</CrossRateOther>
</Currency>
<Currency CrossOrder="5" Kod="JPY" CurrencyCode="JPY">
<Unit>100</Unit>
<Isim>JAPON YENİ</Isim>
<CurrencyName>JAPENESE YEN</CurrencyName>
<ForexBuying>31.6398</ForexBuying>
<ForexSelling>31.8493</ForexSelling>
<BanknoteBuying>31.5227</BanknoteBuying>
<BanknoteSelling>31.9703</BanknoteSelling>
<CrossRateUSD>159.34</CrossRateUSD>
<CrossRateOther/>
</Currency>
<Currency CrossOrder="0" Kod="XDR" CurrencyCode="XDR">
<Unit>1</Unit>
<Isim>ÖZEL ÇEKME HAKKI (SDR) </Isim>
<CurrencyName>SPECIAL DRAWING RIGHT (SDR) </CurrencyName>
<ForexBuying>67.8593</ForexBuying>
<ForexSelling/>
<BanknoteBuying/>
<BanknoteSelling/>
<CrossRateUSD/>
<CrossRateOther>1.34154</CrossRateOther>
</Currency>
</Tarih_Date>
XML;

$XML_23 = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Tarih_Date Tarih="23.11.2026" Date="11/23/2026" Bulten_No="2026/220">
<Currency CrossOrder="0" Kod="USD" CurrencyCode="USD">
<Unit>1</Unit>
<Isim>ABD DOLARI</Isim>
<CurrencyName>US DOLLAR</CurrencyName>
<ForexBuying>50.0000</ForexBuying>
<ForexSelling>50.1000</ForexSelling>
<BanknoteBuying>49.9500</BanknoteBuying>
<BanknoteSelling>50.2000</BanknoteSelling>
<CrossRateUSD/>
<CrossRateOther/>
</Currency>
<Currency CrossOrder="9" Kod="EUR" CurrencyCode="EUR">
<Unit>1</Unit>
<Isim>EURO</Isim>
<CurrencyName>EURO</CurrencyName>
<ForexBuying>55.5000</ForexBuying>
<ForexSelling>55.6000</ForexSelling>
<BanknoteBuying/>
<BanknoteSelling/>
<CrossRateUSD/>
<CrossRateOther>1.1098</CrossRateOther>
</Currency>
</Tarih_Date>
XML;

echo "=== 1. Şema & Tohum ===\n";
ok('kurulum öncesi doviz_hazir = false', !doviz_hazir($db));
ok('kurulum öncesi eksik tablolar = 2', doviz_eksik_tablolar($db) === ['doviz_tanimlar', 'doviz_kurlar']);
ok('DDL CREATE TABLE IF NOT EXISTS, FK/ALTER/ENUM yok', !array_filter(doviz_tablolar(), fn($d) => !str_starts_with($d, 'CREATE TABLE IF NOT EXISTS') || preg_match('/FOREIGN KEY|ALTER TABLE|ENUM\(/i', $d)));

$rap = doviz_migrate($db);
ok('doviz_migrate: 2 tablo oluşturuldu', count($rap) === 2 && !array_filter($rap, fn($r) => $r['durum'] !== 'olusturuldu'), json_encode($rap));
ok('doviz_hazir = true', doviz_hazir($db));
ok('ikinci migrate idempotent (hepsi var)', !array_filter(doviz_migrate($db), fn($r) => $r['durum'] !== 'var'));

$tanimlar = doviz_tanim_listesi($db);
ok('tohum: 8 döviz tanımı', count($tanimlar) === 8);
$aktifler = doviz_tanim_listesi($db, true);
ok('tohum aktif: TRY, USD, EUR, GBP (4 adet)', array_column($aktifler, 'kod') === ['TRY', 'USD', 'EUR', 'GBP']);

echo "=== 2. TCMB XML Ayrıştırma ===\n";
$p24 = doviz_tcmb_xml_coz($XML_24);
ok('XML çözüldü, tarih 2026-11-24, bülten 2026/221', ($p24['ok'] ?? false) && $p24['tarih'] === '2026-11-24' && $p24['bulten_no'] === '2026/221');
ok('5 döviz satırı çözüldü (USD, EUR, GBP, JPY, XDR)', count($p24['kurlar']) === 5);
$kByKod = [];
foreach ($p24['kurlar'] as $kr) $kByKod[$kr['kod']] = $kr;
ok('USD ForexSelling = 50.628600', ($kByKod['USD']['doviz_satis'] ?? null) === '50.628600');
ok('JPY Unit = 100', ($kByKod['JPY']['birim'] ?? 0) === 100 && ($kByKod['JPY']['doviz_satis'] ?? null) === '31.849300');
ok('XDR boş ForexSelling = null, ForexBuying = 67.859300', isset($kByKod['XDR']) && array_key_exists('doviz_satis', $kByKod['XDR']) && $kByKod['XDR']['doviz_satis'] === null && ($kByKod['XDR']['doviz_alis'] ?? null) === '67.859300');
ok('bozuk XML reddedilir', !(doviz_tcmb_xml_coz('<bozuk><xml')['ok'] ?? true));
ok('boş metin reddedilir', !(doviz_tcmb_xml_coz('')['ok'] ?? true));

echo "=== 3. TCMB Senkron & Günlük Değişim ===\n";
$GLOBALS['_doviz_http_get'] = static function (string $url) use ($XML_23, $XML_24): array {
    if (str_contains($url, '23112026.xml')) return ['ok' => true, 'status' => 200, 'body' => $XML_23, 'hata' => ''];
    if (str_contains($url, '24112026.xml') || str_contains($url, 'today.xml')) return ['ok' => true, 'status' => 200, 'body' => $XML_24, 'hata' => ''];
    return ['ok' => false, 'status' => 404, 'body' => '', 'hata' => 'HTTP 404'];
};

$s23 = doviz_tcmb_senkron($db, '2026-11-23', 1);
ok('23.11.2026 senkron ok (2 eklendi)', ($s23['ok'] ?? false) && $s23['eklenen'] === 2 && $s23['tarih'] === '2026-11-23');
$s24 = doviz_tcmb_senkron($db, '2026-11-24', 1);
ok('24.11.2026 senkron ok (5 eklendi)', ($s24['ok'] ?? false) && $s24['eklenen'] === 5 && $s24['tarih'] === '2026-11-24');

$liste24 = doviz_gunluk_liste($db, '2026-11-24', false);
$byKod = [];
foreach ($liste24 as $row) $byKod[$row['kod']] = $row;
ok('24.11.2026 USD önceki gün 50.1000 ve pozitif değişim % hesaplandı', isset($byKod['USD']) && abs((float)$byKod['USD']['degisim_yuzde'] - ((50.6286 - 50.1000) / 50.1000 * 100)) < 0.01);
ok('24.11.2026 EUR negatif değişim % hesaplandı', isset($byKod['EUR']) && (float)$byKod['EUR']['degisim_yuzde'] < 0);
ok('son tarih 2026-11-24', doviz_son_tarih($db) === '2026-11-24');
$mevcutTarihler = doviz_mevcut_tarihler($db);
$tarihListesi = isset($mevcutTarihler[0]) && is_array($mevcutTarihler[0]) ? array_column($mevcutTarihler, 'tarih') : $mevcutTarihler;
ok('mevcut tarihler [2026-11-24, 2026-11-23]', $tarihListesi === ['2026-11-24', '2026-11-23'], json_encode($mevcutTarihler));

echo "=== 4. Otonom Kur Çözümleme (Unit=100, TRY, Tatil Geri Dönüş) ===\n";
$try = doviz_kur_bul($db, 'TRY', '2026-11-24', null, false);
ok('TRY her zaman 1.000000', ($try['ok'] ?? false) && $try['kur_nokta'] === '1.000000' && $try['kur'] === '1,0000');

$usd = doviz_kur_bul($db, 'USD', '2026-11-24', null, false);
ok('USD 24.11.2026 = 50,6286 (Döviz Satış)', ($usd['ok'] ?? false) && $usd['kur'] === '50,6286' && $usd['tip'] === 'doviz_satis' && $usd['bulten_no'] === '2026/221');

$jpy = doviz_kur_bul($db, 'JPY', '2026-11-24', 'doviz_satis', false);
ok('JPY Unit=100 normalize: 31.8493 / 100 = 0.318493', ($jpy['ok'] ?? false) && abs((float)$jpy['kur_nokta'] - 0.318493) < 0.0001 && (int)$jpy['birim'] === 100, json_encode($jpy));

$xdr = doviz_kur_bul($db, 'XDR', '2026-11-24', 'doviz_satis', false);
ok('XDR ForexSelling boş → ForexBuying (67.8593) fallback', ($xdr['ok'] ?? false) && abs((float)$xdr['kur_nokta'] - 67.8593) < 0.0001, json_encode($xdr));

// Tatil/hafta sonu: 2026-11-26 (404 döner) → 24.11.2026'ya geri döner
$tatil = doviz_kur_bul($db, 'USD', '2026-11-26', null, false);
ok('Tatil günü 26.11.2026 → en yakın önceki kayıt 24.11.2026 döner', ($tatil['ok'] ?? false) && $tatil['tarih'] === '2026-11-24' && $tatil['istenen_tarih'] === '2026-11-26');

echo "=== 5. Manuel Kur Koruması & CRUD ===\n";
$mk = doviz_kur_kaydet($db, ['tarih' => '2026-11-24', 'kod' => 'USD', 'doviz_alis' => '51,0000', 'doviz_satis' => '51,2500'], 1);
ok('manuel kur kaydı başarılı', ($mk['ok'] ?? false));
$s24Tekrar = doviz_tcmb_senkron($db, '2026-11-24', 1, false);
ok('TCMB senkron (uzerineYaz=false) manuel USD satırını atlar', ($s24Tekrar['ok'] ?? false) && $s24Tekrar['atlanan'] === 1);
$usdMan = doviz_kur_bul($db, 'USD', '2026-11-24', null, false);
ok('manuel kur korundu (51,2500)', $usdMan['kur'] === '51,2500' && $usdMan['kaynak'] === 'manuel');

$s24Ez = doviz_tcmb_senkron($db, '2026-11-24', 1, true);
ok('TCMB senkron (uzerineYaz=true) üzerine yazar', ($s24Ez['ok'] ?? false) && $s24Ez['atlanan'] === 0);
$usdTcmb = doviz_kur_bul($db, 'USD', '2026-11-24', null, false);
ok('TCMB kuru geri geldi (50,6286)', $usdTcmb['kur'] === '50,6286' && $usdTcmb['kaynak'] === 'tcmb');

echo "=== 6. Döviz Tanım CRUD & TRY Koruması ===\n";
$tryRow = doviz_tanim_oku($db, 'TRY');
ok('TRY pasife alınamaz', !(doviz_tanim_durum($db, (int)$tryRow['id'], 'aktif', 0, 1)['ok'] ?? true));
ok('TRY silinemez', !(doviz_tanim_sil($db, (int)$tryRow['id'], 1)['ok'] ?? true));

$yeni = doviz_tanim_kaydet($db, ['kod' => 'NOK', 'ad' => 'Norveç Kronu', 'sembol' => 'kr', 'birim' => '1', 'ondalik' => '4', 'varsayilan_kur_tipi' => 'doviz_alis', 'tcmb_aktif' => '1', 'aktif' => '1', 'sira' => '90'], 1);
ok('yeni tanım NOK eklendi', ($yeni['ok'] ?? false) && !empty($yeni['id']));
ok('kullanılmayan NOK silinebilir', (doviz_tanim_sil($db, (int)$yeni['id'], 1)['ok'] ?? false));

$usdRow = doviz_tanim_oku($db, 'USD');
$usdSil = doviz_tanim_sil($db, (int)$usdRow['id'], 1);
ok('kur kaydı olan USD silinemez (409 kullanimda)', !($usdSil['ok'] ?? true) && ($usdSil['kod'] ?? '') === 'kullanimda');

echo "=== 7. Dinamik Aktif Para Birimleri (cari_para_birimleri) ===\n";
require_once $ROOT . '/config/cari_core.php';
$bosDb = new PDO('sqlite::memory:');
$bosDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ok('tablo yokken cari_para_birimleri varsayılan [TRY, USD, EUR]', cari_para_birimleri($bosDb, true) === ['TRY', 'USD', 'EUR']);
ok('doviz_tanimlar varken aktif dövizler döner [TRY, USD, EUR, GBP]', cari_para_birimleri($db, true) === ['TRY', 'USD', 'EUR', 'GBP']);
$rubRow = doviz_tanim_oku($db, 'RUB');
doviz_tanim_durum($db, (int)$rubRow['id'], 'aktif', 1, 1);
ok('RUB aktif edilince cari_para_birimleri listesine eklenir', in_array('RUB', cari_para_birimleri($db, true), true));
ok('cari_tutar_bicim GBP ve RUB sembollerini yazar', cari_tutar_bicim(123456, 'GBP') === '1.234,56 £' && cari_tutar_bicim(50000, 'RUB') === '500,00 ₽');

echo "=== 8. today.xml CDN Önbellek Geri Dönüşü (Doğrudan Tarihli Arşiv) ===\n";
$bugun = date('Y-m-d');
$bugunTr = date('d.m.Y');
$dunTr = date('d.m.Y', strtotime('-1 day'));
$bugunArsivUrl = doviz_tcmb_tarih_url($bugun);
$XML_STALE = '<?xml version="1.0" encoding="UTF-8"?><Tarih_Date Tarih="' . $dunTr . '" Bulten_No="2026/900"><Currency Kod="USD"><Unit>1</Unit><ForexBuying>50.0000</ForexBuying><ForexSelling>50.1000</ForexSelling></Currency></Tarih_Date>';
$XML_FRESH = '<?xml version="1.0" encoding="UTF-8"?><Tarih_Date Tarih="' . $bugunTr . '" Bulten_No="2026/901"><Currency Kod="USD"><Unit>1</Unit><ForexBuying>50.8000</ForexBuying><ForexSelling>50.9000</ForexSelling></Currency></Tarih_Date>';
$GLOBALS['_doviz_http_get'] = static function (string $url) use ($XML_STALE, $XML_FRESH, $bugunArsivUrl): array {
    if (str_contains($url, 'today.xml')) return ['ok' => true, 'status' => 200, 'body' => $XML_STALE, 'hata' => ''];
    if ($url === $bugunArsivUrl) return ['ok' => true, 'status' => 200, 'body' => $XML_FRESH, 'hata' => ''];
    return ['ok' => false, 'status' => 404, 'body' => '', 'hata' => 'HTTP 404'];
};
$sBugun = doviz_tcmb_senkron($db, $bugun, 1);
ok('today.xml eski dönünce doğrudan tarihli arşivden bugünün bülteni alınır', ($sBugun['ok'] ?? false) && $sBugun['tarih'] === $bugun && $sBugun['bulten_no'] === '2026/901');

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
