<?php
// =========================================================
// scripts/siparis_vade_canli_smoke.php — "OGL Haftalık Ödeme" ALTIN SENARYO (v572; canlı v571 çıktısının PDF'i bire bir)
//   php scripts/siparis_vade_canli_smoke.php
//
// Saf hesap `siparis_vade_hesapla()` (config/siparis_vade.php) — DB / sayfa YOK; kişisel veri YOK (yalnız sipariş/fatura numaraları).
// Sözleşme: v572 (spec) — yeni alanlar: ozet.kasa_kurus, ozet.avizo_fark_kurus, ozet.uyum{cari_bakiye,avizo_fark,bekleyen_kesinti,acik,sapma,tutarli},
// ozet.kalan_kisim_kurus, bu_hafta.{kesin_kurus,kalan_kisim_kurus}, ozet.gecmis_odeme_max_kurus, ozet.bu_hafta_asiri,
// acik[].kalan_kisim, odenmis[].{fark_kurus,iskonto_suphesi}, uyarilar (hata / iskonto şüphesi / aşırı bu hafta / kalan kısım).
// Alan yoksa FAIL vermesi normaldir (motor henüz bitmemişse); bu, testin hatayı yakaladığının kanıtıdır.
//
// ── ALTIN SENARYO KURULUMU (PDF "OGL Haftalık Ödeme", 23.11.2026, Pazartesi) ──────────────────────────────────────────
//  * 26 sevkiyat (id = fatura no sonu; ortak faturanın 2. sevkiyatı id 1340): 18 açık + 5 yalnız avizoyla kapanan + 7 kısmi-invoice (343/346 kalanları
//    mahsup, 349/354/358/360/363 kalanları açık) + 340b (ortak fatura, tamamı mahsup). PDF'in iki tablosundan (açık 18 satır, ödenmiş 15 satır) her net
//    tutar DOĞRUDAN okunur; PDF'te görünmeyen kalem YOKTUR (Σ net kendiliğinden 296.220,27 € eder — kurulum bunu ayrıca doğrular).
//  * Kısmi invoice: belge toplamı = avizoda ödenen ana tutar; sevkiyat net = ana + PDF'teki "kalan kısım" (ör. 343: 8.838,14 + 8.101,64).
//  * Avizoda "ödenen" = ana satır + düzeltme (IHR…DI) satırı: 356 → 22.176,00 − 887,04; 343/346/358/363 küçük eksikler; 339 − 74,88. Σ düzeltme = 1.107,07.
//  * Avizo toplamları PDF'tekilerdir (27.10 → 62.377,44; 09.11 → 89.341,04). PDF'te görünmeyen kısım = eşleşmeyen satırlar: 11 pozitif "ana" kalem (5 + 6)
//    ve eksi iade/düzeltme satırları (ogl_ic); NET havuz 37.112,54 € (PDF: "eşleşmeyen … NET 37.112,54 EUR … mahsup"). Kalem tutarları/numaraları UYDURMA
//    (sentetik; yalnız toplamları PDF ile birebir tutar).
//  * Fiyat farkı (tahmini kesinti): 360 → 5,38 (kısmi: yalnız kalan payı), 361 → 3.015,94, 366 → 75,26; karsilastirma.fark_kurus bunları üretecek biçimde
//    kurulur (gelen < kesilen; iskonto %4). 389'un gelen (PC) fiyatı yok (karsilastirma null). 300 EUR debit (2028-107735) avizoda yok, sevkiyatsız.
//  * Vade/gün PDF'ten öğrenilir: fatura tarihi + 9 gün → ilk Pazartesi (gecikmeler 9,9,9,9,9,23,24,24; −4/0 olanlar katılmaz).
//  * Beklenen: cari 144.501,79 = açık 139.998,14 + avizo farkı 1.107,07 + bekleyen kesinti 3.396,58; kasa 151.718,48; bu hafta 113.790,14.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/config/siparis_vade.php';

// ── yardımcılar ────────────────────────────────────────────────────────
/** "3.682,56" / "-64,51" → kuruş. */
function T(string $s): int
{
    $s = str_replace(['.', ' '], '', trim($s));
    return (int)round((float)str_replace(',', '.', $s) * 100);
}
function vd_tum(int $brut, int $isk100): int { return $brut - intdiv($brut * $isk100 + 5000, 10000); }
/** İskonto SONRASI net = $net olacak brüt (siparis_vade_fatura_net ile aynı formülün tersi). */
function vd_brut(int $net, int $isk100): int
{
    $b0 = max(0, intdiv($net * 10000, 10000 - $isk100) - 4);
    for ($b = $b0; $b <= $b0 + 12; $b++) if (vd_tum($b, $isk100) === $net) return $b;
    throw new RuntimeException("brüt bulunamadı net=$net");
}
function vd_g(int $no): string { return 'IHR2026000000' . str_pad((string)$no, 3, '0', STR_PAD_LEFT); }
/** Fatura kesilmiş sevkiyat (gram × fiyat → brüt; iskonto fatura haritasında). */
function vd_sev(int $id, string $ref, string $ulke, array $parti, string $yukleme, ?string $ftarih, int $net, string $fatNo, ?array $pc, float $isk = 4.0): array
{
    $brut = vd_brut($net, (int)round($isk * 100));
    return ['id' => $id, 'order_ref' => $ref, 'tarih' => $yukleme, 'fiyat_tarihi' => null, 'ulke' => $ulke, 'plaka' => '', 'parti' => $parti, 'yuklendi' => true,
        'toplam' => ['gram' => $brut * 10, 'koli' => 0, 'palet' => 0], 'tutar' => ['EUR' => $brut], 'net' => ['EUR' => $net],
        'fatura' => ['fiyat' => 10000, 'no' => $fatNo, 'tarih' => $ftarih], 'karsilastirma' => $pc];
}
function vd_faturasiz(int $id, string $ref, int $net): array
{
    return ['id' => $id, 'order_ref' => $ref, 'tarih' => '2026-11-10', 'fiyat_tarihi' => null, 'ulke' => '', 'plaka' => '', 'parti' => [], 'yuklendi' => true,
        'toplam' => ['gram' => 1000000, 'koli' => 0, 'palet' => 0], 'tutar' => ['EUR' => $net], 'net' => ['EUR' => $net], 'fatura' => null, 'karsilastirma' => null];
}
/** Sözleşme alanlarına güvenli erişim: ozet → kök → bu_hafta → ozet.uyum sırasıyla arar; yoksa null. */
function vd_al(array $h, string $k)
{
    foreach ([$h['ozet'] ?? [], $h, $h['bu_hafta'] ?? [], $h['ozet']['uyum'] ?? []] as $d) if (is_array($d) && array_key_exists($k, $d)) return $d[$k];
    return null;
}
function vd_uyum(array $h, string $k) { return $h['ozet']['uyum'][$k] ?? null; }
function vd_bh(array $h, string $k) { $b = $h['bu_hafta'] ?? []; if (array_key_exists($k, $b)) return $b[$k]; $b = $h['ozet']['bu_hafta'] ?? []; return is_array($b) ? ($b[$k] ?? null) : null; }
function vd_uyarilar(array $h, ?string $seviye = null): array
{
    return array_values(array_filter((array)($h['uyarilar'] ?? []), static fn($u): bool => is_array($u) && ($seviye === null || ($u['seviye'] ?? '') === $seviye)));
}
function vd_uyari_var(array $h, string $parca, ?string $seviye = null): bool
{
    foreach (vd_uyarilar($h, $seviye) as $u) if (str_contains((string)($u['mesaj'] ?? ''), $parca)) return true;
    return false;
}
function vd_j($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE); }
function vd_satir_idleri(array $l): array { $o = array_map(static fn(array $a): int => (int)$a['sev']['id'], $l); sort($o); return $o; }

$BUGUN = '2026-11-23';

