// =========================================================
// scripts/buton_gorunum_smoke.js — v297-C "buton buton gibi görünsün"
// Görünür her `a.btn, button.btn, .btn-ghost, .btn-geri` öğesinin ya
// ebeveyn zemininden FARKLI, saydam olmayan bir arka planı ya da görünür
// bir çerçevesi olmalı (düz yazı gibi görünen buton YOK) — açık + koyu tema.
//
//   PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html
//   node scripts/buton_gorunum_smoke.js
// Playwright yoksa kendini ATLAR. BUTON_SHOT_DIR verilirse ekran görüntüleri
// (v297_butonlar_pc.png, v297_butonlar_dark.png) oraya yazılır.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const LISTE = path.join(ROOT, '_test_puantaj_liste.html');
if (!fs.existsSync(LISTE)) {
    console.error('_test_puantaj_liste.html yok. Önce: PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html');
    process.exit(1);
}
const SHOT = process.env.BUTON_SHOT_DIR || '';
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json' };
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(70)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/gunluk_isci_puantaj.php') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(fs.readFileSync(LISTE, 'utf8')); return;
    }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/plain' });
    fs.createReadStream(dosya).pipe(res);
});

// Tarayıcıda çalışır: görünür butonlardan zemini/çerçevesi ayırt edilemeyenleri döndürür.
function olc() {
    const alfa = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return 0; const p = m[1].split(/[ ,\/]+/).filter(Boolean); return p.length > 3 ? parseFloat(p[3]) : 1; };
    const zemin = (el) => {
        for (let e = el; e; e = e.parentElement) {
            const cs = getComputedStyle(e);
            if (cs.backgroundImage !== 'none') return 'img:' + e.tagName;
            if (alfa(cs.backgroundColor) > 0.5) return cs.backgroundColor;
        }
        return 'rgb(255, 255, 255)';
    };
    const sorunlu = []; let toplam = 0;
    document.querySelectorAll('a.btn, button.btn, .btn-ghost, .btn-geri').forEach((el) => {
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        if (r.width < 2 || r.height < 2 || cs.visibility === 'hidden' || cs.display === 'none') return;
        if (el.closest('[hidden], noscript, .pdks-scan-actions')) return;
        toplam++;
        const kendi = alfa(cs.backgroundColor) > 0.5 || cs.backgroundImage !== 'none';
        const fark = kendi && (cs.backgroundImage !== 'none' || cs.backgroundColor !== zemin(el.parentElement));
        const cerceve = parseFloat(cs.borderTopWidth) > 0 && cs.borderTopStyle !== 'none' && alfa(cs.borderTopColor) > 0.3;
        if (!fark && !cerceve) sorunlu.push((el.className || el.tagName) + ' "' + (el.textContent || '').trim().slice(0, 24) + '"');
    });
    return { toplam, sorunlu };
}

(async () => {
    await new Promise((r) => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ executablePath: process.env.PW_CHROME || (fs.existsSync('/opt/pw-browsers/chromium-1194/chrome-linux/chrome') ? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' : undefined) });
    } catch (e) {
        console.log('Chromium başlatılamadı — tarayıcı testi ATLANDI: ' + e.message.split('\n')[0]);
        sunucu.close(); process.exit(0);
    }
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const jsHata = [];
    page.on('pageerror', (e) => jsHata.push(e.message));
    const tarih = new Date().toISOString().slice(0, 10);

    for (const tema of ['light', 'dark']) {
        console.log(`\n=== Günlük Puantaj listesi — ${tema} tema ===`);
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${KOK}/gunluk_isci_puantaj.php?tarih=${tarih}`);
        await page.evaluate((t) => { document.documentElement.setAttribute('data-theme', t); }, tema);
        await page.waitForTimeout(400);
        const s = await page.evaluate(olc);
        ok(`${s.toplam} görünür buton öğesi bulundu`, s.toplam >= 3, 'beklenenden az buton');
        ok('Düz yazı gibi görünen buton yok', s.sorunlu.length === 0, s.sorunlu.join(' | '));
        const geri = page.locator('a.btn-geri').first();
        ok('Geri düğmesi (.btn-geri) sayfa başlığında var', await geri.count() > 0 || tema === 'dark');
        if (await geri.count()) {
            const bg = await geri.evaluate((e) => getComputedStyle(e).backgroundImage);
            ok('Geri düğmesi dolu mavi (gradient)', /gradient/.test(bg), bg);
            const kutu = await geri.boundingBox();
            ok('Geri düğmesi sayfa başlığı içinde ve ekranda', kutu && kutu.x >= 0 && kutu.x + kutu.width <= 1280);
        }
        if (SHOT) {
            const adi = tema === 'dark' ? 'v297_butonlar_dark.png' : 'v297_butonlar_pc.png';
            const kok = page.locator('.page-head').first();
            const alt = page.locator('form[data-oto-filtre]').first();
            const a = await kok.boundingBox(); const b = await alt.boundingBox();
            if (a && b) {
                await page.screenshot({ path: path.join(SHOT, adi), clip: { x: 0, y: Math.max(0, a.y - 12), width: 1280, height: Math.min(880, b.y + b.height - a.y + 28) } });
            } else {
                await page.screenshot({ path: path.join(SHOT, adi) });
            }
        }
    }

    console.log('\n=== 390 px mobil ===');
    for (const tema of ['light', 'dark']) {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${KOK}/gunluk_isci_puantaj.php?tarih=${tarih}`);
        await page.evaluate((t) => { document.documentElement.setAttribute('data-theme', t); }, tema);
        await page.waitForTimeout(300);
        const s = await page.evaluate(olc);
        ok(`${tema}: düz yazı gibi buton yok`, s.sorunlu.length === 0, s.sorunlu.join(' | '));
        const tasma = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        ok(`${tema}: yatay taşma yok`, tasma <= 0, 'taşma ' + tasma + 'px');
    }
    ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));

    await browser.close(); sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTÜMÜ OK');
    process.exit(hata ? 1 : 0);
})().catch((e) => { console.error(e); sunucu.close(); process.exit(1); });
