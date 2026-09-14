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

echo "\n=== 10. WEB NFC — pdks_nfc_test.php İLE AYNI, KANITLANMIŞ TEK-DOKUNUŞ DİZİSİ ===\n";
// ⚠ Canlı hata: önceki sürüm her okumadan SONRA ve sekme görünürlüğü
// değiştiğinde OTOMATİK scan() çağırıyordu (kullanıcı dokunuşu OLMADAN) —
// bu, Chrome'un Web NFC oturumunu sessizce bozup Android'in kendi "Etiket
// algılandı" arayüzünün devreye girmesine yol açıyordu (gerçek cihaz ekran
// görüntüsüyle doğrulandı). Düzeltme: OTOMATİK yeniden silahlanma TAMAMEN
// KALDIRILDI — teşhis sayfasıyla BİREBİR AYNI dizi kullanılır.
$nfcTestSrc = oku2('pdks_nfc_test.php');

ok("Başlangıç NFC buton etiketi 'NFC İLE KART OKU'", str_contains($src, 'NFC İLE KART OKU'));
ok("Dinleme etiketi 'NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN'", str_contains($src, 'NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN'));
ok('OTOMATİK yeniden silahlanma fonksiyonu (nfcBaslat/sessiz parametreli) KALDIRILDI',
    !preg_match('/function\s+nfcBaslat\s*\(/', $srcKod));
ok('visibilitychange tabanlı sessiz yeniden-scan() KALDIRILDI (transient activation ihlali riskiydi)',
    !str_contains($srcKod, 'visibilitychange'));
ok('kaydet() artık NFC okumasından sonra yalnız BUTONU SIFIRLAR — scan() TEKRAR ÇAĞRILMIYOR',
    (bool)preg_match('/if\s*\(nfcOkumasiMi\)\s*nfcButonuSifirla\(\)/', $srcKod)
    && substr_count($srcKod, 'nfcButonuSifirla()') >= 2
    && !preg_match('/if\s*\(nfcOkumasiMi\)\s*nfcBaslat/', $srcKod));

ok('YENİ NDEFReader() BUTON TIKLAMA İŞLEYİCİSİNİN İÇİNDE oluşturuluyor (teşhis sayfasıyla AYNI desen)',
    (bool)preg_match("/nfcBtn\\.addEventListener\\(.click.[\\s\\S]{0,400}new NDEFReader\\(\\)/", $srcKod));
ok('reading dinleyicisi scan()\'DAN ÖNCE ekleniyor (teşhis sayfasıyla AYNI sıra)',
    (bool)preg_match('/addEventListener\(.reading.[\s\S]{0,400}\.scan\(/', $srcKod));
ok('readingerror dinleyicisi de eklenmiş', str_contains($srcKod, "addEventListener('readingerror'"));
ok('scan() bir AbortController sinyaliyle çağrılıyor ("stop/abort" gereksinimini karşılamak için)',
    str_contains($srcKod, 'new AbortController()') && (bool)preg_match('/\.scan\(\s*\{\s*signal:\s*ac\.signal\s*\}\s*\)/', $srcKod));
ok('Kart okunduğunda oturum AÇIKÇA abort() ile kapatılıyor (sıradaki kart YENİ dokunuş gerektirir)',
    (bool)preg_match("/addEventListener\\(.reading.[\\s\\S]{0,400}ac\\.abort\\(\\)/", $srcKod));
ok('scan() reddi (.catch) HER ZAMAN açık Türkçe hata gösterir (sessiz mod YOK)',
    str_contains($src, 'NFC başlatılamadı. NFC İLE KART OKU butonuna tekrar dokunun.'));

echo "\n--- 10b. Teşhis panosu (geçici) ---\n";
ok('#gcNfcDebug paneli sayfada var', str_contains($src, 'id="gcNfcDebug"'));
ok('NFC support / secure context durumu panoya yazılıyor', str_contains($srcKod, 'secure context'));
ok('scan() reddi err.name / err.message panoya yazılıyor (hangi hatayla reddedildiğini görmek için)',
    (bool)preg_match('/err\s*&&\s*err\.name/', $srcKod) && (bool)preg_match('/err\s*&&\s*err\.message/', $srcKod));
// Not: serialNumber/UID panoya YAZILIR (kullanıcının açık isteği — "serialNumber
// received" teşhis panosunda görünmeli); yasaklanan yalnız kimlik doğrulama/
// oturum bilgisidir (csrf token, personel adı).
ok('Panoya CSRF token veya personel adı YAZILMIYOR', !preg_match('/nfcDebugYaz\([^)]*(csrf|full_name)/i', $srcKod));

echo "\n--- 10c. Mod değişimi NFC oturumuna karışmıyor (yalnız buton kapsamlı yerel değişkenler) ---\n";
ok('NFC oturum durumu (ac/ndef) fonksiyon-yerel — modül seviyesinde paylaşılan bir durum YOK',
    !preg_match('/var\s+ndefOkuyucu\b/', $srcKod) && !preg_match('/var\s+nfcListening\b/', $srcKod));
ok('Mod değişimi (girModuSec/gcModeChange) NFC koduna hiç DOKUNMUYOR',
    !preg_match('/function girModuSec[\s\S]{0,500}(NDEFReader|AbortController)/', $srcKod)
    && !preg_match("/gcModeChange'\)\.addEventListener\('click'[\s\S]{0,500}(NDEFReader|AbortController)/", $srcKod));

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
ok('JS, dinlemeye geçince .pdks-kiosk-nfc-armed EKLİYOR, sıfırlayınca KALDIRIYOR',
    str_contains($srcKod, "classList.add('pdks-kiosk-nfc-armed')") && str_contains($srcKod, "classList.remove('pdks-kiosk-nfc-armed')"));

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
    (bool)preg_match('/if\s*\(nfcDestekli\)\s*\{[\s\S]{0,600}nfcBtn\.disabled\s*=\s*false/', $srcKod));
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
ok('NFC tıklamasında odak BIRAKILIYOR (blur) — klavye NFC akışının üstüne çıkmasın',
    (bool)preg_match('/nfcBtn\.addEventListener\(.click.[\s\S]{0,400}document\.activeElement\.blur\(\)/', $srcKod));
// Sabit karakter penceresi yerine SIRA karşılaştırması — araya kod eklendikçe
// kırılmayan, niyeti ("blur önce gelir") doğrudan ifade eden kontrol.
$blurPos = strpos($srcKod, 'document.activeElement.blur()');
$scanPos = strpos($srcKod, '.scan({');
ok('blur, scan() BAŞLAMADAN ÖNCE yapılıyor',
    $blurPos !== false && $scanPos !== false && $blurPos < $scanPos);

echo "\n=== 15. TEŞHİS SAYFASI DEĞİŞMEDİ (referans uygulama korunuyor) ===\n";
$nfcTestGit = shell_exec('cd ' . escapeshellarg($KOK) . ' && git status --porcelain -- pdks_nfc_test.php 2>&1');
ok('pdks_nfc_test.php bu değişiklikte HİÇ DEĞİŞTİRİLMEDİ', trim((string)$nfcTestGit) === '', (string)$nfcTestGit);
ok('Teşhis sayfası hâlâ kendi kanıtlanmış dizisini taşıyor (click → NDEFReader → scan)',
    (bool)preg_match('/addEventListener\(.click.[\s\S]{0,400}new NDEFReader\(\)/', $nfcTestSrc)
    && (bool)preg_match('/addEventListener\(.reading.[\s\S]{0,600}\.scan\(\)/', $nfcTestSrc));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
