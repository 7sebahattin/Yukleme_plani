<?php
// =========================================================
// scripts/hks_kunye_secim_test.php — Referans künye SEÇİM kuralları
//
// SADECE CLI. Ağ çağrısı YAPMAZ, canlı DB'ye DOKUNMAZ: halkayit/kunye_secim_lib.php'nin
// GERÇEK fonksiyonları; SOAP çağrıları plan çekirdeğine callable olarak enjekte
// edilir, hks_kv_oku / hks_kv_yaz bellek içi dizi ile taklit edilir. api.php akış
// kuralları kaynakta (statik) denetlenir. Künye no'ları sentetiktir.
//
// Kapsam: sıralama (fiyat yakınlığı > eski tarih > küçük kalan > künye no;
// fiyatsız künye sonda; satış fiyatı yoksa tarih → kalan), dağıtım toplamı = hedef,
// dışlama, 30 gün TTL, sıfat mesajı tespiti (263 negatif), künyeye-özgü ayrım
// (kısmi başarı, tek satır, tümü düştü + mesajda künye no yok → kaydetme),
// plan çekirdeği (fiyat best-effort, dışlama notu, stok yetersiz), api.php kancaları.
// Mutasyon: MUTASYON=1 ortam değişkeniyle sıralama anahtarı bozulmuş kopya
// çalıştırılır ve testlerin KIRILDIĞI doğrulanır.
//
//   php scripts/hks_kunye_secim_test.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);

// ---- Mutasyon kipi: lib'in sıralamayı bozan kopyası yüklenir ----
$mutasyon = getenv('MUTASYON') ?: '';
$libYol = "$KOK/halkayit/kunye_secim_lib.php";
if ($mutasyon !== '') {
    $src = (string)file_get_contents($libYol);
    $orijinal = $src;
    if ($mutasyon === 'tarih') {         // eski → yeni yerine yeni → eski
        $src = str_replace('return strcmp($ta, $tb);', 'return strcmp($tb, $ta);', $src);
    } elseif ($mutasyon === 'kalan') {   // küçük kalan yerine büyük kalan
        $src = str_replace("\$c = (float)\$a['kalan'] <=> (float)\$b['kalan'];", "\$c = (float)\$b['kalan'] <=> (float)\$a['kalan'];", $src);
    } elseif ($mutasyon === 'fiyat') {   // fiyat yakınlığı yok sayılır
        $src = str_replace('$c = $fark($a) <=> $fark($b);', '$c = 0;', $src);
    } elseif ($mutasyon === 'ozgu') {    // tümü düştüğünde de kaydet
        $src = str_replace('if (!$basariVar && !$tekSatir) continue;', '', $src);
    }
    if ($src === $orijinal) { fwrite(STDERR, "mutasyon uygulanamadı: $mutasyon\n"); exit(2); }
    $libYol = sys_get_temp_dir() . '/kunye_secim_mut_' . getmypid() . '.php';
    file_put_contents($libYol, $src);
    register_shutdown_function(fn() => @unlink($libYol));
}

// db.php'deki hks_kv_oku / hks_kv_yaz'ın bellek içi taklidi (JSON gidiş-dönüşüyle).
$GLOBALS['__KV'] = [];
function hks_kv_oku($anahtar, $varsayilan = null) {
    return array_key_exists($anahtar, $GLOBALS['__KV']) ? json_decode($GLOBALS['__KV'][$anahtar], true) : $varsayilan;
}
function hks_kv_yaz($anahtar, $veri) {
    $GLOBALS['__KV'][$anahtar] = json_encode($veri, JSON_UNESCAPED_UNICODE);
}

require_once $libYol;

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-74s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu);
}
function nolar(array $l): array { return array_map(fn($k) => (string)$k['kunyeNo'], $l); }

// Sentetik künyeler (künye no 19 hane, HKS biçiminde).
function kunye(string $no, string $tarih, float $kalan, ?float $fiyat = null): array {
    $k = ['kunyeNo' => $no, 'tarih' => $tarih, 'kalan' => $kalan, 'urun' => 'Ürün'];
    if ($fiyat !== null) $k['fiyat'] = $fiyat;
    return $k;
}
const K1 = '2600000000000000001';
const K2 = '2600000000000000002';
const K3 = '2600000000000000003';
const K4 = '2600000000000000004';
const K5 = '2600000000000000005';

