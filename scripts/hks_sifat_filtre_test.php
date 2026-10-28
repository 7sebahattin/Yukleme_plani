<?php
// =========================================================
// scripts/hks_sifat_filtre_test.php — Sıfat filtreli künye sorgusu (KisiSifat)
//
// SADECE CLI. Ağ yok, canlı DB'ye dokunmaz. SOAP enjekte callable'larla taklit
// edilir; hks_soap.php kaynağı statik taranır. Künye no'ları gerçek vakadan
// (sıfat=2, plan 7418 KG: 340 uyumlu; 1280 + 5798 uyumsuz).
//   php scripts/hks_sifat_filtre_test.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$KOK = dirname(__DIR__);

$GLOBALS['__KV'] = [];
function hks_kv_oku($anahtar, $varsayilan = null) {
    return array_key_exists($anahtar, $GLOBALS['__KV']) ? json_decode($GLOBALS['__KV'][$anahtar], true) : $varsayilan;
}
function hks_kv_yaz($anahtar, $veri) { $GLOBALS['__KV'][$anahtar] = json_encode($veri, JSON_UNESCAPED_UNICODE); }
require_once "$KOK/halkayit/kunye_secim_lib.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
function nolar(array $l): array { return array_map(fn($k) => (string)$k['kunyeNo'], $l); }
function kunye(string $no, string $tarih, float $kalan, ?float $fiyat = null): array {
    $k = ['kunyeNo' => $no, 'tarih' => $tarih, 'kalan' => $kalan, 'urun' => 'Ürün'];
    if ($fiyat !== null) $k['fiyat'] = $fiyat;
    return $k;
}
function toplam_kg(array $satirlar): float {
    $t = 0.0;
    foreach ($satirlar as $s) $t += (float)($s['miktar'] ?? $s['kg'] ?? $s['kalan'] ?? 0);
    return round($t, 3);
}

const U1 = '1024159260028302865';   // uyumlu, 340
const X1 = '1074159260028576447';   // uyumsuz, 1280
const X2 = '1074159260028576405';   // uyumsuz, 5798
const U2 = '1024159260028302999';   // uyumlu, 3000
const U3 = '1024159260028303111';   // uyumlu, 4200

// Tam stok: uyumsuzlar en ESKİ tarihli (filtre yoksa önce onlar seçilirdi).
$tam = [
    kunye(X1, '2026-01-01', 1280),
    kunye(X2, '2026-01-02', 5798),
    kunye(U1, '2026-02-01', 340),
    kunye(U2, '2026-03-01', 3000),
    kunye(U3, '2026-04-01', 4200),
];
$uyumlu = [kunye(U1, '2026-02-01', 340), kunye(U2, '2026-03-01', 3000), kunye(U3, '2026-04-01', 4200)];
$ortak = ['planKg' => 7418, 'planSorgu' => ['urunId' => 12, 'aySayisi' => 6], 'sifatId' => 2];

echo "── A) KisiSifat SOAP gövdesi (statik) ──\n";
$soap = (string)file_get_contents("$KOK/halkayit/hks_soap.php");
$bas = strpos($soap, 'function hks_kunye_penceresi(');
$son = $bas === false ? false : strpos($soap, "\nfunction ", $bas + 10);
$govde = $bas === false ? '' : substr($soap, $bas, ($son ?: $bas + 3000) - $bas);
$pK = strpos($govde, 'KalanMiktariSifirdanBuyukOlanlar>');
$pS = strpos($govde, 'a:KisiSifat>');
$pN = strpos($govde, '<a:KunyeNo>0</a:KunyeNo>');
ok('KisiSifat, KalanMiktari… ile KunyeNo ARASINDA', $pK !== false && $pS !== false && $pN !== false && $pK < $pS && $pS < $pN);
$satir = '';
foreach (explode("\n", $govde) as $ln) if (strpos($ln, 'a:KisiSifat>') !== false) $satir = $ln;
ok('KisiSifat koşullu (kisiSifat > 0 ya da boş değilse)', (bool)preg_match('/kisiSifat/', $satir) || (bool)preg_match('/if\s*\([^)]*kisiSifat[^)]*\)[^;]*\$p\[\]\s*=\s*[^;]*KisiSifat/s', $govde));
ok('KisiSifat (int) olarak yazılır', (bool)preg_match('/KisiSifat>\'\s*\.\s*\(int\)/', $govde));

