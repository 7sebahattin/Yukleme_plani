// =========================================================
// scripts/kasa_ui_smoke.js — "Kasa" TARAYICI uçtan uca testi (gerçek PHP + gerçek assets/kasa.js)
//   NODE_PATH=/opt/node-tools/node_modules node scripts/kasa_ui_smoke.js   → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   KASA_SHOT=<klasör>   → ekran görüntüleri (pc_liste.png, pc_form.png, mobil_liste.png, mobil_form.png, tanimlar_pc.png …; repoya konmaz)
//
// Sahte yanıt YOK: scripts/_kasa_e2e_sunucu.js her isteği scripts/_kasa_istek.php alt süreciyle GERÇEK kasa_*.php sayfalarında çalıştırır
// (gerçek config/helpers.php kabuğu + kasa_core + kasa_ui) ve assets/'i diskten sunar. Durum dosya SQLite'ta kalıcıdır; DB gerçeği
// scripts/kasa_ui_e2e_say.php ile okunur. Canlı DB'ye / migrasyona HİÇ dokunulmaz.
// Bölümler: 1 masaüstü 1366 (tek ekran, liste) · 2 uçtan uca (kasa kartı ekle → tahsilat (cari bağlı) → gider → virman → yetersiz bakiye "Yine de kaydet" → iptal) ·
//           3 tanımlar 1366 · 4 tablet 820 · 5 mobil 390 (kartlar, alt çubuk, ≥44px, ≥16px, modal kaydırma) · 6 şema yok / hiç kasa yok.
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
const { baslat } = require('./_kasa_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'kasa_ui_'));
const SHOT = process.env.KASA_SHOT || '';
let fail = 0, n = 0, dbNo = 0;
let sonAd = '';
const ok = (ad, k, ip) => { n++; sonAd = ad.slice(0, 70); if (!k) fail++; console.log(ad.padEnd(100) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];

async function ortam(mod) {
  const db = path.join(TMP, 'kasa' + (++dbNo) + '.sqlite');
  execFileSync('php', [path.join(__dirname, 'kasa_ui_e2e_setup.php'), db, mod || 'fisler'], { cwd: ROOT, stdio: 'pipe', timeout: 60000 });
  const srv = await baslat({ db, sid: 'kasaE2E' + dbNo, depolar: ['Merkez Depo'] });
  srv.say = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'kasa_ui_e2e_say.php'), db], { cwd: ROOT, timeout: 60000 }).toString());
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
  p.setDefaultTimeout(20000); p.srv = srv; p.url0 = srv.url;
  return p;
}
const git = (p, yol) => p.goto(p.url0 + '/' + yol, { waitUntil: 'load' });
const tasma = p => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const goster = (p, s) => p.$eval(s, e => !!(e.getClientRects().length) && getComputedStyle(e).visibility !== 'hidden').catch(() => false);
const metin = (p, s) => p.$eval(s, e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const gorunurSay = (p, s) => p.$$eval(s, l => l.filter(e => e.getClientRects().length).length);
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 6000 }); return true; } catch (e) { return false; } };
const postSay = p => p.srv.sayi(/^POST \/kasa_kaydet/);
async function yenilenmeBekle(p, n0) {
  for (let i = 0; i < 80 && postSay(p) <= n0; i++) await bekle(100);
  await bekle(300); await p.waitForLoadState('load');
  await kisaBekle(p, () => document.readyState === 'complete');
  await bekle(250);
}
async function modalAcik(p, id) { const r = await kisaBekle(p, i => { const m = document.getElementById(i); return m && !m.hidden && m.getBoundingClientRect().height > 50; }, id, 3000); await bekle(450); return r; }   // açılış animasyonu 220ms
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
const toastMetin = p => metin(p, '.ks-toast:not([hidden])');
const kucukHedef = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' a, ' + k + ' button, ' + k + ' select, ' + k + ' input:not([type=hidden]):not([type=checkbox])')]
  .filter(e => e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.closest('[hidden]') && !e.closest('.pm-overlay[hidden]') && !e.closest('.ks-kasa-liste') && !e.closest('.ks-tablo') && !e.closest('.ks-sekmeler'))
  .map(e => { const r = e.getBoundingClientRect(); return { s: e.tagName + '.' + String(e.className).split(' ')[0] + ':' + (e.textContent || e.name || '').trim().slice(0, 14), h: Math.round(r.height), w: Math.round(r.width) }; })
  .filter(x => x.h < 44 || x.w < 44), kapsam);
const kucukYazi = (p, kapsam) => p.evaluate(k => [...document.querySelectorAll(k + ' input:not([type=hidden]):not([type=checkbox]), ' + k + ' select, ' + k + ' textarea')]
  .filter(e => e.getClientRects().length).filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.id || e.type), kapsam);

