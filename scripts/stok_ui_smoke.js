// =========================================================
// scripts/stok_ui_smoke.js — "Stok (muhasebe)" TARAYICI uçtan uca testi (gerçek PHP + gerçek assets/stok.js) — Parça A + B
//   node scripts/stok_ui_smoke.js        → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   STOK_SHOT=<klasör>                   → ekran görüntüleri (A_pc / A_mobil · B_form_pc / B_form_mobil / B_transfer_pc + diğerleri; repoya konmaz)
//
// Sahte yanıt YOK: scripts/_stok_e2e_sunucu.js her isteği scripts/_stok_istek.php alt süreciyle GERÇEK stok_*.php sayfalarında
// çalıştırır (gerçek config/helpers.php kabuğu + stok_core + stok_ui) ve assets/'i diskten sunar. Durum dosya SQLite'ta kalıcıdır;
// DB gerçeği scripts/stok_ui_e2e_say.php ile okunur. Canlı DB'ye / migrasyona HİÇ dokunulmaz.
// Bölümler: 1 kartlar masaüstü 1366 (tek ekran, süzgeç, master-detail, kart oluştur/düzenle/pasifleştir/sil) · 2 tanımlar 1366 ·
//           3 tablet 820 · 4 mobil 390 (liste→detay→geri, alt çubuk, ≥44px, ≥16px, taşma, modal) · 5 şema yok / boş ·
//           6 fişler 1366 (Enter/Ctrl+S giriş, master-detail, iptal, kopya, yetersiz→Yine de kaydet, transfer, dönüşüm, kantar + cari) · 7 fiş formu 390.
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
const { baslat } = require('./_stok_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'stok_ui_'));
const SHOT = process.env.STOK_SHOT || '';
let fail = 0, n = 0, dbNo = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(100) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];

async function ortam(mod) {
  const db = path.join(TMP, 'stok' + (++dbNo) + '.sqlite');
  execFileSync('php', [path.join(__dirname, 'stok_ui_e2e_setup.php'), db, mod || 'ornek'], { cwd: ROOT, stdio: 'pipe' });
  const srv = await baslat({ db, sid: 'stokE2E' + dbNo });
  srv.say = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'stok_ui_e2e_say.php'), db], { cwd: ROOT }).toString());
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
  p.on('dialog', d => d.accept());
  p.srv = srv; p.url0 = srv.url;
  return p;
}
const git = (p, yol) => p.goto(p.url0 + '/' + yol, { waitUntil: 'load' });
const tasma = p => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const goster = (p, s) => p.$eval(s, e => !!(e.getClientRects().length) && getComputedStyle(e).visibility !== 'hidden').catch(() => false);
const metin = (p, s) => p.$eval(s, e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const gorunurSay = (p, s) => p.$$eval(s, l => l.filter(e => e.getClientRects().length).length);
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 6000 }); return true; } catch (e) { return false; } };
async function yenilenmeBekle(p, n0) {
  for (let i = 0; i < 80 && p.srv.sayi(/^POST \/stok_kaydet/) <= n0; i++) await bekle(100);
  await bekle(300); await p.waitForLoadState('load');
  await kisaBekle(p, () => document.readyState === 'complete');
  await bekle(250);
}
const postSay = p => p.srv.sayi(/^POST \/stok_kaydet/);
async function modalAcik(p, id) { const r = await kisaBekle(p, i => { const m = document.getElementById(i); return m && !m.hidden && m.getBoundingClientRect().height > 50; }, id, 3000); await bekle(120); return r; }
async function detayBekle(p, id) { return await kisaBekle(p, i => { const d = document.getElementById('stDetay'); return d && d.getAttribute('data-id') === String(i) && !d.classList.contains('st-yukleniyor') && d.querySelector('.st-unvan'); }, id, 6000); }
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
const toastMetin = p => metin(p, '.st-toast:not([hidden])');
// Görünür etkileşim öğelerinden 44px altında kalanlar (kapsam içinde)
const kucukHedef = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' a, ' + k + ' button, ' + k + ' select, ' + k + ' input:not([type=hidden]):not([type=checkbox]), ' + k + ' .st-aktif, ' + k + ' .st-onay')]
  .filter(e => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]') && !e.closest('.pm-overlay[hidden]') && !e.closest('label.st-aktif, label.st-onay') )
  .map(e => { const r = e.getBoundingClientRect(); return { s: e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || e.name || '').trim().slice(0, 14), h: Math.round(r.height), w: Math.round(r.width) }; })
  .filter(x => x.h < 44 || x.w < 44), kapsam);
const kucukYazi = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' input:not([type=hidden]):not([type=checkbox]), ' + k + ' select, ' + k + ' textarea')]
  .filter(e => e.getClientRects().length).filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.id || e.type), kapsam);

