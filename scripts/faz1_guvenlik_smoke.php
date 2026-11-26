<?php
// =========================================================
// scripts/faz1_guvenlik_smoke.php — Faz 1 Güvenlik Sertleştirme Testi
// (B-02, B-03, B-15, B-16, B-17, B-38, B-13, B-14, B-18)
// SADECE CLI. Bellek içi SQLite + geçici dizin; canlı DB'ye dokunmaz.
//   php scripts/faz1_guvenlik_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();

$ROOT = dirname(__DIR__);
$fail = 0;
$pass = 0;
function ok(string $ad, bool $kosul, string $ipucu = ''): void {
    global $fail, $pass;
    if ($kosul) $pass++; else $fail++;
    fwrite(STDOUT, sprintf("%-76s %s%s\n", $ad, $kosul ? 'OK' : '*** FAIL', $kosul ? '' : '  ' . $ipucu));
}

// ── 1) B-02: deploy.php & deploy_webhook.php ──────────────────────────────────
$dp  = (string)file_get_contents($ROOT . '/scripts/deploy.php');
$dwh = (string)file_get_contents($ROOT . '/scripts/deploy_webhook.php');

// smart_merge_db fonksiyonunu izole test et ($1, \1, ' içeren şifre bozulmamalı)
$tmpDir = sys_get_temp_dir() . '/asya_faz1_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
try {
    $fnA = strpos($dp, 'function smart_merge_db(');
    $fnB = strpos($dp, "\n}\n", $fnA);
    $mergeCode = str_replace('log_msg(', '// log_msg(', substr($dp, $fnA, $fnB - $fnA + 3));
    eval($mergeCode);

    $dbFile = $tmpDir . '/db.php';
    $trickyPass = 'p$0a$1s\\1s\'x';
    $oldDb = "<?php\nconst DB_HOST = 'sql.example';\nconst DB_NAME = 'prod_db';\nconst DB_USER = 'prod_u';\nconst DB_PASS = '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $trickyPass) . "';\n";
    $newDb = "<?php\nconst DB_HOST = 'localhost';\nconst DB_NAME = 'yukleme_plani';\nconst DB_USER = 'root';\nconst DB_PASS = '';\n// yeni satir\n";
    file_put_contents($dbFile, $oldDb);
    smart_merge_db($dbFile, $newDb);
    $merged = (string)file_get_contents($dbFile);
    $expectedPassLine = "const DB_PASS = '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $trickyPass) . "';";
    ok('B-02a smart_merge_db $0/$1/\\1/\' içeren DB_PASS bozulmaz',
        str_contains($merged, "const DB_HOST = 'sql.example';")
        && str_contains($merged, "const DB_NAME = 'prod_db';")
        && str_contains($merged, $expectedPassLine)
        && str_contains($merged, '// yeni satir'),
        $merged
    );
    ok('B-02b scripts/deploy.php config/db.php dalı $protected dışında + zip-slip',
        strpos($dp, "if (in_array(\$rel, \$protected, true))") < strpos($dp, "if (\$rel === 'config/db.php')")
        && str_contains($dp, "strpos(\$rel, '..') !== false")
        && str_contains($dp, 'opcache_reset')
        && str_contains($dp, '/storage')
    );
    ok('B-02c scripts/deploy_webhook.php preg_replace_callback + storage/deploy.log + opcache',
        str_contains($dwh, 'preg_replace_callback')
        && str_contains($dwh, "storage/deploy.log")
        && str_contains($dwh, 'opcache_reset')
        && str_contains($dwh, "DEPLOY_SECRET === ''")
    );

    // ── 2) B-03: kantar_foto_dogrula (SVG Stored XSS koruması) ────────────────
    $hlp = (string)file_get_contents($ROOT . '/config/helpers.php');
    $ka = strpos($hlp, 'function kantar_foto_dogrula(');
    $kb = strpos($hlp, "\n}\n", $ka);
    eval(substr($hlp, $ka, $kb - $ka + 3));

    $svgPayload = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
    $svgAsPng   = 'data:image/png;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>');
    $htmlAsJpg  = 'data:image/jpeg;base64,' . base64_encode('<html><script>alert(1)</script></html>');
    // Geçerli 1x1 şeffaf PNG
    $validPngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $validPngUri = 'data:image/png;base64,' . base64_encode($validPngBin);

    ok('B-03a SVG data URI reddedilir', kantar_foto_dogrula($svgPayload) === null);
    ok('B-03b PNG kılıflı SVG finfo ile reddedilir', kantar_foto_dogrula($svgAsPng) === null);
    ok('B-03c JPEG kılıflı HTML finfo ile reddedilir', kantar_foto_dogrula($htmlAsJpg) === null);
    ok('B-03d Boyut aşımı reddedilir', kantar_foto_dogrula($validPngUri, 10) === null);
    $validRes = kantar_foto_dogrula($validPngUri);
    ok('B-03e Geçerli PNG kabul edilir', is_array($validRes) && $validRes['mime'] === 'image/png' && $validRes['raw'] === $validPngBin);
    $kf = (string)file_get_contents($ROOT . '/kantar_foto.php');
    ok('B-03f kantar_foto.php nosniff + sandbox CSP + private cache',
        str_contains($kf, 'kantar_foto_dogrula')
        && str_contains($kf, 'X-Content-Type-Options: nosniff')
        && str_contains($kf, 'Content-Security-Policy: sandbox')
        && str_contains($kf, 'Cache-Control: private')
    );

    // ── 3) B-15: HKS_CRED_KEY fail-closed, AES-256-GCM + .htaccess ────────────
    $hkCfg = (string)file_get_contents($ROOT . '/halkayit/config.php');
    ok('B-15a halkayit/config.php içinde sabit fallback anahtar YOK', !str_contains($hkCfg, 'AsyaFresh-HKS-2026-'));

    // Subprocess: HKS_CRED_KEY tanımsızken hks_sifrele fail-closed exception atmalı
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "define('HKS_SIFRELEME_ANAHTARI', '');"
        . "\$src = file_get_contents(" . var_export($ROOT . '/halkayit/db.php', true) . ");"
        . "\$a = strpos(\$src, 'function hks_sifreleme_anahtari_ham(');"
        . "eval(substr(\$src, \$a));"
        . "try { hks_sifrele('test'); exit(2); } catch (RuntimeException \$e) { echo 'FAIL_CLOSED_OK'; exit(0); }"
    );
    $out = trim((string)shell_exec($cmd));
    ok('B-15b HKS_CRED_KEY yoksa hks_sifrele fail-closed (RuntimeException)', $out === 'FAIL_CLOSED_OK', $out);

    // AES-256-GCM roundtrip + kurcalama reddi + eski CBC geriye uyumluluk
    $cmd2 = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "define('HKS_SIFRELEME_ANAHTARI', str_repeat('s', 32));"
        . "\$src = file_get_contents(" . var_export($ROOT . '/halkayit/db.php', true) . ");"
        . "\$a = strpos(\$src, 'function hks_sifreleme_anahtari_ham(');"
        . "eval(substr(\$src, \$a));"
        . "\$enc = hks_sifrele('GizliSifre!2026');"
        . "if (!str_starts_with(\$enc, 'gcm1:')) exit(11);"
        . "if (hks_coz(\$enc) !== 'GizliSifre!2026') exit(12);"
        . "\$raw = base64_decode(substr(\$enc, 5), true); \$raw[strlen(\$raw)-1] = chr(ord(\$raw[strlen(\$raw)-1]) ^ 1);"
        . "if (hks_coz('gcm1:' . base64_encode(\$raw)) !== '') exit(13);"
        . "\$key = hash('sha256', HKS_SIFRELEME_ANAHTARI, true); \$iv = random_bytes(16);"
        . "\$cbc = base64_encode(\$iv . openssl_encrypt('EskiSifre', 'aes-256-cbc', \$key, OPENSSL_RAW_DATA, \$iv));"
        . "if (hks_coz(\$cbc) !== 'EskiSifre') exit(14);"
        . "echo 'CRYPTO_OK';"
    );
    $out2 = trim((string)shell_exec($cmd2));
    ok('B-15c AES-256-GCM roundtrip + kurcalama reddi + eski CBC geriye uyum', $out2 === 'CRYPTO_OK', $out2);

    $htRoot = (string)file_get_contents($ROOT . '/.htaccess');
    $htUpl  = (string)file_get_contents($ROOT . '/uploads/.htaccess');
    ok('B-15d config/.htaccess ve vendor/.htaccess mevcut + Require all denied',
        is_file($ROOT . '/config/.htaccess') && str_contains((string)file_get_contents($ROOT . '/config/.htaccess'), 'Require all denied')
        && is_file($ROOT . '/vendor/.htaccess') && str_contains((string)file_get_contents($ROOT . '/vendor/.htaccess'), 'Require all denied')
    );
    $fmMatch = preg_match('/<FilesMatch\s+"([^"]+)">/', $htRoot, $fm);
    $fmPat = $fmMatch ? '#' . $fm[1] . '#' : '';
    ok('B-15e Kök/uploads/templates/scripts/tools .htaccess Apache 2.4 + IfModule + manifest.json açık',
        str_contains($htRoot, 'Options -Indexes') && str_contains($htRoot, '<IfModule mod_php')
        && $fmPat !== '' && !preg_match($fmPat, 'manifest.json')
        && (bool)preg_match($fmPat, 'composer.json') && (bool)preg_match($fmPat, 'deploy.log')
        && str_contains($htRoot, 'X-Content-Type-Options')
        && str_contains($htUpl, '<IfModule mod_php') && str_contains($htUpl, 'Require all denied')
        && str_contains((string)file_get_contents($ROOT . '/templates/.htaccess'), 'Require all denied')
        && str_contains((string)file_get_contents($ROOT . '/scripts/.htaccess'), 'Require all denied')
        && str_contains((string)file_get_contents($ROOT . '/tools/.htaccess'), 'Require all denied')
    );

    // ── 4) B-16 + B-38: safe_next_url, brute-force rate limit, logout cleanup ─
    $authSrc = (string)file_get_contents($ROOT . '/config/auth.php');
    foreach (['safe_next_url', 'login_rate_limit_dir', 'login_rate_limit_keys', 'login_rate_limit_with_file', 'login_rate_limit_check', 'login_rate_limit_record_failure', 'login_rate_limit_reset'] as $fn) {
        $a = strpos($authSrc, 'function ' . $fn . '(');
        $b = strpos($authSrc, "\n}\n", $a);
        eval(substr($authSrc, $a, $b - $a + 3));
    }
    if (!defined('LOGIN_RL_BEKLEME')) define('LOGIN_RL_BEKLEME', [5 => 30, 6 => 60, 7 => 120, 8 => 300]);
    if (!defined('LOGIN_RL_MAX_BEKLEME')) define('LOGIN_RL_MAX_BEKLEME', 600);
    if (!defined('LOGIN_RL_PENCERE_SN')) define('LOGIN_RL_PENCERE_SN', 900);

    ok('B-38a safe_next_url geçerli yerel yolları kabul eder',
        safe_next_url('/records.php?id=5') === '/records.php?id=5' && safe_next_url('/index.php') === '/index.php'
    );
    ok('B-38b safe_next_url //, /\\, CRLF, harici URL reddeder',
        safe_next_url('//evil.com') === ''
        && safe_next_url('/\\evil.com') === ''
        && safe_next_url("/foo\r\nBar: 1") === ''
        && safe_next_url('https://evil.com/a') === ''
        && safe_next_url('javascript:alert(1)') === ''
        && safe_next_url('records.php') === ''
    );

    $GLOBALS['_auth_rl_dir'] = $tmpDir . '/rl';
    $t0 = 1700000000;
    ok('B-16a İlk 4 başarısız denemede bekleme yok',
        login_rate_limit_record_failure('ali', '1.2.3.4', $t0)['bekle'] === 0
        && login_rate_limit_record_failure('ali', '1.2.3.4', $t0 + 1)['bekle'] === 0
        && login_rate_limit_record_failure('ali', '1.2.3.4', $t0 + 2)['bekle'] === 0
        && login_rate_limit_record_failure('ali', '1.2.3.4', $t0 + 3)['bekle'] === 0
    );
    $r5 = login_rate_limit_record_failure('ali', '1.2.3.4', $t0 + 4);
    $chk5 = login_rate_limit_check('ali', '1.2.3.4', $t0 + 10);
    ok('B-16b 5. hatada 30 sn bekleme + aktif bekleme kontrolü reddeder',
        $r5['bekle'] === 30 && !$chk5['ok'] && $chk5['bekle'] === 24,
        json_encode([$r5, $chk5])
    );
    $r6 = login_rate_limit_record_failure('ali', '1.2.3.4', $t0 + 40);
    ok('B-16c 6. hatada 60 sn artımlı bekleme', $r6['bekle'] === 60);
    login_rate_limit_reset('ali', '1.2.3.4');
    ok('B-16d Başarılı giriş sonrası rate-limit sıfırlanır', login_rate_limit_check('ali', '1.2.3.4', $t0 + 41)['ok'] === true);

    ok('B-16e logout_user asya_depo çerezini siler ve auth_reset_cache çağırır',
        str_contains($authSrc, 'DEPOT_COOKIE_NAME')
        && str_contains($authSrc, 'unset($_COOKIE[DEPOT_COOKIE_NAME])')
        && str_contains($authSrc, 'auth_reset_cache();')
    );

    // ── 5) B-17: Sunucu tarafı ekran kilidi zorlaması ─────────────────────────
    if (!defined('HKS_CRED_KEY')) define('HKS_CRED_KEY', str_repeat('k', 32));
    if (!defined('AUTH_COOKIE_NAME')) define('AUTH_COOKIE_NAME', 'asya_session');
    if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('base_url')) { function base_url(): string { return '/'; } }
    require_once $ROOT . '/config/ekran_kilit.php';

    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE hks_kv (anahtar TEXT PRIMARY KEY, deger TEXT)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, password_hash TEXT, is_active INTEGER)');
    $pdo->prepare('INSERT INTO users VALUES (1, ?, 1)')->execute([password_hash('dogru', PASSWORD_DEFAULT)]);
    ekran_pin_kur($pdo, 1, '1234', '1234');

    $tok = str_repeat('ef', 32);
    $_COOKIE = ['asya_session' => $tok];
    ekran_kilitle($tok, $pdo);
    // Saldırgan asya_kilit çerezini siler!
    unset($_COOKIE[EKRAN_KILIT_COOKIE]);
    ok('B-17a asya_kilit çerezi silinse bile sunucu kilidi true döner',
        ekran_kilitli_mi($tok) === false && ekran_kilit_oturum_kilitli_mi($pdo, $tok) === true
    );
    $acRes = ekran_kilit_ac($pdo, 1, '1234', time());
    ok('B-17b Doğru PIN sunucu kilidini kaldırır',
        $acRes['ok'] === true && ekran_kilit_oturum_kilitli_mi($pdo, $tok) === false
    );
    ekran_kilitle($tok, $pdo);
    unset($_COOKIE[EKRAN_KILIT_COOKIE]);
    ekran_kilit_temizle($tok, $pdo);
    ok('B-17c ekran_kilit_temizle sunucu kilidini siler', ekran_kilit_oturum_kilitli_mi($pdo, $tok) === false);
    ok('B-17d require_login, require_perm ve halkayit/api.php sunucu kilidini zorlar (423)',
        str_contains($authSrc, 'function enforce_screen_unlock')
        && str_contains($authSrc, 'http_response_code(423)')
        && substr_count($authSrc, 'enforce_screen_unlock();') >= 2
        && str_contains((string)file_get_contents($ROOT . '/halkayit/api.php'), 'http_response_code(423)')
    );

    // ── 6) B-38: CSRF (logout, endpoint_test, opcache_reset) + PHPSESSID + Fail-closed ──
    $loSrc = (string)file_get_contents($ROOT . '/logout.php');
    $etSrc = (string)file_get_contents($ROOT . '/halkayit/endpoint_test.php');
    $opSrc = (string)file_get_contents($ROOT . '/halkayit/opcache_reset.php');
    $lgSrc = (string)file_get_contents($ROOT . '/login.php');
    $sdSrc = (string)file_get_contents($ROOT . '/sifre_degistirme.php');
    ok('B-38c logout/endpoint_test/opcache_reset POST + csrf_check zorunlu',
        str_contains($loSrc, "REQUEST_METHOD") && str_contains($loSrc, 'csrf_check(')
        && str_contains($etSrc, 'csrf_check(') && str_contains($etSrc, 'name="csrf"')
        && str_contains($opSrc, 'csrf_check(') && !str_contains($opSrc, '{$soap_path}')
    );
    ok('B-38d app_session_start + session_regenerate_id + SESSION_ABSOLUTE_MAX_DAYS',
        str_contains($hlp, 'session_set_cookie_params')
        && str_contains($hlp, 'session_regenerate_id(true)')
        && str_contains($lgSrc, 'app_session_regenerate()')
        && str_contains($sdSrc, 'app_session_regenerate()')
        && str_contains($authSrc, 'SESSION_ABSOLUTE_MAX_DAYS')
    );
    ok('B-38e user_assigned_depots PDOException durumunda fail-closed ([]) döner',
        str_contains($authSrc, "catch (PDOException \$e) { return \$store['assigned_depots'][\$user_id] = []; }")
        && str_contains($authSrc, "return \$store['assigned_depots'][\$user_id] = [];\n    }\n}")
    );

    // ── 7) B-13: Kullanıcı/aktif depo bazlı IDOR engeli (role_data_scope) ─────
    $cmd3 = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        "require_once " . var_export($ROOT . '/config/role_data_scope.php', true) . ";"
        . "\$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);"
        . "\$pdo->exec('CREATE TABLE user_roles(user_id INTEGER, role_id INTEGER); CREATE TABLE role_permissions(role_id INTEGER, permission TEXT); CREATE TABLE loading_records(id INTEGER, type TEXT); CREATE TABLE loading_pallets(loading_record_id INTEGER, depo TEXT); CREATE TABLE kantar_fisleri(id INTEGER, depo TEXT)');"
        . "\$pdo->exec(\"INSERT INTO user_roles VALUES(5, 2); INSERT INTO role_permissions VALUES(2, 'records.loading.delete'); INSERT INTO loading_records VALUES(1,'yukleme'),(2,'yukleme'),(3,'yukleme'); INSERT INTO loading_pallets VALUES(1,'Depo A'),(2,'Depo B'),(3,''); INSERT INTO kantar_fisleri VALUES(10,'Depo A'),(11,'Depo B'),(12,'')\");"
        . "function db(): PDO { return \$GLOBALS['pdo']; }"
        . "function current_user(): ?array { return ['id' => 5]; }"
        . "function is_admin(): bool { return false; }"
        . "function can(string \$p): bool { return true; }"
        . "function user_allowed_depots(?int \$uid = null): ?array { return ['Depo A']; }"
        . "function forbidden(string \$m = ''): void { throw new RuntimeException('FORBIDDEN'); }"
        . "\$as = str_replace(\"\\r\\n\", \"\\n\", file_get_contents(" . var_export($ROOT . '/config/auth.php', true) . "));"
        . "foreach (['depo_fold','depo_in_allowed','depot_visible_to_user'] as \$fn) { \$a = strpos(\$as, 'function '.\$fn.'('); \$b = strpos(\$as, \"\\n}\\n\", \$a); eval(substr(\$as, \$a, \$b - \$a + 3)); }"
        . "if (role_current_depot_scope_restricted() !== false) exit(21);"
        . "role_require_record_access(1, 'delete');"
        . "role_require_record_access(3, 'delete');" // atanmamış depo geçer
        . "\$d1 = false; try { role_require_record_access(2, 'delete'); } catch (RuntimeException \$e) { \$d1 = (\$e->getMessage() === 'FORBIDDEN'); }"
        . "if (!\$d1) exit(22);"
        . "role_require_kantar_scope(10);"
        . "role_require_kantar_scope(12);"
        . "\$d2 = false; try { role_require_kantar_scope(11); } catch (RuntimeException \$e) { \$d2 = (\$e->getMessage() === 'FORBIDDEN'); }"
        . "if (!\$d2) exit(23);"
        . "echo 'IDOR_BLOCKED_OK';"
    );
    $out3 = trim((string)shell_exec($cmd3));
    ok('B-13 Kullanıcı/aktif depo kısıtında yabancı kayıt ve kantar 403 ile engellenir', $out3 === 'IDOR_BLOCKED_OK', $out3);

    // ── 8) B-14: users.php dikey yetki yükseltme engeli ───────────────────────
    $usSrc = (string)file_get_contents($ROOT . '/users.php');
    ok('B-14 users.php !is_admin için admin rolü atama ve admin hesabına müdahale engelli',
        str_contains($usSrc, '$caller_is_admin = is_admin();')
        && substr_count($usSrc, '!$caller_is_admin && $admin_rid > 0 && in_array($admin_rid, $roles_post, true)') >= 2
        && substr_count($usSrc, '!$caller_is_admin && $admin_rid > 0 && user_has_role($pdo, $uid, $admin_rid)') >= 4
    );

    // ── 9) B-18: DOM XSS kaçışları ────────────────────────────────────────────
    $hkApp = (string)file_get_contents($ROOT . '/halkayit/app.html');
    $appJs = (string)file_get_contents($ROOT . '/assets/app.js');
    ok('B-18a halkayit/app.html:5122 e.message eHtml() ile kaçırılır',
        str_contains($hkApp, '${eHtml(e.message)}')
        && !str_contains($hkApp, '<div class="mesaj hata" style="display:block">${e.message}</div>')
    );
    ok('B-18b assets/app.js kasa_adeti escHtml() ile kaçırılır',
        str_contains($appJs, '${escHtml(p.kasa_adeti)}')
        && !str_contains($appJs, '>${p.kasa_adeti}</strong>')
    );
} finally {
    // Geçici test dizinini temizle
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmpDir);
}

fwrite(STDOUT, "\nFaz 1 Güvenlik Testi: {$pass} geçti, {$fail} başarısız.\n");
exit($fail > 0 ? 1 : 0);