// ── Sağlamlık: hiçbir bekleme sonsuza dek sürmez ─────────────────────────────────────────────
let browser; const sunucular = [];
async function temizle() {   // tarayıcı + köprü sunucuları + geçici klasör; her adım zaman sınırlı (asılırsa atlanır)
  if (browser) await Promise.race([browser.close().catch(() => {}), bekle(10000)]);
  for (const s of sunucular) { try { await Promise.race([s.kapat(), bekle(3000)]); } catch (e) { /* kapanış */ } }
  try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* geçici */ }
}
const SURE_MS = +(process.env.KASA_UI_SURE_MS || 240000);   // tüm koşu için üst sınır
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

    // ───────────────────────────── 1. MASAÜSTÜ 1366x800
    console.log('\n=== 1. Fişler (1366x800) ===');
    const S1 = await ortam('fisler'); sunucular.push(S1);
    const D0 = S1.say();
    const KID = Object.keys(D0.kasalar).find(i => D0.kasalar[i].ad === 'Merkez Kasa');
    const BID = Object.keys(D0.kasalar).find(i => D0.kasalar[i].ad === 'Örnek Banka TL');
    const DID = Object.keys(D0.kasalar).find(i => D0.kasalar[i].ad === 'Döviz Kasası');
    const p = await sayfa(browser, S1, { width: 1366, height: 800 });
    await git(p, 'kasa_fisleri.php?kasa=' + KID);
    ok('sayfa açıldı: başlık Kasa Fişleri, kasa başlığı Merkez Kasa', /Kasa Fişleri/.test(await p.title()) && (await metin(p, '.ks-unvan')) === 'Merkez Kasa');
    ok('sidebar görünür; topbar + bottomnav gizli', await goster(p, 'aside.desktop-sidebar') && !(await goster(p, '.bottomnav')));
    const tek = await p.evaluate(() => {
      const L = document.getElementById('ksSol').getBoundingClientRect(), D = document.querySelector('.ks-sag').getBoundingClientRect(), ul = document.getElementById('ksKasaListe');
      return { sayfaKayar: document.documentElement.scrollHeight - document.documentElement.clientHeight, yanyana: L.right <= D.left + 1 && Math.abs(L.top - D.top) < 4, solW: Math.round(L.width), sagW: Math.round(D.width), altVh: Math.round(D.bottom) <= innerHeight, listeKayar: getComputedStyle(ul).overflowY };
    });
    ok('TEK EKRAN: sayfa kaydırılmaz, kasalar + liste yan yana, ikisi de ekranda', tek.sayfaKayar <= 2 && tek.yanyana && tek.altVh, JSON.stringify(tek));
    ok('sol sütun 260–330px, sağ panel ≥ 700px; kasa listesi kendi içinde kayar', tek.solW >= 260 && tek.solW <= 331 && tek.sagW >= 700 && tek.listeKayar === 'auto', JSON.stringify(tek));
    ok('yatay taşma yok', (await tasma(p)) <= 0);
    ok('bakiye: Merkez Kasa 81.325,50 ₺ (başlıkta ve sol listede)', /81\.325,50/.test(await metin(p, '.ks-bakiye-deger')) && /81\.325,50/.test(await metin(p, '#ksKasaListe a[data-id="' + KID + '"]')));
    ok('sol: 3 kasa + Tüm kasalar (Şube Kasası depo dışı gizli); genel özet TRY ve USD AYRI kutular', (await gorunurSay(p, '#ksKasaListe a.ks-kasa')) === 4 && (await gorunurSay(p, '.ks-genel-kutu')) === 2);
    ok('tablo görünür (≥768), 6 geçerli satır (iptalli yok), mobil kartlar gizli', (await gorunurSay(p, '.ks-tablo tbody tr')) === 6 && (await gorunurSay(p, '.ks-hk')) === 0, String(await gorunurSay(p, '.ks-tablo tbody tr')));
    ok('liste alanı kendi içinde kayar; mobil alt çubuk gizli', await p.$eval('.ks-liste-govde', e => /auto|scroll/.test(getComputedStyle(e).overflowY)) && !(await goster(p, '.ks-eylem-bar')));
    ok('yürüyen bakiye sütunu: ilk satırın bakiyesi = kasa bakiyesi', /81\.325,50/.test(await metin(p, '.ks-tablo tbody tr:first-child td:last-child')));
    await shot(p, 'pc_liste');
    await p.click('#ksKasaListe a[data-id="0"]');
    await p.waitForLoadState('load');
    ok('Tüm kasalar: Kasa sütunu görünür, bakiye sütunu yok, taşma yok', (await gorunurSay(p, '.ks-tablo thead th')) === 6 && (await tasma(p)) <= 0);
    await shot(p, 'pc_tum_kasalar');
    await git(p, 'kasa_fisleri.php?kasa=' + KID);

    // süzgeç
    await p.selectOption('select[name="tur"]', 'gider');
    await p.waitForLoadState('load'); await bekle(300);
    ok('tür=gider süzgeci (otomatik gönder): yalnız gider; URL ?tur=gider', /tur=gider/.test(p.url()) && (await gorunurSay(p, '.ks-tablo tbody tr')) === 1);
    await p.click('.ks-cip:has-text("İptal")');
    await p.waitForLoadState('load');
    await git(p, 'kasa_fisleri.php?kasa=' + KID + '&durum=iptal');
    ok('durum=iptal: yalnız iptalli hareket (Hatalı giriş), İPTAL rozeti', (await gorunurSay(p, '.ks-tablo tbody tr')) === 1 && /İPTAL/.test(await metin(p, '.ks-tablo tbody tr')));
    ok('iptal sekmesi özeti süzgeçle tutarlı: "İptal edilen" etiketi, çıkış 10,00 (geçerli toplam DEĞİL), Net yok', /İptal edilen/.test(await metin(p, '#ksOzet')) && /10,00/.test(await metin(p, '#ksOzet')) && !/Net/.test(await metin(p, '#ksOzet')) && !/81\.325/.test(await metin(p, '#ksOzet')), await metin(p, '#ksOzet'));
    await git(p, 'kasa_fisleri.php?kasa=' + KID + '&durum=tumu');
    ok('tümü sekmesi özeti: etiket "Geçerli hareketler" (iptaller toplanmaz bilgisi)', /Geçerli hareketler/.test(await metin(p, '#ksOzet')), await metin(p, '#ksOzet'));
    await git(p, 'kasa_fisleri.php?kasa=' + KID);
    await p.keyboard.press('/');
    ok('"/" kısayolu aramaya odaklar', await p.evaluate(() => document.activeElement && document.activeElement.id === 'ksArama'));

    // ───────────────────────────── 2. UÇTAN UCA (PC)
    console.log('\n=== 2. Uçtan uca (1366): tahsilat (cari bağlı) → gider → virman → yetersiz bakiye → iptal ===');
    // 2a. tahsilat
    await p.click('.ks-eylemler [data-ks-form="cari_tahsilat"]');
    ok('Tahsilat → form modalı açılır, başlık "Cari Tahsilat", kasa = seçili kasa, cari alanı görünür, kalem/virman alanları gizli', await modalAcik(p, 'ksModalForm') && (await metin(p, '#ksFormBaslik')) === 'Cari Tahsilat'
      && (await p.inputValue('#ksForm [name="kasa_id"]')) === KID && await goster(p, '#ksForm [name="cari_ara"]') && !(await goster(p, '#ksForm [name="kalem_id"]')) && !(await goster(p, '#ksForm [name="kaynak_kasa_id"]')));
    const dlg = await p.$eval('#ksModalForm .pm-dialog', e => { const r = e.getBoundingClientRect(); return { z: +getComputedStyle(e.closest('.pm-overlay')).zIndex, ust: r.top >= 0, alt: r.bottom <= innerHeight }; });
    ok('modal z-index ≥ 600, ekran içinde', dlg.z >= 600 && dlg.ust && dlg.alt, JSON.stringify(dlg));
    ok('para birimi etiketi ₺ yerine TRY; tutar boş', (await metin(p, '#ksForm [data-pb-etiket]')) === 'TRY');
    await shot(p, 'pc_form');
    let n0 = postSay(p);
    await p.click('#ksForm button[type="submit"]');
    ok('boş tutar → istemci uyarısı (Zorunlu alan), istek gitmez', (await metin(p, '#ksForm .ks-hata')) === 'Zorunlu alan.' && postSay(p) === n0);
    await p.fill('#ksForm [name="tutar"]', '2.500,75');
    await p.click('#ksForm button[type="submit"]');
    ok('cari seçilmeden → sunucu 422 alan hatası (modal açık kalır)', await kisaBekle(p, () => { const e = document.querySelector('#ksForm .ks-hata'); return e && /Cari/.test(e.textContent); }) && await goster(p, '#ksModalForm .pm-dialog'));
    const cari = D0.cariler.find(c => /TİCARET/.test(c.unvan));
    await p.fill('#ksForm [name="cari_ara"]', cari.kod + ' — ' + cari.unvan);
    await p.dispatchEvent('#ksForm [name="cari_ara"]', 'change');
    ok('cari seçilince gizli cari_id dolar + ipucu "Seçildi"', (await p.inputValue('#ksForm [name="cari_id"]')) === String(cari.id) && /Seçildi/.test(await metin(p, '[data-cari-ipucu]')));
    ok('bakiye ipucu: "Bakiye: 81.325,50 ₺ → 83.826,25 ₺"', /81\.325,50 ₺ → 83\.826,25 ₺/.test(await metin(p, '#ksForm [data-bakiye-ipucu]')), await metin(p, '#ksForm [data-bakiye-ipucu]'));
    await p.fill('#ksForm [name="aciklama"]', 'Uçtan uca tahsilat');
    n0 = postSay(p);
    await p.keyboard.press('Control+Enter');
    await yenilenmeBekle(p, n0);
    let D = S1.say();
    const yeniH = D.hareketler[D.hareketler.length - 1];
    ok('Ctrl+Enter kaydeder: DB +1 hareket (cari_tahsilat G 2500.75, KTH-), cari bağı (cari_hareket_id dolu), kasa bakiyesi +250075', D.hareket === D0.hareket + 1 && yeniH.tur === 'cari_tahsilat' && yeniH.yon === 'G' && Number(yeniH.tutar) === 2500.75 && yeniH.belge_no.startsWith('KTH-') && yeniH.cari_hareket_id
      && D.kasalar[KID].bakiye === D0.kasalar[KID].bakiye + 250075, JSON.stringify(yeniH));
    const ch = D.cari_hareketler.find(c => c.id === yeniH.cari_hareket_id);
    ok('cari defteri: +1 hareket, Alacak (A) 2500.75, kaynak_modul=kasa, kaynak_id=hareket', D.cari_hareket === D0.cari_hareket + 1 && ch && ch.yon === 'A' && Number(ch.tutar) === 2500.75 && ch.kaynak_modul === 'kasa' && Number(ch.kaynak_id) === yeniH.id, JSON.stringify(ch));
    ok('yönlendirme: ?kasa=' + KID + ' + toast "Cari Tahsilat kaydedildi · KTH-…"; liste güncel (yeni satır en üstte)', /kasa=/.test(p.url()) && /Cari Tahsilat kaydedildi · KTH-/.test(await toastMetin(p) || '') && /Uçtan uca tahsilat/.test(await metin(p, '.ks-tablo tbody tr:first-child')));
    ok('başlık bakiyesi 83.826,25 ₺ (DB ile aynı)', /83\.826,25/.test(await metin(p, '.ks-bakiye-deger')));
    // 2b. gider
    await p.click('.ks-eylemler [data-ks-form="gider"]');
    await modalAcik(p, 'ksModalForm');
    ok('Gider formu: gider kalemi seçici görünür (gelir gizli), cari gizli', await goster(p, '#ksForm select[name="kalem_id"]:not([disabled])') && !(await goster(p, '#ksForm [name="cari_ara"]')) && (await gorunurSay(p, '#ksForm select[name="kalem_id"]')) === 1);
    await p.fill('#ksForm [name="tutar"]', '120');
    n0 = postSay(p);
    await p.click('#ksForm button[type="submit"]');
    ok('kalem seçilmeden → sunucu 422 alan hatası kalem_id', await kisaBekle(p, () => { const e = document.querySelector('#ksForm .ks-hata'); return e && /kalem/i.test(e.textContent); }) && await goster(p, '#ksModalForm .pm-dialog'));
    const yemek = D.tanimlar.find(t => t.tur === 'gider' && t.ad === 'Yemek');
    await p.selectOption('#ksForm select[name="kalem_id"]:not([disabled])', String(yemek.id));
    n0 = postSay(p);
    await p.click('#ksForm button[type="submit"]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    const gH = D.hareketler[D.hareketler.length - 1];
    ok('gider kaydedildi: tur gider, yon C, 120.00, kalem Yemek, cari bağı YOK', gH.tur === 'gider' && gH.yon === 'C' && Number(gH.tutar) === 120 && Number(gH.kalem_id) === yemek.id && !gH.cari_hareket_id && D.cari_hareket === D0.cari_hareket + 1);
    // 2c. virman
    await p.click('.ks-eylemler [data-ks-form="virman"]');
    await modalAcik(p, 'ksModalForm');
    ok('Virman formu: kaynak = Merkez Kasa, hedef yalnız AYNI para birimi ve farklı kasa (Banka TL), kasa/cari/kalem gizli', (await p.inputValue('#ksForm [name="kaynak_kasa_id"]')) === KID && (await p.inputValue('#ksForm [name="hedef_kasa_id"]')) === BID
      && (await p.$$eval('#ksForm [name="hedef_kasa_id"] option:not([disabled])', o => o.map(x => x.textContent).filter(x => x && x !== 'Seçiniz'))).length === 1 && !(await goster(p, '#ksForm [name="kasa_id"]')));
    await p.fill('#ksForm [name="tutar"]', '3.000');
    n0 = postSay(p);
    await p.click('#ksForm button[type="submit"]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    const vh = D.hareketler.slice(-2);
    ok('virman: iki bacak (C Merkez / G Banka) 3000.00, ortak KVR- belge no, bagli_id çapraz; bakiyeler −/+ 300000', vh.length === 2 && vh[0].tur === 'virman' && vh[1].tur === 'virman' && vh[0].belge_no === vh[1].belge_no && vh[0].belge_no.startsWith('KVR-') && vh[0].bagli_id === vh[1].id && vh[1].bagli_id === vh[0].id
      && D.kasalar[KID].bakiye === D0.kasalar[KID].bakiye + 250075 - 12000 - 300000 && D.kasalar[BID].bakiye === D0.kasalar[BID].bakiye + 300000, JSON.stringify(vh));
    // 2d. yetersiz bakiye
    await git(p, 'kasa_fisleri.php?kasa=' + DID);
    await p.click('.ks-eylemler [data-ks-form="gider"]');
    await modalAcik(p, 'ksModalForm');
    const yakit = D.tanimlar.find(t => t.tur === 'gider' && t.ad === 'Yakıt');
    await p.selectOption('#ksForm select[name="kalem_id"]:not([disabled])', String(yakit.id));
    await p.fill('#ksForm [name="tutar"]', '5.000');
    ok('Döviz Kasası: para birimi etiketi USD, kur alanı görünür', (await metin(p, '#ksForm [data-pb-etiket]')) === 'USD' && await goster(p, '#ksForm [name="kur"]'));
    ok('bakiye ipucu eksiye düşeceğini kırmızı gösterir (−4.000,00 $)', /1\.000,00 \$ → -4\.000,00 \$/.test(await metin(p, '#ksForm [data-bakiye-ipucu]')) && await p.$eval('#ksForm [data-bakiye-ipucu]', e => e.classList.contains('ks-eksi')));
    n0 = postSay(p);
    const sayac0 = S1.say().hareket;
    await p.click('#ksForm button[type="submit"]');
    ok('yetersiz bakiye → #ksEksik görünür (modal kapanmaz): Mevcut 1.000,00 $ · İstenen 5.000,00 $ · Eksik 4.000,00 $', await kisaBekle(p, () => { const e = document.getElementById('ksEksik'); return e && !e.hidden; })
      && /1\.000,00 \$/.test(await metin(p, '[data-eksik="bakiye"]')) && /5\.000,00 \$/.test(await metin(p, '[data-eksik="istenen"]')) && /4\.000,00 \$/.test(await metin(p, '[data-eksik="eksik"]')) && await goster(p, '#ksModalForm .pm-dialog'));
    ok('kayıt YAZILMADI (409)', S1.say().hareket === sayac0);
    await shot(p, 'pc_eksik');
    n0 = postSay(p);
    await p.click('[data-eksik-kaydet]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    const eH = D.hareketler[D.hareketler.length - 1];
    ok('"Yine de kaydet": aynı istek anahtarıyla kayıt yapıldı; bakiye −4.000,00 USD; toast uyarısı (eksiye düştü)', D.hareket === sayac0 + 1 && eH.tur === 'gider' && eH.para_birimi === 'USD' && D.kasalar[DID].bakiye === -400000 && /eksiye düştü/.test(await toastMetin(p) || ''), JSON.stringify(eH));
    ok('liste: eksi bakiye kırmızı (ks-eksi)', await p.$eval('.ks-bakiye-deger .ks-tutar', e => e.classList.contains('ks-eksi')));
    // 2e. iptal (cari bağlı tahsilat)
    await git(p, 'kasa_fisleri.php?kasa=' + KID);
    await p.click('.ks-tablo tbody tr[data-id="' + yeniH.id + '"] td.ks-acik');
    ok('satıra tıklayınca detay modalı (sayfa yenilemeden, ?parca=detay): belge no, cari, cari belgesi, tutar', await modalAcik(p, 'ksModalDetay') && S1.sayi(/parca=detay/) >= 1 && /KTH-/.test(await metin(p, '#ksDetay .ks-detay-belge')) && /Cari belgesi/.test(await metin(p, '#ksDetay')) && /\+2\.500,75/.test(await metin(p, '#ksDetay')));
    await shot(p, 'pc_detay');
    ok('detayda İptal et düğmesi görünür', await goster(p, '[data-ks-iptal-ac]'));
    await p.click('[data-ks-iptal-ac]');
    ok('iptal modalı açılır (detay kapanır), belge no gösterilir, Hareketi iptal et pasif (neden boş)', await modalAcik(p, 'ksModalIptal') && /KTH-/.test(await metin(p, '#ksModalIptal [data-iptal-no]')) && await p.$eval('[data-iptal-onay]', e => e.disabled));
    await p.fill('#ksModalIptal [name="neden"]', 'ab');
    ok('neden < 3 karakter: düğme pasif', await p.$eval('[data-iptal-onay]', e => e.disabled));
    await p.fill('#ksModalIptal [name="neden"]', 'Yanlış cari seçildi');
    n0 = postSay(p);
    await p.click('[data-iptal-onay]');
    await yenilenmeBekle(p, n0);
    D = S1.say();
    const ih = D.hareketler.find(h => h.id === yeniH.id);
    const ich = D.cari_hareketler.find(c => c.id === yeniH.cari_hareket_id);
    ok('iptal: hareket durum=iptal + neden; bağlı cari hareketi de iptal; kasa bakiyesi geri döndü', ih.durum === 'iptal' && ih.iptal_neden === 'Yanlış cari seçildi' && ich.durum === 'iptal' && D.kasalar[KID].bakiye === D0.kasalar[KID].bakiye - 12000 - 300000, JSON.stringify([ih, ich]));
    ok('iptal sonrası liste: iptalli satır varsayılan (geçerli) görünümden çıktı; toast "Hareket iptal edildi"', !/KTH-/.test((await p.$$eval('.ks-tablo tbody tr[data-id="' + yeniH.id + '"]', l => l.length)) ? 'KTH-' : '') && /iptal edildi/.test(await toastMetin(p) || ''));
    await p.click('.ks-eylemler [data-ks-form="cari_tahsilat"]');
    await modalAcik(p, 'ksModalForm');
    ok('tevsik: nakit TRY tahsilat < 30.000 → VUK uyarısı gizli', !(await goster(p, '[data-ks-tevsik]')));
    await p.fill('#ksForm [name="tutar"]', '30.000');
    ok('tevsik: nakit TRY tahsilat ≥ 30.000 → VUK GT 575 uyarısı görünür (kayıt engellenmez)', await goster(p, '[data-ks-tevsik]') && /VUK GT 575/.test(await metin(p, '[data-ks-tevsik]')));
    await p.keyboard.press('Escape');
    ok('audit: kasa modülü kayıtları yazıldı', S1.say().audit > D0.audit);
    ok('sayfa hata vermedi (pageerror / console / 5xx)', hatalar.length === 0, hatalar.join(' | '));
    await p.context().close();

    const pa = await sayfa(browser, S1, { width: 900, height: 1200 });
    await git(pa, 'kasa_cikti.php?kasa=' + KID);
    ok('A4 kasa defteri: KASA DEFTERİ başlığı, firma, devir + kapanış, yatay taşma yok', /KASA DEFTERİ/.test(await metin(pa, '.kasa-yz-tur')) && /KAPANIŞ/.test(await metin(pa, '.kasa-yz-toplam')) && (await gorunurSay(pa, '.kasa-yz-satir tbody tr')) >= 6 && (await tasma(pa)) <= 0);
    await shot(pa, 'a4_defter');
    await pa.context().close();

    // ───────────────────────────── 3. TANIMLAR 1366
    console.log('\n=== 3. Tanımlar (1366): kasa kartı ekle / düzenle / pasifleştir / sil ===');
    const p3 = await sayfa(browser, S1, { width: 1366, height: 800 });
    await git(p3, 'kasa_tanimlari.php');
    ok('sayfa açıldı: Kasa Tanımları, 3 sekme, kasa kartları listesi', /Kasa Tanımları/.test(await p3.title()) && (await gorunurSay(p3, '.ks-tanim-nav-oge')) === 3 && (await gorunurSay(p3, '.ks-kk')) === 3);
    ok('yatay taşma yok', (await tasma(p3)) <= 0);
    await shot(p3, 'tanimlar_pc');
    await p3.click('.ks-panel-ust [data-ks-modal="kart"]');
    ok('+ Yeni kasa → modal; kod önerisi KS-005; banka alanları gizli', await modalAcik(p3, 'ksModalKart') && (await p3.inputValue('#ksModalKart [name="kod"]')) === 'KS-005' && !(await goster(p3, '#ksModalKart [name="iban"]')));
    await p3.selectOption('#ksModalKart [name="tip"]', 'banka');
    ok('tip=Banka → banka adı / şube / IBAN alanları görünür', await goster(p3, '#ksModalKart [name="iban"]') && await goster(p3, '#ksModalKart [name="banka_adi"]'));
    await shot(p3, 'pc_kart_modal');
    await p3.fill('#ksModalKart [name="ad"]', 'Euro Hesabı');
    await p3.selectOption('#ksModalKart [name="para_birimi"]', 'EUR');
    await p3.fill('#ksModalKart [name="iban"]', 'bozuk');
    let n3 = postSay(p3);
    await p3.click('#ksModalKart button[type="submit"]');
    ok('geçersiz IBAN → 422 alan hatası, modal açık', await kisaBekle(p3, () => { const e = document.querySelector('#ksModalKart .ks-hata'); return e && /IBAN/.test(e.textContent); }) && await goster(p3, '#ksModalKart .pm-dialog'));
    await p3.fill('#ksModalKart [name="iban"]', 'TR33 0006 1005 1978 6457 8413 26');
    await p3.fill('#ksModalKart [name="banka_adi"]', 'Örnek Banka');
    await p3.check('#ksModalKart [name="eksi_izin"]');
    n3 = postSay(p3);
    await p3.keyboard.press('Control+Enter');
    await yenilenmeBekle(p3, n3);
    let E = S1.say();
    const yk = Object.values(E.kasalar).find(k => k.ad === 'Euro Hesabı');
    ok('kasa kartı kaydedildi: KS-005, banka, EUR, eksi_izin=1, aktif; listede 4 kart; toast', E.kasa === D0.kasa + 1 && yk && yk.kod === 'KS-005' && yk.tip === 'banka' && yk.pb === 'EUR' && yk.eksi_izin === 1 && yk.aktif === 1 && (await gorunurSay(p3, '.ks-kk')) === 4 && /Kasa kaydedildi/.test(await toastMetin(p3) || ''));
    // düzenle (hareketli kart: para birimi kilitli)
    await p3.click('.ks-kk[data-id="' + KID + '"] [data-duzenle="1"]');
    ok('Düzenle (hareketli kasa): modal dolu (ad Merkez Kasa), para birimi kilitli + ipucu', await modalAcik(p3, 'ksModalKart') && (await p3.inputValue('#ksModalKart [name="ad"]')) === 'Merkez Kasa' && await p3.$eval('#ksModalKart select[name="para_birimi"]', e => e.disabled) && await goster(p3, '[data-pb-kilit]'));
    await p3.fill('#ksModalKart [name="aciklama"]', 'Ana nakit kasası');
    n3 = postSay(p3);
    await p3.click('#ksModalKart button[type="submit"]');
    await yenilenmeBekle(p3, n3);
    ok('düzenleme kaydedildi (aciklama), para birimi bozulmadı, hata yok', S1.say().kasalar[KID].pb === 'TRY' && /güncellendi|kaydedildi/.test(await toastMetin(p3) || ''));
    // pasifleştir + sil
    n3 = postSay(p3);
    const yid = Object.keys(S1.say().kasalar).find(i => S1.say().kasalar[i].ad === 'Euro Hesabı');
    await p3.click('.ks-kk[data-id="' + yid + '"] [data-ks-islem="kart_durum"]');
    await yenilenmeBekle(p3, n3);
    E = S1.say();
    ok('Pasifleştir: kasa aktif=0; satır "Pasif" rozetli', E.kasalar[yid].aktif === 0 && (await gorunurSay(p3, '.ks-kk--pasif')) === 1);
    ok('hareketli kasanın Sil düğmesi devre dışı; hareketsiz (Euro) Sil etkin', await p3.$eval('.ks-kk[data-id="' + KID + '"] button.ks-tehlike', e => e.disabled) && await goster(p3, '.ks-kk[data-id="' + yid + '"] [data-ks-islem="kart_sil"]'));
    n3 = postSay(p3);
    await p3.click('.ks-kk[data-id="' + yid + '"] [data-ks-islem="kart_sil"]');   // confirm otomatik kabul
    await yenilenmeBekle(p3, n3);
    ok('Sil: hareketsiz kasa silindi (DB −1)', S1.say().kasa === D0.kasa);
    // kalemler
    await p3.click('#ksTabGider');
    ok('Gider sekmesi: panel görünür, URL ?sekme=gider (sayfa yenilemeden); 6 kalem', await goster(p3, '#ksPanelGider') && /sekme=gider/.test(p3.url()) && (await gorunurSay(p3, '#ksPanelGider .ks-tanim-satir:not(.ks-tanim-satir--duzen)')) === 6);
    await p3.fill('#ksPanelGider .ks-tanim-ekle [name="ad"]', 'Temsil Ağırlama');
    n3 = postSay(p3);
    await p3.click('#ksPanelGider .ks-tanim-ekle button[type="submit"]');
    await yenilenmeBekle(p3, n3);
    ok('yeni gider kalemi eklendi (DB gider 7); sayfa Gider sekmesinde', S1.say().tanim.gider === 7 && await goster(p3, '#ksPanelGider'));
    ok('sayfa hata vermedi', hatalar.length === 0, hatalar.join(' | '));
    await p3.context().close();

    // ───────────────────────────── 4. TABLET 820
    console.log('\n=== 4. Tablet (820x1000) ===');
    const S4 = await ortam('fisler'); sunucular.push(S4);
    const t = await sayfa(browser, S4, { width: 820, height: 1000 });
    await git(t, 'kasa_fisleri.php?kasa=' + KID);
    ok('yatay taşma yok; sol sütun gizli, kasa seçici görünür; tablo görünür; eylem düğmeleri satır içi; alt çubuk gizli', (await tasma(t)) <= 0 && !(await goster(t, '#ksKasaListe')) && await goster(t, '#ksKasaSec') && (await gorunurSay(t, '.ks-tablo tbody tr')) > 3 && await goster(t, '.ks-eylemler') && !(await goster(t, '.ks-eylem-bar')));
    ok('topbar görünür (<900), sidebar gizli', await goster(t, '.topbar') && !(await goster(t, 'aside.desktop-sidebar')));
    await shot(t, 'tablet_liste');
    await t.click('.ks-eylemler [data-ks-form="gelir"]');
    ok('tablet: gelir formu açılır, taşma yok, modal ekran içinde', await modalAcik(t, 'ksModalForm') && (await tasma(t)) <= 0 && await t.$eval('#ksModalForm .pm-dialog', e => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; }));
    await shot(t, 'tablet_form');
    await t.context().close();

    // ───────────────────────────── 5. MOBİL 390
    console.log('\n=== 5. Mobil (390x844) ===');
    const m = await sayfa(browser, S4, { width: 390, height: 844 }, { dokunmatik: true });
    await git(m, 'kasa_fisleri.php?kasa=' + KID);
    ok('yatay taşma yok; bottomnav görünür; sidebar + tablo gizli; fiş KARTLARI görünür', (await tasma(m)) <= 0 && await goster(m, '.bottomnav') && !(await goster(m, 'aside.desktop-sidebar')) && !(await goster(m, '.ks-tablo')) && (await gorunurSay(m, '.ks-hk')) > 3);
    ok('mobil kasa seçici (select) görünür, sol liste gizli; genel özet kutuları görünür', await goster(m, '#ksKasaSec') && !(await goster(m, '#ksKasaListe')) && (await gorunurSay(m, '.ks-genel-kutu')) === 2);
    const altBar = await m.evaluate(() => { const b = document.querySelector('.ks-eylem-bar').getBoundingClientRect(), n = document.querySelector('.bottomnav').getBoundingClientRect(); return { barAlt: Math.round(b.bottom), navUst: Math.round(n.top), gorunur: b.bottom <= n.top + 1 && b.top >= 0 }; });
    ok('alt eylem çubuğu görünür, bottomnav\'ın ÜSTÜNDE (--bn-h)', altBar.gorunur, JSON.stringify(altBar));
    ok('alt çubuk: Tahsilat · Ödeme · Diğer ≥ 44px', (await kucukHedef(m, '.ks-eylem-bar')).length === 0, JSON.stringify(await kucukHedef(m, '.ks-eylem-bar')));
    ok('mobil ≥44px dokunma hedefleri (liste kapsamı; tablo/menü hariç)', (await kucukHedef(m, '#kasa')).filter(x => !/ks-sekme/.test(x.s)).length === 0, JSON.stringify((await kucukHedef(m, '#kasa')).slice(0, 6)));
    ok('mobil girdiler ≥ 16px', (await kucukYazi(m, '#kasa')).length === 0, JSON.stringify(await kucukYazi(m, '#kasa')));
    await shot(m, 'mobil_liste');
    await m.click('[data-ks-daha]');
    ok('Diğer → Gelir / Gider / Virman / Açılış + Kasa Defteri paneli açılır, çubuğun üstünde, ekran içinde', await goster(m, '#ksDaha') && await m.$eval('#ksDaha', e => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight; }));
    ok('mobil Diğer paneli: Kasa Defteri + Excel (XLSX) + CSV bağlantıları (dışa aktarım mobilde de erişilebilir)', (await m.$$eval('#ksDaha a', l => l.map(x => x.getAttribute('href')))).filter(h => /kasa_cikti\.php/.test(h)).length === 3);
    await shot(m, 'mobil_daha');
    await m.click('#ksDaha [data-ks-form="gider"]');
    ok('mobil gider formu: modal açılır, tam yükseklik, z ≥ 600', await modalAcik(m, 'ksModalForm') && await m.$eval('#ksModalForm', e => +getComputedStyle(e).zIndex >= 600));
    const gv = await m.evaluate(() => {
      const b = document.querySelector('#ksModalForm .pm-body'), f = document.querySelector('#ksModalForm .pm-footer'), d = document.querySelector('#ksModalForm .pm-dialog');
      const fr = f.getBoundingClientRect(), dr = d.getBoundingClientRect();
      return { kayar: b.scrollHeight > b.clientHeight + 1, ovf: getComputedStyle(b).overflowY, footerIci: fr.bottom <= innerHeight + 1 && fr.top >= 0, dialogIci: dr.bottom <= innerHeight + 1 && dr.top >= 0, taşma: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    ok('mobil modal: gövde kaydırılır (overflow auto), footer (Vazgeç / Kaydet) ekran içinde, yatay taşma yok', /auto|scroll/.test(gv.ovf) && gv.footerIci && gv.dialogIci && gv.taşma <= 0, JSON.stringify(gv));
    await shot(m, 'mobil_form');
    await m.evaluate(() => { const b = document.querySelector('#ksModalForm .pm-body'); b.scrollTop = b.scrollHeight; });
    await bekle(200);
    const sonKutu = await m.evaluate(() => {
      const el = document.querySelector('#ksForm [name="aciklama"]'), r = el.getBoundingClientRect(), f = document.querySelector('#ksModalForm .pm-footer').getBoundingClientRect();
      const t = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return { gorunur: r.top >= 0 && r.bottom <= f.top + 1, tiklanir: t === el };
    });
    ok('en alttaki alan (Açıklama) kaydırınca görünür, footer tarafından örtülmez ve tıklanabilir', sonKutu.gorunur && sonKutu.tiklanir, JSON.stringify(sonKutu));
    ok('mobil form: girdiler ≥16px, hedefler ≥44px', (await kucukYazi(m, '#ksModalForm')).length === 0 && (await kucukHedef(m, '#ksModalForm')).filter(x => !/pm-close/.test(x.s) || x.h < 44).length === 0, JSON.stringify([await kucukYazi(m, '#ksModalForm'), await kucukHedef(m, '#ksModalForm')]));
    await m.keyboard.press('Escape');
    await m.click('.ks-hk:first-child a.ks-hk-baglanti');
    ok('mobil: kart dokununca detay modalı (ayrıntı listesi), kapat düğmesi erişilebilir', await modalAcik(m, 'ksModalDetay') && await goster(m, '#ksDetay .ks-dl') && await m.$eval('#ksModalDetay .pm-footer', e => e.getBoundingClientRect().bottom <= innerHeight + 1));
    await shot(m, 'mobil_detay');
    await m.keyboard.press('Escape');
    await git(m, 'kasa_tanimlari.php');
    ok('mobil tanımlar: taşma yok, kasa kartları dikey, düğmeler ≥44px', (await tasma(m)) <= 0 && (await gorunurSay(m, '.ks-kk')) === 3 && (await kucukHedef(m, '.ks-tanim')).length === 0, JSON.stringify(await kucukHedef(m, '.ks-tanim')));
    await shot(m, 'mobil_tanimlar');
    await m.click('.ks-panel-ust [data-ks-modal="kart"]');
    await modalAcik(m, 'ksModalKart');
    await m.selectOption('#ksModalKart [name="tip"]', 'banka');
    const kv = await m.evaluate(() => { const b = document.querySelector('#ksModalKart .pm-body'), f = document.querySelector('#ksModalKart .pm-footer').getBoundingClientRect(); return { kayar: b.scrollHeight > b.clientHeight, footerIci: f.bottom <= innerHeight + 1, taşma: document.documentElement.scrollWidth - document.documentElement.clientWidth }; });
    ok('mobil kasa modalı (banka alanları açık): gövde kaydırılır, footer ekran içinde, taşma yok', kv.kayar && kv.footerIci && kv.taşma <= 0, JSON.stringify(kv));
    await shot(m, 'mobil_kart_modal');
    await m.context().close();

    // ───────────────────────────── 6. ŞEMA YOK / HİÇ KASA YOK
    console.log('\n=== 6. Şema yok / hiç kasa yok ===');
    const S6 = await ortam('sema'); sunucular.push(S6);
    const e1 = await sayfa(browser, S6, { width: 1366, height: 800 });
    await git(e1, 'kasa_fisleri.php');
    ok('şema yok: uyarı kartı + migrate.php#cari ve #kasa bağlantıları; sekme şeridi yok; taşma yok', await goster(e1, '[data-ks-sema]') && await goster(e1, 'a[href="migrate.php#cari"]') && await goster(e1, 'a[href="migrate.php#kasa"]') && !(await goster(e1, '.ks-sekmeler')) && (await tasma(e1)) <= 0);
    await shot(e1, 'sema_yok');
    await e1.context().close();
    const S7 = await ortam('bos'); sunucular.push(S7);
    const e2 = await sayfa(browser, S7, { width: 390, height: 844 }, { dokunmatik: true });
    await git(e2, 'kasa_fisleri.php');
    ok('hiç kasa yok (mobil): "İlk kasanızı ekleyin" boş durumu, tıklayınca tanımlarda yeni kasa modalı açılır', await goster(e2, '.ks-bos') && /İlk kasanızı ekleyin/.test(await metin(e2, '.ks-bos')) && (await tasma(e2)) <= 0);
    await shot(e2, 'kasa_yok_mobil');
    await e2.click('.ks-bos a.btn-primary');
    await e2.waitForLoadState('load');
    ok('yeni kasa modalı otomatik açıldı (?yeni=1), URL temizlendi', await modalAcik(e2, 'ksModalKart') && !/yeni=1/.test(e2.url()));
    await e2.fill('#ksModalKart [name="ad"]', 'İlk Kasa');
    const n7 = postSay(e2);
    await e2.click('#ksModalKart button[type="submit"]');
    await yenilenmeBekle(e2, n7);
    ok('ilk kasa kaydedildi (DB 1 kasa, KS-001, nakit TRY aktif)', S7.say().kasa === 1 && Object.values(S7.say().kasalar)[0].kod === 'KS-001');
    await e2.context().close();
    const S8 = await ortam('ornek'); sunucular.push(S8);
    const e3 = await sayfa(browser, S8, { width: 1366, height: 800 });
    await git(e3, 'kasa_fisleri.php?kasa=' + KID);
    ok('kasa var ama hareket yok: "Henüz hareket yok" + Açılış bakiyesi gir düğmesi; Kasa Defteri bağlantısı var', /Henüz hareket yok/.test(await metin(e3, '.ks-liste-govde')) && await goster(e3, '.ks-liste-govde [data-ks-form="acilis"]'));
    await shot(e3, 'pc_hareket_yok');
    await e3.context().close();
    const e4 = await sayfa(browser, S8, { width: 1366, height: 800 });
    await e4.route('**/*', r => r.continue());
    const yr = await e4.request.get(S8.url + '/kasa_fisleri.php?kasa=' + KID, { headers: {} });
    ok('GET sayfaları $_POST kabul etmez: kasa_kaydet GET → 405', (await e4.request.get(S8.url + '/kasa_kaydet.php')).status() === 405 && yr.status() === 200);
    await e4.context().close();

    // ───────────────────────────── 7. v528 DENETİM DÜZELTMELERİ
    console.log('\n=== 7. v528 denetim düzeltmeleri: sayfalama çubuğu · tutar önizlemesi (JS = PHP) · toast · anahtar ===');
    // 7a. JS ↔ PHP tutar eşitliği (assets/kasa.js `tutarCoz` kaynaktan çıkarılıp çalıştırılır; sunucu = kasa_tutar_coz)
    {
      const src = fs.readFileSync(path.join(ROOT, 'assets/kasa.js'), 'utf8');
      const a0 = src.indexOf('function tutarCoz(ham)'), b0 = src.indexOf('function kurusCoz');
      const tutarCoz = new Function(src.slice(a0, b0) + '; return tutarCoz;')();
      const girdiler = ['1.234', '12.345', '1.5', '1,5', '1.234,5', '1,234.56', '1.234.567', '0,005', '1 234,56', '-5', '1e3', '1.234,56', '100', '0,01', '1.00', '10.50', '1.000.000,00', '1,2,3', '12,345', '1.23', '123.456,789', ' 5 ', '٥', '1.2345',
        '1,500', '999,999', '0,500', '1.500', '1.500,00', '1500', '1,50', '100,000', '1,000,000', '-1,500', '', ' ', 'abc', '0', '0,00', '1.500 TL', '₺1.500,00', '2.50 tl', '1,15', '19,99', '4,35', '0,07', '0,015', '999.999.999.999,99', '1.000.000.000.000,00', '1.234,5.6', ',5', '5,', '.5', '1.5.5', '12,34,567', '1 500,25', '1 500,25', '7,000', '10,000'];
      const php = JSON.parse(execFileSync('php', [path.join(__dirname, 'kasa_ui_e2e_tutar.php'), path.join(TMP, 'tutar.sqlite'), JSON.stringify(girdiler)], { cwd: ROOT, timeout: 60000 }).toString());
      const farklar = girdiler.filter(g => { const j = tutarCoz(g), q = php[g]; return j.k !== q.k || j.belirsiz !== q.belirsiz || j.mesaj !== q.mesaj; }).map(g => JSON.stringify(g) + ' JS=' + JSON.stringify(tutarCoz(g)) + ' PHP=' + JSON.stringify(php[g]));
      ok('tutar çözümü: JS tutarCoz ile PHP kasa_tutar_coz ' + girdiler.length + ' girdide BİREBİR aynı (kuruş + belirsiz + mesaj)', farklar.length === 0, farklar.join('\n    → '));
      ok('belirsiz kalıp iki tarafta da "belirsiz": 1,500 · 12,345 · 100,000; "0,500" belirsiz değil (50 kuruş); "1,234.56" = 123456', tutarCoz('1,500').belirsiz && tutarCoz('12,345').belirsiz && tutarCoz('100,000').belirsiz && !tutarCoz('0,500').belirsiz && tutarCoz('0,500').k === 50 && tutarCoz('1,234.56').k === 123456 && php['1,500'].belirsiz && php['0,500'].k === 50);
    }
    // 7b. sayfalama çubuğu (100+ hareket) son satırın ALTINDA ve liste sonuna kaydırınca görünür/tıklanabilir
    const S9 = await ortam('cok'); sunucular.push(S9);
    const K9 = Object.keys(S9.say().kasalar).find(i => S9.say().kasalar[i].ad === 'Merkez Kasa');
    for (const vp of [{ width: 1366, height: 800 }, { width: 1024, height: 768 }]) {
      const pg = await sayfa(browser, S9, vp);
      await git(pg, 'kasa_fisleri.php?kasa=' + K9);
      const o = await pg.evaluate(() => {
        const g = document.getElementById('ksListe'), sf = g.querySelector('.ks-sayfalama'), satir = [...g.querySelectorAll('.ks-tablo tbody tr')], son = satir[satir.length - 1];
        const once = { sfUst: sf.getBoundingClientRect().top, sonAlt: son.getBoundingClientRect().bottom };
        g.scrollTop = g.scrollHeight;
        const gr = g.getBoundingClientRect(), sr = sf.getBoundingClientRect(), el = document.elementFromPoint(sr.left + sr.width / 2, sr.top + sr.height / 2);
        return { satir: satir.length, altinda: once.sfUst >= once.sonAlt - 1, gorunur: sr.top >= gr.top - 1 && sr.bottom <= gr.bottom + 1, tiklanir: !!el && sf.contains(el), kayar: g.scrollHeight > g.clientHeight };
      });
      ok('sayfalama (' + vp.width + 'px, ' + o.satir + ' satır): çubuk son satırın ALTINDA; listenin sonuna kaydırınca görünür ve tıklanabilir', o.satir === 100 && o.altinda && o.gorunur && o.tiklanir && o.kayar, JSON.stringify(o));
      await shot(pg, 'pager_' + vp.width);
      await pg.click('.ks-sayfalama a');
      await pg.waitForLoadState('load');
      ok('sayfalama bağlantısı tıklanır: ?sayfa=2, kalan 30 satır', /sayfa=2/.test(pg.url()) && (await gorunurSay(pg, '.ks-tablo tbody tr')) === 30, pg.url());
      await pg.context().close();
    }
    // 7c. tutar önizlemesi + belirsiz tutar (PC form ve mobil kart görünümü)
    const S10 = await ortam('fisler'); sunucular.push(S10);
    for (const [ad, vp, dok] of [['PC', { width: 1366, height: 800 }, false], ['mobil', { width: 390, height: 844 }, true]]) {
      const pt = await sayfa(browser, S10, vp, { dokunmatik: dok });
      await git(pt, 'kasa_fisleri.php?kasa=' + KID);
      await pt.click(dok ? '.ks-eylem-bar [data-ks-form="cari_tahsilat"]' : '.ks-eylemler [data-ks-form="cari_tahsilat"]');
      await modalAcik(pt, 'ksModalForm');
      await pt.fill('#ksForm [name="tutar"]', '1.500');
      ok(ad + ': tutar "1.500" → altında "= 1.500,00 ₺" önizlemesi', /^= 1\.500,00 ₺$/.test(await metin(pt, '#ksForm [data-tutar-onizleme]') || ''), await metin(pt, '#ksForm [data-tutar-onizleme]'));
      await pt.fill('#ksForm [name="tutar"]', '1,500');
      ok(ad + ': tutar "1,500" → BELİRSİZ hata metni görünür, alan aria-invalid', /Tutar belirsiz: 1\.500 için nokta kullanın \(1\.500\)/.test(await metin(pt, '#ksForm [data-tutar-onizleme]') || '') && await goster(pt, '#ksForm [data-tutar-onizleme]') && (await pt.getAttribute('#ksForm [name="tutar"]', 'aria-invalid')) === 'true');
      await shot(pt, 'tutar_belirsiz_' + ad);
      await pt.fill('#ksForm [name="tutar"]', '1,50');
      ok(ad + ': "1,50" → "= 1,50 ₺", hata işareti kalkar', /^= 1,50 ₺$/.test(await metin(pt, '#ksForm [data-tutar-onizleme]') || '') && (await pt.getAttribute('#ksForm [name="tutar"]', 'aria-invalid')) !== 'true');
      await pt.fill('#ksForm [name="tutar"]', '1,500');
      const cr = S10.say().hareket;
      await pt.fill('#ksForm [name="cari_ara"]', S10.say().cariler[0].kod + ' — ' + S10.say().cariler[0].unvan);
      await pt.dispatchEvent('#ksForm [name="cari_ara"]', 'change');
      await pt.click('#ksForm button[type="submit"]');
      ok(ad + ': "1,500" gönderilirse sunucu 422 alan hatası (Tutar belirsiz …), kayıt YOK, modal açık', await bekle(900) === undefined && await kisaBekle(pt, () => document.querySelector('#ksForm .ks-tutar-onizleme--hata') && !document.querySelector('#ksForm .ks-hata')) && S10.say().hareket === cr && await goster(pt, '#ksModalForm .pm-dialog'));
      await pt.context().close();
    }
    // 7d. mobil: toast alt eylem çubuğunu örtmez, tıklamayı engellemez
    {
      const pm = await sayfa(browser, S10, { width: 390, height: 844 }, { dokunmatik: true });
      await git(pm, 'kasa_fisleri.php?kasa=' + KID);
      await pm.evaluate(() => sessionStorage.setItem('kasa_toast', 'Cari Tahsilat kaydedildi · KTH-2026-000999 — uyarı metni uzun uzun'));
      await pm.reload({ waitUntil: 'load' }); await bekle(250);
      const t = await pm.evaluate(() => {
        const to = document.querySelector('.ks-toast'), bar = document.querySelector('.ks-eylem-bar'), tr = to.getBoundingClientRect(), br = bar.getBoundingClientRect(), btn = bar.querySelector('[data-ks-form="cari_tahsilat"]'), r = btn.getBoundingClientRect();
        const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return { gorunur: !to.hidden && tr.height > 0, toastAlt: Math.round(tr.bottom), barUst: Math.round(br.top), pe: getComputedStyle(to).pointerEvents, tik: el === btn || btn.contains(el) };
      });
      ok('mobil toast (390): alt eylem çubuğunun ÜSTÜNDE, pointer-events none; Tahsilat düğmesi elementFromPoint ile tıklanabilir', t.gorunur && t.toastAlt <= t.barUst && t.pe === 'none' && t.tik, JSON.stringify(t));
      await shot(pm, 'mobil_toast');
      await pm.context().close();
    }
    // 7e. aktif/pasif anahtarı: istek sürerken devre dışı, hata dönerse eski hâline
    {
      const pk = await sayfa(browser, S10, { width: 1366, height: 800 });
      await git(pk, 'kasa_tanimlari.php?sekme=gider');
      await pk.route('**/kasa_kaydet.php', async r => { await bekle(900); await r.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, hata: 'Deneme hatası', alanlar: {} }) }); });
      const sel = '#ksPanelGider .ks-tanim-satir:not(.ks-tanim-satir--duzen) input[role="switch"]';
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
    ok('tüm oturum boyunca pageerror / console error / 5xx YOK', hatalar.length === 0, hatalar.join(' | '));
  } catch (e) {
    fail++; console.log('*** İSTİSNA: ' + (e && e.stack || e));
  } finally {
    kapaniyor = true; await temizle();
  }
  console.log('\n' + (n - fail) + ' geçti, ' + fail + ' kaldı (' + n + ' adım)');
  process.exit(fail ? 1 : 0);
})();