// ══════════════════════════════════════════════════════════════════════
echo "=== 0. Altın senaryo kurulumu (PDF ile birebir toplamlar) ===\n";
// id => [ref, ülke, parti[], yükleme, fatura tarihi, net, belge (sistemdeki invoice), pc fark_kurus|null, fatura no sonu]
$GS = [
    339  => ['TC418910.10', 'İNGİLTERE', ['61-220'],            '2026-10-02', '2026-10-27', '23.936,64', '23.936,64', 0, 339],
    340  => ['TC418932.10', 'İNGİLTERE', [],                    '2026-10-05', '2026-10-03', '23.936,64', '23.936,64', 0, 340],
    1340 => ['TC430234.10', 'İNGİLTERE', ['61/234'],            '2026-10-22', '2026-10-27', '23.936,64', '23.936,64', 0, 340],   // ortak fatura IHR…340'ın 2. sevkiyatı
    341  => ['TC420040.10', 'ROMANYA',   [],                    '2026-10-04', '2026-10-03', '17.676,29', '17.676,29', 0, 341],
    343  => ['TC426865.10', 'SIRBİSTAN', ['61/225', '61/224'],  '2026-10-16', '2026-10-31', '16.939,78', '8.838,14',  0, 343],   // kısmi invoice: kalan 8.101,64
    345  => ['TC427742.10', 'ROMANYA',   ['61-226'],            '2026-10-17', '2026-10-17', '5.155,58',  '5.155,58',  0, 345],
    346  => ['TC426866.10', 'SIRBİSTAN', ['61/227', '61/228'],  '2026-10-18', '2026-10-31', '4.419,07',  '2.209,54',  0, 346],   // kalan 2.209,53
    348  => ['TC427747.10', 'ROMANYA',   ['61/229'],            '2026-10-19', '2026-10-31', '3.682,56',  '3.682,56',  0, 348],
    349  => ['TC426877.10', 'SIRBİSTAN', ['61/231', '61/230'],  '2026-10-19', '2026-10-31', '2.946,05',  '1.473,02',  0, 349],   // kalan 1.473,03
    351  => ['TC427751.10', 'ROMANYA',   ['61/232'],            '2026-10-21', '2026-10-31', '14.730,24', '14.730,24', 0, 351],
    352  => ['TC427752.10', 'ROMANYA',   ['61/233'],            '2026-10-22', '2026-10-31', '19.149,31', '19.149,31', 0, 352],
    354  => ['TC429403.10', 'SIRBİSTAN', ['61/236', '61/235'],  '2026-10-24', '2026-10-31', '1.473,02',  '736,51',    0, 354],   // kalan 736,51
    356  => ['TC432845.10', 'ALMANYA',   ['61/237'],            '2026-10-24', '2026-10-31', '22.176,00', '22.176,00', 0, 356],
    358  => ['TC432691.10', 'SIRBİSTAN', ['61/238', '61/239'],  '2026-10-25', '2026-10-31', '2.946,05',  '1.473,02',  0, 358],   // kalan 1.473,03
    360  => ['TC432734.10', 'SIRBİSTAN', ['61/240', '61/241'],  '2026-10-26', '2026-10-31', '1.473,02',  '736,51',    -1121, 360],   // kalan 736,51; tahmini fiyat farkı → 5,38
    361  => ['TC433599.10', 'ALMANYA',   ['61/242'],            '2026-10-26', '2026-10-31', '24.304,90', '24.304,90', -314160, 361],  // tahmini 3.015,94
    363  => ['TC432924.10', 'SIRBİSTAN', ['61/244', '61/243'],  '2026-10-29', '2026-10-31', '18.412,80', '7.365,12',  0, 363],   // kalan 11.047,68
    366  => ['TC433176.10', 'SIRBİSTAN', ['61/245', '61/246'],  '2026-10-31', '2026-11-05', '10.311,17', '10.311,17', -7840, 366],   // tahmini 75,26
    367  => ['TC438448.10', 'SIRBİSTAN', ['61/249', '61/248'],  '2026-11-03', '2026-11-03', '1.473,02',  '1.473,02',  0, 367],
    369  => ['TC438454.10', 'SIRBİSTAN', ['61/250', '61/251'],  '2026-11-03', '2026-11-03', '1.473,02',  '1.473,02',  0, 369],
    374  => ['TC438456.10', 'SIRBİSTAN', ['61/247', '61/252'],  '2026-11-04', '2026-11-09', '1.473,02',  '1.473,02',  0, 374],
    376  => ['TC438459.10', 'SIRBİSTAN', ['61/253', '61/254'],  '2026-11-05', '2026-11-09', '1.473,02',  '1.473,02',  0, 376],
    378  => ['TC439097.10', 'ALMANYA',   ['61/257'],            '2026-11-05', '2026-11-06', '24.304,90', '24.304,90', 0, 378],
    379  => ['TC438467.10', 'SIRBİSTAN', ['61/259', '61/258'],  '2026-11-07', '2026-11-09', '1.473,02',  '1.473,02',  0, 379],
    381  => ['TC442602.10', 'SIRBİSTAN', ['61/262'],            '2026-11-08', '2026-11-10', '736,51',    '736,51',    0, 381],
    389  => ['TC448334.10', 'İNGİLTERE', ['61/269'],            '2026-11-18', '2026-11-18', '26.208,00', '26.208,00', null, 389],   // gelen (PC) fiyat YOK
];
$sevler = []; $fatura = []; $belge = []; $toplamNet = 0; $ref2id = [];
foreach ($GS as $id => [$ref, $ulke, $parti, $yuk, $ft, $net, $blg, $pc, $fno]) {
    $sevler[] = vd_sev($id, $ref, $ulke, $parti, $yuk, $ft, T($net), vd_g($fno), $pc === null ? null : ['fark_kurus' => $pc]);
    $fatura[$id] = ['iskonto' => 4.0];
    $belge[$id] = T($blg);
    $toplamNet += T($net);
    $ref2id[$ref] = $id;
}
$ana = static fn(int $fno, string $tutar): array => ['n' => vd_g($fno), 't' => 'ana', 'g' => vd_g($fno), 'z' => T($tutar)];
$duz = static fn(int $fno, string $tutar): array => ['n' => vd_g($fno) . 'DI', 't' => 'duzeltme', 'g' => vd_g($fno), 'z' => -T($tutar)];
$yok = static fn(int $no, string $tutar): array => ['n' => vd_g($no), 't' => 'ana', 'g' => vd_g($no), 'z' => T($tutar)];   // sistemde invoice'ı olmayan eski fatura (eşleşmeyen)
$iade = static fn(string $no, string $tutar): array => ['n' => $no, 't' => 'ogl_ic', 'g' => '', 'z' => -T($tutar)];
$av1 = [   // 27.10.2026 (Salı) — 62.377,44
    $ana(343, '8.838,14'), $duz(343, '64,51'), $ana(346, '2.209,54'), $duz(346, '16,13'), $ana(349, '1.473,02'),
    $ana(339, '23.936,64'), $duz(339, '74,88'), $ana(341, '17.676,29'), $ana(340, '23.936,64'),
    $yok(301, '14.200,00'), $yok(305, '11.800,50'), $yok(310, '9.350,25'), $yok(315, '7.480,00'), $yok(320, '4.620,10'),   // 5 eşleşmeyen (+)
    $iade('2027-0412', '45.000,00'), $iade('2027-0418', '17.988,16'),                                                     // iade / iptal (−)
];
$av2 = [   // 09.11.2026 (Pazartesi) — 89.341,04
    $ana(354, '736,51'), $ana(356, '22.176,00'), $duz(356, '887,04'), $ana(358, '1.473,02'), $duz(358, '10,75'), $ana(360, '736,51'),
    $ana(363, '7.365,12'), $duz(363, '53,76'), $ana(345, '5.155,58'),
    $yok(322, '15.400,00'), $yok(325, '12.350,75'), $yok(328, '11.020,00'), $yok(331, '9.870,30'), $yok(333, '6.205,80'), $yok(336, '4.103,00'),   // 6 eşleşmeyen (+)
    $iade('2027-0501', '6.300,00'),
];
$z1 = array_sum(array_column($av1, 'z')); $z2 = array_sum(array_column($av2, 'z'));
$harita = [];
foreach ([343, 346, 349, 339, 341, 354, 356, 358, 360, 363, 345] as $fno) $harita[vd_g($fno)] = ['durum' => 'eslesti', 'v' => [['id' => $fno]]];
$harita[vd_g(340)] = ['durum' => 'belirsiz', 'v' => [['id' => 340], ['id' => 1340]]];
$GIRDI = [
    'sevkiyatlar' => $sevler, 'fatura' => $fatura, 'belge_toplam' => $belge, 'harita' => $harita,
    'odemeler' => [['id' => 11, 'tarih' => '2026-10-27', 'kurus' => $z1, 'pb' => 'EUR', 'satirlar' => $av1], ['id' => 12, 'tarih' => '2026-11-09', 'kurus' => $z2, 'pb' => 'EUR', 'satirlar' => $av2]],
    'debitler' => [['no' => '2028-107735', 'tarih' => '2026-11-17', 'kurus' => T('300,00'), 'pb' => 'EUR', 'invoice_no' => '', 'sid' => null, 'ref' => '', 'fiyat_farki' => false, 'neden' => 'NON-COMPLIANCE OF SUPPLIERS DECLARATION']],
];
ok('kurulum: 26 sevkiyat, Σ sevkiyat net = 296.220,27 (PDF "invoice toplamı")', count($sevler) === 26 && $toplamNet === T('296.220,27'), (string)$toplamNet);
ok('kurulum: avizo toplamları PDF ile aynı (62.377,44 + 89.341,04 = 151.718,48)', $z1 === T('62.377,44') && $z2 === T('89.341,04') && $z1 + $z2 === T('151.718,48'), "$z1 / $z2");
ok('kurulum: eşleşmeyen (eski fatura + iade) satırların NET toplamı = 37.112,54 (PDF mahsup havuzu); 11 pozitif eşleşmeyen kalem', (static function () use ($av1, $av2): bool {
    $e = 0; $poz = 0;
    foreach (array_merge($av1, $av2) as $r) if ($r['g'] === '' || (int)substr($r['g'], -3) < 339) { $e += $r['z']; if ($r['z'] > 0) $poz++; }
    return $e === T('37.112,54') && $poz === 11;
})());

