<?php
// =========================================================
// scripts/siparis_vade_smoke.php — "OGL Haftalık Ödeme" (v570): config/siparis_vade.php saf hesabı + siparis_vade.php sayfası
//   php scripts/siparis_vade_smoke.php        (SIPARIS_VADE_OUT=<klasör> → sayfa render'ını dosyaya döker)
// Bellek içi SQLite + stub auth + SENTETİK veri (TC820…, IHR2026…; kişisel veri / gerçek fiyat YOK). Canlı DB'ye dokunmaz.
// =========================================================
require_once __DIR__ . '/_siparis_test_lib.php';
require_once $ROOT . '/_siparis_ortak.php';
require_once $ROOT . '/config/print_helpers.php';
require_once $ROOT . '/config/siparis_vade.php';
ini_set('session.save_path', sys_get_temp_dir()); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', ''); session_start();

echo "=== 1. Tarih yardımcıları + parametre ===\n";
ok('hafta başı / sonraki gün / gün farkı', siparis_vade_hafta_basi('2026-11-26') === '2026-11-23' && siparis_vade_hafta_basi('2026-11-23') === '2026-11-23'
    && siparis_vade_sonraki_gun('2026-11-22', 1) === '2026-11-23' && siparis_vade_sonraki_gun('2026-11-23', 1) === '2026-11-23' && siparis_vade_sonraki_gun('2026-11-24', 1) === '2026-11-30'
    && siparis_vade_gun_farki('2026-10-03', '2026-10-27') === 24 && siparis_vade_gun_farki('2026-10-20', '2026-11-09') === 20);
$p0 = siparis_vade_param([]);
ok('param: boş → otomatik (vade/gün null, baz fatura)', $p0 === ['vade' => null, 'gun' => null, 'baz' => 'fatura', 'hata' => []]);
$p1 = siparis_vade_param(['vade' => '14', 'gun' => '3', 'baz' => 'yukleme']);
ok('param: geçerli değerler', $p1['vade'] === 14 && $p1['gun'] === 3 && $p1['baz'] === 'yukleme' && $p1['hata'] === []);
$p2 = siparis_vade_param(['vade' => '-1', 'gun' => '9', 'baz' => '<x>']);
ok('param: geçersiz → otomatik + 3 hata', $p2['vade'] === null && $p2['gun'] === null && $p2['baz'] === 'fatura' && count($p2['hata']) === 3);
ok('istatistik: min/medyan/max', siparis_vade_istatistik([24, 20, 22, 21]) === ['n' => 4, 'min' => 20, 'medyan' => 21, 'max' => 24] && siparis_vade_istatistik([])['n'] === 0);

