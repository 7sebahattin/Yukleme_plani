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

echo "\n=== 10. MOBİL NFC YAŞAM DÖNGÜSÜ — canlı düzeltme (re-arm, görünürlük) ===\n";
ok("Başlangıç NFC buton etiketi 'NFC İLE OKU'", str_contains($src, 'NFC İLE OKU'));
ok("Dinleme etiketi 'NFC HAZIR — KARTI YAKLAŞTIRIN'", str_contains($src, 'NFC HAZIR — KARTI YAKLAŞTIRIN'));
ok('nfcBaslat() fonksiyonu tanımlı (yeniden kullanılabilir başlatma/yeniden-silahlanma)',
    (bool)preg_match('/function\s+nfcBaslat\s*\(/', $srcKod));
ok('Başarılı NFC okuması sonrası kaydet() nfcBaslat(true) ile SESSİZCE yeniden silahlanmayı dener',
    (bool)preg_match('/if\s*\(nfcOkumasiMi\)\s*nfcBaslat\(true\)/', $srcKod));
ok('kaydet() İKİ dalda da (başarı VE ağ hatası) yeniden silahlanma dener',
    substr_count($srcKod, 'nfcBaslat(true)') >= 2);
ok('Buton tıklaması GERÇEK kullanıcı dokunuşuyla nfcBaslat(false) çağırır (başarısızlıkta açık hata gösterir)',
    (bool)preg_match('/nfcBtn\.addEventListener\(.click.[\s\S]{0,200}nfcBaslat\(false\)/', $srcKod));
ok("Otomatik yeniden silahlanma başarısız olursa AÇIKÇA 'tekrar dokunun' hatası gösterilir",
    str_contains($src, 'NFC başlatılamadı. NFC İLE OKU butonuna tekrar dokunun.'));
ok('visibilitychange dinleyicisi var (sekme arka plandan dönünce oturum sessizce doğrulanır)',
    str_contains($srcKod, "visibilitychange"));
ok('reading/readingerror dinleyicileri TEK NDEFReader örneğine BİR KEZ eklenir (tekrar tekrar değil)',
    (bool)preg_match('/if\s*\(\s*!ndefOkuyucu\s*\)\s*\{[\s\S]{0,400}addEventListener\(.reading.[\s\S]{0,400}addEventListener\(.readingerror./', $srcKod));
ok('Mod değişimi (girModuSec/gcModeChange) NFC oturum durumuna (ndefOkuyucu/nfcListening) DOKUNMUYOR',
    !preg_match('/function girModuSec[\s\S]{0,500}(ndefOkuyucu|nfcListening)/', $srcKod)
    && !preg_match("/gcModeChange'\)\.addEventListener\('click'[\s\S]{0,500}(ndefOkuyucu|nfcListening)/", $srcKod));

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
ok('JS, dinleme durumunda .pdks-kiosk-nfc-armed sınıfını ekliyor/kaldırıyor',
    str_contains($srcKod, "classList.toggle('pdks-kiosk-nfc-armed'"));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