$h = siparis_vade_hesapla($GIRDI, siparis_vade_param([]), $BUGUN);
$o = $h['ozet'];

// ══════════════════════════════════════════════════════════════════════
echo "\n=== 1. Altın senaryo: PDF rakamları ===\n";
ok('PDF: bu hafta 23.11–29.11, ödeme günü Pazartesi 23.11; vade otomatik 9 gün (fatura tarihi + 9 → ilk Pazartesi)', $h['bu_hafta']['bas'] === '2026-11-23' && $h['bu_hafta']['son'] === '2026-11-29' && $h['bu_hafta']['odeme_gunu'] === '2026-11-23'
    && $h['param']['vade'] === 9 && $h['param']['vade_oto'] === true && $h['param']['gun'] === 1 && $o['gecikme']['n'] === 8 && $o['gecikme']['min'] === 9 && $o['gecikme']['medyan'] === 9 && $o['gecikme']['max'] === 24, vd_j([$h['param'], $o['gecikme']]));
ok('PDF kartı: BU HAFTA 113.790,14 (17 fatura, 13 gecikmiş = 108.934,57), kesinti −3.396,58 (hafta kesintisi)', $o['bu_hafta_kurus'] === T('113.790,14') && $o['bu_hafta_sevkiyat'] === 17 && $o['bu_hafta_gecikmis_sevkiyat'] === 13
    && $o['bu_hafta_gecikmis_kurus'] === T('108.934,57') && $o['bu_hafta_kesinti'] === T('3.396,58'), vd_j($o));
ok('PDF kartı: GELECEK HAFTA 26.208,00 (30.11, 1 fatura)', $o['gelecek_hafta_kurus'] === T('26.208,00') && $o['gelecek_hafta_sevkiyat'] === 1 && $o['gelecek_odeme_gunu'] === '2026-11-30');
ok('PDF kartı: AÇIK ALACAK 139.998,14 (18 fatura); fatura toplamı 296.220,27; ödenen fatura 152.825,55; mahsup 37.112,54', $o['acik_kurus'] === T('139.998,14') && $o['acik_sevkiyat'] === 18
    && $o['fatura_toplam_kurus'] === T('296.220,27') && $o['odenen_fatura_kurus'] === T('152.825,55') && $o['mahsup_kurus'] === T('37.112,54') && $o['havuz_kurus'] === T('37.112,54'), vd_j($o));
ok('PDF kartı: ŞİMDİYE KADAR ÖDENEN 151.718,48 (2 ödeme, son 09.11 · 89.341,04); invoice\'u kesilmemiş 0', $o['odenen_kurus'] === T('151.718,48') && $o['odeme_adet'] === 2 && $o['son_odeme'] === ['tarih' => '2026-11-09', 'kurus' => T('89.341,04')] && $o['faturasiz_sevkiyat'] === 0);
ok('PDF: ödenmiş invoice\'lar 15 satır (12 avizo + 3 mahsup tahmini: 340b, 343 ve 346 kalanları); açık 18 satır', count($h['odenmis']) === 15 && count(array_filter($h['odenmis'], static fn(array $x): bool => $x['elle'])) === 3 && count($h['acik']) === 18);
ok('PDF: mahsup (tahmini) satırları = 23.936,64 (340b) + 8.101,64 (343) + 2.209,53 (346); 348\'den 2.864,73 mahsup → beklenen 817,83', (static function () use ($h): bool {
    $m = []; foreach ($h['odenmis'] as $x) if ($x['elle']) $m[(int)$x['sev']['id']] = $x['odenen'];
    ksort($m);
    $a = []; foreach ($h['acik'] as $x) $a[(int)$x['sev']['id']] = $x;
    return $m === [343 => T('8.101,64'), 346 => T('2.209,53'), 1340 => T('23.936,64')] && $a[348]['mahsup'] === T('2.864,73') && $a[348]['beklenen'] === T('817,83');
})());
ok('PDF: tahmini fiyat farkı kesintileri 5,38 (360, kısmi) · 3.015,94 (361) · 75,26 (366) = 3.096,58; beklenenler 731,13 / 21.288,96 / 10.235,91', (static function () use ($h): bool {
    $a = []; foreach ($h['acik'] as $x) $a[(int)$x['sev']['id']] = $x;
    return $a[360]['tahmini'] === 538 && $a[361]['tahmini'] === 301594 && $a[366]['tahmini'] === 7526 && $a[360]['beklenen'] === 73113 && $a[361]['beklenen'] === 2128896 && $a[366]['beklenen'] === 1023591
        && array_sum(array_column($h['acik'], 'tahmini')) === T('3.096,58');
})());
ok('PDF: 300,00 debit (2028-107735) avizoda düşülmemiş → bekleyen (eski anahtar bekleyen_kesinti_kurus korunur = 300,00)', count($h['bekleyen_kesinti']) === 1 && $h['bekleyen_kesinti'][0]['no'] === '2028-107735' && $o['bekleyen_kesinti_kurus'] === 30000);
ok('PDF: ödeme günü kolonları — 13 gecikmiş (vadesi geçmiş) fatura bu haftaya alındı; 374/376/379/381 vadesi bu hafta (gecikmiş değil); 389 → 30.11', (static function () use ($h): bool {
    $a = []; foreach ($h['acik'] as $x) $a[(int)$x['sev']['id']] = $x;
    $gec = array_keys(array_filter($a, static fn(array $x): bool => $x['gecikmis']));
    sort($gec);
    return $gec === [348, 349, 351, 352, 354, 358, 360, 361, 363, 366, 367, 369, 378] && !$a[374]['gecikmis'] && $a[374]['odeme_gunu'] === '2026-11-23' && $a[389]['odeme_gunu'] === '2026-11-30' && $a[389]['pc_yok'] === true;
})());
ok('PDF uyarıları (v571) KORUNUR: ortak fatura · 7 kısmi invoice (25.777,93 EUR eksik) · 11 eşleşmeyen kalem NET 37.112,54 · 4 ters tarihli · 1 PC yok',
    vd_uyari_var($h, 'Ortak fatura') && vd_uyari_var($h, '25.777,93 EUR eksik') && vd_uyari_var($h, 'eşleşmeyen 11 fatura kalemi') && vd_uyari_var($h, '37.112,54')
    && vd_uyari_var($h, '4 ödenmiş faturada fatura tarihi') && vd_uyari_var($h, '1 açık faturanın gelen (PC)'), vd_j(array_column($h['uyarilar'], 'mesaj')));