echo "\n=== 2. Saf hesap: ödenen / açık / bu hafta ===\n";
$BUGUN = '2026-11-25';   // Çarşamba; bu hafta 23.11–29.11
$sev = static function (int $id, ?string $fatTarih, array $ek = []): array {
    return $ek + ['id' => $id, 'order_ref' => 'TC8200' . $id . '.10', 'tarih' => $fatTarih !== null ? siparis_vade_gun_ekle($fatTarih, -2) : '2026-11-01', 'fiyat_tarihi' => null,
        'ulke' => 'SIRBİSTAN', 'plaka' => '', 'parti' => [], 'yuklendi' => true, 'toplam' => ['gram' => 1000000, 'koli' => 0, 'palet' => 0], 'tutar' => ['EUR' => 140000], 'net' => ['EUR' => 134400],
        'fatura' => $fatTarih !== null ? ['fiyat' => 13700, 'no' => 'IHR2026000000' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), 'tarih' => $fatTarih] : null, 'karsilastirma' => null];
};
$G = static fn(int $id): string => 'IHR2026000000' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
$girdi = [
    'sevkiyatlar' => [
        $sev(1, '2026-10-03'), $sev(2, '2026-10-20'), $sev(3, '2026-10-26'), $sev(4, '2026-11-02'),
        $sev(5, '2026-11-05', ['karsilastirma' => ['fark_kurus' => -12880]]),   // gelen < kesilen, debit yok → tahmini kesinti 12.880 × 0,96 = 12.365
        $sev(6, '2026-11-10'),
        $sev(7, null, ['tutar' => ['EUR' => 50000], 'net' => ['EUR' => 48000]]),   // invoice yok → takvime girmez
    ],
    'fatura' => array_fill_keys([1, 2, 3, 4, 5, 6], ['iskonto' => 4.0]),
    'belge_toplam' => [1 => 2393664, 2 => 1000000, 3 => 500000, 4 => 300000, 5 => 400000, 6 => 200000],
    'odemeler' => [
        ['id' => 11, 'tarih' => '2026-10-27', 'kurus' => 2393654, 'pb' => 'EUR', 'satirlar' => [['n' => $G(1), 't' => 'ana', 'g' => $G(1), 'z' => 2393664], ['n' => '2027-1', 't' => 'ogl_ic', 'g' => '', 'z' => -10]]],
        ['id' => 12, 'tarih' => '2026-11-09', 'kurus' => 997000, 'pb' => 'EUR', 'satirlar' => [['n' => $G(2), 't' => 'ana', 'g' => $G(2), 'z' => 1000000], ['n' => '2029-3', 't' => 'ogl_ic', 'g' => '', 'z' => -3000], ['n' => 'IHR2026999999999', 't' => 'ana', 'g' => 'IHR2026999999999', 'z' => 0]]],
        ['id' => 13, 'tarih' => '2026-11-10', 'kurus' => 7000, 'pb' => 'USD', 'satirlar' => null],
    ],
    'harita' => [$G(1) => ['durum' => 'eslesti', 'v' => [['id' => 1]]], $G(2) => ['durum' => 'eslesti', 'v' => [['id' => 2]]]],
    'debitler' => [
        ['no' => '2029-2', 'tarih' => '2026-11-12', 'kurus' => 7488, 'pb' => 'EUR', 'invoice_no' => $G(1) . 'DI', 'sid' => 1, 'ref' => 'TC82001.10', 'fiyat_farki' => true, 'neden' => 'WRONG PRICE'],   // ödenmiş sevkiyat, avizoda yok → bu hafta düşülür
        ['no' => '2029-3', 'tarih' => '2026-11-01', 'kurus' => 3000, 'pb' => 'EUR', 'invoice_no' => $G(2) . 'DI', 'sid' => 2, 'ref' => 'TC82002.10', 'fiyat_farki' => true, 'neden' => 'WRONG PRICE'],   // avizoda düşülmüş
        ['no' => '2029-4', 'tarih' => '2026-11-15', 'kurus' => 5000, 'pb' => 'EUR', 'invoice_no' => $G(6) . 'DI', 'sid' => 6, 'ref' => 'TC82006.10', 'fiyat_farki' => false, 'neden' => 'QUALITY'],   // açık faturadan düşülür
    ],
];
$h = siparis_vade_hesapla($girdi, siparis_vade_param([]), $BUGUN);
$o = $h['ozet'];
ok('otomatik vade = en kısa gecikme 20 gün (24 / 20); ödeme günü Pazartesi (eşit sayıda → en yeni avizo)', $h['param']['vade'] === 20 && $h['param']['vade_oto'] === true && $h['param']['gun'] === 1 && $o['gecikme'] === ['n' => 2, 'min' => 20, 'medyan' => 22, 'max' => 24], json_encode([$h['param'], $o['gecikme']]));
ok('bu hafta: Pazartesi 23.11 ödeme günü (v572: bu_hafta dizisi ek alanlar taşır → bas/son/odeme_gunu alt kümesi)', array_intersect_key($h['bu_hafta'], array_flip(['bas', 'son', 'odeme_gunu'])) === ['bas' => '2026-11-23', 'son' => '2026-11-29', 'odeme_gunu' => '2026-11-23']);
$ids = static fn(array $l): array => array_map(static fn(array $a): int => (int)$a['sev']['id'], $l);
ok('ödenmiş = avizoda fatura no geçen 1, 2; açık = 3, 4, 5, 6; faturasız = 7', $ids($h['odenmis']) === [2, 1] && $ids($h['acik']) === [3, 4, 5, 6] && $ids($h['faturasiz']) === [7], json_encode([$ids($h['odenmis']), $ids($h['acik'])]));
$a = []; foreach ($h['acik'] as $x) $a[(int)$x['sev']['id']] = $x;
ok('3: vade 15.11 → ödeme günü geçmiş (16.11) → BU HAFTAYA alınır, gecikmiş', $a[3]['vade_tarih'] === '2026-11-15' && $a[3]['odeme_gunu'] === '2026-11-23' && $a[3]['gecikmis'] === true);
ok('4: vade 22.11 → Pazartesi 23.11 (gecikmiş değil)', $a[4]['vade_tarih'] === '2026-11-22' && $a[4]['odeme_gunu'] === '2026-11-23' && $a[4]['gecikmis'] === false);
ok('5: tahmini fiyat farkı kesintisi 12.880 × %96 = 12.365 → beklenen 387.635; ödeme 30.11', $a[5]['tahmini'] === 12365 && $a[5]['beklenen'] === 387635 && $a[5]['odeme_gunu'] === '2026-11-30');
ok('6: avizoda düşülmemiş kesinti (kalite 50,00) açık faturadan düşülür → 195.000', $a[6]['kesinti'] === 5000 && $a[6]['beklenen'] === 195000 && $a[6]['tahmini'] === 0);
ok('ödenmiş 1\'in avizoda olmayan kesintisi (74,88) bekleyen → bu haftadan düşülür; avizoda geçen 2029-3 düşülmüş sayılır', count($h['bekleyen_kesinti']) === 1 && $h['bekleyen_kesinti'][0]['no'] === '2029-2' && $o['bekleyen_kesinti_kurus'] === 7488);
ok('BU HAFTA = 500.000 + 300.000 − 7.488 = 792.512 (2 fatura, 1 gecikmiş)', $o['bu_hafta_kurus'] === 792512 && $o['bu_hafta_sevkiyat'] === 2 && $o['bu_hafta_gecikmis_sevkiyat'] === 1 && $o['bu_hafta_gecikmis_kurus'] === 500000, json_encode($o));
ok('GELECEK HAFTA (30.11) = 387.635 + 195.000 = 582.635', $o['gelecek_hafta_kurus'] === 582635 && $o['gelecek_hafta_sevkiyat'] === 2 && $o['gelecek_odeme_gunu'] === '2026-11-30');
ok('açık alacak = Σ beklenen − bekleyen kesinti = 1.375.147; ödenen yalnız EUR (3.390.654), USD ayrı', $o['acik_kurus'] === 1375147 && $o['odenen_kurus'] === 3390654 && $o['diger_pb'] === ['USD' => 7000] && $o['son_odeme'] === ['tarih' => '2026-11-09', 'kurus' => 997000]);
ok('haftalar: 2 hafta, kümülatif, bu hafta işaretli', count($h['haftalar']) === 2 && $h['haftalar'][0]['bu_hafta'] === true && $h['haftalar'][1]['kumulatif'] === 792512 + 582635);
ok('ödeme özeti: avizo eşleşmeyen fatura sayılır; gecikme istatistiği ödeme başına', $h['odemeler'][1]['eslesmeyen'] === 1 && $h['odemeler'][1]['sevkiyat'] === 1 && $h['odemeler'][0]['gecikme']['min'] === 24);
ok('faturasız: net (iskonto sonrası) gelen tutarı', $h['faturasiz'][0]['gelen_kurus'] === 48000 && $o['faturasiz_kurus'] === 48000);
ok('uyarılar: PC bekleyen + invoice yok + EUR dışı ödeme', count($h['uyarilar']) >= 3);

