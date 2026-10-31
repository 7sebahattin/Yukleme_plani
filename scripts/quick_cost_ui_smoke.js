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
test('Focus clearing recognizes only valid complete zero strings', () => {
    for (const value of ['0','0,00','0.000','0000,0000',' 0 ']) assert.equal(quick.isZeroInput(value),true,value);
    for (const value of ['','0,25','25','025','-1','0,','1.2.3','0,00001','0.025']) assert.equal(quick.isZeroInput(value),false,value);
});
test('Blur normalization restores blank zero and removes only valid leading zeroes', () => {
    assert.equal(quick.normalizeInput(''),'0'); assert.equal(quick.normalizeInput('  '),'0');
    assert.equal(quick.normalizeInput('025'),'25'); assert.equal(quick.normalizeInput('020000'),'20.000');
    assert.equal(quick.normalizeInput('000,25'),'0,25'); assert.equal(quick.normalizeInput('01.234,50'),'1.234,5');
    for (const value of ['0,25','25','25,00','-1','1.2.3','0,']) assert.equal(quick.normalizeInput(value),value);
});
test('Word-only explanation follows kg binding without exposing numeric values', () => {
    let d=example(); assert.equal(quick.formulaText(d.items[0]),'Ürün KG × fiyat = toplam maliyet');
    d=quick.updateRow(d,'product','qty','020000');
    assert.equal(quick.formulaText(d.items[0]),'Kg miktarı × kg fiyatı = toplam maliyet');
    assert.equal(/\d/.test(quick.formulaText(d.items[0])),false);
});
test('Every manual unit and fixed expense has the corresponding word-only explanation', () => {
    const explanations={kg:'Kg miktarı × kg fiyatı = toplam maliyet',ton:'Ton miktarı × ton fiyatı = toplam maliyet',piece:'Adet × adet fiyatı = toplam maliyet',box:'Koli / kasa adedi × birim fiyat = toplam maliyet',pallet:'Palet adedi × palet fiyatı = toplam maliyet',vehicle:'Araç sayısı × araç fiyatı = toplam maliyet',fixed:'Sabit tutar = toplam maliyet'};
    for (const [unit,text] of Object.entries(explanations)) {
        const row={unit,basis:unit==='fixed'?'fixed':'manual',qty:26,unit_price:380,amount:9880};
        assert.equal(quick.formulaText(row),text); assert.equal(/\d/.test(quick.formulaText(row)),false);
    }
});
test('Word explanation remains useful with invalid drafts and never changes calculation input', () => {
    const row={unit:'pallet',basis:'manual',qty:'1.2.3',unit_price:'-5'}; const before=JSON.stringify(row);
    assert.equal(quick.formulaText(row),'Palet adedi × palet fiyatı = toplam maliyet');
    assert.equal(JSON.stringify(row),before); assert.equal(quick.formulaText(null),'Miktar × birim fiyat = toplam maliyet');
});
test('A new custom row is fixed zero by default and requires its own name', () => {
    const original=example(), next=quick.addCustomRow(original,'custom_first');
    assert.equal(original.items.length,10); assert.equal(next.items.length,11);
    assert.deepEqual(next.items[10],{code:'custom_first',label:'',unit:'fixed',basis:'fixed',qty:1,unit_price:0});
    assert.throws(()=>quick.calculate(next));
});
test('Custom fixed costs join group D exactly once with Turkish uppercase names', () => {
    let d=quick.addCustomRow(example(),'custom_first'); d=quick.updateRow(d,'custom_first','label','ek taksi iıİI'); d=quick.updateRow(d,'custom_first','unit_price',500);
    const result=quick.calculate(d), row=result.items[10];
    assert.equal(row.label,'EK TAKSİ İIİI'); assert.equal(row.group,'D'); assert.equal(row.amount,500);
    assert.equal(result.total,670500); assert.equal(result.groups.D,90500);
});
test('Custom units, manual quantities and kg binding use the existing exact engine', () => {
    let d=quick.addCustomRow(example(),'custom_pallet'); d=quick.updateRow(d,'custom_pallet','label','ek palet');
    d=quick.updateRow(d,'custom_pallet','unit','pallet'); d=quick.updateRow(d,'custom_pallet','qty',26); d=quick.updateRow(d,'custom_pallet','unit_price',380);
    assert.equal(quick.calculate(d).items[10].amount,9880); assert.equal(quick.calculate(quick.setNetKg(d,10000)).items[10].amount,9880);
    d=quick.updateRow(d,'custom_pallet','unit','kg'); d.items[10].basis='net_kg'; d=quick.updateRow(d,'custom_pallet','unit_price',1.25);
    assert.equal(quick.calculate(quick.setNetKg(d,10000)).items[10].amount,12500);
});
test('Only custom rows can be removed and legacy metadata survives', () => {
    let d=example(); d.operation_type='uretim'; d.method='detailed';
    d=quick.addCustomRow(d,'custom_remove'); d=quick.updateRow(d,'custom_remove','label','ek');
    assert.throws(()=>quick.removeCustomRow(d,'product')); assert.throws(()=>quick.updateRow(d,'product','label','changed'));
    d=quick.removeCustomRow(d,'custom_remove'); assert.equal(quick.calculate(d).total,670000); assert.equal(d.operation_type,'uretim'); assert.equal(d.method,'detailed');
});
test('Custom codes, mandatory rows and forty-row maximum are enforced', () => {
    let d=example();
    for (const code of ['custom_bad\n','custom_','custom_a-b','unknown']) assert.throws(()=>quick.addCustomRow(d,code));
    for(let i=0;i<40;i++){const code='custom_'+i; d=quick.addCustomRow(d,code); d=quick.updateRow(d,code,'label','kalem '+i);}
    assert.equal(quick.calculate(d).items.length,50); assert.throws(()=>quick.addCustomRow(d,'custom_41'));
    const missing=JSON.parse(JSON.stringify(d)); missing.items.splice(0,1); assert.throws(()=>quick.calculate(missing));
    const duplicate=JSON.parse(JSON.stringify(d)); duplicate.items[11].code=duplicate.items[10].code; assert.throws(()=>quick.calculate(duplicate));
});
test('Custom labels survive serialization and amounts recalculate after reopening', () => {
    let d=quick.addCustomRow(example(),'custom_saved'); d=quick.updateRow(d,'custom_saved','label','özel gider'); d=quick.updateRow(d,'custom_saved','unit_price','150,50');
    d.product='iıİI'; d.notes='bilgi iı';
    const payload=JSON.parse(JSON.stringify(quick.canonicalPayload(d)));
    assert.equal(payload.product,'İIİI'); assert.equal(payload.notes,'BİLGİ İI'); assert.equal(payload.items[10].label,'ÖZEL GİDER');
    assert.equal(quick.calculate(payload).total,670150.5); assert.equal(payload.items[0].label,undefined);
});
test('Turkish uppercase is actual data and post-conversion limits/control characters are checked', () => {
    assert.equal(quick.upperText('iıİI'),'İIİI'); assert.equal(quick.upperText('straße'),'STRASSE');
    let d=example(); d.product='ß'.repeat(76); assert.throws(()=>quick.canonicalPayload(d));
    d=example(); d.notes='ß'.repeat(1001); assert.throws(()=>quick.canonicalPayload(d));
    d=quick.addCustomRow(example(),'custom_long'); d.items[10].label='ß'.repeat(76); assert.throws(()=>quick.calculate(d));
    d.items[10].label='BAD\u0000NAME'; assert.throws(()=>quick.calculate(d));
});
test('Unit change resets the stale quantity instead of reinterpreting it', () => {
    let d = example(); d = quick.updateRow(d, 'transport', 'unit', 'ton'); const t = d.items.find(r => r.code === 'transport');
    assert.equal(t.qty, 1); assert.equal(t.basis, 'manual');
    d = quick.updateRow(d, 'transport', 'unit_price', '1500'); assert.equal(quick.calculate(d).items.find(r => r.code === 'transport').amount, 1500);
    assert.strictEqual(quick.updateRow(d, 'transport', 'unit', 'ton').items.find(r => r.code === 'transport').qty, 1);
});
test('Dotted thousands input is explained, other inputs are not', () => {
    assert.match(quick.numberReadNote('1.234'), /1234 olarak okunur/); assert.match(quick.numberReadNote('20.000'), /20000/);
    ['', '1,234', '1.234,5', '12,5', '1234', '1.23', 'abc'].forEach(v => assert.equal(quick.numberReadNote(v), '', v));
});
console.log('PASS '+passed+' pure quick-cost UI checks');