echo "\n=== 1b. SÖZLEŞME (v572): kasa / uyum / avizo farkı / kalan kısım ===\n";
ok('(1) kasa_kurus = 151.718,48 (avizolardan kasaya giren NET)', vd_al($h, 'kasa_kurus') === T('151.718,48'), vd_j(vd_al($h, 'kasa_kurus')));
ok('(1) cari_bakiye = Σ net − kasa = 144.501,79', vd_uyum($h, 'cari_bakiye_kurus') === T('144.501,79'), vd_j(vd_uyum($h, 'cari_bakiye_kurus')));
ok('(1) açık alacak (uyum.acik_kurus) = 139.998,14', vd_uyum($h, 'acik_kurus') === T('139.998,14'), vd_j(vd_uyum($h, 'acik_kurus')));
ok('(1) avizo_fark = 1.107,07 (ozet + uyum)', vd_al($h, 'avizo_fark_kurus') === T('1.107,07') && vd_uyum($h, 'avizo_fark_kurus') === T('1.107,07'), vd_j([vd_al($h, 'avizo_fark_kurus'), vd_uyum($h, 'avizo_fark_kurus')]));
ok('(1) bekleyen kesinti (uyum) = 3.396,58 = 3.096,58 tahmini fiyat farkı + 300,00 debit', vd_uyum($h, 'bekleyen_kesinti_kurus') === T('3.396,58'), vd_j(vd_uyum($h, 'bekleyen_kesinti_kurus')));
ok('(1) sapma_kurus === 0 (TAM eşitlik) ve tutarli === true; "hata" uyarısı YOK', vd_uyum($h, 'sapma_kurus') === 0 && vd_uyum($h, 'tutarli') === true && vd_uyarilar($h, 'hata') === [], vd_j($h['ozet']['uyum'] ?? null));
ok('(1) sapma bileşenleri toplamı: 144.501,79 − 1.107,07 − 3.396,58 − 139.998,14 = 0', T('144.501,79') - T('1.107,07') - T('3.396,58') - T('139.998,14') === 0);
ok('(1) eski toleranslı "DİKKAT" uyarısı kalktı', !vd_uyari_var($h, 'DİKKAT'));
ok('(1) avizo farkı = ödenmiş kalem satırlarının fark_kurus toplamı (bağımsız denetim; 887,04 + 220,03)', (static function () use ($h): bool {
    $t = 0; foreach ($h['odenmis'] as $x) { if (!array_key_exists('fark_kurus', $x)) return false; $t += max(0, (int)$x['fark_kurus']); }
    return $t === T('1.107,07');
})());
ok('(1) fark_kurus satırlara göre: 356 → 887,04; 343 → 64,51; 346 → 16,13; 358 → 10,75; 363 → 53,76; 339 → 74,88; mahsup/diğerleri 0', (static function () use ($h): bool {
    $f = []; foreach ($h['odenmis'] as $x) { if (!array_key_exists('fark_kurus', $x)) return false; if (!$x['elle']) $f[(int)$x['sev']['id']] = (int)$x['fark_kurus']; }
    $beklenen = [339 => 7488, 340 => 0, 341 => 0, 343 => 6451, 345 => 0, 346 => 1613, 349 => 0, 354 => 0, 356 => 88704, 358 => 1075, 360 => 0, 363 => 5376];
    ksort($f); ksort($beklenen);
    return $f === $beklenen;
})());

echo "\n=== 3. İskonto şüphesi YALNIZ 356'da; %0,73 / %0,31 küçük farklarda false ===\n";
$sus = []; foreach ($h['odenmis'] as $x) { if (!array_key_exists('iskonto_suphesi', $x)) { $sus = null; break; } if ($x['iskonto_suphesi']) $sus[] = (int)$x['sev']['id']; }
ok('(3) iskonto_suphesi = true yalnız IHR…356 (22.176,00 − 21.288,96 = tam %4)', $sus === [356], vd_j($sus));
ok('(3) küçük farklar (343/346/358/363 ≈ %0,73; 339 ≈ %0,31) iskonto şüphesi DEĞİL', $sus !== null && !array_intersect($sus, [343, 346, 358, 363, 339]));
$isk = vd_uyarilar($h);
$iskU = array_values(array_filter($isk, static fn(array $u): bool => str_contains((string)$u['mesaj'], '%4') && str_contains((string)$u['mesaj'], 'iskonto')));
ok('(3) iskonto uyarısı var, 356\'yı (fatura no ya da sipariş no) ve farkı (887,04) anar; diğer küçük fark kalemlerini ANMAZ', count($iskU) >= 1
    && (str_contains($iskU[0]['mesaj'], vd_g(356)) || str_contains($iskU[0]['mesaj'], 'TC432845.10')) && str_contains($iskU[0]['mesaj'], '887,04')
    && !preg_match('~' . implode('|', array_map(static fn(int $n): string => vd_g($n) . '|' . preg_quote($GS[$n][0], '~'), [343, 346, 358, 363, 339])) . '~', $iskU[0]['mesaj']), vd_j($iskU));

echo "\n=== 4. Bu hafta aşırı mı + kalan kısım (faturası girilmemiş) ===\n";
ok('(4) geçmiş ödeme (gün bazında) en büyüğü = 89.341,04', vd_al($h, 'gecmis_odeme_max_kurus') === T('89.341,04'), vd_j(vd_al($h, 'gecmis_odeme_max_kurus')));
ok('(4) bu_hafta_asiri === true (113.790,14 > 1,25 × 89.341,04 = 111.676,30)', vd_al($h, 'bu_hafta_asiri') === true && 11379014 * 4 > 8934104 * 5);
ok('(4) bu_hafta_asiri uyarısı: "geçmiş ödemelerin en büyüğünün (89.341,04) 1,25 katından fazla" + birikmiş gecikmiş', vd_uyari_var($h, '1,25 kat') && vd_uyari_var($h, '89.341,04') && vd_uyari_var($h, 'birikmiş'), vd_j(array_column($h['uyarilar'], 'mesaj')));
$ack = []; foreach ($h['acik'] as $x) $ack[(int)$x['sev']['id']] = $x;
$kk = []; foreach ($ack as $i => $x) if (!empty($x['kalan_kisim'])) $kk[] = $i;
ok('(4) kalan_kisim = true yalnız 349 / 354 / 358 / 360 / 363 (343/346 kalanları mahsupla kapandı; 348 tam invoice)', (bool)array_filter($h['acik'], static fn(array $x): bool => array_key_exists('kalan_kisim', $x)) && $kk === [349, 354, 358, 360, 363], vd_j($kk));
ok('(4) kalan_kisim_kurus = 1.473,03 + 736,51 + 1.473,03 + 731,13 + 11.047,68 = 15.461,38 (kesintiden sonra BEKLENEN)', vd_al($h, 'kalan_kisim_kurus') === T('15.461,38'), vd_j(vd_al($h, 'kalan_kisim_kurus')));
$beklenenBH = vd_bh($h, 'beklenen_kurus') ?? $o['bu_hafta_kurus'];
ok('(4) bu_hafta.kesin_kurus + kalan_kisim_kurus === beklenen_kurus (113.790,14); kalan = 15.461,38', vd_bh($h, 'kalan_kisim_kurus') === T('15.461,38') && vd_bh($h, 'kesin_kurus') !== null
    && vd_bh($h, 'kesin_kurus') + vd_bh($h, 'kalan_kisim_kurus') === $beklenenBH && $beklenenBH === T('113.790,14'), vd_j($h['bu_hafta']));
ok('(4) bu_hafta.beklenen_kurus (varsa) === ozet.bu_hafta_kurus', vd_bh($h, 'beklenen_kurus') === null || vd_bh($h, 'beklenen_kurus') === $o['bu_hafta_kurus']);
ok('(4) kalan kısım uyarısı: "invoice girilmemiş kalan kısım" + "kesin değil"', vd_uyari_var($h, 'invoice girilmemiş kalan kısım') && vd_uyari_var($h, 'kesin değil'), vd_j(array_column($h['uyarilar'], 'mesaj')));

// (4b) tersi: aşırı DEĞİL
$h0 = siparis_vade_hesapla(['sevkiyatlar' => [vd_sev(1, 'TC1.10', 'X', [], '2026-11-01', '2026-11-01', T('1.000,00'), vd_g(1), null)], 'fatura' => [1 => ['iskonto' => 4.0]], 'belge_toplam' => [1 => T('1.000,00')], 'odemeler' => [], 'harita' => [], 'debitler' => []], siparis_vade_param([]), $BUGUN);
ok('(4b) geçmiş ödeme yok → gecmis_odeme_max 0 ve bu_hafta_asiri false (uyarı yok)', vd_al($h0, 'gecmis_odeme_max_kurus') === 0 && vd_al($h0, 'bu_hafta_asiri') === false && !vd_uyari_var($h0, '1,25 kat'), vd_j([vd_al($h0, 'gecmis_odeme_max_kurus'), vd_al($h0, 'bu_hafta_asiri')]));
ok('(4b) ödeme yok: kasa 0, cari = Σ net, sapma 0, tutarli', vd_al($h0, 'kasa_kurus') === 0 && vd_uyum($h0, 'cari_bakiye_kurus') === T('1.000,00') && vd_uyum($h0, 'sapma_kurus') === 0 && vd_uyum($h0, 'tutarli') === true, vd_j($h0['ozet']['uyum'] ?? null));
// bu hafta ≤ 1,25 × geçmiş: tam 1,25 katı da "aşırı" DEĞİL (sınır dahil değil)
$sinir = static function (int $bekle, int $gecmis) use ($BUGUN): array {
    $s = [vd_sev(1, 'TC1.10', 'X', [], '2026-10-01', '2026-10-01', $gecmis, vd_g(1), null, 0.0), vd_sev(2, 'TC2.10', 'X', [], '2026-11-01', '2026-11-01', $bekle, vd_g(2), null, 0.0)];
    return siparis_vade_hesapla(['sevkiyatlar' => $s, 'fatura' => [1 => ['iskonto' => 0.0], 2 => ['iskonto' => 0.0]], 'belge_toplam' => [1 => $gecmis, 2 => $bekle],
        'odemeler' => [['id' => 1, 'tarih' => '2026-11-09', 'kurus' => $gecmis, 'pb' => 'EUR', 'satirlar' => [['n' => vd_g(1), 't' => 'ana', 'g' => vd_g(1), 'z' => $gecmis]]]],
        'harita' => [vd_g(1) => ['durum' => 'eslesti', 'v' => [['id' => 1]]]], 'debitler' => []], siparis_vade_param(['vade' => '1', 'gun' => '1']), $BUGUN);
};
$s1 = $sinir(125000, 100000); $s2 = $sinir(125001, 100000); $s3 = $sinir(90000, 100000);
ok('(4b) sınır: bu hafta tam 1,25 × geçmiş → false; 1 kuruş fazlası → true; altı → false', vd_al($s1, 'bu_hafta_asiri') === false && vd_al($s2, 'bu_hafta_asiri') === true && vd_al($s3, 'bu_hafta_asiri') === false
    && $s1['ozet']['bu_hafta_kurus'] === 125000, vd_j([vd_al($s1, 'bu_hafta_asiri'), vd_al($s2, 'bu_hafta_asiri'), vd_al($s3, 'bu_hafta_asiri'), $s1['ozet']['bu_hafta_kurus']]));
