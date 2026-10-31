<?php
// CLI only. Hızlı Maliyet v2 motoru + depo katmanı; anonim bellek içi SQLite, uygulama bootstrap'ı yok.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Yalnız CLI.'); }
require_once __DIR__ . '/../config/quick_cost_store.php';

if (($argv[1] ?? '') === '--json') {
    // Parite testi: stdin'den belge listesi alır, her biri için sonuç ya da hata döndürür.
    $cases = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $out = [];
    foreach ($cases as $case) {
        try { $out[] = ['ok' => quick_cost_calculate($case)]; }
        catch (InvalidArgumentException $e) { $out[] = ['error' => $e->getMessage()]; }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    exit;
}

$QC_USER = ['id' => 7];
$QC_PERMS = ['read' => true, 'write' => true, 'delete' => true];
$QC_DEPOT = 'TEST DEPO A';
$QC_DB = new PDO('sqlite::memory:');
$QC_DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$QC_DB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function current_user(): ?array { return $GLOBALS['QC_USER']; }
function is_admin(): bool { return false; }
function can_hizli_maliyet(string $perm): bool { $p = $GLOBALS['QC_PERMS']; return !empty($p['read']) && !empty($p[$perm]); }
function active_depot(): ?string { return $GLOBALS['QC_DEPOT']; }
function depot_visible_to_user(?string $depot): bool { return $depot === $GLOBALS['QC_DEPOT']; }
function depo_sql_in(string $column): array { return [$column . ' IN (?)', [$GLOBALS['QC_DEPOT']]]; }
function audit_log_event(string $action, string $module, ?int $id = null, ?array $old = null, ?array $new = null): void {
    $GLOBALS['QC_DB']->prepare('INSERT INTO audit_log(action,module,record_id,new_values) VALUES(?,?,?,?)')->execute([$action, $module, $id, json_encode($new)]);
}
$QC_DB->exec('CREATE TABLE quick_cost_sheets (id INTEGER PRIMARY KEY AUTOINCREMENT, product TEXT NOT NULL, sheet_date TEXT NOT NULL,
    operation_type TEXT NOT NULL, method TEXT NOT NULL, net_kg NUMERIC NOT NULL, document_json TEXT NOT NULL, total NUMERIC NOT NULL,
    unit_cost NUMERIC NOT NULL, notes TEXT NOT NULL, depo TEXT NOT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL)');
$QC_DB->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, action TEXT, module TEXT, record_id INTEGER, new_values TEXT)');

