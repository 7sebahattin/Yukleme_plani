'use strict';
// Local browser behavior tests; mocked endpoints, no DB / HKS / SOAP calls.
const fs = require('fs');
const path = require('path');
let chromium;
try { chromium = require(process.env.PW_PATH || 'playwright').chromium; }
catch (_) { console.error('Playwright required: set PW_PATH to its installed package.'); process.exit(1); }
const app = fs.readFileSync(path.join(__dirname, '..', 'assets', 'app.js'), 'utf8');
const fixture = `<!doctype html><html lang="tr"><meta charset="utf-8"><body>
<div data-beyan-workspace><form data-beyan-form id="beyanForm">
<input type="hidden" name="csrf" value="csrf-test">
<div id="beyanSingleActions"><span data-beyan-dirty hidden></span><button type="submit">Kaydet</button></div>
<div data-beyan-tabs hidden><button type="button" data-beyan-tab="beyan">Beyan</button><button type="button" data-beyan-tab="analiz">Analiz</button><button type="button" data-beyan-tab="kaynak">Kaynak metin</button></div>
<div data-beyan-panel="beyan"><select name="status"><option value="taslak">Taslak</option><option value="red">Red</option><option value="temiz">Temiz</option></select><input name="party_no"><input name="product_name"><input name="gross_kg"><input name="net_kg"><input name="pallet_count" value="0"></div>
<div data-beyan-panel="analiz"><textarea name="analysis_note"></textarea><details id="requiredDetails"><summary>Analiz</summary><input name="required_test" required></details></div>
<div data-beyan-panel="kaynak"><textarea name="raw_text"></textarea><details data-beyan-unmatched hidden><summary>Eşleşmeyen</summary><textarea name="unmatched_text"></textarea></details>
<button type="button" data-beyan-parse-btn>Ayrıştır</button><div id="beyanParseStatus" hidden></div><div id="beyanBulkPreview" hidden></div></div>
</form></div></body></html>`;
let count = 0;
function ok(condition, label) { if (!condition) throw Error(label); count++; console.log('OK ' + label); }
(async () => {
    const browser = await chromium.launch(process.env.PW_CHANNEL ? {channel: process.env.PW_CHANNEL} : {});
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        async function setup(response) {
            await page.setContent(fixture);
            await page.evaluate(data => {
                window.parseResponse = data;
                window.sentRequests = [];
                window.fetch = async function(url, options) {
                    if (String(url).includes('beyan_parse')) return {json: async () => window.parseResponse};
                    window.sentRequests.push(JSON.parse(options.body));
                    // First save response is lost, second is an ordinary validation error.
                    if (window.sentRequests.length === 1) throw Error('lost response');
                    return { json: async () => ({ok:false,error:'Satır kontrolü', rows:[{row:1, errors:['Geçersiz değer']}]}) };
                };
            }, response || {});
            await page.addScriptTag({content: app});
        }
        await setup();
        ok(await page.locator('[data-beyan-tabs]').isVisible(), 'tabs progressively enabled');
        ok(await page.locator('[data-beyan-panel=analiz]').isHidden(), 'inactive panels hidden');
        await page.locator('[name=status]').selectOption('red');
        ok(await page.locator('[name=analysis_note]').evaluate(el => el.required), 'Red selection requires analysis note');
        await page.locator('[name=status]').selectOption('temiz');
        ok(!await page.locator('[name=analysis_note]').evaluate(el => el.required), 'Leaving Red releases analysis note requirement');
        await page.locator('[data-beyan-tab=beyan]').focus();
        await page.keyboard.press('ArrowRight');
        ok(await page.locator('[data-beyan-tab=analiz]').getAttribute('aria-selected') === 'true', 'keyboard activates next tab');
        await page.keyboard.press('End');
        ok(await page.locator('[data-beyan-tab=kaynak]').getAttribute('aria-selected') === 'true', 'End activates last tab');
        await page.locator('#beyanSingleActions button').click();
        await page.waitForTimeout(20);
        ok(await page.locator('[name=required_test]').evaluate(el => el === document.activeElement), 'invalid hidden field receives focus');
        ok(await page.locator('#requiredDetails').evaluate(el => el.open), 'invalid field ancestor details opens');
        await page.locator('[name=required_test]').fill('ready');
        ok(await page.locator('[data-beyan-dirty]').isVisible(), 'dirty indicator updates');
        ok(await page.evaluate(() => { const event = new Event('beforeunload', {cancelable:true}); window.dispatchEvent(event); return event.defaultPrevented; }), 'dirty unload guard active');
        await page.evaluate(() => document.querySelector('form').dispatchEvent(new Event('submit', {bubbles:true,cancelable:true})));
        ok(!await page.evaluate(() => {const event=new Event('beforeunload',{cancelable:true});window.dispatchEvent(event);return event.defaultPrevented;}), 'submit releases unload guard');
        await setup({ok:true,declarations:[{parsed:{party_no:'P1', gross_kg:123.456, net_kg:120.5,pallet_count:4},unmatched:'incomplete'}]});
        await page.locator('[data-beyan-tab=beyan]').click();
        await page.locator('[name=party_no]').fill('KEEP');
        await page.locator('[data-beyan-tab=kaynak]').click();
        await page.locator('[name=raw_text]').fill('text');
        await page.locator('[data-beyan-parse-btn]').click();
        await page.waitForTimeout(30);
        ok(await page.locator('[name=gross_kg]').inputValue() === '123,456', 'parser preserves 3 decimal KG using TR format');
        ok(await page.locator('[name=net_kg]').inputValue() === '120,5', 'parser preserves decimal KG');
        ok(await page.locator('[name=party_no]').inputValue() === 'KEEP', 'parser preserves nonempty differing values');
        ok(await page.locator('[name=pallet_count]').inputValue() === '0', 'explicit existing zero is preserved');
        ok((await page.locator('#beyanParseStatus').textContent()).includes('5 alan bulundu; 3 alan uygulandı; 2 alan'), 'summary counts actual found/applied/conflicting fields');
        ok(await page.locator('[data-beyan-tab=beyan]').getAttribute('aria-selected') === 'true', 'single parse returns to declaration tab');
        await page.locator('[data-beyan-tab=kaynak]').click();
        ok(await page.locator('[data-beyan-unmatched]').isVisible(), 'unmatched section appears on parsed content');
        await page.locator('[data-beyan-compare="0"]').check();
        await page.locator('[data-beyan-apply]').click();
        ok(await page.locator('[name=party_no]').inputValue() === 'P1', 'only selected conflicting field is applied');
        ok(await page.locator('[name=pallet_count]').inputValue() === '0', 'unchecked conflicting field remains unchanged');
        const bulkResponse = {ok:true,declarations:[{parsed:{party_no:'P1',gross_kg:12.125},raw_text:'one',unmatched:'unknown one'},{parsed:{party_no:'P2',net_kg:10.5},raw_text:'two',unmatched:'unknown two'}]};
        await setup(bulkResponse);
        await page.locator('[data-beyan-tab=kaynak]').click();
        await page.locator('[name=raw_text]').fill('one + two');
        await page.locator('[data-beyan-parse-btn]').click();
        await page.waitForTimeout(30);
        ok(await page.locator('[data-bulk-row]').count() === 2, 'bulk preview has selectable rows');
        ok(await page.locator('#beyanSingleActions button').isDisabled(), 'single submit disabled during bulk preview');
        await page.locator('[data-bulk-row="0"] [data-bulk-selected]').uncheck();
        ok((await page.locator('[data-beyan-bulk-save]').textContent()).includes('(1)'), 'save count follows selected rows');
        await page.locator('[data-bulk-row="1"] > details > summary').click();
        await page.locator('[data-bulk-row="1"] [data-bulk-field=party_no]').fill('EDITED');
        await page.locator('[data-beyan-bulk-save]').click();
        await page.waitForTimeout(30);
        const first = await page.evaluate(() => window.sentRequests[0]);
        ok(first.declarations.length === 1 && first.declarations[0].party_no === 'EDITED', 'bulk sends selected rows and inline corrections only');
        ok(first.declarations[0].net_kg === '10,5' && first.declarations[0].unmatched_text === 'unknown two', 'bulk TR KG and unmatched text included');
        ok(/^[A-Za-z0-9_-]{16,100}$/.test(first.request_id), 'bulk sends valid idempotency request ID');
        ok(await page.locator('[data-bulk-row="1"] [data-bulk-field=party_no]').isDisabled(), 'uncertain result freezes editable payload');
        await page.locator('[data-beyan-bulk-save]').click();
        await page.waitForTimeout(30);
        const second = await page.evaluate(() => window.sentRequests[1]);
        ok(JSON.stringify(first) === JSON.stringify(second), 'network retry uses identical request ID and payload');
        ok(await page.locator('.beyan-bulk-status').textContent() === 'Hata: Satır kontrolü', 'validation failure shown without navigation');
        await page.locator('[data-bulk-row="1"] [data-bulk-single]').click();
        ok(await page.locator('[name=party_no]').inputValue() === 'EDITED', 'chosen bulk row transfers inline edits to single form');
        ok(await page.locator('[name=net_kg]').inputValue() === '10,5', 'chosen row keeps KG precision in single form');
        // Full batch source already exists: transfer remains explicit via conflict review.
        await page.locator('[data-beyan-tab=kaynak]').click();
        ok((await page.locator('#beyanParseCompare').textContent()).includes('two'), 'single transfer offers exact row source for review');
        ok(errors.length === 0, 'no browser errors: ' + errors.join('; '));
        console.log(count + ' workspace/parser/bulk checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