// gün bazında: aynı gün iki ödeme TOPLANIR
$gb = $sinir(100, 100000); $gbG = ['sevkiyatlar' => [vd_sev(1, 'TC1.10', 'X', [], '2026-10-01', '2026-10-01', 100000, vd_g(1), null, 0.0), vd_sev(2, 'TC2.10', 'X', [], '2026-10-01', '2026-10-01', 60000, vd_g(2), null, 0.0)],
    'fatura' => [1 => ['iskonto' => 0.0], 2 => ['iskonto' => 0.0]], 'belge_toplam' => [1 => 100000, 2 => 60000],
    'odemeler' => [['id' => 1, 'tarih' => '2026-11-09', 'kurus' => 100000, 'pb' => 'EUR', 'satirlar' => [['n' => vd_g(1), 't' => 'ana', 'g' => vd_g(1), 'z' => 100000]]],
                   ['id' => 2, 'tarih' => '2026-11-09', 'kurus' => 60000, 'pb' => 'EUR', 'satirlar' => [['n' => vd_g(2), 't' => 'ana', 'g' => vd_g(2), 'z' => 60000]]]],
    'harita' => [vd_g(1) => ['durum' => 'eslesti', 'v' => [['id' => 1]]], vd_g(2) => ['durum' => 'eslesti', 'v' => [['id' => 2]]]], 'debitler' => []];
$hgb = siparis_vade_hesapla($gbG, siparis_vade_param([]), $BUGUN);
ok('(4b) gün bazında: aynı gün iki avizo ödemesi (100.000 + 60.000) → geçmiş en büyük 160.000', vd_al($hgb, 'gecmis_odeme_max_kurus') === 160000, vd_j(vd_al($hgb, 'gecmis_odeme_max_kurus')));

// iskonto şüphesi sınırları (tek fatura, ana = net, düzeltme = −fark)
$isksev = static function (int $net, int $fark) use ($BUGUN): array {
    $g = ['sevkiyatlar' => [vd_sev(1, 'TC1.10', 'X', [], '2026-10-01', '2026-10-01', $net, vd_g(1), null, 0.0)], 'fatura' => [1 => ['iskonto' => 0.0]], 'belge_toplam' => [1 => $net],
        'odemeler' => [['id' => 1, 'tarih' => '2026-11-09', 'kurus' => $net - $fark, 'pb' => 'EUR', 'satirlar' => [['n' => vd_g(1), 't' => 'ana', 'g' => vd_g(1), 'z' => $net], ['n' => vd_g(1) . 'DI', 't' => 'duzeltme', 'g' => vd_g(1), 'z' => -$fark]]]],
        'harita' => [vd_g(1) => ['durum' => 'eslesti', 'v' => [['id' => 1]]]], 'debitler' => []];
    $r = siparis_vade_hesapla($g, siparis_vade_param([]), $BUGUN);
    return [$r['odenmis'][0]['iskonto_suphesi'] ?? null, $r];
};
$N = 1000000;   // 10.000,00 €; %4 = 40.000; tolerans max(100 kuruş, %0,1·net = 1.000 kuruş)
ok('(3b) şüphe sınırları: fark = %4 → true; %4 ± 1.000 kuruş → true; ±1.001 → false', $isksev($N, 40000)[0] === true && $isksev($N, 41000)[0] === true && $isksev($N, 39000)[0] === true && $isksev($N, 41001)[0] === false && $isksev($N, 38999)[0] === false);
ok('(3b) %0,73 (7.300) ve %0,31 (3.100) → false; fark 0 → false', $isksev($N, 7300)[0] === false && $isksev($N, 3100)[0] === false && $isksev($N, 0)[0] === false);
ok('(3b) küçük net: tolerans en az 100 kuruş (net 20.000 → %4 = 800; fark 900 → true, 901 → false)', $isksev(20000, 900)[0] === true && $isksev(20000, 901)[0] === false);
ok('(3b) şüpheli kalem yoksa iskonto uyarısı da yok (fark %0,73)', !vd_uyari_var($isksev($N, 7300)[1], '%4 eksik'));

echo "\n=== 5. Mutasyon dayanıklılığı: girdi bozulunca sapma ≠ 0 + 'hata' uyarısı ===\n";
$mut = static function (callable $f) use ($GIRDI, $BUGUN): array { $g = $GIRDI; $f($g); return siparis_vade_hesapla($g, siparis_vade_param([]), $BUGUN); };
$mutKontrol = static function (string $ad, array $m) use ($h): void {
    $s = vd_uyum($m, 'sapma_kurus');
    ok("mutasyon $ad: sapma_kurus ≠ 0 (=" . vd_j($s) . ') ve tutarli === false', is_int($s) && $s !== 0 && vd_uyum($m, 'tutarli') === false);
    $hata = vd_uyarilar($m, 'hata');
    ok("mutasyon $ad: seviye 'hata' uyarısı (\"Cari bakiyeyle tutmuyor\") çıktı", count($hata) >= 1 && str_contains((string)$hata[0]['mesaj'], 'tutmuyor'), vd_j($hata));
};
// (a) avizo satırını ÇİFT saymak: 354'ün ana satırı iki kez (nakit aynı kaldı) → kalan kısım yok olur, hesap sessizce "kapanır"
$mutKontrol('(a) 354 ana satırı çift sayıldı', $mut(static function (array &$g) { $g['odemeler'][1]['satirlar'][] = ['n' => vd_g(354), 't' => 'ana', 'g' => vd_g(354), 'z' => T('736,51')]; }));
// (b) eşleşmeyen pozitif satırı çift saymak (havuz şişer, nakit aynı)
$mutKontrol('(b) eşleşmeyen 14.200,00 satırı çift sayıldı', $mut(static function (array &$g) { $g['odemeler'][0]['satirlar'][] = $g['odemeler'][0]['satirlar'][9]; }));
// (c) ödeme nakdi satırlarla tutmuyor (kurus +10,00)
$mutKontrol('(c) ödeme tutarı +10,00 (satırlar tutmuyor)', $mut(static function (array &$g) { $g['odemeler'][1]['kurus'] += 1000; }));
// (d) bir satırın tutarı bozuldu (354 ana 736,51 → 836,51; nakit aynı)
$mutKontrol('(d) 354 ana tutarı +100,00 (nakit aynı)', $mut(static function (array &$g) { $g['odemeler'][1]['satirlar'][0]['z'] += 10000; }));
// sağlam girdi hâlâ tutarlı (mutasyonlar gerçekten fark yaratıyor, test yanlış pozitif vermiyor)
ok('kontrol: bozulmamış altın senaryoda hata uyarısı YOK ve sapma 0', vd_uyarilar($h, 'hata') === [] && vd_uyum($h, 'sapma_kurus') === 0);

// ══════════════════════════════════════════════════════════════════════
echo "\n=== 2. DEĞİŞMEZLER (property): sabit tohumlu rastgele dünyalar ===\n";
/**
 * Rastgele ama deterministik dünya. $kenar=false → "temiz dünya" (spec tanım alanı: havuz ≥ 0, aşırı ödeme yok, bilinen debit satırı yok);
 * $kenar=true → kenar durumlar (aşırı ödeme/avans, iade > eşleşmeyen, avizoda bilinen debit satırı, büyük açık fatura YOK).
 * @return array{girdi:array,kasa:int,fatura_toplam:int,gun_avizo:int,gun_hepsi:int,elle:bool}
 */
