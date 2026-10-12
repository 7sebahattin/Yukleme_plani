// =========================================================
// scripts/hks_kunye_secim_smoke.js — Otomatik Dağıt künye önceliği
// (fiyat yakınlığı > eski tarih > küçük kalan > künye no), sıfat uyumsuz
// künyenin atlanması + rozet, gönderim sonucunda sifatUyumsuzKunyeler bilgisi.
//   node scripts/hks_kunye_secim_smoke.js   → çıkış 0 = geçti
// Sunucudaki sıralama algoritmasının JS aynasını denetler. Playwright yoksa ATLAR.
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
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 2, ad: 'Satın Alım' }],
};
const K = (no, tarih, kalan, fiyat, extra) => Object.assign({ kunyeNo: no, tarih, urun: 'Kayısı', kalan, birim: 'KG', fiyat }, extra || {});
// Satış fiyatı 50. fark: A=0, G=0, B=0, C=2, D=5, E=fiyatsız, F=uyumsuz
const VERI = [
  K('KN-D', '2026-01-01', 100, 45),
  K('KN-C', '2026-03-01', 100, 52),
  K('KN-B', '2026-02-01', 500, 50),         // fark 0, G ile aynı tarih, daha büyük kalan
  K('KN-A', '2026-01-15', 800, 50),         // fark 0, en eski → ilk
  K('KN-E', '2025-12-01', 10, 0),           // fiyatsız → fiyat varken sonda
  K('KN-F', '2025-11-01', 5, 50, { sifatUyumsuz: true }),
  K('KN-G', '2026-02-01', 200, 50),         // KN-B ile aynı tarih+fark, daha küçük kalan → B'den önce
];

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
  let kunyeIstek = null, gonderYanit = {}, kunyeVeri = VERI;
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    const j = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'urun_listeleri': return j({ nitelikler: [], uretimSekli: [], birimler: [] });
      case 'iller': return j({ iller: [] });
      case 'taslaklar': return j({ taslaklar: [] });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'kunyeler': kunyeIstek = JSON.parse(r.postData() || '{}'); return j({ kunyeler: JSON.parse(JSON.stringify(kunyeVeri)) });
      case 'kunye_detay': return j({ detaylar: {} });
      case 'stok': return j({ stok: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'kisiler': return j({ kisiler: [] });
      case 'taslak_gonder': return j(gonderYanit);
      default: return j({});
    }
  });

  async function ac() {
    await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    await sayfa.click('#btnEBildirim');
    await sayfa.waitForSelector('#sTur option:nth-child(2)', { state: 'attached' });
    await sayfa.evaluate(() => { document.getElementById('sUrun').value = '1'; });
    await sayfa.evaluate(() => { document.getElementById('sSifat').value = '1'; });
  }
  async function getir() {
    await sayfa.click('#btnKunyeler');
    await sayfa.waitForSelector('#kunyeListe .kunye');
  }
  async function dagit(hedef, fiyat) {
    await sayfa.fill('#hedefKilo', hedef);
    await sayfa.fill('#oFiyat', fiyat);
    await sayfa.click('#btnDagit');
    return sayfa.evaluate(() => {
      const out = [];
      document.querySelectorAll('#kunyeListe .kunye').forEach(d => {
        const cb = d.querySelector('input[type=checkbox]');
        if (cb.checked) out.push([d.querySelector('.no').textContent, d.querySelector('input.miktar').value]);
      });
      return { sec: out, mesaj: document.getElementById('dagitMesaj').textContent, sira: [...document.querySelectorAll('#kunyeListe .no')].map(e => e.textContent) };
    });
  }
  const adlar = (r) => r.sec.map(x => x[0]).sort().join(',');   // küme karşılaştırması: sıra, hedef/miktar kırılımıyla doğrulanır
