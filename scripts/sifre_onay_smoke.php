<?php
// =========================================================
// scripts/sifre_onay_smoke.php — config/sifre_onay.php testleri (v464).
//
// Bellek içi SQLite; ekran kilidi/PIN koduna BAĞIMLILIK YOK (dosya yüklenmez, çerezi/KV kaydı kullanılmaz).
//   php scripts/sifre_onay_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);

$LOG = tempnam(sys_get_temp_dir(), 'sifre_onay_log_');
ini_set('log_errors', '1');
ini_set('error_log', $LOG);

$AUDIT = [];
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null, ?int $u = null): void { global $AUDIT; $AUDIT[] = [$a, $m, $id, $o, $n]; }

require_once $ROOT . '/config/sifre_onay.php';

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, password_hash TEXT, is_active INTEGER DEFAULT 1)');
$SIFRE = 'Gizli-Sifre-9731';
$pdo->prepare('INSERT INTO users (id, username, password_hash, is_active) VALUES (1, ?, ?, 1)')->execute(['kullanici_a', password_hash($SIFRE, PASSWORD_DEFAULT)]);
$pdo->prepare('INSERT INTO users (id, username, password_hash, is_active) VALUES (2, ?, ?, 0)')->execute(['pasif', password_hash($SIFRE, PASSWORD_DEFAULT)]);
$pdo->prepare('INSERT INTO users (id, username, password_hash, is_active) VALUES (3, ?, ?, 1)')->execute(['bos_hash', '']);

echo "\n=== 1. Doğrulama ===\n";
$_SESSION = [];
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'fis_sifirla', 1000);
ok('1a) doğru şifre → ok', $r['ok'] === true && $r['bekle'] === 0, json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, 'yanlis', 'fis_sifirla', 1000);
ok('1b) yanlış şifre → ok=false + kalan hak', $r['ok'] === false && str_contains($r['mesaj'], '4 deneme'), json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, '', 'fis_sifirla', 1000);
ok('1c) boş şifre → ok=false, sayaç ARTMAZ', $r['ok'] === false && ($_SESSION['sifre_onay']['fis_sifirla']['hata'] ?? 0) === 1, json_encode([$r, $_SESSION]));
$r = sifre_onay_dogrula($pdo, 2, $SIFRE, 'fis_sifirla', 1000);
ok('1d) pasif kullanıcı → doğru şifre bile REDDEDİLİR', $r['ok'] === false, json_encode($r));
$r = sifre_onay_dogrula($pdo, 3, $SIFRE, 'fis_sifirla', 1000);
ok('1e) hash\'i boş kullanıcı → reddedilir', $r['ok'] === false);
$r = sifre_onay_dogrula($pdo, 99, $SIFRE, 'fis_sifirla', 1000);
ok('1f) olmayan kullanıcı → reddedilir', $r['ok'] === false);
$r = sifre_onay_dogrula($pdo, 0, $SIFRE, 'fis_sifirla', 1000);
ok('1g) kullanıcı id 0 → reddedilir (oturum yok)', $r['ok'] === false && str_contains($r['mesaj'], 'Oturum'), json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'Gecersiz Baglam!', 1000);
ok('1h) geçersiz bağlam adı → reddedilir', $r['ok'] === false && str_contains($r['mesaj'], 'bağlam'));