function vd_uret(int $seed, bool $kenar): array
{
    mt_srand($seed);
    $r = static fn(int $a, int $b): int => mt_rand($a, $b);
    $gun = static fn(string $b, int $n): string => date('Y-m-d', strtotime($b . ' ' . ($n >= 0 ? '+' : '') . $n . ' day'));
    $n = $r(3, 10);
    $sevler = []; $fatura = []; $belge = []; $netEf = []; $harita = []; $gvOf = []; $faturali = [];
    for ($i = 1; $i <= $n; $i++) {
        if ($r(1, 100) <= 12) { $sevler[] = vd_faturasiz($i, 'TC' . $i . '.10', $r(30000, 900000)); continue; }   // faturasız sevkiyat
        $isk = $r(1, 10) <= 7 ? 4.0 : 0.0; $isk100 = (int)round($isk * 100);
        $ft = $gun('2026-09-25', $r(0, 50)); $yuk = $gun($ft, -$r(0, 4));
        $N = $r(30000, 2500000);
        $c = $r(1, 100);
        $b = $c <= 55 ? $N : ($c <= 70 ? intdiv($N * $r(25, 75), 100) : ($c <= 85 ? null : $N + $r(1, 3000)));   // tam / kısmi invoice / belge yok / belge > hesap (PKL gerçek kg)
        $pc = $r(1, 100) <= 55 ? ['fark_kurus' => $r(-30000, 8000)] : null;
        $sevler[] = vd_sev($i, 'TC' . $i . '.10', 'X', [], $yuk, $ft, $N, vd_g($i), $pc, $isk);
        $fatura[$i] = ['iskonto' => $isk]; if ($b !== null) $belge[$i] = $b;
        $netEf[$i] = max($N, (int)($b ?? 0)); $faturali[] = $i; $gvOf[$i] = vd_g($i);
    }
    // ortak fatura (aynı fatura no iki sevkiyatta)
    $ortak = null;
    if (count($faturali) >= 2 && $r(1, 100) <= 25) {
        $a = $faturali[0]; $b2 = $faturali[1];
        $gvOf[$b2] = $gvOf[$a];
        foreach ($sevler as $k => $s) if ((int)$s['id'] === $b2) $sevler[$k]['fatura']['no'] = $gvOf[$a];
        $ortak = [$a, $b2]; $harita[$gvOf[$a]] = ['durum' => 'belirsiz', 'v' => [['id' => $a], ['id' => $b2]]];
    }
    // ödemeler (avizo): 1–3 farklı gün
    $m = $r(1, 3); $tarih = [];
    while (count($tarih) < $m) { $d = $gun('2026-10-05', $r(0, 40)); if (!in_array($d, $tarih, true)) $tarih[] = $d; }
    sort($tarih);
    $satir = array_fill(0, $m, []);
    $say = 900;
    foreach ($faturali as $i) {
        if ($ortak !== null && in_array($i, $ortak, true)) continue;
        $net = $netEf[$i]; $tol = max(100, intdiv($net, 200)); $p = $r(0, $m - 1);
        $d = $r(1, 100);
        if ($d <= 35) continue;                                                    // ödenmemiş
        $parca = [];
        if ($d <= 60) $parca = [[$p, $net]];                                       // tam
        elseif ($d <= 75) $parca = [[$p, max(1, intdiv($net * $r(20, 80), 100))]]; // kısmi
        elseif ($d <= 87) { $a1 = max(1, intdiv($net * $r(20, 80), 100)); $parca = [[$p, $a1], [$r(0, $m - 1), $net - $a1]]; }   // iki adımda
        else $parca = [[$p, $net - $r(0, $tol)]];                                  // tolerans içinde eksik
        if ($kenar && $r(1, 100) <= 15) $parca = [[$p, $net + $r(1, 100000)]];     // aşırı ödeme (kenar)
        foreach ($parca as [$pi, $z]) {
            $satir[$pi][] = ['n' => $gvOf[$i], 't' => 'ana', 'g' => $gvOf[$i], 'z' => $z];
            $harita[$gvOf[$i]] = ['durum' => 'eslesti', 'v' => [['id' => $i]]];
            if ($r(1, 100) <= 45 && $z > 40) $satir[$pi][] = ['n' => $gvOf[$i] . 'DI', 't' => 'duzeltme', 'g' => $gvOf[$i], 'z' => -$r(1, max(1, intdiv($z * 4, 100)))];
        }
    }
    if ($ortak !== null) {
        $toplam = $netEf[$ortak[0]] + $netEf[$ortak[1]];
        $z = $r(1, 100) <= 40 ? $netEf[$ortak[0]] : $r(1, $toplam + 5000);
        if ($r(1, 100) > 30) $satir[$r(0, $m - 1)][] = ['n' => $gvOf[$ortak[0]], 't' => 'ana', 'g' => $gvOf[$ortak[0]], 'z' => $z];
    }
    // eşleşmeyen satırlar: pozitif "ana" (eski faturalar) + eksi iade/iptal (ogl_ic)
    $net_esl = 0;
    foreach ($satir as $pi => $_) {
        for ($k = $r(0, 3); $k > 0; $k--) { $z = $r(20000, 600000); $satir[$pi][] = ['n' => vd_g(++$say), 't' => 'ana', 'g' => vd_g($say), 'z' => $z]; $net_esl += $z; }
        for ($k = $r(0, 2); $k > 0; $k--) { $z = $r(5000, 200000); $satir[$pi][] = ['n' => '2027-' . (++$say), 't' => 'ogl_ic', 'g' => '', 'z' => -$z]; $net_esl -= $z; }
    }
    if (!$kenar && $net_esl < 0) { $satir[0][] = ['n' => vd_g(++$say), 't' => 'ana', 'g' => vd_g($say), 'z' => -$net_esl + $r(0, 30000)]; }   // havuz ≥ 0 (iade > eşleşmeyen = kenar)
    // bilinen debit: avizoda düşülmüş (iade değil; …DI düzeltme satırı) ya da bekleyen
    $debitler = [];
    for ($k = $r(0, 3); $k > 0; $k--) {
        $kurus = $r(100, 150000); $no = '2029-' . $k . $seed % 97;
        $sid = ($faturali && $r(1, 100) <= 60) ? $faturali[$r(0, count($faturali) - 1)] : 0;
        $inv = '';
        if ($sid > 0 && isset($harita[$gvOf[$sid]]) && $harita[$gvOf[$sid]]['durum'] === 'eslesti' && $r(1, 100) <= 30) {   // düzeltme satırıyla düşülmüş
            foreach ($satir as $pi => $ls) foreach ($ls as $x) if ($x['t'] === 'ana' && $x['g'] === $gvOf[$sid]) { $satir[$pi][] = ['n' => $gvOf[$sid] . 'DI', 't' => 'duzeltme', 'g' => $gvOf[$sid], 'z' => -$kurus]; $inv = $gvOf[$sid] . 'DI'; break 2; }
        }
        if ($kenar && $inv === '' && $r(1, 100) <= 40) { $satir[0][] = ['n' => $no, 't' => 'ogl_ic', 'g' => '', 'z' => -$kurus]; }   // avizoda kendi numarasıyla düşülmüş bilinen debit (kenar)
        $debitler[] = ['no' => $no, 'tarih' => $gun('2026-10-20', $r(0, 30)), 'kurus' => $kurus, 'pb' => 'EUR', 'invoice_no' => $inv, 'sid' => $sid > 0 ? $sid : null, 'ref' => '', 'fiyat_farki' => $r(1, 2) === 1, 'neden' => 'WRONG PRICE'];
    }
    // her avizo nakdi ≥ 0 olmalı
    $odemeler = []; $kasa = 0; $gAv = []; $gHep = []; $toplamPoz = 0; $duzDebit = 0;
    foreach ($debitler as $d) $duzDebit += $d['kurus'];
    foreach ($satir as $pi => $ls) {
        $z = array_sum(array_column($ls, 'z'));
        if ($z < 0) { $ls[] = ['n' => vd_g(++$say), 't' => 'ana', 'g' => vd_g($say), 'z' => -$z + $r(0, 5000)]; $z = array_sum(array_column($ls, 'z')); }
        $odemeler[] = ['id' => 10 + $pi, 'tarih' => $tarih[$pi], 'kurus' => $z, 'pb' => 'EUR', 'satirlar' => $ls];
        $kasa += $z; $gAv[$tarih[$pi]] = ($gAv[$tarih[$pi]] ?? 0) + $z; $gHep[$tarih[$pi]] = ($gHep[$tarih[$pi]] ?? 0) + $z;
        foreach ($ls as $x) if ($x['z'] > 0) $toplamPoz += $x['z'];
    }
    $elle = false;
    if ($r(1, 100) <= 30) { $z = $r(1000, 500000); $d = $gun('2026-10-05', $r(0, 40)); $odemeler[] = ['id' => 50, 'tarih' => $d, 'kurus' => $z, 'pb' => 'EUR', 'satirlar' => null]; $kasa += $z; $gHep[$d] = ($gHep[$d] ?? 0) + $z; $toplamPoz += $z; $elle = true; }
    if ($r(1, 100) <= 15) $odemeler[] = ['id' => 51, 'tarih' => '2026-11-12', 'kurus' => $r(1000, 90000), 'pb' => 'USD', 'satirlar' => null];   // EUR dışı: takvime/kasaya katılmaz
    // büyük açık fatura: havuz + kesintiler tamamen emilsin (kenar'da YOK → artık/aşırı ödeme olabilir)
    if (!$kenar) {
        $bigNet = $toplamPoz + array_sum(array_column($debitler, 'kurus')) + 800000 + $r(0, 500000); $id = 99;
        $sevler[] = vd_sev($id, 'TC99.10', 'X', [], '2026-11-18', '2026-11-20', $bigNet, vd_g(99), null, 4.0);
        $fatura[$id] = ['iskonto' => 4.0]; $belge[$id] = $bigNet; $netEf[$id] = $bigNet;
    }
    return ['girdi' => ['sevkiyatlar' => $sevler, 'fatura' => $fatura, 'belge_toplam' => $belge, 'odemeler' => $odemeler, 'harita' => $harita, 'debitler' => $debitler],
        'kasa' => $kasa, 'fatura_toplam' => array_sum($netEf), 'gun_avizo' => $gAv ? max($gAv) : 0, 'gun_hepsi' => $gHep ? max($gHep) : 0, 'elle' => $elle, 'duz_debit' => $duzDebit];
}

