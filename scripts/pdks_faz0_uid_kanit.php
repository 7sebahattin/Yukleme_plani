<?php
// =========================================================
// scripts/pdks_faz0_uid_kanit.php — FAZ 0 · UID normalizasyon KANITI
//
// SADECE CLI. Veritabanına HİÇ dokunmaz, ağ kullanmaz, üretim kodu değildir.
//   php scripts/pdks_faz0_uid_kanit.php     → çıkış kodu 0 = tüm iddialar doğrulandı
//
// AMACI: docs/PDKS_NFC_YOL_HARITASI.md §C'deki UID dönüşüm iddialarını
// çalıştırılabilir biçimde kanıtlamak — şema dondurulmadan önce.
//
// ⚠ FAZ 1 NOTU: Aşağıdaki fonksiyonlar onaydan sonra AYNEN config/pdks.php'ye
// TAŞINACAK ve bu betik onları require edip yalnız test edecek. İKİNCİ BİR KOPYA
// BIRAKMAYIN — iki kopya ayrışır ve ayrışan taraf sessizce yanlış kart eşler.
//
// ⚠ bcmath/gmp YOK varsayımıyla yazıldı (bu ortamda ikisi de yoktu; paylaşımlı
// hostingde de garanti değil). 10 baytlık UID (80 bit) PHP tamsayısına sığmaz,
// bu yüzden ondalık→hex dönüşümü saf string aritmetiğiyle yapılır.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

// ─────────────────────────────────────────────────────────
// ALGORİTMA (Faz 1'de config/pdks.php'ye taşınacak)
// ─────────────────────────────────────────────────────────

/** Desteklenen UID uzunlukları — bayt cinsinden. ISO/IEC 14443-3: tek/çift/üçlü kaskad. */
const PDKS_UID_BAYT = [4, 7, 10];

/**
 * Ham HEX gösterimini kanona çevirir.
 * Kabul: "25A87ED7" · "25a87ed7" · "25:A8:7E:D7" · "25-A8-7E-D7" · "25 A8 7E D7" · "0x25A87ED7"
 * Ret  : "25A87ED7"  · geçersizse null
 */
function pdks_uid_hex_normalize(?string $ham): ?string
{
    if ($ham === null) return null;
    $s = strtoupper(trim($ham));
    $s = str_replace([':', '-', '.', ' ', "\t", "\xc2\xa0"], '', $s);
    if (str_starts_with($s, '0X')) $s = substr($s, 2);
    if ($s === '' || !preg_match('/^[0-9A-F]+$/', $s)) return null;
    if (strlen($s) % 2 !== 0) return null;                       // tam bayt olmalı
    if (!in_array(intdiv(strlen($s), 2), PDKS_UID_BAYT, true)) return null;
    return $s;
}

/** Ondalık STRING'i 16'lık tabana çevirir (bcmath/gmp gerektirmez). */
function pdks_dec_to_hex_string(string $dec): string
{
    $dec = ltrim($dec, '0');
    if ($dec === '') return '0';
    $hex = '';
    while ($dec !== '') {
        $kalan = 0;
        $bolum = '';
        $n = strlen($dec);
        for ($i = 0; $i < $n; $i++) {
            $cur     = $kalan * 10 + (int)$dec[$i];
            $basamak = intdiv($cur, 16);
            $kalan   = $cur % 16;
            if ($bolum !== '' || $basamak !== 0) $bolum .= (string)$basamak;
        }
        $hex = strtoupper(dechex($kalan)) . $hex;
        $dec = $bolum;
    }
    return $hex;
}

/**
 * USB HID okuyucunun yazdığı ONDALIK değeri kanonik HEX'e çevirir.
 * $bayt verilmezse değere sığan en küçük desteklenen uzunluk seçilir.
 * Baştaki sıfır baytları ondalıkta KAYBOLDUĞU için sola sıfır doldurulur.
 */
