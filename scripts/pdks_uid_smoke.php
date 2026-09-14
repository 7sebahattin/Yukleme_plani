<?php
// =========================================================
// scripts/pdks_uid_smoke.php — PDKS UID normalizasyon sözleşmesi testi
//
// SADECE CLI. Veritabanına HİÇ dokunmaz, ağ kullanmaz.
//   php scripts/pdks_uid_smoke.php     → çıkış kodu 0 = tüm testler geçti
//
// Faz 0'daki scripts/pdks_faz0_uid_kanit.php'nin YERİNİ ALIR. O betik
// algoritmanın bir KOPYASINI taşıyordu (kanıt amaçlıydı); Faz 1'de algoritma
// config/pdks.php'ye taşındığı için burada yalnız ONA karşı test yazılır.
// İki kopya bırakılsaydı ayrışır ve ayrışan taraf sessizce yanlış kart eşlerdi.
//
// ⚠ FAZ 1 DÜZELTMESİ (§8b): §2'deki testler eskiden bir kartın bayt-tersini
// de "aynı fiziksel kart" sayardı (ör. D7:7E:A8:25'in 631799511 ile aynı
// karta çözüldüğünü doğruluyordu). Bu YANLIŞTI — iki farklı fiziksel kartın
// kanonik UID'leri birbirinin bayt-tersi olabilir ve otomatik eşleme ikinci,
// gerçek kartın kaydını reddederdi. Bkz. config/pdks.php "UID NORMALİZASYONU"
// ve docs/PDKS_FAZ1_SEMA.md §6a.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/pdks.php';   // TEK OTORİTE

$hata = 0; $gecen = 0;
function dogrula(string $ad, $bulunan, $beklenen): void {
    global $hata, $gecen;
    $ok = $bulunan === $beklenen;
    $ok ? $gecen++ : $hata++;
    printf("%-60s %-22s %s\n", $ad, var_export($bulunan, true),
        $ok ? 'OK' : '*** HATA (beklenen ' . var_export($beklenen, true) . ')');
}

echo "\n=== 1. FİZİKSEL TEST KARTI ===\n";
echo "    USB HID okuyucu: 631799511   ·   Android teşhis: 25 A8 7E D7\n\n";
dogrula('0x25A87ED7 ondalığı',                pdks_uid_to_decimal('25A87ED7'), '631799511');
dogrula('631799511 → kanonik HEX',            pdks_uid_from_decimal('631799511'), '25A87ED7');
dogrula('25A87ED7 → 25A87ED7 (değişmez)',     pdks_uid_hex_normalize('25A87ED7'), '25A87ED7');
dogrula('0xD77EA825 ondalığı (ters)',         pdks_uid_to_decimal('D77EA825'), '3615402021');
// pdks_uid_reverse() yalnız TEŞHİS/GÖSTERİM amaçlıdır — kimlik eşleştirmede
// KULLANILMAZ (bkz. §8b). Burada yalnız kendi matematiğini doğruluyoruz.
dogrula('bayt-ters çevrimi (yalnız teşhis amaçlı)', pdks_uid_reverse('25A87ED7'), 'D77EA825');
dogrula('tersin tersi = kendisi',                   pdks_uid_reverse(pdks_uid_reverse('25A87ED7')), '25A87ED7');

echo "\n=== 2. HER KAYNAK KENDİ KANONİĞİNE DETERMİNİSTİK ÇÖZÜLÜR ===\n";
echo "    (YAZIM farkları — ayraç, büyük/küçük harf — normalize edilir;\n";
echo "     bu, bayt SIRASI eşitlemesi ile KARIŞTIRILMAMALI, bkz. §8b.)\n\n";
dogrula("USB '631799511' → kanon",              pdks_uid_adaylari('631799511',  'usb_decimal'), ['25A87ED7']);
dogrula("NFC '25 A8 7E D7' → aynı kanon (boşluk)", pdks_uid_adaylari('25 A8 7E D7','nfc_hex'),  ['25A87ED7']);
dogrula("NFC '25:A8:7E:D7' → aynı kanon (ayraç)",  pdks_uid_adaylari('25:A8:7E:D7','nfc_hex'),  ['25A87ED7']);
dogrula("NFC '25a87ed7' → aynı kanon (küçük harf)",pdks_uid_adaylari('25a87ed7','nfc_hex'),     ['25A87ED7']);

