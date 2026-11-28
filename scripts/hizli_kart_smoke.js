// =========================================================
// scripts/hizli_kart_smoke.js — Ortak "Hızlı Cari / Stok Ekle" penceresi TARAYICI uçtan uca testi (v611 Faz 2; gerçek PHP + gerçek assets/hizli_kart.js)
//   node scripts/hizli_kart_smoke.js     → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   HK_SHOT=<klasör>                     → ekran görüntüleri (repoya konmaz)
// Sahte yanıt YOK: scripts/_stok_e2e_sunucu.js her isteği scripts/_stok_istek.php alt süreciyle GERÇEK cari_kartlari.php / stok_kartlari.php /
// api_hizli_kart.php'de çalıştırır (dosya SQLite, stok_ui_e2e_setup.php "ornek": stok + cari şeması v611 kolonlarıyla). Canlı DB'ye dokunulmaz.
// Her genişlikte (1440 · 768 · 390 dokunmatik + kısa 390x560): düğme → pencere; 400 ms sonra ölçüm: Kaydet/İptal ekranda ve tıklanabilir,
// gövde kayar (kısa ekranda), yatay taşma yok, <768'de girdiler 16px; müstahsil vurgusu (açık + koyu); Esc kapatır; odak tuzağı;
// alan hatası; çift gönderim tek istek; başarı → yeni karta gidilir, listede hızlı renkli satır + bildirim. Stok: HKS süzülen liste, dara zorunlu, "1,8".
// Kimlikler algoritmayla üretilmiş SENTETİK değerlerdir.
// =========================================================
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
let pw;
try { pw = require(process.env.PW_PATH || 'playwright'); }
catch (e) {
  try { pw = require('/opt/node22/lib/node_modules/playwright'); }
  catch (e2) {
    try { pw = require('/opt/node-tools/node_modules/playwright'); }
    catch (e3) { console.log('Playwright bulunamadi — test ATLANDI (hata degil).'); process.exit(0); }
  }
}
const ROOT = path.resolve(__dirname, '..');
const { baslat } = require('./_stok_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'hizli_kart_'));
const SHOT = process.env.HK_SHOT || '';
let fail = 0, n = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(104) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];

