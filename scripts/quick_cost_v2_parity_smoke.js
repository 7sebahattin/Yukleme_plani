'use strict';
// Hızlı Maliyet v2: JS önizleme motoru ile PHP otorite motoru aynı finansal sonucu verir mi?
// Ağ/DB yok: PHP motoru `php scripts/quick_cost_v2_smoke.php --json` ile stdin üzerinden çağrılır.
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const v2 = require('../assets/hizli-maliyet-v2.js');
let passed = 0;
function test(label, run) { run(); passed++; console.log('OK ' + label); }

function base() {
    const items = Object.entries(v2.catalog).map(([code, d]) => ({ code, basis: d.basis, unit: d.unit, qty: 0, qty2: 0, unit_price: 0 }));
    return { model: 2, product: 'nar', sheet_date: '2026-10-31', operation_type: 'fason', method: 'quick', notes: '', yield_mode: 'actual', target_yield: 0,
        quantities: { raw_kg: 25000, processed_kg: 25000, export_kg: 20000, domestic_kg: 3000, industry_kg: 0, waste_kg: 2000 },
        fason_includes: { labor: false, material: false },
        credit: { apply: false, domestic_sold_kg: 0, domestic_price: 0, industry_sold_kg: 0, industry_price: 0 },
        sale: { price: 0, currency: 'TL', rate: 1, target_margin: null }, items };
}
function example() {
    const d = base(), p = { raw_purchase: 20, sorting_labor: 2, material: 4, field_transport: 15000, export_freight: 60000, customs: 5000, official_analysis: 3000 };
    d.items.forEach(r => { r.unit_price = p[r.code] || 0; }); return d;
}
function setRow(d, code, c) { d.items = d.items.map(r => r.code === code ? Object.assign({}, r, c) : r); return d; }
function row(r, code) { return r.items.find(x => x.code === code); }

test('Zorunlu senaryo JS: 713.000 / %80 / 35,65; kredi ile 689.000 / 34,45', () => {
    const r = v2.calculate(example());
    assert.equal(r.total, 713000); assert.equal(r.derived.yield_pct, 80); assert.equal(r.unit_cost, 35.65);
    const d = example(); d.credit = { apply: true, domestic_sold_kg: 3000, domestic_price: 8, industry_sold_kg: 0, industry_price: 0 };
    const c = v2.calculate(d); assert.equal(c.export_cost, 689000); assert.equal(c.unit_cost, 34.45);
});
test('Taban değişiminde eski miktar ve farklı birimin fiyatı taşınmaz', () => {
    let d = setRow(example(), 'loading', { basis: 'manual', unit: 'kg', qty: 20000, unit_price: 2 });
    d = v2.setBasis(d, 'loading', 'manual', 'pallet');
    assert.deepEqual([row(d, 'loading').qty, row(d, 'loading').unit_price], [0, 0]);
    d = v2.setBasis(example(), 'sorting_labor', 'raw_kg', 'kg');
    assert.equal(row(d, 'sorting_labor').unit_price, 2); // aynı birim (TL/kg): fiyat korunur
    assert.equal(row(v2.calculate(d), 'sorting_labor').amount, 50000);
    d = v2.setBasis(example(), 'sorting_labor', 'processed_kg', 'ton');
    assert.equal(row(d, 'sorting_labor').unit_price, 0); // kg → ton: 2 TL/kg asla 2 TL/ton olmaz
});
test('Randıman yöntemi geçişi diğer girdiyi mevcut sonuçtan doldurur, veri kaybı yok', () => {
    let d = v2.setYieldMode(example(), 'target');
    assert.equal(d.target_yield, 80); assert.equal(v2.calculate(d).derived.raw_kg, 25000);
    d.quantities = Object.assign(d.quantities, { export_kg: 16000, processed_kg: null, domestic_kg: 0, waste_kg: 0 }); d = v2.setYieldMode(d, 'actual');
    assert.equal(d.quantities.raw_kg, 20000); assert.equal(v2.calculate(d).derived.yield_pct, 80);
});
test('Geçersiz durumda yöntem geçişi mevcut girdiyi değiştirmez', () => {
    const d = example(); d.quantities.waste_kg = 9999;
    const t = v2.setYieldMode(d, 'target'); assert.equal(t.target_yield, 0); assert.equal(t.yield_mode, 'target');
});
test('Hızlı görünüm: tutarlı satır hiçbir zaman gizlenmez', () => {
    assert.equal(v2.rowVisible('harvest', 'quick', 'fason', 0), false);
    assert.equal(v2.rowVisible('harvest', 'quick', 'fason', 10), true);
    assert.equal(v2.rowVisible('fason_service', 'quick', 'uretim', 0), false);
    assert.equal(v2.rowVisible('fason_service', 'quick', 'uretim', 5), true);
    assert.equal(v2.rowVisible('casus', 'detailed', 'uretim', 0), true);
});
test('Açıklamalar rakam içermez', () => {
    for (const [b, u] of [['raw_kg', 'kg'], ['processed_kg', 'ton'], ['manual', 'worker_day'], ['manual', 'pallet'], ['fixed', 'fixed'], ['manual', 'ton_day']]) {
        assert.equal(/\d/.test(v2.hintText({ basis: b, unit: u }).replace('1.000', '')), false);
    }
});