echo "\n=== 3. KABUL EDİLEN HEX BİÇİMLERİ ===\n";
foreach (['25A87ED7','25a87ed7','25:A8:7E:D7','25-A8-7E-D7','25 A8 7E D7','0x25A87ED7','  25A87ED7  '] as $b) {
    dogrula("normalize('$b')", pdks_uid_hex_normalize($b), '25A87ED7');
}

echo "\n=== 4. REDDEDİLEN BİÇİMLER ===\n";
dogrula("boş",                    pdks_uid_hex_normalize(''), null);
dogrula("hex olmayan 'ZZZZ'",     pdks_uid_hex_normalize('ZZZZ'), null);
dogrula("tek hane '25A87ED'",     pdks_uid_hex_normalize('25A87ED'), null);
dogrula("3 bayt (desteksiz)",     pdks_uid_hex_normalize('25A87E'), null);
dogrula("5 bayt (desteksiz)",     pdks_uid_hex_normalize('25A87ED7AA'), null);
dogrula("8 bayt (desteksiz)",     pdks_uid_hex_normalize('25A87ED7AABBCCDD'), null);
dogrula("null",                   pdks_uid_hex_normalize(null), null);

echo "\n=== 5. BAŞTAKİ SIFIR BAYTI KORUNUYOR ===\n";
dogrula('0x0025A87E ondalığı',        pdks_uid_to_decimal('0025A87E'), '2467966');
dogrula("2467966 → '0025A87E'",       pdks_uid_from_decimal('2467966'), '0025A87E');
dogrula("'1' → '00000001'",           pdks_uid_from_decimal('1'), '00000001');
dogrula("'0' → '00000000'",           pdks_uid_from_decimal('0'), '00000000');
dogrula("sıfır dolgulu '0631799511'", pdks_uid_from_decimal('0631799511'), '25A87ED7');
dogrula('4 baytlık kanon 8 hane',     strlen((string)pdks_uid_from_decimal('2467966')), 8);

echo "\n=== 6. 7 BAYT UID ===\n";
dogrula('7 bayt hex kabul',     pdks_uid_hex_normalize('04A2B3C4D5E6F0'), '04A2B3C4D5E6F0');
dogrula('7 bayt bayt-ters',     pdks_uid_reverse('04A2B3C4D5E6F0'), 'F0E6D5C4B3A204');
$d7 = pdks_uid_to_decimal('04A2B3C4D5E6F0');
dogrula('7 bayt ondalık dönüş', pdks_uid_from_decimal($d7, 7), '04A2B3C4D5E6F0');
dogrula('7 bayt = 14 hane',     strlen('04A2B3C4D5E6F0'), 14);
dogrula('bayt sayısı doğru',    pdks_uid_bayt_sayisi('04A2B3C4D5E6F0'), 7);

echo "\n=== 7. 10 BAYT UID (80 bit — PHP int64'e SIĞMAZ) ===\n";
$k10 = '0102030405060708090A';
$d10 = pdks_uid_to_decimal($k10);
dogrula('10 bayt hex kabul',       pdks_uid_hex_normalize($k10), $k10);
dogrula('10 bayt ondalık dönüş',   pdks_uid_from_decimal($d10, 10), $k10);
dogrula('10 bayt = 20 hane',       strlen($k10), 20);
dogrula('bayt sayısı doğru',       pdks_uid_bayt_sayisi($k10), 10);
dogrula('ondalık 25 haneyi aşmıyor (VARCHAR(25))', strlen(pdks_uid_to_decimal('FFFFFFFFFFFFFFFFFFFF')) <= 25, true);
// int64 olsaydı bozulurdu: (int) cast ile karşılaştır
dogrula('int cast BOZAR (bu yüzden kullanılmıyor)', (string)(int)$d10 !== $d10, true);

