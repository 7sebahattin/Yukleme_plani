<?php
// =========================================================
// scripts/mail_gate_static_smoke.php — Mail Merkezi STATİK değişmezleri
// Kaynak koda bakar (sayfa çalıştırmaz): kapı tek kaynak, migrate yalnız
// migrate.php'de, sır/anahtar repoda yok, SW mail yollarını önbelleğe almaz,
// sürüm eşitliği. CLI; DB'ye dokunmaz.
//   php scripts/mail_gate_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

$helpers = oku('config/helpers.php'); $index = oku('index.php'); $sw = oku('sw.js');
$core = oku('config/mail_core.php'); $migrate = oku('migrate.php');

echo "=== Kapı tek kaynak ===\n";
ok('helpers.php can_mail() tanımlı', str_contains($helpers, 'function can_mail(string $perm): bool'));
ok('can_mail fail-closed (can() yoksa false)', (bool)preg_match("/function can_mail[^{]*\{\s*if \(!function_exists\('can'\)\) return false;/", $helpers));
ok('sidebar $p_mail = can_mail(\'read\')', str_contains($helpers, "\$p_mail  = can_mail('read');"));
ok('sidebar mail bağlantısı $p_mail kapılı', str_contains($helpers, "if (\$p_mail) \$lnk('mail.php'"));
ok('nav_alt_izinler mail = can_mail(\'read\')', str_contains($helpers, "'mail'    => \$fn && can_mail('read')"));
ok('first_allowed_page mail.php = can_mail(\'read\')', str_contains($helpers, "'mail.php'           => can_mail('read')"));
ok('index.php kartı can_mail(\'read\') kapılı', (bool)preg_match("/if \(can_mail\('read'\)\): \?>\s*\n\s*<a href=\"mail\.php\"/", $index));
ok('index.php bölüm görünürlüğü can_mail içeriyor', str_contains($index, "|| can_mail('read');"));
ok('mail.php require_mail(\'read\')', str_contains(oku('mail.php'), "require_mail('read');"));
ok('mail_hesaplar.php require_mail(\'admin\')', str_contains(oku('mail_hesaplar.php'), "require_mail('admin');"));
ok('mail_hesaplar.php POST\'ta csrf_check', str_contains(oku('mail_hesaplar.php'), "csrf_check(\$_POST['csrf'] ?? null);"));
ok('mail.* yetkileri HİÇBİR sayfada elle can(\'mail.…\') ile denetlenmiyor (yalnız can_mail)',
    preg_match("/can\('mail\.(?!admin|read|reply|send)/", $helpers) === 0 && substr_count($helpers, "can('mail.") === 3 + 1 /* read/reply/send + admin */,
    'sayım: ' . substr_count($helpers, "can('mail."));
foreach (['mail.php', 'mail_hesaplar.php', 'index.php', 'migrate.php'] as $f) {
    ok("$f kapıyı can('mail.*') ile KOPYALAMIYOR", preg_match("/\bcan\('mail\./", oku($f)) === 0);
}

echo "\n=== Yetki kataloğu ve seed ===\n";
$auth = oku('config/auth.php');
foreach (['mail.read', 'mail.reply', 'mail.send', 'mail.admin'] as $p) {
    ok("katalogda $p", str_contains($auth, "'$p'"));
    ok("admin seed listesinde $p", str_contains($helpers, "'$p'"));
}

echo "\n=== Şema yalnız migrate.php'den ===\n";
$cagiran = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS)) as $f) {
    $yol = (string)$f;
    if (!str_ends_with($yol, '.php') || str_contains($yol, '/vendor/') || str_contains($yol, '/scripts/') || str_contains($yol, '/.git/')) continue;
    if (str_contains(file_get_contents($yol), 'mail_migrate(')) $cagiran[] = substr($yol, strlen($ROOT) + 1);
}
sort($cagiran);
ok('mail_migrate() yalnız tanım + migrate.php', $cagiran === ['config/mail_core.php', 'migrate.php'], implode(',', $cagiran));
ok('migrate.php mail kartı CSRF + audit', str_contains($migrate, "'ne'] ?? '') === 'mail'") && str_contains($migrate, "audit_log_event('migrate', 'mail'"));
ok('mail_core.php şemayı db.php auto-migration\'a eklemiyor', !str_contains(oku('config/db.php'), 'mail_'));
ok('ALTER yok', stripos($core, 'ALTER TABLE') === false);

echo "\n=== Sır / anahtar repoda yok ===\n";
ok('config/local.php .gitignore\'da', str_contains(oku('.gitignore'), 'config/local.php'));
ok('MAIL_MASTER_KEY için sabit/yedek değer YOK', preg_match("/define\(\s*'MAIL_MASTER_KEY'/", $core) === 0 && preg_match('/MAIL_MASTER_KEY[\'"]?\s*(=|=>)\s*[\'"][A-Za-z0-9+\/=]{20,}/', $core) === 0);
$izlenen = shell_exec('cd ' . escapeshellarg($ROOT) . ' && git ls-files') ?: '';
ok('config/local.php izlenmiyor (git ls-files)', !preg_match('#^config/local\.php$#m', $izlenen));
$sizinti = [];
foreach (['config/mail_core.php', 'mail.php', 'mail_hesaplar.php', 'scripts/mail_core_smoke.php'] as $f) {
    if (preg_match('/(password|passwd|pass)\s*=\s*[\'"][^\'"\s]{6,}[\'"]/i', oku($f))) $sizinti[] = $f;
}
ok('mail dosyalarında gömülü şifre atamaları yok', $sizinti === [], implode(',', $sizinti));
ok('audit çağrıları şifre/değer geçirmiyor (mail_hesaplar)', !preg_match("/audit_log_event\([^;]*\\\$_POST\[(?:'imap_pass'|'smtp_pass')\]\s*[,)]/s", oku('mail_hesaplar.php')));
ok('error_log çağrıları mail_redact() ile', preg_match_all('/error_log\(([^;]*)\);/', $core, $m) && !array_filter($m[1], fn($a) => !str_contains($a, 'mail_redact(')),
    json_encode($m[1] ?? []));
ok('şifre alanı forma value= ile geri basılmıyor', !preg_match('/name="(?:imap|smtp)_pass"[^>]*value="<\?=/', oku('mail_hesaplar.php')));

echo "\n=== Service Worker + sürüm ===\n";
ok('sw.js mail yollarını bypass ediyor', str_contains($sw, "/\\/mail(_[a-z]+)?\\.php$/.test(u.pathname)) return;"));
ok('sw.js mail.svg SHELL\'de', str_contains($sw, "'./assets/nav-icons/mail.svg'"));
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $helpers, $a); preg_match("/CACHE_NAME = 'yukleme-plani-(v\d+)'/", $sw, $b);
ok('APP_SURUM == sw.js CACHE_NAME', ($a[1] ?? 'x') === ($b[1] ?? 'y'), ($a[1] ?? '?') . ' vs ' . ($b[1] ?? '?'));
ok('mail.svg dosyası var', is_file($ROOT . '/assets/nav-icons/mail.svg'));
ok('HTTP\'den çalıştırılabilen mail sayfası oturum olmadan açılmaz (require_login)', str_contains(oku('mail.php'), 'require_login()') && str_contains(oku('mail_hesaplar.php'), 'require_login()'));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