echo "── Sıralama ──\n";
// Satış fiyatı yok → tarih (eski önce) → kalan (küçük önce) → künye no.
$l = [
    kunye(K1, '2026-09-10', 500),
    kunye(K2, '2026-08-01', 900),
    kunye(K3, '2026-08-01', 100),
    kunye(K4, '', 50),                 // tarihsiz → en sonda
    kunye(K5, '2026-07-01', 0),        // kalan 0 → aday değil
];
$s = hks_kunye_sirala($l, 0.0, []);
ok('fiyat yok: tarih → kalan → tarihsiz sonda; kalan 0 aday değil', nolar($s) === [K3, K2, K1, K4], implode(',', nolar($s)));

// Aynı tarih + aynı kalan → künye no artan.
$s = hks_kunye_sirala([kunye(K2, '2026-08-01', 10), kunye(K1, '2026-08-01', 10)], 0.0, []);
ok('eşitlikte künye no artan (deterministik)', nolar($s) === [K1, K2]);

// Satış fiyatı 20: fiyat yakınlığı tarihten önce gelir; fiyatsız künye fiyatlıların arkasında.
$l = [
    kunye(K1, '2026-01-01', 100, 30.0),   // fark 10 (en eski ama uzak)
    kunye(K2, '2026-09-01', 100, 21.0),   // fark 1
    kunye(K3, '2026-08-01', 100, 19.0),   // fark 1 → K2 ile eşit, daha eski
    kunye(K4, '2025-01-01', 100),         // fiyatsız, en eski — yine de sonda
    kunye(K5, '2026-05-01', 100, 0.0),    // fiyat 0 = fiyatsız
];
$s = hks_kunye_sirala($l, 20.0, []);
ok('satış fiyatı: en yakın fiyat önce, eşit farkta eski tarih', nolar($s) === [K3, K2, K1, K4, K5], implode(',', nolar($s)));

// Fiyat farkı 2 ondalığa yuvarlanır → 0.001 fark eşit sayılır, tarih karar verir.
$s = hks_kunye_sirala([kunye(K1, '2026-09-01', 5, 20.001), kunye(K2, '2026-01-01', 5, 20.004)], 20.0, []);
ok('fiyat farkı 2 ondalık (çok küçük fark eşit → eski tarih)', nolar($s) === [K2, K1]);

// Satış fiyatı 0 iken künye fiyatları yok sayılır.
$s = hks_kunye_sirala([kunye(K1, '2026-09-01', 5, 20.0), kunye(K2, '2026-01-01', 5, 99.0)], 0.0, []);
ok('satış fiyatı yok → künye fiyatı sıralamayı etkilemez', nolar($s) === [K2, K1]);

// Aynı fark + aynı tarih → küçük kalan önce.
$s = hks_kunye_sirala([kunye(K1, '2026-09-01', 800, 20.0), kunye(K2, '2026-09-01', 40, 20.0)], 20.0, []);
ok('aynı fiyat farkı + tarih → küçük kalan önce', nolar($s) === [K2, K1]);

// Dışlama (yazım farkına dayanıklı).
$s = hks_kunye_sirala([kunye(K1, '2026-01-01', 5), kunye(K2, '2026-02-01', 5)], 0.0, [' ' . K1 . ' ']);
ok('dışlanan künye aday değil', nolar($s) === [K2]);

echo "── Dağıtım ──\n";
$d = hks_kunye_dagit([kunye(K1, 'x', 100.5), kunye(K2, 'x', 300), kunye(K3, 'x', 50)], 250.25);
$top = round(array_sum(array_column($d['satirlar'], 'miktar')), 3);
ok('dağıtım toplamı = hedef', $top === 250.25 && $d['acik'] == 0.0, json_encode($d));
ok('greedy sıra: ilk künye tamamen, ikinci kısmen, üçüncü hiç',
    nolar($d['satirlar']) === [K1, K2] && $d['satirlar'][1]['miktar'] === 149.75);
$d = hks_kunye_dagit([kunye(K1, 'x', 10)], 25);
ok('yetersiz stok → açık kalan döner', abs($d['acik'] - 15.0) < 1e-9);
$d = hks_kunye_dagit([kunye(K1, 'x', 0.1), kunye(K2, 'x', 0.2)], 0.3);
ok('ondalık birikim yok (3 ondalık)', $d['acik'] == 0.0 && count($d['satirlar']) === 2);

