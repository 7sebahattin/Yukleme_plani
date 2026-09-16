<?php
// =========================================================
// scripts/pdks_faz1b_static_smoke.php â€” PDKS Faz 1B statik kural testi
//
// SADECE CLI. AÄŸ yok, DB yok â€” kaynak kodda regex ile kural arar
// (beyan_bildirim_smoke.php ile aynÄ± desen). ÅunlarÄ± KANITLAR:
//
//  1) Her POST iÅŸleyen dosya csrf_check() Ã§aÄŸÄ±rÄ±yor.
//  2) Her sayfa require_pdks() ile yetki kapÄ±sÄ±ndan geÃ§iyor.
//  3) FAZ 1B sayfalarÄ± (personel*.php) kendi giriÅŸ/Ã§Ä±kÄ±ÅŸ yazma mantÄ±ÄŸÄ±nÄ±
//     TEKRARLAMIYOR â€” attendance_events artÄ±k GERÃ‡EKTEN VAR (GiriÅŸ-Ã‡Ä±kÄ±ÅŸ
//     fazÄ±), ama TEK yazma yolu config/pdks.php â†’ pdks_devam_kaydet()'tir;
//     bu sayfalar ona dokunmaz. AyrÄ±ca:
//     - Android/NFC ÃœRETÄ°M kodu yok (yalnÄ±z Faz 0'Ä±n teÅŸhis APK'si tools/ altÄ±nda,
//       o da Ã¼retim deÄŸil)
//     - ayrÄ± bir api_pdks.php dosyasÄ± yok (uÃ§ nokta giris_cikis.php iÃ§inde)
//     - cihaz/token tabanlÄ± model (attendance_devices/attendance_api_sessions) yok
//  4) UID mantÄ±ÄŸÄ± sayfa dosyalarÄ±nda TEKRARLANMADI â€” yalnÄ±z config/pdks.php'nin
//     fonksiyonlarÄ± Ã§aÄŸrÄ±lÄ±yor (pdks_uid_hex_normalize/from_decimal doÄŸrudan
//     kart yazma yolunun DIÅINDA kullanÄ±lmÄ±yor).
//  5) Faz 1'in dÃ¼zeltilen "otomatik ters-alias" hatasÄ± YENÄ°DEN AÃ‡ILMADI.
//  6) FotoÄŸraf endpoint'i path traversal'a kapalÄ± (regex ile dosya adÄ± doÄŸrulamasÄ±).
//
//   php scripts/pdks_faz1b_static_smoke.php   â†’ Ã§Ä±kÄ±ÅŸ kodu 0 = tÃ¼m testler geÃ§ti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnÄ±zca CLI Ã¼zerinden Ã§alÄ±ÅŸtÄ±rÄ±labilir.');
}

$KOK = dirname(__DIR__);
$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-70s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    â†’ " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }

$sayfalar = ['personel.php', 'personel_form.php', 'personel_kartlar.php', 'personel_foto.php'];
$hepsi = ['config/pdks.php', ...$sayfalar, 'assets/pdks.js', 'assets/pdks.css'];

echo "\n=== 1. SÃ–Z DÄ°ZÄ°MÄ° ===\n";
foreach ($hepsi as $d) {
    if (str_ends_with($d, '.php')) {
        $cikti = []; $rc = 0;
        exec('php -l ' . escapeshellarg($KOK . '/' . $d) . ' 2>&1', $cikti, $rc);
        ok("php -l: $d", $rc === 0, implode("\n", $cikti));
    } else {
        ok("dosya var: $d", file_exists($KOK . '/' . $d));
    }
}

echo "\n=== 2. HER SAYFA YETKÄ° KAPISINDAN GEÃ‡Ä°YOR (require_pdks) ===\n";
foreach ($sayfalar as $s) {
    $src = oku($s);
    ok("$s â†’ require_pdks(...) Ã§aÄŸÄ±rÄ±yor", (bool)preg_match('/require_pdks\(\s*[\'"]/', $src));
}

