<?php
// =========================================================
// scripts/pdks_ters_kart_render.php — Ters Kart Takip ekranlarını GERÇEK
// sayfa koduyla (bellek içi SQLite) tek HTML'e basar. Tek amacı tarayıcı
// testine (pdks_ters_kart_smoke.js) girdi üretmektir; canlı DB'ye dokunmaz.
//
//   TK_SAYFA=hub|havuz|sayim|sayim_onayli|sayim_secim|cikis|mutabakat [TK_KURULU=0]
//   php scripts/pdks_ters_kart_render.php > _test_ters_<sayfa>.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
$SAYFA = getenv('TK_SAYFA') ?: 'sayim';
$KURULU = getenv('TK_KURULU') !== '0';
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
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<meta name=\"csrf-token\" content=\"testcsrf\">"
       . "<title>" . h($t) . "</title><link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_ters.php';

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
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test')");
foreach (pdks_tablolar() as $ad => $sql) if (in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) sqlite_kur($sql);
foreach (pdks_gunluk_tablolar() as $sql) sqlite_kur($sql);
pdks_gunluk_migrate(db());
sqlite_kur(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_hakedis_tablolar() as $sql) sqlite_kur($sql);
pdks_faz8b_migrate(db());
sqlite_kur(pdks_gunluk_kart_tanim_tablolar()['worker_card_assignments']);

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$a = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Çavuş Anadolu Uzun İsimli Ekip'], 1, db())['id'];
$b = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Çavuş B'], 1, db())['id'];
pdks_gunluk_cavus_olustur(['code' => 'C003', 'name' => 'Çavuş C'], 1, db());
$saat = date('H') . ':00';

if ($KURULU) {
    foreach (pdks_ters_tablolar() as $sql) sqlite_kur($sql);
    pdks_ters_giris_saati_kaydet($a, $saat, date('Y-m-d'), 'kurulum', 1, db());
    for ($i = 1; $i <= 20; $i++) pdks_ters_havuz_ekle((string)(700000000 + $i), 'usb_decimal', $a, $i <= 12 ? $kadin : $erkek, 1, db());
    for ($i = 1; $i <= 6; $i++) pdks_ters_havuz_ekle((string)(800000000 + $i), 'usb_decimal', $b, $kadin, 1, db());
    $kart19 = (int)db()->query("SELECT worker_card_id FROM ters_havuz_kart WHERE foreman_id = $a AND aktif_kart_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
    pdks_ters_kart_durum($kart19, $a, 'bozuk', 'kırık', 1, db());
    // A: taslak sayım, 3 kalan
    $sa = (int)pdks_ters_sayim_ac($a, 1, db())['sayim']['id'];
    foreach ([3, 7, 11] as $i) pdks_ters_sayim_okut($sa, (string)(700000000 + $i), 'usb_decimal', 1, db());
    // B: onaylı sayım + 2 çıkış
    $sb = (int)pdks_ters_sayim_ac($b, 1, db())['sayim']['id'];
    pdks_ters_sayim_okut($sb, '800000006', 'usb_decimal', 1, db());
    pdks_ters_sayim_onayla($sb, $saat, 'test', '5', bin2hex(random_bytes(16)), 1, db());
    foreach ([1, 2] as $i) pdks_ters_cikis_kaydet((string)(800000000 + $i), 'usb_decimal', 1, db());
}

$dosya = ['hub' => 'ters_kart.php', 'havuz' => 'ters_kart_havuz.php', 'sayim' => 'ters_kart_sayim.php', 'sayim_onayli' => 'ters_kart_sayim.php',
          'sayim_secim' => 'ters_kart_sayim.php', 'cikis' => 'ters_kart_cikis.php', 'mutabakat' => 'ters_kart_mutabakat.php'][$SAYFA] ?? null;
if ($dosya === null) { fwrite(STDERR, "Bilinmeyen TK_SAYFA: $SAYFA\n"); exit(1); }
$_GET = match ($SAYFA) { 'havuz' => ['cavus' => (string)$a], 'sayim' => ['cavus' => (string)$a], 'sayim_onayli' => ['cavus' => (string)$b], default => [] };
$_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . $dosya;
$src = file_get_contents($ROOT . '/' . $dosya);
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[a-z0-9_]+\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_ters_render_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); fwrite(STDERR, 'HATA: ' . $e->getMessage() . ' @' . $e->getLine() . "\n"); exit(1); }
$html = ob_get_clean();
@unlink($tmp);
echo $html;
