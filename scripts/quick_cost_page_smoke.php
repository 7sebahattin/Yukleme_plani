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
$testPermissions = ['read', 'write', 'delete'];
$testAdmin = false; // varsayılan: yönetici DEĞİL; yetki yalnız hizli_maliyet.* izinlerinden
$testDepot = 'DEMO';
$testStatus = 200;
$testHeaders = [];
$testAudit = [];
function db(): PDO { return $GLOBALS['testPdo']; }
function current_user(): array { return ['id' => 7]; }
function active_depot(): string { return $GLOBALS['testDepot']; }
// config/helpers.php can_hizli_maliyet() aynası: admin her zaman geçer; write/delete okumayı da ister.
function can_hizli_maliyet(string $permission): bool {
    if ($GLOBALS['testAdmin']) return in_array($permission, ['read', 'write', 'delete'], true);
    $p = $GLOBALS['testPermissions'];
    return in_array('read', $p, true) && in_array($permission, $p, true);
}
// Eski maliyet.* kapısı Hızlı Maliyet'te KULLANILMAMALI.
function can_maliyet(string $permission): bool { throw new LogicException('Hızlı Maliyet can_maliyet() kullanmamalı.'); }
function is_admin(): bool { return $GLOBALS['testAdmin']; }
function require_maliyet(string $permission): void { throw new LogicException('Hızlı Maliyet require_maliyet() kullanmamalı.'); }
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

// Only bootstrap, response headers, exit and the fixed internal form dispatch are
// intercepted. Actual list/form controllers, partial and store run; no bootstrap
// can be reached by a legacy POST include in this isolated harness.
function quick_page_source(string $file): string {
    global $root;
    $source = file_get_contents($root . '/' . $file);
    $source = preg_replace("/^require_once __DIR__ \\. '\/config\/[^']+\.php';\r?\n/m", '', $source);
    $source = preg_replace('/^declare\(strict_types=1\);\r?\n/m', '', $source);
    $source = str_replace("require __DIR__ . '/hizli_maliyet_form.php';", "quick_page_dispatch('form');", $source);
    $source = str_replace('__DIR__', var_export($root, true), $source);
    $source = preg_replace('/(?<![\w>:])header\(/', 'test_header(', $source);
    $source = str_replace('http_response_code(', 'test_status(', $source);
    $source = str_replace('exit;', 'throw new QuickPageExit();', $source);
    return preg_replace('/\bexit\(([^;]+)\);/', 'throw new QuickPageExit($1);', $source);
}
$testSources = ['list' => quick_page_source('hizli_maliyet.php'), 'form' => quick_page_source('hizli_maliyet_form.php')];
$helperStart = strpos($testSources['form'], '/** Recover ordinary');
$helperEnd = strpos($testSources['form'], '$id_raw =');
if ($helperStart === false || $helperEnd === false) throw new RuntimeException('Form helper extraction failed.');
eval(substr($testSources['form'], $helperStart, $helperEnd - $helperStart));
$testSources['form'] = substr_replace($testSources['form'], '', $helperStart, $helperEnd - $helperStart);
function quick_page_dispatch(string $route): void {
    global $testSources;
    if (!isset($testSources[$route])) throw new RuntimeException('Unknown controller fixture.');
    eval('?>' . $testSources[$route]);
}