echo "\n=== 3. HER POST Ä°ÅLEYÄ°CÄ° csrf_check() Ã‡AÄIRIYOR ===\n";
foreach (['personel.php', 'personel_form.php', 'personel_kartlar.php'] as $s) {
    $src = oku($s);
    if (preg_match("/REQUEST_METHOD'\\]\\s*===\\s*'POST'/", $src)) {
        ok("$s â†’ POST dalÄ±nda csrf_check() var", str_contains($src, 'csrf_check($_POST'));
    } else {
        ok("$s â†’ POST iÅŸlemi yok (kontrol gereksiz)", true);
    }
}
// personel_form.php'nin BÄ°RDEN Ã‡OK action dalÄ± var (save_employee/delete_employee/
// kart_ata/kart_durum/kart_degistir) â€” csrf_check() dallarÄ±n TEPESÄ°NDE, action
// belirlenmeden Ã–NCE Ã§aÄŸrÄ±lmalÄ± (aksi hÃ¢lde bir dal korumasÄ±z kalabilir).
$pf = oku('personel_form.php');
ok('personel_form.php: csrf_check() action switch\'inden Ã–NCE (tÃ¼m dallar korunuyor)',
    (function () use ($pf) {
        $posCsrf = strpos($pf, 'csrf_check(');
        $posAction = strpos($pf, "\$action = trim(\$_POST['action']");
        return $posCsrf !== false && $posAction !== false && $posCsrf < $posAction;
    })());

echo "\n=== 4. SERVER-SIDE YETKÄ° â€” POST DALLARINDA DA TEKRAR KONTROL (savunma derinliÄŸi) ===\n";
ok('personel_kartlar.php: POST dalÄ±nda require_pdks TEKRARI var',
    (bool)preg_match("/REQUEST_METHOD'\\]\\s*===\\s*'POST'.*?require_pdks\\(/s", oku('personel_kartlar.php')));
ok('personel_form.php: kart eylemlerinde pdks_can(\'cards\') tekrar kontrolÃ¼ var',
    str_contains($pf, "pdks_can('cards')"));
ok('personel_form.php: silme iÅŸleminde pdks_can(\'employees\') tekrar kontrolÃ¼ var',
    str_contains($pf, "pdks_can('employees')"));

echo "\n=== 5. PERSONEL/KART SAYFALARI GÄ°RÄ°Å-Ã‡IKIÅ MANTIÄINI TEKRARLAMIYOR ===\n";
// Not: attendance_events / pdks_devam_kaydet() artÄ±k config/pdks.php'de
// GERÃ‡EKTEN VAR (GiriÅŸ-Ã‡Ä±kÄ±ÅŸ fazÄ±, bkz. scripts/pdks_db_smoke.php Â§15 ve
// scripts/pdks_giris_cikis_static_smoke.php) â€” bu ARTIK "kapsam dÄ±ÅŸÄ±" deÄŸil.
// Burada doÄŸrulanan, bu Faz 1B sayfalarÄ±nÄ±n (personel*.php) KENDÄ° Ä°Ã‡LERÄ°NDE
// giriÅŸ/Ã§Ä±kÄ±ÅŸ yazma mantÄ±ÄŸÄ±nÄ± TEKRARLAMADIÄI, TEK OTORÄ°TENÄ°N (config/pdks.php)
// dÄ±ÅŸÄ±na taÅŸmadÄ±ÄŸÄ±dÄ±r.
$tumIcerik = '';
foreach ($sayfalar as $s) $tumIcerik .= "\n" . oku($s);
$tumIcerik .= "\n" . oku('config/pdks.php');
$sayfaIcerik = '';
foreach ($sayfalar as $s) $sayfaIcerik .= "\n" . oku($s);

ok('Faz 1B sayfalarÄ± (personel*.php) attendance_events\'e SQL YAZMIYOR (yalnÄ±z config/pdks.php yazar)',
    !preg_match('/\bINSERT\s+INTO\s+attendance_events\b/i', $sayfaIcerik));
ok('Faz 1B sayfalarÄ± kendi giriÅŸ/Ã§Ä±kÄ±ÅŸ yÃ¶n mantÄ±ÄŸÄ±nÄ± TAÅIMIYOR (event_type/entry_gate/proposed_type)',
    !preg_match('/\b(event_type|entry_gate|proposed_type)\b/i', $sayfaIcerik));
ok('api_pdks.php (ayrÄ± bir API dosyasÄ±) OLUÅTURULMADI â€” uÃ§ nokta giris_cikis.php Ä°Ã‡Ä°NDE',
    !file_exists($KOK . '/api_pdks.php'));
ok('attendance_devices/attendance_api_sessions (cihaz/token tabanlÄ± model) KULLANILMIYOR',
    !preg_match('/\battendance_(devices|api_sessions)\b/i', $tumIcerik));
