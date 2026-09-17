<?php
// =========================================================
// scripts/pdks_faz1b_static_smoke.php — PDKS Faz 1B statik kural testi
//
// SADECE CLI. Ağ yok, DB yok — kaynak kodda regex ile kural arar
// (beyan_bildirim_smoke.php ile aynı desen). Şunları KANITLAR:
//
//  1) Her POST işleyen dosya csrf_check() çağırıyor.
//  2) Her sayfa require_pdks() ile yetki kapısından geçiyor.
//  3) FAZ 1B sayfaları (personel*.php) kendi giriş/çıkış yazma mantığını
//     TEKRARLAMIYOR — attendance_events artık GERÇEKTEN VAR (Giriş-Çıkış
//     fazı), ama TEK yazma yolu config/pdks.php → pdks_devam_kaydet()'tir;
//     bu sayfalar ona dokunmaz. Ayrıca:
//     - Android/NFC ÜRETİM kodu yok (yalnız Faz 0'ın teşhis APK'si tools/ altında,
//       o da üretim değil)
//     - ayrı bir api_pdks.php dosyası yok (uç nokta giris_cikis.php içinde)
//     - cihaz/token tabanlı model (attendance_devices/attendance_api_sessions) yok
//  4) UID mantığı sayfa dosyalarında TEKRARLANMADI — yalnız config/pdks.php'nin
//     fonksiyonları çağrılıyor (pdks_uid_hex_normalize/from_decimal doğrudan
//     kart yazma yolunun DIŞINDA kullanılmıyor).
//  5) Faz 1'in düzeltilen "otomatik ters-alias" hatası YENİDEN AÇILMADI.
//  6) Fotoğraf endpoint'i path traversal'a kapalı (regex ile dosya adı doğrulaması).
//
//   php scripts/pdks_faz1b_static_smoke.php   → çıkış kodu 0 = tüm testler geçti
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
    printf("%-70s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }

$sayfalar = ['personel.php', 'personel_form.php', 'personel_kartlar.php', 'personel_foto.php'];
$hepsi = ['config/pdks.php', ...$sayfalar, 'assets/pdks.js', 'assets/pdks.css'];

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach ($hepsi as $d) {
    if (str_ends_with($d, '.php')) {
        $cikti = []; $rc = 0;
        exec('php -l ' . escapeshellarg($KOK . '/' . $d) . ' 2>&1', $cikti, $rc);
        ok("php -l: $d", $rc === 0, implode("\n", $cikti));
    } else {
        ok("dosya var: $d", file_exists($KOK . '/' . $d));
    }
}

echo "\n=== 2. HER SAYFA YETKİ KAPISINDAN GEÇİYOR (require_pdks) ===\n";
foreach ($sayfalar as $s) {
    $src = oku($s);
    ok("$s → require_pdks(...) çağırıyor", (bool)preg_match('/require_pdks\(\s*[\'"]/', $src));
}

echo "\n=== 3. HER POST İŞLEYİCİ csrf_check() ÇAĞIRIYOR ===\n";
foreach (['personel.php', 'personel_form.php', 'personel_kartlar.php'] as $s) {
    $src = oku($s);
    if (preg_match("/REQUEST_METHOD'\\]\\s*===\\s*'POST'/", $src)) {
        ok("$s → POST dalında csrf_check() var", str_contains($src, 'csrf_check($_POST'));
    } else {
        ok("$s → POST işlemi yok (kontrol gereksiz)", true);
    }
}
// personel_form.php'nin BİRDEN ÇOK action dalı var (save_employee/delete_employee/
// kart_ata/kart_durum/kart_degistir) — csrf_check() dalların TEPESİNDE, action
// belirlenmeden ÖNCE çağrılmalı (aksi hâlde bir dal korumasız kalabilir).
$pf = oku('personel_form.php');
ok('personel_form.php: csrf_check() action switch\'inden ÖNCE (tüm dallar korunuyor)',
    (function () use ($pf) {
        $posCsrf = strpos($pf, 'csrf_check(');
        $posAction = strpos($pf, "\$action = trim(\$_POST['action']");
        return $posCsrf !== false && $posAction !== false && $posCsrf < $posAction;
    })());

echo "\n=== 4. SERVER-SIDE YETKİ — POST DALLARINDA DA TEKRAR KONTROL (savunma derinliği) ===\n";
ok('personel_kartlar.php: POST dalında require_pdks TEKRARI var',
    (bool)preg_match("/REQUEST_METHOD'\\]\\s*===\\s*'POST'.*?require_pdks\\(/s", oku('personel_kartlar.php')));
ok('personel_form.php: kart eylemlerinde pdks_can(\'cards\') tekrar kontrolü var',
    str_contains($pf, "pdks_can('cards')"));
ok('personel_form.php: silme işleminde pdks_can(\'employees\') tekrar kontrolü var',
    str_contains($pf, "pdks_can('employees')"));

echo "\n=== 5. PERSONEL/KART SAYFALARI GİRİŞ-ÇIKIŞ MANTIĞINI TEKRARLAMIYOR ===\n";
// Not: attendance_events / pdks_devam_kaydet() artık config/pdks.php'de
// GERÇEKTEN VAR (Giriş-Çıkış fazı, bkz. scripts/pdks_db_smoke.php §15 ve
// scripts/pdks_giris_cikis_static_smoke.php) — bu ARTIK "kapsam dışı" değil.
// Burada doğrulanan, bu Faz 1B sayfalarının (personel*.php) KENDİ İÇLERİNDE
// giriş/çıkış yazma mantığını TEKRARLAMADIĞI, TEK OTORİTENİN (config/pdks.php)
// dışına taşmadığıdır.
$tumIcerik = '';
foreach ($sayfalar as $s) $tumIcerik .= "\n" . oku($s);
$tumIcerik .= "\n" . oku('config/pdks.php');
$sayfaIcerik = '';
foreach ($sayfalar as $s) $sayfaIcerik .= "\n" . oku($s);

ok('Faz 1B sayfaları (personel*.php) attendance_events\'e SQL YAZMIYOR (yalnız config/pdks.php yazar)',
    !preg_match('/\bINSERT\s+INTO\s+attendance_events\b/i', $sayfaIcerik));
ok('Faz 1B sayfaları kendi giriş/çıkış yön mantığını TAŞIMIYOR (event_type/entry_gate/proposed_type)',
    !preg_match('/\b(event_type|entry_gate|proposed_type)\b/i', $sayfaIcerik));
ok('api_pdks.php (ayrı bir API dosyası) OLUŞTURULMADI — uç nokta giris_cikis.php İÇİNDE',
    !file_exists($KOK . '/api_pdks.php'));
ok('attendance_devices/attendance_api_sessions (cihaz/token tabanlı model) KULLANILMIYOR',
    !preg_match('/\battendance_(devices|api_sessions)\b/i', $tumIcerik));
ok('Android ÜRETİM Kotlin/Gradle dosyası REPO KÖKÜNDE yok (yalnız tools/ altındaki Faz 0 teşhis APK\'si — üretim değil)',
    !file_exists($KOK . '/app') && !file_exists($KOK . '/MainActivity.kt'));

echo "\n=== 6. UID MANTIĞI TEKRARLANMADI — sayfalar yalnız config/pdks.php'yi çağırıyor ===\n";
foreach ($sayfalar as $s) {
    if ($s === 'personel_foto.php') continue;   // UID ile ilgisi yok
    $src = oku($s);
    ok("$s: kendi hexdec()/dechex() dönüşümü YOK (UID mantığı tekrarlanmamalı)",
        !preg_match('/\b(hexdec|dechex)\s*\(/', $src));
    ok("$s: doğrudan employee_card_uids'e INSERT YOK (kart yazmanın tek yolu pdks_kart_olustur)",
        !preg_match('/INSERT\s+INTO\s+employee_card_uids/i', $src));
}
ok('config/pdks.php DIŞINDA employee_card_uids\'e yazan İKİNCİ bir yer yok',
    substr_count($tumIcerik, 'INSERT INTO employee_card_uids') === 1);

echo "\n=== 7. FAZ 1 DÜZELTMESİ (§6a) YENİDEN AÇILMADI — otomatik ters-alias yok ===\n";
$pdksSrc = oku('config/pdks.php');
ok("pdks_kart_ata() kendi UID mantığını YAZMIYOR, pdks_kart_olustur()'u ÇAĞIRIYOR",
    (bool)preg_match('/function pdks_kart_ata.*?pdks_kart_olustur\(/s', $pdksSrc));
ok("pdks_kart_degistir() kendi UID mantığını YAZMIYOR, pdks_kart_olustur()'u ÇAĞIRIYOR",
    (bool)preg_match('/function pdks_kart_degistir.*?pdks_kart_olustur\(/s', $pdksSrc));
ok("Faz 1B'de İKİNCİ bir 'kind'=>'reversed' otomatik yazımı YOK",
    !preg_match("/'reversed'/", oku('personel.php') . oku('personel_form.php') . oku('personel_kartlar.php')));

echo "\n=== 8. FOTOĞRAF ENDPOINT'İ — PATH TRAVERSAL'A KAPALI ===\n";
// Not: aranan desenin kendisi bir regex olduğu için (a-f0-9{32}.jpg), burada
// regex-içinde-regex yazmak yerine kaynakta AYNEN geçen sabit alt-diziyi arıyoruz
// — daha az kırılgan, niyeti daha açık.
$guvenliAdDeseni = '^[a-f0-9]{32}\.jpg$';
$fotoSrc = oku('personel_foto.php');
ok('dosya adı doğrulaması var (32 hex + .jpg)',
    str_contains($fotoSrc, $guvenliAdDeseni));
ok('doğrulamadan SONRA $_GET doğrudan dosya yoluna eklenmiyor (PDKS_FOTO_DIR . $fn kalıbı)',
    str_contains($fotoSrc, 'PDKS_FOTO_DIR . $fn'));
ok('dosyanın GERÇEKTEN bir employees satırına ait olduğu DB\'den doğrulanıyor (yalnız disk varlığı yetmiyor)',
    str_contains($fotoSrc, 'SELECT id FROM employees WHERE photo_file'));
$fotoSilBlok = '';
if (preg_match('/function pdks_foto_sil\b.*?\n}/s', $pdksSrc, $mm)) $fotoSilBlok = $mm[0];
ok('pdks_foto_sil() de AYNI güvenli-ad deseniyle korunuyor',
    $fotoSilBlok !== '' && str_contains($fotoSilBlok, $guvenliAdDeseni));

echo "\n=== 9. TC KİMLİK NUMARASI HİÇBİR YERDE İSTENMİYOR (onaylanan karar #2) ===\n";
$aranan = ['tc_kimlik', 'national_id', 'tckn', 'kimlik_no'];
foreach ($aranan as $a) {
    ok("'$a' alanı YOK", stripos($tumIcerik, $a) === false);
}

echo "\n=== 10. MEVCUT SİSTEM DAVRANIŞI DEĞİŞMEDİ ===\n";
// index.php ve config/helpers.php BİLEREK bu listenin DIŞINDA: navigasyon
// bağlama (§7 gereği) ikisine de birer küçük, katkılı (additive) ekleme
// yapar. Burada "hiç değişmedi" değil "yalnız BEKLENEN şekilde değişti"
// doğrulanır — tek CSS/JS ve çekirdek auth/db katmanı asıl korunması
// gereken yerlerdir.
// Faz 7: sw.js bu listeden ÇIKARILDI — assets/print_base.css'e yazdırma
// sayfaları için ek kural eklendiği için CLAUDE.md kuralı gereği SW
// CACHE_NAME (ve config/helpers.php'deki eşlenik APP_SURUM) v217'den
// v218'e çekildi. Bu TEK SATIRLIK, RUTİN sürüm damgası güncellemesi —
// içerik/mantık kaybı DEĞİL; sw.js'in kendi önbellekleme mantığı
// (network-first fetch stratejisi, SHELL listesi) hiç değişmedi, yalnız
// sabit sürüm dizesi arttı. tek-CSS/JS kuralı (style.css/app.js) ve
// çekirdek auth/db katmanı (db.php/auth.php) hâlâ TAM korunuyor.
$dokunulmamali = ['assets/style.css', 'assets/app.js', 'config/db.php', 'config/auth.php'];
$gitDurum = shell_exec('cd ' . escapeshellarg($KOK) . ' && git status --porcelain -- ' . implode(' ', array_map('escapeshellarg', $dokunulmamali)) . ' 2>&1');
ok('style.css / app.js / db.php / auth.php DEĞİŞMEDİ (tek-CSS/JS ve çekirdek auth korunuyor)',
    trim((string)$gitDurum) === '', (string)$gitDurum);
// git diff'e DEĞİL, doğrudan mevcut dosya içeriğine bakılır — bu kontrol
// hem işlenmemiş (dirty) özellik dalında hem de commit/merge SONRASI temiz
// bir checkout'ta (git diff boş döner, hiçbir şey KANITLAMAZ) aynı şekilde
// anlamlı kalsın diye.
$swSrc = oku('sw.js');
ok('sw.js: CACHE_NAME ve APP_SURUM sürümü v225',
    str_contains($swSrc, "const CACHE_NAME = 'yukleme-plani-v225';")
    && str_contains(oku('config/helpers.php'), "define('APP_SURUM', 'v225');"));
ok('sw.js: SHELL önbellek listesi / network-first fetch stratejisi AYNI (yalnız sürüm sabiti değişti)',
    str_contains($swSrc, "'./assets/hesap.js'") && str_contains($swSrc, "fetch(e.request).then(function(response)"));

// index.php İÇİN: bu turdan (Faz 7, Sprint Navigasyon-01) ÖNCE değişti
// (nav bağlama), ama yalnız EKLEME olarak — mevcut hiçbir satır
// silinmedi/değiştirilmedi. Faz 7 kullanıcının AÇIK talimatıyla ("Connect/
// add the existing mobile 'Personel' button... to personel_takip.php")
// TEK bir mevcut kartı (Personel → personel.php) KASITLI olarak
// personel_takip.php'ye yeniden yönlendirdi + görünürlüğünü genişletti —
// bu BEŞ satır bu YÜZDEN allowlist'e alındı; bunun DIŞINDA index.php'de
// hiçbir satır silinmemiş olmalı.
$diffIndex = shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- index.php 2>&1');
$silinenIndex = array_filter(explode("\n", (string)$diffIndex), function ($l) {
    return preg_match('/^-(?!--)/', $l) === 1;   // '-' ile başlayan ama '---' başlığı olmayan satır
});
$indexBeklenenEskiSatirlar = [
    "-<?php if (can('attendance.employees') || can('attendance.cards') || is_admin()): ?>",
    '-    <a href="personel.php" class="home-card">',
    '-        <div class="home-card-icon" style="background:#eef2ff">👤</div>',
    '-        <div class="home-card-title">Personel</div>',
    '-        <div class="home-card-sub">Personel ve kart yönetimi</div>',
];
$indexBeklenmeyenSilinen = array_filter($silinenIndex, fn($l) => !in_array(trim($l), array_map('trim', $indexBeklenenEskiSatirlar), true));
ok('index.php: YALNIZ Faz 7\'nin bilinen "Personel" kart değişikliği silindi, başka hiçbir satır silinmedi',
    count($indexBeklenmeyenSilinen) === 0,
    count($indexBeklenmeyenSilinen) . ' beklenmeyen satır silinmiş görünüyor: ' . implode(' | ', $indexBeklenmeyenSilinen));

// config/helpers.php İÇİN: Giriş-Çıkış fazı, Personel bölümünün görünürlük
// koşulunu (§11) KASITLI olarak GENİŞLETTİ — yalnız attendance.scan yetkisi
// olan (employees/cards YOK) bir kullanıcı da artık "Personel" bölüm
// başlığını görmeli, aksi hâlde Giriş/Çıkış linki kimseye görünmezdi. Bu
// YÜZDEN o TEK satırın değiştirilmesi (silinip yeniden yazılması) burada
// BEKLENEN ve İNCELENMİŞ bir değişikliktir — "hiç silinmesin" kuralı
// YALNIZ bu bilinen satır için gevşetilir, başka hiçbir satır için değil.
// Sprint Günlük-İşçi-01: çavuş/işçi-kart-havuzu izinleri (attendance.foremen,
// attendance.worker_cards) İKİ mevcut izin dizisine (admin'in $pdks_p'si,
// 'ik' rolünün listesi) EKLENDİ — çok satırlı literal olduğu için git diff
// bu satırları "silinip yeniden yazılmış" gösterir, İÇERİK KAYBI değil.
// Aynı AZ-İSTİSNA yaklaşımı: yalnız BİLİNEN, İNCELENMİŞ satırlar allowlist'e
// eklenir, başka hiçbir satır için gevşetilmez.
$beklenenEskiSatirlar = [
    "-    \$p_pdks  = (\$_fn && (can('attendance.employees') || can('attendance.cards'))) || \$p_adm;",
    "-                       'attendance.devices','attendance.admin'];",
    "-                               'attendance.report','attendance.employees','attendance.cards'],",
    // Sprint Günlük-İşçi-01 → Günlük-İşçi-02 (Faz 2, seri Giriş/Çıkış):
    // AYNI üç çok satırlı literal BİR KEZ DAHA genişletildi
    // (attendance.daily_scan eklendi) — git diff bu satırları da "silinip
    // yeniden yazılmış" gösterir, İÇERİK KAYBI değil.
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards'))) || \$p_adm;",
    "-                       'attendance.foremen','attendance.worker_cards'];",
    "-                               'attendance.foremen','attendance.worker_cards'],",
    // Faz 2 düzeltme turu (kullanıcının açık talimatı #2 — güvenlik/operasyon
    // rolü): 'operator' rolünün tek satırlık literal dizisine YALNIZ
    // attendance.daily_scan eklendi (çavuş/kart/muhasebe/admin izni YOK) —
    // git diff bu TEK satırı da "silinip yeniden yazılmış" gösterir.
    "-                'operator' => ['dashboard.read','records.read','records.write','records.lock','kantar.read','kantar.write','stok.read','stok.write','defs.read','reports.read','reports.export','beyan.read','beyan.write','maliyet.read','maliyet.write','hesap.read','hesap.write'],",
    // Sprint Günlük-İşçi-02 → Günlük-İşçi-04 (Faz 3, günlük puantaj raporları):
    // AYNI üç çok satırlı literal + 'muhasebe' rolü TEK yeni izinle
    // (attendance.daily_reports) genişletildi — 'operator' BİLEREK BUNA
    // DOKUNULMADI (görev talimatı: "Do NOT automatically give full
    // historical reporting" — bkz. yukarıdaki operator satırı, hâlâ AYNI).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan'))) || \$p_adm;",
    "-                       'attendance.foremen','attendance.worker_cards','attendance.daily_scan'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay'],",
    "-                               'attendance.foremen','attendance.worker_cards','attendance.daily_scan'],",
    // Sprint Günlük-İşçi-04 → Günlük-İşçi-05 (Faz 4, hakediş): AYNI üç çok
    // satırlı literal + 'muhasebe' rolü İKİ yeni izinle (attendance.
    // foreman_rates, attendance.entitlements) genişletildi; 'ik' rolü
    // YALNIZ attendance.entitlements aldı (foreman_rates ALMADI — ticari
    // fiyat yönetimi kapsam dışı). 'operator' BİLEREK BUNA DA DOKUNULMADI
    // (görev talimatı: "Do not expose prices or hakediş amounts on the
    // security scanning screen." — bkz. yukarıdaki operator satırı, hâlâ AYNI).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports'))) || \$p_adm;",
    "-                       'attendance.daily_reports'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports'],",
    "-                               'attendance.daily_reports'],",
    // Sprint Günlük-İşçi-05 → Günlük-İşçi-06 (Faz 5, cari hesap/ödeme): AYNI üç
    // çok satırlı literal + 'muhasebe' rolü İKİ yeni izinle (attendance.
    // foreman_accounts, attendance.foreman_payments) genişletildi; 'ik' rolü
    // BİLEREK BUNA DA DOKUNULMADI (görev talimatı: "ik: NO payment management
    // by default" — belirsizlikte hesap görüntüleme de verilmedi). 'operator'
    // yine DOKUNULMADI (aynı gerekçe: finansal ekranlar operatöre kapalı).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports') || can('attendance.foreman_rates') || can('attendance.entitlements'))) || \$p_adm;",
    "-                       'attendance.daily_reports','attendance.foreman_rates','attendance.entitlements'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements'],",
    // Sprint Günlük-İşçi-06 → Günlük-İşçi-07 (Faz 6, yönetim raporlama
    // merkezi): AYNI üç çok satırlı literal + 'muhasebe' VE 'ik' rolleri
    // TEK yeni izinle (attendance.management_reports) genişletildi — 'ik'
    // BU SEFER BİLEREK DAHİL EDİLDİ (görev talimatı: "ik = YES if
    // operational reporting is appropriate"), ama attendance.foreman_accounts
    // HÂLÂ ALMADI (Faz 5'in kararı korunuyor — raporlar.php'nin finansal
    // bölümleri 'ik'e yine KAPALI, bkz. pdks_rapor_static_smoke.php §4).
    // 'operator' yine DOKUNULMADI.
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports') || can('attendance.foreman_rates') || can('attendance.entitlements') || can('attendance.foreman_accounts') || can('attendance.foreman_payments'))) || \$p_adm;",
    "-                       'attendance.foreman_accounts','attendance.foreman_payments'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements','attendance.foreman_accounts','attendance.foreman_payments'],",
    "-                               'attendance.daily_reports','attendance.entitlements'],",
    // Sprint Navigasyon-01 (Faz 7, kullanıcının açık talimatı: "Replace
    // these scattered sidebar entries with ONE primary entry"): ÖNCEKİ
    // turlardan FARKLI olarak bu SATIR YENİDEN YAZMA DEĞİL, BİLEREK,
    // KAPSAMLI bir SİLMEDİR — Personel + Günlük İşçi bölümlerindeki 12 ayrı
    // link (ve onların $a_* aktif-sayfa değişkenleri) TEK bir
    // "Personel Takibi" (personel_takip.php) linkine indirildi. Hiçbir
    // SAYFA silinmedi (görev talimatı: "This is a navigation consolidation
    // layer... Existing URLs/pages remain authoritative and accessible.") —
    // yalnız SIDEBAR görünürlüğü değişti; her hedef sayfa KENDİ yetki
    // kontrolünü hâlâ taşır (bkz. pdks_takip_static_smoke.php §10).
    "-    \$a_pdksp = in_array(\$cur, ['personel.php', 'personel_form.php'], true);",
    "-    \$a_pdksk = \$cur === 'personel_kartlar.php';",
    "-    \$a_pdksg = \$cur === 'giris_cikis.php';",
    "-    \$a_cavus = in_array(\$cur, ['cavuslar.php', 'cavus_form.php'], true);",
    "-    \$a_isk   = in_array(\$cur, ['isci_kartlari.php', 'isci_tipleri.php'], true);",
    "-    \$a_gunlukgc = \$cur === 'gunluk_isci_giris_cikis.php';",
    "-    \$a_puantaj  = in_array(\$cur, ['gunluk_isci_puantaj.php', 'gunluk_isci_puantaj_detay.php'], true);",
    "-    \$a_fiyat    = \$cur === 'cavus_fiyatlari.php';",
    "-    \$a_hakedis  = in_array(\$cur, ['cavus_hakedis.php', 'cavus_hakedis_detay.php'], true);",
    "-    \$a_odeme    = \$cur === 'cavus_odeme.php';",
    "-    \$a_cari     = in_array(\$cur, ['cavus_cari.php', 'cavus_ekstre.php'], true);",
    "-    \$a_yrapor   = \$cur === 'raporlar.php';",
    "-        <?php if (\$p_pdks): ?>",
    "-        <?php if (\$p_pdks || \$p_gunluk): ?>",
    "-        <?php if (\$_fn && (can('attendance.employees') || \$p_adm)) \$lnk('personel.php', '👤', 'Personeller', \$a_pdksp); ?>",
    "-        <?php if (\$_fn && (can('attendance.cards')     || \$p_adm)) \$lnk('personel_kartlar.php', '🪪', 'Kart Yönetimi', \$a_pdksk); ?>",
    "-        <?php if (\$_fn && (can('attendance.scan')      || \$p_adm)) \$lnk('giris_cikis.php', '🚪', 'Giriş / Çıkış', \$a_pdksg); ?>",
    "-        <?php endif; ?>",
    "-",
    "-        <?php if (\$p_gunluk): ?>",
    "-        <div class=\"sidebar-section\">Günlük İşçi</div>",
    "-        <?php if (\$_fn && (can('attendance.foremen')      || \$p_adm)) \$lnk('cavuslar.php',      '👷', 'Çavuşlar',      \$a_cavus); ?>",
    "-        <?php if (\$_fn && (can('attendance.worker_cards') || \$p_adm)) \$lnk('isci_kartlari.php', '🪪', 'İşçi Kartları', \$a_isk); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_scan')   || \$p_adm)) \$lnk('gunluk_isci_giris_cikis.php', '🚪', 'Giriş / Çıkış', \$a_gunlukgc); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_reports') || \$p_adm)) \$lnk('gunluk_isci_puantaj.php', '📅', 'Günlük Puantaj', \$a_puantaj); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_rates')  || \$p_adm)) \$lnk('cavus_fiyatlari.php', '💰', 'Çavuş Fiyatları', \$a_fiyat); ?>",
    "-        <?php if (\$_fn && (can('attendance.entitlements')   || \$p_adm)) \$lnk('cavus_hakedis.php',   '🧾', 'Hakediş',         \$a_hakedis); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_payments') || \$p_adm)) \$lnk('cavus_odeme.php', '💸', 'Çavuş Ödeme', \$a_odeme); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_accounts') || \$p_adm)) \$lnk('cavus_cari.php',  '📒', 'Çavuş Cari',  \$a_cari); ?>",
    "-        <?php if (\$_fn && (can('attendance.management_reports') || \$p_adm)) \$lnk('raporlar.php', '📊', 'Yönetim Raporları', \$a_yrapor); ?>",
    // Faz 7: assets/print_base.css'e yeni yazdırma sayfaları için ek kural
    // eklendi (thead tekrarı) → SW cache sürümü + APP_SURUM birlikte v217'den
    // v218'e çekildi (CLAUDE.md kuralı: "SW cache versiyonu artırıldı mı?").
    // Tek satırlık sürüm sabiti güncellemesi, içerik kaybı DEĞİL.
    "-    define('APP_SURUM', 'v217');",
    // Faz 8A PRE-MERGE GÜVENLİK DÜZELTMESİ: config/pdks_gunluk.php (üç
    // durumlu status modeli) + assets/pdks.css (yeni rozet rengi) değişti
    // → SW cache sürümü + APP_SURUM birlikte v218'den v219'a çekildi (AYNI
    // rutin, tek satırlık sürüm damgası güncellemesi — bkz. yukarıdaki
    // v217→v218 emsali).
    "-    define('APP_SURUM', 'v218');",
    "-    define('APP_SURUM', 'v219');",
    "-    define('APP_SURUM', 'v220');",
    "-    define('APP_SURUM', 'v221');",
    "-    define('APP_SURUM', 'v222');",
    "-    define('APP_SURUM', 'v223');",
    "-    define('APP_SURUM', 'v224');",
];
$diffHelpers = shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- config/helpers.php 2>&1');
$silinenHelpers = array_filter(explode("\n", (string)$diffHelpers), function ($l) {
    return preg_match('/^-(?!--)/', $l) === 1;
});
$beklenmeyenSilinen = array_filter($silinenHelpers, fn($l) => !in_array(trim($l), array_map('trim', $beklenenEskiSatirlar), true));
ok('config/helpers.php: YALNIZ BİLİNEN/İNCELENMİŞ satırlar değişti (attendance.scan genişlemesi + Günlük İşçi izin ekleri), başka hiçbir satır silinmedi',
    count($beklenmeyenSilinen) === 0,
    count($beklenmeyenSilinen) . " beklenmeyen silinen satır:\n" . implode("\n", $beklenmeyenSilinen));

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