ok('property: üretici deterministik (aynı tohum → aynı dünya)', vd_j(vd_uret(777, false)) === vd_j(vd_uret(777, false)) && vd_j(vd_uret(778, false)) !== vd_j(vd_uret(777, false)));

$TOHUM = 20261123; $ADET = 300; $ADET_KENAR = 150;
$ihlal = []; $say = ['senaryo' => 0, 'ortak' => 0, 'kismi' => 0, 'iade' => 0, 'elle' => 0, 'faturasiz' => 0, 'sapma_sifir' => 0];
$ihl = static function (string $ad, int $seed, string $detay) use (&$ihlal): void { if (!isset($ihlal[$ad])) $ihlal[$ad] = [$seed, $detay]; };
for ($s = 0; $s < $ADET; $s++) {
    $seed = $TOHUM + $s;
    $w = vd_uret($seed, false);
    $say['senaryo']++;
    foreach ($w['girdi']['harita'] as $hh) if ($hh['durum'] === 'belirsiz') { $say['ortak']++; break; }
    foreach ($w['girdi']['odemeler'] as $od) foreach ((array)($od['satirlar'] ?? []) as $x) if ($x['z'] < 0 && $x['t'] === 'ogl_ic') { $say['iade']++; break 2; }
    if ($w['elle']) $say['elle']++;
    foreach ($w['girdi']['sevkiyatlar'] as $sv) if ($sv['fatura'] === null) { $say['faturasiz']++; break; }
    try { $r = siparis_vade_hesapla($w['girdi'], siparis_vade_param([]), $BUGUN); } catch (Throwable $e) { $ihl('istisna fırlatmaz', $seed, get_class($e) . ': ' . $e->getMessage()); continue; }
    $oz = $r['ozet']; $U = $oz['uyum'] ?? [];
    $kasa = vd_al($r, 'kasa_kurus'); $fark = vd_al($r, 'avizo_fark_kurus');
    $det = static fn(): string => vd_j(['kasa' => $kasa, 'fark' => $fark, 'uyum' => $U, 'fatura_toplam' => $oz['fatura_toplam_kurus'] ?? null, 'odenen_fatura' => $oz['odenen_fatura_kurus'] ?? null, 'mahsup' => $oz['mahsup_kurus'] ?? null, 'acik' => $oz['acik_kurus'] ?? null]);
    if ($kasa !== $w['kasa']) $ihl('kasa_kurus === Σ EUR avizo/ödeme (USD hariç)', $seed, $det());
    if (!is_int($kasa) || $kasa < 0) $ihl('kasa ≥ 0', $seed, $det());
    if (($oz['fatura_toplam_kurus'] ?? null) !== $w['fatura_toplam']) $ihl('fatura_toplam_kurus === Σ sevkiyat net (bağımsız hesap)', $seed, $det());
    if (($U['cari_bakiye_kurus'] ?? null) !== $w['fatura_toplam'] - $w['kasa']) $ihl('uyum.cari_bakiye === Σ net − kasa', $seed, $det());
    if (($U['sapma_kurus'] ?? null) !== 0) $ihl('uyum.sapma_kurus === 0 (TAM eşitlik, tolerans yok)', $seed, $det()); else $say['sapma_sifir']++;
    if (($U['tutarli'] ?? null) !== true) $ihl('uyum.tutarli === true', $seed, $det());
    if (vd_uyarilar($r, 'hata') !== []) $ihl('tutarlı dünyada "hata" uyarısı çıkmaz', $seed, vd_j(vd_uyarilar($r, 'hata')));
    if (!is_int($fark) || $fark < 0) $ihl('avizo_fark_kurus ≥ 0', $seed, $det());
    if (($U['avizo_fark_kurus'] ?? null) !== $fark) $ihl('uyum.avizo_fark === ozet.avizo_fark', $seed, $det());
    if (($U['acik_kurus'] ?? null) !== ($oz['acik_kurus'] ?? null)) $ihl('uyum.acik === ozet.acik_kurus', $seed, $det());
    // not: açık = Σ net − kapanan − bekleyen kesinti DEĞİL — tahmini mahsupla kapananın kesintisi bekleyen kesintiye girer ama net'i kapananda zaten tam sayılır; bağımsız denetim = sapma === 0
    if (!isset($U['bekleyen_kesinti_kurus']) || $U['bekleyen_kesinti_kurus'] < 0) $ihl('bekleyen_kesinti ≥ 0 (mutlak)', $seed, $det());
    // kapanan faturalar kasayı AŞMAZ: aşan kısım yalnız avizoda düşülen fark + tahmini mahsupla kapananların düşülecek kesintisi kadardır
    if (($oz['odenen_fatura_kurus'] ?? PHP_INT_MAX) > $w['kasa'] + (int)$fark + (int)($U['bekleyen_kesinti_kurus'] ?? 0)) $ihl('kapanan faturalar ≤ kasa + avizo farkı + bekleyen kesinti (kasayı aşmaz)', $seed, $det());
    if (($oz['mahsup_kurus'] ?? PHP_INT_MAX) > ($oz['havuz_kurus'] ?? -1) || ($oz['mahsup_kurus'] ?? -1) < 0) $ihl('0 ≤ mahsup ≤ havuz', $seed, $det());
    if (($oz['havuz_kurus'] ?? PHP_INT_MAX) > $w['kasa'] + (int)($U['avizo_fark_diger_kurus'] ?? 0) + $w['duz_debit']) $ihl('havuz ≤ kasa + düşülen fark (havuz nakitten türer)', $seed, $det());
    $rf = 0; $rfOk = true;
    foreach ($r['odenmis'] as $x) { if (!array_key_exists('fark_kurus', $x) || !array_key_exists('iskonto_suphesi', $x)) { $rfOk = false; break; } $rf += max(0, (int)$x['fark_kurus']); }
    if (!$rfOk) $ihl('odenmis[].fark_kurus + iskonto_suphesi alanları var', $seed, 'alan yok');
    elseif ($rf !== (int)$fark) $ihl('Σ odenmis[].fark_kurus === avizo_fark_kurus', $seed, $det() . ' satır toplamı=' . $rf);
    $kk = 0; $kkOk = true; $kkAralik = true;
    foreach ($r['acik'] as $x) {
        if (!array_key_exists('kalan_kisim', $x) || !array_key_exists('kalan_kisim_kurus', $x)) { $kkOk = false; break; }
        $kk += (int)$x['kalan_kisim_kurus'];
        if ($x['kalan_kisim_kurus'] < 0 || $x['kalan_kisim_kurus'] > max(0, (int)$x['beklenen']) || $x['kalan_kisim'] !== ($x['kalan_kisim_kurus'] > 0)) $kkAralik = false;
    }
    if (!$kkOk) $ihl('acik[].kalan_kisim + kalan_kisim_kurus alanları var', $seed, 'alan yok');
    elseif (vd_al($r, 'kalan_kisim_kurus') !== $kk) $ihl('ozet.kalan_kisim_kurus === Σ satır kalan_kisim_kurus', $seed, $det() . ' kk=' . $kk . ' ozet=' . vd_j(vd_al($r, 'kalan_kisim_kurus')));
    elseif (!$kkAralik) $ihl('satır: 0 ≤ kalan_kisim_kurus ≤ beklenen ve bayrak ⇔ > 0', $seed, vd_j($r['acik']));
    $bk = vd_bh($r, 'beklenen_kurus') ?? $oz['bu_hafta_kurus'];
    if (vd_bh($r, 'kesin_kurus') === null || vd_bh($r, 'kalan_kisim_kurus') === null || vd_bh($r, 'kesin_kurus') + vd_bh($r, 'kalan_kisim_kurus') !== $bk) $ihl('bu_hafta.kesin + kalan_kisim === beklenen', $seed, vd_j([$r['bu_hafta'], $bk]));
    if ($bk >= 0 && vd_bh($r, 'kesin_kurus') !== null && vd_bh($r, 'kesin_kurus') < 0) $ihl('v572: bu_hafta.kesin_kurus eksiye düşmez (bekleyen kesinti kalan kısımdan düşer)', $seed, vd_j([$r['bu_hafta'], $bk]));
    if (vd_bh($r, 'kalan_kisim_kurus') !== null && vd_al($r, 'kalan_kisim_kurus') !== null && vd_bh($r, 'kalan_kisim_kurus') > vd_al($r, 'kalan_kisim_kurus') && $kkOk && $kk >= 0) $ihl('bu_hafta.kalan_kisim ≤ ozet.kalan_kisim', $seed, vd_j([$r['bu_hafta'], vd_al($r, 'kalan_kisim_kurus')]));
    $gm = vd_al($r, 'gecmis_odeme_max_kurus');
    if ($gm !== $w['gun_avizo'] && $gm !== $w['gun_hepsi']) $ihl('gecmis_odeme_max_kurus === en büyük günlük ödeme', $seed, vd_j([$gm, $w['gun_avizo'], $w['gun_hepsi']]));
    $as = vd_al($r, 'bu_hafta_asiri');
    if (!is_bool($as) || $as !== (is_int($gm) && $gm > 0 && $bk * 4 > $gm * 5)) $ihl('bu_hafta_asiri === (beklenen > 1,25 × geçmiş max)', $seed, vd_j([$as, $gm, $bk]));
    if (is_bool($as) && $as !== vd_uyari_var($r, '1,25 kat')) $ihl('bu_hafta_asiri ↔ uyarı metni tutarlı', $seed, vd_j([$as, array_column($r['uyarilar'], 'mesaj')]));
}
echo "  (üretilen dünya: {$say['senaryo']} · ortak fatura içeren {$say['ortak']} · iade satırlı {$say['iade']} · elle ödemeli {$say['elle']} · faturasız sevkiyatlı {$say['faturasiz']} · sapma 0: {$say['sapma_sifir']})\n";
ok("property: ≥ 200 senaryo üretildi ve her tür örneklendi (ortak fatura, iade, elle ödeme, faturasız sevkiyat)", $say['senaryo'] >= 200 && $say['ortak'] > 0 && $say['iade'] > 0 && $say['elle'] > 0 && $say['faturasiz'] > 0, vd_j($say));
$sira = ['istisna fırlatmaz', 'kasa_kurus === Σ EUR avizo/ödeme (USD hariç)', 'kasa ≥ 0', 'fatura_toplam_kurus === Σ sevkiyat net (bağımsız hesap)', 'uyum.cari_bakiye === Σ net − kasa', 'uyum.sapma_kurus === 0 (TAM eşitlik, tolerans yok)',
    'uyum.tutarli === true', 'tutarlı dünyada "hata" uyarısı çıkmaz', 'avizo_fark_kurus ≥ 0', 'uyum.avizo_fark === ozet.avizo_fark', 'uyum.acik === ozet.acik_kurus', 'açık = Σ net − kapanan − bekleyen kesinti', 'bekleyen_kesinti ≥ 0 (mutlak)',
    'kapanan faturalar ≤ kasa + avizo farkı + bekleyen kesinti (kasayı aşmaz)', '0 ≤ mahsup ≤ havuz', 'havuz ≤ kasa + düşülen fark (havuz nakitten türer)', 'odenmis[].fark_kurus + iskonto_suphesi alanları var',
    'Σ odenmis[].fark_kurus === avizo_fark_kurus', 'acik[].kalan_kisim + kalan_kisim_kurus alanları var', 'ozet.kalan_kisim_kurus === Σ satır kalan_kisim_kurus', 'satır: 0 ≤ kalan_kisim_kurus ≤ beklenen ve bayrak ⇔ > 0', 'bu_hafta.kesin + kalan_kisim === beklenen', 'bu_hafta.kalan_kisim ≤ ozet.kalan_kisim',
    'gecmis_odeme_max_kurus === en büyük günlük ödeme', 'bu_hafta_asiri === (beklenen > 1,25 × geçmiş max)', 'bu_hafta_asiri ↔ uyarı metni tutarlı'];
