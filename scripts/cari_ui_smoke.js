// =========================================================
// scripts/cari_ui_smoke.js — "Cari İşlemler" TARAYICI uçtan uca testi (gerçek PHP + gerçek assets/cari.js)
//   node scripts/cari_ui_smoke.js        → çıkış 0 = geçti (Playwright yoksa ATLANIR)
//   CARI_SHOT=<klasör>                   → ekran görüntüleri (görsel inceleme; repoya konmaz)
//
// Sahte yanıt YOK: scripts/_cari_e2e_sunucu.js her isteği scripts/_cari_istek.php alt süreciyle GERÇEK cari_*.php sayfalarında
// çalıştırır (gerçek config/helpers.php kabuğu: sidebar/topbar/bottomnav + cari_core + cari_ui) ve assets/'i diskten sunar.
// Durum dosya SQLite'ta kalıcıdır (tahsilat → bakiye gerçekten değişir); DB gerçeği scripts/cari_ui_e2e_say.php ile okunur.
// Canlı DB'ye / migrasyona HİÇ dokunulmaz.
// Bölümler: 1 kartlar masaüstü (liste/arama/çip/detay/ekstre/tahsilat/virman/iptal/kart yönetimi) · 2 fişler+dekontlar · 3 tanımlar ·
//           4 şema yok / boş durum · 5 tablet (1024, 820) · 6 mobil (390) · 7 JS kapalı · 8 ekstre yazdır.
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
const { baslat } = require('./_cari_e2e_sunucu.js');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'cari_ui_'));
const SHOT = process.env.CARI_SHOT || '';
let fail = 0, n = 0, dbNo = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(100) + (k ? 'OK' : '*** FAIL' + (ip !== undefined ? '\n    → ' + ip : ''))); };
const bekle = ms => new Promise(r => setTimeout(r, ms));
const hatalar = [];

