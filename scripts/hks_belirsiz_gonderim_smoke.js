// =========================================================
// scripts/hks_belirsiz_gonderim_smoke.js — Hal Kayıt "belirsiz gönderim" arayüzü (v417)
//   node scripts/hks_belirsiz_gonderim_smoke.js   → çıkış 0 = geçti
// Sunucu 409 {belirsiz:true} döndürünce alert DEĞİL kalıcı kutu; künye bulunduysa iki onay,
// kontrol edilemediyse tek onay; onay gövdesi belirsizOnay(+belirsizKunyeOnay); kartta rozet
// ve "HKS'te kontrol ettim" düğmesi; proxy HTML 502 anlaşılır mesaj; 390/1280 taşma yok;
// koyu tema. api.php sahte JSON'dur (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
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

const LISTELER = {
  urunler: [{ id: 1, ad: 'Nar' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }], bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }];
const BELIRSIZ = { isaretId: 'bgABC123', gun: '2026-10-22', zaman: '2026-10-22 10:00:00', neden: 'istisna' };
const TASLAK = (id, plaka, belirsiz) => ({ id, zaman: '2026-10-22T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A',
  satirlar: [{ kunyeNo: '3601000000000001', miktar: 22000 }], ortak: { plaka, belgeNo: '', urunAd: 'Nar', ulkeAd: 'Rusya', fiyat: 25 },
  ayniPlakaBugun: 0, belirsizGonderim: belirsiz || null });
const XSS = '<img src=x onerror="window.__xss=1">';
const BULUNAN = [
  { kunyeNo: '3601000000000777', urun: 'Nar ' + XSS, miktar: 21500, birim: 'Kg', tarih: '2026-10-22', bildirimci: 'Örnek A.Ş. ' + XSS },
  { kunyeNo: '3601000000000778', urun: 'Nar', miktar: 500, birim: 'Kg', tarih: '2026-10-22', bildirimci: 'Örnek A.Ş.' },
];

