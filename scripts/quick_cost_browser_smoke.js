'use strict';
// Headless, anonymous rendering only. No live application/DB/server is started.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
let playwright;
for (const candidate of [process.env.PW_PATH, 'playwright', 'playwright-core']) {
    if (!candidate) continue;
    try { playwright = require(candidate); break; } catch (_) {}
}
if (!playwright) throw new Error('Playwright gerekli. PW_PATH ile kurulu paket yolunu belirtin.');
const output = process.env.QUICK_COST_QA_OUT || fs.mkdtempSync(path.join(os.tmpdir(), 'quick-cost-qa-'));
fs.mkdirSync(output, { recursive: true });
function renderFixture(variant = '', list = false) {
    const rendered = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'quick_cost_page_smoke.php'), list ? '--render-list' : '--render', variant], { cwd: root, encoding: 'utf8' });
    const target = path.join(output, 'anonymous' + (list ? '-list' : variant ? '-' + variant : '') + '.html');
    fs.writeFileSync(target, rendered.replace('<head>', '<head><base href="' + pathToFileURL(root + path.sep).href + '">'));
    return target;
}
const fixture = renderFixture(), legacyFixture = renderFixture('legacy'), readOnlyFixture = renderFixture('readonly'), customFixture = renderFixture('custom'), listFixture = renderFixture('', true);
let checks = 0;
const proof = { anonymous: true, liveApplication: false, nativeMobileDevice: false, views: [], checks: 0 };
function check(value, label) { assert.ok(value, label); checks++; }
const qcostIcon = fs.readFileSync(path.join(root, 'assets/nav-icons/qcost.svg'), 'utf8');
check(qcostIcon.includes('viewBox="0 0 64 64"') && qcostIcon.includes('<linearGradient') && qcostIcon.includes('<feDropShadow'), 'dollar icon matches the native beveled SVG family');
check(!/<(?:script|text|image|foreignObject)\b/i.test(qcostIcon) && !/\b(?:href|src)\s*=/i.test(qcostIcon), 'dollar icon uses paths without fonts or external content');
// Real SW routing: business HTML bypasses CacheStorage, assets still use SW.
const worker = fs.readFileSync(path.join(root, 'sw.js'), 'utf8'), listeners = {};
vm.runInNewContext(worker, { self: { addEventListener: (event, callback) => { listeners[event] = callback; } }, URL, fetch: () => Promise.resolve({ headers: { get: () => '' }, clone: () => ({}), type: 'basic', ok: true }), caches: { open: () => Promise.resolve({ put: () => {} }) } });
for (const route of ['/hizli_maliyet.php','/hizli_maliyet.php?id=1','/hizli_maliyet.php/extra','/hizli_maliyet_form.php','/hizli_maliyet_form.php?id=1','/hizli_maliyet_form.php/extra']) {
    let intercepted = false;
    listeners.fetch({ request: { method:'GET', url:'https://local.invalid' + route }, respondWith: () => { intercepted = true; } });
    check(!intercepted, route + ' HTML bypasses SW');
}
for (const route of ['/assets/hizli-maliyet.js','/assets/hizli-maliyet.css','/maliyet.php']) {
    let intercepted = false;
    listeners.fetch({ request: { method:'GET', url:'https://local.invalid' + route }, respondWith: () => { intercepted = true; } });
    check(intercepted, route + ' existing routing retained');
}
const version = worker.match(/yukleme-plani-v(\d+)/)[1];
check(fs.readFileSync(path.join(root, 'config/helpers.php'), 'utf8').includes("define('APP_SURUM', 'v" + version + "')"), 'APP_SURUM and SW match');
(async () => {
    const browser = await playwright.chromium.launch({ headless: true, ...(process.env.QUICK_COST_BROWSER_CHANNEL ? { channel: process.env.QUICK_COST_BROWSER_CHANNEL } : {}) });
    try {
        for (const [name, width, height] of [['small',320,740],['iphone',390,844],['android',412,915],['desktop',1440,1000]]) {
            const context = await browser.newContext({ viewport: { width, height }, isMobile: width < 768, hasTouch: width < 768 });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('dialog', dialog => dialog.accept());
            const settings = code => page.locator('[data-qc-settings="' + code + '"]');
            async function toggle(code) { await settings(code).locator('summary').click(); }
            async function load(target = fixture) {
                await page.goto(pathToFileURL(target).href);
                await page.waitForSelector('#qc-form[data-qc-ready="1"]');
            }
            async function submitPayload() {
                await page.evaluate(() => document.getElementById('qc-form').addEventListener('submit', event => event.preventDefault()));
                await page.locator('#qc-save').click();
                return JSON.parse(await page.locator('#qc-payload').inputValue());
            }
            async function checkUnitArrows(label) {
                check(await page.locator('[data-qc-field="unit"]').evaluateAll(selects => selects.length > 0 && selects.every(select => {
                    const wrapper=select.parentElement, field=getComputedStyle(select), arrow=getComputedStyle(wrapper,'::after');
                    return select.tagName==='SELECT' && wrapper.classList.contains('qc-unit-select') && select.options.length===7
                        && field.appearance==='none' && parseFloat(field.paddingRight)>=36
                        && arrow.content==='""' && arrow.pointerEvents==='none' && arrow.position==='absolute'
                        && parseFloat(arrow.right)>=12 && parseFloat(arrow.borderTopWidth)>=5
                        && parseFloat(arrow.borderLeftWidth)>=3 && parseFloat(arrow.borderRightWidth)>=3
                        && arrow.borderTopColor===getComputedStyle(wrapper).color;
                })), label + ' native unit selectors retain a visible theme-aware triangle and text padding');
            }
            await load();
            if(name==='small') {
                check(await page.evaluate(async ({source,url}) => {
                    const svg=new DOMParser().parseFromString(source,'image/svg+xml');
                    if(svg.querySelector('parsererror') || svg.documentElement.getAttribute('viewBox')!=='0 0 64 64') return false;
                    const icon=new Image(); icon.src=url; await icon.decode();
                    return icon.naturalWidth===64 && icon.naturalHeight===64;
                }, {source:qcostIcon,url:pathToFileURL(path.join(root,'assets/nav-icons/qcost.svg')).href}), 'dollar SVG parses and decodes at 64px without external dependencies');
            }
            await checkUnitArrows(name + ' built-in');
            check(await page.evaluate(() => scrollY === 0), name + ' initialization keeps the top of the form visible');
            check(await page.locator('#qc-total').textContent() === '670.000,00', name + ' initial total');
            check(await page.locator('#qc-unit-cost').textContent() === '33,50', name + ' initial unit');
            check(await page.locator('[data-qc-row]').count() === 10, name + ' ten rows');
            check(!(await page.locator('.qc-costs').innerText()).includes('Malzeme; kasa, viyol') && await page.locator('[data-qc-row="material"] [data-qc-row-title]').textContent()==='Malzeme' && await page.locator('[data-qc-basis="material"]').textContent()==='Ürün KG × fiyat = toplam maliyet', name + ' lower material description removed while material row and calculation hint remain');
            check(await page.locator('[data-qc-operation], #qc-operation-hint').count() === 0 && !/Üretim|Fason|İşlem türü/.test(await page.locator('#qc-form').innerText()), name + ' operation metadata has no visible controls or captions');
            check(await page.locator('.qc-group-badge').count() === 0 && await page.locator('.qc-breakdown dt').evaluateAll(nodes => nodes.every(node => !/^[ABD]\s*[—-]/.test(node.textContent))), name + ' card badges and summary letter prefixes removed');
            check(await page.locator('#qc-group-A').textContent() === '500.000,00 TL' && await page.locator('#qc-group-B').textContent() === '80.000,00 TL' && await page.locator('#qc-group-D').textContent() === '90.000,00 TL', name + ' named subtotals preserved');
            check(await page.locator('[data-qc-basis="product"]').textContent() === 'Ürün KG × fiyat = toplam maliyet' && await page.locator('[data-qc-basis="customs"]').textContent() === 'Sabit tutar = toplam maliyet', name + ' calculation hints explain kg and fixed logic in words');
            check(await page.locator('#qc-product').getAttribute('placeholder') === null, name + ' product has no example placeholder');
            check(await page.getByRole('button', { name: 'Maliyet menüsü', exact: true }).count() === 0 && await page.getByRole('link', { name: 'Maliyet menüsü', exact: true }).count() === 0, name + ' removed header menu action');
            check(await page.locator('[data-qc-method], #qc-form[data-method]').count() === 0 && await page.getByRole('button', { name: 'Hızlı Hesap', exact: true }).count() === 0 && await page.getByRole('button', { name: 'Detaylı Hesap', exact: true }).count() === 0, name + ' no global mode controls or mode-dependent layout');
            check(await page.getByLabel('Ürün KG', { exact: true }).count() === 1 && await page.locator('.qc-hero-secondary').textContent().then(text => text.includes('Ürün KG')), name + ' quantity caption and input use Ürün KG');
            check(await page.locator('details[data-qc-settings]').count() === 10 && await page.locator('details[data-qc-settings][open]').count() === 0, name + ' ten independent settings panels start collapsed');
            check(await page.locator('[data-qc-settings] summary').evaluateAll(nodes => nodes.every(node => node.textContent.trim() === 'Ayar') && new Set(nodes.map(node => node.getAttribute('aria-label'))).size === 10), name + ' native Ayar summaries have unique accessible names');
            check(await page.locator('#qc-unit-product').isHidden() && await page.locator('#qc-qty-product').isHidden() && await page.locator('#qc-settings-price-product').isHidden(), name + ' collapsed settings hide their advanced fields');
            const zero = page.locator('#qc-price-casus');
            await zero.focus();
            check(await zero.inputValue() === '' && await page.locator('#qc-total').textContent() === '670.000,00', name + ' focusing zero clears only its display');
            await page.locator('#qc-product').focus();
            check(await page.evaluate(() => { const event=new Event('beforeunload',{cancelable:true}); window.dispatchEvent(event); return !event.defaultPrevented; }), name + ' focus and blur alone never mark an untouched zero dirty');
            await zero.focus();
            await zero.pressSequentially('25');
            check(await zero.inputValue() === '25' && await page.locator('#qc-settings-price-casus').inputValue() === '25' && await page.locator('#qc-total').textContent() === '670.025,00', name + ' typing after zero focus gives 25 without a leading zero');
            await zero.fill('0'); await page.locator('#qc-product').focus(); await zero.focus(); await page.locator('#qc-product').focus();
            check(await zero.inputValue() === '0' && await page.locator('#qc-total').textContent() === '670.000,00', name + ' untouched focused zero returns on blur without amount change');
            await zero.fill('025'); await page.locator('#qc-product').focus();
            check(await zero.inputValue() === '25' && await page.locator('#qc-settings-price-casus').inputValue() === '25', name + ' valid leading zero price normalizes and mirrors');
            await zero.fill('0,25'); await page.locator('#qc-product').focus(); await zero.focus();
            check(await zero.inputValue() === '0,25', name + ' nonzero decimal is never cleared on focus');
            await zero.fill('1.2.3'); await page.locator('#qc-product').focus(); await zero.focus();
            check(await zero.inputValue() === '1.2.3', name + ' invalid draft is never cleared or normalized');
            await zero.fill('0'); await page.locator('#qc-product').focus();
            await toggle('casus');
            await page.locator('#qc-settings-price-casus').focus();
            check(await page.locator('#qc-settings-price-casus').inputValue() === '' && await page.locator('#qc-qty-casus').inputValue() === '1' && await page.locator('#qc-qty-casus').isDisabled(), name + ' settings zero clears while fixed quantity one stays intact');
            await page.locator('#qc-settings-price-casus').pressSequentially('12');
            check(await zero.inputValue() === '12', name + ' settings zero entry shares the same canonical price');
            await page.locator('#qc-settings-price-casus').fill(''); await page.locator('#qc-product').focus();
            check(await page.locator('#qc-settings-price-casus').inputValue() === '0' && await zero.inputValue() === '0', name + ' erased settings price restores and mirrors zero on blur');
            await toggle('casus');
            await toggle('product');
            check(await page.locator('details[data-qc-settings][open]').count() === 1 && await page.locator('#qc-unit-product').isVisible() && await page.locator('#qc-qty-product').isVisible(), name + ' Ayar opens only its row');
            check(await page.locator('#qc-total').textContent() === '670.000,00', name + ' opening settings never changes amounts');
            await page.locator('#qc-price-product').fill('26');
            check(await page.locator('#qc-settings-price-product').inputValue() === '26' && await page.locator('#qc-total').textContent() === '690.000,00', name + ' main price updates the one canonical settings price');
            check(await page.locator('#qc-price-product').evaluate(input => { input.focus(); input.setSelectionRange(1,1); input.dispatchEvent(new Event('input',{bubbles:true})); return input.selectionStart === 1; }), name + ' main price repaint preserves caret');
            await page.locator('#qc-settings-price-product').fill('25');
            check(await page.locator('#qc-price-product').inputValue() === '25' && await page.locator('#qc-total').textContent() === '670.000,00', name + ' settings price mirrors main without double charging');
            check(await page.locator('#qc-settings-price-product').evaluate(input => { input.focus(); input.setSelectionRange(1,1); input.dispatchEvent(new Event('input',{bubbles:true})); return input.selectionStart === 1; }), name + ' settings price repaint preserves caret');
            await toggle('transport');
            check(await settings('product').getAttribute('open') !== null && await settings('transport').getAttribute('open') !== null && await page.locator('details[data-qc-settings][open]').count() === 2, name + ' opening another row preserves existing open state');
            await toggle('product');
            check(await settings('transport').getAttribute('open') !== null && await settings('product').getAttribute('open') === null, name + ' closing one row leaves others unchanged');
            await toggle('labor');
            await page.locator('#qc-qty-labor').fill('100');
            check(await page.locator('#qc-price-labor').getAttribute('readonly') !== null && await page.locator('[data-qc-amount="labor"]').textContent() === '200,00 TL', name + ' manual kg quantity uses equivalent main price');
            await page.locator('[data-qc-rebind="labor"]').click();
            check(await page.locator('#qc-qty-labor').inputValue() === '20.000' && await page.locator('#qc-price-labor').getAttribute('readonly') === null && await page.locator('#qc-total').textContent() === '670.000,00', name + ' Ürün KG kullan restores kg binding explicitly');
            await toggle('labor');
            await page.locator('#qc-unit-transport').selectOption('pallet');
            await page.locator('#qc-qty-transport').fill('26');
            await page.locator('#qc-settings-price-transport').fill('380');
            check(await page.locator('[data-qc-amount="transport"]').textContent() === '9.880,00 TL', name + ' pallet amount');
            check(await page.locator('[data-qc-basis="transport"]').textContent() === 'Palet adedi × palet fiyatı = toplam maliyet' && !/\d/.test(await page.locator('[data-qc-basis="transport"]').textContent()), name + ' pallet explanation changes unit without showing numeric values');
            check(await page.locator('#qc-price-transport').getAttribute('readonly') !== null, name + ' manual equivalent readonly');
            check(await page.locator('#qc-price-transport').inputValue() === '0,4940', name + ' manual main price is amount divided by Ürün KG');
            await toggle('transport'); await toggle('transport');
            check(await page.locator('#qc-qty-transport').inputValue() === '26' && await page.locator('#qc-settings-price-transport').inputValue() === '380', name + ' closing/reopening retains original manual quantity and price');
            await page.locator('#qc-net-kg').fill('10.000');
            check(await page.locator('[data-qc-amount="transport"]').textContent() === '9.880,00 TL', name + ' manual qty preserved net change');
            check(await page.locator('#qc-total').textContent() === '332.880,00', name + ' bound kg follows net');
            check(await settings('transport').getAttribute('open') !== null && await page.locator('#qc-qty-transport').inputValue() === '26' && await page.locator('#qc-settings-price-transport').inputValue() === '380', name + ' Ürün KG edit preserves open state and original manual price');
            check(await page.locator('#qc-qty-product').inputValue() === '10.000' && await page.locator('#qc-price-transport').inputValue() === '0,9880', name + ' bound quantity and manual equivalent follow Ürün KG');
            await page.locator('#qc-net-kg').fill('0');
            check(await page.locator('#qc-unit-cost').textContent() === '0,00' && await page.locator('#qc-price-transport').inputValue() === '—', name + ' zero Ürün KG avoids division and preserves manual row');
            await toggle('product');
            await page.locator('#qc-qty-product').focus();
            check(await page.locator('#qc-qty-product').inputValue() === '', name + ' zero linked quantity clears on focus');
            await page.evaluate(() => { const other=document.getElementById('qc-price-casus'); other.value='0'; other.dispatchEvent(new Event('input',{bubbles:true})); });
            check(await page.locator('#qc-qty-product').inputValue() === '', name + ' another repaint does not repopulate the active cleared zero');
            await page.locator('#qc-product').focus();
            check(await page.locator('#qc-qty-product').inputValue() === '0' && await page.locator('[data-qc-rebind="product"]').isHidden(), name + ' focus-only zero quantity keeps its kg binding');
            await toggle('product');
            await page.locator('#qc-net-kg').focus();
            check(await page.locator('#qc-net-kg').inputValue() === '', name + ' zero Ürün KG clears on focus');
            await page.locator('#qc-net-kg').pressSequentially('20000');
            await page.locator('#qc-net-kg').fill('020000'); await page.locator('#qc-product').focus();
            check(await page.locator('#qc-net-kg').inputValue() === '20.000' && await page.locator('#qc-qty-product').inputValue() === '20.000', name + ' leading zero Ürün KG normalizes without corrupting bound quantity');
            await page.locator('#qc-net-kg').fill('20.000');
            await page.locator('#qc-qty-transport').fill('0'); await page.locator('#qc-price-transport').focus();
            check(await page.locator('#qc-price-transport').inputValue() === '0,0000', name + ' readonly zero equivalent is never cleared');
            await page.locator('#qc-qty-transport').focus(); await page.locator('#qc-qty-transport').pressSequentially('26');
            check(await page.locator('#qc-qty-transport').inputValue() === '26' && await page.locator('[data-qc-amount="transport"]').textContent() === '9.880,00 TL', name + ' manual quantity enters without a prefixed zero');
            await toggle('customs');
            check(await page.locator('#qc-qty-customs').isDisabled() && await page.locator('#qc-qty-customs').inputValue() === '1', name + ' fixed settings keep quantity one');
            await page.locator('#qc-price-customs').fill('4600');
            check(await page.locator('#qc-settings-price-customs').inputValue() === '4600' && await page.locator('[data-qc-amount="customs"]').textContent() === '4.600,00 TL', name + ' fixed main price synchronizes once');
            await page.locator('#qc-settings-price-customs').fill('4500');
            check(await page.locator('#qc-price-customs').inputValue() === '4500', name + ' fixed settings price synchronizes main');
            await page.locator('#qc-settings-price-transport').fill('-1'); await toggle('transport');
            check(await page.locator('#qc-save').isDisabled() && await settings('transport').getAttribute('class').then(value => value.includes('has-error')) && await page.locator('#qc-validation').isVisible(), name + ' closed invalid settings price blocks save with row indicator');
            check(await page.locator('[data-qc-basis="transport"]').textContent() === 'Palet adedi × palet fiyatı = toplam maliyet', name + ' invalid numeric draft still shows word-only calculation logic');
            await page.locator('#qc-notes').fill('Korunan açıklama');
            check(await settings('transport').getAttribute('open') === null && await settings('customs').getAttribute('open') !== null, name + ' header edit retains each settings open state');
            await toggle('transport');
            check(await page.locator('#qc-settings-price-transport').inputValue() === '-1', name + ' invalid original price survives close and header change');
            await page.locator('#qc-settings-price-transport').fill('380');
            await page.locator('#qc-qty-transport').fill('1.2.3'); await toggle('transport');
            check(await page.locator('#qc-save').isDisabled(), name + ' invalid closed quantity cannot be silently coerced');
            await toggle('transport');
            check(await page.locator('#qc-qty-transport').inputValue() === '1.2.3', name + ' invalid quantity draft retained');
            await page.locator('#qc-qty-transport').fill('26');
            check(await page.locator('#qc-total').textContent() === '647.880,00', name + ' correction restores exact canonical total');
            // Intercept submission after the actual handler has generated canonical payload.
            const data = await submitPayload();
            check(data.operation_type === 'fason' && data.method === 'quick', name + ' hidden operation and legacy method metadata retained');
            check(data.items.find(row => row.code === 'transport').qty === 26, name + ' detailed qty payload');
            check(data.items.length === 10 && data.items.find(row => row.code === 'transport').unit_price === 380 && !Object.prototype.hasOwnProperty.call(data,'total'), name + ' dual price inputs serialize only one trusted row price');
            await load();
            check(await page.locator('[data-qc-row="product"] [data-qc-remove]').count() === 0, name + ' mandatory rows have no remove action');
            await page.locator('#qc-add-item').click();
            const customRow=page.locator('[data-qc-row^="custom_"]').last(), customCode=await customRow.getAttribute('data-qc-row');
            check(await page.locator('[data-qc-row]').count() === 11 && await page.locator('#qc-save').isDisabled(), name + ' adding a custom row requires its name');
            const customName=customRow.locator('[data-qc-field="label"]');
            await customName.fill('ek palet iıİI');
            check(await customName.inputValue() === 'EK PALET İIİI' && await customRow.locator('[data-qc-row-title]').textContent() === 'EK PALET İIİI', name + ' custom name is actual Turkish uppercase data');
            await customName.fill('<svg onload=alert(1)>');
            check(await customRow.locator('svg,img').count() === 0 && (await customRow.locator('[data-qc-row-title]').textContent()).includes('<SVG'), name + ' custom label is rendered as text rather than HTML');
            await customName.fill('ek palet iıİI');
            await page.locator('#qc-product').fill('iıİI');
            check(await page.locator('#qc-product').inputValue() === 'İIİI', name + ' product text is Turkish uppercase');
            check(await page.locator('#qc-notes').evaluate(input => { input.value='aßi'; input.setSelectionRange(2,2); input.dispatchEvent(new Event('input',{bubbles:true})); return input.value==='ASSİ' && input.selectionStart===3; }), name + ' uppercase length expansion preserves caret position');
            check(await page.locator('#qc-notes').evaluate(input => {
                input.dispatchEvent(new CompositionEvent('compositionstart',{bubbles:true})); input.value='i';
                input.dispatchEvent(new InputEvent('input',{bubbles:true,isComposing:true,data:'i'}));
                return input.value==='i' && document.getElementById('qc-save').disabled;
            }), name + ' IME draft is not rewritten during composition');
            check(await page.locator('#qc-notes').evaluate(input => { input.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true,data:'i'})); return input.value==='İ'; }), name + ' IME commit converts actual text to Turkish uppercase');
            await customRow.locator('summary').click();
            await checkUnitArrows(name + ' custom');
            check(await customRow.locator('[data-qc-field="qty"]').inputValue() === '1' && await customRow.locator('[data-qc-field="qty"]').isDisabled(), name + ' custom row defaults to fixed quantity one');
            await customRow.locator('[data-qc-field="unit"]').selectOption('pallet');
            await customRow.locator('[data-qc-field="qty"]').fill('26');
            await customRow.locator('[data-qc-price="settings"]').fill('380');
            check(await customRow.locator('[data-qc-amount]').textContent() === '9.880,00 TL' && await page.locator('#qc-total').textContent() === '679.880,00', name + ' custom settings use the same exact cost engine');
            check(await customRow.locator('[data-qc-price="main"]').getAttribute('readonly') !== null && await customRow.locator('[data-qc-price="main"]').inputValue() === '0,4940', name + ' custom manual row shows readonly equivalent');
            await page.locator('#qc-net-kg').fill('10.000');
            check(await customRow.locator('[data-qc-amount]').textContent() === '9.880,00 TL', name + ' custom manual quantity survives Ürün KG change');
            await page.locator('#qc-net-kg').fill('20.000');
            const customPayload=await submitPayload(), customSaved=customPayload.items.find(row=>row.code===customCode);
            check(customPayload.items.length === 11 && customSaved.label === 'EK PALET İIİI' && customSaved.qty === 26 && customSaved.unit_price === 380 && customPayload.product === 'İIİI' && customPayload.notes === 'İ', name + ' custom label and uppercase text serialize with one canonical price');
            await customRow.locator('[data-qc-remove]').click();
            check(await page.locator('[data-qc-row]').count() === 10 && await page.locator('#qc-total').textContent() === '670.000,00', name + ' removing custom row restores baseline totals');
            if (name === 'desktop') {
                check(await page.evaluate(() => {
                    const add=document.getElementById('qc-add-item');
                    for(let i=0;i<40;i++){add.click(); const input=document.activeElement; input.value='KALEM '+i; input.dispatchEvent(new Event('input',{bubbles:true}));}
                    return document.querySelectorAll('[data-qc-row]').length===50 && add.disabled;
                }), name + ' UI caps custom rows at forty');
                await page.evaluate(() => Array.from(document.querySelectorAll('[data-qc-remove]')).forEach(button=>button.click()));
                check(await page.locator('#qc-add-item').isEnabled() && await page.locator('[data-qc-row]').count() === 10, name + ' removing custom rows frees the add limit');
            }
            await load(customFixture);
            check(await page.locator('[data-qc-row]').count() === 11 && await page.locator('#qc-total').textContent() === '679.880,00', name + ' server-saved custom row reopens with identical amount');
            await page.locator('[data-qc-settings="custom_fixture"] summary').click();
            check(await page.locator('#qc-settings-price-custom_fixture').inputValue() === '380' && await page.locator('#qc-label-custom_fixture').inputValue() === 'EK PALET', name + ' reopened custom row preserves its original name and price');
            await load(legacyFixture);
            check(await page.locator('[data-qc-method]').count() === 0 && await page.locator('details[open]').count() === 0 && await page.locator('#qc-total').textContent() === '647.880,00', name + ' legacy detailed record uses same collapsed unified screen');
            await toggle('transport');
            check(await page.locator('#qc-settings-price-transport').inputValue() === '380' && await page.locator('#qc-price-transport').getAttribute('readonly') !== null, name + ' legacy detailed manual price remains original in Ayar');
            const legacyData=await submitPayload();
            check(legacyData.method === 'detailed' && legacyData.operation_type === 'uretim', name + ' editing legacy record preserves its hidden internal metadata');
            await load(readOnlyFixture);
            await toggle('transport');
            await checkUnitArrows(name + ' read-only');
            check(await page.locator('#qc-unit-transport').isDisabled() && await page.locator('#qc-qty-transport').isDisabled() && await page.locator('#qc-settings-price-transport').isDisabled() && await page.locator('#qc-price-transport').isDisabled(), name + ' read-only users inspect Ayar with controls disabled');
            check(await page.locator('#qc-save').count() === 0 && await page.locator('[data-qc-method]').count() === 0, name + ' read-only screen has no save or mode controls');
            check(await page.locator('#qc-add-item, [data-qc-remove]').count() === 0, name + ' read-only users cannot add or remove custom rows');
            await page.goto(pathToFileURL(listFixture).href);
            await page.waitForSelector('.qc-record-list');
            check(await page.locator('#qc-form').count() === 0, name + ' main page is the record list without cost inputs');
            check(await page.locator('a[href="hizli_maliyet_form.php"]').count() > 0 && await page.locator('a[href="hizli_maliyet_form.php?id=1"]').count() > 0, name + ' list new/edit links target the separate form');
            const deletion=page.locator('form').filter({has:page.locator('input[name="action"][value="delete"]')});
            check(await deletion.count() === 1 && await deletion.getAttribute('method').then(method=>method.toLowerCase()==='post') && await deletion.locator('input[name="csrf"],input[name="id"],input[name="version"]').count() === 3, name + ' record deletion uses a protected POST form');
            // Capture a fresh sample; the intercepted save above intentionally
            // has no navigation response and would otherwise show "Kaydediliyor".
            await load();
            for (const theme of ['light','dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-top.png') });
                await page.locator('[data-qc-row="product"]').scrollIntoViewIfNeeded();
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-collapsed.png') });
                await toggle('transport');
                await page.locator('[data-qc-row="transport"]').scrollIntoViewIfNeeded();
                await checkUnitArrows(name + ' ' + theme);
                check(await page.locator('#qc-unit-transport').evaluate(select => {
                    const box=select.getBoundingClientRect(), y=box.top+box.height/2, x=box.right-18;
                    if(y<0 || y>=innerHeight || x<0 || x>=innerWidth) return true;
                    return document.elementFromPoint(x,y)===select;
                }), name + ' ' + theme + ' triangle does not intercept native unit clicks');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' ' + theme + ' settings no horizontal overflow');
                const min = await page.locator('#qc-form input:not([type=hidden]):visible, #qc-form select:visible, #qc-form button:visible, #qc-form summary:visible').evaluateAll(nodes => Math.min(...nodes.map(node => node.getBoundingClientRect().height)));
                check(min >= 44, name + ' ' + theme + ' settings touch targets');
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-settings.png') });
                await page.locator('.qc-breakdown').scrollIntoViewIfNeeded();
                const saveBox = await page.locator('#qc-save').boundingBox();
                const navBox = await page.locator('.bottomnav').boundingBox();
                check(saveBox && saveBox.y >= 0 && saveBox.y + saveBox.height <= (navBox && width < 768 ? navBox.y : height) + 1, name + ' ' + theme + ' save clear of nav');
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-save.png') });
                await toggle('transport');
                await page.evaluate(() => scrollTo({ top:0, behavior:'instant' }));
            }
            check(errors.length === 0, name + ' no browser exceptions: ' + errors.join(','));
            proof.views.push({ name, width, height, themes: ['light','dark'], browserErrors: errors });
            await context.close();
        }
        proof.checks = checks;
        fs.writeFileSync(path.join(output, 'proof.json'), JSON.stringify(proof, null, 2));
        console.log(JSON.stringify({ ok:true, checks, output }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
