<?php
// Real authentication permission query against isolated SQLite; no migrations/network.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$testDb = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function db(): PDO { global $testDb; return $testDb; }
require_once dirname(__DIR__) . '/config/auth.php';
$testDb->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER)');
$testDb->exec('CREATE TABLE role_permissions (role_id INTEGER, permission TEXT)');
$insert = $testDb->prepare('INSERT INTO role_permissions VALUES (?, ?)');
$roleSets = [
    1 => ['beyan.read', 'beyan.write'],
    2 => ['beyan.permissions.detailed', 'beyan.read', 'beyan.edit', 'beyan.contact.read'],
    3 => ['beyan.permissions.detailed', 'beyan.read', 'beyan.notify', 'beyan.hks.read'],
    4 => ['beyan.read'],
    5 => ['records.read', 'users.admin'],
    6 => ['beyan.permissions.detailed', 'beyan.read', 'beyan.write'],
];
foreach ($roleSets as $id => $permissions) foreach ($permissions as $p) $insert->execute([$id, $p]);
$testDb->exec('INSERT INTO user_roles VALUES (10,1),(11,2),(12,2),(12,3),(13,1),(13,2),(14,4),(15,5),(16,6)');
$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$legacy = user_permissions(10);
foreach (['create','edit','status','match','notify','contact.read','contact.write','analysis.read','analysis.write','source.read','source.write','hks.read','hks.write'] as $p) {
    verify(in_array('beyan.' . $p, $legacy, true), 'Legacy missing ' . $p);
}
$limited = user_permissions(11);
verify(in_array('beyan.edit', $limited, true), 'Explicit action absent');
foreach (['create','status','match','notify','analysis.read','contact.write'] as $p) verify(!in_array('beyan.' . $p, $limited, true), 'Unexpected grant ' . $p);
$combined = user_permissions(12);
verify(in_array('beyan.edit', $combined, true) && in_array('beyan.notify', $combined, true), 'Detailed roles must union');
verify(!in_array('beyan.analysis.read', $combined, true), 'Union added unassigned section');
$mixed = user_permissions(13);
verify(in_array('beyan.analysis.write', $mixed, true), 'One detailed marker must not narrow another legacy role');
verify(count($mixed) === count(array_unique($mixed)), 'Union should deduplicate');
$viewer = user_permissions(14);
verify(in_array('beyan.analysis.read', $viewer, true), 'Legacy read lost section access');
verify(!in_array('beyan.edit', $viewer, true), 'Viewer gained edit');
$recordViewer = user_permissions(15);
verify(in_array('users.admin', $recordViewer, true) && in_array('records.loading.read', $recordViewer, true) && in_array('records.pallet.read', $recordViewer, true), 'Legacy record reader expansion lost grants');
verify(!in_array('records.loading.create', $recordViewer, true) && !in_array('records.pallet.edit', $recordViewer, true), 'Legacy record reader gained writes');
verify(user_permissions(999) === [], 'Unassigned user gained permission');
$detailedLegacy = user_permissions(16);
verify(!in_array('beyan.notify', $detailedLegacy, true), 'Detailed role legacy write must not expand');
verify(!in_array('beyan.hks.read', $detailedLegacy, true), 'Detailed role read must not expand');
verify(user_permissions(10) === $legacy, 'Per-user cache isolation failed');
echo "Authentication permissions: $checks checks passed.\n";
