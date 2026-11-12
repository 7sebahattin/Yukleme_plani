'use strict';
// Frugro Siparişler LİSTE (v515 P1) — gerçek PHP render (frugro_ui_render.php) + gerçek tarayıcı (Playwright + Chromium): 3 bölüm, zebra, ⋮ menü,
// ürün kartı, yatay taşma yok (1440 / 820 / 390), yazdır düğmeleri yalnız satırlı bölümde, Yazdır → popup (birim "sipariş/kutu"), WP Paylaş (frugro_paylas.php).
// Canlı DB / ağ YOK: frugro_yazdir.php / frugro_paylas.php yanıtları page.route() ile, sentetik JSON'dan (render çıktısındaki yazdir_acik / paylas_pkl_hazir).
//   node scripts/frugro_liste_smoke.js          (Playwright yoksa kendini ATLAR)
const fs = require('fs'), os = require('os'), path = require('path');
const { spawnSync } = require('child_process');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }
const root = path.resolve(__dirname, '..');
const OUT = fs.mkdtempSync(path.join(os.tmpdir(), 'frg-liste-'));
let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) pass++; else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }
const r = spawnSync('php', ['scripts/frugro_ui_render.php'], { cwd: root, encoding: 'utf8', env: Object.assign({}, process.env, { FRUGRO_UI_OUT: OUT }), maxBuffer: 64 * 1024 * 1024 });
if (r.status !== 0) { console.log('render başarısız: ' + (r.stderr || r.stdout).slice(0, 600)); process.exit(1); }
const json = ad => JSON.parse(fs.readFileSync(path.join(OUT, ad + '.html'), 'utf8'));
const YAZDIR = json('yazdir_acik'), PAYLAS = json('paylas_pkl_hazir');
const JSONY = obj => ({ status: 200, contentType: 'application/json; charset=utf-8', body: JSON.stringify(obj) });

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : { channel: 'chromium' }).catch(() => chromium.launch({}));
    for (const [w, h] of [[1440, 900], [820, 900], [390, 844]]) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce' });
        await ctx.addInitScript(() => { window.print = () => {}; });
        const istek = [];
        await ctx.route('**/frugro_yazdir.php*', route => { istek.push(route.request().url()); return route.fulfill(JSONY(YAZDIR)); });
        await ctx.route('**/frugro_paylas.php*', route => { istek.push(route.request().url()); return route.fulfill(JSONY(PAYLAS)); });
        await ctx.route('**/siparis_paylas.php*', route => { istek.push('YANLIS ' + route.request().url()); return route.fulfill(JSONY(PAYLAS)); });
        await ctx.route('**/halkayit/html2canvas.min.js', route => route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'halkayit/html2canvas.min.js')) }));
        await ctx.route('**/halkayit/jspdf.umd.min.js', route => route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'halkayit/jspdf.umd.min.js')) }));
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        await page.goto('file://' + path.join(OUT, 'liste.html'));
        await page.waitForTimeout(500);
        const t = `[${w}] `;

        ok(t + 'konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        ok(t + 'yatay taşma yok', (await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0);
        const bol = await page.evaluate(() => ['sipAcik', 'sipEvrak', 'sipKapali'].map(id => { const e = document.getElementById(id); return e ? { id, say: e.querySelector('.sip-say').textContent, dugme: e.querySelectorAll('[data-sip-yazdir]').length } : null; }));
        ok(t + 'üç bölüm: İşlem Bekleyen (6) · Evrak (1) · Kapanan (3), her başlıkta 3 düğme', bol.every(Boolean) && bol.map(b => b.say).join() === '6,1,3' && bol.every(b => b.dugme === 3), JSON.stringify(bol));
        const kpi = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-chip')).map(c => c.textContent.replace(/\s+/g, ' ').trim()));
        ok(t + '7 KPI çipi', kpi.length === 7 && /^Açık\s*6/.test(kpi[0]), kpi.join('|'));

        if (w >= 1024) {
            const z = await page.evaluate(() => {
                const bg = e => getComputedStyle(e.querySelector('td')).backgroundColor;
                const ust = Array.from(document.querySelectorAll('section.sip-bolum-acik tbody tr.sip-ust'));
                const alt = ust[0].nextElementSibling;
                return { a: bg(ust[0]), b: bg(ust[1]), c: bg(ust[2]), altAyni: bg(alt) === bg(ust[0]), altClass: alt.classList.contains('sip-urun-tr'), href: alt.getAttribute('data-href') === ust[0].getAttribute('data-href') };
            });
            ok(t + 'ardışık gruplar farklı zemin, grup içi (satır + ürün kartı) aynı', z.a !== z.b && z.a === z.c && z.altAyni && z.altClass && z.href, JSON.stringify(z));
            const k = await page.evaluate(() => {
                const tr = document.querySelector('section.sip-bolum-acik tbody tr.sip-ust');
                return { kart: !!tr.nextElementSibling.querySelector('.sip-urunler'), sutun: document.querySelectorAll('section.sip-bolum-acik thead th').length, colspan: tr.nextElementSibling.firstElementChild.getAttribute('colspan') };
            });
            ok(t + 'ürün kartı satırın hemen altında, colspan = sütun sayısı', k.kart && String(k.sutun) === k.colspan, JSON.stringify(k));
            await page.click('section.sip-bolum-acik tbody tr.sip-ust .pc-kebab');
            const m = await page.evaluate(() => { const d = document.querySelector('section.sip-bolum-acik tbody tr.sip-ust .pc-dropdown'); return { goster: !d.hidden && getComputedStyle(d).display !== 'none', metin: Array.from(d.children).map(x => x.textContent.trim()) }; });
            ok(t + '⋮ menü açılır: Aç / PKL / Invoice / [Evrak ZIP: yalnız PKL varsa] / WP Paylaş', m.goster && ['📂 Aç|📄 PKL|🧾 Invoice|💬 WP Paylaş', '📂 Aç|📄 PKL|🧾 Invoice|🗜 Evrak takımı (ZIP)|💬 WP Paylaş'].includes(m.metin.join('|')), JSON.stringify(m));
            await page.click('.page-head h1');
        } else {
            const c = await page.evaluate(() => ({ kart: document.querySelectorAll('.sip-kartlar .sip-card').length, tablo: getComputedStyle(document.querySelector('table.sip-table').closest('.pc-only')).display, urun: document.querySelectorAll('.sip-card .sip-card-urun .sip-urunler').length }));
            ok(t + 'dar ekran: kartlar görünür, tablo gizli, ürün kartı kartın içinde', c.kart === 10 && c.tablo === 'none' && c.urun >= 8, JSON.stringify(c));
            const f = await page.evaluate(() => getComputedStyle(document.querySelector('.sip-filtre input[type=search]')).fontSize);
            ok(t + 'dar ekran: arama girdisi 16px (iOS zoom yok)', !mobil || f === '16px', f);
        }

        // ▾ Filtre paneli
        await page.click('[data-sip-filtre-toggle]');
        ok(t + '▾ Filtre paneli açılır (Durum / Müşteri / Liman / Çıkış)', await page.evaluate(() => { const p = document.getElementById('sipFiltrePanel'); return p.classList.contains('sip-acik') && ['durum', 'musteri', 'liman', 'bas', 'bit'].every(n => p.querySelector('[name=' + n + ']')); }));
        ok(t + 'Order Log yükle kartı kapalı başlar; açılınca Excel yükle düğmesi görünür', await (async () => {
            const kapali = await page.evaluate(() => !document.querySelector('details.sip-yukle').open);
            await page.click('details.sip-yukle > summary');
            return kapali && await page.evaluate(() => !!document.querySelector('details.sip-yukle').open);
        })());

        // Yazdır → popup
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click('section.sip-bolum-acik [data-sip-yazdir="yazdir"]')]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        await popup.waitForTimeout(400);
        const baslik = await popup.locator('.syz thead th').allTextContents();
        const metin = await popup.evaluate(() => document.body.innerText);
        ok(t + 'yazdırma: sütunlar sunucudan (Çıkış tarihi … Toplam kutu), meta "sipariş"', baslik.join('|') === YAZDIR.kolonlar.map(k => k.l).join('|') && /sipariş/.test(metin) && !/sevkiyat/.test(metin.split('\n').slice(0, 6).join(' ')), baslik.join('|'));
        ok(t + 'yazdırma: istek frugro_yazdir.php?bolum=acik (siparis_yazdir.php DEĞİL)', istek.some(u => /frugro_yazdir\.php.*bolum=acik/.test(u)) && !istek.some(u => /\/siparis_yazdir\.php/.test(u)), istek.join(' '));
        await popup.close();

        // WP Paylaş
        const wp = page.locator('section.sip-bolum-acik [data-sip-wp]').first();
        await page.evaluate(() => { const d = document.querySelector('section.sip-bolum-acik [data-sip-wp]').closest('.pc-dropdown'); if (d) d.hidden = false; });
        await wp.evaluate(b => b.click());
        await page.waitForTimeout(1200);
        ok(t + 'WP Paylaş: istek frugro_paylas.php?id= (siparis_paylas.php DEĞİL)', istek.some(u => /frugro_paylas\.php\?id=\d+/.test(u)) && !istek.some(u => u.startsWith('YANLIS')), istek.join(' '));
        ok(t + 'WP Paylaş sonrası konsol hatası yok, taşma yok', hatalar.length === 0 && (await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)) <= 0, hatalar.join(' | '));
        await ctx.close();
    }
    // tek durum: yalnız satırlı bölümde düğme; boş bölümde YOK
    {
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const page = await ctx.newPage();
        await page.goto('file://' + path.join(OUT, 'liste_tek_durum.html'));
        await page.waitForTimeout(300);
        const d = await page.evaluate(() => ({ bolum: document.querySelectorAll('.sip-bolum-acik, .sip-bolum-evrak, .sip-bolum-kapali').length, sonuc: !!document.getElementById('sipSonuclar'), dugme: document.querySelectorAll('section [data-sip-yazdir]').length }));
        ok('tek durum: düz "Sonuçlar" (bölüm kartı yok), 3 düğme', d.bolum === 0 && d.sonuc && d.dugme === 3, JSON.stringify(d));
        await page.goto('file://' + path.join(OUT, 'liste_arama.html'));
        const e = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-bolum')).map(s => ({ id: s.querySelector('.sip-bolum-bas').id, bos: !!s.querySelector('.sip-bos'), dugme: s.querySelectorAll('[data-sip-yazdir]').length })));
        ok('boş bölümde yazdırma düğmesi YOK, satırlı bölümde 3', e.every(x => x.bos ? x.dugme === 0 : x.dugme === 3), JSON.stringify(e));
        await ctx.close();
    }
    await browser.close();
    console.log(`${pass} geçti, ${fail} başarısız`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.log('HATA ' + e.stack); process.exit(1); });