echo "── Sıfat mesajı tespiti ──\n";
foreach ([
    'Bildirimci sıfatı ile referans künye uyumsuz' => true,
    'BİLDİRİMCİ SIFATI UYGUN DEĞİL' => true,
    'SİFAT HATASI' => true,
    'Sifat uyumsuz' => true,
    'Kişi sıfatı bu bildirim türünde kullanılamaz.' => true,
    'Lütfen üreticinin GSM numarasını doğru giriniz' => false,     // 263
    'Tc kimlik numarası Mernis sisteminde bulunamadı' => false,     // 21
    'Referans künyenin kalan miktarı yetersiz' => false,
    '' => false,
] as $m => $bek) {
    ok('mesaj: "' . ($m === '' ? '(boş)' : mb_substr($m, 0, 40)) . '" → ' . ($bek ? 'sıfat' : 'değil'),
        hks_sifat_uyumsuz_mesaji_mi($m) === $bek);
}

echo "── Künyeye özgü sıfat hatası ──\n";
$hata = fn(string $m = 'Bildirimci sıfatı uyumsuz') => ['yeniKunyeNo' => '0', 'hataKodu' => 77, 'mesaj' => $m];
$basari = ['yeniKunyeNo' => '2700000000000000009', 'hataKodu' => 0, 'mesaj' => 'Başarılı'];
$gsm = ['yeniKunyeNo' => '0', 'hataKodu' => 263, 'mesaj' => 'Lütfen üreticinin GSM numarasını doğru giriniz'];
$sat3 = [['kunyeNo' => K1, 'miktar' => 1], ['kunyeNo' => K2, 'miktar' => 1], ['kunyeNo' => K3, 'miktar' => 1]];

$r = hks_kunye_sifat_hatali_satirlar([$basari, $hata(), $basari], $sat3);
ok('kısmi başarı: sıfat hatalı satır (sıra eşleme) kaydedilir', $r === [K2], json_encode($r));

$r = hks_kunye_sifat_hatali_satirlar([$hata()], [['kunyeNo' => K4, 'miktar' => 5]]);
ok('tek satırlık gönderim: künyeye özgü sayılır', $r === [K4], json_encode($r));

$r = hks_kunye_sifat_hatali_satirlar([$hata(), $hata(), $hata()], $sat3);
ok('tümü sıfat hatasıyla düştü + mesajda künye no yok → KAYDETME', $r === [], json_encode($r));

$r = hks_kunye_sifat_hatali_satirlar([$hata(), $hata('Künye ' . K3 . ' bildirimci sıfatı ile uyumsuz'), $hata()], $sat3);
ok('tümü düştü ama mesaj künye no içeriyor → yalnız o künye', $r === [K3], json_encode($r));

$r = hks_kunye_sifat_hatali_satirlar([$basari, $gsm, $basari], $sat3);
ok('sıfatla ilgisiz hata (263) kaydedilmez', $r === []);

$r = hks_kunye_sifat_hatali_satirlar([$basari, $hata()], $sat3);
ok('sonuç/satır sayısı farklı + mesajda no yok → atanamaz, kaydetme', $r === []);

$r = hks_kunye_sifat_hatali_satirlar([$basari, $hata('Sıfat uyumsuz: ' . K1)], $sat3);
ok('sayı farklı ama mesaj künye no içeriyor → o künye', $r === [K1]);

$r = hks_kunye_sifat_hatali_satirlar([$hata('Sıfat uyumsuz: ' . K1 . '5')], [['kunyeNo' => K1, 'miktar' => 1], ['kunyeNo' => K2, 'miktar' => 1]]);
ok('künye no yalnız TAM sayı olarak eşleşir (önek eşleşmesi yok)', $r === []);

$r = hks_kunye_sifat_hatali_satirlar([], $sat3);
ok('sonuç yok (genel hata) → boş', $r === []);

