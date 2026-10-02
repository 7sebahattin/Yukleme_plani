// =========================================================
// scripts/pdks_karisik_smoke.js — v295 KARIŞIK giriş + "🎲 Otomatik Ata"
// GERÇEK TARAYICI testi.
//   A) Kiosk (gunluk_isci_giris_cikis.php): tip ekranında 3 düğme (Kadın/Erkek/
//      Karışık), KARIŞIK → ?ajax=kaydet KARISIK tip id'siyle gider, rozet
//      "Karışık", Kadın⇄Erkek hızlı geçiş düğmesi GİZLİ, sonuç kapsülü is-karisik,
//      GİRİŞ dairesi Karışık toplamı; ÇIKIŞ (Karışık) eski davranış (toplam giriş).
//   B) Mesai Detayı: uyarı kartı, Otomatik Ata penceresi tek başına, ekran içinde,
//      canlı toplam, sınır aşımı engeli, alt düğme tıklanabilir; Karışık rozeti.
// ?ajax= uçları Playwright ile taklit edilir. 1280×900, 820×1024, 390×844.
//
//   php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html
//   php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
//   node scripts/pdks_karisik_smoke.js
// Playwright yoksa kendini ATLAR. KARISIK_SHOT_DIR verilirse ekran görüntüsü kaydeder.
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
const KIOSK = path.join(ROOT, '_test_ortak_cikis.html');
const DETAY = path.join(ROOT, '_test_puantaj_dialog.html');
for (const [f, k] of [[KIOSK, 'pdks_ortak_cikis_render.php > _test_ortak_cikis.html'], [DETAY, 'pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html']]) {
    if (!fs.existsSync(f)) { console.error(path.basename(f) + ' yok. Önce: php scripts/' + k); process.exit(1); }
}
const SHOT = process.env.KARISIK_SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(80)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const dosya = u.pathname === '/' ? KIOSK : path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});
const BUGUN = new Date().toISOString().slice(0, 10);
const OZET = { giris: { 'Kadın': 4, 'Erkek': 2, 'Karışık': 7 }, cikis: { 'Kadın': 1 }, giris_toplam: 13, cikis_toplam: 1, icerde_toplam: 12,
               eksik_tip: { 'Kadın': 3, 'Erkek': 2, 'Karışık': 7 }, eksik_toplam: 0 };
const EKRANLAR = [{ ad: 'MASAÜSTÜ', kod: 'pc', width: 1280, height: 900 }, { ad: 'TABLET', kod: 'tab', width: 820, height: 1024 }, { ad: 'MOBİL', kod: 'mob', width: 390, height: 844 }];

