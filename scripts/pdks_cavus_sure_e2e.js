// Tarayıcı E2E: tarihli normal çalışma süresi tanımları.
'use strict';

const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const pw = require(process.env.PW_PATH || 'playwright');

const ROOT = path.resolve(__dirname, '..');
const ROUTER = path.join(__dirname, 'pdks_cavus_sure_e2e_server.php');
const DB = path.join(os.tmpdir(), `pdks_cavus_sure_e2e_${process.pid}.sqlite`);
const PORT = 18400 + (process.pid % 1000);
const URL = `http://127.0.0.1:${PORT}`;
const env = { ...process.env, CAVUS_SURE_TEST_DB: DB };
let assertions = 0;
let failures = 0;
const pageErrors = [];
const check = (name, condition, detail = '') => {
    assertions++;
    if (!condition) failures++;
    console.log(`${condition ? 'OK   ' : 'FAIL '} ${name}${condition || !detail ? '' : ` → ${detail}`}`);
};

function sql(query) {
    const out = execFileSync('php', [ROUTER, '--sql', query], { cwd: ROOT, env, stdio: ['ignore', 'pipe', 'pipe'] });
    return JSON.parse(out.toString());
}

function snapshots() {
    return sql('SELECT id, normal_work_minutes_snapshot FROM daily_work_sessions ORDER BY id');
}

async function waitForServer(server) {
    let lastError;
    for (let i = 0; i < 60; i++) {
        if (server.exitCode !== null) throw new Error(`PHP test server exited (${server.exitCode}): ${server.stderrText}`);
        try {
            const response = await fetch(`${URL}/cavus_form.php?id=1`);
            if (response.status < 500) return;
        } catch (e) { lastError = e; }
        await new Promise(resolve => setTimeout(resolve, 150));
    }
    throw new Error(`PHP test server did not start: ${lastError || server.stderrText}`);
}

function observeErrors(page) {
    page.on('pageerror', error => pageErrors.push(error.message));
    page.on('console', message => {
        // Chromium also reports the expected missing default /favicon.ico as
        // a console error; only collect script console errors here.
        if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) pageErrors.push(message.text());
    });
}

async function fillPeriod(page, { hours, from, scope, to = '', reason }) {
    await page.locator('form').filter({ has: page.locator('[name="action"][value="sure_kaydet"]') }).locator('[name="saat"]').fill(hours);
    await page.locator('[name="valid_from"]').fill(from);
    await page.locator('[name="kapsam"]').selectOption(scope);
    if (scope === 'aralik') await page.locator('[name="valid_to"]').fill(to);
    await page.locator('[name="gerekce"]').fill(reason);
}

async function submitPeriod(page) {
    await Promise.all([
        page.waitForURL(/sure_ok=/),
        page.getByRole('button', { name: 'Süre tanımını kaydet ve oturumları yeniden değerlendir' }).click(),
    ]);
}