echo "── Kayıt + 30 gün TTL ──\n";
$F = 'f1700000000000';
$yeni = hks_kunye_sifat_uyumsuz_ekle($F, [K1, K2], 5, '2026-10-01');
ok('ekle: yeni kaydedilenler döner', $yeni === [K1, K2]);
$yeni = hks_kunye_sifat_uyumsuz_ekle($F, [K1, K3], 5, '2026-10-02');
ok('ekle: zaten kayıtlı olan "yeni" sayılmaz', $yeni === [K3]);
hks_kunye_sifat_uyumsuz_ekle($F, [K4], 9, '2026-10-02');
$m = hks_kunye_sifat_uyumsuz_oku($F, '2026-10-05');
$dis5 = hks_kunye_dislanan_set($m, 5);
sort($dis5);
ok('sıfat 5 için dışlananlar', $dis5 === [K1, K2, K3], json_encode($dis5));
ok('sıfat 9 için yalnız kendi kaydı', hks_kunye_dislanan_set($m, '9') === [K4]);
ok('kaydı olmayan sıfat → boş', hks_kunye_dislanan_set($m, 7) === []);
ok('firma bazlı: başka firma boş', hks_kunye_sifat_uyumsuz_oku('f9999', '2026-10-05') === []);
ok('kv anahtarı firma bazlı ve ≤ 60 karakter',
    isset($GLOBALS['__KV']['kunye_sifat_uyumsuz_' . $F]) && strlen('kunye_sifat_uyumsuz_' . $F) <= 60);
// K2 2026-10-01'de, K1 2026-10-02'de güncellendi. 30 gün sonra:
$m = hks_kunye_sifat_uyumsuz_oku($F, '2026-10-31');
$d = hks_kunye_dislanan_set($m, 5); sort($d);
ok('TTL: 30. günde (10-01 kaydı) düşer, 29. gündeki kalır', $d === [K1, K3], json_encode($d));
$m = hks_kunye_sifat_uyumsuz_oku($F, '2026-11-02');
ok('TTL: hepsi 30 günü geçince boş', hks_kunye_dislanan_set($m, 5) === [] && hks_kunye_dislanan_set($m, 9) === []);
ok('bozuk kv verisi → boş harita', hks_kunye_sifat_map_temizle('bozuk', '2026-10-01') === []
    && hks_kunye_sifat_map_temizle([K1 => ['5' => 'dün']], '2026-10-01') === []);
ok('sıfat 0 / firma boş → kaydetmez',
    hks_kunye_sifat_uyumsuz_ekle($F, [K5], 0, '2026-10-02') === [] && hks_kunye_sifat_uyumsuz_ekle('', [K5], 5) === []);
// Yazarken süresi geçenler budanır.
hks_kunye_sifat_uyumsuz_ekle($F, [K5], 5, '2026-12-01');
$ham = json_decode($GLOBALS['__KV']['kunye_sifat_uyumsuz_' . $F], true);
ok('yazarken süresi geçen girdiler budanır', array_map('strval', array_keys($ham)) === [K5], json_encode($ham));

// kunyeler ucu bayrağı
$bay = hks_kunyelere_sifat_bayragi([kunye(K5, 'x', 1), kunye(K1, 'x', 1)], hks_kunye_sifat_uyumsuz_oku($F, '2026-12-01'), 5);
ok('kunyeler ucu: sifatUyumsuz bayrağı', $bay[0]['sifatUyumsuz'] === true && $bay[1]['sifatUyumsuz'] === false);

echo "── Plan çekirdeği (enjekte SOAP) ──\n";
$stok = [
    kunye(K1, '2026-03-01', 400),
    kunye(K2, '2026-01-01', 300),
    kunye(K3, '2026-02-01', 200),
];
$cagri = ['kunye' => 0, 'detay' => 0, 'secenek' => null];
$getir = function (array $sec) use (&$cagri, $stok) { $cagri['kunye']++; $cagri['secenek'] = $sec; return $stok; };
$detay = function (int $ay) use (&$cagri) {
    $cagri['detay']++;
    return ['harita' => [K1 => ['kunyeNo' => K1, 'fiyat' => 25.0], K3 => ['kunyeNo' => K3, 'fiyat' => 40.0]], 'kismiHata' => true];
};
$ortak = ['planKg' => 500, 'planSorgu' => ['urunId' => 12, 'aySayisi' => 6], 'sifatId' => 5];

$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, $detay, []);
ok('fiyat yok: detay ÇAĞRILMAZ, eski tarih önce (K2, K3)',
    $cagri['detay'] === 0 && nolar($p['satirlar']) === [K2, K3] && $p['fiyatSiralamaKullanildi'] === false, json_encode($p));
ok('künye sorgusu planSorgu ile', $cagri['secenek']['urunId'] === 12 && $cagri['secenek']['aySayisi'] === 6);
ok('dönüş alanları mevcut', array_key_exists('atlananSifatUyumsuz', $p) && $p['atlananSifatUyumsuz'] === []);

