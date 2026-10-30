'use strict';
// Pure anonymous checks; controller/browser integration has a separate suite.
const assert = require('node:assert/strict');
const quick = require('../assets/hizli-maliyet.js');
let passed = 0;
function test(label, run) { run(); passed++; console.log('OK ' + label); }
function document() {
    const kg = ['product', 'material', 'transport', 'labor', 'overhead'];
    return { product: 'Örnek ürün', sheet_date: '2026-10-30', operation_type: 'fason', method: 'quick', net_kg: 20000, notes: '', items: Object.keys(quick.catalog).map(code => ({code, unit: kg.includes(code) ? 'kg' : 'fixed', basis: kg.includes(code) ? 'net_kg' : 'fixed', qty: kg.includes(code) ? 20000 : 1, unit_price: 0})) };
}
function example() {
    const doc = document(), prices = { product:25, material:4, transport:1.6, labor:2, overhead:0.5, customs:4500, official_analysis:1500, private_analysis:2000 };
    doc.items.forEach(row => row.unit_price = prices[row.code] || 0); return doc;
}
test('20.000 kg yields 670.000 TL and 33,50 TL/kg', () => { const r=quick.calculate(example()); assert.equal(r.total,670000); assert.equal(r.unit_cost,33.5); assert.deepEqual(r.groups,{A:500000,B:80000,D:90000}); });
test('Turkish decimal/grouping parsing and canonical numeric JSON remain distinct', () => { assert.equal(quick.parseNumber('20.000'),20000); assert.equal(quick.parseNumber('1,60'),1.6); assert.equal(quick.parseNumber('1.234,5678'),1234.5678); assert.equal(quick.parseNumber(1.234),1.234); assert.equal(quick.parseNumber(''),0); });
test('Malformed, negative, scientific and overprecision values rejected', () => { for (const value of ['-1','1.2.3','1e3','1.60','1,23456','NaN',Infinity,null,{},1000001]) assert.throws(() => quick.parseNumber(value)); });
test('Detailed pallet quantity times price is 9.880 TL', () => { let d=document(); d=quick.updateRow(d,'transport','unit','pallet'); d=quick.updateRow(d,'transport','qty',26); d=quick.updateRow(d,'transport','unit_price',380); assert.equal(quick.calculate(d).items.find(row=>row.code==='transport').amount,9880); });
test('Mode roundtrip never rewrites quantity, unit or price', () => { let d=example(); d=quick.updateRow(d,'transport','unit','pallet'); d=quick.updateRow(d,'transport','qty','26'); d=quick.updateRow(d,'transport','unit_price','380'); const items=JSON.stringify(d.items); d=quick.setMethod(quick.setMethod(d,'detailed'),'quick'); assert.equal(JSON.stringify(d.items),items); assert.equal(quick.calculate(d).total,647880); });
test('Net kg updates linked rows while detailed manual quantities and fixed amounts persist', () => { let d=example(); d=quick.updateRow(d,'transport','qty',123); d=quick.setNetKg(d,'10.000'); const r=quick.calculate(d); assert.equal(r.items.find(x=>x.code==='product').amount,250000); assert.equal(r.items.find(x=>x.code==='transport').qty,123); assert.equal(r.items.find(x=>x.code==='customs').amount,4500); });
test('Manual kg edits keep their unit price and quantity through mode changes', () => { let d=example(); d=quick.updateRow(d,'labor','qty',100); const row=d.items.find(x=>x.code==='labor'); const r=quick.calculate(d).items.find(x=>x.code==='labor'); assert.equal(row.basis,'manual'); assert.equal(r.amount,200); assert.equal(quick.calculate(d).items.find(x=>x.code==='labor').unit_price,2); assert.equal(quick.setMethod(d,'quick').items.find(x=>x.code==='labor').qty,100); });
test('Zero kg safely leaves fixed expenses without dividing by zero', () => { const r=quick.calculate(quick.setNetKg(example(),0)); assert.equal(r.total,8000); assert.equal(r.unit_cost,0); });
test('Fixed quantity is always one, never double counted', () => { let d=document(); d=quick.updateRow(d,'customs','qty',100); d=quick.updateRow(d,'customs','unit_price',50); assert.equal(quick.calculate(d).total,50); });
test('Half-cent rounding matches server rule', () => { const d=document(); d.items.find(r=>r.code==='customs').unit_price=1.005; assert.equal(quick.calculate(d).total,1.01); });
test('High-scale fractional product keeps the exact lower cent', () => { let d=document(); d=quick.updateRow(d,'transport','qty',999999.9999); d=quick.updateRow(d,'transport','unit_price',9950.0001); assert.equal(quick.calculate(d).total,9950000099); });
test('Six-decimal kg cost rounds exact tie and adjacent lower rational correctly', () => { let d=document(); d.items.find(r=>r.code==='customs').unit_price=0.01; assert.equal(quick.calculate(d).unit_cost,0.000001); d=quick.setNetKg(d,20000.0001); assert.equal(quick.calculate(d).unit_cost,0); });
test('Canonical payload strips totals and posts JSON numbers', () => { const d=example(); d.net_kg='20.000'; d.items[0].unit_price='25,00'; d.total=1; const payload=quick.canonicalPayload(d); assert.equal(payload.net_kg,20000); assert.equal(payload.items[0].unit_price,25); assert.equal(payload.total,undefined); assert.equal(payload.items[0].amount,undefined); assert.equal(quick.calculate(payload).total,670000); });
test('Operation type retained independently of calculation method', () => { const d=example(); d.operation_type='uretim'; assert.equal(quick.canonicalPayload(quick.setMethod(d,'detailed')).operation_type,'uretim'); });
test('Duplicate or unknown row cannot inflate total', () => { const d=document(); d.items[1].code='product'; assert.throws(()=>quick.calculate(d)); });
test('Invalid input survives UI transforms and is never silently changed to zero', () => { let d=example(); d=quick.updateRow(d,'product','unit_price','-5'); d=quick.setMethod(d,'detailed'); assert.equal(d.items[0].unit_price,'-5'); assert.throws(()=>quick.canonicalPayload(d)); });
console.log('PASS '+passed+' pure quick-cost UI checks');

