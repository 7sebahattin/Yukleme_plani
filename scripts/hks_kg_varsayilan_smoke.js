// =========================================================
// scripts/hks_kg_varsayilan_smoke.js — Satın Alım / Üreticiden Sevk Alım'da
// Miktar birimi varsayılanı "Kg" (gerçek tarayıcı, sahte api.php).
//   node scripts/hks_kg_varsayilan_smoke.js   → çıkış 0 = geçti
// Playwright yoksa ATLAR.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(72) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const LISTELER = {
  urunler: [{ id: 1, ad: 'Kayısı' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }, { id: 3, ad: 'Üretici' }],
  isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 2, ad: 'Satın Alım' }, { id: 4, ad: 'Sevk Etme' }],
};
// Kg bilerek ortada ve id'si sıra dışı: id uydurulmadığını gösterir.
const BIRIMLER = [{ id: 11, ad: 'Adet' }, { id: 27, ad: 'Kg' }, { id: 35, ad: 'Bağ' }];
const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const port = sunucu.address().port;
  const tarayici = await chromium.launch();
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 900 } });
  const sayfa = await ctx.newPage();
  const hatalar = [];
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => d.accept());
  let birimler = BIRIMLER;
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    const j = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'urun_listeleri': return j({ nitelikler: [{ id: 1, ad: 'Yurt İçi' }], uretimSekli: [{ id: 1, ad: 'Geleneksel' }], birimler });
      case 'iller': return j({ iller: [] });
      case 'taslaklar': return j({ taslaklar: [] });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': case 'kunyeler': return j({ kunyeler: [], stok: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'kisiler': return j({ kisiler: [] });
      default: return j({});
    }
  });

  const birim = () => sayfa.evaluate(() => { const s = document.getElementById('sMiktarBirim'); const o = s.selectedOptions[0]; return { v: s.value, t: o ? o.textContent : '' }; });
  async function ac() {
    await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    await sayfa.click('#btnEBildirim');
    await sayfa.waitForSelector('#sTur option:nth-child(2)', { state: 'attached' });
  }
  const tur = async (ad) => { await sayfa.selectOption('#sTur', { label: ad }); await sayfa.waitForTimeout(400); };

  await ac();
  let b = await birim();
  ok('Satış (varsayılan tür): otomatik Kg YOK', b.v === '', JSON.stringify(b));
  await tur('Sevk Etme');
  ok('Sevk Etme: otomatik Kg YOK', (await birim()).v === '');

  await tur('Satın Alım');
  b = await birim();
  ok('Satın Alım: Kg seçili (id listeden = 27)', b.v === '27' && b.t === 'Kg', JSON.stringify(b));

  // elle Adet → tür değişip geri gelince ezilmez
  await sayfa.selectOption('#sMiktarBirim', '11');
  await tur('Satış'); await tur('Satın Alım');
  b = await birim();
  ok('elle Adet seçildi: tür döngüsünden sonra EZİLMEZ', b.v === '11', JSON.stringify(b));
  await sayfa.evaluate(() => { document.getElementById('sTur').dispatchEvent(new Event('change')); });
  await sayfa.waitForTimeout(200);
  ok('elle Adet: yeniden uygulama (change) ezmez', (await birim()).v === '11');

  // Satış'ta yeni otomatik atama yok (temiz formda Satış'a dönünce de boş)
  await ac();
  await tur('Satış');
  ok('Satış: otomatik Kg YOK (Satın Alım\'a hiç girilmedi)', (await birim()).v === '');

  // Kg listede yoksa dokunulmaz
  birimler = [{ id: 11, ad: 'Adet' }, { id: 35, ad: 'Bağ' }];
  await ac(); await tur('Satın Alım');
  ok('listede Kg yok: boş kalır', (await birim()).v === '');

  // Ad eşleşmesi harf duyarsız, id listeden
  birimler = [{ id: 5, ad: 'KG' }, { id: 6, ad: 'Adet' }];
  await ac(); await tur('Satın Alım');
  ok('ad eşleşmesi büyük/küçük harf duyarsız (KG → id 5)', (await birim()).v === '5');

  // Seçili/yüklenmiş birim turDegisti() ile ezilmez
  birimler = BIRIMLER;
  await ac(); await tur('Satın Alım');
  await sayfa.selectOption('#sMiktarBirim', '35');
  await sayfa.evaluate(() => { turDegisti(); });
  await sayfa.waitForTimeout(200);
  ok('seçili/yüklenmiş Bağ: turDegisti() ezmez', (await birim()).v === '35');

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
