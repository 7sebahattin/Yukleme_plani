// =========================================================
// scripts/hks_rusum_borc_smoke.js — Hal Kayıt Rüsum Borcu (v566) tarayıcı testi
// Sayfa hks_rusum_borc_render.php ile GERÇEKTEN render edilir (sahte HKS verisi, ağ yok),
// Playwright ile 390 / 800 / 1280 px ve açık/koyu temada ölçülür. Playwright yoksa atlar.
//   node scripts/hks_rusum_borc_smoke.js
// =========================================================
const { execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
let chromium;
try { ({ chromium } = require('playwright')); } catch (e) { console.log('playwright yok — atlandı'); process.exit(0); }

const render = path.join(__dirname, 'hks_rusum_borc_render.php');
const env = Object.assign({}, process.env, {
  RB_INLINE_CSS: '1', RB_PERMS: 'role.sections.detailed,hks.read,hks.edit', RB_QS: 'firma=f1&sorgula=1&durum=odenmedi',
});
const html = execFileSync('php', [render], { env, encoding: 'utf8' });
const dosya = path.join(os.tmpdir(), 'rb_smoke_' + process.pid + '.html');
fs.writeFileSync(dosya, html);

(async () => {
  const b = await chromium.launch();
  let hata = 0;
  const ok = (ad, c, i = '') => { if (!c) hata++; console.log((c ? 'OK  ' : 'FAIL') + ' ' + ad + (c ? '' : '  ' + i)); };
  const gorunen = p => p.evaluate(() => [...document.querySelectorAll('#rbTablo tbody tr')].filter(t => t.offsetParent !== null).length);
  for (const [ad, w, tema] of [['mobil 390', 390, 'light'], ['tablet 800', 800, 'light'], ['masaüstü 1280', 1280, 'light'], ['mobil koyu 390', 390, 'dark']]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 800 }, colorScheme: tema });
    const p = await ctx.newPage();
    await p.goto('file://' + dosya);
    await p.waitForTimeout(300);
    const o = await p.evaluate(() => ({
      tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      sag: document.querySelector('#rbTablo').getBoundingClientRect().right, vw: window.innerWidth,
      kart: [...document.querySelectorAll('[data-rb-top]')].map(e => e.textContent),
      tutarGorunur: [...document.querySelectorAll('#rbTablo .rb-c-tutar')].every(e => e.getBoundingClientRect().right <= window.innerWidth),
      dugmeGorunur: [...document.querySelectorAll('[data-rb-dugme]')].every(e => e.getBoundingClientRect().right <= window.innerWidth),
      dugmeYuk: Math.round(document.querySelector('[data-rb-dugme]').getBoundingClientRect().height),
    }));
    ok(ad + ': yatay taşma yok', o.tasma <= 0, JSON.stringify(o));
    ok(ad + ': tablo ekrana sığıyor (kutu içinde kaydırma yok)', o.sag <= o.vw + 1, JSON.stringify(o));
    ok(ad + ': Rüsum tutarı ve işaret düğmesi ekranda', o.tutarGorunur && o.dugmeGorunur, JSON.stringify(o));
    ok(ad + ': işaret düğmesi ≥ 36px', o.dugmeYuk >= 36, String(o.dugmeYuk));
    ok(ad + ': özet kartları (toplam 55.413,00 · vadesi geçmiş 53.304,60)', o.kart[0] === '55.413,00' && o.kart[2] === '53.304,60', JSON.stringify(o.kart));
    ok(ad + ': Ödenmemiş görünümünde 3 satır', await gorunen(p) === 3);
    await p.click('[data-rb-filtre="odendi"]');
    ok(ad + ': Ödenmiş filtresi satırları gizler + boş mesajı gösterir', await gorunen(p) === 0 && await p.evaluate(() => !document.querySelector('[data-rb-bos]').hidden));
    await p.click('[data-rb-filtre="hepsi"]');
    ok(ad + ': Hepsi 3 satır', await gorunen(p) === 3);
    await ctx.close();
  }
  // Ödendi işaretleme: yerel http sunucusu sayfayı verir, POST'a sahte başarı döner (file:// fetch yapamaz).
  const http = require('http');
  const post = [];
  const alan = (govde, ad) => { const m = govde.match(new RegExp('name="' + ad + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
  const srv = http.createServer((req, res) => {
    if (req.method === 'POST') {
      let g = ''; req.on('data', c => g += c); req.on('end', () => {
        post.push({ ajax: req.headers['x-requested-with'], govde: g });
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ tamam: true, odendi: alan(g, 'odendi') === '1', odemeTarihi: '2026-11-23' }));
      });
    } else { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html); }
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const url = 'http://127.0.0.1:' + srv.address().port + '/rusum_borc.php';
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const p = await ctx.newPage();
  await p.goto(url);
  await p.waitForTimeout(200);
  await p.locator('#rbTablo tbody tr').first().locator('[data-rb-dugme]').click();
  await p.waitForTimeout(400);
  const s = await p.evaluate(() => ({
    durum: document.querySelector('#rbTablo tbody tr').getAttribute('data-durum'),
    kart: [...document.querySelectorAll('[data-rb-top]')].map(e => e.textContent),
    gorunen: [...document.querySelectorAll('#rbTablo tbody tr')].filter(t => t.offsetParent !== null).length,
  }));
  ok('işaretle: POST AJAX başlığıyla, grup + odendi=1 + csrf ile gider', post.length === 1 && post[0].ajax === 'fetch' && /^0091880872-\d{8}-\d{8}$/.test(alan(post[0].govde, 'grup')) && alan(post[0].govde, 'odendi') === '1' && alan(post[0].govde, 'csrf') === 'x' && alan(post[0].govde, 'firma') === 'f1', JSON.stringify(post));
  ok('işaretle: satır "odendi" olur ve Ödenmemiş görünümünden çıkar (2 satır kalır)', s.durum === 'odendi' && s.gorunen === 2, JSON.stringify(s));
  ok('işaretle: kartlar yerinde güncellenir (ödenmiş 2.000,40 · ödenmemiş 53.412,60)', s.kart[3] === '2.000,40' && s.kart[1] === '53.412,60', JSON.stringify(s.kart));
  await p.click('[data-rb-filtre="odendi"]');
  ok('işaretle: Ödenmiş görünümünde o satır "Geri al" düğmesiyle görünür', await gorunen(p) === 1 && (await p.locator('#rbTablo tbody tr:visible [data-rb-dugme]').textContent()).trim() === 'Geri al');
  srv.close();
  await ctx.close();
  await b.close();
  try { fs.unlinkSync(dosya); } catch (e) {}
  console.log(hata ? hata + ' FAIL' : 'hepsi OK');
  process.exit(hata ? 1 : 0);
})();
