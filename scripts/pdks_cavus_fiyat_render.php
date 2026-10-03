<?php
// =========================================================
// scripts/pdks_cavus_fiyat_render.php — cavus_fiyatlari.php'yi (v297-A
// yeni görünüm) GERÇEK CSS ile tek HTML dosyasına basar. Tek amacı tarayıcı
// testine (pdks_cavus_fiyat_smoke.js) girdi üretmektir; canlı DB'ye
// dokunmaz (bellek içi SQLite + gerçek DDL çevirici + gerçek fonksiyonlar).
//
//   CAVUS_FIYAT_SENARYO=dolu php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat.html
//   CAVUS_FIYAT_SENARYO=bos  php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat_bos.html
//
//   dolu: fiyatlı çavuş, Yöntem B (30 kişi-gün = 1 hakediş), çavuş ücreti var
//   bos : fiyatsız çavuş, Yöntem A, hiç çavuş ücreti yok, v299 saat kolonları KURULU DEĞİL
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
$SENARYO = getenv('CAVUS_FIYAT_SENARYO') ?: 'dolu';
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
function get_flash(): ?array { return null; }
function render_flash(): void {}
function forbidden($m = ''): void { throw new RuntimeException('forbidden: ' . $m); }
function base_url(): string { return ''; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\">"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>" . h($t) . "</title><link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_cari.php';
require_once $ROOT . '/config/pdks_faz8b_cavus_b.php';

// MySQL DDL → SQLite (diğer *_render.php / *_ui_smoke.php ile aynı çevirici)
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
function ddl_kur(array $tablolar): void {
    foreach ($tablolar as $sql) { [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x); }
}

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` TEXT, `display_name` TEXT, `is_active` INTEGER DEFAULT 1)");
db()->exec("INSERT INTO users (id, username) VALUES (1, 'test')");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    ddl_kur([$sql]);
}
ddl_kur(pdks_gunluk_tablolar());
ddl_kur(pdks_hakedis_tablolar());
pdks_gunluk_migrate(db());
ddl_kur([pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']]);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8b_migrate(db());
ddl_kur(pdks_cari_tablolar());
ddl_kur(pdks_faz8b_cavus_ucret_tablolar());
ddl_kur(pdks_faz8b_cavus_ucret_b_tablolar());
ddl_kur(pdks_servis_tablolar());   // v299 Servis Ücreti kartı

$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$erkek = (int)db()->query("SELECT id FROM worker_types WHERE code='ERKEK'")->fetchColumn();
$a = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Çavuş A'], 1, db())['id'];
$b = (int)pdks_gunluk_cavus_olustur(['code' => 'C002', 'name' => 'Çavuş B'], 1, db())['id'];

if ($SENARYO === 'dolu') {
    // v299: saat kolonları kurulu; KADIN dönemi saatli + Çift Yevmiyeli.
    pdks_faz8b_saat_kolonlari_migrate(db());
    $rs = pdks_faz8b_oran_ekle($a, $kadin, '1200', '700', 'hourly', '150', '2026-01-01', 'TRY', 1, db(), [
        'full_day' => '9', 'half_day' => '5', 'overtime_start' => '9:30', 'double_day' => '12', 'double_day_rate' => '2000',
    ]);
    if (!($rs['ok'] ?? false)) { fwrite(STDERR, 'HATA: ' . json_encode($rs, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    pdks_faz8b_oran_ekle($a, $erkek, '1500', '900', 'fixed', '400', '2026-01-01', 'TRY', 1, db());
    pdks_faz8b_cavus_ucret_ekle($a, '2500', '2026-01-01', 'TRY', 1, db());
    pdks_servis_ucret_ekle($a, '1000', '', '2026-01-01', 'TRY', 1, db());
    pdks_servis_ucret_ekle($a, '1500', '800', '2026-03-01', 'TRY', 1, db());
    $r = pdks_faz8b_cavus_ucret_yontem_degistir($a, 'B', 1, db(), '30');
    if (!($r['ok'] ?? false)) { fwrite(STDERR, 'HATA: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
    $cavus = $a;
} else {
    $cavus = $b;
}

$_GET = ['cavus' => (string)$cavus]; $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cavus_fiyatlari.php';
$src = file_get_contents($ROOT . '/cavus_fiyatlari.php');
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/[a-z0-9_]+\.php\';.*$/m', '', $src);
$src = preg_replace('/^\s*\$auth_user = require_login\(\);\s*$/m', '$auth_user = current_user();', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_cavus_fiyat_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n"); exit(1); }
$html = ob_get_clean();
@unlink($tmp);
if (preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html)) { fwrite(STDERR, "HATA: PHP uyarısı çıktıda\n"); exit(1); }
echo $html;
