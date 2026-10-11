<?php
// beyan_whatsapp_metin() — saf biçimleyici testi (DB yok). php scripts/beyan_whatsapp_smoke.php
declare(strict_types=1);
$src = file_get_contents(__DIR__ . '/../config/helpers.php');
preg_match('/function fmt_kg.*?\n}\n/s', $src, $a);
preg_match('/function tr_upper.*?\n}\n/s', $src, $b);
preg_match('/function beyan_whatsapp_ayrac.*/s', $src, $c);
eval($a[0] . $b[0] . $c[0]);

$f = 0; $n = 0;
function ok(string $ad, bool $d): void { global $f, $n; $n++; if (!$d) $f++; printf("%-70s %s\n", $ad, $d ? 'OK' : '*** FAIL'); }

$tam = ['company_name' => 'LLC ZAPADNYE VOROTA',
  'company_address' => "SEPARATE SUBDIVISION: 143200, RUSSIAN FEDERATION, MOSCOW REGION, MOZHAISK, PROEZD MIRA, 3\nTIN/KPP: 5004025693/5028455001",
  'transport_type' => 'KARAYOLU (YEŞİLHAT)', 'line_type' => 'VİTAMİNRUS', 'party_no' => '61-10',
  'product_name' => 'NAR', 'product_variety' => 'HİCAZ', 'pallet_count' => 10, 'gross_kg' => 9450, 'net_kg' => 8370,
  'crate_count' => 2000, 'crate_type' => 'Tahta Kasa', 'unmatched_text' => "Numune\n1 tahta kasa\n5 kg brüt\n4 kg net",
  'exit_depot' => 'Hasyurt Fabrika', 'contact_person' => 'Yasin Bey', 'buyer_name' => 'Tolga Moskova', 'brand' => 'Asya',
  'note' => '61-08 parti ile birleşecektir !!!'];
$beklenen = <<<'TXT'
🏢 *LLC ZAPADNYE VOROTA*

📍 *SEPARATE SUBDIVISION*
143200, RUSSIAN FEDERATION,
MOSCOW REGION, MOZHAISK,
PROEZD MIRA, 3

📑 *TIN/KPP:* 5004025693/5028455001

━━━━━━━━━━━━━━━━

🚛 *KARAYOLU (YEŞİLHAT)*
🌿 *VİTAMİNRUS*

📝 *YENİ BEYAN*

━━━━━━━━━━━━━━━━

📦 *PARTİ NO:* 61-10

🍎 *ÜRÜN:* NAR HİCAZ
🚚 *MİKTAR:* 10 PALET

⚖️ *YÜK BİLGİLERİ*
▪️ *BRÜT:* 9.450 KG
▪️ *NET:* 8.370 KG
▪️ *TAHTA KASA:* 2.000 ADET

━━━━━━━━━━━━━━━━

🧪 *NUMUNE*
📦 1 Tahta Kasa
⚖️ Brüt: 5 KG
⚖️ Net: 4 KG

━━━━━━━━━━━━━━━━

🏭 *ÇIKIŞ:* HASYURT FABRİKA
👤 *İLGİLİ:* YASİN BEY
🤝 *ALICI:* TOLGA MOSKOVA
🏷️ *MARKA:* ASYA

━━━━━━━━━━━━━━━━

🚨 *ÖNEMLİ NOT* 🚨

⚠️ *61-08 PARTİ İLE BİRLEŞECEKTİR !!!*
TXT;
ok('örnek beyan → istenen şablonla BİREBİR', beyan_whatsapp_metin($tam) === $beklenen);

$bos = beyan_whatsapp_metin([]);
ok('boş beyan: yalnız YENİ BEYAN, ayraç/boş bölüm yok', $bos === '📝 *YENİ BEYAN*');
$m = beyan_whatsapp_metin(['party_no' => 'X-1', 'net_kg' => 100]);
ok('kısmi beyan: dolu bölümler var, boşlar (numune/not/marka) yok',
   strpos($m, 'PARTİ NO:* X-1') !== false && strpos($m, 'NET:* 100 KG') !== false
   && strpos($m, 'NUMUNE') === false && strpos($m, 'ÖNEMLİ NOT') === false && strpos($m, 'MARKA') === false);
ok('kasa tipi yoksa KASA etiketi', strpos(beyan_whatsapp_metin(['crate_count' => 5]), '▪️ *KASA:* 5 ADET') !== false);
$m = beyan_whatsapp_metin(['company_name' => 'A*B', 'brand' => 'x*y_z', 'note' => "bir\n\niki"]);
ok('* ve _ değer içinde temizlenir (biçim bozulmaz)', strpos($m, '🏢 *AB*') !== false && strpos($m, 'MARKA:* XY Z') !== false);
ok('çok satırlı not: her satır ayrı ⚠️', substr_count($m, '⚠️ *') === 2 && strpos($m, '⚠️ *BİR*') !== false && strpos($m, '⚠️ *İKİ*') !== false);
$m = beyan_whatsapp_metin(['company_address' => "Moscow, Lenina 5\nTIN/KPP: 123/456"]);
ok('başlıksız adres: 📍 *ADRES* + TIN ayrı satır', strpos($m, '📍 *ADRES*') !== false && strpos($m, '📑 *TIN/KPP:* 123/456') !== false);
$m = beyan_whatsapp_metin(['raw_text' => "x\nNUMUNE\n2 kasa\nBrüt: 10\nNet: 8"]);
ok('numune ham metinden (etiket: değer biçimi)', strpos($m, '📦 2 Tahta Kasa') !== false && strpos($m, 'Brüt: 10 KG') !== false && strpos($m, 'Net: 8 KG') !== false);
ok('numune kelimesi yoksa bölüm yok', strpos(beyan_whatsapp_metin(['raw_text' => "1 kasa 5 kg brüt"]), 'NUMUNE') === false);
printf("\n%d test, %d başarısız\n", $n, $f);
exit($f ? 1 : 0);
