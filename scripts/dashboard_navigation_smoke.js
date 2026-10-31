'use strict';
// Anonymous presentation fixtures only; never start the application or a DB.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '..');
let playwright;
for (const candidate of [process.env.PW_PATH, 'playwright', 'playwright-core']) {
    if (!candidate) continue;
    try { playwright = require(candidate); break; } catch (_) {}
}
if (!playwright) throw new Error('Playwright gerekli; PW_PATH ile kurulu paket yolunu belirtin.');
const output = process.env.DASHBOARD_NAV_QA_OUT || fs.mkdtempSync(path.join(os.tmpdir(), 'dashboard-nav-qa-'));
fs.mkdirSync(output, { recursive: true });
function fixture(profile) {
    const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'dashboard_sidebar_parity_smoke.php'), '--render', profile], { cwd: root, encoding: 'utf8' });
    const file = path.join(output, profile + '.html');
    const base = '<base href="' + pathToFileURL(root + path.sep).href + '">';
    fs.writeFileSync(file, html.includes('<base ') ? html : html.replace('<head>', '<head>' + base));
    return pathToFileURL(file).href;
}
const fixtures = Object.fromEntries(['admin', 'operator', 'only_stok_read', 'only_users_admin', 'dashboard_only'].map(profile => [profile, fixture(profile)]));
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
const proof = { anonymous: true, liveApplication: false, physicalMobileDevice: false, views: [], checks: 0 };
(async () => {
    const browser = await playwright.chromium.launch({ headless: true, ...(process.env.QUICK_COST_BROWSER_CHANNEL ? { channel: process.env.QUICK_COST_BROWSER_CHANNEL } : {}) });
    try {
        for (const [name, width, height] of [['small', 320, 740], ['iphone', 390, 844], ['android', 412, 915], ['desktop', 1440, 1000]]) {
            const context = await browser.newContext({ viewport: { width, height }, isMobile: width < 768, hasTouch: width < 768 });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(fixtures.admin);
            await page.locator('.home-card[href="hizli_maliyet.php"] img').waitFor({ state: 'attached' });
            check(await page.locator('.home-card').count() === 69, name + ' all sixty-nine admin destinations are cards');
            check(await page.evaluate(() => {
                const cards = new Set(Array.from(document.querySelectorAll('.home-card')).map(a => a.getAttribute('href')));
                return Array.from(document.querySelectorAll('.sidebar-nav .sidebar-link')).every(a => a.getAttribute('href') === 'index.php' || cards.has(a.getAttribute('href')));
            }), name + ' every sidebar module destination is on the home screen');
            check(await page.locator('.home-card[href="hizli_maliyet.php"] img').evaluate(image => image.complete && image.naturalWidth === 64 && image.getAttribute('src').includes('assets/nav-icons/qcost.svg?v=')), name + ' dollar asset decodes on the home card');
            check(await page.locator('.sidebar-link[href="hizli_maliyet.php"] img').evaluate(image => image.complete && image.naturalWidth === 64 && image.width === 20 && image.height === 20 && image.getAttribute('src').includes('assets/nav-icons/qcost.svg?v=')), name + ' sidebar reuses the dollar asset at its original icon size');
            for (const theme of ['light', 'dark']) {
                await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
                check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + '/' + theme + ' no horizontal page overflow');
                check(await page.locator('.home-card').evaluateAll(nodes => nodes.every(node => {
                    const box = node.getBoundingClientRect();
                    return box.width >= 44 && box.height >= 44 && node.scrollWidth <= node.clientWidth + 1;
                })), name + '/' + theme + ' all cards fit and remain touchable');
                const quick = page.locator('.home-card[href="hizli_maliyet.php"]');
                await quick.focus();
                check(await quick.evaluate(node => document.activeElement === node), name + '/' + theme + ' quick card is keyboard focusable');
                await page.evaluate(() => scrollTo(0, 0));
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-home.png') });
                await page.locator('.home-card[href="lisans_kayit_islemleri.php"]').scrollIntoViewIfNeeded();
                check(await page.locator('.home-card[href="lisans_kayit_islemleri.php"]').isVisible(), name + '/' + theme + ' last catalog group remains reachable by scrolling');
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-last-group.png') });
            }
            check(errors.length === 0, name + ' no browser exceptions: ' + errors.join(', '));
            proof.views.push({ name, width, height, themes: ['light', 'dark'], browserErrors: errors });
            await context.close();
        }
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
        const page = await context.newPage();
        for (const profile of ['operator', 'only_stok_read', 'only_users_admin', 'dashboard_only']) {
            await page.goto(fixtures[profile]);
            // users.admin izni Roller kartını bilerek gösterir; Hızlı Maliyet yalnız hizli_maliyet.read/yönetici ile görünür.
            const adminOnlySel = profile === 'only_users_admin' ? '.home-card[href="hizli_maliyet.php"], .home-card[href="cari_kartlari.php"]' : '.home-card[href="hizli_maliyet.php"], .home-card[href="roles.php"], .home-card[href="cari_kartlari.php"]';
            check(await page.locator(adminOnlySel).count() === 0, profile + ' no admin cards');
            check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), profile + ' narrow layout fits');
            if (profile === 'only_stok_read') check(await page.locator('.home-card[href="malzeme_stok.php"]').count() === 1, 'stock-only card remains visible');
            if (profile === 'only_users_admin') check(await page.locator('.home-card[href="users.php"]').count() === 1, 'legitimate non-admin users permission retained');
            if (profile === 'dashboard_only') check(await page.locator('.home-card').count() === 0, 'dashboard permission alone reveals no unauthorized module');
        }
        await context.close();
        proof.checks = checks;
        fs.writeFileSync(path.join(output, 'proof.json'), JSON.stringify(proof, null, 2));
        console.log(JSON.stringify({ ok: true, checks, output }));
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