function pdks_uid_from_decimal(?string $ham, ?int $bayt = null): ?string
{
    if ($ham === null) return null;
    $s = trim($ham);
    $s = str_replace([' ', '.', ',', "\t", "\xc2\xa0"], '', $s);   // binlik ayracı da temizle
    if ($s === '' || !preg_match('/^[0-9]+$/', $s)) return null;

    $hex = pdks_dec_to_hex_string($s);
    if ($hex === '0') $hex = '';                                   // UID 0 → tümü sıfır bayt

    if ($bayt === null) {
        $gereken = (int)ceil(max(1, strlen($hex)) / 2);
        $bayt = null;
        foreach (PDKS_UID_BAYT as $b) { if ($gereken <= $b) { $bayt = $b; break; } }
        if ($bayt === null) return null;                           // 10 bayttan uzun → geçersiz
    }
    if (!in_array($bayt, PDKS_UID_BAYT, true)) return null;
    if (strlen($hex) > $bayt * 2) return null;                     // istenen uzunluğa sığmıyor

    return str_pad($hex, $bayt * 2, '0', STR_PAD_LEFT);
}

/** Kanonik HEX'i BAYT bazında ters çevirir (nibble değil). */
function pdks_uid_reverse(string $kanonik): string
{
    $out = '';
    for ($i = strlen($kanonik) - 2; $i >= 0; $i -= 2) $out .= substr($kanonik, $i, 2);
    return $out;
}

/** Kanonik HEX'in işaretsiz ondalık karşılığı (yalnız teşhis/gösterim için). */
function pdks_uid_to_decimal(string $kanonik): string
{
    $dec = '0';
    for ($i = 0; $i < strlen($kanonik); $i++) {
        $dec = pdks_dec_carpi_ekle($dec, 16, (int)hexdec($kanonik[$i]));
    }
    return $dec;
}
function pdks_dec_carpi_ekle(string $dec, int $carpan, int $ekle): string
{
    $out = '';
    $tasi = $ekle;
    for ($i = strlen($dec) - 1; $i >= 0; $i--) {
        $v    = (int)$dec[$i] * $carpan + $tasi;
        $out  = (string)($v % 10) . $out;
        $tasi = intdiv($v, 10);
    }
    while ($tasi > 0) { $out = (string)($tasi % 10) . $out; $tasi = intdiv($tasi, 10); }
    return ltrim($out, '0') === '' ? '0' : ltrim($out, '0');
}

/**
 * Bir okumadan üretilebilecek TÜM kanonik adaylar.
 *
 * ⚠ $kaynak ZORUNLUDUR ve TAHMİN EDİLMEZ. Gerekçe: "12345678" hem geçerli bir
 * 4 baytlık HEX (0x12345678) hem de geçerli bir ondalıktır (0x00BC614E).
 * Otomatik tespit, bu iki kartı sessizce birbirine karıştırırdı.
 */
function pdks_uid_adaylari(string $ham, string $kaynak): array
{
    $adaylar = [];
    if ($kaynak === 'usb_decimal') {
        $k = pdks_uid_from_decimal($ham);
        if ($k !== null) { $adaylar[] = $k; $adaylar[] = pdks_uid_reverse($k); }
    } elseif ($kaynak === 'nfc_hex') {
        $k = pdks_uid_hex_normalize($ham);
        if ($k !== null) { $adaylar[] = $k; $adaylar[] = pdks_uid_reverse($k); }
    } else {
        return [];
    }
    return array_values(array_unique($adaylar));
}

// ─────────────────────────────────────────────────────────
// KANIT
// ─────────────────────────────────────────────────────────

$hata = 0; $gecen = 0;
function dogrula(string $ad, $bulunan, $beklenen): void {
    global $hata, $gecen;
    $ok = $bulunan === $beklenen;
    $ok ? $gecen++ : $hata++;
    printf("%-62s %-22s %s\n", $ad, var_export($bulunan, true), $ok ? 'OK' : '*** HATA (beklenen ' . var_export($beklenen, true) . ')');
}

