<?php
// CLI, tamamen sürücü doubles. Gerçek bağlantı/DDL/uygulama bootstrap yoktur.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnız CLI.'); }
require_once __DIR__ . '/../config/quick_cost_schema.php';

$QCS_USER = ['id' => 7];
$QCS_ADMIN = true;
$QCS_PERMS = ['read' => true, 'write' => true];
$QCS_DEPOT = 'ANONİM TEST DEPO';
$QCS_AUDIT = [];
function current_user(): ?array { return $GLOBALS['QCS_USER']; }
function is_admin(): bool { return $GLOBALS['QCS_ADMIN']; }
// Gerçek kapıyla aynı kural: yönetici her zaman geçer; yoksa izin satırı gerekir.
function can_hizli_maliyet(string $perm): bool { return $GLOBALS['QCS_ADMIN'] || ($GLOBALS['QCS_PERMS'][$perm] ?? false); }
function active_depot(): ?string { return $GLOBALS['QCS_DEPOT']; }
function depot_visible_to_user(?string $depot): bool { return $depot === $GLOBALS['QCS_DEPOT']; }
function audit_log_event(string $action, string $module, ?int $id = null, ?array $old = null, ?array $new = null): void {
    $GLOBALS['QCS_AUDIT'][] = compact('action', 'module', 'id', 'old', 'new');
}

class QuickCostSchemaStatement extends PDOStatement { public function __construct() {} }
class QuickCostSchemaSpy extends PDO
{
    public bool $ready = false;
    public bool $transaction = false;
    public bool $failCreate = false;
    public bool $incompleteCreate = false;
    public bool $probeError = false;
    public array $calls = [];
    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'sqlite' : null; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->calls[] = ['query', $query];
        if ($this->probeError) throw new PDOException('anonymous permission denied');
        if (!$this->ready) throw new PDOException('no such table: quick_cost_sheets');
        return new QuickCostSchemaStatement();
    }
    public function exec(string $statement): int|false {
        $this->calls[] = ['exec', $statement];
        if ($this->failCreate) throw new PDOException('anonymous CREATE permission denied');
        $this->ready = !$this->incompleteCreate;
        return 0;
    }
}