async function sonucBekle(page) {
    await page.waitForFunction(() => !document.getElementById('giResult').hidden, null, { timeout: 3000 });
    const html = await page.evaluate(() => document.getElementById('giResultInner').innerHTML);
    await page.waitForFunction(() => document.getElementById('giResult').hidden, null, { timeout: 7000 });
    return html;
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of EKRANLAR) {
        // ───────────────────────── A) KIOSK ─────────────────────────
        console.log(`\n=== KIOSK — ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const jsHata = [];
        page.on('pageerror', e => jsHata.push(String(e)));
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
            else if (uc === 'kaydet') yanit = kaydetYanit || { ok: false, hata: 'Kart tanımsız.' };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });
        await page.goto(KOK);
        await page.waitForTimeout(300);
        await page.click('[data-gi-cavus-id="1"]'); await page.waitForTimeout(300);
        const sayac = await page.evaluate(() => document.getElementById('giSayacSatirlar').textContent.replace(/\s+/g, ' '));
        ok('mod ekranı sayaçlarında "Karışık" satırı', /Karışık/.test(sayac), sayac);
        await page.click('[data-gi-mode="GIRIS"]'); await page.waitForTimeout(400);
        const tip = await page.evaluate(() => {
            const bs = [...document.querySelectorAll('#giTipSec [data-gi-tip-id]')];
            const k = document.querySelector('[data-gi-tip-kod="KARISIK"]');
            const r = k ? k.getBoundingClientRect() : null;
            return { kodlar: bs.map(b => b.getAttribute('data-gi-tip-kod')), metin: k ? k.textContent.trim() : '',
                     sinif: !!k && k.classList.contains('pdks-kiosk-typebtn-karisik'), id: k ? parseInt(k.getAttribute('data-gi-tip-id'), 10) : 0,
                     ekranda: !!r && r.width > 0 && r.left >= 0 && r.right <= innerWidth + 0.5,
                     ust: !!r && document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === k,
                     renkFarkli: !!k && getComputedStyle(k).backgroundImage !== getComputedStyle(bs[1]).backgroundImage,
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('tip ekranında 3 düğme: KADIN, ERKEK, KARISIK', JSON.stringify(tip.kodlar) === '["KADIN","ERKEK","KARISIK"]', JSON.stringify(tip));
        ok('KARIŞIK düğmesi: metin, kendi sınıfı/rengi, ekranda, tıklanabilir, taşma yok', tip.metin === 'KARIŞIK' && tip.sinif && tip.renkFarkli && tip.ekranda && tip.ust && !tip.tasma, JSON.stringify(tip));
        if (SHOT && ekran.kod === 'pc') await page.screenshot({ path: path.join(SHOT, 'v295_kiosk_tip_pc.png') });
        await page.click('[data-gi-tip-kod="KARISIK"]'); await page.waitForTimeout(400);
        const t2 = await page.evaluate(() => {
            const b = document.getElementById('giTipBadge');
            return { rozet: b.textContent, hidden: b.hidden, sinif: b.classList.contains('pdks-kiosk-type-badge-karisik'),
                     gecisGizli: document.getElementById('giTipGecis').hidden, tarama: !document.getElementById('giScanSec').hidden };
        });
        ok('rozet "Karışık" (kendi rengi), tarama ekranında', t2.rozet === 'Karışık' && !t2.hidden && t2.sinif && t2.tarama, JSON.stringify(t2));
        ok('Kadın⇄Erkek hızlı geçiş düğmesi KARIŞIK\'ta gizli', t2.gecisGizli, JSON.stringify(t2));
        istekler.length = 0;
        kaydetYanit = { ok: true, event_type: 'GIRIS', server_time: BUGUN + ' 08:00:00', card: { card_no: 'K009', worker_type_name: 'Karışık' }, ozet: OZET };
        await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
        const h1 = await sonucBekle(page);
        const k = istekler.find(i => i.uc === 'kaydet');
        ok('okutma KARISIK tip id ile gidiyor (event GIRIS)', !!k && k.govde.worker_type_id === tip.id && tip.id > 0 && k.govde.event_type === 'GIRIS', JSON.stringify(k && k.govde));
        ok('GİRİŞ sonucu: kapsül is-karisik, daire Karışık toplamı (7)', /pdks-result-gender is-karisik/.test(h1) && /pdks-result-3d-icon-count[^>]*><span>7<\/span>/.test(h1) && !/data-sayi="kalan"/.test(h1), h1.slice(0, 300));
        // ÇIKIŞ — Karışık kişi: eski davranış (toplam giriş); atanmış (Kadın) kişi: v290 KALAN.
        await page.click('#giModDegistir'); await page.waitForTimeout(300);
        await page.click('[data-gi-mode="CIKIS"]'); await page.waitForTimeout(400);
        ok('ÇIKIŞ modunda tip geçiş düğmesi gizli', await page.evaluate(() => document.getElementById('giTipGecis').hidden));
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 17:00:00', card: { card_no: 'K009', worker_type_name: 'Karışık', entry_time: BUGUN + ' 08:00:00' }, ozet: OZET };
        await page.focus('#giScanInput'); await page.keyboard.type('100000009'); await page.keyboard.press('Enter');
        const h2 = await sonucBekle(page);
        ok('ÇIKIŞ (Karışık): daire toplam giriş (7), KALAN değil', /<span>7<\/span>/.test(h2) && !/data-sayi="kalan"/.test(h2) && /is-karisik/.test(h2), h2.slice(0, 300));
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 17:00:00', card: { card_no: 'K010', worker_type_name: 'Kadın', entry_time: BUGUN + ' 08:00:00' }, ozet: OZET };
        await page.focus('#giScanInput'); await page.keyboard.type('100000010'); await page.keyboard.press('Enter');
        const h3 = await sonucBekle(page);
        ok('ÇIKIŞ (atanmış → Kadın): v290 KALAN (3)', /data-sayi="kalan"[^>]*><span>3<\/span>/.test(h3), h3.slice(0, 300));
        ok('kiosk: JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();

        // ───────────────────────── B) MESAİ DETAYI ─────────────────────────
        console.log(`\n=== MESAİ DETAYI — ${ekran.ad} ===`);
        const d = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const dHata = [];
        d.on('pageerror', e => dHata.push(String(e)));
        await d.goto('file://' + DETAY);
        await d.waitForTimeout(400);
        const u = await d.evaluate(() => {
            const kart = document.getElementById('karisikUyari'); const r = kart ? kart.getBoundingClientRect() : null;
            const btn = kart ? [...kart.querySelectorAll('button')].find(b => /Otomatik Ata/.test(b.textContent)) : null;
            const rozetler = [...document.querySelectorAll('.pdks-badge-karisik')].filter(x => { const q = x.getBoundingClientRect(); return q.width > 0 && q.height > 0; });
            return { var: !!kart, metin: kart ? kart.textContent.replace(/\s+/g, ' ') : '', icerde: kart ? /4 kişi içeride/.test(kart.textContent) : false,
                     ekranda: !!r && r.left >= 0 && r.right <= innerWidth + 0.5, btn: !!btn, rozet: rozetler.length, rozetMetin: rozetler[0] ? rozetler[0].textContent : '',
                     acikDialog: [...document.querySelectorAll('dialog')].filter(x => getComputedStyle(x).display !== 'none' && x.getBoundingClientRect().height > 0).length,
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('uyarı kartı "🎲 5 Karışık kayıt atanmamış" (+ 4 içeride), ekranda', u.var && /5 Karışık kayıt atanmamış/.test(u.metin) && u.icerde && u.ekranda, JSON.stringify(u));
        ok('uyarı kartında "🎲 Otomatik Ata" düğmesi', u.btn);
        ok('Karışık satır rozetleri görünür (5)', u.rozet === 5 && u.rozetMetin === 'Karışık', JSON.stringify(u));
        ok('açılışta hiçbir dialog görünmüyor, yatay taşma yok', u.acikDialog === 0 && !u.tasma, JSON.stringify(u));
        if (SHOT && ekran.kod === 'pc') {
            const y = await d.evaluate(() => Math.max(0, document.getElementById('karisikUyari').getBoundingClientRect().top + scrollY - 16));
            await d.screenshot({ path: path.join(SHOT, 'v295_detay_pc.png'), fullPage: true, clip: { x: 0, y, width: ekran.width, height: Math.min(1700, (await d.evaluate(() => document.documentElement.scrollHeight)) - y) } });
        }
        await d.evaluate(() => [...document.querySelectorAll('#karisikUyari button')].find(b => /Otomatik Ata/.test(b.textContent)).click());
        await d.waitForTimeout(400);
        const m = await d.evaluate(() => {
            const acik = [...document.querySelectorAll('dialog')].filter(x => getComputedStyle(x).display !== 'none' && x.getBoundingClientRect().height > 0).map(x => x.id);
            const dl = document.getElementById('karisikAta'); const r = dl.getBoundingClientRect();
            const f = dl.querySelector('form');
            return { acik, modal: dl.matches(':modal'), sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight,
                     havuz: document.getElementById('kaHavuz').textContent, toplam: document.getElementById('kaToplam').textContent,
                     kapali: document.getElementById('kaKaydet').disabled, action: f.querySelector('[name="action"]').value,
                     istek: (f.querySelector('[name="istek_id"]').value || '').length, csrf: !!f.querySelector('[name="csrf"]').value,
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('Otomatik Ata: YALNIZ karisikAta dialog\'u açık (modal)', m.acik.length === 1 && m.acik[0] === 'karisikAta' && m.modal, JSON.stringify(m));
        ok('dialog ekran içinde, yatay taşma yok', m.sol >= 0 && m.sag <= m.vw + 0.5 && m.ust >= 0 && m.alt <= m.vh + 0.5 && !m.tasma, JSON.stringify(m));
        ok('havuz "5 Karışık atanmamış", başlangıç "Toplam 0 / 5, kalan 5", Ata kapalı', m.havuz === '5 Karışık atanmamış' && m.toplam === 'Toplam 0 / 5, kalan 5' && m.kapali, JSON.stringify(m));
        ok('form: action=karisik_ata, istek_id 32 hex, csrf', m.action === 'karisik_ata' && m.istek === 32 && m.csrf, JSON.stringify(m));
        await d.fill('#kaKadin', '3'); await d.fill('#kaErkek', '3'); await d.fill('#kaSebep', 'Sayım');
        const asim = await d.evaluate(() => ({ t: document.getElementById('kaToplam').textContent, asim: document.getElementById('kaToplam').classList.contains('ka-asim'), kapali: document.getElementById('kaKaydet').disabled }));
        ok('sınır aşımı (6 > 5): uyarı + Ata kapalı', asim.asim && asim.kapali && /en fazla 5/.test(asim.t), JSON.stringify(asim));
        const engel = await d.evaluate(() => { const f = document.getElementById('karisikAtaForm'); const ev = new Event('submit', { cancelable: true }); f.dispatchEvent(ev); return ev.defaultPrevented; });
        ok('sınır aşımında gönderim istemcide engellenir', engel);
        await d.fill('#kaErkek', '1');
        const gecerli = await d.evaluate(() => {
            const dl = document.getElementById('karisikAta'); const f = dl.querySelector('form');
            f.scrollTop = f.scrollHeight;
            const b = document.getElementById('kaKaydet'); const r = b.getBoundingClientRect();
            const ust = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return { t: document.getElementById('kaToplam').textContent, kapali: b.disabled, ekranda: r.bottom <= innerHeight && r.top >= 0, tiklanir: b === ust || b.contains(ust) };
        });
        ok('canlı toplam "Toplam 4 / 5, kalan 1", Ata açık', gecerli.t === 'Toplam 4 / 5, kalan 1' && !gecerli.kapali, JSON.stringify(gecerli));
        ok('alt düğme (🎲 Ata) ekranda ve tıklanabilir', gecerli.ekranda && gecerli.tiklanir, JSON.stringify(gecerli));
        await d.fill('#kaSebep', '');
        ok('sebep boşken Ata kapalı', await d.evaluate(() => document.getElementById('kaKaydet').disabled));
        await d.fill('#kaSebep', 'Sayım');
        if (SHOT && ekran.kod === 'mob') await d.screenshot({ path: path.join(SHOT, 'v295_ata_mob.png') });
        await d.evaluate(() => document.getElementById('karisikAta').close());
        await d.waitForTimeout(100);
        ok('kapatınca hiçbir dialog görünmüyor', await d.evaluate(() => [...document.querySelectorAll('dialog')].every(x => getComputedStyle(x).display === 'none' || x.getBoundingClientRect().height === 0)));
        ok('detay: JS hatası yok', dHata.length === 0, dHata.join(' | '));
        await d.close();
    }
    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
