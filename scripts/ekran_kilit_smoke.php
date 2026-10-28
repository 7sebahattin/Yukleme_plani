<?php
// =========================================================
// scripts/ekran_kilit_smoke.php — Masaüstü ekran kilidi (v454)
// SADECE CLI. Bellek içi SQLite; canlı DB'ye dokunmaz. config/ekran_kilit.php'nin GERÇEK
// fonksiyonları + kaynak/bağlantı denetimleri. Tarayıcı davranışı: node scripts/ekran_kilit_smoke.js
//   php scripts/ekran_kilit_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();   // CLI'da session_start() "headers already sent" uyarısı vermesin

$KOK = dirname(__DIR__);
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function base_url(): string { return '/'; }
require_once "$KOK/config/ekran_kilit.php";
session_id('ekrankilitsmoke'); session_start();

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    fwrite(STDOUT, sprintf("%-76s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu));
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, is_active INTEGER)');
$db->prepare('INSERT INTO users VALUES (1, ?, 1)')->execute([password_hash('dogru-sifre', PASSWORD_DEFAULT)]);
$db->prepare('INSERT INTO users VALUES (2, ?, 0)')->execute([password_hash('pasif-sifre', PASSWORD_DEFAULT)]);

// ── A) Durum + açma ──────────────────────────────────────────
$_SESSION = [];
ok('A1 başlangıçta kilitli değil', ekran_kilitli_mi() === false);
ekran_kilitle();
ok('A2 kilitle → kilitli', ekran_kilitli_mi() === true);
$r = ekran_kilit_ac($db, 1, 'yanlis', 1000);
ok('A3 yanlış şifre → ok=false, hâlâ kilitli', $r['ok'] === false && $r['mesaj'] === 'Şifre hatalı.' && ekran_kilitli_mi());
$r = ekran_kilit_ac($db, 1, '', 1000);
ok('A4 boş şifre → reddedilir, hata sayacı artmaz', $r['ok'] === false && ($_SESSION['ekran_kilit_hata'] ?? 0) === 1);
$r = ekran_kilit_ac($db, 1, 'dogru-sifre', 1001);
ok('A5 doğru şifre → açılır, bayraklar temizlenir',
    $r['ok'] === true && !ekran_kilitli_mi() && !isset($_SESSION['ekran_kilit_hata']) && !isset($_SESSION['ekran_kilit_bekle']));

// ── B) Yanlış şifre sınırı ───────────────────────────────────
$_SESSION = []; ekran_kilitle();
for ($i = 1; $i <= 4; $i++) $r = ekran_kilit_ac($db, 1, 'x' . $i, 2000);
ok('B1 4 hata → henüz bekleme yok', $r['bekle'] === 0 && $r['mesaj'] === 'Şifre hatalı.');
$r = ekran_kilit_ac($db, 1, 'x5', 2000);
ok('B2 5. hata → ' . EKRAN_KILIT_BEKLEME_SN . ' sn bekleme', $r['ok'] === false && $r['bekle'] === EKRAN_KILIT_BEKLEME_SN);
$r = ekran_kilit_ac($db, 1, 'dogru-sifre', 2010);
ok('B3 bekleme sürerken DOĞRU şifre bile reddedilir', $r['ok'] === false && $r['bekle'] === 20 && ekran_kilitli_mi());
$r = ekran_kilit_ac($db, 1, 'dogru-sifre', 2031);
ok('B4 bekleme bitince doğru şifre açar', $r['ok'] === true && !ekran_kilitli_mi());

// ── C) Hesap durumları ───────────────────────────────────────
$_SESSION = []; ekran_kilitle();
ok('C1 pasif kullanıcı doğru şifreyle de açamaz', ekran_kilit_ac($db, 2, 'pasif-sifre', 3000)['ok'] === false);
ok('C2 olmayan kullanıcı açamaz', ekran_kilit_ac($db, 99, 'dogru-sifre', 3000)['ok'] === false);
ok('C3 kullanıcı id 0 açamaz', ekran_kilit_ac($db, 0, 'dogru-sifre', 3000)['ok'] === false);
$bozuk = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = @ekran_kilit_ac($bozuk, 1, 'dogru-sifre', 3000);
ok('C4 DB hatası → kilit AÇILMAZ (fail-closed)', $r['ok'] === false && ekran_kilitli_mi());

// ── D) İşaretleme ────────────────────────────────────────────
$_SESSION = [];
$html = ekran_kilit_html(['display_name' => 'Ali <b>x</b>', 'username' => 'ali']);
ok('D1 dialog + form + şifre alanı', str_contains($html, '<dialog id="ekranKilit"') && str_contains($html, 'id="ekranKilitForm"') && str_contains($html, 'type="password"'));
ok('D2 kullanıcı adı kaçırılır (XSS yok)', !str_contains($html, '<b>x</b>') && str_contains($html, 'Ali &lt;b&gt;x&lt;/b&gt;'));
ok('D3 kilitli değilken data-kilitli YOK', !str_contains($html, 'data-kilitli'));
ekran_kilitle();
ok('D4 kilitliyken data-kilitli="1"', str_contains(ekran_kilit_html(['username' => 'ali']), 'data-kilitli="1"'));
ok('D5 oturumsuz kullanıcıda işaretleme basılmaz', ekran_kilit_html(null) === '');
ok('D6 görünen ad yoksa kullanıcı adı', str_contains(ekran_kilit_html(['display_name' => '', 'username' => 'ali']), '<b>ali</b>'));
ok('D7 uç + login adresleri data-* ile', str_contains($html, 'data-uc="/ekran_kilit.php"') && str_contains($html, 'data-login="/login.php"'));

// ── E) Bağlantılar + kaynak denetimleri ──────────────────────
$uc  = file_get_contents("$KOK/ekran_kilit.php");
$cor = file_get_contents("$KOK/config/ekran_kilit.php");
$hlp = file_get_contents("$KOK/config/helpers.php");
$js  = file_get_contents("$KOK/assets/app.js");
$css = file_get_contents("$KOK/assets/style.css");
$sw  = file_get_contents("$KOK/sw.js");
ok('E1 uç: oturum + POST + CSRF kapısı', str_contains($uc, 'current_user()') && str_contains($uc, "!== 'POST'") && str_contains($uc, 'csrf_check('));
ok('E2 şifre audit/log\'a yazılmaz', !preg_match('/audit_log_event\([^;]*sifre/i', $uc) && !preg_match('/(error_log|audit_log_event)\([^;]*\$sifre/', $cor));
ok('E3 401 oturum yoksa', str_contains($uc, ", 401)"));
ok('E4 helpers: require + footer + header sınıfı',
    str_contains($hlp, "require_once __DIR__ . '/ekran_kilit.php'") && str_contains($hlp, 'ekran_kilit_html(current_user())') && str_contains($hlp, 'ekran-kilitli'));
ok('E5 istemci: yalnız masaüstü (≥900 + fare) ve kiosk muaf',
    str_contains($js, '(min-width: 900px) and (hover: hover) and (pointer: fine)') && str_contains($js, '.pdks-kiosk'));
ok('E6 istemci: 5 dk varsayılan + Esc kapatmaz', str_contains($js, ': 300000') && str_contains($js, "'cancel'"));
ok('E7 css: kilit yalnız masaüstü medya sorgusunda gizler',
    (bool)preg_match('/@media \(min-width: 900px\) and \(hover: hover\) and \(pointer: fine\) \{\s*body\.ekran-kilitli/', $css) && str_contains($css, 'dialog.ekran-kilit::backdrop'));
preg_match("/define\('APP_SURUM', '(v\d+)'/", $hlp, $m1); preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $m2);
ok('E8 APP_SURUM = sw.js cache sürümü', !empty($m1[1]) && ($m1[1] ?? '') === ($m2[1] ?? 'x'), ($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?'));

fwrite(STDOUT, $fail ? "\n$fail TEST BAŞARISIZ\n" : "\nTÜM TESTLER GEÇTİ\n");
exit($fail ? 1 : 0);
