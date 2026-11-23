// =========================================================
// scripts/ceksenet_ui_smoke.js — "Çek-Senet" TARAYICI uçtan uca testi (gerçek PHP + gerçek assets/ceksenet.js)
//   NODE_PATH=/opt/node-tools/node_modules node scripts/ceksenet_ui_smoke.js   → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   CEKSENET_SHOT=<klasör>   → ekran görüntüleri (pc_liste.png, pc_form.png, pc_islem.png, mobil_liste.png, mobil_form.png, tanimlar_pc.png …; repoya konmaz)
//
// Sahte yanıt YOK: scripts/_ceksenet_e2e_sunucu.js her isteği scripts/_ceksenet_istek.php alt süreciyle GERÇEK cek_*.php / senet_dekontu.php sayfalarında çalıştırır
// (gerçek config/helpers.php kabuğu + ceksenet_core + ceksenet_ui) ve assets/'i diskten sunar. Durum dosya SQLite'ta kalıcıdır; DB gerçeği
// scripts/ceksenet_ui_e2e_say.php ile okunur. Canlı DB'ye / migrasyona HİÇ dokunulmaz.
// Bölümler: 1 masaüstü 1366 (tek ekran, liste, panel, seçim + ortalama vade) · 2 uçtan uca (banka kasası + cari hazır → 3 çekli giriş bordrosu [ortalama vade önizlemesi = sunucu] → ciro →
//   tahsile ver → bankadan tahsil → karşılıksız → son hareket iptali → verilen çek çıkışı → ödendi [yetersiz bakiye "Yine de kaydet"] → mükerrer → toplu seçim + ortalama vade → senet + protesto) ·
//   3 tanımlar · 4 tablet 820 · 5 mobil 390 · 6 şema yok / kasasız.
// =========================================================
'use strict';
const { execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
let pw;
try { pw = require(process.env.PW_PATH || 'playwright'); }
catch (e) {
  try { pw = require('/opt/node22/lib/node_modules/playwright'); }
  catch (e2) { console.log('Playwright bulunamadi — test ATLANDI (hata degil).'); process.exit(0); }
}
const ROOT = path.resolve(__dirname, '..');
const { baslat } = require('./_ceksenet_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'ceksenet_ui_'));
const SHOT = process.env.CEKSENET_SHOT || '';
const IZLE = !!process.env.E2E_IZLE;
let fail = 0, n = 0, dbNo = 0;
let sonAd = '';
const ok = (ad, k, ip) => { n++; sonAd = ad.slice(0, 70); if (!k) fail++; console.log(ad.padEnd(100) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];
const kr = x => Math.round(Number(x) * 100);   // SQLite sayı / MySQL metin: kuruşa çevir

async function ortam(mod) {
  const db = path.join(TMP, 'cs' + (++dbNo) + '.sqlite');
  execFileSync('php', [path.join(__dirname, 'ceksenet_ui_e2e_setup.php'), db, mod || 'fisler'], { cwd: ROOT, stdio: 'pipe', timeout: 60000 });
  const srv = await baslat({ db, sid: 'csE2E' + dbNo, depolar: ['Merkez Depo'] });
  srv.say = ids => JSON.parse(execFileSync('php', [path.join(__dirname, 'ceksenet_ui_e2e_say.php'), db].concat(ids ? [ids.join(',')] : []), { cwd: ROOT, timeout: 60000 }).toString());
  srv.sayi = re => srv.istekler.filter(i => re.test(i.yontem + ' ' + i.url)).length;
  return srv;
}
async function sayfa(browser, srv, vp, o) {
  o = o || {};
  const ctx = await browser.newContext({ viewport: vp, locale: 'tr-TR', serviceWorkers: 'block', deviceScaleFactor: 1, hasTouch: !!o.dokunmatik, isMobile: !!o.dokunmatik });
  const p = await ctx.newPage();
  p.on('pageerror', e => hatalar.push('[' + vp.width + '] pageerror: ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) hatalar.push('[' + vp.width + '] console: ' + m.text()); });
  p.on('response', r => { if (r.status() >= 500) hatalar.push('[' + vp.width + '] HTTP ' + r.status() + ' ' + r.url()); });
  p.on('dialog', d => { if (IZLE) console.error('[tanı] dialog ' + d.type() + ' ' + JSON.stringify(d.message()).slice(0, 80)); d.accept().catch(() => {}); });
  if (IZLE) {
    p.bekleyen = new Set();
    p.on('request', r => p.bekleyen.add(r)); p.on('requestfinished', r => p.bekleyen.delete(r)); p.on('requestfailed', r => p.bekleyen.delete(r));
    p.on('crash', () => console.error('[tanı] SAYFA ÇÖKTÜ (crash) w=' + vp.width));
    p.on('close', () => console.error('[tanı] sayfa KAPANDI w=' + vp.width));
    p.on('framenavigated', f => { if (f === p.mainFrame()) console.error('[tanı] navigasyon → ' + f.url().replace(/^http:\/\/[^/]+/, '')); });
    p.on('requestfailed', r => console.error('[tanı] istek başarısız ' + r.method() + ' ' + r.url().replace(/^http:\/\/[^/]+/, '') + ' ' + (r.failure() || {}).errorText));
  }
  p.setDefaultTimeout(20000); p.srv = srv; p.url0 = srv.url;
  return p;
}
const git = (p, yol) => p.goto(p.url0 + '/' + yol, { waitUntil: 'load' });
const tasma = p => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const goster = (p, s) => p.$eval(s, e => !!(e.getClientRects().length) && getComputedStyle(e).visibility !== 'hidden').catch(() => false);
const metin = (p, s) => p.$eval(s, e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const gorunurSay = (p, s) => p.$$eval(s, l => l.filter(e => e.getClientRects().length).length);
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 6000 }); return true; } catch (e) { return false; } };
const postSay = p => p.srv.sayi(/^POST \/cek_kaydet/);
async function yenilenmeBekle(p, n0) {
  for (let i = 0; i < 80 && postSay(p) <= n0; i++) await bekle(100);
  await bekle(300);
  try { await p.waitForLoadState('load', { timeout: 15000 }); } catch (e) {
    const d = await Promise.race([p.evaluate(() => ({ rs: document.readyState, url: location.href })).catch(x => 'evaluate başarısız: ' + x.message), bekle(4000).then(() => 'evaluate yanıt vermedi')]);
    const bk = p.bekleyen ? [...p.bekleyen].map(r => r.method() + ' ' + r.url().replace(/^http:\/\/[^/]+/, '')) : [];
    ok('sayfa yenilendikten sonra "load" 15 sn içinde geldi', false, JSON.stringify({ sayfa: d, bekleyenIstekler: bk }));
    throw new Error('sayfa yüklenemedi (yenilenmeBekle)');   // sonraki adımlar anlamsız → koşu net hatayla biter
  }
  await kisaBekle(p, () => document.readyState === 'complete');
  await bekle(250);
}
async function modalAcik(p, id) { const r = await kisaBekle(p, i => { const m = document.getElementById(i); return m && !m.hidden && m.getBoundingClientRect().height > 50; }, id, 3000); await bekle(450); return r; }   // açılış animasyonu 220ms
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
const kucukHedef = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' a, ' + k + ' button, ' + k + ' select, ' + k + ' input:not([type=hidden])')]
  .filter(e => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]') && !e.closest('.pm-overlay[hidden]') && !e.closest('.cs-tablo') && !e.closest('.cs-sekmeler') && !e.closest('.cs-panel:not(.cs-panel--acik)') && !(e.type === 'checkbox' && e.closest('label, td')) && !e.closest('.cs-cipler, .cs-disa, .cs-yon-sekme, .cs-ozet'))
  .map(e => { const r = e.getBoundingClientRect(); return { s: e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || e.name || '').trim().slice(0, 14), h: Math.round(r.height), w: Math.round(r.width) }; })
  .filter(x => x.h < 44 || x.w < 44), kapsam);
const kucukYazi = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' input:not([type=hidden]):not([type=checkbox]), ' + k + ' select, ' + k + ' textarea')]
  .filter(e => e.getClientRects().length).filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.id || e.type), kapsam);
