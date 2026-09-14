<?php
// =========================================================
// scripts/pdks_giris_cikis_static_smoke.php — Giriş/Çıkış sayfası statik testi
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar
// (pdks_faz1b_static_smoke.php / beyan_bildirim_smoke.php ile aynı desen).
// Kanıtlanan kurallar:
//
//  1) Yetki kapısından geçiyor (require_pdks('scan')) — sayfa girişinde VE
//     POST/ajax dalında TEKRAR (savunma derinliği).
//  2) Kayıt ucu csrf_check() çağırıyor.
//  3) YÖN (GİRİŞ/ÇIKIŞ) istemcide OTOMATİK seçilmiyor — kullanıcı AÇIKÇA
//     seçmeden currentMode boştur, "son olay"/otomatik tahmin mantığı YOK.
//  4) İstemci hiçbir KİMLİK iddiasında bulunmuyor — yalnız ham_uid/kaynak/
//     event_type gönderiyor; employee_id/card_id İSTEMCİDEN GELMİYOR.
//  5) Bayt-tersi dönüşümü İSTEMCİDE YAPILMIYOR — kanonikleştirme TEK
//     OTORİTE (sunucu, pdks_devam_kaydet() → pdks_kart_cozumle()).
//  6) Cihaz/Android/token/heartbeat/offline-kuyruk/vardiya/bordro YOK.
//  7) Mükerrer okuma/çift-tıklama koruması sunucuda (pdks_devam_kaydet())
//     uygulanıyor — sayfa kendi mükerrer kontrolünü İCAT ETMİYOR.
//
//   php scripts/pdks_giris_cikis_static_smoke.php   → çıkış kodu 0 = geçti
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
    printf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku2(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }

$src = oku2('giris_cikis.php');
$pdksSrc = oku2('config/pdks.php');
// Yorum satırları (// ...) çıkarılmış hâli — bu dosyanın kendi AÇIKLAYICI
// yorumları (ör. "otomatik tahmin EDİLMEZ", "heartbeat ... YOK") aşağıdaki
// "yasaklı kelime" regex'lerini YANLIŞ POZİTİF olarak tetiklemesin diye.
$srcKod = preg_replace('/^\s*\/\/.*$/m', '', $src);

echo "\n=== 1. SÖZ DİZİMİ + TEMEL YAPI ===\n";
$cikti = []; $rc = 0;
exec('php -l ' . escapeshellarg($KOK . '/giris_cikis.php') . ' 2>&1', $cikti, $rc);
ok('php -l geçiyor', $rc === 0, implode("\n", $cikti));
ok('require_login() çağırıyor', str_contains($src, 'require_login()'));
ok("require_pdks('scan') sayfa girişinde", (bool)preg_match('/require_pdks\(\s*[\'"]scan[\'"]\s*\)/', $src));
ok("require_pdks('scan') POST/ajax dalında TEKRAR var (savunma derinliği)",
    substr_count($src, "require_pdks('scan')") >= 2);

echo "\n=== 2. KAYIT UCU GÜVENLİĞİ ===\n";
ok('csrf_check() çağırıyor', str_contains($src, 'csrf_check('));
ok('JSON gövdesi php://input üzerinden okunuyor (form değil)', str_contains($src, 'php://input'));
ok('ajax=kaydet POST dalı var', str_contains($src, "'ajax'] ?? '') === 'kaydet'"));

echo "\n=== 3. YÖN OTOMATİK SEÇİLMİYOR — kullanıcı AÇIKÇA seçiyor ===\n";
ok("currentMode başlangıçta null (otomatik seçim YOK)", (bool)preg_match('/currentMode\s*=\s*null/', $src));
ok('mod seçim butonları var (data-gc-mode)', substr_count($src, 'data-gc-mode') >= 2);
ok('GİRİŞ modu buton metni var', str_contains($src, 'GİRİŞ MODU'));
ok('ÇIKIŞ modu buton metni var', str_contains($src, 'ÇIKIŞ MODU'));
ok('"son okuma"/"last event"den otomatik yön tahmini YOK (yorumlar hariç GERÇEK KOD)',
    !preg_match('/(son\s+olay|last\s*event|previous\s*event|otomatik\s+yön)/i', $srcKod));
