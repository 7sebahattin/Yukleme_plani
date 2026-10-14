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
// v355: /eski — aynı sayfa, önceki günden kapatılmamış bir mesaiyle (pencere açık
// başlar). Sunucu render'ı yerine HTML'de yalnız iki yer değiştirilir: pencerenin
// `hidden`'ı çavuş listesine taşınır ve #giEskiVeri bir satırla doldurulur.
const ESKI_VERI = [{ id: 77, foreman_id: 2, foreman_name: 'Çavuş B', work_date: '2026-10-13', depo: 'Depo A',
                     ozet: { giris_toplam: 3, cikis_toplam: 1, icerde_toplam: 2, eksik_toplam: 0 } }];
function eskiSayfa() {
    let html = fs.readFileSync(SAYFA, 'utf8');
    html = html.replace('id="giEskiVeri">[]</script>', 'id="giEskiVeri">' + JSON.stringify(ESKI_VERI) + '</script>');
    html = html.replace(/(<div id="giEskiSec"[^>]*?) hidden>/, '$1>');
    html = html.replace('<div id="giCavusSec" class="pdks-kiosk-modesec">', '<div id="giCavusSec" class="pdks-kiosk-modesec" hidden>');
    return html;
}
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/eski') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(eskiSayfa()); return; }
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
        let kaydetYanit = null;
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
                        ozet: { giris: { 'Kadın': 4, 'Erkek': 2 }, cikis: { 'Kadın': 1 }, eksik_tip: { 'Kadın': 3, 'Erkek': 2 } },
                        cavus: { id: 2, ad: 'Çavuş B', session_id: 12, work_date: '2026-09-30', onceki_gun: true },
                        mesailer: SONRA }
                    : { ok: false, kod: 'acik_donem_yok', hata: 'Bu kart için açık bir mesai bulunamadı.', mesailer: SONRA };
            }
            else if (uc === 'oturum') yanit = { ok: true, session: { id: 11, depo: 'Depo A', work_date: BUGUN, status: 'open' }, ozet: {} };
            else if (uc === 'kaydet') yanit = kaydetYanit || { ok: false, kod: 'acik_donem_yok', hata: 'test' };
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
        const r1b = await page.evaluate(() => {
            const btn = document.getElementById('giOrtakCikisBtn').getBoundingClientRect();
            const ay = document.querySelector('.pdks-kiosk-ortak-ayrac');
            const liste = document.getElementById('giCavusListe').getBoundingClientRect();
            const a = ay.getBoundingClientRect();
            return { metin: ay.textContent.trim(), yukseklik: a.height, genislik: a.width, ustBosluk: a.top - btn.bottom, altBosluk: liste.top - a.bottom, arada: a.top >= btn.bottom && a.bottom <= liste.top, mesafe: liste.top - btn.bottom };
        });
        ok('ayraç düz çizgi (yazı YOK), düğme ile liste ARASINDA, mesafe ≥ 16px', r1b.metin === '' && r1b.yukseklik <= 2 && r1b.genislik > 100 && r1b.arada && r1b.mesafe >= 16 && r1b.ustBosluk >= 8 && r1b.altBosluk >= 8, JSON.stringify(r1b));
        ok('içeride rozetleri: A=2, B=1, C gizli', r1.a === 'içeride 2' && r1.b === 'içeride 1' && r1.c === null, JSON.stringify(r1));

        // 2) Ortak moda gir
        await page.click('#giOrtakCikisBtn');
        await page.waitForTimeout(300);
        const r2 = await page.evaluate(() => {
            const gor = id => { const el = document.getElementById(id); if (!el) return false; const r = el.getBoundingClientRect(); return !el.hidden && getComputedStyle(el).display !== 'none' && r.width > 0 && r.height > 0; };
            return { tarama: gor('giScanSec'), rozet: document.getElementById('giModeBadge').textContent,
                     ad: document.getElementById('giScanCavusAd').textContent, modDegistir: gor('giModDegistir'),
                     panelVar: !!document.getElementById('giOrtakSayac') || !!document.getElementById('giOrtakSatirlar'),
                     geri: document.getElementById('giCavusDegistir2').textContent,
                     kapat: [...document.querySelectorAll('button')].some(b => /MESA[İI]Y[İI] KAPAT/i.test(b.textContent) && b.getBoundingClientRect().height > 0 && !b.closest('[hidden]')),
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('tarama ekranı açıldı, rozet "ORTAK ÇIKIŞ MODU", ad "Tüm çavuşlar"', r2.tarama && /ORTAK ÇIKIŞ/.test(r2.rozet) && r2.ad === 'Tüm çavuşlar', JSON.stringify(r2));
        ok('"Modu Değiştir" gizli, geri düğmesi "Çavuş Seçimine Dön"', !r2.modDegistir && /Çavuş Seçimine Dön/.test(r2.geri), JSON.stringify(r2));
        ok('"Açık mesailer — çavuş çavuş" paneli YOK (#giOrtakSayac DOM\'da yok)', !r2.panelVar, JSON.stringify(r2));
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
            daireSayi: (d => d ? d.textContent.trim() : null)(document.querySelector('#giResultInner .pdks-result-3d-icon-count')),
            daireKalan: (d => !!d && d.getAttribute('data-sayi') === 'kalan')(document.querySelector('#giResultInner .pdks-result-3d-icon-count')),
            halka: !!document.querySelector('#giResultInner .pdks-result-3d-icon'),
            eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]'),
            daire: !!document.querySelector('#giResultInner .pdks-result-3d-icon-count'),
            baslik: /KALAN/.test(document.getElementById('giResultInner').textContent),
            rozetB: (el => el.hidden ? null : el.textContent)(document.querySelector('[data-gi-icerde-cavus="2"]')),
        }));
        ok('sonuç: ÇIKIŞ KAYDEDİLDİ + "Çavuş: Çavuş B · 30.09.2026 mesaisi"', /ÇIKIŞ KAYDEDİLDİ/.test(r3.sonuc) && /Çavuş: Çavuş B · 30\.09\.2026 mesaisi/.test(r3.sonuc), r3.sonuc);
        ok('ÇIKIŞ (Kadın kartı): AYNI daire, içinde içeride kalan Kadın = 3; "KALAN" yazısı/kutu YOK', r3.halka && r3.daireKalan && r3.daireSayi === '3' && !r3.baslik && !r3.eskiBlok, JSON.stringify(r3));
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
            sayac: !!document.getElementById('giOrtakSayac'),
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
        // 6) Normal mod ÇIKIŞ: yalnız Kadın içeren yanıt → Erkek 0
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:00:00', card: { card_no: 'K001', worker_type_name: 'Kadın', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 2 }, cikis: { 'Kadın': 1 }, eksik_tip: { 'Kadın': 1 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000001');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6 = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false, eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]') }; }));
        ok('normal ÇIKIŞ, Kadın kartı: daire = 1 (kalan Kadın)', r6.kalan && r6.sayi === '1' && !r6.eskiBlok, JSON.stringify(r6));
        // v299: Rampacı kartı → kalan dairesi yalnız Rampacı (Kadın/Erkek değil)
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:00:30', card: { card_no: 'R001', worker_type_name: 'Rampacı', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 2, 'Rampacı': 4 }, cikis: { 'Kadın': 1, 'Rampacı': 1 }, eksik_tip: { 'Kadın': 1, 'Rampacı': 3 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000001');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6r = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false }; }));
        ok('v299 ÇIKIŞ, Rampacı kartı: daire = 3 (kalan Rampacı)', r6r.kalan && r6r.sayi === '3', JSON.stringify(r6r));
        // eksik_tip yoksa yedek: giris - cikis
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:01:00', card: { card_no: 'K001', worker_type_name: 'Kadın', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 3, 'Erkek': 1 }, cikis: { 'Kadın': 1, 'Erkek': 2 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000001');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6b = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false, eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]') }; }));
        ok('eksik_tip yok: yedek giriş−çıkış, daire = 2 (Kadın)', r6b.kalan && r6b.sayi === '2', JSON.stringify(r6b));
        // Erkek kartı → yalnız Erkek (yedek hesap negatife düşmez: giriş 1 − çıkış 2 → 0)
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:02:00', card: { card_no: 'E001', worker_type_name: 'Erkek', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 3, 'Erkek': 1 }, cikis: { 'Kadın': 1, 'Erkek': 2 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000002');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6c = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false, eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]') }; }));
        ok('Erkek kartı, yedek hesap negatife düşmez: daire = 0', r6c.kalan && r6c.sayi === '0', JSON.stringify(r6c));
        // Erkek kartı, eksik_tip ile → yalnız Erkek 2
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:03:00', card: { card_no: 'E001', worker_type_name: 'Erkek', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 4, 'Erkek': 3 }, cikis: { 'Kadın': 1, 'Erkek': 1 }, eksik_tip: { 'Kadın': 3, 'Erkek': 2 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000002');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6d = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false, eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]') }; }));
        ok('Erkek kartı (eksik_tip): daire = 2 (kalan Erkek, Kadın 3 DEĞİL)', r6d.kalan && r6d.sayi === '2', JSON.stringify(r6d));
        // Tanınmayan tip → ikisi birden (bilgi kaybolmaz)
        kaydetYanit = { ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 10:04:00', card: { card_no: 'X001', worker_type_name: 'Forklift', entry_time: BUGUN + ' 08:00:00' },
                        ozet: { giris: { 'Kadın': 4, 'Erkek': 3 }, cikis: { 'Kadın': 1, 'Erkek': 1 }, eksik_tip: { 'Kadın': 3, 'Erkek': 2 } } };
        await page.waitForTimeout(100);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000002');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r6e = await page.evaluate((() => { const d = document.querySelector('#giResultInner .pdks-result-3d-icon-count'); return { sayi: d ? d.textContent.trim() : null, kalan: d ? d.getAttribute('data-sayi') === 'kalan' : false, eskiBlok: !!document.querySelector('#giResultInner .pdks-result-kalan, #giResultInner [data-kalan]') }; }));
        ok('tanınmayan tip: eski davranış (kalan sayısı değil), kutu/başlık yok', !r6e.kalan && !r6e.eskiBlok, JSON.stringify(r6e));
        // GİRİŞ: daire = toplam giriş (kalan DEĞİL)
        await page.click('#giCavusDegistir2');
        await page.waitForTimeout(200);
        await page.click('[data-gi-cavus-id="1"]');
        await page.waitForTimeout(300);
        await page.click('[data-gi-mode="GIRIS"]');
        await page.waitForTimeout(300);
        kaydetYanit = { ok: true, event_type: 'GIRIS', server_time: BUGUN + ' 08:00:00', card: { card_no: 'K001', worker_type_name: 'Kadın' },
                        ozet: { giris: { 'Kadın': 5 }, cikis: {}, eksik_tip: { 'Kadın': 5 } } };
        await page.click('[data-gi-tip-id]');
        await page.waitForTimeout(300);
        await page.focus('#giScanInput');
        await page.keyboard.type('100000001');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(400);
        const r7 = await page.evaluate(() => ({ daire: (document.querySelector('#giResultInner .pdks-result-3d-icon-count') || {}).textContent || null, kalan: (d => !!d && d.getAttribute('data-sayi') === 'kalan')(document.querySelector('#giResultInner .pdks-result-3d-icon-count')) }));
        ok('GİRİŞ sonucu: daire = toplam giriş (5), kalan sayısı değil', r7.daire === '5' && !r7.kalan, JSON.stringify(r7));
        const tas = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
        ok('yatay taşma yok (sonuç sonrası)', !tas);
        await page.close();
    }

    // ── v355: giriş kaynağı satırı (card.entry_source) + ?mod=ortak derin bağlantısı ──
    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'MOBİL',    width: 390,  height: 844 }]) {
        console.log(`\n=== v355 ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const istekler = [];
        let ortakYanit = null;
        await page.route('**/gunluk_isci_giris_cikis.php?ajax=*', async route => {
            const req = route.request();
            const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {};
            try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde });
            let yanit;
            if (uc === 'ortak_mesailer') yanit = { ok: true, mesailer: MESAILER };
            else if (uc === 'ortak_cikis') yanit = ortakYanit || { ok: false, kod: 'acik_donem_yok', hata: 'test' };
            else if (uc === 'kapat') yanit = { ok: true };
            else yanit = { ok: false };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });
        const gorunur = () => page.evaluate(() => {
            const gor = id => { const el = document.getElementById(id); if (!el) return false; const r = el.getBoundingClientRect(); return !el.hidden && getComputedStyle(el).display !== 'none' && r.width > 0 && r.height > 0; };
            return { tarama: gor('giScanSec'), cavus: gor('giCavusSec'), eski: gor('giEskiSec'),
                     rozet: document.getElementById('giModeBadge').textContent, ad: document.getElementById('giScanCavusAd').textContent,
                     tasma: document.documentElement.scrollWidth > innerWidth };
        });

        // (d) ?mod=ortak → doğrudan ortak modda tarama ekranı, çavuş listesi gizli
        await page.goto(KOK + '?mod=ortak');
        await page.waitForTimeout(300);
        const d1 = await gorunur();
        ok('v355 ?mod=ortak: tarama ekranı açık, rozet "ORTAK ÇIKIŞ", çavuş listesi gizli', d1.tarama && !d1.cavus && !d1.eski && /ORTAK ÇIKIŞ/.test(d1.rozet) && d1.ad === 'Tüm çavuşlar', JSON.stringify(d1));
        ok('v355 ?mod=ortak: yatay taşma yok', !d1.tasma, JSON.stringify(d1));
        const odak = await page.evaluate(() => document.activeElement && document.activeElement.id);
        ok('v355 ?mod=ortak: odak USB okuma kutusunda', odak === 'giScanInput', String(odak));

        const okut = async (yanit, uid) => {
            ortakYanit = yanit;
            istekler.length = 0;
            await page.waitForTimeout(100);
            await page.focus('#giScanInput');
            await page.keyboard.type(uid || '100000003');
            await page.keyboard.press('Enter');
            await page.waitForTimeout(450);
            return page.evaluate(() => {
                const k = document.querySelector('#giResultInner .pdks-result-kaynak');
                const t = document.querySelector('#giResultInner .pdks-result-time');
                const r = k ? k.getBoundingClientRect() : null;
                return { var: !!k, sinif: k ? k.className : '', metin: k ? k.textContent : '',
                         saatAltinda: !!(k && t && (t.compareDocumentPosition(k) & Node.DOCUMENT_POSITION_FOLLOWING)),
                         ekranda: r ? (r.left >= -0.5 && r.right <= innerWidth + 0.5 && r.width > 0) : null,
                         bg: k ? getComputedStyle(k).backgroundColor : '',
                         sonuc: document.getElementById('giResult').hidden ? '' : document.getElementById('giResultInner').textContent.replace(/\s+/g, ' '),
                         tasma: document.documentElement.scrollWidth > innerWidth };
            });
        };
        const cikis = (kaynak, giris) => ({ ok: true, event_type: 'CIKIS', server_time: BUGUN + ' 17:05:00',
            card: { card_no: 'K003', worker_type_name: 'Kadın', entry_time: giris === undefined ? BUGUN + ' 08:00:00' : giris, entry_source: kaynak },
            ozet: { giris: { 'Kadın': 4 }, cikis: { 'Kadın': 1 }, eksik_tip: { 'Kadın': 3 } },
            cavus: { id: 1, ad: 'Çavuş A', session_id: 11, work_date: BUGUN, onceki_gun: false }, mesailer: MESAILER });

        // (a) ters
        const a = await okut(cikis('ters'));
        ok('v355 (a) ters: istek ortak_cikis ucuna gitti', istekler.some(i => i.uc === 'ortak_cikis'), JSON.stringify(istekler));
        ok('v355 (a) ters: .pdks-result-kaynak-ters görünür, "Giriş Tanımlı" + "08:00"', a.var && /pdks-result-kaynak-ters/.test(a.sinif) && /Giriş Tanımlı/.test(a.metin) && /sabit giriş 08:00/.test(a.metin), JSON.stringify(a));
        ok('v355 (a) ters: kaynak satırı saat satırının ALTINDA, ekran içinde, taşma yok', a.saatAltinda && a.ekranda && !a.tasma, JSON.stringify(a));
        ok('v355 (a) ters: ÇIKIŞ KAYDEDİLDİ + saat 08:00 → 17:05 korunur', /ÇIKIŞ KAYDEDİLDİ/.test(a.sonuc) && /08:00 → 17:05/.test(a.sonuc), a.sonuc);
        ok('v355 (a) ters: açık temada indigo zemin (#eef2ff)', a.bg === 'rgb(238, 242, 255)', a.bg);
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        const koyu = await page.evaluate(() => { const k = document.querySelector('#giResultInner .pdks-result-kaynak'); return k ? getComputedStyle(k).backgroundColor + '|' + getComputedStyle(k).color : ''; });
        ok('v355 (a) ters: koyu temada #1e1b4b / #c7d2fe', koyu === 'rgb(30, 27, 75)|rgb(199, 210, 254)', koyu);
        await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
        // ters, giriş saati gelmediyse yalnız etiket (uydurma saat YOK)
        const a2 = await okut(cikis('ters', null));
        ok('v355 ters, entry_time yok: yalnız "↩ Giriş Tanımlı" (saat uydurulmaz)', a2.var && a2.metin === '↩ Giriş Tanımlı', JSON.stringify(a2));

        // (b) scan → satır YOK; boş/bilinmeyen/legacy de YOK
        const b = await okut(cikis('scan'));
        ok('v355 (b) scan: kaynak satırı YOK (eski görünüm)', !b.var && /ÇIKIŞ KAYDEDİLDİ/.test(b.sonuc), JSON.stringify(b));
        const b2 = await okut(cikis(undefined));
        ok('v355 entry_source yok: kaynak satırı YOK', !b2.var && /ÇIKIŞ KAYDEDİLDİ/.test(b2.sonuc), JSON.stringify(b2));
        const b3 = await okut(cikis('legacy_backfill'));
        ok('v355 legacy_backfill: kaynak satırı YOK', !b3.var && /ÇIKIŞ KAYDEDİLDİ/.test(b3.sonuc), JSON.stringify(b3));
        const b4 = await okut(cikis('"><img src=x onerror=alert(1)>'));
        ok('v355 bilinmeyen/kötü niyetli kaynak: satır YOK, işaretleme enjekte edilmez', !b4.var && !(await page.$('#giResultInner img')), JSON.stringify(b4));

        // (c) tanimli / manual
        const c1 = await okut(cikis('tanimli'));
        ok('v355 (c) tanimli: "🏷 Tanımlı giriş"', c1.var && /pdks-result-kaynak-tanimli/.test(c1.sinif) && c1.metin === '🏷 Tanımlı giriş' && c1.ekranda, JSON.stringify(c1));
        const c2 = await okut(cikis('manual'));
        ok('v355 (c) manual: "✍ Elle eklenmiş giriş"', c2.var && /pdks-result-kaynak-manual/.test(c2.sinif) && c2.metin === '✍ Elle eklenmiş giriş' && c2.ekranda, JSON.stringify(c2));

        // GİRİŞ sonucu DEĞİŞMEZ — entry_source gelse bile satır yok
        const g = await okut({ ok: true, event_type: 'GIRIS', server_time: BUGUN + ' 08:00:00',
            card: { card_no: 'K003', worker_type_name: 'Kadın', entry_time: BUGUN + ' 08:00:00', entry_source: 'ters' },
            ozet: { giris: { 'Kadın': 4 } } });
        ok('v355 GİRİŞ sonucu: kaynak satırı YOK', !g.var && /GİRİŞ KAYDEDİLDİ/.test(g.sonuc), JSON.stringify(g));
        ok('v355 (e) sonuç sonrası yatay taşma yok', !g.tasma && !a.tasma && !c1.tasma && !c2.tasma);

        // Bilinmeyen ?mod → yok sayılır (çavuş listesi)
        await page.goto(KOK + '?mod=xyz');
        await page.waitForTimeout(300);
        const x = await gorunur();
        ok('v355 bilinmeyen ?mod=xyz yok sayılır: çavuş listesi açık, tarama gizli', x.cavus && !x.tarama && !x.eski, JSON.stringify(x));
        // Parametresiz açılış eskisi gibi
        await page.goto(KOK);
        await page.waitForTimeout(300);
        const y = await gorunur();
        ok('v355 parametresiz açılış: çavuş listesi (eski davranış)', y.cavus && !y.tarama, JSON.stringify(y));

        // ?mod=ortak + kapatılmamış mesai penceresi → ÖNCE pencere, "Sonra" → ortak mod
        await page.goto(KOK + 'eski?mod=ortak');
        await page.waitForTimeout(300);
        const e1 = await gorunur();
        const eskiSatir = await page.evaluate(() => document.getElementById('giEskiListe').textContent);
        ok('v355 ?mod=ortak + açık eski mesai: ÖNCE kapatılmamış mesai penceresi', e1.eski && !e1.tarama && !e1.cavus && /Çavuş B/.test(eskiSatir), JSON.stringify(e1));
        await page.click('#giEskiSonra');
        await page.waitForTimeout(300);
        const e2 = await gorunur();
        ok('v355 pencerede "Sonra" → ORTAK ÇIKIŞ tarama ekranı (çavuş listesi DEĞİL)', e2.tarama && !e2.cavus && !e2.eski && /ORTAK ÇIKIŞ/.test(e2.rozet), JSON.stringify(e2));
        ok('v355 pencere sonrası yatay taşma yok', !e2.tasma, JSON.stringify(e2));
        // Ortak moddan çavuş seçimine dönüp banner ile pencereyi açınca "Sonra" artık çavuş listesine döner
        await page.click('#giCavusDegistir2');
        await page.waitForTimeout(200);
        await page.click('#giEskiBanner');
        await page.waitForTimeout(200);
        await page.click('#giEskiSonra');
        await page.waitForTimeout(300);
        const e3 = await gorunur();
        ok('v355 derin bağlantı tek seferlik: sonraki "Sonra" çavuş listesine döner', e3.cavus && !e3.tarama && !e3.eski, JSON.stringify(e3));
        // Pencereli sayfa, parametresiz: "Sonra" eskisi gibi çavuş listesi
        await page.goto(KOK + 'eski');
        await page.waitForTimeout(300);
        await page.click('#giEskiSonra');
        await page.waitForTimeout(300);
        const e4 = await gorunur();
        ok('v355 parametresiz pencereli açılış: "Sonra" → çavuş listesi (eski davranış)', e4.cavus && !e4.tarama, JSON.stringify(e4));
        // ?mod=ortak + pencereden son eski mesai kapatılınca → ortak mod (kapatma yine ?ajax=kapat)
        page.on('dialog', dlg => dlg.accept());
        await page.goto(KOK + 'eski?mod=ortak');
        await page.waitForTimeout(300);
        await page.click('[data-gi-eski-id="77"]');
        await page.waitForTimeout(200);
        istekler.length = 0;
        await page.click('#giCloseConfirmBtn');
        await page.waitForTimeout(400);
        const e5 = await gorunur();
        const kapatIst = istekler.find(i => i.uc === 'kapat');
        ok('v355 ?mod=ortak: son eski mesai ?ajax=kapat ile kapanınca → ORTAK ÇIKIŞ', !!kapatIst && kapatIst.govde.session_id === 77 && e5.tarama && !e5.eski && /ORTAK ÇIKIŞ/.test(e5.rozet), JSON.stringify({ e5, istekler }));
        await page.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
