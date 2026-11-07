// =========================================================
// scripts/beyansiz_yukleme_modal_smoke.js — "Yükleme Bildir" penceresi (v498)
//   node scripts/beyansiz_yukleme_modal_smoke.js   → çıkış 0 = geçti
// Pencereyi php ile minimal sayfaya basar (scripts/beyansiz_yukleme_modal_render.php),
// gerçek assets/style.css + app.js ile yerel http sunucusundan servis eder; API
// isteklerini sahte JSON ile yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
// Doğrular: açılış + hazırla; alanlar dolmadan Kaydet pasif; yurt dışı/içi geçişi;
// plaka boşluksuz+büyük; plaka_kontrol bandı; aynı-plaka 409 → "Yine de bildir"
// plakaOnay'lı yeniden gönderim (aynı istek_kodu); çift tıklamada tek istek;
// gövdede ad/TC YOK; tablo kurulu değil → açıklayıcı hata (+admin bağlantısı);
// 390px'te yatay taşma yok ve inputlar 16px.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(86) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };
const render = (...a) => cp.execFileSync('php', [path.join(KOK, 'scripts', 'beyansiz_yukleme_modal_render.php'), ...a], { encoding: 'utf8' });
const HTML = { user: render(), admin: render('admin') };

const HAZIR = {
  tabloVar: true, katalogYok: false,
  isletmeTurleri: [{ id: '30', ad: 'Depo' }, { id: '31', ad: 'Şube' }],
  firmalar: [{ id: 'f1', ad: 'ASYA FRESH' }, { id: 'f2', ad: 'DİĞER' }],
  ulkeler: [{ id: '20', ad: 'Rusya' }, { id: '21', ad: 'Almanya' }],
  urunler: [{ id: '10', ad: 'Kayısı' }, { id: '11', ad: 'Kiraz' }],
  kisiler: [{ id: 5, ad: 'ALİ VELİ' }],
};
let mod = 'user';
const sunucu = http.createServer((q, s) => {
  const u = q.url.split('?')[0];
  if (u === '/' || u === '/index.html') { s.setHeader('Content-Type', 'text/html; charset=utf-8'); s.end(HTML[mod]); return; }
  if (u.startsWith('/assets/')) {
    const f = path.join(KOK, u);
    if (fs.existsSync(f)) { s.setHeader('Content-Type', u.endsWith('.css') ? 'text/css' : 'application/javascript'); s.end(fs.readFileSync(f)); return; }
  }
  if (u.startsWith('/beyanlar.php')) { s.setHeader('Content-Type', 'text/html'); s.end('<!doctype html><title>liste</title>'); return; }
  s.statusCode = 404; s.end();
});

async function kur(browser, w, hazir) {
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
  const sayfa = await ctx.newPage();
  const D = { istekler: [], hatalar: [], dialoglar: [], olusturCevap: null, gecikme: 0 };
  sayfa.on('pageerror', e => D.hatalar.push(e.message));
  sayfa.on('dialog', d => { D.dialoglar.push(d.message()); d.accept(); });
  await sayfa.route('**/api_beyansiz_yukleme.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    D.istekler.push({ action, govde: g });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    if (action === 'hazirla') return j(hazir || HAZIR);
    if (action === 'plaka_kontrol') return j({ plaka: g.plaka, liste: D.plakaListe || [] });
    if (action === 'olustur') {
      if (D.gecikme) await new Promise(r2 => setTimeout(r2, D.gecikme));
      const c = D.olusturCevap ? D.olusturCevap(g) : { st: 200, j: { tamam: true, mesaj: 'oluştu' } };
      return j(c.j, c.st);
    }
    return j({ hata: 'bilinmeyen' }, 400);
  });
  return { ctx, sayfa, D };
}
async function doldur(sayfa, o) {
  if (o.firma !== '') await sayfa.selectOption('#bszFirma', o.firma || 'f1');
  if (o.ulke) { await sayfa.fill('#bszUlke + input, input[list="bszUlkeListe"]', o.ulke); }
  if (o.urun) { await sayfa.fill('input[list="bszUrunListe"]', o.urun); }
  if (o.kg) await sayfa.fill('#bszKg', o.kg);
  if (o.plaka) { await sayfa.fill('#bszPlaka', o.plaka); await sayfa.dispatchEvent('#bszPlaka', 'change'); }
}