(async () => {
  let browser; const sunucular = [];
  try {
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});

    // ───────────────────────────────────────── 1. KARTLAR — masaüstü 1366x800
    console.log('\n=== 1. Kartlar (1366x800) ===');
    const S1 = await ortam(); sunucular.push(S1);
    const D0 = S1.say();
    const p = await sayfa(browser, S1, { width: 1366, height: 800 });
    await git(p, 'stok_kartlari.php?id=1');
    await detayBekle(p, 1);
    ok('sayfa açıldı: başlık + detay (Domates (Salkım))', /Stok Kartları/.test(await p.title()) && (await metin(p, '#stDetay .st-unvan')) === 'Domates (Salkım)');
    ok('sidebar görünür; topbar + bottomnav gizli', await goster(p, 'aside.desktop-sidebar') && !(await goster(p, '.bottomnav')));
    const tek = await p.evaluate(() => {
      const L = document.getElementById('stListeKol').getBoundingClientRect(), D = document.getElementById('stDetay').getBoundingClientRect(), ul = document.getElementById('stListe');
      return { sayfaKayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, yanyana: L.right <= D.left + 1 && Math.abs(L.top - D.top) < 4,
        solW: Math.round(L.width), sagW: Math.round(D.width), listeKayar: getComputedStyle(ul).overflowY, altVh: Math.round(D.bottom) <= innerHeight };
    });
    ok('TEK EKRAN: sayfa kaydırılmaz, liste + detay yan yana, ikisi de ekranda', tek.sayfaKayar <= 2 && tek.yanyana && tek.altVh, JSON.stringify(tek));
    ok('sol sütun 300–380px, sağ panel ≥ 600px; liste kendi içinde kayar', tek.solW >= 300 && tek.solW <= 381 && tek.sagW >= 600 && tek.listeKayar === 'auto', JSON.stringify(tek));
    ok('yatay taşma yok', (await tasma(p)) <= 0);
    ok('bakiye kutuları: Toplam 14.300 kg + Merkez/Şube + Ort. maliyet (Değer alt satırda)', (await metin(p, '#stDetay .st-bk-kutu .st-bk-deger')) === '14.300 kg' && (await gorunurSay(p, '#stDetay .st-bk-kutu')) === 4 && /Değer \d/.test(await metin(p, '#stDetay .st-bk-alt')));
    ok('hareket tablosu görünür (≥768), 6 satır, mobil kartlar gizli', (await gorunurSay(p, '#stDetay .st-tablo tbody tr')) === 6 && (await gorunurSay(p, '#stDetay .st-hk')) === 0);
    ok('hareket tablosu kendi içinde kayar (overflow auto)', await p.$eval('#stDetay .st-hareket-kaydir', e => getComputedStyle(e).overflow.includes('auto')));
    await shot(p, 'A_pc');
    await shot(p, 'kartlar_1366');

    // süzgeç
    ok('varsayılan "Aktif" çipi: 7 görünür satır, sayaç "7 kart"', (await gorunurSay(p, '#stListe a.st-satir')) === 7 && (await metin(p, '#stSayi')) === '7 kart');
    await p.click('.st-cip[data-deger="pasif"]');
    ok('Pasif çipi: yalnız Limon (Yerli)', (await gorunurSay(p, '#stListe a.st-satir')) === 1 && /Limon/.test(await metin(p, '#stListe li:not([hidden]) .st-satir-ust')));
    await p.click('.st-cip[data-deger="kritik"]');
    ok('Kritik altı çipi: 2 satır (Patlıcan, Euro Palet), kırmızı Kritik rozetli', (await gorunurSay(p, '#stListe a.st-satir')) === 2 && (await gorunurSay(p, '#stListe .st-rozet--kritik')) === 2);
    await p.click('.st-cip[data-deger="tumu"]');
    await p.keyboard.press('Escape');
    await p.click('body', { position: { x: 700, y: 5 } }).catch(() => {});
    await p.keyboard.press('/');
    ok('"/" kısayolu aramaya odaklar', await p.evaluate(() => document.activeElement && document.activeElement.id === 'stArama'));
    await p.keyboard.type('palet');
    ok('arama "palet": 1 satır (Euro Palet); URL ?ara=palet', (await gorunurSay(p, '#stListe a.st-satir')) === 1 && /ara=palet/.test(p.url()));
    await p.keyboard.press('Escape');
    ok('Esc aramayı temizler: Tümü 8 satır', (await gorunurSay(p, '#stListe a.st-satir')) === 8);
    await p.selectOption('select[data-filtre="tip"]', 'malzeme');
    ok('tip=Malzeme: 3 satır', (await gorunurSay(p, '#stListe a.st-satir')) === 3);
    await p.selectOption('select[data-filtre="tip"]', '');

    // master-detail
    const yuk0 = S1.sayi(/^GET \/stok_kartlari\.php\?id=2$/);
    await p.click('#stListe a.st-satir[data-id="2"]');
    ok('liste satırı → detay sayfa yenilemeden (parca=detay) yüklenir; URL ?id=2', await detayBekle(p, 2) && /[?&]id=2/.test(p.url()) && S1.sayi(/parca=detay/) >= 1 && S1.sayi(/^GET \/stok_kartlari\.php\?id=2$/) === yuk0);
    ok('seçili satır aria-current; eski seçim kalktı', (await p.$eval('#stListe a.st-satir[data-id="2"]', e => e.getAttribute('aria-current'))) === 'true' && (await p.$eval('#stListe a.st-satir[data-id="1"]', e => e.getAttribute('aria-current'))) === null);
    await p.focus('#stListe a.st-satir[data-id="2"]');
    await p.keyboard.press('ArrowDown');
    ok('↓ tuşu sonraki görünür satıra odak taşır', await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('st-satir') && document.activeElement.getAttribute('data-id') !== '2'));
    await p.goBack(); await bekle(400);
    ok('tarayıcı geri → önceki kart (id=1) detayı', await detayBekle(p, 1));

    // yeni kart
    await p.click('.st-liste-ust [data-st-modal="kart"]');
    ok('+ Yeni → kart modalı açılır; başlık "Yeni stok kartı"; kod önerisi STK-0009; odak Ad alanında', await modalAcik(p, 'stModalKart') && (await metin(p, '#stKartBaslik')) === 'Yeni stok kartı'
      && (await p.inputValue('#stModalKart [name="kod"]')) === 'STK-0009' && await p.evaluate(() => document.activeElement && document.activeElement.name === 'ad'));
    const dlg = await p.$eval('#stModalKart .pm-dialog', e => { const r = e.getBoundingClientRect(); return { z: +getComputedStyle(e.closest('.pm-overlay')).zIndex, ust: r.top >= 0, alt: r.bottom <= innerHeight }; });
    ok('modal z-index ≥ 600, ekran içinde', dlg.z >= 600 && dlg.ust && dlg.alt, JSON.stringify(dlg));
    let n0 = postSay(p);
    await p.click('#stModalKart button[type="submit"]');
    ok('boş Ad → istemci uyarısı (Zorunlu alan), istek gitmez', (await metin(p, '#stModalKart .st-hata')) === 'Zorunlu alan.' && postSay(p) === n0);
    await p.fill('#stModalKart [name="ad"]', 'X');
    await p.click('#stModalKart button[type="submit"]');
    ok('kısa Ad → sunucu 422 alan hatası modalda (modal açık kalır)', await kisaBekle(p, () => { const e = document.querySelector('#stModalKart .st-hata'); return e && /en az 2/.test(e.textContent); }) && await goster(p, '#stModalKart .pm-dialog'));
    await p.fill('#stModalKart [name="ad"]', 'Test Ambalaj Bandı');
    await p.selectOption('#stModalKart [name="tip"]', 'malzeme');
    await p.selectOption('#stModalKart [name="ana_birim"]', 'adet');
    await p.selectOption('#stModalKart [name="cevrim_birim"]', 'koli');
    await p.fill('#stModalKart [name="cevrim_katsayi"]', '36');
    await p.fill('#stModalKart [name="kritik_seviye"]', '50');
    n0 = postSay(p);
    await p.keyboard.press('Control+Enter');
    await yenilenmeBekle(p, n0);
    let D = S1.say();
    const yeniId = Math.max(...Object.keys(D.kartlar).map(Number));
    ok('Ctrl+Enter kaydeder: DB +1 kart (STK-0009, adet, koli×36, kritik 50)', D.kart === D0.kart + 1 && D.kartlar[yeniId].kod === 'STK-0009' && D.kartlar[yeniId].birim === 'adet' && D.kartlar[yeniId].cevrim === 36000 && D.kartlar[yeniId].kritik === 50000, JSON.stringify(D.kartlar[yeniId]));
    ok('yeni kartın detayına gidildi (?id=yeni) + toast', /[?&]id=\d+/.test(p.url()) && (await metin(p, '#stDetay .st-unvan')) === 'Test Ambalaj Bandı' && /kaydedildi/.test(await toastMetin(p) || ''));
    ok('yeni kart: bakiye 0 adet, Kritik rozetli (0 ≤ 50), hareket yok, Sil etkin', /^0 adet/.test(await metin(p, '#stDetay .st-bk-deger')) && await goster(p, '#stDetay .st-bk-kutu--kritik') && /Henüz stok hareketi yok/.test(await metin(p, '#stDetay .st-hareket')) && await goster(p, '#stDetay [data-st-islem="kart_sil"]'));

    // düzenle (hareketli kart)
    await git(p, 'stok_kartlari.php?id=1'); await detayBekle(p, 1);
    await p.click('#stDetay [data-st-modal="kart"][data-duzenle="1"]');
    ok('Düzenle → modal değerlerle dolu (ad, kod, çevrim 8, kritik 1.000); ana birim kilidi uyarısı görünür', await modalAcik(p, 'stModalKart') && (await metin(p, '#stKartBaslik')) === 'Kartı düzenle'
      && (await p.inputValue('#stModalKart [name="ad"]')) === 'Domates (Salkım)' && (await p.inputValue('#stModalKart [name="cevrim_katsayi"]')) === '8' && (await p.inputValue('#stModalKart [name="kritik_seviye"]')) === '1.000'
      && await goster(p, '#stModalKart [data-birim-kilit]'));
    await p.selectOption('#stModalKart [name="ana_birim"]', 'adet');
    n0 = postSay(p);
    await p.click('#stModalKart button[type="submit"]');
    ok('hareketli kartın ana birimi değiştirilemez → alan hatası', await kisaBekle(p, () => { const e = document.querySelector('#stModalKart .st-hata'); return e && /kilitli/.test(e.textContent); }) && S1.say().kartlar['1'].birim === 'kg');
    await p.selectOption('#stModalKart [name="ana_birim"]', 'kg');
    await p.fill('#stModalKart [name="ad"]', 'Domates (Salkım) Ekstra');
    await p.fill('#stModalKart [name="kritik_seviye"]', '20.000');
    n0 = postSay(p);
    await p.click('#stModalKart button[type="submit"]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    ok('düzenleme kaydedildi: ad + kritik 20.000 (DB); detay yenilendi; artık KRİTİK', D.kartlar['1'].ad === 'Domates (Salkım) Ekstra' && D.kartlar['1'].kritik === 20000000 && (await metin(p, '#stDetay .st-unvan')) === 'Domates (Salkım) Ekstra' && await goster(p, '#stDetay .st-bk-kutu--kritik'));
    await p.click('#stDetay [data-st-modal="kart"][data-duzenle="1"]'); await modalAcik(p, 'stModalKart');
    await p.keyboard.press('Escape');
    ok('Esc modalı kapatır, odak Düzenle düğmesine döner', await kisaBekle(p, () => document.getElementById('stModalKart').hidden) && await p.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-duzenle') === '1'));

    // pasifleştir / aktifleştir
    n0 = postSay(p);
    await p.click('#stDetay [data-st-islem="kart_durum"]');
    await yenilenmeBekle(p, n0);
    ok('Pasifleştir: DB aktif=0, Pasif rozeti + uyarı (bakiye ≠ 0 uyarısı toast\'ta)', S1.say().kartlar['1'].aktif === 0 && /Pasif/.test(await metin(p, '#stDetay .st-baslik .st-rozet')) && /bakiyesi sıfır değil/.test(await toastMetin(p) || ''));
    n0 = postSay(p);
    await p.click('#stDetay [data-st-islem="kart_durum"]');
    await yenilenmeBekle(p, n0);
    ok('Aktifleştir: DB aktif=1', S1.say().kartlar['1'].aktif === 1 && (await metin(p, '#stDetay [data-st-islem="kart_durum"]')) === 'Pasifleştir');
    ok('hareketli kartta Sil devre dışı', await p.$eval('#stDetay .st-islemler button.st-tehlike', e => e.disabled));

    // sil (hareketsiz)
    await git(p, 'stok_kartlari.php?id=7'); await detayBekle(p, 7);
    n0 = postSay(p);
    await p.click('#stDetay [data-st-islem="kart_sil"]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    ok('Sil (onaylı): DB\'den kalktı, liste sayfasına dönüldü, toast', !D.kartlar['7'] && /stok_kartlari\.php$/.test(p.url().replace(/\?.*$/, '')) && !/id=7/.test(p.url()) && /silindi/.test(await toastMetin(p) || ''));
    ok('silinen kart listede yok', (await p.$('#stListe a.st-satir[data-id="7"]')) === null);
    ok('audit satırları arttı (create/update/deactivate/activate/delete)', D.audit >= D0.audit + 5, `${D0.audit} → ${D.audit}`);

    // ───────────────────────────────────────── 2. TANIMLAR 1366
    console.log('\n=== 2. Tanımlar (1366x800) ===');
    await git(p, 'stok_tanimlari.php');
    ok('tanımlar açıldı: 4 sekme, Gruplar paneli görünür', (await gorunurSay(p, '.st-tanim-nav-oge')) === 4 && await goster(p, '#stPanelGrup') && !(await goster(p, '#stPanelCikis')));
    ok('yatay taşma yok', (await tasma(p)) <= 0);
    await shot(p, 'tanimlar_1366');
    await p.fill('#stPanelGrup .st-tanim-ekle [name="ad"]', 'Kuru Gıda');
    n0 = postSay(p);
    await p.keyboard.press('Enter');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    ok('Enter ile grup eklendi (DB grup 4), listede görünür', D.tanim.grup === 4 && /Kuru Gıda/.test(await metin(p, '#stPanelGrup .st-tanim-liste')));
    await p.click('#stTabCikis');
    ok('Çıkış Nedenleri sekmesi: panel görünür, URL sekme=cikis_neden, 6 sistem nedeni kilitli', await goster(p, '#stPanelCikis') && !(await goster(p, '#stPanelGrup')) && /sekme=cikis_neden/.test(p.url()) && (await gorunurSay(p, '#stPanelCikis .st-rozet--kilit')) === 6);
    const fire = D.tanimlar.find(t => t.tur === 'cikis_neden' && t.ad === 'Fire');
    n0 = postSay(p);
    await p.click(`#stPanelCikis input[role="switch"][data-id="${fire.id}"]`);
    await yenilenmeBekle(p, n0);
    ok('aktif anahtarı: Fire pasifleşti (DB), panel yeniden Çıkış sekmesinde', +S1.say().tanimlar.find(t => t.id === fire.id).aktif === 0 && await goster(p, '#stPanelCikis'));
    await p.click(`#stPanelCikis li[data-id="${fire.id}"] [data-tanim-duzen]`);
    ok('sistem nedeni düzenle: ad readonly, sıra düzenlenebilir', await p.$eval(`#stPanelCikis li.st-tanim-satir--duzen:not([hidden]) [name="ad"]`, e => e.readOnly));
    await p.fill('#stPanelCikis li.st-tanim-satir--duzen:not([hidden]) [name="sira"]', '5');
    n0 = postSay(p);
    await p.click('#stPanelCikis li.st-tanim-satir--duzen:not([hidden]) button[type="submit"]');
    await yenilenmeBekle(p, n0);
    ok('sıra kaydedildi (Fire sira=5, ilk sırada)', +S1.say().tanimlar.find(t => t.id === fire.id).sira === 5 && /^Fire/.test(await metin(p, '#stPanelCikis .st-tanim-liste li:first-child .st-tanim-ad')));
    const kuru = S1.say().tanimlar.find(t => t.ad === 'Kuru Gıda');
    await git(p, 'stok_tanimlari.php?sekme=grup');
    n0 = postSay(p);
    await p.click(`#stPanelGrup li[data-id="${kuru.id}"] button.st-tehlike`);
    await yenilenmeBekle(p, n0);
    ok('kullanılmayan grup silindi (onaylı)', !S1.say().tanimlar.find(t => t.id === kuru.id));
    await p.click('#stTabIce');
    const iceN = +((await metin(p, '#stPanelIce [data-ice-aktar]') || '').match(/^(\d+) kartı içe aktar/) || [0, 0])[1];
    ok('İçe Aktar sekmesi: önizleme tablosu, "4 kartı içe aktar" (Kayısı, Köşebent, Şerit + adı değişen Domates)', await goster(p, '#stPanelIce .st-onizleme-tablo') && iceN === 4, String(iceN));
    const k0 = S1.say().kart;
    n0 = postSay(p);
    await p.click('#stPanelIce [data-ice-aktar]');
    await yenilenmeBekle(p, n0);
    ok('içe aktar: DB +4 kart, toast "4 kart eklendi", düğme artık devre dışı', S1.say().kart === k0 + 4 && /4 kart eklendi/.test(await toastMetin(p) || '') && await p.$eval('#stPanelIce [data-ice-aktar]', e => e.disabled));
    await p.context().close();

    // ───────────────────────────────────────── 3. TABLET 820
    console.log('\n=== 3. Tablet (820x1180) ===');
    const t = await sayfa(browser, S1, { width: 820, height: 1180 });
    await git(t, 'stok_kartlari.php');
    ok('[820] liste görünür, detay gizli, 2 kolon kart ızgarası, taşma yok', await goster(t, '#stListeKol') && !(await goster(t, '#stDetay')) && (await tasma(t)) <= 0
      && await t.$eval('#stListe', e => getComputedStyle(e).gridTemplateColumns.split(' ').length === 2));
    await git(t, 'stok_kartlari.php?id=1');
    ok('[820] detay: tablo görünür, ← Liste görünür, taşma yok', await goster(t, '#stDetay .st-tablo') && await goster(t, '.st-geri') && (await tasma(t)) <= 0);
    await t.context().close();

    // ───────────────────────────────────────── 4. MOBİL 390x844
    console.log('\n=== 4. Mobil (390x844) ===');
    const S4 = await ortam(); sunucular.push(S4);
    const m = await sayfa(browser, S4, { width: 390, height: 844 }, { dokunmatik: true });
    await git(m, 'stok_kartlari.php');
    ok('[390] liste: bottomnav + topbar görünür, sidebar gizli, detay gizli', await goster(m, '.bottomnav') && await goster(m, '.topbar') && !(await goster(m, 'aside.desktop-sidebar')) && await goster(m, '#stListeKol') && !(await goster(m, '#stDetay')));
    ok('[390] yatay taşma yok', (await tasma(m)) <= 0);
    let kh = await kucukHedef(m, '#stListeKol');
    ok('[390] liste: dokunma hedefleri ≥ 44px (arama, +Yeni, çipler, select, satırlar)', kh.length === 0, JSON.stringify(kh));
    let ky = await kucukYazi(m, '.stok');
    ok('[390] liste: girdiler font-size ≥ 16px', ky.length === 0, ky.join(','));
    ok('[390] liste satırı ≥ 64px yükseklik', await m.$eval('#stListe a.st-satir', e => e.getBoundingClientRect().height >= 63.5));
    await shot(m, 'A_mobil');
    await shot(m, 'kartlar_390_liste');
    await m.tap('#stListe a.st-satir[data-id="1"]');
    await m.waitForLoadState('load');
    ok('[390] satıra dokun → tam sayfa detay (?id=1), liste gizli, ← Liste görünür', await detayBekle(m, 1) && /id=1/.test(m.url()) && !(await goster(m, '#stListeKol')) && await goster(m, '.st-geri'));
    ok('[390] detay: taşma yok; hareketler KART görünümünde (tablo gizli)', (await tasma(m)) <= 0 && (await gorunurSay(m, '#stDetay .st-hk')) === 6 && !(await goster(m, '#stDetay .st-hareket-kaydir')));
    await m.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await bekle(200);
    const bar = await m.evaluate(() => { const b = document.querySelector('#stDetay .st-islemler').getBoundingClientRect(), bn = document.querySelector('.bottomnav').getBoundingClientRect(); return { altBar: Math.round(b.bottom), ustBn: Math.round(bn.top), gor: b.height > 0 && b.top >= 0, h: Math.round(b.height) }; });
    ok('[390] alt sabit eylem çubuğu bottomnav\'ın ÜSTÜNDE (çakışma yok)', bar.gor && bar.altBar <= bar.ustBn + 1, JSON.stringify(bar));
    kh = await kucukHedef(m, '#stDetay');
    ok('[390] detay: dokunma hedefleri ≥ 44px (Düzenle/Pasifleştir/Sil, ← Liste, fiş bağlantıları hariç metin içi)', kh.filter(x => !/^A\.:(GRS|CKS)/.test(x.s)).length === 0, JSON.stringify(kh));
    await shot(m, 'kartlar_390_detay');
    await m.tap('#stDetay [data-st-modal="kart"][data-duzenle="1"]');
    await modalAcik(m, 'stModalKart'); await bekle(300);
    const mm = await m.evaluate(() => {
      const d = document.querySelector('#stModalKart .pm-dialog'), f = d.querySelector('.pm-footer').getBoundingClientRect(), b = d.querySelector('.pm-body');
      const kucuk = [...d.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select, textarea')].filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name);
      const dok = [...d.querySelectorAll('.btn, .pm-close')].filter(e => e.getClientRects().length).map(e => e.getBoundingClientRect()).filter(r => r.height < 44 || r.width < 44).length;
      b.scrollTop = b.scrollHeight;
      const son = d.querySelector('[name="notlar"]').getBoundingClientRect();
      return { footAlt: Math.round(f.bottom), vh: innerHeight, kucuk, dok, kaydi: b.scrollTop > 0, sonGor: son.bottom <= f.top + 1 && son.top >= 0, tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    ok('[390] modal: footer ekranda, gövde kaydırılır, son alan (Notlar) footer üstünde', mm.footAlt <= mm.vh + 1 && mm.kaydi && mm.sonGor, JSON.stringify(mm));
    ok('[390] modal: girdiler ≥ 16px, düğmeler ≥ 44px, taşma yok', mm.kucuk.length === 0 && mm.dok === 0 && mm.tasma <= 0, JSON.stringify(mm));
    await shot(m, 'kart_modal_390');
    await m.tap('#stModalKart .pm-close');
    await kisaBekle(m, () => document.getElementById('stModalKart').hidden);
    await m.tap('.st-geri');
    await m.waitForLoadState('load');
    ok('[390] ← Liste → liste geri gelir', await kisaBekle(m, () => document.getElementById('stListeKol').getClientRects().length > 0 && !document.getElementById('stDetay').getClientRects().length));
    await git(m, 'stok_tanimlari.php');
    ok('[390] tanımlar: taşma yok, girdiler ≥ 16px', (await tasma(m)) <= 0 && (await kucukYazi(m, '.stok')).length === 0);
    kh = await kucukHedef(m, '#stPanelGrup');
    ok('[390] tanımlar: dokunma hedefleri ≥ 44px', kh.length === 0, JSON.stringify(kh));
    await shot(m, 'tanimlar_390');
    await m.context().close();

    // ───────────────────────────────────────── 5. ŞEMA YOK / BOŞ
    console.log('\n=== 5. Şema yok / boş ===');
    const S5 = await ortam('sema'); sunucular.push(S5);
    const s = await sayfa(browser, S5, { width: 1366, height: 800 });
    await git(s, 'stok_kartlari.php');
    ok('şema yok: uyarı kartı + migrate.php#stok bağlantısı', /Stok tabloları kurulmamış/.test(await metin(s, '.st-uyari') || '') && await goster(s, '.st-uyari a[href*="migrate.php#stok"]'));
    await s.context().close();
    const S6 = await ortam('bos'); sunucular.push(S6);
    const b = await sayfa(browser, S6, { width: 1366, height: 800 });
    await git(b, 'stok_kartlari.php?yeni=1');
    ok('boş: "Henüz stok kartı yok"; ?yeni=1 modalı açık gelir (kod STK-0001); URL\'den yeni silinir', /Henüz stok kartı yok/.test(await metin(b, '.st-bos') || '') && await modalAcik(b, 'stModalKart')
      && (await b.inputValue('#stModalKart [name="kod"]')) === 'STK-0001' && !/yeni=1/.test(b.url()));
    await b.fill('#stModalKart [name="ad"]', 'İlk Kart');
    n0 = b.srv.sayi(/^POST \/stok_kaydet/);
    await b.click('#stModalKart button[type="submit"]');
    for (let i = 0; i < 80 && b.srv.sayi(/^POST \/stok_kaydet/) <= n0; i++) await bekle(100);
    await bekle(400); await b.waitForLoadState('load');
    ok('boş DB\'de ilk kart kaydedildi → detayı açık', S6.say().kart === 1 && await detayBekle(b, 1));
    await b.context().close();

    // ───────────────────────────────────────── 6. FİŞLER — masaüstü 1366x800 (Parça B)
    console.log('\n=== 6. Fişler (1366x800) ===');
    const S7 = await ortam('fisler'); sunucular.push(S7);
    const E0 = S7.say();
    const fp = await sayfa(browser, S7, { width: 1366, height: 800 });
    const fpost = () => S7.sayi(/^POST \/stok_kaydet/);
    const kartYaz = async (p, sec, metin) => { await p.fill(sec, metin); await p.press(sec, 'Enter'); };
    await git(fp, 'stok_giris_fisleri.php?yeni=1');
    await kisaBekle(fp, () => document.activeElement && document.activeElement.classList.contains('st-kart-ara'));
    const ft = await fp.evaluate(() => {
      const L = document.getElementById('stListeKol').getBoundingClientRect(), D = document.getElementById('stDetay').getBoundingClientRect(), bar = document.querySelector('.st-eylem-bar').getBoundingClientRect();
      return { kayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, yanyana: L.right <= D.left + 1, solW: Math.round(L.width), sagW: Math.round(D.width), barIc: bar.bottom <= innerHeight && bar.top > 0 };
    });
    ok('[1366] giriş formu: TEK EKRAN (sayfa kaymaz), liste + form yan yana, eylem çubuğu ekranda; odak ilk kart alanında', ft.kayar <= 2 && ft.yanyana && ft.barIc && ft.sagW >= 620, JSON.stringify(ft));
    ok('[1366] taşma yok; satır tablosu başlıklı (thead görünür)', (await tasma(fp)) <= 0 && await goster(fp, '.st-ftablo thead'));
    await fp.selectOption('#stFisForm [name="neden_id"]', { label: 'Alış' });
    await fp.fill('#stFisForm [name="evrak_no"]', 'IRS-E2E');
    await kartYaz(fp, '.st-fsatir:nth-child(1) .st-kart-ara', 'STK-0002');
    ok('Enter: kod → kart çözüldü ("STK-0002 — Biber (Dolmalık)"), bakiye ipucu, odak Miktar\'da', (await fp.inputValue('.st-fsatir:nth-child(1) .st-kart-ara')) === 'STK-0002 — Biber (Dolmalık)'
      && /Mevcut: 2\.570 kg/.test(await metin(fp, '.st-fsatir:nth-child(1) .st-fs-ipucu') || '') && await fp.evaluate(() => document.activeElement.name === 'miktar[]'), await metin(fp, '.st-fsatir:nth-child(1) .st-fs-ipucu'));
    await fp.keyboard.type('100'); await fp.keyboard.press('Enter');
    ok('Enter: Miktar → Birim', await fp.evaluate(() => document.activeElement.name === 'birim[]'));
    await fp.keyboard.press('Enter'); await fp.keyboard.type('20'); await fp.keyboard.press('Enter');
    ok('Enter: Birim → Fiyat → KDV; satır tutarı 2.000,00', await fp.evaluate(() => document.activeElement.name === 'kdv_orani[]') && (await metin(fp, '.st-fsatir:nth-child(1) .st-fs-tutar-deger')) === '2.000,00');
    await fp.keyboard.press('Enter');
    ok('son hücrede Enter → YENİ SATIR + odak yeni satırın kart alanında', (await gorunurSay(fp, '#stFisForm .st-fsatir')) === 2 && await fp.evaluate(() => document.activeElement.classList.contains('st-kart-ara') && document.activeElement.closest('.st-fsatir') === document.querySelectorAll('.st-fsatir')[1]));
    await kartYaz(fp, '.st-fsatir:nth-child(2) .st-kart-ara', 'STK-0004');
    await fp.keyboard.type('50'); await fp.keyboard.press('Enter'); await fp.keyboard.press('Enter'); await fp.keyboard.type('1,5');
    ok('toplamlar: miktar 150 · tutar 2.075,00 · KDV 35,00 (kart KDV\'leri %1 / %20)', (await metin(fp, '[data-top="miktar"]')) === '150' && (await metin(fp, '[data-top="tutar"]')) === '2.075,00' && (await metin(fp, '[data-top="kdv"]')) === '35,00',
      [await metin(fp, '[data-top="miktar"]'), await metin(fp, '[data-top="tutar"]'), await metin(fp, '[data-top="kdv"]')].join(' | '));
    await shot(fp, 'B_form_pc');
    n0 = fpost();
    await fp.keyboard.press('Control+s');
    await yenilenmeBekle(fp, n0);
    let E = S7.say(); let son = E.fisler[E.fisler.length - 1];
    ok('Ctrl+S kaydeder: DB +1 giriş fişi (evrak IRS-E2E), detayına gidildi (?id=), toast fiş no', E.fis === E0.fis + 1 && son.tur === 'giris' && new RegExp('id=' + son.id).test(fp.url()) && (await metin(fp, '#stDetay .st-unvan')) === son.fis_no && new RegExp(son.fis_no).test(await toastMetin(fp) || ''), JSON.stringify(son));
    ok('bakiye arttı: Biber Merkez 2.570 → 2.670 kg, Kasa +50', E.kartlar['2'].depo['Merkez Depo'] === 2670000 && E.kartlar['4'].depo['Merkez Depo'] === E0.kartlar['4'].depo['Merkez Depo'] + 50000);
    ok('detay: 2 satır, tutar sütunları, liste başında yeni fiş seçili', (await gorunurSay(fp, '#stDetay .st-fis-tablo tbody tr')) === 2 && (await fp.$eval('#stListe a.st-satir[aria-current="true"]', e => e.getAttribute('data-id'))) === String(son.id));
    const girisId = son.id;

    // master-detail (fiş listesi)
    const ilkFis = E.fisler.find(f => f.tur === 'giris' && f.id !== girisId);
    await fp.click(`#stListe a.st-satir[data-id="${ilkFis.id}"]`);
    ok('fiş listesi satırı → detay sayfa yenilemeden (parca=detay); URL ?id=', await detayBekle(fp, ilkFis.id) && new RegExp('id=' + ilkFis.id).test(fp.url()) && S7.sayi(/parca=detay/) >= 1);

    // iptal
    await git(fp, 'stok_giris_fisleri.php?id=' + girisId); await detayBekle(fp, girisId);
    await fp.click('#stDetay [data-st-modal="iptal"]');
    ok('İptal et → modal (z ≥ 600), başlıkta fiş no, "Fişi iptal et" neden girilene dek kapalı', await modalAcik(fp, 'stModalIptal') && (await metin(fp, '#stModalIptal [data-iptal-no]')) === son.fis_no
      && await fp.$eval('#stModalIptal [data-iptal-onay]', e => e.disabled) && +(await fp.$eval('#stModalIptal', e => getComputedStyle(e).zIndex)) >= 600);
    await fp.fill('#stModalIptal [name="neden"]', 'Test iptali');
    ok('neden ≥ 3 → düğme etkin', !(await fp.$eval('#stModalIptal [data-iptal-onay]', e => e.disabled)));
    n0 = fpost();
    await fp.click('#stModalIptal [data-iptal-onay]');
    await yenilenmeBekle(fp, n0);
    E = S7.say();
    ok('iptal: DB durum iptal, Biber bakiyesi geri (2.570), detayda İPTAL şeridi', E.fisler.find(f => f.id === girisId).durum === 'iptal' && E.kartlar['2'].depo['Merkez Depo'] === 2570000 && await goster(fp, '#stDetay .st-iptal-serit'));
    await fp.click('#stDetay [data-kopya]'); await fp.waitForLoadState('load');
    ok('Kopyala → yeni form, satırlar dolu (2), "kopyalandı" bilgisi', /kopya=/.test(fp.url()) && (await gorunurSay(fp, '#stFisForm .st-fsatir')) === 2 && (await fp.inputValue('.st-fsatir:nth-child(1) .st-kart-ara')) === 'STK-0002 — Biber (Dolmalık)' && /kopyalandı/.test(await metin(fp, '[data-kaynak-bilgi]') || ''));

    // yetersiz stok → Yine de kaydet
    await git(fp, 'stok_cikis_fisleri.php?yeni=1');
    ok('çıkış formu: cari yokken fiyat sütunu gizli', !(await goster(fp, '#stFisForm .st-fs-fiyat')));
    await fp.selectOption('#stFisForm [name="neden_id"]', { label: 'Satış/Sevk' });
    await kartYaz(fp, '.st-fsatir:nth-child(1) .st-kart-ara', 'STK-0003');
    await fp.keyboard.type('500');
    ok('miktar > bakiye: satır kırmızı + "yetersiz" ipucu', await fp.$eval('.st-fsatir', e => e.classList.contains('st-fsatir--eksik')) && /yetersiz/.test(await metin(fp, '.st-fs-ipucu') || ''));
    n0 = fpost();
    await fp.click('#stFisForm [data-kaydet]');
    ok('Kaydet → 409: "Yetersiz stok" kutusu (Patlıcan, Mevcut 240, Eksik 260), kayıt yok', await kisaBekle(fp, () => !document.getElementById('stEksik').hidden) && /240 kg/.test(await metin(fp, '#stEksik tbody') || '') && /260 kg/.test(await metin(fp, '#stEksik tbody') || '') && S7.say().fis === E.fis,
      await metin(fp, '#stEksik'));
    ok('odak "Düzelt" düğmesinde', await fp.evaluate(() => document.activeElement && document.activeElement.hasAttribute('data-eksik-duzelt')));
    n0 = fpost();
    await fp.click('#stEksik [data-eksik-kaydet]');
    await yenilenmeBekle(fp, n0);
    E = S7.say(); son = E.fisler[E.fisler.length - 1];
    ok('"Yine de kaydet": DB çıkış fişi + uyarı, bakiye −260, toast "Eksiye"', son.tur === 'cikis' && /Eksiye/.test(son.uyari || '') && E.kartlar['3'].depo['Merkez Depo'] === -260000 && /Eksiye/.test(await toastMetin(fp) || ''));

    // transfer
    await git(fp, 'transfer_fisleri.php?yeni=1');
    await fp.selectOption('#stFisForm [name="hedef_depo"]', 'Şube Depo');
    await kartYaz(fp, '.st-fsatir:nth-child(1) .st-kart-ara', 'STK-0001');
    await fp.keyboard.type('100');
    n0 = fpost();
    await fp.click('#stFisForm [data-kaydet]');
    await yenilenmeBekle(fp, n0);
    E = S7.say(); son = E.fisler[E.fisler.length - 1];
    ok('transfer kaydedildi: Domates Merkez −100, Şube +100; detay "Merkez Depo → Şube Depo"', son.tur === 'transfer' && E.kartlar['1'].depo['Merkez Depo'] === 5450000 && E.kartlar['1'].depo['Şube Depo'] === 8350000 && /Merkez Depo → hedef Şube Depo|Merkez Depo.*Şube Depo/.test(await metin(fp, '#stDetay .st-meta') || ''),
      JSON.stringify(E.kartlar['1'].depo));
    await shot(fp, 'B_transfer_pc');
    const trfTek = await fp.evaluate(() => ({ kayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth }));
    ok('[1366] transfer detayı: sayfa kaymaz, taşma yok', trfTek.kayar <= 2 && trfTek.tasma <= 0, JSON.stringify(trfTek));

    // dönüşüm
    await git(fp, 'stok_donusum_fisleri.php?yeni=1');
    await kartYaz(fp, '.st-satirlar[data-yon="C"] .st-kart-ara', 'STK-0001');
    await fp.keyboard.type('10');
    await kartYaz(fp, '.st-satirlar[data-yon="G"] .st-kart-ara', 'STK-0002');
    await fp.keyboard.type('9');
    ok('dönüşüm formu: fire özeti 1 (%10), çıktı birim maliyeti tahmini', /^1 \(%10\)/.test(await metin(fp, '[data-top="fark"]') || '') && /₺/.test(await metin(fp, '[data-top="cikti_ort"]') || ''), await metin(fp, '[data-fire]'));
    n0 = fpost();
    await fp.click('#stFisForm [data-kaydet]');
    await yenilenmeBekle(fp, n0);
    E = S7.say(); son = E.fisler[E.fisler.length - 1];
    ok('dönüşüm kaydedildi (DNS-), detayda Girdiler + Çıktılar', son.tur === 'donusum' && /^DNS-/.test(son.fis_no) && await goster(fp, '#stDetay .st-donusum-kol--girdi') && await goster(fp, '#stDetay .st-donusum-kol--cikti'));

    // cari seçili alış + kantardan doldur
    await git(fp, 'stok_giris_fisleri.php?yeni=1');
    await fp.click('#stFisForm [data-st-modal="kantar"]');
    ok('Kantardan doldur → modal; 3 aday (Şube kantarı da kısıtsız oturumda)', await modalAcik(fp, 'stModalKantar') && (await gorunurSay(fp, '#stModalKantar .st-kaynak-satir')) === 3);
    await fp.fill('#stKantarAra', 'karpuz');
    ok('modal araması süzer (karpuz → 1)', (await gorunurSay(fp, '#stModalKantar .st-kaynak-satir')) === 1);
    await fp.fill('#stKantarAra', '');
    const k1 = E.kantar.find(k => k.fis_no === '1201');
    await fp.check(`#stModalKantar input[name="kantar_id"][value="${k1.id}"]`);
    await fp.click('#stModalKantar button[type="submit"]');
    ok('Seç → form doldu: Domates, 6.120 kg, cari Örnek Tarım, bilgi şeridi; modal kapandı', await kisaBekle(fp, () => document.getElementById('stModalKantar').hidden) && (await fp.inputValue('.st-fsatir:nth-child(1) .st-kart-ara')) === 'STK-0001 — Domates (Salkım)'
      && (await fp.inputValue('.st-fsatir:nth-child(1) [name="miktar[]"]')) === '6.120' && /Örnek Tarım/.test(await fp.inputValue('#stFisForm [name="cari_ara"]')) && /Kantar #1201/.test(await metin(fp, '[data-kaynak-bilgi]') || '')
      && (await fp.inputValue('#stFisForm [name="kaynak_id"]')) === String(k1.id));
    await fp.selectOption('#stFisForm [name="neden_id"]', { label: 'Alış' });
    n0 = fpost();
    await fp.click('#stFisForm [data-kaydet]');
    ok('cari seçili + fiyat yok → satır hatası (birim fiyat zorunlu), kayıt yok', await kisaBekle(fp, () => !!document.querySelector('#stFisForm .st-fsatir .st-hata')) && S7.say().fis === E.fis);
    await fp.fill('.st-fsatir:nth-child(1) [name="birim_fiyat[]"]', '9,50');
    n0 = fpost();
    await fp.click('#stFisForm [data-kaydet]');
    await yenilenmeBekle(fp, n0);
    let E2 = S7.say(); son = E2.fisler[E2.fisler.length - 1];
    ok('kantar + cari alış kaydedildi: kaynak kantar, cari hareketi +1 (alacak dekontu)', son.kaynak_modul === 'kantar' && +son.kaynak_id === k1.id && son.cari_hareket_id && E2.cari_hareket === E.cari_hareket + 1, JSON.stringify(son));
    await git(fp, 'stok_giris_fisleri.php?yeni=1');
    await fp.click('#stFisForm [data-st-modal="kantar"]'); await modalAcik(fp, 'stModalKantar');
    ok('aynı kantar fişi artık "Aktarıldı" ve seçilemez', await fp.$eval(`#stModalKantar input[name="kantar_id"][value="${k1.id}"]`, e => e.disabled) && /Aktarıldı/.test(await metin(fp, '#stModalKantar .st-kaynak-satir--pasif') || ''));
    await fp.keyboard.press('Escape');
    ok('Esc kantar modalını kapatır', await kisaBekle(fp, () => document.getElementById('stModalKantar').hidden));
    ok('hareket satırı fiş no → kart detayından fiş sayfasına', (await (async () => { await git(fp, 'stok_kartlari.php?id=1'); return !!(await fp.$('#stDetay a[href^="transfer_fisleri.php?id="]')); })()));
    await fp.context().close();

    // ───────────────────────────────────────── 7. FİŞ — mobil 390x844
    console.log('\n=== 7. Fiş formu mobil (390x844) ===');
    const fm = await sayfa(browser, S7, { width: 390, height: 844 }, { dokunmatik: true });
    await git(fm, 'stok_giris_fisleri.php');
    ok('[390] fiş listesi: tam ekran, form/detay gizli, taşma yok', await goster(fm, '#stListeKol') && !(await goster(fm, '#stDetay')) && (await tasma(fm)) <= 0);
    await git(fm, 'stok_giris_fisleri.php?yeni=1');
    ok('[390] form: liste gizli, ← Liste görünür, 3 adım başlığı, taşma yok', !(await goster(fm, '#stListeKol')) && await goster(fm, '.st-geri') && (await gorunurSay(fm, '#stFisForm .st-adim')) >= 3 && (await tasma(fm)) <= 0);
    ok('[390] satır = kart (thead gizli; Miktar/Birim etiketleri görünür)', !(await goster(fm, '.st-ftablo thead')) && await goster(fm, '.st-fsatir .st-fs-etiket'));
    ky = await kucukYazi(fm, '#stFisForm');
    ok('[390] form girdileri ≥ 16px', ky.length === 0, ky.join(','));
    kh = await kucukHedef(fm, '#stDetay');
    ok('[390] form dokunma hedefleri ≥ 44px', kh.length === 0, JSON.stringify(kh));
    const mbar = await fm.evaluate(() => { const b = document.querySelector('.st-eylem-bar').getBoundingClientRect(), bn = document.querySelector('.bottomnav').getBoundingClientRect(); return { alt: Math.round(b.bottom), bnUst: Math.round(bn.top), gor: b.top >= 0 && b.height > 0 }; });
    ok('[390] alt eylem çubuğu (toplam + Kaydet) bottomnav\'ın ÜSTÜNDE, ekranda', mbar.gor && mbar.alt <= mbar.bnUst + 1, JSON.stringify(mbar));
    await shot(fm, 'B_form_mobil');
    await fm.selectOption('#stFisForm [name="neden_id"]', { label: 'Sayım Fazlası' });
    await fm.fill('.st-fsatir:nth-child(1) .st-kart-ara', 'STK-0005 — Euro Palet');
    await fm.fill('.st-fsatir:nth-child(1) [name="miktar[]"]', '14');
    await fm.tap('#stFisForm [data-satir-ekle]');
    ok('[390] ＋ Satır ekle → 2. kart', (await gorunurSay(fm, '#stFisForm .st-fsatir')) === 2);
    await fm.tap('.st-fsatir:nth-child(2) [data-satir-sil]');
    ok('[390] ✕ satırı siler', (await gorunurSay(fm, '#stFisForm .st-fsatir')) === 1);
    n0 = fm.srv.sayi(/^POST \/stok_kaydet/);
    await fm.tap('#stFisForm [data-kaydet]');
    await yenilenmeBekle(fm, n0);
    ok('[390] dokunarak kaydedildi → fiş detayı (tam ekran), Euro Palet +14', /id=\d+/.test(fm.url()) && await goster(fm, '#stDetay .st-unvan') && S7.say().kartlar['5'].depo['Merkez Depo'] === (E2.kartlar['5'].depo['Merkez Depo'] || 0) + 14000, fm.url() + ' | ' + (await metin(fm, '.st-form-hata') || '') + ' | ' + JSON.stringify(S7.say().kartlar['5'].depo) + ' ' + JSON.stringify(E2.kartlar['5'].depo));
    ok('[390] fiş detayı: satırlar kart görünümünde, taşma yok', (await gorunurSay(fm, '#stDetay .st-hk')) === 1 && (await tasma(fm)) <= 0);
    await fm.context().close();

    ok('köprü: PHP alt süreç hatası yok', !sunucular.some(x => x.istekler.some(i => i.hata)), JSON.stringify(sunucular.flatMap(x => x.istekler.filter(i => i.hata)).slice(0, 3)));
    ok('tarayıcı: JS hatası / 5xx yok', hatalar.length === 0, hatalar.slice(0, 5).join(' | '));
  } catch (e) {
    fail++; console.log('*** İSTİSNA: ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    for (const s of sunucular) await s.kapat();
    try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* önemsiz */ }
  }
  console.log(`\n${n} adım, ${fail} başarısız`);
  process.exit(fail ? 1 : 0);
})();