echo "\n=== 1. FİZİKSEL TEST KARTI — ölçülen değerlerin aritmetiği ===\n";
echo "    USB HID okuyucu Excel'e yazdı : 631799511\n";
echo "    Android teşhis uygulaması     : 25 A8 7E D7  (ters gösterim: D7 7E A8 25)\n\n";

dogrula('0x25A87ED7 ondalık karşılığı',              pdks_uid_to_decimal('25A87ED7'), '631799511');
dogrula('631799511 → kanonik HEX',                   pdks_uid_from_decimal('631799511'), '25A87ED7');
dogrula('0xD77EA825 ondalık karşılığı (ters)',       pdks_uid_to_decimal('D77EA825'), '3615402021');
dogrula('25A87ED7 bayt-ters çevrimi',                pdks_uid_reverse('25A87ED7'), 'D77EA825');
dogrula('ters çevrimin ters çevrimi = kendisi',      pdks_uid_reverse(pdks_uid_reverse('25A87ED7')), '25A87ED7');

echo "\n=== 2. ÜÇ GÖSTERİM AYNI KANONA ÇÖZÜLÜYOR MU? (§5 şartı) ===\n";
$hedef = '25A87ED7';
dogrula("USB '631799511' adayları hedefi içeriyor",  in_array($hedef, pdks_uid_adaylari('631799511', 'usb_decimal'), true), true);
dogrula("NFC '25 A8 7E D7' adayları hedefi içeriyor",in_array($hedef, pdks_uid_adaylari('25 A8 7E D7', 'nfc_hex'), true), true);
dogrula("NFC 'D7:7E:A8:25' adayları hedefi içeriyor",in_array($hedef, pdks_uid_adaylari('D7:7E:A8:25', 'nfc_hex'), true), true);
dogrula("USB ters ondalık '3615402021' de içeriyor", in_array($hedef, pdks_uid_adaylari('3615402021', 'usb_decimal'), true), true);

echo "\n=== 3. HEX NORMALİZASYON — kabul edilen biçimler ===\n";
foreach (['25A87ED7', '25a87ed7', '25:A8:7E:D7', '25-A8-7E-D7', '25 A8 7E D7', '0x25A87ED7', '  25A87ED7  '] as $b) {
    dogrula("normalize('$b')", pdks_uid_hex_normalize($b), '25A87ED7');
}

echo "\n=== 4. HEX NORMALİZASYON — reddedilen biçimler ===\n";
dogrula("boş",                         pdks_uid_hex_normalize(''), null);
dogrula("hex olmayan karakter 'ZZZZ'", pdks_uid_hex_normalize('ZZZZ'), null);
dogrula("tek sayıda hane '25A87ED'",   pdks_uid_hex_normalize('25A87ED'), null);
dogrula("desteklenmeyen uzunluk 3 bayt",pdks_uid_hex_normalize('25A87E'), null);
dogrula("desteklenmeyen uzunluk 5 bayt",pdks_uid_hex_normalize('25A87ED7AA'), null);
dogrula("desteklenmeyen uzunluk 8 bayt",pdks_uid_hex_normalize('25A87ED7AABBCCDD'), null);
dogrula("null girdi",                  pdks_uid_hex_normalize(null), null);

echo "\n=== 5. BAŞTAKİ SIFIR BAYTI — ondalıkta kaybolur, geri konmalı ===\n";
dogrula('0x0025A87E ondalığı',            pdks_uid_to_decimal('0025A87E'), '2467966');
dogrula("2467966 → '0025A87E' (25A87E DEĞİL)", pdks_uid_from_decimal('2467966'), '0025A87E');
dogrula('UID 00000001 → ondalık 1',       pdks_uid_to_decimal('00000001'), '1');
dogrula("'1' → '00000001'",               pdks_uid_from_decimal('1'), '00000001');
dogrula("'0' → '00000000'",               pdks_uid_from_decimal('0'), '00000000');
dogrula("sıfır dolgulu giriş '0631799511'", pdks_uid_from_decimal('0631799511'), '25A87ED7');

