// =========================================================
// scripts/pdks_tanimli_giris_smoke.js — v298 TANIMLI GİRİŞ ekranının GERÇEK
// TARAYICI testi (gunluk_isci_giris_cikis.php istemci davranışı)
//
// Sunucu mantığı (tanım, mesai açma, ret kuralları) gerçek veritabanıyla
// scripts/pdks_tanimli_giris_smoke.php'de test edilir. Bu test yalnız
// İSTEMCİYİ doğrular: ?ajax= uçları Playwright ile taklit edilir ve giden
// istekler denetlenir (tanımlı modda session_id / foreman_id / worker_type_id
// GİTMEZ; normal mod eskisi gibi).
//
//   php scripts/pdks_tanimli_giris_render.php > _test_tanimli_giris.html
//   node scripts/pdks_tanimli_giris_smoke.js
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
const SAYFA = path.join(ROOT, '_test_tanimli_giris.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_tanimli_giris.html yok. Önce: php scripts/pdks_tanimli_giris_render.php > _test_tanimli_giris.html');
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
const ESKI = [{ id: 21, foreman_id: 2, foreman_name: 'Çavuş B', work_date: '2026-10-01', depo: 'Depo A',
                ozet: { giris_toplam: 2, cikis_toplam: 2, icerde_toplam: 0, eksik_toplam: 0 } }];

