<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config/role_data_scope.php';
require_once dirname(__DIR__) . '/config/role_permission_matrix.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function db(): PDO { return $GLOBALS['pdo']; }
$uid = 20; $admin = false; $allowed = ['A'];
$perms = ['records.loading.read', 'records.loading.create'];
function current_user(): ?array { return ['id' => $GLOBALS['uid']]; }
function is_admin(): bool { return $GLOBALS['admin']; }
function active_depot(): ?string { return 'A'; }
function user_allowed_depots(?int $user_id = null): ?array { return $GLOBALS['allowed']; }
function can(string $p): bool { return in_array($p, $GLOBALS['perms'], true); }
function forbidden(string $message = ''): void { throw new RuntimeException('denied'); }
// Load only shared pure predicates/SQL builders. No application bootstrap.
$src = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/config/auth.php'));
foreach (['depo_fold','depo_in_allowed','depot_visible_to_user','depo_sql_records','depo_sql_records_in','depo_sql_in','depo_sql_column'] as $name) {
    $a = strpos($src, 'function ' . $name . '('); $b = strpos($src, "\n}\n", $a);
    if ($a === false || $b === false) throw new RuntimeException('Missing helper');
    eval(substr($src, $a, $b - $a + 3));
}
$checks = 0;
function check_scope(bool $result, string $name): void {
    $GLOBALS['checks']++;
    if (!$result) throw new RuntimeException($name);
}
function denies(callable $fn): bool { try { $fn(); } catch (RuntimeException $e) { return $e->getMessage() === 'denied'; } return false; }
$encode = static fn(array $names) => role_data_scope_permissions(['scope_mode'=>'selected','scope_depots'=>$names], $names);
$ab = $encode(['A','B']); $bc = $encode(['B','C']);
check_scope(role_data_scope_decode($ab) === ['mode'=>'selected','depots'=>['A','B']], 'Scope roundtrip');
check_scope(role_data_scope_union([$ab,$bc]) === ['B'], 'Role boundary intersection');
check_scope(role_data_scope_union([$ab,['dashboard.read']]) === ['A','B'], 'Unrelated legacy role removed boundary');
check_scope(role_data_scope_union([['records.read']]) === null, 'Legacy behavior changed');
check_scope(role_data_scope_union([$ab,$encode([])]) === [], 'Empty role scope widened');
check_scope(role_depot_scope_intersect(['B','C'], ['A','B']) === ['B'], 'User assignment boundary ignored');
check_scope(role_data_scope_permissions(['scope_mode'=>'selected','scope_depots'=>['INJECT',[]]], ['A']) === ['scope.depots.selected'], 'Unverified scope accepted');
check_scope(role_data_scope_permissions(['scope_mode'=>[]], ['A']) === ['scope.depots.selected'], 'Malformed scope removed restriction');
$long = str_repeat('İ', 150); $longCodes = $encode([$long]);
check_scope(role_data_scope_decode($longCodes)['depots'] === [$long], 'Long Turkish depot roundtrip');
foreach ($longCodes as $code) check_scope(strlen($code) <= 60, 'Permission storage width exceeded');
check_scope(role_data_scope_decode($encode(['AnonymousDepot670']))['depots'] === ['AnonymousDepot670'], 'Numeric hash prefix lost depot');
$broken = $longCodes; array_pop($broken);
check_scope(role_data_scope_decode($broken)['depots'] === [], 'Truncated scope did not fail closed');
$pdo->exec('CREATE TABLE user_roles(user_id INTEGER, role_id INTEGER); CREATE TABLE role_permissions(role_id INTEGER, permission TEXT); CREATE TABLE loading_records(id INTEGER, type TEXT); CREATE TABLE loading_pallets(loading_record_id INTEGER, depo TEXT); CREATE TABLE kantar_fisleri(id INTEGER, depo TEXT)');
$pdo->exec("INSERT INTO user_roles VALUES(20,1); INSERT INTO loading_records VALUES(1,'yukleme'),(2,'yukleme'),(3,'yukleme'),(4,'yukleme'),(5,'yukleme'),(6,'cikma'); INSERT INTO loading_pallets VALUES(1,'A'),(2,'B'),(3,'A'),(3,'B'),(4,''),(6,'A'); INSERT INTO kantar_fisleri VALUES(1,'A'),(2,'B'),(3,'')");
$insert = $pdo->prepare('INSERT INTO role_permissions VALUES(1,?)');
foreach ($encode(['A']) as $code) $insert->execute([$code]);
check_scope(role_current_depot_scope_restricted(), 'User explicit boundary absent');
foreach (['A','B',''] as $depot) check_scope(depot_visible_to_user($depot) === ($depot === 'A'), 'Single depot visibility');
foreach (['named','positional'] as $kind) {
    [$where,$params] = $kind === 'named' ? depo_sql_records('r') : depo_sql_records_in('r');
    $stmt=$pdo->prepare('SELECT r.id FROM loading_records r WHERE 1=1 ' . ($kind==='named' ? $where : ' AND '.$where) . ' ORDER BY r.id'); $stmt->execute($params);
    check_scope($stmt->fetchAll(PDO::FETCH_COLUMN) === [1,6], 'Record SQL leaked mixed/empty/foreign depot: '.$kind);
}
foreach (['named','positional'] as $kind) {
    [$where,$params] = $kind === 'named' ? depo_sql_column('depo') : depo_sql_in('depo');
    $stmt=$pdo->prepare('SELECT id FROM kantar_fisleri WHERE 1=1 ' . ($kind==='named' ? $where : ' AND '.$where)); $stmt->execute($params);
    check_scope($stmt->fetchAll(PDO::FETCH_COLUMN) === [1], 'Column SQL leaked foreign/blank depot');
}
role_require_record_access(1);
check_scope(denies(fn()=>role_require_record_access(2)), 'Direct foreign record allowed');
check_scope(denies(fn()=>role_require_record_access(3)), 'Mixed depot record allowed');
check_scope(denies(fn()=>role_require_record_access(4)), 'Unassigned record allowed');
check_scope(denies(fn()=>role_require_record_access(5)), 'Paletless record allowed');
check_scope(denies(fn()=>role_require_record_access(6)), 'Other page record allowed');
check_scope(denies(fn()=>role_require_record_access(1,'edit')), 'Create grant allowed edit');
check_scope(denies(fn()=>role_require_pallet_depots([['depo'=>'B']])), 'Submitted foreign depot allowed');
check_scope(denies(fn()=>role_require_pallet_depots([])), 'Empty submitted pallets allowed');
check_scope(denies(fn()=>role_require_kantar_scope(2)), 'Foreign scale record allowed');
// Exercise the real cost-link SQL function without its bootstrap/includes.
foreach (['kasa_adeti','brut_kg','net_kg'] as $column) $pdo->exec('ALTER TABLE loading_pallets ADD COLUMN '.$column.' REAL DEFAULT 1');
$pdo->exec('ALTER TABLE loading_pallets ADD COLUMN id INTEGER');
$pdo->exec('ALTER TABLE loading_records ADD COLUMN tarih TEXT');
$costSource = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__).'/config/cost_link.php'));
$a = strpos($costSource,'function cost_link_record_summary('); $b = strpos($costSource,"\n}\n",$a);
eval(substr($costSource,$a,$b-$a+3));
$perms=['records.loading.read'];
check_scope(cost_link_record_summary(1)['record_id']===1, 'Cost link lost allowed loading');
check_scope(cost_link_record_summary(2)===null && cost_link_record_summary(3)===null, 'Cost link disclosed foreign/mixed record');
$a = strpos($costSource,'function cost_link_stale_info('); $b = strpos($costSource,"\n}\n",$a);
eval(substr($costSource,$a,$b-$a+3));
check_scope(cost_link_stale_info(2,'2026-01-01')===null && cost_link_stale_info(3,'2026-01-01')===null, 'Cost timestamp disclosed foreign/mixed record');
$perms=['records.pallet.read'];
check_scope(cost_link_record_summary(1)===null, 'Pallet read exposed loading cost link');
$allowed=[];
check_scope(!depot_visible_to_user('A'), 'Empty intersection opened detail');
foreach ([depo_sql_records(),depo_sql_records_in(),depo_sql_column('depo'),depo_sql_in('depo')] as [$where,$params]) check_scope(str_contains($where,'1=0') && $params===[], 'Empty intersection opened SQL');
echo "Role data scope: $checks checks passed.\n";
