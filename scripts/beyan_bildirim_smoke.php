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

// bb_* yardimcilari config/helpers.php'ye tasindi (beyan FORMLARI da kullaniyor).
$src = oku("$KOK/config/helpers.php");
$uc  = oku("$KOK/api_beyan_bildirim.php");

// ── 7) Köprü: bildirim gönderme yolu YOK ──────────────────────────────────
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

// ── 9) Ulke ipucu zinciri (Adim 2) ────────────────────────────────────────
// bb_ulke_tahmin saf bir siralayicidir (DB'ye dokunmaz); bagimliligi olan
// bb_tahmin sahte bir surumle degistirilerek SIRALAMA mantigi izole test edilir.
$src2 = $src;
if (preg_match('/^function\s+bb_ulke_tahmin\s*\(.*?^\}/ms', $src2, $m)) {
    // Sahte bb_tahmin: yalniz "rusya" metnini cozer.
    eval('function bb_tahmin(string $tip, string $metin, array $liste): array {
              return hks_eslesme_norm($metin) === "rusya"
                  ? ["id" => "RU", "ad" => "Rusya", "kaynak" => "katalog"]
                  : ["id" => "", "ad" => "", "kaynak" => "yok"]; }');
    eval($m[0]);

    // Ilk cozulen aday kazanmali — plan, alici'dan ONCE denenir.
    $r = bb_ulke_tahmin([
        ['metin' => 'RUSYA',           'kaynak' => 'plan'],
        ['metin' => 'TOLGA KRASNODAR', 'kaynak' => 'alici'],
    ], []);
    ok('ulke: plan adayi oncelikli', $r['id'] === 'RU' && $r['ipucu'] === 'plan',
       'gelen: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

    // Cozulmeyen aday ATLANIR, sonraki denenir.
    $r2 = bb_ulke_tahmin([
        ['metin' => 'BILINMEYEN', 'kaynak' => 'plan'],
        ['metin' => 'Rusya',      'kaynak' => 'gecmis'],
    ], []);
    ok('ulke: cozulmeyen aday atlanip sonrakine gecilir',
       $r2['id'] === 'RU' && $r2['ipucu'] === 'gecmis',
       'gelen: ' . json_encode($r2, JSON_UNESCAPED_UNICODE));

    // Hicbiri cozulmezse BOS doner — ulke ASLA tahmin edilmez.
    $r3 = bb_ulke_tahmin([['metin' => 'ABC', 'kaynak' => 'alici']], []);
    ok('ulke: hicbir ipucu cozulmezse bos doner', $r3['id'] === '',
       'ulke uydurulmus — geri alinamaz bildirimde kabul edilemez');
} else {
    ok('bb_ulke_tahmin kaynakta bulundu', false, 'fonksiyon adi degismis');
}

// Ogrenme BEYAN KAYDEDILIRKEN yapilmali (secim orada) — ve TEK yerde.
foreach (['beyan_create.php', 'beyan_edit.php'] as $ff) {
    $fs = oku("$KOK/$ff");
    // Urun dogrudan, ulke beyan_hks_ulke_ogren() uzerinden (uc yapisal
    // kaynaktan birden: alici + sirket adi + adresin ulke parcasi).
    ok("$ff esleme secimlerini ogreniyor",
       strpos($fs, "hks_eslesme_yaz('urun'") !== false
       && strpos($fs, 'beyan_hks_ulke_ogren(') !== false,
       'form kaydinda ogrenme yok — alanlar bir daha otomatik gelmez');
}
// Ulke ogrenmesi TEK fonksiyonda olmali; formlar kendi anahtar listesini
// tutmamali (aday uretimi ile ogrenme ayni kaynaklari kullansin).
ok('ulke ogrenmesi tek fonksiyonda toplandi',
   strpos($src, 'function beyan_hks_ulke_ogren') !== false
   && strpos($src, 'function bb_adres_ulke_parcasi') !== false,
   'cikarim mantigi kopyalanmis olabilir');
// Serbest metin (raw_text / unmatched_text) ne ADAY uretiminde ne de
// OGRENMEDE kullanilmali — "Yeni Beyan" gibi her beyanda gecen bir satir
// ulke anahtari olsaydi TUM beyanlara yanlis ulke on-dolardi.
$__ulke_govde = '';
foreach (['bb_ulke_adaylari', 'beyan_hks_ulke_ogren', 'bb_adres_ulke_parcasi'] as $fn) {
    if (preg_match('/^function\s+' . $fn . '\s*\(.*?^\}/ms', $src, $mg)) $__ulke_govde .= $mg[0];
}
ok('ulke aday/ogrenme yolu serbest metin taramiyor',
   $__ulke_govde !== ''
   && strpos($__ulke_govde, 'raw_text') === false
   && strpos($__ulke_govde, 'unmatched_text') === false,
   'her beyanda gecen bir satir ulke anahtari olabilir');
ok('ogrenme uc noktada TEKRARLANMIYOR',
   strpos($uc, 'hks_eslesme_yaz(') === false,
   'iki yazici ayni anahtari farkli anlarda ezer');

// ── 10) Kalici eslestirme alanlari (Adim 2b) ─────────────────────────────
ok('uc nokta eslestirmeyi BEYANDAN okuyor, istemciden degil',
   strpos($uc, "\$firmaId = trim((string)\$beyan['hks_firma_id']);") !== false
   && strpos($uc, "\$govde['firmaId']") === false
   && strpos($uc, "\$govde['urunId']") === false
   && strpos($uc, "\$govde['ulkeId']") === false,
   'istemci beyanda gorunenden BASKA bir bildirim olusturabilir');

ok('uc nokta esleme tamligini on kosulda dogruluyor',
   strpos($uc, 'beyan_hks_eslesme_tam($beyan)') !== false,
   'plaka dolu ama eslestirme bossa bildirim kurulabilir');

ok('buton kapisi esleme tamligini ariyor',
   strpos($view, 'beyan_hks_eslesme_tam($beyan)') !== false,
   'beyan_view kapisinda eslestirme kontrolu yok');

// Sifat/tur gövdeden geliyor — katalog disi id REDDEDILMELI.
ok('gövdeden gelen sifat/tur katalogda dogrulaniyor',
   strpos($uc, "\$katalogda(\$katalog['sifatlar'], \$sifatId)") !== false
   && strpos($uc, "\$katalogda(\$katalog['bildirimTurleri'], \$turId)") !== false,
   'liste disi bir tur id elle gonderilebilir (alim turu filtresi atlanir)');

// Yeni kolonlar da hizli durum gecisinde silinmemeli (vehicle_plate ile ayni tuzak).
preg_match("/foreach \(\['raw_text'.*?\] as \\\$hf\)/s", $view, $mh);
foreach (['hks_firma_id', 'hks_urun_id', 'hks_ulke_id'] as $kol) {
    ok("hizli durum gecisi $kol gonderiyor",
       isset($mh[0]) && strpos($mh[0], "'$kol'") !== false,
       'hidden listesinde yok — her durum degisikligi bu alani NULL yapar');
}

// beyan_edit UPDATE tutarliligi (kolon eklendi — sayilar kaymamali)
$ed = oku("$KOK/beyan_edit.php");
// beyan_edit.php'de BIRDEN FAZLA UPDATE var (durum degisikligi yolu ayri);
// tam kaydetme bloğu SON olandir — oradan itibaren bakilir.
$ed_son = substr($ed, (int)strrpos($ed, 'UPDATE customs_declarations SET'));
if (preg_match('/^(.*?)WHERE id = \?"\);\s*\n\s*\$st->execute\(\[(.*?)\n        \]\);/s', $ed_son, $me)) {
    $q   = substr_count($me[1], '?') + 1;   // SET + WHERE
    $par = count(array_filter(array_map('trim', explode("\n", $me[2])), fn($l) => $l !== '' && $l[0] === '$'));
    ok('beyan_edit: ? sayisi = execute parametre sayisi', $q === $par, "? $q vs parametre $par");
} else {
    ok('beyan_edit UPDATE bulundu', false, 'regex eslesmedi — dosya yapisi degismis');
}

// ── 11) Birim fiyat onerileri (Adim 3) ────────────────────────────────────
ok('fiyat onerileri iki kaynaktan uretiliyor',
   strpos($uc, "'kaynak'   => 'gonderilen'") !== false
   && strpos($uc, "'kaynak'   => 'maliyet'") !== false,
   'bb_fiyat_onerileri kaynaklari eksik');

// Maliyet hesabi is-hassas veridir — yetkisiz kullaniciya sizmamali.
ok('maliyet onerisi maliyet.read yetkisiyle kapili',
   strpos($uc, "can('maliyet.read')") !== false,
   'beyan yetkisi olan herkes maliyet satis fiyatini gorebilir');

// EUR fiyati SESSIZCE TL'ye cevrilmemeli: HKS fiyat alaninda para birimi yok
// ve rusum bu sayidan hesaplanir. Cevrim AYRI bir oneri olarak sunulmali.
ok('kur cevrimi ayri oneri olarak sunuluyor',
   strpos($uc, "'kaynak'   => 'maliyet_kur'") !== false,
   'cevrim tek degere gomulmus — hangi birimin dogru oldugu varsayilmis');

// Hicbir oneri alani KENDILIGINDEN doldurmamali.
ok('oneriler yalnizca tiklaninca yaziyor',
   strpos($view, "b.addEventListener('click'") !== false
   && strpos($view, "el('hksFiyat').value = o.deger") !== false,
   'oneri otomatik dolduruluyorsa kullanici rakamin kaynagini gormez');

// Modal ön-seçimi gercekten kullaniyor mu?
ok('modal varsayilan sifat/tur on-secimini uyguluyor',
   strpos($view, 'vs.sifatId') !== false && strpos($view, 'vs.bildirimTuruId') !== false,
   'hazirla yanitindaki varsayilan kullanilmiyor');

ok('modal firma/urun/ulke SECTIRMIYOR (beyandan gelir)',
   strpos($view, "id=\"hksFirma\"") === false && strpos($view, "id=\"hksUrun\"") === false
   && strpos($view, "id=\"hksUlke\"") === false,
   'eslestirme iki yerden girilebilir — beyandaki degerle ayrisir');

// ── 12) Toplu bildirim (Adim 4) ───────────────────────────────────────────
$liste = oku("$KOK/beyanlar.php");

// Toplu akis tekil akisi KOPYALAMAMALI — ikisi de bb_taslak_kur'dan gecmeli.
ok('toplu ve tekil akis ayni kurulum fonksiyonunu kullaniyor',
   substr_count($uc, 'bb_taslak_kur(') === 3,   // tanim + tekil + toplu
   'bulunan cagri sayisi: ' . substr_count($uc, 'bb_taslak_kur('));

// bb_taslak_kur icinde bb_cikti OLMAMALI: toplu akista tek satirin hatasi
// istegin TAMAMINI sonlandirirdi (digerleri hic denenmezdi).
if (preg_match('/^function bb_taslak_kur.*?\n\}/ms', $uc, $mk)) {
    ok('bb_taslak_kur istegi sonlandirmiyor (return kullaniyor)',
       strpos($mk[0], 'bb_cikti(') === false,
       'bir satirin hatasi diger satirlari da durdurur');
} else {
    ok('bb_taslak_kur bulundu', false, 'fonksiyon adi degismis');
}

ok('toplu uc noktalar tanimli',
   strpos($uc, "case 'toplu_hazirla'") !== false && strpos($uc, "case 'toplu_olustur'") !== false);
ok('toplu olusturmada CSRF var',
   preg_match("/case 'toplu_olustur'.*?csrf_check\(/s", $uc) === 1,
   'toplu yazma ucunda csrf_check yok');
ok('toplu istekte ust sinir var', strpos($uc, 'BB_TOPLU_LIMIT') !== false);

// Toplu sonuc satir satir donmeli — sessiz basarisizlik olmamali.
ok('toplu sonuc satir satir donuyor',
   strpos($uc, "'sonuclar' => \$sonuclar") !== false,
   'hangi beyanin neden olmadigi gorunmez');
ok('arayuz basarisiz satirlari gosteriyor',
   strpos($liste, 'Oluşturulamayanlar') !== false,
   'kullanici hangi satirin atlandigini goremez');

// Ayni beyan hem tabloda hem mobil kartta secilebilir — id'ler tekillesmeli.
ok('secili id\'ler tekillestiriliyor',
   strpos($liste, 'function seciliIdler') !== false,
   'ayni beyan icin iki taslak denenebilir');

// Uygunluk kapisi listede de ayni kurallari aramali.
ok('liste uygunluk kapisi buton kapisiyla ayni kurallari kullaniyor',
   strpos($liste, 'beyan_hks_eslesme_tam($r)') !== false
   && strpos($liste, 'beyan_hks_uygun_durumlar()') !== false
   && strpos($liste, "\$r['vehicle_plate']") !== false,
   'listede uygun gorunen bir satir uc noktada reddedilebilir');

// N+1 sorgu tuzagi: aktif baglar TEK sorguda cekilmeli.
ok('aktif baglar tek sorguda cekiliyor',
   strpos($liste, 'WHERE beyan_id IN (' . '$ph)') !== false,
   '50 satirlik sayfada 50 ayri sorgu calisir');

// ── 13) Derin baglanti (Adim 6) ───────────────────────────────────────────
$hkIndex = oku("$KOK/halkayit/index.php");
ok('halkayit/index.php ekran parametresini BEYAZ LISTE ile geciriyor',
   strpos($hkIndex, "in_array((string)(\$_GET['ekran'] ?? ''), ['taslaklar'], true)") !== false,
   'serbest metin hash e yaziliyor olabilir');

$appHtml = oku("$KOK/halkayit/app.html");
ok('SPA bekleyen ekrani firma secildikten SONRA aciyor',
   strpos($appHtml, 'bekleyenEkraniAc()') !== false
   && strpos($appHtml, 'let bekleyenEkran') !== false,
   'taslaklar firma bazli izole — firma secilmeden acilamaz');
ok('bekleyen ekran yalnizca BIR KEZ tuketiliyor',
   preg_match("/function bekleyenEkraniAc.*?bekleyenEkran = '';.*?taslakEkraniAc\(\);/s", $appHtml) === 1,
   'bayrak sifirlanmiyor — her firma degisiminde taslak ekrani acilir');

// ── 14) Ana sayfa sayaci (Adim 5) ─────────────────────────────────────────
$idx = oku("$KOK/index.php");
ok('ana sayfa sayaci buton kapisinin ayni kurallarini kullaniyor',
   strpos($idx, 'beyan_hks_uygun_durumlar()') !== false
   && strpos($idx, "COALESCE(d.hks_urun_id,  '') <> ''") !== false
   && strpos($idx, "b.durum IN ('taslak','gonderildi')") !== false,
   'sayac ile buton kapisi ayrisirsa sayi yaniltir');

// ── 15) On kontrol sayfasi ────────────────────────────────────────────────
$tani = oku("$KOK/beyan_bildirim_tani.php");
ok('on kontrol sayfasi sozdizimsel olarak gecerli', (function () use ($KOK) {
    exec('php -l ' . escapeshellarg("$KOK/beyan_bildirim_tani.php") . ' 2>&1', $o, $rc);
    return $rc === 0;
})());
ok('on kontrol admin ile sinirli',
   strpos($tani, 'is_admin()') !== false && strpos($tani, 'forbidden(') !== false,
   'teshis sayfasi is verisi gosteriyor — admin disina acilmamali');
ok('on kontrol SALT-OKUNUR',
   !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP)\b/i', $tani),
   'teshis sayfasi yazma ifadesi iceriyor');

