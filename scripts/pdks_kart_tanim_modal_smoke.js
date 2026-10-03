// =========================================================
// scripts/pdks_kart_tanim_modal_smoke.js — v298 Kart Havuzu "🏷 Tanım"
// modalının GERÇEK TARAYICI testi (masaüstü + tablet + mobil).
//
// Modal açılır, değerler karttan dolar, Kaydet düğmesi ekran içinde ve
// TIKLANABİLİR (elementFromPoint), tanımsız kartta "Tanımı Kaldır" gizli,
// pasif çavuşun tanımı uyarıyla görünür, yatay taşma yok. Ölçümden önce
// 400 ms beklenir (açılış animasyonu — CLAUDE.md "Modal içinde form").
//
//   php scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html
//   node scripts/pdks_kart_tanim_modal_smoke.js
//
// Playwright yoksa kendini ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core',
                   '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) {
    console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).');
    process.exit(0);
}

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_kart_tanim.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_kart_tanim.html yok. Önce: php scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html');
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

async function ac(page, kartNo) {
    await page.evaluate(no => {
        const b = [...document.querySelectorAll('[data-isk-tanim-no="' + no + '"]')].find(x => x.getBoundingClientRect().height > 0);
        b.scrollIntoView({ block: 'center' });
    }, kartNo);
    const sec = `[data-isk-tanim-no="${kartNo}"] >> visible=true`;
    await page.click(sec);
    await page.waitForTimeout(400);
}
async function olc(page) {
    return page.evaluate(() => {
        const ovl = document.getElementById('iskTanimModal');
        const dlg = ovl.querySelector('.pm-dialog').getBoundingClientRect();
        const kaydet = [...ovl.querySelectorAll('button[type="submit"]')].find(b => /Tanımı Kaydet/.test(b.textContent)).getBoundingClientRect();
        const ust = document.elementFromPoint(kaydet.left + kaydet.width / 2, kaydet.top + kaydet.height / 2);
        const bitir = document.getElementById('iskTanimBitirForm');
        return {
            acik: !ovl.hidden, vw: innerWidth, vh: innerHeight,
            dlg: { sol: dlg.left, sag: dlg.right, ust: dlg.top, alt: dlg.bottom },
            kaydetEkranda: kaydet.top >= 0 && kaydet.bottom <= innerHeight && kaydet.left >= 0 && kaydet.right <= innerWidth,
            kaydetTiklanir: !!ust && !!ust.closest('button') && /Tanımı Kaydet/.test(ust.closest('button').textContent),
            cavus: document.getElementById('iskTanimCavus').value,
            cavusMetin: (s => s.selectedIndex >= 0 ? s.options[s.selectedIndex].text : '')(document.getElementById('iskTanimCavus')),
            tip: (s => s.selectedIndex >= 0 ? s.options[s.selectedIndex].text : '')(document.getElementById('iskTanimTip')),
            mevcut: document.getElementById('iskTanimMevcut').hidden ? '' : document.getElementById('iskTanimMevcut').textContent,
            bitirGorunur: !bitir.hidden && bitir.getBoundingClientRect().height > 0,
            baslik: document.getElementById('iskTanimKartNo').textContent,
            tasma: document.documentElement.scrollWidth > innerWidth,
            tasmaDlg: ovl.querySelector('.pm-dialog').scrollWidth > ovl.querySelector('.pm-dialog').clientWidth + 1,
        };
    });
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET',   width: 820,  height: 1024 },
                         { ad: 'MOBİL',    width: 390,  height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        await page.goto(KOK);
        await page.waitForTimeout(300);

        const liste = await page.evaluate(() => ({
            rozet: [...document.querySelectorAll('.pdks-badge-tanimli')].filter(e => e.getBoundingClientRect().height > 0).map(e => e.textContent.trim()),
            pasif: [...document.querySelectorAll('.pdks-badge')].some(e => e.getBoundingClientRect().height > 0 && /çavuş pasif/.test(e.textContent)),
            tasma: document.documentElement.scrollWidth > innerWidth,
        }));
        ok('listede tanım rozeti (Çavuş A · Kadın · Depo A) görünür', liste.rozet.some(t => /Çavuş A · Kadın · Depo A/.test(t)), JSON.stringify(liste));
        ok('pasif çavuşa tanımlı kartta "çavuş pasif" uyarı rozeti', liste.pasif);
        ok('liste: yatay taşma yok', !liste.tasma);

        await ac(page, 'K001');
        const r1 = await olc(page);
        ok('K001: modal açıldı, başlıkta kart no', r1.acik && r1.baslik === 'K001', JSON.stringify(r1));
        ok('K001: çavuş/tip karttan doldu (Çavuş A / Kadın), mevcut tanım yazıyor', /Çavuş A/.test(r1.cavusMetin) && r1.tip === 'Kadın' && /Mevcut tanım: Çavuş A · Kadın · Depo A/.test(r1.mevcut), JSON.stringify(r1));
        ok('K001: "Tanımı Kaldır" görünür', r1.bitirGorunur);
        ok('K001: dialog ekran içinde, "Tanımı Kaydet" ekranda ve TIKLANABİLİR', r1.dlg.sol >= -0.5 && r1.dlg.sag <= r1.vw + 0.5 && r1.dlg.ust >= -0.5 && r1.dlg.alt <= r1.vh + 0.5 && r1.kaydetEkranda && r1.kaydetTiklanir, JSON.stringify(r1));
        ok('K001: yatay taşma yok (sayfa + dialog)', !r1.tasma && !r1.tasmaDlg, JSON.stringify(r1));
        await page.click('#iskTanimModal .pm-close');
        await page.waitForTimeout(200);

        await ac(page, 'K003');
        const r3 = await olc(page);
        ok('K003 (tanımsız): seçimler boş, mevcut tanım yok, "Tanımı Kaldır" GİZLİ', r3.cavus === '' && r3.tip === '— Tip seçin —' && r3.mevcut === '' && !r3.bitirGorunur, JSON.stringify(r3));
        await page.click('#iskTanimModal .pm-close');
        await page.waitForTimeout(200);

        await ac(page, 'K002');
        const r2 = await olc(page);
        ok('K002 (pasif çavuş): çavuş seçimi boş (listede yok), uyarı metni', r2.cavus === '' && /çavuş pasif/.test(r2.mevcut) && r2.tip === 'Erkek', JSON.stringify(r2));
        ok('K002: uzun çavuş adı dialogu TAŞIRMADI', !r2.tasmaDlg && r2.dlg.sag <= r2.vw + 0.5, JSON.stringify(r2));
        await page.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