$p = hks_plan_kunye_coz_cekirdek($ortak + ['fiyat' => '24,5'], $getir, $detay, []);
ok('fiyat "24,5": detay çağrılır, en yakın fiyat (K1 25) önce, sonra K3 (40), fiyatsız K2 sonda',
    $cagri['detay'] === 1 && nolar($p['satirlar']) === [K1, K3] && $p['satirlar'][0]['miktar'] === 400.0
    && $p['satirlar'][1]['miktar'] === 100.0 && $p['fiyatSiralamaKullanildi'] === true, json_encode($p));

$patlak = function (int $ay) { throw new RuntimeException('SOAP zaman aşımı'); };
$p = hks_plan_kunye_coz_cekirdek($ortak + ['fiyat' => 24.5], $getir, $patlak, []);
ok('detay istisnası → fiyatsız devam (best-effort)',
    nolar($p['satirlar']) === [K2, K3] && $p['fiyatSiralamaKullanildi'] === false, json_encode($p));

$p = hks_plan_kunye_coz_cekirdek($ortak, $getir, $detay, [K2]);
ok('dışlanan K2 atlanır → K3, K1', nolar($p['satirlar']) === [K3, K1] && $p['atlananSifatUyumsuz'] === [K2], json_encode($p));

$p = hks_plan_kunye_coz_cekirdek(['planKg' => 700] + $ortak, $getir, $detay, [K2]);
ok('dışlama yüzünden stok yetersiz: hata + atlama notu',
    isset($p['hata']) && strpos($p['hata'], 'Stok yetersiz') === 0
    && strpos($p['hata'], '1 künye (300 KG) bu sıfatla uyumsuz olduğu için atlandı') !== false
    && strpos($p['hata'], 'taslak silinmedi') !== false && !isset($p['satirlar']), $p['hata'] ?? '');

$p = hks_plan_kunye_coz_cekirdek(['planKg' => 901] + $ortak, $getir, $detay, []);
ok('stok yetersiz (dışlama yok): not eklenmez', isset($p['hata']) && strpos($p['hata'], 'atlandı') === false);
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 0] + $ortak, $getir, $detay, []);
ok('plan kilosu yok → hata', ($p['hata'] ?? '') === 'Plan taslağında toplam kilo yok.');
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 5, 'planSorgu' => []], $getir, $detay, []);
ok('ürün yok → hata', ($p['hata'] ?? '') === 'Plan taslağında ürün bilgisi yok.');

$cok = [];
for ($i = 1; $i <= 101; $i++) $cok[] = kunye(sprintf('26%017d', $i), '2026-01-01', 1);
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 101] + $ortak, fn($s) => $cok, null, []);
ok('100 künye sınırı korunur', isset($p['hata']) && strpos($p['hata'], '101 künyeye dağıldı') !== false);

echo "── Depo/Tasnif kaynak kuralı (HataKodu 108, v366) ──\n";
$m108 = 'Depo/Tasnif sıfatından sevk ederken, ancak referans künye bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz.. Referans künye no : "' . K2 . '"';
$h108 = ['yeniKunyeNo' => '0', 'hataKodu' => 108, 'mesaj' => $m108];
ok('108: kaynak karşı taraf hatası', hks_kaynak_karsi_taraf_hatasi_mi($h108));
ok('108 metni kodsuz da tanınır (büyük harf/İ)', hks_kaynak_karsi_taraf_hatasi_mi(['hataKodu' => 5, 'mesaj' => mb_strtoupper('REFERANS KÜNYE BİLDİRİMİNİ YAPAN KİŞİYE', 'UTF-8')]));
ok('sıradan sıfat hatası 108 sayılmaz', !hks_kaynak_karsi_taraf_hatasi_mi($hata()) && !hks_kaynak_karsi_taraf_hatasi_mi($gsm));
$r = hks_kunye_sifat_hatali_satirlar([$h108], [['kunyeNo' => K2, 'miktar' => 1]]);
ok('108 künye×sıfat uyumsuzluğu olarak KAYDEDİLMEZ (tek satır)', $r === [], json_encode($r));
$r = hks_kunye_sifat_hatali_satirlar([$basari, $h108, $hata()], $sat3);
ok('karışık: 108 atlanır, gerçek sıfat hatası (K3) yine kaydedilir', $r === [K3], json_encode($r));
$r = hks_kaynak_hatali_kunyeler([$basari, $h108, $hata()], $sat3);
ok('108 satırı künyesi (mesajdaki no) bulunur', $r === [K2], json_encode($r));
$r = hks_kaynak_hatali_kunyeler([['hataKodu' => 108, 'mesaj' => 'kaynak']], [['kunyeNo' => K4]]);
ok('108 mesajda no yoksa sıra eşlemesi', $r === [K4], json_encode($r));
ok('108 sayı farklı + no yok → atanmaz', hks_kaynak_hatali_kunyeler([['hataKodu' => 108, 'mesaj' => 'x']], $sat3) === []);

