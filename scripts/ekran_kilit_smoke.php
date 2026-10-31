<?php
// =========================================================
// scripts/ekran_kilit_smoke.php — PIN'li masaüstü ekran kilidi (v455)
// SADECE CLI. Bellek içi SQLite; canlı DB'ye dokunmaz. config/ekran_kilit.php'nin GERÇEK
// fonksiyonları + kaynak/bağlantı denetimleri. Tarayıcı davranışı: node scripts/ekran_kilit_smoke.js
//   php scripts/ekran_kilit_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();   // CLI'da setcookie "headers already sent" vermesin

$KOK = dirname(__DIR__);
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function base_url(): string { return '/'; }
const AUTH_COOKIE_NAME = 'asya_session';
const HKS_CRED_KEY_TEST = 1;
define('HKS_CRED_KEY', str_repeat('k', 32));
require_once "$KOK/config/ekran_kilit.php";

$fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail;
    if (!$c) $fail++;
    fwrite(STDOUT, sprintf("%-78s %s%s\n", $ad, $c ? 'OK' : '*** FAIL', $c ? '' : '  ' . $ipucu));
}
function yeni_db(bool $kv = true): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($kv) $db->exec('CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)');
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, is_active INTEGER)');
    $db->prepare('INSERT INTO users VALUES (1, ?, 1)')->execute([password_hash('dogru-sifre', PASSWORD_DEFAULT)]);
    $db->prepare('INSERT INTO users VALUES (2, ?, 0)')->execute([password_hash('pasif-sifre', PASSWORD_DEFAULT)]);
    return $db;
}
function kayit(PDO $db, int $uid = 1): ?array {
    $d = $db->query("SELECT deger FROM hks_kv WHERE anahtar = 'ekran_pin_$uid'")->fetchColumn();
    return $d ? json_decode($d, true) : null;
}
function yanlis_n(PDO $db, int $n, int $simdi, string $pin = '9999'): array {
    $r = [];
    for ($i = 0; $i < $n; $i++) $r = ekran_kilit_ac($db, 1, $pin, $simdi);
    return $r;
}

// ── A) Çerez ─────────────────────────────────────────────────
$tok = str_repeat('ab', 32);
$tok2 = str_repeat('cd', 32);
$_COOKIE = ['asya_session' => $tok];
ok('A1 başlangıçta kilitli değil', ekran_kilitli_mi() === false);
ok('A2 kilitle → true + oturum başlatılmadı', ekran_kilitle() === true && ekran_kilitli_mi() === true && session_status() === PHP_SESSION_NONE);
ok('A3 çerez biçimi 1.<hmac>', (bool)preg_match('/^1\.[0-9a-f]{64}$/', $_COOKIE['asya_kilit'] ?? ''));
ok('A4 farklı oturum token\'ı → kilitli değil', ekran_kilitli_mi($tok2) === false);
ok('A5 kurcalanmış çerez → false', ekran_kilitli_mi($tok, '1.' . str_repeat('0', 64)) === false && ekran_kilitli_mi($tok, '') === false);
ekran_kilit_temizle();
ok('A6 temizle sonrası false', ekran_kilitli_mi() === false && !isset($_COOKIE['asya_kilit']));
ok('A7 token geçersizse kilitle false', ekran_kilitle('kisa') === false);