echo "── B) Sıfat filtreli plan çözümü ──\n";
$c = ['kunye' => 0, 'sifatli' => 0, 'sec' => []];
$getir = function (array $s) use (&$c, $tam) { $c['kunye']++; return $tam; };
$sifatli = function (array $s) use (&$c, $uyumlu) { $c['sifatli']++; $c['sec'][] = $s; return $uyumlu; };

$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, null, [], null, $sifatli);
$no = nolar($p['satirlar'] ?? []);
ok('filtre kullanıldı, satır var', isset($p['satirlar']) && ($p['sifatFiltreKullanildi'] ?? null) === true, json_encode($p));
ok('yalnız uyumlu künyeler; uyumsuzlar HİÇ yok', $no && !in_array(X1, $no, true) && !in_array(X2, $no, true) && !array_diff($no, [U1, U2, U3]), json_encode($no));
ok('toplam = 7418 KG', toplam_kg($p['satirlar'] ?? []) === 7418.0, (string)toplam_kg($p['satirlar'] ?? []));
ok('başarıda $kunyeGetir ÇAĞRILMAZ', $c['kunye'] === 0 && $c['sifatli'] === 1, json_encode($c['kunye']));
ok('sifatliGetir aynı $secenek dizisiyle çağrılır',
    ($c['sec'][0]['urunId'] ?? null) === 12 && ($c['sec'][0]['aySayisi'] ?? null) === 6 && array_key_exists('sirala', $c['sec'][0] ?? []), json_encode($c['sec']));

// Eski davranış karşılaştırması: sifatliGetir=null → sıfat yok, uyumsuzlar seçilir
$c = ['kunye' => 0, 'sifatli' => 0, 'sec' => []];
$p0 = hks_plan_kunye_coz_cekirdek($ortak, $getir, null, [], null, null);
ok('sifatliGetir=null: eski yol, sifatFiltreKullanildi=false', ($p0['sifatFiltreKullanildi'] ?? null) === false && $c['kunye'] === 1);
ok('sifatliGetir=null: sonuç eski (5 argümanlı) çağrıyla birebir',
    $p0 === hks_plan_kunye_coz_cekirdek($ortak, $getir, null, []));
ok('sifatliGetir=null: eski sıralama uyumsuzu da seçer (filtre farkı)', in_array(X1, nolar($p0['satirlar'] ?? []), true));

// sifatId=0 → sifatliGetir çağrılmaz
$c = ['kunye' => 0, 'sifatli' => 0, 'sec' => []];
$p = hks_plan_kunye_coz_cekirdek(['sifatId' => 0] + $ortak, $getir, null, [], null, $sifatli);
ok('sifatId=0: sifatliGetir çağrılmaz, eski yol', $c['sifatli'] === 0 && $c['kunye'] === 1 && ($p['sifatFiltreKullanildi'] ?? null) === false);
$ok2 = $ortak; unset($ok2['sifatId']);
$c = ['kunye' => 0, 'sifatli' => 0, 'sec' => []];
$p = hks_plan_kunye_coz_cekirdek($ok2, $getir, null, [], null, $sifatli);
ok('sifatId yok: sifatliGetir çağrılmaz', $c['sifatli'] === 0 && ($p['sifatFiltreKullanildi'] ?? null) === false);

// Hata yollarında da anahtar var
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 0] + $ortak, $getir, null, [], null, $sifatli);
ok('planKg yok hatasında da sifatFiltreKullanildi=false', isset($p['hata']) && ($p['sifatFiltreKullanildi'] ?? null) === false);
$p = hks_plan_kunye_coz_cekirdek(['planSorgu' => []] + $ortak, $getir, null, [], null, $sifatli);
ok('ürün yok hatasında da sifatFiltreKullanildi=false', isset($p['hata']) && ($p['sifatFiltreKullanildi'] ?? null) === false);

