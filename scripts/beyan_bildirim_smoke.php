<?php
// =========================================================
// scripts/beyan_bildirim_smoke.php — Beyan ↔ Hal Kayıt köprüsü yapı testi
// (Sprint Beyan-Bildirim-01)
//
// SADECE CLI. Canlı HKS servisine HİÇBİR AĞ ÇAĞRISI, canlı DB'ye HİÇBİR
// yazma yapmaz. hks_uretici_sevk_test.php ile aynı desen: config.php / api.php
// require EDİLMEZ (onlar gerçek MySQL bağlantısı ister).
//
// NE DOĞRULAR:
//  1) Tüm dokunulan dosyalar sözdizimsel olarak geçerli.
//  2) api.php'den taslak_lib.php'ye TAŞINAN fonksiyonlar kaybolmadı ve
//     ÇİFTLENMEDİ (taşıma sırasında en sık yapılan hata budur).
//  3) api.php'de çağrılan her hks_* fonksiyonu bir yerde tanımlı.
//  4) taslak yazma yolu TEK: hks_taslak_olustur dışında hks_taslaklar'a
//     INSERT eden ikinci bir yer yok.
//  5) beyan_view.php'deki "hızlı durum geçişi" formu vehicle_plate'i hidden
//     olarak gönderiyor (aksi hâlde her durum değişikliği plakayı SİLER).
//  6) beyan_create.php INSERT'ünde kolon / placeholder / parametre sayıları
//     birbirini tutuyor.
//
//   php scripts/beyan_bildirim_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$KOK = dirname(__DIR__);
$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    printf("%-66s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { return (string)@file_get_contents($p); }

// ── 1) Sözdizimi ──────────────────────────────────────────────────────────
$dosyalar = [
    'halkayit/api.php', 'halkayit/taslak_lib.php', 'config/helpers.php',
    'api_beyan_bildirim.php', 'beyan_view.php', 'beyan_edit.php',
    'beyan_create.php', 'beyanlar.php',
];
foreach ($dosyalar as $d) {
    $out = [];
    exec('php -l ' . escapeshellarg($KOK . '/' . $d) . ' 2>&1', $out, $rc);
    ok("sozdizimi: $d", $rc === 0, implode(' ', $out));
}

// ── 2) Taşınan fonksiyonlar: kayıp yok, çift tanım yok ────────────────────
$TASINAN = ['hks_firma_bul', 'hks_uret_sevk_mi', 'hks_bildirim_turu_kodu', 'hks_cep_rakam',
            'hks_sifat_adi', 'hks_tr_normalize', 'hks_uretici_sifat_id', 'hks_liste_ad',
            'hks_bildirim_dogrula'];
$lib = oku("$KOK/halkayit/taslak_lib.php");
$api = oku("$KOK/halkayit/api.php");
foreach ($TASINAN as $fn) {
    $inLib = (bool)preg_match('/^function\s+' . $fn . '\s*\(/m', $lib);
    $inApi = (bool)preg_match('/^function\s+' . $fn . '\s*\(/m', $api);
    ok("tasindi ve tek tanimli: $fn", $inLib && !$inApi,
       $inLib ? 'api.php\'de de tanimli — CIFT TANIM, fatal error' : 'taslak_lib.php\'de KAYIP');
}
ok('api.php taslak_lib.php\'yi require ediyor',
   strpos($api, "require_once __DIR__ . '/taslak_lib.php'") !== false,
   'require satiri yok — tasinan fonksiyonlar bulunamaz');

// ── 3) Tanımsız çağrı yok ─────────────────────────────────────────────────
$tanimli = [];
foreach (glob("$KOK/halkayit/*.php") as $f) {
    preg_match_all('/^function\s+([a-zA-Z0-9_]+)/m', oku($f), $m);
    $tanimli = array_merge($tanimli, $m[1]);
}
$tanimli = array_flip($tanimli);
$eksik = [];
foreach ([$api, $lib] as $src) {
    preg_match_all('/\b(hks_[a-zA-Z0-9_]+)\s*\(/', $src, $m);
    foreach (array_unique($m[1]) as $c) if (!isset($tanimli[$c])) $eksik[] = $c;
}
ok('api.php + taslak_lib.php: tanimsiz hks_* cagrisi yok', empty($eksik),
   'tanimsiz: ' . implode(', ', array_unique($eksik)));

// ── 4) Taslak yazma yolu TEK ──────────────────────────────────────────────
$yazanlar = [];
foreach (array_merge(glob("$KOK/*.php"), glob("$KOK/halkayit/*.php"), glob("$KOK/config/*.php")) as $f) {
    $src = oku($f);
    if (preg_match("/INSERT INTO[^;]*hks_tablo\('taslaklar'\)/", $src) ||
        preg_match('/INSERT INTO\s+`?hks_taslaklar`?/i', $src)) {
        $yazanlar[] = basename(dirname($f)) . '/' . basename($f);
    }
}
// Beklenen: taslak_lib.php (asil yol) + api.php ($taslagiGeriKoy — gonderim
// durursa AYNI taslagi geri yazan telafi yolu; yeni taslak URETMEZ).
sort($yazanlar);
ok('hks_taslaklar\'a yazan dosyalar beklenen ikili',
   $yazanlar === ['halkayit/api.php', 'halkayit/taslak_lib.php'],
   'bulunan: ' . implode(', ', $yazanlar) . ' — ucuncu bir yazma yolu dogrulamayi atlar');

// ── 5) Plaka, durum geçişinde silinmiyor ──────────────────────────────────
$view = oku("$KOK/beyan_view.php");
preg_match("/foreach \(\['raw_text'.*?\] as \\\$hf\)/s", $view, $mm);
ok('beyan_view hizli durum gecisi vehicle_plate gonderiyor',
   isset($mm[0]) && strpos($mm[0], "'vehicle_plate'") !== false,
   'hidden listesinde yok — her durum degisikligi plakayi NULL yapar');

// ── 6) beyan_create INSERT tutarlılığı ────────────────────────────────────
$cre = oku("$KOK/beyan_create.php");
if (preg_match('/INSERT INTO customs_declarations\s*\((.*?)\)\s*VALUES\s*\((.*?)\)"/s', $cre, $m)) {
    $kolon = array_filter(array_map('trim', explode(',', str_replace("\n", ' ', $m[1]))));
    $deger = array_filter(array_map('trim', explode(',', str_replace("\n", ' ', $m[2]))));
    ok('beyan_create: kolon sayisi = deger sayisi',
       count($kolon) === count($deger),
       'kolon ' . count($kolon) . ' vs deger ' . count($deger));
    $soru = count(array_filter($deger, fn($d) => $d === '?'));
    preg_match('/\$st->execute\(\[(.*?)\n        \]\);/s', $cre, $pm);
    $par = count(array_filter(array_map('trim', explode("\n", $pm[1] ?? '')), fn($l) => $l !== '' && $l[0] === '$'));
    ok('beyan_create: ? sayisi = execute parametre sayisi', $soru === $par,
       '? ' . $soru . ' vs parametre ' . $par);
} else {
    ok('beyan_create INSERT bulundu', false, 'regex eslesmedi — dosya yapisi degismis');
}

// ── 7) Köprü: bildirim gönderme yolu YOK ──────────────────────────────────
$uc = oku("$KOK/api_beyan_bildirim.php");
ok('api_beyan_bildirim HKS\'e gonderim yapmiyor',
   strpos($uc, 'hks_bildirim_kaydet') === false && strpos($uc, 'taslak_gonder') === false,
   'Bu uc yalnizca TASLAK yazmalidir — gonderim geri alinamaz ve rusum dogurur');
ok('api_beyan_bildirim CSRF kontrolu yapiyor',
   strpos($uc, 'csrf_check(') !== false, 'yazma ucunda csrf_check yok');
ok('api_beyan_bildirim cift yetki kapisi (beyan.write + records.write)',
   strpos($uc, "can_beyan('write')") !== false && strpos($uc, "can('records.write')") !== false,
   'tek yetkiyle bildirim uretilebilir');

// ── 8) Ön-seçim kuralları (Adım 1) ────────────────────────────────────────
// bb_katalog / bb_varsayilanlar SAF fonksiyonlardır ama api_beyan_bildirim.php
// tepesinde oturum + yetim kontrolu calistirir; dosya require EDILEMEZ.
// Bu yuzden fonksiyon govdesi kaynaktan cikarilip izole degerlendirilir —
// hks_uretici_sevk_test.php'deki "saf fonksiyonu izole calistir" deseni.
$src = oku("$KOK/api_beyan_bildirim.php");
$gov = '';
foreach (['bb_alim_turu_mu', 'bb_varsayilanlar'] as $fn) {
    if (preg_match('/^function\s+' . $fn . '\s*\(.*?^\}/ms', $src, $m)) $gov .= $m[0] . "\n";
}
ok('bb_alim_turu_mu + bb_varsayilanlar kaynakta bulundu', substr_count($gov, 'function ') >= 2,
   'fonksiyon adlari degismis — test guncellenmeli');

if (substr_count($gov, 'function ') >= 2) {
    // hks_eslesme_norm bagimliligi: TR-duyarsiz normalize (config/helpers.php ile ayni)
    if (!function_exists('hks_eslesme_norm')) {
        eval('function hks_eslesme_norm(string $s): string {
                  $s = str_replace(["İ","I"], ["i","ı"], trim($s));
                  return mb_strtolower($s, "UTF-8"); }');
    }
    eval($gov);

    ok('"Satın Alım" alim turu sayiliyor',            bb_alim_turu_mu('Satın Alım'));
    ok('"Üreticiden Sevk Alım" alim turu sayiliyor',  bb_alim_turu_mu('Üreticiden Sevk Alım'));
    ok('"Satış" alim turu SAYILMIYOR',               !bb_alim_turu_mu('Satış'));
    ok('"Sevk Etme" alim turu SAYILMIYOR',           !bb_alim_turu_mu('Sevk Etme'));

    // Gercek katalog sekli: "İhracat" bas harfi İ — mb_strtolower tek basina
    // bunu 'i'ye cevirmez, bu yuzden esleme KACIRILIRDI. Regresyon testi.
    $kat = [
        'sifatlar' => [['id' => '1', 'ad' => 'Üretici'], ['id' => '7', 'ad' => 'İhracat'],
                       ['id' => '9', 'ad' => 'Hal İçi Tüccar']],
        'bildirimTurleri' => [['id' => '3', 'ad' => 'Sevk Etme'], ['id' => '5', 'ad' => 'Satış']],
    ];
    $v = bb_varsayilanlar($kat);
    ok('varsayilan sifat = İhracat (id 7)',    $v['sifatId'] === '7',        'gelen: ' . var_export($v['sifatId'], true));
    ok('varsayilan tur = Satış (id 5)',        $v['bildirimTuruId'] === '5', 'gelen: ' . var_export($v['bildirimTuruId'], true));

    // Katalogda karsiligi yoksa bos donmeli — YANLIS bir id UYDURULMAMALI.
    $bos = bb_varsayilanlar(['sifatlar' => [['id' => '1', 'ad' => 'Üretici']], 'bildirimTurleri' => []]);
    ok('karsiligi yoksa varsayilan bos doner',
       $bos['sifatId'] === '' && $bos['bildirimTuruId'] === '',
       'eslesmeyen katalogda id uydurulmus');
}

// Modal ön-seçimi gercekten kullaniyor mu?
$view = oku("$KOK/beyan_view.php");
ok('modal varsayilan sifat/tur on-secimini uyguluyor',
   strpos($view, 'vs.sifatId') !== false && strpos($view, 'vs.bildirimTuruId') !== false,
   'hazirla yanitindaki varsayilan kullanilmiyor');

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