// Kural tekrari olmamali: sayfa uygulamanin KENDI fonksiyonlarini cagirmali.
foreach (['bb_katalog(', 'bb_varsayilanlar(', 'bb_yurtdisi_isletme_turu(',
          'bb_tahmin(', 'beyan_hks_eslesme_tam(', 'beyan_hks_uygun_durumlar('] as $fn) {
    ok("on kontrol $fn kullaniyor", strpos($tani, $fn) !== false,
       'kural kopyalanmis — teshis ile uygulama ayrisir');
}

// "Yurt Disi" kurali TEK yerde olmali (helpers.php); uc noktada kopyasi kalmamali.
ok('yurt disi kurali tek yerde',
   strpos($src, 'function bb_yurtdisi_isletme_turu') !== false
   && strpos($uc, "strpos(\$n, 'yurt dışı')") === false,
   'kural hem helpers.php hem uc noktada — ayrisir');

// ── 16) Bildirim karti (ust ozet) ─────────────────────────────────────────
ok('bildirim karti var', strpos($view, 'class="beyan-section bk-kart"') !== false);

// Kart sirasi kullanicinin istedigi gibi: Ulke → Urun → Net KG → Plaka.
preg_match_all("/\\\$bk_hucre\('([^']+)'/u", $view, $mc);
ok('kart alan sirasi: Ulke, Urun, Net KG, Plaka',
   ($mc[1] ?? []) === ['Ülke', 'Ürün', 'Net KG', 'Plaka'],
   'gelen sira: ' . implode(' > ', $mc[1] ?? []));

