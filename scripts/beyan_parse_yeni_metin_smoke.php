<?php
// Yeni WhatsApp metin biçimi (firma+adres, "Nar Hicaz / Kalibre 14") + eski biçim regresyonu.
declare(strict_types=1);
$src = file_get_contents(__DIR__ . '/../beyan_parse.php');
preg_match('/function normalize_line.*?\n}\n/s', $src, $a);
preg_match('/function parse_beyan_number.*?\n}\n/s', $src, $b);
preg_match('/function parse_beyan_text.*?\n}\n\n\/\/ ── WhatsApp/s', $src, $c);
eval($a[0] . $b[0] . preg_replace('/\n\/\/ ── WhatsApp$/', '', $c[0]));
$f = 0;
$ok = function (string $ad, $beklenen, $gercek) use (&$f) {
    if ($beklenen !== $gercek) { $f++; echo "FAIL $ad: ", var_export($gercek, true), " != ", var_export($beklenen, true), "\n"; }
    else echo "ok   $ad\n";
};

$yeni = "YENİ BEYAN \n\nOGL-SIRBİSTAN \nLIDL Nova Pazova / Prva juzna radna 3 / 22330 Nova Pazova\n\n61/ 253 parti \n\nNar Hicaz \n\nKalibre 14\n\n1 palet\n\nBrut: 650\nKarton Kutu : 160\nNet: 560\n\nAlıcı: OGL\nİlgili: YASİN BEY \nÇIKIŞ: HASYURT FABRİKA";
$p = parse_beyan_text($yeni);
$o = $p['parsed'];
$ok('firma', 'OGL-SIRBİSTAN', $o['company_name']);
$ok('adres', 'LIDL Nova Pazova, Prva juzna radna 3, 22330 Nova Pazova', $o['company_address']);
$ok('parti', '61/253', $o['party_no']);
$ok('urun', 'Nar', $o['product_name']);
$ok('cesit', 'Hicaz KALİBRE 14', $o['product_variety']);
$ok('palet', 1, $o['pallet_count']);
$ok('net', 560.0, $o['net_kg']);
$ok('kasa', 160, $o['crate_count']);
$ok('eslesmeyen', '', $p['unmatched']);

// Eski biçim: değişmemeli
$eski = "YENİ BEYAN\nLLC \"ZAPADNYE VOROTA\"\nRUSSIA, 108811, G.MOSKVA, UL 5\n\n46/22 PARTİ\n26 PALET KAYISI MİKADO\n24.200 BRÜT\n22.400 NET\nALICI: X\nÇIKIŞ: KARAMAN DEPO";
$e = parse_beyan_text($eski)['parsed'];
$ok('eski firma', 'LLC "ZAPADNYE VOROTA"', $e['company_name']);
$ok('eski urun', 'KAYISI', $e['product_name']);
$ok('eski cesit', 'MİKADO', $e['product_variety']);
$ok('eski parti', '46/22', $e['party_no']);

// Yanlış pozitif: kalibresiz, adressiz satırlar dokunulmaz
$d = parse_beyan_text("YENİ BEYAN\nŞOFÖR ADI\n34 ABC 1234\n5 PALET")['parsed'];
$ok('plaka firma sanılmaz', null, $d['company_name']);
exit($f ? 1 : 0);