// ── B) PIN kur / aç ──────────────────────────────────────────
$db = yeni_db();
ok('B0 PIN yokken durum yok', ekran_pin_durum($db, 1) === 'yok');
ok('B1 123 reddedilir', ekran_pin_kur($db, 1, '123', '123')['kod'] === 'gecersiz');
ok('B2 123456789 reddedilir', ekran_pin_kur($db, 1, '123456789', '123456789')['kod'] === 'gecersiz');
ok('B3 12a4 reddedilir', ekran_pin_kur($db, 1, '12a4', '12a4')['kod'] === 'gecersiz');
ok('B4 boş reddedilir', ekran_pin_kur($db, 1, '', '')['kod'] === 'gecersiz');
ok('B5 pin2 farklı → eslesmiyor', ekran_pin_kur($db, 1, '1234', '1235')['kod'] === 'eslesmiyor');
ok('B6 1234 kabul', ekran_pin_kur($db, 1, '1234', '1234')['ok'] === true && ekran_pin_durum($db, 1) === 'var');
$ham = (string)$db->query("SELECT deger FROM hks_kv WHERE anahtar='ekran_pin_1'")->fetchColumn();
ok('B7 depoda hash var, düz PIN yok', str_contains($ham, '"h"') && !str_contains($ham, '1234'));
ok('B8 ikinci kur → var', ekran_pin_kur($db, 1, '5678', '5678')['kod'] === 'var');
$db2 = yeni_db();
ok('B9 0000 ve 12345678 kabul', ekran_pin_kur($db2, 1, '0000', '0000')['ok'] && ekran_pin_kur($db2, 2, '12345678', '12345678')['ok']);
$_COOKIE['asya_session'] = $tok; ekran_kilitle();
$r = ekran_kilit_ac($db, 1, '1234', 1000);
ok('B10 doğru PIN → ok + çerez silinir', $r['ok'] === true && !ekran_kilitli_mi());
ekran_kilitle();
$r = ekran_kilit_ac($db, 1, '4321', 1000);
ok('B11 yanlış PIN → yanlis, hâlâ kilitli', $r['ok'] === false && $r['kod'] === 'yanlis' && $r['mesaj'] === 'PIN hatalı.' && ekran_kilitli_mi());
ok('B12 biçimsiz PIN → gecersiz, sayaç artmaz', ekran_kilit_ac($db, 1, 'abc', 1000)['kod'] === 'gecersiz' && kayit($db)['toplam_hata'] === 1);
ok('B13 PIN\'i olmayan kullanıcı → yok', ekran_kilit_ac($db, 2, '1234', 1000)['kod'] === 'yok');
ekran_kilit_temizle();

// ── C) Brute-force ───────────────────────────────────────────
$db = yeni_db(); ekran_pin_kur($db, 1, '1234', '1234');
$r = yanlis_n($db, 4, 1000);
ok('C1 4 yanlışta bekleme yok', $r['kod'] === 'yanlis' && $r['bekle'] === 0 && kayit($db)['bekle_bitis'] === 0);
$bek = [5 => 30, 6 => 120, 7 => 600, 8 => 1800, 9 => 3600];
$t = 2000; $hepsi = true; $ayrinti = '';
for ($n = 5; $n <= 9; $n++) {
    $r = ekran_kilit_ac($db, 1, '9999', $t);
    if ($r['kod'] !== 'bekle' || $r['bekle'] !== $bek[$n] || $r['engelli'] || kayit($db)['bekle_bitis'] !== $t + $bek[$n]) { $hepsi = false; $ayrinti .= " n=$n " . json_encode($r); }
    $t += $bek[$n] + 1;
}
ok('C2 5→30, 6→120, 7→600, 8→1800, 9→3600 sn bekleme', $hepsi, $ayrinti);
$db = yeni_db(); ekran_pin_kur($db, 1, '1234', '1234'); yanlis_n($db, 5, 1000);
$once = kayit($db)['toplam_hata'];
$r = ekran_kilit_ac($db, 1, '1234', 1010);
ok('C3 bekleme sürerken doğru PIN reddedilir, engelli, sayaç artmaz', !$r['ok'] && $r['engelli'] === true && $r['kod'] === 'bekle' && kayit($db)['toplam_hata'] === $once);
$r = ekran_kilit_ac($db, 1, '1234', 1031);
ok('C3b bekleme bitince doğru PIN açar, sayaç sıfır', $r['ok'] === true && kayit($db)['toplam_hata'] === 0 && kayit($db)['bekle_bitis'] === 0);
$db = yeni_db(); ekran_pin_kur($db, 1, '1234', '1234');
$t = 1000; $son = [];
for ($n = 1; $n <= 10; $n++) { $son = ekran_kilit_ac($db, 1, '9999', $t); $t += 4000; }
ok('C4 10. yanlış → cikis, sayaç ve bekleme sıfır', $son['cikis'] === true && $son['kod'] === 'cikis' && kayit($db)['toplam_hata'] === 0 && kayit($db)['bekle_bitis'] === 0);
yanlis_n($db, 3, 100000);
ok('C5 sayaç kalıcı (kv\'de, oturumdan bağımsız)', kayit($db)['toplam_hata'] === 3);
ekran_pin_sayac_sifirla($db, 1);
ok('C6 ekran_pin_sayac_sifirla sıfırlar', kayit($db)['toplam_hata'] === 0);
yanlis_n($db, 2, 200000); ekran_kilit_ac($db, 1, '1234', 200001);
ok('C7 başarılı PIN sayacı sıfırlar', kayit($db)['toplam_hata'] === 0);

