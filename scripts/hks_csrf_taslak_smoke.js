// =========================================================
// scripts/hks_csrf_taslak_smoke.js — Hal Kayıt yazan uçlarda CSRF istemci akışı (v418)
//   node scripts/hks_csrf_taslak_smoke.js   → çıkış 0 = geçti
// Sahte api.php, sunucudaki merkezi kapıyı taklit eder: muaf (salt okunur) eylemler dışında
// X-CSRF-Token başlığı geçerli değilse 403 {ok:false,error,code:403} (csrf_check biçimi).
// Doğrulananlar: bayat meta token ile taslak_gonder 403 → /api/csrf → TEK tekrar → başarı;
// kalıcı 403'te tam 2 deneme + "Sayfayı yenileyip tekrar deneyin." ipucu; yazan uçlarda hem
// başlık hem gövde csrf, okuma uçlarında yalnız başlık (gövde YOK); meta yer tutucusu
// (__CSRF_TOKEN__) iken ilk yazmadan önce /api/csrf. Canlıya/DB'ye dokunmaz.
// Playwright yoksa kendini ATLAR (hata değil).
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

const MUAF = ['firmalar', 'sonlar', 'kisiler', 'csrf', 'listeler', 'kunyeler', 'stok', 'kunye_detay', 'toplu_kunye', 'taslaklar',
  'gonderilenler', 'gonderilen_tohum', 'urun_listeleri', 'urun_cinsleri', 'sorgu', 'etiket', 'bildirim_onizle', 'iller', 'ilceler',
  'beldeler', 'isyerleri', 'ayni_plaka_bugun', 'kayitli_kisi'];
