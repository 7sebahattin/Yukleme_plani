// v296-B tarayıcı: Çavuş Toplu Döküm gün grubu renkleri (gerçek style.css + pdks.css, sınıflar sayfayla aynı).
//   node scripts/pdks_v296b_smoke.js   (Playwright yoksa ATLAR)   SHOT_DIR=... ekran görüntüsü
'use strict';
const fs = require('fs'), path = require('path');
let chromium = null;
for (const m of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) { if (!m) continue; try { chromium = require(m).chromium; break; } catch (e) { /* sonrakini dene */ } }
if (!chromium) { console.log('Playwright bulunamadı — ATLANDI.'); process.exit(0); }
const ROOT = path.dirname(__dirname);
let hata = 0; const ok = (a, k, i) => { if (!k) hata++; console.log(a.padEnd(78), k ? 'OK' : '*** HATA ' + (i || '')); };
const gunler = ['15.09.2026', '15.09.2026', '16.09.2026', '17.09.2026', '17.09.2026', '17.09.2026'];
let sonG = null, no = 1;
const satirlar = gunler.map((g, i) => {
  const yeni = g !== sonG; if (yeni) { no = 1 - no; sonG = g; }
  return `<tr class="ctd-click-row ctd-g${no}${yeni ? ' ctd-gyeni' : ''}" tabindex="0"><td><strong>${g}</strong></td><td>Çavuş ${i}</td><td>3</td><td>2</td><td>5</td></tr>`;
}).join('');
const html = (tema) => `<!doctype html><html lang="tr" ${tema ? 'data-theme="dark"' : ''}><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="file://${ROOT}/assets/style.css"><link rel="stylesheet" href="file://${ROOT}/assets/pdks.css"></head><body><main class="container"><h1>Çavuş Toplu Döküm</h1>
<div class="table-wrap"><table class="data-table" id="ctdTable"><thead><tr><th>Tarih</th><th>Çavuş</th><th>Kadın</th><th>Erkek</th><th>Toplam</th></tr></thead><tbody>${satirlar}</tbody></table></div></main></body></html>`;
(async () => {
  const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
  const b = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
  for (const tema of [0, 1]) {
    const f = path.join(ROOT, '_test_v296b.html'); fs.writeFileSync(f, html(tema));
    const page = await b.newPage({ viewport: { width: 1100, height: 600 } });
    await page.goto('file://' + f); await page.waitForTimeout(200);
    console.log(`\n=== ${tema ? 'KOYU' : 'AÇIK'} tema ===`);
    const m = await page.evaluate(() => [...document.querySelectorAll('#ctdTable tbody tr')].map(r => ({ bg: getComputedStyle(r.cells[0]).backgroundColor, ust: getComputedStyle(r.cells[0]).borderTopWidth })));
    ok('aynı tarihli satırlar (15.09) aynı arka plan', m[0].bg === m[1].bg);
    ok('tarih değişince (15→16, 16→17) arka plan farklı', m[1].bg !== m[2].bg && m[2].bg !== m[3].bg);
    ok('17.09 üç satırı aynı arka plan', m[3].bg === m[4].bg && m[4].bg === m[5].bg);
    ok('yeni grubun ilk satırında daha kalın üst çizgi', parseFloat(m[2].ust) >= 2 && parseFloat(m[1].ust) < 2, JSON.stringify([m[1].ust, m[2].ust]));
    await page.hover('#ctdTable tbody tr:nth-child(3)');
    const hv = await page.evaluate(() => { const c = document.querySelector('#ctdTable tbody tr:nth-child(3)').cells[0]; return { bg: getComputedStyle(c).backgroundColor, renk: getComputedStyle(c).color }; });
    ok('hover: arka plan değişir ve yazı rengi okunur kalır', hv.bg !== m[2].bg && hv.renk !== hv.bg, JSON.stringify(hv));
    if (process.env.SHOT_DIR && tema === 0) await page.screenshot({ path: path.join(process.env.SHOT_DIR, 'v296_dokum.png') });
    await page.close(); fs.unlinkSync(f);
  }
  await b.close(); console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.'); process.exit(hata ? 1 : 0);
})();