// --- PHP ↔ JS paritesi ---
function lcg(seed) { let s = seed >>> 0; return () => (s = (Math.imul(s, 1664525) + 1013904223) >>> 0) / 4294967296; }
const rnd = lcg(20261031);
function pick(a) { return a[Math.floor(rnd() * a.length)]; }
function num(max, dec) { const f = Math.pow(10, dec); return Math.round(rnd() * max * f) / f; }
function turkish(n) { return n.toLocaleString('tr-TR', { maximumFractionDigits: 4 }); }
const units = Object.keys(v2.units).filter(u => u !== 'fixed');
const cases = [];
cases.push(example());
{ const d = example(); d.credit = { apply: true, domestic_sold_kg: 3000, domestic_price: 8, industry_sold_kg: 0, industry_price: 0 }; cases.push(d); }
{ const d = example(); d.yield_mode = 'target'; d.target_yield = '75,5'; d.quantities.processed_kg = null; d.quantities.domestic_kg = 0; d.quantities.waste_kg = 0; cases.push(d); }
{ const d = example(); d.fason_includes = { labor: true, material: true }; setRow(d, 'fason_service', { unit: 'ton', unit_price: '1.234,5' }); cases.push(d); }
{ const d = example(); d.operation_type = 'uretim'; setRow(d, 'fason_service', { unit_price: 1 }); cases.push(d); }
for (const [cur, rate, price, m] of [['TL', 1, 40, 20], ['USD', '34,25', '1,1', ''], ['EUR', '36,8', '0,95', 10], ['EUR', 0, 1, null], ['TL', 1, 1, 100]]) {
    const d = example(); d.sale = { price, currency: cur, rate, target_margin: m }; cases.push(d);
}
{ const d = example(); d.quantities.waste_kg = 2001; cases.push(d); }
{ const d = example(); d.items.push({ code: 'custom_x', label: 'ilaçlama', basis: 'manual', unit: 'worker_day', qty: 3, qty2: '1,5', unit_price: 900 }); cases.push(d); }
for (let i = 0; i < 160; i++) {
    const d = base(), raw = num(800000, rnd() < .5 ? 0 : 4), exp = Math.min(raw, num(raw, rnd() < .5 ? 0 : 2));
    d.operation_type = pick(['fason', 'uretim']); d.method = pick(['quick', 'detailed']);
    d.yield_mode = rnd() < .25 ? 'target' : 'actual';
    d.target_yield = d.yield_mode === 'target' ? pick([80, '72,25', 55, '99,99', 100, 0, '33,33']) : 0;
    const rest = Math.max(0, raw - exp);
    d.quantities = { raw_kg: rnd() < .3 ? turkish(raw) : raw, processed_kg: rnd() < .4 ? null : Math.max(exp, Math.min(raw, num(raw, 1))), export_kg: exp,
        domestic_kg: num(rest / 3, 1), industry_kg: num(rest / 4, 0), waste_kg: num(rest / 4, 2) };
    d.fason_includes = { labor: rnd() < .3, material: rnd() < .3 };
    d.items.forEach(r => {
        r.unit_price = rnd() < .4 ? 0 : (r.basis === 'fixed' ? num(90000, 2) : num(60, 4));
        if (rnd() < .3) {
            const kind = pick(['raw_kg', 'processed_kg', 'export_kg', 'manual', 'fixed']);
            r.basis = kind; r.unit = kind === 'fixed' ? 'fixed' : (kind === 'manual' ? pick(units) : pick(['kg', 'ton']));
            r.qty = num(500, rnd() < .5 ? 0 : 4); r.qty2 = num(30, rnd() < .5 ? 0 : 1);
            if (r.unit === 'ton') r.unit_price = num(5000, 2);
        }
        if (rnd() < .2) r.unit_price = turkish(Number(r.unit_price));
    });
    if (rnd() < .5) d.credit = { apply: rnd() < .6, domestic_sold_kg: num(Number(d.quantities.domestic_kg), 0), domestic_price: num(15, 2), industry_sold_kg: num(d.quantities.industry_kg, 0), industry_price: num(5, 2) };
    if (rnd() < .6) d.sale = { price: num(3, 4) || 1, currency: pick(['TL', 'USD', 'EUR']), rate: pick([1, '34,25', '36,8', '0,5', 0]), target_margin: pick([null, '', 15, '22,5', 0]) };
    cases.push(d);
}
const php = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'quick_cost_v2_smoke.php'), '--json'], { input: JSON.stringify(cases), encoding: 'utf8', maxBuffer: 64 << 20 }));
test('PHP ve JS ' + cases.length + ' senaryoda aynı sonuç / aynı ret kararı', () => {
    let okCount = 0, errCount = 0;
    cases.forEach((c, i) => {
        let js = null, jsErr = null;
        try { js = v2.calculate(JSON.parse(JSON.stringify(c))); } catch (e) { jsErr = e.message; }
        const p = php[i];
        assert.equal(!!jsErr, !!p.error, 'case ' + i + ' karar farklı: JS=' + jsErr + ' PHP=' + p.error);
        if (jsErr) { errCount++; return; }
        const pick = r => ({ total: r.total, export_cost: r.export_cost, unit_cost: r.unit_cost, unit_cost_prudent: r.unit_cost_prudent, excluded_total: r.excluded_total,
            credit_total: r.credit_total, credit_applied: r.credit_applied, groups: r.groups, derived: r.derived, quantities: r.quantities, sale: r.sale,
            sale_result: r.sale_result, target_yield: r.target_yield, product: r.product,
            items: r.items.map(x => [x.code, x.basis, x.unit, x.qty, x.qty2, x.unit_price, x.amount, x.excluded]),
            warnings: r.warnings.map(w => w.code + ':' + w.text) });
        assert.deepEqual(pick(js), pick(p.ok), 'case ' + i);
        okCount++;
    });
    assert.ok(okCount > 60 && errCount > 5, 'kapsam: ' + okCount + ' sonuç, ' + errCount + ' ret');
    console.log('   (' + okCount + ' hesaplanan, ' + errCount + ' reddedilen senaryo)');
});
console.log('PASS ' + passed + ' Hızlı Maliyet v2 JS/PHP parite kontrolü');
