// =========================================================
// scripts/pdks_ortak_cikis_smoke.js — v288 ORTAK ÇIKIŞ ekranının GERÇEK
// TARAYICI testi (gunluk_isci_giris_cikis.php istemci davranışı)
//
// Sunucu mantığı (doğru mesaiye yazma, depo/kapalı mesai reddi) gerçek
// veritabanıyla scripts/pdks_ortak_cikis_smoke.php'de test edilir. Bu test
// yalnız İSTEMCİYİ doğrular: ?ajax= uçları Playwright ile taklit edilir ve
// giden istekler denetlenir (ortak modda session_id GİTMEZ, normal modda
// GİDER — iki mod birbirine karışmaz).
//
//   php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html
//   node scripts/pdks_ortak_cikis_smoke.js
//
// Playwright yoksa kendini ATLAR (roles_modal_smoke.js ile aynı kural).
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
const SAYFA = path.join(ROOT, '_test_ortak_cikis.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_ortak_cikis.html yok. Önce: php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(74)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

// Yalnız sayfa + assets/ sunan küçük statik sunucu (fetch file:// ile çalışmaz).
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const dosya = u.pathname === '/' ? SAYFA : path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

const BUGUN = new Date().toISOString().slice(0, 10);
const MESAILER = [
    { session_id: 11, foreman_id: 1, foreman_name: 'Çavuş A', work_date: BUGUN, onceki_gun: false, giris: 2, cikis: 0, icerde: 2 },
    { session_id: 12, foreman_id: 2, foreman_name: 'Çavuş B', work_date: '2026-09-30', onceki_gun: true, giris: 1, cikis: 0, icerde: 1 },
];
const SONRA = [MESAILER[0], Object.assign({}, MESAILER[1], { cikis: 1, icerde: 0 })];

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
        const istekler = [];
        let cikisSayisi = 0;
        await page.route('**/gunluk_isci_giris_cikis.php?ajax=*', async route => {
            const req = route.request();
            const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {};
            try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde });
            let yanit;
            if (uc === 'ortak_mesailer') yanit = { ok: true, mesailer: MESAILER };
            else if (uc === 'ortak_cikis') {
                cikisSayisi++;
                yanit = cikisSayisi === 1
                    ? { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 01:05:00',
                        card: { card_no: 'K003', worker_type_name: 'Kadın', entry_time: '2026-09-30 17:00:00' },
                        ozet: { giris: { 'Kadın': 1 }, cikis: { 'Kadın': 1 }, giris_toplam: 1, cikis_toplam: 1 },
                        cavus: { id: 2, ad: 'Çavuş B', session_id: 12, work_date: '2026-09-30', onceki_gun: true },
                        mesailer: SONRA }
                    : { ok: false, kod: 'acik_donem_yok', hata: 'Bu kart için açık bir mesai bulunamadı.', mesailer: SONRA };
            }
            else if (uc === 'oturum') yanit = { ok: true, session: { id: 11, depo: 'Depo A', work_date: BUGUN, status: 'open' }, ozet: {} };
            else if (uc === 'kaydet') yanit = { ok: false, kod: 'acik_donem_yok', hata: 'test' };
            else yanit = { ok: false };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });
        await page.goto(KOK);
        await page.waitForTimeout(300);

        // 1) Çavuş ekranı
        const r1 = await page.evaluate(() => {
            const btn = document.getElementById('giOrtakCikisBtn');
            const b = btn.getBoundingClientRect();
            const rozet = id => { const el = document.querySelector('[data-gi-icerde-cavus="' + id + '"]'); return el.hidden ? null : el.textContent.trim(); };
            return { gorunur: b.width > 0 && b.height > 0, ekranda: b.left >= 0 && b.right <= innerWidth + 0.5,
                     listeUstunde: btn.compareDocumentPosition(document.getElementById('giCavusListe')) & Node.DOCUMENT_POSITION_FOLLOWING,
                     a: rozet(1), b: rozet(2), c: rozet(3) };
        });
        ok('ortak çıkış düğmesi görünür, ekran içinde ve çavuş listesinin ÜSTÜNDE', r1.gorunur && r1.ekranda && !!r1.listeUstunde, JSON.stringify(r1));
        ok('içeride rozetleri: A=2, B=1, C gizli', r1.a === 'içeride 2' && r1.b === 'içeride 1' && r1.c === null, JSON.stringify(r1));

        // 2) Ortak moda gir
        await page.click('#giOrtakCikisBtn');
        await page.waitForTimeout(300);
        const r2 = await page.evaluate(() => {
            const gor = id => { const el = document.getElementById(id); if (!el) return false; const r = el.getBoundingClientRect(); return !el.hidden && getComputedStyle(el).display !== 'none' && r.width > 0 && r.height > 0; };
            return { tarama: gor('giScanSec'), rozet: document.getElementById('giModeBadge').textContent,
                     ad: document.getElementById('giScanCavusAd').textContent, modDegistir: gor('giModDegistir'),
                     sayac: gor('giOrtakSayac'), geri: document.getElementById('giCavusDegistir2').textContent,
                     satirlar: [...document.querySelectorAll('#giOrtakSatirlar .pdks-kiosk-counter-row')].map(e => e.textContent.replace(/\s+/g, ' ').trim()),
                     kapat: [...document.querySelectorAll('button')].some(b => /MESA[İI]Y[İI] KAPAT/i.test(b.textContent) && b.getBoundingClientRect().height > 0 && !b.closest('[hidden]')),
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('tarama ekranı açıldı, rozet "ORTAK ÇIKIŞ MODU", ad "Tüm çavuşlar"', r2.tarama && /ORTAK ÇIKIŞ/.test(r2.rozet) && r2.ad === 'Tüm çavuşlar', JSON.stringify(r2));
        ok('"Modu Değiştir" gizli, geri düğmesi "Çavuş Seçimine Dön"', !r2.modDegistir && /Çavuş Seçimine Dön/.test(r2.geri), JSON.stringify(r2));
        ok('çavuş çavuş sayaç görünür: 2 satır (A içeride 2, B içeride 1)', r2.sayac && r2.satirlar.length === 2 && /Çavuş A.*İçeride 2/.test(r2.satirlar[0]) && /Çavuş B.*İçeride 1/.test(r2.satirlar[1]), JSON.stringify(r2.satirlar));
        ok('dünden açık mesai tarihiyle işaretli (30.09.2026)', /30\.09\.2026/.test(r2.satirlar[1] || ''), JSON.stringify(r2.satirlar));
        ok('ortak modda "Mesaiyi Kapat" görünmüyor', !r2.kapat);
        ok('yatay taşma yok', !r2.tasma);

        // 3) USB okutma (klavye taklitli okuyucu: rakamlar + Enter)
        istekler.length = 0;
        await page.focus('#giScanInput');
        await page.keyboard.type('100000003');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const ist = istekler.find(i => i.uc === 'ortak_cikis');
        ok('okutma ortak_cikis ucuna gitti (kaydet ucuna DEĞİL)', !!ist && !istekler.some(i => i.uc === 'kaydet'), JSON.stringify(istekler));
        ok('istek gövdesinde session_id YOK, ham_uid/kaynak/csrf VAR', !!ist && !('session_id' in ist.govde) && ist.govde.ham_uid === '100000003' && ist.govde.kaynak === 'usb_decimal' && !!ist.govde.csrf, JSON.stringify(ist && ist.govde));
        const r3 = await page.evaluate(() => ({
            sonuc: document.getElementById('giResult').hidden ? '' : document.getElementById('giResultInner').textContent.replace(/\s+/g, ' '),
            satirlar: [...document.querySelectorAll('#giOrtakSatirlar .pdks-kiosk-counter-row')].map(e => e.textContent.replace(/\s+/g, ' ').trim()),
            rozetB: (el => el.hidden ? null : el.textContent)(document.querySelector('[data-gi-icerde-cavus="2"]')),
        }));
        ok('sonuç: ÇIKIŞ KAYDEDİLDİ + "Çavuş: Çavuş B · 30.09.2026 mesaisi"', /ÇIKIŞ KAYDEDİLDİ/.test(r3.sonuc) && /Çavuş: Çavuş B · 30\.09\.2026 mesaisi/.test(r3.sonuc), r3.sonuc);
        ok('sayaç yanıttaki mesailerle güncellendi (B içeride 0)', /Çavuş B.*İçeride 0/.test(r3.satirlar[1] || ''), JSON.stringify(r3.satirlar));
        ok('çavuş listesindeki B rozeti gizlendi (içeride 0)', r3.rozetB === null, String(r3.rozetB));
        const sonucKutu = await page.evaluate(() => { const r = document.getElementById('giResult').getBoundingClientRect(); return { sol: r.left, sag: r.right, vw: innerWidth }; });
        ok('sonuç kartı ekran içinde', sonucKutu.sol >= -0.5 && sonucKutu.sag <= sonucKutu.vw + 0.5, JSON.stringify(sonucKutu));

        // 4) Hata yolu
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000003');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const hataMetni = await page.evaluate(() => document.getElementById('giResultInner').textContent);
        ok('ikinci okutma: sunucu hata mesajı ekranda', /açık bir mesai bulunamadı/.test(hataMetni), hataMetni);

        // 5) Çavuş seçimine dön → normal akış eski hâline döner
        await page.click('#giCavusDegistir2');
        await page.waitForTimeout(200);
        await page.click('[data-gi-cavus-id="1"]');
        await page.waitForTimeout(300);
        await page.click('[data-gi-mode="CIKIS"]');
        await page.waitForTimeout(300);
        const r5 = await page.evaluate(() => ({
            rozet: document.getElementById('giModeBadge').textContent,
            ad: document.getElementById('giScanCavusAd').textContent,
            modDegistir: !document.getElementById('giModDegistir').hidden,
            sayac: !document.getElementById('giOrtakSayac').hidden,
            geri: document.getElementById('giCavusDegistir2').textContent,
        }));
        ok('normal ÇIKIŞ modu: rozet "ÇIKIŞ MODU", seçili çavuş adı', /^🚪 ÇIKIŞ MODU$/.test(r5.rozet) && r5.ad === 'Çavuş A', JSON.stringify(r5));
        ok('normal modda "Modu Değiştir" geri geldi, ortak sayaç gizli, etiket eski', r5.modDegistir && !r5.sayac && /Çavuşu Değiştir/.test(r5.geri), JSON.stringify(r5));
        istekler.length = 0;
        await page.focus('#giScanInput');
        await page.keyboard.type('100000001');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const ist5 = istekler.find(i => i.uc === 'kaydet');
        ok('normal modda okutma kaydet ucuna, session_id + event_type ile', !!ist5 && ist5.govde.session_id === 11 && ist5.govde.event_type === 'CIKIS' && !istekler.some(i => i.uc === 'ortak_cikis'), JSON.stringify(istekler));
        await page.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