const tarihGun = (bugun, f) => new Date(Date.parse(bugun + 'T00:00:00Z') + f * 86400000).toISOString().slice(0, 10);
const trT = iso => iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
/** BAĞIMSIZ (test tarafı) ortalama vade: round-half-away(Σ(kuruş×gün)/Σkuruş) */
function ortBeklenen(satirlar, baz) {
  const b = Date.parse(baz + 'T00:00:00Z') / 86400000; let S = 0, T = 0;
  satirlar.forEach(s => { S += s.kurus * (Date.parse(s.vade + 'T00:00:00Z') / 86400000 - b); T += s.kurus; });
  const g = S >= 0 ? Math.floor((2 * S + T) / (2 * T)) : -Math.floor((-2 * S + T) / (2 * T));
  return { gun: g, tarih: tarihGun(baz, g) };
}
async function cariSec(p, formSel, cari) {
  await p.fill(formSel + ' [name="cari_ara"]', cari.kod + ' — ' + cari.unvan);
  await p.dispatchEvent(formSel + ' [name="cari_ara"]', 'change');
}
async function satirDoldur(p, i, v) {
  const tr = p.locator('.cs-fsatir').nth(i);
  for (const [ad, deger] of Object.entries(v)) {
    const el = tr.locator('[name="' + ad + '[]"]');
    if (ad === 'para_birimi' || ad === 'karne_id') await el.selectOption(deger); else await el.fill(deger);
  }
}
// cek_kaydet.php POST yanıtını YAKALAR — page.route (Fetch interception) KULLANMAZ.
// Eski sürüm `page.route` açık tutup, kayıttan hemen sonra başlayan yönlendirme (location.assign) sürerken `unroute` ediyordu. Route açıkken
// Chromium TÜM istekleri (yeni belgenin stil/js/görsel alt kaynakları dahil) Playwright'a durdurtur; unroute ile yeni belgenin yüklenmesi
// yarışınca bazı alt kaynak istekleri hiç sunucuya ulaşmıyor, `load` olayı gelmiyordu (yenilenmeBekle'de "waitForLoadState: Timeout";
// bazen sayfa/tarayıcı kapanıp süreç asılı kalıyordu; ~1/10 koşuda, hep gonderCevap'ın hemen ardından).
// Şimdi: sayfa içindeki fetch sarmalanır; yanıt klonu `exposeFunction` bağlamasıyla Node'a İLETİLDİKTEN SONRA uygulamaya verilir
// (navigasyon gövdeyi silmeden önce yakalama garantili; interception yok → yarış yok).
const SAR = () => {
  if (window.__csSar) return; window.__csSar = 1;
  const f = window.fetch;
  window.fetch = function (u, o) {
    const pr = f.apply(this, arguments);
    if (!(o && /^POST$/i.test(o.method || '') && /cek_kaydet\.php/.test(String((u && u.url) || u)))) return pr;
    return pr.then(r => r.clone().text().then(
      t => Promise.resolve().then(() => window.__csYakala(r.status, t)).then(() => r, () => r),
      () => r));
  };
};
async function gonderCevap(p, tikla) {
  if (!p.__yakalaKurulu) {
    p.__cap = null;
    await p.exposeFunction('__csYakala', (kod, govde) => { let j; try { j = JSON.parse(govde); } catch (e) { j = { _hata: govde }; } p.__cap = { kod, j }; });
    await p.addInitScript(SAR);   // sonraki belgeler (kayıttan sonra yönlendirme dahil)
    p.__yakalaKurulu = true;
  }
  await p.evaluate(SAR);          // geçerli belge
  p.__cap = null;
  await tikla();
  const t0 = Date.now();
  while (!p.__cap && Date.now() - t0 < 15000) await bekle(50);   // en çok 15 sn; gelmezse {kod:0} → çağıran adım BAŞARISIZ olur (sonsuz bekleme YOK)
  return p.__cap || { kod: 0, j: {} };
}

// ── Sağlamlık: hiçbir bekleme sonsuza dek sürmez ─────────────────────────────────────────────
let browser; const sunucular = [];
async function temizle() {   // tarayıcı + köprü sunucuları + geçici klasör; her adım zaman sınırlı (asılırsa atlanır)
  if (browser) await Promise.race([browser.close().catch(() => {}), bekle(10000)]);
  for (const s of sunucular) { try { await Promise.race([s.kapat(), bekle(3000)]); } catch (e) { /* kapanış */ } }
  try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* geçici */ }
}
const SURE_MS = +(process.env.CEKSENET_UI_SURE_MS || 240000);   // tüm koşu için üst sınır (normal koşu ~35-60 sn)
setTimeout(async () => {
  console.log('\n*** ZAMAN AŞIMI: koşu ' + SURE_MS / 1000 + ' sn içinde bitmedi (son adım: ' + n + '. — "' + sonAd + '") — tarayıcı/köprü kapatılıp çıkılıyor');
  await temizle(); process.exit(2);
}, SURE_MS).unref();
let kapaniyor = false;
const olumcul = async (e) => { if (kapaniyor) return; kapaniyor = true; console.log('\n*** BEKLENMEYEN HATA (süreç düzeyi): ' + (e && e.stack || e)); await temizle(); process.exit(1); };
process.on('unhandledRejection', olumcul); process.on('uncaughtException', olumcul);
process.on('SIGTERM', async () => { console.log('\n*** SIGTERM alındı — temizlenip çıkılıyor'); await temizle(); process.exit(143); });