function tckn(d9) {
  const d = d9.split('').map(Number);
  const d10 = ((((d[0] + d[2] + d[4] + d[6] + d[8]) * 7 - (d[1] + d[3] + d[5] + d[7])) % 10) + 10) % 10;
  return d9 + d10 + ((d.reduce((a, b) => a + b, 0) + d10) % 10);
}
async function ortam() {
  const db = path.join(TMP, 'hk.sqlite');
  execFileSync('php', [path.join(__dirname, 'stok_ui_e2e_setup.php'), db, 'ornek'], { cwd: ROOT, stdio: 'pipe' });
  const srv = await baslat({ db, sid: 'hkE2E' + process.pid });
  srv.say = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'stok_ui_e2e_say.php'), db], { cwd: ROOT }).toString());
  srv.sayi = re => srv.istekler.filter(i => re.test(i.yontem + ' ' + i.url)).length;
  return srv;
}
async function sayfa(browser, srv, vp, dokunmatik) {
  const ctx = await browser.newContext({ viewport: vp, locale: 'tr-TR', serviceWorkers: 'block', deviceScaleFactor: 1, hasTouch: !!dokunmatik, isMobile: !!dokunmatik });
  const p = await ctx.newPage();
  p.on('pageerror', e => hatalar.push('[' + vp.width + '] pageerror: ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) hatalar.push('[' + vp.width + '] console: ' + m.text()); });
  p.on('response', r => { if (r.status() >= 500) hatalar.push('[' + vp.width + '] HTTP ' + r.status() + ' ' + r.url()); });
  p.srv = srv;
  return p;
}
const git = (p, yol) => p.goto(p.srv.url + '/' + yol, { waitUntil: 'load' });
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 6000 }); return true; } catch (e) { return false; } };
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
async function pencereAc(p, tur) {
  const d = p.locator('[data-hk-ac="' + tur + '"]:visible').first();
  await d.click();
  const r = await kisaBekle(p, () => { const m = document.getElementById('hkModal'); return m && !m.hidden && m.querySelector('.pm-dialog').getBoundingClientRect().height > 50; }, null, 3000);
  await bekle(400);   // açılış animasyonu (220 ms) bitmeden ölçme
  return r;
}
const acik = p => p.evaluate(() => { const m = document.getElementById('hkModal'); return !!(m && !m.hidden); });
// Pencere düzeni: footer düğmeleri ekranda + gerçekten tıklanabilir (elementFromPoint), yatay taşma yok, gövde kayabiliyor mu
const olc = p => p.evaluate(() => {
  const m = document.getElementById('hkModal'), dlg = m.querySelector('.pm-dialog'), govde = m.querySelector('.pm-body'), vw = innerWidth, vh = innerHeight;
  const dugme = s => {
    const b = m.querySelector(s), r = b.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
    const e = document.elementFromPoint(x, y);
    return { ekranda: r.top >= 0 && r.bottom <= vh + 0.5 && r.left >= 0 && r.right <= vw + 0.5, tiklanir: !!e && (e === b || b.contains(e)), h: Math.round(r.height) };
  };
  return {
    kaydet: dugme('[data-hk-kaydet]'), iptal: dugme('.pm-footer [data-hk-kapat]'),
    tasmaSayfa: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    tasmaDialog: dlg.scrollWidth - dlg.clientWidth, tasmaGovde: govde.scrollWidth - govde.clientWidth,
    govdeKayar: govde.scrollHeight > govde.clientHeight + 1, govdeOverflow: getComputedStyle(govde).overflowY,
    dlgAlt: Math.round(dlg.getBoundingClientRect().bottom), vh, z: +getComputedStyle(m).zIndex,
    kucukYazi: [...m.querySelectorAll('input:not([type=hidden]), select')].filter(e => e.getClientRects().length && parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.id),
    kucukHedef: [...m.querySelectorAll('input:not([type=hidden]), select, .pm-footer .btn')].filter(e => e.getClientRects().length && e.getBoundingClientRect().height < 44).map(e => e.name || e.textContent.trim())
  };
});
// Gövdeyi sona kaydır → en alttaki görünür alan görünür + tıklanabilir mi
const sonaKaydir = p => p.evaluate(async () => {
  const m = document.getElementById('hkModal'), govde = m.querySelector('.pm-body');
  govde.scrollTop = govde.scrollHeight;
  await new Promise(r => setTimeout(r, 80));
  const alanlar = [...m.querySelectorAll('.hk-bolum:not([hidden]) input:not([type=hidden]), .hk-bolum:not([hidden]) select')].filter(e => e.getClientRects().length);
  const son = alanlar[alanlar.length - 1], r = son.getBoundingClientRect(), g = govde.getBoundingClientRect();
  const e = document.elementFromPoint(r.left + Math.min(20, r.width / 2), r.top + r.height / 2);
  return { kaydi: govde.scrollTop > 0, gorunur: r.top >= g.top - 0.5 && r.bottom <= g.bottom + 0.5, tiklanir: !!e && (e === son || son.contains(e)), ad: son.name };
});
const vurgu = p => p.evaluate(() => {
  const k = s => document.querySelector('#hkModal [data-hk-bolum="cari"] [data-hk-alan="' + s + '"]');
  const v = e => ({ sinif: e.classList.contains('hk-vurgu'), bg: getComputedStyle(e).backgroundColor, ipucu: !!e.querySelector('[data-hk-vurgu-ipucu]:not([hidden])') && e.querySelector('[data-hk-vurgu-ipucu]').getClientRects().length > 0 });
  return { tel: v(k('telefon')), tckn: v(k('tckn')), vkn: v(k('vergi_no')), not: (() => { const x = document.querySelector('[data-hk-mustahsil-not]'); return !!x && !x.hidden && x.getClientRects().length > 0; })() };
});
const tipSec = (p, deger) => p.selectOption('#hkModal [data-hk-bolum]:not([hidden]) select[name="tip"]', deger);
const doldur = (p, ad, deger) => p.fill('#hkModal [data-hk-bolum]:not([hidden]) [name="' + ad + '"]', deger);
const alanHatasi = (p, ad) => p.evaluate(a => { const k = document.querySelector('#hkModal [data-hk-bolum]:not([hidden]) [data-hk-alan="' + a + '"]'); const s = k && k.querySelector('.hk-alan-hata'); return s && !s.hidden ? s.textContent : ''; }, ad);
const bildirim = p => p.evaluate(() => { const t = document.querySelector('.hk-toast'); return t && !t.hidden ? t.textContent : ''; });

