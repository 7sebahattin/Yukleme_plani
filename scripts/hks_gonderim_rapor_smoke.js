// =========================================================
// scripts/hks_gonderim_rapor_smoke.js — Hal Kayıt Gönderim Raporu (v494) tarayıcı testi.
//   node scripts/hks_gonderim_rapor_smoke.js   → çıkış 0 = geçti
// Sayfayı php ile (bellek içi SQLite, sentetik veri) üretir; 1280 / 390 px'te yatay taşma,
// koyu tema, Yazdır düğmesi ve app.html Gönderilenler başlığındaki "📊 Rapor" bağlantısını sınar.
// Playwright yoksa ATLAR.
// =========================================================
'use strict';
const fs = require('fs'), path = require('path'), http = require('http'), cp = require('child_process');
let chromium;
try { chromium = require(process.env.PW_PATH || 'playwright').chromium; }
catch (e) {
  try { chromium = require('/opt/node22/lib/node_modules/playwright').chromium; }
  catch (e2) { console.log('Playwright bulunamadi — test ATLANDI (hata degil).'); process.exit(0); }
}
const KOK = path.dirname(__dirname);
let fail = 0;
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(72) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const render = (qs) => cp.execFileSync(process.env.PHP || 'php', [path.join(KOK, 'scripts', 'hks_gonderim_rapor_render.php')],
  { env: Object.assign({}, process.env, { GR_QS: qs, GR_PERMS: 'hks.access,reports.export', GR_INLINE_CSS: '1' }), encoding: 'utf8' });
const RAPOR = render('bas=2026-10-01&bit=2026-10-20');
const RAPOR_PLAKA = render('bas=2026-10-01&bit=2026-10-20&grup=plaka');

const sunucu = http.createServer((q, s) => {
  s.setHeader('Content-Type', 'text/html; charset=utf-8');
  if (q.url.startsWith('/halkayit/gonderim_rapor.php')) s.end(q.url.includes('grup=plaka') ? RAPOR_PLAKA : RAPOR);
  else if (q.url.startsWith('/halkayit/app.html')) s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const kok = 'http://127.0.0.1:' + sunucu.address().port;
  const tarayici = await chromium.launch();
  try {
    for (const [w, h] of [[1280, 900], [390, 844]]) {
      for (const tema of ['acik', 'koyu']) {
        const ctx = await tarayici.newContext({ viewport: { width: w, height: h } });
        await ctx.addInitScript(t => { try { localStorage.setItem('asya_tema', t); } catch (e) {} }, tema);
        const p = await ctx.newPage();
        const hatalar = [];
        p.on('pageerror', e => hatalar.push(String(e)));
        await p.route('**/*', r => r.request().url().startsWith(kok) ? r.continue() : r.fulfill({ status: 204, body: '' }));
        for (const u of ['/halkayit/gonderim_rapor.php?x=1', '/halkayit/gonderim_rapor.php?grup=plaka']) {
          await p.goto(kok + u);
          const olc = await p.evaluate(() => ({
            sw: document.documentElement.scrollWidth, iw: window.innerWidth,
            ozet: !!document.querySelector('#grOzet'), satir: document.querySelectorAll('#grAyrinti tbody tr').length,
            tema: document.documentElement.getAttribute('data-theme'),
            bg: getComputedStyle(document.querySelector('.gr-kart')).backgroundColor,
            uygula: (() => { const b = document.querySelector('.gr-filtre button[type=submit]'); const r = b.getBoundingClientRect(); return r.width > 0 && r.right <= window.innerWidth + 1; })(),
          }));
          ok(`${w}px ${tema} ${u.includes('plaka') ? 'plaka' : 'ülke'}: yatay sayfa taşması yok (${olc.sw} ≤ ${olc.iw})`, olc.sw <= olc.iw, JSON.stringify(olc));
          ok(`${w}px ${tema}: özet + ayrıntı (10 satır) + Uygula görünür`, olc.ozet && olc.satir === 10 && olc.uygula, JSON.stringify(olc));
          if (tema === 'koyu') ok(`${w}px koyu: data-theme=dark, kart zemini koyu`, olc.tema === 'dark' && !/255, 255, 255/.test(olc.bg), JSON.stringify(olc));
        }
        await p.evaluate(() => { window.__basildi = 0; window.print = () => { window.__basildi++; }; });
        await p.click('[data-gr-yazdir]');
        ok(`${w}px ${tema}: Yazdır düğmesi window.print() çağırır`, await p.evaluate(() => window.__basildi) === 1);
        await p.emulateMedia({ media: 'print' });
        const yaz = await p.evaluate(() => ({
          filtre: getComputedStyle(document.querySelector('.gr-filtre')).display,
          eylem: getComputedStyle(document.querySelector('.gr-eylem')).display,
          ozet: getComputedStyle(document.querySelector('.gr-ozet-metin')).display,
        }));
        ok(`${w}px ${tema} yazdırma: filtre/düğmeler gizli, filtre özeti görünür`, yaz.filtre === 'none' && yaz.eylem === 'none' && yaz.ozet !== 'none', JSON.stringify(yaz));
        ok(`${w}px ${tema}: JS hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
      }
    }

    // app.html — Gönderilenler başlığındaki "📊 Rapor" bağlantısı
    for (const [w, h] of [[1280, 900], [390, 844]]) {
      const ctx = await tarayici.newContext({ viewport: { width: w, height: h } });
      const p = await ctx.newPage();
      await p.route('**/api/**', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{"firmalar":[],"yonetici":false}' }));
      await p.goto(kok + '/halkayit/app.html');
      await p.waitForTimeout(150);
      const s = await p.evaluate(() => {
        document.querySelectorAll('body > div[id^="ekran"]').forEach(e => e.classList.add('gizli'));
        document.getElementById('ekranGonderilen').classList.remove('gizli');
        aktifFirma = { id: 'f1770000000000', ad: 'Firma A' };   // eslint-disable-line no-undef
        const a = document.getElementById('gonderilenRapor');
        a.addEventListener('click', e => e.preventDefault());
        a.click();
        const r = a.getBoundingClientRect(), ust = document.querySelector('#ekranGonderilen .ustbar').getBoundingClientRect();
        return { href: a.getAttribute('href'), target: a.target, gor: r.width > 0 && r.height > 0, sag: r.right, ustSag: ust.right,
                 iw: window.innerWidth, sw: document.documentElement.scrollWidth, metin: a.textContent.trim() };
      });
      ok(`app.html ${w}px: "📊 Rapor" görünür, başlık içinde, taşma yok`, s.gor && s.sag <= s.iw + 1 && s.sw <= s.iw && s.metin === '📊 Rapor', JSON.stringify(s));
      ok(`app.html ${w}px: tık → ?firma=<aktif firma>, target=_top`, s.href === 'gonderim_rapor.php?firma=f1770000000000' && s.target === '_top', JSON.stringify(s));
      await ctx.close();
    }
  } finally {
    await tarayici.close();
    sunucu.close();
  }
  console.log(fail === 0 ? '\nTÜM TESTLER GEÇTİ' : `\n${fail} TEST BAŞARISIZ`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
