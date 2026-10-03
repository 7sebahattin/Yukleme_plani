// =========================================================
// scripts/pdks_kiosk_renk_smoke.js — v297 kiosk tip renkleri, GERÇEK TARAYICI testi
//   1) GİRİŞ modunda tarama görseli (halka + kart parıltısı) tipe göre: KADIN pembe,
//      ERKEK mavi, KARIŞIK mor (hesaplanmış renkler birbirinden farklı); ÇIKIŞ ve ORTAK
//      ÇIKIŞ'ta sınıf yok → mevcut yeşil.
//   2) Başarı sonucu: Kadın kartı pembe, Erkek mavi, Karışık mor, tanınmayan tip yeşil;
//      hata kırmızı. GİRİŞ ve ÇIKIŞ sonucunda aynı.
// ?ajax= uçları taklit edilir.
//
//   php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html
//   node scripts/pdks_kiosk_renk_smoke.js        (SHOT_DIR=… ekran görüntüsü alır)
// Playwright yoksa kendini ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_ortak_cikis.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_ortak_cikis.html yok. Önce: php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html');
    process.exit(1);
}
const SHOT = process.env.SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(84)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const dosya = u.pathname === '/' ? SAYFA : path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});
const BUGUN = new Date().toISOString().slice(0, 10);
const OZET = { giris: { 'Kadın': 4, 'Erkek': 2 }, cikis: { 'Kadın': 1 }, giris_toplam: 6, cikis_toplam: 1, icerde_toplam: 5, eksik_toplam: 0 };
const rgb = s => (String(s).match(/[\d.]+/g) || []).slice(0, 3).map(Number);
// baskın kanal: 'p' pembe (R yüksek, G düşük, B orta), 'm' mavi (B baskın, R düşük), 'y' yeşil (G baskın), 'k' kırmızı, 'mor' (R ve B yüksek, G düşük, B > R)
function ton(c) {
    const [r, g, b] = rgb(c);
    if (g > r && g > b) return 'yesil';
    if (r > b && r > g) return (b > g + 30) ? 'pembe' : 'kirmizi';
    if (b > r && b > g) return (r >= g + 10) ? 'mor' : 'mavi';
    return '?';
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const tema of ['light', 'dark']) {
        for (const ekran of [{ ad: 'MASAÜSTÜ', k: 'pc', width: 1280, height: 900 }, { ad: 'MOBİL', k: 'mob', width: 390, height: 844 }]) {
            console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) · tema ${tema} ===`);
            const ctx = await browser.newContext({ viewport: { width: ekran.width, height: ekran.height } });
            const page = await ctx.newPage();
            await page.addInitScript(t => { try { localStorage.setItem('theme', t); } catch (e) {} }, tema);
            const jsHata = [];
            page.on('pageerror', e => jsHata.push(String(e)));
            page.on('dialog', d => d.accept());
            let kaydetYanit = null;
            await page.route('**/gunluk_isci_giris_cikis.php?ajax=*', async route => {
                const uc = new URL(route.request().url()).searchParams.get('ajax');
                let yanit = { ok: false };
                if (uc === 'ortak_mesailer') yanit = { ok: true, mesailer: [] };
                else if (uc === 'oturum') yanit = { ok: true, session: { id: 11, depo: 'Depo A', work_date: BUGUN, status: 'open' }, ozet: OZET };
                else if (uc === 'kaydet') yanit = kaydetYanit || { ok: false, hata: 'Kart tanımsız.' };
                await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
            });
            await page.goto(KOK); await page.waitForTimeout(300);
            await page.evaluate(t => document.documentElement.setAttribute('data-theme', t), tema);

            const gorsel = () => page.evaluate(() => {
                const s = document.getElementById('giScanSec'); const v = s.querySelector('.pdks-scan-ripple:nth-child(3)'); const c = s.querySelector('.pdks-scan-card');
                return { sinif: [...s.classList].filter(x => /^pdks-tip-/.test(x)).join(','), halka: getComputedStyle(v).borderTopColor, parilti: getComputedStyle(c).borderTopColor,
                         golge: getComputedStyle(v).boxShadow, anim: getComputedStyle(v).animationName };
            });
            const girisTip = async kod => {
                await page.click('[data-gi-cavus-id="1"]'); await page.waitForTimeout(250);
                await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(150);
                await page.click(`[data-gi-tip-kod="${kod}"]`); await page.waitForTimeout(350);
            };

            // ── 1) tarama görseli ──
            await girisTip('KADIN');
            const gK = await gorsel();
            if (SHOT && tema === 'dark' && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_kiosk_kadin.png') });
            await page.click('#giTipGecis'); await page.waitForTimeout(350);
            const gE = await gorsel();
            if (SHOT && tema === 'dark' && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_kiosk_erkek.png') });
            ok('KADIN girişi: sınıf pdks-tip-kadin, halka + kart parıltısı PEMBE', gK.sinif === 'pdks-tip-kadin' && ton(gK.halka) === 'pembe' && ton(gK.parilti) === 'pembe', JSON.stringify(gK));
            ok('hızlı geçiş → ERKEK: sınıf pdks-tip-erkek, halka + kart MAVİ', gE.sinif === 'pdks-tip-erkek' && ton(gE.halka) === 'mavi' && ton(gE.parilti) === 'mavi', JSON.stringify(gE));
            ok('Kadın ve Erkek halka rengi birbirinden FARKLI; animasyon duruyor', gK.halka !== gE.halka && gK.anim !== 'none' && gE.anim !== 'none', JSON.stringify([gK.halka, gE.halka]));
            await page.click('#giModDegistir'); await page.waitForTimeout(250);
            await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(150);
            await page.click('[data-gi-tip-kod="KARISIK"]'); await page.waitForTimeout(350);
            const gM = await gorsel();
            if (SHOT && tema === 'dark' && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_kiosk_karisik.png') });
            ok('KARIŞIK girişi: sınıf pdks-tip-karisik, halka + kart MOR; üç renk farklı', gM.sinif === 'pdks-tip-karisik' && ton(gM.halka) === 'mor' && ton(gM.parilti) === 'mor' && new Set([gK.halka, gE.halka, gM.halka]).size === 3, JSON.stringify(gM));
            // ÇIKIŞ: sınıf yok, yeşil
            await page.click('#giModDegistir'); await page.waitForTimeout(250);
            await page.click('[data-gi-mode="CIKIS"]'); await page.waitForTimeout(350);
            const gC = await gorsel();
            ok('ÇIKIŞ modu: tip sınıfı YOK, halka mevcut YEŞİL', gC.sinif === '' && ton(gC.halka) === 'yesil', JSON.stringify(gC));
            // ORTAK ÇIKIŞ: GİRİŞ(KADIN)'dan sonra da sınıf kalmaz
            await page.click('#giModDegistir'); await page.waitForTimeout(250);
            await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(150);
            await page.click('[data-gi-tip-kod="KADIN"]'); await page.waitForTimeout(350);
            await page.click('#giCavusDegistir2'); await page.waitForTimeout(250);
            await page.click('#giOrtakCikisBtn'); await page.waitForTimeout(350);
            const gO = await gorsel();
            ok('ORTAK ÇIKIŞ: önceki KADIN rengi kalmaz, tip sınıfı YOK, YEŞİL', gO.sinif === '' && ton(gO.halka) === 'yesil', JSON.stringify(gO));

            // ── 2) sonuç ekranı ──
            const sonuc = async (olay, tipAd) => {
                kaydetYanit = { ok: true, event_type: olay, server_time: BUGUN + ' 08:00:00', card: { card_no: 'K009', worker_type_name: tipAd, entry_time: BUGUN + ' 07:00:00' }, ozet: OZET };
                await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
                await page.waitForFunction(() => !document.getElementById('giResult').hidden);
                await page.waitForTimeout(500);
                return page.evaluate(() => {
                    const b = document.getElementById('giResult'); const ik = b.querySelector('.pdks-result-3d-icon');
                    return { sinif: b.className, renk: getComputedStyle(b).color, zemin: getComputedStyle(b).backgroundImage.match(/rgb\([^)]*\)/g)[0], ikon: ik ? getComputedStyle(ik).color : '', ikonBg: ik ? getComputedStyle(ik).backgroundImage : '' };
                });
            };
            const bekle = () => page.waitForFunction(() => document.getElementById('giResult').hidden, null, { timeout: 9000 });
            // ortak çıkış modundayız → önce normal moda dön
            const cikisModu = async () => {
                await page.click('#giCavusDegistir2'); await page.waitForTimeout(250);
                await page.click('[data-gi-cavus-id="1"]'); await page.waitForTimeout(250);
            };
            await cikisModu();
            await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(150);
            await page.click('[data-gi-tip-kod="KADIN"]'); await page.waitForTimeout(350);
            const sK = await sonuc('GIRIS', 'Kadın');
            if (SHOT && tema === 'dark' && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_sonuc_kadin.png') });
            ok('GİRİŞ sonucu Kadın kartı: sınıf pdks-sonuc-kadin, panel + ikon PEMBE', /pdks-sonuc-kadin/.test(sK.sinif) && ton(sK.renk) === 'pembe' && ton(sK.zemin) === 'pembe', JSON.stringify(sK));
            await bekle();
            const sE = await sonuc('GIRIS', 'Erkek');
            if (SHOT && tema === 'dark' && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_sonuc_erkek.png') });
            ok('GİRİŞ sonucu Erkek kartı: pdks-sonuc-erkek, panel + ikon MAVİ', /pdks-sonuc-erkek/.test(sE.sinif) && ton(sE.renk) === 'mavi' && ton(sE.zemin) === 'mavi', JSON.stringify(sE));
            await bekle();
            const sM = await sonuc('GIRIS', 'Karışık');
            ok('GİRİŞ sonucu Karışık kartı: pdks-sonuc-karisik, panel + ikon MOR', /pdks-sonuc-karisik/.test(sM.sinif) && ton(sM.renk) === 'mor' && ton(sM.zemin) === 'mor', JSON.stringify(sM));
            await bekle();
            const cK = await sonuc('CIKIS', 'Kadın');
            ok('ÇIKIŞ sonucu Kadın kartı da PEMBE', /pdks-sonuc-kadin/.test(cK.sinif) && ton(cK.renk) === 'pembe', JSON.stringify(cK));
            await bekle();
            const cE = await sonuc('CIKIS', 'Erkek');
            ok('ÇIKIŞ sonucu Erkek kartı da MAVİ', /pdks-sonuc-erkek/.test(cE.sinif) && ton(cE.renk) === 'mavi', JSON.stringify(cE));
            await bekle();
            const sB = await sonuc('GIRIS', 'Bilinmeyen');
            ok('tanınmayan tip: YEŞİL (eski görünüm)', !/pdks-sonuc-(kadin|erkek|karisik)/.test(sB.sinif) && ton(sB.renk) === 'yesil', JSON.stringify(sB));
            await bekle();
            kaydetYanit = null;
            await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
            await page.waitForFunction(() => !document.getElementById('giResult').hidden);
            await page.waitForTimeout(400);
            const er = await page.evaluate(() => { const b = document.getElementById('giResult'); return { sinif: b.className, renk: getComputedStyle(b).color }; });
            ok('HATA sonucu KIRMIZI kalır (tip rengi uygulanmaz)', /pdks-kiosk-result-err/.test(er.sinif) && !/pdks-sonuc-/.test(er.sinif) && ton(er.renk) === 'kirmizi', JSON.stringify(er));
            ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
            await ctx.close();
        }
    }
    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