const D = { istekler: [], taslaklar: [], gonder: null };
async function apiYanit(action, g) {
  D.istekler.push({ action, govde: g });
  switch (action) {
    case 'firmalar': return [200, { firmalar: FIRMALAR }];
    case 'sonlar': return [200, { ulkeler: [], urunler: [] }];
    case 'listeler': return [200, LISTELER];
    case 'taslaklar': return [200, { taslaklar: D.taslaklar }];
    case 'ayni_plaka_bugun': return [200, { gonderimler: [] }];
    case 'gonderilenler': return [200, { gonderilenler: [] }];
    case 'csrf': return [200, { csrf: 'taze456' }];
    case 'belirsiz_isaret_kapat': return [200, { tamam: true }];
    case 'taslak_gonder': return D.gonder(g);
    default: return [200, {}];
  }
}
const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/api.php')) {
    let govde = '';
    q.on('data', c => { govde += c; });
    q.on('end', async () => {
      let g = {}; try { g = JSON.parse(govde || '{}'); } catch (e) {}
      const [st, o, tur] = await apiYanit(new URL(q.url, 'http://x').searchParams.get('action'), g);
      s.statusCode = st;
      if (tur === 'html') { s.setHeader('Content-Type', 'text/html'); s.end(o); }
      else { s.setHeader('Content-Type', 'application/json'); s.end(JSON.stringify(o)); }
    });
    return;
  }
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const dialoglar = [];

  async function sayfaKur(viewport) {
    const ctx = await tarayici.newContext({ viewport });
    const sayfa = await ctx.newPage();
    sayfa.on('pageerror', e => hatalar.push(e.message));
    sayfa.on('dialog', d => { dialoglar.push(d.type() + ':' + d.message()); d.accept(); });
    await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
    await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
    await sayfa.click('.firma-kart:not(.firma-ekle)');
    return { ctx, sayfa };
  }
  const taslaklariAc = async (sayfa) => {
    await sayfa.click('#btnTaslaklar');
    await sayfa.waitForSelector('.taslak-kart');
  };
  const onayAc = async (sayfa, i) => {
    await sayfa.locator('.taslak-kart').nth(i).locator('[data-gonder]').click();
    await sayfa.waitForSelector('#modalOnayKutu:not(.gizli)');
    await sayfa.waitForFunction(() => !document.getElementById('btnOnay').disabled);
  };
  const sonGonder = () => D.istekler.filter(i => i.action === 'taslak_gonder').pop();

  // ================= masaüstü 1280 =================
  const { ctx, sayfa } = await sayfaKur({ width: 1280, height: 800 });
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ), TASLAK('t2', '07ABC12', null)];
  await taslaklariAc(sayfa);
  const kart = (i) => sayfa.locator('.taslak-kart').nth(i);

  // (A) kart rozeti
  ok('(A) işaretli taslakta plaka satırında rozet + "HKS\'te kontrol ettim" düğmesi',
     await kart(0).locator('.tk-plaka .tk-belirsiz').count() === 1 && await kart(0).locator('.tk-belirsiz-kapat').count() === 1);
  ok('(A) işaretsiz taslakta rozet YOK', await kart(1).locator('.tk-belirsiz').count() === 0);
  const satirSay = [await kart(0).locator('.tk-satir').count(), await kart(1).locator('.tk-satir').count()];
  ok('(A) kart satır SAYISI aynı (rozet satır eklemez), düğme ızgarası 4 düğme', satirSay[0] === satirSay[1] && await kart(0).locator('.tk-butonlar > button').count() === 4, JSON.stringify(satirSay));

  // (B) onay penceresi
  await onayAc(sayfa, 0);
  ok('(B) işaretli taslak: onayda kırmızı uyarı + düğme "HKS\'te kontrol et ve gönder"',
     await sayfa.locator('#onayBelirsizUyari').count() === 1 && (await sayfa.innerText('#btnOnay')).trim() === "HKS'te kontrol et ve gönder");
  await sayfa.click('#btnVazgec');
  await onayAc(sayfa, 1);
  ok('(B) işaretsiz taslak: uyarı yok, düğme "Evet, Gönder"', await sayfa.locator('#onayBelirsizUyari').count() === 0 && (await sayfa.innerText('#btnOnay')).trim() === 'Evet, Gönder');
  await sayfa.click('#btnVazgec');

  // (C) künye bulundu → 409 kalıcı kutu, iki onay
  D.gonder = (g) => g.belirsizOnay
    ? [200, { sonuclar: [{ yeniKunyeNo: '3601000000000999', miktar: 22000 }], belirsizKontrol: 'kunye_var' }]
    : [409, { hata: 'DURDURULDU: bu plaka için önceki gönderim belirsiz kalmıştı ve HKS\'te Gönderilenler\'de kaydı olmayan 2 künye bulundu. Bildirim gönderilmedi, taslak silinmedi.',
        kod: 'belirsiz_gonderim_kunye_var', belirsiz: true, taslakKorundu: true, isaretId: 'bgABC123', bulunan: BULUNAN }];
  dialoglar.length = 0;
  await onayAc(sayfa, 0);
  await sayfa.click('#btnOnay');
  await sayfa.waitForSelector('#belirsizKutu');
  ok('(C) 409 → alert YOK, kalıcı kutu görünür', dialoglar.length === 0 && await sayfa.isVisible('#belirsizKutu'), dialoglar.join('|'));
  ok('(C) kutuda bulunan künyeler tabloda (2 satır)', await sayfa.locator('#belirsizKutu tbody tr').count() === 2 && /3601000000000777/.test(await sayfa.innerText('#belirsizKutu')));
  ok('(C) HKS verisi HTML olarak yorumlanmaz (XSS yok)', await sayfa.locator('#belirsizKutu img').count() === 0 && !(await sayfa.evaluate(() => window.__xss)));
  ok('(C) düğme PASİF + etiket "HKS\'te kontrol ettim — yine de gönder"', await sayfa.isDisabled('#btnOnay') && (await sayfa.innerText('#btnOnay')).trim() === "HKS'te kontrol ettim — yine de gönder");
  ok('(C) künye bulunduysa İKİ onay kutusu', await sayfa.locator('#bgOnay1').count() === 1 && await sayfa.locator('#bgOnay2').count() === 1);
  await sayfa.check('#bgOnay1');
  ok('(C) yalnız ilk kutu → düğme HÂLÂ pasif', await sayfa.isDisabled('#btnOnay'));
  await sayfa.check('#bgOnay2');
  ok('(C) iki kutu → düğme açılır', !(await sayfa.isDisabled('#btnOnay')));
  await sayfa.uncheck('#bgOnay1');
  ok('(C) kutu geri alınınca düğme tekrar pasif', await sayfa.isDisabled('#btnOnay'));
  await sayfa.check('#bgOnay1');
  D.istekler.length = 0;
  await sayfa.click('#btnOnay');
  await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  const g1 = sonGonder().govde;
  ok('(C) onaylı gönderim: belirsizOnay=işaret id + belirsizKunyeOnay=true', g1.belirsizOnay === 'bgABC123' && g1.belirsizKunyeOnay === true && g1.id === 't1', JSON.stringify(g1));
  ok('(C) sonuç ekranı normal (KAYDEDİLDİ)', /KAYDEDİLDİ/.test(await sayfa.innerText('#modalIcerik')));
  await sayfa.click('#btnModalX');

  // (D) kontrol edilemedi → tek onay
  D.gonder = (g) => g.belirsizOnay
    ? [200, { sonuclar: [{ yeniKunyeNo: '3601000000000998', miktar: 1 }] }]
    : [409, { hata: 'DURDURULDU: HKS kontrol edilemedi (Bağlantı hatası: timed out).', kod: 'belirsiz_gonderim_kontrol_edilemedi',
        belirsiz: true, taslakKorundu: true, isaretId: 'bgABC123', bulunan: [] }];
  await sayfa.waitForSelector('.taslak-kart');
  await onayAc(sayfa, 0);
  await sayfa.click('#btnOnay');
  await sayfa.waitForSelector('#belirsizKutu');
  ok('(D) kontrol edilemedi: TEK onay kutusu, tablo yok', await sayfa.locator('#bgOnay1').count() === 1 && await sayfa.locator('#bgOnay2').count() === 0 && await sayfa.locator('#belirsizKutu tbody tr').count() === 0);
  await sayfa.check('#bgOnay1');
  D.istekler.length = 0;
  await sayfa.click('#btnOnay');
  await sayfa.waitForFunction(() => document.getElementById('modalBaslik').textContent.includes('Gönderim Sonucu'));
  const g2 = sonGonder().govde;
  ok('(D) gövde: belirsizOnay VAR, belirsizKunyeOnay YOK', g2.belirsizOnay === 'bgABC123' && !('belirsizKunyeOnay' in g2), JSON.stringify(g2));
  await sayfa.click('#btnModalX');

  // (E) bağlantı koptu → 409 belirsiz_gonderim: kalıcı kutu, onay düğmesi gizli, liste tazelenir
  D.gonder = () => [409, { hata: 'Bildirim gönderilirken bağlantı kesildi: timed out — HKS\'in bildirimi ALIP ALMADIĞI BİLİNMİYOR.',
    kod: 'belirsiz_gonderim', belirsiz: true, taslakKorundu: true, isaretId: 'bgNEW1' }];
  await sayfa.waitForSelector('.taslak-kart');
  await onayAc(sayfa, 1);
  dialoglar.length = 0; D.istekler.length = 0;
  await sayfa.click('#btnOnay');
  await sayfa.waitForSelector('#belirsizKutu');
  ok('(E) bağlantı koptu: alert YOK, kalıcı kutu, onay düğmesi gizli', dialoglar.length === 0 && !(await sayfa.isVisible('#btnOnay')) && /BİLİNMİYOR/.test(await sayfa.innerText('#belirsizKutu')));
  await sayfa.waitForFunction(() => document.querySelector('.taslak-kart'));
  ok('(E) Taslaklar listesi tazelendi (işaret kartta görünsün)', D.istekler.some(i => i.action === 'taslaklar'));
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ), TASLAK('t2', '07ABC12', { isaretId: 'bgNEW1', gun: '2026-10-22', zaman: '2026-10-22 11:00:00', neden: 'istisna' })];
  await sayfa.click('#btnVazgec');
  await sayfa.waitForSelector('.taslak-kart');
  ok('(E) "Taslağa Dön" kutuyu kapatır', !(await sayfa.locator('#modal').evaluate(el => el.classList.contains('acik'))));
  // sonraki onayda eski durum sızmaz: işaretli taslak yine "kontrol et ve gönder"
  await sayfa.waitForFunction(() => document.querySelectorAll('.taslak-kart .tk-belirsiz').length === 2);
  await onayAc(sayfa, 1);
  ok('(E) tazelenen kartta artık işaret var; onay düğmesi "HKS\'te kontrol et ve gönder"', (await sayfa.innerText('#btnOnay')).trim() === "HKS'te kontrol et ve gönder");
  await sayfa.click('#btnVazgec');

  // (F) proxy HTML 502 → anlaşılır mesaj
  D.gonder = () => [502, '<html><body><h1>502 Bad Gateway</h1></body></html>', 'html'];
  dialoglar.length = 0;
  await onayAc(sayfa, 0);
  await sayfa.click('#btnOnay');
  await sayfa.waitForFunction(() => true);
  await sayfa.waitForTimeout(300);
  const alertMetni = dialoglar.find(d => d.startsWith('alert:')) || '';
  ok('(F) HTML 502: "Unexpected token" sızmaz, anlaşılır mesaj (HTTP 502)', /Sunucu beklenmeyen bir yanıt verdi \(HTTP 502\)/.test(alertMetni) && !/Unexpected|JSON/.test(alertMetni), alertMetni);
  await sayfa.waitForTimeout(100);

  // (G) "HKS'te kontrol ettim" → kapat isteği + liste tazelenir
  D.istekler.length = 0; dialoglar.length = 0;
  D.taslaklar = [TASLAK('t1', '33NCN99', null), TASLAK('t2', '07ABC12', null)];
  await sayfa.click('#btnTaslakGeri').catch(() => {});
  await taslaklariAc(sayfa).catch(() => {});
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ), TASLAK('t2', '07ABC12', null)];
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('.tk-belirsiz-kapat');
  D.istekler.length = 0;
  D.taslaklar = [TASLAK('t1', '33NCN99', null), TASLAK('t2', '07ABC12', null)];
  await sayfa.click('.tk-belirsiz-kapat');
  await sayfa.waitForFunction(() => document.querySelectorAll('.taslak-kart .tk-belirsiz').length === 0);
  const kp = D.istekler.find(i => i.action === 'belirsiz_isaret_kapat');
  ok('(G) kapat: onay sorulur, istek isaretId + firmaId taşır, liste tazelenir',
     dialoglar.some(d => d.startsWith('confirm:') && /kontrol ettiniz mi/.test(d)) && kp && kp.govde.isaretId === 'bgABC123' && String(kp.govde.firmaId) === '7', JSON.stringify(kp));
  ok('(G) kapat TAKİP gönderimi YAPMAZ (taslak_gonder isteği yok)', !D.istekler.some(i => i.action === 'taslak_gonder'));

  // (H) Sil onayı işaretli taslakta ek uyarı
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ), TASLAK('t2', '07ABC12', null)];
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('.tk-belirsiz');
  dialoglar.length = 0;
  await sayfa.locator('.taslak-kart').nth(0).locator('[data-sil]').click();
  await sayfa.waitForTimeout(200);
  const silMetin = dialoglar.find(d => d.startsWith('confirm:')) || '';
  ok('(H) Sil onayı işaretli taslakta belirsiz uyarısı taşır', /BELİRSİZ/.test(silMetin), silMetin);
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ), TASLAK('t2', '07ABC12', null)];
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForSelector('.tk-belirsiz');
  dialoglar.length = 0;
  await sayfa.locator('.taslak-kart').nth(1).locator('[data-sil]').click();
  await sayfa.waitForTimeout(200);
  ok('(H) işaretsiz taslakta eski kısa onay metni', !/BELİRSİZ/.test(dialoglar.find(d => d.startsWith('confirm:')) || ''));

  // (I) eski sunucu yanıtı (belirsizGonderim alanı yok) → hata yok, rozet yok
  D.taslaklar = [{ ...TASLAK('t9', '01XYZ01', null), belirsizGonderim: undefined }];
  await sayfa.evaluate(() => taslakEkraniAc());
  await sayfa.waitForFunction(() => document.querySelectorAll('.taslak-kart').length === 1);
  ok('(I) belirsizGonderim alanı hiç yoksa rozet yok, sayfa çalışır', await sayfa.locator('.tk-belirsiz').count() === 0);
  await ctx.close();

  // ================= mobil 390 + koyu tema =================
  const m = await sayfaKur({ width: 390, height: 800 });
  D.taslaklar = [TASLAK('t1', '33NCN99', BELIRSIZ)];
  await taslaklariAc(m.sayfa);
  D.gonder = () => [409, { hata: 'DURDURULDU: ' + 'çok uzun açıklama '.repeat(12), kod: 'belirsiz_gonderim_kunye_var', belirsiz: true,
    taslakKorundu: true, isaretId: 'bgABC123', bulunan: BULUNAN }];
  const tasma = () => m.sayfa.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
  ok('(M) 390px: rozetli kartta yatay taşma yok', !(await tasma()));
  const rozet = await m.sayfa.evaluate(() => { const r = document.querySelector('.tk-belirsiz').getBoundingClientRect(), k = document.querySelector('.taslak-kart').getBoundingClientRect();
    return r.left >= k.left - 1 && r.right <= k.right + 1; });
  ok('(M) 390px: rozet kartın içinde', rozet);
  await m.sayfa.locator('.taslak-kart [data-gonder]').click();
  await m.sayfa.waitForSelector('#modalOnayKutu:not(.gizli)');
  await m.sayfa.waitForFunction(() => !document.getElementById('btnOnay').disabled);
  await m.sayfa.click('#btnOnay');
  await m.sayfa.waitForSelector('#belirsizKutu');
  const mk = await m.sayfa.evaluate(() => {
    const kutu = document.getElementById('modalOnayKutu').getBoundingClientRect(), b = document.getElementById('belirsizKutu').getBoundingClientRect();
    const o = document.getElementById('btnOnay').getBoundingClientRect();
    return { icinde: b.left >= kutu.left - 1 && b.right <= kutu.right + 1, onayIc: o.right <= innerWidth + 1 && o.left >= -1,
      tasma: document.documentElement.scrollWidth > innerWidth + 1 };
  });
  ok('(M) 390px: kalıcı kutu pencerenin içinde, onay düğmesi ekranda, yatay taşma yok', mk.icinde && mk.onayIc && !mk.tasma, JSON.stringify(mk));
  // koyu tema
  await m.sayfa.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  const koyu = await m.sayfa.evaluate(() => getComputedStyle(document.getElementById('belirsizKutu')).backgroundColor);
  ok('(M) koyu tema: kutu koyu zeminli (açık pembe değil)', koyu === 'rgb(69, 10, 10)', koyu);
  await m.sayfa.click('#btnVazgec');
  await m.sayfa.waitForSelector('.tk-belirsiz');
  const koyuRozet = await m.sayfa.evaluate(() => getComputedStyle(document.querySelector('.tk-belirsiz')).backgroundColor);
  ok('(M) koyu tema: kart rozeti koyu zeminli', koyuRozet === 'rgb(127, 29, 29)', koyuRozet);
  await m.ctx.close();

  ok('sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTümü geçti');
  process.exit(fail ? 1 : 0);
})();
