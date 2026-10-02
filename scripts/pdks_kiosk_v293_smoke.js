// =========================================================
// scripts/pdks_kiosk_v293_smoke.js — Günlük işçi kart okutma ekranı v293
// GERÇEK TARAYICI testi (gunluk_isci_giris_cikis.php istemci davranışı):
//   1) "Mesaiyi Kapat" PENCERE açar (ekran değişmez), özet altında "Onayla";
//      Onayla'ya basmadan ?ajax=kapat GİTMEZ; Vazgeç/Esc/dış tık kapatmaz.
//   2) GİRİŞ (KADIN) taramasında "ERKEK GİRİŞ" düğmesi → Erkek'e geçer
//      (ve tersi); ÇIKIŞ modunda düğme YOK.
//   3) Sonuç kartı: başarı 3 sn, hata 5 sn ekranda kalır.
// ?ajax= uçları Playwright ile taklit edilir.
//
//   php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html
//   node scripts/pdks_kiosk_v293_smoke.js
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
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(78)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
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

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 }, { ad: 'TABLET', width: 820, height: 1024 }, { ad: 'MOBİL', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const jsHata = [];
        page.on('pageerror', e => jsHata.push(String(e)));
        page.on('dialog', d => d.accept());
        const istekler = [];
        let kaydetYanit = null;
        await page.route('**/gunluk_isci_giris_cikis.php?ajax=*', async route => {
            const req = route.request();
            const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {};
            try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde });
            let yanit = { ok: false };
            if (uc === 'ortak_mesailer') yanit = { ok: true, mesailer: [] };
            else if (uc === 'oturum') yanit = { ok: true, session: { id: 11, depo: 'Depo A', work_date: BUGUN, status: 'open' }, ozet: OZET };
            else if (uc === 'kapanis_kontrol') yanit = { ok: true, acik_donem_sayisi: 0, bekleyen_sinif: 0, bekleyen_fazla_mesai: 0, hakedis_durum: 'yok' };
            else if (uc === 'kapat') yanit = { ok: true };
            else if (uc === 'kaydet') yanit = kaydetYanit || { ok: false, hata: 'Kart tanımsız.' };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });
        await page.goto(KOK);
        await page.waitForTimeout(300);
        await page.click('[data-gi-cavus-id="1"]');
        await page.waitForTimeout(300);

        // ── 1) Mesaiyi Kapat → pencere ──
        const kapatGorunur = await page.evaluate(() => !document.getElementById('giKapatBtn').hidden);
        ok('mod ekranında "MESAİYİ KAPAT" görünür', kapatGorunur);
        istekler.length = 0;
        await page.click('#giKapatBtn');
        await page.waitForTimeout(400);
        const p1 = await page.evaluate(() => {
            const ovl = document.getElementById('giCloseConfirmSec');
            const pen = ovl.querySelector('.pdks-kapat-pencere');
            const r = pen.getBoundingClientRect();
            pen.scrollTop = pen.scrollHeight;
            const btn = document.getElementById('giCloseConfirmBtn'); const b = btn.getBoundingClientRect();
            const ust = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
            const ozetH = pen.querySelector('.pdks-kapat-alt');
            return { acik: !ovl.hidden && getComputedStyle(ovl).display !== 'none', sabit: getComputedStyle(ovl).position === 'fixed',
                     modEkraniHala: !document.getElementById('giModeSec').hidden, odak: document.activeElement === pen,
                     sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight,
                     onayMetin: btn.textContent.trim(), onayTiklanir: btn === ust || btn.contains(ust), onayEkranda: b.bottom <= innerHeight,
                     ozetOnce: !!ozetH && !!(ozetH.compareDocumentPosition(btn) & Node.DOCUMENT_POSITION_FOLLOWING),
                     giris: document.getElementById('giCloseGiris').textContent, icerde: document.getElementById('giCloseIceride').textContent,
                     satir: document.getElementById('giCloseSatirlar').textContent.replace(/\s+/g, ' '),
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('Kapat → PENCERE açıldı (fixed), alttaki mod ekranı yerinde', p1.acik && p1.sabit && p1.modEkraniHala, JSON.stringify(p1));
        ok('pencere ekran içinde, yatay taşma yok', p1.sol >= 0 && p1.sag <= p1.vw + 0.5 && p1.ust >= 0 && p1.alt <= p1.vh + 0.5 && !p1.tasma, JSON.stringify(p1));
        ok('mesai özeti (Giriş 6 · İçeride 5 · tip satırları) dolu', p1.giris === '6' && p1.icerde === '5' && /Kadın/.test(p1.satir) && /Erkek/.test(p1.satir), JSON.stringify(p1));
        ok('özetin ALTINDA "✅ Onayla" görünür ve tıklanabilir', p1.ozetOnce && /Onayla/.test(p1.onayMetin) && p1.onayTiklanir && p1.onayEkranda, JSON.stringify(p1));
        ok('odak düğmede değil pencerede (USB Enter mesaiyi kapatmaz)', p1.odak, JSON.stringify(p1));
        ok('pencere açılınca ?ajax=kapat GİTMEDİ', !istekler.some(i => i.uc === 'kapat'), JSON.stringify(istekler.map(i => i.uc)));
        await page.keyboard.press('Enter');
        await page.waitForTimeout(200);
        ok('Enter (USB okuyucu) mesaiyi KAPATMADI', !istekler.some(i => i.uc === 'kapat'));
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        const p2 = await page.evaluate(() => ({ kapali: document.getElementById('giCloseConfirmSec').hidden, mod: !document.getElementById('giModeSec').hidden }));
        ok('Esc → pencere kapandı, mod ekranı duruyor, kapatma YOK', p2.kapali && p2.mod && !istekler.some(i => i.uc === 'kapat'), JSON.stringify(p2));
        await page.click('#giKapatBtn'); await page.waitForTimeout(300);
        await page.mouse.click(3, 3); await page.waitForTimeout(300);
        ok('dış tık → pencere kapandı, kapatma YOK', await page.evaluate(() => document.getElementById('giCloseConfirmSec').hidden) && !istekler.some(i => i.uc === 'kapat'));
        await page.click('#giKapatBtn'); await page.waitForTimeout(300);
        await page.click('#giCloseCancelBtn'); await page.waitForTimeout(300);
        ok('Vazgeç → pencere kapandı, kapatma YOK', await page.evaluate(() => document.getElementById('giCloseConfirmSec').hidden) && !istekler.some(i => i.uc === 'kapat'));
        await page.click('#giKapatBtn'); await page.waitForTimeout(300);
        await page.evaluate(() => { const b = document.getElementById('giCloseConfirmBtn'); b.click(); b.click(); });
        await page.waitForTimeout(400);
        const kapatlar = istekler.filter(i => i.uc === 'kapat');
        const iptalKilit = await page.evaluate(() => /kapatSuruyor\) return;/.test(document.documentElement.innerHTML));
        ok('istek sürerken Vazgeç/Esc kapatmayı "iptal edilmiş" gibi göstermez (kapatSuruyor kilidi)', iptalKilit);
        ok('Onayla → TEK ?ajax=kapat (çift dokunma korumalı), session_id=11', kapatlar.length === 1 && kapatlar[0].govde.session_id === 11, JSON.stringify(kapatlar));

        // ── 2) KADIN girişi → ERKEK GİRİŞ düğmesi ──
        await page.click('[data-gi-cavus-id="1"]'); await page.waitForTimeout(300);
        await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(200);
        await page.click('[data-gi-tip-kod="KADIN"]'); await page.waitForTimeout(400);
        const t1 = await page.evaluate(() => {
            const b = document.getElementById('giTipGecis'); const r = b.getBoundingClientRect();
            const kardes = [...b.parentNode.children].filter(x => !x.hidden).map(x => x.getBoundingClientRect());
            return { gorunur: !b.hidden && r.width > 0 && r.height > 0, metin: b.textContent.trim(), ekranda: r.left >= 0 && r.right <= innerWidth + 0.5 && r.bottom <= innerHeight,
                     rozet: document.getElementById('giTipBadge').textContent, ayniSatir: kardes.length === 3 && Math.abs(kardes[0].top - kardes[2].top) < 2,
                     ust: document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === b, tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('KADIN girişinde "ERKEK GİRİŞ" düğmesi görünür, ekran içinde, tıklanabilir', t1.gorunur && /ERKEK GİRİŞ/.test(t1.metin) && t1.ekranda && t1.ust && !t1.tasma, JSON.stringify(t1));
        ok(ekran.width >= 640 ? 'geniş ekranda üç düğme TEK satırda' : 'dar ekranda düğme alt satırda tam genişlik', ekran.width >= 640 ? t1.ayniSatir : !t1.ayniSatir, JSON.stringify(t1));
        const erkekId = await page.evaluate(() => parseInt(document.querySelector('[data-gi-tip-kod="ERKEK"]').getAttribute('data-gi-tip-id'), 10));
        await page.click('#giTipGecis'); await page.waitForTimeout(400);
        const t2 = await page.evaluate(() => ({ rozet: document.getElementById('giTipBadge').textContent, metin: document.getElementById('giTipGecis').textContent.trim(), tarama: !document.getElementById('giScanSec').hidden }));
        ok('ERKEK GİRİŞ → rozet Erkek, düğme artık "KADIN GİRİŞ", tarama ekranında', /Erkek/i.test(t2.rozet) && /KADIN GİRİŞ/.test(t2.metin) && t2.tarama, JSON.stringify(t2));
        istekler.length = 0;
        await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const k = istekler.find(i => i.uc === 'kaydet');
        ok('okutma Erkek tip id ile gidiyor (event GIRIS)', !!k && k.govde.worker_type_id === erkekId && k.govde.event_type === 'GIRIS', JSON.stringify(k && k.govde));

        // ── 3) Süreler: hata 5 sn ──
        const hataSure = await page.evaluate(async () => {
            const box = document.getElementById('giResult'); const t0 = performance.now();
            while (!box.hidden && performance.now() - t0 < 7000) await new Promise(r => setTimeout(r, 100));
            return performance.now() - t0;
        });
        ok(`hata sonucu ~5 sn ekranda (ölçülen ${Math.round(hataSure + 400)} ms)`, hataSure + 400 >= 4600 && hataSure + 400 <= 5800, String(hataSure));
        kaydetYanit = { ok: true, event_type: 'GIRIS', server_time: BUGUN + ' 08:00:00', card: { card_no: 'K009', worker_type_name: 'Erkek' }, ozet: OZET };
        await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
        const t0 = Date.now();
        await page.waitForFunction(() => !document.getElementById('giResult').hidden);
        await page.waitForFunction(() => document.getElementById('giResult').hidden, null, { timeout: 7000 });
        const okSure = Date.now() - t0;
        ok(`başarı sonucu ~3 sn ekranda (ölçülen ${okSure} ms)`, okSure >= 2700 && okSure <= 3800, String(okSure));

        // ── GİRİŞ(KADIN) → Modu Değiştir → çavuş listesi → ORTAK ÇIKIŞ: eski düğme kalmamalı ──
        await page.click('#giModDegistir'); await page.waitForTimeout(300);
        await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(200);
        await page.click('[data-gi-tip-kod="KADIN"]'); await page.waitForTimeout(400);
        await page.click('#giCavusDegistir2'); await page.waitForTimeout(300);
        await page.click('#giOrtakCikisBtn'); await page.waitForTimeout(400);
        const o = await page.evaluate(() => ({ gizli: document.getElementById('giTipGecis').hidden, uc: document.getElementById('giTipGecis').parentNode.classList.contains('pdks-scan-actions-uc') }));
        ok('ORTAK ÇIKIŞ modunda Kadın/Erkek geçiş düğmesi gizli (önceki GİRİŞ\'ten kalmaz)', o.gizli && !o.uc, JSON.stringify(o));
        await page.click('#giCavusDegistir2'); await page.waitForTimeout(300);
        await page.click('[data-gi-cavus-id="1"]'); await page.waitForTimeout(300);

        // ── ÇIKIŞ modunda geçiş düğmesi YOK (mod ekranındayız) ──
        await page.click('[data-gi-mode="CIKIS"]'); await page.waitForTimeout(400);
        ok('ÇIKIŞ modunda tip geçiş düğmesi gizli', await page.evaluate(() => document.getElementById('giTipGecis').hidden));
        ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
