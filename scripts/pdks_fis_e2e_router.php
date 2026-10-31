<?php
// =========================================================
// scripts/pdks_fis_e2e_router.php — Fiş Excel'i uçtan uca testi için php -S YÖNLENDİRİCİSİ (yalnız test).
//   PDKS_FIS_E2E_DB=<db.sqlite> php -S 127.0.0.1:PORT -t <repo kökü> scripts/pdks_fis_e2e_router.php
// gunluk_fis_yukle.php'yi GERÇEK PHP SAPI'sinde (gerçek multipart yükleme, is_uploaded_file, oturum, başlıklar) çalıştırır;
// yalnız kimlik/şablon/depo yardımcıları sahtedir (kaynak, require satırları çıkarılarak include edilir —
// pdks_puantaj_dialog_render.php deseni). Canlı DB'ye dokunmaz: dosya tabanlı SQLite.
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$ROOT = dirname(__DIR__);
$yol = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
// v464: Fiş düzeltme sayfaları da aynı sahte kimlik/şablon yardımcılarıyla çalışır (pdks_fis_duzelt_smoke.js)
$SAYFALAR = ['/gunluk_fis_yukle.php', '/cavus_fis_geri_al.php', '/cavus_fis_rapor.php'];
if (!in_array($yol, $SAYFALAR, true)) return false;       // assets/ vb. statik dosyaları sunucu kendisi sunar

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
session_start();
$dbYol = (string)getenv('PDKS_FIS_E2E_DB');
$PDO_TEST = new PDO('sqlite:' . $dbYol);
$PDO_TEST->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PDO_TEST->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$ADMIN = getenv('PDKS_FIS_E2E_ADMIN') !== '0';
function db(): PDO { global $PDO_TEST; return $PDO_TEST; }
function current_user(): ?array { return ['id' => 1, 'username' => 'test', 'display_name' => 'Test Yönetici']; }
function can(string $p): bool { return true; }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
function depot_options(): array { return ['Depo A', 'Depo B']; }
function active_depot(): ?string { return $_SESSION['e2e_depo'] ?? 'Depo A'; }
function set_active_depot(string $d): void { $_SESSION['e2e_depo'] = $d; }
function audit_log_event(...$a): void {}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_check($t): void { if (!is_string($t) || !hash_equals(csrf_token(), $t)) { http_response_code(403); echo 'CSRF reddedildi'; exit; } }
function set_flash(string $a, string $b): void { $_SESSION['flash'][] = [$a, $b]; }
function render_flash(): void { foreach ($_SESSION['flash'] ?? [] as [$t, $m]) echo '<div class="flash flash-' . h($t) . '" data-flash="' . h($t) . '">' . h($m) . '</div>'; unset($_SESSION['flash']); }
function forbidden($m = ''): void { http_response_code(403); echo '403 ' . h($m); exit; }
function base_url(): string { return ''; }
function require_login(): array { return current_user(); }
function enforce_active_depot(): void {}
function render_header(string $t, bool $p = false): void {
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($t) . '</title>'
       . '<link rel="stylesheet" href="assets/style.css"></head><body><main class="container">';
}
function render_footer(bool $p = false): void { echo '</main></body></html>'; }

require_once $ROOT . '/config/pdks.php';
require_once $ROOT . '/config/pdks_gunluk.php';
require_once $ROOT . '/config/pdks_hakedis.php';
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_faz8j.php';
require_once $ROOT . '/config/pdks_fis.php';

$src = file_get_contents($ROOT . $yol);
$src = preg_replace('/^\s*require_once __DIR__ \. \'\/config\/(db|auth|pdks_gunluk|pdks_hakedis|pdks_faz8b|pdks_faz8j|pdks_fis)\.php\';.*$/m', '', $src);
$src = preg_replace('/^<\?php\s*$/m', '', $src, 1);
$src = preg_replace('/^declare\(strict_types=1\);\s*$/m', '', $src);
$src = str_replace('__DIR__', var_export($ROOT, true), $src);
$tmp = sys_get_temp_dir() . '/pdks_fis_e2e_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($tmp, "<?php\n" . $src);
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });
include $tmp;