// Net KG bilerek IKI yerde: kartta ve Urun Bilgileri bolumunde.
ok('net kg hem kartta hem urun bilgilerinde',
   substr_count($view, 'fmt_kg($beyan[\'net_kg\'])') >= 2,
   'kopya kaldirilmis — kullanici iki yerde gormek istemisti');

// Ust eylem cubugu SADECE sayfa duzeyi eylemleri tasimali (Beyanlar/Duzenle/Sil).
// Baglama ait butonlar (Bildirim Yap, Yukleme Plani) kendi kartlarinin icinde.
preg_match('/<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">(.*?)\n    <\/div>/s', $view, $mb);
ok('ust eylem cubugu bulundu', isset($mb[1]), 'yapisi degismis — test guncellenmeli');
if (isset($mb[1])) {
    ok('ust cubukta tek birincil buton', substr_count($mb[1], 'btn-primary') === 1,
       'birden fazla birincil eylem — renk enflasyonu');
    ok('ust cubukta baglama ait buton YOK',
       strpos($mb[1], 'hksOpenBtn') === false && strpos($mb[1], 'beyanMatchOpenBtn') === false,
       'buton etkiledigi karttan uzakta — kullanici hangi butonun ne yaptigini bilemez');
    ok('ust cubukta satir ici renk yok',
       strpos($mb[1], 'background:#0ea5e9') === false && strpos($mb[1], 'background:#7c3aed') === false,
       'butonlar hala satir ici renk tasiyor');
}