ok('sunucu tarafında da otomatik yön tahmini YOK (event_type İSTEMCİDEN gelir)',
    !preg_match('/ORDER BY.*server_event_time.*DESC.*LIMIT 1.*event_type\s*=/is', $src));

echo "\n=== 4. İSTEMCİ HİÇBİR KİMLİK İDDİASINDA BULUNMUYOR ===\n";
ok('employee_id İSTEMCİDEN gönderilmiyor', !preg_match('/JSON\.stringify\([^)]*employee_id/', $src));
ok('card_id İSTEMCİDEN gönderilmiyor',     !preg_match('/JSON\.stringify\([^)]*card_id/', $src));
ok('fetch gövdesi yalnız ham_uid/kaynak/event_type/csrf taşıyor',
    (bool)preg_match('/body:\s*JSON\.stringify\(\{\s*csrf:\s*csrf,\s*ham_uid:\s*deger,\s*kaynak:\s*kaynak,\s*event_type:\s*currentMode\s*\}\)/', $src));
ok('sunucu tarafı kart/personel kimliğini KENDİSİ çözüyor (pdks_kart_cozumle üzerinden pdks_devam_kaydet)',
    str_contains($src, 'pdks_devam_kaydet('));

echo "\n=== 5. BAYT-TERSİ DÖNÜŞÜMÜ İSTEMCİDE YAPILMIYOR (TEK OTORİTE SUNUCUDA) ===\n";
// pdks_nfc_test.php TEŞHİS sayfasında (yalnız GÖSTERİM amaçlı) ters çevirme
// vardır — giris_cikis.php ÜRETİM sayfasında AYNI şey OLMAMALI, çünkü orada
// gösterilen değer hiçbir zaman geri yazılmaz. Burada BAYT-TERSİ hesaplayan
// bir döngü/algoritma ARANMAMALI — yalnız ham serialNumber okunup kaynak
// etiketiyle birlikte SUNUCUYA gönderiliyor olmalı.
ok('serialNumber ham hâliyle gönderiliyor (ayraç/harf temizliği bile YOK)',
    (bool)preg_match('/ev\.serialNumber/', $src));
ok('JS içinde bayt-tersi çeviren bir döngü YOK (substr(i,2) tersleme kalıbı)',
    !preg_match('/for\s*\([^)]*i\s*-=\s*2[^)]*\)[\s\S]{0,80}substr/i', $src));
ok("kaynak='web_nfc' etiketiyle gönderiliyor (kanonikleştirme SUNUCUDA)",
    str_contains($src, "'web_nfc'"));

echo "\n=== 6. AŞIRI MÜHENDİSLİK YASAKLARI — cihaz/token/heartbeat/vardiya/bordro YOK ===\n";
$yasakli = [
    'device_token'   => 'cihaz token modeli',
    'heartbeat'      => 'heartbeat',
    'offline_queue'  => 'offline kuyruk',
    'shift_engine'   => 'vardiya motoru',
    'payroll'        => 'bordro',
    'MainActivity'   => 'Android üretim kodu',
];
foreach ($yasakli as $desen => $ad) {
    ok("$ad YOK (yorumlar hariç GERÇEK KOD)", stripos($srcKod, $desen) === false);
}
ok('Ayrı bir API/token doğrulaması YOK — mevcut Nuverna oturumu (require_login) kullanılıyor',
    !preg_match('/api_key|bearer\s+token|X-Api-Key/i', $srcKod));

echo "\n=== 7. MÜKERRER OKUMA KORUMASI SUNUCUDA (sayfa kendi kuralını İCAT ETMİYOR) ===\n";
ok("PDKS_COOLDOWN_SN sabiti giris_cikis.php'de TEKRARLANMIYOR (tek otorite config/pdks.php)",
    !preg_match('/define\(\s*[\'"]PDKS_COOLDOWN_SN|PDKS_COOLDOWN_SN\s*=\s*\d/', $src));
ok('pdks_devam_kaydet() içinde 20sn mükerrer kontrolü var (PDKS_COOLDOWN_SN kullanılıyor)',
    (bool)preg_match('/function pdks_devam_kaydet.*?PDKS_COOLDOWN_SN/s', $pdksSrc));
ok('"busy" bayrağıyla istemci tarafı çift-gönderim engeli de var (ek katman)',
    str_contains($src, 'busy'));

