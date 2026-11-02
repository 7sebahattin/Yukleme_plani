<?php
// =========================================================
// scripts/siparis_denge_smoke.php — Fatura fiyatı haritası + Fiyat Dengeleme (v448): config/siparis_cari.php + config/siparis_denge.php
//   php scripts/siparis_denge_smoke.php
// Bellek içi SQLite + stub auth + SENTETİK veri (34TST…, TC8100…; kişisel veri YOK). Canlı DB'ye HİÇ dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/siparis_cari.php';
require_once $ROOT . '/config/siparis_denge.php';

$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);
$PERMS = ['siparis.read', 'reports.export']; $DEPOLAR = ['DEPO1'];

function sev(PDO $db, array $a): int
{
    $v = $a + ['tedarikci_no' => '', 'durum' => 'mail_gonderildi', 'depo' => 'DEPO1', 'var_po' => 1];
    $db->prepare('INSERT INTO siparis_sevkiyatlar (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
    return (int)$db->lastInsertId();
}
function kalem(PDO $db, int $sid, array $a): void
{
    $v = $a + ['sevkiyat_id' => $sid, 'ian' => '', 'sira' => 1, 'koli_icerik' => '3,5 KG', 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG'];
    $db->prepare('INSERT INTO siparis_kalemler (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
}
/** Geçerli invoice belgesi + bağ (girdideki belge_id ile; v448: silinmiş/bağsız belgenin girdisi sayılmaz). */
function inv_belge(PDO $db, int $bid, int $sid, bool $gecerli = true, ?string $silindi = null): void
{
    $db->prepare("INSERT OR IGNORE INTO siparis_belgeler (id, tur, kaynak, durum, sha256, disk_adi, orijinal_ad, mime, deleted_at) VALUES (?, 'invoice', 'uretim', 'islendi', ?, ?, ?, 'application/pdf', ?)")
        ->execute([$bid, str_pad((string)$bid, 64, '0', STR_PAD_LEFT), 'd' . $bid . '.pdf', 'INV-' . $bid . '.pdf', $silindi]);
    $db->prepare('INSERT OR IGNORE INTO siparis_belge_bag (belge_id, sevkiyat_id, gecerli) VALUES (?, ?, ?)')->execute([$bid, $sid, $gecerli ? 1 : 0]);
}
function inv(PDO $db, int $sid, array|string $girdi): void
{
    $db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_' . $sid, is_string($girdi) ? $girdi : json_encode($girdi)]);
    if (is_array($girdi) && !empty($girdi['uretildi_at']) && (int)($girdi['belge_id'] ?? 0) > 0) inv_belge($db, (int)$girdi['belge_id'], $sid);
}
$uretildi = static fn(string $fiyat, array $ek = []): array => $ek + ['no' => 'IHR2026000000001', 'tarih' => '2026-10-20', 'fiyat' => $fiyat, 'iskonto' => '0', 'uretildi_at' => '2026-10-21 10:00:00', 'belge_id' => 77];

$bugun = date('Y-m-d'); $dun = date('Y-m-d', strtotime('-1 day')); $once = date('Y-m-d', strtotime('-3 day')); $yarin = date('Y-m-d', strtotime('+1 day'));

// ── tohum ──
$ID = [];
// s1: tek kalibre 18.200 kg (5200 koli × 3,5), PC 1,45 / fatura 1,37
$ID['s1'] = sev($db, ['order_ref' => 'TC810001.10', 'yukleme_tarihi' => $once, 'alici_kodu' => '1038529']);
kalem($db, $ID['s1'], ['kalibre' => '14', 'aciklama' => 'Testfruit 14 PC', 'po_palet' => 20, 'etkin_palet' => 20, 'etkin_koli' => 5200, 'pc_koli' => 5200, 'fiyat' => 1.45]);
// s2: çok kalibreli (2000 koli × 1,50 + 1000 koli × 1,60 = 10.500 kg), fatura 1,40
$ID['s2'] = sev($db, ['order_ref' => 'TC810002.10', 'yukleme_tarihi' => $dun, 'durum' => 'yola_cikti']);
kalem($db, $ID['s2'], ['kalibre' => '12', 'sira' => 1, 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 2000, 'pc_koli' => 2000, 'fiyat' => 1.5]);
kalem($db, $ID['s2'], ['kalibre' => '10', 'sira' => 2, 'aciklama' => 'Testfruit 10 PC', 'etkin_koli' => 1000, 'pc_koli' => 1000, 'fiyat' => 1.6]);
// s3: USD satırlı (EUR olmayan) + invoice var → para birimi atlanır
$ID['s3'] = sev($db, ['order_ref' => 'TC810003.10', 'yukleme_tarihi' => $dun]);
kalem($db, $ID['s3'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 2.0, 'para_birimi' => 'USD']);
// s4: EUR fiyatlı, invoice yok → fatura_yok
$ID['s4'] = sev($db, ['order_ref' => 'TC810004.10', 'yukleme_tarihi' => $dun]);
kalem($db, $ID['s4'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 2.0]);
// s5: yüklenen, PC fiyatı yok, invoice var → pc_yok
$ID['s5'] = sev($db, ['order_ref' => 'TC810005.10', 'yukleme_tarihi' => $dun]);
kalem($db, $ID['s5'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 100, 'pc_koli' => 100]);
// s6: EUR fiyatlı ama invoice girdisi ÜRETİLMEMİŞ → fatura_yok
$ID['s6'] = sev($db, ['order_ref' => 'TC810006.10', 'yukleme_tarihi' => $dun]);
kalem($db, $ID['s6'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 2.0]);
// s7: karışık para birimi (10 = USD, 12 = EUR) + fatura 1,90 → yalnız EUR satırı karşılaştırılır
$ID['s7'] = sev($db, ['order_ref' => 'TC810007.10', 'yukleme_tarihi' => $yarin]);
kalem($db, $ID['s7'], ['kalibre' => '12', 'sira' => 1, 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 2.0]);
kalem($db, $ID['s7'], ['kalibre' => '10', 'sira' => 2, 'aciklama' => 'Testfruit 10 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 2.0, 'para_birimi' => 'USD']);
// s8: koli bazlı (CAR) fiyat: 100 koli × 3,5 kg, 7,00 €/koli → kg fiyatı 2,00; fatura 2,10
$ID['s8'] = sev($db, ['order_ref' => 'TC810008.10', 'yukleme_tarihi' => $bugun]);
kalem($db, $ID['s8'], ['kalibre' => '14', 'aciklama' => 'Testfruit 14 PC', 'etkin_koli' => 100, 'pc_koli' => 100, 'fiyat' => 7.0, 'fiyat_birim' => 'CAR']);
$tumId = array_values($ID);

echo "=== 1. Fatura haritası (hks_kv) ===\n";
ok('hks_kv tablosu yokken → [] (fail soft)', siparis_cari_fatura_fiyatlari($db, $tumId) === []);
$db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)');
ok('tablo var ama boş → []', siparis_cari_fatura_fiyatlari($db, $tumId) === []);
ok('boş id listesi → []', siparis_cari_fatura_fiyatlari($db, []) === []);
inv($db, $ID['s1'], $uretildi('1.37', ['no' => 'IHR2026000000340', 'tarih' => '2026-10-03', 'iskonto' => '5']));
inv($db, $ID['s2'], $uretildi('1,40'));
inv($db, $ID['s3'], $uretildi('1.00'));
inv($db, $ID['s5'], $uretildi('1.20'));
inv($db, $ID['s6'], ['no' => 'X', 'tarih' => '2026-10-20', 'fiyat' => '1.20', 'iskonto' => '0']);                 // uretildi_at / belge_id yok
inv($db, $ID['s7'], $uretildi('1.9'));
inv($db, $ID['s8'], $uretildi('2.1', ['belge_id' => 78]));
$h = siparis_cari_fatura_fiyatlari($db, $tumId);
ok('üretilmiş girdiler okunur: s1 s2 s3 s5 s7 s8', array_keys($h) === [$ID['s1'], $ID['s2'], $ID['s3'], $ID['s5'], $ID['s7'], $ID['s8']] || (function () use ($h, $ID) { $k = array_keys($h); sort($k); return $k === [$ID['s1'], $ID['s2'], $ID['s3'], $ID['s5'], $ID['s7'], $ID['s8']]; })(), json_encode(array_keys($h)));
ok('fiyat on-binde int; no / tarih / iskonto', $h[$ID['s1']] === ['fiyat' => 13700, 'no' => 'IHR2026000000340', 'tarih' => '2026-10-03', 'iskonto' => 5.0], json_encode($h[$ID['s1']]));
ok('virgüllü fiyat "1,40" → 14000; "1.9" → 19000', $h[$ID['s2']]['fiyat'] === 14000 && $h[$ID['s7']]['fiyat'] === 19000);
ok('üretilmemiş girdi (uretildi_at/belge_id yok) sayılmaz', !isset($h[$ID['s6']]));
inv($db, $ID['s4'], $uretildi('1.20', ['belge_id' => 0]));
ok('belge_id 0 sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
inv($db, $ID['s4'], $uretildi('1.20', ['uretildi_at' => '']));
ok('uretildi_at boş sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
inv($db, $ID['s4'], '{bozuk json');
ok('bozuk JSON atlanır, diğerleri etkilenmez', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]) && count(siparis_cari_fatura_fiyatlari($db, $tumId)) === 6);
foreach (['0', '0.00', '1000', '1500', '-1', 'abc', ''] as $kotu) {
    inv($db, $ID['s4'], $uretildi($kotu));
    ok('geçersiz fiyat "' . $kotu . '" sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
}
inv($db, $ID['s4'], $uretildi('999.9999'));
ok('sınır: 999,9999 geçerli (9999999)', (siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]['fiyat'] ?? 0) === 9999999);
inv($db, $ID['s4'], $uretildi('1.37255'));
ok('4 ondalıktan fazlası yuvarlanır (1.37255 → 13726)', (siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]['fiyat'] ?? 0) === 13726);
// — v448: belge silinince / bağı geçersizleşince / eski sürüm olunca hks_kv girdisi KALIR; fatura fiyatı olarak SAYILMAMALI —
$db->exec('DELETE FROM siparis_belge_bag WHERE sevkiyat_id = ' . $ID['s4']);
inv($db, $ID['s4'], $uretildi('1.20', ['belge_id' => 500]));
ok('geçerli belge + bağ → sayılır', isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
$db->exec('UPDATE siparis_belgeler SET deleted_at = ' . $db->quote('2026-10-22 10:00:00') . ' WHERE id = 500');
ok('belge SOFT SİLİNMİŞ (hks_kv girdisi kalmış) → sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
$db->exec('UPDATE siparis_belgeler SET deleted_at = NULL WHERE id = 500');
$db->exec('UPDATE siparis_belge_bag SET gecerli = 0 WHERE belge_id = 500 AND sevkiyat_id = ' . $ID['s4']);
ok('bağ gecerli=0 (eski sürüm) → sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
$db->exec('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = 500 AND sevkiyat_id = ' . $ID['s4']);
inv_belge($db, 501, $ID['s4'], true);   // başka (güncel) invoice belgesi; kv hâlâ 500'ü gösteriyor
$db->exec('UPDATE siparis_belge_bag SET gecerli = 0 WHERE belge_id = 500 AND sevkiyat_id = ' . $ID['s4']);
ok('kv belge_id ile geçerli belge FARKLIYSA → sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
$db->exec("UPDATE siparis_belgeler SET tur = 'pkl' WHERE id = 501");
$db->exec('UPDATE siparis_belge_bag SET gecerli = 1 WHERE belge_id = 500 AND sevkiyat_id = ' . $ID['s4']);
$db->exec("UPDATE siparis_belgeler SET tur = 'pkl' WHERE id = 500");
ok('belge türü invoice değilse → sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
$db->exec("UPDATE siparis_belgeler SET tur = 'invoice' WHERE id IN (500, 501)");
ok('tür invoice\'a dönünce yeniden sayılır', isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
inv($db, $ID['s4'], $uretildi('1.20', ['uretildi_at' => ['x']]));
ok('uretildi_at dizi (bozuk girdi) → uyarı yok, sayılmaz', !isset(siparis_cari_fatura_fiyatlari($db, $tumId)[$ID['s4']]));
inv($db, $ID['s4'], $uretildi('1.37255'));   // sonraki testler için eski durum
$db->exec("DELETE FROM hks_kv WHERE anahtar = 'siparis_invoice_" . $ID['s4'] . "'");
$db->prepare('REPLACE INTO hks_kv (anahtar, deger) VALUES (?, ?)')->execute(['siparis_invoice_ayar', json_encode(['rex_no' => 'x'])]);
ok('siparis_invoice_ayar anahtarı haritaya karışmaz', count(siparis_cari_fatura_fiyatlari($db, $tumId)) === 6);
$cok = range(1, 1200);
$cokH = siparis_cari_fatura_fiyatlari($db, array_merge($cok, $tumId));
ok('500\'lük parçalar: 1200+ id ile sonuç aynı', count($cokH) === 6);
ok('yalnız istenen id döner', array_keys(siparis_cari_fatura_fiyatlari($db, [$ID['s1']])) === [$ID['s1']]);

echo "\n=== 2. Biçim yardımcıları ===\n";
foreach ([[8, '+0,08'], [800, '+0,08'], [-1250, '-0,125'], [0, '0,00'], [-1, '-0,0001'], [12500, '+1,25'], [-13725, '-1,3725'], [100000, '+10,00'], [10, '+0,001']] as [$in, $beklenen]) {
    // 800 → 0,0800 → "+0,08"; 8 → 0,0008 → "+0,0008" (aşağıda ayrıca denetlenir)
    if ($in === 8) { ok('fark_metin(8) = +0,0008', siparis_cari_fark_metin(8) === '+0,0008'); continue; }
    ok('fark_metin(' . $in . ') = ' . $beklenen, siparis_cari_fark_metin($in) === $beklenen, siparis_cari_fark_metin($in));
}
ok('fark_metin: sıfır işaretsiz', siparis_cari_fark_metin(0) === '0,00' && !str_contains(siparis_cari_fark_metin(0), '+') && !str_contains(siparis_cari_fark_metin(0), '-'));
foreach ([[13700, '1,37'], [13725, '1,3725'], [14000, '1,40'], [10000, '1,00'], [0, '0,00'], [9999999, '999,9999'], [14500, '1,45'], [14510, '1,451']] as [$in, $beklenen]) {
    ok('fiyat_kisa(' . $in . ') = ' . $beklenen, siparis_cari_fiyat_kisa($in) === $beklenen, siparis_cari_fiyat_kisa($in));
}

echo "\n=== 3. siparis_cari_veri: fatura / karşılaştırma ===\n";
$f = siparis_cari_filtre([], $bugun);
$ids = array_map(static fn(array $r): int => (int)$r['id'], siparis_cari_oku($db, $f)['rows']);
$fh = siparis_cari_fatura_fiyatlari($db, $ids);
$v = siparis_cari_veri(siparis_cari_oku($db, $f), ['bugun' => $bugun, 'tahsilat' => [], 'fatura' => $fh]);
$v0 = siparis_cari_veri(siparis_cari_oku($db, $f), ['bugun' => $bugun, 'tahsilat' => []]);
$bul = static function (array $liste, string $ref): ?array { foreach ($liste as $s) if ($s['order_ref'] === $ref) return $s; return null; };
$s1 = $bul($v['yuklenen'], 'TC810001.10');
ok('s1: fatura = fiyat/no/tarih', $s1['fatura'] === ['fiyat' => 13700, 'no' => 'IHR2026000000340', 'tarih' => '2026-10-03'], json_encode($s1['fatura']));
ok('s1: karşılaştırma gram 18.200.000, PC 26.390,00 €, fatura 24.934,00 €, fark +1.456,00 € (gelen − kesilen)',
    $s1['karsilastirma'] === ['gram' => 18200000, 'pc_kurus' => 2639000, 'fatura_kurus' => 2493400, 'fark_kurus' => 145600], json_encode($s1['karsilastirma']));
ok('s1 satırı: fatura_kg 13700, fiyat_kg 14500, fark_kg +800 (gelen − kesilen)', $s1['satirlar'][0]['fatura_kg'] === 13700 && $s1['satirlar'][0]['fiyat_kg'] === 14500 && $s1['satirlar'][0]['fark_kg'] === 800);
$s2 = $bul($v['yuklenen'], 'TC810002.10') ?? $bul($v['yuklenmemis'], 'TC810002.10');
ok('s2 (yüklenmemiş, çok kalibreli): her satırda fatura 14000; fark_kg 10 → +2000, 12 → +1000', array_map(static fn(array $l): string => $l['kalibre'], $s2['satirlar']) === ['10', '12']
    && $s2['satirlar'][0]['fatura_kg'] === 14000 && $s2['satirlar'][1]['fatura_kg'] === 14000 && $s2['satirlar'][0]['fark_kg'] === 2000 && $s2['satirlar'][1]['fark_kg'] === 1000, json_encode($s2['satirlar']));
ok('s2: karşılaştırma toplamı (10.500 kg: PC 16.100,00 €, fatura 14.700,00 €, fark +1.400,00 €)', $s2['karsilastirma'] === ['gram' => 10500000, 'pc_kurus' => 1610000, 'fatura_kurus' => 1470000, 'fark_kurus' => 140000], json_encode($s2['karsilastirma']));
$s3 = $bul($v['yuklenen'], 'TC810003.10');
ok('s3 (USD): fatura bilgisi var, ama satırda fatura_kg/fark_kg null ve karsilastirma null', $s3['fatura'] !== null && $s3['satirlar'][0]['fatura_kg'] === null && $s3['satirlar'][0]['fark_kg'] === null && $s3['karsilastirma'] === null);
$s4 = $bul($v['yuklenen'], 'TC810004.10');
ok('s4 (invoice yok): fatura null, fatura_kg/fark_kg null, karsilastirma null', $s4['fatura'] === null && $s4['satirlar'][0]['fatura_kg'] === null && $s4['satirlar'][0]['fark_kg'] === null && $s4['karsilastirma'] === null);
$s5 = $bul($v['yuklenen'], 'TC810005.10');
ok('s5 (PC fiyatı yok): fatura var, fark_kg null, karsilastirma null', $s5['fatura']['fiyat'] === 12000 && $s5['satirlar'][0]['fark_kg'] === null && $s5['karsilastirma'] === null && $s5['fiyatli'] === false);
$s6 = $bul($v['yuklenen'], 'TC810006.10');
ok('s6 (üretilmemiş invoice girdisi): fatura null', $s6['fatura'] === null && $s6['karsilastirma'] === null);
$s7 = $bul($v['yuklenmemis'], 'TC810007.10') ?? $bul($v['yuklenen'], 'TC810007.10');
ok('s7 karışık: yalnız EUR satırı karşılaştırılır (gram 350.000, PC 700,00 €, fatura 665,00 €)', $s7['satirlar'][0]['kalibre'] === '10' && $s7['satirlar'][0]['pb'] === 'USD' && $s7['satirlar'][0]['fatura_kg'] === null && $s7['satirlar'][0]['fark_kg'] === null
    && $s7['satirlar'][1]['pb'] === 'EUR' && $s7['satirlar'][1]['fark_kg'] === 1000 && $s7['karsilastirma'] === ['gram' => 350000, 'pc_kurus' => 70000, 'fatura_kurus' => 66500, 'fark_kurus' => 3500], json_encode($s7['karsilastirma']));
$s8 = $bul($v['yuklenmemis'], 'TC810008.10') ?? $bul($v['yuklenen'], 'TC810008.10');
ok('s8 koli bazlı: PC kg fiyatı 2,00 (20000), fatura 2,10 → fark −1000 (kesilen yüksek → eksi)', $s8['satirlar'][0]['baz'] === 'koli' && $s8['satirlar'][0]['fiyat_kg'] === 20000 && $s8['satirlar'][0]['fark_kg'] === -1000 && $s8['karsilastirma']['fark_kurus'] === -3500, json_encode($s8['karsilastirma']));
ok('fatura haritası verilmezse alanlar null; mevcut anahtarlar aynı', $bul($v0['yuklenen'], 'TC810001.10')['fatura'] === null && $bul($v0['yuklenen'], 'TC810001.10')['karsilastirma'] === null
    && $bul($v0['yuklenen'], 'TC810001.10')['satirlar'][0]['fatura_kg'] === null && array_keys($v0) === array_keys($v));
ok('sevkiyat anahtar sırası: eski anahtarlar aynı sırada, fatura + karsilastirma sonda', array_slice(array_keys($s1), 0, 14) === ['id', 'order_ref', 'tarih', 'ulke', 'plaka', 'nakliyeci', 'yuklendi', 'fiyatli', 'fiyat_tarihi', 'satirlar', 'toplam', 'tutar', 'fatura', 'karsilastirma'] || array_slice(array_keys($s1), -2) === ['fatura', 'karsilastirma'], json_encode(array_keys($s1)));
ok('cari/özet etkilenmedi (fatura haritasıyla ve haritasız aynı)', $v['cari'] === $v0['cari'] && $v['ozet'] === $v0['ozet'] && !isset($v['ozet']['karsilastirma']));

echo "\n=== 4. Parametre doğrulama ===\n";
$bos = siparis_denge_param([], []);
ok('hiçbir şey yok: n 5, hane 2, kg 0, hedef 0, hata yok', $bos['n'] === 5 && $bos['hane'] === 2 && $bos['kg'] === 0 && $bos['hedef'] === 0 && $bos['hata'] === [] && !$bos['kg_girilen'] && !$bos['hedef_girilen'], json_encode($bos));
$vz = siparis_denge_param([], $v);
$sat0 = siparis_denge_satirlar($v);
ok('veri varken: kg boş = ortalama kg, hedef boş = SON (tarihe göre) sevkiyatın ağırlıklı PC fiyatı', $vz['kg'] > 0 && $vz['hedef'] === end($sat0)['pc_fiyat'] && $vz['hata'] === [], json_encode($vz));
$p = siparis_denge_param(['n' => '7', 'kg' => '18200', 'hedef' => '1,45', 'hane' => '3'], []);
ok('geçerli: n 7, kg 18200, hedef 14500, hane 3', $p['n'] === 7 && $p['kg'] === 18200 && $p['hedef'] === 14500 && $p['hane'] === 3 && $p['kg_girilen'] && $p['hedef_girilen'] && $p['hata'] === [], json_encode($p));
ok('hedef "1.45" (tek ayraç = ondalık) = 14500; "1.234,5" çift ayraç son = ondalık → 1000 üstü hata', siparis_denge_param(['hedef' => '1.45'], [])['hedef'] === 14500 && siparis_denge_param(['hedef' => '1.234,5'], [])['hata'] !== []);
ok('hedef 4 ondalık geçerli (1,4525); 5 ondalık hata', siparis_denge_param(['hedef' => '1,4525'], [])['hedef'] === 14525 && siparis_denge_param(['hedef' => '1,45251'], [])['hata'] !== []);
ok('kg binlik "18.200" → 18200', siparis_denge_param(['kg' => '18.200'], [])['kg'] === 18200);
foreach ([['n', '0'], ['n', '13'], ['n', 'x'], ['n', '2.5'], ['hane', '1'], ['hane', '5'], ['hane', 'a'], ['kg', '0'], ['kg', '100000000'], ['kg', '18200,5'], ['kg', 'abc'], ['kg', '-5'], ['hedef', '0'], ['hedef', '1000'], ['hedef', '-1'], ['hedef', 'abc']] as [$k, $d]) {
    $r = siparis_denge_param([$k => $d], $v);
    $varsayilan = siparis_denge_param([], $v);
    ok('geçersiz ' . $k . '=' . $d . ' → varsayılan + hata', $r['hata'] !== [] && $r[$k] === $varsayilan[$k], json_encode($r));
}
$r = siparis_denge_param(['n' => ['x'], 'kg' => ['1']], []);
ok('dizi değer → varsayılan + hata (kaçış/enjeksiyon güvenli)', $r['n'] === 5 && $r['hata'] !== []);
ok('sınırlar: n 1 ve 12 geçerli, kg 1 ve 99999999 geçerli', siparis_denge_param(['n' => '1'], [])['n'] === 1 && siparis_denge_param(['n' => '12'], [])['n'] === 12 && siparis_denge_param(['kg' => '1'], [])['kg'] === 1 && siparis_denge_param(['kg' => '99999999'], [])['kg'] === 99999999);
ok('hazır özet formu (kg_ort / son_pc_fiyat) kabul edilir', siparis_denge_param([], ['kg_ort' => 12000, 'son_pc_fiyat' => 14000])['kg'] === 12000 && siparis_denge_param([], ['kg_ort' => 12000, 'son_pc_fiyat' => 14000])['hedef'] === 14000);

echo "\n=== 5. Özet + sevkiyat satırları (elle doğrulanmış) ===\n";
$pk = static function (array $kullan) use ($v): array { return $v; };
$hv = siparis_denge_hesapla($v, siparis_denge_param(['kg' => '18200', 'hedef' => '1,45'], $v));
ok('tüm anahtarlar mevcut', array_keys($hv) === ['sevkiyatlar', 'satirlar', 'ozet', 'atlanan', 'plan', 'plan_ozet', 'uyarilar'], json_encode(array_keys($hv)));
$refler = array_map(static fn(array $r): string => $r['order_ref'], $hv['sevkiyatlar']);
ok('karşılaştırılan: s1 s2 s7 s8 (tarih artan: −3g, −1g, bugün, yarın)', $refler === ['TC810001.10', 'TC810002.10', 'TC810008.10', 'TC810007.10'], json_encode($refler));
$r1 = $hv['sevkiyatlar'][0]; $r2 = $hv['sevkiyatlar'][1];
ok('s1 satırı: pc_fiyat 14500, fatura_fiyat 13700, fark_fiyat +800, fark +1.456,00 €, kümülatif aynı', $r1['pc_fiyat'] === 14500 && $r1['fatura_fiyat'] === 13700 && $r1['fark_fiyat'] === 800 && $r1['fark_kurus'] === 145600 && $r1['kumulatif_fark_kurus'] === 145600 && $r1['tarih'] === $once);
ok('s2 satırı: ağırlıklı PC fiyatı 1,5333 (16.100 € / 10.500 kg), fatura 1,40, kümülatif +285.600', $r2['pc_fiyat'] === 15333 && $r2['fatura_fiyat'] === 14000 && $r2['kumulatif_fark_kurus'] === 145600 + 140000, json_encode($r2));
$oz = $hv['ozet'];
$topPc = 2639000 + 1610000 + 70000 + 70000; $topFa = 2493400 + 1470000 + 66500 + 73500; $topGr = 18200000 + 10500000 + 350000 + 350000;
ok('özet: adet 4, gram, PC/fatura/fark toplamı', $oz['adet'] === 4 && $oz['gram'] === $topGr && $oz['pc_kurus'] === $topPc && $oz['fatura_kurus'] === $topFa && $oz['fark_kurus'] === $topPc - $topFa, json_encode($oz));
ok('özet: kapatilacak = fark = gelen − kesilen (pozitif = ileride fazla faturalanmalı)', $oz['kapatilacak_kurus'] === ($topPc - $topFa) && $oz['kapatilacak_kurus'] === $oz['fark_kurus']);
// kg-ağırlıklı ortalama, ELLE: pc_ort = Σpc / Σkg ; fatura_ort = Σ(kg × fatura fiyatı) / Σkg (basit ortalama DEĞİL)
$pcOrt = intdiv($topPc * 100000 * 2 + $topGr, 2 * $topGr);
$faOrt = intdiv((18200000 * 13700 + 10500000 * 14000 + 350000 * 19000 + 350000 * 21000) * 2 + $topGr, 2 * $topGr);
ok('özet: pc_ort / fatura_ort kg-ağırlıklı; fark_ort = pc_ort − fatura_ort', $oz['pc_ort'] === $pcOrt && $oz['fatura_ort'] === $faOrt && $oz['fark_ort'] === $pcOrt - $faOrt, json_encode([$pcOrt, $faOrt]));
$basit = intdiv(13700 + 14000 + 19000 + 21000, 4);
ok('ağırlıklı ortalama basit ortalamadan farklı', $oz['fatura_ort'] !== $basit);
ok('atlanan: fatura_yok 2 (s4, s6), para_birimi 1 (s3), pc_yok 1 (s5)', $hv['atlanan'] === ['fatura_yok' => 2, 'para_birimi' => 1, 'pc_yok' => 1], json_encode($hv['atlanan']));
ok('satirlar = sevkiyatlar (takma ad)', $hv['satirlar'] === $hv['sevkiyatlar']);

echo "\n=== 6. Plan: saf senaryolar ===\n";
$mk = static function (int $id, ?string $tarih, string $ref, int $gram, int $pcKurus, int $faFiyat): array {
    $fa = intdiv($gram * $faFiyat + 50000, 100000);
    return ['id' => $id, 'order_ref' => $ref, 'tarih' => $tarih, 'fiyatli' => true, 'yuklendi' => true, 'fatura' => ['fiyat' => $faFiyat, 'no' => '', 'tarih' => null],
        'karsilastirma' => ['gram' => $gram, 'pc_kurus' => $pcKurus, 'fatura_kurus' => $fa, 'fark_kurus' => $pcKurus - $fa],
        'satirlar' => [['fiyatli' => true, 'pb' => 'EUR']]];
};
$cari1 = ['fiyatli' => [$mk(1, '2026-10-01', 'T1', 18200000, 2639000, 13700)], 'yuklenen' => [], 'yuklenmemis' => []];
$p1 = siparis_denge_param(['n' => '5', 'kg' => '18200', 'hedef' => '1,45'], $cari1);
$h1 = siparis_denge_hesapla($cari1, $p1);
ok('tek sevkiyat 18.200 kg: PC 1,45 / fatura 1,37 → fark +1.456,00 €, kapatılacak +1.456,00 €', $h1['ozet']['fark_kurus'] === 145600 && $h1['ozet']['kapatilacak_kurus'] === 145600 && $h1['ozet']['fark_ort'] === 800 && $h1['ozet']['pc_ort'] === 14500 && $h1['ozet']['fatura_ort'] === 13700);
ok('plan 5 satır; sıra 1..5; her satır gram 18.200.000, hedef 14500', count($h1['plan']) === 5 && array_column($h1['plan'], 'sira') === [1, 2, 3, 4, 5] && count(array_unique(array_column($h1['plan'], 'gram'))) === 1 && $h1['plan'][0]['gram'] === 18200000 && $h1['plan'][0]['hedef'] === 14500);
$hedefTutar = intdiv(18200000 * 14500 + 50000, 100000);
ok('hedef tutarı 26.390,00 €', $h1['plan_ozet']['hedef_tutar_kurus'] === 2639000 && $hedefTutar === 2639000);
$ilk = $h1['plan'][0];
ok('1. fatura (hane 2): ideal toplam 2.639.000+29.120 kuruş → 1,466 €/kg → 1,47 (14700); düzeltme +0,02; tutar 26.754,00 €; kapanan 364,00 €', $ilk['fiyat'] % 100 === 0 && $ilk['duzeltme_fiyat'] === $ilk['fiyat'] - 14500 && $ilk['fiyat'] === 14700 && $ilk['tutar_kurus'] === 2675400 && $ilk['kapanan_kurus'] === 36400 && $ilk['kalan_kurus'] === 145600 - 36400, json_encode($ilk));
$toplamKap = array_sum(array_column($h1['plan'], 'kapanan_kurus'));
ok('toplam kapanan + kalan = kapatılacak (tam eşitlik)', $h1['plan_ozet']['toplam_kapanan'] === $toplamKap && $toplamKap + $h1['plan_ozet']['kalan'] === 145600, json_encode($h1['plan_ozet']));
ok('kalan = son satırın kalan_kurus; her satırda kalan = önceki kalan − kapanan', end($h1['plan'])['kalan_kurus'] === $h1['plan_ozet']['kalan'] && (function () use ($h1): bool { $rem = 145600; foreach ($h1['plan'] as $r) { $rem -= $r['kapanan_kurus']; if ($r['kalan_kurus'] !== $rem || $r['kapanan_kurus'] !== $r['tutar_kurus'] - $r['hedef_tutar_kurus']) return false; } return true; })());
ok('hane 2: tüm fiyatlar 100\'ün katı (iki ondalık)', count(array_filter($h1['plan'], static fn(array $r): bool => $r['fiyat'] % 100 !== 0)) === 0);
ok('duzeltme_ort_fiyat = Σkapanan × 1e5 / Σgram (yarım yukarı); oran % 2 ondalık işaretli', $h1['plan_ozet']['duzeltme_ort_fiyat'] === intdiv($toplamKap * 100000 + intdiv(18200000 * 5, 2), 18200000 * 5) && $h1['plan_ozet']['duzeltme_oran_metin'][0] === '+' && $h1['plan_ozet']['duzeltme_oran_bp'] > 0, json_encode($h1['plan_ozet']));
foreach ([2, 3, 4] as $hane) {
    $ph = siparis_denge_param(['n' => '5', 'kg' => '18200', 'hedef' => '1,45', 'hane' => (string)$hane], $cari1);
    $hh = siparis_denge_hesapla($cari1, $ph);
    $unit = 10 ** (4 - $hane);
    $sinir = (int)ceil(18200000 * $unit / 100000 / 2) + 1;   // son satırın artığı en çok yarım adım (kuruş)
    ok('hane ' . $hane . ': fiyatlar ' . $unit . ' adımında; kalan ≤ ' . $sinir . ' kuruş; kapanan + kalan = kapatılacak', count(array_filter($hh['plan'], static fn(array $r): bool => $r['fiyat'] % $unit !== 0)) === 0 && abs($hh['plan_ozet']['kalan']) <= $sinir
        && $hh['plan_ozet']['toplam_kapanan'] + $hh['plan_ozet']['kalan'] === 145600, 'kalan=' . $hh['plan_ozet']['kalan']);
}
$h4 = siparis_denge_hesapla($cari1, siparis_denge_param(['n' => '5', 'kg' => '18200', 'hedef' => '1,45', 'hane' => '4'], $cari1));
ok('hane 4: kalan ≤ 1 € (100 kuruş)', abs($h4['plan_ozet']['kalan']) <= 100);
// D > 0 (fatura fazla): PC 1,30 / fatura 1,37 → fark +1.274,00 € → kapatılacak negatif, düzeltme negatif
$cariP = ['fiyatli' => [$mk(2, '2026-10-02', 'T2', 18200000, 2366000, 13700)], 'yuklenen' => [], 'yuklenmemis' => []];
$hp = siparis_denge_hesapla($cariP, siparis_denge_param(['n' => '4', 'kg' => '18200', 'hedef' => '1.30'], $cariP));
ok('D>0: fark −1.274,00 € (gelen − kesilen), kapatılacak −127.400; fiyatlar hedefin ALTINDA, kapanan negatif', $hp['ozet']['fark_kurus'] === -127400 && $hp['ozet']['kapatilacak_kurus'] === -127400 && $hp['plan'][0]['fiyat'] < 13000 && $hp['plan'][0]['kapanan_kurus'] < 0
    && $hp['plan_ozet']['duzeltme_ort_fiyat'] < 0 && $hp['plan_ozet']['duzeltme_oran_metin'][0] === '-' && $hp['plan_ozet']['toplam_kapanan'] + $hp['plan_ozet']['kalan'] === -127400, json_encode($hp['plan_ozet']));
// D = 0
$cari0 = ['fiyatli' => [$mk(3, '2026-10-03', 'T3', 10000000, 1450000, 14500)], 'yuklenen' => [], 'yuklenmemis' => []];
$h0 = siparis_denge_hesapla($cari0, siparis_denge_param(['kg' => '10000', 'hedef' => '1.45'], $cari0));
ok('D=0: tüm fiyatlar hedefe eşit, kalan 0, "dengede" bilgisi', $h0['ozet']['fark_kurus'] === 0 && array_unique(array_column($h0['plan'], 'fiyat')) === [14500] && $h0['plan_ozet']['kalan'] === 0 && $h0['plan_ozet']['duzeltme_oran_metin'] === '0,00'
    && in_array('Fatura fiyatları PC ile dengede.', array_column($h0['uyarilar'], 'mesaj'), true));
// N=1: tüm borç tek faturada
$hn1 = siparis_denge_hesapla($cari1, siparis_denge_param(['n' => '1', 'kg' => '18200', 'hedef' => '1.45', 'hane' => '4'], $cari1));
ok('N=1: tek satır, kapanan ≈ kapatılacak (hane 4)', count($hn1['plan']) === 1 && abs($hn1['plan'][0]['kapanan_kurus'] - 145600) <= 100 && $hn1['plan'][0]['fiyat'] === 14500 + 800);
ok('N=12: 12 satır, kalan tutarlı', count(siparis_denge_hesapla($cari1, siparis_denge_param(['n' => '12', 'kg' => '18200', 'hedef' => '1.45'], $cari1))['plan']) === 12);
// kg boş → ortalama kg; hedef boş → son sevkiyat fiyatı
$cariK = ['fiyatli' => [$mk(4, '2026-10-01', 'TA', 10000000, 1500000, 14000), $mk(5, '2026-10-05', 'TB', 20000000, 3200000, 15000)], 'yuklenen' => [], 'yuklenmemis' => []];
$pk0 = siparis_denge_param([], $cariK);
ok('kg boş → ortalama kg (15.000), hedef boş → son (tarihe göre) sevkiyatın PC fiyatı (1,60)', $pk0['kg'] === 15000 && $pk0['hedef'] === 16000, json_encode($pk0));
$hk = siparis_denge_hesapla($cariK, $pk0);
ok('varsayılanlarla plan üretilir ve tutarlı', count($hk['plan']) === 5 && $hk['plan'][0]['gram'] === 15000000 && $hk['plan_ozet']['toplam_kapanan'] + $hk['plan_ozet']['kalan'] === $hk['ozet']['kapatilacak_kurus']);
// tarihsiz sonda; son = tarihli olanların sonuncusu
$cariT = ['fiyatli' => [$mk(6, null, 'TN', 1000000, 150000, 14000), $mk(7, '2026-10-09', 'TM', 1000000, 160000, 14000), $mk(8, '2026-10-01', 'TL', 1000000, 140000, 14000)], 'yuklenen' => [], 'yuklenmemis' => []];
$st = siparis_denge_satirlar($cariT);
ok('sıra: tarih artan, tarihsiz sonda', array_column($st, 'order_ref') === ['TL', 'TM', 'TN']);
ok('hedef varsayılanı: tarihli sevkiyatların sonuncusu (TM = 1,60), tarihsiz değil', siparis_denge_param([], $cariT)['hedef'] === 16000);
// veri yok → boş plan
$hbos = siparis_denge_hesapla(['fiyatli' => [], 'yuklenen' => [], 'yuklenmemis' => []], siparis_denge_param([], []));
ok('veri yok: plan boş, tüm anahtarlar mevcut, "veri yok" uyarısı', $hbos['plan'] === [] && $hbos['sevkiyatlar'] === [] && $hbos['ozet']['adet'] === 0 && $hbos['plan_ozet']['n'] === 0 && $hbos['uyarilar'] !== [] && str_contains($hbos['uyarilar'][0]['mesaj'], 'Karşılaştırılacak sevkiyat yok'));
ok('boş girdi dizisi ([]) hata fırlatmaz', siparis_denge_hesapla([], [])['plan'] === []);
// kg / hedef girilmemiş ama veri var
$hkg = siparis_denge_hesapla($cari1, ['n' => 5, 'kg' => 0, 'hedef' => 0, 'hane' => 2]);
ok('kg/hedef 0: plan boş + "kg ve beklenen PC fiyatı gerekli" uyarısı', $hkg['plan'] === [] && count(array_filter($hkg['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'fatura başına net kg'))) === 1);
// büyük sıçrama uyarısı
$cariB = ['fiyatli' => [$mk(9, '2026-10-01', 'TB1', 18200000, 2639000, 10000)], 'yuklenen' => [], 'yuklenmemis' => []];   // fark −0,45 €/kg (−8.190 €)
$hb = siparis_denge_hesapla($cariB, siparis_denge_param(['n' => '2', 'kg' => '18200', 'hedef' => '1.45'], $cariB));
$uy = array_values(array_filter($hb['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'uyari'));
ok('büyük sıçrama (|düzeltme| > hedefin %5\'i): uyarı + önerilen fatura sayısı', count($uy) === 1 && str_contains($uy[0]['mesaj'], 'büyük sıçrama') && str_contains($uy[0]['mesaj'], 'fatura sayısını') && str_contains($uy[0]['mesaj'], '€/kg'), json_encode($uy));
// N' = ceil(|D| / (0.03 × hedefTutar)) = ceil(819000 / (0.03 × 2639000)) = ceil(10.34) = 11
ok('önerilen N\' = 11', isset($uy[0]) && str_contains($uy[0]['mesaj'], 'fatura sayısını 11 yap'), $uy[0]['mesaj'] ?? '');
$cariBB = ['fiyatli' => [$mk(10, '2026-10-01', 'TB2', 18200000, 2639000, 1000)], 'yuklenen' => [], 'yuklenmemis' => []];
$hbb = siparis_denge_hesapla($cariBB, siparis_denge_param(['n' => '2', 'kg' => '18200', 'hedef' => '1.45'], $cariBB));
ok('N\' 12\'yi aşarsa "12\'den fazla"', count(array_filter($hbb['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], "12'den fazla"))) === 1);
ok('küçük düzeltmede sıçrama uyarısı yok', count(array_filter($h1['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'uyari')) === 0 || $h1['plan_ozet']['duzeltme_oran_bp'] > 500);
// uyarı yapısı ve atlanan metinleri
ok('uyarı satırları: seviye bilgi|uyari + mesaj', count(array_filter($hv['uyarilar'], static fn(array $u): bool => in_array($u['seviye'], ['bilgi', 'uyari'], true) && is_string($u['mesaj']) && $u['mesaj'] !== '')) === count($hv['uyarilar']) && count($hv['uyarilar']) >= 3);
ok('atlanan sayıları uyarılarda', count(array_filter($hv['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'invoice yok'))) === 1 && count(array_filter($hv['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'EUR dışı'))) === 1 && count(array_filter($hv['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'PC fiyatı henüz'))) === 1);

echo "\n=== 7. Gerçek veriyle uçtan uca (DB → cari_veri → denge) ===\n";
$hg = siparis_denge_hesapla($v, siparis_denge_param(['n' => '5', 'kg' => '18200', 'hedef' => '1,45'], $v));
ok('plan kapanan + kalan = kapatılacak (4 sevkiyat)', $hg['plan_ozet']['toplam_kapanan'] + $hg['plan_ozet']['kalan'] === $hg['ozet']['kapatilacak_kurus']);
ok('plan_ozet: n 5, gram 18.200.000', $hg['plan_ozet']['n'] === 5 && $hg['plan_ozet']['gram'] === 18200000);

echo "\n=== 7b. v489: plan kaynağı — yüklenmemiş siparişlere göre ===\n";
$ykm = static function (int $id, ?string $tarih, string $ref, int $gram, bool $fiyatli = false, bool $kgv = false): array {
    return ['id' => $id, 'order_ref' => $ref, 'tarih' => $tarih, 'ulke' => 'Rusya', 'plaka' => '34TST' . $id, 'parti' => ['P' . $id], 'nakliyeci' => '', 'yuklendi' => false, 'fiyatli' => $fiyatli,
        'toplam' => ['palet' => 1, 'koli' => 1, 'gram' => $gram], 'satirlar' => [['kalibre' => '14', 'kg_varsayilan' => $kgv, 'gram' => $gram, 'fiyatli' => $fiyatli, 'pb' => 'EUR']]];
};
$cariY = ['fiyatli' => [$mk(1, '2026-10-01', 'T1', 18200000, 2639000, 13700)], 'yuklenen' => [],
    'yuklenmemis' => [$ykm(33, '2026-11-09', 'YC', 7000000), $ykm(31, '2026-11-02', 'YA', 18200000), $ykm(32, '2026-11-02', 'YB', 10500000, false, true), $ykm(34, null, 'YD', 3500000), $ykm(35, '2026-11-01', 'YF', 9000000, true), $ykm(36, '2026-11-01', 'Y0', 0)]];
// (c) param kaynak ayrıştırma
$py = siparis_denge_param([], $cariY);
ok('param: planlanabilir yüklenmemiş varken varsayılan kaynak = yuklenmemis (girilmedi)', $py['kaynak'] === 'yuklenmemis' && $py['kaynak_girilen'] === false && $py['hata'] === [], json_encode($py));
ok('param: planlanabilir yok → elle', siparis_denge_param([], $cari1)['kaynak'] === 'elle' && siparis_denge_param([], [])['kaynak'] === 'elle' && siparis_denge_param([], ['kg_ort' => 1, 'son_pc_fiyat' => 1])['kaynak'] === 'elle');
ok('param: yalnız fiyatı belli yüklenmemiş var → elle (planlanabilir sayılmaz)', siparis_denge_param([], ['fiyatli' => [], 'yuklenmemis' => [$ykm(1, null, 'X', 1000000, true)]])['kaynak'] === 'elle');
ok('param: n yazıldıysa (kaynak verilmediyse) → elle', siparis_denge_param(['n' => '3'], $cariY)['kaynak'] === 'elle');
ok('param: kg yazıldıysa → elle', siparis_denge_param(['kg' => '18200'], $cariY)['kaynak'] === 'elle');
ok('param: kaynak açıkça verilirse kazanır (n yazılsa da): yuklenmemis / elle, kaynak_girilen true', siparis_denge_param(['kaynak' => 'yuklenmemis', 'n' => '3'], $cariY)['kaynak'] === 'yuklenmemis' && siparis_denge_param(['kaynak' => 'yuklenmemis', 'n' => '3'], $cariY)['kaynak_girilen'] === true
    && siparis_denge_param(['kaynak' => 'elle'], $cariY)['kaynak'] === 'elle' && siparis_denge_param(['kaynak' => 'elle'], $cariY)['kaynak_girilen'] === true);
$pk = siparis_denge_param(['kaynak' => 'tum'], $cariY);
ok('param: geçersiz kaynak → varsayılan + hata satırı', $pk['kaynak'] === 'yuklenmemis' && $pk['kaynak_girilen'] === false && count($pk['hata']) === 1);
ok('param: dizi kaynak → hata, varsayılan', siparis_denge_param(['kaynak' => ['x']], $cariY)['hata'] !== [] && siparis_denge_param(['kaynak' => ['x']], $cariY)['kaynak'] === 'yuklenmemis');
// yardımcı: planlanabilir liste
$yl = siparis_denge_yuklenmemis($cariY);
ok('yuklenmemis(): fiyatlı (YF) ve 0 gram (Y0) hariç; sıra tarih artan, tarihsiz sonda, aynı tarihte id (YA 31 < YB 32)', array_column($yl['liste'], 'order_ref') === ['YA', 'YB', 'YC', 'YD'] && $yl['toplam'] === 4 && $yl['fiyatli_haric'] === 1, json_encode(array_column($yl['liste'], 'order_ref')));
ok('yuklenmemis(): kg_varsayilan satırdan taşınır (YB true, YA false)', $yl['liste'][1]['kg_varsayilan'] === true && $yl['liste'][0]['kg_varsayilan'] === false);
// (b) yüklenmemiş mod hesabı
$hy = siparis_denge_hesapla($cariY, siparis_denge_param(['hedef' => '1,45'], $cariY));
$gramlar = [18200000, 10500000, 7000000, 3500000];
ok('yüklenmemiş: plan 4 satır, satır başına kendi gram (18.200 / 10.500 / 7.000 / 3.500 kg), sıra tarihe göre', array_column($hy['plan'], 'gram') === $gramlar && array_column($hy['plan'], 'sira') === [1, 2, 3, 4], json_encode(array_column($hy['plan'], 'gram')));
ok('yüklenmemiş: plan_ozet kaynak/sevkiyat/toplam_gram/n', $hy['plan_ozet']['kaynak'] === 'yuklenmemis' && $hy['plan_ozet']['sevkiyat'] === 4 && $hy['plan_ozet']['n'] === 4 && $hy['plan_ozet']['toplam_gram'] === array_sum($gramlar) && $hy['plan_ozet']['gram'] === array_sum($gramlar), json_encode($hy['plan_ozet']));
ok('yüklenmemiş: her satır sev (id/order_ref/tarih/ulke/plaka/parti) + kg_varsayilan taşır; kimlik yardımcısı çalışır', $hy['plan'][1]['sev']['order_ref'] === 'YB' && $hy['plan'][1]['kg_varsayilan'] === true && $hy['plan'][0]['kg_varsayilan'] === false
    && str_contains(siparis_cari_kimlik_html($hy['plan'][0]['sev']), '<td>YA</td>'));
$kapY = $hy['ozet']['kapatilacak_kurus'];
ok('yüklenmemiş: Σkapanan + kalan === kapatılacak (tam eşitlik)', $kapY === 145600 && $hy['plan_ozet']['toplam_kapanan'] + $hy['plan_ozet']['kalan'] === $kapY, json_encode($hy['plan_ozet']));
ok('yüklenmemiş: her satırda kalan = önceki kalan − kapanan; kapanan = tutar − hedef tutar; hedef tutar satırın kendi gramıyla', (function () use ($hy, $kapY): bool {
    $rem = $kapY; $hedefTop = 0;
    foreach ($hy['plan'] as $r) {
        $ht = intdiv($r['gram'] * 14500 + 50000, 100000);
        if ($r['hedef_tutar_kurus'] !== $ht || $r['kapanan_kurus'] !== $r['tutar_kurus'] - $ht) return false;
        $rem -= $r['kapanan_kurus']; $hedefTop += $ht;
        if ($r['kalan_kurus'] !== $rem) return false;
    }
    return $hy['plan_ozet']['hedef_tutar_kurus'] === $hedefTop;
})());
ok('yüklenmemiş: €/kg düzeltme her satırda yaklaşık eşit (|fark| ≤ 1 hane birimi = 0,01 €)', (function () use ($hy): bool {
    $d = array_column($hy['plan'], 'duzeltme_fiyat'); return max($d) - min($d) <= 100;
})(), json_encode(array_column($hy['plan'], 'duzeltme_fiyat')));
ok('yüklenmemiş: tüm fiyatlar hane 2 adımında (100\'ün katı); duzeltme_fiyat = fiyat − hedef', count(array_filter($hy['plan'], static fn(array $r): bool => $r['fiyat'] % 100 !== 0 || $r['duzeltme_fiyat'] !== $r['fiyat'] - 14500)) === 0);
$ortDz = intdiv(($hy['plan_ozet']['toplam_kapanan'] * 100000 * 2) + array_sum($gramlar), 2 * array_sum($gramlar));
ok('yüklenmemiş: duzeltme_ort_fiyat = Σkapanan × 1e5 / Σgram (yarım yukarı)', $hy['plan_ozet']['duzeltme_ort_fiyat'] === $ortDz, (string)$hy['plan_ozet']['duzeltme_ort_fiyat'] . ' / ' . $ortDz);
ok('yüklenmemiş: fiyatı belli yüklenmemiş sevkiyat plana ALINMADI + bilgi notu', !in_array('YF', array_column(array_column($hy['plan'], 'sev'), 'order_ref'), true)
    && count(array_filter($hy['uyarilar'], static fn(array $u): bool => $u['mesaj'] === '1 yüklenmemiş sevkiyatın fiyatı belli; plana dahil değil.' && $u['seviye'] === 'bilgi')) === 1);
ok('yüklenmemiş: kg tabanı "kg/n girildi" parametrelerinden etkilenmez (kaynak açık yuklenmemis + n=3 + kg=1 → aynı plan)', siparis_denge_hesapla($cariY, siparis_denge_param(['kaynak' => 'yuklenmemis', 'hedef' => '1,45', 'n' => '3', 'kg' => '1'], $cariY))['plan'] === $hy['plan']);
// ağırlıklı pay: gram'ı büyük satır daha çok tutar kapatır
ok('yüklenmemiş: ağırlıklı pay — en büyük sevkiyat (18.200 kg) en büyük kapanan tutarı, en küçüğü (3.500 kg) en küçüğünü kapatır', $hy['plan'][0]['kapanan_kurus'] > $hy['plan'][1]['kapanan_kurus'] && $hy['plan'][1]['kapanan_kurus'] > $hy['plan'][3]['kapanan_kurus'] && $hy['plan'][2]['kapanan_kurus'] > $hy['plan'][3]['kapanan_kurus']);
foreach ([3, 4] as $hane) {
    $hh = siparis_denge_hesapla($cariY, siparis_denge_param(['hedef' => '1,45', 'hane' => (string)$hane], $cariY));
    $unit = 10 ** (4 - $hane);
    ok('yüklenmemiş hane ' . $hane . ': adım ' . $unit . ', Σkapanan + kalan = kapatılacak, düzeltme farkı ≤ ' . $unit . ' on-binde', count(array_filter($hh['plan'], static fn(array $r): bool => $r['fiyat'] % $unit !== 0)) === 0
        && $hh['plan_ozet']['toplam_kapanan'] + $hh['plan_ozet']['kalan'] === $kapY && (max(array_column($hh['plan'], 'duzeltme_fiyat')) - min(array_column($hh['plan'], 'duzeltme_fiyat'))) <= max($unit, 1) * 2, json_encode(array_column($hh['plan'], 'duzeltme_fiyat')));
}
// (a) elle modda eski plan BİREBİR aynı: kaynak=elle, aynı veri, yüklenmemiş sevkiyat dolu olsa bile
$he = siparis_denge_hesapla($cariY, siparis_denge_param(['kaynak' => 'elle', 'n' => '5', 'kg' => '18200', 'hedef' => '1,45'], $cariY));
$he1 = siparis_denge_hesapla($cari1, $p1);
ok('ELLE: yüklenmemiş sevkiyat olsa da plan, plan_ozet (kaynak/sevkiyat/toplam_gram hariç) ve satırlar cari1\'deki eski planla BİREBİR aynı', $he['plan'] === $he1['plan'] && $he['plan_ozet']['kaynak'] === 'elle'
    && array_diff_key($he['plan_ozet'], ['kaynak' => 1, 'sevkiyat' => 1, 'toplam_gram' => 1]) === array_diff_key($he1['plan_ozet'], ['kaynak' => 1, 'sevkiyat' => 1, 'toplam_gram' => 1]));
ok('ELLE: satırlarda sev / kg_varsayilan anahtarı YOK (eski satır biçimi)', !isset($he['plan'][0]['sev']) && !isset($he['plan'][0]['kg_varsayilan']) && array_keys($he['plan'][0]) === ['sira', 'gram', 'hedef', 'duzeltme_fiyat', 'fiyat', 'tutar_kurus', 'hedef_tutar_kurus', 'kapanan_kurus', 'kalan_kurus']);
ok('ELLE: hesapla param[kaynak] verilmezse elle (eski çağrılar bozulmaz); plan_ozet.gram = fatura başına gram, toplam_gram = n × gram', $h1['plan_ozet']['kaynak'] === 'elle' && $h1['plan_ozet']['gram'] === 18200000 && $h1['plan_ozet']['toplam_gram'] === 5 * 18200000);
// elle modda her satır g_i aynı → genelleştirilmiş pay eski rem/r paylaşımıyla özdeş (rasgele tarama)
mt_srand(7); $farkli = 0;
for ($t = 0; $t < 300; $t++) {
    $gr = mt_rand(1, 40) * 1000000 + mt_rand(0, 999) * 1000; $pcK = mt_rand(100, 3000000); $faF = mt_rand(8000, 20000); $faK = intdiv($gr * $faF + 50000, 100000);
    $c = ['fiyatli' => [['id' => 1, 'order_ref' => 'T', 'tarih' => '2026-10-01', 'fiyatli' => true, 'fatura' => ['fiyat' => $faF], 'karsilastirma' => ['gram' => $gr, 'pc_kurus' => $pcK, 'fatura_kurus' => $faK, 'fark_kurus' => $pcK - $faK], 'satirlar' => [['fiyatli' => true, 'pb' => 'EUR']]]], 'yuklenen' => [], 'yuklenmemis' => []];
    $n = mt_rand(1, 12); $kgT = mt_rand(1, 200000); $hd = mt_rand(5000, 40000); $hn = [2, 3, 4][mt_rand(0, 2)];
    $pl = siparis_denge_hesapla($c, ['n' => $n, 'kg' => $kgT, 'hedef' => $hd, 'hane' => $hn, 'kaynak' => 'elle'])['plan'];
    // bağımsız (eski formül) yeniden hesap
    $gF = $kgT * 1000; $hT = intdiv($gF * $hd + 50000, 100000); $un = 10 ** (4 - $hn); $rem = $pcK - $faK; $eski = [];
    for ($i = 1; $i <= $n; $i++) { $ideal = $hT + siparis_denge_bol($rem, $n - $i + 1); $fy = max(0, siparis_denge_bol($ideal * 100000, $gF * $un) * $un); $tu = intdiv($gF * $fy + 50000, 100000); $rem -= $tu - $hT; $eski[] = [$fy, $tu, $rem]; }
    $yeni = array_map(static fn(array $r): array => [$r['fiyat'], $r['tutar_kurus'], $r['kalan_kurus']], $pl);
    if ($eski !== $yeni) $farkli++;
}
ok('ELLE: 300 rasgele senaryoda genelleştirilmiş plan eski formülle (rem / kalan satır) satır satır AYNI', $farkli === 0, (string)$farkli);
// >12 sevkiyat → ilk 12
$many = []; for ($i = 1; $i <= 15; $i++) $many[] = $ykm(100 + $i, date('Y-m-d', strtotime('2026-12-01 +' . $i . ' day')), 'M' . $i, 3500000 + $i * 1000);
$cariM = ['fiyatli' => $cari1['fiyatli'], 'yuklenen' => [], 'yuklenmemis' => array_reverse($many)];
$hm = siparis_denge_hesapla($cariM, siparis_denge_param(['hedef' => '1,45'], $cariM));
ok('>12 yüklenmemiş: ilk 12 (tarihe göre) planlanır + "ilk 12 sevkiyat planlandı" notu', count($hm['plan']) === 12 && $hm['plan'][0]['sev']['order_ref'] === 'M1' && $hm['plan'][11]['sev']['order_ref'] === 'M12'
    && count(array_filter($hm['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'İlk 12 sevkiyat planlandı') && str_contains($u['mesaj'], '15 planlanabilir'))) === 1 && $hm['plan_ozet']['toplam_kapanan'] + $hm['plan_ozet']['kalan'] === $hm['ozet']['kapatilacak_kurus']);
// hiç yüklenmemiş yok → elle'ye düşme
$hz = siparis_denge_hesapla($cari1, siparis_denge_param(['kaynak' => 'yuklenmemis', 'kg' => '18200', 'hedef' => '1,45'], $cari1));
ok('yüklenmemiş yokken kaynak=yuklenmemis istenirse elle plana düşer + "Yüklenmemiş sipariş yok; elle plan kullanıldı." bilgisi', $hz['plan_ozet']['kaynak'] === 'elle' && count($hz['plan']) === 5
    && count(array_filter($hz['uyarilar'], static fn(array $u): bool => $u['mesaj'] === 'Yüklenmemiş sipariş yok; elle plan kullanıldı.' && $u['seviye'] === 'bilgi')) === 1);
$hz2 = siparis_denge_hesapla($cari1, siparis_denge_param(['kg' => '18200', 'hedef' => '1,45'], $cari1));
ok('varsayılan düşüşte (kaynak açıkça verilmedi) bu bilgi notu EKLENMEZ — eski çıktı aynı', count(array_filter($hz2['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'Yüklenmemiş sipariş yok'))) === 0);
// hedef yoksa plan kurulamaz (yüklenmemiş)
$hh0 = siparis_denge_hesapla($cariY, ['kaynak' => 'yuklenmemis', 'n' => 5, 'kg' => 0, 'hedef' => 0, 'hane' => 2]);
ok('yüklenmemiş + hedef 0: plan boş; "beklenen PC fiyatı gerekli" (kg istenmez)', $hh0['plan'] === [] && $hh0['plan_ozet']['kaynak'] === 'yuklenmemis'
    && count(array_filter($hh0['uyarilar'], static fn(array $u): bool => $u['mesaj'] === 'Plan için beklenen PC fiyatı gerekli; değeri girip yeniden hesaplayın.')) === 1);
// karşılaştırılan sevkiyat yoksa (adet 0) plan yok
ok('karşılaştırma yok: yüklenmemiş kaynak da plan üretmez', siparis_denge_hesapla(['fiyatli' => [], 'yuklenen' => [], 'yuklenmemis' => [$ykm(1, '2026-11-01', 'Z', 5000000)]], ['kaynak' => 'yuklenmemis', 'hedef' => 14500, 'hane' => 2])['plan'] === []);
// büyük sıçrama: yüklenmemiş metin
$cariYB = ['fiyatli' => [$mk(9, '2026-10-01', 'TB1', 18200000, 2639000, 10000)], 'yuklenen' => [], 'yuklenmemis' => [$ykm(1, '2026-11-01', 'Z1', 5000000), $ykm(2, '2026-11-02', 'Z2', 5000000)]];
$hyb = siparis_denge_hesapla($cariYB, siparis_denge_param(['hedef' => '1.45'], $cariYB));
$uyb = array_values(array_filter($hyb['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'uyari'));
ok('yüklenmemiş büyük sıçrama: "fatura sayısını N yap" önerisi YOK; "\'Elle\' kaynağıyla daha çok fatura deneyin."', count($uyb) === 1 && str_contains($uyb[0]['mesaj'], "büyük sıçrama; 'Elle' kaynağıyla daha çok fatura deneyin.") && !str_contains($uyb[0]['mesaj'], 'fatura sayısını') && str_contains($uyb[0]['mesaj'], '€/kg (hedefin %'), $uyb[0]['mesaj'] ?? '');
ok('ELLE büyük sıçrama metni eskisi gibi ("fatura sayısını 11 yap")', isset($uy[0]) && str_contains($uy[0]['mesaj'], 'fatura sayısını 11 yap'));
// pay: taşma koruması + eşit ağırlık
ok('pay: eşit ağırlık → rem / r ile özdeş (taşmasız); orantılı pay yarım yukarı', siparis_denge_pay(-145600, 18200000000, 18200000000 * 3, 3) === siparis_denge_bol(-145600, 3) && siparis_denge_pay(100, 1, 3, 3) === 33 && siparis_denge_pay(100, 2, 3, 2) === 67);
ok('pay: devasa gram × tutar çarpımı int64\'ü aşarsa taşmadan sonlanır (oran korunur)', siparis_denge_pay(900000000, 90000000000, 120000000000, 2) > 0);
// DB'den gerçek yapıyla uçtan uca
$ID['y1'] = sev($db, ['order_ref' => 'TC810101.10', 'yukleme_tarihi' => date('Y-m-d', strtotime('+6 day')), 'durum' => 'yola_cikti', 'alici_kodu' => '1038529', 'plakalar' => '34TST101']);
kalem($db, $ID['y1'], ['kalibre' => '14', 'aciklama' => 'Testfruit 14 PC', 'etkin_koli' => 5000, 'pc_koli' => 0, 'koli_icerik' => '3,5 KG']);
$ID['y2'] = sev($db, ['order_ref' => 'TC810102.10', 'yukleme_tarihi' => date('Y-m-d', strtotime('+3 day')), 'durum' => 'yola_cikti', 'alici_kodu' => '1038529', 'plakalar' => '34TST102']);
kalem($db, $ID['y2'], ['kalibre' => '12', 'aciklama' => 'Testfruit 12 PC', 'etkin_koli' => 2000, 'pc_koli' => 0, 'koli_icerik' => '']);   // koli kg okunamadı → 3,5 varsayılan (*)
$okuY = siparis_cari_oku($db, siparis_cari_filtre([], $bugun));
$idsY = array_map(static fn(array $r): int => (int)$r['id'], $okuY['rows']);
$vY = siparis_cari_veri($okuY, ['bugun' => $bugun, 'tahsilat' => [], 'fatura' => siparis_cari_fatura_fiyatlari($db, $idsY)]);
$pY = siparis_denge_param(['hedef' => '1,45'], $vY);
$hY = siparis_denge_hesapla($vY, $pY);
ok('DB uçtan uca: kaynak yuklenmemis; yalnız fiyatsız yüklenmemiş (y2 +3g, y1 +6g) sırayla; s2 (fiyatlı, yola_cikti) hariç', $pY['kaynak'] === 'yuklenmemis' && array_column(array_column($hY['plan'], 'sev'), 'order_ref') === ['TC810102.10', 'TC810101.10']
    && array_column($hY['plan'], 'gram') === [7000000, 17500000] && $hY['plan'][0]['kg_varsayilan'] === true && $hY['plan'][1]['kg_varsayilan'] === false, json_encode(array_column($hY['plan'], 'gram')));
ok('DB uçtan uca: Σkapanan + kalan = kapatılacak', $hY['plan_ozet']['toplam_kapanan'] + $hY['plan_ozet']['kalan'] === $hY['ozet']['kapatilacak_kurus'] && $hY['plan_ozet']['sevkiyat'] === 2);
ok('DB uçtan uca: kullanıcı n yazınca elle plana geçer (5 satır)', count(siparis_denge_hesapla($vY, siparis_denge_param(['hedef' => '1,45', 'n' => '5'], $vY))['plan']) === 5);

echo "\n=== 8. Statik ===\n";
$cfg = (string)file_get_contents($ROOT . '/config/siparis_denge.php');
$cfgKod = (string)preg_replace('#/\*.*?\*/#s', '', $cfg);
$cfgKod = (string)preg_replace('#(?m)(^|\s)//.*$#', '', $cfgKod);
ok('denge config: DB / çıktı / h() / yazma YOK', !preg_match('/\b(PDO|prepare|->query|->exec|echo|print|INSERT|UPDATE|DELETE|SELECT)\b/', $cfgKod) && !preg_match('/\bh\(/', $cfgKod));
ok('denge config: float yuvarlama yok — yalnız GET ayrıştırmada tek round(), floor/ceil çağrısı yok', substr_count($cfgKod, 'round(') === 1 && !str_contains($cfgKod, 'floor(') && !str_contains($cfgKod, 'ceil('));
ok('denge config: declare(strict_types=1) + doğrudan istek koruması (realpath)', str_contains($cfg, 'declare(strict_types=1);') && str_contains($cfg, "realpath((string)(\$_SERVER['SCRIPT_FILENAME']"));
$cari = (string)file_get_contents($ROOT . '/config/siparis_cari.php');
ok('cari config: yalnız SELECT (yazan SQL yok)', !preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|REPLACE)\s/', $cari));
$__kos = function (string $script, string $dosya) use ($ROOT): string {
    $kod = '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($script, true) . '; function h($s){return htmlspecialchars((string)$s);}'
         . ' require ' . var_export($ROOT . '/config/siparis_core.php', true) . '; require ' . var_export($ROOT . '/_siparis_ortak.php', true) . ';'
         . ' require ' . var_export($ROOT . '/config/' . $dosya, true) . '; echo "YUKLENDI";';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($kod) . ' 2>&1');
};
ok('config/siparis_denge.php doğrudan istenirse durur', !str_contains($__kos($ROOT . '/config/siparis_denge.php', 'siparis_denge.php'), 'YUKLENDI'));
ok('kök sayfa çalışırken config/siparis_denge.php yüklenir (siparis_cari + invoice zinciri dompdf istemez)', str_contains($__kos($ROOT . '/siparis_denge.php', 'siparis_denge.php'), 'YUKLENDI'));
ok('config/siparis_cari.php doğrudan istenirse hâlâ durur', !str_contains($__kos($ROOT . '/config/siparis_cari.php', 'siparis_cari.php'), 'YUKLENDI'));

siparis_test_bitir();
