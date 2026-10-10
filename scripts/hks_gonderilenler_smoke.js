// =========================================================
// scripts/hks_gonderilenler_smoke.js — Hal Kayıt "Gönderilenler" masaüstü tablo +
// "↻ Tekrar gönder" akışı (gerçek tarayıcı)
//   node scripts/hks_gonderilenler_smoke.js   → çıkış 0 = geçti
//   GSHOT=/tmp/shots node scripts/hks_gonderilenler_smoke.js  → ekran görüntüleri de kaydeder
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
const SHOT = process.env.GSHOT || '';
if (SHOT) fs.mkdirSync(SHOT, { recursive: true });
let fail = 0;
const ok = (ad, k, ip) => { if (!k) fail++; console.log(ad.padEnd(88) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };

const LISTELER = {
  urunler: [{ id: 1, ad: 'Kayısı' }, { id: 2, ad: 'Kiraz' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }, { id: 2, ad: 'Komisyoncu' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 2, ad: 'Satın Alım' }],
};
const FIRMALAR = [
  { id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' },
  { id: 8, ad: 'Beta Tarım Ltd.', vergiNo: '2222222222', renk: 'amber' },
  { id: 9, ad: 'Gamma <b>Gıda</b>', vergiNo: '3333333333', renk: 'rose' },
];
const UZUN_FIRMA = 'Çok Uzun Adlı Tarım Ürünleri İhracat Sanayi ve Ticaret Limited Şirketi Şubesi';
const UZUN_ULKE = 'Satın Alım ← Örnek Çok Uzun Adlı Müstahsil Ziraat Üreticisi Tarım Hayvancılık Ticaret';
const KUNYELER = ['3601234567890123', '3601234567890124', '3601234567890125'];
function gonderilenler() {
  return [
    { id: 'g1001', zaman: '2026-10-05T14:32:10+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '31AJE915', belgeNo: '',
      ulkeAd: 'Rusya', urunAd: 'Kayısı', adet: 3, toplamKg: 24500.4, fiyat: 25, rusum: 123.45, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SATIS', yeniKunyeler: KUNYELER },
    { id: 'g1002', zaman: '2026-10-04T09:05:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '', belgeNo: 'B-77',
      ulkeAd: 'Satın Alım ← Örnek Müstahsil', urunAd: 'Kiraz', adet: 1, toplamKg: 1200, fiyat: 18.5, rusum: 0, hataSayisi: 2, genelHata: '',
      bildirimTuru: 'SATIN_ALIM', yeniKunyeler: [] },
    { id: 'g1003', zaman: '2026-10-03T18:20:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '06ABC123', belgeNo: '',
      ulkeAd: 'Yurt içi → Hal Komisyoncu A.Ş.', urunAd: 'Kayısı', adet: 2, toplamKg: 8000, fiyat: 0, rusum: 0, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SEVK_ETME', yeniKunyeler: KUNYELER.slice(0, 2) },
    { id: 'g1004', zaman: '2026-10-02T07:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', plaka: '33XYZ99', belgeNo: '',
      ulkeAd: 'Üreticiden Sevk Alım ← Örnek Üretici (Mersin/Silifke)', urunAd: 'Kayısı', adet: 1, toplamKg: 15000, fiyat: 22.75, rusum: 0,
      hataSayisi: 0, genelHata: '', bildirimTuru: 'URETICIDEN_SEVK_ALIM', yeniKunyeler: ['3601234567890199'] },
    { id: 'g1005', zaman: '2026-09-30T12:00:00+03:00', firmaId: 7, firmaAd: UZUN_FIRMA, plaka: '35UZUN1', belgeNo: '',
      ulkeAd: UZUN_ULKE, urunAd: 'Kayısı', adet: 1, toplamKg: 1234567.8, fiyat: 100.126, rusum: 5, hataSayisi: 0, genelHata: '',
      bildirimTuru: 'SATIN_ALIM', yeniKunyeler: [] },
  ];
}

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

// Her sayfa kendi sahte sunucu durumuna sahiptir.
function durumYarat(extra) {
  return Object.assign({ istekler: [], firmalar: FIRMALAR, taslaklar: [], tohumGecikme: 0, tohumHata: false, dialoglar: [] }, extra || {});
}
async function sayfaKur(ctx, durum, hatalar) {
  const sayfa = await ctx.newPage();
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => { durum.dialoglar.push(d.message()); d.accept(); });
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    durum.istekler.push({ action, govde: g });
    const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: durum.firmalar });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: durum.taslaklar });
      case 'gonderilenler': return j({ gonderilenler: String(g.firmaId) === '7' ? gonderilenler() : [] });
      case 'stok': return j({ stok: [], toplam: 0 });
      case 'kunyeler': return j({ kunyeler: [] });
      case 'toplu_kunye': return j({ kunyeler: [], toplam: 0 });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'taslak_kaydet': return j({ tamam: true, id: 'yeni-t1' });
      case 'taslak_sil': return j({ tamam: true });
      case 'gonderilen_tohum': {
        if (durum.tohumGecikme) await new Promise(r2 => setTimeout(r2, durum.tohumGecikme));
        if (durum.tohumHata || g.id === 'yok') return j({ hata: 'Gönderim bulunamadı (veya bu firmaya ait değil).' }, 404);
        if (g.id === 'g1004') {          // künye satırlı (plana çevrilmemiş) tohum, uyarı yok
          return j({ tohum: { satirlar: [{ kunyeNo: '3601234567890123', miktar: 500 }],
            ortak: { sifatId: 1, bildirimTuruId: 1, plaka: '33XYZ99', urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya', fiyat: 22.75 } },
            kaynak: 'kopya', plana: false, notlar: [] });
        }
        const degisti = g.hedefFirmaId !== undefined;
        const ortak = {
          bildirimTuruId: 1, plaka: g.id === 'g1002' ? '' : '31AJE915', belgeNo: g.id === 'g1002' ? 'B-77' : '',
          urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya', fiyat: 25, planKg: 24500,
          planSorgu: { urunId: 1, aySayisi: 12, isletmeTuruId: 0, sirala: 'azalan' },
        };
        if (!degisti) ortak.sifatId = 1;
        const notlar = ['Önceki künyeler kullanıldığı için plan taslağı olarak açıldı — künyeler gönderimde stoktan seçilir.',
          '<b>x</b> HTML değil metin olmalı'];
        if (degisti) notlar.push('Firma değişti: bildirimci sıfatı ve işyeri/depo yeniden seçilmeli.');
        return j({ tohum: { satirlar: [], ortak }, kaynak: 'kopya', plana: true, notlar });
      }
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  return sayfa;
}
const gonderilenleriAc = async (sayfa) => {
  await sayfa.click('#btnGonderilenler');
  await sayfa.waitForSelector('#gonderilenListe .g-kart, #gonderilenListe .g-tablo, #gonderilenListe .yukleniyor:not(:has-text("Yükleniyor"))');
  await sayfa.waitForTimeout(100);
};
const gor = (sayfa, sel) => sayfa.isVisible(sel);
const yatayTasma = (sayfa) => sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
async function foto(sayfa, ad) { if (SHOT) await sayfa.screenshot({ path: path.join(SHOT, ad + '.png'), fullPage: false }); }

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];

  // ================= MASAÜSTÜ 1280×800 =================
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const D = durumYarat();
  const sayfa = await sayfaKur(ctx, D, hatalar);
  await sayfa.click('.firma-kart:not(.firma-ekle)');           // Test Firma A (id 7)
  ok('ana menüde "panel sürümü" yazısı YOK (v306: kaldırıldı; tek sürüm kaynağı sidebar APP_SURUM)',
    await sayfa.locator('#panelSurum').count() === 0
    && !(await sayfa.evaluate(() => /panel sürümü/i.test(document.body.innerText))));
  await gonderilenleriAc(sayfa);

  ok('masaüstü: TABLO çizildi, kart yok', await gor(sayfa, '.g-tablo') && await sayfa.locator('#gonderilenListe .g-kart').count() === 0);
  ok('5 gönderim = 5 satır', await sayfa.locator('tbody.g-grup > tr.g-satir').count() === 5);
  const basliklar = (await sayfa.locator('.g-tablo thead th').allTextContents()).map(t => t.trim());
  ok('sütun başlıkları', JSON.stringify(basliklar) === JSON.stringify(['Tarih', 'Firma', 'Plaka', 'Ülke / Müstahsil', 'Ürün', 'Kilo', 'Ücret', 'İşlem']), basliklar.join('|'));
  const hucre = async (satir, n) => (await sayfa.locator('tr.g-satir').nth(satir).locator('td').nth(n).innerText()).replace(/\s+/g, ' ').trim();
  const t0 = await hucre(0, 0);
  ok('satır1 tarih kısa (gg.aa.yy ss:dd) + ✓', /^▾?\s*\d\d\.\d\d\.\d\d \d\d:\d\d\s*✓$/.test(t0), t0);
  ok('satır1 firma / plaka', (await hucre(0, 1)) === 'Test Firma A' && (await hucre(0, 2)) === '31AJE915');
  ok('satır1 ülke: etiket + ad', (await hucre(0, 3)) === 'İhracat Rusya', await hucre(0, 3));
  ok('satır1 kilo TAM SAYI, binlik nokta (24500,4 → 24.500 KG)', (await hucre(0, 5)) === '24.500 KG', await hucre(0, 5));
  ok('satır1 ücret "25 TL/KG"', (await hucre(0, 6)) === '25 TL/KG', await hucre(0, 6));
  ok('satır2 hata durumu ✗2', /✗2$/.test(await hucre(1, 0)), await hucre(1, 0));
  ok('satır2 plaka yoksa belgeNo', (await hucre(1, 2)) === 'B-77');
  ok('satır2 önek atıldı, etiket "Satın alım"', (await hucre(1, 3)) === 'Satın alım Örnek Müstahsil', await hucre(1, 3));
  ok('satır2 title = tam metin', (await sayfa.locator('tr.g-satir').nth(1).locator('td.g-c-ulke').getAttribute('title')) === 'Satın Alım ← Örnek Müstahsil');
  ok('satır2 ücret 18,5 TL/KG (2 ondalığa kadar)', (await hucre(1, 6)) === '18,5 TL/KG', await hucre(1, 6));
  ok('satır3 "Yurt içi →" atıldı, "Sevk etme", fiyat 0 → "—"', (await hucre(2, 3)) === 'Sevk etme Hal Komisyoncu A.Ş.' && (await hucre(2, 6)) === '—', (await hucre(2, 3)) + ' / ' + (await hucre(2, 6)));
  ok('satır4 "Üreticiden Sevk Alım ←" atıldı', (await hucre(3, 3)) === 'Üretici sevk Örnek Üretici (Mersin/Silifke)', await hucre(3, 3));
  ok('satır5 ücret 100,13 TL/KG, kilo 1.234.568 KG', (await hucre(4, 6)) === '100,13 TL/KG' && (await hucre(4, 5)) === '1.234.568 KG', (await hucre(4, 6)) + ' / ' + (await hucre(4, 5)));
  ok('satır5 uzun firma "…" ile kısalır (title tam)',
     (await sayfa.locator('tr.g-satir').nth(4).locator('td.g-c-firma').getAttribute('title')) === UZUN_FIRMA &&
     await sayfa.evaluate(() => { const td = document.querySelectorAll('tr.g-satir')[4].querySelector('td.g-c-firma'); return td.scrollWidth > td.clientWidth; }));
  ok('1280: sayfada yatay taşma yok', !(await yatayTasma(sayfa)));
  ok('1280: tablo sarmalayıcıda kaydırma gerekmiyor', await sayfa.evaluate(() => { const s = document.querySelector('.g-tablo-sarma'); return s.scrollWidth <= s.clientWidth + 1; }));
  ok('her satırda 🖨 Yazdır + ↻ Tekrar gönder ▾',
     (await sayfa.locator('tr.g-satir .g-yazdir-btn-ust').count()) === 5 && (await sayfa.locator('tr.g-satir .g-btn-tekrar').first().innerText()).replace(/\s+/g, ' ').trim() === '↻ Tekrar gönder ▾');
  ok('satırlar klavyeyle odaklanır (tabindex=0, aria-expanded=false)',
     await sayfa.evaluate(() => [...document.querySelectorAll('tr.g-satir')].every(t => t.tabIndex === 0 && t.getAttribute('aria-expanded') === 'false')));
  await foto(sayfa, '01_masaustu_1280');

  // --- satıra tıkla: altında bugünkü kart açılır ---
  const satir0 = sayfa.locator('tr.g-satir').first();
  const detay0 = sayfa.locator('tbody.g-grup').first().locator('tr.g-detay-tr');
  ok('başlangıçta detay satırı yok (tembel)', await detay0.count() === 0);
  await satir0.locator('td.g-c-firma').click();
  ok('satıra tık → hemen altında detay kartı açılır', await detay0.count() === 1 && await detay0.isVisible() && (await satir0.getAttribute('aria-expanded')) === 'true');
  const kartMetin = await detay0.textContent();
  ok('kartta bugünkü içerik: Firma/Plaka/Ülke/Ürün/Kilo/Künye/Fiyat/Rüsum + künye çipleri',
     ['Firma', 'Plaka', 'Ülke', 'Ürün', 'Kilo', 'Künye', 'Fiyat', 'Rüsum', 'Künye Numaraları'].every(w => kartMetin.includes(w)) &&
     await detay0.locator('.g-kunye-cip').count() === 3 && kartMetin.includes('3601234567890123'), kartMetin.slice(0, 120));
  ok('kart satırın HEMEN altında (kardeş sıra)', await sayfa.evaluate(() => { const t = document.querySelector('tr.g-satir'); return t.nextElementSibling && t.nextElementSibling.classList.contains('g-detay-tr'); }));
  ok('açık kartta Yazdır / Tekrar gönder tekrarlanmaz', await detay0.locator('.g-yazdir-btn-ust, .g-btn-tekrar, .g-detay-eylem').count() === 0);
  ok('açıkken yatay taşma yok', !(await yatayTasma(sayfa)));
  await foto(sayfa, '02_masaustu_acik');
  await satir0.locator('td.g-c-firma').click();
  ok('ikinci tık → kapanır', !(await detay0.isVisible()) && (await satir0.getAttribute('aria-expanded')) === 'false');
  await satir0.focus();
  await sayfa.keyboard.press('Enter');
  ok('Enter → açılır', await detay0.isVisible() && (await satir0.getAttribute('aria-expanded')) === 'true');
  const scrollOnce = await sayfa.evaluate(() => scrollY);
  await sayfa.keyboard.press('Space');
  ok('Boşluk → kapanır (sayfa kaymaz)', !(await detay0.isVisible()) && (await sayfa.evaluate(() => scrollY)) === scrollOnce);
  // Başka bir satır bağımsız açılıp kapanır
  await sayfa.locator('tr.g-satir').nth(2).click();
  ok('satırlar bağımsız (3. satır açıldı, 1. kapalı)', await sayfa.locator('tr.g-detay-tr:visible').count() === 1 && !(await detay0.isVisible()));
  await sayfa.locator('tr.g-satir').nth(2).click();

  // --- Yazdır: toplu_kunye akışı, satırı açıp kapatmaz ---
  D.istekler.length = 0;
  await satir0.locator('.g-yazdir-btn-ust').click();
  await sayfa.waitForTimeout(250);
  const tk = D.istekler.find(i => i.action === 'toplu_kunye');
  ok('Yazdır → Çoklu Künye ekranı + toplu_kunye isteği (plaka, tarih, aktif firma)',
     !!tk && tk.govde.plaka === '31AJE915' && tk.govde.tarih === '2026-10-05' && String(tk.govde.firmaId) === '7' && await gor(sayfa, '#ekranToplu'), JSON.stringify(tk && tk.govde));
  await sayfa.click('#btnTopluGeri');
  await gonderilenleriAc(sayfa);
  ok('Yazdır satırı AÇMADI (detay yok, aria-expanded=false)',
     await sayfa.locator('tr.g-detay-tr').count() === 0 && (await sayfa.locator('tr.g-satir').first().getAttribute('aria-expanded')) === 'false');

  // --- Tekrar gönder menüsü ---
  const tekrar0 = sayfa.locator('tr.g-satir').first().locator('.g-btn-tekrar');
  await tekrar0.click();
  const menuKutu = async () => sayfa.evaluate(() => {
    const m = document.getElementById('gTekrarMenu'); const r = m.getBoundingClientRect(); const cs = getComputedStyle(m);
    return { gorunur: cs.display !== 'none', konum: cs.position, z: +cs.zIndex, l: r.left, t: r.top, r: r.right, b: r.bottom, vw: innerWidth, vh: innerHeight };
  });
  let mk = await menuKutu();
  ok('menü açıldı: position:fixed, z ≥ 600, ekran içinde',
     mk.gorunur && mk.konum === 'fixed' && mk.z >= 600 && mk.l >= 0 && mk.r <= mk.vw && mk.t >= 0 && mk.b <= mk.vh, JSON.stringify(mk));
  ok('menü satırın aç/kapa durumunu DEĞİŞTİRMEDİ', await sayfa.locator('tr.g-detay-tr').count() === 0 && (await tekrar0.getAttribute('aria-expanded')) === 'true');
  const menuMetin = (await sayfa.locator('#gTekrarMenu').innerText());
  ok('menü: "Aynı firma ile taslağa al" (+ firma adı) ve "Firma değiştir…"', menuMetin.includes('Aynı firma ile taslağa al') && menuMetin.includes('Test Firma A') && menuMetin.includes('Firma değiştir…'), menuMetin);
  await foto(sayfa, '03_menu');
  await sayfa.keyboard.press('Escape');
  ok('Esc menüyü kapatır, odak düğmede', !(await menuKutu()).gorunur && (await tekrar0.getAttribute('aria-expanded')) === 'false' &&
     await sayfa.evaluate(() => document.activeElement.classList.contains('g-btn-tekrar')));
  await tekrar0.click();
  await sayfa.mouse.click(640, 700);
  ok('dışarı tık menüyü kapatır', !(await menuKutu()).gorunur);
  await tekrar0.click();
  await sayfa.evaluate(() => document.dispatchEvent(new Event('scroll', { bubbles: true })));
  ok('kaydırma menüyü kapatır', !(await menuKutu()).gorunur);
  await tekrar0.click(); await tekrar0.click();
  ok('aynı düğmeye ikinci tık menüyü kapatır (aç/kapa)', !(await menuKutu()).gorunur);
  // sağ kenardaki düğme: menü sağdan taşmaz; aşağıdaki satırda alt kenar kontrolü için pencereyi küçült
  await sayfa.setViewportSize({ width: 1280, height: 330 });
  await sayfa.waitForTimeout(150);
  await sayfa.locator('tr.g-satir').nth(4).scrollIntoViewIfNeeded();
  await sayfa.locator('tr.g-satir').nth(4).locator('.g-btn-tekrar').click();
  mk = await menuKutu();
  ok('altta yer yoksa menü YUKARI açılır / ekran içinde kalır', mk.gorunur && mk.t >= 0 && mk.b <= mk.vh && mk.r <= mk.vw, JSON.stringify(mk));
  await sayfa.keyboard.press('Escape');
  await sayfa.setViewportSize({ width: 1280, height: 800 });
  await sayfa.waitForTimeout(150);

  // --- "Aynı firma ile taslağa al" ---
  D.istekler.length = 0;
  await tekrar0.click();
  await sayfa.locator('#gTekrarMenu button[data-sec="ayni"]').click();
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(500);
  const th = D.istekler.find(i => i.action === 'gonderilen_tohum');
  ok('gonderilen_tohum: {id, firmaId=aktif, firmaAd}, hedefFirmaId YOK',
     !!th && th.govde.id === 'g1001' && String(th.govde.firmaId) === '7' && th.govde.firmaAd === 'Test Firma A' && !('hedefFirmaId' in th.govde), JSON.stringify(th && th.govde));
  ok('Bildirim ekranına geçti, plaka dolu', await gor(sayfa, '#ekranBildirim') && (await sayfa.inputValue('#oPlaka')) === '31AJE915');
  ok('plan bloğu açık, kilo/fiyat dolu (24.500 / 25)', await gor(sayfa, '#planBlok') && (await sayfa.inputValue('#oPlanKilo')) === '24.500' && (await sayfa.inputValue('#oPlanFiyat')) === '25');
  ok('notlar formun üstünde GÖRÜNÜR', await gor(sayfa, '#kopyaNotlar') && (await sayfa.textContent('#kopyaNotlar')).includes('plan taslağı olarak açıldı'));
  ok('notlar metin olarak basılır (HTML değil)', (await sayfa.locator('#kopyaNotlar b').count()) === 1 && (await sayfa.textContent('#kopyaNotlar')).includes('<b>x</b>'));
  ok('toast: Önceki gönderim forma yüklendi…', (await sayfa.textContent('#toast')).includes('↻ Önceki gönderim forma yüklendi — plaka/kilo kontrol edip Taslağa Kaydet'), await sayfa.textContent('#toast'));
  ok('notlar kutusu kart başlığının hemen altında (formun tepesi)', await sayfa.evaluate(() => {
    const k = document.getElementById('kopyaNotlar').getBoundingClientRect(); const p = document.getElementById('oPlaka').getBoundingClientRect(); return k.top < p.top && k.top < 400; }));
  await foto(sayfa, '04_form_kopya');

  // Kaydet: eskiTaslakId YOK, taslak_sil YOK
  D.istekler.length = 0;
  await sayfa.click('#btnPlanKaydet');
  await sayfa.waitForTimeout(400);
  const kayit = D.istekler.find(i => i.action === 'taslak_kaydet');
  ok('Taslağa Kaydet: taslak_kaydet gitti, gövdede eskiTaslakId YOK',
     !!kayit && !('eskiTaslakId' in kayit.govde) && kayit.govde.ortak.plaka === '31AJE915' && kayit.govde.ortak.planKg === 24500, JSON.stringify(kayit && Object.keys(kayit.govde)));
  ok('hiçbir taslak_sil isteği yok', !D.istekler.some(i => i.action === 'taslak_sil'));
  ok('kayıttan sonra Taslaklar ekranı, bilgi kutusu temizlendi', await gor(sayfa, '#ekranTaslaklar') && !(await gor(sayfa, '#kopyaNotlar')));

  // --- Regresyon: normal "Düzenle" (kopya DEĞİL) eskisi gibi çalışır ---
  D.taslaklar = [{ id: 't-eski', zaman: '2026-10-06T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [],
    ortak: { sifatId: 1, bildirimTuruId: 1, plaka: '34DUZ01', urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya', fiyat: 20, planKg: 5000,
      planSorgu: { urunId: 1, aySayisi: 12, isletmeTuruId: 0, sirala: 'azalan' } } }];
  await sayfa.click('#btnTaslakGeri'); await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('[data-duzenle]');
  await sayfa.click('[data-duzenle]');
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(400);
  ok('Düzenle: eski toast ("Plan taslağı forma yüklendi"), bilgi kutusu YOK',
     (await sayfa.textContent('#toast')).includes('Plan taslağı forma yüklendi') && !(await gor(sayfa, '#kopyaNotlar')), await sayfa.textContent('#toast'));
  D.istekler.length = 0;
  await sayfa.click('#btnPlanKaydet'); await sayfa.waitForTimeout(400);
  const k2 = D.istekler.find(i => i.action === 'taslak_kaydet'), sil2 = D.istekler.find(i => i.action === 'taslak_sil');
  ok('Düzenle→Kaydet: eskiTaslakId gider + eski taslak silinir (DEĞİŞMEDİ)',
     !!k2 && k2.govde.eskiTaslakId === 't-eski' && !!sil2 && sil2.govde.id === 't-eski', JSON.stringify([k2 && k2.govde.eskiTaslakId, sil2 && sil2.govde]));
  D.taslaklar = [];

  // --- Firma değiştir ---
  await sayfa.click('#btnTaslakGeri'); await gonderilenleriAc(sayfa);
  D.istekler.length = 0;
  await sayfa.locator('tr.g-satir').nth(1).locator('.g-btn-tekrar').click();
  await sayfa.locator('#gTekrarMenu button[data-sec="firma"]').click();
  await sayfa.waitForTimeout(150);
  const pk = await sayfa.evaluate(() => {
    const p = document.getElementById('firmaSecPencere'); const cs = getComputedStyle(p);
    return { gorunur: cs.display !== 'none', z: +cs.zIndex, adlar: [...p.querySelectorAll('.firma-kart .ad')].map(e => e.textContent), siniflar: [...p.querySelectorAll('.firma-kart')].map(e => e.className) };
  });
  ok('firma penceresi açık, z ≥ 600', pk.gorunur && pk.z >= 600, JSON.stringify(pk));
  ok('aktif firma (Test Firma A) listede YOK, diğerleri var', pk.adlar.length === 2 && !pk.adlar.includes('Test Firma A') && pk.adlar.includes('Beta Tarım Ltd.'), pk.adlar.join(' | '));
  ok('firma renkleri uygulanır (r-amber, r-rose)', pk.siniflar.some(c => c.includes('r-amber')) && pk.siniflar.some(c => c.includes('r-rose')));
  ok('firma adı HTML olarak yorumlanmaz', pk.adlar.includes('Gamma <b>Gıda</b>'));
  ok('hiç istek atılmadı (seçim yapılmadı)', !D.istekler.some(i => i.action === 'gonderilen_tohum'));
  await foto(sayfa, '05_firma_sec');
  await sayfa.keyboard.press('Escape');
  ok('Esc penceresini kapatır', !(await gor(sayfa, '#firmaSecPencere')));
  await sayfa.locator('tr.g-satir').nth(1).locator('.g-btn-tekrar').click();
  await sayfa.locator('#gTekrarMenu button[data-sec="firma"]').click();
  await sayfa.mouse.click(5, 5);
  ok('arka plana tık kapatır', !(await gor(sayfa, '#firmaSecPencere')));
  await sayfa.locator('tr.g-satir').nth(1).locator('.g-btn-tekrar').click();
  await sayfa.locator('#gTekrarMenu button[data-sec="firma"]').click();
  await sayfa.click('#btnFirmaSecKapat');
  ok('✕ kapatır', !(await gor(sayfa, '#firmaSecPencere')));
  await sayfa.locator('tr.g-satir').nth(1).locator('.g-btn-tekrar').click();
  await sayfa.locator('#gTekrarMenu button[data-sec="firma"]').click();
  D.istekler.length = 0;
  await sayfa.locator('#firmaSecListe .firma-kart', { hasText: 'Beta Tarım Ltd.' }).click();
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(500);
  const th2 = D.istekler.find(i => i.action === 'gonderilen_tohum');
  ok('Firma değiştir: tohum ÖNCE eski firmayla (firmaId=7) + hedefFirmaId=8',
     !!th2 && th2.govde.id === 'g1002' && String(th2.govde.firmaId) === '7' && th2.govde.firmaAd === 'Test Firma A' && String(th2.govde.hedefFirmaId) === '8', JSON.stringify(th2 && th2.govde));
  const ix = D.istekler.map(i => i.action);
  ok('firma geçişi tohumdan SONRA (sonraki istekler firmaId=8)',
     ix.indexOf('gonderilen_tohum') < ix.indexOf('sonlar') && String((D.istekler.find(i => i.action === 'sonlar') || {govde: {}}).govde.firmaId) === '8', ix.join(','));
  ok('aktif firma Beta Tarım Ltd. oldu (başlık + tema)', (await sayfa.textContent('#kart1Baslik')).includes('Beta Tarım Ltd.') &&
     (await sayfa.evaluate(() => document.body.dataset.tema)) === 'amber' && (await sayfa.textContent('#menuFirmaAd')) === 'Beta Tarım Ltd.');
  ok('belge no (plaka yok) forma geldi, firma notu görünür', (await sayfa.inputValue('#oBelgeNo')) === 'B-77' && (await sayfa.textContent('#kopyaNotlar')).includes('Firma değişti'));
  ok('firma değişince sıfat tohumdan gelmedi (boşalmış → varsayılan kural)', (await sayfa.inputValue('#sSifat')) !== '' );

  // --- Hata: tohum 404 → alert, firma/ekran DEĞİŞMEZ ---
  await sayfa.click('#btnMenuyeDon');                 // Beta menüsü
  await sayfa.click('#btnFirmaDegis');
  await sayfa.locator('.firma-kart', { hasText: 'Test Firma A' }).click();
  await gonderilenleriAc(sayfa);
  D.tohumHata = true; D.dialoglar.length = 0;
  await sayfa.locator('tr.g-satir').first().locator('.g-btn-tekrar').click();
  await sayfa.locator('#gTekrarMenu button[data-sec="firma"]').click();
  await sayfa.locator('#firmaSecListe .firma-kart', { hasText: 'Gamma' }).click();
  await sayfa.waitForTimeout(400);
  ok('tohum hatası: alert gösterildi', D.dialoglar.some(m => m.includes('Gönderim bulunamadı')), D.dialoglar.join('|'));
  ok('hata → firma DEĞİŞMEDİ, Gönderilenler ekranında kalındı',
     (await sayfa.textContent('#menuFirmaAd')) === 'Test Firma A' && await gor(sayfa, '#ekranGonderilen') && !(await gor(sayfa, '#ekranBildirim')));
  D.tohumHata = false;

  // --- Künye satırlı tohum (plan değil): aynı toast, uyarı yoksa kutu gizli, eski taslak kimliği YOK ---
  D.istekler.length = 0;
  await sayfa.evaluate(() => { gonderilenTekrar(gonderilenVeri[3], null); });
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(600);
  ok('künyeli tohum: forma geldi (plaka), künye sorgusu yapıldı',
     (await sayfa.inputValue('#oPlaka')) === '33XYZ99' && D.istekler.some(i => i.action === 'kunyeler'));
  ok('künyeli tohum: kopya toast + duzenlenenTaslakId=null + notlar boşsa kutu gizli',
     (await sayfa.textContent('#toast')).includes('Önceki gönderim forma yüklendi') && (await sayfa.evaluate(() => duzenlenenTaslakId)) === null && !(await gor(sayfa, '#kopyaNotlar')));
  await sayfa.click('#btnMenuyeDon'); await gonderilenleriAc(sayfa);

  // --- Çift tık koruması ---
  D.tohumGecikme = 500; D.istekler.length = 0;
  await sayfa.evaluate(() => { gonderilenTekrar(gonderilenVeri[2], null); gonderilenTekrar(gonderilenVeri[2], null); gonderilenTekrar(gonderilenVeri[2], null); });
  await sayfa.waitForTimeout(100);
  ok('yüklenirken liste "meşgul" (tıklanamaz)', await sayfa.evaluate(() => document.getElementById('gonderilenListe').classList.contains('g-mesgul')));
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(300);
  ok('çift/üçlü çağrı → TEK gonderilen_tohum isteği', D.istekler.filter(i => i.action === 'gonderilen_tohum').length === 1);
  D.tohumGecikme = 0;

  // --- Kip değişimi: <900px kartlar, ≥900px tablo ---
  await sayfa.click('#btnTaslakGeri').catch(() => {});
  await sayfa.click('#btnMenuyeDon').catch(() => {});
  await gonderilenleriAc(sayfa);
  await sayfa.setViewportSize({ width: 700, height: 800 });
  await sayfa.waitForTimeout(250);
  ok('700px: kartlar (tablo yok), veri yeniden istenmeden',
     await sayfa.locator('.g-tablo').count() === 0 && await sayfa.locator('#gonderilenListe .g-kart').count() === 5);
  ok('700px: yatay taşma yok', !(await yatayTasma(sayfa)));
  await sayfa.setViewportSize({ width: 1280, height: 800 });
  await sayfa.waitForTimeout(250);
  ok('1280px\'e dönünce tablo geri geldi', await sayfa.locator('.g-tablo').count() === 1 && await sayfa.locator('#gonderilenListe .g-kart').count() === 0);

  // --- Farklı iframe genişlikleri: sayfa taşması yok ---
  for (const w of [900, 1024, 1100, 1200, 1400]) {
    await sayfa.setViewportSize({ width: w, height: 800 });
    await sayfa.waitForTimeout(250);
    await sayfa.locator('tr.g-satir').first().click();            // açıkken de ölç
    const ol = await sayfa.evaluate(() => { const s = document.querySelector('.g-tablo-sarma'); return { sayfa: document.documentElement.scrollWidth, vw: innerWidth, sarma: s.scrollWidth, sarmaC: s.clientWidth }; });
    ok(w + 'px: sayfada yatay taşma yok', ol.sayfa <= ol.vw + 1, JSON.stringify(ol));
    if (w >= 1200) ok(w + 'px: tablo sarmalayıcıda kaydırma gerekmiyor', ol.sarma <= ol.sarmaC + 1, JSON.stringify(ol));
    const bt = await sayfa.evaluate(() => [...document.querySelectorAll('tr.g-satir')[0].querySelectorAll('button')].map(b => { const r = b.getBoundingClientRect(); return r.width > 0 && r.right <= innerWidth + 1; }));
    ok(w + 'px: eylem düğmeleri görünür/ekran içinde', bt.every(Boolean), JSON.stringify(bt));
    await foto(sayfa, '06_masaustu_' + w);
    await sayfa.locator('tr.g-satir').first().click();
  }
  await sayfa.setViewportSize({ width: 1280, height: 800 });
  await ctx.close();

  // ================= KOYU TEMA =================
  const ctxK = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  await ctxK.addInitScript(() => { try { localStorage.setItem('asya_tema', 'koyu'); } catch (e) {} });
  const DK = durumYarat();
  const sayfaK = await sayfaKur(ctxK, DK, hatalar);
  await sayfaK.click('.firma-kart:not(.firma-ekle)');
  await gonderilenleriAc(sayfaK);
  await sayfaK.locator('tr.g-satir').first().click();
  const renk = await sayfaK.evaluate(() => {
    const c = (el) => getComputedStyle(el);
    const th = document.querySelector('.g-tablo thead th'), tb = document.querySelector('.g-tablo-sarma');
    const satir = document.querySelectorAll('tr.g-satir')[1], det = document.querySelector('.g-detay-tr td');
    return { tema: document.documentElement.dataset.theme, th: c(th).backgroundColor, sarma: c(tb).backgroundColor, satirYazi: c(satir.querySelector('.g-c-firma')).color,
      detay: c(det).backgroundColor };
  });
  ok('koyu tema: başlık koyu (#1a2331), sarmalayıcı koyu, satır yazısı açık',
     renk.tema === 'dark' && renk.th === 'rgb(26, 35, 49)' && renk.sarma === 'rgb(22, 29, 39)' && renk.satirYazi !== 'rgb(0, 0, 0)', JSON.stringify(renk));
  await foto(sayfaK, '07_koyu');
  await sayfaK.locator('tr.g-satir').first().locator('.g-btn-tekrar').click();
  await foto(sayfaK, '08_koyu_menu');
  await ctxK.close();

  // ================= TEK FİRMA: "başka firma yok" =================
  const ctxT = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const DT = durumYarat({ firmalar: [FIRMALAR[0]] });
  const sayfaT = await sayfaKur(ctxT, DT, hatalar);
  await sayfaT.click('.firma-kart:not(.firma-ekle)');
  await gonderilenleriAc(sayfaT);
  await sayfaT.locator('tr.g-satir').first().locator('.g-btn-tekrar').click();
  await sayfaT.locator('#gTekrarMenu button[data-sec="firma"]').click();
  ok('tek firma: "Başka firma tanımlı değil" mesajı, seçenek yok',
     (await sayfaT.textContent('#firmaSecBos')).includes('Başka firma tanımlı değil') && await sayfaT.locator('#firmaSecListe .firma-kart').count() === 0);
  await ctxT.close();

  // ================= MOBİL 390×844 (dokunmatik) =================
  const ctxM = await tarayici.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
  const DM = durumYarat();
  const sayfaM = await sayfaKur(ctxM, DM, hatalar);
  await sayfaM.tap('.firma-kart:not(.firma-ekle)');
  await sayfaM.tap('#btnGonderilenler');
  await sayfaM.waitForSelector('#gonderilenListe .g-kart');
  ok('mobil: kartlar çizildi, TABLO yok', await sayfaM.locator('#gonderilenListe .g-kart').count() === 5 && await sayfaM.locator('.g-tablo').count() === 0);
  ok('mobil: kart başlığında Yazdır var, her kartta 8 bilgi satırı',
     await sayfaM.locator('.g-kart .g-yazdir-btn-ust').count() === 5 && await sayfaM.locator('.g-kart').first().locator('.tk-satir').count() === 8);
  ok('mobil: kart içeriği DEĞİŞMEDİ (kilo 2 ondalık biçimli "24.500,4 KG", fiyat "25 TL")',
     (await sayfaM.locator('.g-kart').first().innerText()).includes('24.500,4 KG') && (await sayfaM.locator('.g-kart').first().innerText()).includes('25 TL'));
  ok('mobil: başta künye detayı KAPALI', !(await sayfaM.locator('.g-kart').first().evaluate(e => e.classList.contains('acik'))));
  const mtekrar = sayfaM.locator('.g-kart').first().locator('.g-btn-tekrar');
  ok('mobil: "↻ Tekrar gönder" kart KAPALIYKEN de görünür (her kartta bir tane)',
     await mtekrar.isVisible() && (await mtekrar.innerText()).includes('Tekrar gönder') && await sayfaM.locator('.g-kart .g-btn-tekrar').count() === 5);
  const mkonum = await sayfaM.evaluate(() => {
    const k = document.querySelector('.g-kart'); const b = k.querySelector('.g-btn-tekrar').getBoundingClientRect();
    const son = k.querySelectorAll('.tk-satir'); const sonS = son[son.length - 1].getBoundingClientRect();
    const detay = k.querySelector('.g-kart-detay'); const kr = k.getBoundingClientRect();
    return { altinda: b.top >= sonS.bottom - 1, detayDisinda: !detay.contains(k.querySelector('.g-btn-tekrar')),
             tamGenislik: b.width >= kr.width - 40, ekranda: b.left >= 0 && b.right <= innerWidth, yukseklik: b.height };
  });
  ok('mobil: düğme bilgi satırlarının ALTINDA, künye detayının DIŞINDA, tam genişlik, parmakla basılır boy (≥40px)',
     mkonum.altinda && mkonum.detayDisinda && mkonum.tamGenislik && mkonum.ekranda && mkonum.yukseklik >= 40, JSON.stringify(mkonum));
  await foto(sayfaM, '09a_mobil_kapali');
  await mtekrar.tap();
  ok('mobil: düğmeye dokunmak kartı AÇMAZ (yalnız menüyü açar)', !(await sayfaM.locator('.g-kart').first().evaluate(e => e.classList.contains('acik'))) && await sayfaM.isVisible('#gTekrarMenu'));
  await foto(sayfaM, '09b_mobil_kapali_menu');
  await sayfaM.keyboard.press('Escape');
  ok('mobil: Esc menüyü kapatır', !(await sayfaM.isVisible('#gTekrarMenu')));
  ok('mobil: yatay taşma yok', !(await yatayTasma(sayfaM)));
  await sayfaM.locator('.g-kart').first().locator('.g-kart-head-sol').tap();
  ok('mobil: kart başlığına dokununca detay açılır (künye çipleri)', await sayfaM.locator('.g-kart').first().evaluate(e => e.classList.contains('acik')) && await sayfaM.locator('.g-kart').first().locator('.g-kunye-cip').first().isVisible());
  ok('mobil: detay açıkken de düğme aynı yerde görünür', await mtekrar.isVisible());
  await foto(sayfaM, '09_mobil_acik');
  await mtekrar.tap();
  const mm = await sayfaM.evaluate(() => { const m = document.getElementById('gTekrarMenu'); const r = m.getBoundingClientRect(); const cs = getComputedStyle(m);
    return { gorunur: cs.display !== 'none', z: +cs.zIndex, l: r.left, r: r.right, t: r.top, b: r.bottom, vw: innerWidth, vh: innerHeight }; });
  ok('mobil: dokunmayla menü açılır, ekran içinde, z ≥ 600', mm.gorunur && mm.z >= 600 && mm.l >= 0 && mm.r <= mm.vw && mm.t >= 0 && mm.b <= mm.vh, JSON.stringify(mm));
  await foto(sayfaM, '10_mobil_menu');
  await sayfaM.tap('#ekranGonderilen .ustbar .baslik');            // dışarı dokun
  ok('mobil: dışarı dokunmak menüyü kapatır', !(await sayfaM.isVisible('#gTekrarMenu')));
  await mtekrar.tap();
  DM.istekler.length = 0;
  await sayfaM.locator('#gTekrarMenu button[data-sec="firma"]').tap();
  const mp = await sayfaM.evaluate(() => { const d = document.querySelector('#firmaSecPencere .kisi-dlg').getBoundingClientRect(); return { l: d.left, r: d.right, b: d.bottom, vw: innerWidth, vh: innerHeight }; });
  ok('mobil: firma penceresi ekranı aşmaz', mp.l >= 0 && mp.r <= mp.vw + 1 && mp.b <= mp.vh + 1, JSON.stringify(mp));
  await foto(sayfaM, '11_mobil_firma_sec');
  await sayfaM.locator('#firmaSecListe .firma-kart', { hasText: 'Beta' }).tap();
  await sayfaM.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfaM.waitForTimeout(400);
  const tm = DM.istekler.find(i => i.action === 'gonderilen_tohum');
  ok('mobil: dokunmayla Firma değiştir → hedefFirmaId=8, forma geçti', !!tm && String(tm.govde.hedefFirmaId) === '8' && (await sayfaM.inputValue('#oPlaka')) === '31AJE915');
  ok('mobil: notlar kutusu görünür, yatay taşma yok', await sayfaM.isVisible('#kopyaNotlar') && !(await yatayTasma(sayfaM)));
  await foto(sayfaM, '12_mobil_form');
  await ctxM.close();

  // ================= GÖNDERİLENLER ARAMA SÜZGECİ (v321) — masaüstü 1280 + mobil 390 =================
  for (const mobil of [false, true]) {
    const et = mobil ? 'mobil' : 'masaüstü';
    const c = await tarayici.newContext(mobil ? { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } : { viewport: { width: 1280, height: 800 } });
    const DF = durumYarat();
    const p = await sayfaKur(c, DF, hatalar);
    const tik = (sel) => mobil ? p.tap(sel) : p.click(sel);
    await tik('.firma-kart:not(.firma-ekle)');
    await tik('#btnGonderilenler');
    await p.waitForSelector(mobil ? '#gonderilenListe .g-kart' : '#gonderilenListe .g-tablo');
    const birim = mobil ? '#gonderilenListe .g-kart' : '#gonderilenListe tr.g-satir';
    const say = () => p.locator(birim).count();
    const sayac = () => p.textContent('#gonderilenAraSay');
    const ara = async (m) => { await p.fill('#gonderilenAra', m); await p.waitForTimeout(350); };
    const idler = () => p.$$eval(birim, a => a.map(e => e.dataset.i));
    const plakalar = () => p.$$eval(birim, (a, mob) => a.map(e => e.querySelector(mob ? '.tk-plaka .d' : '.g-plaka').textContent), mobil);

    ok(et + ' filtre: arama kutusu liste DIŞINDA, 16px, görünür', await p.evaluate(() => {
      const k = document.getElementById('gonderilenAra'); return !document.getElementById('gonderilenListe').contains(k) && getComputedStyle(k).fontSize === '16px' && k.offsetParent !== null; }));
    ok(et + ' filtre: başlangıçta 5 / 5 gönderim', (await say()) === 5 && (await sayac()).trim() === '5 / 5 gönderim', await sayac());
    ok(et + ' filtre: ✕ düğmesi boşken gizli', !(await p.isVisible('#btnGonderilenAraTemizle')));

    await ara('31aje'); ok(et + ' filtre: plaka "31aje" → 1', (await say()) === 1 && (await sayac()).trim() === '1 / 5 gönderim', await sayac());
    ok(et + ' filtre: ✕ görünür', await p.isVisible('#btnGonderilenAraTemizle'));
    await ara('06 abc 123'); ok(et + ' filtre: boşluklu plaka "06 abc 123" → g1003', JSON.stringify(await idler()) === '["2"]', JSON.stringify(await idler()));
    await ara('06abc-123'); ok(et + ' filtre: tireli plaka "06abc-123" → g1003', JSON.stringify(await idler()) === '["2"]', JSON.stringify(await idler()));
    await ara('KİRAZ'); ok(et + ' filtre: ürün "KİRAZ" → g1002', JSON.stringify(await idler()) === '["1"]', JSON.stringify(await idler()));
    await ara('silifke'); ok(et + ' filtre: gönderilen taraf "silifke" → g1004', JSON.stringify(await idler()) === '["3"]', JSON.stringify(await idler()));
    await ara('komisyoncu'); ok(et + ' filtre: gönderilen "komisyoncu" → g1003', JSON.stringify(await idler()) === '["2"]', JSON.stringify(await idler()));
    await ara('rusya'); ok(et + ' filtre: ülke "rusya" → g1001', JSON.stringify(await idler()) === '["0"]', JSON.stringify(await idler()));
    await ara('test firma'); ok(et + ' filtre: firma "test firma" → 4', (await say()) === 4 && (await sayac()).trim() === '4 / 5 gönderim', await sayac());
    await ara('şubesi'); ok(et + ' filtre: uzun firma "şubesi" → g1005', JSON.stringify(await idler()) === '["4"]', JSON.stringify(await idler()));
    await ara('b-77'); ok(et + ' filtre: belge no "b-77" → g1002', JSON.stringify(await idler()) === '["1"]', JSON.stringify(await idler()));
    await ara('kayısı 31aje'); ok(et + ' filtre: çoklu sözcük (AND) "kayısı 31aje" → 1', JSON.stringify(await idler()) === '["0"]', JSON.stringify(await idler()));
    await ara('kayısı test firma'); ok(et + ' filtre: "kayısı test firma" → g1001,g1003,g1004', JSON.stringify(await idler()) === '["0","2","3"]', JSON.stringify(await idler()));
    await ara('SATIN ALIM'); ok(et + ' filtre: Türkçe I/ı "SATIN ALIM" → g1002+g1005', JSON.stringify(await idler()) === '["1","4"]', JSON.stringify(await idler()));
    await ara('satin alim'); ok(et + ' filtre: ASCII "satin alim" da eşleşir', JSON.stringify(await idler()) === '["1","4"]', JSON.stringify(await idler()));
    await ara('HAL KOMİSYONCU'); ok(et + ' filtre: İ "HAL KOMİSYONCU" → g1003', JSON.stringify(await idler()) === '["2"]', JSON.stringify(await idler()));

    await ara('kayısı kiraz');
    ok(et + ' filtre: sonuç yok → mesaj + 0 / 5', (await p.textContent('#gonderilenListe')).includes('Aramaya uyan gönderim yok.') && (await sayac()).trim() === '0 / 5 gönderim' && (await say()) === 0, await sayac());
    ok(et + ' filtre: boş-sonuç mesajı "Henüz gönderim yapılmadı" DEĞİL', !(await p.textContent('#gonderilenListe')).includes('Henüz gönderim yapılmadı'));
    ok(et + ' filtre: boş sonuçta yatay taşma yok', !(await yatayTasma(p)));

    await ara('<img src=x onerror="window.__xss=1">');
    ok(et + ' filtre: HTML girdisi DOM\'a basılmaz', (await p.locator('#gonderilenListe img, #gonderilenAraSay img').count()) === 0 && !(await p.evaluate(() => window.__xss)));

    await p.click('#btnGonderilenAraTemizle');
    await p.waitForTimeout(100);
    ok(et + ' filtre: ✕ temizler → 5 / 5, kutu boş, ✕ gizli', (await say()) === 5 && (await p.inputValue('#gonderilenAra')) === '' && !(await p.isVisible('#btnGonderilenAraTemizle')) && (await sayac()).trim() === '5 / 5 gönderim');

    // --- Özgün indeks: süzülmüş satırda Yazdır DOĞRU kaydı açar ---
    await ara('33xyz');
    ok(et + ' filtre: süzülmüş satırın data-i ÖZGÜN indeks (3), sıra 0 değil', JSON.stringify(await idler()) === '["3"]' && JSON.stringify(await plakalar()) === '["33XYZ99"]', JSON.stringify(await idler()));
    ok(et + ' filtre: butonlar da özgün indeksi taşır', await p.$$eval('#gonderilenListe .g-yazdir-btn-ust, #gonderilenListe .g-btn-tekrar', a => a.length > 0 && a.every(e => e.dataset.i === '3')));
    DF.istekler.length = 0;
    await tik(birim + ' .g-yazdir-btn-ust');
    await p.waitForTimeout(300);
    const tkF = DF.istekler.find(i => i.action === 'toplu_kunye');
    ok(et + ' filtre: filtreliyken Yazdır → g1004 (plaka 33XYZ99, 2026-10-02)', !!tkF && tkF.govde.plaka === '33XYZ99' && tkF.govde.tarih === '2026-10-02', JSON.stringify(tkF && tkF.govde));
    await tik('#btnTopluGeri');
    await tik('#btnGonderilenler');
    await p.waitForSelector(birim);
    await p.waitForTimeout(150);
    ok(et + ' filtre: ekran yeniden açılınca süzgeç SIFIRLANIR (5 / 5, kutu boş)', (await say()) === 5 && (await p.inputValue('#gonderilenAra')) === '' && (await sayac()).trim() === '5 / 5 gönderim');

    // --- kip değişimi süzgeci korur ---
    await ara('kayısı 06abc');
    const kipSonra = mobil ? { width: 1280, height: 800 } : { width: 390, height: 844 };
    await p.setViewportSize(kipSonra);
    await p.waitForTimeout(400);
    const birim2 = mobil ? '#gonderilenListe tr.g-satir' : '#gonderilenListe .g-kart';
    ok(et + ' filtre: kip değişince süzgeç korunur (1 sonuç, g1003)', (await p.locator(birim2).count()) === 1 && (await p.$eval(birim2, e => e.dataset.i)) === '2' && (await p.inputValue('#gonderilenAra')) === 'kayısı 06abc' && (await sayac()).trim() === '1 / 5 gönderim');
    await p.setViewportSize(mobil ? { width: 390, height: 844 } : { width: 1280, height: 800 });
    await p.waitForTimeout(400);

    // --- Özgün indeks: süzülmüş satırda Tekrar gönder DOĞRU kaydı açar ---
    await ara('kiraz');
    DF.istekler.length = 0;
    await tik(birim + ' .g-btn-tekrar');
    await tik('#gTekrarMenu button[data-sec="ayni"]');
    await p.waitForSelector('#ekranBildirim:not(.gizli)');
    await p.waitForTimeout(400);
    const thF = DF.istekler.find(i => i.action === 'gonderilen_tohum');
    ok(et + ' filtre: filtreliyken Tekrar gönder → g1002 (özgün indeks 1)', !!thF && thF.govde.id === 'g1002', JSON.stringify(thF && thF.govde));
    ok(et + ' filtre: forma g1002 verisi (belge B-77) geldi', (await p.inputValue('#oBelgeNo')) === 'B-77' && (await p.inputValue('#oPlaka')) === '');
    await c.close();
  }

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? '\n' + fail + ' TEST BASARISIZ' : '\nTUMU GECTI');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
