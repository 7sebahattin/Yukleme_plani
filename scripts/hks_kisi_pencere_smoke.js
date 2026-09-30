// =========================================================
// scripts/hks_kisi_pencere_smoke.js — Hal Kayıt "Kişi Seç" penceresi (gerçek tarayıcı)
//   node scripts/hks_kisi_pencere_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini
// sahte JSON ile yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(64) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const TC_OK = '10000000146';   // algoritmadan geçen 11 hane
const LISTELER = {
  urunler: [{ id: 1, ad: 'Kayısı' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 2, ad: 'Satın Alım' }],
};
let havuz, istekler, sonId;
function sifirla() {
  sonId = 100; istekler = [];
  havuz = [
    { id: 1, tc: '12345678950', ad: 'İSMAİL ÇAĞLAR ÇOK UZUN BİR ÜNVAN ADI TAŞMA DENEMESİ İÇİN YAZILDI', cep: '05321234567', dogum: '1980-01-02', kullanimSayisi: 3, sonKullanim: '2026-09-01 10:00:00' },
    { id: 2, tc: '1234567890', ad: 'Ilgaz Tarım Ltd.', cep: '', dogum: '', kullanimSayisi: 0, sonKullanim: '' },
  ];
  for (let i = 3; i <= 30; i++) havuz.push({ id: i, tc: String(2000000000 + i), ad: 'Kişi ' + i, cep: '', dogum: '', kullanimSayisi: 0, sonKullanim: '' });
}
sifirla();

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
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const sayfa = await ctx.newPage();
  const hatalar = [];
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => { sayfa._dlg = d.message(); d.accept(); });

  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    istekler.push({ action, govde: g, csrf: r.headers()['x-csrf-token'] });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': case 'gonderilenler': return j({ taslaklar: [], gonderilenler: [] });
      case 'stok': case 'kunyeler': return j({ kunyeler: [], stok: [] });
      case 'kayitli_kisi': return j({ kisi: { tcVkn: g.tcVkn, durum: 'NOT_REGISTERED', kayitliMi: false, sifatlar: [] } });
      case 'kisiler': return j({ kisiler: havuz });
      case 'kisi_kaydet': {
        if (g.tc === '1234567890' && !g.id) return j({ hata: 'Bu TC/VKN havuzda zaten kayıtlı: Ilgaz' }, 409);
        // csrf_check() biçimi: 403 + {ok,error,code} ('hata' YOK)
        if (g.ad === 'CSRF-DENEME') return j({ ok: false, error: 'Güvenlik doğrulaması başarısız.', code: 403 }, 403);
        let k;
        if (g.id) { k = havuz.find(x => x.id === g.id); Object.assign(k, { tc: g.tc, ad: g.ad, cep: g.cep, dogum: g.dogum }); }
        else { k = { id: ++sonId, tc: g.tc, ad: g.ad, cep: g.cep, dogum: g.dogum, kullanimSayisi: 0, sonKullanim: '' }; havuz.unshift(k); }
        return j({ tamam: true, kisi: k });
      }
      case 'kisi_sil': havuz = havuz.filter(x => x.id !== g.id); return j({ tamam: true });
      default: return j({});
    }
  });

  await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.click('#btnEBildirim');
  await sayfa.waitForSelector('#sTur option:nth-child(2)', { state: 'attached' });
  await sayfa.selectOption('#sTur', { label: 'Satın Alım' });
  await sayfa.waitForTimeout(200);

  ok('karşı taraf bloğu + Kişi Seç butonu görünür', await sayfa.isVisible('#btnKisiSec'));
  ok('eski #ikSonSecim kalmadı', await sayfa.locator('#ikSonSecim').count() === 0);

  const bekle = () => sayfa.waitForTimeout(400);
  const tasma = () => sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1 ||
    [...document.querySelectorAll('#kisiPencere *')].some(e => e.offsetParent && e.getBoundingClientRect().right > innerWidth + 1));

  // Aç
  await sayfa.click('#btnKisiSec'); await bekle();
  ok('pencere açılır, başlık "Satıcı Seç"', await sayfa.isVisible('#kisiPencere') && (await sayfa.textContent('#kisiPencereBaslik')) === 'Satıcı Seç');
  ok('arama kutusuna odak', await sayfa.evaluate(() => document.activeElement.id) === 'kisiAra');
  ok('kisiler isteği gitti', istekler.some(i => i.action === 'kisiler'));
  ok('liste çizildi (30 satır)', await sayfa.locator('.kisi-satir').count() === 30);
  ok('VKN/TC rozetleri', (await sayfa.locator('.kisi-satir').nth(0).textContent()).includes('TC') &&
     (await sayfa.locator('.kisi-satir').nth(1).textContent()).includes('VKN'));

  // Arama (Türkçe İ/ı)
  await sayfa.fill('#kisiAra', 'ILGAZ');
  ok('arama "ILGAZ" → Ilgaz (I→ı)', await sayfa.locator('.kisi-satir').count() === 1);
  await sayfa.fill('#kisiAra', 'İSMAİL');
  ok('arama "İSMAİL" → 1 sonuç', await sayfa.locator('.kisi-satir').count() === 1);
  await sayfa.fill('#kisiAra', '1234567890');
  ok('TC ile arama', await sayfa.locator('.kisi-satir').count() === 1);
  await sayfa.fill('#kisiAra', 'zzzz');
  ok('boş sonuç mesajı', (await sayfa.textContent('#kisiListe')).includes('uyan kişi yok'));
  await sayfa.fill('#kisiAra', '');

  // Masaüstü taşma
  ok('1280px yatay taşma yok', !(await tasma()));

  // Seç
  istekler.length = 0;
  await sayfa.locator('.kisi-satir').nth(1).click(); await bekle();
  ok('satır tıklanınca pencere kapanır', !(await sayfa.isVisible('#kisiPencere')));
  ok('#oIkTc doldu', (await sayfa.inputValue('#oIkTc')) === '1234567890');
  ok('kayitli_kisi isteği gitti', istekler.some(i => i.action === 'kayitli_kisi' && i.govde.tcVkn === '1234567890'));
  ok('"Seçili:" satırı', (await sayfa.textContent('#kisiSecili')).includes('Seçili: Ilgaz Tarım Ltd. — 1234567890'));
  ok('odak Kişi Seç butonunda', await sayfa.evaluate(() => document.activeElement.id) === 'btnKisiSec');
  await sayfa.fill('#oIkTc', '123');
  ok('TC elle değişince "Seçili" temizlenir', !(await sayfa.isVisible('#kisiSecili')));

  // Yeni Ekle — geçersiz TC
  await sayfa.click('#btnKisiSec'); await bekle();
  istekler.length = 0;
  await sayfa.click('#btnKisiYeni'); await bekle();
  ok('Yeni Ekle formu açılır (boş)', await sayfa.isVisible('#kisiForm') && (await sayfa.inputValue('#kfTc')) === '');
  await sayfa.fill('#kfTc', '12345678901'); await sayfa.fill('#kfAd', 'Deneme');
  await sayfa.click('#btnKisiFormKaydet');
  ok('geçersiz TC: hata görünür, istek YOK, form açık',
     (await sayfa.isVisible('#kisiFormMesaj')) && !istekler.some(i => i.action === 'kisi_kaydet') && await sayfa.isVisible('#kisiForm'));
  // 409 hatası formda
  await sayfa.fill('#kfTc', '1234567890');
  await sayfa.click('#btnKisiFormKaydet'); await sayfa.waitForTimeout(200);
  ok('sunucu hatası formda görünür, form kapanmaz',
     (await sayfa.textContent('#kisiFormMesaj')).includes('zaten kayıtlı') && await sayfa.isVisible('#kisiForm'));
  // CSRF reddi (csrf_check JSON biçimi) formda anlaşılır metinle görünür
  await sayfa.fill('#kfTc', TC_OK); await sayfa.fill('#kfAd', 'CSRF-DENEME');
  await sayfa.click('#btnKisiFormKaydet'); await sayfa.waitForTimeout(200);
  ok('CSRF 403: csrf_check mesajı formda (HTTP 403 değil)',
     (await sayfa.textContent('#kisiFormMesaj')).includes('Güvenlik doğrulaması') && await sayfa.isVisible('#kisiForm'),
     await sayfa.textContent('#kisiFormMesaj'));
  // Geçerli kayıt
  await sayfa.fill('#kfTc', TC_OK); await sayfa.fill('#kfAd', 'Yeni Müstahsil'); await sayfa.fill('#kfCep', '0532 111 22 33');
  await sayfa.fill('#kfDogum', '1990-05-06');
  istekler.length = 0;
  await sayfa.click('#btnKisiFormKaydet'); await bekle();
  const kk = istekler.find(i => i.action === 'kisi_kaydet');
  ok('kisi_kaydet gövdesi doğru', !!kk && kk.govde.tc === TC_OK && kk.govde.ad === 'Yeni Müstahsil' &&
     kk.govde.cep === '05321112233' && kk.govde.dogum === '1990-05-06' && !('id' in kk.govde), JSON.stringify(kk && kk.govde));
  ok('X-CSRF-Token başlığı var', !!kk && kk.csrf === 'tok123', String(kk && kk.csrf));
  ok('form kapandı, liste yenilendi, yeni kişi vurgulu',
     !(await sayfa.isVisible('#kisiForm')) && (await sayfa.locator('.kisi-satir.vurgu').textContent()).includes('Yeni Müstahsil'));

  // Düzenle
  await sayfa.locator('.kisi-satir.vurgu .kisi-ikon').first().click(); await bekle();
  ok('Düzenle dolu açılır, satır seçimi tetiklenmez',
     (await sayfa.inputValue('#kfAd')) === 'Yeni Müstahsil' && (await sayfa.inputValue('#kfDogum')) === '1990-05-06' && await sayfa.isVisible('#kisiPencere'));
  await sayfa.fill('#kfAd', 'Düzeltilmiş Ad');
  istekler.length = 0;
  await sayfa.click('#btnKisiFormKaydet'); await bekle();
  const du = istekler.find(i => i.action === 'kisi_kaydet');
  ok('düzenleme gövdesinde id var', !!du && du.govde.id === 101);

  // Esc sırası
  await sayfa.locator('.kisi-satir').first().locator('.kisi-ikon').first().click(); await bekle();
  await sayfa.keyboard.press('Escape'); await bekle();
  ok('Esc önce formu kapatır, pencere açık', !(await sayfa.isVisible('#kisiForm')) && await sayfa.isVisible('#kisiPencere'));
  await sayfa.keyboard.press('Escape'); await bekle();
  ok('ikinci Esc pencereyi kapatır, odak butonda', !(await sayfa.isVisible('#kisiPencere')) &&
     await sayfa.evaluate(() => document.activeElement.id) === 'btnKisiSec');

  // Arka plana tıklama
  await sayfa.click('#btnKisiSec'); await bekle();
  await sayfa.mouse.click(5, 5); await bekle();
  ok('arka plana tıklayınca kapanır', !(await sayfa.isVisible('#kisiPencere')));

  // Sil
  await sayfa.click('#btnKisiSec'); await bekle();
  istekler.length = 0;
  await sayfa.locator('.kisi-satir').first().locator('.kisi-ikon').nth(1).click(); await bekle();
  const sk = istekler.find(i => i.action === 'kisi_sil');
  ok('Sil: confirm adı içerir + kisi_sil gitti', !!sk && sk.csrf === 'tok123' && (sayfa._dlg || '').includes('Düzeltilmiş Ad'), sayfa._dlg);
  ok('silinen listeden kalktı', !(await sayfa.textContent('#kisiListe')).includes('Düzeltilmiş Ad'));
  await sayfa.keyboard.press('Escape');

  // Mobil
  await sayfa.setViewportSize({ width: 390, height: 780 }); await bekle();
  await sayfa.click('#btnKisiSec'); await bekle();
  ok('390px yatay taşma yok', !(await tasma()));
  const govde = await sayfa.evaluate(() => { const g = document.getElementById('kisiListe'); return { sh: g.scrollHeight, ch: g.clientHeight }; });
  ok('gövde kaydırılabilir', govde.sh > govde.ch, JSON.stringify(govde));
  await sayfa.evaluate(() => { const g = document.getElementById('kisiListe'); g.scrollTop = g.scrollHeight; });
  await sayfa.waitForTimeout(100);
  const son = sayfa.locator('.kisi-satir').last();
  const kutu = await sayfa.evaluate(() => {
    const g = document.getElementById('kisiListe').getBoundingClientRect();
    const l = [...document.querySelectorAll('.kisi-satir')].pop().getBoundingClientRect();
    const a = document.querySelector('#kisiPencere .kisi-alt').getBoundingClientRect();
    return { altSatir: l.bottom, govdeAlt: g.bottom, altBtnUst: a.top, altBtnAlt: a.bottom, vh: innerHeight };
  });
  ok('son satır kaydırınca görünür', kutu.altSatir <= kutu.govdeAlt + 1, JSON.stringify(kutu));
  ok('alt buton ekran içinde', kutu.altBtnAlt <= kutu.vh + 1 && kutu.altBtnUst >= 0);
  istekler.length = 0;
  await son.click(); await bekle();
  ok('son satır tıklanabilir (seçilir)', istekler.some(i => i.action === 'kayitli_kisi'));

  await sayfa.click('#btnKisiSec'); await bekle();
  await sayfa.click('#btnKisiYeni'); await bekle();
  const f = await sayfa.evaluate(() => {
    const k = document.querySelector('#kisiForm .kisi-alt #btnKisiFormKaydet').getBoundingClientRect();
    return { alt: k.bottom, ust: k.top, vh: innerHeight, fs: getComputedStyle(document.getElementById('kfAd')).fontSize };
  });
  ok('mobil: form Kaydet düğmesi ekran içinde', f.alt <= f.vh + 1 && f.ust >= 0, JSON.stringify(f));
  ok('mobil: input 16px', f.fs === '16px', f.fs);
  ok('390px form taşma yok', !(await sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)));

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? '\n' + fail + ' TEST BASARISIZ' : '\nTUMU GECTI');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
