// =========================================================
// scripts/hks_taslak_kopyala_smoke.js — Hal Kayıt "Taslaklar → 📋 Kopyala" (v368)
//   node scripts/hks_taslak_kopyala_smoke.js   → çıkış 0 = geçti
// halkayit/app.html'i yerel http sunucusundan servis eder, api.php isteklerini
// sahte JSON ile yanıtlar (canlıya/DB'ye dokunmaz). Playwright yoksa ATLAR.
// Doğrular: her kartta Kopyala düğmesi; Kopyala forma yükler (kopya kipi,
// kendi toast'ı); kayıtta eskiTaslakId GİTMEZ, taslak_sil İSTEĞİ YOK, beyan
// bağı (ortak.kaynak) taşınmaz; Düzenle eskisi gibi (eskiTaslakId + sil);
// 4 düğme 360/390/900/1280 genişlikte taşmaz.
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
  urunler: [{ id: 1, ad: 'Kayısı' }], ulkeler: [{ id: 1, ad: 'Rusya' }], belgeTipleri: [{ id: 1, ad: 'Fatura' }],
  sifatlar: [{ id: 1, ad: 'İhracat' }], isletmeTurleri: [{ id: 1, ad: 'Hal' }],
  bildirimTurleri: [{ id: 1, ad: 'Satış' }],
};
const FIRMALAR = [{ id: 7, ad: 'Test Firma A', vergiNo: '1111111111', renk: 'teal' }, { id: 8, ad: 'Test Firma B', vergiNo: '2222222222', renk: 'teal' }];
const planOrtak = (plaka, kg, ek) => Object.assign({
  sifatId: 1, bildirimTuruId: 1, plaka, urunId: 1, urunAd: 'Kayısı', ulkeId: 1, ulkeAd: 'Rusya', fiyat: 20, planKg: kg,
  planSorgu: { urunId: 1, aySayisi: 12, isletmeTuruId: 0, sirala: 'azalan' },
}, ek || {});
const TASLAKLAR = () => [
  { id: 't-asil', zaman: '2026-10-06T10:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [], partiNo: 'BYN-77', partiKaynak: 'beyan',
    ortak: planOrtak('34ASL01', 5000, { kaynak: { tip: 'beyan', beyanId: 42 } }) },
  { id: 't-iki', zaman: '2026-10-06T11:00:00+03:00', firmaId: 7, firmaAd: 'Test Firma A', satirlar: [], partiNo: 'MAN-5', partiKaynak: '',
    ortak: planOrtak('06IKI02', 7000) },
];

const sunucu = http.createServer((q, s) => {
  if (q.url.startsWith('/app.html')) {
    s.setHeader('Content-Type', 'text/html; charset=utf-8');
    s.end(fs.readFileSync(path.join(KOK, 'halkayit', 'app.html'), 'utf8').replace('__CSRF_TOKEN__', 'tok123'));
  } else { s.statusCode = 404; s.end(); }
});

async function sayfaKur(ctx, D, hatalar) {
  const sayfa = await ctx.newPage();
  sayfa.on('pageerror', e => hatalar.push(e.message));
  sayfa.on('dialog', d => d.accept());
  await sayfa.route('**/api.php*', async (route) => {
    const r = route.request();
    const action = new URL(r.url()).searchParams.get('action');
    let g = {}; try { g = JSON.parse(r.postData() || '{}'); } catch (e) {}
    D.istekler.push({ action, govde: g });
    const j = (o) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
    switch (action) {
      case 'firmalar': return j({ firmalar: FIRMALAR });
      case 'sonlar': return j({ ulkeler: [], urunler: [] });
      case 'listeler': return j(LISTELER);
      case 'taslaklar': return j({ taslaklar: D.taslaklar });
      case 'gonderilenler': return j({ gonderilenler: [] });
      case 'stok': return j({ stok: [], toplam: 0 });
      case 'kunyeler': return j({ kunyeler: [] });
      case 'csrf': return j({ csrf: 'taze456' });
      case 'taslak_kaydet': return j({ tamam: true, id: 'yeni-t1' });
      case 'taslak_sil': return j({ tamam: true });
      case 'taslak_kopya_firmaya': return j({ tamam: true, id: 'kopya-1', firmaAd: 'Test Firma B', notlar: [] });
      default: return j({});
    }
  });
  await sayfa.goto('http://127.0.0.1:' + sunucu.address().port + '/app.html');
  await sayfa.waitForSelector('.firma-kart:not(.firma-ekle)');
  await sayfa.click('.firma-kart:not(.firma-ekle)');
  return sayfa;
}
const taslaklariAc = async (sayfa) => {
  await sayfa.click('#btnTaslaklar');
  await sayfa.waitForSelector('#taslakListe .taslak-kart');
};
const butonOlcu = (sayfa) => sayfa.evaluate(() => {
  const vw = innerWidth, sorun = [];
  document.querySelectorAll('#taslakListe .taslak-kart').forEach((kart, ki) => {
    const kr = kart.getBoundingClientRect();
    kart.querySelectorAll('.tk-butonlar .btn').forEach(b => {
      const r = b.getBoundingClientRect();
      if (r.left < kr.left - 1 || r.right > kr.right + 1) sorun.push('kart' + ki + ' dışına taşıyor: ' + b.textContent);
      if (b.scrollWidth > b.clientWidth + 1) sorun.push('kart' + ki + ' metin kesik: ' + b.textContent);
    });
  });
  if (document.documentElement.scrollWidth > vw + 1) sorun.push('sayfa yatay taşma');
  const sira = [...document.querySelectorAll('#taslakListe .taslak-kart:first-child .tk-butonlar .btn')].map(b => b.textContent.trim());
  return { sorun, sira };
});