echo "\n=== 3. Elle vade / gün, elle ödeme FIFO, düzeltme satırıyla kesinti ===\n";
$h2 = siparis_vade_hesapla($girdi, siparis_vade_param(['vade' => '30', 'gun' => '5']), $BUGUN);
ok('elle vade 30 + Cuma: 3 → 25.11+… (vade 25.11 → Cuma 27.11 bu hafta); 4 → 02.12 → Cuma 04.12', $h2['param']['vade'] === 30 && $h2['param']['gun'] === 5 && $h2['bu_hafta']['odeme_gunu'] === '2026-11-27'
    && (static function (array $l): bool { foreach ($l as $x) { if ($x['sev']['id'] === 3 && $x['odeme_gunu'] !== '2026-11-27') return false; if ($x['sev']['id'] === 4 && $x['odeme_gunu'] !== '2026-12-04') return false; } return true; })($h2['acik']));
$g3 = $girdi; $g3['odemeler'][] = ['id' => 14, 'tarih' => '2026-11-20', 'kurus' => 600000, 'pb' => 'EUR', 'satirlar' => null];
$h3 = siparis_vade_hesapla($g3, siparis_vade_param([]), $BUGUN);
$a3 = []; foreach ($h3['acik'] as $x) $a3[(int)$x['sev']['id']] = $x;
ok('elle (avizosuz) 6.000,00: en eski açık 3 (5.000,00) tamamen ödendi sayılır, 4\'ten 1.000,00 mahsup', !isset($a3[3]) && $a3[4]['mahsup'] === 100000 && $a3[4]['beklenen'] === 200000
    && in_array(3, array_map(static fn(array $x): int => (int)$x['sev']['id'], array_filter($h3['odenmis'], static fn(array $x): bool => $x['elle'])), true));
