<?php
// CLI only. Saf model + anonim bellek içi SQLite; uygulama bootstrap/gerçek DB yok.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnız CLI.'); }
require_once __DIR__ . '/../config/quick_cost_store.php';

$QC_USER = ['id' => 7];
$QC_ADMIN = true;
$QC_PERMS = ['read' => true, 'write' => true];
$QC_DEPOT = 'TEST DEPO A';
$QC_DEPOT_CALLS = 0;
$QC_UNRESTRICTED_SCOPE = false;
$QC_DB = new PDO('sqlite::memory:');
$QC_DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$QC_DB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function current_user(): ?array { return $GLOBALS['QC_USER']; }
function is_admin(): bool { return $GLOBALS['QC_ADMIN']; }
function can_maliyet(string $perm): bool { return $GLOBALS['QC_PERMS'][$perm] ?? false; }
function active_depot(): ?string { $GLOBALS['QC_DEPOT_CALLS']++; return $GLOBALS['QC_DEPOT']; }
function depot_visible_to_user(?string $depot): bool { return $depot === $GLOBALS['QC_DEPOT']; }
function depo_sql_in(string $column): array {
    return $GLOBALS['QC_UNRESTRICTED_SCOPE'] ? ['', []] : [$column . ' IN (?)', [$GLOBALS['QC_DEPOT']]];
}
function db(): PDO { return $GLOBALS['QC_DB']; }
function audit_log_event(string $action, string $module, ?int $id = null, ?array $old = null, ?array $new = null): void {
    db()->prepare('INSERT INTO audit_log(action,module,record_id,old_values,new_values) VALUES(?,?,?,?,?)')
        ->execute([$action, $module, $id, json_encode($old), json_encode($new)]);
}

$QC_DB->exec('CREATE TABLE quick_cost_sheets (
    id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT NOT NULL, sheet_date TEXT NOT NULL,
    operation_type TEXT NOT NULL, method TEXT NOT NULL, net_kg NUMERIC NOT NULL,
    document_json TEXT NOT NULL, total NUMERIC NOT NULL, unit_cost NUMERIC NOT NULL,
    notes TEXT NOT NULL, depo TEXT NOT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL)');