(async () => {
  try {
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
    if (IZLE) browser.on('disconnected', () => console.error('[tanı] TARAYICI bağlantısı koptu'));

    // ───────────────────────────── 1. MASAÜSTÜ 1366x800
    console.log('\n=== 1. Çek Dekontu (1366x800) ===');
    const S1 = await ortam('fisler'); sunucular.push(S1);
    const D0 = S1.say();
    const bugun = await (async () => { const t = await sayfa(browser, S1, { width: 1366, height: 800 }); await git(t, 'cek_dekontu.php'); const b = await t.$eval('#ceksenet', e => e.getAttribute('data-bugun')); await t.context().close(); return b; })();
    const p = await sayfa(browser, S1, { width: 1366, height: 800 });
    await git(p, 'cek_dekontu.php');
    ok('sayfa açıldı: başlık Çek Dekontu, h1, yön sekmesi Alınan seçili', /Çek Dekontu/.test(await p.title()) && (await metin(p, '.cs-unvan')) === 'Çek Dekontu' && (await p.$eval('.cs-yon[aria-current="true"]', e => e.textContent.trim())) === 'Alınan');
    ok('sidebar görünür; topbar + bottomnav gizli', await goster(p, 'aside.desktop-sidebar') && !(await goster(p, '.bottomnav')));
    const tek = await p.evaluate(() => {
      const L = document.querySelector('.cs-sol').getBoundingClientRect(), D = document.getElementById('csPanel').getBoundingClientRect(), g = document.getElementById('csListe');
      return { sayfaKayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, yanyana: L.right <= D.left + 1 && Math.abs(L.top - D.top) < 4, listeW: Math.round(L.width), panelW: Math.round(D.width), alt: Math.round(L.bottom) <= innerHeight, listeKayar: getComputedStyle(g).overflowY };
    });
    ok('TEK EKRAN: sayfa kaydırılmaz, liste + sağ panel yan yana, ikisi de ekranda; liste kendi içinde kayar', tek.sayfaKayar <= 2 && tek.yanyana && tek.alt && tek.listeKayar === 'auto', JSON.stringify(tek));
    ok('panel 330–420px; liste ≥ 560px; yatay taşma yok', tek.panelW >= 330 && tek.panelW <= 420 && tek.listeW >= 560 && (await tasma(p)) <= 0, JSON.stringify(tek));
    ok('4 açık evrak (tablo görünür, mobil kartlar gizli); özet kartları TRY ve USD AYRI', (await gorunurSay(p, '.cs-tablo tbody tr')) === 4 && (await gorunurSay(p, '.cs-hk')) === 0 && /20\.750,50/.test(await metin(p, '[data-ozet="portfoy"]')) && /1\.200,00 \$/.test(await metin(p, '[data-ozet="portfoy"]')));
    ok('tablo tam görünür: Durum sütunu liste genişliği içinde (kesilmedi)', await p.evaluate(() => { const t = document.querySelector('.cs-tablo-kaydir'); return t.scrollWidth <= t.clientWidth + 1; }));
    ok('vadesi geçmiş satır KIRMIZI, ≤7 gün SARI (renk + metin)', await p.evaluate(() => { const a = document.querySelector('.cs-tablo .cs-vade--gecmis'), b = document.querySelector('.cs-tablo .cs-vade--yakin'); return !!a && !!b && getComputedStyle(a).color !== getComputedStyle(b).color && /gün geçti/.test(a.textContent) && /gün kaldı/.test(b.textContent); }));
    ok('mobil alt çubuk gizli; "Yeni giriş bordrosu" başlıkta', !(await goster(p, '#csEylemBar')) && await goster(p, '.cs-yeni'));
    await shot(p, 'pc_liste');
    // detay paneli
    const id = (seri) => D0.kayitlar.find(k => k.seri_no === seri).id;
    await p.click('.cs-tablo tbody tr[data-id="' + id('2000012') + '"] td.cs-evrak');
    ok('satıra tıklayınca sağ panel dolar (fetch ?parca=detay): portföy no, Halkbank, hareket geçmişi; satır işaretli; URL ?id=', await kisaBekle(p, () => { const d = document.getElementById('csDetay'); return d && /Halkbank/.test(d.textContent); }) && await goster(p, '#csDetay .cs-zaman') && /id=\d+/.test(p.url()) && (await p.$$eval('.cs-tablo tbody tr[aria-current="true"]', l => l.length)) === 1);
    ok('panel: izinli işlem düğmeleri portföy durumuna göre (Ciro Et, Tahsile Ver, Teminata Ver, Kasadan Tahsil, Karşılıksız, İade)', (await p.$$eval('#csDetay [data-cs-islem]', l => l.map(e => e.getAttribute('data-cs-islem')))).join() === 'ciro,tahsile_ver,teminata_ver,tahsil_kasa,karsiliksiz,iade');
    await shot(p, 'pc_liste_detay');
    // seçim + ortalama vade
    const secIds = [id('2000011'), id('2000012'), id('2000013')];
    for (const i of secIds) await p.check('.cs-tablo tbody tr[data-id="' + i + '"] [data-cs-sec]');
    ok('3 evrak seçilince seçim çubuğu: "3 seçili" + toplam (TRY) + Ort. vade; satırlar işaretli', await goster(p, '#csSecimBar') && (await metin(p, '[data-secim="adet"]')) === '3' && /50\.750,50 ₺/.test(await metin(p, '[data-secim="toplam"]')) && /Ort\. vade/.test(await metin(p, '[data-secim="ort"]')));
    const sv = S1.say(secIds).secim, secMetin = await metin(p, '[data-secim="ort"]');
    ok('seçim çubuğu ortalama vade = SUNUCU ceksenet_secim_ozeti (gün + tarih)', secMetin.includes(sv.ortalama_vade.TRY.gun + ' gün · ' + trT(sv.ortalama_vade.TRY.tarih)), secMetin + ' ← ' + JSON.stringify(sv.ortalama_vade));
    ok('toplu işlem menüsü: yalnız HEPSİNDE izinli işlemler (portföy + tahsilde → karşılıksız)', await (async () => { await p.click('[data-cs-toplu-ac]'); const t = await p.$$eval('#csTopluMenu [data-cs-toplu-islem]', l => l.map(e => e.getAttribute('data-cs-toplu-islem'))); return t.join() === 'karsiliksiz'; })());
    await shot(p, 'pc_secim');
    await p.click('[data-cs-sec-temizle]');
    ok('Temizle: çubuk gizlenir, onay kutuları boş', !(await goster(p, '#csSecimBar')) && (await p.$$eval('.cs-tablo [data-cs-sec]:checked', l => l.length)) === 0);
    // süzgeç
    await p.selectOption('select[name="durum"]', 'tumu');
    await p.waitForLoadState('load'); await bekle(300);
    ok('durum=tumu (otomatik gönder): 5 satır; ciro edilen dahil; URL ?durum=tumu', /durum=tumu/.test(p.url()) && (await gorunurSay(p, '.cs-tablo tbody tr')) === 5);
    await p.keyboard.press('/');
    ok('"/" kısayolu aramaya odaklar', await p.evaluate(() => document.activeElement && document.activeElement.id === 'csArama'));
    await git(p, 'cek_dekontu.php?yon=verilen');
    ok('Verilen: 1 satır (9.000,00 ₺ Verildi); özet Ödenecek; buton "Yeni çıkış bordrosu"', (await gorunurSay(p, '.cs-tablo tbody tr')) === 1 && /9\.000,00/.test(await metin(p, '[data-ozet="verildi"]')) && /Yeni çıkış bordrosu/.test(await metin(p, '.cs-yeni')));
    await git(p, 'senet_dekontu.php');
    ok('Senet Dekontu: 2 satır; sütun "Borçlu / senet no"; kefil görünür', (await gorunurSay(p, '.cs-tablo tbody tr')) === 2 && /Borçlu \/ senet no/.test(await metin(p, '.cs-tablo thead')) && /Kefil/.test(await metin(p, '.cs-tablo tbody')));
    await shot(p, 'pc_senet');
    await p.context().close();

    // ───────────────────────────── 2. UÇTAN UCA
    console.log('\n=== 2. Uçtan uca (1366): bordro → ciro → tahsile ver → bankadan tahsil → karşılıksız → iptal → verilen çek → ödendi → toplu → senet ===');
    const S2 = await ortam('ornek'); sunucular.push(S2);
    const E0 = S2.say();
    const C1 = E0.cariler.find(c => /TİCARET/.test(c.unvan)), C2 = E0.cariler.find(c => /TEDARİK/.test(c.unvan)), C3 = E0.cariler.find(c => /NAKLİYAT/.test(c.unvan));
    const BANKA = Object.keys(E0.kasalar).find(i => E0.kasalar[i].ad === 'Örnek Banka TL');
    const q = await sayfa(browser, S2, { width: 1366, height: 800 });
    await git(q, 'cek_dekontu.php');
    ok('kayıt yok: boş durum "Henüz çek kaydı yok" + Yeni giriş bordrosu', /Henüz çek kaydı yok/.test(await metin(q, '.cs-liste-govde')) && await goster(q, '.cs-yeni'));
    await shot(q, 'pc_bos');
    // 2a. bordro formu
    await q.click('.cs-yeni');
    await q.waitForLoadState('load');
    ok('"Yeni giriş bordrosu" → form sayfası (tek ekran), başlık, 1 boş satır, tablo görünümü (≥1100)', await goster(q, '#csBordroForm') && (await gorunurSay(q, '.cs-fsatir')) === 1 && await goster(q, '.cs-ftablo thead') && (await tasma(q)) <= 0);
    const fz = await q.evaluate(() => { const f = document.getElementById('csBordroForm').getBoundingClientRect(); return { sayfaKayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, altIci: f.bottom <= innerHeight + 1, kaydet: document.querySelector('#csBordroForm [data-kaydet]').getBoundingClientRect().bottom <= innerHeight }; });
    ok('form tek ekran: sayfa kaymaz, Kaydet düğmesi ekranda', fz.sayfaKayar <= 2 && fz.altIci && fz.kaydet, JSON.stringify(fz));
    await shot(q, 'pc_form');
    let n0 = postSay(q);
    await q.click('#csBordroForm [data-kaydet]');
    ok('boş form → istemci uyarısı (cari zorunlu), istek gitmez', await kisaBekle(q, () => !!document.querySelector('#csBordroForm .cs-hata')) && postSay(q) === n0);
    await cariSec(q, '#csBordroForm', C1);
    ok('cari seçilince gizli cari_id dolar + ipucu "Seçildi" + açık çek-senet özeti kutusu (parça)', (await q.inputValue('#csBordroForm [name="cari_id"]')) === String(C1.id) && /Seçildi/.test(await metin(q, '[data-cari-ipucu]')) && await kisaBekle(q, () => !!document.querySelector('[data-cari-ozet-kap] .cs-cari-ozet')));
    const gunler = [15, 45, 100], tutarlar = ['10.000,00', '20.000,50', '5.000,00'], bankalar = ['Ziraat Bankası', 'Halkbank', 'Akbank'];
    await satirDoldur(q, 0, { seri_no: '3000001', banka_ad: bankalar[0], sube: 'Merkez', hesap_no: '1111', borclu: 'ÖRNEK KEŞİDECİ A.Ş.', vade: tarihGun(bugun, gunler[0]), tutar: tutarlar[0] });
    await q.click('[data-satir-ekle]');
    await satirDoldur(q, 1, { seri_no: '3000002', banka_ad: bankalar[1], vade: tarihGun(bugun, gunler[1]), tutar: tutarlar[1] });
    await q.locator('.cs-fsatir').nth(1).locator('[name="tutar[]"]').press('Control+ArrowDown');   // Ctrl+↓ yeni satır
    await satirDoldur(q, 2, { seri_no: '3000003', banka_ad: bankalar[2], vade: tarihGun(bugun, gunler[2]), tutar: tutarlar[2] });
    ok('Ctrl+↓ ve "Satır ekle" ile 3 satır; satır numaraları 1-2-3', (await gorunurSay(q, '.cs-fsatir')) === 3 && (await q.$$eval('.cs-fs-no', l => l.filter(e => e.closest('tbody')).map(e => e.textContent)).then(a => a.join())) === '1,2,3');
    const beklenen = ortBeklenen([{ vade: tarihGun(bugun, 15), kurus: 1000000 }, { vade: tarihGun(bugun, 45), kurus: 2000050 }, { vade: tarihGun(bugun, 100), kurus: 500000 }], bugun);
    const onizleme = await metin(q, '[data-top-ort] b'), onTop = await metin(q, '[data-top-pb] b'), onAdet = await metin(q, '[data-top="adet"]');
    ok('canlı özet: adet 3, toplam 35.000,50 ₺, ortalama vade önizlemesi (bağımsız hesapla aynı)', onAdet === '3' && /35\.000,50 ₺/.test(onTop) && onizleme.includes(beklenen.gun + ' gün · ' + trT(beklenen.tarih)), onizleme + ' ← ' + JSON.stringify(beklenen));
    await q.fill('#csBordroForm [name="aciklama"]', 'Uçtan uca giriş bordrosu');
    await shot(q, 'pc_form_dolu');
    n0 = postSay(q);
    const sonuc = await gonderCevap(q, () => q.keyboard.press('Control+s'));
    ok('Ctrl+S ile kaydedildi: 200, ok, 3 id, bordro_no BRD-…, yonlendir cek_dekontu.php?yon=alinan', sonuc.kod === 200 && sonuc.j.ok && sonuc.j.idler.length === 3 && /^BRD-\d{4}-\d{6}$/.test(sonuc.j.bordro_no) && sonuc.j.yonlendir === 'cek_dekontu.php?yon=alinan', JSON.stringify(sonuc.j).slice(0, 300));
    ok('önizlemedeki ortalama vade = SUNUCU yanıtındaki ortalama_vade (gün + tarih)', sonuc.j.ortalama_vade.TRY.gun === beklenen.gun && sonuc.j.ortalama_vade.TRY.tarih === beklenen.tarih && onizleme.includes(sonuc.j.ortalama_vade.TRY.gun + ' gün · ' + trT(sonuc.j.ortalama_vade.TRY.tarih)), JSON.stringify(sonuc.j.ortalama_vade));
    await yenilenmeBekle(q, n0);
    const E1 = S2.say();
    ok('liste sayfasına döndü: 3 satır görünür, toast "Bordro kaydedildi · BRD-…"', /cek_dekontu\.php/.test(q.url()) && (await gorunurSay(q, '.cs-tablo tbody tr')) === 3 && /Bordro kaydedildi · 3 evrak · BRD-/.test(await metin(q, '.cs-toast:not([hidden])') || ''));
    const bagH = await q.$eval('.cs-toast:not([hidden]) a.cs-toast-bag', e => ({ href: e.getAttribute('href'), hedef: e.target, rel: e.rel, tik: getComputedStyle(e).pointerEvents })).catch(() => null);
    ok('toast\'taki bordro no bağlantı: cek_cikti.php?bordro=BRD-…, yeni sekme (_blank + noopener), tıklanabilir (pointer-events auto)', !!bagH && /^cek_cikti\.php\?bordro=BRD-\d{4}-\d{6}$/.test(bagH.href) && bagH.hedef === '_blank' && /noopener/.test(bagH.rel) && bagH.tik === 'auto', JSON.stringify(bagH));
    ok('DB: 3 kayıt (portföy), 3 giriş hareketi, ortak bordro_no, 3 cari dekontu ALACAK (kuruşu kuruşuna 10.000,00 / 20.000,50 / 5.000,00), ortalama sunucu = istemci',
      E1.kayit === 3 && E1.kayitlar.every(k => k.durum === 'portfoy' && k.cari_id === C1.id && k.bordro_no === sonuc.j.bordro_no) && E1.cari_hareketler.length === 3 && E1.cari_hareketler.every(c => c.yon === 'A' && c.kaynak_modul === 'ceksenet')
      && E1.cari_hareketler.map(c => kr(c.tutar)).join() === '1000000,2000050,500000' && E1.ortalama[sonuc.j.bordro_no].TRY.gun === beklenen.gun, JSON.stringify([E1.cari_hareketler.map(c => c.yon + c.tutar), E1.ortalama]));
    const K = s => E1.kayitlar.find(k => k.seri_no === s).id;
    const r1 = K('3000001'), r2 = K('3000002'), r3 = K('3000003');
    // 2b. ciro
    await q.click('.cs-tablo tbody tr[data-id="' + r1 + '"] td.cs-evrak');
    await kisaBekle(q, () => !!document.getElementById('csDetay'));
    await q.click('#csDetay [data-cs-islem="ciro"]');
    ok('Ciro Et → işlem modalı: cari alanı görünür + zorunlu, kasa alanı GİZLİ, etki metni çekirdekten', await modalAcik(q, 'csModalIslem') && await goster(q, '#csIslemForm [name="cari_ara"]') && !(await goster(q, '#csIslemForm [name="kasa_id"]')) && /ciro edilen cariye Borç dekontu/.test(await metin(q, '[data-islem-etki]')));
    await shot(q, 'pc_islem');
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    ok('cari seçilmeden gönder → istemci uyarısı (Zorunlu alan), istek gitmez', (await metin(q, '#csIslemForm .cs-hata')) === 'Zorunlu alan.' && postSay(q) === n0);
    await cariSec(q, '#csIslemForm', C3);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const ciroToast = await q.$eval('.cs-toast:not([hidden])', e => ({ m: e.textContent.replace(/\s+/g, ' ').trim(), a: (e.querySelector('a.cs-toast-bag') || {}).href || '', t: (e.querySelector('a.cs-toast-bag') || {}).target || '' })).catch(() => null);
    ok('işlem (Ciro Et) sonrası toast: "Ciro Et kaydedildi · BRD-…" + işlem bordrosuna yeni sekme bağlantısı (arayüzden işlem bordrosuna ulaşılır)', !!ciroToast && /^Ciro Et kaydedildi · BRD-\d{4}-\d{6}$/.test(ciroToast.m) && /cek_cikti\.php\?bordro=BRD-/.test(ciroToast.a) && ciroToast.t === '_blank', JSON.stringify(ciroToast));
    const E2 = S2.say();
    ok('DB: r1 durum=ciro, yer_cari=NAKLİYAT; cari dekontu BORÇ 10.000,00 NAKLİYAT; hareket ciro', E2.kayitlar.find(k => k.id === r1).durum === 'ciro' && E2.kayitlar.find(k => k.id === r1).yer_cari_id === C3.id && E2.cari_hareketler.some(c => c.cari_id === C3.id && c.yon === 'B' && kr(c.tutar) === 1000000) && E2.hareketler.some(h => h.kayit_id === r1 && h.islem === 'ciro'));
    // 2c. tahsile ver (banka kasası)
    await q.click('.cs-tablo tbody tr[data-id="' + r3 + '"] td.cs-evrak');
    await kisaBekle(q, () => { const d = document.getElementById('csDetay'); return d && /Akbank/.test(d.textContent); });
    await q.click('#csDetay [data-cs-islem="tahsile_ver"]');
    await modalAcik(q, 'csModalIslem');
    const kasaSecenek = await q.$$eval('#csIslemForm [name="kasa_id"] option', l => l.filter(o => o.value && !o.disabled && !o.hidden).map(o => o.textContent));
    ok('Tahsile Ver: kasa alanı görünür; seçenekler yalnız BANKA tipi + aynı para birimi (Örnek Banka TL) ve tek seçenek otomatik seçili', await goster(q, '#csIslemForm [name="kasa_id"]') && kasaSecenek.length === 1 && /Örnek Banka TL/.test(kasaSecenek[0]) && (await q.inputValue('#csIslemForm [name="kasa_id"]')) === BANKA, JSON.stringify(kasaSecenek));
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const E3 = S2.say();
    ok('DB: r3 durum=tahsilde, yer_kasa = banka; para hareketi YOK, ek cari dekontu YOK', E3.kayitlar.find(k => k.id === r3).durum === 'tahsilde' && String(E3.kayitlar.find(k => k.id === r3).yer_kasa_id) === BANKA && E3.kasa_hareketler.length === 0 && E3.cari_hareketler.length === 4);
    // 2d. bankadan tahsil
    await q.click('.cs-tablo tbody tr[data-id="' + r3 + '"] td.cs-evrak');
    await kisaBekle(q, () => { const d = document.getElementById('csDetay'); return d && /Tahsilde/.test(d.textContent); });
    await q.click('#csDetay [data-cs-islem="tahsil_banka"]');
    ok('Bankadan Tahsil: kasa alanı YOK (kayıttaki banka kullanılır); etki "Tahsilat bankasına para girişi"', await modalAcik(q, 'csModalIslem') && !(await goster(q, '#csIslemForm [name="kasa_id"]')) && /Tahsilat bankasına para girişi/.test(await metin(q, '[data-islem-etki]')));
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const E4 = S2.say();
    ok('DB: r3 durum=tahsil; kasa hareketi GİRİŞ 5.000,00 (kaynak ceksenet); BANKA BAKİYESİ = 5.000 (arttı)', E4.kayitlar.find(k => k.id === r3).durum === 'tahsil' && E4.kasa_hareketler.length === 1 && E4.kasa_hareketler[0].yon === 'G' && kr(E4.kasa_hareketler[0].tutar) === 500000 && E4.kasa_hareketler[0].kaynak_modul === 'ceksenet' && E4.kasalar[BANKA].bakiye === 500000, JSON.stringify([E4.kasa_hareketler, E4.kasalar[BANKA]]));
    // 2e. karşılıksız + son hareket iptali
    await q.click('.cs-tablo tbody tr[data-id="' + r2 + '"] td.cs-evrak');
    await kisaBekle(q, () => { const d = document.getElementById('csDetay'); return d && /Halkbank/.test(d.textContent); });
    await q.click('#csDetay [data-cs-islem="karsiliksiz"]');
    await modalAcik(q, 'csModalIslem');
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const E5 = S2.say();
    ok('Karşılıksız (portföyden): r2 durum=karsiliksiz; kayıt carisine BORÇ dekontu 20.000,50 (geri borç)', E5.kayitlar.find(k => k.id === r2).durum === 'karsiliksiz' && E5.cari_hareketler.some(c => c.cari_id === C1.id && c.yon === 'B' && kr(c.tutar) === 2000050 && c.durum === 'gecerli'));
    await git(q, 'cek_dekontu.php?durum=tumu');   // karşılıksız artık "açık" değil
    await q.click('.cs-tablo tbody tr[data-id="' + r2 + '"] td.cs-evrak');
    await kisaBekle(q, () => { const d = document.getElementById('csDetay'); return d && /Karşılıksız/.test(d.textContent); });
    ok('panelde SON hareket için iptal düğmesi tek; durum rozeti Karşılıksız (kırmızı sınıf cs-d-karsiliksiz)', (await q.$$eval('#csDetay [data-cs-iptal-ac]', l => l.length)) === 1 && (await q.$$eval('#csDetay .cs-d-karsiliksiz', l => l.length)) >= 1);
    await q.click('#csDetay [data-cs-iptal-ac]');
    ok('iptal modalı açılır; neden < 3 karakterken "İptal et" düğmesi devre dışı', await modalAcik(q, 'csModalIptal') && await q.$eval('#csModalIptal [data-iptal-onay]', e => e.disabled));
    await q.fill('#csModalIptal [name="neden"]', 'Hatalı işlem');
    n0 = postSay(q);
    await q.click('#csModalIptal [data-iptal-onay]');
    await yenilenmeBekle(q, n0);
    const E6 = S2.say();
    ok('DB: r2 tekrar PORTFÖY; karşılıksız hareketi iptal; BORÇ dekontu iptal (bakiye geri); neden kayıtlı', E6.kayitlar.find(k => k.id === r2).durum === 'portfoy' && E6.hareketler.some(h => h.kayit_id === r2 && h.islem === 'karsiliksiz' && h.durum === 'iptal' && h.iptal_neden === 'Hatalı işlem') && E6.cari_hareketler.filter(c => c.cari_id === C1.id && c.yon === 'B').every(c => c.durum === 'iptal'));
    // 2f. mükerrer
    await git(q, 'cek_dekontu.php?yeni=1&yon=alinan');
    await cariSec(q, '#csBordroForm', C1);
    await satirDoldur(q, 0, { seri_no: '3000001', banka_ad: 'Ziraat Bankası', hesap_no: '1111', vade: tarihGun(bugun, 20), tutar: '100,00' });
    const mk = await gonderCevap(q, () => q.click('#csBordroForm [data-kaydet]'));
    ok('mükerrer çek no → 409 mukerrer; satır hatası satırda gösterilir (kırmızı çerçeve + "zaten kayıtlı"); form açık kalır', mk.kod === 409 && mk.j.kod === 'mukerrer' && await kisaBekle(q, () => !!document.querySelector('.cs-fsatir--hata .cs-hata')) && /kayıtlı/.test(await metin(q, '.cs-fsatir--hata .cs-hata')));
    ok('mükerrerde DB değişmedi (3 kayıt)', S2.say().kayit === 3);
    // 2g. verilen çek (karne)
    await git(q, 'cek_dekontu.php?yon=verilen');
    await q.click('.cs-yeni');
    await q.waitForLoadState('load');
    await cariSec(q, '#csBordroForm', C2);
    const karneId = await q.$eval('[name="karne_id[]"] option:nth-child(2)', e => e.value);
    await q.locator('.cs-fsatir').nth(0).locator('[name="karne_id[]"]').selectOption(karneId);
    const satir0 = q.locator('.cs-fsatir').nth(0);
    ok('karne seçilince: sıradaki çek no ÖNERİLİR (1000001), banka alanı kartan dolar + salt okunur, PB karne PB\'si ve kilitli', (await satir0.locator('[name="seri_no[]"]').inputValue()) === '1000001' && (await satir0.locator('[name="banka_ad[]"]').inputValue()) === 'Örnek Banka' && await satir0.locator('[name="banka_ad[]"]').evaluate(e => e.readOnly) && (await satir0.locator('[name="para_birimi[]"]').inputValue()) === 'TRY' && /sıradaki 1000001/.test(await satir0.locator('.cs-fs-ipucu').textContent()));
    await satir0.locator('[name="vade[]"]').fill(tarihGun(bugun, 20));
    await satir0.locator('[name="tutar[]"]').fill('9.000,00');
    await shot(q, 'pc_form_verilen');
    const vs = await gonderCevap(q, () => q.click('#csBordroForm [data-kaydet]'));
    ok('verilen çek bordrosu kaydedildi (cikis); yonlendir cek_dekontu.php?yon=verilen', vs.kod === 200 && vs.j.ok && vs.j.yonlendir === 'cek_dekontu.php?yon=verilen', JSON.stringify(vs.j).slice(0, 200));
    n0 = postSay(q) - 1; await yenilenmeBekle(q, n0);
    const E7 = S2.say();
    const rv = E7.kayitlar.find(k => k.yon === 'verilen');
    ok('DB: verilen çek durum=verildi, banka_ad Örnek Banka, karne_id dolu; cari TEDARİK BORÇ dekontu 9.000,00', rv && rv.durum === 'verildi' && rv.banka_ad === 'Örnek Banka' && rv.karne_id > 0 && E7.cari_hareketler.some(c => c.cari_id === C2.id && c.yon === 'B' && kr(c.tutar) === 900000));
    // 2h. ödendi → yetersiz bakiye
    await q.click('.cs-tablo tbody tr[data-id="' + rv.id + '"] td.cs-evrak');
    await kisaBekle(q, () => !!document.getElementById('csDetay'));
    await q.click('#csDetay [data-cs-islem="odendi"]');
    await modalAcik(q, 'csModalIslem');
    ok('Ödendi: kasa alanı görünür + KARNENİN BANKA HESABI varsayılan seçili; tüm TRY kasalar listelenir', await goster(q, '#csIslemForm [name="kasa_id"]') && (await q.inputValue('#csIslemForm [name="kasa_id"]')) === BANKA && (await q.$$eval('#csIslemForm [name="kasa_id"] option', l => l.filter(o => o.value && !o.disabled).length)) === 2);
    n0 = postSay(q);
    const yb = await gonderCevap(q, () => q.click('#csIslemForm button[type="submit"]'));
    ok('yetersiz bakiye → 409 yetersiz_bakiye (bakiye 500000, eksik 400000); #csEksik görünür: Mevcut 5.000,00 / İstenen 9.000,00 / Eksik 4.000,00', yb.kod === 409 && yb.j.kod === 'yetersiz_bakiye' && yb.j.bakiye === 500000 && yb.j.eksik === 400000 && await goster(q, '#csEksik') && /5\.000,00/.test(await metin(q, '[data-eksik="bakiye"]')) && /9\.000,00/.test(await metin(q, '[data-eksik="istenen"]')) && /4\.000,00/.test(await metin(q, '[data-eksik="eksik"]')), JSON.stringify(yb.j).slice(0, 200));
    ok('yetersiz bakiyede DB değişmedi (durum verildi, kasa hareketi 1)', S2.say().kayitlar.find(k => k.id === rv.id).durum === 'verildi' && S2.say().kasa_hareketler.length === 1);
    await shot(q, 'pc_eksik');
    n0 = postSay(q);
    await q.click('#csIslemForm [data-eksik-kaydet]');
    await yenilenmeBekle(q, n0);
    const E8 = S2.say();
    ok('"Yine de kaydet" (aynı istek anahtarı + eksiye_izin): durum=odendi; kasa ÇIKIŞ 9.000,00; BANKA BAKİYESİ = −4.000,00', E8.kayitlar.find(k => k.id === rv.id).durum === 'odendi' && E8.kasa_hareketler.some(k => k.yon === 'C' && kr(k.tutar) === 900000 && k.kaynak_modul === 'ceksenet') && E8.kasalar[BANKA].bakiye === -400000, JSON.stringify(E8.kasalar[BANKA]));
    // 2i. toplu seçim + ortalama vade (ikinci bordro + r2)
    await git(q, 'cek_dekontu.php?yeni=1&yon=alinan');
    await cariSec(q, '#csBordroForm', C1);
    await satirDoldur(q, 0, { seri_no: '4000001', banka_ad: 'Garanti BBVA', hesap_no: '2222', vade: tarihGun(bugun, 10), tutar: '4.000,00' });
    await q.click('[data-satir-ekle]');
    await satirDoldur(q, 1, { seri_no: '4000002', banka_ad: 'Garanti BBVA', hesap_no: '2222', vade: tarihGun(bugun, 30), tutar: '6.000,00' });
    n0 = postSay(q);
    await q.click('#csBordroForm [data-kaydet]');
    await yenilenmeBekle(q, n0);
    const E9 = S2.say();
    const t1 = E9.kayitlar.find(k => k.seri_no === '4000001').id, t2 = E9.kayitlar.find(k => k.seri_no === '4000002').id;
    await git(q, 'cek_dekontu.php');
    ok('liste: açık evraklar = r2 (portföy) + 2 yeni (r1 ciro, r3 tahsil listede değil) → 3 satır', (await gorunurSay(q, '.cs-tablo tbody tr')) === 3);
    await q.check('.cs-tablo thead [data-cs-hepsi]');
    ok('"Tümünü seç" (başlık onay kutusu) → 3 seçili', (await metin(q, '[data-secim="adet"]')) === '3');
    const sv2 = S2.say([r2, t1, t2]).secim;
    const m2 = await metin(q, '[data-secim="ort"]');
    ok('toplu seçim ortalama vadesi = sunucu ceksenet_secim_ozeti; toplam 30.000,50 ₺ (20.000,50 + 4.000 + 6.000)', m2.includes(sv2.ortalama_vade.TRY.gun + ' gün · ' + trT(sv2.ortalama_vade.TRY.tarih)) && /30\.000,50 ₺/.test(await metin(q, '[data-secim="toplam"]')), m2 + ' ← ' + JSON.stringify(sv2.ortalama_vade));
    await q.click('[data-cs-toplu-ac]');
    ok('toplu menü (hepsi portföy): ciro · tahsile_ver · teminata_ver · tahsil_kasa · karsiliksiz · iade', (await q.$$eval('#csTopluMenu [data-cs-toplu-islem]', l => l.map(e => e.getAttribute('data-cs-toplu-islem')))).join() === 'ciro,tahsile_ver,teminata_ver,tahsil_kasa,karsiliksiz,iade');
    await q.click('#csTopluMenu [data-cs-toplu-islem="teminata_ver"]');
    ok('Teminata Ver (toplu): modal başlığı "Teminata Ver — 3 evrak"; kasa isteğe bağlı (banka tipi)', await modalAcik(q, 'csModalIslem') && /Teminata Ver — 3 evrak/.test(await metin(q, '#csIslemBaslik')) && /isteğe bağlı/.test(await metin(q, '[data-kasa-etiket]')));
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const E10 = S2.say();
    ok('DB: 3 evrak TEMİNATTA (tek işlemde, ortak bordro_no, 3 hareket)', [r2, t1, t2].every(i => E10.kayitlar.find(k => k.id === i).durum === 'teminatta') && E10.hareketler.filter(h => h.islem === 'teminata_ver').length === 3 && new Set(E10.hareketler.filter(h => h.islem === 'teminata_ver').map(h => h.bordro_no)).size === 1);
    ok('toplu işlem sonrası liste: durum rozetleri Teminatta, çubuk gizli', (await q.$$eval('.cs-tablo .cs-d-teminatta', l => l.length)) === 3 && !(await goster(q, '#csSecimBar')));
    // 2j. senet: giriş + protesto
    await git(q, 'senet_dekontu.php?yeni=1&yon=alinan');
    ok('senet formu: Borçlu · Kefil · Ödeme yeri · Düzenleme sütunları; banka sütunu YOK', await goster(q, '.cs-ftablo th.cs-fs-kefil') && await goster(q, '.cs-ftablo th.cs-fs-yer-adi') && !(await goster(q, '.cs-ftablo th.cs-fs-banka-ad')));
    await shot(q, 'pc_form_senet');
    await cariSec(q, '#csBordroForm', C1);
    await satirDoldur(q, 0, { seri_no: 'SN-77', borclu: 'ÖRNEK BORÇLU LTD.', kefil: 'ÖRNEK KEFİL', yer_adi: 'ANKARA', duzenleme_tarihi: tarihGun(bugun, -5), vade: tarihGun(bugun, 60), tutar: '12.000,00' });
    const sn = await gonderCevap(q, () => q.click('#csBordroForm [data-kaydet]'));
    ok('senet bordrosu kaydedildi: yonlendir senet_dekontu.php?yon=alinan; ortalama vade 60 gün', sn.kod === 200 && sn.j.ok && sn.j.yonlendir === 'senet_dekontu.php?yon=alinan' && sn.j.ortalama_vade.TRY.gun === 60, JSON.stringify(sn.j).slice(0, 200));
    n0 = postSay(q) - 1; await yenilenmeBekle(q, n0);
    const sid = S2.say().kayitlar.find(k => k.seri_no === 'SN-77').id;
    await q.click('.cs-tablo tbody tr[data-id="' + sid + '"] td.cs-evrak');
    await kisaBekle(q, () => !!document.getElementById('csDetay'));
    ok('senet panelinde düğme "Protesto" (Karşılıksız değil)', (await q.$$eval('#csDetay [data-cs-islem]', l => l.map(e => e.textContent.trim()))).includes('Protesto') && !(await q.$$eval('#csDetay [data-cs-islem]', l => l.map(e => e.textContent.trim()))).includes('Karşılıksız'));
    await q.click('#csDetay [data-cs-islem="karsiliksiz"]');
    ok('Protesto modalı başlığı "Protesto — 1 evrak"', await modalAcik(q, 'csModalIslem') && /Protesto — 1 evrak/.test(await metin(q, '#csIslemBaslik')));
    n0 = postSay(q);
    await q.click('#csIslemForm button[type="submit"]');
    await yenilenmeBekle(q, n0);
    const E11 = S2.say();
    await git(q, 'senet_dekontu.php?durum=tumu');   // protesto artık "açık" değil
    ok('DB: senet durum=karsiliksiz; kayıt carisine BORÇ dekontu 12.000,00; liste rozeti "Protesto"', E11.kayitlar.find(k => k.id === sid).durum === 'karsiliksiz' && E11.cari_hareketler.some(c => c.cari_id === C1.id && c.yon === 'B' && kr(c.tutar) === 1200000 && c.durum === 'gecerli') && /Protesto/.test(await metin(q, '.cs-tablo tbody tr[data-id="' + sid + '"] .cs-rozet') || ''));
    ok('bu akış boyunca sayfa/konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
    ok('audit: her yazma audit_log (module=ceksenet) — ≥ 12 kayıt', S2.say().audit >= 12, String(S2.say().audit));
    await q.context().close();

    // ───────────────────────────── 3. TANIMLAR
    console.log('\n=== 3. Tanımlar (1366) ===');
    const S3 = await ortam('fisler'); sunucular.push(S3);
    const T = await sayfa(browser, S3, { width: 1366, height: 800 });
    await git(T, 'cek_tanimlari.php');
    ok('banka sekmesi: 10 tohum banka görünür; karne paneli gizli; taşma yok', (await gorunurSay(T, '#csPanelBanka .cs-tanim-satir')) === 10 && !(await goster(T, '#csPanelKarne')) && (await tasma(T)) <= 0);
    await T.click('.cs-tanim-nav-oge[data-sekme="karne"]');
    ok('Çek Karneleri sekmesi: Karne A, kullanılan 1 / kalan 24 / sıradaki; URL ?sekme=karne', await goster(T, '#csPanelKarne') && /Kullanılan 1 · Kalan 24/.test(await metin(T, '#csPanelKarne')) && /sekme=karne/.test(T.url()));
    await shot(T, 'tanimlar_pc');
    await T.click('#csPanelKarne [data-cs-tanim-ac="karne"]:not([data-tanim])');
    ok('Yeni karne modalı: banka hesabı + ilk no + yaprak alanları görünür, şube gizli', await modalAcik(T, 'csModalTanim') && await goster(T, '#csModalTanim [name="kasa_id"]') && await goster(T, '#csModalTanim [name="ilk_no"]') && !(await goster(T, '#csModalTanim [name="sube"]')));
    await T.selectOption('#csModalTanim [name="kasa_id"]', { index: 1 });
    await T.fill('#csModalTanim [name="ad"]', 'Karne B'); await T.fill('#csModalTanim [name="ilk_no"]', '2000001'); await T.fill('#csModalTanim [name="yaprak"]', '10');
    let nT = postSay(T);
    await T.click('#csModalTanim button[type="submit"]');
    await yenilenmeBekle(T, nT);
    ok('karne eklendi (DB 2 karne; "Karne B" listede, sıradaki 2000001)', S3.say().tanim.karne === 2 && /Karne B/.test(await metin(T, '#csPanelKarne')) && /Sıradaki: 2000001/.test(await metin(T, '#csPanelKarne')));
    await git(T, 'cek_tanimlari.php');
    await T.click('#csPanelBanka .cs-tanim-satir:last-child [data-cs-tanim-ac="banka"]');
    await modalAcik(T, 'csModalTanim');
    ok('banka düzenle: ad dolu, şube alanı görünür', (await T.inputValue('#csModalTanim [name="ad"]')) === 'TEB' && await goster(T, '#csModalTanim [name="sube"]') && !(await goster(T, '#csModalTanim [name="ilk_no"]')));
    await T.fill('#csModalTanim [name="sube"]', 'Kadıköy');
    nT = postSay(T);
    await T.click('#csModalTanim button[type="submit"]');
    await yenilenmeBekle(T, nT);
    ok('banka şubesi güncellendi (DB)', S3.say().tanimlar.filter(t => t.tur === 'banka' && t.ad === 'TEB').length === 1 && /Kadıköy/.test(await metin(T, '#csPanelBanka')));
    nT = postSay(T);
    await T.uncheck('#csPanelBanka .cs-tanim-satir:last-child input[role="switch"]');
    await yenilenmeBekle(T, nT);
    ok('banka pasifleştirildi (aktif anahtarı otomatik gönderir): DB aktif=0, "Pasif" rozeti', S3.say().tanimlar.find(t => t.ad === 'TEB').aktif === 0 && /Pasif/.test(await metin(T, '#csPanelBanka .cs-tanim-satir:last-child')));
    await T.context().close();

    // ───────────────────────────── 4. TABLET 820
    console.log('\n=== 4. Tablet (820x1000) ===');
    const S4 = await ortam('fisler'); sunucular.push(S4);
    const t = await sayfa(browser, S4, { width: 820, height: 1000 });
    await git(t, 'cek_dekontu.php');
    ok('yatay taşma yok; topbar görünür, sidebar gizli; tablo görünür; kartlar + alt çubuk gizli; panel gizli', (await tasma(t)) <= 0 && await goster(t, '.topbar') && !(await goster(t, 'aside.desktop-sidebar')) && (await gorunurSay(t, '.cs-tablo tbody tr')) === 4 && !(await goster(t, '.cs-hk')) && !(await goster(t, '#csEylemBar')) && !(await goster(t, '#csPanel')));
    await shot(t, 'tablet_liste');
    await t.click('.cs-tablo tbody tr:first-child td.cs-evrak');
    ok('tablet: satıra tıklayınca detay ÇEKMECE olarak açılır (z ≥ 600, sağda, ekran içinde), kapat düğmesi erişilebilir', await kisaBekle(t, () => { const d = document.getElementById('csDetay'); return d && d.getClientRects().length > 0; }) && await t.$eval('#csPanel', e => { const r = e.getBoundingClientRect(); return +getComputedStyle(e).zIndex >= 600 && r.right <= innerWidth + 1 && r.top >= 0 && r.bottom <= innerHeight + 1 && r.width <= 450; }) && await goster(t, '.cs-panel-kapat'));
    await shot(t, 'tablet_detay');
    await t.click('.cs-panel-kapat');
    ok('çekmece kapanır (panel gizli, URL ?id temiz)', !(await goster(t, '#csPanel')) && !/id=\d/.test(t.url()));
    await git(t, 'cek_dekontu.php?yeni=1&yon=alinan');
    ok('tablet form: satır = kart (tablo başlığı gizli), taşma yok', !(await goster(t, '.cs-ftablo thead')) && (await tasma(t)) <= 0 && (await gorunurSay(t, '.cs-fsatir')) === 1);
    await shot(t, 'tablet_form');
    await t.context().close();

    // ───────────────────────────── 5. MOBİL 390
    console.log('\n=== 5. Mobil (390x844) ===');
    const m = await sayfa(browser, S4, { width: 390, height: 844 }, { dokunmatik: true });
    await git(m, 'cek_dekontu.php');
    ok('yatay taşma yok; bottomnav görünür; sidebar + tablo gizli; evrak KARTLARI görünür (4)', (await tasma(m)) <= 0 && await goster(m, '.bottomnav') && !(await goster(m, 'aside.desktop-sidebar')) && !(await goster(m, '.cs-tablo')) && (await gorunurSay(m, '.cs-hk')) === 4);
    ok('özet kartları yatay kaydırılır (sayfa taşmaz); vadesi geçmiş kart kırmızı kenarlı', (await gorunurSay(m, '.cs-ozet-kart')) >= 3 && await m.$eval('.cs-ozet', e => e.scrollWidth > e.clientWidth));
    const altBar = await m.evaluate(() => { const b = document.getElementById('csEylemBar').getBoundingClientRect(), n = document.querySelector('.bottomnav').getBoundingClientRect(); return { barAlt: Math.round(b.bottom), navUst: Math.round(n.top), gorunur: b.bottom <= n.top + 1 && b.top >= 0 }; });
    ok('alt eylem çubuğu (Giriş bordrosu) görünür, bottomnav\'ın ÜSTÜNDE (--bn-h)', altBar.gorunur, JSON.stringify(altBar));
    ok('mobil ≥44px dokunma hedefleri (liste kapsamı)', (await kucukHedef(m, '#ceksenet')).length === 0, JSON.stringify((await kucukHedef(m, '#ceksenet')).slice(0, 8)));
    ok('mobil girdiler ≥ 16px', (await kucukYazi(m, '#ceksenet')).length === 0, JSON.stringify(await kucukYazi(m, '#ceksenet')));
    ok('kart onay kutusu dokunma alanı ≥ 44×44 (etiket)', await m.$eval('.cs-hk-sec', e => { const r = e.getBoundingClientRect(); return r.width >= 44 && r.height >= 44; }));
    await shot(m, 'mobil_liste');
    await m.tap('.cs-hk:nth-child(1) .cs-hk-sec');
    await m.tap('.cs-hk:nth-child(2) .cs-hk-sec');
    const sb = await m.evaluate(() => { const b = document.getElementById('csSecimBar').getBoundingClientRect(), n = document.querySelector('.bottomnav').getBoundingClientRect(); return { gorunur: b.bottom <= n.top + 1 && b.top >= 0, altEylemGizli: !document.getElementById('csEylemBar').getClientRects().length }; });
    ok('2 kart seçilince SEÇİM çubuğu: adet + toplam + ort. vade, bottomnav\'ın ÜSTÜNDE; alt eylem çubuğunun yerini alır', (await metin(m, '[data-secim="adet"]')) === '2' && /Ort\. vade/.test(await metin(m, '[data-secim="ort"]')) && sb.gorunur && sb.altEylemGizli, JSON.stringify(sb));
    ok('seçim çubuğu düğmeleri ≥44px', (await kucukHedef(m, '#csSecimBar')).length === 0, JSON.stringify(await kucukHedef(m, '#csSecimBar')));
    await shot(m, 'mobil_secim');
    await m.tap('[data-cs-toplu-ac]');
    ok('toplu menü çubuğun üstünde, ekran içinde, düğmeler ≥44px', await goster(m, '#csTopluMenu') && await m.$eval('#csTopluMenu', e => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= document.getElementById('csSecimBar').getBoundingClientRect().top + 1; }) && (await kucukHedef(m, '#csTopluMenu')).length === 0);
    await m.tap('[data-cs-sec-temizle]');
    await m.tap('.cs-hk:nth-child(1) .cs-hk-baglanti');
    ok('kart dokununca detay TAM EKRAN sayfa (z ≥ 600), içerik kaydırılır, kapat düğmesi ≥44px', await kisaBekle(m, () => { const d = document.getElementById('csDetay'); return d && d.getClientRects().length > 0; }) && await m.$eval('#csPanel', e => { const r = e.getBoundingClientRect(); return +getComputedStyle(e).zIndex >= 600 && r.width >= innerWidth - 1 && r.height >= innerHeight - 1; }) && await m.$eval('#csPanelGovde', e => /auto|scroll/.test(getComputedStyle(e).overflowY)) && (await kucukHedef(m, '#csPanel')).length === 0, JSON.stringify(await kucukHedef(m, '#csPanel')));
    await shot(m, 'mobil_detay');
    await m.tap('#csDetay [data-cs-islem="tahsile_ver"]');
    ok('mobil işlem modalı: z ≥ 600, panelin ÜSTÜNDE (görünür), tam yükseklik', await modalAcik(m, 'csModalIslem') && await m.evaluate(() => { const o = document.getElementById('csModalIslem'), r = o.querySelector('.pm-dialog').getBoundingClientRect(), mid = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return +getComputedStyle(o).zIndex >= 600 && o.contains(mid); }));
    const gv = await m.evaluate(() => {
      const b = document.querySelector('#csModalIslem .pm-body'), f = document.querySelector('#csModalIslem .pm-footer'), d = document.querySelector('#csModalIslem .pm-dialog');
      const fr = f.getBoundingClientRect(), dr = d.getBoundingClientRect();
      return { ovf: getComputedStyle(b).overflowY, footerIci: fr.bottom <= innerHeight + 1 && fr.top >= 0, dialogIci: dr.bottom <= innerHeight + 1 && dr.top >= 0, tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    ok('mobil modal: gövde kaydırılır (overflow auto), footer (Vazgeç / Kaydet) ekran içinde, yatay taşma yok', /auto|scroll/.test(gv.ovf) && gv.footerIci && gv.dialogIci && gv.tasma <= 0, JSON.stringify(gv));
    ok('mobil işlem modalı: girdiler ≥16px, hedefler ≥44px', (await kucukYazi(m, '#csModalIslem')).length === 0 && (await kucukHedef(m, '#csModalIslem')).length === 0, JSON.stringify([await kucukYazi(m, '#csModalIslem'), await kucukHedef(m, '#csModalIslem')]));
    await shot(m, 'mobil_islem');
    await m.evaluate(() => { const b = document.querySelector('#csModalIslem .pm-body'); b.scrollTop = b.scrollHeight; });
    await bekle(200);
    const son = await m.evaluate(() => { const el = document.querySelector('#csIslemForm [name="aciklama"]'), r = el.getBoundingClientRect(), f = document.querySelector('#csModalIslem .pm-footer').getBoundingClientRect(); const t = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { gorunur: r.top >= 0 && r.bottom <= f.top + 1, tiklanir: t === el }; });
    ok('en alttaki alan (Açıklama) kaydırınca görünür, footer tarafından örtülmez ve tıklanabilir', son.gorunur && son.tiklanir, JSON.stringify(son));
    await m.keyboard.press('Escape');
    await git(m, 'cek_dekontu.php?yeni=1&yon=alinan');
    ok('mobil bordro formu: satır = KART (tablo başlığı gizli), taşma yok; alt çubuk (adet / toplam / Ort. vade + Kaydet) bottomnav üstünde', !(await goster(m, '.cs-ftablo thead')) && (await tasma(m)) <= 0 && await m.evaluate(() => { const b = document.querySelector('.cs-form-bar').getBoundingClientRect(), n = document.querySelector('.bottomnav').getBoundingClientRect(); return b.bottom <= n.top + 1 && b.top >= 0; }));
    ok('mobil form: girdiler ≥16px, hedefler ≥44px', (await kucukYazi(m, '#csBordroForm')).length === 0 && (await kucukHedef(m, '#csBordroForm')).length === 0, JSON.stringify([await kucukYazi(m, '#csBordroForm'), (await kucukHedef(m, '#csBordroForm')).slice(0, 6)]));
    await m.tap('[data-satir-ekle]');
    ok('mobil "Satır ekle" düğmesi ≥44px; ikinci satır kartı eklenir; taşma yok', (await gorunurSay(m, '.cs-fsatir')) === 2 && (await tasma(m)) <= 0);
    await shot(m, 'mobil_form');
    await git(m, 'cek_tanimlari.php?sekme=karne');
    ok('mobil tanımlar: taşma yok, düğmeler ≥44px', (await tasma(m)) <= 0 && (await kucukHedef(m, '.cs-tanim')).length === 0, JSON.stringify(await kucukHedef(m, '.cs-tanim')));
    await shot(m, 'mobil_tanimlar');
    await m.context().close();

    // ───────────────────────────── 6. ŞEMA YOK / KASASIZ
    console.log('\n=== 6. Şema yok / kasa yok / yönetici değil ===');
    const S6 = await ortam('sema'); sunucular.push(S6);
    const e1 = await sayfa(browser, S6, { width: 1366, height: 800 });
    await git(e1, 'cek_dekontu.php');
    ok('şema yok: uyarı kartı + migrate.php#cari / #kasa / #ceksenet bağlantıları; sekme şeridi yok; taşma yok', await goster(e1, '[data-cs-sema]') && await goster(e1, 'a[href="migrate.php#cari"]') && await goster(e1, 'a[href="migrate.php#kasa"]') && await goster(e1, 'a[href="migrate.php#ceksenet"]') && !(await goster(e1, '.cs-sekmeler')) && (await tasma(e1)) <= 0);
    await shot(e1, 'sema_yok');
    await e1.context().close();
    const S7 = await ortam('kasasiz'); sunucular.push(S7);
    const e2 = await sayfa(browser, S7, { width: 390, height: 844 }, { dokunmatik: true });
    await git(e2, 'cek_dekontu.php');
    ok('banka kasası yok → yönlendirme: "Kasa Tanımları\'ndan banka hesabı ekleyin" bağlantısı (mobil, taşma yok)', await goster(e2, '.cs-kasa-not a[href*="kasa_tanimlari.php?sekme=kasa"]') && (await tasma(e2)) <= 0);
    await shot(e2, 'kasasiz_mobil');
    await e2.click('.cs-kasa-not a');
    await e2.waitForLoadState('load');
    await git(e2, 'cek_tanimlari.php?sekme=karne');
    ok('kasasız Karne sekmesi: "Önce Kasa Tanımları\'ndan banka hesabı ekleyin"; Yeni karne düğmesi devre dışı', /Önce Kasa Tanımları/.test(await metin(e2, '#csPanelKarne')) && await e2.$eval('#csPanelKarne [data-cs-tanim-ac="karne"]', e => e.disabled));
    await e2.context().close();


    // ───────────────────────────── 7. v528 DENETİM DÜZELTMELERİ
    console.log('\n=== 7. v528 denetim düzeltmeleri: tutar önizlemesi (JS = PHP) · toast · aktif/pasif anahtarı ===');
    // 7a. JS ↔ PHP tutar eşitliği (assets/ceksenet.js `tutarCoz` kaynaktan çıkarılıp çalıştırılır; sunucu = kasa_tutar_coz)
    {
      const src = fs.readFileSync(path.join(ROOT, 'assets/ceksenet.js'), 'utf8');
      const a0 = src.indexOf('function tutarCoz(ham)'), b0 = src.indexOf('function kurusCoz');
      const tutarCoz = new Function(src.slice(a0, b0) + '; return tutarCoz;')();
      const girdiler = ['1.234', '12.345', '1.5', '1,5', '1.234,5', '1,234.56', '1.234.567', '0,005', '1 234,56', '-5', '1e3', '1.234,56', '100', '0,01', '1.00', '10.50', '1.000.000,00', '1,2,3', '12,345', '1.23', '123.456,789', ' 5 ', '٥', '1.2345',
        '1,500', '999,999', '0,500', '1.500', '1.500,00', '1500', '1,50', '100,000', '1,000,000', '-1,500', '', ' ', 'abc', '0', '0,00', '1.500 TL', '₺1.500,00', '2.50 tl', '1,15', '19,99', '4,35', '0,07', '0,015', '999.999.999.999,99', '1.000.000.000.000,00', '1.234,5.6', ',5', '5,', '.5', '1.5.5', '12,34,567', '1 500,25', '7,000', '10,000'];
      const php = JSON.parse(execFileSync('php', [path.join(__dirname, 'kasa_ui_e2e_tutar.php'), path.join(TMP, 'tutar.sqlite'), JSON.stringify(girdiler)], { cwd: ROOT, timeout: 60000 }).toString());
      const farklar = girdiler.filter(g => { const j = tutarCoz(g), q = php[g]; return j.k !== q.k || j.belirsiz !== q.belirsiz || j.mesaj !== q.mesaj; }).map(g => JSON.stringify(g) + ' JS=' + JSON.stringify(tutarCoz(g)) + ' PHP=' + JSON.stringify(php[g]));
      ok('tutar çözümü: JS tutarCoz ile PHP kasa_tutar_coz ' + girdiler.length + ' girdide BİREBİR aynı (kuruş + belirsiz + mesaj)', farklar.length === 0, farklar.join('\n    → '));
      ok('belirsiz kalıp iki tarafta da "belirsiz": 1,500 · 12,345 · 100,000; "0,500" belirsiz değil (50 kuruş); "1,234.56" = 123456', tutarCoz('1,500').belirsiz && tutarCoz('12,345').belirsiz && tutarCoz('100,000').belirsiz && !tutarCoz('0,500').belirsiz && tutarCoz('0,500').k === 50 && tutarCoz('1,234.56').k === 123456 && php['1,500'].belirsiz && php['0,500'].k === 50);
    }
    // 7b. bordro formu: tutar önizlemesi (PC tablo satırı + mobil kart), belirsiz tutar, toplam = kaydedilecek toplam
    const S10 = await ortam('fisler'); sunucular.push(S10);
    const F0 = S10.say();
    const FC1 = F0.cariler.find(c => /TİCARET/.test(c.unvan));
    for (const [ad, vp, dok] of [['PC', { width: 1366, height: 800 }, false], ['mobil', { width: 390, height: 844 }, true]]) {
      const pb = await sayfa(browser, S10, vp, { dokunmatik: dok });
      await git(pb, 'cek_dekontu.php?yeni=1&yon=alinan');
      await cariSec(pb, '#csBordroForm', FC1);
      await satirDoldur(pb, 0, { seri_no: 'V528-1', banka_ad: 'Akbank', hesap_no: '77', vade: tarihGun(bugun, 30), tutar: '1,500' });
      const tx = '.cs-fsatir:nth-child(1) [data-tutar-onizleme]';
      ok(ad + ': tutar "1,500" → alanın altında BELİRSİZ hata metni (kırmızı), alan aria-invalid; toplam bu satırı saymaz (—)', /Tutar belirsiz: 1\.500 için nokta kullanın \(1\.500\), bir buçuk için 1,50 yazın\./.test(await metin(pb, tx) || '') && await goster(pb, tx)
        && (await pb.getAttribute('.cs-fsatir [name="tutar[]"]', 'aria-invalid')) === 'true' && (await metin(pb, '[data-top-pb] b')) === '—', await metin(pb, tx));
      await shot(pb, 'tutar_belirsiz_' + ad);
      await satirDoldur(pb, 0, { tutar: '1.500' });
      ok(ad + ': "1.500" → "= 1.500,00 ₺", hata işareti kalkar; canlı toplam 1.500,00 ₺ (kaydedilecek tutarla aynı)', (await metin(pb, tx)) === '= 1.500,00 ₺' && (await pb.getAttribute('.cs-fsatir [name="tutar[]"]', 'aria-invalid')) !== 'true' && /1\.500,00 ₺/.test(await metin(pb, '[data-top-pb] b') || ''));
      await satirDoldur(pb, 0, { tutar: '1,500' });
      const n0b = S10.say().kayit;
      const sn = await gonderCevap(pb, () => pb.click('#csBordroForm [data-kaydet]'));
      ok(ad + ': "1,500" gönderilirse sunucu 422 satır hatası (Tutar belirsiz …), kayıt YOK, form açık', sn.kod === 422 && /Tutar belirsiz/.test((sn.j.satir_hatalari && sn.j.satir_hatalari['0'] && sn.j.satir_hatalari['0'].tutar) || '') && S10.say().kayit === n0b && await kisaBekle(pb, () => !!document.querySelector('.cs-fsatir--hata')) && (await pb.$$eval('.cs-fsatir--hata .cs-hata', l => l.length)) === 0);
      await satirDoldur(pb, 0, { tutar: '1.500' });
      ok(ad + ': 422 sonrası tutar düzeltilince alan hata işareti ve satır hata metni kalmaz', (await pb.getAttribute('.cs-fsatir [name="tutar[]"]', 'aria-invalid')) !== 'true' && (await pb.$$eval('.cs-fsatir .cs-hata', l => l.length)) === 0);
      await pb.context().close();
    }
    // 7c. mobil: toast bordro Kaydet düğmesini örtmez
    {
      const pm = await sayfa(browser, S10, { width: 390, height: 844 }, { dokunmatik: true });
      await git(pm, 'cek_dekontu.php?yeni=1&yon=alinan');
      await pm.evaluate(() => sessionStorage.setItem('cek_toast', 'Ciro Et kaydedildi — uyarı metni uzun uzun uzun'));
      await pm.reload({ waitUntil: 'load' }); await bekle(250);
      const t = await pm.evaluate(() => {
        const to = document.querySelector('.cs-toast'), bar = document.querySelector('.cs-eylem-bar'), tr = to.getBoundingClientRect(), br = bar.getBoundingClientRect(), btn = bar.querySelector('[data-kaydet]'), r = btn.getBoundingClientRect();
        const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return { gorunur: !to.hidden && tr.height > 0, toastAlt: Math.round(tr.bottom), barUst: Math.round(br.top), pe: getComputedStyle(to).pointerEvents, tik: el === btn || btn.contains(el) };
      });
      ok('mobil toast (390): bordro alt çubuğunun ÜSTÜNDE, pointer-events none; Kaydet düğmesi elementFromPoint ile tıklanabilir', t.gorunur && t.toastAlt <= t.barUst && t.pe === 'none' && t.tik, JSON.stringify(t));
      await shot(pm, 'mobil_toast_form');
      await pm.context().close();
      const pl = await sayfa(browser, S10, { width: 390, height: 844 }, { dokunmatik: true });
      await git(pl, 'cek_dekontu.php');
      await pl.evaluate(() => sessionStorage.setItem('cek_toast', JSON.stringify({ m: 'Ciro Et kaydedildi', b: 'BRD-2026-000123' })));
      await pl.reload({ waitUntil: 'load' }); await bekle(250);
      const tl = await pl.evaluate(() => {
        const to = document.querySelector('.cs-toast'), a = to.querySelector('a.cs-toast-bag'), bars = [...document.querySelectorAll('.cs-eylem-bar, .cs-secim-bar')].filter(b => b.getClientRects().length), tr = to.getBoundingClientRect();
        return { link: a ? a.getAttribute('href') : null, tik: a ? getComputedStyle(a).pointerEvents : null, barlar: bars.length, ustte: bars.every(b => tr.bottom <= b.getBoundingClientRect().top + 1) };
      });
      ok('mobil liste: toast bordro bağlantısı (pointer-events auto) + görünür alt çubuğun üstünde', tl.link === 'cek_cikti.php?bordro=BRD-2026-000123' && tl.tik === 'auto' && tl.ustte, JSON.stringify(tl));
      await shot(pl, 'mobil_toast_liste');
      await pl.context().close();
    }
    // 7d. aktif/pasif anahtarı: istek sürerken devre dışı, hata dönerse eski hâline
    {
      const pk = await sayfa(browser, S10, { width: 1366, height: 800 });
      await git(pk, 'cek_tanimlari.php?sekme=banka');
      await pk.route('**/cek_kaydet.php', async r => { await bekle(900); await r.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, hata: 'Deneme hatası', alanlar: {} }) }); });
      const sel = '#csPanelBanka .cs-tanim-satir input[role="switch"]';
      const once = await pk.$eval(sel, e => e.checked);
      await pk.click(sel);
      await bekle(250);
      const bekleyen = await pk.$eval(sel, e => ({ dis: e.disabled, chk: e.checked }));
      await kisaBekle(pk, s => { const e = document.querySelector(s); return e && !e.disabled; }, sel, 4000);
      const sonra = await pk.$eval(sel, e => ({ dis: e.disabled, chk: e.checked }));
      ok('anahtar (aktif/pasif): istek sürerken kutu disabled; sunucu hata dönünce kutu eski hâline (işaret geri alındı) ve yeniden etkin', bekleyen.dis === true && sonra.dis === false && sonra.chk === once && bekleyen.chk === !once, JSON.stringify({ once, bekleyen, sonra }));
      await pk.context().close();
    }
    ok('v528 bölümünde pageerror / console error / 5xx YOK', hatalar.length === 0, hatalar.join(' | '));
    ok('tüm senaryolarda sayfa/konsol hatası yok (pageerror / console.error / HTTP ≥500)', hatalar.length === 0, hatalar.join(' | '));
  } catch (e) {
    fail++; console.log('*** BEKLENMEYEN HATA: ' + (e && e.stack || e));
  } finally {
    kapaniyor = true; await temizle();
  }
  console.log('\n' + n + ' adım, ' + fail + ' başarısız');
  process.exit(fail ? 1 : 0);
})();