ok('Android ÃœRETÄ°M Kotlin/Gradle dosyasÄ± REPO KÃ–KÃœNDE yok (yalnÄ±z tools/ altÄ±ndaki Faz 0 teÅŸhis APK\'si â€” Ã¼retim deÄŸil)',
    !file_exists($KOK . '/app') && !file_exists($KOK . '/MainActivity.kt'));

echo "\n=== 6. UID MANTIÄI TEKRARLANMADI â€” sayfalar yalnÄ±z config/pdks.php'yi Ã§aÄŸÄ±rÄ±yor ===\n";
foreach ($sayfalar as $s) {
    if ($s === 'personel_foto.php') continue;   // UID ile ilgisi yok
    $src = oku($s);
    ok("$s: kendi hexdec()/dechex() dÃ¶nÃ¼ÅŸÃ¼mÃ¼ YOK (UID mantÄ±ÄŸÄ± tekrarlanmamalÄ±)",
        !preg_match('/\b(hexdec|dechex)\s*\(/', $src));
    ok("$s: doÄŸrudan employee_card_uids'e INSERT YOK (kart yazmanÄ±n tek yolu pdks_kart_olustur)",
        !preg_match('/INSERT\s+INTO\s+employee_card_uids/i', $src));
}
ok('config/pdks.php DIÅINDA employee_card_uids\'e yazan Ä°KÄ°NCÄ° bir yer yok',
    substr_count($tumIcerik, 'INSERT INTO employee_card_uids') === 1);

echo "\n=== 7. FAZ 1 DÃœZELTMESÄ° (Â§6a) YENÄ°DEN AÃ‡ILMADI â€” otomatik ters-alias yok ===\n";
$pdksSrc = oku('config/pdks.php');
ok("pdks_kart_ata() kendi UID mantÄ±ÄŸÄ±nÄ± YAZMIYOR, pdks_kart_olustur()'u Ã‡AÄIRIYOR",
    (bool)preg_match('/function pdks_kart_ata.*?pdks_kart_olustur\(/s', $pdksSrc));
ok("pdks_kart_degistir() kendi UID mantÄ±ÄŸÄ±nÄ± YAZMIYOR, pdks_kart_olustur()'u Ã‡AÄIRIYOR",
    (bool)preg_match('/function pdks_kart_degistir.*?pdks_kart_olustur\(/s', $pdksSrc));
ok("Faz 1B'de Ä°KÄ°NCÄ° bir 'kind'=>'reversed' otomatik yazÄ±mÄ± YOK",
    !preg_match("/'reversed'/", oku('personel.php') . oku('personel_form.php') . oku('personel_kartlar.php')));

echo "\n=== 8. FOTOÄRAF ENDPOINT'Ä° â€” PATH TRAVERSAL'A KAPALI ===\n";
// Not: aranan desenin kendisi bir regex olduÄŸu iÃ§in (a-f0-9{32}.jpg), burada
// regex-iÃ§inde-regex yazmak yerine kaynakta AYNEN geÃ§en sabit alt-diziyi arÄ±yoruz
// â€” daha az kÄ±rÄ±lgan, niyeti daha aÃ§Ä±k.
$guvenliAdDeseni = '^[a-f0-9]{32}\.jpg$';
$fotoSrc = oku('personel_foto.php');
ok('dosya adÄ± doÄŸrulamasÄ± var (32 hex + .jpg)',
    str_contains($fotoSrc, $guvenliAdDeseni));
ok('doÄŸrulamadan SONRA $_GET doÄŸrudan dosya yoluna eklenmiyor (PDKS_FOTO_DIR . $fn kalÄ±bÄ±)',
    str_contains($fotoSrc, 'PDKS_FOTO_DIR . $fn'));
ok('dosyanÄ±n GERÃ‡EKTEN bir employees satÄ±rÄ±na ait olduÄŸu DB\'den doÄŸrulanÄ±yor (yalnÄ±z disk varlÄ±ÄŸÄ± yetmiyor)',
    str_contains($fotoSrc, 'SELECT id FROM employees WHERE photo_file'));
$fotoSilBlok = '';
if (preg_match('/function pdks_foto_sil\b.*?\n}/s', $pdksSrc, $mm)) $fotoSilBlok = $mm[0];
ok('pdks_foto_sil() de AYNI gÃ¼venli-ad deseniyle korunuyor',
    $fotoSilBlok !== '' && str_contains($fotoSilBlok, $guvenliAdDeseni));

