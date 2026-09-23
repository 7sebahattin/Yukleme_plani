// =========================================================
// scripts/export_menu_smoke.js — "⬇ Excel İndir ▾" menüsünün GERÇEK
// TARAYICI testi (masaüstü + tablet + mobil).
//
// Neden gerekli: menü mobilde overflow-x:auto olan .rpt-actions içinde
// duruyor; position:absolute bir liste orada kesilir ve seçenekler
// tıklanamaz. PHP/statik testler bunu göremez — yalnız düzen motoru görür.
//
//   php scripts/export_menu_render.php > _test_export_menu.html
//   node scripts/export_menu_smoke.js
//
// Doğrular: tıklayınca liste açılır; iki seçenek (CSV / XLSX) ekranın
// İÇİNDE ve GERÇEKTEN tıklanabilir (üstünde başka öğe yok); bağlantılar
// doğru; ikinci menü açılınca birincisi kapanır; dışarı tıklayınca
// kapanır; mobilde dokunma hedefi ≥44px; yatay taşma yok.
// Playwright yoksa kendini ATLAR (beyan_js_smoke.js ile aynı kural).
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core',
                   '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) {
    console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).');
    process.exit(0);
}

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_export_menu.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_export_menu.html yok. Önce: php scripts/export_menu_render.php > _test_export_menu.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(70)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET',   width: 800,  height: 1000 },
                         { ad: 'MOBİL',    width: 375,  height: 700 }]) {
        console.log(`\n=== ${ekran.ad} ${ekran.width}×${ekran.height} ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const jsHata = [];
        page.on('pageerror', e => jsHata.push(e.message));
        await page.goto('file://' + SAYFA);

        const menuler = await page.$$('.dl-menu');
        ok('4 menü basıldı', menuler.length === 4, String(menuler.length));

        for (const [i, kutu] of [['kutu1', '#kutu1 .dl-menu:nth-of-type(1)'], ['kutu1-detay', '#kutu1 .dl-menu:nth-of-type(2)'],
                                 ['kutu2 (satır solu)', '#kutu2 .dl-menu'], ['kutu3 (sayfa sonu)', '#kutu3 .dl-menu']].entries()) {
            const [ad, sec] = kutu;
            // Önceki menüyü kapat — açık liste alttaki butonu örtebilir (normal açılır menü davranışı)
            await page.evaluate(() => document.body.click());
            const btn = await page.$(sec + ' .dl-menu-btn');
            await btn.scrollIntoViewIfNeeded();
            if (ad.startsWith('kutu3')) await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await page.waitForTimeout(80);
            await btn.click();
            await page.waitForTimeout(60);
            const liste = await page.$(sec + ' .dl-menu-list');
            const gorunur = await liste.isVisible();
            ok(`[${ad}] tıklayınca liste açıldı`, gorunur);
            if (!gorunur) continue;
            const r = await liste.boundingBox();
            ok(`[${ad}] liste ekranın içinde`, r.x >= 0 && r.y >= 0 && r.x + r.width <= ekran.width + 0.5 && r.y + r.height <= ekran.height + 0.5,
               JSON.stringify(r));
            const ogeler = await liste.$$('a');
            ok(`[${ad}] iki seçenek: CSV İndir + XLSX İndir`, ogeler.length === 2
               && (await ogeler[0].innerText()).includes('CSV İndir') && (await ogeler[1].innerText()).includes('XLSX İndir'));
            for (const a of ogeler) {
                const b = await a.boundingBox();
                const ust = await page.evaluate(([x, y]) => {
                    const e = document.elementFromPoint(x, y);
                    return e ? (e.closest('a') ? e.closest('a').getAttribute('href') : e.tagName) : null;
                }, [b.x + b.width / 2, b.y + b.height / 2]);
                const href = await a.getAttribute('href');
                ok(`[${ad}] "${href}" GERÇEKTEN tıklanabilir (üstünde başka öğe yok)`, ust === href, 'noktadaki: ' + ust);
            }
            if (ekran.width < 768) {
                const h = await ogeler[0].boundingBox();
                ok(`[${ad}] mobil dokunma hedefi ≥ 44px`, h.height >= 43.5, String(h.height));
            }
        }

        // Tek menü açık kalır; dışarı tıklayınca kapanır
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.click('#kutu1 .dl-menu:nth-of-type(1) .dl-menu-btn');
        await page.click('#kutu1 .dl-menu:nth-of-type(2) .dl-menu-btn');
        const acik = await page.$$eval('.dl-menu-list', ls => ls.filter(l => !l.hidden).length);
        ok('ikinci menü açılınca birincisi kapandı (aynı anda tek liste)', acik === 1, String(acik));
        await page.mouse.click(5, ekran.height - 5);
        const acik2 = await page.$$eval('.dl-menu-list', ls => ls.filter(l => !l.hidden).length);
        ok('dışarı tıklayınca kapandı', acik2 === 0, String(acik2));

        const tasma = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        ok('yatay taşma yok', tasma <= 0, tasma + 'px');
        ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }

    await browser.close();
    console.log('\n' + (hata === 0 ? 'TÜMÜ GEÇTİ' : `${hata} HATA`));
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