(async () => {
  await new Promise(r => sunucu.listen(0, r));
  const tarayici = await chromium.launch();
  const hatalar = [];
  const ctx = await tarayici.newContext({ viewport: { width: 1280, height: 800 } });
  const D = { istekler: [], taslaklar: TASLAKLAR() };
  const sayfa = await sayfaKur(ctx, D, hatalar);
  await taslaklariAc(sayfa);

  ok('her taslak kartında 📋 Kopyala (data-kopyala = taslak id)',
     JSON.stringify(await sayfa.$$eval('#taslakListe [data-kopyala]', bs => bs.map(b => b.dataset.kopyala))) === JSON.stringify(['t-asil', 't-iki']));
  // --- v526: taslak kartında parti no rozeti (yeni satır AÇMAZ: iki kartta da satır sayısı eşit) ---
  const rz = await sayfa.evaluate(() => [...document.querySelectorAll('#taslakListe .taslak-kart')].map(k => ({
    satir: k.querySelectorAll('.tk-satir').length, rozet: (k.querySelector('.tk-parti') || {}).textContent || '', plakaIci: !!k.querySelector('.tk-plaka .tk-parti') })));
  ok('v526: taslak kartında Parti rozeti plaka satırının içinde ("Parti: BYN-77" / "Parti: MAN-5"), satır sayısı eşit',
     rz.length === 2 && rz[0].rozet === 'Parti: BYN-77' && rz[1].rozet === 'Parti: MAN-5' && rz[0].plakaIci && rz[1].plakaIci && rz[0].satir === rz[1].satir, JSON.stringify(rz));
  const o1280 = await butonOlcu(sayfa);
  ok('düğme sırası: Gönder · Kopyala · Düzenle · Sil', JSON.stringify(o1280.sira) === JSON.stringify(['📤 Gönder', '📋 Kopyala', '✏️ Düzenle', '🗑️ Sil']), o1280.sira.join('|'));

  // --- Kopyala → form ---
  D.istekler.length = 0;
  await sayfa.click('#taslakListe [data-kopyala="t-asil"]');
  ok('Kopyala bir menü açar (Aynı firma ile kopyala / Firma değiştir…)',
     await sayfa.isVisible('#gTekrarMenu') && (await sayfa.textContent('#gTekrarMenuAyniAd')) === 'Aynı firma ile kopyala'
     && await sayfa.isVisible('#gTekrarMenu [data-sec="firma"]'));
  ok('menü açılınca henüz forma yüklenmedi, sunucuya yazı yok', !(await sayfa.isVisible('#ekranBildirim')) && !D.istekler.some(i => i.action === 'taslak_kopya_firmaya'));
  await sayfa.click('#gTekrarMenu [data-sec="ayni"]');
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(400);
  ok('Kopyala: form açıldı, plaka + plan kilosu taslaktan doldu',
     (await sayfa.inputValue('#oPlaka')) === '34ASL01' && (await sayfa.inputValue('#oPlanKilo')) === '5.000',
     (await sayfa.inputValue('#oPlaka')) + ' / ' + (await sayfa.inputValue('#oPlanKilo')));
  const tst = await sayfa.textContent('#toast');
  ok('Kopyala: kendi toast\'ı ("📋 Taslak kopyalandı…"), Tekrar gönder metni DEĞİL',
     tst.includes('📋 Taslak kopyalandı — değiştirip Taslağa Kaydet; asıl taslak yerinde kalır') && !tst.includes('Önceki gönderim'), tst);
  ok('Kopyala: "Önceki gönderimden" bilgi kutusu görünmez', !(await sayfa.isVisible('#kopyaNotlar')));
  ok('Kopyala: yükleme sunucuya hiçbir şey YAZMAZ (taslak_kaydet/taslak_sil yok)',
     !D.istekler.some(i => i.action === 'taslak_kaydet' || i.action === 'taslak_sil'));
  ok('Kopyala: duzenlenenTaslakId null', await sayfa.evaluate(() => duzenlenenTaslakId === null));
  const pk = await sayfa.evaluate(() => ({ v: document.getElementById('sPartiNo').value, ro: document.getElementById('sPartiNo').readOnly,
    not: !document.getElementById('partiNoNot').classList.contains('gizli'), max: document.getElementById('sPartiNo').maxLength,
    kart1: !!document.querySelector('.kart.bk-ayar #sPartiNo'), fs: getComputedStyle(document.getElementById('sPartiNo')).fontSize }));
  ok('v526: Kopyala kipinde Parti No BOŞ ve düzenlenebilir (beyandan gelen BYN-77 taşınmaz), not gizli; alan 1. kartta, maxlength 60',
     pk.v === '' && !pk.ro && !pk.not && pk.max === 60 && pk.kart1, JSON.stringify(pk));
  await sayfa.fill('#sPartiNo', '  KOP-1  ');

  // Plakayı değiştirip kaydet → YENİ taslak, asıl taslak dokunulmaz
  await sayfa.fill('#oPlaka', '35KOP99');
  D.istekler.length = 0;
  await sayfa.click('#btnPlanKaydet');
  await sayfa.waitForTimeout(400);
  const kayit = D.istekler.find(i => i.action === 'taslak_kaydet');
  ok('Kaydet: taslak_kaydet gitti, gövdede eskiTaslakId YOK, yeni plaka',
     !!kayit && !('eskiTaslakId' in kayit.govde) && kayit.govde.ortak.plaka === '35KOP99' && kayit.govde.ortak.planKg === 5000,
     JSON.stringify(kayit && kayit.govde));
  ok('Kaydet: beyan bağı (ortak.kaynak) kopyaya TAŞINMADI', !!kayit && !('kaynak' in kayit.govde.ortak));
  ok('v526: Kaydet: ortak.partiNo = trim(değer) = "KOP-1"', !!kayit && kayit.govde.ortak.partiNo === 'KOP-1', JSON.stringify(kayit && kayit.govde.ortak));
  ok('Kaydet: hiçbir taslak_sil isteği YOK (asıl taslak silinmez)', !D.istekler.some(i => i.action === 'taslak_sil'));
  ok('Kaydet: toast "Taslak kaydedildi" (güncellendi DEĞİL), Taslaklar ekranı',
     (await sayfa.textContent('#toast')).includes('Taslak kaydedildi') && await sayfa.isVisible('#ekranTaslaklar'));

  // --- Regresyon: Düzenle eskisi gibi ---
  await sayfa.waitForSelector('#taslakListe [data-duzenle="t-asil"]');
  await sayfa.click('#taslakListe [data-duzenle="t-asil"]');
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(400);
  ok('Düzenle: eski toast ("Plan taslağı forma yüklendi")', (await sayfa.textContent('#toast')).includes('Plan taslağı forma yüklendi'));
  const pd = await sayfa.evaluate(() => ({ v: document.getElementById('sPartiNo').value, ro: document.getElementById('sPartiNo').readOnly,
    not: !document.getElementById('partiNoNot').classList.contains('gizli'), notMetin: document.getElementById('partiNoNot').textContent }));
  ok('v526: Düzenle (beyandan gelen taslak): Parti No "BYN-77", salt okunur + "Beyandan geldi" notu',
     pd.v === 'BYN-77' && pd.ro && pd.not && pd.notMetin === 'Beyandan geldi', JSON.stringify(pd));
  D.istekler.length = 0;
  await sayfa.click('#btnPlanKaydet'); await sayfa.waitForTimeout(400);
  const k2 = D.istekler.find(i => i.action === 'taslak_kaydet'), sil2 = D.istekler.find(i => i.action === 'taslak_sil');
  ok('Düzenle→Kaydet: eskiTaslakId gider + eski taslak silinir (DEĞİŞMEDİ)',
     !!k2 && k2.govde.eskiTaslakId === 't-asil' && !!sil2 && sil2.govde.id === 't-asil', JSON.stringify([k2 && k2.govde.eskiTaslakId, sil2 && sil2.govde]));
  ok('v526: Düzenle→Kaydet: ortak.partiNo "BYN-77" korunur', !!k2 && k2.govde.ortak.partiNo === 'BYN-77');
  const pt = await sayfa.evaluate(() => ({ v: document.getElementById('sPartiNo').value, ro: document.getElementById('sPartiNo').readOnly, not: !document.getElementById('partiNoNot').classList.contains('gizli') }));
  ok('v526: kayıttan sonra formuTemizle: alan boş, readOnly kalkar, not gizli', pt.v === '' && !pt.ro && !pt.not, JSON.stringify(pt));

  // --- v526: beyansız (kaynaksız) taslakta alan düzenlenebilir; boş bırakılırsa ortak.partiNo GİTMEZ ---
  await sayfa.waitForSelector('#taslakListe [data-duzenle="t-iki"]');
  await sayfa.click('#taslakListe [data-duzenle="t-iki"]');
  await sayfa.waitForSelector('#ekranBildirim:not(.gizli)');
  await sayfa.waitForTimeout(400);
  const pi = await sayfa.evaluate(() => ({ v: document.getElementById('sPartiNo').value, ro: document.getElementById('sPartiNo').readOnly, not: !document.getElementById('partiNoNot').classList.contains('gizli') }));
  ok('v526: Düzenle (elle taslak): Parti No "MAN-5", düzenlenebilir, not yok', pi.v === 'MAN-5' && !pi.ro && !pi.not, JSON.stringify(pi));
  await sayfa.fill('#sPartiNo', '');
  D.istekler.length = 0;
  await sayfa.click('#btnPlanKaydet'); await sayfa.waitForTimeout(400);
  const k3 = D.istekler.find(i => i.action === 'taslak_kaydet');
  ok('v526: alan boşaltılınca ortak.partiNo GÖNDERİLMEZ (opsiyonel)', !!k3 && !('partiNo' in k3.govde.ortak), JSON.stringify(k3 && k3.govde.ortak));

  // --- v500: Kopyala → Firma değiştir… (doğrudan kopya taslak) ---
  await sayfa.waitForSelector('#taslakListe [data-kopyala="t-iki"]');
  D.istekler.length = 0;
  await sayfa.click('#taslakListe [data-kopyala="t-iki"]');
  await sayfa.click('#gTekrarMenu [data-sec="firma"]');
  await sayfa.waitForSelector('#firmaSecPencere.acik');
  ok('firma penceresi: taslak başlığı + özet, aktif firma listede YOK',
     (await sayfa.textContent('#firmaSecBaslik')).includes('taslağına kopyalansın') && (await sayfa.textContent('#firmaSecOzet')).includes('06IKI02')
     && JSON.stringify(await sayfa.$$eval('#firmaSecListe [data-firma-id]', bs => bs.map(b => b.dataset.firmaId))) === JSON.stringify(['8']));
  await sayfa.click('#firmaSecListe [data-firma-id="8"]');
  await sayfa.waitForTimeout(500);
  const kf = D.istekler.find(i => i.action === 'taslak_kopya_firmaya');
  ok('taslak_kopya_firmaya: id + kaynak firma + hedef firma gitti', !!kf && kf.govde.id === 't-iki' && String(kf.govde.firmaId) === '7' && String(kf.govde.hedefFirmaId) === '8', JSON.stringify(kf && kf.govde));
  ok('kopya sonrası hedef firmaya geçildi, form AÇILMADI, kaynak taslak silinmedi',
     await sayfa.evaluate(() => String(aktifFirma.id) === '8') && !D.istekler.some(i => i.action === 'taslak_sil') && !(await sayfa.isVisible('#ekranBildirim')));
  ok('hedef firmanın Taslaklar ekranı yenilendi (taslaklar isteği)', D.istekler.some(i => i.action === 'taslaklar'));

  // --- Düğme yerleşimi: taşma yok ---
  await sayfa.waitForSelector('#taslakListe .taslak-kart');
  for (const w of [360, 390, 420, 768, 900, 1280]) {
    await sayfa.setViewportSize({ width: w, height: 800 });
    await sayfa.waitForTimeout(150);
    const o = await butonOlcu(sayfa);
    ok(w + 'px: 4 düğme kart içinde, metin kesilmez, yatay taşma yok', o.sorun.length === 0, o.sorun.join(' | '));
  }
  await sayfa.setViewportSize({ width: 360, height: 800 });
  const iki = await sayfa.evaluate(() => {
    const bs = [...document.querySelectorAll('#taslakListe .taslak-kart:first-child .tk-butonlar .btn')].map(b => Math.round(b.getBoundingClientRect().top));
    return bs[0] === bs[1] && bs[2] === bs[3] && bs[2] > bs[0];
  });
  ok('360px: düğmeler 2×2', iki);

  ok('sayfa JS hatası yok', hatalar.length === 0, hatalar.join(' | '));
  await tarayici.close(); sunucu.close();
  console.log(fail ? `\n${fail} test BAŞARISIZ` : '\nTüm testler geçti.');
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
