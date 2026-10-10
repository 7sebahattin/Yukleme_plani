// =========================================================
// scripts/hks_binlik_smoke.js — Hal Kayıt kilo/fiyat kutularında binlik ayırıcı
//   node scripts/hks_binlik_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini
// sahte JSON ile yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
// Doğrular: canlı biçim ("1000" → "1.000"), imleç korunması, "." → ondalık
// virgül, yapıştırma, uç durumlar, taslak geri yüklemede biçimli yazım ve
// sunucuya giden gövdede SAYI (biçimli metin değil).
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

const LISTELER = {
  urunler: [{ id: 1, ad: 'Kayısı' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Yurt Dışı' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const KUNYELER = [
  { kunyeNo: '1001', kalan: 1500.5, birim: 'KG', tarih: '2026-09-01', urun: 'Kayısı' },
  { kunyeNo: '1002', kalan: 3000, birim: 'KG', tarih: '2026-09-02', urun: 'Kayısı' },
];
const ORTAK = { sifatId: 1, bildirimTuruId: 1, urunId: 1, urunAd: 'Kayısı', plaka: '06ABC123', ulkeId: 1, ulkeAd: 'Rusya', isletmeTuruId: 1 };
const TASLAKLAR = [
  { id: 'plan1', firmaId: 7, satirlar: [], ortak: Object.assign({}, ORTAK, { fiyat: 1234.5, planKg: 1234567.89, planSorgu: { aySayisi: 1 } }) },
  { id: 'kny1', firmaId: 7, satirlar: [{ kunyeNo: '1001', miktar: 1500.5 }, { kunyeNo: '1002', miktar: 2500 }], ortak: Object.assign({}, ORTAK, { fiyat: 12345.67 }) },
];
let istekler = [];
const DETAY = { hata: false, gecikme: 0 };   // kunye_detay sahte sunucu durumu

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
  sayfa.on('dialog', d => { sayfa._dlg = d.message(); d.accept(); });

  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    istekler.push({ action, govde: g, ham: r.postData() || '', t: Date.now() });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: TASLAKLAR });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'kunyeler': return j({ kunyeler: KUNYELER.map(k => Object.assign({}, k)) });
      // Fiyat haritası /api/kunyeler ile PARALEL istenir; ikisi bitince tek kunyeCiz().
      // DETAY.hata=true → kunye_detay 500 (künyeler fiyatsız basılmalı).
      case 'kunye_detay':
        if (DETAY.gecikme) await new Promise(r2 => setTimeout(r2, DETAY.gecikme));
        if (DETAY.hata) return j({ hata: 'test' }, 500);
        return j({ detaylar: { '1001': { fiyat: 12.5, birim: 'KG' }, '1002': { fiyat: 0, birim: 'KG' } } });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'taslak_kaydet': return j({ tamam: true, id: 'yeni' });
      default: return j({});
    }
  });

  await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  await sayfa.click('#btnEBildirim');
  await sayfa.waitForSelector('#sUrun option:nth-child(2)', { state: 'attached' });

  // kunyeCiz çağrı sayacı (fiyat ikinci aşamada ayrıca çizilmemeli)
  await sayfa.evaluate(() => { window.__kc = 0; const o = kunyeCiz; kunyeCiz = function () { window.__kc++; return o.apply(this, arguments); }; });

  // ---- Saf yardımcılar (sayfa bağlamında) ----
  const saf = await sayfa.evaluate(() => {
    const r = {};
    r.bicim = [1000, 1234567.89, 0.5, 0, 12, 1500.5, 0.1 + 0.2, '1234.5', null, ''].map(sayiBicimle);
    r.trSayi = ['1.000', '1.234.567,89', '0,5', '', ',', '12,', '0'].map(trSayi);
    // Gidiş-dönüş: ekranda görünen = sayıya çevrilen
    let bozuk = [];
    for (let i = 0; i < 2000; i++) {
      const n = Math.round(Math.random() * 1e9) / [1, 10, 100, 1000][i % 4];
      if (trSayi(sayiBicimle(n)) !== n) bozuk.push(n);
    }
    r.bozuk = bozuk.slice(0, 5);
    r.yap = ['1234567.89', '1.234', '1.234.567', '12 345,6', '1.234,5', '12.5', '1.2345', 'abc12'].map(binlikYapistirCoz);
    return r;
  });
  ok('sayiBicimle', JSON.stringify(saf.bicim) === JSON.stringify(['1.000', '1.234.567,89', '0,5', '0', '12', '1.500,5', '0,3', '1.234,5', '', '']), JSON.stringify(saf.bicim));
  ok('trSayi biçimli metni okur', JSON.stringify(saf.trSayi) === JSON.stringify([1000, 1234567.89, 0.5, 0, 0, 12, 0]), JSON.stringify(saf.trSayi));
  ok('gidiş-dönüş trSayi(sayiBicimle(n)) === n (2000 rastgele)', saf.bozuk.length === 0, JSON.stringify(saf.bozuk));
  ok('yapıştırma çözümü', JSON.stringify(saf.yap) === JSON.stringify(['1234567,89', '1234', '1234567', '12345,6', '1234,5', '12,5', '1,2345', '12']), JSON.stringify(saf.yap));

  // Ürün + ülke + plaka (ortakTopla için)
  await sayfa.evaluate(() => {
    const sec = (id, v) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); };
    sec('sUrun', '1'); sec('sUlke', '1');
    document.getElementById('oPlaka').value = '06ABC123';
  });

  // ---- Plan bloğu: canlı yazım ----
  await sayfa.click('#btnPlanAc');
  const K = '#oPlanKilo';
  const deger = (s) => sayfa.inputValue(s || K);
  const imlec = (s) => sayfa.$eval(s || K, e => e.selectionStart);
  const yaz = async (metin, s) => { await sayfa.fill(s || K, ''); await sayfa.click(s || K); await sayfa.keyboard.type(metin); };
  const imlecKoy = (p, s) => sayfa.$eval(s || K, (e, p) => { e.focus(); e.setSelectionRange(p, p); }, p);

  await yaz('1000'); ok('"1000" → "1.000"', await deger() === '1.000', await deger());
  await yaz('1234567,89'); ok('"1234567,89" → "1.234.567,89"', await deger() === '1.234.567,89', await deger());
  ok('imleç sonda', await imlec() === 12, String(await imlec()));
  await yaz('12.5'); ok('tuşla "." → ondalık virgül: "12.5" → "12,5"', await deger() === '12,5', await deger());
  await yaz('1,5.'); ok('virgül varken "." yok sayılır', await deger() === '1,5', await deger());
  await yaz('007'); ok('baştaki sıfırlar: "007" → "7"', await deger() === '7', await deger());
  await yaz(','); ok('yalnız "," → "0,"', await deger() === '0,', await deger());
  await yaz('12,'); await sayfa.click('#oPlanFiyat');
  ok('blur: sondaki virgül atılır "12," → "12"', await deger() === '12', await deger());
  await yaz('5'); await sayfa.keyboard.press('Backspace'); ok('boş değer boş kalır', await deger() === '', await deger());

  // Ortadan rakam silme — imleç kaymamalı
  await yaz('1234567');                 // "1.234.567"
  await imlecKoy(4);                    // "1.23|4.567"
  await sayfa.keyboard.press('Backspace');
  ok('ortadan silme: "1.23|4.567" ⌫ → "124.567"', await deger() === '124.567', await deger());
  ok('  imleç "12|4.567" (2)', await imlec() === 2, String(await imlec()));
  // Ortaya rakam ekleme
  await yaz('1000'); await imlecKoy(1);  // "1|.000"
  await sayfa.keyboard.type('5');
  ok('ortaya ekleme: "1|.000" + 5 → "15.000"', await deger() === '15.000', await deger());
  ok('  imleç "15|.000" (2)', await imlec() === 2, String(await imlec()));
  // Noktanın hemen sağında Backspace → soldaki rakam silinir
  await yaz('1234'); await imlecKoy(2);  // "1.|234"
  await sayfa.keyboard.press('Backspace');
  ok('noktanın sağında ⌫: "1.|234" → "234"', await deger() === '234', await deger());
  ok('  imleç başta (0)', await imlec() === 0, String(await imlec()));
  // Noktanın hemen solunda Delete → sağdaki rakam silinir
  await yaz('1234'); await imlecKoy(1);  // "1|.234"
  await sayfa.keyboard.press('Delete');
  ok('noktanın solunda Delete: "1|.234" → "134"', await deger() === '134', await deger());
  ok('  imleç (1)', await imlec() === 1, String(await imlec()));
  // Ondalık kısmı biçimlenmez / kırpılmaz
  await yaz('1234,5678'); ok('ondalık kısmı biçimlenmez "1.234,5678"', await deger() === '1.234,5678', await deger());

  // Yapıştırma
  const yapistir = async (metin, s) => {
    await sayfa.fill(s || K, ''); await sayfa.focus(s || K);
    await sayfa.$eval(s || K, (e, m) => {
      const dt = new DataTransfer(); dt.setData('text/plain', m);
      e.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    }, metin);
  };
  await yapistir('1234567.89'); ok('yapıştır "1234567.89" → "1.234.567,89"', await deger() === '1.234.567,89', await deger());
  await yapistir('1.234.567,5'); ok('yapıştır "1.234.567,5" → aynı', await deger() === '1.234.567,5', await deger());
  await yapistir('12 345'); ok('yapıştır "12 345" → "12.345"', await deger() === '12.345', await deger());
  ok('  yapıştırma sonrası imleç sonda', await imlec() === 6, String(await imlec()));

  // ---- Plan taslağı kaydet: gövdede SAYI ----
  await yaz('1234567,89');
  await yaz('1234,5', '#oPlanFiyat');
  ok('#oPlanFiyat "1.234,5"', await deger('#oPlanFiyat') === '1.234,5', await deger('#oPlanFiyat'));
  istekler = []; sayfa._dlg = null;
  await sayfa.click('#btnPlanKaydet'); await sayfa.waitForTimeout(400);
  const pk = istekler.find(i => i.action === 'taslak_kaydet');
  ok('plan taslak_kaydet gitti', !!pk, sayfa._dlg || '');
  ok('  ortak.planKg === 1234567.89 (sayı)', !!pk && pk.govde.ortak.planKg === 1234567.89, JSON.stringify(pk && pk.govde.ortak));
  ok('  ortak.fiyat === 1234.5 (sayı)', !!pk && pk.govde.ortak.fiyat === 1234.5);
  ok('  gövdede biçimli metin YOK', !!pk && !/1\.234/.test(pk.ham), pk && pk.ham);

  // ---- Künye seçimi: hedef kilo + otomatik dağıt + satır miktarları ----
  await sayfa.evaluate(async () => { ekranGoster('ekranBildirim'); await listeleriHazirla(); });
  await sayfa.evaluate(() => { document.getElementById('sUrun').value = '1'; document.getElementById('sUlke').value = '1'; document.getElementById('oPlaka').value = '06ABC123'; });
  await sayfa.evaluate(() => document.getElementById('btnKunyeler').onclick());
  await sayfa.waitForSelector('#kartKunyeler:not(.gizli)');
  await yaz('2000', '#hedefKilo'); ok('#hedefKilo "2000" → "2.000"', await deger('#hedefKilo') === '2.000');
  await sayfa.click('#btnDagit');
  const m = () => sayfa.$$eval('#kunyeListe input.miktar', a => a.map(e => e.value));
  ok('Otomatik Dağıt biçimli yazar ["1.500,5","499,5"]', JSON.stringify(await m()) === JSON.stringify(['1.500,5', '499,5']), JSON.stringify(await m()));
  await yaz('2500', '#kunyeListe input.miktar[data-i="1"]');
  ok('künye satırı canlı: "2500" → "2.500"', (await m())[1] === '2.500', JSON.stringify(await m()));
  ok('özet toplamı trSayi ile (4.000,5 KG)', (await sayfa.textContent('#ozetKilo')).replace(/\s/g, '') === '4.000,5KG', await sayfa.textContent('#ozetKilo'));
  await yaz('12345,67', '#oFiyat'); ok('#oFiyat "12.345,67"', await deger('#oFiyat') === '12.345,67');
  istekler = [];
  await sayfa.click('#btnTaslakKaydet'); await sayfa.waitForTimeout(400);
  const kk = istekler.find(i => i.action === 'taslak_kaydet');
  ok('künye taslak_kaydet gitti', !!kk, sayfa._dlg || '');
  ok('  satır miktarları sayı [1500.5, 2500]', !!kk && JSON.stringify(kk.govde.satirlar.map(s => s.miktar)) === '[1500.5,2500]', JSON.stringify(kk && kk.govde.satirlar));
  ok('  fiyat === 12345.67', !!kk && kk.govde.ortak.fiyat === 12345.67);

  // ---- Taslak geri yükleme ----
  await sayfa.waitForSelector('#ekranTaslaklar:not(.gizli)').catch(() => {});
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('[data-duzenle="plan1"]');
  await sayfa.click('[data-duzenle="plan1"]'); await sayfa.waitForTimeout(500);
  ok('plan taslağı: #oPlanKilo "1.234.567,89"', await deger('#oPlanKilo') === '1.234.567,89', await deger('#oPlanKilo'));
  ok('plan taslağı: #oPlanFiyat "1.234,5"', await deger('#oPlanFiyat') === '1.234,5', await deger('#oPlanFiyat'));
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('[data-duzenle="kny1"]');
  istekler = [];
  await sayfa.evaluate(() => { window.__kc = 0; });
  DETAY.gecikme = 400;
  await sayfa.click('[data-duzenle="kny1"]'); await sayfa.waitForTimeout(1200);
  DETAY.gecikme = 0;
  const kq = istekler.find(i => i.action === 'kunyeler'), dq = istekler.find(i => i.action === 'kunye_detay');
  ok('künye+fiyat istekleri PARALEL başladı (aralık < 250 ms, detay 400 ms gecikmeli)', !!kq && !!dq && Math.abs(dq.t - kq.t) < 250, JSON.stringify([kq && kq.t, dq && dq.t]));
  ok('kunye_detay gövdesi korundu {firmaId, aySayisi}, urunId YOK', !!dq && dq.govde.firmaId === 7 && 'aySayisi' in dq.govde && !('urunId' in dq.govde), JSON.stringify(dq && dq.govde));
  ok('taslak geri yükleme: kunyeCiz TEK sefer (fiyat ikinci çizim değil)', await sayfa.evaluate(() => window.__kc) === 1, String(await sayfa.evaluate(() => window.__kc)));
  ok('taslak geri yükleme: checkbox\'lar işaretli + miktar kutuları açık', await sayfa.$$eval('#kunyeListe .kunye', a => a.length === 2 && a.every(d => d.querySelector('input[type=checkbox]').checked && !d.querySelector('input.miktar').disabled)));
  ok('taslak geri yükleme: fiyat aynı ilk render\'da ("12,5 TL/KG", "fiyat yok")', JSON.stringify(await sayfa.$$eval('#kunyeListe .kalan-fiyat', a => a.map(e => e.textContent))) === JSON.stringify(['12,5 TL/KG', 'fiyat yok']), JSON.stringify(await sayfa.$$eval('#kunyeListe .kalan-fiyat', a => a.map(e => e.textContent))));
  ok('"fiyatlar yükleniyor" ikinci aşaması YOK, sayı metni temiz', !(await sayfa.textContent('#kunyeSayi')).includes('yükleniyor') && !(await sayfa.textContent('#kunyeSayi')).includes('alınamadı'), await sayfa.textContent('#kunyeSayi'));
  ok('buton tekrar açık', await sayfa.isEnabled('#btnKunyeler') && (await sayfa.textContent('#btnKunyeler')).includes('Künyeleri Getir'));
  ok('künye taslağı: miktarlar ["1.500,5","2.500"]', JSON.stringify(await m()) === JSON.stringify(['1.500,5', '2.500']), JSON.stringify(await m()));
  ok('künye taslağı: #oFiyat "12.345,67"', await deger('#oFiyat') === '12.345,67', await deger('#oFiyat'));
  // Tikle seçim: kalan biçimli dolar
  await sayfa.click('#btnSecimKaldir');
  await sayfa.check('#kunyeListe input[type=checkbox][data-i="0"]');
  ok('tik ile seçim: kalan "1.500,5" yazılır', (await m())[0] === '1.500,5', JSON.stringify(await m()));

  // ---- kunye_detay HATA verirse: künyeler yine fiyatsız gelir ----
  DETAY.hata = true;
  await sayfa.evaluate(async () => { window.__kc = 0; await document.getElementById('btnKunyeler').onclick(); });
  ok('detay hatası: künyeler yine basıldı (2 satır), fiyat satırı yok', await sayfa.locator('#kunyeListe .kunye').count() === 2 && await sayfa.locator('#kunyeListe .kalan-fiyat').count() === 0);
  ok('detay hatası: "fiyatlar alınamadı" bilgisi görünür', (await sayfa.textContent('#kunyeSayi')).includes('fiyatlar alınamadı'), await sayfa.textContent('#kunyeSayi'));
  ok('detay hatası: tek kunyeCiz, buton açık', await sayfa.evaluate(() => window.__kc) === 1 && await sayfa.isEnabled('#btnKunyeler'));
  DETAY.hata = false;

  // ---- Yarış: çift tık → eski cevap atılır, tek çizim ----
  DETAY.gecikme = 300;
  const yaris = await sayfa.evaluate(async () => {
    window.__kc = 0; const b = document.getElementById('btnKunyeler');
    const p1 = b.onclick(); const p2 = b.onclick(); await Promise.all([p1, p2]);
    return { kc: window.__kc, dis: b.disabled, satir: document.querySelectorAll('#kunyeListe .kunye').length };
  });
  DETAY.gecikme = 0;
  ok('çift sorgu: yalnız sonuncusu çizer (kunyeCiz 1), buton açık, 2 satır', yaris.kc === 1 && !yaris.dis && yaris.satir === 2, JSON.stringify(yaris));
  // Firma değişimi/form temizliği uçuştaki sorguyu geçersiz kılar
  DETAY.gecikme = 300;
  const iptal = await sayfa.evaluate(async () => {
    window.__kc = 0; const b = document.getElementById('btnKunyeler');
    const p1 = b.onclick(); formuTemizle(); await p1;
    return { kc: window.__kc, satir: document.querySelectorAll('#kunyeListe .kunye').length, dis: b.disabled };
  });
  DETAY.gecikme = 0;
  ok('uçuşta formuTemizle: eski cevap atıldı (çizim 0, liste boş, buton açık)', iptal.kc === 0 && iptal.satir === 0 && !iptal.dis, JSON.stringify(iptal));

  // ---- Statik kutuların hepsi bağlı ----
  ok('altı statik kutu + künye satırları bağlı', await sayfa.evaluate(() =>
    ['oMalMiktar', 'oMalFiyat', 'oPlanKilo', 'oPlanFiyat', 'oFiyat', 'hedefKilo'].every(id => document.getElementById(id)._binlikBagli) &&
    [...document.querySelectorAll('#kunyeListe input.miktar')].every(e => e._binlikBagli)));
  ok('inputmode=decimal olan her kutu bağlı', await sayfa.evaluate(() =>
    [...document.querySelectorAll('input[inputmode=decimal]')].every(e => e._binlikBagli)));

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? '\n' + fail + ' TEST BASARISIZ' : '\nTUMU GECTI');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
