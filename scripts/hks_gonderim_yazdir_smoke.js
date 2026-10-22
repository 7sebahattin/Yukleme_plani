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
const HKS_HEPSI = [K('3601000000000001', 12000), K('3601000000000002', 10000), K('3601000000000003', 22000)];

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const D = { istekler: [], topluYanit: () => ({ kunyeler: HKS_HEPSI, toplam: 44000 }), topluGecikme: 0, gonderYanit: {} };

  async function sayfaKur(viewport) {
    const ctx = await tarayici.newContext({ viewport });
    // print() çağrısını say (açılan yazdırma pencereleri de bu bağlamda).
    await ctx.addInitScript(() => { window.print = () => { window.__basildi = (window.__basildi || 0) + 1; }; });
    const sayfa = await ctx.newPage();
    sayfa.on('pageerror', e => hatalar.push(e.message));
    sayfa.on('dialog', d => d.accept());
    await sayfa.route('**/api.php*', async (route) => {
      const r = route.request();
      const action = new URL(r.url()).searchParams.get('action');
      let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
      D.istekler.push({ action, govde: g });
      const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
      switch (action) {
        case 'firmalar': return j({ firmalar: FIRMALAR });
        case 'sonlar': return j({ ulkeler: [], urunler: [] });
        case 'listeler': return j(LISTELER);
        case 'taslaklar': return j({ taslaklar: [] });
        case 'gonderilenler': return j({ gonderilenler: gonderilenler() });
        case 'stok': return j({ stok: [], toplam: 0 });
        case 'csrf': return j({ csrf: 'taze456' });
        case 'toplu_kunye': {
          if (D.topluGecikme) await new Promise(r2 => setTimeout(r2, D.topluGecikme));
          const y = D.topluYanit(g);
          return y.hata ? j(y, 400) : j(y);
        }
        case 'taslak_gonder': return j(D.gonderYanit);
        default: return j({});
      }
    });
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
  const tabloBekle = (p) => p.waitForSelector('#kunyeTablo', { timeout: 5000 });
  const kunyeNolar = (p) => p.$$eval('#kunyeTablo tbody tr .qrno', a => a.map(e => e.textContent.trim()));
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
  ok('(A) toplam yalnız süzülen künyeler (22.000 KG)', /TOPLAM\s*22\.000 KG/.test(await p.innerText('#kunyeTablo tfoot')), await p.innerText('#kunyeTablo tfoot'));
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
  ok('(E) kullanıcı verisi kaçışlı (img yok, xss yok)', await p.locator('#kunyeTablo img:not([width="88"])').count() === 0 && !(await p.evaluate(() => window.__xss)));
  await p.close();

  // (F) açılır pencere engellendi → toast, istek yok
  await sayfa.evaluate(() => { window.__eskiOpen = window.open; window.open = () => null; });
  D.istekler.length = 0;
  await satir(0).locator('.g-yazdir-btn-ust').click();
  await sayfa.waitForTimeout(200);
  ok('(F) engellendi: uyarı gösterilir, HKS sorgusu atılmaz', /engellendi/.test(await sayfa.innerText('body')) && !sonTk());
  await sayfa.evaluate(() => { window.open = window.__eskiOpen; });

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
  await m.ctx.close();

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