echo "── C) Stok yetersiz (uyumlu stok az) ──\n";
$c = ['kunye' => 0, 'sifatli' => 0];
$az = [kunye(U1, '2026-02-01', 340), kunye(U2, '2026-03-01', 3000)];   // 3340 < 7418
$tamAz = [kunye(X1, '2026-01-01', 1280), kunye(X2, '2026-01-02', 5798), kunye(U1, '2026-02-01', 340), kunye(U2, '2026-03-01', 3000)];
$getirAz = fn(array $s) => $tamAz;
$sifatliAz = function (array $s) use (&$c, $az) { $c['sifatli']++; return $az; };
$p = hks_plan_kunye_coz_cekirdek($ortak, function (array $s) use (&$c, $tamAz) { $c['kunye']++; return $tamAz; }, null, [], null, $sifatliAz);
$h = $p['hata'] ?? '';
ok('Stok yetersiz hatası, satır YOK', strpos($h, 'Stok yetersiz') !== false && !isset($p['satirlar']), $h);
ok('filtre kullanıldı bayrağı hata dönüşünde de true', ($p['sifatFiltreKullanildi'] ?? null) === true);
ok('"seçilen sıfatla uyumsuz" notu', strpos($h, 'seçilen sıfatla uyumsuz') !== false, $h);
ok('not: uyumsuz kalanlı künyelerin toplamı 7.078 KG (1280+5798)', strpos($h, '7.078') !== false, $h);
ok('not için $kunyeGetir tembelce çağrıldı (1 kez)', $c['kunye'] === 0 ? false : true);

// $kunyeGetir hata verirse not yok ama hata yine döner
$patlak = function (array $s) { throw new RuntimeException('ağ'); };
$p = hks_plan_kunye_coz_cekirdek($ortak, $patlak, null, [], null, $sifatliAz);
ok('kunyeGetir patlarsa: Stok yetersiz hatası yine döner, not yok',
    isset($p['hata']) && strpos($p['hata'], 'Stok yetersiz') !== false && strpos($p['hata'], 'seçilen sıfatla uyumsuz') === false && ($p['sifatFiltreKullanildi'] ?? null) === true, json_encode($p));

echo "── D) Fail-open: hata / boş liste ──\n";
$c = ['kunye' => 0];
$hataF = function (array $s) { throw new RuntimeException('KisiSifat reddedildi'); };
$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, null, [], null, $hataF);
ok('sifatliGetir Throwable: eski yola düşer, satır var', isset($p['satirlar']) && $c['kunye'] === 1, json_encode($p));
ok('sifatFiltreHata dolu, filtre kullanılmadı', !empty($p['sifatFiltreHata']) && ($p['sifatFiltreKullanildi'] ?? null) === false);
$c = ['kunye' => 0];
$bosF = fn(array $s) => [];
$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, null, [], null, $bosF);
ok('boş liste: sifatFiltreBos=true, eski yol, filtre yok',
    ($p['sifatFiltreBos'] ?? null) === true && ($p['sifatFiltreKullanildi'] ?? null) === false && $c['kunye'] === 1 && isset($p['satirlar']), json_encode($p));

echo "── E) Öğrenilmiş dışlama filtre SONRASI da uygulanır ──\n";
$c = ['kunye' => 0, 'sifatli' => 0, 'sec' => []];
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 3340] + $ortak, $getir, null, [U3], null, $sifatli);
ok('uyumlu ama öğrenilmiş uyumsuz U3 seçilmez', nolar($p['satirlar'] ?? []) === [U1, U2] && ($p['sifatFiltreKullanildi'] ?? null) === true, json_encode($p));
ok('atlananSifatUyumsuz U3 raporlanır', ($p['atlananSifatUyumsuz'] ?? []) === [U3], json_encode($p['atlananSifatUyumsuz'] ?? null));
$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, null, [U3], null, $sifatli);
ok('dışlama sonrası stok yetmezse not içerir (öğrenilmiş atlandı)', isset($p['hata']) && strpos($p['hata'], 'Stok yetersiz') !== false && strpos($p['hata'], 'bu sıfatla uyumsuz olduğu için atlandı') !== false, $p['hata'] ?? '');

