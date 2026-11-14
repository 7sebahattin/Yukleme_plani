// =========================================================
// scripts/fatura_ui_smoke.js — "Fatura" TARAYICI uçtan uca testi (gerçek PHP + gerçek assets/fatura.js) — Dalga B
//   node scripts/fatura_ui_smoke.js        → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   FATURA_SHOT=<klasör>                   → ekran görüntüleri (ss_pc_form / ss_mobil_form / ss_pc_liste / ss_mobil_liste; deviceScaleFactor 2; repoya konmaz)
//
// Sahte yanıt YOK: scripts/_fatura_e2e_sunucu.js her isteği scripts/_fatura_istek.php alt süreciyle GERÇEK fatura sayfalarında / fatura_kaydet.php'de
// çalıştırır ve assets/'i diskten sunar. Durum dosya SQLite'ta kalıcıdır; DB gerçeği scripts/fatura_ui_e2e_say.php ile okunur. Canlı DB'ye / migrasyona HİÇ dokunulmaz.
// Bölümler: 1 PC 1366×768 (liste tek ekran, master-detail, form tek ekran, klavye girişi, canlı toplam = plan §4 kuruşu kuruşuna, gerçek kaydet → detay → iptal)
//           2 Plan §4 diğer örnekleri (tevkifat, müstahsil, ihracat TL) canlı toplam · 3 Sipariş → İrsaliye → Fatura zinciri (JS modal + sunucu ?kaynak=) + DB (stok 1 kez)
//           4 409 yetersiz stok → "Yine de kaydet" · 5 bagli_belge iptal modalı · 6 60 satır (Kaydet + toplam ekranda) · 7 tablet 820 · 8 mobil 390 (kart satır,
//           alt sayfa, alt çubuk bottomnav üstünde, ≥44px, 16px, taşma yok, uzun kaynak modalı kayar) · 9 tanımlar · 10 şema yok.
// Ölçümlerden önce animasyon için ≥400 ms beklenir (CLAUDE.md).
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
const { baslat } = require('./_fatura_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'fatura_ui_'));
const SHOT = process.env.FATURA_SHOT || '';
let fail = 0, n = 0, dbNo = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(110) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];

async function ortam(mod, ek) {
  const db = path.join(TMP, 'fatura' + (++dbNo) + '.sqlite');
  const cikti = execFileSync('php', [path.join(__dirname, 'fatura_ui_e2e_setup.php'), db, mod || 'ornek', ...(ek ? [ek] : [])], { cwd: ROOT, stdio: 'pipe' }).toString().trim().split('\n');
  const srv = await baslat({ db, sid: 'faturaE2E' + dbNo });
  srv.ornek = JSON.parse(cikti[cikti.length - 1] || '{}');
  srv.say = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'fatura_ui_e2e_say.php'), db], { cwd: ROOT }).toString());
  srv.sayi = re => srv.istekler.filter(i => re.test(i.yontem + ' ' + i.url)).length;
  return srv;
}
async function sayfa(browser, srv, vp, o) {
  o = o || {};
  const ctx = await browser.newContext({ viewport: vp, locale: 'tr-TR', serviceWorkers: 'block', deviceScaleFactor: o.dsf || 1, hasTouch: !!o.dokunmatik, isMobile: !!o.dokunmatik });
  const p = await ctx.newPage();
  p.on('pageerror', e => hatalar.push('[' + vp.width + '] pageerror: ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) hatalar.push('[' + vp.width + '] console: ' + m.text()); });
  p.on('response', r => { if (r.status() >= 500) hatalar.push('[' + vp.width + '] HTTP ' + r.status() + ' ' + r.url()); });
  p.on('dialog', d => d.accept());
  p.srv = srv; p.url0 = srv.url;
  return p;
}
const git = async (p, yol) => { await p.goto(p.url0 + '/' + yol, { waitUntil: 'load' }); await bekle(420); };
const tasma = p => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const sayfaKayar = p => p.evaluate(() => document.scrollingElement.scrollHeight - innerHeight);
const goster = (p, s) => p.$eval(s, e => !!(e.getClientRects().length) && getComputedStyle(e).visibility !== 'hidden').catch(() => false);
const metin = (p, s) => p.$eval(s, e => (e.tagName === 'TR' ? [...e.children].map(c => c.textContent).join(' ') : e.textContent).replace(/\s+/g, ' ').trim()).catch(() => null);
const gorunurSay = (p, s) => p.$$eval(s, l => l.filter(e => e.getClientRects().length).length);
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 8000 }); return true; } catch (e) { return false; } };
const postSay = p => p.srv.sayi(/^POST \/fatura_kaydet/);
async function postBekle(p, n0) {
  for (let i = 0; i < 120 && postSay(p) <= n0; i++) await bekle(100);
  await bekle(400); await p.waitForLoadState('load'); await bekle(450);
}
const sonPost = p => { const l = p.srv.istekler.filter(i => i.yontem === 'POST'); const x = l[l.length - 1]; try { return JSON.parse(x.ham); } catch (e) { return { _ham: x && x.ham, kod: x && x.kod }; } };
async function modalAcik(p, id) { const r = await kisaBekle(p, i => { const m = document.getElementById(i); return m && !m.hidden && m.getBoundingClientRect().height > 50; }, id, 4000); await bekle(420); return r; }
async function detayBekle(p, id) { return kisaBekle(p, i => { const d = document.getElementById('ftDetay'); return d && d.getAttribute('data-id') === String(i) && !d.classList.contains('ft-yukleniyor'); }, id, 8000); }
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
const top = (p, a) => metin(p, '#ftToplam [data-top="' + a + '"]');
const R = i => 'tbody.ft-fsatirlar > tr.ft-fsatir:nth-child(' + i + ')';
async function cariSec(p, ad) { await p.fill('#ftForm [name="cari_ara"]', ad); await p.press('#ftForm [name="cari_ara"]', 'Enter'); await bekle(500); }
async function satirDoldur(p, i, o) {
  const s = R(i);
  if (o.kart) { await p.fill(s + ' [name="kart_ara[]"]', o.kart); await p.press(s + ' [name="kart_ara[]"]', 'Tab'); }
  if (o.birim != null) await p.selectOption(s + ' [name="birim[]"]', o.birim);
  if (o.miktar != null) await p.fill(s + ' [name="miktar[]"]', o.miktar);
  if (o.fiyat != null) await p.fill(s + ' [name="birim_fiyat[]"]', o.fiyat);
  if (o.isk != null) await p.fill(s + ' [name="iskonto[]"]', o.isk);
  if (o.kdv != null) await p.selectOption(s + ' [name="kdv_orani[]"]', o.kdv);
  await bekle(200);
}
const kucukHedef = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' a, ' + k + ' button, ' + k + ' select, ' + k + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + k + ' summary')]
  .filter(e => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]'))
  .map(e => { const r = e.getBoundingClientRect(); return { s: e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || e.name || '').trim().slice(0, 16), h: Math.round(r.height), w: Math.round(r.width) }; })
  .filter(x => x.h < 44 || x.w < 44), kapsam);