$passed = 0; $failed = 0;
function ok(bool $c, string $m = ''): void { if (!$c) throw new RuntimeException($m ?: 'Beklenti sağlanmadı.'); }
function eq($a, $e): void { ok($a == $e, 'Beklenen ' . json_encode($e, JSON_UNESCAPED_UNICODE) . ', alınan ' . json_encode($a, JSON_UNESCAPED_UNICODE)); }
function throws(callable $f, string $needle = '', string $class = InvalidArgumentException::class): void {
    try { $f(); } catch (Throwable $e) {
        ok($e instanceof $class, 'Yanlış exception: ' . get_class($e) . ' ' . $e->getMessage());
        ok($needle === '' || str_contains($e->getMessage(), $needle), 'Mesaj beklenmiyor: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Exception bekleniyordu.');
}
function t(string $name, callable $f): void {
    global $passed, $failed;
    try { $f(); $passed++; echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
function v2_example(): array {
    $d = quick_cost_v2_defaults('2026-10-31');
    $d['product'] = 'nar';
    $d['quantities'] = ['raw_kg' => 25000, 'processed_kg' => 25000, 'export_kg' => 20000, 'domestic_kg' => 3000, 'industry_kg' => 0, 'waste_kg' => 2000];
    $prices = ['raw_purchase' => 20, 'sorting_labor' => 2, 'material' => 4, 'field_transport' => 15000,
        'export_freight' => 60000, 'customs' => 5000, 'official_analysis' => 3000];
    foreach ($d['items'] as &$i) $i['unit_price'] = $prices[$i['code']] ?? 0;
    unset($i);
    return $d;
}
function row(array $r, string $code): array { foreach ($r['items'] as $i) if ($i['code'] === $code) return $i; throw new RuntimeException('Kalem yok: ' . $code); }
function setRow(array $d, string $code, array $c): array { foreach ($d['items'] as &$i) if ($i['code'] === $code) $i = array_merge($i, $c); unset($i); return $d; }
function codes(array $r): array { return array_column($r['warnings'], 'code'); }

t('Zorunlu senaryo: 713.000 TL, %80, ihtiyatlı 35,65 TL/kg', function (): void {
    $r = quick_cost_calculate(v2_example());
    eq($r['total'], 713000); eq($r['derived']['yield_pct'], 80); eq($r['unit_cost'], 35.65); eq($r['unit_cost_prudent'], 35.65);
    eq($r['export_cost'], 713000); eq($r['credit_applied'], 0); eq($r['net_kg'], 20000);
    eq(row($r, 'raw_purchase')['amount'], 500000); eq(row($r, 'sorting_labor')['amount'], 50000); eq(row($r, 'material')['amount'], 80000);
    eq($r['groups'], ['raw' => 500000, 'supply' => 15000, 'sorting' => 50000, 'packing' => 0, 'material' => 80000, 'fason' => 0, 'export' => 68000, 'other' => 0]);
    eq($r['derived']['unclassified_kg'], 0);
});
t('Yan ürün kredisi açıkça seçilince 689.000 TL / 34,45 TL/kg', function (): void {
    $d = v2_example();
    $d['credit'] = ['apply' => true, 'domestic_sold_kg' => 3000, 'domestic_price' => 8, 'industry_sold_kg' => 0, 'industry_price' => 0];
    $r = quick_cost_calculate($d);
    eq($r['total'], 713000); eq($r['credit_total'], 24000); eq($r['credit_applied'], 24000);
    eq($r['export_cost'], 689000); eq($r['unit_cost'], 34.45); eq($r['unit_cost_prudent'], 35.65);
    ok(in_array('credit_applied', codes($r), true));
});
t('Kredi seçilmezse gelir düşülmez; satılmamış ürün otomatik gelir sayılmaz', function (): void {
    $d = v2_example();
    $d['credit'] = ['apply' => false, 'domestic_sold_kg' => 3000, 'domestic_price' => 8];
    $r = quick_cost_calculate($d);
    eq($r['export_cost'], 713000); eq($r['unit_cost'], 35.65); ok(in_array('credit_not_applied', codes($r), true));
    $d = v2_example(); $d['credit']['apply'] = true; // satış girilmemiş
    $r = quick_cost_calculate($d);
    eq($r['credit_applied'], 0); eq($r['export_cost'], 713000); ok(!in_array('credit_applied', codes($r), true));
});
t('Aynı gelir iki kez sayılmaz: satış kârı ihracata yüklenen maliyetten hesaplanır', function (): void {
    $d = v2_example();
    $d['credit'] = ['apply' => true, 'domestic_sold_kg' => 3000, 'domestic_price' => 8, 'industry_sold_kg' => 0, 'industry_price' => 0];
    $d['sale'] = ['price' => 40, 'currency' => 'TL', 'rate' => 1, 'target_margin' => null];
    $r = quick_cost_calculate($d);
    eq($r['sale_result']['revenue_tl'], 800000); // yalnız ihracat satışı; iç piyasa geliri satışa EKLENMEZ
    eq($r['sale_result']['profit'], 111000);     // 800.000 − 689.000
});
t('Satılan KG ayrılan KG\'yi, kredi toplam maliyeti aşamaz', function (): void {
    $d = v2_example(); $d['credit'] = ['apply' => false, 'domestic_sold_kg' => 3001, 'domestic_price' => 1];
    throws(fn() => quick_cost_calculate($d), 'İç piyasada satılan KG');
    $d = v2_example(); $d['credit'] = ['apply' => false, 'industry_sold_kg' => 1, 'industry_price' => 1];
    throws(fn() => quick_cost_calculate($d), 'Sanayiye satılan KG');
    $d = v2_example(); $d['credit'] = ['apply' => true, 'domestic_sold_kg' => 3000, 'domestic_price' => 1000];
    throws(fn() => quick_cost_calculate($d), 'aşamaz');
});
t('Tabanlar: ham alış ham KG, ayıklama işlenen KG, malzeme/paketleme ihracat KG', function (): void {
    $d = v2_example(); $d['quantities']['processed_kg'] = 24000;
    $d = setRow($d, 'packing_labor', ['unit_price' => 1]);
    $r = quick_cost_calculate($d);
    eq(row($r, 'raw_purchase')['qty'], 25000); eq(row($r, 'raw_purchase')['amount'], 500000);
    eq(row($r, 'sorting_labor')['qty'], 24000); eq(row($r, 'sorting_labor')['amount'], 48000);
    eq(row($r, 'packing_labor')['qty'], 20000); eq(row($r, 'packing_labor')['amount'], 20000);
    eq(row($r, 'material')['qty'], 20000); eq($r['total'], 731000);
});
t('İşlenen KG boşsa ham KG varsayılır ve açıkça belirtilir', function (): void {
    $d = v2_example(); $d['quantities']['processed_kg'] = null;
    $r = quick_cost_calculate($d);
    ok($r['derived']['processed_assumed']); eq($r['derived']['processed_kg'], 25000); eq($r['quantities']['processed_kg'], null);
    ok(in_array('processed_assumed', codes($r), true));
    $d['quantities']['processed_kg'] = ''; ok(quick_cost_calculate($d)['derived']['processed_assumed']);
});
t('Randıman ve ters randıman (hedef 20.000 KG, %80 → 25.000 ham KG)', function (): void {
    $d = v2_example(); $d['yield_mode'] = 'target'; $d['target_yield'] = 80; $d['quantities']['raw_kg'] = 1; // yok sayılır
    $r = quick_cost_calculate($d);
    eq($r['derived']['raw_kg'], 25000); eq($r['quantities']['raw_kg'], 25000); eq($r['total'], 713000); eq($r['derived']['yield_pct'], 80);
    $d['target_yield'] = '75,5'; $d['quantities'] = ['raw_kg' => 0, 'processed_kg' => null, 'export_kg' => 20000, 'domestic_kg' => 0, 'industry_kg' => 0, 'waste_kg' => 0];
    eq(quick_cost_calculate($d)['derived']['raw_kg'], 26490.0662); // 20000 / 0,755
    $d['target_yield'] = 0; throws(fn() => quick_cost_calculate($d), 'randıman');
    $d['target_yield'] = '80,123'; throws(fn() => quick_cost_calculate($d), 'iki ondalık');
    $d['target_yield'] = 101; throws(fn() => quick_cost_calculate($d), '%100');
    $d['target_yield'] = 2; $d['quantities']['export_kg'] = 30000; throws(fn() => quick_cost_calculate($d), '1.000.000');
    $r = quick_cost_calculate(v2_example()); eq(quick_cost_calculate($r), $r); // hesap çıktısı tekrar hesaplanınca aynı (idempotent)
});
t('Ürün dağılımı dengesi: aşım hata, kalan sınıflandırılmamış', function (): void {
    $d = v2_example(); $d['quantities']['waste_kg'] = 2001; throws(fn() => quick_cost_calculate($d), 'dağılımı');
    $d = v2_example(); $d['quantities']['waste_kg'] = 500; $d['quantities']['industry_kg'] = 700;
    $r = quick_cost_calculate($d); eq($r['derived']['unclassified_kg'], 800); ok(in_array('unclassified', codes($r), true));
    $d = v2_example(); $d['quantities']['export_kg'] = 25001; throws(fn() => quick_cost_calculate($d), 'ham mal');
    $d = v2_example(); $d['quantities']['processed_kg'] = 25001; throws(fn() => quick_cost_calculate($d), 'İşlenen');
    $d = v2_example(); $d['quantities']['processed_kg'] = 19999; throws(fn() => quick_cost_calculate($d), 'işlenen KG');
    $d = v2_example(); $d['quantities']['domestic_kg'] = 3000; $r = quick_cost_calculate($d);
    eq($r['quantities']['domestic_kg'], 3000); eq($r['quantities']['waste_kg'], 2000); // iç piyasa fireye karışmaz
});
t('Ton, palet, koli, araç ve iki çarpanlı birimler', function (): void {
    $d = setRow(v2_example(), 'fason_service', ['basis' => 'processed_kg', 'unit' => 'ton', 'unit_price' => 1500]);
    eq(row(quick_cost_calculate($d), 'fason_service')['amount'], 37500); // 25 ton × 1.500
    $d = setRow(v2_example(), 'fason_service', ['basis' => 'processed_kg', 'unit' => 'ton', 'unit_price' => '0,0005']);
    eq(row(quick_cost_calculate($d), 'fason_service')['amount'], 0.01); // 25 ton × 0,0005 = 0,0125 → 0,01
    eq(quick_cost_v2_ton_cents(1, 5), 1); eq(quick_cost_v2_ton_cents(1, 4.9999), 0); eq(quick_cost_v2_ton_cents(999999.9999, 999999.9999), 99999999980);
    $d = setRow(v2_example(), 'loading', ['basis' => 'manual', 'unit' => 'pallet', 'qty' => 26, 'unit_price' => 380]);
    eq(row(quick_cost_calculate($d), 'loading')['amount'], 9880);
    $d = setRow(v2_example(), 'packing_labor', ['basis' => 'manual', 'unit' => 'worker_day', 'qty' => 12, 'qty2' => '2,5', 'unit_price' => 1200]);
    eq(row(quick_cost_calculate($d), 'packing_labor')['amount'], 36000);
    $d = setRow(v2_example(), 'cold_storage', ['basis' => 'manual', 'unit' => 'pallet_day', 'qty' => 26, 'qty2' => 3, 'unit_price' => 50]);
    eq(row(quick_cost_calculate($d), 'cold_storage')['amount'], 3900);
    $d = setRow(v2_example(), 'cold_storage', ['basis' => 'manual', 'unit' => 'ton_day', 'qty' => '0,3333', 'qty2' => '0,3333', 'unit_price' => 10000]);
    eq(row(quick_cost_calculate($d), 'cold_storage')['amount'], 1111); // 0,11108889 → 0,1111 × 10.000
    $d = setRow(v2_example(), 'cold_storage', ['basis' => 'manual', 'unit' => 'ton_day', 'qty' => 1000000, 'qty2' => 2, 'unit_price' => 1]);
    throws(fn() => quick_cost_calculate($d), 'çarpımı');
    $d = setRow(v2_example(), 'sorting_labor', ['basis' => 'manual', 'unit' => 'hour', 'qty' => 480, 'unit_price' => 150]);
    eq(row(quick_cost_calculate($d), 'sorting_labor')['amount'], 72000);
    $d = setRow(v2_example(), 'raw_purchase', ['basis' => 'fixed', 'unit' => 'fixed', 'qty' => 99, 'unit_price' => 1000]);
    eq(row(quick_cost_calculate($d), 'raw_purchase')['amount'], 1000); // sabit tutarda miktar 1
});
t('Uyumsuz taban/birim reddedilir', function (): void {
    foreach ([['basis' => 'raw_kg', 'unit' => 'pallet'], ['basis' => 'fixed', 'unit' => 'kg'], ['basis' => 'manual', 'unit' => 'fixed'],
        ['basis' => 'net_kg', 'unit' => 'kg'], ['basis' => 'manual', 'unit' => 'bogus']] as $c) {
        throws(fn() => quick_cost_calculate(setRow(v2_example(), 'material', $c)), 'uyumsuz');
    }
});
t('Bağlı/sabit satırda gönderilen miktar hesaba girmez ama bozuk değer reddedilir', function (): void {
    $r = quick_cost_calculate(setRow(v2_example(), 'material', ['qty' => 99999]));
    eq(row($r, 'material')['amount'], 80000);
    throws(fn() => quick_cost_calculate(setRow(v2_example(), 'material', ['qty' => '-1'])));
});
t('Fason: dahil işçilik/ambalaj tekrar eklenmez, uyarı verir', function (): void {
    $d = v2_example(); $d['fason_includes'] = ['labor' => true, 'material' => false];
    $d = setRow($d, 'fason_service', ['unit_price' => 3]);
    $r = quick_cost_calculate($d);
    eq(row($r, 'fason_service')['amount'], 75000); ok(row($r, 'sorting_labor')['excluded']); eq(row($r, 'sorting_labor')['amount'], 50000);
    eq($r['excluded_total'], 50000); eq($r['total'], 713000 - 50000 + 75000); ok(in_array('fason_excluded', codes($r), true));
    $d['fason_includes'] = ['labor' => true, 'material' => true]; $r = quick_cost_calculate($d);
    eq($r['excluded_total'], 130000); eq($r['groups']['material'], 0); eq($r['total'], 713000 - 130000 + 75000);
    $d = setRow($d, 'fason_service', ['unit_price' => 0]); ok(in_array('fason_service_zero', codes(quick_cost_calculate($d)), true));
    throws(fn() => quick_cost_calculate(array_merge(v2_example(), ['fason_includes' => ['labor' => 'evet']])), 'evet/hayır');
});
t('Üretimde fason dahil seçimi uygulanmaz; girilen fason bedeli sayılır ve uyarılır', function (): void {
    $d = v2_example(); $d['operation_type'] = 'uretim'; $d['fason_includes'] = ['labor' => true, 'material' => true];
    $d = setRow($d, 'fason_service', ['unit_price' => 1]);
    $r = quick_cost_calculate($d);
    eq($r['excluded_total'], 0); eq($r['total'], 713000 + 25000); ok(in_array('uretim_fason_service', codes($r), true));
});
t('Satış karşılaştırması TL / USD / EUR', function (): void {
    $d = v2_example(); $d['sale'] = ['price' => 40, 'currency' => 'TL', 'rate' => 99, 'target_margin' => 20];
    $s = quick_cost_calculate($d)['sale_result'];
    eq($s['revenue_tl'], 800000); eq($s['profit'], 87000); eq($s['profit_per_kg'], 4.35); eq($s['margin_pct'], 10.88);
    eq($s['break_even_tl'], 35.65); eq($s['target_price_tl'], 44.5625); eq(quick_cost_calculate($d)['sale']['rate'], 1);
    $d['sale'] = ['price' => '1,1', 'currency' => 'USD', 'rate' => '34,25', 'target_margin' => ''];
    $s = quick_cost_calculate($d)['sale_result'];
    eq($s['revenue_fx'], 22000); eq($s['revenue_tl'], 753500); eq($s['profit'], 40500); eq($s['margin_pct'], 5.37);
    eq($s['break_even_fx'], 1.0409); eq($s['target_price_tl'], null);
    $d['sale'] = ['price' => '0,95', 'currency' => 'EUR', 'rate' => '36,8', 'target_margin' => 10];
    $s = quick_cost_calculate($d)['sale_result'];
    eq($s['revenue_tl'], 699200); eq($s['profit'], -13800); eq($s['profit_per_kg'], -0.69); eq($s['margin_pct'], -1.97);
    eq($s['target_price_tl'], 39.6111); eq($s['target_price_fx'], 1.0764);
});
t('Satış doğrulaması: kur, marj, satış fiyatsız kayıt', function (): void {
    $d = v2_example(); $d['sale'] = ['price' => 1, 'currency' => 'EUR', 'rate' => 0];
    throws(fn() => quick_cost_calculate($d), 'kuru');
    $d['sale']['rate'] = 1001; throws(fn() => quick_cost_calculate($d), 'kuru');
    $d['sale'] = ['price' => 1, 'currency' => 'GBP', 'rate' => 40]; throws(fn() => quick_cost_calculate($d), 'Para birimi');
    $d['sale'] = ['price' => 1, 'currency' => 'TL', 'target_margin' => 100]; throws(fn() => quick_cost_calculate($d), 'marjı');
    $d['sale'] = ['price' => 1, 'currency' => 'TL', 'target_margin' => 150]; throws(fn() => quick_cost_calculate($d), 'marjı');
    $d['sale'] = ['price' => 0, 'currency' => 'EUR', 'rate' => 0]; eq(quick_cost_calculate($d)['sale_result'], null);
    unset($d['sale']); eq(quick_cost_calculate($d)['sale_result'], null);
    $d = v2_example(); $d['quantities']['export_kg'] = 0; $d['quantities']['domestic_kg'] = 0; $d['sale'] = ['price' => 5];
    $r = quick_cost_calculate($d); eq($r['sale_result'], null); eq($r['unit_cost'], 0); // sıfıra bölme yok
});
t('Ek kalem: ad zorunlu, grup Genel/diğer, en fazla kırk', function (): void {
    $d = v2_example(); $d['items'][] = ['code' => 'custom_a', 'label' => 'ilaçlama', 'basis' => 'fixed', 'unit' => 'fixed', 'unit_price' => 1000];
    $r = quick_cost_calculate($d); eq(row($r, 'custom_a')['label'], 'İLAÇLAMA'); eq($r['groups']['other'], 1000); eq($r['total'], 714000);
    $d['items'][17]['label'] = ' '; throws(fn() => quick_cost_calculate($d), 'adı');
    $d = v2_example(); for ($i = 0; $i < 41; $i++) $d['items'][] = ['code' => 'custom_' . $i, 'label' => 'x', 'basis' => 'fixed', 'unit' => 'fixed', 'unit_price' => 0];
    throws(fn() => quick_cost_calculate($d), 'kırk');
    $d = v2_example(); array_pop($d['items']); throws(fn() => quick_cost_calculate($d));
    $d = v2_example(); $d['items'][1]['code'] = 'raw_purchase'; throws(fn() => quick_cost_calculate($d), 'tekrarlanan');
});
t('Görünüm (Hızlı/Detaylı) hesabı değiştirmez', function (): void {
    $a = quick_cost_calculate(v2_example()); $d = v2_example(); $d['method'] = 'detailed'; $b = quick_cost_calculate($d);
    eq($a['items'], $b['items']); eq($a['total'], $b['total']); eq($b['method'], 'detailed');
});
t('Eski (v1) belge v1 motoruyla aynen hesaplanır', function (): void {
    $v1 = quick_cost_defaults('2026-10-30'); $v1['product'] = 'eski'; $v1['net_kg'] = 20000;
    foreach ($v1['items'] as &$i) $i['unit_price'] = ['product' => 25, 'material' => 4][$i['code']] ?? 0; unset($i);
    $r = quick_cost_calculate($v1);
    ok(!isset($r['model'])); eq($r['total'], 580000); eq($r['groups'], ['A' => 500000, 'B' => 80000, 'D' => 0]);
    $v1['model'] = 1; eq(quick_cost_calculate($v1)['total'], 580000);
    $bad = v2_example(); $bad['model'] = '2'; throws(fn() => quick_cost_calculate($bad)); // dize "2" v2 sayılmaz → v1 doğrulaması reddeder
});
t('Taslak kurtarma yalnız skaler değerleri tutar', function (): void {
    $d = v2_example(); $d['product'] = ['x']; $d['quantities']['raw_kg'] = '-5'; $d['credit']['apply'] = 'yes';
    $d['items'][] = ['code' => 'custom_k', 'label' => 'k', 'unit_price' => [1]];
    $f = quick_cost_v2_form_input($d);
    eq($f['product'], ''); eq($f['quantities']['raw_kg'], '-5'); eq($f['credit']['apply'], false); eq($f['model'], 2);
    eq(end($f['items'])['code'], 'custom_k'); eq(end($f['items'])['unit_price'], 0);
});

t('MySQL şeması değişmeden v2 değerlerini taşır (migration yok)', function (): void {
    $sql = (string)file_get_contents(__DIR__ . '/../docs/sql/quick_cost_sheets.sql');
    ok(preg_match('/`operation_type` VARCHAR\((\d+)\)/', $sql, $op) === 1 && (int)$op[1] >= strlen('uretim'));
    ok(preg_match('/`method` VARCHAR\((\d+)\)/', $sql, $m) === 1 && (int)$m[1] >= strlen('detailed'));
    ok(preg_match('/`net_kg` DECIMAL\((\d+),(\d+)\)/', $sql, $n) === 1 && (int)$n[2] === 4 && 10 ** ((int)$n[1] - 4) > QUICK_COST_NUMBER_MAX);
    ok(preg_match('/`total` DECIMAL\((\d+),(\d+)\)/', $sql, $tt) === 1 && (int)$tt[2] === 2 && 10 ** ((int)$tt[1] - 2) > QUICK_COST_TOTAL_MAX);
    ok(preg_match('/`unit_cost` DECIMAL\((\d+),(\d+)\)/', $sql, $u) === 1 && (int)$u[2] === 6);
    ok(str_contains($sql, '`document_json` LONGTEXT'));
    // En büyük v2 belgesi (40 ek kalem, en uzun metinler) form gövdesi sınırına sığar.
    $d = v2_example(); $d['product'] = str_repeat('Ğ', 150); $d['notes'] = str_repeat('Ğ', 2000);
    for ($i = 0; $i < 40; $i++) $d['items'][] = ['code' => 'custom_' . str_repeat('k', 30) . $i, 'label' => str_repeat('Ğ', 150), 'basis' => 'manual', 'unit' => 'worker_day', 'qty' => 1, 'qty2' => 1, 'unit_price' => 1];
    $payload = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    ok(strlen($payload) < 65536, 'payload ' . strlen($payload));
    eq(count(quick_cost_calculate($d)['items']), 57);
});

$saved = null;
t('v2 kaydet/aç: kolonlar ihracat KG / operasyon toplamı / ihracat KG maliyeti', function () use ($QC_DB, &$saved): void {
    $d = v2_example(); $d['credit'] = ['apply' => true, 'domestic_sold_kg' => 3000, 'domestic_price' => 8];
    $d['total'] = 1; $d['warnings'] = [['code' => 'fake']];
    $saved = quick_cost_save($QC_DB, $d);
    $row = $QC_DB->query('SELECT * FROM quick_cost_sheets WHERE id=' . $saved['id'])->fetch();
    eq((float)$row['net_kg'], 20000); eq((float)$row['total'], 713000); eq((float)$row['unit_cost'], 34.45); eq($row['product'], 'NAR');
    $doc = json_decode($row['document_json'], true); eq($doc['model'], 2);
    $found = quick_cost_find($QC_DB, $saved['id']);
    eq($found['document']['export_cost'], 689000); ok(!in_array('fake', codes($found['document']), true));
});
t('v2 düzenle/kopyala sürüm kontrolüyle', function () use ($QC_DB, &$saved): void {
    $doc = $saved['document']; $doc['operation_type'] = 'uretim';
    $u = quick_cost_save($QC_DB, $doc, $saved['id'], 1); eq($u['version'], 2); eq($u['document']['operation_type'], 'uretim');
    throws(fn() => quick_cost_save($QC_DB, $doc, $saved['id'], 1), '', QuickCostConflictException::class);
    $copy = quick_cost_save($QC_DB, $u['document']); ok($copy['id'] !== $u['id']); eq($copy['document'], $u['document']);
});
t('v2 kayıt: ihracat KG ve ürün adı zorunlu; yazma izni olmadan kaydedilmez', function () use ($QC_DB): void {
    $d = v2_example(); $d['quantities']['export_kg'] = 0; throws(fn() => quick_cost_save($QC_DB, $d), 'İhracatlık net KG');
    $d = v2_example(); $d['product'] = ' '; throws(fn() => quick_cost_save($QC_DB, $d), 'Ürün');
    $GLOBALS['QC_PERMS']['write'] = false;
    try { throws(fn() => quick_cost_save($QC_DB, v2_example()), '', QuickCostAccessException::class); }
    finally { $GLOBALS['QC_PERMS']['write'] = true; }
    $GLOBALS['QC_DEPOT'] = 'TEST DEPO B';
    try { eq(quick_cost_list($QC_DB)['total'], 0); } finally { $GLOBALS['QC_DEPOT'] = 'TEST DEPO A'; }
});
t('Eski v1 satırı v2 kodu yüklüyken de açılır ve sonucu değişmez', function () use ($QC_DB): void {
    $v1 = quick_cost_defaults('2026-10-30'); $v1['product'] = 'eski'; $v1['net_kg'] = 1000;
    foreach ($v1['items'] as &$i) $i['unit_price'] = $i['code'] === 'product' ? 10 : 0; unset($i);
    $stored = json_encode(quick_cost_calculate($v1), JSON_UNESCAPED_UNICODE);
    $QC_DB->prepare("INSERT INTO quick_cost_sheets (product,sheet_date,operation_type,method,net_kg,document_json,total,unit_cost,notes,depo,created_by,updated_by,created_at,updated_at,version)
        VALUES ('ESKİ','2026-10-30','fason','quick',1000,?,10000,10,'','TEST DEPO A',7,7,'x','x',1)")->execute([$stored]);
    $found = quick_cost_find($QC_DB, (int)$QC_DB->lastInsertId());
    ok(!quick_cost_is_v2($found['document'])); eq($found['document']['total'], 10000); eq($found['document']['unit_cost'], 10);
});

echo "\nHızlı Maliyet v2: $passed geçti, $failed başarısız. Yalnız anonim SQLite.\n";
exit($failed === 0 ? 0 : 1);