// ── D) Değiştir ──────────────────────────────────────────────
$db = yeni_db(); ekran_pin_kur($db, 1, '1234', '1234');
$h1 = kayit($db)['h'];
$r = ekran_pin_degistir($db, 1, ['mevcut' => '1234', 'pin' => '5678', 'pin2' => '5678'], 1000);
ok('D1 doğru mevcut → ok, hash değişir', $r['ok'] === true && $r['yontem'] === 'pin' && kayit($db)['h'] !== $h1);
ok('D2 eski PIN geçmez, yenisi geçer', ekran_kilit_ac($db, 1, '1234', 1000)['ok'] === false && ekran_kilit_ac($db, 1, '5678', 1000)['ok'] === true);
$r = ekran_pin_degistir($db, 1, ['mevcut' => '0000', 'pin' => '1111', 'pin2' => '1111'], 1000);
ok('D3 yanlış mevcut → yanlis, sayaç artar', $r['kod'] === 'yanlis' && kayit($db)['toplam_hata'] === 1);
$r = ekran_pin_degistir($db, 1, ['sifre' => 'dogru-sifre', 'pin' => '2468', 'pin2' => '2468'], 1000);
ok('D4 şifre yolu doğruda çalışır, sayaç sıfır', $r['ok'] === true && $r['yontem'] === 'sifre' && kayit($db)['toplam_hata'] === 0 && ekran_kilit_ac($db, 1, '2468', 1000)['ok']);
$r = ekran_pin_degistir($db, 1, ['sifre' => 'yanlis', 'pin' => '2468', 'pin2' => '2468'], 1000);
ok('D5 yanlış şifre → yanlis + mesaj, sayaç artar', $r['kod'] === 'yanlis' && $r['mesaj'] === 'Hesap şifresi hatalı.' && kayit($db)['toplam_hata'] === 1);
ok('D6 yeni PIN geçersiz/uyuşmaz', ekran_pin_degistir($db, 1, ['mevcut' => '2468', 'pin' => '12', 'pin2' => '12'])['kod'] === 'gecersiz'
    && ekran_pin_degistir($db, 1, ['mevcut' => '2468', 'pin' => '1234', 'pin2' => '1235'])['kod'] === 'eslesmiyor');
ok('D7 PIN yokken yok', ekran_pin_degistir($db, 2, ['mevcut' => '1234', 'pin' => '5678', 'pin2' => '5678'])['kod'] === 'yok');
yanlis_n($db, 4, 5000);   // toplam 5 (önceki 1 + 4)
$r = ekran_pin_degistir($db, 1, ['sifre' => 'dogru-sifre', 'pin' => '2468', 'pin2' => '2468'], 5010);
ok('D8 bekleme sırasında şifre yolu da reddedilir', $r['kod'] === 'bekle' && $r['engelli'] === true);
$db3 = yeni_db(); ekran_pin_kur($db3, 2, '1234', '1234');
ok('D9 pasif kullanıcı şifre yolunda doğrulanmaz', ekran_pin_degistir($db3, 2, ['sifre' => 'pasif-sifre', 'pin' => '5678', 'pin2' => '5678'], 1000)['kod'] === 'yanlis');
$db4 = yeni_db(); ekran_pin_kur($db4, 1, '1234', '1234'); $t = 1000; $son = [];
for ($n = 1; $n <= 10; $n++) { $son = ekran_pin_degistir($db4, 1, ['sifre' => 'x', 'pin' => '5678', 'pin2' => '5678'], $t); $t += 4000; }
ok('D10 şifre yolunda 10. yanlış → cikis', $son['cikis'] === true && $son['kod'] === 'cikis');