async function cariAkis(browser, srv, vp, dokunmatik, etiket, tcknNo) {
  const p = await sayfa(browser, srv, vp, dokunmatik);
  await git(p, 'cari_kartlari.php');
  ok(`[${etiket}] Cari Kartları: ⚡ Hızlı ekle düğmesi görünür`, await p.locator('[data-hk-ac="cari"]:visible').count() === 1);
  if (vp.width < 768) ok(`[${etiket}] düğme ≥44px (dokunma hedefi)`, await p.$eval('[data-hk-ac="cari"]', e => e.getBoundingClientRect().height >= 44 && e.getBoundingClientRect().width >= 44));
  ok(`[${etiket}] düğme "+ Yeni" ile aynı satırda, arama kutusu daralmadan (≥120px)`, await p.evaluate(() => { const d = document.querySelector('[data-hk-ac="cari"]'), y = document.querySelector('[data-cr-modal="kart"][title]'), a = document.getElementById('crArama'); return Math.abs(d.getBoundingClientRect().top - y.getBoundingClientRect().top) < 2 && a.getBoundingClientRect().width >= 120; }));
  ok(`[${etiket}] pencere açılır`, await pencereAc(p, 'cari'));
  const o = await olc(p);
  ok(`[${etiket}] Kaydet + İptal ekranda ve tıklanabilir`, o.kaydet.ekranda && o.kaydet.tiklanir && o.iptal.ekranda && o.iptal.tiklanir, JSON.stringify(o));
  ok(`[${etiket}] yatay taşma yok (sayfa/pencere/gövde), z-index ≥ 600`, o.tasmaSayfa <= 0 && o.tasmaDialog <= 0 && o.tasmaGovde <= 0 && o.z >= 600, JSON.stringify(o));
  ok(`[${etiket}] gövde kaydırılabilir (overflow-y auto), pencere ekranın içinde`, o.govdeOverflow === 'auto' && o.dlgAlt <= o.vh + 0.5, JSON.stringify(o));
  if (vp.width < 768) ok(`[${etiket}] mobil: girdiler 16px, hedefler ≥44px`, !o.kucukYazi.length && !o.kucukHedef.length, JSON.stringify(o));
  else ok(`[${etiket}] girdi/düğme yüksekliği ≥44px`, !o.kucukHedef.length, JSON.stringify(o.kucukHedef));
  ok(`[${etiket}] ilk odak Unvan`, await p.evaluate(() => document.activeElement && document.activeElement.name === 'unvan'));
  // Odak tuzağı: 14 Tab sonra hâlâ pencere içinde
  for (let i = 0; i < 14; i++) await p.keyboard.press('Tab');
  ok(`[${etiket}] odak tuzağı (Tab ×14 pencere içinde)`, await p.evaluate(() => document.getElementById('hkModal').contains(document.activeElement)));
  // Müstahsil vurgusu
  let v = await vurgu(p);
  ok(`[${etiket}] tip seçilmeden vurgu yok`, !v.tel.sinif && !v.tckn.sinif && !v.not);
  await tipSec(p, 'mustahsil');
  v = await vurgu(p);
  ok(`[${etiket}] müstahsil: TCKN + Telefon FARKLI tonda (vurgu sınıfı + zemin), "HKS için önerilir" görünür; VKN vurgusuz`, v.tel.sinif && v.tckn.sinif && v.tckn.bg === 'rgb(228, 246, 243)' && v.tel.ipucu && v.tckn.ipucu && v.not && !v.vkn.sinif, JSON.stringify(v));
  await shot(p, 'cari_pencere_' + etiket);
  if (etiket === '1440') {
    await p.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await bekle(400);
    v = await vurgu(p);
    ok(`[${etiket}] koyu tema: vurgu zemini koyu belirteç`, v.tckn.bg === 'rgb(18, 51, 47)', JSON.stringify(v.tckn));
    await shot(p, 'cari_koyu_' + etiket);
    await p.evaluate(() => document.documentElement.removeAttribute('data-theme'));
  }
  // Esc kapatır, odak düğmeye döner
  await p.keyboard.press('Escape'); await bekle(100);
  ok(`[${etiket}] Esc kapatır`, !(await acik(p)));
  ok(`[${etiket}] kapanınca odak ⚡ düğmesine döner`, await p.evaluate(() => !!document.activeElement && document.activeElement.hasAttribute('data-hk-ac')));
  // İptal düğmesi kapatır
  await pencereAc(p, 'cari');
  await p.click('#hkModal .pm-footer [data-hk-kapat]'); await bekle(100);
  ok(`[${etiket}] İptal kapatır`, !(await acik(p)));
  // Alan hatası (sunucu): geçersiz TCKN → alanın altında; kayıt yok
  await pencereAc(p, 'cari');
  const unvan = 'Hızlı E2E Müstahsil ' + etiket;
  await doldur(p, 'unvan', unvan);
  await tipSec(p, 'mustahsil');
  await doldur(p, 'tckn', '12345678900');
  const post0 = srv.sayi(/^POST \/api_hizli_kart/);
  await p.keyboard.press('Enter');
  await kisaBekle(p, () => { const s = document.querySelector('#hkModal [data-hk-alan="tckn"] .hk-alan-hata'); return s && !s.hidden; }, null, 6000);
  ok(`[${etiket}] geçersiz TCKN → alanın altında sunucu hatası, pencere açık`, /T\.C\. kimlik/.test(await alanHatasi(p, 'tckn')) && await acik(p) && srv.sayi(/^POST \/api_hizli_kart/) === post0 + 1);
  // Geçerli TCKN + telefon → çift gönderim tek istek → başarı → yeni karta gidilir
  await doldur(p, 'tckn', tcknNo);
  await doldur(p, 'telefon', '0 555 000 00 ' + String(10 + (vp.width % 80)).slice(-2));
  const post1 = srv.sayi(/^POST \/api_hizli_kart/);
  await p.evaluate(() => { const f = document.getElementById('hkForm'); f.requestSubmit(); f.requestSubmit(); });
  await p.waitForURL(/cari_kartlari\.php\?id=\d+/, { timeout: 8000 }).catch(() => {});
  await p.waitForLoadState('load'); await bekle(300);
  ok(`[${etiket}] çift gönderim → TEK POST (düğme kilidi)`, srv.sayi(/^POST \/api_hizli_kart/) === post1 + 1, String(srv.sayi(/^POST \/api_hizli_kart/) - post1));
  const id = +((p.url().match(/id=(\d+)/) || [])[1] || 0);
  ok(`[${etiket}] başarı → cari_kartlari.php?id=<yeni> açıldı`, id > 0, p.url());
  ok(`[${etiket}] bildirim "Hızlı eklendi — eksikleri Cari Kartları'ndan tamamlayın"`, /Hızlı eklendi — eksikleri Cari Kartları/.test(await bildirim(p)), await bildirim(p));
  const satir = await p.evaluate(i => {
    const a = document.querySelector('a.cari-kart[href="cari_kartlari.php?id=' + i + '"]');
    if (!a) return null;
    return { hizli: a.classList.contains('cari-kart--hizli'), data: a.getAttribute('data-hizli'), ad: (a.querySelector('.cari-kart-ad') || {}).textContent, gorunur: a.getClientRects().length > 0, bg: getComputedStyle(a).backgroundColor,
      bant: !!document.querySelector('.cari-hizli-bant') && document.querySelector('.cari-hizli-bant').getClientRects().length > 0 };
  }, id);
  ok(`[${etiket}] listede yeni satır hızlı renkte (cari-kart--hizli + data-hizli=1)`, !!satir && satir.hizli && satir.data === '1' && satir.ad === unvan, JSON.stringify(satir));
  if (vp.width >= 1024) ok(`[${etiket}] hızlı satır görünür ve ayrı zeminde (--cari-hizli-bg)`, !!satir && satir.gorunur && satir.bg === 'rgb(255, 244, 214)', JSON.stringify(satir));
  else ok(`[${etiket}] dar ekranda detayda "hızlı eklendi" bandı görünür`, !!satir && satir.bant, JSON.stringify(satir));
  await shot(p, 'cari_sonra_' + etiket);
  await p.context().close();
  return id;
}

