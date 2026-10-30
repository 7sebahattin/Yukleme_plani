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
const rendered = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'quick_cost_page_smoke.php'), '--render'], { cwd: root, encoding: 'utf8' });
const fixture = path.join(output, 'anonymous.html');
fs.writeFileSync(fixture, rendered.replace('<head>', '<head><base href="' + pathToFileURL(root + path.sep).href + '">'));
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
            await page.goto(pathToFileURL(fixture).href);
            await page.waitForSelector('#qc-form[data-qc-ready="1"]');
            check(await page.evaluate(() => scrollY === 0), name + ' initialization keeps the top of the form visible');
            check(await page.locator('#qc-total').textContent() === '670.000,00', name + ' initial total');
            check(await page.locator('#qc-unit-cost').textContent() === '33,50', name + ' initial unit');
            check(await page.locator('[data-qc-row]').count() === 10, name + ' ten rows');
            check(await page.locator('[data-qc-operation]').evaluateAll(nodes => nodes.map(node => node.dataset.qcOperation).join(',')) === 'uretim,fason', name + ' production precedes fason');
            check(await page.locator('[data-qc-operation="fason"]').getAttribute('aria-pressed') === 'true', name + ' fason default selection retained');
            check(await page.locator('#qc-product').getAttribute('placeholder') === null, name + ' product has no example placeholder');
            check(await page.getByRole('button', { name: 'Maliyet menüsü', exact: true }).count() === 0 && await page.getByRole('link', { name: 'Maliyet menüsü', exact: true }).count() === 0, name + ' removed header menu action');
            check(await page.getByRole('button', { name: 'Hızlı Hesap', exact: true }).getAttribute('aria-describedby') === 'qc-quick-description', name + ' quick button retains its accessible name');
            check(await page.locator('#qc-quick-description').textContent() === 'Yalnız fiyat' && await page.locator('#qc-detailed-description').textContent() === 'Birim, miktar ve fiyat', name + ' method differences described on buttons');
            check(await page.evaluate(() => {
                const before = scrollY;
                document.querySelector('[data-qc-operation="uretim"]').click();
                const unchanged = scrollY === before;
                document.querySelector('[data-qc-operation="fason"]').click();
                return unchanged && scrollY === before;
            }), name + ' operation changes do not scroll to cost fields');
            for (const method of ['quick','detailed']) {
                await page.getByRole('button', { name: method === 'quick' ? 'Hızlı Hesap' : 'Detaylı Hesap', exact: true }).click();
                check(await page.locator('#qc-unit-product').isVisible() === (method === 'detailed') && await page.locator('#qc-qty-product').isVisible() === (method === 'detailed'), name + ' ' + method + ' exposes the correct input fields');
                check(await page.locator('#qc-costs-heading').textContent() === (method === 'quick' ? 'Hızlı Hesap — Fiyatlar' : 'Detaylı Hesap — Birim, miktar ve fiyat'), name + ' ' + method + ' cost section names active method');
                check((await page.locator('#qc-method-hint').textContent()).includes(method === 'quick' ? 'Kg başına fiyatları ve sabit giderleri' : 'birimini, miktarını ve fiyatını'), name + ' ' + method + ' chooser explains active method');
                check(await page.evaluate(() => {
                    const heading = document.getElementById('qc-costs-heading');
                    const row = document.querySelector('[data-qc-row="product"]');
                    const box = row.getBoundingClientRect();
                    const obstacles = [document.querySelector('.qc-save-bar'), document.querySelector('.bottomnav')].filter(Boolean).map(node => node.getBoundingClientRect()).filter(rect => rect.height > 0 && rect.top > 0 && rect.top < innerHeight);
                    const bottom = Math.min(innerHeight, ...obstacles.map(rect => rect.top));
                    return document.activeElement === heading && heading.getBoundingClientRect().top >= 70 && box.top >= 0 && box.bottom <= bottom + 1;
                }), name + ' ' + method + ' focuses the heading and reveals first row above save/navigation');
                check(await page.locator('#qc-total').textContent() === '670.000,00' && await page.locator('[data-qc-operation="fason"]').getAttribute('aria-pressed') === 'true', name + ' ' + method + ' preserves values and operation');
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' ' + method + ' no horizontal overflow');
                const min = await page.locator('#qc-form input:not([type=hidden]):visible, #qc-form select:visible, #qc-form button:visible').evaluateAll(nodes => Math.min(...nodes.map(node => node.getBoundingClientRect().height)));
                check(min >= 44, name + ' ' + method + ' touch targets');
            }
            await page.locator('#qc-unit-transport').selectOption('pallet');
            await page.locator('#qc-qty-transport').fill('26');
            await page.locator('#qc-price-transport').fill('380');
            check(await page.locator('[data-qc-amount="transport"]').textContent() === '9.880,00 TL', name + ' pallet amount');
            await page.locator('[data-qc-method="quick"]').click();
            check(await page.locator('#qc-price-transport').getAttribute('readonly') !== null, name + ' manual equivalent readonly');
            await page.locator('#qc-net-kg').fill('10.000');
            check(await page.locator('[data-qc-amount="transport"]').textContent() === '9.880,00 TL', name + ' manual qty preserved net change');
            check(await page.locator('#qc-total').textContent() === '332.880,00', name + ' bound kg follows net');
            await page.locator('[data-qc-method="detailed"]').click();
            check(await page.locator('#qc-qty-transport').inputValue() === '26', name + ' mode retains qty');
            check(await page.locator('#qc-price-transport').inputValue() === '380', name + ' mode retains original price');
            await page.locator('[data-qc-operation="uretim"]').click();
            // Intercept submission after the actual handler has generated canonical payload.
            await page.evaluate(() => document.getElementById('qc-form').addEventListener('submit', event => event.preventDefault()));
            await page.locator('#qc-save').click();
            const payload = await page.locator('#qc-payload').inputValue();
            const data = JSON.parse(payload);
            check(data.operation_type === 'uretim' && data.method === 'detailed', name + ' metadata in payload');
            check(data.items.find(row => row.code === 'transport').qty === 26, name + ' detailed qty payload');
            await page.locator('#qc-price-product').fill('-1');
            check(await page.locator('#qc-save').isDisabled(), name + ' invalid input save disabled');
            await page.locator('#qc-price-product').fill('25');
            await page.locator('#qc-net-kg').fill('0');
            check(await page.locator('#qc-unit-cost').textContent() === '0,00', name + ' zero net safe');
            await page.locator('#qc-net-kg').fill('20.000');
            await page.locator('[data-qc-method="quick"]').click();
            // Capture a fresh sample; the intercepted save above intentionally
            // has no navigation response and would otherwise show "Kaydediliyor".
            await page.goto(pathToFileURL(fixture).href);
            await page.waitForSelector('#qc-form[data-qc-ready="1"]');
            for (const theme of ['light','dark']) {
                await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-top.png') });
                await page.locator('[data-qc-method="detailed"]').click();
                await page.locator('[data-qc-row="transport"]').scrollIntoViewIfNeeded();
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-detailed.png') });
                await page.locator('.qc-breakdown').scrollIntoViewIfNeeded();
                const saveBox = await page.locator('#qc-save').boundingBox();
                const navBox = await page.locator('.bottomnav').boundingBox();
                check(saveBox && saveBox.y >= 0 && saveBox.y + saveBox.height <= (navBox && width < 768 ? navBox.y : height) + 1, name + ' ' + theme + ' save clear of nav');
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-save.png') });
                await page.locator('[data-qc-method="quick"]').click();
                await page.evaluate(() => scrollTo(0,0));
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
