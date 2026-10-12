<?php
// CLI, bellek içi/geçici SQLite ve izole test oturumu. Gerçek DB/HKS yoktur.
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/beyan_permissions.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// helpers.php üst düzeyde auto-migration içerir; include etmek yerine yalnız
// test edilen gerçek fonksiyonları tokenizer ile yükle. Migration ASLA çalışmaz.
$wanted = array_flip(['beyan_suggest_fields', 'beyan_suggest_normalize', 'tr_upper', 'beyan_statuses', 'beyan_num_or_null', 'beyan_int_or_null', 'beyan_edit_kg', 'beyan_veri_dogrula', 'beyan_edit_token', 'beyan_duzenleme_kilitle', 'beyan_durum_kaydet', 'beyan_insert', 'beyan_hks_secimleri_coz', 'beyan_plaka_normalize']);
$tokens = token_get_all((string)file_get_contents(dirname(__DIR__) . '/config/helpers.php'));
for ($i = 0, $n = count($tokens); $i < $n; $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
    if (!isset($tokens[$j]) || !is_array($tokens[$j]) || !isset($wanted[$tokens[$j][1]])) continue;
    $code = '';
    $depth = 0;
    $started = false;
    for (; $i < $n; $i++) {
        $token = $tokens[$i];
        $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{') { $depth++; $started = true; }
        if ($token === '}' && --$depth === 0 && $started) break;
    }
    eval($code);
}
// Alt süreç gerçek endpoint kontrol akışını yalnız require/input sınırları
// mock edilerek çalıştırır. Kalıcı SQLite dosyası test sonunda kaldırılır.
if (($argv[1] ?? '') === '--bulk-worker') {
    ini_set('session.save_path', $argv[3]);
    session_id('beyandatatest');
    session_start();
    $pdo_test = new PDO('sqlite:' . $argv[2], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo_test->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
    function require_login(): array { return ['id' => 1]; }
    function can_beyan(string $perm): bool { return true; }
    function csrf_check($token): void { if ($token !== 'test-csrf') throw new RuntimeException('Bad CSRF'); }
    function audit_log_event(...$args): void {}
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $source = (string)file_get_contents(dirname(__DIR__) . '/beyan_bulk_save.php');
    $source = preg_replace("~^require_once __DIR__ \\. '/config/(?:db|auth)\\.php';\\R~m", '', $source);
    $source = str_replace("'php://input'", "'php://stdin'", $source);
    eval(substr($source, 5));
    exit;
}
$fails = 0;
$tests = 0;
function check_data(string $label, bool $ok): void {
    global $fails, $tests;
    $tests++;
    if (!$ok) { $fails++; echo "FAIL: $label\n"; }
}
function throws_data(callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
foreach ([123.45, '123,450', '123.45', '123,45'] as $number) {
    check_data('decimal ' . json_encode($number), beyan_num_or_null($number) === 123.45);
}
check_data('Turkish thousands', beyan_num_or_null('24.300,125') === 24300.125);
check_data('Turkish integer thousands', beyan_num_or_null('24.300') === 24300.0);
check_data('parser decimal triple remains decimal', beyan_num_or_null(123.450) === 123.45);
check_data('edit roundtrip preserves 3 decimals', beyan_num_or_null(beyan_edit_kg(123.451)) === 123.451);
check_data('zero preserved in editing', beyan_num_or_null(beyan_edit_kg(0)) === 0.0);
check_data('null preserved in editing', beyan_num_or_null(beyan_edit_kg(null)) === null);
foreach ([-1, '-1', 'abc', '12kg', '1,0000', 1.2345, INF, '1.2.3', '1,2,3', 1000000000] as $number) {
    check_data('invalid number ' . json_encode($number), throws_data(fn() => beyan_num_or_null($number)));
}
check_data('fractional count string rejected', throws_data(fn() => beyan_int_or_null('2,5')));
check_data('fractional count JSON rejected', throws_data(fn() => beyan_int_or_null(2.5)));
check_data('integer count accepted', beyan_int_or_null('24') === 24);
$base = ['product_name' => 'ÜRÜN', 'status' => 'beyan_acildi'];
check_data('net > gross rejected', count(beyan_veri_dogrula($base + ['gross_kg' => 12, 'net_kg' => 13])['errors']) === 1);
check_data('invalid calendar rejected', count(beyan_veri_dogrula($base + ['sample_taken_at' => '2026-02-30T12:00'])['errors']) === 1);
check_data('invalid time rejected', count(beyan_veri_dogrula($base + ['sample_taken_at' => '2026-02-28T25:00'])['errors']) === 1);
check_data('reverse dates rejected', count(beyan_veri_dogrula($base + ['sample_taken_at' => '2026-02-28T12:00', 'analysis_result_at' => '2026-02-27T12:00'])['errors']) === 1);
check_data('valid datetime normalized', beyan_veri_dogrula($base + ['sample_taken_at' => '2026-02-28T12:00'])['values']['sample_taken_at'] === '2026-02-28 12:00:00');
check_data('red note required', beyan_veri_dogrula(['product_name' => 'ÜRÜN', 'status' => 'red'])['errors'] !== []);
check_data('blank declaration rejected', beyan_veri_dogrula([])['errors'] !== []);
check_data('invalid status rejected', beyan_veri_dogrula(['product_name' => 'ÜRÜN', 'status' => 'unknown'])['errors'] !== []);
$old = ['hks_firma_id' => '1', 'hks_urun_id' => '2', 'hks_urun_ad' => 'ÜRÜN', 'hks_ulke_id' => '3', 'hks_ulke_ad' => 'ÜLKE'];
$hks = beyan_hks_secimleri_coz([], $old, []);
check_data('catalog outage omissions preserve HKS', $hks === ['firma_id' => '1', 'urun_id' => '2', 'urun_ad' => 'ÜRÜN', 'ulke_id' => '3', 'ulke_ad' => 'ÜLKE']);
check_data('unchanged HKS accepted during outage', beyan_hks_secimleri_coz(['hks_urun_id' => '2'], $old, []) === $hks);
check_data('explicit HKS clear distinguished', beyan_hks_secimleri_coz(['hks_urun_id' => ''], $old, [])['urun_id'] === null);
check_data('new HKS selection during outage errors', throws_data(fn() => beyan_hks_secimleri_coz(['hks_urun_id' => '4'], $old, [])));
check_data('new HKS selection resolves label', beyan_hks_secimleri_coz(['hks_urun_id' => '4'], $old, ['urunler' => [['id' => '4', 'ad' => 'YENİ']]])['urun_ad'] === 'YENİ');

$pdo_test = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo_test->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
function db(): PDO { global $pdo_test; return $pdo_test; }
$pdo_test->exec('CREATE TABLE customs_declarations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, raw_text TEXT, unmatched_text TEXT, declaration_title TEXT,
    company_name TEXT, company_address TEXT, transport_type TEXT, vehicle_plate TEXT, line_type TEXT,
    party_no TEXT, pallet_count INTEGER, product_name TEXT, product_variety TEXT, gross_kg NUMERIC,
    net_kg NUMERIC, crate_count INTEGER, crate_type TEXT, exit_depot TEXT, contact_person TEXT,
    buyer_name TEXT, brand TEXT, status TEXT, analysis_note TEXT, sample_taken_at TEXT,
    analysis_result_at TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT,
    updated_at TEXT, deleted_at TEXT)');
$id = beyan_insert($base + ['gross_kg' => 123.45, 'net_kg' => '123,450', 'pallet_count' => 2, 'vehicle_plate' => '34 abc 123', 'sample_taken_at' => '2026-02-28T12:00'], 1);
function row_data(int $id): array { return db()->query('SELECT * FROM customs_declarations WHERE id = ' . $id)->fetch(); }
$row = row_data($id);
check_data('bulk insert decimal roundtrip', (float)$row['gross_kg'] === 123.45 && (float)$row['net_kg'] === 123.45);
check_data('bulk insert plate preserved normalized', $row['vehicle_plate'] === '34ABC123');
check_data('bulk dates saved', $row['sample_taken_at'] === '2026-02-28 12:00:00');
$token = beyan_edit_token($row);
// Başka kullanıcı plaka değiştirir, updated_at aynı saniye kalır.
$pdo_test->exec("UPDATE customs_declarations SET vehicle_plate = '06ABC456' WHERE id = $id");
check_data('same-second concurrent edit rejected', throws_data(fn() => beyan_duzenleme_kilitle(db(), $id, $token)));
check_data('conflict transaction closed', !db()->inTransaction());
$count_before = (int)$pdo_test->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn();
check_data('invalid bulk insert rejected', throws_data(fn() => beyan_insert($base + ['pallet_count' => 1.5], 1)));
check_data('invalid insert writes nothing', (int)$pdo_test->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === $count_before);
$before = row_data($id);
beyan_durum_kaydet(db(), $id, 'temiz', ['vehicle_plate' => 'STALE', 'gross_kg' => 0, 'analysis_note' => 'Kontrol tamam'], 2);
$after = row_data($id);
check_data('status preserves concurrent plate', $after['vehicle_plate'] === '06ABC456');
check_data('status preserves decimal KG', $after['gross_kg'] === $before['gross_kg'] && $after['net_kg'] === $before['net_kg']);
check_data('status only mutates allowed fields', array_diff_assoc(array_diff_key($after, array_flip(['status', 'analysis_note', 'analysis_result_at', 'updated_by', 'updated_at'])), array_diff_key($before, array_flip(['status', 'analysis_note', 'analysis_result_at', 'updated_by', 'updated_at']))) === []);
check_data('clean sets result timestamp', $after['analysis_result_at'] !== null && $after['updated_by'] === 2);
check_data('red requires meaningful note', throws_data(fn() => beyan_durum_kaydet(db(), $id, 'red', ['analysis_note' => ' '], 2)));
check_data('rejected status leaves row unchanged', row_data($id) === $after);
check_data('status invalid date rejected', throws_data(fn() => beyan_durum_kaydet(db(), $id, 'red', ['analysis_note' => 'Red', 'analysis_result_at' => '2026-02-30T12:00'], 2)));
foreach (array_keys(beyan_statuses()) as $status) {
    beyan_durum_kaydet(db(), $id, $status, ['analysis_note' => 'Durum açıklaması'], 2);
    check_data('free status transition ' . $status, row_data($id)['status'] === $status);
}
$current = row_data($id);
$locked = beyan_duzenleme_kilitle(db(), $id, beyan_edit_token($current));
check_data('fresh snapshot accepted while locked', $locked === $current && db()->inTransaction());
db()->rollBack();
$pdo_test->exec("UPDATE customs_declarations SET deleted_at = CURRENT_TIMESTAMP WHERE id = $id");
check_data('deleted row status blocked', throws_data(fn() => beyan_durum_kaydet(db(), $id, 'temiz', [], 2)));
check_data('deleted row edit blocked', throws_data(fn() => beyan_duzenleme_kilitle(db(), $id, beyan_edit_token($current))));

// Gerçek endpoint: başarılı retry, payload çakışması, hatalı satır ve rollback.
$schema = (string)$pdo_test->query("SELECT sql FROM sqlite_master WHERE name = 'customs_declarations'")->fetchColumn();
$test_dir = sys_get_temp_dir() . '/beyan-data-' . bin2hex(random_bytes(6));
mkdir($test_dir, 0700);
$test_db = $test_dir . '/bulk.sqlite';
$bulk_db = new PDO('sqlite:' . $test_db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bulk_db->exec($schema);
function bulk_request_data(array $body, string $db_file, string $session_dir): array {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, '--bulk-worker', $db_file, $session_dir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    $decoded = json_decode($out, true);
    if (!is_array($decoded) || $code !== 0) throw new RuntimeException('Bulk test harness: ' . $out . $err);
    return $decoded;
}
try {
    $request = ['csrf' => 'test-csrf', 'request_id' => 'bulk_test_request_0001', 'declarations' => [$base + ['net_kg' => '123,450']]];
    $first = bulk_request_data($request, $test_db, $test_dir);
    $again = bulk_request_data($request, $test_db, $test_dir);
    check_data('bulk successful retry returns identical result', $first['ok'] === true && $first === $again);
    check_data('bulk successful retry inserts only once', (int)$bulk_db->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === 1);
    $different = $request;
    $different['declarations'][0]['net_kg'] = '124,450';
    check_data('bulk request id payload conflict blocked', bulk_request_data($different, $test_db, $test_dir)['ok'] === false);
    $invalid = ['csrf' => 'test-csrf', 'request_id' => 'bulk_test_request_0002', 'declarations' => [$base, $base + ['crate_count' => '1,5']]];
    $result = bulk_request_data($invalid, $test_db, $test_dir);
    check_data('bulk returns selected row validation error', $result['ok'] === false && $result['rows'][0]['row'] === 2);
    check_data('bulk prevalidation inserts no partial records', (int)$bulk_db->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === 1);
    $bulk_db->exec("CREATE TRIGGER reject_test BEFORE INSERT ON customs_declarations WHEN NEW.product_name = 'FAIL' BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    $rollback = ['csrf' => 'test-csrf', 'request_id' => 'bulk_test_request_0003', 'declarations' => [$base, ['product_name' => 'FAIL']]];
    check_data('bulk database failure rejects transaction', bulk_request_data($rollback, $test_db, $test_dir)['ok'] === false);
    check_data('bulk database failure rolls back preceding insert', (int)$bulk_db->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === 1);
    $bulk_db->exec('DROP TRIGGER reject_test');
    check_data('bulk failed request can retry after fix', bulk_request_data($rollback, $test_db, $test_dir)['created'] === 2);
    check_data('bulk retry after failure inserts exactly expected rows', (int)$bulk_db->query('SELECT COUNT(*) FROM customs_declarations')->fetchColumn() === 3);
} finally {
    $bulk_db = null;
    foreach (glob($test_dir . '/*') as $test_file) unlink($test_file);
    rmdir($test_dir);
}
echo "Beyan data: $tests kontrol, $fails hata.\n";
exit($fails ? 1 : 0);