// Tek seferlik temizlik bayrağı
$F2 = 'f1800000000000';
$GLOBALS['__KV']['kunye_sifat_uyumsuz_' . $F2] = json_encode([K1 => ['9' => '2026-10-10']]);
$m = hks_kunye_sifat_uyumsuz_oku($F2, '2026-10-15');
ok('v366 temizliği: bayrak yokken eski harita boşaltılır', $m === [] && json_decode($GLOBALS['__KV']['kunye_sifat_uyumsuz_' . $F2], true) === []);
ok('temizlik bayrağı yazıldı (anahtar ≤ 60)', isset($GLOBALS['__KV']['kunye_sifat_v366_' . $F2]) && strlen('kunye_sifat_v366_' . $F2) <= 60);
hks_kunye_sifat_uyumsuz_ekle($F2, [K1], 9, '2026-10-15');
ok('bayraktan sonra yeni kayıtlar korunur', hks_kunye_dislanan_set(hks_kunye_sifat_uyumsuz_oku($F2, '2026-10-16'), 9) === [K1]);

// Kaynak haritası
$AGRO = '0091880872'; $BASKA = '1234567890';
$km = hks_kunye_kaynak_map_ekle([], [K1], $AGRO, 2, '2026-10-01');
$km = hks_kunye_kaynak_map_ekle($km, [K1], $AGRO, 7, '2026-10-02', [K1 => $BASKA]);
$b = hks_kunye_kaynak_bilgisi($km, K1);
ok('kaynak bilgisi: koken öncelikli kaynakTc', $b['kaynakTc'] === $BASKA && $b['kaynakKoken'] === $BASKA, json_encode($b));
ok('kaynak bilgisi: TC bazında red listesi', (array)$b['kaynakRed'] === [$AGRO => [2, 7]], json_encode($b));
$km2 = hks_kunye_kaynak_map_ekle([], [K2], $AGRO, 2, '2026-10-01');
$b2 = hks_kunye_kaynak_bilgisi($km2, K2);
ok('koken yoksa kaynakTc = son denenen TC, red = o TC', $b2['kaynakTc'] === $AGRO && $b2['kaynakKoken'] === '' && $b2['kaynakRedSifatlar'] === [2], json_encode($b2));
ok('red engeli: aynı künye+TC+sıfat', hks_kaynak_red_engeli([['kunyeNo' => K1]], $km, $AGRO, 7) === [K1]);
ok('red engeli: farklı sıfat / TC engel yok',
    hks_kaynak_red_engeli([['kunyeNo' => K1]], $km, $AGRO, 11) === [] && hks_kaynak_red_engeli([['kunyeNo' => K1]], $km, $BASKA, 2) === []);
ok('TTL 60 gün: red ve koken düşer', hks_kunye_kaynak_map_temizle($km, '2026-12-05') === []);
ok('TTL içinde korunur', isset(hks_kunye_kaynak_map_temizle($km, '2026-11-29')[K1]['koken']));
ok('bozuk kayıt → boş', hks_kunye_kaynak_map_temizle('x', '2026-10-01') === [] && hks_kunye_kaynak_map_temizle([K1 => ['red' => [$AGRO => ['2' => 'dün']]]], '2026-10-01') === []);
ok('kokenler listesi', hks_kunye_kaynak_kokenler($km) === [K1 => $BASKA]);
$FK = 'f1900000000000';
hks_kunye_kaynak_ekle($FK, [K3], $AGRO, 2, [K3 => $AGRO], '2026-10-10');
ok('kv sarmalayıcı: yaz/oku (kunye_kaynak_<firma>)', isset(hks_kunye_kaynak_oku($FK, '2026-10-11')[K3]['red'][$AGRO]['2']) && strlen('kunye_kaynak_' . $FK) <= 60);
$bay = hks_kunyelere_kaynak_bilgisi([kunye(K3, 'x', 1), kunye(K4, 'x', 1)], hks_kunye_kaynak_oku($FK, '2026-10-11'));
ok('kunyeler ucu: yalnız kaydı olana kaynak alanları', ($bay[0]['kaynakKoken'] ?? '') === $AGRO && !isset($bay[1]['kaynakTc']));