// ── E) Fail-soft ─────────────────────────────────────────────
$kvsiz = yeni_db(false);
$ep = tempnam(sys_get_temp_dir(), 'ekp'); $eski = ini_set('error_log', $ep);
ok('E1 tablo yok → durum pasif', ekran_pin_durum($kvsiz, 1) === 'pasif');
$r = ekran_kilit_ac($kvsiz, 1, '1234');
ok('E2 ac → ok=false kod pasif, istisna sızmaz', $r['ok'] === false && $r['kod'] === 'pasif');
ok('E3 kur → ok=false kod pasif', ekran_pin_kur($kvsiz, 1, '1234', '1234')['kod'] === 'pasif' && ekran_pin_degistir($kvsiz, 1, ['mevcut' => '1234', 'pin' => '5678', 'pin2' => '5678'])['kod'] === 'pasif');
// yazma hatası: okuma geçer ama güncelleme düşer → fail-closed
$db5 = yeni_db(); ekran_pin_kur($db5, 1, '1234', '1234');
$db5->exec("CREATE TRIGGER kv_ro BEFORE UPDATE ON hks_kv BEGIN SELECT RAISE(ABORT, 'ro'); END");
$r = ekran_kilit_ac($db5, 1, '9999', 1000);
$r2 = ekran_kilit_ac($db5, 1, '1234', 1000);
ok('E4 sayaç yazılamıyorsa fail-closed (pasif, açılmaz)', $r['kod'] === 'pasif' && $r2['ok'] === false && $r2['kod'] === 'pasif');
$log = (string)file_get_contents($ep); ini_set('error_log', $eski === false ? '' : $eski); @unlink($ep);
ok('E5 error_log\'da PIN/hash yok, yalnız sınıf adı', str_contains($log, '[ekran_pin]') && !str_contains($log, '1234') && !str_contains($log, '9999') && !str_contains($log, '$2y$'));
ok('E6 sır yoksa ekran_kilit_sir boş değil (test sırrı) ve kilit çerezi sırsız üretilemez', ekran_kilit_sir() !== '' );
// F) sıfırla
$db = yeni_db(); ekran_pin_kur($db, 1, '1234', '1234');
ok('F1 ekran_pin_sifirla → yok', ekran_pin_sifirla($db, 1) === true && ekran_pin_durum($db, 1) === 'yok' && kayit($db) === null);
ok('F2 sonraki kur çalışır, sifirla idempotent', ekran_pin_kur($db, 1, '4321', '4321')['ok'] && ekran_pin_sifirla($db, 1) && ekran_pin_sifirla($db, 1));
ini_set('error_log', '/dev/null');
ok('F3 tablo yoksa sifirla false (sızmaz)', @ekran_pin_sifirla($kvsiz, 1) === false);

