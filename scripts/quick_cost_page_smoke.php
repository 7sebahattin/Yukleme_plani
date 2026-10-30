<?php
// Anonymous controller tests/render fixture. Never load application bootstrap or DB config.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/quick_cost_store.php';
require_once $root . '/config/quick_cost_schema.php';
$testPdo = new PDO('sqlite::memory:');
$testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$testPdo->exec('CREATE TABLE quick_cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT, sheet_date TEXT, operation_type TEXT, method TEXT, net_kg NUMERIC, document_json TEXT, total NUMERIC, unit_cost NUMERIC, notes TEXT, depo TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, version INTEGER)');
$testPdo->exec('CREATE TABLE cost_sheets (id INTEGER PRIMARY KEY, marker TEXT)');
$testPdo->exec("INSERT INTO cost_sheets VALUES (1,'untouched')");
$testPermissions = ['read', 'write'];
$testAdmin = true;
$testDepot = 'DEMO';
$testStatus = 200;
$testHeaders = [];
$testAudit = [];
function db(): PDO { return $GLOBALS['testPdo']; }
function current_user(): array { return ['id' => 7]; }
function active_depot(): string { return $GLOBALS['testDepot']; }
function can_maliyet(string $permission): bool { return in_array($permission, $GLOBALS['testPermissions'], true); }
function is_admin(): bool { return $GLOBALS['testAdmin']; }
function require_maliyet(string $permission): void { if (!can_maliyet($permission)) forbidden('permission'); }
function require_login(): array { return current_user(); }
function forbidden(string $message): void { test_status(403); throw new QuickPageExit($message); }
function depo_sql_in(string $column): array { return ["$column IN (?)", [active_depot()]]; }
function depot_visible_to_user(?string $depot): bool { return $depot === active_depot(); }
function csrf_check(?string $token): void { if ($token !== 'anonymous-csrf') forbidden('csrf'); }
function csrf_token(): string { return 'anonymous-csrf'; }
function set_flash(string $kind, string $message): void {}
function render_flash(): void {}
function audit_log_event(...$args): void { $GLOBALS['testAudit'][] = $args; }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function test_header(string $value): void { $GLOBALS['testHeaders'][] = $value; }
function test_status(int $status): void { $GLOBALS['testStatus'] = $status; }
function render_header(string $title): void {
    echo '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="assets/style.css"><title>' . h($title) . '</title></head><body><main class="container">';
}
function render_footer(): void {
    echo '</main><nav class="bottomnav" style="position:fixed;bottom:0;left:0;right:0;height:var(--bn-h);background:var(--card);border-top:1px solid var(--border);z-index:200;display:flex;align-items:center;justify-content:space-around"><span>Ana Sayfa</span><span>Raporlar</span><span>Diğer</span></nav></body></html>';
}
class QuickPageExit extends Exception {}

// Apply the repository's established isolated-page harness technique: only bootstrap,
// response headers and exit are intercepted; actual controller/partial/store run.
$source = file_get_contents($root . '/hizli_maliyet.php');
$source = preg_replace("/^require_once __DIR__ \\. '\/config\/[^']+\.php';\r?\n/m", '', $source);
$source = preg_replace('/^declare\(strict_types=1\);\r?\n/m', '', $source);
$source = str_replace('__DIR__', var_export($root, true), $source);
$source = preg_replace('/(?<![\w>:])header\(/', 'test_header(', $source);
$source = str_replace('http_response_code(', 'test_status(', $source);
$source = str_replace('exit;', 'throw new QuickPageExit();', $source);
$source = preg_replace('/\bexit\(([^;]+)\);/', 'throw new QuickPageExit($1);', $source);
// First run declares the recovery helper; later runs use the same real function.
$helperStart = strpos($source, '/** Recover ordinary');
$helperEnd = strpos($source, '$id_raw =');
$helper = substr($source, $helperStart, $helperEnd - $helperStart);
eval($helper);
$source = substr_replace($source, '', $helperStart, $helperEnd - $helperStart);