echo "\n=== 8. GÖRSEL/UX GEREKLERİ — başarı/hata ekranları, alanlar ===\n";
foreach ([
    'GİRİŞ KAYDEDİLDİ', 'ÇIKIŞ KAYDEDİLDİ', 'full_name', 'personnel_no', 'department', 'photo_html',
] as $beklenen) {
    ok("'$beklenen' sayfada geçiyor", str_contains($src, $beklenen));
}

echo "\n=== 9. NAVİGASYON — sidebar'da bağlantı var, teşhis sayfası birincil DEĞİL ===\n";
$helpersSrc = oku2('config/helpers.php');
ok("config/helpers.php sidebar'da giris_cikis.php linki var", str_contains($helpersSrc, "giris_cikis.php"));
ok("attendance.scan yetkisiyle gösteriliyor", (bool)preg_match(
    "/attendance\\.scan[^\\n]*giris_cikis\\.php|giris_cikis\\.php[\\s\\S]{0,10}\\/\\/[\\s\\S]{0,10}$/m", $helpersSrc
) || str_contains($helpersSrc, "can('attendance.scan')"));
ok('pdks_nfc_test.php sidebar/ana navigasyona EKLENMEDİ (yalnız kart yönetimi sayfasında ikincil link kalır)',
    !preg_match('/pdks_nfc_test\.php/', $helpersSrc));

echo "\n=== 10. WEB NFC — TEK PAYLAŞILAN OKUMA YOLU (pdks_nfc_oku_js) ===\n";
// ⚠ CANLI HATA: giris_cikis.php KENDİ Web NFC varyantını taşıyordu
// (AbortController + scan({signal}) + her okumadan sonra ac.abort() + scan()
// çözülmeden butonu pasifleştirme). Teşhis sayfası (pdks_nfc_test.php) GERÇEK
// telefonda kartı okuyup serialNumber=d7:7e:a8:25 döndürürken bu sayfa AYNI
// telefonda HİÇ okumuyordu. DÜZELTME: okuma dizisi TEK bir yere alındı
// (config/pdks.php → pdks_nfc_oku_js) ve İKİ SAYFA DA oradan geçiyor.
//
// Bu bölüm "iki dosyada da NDEFReader geçiyor mu" DEMEZ — dizinin kendisini,
// ADIM ADIM ve SIRAYLA doğrular.
$nfcTestSrc = oku2('pdks_nfc_test.php');
$pdksKodSrc = oku2('config/pdks.php');
$nfcTestKod = preg_replace('/^\s*\/\/.*$/m', '', $nfcTestSrc);

// Paylaşılan JS gövdesi — YALNIZ nowdoc'un İÇİ. (Üstündeki PHP docblock'unda
// "AbortController" kelimesi YASAK NOTU olarak geçer; kod olarak değil.)
$helperJs = '';
if (preg_match("/echo <<<'JS'\n(.*?)\nJS;/s", $pdksKodSrc, $hm)) $helperJs = $hm[1];
ok('config/pdks.php pdks_nfc_oku_js() fonksiyonunu tanımlıyor',
    (bool)preg_match('/function pdks_nfc_oku_js\(\)/', $pdksKodSrc));
ok('Paylaşılan JS gövdesi çıkarılabildi', $helperJs !== '', 'nowdoc bulunamadı');

echo "\n--- 10a. KANITLANMIŞ DİZİ, ADIM ADIM VE SIRAYLA ---\n";
$adimlar = [
    'new NDEFReader()'                    => 'new NDEFReader()',
    "'reading' dinleyicisi"               => "addEventListener('reading'",
    "'readingerror' dinleyicisi"          => "addEventListener('readingerror'",
    'scan() çağrısı'                      => '.scan()',
    '.then( — başlatıldı geri çağrısı'    => '.then(',
    '.catch( — reddedildi geri çağrısı'   => '.catch(',
];
$oncekiPoz = -1; $siraOk = true; $bozulan = '';
foreach ($adimlar as $ad => $ipucu) {
    $poz = strpos($helperJs, $ipucu);
    ok("dizi adımı VAR: $ad", $poz !== false);
    if ($poz === false) { $siraOk = false; continue; }
    if ($poz < $oncekiPoz) { $siraOk = false; $bozulan = $ad; }
    $oncekiPoz = $poz;
}
ok('ALTI ADIM DA BU SIRADA: NDEFReader → reading → readingerror → scan() → then → catch',
    $siraOk, "sıra bozulduğu adım: $bozulan");