// Kaynak (koken) haritası + TC engeli
$kh = hks_kunye_koken_haritasi([K1 => ['kunyeNo' => K1, 'bildirimciTc' => $BASKA], K2 => ['kunyeNo' => K2, 'bildirimciTc' => '1111111111'],
    K3 => ['kunyeNo' => K3, 'bildirimciTc' => '']], '1111111111', [K4 => $AGRO, K1 => $AGRO]);
ok('koken: detay öğrenilenden öncelikli, kendi VKN ve boş sayılmaz', $kh === [K4 => $AGRO, K1 => $BASKA], json_encode($kh));
$e = hks_kaynak_tc_engeli([['kunyeNo' => K1], ['kunyeNo' => K3], ['kunyeNo' => K4]], $kh, $AGRO);
ok('TC engeli: yalnız kaynağı KESİN ve farklı olan', $e === [['kunyeNo' => K1, 'kaynakTc' => $BASKA]], json_encode($e));
ok('TC engeli: kaynak bilinmiyorsa engel yok', hks_kaynak_tc_engeli([['kunyeNo' => K3]], $kh, $AGRO) === []);
ok('maske', hks_tc_maske($AGRO) === '***0872' && hks_tc_maske('') === '');

// Plan çekirdeği kaynak kipi: fiyatsız sevkte detay istenir, başka kaynaklı künye atlanır
$cagri2 = 0;
$detayK = function (int $ay) use (&$cagri2, $BASKA, $AGRO) { $cagri2++; return ['harita' => [K2 => ['kunyeNo' => K2, 'bildirimciTc' => $BASKA], K3 => ['kunyeNo' => K3, 'bildirimciTc' => $AGRO]]]; };
$stokK = [kunye(K1, '2026-03-01', 400), kunye(K2, '2026-01-01', 300), kunye(K3, '2026-02-01', 200)];
$ortakK = ['planKg' => 500, 'planSorgu' => ['urunId' => 12, 'aySayisi' => 6], 'sifatId' => 9];
$p = hks_plan_kunye_coz_cekirdek($ortakK, fn($s) => $stokK, $detayK, [], ['tc' => $AGRO, 'kendi' => '1111111111', 'ogrenilen' => []]);
ok('kaynak kipi: fiyatsız da detay çağrılır', $cagri2 === 1);
ok('kaynak kipi: başka kaynaklı K2 atlanır → K3, K1', nolar($p['satirlar'] ?? []) === [K3, K1] && $p['atlananKaynakFarkli'] === [K2], json_encode($p));
ok('kaynak kipi: kokenHarita döner', ($p['kokenHarita'][K3] ?? '') === $AGRO);
$p = hks_plan_kunye_coz_cekirdek(['planKg' => 700] + $ortakK, fn($s) => $stokK, $detayK, [], ['tc' => $AGRO, 'kendi' => '', 'ogrenilen' => []]);
ok('kaynak kipi: stok yetersiz notunda başka kişiden atlanan', isset($p['hata']) && strpos($p['hata'], 'başka kişiden geldiği için atlandı') !== false, $p['hata'] ?? '');
$p = hks_plan_kunye_coz_cekirdek($ortakK, fn($s) => $stokK, $patlak, [], ['tc' => $AGRO, 'kendi' => '', 'ogrenilen' => [K1 => $BASKA]]);
ok('kaynak kipi: detay patlarsa öğrenilen koken yine uygulanır', nolar($p['satirlar'] ?? []) === [K2, K3] && $p['atlananKaynakFarkli'] === [K1], json_encode($p));
$cagri2 = 0;
hks_plan_kunye_coz_cekirdek($ortakK, fn($s) => $stokK, $detayK, []);
ok('kaynak kipi kapalı + fiyatsız: detay çağrılmaz (eski davranış)', $cagri2 === 0);