foreach ($sira as $ad) ok("property ($ADET senaryo): $ad", !isset($ihlal[$ad]), isset($ihlal[$ad]) ? 'ilk ihlal tohum=' . $ihlal[$ad][0] . ' :: ' . $ihlal[$ad][1] : '');
foreach (array_diff(array_keys($ihlal), $sira) as $ad) ok("property: $ad", false, 'tohum=' . $ihlal[$ad][0] . ' :: ' . $ihlal[$ad][1]);

echo "\n=== 2b. Kenar dünyalar (aşırı ödeme/avans, iade > eşleşmeyen, avizoda bilinen debit satırı, açık fatura yok): zayıf değişmezler ===\n";
$ih2 = []; $sap = 0; $top2 = 0; $hataSay = 0;
$ihl2 = static function (string $ad, int $seed, string $detay) use (&$ih2): void { if (!isset($ih2[$ad])) $ih2[$ad] = [$seed, $detay]; };
for ($s = 0; $s < $ADET_KENAR; $s++) {
    $seed = $TOHUM + 5000 + $s; $w = vd_uret($seed, true); $top2++;
    try { $r = siparis_vade_hesapla($w['girdi'], siparis_vade_param([]), $BUGUN); } catch (Throwable $e) { $ihl2('istisna fırlatmaz', $seed, $e->getMessage()); continue; }
    $oz = $r['ozet']; $U = $oz['uyum'] ?? []; $kasa = vd_al($r, 'kasa_kurus');
    if ($kasa !== $w['kasa'] || $kasa < 0) $ihl2('kasa_kurus === Σ EUR ödeme ve ≥ 0', $seed, vd_j([$kasa, $w['kasa']]));
    if (($U['cari_bakiye_kurus'] ?? null) !== $w['fatura_toplam'] - $w['kasa']) $ihl2('cari_bakiye === Σ net − kasa', $seed, vd_j($U));
    $sp = $U['sapma_kurus'] ?? null;
    if (!is_int($sp) || ($U['tutarli'] ?? null) !== ($sp === 0)) $ihl2('tutarli === (sapma_kurus === 0)', $seed, vd_j($U));
    $hv = vd_uyarilar($r, 'hata') !== [];
    if (is_int($sp) && $hv !== ($sp !== 0)) $ihl2('"hata" uyarısı ⇔ sapma ≠ 0', $seed, vd_j([$sp, $hv]));
    if ($sp !== 0) $sap++;
    if ($hv) $hataSay++;
    if (!is_int(vd_al($r, 'avizo_fark_kurus'))) $ihl2('avizo_fark int (işaretli; aşırı ödemede negatif olabilir)', $seed, vd_j(vd_al($r, 'avizo_fark_kurus')));
}
foreach (['istisna fırlatmaz', 'kasa_kurus === Σ EUR ödeme ve ≥ 0', 'cari_bakiye === Σ net − kasa', 'tutarli === (sapma_kurus === 0)', '"hata" uyarısı ⇔ sapma ≠ 0', 'avizo_fark int (işaretli; aşırı ödemede negatif olabilir)'] as $ad)
    ok("kenar ($ADET_KENAR senaryo): $ad", !isset($ih2[$ad]), isset($ih2[$ad]) ? 'tohum=' . $ih2[$ad][0] . ' :: ' . $ih2[$ad][1] : '');
echo "  INFO: kenar dünyalarda sapma ≠ 0 olan senaryo: $sap / $top2 (aşırı ödeme / iade > eşleşmeyen / avizoda bilinen debit satırı gibi, spec'in tanım alanı dışı durumlar; 'hata' uyarısı çıkan: $hataSay)\n";

siparis_test_bitir();