(async () => {
    let browser;
    let server;
    try {
        execFileSync('php', [ROUTER, '--kur'], { cwd: ROOT, env, stdio: 'pipe' });
        server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', ROOT, ROUTER], { cwd: ROOT, env, stdio: ['ignore', 'ignore', 'pipe'] });
        server.stderrText = '';
        server.stderr.on('data', chunk => { server.stderrText += chunk.toString(); });
        await waitForServer(server);
        browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});

        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await context.newPage();
        observeErrors(page);
        await page.goto(`${URL}/cavus_form.php?id=1`);
        check('form shows the foreman duration section and history below it',
            await page.getByRole('heading', { name: 'Tarihli normal çalışma süresi' }).count() === 1 &&
            await page.getByRole('heading', { name: 'Süre tanımı geçmişi' }).count() === 1);
        const initialScope = await page.locator('[name="kapsam"]').inputValue();
        const endState = await page.locator('[name="valid_to"]').evaluate(el => ({ required: el.required, disabled: el.disabled, hidden: el.closest('label').hidden }));
        check('indefinite scope hides and disables the optional end date', initialScope === 'suresiz' && !endState.required && endState.disabled && endState.hidden, JSON.stringify(endState));

        // Desktop and phone layouts: every active control stays inside its viewport and document width.
        let layout = await page.evaluate(() => ({
            width: document.documentElement.scrollWidth,
            controls: [...document.querySelectorAll('input:not([type=hidden]),select,textarea,button')]
                .filter(el => !el.disabled && el.getClientRects().length)
                .map(el => { const r = el.getBoundingClientRect(); return [r.left, r.right]; }),
        }));
        check('desktop controls fit without horizontal page overflow', layout.width <= 1280 && layout.controls.every(([left, right]) => left >= -1 && right <= 1281), JSON.stringify(layout));
        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(80);
        layout = await page.evaluate(() => ({
            width: document.documentElement.scrollWidth,
            controls: [...document.querySelectorAll('input:not([type=hidden]),select,textarea,button')]
                .filter(el => !el.disabled && el.getClientRects().length)
                .map(el => { const r = el.getBoundingClientRect(); return [r.left, r.right]; }),
        }));
        check('mobile controls fit without horizontal page overflow', layout.width <= 390 && layout.controls.every(([left, right]) => left >= -1 && right <= 391), JSON.stringify(layout));
        await page.setViewportSize({ width: 1280, height: 900 });

        // 1) Indefinite period beginning Jan 16 affects only session 2, inclusive from-date.
        await fillPeriod(page, { hours: '9', from: '2026-01-16', scope: 'suresiz', reason: 'Ocak 16 sonrası anlaşma' });
        await page.locator('[name="kapsam"]').selectOption('aralik');
        const rangeEndState = await page.locator('[name="valid_to"]').evaluate(el => ({ required: el.required, disabled: el.disabled, hidden: el.closest('label').hidden }));
        check('bounded scope reveals a required end date', rangeEndState.required && !rangeEndState.disabled && !rangeEndState.hidden, JSON.stringify(rangeEndState));
        await page.locator('[name="kapsam"]').selectOption('suresiz');
        await submitPeriod(page);
        const successText = await page.locator('.flash-success').innerText();
        check('indefinite Jan 16 period succeeds and reports updated counts', successText.includes('Süre tanımı kaydedildi') && /1 mesai/.test(successText) && /0 taslak hakediş/.test(successText), successText);
        check('Jan 16 start is inclusive and earlier session is unchanged', JSON.stringify(snapshots()) === JSON.stringify([
            { id: 1, normal_work_minutes_snapshot: 600 }, { id: 2, normal_work_minutes_snapshot: 540 },
        ]), JSON.stringify(snapshots()));
        let history = await page.locator('.card').filter({ has: page.getByRole('heading', { name: 'Süre tanımı geçmişi' }) }).innerText();
        check('history shows date, hours, reason and recording user', history.includes('2026-01-16') && history.includes('Süresiz') && history.includes('9 saat') && history.includes('Ocak 16 sonrası anlaşma') && history.includes('Test Kullanıcısı'), history);

        // 2) Bounded Jan 1–15 inclusive and 3) newest overlapping definition wins.
        await fillPeriod(page, { hours: '10', from: '2026-01-01', scope: 'aralik', to: '2026-01-15', reason: 'İlk yarı dönem' });
        await submitPeriod(page);
        check('bounded Jan 1–15 period updates its inclusive session', snapshots()[0].normal_work_minutes_snapshot === 600 && snapshots()[1].normal_work_minutes_snapshot === 540);
        await fillPeriod(page, { hours: '8', from: '2026-01-10', scope: 'aralik', to: '2026-01-12', reason: 'Örtüşen istisna' });
        await submitPeriod(page);
        check('latest overlapping Jan 10–12 period takes precedence', snapshots()[0].normal_work_minutes_snapshot === 480 && snapshots()[1].normal_work_minutes_snapshot === 540, JSON.stringify(snapshots()));

        // Crafted malformed date and scope are rejected without losing submitted fields/reason.
        const badFields = {
            csrf: 'test-csrf', action: 'sure_kaydet', id: '1', saat: '8',
            valid_from: '2026-02-31', valid_to: 'broken-date', kapsam: 'crafted-scope', gerekce: 'Validation fields stay visible',
        };
        const invalidResponse = await context.request.post(`${URL}/cavus_form.php?id=1`, { form: badFields });
        const invalidHtml = await invalidResponse.text();
        check('crafted invalid date and scope are rejected with their input and reason retained', invalidResponse.status() === 200 && invalidHtml.includes('Başlangıç tarihi geçerli bir tarih olmalıdır.') && invalidHtml.includes('Süre kapsamı geçersiz.') && invalidHtml.includes('value="2026-02-31"') && invalidHtml.includes('value="broken-date"') && invalidHtml.includes('Validation fields stay visible'));
        const indefiniteBadDate = await context.request.post(`${URL}/cavus_form.php?id=1`, { form: { ...badFields, kapsam: 'suresiz', valid_from: '2026-13-01' } });
        check('malformed date remains an error for indefinite periods', (await indefiniteBadDate.text()).includes('Başlangıç tarihi geçerli bir tarih olmalıdır.'));

        // The profile submit must leave period history and applied snapshots alone.
        const beforeProfileHistory = sql("SELECT COUNT(*) AS n FROM audit_log WHERE action='normal_sure_donemi' AND module='foremen' AND record_id=1")[0].n;
        await page.goto(`${URL}/cavus_form.php?id=1`);
        await page.locator('[name="notes"]').fill('E2E profil kaydı');
        await Promise.all([page.waitForURL(/ok=/), page.getByRole('button', { name: 'Profili Kaydet' }).click()]);
        const afterProfileHistory = sql("SELECT COUNT(*) AS n FROM audit_log WHERE action='normal_sure_donemi' AND module='foremen' AND record_id=1")[0].n;
        check('profile save leaves period history and session snapshots unchanged', beforeProfileHistory === afterProfileHistory && snapshots()[0].normal_work_minutes_snapshot === 480 && snapshots()[1].normal_work_minutes_snapshot === 540);

        // An HTML payload is shown as escaped history text and never executes.
        await page.goto(`${URL}/cavus_form.php?id=1`);
        let dialogSeen = false;
        page.on('dialog', async dialog => { dialogSeen = true; await dialog.dismiss(); });
        const xss = '<img src=x onerror=alert(1)>';
        await fillPeriod(page, { hours: '9', from: '2026-02-01', scope: 'suresiz', reason: xss });
        await submitPeriod(page);
        const xssHistory = page.locator('.card').filter({ has: page.getByRole('heading', { name: 'Süre tanımı geçmişi' }) });
        check('history escapes a reason containing HTML markup', await xssHistory.locator('img').count() === 0 && (await xssHistory.innerText()).includes(xss) && !dialogSeen);

        // CSRF and permission gates reject direct requests.
        const badCsrf = await context.request.post(`${URL}/cavus_form.php?id=1`, { form: { ...badFields, csrf: 'wrong-token' } });
        check('bad CSRF token is rejected with 403', badCsrf.status() === 403, String(badCsrf.status()));
        const denied = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        await denied.addCookies([{ name: 'deny', value: '1', url: URL }]);
        const permissionResponse = await denied.request.post(`${URL}/cavus_form.php?id=1`, { form: { ...badFields, valid_from: '2026-02-01', kapsam: 'suresiz' } });
        check('foremen permission cookie is enforced with 403', permissionResponse.status() === 403, String(permissionResponse.status()));
        await denied.close();

        // A finalized entitlement blocks a period transaction without partial history/snapshot writes.
        sql("INSERT INTO foreman_daily_entitlements (id,session_id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo,status,calculated_at) VALUES (1,2,1,'Test Çavuş','C003','2026-01-16','Depo B','final','2026-01-16 12:00:00')");
        const beforeFinalSnapshots = JSON.stringify(snapshots());
        const beforeFinalHistory = Number(sql("SELECT COUNT(*) AS n FROM audit_log WHERE action='normal_sure_donemi' AND module='foremen' AND record_id=1")[0].n);
        await page.goto(`${URL}/cavus_form.php?id=1`);
        await fillPeriod(page, { hours: '7', from: '2026-01-16', scope: 'suresiz', reason: 'Final dönemde değişiklik' });
        await page.getByRole('button', { name: 'Süre tanımını kaydet ve oturumları yeniden değerlendir' }).click();
        check('finalized entitlement shows a clear reopen instruction', await page.locator('.flash-error').innerText().then(t => t.includes('Önce ilgili hakedişi yeniden açın')));
        check('finalized rejection preserves history and all session snapshots', JSON.stringify(snapshots()) === beforeFinalSnapshots && Number(sql("SELECT COUNT(*) AS n FROM audit_log WHERE action='normal_sure_donemi' AND module='foremen' AND record_id=1")[0].n) === beforeFinalHistory);

        check('no browser JavaScript or console errors', pageErrors.length === 0, pageErrors.join(' | '));

        if (process.env.CAVUS_SURE_SHOT_DIR) {
            fs.mkdirSync(process.env.CAVUS_SURE_SHOT_DIR, { recursive: true });
            await page.goto(`${URL}/cavus_form.php?id=1`);
            await page.screenshot({ path: path.join(process.env.CAVUS_SURE_SHOT_DIR, 'cavus-sure-final-desktop.png'), fullPage: true });
            await page.setViewportSize({ width: 390, height: 844 });
            await page.screenshot({ path: path.join(process.env.CAVUS_SURE_SHOT_DIR, 'cavus-sure-final-mobile.png'), fullPage: true });
        }
        await context.close();
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) server.kill();
        for (const suffix of ['', '-wal', '-shm']) {
            try { fs.unlinkSync(DB + suffix); } catch (_) {}
        }
    }
    console.log(`\n${assertions} checks, ${failures} failures`);
    process.exitCode = failures ? 1 : 0;
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