echo "\n=== 9. TC KÄ°MLÄ°K NUMARASI HÄ°Ã‡BÄ°R YERDE Ä°STENMÄ°YOR (onaylanan karar #2) ===\n";
$aranan = ['tc_kimlik', 'national_id', 'tckn', 'kimlik_no'];
foreach ($aranan as $a) {
    ok("'$a' alanÄ± YOK", stripos($tumIcerik, $a) === false);
}

echo "\n=== 10. MEVCUT SÄ°STEM DAVRANIÅI DEÄÄ°ÅMEDÄ° ===\n";
// index.php ve config/helpers.php BÄ°LEREK bu listenin DIÅINDA: navigasyon
// baÄŸlama (Â§7 gereÄŸi) ikisine de birer kÃ¼Ã§Ã¼k, katkÄ±lÄ± (additive) ekleme
// yapar. Burada "hiÃ§ deÄŸiÅŸmedi" deÄŸil "yalnÄ±z BEKLENEN ÅŸekilde deÄŸiÅŸti"
// doÄŸrulanÄ±r â€” tek CSS/JS ve Ã§ekirdek auth/db katmanÄ± asÄ±l korunmasÄ±
// gereken yerlerdir.
// Faz 7: sw.js bu listeden Ã‡IKARILDI â€” assets/print_base.css'e yazdÄ±rma
// sayfalarÄ± iÃ§in ek kural eklendiÄŸi iÃ§in CLAUDE.md kuralÄ± gereÄŸi SW
// CACHE_NAME (ve config/helpers.php'deki eÅŸlenik APP_SURUM) v217'den
// v218'e Ã§ekildi. Bu TEK SATIRLIK, RUTÄ°N sÃ¼rÃ¼m damgasÄ± gÃ¼ncellemesi â€”
// iÃ§erik/mantÄ±k kaybÄ± DEÄÄ°L; sw.js'in kendi Ã¶nbellekleme mantÄ±ÄŸÄ±
// (network-first fetch stratejisi, SHELL listesi) hiÃ§ deÄŸiÅŸmedi, yalnÄ±z
// sabit sÃ¼rÃ¼m dizesi arttÄ±. tek-CSS/JS kuralÄ± (style.css/app.js) ve
// Ã§ekirdek auth/db katmanÄ± (db.php/auth.php) hÃ¢lÃ¢ TAM korunuyor.
$dokunulmamali = ['assets/style.css', 'assets/app.js', 'config/db.php', 'config/auth.php'];
$gitDurum = shell_exec('cd ' . escapeshellarg($KOK) . ' && git status --porcelain -- ' . implode(' ', array_map('escapeshellarg', $dokunulmamali)) . ' 2>&1');
ok('style.css / app.js / db.php / auth.php DEÄÄ°ÅMEDÄ° (tek-CSS/JS ve Ã§ekirdek auth korunuyor)',
    trim((string)$gitDurum) === '', (string)$gitDurum);
// git diff'e DEÄÄ°L, doÄŸrudan mevcut dosya iÃ§eriÄŸine bakÄ±lÄ±r â€” bu kontrol
// hem iÅŸlenmemiÅŸ (dirty) Ã¶zellik dalÄ±nda hem de commit/merge SONRASI temiz
// bir checkout'ta (git diff boÅŸ dÃ¶ner, hiÃ§bir ÅŸey KANITLAMAZ) aynÄ± ÅŸekilde
// anlamlÄ± kalsÄ±n diye.
$swSrc = oku('sw.js');
ok('sw.js: CACHE_NAME sÃ¼rÃ¼mÃ¼ v220 (helpers.php\'deki APP_SURUM ile eÅŸlenik)',
    str_contains($swSrc, "const CACHE_NAME = 'yukleme-plani-v220';"));
ok('sw.js: SHELL Ã¶nbellek listesi / network-first fetch stratejisi AYNI (yalnÄ±z sÃ¼rÃ¼m sabiti deÄŸiÅŸti)',
    str_contains($swSrc, "'./assets/hesap.js'") && str_contains($swSrc, "fetch(e.request).then(function(response)"));