(async () => {
  await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
  const BASE = 'http://127.0.0.1:' + sunucu.address().port + '/';
  const browser = await chromium.launch();
  try {
    // ── 1) Açılış + form ─────────────────────────────────────
    let { ctx, sayfa, D } = await kur(browser, 1280);
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszIcerik:not([hidden])');
    ok('pencere açıldı, hazırla çağrıldı', D.istekler.filter(i => i.action === 'hazirla').length === 1);
    ok('overlay z-index ≥ 600', Number(await sayfa.$eval('#bszOverlay', e => getComputedStyle(e).zIndex)) >= 600);
    ok('firma listesi dolu, HİÇBİRİ seçili gelmez ("Firma seçin")', (await sayfa.$$eval('#bszFirma option', o => o.length)) === 3 && (await sayfa.$eval('#bszFirma', e => e.value)) === '' && (await sayfa.$eval('#bszFirma option', o => o.textContent)) === 'Firma seçin');
    ok('Kaydet pasif (alanlar boş)', await sayfa.$eval('#bszKaydet', e => e.disabled));
    ok('yurt dışı varsayılan: ülke görünür, kişi gizli', await sayfa.$eval('#bszUlkeKap', e => !e.hidden) && await sayfa.$eval('#bszKisiKap', e => e.hidden));
    ok('fiyat alanı YOK', (await sayfa.$$('#bszIcerik input[id*="iyat"]')).length === 0);
    await doldur(sayfa, { firma: '', ulke: 'Rusya', urun: 'Kayısı', kg: '20000', plaka: '34 abc 123' });
    ok('firma seçilmeden Kaydet pasif', await sayfa.$eval('#bszKaydet', e => e.disabled));
    ok('Net KG yazarken binlik nokta alır (20000 → 20.000)', await sayfa.$eval('#bszKg', e => e.value) === '20.000');
    await sayfa.selectOption('#bszFirma', 'f1');
    ok('plaka boşluksuz', (await sayfa.$eval('#bszPlaka', e => e.value)).replace(/\s/g, '') === await sayfa.$eval('#bszPlaka', e => e.value));
    ok('plaka büyük harf', await sayfa.$eval('#bszPlaka', e => e.value) === '34ABC123');
    ok('alanlar dolunca Kaydet aktif', await sayfa.$eval('#bszKaydet', e => !e.disabled));
    ok('plaka_kontrol çağrıldı', D.istekler.some(i => i.action === 'plaka_kontrol' && i.govde.plaka === '34ABC123'));

    // ── 2) Çift tıklama: tek istek ───────────────────────────
    D.gecikme = 400;
    D.olusturCevap = () => ({ st: 200, j: { tamam: true, mesaj: 'TASLAK ok' } });
    await sayfa.dblclick('#bszKaydet');
    await sayfa.waitForURL(/beyanlar\.php\?sekme=beyansiz/, { timeout: 5000 });
    const olusturlar = D.istekler.filter(i => i.action === 'olustur');
    ok('çift tıklamada TEK olustur isteği', olusturlar.length === 1, String(olusturlar.length));
    const g = olusturlar[0].govde;
    ok('gövde: csrf + istek_kodu + id\'ler, ad/TC YOK', g.csrf === 'tok-test' && /^b[a-z0-9]{10,}$/.test(g.istek_kodu) && g.firmaId === 'f1' && g.ulkeId === '20' && g.urunId === '10' && g.plaka === '34ABC123'
       && !('urunAd' in g) && !('ulkeAd' in g) && !('firmaAd' in g) && !('tc' in g) && g.yon === 'yurt_disi' && g.plakaOnay === false, JSON.stringify(g));
    ok('başarı mesajı gösterildi + liste sekmesine gidildi', D.dialoglar.some(m => m.includes('TASLAK ok')));
    ok('sayfa hatası yok', D.hatalar.length === 0, D.hatalar.join('|'));
    await ctx.close();

    // ── 3) Aynı plaka: 409 → Yine de bildir (aynı istek_kodu) ──
    ({ ctx, sayfa, D } = await kur(browser, 1280));
    D.plakaListe = [{ saat: '09:15', urun: 'Kayısı', kg: 18000, firma: 'ASYA FRESH' }];
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszIcerik:not([hidden])');
    await doldur(sayfa, { ulke: 'Almanya', urun: 'Kiraz', kg: '1500', plaka: '06XYZ99' });
    await sayfa.waitForSelector('#bszPlakaBand:not([hidden])');
    ok('uyarı bandı: bugün bu plakaya N kayıt', (await sayfa.textContent('#bszPlakaMetin')).includes('Bugün bu plakaya 1 kayıt'));
    D.olusturCevap = (g2) => g2.plakaOnay ? { st: 200, j: { tamam: true, mesaj: 'onaylı oluştu' } }
                                            : { st: 409, j: { hata: 'Bugün', kod: 'ayni_plaka', liste: D.plakaListe } };
    await sayfa.click('#bszKaydet');
    await sayfa.waitForFunction(() => document.querySelectorAll('#bszPlakaBand:not([hidden])').length === 1);
    await sayfa.waitForTimeout(150);
    await sayfa.click('#bszYineDe');
    await sayfa.waitForURL(/beyanlar\.php\?sekme=beyansiz/, { timeout: 5000 });
    const ol = D.istekler.filter(i => i.action === 'olustur');
    ok('önce onaysız (409), sonra plakaOnay:true', ol.length === 2 && ol[0].govde.plakaOnay === false && ol[1].govde.plakaOnay === true, JSON.stringify(ol.map(x => x.govde.plakaOnay)));
    ok('iki istekte AYNI istek_kodu', ol[0].govde.istek_kodu === ol[1].govde.istek_kodu);
    await ctx.close();

    // ── 4) Yurt içi ──────────────────────────────────────────
    ({ ctx, sayfa, D } = await kur(browser, 1280));
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszIcerik:not([hidden])');
    await sayfa.check('input[name="bszYon"][value="yurt_ici"]');
    ok('yurt içi: kişi + işletme türü görünür, ülke gizli', await sayfa.$eval('#bszKisiKap', e => !e.hidden) && await sayfa.$eval('#bszIscKap', e => !e.hidden) && await sayfa.$eval('#bszUlkeKap', e => e.hidden));
    ok('yurt dışında işletme türü ve kişi gizli (yalnız ülke)', await (async () => { await sayfa.check('input[name="bszYon"][value="yurt_disi"]'); const r = await sayfa.$eval('#bszIscKap', e => e.hidden) && await sayfa.$eval('#bszKisiKap', e => e.hidden) && !(await sayfa.$eval('#bszUlkeKap', e => e.hidden)); await sayfa.check('input[name="bszYon"][value="yurt_ici"]'); return r; })());
    await doldur(sayfa, { urun: 'Kayısı', kg: '500', plaka: '35IC1' });
    ok('kişi seçilmeden Kaydet pasif', await sayfa.$eval('#bszKaydet', e => e.disabled));
    await sayfa.fill('input[list="bszKisiListe"]', 'ALİ VELİ');
    ok('işletme türü seçilmeden Kaydet pasif', await sayfa.$eval('#bszKaydet', e => e.disabled));
    await sayfa.selectOption('#bszIsc', '30');
    ok('kişi + işletme türü seçilince Kaydet aktif', await sayfa.$eval('#bszKaydet', e => !e.disabled));
    await sayfa.click('#bszKaydet');
    await sayfa.waitForURL(/beyanlar\.php\?sekme=beyansiz/, { timeout: 5000 });
    const gi = D.istekler.filter(i => i.action === 'olustur')[0].govde;
    ok('yurt içi gövde: yon + kisiId + isletmeTuruId, ulkeId boş', gi.yon === 'yurt_ici' && gi.kisiId === '5' && gi.isletmeTuruId === '30' && gi.ulkeId === '' && gi.kg === 500, JSON.stringify(gi));
    await ctx.close();

    // ── 5) Kurulu değil / katalog yok ────────────────────────
    ({ ctx, sayfa, D } = await kur(browser, 1280, Object.assign({}, HAZIR, { tabloVar: false })));
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszHata:not([hidden])');
    ok('tablo yok: açıklayıcı hata, form açılmaz', (await sayfa.textContent('#bszHata')).includes('kurulu değil') && await sayfa.$eval('#bszIcerik', e => e.hidden));
    await ctx.close();
    mod = 'admin';
    ({ ctx, sayfa, D } = await kur(browser, 1280, Object.assign({}, HAZIR, { tabloVar: false })));
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszHata:not([hidden])');
    ok('admin: migrate.php bağlantısı', (await sayfa.$$eval('#bszHata a', a => a.map(x => x.getAttribute('href')))).includes('migrate.php#beyansiz-yukleme'));
    await ctx.close();
    mod = 'user';
    ({ ctx, sayfa, D } = await kur(browser, 1280, Object.assign({}, HAZIR, { katalogYok: true })));
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszHata:not([hidden])');
    ok('katalog yok: Listeleri Güncelle yönlendirmesi', (await sayfa.textContent('#bszHata')).includes('Listeleri Güncelle'));
    await ctx.close();

    // ── 6) Mobil 390 ─────────────────────────────────────────
    ({ ctx, sayfa, D } = await kur(browser, 390));
    await sayfa.goto(BASE);
    await sayfa.click('#bszAc');
    await sayfa.waitForSelector('#bszIcerik:not([hidden])');
    await sayfa.waitForTimeout(400);
    ok('390px: yatay taşma yok', await sayfa.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    const kucuk = await sayfa.$$eval('#bszIcerik input[type="text"], #bszIcerik select', els => els.filter(e => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).length);
    ok('390px: inputlar 16px', kucuk === 0, String(kucuk));
    const dlg = await sayfa.$eval('.beyan-hks-dialog', e => { const r = e.getBoundingClientRect(); return [r.left, r.right]; });
    ok('390px: pencere ekran içinde', dlg[0] >= -1 && dlg[1] <= 391, JSON.stringify(dlg));
    ok('sayfa hatası yok', D.hatalar.length === 0, D.hatalar.join('|'));
    await ctx.close();
  } finally {
    await browser.close(); sunucu.close();
  }
  console.log(fail === 0 ? '\nTUM TESTLER GECTI' : '\n' + fail + ' TEST BASARISIZ');
  process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
