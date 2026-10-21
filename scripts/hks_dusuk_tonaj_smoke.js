// =========================================================
// scripts/hks_dusuk_tonaj_smoke.js — Hal Kayıt taslak kartı düşük tonaj uyarısı (v412)
//   node scripts/hks_dusuk_tonaj_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini
// sahte JSON ile yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
// Doğrular: < 5.000 KG taslakta kartta + onay penceresinde uyarı; 5.000 ve üstünde YOK; 360-1280 taşma yok.
// (eski) her kartta Kopyala düğmesi; Kopyala forma yükler (kopya kipi,
// kendi toast'ı); kayıtta eskiTaslakId GİTMEZ, taslak_sil İSTEĞİ YOK, beyan
// bağı (ortak.kaynak) taşınmaz; Düzenle eskisi gibi (eskiTaslakId + sil);
// 4 düğme 360/390/900/1280 genişlikte taşmaz.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(88) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const LISTELER = {
  urunler: [{ id: 1, ad: 'Kayısı' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }];
const planOrtak = (plaka, kg, ek) => Object.assign({
  sifatId: 1, bildirimTuruId: 1, plaka, urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya', fiyat: 20, planKg: kg,
  planSorgu: { urunId: 1, aySayisi: 12, isletmeTuruId: 0, sirala: 'azalan' },
}, ek || {});
const TASLAKLAR = () => [
  { id: 't-az', zaman: '2026-10-21T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [], ortak: planOrtak('34AZ001', 2199) },
  { id: 't-sinir', zaman: '2026-10-21T11:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [], ortak: planOrtak('34SNR02', 5000) },
  { id: 't-tam', zaman: '2026-10-21T12:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [], ortak: planOrtak('34TAM03', 20199) },
  { id: 't-kunye', zaman: '2026-10-21T13:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A',
    satirlar: [{ kunyeNo: '123', miktar: 4999 }], ortak: planOrtak('34KNY04', 0) },
];

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

async function sayfaKur(ctx, D, hatalar) {
  const sayfa = await ctx.newPage();
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => d.accept());
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    D.istekler.push({ action, govde: g });
    const j = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: FIRMALAR });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: D.taslaklar });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': return j({ stok: [], toplam: 0 });
      case 'kunyeler': return j({ kunyeler: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'taslak_kaydet': return j({ tamam: true, id: 'yeni-t1' });
      case 'taslak_sil': return j({ tamam: true });
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  return sayfa;
}
const taslaklariAc = async (sayfa) => {
  await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('#taslakListe .taslak-kart');
};
const butonOlcu = (sayfa) => sayfa.evaluate(() => {
  const vw = innerWidth, sorun = [];
  document.querySelectorAll('#taslakListe .taslak-kart').forEach((kart, ki) => {
    const kr = kart.getBoundingClientRect();
    kart.querySelectorAll('.tk-butonlar .btn').forEach(b => {
      const r = b.getBoundingClientRect();
      if (r.left < kr.left - 1 || r.right > kr.right + 1) sorun.push('kart' + ki + ' dışına taşıyor: ' + b.textContent);
      if (b.scrollWidth > b.clientWidth + 1) sorun.push('kart' + ki + ' metin kesik: ' + b.textContent);
    });
  });
  if (document.documentElement.scrollWidth > vw + 1) sorun.push('sayfa yatay taşma');
  const sira = [...document.querySelectorAll('#taslakListe .taslak-kart:first-child .tk-butonlar .btn')].map(b => b.textContent.trim());
  return { sorun, sira };
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const D = { istekler: [], taslaklar: TASLAKLAR() };
  const sayfa = await sayfaKur(ctx, D, hatalar);
  await taslaklariAc(sayfa);
  const uyari = (id) => sayfa.$$eval('#taslakListe [data-gonder="' + id + '"]', (b) => {
    const k = b[0].closest('.taslak-kart'); const u = k.querySelector('.tk-uyari');
    return { var: !!u, metin: u ? u.textContent : '', satir: k.querySelectorAll('.tk-satir').length };
  });
  const a = await uyari('t-az'), s = await uyari('t-sinir'), t = await uyari('t-tam'), k = await uyari('t-kunye');
  ok('2.199 KG plan taslağı: kartta uyarı var, 5.000 geçiyor', a.var && a.metin.includes('5.000 KG altı'), a.metin);
  ok('5.000 KG (sınır) uyarısız', !s.var);
  ok('20.199 KG uyarısız', !t.var);
  ok('künye satırlı 4.999 KG taslağı da uyarılır', k.var);
  ok('satır sayısı değişmez (renk dönüşümü kaymaz): 7', a.satir === 7 && t.satir === 7, a.satir + '/' + t.satir);
  // onay penceresi
  await sayfa.click('#taslakListe [data-gonder="t-az"]');
  await sayfa.waitForSelector('#modal.acik');
  ok('Gönderim Onayı: düşük tonaj uyarısı satırı var', await sayfa.isVisible('#modalIcerik .tk-uyari-onay'));
  await sayfa.click('#btnVazgec');
  await sayfa.click('#taslakListe [data-gonder="t-tam"]');
  await sayfa.waitForSelector('#modal.acik');
  ok('Gönderim Onayı: 20.199 KG\'ta uyarı yok', !(await sayfa.isVisible('#modalIcerik .tk-uyari-onay')));
  await sayfa.click('#btnVazgec');
  for (const w of [360, 390, 768, 1280]) {
    await sayfa.setViewportSize({ width: w, height: 800 });
    await sayfa.waitForTimeout(150);
    const o = await butonOlcu(sayfa);
    const tasma = await sayfa.evaluate(() => [...document.querySelectorAll('.tk-uyari')].some(u => u.getBoundingClientRect().right > u.closest('.taslak-kart').getBoundingClientRect().right + 1));
    ok(w + 'px: uyarı kart içinde, yatay taşma yok', o.sorun.length === 0 && !tasma, o.sorun.join('|'));
  }
  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTüm testler geçti.');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