$QC_DB->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, action TEXT, module TEXT, record_id INTEGER, old_values TEXT, new_values TEXT)');
$QC_DB->exec("CREATE TABLE cost_sheets (id INTEGER PRIMARY KEY, payload TEXT);
    INSERT INTO cost_sheets VALUES(1,'UNCHANGED LEGACY COST');
    CREATE TABLE loading_records (id INTEGER PRIMARY KEY, payload TEXT);
    INSERT INTO loading_records VALUES(1,'UNCHANGED LEGACY LOADING')");
$legacyBefore = json_encode([$QC_DB->query('SELECT * FROM cost_sheets')->fetchAll(), $QC_DB->query('SELECT * FROM loading_records')->fetchAll()]);
$passed = 0;
$failed = 0;
function qc_assert(bool $condition, string $message = ''): void {
    if (!$condition) throw new RuntimeException($message ?: 'Beklenti sağlanmadı.');
}
function qc_equal($actual, $expected): void {
    qc_assert($actual == $expected, 'Beklenen ' . json_encode($expected) . ', alınan ' . json_encode($actual));
}
function qc_throws(callable $action, string $class = InvalidArgumentException::class): void {
    try { $action(); } catch (Throwable $e) {
        qc_assert($e instanceof $class, 'Yanlış exception: ' . get_class($e));
        return;
    }
    throw new RuntimeException('Exception bekleniyordu: ' . $class);
}
function qc_test(string $name, callable $test): void {
    global $passed, $failed;
    try { $test(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
function qc_example(): array {
    $document = quick_cost_defaults('2026-10-30');
    $document['product'] = 'ANONİM TEST ÜRÜNÜ';
    $document['net_kg'] = '20.000';
    $prices = ['product' => 25, 'material' => 4, 'transport' => '1,60', 'customs' => 4500,
        'labor' => 2, 'official_analysis' => 1500, 'private_analysis' => 2000, 'overhead' => '0,50'];
    foreach ($document['items'] as &$item) $item['unit_price'] = $prices[$item['code']] ?? 0;
    unset($item);
    return $document;
}
function qc_item(array $document, string $code): array {
    foreach ($document['items'] as $item) if ($item['code'] === $code) return $item;
    throw new RuntimeException('Kalem bulunamadı.');
}

qc_test('20.000 kg / 670.000 TL / 33,50 TL/kg örneği ve gruplar', function (): void {
    $result = quick_cost_calculate(qc_example());
    qc_equal($result['total'], 670000);
    qc_equal($result['unit_cost'], 33.5);
    qc_equal($result['groups'], ['A' => 500000, 'B' => 80000, 'D' => 90000]);
    foreach (['product' => 500000, 'material' => 80000, 'transport' => 32000, 'labor' => 40000, 'overhead' => 10000] as $code => $amount) {
        qc_equal(qc_item($result, $code)['amount'], $amount);
    }
});
qc_test('Net kg değişimi bağlı satırları günceller, sabitleri korur', function (): void {
    $document = quick_cost_calculate(qc_example());
    $document['net_kg'] = 40000;
    $result = quick_cost_calculate($document);
    qc_equal($result['total'], 1332000);
    qc_equal(qc_item($result, 'transport')['amount'], 64000);
    qc_equal(qc_item($result, 'customs')['amount'], 4500);
});
qc_test('Detaylı palet 26 × 380 = 9880 ve manuel miktar korunur', function (): void {
    $document = qc_example();
    $document['method'] = 'detailed';
    $document['items'][2] = ['code' => 'transport', 'unit' => 'pallet', 'qty' => 26, 'unit_price' => 380, 'basis' => 'manual'];
    $result = quick_cost_calculate($document);
    qc_equal(qc_item($result, 'transport')['amount'], 9880);
    $result['net_kg'] = 30000;
    qc_equal(qc_item(quick_cost_calculate($result), 'transport')['qty'], 26);
});
qc_test('Hızlı/Detaylı geçiş kalemleri ve toplamı değiştirmez', function (): void {
    $document = quick_cost_calculate(qc_example());
    $document['items'][0]['basis'] = 'manual';
    $document['items'][0]['qty'] = 12345;
    $before = quick_cost_calculate($document);
    $document['method'] = 'detailed';
    $detailed = quick_cost_calculate($document);
    $detailed['method'] = 'quick';
    $after = quick_cost_calculate($detailed);
    qc_equal($after['items'], $before['items']);
    qc_equal($after['total'], $before['total']);
});
qc_test('Sıfır net kg önizlemesi bölme hatası üretmez', function (): void {
    $document = qc_example(); $document['net_kg'] = 0;
    $result = quick_cost_calculate($document);
    qc_equal($result['unit_cost'], 0);
    qc_equal($result['total'], 8000);
});
qc_test('Türkçe virgül ve binlik ayracı güvenli işlenir', function (): void {
    foreach (['20.000' => 20000, '1,60' => 1.6, '1.234,5678' => 1234.5678, '0,0001' => 0.0001, '' => 0] as $input => $expected) {
        qc_equal(quick_cost_number((string)$input), $expected);
    }
    qc_equal(quick_cost_number(1.6), 1.6);
});
qc_test('Negatif/hatalı/taşan sayılar reddedilir', function (): void {
    foreach (['-1', '1.60', '1,2,3', '1.00.000', '1e3', 'NaN', 'Infinity', '1,23456', [], null, true, INF, -1, 1000001, 0.00001] as $input) {
        qc_throws(fn() => quick_cost_number($input));
    }
});
qc_test('Satır miktarı bağlı olsa bile negatif gönderim reddedilir', function (): void {
    $document = qc_example(); $document['items'][0]['qty'] = -1;
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Yarım kuruş ve dört ondalıklı çarpım yuvarlaması', function (): void {
    $document = quick_cost_defaults('2026-10-30'); $document['net_kg'] = 1;
    $document['items'][0]['unit_price'] = 1.005;
    $document['items'][1]['basis'] = 'manual'; $document['items'][1]['qty'] = 1.2345;
    $document['items'][1]['unit_price'] = 9.8765;
    $result = quick_cost_calculate($document);
    qc_equal(qc_item($result, 'product')['amount'], 1.01);
    qc_equal(qc_item($result, 'material')['amount'], 12.19);
    qc_equal($result['total'], 13.2);
});
qc_test('Üst ölçekte yarım kuruşun altındaki değer yukarı yuvarlanmaz', function (): void {
    qc_equal(quick_cost_amount_cents(999999.9999, 9950.0001), 995000009900);
    $document = quick_cost_defaults('2026-10-30');
    $document['net_kg'] = 999999.9999; $document['items'][0]['unit_price'] = 9950.0001;
    qc_equal(quick_cost_calculate($document)['total'], 9950000099.00);
});
qc_test('Net kg maliyeti altı ondalık eşik yuvarlaması', function (): void {
    qc_equal(quick_cost_unit_cost(1, 20000), 0.000001);
    qc_equal(quick_cost_unit_cost(1, 20000.0001), 0);
    qc_equal(quick_cost_unit_cost(1, 0.0003), 33.333333);
});
qc_test('Bilinmeyen/eksik/tekrarlanan kalemler reddedilir', function (): void {
    $document = qc_example(); $document['items'][0]['code'] = 'other';
    qc_throws(fn() => quick_cost_calculate($document));
    $document = qc_example(); array_pop($document['items']);
    qc_throws(fn() => quick_cost_calculate($document));
    $document = qc_example(); $document['items'][0] = $document['items'][1];
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Birim ve baz uyuşmazlığı reddedilir', function (): void {
    foreach ([['unit' => 'bad'], ['unit' => 'pallet'], ['basis' => 'fixed'], ['unit' => 'fixed', 'basis' => 'manual']] as $override) {
        $document = qc_example(); $document['items'][0] = array_merge($document['items'][0], $override);
        qc_throws(fn() => quick_cost_calculate($document));
    }
});
qc_test('Takvim/işlem/yöntem/nesne/metin doğrulaması', function (): void {
    foreach ([['sheet_date' => '2026-02-30'], ['sheet_date' => []], ['operation_type' => 'unknown'], ['method' => []],
        ['product' => []], ['notes' => str_repeat('a', 2001)], ['product' => "bad\0"]] as $override) {
        qc_throws(fn() => quick_cost_calculate(array_merge(qc_example(), $override)));
    }
});
qc_test('Hesaplanan toplam ve etiketler sunucuda yeniden oluşturulur', function (): void {
    $document = qc_example(); $document['total'] = 1; $document['unit_cost'] = 0; $document['groups'] = ['A' => 0];
    $document['items'][0]['amount'] = 1; $document['items'][0]['label'] = 'FAKE';
    $result = quick_cost_calculate($document);
    qc_equal($result['total'], 670000); qc_equal($result['items'][0]['label'], 'Ürün maliyeti');
});
qc_test('Tutar üst sınırı reddedilir', function (): void {
    $document = qc_example(); $document['net_kg'] = 1000000; $document['items'][0]['unit_price'] = 1000000;
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Storage probe salt okunur ve yalnız eksik tabloda false', function () use ($QC_DB): void {
    qc_assert(quick_cost_storage_ready($QC_DB));
    $empty = new PDO('sqlite::memory:'); $empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    qc_assert(!quick_cost_storage_ready($empty));
    $empty->exec('CREATE TABLE quick_cost_sheets(id INTEGER)');
    qc_throws(fn() => quick_cost_storage_ready($empty), PDOException::class);
});
$saved = null;
qc_test('Kaydet/aç: aynı sonuç, kullanıcı/depo/zaman/sürüm damgası', function () use ($QC_DB, &$saved): void {
    $document = qc_example(); $document['created_by'] = 999; $document['depo'] = 'FORGED'; $document['total'] = 1;
    $saved = quick_cost_save($QC_DB, $document);
    qc_equal($saved['created_by'], 7); qc_equal($saved['updated_by'], 7); qc_equal($saved['depo'], 'TEST DEPO A');
    qc_equal($saved['version'], 1); qc_assert($saved['created_at'] !== '' && $saved['updated_at'] !== '');
    qc_equal(quick_cost_find($QC_DB, $saved['id'])['document']['total'], 670000);
    qc_equal($QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 1);
});
qc_test('Fason/Üretim ve yöntem kayıtta korunur', function () use ($QC_DB, &$saved): void {
    $document = $saved['document']; $document['operation_type'] = 'uretim'; $document['method'] = 'detailed';
    $saved = quick_cost_save($QC_DB, $document, $saved['id'], $saved['version']);
    qc_equal($saved['version'], 2); qc_equal($saved['document']['operation_type'], 'uretim');
    qc_equal($saved['document']['method'], 'detailed'); qc_equal($saved['created_by'], 7);
});
qc_test('Eski sürüm yeni değişikliği ezemez', function () use ($QC_DB, &$saved): void {
    qc_throws(fn() => quick_cost_save($QC_DB, qc_example(), $saved['id'], 1), QuickCostConflictException::class);
    qc_equal(quick_cost_find($QC_DB, $saved['id'])['version'], 2);
});
qc_test('Kopya yeni kayıt üretir, kaynak kaydı değiştirmez', function () use ($QC_DB, &$saved): void {
    $source = quick_cost_find($QC_DB, $saved['id']);
    $copy = quick_cost_save($QC_DB, $source['document']);
    qc_assert($copy['id'] !== $source['id']); qc_equal($copy['version'], 1);
    qc_equal($copy['document'], $source['document']);
    qc_equal(quick_cost_find($QC_DB, $source['id'])['version'], 2);
});
qc_test('Sıfır kg/boş ürün kaydedilmez', function () use ($QC_DB): void {
    $document = qc_example(); $document['net_kg'] = 0;
    qc_throws(fn() => quick_cost_save($QC_DB, $document));
    $document = qc_example(); $document['product'] = '  ';
    qc_throws(fn() => quick_cost_save($QC_DB, $document));
});
qc_test('Yazma yetkisi olmayan kullanıcı kayıt oluşturamaz/değiştiremez', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_PERMS']['write'] = false;
    try {
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example()), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example(), $saved['id'], 2), QuickCostAccessException::class);
        qc_assert(quick_cost_find($QC_DB, $saved['id']) !== null);
    } finally { $GLOBALS['QC_PERMS']['write'] = true; }
});
qc_test('Maliyet okuma/yazma yetkili yönetici olmayan kullanıcıda store kapalı', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_ADMIN'] = false;
    $depotCallsBefore = $GLOBALS['QC_DEPOT_CALLS'];
    // Constructor bağlantı kurmaz. Yanlışlıkla PDO'ya ulaşılırsa erişim
    // exception'ı yerine uninitialized PDO hatası oluşur ve test başarısız olur.
    $blockedPdo = new class extends PDO { public function __construct() {} };
    try {
        qc_throws(fn() => quick_cost_list($blockedPdo), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_find($blockedPdo, $saved['id']), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($blockedPdo, qc_example()), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($blockedPdo, qc_example(), $saved['id'], $saved['version']), QuickCostAccessException::class);
        qc_equal($GLOBALS['QC_DEPOT_CALLS'], $depotCallsBefore);
    } finally { $GLOBALS['QC_ADMIN'] = true; }
});
qc_test('Okuma yetkisi yoksa liste/görüntüleme/yazma kapalı', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_PERMS']['read'] = false;
    try {
        qc_throws(fn() => quick_cost_list($QC_DB), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_find($QC_DB, $saved['id']), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example()), QuickCostAccessException::class);
    } finally { $GLOBALS['QC_PERMS']['read'] = true; }
});
qc_test('Aktif depo olmadan veya oturum yokken erişim kapalı', function () use ($QC_DB): void {
    $GLOBALS['QC_DEPOT'] = null;
    try { qc_throws(fn() => quick_cost_list($QC_DB), QuickCostAccessException::class); }
    finally { $GLOBALS['QC_DEPOT'] = 'TEST DEPO A'; }
    $GLOBALS['QC_USER'] = null;
    try { qc_throws(fn() => quick_cost_save($QC_DB, qc_example()), QuickCostAccessException::class); }
    finally { $GLOBALS['QC_USER'] = ['id' => 7]; }
});
qc_test('Başka aktif depoda kayıt görülmez ve değiştirilemez', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_DEPOT'] = 'TEST DEPO B';
    try {
        qc_equal(quick_cost_list($QC_DB)['total'], 0); qc_equal(quick_cost_find($QC_DB, $saved['id']), null);
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example(), $saved['id'], 2), QuickCostAccessException::class);
        $other = quick_cost_save($QC_DB, qc_example());
        qc_equal($other['depo'], 'TEST DEPO B');
    } finally { $GLOBALS['QC_DEPOT'] = 'TEST DEPO A'; }
    qc_equal(quick_cost_list($QC_DB)['total'], 2);
});
qc_test('Admin kısıtsız helper sonucu bile aktif depoyu genişletmez', function () use ($QC_DB): void {
    $GLOBALS['QC_UNRESTRICTED_SCOPE'] = true;
    try { qc_equal(quick_cost_list($QC_DB)['total'], 2); }
    finally { $GLOBALS['QC_UNRESTRICTED_SCOPE'] = false; }
});
qc_test('Arama wildcard kaçışı ve sayfalama', function () use ($QC_DB): void {
    qc_equal(quick_cost_list($QC_DB, 1, 1)['pages'], 2);
    qc_equal(count(quick_cost_list($QC_DB, 999, 1)['items']), 1);
    qc_equal(quick_cost_list($QC_DB, 1, 20, '%')['total'], 0);
    qc_equal(quick_cost_list($QC_DB, 1, 20, 'TEST')['total'], 2);
});
qc_test('DB yazma hatasında kayıt ve audit değişmeden rollback olur', function () use ($QC_DB, &$saved): void {
    $before = quick_cost_find($QC_DB, $saved['id']);
    $auditCount = $QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $QC_DB->exec("CREATE TRIGGER qc_abort BEFORE UPDATE ON quick_cost_sheets WHEN NEW.product='ROLLBACK'
        BEGIN SELECT RAISE(ABORT,'anonymous storage failure'); END");
    $document = $before['document']; $document['product'] = 'ROLLBACK';
    qc_throws(fn() => quick_cost_save($QC_DB, $document, $before['id'], $before['version']), PDOException::class);
    qc_equal(quick_cost_find($QC_DB, $before['id']), $before);
    qc_equal($QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), $auditCount);
    qc_assert(!$QC_DB->inTransaction());
});
qc_test('Çağıranın açık işlemi sahiplenilmez', function () use ($QC_DB): void {
    $QC_DB->beginTransaction();
    try {
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example()), LogicException::class);
        qc_assert($QC_DB->inTransaction());
    } finally { $QC_DB->rollBack(); }
});
qc_test('Eski maliyet/yükleme tabloları değişmedi', function () use ($QC_DB, $legacyBefore): void {
    qc_equal(json_encode([$QC_DB->query('SELECT * FROM cost_sheets')->fetchAll(), $QC_DB->query('SELECT * FROM loading_records')->fetchAll()]), $legacyBefore);
});

echo "\nHızlı Maliyet: $passed geçti, $failed başarısız. Yalnız anonim SQLite.\n";
exit($failed === 0 ? 0 : 1);
