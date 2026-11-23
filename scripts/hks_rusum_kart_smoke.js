// =========================================================
// scripts/hks_rusum_kart_smoke.js — Hal Kayıt ana ekranı sağ üst "Rüsum Borcu" kartı (v568)
//   node scripts/hks_rusum_kart_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini sahte JSON ile
// yanıtlar (canlıya/DB'ye/HKS'e dokunmaz). Playwright yoksa ATLAR.
// Doğrular: kart sağ üstte; "Vadesi geçmiş rüsum borcu" + kırmızı tutar + altında KÜÇÜK "Toplam borç";
// önbellek yoksa "Hesaplamak için dokunun"; eski veri notu; hata → "Alınamadı"; tıklayınca firma hazır
// bağlantı (?sorgula=1&firma=); firma değişince kart gizlenir; içerik yetkisi kapalıysa görünmez;
// 360–1280 px yatay taşma yok.
// =========================================================
'use strict';
const fs = require('fs'), path = require('path'), http = require('http');
let chromium;
try { chromium = require(process.env.PW_PATH || 'playwright').chromium; }
catch (e) {
  try { chromium = require('/opt/node22/lib/node_modules/playwright').chromium; }
  catch (e2) { console.log('Playwright bulunamadi — test ATLANDI (hata degil).'); process.exit(0); }
}
const KOK = path.dirname(__dirname);
let fail = 0;
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(92) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }, { id: 8, ad: 'Test Firma B', vergiNo: '2222222222', renk: 'blue' }];
const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

