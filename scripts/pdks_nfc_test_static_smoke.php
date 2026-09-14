<?php
// =========================================================
// scripts/pdks_nfc_test_static_smoke.php — Web NFC teşhis sayfası statik testi
//
// SADECE CLI. Ağ/DB yok. pdks_nfc_test.php'nin KULLANICI TALEBİNDEKİ
// kritik kısıtları hâlâ karşıladığını regex ile kanıtlar:
//
//   1) Yetki kapısından geçiyor (require_pdks).
//   2) HİÇBİR yazma yolu yok: fetch/XHR/POST/csrf_check YOK — sayfa
//      tamamen istemci tarafında kalıyor, sunucuya HİÇBİR ŞEY göndermiyor.
//   3) NDEF izni YALNIZ buton tıklamasının İÇİNDE isteniyor (sayfa
//      açılışında/otomatik DEĞİL).
//   4) Bayt-tersini OTOMATİK "eşleşme" olarak YAZMIYOR/KAYDETMİYOR —
//      yalnız GÖSTERİYOR (Faz 1 §6a kuralının bu sayfadaki karşılığı).
//   5) Gerekli tüm alanlar ekranda var: serialNumber, ayraçsız gösterim,
//      normalize edilmiş aday, user agent, başarı/hata mesajı.
//
//   php scripts/pdks_nfc_test_static_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);
$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-72s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

$src = (string)@file_get_contents($KOK . '/pdks_nfc_test.php');

echo "\n=== 1. SÖZ DİZİMİ + TEMEL YAPI ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/pdks_nfc_test.php') . ' 2>&1', $cikti, $rc);
ok('php -l geçiyor', $rc === 0, implode("\n", $cikti));
ok('require_login() çağırıyor', str_contains($src, 'require_login()'));
ok('require_pdks(...) ile yetki kapısından geçiyor', (bool)preg_match('/require_pdks\(\s*[\'"]/', $src));

echo "\n=== 2. HİÇBİR YAZMA YOLU YOK ===\n";
ok('fetch(...) çağrısı YOK (sunucuya hiçbir şey göndermiyor)', !preg_match('/\bfetch\s*\(/', $src));
ok('XMLHttpRequest YOK', !str_contains($src, 'XMLHttpRequest'));
ok('csrf_check() YOK (POST yok, gerekmiyor)', !str_contains($src, 'csrf_check('));
ok('<form ...method="post"> YOK', !preg_match('/<form[^>]*method\s*=\s*["\']post/i', $src));
ok('INSERT/UPDATE/DELETE SQL YOK', !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $src));
ok('audit_log_event(...) ÇAĞRILMIYOR (kayıt yazmadığı için gerekmez)', !str_contains($src, 'audit_log_event('));
ok('pdks_kart_olustur/pdks_kart_ata ÇAĞRILMIYOR (kart ataması yapmıyor)',
    !str_contains($src, 'pdks_kart_olustur(') && !str_contains($src, 'pdks_kart_ata('));
ok('"hiçbir kayıt yazmaz" uyarısı EKRANDA açıkça yazıyor', str_contains($src, 'hiçbir kayıt yazmaz'));

echo "\n=== 3. İZİN YALNIZ KULLANICI ETKİLEŞİMİYLE İSTENİYOR ===\n";
// ⚠ Okuma dizisi artık bu dosyada DEĞİL: config/pdks.php → pdks_nfc_oku_js()
// içindeki PAYLAŞILAN yolda (giris_cikis.php de AYNI koddan geçer — bu sayfa
// gerçek cihazda çalışıyordu, o sayfa kendi varyantıyla çalışmıyordu).
// DAVRANIŞ değişmedi; burada hem paylaşılan yol hem de bu sayfanın onu
// YALNIZ tıklama içinden çağırdığı doğrulanır.
$pdksSrc = (string)@file_get_contents($KOK . '/config/pdks.php');
$helperJs = '';
if (preg_match("/echo <<<'JS'\n(.*?)\nJS;/s", $pdksSrc, $hm)) $helperJs = $hm[1];
ok('paylaşılan okuma yolu (pdks_nfc_oku_js) bulundu', $helperJs !== '');
ok('bu sayfa paylaşılan yolu basıyor', str_contains($src, 'pdks_nfc_oku_js()'));
ok('sayfa KENDİ new NDEFReader()ını taşımıyor (tek kopya paylaşılan yolda)',
    !preg_match('/^(?!\s*\/\/).*new NDEFReader\(\)/m', $src));