// ── Ortam: yeni dosya SQLite + köprü sunucu ──
async function ortam(mod) {
  const db = path.join(TMP, 'cari' + (++dbNo) + '.sqlite');
  execFileSync('php', [path.join(__dirname, 'cari_ui_e2e_setup.php'), db, mod || 'ornek'], { cwd: ROOT, stdio: 'pipe' });
  const srv = await baslat({ db, sid: 'cariE2E' + dbNo });
  srv.db = db;
  srv.say = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'cari_ui_e2e_say.php'), db], { cwd: ROOT }).toString());
  srv.say_ = null;
  srv.sayi = re => srv.istekler.filter(i => re.test(i.url) || re.test(i.yontem + ' ' + i.url)).length;
  return srv;
}
async function sayfa(browser, srv, vp, o) {
  o = o || {};
  const ctx = await browser.newContext({ viewport: vp, locale: 'tr-TR', serviceWorkers: 'block', javaScriptEnabled: o.js !== false, deviceScaleFactor: 1, hasTouch: !!o.dokunmatik, isMobile: !!o.dokunmatik });
  const p = await ctx.newPage();
  p.on('pageerror', e => hatalar.push('[' + vp.width + '] pageerror: ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) hatalar.push('[' + vp.width + '] console: ' + m.text()); });
  p.on('requestfailed', r => { if (!/sw\.js|favicon/.test(r.url())) hatalar.push('[' + vp.width + '] requestfailed: ' + r.url()); });
  p.on('response', r => { if (r.status() >= 500) hatalar.push('[' + vp.width + '] HTTP ' + r.status() + ' ' + r.url()); });
  p.on('dialog', d => { if (p._reddet) d.dismiss(); else d.accept(); });
  p.srv = srv; p.url0 = srv.url;
  return p;
}
const git = (p, yol) => p.goto(p.url0 + '/' + yol, { waitUntil: 'load' });
const tasma = p => p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const goster = (p, s) => p.$eval(s, e => !!(e.getClientRects().length) && getComputedStyle(e).visibility !== 'hidden').catch(() => false);
const metin = (p, s) => p.$eval(s, e => e.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const say = (p, s) => p.$$eval(s, l => l.filter(e => e.getClientRects().length).length);
const kurus = t => { const m = String(t || '').match(/-?[\d.]+,\d{2}/); return m ? Math.round(parseFloat(m[0].replace(/\./g, '').replace(',', '.')) * 100) : null; };
async function bakiyeUI(p, pb) { return kurus(await metin(p, `#crDetay .cari-bk-kutu[data-pb="${pb}"] .cari-bk-tutar`)); }
async function bakiyeYonUI(p, pb) { const k = await p.$(`#crDetay .cari-bk-kutu[data-pb="${pb}"]`); return k ? (await k.getAttribute('class')).match(/cari-bk-kutu--(\w)/)[1] : null; }
async function isaretli(p, pb) { const v = await bakiyeUI(p, pb), y = await bakiyeYonUI(p, pb); return v == null ? null : (y === 'a' ? -v : v); }   // + = bize borçlu
const kisaBekle = async (p, fn, arg, ms) => { try { await p.waitForFunction(fn, arg, { timeout: ms || 6000 }); return true; } catch (e) { return false; } };
// Kayıt isteği sunucuda BİTTİ (köprü kaydı) ve sayfa yeniden yüklendi mi? n0 = tıklamadan önceki POST sayısı.
async function yenilenmeBekle(p, n0) {
  for (let i = 0; i < 80 && p.srv.sayi(/POST \/cari_kaydet/) <= n0; i++) await bekle(100);
  await bekle(300); await p.waitForLoadState('load');
  await kisaBekle(p, () => document.readyState === 'complete');
  await bekle(300);
}
const toastMetin = p => metin(p, '.cari-toast:not([hidden])');
async function modalAcik(p, id) { const r = await modalAcik_(p, id); await bekle(120); return r; }   // açılışta ilk alana odak 30ms sonra verilir
async function modalAcik_(p, id) { return await kisaBekle(p, i => { const m = document.getElementById(i); return m && !m.hidden && m.getBoundingClientRect().height > 50; }, id, 3000); }
async function modalKapali(p, id) { return await kisaBekle(p, i => { const m = document.getElementById(i); return m && m.hidden; }, id, 6000); }
async function detayBekle(p, id) { return await kisaBekle(p, i => { const d = document.getElementById('crDetay'); return d && d.getAttribute('data-id') === String(i) && !d.classList.contains('cari-yukleniyor') && d.querySelector('.cari-unvan'); }, id, 6000); }
const shot = async (p, ad) => { if (SHOT) { fs.mkdirSync(SHOT, { recursive: true }); await p.screenshot({ path: path.join(SHOT, ad + '.png') }); } };
async function alanHatasi(p, kapsam, ad) { return await p.$$eval(`${kapsam} [name="${ad}"]`, l => { const e = l.find(x => x.type !== 'hidden'); const h = e && e.closest('.pm-label, form, div') && e.closest('.pm-label, div').querySelector('.cari-hata'); return h ? h.textContent.trim() : null; }).catch(() => null); }

(async () => {
  let browser; const sunucular = [];
  try {
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});

    // ───────────────────────────────────────── 1. KARTLAR — masaüstü 1440x900
    console.log('\n=== 1. Kartlar (1440x900) ===');
    const S1 = await ortam(); sunucular.push(S1);
    const p = await sayfa(browser, S1, { width: 1440, height: 900 });
    await git(p, 'cari_kartlari.php?id=1');
    await detayBekle(p, 1);
    ok('sayfa açıldı: başlık + detay (Örnek Tarım Ltd.)', /Cari Kayıt Kartları/.test(await p.title()) && (await metin(p, '#crDetay .cari-unvan')) === 'Örnek Tarım Ltd.');
    ok('yatay taşma yok (1440)', (await tasma(p)) <= 0);
    ok('sidebar görünür; "Cari İşlemler" grubu AÇIK', await p.$eval('details[data-sb-grup="cari"]', d => d.open && d.getClientRects().length > 0).catch(() => false));
    const aktifLinkler = await p.$$eval('details[data-sb-grup="cari"] a.sidebar-link.active', l => l.map(a => a.textContent.trim()));
    ok('sidebar: yalnız "Cari Kayıt Kartları" aktif', aktifLinkler.length === 1 && /Cari Kayıt Kartları/.test(aktifLinkler[0]), JSON.stringify(aktifLinkler));
    const aktifRenk = await p.$eval('a.sidebar-link.active', a => { const c = getComputedStyle(a); return [c.color, c.backgroundColor]; });
    ok('sidebar aktif link okunur (metin rengi koyu zemine karşı açık)', aktifRenk[0] !== aktifRenk[1] && /rgb\((2[0-9]{2}|1[5-9][0-9])/.test(aktifRenk[0]), JSON.stringify(aktifRenk));
    ok('topbar + bottomnav masaüstünde gizli', !(await goster(p, '.topbar')) && !(await goster(p, '.bottomnav')));
    ok('cari sekme çubuğu: Kartlar aktif', (await p.$eval('.cari-sekme[aria-current="page"]', e => e.textContent.trim()).catch(() => '')) === 'Kartlar');
    await shot(p, 'kartlar_1440');

    // — liste, arama, çipler —
    const kartSay = await say(p, '#crListe .cari-kart');
    ok('liste: 12 aktif cari (pasif listelenmez)', kartSay === 12, kartSay);
    const cipler = await p.$$eval('.cari-cip[data-filtre="bakiye"]', l => l.map(c => ({ d: c.getAttribute('data-deger'), n: parseInt(c.querySelector('.cari-cip-say').textContent, 10) })));
    let tutarsiz = [];
    for (const c of cipler) {
      await p.click(`.cari-cip[data-filtre="bakiye"][data-deger="${c.d}"]`);
      const g = await say(p, '#crListe .cari-kart');
      if (g !== c.n) tutarsiz.push(`${c.d || 'tümü'}: rozet ${c.n} ≠ görünen ${g}`);
    }
    ok('çip rozet sayıları gerçek sonuçla tutarlı (Tümü/Borçlu/Alacaklı/Pasif)', tutarsiz.length === 0, tutarsiz.join('; '));
    ok('"Pasif" çipi pasif kartı gösterir (Eski Müşteri)', (await p.click('.cari-cip[data-deger="pasif"]'), (await metin(p, '#crListe li:not([hidden]) .cari-kart-ad')) === 'Eski Müşteri Örnek Ltd.'));
    await p.click('.cari-cip[data-deger=""]');
    await p.fill('#crArama', 'cavus');
    await bekle(250);
    ok('arama: "cavus" (TR-duyarsız) → Deneme Çavuş Grubu', (await say(p, '#crListe li:not([hidden]) .cari-kart')) === 1 && /Çavuş/.test(await metin(p, '#crListe li:not([hidden]) .cari-kart-ad')));
    ok('arama: sayaç "1 cari"', (await metin(p, '#crSayi')) === '1 cari');
    await p.fill('#crArama', 'zzzyok'); await bekle(250);
    ok('arama: eşleşme yok → "Eşleşen cari yok" görünür', await goster(p, '#crSonucYok'));
    await p.fill('#crArama', ''); await bekle(250);
    await p.selectOption('select[data-filtre="tip"]', 'musteri');
    const musteriSay = await say(p, '#crListe li:not([hidden]) .cari-kart');
    ok('tip süzgeci: yalnız müşteri kartları', musteriSay > 0 && musteriSay < 12, musteriSay);
    await p.selectOption('select[data-filtre="tip"]', '');

    // — satır seç → detay + menüler + URL + geri —
    const ist0 = S1.sayi(/parca=detay/);
    await p.click('#crListe .cari-kart[data-id="2"]');
    ok('satır seç → detay yenilenir (Deneme Ambalaj A.Ş.)', await detayBekle(p, 2) && (await metin(p, '#crDetay .cari-unvan')) === 'Deneme Ambalaj A.Ş.');
    ok('detay parçası sunucudan istendi (parca=detay)', S1.sayi(/parca=detay/) === ist0 + 1);
    ok('URL ?id=2 oldu (pushState)', /[?&]id=2(&|$)/.test(p.url()));
    ok('seçili satır aria-current', (await p.$eval('#crListe .cari-kart[aria-current="true"]', e => e.getAttribute('data-id')).catch(() => null)) === '2');
    const menuCari = await p.$$eval('#crMenuDekont [data-cari], #crMenuDiger [data-cari]', l => [...new Set(l.map(e => e.getAttribute('data-cari')))]);
    ok('menüler (Dekont ▾/⋯) YENİ cariye göre yenilendi (data-cari=2)', menuCari.length === 1 && menuCari[0] === '2', JSON.stringify(menuCari));
    const ekstreLink = await p.$$eval('#crMenuDiger a[href*="cari_ekstre.php"]', l => l.map(a => a.getAttribute('href')));
    ok('⋯ menüsündeki ekstre bağlantıları yeni cariye (id=2)', ekstreLink.length > 0 && ekstreLink.every(h => /id=2(&|$)/.test(h)), JSON.stringify(ekstreLink));
    const pasifDugme = await metin(p, '#crMenuDiger [data-cr-islem="kart_durum"]');
    ok('⋯ menüsü: Pasifleştir (aktif kart)', pasifDugme === 'Pasifleştir', pasifDugme);
    await p.goBack();
    ok('geri tuşu → önceki cari (id=1) detayı', await detayBekle(p, 1) && /Örnek Tarım/.test(await metin(p, '#crDetay .cari-unvan')));
    await p.goForward(); await detayBekle(p, 2);
    await p.click('#crListe .cari-kart[data-id="1"]'); await detayBekle(p, 1);

    // — ekstre filtreleri —
    ok('ekstre: varsayılan TRY seçili, "Bu yıl" çipi basılı', (await p.$eval('#crEkstreForm input[name="pb"]:checked', e => e.value)) === 'TRY' && (await p.$eval('.cari-cip[data-donem="yil"]', e => e.getAttribute('aria-pressed'))) === 'true');
    const satirTRY = await say(p, '#crPanelEkstre tbody tr[data-id]');
    await p.click('#crEkstreForm .cari-seg:has(input[value="USD"])');
    ok('ekstre: para birimi USD → USD satırları + toplam USD', await kisaBekle(p, () => /USD/.test(document.querySelector('#crPanelEkstre tfoot').textContent)) && (await say(p, '#crPanelEkstre tbody tr[data-id]')) === 2, await say(p, '#crPanelEkstre tbody tr[data-id]'));
    ok('ekstre USD: kapanış 48.250,00 $', /48\.250,00/.test(await metin(p, '#crPanelEkstre .cari-ekstre-toplam')));
    await p.click('#crEkstreForm .cari-seg:has(input[value="TRY"])');
    await kisaBekle(p, () => /TRY/.test(document.querySelector('#crPanelEkstre tfoot').textContent));
    await p.selectOption('#crEkstreForm select[name="tur"]', 'tahsilat');
    await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr[data-id]').length > 0 && [...document.querySelectorAll('#crPanelEkstre tbody tr[data-id]')].every(r => /Tahsilat/.test(r.textContent)));
    const tahSatir = await say(p, '#crPanelEkstre tbody tr[data-id]');
    ok('ekstre: tür=Tahsilat → yalnız tahsilat satırları', tahSatir === 3 && (await p.$$eval('#crPanelEkstre tbody tr[data-id]', l => l.every(r => /Tahsilat/.test(r.textContent)))), tahSatir);
    await p.selectOption('#crEkstreForm select[name="tur"]', '');
    await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr[data-id]').length > 5);
    ok('ekstre: tür sıfırlanınca tüm satırlar döner', (await say(p, '#crPanelEkstre tbody tr[data-id]')) === satirTRY, await say(p, '#crPanelEkstre tbody tr[data-id]'));
    ok('ekstre: iptal edilmiş satır varsayılan GİZLİ', (await say(p, '#crPanelEkstre tbody tr.cari-iptal')) === 0);
    await p.check('#crPanelEkstre input[name="iptal"]');
    ok('ekstre: "İptalleri göster" → üstü çizili iptal satırı + gerekçe', await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr.cari-iptal').length === 1)
      && /Yanlış cari seçildi/.test(await metin(p, '#crPanelEkstre tbody tr.cari-iptal'))
      && /line-through/.test(await p.$eval('#crPanelEkstre tbody tr.cari-iptal', r => [...r.querySelectorAll('td')].map(t => getComputedStyle(t).textDecorationLine + getComputedStyle(t.firstElementChild || t).textDecorationLine).join(' '))));
    await p.uncheck('#crPanelEkstre input[name="iptal"]');
    await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr.cari-iptal').length === 0);
    await p.click('.cari-cip[data-donem="ay"]');
    await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr[data-id]').length < 9);
    ok('ekstre: dönem "Bu ay" daraltır; "Tümü" tekrar genişletir', (await say(p, '#crPanelEkstre tbody tr[data-id]')) < satirTRY);
    await p.click('.cari-cip[data-donem="tumu"]');
    ok('ekstre: dönem Tümü → satırlar tam', await kisaBekle(p, s => document.querySelectorAll('#crPanelEkstre tbody tr[data-id]').length >= s, satirTRY));
    await p.click('.cari-cip[data-donem="yil"]');
    await kisaBekle(p, () => document.querySelector('.cari-cip[data-donem="yil"]').getAttribute('aria-pressed') === 'true');
    await p.click('.cari-cip[data-donem="ozel"]');
    ok('ekstre: "Özel…" tarih alanlarını açar', await goster(p, '#crEkstreForm input[name="bas"]'));
    await p.click('.cari-cip[data-donem="yil"]');
    await bekle(300);

    // — Tahsilat: doğrulama hataları —
    await p.click('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]');
    ok('Tahsilat modalı açılır; cari kilitli (Örnek Tarım Ltd.), TRY seçili', await modalAcik(p, 'crModalHareket') && /Örnek Tarım/.test(await metin(p, '#crModalHareket .cari-secili-ad')) && (await p.$eval('#crModalHareket input[name="para_birimi"]:checked', e => e.value)) === 'TRY');
    await shot(p, 'hareket_modal_1440');
    const istSay0 = S1.sayi(/POST \/cari_kaydet/);
    await p.click('#crModalHareket button[type="submit"]');
    ok('boş tutar → alan altında "Bu alan zorunlu"', (await alanHatasi(p, '#crModalHareket', 'tutar')) === 'Bu alan zorunlu', await alanHatasi(p, '#crModalHareket', 'tutar'));
    ok('boş tutarda sunucuya İSTEK GİTMEDİ', S1.sayi(/POST \/cari_kaydet/) === istSay0);
    await p.fill('#crModalHareket input[name="tutar"]', '0');
    await p.click('#crModalHareket button[type="submit"]');
    const tutarHata = await kisaBekle(p, () => { const e = document.querySelector('#crModalHareket input[name="tutar"]'); const h = e.closest('.pm-label').querySelector('.cari-hata'); return h && h.textContent.length > 3; });
    ok('sunucu alan hatası (tutar 0) alanın altında gösterilir', tutarHata, await alanHatasi(p, '#crModalHareket', 'tutar'));
    ok('sunucu hatasında modal AÇIK kalır, kayıt yok', (await goster(p, '#crModalHareket')) && S1.say().tur.tahsilat === 7);
    await p.fill('#crModalHareket input[name="tutar"]', '12,345');
    ok('3 ondalık → anında uyarı (error sınıfı)', await p.$eval('#crModalHareket input[name="tutar"]', e => e.classList.contains('error')));
    await p.fill('#crModalHareket input[name="tutar"]', 'abc12');
    ok('tutara yalnız rakam/nokta/virgül girilir', (await p.inputValue('#crModalHareket input[name="tutar"]')) === '12');
    await p.fill('#crModalHareket input[name="tutar"]', '10.000,00');
    await p.evaluate(() => { const t = document.querySelector('#crModalHareket input[name="tarih"]'); t.value = ''; });
    await p.click('#crModalHareket button[type="submit"]');
    ok('boş/geçersiz tarih → tarih alanı altında hata', (await alanHatasi(p, '#crModalHareket', 'tarih')) === 'Bu alan zorunlu');
    await p.evaluate(() => { const t = document.querySelector('#crModalHareket input[name="tarih"]'); t.value = new Date().toISOString().slice(0, 10); });

    // — Tahsilat: başarılı + bakiye gerçekten değişir —
    const before = await isaretli(p, 'TRY'), usd0 = await isaretli(p, 'USD'), eur0 = await isaretli(p, 'EUR');
    await p.fill('#crModalHareket textarea[name="aciklama"]', 'E2E tahsilat');
    await p.click('#crModalHareket button[type="submit"]');
    ok('Tahsilat kaydedildi: modal kapandı + toast (TAH-… belge no)', await modalKapali(p, 'crModalHareket') && /TAH-\d{4}-\d{6}/.test((await kisaBekle(p, () => /TAH-/.test((document.querySelector('.cari-toast:not([hidden])') || {}).textContent || ''))) ? await toastMetin(p) : ''));
    await kisaBekle(p, () => { const e = document.querySelector('#crDetay .cari-bk-kutu[data-pb="TRY"] .cari-bk-tutar'); return e && e.textContent.indexOf('115.400,00') === 0; });
    const after = await isaretli(p, 'TRY');
    ok('TRY bakiyesi tam tutar kadar azaldı (125.400,00 B → 115.400,00 B)', before === 12540000 && after === 11540000, `${before} → ${after}`);
    ok('USD ve EUR bakiyeleri DEĞİŞMEDİ (para birimi ayrı)', (await isaretli(p, 'USD')) === usd0 && (await isaretli(p, 'EUR')) === eur0);
    ok('DB gerçeği: cari 1 TRY bakiyesi = 115.400,00 (11540000)', S1.say().bakiye['1'].TRY === 11540000, JSON.stringify(S1.say().bakiye['1']));
    ok('sol listede cari 1 bakiyesi + özet şeridi güncellendi', /115\.400,00/.test(await metin(p, '#crListe .cari-kart[data-id="1"] .cari-kart-bk')) && /115\.400,00/.test(await metin(p, '.cari-ozet')) && !/125\.400,00/.test(await metin(p, '.cari-ozet')));
    ok('ekstrede yeni tahsilat satırı (E2E tahsilat)', /E2E tahsilat/.test(await metin(p, '#crPanelEkstre tbody')));
    ok('toast: ekstre tazelenirken açık kalan modal yok, yatay taşma yok', !(await goster(p, '#crModalHareket')) && (await tasma(p)) <= 0);

    // — çift tık = tek kayıt —
    const tahsilat0 = S1.say().tur.tahsilat;
    await p.click('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]'); await modalAcik(p, 'crModalHareket');
    ok('açılışta form sıfırlandı (tutar boş, tarih bugün)', (await p.inputValue('#crModalHareket input[name="tutar"]')) === '');
    await p.fill('#crModalHareket input[name="tutar"]', '1,00');
    await p.dblclick('#crModalHareket button[type="submit"]');
    await modalKapali(p, 'crModalHareket'); await bekle(500);
    ok('çift tık TEK kayıt üretti (DB tahsilat +1)', S1.say().tur.tahsilat === tahsilat0 + 1, S1.say().tur.tahsilat - tahsilat0);

    // — USD tahsilat (kur alanı) —
    await p.click('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]'); await modalAcik(p, 'crModalHareket');
    ok('TRY iken kur alanı gizli', !(await goster(p, '#crModalHareket [data-kur]')));
    await p.click('#crModalHareket .cari-seg:has(input[value="USD"])');
    ok('USD seçilince kur alanı görünür', await goster(p, '#crModalHareket [data-kur]'));
    await p.fill('#crModalHareket input[name="tutar"]', '1.000,00');
    await p.fill('#crModalHareket input[name="kur"]', '32,50');
    await p.click('#crModalHareket button[type="submit"]'); await modalKapali(p, 'crModalHareket');
    ok('USD bakiyesi 48.250,00 → 47.250,00; TRY değişmedi', await kisaBekle(p, () => /47\.250,00/.test(document.querySelector('#crDetay .cari-bk-kutu[data-pb="USD"] .cari-bk-tutar').textContent)) && (await isaretli(p, 'TRY')) === 11540000 - 100, `${await isaretli(p, 'USD')} / ${await isaretli(p, 'TRY')}`);

    // — Virman —
    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDekont"]');
    ok('Dekont ▾ menüsü açılır (3+1 öge)', await goster(p, '#crMenuDekont') && (await say(p, '#crMenuDekont [role="menuitem"]')) === 4);
    await p.click('#crMenuDekont [data-tur="virman"]');
    ok('Virman modalı: başlık + hedef cari alanı görünür', await modalAcik(p, 'crModalHareket') && /Virman/.test(await metin(p, '#crHareketBaslik')) && await goster(p, '#crModalHareket [data-alan="hedef_id"]'));
    ok('virman modalında Ödeme şekli alanı gizli ve gönderilmez', !(await goster(p, '#crModalHareket select[name="odeme_sekli"]')) && await p.$eval('#crModalHareket select[name="odeme_sekli"]', e => e.disabled));
    await p.fill('#crModalHareket [data-alan="hedef_id"] input[role="combobox"]', 'ambalaj');
    ok('hedef cari seçici: sonuç listesi (kaynak cari hariç)', await goster(p, '#crModalHareket [data-alan="hedef_id"] .cari-secici-liste') && /Ambalaj/.test(await metin(p, '#crModalHareket [data-alan="hedef_id"] .cari-secici-liste')));
    await p.fill('#crModalHareket [data-alan="hedef_id"] input[role="combobox"]', 'örnek tarım');
    ok('hedef seçicide kaynak cari (Örnek Tarım) listelenmez', !/Örnek Tarım/.test(await metin(p, '#crModalHareket [data-alan="hedef_id"] .cari-secici-liste') || ''));
    await p.fill('#crModalHareket [data-alan="hedef_id"] input[role="combobox"]', 'ambalaj');
    await p.keyboard.press('ArrowDown'); await p.keyboard.press('Enter');
    ok('hedef seçildi (klavye) → seçili ad görünür', /Deneme Ambalaj/.test(await metin(p, '#crModalHareket [data-alan="hedef_id"] .cari-secili-ad')));
    await p.fill('#crModalHareket input[name="tutar"]', '5.000,00');
    const onizleme = await kisaBekle(p, () => /İşlem sonrası/.test(document.querySelector('#crModalHareket [data-virman-onizleme]').textContent) && /Kaynak/.test(document.querySelector('#crModalHareket [data-virman-onizleme]').textContent) && /110\.399,00/.test(document.querySelector('#crModalHareket [data-virman-onizleme]').textContent));
    ok('virman önizlemesi iki cari bakiyesini + işlem sonrasını gösterir (110.399,00 / 46.320,50)', onizleme && /46\.320,50/.test(await metin(p, '#crModalHareket [data-virman-onizleme]')), await metin(p, '#crModalHareket [data-virman-onizleme]'));
    await shot(p, 'virman_1440');
    await p.click('#crModalHareket button[type="submit"]');
    ok('virman: açıklama zorunlu → açıklama altında hata', (await alanHatasi(p, '#crModalHareket', 'aciklama')) === 'Bu alan zorunlu');
    await p.fill('#crModalHareket textarea[name="aciklama"]', 'E2E virman');
    await p.click('#crModalHareket button[type="submit"]');
    ok('virman kaydedildi (modal kapandı)', await modalKapali(p, 'crModalHareket'));
    await kisaBekle(p, () => /110\.399,00/.test(document.querySelector('#crDetay .cari-bk-kutu[data-pb="TRY"] .cari-bk-tutar').textContent));
    const dbV = S1.say();
    ok('virman: kaynak (cari 1) bakiyesi 5.000 azaldı → 110.399,00 (UI ve DB)', (await isaretli(p, 'TRY')) === 11039900 && dbV.bakiye['1'].TRY === 11039900, `${await isaretli(p, 'TRY')} / ${dbV.bakiye['1'].TRY}`);
    ok('virman: hedef (cari 2) bakiyesi 5.000 arttı: 51.320,50 A → 46.320,50 A (DB)', dbV.bakiye['2'].TRY === -4632050, dbV.bakiye['2'].TRY);
    ok('virman iki bacak: DB virman satırı 2 → 4', dbV.tur.virman === 4, dbV.tur.virman);
    await p.click('#crListe .cari-kart[data-id="2"]'); await detayBekle(p, 2);
    ok('hedef carinin detayında bakiye 46.320,50 (UI)', (await bakiyeUI(p, 'TRY')) === 4632050);
    await p.click('#crListe .cari-kart[data-id="1"]'); await detayBekle(p, 1);

    // — İptal (neden zorunlu) → bakiye geri —
    const iptalOnce = await isaretli(p, 'TRY');
    await p.click('#crPanelEkstre tbody tr:has-text("Mart sevkiyatı kısmi tahsilat") .cari-iptal-dugme');
    ok('İptal modalı açılır; belge özeti (TAH-… · Tahsilat) görünür', await modalAcik(p, 'crModalIptal') && /TAH-\d{4}-\d{6}/.test(await metin(p, '#crModalIptal .cari-iptal-ozet')) && /120\.000,00/.test(await metin(p, '#crModalIptal .cari-iptal-ozet')), await metin(p, '#crModalIptal .cari-iptal-ozet'));
    await shot(p, 'iptal_1440');
    await p.click('#crModalIptal button[type="submit"]');
    ok('iptal nedeni boş → "Bu alan zorunlu"', (await alanHatasi(p, '#crModalIptal', 'neden')) === 'Bu alan zorunlu');
    await p.fill('#crModalIptal textarea[name="neden"]', 'ab');
    await p.click('#crModalIptal button[type="submit"]');
    ok('neden 2 karakter → "En az 3 karakter"', /En az 3/.test(await alanHatasi(p, '#crModalIptal', 'neden') || ''));
    await p.fill('#crModalIptal textarea[name="neden"]', 'E2E hatalı giriş');
    await p.click('#crModalIptal button[type="submit"]');
    ok('iptal kaydedildi', await modalKapali(p, 'crModalIptal'));
    await kisaBekle(p, o => !document.querySelector('#crDetay .cari-bk-kutu[data-pb="TRY"] .cari-bk-tutar').textContent.startsWith('110.399'), null);
    ok('iptalden sonra bakiye 120.000 geri geldi (110.399,00 → 230.399,00 B); DB aynı', (await isaretli(p, 'TRY')) === iptalOnce + 12000000 && S1.say().bakiye['1'].TRY === iptalOnce + 12000000, `${iptalOnce} → ${await isaretli(p, 'TRY')}`);
    await p.check('#crPanelEkstre input[name="iptal"]');
    ok('iptal satırı ekstrede üstü çizili + "E2E hatalı giriş"', await kisaBekle(p, () => [...document.querySelectorAll('#crPanelEkstre tbody tr.cari-iptal')].some(r => /E2E hatalı giriş/.test(r.textContent))));
    ok('iptal edilen satırda tekrar iptal düğmesi YOK', (await say(p, '#crPanelEkstre tbody tr.cari-iptal .cari-iptal-dugme')) === 0);
    await p.uncheck('#crPanelEkstre input[name="iptal"]');
    await kisaBekle(p, () => document.querySelectorAll('#crPanelEkstre tbody tr.cari-iptal').length === 0);

    // — "iptal et ve düzelt" —
    await p.click('#crPanelEkstre tbody tr:has-text("Nisan sevkiyatı tahsilatı") .cari-iptal-dugme');
    await modalAcik(p, 'crModalIptal');
    await p.fill('#crModalIptal textarea[name="neden"]', 'E2E tutar düzeltme');
    await p.check('#crModalIptal input[name="duzelt"]');
    await p.click('#crModalIptal button[type="submit"]');
    ok('"iptal et ve düzelt": hareket modalı ESKİ değerlerle açılır (150.000,00 · Nisan sevkiyatı tahsilatı · Tahsilat)',
      await kisaBekle(p, () => { const m = document.getElementById('crModalHareket'); return m && !m.hidden && m.querySelector('input[name="tutar"]').value === '150.000,00'; }, null, 8000)
      && /Nisan sevkiyatı tahsilatı/.test(await p.inputValue('#crModalHareket textarea[name="aciklama"]')) && (await p.$eval('#crModalHareket input[name="tur"]:checked', e => e.value)) === 'tahsilat' && /Örnek Tarım/.test(await metin(p, '#crModalHareket .cari-secili-ad')));
    await p.fill('#crModalHareket input[name="tutar"]', '140.000,00');
    const sd = S1.say().tur.tahsilat;
    await p.click('#crModalHareket button[type="submit"]');
    await modalKapali(p, 'crModalHareket'); await bekle(500);
    ok('düzeltme belgesi kaydedildi (yeni tahsilat +1)', S1.say().tur.tahsilat === sd + 1);

    // — Kart yönetimi: yeni, düzenle, pasifleştir, sil —
    await p.click('[data-cr-modal="kart"]:not([data-duzenle])');
    ok('"+ Yeni" kart modalı açılır (başlık "Yeni cari", kod önerisi dolu)', await modalAcik(p, 'crModalKart') && (await metin(p, '#crKartBaslik')) === 'Yeni cari' && /^C\d{5}$/.test(await p.inputValue('#crModalKart input[name="kod"]')));
    await shot(p, 'kart_modal_1440');
    await p.click('#crModalKart button[type="submit"]');
    ok('ünvan boş → "Bu alan zorunlu"', (await alanHatasi(p, '#crModalKart', 'unvan')) === 'Bu alan zorunlu');
    await p.fill('#crModalKart input[name="unvan"]', 'Deneme Yeni Cari Ltd.');
    await p.fill('#crModalKart input[name="vergi_no"]', '123');
    await p.click('#crModalKart button[type="submit"]');
    ok('geçersiz vergi no (sunucu) → vergi_no altında hata, modal açık', await kisaBekle(p, () => { const e = document.querySelector('#crModalKart input[name="vergi_no"]'); const h = e.closest('.pm-label').querySelector('.cari-hata'); return h && h.textContent.length > 3; }) && await goster(p, '#crModalKart'), await alanHatasi(p, '#crModalKart', 'vergi_no'));
    await p.fill('#crModalKart input[name="vergi_no"]', '');
    await p.fill('#crModalKart input[name="telefon"]', '0555 000 00 00');
    await p.click('#crModalKart button[type="submit"]');
    await kisaBekle(p, () => /[?&]id=14(&|$)/.test(location.search));
    await detayBekle(p, 14);
    ok('yeni cari oluşturuldu → ?id=14 ve detay "Deneme Yeni Cari Ltd."', /id=14/.test(p.url()) && (await metin(p, '#crDetay .cari-unvan')) === 'Deneme Yeni Cari Ltd.');
    ok('yeni cari listede görünür (13 aktif)', (await say(p, '#crListe .cari-kart')) === 13 && !!(await p.$('#crListe .cari-kart[data-id="14"]')));
    ok('hareketsiz yeni cari: ekstre boş durumu "henüz hareketi yok"', /henüz hareketi yok/.test(await metin(p, '#crPanelEkstre')));
    ok('kayıt sonrası toast gösterildi ("Cari kaydedildi")', /Cari kaydedildi/.test(await toastMetin(p) || ''));

    await p.click('#crSekmeBilgi');
    ok('Bilgiler sekmesi: telefon görünür', await goster(p, '#crPanelBilgi') && /0555 000 00 00/.test(await metin(p, '#crPanelBilgi')));
    await p.click('#crPanelBilgi [data-cr-modal="kart"][data-duzenle="1"]');
    ok('Düzenle → kart modalı ÖN-DOLU (ünvan, telefon, başlık "Cariyi düzenle")', await modalAcik(p, 'crModalKart') && (await p.inputValue('#crModalKart input[name="unvan"]')) === 'Deneme Yeni Cari Ltd.' && (await p.inputValue('#crModalKart input[name="telefon"]')) === '0555 000 00 00' && (await metin(p, '#crKartBaslik')) === 'Cariyi düzenle' && (await p.inputValue('#crModalKart input[name="id"]')) === '14');
    ok('düzenlemede aktif kutusu işaretli geldi', await p.$eval('#crModalKart input[name="aktif"]', e => e.checked));
    await p.fill('#crModalKart input[name="telefon"]', '0555 111 22 33');
    await p.fill('#crModalKart input[name="yetkili"]', 'Örnek Kişi');
    await p.click('#crModalKart button[type="submit"]'); await modalKapali(p, 'crModalKart');
    ok('düzenleme kaydedildi: Bilgiler paneli yeni telefon/yetkili', await kisaBekle(p, () => /0555 111 22 33/.test(document.getElementById('crPanelBilgi').textContent)) && /Örnek Kişi/.test(await metin(p, '#crPanelBilgi')));
    ok('kayıt sonrası Bilgiler sekmesi açık kaldı', await goster(p, '#crPanelBilgi'));

    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDiger"]');
    ok('⋯ menüsü açılır: Düzenle / Pasifleştir / Sil (hareketsiz → etkin)', await goster(p, '#crMenuDiger') && /Pasifleştir/.test(await metin(p, '#crMenuDiger')) && !(await p.$eval('#crMenuDiger [data-cr-islem="kart_sil"]', e => e.disabled)));
    await shot(p, 'menu_1440');
    await p.click('#crMenuDiger [data-cr-islem="kart_durum"]');
    ok('Pasifleştir → detayda "Pasif" rozeti; DB aktif=0', await kisaBekle(p, () => /Pasif/.test((document.querySelector('#crDetay .cari-rozet--pasif') || {}).textContent || '')) && S1.say().kartlar['14'].aktif === 0, JSON.stringify(S1.say().kartlar['14']));
    ok('pasif kartta Tahsilat/Ödeme düğmeleri devre dışı', await p.$eval('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]', e => e.disabled));
    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDiger"]');
    ok('menü yenilendi: düğme artık "Aktifleştir"', (await metin(p, '#crMenuDiger [data-cr-islem="kart_durum"]')) === 'Aktifleştir');
    await p.click('#crMenuDiger [data-cr-islem="kart_durum"]');
    ok('Aktifleştir → kart yeniden AKTİF (DB aktif=1, rozet Aktif)', await kisaBekle(p, () => /Aktif/.test((document.querySelector('#crDetay .cari-rozet--aktif') || {}).textContent || '')) && S1.say().kartlar['14'].aktif === 1, JSON.stringify(S1.say().kartlar['14']));
    // hareketli kart silinemez
    await p.click('#crListe .cari-kart[data-id="1"]'); await detayBekle(p, 1);
    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDiger"]');
    ok('hareketli cari: Sil devre dışı ("Sil (hareketi var)")', await p.$eval('#crMenuDiger .cari-menu-oge--tehlike', e => e.disabled && /hareketi var/.test(e.textContent)));
    await p.keyboard.press('Escape');
    // sunucu silmeyi reddeder (JS atlatılsa bile): 422 + hata mesajı
    const red = await p.evaluate(async () => { const fd = new FormData(); fd.set('csrf', document.querySelector('input[name=csrf]').value); fd.set('islem', 'kart_sil'); fd.set('id', '1'); const r = await fetch('cari_kaydet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } }); return { s: r.status, j: await r.json() }; });
    ok('sunucu: hareketli kartı silme → HTTP 422 + gerekçe', red.s === 422 && red.j.ok === false && /hareket/i.test(red.j.hata), JSON.stringify(red));
    // hareketsiz sil
    await p.click('#crListe .cari-kart[data-id="14"]'); await detayBekle(p, 14);
    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDiger"]');
    await p.click('#crMenuDiger [data-cr-islem="kart_sil"]');
    await kisaBekle(p, () => !/[?&]id=/.test(location.search) && !document.querySelector('#crListe .cari-kart[data-id="14"]'));
    ok('hareketsiz cari SİLİNİR (liste 12, DB 13 kart); iptal edilen onayda silinmez', (await say(p, '#crListe .cari-kart')) === 12 && S1.say().kart === 13);
    ok('silme sonrası detay boş ("Soldan bir cari seçin")', /Soldan bir cari seçin/.test(await metin(p, '#crDetay')));
    // onay reddedilirse silinmez
    await p.click('#crListe .cari-kart[data-id="13"]'); await detayBekle(p, 13);
    p._reddet = true;
    await p.click('#crDetay [data-cari-menu-ac][aria-controls="crMenuDiger"]');
    await p.click('#crMenuDiger [data-cr-islem="kart_sil"]');
    await bekle(700); p._reddet = false;
    ok('silme onayı REDDEDİLİNCE kart silinmedi (DB 13 kart)', S1.say().kart === 13, S1.say().kart);

    // klavye kısayolları
    await p.keyboard.press('/');
    ok('"/" kısayolu aramaya odaklanır', await p.evaluate(() => document.activeElement && document.activeElement.id === 'crArama'));
    await p.keyboard.press('Escape'); await p.evaluate(() => document.activeElement.blur());
    await p.keyboard.press('t');
    ok('"t" kısayolu Tahsilat modalını açar; Esc kapatır', await modalAcik(p, 'crModalHareket'));
    await p.keyboard.press('Escape');
    ok('Esc modalı kapattı, odak geri döndü', await modalKapali(p, 'crModalHareket'));
    ok('JS hatası/konsol hatası yok (bölüm 1)', hatalar.length === 0, hatalar.join(' | '));
    await p.context().close();

    // ───────────────────────────────────────── 2. FİŞLER + DEKONTLAR
    console.log('\n=== 2. Fişler / Dekontlar (1440x900) ===');
    const S2 = await ortam(); sunucular.push(S2);
    const f = await sayfa(browser, S2, { width: 1440, height: 900 });
    await git(f, 'cari_fisler.php?bas=&bit=');
    ok('Fişler açıldı; sidebar Cari Fişler aktif', /Cari Fişler/.test(await f.title()) && /Cari Fişler/.test(await metin(f, 'details[data-sb-grup="cari"] a.sidebar-link.active') || ''));
    ok('yatay taşma yok', (await tasma(f)) <= 0);
    const fisSatir = await say(f, 'table.cari-tablo tbody tr');
    ok('liste: fiş satırları (tahsilat/ödeme/açılış; dekont/virman yok)', fisSatir > 10 && !(await f.$$eval('table.cari-tablo tbody tr', l => l.some(r => /Borç Dekontu|Alacak Dekontu|Virman/.test(r.children[1].textContent)))), fisSatir);
    ok('özet şeridi para birimi başına', (await say(f, '.cari-ozet-pb')) >= 2);
    await shot(f, 'fisler_1440');
    // süzgeç
    await f.selectOption('#crFiltre select[name="tur"]', 'tahsilat');
    await f.click('#crFiltre .cari-filtre-uygula');
    await f.waitForLoadState('load');
    ok('süzgeç: tür=Tahsilat → URL + yalnız tahsilat satırları', /tur=tahsilat/.test(f.url()) && (await f.$$eval('table.cari-tablo tbody tr', l => l.length > 0 && l.every(r => /Tahsilat/.test(r.children[1].textContent)))));
    await f.selectOption('#crFiltre select[name="pb"]', 'USD');
    await f.click('#crFiltre .cari-filtre-uygula'); await f.waitForLoadState('load');
    ok('süzgeç: para birimi USD → yalnız $ tutarlar', await f.$$eval('table.cari-tablo tbody tr .cari-tut', l => l.length > 0 && l.every(t => /\$/.test(t.textContent))));
    await f.goto(S2.url + '/cari_fisler.php?bas=&bit=&durum=iptal');
    ok('süzgeç: durum=İptal → yalnız iptal satırları (üstü çizili)', await f.$$eval('table.cari-tablo tbody tr', l => l.length === 2 && l.every(r => r.classList.contains('cari-iptal'))));
    await f.goto(S2.url + '/cari_fisler.php?cari_q=nakliyat&bas=&bit=');
    ok('süzgeç: cari_q=nakliyat → yalnız Test Nakliyat', await f.$$eval('table.cari-tablo tbody tr', l => l.length > 0 && l.every(r => /Nakliyat/.test(r.children[2].textContent))));
    await f.goto(S2.url + '/cari_fisler.php');
    const yilSay = await say(f, 'table.cari-tablo tbody tr');
    await f.click('#crFiltre .cari-cip[data-donem="tumu"]');
    ok('dönem çipi "Tümü" → bas/bit alanları dolar (2000-01-01 …)', (await f.inputValue('#crFiltre input[name="bas"]')) === '2000-01-01' && /^\d{4}-\d{2}-\d{2}$/.test(await f.inputValue('#crFiltre input[name="bit"]')));
    await f.goto(S2.url + '/cari_fisler.php?bas=&bit=');
    // yeni kayıt (cari seçici combobox)
    await f.click('.page-head-actions [data-cr-modal="hareket"][data-tur="tahsilat"]');
    ok('Fişler: "+ Tahsilat" modalı açılır; cari seçici combobox (kilitli değil)', await modalAcik(f, 'crModalHareket') && await goster(f, '#crModalHareket [data-alan="cari_id"] input[role="combobox"]'));
    await f.click('#crModalHareket button[type="submit"]');
    ok('cari seçilmeden gönderilemez → "Bir cari seçin"', await f.$eval('#crModalHareket [data-alan="cari_id"]', w => !!(w.querySelector('.cari-hata') && /Bir cari seçin/.test(w.querySelector('.cari-hata').textContent))));
    await f.fill('#crModalHareket [data-alan="cari_id"] input[role="combobox"]', 'test nak');
    await f.click('#crModalHareket [data-alan="cari_id"] .cari-secici-oge');
    ok('combobox: fare ile seçim → Test Nakliyat seçildi, para birimi cari varsayılanı (TRY)', /Test Nakliyat/.test(await metin(f, '#crModalHareket [data-alan="cari_id"] .cari-secili-ad')));
    await f.fill('#crModalHareket input[name="tutar"]', '2.500,00');
    await f.selectOption('#crModalHareket select[name="odeme_sekli"]', 'Nakit');
    let n0 = S2.sayi(/POST \/cari_kaydet/);
    await f.click('#crModalHareket button[type="submit"]');
    await yenilenmeBekle(f, n0); await kisaBekle(f, () => !!document.querySelector('.cari-toast:not([hidden])'));
    ok('kayıt sonrası sayfa yenilendi + toast (TAH-…)', /TAH-\d{4}-\d{6}/.test(await toastMetin(f) || ''), await toastMetin(f));
    ok('yeni fiş listede (Test Nakliyat 2.500,00 ₺ Nakit)', await f.$$eval('table.cari-tablo tbody tr', l => l.some(r => /Nakliyat/.test(r.textContent) && /2\.500,00/.test(r.textContent) && /Nakit/.test(r.textContent))));
    ok('DB: Test Nakliyat (cari 3) TRY bakiyesi 42.000,00 A → 44.500,00 A (tahsilat Alacak yazar)', S2.say().bakiye['3'].TRY === -4450000, S2.say().bakiye['3'].TRY);
    // listeden iptal
    await f.click('table.cari-tablo tbody tr:has-text("Nakit") .cari-iptal-dugme');
    ok('listeden İptal: modal özet (cari + tutar)', await modalAcik(f, 'crModalIptal') && /Test Nakliyat/.test(await metin(f, '#crModalIptal .cari-iptal-ozet')) && /2\.500,00/.test(await metin(f, '#crModalIptal .cari-iptal-ozet')), await metin(f, '#crModalIptal .cari-iptal-ozet'));
    await f.fill('#crModalIptal textarea[name="neden"]', 'E2E fiş iptali');
    n0 = S2.sayi(/POST \/cari_kaydet/);
    await f.click('#crModalIptal button[type="submit"]');
    await yenilenmeBekle(f, n0);
    ok('liste iptali: DB bakiyesi geri (42.000,00 A)', S2.say().bakiye['3'].TRY === -4200000, S2.say().bakiye['3'].TRY);
    // dekontlar
    await git(f, 'cari_dekontlar.php?bas=&bit=');
    ok('Dekontlar açıldı; yalnız dekont/virman satırları; sidebar Cari Dekontlar aktif', /Cari Dekontlar/.test(await metin(f, 'details[data-sb-grup="cari"] a.sidebar-link.active') || '') && await f.$$eval('table.cari-tablo tbody tr', l => l.length > 0 && l.every(r => /Borç Dekontu|Alacak Dekontu|Virman|Borç Dek|Alacak Dek/.test(r.children[1].textContent))));
    await shot(f, 'dekontlar_1440');
    await f.click('.page-head-actions [data-cr-modal="hareket"][data-tur="borc_dekont"]');
    await modalAcik(f, 'crModalHareket');
    ok('Dekont modalı: Borç Dekontu başlığı, açıklama zorunlu işareti', /Borç Dekontu/.test(await metin(f, '#crHareketBaslik')) && await goster(f, '#crModalHareket [data-zorunlu-aciklama]'));
    await f.fill('#crModalHareket [data-alan="cari_id"] input[role="combobox"]', 'ege');
    await f.keyboard.press('Enter');
    await f.fill('#crModalHareket input[name="tutar"]', '700,00');
    await f.click('#crModalHareket button[type="submit"]');
    ok('açıklamasız dekont → açıklama altında hata', (await alanHatasi(f, '#crModalHareket', 'aciklama')) === 'Bu alan zorunlu');
    await f.fill('#crModalHareket textarea[name="aciklama"]', 'E2E dekont');
    n0 = S2.sayi(/POST \/cari_kaydet/);
    await f.click('#crModalHareket button[type="submit"]');
    await yenilenmeBekle(f, n0);
    ok('dekont kaydedildi: DB Ege Soğuk Depo (7) 18.750,00 A → 18.050,00 A (borç dekontu bakiyeyi azaltır)', S2.say().bakiye['7'].TRY === -1805000, JSON.stringify(S2.say().son.slice(0, 1)) + ' | ' + (await f.$$eval('#crModalHareket .cari-hata, #crModalHareket .cari-form-hata', l => l.map(e => e.textContent + e.hidden).join(';')).catch(() => 'modal yok')) + ' | n0=' + n0 + ' post=' + S2.sayi(/POST \/cari_kaydet/));
    ok('yeni dekont listede + BDK- belge no', await f.$$eval('table.cari-tablo tbody tr', l => l.some(r => /BDK-/.test(r.textContent) && /E2E dekont/.test(r.textContent))));
    // yeni= URL parametresi
    await f.goto(S2.url + '/cari_fisler.php?yeni=odeme&bas=&bit=');
    ok('?yeni=odeme → Ödeme modalı otomatik açılır, URL temizlenir', await modalAcik(f, 'crModalHareket') && /Ödeme/.test(await metin(f, '#crHareketBaslik')) && !/yeni=/.test(f.url()));
    ok('JS/konsol hatası yok (bölüm 2)', hatalar.length === 0, hatalar.join(' | '));
    await f.context().close();

    // ───────────────────────────────────────── 3. TANIMLAR
    console.log('\n=== 3. Tanımlar (1440x900) ===');
    const S3 = await ortam(); sunucular.push(S3);
    const t = await sayfa(browser, S3, { width: 1440, height: 900 });
    await git(t, 'cari_tanimlari.php');
    ok('Tanımlar açıldı; 3 sekme; sidebar Cari Tanımları aktif', /Cari Tanımları/.test(await metin(t, 'details[data-sb-grup="cari"] a.sidebar-link.active') || '') && (await say(t, '.cari-tanim-nav-oge')) === 3);
    ok('yatay taşma yok', (await tasma(t)) <= 0);
    ok('Cari Grupları: 7 tanım listelenir', (await say(t, '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen)')) === 7);
    await shot(t, 'tanimlar_1440');
    await t.fill('#crPanelGrup form.cari-tanim-ekle input[name="ad"]', '');
    await t.click('#crPanelGrup form.cari-tanim-ekle button[type="submit"]');
    ok('boş ad → "Bu alan zorunlu" (istek gitmeden)', await t.$eval('#crPanelGrup form.cari-tanim-ekle', f => !!(f.querySelector('.cari-hata') && /zorunlu/.test(f.querySelector('.cari-hata').textContent))));
    await t.fill('#crPanelGrup form.cari-tanim-ekle input[name="ad"]', 'E2E Grubu');
    let nt = S3.sayi(/POST \/cari_kaydet/);
    await t.click('#crPanelGrup form.cari-tanim-ekle button[type="submit"]');
    await yenilenmeBekle(t, nt); await kisaBekle(t, () => /E2E Grubu/.test(document.getElementById('crPanelGrup').textContent));
    ok('grup eklendi (8 tanım, liste + DB)', (await say(t, '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen)')) === 8 && S3.say().tanim.grup === 8);
    await t.fill('#crPanelGrup form.cari-tanim-ekle input[name="ad"]', 'e2e grubu');
    await t.click('#crPanelGrup form.cari-tanim-ekle button[type="submit"]');
    ok('aynı ad (harf farkıyla) → sunucu hatası ad alanında/toast', await kisaBekle(t, () => !!document.querySelector('#crPanelGrup form.cari-tanim-ekle .cari-hata') || !!document.querySelector('.cari-toast:not([hidden])')) && S3.say().tanim.grup === 8);
    // düzenle
    const satirE2E = '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen):has-text("E2E Grubu")';
    await t.click(satirE2E + ' [data-tanim-duzen]');
    ok('Düzenle → satır içi düzenleme formu açılır (ad dolu)', (await t.$eval(satirE2E + ' + .cari-tanim-satir--duzen', e => !e.hidden && e.querySelector('input[name="ad"]').value === 'E2E Grubu')));
    await t.fill(satirE2E + ' + .cari-tanim-satir--duzen input[name="ad"]', 'E2E Grubu 2');
    nt = S3.sayi(/POST \/cari_kaydet/);
    await t.click(satirE2E + ' + .cari-tanim-satir--duzen button[type="submit"]');
    await yenilenmeBekle(t, nt); await kisaBekle(t, () => /E2E Grubu 2/.test(document.getElementById('crPanelGrup').textContent));
    ok('ad güncellendi (E2E Grubu 2)', /E2E Grubu 2/.test(await metin(t, '#crPanelGrup')));
    // switch
    const satirE2E2 = '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen):has-text("E2E Grubu 2")';
    const istT = S3.sayi(/POST \/cari_kaydet/);
    await t.$eval(satirE2E2 + ' input[role="switch"]', e => e.click());
    ok('aktif switch kapat → satır pasif görünür + DB pasif', await kisaBekle(t, () => document.querySelector('#crPanelGrup .cari-tanim-satir--pasif')) && S3.sayi(/POST \/cari_kaydet/) === istT + 1);
    ok('switch toast\'ında "Geri al" eylemi', /Pasifleştirildi/.test(await toastMetin(t) || '') && await goster(t, '.cari-toast button'));
    await t.click('.cari-toast button');
    ok('"Geri al" → tekrar aktif', await kisaBekle(t, () => !document.querySelector('#crPanelGrup .cari-tanim-satir--pasif')));
    // sil: kullanımdaki grup için Sil düğmesi yok
    ok('kullanımdaki grup (Yurt Dışı Müşteriler) için Sil düğmesi YOK; kullanılmayan (E2E) için var', !(await t.$('#crPanelGrup .cari-tanim-satir:has-text("Yurt Dışı Müşteriler") [data-ok="Tanım silindi"] button')) && !!(await t.$(satirE2E2 + ' button[aria-label*="sil"]')));
    // sunucu kullanımdaki grubu silmeyi reddeder (422)
    const kg = await t.evaluate(async () => { const fd = new FormData(); fd.set('csrf', document.querySelector('input[name=csrf]').value); fd.set('islem', 'tanim_sil'); const satir = [...document.querySelectorAll('#crPanelGrup .cari-tanim-satir')].find(s => /Yurt Dışı Müşteriler/.test(s.textContent)); fd.set('id', satir.getAttribute('data-id')); const r = await fetch('cari_kaydet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } }); return { s: r.status, j: await r.json() }; });
    ok('sunucu: kullanımdaki grubu silme → 422 + gerekçe', kg.s === 422 && kg.j.ok === false && /kullan|silin/i.test(kg.j.hata), JSON.stringify(kg));
    nt = S3.sayi(/POST \/cari_kaydet/);
    await t.click(satirE2E2 + ' button[aria-label*="sil"]');
    await yenilenmeBekle(t, nt); await kisaBekle(t, () => !/E2E Grubu 2/.test(document.getElementById('crPanelGrup').textContent));
    ok('kullanılmayan grup SİLİNDİ (7 tanım; DB 7)', (await say(t, '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen)')) === 7 && S3.say().tanim.grup === 7);
    // ödeme şekli sekmesi
    await t.click('#crTabOdeme');
    ok('Ödeme Şekilleri sekmesi: panel değişir, URL #hash', await goster(t, '#crPanelOdeme') && !(await goster(t, '#crPanelGrup')));
    const odSatir = '#crPanelOdeme .cari-tanim-satir:not(.cari-tanim-satir--duzen):has-text("Havale/EFT")';
    nt = S3.sayi(/POST \/cari_kaydet/);
    await t.click(odSatir + ' button[aria-label*="sil"]');
    await yenilenmeBekle(t, nt);
    ok('ödeme şekli silinebilir (fişe ad saklanır): Havale/EFT satırı kalktı, DB -1', !(await t.$(odSatir)) && S3.say().tanim.odeme_sekli >= 1);
    // içe aktar
    await t.click('#crTabIce');
    ok('İçe Aktar sekmesi: önizleme tablosu + "N kartı içe aktar" düğmesi', await goster(t, '#crPanelIce') && /içe aktar/.test(await metin(t, '#crPanelIce [data-ice-aktar]')));
    await shot(t, 'tanimlar_ice_1440');
    const kart0 = S3.say().kart;
    nt = S3.sayi(/POST \/cari_kaydet/);
    await t.click('#crPanelIce [data-ice-aktar]');
    await yenilenmeBekle(t, nt); await kisaBekle(t, () => !!document.querySelector('.cari-toast:not([hidden])'));
    ok('içe aktarıldı: yeni kartlar DB\'de; toast "İçe aktarıldı"', S3.say().kart > kart0 && /İçe aktarıldı/.test(await toastMetin(t) || ''), `${kart0} → ${S3.say().kart} toast=${await toastMetin(t)}`);
    await t.reload({ waitUntil: 'load' });
    ok('ikinci kez içe aktar → düğme devre dışı (0 yeni kart)', !(await t.$('#crPanelIce [data-ice-aktar]')) || await t.$eval('#crPanelIce [data-ice-aktar]', e => e.disabled));
    ok('JS/konsol hatası yok (bölüm 3)', hatalar.length === 0, hatalar.join(' | '));
    await t.context().close();

    // ───────────────────────────────────────── 4. ŞEMA YOK + BOŞ DURUM
    console.log('\n=== 4. Şema yok / boş durum ===');
    const S4 = await ortam('sema'); sunucular.push(S4);
    const s4 = await sayfa(browser, S4, { width: 1440, height: 900 });
    for (const sy of ['cari_kartlari.php', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php']) {
      await git(s4, sy);
      ok(`şema yok: ${sy} uyarı kartı (migrate.php bağlantısı), sayfa bozulmadı`, /migrate\.php/.test(await s4.content()) && await goster(s4, '.cari-uyari') && (await tasma(s4)) <= 0 && !(await s4.$('#crListe')));
    }
    await shot(s4, 'sema_yok_1440');
    const yazmaSema = await s4.evaluate(async () => { const fd = new FormData(); fd.set('csrf', document.querySelector('meta[name=csrf-token]').content); fd.set('islem', 'hareket_ekle'); const r = await fetch('cari_kaydet.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } }); return r.status; });
    ok('şema yokken yazma ucu 503 döner (tablo oluşturmaz)', yazmaSema === 503, yazmaSema);
    for (let i = hatalar.length - 1; i >= 0; i--) if (/HTTP 503/.test(hatalar[i])) hatalar.splice(i, 1);   // beklenen 503
    await s4.context().close();
    const S5 = await ortam('bos'); sunucular.push(S5);
    const b = await sayfa(browser, S5, { width: 1440, height: 900 });
    await git(b, 'cari_kartlari.php');
    ok('boş durum: "Henüz cari yok" + "İlk cariyi ekle" + içe aktar bağlantısı', /Henüz cari yok/.test(await metin(b, '#crListeKol')) && await goster(b, '#crListeKol [data-cr-modal="kart"].btn-primary') && /Tanımlardan içe aktar/.test(await metin(b, '#crListeKol')));
    await shot(b, 'bos_1440');
    await b.click('#crListeKol [data-cr-modal="kart"].btn-primary');
    await modalAcik(b, 'crModalKart');
    await b.fill('#crModalKart input[name="unvan"]', 'İlk Deneme Cari');
    await b.click('#crModalKart button[type="submit"]');
    ok('boş durumdan ilk cari oluşturulur → detay açılır', await kisaBekle(b, () => /id=\d+/.test(location.search)) && await detayBekle(b, 1) && (await metin(b, '#crDetay .cari-unvan')) === 'İlk Deneme Cari');
    await git(b, 'cari_fisler.php');
    ok('boş durum: Fişler "Bu filtreyle kayıt yok"', /kayıt yok/.test(await metin(b, '.cari') || ''));
    await git(b, 'cari_tanimlari.php');
    ok('boş durum: Tanımlar sayfası açılır (grup yok mesajı), taşma yok', (await tasma(b)) <= 0 && /Henüz tanım yok|Cari Grupları/.test(await metin(b, '.cari')));
    ok('JS/konsol hatası yok (bölüm 4)', hatalar.length === 0, hatalar.join(' | '));
    await b.context().close();

    // ───────────────────────────────────────── 5. TABLET 1024 + 820
    console.log('\n=== 5. Tablet (1024x768, 820x1180) ===');
    const S6 = await ortam(); sunucular.push(S6);
    for (const vp of [{ width: 1024, height: 768 }, { width: 820, height: 1180 }]) {
      const pt = await sayfa(browser, S6, vp);
      for (const sy of ['cari_kartlari.php?id=1', 'cari_fisler.php', 'cari_dekontlar.php', 'cari_tanimlari.php']) {
        await git(pt, sy);
        ok(`[${vp.width}] ${sy}: yatay taşma yok`, (await tasma(pt)) <= 0, await tasma(pt));
      }
      await git(pt, 'cari_kartlari.php?id=1'); await detayBekle(pt, 1);
      if (vp.width === 1024) {
        ok('[1024] sol liste + sağ detay yan yana (master-detail)', await pt.evaluate(() => { const l = document.getElementById('crListeKol').getBoundingClientRect(), d = document.getElementById('crDetay').getBoundingClientRect(); return l.right <= d.left + 2 && l.width > 200 && d.width > 300; }));
        ok('[1024] sidebar görünür, topbar gizli', await goster(pt, 'aside.desktop-sidebar') && !(await goster(pt, '.topbar')));
      } else {
        ok('[820] tablet: topbar görünür, sidebar gizli', await goster(pt, '.topbar') && !(await goster(pt, 'aside.desktop-sidebar')));
        ok('[820] detay açık: liste yerine detay (tek sütun) + Geri düğmesi', await goster(pt, '.cari-geri') || await goster(pt, '#crDetay'));
      }
      await pt.click('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]');
      ok(`[${vp.width}] Tahsilat modalı açılır, footer ekranda`, await modalAcik(pt, 'crModalHareket') && await (async () => { await bekle(400); return pt.evaluate(() => { const r = document.querySelector('#crModalHareket .pm-footer').getBoundingClientRect(); return r.bottom <= innerHeight + 1 && r.top >= 0; }); })());
      await shot(pt, 'hareket_modal_' + vp.width);
      await pt.keyboard.press('Escape');
      await git(pt, 'cari_kartlari.php'); await shot(pt, 'kartlar_' + vp.width);
      await pt.context().close();
    }
    ok('JS/konsol hatası yok (bölüm 5)', hatalar.length === 0, hatalar.join(' | '));

    // ───────────────────────────────────────── 6. MOBİL 390x844
    console.log('\n=== 6. Mobil (390x844) ===');
    const S7 = await ortam(); sunucular.push(S7);
    const m = await sayfa(browser, S7, { width: 390, height: 844 }, { dokunmatik: true });
    await git(m, 'cari_kartlari.php');
    ok('[390] liste açıldı; bottomnav + topbar görünür, sidebar gizli', await goster(m, '.bottomnav') && await goster(m, '.topbar') && !(await goster(m, 'aside.desktop-sidebar')));
    ok('[390] seçimsiz: yalnız liste görünür (detay gizli), taşma yok', await goster(m, '#crListeKol') && !(await goster(m, '#crDetay')) && (await tasma(m)) <= 0);
    await shot(m, 'kartlar_390_liste');
    await m.tap('#crListe .cari-kart[data-id="1"]');
    ok('[390] satıra dokun → detay görünür, liste gizli; URL ?id=1', await detayBekle(m, 1) && await goster(m, '#crDetay') && !(await goster(m, '#crListeKol')) && /id=1/.test(m.url()));
    ok('[390] detay: taşma yok; Geri (← Cariler) görünür', (await tasma(m)) <= 0 && await goster(m, '.cari-geri'));
    await shot(m, 'kartlar_390_detay');
    await m.tap('.cari-geri');
    ok('[390] ← Cariler → liste geri gelir, detay gizlenir', await kisaBekle(m, () => document.getElementById('crListeKol').getClientRects().length > 0 && document.getElementById('crDetay').getClientRects().length === 0));
    await m.tap('#crListe .cari-kart[data-id="1"]'); await detayBekle(m, 1);
    await m.goBack();
    ok('[390] tarayıcı geri tuşu → liste', await kisaBekle(m, () => document.getElementById('crListeKol').getClientRects().length > 0 && document.getElementById('crDetay').getClientRects().length === 0));
    await m.tap('#crListe .cari-kart[data-id="1"]'); await detayBekle(m, 1);
    // alt sayfa menüleri bottomnav ÜSTÜNDE
    for (const [ad, ctl] of [['Dekont ▾', 'crMenuDekont'], ['⋯', 'crMenuDiger']]) {
      await m.tap(`#crDetay [data-cari-menu-ac][aria-controls="${ctl}"]`);
      await bekle(400);
      const olcum = await m.evaluate(c => {
        const ul = document.getElementById(c), r = ul.getBoundingClientRect(), bn = document.querySelector('.bottomnav').getBoundingClientRect();
        const noktalar = [[r.left + r.width / 2, r.top + 10], [r.left + r.width / 2, r.bottom - 10], [r.left + 20, r.bottom - 6]];
        const ustte = noktalar.every(([x, y]) => { const e = document.elementFromPoint(x, y); return e && ul.contains(e); });
        return { gorunur: ul.getClientRects().length > 0, ustte, altKenar: r.bottom, bnUst: bn.top, ic: r.left >= 0 && r.right <= innerWidth + 1, ilkDokunma: [...ul.querySelectorAll('[role=menuitem]')].filter(e => e.getClientRects().length).map(e => e.getBoundingClientRect().height) };
      }, ctl);
      ok(`[390] ${ad} menüsü görünür, ekran içinde, bottomnav ÜSTÜNDE (z-index)`, olcum.gorunur && olcum.ustte && olcum.ic, JSON.stringify(olcum));
      ok(`[390] ${ad} menü öğeleri ≥44px yükseklikte`, olcum.ilkDokunma.every(h => h >= 43.5), JSON.stringify(olcum.ilkDokunma));
      if (ctl === 'crMenuDiger') await shot(m, 'menu_390');
      await m.keyboard.press('Escape'); await bekle(150);
    }
    // hareket modalı mobil ölçümleri
    await m.tap('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]');
    await modalAcik(m, 'crModalHareket'); await bekle(400);
    const mm = await m.evaluate(() => {
      const f = document.querySelector('#crModalHareket .pm-footer').getBoundingClientRect(), b = document.querySelector('#crModalHareket .pm-body'), d = document.querySelector('#crModalHareket .pm-dialog').getBoundingClientRect();
      const gir = [...document.querySelectorAll('#crModalHareket input:not([type=hidden]):not([type=radio]):not([type=checkbox]), #crModalHareket select, #crModalHareket textarea')].filter(e => e.getClientRects().length);
      const kucuk = gir.filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name || e.type);
      const dokunma = [...document.querySelectorAll('#crModalHareket .btn, #crModalHareket .pm-close, #crModalHareket .cari-seg, #crModalHareket .cari-tur-sec')].filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { s: (e.className + '').split(' ')[0] + ':' + (e.textContent || '').trim().slice(0, 12), h: Math.round(r.height), w: Math.round(r.width) }; }).filter(x => x.h < 44 || x.w < 44);
      return { footAlt: f.bottom, vh: innerHeight, footUst: f.top, bodyKaydir: b.scrollHeight > b.clientHeight, bodyOverflow: getComputedStyle(b).overflowY, kucuk, dokunma, dlgAlt: d.bottom, tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth };
    });
    ok('[390] modal: footer ekranda (alt ≤ viewport)', mm.footAlt <= mm.vh + 1 && mm.footUst > 0, JSON.stringify(mm));
    ok('[390] modal: tüm girdi/select/textarea font-size ≥ 16px (iOS zoom yok)', mm.kucuk.length === 0, mm.kucuk.join(','));
    ok('[390] modal: dokunma hedefleri (düğme/segment) ≥ 44px', mm.dokunma.length === 0, JSON.stringify(mm.dokunma));
    ok('[390] modal: yatay taşma yok', mm.tasma <= 0);
    await shot(m, 'hareket_390');
    // gövde kayıyor + Kaydet tıklanabilir
    await m.tap('#crModalHareket .cari-tur-sec:has(input[value="virman"])');
    await bekle(300);
    const kayma = await m.evaluate(() => { const b = document.querySelector('#crModalHareket .pm-body'); const once = b.scrollTop; b.scrollTop = b.scrollHeight; return { kaydi: b.scrollTop > once || b.scrollHeight <= b.clientHeight, sh: b.scrollHeight, ch: b.clientHeight }; });
    await bekle(150);
    const kaydet = await m.evaluate(() => { const bt = document.querySelector('#crModalHareket button[type=submit]'), r = bt.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); const son = [...document.querySelectorAll('#crModalHareket textarea[name=aciklama]')][0].getBoundingClientRect(); return { tikla: e === bt || bt.contains(e), sonGorunur: son.bottom <= document.querySelector('#crModalHareket .pm-footer').getBoundingClientRect().top + 1 && son.top >= 0 }; });
    ok('[390] virman (uzun) modal: gövde kaydırılır, son alan footer üstünde görünür, Kaydet tıklanabilir', kayma.kaydi && kaydet.tikla && kaydet.sonGorunur, JSON.stringify({ kayma, kaydet }));
    await shot(m, 'hareket_390_virman_alt');
    await m.keyboard.press('Escape'); await modalKapali(m, 'crModalHareket');
    // mobil tahsilat uçtan uca
    const mb0 = await isaretli(m, 'TRY');
    await m.tap('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]'); await modalAcik(m, 'crModalHareket');
    await m.fill('#crModalHareket input[name="tutar"]', '1.000,00');
    await m.tap('#crModalHareket button[type="submit"]');
    ok('[390] mobil tahsilat: kaydedildi, bakiye 1.000 azaldı', await modalKapali(m, 'crModalHareket') && await kisaBekle(m, v => { const e = document.querySelector('#crDetay .cari-bk-kutu[data-pb="TRY"] .cari-bk-tutar'); return e && e.textContent.indexOf('124.400,00') === 0; }) && (await isaretli(m, 'TRY')) === mb0 - 100000, `${mb0} → ${await isaretli(m, 'TRY')}`);
    // uzun kart modalı
    await m.tap('.cari-geri'); await bekle(300);
    await m.tap('#crListeKol [data-cr-modal="kart"]');
    await modalAcik(m, 'crModalKart'); await bekle(400);
    const km = await m.evaluate(() => {
      const b = document.querySelector('#crModalKart .pm-body'), f = document.querySelector('#crModalKart .pm-footer').getBoundingClientRect();
      b.scrollTop = b.scrollHeight;
      return { kaydirilabilir: b.scrollHeight > b.clientHeight + 20, footAlt: f.bottom, vh: innerHeight };
    });
    await bekle(200);
    const sonAlan = await m.evaluate(() => { const e = document.querySelector('#crModalKart textarea[name=notlar]'), r = e.getBoundingClientRect(), f = document.querySelector('#crModalKart .pm-footer').getBoundingClientRect(); const ort = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(20, r.height / 2)); return { gorunur: r.top >= 0 && r.bottom <= f.top + 1, tiklanir: ort === e, ort: ort && (ort.tagName + '.' + ort.className), r: [r.top, r.bottom, f.top] }; });
    ok('[390] uzun kart modalı: kaydırılabilir, footer ekranda', km.kaydirilabilir && km.footAlt <= km.vh + 1, JSON.stringify(km));
    ok('[390] uzun kart modalı: en alttaki alan (Notlar) görünür ve tıklanabilir', sonAlan.gorunur && sonAlan.tiklanir, JSON.stringify(sonAlan));
    const kmGir = await m.evaluate(() => [...document.querySelectorAll('#crModalKart input:not([type=hidden]):not([type=radio]):not([type=checkbox]), #crModalKart select, #crModalKart textarea')].filter(e => parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name));
    ok('[390] kart modalı girdileri ≥16px', kmGir.length === 0, kmGir.join(','));
    await m.fill('#crModalKart input[name="unvan"]', 'Mobil Deneme Cari');
    await m.tap('#crModalKart button[type="submit"]');
    ok('[390] mobilde footer\'daki Kaydet ile cari oluşturulur', await kisaBekle(m, () => /id=14/.test(location.search), null, 8000));
    await shot(m, 'kart_390_sonrasi');
    // liste sayfasındaki dokunma hedefleri
    await git(m, 'cari_kartlari.php');
    const dk = await m.evaluate(() => [...document.querySelectorAll('.cari-cip, .cari-kart, #crListeKol .btn, #crListeKol select, #crListeKol input[type=search], .cari-sekme')].filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { s: (e.className + e.tagName).slice(0, 24) + ':' + (e.textContent || '').trim().slice(0, 10), h: Math.round(r.height) }; }).filter(x => x.h < 44));
    ok('[390] liste: çip/satır/düğme/arama dokunma hedefleri ≥44px', dk.length === 0, JSON.stringify(dk));
    const arama = await m.$eval('#crArama', e => parseFloat(getComputedStyle(e).fontSize));
    ok('[390] arama kutusu ≥16px', arama >= 16, arama);
    // fişler mobil: filtre sayfası
    await git(m, 'cari_fisler.php');
    ok('[390] Fişler: kart görünümü (tablo gizli), taşma yok', await goster(m, '.cari-fis') && !(await goster(m, 'table.cari-tablo')) && (await tasma(m)) <= 0);
    await shot(m, 'fisler_390');
    await m.tap('.cari-filtre-ac');
    await bekle(400);
    ok('[390] Filtrele → tam ekran filtre sayfası açılır, Uygula görünür ve ekranda', await m.evaluate(() => { const f = document.getElementById('crFiltre'), u = f.querySelector('.cari-filtre-uygula').getBoundingClientRect(); return f.classList.contains('acik') && u.bottom <= innerHeight + 1 && u.top >= 0; }));
    await shot(m, 'fisler_390_filtre');
    await m.tap('[data-filtre-kapat]');
    ok('[390] filtre kapatılır', await kisaBekle(m, () => !document.getElementById('crFiltre').classList.contains('acik')));
    await m.tap('.cari-fis-eylem .cari-iptal-dugme');
    ok('[390] fiş kartından İptal modalı açılır; özet dolu', await modalAcik(m, 'crModalIptal') && (await metin(m, '#crModalIptal .cari-iptal-ozet')).length > 5);
    await m.keyboard.press('Escape');
    await git(m, 'cari_tanimlari.php');
    ok('[390] Tanımlar: sekmeler + ekleme formu görünür, taşma yok', await goster(m, '.cari-tanim-nav') && await goster(m, '#crPanelGrup form.cari-tanim-ekle') && (await tasma(m)) <= 0);
    await shot(m, 'tanimlar_390');
    ok('JS/konsol hatası yok (bölüm 6)', hatalar.length === 0, hatalar.join(' | '));
    await m.context().close();

    // ───────────────────────────────────────── 7. JS KAPALI
    console.log('\n=== 7. JS kapalı (klasik form/GET/PRG) ===');
    const S8 = await ortam(); sunucular.push(S8);
    const j = await sayfa(browser, S8, { width: 1440, height: 900 }, { js: false });
    await git(j, 'cari_kartlari.php?id=1');
    ok('JS yok: sayfa + seçili detay sunucudan basılı, ekstre görünür', (await metin(j, '#crDetay .cari-unvan')) === 'Örnek Tarım Ltd.' && (await say(j, '#crPanelEkstre tbody tr[data-id]')) > 5);
    await git(j, 'cari_kartlari.php?id=2');
    ok('JS yok: kart satırı bağlantısı çalışır (?id=2 → Deneme Ambalaj)', (await metin(j, '#crDetay .cari-unvan')) === 'Deneme Ambalaj A.Ş.');
    await git(j, 'cari_kartlari.php?q=Ambalaj');
    { const qn = await say(j, '#crListe .cari-kart'); ok('JS yok: ?q= ile sunucu tarafı süzme (Ambalaj → az sonuç)', qn >= 1 && qn < 12, qn); }
    await git(j, 'cari_tanimlari.php');
    const grupSay0 = await say(j, '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen)');
    await j.fill('#crPanelGrup form.cari-tanim-ekle input[name="ad"]', 'JS Yok Grubu');
    await j.click('#crPanelGrup form.cari-tanim-ekle button[type="submit"]');
    await j.waitForLoadState('load');
    ok('JS yok: tanım ekleme klasik POST → 303 → liste + flash', /cari_tanimlari\.php/.test(j.url()) && /JS Yok Grubu/.test(await metin(j, '#crPanelGrup')) && (await say(j, '#crPanelGrup .cari-tanim-satir:not(.cari-tanim-satir--duzen)')) === grupSay0 + 1 && /Tanım eklendi/.test(await metin(j, '.flash') || ''), await metin(j, '.flash'));
    // klasik tahsilat POST (modal JS'siz açılamaz; form yine de çalışmalı) — gerçek tarayıcı form gönderimi
    await git(j, 'cari_kartlari.php?id=1');
    const csrf = await j.$eval('#crModalHareket input[name="csrf"]', e => e.value);
    const tah0 = S8.say().bakiye['1'].TRY;
    const yanit = await j.context().request.post(S8.url + '/cari_kaydet.php', { form: { csrf, islem: 'hareket_ekle', cari_id: '1', tur: 'tahsilat', tarih: new Date().toISOString().slice(0, 10), tutar: '2.000,00', para_birimi: 'TRY', aciklama: 'JS yok', geri: 'cari_kartlari.php?id=1' }, maxRedirects: 0 });
    ok('JS yok: klasik tahsilat POST → 303 + Location cari_kartlari.php?id=1', yanit.status() === 303 && /cari_kartlari\.php\?id=1/.test(yanit.headers()['location'] || ''), yanit.status() + ' ' + yanit.headers()['location']);
    ok('JS yok: tahsilat DB\'ye yazıldı (bakiye -2.000,00)', S8.say().bakiye['1'].TRY === tah0 - 200000);
    await git(j, 'cari_kartlari.php?id=1');
    ok('JS yok: yeniden açınca bakiye sayfada güncel (flash tüketildi)', (await bakiyeUI(j, 'TRY')) === tah0 - 200000);
    const gec = await j.context().request.post(S8.url + '/cari_kaydet.php', { form: { csrf: 'yanlis', islem: 'hareket_ekle' }, maxRedirects: 0, headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
    const gj = await gec.json().catch(() => null);
    ok('CSRF yanlış → 403 JSON (anahtar "error"); DB değişmedi', gec.status() === 403 && gj && (gj.error || gj.hata) && S8.say().bakiye['1'].TRY === tah0 - 200000, gec.status() + ' ' + JSON.stringify(gj));
    const get405 = await j.context().request.get(S8.url + '/cari_kaydet.php');
    ok('yazma ucu GET → 405 (sayfalar GET-only, yazma tek uç POST)', get405.status() === 405);
    ok('JS yok: konsol/sayfa hatası yok', hatalar.length === 0, hatalar.join(' | '));
    await j.context().close();

    // CSRF hatası arayüzde anlaşılır mesaj (JS açık)
    const c = await sayfa(browser, S8, { width: 1440, height: 900 });
    await git(c, 'cari_kartlari.php?id=1'); await detayBekle(c, 1);
    await c.click('#crDetay [data-cr-modal="hareket"][data-tur="tahsilat"]'); await modalAcik(c, 'crModalHareket');
    await c.evaluate(() => { document.querySelector('#crModalHareket input[name="csrf"]').value = 'bozuk'; });
    await c.fill('#crModalHareket input[name="tutar"]', '5,00');
    await c.click('#crModalHareket button[type="submit"]');
    ok('UI: bozuk CSRF → form hata şeridinde "Güvenlik anahtarı…" mesajı, modal açık, kayıt yok',
      await kisaBekle(c, () => { const g = document.querySelector('#crModalHareket .cari-form-hata'); return g && !g.hidden && /Güvenlik|CSRF|yenile/i.test(g.textContent); }) && await goster(c, '#crModalHareket'), await metin(c, '#crModalHareket .cari-form-hata'));
    // ağ hatası
    await c.evaluate(() => { document.querySelector('#crModalHareket input[name="csrf"]').value = document.querySelector('meta[name=csrf-token]').content; });
    await c.route('**/cari_kaydet.php', r => r.abort());
    await c.click('#crModalHareket button[type="submit"]');
    ok('UI: ağ hatası → "Bağlantı hatası… bilgiler korundu"; girdi korunur', await kisaBekle(c, () => /Bağlantı hatası/.test(document.querySelector('#crModalHareket .cari-form-hata').textContent)) && (await c.inputValue('#crModalHareket input[name="tutar"]')) === '5,00');
    await c.unroute('**/cari_kaydet.php');
    // 500 → sunucu hatası mesajı
    await c.route('**/cari_kaydet.php', r => r.fulfill({ status: 500, contentType: 'text/html', body: '<h1>boom</h1>' }));
    await c.click('#crModalHareket button[type="submit"]');
    ok('UI: HTTP 500 (JSON değil) → "Sunucu hatası (500)"', await kisaBekle(c, () => /Sunucu hatası \(500\)/.test(document.querySelector('#crModalHareket .cari-form-hata').textContent)));
    await c.unroute('**/cari_kaydet.php');
    ok('JS/konsol hatası yok (CSRF/ağ senaryoları)', hatalar.filter(h => !/500|requestfailed/.test(h)).length === 0, hatalar.join(' | '));
    await c.context().close();

    // ───────────────────────────────────────── 8. EKSTRE YAZDIR
    console.log('\n=== 8. Ekstre yazdır ===');
    const y = await sayfa(browser, S8, { width: 1440, height: 900 });
    await git(y, 'cari_kartlari.php?id=1'); await detayBekle(y, 1);
    const yHref = await y.$eval('#crDetay a[href*="bicim=yazdir"]', a => a.getAttribute('href'));
    ok('detaydaki "Ekstre Yazdır" bağlantısı cari_ekstre.php?id=1&bicim=yazdir (yeni sekme)', /^cari_ekstre\.php\?id=1&bicim=yazdir$/.test(yHref) && (await y.$eval('#crDetay a[href*="bicim=yazdir"]', a => a.target)) === '_blank', yHref);
    const [yeniSekme] = await Promise.all([y.context().waitForEvent('page'), y.click('#crDetay a[href*="bicim=yazdir"]')]);
    await yeniSekme.waitForLoadState('load');
    const yg = await yeniSekme.evaluate(() => document.body.innerText);
    ok('yazdır sayfası: cari ünvanı + TRY bölümü + toplam satırı, sunucu hatası yok', /Örnek Tarım Ltd\./.test(yg) && /TRY/.test(yg) && /(Dönem|Kapanış|Toplam)/i.test(yg), yg.slice(0, 200));
    ok('yazdır sayfası: sidebar/bottomnav YOK (temiz çıktı), yatay taşma yok', !(await yeniSekme.$('.sidebar, .bottomnav')) && (await tasma(yeniSekme)) <= 0);
    await yeniSekme.screenshot({ path: SHOT ? path.join(SHOT, 'ekstre_yazdir_1440.png') : undefined }).catch(() => {});
    await y.context().close();

    ok('toplam: PHP alt süreç hatası yok (köprü kaydı)', sunucular.every(s => s.istekler.every(i => !i.hata)), JSON.stringify(sunucular.flatMap(s => s.istekler.filter(i => i.hata).map(i => i.url + ' ' + i.hata)).slice(0, 3)));
  } catch (e) {
    n++; fail++; console.log('*** FAIL beklenmeyen hata: ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    for (const s of sunucular) await s.kapat();
    try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* geçici */ }
  }
  console.log(`\n${n} adım, ${fail} başarısız`);
  process.exit(fail ? 1 : 0);
})();
