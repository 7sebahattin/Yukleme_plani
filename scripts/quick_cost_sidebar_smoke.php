<?php
// Real sidebar function, anonymous permissions; no DB/bootstrap/migration.
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/role_permission_matrix.php';
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
// GERÇEK can_hizli_maliyet() (config/helpers.php) kaynaktan alınır; kapı kopyası yok.
$gateStart = strpos($source, 'function can_hizli_maliyet(string $perm): bool {');
$gateEnd = strpos($source, '/**', $gateStart);
if ($gateStart === false || $gateEnd === false) throw new RuntimeException('can_hizli_maliyet source not found.');
eval(substr($source, $gateStart, $gateEnd - $gateStart));
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
$mobileStart = strpos($source, 'function nav_alt_sayfalar(): array {');
$mobileEnd = strpos($source, 'function nav_alt_cerez_oku(', $mobileStart);
if ($mobileStart === false || $mobileEnd === false) throw new RuntimeException('Mobile source not found.');
eval(substr($source, $mobileStart, $mobileEnd - $mobileStart));
$iconStart = strpos($source, 'function nav_alt_ikon_url(');
$iconEnd = strpos($source, '/**', $iconStart);
if ($iconStart === false || $iconEnd === false) throw new RuntimeException('Icon URL source not found.');
eval(substr($source, $iconStart, $iconEnd - $iconStart));
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
foreach (['hizli_maliyet.php', 'hizli_maliyet_form.php'] as $quickPage) {
[$html, $xp] = sidebar($quickPage);
qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php"]')->length === 1, 'admin sees exactly one quick link');
qc_sidebar_check(str_contains($html, 'Hızlı Maliyet'), 'requested Turkish label');
qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php" and contains(@class," active")]')->length === 1, 'quick link highlighted');
qc_sidebar_check($xp->query('//a[contains(@class,"sidebar-link") and contains(@class," active")]')->length === 1, 'exactly one active link');
qc_sidebar_check($xp->query('//a[@href="/app/reports.php" and contains(@class," active")]')->length === 0, 'report link not double highlighted');
qc_sidebar_check($xp->query('//details[@data-sb-grup="operasyon" and @open]//a[@href="/app/hizli_maliyet.php"]')->length === 1, 'operation group open');
qc_sidebar_check(nav_aktif_anahtar() === 'qcost', 'list and form share the dedicated mobile item');
qc_sidebar_check(nav_alt_izinler()['qcost'] === true, 'admin mobile entry works without loading cost_calc');
qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php"]//img[starts-with(@src,"/app/assets/nav-icons/qcost.svg?v=") and @alt=""]')->length === 1, 'sidebar uses the shared decorative dollar asset with cache version');
}
qc_sidebar_check(nav_alt_sayfalar()['qcost']['href'] === 'hizli_maliyet.php' && nav_alt_sayfalar()['qcost']['etiket'] === 'Hızlı Maliyet', 'mobile button opens the record list with the requested name');
qc_sidebar_check(nav_alt_sayfalar()['qcost']['ikon'] === 'assets/nav-icons/qcost.svg', 'mobile item uses the same dedicated dollar icon');
foreach (['reports.php','maliyet.php','maliyet_form.php','maliyet_view.php'] as $page) {
    [, $xp] = sidebar($page);
    qc_sidebar_check($xp->query('//a[@href="/app/reports.php" and contains(@class," active")]')->length === 1, 'existing report/cost active route retained: ' . $page);
    qc_sidebar_check($xp->query('//a[@href="/app/hizli_maliyet.php" and contains(@class," active")]')->length === 0, 'quick link not active on ' . $page);
}
$permissions = [];
[$html] = sidebar('hizli_maliyet.php');
qc_sidebar_check(str_contains($html, 'href="/app/hizli_maliyet.php"'), 'admin link independent of reports permission');
// Eski maliyet.* izinleri Hızlı Maliyet'i AÇMAZ (operator/muhasebe kendiliğinden görmez).
$admin = false; $permissions = ['reports.read','maliyet.read','maliyet.write','maliyet.delete','maliyet.admin'];
[$html, $xp] = sidebar('reports.php');
qc_sidebar_check(!str_contains($html, 'hizli_maliyet.php') && !str_contains($html, 'Hızlı Maliyet'), 'legacy maliyet.* user without hizli_maliyet.* cannot see link');
qc_sidebar_check(nav_alt_izinler()['qcost'] === false, 'legacy maliyet.* user gains no mobile item');
qc_sidebar_check($xp->query('//a[@href="/app/reports.php"]')->length === 1, 'non-admin reports access preserved');
// Yetki matrisi: yalnız read / read+write / read+write+delete / yalnız write (okumasız) / hiçbiri.
foreach ([
    [['hizli_maliyet.read'], true, true, false, false],
    [['hizli_maliyet.read', 'hizli_maliyet.write'], true, true, true, false],
    [['hizli_maliyet.read', 'hizli_maliyet.write', 'hizli_maliyet.delete'], true, true, true, true],
    [['hizli_maliyet.read', 'hizli_maliyet.delete'], true, true, false, true],
    [['hizli_maliyet.write', 'hizli_maliyet.delete'], false, false, false, false],
    [[], false, false, false, false],
] as [$perms, $seeLink, $mobile, $write, $delete]) {
    $permissions = $perms;
    $label = json_encode($perms);
    [$html, $xp] = sidebar('hizli_maliyet.php');
    qc_sidebar_check(str_contains($html, 'href="/app/hizli_maliyet.php"') === $seeLink, 'sidebar link ' . $label);
    qc_sidebar_check(($xp->query('//a[@href="/app/hizli_maliyet.php" and contains(@class," active")]')->length === 1) === $seeLink, 'active link ' . $label);
    qc_sidebar_check(nav_alt_izinler()['qcost'] === $mobile, 'mobile gate ' . $label);
    qc_sidebar_check(can_hizli_maliyet('read') === $seeLink && can_hizli_maliyet('write') === $write && can_hizli_maliyet('delete') === $delete, 'gate matrix ' . $label);
}
$admin = true; $permissions = [];
qc_sidebar_check(can_hizli_maliyet('read') && can_hizli_maliyet('write') && can_hizli_maliyet('delete') && !can_hizli_maliyet('unknown'), 'admin passes without any hizli_maliyet row');
qc_sidebar_check(str_contains(file_get_contents(dirname(__DIR__) . '/sw.js'), 'yukleme-plani-' . APP_SURUM), 'PWA and app versions match');
$sw = file_get_contents(dirname(__DIR__) . '/sw.js');
qc_sidebar_check(str_contains($sw, '/\\/hizli_maliyet(?:_form)?\\.php(\\/|$)/'), 'list/form and PATH_INFO bypass share an anchored SW rule');
qc_sidebar_check(str_contains($sw, "'./assets/nav-icons/qcost.svg'"), 'dollar icon included in the PWA shell');
echo 'PASS ' . $checks . " sidebar permission/highlight checks.\n";