async function okut(page, uid) {
    await page.focus('#giScanInput');
    await page.keyboard.type(uid);
    await page.keyboard.press('Enter');
    await page.waitForTimeout(400);
}
const gorunur = (page, id) => page.evaluate(i => {
    const el = document.getElementById(i);
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return !el.hidden && !el.closest('[hidden]') && getComputedStyle(el).display !== 'none' && r.width > 0 && r.height > 0;
}, id);

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
        page.on('dialog', d => d.accept());
        const istekler = [];
        let tanimliYanit = null;
        await page.route('**/gunluk_isci_giris_cikis.php?ajax=*', async route => {
            const req = route.request();
            const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {};
            try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde });
            let yanit;
            if (uc === 'ortak_mesailer') yanit = { ok: true, mesailer: [] };
            else if (uc === 'tanimli_giris') yanit = tanimliYanit || { ok: false, kod: 'kart_tanimli_degil', hata: 'tanımsız' };
            else if (uc === 'kapat') yanit = { ok: true };
            else if (uc === 'kapanis_kontrol') yanit = { ok: true, acik_donem_sayisi: 0, bekleyen_sinif: null, bekleyen_fazla_mesai: null, hakedis_durum: 'yok' };
            else if (uc === 'oturum') yanit = { ok: true, session: { id: 11, depo: 'Depo A', work_date: BUGUN, status: 'open' }, ozet: {} };
            else if (uc === 'kaydet') yanit = { ok: false, kod: 'test', hata: 'test' };
            else yanit = { ok: false };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });
        await page.goto(KOK);
        await page.waitForTimeout(300);

        // 1) Yerleşim
        const r1 = await page.evaluate(() => {
            const t = document.getElementById('giTanimliGirisBtn');
            const o = document.getElementById('giOrtakCikisBtn');
            const tb = t.getBoundingClientRect(), ob = o.getBoundingClientRect();
            return { gorunur: tb.width > 0 && tb.height > 0, ekranda: tb.left >= 0 && tb.right <= innerWidth + 0.5,
                     onceDom: !!(t.compareDocumentPosition(o) & Node.DOCUMENT_POSITION_FOLLOWING),
                     ustte: tb.bottom <= ob.top, bosluk: ob.top - tb.bottom,
                     metin: t.textContent.replace(/\s+/g, ' ').trim(), tasma: document.documentElement.scrollWidth > innerWidth };
        });
        ok('"🏷 TANIMLI GİRİŞ" görünür, ekran içinde', r1.gorunur && r1.ekranda && /TANIMLI GİRİŞ/.test(r1.metin), JSON.stringify(r1));
        ok('düğme ORTAK ÇIKIŞ\'ın ÜSTÜNDE (DOM + ekran), çakışma yok', r1.onceDom && r1.ustte && r1.bosluk >= 8, JSON.stringify(r1));
        ok('çavuş ekranında yatay taşma yok', !r1.tasma);

        // 2) Tanımlı moda gir
        await page.click('#giTanimliGirisBtn');
        await page.waitForTimeout(300);
        const r2 = await page.evaluate(() => ({
            rozet: document.getElementById('giModeBadge').textContent,
            ad: document.getElementById('giScanCavusAd').textContent,
            geri: document.getElementById('giCavusDegistir2').textContent,
            tipRenk: [...document.getElementById('giScanSec').classList].filter(c => /^pdks-tip-/.test(c)),
            kapat: [...document.querySelectorAll('button')].some(b => /MESA[İI]Y[İI] KAPAT/i.test(b.textContent) && b.getBoundingClientRect().height > 0 && !b.closest('[hidden]')),
            tasma: document.documentElement.scrollWidth > innerWidth,
        }));
        ok('tarama ekranı açıldı, rozet "TANIMLI GİRİŞ MODU"', (await gorunur(page, 'giScanSec')) && /TANIMLI GİRİŞ MODU/.test(r2.rozet), JSON.stringify(r2));
        ok('ad "Kartın tanımlı çavuşu", geri "Çavuş Seçimine Dön"', r2.ad === 'Kartın tanımlı çavuşu' && /Çavuş Seçimine Dön/.test(r2.geri), JSON.stringify(r2));
        ok('"Modu Değiştir", Kadın⇄Erkek hızlı geçiş ve tip rozeti GİZLİ', !(await gorunur(page, 'giModDegistir')) && !(await gorunur(page, 'giTipGecis')) && !(await gorunur(page, 'giTipBadge')));
        ok('tanımlı modda "Mesaiyi Kapat" görünmüyor, tip rengi yok', !r2.kapat && r2.tipRenk.length === 0, JSON.stringify(r2));
        ok('yatay taşma yok (tarama)', !r2.tasma);

        // 3) Başarılı okutma
        tanimliYanit = { ok: true, event_type: 'GIRIS', server_time: BUGUN + ' 08:05:00',
                         card: { card_no: 'K005', worker_type_name: 'Kadın', declared_class_label: 'Otomatik' },
                         ozet: { giris: { 'Kadın': 3 }, cikis: {} },
                         cavus: { id: 2, ad: 'Çavuş B', session_id: 12, work_date: BUGUN, onceki_gun: false },
                         tanim: { id: 1, foreman_name: 'Çavuş B', tip_adi: 'Kadın', depo: 'Depo A' }, mesailer: [] };
        istekler.length = 0;
        await okut(page, '100000005');
        const ist = istekler.filter(i => i.uc === 'tanimli_giris');
        ok('okutma tanimli_giris ucuna gitti (kaydet / ortak_cikis DEĞİL)', ist.length === 1 && !istekler.some(i => i.uc === 'kaydet' || i.uc === 'ortak_cikis'), JSON.stringify(istekler));
        const g = ist[0] ? ist[0].govde : {};
        ok('gövdede YALNIZ csrf + ham_uid + kaynak (session_id/foreman_id/worker_type_id YOK)',
            JSON.stringify(Object.keys(g).sort()) === JSON.stringify(['csrf', 'ham_uid', 'kaynak']) && g.ham_uid === '100000005' && g.kaynak === 'usb_decimal', JSON.stringify(g));
        const r3 = await page.evaluate(() => ({
            metin: document.getElementById('giResult').hidden ? '' : document.getElementById('giResultInner').textContent.replace(/\s+/g, ' '),
            sinif: document.getElementById('giResult').className,
            kutu: (r => ({ sol: r.left, sag: r.right, vw: innerWidth }))(document.getElementById('giResult').getBoundingClientRect()),
        }));
        ok('sonuç: GİRİŞ KAYDEDİLDİ + "Çavuş: Çavuş B" + tip Kadın', /GİRİŞ KAYDEDİLDİ/.test(r3.metin) && /Çavuş: Çavuş B/.test(r3.metin) && /Kadın/.test(r3.metin), r3.metin);
        ok('sonuç rengi tipten (pdks-sonuc-kadin), ekran içinde', /pdks-kiosk-result-ok/.test(r3.sinif) && /pdks-sonuc-kadin/.test(r3.sinif) && r3.kutu.sol >= -0.5 && r3.kutu.sag <= r3.kutu.vw + 0.5, JSON.stringify(r3));

        // 4) Aynı UID ~3 sn içinde → istek GİTMEZ
        istekler.length = 0;
        await okut(page, '100000005');
        ok('aynı kart 3 sn içinde tekrar okundu → istek GİTMEDİ', istekler.filter(i => i.uc === 'tanimli_giris').length === 0, JSON.stringify(istekler));

        // 5) Mükerrer → bilgi (kırmızı hata değil)
        tanimliYanit = { ok: false, kod: 'mukerrer_giris', hata: 'Bu kart zaten bu mesaide giriş yapmış.', cavus: { id: 2, ad: 'Çavuş B', session_id: 12 }, mesailer: [] };
        istekler.length = 0;
        await okut(page, '100000006');
        const r5 = await page.evaluate(() => ({ metin: document.getElementById('giResultInner').textContent.replace(/\s+/g, ' '), sinif: document.getElementById('giResult').className }));
        ok('farklı kart istek gönderdi', istekler.filter(i => i.uc === 'tanimli_giris').length === 1);
        ok('mukerrer_giris → "Zaten giriş yapıldı" bilgisi (err DEĞİL), çavuş adıyla', /Zaten giriş yapıldı/.test(r5.metin) && /Çavuş B/.test(r5.metin) && /pdks-kiosk-result-bilgi/.test(r5.sinif) && !/result-err/.test(r5.sinif), JSON.stringify(r5));

        // 6) 3 sn sonra aynı UID yeniden gider; önceki gün açık mesai → düğmeli hata, pencere OTOMATİK AÇILMAZ
        await page.waitForTimeout(3100);
        tanimliYanit = { ok: false, kod: 'onceki_mesai_acik', hata: 'Çavuş B: Bu çavuşun 01.10.2026 tarihli mesaisi kapatılmamış.', eski_oturumlar: ESKI, cavus: { id: 2, ad: 'Çavuş B' }, mesailer: [] };
        istekler.length = 0;
        await okut(page, '100000005');
        ok('3 sn sonra aynı kart yeniden okunabilir (istek gitti)', istekler.filter(i => i.uc === 'tanimli_giris').length === 1, JSON.stringify(istekler));
        const r6 = await page.evaluate(() => ({ sinif: document.getElementById('giResult').className, btn: !!document.querySelector('#giResultInner [data-gi-tanimli-eski]'),
                                               eski: !document.getElementById('giEskiSec').hidden }));
        ok('onceki_mesai_acik → hata + "Kapatılmamış mesaiyi aç" düğmesi, pencere kendiliğinden AÇILMADI', /result-err/.test(r6.sinif) && r6.btn && !r6.eski, JSON.stringify(r6));
        await page.click('#giResultInner [data-gi-tanimli-eski]');
        await page.waitForTimeout(300);
        const r6b = await page.evaluate(() => ({ eski: !document.getElementById('giEskiSec').hidden, liste: document.getElementById('giEskiListe').textContent }));
        ok('düğme → mevcut kapatılmamış mesai penceresi (Çavuş B)', r6b.eski && /Çavuş B/.test(r6b.liste), JSON.stringify(r6b));
        await page.click('#giEskiSonra');
        await page.waitForTimeout(300);
        ok('"Sonra" → Tanımlı Giriş moduna DÖNÜLDÜ', (await gorunur(page, 'giScanSec')) && /TANIMLI GİRİŞ MODU/.test(await page.textContent('#giModeBadge')));

        // 7) Pencereden kapatma → mevcut ?ajax=kapat yolu → Tanımlı moda dönüş
        await page.waitForTimeout(3100);
        istekler.length = 0;
        await okut(page, '100000005');
        await page.click('#giResultInner [data-gi-tanimli-eski]');
        await page.waitForTimeout(300);
        await page.click('[data-gi-eski-id="21"]');
        await page.waitForTimeout(300);
        ok('kapatma onay penceresi açıldı', await gorunur(page, 'giCloseConfirmSec'));
        await page.click('#giCloseConfirmBtn');
        await page.waitForTimeout(400);
        const kapat = istekler.filter(i => i.uc === 'kapat');
        ok('kapatma mevcut ?ajax=kapat ucundan, session_id 21 ile', kapat.length === 1 && kapat[0].govde.session_id === 21, JSON.stringify(istekler));
        ok('kapatma sonrası Tanımlı Giriş moduna dönüldü', (await gorunur(page, 'giScanSec')) && /TANIMLI GİRİŞ MODU/.test(await page.textContent('#giModeBadge')));
        const tas = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
        ok('yatay taşma yok (akış sonrası)', !tas);

        // 8) Çavuş seçimine dön → normal GİRİŞ eskisi gibi (session_id + worker_type_id)
        await page.click('#giCavusDegistir2');
        await page.waitForTimeout(200);
        await page.click('[data-gi-cavus-id="1"]');
        await page.waitForTimeout(300);
        await page.click('[data-gi-mode="GIRIS"]');
        await page.waitForTimeout(200);
        await page.click('[data-gi-tip-id]');
        await page.waitForTimeout(300);
        const r8 = await page.evaluate(() => ({ rozet: document.getElementById('giModeBadge').textContent, ad: document.getElementById('giScanCavusAd').textContent,
                                               modDegistir: !document.getElementById('giModDegistir').hidden, geri: document.getElementById('giCavusDegistir2').textContent }));
        ok('normal GİRİŞ modu: rozet "GİRİŞ MODU", çavuş adı, Modu Değiştir geri geldi', /^✅ GİRİŞ MODU$/.test(r8.rozet) && r8.ad === 'Çavuş A' && r8.modDegistir && /Çavuşu Değiştir/.test(r8.geri), JSON.stringify(r8));
        istekler.length = 0;
        await okut(page, '100000001');
        const k = istekler.find(i => i.uc === 'kaydet');
        ok('normal modda okutma kaydet ucuna (session_id + worker_type_id), tanimli_giris DEĞİL',
            !!k && k.govde.session_id === 11 && k.govde.event_type === 'GIRIS' && Number(k.govde.worker_type_id) > 0 && !istekler.some(i => i.uc === 'tanimli_giris'), JSON.stringify(istekler));
        await page.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
