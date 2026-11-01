'use strict';
// Hızlı Hesapla: JS önizleme motoru + PHP/JS eşitliği (PHP motoru `hizli_hesapla_smoke.php --json` ile çağrılır).
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const H = require('../assets/hizli-hesapla.js');
let passed = 0;
function test(label, run) { run(); passed++; console.log('OK ' + label); }
const base = () => ({ model: 4, currency: 'USD', price_in_fx: true, sale_kg: '20.000', sale_price: '1,10', rate: '50,00', raw_price: '20',
    freight: '15.000', material_kg: '4', docs: '5.000', other_kg: '0,50', other_kg_basis: 'net', other_fixed: '0', fire_pct: '20', target_margin: '20' });
const legacy = () => { const b = base(); delete b.fire_pct; delete b.other_kg_basis; b.model = 3; b.waste_pct = '8'; return b; };
const near = (a, b, t, m) => assert.ok(Math.abs(a - b) <= t, m + ' ' + a + ' ≠ ' + b);

test('Zorunlu sayısal örnek (fire %20 → ihracata kalan %80)', () => {
    const c = H.calculate(base()); assert.ok(c.ok); const r = c.r;
    near(r.raw_kg, 25000, 1e-9, 'ham kg'); near(r.raw_cost, 500000, 1e-6, 'ham maliyet');
    assert.equal(r.material_cost, 80000); assert.equal(r.other_kg_cost, 10000); assert.equal(r.fixed_cost, 20000);
    assert.equal(H.fmt(r.total, 2), '610.000,00'); assert.equal(H.fmt(r.unit_cost, 4), '30,5000'); assert.equal(H.fmt(r.unit_cost_fx, 4), '0,6100');
    near(r.sale_tl, 1100000, 1e-6, 'satış'); assert.equal(H.fmt(r.profit, 2), '490.000,00'); assert.equal(H.fmt(r.margin_pct, 2), '44,55');
    assert.equal(H.fmt(r.breakeven_fx, 4), '0,6100'); assert.equal(H.fmt(r.suggested_fx, 4), '0,7625');
});
test('Türkçe sayı girişi', () => {
    assert.equal(H.parseNum('20.000').value, 20000); assert.equal(H.parseNum('1,10').value, 1.1);
    assert.equal(H.parseNum('15.000').value, 15000); assert.equal(H.parseNum('0,50').value, 0.5);
    assert.ok(H.parseNum('1.10').error, 'belirsiz biçim hata'); assert.ok(H.parseNum('abc').error); assert.ok(H.parseNum('-3').error);
    assert.ok(H.parseNum('1,23456').error); assert.equal(H.parseNum('').value, 0);
    assert.equal(H.parseNum('1,09734512', 'x', 8).value, 1.09734512); assert.ok(H.parseNum('1,097345123', 'x', 8).error);
});
test('Fire: %0 → ham = net, arttıkça ham mal artar, geçersizler reddedilir', () => {
    near(H.calculate(Object.assign(base(), { fire_pct: '0' })).r.raw_kg, 20000, 1e-9, 'ham = net');
    const a = H.calculate(base()), b = H.calculate(Object.assign(base(), { fire_pct: '40' }));
    assert.ok(b.r.raw_kg > a.r.raw_kg && b.r.raw_cost > a.r.raw_cost);
    for (const y of ['', '100', '101', '-5', 'abc']) assert.equal(H.calculate(Object.assign(base(), { fire_pct: y })).ok, false, 'fire ' + y);
    assert.ok(H.calculate(Object.assign(base(), { fire_pct: '' })).errors.join(' ').includes('Fire'));
    assert.equal(H.calculate(base()).n.yield_pct, 80); assert.equal(H.calculate(Object.assign(base(), { fire_pct: '12,5' })).n.yield_pct, 87.5);
});
test('Diğer masraf tabanı Net / Ham KG', () => {
    const n = H.calculate(Object.assign(base(), { other_kg: '2', other_kg_basis: 'net' })).r, h = H.calculate(Object.assign(base(), { other_kg: '2', other_kg_basis: 'raw' })).r;
    assert.equal(n.other_kg_cost, 40000); assert.equal(h.other_kg_cost, 50000); near(h.total - n.total, 10000, 1e-6, 'fark');
});
test('Sabit giderler bir kez; TL fiyat; EUR; zarar; hedef marj', () => {
    near(H.calculate(base()).r.total - H.calculate(Object.assign(base(), { freight: '0', docs: '0' })).r.total, 20000, 1e-6, 'sabit');
    let c = H.calculate(Object.assign(base(), { price_in_fx: false, sale_price: '55' })); near(c.r.sale_tl, 1100000, 1e-6, 'TL'); near(c.r.price_fx, 1.1, 1e-12, 'fx');
    c = H.calculate(Object.assign(base(), { currency: 'EUR', rate: '55,5' })); near(c.r.sale_tl, 20000 * 1.1 * 55.5, 1e-6, 'EUR');
    assert.ok(H.view(c.n, c.r, 'EUR', '').total_fx.endsWith(' EUR'));
    c = H.calculate(Object.assign(base(), { sale_price: '0,40' })); assert.ok(c.r.profit < 0 && H.view(c.n, c.r, 'USD', '').loss);
    near(H.calculate(Object.assign(base(), { target_margin: '30' })).r.suggested_tl, H.calculate(base()).r.unit_cost / 0.7, 1e-9, 'marj');
    const e = H.calculate(Object.assign(base(), { target_margin: '' })); assert.equal(e.r.suggested_fx, null);
    assert.equal(H.view(e.n, e.r, 'USD', '').suggested, 'Hedef marj girin');
});
test('Geçersiz giriş sonuç üretmez', () => {
    for (const bad of [{ rate: '' }, { rate: '0' }, { sale_kg: '0' }, { sale_kg: '' }, { target_margin: '100' }, { freight: 'x' }, { freight: '-1' }]) {
        const c = H.calculate(Object.assign(base(), bad)); assert.equal(c.ok, false, JSON.stringify(bad)); assert.ok(c.errors.length);
    }
});
test('Eski model 3 (fire) kendi matematiğiyle korunur', () => {
    const c = H.calculate(legacy()); assert.ok(c.ok); assert.equal(H.fmt(c.r.total, 2), '544.782,61'); near(c.r.raw_kg, 20000 / 0.92, 1e-6, 'ham');
    assert.equal(H.calculate(Object.assign(legacy(), { waste_pct: '100' })).ok, false);
    assert.ok(!H.view(c.n, c.r, 'USD', '').share.includes('randıman'), 'eski paylaşımda randıman satırı yok');
});
test('Paylaşım metni ekrandaki değerlerle aynı + randıman/ham mal/başabaş', () => {
    const c = H.calculate(base()), v = H.view(c.n, c.r, 'USD', 'NAR TEKLİF');
    for (const k of ['total', 'total_fx', 'unit', 'unit_fx', 'sale', 'sale_fx', 'profit', 'profit_fx', 'margin', 'entered', 'suggested', 'breakeven']) assert.ok(v.share.includes(v[k]), k);
    assert.ok(v.share.startsWith('HIZLI HESAPLA — NAR TEKLİF')); assert.ok(v.share.includes('1 USD = 50,00 TL'));
    assert.ok(v.share.includes('Fire oranı: %20,00 (ihracata kalan %80,00)') && v.share.includes('Gerekli ham mal: 25.000 kg') && v.share.includes('yan ürün geliri düşülmemiş'));
});
test('PHP/JS eşitliği (rastgele 300 senaryo, model 4 + model 3)', () => {
    let seed = 12345; const rnd = () => (seed = (seed * 1103515245 + 12345) % 2147483648) / 2147483648;
    const pick = (max, dec) => Math.round(rnd() * max * Math.pow(10, dec)) / Math.pow(10, dec);
    const tr = x => String(x).replace('.', ',');
    const cases = [], inputs = [];
    for (let i = 0; i < 300; i++) {
        const m3 = i % 6 === 0;
        const n = { model: m3 ? 3 : 4, product: 'T', sheet_date: '2026-11-01', sale_kg: Math.max(1, pick(50000, 0)), sale_price: pick(5, m3 ? 4 : 8), currency: i % 2 ? 'EUR' : 'USD',
            rate: Math.max(0.5, pick(80, 4)), price_in_fx: rnd() > 0.3, raw_price: pick(60, 4), freight: pick(60000, 2), material_kg: pick(10, 4),
            docs: pick(9000, 2), other_kg: pick(3, 4), other_fixed: pick(9000, 2), target_margin: rnd() > 0.2 ? pick(70, 2) : null };
        if (m3) n.waste_pct = pick(60, 2); else { n.fire_pct = pick(95, 2); n.yield_pct = Math.round((100 - n.fire_pct) * 1e4) / 1e4; n.other_kg_basis = rnd() > 0.5 ? 'raw' : 'net'; }
        cases.push(n);
        const raw = { model: n.model, currency: n.currency, price_in_fx: n.price_in_fx, sale_kg: tr(n.sale_kg), sale_price: tr(n.sale_price), rate: tr(n.rate), raw_price: tr(n.raw_price),
            freight: tr(n.freight), material_kg: tr(n.material_kg), docs: tr(n.docs), other_kg: tr(n.other_kg), other_fixed: tr(n.other_fixed),
            target_margin: n.target_margin === null ? '' : tr(n.target_margin) };
        if (m3) raw.waste_pct = tr(n.waste_pct); else { raw.fire_pct = tr(n.fire_pct); raw.other_kg_basis = n.other_kg_basis; }
        inputs.push(H.calculate(raw));
    }
    const php = JSON.parse(execFileSync('php', [path.join(__dirname, 'hizli_hesapla_smoke.php'), '--json'], { input: JSON.stringify(cases), maxBuffer: 1 << 26 }).toString());
    let compared = 0;
    php.forEach((p, i) => {
        if (p.error) { assert.equal(inputs[i].ok, false, 'PHP hata, JS ok: ' + p.error + ' ' + JSON.stringify(cases[i])); return; }
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