ok('scan() ARGÜMANSIZ çağrılıyor (teşhis sayfasıyla birebir) — scan({...}) YOK',
    (bool)preg_match('/ndef\.scan\(\)\s*\.then\(/', $helperJs) && !preg_match('/\.scan\(\s*\{/', $helperJs));
ok('baslat() yalnız kullanıcı hareketinden çağrılacak biçimde SAF — kendi içinde zamanlayıcı/otomatik tetik YOK',
    !preg_match('/setTimeout|setInterval|addEventListener\(\s*.visibilitychange/', $helperJs));

echo "\n--- 10b. KALDIRILAN BOZUK MANTIK GERİ GELMEDİ ---\n";
$yasakNfc = [
    'AbortController'    => 'new AbortController()',
    'scan({signal:...})' => 'signal:',
    'abort() çağrısı'    => '.abort()',
];
foreach ($yasakNfc as $ad => $desen) {
    ok("Paylaşılan yolda $ad YOK (canlı hatanın kaynağıydı)", !str_contains($helperJs, $desen));
    ok("giris_cikis.php GERÇEK KODUNDA $ad YOK", !str_contains($srcKod, $desen));
    ok("pdks_nfc_test.php GERÇEK KODUNDA $ad YOK", !str_contains($nfcTestKod, $desen));
}
ok('OTOMATİK yeniden silahlanma YOK (nfcBaslat / nfcButonuSifirla kaldırıldı)',
    !preg_match('/function\s+nfcBaslat\s*\(/', $srcKod) && !str_contains($srcKod, 'nfcButonuSifirla('));
ok('visibilitychange tabanlı sessiz yeniden-scan() YOK', !str_contains($srcKod, 'visibilitychange'));
ok('kaydet() artık NFC oturumuna HİÇ DOKUNMUYOR (imzasında nfcOkumasiMi parametresi YOK)',
    (bool)preg_match('/function kaydet\(hamUid,\s*kaynak\)\s*\{/', $srcKod)
    && !str_contains($srcKod, 'nfcOkumasiMi'));

echo "\n--- 10c. TEK UYGULAMA — iki sayfa da AYNI koddan geçiyor ---\n";
ok('giris_cikis.php KENDİ `new NDEFReader()`ını TAŞIMIYOR', !str_contains($srcKod, 'new NDEFReader()'));
ok('pdks_nfc_test.php KENDİ `new NDEFReader()`ını TAŞIMIYOR', !str_contains($nfcTestKod, 'new NDEFReader()'));
ok('giris_cikis.php paylaşılan yolu sayfaya basıyor (pdks_nfc_oku_js)', str_contains($src, 'pdks_nfc_oku_js()'));
ok('pdks_nfc_test.php paylaşılan yolu sayfaya basıyor (pdks_nfc_oku_js)', str_contains($nfcTestSrc, 'pdks_nfc_oku_js()'));
ok('giris_cikis.php okumayı PdksNfcOku.baslat() ile başlatıyor', str_contains($srcKod, 'PdksNfcOku.baslat('));
ok('pdks_nfc_test.php okumayı PdksNfcOku.baslat() ile başlatıyor', str_contains($nfcTestKod, 'PdksNfcOku.baslat('));
ok('giris_cikis.php: baslat() BUTON TIKLAMASININ İÇİNDE (transient activation)',
    (bool)preg_match("/nfcBtn\.addEventListener\('click'[\s\S]{0,700}PdksNfcOku\.baslat\(/", $srcKod));
ok('pdks_nfc_test.php: baslat() BUTON TIKLAMASININ İÇİNDE (transient activation)',
    (bool)preg_match("/startBtn\.addEventListener\('click'[\s\S]{0,700}PdksNfcOku\.baslat\(/", $nfcTestKod));