const kucukYazi = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + k + ' select, ' + k + ' textarea')]
  .filter(e => e.getClientRects().length).filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.getAttribute('data-sh') || e.type), kapsam);

(async () => {
  let browser; const sunucular = [];
  try {
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});

    // ───────────────────────────────────────── 1. PC 1366×768
    console.log('\n=== 1. PC 1366×768: liste, master-detail, form, canlı toplam, kaydet, iptal ===');
    const S1 = await ortam(); sunucular.push(S1);
    const O = S1.ornek;
    const p = await sayfa(browser, S1, { width: 1366, height: 768 });
    await git(p, 'satis_faturalari.php');
    ok('liste: sayfa kaymaz (tek ekran), yatay taşma yok, 2 belge', (await sayfaKayar(p)) <= 1 && (await tasma(p)) <= 0 && (await gorunurSay(p, '#ftListe a.ft-satir')) === 2);
    ok('liste: sidebar görünür; bottomnav gizli; hızlı eylem (⋯) PC\'de gizli', await goster(p, 'aside.desktop-sidebar') && !(await goster(p, '.bottomnav')) && (await gorunurSay(p, '.ft-hizli')) === 0);
    await shot(p, 'pc_liste');
    const yuk0 = S1.sayi(/^GET \/satis_faturalari\.php\?id=\d+$/);
    await p.click('#ftListe a.ft-satir[data-id="' + O.satis + '"]');
    ok('liste satırı → detay sayfa yenilemeden (?parca=detay); URL ?id=; seçili satır aria-current', await detayBekle(p, O.satis) && /[?&]id=\d+/.test(p.url()) && S1.sayi(/parca=detay/) >= 1 && S1.sayi(/^GET \/satis_faturalari\.php\?id=\d+$/) === yuk0
      && (await p.$eval('#ftListe a.ft-satir[data-id="' + O.satis + '"]', e => e.getAttribute('aria-current'))) === 'true');
    ok('detay: toplam 15.197,86 · cariye 15.197,86 · sayfa kaymaz', (await top(p, 'genel')).startsWith('15.197,86') && (await top(p, 'cari')) === '15.197,86' && (await sayfaKayar(p)) <= 1);
    await p.focus('#ftArama'); await p.keyboard.press('ArrowDown');
    ok('↓ tuşu aramadan liste satırına odak taşır', await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('ft-satir')));

    await git(p, 'satis_faturalari.php?yeni=1');
    const tek = await p.evaluate(() => {
      const k = document.querySelector('.ft-satirlar-kaydir'), b = document.querySelector('.ft-eylem-bar').getBoundingClientRect(), kay = document.querySelector('[data-kaydet]').getBoundingClientRect();
      return { sk: document.scrollingElement.scrollHeight - innerHeight, kaydir: getComputedStyle(k).overflowY, bar: b.bottom <= innerHeight + 0.5, kay: kay.bottom <= innerHeight && kay.top >= 0,
        ray: document.querySelector('.ft-liste-ray').getBoundingClientRect().width, form: document.getElementById('ftForm').getBoundingClientRect().width };
    });
    ok('form TEK EKRAN: sayfa kaymaz, ızgara kendi içinde kayar (overflow auto), Kaydet ekranda, liste 56px raya iner, form ≥ 900px', tek.sk <= 1 && tek.kaydir === 'auto' && tek.bar && tek.kay && tek.ray <= 60 && tek.form >= 900, JSON.stringify(tek));
    ok('form: odak cari alanında; seri önizlemesi FTR…(tahmini)', await p.evaluate(() => document.activeElement && document.activeElement.name === 'cari_ara') && /FTR\d{13} \(tahmini\)/.test(await metin(p, '[data-seri-onizleme]')));
    await cariSec(p, 'Örnek Market A.Ş.');
    ok('cari seçilince bakiye çipi (#ftCariOzet .ft-bk[data-pb=TRY]) + kaynak ipucu (kalanı olan 2 belge) + vade otomatik (30 gün)', await kisaBekle(p, () => !!document.querySelector('#ftCariOzet .ft-bk[data-pb="TRY"]'))
      && !(await p.$eval('.ft-kaynak-ipucu', e => e.hidden)) && (await p.inputValue('#ftForm [name="vade_tarihi"]')) !== '');
    // klavye satır girişi
    await p.fill(R(1) + ' [name="kart_ara[]"]', 'STK-0001'); await p.press(R(1) + ' [name="kart_ara[]"]', 'Enter');
    ok('Enter: kart çözülür ("KOD — Ad"), odak miktara geçer; ipucu "Mevcut … · son 12,3456" + [Uygula]', (await p.inputValue(R(1) + ' [name="kart_ara[]"]')) === 'STK-0001 — Domates (Salkım)'
      && await p.evaluate(() => document.activeElement.name === 'miktar[]') && /Mevcut .* son 12,3456/.test(await metin(p, R(1) + ' .ft-fs-ipucu')) && !!(await p.$(R(1) + ' [data-son-fiyat-uygula]')));
    ok('son fiyat OTOMATİK yazılmaz (yalnız placeholder)', (await p.inputValue(R(1) + ' [name="birim_fiyat[]"]')) === '' && (await p.getAttribute(R(1) + ' [name="birim_fiyat[]"]', 'placeholder')) === '12,3456');
    await p.keyboard.type('1250'); await p.keyboard.press('Enter'); await p.keyboard.press('Enter');
    await p.click(R(1) + ' [data-son-fiyat-uygula]');
    ok('[Uygula] son fiyatı yazar (12,3456)', (await p.inputValue(R(1) + ' [name="birim_fiyat[]"]')) === '12,3456');
    await p.focus(R(1) + ' [name="birim_fiyat[]"]'); await p.keyboard.press('Enter'); await p.keyboard.type('2,5'); await p.keyboard.press('Enter'); await p.keyboard.press('Enter');
    ok('son satırın son hücresinde Enter (satır dolu) → yeni satır + ürün alanına odak', (await p.$$('tbody.ft-fsatirlar > tr')).length === 2 && await p.evaluate(() => document.activeElement.name === 'kart_ara[]'));
    await satirDoldur(p, 2, { kart: 'Nakliye hizmeti', birim: 'adet', miktar: '3', fiyat: '0,3333', kdv: '20' });
    ok('kartsız satır: "serbest satır olarak kaydedilecek" ipucu', /serbest satır/.test(await metin(p, R(2) + ' .ft-fs-ipucu')));
    await bekle(200);
    ok('CANLI TOPLAM (plan §4 satış): brüt 15.433,00 · isk 385,80 · net 15.047,20 · KDV 150,66 · genel 15.197,86 · cari 15.197,86', (await top(p, 'brut')) === '15.433,00' && (await top(p, 'isk')) === '385,80' && (await top(p, 'net')) === '15.047,20'
      && (await top(p, 'kdv')) === '150,66' && (await top(p, 'genel')).startsWith('15.197,86') && (await top(p, 'cari')) === '15.197,86', JSON.stringify([await top(p, 'brut'), await top(p, 'genel')]));
    ok('KDV kırılımı: %1 15.046,20/150,46 · %20 1,00/0,20; satır tutarları 15.046,20 / 1,00', (await metin(p, '[data-kdv-grup="1"]')) === '%1 15.046,20 150,46' && (await metin(p, '[data-kdv-grup="20"]')) === '%20 1,00 0,20'
      && (await metin(p, R(1) + ' .ft-fs-tutar-deger')) === '15.046,20' && (await metin(p, R(2) + ' .ft-fs-tutar-deger')) === '1,00');
    ok('eylem çubuğu özeti: Genel 15.197,86 ₺', (await metin(p, '[data-ozet="genel"]')) === '15.197,86 ₺');
    // Ctrl+↓ / Ctrl+Shift+↓ / Ctrl+Del
    await p.focus(R(2) + ' [name="miktar[]"]'); await p.keyboard.press('Control+ArrowDown');
    ok('Ctrl+↓ yeni satır', (await p.$$('tbody.ft-fsatirlar > tr')).length === 3);
    await p.keyboard.press('Control+Delete'); await bekle(150);
    ok('Ctrl+Del satır siler', (await p.$$('tbody.ft-fsatirlar > tr')).length === 2);
    await p.focus(R(2) + ' [name="miktar[]"]'); await p.keyboard.press('Control+Shift+ArrowDown'); await bekle(200);
    ok('Ctrl+Shift+↓ satırı alta kopyalar; toplam iki katı Nakliye (genel 15.199,06)', (await p.$$('tbody.ft-fsatirlar > tr')).length === 3 && (await top(p, 'genel')).startsWith('15.199,06'));
    await p.click(R(3) + ' [data-satir-menu]');
    const menu = await p.evaluate(() => { const m = document.querySelector('.ft-menu'); const r = m.getBoundingClientRect(); return { pos: getComputedStyle(m).position, ic: r.bottom <= innerHeight && r.top >= 0, z: +getComputedStyle(m).zIndex }; });
    ok('⋯ satır menüsü: position fixed, ekran içinde, z 200', menu.pos === 'fixed' && menu.ic && menu.z === 200, JSON.stringify(menu));
    await p.click('.ft-menu [data-satir-sil]'); await bekle(200);
    ok('menü → Sil: 2 satıra döner, toplam 15.197,86', (await p.$$('tbody.ft-fsatirlar > tr')).length === 2 && (await top(p, 'genel')).startsWith('15.197,86'));
    if (SHOT) { const ps = await sayfa(browser, S1, { width: 1366, height: 768 }, { dsf: 2 }); await git(ps, 'satis_faturalari.php?kopya=' + O.satis); await ps.screenshot({ path: path.join(SHOT, 'ss_pc_form.png') }); await git(ps, 'satis_faturalari.php?id=' + O.satis); await ps.screenshot({ path: path.join(SHOT, 'ss_pc_liste.png') }); await ps.context().close(); }
    // gerçek kaydet (Ctrl+S)
    const D0 = S1.say();
    let n0 = postSay(p);
    await p.keyboard.press('Control+s');
    await postBekle(p, n0);
    const D1 = S1.say(), yeni = D1.belgeler[D1.belgeler.length - 1];
    ok('Ctrl+S kaydeder: yeni satış belgesi + 1 cari dekont (BDK), stok fişi yok (stok etkisi kapalı)', D1.belge === D0.belge + 1 && yeni.tur === 'satis' && Math.abs(Number(yeni.genel_toplam) - 15197.86) < 0.001 && D1.cari_hareket === D0.cari_hareket + 1 && D1.stok_fis === D0.stok_fis && !yeni.stok_fis_id, JSON.stringify(yeni));
    ok('kayıttan sonra detaya yönlendirme (?id=yeni) + toast; sunucu toplamı = canlı önizleme', new RegExp('satis_faturalari\\.php\\?id=' + yeni.id + '$').test(p.url()) && (await top(p, 'genel')).startsWith('15.197,86') && /Kaydedildi · STF-/.test(await metin(p, '.ft-toast')));
    // iptal
    await p.click('.ft-islemler .ft-islem-tehlike [data-ft-modal="iptal"]');
    ok('İptal modalı açılır (z ≥ 600), onay düğmesi neden < 3 iken pasif', await modalAcik(p, 'ftModalIptal') && (await p.$eval('#ftModalIptal', e => +getComputedStyle(e).zIndex)) >= 600 && await p.$eval('[data-iptal-onay]', b => b.disabled));
    await p.fill('[data-iptal-neden]', 'Test iptali — yanlış fiyat');
    n0 = postSay(p);
    await p.click('[data-iptal-onay]');
    await postBekle(p, n0);
    const D2 = S1.say(), ip = D2.belgeler.find(b => b.id === yeni.id);
    ok('iptal: belge durum=iptal, cari dekont iptal (kaynak fatura), sayfa İPTAL şeridi', ip.durum === 'iptal' && D2.cari_hareketler.filter(h => h.kaynak_modul === 'fatura' && Number(h.kaynak_id) === yeni.id).every(h => h.durum === 'iptal')
      && /İptal edildi: Test iptali/.test(await metin(p, '.ft-iptal-serit')));

    // ───────────────────────────────────────── 2. Plan §4 diğer örnekleri
    console.log('\n=== 2. Plan §4 örnekleri (canlı önizleme) ===');
    await git(p, 'satis_faturalari.php?yeni=1');
    await cariSec(p, 'Örnek Market A.Ş.');
    await satirDoldur(p, 1, { kart: 'Danışmanlık', birim: 'adet', miktar: '1', fiyat: '10.000', kdv: '20' });
    await p.selectOption('#ftForm [name="tevkifat_id"]', { label: 'Tevkifat 7/10' }); await bekle(200);
    ok('tevkifat 7/10: net 10.000,00 · KDV 2.000,00 · tevkifat −1.400,00 · cari 10.600,00 (satır görünür)', (await top(p, 'kdv')) === '2.000,00' && (await top(p, 'tevkifat')) === '−1.400,00' && (await top(p, 'cari')) === '10.600,00' && await goster(p, '[data-satir="tevkifat"]'));
    await git(p, 'alis_faturalari.php?yeni=1&tur=alis_mustahsil');
    await cariSec(p, 'Örnek Üretici Kooperatifi');
    await satirDoldur(p, 1, { kart: 'STK-0001', miktar: '5000', fiyat: '20,00' }); await bekle(200);
    ok('müstahsil: net 100.000,00 · stopaj −2.000,00 · Bağ-Kur −1.000,00 · Net ödenecek 97.000,00; KDV satırı %0 kilitli', (await top(p, 'net')) === '100.000,00' && (await top(p, 'stopaj')) === '−2.000,00' && (await top(p, 'bagkur')) === '−1.000,00'
      && (await top(p, 'cari')) === '97.000,00' && /Net ödenecek/.test(await metin(p, '[data-cari-etiket]')) && (await p.$eval(R(1) + ' [name="kdv_orani[]"]', s => s.options.length === 1 && s.value === '0')));
    await git(p, 'satis_faturalari.php?yeni=1');
    await p.check('#ftTur_satis_ihracat', { force: true }); await bekle(200);
    ok('tür segmenti → İhracat: istisna görünür, tevkifat gizli, KDV %0 kilitli, başlık "Yeni İhracat Faturası", açıklamaya şablon', await goster(p, '#ftForm [name="istisna_id"]') && !(await goster(p, '#ftForm [name="tevkifat_id"]'))
      && /İhracat Faturası/.test(await metin(p, '[data-form-baslik]')) && /11\/1-a/.test(await p.inputValue('#ftForm [name="aciklama"]')));
    await cariSec(p, 'Örnek İhracat Ltd.');
    ok('USD carisi: para birimi USD\'ye çekilir, kur alanı açılır', (await p.inputValue('#ftForm [name="para_birimi"]')) === 'USD' && !(await p.$eval('#ftForm [name="kur"]', e => e.disabled)));
    await p.fill('#ftForm [name="kur"]', '34,5678');
    await satirDoldur(p, 1, { kart: 'STK-0001', miktar: '2000', fiyat: '1,15' }); await bekle(200);
    ok('ihracat USD: genel 2.300,00 · TL karşılığı 79.505,94 ₺ (plan §4: 7.950.594 kr)', (await top(p, 'genel')).startsWith('2.300,00') && (await top(p, 'tl')) === '79.505,94 ₺' && await goster(p, '[data-satir="tl"]'));

    // ───────────────────────────────────────── 3. Sipariş → İrsaliye → Fatura
    console.log('\n=== 3. Sipariş → İrsaliye → Fatura zinciri ===');
    await git(p, 'alinan_siparis_fisleri.php?id=' + O.siparis);
    ok('sipariş detayı: kalan 600 kg; İrsaliye oluştur bağlantısı', /600 kg/.test(await metin(p, '#ftDetay .ft-kalan--acik')) && await goster(p, '[data-irsaliye-olustur]'));
    await p.click('[data-irsaliye-olustur]'); await p.waitForLoadState('load'); await bekle(450);
    ok('İrsaliye oluştur → sevk_irsaliyeleri.php?yeni=1&tur=irsaliye&kaynak=ID: 2 kaynaklı satır (Domates 600, Kasa 100), cari dolu, bilgi şeridi', /sevk_irsaliyeleri\.php\?yeni=1&tur=irsaliye&kaynak=/.test(p.url())
      && (await p.$$('tr.ft-fsatir--kaynakli')).length === 2 && (await p.inputValue(R(1) + ' [name="miktar[]"]')) === '600' && !(await p.$eval('[data-kaynak-bilgi]', e => e.hidden)));
    await p.fill(R(1) + ' [name="miktar[]"]', '700'); await bekle(200);
    ok('canlı kalan aşımı: 700 > 600 → ft-fsatir--kalan + "kalanı 100 aşıyor"', await p.$eval(R(1), e => e.classList.contains('ft-fsatir--kalan')) && /aşıyor/.test(await metin(p, R(1) + ' .ft-kalan')));
    n0 = postSay(p); await p.click('[data-kaydet]'); await postBekle(p, n0);
    ok('kalan aşımı sunucuda 409 kalan_asimi → satır hatası + [Kalana eşitle]', sonPost(p).kod === 'kalan_asimi' && !!(await p.$(R(1) + ' [data-kalana-esitle]')) && /Kalan miktarı aşıyor/.test(await metin(p, '#ftHataOzet')), JSON.stringify(sonPost(p)));
    await p.click(R(1) + ' [data-kalana-esitle]');
    ok('Kalana eşitle → miktar 600', (await p.inputValue(R(1) + ' [name="miktar[]"]')) === '600');
    const Z0 = S1.say();
    n0 = postSay(p); await p.click('[data-kaydet]'); await postBekle(p, n0);
    const Z1 = S1.say(), irs = Z1.belgeler[Z1.belgeler.length - 1];
    ok('irsaliye kaydı: stok ÇIKIŞ fişi 1 (kaynak fatura), cari hareketi YOK', irs.tur === 'irsaliye' && Z1.stok_fis === Z0.stok_fis + 1 && Z1.stok_fisler[Z1.stok_fisler.length - 1].kaynak_modul === 'fatura' && Z1.cari_hareket === Z0.cari_hareket, JSON.stringify(irs));
    ok('irsaliye detayı + zincir (ASF → IRS) + Faturalaştır', /sevk_irsaliyeleri\.php\?id=/.test(p.url()) && (await gorunurSay(p, '#ftZincir .ft-zincir-oge[data-zincir-tur="siparis"]')) === 1 && await goster(p, 'a[data-faturalastir]'));
    // JS modalıyla faturalaştır (kaynaktan getir)
    await git(p, 'satis_faturalari.php?yeni=1');
    await cariSec(p, 'Örnek Market A.Ş.');
    await p.click('.ft-form-bas [data-kaynak-getir]');
    ok('Kaynaktan getir modalı: adaylar ?parca=kaynaklar (irsaliyeler + sipariş)', await modalAcik(p, 'ftModalKaynak') && await kisaBekle(p, () => document.querySelectorAll('#ftModalKaynak input[name="kaynak[]"]').length >= 2));
    await p.check('#ftModalKaynak input[name="kaynak[]"][value="' + irs.id + '"]');
    await p.click('[data-kaynak-ileri]');
    ok('adım 2: satırlar (?parca=kaynak_satirlari) aktarılacak miktarlarla', await kisaBekle(p, () => document.querySelectorAll('#ftModalKaynak [data-aktar-milli]').length === 2));
    await p.click('[data-kaynak-ekle]'); await bekle(400);
    ok('Forma ekle: 2 kaynaklı satır (IRS rozeti), boş satır silindi, tür segmenti kilitli (aria-disabled)', (await p.$$('tbody.ft-fsatirlar > tr')).length === 2 && (await p.$$('tr.ft-fsatir--kaynakli')).length === 2
      && /IRS-/.test(await metin(p, R(1) + ' .ft-rozet--kaynak')) && (await p.getAttribute('.ft-tur-sec', 'aria-disabled')) === 'true');
    const F0 = S1.say();
    n0 = postSay(p); await p.click('[data-kaydet]'); await postBekle(p, n0);
    const F1 = S1.say(), fat = F1.belgeler[F1.belgeler.length - 1];
    const fSat = F1.satirlar.filter(s => s.belge_id === fat.id);
    ok('irsaliyeden fatura: 1 cari dekont, stok fişi YOK (çift sayım yok), satırlar kaynak_satir_id dolu + stoklu=0', fat.tur === 'satis' && F1.cari_hareket === F0.cari_hareket + 1 && F1.stok_fis === F0.stok_fis && fSat.length === 2 && fSat.every(s => s.kaynak_satir_id && Number(s.stoklu) === 0), JSON.stringify(fSat));
    await git(p, 'sevk_irsaliyeleri.php?id=' + irs.id);
    ok('irsaliye artık "Faturalandı", Faturalaştır pasif (Kalan miktar yok)', /Faturalandı/.test(await metin(p, '#ftDetay .ft-baslik-rozet')) && await p.$eval('[data-faturalastir]', b => b.disabled && b.title === 'Kalan miktar yok'));

    // ───────────────────────────────────────── 4. 409 yetersiz stok
    console.log('\n=== 4. Yetersiz stok → Yine de kaydet ===');
    await git(p, 'sevk_irsaliyeleri.php?yeni=1');
    await cariSec(p, 'Deneme Gıda A.Ş.');
    await satirDoldur(p, 1, { kart: 'STK-0003', miktar: '90000' });
    ok('canlı stok uyarısı: ft-fsatir--eksik + "— yetersiz"', await p.$eval(R(1), e => e.classList.contains('ft-fsatir--eksik')) && /yetersiz/.test(await metin(p, R(1) + ' .ft-fs-ipucu')));
    const Y0 = S1.say();
    n0 = postSay(p); await p.click('[data-kaydet]'); await postBekle(p, n0);
    ok('409 yetersiz_stok → #ftEksik paneli (tablo: Patlıcan), HİÇBİR şey yazılmaz', sonPost(p).kod === 'yetersiz_stok' && await goster(p, '#ftEksik') && /Patlıcan/.test(await metin(p, '#ftEksik tbody')) && S1.say().belge === Y0.belge && S1.say().stok_fis === Y0.stok_fis);
    const ik = await p.inputValue('#ftForm [name="istek_anahtari"]');
    n0 = postSay(p); await p.click('[data-eksik-kaydet]'); await postBekle(p, n0);
    const Y1 = S1.say(), yb = Y1.belgeler[Y1.belgeler.length - 1];
    ok('"Yine de kaydet": aynı istek anahtarı + eksiye_izin=1 → kaydedildi, stok fişine uyarı', Y1.belge === Y0.belge + 1 && yb.istek_anahtari === ik && !!Y1.stok_fisler[Y1.stok_fisler.length - 1].uyari, JSON.stringify(yb));
    ok('yetersiz akışında POST gövdesi eksiye_izin=1', S1.istekler.filter(i => i.yontem === 'POST').slice(-1)[0].post.eksiye_izin === '1');

    // ───────────────────────────────────────── 5. bagli_belge
    console.log('\n=== 5. İptal: bagli_belge ===');
    await git(p, 'alinan_siparis_fisleri.php?id=' + O.siparis);
    await p.click('.ft-islem-tehlike [data-ft-modal="iptal"]'); await modalAcik(p, 'ftModalIptal');
    await p.fill('[data-iptal-neden]', 'Müşteri vazgeçti');
    n0 = postSay(p); await p.click('[data-iptal-onay]');
    await kisaBekle(p, () => !document.querySelector('#ftModalIptal .ft-form-hata').hidden);
    ok('bağlı irsaliyesi olan sipariş: 409 bagli_belge → modal açık kalır, IRS bağlantıları listelenir', !(await p.$eval('#ftModalIptal', e => e.hidden)) && (await p.$$('#ftModalIptal .ft-bagli-liste a')).length >= 1
      && /sevk_irsaliyeleri\.php\?id=/.test(await p.getAttribute('#ftModalIptal .ft-bagli-liste a', 'href')));
    await p.keyboard.press('Escape');

    // ───────────────────────────────────────── 6. 60 satır
    console.log('\n=== 6. 60 satır: Kaydet ve toplam ekranda ===');
    await git(p, 'satis_faturalari.php?yeni=1');
    await cariSec(p, 'Örnek Market A.Ş.');
    await p.evaluate(() => { const b = document.querySelector('[data-satir-ekle]'); for (let i = 0; i < 59; i++) b.click(); });
    await p.evaluate(() => { document.querySelectorAll('tbody.ft-fsatirlar > tr').forEach((tr, i) => { tr.querySelector('[name="kart_ara[]"]').value = 'Hizmet ' + (i + 1); tr.querySelector('[name="miktar[]"]').value = '1'; tr.querySelector('[name="birim_fiyat[]"]').value = '10'; tr.querySelector('[name="miktar[]"]').dispatchEvent(new Event('input', { bubbles: true })); }); });
    await bekle(500);
    const s60 = await p.evaluate(() => { const g = e => { const r = document.querySelector(e).getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; };
      return { satir: document.querySelectorAll('tbody.ft-fsatirlar > tr').length, kay: g('[data-kaydet]'), cari: g('[data-ozet="cari"]'), sk: document.scrollingElement.scrollHeight - innerHeight, ekle: document.querySelector('[data-satir-ekle]').disabled, iz: document.querySelector('.ft-satirlar-kaydir').scrollHeight > document.querySelector('.ft-satirlar-kaydir').clientHeight }; });
    ok('60 satır: sayfa kaymaz, ızgara kayar, Kaydet + toplam ekranda, "Satır ekle" sınırda pasif', s60.satir === 60 && s60.sk <= 1 && s60.iz && s60.kay && s60.cari && s60.ekle, JSON.stringify(s60));
    ok('60 satır toplam: net 600,00 + %1 KDV (varsayılan) 6,00 = genel 606,00', (await top(p, 'net')) === '600,00' && (await top(p, 'genel')).startsWith('606,00'), await top(p, 'genel'));

    // ───────────────────────────────────────── 7. Tablet 820
    console.log('\n=== 7. Tablet 820 ===');
    const t = await sayfa(browser, S1, { width: 820, height: 1100 });
    await git(t, 'satis_faturalari.php?kopya=' + O.satis);
    const tb = await t.evaluate(() => { const th = document.querySelector('.ft-ftablo thead'); const bar = document.querySelector('.ft-eylem-bar').getBoundingClientRect();
      return { tablo: getComputedStyle(th).display, gir: getComputedStyle(document.querySelector('td.ft-fs-miktar')).display, bar: bar.bottom <= innerHeight + 0.5 && bar.top < innerHeight, pos: getComputedStyle(document.querySelector('.ft-eylem-bar')).position }; });
    ok('tablet: tablo düzeni (thead görünür, girdiler hücrede), eylem çubuğu sticky ve görünür, taşma yok', tb.tablo === 'table-header-group' && tb.gir === 'table-cell' && tb.bar && tb.pos === 'sticky' && (await tasma(t)) <= 0, JSON.stringify(tb));
    await t.context().close();

    // ───────────────────────────────────────── 8. Mobil 390
    console.log('\n=== 8. Mobil 390×844 ===');
    const S8 = await ortam('ornek', 'cok'); sunucular.push(S8);
    const m = await sayfa(browser, S8, { width: 390, height: 844 }, { dokunmatik: true });
    await git(m, 'satis_faturalari.php');
    ok('mobil liste: yatay taşma yok, kartlar ≥64px, hızlı eylem (⋯) görünür, bottomnav görünür', (await tasma(m)) <= 0 && (await m.$eval('#ftListe a.ft-satir', e => e.getBoundingClientRect().height)) >= 64
      && (await gorunurSay(m, '.ft-hizli')) >= 1 && await goster(m, '.bottomnav'));
    await shot(m, 'mobil_liste');
    await m.click('#ftListe a.ft-satir'); await m.waitForLoadState('load'); await bekle(450);
    ok('mobil: satıra dokun → detay tam sayfa (?id=), liste gizli, işlem çubuğu alt sabit [Yazdır][Kopyala][⋯]', /\?id=/.test(m.url()) && !(await goster(m, '#ftListeKol')) && await goster(m, '.ft-diger-menu') && !(await goster(m, '.ft-islem-tehlike .btn')));
    await git(m, 'satis_faturalari.php?yeni=1');
    await cariSec(m, 'Örnek Market A.Ş.');
    ok('mobil form: satır kart (girdiler gizli, özet görünür), taşma yok', !(await goster(m, R(1) + ' [name="miktar[]"]')) && await goster(m, R(1) + ' .ft-fs-ac') && (await tasma(m)) <= 0);
    await m.click('[data-satir-ekle]');
    ok('＋ Satır ekle → alt sayfa (#ftSatirSheet) açılır; içinde form yok', await modalAcik(m, 'ftSatirSheet') && !(await m.$('#ftSatirSheet form')));
    await m.fill('[data-sh="ara"]', 'domates'); await bekle(200);
    ok('alt sayfa araması: sonuç düğmeleri ≥52px', (await m.$$eval('[data-sh-sonuc] button[data-kart-id]', l => l.length)) >= 1 && (await m.$eval('[data-sh-sonuc] button', b => b.getBoundingClientRect().height)) >= 52);
    await m.click('[data-sh-sonuc] button[data-kart-id]');
    await m.fill('[data-sh="miktar"]', '1250'); await m.fill('[data-sh="fiyat"]', '12,3456'); await m.fill('[data-sh="iskonto"]', '2,5'); await bekle(150);
    ok('alt sayfa canlı tutar: Tutar 15.046,20 ₺ · KDV 150,46 ₺', /Tutar 15\.046,20 ₺ · KDV 150,46 ₺/.test(await metin(m, '[data-sh-hesap]')));
    const shm = await m.evaluate(() => { const d = document.querySelector('#ftSatirSheet .pm-dialog').getBoundingClientRect(), f = document.querySelector('#ftSatirSheet .pm-footer').getBoundingClientRect(); return { ust: d.top >= 0, alt: f.bottom <= innerHeight + 1 }; });
    ok('alt sayfa ekran içinde, footer görünür', shm.ust && shm.alt, JSON.stringify(shm));
    ok('alt sayfa: girdiler 16px, hedefler ≥44px', !(await kucukYazi(m, '#ftSatirSheet')).length && !(await kucukHedef(m, '#ftSatirSheet .pm-footer')).length, JSON.stringify(await kucukHedef(m, '#ftSatirSheet .pm-footer')));
    await m.click('[data-sh-uygula]'); await bekle(450);
    const satirSay = (await m.$$('tbody.ft-fsatirlar > tr')).length;
    ok('Uygula → kart satır özeti "Domates (Salkım) … 1250 kg × 12,3456" + tutar 15.046,20; toplam güncel', /Domates \(Salkım\).*1250 kg × 12,3456/.test(await metin(m, R(satirSay) + ' .ft-fs-ozet')) && (await metin(m, R(satirSay) + ' .ft-fs-tutar-deger')) === '15.046,20' && (await metin(m, '[data-ozet="genel"]')) === '15.196,66 ₺');
    const alt = await m.evaluate(() => { const b = document.querySelector('.ft-eylem-bar').getBoundingClientRect(), n = document.querySelector('.bn-dock') || document.querySelector('.bottomnav'); const nr = n.getBoundingClientRect(); return { barAlt: b.bottom, navUst: nr.top, gor: b.top < innerHeight && b.bottom > 0, z: getComputedStyle(document.querySelector('.ft-eylem-bar')).zIndex }; });
    ok('mobil alt çubuk (Genel + Kaydet) bottomnav\'ın ÜSTÜNDE, örtüşme yok, z-index yok', alt.gor && alt.barAlt <= alt.navUst + 0.5 && alt.z === 'auto', JSON.stringify(alt));
    const kay = await m.$eval('[data-kaydet]', b => { const r = b.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return e === b || b.contains(e); });
    ok('Kaydet tıklanabilir (elementFromPoint)', kay);
    ok('mobil form: girdiler 16px, hedefler ≥44px (#ftForm görünür öğeler)', !(await kucukYazi(m, '#ftForm')).length && !(await kucukHedef(m, '#ftForm')).length, JSON.stringify([await kucukYazi(m, '#ftForm'), await kucukHedef(m, '#ftForm')]));
    ok('mobil form: yatay taşma yok', (await tasma(m)) <= 0);
    if (SHOT) {
      const ms = await sayfa(browser, S8, { width: 390, height: 844 }, { dokunmatik: true, dsf: 2 });
      await git(ms, 'satis_faturalari.php?kopya=' + S8.ornek.satis); await ms.screenshot({ path: path.join(SHOT, 'ss_mobil_form.png') });
      await git(ms, 'satis_faturalari.php'); await ms.screenshot({ path: path.join(SHOT, 'ss_mobil_liste.png') });
      await ms.context().close();
    }
    // mobilde kaydet
    const M0 = S8.say();
    n0 = postSay(m); await m.click('[data-kaydet]'); await postBekle(m, n0);
    const M1 = S8.say();
    ok('mobil Kaydet → belge yazıldı, detay sayfası', M1.belge === M0.belge + 1 && /\?id=/.test(m.url()));
    // uzun kaynak modalı (40 sipariş)
    await git(m, 'sevk_irsaliyeleri.php?yeni=1');
    await cariSec(m, 'Deneme Gıda A.Ş.');
    await m.click('.ft-form-bas [data-kaynak-getir]');
    await modalAcik(m, 'ftModalKaynak');
    await kisaBekle(m, () => document.querySelectorAll('#ftModalKaynak input[name="kaynak[]"]').length >= 40);
    const km = await m.evaluate(() => { const b = document.querySelector('#ftModalKaynak .pm-body'), f = document.querySelector('#ftModalKaynak .pm-footer').getBoundingClientRect(); return { kayar: b.scrollHeight > b.clientHeight + 10, ov: getComputedStyle(b).overflowY, alt: f.bottom <= innerHeight + 1 }; });
    ok('uzun kaynak modalı (40 aday): .pm-body kayar, footer ekranda (.pm-dialog > form flex zinciri)', km.kayar && /auto|scroll/.test(km.ov) && km.alt, JSON.stringify(km));
    const son = await m.$$('#ftModalKaynak input[name="kaynak[]"]');
    await son[son.length - 1].scrollIntoViewIfNeeded(); await bekle(150);
    await son[son.length - 1].click({ force: false });
    ok('en alttaki onay kutusu kaydırılıp tıklanabilir', await son[son.length - 1].isChecked());
    await m.click('[data-kaynak-ileri]');
    await kisaBekle(m, () => document.querySelectorAll('#ftModalKaynak [data-aktar-milli]').length === 1);
    await m.click('[data-kaynak-ekle]'); await bekle(450);
    ok('mobil: kaynaktan 1 satır eklendi (kart özetinde ASF rozeti)', (await m.$$('tr.ft-fsatir--kaynakli')).length === 1 && /ASF-/.test(await metin(m, 'tr.ft-fsatir--kaynakli .ft-fs-ozet')));
    await m.context().close();

    // ───────────────────────────────────────── 9. Tanımlar
    console.log('\n=== 9. Fatura Tanımları ===');
    await git(p, 'fatura_tanimlari.php');
    ok('tanımlar: 8 sekme, KDV paneli açık', (await p.$$('.ft-tanim-nav-oge')).length === 8 && await goster(p, '#ftPanelKdv'));
    await p.click('#ftTabSeri');
    ok('sekme tıklaması paneli değiştirir (aria-selected), URL ?sekme=seri', await goster(p, '#ftPanelSeri') && !(await goster(p, '#ftPanelKdv')) && /sekme=seri/.test(p.url()));
    await p.click('#ftTabKdv');
    const T0 = S1.say();
    await p.fill('#ftPanelKdv .ft-tanim-ekle [name="ad"]', 'KDV %8'); await p.fill('#ftPanelKdv .ft-tanim-ekle [name="deger1"]', '8');
    n0 = postSay(p); await p.click('#ftPanelKdv .ft-tanim-ekle button[type="submit"]'); await postBekle(p, n0);
    const T1 = S1.say(), k8 = T1.tanimlar.find(x => x.ad === 'KDV %8');
    ok('tanım ekle (satır içi form, fetch) → DB\'de KDV %8 (deger1 8)', T1.tanim === T0.tanim + 1 && k8 && Number(k8.deger1) === 8);
    n0 = postSay(p); await p.click('.ft-tanim-satir[data-id="' + k8.id + '"] input[role="switch"]'); await postBekle(p, n0);
    ok('aktif anahtarı → pasif (DB aktif=0)', Number(S1.say().tanimlar.find(x => x.id === k8.id).aktif) === 0);
    await p.click('.ft-tanim-satir[data-id="' + k8.id + '"] [data-tanim-duzen]');
    ok('Düzenle → satır içi düzenleme formu görünür', await goster(p, '.ft-tanim-satir--duzen:not([hidden]) [name="ad"]'));

    // ───────────────────────────────────────── 10. Şema yok
    console.log('\n=== 10. Şema yok ===');
    const S10 = await ortam('sema'); sunucular.push(S10);
    const q = await sayfa(browser, S10, { width: 1366, height: 768 });
    await git(q, 'satis_faturalari.php');
    ok('şema yok: uyarı kartı [data-ft-sema], 3 eksik (cari, stok, fatura), sekme şeridi yok', await goster(q, '[data-ft-sema]') && (await q.$$('[data-sema-eksik]')).length === 3 && !(await q.$('.ft-sekmeler')));
    await q.context().close();

    ok('sayfa hatası / konsol hatası / HTTP 5xx YOK', hatalar.length === 0, hatalar.slice(0, 6).join('\n    '));
  } catch (e) {
    fail++; console.log('*** İSTİSNA: ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    for (const s of sunucular) await s.kapat();
    try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* önemsiz */ }
  }
  console.log('\n' + (n - fail) + ' geçti, ' + fail + ' kaldı');
  process.exit(fail > 0 ? 1 : 0);
})();