$g4 = $girdi; $g4['odemeler'][1]['satirlar'][] = ['n' => $G(1) . 'DI', 't' => 'duzeltme', 'g' => $G(1), 'z' => -7488];
$h4 = siparis_vade_hesapla($g4, siparis_vade_param([]), $BUGUN);
ok('avizoda IHR…DI düzeltme satırı (aynı tutar) varsa kesinti düşülmüş sayılır → bekleyen yok', $h4['bekleyen_kesinti'] === [] && $h4['ozet']['bu_hafta_kurus'] === 800000);
$h5 = siparis_vade_hesapla(['sevkiyatlar' => [$sev(3, '2026-10-26')], 'fatura' => [3 => ['iskonto' => 4.0]], 'belge_toplam' => []], siparis_vade_param([]), $BUGUN);
ok('geçmiş ödeme yok: vade 21 gün varsayılır + uyarı; belge toplamı yoksa 1.000 kg × 1,37 − %4 = 1.315,20', $h5['param']['vade'] === SIPARIS_VADE_GUN_VARSAYILAN && $h5['acik'][0]['net'] === 131520
    && (bool)array_filter($h5['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'uyari'));
ok('baz yükleme: vade başlangıcı yükleme tarihi (fatura − 2 gün)', siparis_vade_baz_tarihi($sev(3, '2026-10-26'), 'yukleme') === '2026-10-24' && siparis_vade_baz_tarihi($sev(3, '2026-10-26'), 'fatura') === '2026-10-26');

echo "\n=== 3b. v570: kısmi invoice + eşleşmeyen avizo ödemesi (FIFO mahsup) + aynı fatura no + ters tarih + yüklemeden beri gün ===\n";
$g6 = [
    'sevkiyatlar' => [$sev(21, '2026-10-01'), $sev(22, '2026-10-05'), $sev(23, '2026-11-10')],
    'fatura' => array_fill_keys([21, 22, 23], ['iskonto' => 4.0]),
    'belge_toplam' => [21 => 65760, 22 => 131520, 23 => 131520],   // 21: invoice sevkiyatın YARISINI kapsıyor (1.000 kg × 1,37 − %4 = 131.520)
    'odemeler' => [['id' => 31, 'tarih' => '2026-11-09', 'kurus' => 165760, 'pb' => 'EUR', 'satirlar' => [
        ['n' => $G(21), 't' => 'ana', 'g' => $G(21), 'z' => 65760], ['n' => 'IHR2026000000777', 't' => 'ana', 'g' => 'IHR2026000000777', 'z' => 100000]]]],
    'harita' => [$G(21) => ['durum' => 'eslesti', 'v' => [['id' => 21]]]],
    'debitler' => [],
];
$h6 = siparis_vade_hesapla($g6, siparis_vade_param([]), $BUGUN);
$o6 = $h6['ozet'];
ok('sevkiyat net = TAMAMI (131.520), belge yarım (65.760) tek başına KULLANILMAZ; invoice_kismi sayılır (65.760 eksik)', $o6['fatura_toplam_kurus'] === 3 * 131520 && $o6['invoice_kismi_sevkiyat'] === 1, json_encode($o6));
ok('21: avizoda yarısı ödenmiş (kısmi) → ödenmiş listesinde kismi=true + net 65.760; kalan 65.760 açık listeye girer', (static function () use ($h6): bool {
    foreach ($h6['odenmis'] as $x) if ($x['sev']['id'] === 21 && !empty($x['kismi']) && $x['net'] === 65760 && $x['elle'] === false) return true;
    return false; })());
ok('eşleşmeyen avizo faturası (100.000) en eski açık faturalara FIFO mahsup: 21\'in kalanı (65.760) tamamen, 22\'den 34.240 → 22 beklenen 97.280; 23 değişmez', array_map(static fn(array $a): int => $a['sev']['id'], $h6['acik']) === [22, 23]
    && $h6['acik'][0]['beklenen'] === 97280 && $h6['acik'][0]['mahsup'] === 34240 && $h6['acik'][1]['beklenen'] === 131520 && $o6['mahsup_kurus'] === 100000 && $o6['havuz_kurus'] === 100000, json_encode($h6['acik']));
ok('mutabakat: invoice toplamı − ödenen faturalar = açık; ödenen faturalar = toplam EUR ödeme (165.760)', $o6['odenen_fatura_kurus'] === 165760 && $o6['fatura_toplam_kurus'] - $o6['odenen_fatura_kurus'] === 97280 + 131520 && $o6['acik_kurus'] === 97280 + 131520, json_encode($o6));
ok('uyarılar: eşleşmeyen avizo (TAHMİNİ) + kısmi invoice; eşleşmeyen sayısı özetlenir', (bool)array_filter($h6['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'eşleşmeyen 1 fatura kalemi') && str_contains($u['mesaj'], 'TAHMİNİ'))
    && (bool)array_filter($h6['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'yalnız bir kısmını kapsıyor')));
ok('yüklemeden beri gün: açık faturada (bugün − yükleme tarihi); 22: yükleme 03.10 → 53 gün, 23: 08.11 → 17', $h6['acik'][0]['yukleme_gun'] === 53 && $h6['acik'][1]['yukleme_gun'] === 17, json_encode(array_column($h6['acik'], 'yukleme_gun')));
$g7 = ['sevkiyatlar' => [$sev(41, '2026-11-01'), $sev(42, '2026-11-02', ['fatura' => ['fiyat' => 13700, 'no' => $G(41), 'tarih' => '2026-11-02']])], 'fatura' => [41 => ['iskonto' => 4.0], 42 => ['iskonto' => 4.0]], 'belge_toplam' => []];
$h7 = siparis_vade_hesapla($g7, siparis_vade_param([]), $BUGUN);
ok('aynı fatura no iki sevkiyatta → "Ortak fatura" notu (no + iki sipariş)', (bool)array_filter($h7['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'bilgi' && str_contains($u['mesaj'], 'Ortak fatura') && str_contains($u['mesaj'], $G(41)) && str_contains($u['mesaj'], 'TC820041.10') && str_contains($u['mesaj'], 'TC820042.10')));
$ortak = static function (int $z) use ($sev, $G): array {
    return ['sevkiyatlar' => [$sev(61, '2026-10-03'), $sev(62, '2026-10-27', ['fatura' => ['fiyat' => 13700, 'no' => $G(61), 'tarih' => '2026-10-27']])],
        'fatura' => [61 => ['iskonto' => 4.0], 62 => ['iskonto' => 4.0]], 'belge_toplam' => [61 => 131520, 62 => 131520],
        'odemeler' => [['id' => 71, 'tarih' => '2026-11-09', 'kurus' => $z, 'pb' => 'EUR', 'satirlar' => [['n' => $G(61), 't' => 'ana', 'g' => $G(61), 'z' => $z]]]],
        'harita' => [$G(61) => ['durum' => 'belirsiz', 'v' => [['id' => 61], ['id' => 62]]]], 'debitler' => []];
};
$h9 = siparis_vade_hesapla($ortak(263040), siparis_vade_param([]), $BUGUN);
ok('ORTAK FATURA (aynı no iki sevkiyatta): avizo tutarı iki sevkiyatı da kapsıyorsa (263.040) ikisi de ÖDENMİŞ, açık yok, havuz 0', count($h9['odenmis']) === 2 && $h9['acik'] === [] && $h9['ozet']['havuz_kurus'] === 0 && $h9['ozet']['acik_kurus'] === 0, json_encode($h9['ozet']));
ok('ortak fatura: ödeme özetinde 2 sevkiyat; "Ortak fatura" bilgi notu (no + iki sipariş), "eşleşmeyen" uyarısı YOK', $h9['odemeler'][0]['sevkiyat'] === 2 && (bool)array_filter($h9['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'Ortak fatura') && str_contains($u['mesaj'], 'TC820061.10') && str_contains($u['mesaj'], 'TC820062.10'))
    && !array_filter($h9['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'eşleşmeyen')));
$h10 = siparis_vade_hesapla($ortak(131520), siparis_vade_param([]), $BUGUN);
ok('ortak fatura: avizo tutarı yalnız bir sevkiyatlık (131.520) → EN ESKİ (61) ödenmiş, 62 açık kalır', array_map(static fn(array $a): int => $a['sev']['id'], $h10['odenmis']) === [61] && array_map(static fn(array $a): int => $a['sev']['id'], $h10['acik']) === [62] && $h10['acik'][0]['beklenen'] === 131520, json_encode($h10['ozet']));
$h11 = siparis_vade_hesapla($ortak(300000), siparis_vade_param([]), $BUGUN);
ok('ortak fatura: artan tutar (300.000 − 263.040 = 36.960) mahsup havuzuna gider (açık fatura yoksa "artık" bilgisi)', $h11['ozet']['havuz_kurus'] === 36960 && $h11['ozet']['mahsup_kurus'] === 0 && (bool)array_filter($h11['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'Mahsup edilemeyen')));
// v571 REGRESYON: eşleşmeyen avizo satırları NET sayılır (eksi iade/düzeltme/iptal satırları pozitif eşleşmeyen faturayı götürür; bilinen debit satırı hariç)
$g12 = [
    'sevkiyatlar' => [$sev(81, '2026-10-01'), $sev(82, '2026-10-05'), $sev(83, '2026-11-10')],
    'fatura' => array_fill_keys([81, 82, 83], ['iskonto' => 4.0]), 'belge_toplam' => [81 => 131520, 82 => 131520, 83 => 131520],
    'odemeler' => [['id' => 91, 'tarih' => '2026-11-09', 'kurus' => 191020, 'pb' => 'EUR', 'satirlar' => [
        ['n' => $G(81), 't' => 'ana', 'g' => $G(81), 'z' => 131520],
        ['n' => 'IHR2026000000888', 't' => 'ana', 'g' => 'IHR2026000000888', 'z' => 100000],                 // eşleşmeyen fatura (+)
        ['n' => 'IHR2026000000888DI', 't' => 'duzeltme', 'g' => 'IHR2026000000888', 'z' => -40000],          // aynı faturanın iade/düzeltmesi (−)
        ['n' => '2029-9', 't' => 'ogl_ic', 'g' => '', 'z' => -500]]]],                                       // bilinen debit note satırı (havuzdan düşülmez)
    'harita' => [$G(81) => ['durum' => 'eslesti', 'v' => [['id' => 81]]]],
    'debitler' => [['no' => '2029-9', 'tarih' => '2026-11-01', 'kurus' => 500, 'pb' => 'EUR', 'invoice_no' => '', 'sid' => null, 'ref' => '', 'fiyat_farki' => false, 'neden' => 'QUALITY']],
];
$h12 = siparis_vade_hesapla($g12, siparis_vade_param([]), $BUGUN);
ok('eşleşmeyen havuz = 100.000 − 40.000 = 60.000 NET (eksi düzeltme satırı düşülür; bilinen debit satırı hariç) — pozitif satırı tek başına SAYMAZ', $h12['ozet']['havuz_kurus'] === 60000 && $h12['ozet']['mahsup_kurus'] === 60000, json_encode($h12['ozet']));
ok('mutabakat: ödenen fatura (191.520) kasaya giren (191.020) + debit payı içinde; açık = 71.520 + 131.520; "tutarsız" uyarısı YOK', $h12['ozet']['odenen_fatura_kurus'] === 191520 && $h12['ozet']['acik_kurus'] === 71520 + 131520
    && !array_filter($h12['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'DİKKAT')), json_encode($h12['ozet']));
$g13 = $g12; $g13['odemeler'] = [['id' => 92, 'tarih' => '2026-11-09', 'kurus' => 50000, 'pb' => 'EUR', 'satirlar' => [['n' => $G(81), 't' => 'ana', 'g' => $G(81), 'z' => 131520]]]];
$h13 = siparis_vade_hesapla($g13, siparis_vade_param([]), $BUGUN);
ok('güvenlik ağı (v572): ödenen fatura toplamı kasaya giren toplamdan BÜYÜKSE toleranslı "DİKKAT" yerine TAM eşitlik denetimi: seviye "hata" + "tutmuyor", sapma 81.520 (= avizo kalemleri ödemeden 815,20 € fazla)',
    (bool)array_filter($h13['uyarilar'], static fn(array $u): bool => $u['seviye'] === 'hata' && str_contains($u['mesaj'], 'tutmuyor')) && !array_filter($h13['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'DİKKAT'))
    && $h13['ozet']['uyum']['sapma_kurus'] === 81520 && $h13['ozet']['uyum']['tutarli'] === false, json_encode($h13['ozet']['uyum'] ?? null));

$g8 = ['sevkiyatlar' => [$sev(51, '2026-11-12'), $sev(52, '2026-10-20'), $sev(53, '2026-11-20')], 'fatura' => array_fill_keys([51, 52, 53], ['iskonto' => 4.0]), 'belge_toplam' => [51 => 131520, 52 => 131520, 53 => 131520],
    'odemeler' => [['id' => 61, 'tarih' => '2026-11-09', 'kurus' => 263040, 'pb' => 'EUR', 'satirlar' => [['n' => $G(51), 't' => 'ana', 'g' => $G(51), 'z' => 131520], ['n' => $G(52), 't' => 'ana', 'g' => $G(52), 'z' => 131520]]]],
    'harita' => [$G(51) => ['durum' => 'eslesti', 'v' => [['id' => 51]]], $G(52) => ['durum' => 'eslesti', 'v' => [['id' => 52]]]], 'debitler' => []];
$h8 = siparis_vade_hesapla($g8, siparis_vade_param([]), $BUGUN);
ok('ödeme tarihinden SONRA tarihli fatura (51: −3 gün) vade öğrenmesine katılmaz: vade = 20 (52), 0/negatif değil + uyarı', $h8['param']['vade'] === 20 && $h8['ozet']['gecikme']['n'] === 1 && (bool)array_filter($h8['uyarilar'], static fn(array $u): bool => str_contains($u['mesaj'], 'fatura tarihi ödeme tarihinden sonra')), json_encode([$h8['param'], $h8['ozet']['gecikme']]));

echo "\n=== 4. Sayfa (siparis_vade.php) gerçek render ===\n";
$db = db();
siparis_test_diger_tablolar($db);
siparis_test_sema_kur($db);
foreach (siparis_odeme_tablolar() as $ddl) { [$cr, $ix] = siparis_ddl_sqlite($ddl); $db->exec($cr); foreach ($ix as $x) $db->exec($x); }
try { $db->exec('CREATE TABLE hks_kv (anahtar VARCHAR(60) PRIMARY KEY, deger MEDIUMTEXT)'); } catch (Throwable $e) { /* var */ }
class VdExit extends RuntimeException {}
function vd_header(string $h, bool $r = true, int $c = 0): void {}
function vd_code(?int $c = null) { return 200; }
function vd_calistir(string $tmp): void { include $tmp; }
function vd_uc(string $dosya, array $get = []): array
{
    global $ROOT;
    $src = (string)file_get_contents($ROOT . '/' . $dosya);
    $src = preg_replace("/^\s*require_once __DIR__ \. '\/config\/(db|auth)\.php';\s*$/m", '', $src);
    $src = str_replace('__DIR__', var_export($ROOT, true), $src);
    $src = preg_replace('/\bheader\(/', 'vd_header(', $src);
    $src = preg_replace('/\bhttp_response_code\(/', 'vd_code(', $src);
    $src = preg_replace('/\bexit;/', 'throw new VdExit();', $src);
    $tmp = sys_get_temp_dir() . '/sip_vade_' . getmypid() . '_' . basename($dosya);
    file_put_contents($tmp, $src);
    $_GET = $get; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/' . $dosya;
    ob_start(); $hata = null;
    try { vd_calistir($tmp); } catch (VdExit $e) {} catch (Throwable $e) { $hata = get_class($e) . ': ' . $e->getMessage(); }
    $g = (string)ob_get_clean();
    @unlink($tmp);
    return ['govde' => $g, 'hata' => $hata];
}
$gun = static fn(int $n): string => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day'));
$ekle = static function (PDO $db, string $tablo, array $v): int {
    $db->prepare('INSERT INTO ' . $tablo . ' (' . implode(',', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
    return (int)$db->lastInsertId();
};
$S = [];
foreach ([[1, -40, -38], [2, -20, -18], [3, -3, -1]] as [$i, $yk, $ft]) {
    $S[$i] = $ekle($db, 'siparis_sevkiyatlar', ['order_ref' => 'TC82000' . $i . '.10', 'yukleme_tarihi' => $gun($yk), 'alici_kodu' => '1038529', 'alici_adi' => 'LIDL RS', 'tedarikci_no' => '', 'durum' => 'mail_gonderildi', 'depo' => 'DEPO1', 'var_po' => 1]);
    $ekle($db, 'siparis_kalemler', ['sevkiyat_id' => $S[$i], 'ian' => '', 'sira' => 1, 'kalibre' => '14', 'koli_icerik' => '3,5 KG', 'etkin_koli' => 1000, 'pc_koli' => 1000, 'fiyat' => 1.37, 'para_birimi' => 'EUR', 'fiyat_birim' => 'KG', 'fiyat_tarihi' => $gun($ft)]);
    $bid = 900 + $i;
    $ekle($db, 'siparis_belgeler', ['id' => $bid, 'tur' => 'invoice', 'kaynak' => 'uretim', 'durum' => 'islendi', 'sha256' => str_pad((string)$bid, 64, '0', STR_PAD_LEFT), 'disk_adi' => 'd' . $i . '.pdf', 'orijinal_ad' => 'INV-' . $i . '.pdf', 'mime' => 'application/pdf',
        'parse_json' => json_encode(['toplam_kurus' => 460320])]);   // 3.500 kg × 1,37 = 4.795,00 − %4 191,80 = 4.603,20
    $ekle($db, 'siparis_belge_bag', ['belge_id' => $bid, 'sevkiyat_id' => $S[$i], 'gecerli' => 1]);
    $ekle($db, 'hks_kv', ['anahtar' => 'siparis_invoice_' . $S[$i], 'deger' => json_encode(['no' => $G($S[$i]), 'tarih' => $gun($ft), 'fiyat' => '1,37', 'iskonto' => '4', 'uretildi_at' => $gun($ft) . ' 10:00:00', 'belge_id' => $bid])]);
}
// 1. sevkiyat avizoyla ödendi (fatura + 21 gün)
$odId = $ekle($db, 'siparis_odemeler', ['odeme_tarihi' => $gun(-17), 'tutar_kurus' => 460320, 'para_birimi' => 'EUR', 'aciklama' => 'OGL ödeme avizosu · mailden · 1 kalem', 'dekont_no' => 'AVZ-1', 'istek_id' => 'avz-test0000000001', 'created_at' => $gun(-17) . ' 09:00:00']);
$ekle($db, 'hks_kv', ['anahtar' => 'siparis_odeme_aviz_' . $odId, 'deger' => json_encode(['v' => 1, 'satirlar' => [['n' => $G($S[1]), 'd' => $gun(-38), 'r' => 460320, 'z' => 460320, 't' => 'ana', 'g' => $G($S[1]), 's' => '', 'e' => 'eslesti', 'v' => []]]])]);
$PERMS = ['siparis.read', 'reports.export']; $IS_ADMIN = false; $UID = 3; $DEPOLAR = ['DEPO1'];
$r = vd_uc('siparis_vade.php');
$g = $r['govde'];
$cikti = (string)(getenv('SIPARIS_VADE_OUT') ?: '');
if ($cikti !== '') { @mkdir($cikti, 0777, true); file_put_contents($cikti . '/vade.html', $g); }
ok('sayfa: hata/uyarı yok', $r['hata'] === null && !preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b:/', $g), (string)$r['hata'] . substr(strip_tags($g), 0, 300));
ok('sayfa: başlık + bu hafta kutusu + takvim + açık faturalar + geçmiş ödemeler', str_contains($g, 'OGL Haftalık Ödeme') && str_contains($g, 'id="svBuHafta"') && str_contains($g, 'id="svHaftalar"') && str_contains($g, 'id="svAcik"') && str_contains($g, 'id="svOdemeler"'));
ok('sayfa: 1. sevkiyat ödendi (21 gün), 2. sevkiyat (fatura −18 + 21 = +3 gün) takvimde; 4.603,20 tutarı görünür', str_contains($g, 'Ödenmiş invoice\'lar (1)') && str_contains($g, $G($S[2])) && str_contains($g, '4.603,20'));
ok('sayfa: ödenmemiş faturada "Yüklemeden beri (gün)" sütunu + sayı (v570)', str_contains($g, 'Yüklemeden beri (gün)') && (bool)preg_match('~class="num sv-yg">\d+</td>~', $g));
ok('sayfa: GET form, onclick yok, POST yok', str_contains($g, 'method="get" action="siparis_vade.php"') && !preg_match('/\sonclick=/i', $g) && !str_contains($g, 'method="post"'));
$r = vd_uc('siparis_vade.php', ['vade' => 'abc']);
ok('sayfa: geçersiz vade → hata kutusu, sayfa çalışır', $r['hata'] === null && str_contains($r['govde'], 'Parametre hatası'));
$PERMS = ['siparis.read'];
$r = vd_uc('siparis_vade.php');
ok('kapı: reports.export yoksa forbidden', $r['hata'] !== null && str_contains((string)$r['hata'], 'forbidden'));

siparis_test_bitir();