// index.php Ä°Ã‡Ä°N: bu turdan (Faz 7, Sprint Navigasyon-01) Ã–NCE deÄŸiÅŸti
// (nav baÄŸlama), ama yalnÄ±z EKLEME olarak â€” mevcut hiÃ§bir satÄ±r
// silinmedi/deÄŸiÅŸtirilmedi. Faz 7 kullanÄ±cÄ±nÄ±n AÃ‡IK talimatÄ±yla ("Connect/
// add the existing mobile 'Personel' button... to personel_takip.php")
// TEK bir mevcut kartÄ± (Personel â†’ personel.php) KASITLI olarak
// personel_takip.php'ye yeniden yÃ¶nlendirdi + gÃ¶rÃ¼nÃ¼rlÃ¼ÄŸÃ¼nÃ¼ geniÅŸletti â€”
// bu BEÅ satÄ±r bu YÃœZDEN allowlist'e alÄ±ndÄ±; bunun DIÅINDA index.php'de
// hiÃ§bir satÄ±r silinmemiÅŸ olmalÄ±.
$diffIndex = shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- index.php 2>&1');
$silinenIndex = array_filter(explode("\n", (string)$diffIndex), function ($l) {
    return preg_match('/^-(?!--)/', $l) === 1;   // '-' ile baÅŸlayan ama '---' baÅŸlÄ±ÄŸÄ± olmayan satÄ±r
});
$indexBeklenenEskiSatirlar = [
    "-<?php if (can('attendance.employees') || can('attendance.cards') || is_admin()): ?>",
    '-    <a href="personel.php" class="home-card">',
    '-        <div class="home-card-icon" style="background:#eef2ff">ğŸ‘¤</div>',
    '-        <div class="home-card-title">Personel</div>',
    '-        <div class="home-card-sub">Personel ve kart yÃ¶netimi</div>',
];
$indexBeklenmeyenSilinen = array_filter($silinenIndex, fn($l) => !in_array(trim($l), array_map('trim', $indexBeklenenEskiSatirlar), true));
ok('index.php: YALNIZ Faz 7\'nin bilinen "Personel" kart deÄŸiÅŸikliÄŸi silindi, baÅŸka hiÃ§bir satÄ±r silinmedi',
    count($indexBeklenmeyenSilinen) === 0,
    count($indexBeklenmeyenSilinen) . ' beklenmeyen satÄ±r silinmiÅŸ gÃ¶rÃ¼nÃ¼yor: ' . implode(' | ', $indexBeklenmeyenSilinen));

