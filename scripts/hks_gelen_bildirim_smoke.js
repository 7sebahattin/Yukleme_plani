// =========================================================
// scripts/hks_gelen_bildirim_smoke.js — Hal Kayıt "Bana Yapılan Bildirimler" (v432)
//   node scripts/hks_gelen_bildirim_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php'yi sahte JSON
// ile yanıtlar (canlıya/HKS'e dokunmaz). Playwright yoksa ATLAR.
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
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(86) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }];
const LISTELER = { urunler: [], ulkeler: [], belgeTipleri: [], sifatlar: [], isletmeTurleri: [], bildirimTurleri: [] };
const SATIRLAR = [
  { kunyeNo: '1203829260020605791', tarihSaat: '2026-10-25T09:30:00', tarih: '2026-10-25', saat: '09:30', urun: 'KİRAZ', cins: 'DİĞER',
    miktar: 11501.4, kalan: 0, birim: 'KG', plaka: '31DE681', belgeNo: '', bildirimciTc: '1111111111', malinSahibiTc: '2', gonderenAd: 'Örnek Müstahsil' },
  { kunyeNo: '1203829260020605792', tarihSaat: '2026-10-25T08:00:00', tarih: '2026-10-25', saat: '08:00',
    urun: '<img src=x onerror=window.__gx=1>', cins: '', miktar: 300, kalan: 120, birim: 'KG', plaka: '', belgeNo: 'B-9',
    bildirimciTc: '3333333333', malinSahibiTc: '', gonderenAd: '' },
];

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

