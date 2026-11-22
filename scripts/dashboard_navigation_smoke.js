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
            check(await page.locator('.home-card').count() === 58, name + ' all fifty-eight admin destinations are cards');
            check(await page.evaluate(() => {
                const cards = new Set(Array.from(document.querySelectorAll('.home-card')).map(a => a.getAttribute('href')));
                return Array.from(document.querySelectorAll('.sidebar-nav .sidebar-link')).every(a => a.getAttribute('href') === 'index.php' || cards.has(a.getAttribute('href')));
            }), name + ' every sidebar module destination is on the home screen');
            check(await page.locator('.home-card[href="hizli_maliyet.php"] img').evaluate(image => image.complete && image.naturalWidth === 64 && image.getAttribute('src').includes('assets/nav-icons/qcost.svg?v=')), name + ' dollar asset decodes on the home card');
            // v551: akordiyon — Hızlı Maliyet, kapalı Operasyon grubunda; görüntü çözülsün diye grubu aç
            await page.evaluate(() => { const d = document.querySelector('details[data-sb-grup="operasyon"]'); if (d) d.open = true; });
            await page.waitForFunction(() => { const i = document.querySelector('.sidebar-link[href="hizli_maliyet.php"] img'); return i && i.complete && i.naturalWidth > 0; });
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
                await page.locator('.home-card[href="veritabani_islemleri.php"]').scrollIntoViewIfNeeded();
                check(await page.locator('.home-card[href="veritabani_islemleri.php"]').isVisible(), name + '/' + theme + ' last catalog group remains reachable by scrolling');
                check(await page.evaluate(() => { const c = document.querySelectorAll('.home-card'); return c[c.length - 1].getAttribute('href') === 'veritabani_islemleri.php'; }), name + '/' + theme + ' scrolled-to card really is the last card');
                await page.screenshot({ path: path.join(output, name + '-' + theme + '-last-group.png') });
            }
            if (width >= 900) {
                // v548: sidebar alt alanı SABİT (yalnız .sidebar-nav kayar); sürüm çıkış butonunun yanında, .sidebar-user içinde.
                await page.evaluate(() => scrollTo(0, 0));
                const lay = await page.evaluate(() => {
                    const r = sel => { const e = document.querySelector(sel); if (!e) return null; const b = e.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, left: b.left, right: b.right }; };
                    // v551: akordiyon — yalnız aktif grup açık gelir, menü kısa kalır; kaydırma ölçümü için
                    // (açma akordiyonu tetikler → diğerlerini kapatır; bu yüzden menüye uzun dolgu eklenir)
                    const dolgu = document.createElement('div'); dolgu.style.height = '3000px'; document.querySelector('.sidebar-nav').appendChild(dolgu);
                    const nav = document.querySelector('.sidebar-nav');
                    const side = document.querySelector('.desktop-sidebar');
                    const before = r('.sidebar-user');
                    nav.scrollTop = nav.scrollHeight;                    // menüyü sonuna kaydır
                    const after = r('.sidebar-user');
                    return { nav: r('.sidebar-nav'), user: before, userAfter: after, surum: r('.sidebar-user .sidebar-surum'), logout: r('.sidebar-user-logout'),
                        navScrolls: nav.scrollHeight > nav.clientHeight, sideOverflow: getComputedStyle(side).overflowY, sideScrolls: side.scrollHeight > side.clientHeight + 1,
                        vh: innerHeight, surumOutside: document.querySelectorAll('.sidebar-surum').length - document.querySelectorAll('.sidebar-user .sidebar-surum').length };
                });
                check(lay.user && lay.user.bottom <= lay.vh + 1 && lay.user.top >= 0, name + ' sidebar user area stays inside the viewport');
                check(lay.userAfter.top === lay.user.top && lay.userAfter.bottom === lay.user.bottom, name + ' user area does not move when the menu scrolls');
                check(lay.nav.bottom <= lay.user.top + 1, name + ' scrolling menu never overlaps the pinned user area');
                check(lay.navScrolls, name + ' admin menu is long enough to scroll inside .sidebar-nav');
                check(lay.sideOverflow === 'hidden' && !lay.sideScrolls, name + ' sidebar itself does not scroll (only .sidebar-nav)');
                check(lay.surum && lay.logout && lay.surum.top >= lay.user.top - 1 && lay.surum.bottom <= lay.user.bottom + 1 && lay.surumOutside === 0, name + ' version sits inside .sidebar-user');
                check(lay.surum.right <= lay.logout.left + 1 || lay.surum.left >= lay.logout.right - 1, name + ' version does not overlap the logout button');
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
