<?php
// =========================================================
// scripts/pdks_kart_tanim_render.php — v298 Kart Havuzu (isci_kartlari.php)
// "🏷 Tanım" modalının tarayıcı testi (pdks_kart_tanim_modal_smoke.js) için
// sayfayı GERÇEK CSS ile tek HTML dosyasına basar: tanım tablosu kurulu,
// bir kart tanımlı (aktif çavuş), bir kart pasif çavuşa tanımlı + kayıp.
// Canlı DB'ye dokunmaz (bellek içi SQLite). PHP uyarısı olursa çıkış kodu 1.
//
//   php scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
set_error_handler(function (int $no, string $msg, string $file, int $line) {
    fwrite(STDERR, "PHP uyarısı: $msg ($file:$line)\n"); exit(1);
});

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test']; }
function can(string $p): bool { return true; }
function is_admin(): bool { return true; }
function active_depot(): ?string { return 'Depo A'; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function csrf_check($t): void {}
function set_flash($a, $b): void {}
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return ''; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function fmt_datetime(?string $d): string { return (string)$d; }
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>" . h($t) . "</title><link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';

function pdks_ddl_sqlite(string $mysql): array
{
    if (!preg_match('/CREATE TABLE IF NOT EXISTS\s+`([^`]+)`\s*\((.*)\)\s*ENGINE=/si', $mysql, $m)) {
        throw new RuntimeException('DDL ayrıştırılamadı: ' . substr($mysql, 0, 60));
    }
    [$tablo, $govde] = [$m[1], $m[2]];
    $parcalar = []; $buf = ''; $d = 0;
    for ($i = 0, $n = strlen($govde); $i < $n; $i++) {
        $c = $govde[$i];
        if ($c === '(') $d++;
        if ($c === ')') $d--;
        if ($c === ',' && $d === 0) { $parcalar[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parcalar[] = trim($buf);
    $kol = []; $ix = [];
    $liste = fn(string $s) => preg_replace('/\s+/', ' ', trim(preg_replace('/`\s*\(\s*\d+\s*\)/', '`', $s)));
    foreach ($parcalar as $p) {
        $p = preg_replace('/\s+/', ' ', $p);
        if (preg_match('/^UNIQUE KEY `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE UNIQUE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^(?:INDEX|KEY) `([^`]+)` \((.+)\)$/i', $p, $mm)) { $ix[] = "CREATE INDEX `{$mm[1]}` ON `$tablo` (" . $liste($mm[2]) . ")"; continue; }
        if (preg_match('/^CONSTRAINT `([^`]+)` FOREIGN KEY/i', $p)) continue;
        $p = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY\b/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $p);
        $p = preg_replace('/\s*ON UPDATE CURRENT_TIMESTAMP\b/i', '', $p);
        $kol[] = $p;
    }
    return ["CREATE TABLE `$tablo` (\n  " . implode(",\n  ", $kol) . "\n)", $ix];
}
function sqlite_kur(string $mysql): void { [$c, $ix] = pdks_ddl_sqlite($mysql); db()->exec($c); foreach ($ix as $x) db()->exec($x); }

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username) VALUES (1, 'test')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) sqlite_kur($sql);
}
foreach (pdks_gunluk_tablolar() as $sql) sqlite_kur($sql);
pdks_gunluk_migrate(db());
sqlite_kur(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
pdks_gunluk_faz8a_migrate(db());
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$a = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Çavuş A'], 1, db())['id'];
$p = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Çok Uzun Adlı Pasif Çavuş Örnek Soyadı'], 1, db())['id'];
pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Çavuş C'], 1, db());
foreach (['K001' => '100000001', 'K002' => '100000002', 'K003' => '100000003'] as $no => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => $no, 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}
$kid = fn(string $no) => (int)db()->query("SELECT id FROM worker_cards WHERE card_no = '$no'")->fetchColumn();
pdks_gunluk_kart_tanim_kaydet($kid('K001'), $a, $kadin, 'Depo A', 1, db());
pdks_gunluk_kart_tanim_kaydet($kid('K002'), $p, $erkek, 'Depo A', 1, db());
pdks_gunluk_cavus_aktiflik($p, false, 1, db());
pdks_gunluk_kart_durum_degistir($kid('K002'), 'lost', 1, db());

$_GET = []; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/isci_kartlari.php';
$src = file_get_contents($ROOT . '/isci_kartlari.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|pdks|pdks_gunluk|auth)\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_kart_tanim_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n"); exit(1); }
$html = ob_get_clean();
@unlink($tmp);
echo $html;