$qcsPassed = 0; $qcsFailed = 0;
function qcs_assert(bool $condition, string $message = 'Beklenti sağlanmadı.'): void {
    if (!$condition) throw new RuntimeException($message);
}
function qcs_throws(callable $action, string $class): void {
    try { $action(); } catch (Throwable $e) {
        qcs_assert($e instanceof $class, 'Yanlış exception: ' . get_class($e)); return;
    }
    throw new RuntimeException('Exception bekleniyordu.');
}
function qcs_test(string $name, callable $test): void {
    global $qcsPassed, $qcsFailed;
    $GLOBALS['QCS_AUDIT'] = [];
    try { $test(); $qcsPassed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $qcsFailed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}

qcs_test('Yalnız eksik yeni tabloya tek kabul edilmiş CREATE ve metadata probe', function (): void {
    $pdo = new QuickCostSchemaSpy();
    qcs_assert(quick_cost_install($pdo) === ['created' => true]);
    qcs_assert(count($pdo->calls) === 3);
    qcs_assert(array_column($pdo->calls, 0) === ['query', 'exec', 'query']);
    qcs_assert($pdo->calls[0][1] === 'SELECT id, version FROM quick_cost_sheets WHERE 1=0');
    qcs_assert($pdo->calls[2][1] === $pdo->calls[0][1]);
    $sql = $pdo->calls[1][1];
    qcs_assert(str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS `quick_cost_sheets`'));
    qcs_assert(substr_count($sql, ';') === 1 && str_ends_with($sql, ';'));
    qcs_assert(!preg_match('/\b(?:ALTER|INSERT|UPDATE|DELETE|DROP|TRUNCATE|SELECT)\b/i', $sql));
    qcs_assert(!str_contains($sql, '`cost_sheets`') && !str_contains($sql, 'loading_'));
    qcs_assert($GLOBALS['QCS_AUDIT'] === [['action' => 'schema_create', 'module' => 'quick_cost_sheets', 'id' => null,
        'old' => null, 'new' => ['table' => 'quick_cost_sheets']]]);
});
qcs_test('Zaten hazırsa tekrar CREATE/audit yok', function (): void {
    $pdo = new QuickCostSchemaSpy(); $pdo->ready = true;
    qcs_assert(quick_cost_install($pdo) === ['created' => false]);
    qcs_assert(count($pdo->calls) === 1 && $pdo->calls[0][0] === 'query');
    qcs_assert($GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('İkinci açık kurulum çağrısı idempotent', function (): void {
    $pdo = new QuickCostSchemaSpy();
    qcs_assert(quick_cost_install($pdo) === ['created' => true]);
    qcs_assert(quick_cost_install($pdo) === ['created' => false]);
    qcs_assert(count(array_filter($pdo->calls, static fn($call) => $call[0] === 'exec')) === 1);
    qcs_assert(count($GLOBALS['QCS_AUDIT']) === 1);
});
qcs_test('Yönetici olmayan kullanıcı hiçbir PDO işlemine ulaşamaz', function (): void {
    $GLOBALS['QCS_ADMIN'] = false; $pdo = new QuickCostSchemaSpy();
    try { qcs_throws(fn() => quick_cost_install($pdo), QuickCostAccessException::class); }
    finally { $GLOBALS['QCS_ADMIN'] = true; }
    qcs_assert($pdo->calls === [] && $GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('Oturumsuz/sıfır id kullanıcısı hiçbir PDO işlemine ulaşamaz', function (): void {
    foreach ([null, ['id' => 0]] as $user) {
        $GLOBALS['QCS_USER'] = $user; $pdo = new QuickCostSchemaSpy();
        try { qcs_throws(fn() => quick_cost_install($pdo), QuickCostAccessException::class); }
        finally { $GLOBALS['QCS_USER'] = ['id' => 7]; }
        qcs_assert($pdo->calls === [] && $GLOBALS['QCS_AUDIT'] === []);
    }
});
qcs_test('Hızlı Maliyet izinleri olsa da yönetici olmayan kullanıcı tabloyu kuramaz', function (): void {
    $GLOBALS['QCS_ADMIN'] = false; $GLOBALS['QCS_PERMS'] = ['read' => true, 'write' => true, 'delete' => true];
    $pdo = new QuickCostSchemaSpy();
    try { qcs_throws(fn() => quick_cost_install($pdo), QuickCostAccessException::class); }
    finally { $GLOBALS['QCS_ADMIN'] = true; }
    qcs_assert($pdo->calls === [] && $GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('Aktif deposuz yönetici PDO işlemine ulaşamaz', function (): void {
    $GLOBALS['QCS_DEPOT'] = null; $pdo = new QuickCostSchemaSpy();
    try { qcs_throws(fn() => quick_cost_install($pdo), QuickCostAccessException::class); }
    finally { $GLOBALS['QCS_DEPOT'] = 'ANONİM TEST DEPO'; }
    qcs_assert($pdo->calls === [] && $GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('Açık işlem örtük commit riski nedeniyle CREATE öncesi reddedilir', function (): void {
    $pdo = new QuickCostSchemaSpy(); $pdo->transaction = true;
    qcs_throws(fn() => quick_cost_install($pdo), LogicException::class);
    qcs_assert($pdo->calls === [] && $GLOBALS['QCS_AUDIT'] === [] && $pdo->transaction);
});
qcs_test('CREATE hatası başarı/audit gibi sunulmaz', function (): void {
    $pdo = new QuickCostSchemaSpy(); $pdo->failCreate = true;
    qcs_throws(fn() => quick_cost_install($pdo), PDOException::class);
    qcs_assert(count($pdo->calls) === 2 && $GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('Kurulum sonrası hazır olmayan tabloda başarı/audit yok', function (): void {
    $pdo = new QuickCostSchemaSpy(); $pdo->incompleteCreate = true;
    qcs_throws(fn() => quick_cost_install($pdo), RuntimeException::class);
    qcs_assert(count($pdo->calls) === 3 && $GLOBALS['QCS_AUDIT'] === []);
});
qcs_test('Eksik tablo dışındaki probe hatasında CREATE yok', function (): void {
    $pdo = new QuickCostSchemaSpy(); $pdo->probeError = true;
    qcs_throws(fn() => quick_cost_install($pdo), PDOException::class);
    qcs_assert(count($pdo->calls) === 1 && $GLOBALS['QCS_AUDIT'] === []);
});

echo "\nŞema kurulumu: $qcsPassed geçti, $qcsFailed başarısız. Yalnız PDO sürücü doubles.\n";
exit($qcsFailed === 0 ? 0 : 1);
