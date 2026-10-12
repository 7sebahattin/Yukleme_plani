<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/config/beyan_permissions.php';
// Load only the real guard, never helpers' migration bootstrap.
$source = file_get_contents(dirname(__DIR__) . '/config/helpers.php');
preg_match('/function can_beyan\(string \$perm\): bool \{.*?\n\}/s', $source, $match);
eval($match[0]);
$permissions = [];
$admin = false;
function can(string $p): bool { return in_array($p, $GLOBALS['permissions'], true); }
function is_admin(): bool { return $GLOBALS['admin']; }
if (($argv[1] ?? '') === '--endpoint') {
    $permissions = ['beyan.read','beyan.write','beyan.permissions.detailed','beyan.edit','records.write'];
    if (($argv[2] ?? '') === 'beyan_edit.php' && ($argv[3] ?? '') === 'edit') $permissions = ['beyan.read','beyan.permissions.detailed'];
    function require_login(): array { return ['id'=>1]; }
    function current_user(): array { return ['id'=>1]; }
    function forbidden(...$args): void { http_response_code(403); exit; }
    function csrf_check(...$args): void {}
    function db() { throw new RuntimeException('Denied request reached database'); }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['status_only'=>($argv[3] ?? '') === 'status' ? '1' : ''];
    $_GET = ['id'=>1,'action'=>'taslak_olustur'];
    register_shutdown_function(static function(): void { echo '|STATUS=' . http_response_code(); });
    $endpoint = file_get_contents(dirname(__DIR__) . '/' . basename($argv[2]));
    $endpoint = preg_replace("~^require_once __DIR__ \\. '[^']+';[^\\r\\n]*~m", '', $endpoint);
    eval(substr($endpoint, 5));
    exit;
}
$tests = 0;
function check_permission(string $name, bool $ok): void {
    global $tests;
    $tests++;
    if (!$ok) throw new RuntimeException($name);
}
$permissions = beyan_expand_role_permissions(['beyan.read','beyan.write']);
foreach (['read','create','edit','status','match','notify','contact.read','contact.write','analysis.read','analysis.write','hks.read','hks.write','source.read','source.write'] as $p) check_permission('legacy ' . $p, can_beyan($p));
check_permission('legacy never grants delete', !can_beyan('delete'));
$permissions = ['beyan.read','beyan.write','beyan.permissions.detailed','beyan.edit'];
foreach (['create','status','match','notify','analysis.read','analysis.write'] as $p) check_permission('detailed denies ' . $p, !can_beyan($p));
check_permission('detailed explicit edit', can_beyan('edit'));
$permissions[] = 'beyan.analysis.write';
check_permission('write requires section read', !can_beyan('analysis.write'));
$permissions[] = 'beyan.analysis.read';
check_permission('section write with read', can_beyan('analysis.write'));
$permissions = ['beyan.edit','beyan.analysis.read','beyan.analysis.write','beyan.permissions.detailed'];
check_permission('page read required', !can_beyan('edit') && !can_beyan('analysis.read'));
$permissions = ['beyan.read','beyan.permissions.detailed','beyan.edit'];
$existing = ['analysis_note'=>'protected','analysis_result_at'=>'2026-10-01 12:34:56','company_address'=>'Original case','raw_text'=>'secret','hks_firma_id'=>'42','status'=>'temiz'];
$attack = array_fill_keys(array_keys($existing), 'injected');
$protected = beyan_protect_input($attack, $existing);
foreach ($existing as $key => $value) check_permission('preserve ' . $key, $protected[$key] === $value);
$redacted = beyan_redact_fields($existing);
foreach (['analysis_note','analysis_result_at','company_address','raw_text','hks_firma_id'] as $key) check_permission('redact ' . $key, $redacted[$key] === null);
$new = beyan_protect_input($attack);
check_permission('creation default status', $new['status'] === 'beyan_acildi');
check_permission('creation cannot inject HKS', $new['hks_firma_id'] === '');
$admin = true;
check_permission('admin recovery', can_beyan('delete') && can_beyan('hks.write'));
foreach ([['beyan_create.php','create'], ['beyan_bulk_save.php','create'], ['beyan_kopyala.php','create'], ['beyan_edit.php','edit'], ['beyan_edit.php','status'], ['beyan_eslestir.php','match'], ['api_beyan_bildirim.php','notify']] as [$endpoint, $action]) {
    $proc = proc_open([PHP_BINARY, __FILE__, '--endpoint', $endpoint, $action], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) throw new RuntimeException('Unable to launch endpoint test');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    check_permission('direct POST denied before database: ' . $endpoint . '/' . $action . ' ' . $out . $err, $code === 0 && str_contains($out, '|STATUS=403') && $err === '');
}
echo "Beyan permissions: $tests checks passed.\n";
