'use strict';
// Actual PHP POST/redirect + Chromium against the existing in-memory fixture.
// The fixture is recreated per request; no production DB/config is loaded.
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PW_PATH || 'playwright');
const ROOT = path.resolve(__dirname, '..');
const router = path.join(ROOT, `_test_servis_scroll_${process.pid}.php`);
const origin = `http://127.0.0.1:${18800 + process.pid % 1000}`;
fs.writeFileSync(router, `<?php
if (PHP_SAPI !== 'cli-server') exit(1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) return false;
if ($path !== '/gunluk_isci_puantaj_detay.php') { http_response_code(404); exit; }
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
$src = file_get_contents(getenv('SERVIS_SCROLL_ROOT') . '/scripts/pdks_puantaj_dialog_render.php');
$src = str_replace("if (PHP_SAPI !== 'cli')", "if (PHP_SAPI !== 'cli-server')", $src);
$src = str_replace('$ROOT = dirname(__DIR__);', '$ROOT = getenv("SERVIS_SCROLL_ROOT");', $src);
$src = str_replace('$_GET = match ($sayfa)', '$fixtureGet = match ($sayfa)', $src);
$reset = <<<'RESET'
$_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET';
RESET;
$src = str_replace($reset, '$_GET = array_merge($fixtureGet, $_GET);', $src);
$src = preg_replace('/^<\\?php\\s*/', '', $src, 1);
$src = str_replace('declare(strict_types=1);', '', $src);
eval($src);
`);
execFileSync('php', ['-l', router], { stdio: 'pipe' });
let checks = 0;
function check(name, value, detail = '') {
    checks++;
    if (!value) throw new Error(`${name}: ${detail}`);
    console.log(`PASS ${name}`);
}
(async () => {
    const server = spawn('php', ['-S', origin.replace('http://', ''), '-t', ROOT, router], {
        cwd: ROOT, env: { ...process.env, SERVIS_SCROLL_ROOT: ROOT, PUANTAJ_SERVIS: '1' },
        stdio: ['ignore', 'ignore', 'pipe'],
    });
    let errors = '';
    server.stderr.on('data', chunk => errors += chunk);
    let browser;
    try {
        for (let n = 0; ; n++) {
            try {
                const r = await fetch(`${origin}/gunluk_isci_puantaj_detay.php?id=1`);
                if (r.status !== 200) throw new Error(await r.text());
                break;
            } catch (e) {
                if (n > 40) throw new Error(`${e.message}\n${errors}`);
                await new Promise(r => setTimeout(r, 100));
            }
        }
        browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
        for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
            const page = await browser.newPage({ viewport });
            const jsErrors = [];
            page.on('pageerror', e => jsErrors.push(e.message));
            await page.goto(`${origin}/gunluk_isci_puantaj_detay.php?id=1`);
            if (!await page.locator('#servisForm').count()) throw new Error((await page.content()) + '\n' + errors);
            await page.getByRole('button', { name: '🚌 Servis Ücreti', exact: true }).click();
            await page.locator('#servis .sv-arti').first().click();
            const before = await page.evaluate(() => window.scrollY);
            check(`${viewport.width}: test starts above the bottom`, await page.evaluate(() => window.scrollY < document.documentElement.scrollHeight - innerHeight - 100));
            const posted = page.waitForRequest(r => r.method() === 'POST');
            await Promise.all([page.waitForNavigation(), page.locator('#svKaydet').click()]);
            const request = await posted;
            const form = new URLSearchParams(request.postData());
            await page.waitForTimeout(100);
            const after = await page.evaluate(() => window.scrollY);
            check(`${viewport.width}: service add keeps scroll position`, Math.abs(after - before) <= 2, `${before} -> ${after}`);
            check(`${viewport.width}: POST contains original position`, Number(form.get('scroll_y')) === before);
            check(`${viewport.width}: redirect has no list anchor or stale scroll parameter`, !page.url().includes('#servisler') && !page.url().includes('servis_scroll'));
            await page.reload();
            await page.waitForTimeout(100);
            check(`${viewport.width}: reload keeps the current position`, Math.abs(await page.evaluate(() => scrollY) - after) <= 2);

            // Cancel a service from the list: the same position-return behavior.
            await page.locator('[data-sv-iptal]').filter({ visible: true }).first().click();
            await page.locator('#servisIptal textarea').fill('Test iptali');
            const cancelY = await page.evaluate(() => scrollY);
            await Promise.all([page.waitForNavigation(), page.locator('#servisIptal button[type=submit], #servisIptal .btn-danger').first().click()]);
            await page.waitForTimeout(100);
            check(`${viewport.width}: service cancellation keeps position`, Math.abs(await page.evaluate(() => scrollY) - cancelY) <= 2);

            // Backend validation errors also return to the submitted position.
            await page.goto(`${origin}/gunluk_isci_puantaj_detay.php?id=1`);
            await page.evaluate(() => scrollTo(0, 250));
            const errorY = await page.evaluate(() => scrollY);
            await Promise.all([page.waitForNavigation(), page.evaluate(y => {
                const f = document.getElementById('servisForm');
                f.elements.scroll_y.value = String(y);
                f.submit(); // zero services -> actual backend validation rejection
            }, errorY)]);
            await page.waitForTimeout(100);
            check(`${viewport.width}: rejected entry keeps position`, Math.abs(await page.evaluate(() => scrollY) - errorY) <= 2);
            check(`${viewport.width}: no JS errors`, jsErrors.length === 0, jsErrors.join('; '));
            await page.close();
        }
        const response = await fetch(`${origin}/gunluk_isci_puantaj_detay.php?id=1`, {
            method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=servis_ekle&csrf=testcsrf&buyuk=0&kucuk=0&scroll_y=invalid',
        });
        check('invalid scroll input is excluded from the redirect', response.status === 302 && !response.headers.get('location').includes('servis_scroll'));
        console.log(`${checks} checks passed`);
    } finally {
        if (browser) await browser.close();
        server.kill();
        fs.unlinkSync(router);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