echo "\n=== 8. OTOMATİK TESPİT YASAK (karar #10) ===\n";
$ik = '12345678';
$hx = pdks_uid_adaylari($ik, 'nfc_hex')[0]     ?? null;
$dc = pdks_uid_adaylari($ik, 'usb_decimal')[0] ?? null;
printf("    '%s' HEX olarak    → %s\n", $ik, (string)$hx);
printf("    '%s' ONDALIK olarak → %s\n", $ik, (string)$dc);
dogrula('aynı metin FARKLI kartlara çözülüyor', $hx !== $dc, true);
dogrula('hex okuma',                            $hx, '12345678');
dogrula('ondalık okuma',                        $dc, '00BC614E');
dogrula('bilinmeyen kaynak → boş (fail-closed)', pdks_uid_adaylari($ik, 'bilinmiyor'), []);
dogrula('boş kaynak → boş',                      pdks_uid_adaylari($ik, ''), []);

echo "\n=== 8b. İKİ FARKLI FİZİKSEL KART — bayt-tersi ARTIK OTOMATİK AYNI KART SAYILMIYOR ===\n";
echo "    (Faz 1 düzeltmesi: 25A87ED7 ve D77EA825 birbirinin bayt-tersidir, ama\n";
echo "     bu tek başına aynı fiziksel kart oldukları anlamına GELMEZ — bir üçüncü\n";
echo "     parti uygulamanın baytları ters göstermesi de bunu KANITLAMAZ. İkisi de\n";
echo "     GERÇEK, birbirinden BAĞIMSIZ kartlar olarak sisteme girebilmelidir.)\n\n";
$kartA = pdks_uid_adaylari('631799511',  'usb_decimal');   // Kart A — USB'den okundu
$kartB = pdks_uid_adaylari('D7:7E:A8:25','nfc_hex');       // Kart B — Kart A'nın TAM TERSİ, farklı fiziksel kart
dogrula('Kart A kanoniği',                        $kartA, ['25A87ED7']);
dogrula("Kart B kanoniği (Kart A'nın bayt-tersi)", $kartB, ['D77EA825']);
dogrula('İkisinin aday kümesi KESİŞMİYOR',        array_intersect($kartA, $kartB), []);
dogrula('D77EA825, Kart A adayları arasında DEĞİL', in_array('D77EA825', $kartA, true), false);
dogrula('25A87ED7, Kart B adayları arasında DEĞİL', in_array('25A87ED7', $kartB, true), false);
// Asıl "iki ayrı kart aynı anda var olabiliyor mu" kanıtı bir VERİTABANI testidir
// (iki INSERT'in de başarılı olması gerekir) — bkz. scripts/pdks_db_smoke.php §4.

echo "\n=== 9. pdks_uid_reverse() — YALNIZ TEŞHİS ARACI, KİMLİK EŞLEŞTİRMESİ DEĞİL ===\n";
dogrula('A5A5A5A5 tersi kendisi (palindrom)', pdks_uid_reverse('A5A5A5A5'), 'A5A5A5A5');
dogrula('adaylar HER ZAMAN tek eleman (palindromda da)', count(pdks_uid_adaylari('A5A5A5A5', 'nfc_hex')), 1);
dogrula('pdks_uid_adaylari ARTIK asla 2 eleman döndürmez', count(pdks_uid_adaylari('25A87ED7', 'nfc_hex')), 1);

echo "\n=== 10. GÜRÜLTÜ TOLERANSI ===\n";
dogrula("binlik ayraçlı '631.799.511'", pdks_uid_from_decimal('631.799.511'), '25A87ED7');
dogrula("boşluklu ' 631799511 '",       pdks_uid_from_decimal(' 631799511 '), '25A87ED7');
dogrula("harfli '63179951X'",           pdks_uid_from_decimal('63179951X'), null);
dogrula("negatif '-631799511'",         pdks_uid_from_decimal('-631799511'), null);
dogrula("10 bayttan uzun ondalık",      pdks_uid_from_decimal(str_repeat('9', 40)), null);
dogrula("ondalık ama hex kaynağı",      pdks_uid_adaylari('631799511', 'nfc_hex'), []);  // 9 hane → tek sayı → geçersiz

echo "\n=== 11. SABİTLER ===\n";
dogrula('desteklenen bayt uzunlukları', PDKS_UID_BAYT, [4, 7, 10]);
dogrula('geçerli kaynaklar',            PDKS_UID_KAYNAKLARI, ['usb_decimal', 'nfc_hex']);
dogrula('cooldown varsayılanı 20 sn',   PDKS_COOLDOWN_SN, 20);

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $hata);
exit($hata === 0 ? 0 : 1);
