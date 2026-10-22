// =========================================================
// scripts/hks_firma_yonetim_smoke.js — Hal Kayıt firma ekranı: yalnız yönetici (gerçek tarayıcı)
//   node scripts/hks_firma_yonetim_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini sahte JSON ile
// yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(70) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

const XSS = '<img src=x onerror="window.__xss=1">';
const FIRMALAR = [
  { id: 'f1', ad: 'Örnek Firma A', vergiNo: '1111111111', renk: 'teal', userName: 'kullanici_a' },
  { id: 'f2', ad: XSS, vergiNo: '2222222222', renk: 'blue', userName: 'kullanici_b' },
];

// yonetici: true | false | undefined (alan hiç yok)
async function senaryo(tarayici, genislik, yonetici, { firmalar = FIRMALAR, silDurum = 200 } = {}) {
  const ctx = await tarayici.newContext({ viewport: { width: genislik, height: 800 } });
  const sayfa = await ctx.newPage();
  const hatalar = [], istekler = [];
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => d.accept());
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    istekler.push({ action, govde: g });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': { const o = { firmalar }; if (yonetici !== undefined) o.yonetici = yonetici; return j(o); }
      case 'firma_sil': return silDurum === 403 ? j({ hata: 'Firma yönetimi yalnız yöneticiye açıktır.' }, 403) : j({ tamam: true });
      case 'firma_kaydet': return j({ tamam: true });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'taslaklar': case 'gonderilenler': return j({ taslaklar: [], gonderilenler: [] });
      case 'stok': case 'kunyeler': return j({ kunyeler: [], stok: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart');
  return { sayfa, ctx, hatalar, istekler };
}

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const tasma = (sayfa) => sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);

  for (const gen of [1280, 390]) {
    console.log(`── yönetici DEĞİL (yonetici:false) @${gen}px ──`);
    let s = await senaryo(tarayici, gen, false);
    ok('✎ düzenle düğmesi YOK', await s.sayfa.locator('.firma-kart .duzenle').count() === 0);
    ok('"＋ Yeni Firma" kartı YOK', await s.sayfa.locator('.firma-ekle').count() === 0);
    ok('firma kartları çizildi (2)', await s.sayfa.locator('.firma-kart').count() === 2);
    ok('yatay taşma yok', !(await tasma(s.sayfa)));
    ok('firma adındaki HTML kaçışlandı (img yok, xss çalışmadı)', await s.sayfa.locator('.firma-kart img').count() === 0 && !(await s.sayfa.evaluate(() => window.__xss)));
    ok('kaçışlı ad metin olarak görünür', (await s.sayfa.locator('.firma-kart .ad').nth(1).textContent()) === XSS);
    await s.sayfa.click('.firma-kart:nth-child(1)');
    ok('kart tıklaması firma seçer (menü açılır)', await s.sayfa.isVisible('#ekranMenu') && (await s.sayfa.textContent('#menuFirmaAd')) === 'Örnek Firma A');
    await s.sayfa.evaluate(() => firmaFormAc(null));
    ok('savunma: firmaFormAc yönetici değilken modal AÇMAZ', !(await s.sayfa.evaluate(() => document.getElementById('modal').classList.contains('acik'))));
    ok('sayfa hatası yok', s.hatalar.length === 0, s.hatalar.join(' | '));
    await s.ctx.close();

    console.log(`── yonetici alanı hiç yok @${gen}px ──`);
    s = await senaryo(tarayici, gen, undefined);
    ok('alan yoksa yönetici DEĞİL sayılır (✎ ve ＋ yok)', await s.sayfa.locator('.duzenle, .firma-ekle').count() === 0 && await s.sayfa.locator('.firma-kart').count() === 2);
    await s.ctx.close();

    console.log(`── yönetici (yonetici:true) @${gen}px ──`);
    s = await senaryo(tarayici, gen, true);
    ok('✎ düğmesi her firmada var (2)', await s.sayfa.locator('.firma-kart .duzenle').count() === 2);
    ok('"＋ Yeni Firma" kartı var', await s.sayfa.locator('.firma-ekle').count() === 1);
    ok('yatay taşma yok', !(await tasma(s.sayfa)));
    ok('kaçışlı ad (yönetici) güvenli', !(await s.sayfa.evaluate(() => window.__xss)) && await s.sayfa.locator('.firma-kart img').count() === 0);
    await s.sayfa.click('.firma-kart:nth-child(1) .duzenle');
    ok('✎ → düzenleme modalı açılır, Sil görünür', await s.sayfa.isVisible('#modalFirmaKutu') && await s.sayfa.isVisible('#btnFirmaSil'));
    await s.sayfa.click('#btnFirmaVazgec');
    await s.sayfa.click('.firma-ekle');
    ok('＋ → boş modal açılır, Sil gizli', await s.sayfa.isVisible('#modalFirmaKutu') && !(await s.sayfa.isVisible('#btnFirmaSil')));
    await s.sayfa.click('#btnFirmaVazgec');
    ok('yönetici de firma seçebilir', (await s.sayfa.locator('.firma-kart:nth-child(1)').click(), await s.sayfa.isVisible('#ekranMenu')));
    ok('sayfa hatası yok', s.hatalar.length === 0, s.hatalar.join(' | '));
    await s.ctx.close();
  }

  console.log('── boş liste ──');
  let s = await senaryo(tarayici, 390, false, { firmalar: [] }).catch(() => null);
  // firma-kart yok → waitForSelector zaman aşımı; ayrı bekleme
  if (!s) {
    const ctx = await tarayici.newContext({ viewport: { width: 390, height: 800 } });
    const sayfa = await ctx.newPage();
    await sayfa.route('**/api.php*', r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(new URL(r.request().url()).searchParams.get('action') === 'firmalar' ? { firmalar: [], yonetici: false } : {}) }));
    await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
    await sayfa.waitForSelector('.firma-ipucu');
    ok('yönetici değil + firma yok → yönetici ipucu', (await sayfa.textContent('.firma-ipucu')).includes('yönetici ile iletişime geçin'));
    ok('yönetici değil + firma yok → ＋ kartı yok', await sayfa.locator('.firma-ekle').count() === 0);
    await ctx.close();
  }

  console.log('── silme 403 → modalda hata, yakalanmamış reddedilme yok ──');
  s = await senaryo(tarayici, 1280, true, { silDurum: 403 });
  await s.sayfa.click('.firma-kart:nth-child(1) .duzenle');
  await s.sayfa.click('#btnFirmaSil');
  await s.sayfa.waitForTimeout(300);
  ok('modal açık kaldı', await s.sayfa.isVisible('#modalFirmaKutu'));
  ok('hata mesajı görünür', (await s.sayfa.textContent('#firmaFormMesaj')).includes('yöneticiye açıktır'));
  ok('403 (code yok) CSRF tazeleme/tekrar denemesi yapmadı', s.istekler.filter(i => i.action === 'firma_sil').length === 1 && !s.istekler.some(i => i.action === 'csrf'));
  ok('yakalanmamış sayfa hatası yok', s.hatalar.length === 0, s.hatalar.join(' | '));
  await s.ctx.close();

  console.log('── silme başarılı ──');
  s = await senaryo(tarayici, 1280, true);
  await s.sayfa.click('.firma-kart:nth-child(1) .duzenle');
  await s.sayfa.click('#btnFirmaSil');
  await s.sayfa.waitForTimeout(300);
  ok('firma_sil gitti, modal kapandı, liste yenilendi', s.istekler.some(i => i.action === 'firma_sil' && i.govde.id === 'f1') && !(await s.sayfa.isVisible('#modalFirmaKutu')) && s.istekler.filter(i => i.action === 'firmalar').length >= 2);
  await s.ctx.close();

  await tarayici.close(); sunucu.close();
  console.log(fail === 0 ? '\nTUM TESTLER GECTI' : `\n${fail} TEST BASARISIZ`);
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
