// =========================================================
// scripts/pdks_geri_ptak_smoke.js — v301 GERÇEK TARAYICI testi.
//   PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html
//   node scripts/pdks_geri_ptak_smoke.js
// 1) "← Personel Takibi" düğmesi TURUNCU (açık + koyu tema), beyaz yazı okunur kontrastta.
// 2) Günlük Puantaj tarih kutusu masaüstünde dar/sabit (≤200px), mobilde tam genişlik; yatay taşma yok.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }
const SAYFA = path.join(path.dirname(__dirname), '_test_puantaj_liste.html');
if (!fs.existsSync(SAYFA)) { console.error('_test_puantaj_liste.html yok. Önce render adımını çalıştırın.'); process.exit(1); }

let hata = 0;
function ok(ad, kosul, ipucu) { if (!kosul) hata++; console.log(`${ad.padEnd(76)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`); }
function rgb(s) { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(s || ''); return m ? [+m[1], +m[2], +m[3]] : null; }
function turuncuMu([r, g, b]) { return r > 150 && r > g + 40 && g > b + 20; }   // kırmızı baskın, yeşil orta, mavi düşük
function lum([r, g, b]) { const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); }
function kontrast(a, b) { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); }

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900, masaustu: true }, { ad: 'MOBİL', width: 390, height: 844, masaustu: false }]) {
        for (const tema of ['light', 'dark']) {
            console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) — ${tema} tema ===`);
            const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
            const jsHata = [];
            page.on('pageerror', e => jsHata.push(e.message));
            await page.goto('file://' + SAYFA);
            if (tema === 'dark') await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
            await page.waitForTimeout(300);
            const d = await page.evaluate(() => {
                const a = [...document.querySelectorAll('a.btn-geri')].find(x => /Personel Takibi/.test(x.textContent));
                const t = document.querySelector('.pdks-filter-bar input[type="date"]');
                const cs = a ? getComputedStyle(a) : null;
                // gradient ilk rengi: backgroundImage içindeki ilk rgb
                const bg = cs ? (cs.backgroundImage || '') : '';
                return {
                    var: !!a, bgImg: bg, bgCol: cs ? cs.backgroundColor : '', yazi: cs ? cs.color : '',
                    tarihW: t ? t.getBoundingClientRect().width : -1,
                    barW: t ? t.closest('.pdks-filter-bar').getBoundingClientRect().width : -1,
                    tasma: document.documentElement.scrollWidth > window.innerWidth + 1,
                };
            });
            ok('"← Personel Takibi" düğmesi sayfada var', d.var);
            const renkler = (d.bgImg.match(/rgba?\([^)]+\)/g) || []).map(rgb).filter(Boolean);
            ok('düğme zemini TURUNCU (gradient renklerinin tümü)', renkler.length >= 2 && renkler.every(turuncuMu), d.bgImg);
            const yazi = rgb(d.yazi);
            ok('yazı beyaz', !!yazi && yazi.every(v => v >= 250), d.yazi);
            ok('beyaz yazı / turuncu zemin kontrastı ≥ 3 (kalın/büyük metin eşiği)', renkler.length > 0 && Math.min(...renkler.map(c => kontrast([255, 255, 255], c))) >= 3, d.bgImg);
            if (tema === 'light') {
                if (ekran.masaustu) {
                    ok('tarih kutusu masaüstünde SABİT/dar (≤ 200px)', d.tarihW > 0 && d.tarihW <= 201, String(d.tarihW));
                    ok('tarih kutusu filtre çubuğunun yarısından dar', d.tarihW < d.barW / 2, `${d.tarihW}/${d.barW}`);
                } else {
                    ok('tarih kutusu mobilde TAM genişlik (değişmedi)', d.tarihW >= d.barW - 2, `${d.tarihW}/${d.barW}`);
                }
                ok('yatay taşma yok', d.tasma === false);
            }
            ok('sayfa JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
            await page.close();
        }
    }
    await browser.close();
    console.log(hata === 0 ? '\nSONUÇ: TÜMÜ GEÇTİ' : `\nSONUÇ: ${hata} HATA`);
    process.exit(hata === 0 ? 0 : 1);
})();