// config/helpers.php Ä°Ã‡Ä°N: GiriÅŸ-Ã‡Ä±kÄ±ÅŸ fazÄ±, Personel bÃ¶lÃ¼mÃ¼nÃ¼n gÃ¶rÃ¼nÃ¼rlÃ¼k
// koÅŸulunu (Â§11) KASITLI olarak GENÄ°ÅLETTÄ° â€” yalnÄ±z attendance.scan yetkisi
// olan (employees/cards YOK) bir kullanÄ±cÄ± da artÄ±k "Personel" bÃ¶lÃ¼m
// baÅŸlÄ±ÄŸÄ±nÄ± gÃ¶rmeli, aksi hÃ¢lde GiriÅŸ/Ã‡Ä±kÄ±ÅŸ linki kimseye gÃ¶rÃ¼nmezdi. Bu
// YÃœZDEN o TEK satÄ±rÄ±n deÄŸiÅŸtirilmesi (silinip yeniden yazÄ±lmasÄ±) burada
// BEKLENEN ve Ä°NCELENMÄ°Å bir deÄŸiÅŸikliktir â€” "hiÃ§ silinmesin" kuralÄ±
// YALNIZ bu bilinen satÄ±r iÃ§in gevÅŸetilir, baÅŸka hiÃ§bir satÄ±r iÃ§in deÄŸil.
// Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-01: Ã§avuÅŸ/iÅŸÃ§i-kart-havuzu izinleri (attendance.foremen,
// attendance.worker_cards) Ä°KÄ° mevcut izin dizisine (admin'in $pdks_p'si,
// 'ik' rolÃ¼nÃ¼n listesi) EKLENDÄ° â€” Ã§ok satÄ±rlÄ± literal olduÄŸu iÃ§in git diff
// bu satÄ±rlarÄ± "silinip yeniden yazÄ±lmÄ±ÅŸ" gÃ¶sterir, Ä°Ã‡ERÄ°K KAYBI deÄŸil.
// AynÄ± AZ-Ä°STÄ°SNA yaklaÅŸÄ±mÄ±: yalnÄ±z BÄ°LÄ°NEN, Ä°NCELENMÄ°Å satÄ±rlar allowlist'e
// eklenir, baÅŸka hiÃ§bir satÄ±r iÃ§in gevÅŸetilmez.
$beklenenEskiSatirlar = [
    "-    \$p_pdks  = (\$_fn && (can('attendance.employees') || can('attendance.cards'))) || \$p_adm;",
    "-                       'attendance.devices','attendance.admin'];",
    "-                               'attendance.report','attendance.employees','attendance.cards'],",
    // Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-01 â†’ GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-02 (Faz 2, seri GiriÅŸ/Ã‡Ä±kÄ±ÅŸ):
    // AYNI Ã¼Ã§ Ã§ok satÄ±rlÄ± literal BÄ°R KEZ DAHA geniÅŸletildi
    // (attendance.daily_scan eklendi) â€” git diff bu satÄ±rlarÄ± da "silinip
    // yeniden yazÄ±lmÄ±ÅŸ" gÃ¶sterir, Ä°Ã‡ERÄ°K KAYBI deÄŸil.
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards'))) || \$p_adm;",
    "-                       'attendance.foremen','attendance.worker_cards'];",
    "-                               'attendance.foremen','attendance.worker_cards'],",
    // Faz 2 dÃ¼zeltme turu (kullanÄ±cÄ±nÄ±n aÃ§Ä±k talimatÄ± #2 â€” gÃ¼venlik/operasyon
    // rolÃ¼): 'operator' rolÃ¼nÃ¼n tek satÄ±rlÄ±k literal dizisine YALNIZ
    // attendance.daily_scan eklendi (Ã§avuÅŸ/kart/muhasebe/admin izni YOK) â€”
    // git diff bu TEK satÄ±rÄ± da "silinip yeniden yazÄ±lmÄ±ÅŸ" gÃ¶sterir.
    "-                'operator' => ['dashboard.read','records.read','records.write','records.lock','kantar.read','kantar.write','stok.read','stok.write','defs.read','reports.read','reports.export','beyan.read','beyan.write','maliyet.read','maliyet.write','hesap.read','hesap.write'],",
    // Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-02 â†’ GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-04 (Faz 3, gÃ¼nlÃ¼k puantaj raporlarÄ±):
    // AYNI Ã¼Ã§ Ã§ok satÄ±rlÄ± literal + 'muhasebe' rolÃ¼ TEK yeni izinle
    // (attendance.daily_reports) geniÅŸletildi â€” 'operator' BÄ°LEREK BUNA
    // DOKUNULMADI (gÃ¶rev talimatÄ±: "Do NOT automatically give full
    // historical reporting" â€” bkz. yukarÄ±daki operator satÄ±rÄ±, hÃ¢lÃ¢ AYNI).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan'))) || \$p_adm;",
    "-                       'attendance.foremen','attendance.worker_cards','attendance.daily_scan'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay'],",
    "-                               'attendance.foremen','attendance.worker_cards','attendance.daily_scan'],",
    // Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-04 â†’ GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-05 (Faz 4, hakediÅŸ): AYNI Ã¼Ã§ Ã§ok
    // satÄ±rlÄ± literal + 'muhasebe' rolÃ¼ Ä°KÄ° yeni izinle (attendance.
    // foreman_rates, attendance.entitlements) geniÅŸletildi; 'ik' rolÃ¼
    // YALNIZ attendance.entitlements aldÄ± (foreman_rates ALMADI â€” ticari
    // fiyat yÃ¶netimi kapsam dÄ±ÅŸÄ±). 'operator' BÄ°LEREK BUNA DA DOKUNULMADI
    // (gÃ¶rev talimatÄ±: "Do not expose prices or hakediÅŸ amounts on the
    // security scanning screen." â€” bkz. yukarÄ±daki operator satÄ±rÄ±, hÃ¢lÃ¢ AYNI).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports'))) || \$p_adm;",
    "-                       'attendance.daily_reports'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports'],",
    "-                               'attendance.daily_reports'],",
    // Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-05 â†’ GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-06 (Faz 5, cari hesap/Ã¶deme): AYNI Ã¼Ã§
    // Ã§ok satÄ±rlÄ± literal + 'muhasebe' rolÃ¼ Ä°KÄ° yeni izinle (attendance.
    // foreman_accounts, attendance.foreman_payments) geniÅŸletildi; 'ik' rolÃ¼
    // BÄ°LEREK BUNA DA DOKUNULMADI (gÃ¶rev talimatÄ±: "ik: NO payment management
    // by default" â€” belirsizlikte hesap gÃ¶rÃ¼ntÃ¼leme de verilmedi). 'operator'
    // yine DOKUNULMADI (aynÄ± gerekÃ§e: finansal ekranlar operatÃ¶re kapalÄ±).
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports') || can('attendance.foreman_rates') || can('attendance.entitlements'))) || \$p_adm;",
    "-                       'attendance.daily_reports','attendance.foreman_rates','attendance.entitlements'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements'],",
    // Sprint GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-06 â†’ GÃ¼nlÃ¼k-Ä°ÅŸÃ§i-07 (Faz 6, yÃ¶netim raporlama
    // merkezi): AYNI Ã¼Ã§ Ã§ok satÄ±rlÄ± literal + 'muhasebe' VE 'ik' rolleri
    // TEK yeni izinle (attendance.management_reports) geniÅŸletildi â€” 'ik'
    // BU SEFER BÄ°LEREK DAHÄ°L EDÄ°LDÄ° (gÃ¶rev talimatÄ±: "ik = YES if
    // operational reporting is appropriate"), ama attendance.foreman_accounts
    // HÃ‚LÃ‚ ALMADI (Faz 5'in kararÄ± korunuyor â€” raporlar.php'nin finansal
    // bÃ¶lÃ¼mleri 'ik'e yine KAPALI, bkz. pdks_rapor_static_smoke.php Â§4).
    // 'operator' yine DOKUNULMADI.
    "-    \$p_gunluk = (\$_fn && (can('attendance.foremen') || can('attendance.worker_cards') || can('attendance.daily_scan') || can('attendance.daily_reports') || can('attendance.foreman_rates') || can('attendance.entitlements') || can('attendance.foreman_accounts') || can('attendance.foreman_payments'))) || \$p_adm;",
    "-                       'attendance.foreman_accounts','attendance.foreman_payments'];",
    "-                'muhasebe' => ['dashboard.read','records.read','stok.read','reports.read','reports.export','beyan.read','maliyet.read','maliyet.write','hesap.read','hesap.write','hesap.approve','hesap.pay','attendance.daily_reports','attendance.foreman_rates','attendance.entitlements','attendance.foreman_accounts','attendance.foreman_payments'],",
    "-                               'attendance.daily_reports','attendance.entitlements'],",
    // Sprint Navigasyon-01 (Faz 7, kullanÄ±cÄ±nÄ±n aÃ§Ä±k talimatÄ±: "Replace
    // these scattered sidebar entries with ONE primary entry"): Ã–NCEKÄ°
    // turlardan FARKLI olarak bu SATIR YENÄ°DEN YAZMA DEÄÄ°L, BÄ°LEREK,
    // KAPSAMLI bir SÄ°LMEDÄ°R â€” Personel + GÃ¼nlÃ¼k Ä°ÅŸÃ§i bÃ¶lÃ¼mlerindeki 12 ayrÄ±
    // link (ve onlarÄ±n $a_* aktif-sayfa deÄŸiÅŸkenleri) TEK bir
    // "Personel Takibi" (personel_takip.php) linkine indirildi. HiÃ§bir
    // SAYFA silinmedi (gÃ¶rev talimatÄ±: "This is a navigation consolidation
    // layer... Existing URLs/pages remain authoritative and accessible.") â€”
    // yalnÄ±z SIDEBAR gÃ¶rÃ¼nÃ¼rlÃ¼ÄŸÃ¼ deÄŸiÅŸti; her hedef sayfa KENDÄ° yetki
    // kontrolÃ¼nÃ¼ hÃ¢lÃ¢ taÅŸÄ±r (bkz. pdks_takip_static_smoke.php Â§10).
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
    "-        <?php if (\$_fn && (can('attendance.employees') || \$p_adm)) \$lnk('personel.php', 'ğŸ‘¤', 'Personeller', \$a_pdksp); ?>",
    "-        <?php if (\$_fn && (can('attendance.cards')     || \$p_adm)) \$lnk('personel_kartlar.php', 'ğŸªª', 'Kart YÃ¶netimi', \$a_pdksk); ?>",
    "-        <?php if (\$_fn && (can('attendance.scan')      || \$p_adm)) \$lnk('giris_cikis.php', 'ğŸšª', 'GiriÅŸ / Ã‡Ä±kÄ±ÅŸ', \$a_pdksg); ?>",
    "-        <?php endif; ?>",
    "-",
    "-        <?php if (\$p_gunluk): ?>",
    "-        <div class=\"sidebar-section\">GÃ¼nlÃ¼k Ä°ÅŸÃ§i</div>",
    "-        <?php if (\$_fn && (can('attendance.foremen')      || \$p_adm)) \$lnk('cavuslar.php',      'ğŸ‘·', 'Ã‡avuÅŸlar',      \$a_cavus); ?>",
    "-        <?php if (\$_fn && (can('attendance.worker_cards') || \$p_adm)) \$lnk('isci_kartlari.php', 'ğŸªª', 'Ä°ÅŸÃ§i KartlarÄ±', \$a_isk); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_scan')   || \$p_adm)) \$lnk('gunluk_isci_giris_cikis.php', 'ğŸšª', 'GiriÅŸ / Ã‡Ä±kÄ±ÅŸ', \$a_gunlukgc); ?>",
    "-        <?php if (\$_fn && (can('attendance.daily_reports') || \$p_adm)) \$lnk('gunluk_isci_puantaj.php', 'ğŸ“…', 'GÃ¼nlÃ¼k Puantaj', \$a_puantaj); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_rates')  || \$p_adm)) \$lnk('cavus_fiyatlari.php', 'ğŸ’°', 'Ã‡avuÅŸ FiyatlarÄ±', \$a_fiyat); ?>",
    "-        <?php if (\$_fn && (can('attendance.entitlements')   || \$p_adm)) \$lnk('cavus_hakedis.php',   'ğŸ§¾', 'HakediÅŸ',         \$a_hakedis); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_payments') || \$p_adm)) \$lnk('cavus_odeme.php', 'ğŸ’¸', 'Ã‡avuÅŸ Ã–deme', \$a_odeme); ?>",
    "-        <?php if (\$_fn && (can('attendance.foreman_accounts') || \$p_adm)) \$lnk('cavus_cari.php',  'ğŸ“’', 'Ã‡avuÅŸ Cari',  \$a_cari); ?>",
    "-        <?php if (\$_fn && (can('attendance.management_reports') || \$p_adm)) \$lnk('raporlar.php', 'ğŸ“Š', 'YÃ¶netim RaporlarÄ±', \$a_yrapor); ?>",
    // Faz 7: assets/print_base.css'e yeni yazdÄ±rma sayfalarÄ± iÃ§in ek kural
    // eklendi (thead tekrarÄ±) â†’ SW cache sÃ¼rÃ¼mÃ¼ + APP_SURUM birlikte v217'den
    // v218'e Ã§ekildi (CLAUDE.md kuralÄ±: "SW cache versiyonu artÄ±rÄ±ldÄ± mÄ±?").
    // Tek satÄ±rlÄ±k sÃ¼rÃ¼m sabiti gÃ¼ncellemesi, iÃ§erik kaybÄ± DEÄÄ°L.
    "-    define('APP_SURUM', 'v217');",
    // Faz 8A PRE-MERGE GÃœVENLÄ°K DÃœZELTMESÄ°: config/pdks_gunluk.php (Ã¼Ã§
    // durumlu status modeli) + assets/pdks.css (yeni rozet rengi) deÄŸiÅŸti
    // â†’ SW cache sÃ¼rÃ¼mÃ¼ + APP_SURUM birlikte v218'den v219'a Ã§ekildi (AYNI
    // rutin, tek satÄ±rlÄ±k sÃ¼rÃ¼m damgasÄ± gÃ¼ncellemesi â€” bkz. yukarÄ±daki
    // v217â†’v218 emsali).
    "-    define('APP_SURUM', 'v218');",
    "-    define('APP_SURUM', 'v219');",
];
$diffHelpers = shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- config/helpers.php 2>&1');
$silinenHelpers = array_filter(explode("\n", (string)$diffHelpers), function ($l) {
    return preg_match('/^-(?!--)/', $l) === 1;
});
$beklenmeyenSilinen = array_filter($silinenHelpers, fn($l) => !in_array(trim($l), array_map('trim', $beklenenEskiSatirlar), true));
ok('config/helpers.php: YALNIZ BÄ°LÄ°NEN/Ä°NCELENMÄ°Å satÄ±rlar deÄŸiÅŸti (attendance.scan geniÅŸlemesi + GÃ¼nlÃ¼k Ä°ÅŸÃ§i izin ekleri), baÅŸka hiÃ§bir satÄ±r silinmedi',
    count($beklenmeyenSilinen) === 0,
    count($beklenmeyenSilinen) . " beklenmeyen silinen satÄ±r:\n" . implode("\n", $beklenmeyenSilinen));

echo "\n";
printf("SONUÃ‡: %d test geÃ§ti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