ok('new NDEFReader() paylaşılan yolda, scan() ile aynı fonksiyonda',
    str_contains($helperJs, 'new NDEFReader()') && str_contains($helperJs, '.scan()'));
ok('okuma sayfa açılışında DEĞİL, tıklama işleyicisinin İÇİNDE başlatılıyor',
    (bool)preg_match('/startBtn\.addEventListener\(.click.[\s\S]{0,900}PdksNfcOku\.baslat\(/', $src));
ok('scan() otomatik çalışmıyor — paylaşılan yolda zamanlayıcı/otomatik tetik YOK',
    !preg_match('/setTimeout|setInterval|visibilitychange/', $helperJs));
ok('scan() ARGÜMANSIZ (AbortController/signal YOK — canlı hatanın kaynağıydı)',
    str_contains($helperJs, '.scan().then(') && !preg_match('/\.scan\(\s*\{/', $helperJs)
    && !str_contains($helperJs, 'AbortController'));

echo "\n=== 4. FAZ 1 §6a KURALI BU SAYFADA DA KORUNUYOR ===\n";
ok('"otomatik alias DEĞİL" notu ekranda açık',
    str_contains($src, 'otomatik alias DEĞİL') || str_contains($src, 'otomatik eşleşme YOK'));
ok('ters gösterim yalnız GÖSTERİLİYOR, employee_card_uids\'e YAZILMIYOR',
    !str_contains($src, 'employee_card_uids'));
ok('"YALNIZ GÖSTERİM" ilkesi kaynak yorumunda açık', str_contains($src, 'YALNIZ GÖSTERİM'));

echo "\n=== 5. İSTENEN EKRAN ALANLARININ HEPSİ VAR ===\n";
$beklenen = [
    'serialNumber (ham)'         => 'event.serialNumber',
    'Ayraçsız gösterim'          => 'rStripped',
    'Normalize edilmiş aday'     => 'rSame',
    'User-Agent'                 => 'navigator.userAgent',
    'NDEFReader destek göstergesi' => "'NDEFReader' in window",   // ortam tablosu — okuma yolu DEĞİL
    'Güvenli bağlam göstergesi'  => 'isSecureContext',
    'Başlat butonu (Türkçe metin)' => 'NFC OKUMAYI BAŞLAT',
    'Hata adı/mesajı alanı'      => 'rErrName',
    'readingerror dinleyicisi'   => 'readingerror',
];
foreach ($beklenen as $ad => $ipucu) {
    ok("$ad var", str_contains($src, $ipucu));
}

echo "\n=== 6. BİLİNEN TEST KARTI REFERANSI DOĞRU ===\n";
ok('631799511 gösteriliyor', str_contains($src, '631799511'));
ok('25A87ED7 (kanonik) gösteriliyor', str_contains($src, '25A87ED7'));
ok('D77EA825 (ters, yalnız referans) gösteriliyor', str_contains($src, 'D77EA825'));

echo "\n=== 7. NAVİGASYON — personel_kartlar.php\'den erişilebilir ===\n";
$kartlarSrc = (string)@file_get_contents($KOK . '/personel_kartlar.php');
ok('personel_kartlar.php → pdks_nfc_test.php\'ye link veriyor', str_contains($kartlarSrc, 'pdks_nfc_test.php'));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