function page(array $get = [], array $post = [], string $route = 'form'): array {
    $_GET = $get; $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
    $GLOBALS['testStatus'] = 200; $GLOBALS['testHeaders'] = [];
    ob_start();
    try { quick_page_dispatch($route); } catch (QuickPageExit $e) {}
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

if (in_array(($argv[1] ?? ''), ['--render', '--render-list'], true)) {
    // Static anonymous HTML includes actual form, CSS and JS; no PHP web server.
    $variant = $argv[2] ?? '';
    if (!in_array($variant, ['', 'readonly', 'legacy', 'custom'], true)) throw new InvalidArgumentException('Unknown render fixture.');
    if ($variant === 'legacy') {
        $document['method'] = 'detailed';
        $document['operation_type'] = 'uretim';
        foreach ($document['items'] as &$item) {
            if ($item['code'] === 'transport') {
                $item['unit'] = 'pallet'; $item['basis'] = 'manual';
                $item['qty'] = 26; $item['unit_price'] = 380;
            }
        }
        unset($item);
    }
    if ($variant === 'custom') {
        $document['items'][] = ['code'=>'custom_fixture','label'=>'ek palet','unit'=>'pallet','basis'=>'manual','qty'=>26,'unit_price'=>380];
    }
    $saved = quick_cost_save(db(), $document);
    if ($variant === 'readonly') $testPermissions = ['read'];
    echo ($argv[1] === '--render-list' ? page([], [], 'list') : page(['id' => (string)$saved['id']]))['html'];
    exit;
}

$result = page();
check($result['status'] === 200 && str_contains($result['html'], 'qc-form'), 'new page renders');
check(in_array('Cache-Control: no-store, private', $result['headers'], true), 'no-store header');
check(!str_contains(page([], [], 'list')['html'], 'id="qc-form"') && str_contains(page([], [], 'list')['html'], 'href="hizli_maliyet_form.php"'), 'root is a list with a separate new-form action');
check(!str_contains($result['html'], 'id="qc-records"'), 'new form does not contain saved list');
check(in_array('Location: hizli_maliyet_form.php?id=1&copy=1', page(['id'=>'1','copy'=>'1'], [], 'list')['headers'], true), 'legacy GET copy redirects to fixed form route');
check(page(['id'=>[]], [], 'list')['status'] === 400, 'legacy GET invalid id rejected');
$testAdmin = true;
check(in_array('Location: hizli_maliyet.php', page([], ['action'=>'install_schema','csrf'=>'anonymous-csrf'], 'list')['headers'], true), 'admin explicit setup is idempotent when ready');
check(page([], ['action'=>'install_schema','csrf'=>'bad'], 'list')['status'] === 403, 'setup requires CSRF');
$testAdmin = false;
check(page([], ['action'=>'install_schema','csrf'=>'anonymous-csrf'], 'list')['status'] === 403, 'setup denies non-admin writer');
check(page([], [], 'list')['status'] === 200 && page()['status'] === 200, 'non-admin with hizli_maliyet read/write/delete opens list and form');
$testPermissions = []; // eski maliyet.* izni olsa bile hizli_maliyet.* yok
check(page([], [], 'list')['status'] === 403, 'user without hizli_maliyet permission cannot open saved list');
check(page()['status'] === 403, 'user without hizli_maliyet permission cannot open quick page');
check(page([], postDocument($document))['status'] === 403, 'user without hizli_maliyet permission cannot create');
$testPermissions = ['write','delete']; // okuma olmadan write/delete tek başına geçmez
check(page()['status'] === 403 && page([], [], 'list')['status'] === 403, 'write/delete without read denied');
$testPermissions = [];
check(page()['status'] === 403, 'read denied');
$testPermissions = ['read'];
check(page([], postDocument($document))['status'] === 403, 'read-only POST denied');
check(page()['status'] === 200, 'read-only GET renders');
$testPermissions = ['read','write','delete'];
check(page([], postDocument($document, '0', 'bad'))['status'] === 403, 'CSRF denied');
check((int)db()->query('SELECT COUNT(*) FROM quick_cost_sheets')->fetchColumn() === 0, 'denials no writes');
$result = page([], postDocument($document));
check(in_array('Location: hizli_maliyet.php', $result['headers'], true), 'create redirects to saved list');
check((float)quick_cost_find(db(), 1)['document']['total'] === 670000.0, 'server stored requested example');
check(str_contains(page(['id'=>'1'])['html'], '670.000,00'), 'reopen summary same total');
$testPermissions = [];
check(page(['id'=>'1'])['status'] === 403, 'user without permission cannot open saved quick record');
check(page(['id'=>'1','copy'=>'1'])['status'] === 403, 'user without permission cannot copy saved quick record');
check(page(['id'=>'1'], postDocument($document, '1'))['status'] === 403, 'user without permission cannot update saved quick record');
$testPermissions = ['read'];
check(page(['id'=>'1'])['status'] === 200 && str_contains(page(['id'=>'1'])['html'], '"readOnly":true'), 'read-only user opens saved record read-only');
check(page(['id'=>'1','copy'=>'1'], postDocument($document))['status'] === 403 && page(['id'=>'1'], postDocument($document, '1'))['status'] === 403, 'read-only user cannot copy or update');
$testPermissions = ['read','write','delete'];
check(quick_cost_find(db(), 1)['version'] === 1, 'denials preserve saved version');
$document['operation_type'] = 'uretim'; $document['method'] = 'detailed';
page(['id'=>'1'], postDocument($document, '1'));
check(quick_cost_find(db(), 1)['document']['operation_type'] === 'fason' && quick_cost_find(db(), 1)['document']['method'] === 'quick', 'hidden operation and method pinned to original record');
$document['notes'] = 'Korunan taslak';
$conflict = page(['id'=>'1'], postDocument($document, '1'));
check($conflict['status'] === 409 && str_contains($conflict['html'], 'KORUNAN TASLAK'), 'conflict preserves draft');
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
check(stripos($html, '<img src=x') === false, 'product escaped in markup and inline config');
$baseDocument = quick_cost_find(db(), 1)['document'];
$legacyDocument = $baseDocument;
$legacyDocument['product'] = 'eski adres'; $legacyDocument['operation_type'] = 'uretim'; $legacyDocument['method'] = 'detailed';
$legacyCreate = page([], postDocument($legacyDocument), 'list');
$legacyId = (int)db()->query('SELECT MAX(id) FROM quick_cost_sheets')->fetchColumn();
check(in_array('Location: hizli_maliyet.php', $legacyCreate['headers'], true), 'legacy root POST saves same body without redirect loss');
check(quick_cost_find(db(), $legacyId)['document']['operation_type'] === 'uretim' && quick_cost_find(db(), $legacyId)['document']['method'] === 'detailed', 'legacy new-record metadata accepted and persisted');
$legacyDocument['operation_type'] = 'fason'; $legacyDocument['method'] = 'quick';
page(['id'=>(string)$legacyId], postDocument($legacyDocument, '1'), 'list');
check(quick_cost_find(db(), $legacyId)['version'] === 2 && quick_cost_find(db(), $legacyId)['document']['method'] === 'detailed', 'legacy root POST retains id/version and pins original hidden metadata');
$legacyDocument['notes'] = 'eski adres çakışma taslağı';
$legacyConflict = page(['id'=>(string)$legacyId], postDocument($legacyDocument, '1'), 'list');
check($legacyConflict['status'] === 409 && str_contains($legacyConflict['html'], 'ESKİ ADRES ÇAKIŞMA TASLAĞI'), 'legacy root conflict preserves original draft on same request');
page(['id'=>(string)$legacyId,'copy'=>'1'], postDocument($legacyDocument), 'list');
$legacyCopyId = (int)db()->query('SELECT MAX(id) FROM quick_cost_sheets')->fetchColumn();
check($legacyCopyId !== $legacyId && quick_cost_find(db(), $legacyId)['version'] === 2 && quick_cost_find(db(), $legacyCopyId)['document']['operation_type'] === 'uretim', 'legacy root copy preserves body/source metadata and leaves source unchanged');
$legacyDocument['items'][0]['unit_price'] = '-9,00';
$legacyBad = page(['id'=>(string)$legacyId], postDocument($legacyDocument, '2'), 'list');
check($legacyBad['status'] === 422 && str_contains($legacyBad['html'], '-9,00') && !array_filter($legacyBad['headers'], static fn($header) => str_starts_with($header, 'Location:')), 'legacy root invalid price remains editable without HTTP redirect');
check(page([], ['payload'=>[],'csrf'=>'anonymous-csrf','version'=>'0'], 'list')['status'] === 422, 'legacy array payload dispatches safely to form validation');
check(page([], array_merge(postDocument($baseDocument), ['action'=>'unknown']), 'list')['status'] === 422, 'unknown list action never falls through to payload save');

$customDocument = $baseDocument;
$customDocument['product'] = 'kiraz'; $customDocument['notes'] = 'özel iş';
$customDocument['items'][] = ['code'=>'custom_manual','label'=>'özel taşıma','unit'=>'pallet','basis'=>'manual','qty'=>26,'unit_price'=>380];
$customSave = page([], postDocument($customDocument));
$customId = (int)db()->query('SELECT MAX(id) FROM quick_cost_sheets')->fetchColumn();
$customStored = quick_cost_find(db(), $customId);
check(in_array('Location: hizli_maliyet.php', $customSave['headers'], true) && (float)$customStored['document']['total'] === 679880.0, 'controller saves custom row once with exact total');
check($customStored['product'] === 'KİRAZ' && $customStored['notes'] === 'ÖZEL İŞ' && $customStored['document']['items'][10]['label'] === 'ÖZEL TAŞIMA', 'controller persists Turkish uppercase product notes and custom label');
$customDocument['items'][10]['unit_price'] = '-1';
$customBad = page(['id'=>(string)$customId], postDocument($customDocument, '1'));
check($customBad['status'] === 422 && str_contains($customBad['html'], 'custom_manual') && str_contains($customBad['html'], '-1') && str_contains($customBad['html'], 'özel taşıma'), 'invalid custom price and original label survive form recovery');
$customDocument['items'][10]['unit_price'] = 400;
page(['id'=>(string)$customId], postDocument($customDocument, '1'));
$customDocument['notes'] = 'ek kalem çakışma taslağı';
$customConflict = page(['id'=>(string)$customId], postDocument($customDocument, '1'));
check($customConflict['status'] === 409 && str_contains($customConflict['html'], 'custom_manual') && str_contains($customConflict['html'], 'EK KALEM ÇAKIŞMA TASLAĞI'), 'custom rows survive version-conflict draft recovery');
$customDocument['items'][10]['label'] = '';
check(page(['id'=>(string)$customId], postDocument($customDocument, '2'))['status'] === 422, 'blank custom label rejected by controller');

$listView = page([], [], 'list');
check(str_contains($listView['html'], 'hizli_maliyet_form.php?id=1') && str_contains($listView['html'], 'Düzenle') && str_contains($listView['html'], 'kalıcı olarak silinecek'), 'saved list has edit and record-specific permanent delete confirmation');
check(!str_contains($listView['html'], '>Fason<') && !str_contains($listView['html'], '>Üretim<'), 'saved list does not display hidden operation type');
$deletePost = ['action'=>'delete','id'=>'1','version'=>'2','csrf'=>'anonymous-csrf'];
check(page(['action'=>'delete'], [], 'list')['status'] === 200 && quick_cost_find(db(), 1) !== null, 'GET delete action never deletes');
check(page([], array_merge($deletePost, ['csrf'=>'bad']), 'list')['status'] === 403, 'delete requires valid CSRF');
$testPermissions = [];
check(page([], $deletePost, 'list')['status'] === 403, 'delete denies user without hizli_maliyet permission');
foreach ([['read'], ['read','write'], ['write','delete'], ['delete']] as $permissions) {
    $testPermissions = $permissions;
    check(page([], $deletePost, 'list')['status'] === 403, 'delete requires read and delete permissions');
}
$testPermissions = ['read','write','delete'];
// read+delete (yazma izni olmadan) silebilir; kayıt 1 korunur diye ayrı satır kullanılır.
$tmpRow = quick_cost_save(db(), $baseDocument);
$testPermissions = ['read','delete'];
$tmpDelete = page([], ['action'=>'delete','id'=>(string)$tmpRow['id'],'version'=>(string)$tmpRow['version'],'csrf'=>'anonymous-csrf'], 'list');
check(in_array('Location: hizli_maliyet.php', $tmpDelete['headers'], true) && quick_cost_find(db(), $tmpRow['id']) === null, 'read+delete (no write) can delete');
$testPermissions = ['read'];
$roList = page([], [], 'list');
check(!str_contains($roList['html'], 'Yeni maliyet hesapla') && !str_contains($roList['html'], 'kalıcı olarak silinecek') && !str_contains($roList['html'], '>Kopyala<'), 'read-only list hides new/copy/delete');
$testPermissions = ['read','write'];
$rwList = page([], [], 'list');
check(str_contains($rwList['html'], 'Yeni maliyet hesapla') && !str_contains($rwList['html'], 'kalıcı olarak silinecek'), 'read+write list has no delete');
$testPermissions = ['read','write','delete'];
$testDepot = 'OTHER';
check(page([], $deletePost, 'list')['status'] === 403, 'delete cannot cross active depot');
$testDepot = 'DEMO';
$staleDelete = page([], array_merge($deletePost, ['version'=>'1']), 'list');
check($staleDelete['status'] === 409 && str_contains($staleDelete['html'], 'hizli_maliyet_form.php?id=1') && quick_cost_find(db(), 1)['version'] === 2, 'stale delete preserves record and returns current list');
check(page([], array_merge($deletePost, ['version'=>'0']), 'list')['status'] === 422, 'delete rejects zero version');
check(page([], array_merge($deletePost, ['id'=>[]]), 'list')['status'] === 422, 'delete rejects array id');
check(page([], array_merge($deletePost, ['id'=>'999999']), 'list')['status'] === 403, 'delete missing target denied without leakage');

db()->exec("UPDATE quick_cost_sheets SET document_json='broken' WHERE id=2");
check(page(['id'=>'2'])['status'] === 503, 'corrupt JSON fails safely');
check(page([], [], 'list')['status'] === 200, 'list reads metadata without parsing corrupt saved JSON');
$deleteCorrupt = page([], ['action'=>'delete','id'=>'2','version'=>'1','csrf'=>'anonymous-csrf'], 'list');
check(in_array('Location: hizli_maliyet.php', $deleteCorrupt['headers'], true) && quick_cost_find(db(), 2) === null && quick_cost_find(db(), 1) !== null, 'delete removes only target corrupt quick record');
check(end($testAudit)[0] === 'delete' && end($testAudit)[1] === 'quick_cost_sheets', 'delete uses existing audit module');
db()->exec('DROP TABLE quick_cost_sheets');
$missing = page();
check($missing['status'] === 200 && str_contains($missing['html'], 'disabled'), 'missing schema form renders disabled save');
$testAdmin = true;
check(str_contains(page([], [], 'list')['html'], 'Yeni maliyet tablosunu kur'), 'admin sees explicit setup form on list when missing');
$testAdmin = false;
$nonAdminMissing = page([], [], 'list');
check($nonAdminMissing['status'] === 200 && !str_contains($nonAdminMissing['html'], 'Yeni maliyet tablosunu kur') && str_contains($nonAdminMissing['html'], 'yöneticisinden beklenir'), 'permitted non-admin sees "setup awaited from admin" message, no install button');
check(!str_contains(page()['html'], 'hizli_maliyet.php">kayıt listesine'), 'non-admin form missing-schema note has no setup link');
$testPermissions = [];
check(page([], [], 'list')['status'] === 403, 'no-permission user missing-schema page is forbidden');
$testPermissions = ['read','write','delete'];
$testAdmin = true;
check(page(['action'=>'install_schema'], [], 'list')['status'] === 200 && !quick_cost_storage_ready(db()), 'GET cannot create schema');
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
check(nav_aktif_anahtar() === 'qcost', 'quick list belongs to dedicated quick-cost navigation');
$_SERVER['PHP_SELF'] = '/hizli_maliyet_form.php';
check(nav_aktif_anahtar() === 'qcost', 'new form belongs to dedicated quick-cost navigation');
foreach (['maliyet.php','maliyet_form.php','maliyet_view.php'] as $oldPage) {
    $_SERVER['PHP_SELF'] = '/' . $oldPage;
    check(nav_aktif_anahtar() === 'rapor', 'legacy cost page navigation unchanged');
}
echo 'PASS ' . $checks . " controller checks (anonymous SQLite; no application bootstrap).\n";
