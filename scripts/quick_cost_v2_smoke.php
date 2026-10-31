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
    $prices = ['raw_purchase' => 20, 'packing_labor' => 2, 'material' => 4, 'field_transport' => 15000,
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
    eq(row($r, 'raw_purchase')['amount'], 500000); eq(row($r, 'packing_labor')['amount'], 50000); eq(row($r, 'material')['amount'], 80000);
    eq($r['groups'], ['raw' => 500000, 'supply' => 15000, 'packing' => 50000, 'material' => 80000, 'export' => 68000, 'other' => 0]);
    eq(array_column($r['items'], 'code'), ['raw_purchase', 'harvest', 'field_transport', 'packing_labor', 'material', 'export_freight', 'customs', 'official_analysis', 'private_analysis', 'dts_taxi', 'overhead', 'casus']);
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
    throws(fn() => quick_cost_calculate($d), 'Çıkma satılan KG');
    $d = v2_example(); $d['credit'] = ['apply' => false, 'industry_sold_kg' => 1, 'industry_price' => 1];
    throws(fn() => quick_cost_calculate($d), 'Suluk satılan KG');
    $d = v2_example(); $d['credit'] = ['apply' => true, 'domestic_sold_kg' => 3000, 'domestic_price' => 1000];
    throws(fn() => quick_cost_calculate($d), 'aşamaz');
});
t('Tabanlar: ham alış ham KG, bağlanan kalem işlenen KG, malzeme/paketleme ihracat KG', function (): void {
    $d = v2_example(); $d['quantities']['processed_kg'] = 24000;
    $d = setRow($d, 'harvest', ['basis' => 'processed_kg', 'unit' => 'kg', 'unit_price' => 2]); // yalnız kullanıcı bağlarsa işlenen KG kullanılır
    $r = quick_cost_calculate($d);
    eq(row($r, 'raw_purchase')['qty'], 25000); eq(row($r, 'raw_purchase')['amount'], 500000);
    eq(row($r, 'harvest')['qty'], 24000); eq(row($r, 'harvest')['amount'], 48000);
    eq(row($r, 'packing_labor')['qty'], 25000); eq(row($r, 'packing_labor')['amount'], 50000);
    eq(row($r, 'material')['qty'], 20000); eq($r['total'], 761000);
});
t('İşlenen KG boşsa ham KG varsayılır ve açıkça belirtilir', function (): void {
    $d = v2_example(); $d['quantities']['processed_kg'] = null;
    $r = quick_cost_calculate($d);
    ok($r['derived']['processed_assumed']); eq($r['derived']['processed_kg'], 25000); eq($r['quantities']['processed_kg'], null);
    ok(in_array('processed_assumed', codes($r), true));
    $d['quantities']['processed_kg'] = ''; ok(quick_cost_calculate($d)['derived']['processed_assumed']);
});
t('Verimlilik ve ters verimlilik (hedef 20.000 KG, %80 → 25.000 ham KG)', function (): void {
    $d = v2_example(); $d['yield_mode'] = 'target'; $d['target_yield'] = 80; $d['quantities']['raw_kg'] = 1; // yok sayılır
    $r = quick_cost_calculate($d);
    eq($r['derived']['raw_kg'], 25000); eq($r['quantities']['raw_kg'], 25000); eq($r['total'], 713000); eq($r['derived']['yield_pct'], 80);
    $d['target_yield'] = '75,5'; $d['quantities'] = ['raw_kg' => 0, 'processed_kg' => null, 'export_kg' => 20000, 'domestic_kg' => 0, 'industry_kg' => 0, 'waste_kg' => 0];
    eq(quick_cost_calculate($d)['derived']['raw_kg'], 26490.0662); // 20000 / 0,755
    $d['target_yield'] = 0; throws(fn() => quick_cost_calculate($d), 'verimliliği');
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
    eq($r['quantities']['domestic_kg'], 3000); eq($r['quantities']['waste_kg'], 2000); // çıkma çöpe karışmaz
});
t('Ton, palet, koli, araç ve iki çarpanlı birimler', function (): void {
    $d = setRow(v2_example(), 'overhead', ['basis' => 'raw_kg', 'unit' => 'ton', 'unit_price' => 1500]);
    eq(row(quick_cost_calculate($d), 'overhead')['amount'], 37500); // 25 ton × 1.500
    $d = setRow(v2_example(), 'overhead', ['basis' => 'raw_kg', 'unit' => 'ton', 'unit_price' => '0,0005']);
    eq(row(quick_cost_calculate($d), 'overhead')['amount'], 0.01); // 25 ton × 0,0005 = 0,0125 → 0,01
    eq(quick_cost_v2_ton_cents(1, 5), 1); eq(quick_cost_v2_ton_cents(1, 4.9999), 0); eq(quick_cost_v2_ton_cents(999999.9999, 999999.9999), 99999999980);
    $d = setRow(v2_example(), 'overhead', ['basis' => 'manual', 'unit' => 'pallet', 'qty' => 26, 'unit_price' => 380]);
    eq(row(quick_cost_calculate($d), 'overhead')['amount'], 9880);
    $d = setRow(v2_example(), 'packing_labor', ['basis' => 'manual', 'unit' => 'worker_day', 'qty' => 12, 'qty2' => '2,5', 'unit_price' => 1200]);
    eq(row(quick_cost_calculate($d), 'packing_labor')['amount'], 36000);
    $d = setRow(v2_example(), 'overhead', ['basis' => 'manual', 'unit' => 'pallet_day', 'qty' => 26, 'qty2' => 3, 'unit_price' => 50]);
    eq(row(quick_cost_calculate($d), 'overhead')['amount'], 3900);
    $d = setRow(v2_example(), 'overhead', ['basis' => 'manual', 'unit' => 'ton_day', 'qty' => '0,3333', 'qty2' => '0,3333', 'unit_price' => 10000]);
    eq(row(quick_cost_calculate($d), 'overhead')['amount'], 1111); // 0,11108889 → 0,1111 × 10.000
    $d = setRow(v2_example(), 'overhead', ['basis' => 'manual', 'unit' => 'ton_day', 'qty' => 1000000, 'qty2' => 2, 'unit_price' => 1]);
    throws(fn() => quick_cost_calculate($d), 'çarpımı');
    $d = setRow(v2_example(), 'packing_labor', ['basis' => 'manual', 'unit' => 'hour', 'qty' => 480, 'unit_price' => 150]);
    eq(row(quick_cost_calculate($d), 'packing_labor')['amount'], 72000);
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
function mat_example(): array {
    $d = v2_example(); $d['material_detail']['use'] = true; $d['material_detail']['pallets'] = 26;
    foreach ($d['material_detail']['lines'] as &$l) {
        if ($l['code'] === 'mat_pallet') { $l['qty'] = 1; $l['price'] = 300; }
        if ($l['code'] === 'mat_corner') { $l['qty'] = 4; $l['price'] = '12,5'; }
        if ($l['code'] === 'mat_crate') { $l['qty'] = 160; $l['price'] = 9; }
    }
    unset($l);
    return $d;
}
t('Malzeme palet detayı: palet başı maliyet × palet sayısı → Malzeme tutarı', function (): void {
    $r = quick_cost_calculate(mat_example()); // palet başı 300 + 50 + 1.440 = 1.790 TL
    eq($r['material_detail']['per_pallet'], 1790); eq($r['material_detail']['total'], 46540);
    eq(row($r, 'material')['amount'], 46540); ok(row($r, 'material')['from_detail']);
    eq($r['groups']['material'], 46540); eq($r['total'], 713000 - 80000 + 46540);
    $lines = array_column($r['material_detail']['lines'], 'per_pallet', 'code');
    eq($lines['mat_pallet'], 300); eq($lines['mat_corner'], 50); eq($lines['mat_crate'], 1440); eq($lines['mat_hat'], 0);
    eq(count($r['material_detail']['lines']), 9);
    eq(quick_cost_calculate($r), $r); // idempotent
});
t('Malzeme detayı seçili değilse satır fiyatı geçerlidir; detay girişi toplama karışmaz', function (): void {
    $d = mat_example(); $d['material_detail']['use'] = false;
    $r = quick_cost_calculate($d);
    eq(row($r, 'material')['amount'], 80000); ok(!row($r, 'material')['from_detail']); eq($r['total'], 713000);
    eq($r['material_detail']['total'], 46540); // hesap görünür ama toplama girmez
});
t('Malzeme detayı: ek satır, kuruş yuvarlama, sınırlar, uyarı', function (): void {
    $d = mat_example(); $d['material_detail']['lines'][] = ['code' => 'mat_custom_etiket', 'label' => 'etiket', 'qty' => '0,5', 'price' => '0,333'];
    $r = quick_cost_calculate($d);
    $last = end($r['material_detail']['lines']); eq($last['label'], 'ETİKET'); eq($last['per_pallet'], 0.17); // 0,1665 → 0,17
    eq($r['material_detail']['per_pallet'], 1790.17); eq($r['material_detail']['total'], 46544.42); // 1790,17 × 26
    $d2 = mat_example(); $d2['material_detail']['lines'][0]['qty'] = 1000000; $d2['material_detail']['lines'][0]['price'] = 1000;
    throws(fn() => quick_cost_calculate($d2), 'sınırını aşıyor');
    $d2 = mat_example(); $d2['material_detail']['lines'][] = ['code' => 'mat_custom_bos', 'label' => '', 'qty' => 0, 'price' => 0];
    $r2 = quick_cost_calculate($d2); eq(count($r2['material_detail']['lines']), 9); eq($r2['material_detail']['total'], 46540); // yeni boş satır hesabı bozmaz
    $d2 = mat_example(); $d2['material_detail']['lines'][] = ['code' => 'mat_custom_x', 'label' => ' ', 'qty' => 1, 'price' => 1];
    throws(fn() => quick_cost_calculate($d2), 'adı gerekli');
    $d2 = mat_example(); $d2['material_detail']['lines'][] = ['code' => 'mat_pallet', 'qty' => 1, 'price' => 1];
    throws(fn() => quick_cost_calculate($d2), 'tekrarlanan');
    $d2 = mat_example(); $d2['material_detail']['lines'][] = ['code' => 'mat_bogus', 'qty' => 1, 'price' => 1];
    throws(fn() => quick_cost_calculate($d2), 'bilinmeyen');
    $d2 = mat_example(); for ($i = 0; $i < 21; $i++) $d2['material_detail']['lines'][] = ['code' => 'mat_custom_' . $i, 'label' => 'x', 'qty' => 0, 'price' => 0];
    throws(fn() => quick_cost_calculate($d2), 'yirmi');
    $d2 = mat_example(); $d2['material_detail']['pallets'] = '-1'; throws(fn() => quick_cost_calculate($d2));
    $d2 = mat_example(); $d2['material_detail']['use'] = 'evet'; throws(fn() => quick_cost_calculate($d2), 'evet/hayır');
    $d2 = v2_example(); $d2['material_detail']['use'] = true; // palet sayısı girilmedi
    $r2 = quick_cost_calculate($d2); eq(row($r2, 'material')['amount'], 0); ok(in_array('material_detail_empty', codes($r2), true));
    $d3 = v2_example(); unset($d3['material_detail']); eq(quick_cost_calculate($d3)['material_detail']['use'], false); // eski belge
});
t('Eski (v472) belge: kaldırılan kalemler korunur, toplam DEĞİŞMEZ, fason dahil seçimi tutarsız bırakılmaz', function (): void {
    $old = v2_example();
    $old['items'] = array_values(array_filter($old['items'], fn($i) => $i['code'] !== 'packing_labor'));
    foreach ([['sorting_labor', 'processed_kg', 'kg', 2], ['grading', 'processed_kg', 'kg', 0], ['packing_labor', 'export_kg', 'kg', 1],
        ['fason_service', 'processed_kg', 'kg', 3], ['cold_storage', 'fixed', 'fixed', 0], ['loading', 'fixed', 'fixed', 1000], ['factory_cost', 'fixed', 'fixed', 700]] as [$c, $b, $u, $pr]) {
        $old['items'][] = ['code' => $c, 'basis' => $b, 'unit' => $u, 'qty' => 0, 'qty2' => 0, 'unit_price' => $pr];
    }
    unset($old['material_detail']); $old['fason_includes'] = ['labor' => false, 'material' => false];
    $r = quick_cost_calculate($old);
    // 713.000 − 50.000 (örnek paketleme) ... örnekte paketleme yok sayıldı: ham 500.000 + malzeme 80.000 + 68.000 + 15.000
    eq($r['total'], 663000 + 50000 + 20000 + 75000 + 1000 + 700);
    $codes = array_column($r['items'], 'code');
    ok(in_array('custom_eski_ayiklama', $codes, true) && in_array('custom_eski_fason', $codes, true) && in_array('custom_eski_yukleme', $codes, true));
    ok(!in_array('custom_eski_boylama', $codes, true) && !in_array('custom_eski_soguk_hava', $codes, true)); // sıfır tutarlılar düşer
    ok(in_array('custom_eski_fabrika', $codes, true) && !in_array('factory_cost', $codes, true) && !in_array('sorting_labor', $codes, true));
    eq(row($r, 'custom_eski_fabrika')['label'], 'FABRİKA GİDERİ (ESKİ)'); eq(row($r, 'custom_eski_fabrika')['amount'], 700);
    eq(row($r, 'custom_eski_ayiklama')['label'], 'AYIKLAMA İŞÇİLİĞİ (ESKİ)'); eq(row($r, 'custom_eski_ayiklama')['amount'], 50000);
    eq(quick_cost_calculate($r), $r);
    $old['fason_includes'] = ['labor' => true, 'material' => true]; $r = quick_cost_calculate($old);
    eq($r['total'], 663000 - 80000 + 75000 + 1000 + 700); // işçilik (ayıklama/paketleme) ve malzeme eskiden toplamda yoktu
    eq(row($r, 'material')['amount'], 0);
    $old['operation_type'] = 'uretim'; $r = quick_cost_calculate($old); // üretimde seçim uygulanmazdı
    eq($r['total'], 663000 + 50000 + 20000 + 75000 + 1000 + 700);
});
t('Eski kayıt 40 ek kalemle doluysa taşınan "(ESKİ)" kalemleri sınırı aşmaz; 41. kullanıcı kalemi yine reddedilir', function (): void {
    $old = v2_example();
    $old['items'][] = ['code' => 'loading', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 1000];
    $old['items'][] = ['code' => 'fason_service', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 500];
    for ($i = 0; $i < 40; $i++) $old['items'][] = ['code' => 'custom_k' . $i, 'label' => 'k' . $i, 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 1];
    $r = quick_cost_calculate($old);
    eq($r['total'], 713000 + 1000 + 500 + 40); eq(count($r['items']), 12 + 40 + 2);
    eq(quick_cost_calculate($r), $r); // taşınmış belge tekrar hesaplanabilir (kayıt açılır, düzenlenir, kopyalanır)
    $f = quick_cost_v2_form_input($r); eq(count($f['items']), 12 + 40 + 2); // 422 sonrası taslak da kırpılmaz
    $r['items'][] = ['code' => 'custom_k41', 'label' => 'fazla', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 1];
    throws(fn() => quick_cost_calculate($r), 'kırk');
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
    $d['items'][count($d['items']) - 1]['label'] = ' '; throws(fn() => quick_cost_calculate($d), 'adı');
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
    eq(count(quick_cost_calculate($d)['items']), 52);
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
