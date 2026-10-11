// =========================================================
// scripts/hks_uretici_cep_smoke.js — HKS 263: sıfat "Üretici" iken KAYITLI karşı
// tarafta da cep telefonu zorunlu (gerçek tarayıcı, sahte api.php).
//   node scripts/hks_uretici_cep_smoke.js   → çıkış 0 = geçti
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
  sifatlar: [{ id: 1, ad: 'İhracat' }, { id: 2, ad: 'Komisyoncu' }, { id: 3, ad: 'Üretici' }],
  isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 2, ad: 'Satın Alım' }],
};
let istekler = [], kayitli = true;
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
  let uyarilar = [];
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => { uyarilar.push(d.message()); d.accept(); });
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    istekler.push({ action, metot: r.method(), govde: g });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslak_kaydet': return j({ tamam: true, id: 5 });
      case 'taslaklar': return j(r.method() === 'POST' ? { tamam: true, id: 5 } : { taslaklar: [] });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': case 'kunyeler': return j({ kunyeler: [], stok: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'kayitli_kisi':
        return j({ kisi: kayitli
          ? { tcVkn: g.tcVkn, durum: 'REGISTERED', kayitliMi: true, sifatlar: [1, 3] }
          : { tcVkn: g.tcVkn, durum: 'NOT_REGISTERED', kayitliMi: false, sifatlar: [] } });
      case 'kisiler': return j({ kisiler: [] });
      default: return j({});
    }
  });

  async function hazirla(kayitliMi, tc) {
    kayitli = kayitliMi; istekler = []; uyarilar = [];
    await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    await sayfa.click('#btnEBildirim');
    await sayfa.waitForSelector('#sTur option:nth-child(2)', { state: 'attached' });
    await sayfa.selectOption('#sTur', { label: 'Satın Alım' });
    await sayfa.waitForTimeout(200);
    await sayfa.fill('#oIkTc', tc);
    await sayfa.click('#btnIkDogrula');
    await sayfa.waitForTimeout(500);
    // Mal / hedef alanlarını doldur (liste uçları sahte; seçenekler elle eklenir)
    await sayfa.evaluate(() => {
      const sec = (id) => { const s = document.getElementById(id);
        if (![...s.options].some(o => o.value === '1')) { const o = document.createElement('option'); o.value = '1'; o.textContent = 'X'; s.appendChild(o); }
        s.value = '1'; };
      ['sMalNitelik', 'sUretimSekli', 'sMalUrun', 'sMalCins', 'sUretimIl', 'sUretimIlce', 'sUretimBelde',
       'sMiktarBirim', 'sIsletmeTuru', 'sIsyeri'].forEach(sec);
    });
    await sayfa.fill('#oMalMiktar', '1000');
    await sayfa.fill('#oMalFiyat', '10');
    await sayfa.fill('#oPlaka', '34ABC123');
  }
  const gorunur = (id) => sayfa.evaluate((i) => !document.getElementById(i).classList.contains('gizli'), id);
  const taslakPost = () => istekler.find(i => i.action === 'taslak_kaydet' && i.govde.ortak);

  // ---- KAYITLI VKN ----
  await hazirla(true, '1234567890');
  ok('kayıtlı: sıfat seçilmeden cep bloğu GİZLİ', !(await gorunur('cepTelBlok')));
  await sayfa.selectOption('#sIkSifat', '3');
  ok('kayıtlı + Üretici: cep bloğu görünür', await gorunur('cepTelBlok'));
  ok('kayıtlı + Üretici: not yazıldı', (await sayfa.textContent('#cepTelNot')).includes('Üretici sıfatında') &&
     await sayfa.evaluate(() => !document.getElementById('cepTelNot').hidden));
  ok('kayıtlı + Üretici: ad ve doğum gizli kalır', !(await gorunur('adBlok')) && !(await gorunur('dogumTarihiBlok')));
  await sayfa.selectOption('#sIkSifat', '2');
  ok('başka sıfat: cep bloğu gizlenir, not boş',
     !(await gorunur('cepTelBlok')) && (await sayfa.textContent('#cepTelNot')) === '');
  await sayfa.selectOption('#sIkSifat', '3');

  uyarilar = []; istekler.length = 0;
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(300);
  ok('cepsiz kayıt: uyarı + POST YOK',
     uyarilar.some(u => u.includes('HKS üreticinin cep telefonunu zorunlu')) && !taslakPost(), JSON.stringify(uyarilar));

  await sayfa.fill('#oIkCep', '0532 111 22 33');
  istekler.length = 0;
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(400);
  const p = taslakPost();
  const o = p && p.govde.ortak;
  ok('cepli kayıt: taslak POST gitti', !!p, JSON.stringify(istekler.map(i=>i.action+i.metot)));
  ok('ortak.ikinciCep = rakamlar', !!o && o.ikinciCep === '05321112233', JSON.stringify(p));
  ok('ortak.ikinciDogumTarihi YOK', !!o && !('ikinciDogumTarihi' in o));
  ok('ortak.ikinciAd boş (kayıtlıda ad gönderilmez)', !!o && !o.ikinciAd);

  // başka sıfat: cep alanı dolu bile olsa gönderilmez
  await hazirla(true, '1234567890');
  await sayfa.selectOption('#sIkSifat', '2');
  istekler.length = 0;
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(400);
  const o2 = taslakPost() && taslakPost().govde.ortak;
  ok('kayıtlı + başka sıfat: cep ZORUNLU DEĞİL, ikinciCep gönderilmez', !!o2 && !('ikinciCep' in o2), JSON.stringify(uyarilar));

  // ---- KAYITSIZ (değişmedi) ----
  await hazirla(false, '10000000146');
  ok('kayıtsız: cep + ad + doğum blokları görünür',
     (await gorunur('cepTelBlok')) && (await gorunur('adBlok')) && (await gorunur('dogumTarihiBlok')));
  ok('kayıtsız: not yok', (await sayfa.textContent('#cepTelNot')) === '');
  await sayfa.selectOption('#sIkSifat', '3');
  uyarilar = [];
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(300);
  ok('kayıtsız: ad zorunlu', uyarilar.some(u => u.includes('Ad / Ünvan zorunludur')));
  await sayfa.fill('#oIkAd', 'Deneme Kisi');
  uyarilar = [];
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(300);
  ok('kayıtsız: cep zorunlu', uyarilar.some(u => u.includes('Cep Telefonu zorunludur')));
  await sayfa.fill('#oIkCep', '05321112233');
  uyarilar = [];
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(300);
  ok('kayıtsız: doğum zorunlu', uyarilar.some(u => u.includes('Doğum Tarihi zorunludur')));
  await sayfa.fill('#oIkDogum', '1980-02-11');
  istekler.length = 0;
  await sayfa.click('#btnMalTaslak'); await sayfa.waitForTimeout(400);
  const o3 = taslakPost() && taslakPost().govde.ortak;
  ok('kayıtsız: ad + cep + doğum gönderildi', !!o3 && o3.ikinciAd === 'Deneme Kisi' &&
     o3.ikinciCep === '05321112233' && o3.ikinciDogumTarihi === '1980-02-11', JSON.stringify(o3));

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