ok('Destek kontrolü de paylaşılan yoldan (PdksNfcOku.destekli)', str_contains($srcKod, 'PdksNfcOku.destekli()'));
// ⚠ BİLİNEN ÜÇÜNCÜ KOPYA: assets/pdks.js — KART KAYDETME akışı
// (personel_kartlar.php / personel_form.php). Bu görevin kapsamı DIŞINDA
// bilerek bırakıldı (kullanıcı: "özellik ekleme", "çalışan akışa dokunma");
// giriş/çıkış hatasıyla ilgisi yok, ayrı sayfalarda yaşıyor ve SW ön-belleğine
// giren harici bir varlık. Burada SABİTLENİYOR ki unutulmasın — birleştirilirse
// bu satır güncellenmeli.
ok('assets/pdks.js kart-kaydetme NFC kopyası hâlâ AYRI (bilinçli, kapsam dışı)',
    str_contains(oku2('assets/pdks.js'), 'new NDEFReader()'));

echo "\n--- 10d. TEK ADAPTASYON: serialNumber → kaydet(web_nfc, seçili mod) ---\n";
ok('serialNumber YALNIZ okuma geri çağrısında ve HAM hâliyle alınıyor',
    (bool)preg_match('/onOkuma:\s*function\s*\(ev\)\s*\{[\s\S]{0,300}ev\.serialNumber/', $srcKod));
ok("Adaptasyon: ham serialNumber → kaydet(ham, 'web_nfc') — başka dönüşüm YOK",
    (bool)preg_match("/onOkuma:[\s\S]{0,400}kaydet\(ham,\s*'web_nfc'\)/", $srcKod));
ok('Boş serialNumber sunucuya GÖNDERİLMEZ', (bool)preg_match("/if\s*\(ham\s*!==\s*''\)\s*kaydet\(/", $srcKod));
ok("Başlangıç NFC buton etiketi 'NFC İLE KART OKU'", str_contains($src, 'NFC İLE KART OKU'));
ok("Dinleme etiketi 'NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN'", str_contains($src, 'NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN'));
ok('scan() reddi (.catch) HER ZAMAN açık Türkçe hata gösterir (sessiz mod YOK)',
    str_contains($src, 'NFC başlatılamadı. NFC İLE KART OKU butonuna tekrar dokunun.'));

echo "\n--- 10e. Teşhis panosu (geçici) — istenen BEŞ adım ---\n";
ok('#gcNfcDebug paneli sayfada var', str_contains($src, 'id="gcNfcDebug"'));
foreach ([
    'button clicked'    => 'button clicked',
    'scan started'      => 'scan started',
    'reading received'  => 'reading received',
    'serialNumber'      => 'serialNumber=',
    'backend response'  => 'backend response:',
] as $ad => $ipucu) {
    ok("panoya '$ad' yazılıyor", str_contains($srcKod, $ipucu));
}
ok('NFC support / secure context durumu panoya yazılıyor', str_contains($srcKod, 'secure context'));
ok('scan() reddi err.name / err.message panoya yazılıyor', (bool)preg_match("/scan\(\) rejected: ' \+ ad \+ ' — ' \+ msj/", $srcKod));
// Not: serialNumber/UID panoya YAZILIR (kullanıcının açık isteği). Yasak olan
// yalnız kimlik doğrulama/oturum bilgisidir (csrf token, personel adı).
ok('Panoya CSRF token veya personel adı YAZILMIYOR', !preg_match('/nfcDebugYaz\([^)]*(csrf|full_name)/i', $srcKod));

echo "\n--- 10f. Mod değişimi NFC oturumuna karışmıyor ---\n";
ok('NFC oturum durumu modül seviyesinde paylaşılan bir okuyucu nesnesi DEĞİL',
    !preg_match('/var\s+ndefOkuyucu\b/', $srcKod) && !preg_match('/var\s+ndef\s*=/', $srcKod));
ok('Mod değişimi (girModuSec/gcModeChange) NFC koduna hiç DOKUNMUYOR',
    !preg_match('/function girModuSec[\s\S]{0,500}(NDEFReader|PdksNfcOku)/', $srcKod)
    && !preg_match("/gcModeChange'\)\.addEventListener\('click'[\s\S]{0,500}(NDEFReader|PdksNfcOku)/", $srcKod));

echo "\n=== 11. MOBİL DÜZEN — üst başlık gizleme, hidden-attribute tuzağına DÜŞMEDİ ===\n";
ok('.page-head gizlemek için style.display kullanılıyor (hidden ÖZNİTELİĞİ DEĞİL — .page-head display:flex taşır ve onu ezer)',
    str_contains($src, "pageHead.style.display = 'none'") && str_contains($src, "pageHead.style.display = ''"));
