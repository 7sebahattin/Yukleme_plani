// =========================================================
// scripts/hks_fiyat_kapisi_smoke.js — v498: fiyat gönderimde girilir (Gönder kapısı)
//   node scripts/hks_fiyat_kapisi_smoke.js   → çıkış 0 = geçti
// halkayit/app.html yerel http sunucusundan servis edilir, api.php sahte JSON ile
// yanıtlanır (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
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
const PS = { urunId: 1, aySayisi: 12, isletmeTuruId: 0, sirala: 'azalan' };
const TASLAKLAR = () => [
  { id: 't-bekler', zaman: '2026-10-06T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [],
    sonFiyat: { deger: 22.5, zaman: '2026-10-01T09:00:00+03:00' },
    ortak: { sifatId: 1, bildirimTuruId: 1, plaka: '34BKL01', urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya',
      planKg: 18000, planSorgu: PS, fiyatBekliyor: true, kaynak: { tip: 'beyan', beyanId: 5, partiNo: 'P1' } } },
  { id: 't-dolu', zaman: '2026-10-06T11:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [],
    ortak: { sifatId: 1, bildirimTuruId: 1, plaka: '06DOL02', urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya',
      fiyat: 20, planKg: 7000, planSorgu: PS } },
  { id: 't-eksik', zaman: '2026-10-06T12:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [],
    sonFiyat: null,
    ortak: { yurtIci: true, tamamlanacak: true, fiyatBekliyor: true, fiyatGonder: true, kayitZorunlu: false,
      bildirimTuruId: 1, turAd: 'Satış', urunId: 1, urunAd: 'Kayısı', plaka: '35EKS03', belgeNo: '', belgeTipiId: 0,
      planKg: 9000, planSorgu: PS, ikinciTc: '1234567890', ikinciAd: 'Örnek Alıcı Ltd',
      kaynak: { tip: 'beyansiz', kayitId: 9, partiNo: '' } } },
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
    D.istekler.push({ action, govde: g, csrfBaslik: r.headers()['x-csrf-token'] });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: FIRMALAR });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: D.taslaklar });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': return j({ stok: [], toplam: 0 });
      case 'kunyeler': return j({ kunyeler: [] });
      case 'ayni_plaka_bugun': return j({ liste: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'kayitli_kisi': return j({ kayitli: true, ad: 'Örnek Alıcı Ltd' });
      case 'isyerleri': return j({ isyerleri: [] });
      case 'taslak_fiyat_yaz': {
        await new Promise(r2 => setTimeout(r2, 300));
        if (D.fiyatHatasi) return j({ hata: 'Taslak bulunamadı' }, 409);
        const t = D.taslaklar.find(x => x.id === g.id);
        if (t) { t.ortak.fiyat = g.fiyat; delete t.ortak.fiyatBekliyor; }
        return j({ tamam: true, id: g.id, fiyat: g.fiyat });
      }
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  return sayfa;
}

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const D = { istekler: [], taslaklar: TASLAKLAR() };
  const sayfa = await sayfaKur(ctx, D, hatalar);
  await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('#taslakListe .taslak-kart');
  const kart = (id) => sayfa.locator(`#taslakListe .taslak-kart:has([data-gonder="${id}"])`);

  // --- Kartlar ---
  const k1 = await kart('t-bekler').innerText();
  ok('fiyatBekliyor kartı: "gönderimde girilecek", NaN/undefined yok', k1.includes('gönderimde girilecek') && !/NaN|undefined/.test(k1), k1);
  ok('fiyatlı kartta fiyat normal', (await kart('t-dolu').innerText()).includes('20 TL/KG'));
  const k3 = await kart('t-eksik').innerText();
  ok('eksik taslak: TAMAMLANACAK + Beyansız yükleme rozetleri', k3.includes('TAMAMLANACAK') && k3.includes('Beyansız yükleme'), k3);
  ok('fiyatlı olmayan kartta rozet yok', !(await kart('t-dolu').innerText()).includes('TAMAMLANACAK'));

  // --- Fiyatsız taslak: Gönder → fiyat penceresi, gönderim YOK ---
  D.istekler.length = 0;
  await sayfa.click('[data-gonder="t-bekler"]');
  await sayfa.waitForSelector('#fiyatPencere.acik');
  ok('Gönder: fiyat penceresi açıldı, onay modalı AÇILMADI', !(await sayfa.evaluate(() => document.getElementById('modal').classList.contains('acik'))));
  ok('Gönder: taslak_gonder isteği YOK', !D.istekler.some(i => i.action === 'taslak_gonder'));
  const z = await sayfa.evaluate(() => ({
    f: +getComputedStyle(document.getElementById('fiyatPencere')).zIndex, m: +getComputedStyle(document.getElementById('modal')).zIndex }));
  ok('fiyat penceresi z-index ≥ 600 ve #modal\'dan düşük değil', z.f >= 600 && z.f >= z.m, JSON.stringify(z));
  const oz = await sayfa.innerText('#fpOzet');
  ok('özet: ürün + plaka + kilo', oz.includes('Kayısı') && oz.includes('34BKL01') && oz.includes('18.000'), oz);
  ok('otomatik doldurma YOK, sonFiyat öneri düğmesi var', (await sayfa.inputValue('#fpFiyat')) === '' && (await sayfa.innerText('#btnFpOneri')).includes('22,5'));
  // ≤0 hata
  await sayfa.fill('#fpFiyat', '0');
  await sayfa.click('#btnFpKaydet');
  ok('0 fiyat: hata gösterilir, istek gitmez', (await sayfa.isVisible('#fpMesaj')) && !D.istekler.some(i => i.action === 'taslak_fiyat_yaz'));
  // öneri
  await sayfa.click('#btnFpOneri');
  ok('öneri düğmesi alanı doldurur', (await sayfa.inputValue('#fpFiyat')) === '22,5');
  // çift tık
  D.istekler.length = 0;
  await sayfa.dblclick('#btnFpKaydet');
  await sayfa.waitForSelector('#modal.acik');
  const fy = D.istekler.filter(i => i.action === 'taslak_fiyat_yaz');
  ok('çift tık: TEK taslak_fiyat_yaz isteği', fy.length === 1, String(fy.length));
  ok('taslak_fiyat_yaz: csrf gövdede + başlıkta, fiyat sayı 22.5',
     fy[0].govde.csrf && fy[0].csrfBaslik && fy[0].govde.fiyat === 22.5 && fy[0].govde.id === 't-bekler' && String(fy[0].govde.firmaId) === '7', JSON.stringify(fy[0]));
  const onay = await sayfa.innerText('#modalIcerik');
  ok('fiyat sonrası Gönderim Onayı açıldı, fiyat 22,5 TL, NaN yok', onay.includes('22,5') && !/NaN|undefined/.test(onay), onay);
  ok('fiyat penceresi kapandı', !(await sayfa.evaluate(() => document.getElementById('fiyatPencere').classList.contains('acik'))));
  ok('onay açılışına kadar taslak_gonder GİTMEDİ', !D.istekler.some(i => i.action === 'taslak_gonder'));
  await sayfa.click('#btnVazgec');

  // --- 409: pencerede hata, liste tazelenir ---
  D.taslaklar = TASLAKLAR(); D.fiyatHatasi = true;
  await sayfa.evaluate(() => taslakEkraniAc()); await sayfa.waitForSelector('#taslakListe .taslak-kart');
  await sayfa.click('[data-gonder="t-bekler"]');
  await sayfa.waitForSelector('#fiyatPencere.acik');
  await sayfa.fill('#fpFiyat', '10');
  D.istekler.length = 0;
  await sayfa.click('#btnFpKaydet');
  await sayfa.waitForTimeout(700);
  ok('409: pencere açık kalır, hata görünür, düğme tekrar açık',
     (await sayfa.isVisible('#fpMesaj')) && (await sayfa.innerText('#fpMesaj')).includes('Taslak bulunamadı') && !(await sayfa.isDisabled('#btnFpKaydet')));
  ok('409: liste tazelendi (taslaklar isteği)', D.istekler.some(i => i.action === 'taslaklar'));
  D.fiyatHatasi = false;
  await sayfa.click('#btnFpIptal');
  ok('Vazgeç pencereyi kapatır', !(await sayfa.evaluate(() => document.getElementById('fiyatPencere').classList.contains('acik'))));

  // --- Fiyatı dolu taslak: pencere YOK, doğrudan onay ---
  D.istekler.length = 0;
  await sayfa.click('[data-gonder="t-dolu"]');
  await sayfa.waitForSelector('#modal.acik');
  ok('fiyatı dolu taslakta fiyat penceresi açılmaz, onay açılır',
     !(await sayfa.evaluate(() => document.getElementById('fiyatPencere').classList.contains('acik'))) && (await sayfa.innerText('#modalIcerik')).includes('20 TL'));
  await sayfa.click('#btnVazgec');

  // --- Tamamlanacak taslak: uyarı + Düzenle ---
  D.istekler.length = 0;
  await sayfa.click('[data-gonder="t-eksik"]');
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(500);
  ok('tamamlanacak: Düzenle ekranı açıldı (çökme yok), onay/fiyat penceresi açılmadı',
     !(await sayfa.evaluate(() => document.getElementById('modal').classList.contains('acik') || document.getElementById('fiyatPencere').classList.contains('acik'))));
  ok('tamamlanacak: taslak_gonder / taslak_fiyat_yaz İSTEĞİ YOK', !D.istekler.some(i => i.action === 'taslak_gonder' || i.action === 'taslak_fiyat_yaz'));
  ok('tamamlanacak: plaka forma yüklendi', (await sayfa.inputValue('#oPlaka')) === '35EKS03', await sayfa.inputValue('#oPlaka'));

  // --- Mobil: pencere ekrana sığar ---
  await sayfa.setViewportSize({ width: 390, height: 700 });
  D.taslaklar = TASLAKLAR();
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('#taslakListe .taslak-kart');
  await sayfa.click('[data-gonder="t-bekler"]');
  await sayfa.waitForSelector('#fiyatPencere.acik');
  await sayfa.waitForTimeout(200);
  const m = await sayfa.evaluate(() => {
    const r = document.querySelector('#fiyatPencere .kisi-dlg').getBoundingClientRect();
    return { l: r.left, r: r.right, vw: innerWidth, tasma: document.documentElement.scrollWidth > innerWidth + 1 };
  });
  ok('390px: fiyat penceresi görünüm içinde, yatay taşma yok', m.l >= 0 && m.r <= m.vw && !m.tasma, JSON.stringify(m));

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTüm testler geçti.');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
