// =========================================================
// scripts/hks_gonderim_yazdir_smoke.js — Hal Kayıt "Gönderimi doğrudan yazdır" (v414)
//   node scripts/hks_gonderim_yazdir_smoke.js   → çıkış 0 = geçti
// Gönderilenler'deki "🖨️ Yazdır" ve Gönderim Sonucu penceresindeki "🖨️ Yazdır":
// Çoklu Künye ekranına uğramadan pencere açılır, HKS canlı sorgusu (toplu_kunye) gider,
// sonuç YALNIZ o gönderimin künyelerine süzülür, yazdırma kendiliğinden açılır.
// Çoklu Künye Basım ekranı eskisi gibi çalışır. api.php sahte JSON'dur (canlıya/DB'ye
// dokunmaz). Playwright yoksa ATLAR.
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
  urunler: [{ id: 1, ad: 'Nar' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }], bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }];
const K = (no, miktar, urun) => ({ kunyeNo: no, urun: urun || 'Nar', miktar, birim: 'Kg', nereden: 'Mersin', nereye: 'Rusya',
  bildirimci: 'Test Firma A', malSahibi: 'Örnek Sahip', plaka: '33NCN99', belgeNo: '', tarih: '2026-10-21' });
// Aynı gün AYNI plakaya iki gönderim: g1 (A1, A2) ve g2 (B1). HKS plaka+gün sorgusu üçünü birden döndürür.
function gonderilenler() {
  return [
    { id: 'g1', zaman: '2026-10-21T15:40:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '33NCN99', belgeNo: '',
      ulkeAd: 'Satın Alım ← Mehmet', urunAd: 'Nar', adet: 2, toplamKg: 22000, fiyat: 27, rusum: 0, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SATIN_ALIM', yeniKunyeler: ['3601000000000001', '3601000000000002'] },
    { id: 'g2', zaman: '2026-10-21T15:40:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '33NCN99', belgeNo: '',
      ulkeAd: 'Satın Alım ← Aslı', urunAd: 'Nar', adet: 1, toplamKg: 22000, fiyat: 25, rusum: 0, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SATIN_ALIM', yeniKunyeler: ['3601000000000003'] },
    { id: 'g3', zaman: '2026-10-20T23:16:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '', belgeNo: 'B-77',
      ulkeAd: 'Rusya', urunAd: 'Nar', adet: 2, toplamKg: 1680, fiyat: 50, rusum: 0, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SATIS', yeniKunyeler: [] },
  ];
}
// Sınır testi: 32 gönderim
function cokGonderim() {
  return Array.from({ length: 32 }, (_, k) => Object.assign({}, gonderilenler()[0], { id: 'c' + k, plaka: '07CK' + String(k).padStart(3, '0') }));
}
const HKS_HEPSI = [K('3601000000000001', 12000), K('3601000000000002', 10000), K('3601000000000003', 22000)];

// Sahte api.php TEST SUNUCUSUNDA cevaplanır (Playwright route() DEĞİL): route ile yakalama
// açıkken, yazdırma penceresine (about:blank → document.write) sonradan eklenen <script>
// istekleri (html2canvas/jspdf) hiç gönderilmeyip takılıyordu — gerçek tarayıcıda sorun yok.
const D = { istekler: [], topluYanit: () => ({ kunyeler: HKS_HEPSI, toplam: 44000 }), topluGecikme: 0, gonderYanit: {} };
async function apiYanit(action, g) {
  D.istekler.push({ action, govde: g });
  switch (action) {
    case 'firmalar': return [200, { firmalar: FIRMALAR }];
    case 'sonlar': return [200, { ulkeler: [], urunler: [] }];
    case 'listeler': return [200, LISTELER];
    case 'taslaklar': return [200, { taslaklar: D.taslaklar || [] }];
    case 'ayni_plaka_bugun': {
      if (D.plakaGecikme) await new Promise(r2 => setTimeout(r2, D.plakaGecikme));
      const y = D.plakaYanit ? D.plakaYanit(g) : { gonderimler: [] };
      return [y.hata ? 400 : 200, y];
    }
    case 'gonderilenler': return [200, { gonderilenler: D.cok ? cokGonderim() : gonderilenler() }];
    case 'stok': return [200, { stok: [], toplam: 0 }];
    case 'csrf': return [200, { csrf: 'taze456' }];
    case 'toplu_kunye': {
      if (D.topluGecikme) await new Promise(r2 => setTimeout(r2, D.topluGecikme));
      const y = D.topluYanit(g);
      return [y.hata ? 400 : 200, y];
    }
    case 'taslak_gonder': return [200, D.gonderYanit];
    default: return [200, {}];
  }
}
const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/api.php')) {
    let govde = '';
    q.on('data', c => { govde += c; });
    q.on('end', async () => {
      let g = {}; try { g = JSON.parse(govde || '{}'); } catch (e) {}
      const [st, o] = await apiYanit(new URL(q.url, 'http://x').searchParams.get('action'), g);
      s.statusCode = st; s.setHeader('Content-Type', 'application/json'); s.end(JSON.stringify(o));
    });
    return;
  }
  const js = /^\/(html2canvas\.min\.js|jspdf\.umd\.min\.js|qrcode\.js)/.exec(q.url);
  if (js) { s.setHeader('Content-Type', 'application/javascript'); s.end(fs.readFileSync(path.join(KOK, 'halkayit', js[1]))); return; }
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];

  async function sayfaKur(viewport) {
    const ctx = await tarayici.newContext({ viewport });
    // print() çağrısını say (açılan yazdırma pencereleri de bu bağlamda).
    await ctx.addInitScript(() => { window.print = () => { window.__basildi = (window.__basildi || 0) + 1; }; });
    const sayfa = await ctx.newPage();
    sayfa.on('pageerror', e => hatalar.push(e.message));
    sayfa.on('dialog', d => d.accept());
    await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
    await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    return { ctx, sayfa };
  }
  const gonderilenleriAc = async (sayfa) => {
    await sayfa.click('#btnGonderilenler');
    await sayfa.waitForSelector('#gonderilenListe tr.g-satir, #gonderilenListe .g-kart');
    await sayfa.waitForTimeout(100);
  };
  // Yazdır düğmesine bas → açılan pencereyi ve son toplu_kunye isteğini döndür.
  async function yazdirBas(sayfa, tiklat) {
    D.istekler.length = 0;
    const [p] = await Promise.all([sayfa.waitForEvent('popup'), tiklat()]);
    return p;
  }
  const tabloBekle = (p) => p.waitForSelector('.kunye-tablo', { timeout: 5000 });
  const kunyeNolar = (p) => p.$$eval('.kunye-tablo tbody tr .qrno', a => a.map(e => e.textContent.trim()));
  const basildi = (p) => p.evaluate(() => window.__basildi || 0);
  const sonTk = () => D.istekler.filter(i => i.action === 'toplu_kunye').pop();

  // ================= Gönderilenler → Yazdır (masaüstü) =================
  const { ctx, sayfa } = await sayfaKur({ width: 1280, height: 800 });
  await gonderilenleriAc(sayfa);
  const satir = (i) => sayfa.locator('tr.g-satir').nth(i);

  // (A) süzme: aynı plaka + gün, yalnız g1'in künyeleri
  D.topluGecikme = 300;
  let p = await yazdirBas(sayfa, () => satir(0).locator('.g-yazdir-btn-ust').click());
  await p.waitForFunction(() => /alınıyor/.test(document.body.innerText));
  ok('(A) pencere tıklama anında açılır, sorgu sürerken "alınıyor" yazar', true);
  D.topluGecikme = 0;
  await tabloBekle(p);
  let tk = sonTk();
  ok('(A) toplu_kunye: plaka + gün + aktif firma', !!tk && tk.govde.plaka === '33NCN99' && tk.govde.tarih === '2026-10-21' && String(tk.govde.firmaId) === '7', JSON.stringify(tk && tk.govde));
  ok('(A) yalnız BU gönderimin künyeleri (aynı plakadaki başka gönderimin künyesi YOK)',
     JSON.stringify(await kunyeNolar(p)) === JSON.stringify(['3601000000000001', '3601000000000002']), JSON.stringify(await kunyeNolar(p)));
  ok('(A) toplam yalnız süzülen künyeler (22.000 KG)', /TOPLAM\s*22\.000 KG/.test(await p.innerText('.kunye-tablo tfoot')), await p.innerText('.kunye-tablo tfoot'));
  ok('(A) başlık: firma + plaka + tarih', /Test Firma A/.test(await p.innerText('h2')) && /33NCN99/.test(await p.innerText('.meta')) && /2026-10-21/.test(await p.innerText('.meta')));
  await p.waitForTimeout(600);
  ok('(A) yazdırma penceresi KENDİLİĞİNDEN açıldı (print 1 kez)', (await basildi(p)) === 1, String(await basildi(p)));
  ok('(A) Yazdır + PDF/Paylaş düğmeleri pencerede', await p.locator('.btnlar .yzbtn').count() === 2);
  ok('(A) eksik uyarısı yok', await p.locator('#yzUyari').count() === 0);
  ok('(A) Çoklu Künye ekranı AÇILMADI, Gönderilenler yerinde', !(await sayfa.isVisible('#ekranToplu')) && await sayfa.isVisible('#ekranGonderilen'));
  await p.close();

  // (A2) ikinci satır (g2) aynı plakadan yalnız kendi künyesini basar
  p = await yazdirBas(sayfa, () => satir(1).locator('.g-yazdir-btn-ust').click());
  await tabloBekle(p);
  ok('(A2) g2 → yalnız 3601000000000003', JSON.stringify(await kunyeNolar(p)) === '["3601000000000003"]', JSON.stringify(await kunyeNolar(p)));
  await p.close();

  // (B) kısmi: beklenen 2 künyeden 1'i HKS'te yok → uyarı, otomatik yazdırma YOK
  D.topluYanit = () => ({ kunyeler: [HKS_HEPSI[0], HKS_HEPSI[2]], toplam: 0 });
  p = await yazdirBas(sayfa, () => satir(0).locator('.g-yazdir-btn-ust').click());
  await tabloBekle(p);
  await p.waitForTimeout(600);
  ok('(B) kısmi: bulunan 1 künye listelenir', JSON.stringify(await kunyeNolar(p)) === '["3601000000000001"]');
  ok('(B) kısmi: "2 künyeden 1 tanesi görünmedi" uyarısı', /2 künyeden 1 tanesi/.test(await p.innerText('#yzUyari')));
  ok('(B) kısmi: uyarı baskıda gizli', await p.evaluate(() => { const st = [...document.styleSheets[0].cssRules].map(r => r.cssText).join(' '); return /\.yzuyari/.test(st) && /@media print/.test(st); }));
  ok('(B) kısmi: yazdırma kendiliğinden AÇILMADI', (await basildi(p)) === 0);
  await p.close();

  // (C) hiç bulunamadı → hata sayfası + Tekrar dene ile düzelir
  D.topluYanit = () => ({ kunyeler: [HKS_HEPSI[2]], toplam: 0 });
  p = await yazdirBas(sayfa, () => satir(0).locator('.g-yazdir-btn-ust').click());
  await p.waitForSelector('#yzTekrar');
  ok('(C) bulunamadı: açıklama + Tekrar dene + Kapat', /bulunamadı/.test(await p.innerText('#yzDurum')) && /2 künye bekleniyordu/.test(await p.innerText('#yzDurum')) && await p.locator('#yzKapat').count() === 1);
  D.topluYanit = () => ({ kunyeler: HKS_HEPSI, toplam: 0 });
  await p.click('#yzTekrar');
  await tabloBekle(p);
  ok('(C) Tekrar dene → yeniden sorgu, tablo geldi', (await kunyeNolar(p)).length === 2);
  await p.close();

  // (D) HKS hatası
  D.topluYanit = () => ({ hata: 'GTBGLB00000001 hata <b>x</b>' });
  p = await yazdirBas(sayfa, () => satir(0).locator('.g-yazdir-btn-ust').click());
  await p.waitForSelector('#yzTekrar');
  const dm = await p.innerText('#yzDurum');
  ok('(D) HKS hatası metni + ipucu, HTML yorumlanmaz', /HKS sorgusu başarısız/.test(dm) && /GTBGLB00000001/.test(dm) && /<b>x<\/b>/.test(dm) && await p.locator('#yzDurum b').count() === 0, dm);
  await p.close();

  // (E) eski kayıt (künye listesi yok, belge no) → süzme yok, belge no ile sorgu
  D.topluYanit = () => ({ kunyeler: [K('3601000000000010', 840, 'Nar <img src=x onerror="window.__xss=1">'), K('3601000000000011', 840)], toplam: 0 });
  p = await yazdirBas(sayfa, () => satir(2).locator('.g-yazdir-btn-ust').click());
  await tabloBekle(p);
  tk = sonTk();
  ok('(E) eski kayıt: belge no ile sorgu, tüm künyeler', tk.govde.belgeNo === 'B-77' && tk.govde.plaka === '' && (await kunyeNolar(p)).length === 2, JSON.stringify(tk.govde));
  ok('(E) kullanıcı verisi kaçışlı (img yok, xss yok)', await p.locator('.kunye-tablo img:not([width="88"])').count() === 0 && !(await p.evaluate(() => window.__xss)));
  await p.close();

  // (F) açılır pencere engellendi → toast, istek yok
  await sayfa.evaluate(() => { window.__eskiOpen = window.open; window.open = () => null; });
  D.istekler.length = 0;
  await satir(0).locator('.g-yazdir-btn-ust').click();
  await sayfa.waitForTimeout(200);
  ok('(F) engellendi: uyarı gösterilir, HKS sorgusu atılmaz', /engellendi/.test(await sayfa.innerText('body')) && !sonTk());
  await sayfa.evaluate(() => { window.open = window.__eskiOpen; });

  // ================= Toplu Yazdır (v415) =================
  D.topluYanit = (g) => g.belgeNo === 'B-77'
    ? { kunyeler: [K('3601000000000010', 840), K('3601000000000011', 840)], toplam: 0 }
    : { kunyeler: HKS_HEPSI, toplam: 0 };
  await sayfa.click('#btnGonderilenGeri');
  await gonderilenleriAc(sayfa);
  const kutu = (i) => satir(i).locator('.g-sec-kutu');
  ok('(T) her satırda seçim kutusu, başlangıçta alt çubuk gizli', await sayfa.locator('tr.g-satir .g-sec-kutu').count() === 3 && !(await sayfa.isVisible('#gTopluBar')));
  await kutu(0).click();
  ok('(T) kutu → satır AÇILMADI, satır vurgulu, çubuk "1 gönderim seçili"',
     (await satir(0).getAttribute('aria-expanded')) === 'false' && await sayfa.locator('tr.g-detay-tr:visible').count() === 0 &&
     /g-secili/.test(await satir(0).getAttribute('class')) && await sayfa.isVisible('#gTopluBar') && (await sayfa.innerText('#gTopluSay')) === '1');
  // label'a (kutunun çevresine) tık da satırı açmaz
  await satir(2).locator('label.g-sec').click({ position: { x: 1, y: 1 } });
  ok('(T) kutunun çevresine tık da satırı açmaz, seçer', (await satir(2).getAttribute('aria-expanded')) === 'false' && (await sayfa.innerText('#gTopluSay')) === '2');
  await kutu(1).click();
  // süzgeç seçimi korur
  await sayfa.fill('#gonderilenAra', 'B-77'); await sayfa.waitForTimeout(250);
  ok('(T) süzgeçte seçim korunur (kutu işaretli, sayaç 3)', await sayfa.locator('tr.g-satir').count() === 1 && await sayfa.locator('tr.g-satir .g-sec-kutu').isChecked() && (await sayfa.innerText('#gTopluSay')) === '3');
  await sayfa.click('#btnGonderilenAraTemizle'); await sayfa.waitForTimeout(150);
  ok('(T) süzgeç temizlenince üç kutu işaretli', (await sayfa.$$eval('tr.g-satir .g-sec-kutu', a => a.map(k => k.checked))).join() === 'true,true,true');
  const altBar = await sayfa.evaluate(() => { const b = gTopluBar.getBoundingClientRect(); return b.bottom <= innerHeight && b.left >= 0 && b.right <= innerWidth && getComputedStyle(gTopluBar).position === 'fixed'; });
  ok('(T) alt çubuk sabit, ekran içinde', altBar);

  D.topluGecikme = 250;
  p = await yazdirBas(sayfa, () => sayfa.click('#btnGTopluYazdir'));
  await p.waitForFunction(() => /gönderim HKS'ten alındı/.test(document.body.innerText));
  ok('(T) tek pencere, ilerleme "k / 3 gönderim HKS\'ten alındı"', /\d \/ 3 gönderim/.test(await p.innerText('#yzDurum')));
  D.topluGecikme = 0;
  await p.waitForSelector('.yz-bolum', { timeout: 8000 });
  const tkler = D.istekler.filter(i => i.action === 'toplu_kunye').map(i => i.govde.plaka + '|' + i.govde.belgeNo + '|' + i.govde.tarih);
  ok('(T) aynı plaka+gün için TEK sorgu (3 gönderim → 2 HKS sorgusu)', tkler.length === 2 && tkler.includes('33NCN99||2026-10-21') && tkler.includes('|B-77|2026-10-20'), JSON.stringify(tkler));
  const bolumler = await p.$$eval('.yz-bolum', a => a.map(b => ({
    meta: b.querySelector('.meta').textContent, h2: b.querySelector('h2').textContent,
    no: [...b.querySelectorAll('.kunye-tablo tbody .qrno')].map(e => e.textContent.trim()),
    top: b.querySelector('.kunye-tablo tfoot').textContent })));
  ok('(T) 3 bölüm, listedeki sırayla', bolumler.length === 3, JSON.stringify(bolumler.map(b => b.no)));
  ok('(T) her bölüm yalnız kendi künyeleri (g1: A1,A2 · g2: B1 · g3 eski: hepsi)',
     JSON.stringify(bolumler.map(b => b.no)) === JSON.stringify([['3601000000000001', '3601000000000002'], ['3601000000000003'], ['3601000000000010', '3601000000000011']]));
  ok('(T) her bölüm tekli çıktıyla aynı başlık + kendi toplamı',
     bolumler.every(b => /Bildirim Çoklu Künye Basım — Test Firma A/.test(b.h2)) && /33NCN99/.test(bolumler[1].meta) && /B-77/.test(bolumler[2].meta) &&
     /22\.000 KG/.test(bolumler[0].top) && /22\.000 KG/.test(bolumler[1].top) && /1\.680 KG/.test(bolumler[2].top), bolumler.map(b => b.top).join('|'));
  const sayfaSonu = await p.evaluate(() => {
    const k = [...document.styleSheets[0].cssRules].find(r => r.media && /print/.test(r.media.mediaText));
    return !!k && [...k.cssRules].some(r => /\.yz-bolum \+ \.yz-bolum/.test(r.selectorText) && /page/.test(r.style.breakBefore || r.style.pageBreakBefore));
  });
  ok('(T) baskıda her gönderim YENİ SAYFADAN başlar (break-before: page)', sayfaSonu);
  await p.waitForTimeout(600);
  ok('(T) sorunsuz → yazdırma kendiliğinden açıldı (1 kez), uyarı yok', (await basildi(p)) === 1 && await p.locator('#yzUyari').count() === 0);
  ok('(T) pencere başlığı/dosya adı "Kunyeler 3 gonderim-Test Firma A"', (await p.title()) === 'Kunyeler 3 gonderim-Test Firma A', await p.title());
  // PDF: her gönderim yeni PDF sayfasından başlar
  const [ind] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }), p.click('#btnPaylas')]);
  const pdfYol = await ind.path();
  const pdfSayfa = (fs.readFileSync(pdfYol, 'latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
  ok('(T) PDF İndir: 3 gönderim → 3 sayfa, ad "Kunyeler 3 gonderim-Test Firma A.pdf"', pdfSayfa === 3 && ind.suggestedFilename() === 'Kunyeler 3 gonderim-Test Firma A.pdf', pdfSayfa + ' / ' + ind.suggestedFilename());
  await p.close();

  // kısmi hata: eski kayıt sorgusu HKS hatası → 2 gönderim basılır, uyarı listeler, otomatik print YOK
  D.topluYanit = (g) => g.belgeNo === 'B-77' ? { hata: 'HKS meşgul' } : { kunyeler: HKS_HEPSI, toplam: 0 };
  p = await yazdirBas(sayfa, () => sayfa.click('#btnGTopluYazdir'));
  await p.waitForSelector('.yz-bolum', { timeout: 8000 });
  await p.waitForTimeout(600);
  const uy = await p.innerText('#yzUyari');
  ok('(T) kısmi: 2 bölüm, uyarıda "2 / 3" + basılmayan gönderim ve sebebi', await p.locator('.yz-bolum').count() === 2 && /2 \/ 3 gönderim basılıyor/.test(uy) && /B-77 · .*basılmadı: HKS sorgusu başarısız: HKS meşgul/.test(uy), uy);
  ok('(T) kısmi: yazdırma kendiliğinden AÇILMADI', (await basildi(p)) === 0);
  await p.close();

  // hepsi başarısız → hata sayfası + Tekrar dene
  D.topluYanit = () => ({ hata: 'Bağlantı yok' });
  p = await yazdirBas(sayfa, () => sayfa.click('#btnGTopluYazdir'));
  await p.waitForSelector('#yzTekrar');
  ok('(T) hepsi başarısız: hata sayfası + Tekrar dene', /hiçbiri basılamadı/.test(await p.innerText('#yzDurum')));
  D.topluYanit = () => ({ kunyeler: HKS_HEPSI, toplam: 0 });
  await p.click('#yzTekrar');
  await p.waitForSelector('.yz-bolum', { timeout: 8000 });
  ok('(T) Tekrar dene → bölümler geldi', await p.locator('.yz-bolum').count() >= 2);
  await p.close();

  await sayfa.click('#btnGTopluTemizle');
  ok('(T) Temizle: seçim kalkar, çubuk gizlenir', !(await sayfa.isVisible('#gTopluBar')) && (await sayfa.$$eval('tr.g-satir .g-sec-kutu', a => a.every(k => !k.checked))) && await sayfa.locator('tr.g-satir.g-secili').count() === 0);
  await kutu(0).click();
  await sayfa.click('#btnGonderilenGeri');
  await sayfa.click('#btnGonderilenler');
  await sayfa.waitForSelector('tr.g-satir');
  ok('(T) ekran yeniden açılınca seçim sıfırlanır', !(await sayfa.isVisible('#gTopluBar')) && await sayfa.locator('tr.g-satir .g-sec-kutu:checked').count() === 0);

  // sınır: 30
  D.cok = true;
  await sayfa.click('#btnGonderilenGeri');
  await gonderilenleriAc(sayfa);
  for (let k = 0; k < 31; k++) await sayfa.locator('tr.g-satir .g-sec-kutu').nth(k).click();
  ok('(T) en çok 30 gönderim: 31. kutu işaretlenmez + uyarı', (await sayfa.innerText('#gTopluSay')) === '30' &&
     !(await sayfa.locator('tr.g-satir .g-sec-kutu').nth(30).isChecked()) && /en çok 30/.test(await sayfa.innerText('body')));
  D.cok = false;
  await sayfa.click('#btnGonderilenGeri');
  await gonderilenleriAc(sayfa);

  // ================= Aynı gün aynı plaka uyarısı (v416) =================
  const TASLAK = (id, plaka, belgeNo, ayni) => ({ id, zaman: '2026-10-21T16:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A',
    satirlar: [{ kunyeNo: '3601000000000001', miktar: 22000 }], ortak: { plaka, belgeNo, urunAd: 'Nar', ulkeAd: 'Rusya', fiyat: 25 },
    ayniPlakaBugun: ayni });
  D.taslaklar = [TASLAK('t1', '33NCN99', '', 2), TASLAK('t2', '07ABC12', '', 0), TASLAK('t3', '', '', 0)];
  D.plakaYanit = (g) => g.plaka === '33NCN99' ? { gonderimler: [
    { id: 'g2', zaman: '2026-10-21 15:40:00', plaka: '33NCN99', ulkeAd: 'Satın Alım ← Aslı <img src=x onerror="window.__xss2=1">', urunAd: 'Nar', toplamKg: 22000 },
    { id: 'g1', zaman: '2026-10-21 08:10:00', plaka: '33NCN99', ulkeAd: 'Satın Alım ← Mehmet', urunAd: 'Nar', toplamKg: 21500.4 },
  ] } : { gonderimler: [] };
  await sayfa.click('#btnGonderilenGeri');
  await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('.taslak-kart');
  const kartlar = sayfa.locator('.taslak-kart');
  ok('(P) taslak kartı: bugün aynı plakaya gönderim varsa plaka satırında uyarı',
     /Bugün bu plakaya 2 gönderim yapıldı/.test(await kartlar.nth(0).locator('.tk-plaka').innerText()) &&
     await kartlar.nth(1).locator('.tk-plaka .tk-uyari').count() === 0);
  const onayAc = async (i) => { await kartlar.nth(i).locator('[data-gonder]').click(); await sayfa.waitForSelector('#modalOnayKutu:not(.gizli)'); };
  D.plakaGecikme = 400; D.istekler.length = 0;
  await onayAc(0);
  ok('(P) onay açılınca kontrol sürerken düğme KİLİTLİ', await sayfa.isDisabled('#btnOnay') && /kontrol/.test(await sayfa.innerText('#btnOnay')));
  await sayfa.waitForSelector('.onay-plaka-uyari');
  const pk = D.istekler.find(i => i.action === 'ayni_plaka_bugun');
  ok('(P) istek: aktif firma + plaka', !!pk && String(pk.govde.firmaId) === '7' && pk.govde.plaka === '33NCN99', JSON.stringify(pk && pk.govde));
  const uyM = await sayfa.innerText('.onay-plaka-uyari');
  ok('(P) uyarı: "Bugün bu plakaya (33NCN99) 2 gönderim" + saat · karşı taraf · ürün · kg',
     /Bugün bu plakaya \(33NCN99\) 2 gönderim yapıldı/.test(uyM) && /15:40 · Satın Alım ← Aslı/.test(uyM) && /08:10 · Satın Alım ← Mehmet · Nar · 21\.500 KG/.test(uyM), uyM);
  ok('(P) uyarı engel DEĞİL: düğme açık, "Yine de Gönder"', !(await sayfa.isDisabled('#btnOnay')) && (await sayfa.innerText('#btnOnay')).trim() === 'Yine de Gönder');
  ok('(P) kullanıcı verisi HTML olarak yorumlanmaz', await sayfa.locator('.onay-plaka-uyari img').count() === 0 && !(await sayfa.evaluate(() => window.__xss2)));
  D.gonderYanit = { sonuclar: [{ yeniKunyeNo: '3601000000000009', miktar: 22000 }] };
  D.istekler.length = 0;
  await sayfa.click('#btnOnay');
  await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  ok('(P) "Yine de Gönder" gönderir', D.istekler.some(i => i.action === 'taslak_gonder' && i.govde.id === 't1'));
  await sayfa.click('#btnModalX');
  await sayfa.waitForSelector('.taslak-kart');
  // eşleşme yok
  await onayAc(1);
  await sayfa.waitForFunction(() => !document.getElementById('btnOnay').disabled);
  ok('(P) eşleşme yoksa uyarı yok, düğme "Evet, Gönder"', await sayfa.locator('.onay-plaka-uyari').count() === 0 && (await sayfa.innerText('#btnOnay')).trim() === 'Evet, Gönder');
  await sayfa.click('#btnVazgec');
  // plaka + belge yok → istek yok
  D.istekler.length = 0;
  await onayAc(2);
  ok('(P) plaka/belge yoksa kontrol isteği atılmaz, düğme açık', !D.istekler.some(i => i.action === 'ayni_plaka_bugun') && !(await sayfa.isDisabled('#btnOnay')));
  await sayfa.click('#btnVazgec');
  // uç hatası → küçük not, gönderim serbest
  D.plakaYanit = () => ({ hata: 'veritabanı meşgul' });
  await onayAc(0);
  await sayfa.waitForSelector('.onay-plaka-kontrol-yok');
  ok('(P) kontrol yapılamazsa not + düğme açık "Evet, Gönder"', !(await sayfa.isDisabled('#btnOnay')) && (await sayfa.innerText('#btnOnay')).trim() === 'Evet, Gönder');
  await sayfa.click('#btnVazgec');
  // yavaş yanıt: 6 sn sonra düğme açılır; eski yanıt sonradan gelse de başka taslağın onayını bozmaz
  D.plakaYanit = (g) => g.plaka === '33NCN99' ? { gonderimler: [{ id: 'g1', zaman: '2026-10-21 08:10:00', ulkeAd: 'X', urunAd: 'Nar', toplamKg: 1 }] } : { gonderimler: [] };
  D.plakaGecikme = 7500;
  await onayAc(0);
  await sayfa.waitForFunction(() => !document.getElementById('btnOnay').disabled, null, { timeout: 9000 });
  ok('(P) yanıt 6 sn içinde gelmezse düğme açılır (kontrol yapılamadı notu)', await sayfa.locator('.onay-plaka-kontrol-yok').count() === 1);
  await sayfa.click('#btnVazgec');
  D.plakaGecikme = 0;
  await onayAc(1);
  await sayfa.waitForTimeout(2200);   // t1'in geç yanıtı bu arada gelir
  ok('(P) geç gelen eski yanıt başka taslağın onayına uyarı YAZMAZ', await sayfa.locator('.onay-plaka-uyari').count() === 0 && (await sayfa.innerText('#btnOnay')).trim() === 'Evet, Gönder');
  await sayfa.click('#btnVazgec');
  D.plakaYanit = null;
  await sayfa.click('#btnTaslakGeri');
  await gonderilenleriAc(sayfa);

  // ================= Gönderim Sonucu → Yazdır =================
  D.topluYanit = () => ({ kunyeler: HKS_HEPSI, toplam: 0 });
  async function gonder(yanit) {
    D.gonderYanit = yanit;
    await sayfa.evaluate(() => { document.getElementById('modalBaslik').textContent = ''; });
    await sayfa.evaluate(() => { gonderilecekTaslakId = 'T1'; document.getElementById('modal').classList.add('acik'); document.getElementById('btnOnay').click(); });
    await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  }
  // Onay penceresi (CANLI) açılışında Yazdır yok
  await sayfa.evaluate(() => taslakOnayAc({ id: 'T1', firmaAd: 'Test Firma A', satirlar: [{ miktar: 10 }], ortak: { plaka: '33NCN99', fiyat: 1 } }));
  ok('(G) CANLI onay penceresinde Yazdır GİZLİ', !(await sayfa.isVisible('#btnSonucYazdir')) && (await sayfa.innerText('#btnVazgec')).trim() === 'Vazgeç');
  await gonder({ sonuclar: [{ yeniKunyeNo: '3601000000000003', miktar: 22000 }],
    gonderim: { id: 'g9', plaka: '33NCN99', belgeNo: '', tarih: '2026-10-21', kunyeler: ['3601000000000003'] } });
  ok('(G) başarılı sonuç: Yazdır görünür, yanında "Gönderilenlere Git"', await sayfa.isVisible('#btnSonucYazdir') && (await sayfa.innerText('#btnVazgec')).trim() === 'Gönderilenlere Git');
  const yan = await sayfa.evaluate(() => { const a = btnSonucYazdir.getBoundingClientRect(), b = btnVazgec.getBoundingClientRect(); return Math.abs(a.top - b.top) < 2 && a.right <= b.left; });
  ok('(G) iki düğme yan yana (aynı satır, Yazdır solda)', yan);
  p = await yazdirBas(sayfa, () => sayfa.click('#btnSonucYazdir'));
  await tabloBekle(p);
  tk = sonTk();
  ok('(G) sonuçtan Yazdır → gönderimin plaka/günü ile sorgu, yalnız gönderilen künye',
     tk.govde.plaka === '33NCN99' && tk.govde.tarih === '2026-10-21' && JSON.stringify(await kunyeNolar(p)) === '["3601000000000003"]', JSON.stringify(tk.govde));
  await p.waitForTimeout(600);
  ok('(G) yazdırma kendiliğinden açıldı', (await basildi(p)) === 1);
  ok('(G) Gönderim Sonucu penceresi AÇIK kaldı', await sayfa.isVisible('#modalOnayKutu') && await sayfa.isVisible('#btnSonucYazdir'));
  await p.close();
  await sayfa.click('#btnModalX');
  ok('(G) ✕ ile kapanınca Yazdır gizlenir', !(await sayfa.isVisible('#btnSonucYazdir')));
  await gonder({ sonuclar: [{ miktar: 5, mesaj: 'red', hataKodu: 21 }], taslakKorundu: true });
  ok('(G) hiç künye oluşmadıysa Yazdır GİZLİ (Taslağa Dön)', !(await sayfa.isVisible('#btnSonucYazdir')) && (await sayfa.innerText('#btnVazgec')).trim() === 'Taslağa Dön');
  await sayfa.click('#btnModalX');
  await gonder({ sonuclar: [{ yeniKunyeNo: '3601000000000003', miktar: 22000 }] });
  ok('(G) eski sunucu yanıtı (gonderim yok) → Yazdır GİZLİ, akış çalışır', !(await sayfa.isVisible('#btnSonucYazdir')) && (await sayfa.innerText('#btnVazgec')).trim() === 'Gönderilenlere Git');
  await sayfa.click('#btnModalX');

  // ================= Çoklu Künye Basım — DEĞİŞMEDİ =================
  await sayfa.click('#btnGonderilenGeri');
  await sayfa.click('#btnTopluKunyeAna');
  await sayfa.fill('#tkPlaka', '33NCN99');
  await sayfa.fill('#tkTarih', '2026-10-21');
  D.istekler.length = 0;
  await sayfa.click('#btnTopluSorgula');
  await sayfa.waitForSelector('#tkSonucSarma .tk-tablo');
  ok('(H) Çoklu Künye: sorgu ekranda tüm künyeleri gösterir (süzme yok)', await sayfa.locator('#tkSonucSarma tbody tr').count() === 3);
  p = await yazdirBas(sayfa, () => sayfa.click('#btnTopluYazdir'));
  await tabloBekle(p);
  await p.waitForTimeout(600);
  ok('(H) Çoklu Künye Yazdır: 3 künye, otomatik print YOK (eski davranış)', (await kunyeNolar(p)).length === 3 && (await basildi(p)) === 0 && await p.locator('#yzUyari').count() === 0);
  const [indH] = await Promise.all([p.waitForEvent('download', { timeout: 20000 }), p.click('#btnPaylas')]);
  const pdfH = (fs.readFileSync(await indH.path(), 'latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
  ok('(H) Çoklu Künye PDF: tek bölüm → 1 sayfa, ad "33NCN99-Test Firma A.pdf" (eski davranış)', pdfH === 1 && indH.suggestedFilename() === '33NCN99-Test Firma A.pdf', pdfH + ' / ' + indH.suggestedFilename());
  await p.close();
  await ctx.close();

  // ================= Mobil 390: sonuç düğmeleri sığar =================
  const m = await sayfaKur({ width: 390, height: 800 });
  await m.sayfa.evaluate(() => { gonderilecekTaslakId = 'T1'; document.getElementById('modal').classList.add('acik'); });
  D.gonderYanit = { sonuclar: [{ yeniKunyeNo: '1', miktar: 1 }], gonderim: { plaka: 'X', tarih: '2026-10-21', kunyeler: ['1'] } };
  await m.sayfa.click('#btnOnay');
  await m.sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  const mob = await m.sayfa.evaluate(() => {
    const k = document.getElementById('modalOnayKutu').getBoundingClientRect();
    const a = btnSonucYazdir.getBoundingClientRect(), b = btnVazgec.getBoundingClientRect();
    return { icinde: a.left >= k.left && b.right <= k.right + 0.5, tasma: document.documentElement.scrollWidth > innerWidth + 1, tek: btnVazgec.scrollWidth <= btnVazgec.clientWidth + 1 };
  });
  ok('(M) 390px: iki düğme kutuda, yazı sığar, yatay taşma yok', mob.icinde && !mob.tasma && mob.tek, JSON.stringify(mob));
  // kartta seçim kutusu: kartı açıp kapatmaz, alt çubuk sığar
  await m.sayfa.click('#btnModalX');
  await gonderilenleriAc(m.sayfa);
  const kart0 = m.sayfa.locator('.g-kart').first();
  ok('(M) kartlarda seçim kutusu', await m.sayfa.locator('.g-kart .g-sec-kutu').count() === 3);
  await kart0.locator('.g-sec-kutu').click();
  await m.sayfa.locator('.g-kart').nth(1).locator('.g-sec-kutu').click();
  const mb = await m.sayfa.evaluate(() => { const b = gTopluBar.getBoundingClientRect();
    return { ici: b.left >= 0 && b.right <= innerWidth && b.bottom <= innerHeight, tasma: document.documentElement.scrollWidth > innerWidth + 1,
      tek: [...gTopluBar.querySelectorAll('button')].every(x => Math.abs(x.getBoundingClientRect().top - b.top) < 20) }; });
  ok('(M) kutu kartı AÇMADI, kart vurgulu, "2 gönderim seçili"', !/\bacik\b/.test(await kart0.getAttribute('class')) && /g-secili/.test(await kart0.getAttribute('class')) && (await m.sayfa.innerText('#gTopluSay')) === '2');
  ok('(M) 390px: alt çubuk tek satır, ekran içinde, yatay taşma yok', mb.ici && !mb.tasma && mb.tek, JSON.stringify(mb));
  const sonKartAlti = await m.sayfa.evaluate(() => { scrollTo(0, document.body.scrollHeight); const k = [...document.querySelectorAll('.g-kart')].pop().getBoundingClientRect(); return k.bottom <= gTopluBar.getBoundingClientRect().top + 1; });
  ok('(M) en alttaki kart alt çubuğun altında KALMAZ', sonKartAlti);
  await m.ctx.close();

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