ok('.page-head İÇİN "hidden = true/false" YAZILMADI (bilinen [hidden] tuzağı — bkz. CLAUDE.md maliyet.css notu)',
    !preg_match('/pageHead\.hidden\s*=/', $srcKod));
ok('#gcPageHead işaretlemesi sayfada var', str_contains($src, 'id="gcPageHead"'));

$pdksCss = oku2('assets/pdks.css');
ok('.pdks-kiosk-scan dvh (dinamik viewport) kullanıyor — mobil tarayıcı adres çubuğu taşmasına karşı',
    str_contains($pdksCss, 'min-height: 60dvh'));
ok('Mobil media query bottomnav/üst boşluk payı düşülmüş calc(100dvh - ...) kullanıyor',
    (bool)preg_match('/calc\(100dvh\s*-\s*\d+px\)/', $pdksCss));
ok('.pdks-kiosk-nfc-armed sınıfı CSS\'te tanımlı', str_contains($pdksCss, '.pdks-kiosk-nfc-armed'));
// Oturum, teşhis sayfasındaki gibi AÇIK KALIR (okuma sonrası abort/sıfırlama
// YOK) — dolayısıyla "dinlemede" görseli de kalıcıdır. Sınıf yalnız EKLENİR;
// bir remove() geri gelirse oturum kapatılıyor demektir, canlı hata da oydu.
ok('JS, dinlemeye geçince .pdks-kiosk-nfc-armed EKLİYOR',
    str_contains($srcKod, "classList.add('pdks-kiosk-nfc-armed')"));
ok('Sınıf KALDIRILMIYOR — oturum açık kaldığı için "dinlemede" görseli kalıcı',
    !str_contains($srcKod, "classList.remove('pdks-kiosk-nfc-armed')"));

echo "\n=== 12. CANLI HATA: [hidden] TUZAĞI — şeffaf katman NFC dokunuşunu yutuyordu ===\n";
// KÖK NEDEN: `[hidden]` tarayıcının UA kuralıdır ve önceliği EN DÜŞÜKTÜR.
// .pdks-kiosk-result (position:absolute; inset:0; z-index:10; display:flex)
// kendi `display` değeriyle onu EZİYOR → #gcResult `hidden` olmasına rağmen
// çiziliyor, şeffaf ama tüm tarama alanını kaplayan bir katman olarak
// "NFC İLE KART OKU" butonunun dokunuşlarını YUTUYORDU. USB etkilenmedi
// (klavye girdisi işaretçi olayı gerektirmez) — bu yüzden masaüstü çalışıp
// mobil NFC ölüydü. hesap.css ve maliyet.css bu korumayı zaten taşıyordu;
// pdks.css'te YOKTU.
ok('assets/pdks.css [hidden] korumasını TAŞIYOR (hesap.css/maliyet.css ile aynı)',
    (bool)preg_match('/\[hidden\]\s*\{\s*display:\s*none\s*!important/', $pdksCss));
ok('hesap.css bu korumayı zaten taşıyor (emsal doğrulaması)',
    (bool)preg_match('/\[hidden\]\s*\{\s*display:\s*none\s*!important/', oku2('assets/hesap.css')));
ok('maliyet.css bu korumayı zaten taşıyor (emsal doğrulaması)',
    (bool)preg_match('/\[hidden\]\s*\{\s*display:\s*none\s*!important/', oku2('assets/maliyet.css')));
ok('Sonuç katmanı hâlâ position:absolute + z-index (koruma olmadan üstü kaplardı)',
    (bool)preg_match('/\.pdks-kiosk-result\s*\{[^}]*position:\s*absolute[^}]*z-index/s', $pdksCss));

echo "\n=== 13. NFC BUTON DEĞİŞMEZ KURALI — destek varsa MUTLAKA tıklanabilir ===\n";
ok('Destekleniyorsa buton AÇIKÇA etkinleştiriliyor (nfcBtn.disabled = false)',
    (bool)preg_match('/if\s*\(PdksNfcOku\.destekli\(\)\)\s*\{[\s\S]{0,600}nfcBtn\.disabled\s*=\s*false/', $srcKod));
ok('Desteklenmiyorsa buton AÇIKÇA pasifleştiriliyor (tek geçerli pasiflik sebebi)',
    (bool)preg_match('/\}\s*else\s*\{[\s\S]{0,300}nfcBtn\.disabled\s*=\s*true/', $srcKod));