const mik = (r, no) => (r.sec.find(x => x[0] === no) || [])[1];

  await ac(); await getir();
  ok('/api/kunyeler isteğine sifatId eklendi', kunyeIstek && String(kunyeIstek.sifatId) === '1', JSON.stringify(kunyeIstek));

  // (1) fiyat 50
  let r = await dagit('1000', '50');
  ok('(1) sıra: fark0 → eski tarih → küçük kalan (A, G)', adlar(r) === 'KN-A,KN-G', adlar(r));
  ok('(1) mesaj: fiyata en yakın bilgisi', /fiyata en yakın/.test(r.mesaj) && /\(2 künye\)/.test(r.mesaj), r.mesaj);
  ok('(1) sıfat uyumsuz künye atlandı + mesajda belirtildi', /1 künye sıfat uyumsuz/.test(r.mesaj), r.mesaj);
  ok('(1) gösterim sırası değişmedi', r.sira.join(',') === VERI.map(k => k.kunyeNo).join(','), r.sira.join(','));
  r = await dagit('1500', '50');
  ok('(1b) hedef 1500: A, G, B', adlar(r) === 'KN-A,KN-B,KN-G', adlar(r));
  r = await dagit('1700', '50');
  ok('(1c) hedef 1800: sonra C (fark 2), D (fark 5)', adlar(r) === 'KN-A,KN-B,KN-C,KN-D,KN-G' && mik(r, 'KN-C') === '100', JSON.stringify(r.sec));
  // (3) fiyatsız künye sonda
  r = await dagit('1705', '50');
  ok('(3) fiyatsız künye (E) en sonda seçilir', adlar(r) === 'KN-A,KN-B,KN-C,KN-D,KN-E,KN-G' && mik(r, 'KN-E') === '5', JSON.stringify(r.sec));
  // (4)/(5) uyumsuz künye hiç seçilmez, hedef karşılanamaz
  r = await dagit('100000', '50');
  ok('(4) uyumsuz künye (F) otomatik seçilmedi', !adlar(r).includes('KN-F'), adlar(r));
  ok('(5) hedef karşılanamadı uyarısı + atlanan bilgisi', /KARŞILANAMADI/.test(r.mesaj) && /sıfat uyumsuz/.test(r.mesaj), r.mesaj);
  const rozet = await sayfa.evaluate(() => [...document.querySelectorAll('#kunyeListe .kunye')].map(d => !!d.querySelector('.sifat-uyumsuz')));
  ok('(4) rozet yalnız KN-F satırında', rozet.join() === 'false,false,false,false,false,true,false', rozet.join());
  await sayfa.evaluate(() => { document.querySelectorAll('#kunyeListe .kunye')[5].querySelector('input[type=checkbox]').click(); });
  const elle = await sayfa.evaluate(() => { const d = document.querySelectorAll('#kunyeListe .kunye')[5]; return d.querySelector('input[type=checkbox]').checked && d.querySelector('input.miktar').value; });
  ok('(4) uyumsuz künye elle seçilebilir (miktar = kalan)', elle === '5', String(elle));

  // (2) satış fiyatı boş: tarih → kalan. E(12/2025,10) D(01/01,100) A(01/15,800) G(02/01,200) B(02/01,500) C(03/01)
  r = await dagit('300', '');
  ok('(2) fiyat boş: tarihe göre (E, D, A)', adlar(r) === 'KN-A,KN-D,KN-E' && /fiyat girilmediği için en eski/.test(r.mesaj), adlar(r) + ' | ' + r.mesaj);
  r = await dagit('1100', '');
  ok('(2b) 1100 → E,D,A,G (G kısmi 190; aynı tarihte küçük kalan önce)', adlar(r) === 'KN-A,KN-D,KN-E,KN-G' && mik(r, 'KN-G') === '190', JSON.stringify(r.sec));

  // fark 2 ondalığa yuvarlanır
  kunyeVeri = [K('X1', '2026-02-01', 50, 50.004), K('X2', '2026-01-01', 50, 49.996), K('X3', '2026-03-01', 50, 50.02)];
  await getir();
  r = await dagit('50', '50');
  ok('farklar 2 ondalığa yuvarlanır: eşit fark → eski tarih (X2)', adlar(r) === 'X2', adlar(r));
  kunyeVeri = VERI;

  // Mobil: yatay taşma yok
  await sayfa.setViewportSize({ width: 390, height: 800 });
  await getir();
  const tasma = await sayfa.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok('mobil 390: yatay taşma yok (rozetli satır)', !tasma);

  // (6) sonuç ekranı
  async function gonder(yanit) {
    gonderYanit = yanit;
    await sayfa.evaluate(() => { gonderilecekTaslakId = 'T1'; document.getElementById('btnOnay').click(); });
    await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
    return sayfa.evaluate(() => document.getElementById('modalIcerik').innerText);
  }
  let t = await gonder({ sonuclar: [{ yeniKunyeNo: '999', miktar: 10 }, { miktar: 5, mesaj: 'red' }], sifatUyumsuzKunyeler: ['KN-F', 'KN-<b>X'] });
  ok('(6) sonuçta uyumsuz künye bilgisi', /sıfatla uyumsuz bulundu ve kaydedildi: KN-F, KN-<b>X/.test(t) && /otomatik atlanır/.test(t), t);
  const bold = await sayfa.evaluate(() => document.querySelectorAll('#sifatUyumsuzBilgi b').length);
  ok('(6) kullanıcı verisi HTML olarak yorumlanmaz', bold === 1);
  await sayfa.evaluate(() => { document.getElementById('modal').classList.remove('acik'); });
  t = await gonder({ sonuclar: [{ yeniKunyeNo: '999', miktar: 10 }] });
  ok('(6) alan yokken bilgi satırı yok, akış çalışır', !/uyumsuz/.test(t) && /999/.test(t), t);

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