async function stokAkis(browser, srv, vp, dokunmatik, etiket) {
  const p = await sayfa(browser, srv, vp, dokunmatik);
  await git(p, 'stok_kartlari.php');
  ok(`[${etiket}] Stok Kartları: ⚡ Hızlı ekle düğmesi görünür`, await p.locator('[data-hk-ac="stok"]:visible').count() === 1);
  ok(`[${etiket}] stok penceresi açılır (yalnız stok bölümü)`, await pencereAc(p, 'stok') && await p.evaluate(() => !document.querySelector('#hkModal [data-hk-bolum="cari"]') && !document.querySelector('#hkModal [data-hk-bolum="stok"]').hidden));
  const o = await olc(p);
  ok(`[${etiket}] stok: Kaydet + İptal ekranda ve tıklanabilir, taşma yok`, o.kaydet.ekranda && o.kaydet.tiklanir && o.iptal.ekranda && o.iptal.tiklanir && o.tasmaSayfa <= 0 && o.tasmaDialog <= 0 && o.tasmaGovde <= 0, JSON.stringify(o));
  if (vp.width < 768) ok(`[${etiket}] stok mobil: girdiler 16px, hedefler ≥44px`, !o.kucukYazi.length && !o.kucukHedef.length, JSON.stringify(o));
  if (o.govdeKayar) {
    const k = await sonaKaydir(p);
    ok(`[${etiket}] kısa ekran: gövde kayar, en alttaki alan (${k.ad}) görünür + tıklanabilir, footer yine tıklanabilir`, k.kaydi && k.gorunur && k.tiklanir && (await olc(p)).kaydet.tiklanir, JSON.stringify(k));
    await p.evaluate(() => { document.querySelector('#hkModal .pm-body').scrollTop = 0; });
  }
  ok(`[${etiket}] varsayılan: tip Ürün, birim kg, HKS alanı görünür`, await p.evaluate(() => { const b = document.querySelector('#hkModal [data-hk-bolum="stok"]'); return b.querySelector('[name=tip]').value === 'urun' && b.querySelector('[name=ana_birim]').value === 'kg' && !b.querySelector('[data-hk-hks-alan]').hidden; }));
  const ad = 'Hızlı E2E Kasa ' + etiket;
  await doldur(p, 'ad', ad);
  // HKS: yazınca süzülen liste → seç
  await p.fill('#hkHksAra', 'doma');
  const liste = await kisaBekle(p, () => { const l = document.getElementById('hkHksListe'); return l && !l.hidden && l.querySelector('li[role=option]'); }, null, 2000);
  ok(`[${etiket}] HKS: "doma" yazınca liste süzülür (Domates)`, liste && await p.evaluate(() => [...document.querySelectorAll('#hkHksListe li[role=option]')].map(l => l.firstChild.textContent).join('|') === 'Domates'));
  await p.locator('#hkHksListe li[role=option]').first().dispatchEvent('mousedown');
  ok(`[${etiket}] HKS seçimi gizli id'ye yazılır (101), liste kapanır`, await p.evaluate(() => document.querySelector('#hkModal [name=hks_urun_id]').value === '101' && document.getElementById('hkHksAra').value === 'Domates' && document.getElementById('hkHksListe').hidden));
  // Malzeme tipine geç → HKS gizli; ambalaj Kasa seç → dara zorunlu işareti
  await tipSec(p, 'malzeme');
  await p.selectOption('#hkModal [name=ana_birim]', 'adet');
  ok(`[${etiket}] tip Malzeme → HKS alanı gizlenir`, await p.evaluate(() => document.querySelector('#hkModal [data-hk-hks-alan]').hidden));
  await p.selectOption('#hkModal [name=ambalaj_tip_id]', { label: 'Kasa' });
  ok(`[${etiket}] ambalaj seçilince dara zorunlu işareti (*) görünür`, await p.evaluate(() => !document.querySelector('[data-hk-dara-zorunlu]').hidden));
  const post0 = srv.sayi(/^POST \/api_hizli_kart/);
  await p.click('#hkModal [data-hk-kaydet]'); await bekle(150);
  ok(`[${etiket}] dara boş → istemci alan hatası, istek gönderilmez`, /zorunlu/.test(await alanHatasi(p, 'birim_dara_kg')) && srv.sayi(/^POST \/api_hizli_kart/) === post0);
  await doldur(p, 'birim_dara_kg', '1.234,5');
  await p.click('#hkModal [data-hk-kaydet]');
  await kisaBekle(p, () => { const s = document.querySelector('#hkModal [data-hk-alan="birim_dara_kg"] .hk-alan-hata'); return s && !s.hidden && /1\.000/.test(s.textContent); }, null, 6000);
  ok(`[${etiket}] dara "1.234,5" → sunucu alan hatası (stok_dara_coz)`, /1\.000 kg/.test(await alanHatasi(p, 'birim_dara_kg')), await alanHatasi(p, 'birim_dara_kg'));
  await doldur(p, 'birim_dara_kg', '1,8');
  await p.focus('#hkModal [name=birim_dara_kg]');
  await p.keyboard.press('Enter');   // Enter gönderir
  await p.waitForURL(/stok_kartlari\.php\?id=\d+/, { timeout: 8000 }).catch(() => {});
  await p.waitForLoadState('load'); await bekle(300);
  const id = +((p.url().match(/id=(\d+)/) || [])[1] || 0);
  ok(`[${etiket}] Enter → kaydedildi, stok_kartlari.php?id=<yeni>`, id > 0, p.url());
  const k = srv.say().kartlar[id] || {};
  ok(`[${etiket}] DB: hizli=1, ambalaj Kasa, dara 1800 g, HKS boş (malzeme)`, k.hizli === 1 && k.dara === 1800 && k.hks_urun_id === null && k.ad === ad, JSON.stringify(k));
  ok(`[${etiket}] bildirim "Hızlı eklendi — eksikleri Stok Kartları'ndan tamamlayın"`, /Hızlı eklendi — eksikleri Stok Kartları/.test(await bildirim(p)), await bildirim(p));
  const satir = await p.evaluate(i => { const a = document.querySelector('a.st-satir[data-id="' + i + '"]'); return a ? { hizli: a.classList.contains('st-satir--hizli'), data: a.getAttribute('data-hizli'), gorunur: a.getClientRects().length > 0 } : null; }, id);
  ok(`[${etiket}] listede yeni satır hızlı renkte (st-satir--hizli + data-hizli=1)`, !!satir && satir.hizli && satir.data === '1' && (vp.width < 1024 || satir.gorunur), JSON.stringify(satir));
  await shot(p, 'stok_sonra_' + etiket);
  await p.context().close();
}