function page(array $get = [], array $post = []): array {
    global $source;
    $_GET = $get; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $GLOBALS['testStatus'] = 200; $GLOBALS['testHeaders'] = [];
    ob_start();
    try { eval('?>' . $source); } catch (QuickPageExit $e) {}
    return ['status' => $GLOBALS['testStatus'], 'headers' => $GLOBALS['testHeaders'], 'html' => ob_get_clean()];
}
$checks = 0;
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException('FAIL: ' . $message);
    $GLOBALS['checks']++;
}
function postDocument(array $document, string $version = '0', string $csrf = 'anonymous-csrf'): array {
    return ['payload' => json_encode($document, JSON_THROW_ON_ERROR), 'version' => $version, 'csrf' => $csrf];
}

$document = quick_cost_defaults('2026-10-30');
$document['product'] = 'Örnek ürün'; $document['net_kg'] = 20000;
$prices = ['product'=>25,'material'=>4,'transport'=>1.6,'labor'=>2,'overhead'=>.5,'customs'=>4500,'official_analysis'=>1500,'private_analysis'=>2000];
foreach ($document['items'] as &$row) $row['unit_price'] = $prices[$row['code']] ?? 0;
unset($row);

if (($argv[1] ?? '') === '--render') {
    // Static anonymous HTML includes actual form, CSS and JS; no PHP web server.
    $saved = quick_cost_save(db(), $document);
    echo page(['id' => (string)$saved['id']])['html'];
    exit;
}