echo "── api.php / .htaccess (statik) ──\n";
$api = (string)file_get_contents("$KOK/halkayit/api.php");
// Yorumsuz kaynak (token_get_all) — çağrı sayımları yorumlardaki adlardan etkilenmesin.
function kod_yorumsuz(string $src): string {
    $o = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) { if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue; $o .= $t[1]; }
        else $o .= $t;
    }
    return $o;
}
$apiKod = kod_yorumsuz($api);
ok('api.php kunye_secim_lib.php\'yi yükler', strpos($api, "require_once __DIR__ . '/kunye_secim_lib.php';") !== false);
ok('hks_plan_kunye_coz çekirdeğe devreder (SOAP enjekte)',
    (bool)preg_match('/function hks_plan_kunye_coz\(\$cfg, \$ortak, \$firmaId = \'\'\).*?hks_plan_kunye_coz_cekirdek\(.*?hks_kunyeleri_getir\(\$cfg.*?hks_kunye_detaylari_getir\(\$cfg/s', $api));
ok('taslak_gonder firma id ile çağırır', strpos($api, '$__plan = hks_plan_kunye_coz($cfg, $ortak, $t[\'firma_id\']);') !== false);
$pKaydet = strpos($api, '$sonuc = hks_bildirim_kaydet($cfg, $satirlar, $ortak, $__secenek);');
$pSifat  = strpos($api, 'hks_kunye_sifat_hatali_satirlar(');
$pTam    = strpos($api, 'if (count($basarili) === 0) {');
ok('sıfat kaydı gönderimden SONRA, tam başarısızlıkta geri koymadan ÖNCE',
    $pKaydet !== false && $pSifat !== false && $pTam !== false && $pKaydet < $pSifat && $pSifat < $pTam);
$blok = substr($api, (int)$pSifat - 600, 1300);
ok('sıfat kaydı referanssızda atlanır + hata yutulur',
    strpos($blok, "empty(\$ortak['referanssiz'])") !== false && strpos($blok, 'catch (Throwable $e)') !== false);
ok('yanıtta sifatUyumsuzKunyeler', strpos($api, "\$sonuc['sifatUyumsuzKunyeler'] = [];") !== false);
ok('gönderim yolu tek: hks_bildirim_kaydet yalnız bir kez çağrılır', preg_match_all('/\\bhks_bildirim_kaydet\\s*\\(/', $apiKod) === 1);
ok('kaynakHatalari yanıtı + 108 öğrenme gönderimden sonra', ($pK = strpos($api, "\$sonuc['kaynakHatalari'] = [];")) !== false && $pK > $pKaydet && $pK < $pTam
    && strpos($api, 'hks_kunye_kaynak_ekle($t[\'firma_id\']') !== false);
$pTcE = strpos($api, 'hks_kaynak_tc_engeli(');
$pRedE = strpos($api, 'hks_kaynak_red_engeli(');
ok('kaynak engelleri gönderimden ÖNCE, taslak geri konarak', $pTcE !== false && $pRedE !== false && $pTcE < $pKaydet && $pRedE < $pKaydet
    && strpos(substr($api, $pTcE, 400), '$taslagiGeriKoy();') !== false && strpos(substr($api, $pRedE, 600), '$taslagiGeriKoy();') !== false);
ok('kunyeler ucu: kaynak bilgisi eklenir', strpos($api, 'hks_kunyelere_kaynak_bilgisi($liste, hks_kunye_kaynak_oku(') !== false);
ok('kunyeler ucu: sifatId varsa bayrak', strpos($api, 'hks_kunyelere_sifat_bayragi($liste, $__sm, $g[\'sifatId\'])') !== false);
$lib = kod_yorumsuz((string)file_get_contents("$KOK/halkayit/kunye_secim_lib.php"));
ok('lib SOAP / gönderim çağırmaz, çıktı basmaz',
    !preg_match('/hks_(soap_cagir|bildirim_kaydet|kunyeleri_getir|kunye_detaylari_getir)\s*\(/', $lib)
    && !preg_match('/\b(echo|print|exit|die)\b/', $lib));
$ht = (string)file_get_contents("$KOK/halkayit/.htaccess");
ok('.htaccess kunye_secim_lib.php\'yi iki sözdiziminde kapatır',
    preg_match_all('/<FilesMatch "\^\([^"]*\bkunye_secim_lib\b[^"]*\)\\\\\.php\$">/', $ht) === 2);

echo $fail === 0 ? "\nTÜM TESTLER GEÇTİ\n" : "\n$fail TEST BAŞARISIZ\n";
exit($fail === 0 ? 0 : 1);