(async () => {
  let browser, srv;
  try {
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
    srv = await ortam();
    const tc = ['21234567', '31234567', '41234567'].map(s => tckn(s + '1'));
    await cariAkis(browser, srv, { width: 1440, height: 900 }, false, '1440', tc[0]);
    await cariAkis(browser, srv, { width: 768, height: 1024 }, false, '768', tc[1]);
    await cariAkis(browser, srv, { width: 390, height: 844 }, true, '390', tc[2]);
    await stokAkis(browser, srv, { width: 1440, height: 900 }, false, '1440');
    await stokAkis(browser, srv, { width: 768, height: 1024 }, false, '768');
    await stokAkis(browser, srv, { width: 390, height: 844 }, true, '390');
    await stokAkis(browser, srv, { width: 390, height: 560 }, true, '390x560');
    // API sözleşmesi (window.HizliKart.ac + sonra geri çağrısı; yönlendirmesiz)
    const p = await sayfa(browser, srv, { width: 1440, height: 900 }, false);
    await git(p, 'cari_kartlari.php');
    const sonuc = await p.evaluate(() => new Promise(res => {
      const acti = window.HizliKart.ac({ tur: 'cari', ad: 'API Çağrı Örnek Ltd.', tip: 'musteri', sonra: k => res({ acti: true, k }) });
      if (!acti) return res({ acti: false });
      setTimeout(() => document.getElementById('hkForm').requestSubmit(), 450);
      setTimeout(() => res({ acti: true, zaman: true }), 7000);
    }));
    ok('HizliKart.ac({tur, ad, tip, sonra}) → sonra(kart) {ok, tur, id, kod, ad, hizli:1, eksikler}', sonuc.acti && sonuc.k && sonuc.k.ok && sonuc.k.tur === 'cari' && sonuc.k.id > 0 && /^C\d{5}$/.test(sonuc.k.kod) && sonuc.k.ad === 'API Çağrı Örnek Ltd.' && sonuc.k.hizli === 1 && Array.isArray(sonuc.k.eksikler), JSON.stringify(sonuc));
    ok('ad + tip verilince pencere açılır, kapandıktan sonra bildirim görünür (yönlendirme yok)', /Hızlı eklendi/.test(await bildirim(p)) && /cari_kartlari\.php$/.test(p.url()));
    ok('HizliKart.ac({tur:"stok"}) cari sayfasında false (bileşende stok bölümü yok)', await p.evaluate(() => window.HizliKart.ac({ tur: 'stok' }) === false && window.HizliKart.kullanilabilir('cari') === true));
    await p.context().close();
    ok('sayfa hatası / konsol hatası / HTTP 5xx yok', hatalar.length === 0, hatalar.join(' | '));
  } catch (e) {
    fail++; console.log('*** BEKLENMEYEN HATA: ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    if (srv) await srv.kapat();
    fs.rmSync(TMP, { recursive: true, force: true });
  }
  console.log(`\n${n - fail} geçti, ${fail} kaldı`);
  process.exit(fail ? 1 : 0);
})();