$result = page();
check($result['status'] === 200 && str_contains($result['html'], 'qc-form'), 'new page renders');
check(in_array('Cache-Control: no-store, private', $result['headers'], true), 'no-store header');
check(in_array('Location: hizli_maliyet.php', page([], ['action'=>'install_schema','csrf'=>'anonymous-csrf'])['headers'], true), 'admin explicit setup is idempotent when ready');
check(page([], ['action'=>'install_schema','csrf'=>'bad'])['status'] === 403, 'setup requires CSRF');
$testAdmin = false;
check(page([], ['action'=>'install_schema','csrf'=>'anonymous-csrf'])['status'] === 403, 'setup denies non-admin writer');
check(page()['status'] === 403, 'non-admin read/write user cannot open quick page');
check(page([], postDocument($document))['status'] === 403, 'non-admin read/write user cannot create');
$testAdmin = true;
$testPermissions = [];
check(page()['status'] === 403, 'read denied');
$testPermissions = ['read'];
check(page([], postDocument($document))['status'] === 403, 'read-only POST denied');
check(page()['status'] === 200, 'read-only GET renders');
$testPermissions = ['read','write'];
check(page([], postDocument($document, '0', 'bad'))['status'] === 403, 'CSRF denied');
check((int)db()->query('SELECT COUNT(*) FROM quick_cost_sheets')->fetchColumn() === 0, 'denials no writes');
$result = page([], postDocument($document));
check(in_array('Location: hizli_maliyet.php?id=1', $result['headers'], true), 'create redirects to saved record');
check((float)quick_cost_find(db(), 1)['document']['total'] === 670000.0, 'server stored requested example');
check(str_contains(page(['id'=>'1'])['html'], '670.000,00'), 'reopen summary same total');
$testAdmin = false;
check(page(['id'=>'1'])['status'] === 403, 'non-admin cannot open saved quick record');
check(page(['id'=>'1','copy'=>'1'])['status'] === 403, 'non-admin cannot copy saved quick record');
check(page(['id'=>'1'], postDocument($document, '1'))['status'] === 403, 'non-admin cannot update saved quick record');
$testAdmin = true;
check(quick_cost_find(db(), 1)['version'] === 1, 'non-admin quick-record denials preserve saved version');
$document['operation_type'] = 'uretim'; $document['method'] = 'detailed';
page(['id'=>'1'], postDocument($document, '1'));
check(quick_cost_find(db(), 1)['document']['operation_type'] === 'uretim', 'operation update');
$document['notes'] = 'Korunan taslak';
$conflict = page(['id'=>'1'], postDocument($document, '1'));
check($conflict['status'] === 409 && str_contains($conflict['html'], 'Korunan taslak'), 'conflict preserves draft');
page(['id'=>'1','copy'=>'1'], postDocument($document));
check((int)db()->query('SELECT COUNT(*) FROM quick_cost_sheets')->fetchColumn() === 2, 'copy makes new row');
check(quick_cost_find(db(), 1)['version'] === 2, 'copy source unchanged');
$testDepot = 'OTHER';
check(page(['id'=>'1'])['status'] === 404, 'other depot GET hides row');
check(page(['id'=>'1'], postDocument($document, '2'))['status'] === 404, 'other depot POST denied');
$testDepot = 'DEMO';
check(page(['id'=>'bad'])['status'] === 400, 'invalid id denied');
check(page([], ['payload'=>'null','csrf'=>'anonymous-csrf','version'=>'0'])['status'] === 422, 'scalar JSON denied');
check(page([], ['payload'=>[],'csrf'=>'anonymous-csrf','version'=>'0'])['status'] === 422, 'array transport denied');
$document['items'][0]['unit_price'] = '-1,00';
$bad = page([], postDocument($document));
check($bad['status'] === 422 && str_contains($bad['html'], '-1,00'), 'invalid typed price retained');
$document['items'][0]['unit_price'] = 25;
$document['product'] = '</script><img src=x onerror=alert(1)>';
$html = page([], postDocument($document, 'invalid'))['html'];
check(!str_contains($html, '<img src=x'), 'product escaped in markup and inline config');
db()->exec("UPDATE quick_cost_sheets SET document_json='broken' WHERE id=2");
check(page(['id'=>'2'])['status'] === 503, 'corrupt JSON fails safely');
db()->exec('DROP TABLE quick_cost_sheets');
$missing = page();
check($missing['status'] === 200 && str_contains($missing['html'], 'disabled'), 'missing schema renders disabled save');
check(str_contains($missing['html'], 'Yeni maliyet tablosunu kur'), 'admin sees explicit setup form when missing');
$testAdmin = false;
$nonAdminMissing = page();
check($nonAdminMissing['status'] === 403 && !str_contains($nonAdminMissing['html'], 'Yeni maliyet tablosunu kur'), 'non-admin missing-schema page is forbidden');
$testAdmin = true;
check(page(['action'=>'install_schema'])['status'] === 200 && !quick_cost_storage_ready(db()), 'GET cannot create schema');
check(page([], postDocument($document))['status'] === 422, 'missing schema no runtime migration');
check(db()->query('SELECT marker FROM cost_sheets WHERE id=1')->fetchColumn() === 'untouched', 'legacy record unchanged');
// Execute the real navigation function without loading helpers' migration IIFE.
$helpers = file_get_contents($root . '/config/helpers.php');
$start = strpos($helpers, 'function nav_aktif_anahtar(): ?string {');
$end = strpos($helpers, 'function render_desktop_sidebar(', $start);
if ($start === false || $end === false) throw new RuntimeException('Navigation extraction failed.');
function nav_muhasebe_gruplari(): array { return []; }
function nav_ptak_sayfalari(): array { return []; }
eval(substr($helpers, $start, $end - $start));
$_SERVER['PHP_SELF'] = '/hizli_maliyet.php';
check(nav_aktif_anahtar() === 'rapor', 'quick page belongs to cost/report navigation');
foreach (['maliyet.php','maliyet_form.php','maliyet_view.php'] as $oldPage) {
    $_SERVER['PHP_SELF'] = '/' . $oldPage;
    check(nav_aktif_anahtar() === 'rapor', 'legacy cost page navigation unchanged');
}
echo 'PASS ' . $checks . " controller checks (anonymous SQLite; no application bootstrap).\n";
