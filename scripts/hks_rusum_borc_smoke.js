// =========================================================
// scripts/hks_rusum_borc_smoke.js — Hal Kayıt Rüsum Borcu (v567 → v568) tarayıcı testi
// Sayfa hks_rusum_borc_render.php ile GERÇEKTEN render edilir (sahte HKS verisi, ağ yok),
// Playwright ile 390 / 800 / 1280 px ve açık/koyu temada ölçülür. Playwright yoksa atlar.
//   node scripts/hks_rusum_borc_smoke.js
// Kapsam: düzen (taşma yok), Sorgula + Firma aynı satırda, varsayılan son 6 ay, sarı bant YOK,
// süzgeç + toplam satırı, tek ve TOPLU "Ödendi işaretle", sade tek satırlık YAZDIR (kâğıt genişliği
// 768px altında bile tablo düzeni; kartlar/süzgeç/grup kodu gizli).
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
const php6ay = execFileSync('php', ['-r', "echo date('Y-m-d', strtotime(date('Y-m-d') . ' -6 months'));"], { encoding: 'utf8' }).trim();

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
    const o = await p.evaluate(() => {
      const r = s => document.querySelector(s).getBoundingClientRect();
      return {
        tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        sag: r('#rbTablo').right, vw: window.innerWidth,
        kart: [...document.querySelectorAll('[data-rb-top]')].map(e => e.textContent),
        tutarGorunur: [...document.querySelectorAll('#rbTablo .rb-c-tutar')].every(e => e.getBoundingClientRect().right <= window.innerWidth),
        dugmeGorunur: [...document.querySelectorAll('[data-rb-dugme]')].every(e => e.getBoundingClientRect().right <= window.innerWidth),
        dugmeYuk: Math.round(r('[data-rb-dugme]').height),
        firmaUst: Math.round(r('.rb-f-firma select').top), sorgulaUst: Math.round(r('.rb-f-btn .btn').top),
        sorgulaSag: r('.rb-f-btn .btn').right, firmaGen: Math.round(r('.rb-f-firma select').width),
        tarihUst: Math.round(r('input[name="bas"]').top), tarihAlt: r('input[name="bas"]').bottom,
        bas: document.querySelector('input[name="bas"]').value,
        sariBant: document.querySelectorAll('.rb-not').length,
        sadeNot: document.querySelector('.rb-alt-not').textContent.trim(),
        ilkSutunKutu: !!document.querySelector('#rbTablo [data-rb-hepsi]'),
      };
    });
    ok(ad + ': yatay taşma yok', o.tasma <= 0, JSON.stringify(o));
    ok(ad + ': tablo ekrana sığıyor (kutu içinde kaydırma yok)', o.sag <= o.vw + 1, JSON.stringify(o));
    ok(ad + ': Rüsum tutarı ve işaret düğmesi ekranda', o.tutarGorunur && o.dugmeGorunur, JSON.stringify(o));
    ok(ad + ': işaret düğmesi ≥ 36px', o.dugmeYuk >= 36, String(o.dugmeYuk));
    ok(ad + ': özet kartları (toplam 55.413,00 · vadesi geçmiş 53.304,60)', o.kart[0] === '55.413,00' && o.kart[2] === '53.304,60', JSON.stringify(o.kart));
    ok(ad + ': Sorgula düğmesi Firma ile AYNI satırda, ekranda', Math.abs(o.firmaUst - o.sorgulaUst) <= 12 && o.sorgulaSag <= o.vw, JSON.stringify(o));
    ok(ad + ': tarihler Firma/Sorgula satırının altında ya da yanında, taşmadan', o.tarihAlt <= 800 + 400, JSON.stringify(o));
    ok(ad + ': Firma alanı daraltılmış (≤ 360px)', o.firmaGen <= 360, String(o.firmaGen));
    ok(ad + ': varsayılan başlangıç = son 6 ay (' + php6ay + ')', o.bas === php6ay, o.bas);
    ok(ad + ': sarı uyarı bandı yok, yalnız kısa not', o.sariBant === 0 && o.sadeNot === 'Ödemeleri manuel işaretleyiniz.', JSON.stringify({ b: o.sariBant, n: o.sadeNot }));
    ok(ad + ': Ödenmemiş görünümünde 3 satır', await gorunen(p) === 3);
    await p.click('[data-rb-filtre="odendi"]');
    ok(ad + ': Ödenmiş filtresi satırları gizler + boş mesajı gösterir', await gorunen(p) === 0 && await p.evaluate(() => !document.querySelector('[data-rb-bos]').hidden));
    await p.click('[data-rb-filtre="hepsi"]');
    ok(ad + ': Hepsi 3 satır', await gorunen(p) === 3);
    // Seç-hepsi: masaüstünde başlıktaki kutu, mobilde (başlık gizli) ayrı "Görünenlerin tümünü seç" kutusu
    const hepsiSel = w < 768 ? '.rb-hepsi-mobil [data-rb-hepsi]' : '#rbTablo [data-rb-hepsi]';
    ok(ad + ': tümünü-seç kutusu görünür (' + (w < 768 ? 'mobil ayrı kutu' : 'tablo başlığı') + ')', await p.isVisible(hepsiSel));
    await p.locator(hepsiSel).check();
    ok(ad + ': tümünü seç → 3 seçili + araç çubuğu', (await p.locator('[data-rb-secili]').textContent()) === '3' && await p.isVisible('[data-rb-toplu]'));
    await p.locator('[data-rb-toplu-temizle]').click();
    ok(ad + ': seçimi kaldır → araç çubuğu gizlenir', await p.evaluate(() => document.querySelector('[data-rb-toplu]').hidden));
    // Toplam satırı görünen satırları toplar
    const tf = await p.evaluate(() => ({ t: document.querySelector('[data-rb-toplam]').textContent, n: document.querySelector('[data-rb-n]').textContent }));
    ok(ad + ': alt toplam satırı = 55.413,00 (3 gün)', tf.t === '55.413,00' && tf.n === '3', JSON.stringify(tf));
    await ctx.close();
  }

  // ── Yazdır: kâğıt genişliği 768px'in altında (A4 − kenar boşluğu ≈ 700px) ve üstünde ──
  for (const [ad, w] of [['yazdır 700', 700], ['yazdır 1000', 1000]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 } });
    const p = await ctx.newPage();
    await p.goto('file://' + dosya);
    await p.emulateMedia({ media: 'print' });
    await p.waitForTimeout(200);
    const y = await p.evaluate(() => {
      const gorunur = s => [...document.querySelectorAll(s)].some(e => e.offsetParent !== null || getComputedStyle(e).position === 'fixed' && getComputedStyle(e).display !== 'none');
      const tr = [...document.querySelectorAll('#rbTablo tbody tr')].filter(t => t.offsetParent !== null);
      return {
        tabloDisplay: getComputedStyle(document.querySelector('#rbTablo')).display,
        trDisplay: tr.length ? getComputedStyle(tr[0]).display : '',
        satir: tr.length,
        satirYuk: tr.map(t => Math.round(t.getBoundingClientRect().height)),
        sutun: [...document.querySelectorAll('#rbTablo thead th')].filter(t => getComputedStyle(t).display !== 'none').map(t => t.textContent.trim()),
        kartlar: gorunur('#rbKartlar'), filtre: gorunur('.rb-filtre'), seg: gorunur('.rb-seg'), baslikH1: gorunur('.rb-bas'),
        grupKodu: gorunur('#rbTablo .rb-grup'), kutular: gorunur('#rbTablo [data-rb-sec]'), isaretDugme: gorunur('#rbTablo [data-rb-dugme]'),
        altNot: gorunur('.rb-alt-not'), yzBaslik: document.querySelector('.rb-yz-baslik') && getComputedStyle(document.querySelector('.rb-yz')).display !== 'none',
        yzAlt: document.querySelector('.rb-yz-alt').textContent,
        toplamGorunur: getComputedStyle(document.querySelector('#rbTablo tfoot')).display !== 'none',
        tfootMetin: document.querySelector('#rbTablo tfoot').textContent.replace(/\s+/g, ' ').trim(),
        tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        toplamYuk: Math.round(document.querySelector('#rbTablo').getBoundingClientRect().height),
      };
    });
    ok(ad + ': tablo düzeni (kart düzeni yazdırmaya sızmıyor)', y.tabloDisplay === 'table' && y.trDisplay === 'table-row', JSON.stringify(y));
    ok(ad + ': her rüsum TEK satır (yükseklik ≤ 28px)', y.satir === 3 && y.satirYuk.every(h => h > 0 && h <= 28), JSON.stringify(y.satirYuk));
    ok(ad + ': yalnız 4 sütun: Tahakkuk · Son ödeme · Rüsum · Durum', JSON.stringify(y.sutun) === JSON.stringify(['Tahakkuk', 'Son ödeme', 'Rüsum (TL)', 'Durum']), JSON.stringify(y.sutun));
    ok(ad + ': gereksizler gizli (kartlar, süzgeç, düğmeler, grup kodu, seçim kutuları, not, ekran başlığı)',
      !y.kartlar && !y.filtre && !y.seg && !y.baslikH1 && !y.grupKodu && !y.kutular && !y.isaretDugme && !y.altNot, JSON.stringify(y));
    ok(ad + ': yazdırma başlığı + alt satır (firma · aralık · süzgeç)', y.yzBaslik && /Ödenmemiş/.test(y.yzAlt) && /–/.test(y.yzAlt), y.yzAlt);
    ok(ad + ': toplam satırı yazılır', y.toplamGorunur && /55\.413,00/.test(y.tfootMetin), y.tfootMetin);
    ok(ad + ': yatay taşma yok', y.tasma <= 0, String(y.tasma));
    await ctx.close();
  }
  // Süzgeç değişince yazdırma başlığı + toplam da değişir (Hepsi)
  {
    const ctx = await b.newContext({ viewport: { width: 700, height: 900 } });
    const p = await ctx.newPage();
    await p.goto('file://' + dosya);
    await p.click('[data-rb-filtre="hepsi"]');
    await p.emulateMedia({ media: 'print' });
    ok('yazdır: süzgeç "Tümü" olunca başlıkta yazar', /Tümü/.test(await p.locator('.rb-yz-alt').textContent()));
    await ctx.close();
  }

  // ── İşaretleme: tek + toplu (yerel http sunucusu sayfayı verir, POST'a sahte başarı döner; file:// fetch yapamaz) ──
  const http = require('http');
  const post = [];
  const alan = (govde, ad) => { const m = govde.match(new RegExp('name="' + ad + '"\\r\\n\\r\\n([^\\r]*)')); return m ? m[1] : null; };
  const alanlar = (govde, ad) => [...govde.matchAll(new RegExp('name="' + ad.replace('[', '\\[').replace(']', '\\]') + '"\\r\\n\\r\\n([^\\r]*)', 'g'))].map(m => m[1]);
  const srv = http.createServer((req, res) => {
    if (req.method === 'POST') {
      let g = ''; req.on('data', c => g += c); req.on('end', () => {
        post.push({ ajax: req.headers['x-requested-with'], govde: g });
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ tamam: true, odendi: alan(g, 'odendi') === '1', adet: alanlar(g, 'grup[]').length || 1, odemeTarihi: '2026-11-23' }));
      });
    } else if (req.url.startsWith('/rusum_borc.php')) { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(html); }
    else { res.writeHead(404); res.end(); }   // assets/app.js vb. istekler: HTML dönmesin (ayrıştırma hatası yapar)
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const url = 'http://127.0.0.1:' + srv.address().port + '/rusum_borc.php';
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const p = await ctx.newPage();
  const sayfaHata = []; p.on('pageerror', e => sayfaHata.push(e.message));
  await p.goto(url);
  await p.waitForTimeout(200);
  const kartlar = () => p.evaluate(() => [...document.querySelectorAll('[data-rb-top]')].map(e => e.textContent));
  // Tek işaretleme
  await p.locator('#rbTablo tbody tr').first().locator('[data-rb-dugme]').click();
  await p.waitForTimeout(300);
  ok('tek işaretle: POST AJAX başlığıyla, grup + odendi=1 + csrf + firma ile gider', post.length === 1 && post[0].ajax === 'fetch'
    && /^0091880872-\d{8}-\d{8}$/.test(alanlar(post[0].govde, 'grup[]')[0] || '') && alan(post[0].govde, 'odendi') === '1' && alan(post[0].govde, 'csrf') !== null && alan(post[0].govde, 'firma') === 'f1', JSON.stringify(post));
  let s = await p.evaluate(() => ({ durum: document.querySelector('#rbTablo tbody tr').getAttribute('data-durum'), g: [...document.querySelectorAll('#rbTablo tbody tr')].filter(t => t.offsetParent !== null).length }));
  ok('tek işaretle: satır "odendi" olur ve Ödenmemiş görünümünden çıkar (2 satır kalır)', s.durum === 'odendi' && s.g === 2, JSON.stringify(s));
  let k = await kartlar();
  ok('tek işaretle: kartlar yerinde güncellenir (ödenmiş 2.000,40 · ödenmemiş 53.412,60)', k[3] === '2.000,40' && k[1] === '53.412,60', JSON.stringify(k));
  // Toplu seçim: "görünenlerin tümü" → 2 satır, araç çubuğu, tek istekle grup[] ×2
  ok('toplu: seçim yokken araç çubuğu gizli', await p.evaluate(() => document.querySelector('[data-rb-toplu]').hidden));
  await p.locator('#rbTablo [data-rb-hepsi]').check();
  ok('toplu: hepsini seç → 2 seçili, araç çubuğu görünür', (await p.locator('[data-rb-secili]').textContent()) === '2' && await p.isVisible('[data-rb-toplu]'));
  await p.locator('[data-rb-toplu-odendi]').click();
  await p.waitForTimeout(300);
  const son = post[post.length - 1];
  ok('toplu: TEK istek, grup[] ×2 + odendi=1', post.length === 2 && alanlar(son.govde, 'grup[]').length === 2 && alan(son.govde, 'odendi') === '1', JSON.stringify(alanlar(son.govde, 'grup[]')));
  s = await p.evaluate(() => ({ d: [...document.querySelectorAll('#rbTablo tbody tr')].map(t => t.getAttribute('data-durum')), g: [...document.querySelectorAll('#rbTablo tbody tr')].filter(t => t.offsetParent !== null).length }));
  ok('toplu: 3 satırın üçü de "odendi", Ödenmemiş görünümü boşalır', s.d.every(x => x === 'odendi') && s.g === 0, JSON.stringify(s));
  k = await kartlar();
  ok('toplu: kartlar (ödenmiş 55.413,00 · ödenmemiş 0,00 · vadesi geçmiş 0,00)', k[3] === '55.413,00' && k[1] === '0,00' && k[2] === '0,00', JSON.stringify(k));
  ok('toplu: işlem sonrası seçim temizlenir, araç çubuğu gizlenir', await p.evaluate(() => document.querySelector('[data-rb-toplu]').hidden));
  // Toplu geri al (Ödenmiş görünümünde seç → Geri al)
  await p.click('[data-rb-filtre="odendi"]');
  await p.locator('#rbTablo [data-rb-hepsi]').check();
  await p.locator('[data-rb-toplu-geri]').click();
  await p.waitForTimeout(300);
  const geri = post[post.length - 1];
  ok('toplu geri al: odendi=0 ile 3 grup', alan(geri.govde, 'odendi') === '0' && alanlar(geri.govde, 'grup[]').length === 3, JSON.stringify([alan(geri.govde, 'odendi'), alanlar(geri.govde, 'grup[]').length]));
  k = await kartlar();
  ok('toplu geri al: kartlar eski haline (ödenmemiş 55.413,00 · vadesi geçmiş 53.304,60)', k[1] === '55.413,00' && k[2] === '53.304,60' && k[3] === '0,00', JSON.stringify(k));
  // Filtre değişince gizlenen satırın seçimi düşer
  await p.click('[data-rb-filtre="hepsi"]');
  await p.locator('#rbTablo tbody tr').first().locator('[data-rb-sec]').check();
  ok('seçim: 1 seçili', (await p.locator('[data-rb-secili]').textContent()) === '1');
  await p.click('[data-rb-filtre="odendi"]');
  ok('seçim: süzgeçle gizlenen satırın seçimi düşer, araç çubuğu kapanır', await p.evaluate(() => document.querySelector('[data-rb-toplu]').hidden));
  ok('sayfada JS hatası yok', sayfaHata.length === 0, sayfaHata.join('|'));
  srv.close();
  await ctx.close();
  await b.close();
  try { fs.unlinkSync(dosya); } catch (e) {}
  console.log(hata ? hata + ' FAIL' : 'hepsi OK');
  process.exit(hata ? 1 : 0);
})();
