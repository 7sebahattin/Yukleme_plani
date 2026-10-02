<?php
// =========================================================
// scripts/pdks_puantaj_dialog_render.php — gunluk_isci_puantaj_detay.php'yi
// YÖNETİCİ olarak, GERÇEK CSS ile tek bir HTML dosyasına basar. Tek amacı
// tarayıcı testine (pdks_puantaj_dialog_smoke.js) girdi üretmektir; canlı
// DB'ye dokunmaz (bellek içi SQLite — pdks_gunluk_faz3_ui_smoke.php deseni).
//
//   php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

if (PHP_SAPI !== 'cli') { http_response_code(403); die('Yalnız CLI.'); }

$ROOT = dirname(__DIR__);
$PDO_TEST = new PDO('sqlite::memory:');
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }

function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Kullanıcı']; }
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
       . "<title>" . h($t) . "</title>"
       . "<link rel=\"stylesheet\" href=\"assets/style.css\">"
       . "</head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_faz8h.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_hakedis.php';

// MySQL DDL → SQLite (pdks_gunluk_faz3_ui_smoke.php ile aynı çevirici)
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

db()->exec("CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `username` VARCHAR(60) NOT NULL, `display_name` VARCHAR(150) NULL, `is_active` INTEGER NOT NULL DEFAULT 1)");
db()->exec("INSERT INTO users (id, username, display_name) VALUES (1, 'test', 'Test Kullanıcı')");
db()->exec("CREATE TABLE `audit_log` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `user_id` INT, `action` TEXT, `module` TEXT, `record_id` INT, `old_values` TEXT, `new_values` TEXT, `ip` TEXT, `user_agent` TEXT, `created_at` TEXT DEFAULT CURRENT_TIMESTAMP)");
foreach (pdks_tablolar() as $ad => $sql) {
    if (!in_array($ad, ['employees', 'employee_cards', 'employee_card_uids'], true)) continue;
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
foreach (pdks_gunluk_tablolar() as $sql) {
    [$c, $ix] = pdks_ddl_sqlite($sql); db()->exec($c); foreach ($ix as $x) db()->exec($x);
}
pdks_gunluk_migrate(db());
// Faz 8A tablosu migrate()'ten ÖNCE DDL çeviriciyle kurulur (ham MySQL
// CREATE SQLite'ta çalışmaz — pdks_gunluk_faz8a_ui_smoke.php ile aynı not).
[$c, $ix] = pdks_ddl_sqlite(pdks_gunluk_faz8a_tablolar()['daily_worker_work_periods']);
db()->exec($c); foreach ($ix as $x) db()->exec($x);
pdks_gunluk_faz8a_migrate(db());
pdks_faz8j_migrate(db());

// Test verisi: tek mesai, birkaç kart (biri çıkışsız) — her satır için
// Düzenle + İptal <dialog>'u çizilir.
$kadin = (int)db()->query("SELECT id FROM worker_types WHERE code='KADIN'")->fetchColumn();
$cavus = (int)pdks_gunluk_cavus_olustur(['code' => 'C001', 'name' => 'Test Çavuş'], 1, db())['id'];
$o = pdks_gunluk_oturum_ac_veya_getir($cavus, 1, db());
$sid = (int)$o['session']['id'];
foreach (['631799511', '111222333', '444555666', '777888999', '123123123', '456456456'] as $i => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => 'K00' . ($i + 1), 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
    $r = pdks_gunluk_faz8a_giris_kaydet($uid, 'usb_decimal', $sid, $kadin, 'auto', 1, db());
    if (!($r['ok'] ?? false)) { fwrite(STDERR, 'giriş: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
}
pdks_gunluk_faz8a_cikis_kaydet('631799511', 'usb_decimal', $sid, 1, db());

// v291: ekle penceresinde seçilebilecek, o gün KULLANILMAMIŞ iki kart.
foreach (['321321321', '654654654'] as $i => $uid) {
    pdks_gunluk_kart_olustur(['card_no' => 'B00' . ($i + 1), 'ham_uid' => $uid, 'kaynak' => 'usb_decimal'], 1, db());
}
// v291: bir dönemi "elle eklendi" (source=manual) say — rozet testi için.
db()->exec("UPDATE daily_worker_work_periods SET source = 'manual' WHERE id = 3");

// Sayfa seçimi: varsayılan = mesai detayı. PUANTAJ_SAYFA=liste → Günlük Puantaj listesi
// (PUANTAJ_TARIH=bugun|dun). Liste sayfası geçmiş gün + yönetici iken "ekle" penceresini basar.
$sayfa = getenv('PUANTAJ_SAYFA') === 'liste' ? 'gunluk_isci_puantaj.php' : 'gunluk_isci_puantaj_detay.php';
$_GET = $sayfa === 'gunluk_isci_puantaj.php'
    ? ['tarih' => getenv('PUANTAJ_TARIH') === 'bugun' ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'))]
    : ['id' => (string)$sid];
$_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . $sayfa;
$src = file_get_contents($ROOT . '/' . $sayfa);
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|pdks_gunluk|pdks_faz8h|pdks_faz8j|pdks_hakedis|auth)\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_puantaj_dialog_' . getmypid() . '.php';
file_put_contents($tmp, "<?php\n" . $src);
ob_start();
try { include $tmp; } catch (Throwable $e) { ob_end_clean(); @unlink($tmp); fwrite(STDERR, 'HATA: ' . $e->getMessage() . "\n"); exit(1); }
$html = ob_get_clean();
@unlink($tmp);
// Göreli assets/ yolları repo kökünden çözülsün diye çıktı kökte durur.
echo $html;
