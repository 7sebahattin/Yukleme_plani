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
function renderFixture(variant = '') {
    const rendered = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'quick_cost_page_smoke.php'), '--render', variant], { cwd: root, encoding: 'utf8' });
    const target = path.join(output, 'anonymous' + (variant ? '-' + variant : '') + '.html');
    fs.writeFileSync(target, rendered.replace('<head>', '<head><base href="' + pathToFileURL(root + path.sep).href + '">'));
    return target;
}
const fixture = renderFixture(), legacyFixture = renderFixture('legacy'), readOnlyFixture = renderFixture('readonly');
let checks = 0;
const proof = { anonymous: true, liveApplication: false, nativeMobileDevice: false, views: [], checks: 0 };
function check(value, label) { assert.ok(value, label); checks++; }
// Real SW routing: business HTML bypasses CacheStorage, assets still use SW.
const worker = fs.readFileSync(path.join(root, 'sw.js'), 'utf8'), listeners = {};
vm.runInNewContext(worker, { self: { addEventListener: (event, callback) => { listeners[event] = callback; } }, URL, fetch: () => Promise.resolve({ headers: { get: () => '' }, clone: () => ({}), type: 'basic', ok: true }), caches: { open: () => Promise.resolve({ put: () => {} }) } });
for (const route of ['/hizli_maliyet.php','/hizli_maliyet.php?id=1','/hizli_maliyet.php/extra']) {
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
            await load();
            check(await page.evaluate(() => scrollY === 0), name + ' initialization keeps the top of the form visible');
            check(await page.locator('#qc-total').textContent() === '670.000,00', name + ' initial total');
            check(await page.locator('#qc-unit-cost').textContent() === '33,50', name + ' initial unit');
            check(await page.locator('[data-qc-row]').count() === 10, name + ' ten rows');
            check(await page.locator('[data-qc-operation]').evaluateAll(nodes => nodes.map(node => node.dataset.qcOperation).join(',')) === 'uretim,fason', name + ' production precedes fason');
            check(await page.locator('[data-qc-operation="fason"]').getAttribute('aria-pressed') === 'true', name + ' fason default selection retained');
            check(await page.locator('#qc-product').getAttribute('placeholder') === null, name + ' product has no example placeholder');
            check(await page.getByRole('button', { name: 'Maliyet menüsü', exact: true }).count() === 0 && await page.getByRole('link', { name: 'Maliyet menüsü', exact: true }).count() === 0, name + ' removed header menu action');
            check(await page.locator('[data-qc-method], #qc-form[data-method]').count() === 0 && await page.getByRole('button', { name: 'Hızlı Hesap', exact: true }).count() === 0 && await page.getByRole('button', { name: 'Detaylı Hesap', exact: true }).count() === 0, name + ' no global mode controls or mode-dependent layout');
            check(await page.getByLabel('Ürün KG', { exact: true }).count() === 1 && await page.locator('.qc-hero-secondary').textContent().then(text => text.includes('Ürün KG')), name + ' quantity caption and input use Ürün KG');
            check(await page.locator('details[data-qc-settings]').count() === 10 && await page.locator('details[data-qc-settings][open]').count() === 0, name + ' ten independent settings panels start collapsed');
            check(await page.locator('[data-qc-settings] summary').evaluateAll(nodes => nodes.every(node => node.textContent.trim() === 'Ayar') && new Set(nodes.map(node => node.getAttribute('aria-label'))).size === 10), name + ' native Ayar summaries have unique accessible names');
            check(await page.locator('#qc-unit-product').isHidden() && await page.locator('#qc-qty-product').isHidden() && await page.locator('#qc-settings-price-product').isHidden(), name + ' collapsed settings hide their advanced fields');
            check(await page.evaluate(() => {
                const before = scrollY;
                document.querySelector('[data-qc-operation="uretim"]').click();
                const unchanged = scrollY === before;
                document.querySelector('[data-qc-operation="fason"]').click();
                return unchanged && scrollY === before;
            }), name + ' operation changes do not auto-scroll');
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
            await page.locator('#qc-net-kg').fill('20.000');
            await toggle('customs');
            check(await page.locator('#qc-qty-customs').isDisabled() && await page.locator('#qc-qty-customs').inputValue() === '1', name + ' fixed settings keep quantity one');
            await page.locator('#qc-price-customs').fill('4600');
            check(await page.locator('#qc-settings-price-customs').inputValue() === '4600' && await page.locator('[data-qc-amount="customs"]').textContent() === '4.600,00 TL', name + ' fixed main price synchronizes once');
            await page.locator('#qc-settings-price-customs').fill('4500');
            check(await page.locator('#qc-price-customs').inputValue() === '4500', name + ' fixed settings price synchronizes main');
            await page.locator('#qc-settings-price-transport').fill('-1'); await toggle('transport');
            check(await page.locator('#qc-save').isDisabled() && await settings('transport').getAttribute('class').then(value => value.includes('has-error')) && await page.locator('#qc-validation').isVisible(), name + ' closed invalid settings price blocks save with row indicator');
            await page.locator('[data-qc-operation="uretim"]').click();
            check(await settings('transport').getAttribute('open') === null && await settings('customs').getAttribute('open') !== null, name + ' operation edit retains each settings open state');
            await toggle('transport');
            check(await page.locator('#qc-settings-price-transport').inputValue() === '-1', name + ' invalid original price survives close and type change');
            await page.locator('#qc-settings-price-transport').fill('380');
            await page.locator('#qc-qty-transport').fill('1.2.3'); await toggle('transport');
            check(await page.locator('#qc-save').isDisabled(), name + ' invalid closed quantity cannot be silently coerced');
            await toggle('transport');
            check(await page.locator('#qc-qty-transport').inputValue() === '1.2.3', name + ' invalid quantity draft retained');
            await page.locator('#qc-qty-transport').fill('26');
            check(await page.locator('#qc-total').textContent() === '647.880,00', name + ' correction restores exact canonical total');
            await page.locator('[data-qc-operation="uretim"]').click();
            // Intercept submission after the actual handler has generated canonical payload.
            const data = await submitPayload();
            check(data.operation_type === 'uretim' && data.method === 'quick', name + ' operation and legacy method metadata retained');
            check(data.items.find(row => row.code === 'transport').qty === 26, name + ' detailed qty payload');
            check(data.items.length === 10 && data.items.find(row => row.code === 'transport').unit_price === 380 && !Object.prototype.hasOwnProperty.call(data,'total'), name + ' dual price inputs serialize only one trusted row price');
            await load(legacyFixture);
            check(await page.locator('[data-qc-method]').count() === 0 && await page.locator('details[open]').count() === 0 && await page.locator('#qc-total').textContent() === '647.880,00', name + ' legacy detailed record uses same collapsed unified screen');
            await toggle('transport');
            check(await page.locator('#qc-settings-price-transport').inputValue() === '380' && await page.locator('#qc-price-transport').getAttribute('readonly') !== null, name + ' legacy detailed manual price remains original in Ayar');
            check((await submitPayload()).method === 'detailed', name + ' editing legacy record preserves its internal method');
            await load(readOnlyFixture);
            await toggle('transport');
            check(await page.locator('#qc-unit-transport').isDisabled() && await page.locator('#qc-qty-transport').isDisabled() && await page.locator('#qc-settings-price-transport').isDisabled() && await page.locator('#qc-price-transport').isDisabled(), name + ' read-only users inspect Ayar with controls disabled');
            check(await page.locator('#qc-save').count() === 0 && await page.locator('[data-qc-method]').count() === 0, name + ' read-only screen has no save or mode controls');
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