// Butonlar etkiledikleri bolumun ICINDE olmali.
ok('Bildirim Yap butonu bildirim kartinin icinde',
   preg_match('/class="beyan-section bk-kart".*?id="hksOpenBtn"/s', $view) === 1,
   'buton karttan koptu');
ok('Eslestir butonu yukleme plani bolumunun icinde',
   preg_match('/Yükleme Planı Bağlantısı.*?id="beyanMatchOpenBtn"/s', $view) === 1,
   'buton ilgisiz bir yerde');

// TEK bildirim bolumu olmali — ust kart ile alt bolum ayni veriyi gosteriyordu.
ok('bildirim bolumu sayfada TEK',
   substr_count($view, 'beyan-section-title">🏛 Hal Kayıt Bildirimi') === 1,
   'ayni bilgi iki yerde — kullanici hangisinin gecerli oldugunu bilemez');
ok('HKS urun/ulke alt bolumde TEKRARLANMIYOR',
   strpos($view, '<span class="lbl">HKS Ürünü</span>') === false,
   'ust karttaki alanlar altta tekrar ediyor');

// Pasif buton GORSEL olarak da pasif olmali (global kural).
$css = oku("$KOK/assets/style.css");
ok('.btn:disabled global kurali var',
   strpos($css, '.btn:disabled') !== false && strpos($css, '.btn[disabled]') !== false,
   'pasif buton aktifle ayni gorunur — kullanici tiklar, hicbir sey olmaz');
ok('pasif butonun sebebi ekranda yaziyor',
   strpos($view, 'class="bk-engel"') !== false,
   'sebep yalnizca title ipucunda — mobilde hic gorunmez');

echo "\n" . ($fail === 0 ? "TUM TESTLER GECTI\n" : "$fail TEST BASARISIZ\n");
exit($fail === 0 ? 0 : 1);