ok('Başlangıç HTML\'inde `disabled` özniteliği YOK (JS karar versin)',
    !preg_match('/id="gcNfcBtn"[^>]*\sdisabled/', $src));
// Yalnız İKİ meşru pasifleştirme vardır: (a) tıklama işleyicisinde geçici,
// (b) desteklenmeyen ortam dalında kalıcı. Üçüncü bir yer çıkarsa bu test düşer.
ok('Butonu pasifleştiren YALNIZ 2 meşru yer var (tıklama anı + desteksiz ortam)',
    substr_count($srcKod, 'nfcBtn.disabled = true') === 2);
ok('GEÇERSİZ DURUM YOK: "support: evet" yazılıp buton pasif bırakılan bir kod yolu yok — '
 . 'etkinleştirme ve debug satırı AYNI blokta',
    (bool)preg_match('/nfcBtn\.disabled\s*=\s*false;\s*nfcDebugYaz\(\s*[\'"]button: enabled/', $srcKod));
ok('Mod seçimi (girModuSec) NFC butonuna DOKUNMUYOR (mod seçmek NFC\'yi pasifleştirmez)',
    !preg_match('/function girModuSec[\s\S]{0,600}nfcBtn/', $srcKod));
// Teşhis sayfasının kanıtlanmış davranışı: buton scan() ÇÖZÜLENE KADAR
// pasifleştirilmez. Eski sürüm tıklama anında pasifleştiriyordu; scan()
// reddedilirse kullanıcı bir daha dokunamıyordu.
ok('Buton YALNIZ scan() çözüldükten sonra (onBasladi) pasifleşiyor — tıklama anında DEĞİL',
    (bool)preg_match('/onBasladi:\s*function\s*\(\)\s*\{[\s\S]{0,300}nfcBtn\.disabled\s*=\s*true/', $srcKod)
    && !preg_match("/nfcBtn\.addEventListener\('click',\s*function\s*\(\)\s*\{[^}]{0,300}nfcBtn\.disabled\s*=\s*true/", $srcKod));
ok('Tekrar-giriş koruması teşhis sayfasıyla aynı desende (dinlemedeyken çık)',
    (bool)preg_match('/if\s*\(nfcDinlemede\)\s*return;/', $srcKod));
// Yalnız işleyicinin KENDİ gövdesine bak (ilk `});`e kadar) — sabit karakter
// penceresi sonraki dinleyiciye taşıp yanlış pozitif veriyordu.
$modeChangeGovde = '';
if (preg_match("/gcModeChange'\)\.addEventListener\('click',\s*function\s*\(\)\s*\{(.*?)\n\s*\}\);/s", $srcKod, $mcM)) {
    $modeChangeGovde = $mcM[1];
}
ok('Modu Değiştir işleyicisinin GÖVDESİ NFC butonuna DOKUNMUYOR',
    $modeChangeGovde !== '' && !str_contains($modeChangeGovde, 'nfcBtn'), 'gövde bulunamadı veya nfcBtn geçiyor');

echo "\n=== 14. MOBİL KLAVYE — USB kutusu artık yazılım klavyesini çağırmıyor ===\n";
ok('USB kutusu inputmode="none" (USB HID fiziksel klavyedir, yazmaya devam eder)',
    (bool)preg_match('/id="gcScanInput"[\s\S]{0,200}inputmode="none"/', $src));
ok('inputmode="numeric" KALDIRILDI (yazılım klavyesini davet ediyordu)',
    !preg_match('/id="gcScanInput"[\s\S]{0,200}inputmode="numeric"/', $src));
ok('İşaretçi-farkında odak: dokunmatik-yalnız cihazda OTOMATİK odak yok',
    str_contains($srcKod, "matchMedia('(any-pointer: fine)')")
    && (bool)preg_match('/function focusInput\(\)\s*\{\s*if\s*\(!inceIsaretci\)\s*return;/', $srcKod));
ok('Masaüstünde (ince işaretçi) odak DAVRANIŞI KORUNDU — scanInput.focus() hâlâ çağrılıyor',
    str_contains($srcKod, 'scanInput.focus('));
