// =========================================================
// scripts/hks_depo_tasnif_kaynak_smoke.js — Depo/Tasnif kaynak kuralı (v366)
// HKS HataKodu 108: Depo/Tasnif sıfatından Sevk Etme yalnız referans künyeyi
// size bildiren kişiye ve onun sıfatına yapılabilir. Arayüz:
//   • künye seçilince karşı taraf TC'si kaynaktan dolar (+ canlı doğrulama),
//   • sıfat bildirimciSifatId'den ya da (kayıtlı sıfatlar − 108 red listesi) tek
//     adayından seçilir, not #ikKaynakNot'a yazılır,
//   • farklı kaynaklı künyeler → uyarı; TC farklı → uyarı (değiştirmez),
//   • gönderim sonucunda 108 → #kaynakHataBilgi (sıfat uyumsuz metni YOK).
//   node scripts/hks_depo_tasnif_kaynak_smoke.js   → çıkış 0 = geçti
// Playwright yoksa ATLAR. Künye/TC numaraları sentetiktir.
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
  sifatlar: [{ id: 2, ad: 'İhracat' }, { id: 9, ad: 'Depo/Tasnif' }, { id: 11, ad: 'Tüccar (Hal Dışı)' }, { id: 3, ad: 'Üretici' }],
  isletmeTurleri: [{ id: 1, ad: 'Hal Dışı İşyeri' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }, { id: 196, ad: 'Sevk Etme' }],
};
const KAYNAK = '0091880872';
const DIGER = '1234567890';
const K = (no, extra) => Object.assign({ kunyeNo: no, tarih: '2026-09-01', urun: 'Kayısı', kalan: 100, birim: 'KG' }, extra || {});

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
  sayfa.on('dialog', d => d.accept());
  let kunyeVeri = [], detayVeri = {}, gonderYanit = {}, kisiSorgu = [];
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    const j = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: [{ id: 7, ad: 'Test Firma', vergiNo: '1111111111', renk: 'teal' }] });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'urun_listeleri': return j({ nitelikler: [], uretimSekli: [], birimler: [] });
      case 'iller': return j({ iller: [] });
      case 'taslaklar': return j({ taslaklar: [] });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'kunyeler': return j({ kunyeler: JSON.parse(JSON.stringify(kunyeVeri)) });
      case 'kunye_detay': return j({ detaylar: detayVeri });
      case 'kayitli_kisi': {
        const b = JSON.parse(r.postData() || '{}');
        kisiSorgu.push(b.tcVkn);
        return j({ kisi: { tcVkn: b.tcVkn, durum: 'REGISTERED', kayitliMi: true, sifatlar: [2, 11] } });
      }
      case 'csrf': return j({ csrf: 'taze456' });
      case 'kisiler': return j({ kisiler: [] });
      case 'taslak_gonder': return j(gonderYanit);
      default: return j({});
    }
  });

  async function ac(bizimSifat) {
    kisiSorgu = [];
    await sayfa.goto('http://127.0.0.1:' + port + '/app.html');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    await sayfa.click('#btnEBildirim');
    await sayfa.waitForSelector('#sTur option:nth-child(2)', { state: 'attached' });
    await sayfa.evaluate((sf) => {
      document.getElementById('sUrun').value = '1';
      selectDegerAta('sSifat', sf, true);
      selectDegerAta('sTur', '196', true);
    }, String(bizimSifat));
  }
  async function getirSec(idx) {
    await sayfa.click('#btnKunyeler');
    await sayfa.waitForSelector('#kunyeListe .kunye');
    await sayfa.evaluate((ix) => { ix.forEach(i => document.querySelectorAll('#kunyeListe .kunye input[type=checkbox]')[i].click()); }, idx);
  }
  const durum = () => sayfa.evaluate(() => ({
    tc: document.getElementById('oIkTc').value,
    sifat: document.getElementById('sIkSifat').value,
    not: document.getElementById('ikKaynakNot').textContent,
    notSinif: document.getElementById('ikKaynakNot').className,
  }));
  async function bekleSifat(v) {
    await sayfa.waitForFunction((x) => document.getElementById('sIkSifat').value === x, v, { timeout: 4000 }).catch(() => {});
  }

  // (A) künye detayında bildirimciTc + bildirimciSifatId → TC + sıfat otomatik
  kunyeVeri = [K('1204159260026133175')];
  detayVeri = { '1204159260026133175': { kunyeNo: '1204159260026133175', bildirimciTc: KAYNAK, bildirimciSifatId: 11 } };
  await ac(9); await getirSec([0]);
  await bekleSifat('11');
  let d = await durum();
  ok('(A) karşı taraf TC kaynaktan dolduruldu', d.tc === KAYNAK, JSON.stringify(d));
  ok('(A) canlı KayitliKisiSorgu çalıştı', kisiSorgu.includes(KAYNAK), kisiSorgu.join());
  ok('(A) sıfat bildirimciSifatId → Tüccar (Hal Dışı)', d.sifat === '11', JSON.stringify(d));
  ok('(A) not: "künye kaynağına göre ayarlandı: Tüccar (Hal Dışı)"', /kaynağına göre ayarlandı: Tüccar \(Hal Dışı\)/.test(d.not) && /tamam/.test(d.notSinif), d.not);

  // (B) detay sıfatı yok; sunucu 108'den İhracat'ı (2) reddedilmiş öğrenmiş → tek aday 11
  kunyeVeri = [K('1204159260026133175', { kaynakTc: KAYNAK, kaynakRedSifatlar: [2], kaynakRed: { [KAYNAK]: [2] } })];
  detayVeri = {};
  await ac(9); await getirSec([0]);
  await bekleSifat('11');
  d = await durum();
  ok('(B) TC öğrenilen kaynakTc ile dolduruldu', d.tc === KAYNAK, JSON.stringify(d));
  ok('(B) red listesi düşülünce tek aday → 11', d.sifat === '11', JSON.stringify(d));
  // Elle reddedilmiş sıfat seçilirse uyarı; tekrar seçimde (künye değişimi) otomatik düzelir
  await sayfa.evaluate(() => { selectDegerAta('sIkSifat', '2', true); kaynakUygula(); });
  d = await durum();
  ok('(B) elle seçilen reddedilmiş sıfat yine kaynağa çekilir', d.sifat === '11', JSON.stringify(d));

  // (C) farklı kaynaklı iki künye → uyarı, TC doldurulmaz
  kunyeVeri = [K('2600000000000000001'), K('2600000000000000002')];
  detayVeri = {
    '2600000000000000001': { kunyeNo: '2600000000000000001', bildirimciTc: KAYNAK },
    '2600000000000000002': { kunyeNo: '2600000000000000002', bildirimciTc: DIGER },
  };
  await ac(9); await getirSec([0, 1]);
  d = await durum();
  ok('(C) farklı kaynak uyarısı', /Farklı kaynaklardan gelen künyeler tek bildirimde sevk edilemez/.test(d.not) && /hata/.test(d.notSinif), d.not);
  // (ilk tıklama tek künyeyken TC'yi doldurabilir; ikinci künye eklenince uyarı verilir)
  ok('(C) TC ikinci kaynağa göre değiştirilmedi', d.tc === '' || d.tc === KAYNAK, d.tc);

  // (D) kullanıcının girdiği TC kaynakla farklı → uyarı, otomatik değiştirilmez
  kunyeVeri = [K('2600000000000000001')];
  detayVeri = { '2600000000000000001': { kunyeNo: '2600000000000000001', bildirimciTc: KAYNAK } };
  await ac(9);
  await sayfa.evaluate((tc) => { document.getElementById('oIkTc').value = tc; }, DIGER);
  await getirSec([0]);
  d = await durum();
  ok('(D) farklı TC → uyarı, TC değişmedi', d.tc === DIGER && /HataKodu 108/.test(d.not), JSON.stringify(d));

  // (E) bildirimci sıfatı Depo/Tasnif değil → hiçbir şey yapılmaz
  await ac(2); await getirSec([0]);
  d = await durum();
  ok('(E) İhracat sıfatında otomatik doldurma yok', d.tc === '' && d.not === '', JSON.stringify(d));

  // (G) kaynak TC filtresi: karşı taraf AGRO; başka kişiden gelen künye rozetli ve
  //     otomatik dağıtıma girmez; kaynağı bilinmeyen künye girer; öğrenilen kaynak (kaynakKoken) da sayılır.
  kunyeVeri = [
    K('2600000000000000011', { kalan: 300 }),
    K('2600000000000000012', { kalan: 300, tarih: '2026-01-01' }),
    K('2600000000000000013', { kalan: 300 }),
    K('2600000000000000014', { kalan: 300, tarih: '2025-01-01', kaynakKoken: DIGER, kaynakTc: DIGER }),
  ];
  detayVeri = {
    '2600000000000000011': { kunyeNo: '2600000000000000011', bildirimciTc: KAYNAK },
    '2600000000000000012': { kunyeNo: '2600000000000000012', bildirimciTc: DIGER },
  };
  await ac(9);
  await sayfa.evaluate((tc) => { document.getElementById('oIkTc').value = tc; }, KAYNAK);
  await sayfa.click('#btnKunyeler');
  await sayfa.waitForSelector('#kunyeListe .kunye');
  const rz = await sayfa.evaluate(() => [...document.querySelectorAll('#kunyeListe .kunye')].map(d => (d.querySelector('.kaynak-baska') || {}).textContent || ''));
  ok('(G) rozet yalnız başka kaynaklı künyelerde (12, 14)', !rz[0] && /başka kişi \(TC \*\*\*7890\)/.test(rz[1]) && !rz[2] && /\*\*\*7890/.test(rz[3]), JSON.stringify(rz));
  await sayfa.fill('#hedefKilo', '600');
  await sayfa.click('#btnDagit');
  const g = await sayfa.evaluate(() => ({
    sec: [...document.querySelectorAll('#kunyeListe .kunye')].filter(d => d.querySelector('input[type=checkbox]').checked).map(d => d.querySelector('.no').textContent),
    mesaj: document.getElementById('dagitMesaj').textContent,
  }));
  ok('(G) otomatik dağıtım başka kaynaklı künyeleri atladı', g.sec.join() === '2600000000000000011,2600000000000000013', JSON.stringify(g));
  ok('(G) dağıtım mesajında atlanan kaynak bilgisi', /2 künye başka kişiden geldiği için atlandı/.test(g.mesaj), g.mesaj);
  // Depo/Tasnif değilken rozet yok, dağıtım normal
  await sayfa.evaluate(() => { selectDegerAta('sSifat', '2', true); });
  const rz2 = await sayfa.evaluate(() => document.querySelectorAll('#kunyeListe .kaynak-baska').length);
  ok('(G) bildirimci sıfatı değişince rozetler kalkar', rz2 === 0, String(rz2));

  // (H) canlı vaka (15.10.2026): künyenin bildirimcisi KENDİ firmamız (alım, fiyatlı) → rozet, dağıtım dışı,
  //     seçilirse uyarı ve TC KENDİ VKN'mizle doldurulmaz; AGRO'nun sevk künyesi seçilir.
  kunyeVeri = [
    K('1204159260026133175', { kalan: 20000, tarih: '2026-09-30' }),
    K('1204159260026750522', { kalan: 23500, tarih: '2026-10-06' }),
  ];
  detayVeri = {
    '1204159260026133175': { kunyeNo: '1204159260026133175', bildirimciTc: '1111111111', fiyat: 25 },
    '1204159260026750522': { kunyeNo: '1204159260026750522', bildirimciTc: KAYNAK },
  };
  await ac(9);
  await sayfa.click('#btnKunyeler');
  await sayfa.waitForSelector('#kunyeListe .kunye');
  const rzH = await sayfa.evaluate(() => [...document.querySelectorAll('#kunyeListe .kunye')].map(d => (d.querySelector('.kaynak-baska') || {}).textContent || ''));
  ok('(H) kendi bildirimimiz rozetli (TC girilmeden de)', /kendi bildiriminiz/.test(rzH[0]) && !rzH[1], JSON.stringify(rzH));
  await sayfa.fill('#hedefKilo', '15000');
  await sayfa.click('#btnDagit');
  const gH = await sayfa.evaluate(() => [...document.querySelectorAll('#kunyeListe .kunye')].filter(d => d.querySelector('input[type=checkbox]').checked).map(d => d.querySelector('.no').textContent));
  ok('(H) otomatik dağıtım AGRO künyesini seçti, kendi alımımızı atladı', gH.join() === '1204159260026750522', JSON.stringify(gH));
  await ac(9);
  await getirSec([0]);
  await sayfa.waitForTimeout(300);
  d = await durum();
  ok('(H) kendi künyemiz seçilince uyarı, TC kendi VKN ile doldurulmadı', /KENDİ firmanız/.test(d.not) && /hata/.test(d.notSinif) && d.tc === '', JSON.stringify(d));

  // (F) gönderim sonucu: 108 → #kaynakHataBilgi, "sıfatla uyumsuz" yazısı YOK
  gonderYanit = {
    sonuclar: [{ miktar: 100, hataKodu: 108, mesaj: 'Depo/Tasnif sıfatından sevk ederken, ancak referans künye bildirimini yapan kişiye ve sıfatına geri bildirim yapabilirsiniz.. Referans künye no : "1204159260026133175"' }],
    sifatUyumsuzKunyeler: [],
    kaynakHatalari: [{ kunyeNo: '1204159260026133175', tc: KAYNAK, sifatId: 2, kaynakTc: '***4321', kaynakFarkli: true }],
    taslakKorundu: true,
  };
  await sayfa.evaluate(() => { gonderilecekTaslakId = 'T1'; document.getElementById('btnOnay').click(); });
  await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  const t = await sayfa.evaluate(() => ({
    var: !!document.getElementById('kaynakHataBilgi'),
    uyumsuz: !!document.getElementById('sifatUyumsuzBilgi'),
    metin: document.getElementById('modalIcerik').innerText,
  }));
  ok('(F) #kaynakHataBilgi gösterildi', t.var && /size bildiren kişiye/.test(t.metin) && /İhracat/.test(t.metin), t.metin);
  ok('(F) kaynak farklı: "size TC ***4321 tarafından gönderilmiş … o kişiye"', /size TC \*\*\*4321 tarafından gönderilmiş/.test(t.metin) && /o kişiye/.test(t.metin), t.metin);
  ok('(F) "sıfatla uyumsuz" bilgisi YOK', !t.uyumsuz && !/sıfatla uyumsuz bulundu/.test(t.metin), t.metin);

  // Mobil taşma
  await sayfa.setViewportSize({ width: 390, height: 800 });
  const tasma = await sayfa.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok('mobil 390: yatay taşma yok', !tasma);

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
