<?php
// CLI only. Saf model + anonim bellek içi SQLite; uygulama bootstrap/gerçek DB yok.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnız CLI.'); }
require_once __DIR__ . '/../config/quick_cost_store.php';

$QC_USER = ['id' => 7];
$QC_ADMIN = false; // varsayılan: yönetici DEĞİL, hizli_maliyet.* izinleri tam
$QC_PERMS = ['read' => true, 'write' => true, 'delete' => true];
$QC_DEPOT = 'TEST DEPO A';
$QC_DEPOT_CALLS = 0;
$QC_UNRESTRICTED_SCOPE = false;
$QC_DB = new PDO('sqlite::memory:');
$QC_DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$QC_DB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function current_user(): ?array { return $GLOBALS['QC_USER']; }
function is_admin(): bool { return $GLOBALS['QC_ADMIN']; }
// config/helpers.php can_hizli_maliyet() aynası: admin her zaman geçer; write/delete okumayı da ister.
function can_hizli_maliyet(string $perm): bool {
    if ($GLOBALS['QC_ADMIN']) return in_array($perm, ['read', 'write', 'delete'], true);
    $p = $GLOBALS['QC_PERMS'];
    return match ($perm) {
        'read' => !empty($p['read']),
        'write' => !empty($p['read']) && !empty($p['write']),
        'delete' => !empty($p['read']) && !empty($p['delete']),
        default => false,
    };
}
// Eski maliyet.* izni: Hızlı Maliyet'i AÇMAMALI — bu çağrı olursa test düşsün.
function can_maliyet(string $perm): bool { throw new LogicException('Hızlı Maliyet eski can_maliyet() kapısını kullanmamalı.'); }
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
function qc_custom(string $code = 'custom_extra', string $label = 'özel gider'): array {
    return ['code' => $code, 'label' => $label, 'unit' => 'pallet', 'basis' => 'manual', 'qty' => 26, 'unit_price' => 380];
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
qc_test('Ek maliyet özgün birim/miktar/fiyat ile bir kez toplanır, grup sunucuda D', function (): void {
    $document = qc_example(); $extra = qc_custom(); $extra['group'] = 'A'; $extra['amount'] = 1;
    $document['items'][] = $extra;
    $result = quick_cost_calculate($document);
    qc_equal(count($result['items']), 11); qc_equal($result['total'], 679880);
    qc_equal(qc_item($result, 'custom_extra')['group'], 'D');
    qc_equal(qc_item($result, 'custom_extra')['label'], 'ÖZEL GİDER');
    qc_equal(qc_item($result, 'custom_extra')['amount'], 9880);
    qc_equal($result['groups'], ['A' => 500000, 'B' => 80000, 'D' => 99880]);
});
qc_test('Ek kalem kod/ad/baz doğrulaması ve tekrarlanan kod reddi', function (): void {
    foreach ([['code' => 'other'], ['code' => 'custom_'], ['code' => 'custom_UPPER'], ['code' => 'custom_' . str_repeat('a', 41)],
        ['label' => ''], ['label' => '   '], ['label' => []], ['label' => str_repeat('a', 151)], ['label' => str_repeat('ß', 150)],
        ['basis' => 'net_kg']] as $change) {
        $document = qc_example(); $document['items'][] = array_merge(qc_custom(), $change);
        qc_throws(fn() => quick_cost_calculate($document));
    }
    $document = qc_example(); $document['items'][] = qc_custom(); $document['items'][] = qc_custom();
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Ek kalem temel on kalemden birinin yerine geçemez', function (): void {
    $document = qc_example(); array_shift($document['items']); $document['items'][] = qc_custom();
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Kırk ek kalem kabul, kırk bir reddedilir; Unicode payload bütçesi', function (): void {
    $document = qc_example();
    $document['product'] = str_repeat('🟢', 150); $document['notes'] = str_repeat('🟢', 2000);
    for ($i = 0; $i < 40; $i++) $document['items'][] = qc_custom('custom_' . $i, str_repeat('🟢', 150));
    $result = quick_cost_calculate($document);
    qc_equal(count($result['items']), 50);
    qc_equal($result['total'], 1065200);
    qc_assert(strlen(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) < 65536);
    $document['items'][] = qc_custom('custom_over');
    qc_throws(fn() => quick_cost_calculate($document));
});
qc_test('Türkçe ürün/not/ek kalem büyük harf dönüşümü ve son uzunluk sınırı', function (): void {
    $document = qc_example(); $document['product'] = 'istanbul ışık çiftçi'; $document['notes'] = "çiğ ürün\nözel iş";
    $document['items'][] = qc_custom('custom_' . str_repeat('a', 40), 'ilave işçilik');
    $result = quick_cost_calculate($document);
    qc_equal($result['product'], 'İSTANBUL IŞIK ÇİFTÇİ');
    qc_equal($result['notes'], "ÇİĞ ÜRÜN\nÖZEL İŞ");
    qc_equal($result['items'][10]['label'], 'İLAVE İŞÇİLİK');
    foreach ([['product' => str_repeat('ß', 150)], ['notes' => str_repeat('ß', 2000)]] as $change) {
        qc_throws(fn() => quick_cost_calculate(array_merge(qc_example(), $change)));
    }
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
qc_test('hizli_maliyet.* izni olmayan (eski maliyet.* izni olsa da) kullanıcıda store kapalı', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_PERMS'] = ['read' => false, 'write' => false, 'delete' => false];
    $depotCallsBefore = $GLOBALS['QC_DEPOT_CALLS'];
    // Constructor bağlantı kurmaz. Yanlışlıkla PDO'ya ulaşılırsa erişim
    // exception'ı yerine uninitialized PDO hatası oluşur ve test başarısız olur.
    $blockedPdo = new class extends PDO { public function __construct() {} };
    try {
        qc_throws(fn() => quick_cost_list($blockedPdo), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_find($blockedPdo, $saved['id']), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($blockedPdo, qc_example()), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_save($blockedPdo, qc_example(), $saved['id'], $saved['version']), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_delete($blockedPdo, $saved['id'], $saved['version']), QuickCostAccessException::class);
        qc_equal($GLOBALS['QC_DEPOT_CALLS'], $depotCallsBefore);
    } finally { $GLOBALS['QC_PERMS'] = ['read' => true, 'write' => true, 'delete' => true]; }
});
qc_test('Yönetici hizli_maliyet.* satırı olmadan da store kapısından geçer', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_ADMIN'] = true;
    $GLOBALS['QC_PERMS'] = ['read' => false, 'write' => false, 'delete' => false];
    try {
        qc_assert(quick_cost_find($QC_DB, $saved['id']) !== null);
        qc_equal(quick_cost_access(true, true)[0], 7);
    } finally { $GLOBALS['QC_ADMIN'] = false; $GLOBALS['QC_PERMS'] = ['read' => true, 'write' => true, 'delete' => true]; }
});
qc_test('Yalnız okuma: listeler/açar, kaydetmez ve silmez', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_PERMS'] = ['read' => true, 'write' => false, 'delete' => false];
    try {
        qc_assert(quick_cost_find($QC_DB, $saved['id']) !== null);
        qc_assert(quick_cost_list($QC_DB)['total'] >= 1);
        qc_throws(fn() => quick_cost_save($QC_DB, qc_example()), QuickCostAccessException::class);
        qc_throws(fn() => quick_cost_delete($QC_DB, $saved['id'], $saved['version']), QuickCostAccessException::class);
    } finally { $GLOBALS['QC_PERMS'] = ['read' => true, 'write' => true, 'delete' => true]; }
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
qc_test('Ek kalem kaydet/aç/düzenle/kopyala kimlik ve özgün fiyatı korur', function () use ($QC_DB): void {
    $document = qc_example(); $document['product'] = 'kiraz'; $document['notes'] = 'özel iş';
    $document['items'][] = qc_custom('custom_stable', 'ilave işçilik');
    $source = quick_cost_save($QC_DB, $document);
    qc_equal($source['product'], 'KİRAZ'); qc_equal($source['notes'], 'ÖZEL İŞ');
    $opened = quick_cost_find($QC_DB, $source['id']);
    qc_equal(qc_item($opened['document'], 'custom_stable')['label'], 'İLAVE İŞÇİLİK');
    qc_equal(qc_item($opened['document'], 'custom_stable')['qty'], 26);
    qc_equal(qc_item($opened['document'], 'custom_stable')['unit_price'], 380);
    $changed = $opened['document']; $changed['items'][10]['unit_price'] = 400;
    $updated = quick_cost_save($QC_DB, $changed, $opened['id'], $opened['version']);
    qc_equal($updated['document']['total'], 680400);
    $copy = quick_cost_save($QC_DB, $updated['document']);
    qc_assert($copy['id'] !== $updated['id']); qc_equal($copy['document'], $updated['document']);
    qc_equal(quick_cost_find($QC_DB, $source['id'])['version'], 2);
});
qc_test('Silme okuma/silme kapıları PDO öncesi kapanır; yazma izni silme için gerekmez', function () use ($QC_DB): void {
    $blockedPdo = new class extends PDO { public function __construct() {} };
    foreach (['read', 'delete'] as $permission) {
        $GLOBALS['QC_PERMS'][$permission] = false;
        try { qc_throws(fn() => quick_cost_delete($blockedPdo, 1, 1), QuickCostAccessException::class); }
        finally { $GLOBALS['QC_PERMS'][$permission] = true; }
    }
    $GLOBALS['QC_PERMS']['write'] = false;
    try { qc_equal(quick_cost_access(false, true)[0], 7); }
    finally { $GLOBALS['QC_PERMS']['write'] = true; }
});
qc_test('Silme geçersiz id/sürümü SQL öncesi reddeder', function (): void {
    $blockedPdo = new class extends PDO { public function __construct() {} };
    foreach ([[0, 1], [-1, 1], [1, 0], [1, -1]] as [$id, $version]) {
        qc_throws(fn() => quick_cost_delete($blockedPdo, $id, $version));
    }
});
qc_test('Silme eski sürümü ve bulunmayan kaydı reddeder', function () use ($QC_DB, &$saved): void {
    $before = quick_cost_find($QC_DB, $saved['id']);
    qc_throws(fn() => quick_cost_delete($QC_DB, $saved['id'], 1), QuickCostConflictException::class);
    qc_throws(fn() => quick_cost_delete($QC_DB, 999999, 1), QuickCostAccessException::class);
    qc_equal(quick_cost_find($QC_DB, $saved['id']), $before);
    qc_assert(!$QC_DB->inTransaction());
});
qc_test('Silme aktif depo dışında kaydı etkilemez', function () use ($QC_DB, &$saved): void {
    $GLOBALS['QC_DEPOT'] = 'TEST DEPO B';
    try { qc_throws(fn() => quick_cost_delete($QC_DB, $saved['id'], $saved['version']), QuickCostAccessException::class); }
    finally { $GLOBALS['QC_DEPOT'] = 'TEST DEPO A'; }
    qc_assert(quick_cost_find($QC_DB, $saved['id']) !== null);
});
qc_test('Silme çağıranın açık transactionını sahiplenmez', function () use ($QC_DB, &$saved): void {
    $QC_DB->beginTransaction();
    try {
        qc_throws(fn() => quick_cost_delete($QC_DB, $saved['id'], $saved['version']), LogicException::class);
        qc_assert($QC_DB->inTransaction());
    } finally { $QC_DB->rollBack(); }
});
qc_test('Yalnız hedef hızlı kayıt silinir ve eski özet audite gider', function () use ($QC_DB, &$saved): void {
    $target = quick_cost_save($QC_DB, qc_example());
    $beforeCount = (int)$QC_DB->query('SELECT COUNT(*) FROM quick_cost_sheets')->fetchColumn();
    quick_cost_delete($QC_DB, $target['id'], $target['version']);
    qc_equal($QC_DB->query('SELECT COUNT(*) FROM quick_cost_sheets')->fetchColumn(), $beforeCount - 1);
    qc_equal(quick_cost_find($QC_DB, $target['id']), null);
    qc_assert(quick_cost_find($QC_DB, $saved['id']) !== null);
    $audit = $QC_DB->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
    qc_equal($audit['action'], 'delete'); qc_equal($audit['module'], 'quick_cost_sheets');
    qc_equal($audit['record_id'], $target['id']); qc_equal(json_decode($audit['new_values'], true), null);
    $old = json_decode($audit['old_values'], true);
    qc_equal($old['product'], $target['product']); qc_equal($old['version'], 1); qc_equal($old['total'], 670000);
    qc_throws(fn() => quick_cost_delete($QC_DB, $target['id'], 1), QuickCostAccessException::class);
});
qc_test('Bozuk JSON kaydı metadata kontrollü silinebilir', function () use ($QC_DB): void {
    $target = quick_cost_save($QC_DB, qc_example());
    $QC_DB->prepare("UPDATE quick_cost_sheets SET document_json='broken' WHERE id=?")->execute([$target['id']]);
    quick_cost_delete($QC_DB, $target['id'], $target['version']);
    qc_equal(quick_cost_find($QC_DB, $target['id']), null);
});
qc_test('DELETE DB hatasında kayıt/audit korunur ve rollback tamamlanır', function () use ($QC_DB): void {
    $target = quick_cost_save($QC_DB, qc_example());
    $auditCount = $QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $QC_DB->exec('CREATE TRIGGER qc_delete_abort BEFORE DELETE ON quick_cost_sheets WHEN OLD.id=' . $target['id'] . " BEGIN SELECT RAISE(ABORT,'anonymous delete failure'); END");
    try {
        qc_throws(fn() => quick_cost_delete($QC_DB, $target['id'], $target['version']), PDOException::class);
        qc_assert(quick_cost_find($QC_DB, $target['id']) !== null);
        qc_equal($QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), $auditCount);
        qc_assert(!$QC_DB->inTransaction());
    } finally { $QC_DB->exec('DROP TRIGGER qc_delete_abort'); }
});
qc_test('DELETE etkilenen satır sıfırsa sürüm çakışması ve rollback', function () use ($QC_DB): void {
    $target = quick_cost_save($QC_DB, qc_example());
    $auditCount = $QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    $QC_DB->exec('CREATE TRIGGER qc_delete_ignore BEFORE DELETE ON quick_cost_sheets WHEN OLD.id=' . $target['id'] . ' BEGIN SELECT RAISE(IGNORE); END');
    try {
        qc_throws(fn() => quick_cost_delete($QC_DB, $target['id'], $target['version']), QuickCostConflictException::class);
        qc_assert(quick_cost_find($QC_DB, $target['id']) !== null);
        qc_equal($QC_DB->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), $auditCount);
        qc_assert(!$QC_DB->inTransaction());
    } finally { $QC_DB->exec('DROP TRIGGER qc_delete_ignore'); }
});
qc_test('Eski maliyet/yükleme tabloları değişmedi', function () use ($QC_DB, $legacyBefore): void {
    qc_equal(json_encode([$QC_DB->query('SELECT * FROM cost_sheets')->fetchAll(), $QC_DB->query('SELECT * FROM loading_records')->fetchAll()]), $legacyBefore);
});

echo "\nHızlı Maliyet: $passed geçti, $failed başarısız. Yalnız anonim SQLite.\n";
exit($failed === 0 ? 0 : 1);