echo "\n=== 6. UZUN UID DESTEĞİ — 7 ve 10 bayt (gelecekteki kartlar) ===\n";
dogrula('7 bayt hex kabul',              pdks_uid_hex_normalize('04A2B3C4D5E6F0'), '04A2B3C4D5E6F0');
dogrula('7 bayt bayt-ters',              pdks_uid_reverse('04A2B3C4D5E6F0'), 'F0E6D5C4B3A204');
$d7 = pdks_uid_to_decimal('04A2B3C4D5E6F0');
dogrula('7 bayt ondalık gidiş-dönüş',    pdks_uid_from_decimal($d7, 7), '04A2B3C4D5E6F0');
dogrula('7 bayt ondalık PHP int sınırını aşıyor mu? (bilgi)', strlen($d7) >= 15, true);
$d10 = pdks_uid_to_decimal('0102030405060708090A');
dogrula('10 bayt hex kabul',             pdks_uid_hex_normalize('0102030405060708090A'), '0102030405060708090A');
dogrula('10 bayt ondalık gidiş-dönüş (80 bit — int64 taşar)', pdks_uid_from_decimal($d10, 10), '0102030405060708090A');
dogrula('otomatik uzunluk: 5 baytlık değer → 7 bayta yükseltilir', pdks_uid_from_decimal(pdks_uid_to_decimal('00FF00FF00FF00')), '00FF00FF00FF00');

echo "\n=== 7. OTOMATİK TESPİT YASAK — ambiguity kanıtı ===\n";
$ikircikli = '12345678';
$hexOkuma  = pdks_uid_adaylari($ikircikli, 'nfc_hex')[0]     ?? null;
$decOkuma  = pdks_uid_adaylari($ikircikli, 'usb_decimal')[0] ?? null;
printf("    '%s' HEX olarak okunursa → %s\n", $ikircikli, (string)$hexOkuma);
printf("    '%s' ONDALIK okunursa    → %s\n", $ikircikli, (string)$decOkuma);
dogrula('aynı metin iki FARKLI karta çözülüyor', $hexOkuma !== $decOkuma, true);
dogrula('bu yüzden kaynak bildirilmeden aday üretilmez', pdks_uid_adaylari($ikircikli, 'bilinmiyor'), []);

echo "\n=== 8. ÇAKIŞMA — palindrom UID (kendi tersi) ===\n";
dogrula('A5A5A5A5 tersi kendisi',        pdks_uid_reverse('A5A5A5A5'), 'A5A5A5A5');
dogrula('bu durumda alias adayları tekilleşiyor', count(pdks_uid_adaylari('A5A5A5A5', 'nfc_hex')), 1);

echo "\n=== 9. GÜRÜLTÜ TOLERANSI (USB okuyucu / elle yazım) ===\n";
dogrula("binlik ayraçlı '631.799.511'", pdks_uid_from_decimal('631.799.511'), '25A87ED7');
dogrula("boşluklu ' 631799511 '",       pdks_uid_from_decimal(' 631799511 '), '25A87ED7');
dogrula("harf içeren '63179951X'",      pdks_uid_from_decimal('63179951X'), null);
dogrula("negatif '-631799511'",         pdks_uid_from_decimal('-631799511'), null);
dogrula("10 bayttan uzun ondalık reddi", pdks_uid_from_decimal(str_repeat('9', 40)), null);

echo "\n";
printf("SONUÇ: %d doğrulama geçti, %d hata.\n", $gecen, $hata);
if ($hata === 0) {
    echo "✓ docs/PDKS_NFC_YOL_HARITASI.md §C'deki UID iddialarının TAMAMI kanıtlandı.\n\n";
}
exit($hata === 0 ? 0 : 1);
