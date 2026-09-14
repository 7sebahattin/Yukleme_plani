<?php
// =========================================================
// scripts/pdks_faz1b_static_smoke.php — PDKS Faz 1B statik kural testi
//
// SADECE CLI. Ağ yok, DB yok — kaynak kodda regex ile kural arar
// (beyan_bildirim_smoke.php ile aynı desen). Şunları KANITLAR:
//
//  1) Her POST işleyen dosya csrf_check() çağırıyor.
//  2) Her sayfa require_pdks() ile yetki kapısından geçiyor.
//  3) FAZ 1B KAPSAM DIŞI olan şeyler kod tabanına SIZMADI:
//     - attendance_events / ENTRY-EXIT hareket motoru yok
//     - Android/NFC ÜRETİM kodu yok (yalnız Faz 0'ın teşhis APK'si tools/ altında,
//       o da üretim değil)
//     - api_pdks.php (Faz 2'nin API'si) yok
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

echo "\n=== 5. FAZ 1B KAPSAM DIŞI OLAN ŞEYLER SIZMADI ===\n";
$tumIcerik = '';
foreach ($sayfalar as $s) $tumIcerik .= "\n" . oku($s);
$tumIcerik .= "\n" . oku('config/pdks.php');

ok('attendance_events tablosuna SQL yazımı KULLANILMIYOR (Faz 2 kapsamı)',
    !preg_match('/\bINSERT\s+INTO\s+attendance_events\b/i', $tumIcerik));
ok('ENTRY/EXIT (giriş/çıkış hareket) mantığı YOK',
    !preg_match('/\b(event_type|entry_gate|proposed_type)\b/i', $tumIcerik));
ok('api_pdks.php (Faz 2 API\'si) OLUŞTURULMADI',
    !file_exists($KOK . '/api_pdks.php'));
ok('attendance_devices/attendance_api_sessions (Faz 2 cihaz kaydı) KULLANILMIYOR',
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
$dokunulmamali = ['assets/style.css', 'assets/app.js', 'sw.js', 'config/db.php', 'config/auth.php'];
$gitDurum = shell_exec('cd ' . escapeshellarg($KOK) . ' && git status --porcelain -- ' . implode(' ', array_map('escapeshellarg', $dokunulmamali)) . ' 2>&1');
ok('style.css / app.js / sw.js / db.php / auth.php DEĞİŞMEDİ (tek-CSS/JS ve çekirdek auth korunuyor)',
    trim((string)$gitDurum) === '', (string)$gitDurum);

// index.php ve config/helpers.php İÇİN: değiştiler (nav bağlama), ama
// yalnız EKLEME olarak — mevcut hiçbir satır silinmedi/değiştirilmedi.
foreach (['index.php', 'config/helpers.php'] as $navDosya) {
    $diffOut = shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff -- ' . escapeshellarg($navDosya) . ' 2>&1');
    $silinenSatirlar = array_filter(explode("\n", (string)$diffOut), function ($l) {
        return preg_match('/^-(?!--)/', $l) === 1;   // '-' ile başlayan ama '---' başlığı olmayan satır
    });
    ok("$navDosya: nav bağlama yalnız EKLEME (silinen satır yok)", count($silinenSatirlar) === 0,
        count($silinenSatirlar) . ' satır silinmiş görünüyor');
}

echo "\n";
printf("SONUÇ: %d test geçti, %d hata.\n\n", $gecen, $fail);
exit($fail === 0 ? 0 : 1);