async function sayfaKur(ctx, durum, hatalar) {
  const sayfa = await ctx.newPage();
  sayfa.on('pageerror', e => hatalar.push(e.message));
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    durum.istekler.push({ action, govde: g, csrf: r.headers()['x-csrf-token'] });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: FIRMALAR });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'gelen_sorgu': {
        if (durum.gecikme) await new Promise(r2 => setTimeout(r2, durum.gecikme));
        if (durum.mod === 'hata') return j({ hata: 'HKS servisi yanıt vermedi.' }, 500);
        if (durum.mod === 'bos') return j({ bildirimler: [], zaman: 'x', kismiHata: 0 });
        return j({ bildirimler: SATIRLAR, zaman: 'x', kismiHata: durum.mod === 'kismi' ? 2 : 0 });
      }
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  return sayfa;
}
const tasma = (sayfa) => sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
const yerel = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const sonGelen = (D) => D.istekler.filter(i => i.action === 'gelen_sorgu').slice(-1)[0];

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];

  for (const [ad, w, h] of [['1280', 1280, 800], ['390', 390, 800]]) {
    const ctx = await tarayici.newContext({ viewport: { width: w, height: h } });
    const D = { istekler: [], mod: 'normal' };
    const sayfa = await sayfaKur(ctx, D, hatalar);
    const p = `[${ad}] `;

    await sayfa.evaluate(() => { document.querySelectorAll('details.grup').forEach(d => d.open = true); });
    const grupMetin = await sayfa.locator('details.grup', { hasText: 'Raporlama İşlemleri' }).locator('#btnGelen').count();
    ok(p + 'düğme "Raporlama İşlemleri" grubunda', grupMetin === 1);
    ok(p + 'düğme görünür + etiket', await sayfa.isVisible('#btnGelen') && /Bana Yapılan Bildirimler/.test(await sayfa.innerText('#btnGelen')));

    await sayfa.click('#btnGelen');
    ok(p + 'ekran açıldı, menü gizli', await sayfa.isVisible('#ekranGelen') && !(await sayfa.isVisible('#ekranMenu')));
    ok(p + 'varsayılan tarihler = bugün', (await sayfa.inputValue('#glBas')) === yerel(0) && (await sayfa.inputValue('#glBit')) === yerel(0),
      (await sayfa.inputValue('#glBas')) + ' / ' + (await sayfa.inputValue('#glBit')));

    await sayfa.click('[data-gl-cip="7"]');
    ok(p + 'çip "Son 7 gün" tarihleri doldurur', (await sayfa.inputValue('#glBas')) === yerel(7) && (await sayfa.inputValue('#glBit')) === yerel(0));
    await sayfa.click('[data-gl-cip="dun"]');
    ok(p + 'çip "Dün"', (await sayfa.inputValue('#glBas')) === yerel(1) && (await sayfa.inputValue('#glBit')) === yerel(1));
    await sayfa.click('[data-gl-cip="30"]');
    ok(p + 'çip "Son 30 gün"', (await sayfa.inputValue('#glBas')) === yerel(30));
    await sayfa.click('[data-gl-cip="bugun"]');

    // Sorgu + yükleniyor + çift tıklama koruması
    D.gecikme = 400;
    await sayfa.click('#btnGelenSorgula');
    ok(p + 'sorgu sürerken düğme disabled + yükleniyor mesajı', await sayfa.isDisabled('#btnGelenSorgula') && /Sorgulanıyor/.test(await sayfa.innerText('#glSonucSarma')));
    await sayfa.waitForSelector('#glTablo');
    D.gecikme = 0;
    const ist = sonGelen(D);
    ok(p + 'istek gövdesi: firmaId + baslangic + bitis', ist && String(ist.govde.firmaId) === '7' && ist.govde.baslangic === yerel(0) && ist.govde.bitis === yerel(0),
      JSON.stringify(ist && ist.govde));
    ok(p + 'istekte CSRF başlığı var', !!(ist && ist.csrf));
    ok(p + 'sorgu bitince düğme tekrar aktif', !(await sayfa.isDisabled('#btnGelenSorgula')));
    ok(p + 'tek istek gitti (çift tıklama yok)', D.istekler.filter(i => i.action === 'gelen_sorgu').length === 1);
    ok(p + 'tablo 2 satır', await sayfa.locator('#glTablo tbody tr').count() === 2);
    const ozet = (await sayfa.innerText('#glOzet')).replace(/\s+/g, ' ');
    ok(p + 'özet: 2 bildirim · toplam KG tam sayı', /2 bildirim/.test(ozet) && /11\.801 KG/.test(ozet), ozet);
    const basl = (await sayfa.locator('#glTablo thead th').allTextContents()).map(t => t.trim()).join('|');
    ok(p + 'sütunlar', basl === 'Tarih|Künye No|Ürün|Miktar|Kalan|Gönderen|Plaka/Belge', basl);
    const s0 = (await sayfa.locator('#glTablo tbody tr').nth(0).innerText()).replace(/\s+/g, ' ');
    ok(p + 'satır1: kalan 0 satırı da var, ad + TC, plaka', /1203829260020605791/.test(s0) && /Örnek Müstahsil/.test(s0) && /1111111111/.test(s0) && /31DE681/.test(s0) && /11\.501 KG/.test(s0), s0);
    const s1 = (await sayfa.locator('#glTablo tbody tr').nth(1).innerText()).replace(/\s+/g, ' ');
    ok(p + 'satır2: ad yoksa TC, belge no', /3333333333/.test(s1) && /B-9/.test(s1), s1);
    ok(p + 'XSS: ürün adı metin olarak kaçışlı', (await sayfa.evaluate(() => window.__gx)) === undefined && await sayfa.locator('#glTablo img').count() === 0);
    ok(p + 'yatay sayfa taşması YOK', !(await tasma(sayfa)));
    if (w < 768) {
      const sar = await sayfa.evaluate(() => { const e = document.querySelector('#glSonucSarma .tk-tablo-sarma'); return e.scrollWidth > e.clientWidth && getComputedStyle(e).overflowX !== 'visible'; });
      ok(p + 'mobil: tablo kendi içinde yatay kaydırılır', sar);
    }

    // Kısmi hata uyarısı
    D.mod = 'kismi';
    await sayfa.click('#btnGelenSorgula'); await sayfa.waitForSelector('#glTablo');
    ok(p + 'kısmi hata uyarısı özetle birlikte', /2 tarih parçası alınamadı/.test(await sayfa.innerText('#glOzet')));

    // Boş sonuç
    D.mod = 'bos';
    await sayfa.click('#btnGelenSorgula');
    await sayfa.waitForFunction(() => /bulunamadı/.test(document.getElementById('glSonucSarma').innerText));
    ok(p + 'boş sonuç mesajı', await sayfa.locator('#glTablo').count() === 0 && /bildirim bulunamadı/.test(await sayfa.innerText('#glSonucSarma')));

    // Hata
    D.mod = 'hata';
    await sayfa.click('#btnGelenSorgula');
    await sayfa.waitForFunction(() => /yanıt vermedi/.test(document.getElementById('glMesaj').innerText));
    ok(p + 'hata mesajı gösterilir, düğme aktif', !(await sayfa.isDisabled('#btnGelenSorgula')) && await sayfa.locator('#glTablo').count() === 0);

    // İstemci doğrulama: bas > bit → istek gitmez
    const onceki = D.istekler.filter(i => i.action === 'gelen_sorgu').length;
    await sayfa.fill('#glBas', yerel(0)); await sayfa.fill('#glBit', yerel(3));
    await sayfa.click('#btnGelenSorgula');
    ok(p + 'başlangıç > bitiş: istek gitmez, mesaj var',
      D.istekler.filter(i => i.action === 'gelen_sorgu').length === onceki && /sonra olamaz/.test(await sayfa.innerText('#glMesaj')));

    // Geri
    await sayfa.click('#btnGelenGeri');
    ok(p + 'Geri → ana menü', await sayfa.isVisible('#ekranMenu') && !(await sayfa.isVisible('#ekranGelen')));
    ok(p + 'menüde yatay taşma YOK', !(await tasma(sayfa)));
    await ctx.close();
  }

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close();
  sunucu.close();
  console.log(fail ? `\n${fail} TEST BAŞARISIZ` : '\nTüm testler geçti.');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