echo "\n=== 2. Brute-force beklemesi ===\n";
$_SESSION = []; $AUDIT = [];
for ($i = 1; $i <= 4; $i++) $r = sifre_onay_dogrula($pdo, 1, 'yanlis' . $i, 'fis_sifirla', 2000);
ok('2a) 4 hatada henüz engel yok', empty($r['engelli']) && $r['ok'] === false);
$r = sifre_onay_dogrula($pdo, 1, 'yanlis5', 'fis_sifirla', 2000);
ok('2b) 5. hatada 60 sn bekleme + engelli', !empty($r['engelli']) && $r['bekle'] === 60, json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'fis_sifirla', 2030);
ok('2c) beklerken DOĞRU şifre de REDDEDİLİR (kalan süre döner)', $r['ok'] === false && !empty($r['engelli']) && $r['bekle'] === 30, json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'fis_sifirla', 2060);
ok('2d) bekleme bitince doğru şifre geçer, sayaç sıfırlanır', $r['ok'] === true && !isset($_SESSION['sifre_onay']['fis_sifirla']), json_encode([$r, $_SESSION]));
$engelAudit = array_values(array_filter($AUDIT, fn($a) => $a[0] === 'sifre_onay_engel'));
ok('2e) 5. hatada TEK sifre_onay_engel audit satırı (bağlam + sayı, şifre YOK)', count($engelAudit) === 1 && ($engelAudit[0][4]['baglam'] ?? '') === 'fis_sifirla' && !str_contains(json_encode($AUDIT), 'yanlis'), json_encode($AUDIT));
$_SESSION = [];
for ($i = 1; $i <= 5; $i++) sifre_onay_dogrula($pdo, 1, 'x' . $i, 'fis_sifirla', 3000);
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'baska_islem', 3001);
ok('2f) farklı bağlamın sayacı AYRI: fis_sifirla engelliyken baska_islem doğru şifreyle geçer', $r['ok'] === true, json_encode($r));
$r = sifre_onay_dogrula($pdo, 1, $SIFRE, 'fis_sifirla', 3001);
ok('2g) aynı bağlam hâlâ engelli', $r['ok'] === false && !empty($r['engelli']));
$r = sifre_onay_dogrula($pdo, 1, 'yanlis', 'fis_sifirla', 3100);
ok('2h) bekleme sonrası yeni yanlış deneme 1\'den sayar (4 hak)', str_contains($r['mesaj'], '4 deneme'), json_encode($r));

echo "\n=== 3. Şifre hiçbir yere sızmaz ===\n";
$_SESSION = [];
sifre_onay_dogrula($pdo, 1, $SIFRE, 'fis_sifirla', 4000);
sifre_onay_dogrula($pdo, 1, 'Gizli-Yanlis-5521', 'fis_sifirla', 4000);
// teknik hata: tablo yok → yalnız sınıf adı loglanır
$bos = new PDO('sqlite::memory:'); $bos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$r = sifre_onay_dogrula($bos, 1, $SIFRE, 'fis_sifirla', 4000);
ok('3a) teknik hata → ok=false + genel mesaj (SQL/şifre yok)', $r['ok'] === false && !str_contains($r['mesaj'], 'users') && !str_contains($r['mesaj'], $SIFRE), json_encode($r));
$logIcerik = (string)@file_get_contents($LOG);
ok('3b) error_log\'da şifre YOK (doğru ve yanlış denenen)', !str_contains($logIcerik, $SIFRE) && !str_contains($logIcerik, 'Gizli-Yanlis-5521'), $logIcerik);
ok('3c) error_log yalnız sınıf adı taşır (ileti/SQL yok)', str_contains($logIcerik, 'PDOException') && !str_contains($logIcerik, 'no such table'), $logIcerik);
ok('3d) oturum verisinde şifre YOK', !str_contains(json_encode($_SESSION), $SIFRE) && !str_contains(json_encode($_SESSION), 'Gizli-Yanlis'), json_encode($_SESSION));

echo "\n=== 4. Ekran kilidi/PIN'den bağımsızlık (statik) ===\n";
$src = (string)file_get_contents($ROOT . '/config/sifre_onay.php');
$kod = preg_replace('#/\*.*?\*/#s', '', $src); $kod = preg_replace('#^\s*//.*$#m', '', (string)$kod); $kod = preg_replace('#\s//\s.*$#m', '', (string)$kod);   // yorumlar hariç
ok('4a) ekran_kilit dosyası/işlevleri/çerezi KULLANILMAZ', !preg_match('/ekran_kilit|ekran_pin|asya_kilit|EKRAN_KILIT|hks_kv|HKS_CRED_KEY/', $kod));
ok('4b) password_verify ile users.password_hash doğrulanır; PIN doğrulaması YOK', str_contains($kod, 'password_verify(') && str_contains($kod, 'password_hash') && !preg_match('/\bpin\b/i', $kod));
ok('4c) çıktı basmaz / exit etmez / header göndermez', !preg_match('/\b(echo|print|exit|die)\b|header\(|setcookie\(/', $kod));
ok('4d) şifre error_log/audit çağrılarına verilmez', !preg_match('/error_log\([^;]*\$sifre|audit_log_event\([^;]*\$sifre/', $kod));
ok('4e) declare(strict_types=1)', str_contains($src, 'declare(strict_types=1);'));
$kilitSrc = (string)file_get_contents($ROOT . '/config/ekran_kilit.php');
ok('4f) ekran_kilit.php sifre_onay\'a atıf YAPMAZ (iki yönde de bağımsız)', !str_contains($kilitSrc, 'sifre_onay'));

@unlink($LOG);
echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