const LISTELER = {
  urunler: [{ id: 1, ad: 'Nar' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }], bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const TASLAK = { id: 't1', zaman: '2026-10-22T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A',
  satirlar: [{ kunyeNo: '3601000000000001', miktar: 22000 }], ortak: { plaka: '33NCN99', belgeNo: '', urunAd: 'Nar', ulkeAd: 'Rusya', fiyat: 25 },
  ayniPlakaBugun: 0, belirsizGonderim: null };

const GECERLI = 'taze456';              // sunucudaki oturum token'ı
let kalici403 = false;                  // true → token ne olursa olsun 403 (oturum düşmüş)
let yerTutucu = false;                  // meta __CSRF_TOKEN__ kalsın (eski app.php önbelleği)
let istekler = [];
let taslaklar = [TASLAK];

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/api.php')) {
    let govde = '';
    q.on('data', c => { govde += c; });
    q.on('end', () => {
      let g = {}; try { g = JSON.parse(govde || '{}'); } catch (e) {}
      const action = new URL(q.url, 'http://x').searchParams.get('action');
      const baslik = q.headers['x-csrf-token'] || '';
      istekler.push({ action, govde: g, baslik });
      const j = (st, o) => { s.statusCode = st; s.setHeader('Content-Type', 'application/json'); s.end(JSON.stringify(o)); };
      // merkezi kapı taklidi (api.php): muaf değilse geçerli token şart (başlık, yoksa gövde)
      if (!MUAF.includes(action)) {
        const tok = baslik || (typeof g.csrf === 'string' ? g.csrf : '');
        if (kalici403 || tok !== GECERLI) return j(403, { ok: false, error: 'Güvenlik doğrulaması başarısız.', code: 403 });
      }
      switch (action) {
        case 'firmalar': return j(200, { firmalar: [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }] });
        case 'sonlar': return j(200, { ulkeler: [], urunler: [] });
        case 'listeler': return j(200, LISTELER);
        case 'taslaklar': return j(200, { taslaklar });
        case 'ayni_plaka_bugun': return j(200, { gonderimler: [] });
        case 'gonderilenler': return j(200, { gonderilenler: [] });
        case 'csrf': return j(200, { csrf: GECERLI });
        case 'taslak_gonder': taslaklar = []; return j(200, { sonuclar: [{ yeniKunyeNo: '3601000000000998', miktar: 1 }] });
        case 'taslak_sil': case 'taslak_kaydet': return j(200, { tamam: true, id: 't2' });
        case 'firma_kaydet': case 'firma_sil': return j(200, { tamam: true });
        case 'listeler_yenile': return j(200, LISTELER);
        default: return j(200, {});
      }
    });
    return;
  }
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8')
      .replace('__CSRF_TOKEN__', yerTutucu ? '__CSRF_TOKEN__' : 'bayat123'));
  } else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const port = sunucu.address().port;
  const tarayici = await chromium.launch();
  const hatalar = [];

  async function sayfaKur() {
    const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
    const sayfa = await ctx.newPage();
    sayfa.on('pageerror', e => hatalar.push(e.message));
    sayfa.on('dialog', d => d.accept());
    await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
    await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    return { ctx, sayfa };
  }
  // api() çağrısı: { ok, mesaj }
  const dene = (sayfa, yol, metod, govde) => sayfa.evaluate(async ([y, m, g]) => {
    try { await api(y, m, g); return { ok: true }; } catch (e) { return { ok: false, mesaj: e.message }; }
  }, [yol, metod, govde]);
  const eylemler = () => istekler.map(i => i.action).filter(a => !['firmalar', 'sonlar', 'listeler', 'taslaklar', 'gonderilenler', 'ayni_plaka_bugun', 'kunyeler', 'stok'].includes(a));

  // ===== (A) bayat meta token + UI'dan taslak gönderimi =====
  let { ctx, sayfa } = await sayfaKur();
  await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('.taslak-kart');
  await sayfa.locator('.taslak-kart').first().locator('[data-gonder]').click();
  await sayfa.waitForSelector('#modalOnayKutu:not(.gizli)');
  await sayfa.waitForFunction(() => !document.getElementById('btnOnay').disabled);
  istekler = [];
  await sayfa.click('#btnOnay');
  await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  const gn = istekler.filter(i => ['taslak_gonder', 'csrf'].includes(i.action));
  ok('(A) bayat token: taslak_gonder 403 → /api/csrf → TEK tekrar → başarı (sıra)',
     JSON.stringify(gn.map(i => i.action)) === JSON.stringify(['taslak_gonder', 'csrf', 'taslak_gonder']), gn.map(i => i.action).join(','));
  ok('(A) ilk deneme bayat token, tekrar taze token (başlık + gövde)',
     gn[0].baslik === 'bayat123' && gn[2].baslik === GECERLI && gn[2].govde.csrf === GECERLI && gn[0].govde.csrf === 'bayat123');
  ok('(A) tekrar gövdesi aynı taslağı gönderir (id/firmaId korunur)', gn[2].govde.id === 't1' && String(gn[2].govde.firmaId) === '7', JSON.stringify(gn[2].govde));
  ok('(A) sonuç ekranı normal (kullanıcıya 403 hatası görünmez)', /KAYDEDİLDİ|Künye|3601000000000998/.test(await sayfa.innerText('#modalIcerik')));

  // ===== (B) yazan uçlar: başlık + gövde csrf; okuma uçları: yalnız başlık =====
  istekler = [];
  for (const [yol, metod, g] of [
    ['/api/taslaklar/sil', 'POST', { id: 't1', firmaId: 7 }],
    ['/api/taslaklar', 'POST', { firmaId: 7, satirlar: [], ortak: {} }],
    ['/api/taslaklar/gonder', 'POST', { id: 't1', firmaId: 7 }],
    ['/api/firmalar', 'POST', { ad: 'X' }],
    ['/api/firmalar/sil', 'POST', { id: 7 }],
    ['/api/listeler', 'POST', { firmaId: 7 }],
    ['/api/belirsiz_isaret_kapat', 'POST', { isaretId: 'x', firmaId: 7 }],
  ]) await dene(sayfa, yol, metod, g);
  const yazanlar = istekler.filter(i => ['taslak_sil', 'taslak_kaydet', 'taslak_gonder', 'firma_kaydet', 'firma_sil', 'listeler_yenile', 'belirsiz_isaret_kapat'].includes(i.action));
  ok('(B) 7 yazan uç çağrıldı', new Set(yazanlar.map(i => i.action)).size === 7, yazanlar.map(i => i.action).join(','));
  ok('(B) yazan uçların HEPSİ gövdede de csrf taşır ve başlık dolu',
     yazanlar.every(i => i.govde.csrf === GECERLI && i.baslik === GECERLI), JSON.stringify(yazanlar.map(i => [i.action, i.govde.csrf, i.baslik])));
  istekler = [];
  for (const [yol, metod, g] of [
    ['/api/kunyeler', 'POST', { firmaId: 7 }], ['/api/taslaklar', 'GET'], ['/api/gonderilenler', 'GET'],
    ['/api/ayni_plaka', 'POST', { firmaId: 7 }], ['/api/stok', 'POST', { firmaId: 7 }],
  ]) await dene(sayfa, yol, metod, g);
  ok('(B) okuma uçlarında gövdeye csrf EKLENMEZ, başlık yine gider',
     istekler.length === 5 && istekler.every(i => !('csrf' in i.govde) && i.baslik === GECERLI), JSON.stringify(istekler.map(i => [i.action, 'csrf' in i.govde, i.baslik])));

  // ===== (C) kalıcı 403: tam 2 deneme, ipucu =====
  kalici403 = true; istekler = [];
  const r = await dene(sayfa, '/api/taslaklar/gonder', 'POST', { id: 't1', firmaId: 7 });
  kalici403 = false;
  ok('(C) kalıcı 403: taslak_gonder tam 2 deneme + 1 token tazeleme',
     istekler.filter(i => i.action === 'taslak_gonder').length === 2 && istekler.filter(i => i.action === 'csrf').length === 1,
     istekler.map(i => i.action).join(','));
  ok('(C) kalıcı 403: hata mesajı "Güvenlik doğrulaması" + "Sayfayı yenileyip tekrar deneyin." ipucu',
     !r.ok && r.mesaj.includes('Güvenlik doğrulaması') && r.mesaj.includes('Sayfayı yenileyip tekrar deneyin.'), r.mesaj);
  // yetki 403'ü (code YOK) tekrar denenmez, ipucu eklenmez — firma_kaydet yönetici kapısı biçimi
  let yetkiIstek = 0, csrfIstek = 0;
  await sayfa.route('**/api.php?action=firma_sil', route => { yetkiIstek++; route.fulfill({ status: 403, contentType: 'application/json',
    body: JSON.stringify({ hata: 'Firma yönetimi yalnız yöneticiye açıktır.' }) }); });
  await sayfa.route('**/api.php?action=csrf', route => { csrfIstek++; route.continue(); });
  const ry = await dene(sayfa, '/api/firmalar/sil', 'POST', { id: 7 });
  ok('(C) yetki 403 (code yok): tekrar YOK (tek istek), token tazeleme YOK', yetkiIstek === 1 && csrfIstek === 0, yetkiIstek + '/' + csrfIstek);
  ok('(C) yetki 403 mesajı sunucudaki gibi', !ry.ok && ry.mesaj === 'Firma yönetimi yalnız yöneticiye açıktır.', ry.mesaj);
  await ctx.close();

  // ===== (D) meta yer tutucusu: ilk yazmadan önce /api/csrf =====
  yerTutucu = true; istekler = [];
  ({ ctx, sayfa } = await sayfaKur());
  await sayfa.evaluate(() => { csrfBellek = ''; });   // açılıştaki okuma istekleri bellekte token bırakmış olabilir
  istekler = [];
  const rd = await dene(sayfa, '/api/taslaklar/sil', 'POST', { id: 't1', firmaId: 7 });
  const ad = istekler.filter(i => ['csrf', 'taslak_sil'].includes(i.action)).map(i => i.action);
  ok('(D) yer tutucu meta: önce /api/csrf, sonra taslak_sil (tek istek, 403 yok)', rd.ok && JSON.stringify(ad) === JSON.stringify(['csrf', 'taslak_sil']), ad.join(','));
  ok('(D) taslak_sil taze token taşır', istekler.find(i => i.action === 'taslak_sil').baslik === GECERLI);
  await ctx.close();

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close();
  sunucu.close();
  console.log(fail === 0 ? '\nTÜM TESTLER GEÇTİ' : '\n' + fail + ' TEST BAŞARISIZ');
  process.exit(fail === 0 ? 0 : 1);
})();
