'use strict';
// Hızlı Hesapla: JS önizleme motoru + PHP/JS eşitliği (PHP motoru `hizli_hesapla_smoke.php --json` ile çağrılır).
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const H = require('../assets/hizli-hesapla.js');
let passed = 0;
function test(label, run) { run(); passed++; console.log('OK ' + label); }
const base = () => ({ currency: 'USD', price_in_fx: true, sale_kg: '20.000', sale_price: '1,10', rate: '50,00', raw_price: '20',
    freight: '15.000', material_kg: '4', docs: '5.000', other_kg: '0,50', other_fixed: '0', waste_pct: '8', target_margin: '20' });
const near = (a, b, t, m) => assert.ok(Math.abs(a - b) <= t, m + ' ' + a + ' ≠ ' + b);

test('Zorunlu sayısal örnek', () => {
    const c = H.calculate(base()); assert.ok(c.ok); const r = c.r;
    near(r.raw_kg, 21739.1304347826, 1e-6, 'ham kg'); near(r.raw_cost, 434782.608695652, 1e-4, 'ham maliyet');
    assert.equal(r.material_cost, 80000); assert.equal(r.other_kg_cost, 10000); assert.equal(r.fixed_cost, 20000);
    assert.equal(H.fmt(r.total, 2), '544.782,61'); assert.equal(H.fmt(r.unit_cost, 4), '27,2391'); assert.equal(H.fmt(r.unit_cost_fx, 4), '0,5448');
    near(r.sale_tl, 1100000, 1e-6, 'satış'); assert.equal(H.fmt(r.profit, 2), '555.217,39'); assert.equal(H.fmt(r.margin_pct, 2), '50,47');
    assert.equal(H.fmt(r.suggested_fx, 4), '0,6810');
});
test('Türkçe sayı girişi', () => {
    assert.equal(H.parseNum('20.000').value, 20000); assert.equal(H.parseNum('1,10').value, 1.1);
    assert.equal(H.parseNum('15.000').value, 15000); assert.equal(H.parseNum('0,50').value, 0.5);
    assert.ok(H.parseNum('1.10').error, 'belirsiz biçim hata'); assert.ok(H.parseNum('abc').error); assert.ok(H.parseNum('-3').error);
    assert.ok(H.parseNum('1,23456').error); assert.equal(H.parseNum('').value, 0);
});
test('Fire 0, fire artışı, TL fiyat, EUR, zarar, hedef marj', () => {
    let c = H.calculate(Object.assign(base(), { waste_pct: '0' })); near(c.r.raw_kg, 20000, 1e-9, 'fire 0');
    assert.ok(H.calculate(Object.assign(base(), { waste_pct: '20' })).r.raw_cost > H.calculate(base()).r.raw_cost);
    c = H.calculate(Object.assign(base(), { price_in_fx: false, sale_price: '55' })); near(c.r.sale_tl, 1100000, 1e-6, 'TL'); near(c.r.price_fx, 1.1, 1e-12, 'fx');
    c = H.calculate(Object.assign(base(), { currency: 'EUR', rate: '55,5' })); near(c.r.sale_tl, 20000 * 1.1 * 55.5, 1e-6, 'EUR');
    assert.ok(H.view(c.n, c.r, 'EUR', '').total_fx.endsWith(' EUR'));
    c = H.calculate(Object.assign(base(), { sale_price: '0,40' })); assert.ok(c.r.profit < 0 && H.view(c.n, c.r, 'USD', '').loss);
    near(H.calculate(Object.assign(base(), { target_margin: '30' })).r.suggested_tl, H.calculate(base()).r.unit_cost / 0.7, 1e-9, 'marj');
    const e = H.calculate(Object.assign(base(), { target_margin: '' })); assert.equal(e.r.suggested_fx, null);
    assert.equal(H.view(e.n, e.r, 'USD', '').suggested, 'Hedef marj girin');
});
test('Geçersiz giriş sonuç üretmez', () => {
    for (const bad of [{ rate: '' }, { rate: '0' }, { sale_kg: '0' }, { sale_kg: '' }, { waste_pct: '100' }, { target_margin: '100' }, { freight: 'x' }, { freight: '-1' }]) {
        const c = H.calculate(Object.assign(base(), bad)); assert.equal(c.ok, false, JSON.stringify(bad)); assert.ok(c.errors.length);
    }
});
test('Paylaşım metni ekrandaki değerlerle aynı', () => {
    const c = H.calculate(base()), v = H.view(c.n, c.r, 'USD', 'NAR TEKLİF');
    for (const k of ['total', 'total_fx', 'unit', 'unit_fx', 'sale', 'sale_fx', 'profit', 'profit_fx', 'margin', 'entered', 'suggested']) assert.ok(v.share.includes(v[k]), k);
    assert.ok(v.share.startsWith('HIZLI HESAPLA — NAR TEKLİF')); assert.ok(v.share.includes('1 USD = 50,00 TL'));
});
test('PHP/JS eşitliği (rastgele 300 senaryo)', () => {
    let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
    const pick = (max, dec) => { const v = Math.round(rnd() * max * Math.pow(10, dec)) / Math.pow(10, dec); return v; };
    const cases = [], inputs = [];
    for (let i = 0; i < 300; i++) {
        const n = { model: 3, product: 'T', sheet_date: '2026-11-01', sale_kg: Math.max(1, pick(50000, 0)), sale_price: pick(5, 4), currency: i % 2 ? 'EUR' : 'USD',
            rate: Math.max(0.5, pick(80, 4)), price_in_fx: rnd() > 0.3, raw_price: pick(60, 4), freight: pick(60000, 2), material_kg: pick(10, 4),
            docs: pick(9000, 2), other_kg: pick(3, 4), other_fixed: pick(9000, 2), waste_pct: pick(60, 2), target_margin: rnd() > 0.2 ? pick(70, 2) : null };
        cases.push(n);
        inputs.push(H.calculate({ currency: n.currency, price_in_fx: n.price_in_fx, sale_kg: String(n.sale_kg).replace('.', ','), sale_price: String(n.sale_price).replace('.', ','),
            rate: String(n.rate).replace('.', ','), raw_price: String(n.raw_price).replace('.', ','), freight: String(n.freight).replace('.', ','), material_kg: String(n.material_kg).replace('.', ','),
            docs: String(n.docs).replace('.', ','), other_kg: String(n.other_kg).replace('.', ','), other_fixed: String(n.other_fixed).replace('.', ','),
            waste_pct: String(n.waste_pct).replace('.', ','), target_margin: n.target_margin === null ? '' : String(n.target_margin).replace('.', ',') }));
    }
    const php = JSON.parse(execFileSync('php', [path.join(__dirname, 'hizli_hesapla_smoke.php'), '--json'], { input: JSON.stringify(cases), maxBuffer: 1 << 26 }).toString());
    let compared = 0;
    php.forEach((p, i) => {
        if (p.error) { assert.equal(inputs[i].ok, false, 'PHP hata, JS ok: ' + JSON.stringify(cases[i])); return; }
        assert.equal(inputs[i].ok, true, 'JS hata: ' + inputs[i].errors + ' ' + JSON.stringify(cases[i]));
        for (const k of Object.keys(inputs[i].r)) {
            const a = inputs[i].r[k], b = p.ok.results[k];
            if (a === null || b === null) assert.equal(a, b, k); else near(a, b, Math.max(1e-9, Math.abs(b) * 1e-12), k + ' #' + i);
        }
        compared++;
    });
    assert.ok(compared > 250, 'karşılaştırılan: ' + compared);
});
console.log('Toplam ' + passed + ' test geçti.');
