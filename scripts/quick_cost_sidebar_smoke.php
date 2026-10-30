<?php
// Real sidebar function, anonymous permissions; no DB/bootstrap/migration.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$source = file_get_contents(dirname(__DIR__) . '/config/helpers.php');
$start = strpos($source, 'function nav_aktif_anahtar(): ?string {');
$sidebar = strpos($source, 'function render_desktop_sidebar(', $start);
$end = strpos($source, '// MOBİL ALT ÇUBUK', $sidebar);
if ($start === false || $sidebar === false || $end === false) throw new RuntimeException('Sidebar source not found.');
$admin = true; $permissions = ['reports.read'];
function can(string $permission): bool { return in_array($permission, $GLOBALS['permissions'], true); }
function is_admin(): bool { return $GLOBALS['admin']; }
function can_mail(string $permission): bool { return false; }
function can_siparis(string $permission): bool { return false; }
function nav_ptak_gorunur(): bool { return false; }
function nav_ptak_sayfalari(): array { return []; }
function nav_muhasebe_gruplari(): array { return []; }
function nav_rozet_html(int $count, bool $short): string { return ''; }
function nav_mail_okunmamis(): int { return 0; }
function current_user(): ?array { return null; }
function active_depot(): ?string { return null; }
function h($text): string { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
preg_match("/define\('APP_SURUM', '(v\d+)'\)/", $source, $version);
define('APP_SURUM', $version[1]);
eval(substr($source, $start, $end - $start));
$checks = 0;
function qc_sidebar_check(bool $value, string $label): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    $GLOBALS['checks']++;
}
function sidebar(string $page): array {
    $_SERVER['PHP_SELF'] = '/' . $page;
    ob_start(); render_desktop_sidebar('/app/'); $html = ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML('<meta charset="utf-8">' . $html);
    return [$html, new DOMXPath($dom)];
}
[$html, $xp] = sidebar('hizli_maliyet.php');
qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php"]')->length === 1, 'admin sees exactly one quick link');
qc_sidebar_check(str_contains($html, 'Hızlı Maliyet'), 'requested Turkish label');
qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php" and contains(@class," active")]')->length === 1, 'quick link highlighted');
qc_sidebar_check($xp->query('//a[contains(@class,"sidebar-link") and contains(@class," active")]')->length === 1, 'exactly one active link');
qc_sidebar_check($xp->query('//a[@href="/app/reports.php" and contains(@class," active")]')->length === 0, 'report link not double highlighted');
qc_sidebar_check($xp->query('//details[@data-sb-grup="operasyon" and @open]//a[@href="/app/hizli_maliyet.php"]')->length === 1, 'operation group open');
qc_sidebar_check(nav_aktif_anahtar() === 'rapor', 'mobile family retained');
foreach (['reports.php','maliyet.php','maliyet_form.php','maliyet_view.php'] as $page) {
    [, $xp] = sidebar($page);
    qc_sidebar_check($xp->query('//a[@href="/app/reports.php" and contains(@class," active")]')->length === 1, 'existing report/cost active route retained: ' . $page);
    qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php" and contains(@class," active")]')->length === 0, 'quick link not active on ' . $page);
}
$permissions = [];
[$html] = sidebar('hizli_maliyet.php');
qc_sidebar_check(str_contains($html, 'href="/app/hizli_maliyet.php"'), 'admin link independent of reports permission');
$admin = false; $permissions = ['reports.read','maliyet.read','maliyet.write'];
[$html, $xp] = sidebar('reports.php');
qc_sidebar_check(!str_contains($html, 'hizli_maliyet.php') && !str_contains($html, 'Hızlı Maliyet'), 'cost reader/writer without admin cannot see link');
qc_sidebar_check($xp->query('//a[@href="/app/reports.php"]')->length === 1, 'non-admin reports access preserved');
qc_sidebar_check(str_contains(file_get_contents(dirname(__DIR__) . '/sw.js'), 'yukleme-plani-' . APP_SURUM), 'PWA and app versions match');
echo 'PASS ' . $checks . " sidebar permission/highlight checks.\n";