async function sayfaKur(ctx, D, hatalar, icerik) {
  const sayfa = await ctx.newPage();
  sayfa.on('pageerror', e => hatalar.push(e.message));
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    D.istekler.push({ action, govde: g });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: FIRMALAR, icerik: icerik || {} });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j({ urunler: [], ulkeler: [], belgeTipleri: [], sifatlar: [], isletmeTurleri: [], bildirimTurleri: [] });
      case 'taslaklar': return j({ taslaklar: [] });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': return j({ stok: [], toplam: 0 });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'rusum_ozet': return D.ozet(g, j);
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  return sayfa;
}
const kartOku = (sayfa) => sayfa.evaluate(() => {
  const k = document.getElementById('rusumKart');
  const r = k.getBoundingClientRect(), ub = document.querySelector('#ekranMenu .ustbar').getBoundingClientRect();
  const d = document.getElementById('rusumKartGecmis'), a = document.getElementById('rusumKartToplam'), e = k.querySelector('.rk-e');
  return {
    gorunur: k.offsetParent !== null && !k.hidden, etiket: e.textContent, gecmis: d.textContent, toplam: a.textContent, href: k.getAttribute('href'),
    title: k.title, sag: r.right, ubSag: ub.right, sol: r.left, vw: innerWidth,
    gecmisBoyut: parseFloat(getComputedStyle(d).fontSize), toplamBoyut: parseFloat(getComputedStyle(a).fontSize),
    gecmisRenk: getComputedStyle(d).color, ad: document.getElementById('menuFirmaAd').getBoundingClientRect().right,
    tasma: document.documentElement.scrollWidth - innerWidth,
    adKesik: (() => { const e = document.getElementById('menuFirmaAd'); return e.scrollWidth > e.clientWidth + 1; })(),
  };
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });

  // 1) Veri var: vadesi geçmiş 589.625,40 · toplam 662.362,80
  let D = { istekler: [], ozet: (g, j) => j({ ozet: { vadesiGecmis: 589625.4, toplam: 662362.8, zaman: '2026-11-23T14:05:00+03:00', bas: '2026-05-23', bit: '2026-11-23', eski: false } }) };
  let sayfa = await sayfaKur(ctx, D, hatalar);
  ok('firma seçilmeden kart görünmez', await sayfa.evaluate(() => document.getElementById('rusumKart').hidden));
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.waitForFunction(() => document.getElementById('rusumKartGecmis').textContent.includes('TL'));
  let k = await kartOku(sayfa);
  ok('kart görünür; etiket "Vadesi geçmiş rüsum borcu"', k.gorunur && k.etiket === 'Vadesi geçmiş rüsum borcu', JSON.stringify(k));
  ok('ana tutar = vadesi geçmiş borç', k.gecmis === '589.625,40 TL', k.gecmis);
  ok('altında KÜÇÜK yazıyla "Toplam borç: 662.362,80 TL"', k.toplam === 'Toplam borç: 662.362,80 TL' && k.toplamBoyut < k.gecmisBoyut, JSON.stringify([k.toplam, k.toplamBoyut, k.gecmisBoyut]));
  ok('vadesi geçmiş tutar kırmızı', /rgb\(220, 38, 38\)/.test(k.gecmisRenk), k.gecmisRenk);
  ok('kart SAĞ ÜSTTE (üst çubuğun sağ kenarı) ve firma adının sağında', Math.abs(k.sag - k.ubSag) <= 30 && k.sol > k.ad, JSON.stringify(k));
  ok('rusum_ozet isteği firmaId ile gitti (HKS çağrısı değil, önbellek ucu)', D.istekler.some(i => i.action === 'rusum_ozet' && String(i.govde.firmaId) === '7'));
  ok('tıklama bağlantısı: firma hazır + sorgula=1', k.href === 'rusum_borc.php?sorgula=1&firma=7', k.href);
  ok('başlıkta son sorgu zamanı yazar', /Son sorgu: .*2026/.test(k.title), k.title);

  if (process.env.GSHOT) {   // GSHOT=<klasör> ekran görüntüsü kaydeder
    for (const w of [1280, 390]) { await sayfa.setViewportSize({ width: w, height: 600 }); await sayfa.waitForTimeout(150); await sayfa.screenshot({ path: path.join(process.env.GSHOT, 'rusum_kart_' + w + '.png') }); }
    await sayfa.setViewportSize({ width: 1280, height: 800 });
  }

  // 2) Firma değiştirince gizlenir, başka firmaya geçince o firma için yeniden sorulur
  await sayfa.click('#btnFirmaDegis');
  ok('Firmalar\'a dönünce kart gizlenir', await sayfa.evaluate(() => document.getElementById('rusumKart').hidden));
  await sayfa.click('.firma-kart:not(.firma-ekle) >> nth=1');
  await sayfa.waitForFunction(() => document.getElementById('rusumKartGecmis').textContent.includes('TL'));
  k = await kartOku(sayfa);
  ok('ikinci firma: bağlantı firma=8', k.href === 'rusum_borc.php?sorgula=1&firma=8', k.href);
  ok('ikinci firma için rusum_ozet firmaId=8 ile soruldu', D.istekler.some(i => i.action === 'rusum_ozet' && String(i.govde.firmaId) === '8'));

  // 3) Taşma: 360–1280
  for (const w of [360, 390, 768, 900, 1280]) {
    await sayfa.setViewportSize({ width: w, height: 800 });
    await sayfa.waitForTimeout(150);
    const o = await kartOku(sayfa);
    ok(w + 'px: kart ekran içinde, yatay taşma yok', o.sag <= o.vw + 1 && o.sol >= -1 && o.tasma <= 0, JSON.stringify([o.sol, o.sag, o.vw, o.tasma]));
    ok(w + 'px: firma adı kesilmiyor (dar ekranda kart alt satıra iner)', !o.adKesik, JSON.stringify(o));
    if (w < 480) ok(w + 'px: kart yine SAĞA hizalı', Math.abs(o.sag - o.ubSag) <= 30, JSON.stringify([o.sag, o.ubSag]));
  }
  await ctx.close();

  // 4) Önbellek yok
  const ctx2 = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  D = { istekler: [], ozet: (g, j) => j({ ozet: null }) };
  sayfa = await sayfaKur(ctx2, D, hatalar);
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.waitForFunction(() => document.getElementById('rusumKartToplam').textContent !== '');
  k = await kartOku(sayfa);
  ok('önbellek yok: "—" ve "Hesaplamak için dokunun"', k.gecmis === '—' && k.toplam === 'Hesaplamak için dokunun', JSON.stringify([k.gecmis, k.toplam]));
  await ctx2.close();

  // 5) Eski veri + sıfır borç (kırmızı değil)
  const ctx3 = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  D = { istekler: [], ozet: (g, j) => j({ ozet: { vadesiGecmis: 0, toplam: 1500, zaman: '2026-11-01T10:00:00+03:00', bas: '', bit: '', eski: true } }) };
  sayfa = await sayfaKur(ctx3, D, hatalar);
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.waitForFunction(() => document.getElementById('rusumKartGecmis').textContent.includes('TL'));
  k = await kartOku(sayfa);
  ok('vadesi geçmiş 0: "0,00 TL" ve kırmızı DEĞİL', k.gecmis === '0,00 TL' && !/rgb\(220, 38, 38\)/.test(k.gecmisRenk), JSON.stringify([k.gecmis, k.gecmisRenk]));
  ok('eski veri notu', k.toplam === 'Toplam borç: 1.500,00 TL · eski veri', k.toplam);
  await ctx3.close();

  // 6) Sunucu hatası
  const ctx4 = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  D = { istekler: [], ozet: (g, j) => j({ hata: 'sunucu hatası' }, 500) };
  sayfa = await sayfaKur(ctx4, D, hatalar);
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.waitForFunction(() => document.getElementById('rusumKartToplam').textContent !== '');
  k = await kartOku(sayfa);
  ok('hata: "Alınamadı" gösterilir, sayfa bozulmaz', k.gecmis === '—' && k.toplam === 'Alınamadı' && k.gorunur, JSON.stringify([k.gecmis, k.toplam]));
  await ctx4.close();

  // 7) İçerik yetkisi kapalı (ui.hks.rusum_borc = false): kart görünmez, rusum_ozet İSTENMEZ
  const ctx5 = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  D = { istekler: [], ozet: (g, j) => j({ ozet: null }) };
  sayfa = await sayfaKur(ctx5, D, hatalar, { rusum_borc: false });
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.waitForTimeout(400);
  ok('içerik yetkisi kapalı: kart görünmez', !(await sayfa.evaluate(() => { const k = document.getElementById('rusumKart'); return k.offsetParent !== null; })));
  ok('içerik yetkisi kapalı: rusum_ozet isteği yapılmaz', !D.istekler.some(i => i.action === 'rusum_ozet'));
  await ctx5.close();

  ok('sayfada JS hatası yok', hatalar.length === 0, hatalar.join('|'));
  await tarayici.close();
  sunucu.close();
  console.log(fail ? '\n' + fail + ' TEST BASARISIZ' : '\nTUMU GECTI');
  process.exit(fail ? 1 : 0);
})();