echo "── F) Fiyat sıralaması filtreli listeyle ──\n";
$fU = [kunye(U1, '2026-02-01', 340), kunye(U2, '2026-03-01', 3000), kunye(U3, '2026-04-01', 4200)];
$detay = fn(int $ay) => ['harita' => [U1 => ['kunyeNo' => U1, 'fiyat' => 50.0], U2 => ['kunyeNo' => U2, 'fiyat' => 25.0], U3 => ['kunyeNo' => U3, 'fiyat' => 80.0]]];
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 3000, 'fiyat' => 25.0] + $ortak, $getir, $detay, [], null, fn($s) => $fU);
ok('fiyat yakınlığı: U2 önce (25 ₺)', nolar($p['satirlar'] ?? []) === [U2] && ($p['fiyatSiralamaKullanildi'] ?? null) === true && ($p['sifatFiltreKullanildi'] ?? null) === true, json_encode($p));

echo "── G) Kaynak (v366) kuralı + sıfat filtresi birlikte ──\n";
$AGRO = '1234567890'; $BASKA = '9876543210';
$detayK = fn(int $ay) => ['harita' => [U1 => ['kunyeNo' => U1, 'bildirimciTc' => $AGRO], U2 => ['kunyeNo' => U2, 'bildirimciTc' => $BASKA], U3 => ['kunyeNo' => U3, 'bildirimciTc' => $AGRO]]];
$c = ['kunye' => 0];
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 4540] + $ortak, $getir, $detayK, [], ['tc' => $AGRO, 'kendi' => '1111111111', 'ogrenilen' => []], fn($s) => $uyumlu);
ok('uyumlu + kaynak farklı U2 atlanır → U1, U3', nolar($p['satirlar'] ?? []) === [U1, U3] && ($p['atlananKaynakFarkli'] ?? []) === [U2] && ($p['sifatFiltreKullanildi'] ?? null) === true, json_encode($p));
ok('kaynak kipinde kokenHarita döner', ($p['kokenHarita'][U1] ?? '') === $AGRO);
ok('uyumsuz X1/X2 hiçbir yolla seçilmez', !array_intersect(nolar($p['satirlar'] ?? []), [X1, X2]));
$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, $detayK, [], ['tc' => $AGRO, 'kendi' => '', 'ogrenilen' => []], fn($s) => $uyumlu);
ok('kaynak + sıfat: yetmezse tek hata, iki not da var',
    isset($p['hata']) && strpos($p['hata'], 'Stok yetersiz') !== false && strpos($p['hata'], 'başka kişiden geldiği') !== false && strpos($p['hata'], 'seçilen sıfatla uyumsuz') !== false, $p['hata'] ?? '');

echo "── H) hks_kunyelere_sifat_filtre_bayragi ──\n";
$liste = [
    ['kunyeNo' => X1, 'kalan' => 1280],
    ['kunyeNo' => U1, 'kalan' => 340],
    ['kunyeNo' => U2, 'kalan' => 3000, 'sifatUyumsuz' => true],     // öğrenilmiş: false'a çevrilmez
    ['kunyeNo' => X2, 'kalan' => 5798, 'sifatUyumsuz' => false],
];
$r = hks_kunyelere_sifat_filtre_bayragi($liste, [['kunyeNo' => U1], ['kunyeNo' => U3]]);
ok('listede olmayan → sifatUyumsuz=true', $r[0]['sifatUyumsuz'] === true && $r[3]['sifatUyumsuz'] === true);
ok('uyumlu olan: mevcut değer yoksa false', $r[1]['sifatUyumsuz'] === false);
ok('zaten true olan false\'a ÇEVRİLMEZ (uyumlu listede olsa bile)', $r[2]['sifatUyumsuz'] === true);
ok('sıra ve diğer alanlar korunur', nolar($r) === [X1, U1, U2, X2] && $r[0]['kalan'] === 1280);
$ro = hks_kunyelere_sifat_filtre_bayragi($liste, []);
ok('$uyumlu boş → liste DEĞİŞMEZ (fail-open)', $ro === $liste);
$r2 = hks_kunyelere_sifat_filtre_bayragi([['kunyeNo' => '1024-1592-6002-8302-865']], [['kunyeNo' => U1]]);
ok('künye no karşılaştırması hks_kunye_no_norm ile (rakam dışı karakterler atılır)', ($r2[0]['sifatUyumsuz'] ?? null) === false, json_encode($r2));
$r3 = hks_kunyelere_sifat_filtre_bayragi([['kunyeNo' => U1, 'sifatUyumsuz' => false]], [['kunyeNo' => U2]]);
ok('false olan, uyumlu listede yoksa true olur', $r3[0]['sifatUyumsuz'] === true);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