ok('USB kutusu SİLİNMEDİ — type="text" + input/keydown dinleyicileri duruyor',
    str_contains($src, 'id="gcScanInput"')
    && str_contains($srcKod, "scanInput.addEventListener('input'")
    && str_contains($srcKod, "scanInput.addEventListener('keydown'"));
ok('USB okuması hâlâ kaynak=usb_decimal ile gönderiliyor (masaüstü akışı değişmedi)',
    substr_count($srcKod, "kaydet(v, 'usb_decimal')") >= 2);
// ⚠ NFC butonuna dokunmak USB kutusuna ODAK VERMEMELİ (yazılım klavyesi
// NFC akışının üstüne çıkardı). Bu artık İKİ kapıyla sağlanıyor ve İKİSİ DE
// teşhis sayfasından FARKLI bir NFC kodu gerektirmiyor:
//   (a) genel click dinleyicisi nfcBtn'i hariç tutar → focusInput() çağrılmaz
//   (b) focusInput() dokunmatik-yalnız cihazda zaten hemen çıkar
// Eski `document.activeElement.blur()` satırı teşhis sayfasında YOKTU; iki
// uygulamayı ayıran farklardan biriydi ve KALDIRILDI.
ok('Genel click dinleyicisi nfcBtn\'i HARİÇ TUTUYOR (NFC dokunuşu USB kutusunu odaklamaz)',
    (bool)preg_match('/e\.target\s*!==\s*nfcBtn/', $srcKod));
ok('NFC tıklama işleyicisi scanInput\'a ODAK VERMİYOR',
    !preg_match("/nfcBtn\.addEventListener\('click',\s*function\s*\(\)\s*\{[\s\S]{0,700}focusInput\(\)/", $srcKod));
ok('Teşhis sayfasında olmayan blur() farkı KALDIRILDI (iki uygulama ayrışmasın)',
    !str_contains($srcKod, 'document.activeElement.blur()'));

echo "\n=== 15. TEŞHİS SAYFASI — DAVRANIŞ KORUNDU (kanıt sayfası) ===\n";
// Teşhis sayfası artık paylaşılan yolu ÇAĞIRIYOR (§10c), ama GÖZLENEBİLİR
// DAVRANIŞI değişmedi: aynı çağrı sırası, aynı arayüz geçişleri, hâlâ
// sunucuya hiçbir şey göndermiyor. Git temizliği yerine DAVRANIŞ sabitlenir —
// dosya artık bilerek (ve yalnız bu ölçüde) değişiyor.
ok('Teşhis sayfası HÂLÂ hiçbir şey göndermiyor (fetch / XHR / csrf_check YOK)',
    !preg_match('/\bfetch\s*\(/', $nfcTestSrc)
    && !str_contains($nfcTestSrc, 'XMLHttpRequest')
    && !str_contains($nfcTestSrc, 'csrf_check('));
ok('Tekrar-giriş koruması korundu (if (tarayiciAktif) return;)',
    str_contains($nfcTestKod, 'if (tarayiciAktif) return;'));
ok('Buton scan() ÇÖZÜLÜNCE pasifleşiyor + "DİNLENİYOR" etiketi korundu',
    (bool)preg_match('/onBasladi:\s*function\s*\(\)\s*\{[\s\S]{0,300}startBtn\.disabled\s*=\s*true/', $nfcTestKod)
    && str_contains($nfcTestSrc, 'DİNLENİYOR — kartı yaklaştırın'));
ok('Okuma/hata gösterimleri korundu (okumaGoster + NDEFReadingError)',
    str_contains($nfcTestKod, 'onOkuma: okumaGoster')
    && str_contains($nfcTestSrc, 'NDEFReadingError'));
ok("'NotSupportedError' dalı paylaşılan yola taşındı (davranış aynı)",
    str_contains($helperJs, 'NotSupportedError') && !str_contains($nfcTestKod, 'NotSupportedError'));
ok('Ortam bilgisi tablosu hâlâ sayfanın KENDİSİNDE (teşhis çıktısı, okuma yolu değil)',
    str_contains($nfcTestSrc, "'NDEFReader' in window") && str_contains($nfcTestSrc, 'isSecureContext'));
ok('Teşhis sayfası php -l geçiyor', (function () use ($KOK) {
    $c = []; $r = 0; exec('php -l ' . escapeshellarg($KOK . '/pdks_nfc_test.php') . ' 2>&1', $c, $r); return $r === 0;
})());

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