// ── G) İstek kapısı ──────────────────────────────────────────
$iyi = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_HOST' => 'asya.scai.tr', 'HTTP_ORIGIN' => 'https://asya.scai.tr'];
ok('G1 doğru başlıklar + aynı host Origin → null', ekran_kilit_istek_denetle($iyi) === null);
ok('G2 çapraz Origin ret', ekran_kilit_istek_denetle(['HTTP_ORIGIN' => 'https://kotu.example'] + $iyi) !== null);
ok('G3 yanlış Content-Type ret', ekran_kilit_istek_denetle(['CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $iyi) !== null);
$x = $iyi; unset($x['HTTP_X_REQUESTED_WITH']);
ok('G4 X-Requested-With eksik ret', ekran_kilit_istek_denetle($x) !== null);
$x = $iyi; unset($x['HTTP_ORIGIN']);
ok('G5 Origin+Referer yok ret', ekran_kilit_istek_denetle($x) !== null);
ok('G6 GET ret', ekran_kilit_istek_denetle(['REQUEST_METHOD' => 'GET'] + $iyi) !== null);
ok('G7 Referer yedeği (aynı host) kabul, çapraz ret', ekran_kilit_istek_denetle(['HTTP_REFERER' => 'https://asya.scai.tr/index.php'] + $x) === null
    && ekran_kilit_istek_denetle(['HTTP_REFERER' => 'https://kotu.example/x'] + $x) !== null);
ok('G8 portlu host eşleşir', ekran_kilit_istek_denetle(['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:8080'] + $iyi) === null
    && ekran_kilit_istek_denetle(['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:9999'] + $iyi) !== null);

// ── H) İşaretleme ────────────────────────────────────────────
$kullanici = ['id' => 1, 'display_name' => 'Ali <b>x</b>', 'username' => 'ali'];
$html = ekran_kilit_html($kullanici, 'var', false);
$kilitParca = substr($html, 0, strpos($html, '<dialog id="ekranPinDegistir"'));
ok('H1 var → #ekranKilit/#ekranKilitPin/#ekranKilitForm', str_contains($html, '<dialog id="ekranKilit"') && str_contains($html, 'id="ekranKilitPin"') && str_contains($html, 'id="ekranKilitForm"'));
ok('H2 kilit penceresinde type="password" ve ekranKilitSifre YOK', !str_contains($kilitParca, 'type="password"') && !str_contains($html, 'id="ekranKilitSifre"'));
ok('H3 PIN alanı nitelikleri', str_contains($kilitParca, 'autocomplete="off"') && str_contains($kilitParca, 'inputmode="numeric"') && str_contains($kilitParca, 'maxlength="8"') && str_contains($kilitParca, 'pattern="[0-9]{4,8}"') && str_contains($kilitParca, 'data-pin-alan="1"'));
ok('H4 nötr name\'ler (pin/password/user yok)', !preg_match('/name="[^"]*(pin|password|user|sifre)[^"]*"/i', $html));
ok('H5 #ekranPinDegistir var\'da; şifre alanı new-password; kimlikler tam',
    str_contains($html, '<dialog id="ekranPinDegistir"') && (bool)preg_match('/id="ekranPinDegistirSifre"[^>]*autocomplete="new-password"|autocomplete="new-password"[^>]*id="ekranPinDegistirSifre"/', $html)
    && str_contains($html, 'id="ekranPinMevcutKap"') && str_contains($html, 'id="ekranPinSifreKap"') && str_contains($html, 'id="ekranPinUnuttum"') && str_contains($html, 'id="ekranPinDegistirYeni2"') && str_contains($html, 'id="ekranPinDegistirIptal"') && str_contains($html, 'id="ekranPinDegistirHata"'));
ok('H6 kilitli değilken data-kilitli yok, kilitliyken data-kilitli="1"', !str_contains($html, 'data-kilitli') && str_contains(ekran_kilit_html($kullanici, 'var', true), 'data-kilitli="1"'));
$yok = ekran_kilit_html($kullanici, 'yok', false);
ok('H7 yok → yalnız #ekranPinKur (+ çıkış), kilit penceresi yok', str_contains($yok, '<dialog id="ekranPinKur"') && str_contains($yok, 'id="ekranPinKurPin2"') && str_contains($yok, 'id="ekranPinKurBtn"') && str_contains($yok, 'class="ekran-kilit-cikis"') && !str_contains($yok, 'id="ekranKilit"') && !str_contains($yok, 'type="password"'));
ok('H8 pasif / oturumsuz → boş', ekran_kilit_html($kullanici, 'pasif') === '' && ekran_kilit_html(null, 'var') === '');
ok('H9 ad kaçırılır (XSS yok)', !str_contains($html, '<b>x</b>') && str_contains($html, 'Ali &lt;b&gt;x&lt;/b&gt;'));
ok('H10 uç + login data-*, hepsinde', substr_count($html, 'data-uc="/ekran_kilit.php"') === 2 && substr_count($html, 'data-login="/login.php"') === 2 && str_contains($yok, 'data-uc="/ekran_kilit.php"'));
ok('H11 sidebar düğmesi yalnız var', str_contains(ekran_pin_sidebar_dugme_html('var'), 'id="btnPinDegistir"') && str_contains(ekran_pin_sidebar_dugme_html('var'), 'data-pin-degistir="1"') && ekran_pin_sidebar_dugme_html('yok') === '' && ekran_pin_sidebar_dugme_html('pasif') === '');
ok('H12 görünen ad yoksa kullanıcı adı', str_contains(ekran_kilit_html(['id' => 1, 'display_name' => '', 'username' => 'ali'], 'var', false), '<b>ali</b>'));

// ── I) Kaynak denetimleri ────────────────────────────────────
$uc  = file_get_contents("$KOK/ekran_kilit.php");
$cor = file_get_contents("$KOK/config/ekran_kilit.php");
$hlp = file_get_contents("$KOK/config/helpers.php");
$js  = file_get_contents("$KOK/assets/app.js");
$css = file_get_contents("$KOK/assets/style.css");
$sw  = file_get_contents("$KOK/sw.js");
$lg = file_get_contents("$KOK/login.php"); $lo = file_get_contents("$KOK/logout.php"); $us = file_get_contents("$KOK/users.php");
ok('I1 uç: csrf/oturum YOK; XRW + istek_denetle + 401 + 405 VAR',
    !str_contains($uc, 'csrf_check') && !str_contains($uc, 'session_start') && !str_contains($uc, 'csrf_token') && !str_contains($uc, '$_SESSION')
    && str_contains($uc, 'ekran_kilit_istek_denetle') && str_contains($cor, 'X-Requested-With') === false && str_contains($cor, 'HTTP_X_REQUESTED_WITH') && str_contains($uc, ', 401') && str_contains($uc, ', 405') && str_contains($uc, ', 403') && str_contains($uc, 'current_user()'));
$corKod = preg_replace('#^\s*//.*$#m', '', $cor);
ok('I2 core: $_SESSION yalnız unset (yorum hariç)', str_contains($corKod, 'unset($_SESSION[') && !str_contains(preg_replace('/unset\(\$_SESSION\[[^;]*;/', '', $corKod), '$_SESSION') && !str_contains($corKod, 'session_start'));
$san = (string)substr($hlp, (int)strpos($hlp, 'function _audit_sanitize'), 900);
ok('I3b ekran_kilit.php kur: kilitliyken reddedilir (kod kilitli)', str_contains(file_get_contents("$KOK/ekran_kilit.php"), "'kod' => 'kilitli'"));
ok('I3 _audit_sanitize pin, pin_hash, sifre süzer (+ işlevsel engeller kalır)', str_contains($san, "'pin'") && str_contains($san, "'pin_hash'") && str_contains($san, "'sifre'") && str_contains($san, "'password'") && str_contains($san, "'csrf'"));
ok('I4 users.php: pin_reset + audit + csrf kapısı altında', str_contains($us, "'pin_reset'") && str_contains($us, "audit_log_event('pin_reset'") && str_contains($us, 'ekran_pin_sifirla') && strpos($us, "csrf_check") < strpos($us, "=== 'pin_reset'"));
ok('I5 login: sayaç sıfırla + mesaj beyaz listesi; logout: temizle',
    str_contains($lg, 'ekran_pin_sayac_sifirla') && str_contains($lg, "'kilit_oturum'") && str_contains($lg, "'pin_cikis'") && str_contains($lo, 'ekran_kilit_temizle') && strpos($lo, 'logout_user()') < strpos($lo, 'ekran_kilit_temizle'));
$auditCagri = preg_match_all('/audit_log_event\([^;]*;/', $uc . $us . $lg, $mm) ? implode("\n", $mm[0]) : '';
ok('I6 audit çağrılarında pin/sifre/hash değişkeni geçmez', !preg_match('/\$(pin|pin2|sifre|mevcut|hash)\b|\$s\(|\[\'(pin|sifre|h)\'\]/', $auditCagri));
ok('I7 helpers: require + footer + sidebar düğmesi + header sınıfı', str_contains($hlp, "require_once __DIR__ . '/ekran_kilit.php'") && str_contains($hlp, 'ekran_kilit_html(current_user())') && str_contains($hlp, 'ekran_pin_sidebar_dugme_html()') && str_contains($hlp, 'ekran-kilitli'));
ok('I8 core: şifre/PIN audit-log\'a girmez', !preg_match('/audit_log_event/', $cor) && !preg_match('/error_log\([^;]*\$(pin|sifre|mevcut|girdi|k\[)/', $cor));
ok('I9 sır yoksa pasif (kaynakta HKS_CRED_KEY kapısı)', str_contains($cor, "defined('HKS_CRED_KEY')") && str_contains($cor, "ekran_kilit_sir() === ''"));
ok('I10 istemci: masaüstü kapısı + kiosk + 300000 + cancel (B)',
    str_contains($js, '(min-width: 900px) and (hover: hover) and (pointer: fine)') && str_contains($js, '.pdks-kiosk') && str_contains($js, '300000') && str_contains($js, "'cancel'"));
ok('I11 css: kilit yalnız masaüstü medya sorgusunda gizler + text-security (B)',
    (bool)preg_match('/@media \(min-width: 900px\) and \(hover: hover\) and \(pointer: fine\) \{\s*body\.ekran-kilitli/', $css) && str_contains($css, 'dialog.ekran-kilit::backdrop') && str_contains($css, '-webkit-text-security'));
preg_match("/define\('APP_SURUM', '(v\d+)'/", $hlp, $m1); preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $m2);
ok('I12 APP_SURUM = sw.js cache sürümü', !empty($m1[1]) && ($m1[1] ?? '') === ($m2[1] ?? 'x'), ($m1[1] ?? '?') . ' / ' . ($m2[1] ?? '?'));

fwrite(STDOUT, $fail ? "\n$fail TEST BAŞARISIZ\n" : "\nTÜM TESTLER GEÇTİ\n");
exit($fail ? 1 : 0);
