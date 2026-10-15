// =========================================================
// scripts/pdks_ters_kart_smoke.js — v354 TERS KART TAKİP ekranlarının GERÇEK
// TARAYICI testi (ters_kart*.php + assets/ters_kart.js + pdks.css ".tk-*").
//
// Sayfalar scripts/pdks_ters_kart_render.php ile GERÇEK sayfa koduyla (bellek
// içi SQLite) basılır; test bunları kendisi üretir (_test_ters_*.html, .gitignore'da).
// ?ajax= uçları Playwright ile taklit edilir, giden istek gövdeleri denetlenir:
// istemci YALNIZ ham UID + kaynak yollar (çavuş / kart id / mesai id GİTMEZ),
// okumalar kuyrukta SIRAYLA işlenir, aynı kart tekrar süresi içinde yok sayılır,
// onay/geri alma çift gönderilmez, hata / ağ hatası / CSRF 403 ekranda yazılır.
// Ayrıca 7 ekran × 3 genişlikte yatay taşma + JS hatası, koyu tema kontrastı,
// dokunmatik hedef boyları ve "kurulmamış" durumu ölçülür.
//
//   node scripts/pdks_ters_kart_smoke.js
//
// Playwright yoksa kendini ATLAR (roles_modal_smoke.js ile aynı kural).
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');

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
const SAYFALAR = ['hub', 'havuz', 'sayim', 'sayim_onayli', 'sayim_manuel_onayli', 'sayim_secim', 'mutabakat'];

// ── Render (gerçek sayfa kodu) ──
function render(ad, sayfa, ekEnv) {
    const dosya = path.join(ROOT, `_test_ters_${ad}.html`);
    try {
        const html = execFileSync('php', [path.join(ROOT, 'scripts/pdks_ters_kart_render.php')], {
            cwd: ROOT, env: Object.assign({}, process.env, { TK_SAYFA: sayfa }, ekEnv || {}), encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        if (!html || html.length < 200) throw new Error('boş çıktı');
        fs.writeFileSync(dosya, html);
    } catch (e) {
        console.error(`Render başarısız (${ad}): ${(e.stderr || e.message || '').toString().trim()}`);
        process.exit(1);
    }
    return `_test_ters_${ad}.html`;
}
const HTML = {};
for (const s of SAYFALAR) HTML[s] = render(s, s);
HTML.kurulmamis = render('hub_kurulmamis', 'hub', { TK_KURULU: '0' });

let hata = 0, gecen = 0;
function ok(ad, kosul, ipucu) {
    if (kosul) gecen++; else hata++;
    console.log(`${ad.padEnd(86)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
function bilgi(m) { console.log('    · ' + m); }

// ── Statik sunucu (PHP kaynağı SUNULMAZ; .php istekleri route ile karşılanır) ──
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.html': 'text/html; charset=utf-8' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const dosya = path.join(ROOT, path.normalize(decodeURIComponent(u.pathname)).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || /\.php$/i.test(dosya) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'application/octet-stream' });
    fs.createReadStream(dosya).pipe(res);
});

const BUGUN = new Date().toISOString().slice(0, 10);
const SAYAC = (kalan) => ({ havuz_aktif: 19, kalan, dagitilacak: 19 - kalan, haric: 1, teyit: 0 });
const ONIZ_OK = { ok: true, degerlendirme: { ok: true, ozet: { calisan: 16, tipler: { 'Kadın': 9, 'Erkek': 7 }, zaten_iceride: 0, havuz_aktif: 19, kalan: 3, haric: 1 }, genel: [], hatalar: [], uyarilar: [] } };
const ONIZ_HATA = { ok: true, degerlendirme: { ok: false, ozet: { calisan: 16, tipler: { 'Kadın': 9, 'Erkek': 7 }, zaten_iceride: 0, havuz_aktif: 19, kalan: 3, haric: 1 }, genel: [],
    hatalar: [{ kart_id: 55, card_no: 'K055', sebep: 'Dünkü mesaiden dönmedi.', donmemis: true }], uyarilar: ['Beyan sayısı tutmuyor.'] } };

/**
 * Sayfa kur: .php istekleri taklit edilir. durum.yanit[uc] = nesne | fonksiyon(govde) | 'abort'
 * | {__status, __body}. durum.gecikme[uc] ms. Gövdeler durum.istekler'e yazılır.
 */
async function sayfaAc(browser, html, viewport, durum, opt = {}) {
    const ctx = await browser.newContext({ viewport });
    if (opt.nfc) {
        await ctx.addInitScript(() => {
            window.NDEFReader = class { constructor() { window.__ndef = this; this.l = {}; } addEventListener(t, f) { this.l[t] = f; } scan() { return Promise.resolve(); } };
        });
    }
    if (opt.init) await ctx.addInitScript(opt.init);
    const page = await ctx.newPage();
    durum.hatalar = durum.hatalar || [];
    durum.istekler = durum.istekler || [];
    durum.ucus = 0; durum.maxUcus = 0;
    page.on('pageerror', e => durum.hatalar.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) durum.hatalar.push('console: ' + m.text()); });
    page.on('dialog', d => d.accept());
    await page.route(u => /\.php$/i.test(u.pathname), async route => {
        const req = route.request();
        const u = new URL(req.url());
        const uc = u.searchParams.get('ajax');
        if (!uc) {   // gezinme (location.href / reload) — sahte sayfa
            durum.gezinme = u.pathname.replace(/^\//, '') + u.search;
            await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>NAV</title><p id="nav">NAV</p>' });
            return;
        }
        let govde = {};
        try { govde = JSON.parse(req.postData() || '{}'); } catch (e) { govde = { __bozuk: req.postData() }; }
        const kayit = { uc, dosya: u.pathname.replace(/^\//, ''), yontem: req.method(), govde, t: Date.now() };
        durum.istekler.push(kayit);
        durum.ucus++; durum.maxUcus = Math.max(durum.maxUcus, durum.ucus);
        try {
            const gec = (durum.gecikme || {})[uc] || 0;
            if (gec) await new Promise(r => setTimeout(r, gec));
            let y = (durum.yanit || {})[uc];
            if (typeof y === 'function') y = y(govde, kayit);
            if (y === 'abort') { await route.abort('failed'); return; }
            if (y && y.__status) {
                const ham = typeof y.__body === 'string';
                await route.fulfill({ status: y.__status, contentType: ham ? 'text/html; charset=utf-8' : 'application/json', body: ham ? y.__body : JSON.stringify(y.__body) });
                return;
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(y || { ok: false, kod: 'test', hata: 'tanımsız uç: ' + uc }) });
        } finally { durum.ucus--; }
    });
    await page.goto(durum.kok + html);
    await page.waitForTimeout(350);
    return { ctx, page };
}

async function okut(page, uid, bekle = 350) {
    await page.focus('#tkScanInput');
    await page.keyboard.type(uid);
    await page.keyboard.press('Enter');
    if (bekle) await page.waitForTimeout(bekle);
}
const istek = (durum, uc) => durum.istekler.filter(i => i.uc === uc);
const anahtarlar = g => JSON.stringify(Object.keys(g || {}).sort());
const gorunur = (page, sec) => page.evaluate(s => {
    const el = document.querySelector(s);
    if (!el) return false;
    const r = el.getBoundingClientRect();
    return !el.hidden && !el.closest('[hidden]') && getComputedStyle(el).display !== 'none' && getComputedStyle(el).visibility !== 'hidden' && r.width > 0 && r.height > 0;
}, sec);
const sonucOku = page => page.evaluate(() => {
    const k = document.getElementById('tkSonuc');
    return k ? { gizli: k.hidden, sinif: k.className, metin: k.textContent.replace(/\s+/g, ' ').trim() } : null;
});
const tasma = page => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: window.innerWidth }));

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    const EKRANLAR = [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 }, { ad: 'TABLET', width: 820, height: 1024 }, { ad: 'MOBİL', width: 390, height: 844 }];
    const varsayilan = () => ({
        kok: KOK,
        yanit: {
            onizle: ONIZ_OK,
            okut: g => ({ ok: true, mukerrer: false, card_no: 'K' + String(g.ham_uid).slice(-3), kart_id: 1000 + (+String(g.ham_uid).slice(-3)), tip_adi: 'Kadın', sayac: SAYAC(4) }),
            karar: { ok: true, sayac: SAYAC(3) },
            cikis: { ok: false, kod: 'acik_donem_yok', hata: 'Kartın açık dönemi yok.' },
            ekle: { ok: true, sonuc: 'eklendi', card_no: 'K050', tip_adi: 'Kadın', ozet: { toplam: 21, aktif: 20, kayip: 0, bozuk: 1, kullanim_disi: 0 } },
        },
    });

    // ===================================================================
    // 1) 7 ekran (+ kurulmamış hub) × 3 genişlik: yatay taşma + JS hatası
    // ===================================================================
    console.log('\n=== 1) Yerleşim: yatay taşma + JS hatası ===');
    for (const ekran of EKRANLAR) {
        for (const s of [...SAYFALAR, 'kurulmamis']) {
            const durum = varsayilan();
            const { ctx, page } = await sayfaAc(browser, HTML[s], { width: ekran.width, height: ekran.height }, durum);
            await page.waitForTimeout(150);
            const t = await tasma(page);
            let tasan = [];
            if (t.sw > t.vw + 1) {
                tasan = await page.evaluate(() => [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1)
                    .slice(0, 5).map(e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).join('.') : '') + ' r=' + Math.round(e.getBoundingClientRect().right)));
            }
            ok(`${ekran.width}px ${s.padEnd(12)}: yatay taşma yok (sw ${t.sw} ≤ vw ${t.vw}+1)`, t.sw <= t.vw + 1, tasan.join(' | '));
            ok(`${ekran.width}px ${s.padEnd(12)}: JS hatası yok`, durum.hatalar.length === 0, durum.hatalar.join(' | '));
            await ctx.close();
        }
    }

    // Koyu tema (390, sayım)
    {
        const durum = varsayilan();
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: 390, height: 844 }, durum);
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        await page.waitForTimeout(200);
        const t = await tasma(page);
        ok('koyu tema 390px sayım: yatay taşma yok', t.sw <= t.vw + 1, JSON.stringify(t));
        const k = await page.evaluate(() => {
            const rgb = s => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
            const zemin = el => { for (let e = el; e; e = e.parentElement) { const c = rgb(getComputedStyle(e).backgroundColor); if (c && c.a > 0.5) return c; } return rgb(getComputedStyle(document.documentElement).backgroundColor) || { r: 255, g: 255, b: 255 }; };
            const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
            const kontrast = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
            // v363: gizli (seçilmemiş) manuel paneldeki sayaçlar ölçülmez — yalnız görünenler.
            return [...[...document.querySelectorAll('.tk-sayac-n')].filter(e => !e.closest('[hidden]')), document.querySelector('.tk-sekme.aktif'), ...document.querySelectorAll('.tk-sekme:not(.aktif)')]
                .filter(Boolean).map(el => { const c = rgb(getComputedStyle(el).color), z = zemin(el); return { s: el.className + ' "' + el.textContent.trim().slice(0, 14) + '"', c, z, k: +kontrast(c, z).toFixed(2), ayni: c.r === z.r && c.g === z.g && c.b === z.b }; });
        });
        const sayacN = k.filter(x => /tk-sayac-n/.test(x.s)), sekme = k.filter(x => /tk-sekme aktif/.test(x.s));
        ok('koyu tema: .tk-sayac-n ölçüldü (4) ve .tk-sekme.aktif var', sayacN.length === 4 && sekme.length === 1, JSON.stringify(k));
        ok('koyu tema: .tk-sayac-n metin rengi ≠ zemin', sayacN.every(x => !x.ayni), JSON.stringify(sayacN));
        ok('koyu tema: .tk-sekme.aktif metin rengi ≠ zemin', sekme.every(x => !x.ayni), JSON.stringify(sekme));
        ok('koyu tema: tüm ölçülen metinlerde kontrast ≥ 3:1', k.every(x => x.k >= 3), k.map(x => x.s + ' ' + x.k).join(' | '));
        bilgi('kontrastlar: ' + k.map(x => x.s.replace(/^tk-/, '') + ' ' + x.k).join(' · '));
        ok('koyu tema: JS hatası yok', durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();
    }

    // ===================================================================
    // 2) Sabah sayımı — okutma
    // ===================================================================
    for (const ekran of [EKRANLAR[0], EKRANLAR[2]]) {
        console.log(`\n=== 2) Sabah sayımı — okutma (${ekran.width}px) ===`);
        const durum = varsayilan();
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: ekran.width, height: ekran.height }, durum);
        const ilkCip = await page.evaluate(() => document.querySelectorAll('#tkKalanListe .tk-cip').length);
        durum.istekler.length = 0;
        await okut(page, '700000001', 0);
        const t0 = Date.now();   // Enter'dan HEMEN sonra — gerçek aralık ölçülenden biraz uzundur
        await page.waitForTimeout(300);
        let o = istek(durum, 'okut');
        ok('okutma → 1 POST okut (ters_kart_sayim.php)', o.length === 1 && o[0].yontem === 'POST' && o[0].dosya === 'ters_kart_sayim.php', JSON.stringify(durum.istekler));
        const g = o[0] ? o[0].govde : {};
        ok('okut gövdesi YALNIZ {csrf, sayim_id, ham_uid, kaynak} (çavuş/kart id YOK)', anahtarlar(g) === JSON.stringify(['csrf', 'ham_uid', 'kaynak', 'sayim_id']), JSON.stringify(g));
        ok('değerler: csrf=testcsrf, sayim_id=1, ham_uid=700000001, kaynak=usb_decimal', g.csrf === 'testcsrf' && g.sayim_id === 1 && g.ham_uid === '700000001' && g.kaynak === 'usb_decimal', JSON.stringify(g));
        let s = await sonucOku(page);
        ok('başarı: #tkSonuc görünür + yeşil (tk-sonuc-ok), kart no yazıyor', !s.gizli && /tk-sonuc-ok/.test(s.sinif) && /K001/.test(s.metin), JSON.stringify(s));
        const c = await page.evaluate(() => ({ n: document.querySelectorAll('#tkKalanListe .tk-cip').length,
            ilk: document.querySelector('#tkKalanListe .tk-cip') ? document.querySelector('#tkKalanListe .tk-cip').dataset.kart : null,
            kalan: document.querySelector('[data-tk-sayac="kalan"]').textContent, dag: document.querySelector('[data-tk-sayac="dagitilacak"]').textContent }));
        ok('#tkKalanListe\'ye yeni çip (kart 1001) EN BAŞA eklendi', c.n === ilkCip + 1 && c.ilk === '1001', JSON.stringify(c) + ' ilk=' + ilkCip);
        ok('data-tk-sayac="kalan" sunucu sayacıyla güncellendi (4)', c.kalan === '4', JSON.stringify(c));

        // aynı UID 1.5 sn içinde
        durum.istekler.length = 0;
        await okut(page, '700000001', 200);
        ok(`aynı UID ${Date.now() - t0} ms sonra (<1500) → ikinci POST GİTMEDİ`, istek(durum, 'okut').length === 0, JSON.stringify(durum.istekler));
        // 1.5 sn sonra tekrar gider
        await page.waitForTimeout(Math.max(0, 1600 - (Date.now() - t0)));
        durum.istekler.length = 0;
        durum.yanit.okut = { ok: true, mukerrer: true, card_no: 'K001', kart_id: 1001, tip_adi: 'Kadın', sayac: SAYAC(4) };
        await okut(page, '700000001', 300);
        s = await sonucOku(page);
        ok('1.5 sn sonra aynı UID yeniden gider; mükerrer → mavi bilgi', istek(durum, 'okut').length === 1 && /tk-sonuc-bilgi/.test(s.sinif) && /zaten okutuldu/.test(s.metin), JSON.stringify(s));

        // hata yanıtı
        durum.yanit.okut = { ok: false, kod: 'havuz_disi', hata: 'Bu kart bu çavuşun havuzunda değil.', sayac: SAYAC(4) };
        await okut(page, '700000002', 300);
        s = await sonucOku(page);
        ok('hata yanıtı → kırmızı (tk-sonuc-hata) + sunucu metni', !s.gizli && /tk-sonuc-hata/.test(s.sinif) && /havuzunda değil/.test(s.metin), JSON.stringify(s));
        // XSS: kullanıcı verisi textContent ile
        durum.yanit.okut = { ok: true, mukerrer: false, card_no: '<img src=x id=xssImg onerror="window.__xss=1">', kart_id: 4242, tip_adi: '<b id=xssB>K</b>', sayac: SAYAC(5) };
        await okut(page, '700000004', 300);
        const xss = await page.evaluate(() => ({ img: !!document.getElementById('xssImg'), b: !!document.getElementById('xssB'), f: !!window.__xss }));
        ok('kart no / tip HTML olarak yorumlanmadı (textContent)', !xss.img && !xss.b && !xss.f, JSON.stringify(xss));

        // ağ hatası
        durum.yanit.okut = 'abort';
        await okut(page, '700000005', 300);
        s = await sonucOku(page);
        ok('ağ hatası (abort) → kırmızı "Bağlantı hatası"', !s.gizli && /tk-sonuc-hata/.test(s.sinif) && /Bağlantı hatası/.test(s.metin), JSON.stringify(s));
        // CSRF 403 {ok:false, error}
        durum.yanit.okut = { __status: 403, __body: { ok: false, error: 'Güvenlik doğrulaması başarısız (CSRF).' } };
        await okut(page, '700000006', 300);
        s = await sonucOku(page);
        ok('CSRF 403 {ok:false,error} → kırmızı + "error" metni', !s.gizli && /tk-sonuc-hata/.test(s.sinif) && /CSRF/.test(s.metin) && !/undefined/.test(s.metin), JSON.stringify(s));
        // bozuk (JSON olmayan) yanıt
        durum.yanit.okut = { __status: 500, __body: '<b>Fatal error</b>' };
        await okut(page, '700000007', 300);
        s = await sonucOku(page);
        ok('JSON olmayan 500 yanıt → kırmızı "okunamadı (500)"', /tk-sonuc-hata/.test(s.sinif) && /500/.test(s.metin), JSON.stringify(s));

        // hızlı art arda 3 farklı UID → kuyruk
        durum.yanit.okut = g => ({ ok: true, mukerrer: false, card_no: 'K' + String(g.ham_uid).slice(-3), kart_id: 2000 + (+String(g.ham_uid).slice(-3)), tip_adi: 'Erkek', sayac: SAYAC(7) });
        durum.gecikme = { okut: 180 };
        durum.istekler.length = 0; durum.maxUcus = 0;
        for (const u of ['700000011', '700000012', '700000013']) await okut(page, u, 0);
        await page.waitForTimeout(1200);
        o = istek(durum, 'okut');
        ok('hızlı 3 farklı UID → 3 POST, okutma sırasıyla', o.length === 3 && o.map(x => x.govde.ham_uid).join(',') === '700000011,700000012,700000013', JSON.stringify(o.map(x => x.govde.ham_uid)));
        ok('kuyruk: aynı anda en çok 1 okut isteği uçuşta (SIRALI)', durum.maxUcus === 1, 'maxUcus=' + durum.maxUcus);
        const c3 = await page.evaluate(() => ['2011', '2012', '2013'].map(k => !!document.querySelector('#tkKalanListe [data-kart="' + k + '"]')));
        ok('3 okutmanın 3 çipi de listede (hiçbiri kaybolmadı)', c3.every(Boolean), JSON.stringify(c3));
        durum.gecikme = {};

        // 350 ms debounce (Enter'sız okuyucu)
        durum.istekler.length = 0;
        durum.yanit.okut = { ok: true, mukerrer: false, card_no: 'K021', kart_id: 3021, tip_adi: 'Kadın', sayac: SAYAC(8) };
        await page.focus('#tkScanInput');
        await page.keyboard.type('700000021');
        await page.waitForTimeout(150);
        const erken = istek(durum, 'okut').length;
        await page.waitForTimeout(500);
        o = istek(durum, 'okut');
        ok('Enter\'sız okutma: 350 ms sessizlikten sonra tek POST (önce değil)', erken === 0 && o.length === 1 && o[0].govde.ham_uid === '700000021', `erken=${erken} ${JSON.stringify(o.map(x => x.govde))}`);
        durum.istekler.length = 0;
        await page.focus('#tkScanInput');
        await page.keyboard.type('70a0b000022');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(300);
        o = istek(durum, 'okut');
        ok('rakam dışı karakterler ayıklanır (ham_uid yalnız rakam)', o.length === 1 && o[0].govde.ham_uid === '700000022', JSON.stringify(o.map(x => x.govde)));

        // çipteki × → karar kalan_sil
        durum.istekler.length = 0;
        await page.click('#tkKalanListe [data-kart="2011"] .tk-cip-x');
        await page.waitForTimeout(300);
        const k = istek(durum, 'karar');
        ok('çip × → POST karar {sayim_id, kart_id:2011, karar:kalan_sil}', k.length === 1 && k[0].govde.kart_id === 2011 && k[0].govde.karar === 'kalan_sil' && k[0].govde.sayim_id === 1, JSON.stringify(k.map(x => x.govde)));
        ok('başarıda çip listeden kalktı', !(await page.$('#tkKalanListe [data-kart="2011"]')));
        // sunucunun bastığı çip (delegeli data-tk-karar) → tek karar isteği
        const sunucuCip = await page.$eval('#tkKalanListe .tk-cip-x[data-tk-karar="kalan_sil"]', b => +b.getAttribute('data-kart'));
        durum.istekler.length = 0;
        await page.click(`#tkKalanListe .tk-cip-x[data-tk-karar="kalan_sil"][data-kart="${sunucuCip}"]`);
        await page.waitForTimeout(300);
        const k2 = istek(durum, 'karar');
        ok(`sunucu çipi × (kart ${sunucuCip}) → TEK karar isteği, çip kalktı`, k2.length === 1 && k2[0].govde.kart_id === sunucuCip && k2[0].govde.karar === 'kalan_sil' && !(await page.$(`#tkKalanListe .tk-cip[data-kart="${sunucuCip}"]`)), JSON.stringify(k2.map(x => x.govde)));
        ok('JS hatası yok (okutma akışı)', durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();
    }

    // ===================================================================
    // 3) Önizleme + onay
    // ===================================================================
    console.log('\n=== 3) Önizleme + onay ===');
    {
        const durum = varsayilan();
        durum.yanit.onizle = ONIZ_HATA;
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: 390, height: 844 }, durum);
        await page.waitForTimeout(200);
        const on = istek(durum, 'onizle');
        ok('sayfa açılınca onizle POST\'u gitti', on.length >= 1, JSON.stringify(durum.istekler.map(x => x.uc)));
        const og = on[0] ? on[0].govde : {};
        ok('onizle gövdesi: sayim_id + varsayılan giriş saati (22:00 render) + neden/beyan',
            og.sayim_id === 1 && /^\d\d:\d\d$/.test(og.giris_saati) && 'neden' in og && 'beyan' in og && !('foreman_id' in og), JSON.stringify(og));
        const varsSaat = await page.inputValue('#tkGirisSaati');
        const h1 = await page.evaluate(() => {
            const b = document.getElementById('tkOnayBtn'), li = document.querySelector('#tkOnizleme .tk-hata-liste li');
            return { dis: b.disabled, liste: !!li, li: li ? li.textContent : '', kayip: li ? [...li.querySelectorAll('button')].map(x => x.textContent) : [],
                     uyari: document.querySelector('#tkOnizleme .tk-kutu-uyari') ? document.querySelector('#tkOnizleme .tk-kutu-uyari').textContent : '' };
        });
        ok('hatalı önizleme → #tkOnayBtn DISABLED', h1.dis, JSON.stringify(h1));
        ok('hata listesi görünür, K055 + sebep', h1.liste && (await gorunur(page, '#tkOnizleme .tk-hata-liste')) && /K055/.test(h1.li) && /dönmedi/.test(h1.li), JSON.stringify(h1));
        ok('dönmemiş satırda "Kayıp" ve "Bugün dağıtıldı" düğmeleri', h1.kayip.includes('Kayıp') && h1.kayip.includes('Bugün dağıtıldı'), JSON.stringify(h1));
        ok('uyarılar turuncu kutuda', /Beyan sayısı tutmuyor/.test(h1.uyari), JSON.stringify(h1));
        // "Bugün dağıtıldı" → karar dagitildi → sayfa yenilenir
        durum.istekler.length = 0;
        await page.click('#tkOnizleme button[data-tk-karar="dagitildi"]');
        await page.waitForTimeout(500);
        const kd = istek(durum, 'karar');
        ok('"Bugün dağıtıldı" → POST karar {kart_id:55, karar:dagitildi}', kd.length === 1 && kd[0].govde.kart_id === 55 && kd[0].govde.karar === 'dagitildi', JSON.stringify(kd.map(x => x.govde)));
        await page.waitForTimeout(300);
        // "Kayıp" → dialog → onay → karar kayip
        durum.istekler.length = 0;
        await page.click('#tkOnizleme button[data-tk-karar="kayip"]');
        await page.waitForTimeout(250);
        const dlgA = await page.evaluate(() => ({ open: document.getElementById('tkDlg').open, b: document.querySelector('[data-tk-dlg-baslik]').textContent }));
        ok('"Kayıp" → onay penceresi açıldı (karar POST\'u henüz YOK)', dlgA.open && /K055/.test(dlgA.b) && istek(durum, 'karar').length === 0, JSON.stringify(dlgA));
        await page.fill('[data-tk-dlg-gerekce]', 'kayboldu');
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(400);
        const kk = istek(durum, 'karar');
        ok('pencere Onayla → POST karar {kart_id:55, karar:kayip, not}', kk.length === 1 && kk[0].govde.karar === 'kayip' && kk[0].govde.kart_id === 55 && kk[0].govde.not === 'kayboldu', JSON.stringify(kk.map(x => x.govde)));
        ok('JS hatası yok (hatalı önizleme)', durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();

        // OK önizleme
        const d2 = varsayilan();
        d2.gecikme = { onayla: 600 };
        d2.yanit.onayla = { ok: true, sayim_id: 1, dagitilan: 16 };
        const s2 = await sayfaAc(browser, HTML.sayim, { width: 1280, height: 900 }, d2);
        await s2.page.waitForTimeout(200);
        let b = await s2.page.evaluate(() => { const x = document.getElementById('tkOnayBtn'); return { dis: x.disabled, t: x.textContent, neden: !document.getElementById('tkNedenAlan').hidden }; });
        ok('ok önizleme → #tkOnayBtn ETKİN', !b.dis, JSON.stringify(b));
        ok(`buton metni kişi sayısı (16) + saat (${varsSaat}) içeriyor`, /16 kişi/.test(b.t) && b.t.includes('(' + varsSaat + ')'), JSON.stringify(b));
        ok('varsayılan saatte #tkNedenAlan GİZLİ', !b.neden, JSON.stringify(b));
        const yeniSaat = varsSaat === '07:30' ? '08:15' : '07:30';
        d2.istekler.length = 0;
        await s2.page.fill('#tkGirisSaati', yeniSaat);
        await s2.page.waitForTimeout(700);
        b = await s2.page.evaluate(() => { const x = document.getElementById('tkOnayBtn'); return { dis: x.disabled, t: x.textContent, neden: !document.getElementById('tkNedenAlan').hidden }; });
        const on2 = istek(d2, 'onizle');
        ok('saat değişince yeniden onizle (gecikmeli, yeni saatle)', on2.length >= 1 && on2[on2.length - 1].govde.giris_saati === yeniSaat, JSON.stringify(on2.map(x => x.govde)));
        ok('saat varsayılandan farklı → #tkNedenAlan GÖRÜNÜR', b.neden && (await gorunur(s2.page, '#tkNeden')), JSON.stringify(b));
        ok('buton metni yeni saati gösteriyor', b.t.includes('(' + yeniSaat + ')') && !b.dis, JSON.stringify(b));
        await s2.page.fill('#tkGirisSaati', varsSaat);
        await s2.page.waitForTimeout(700);
        ok('saat varsayılana dönünce #tkNedenAlan yeniden gizli', await s2.page.evaluate(() => document.getElementById('tkNedenAlan').hidden));
        await s2.page.fill('#tkGirisSaati', yeniSaat);
        await s2.page.waitForTimeout(700);
        await s2.page.fill('#tkNeden', 'servis geç geldi');
        await s2.page.waitForTimeout(700);
        // Onayla — çift tıklama
        d2.istekler.length = 0;
        await s2.page.locator('#tkOnayBtn').dblclick();
        await s2.page.waitForTimeout(150);
        b = await s2.page.evaluate(() => { const x = document.getElementById('tkOnayBtn'); return x ? { dis: x.disabled, t: x.textContent } : null; });
        ok('Onayla tıklanınca buton hemen DISABLED + "Kaydediliyor"', b && b.dis && /Kaydediliyor/.test(b.t), JSON.stringify(b));
        await s2.page.waitForTimeout(900);
        const oy = istek(d2, 'onayla');
        ok('çift tıklamada YALNIZ 1 onayla POST\'u', oy.length === 1, 'adet=' + oy.length);
        const og2 = oy[0] ? oy[0].govde : {};
        ok('onayla gövdesi: istek_id 32 küçük hex', /^[0-9a-f]{32}$/.test(og2.istek_id || ''), JSON.stringify(og2));
        ok('onayla gövdesi: {csrf, sayim_id:1, giris_saati(yeni), neden, beyan, istek_id} — çavuş YOK',
            anahtarlar(og2) === JSON.stringify(['beyan', 'csrf', 'giris_saati', 'istek_id', 'neden', 'sayim_id']) && og2.sayim_id === 1 && og2.giris_saati === yeniSaat && og2.neden === 'servis geç geldi', JSON.stringify(og2));
        ok('başarıda ters_kart_sayim.php?cavus=1&onay=1 adresine gidildi', d2.gezinme === 'ters_kart_sayim.php?cavus=1&onay=1', String(d2.gezinme));
        ok('JS hatası yok (onay)', d2.hatalar.length === 0, d2.hatalar.join(' | '));
        await s2.ctx.close();

        // Onay hatası → buton metni geri, hata gösterilir, aynı istek_id ile tekrar
        const d3 = varsayilan();
        d3.yanit.onayla = { ok: false, kod: 'eszamanli', hata: 'Eşzamanlı işlem — tekrar deneyin.' };
        const s3 = await sayfaAc(browser, HTML.sayim, { width: 390, height: 844 }, d3);
        await s3.page.waitForTimeout(200);
        await s3.page.click('#tkOnayBtn');
        await s3.page.waitForTimeout(900);
        const r3 = await s3.page.evaluate(() => ({ t: document.getElementById('tkOnayBtn').textContent, dis: document.getElementById('tkOnayBtn').disabled }));
        const s3s = await sonucOku(s3.page);
        ok('onay hatası → kırmızı mesaj, buton yeniden önizlemeyle etkin', /tk-sonuc-hata/.test(s3s.sinif) && /Eşzamanlı/.test(s3s.metin) && !r3.dis && !/Kaydediliyor/.test(r3.t), JSON.stringify([s3s, r3]));
        await s3.page.click('#tkOnayBtn');
        await s3.page.waitForTimeout(600);
        const oy3 = istek(d3, 'onayla');
        ok('ikinci deneme AYNI istek_id ile (tekrar gönderim koruması)', oy3.length === 2 && oy3[0].govde.istek_id === oy3[1].govde.istek_id, JSON.stringify(oy3.map(x => x.govde.istek_id)));
        await s3.ctx.close();
    }

    // ===================================================================
    // 4) Onaylı sayım — Geri Al
    // ===================================================================
    console.log('\n=== 4) Onaylı sayım — Geri Al ===');
    for (const ekran of [EKRANLAR[0], EKRANLAR[2]]) {
        const durum = varsayilan();
        durum.yanit.geri_al = { ok: true };
        const { ctx, page } = await sayfaAc(browser, HTML.sayim_onayli, { width: ekran.width, height: ekran.height }, durum);
        ok(`${ekran.width}px onaylı sayfada okut/onizle isteği GİTMEDİ`, istek(durum, 'okut').length === 0 && istek(durum, 'onizle').length === 0, JSON.stringify(durum.istekler.map(x => x.uc)));
        await page.click('#tkGeriAlBtn');
        await page.waitForTimeout(250);
        const d = await page.evaluate(() => { const g = document.getElementById('tkDlg'); const r = g.getBoundingClientRect();
            return { open: g.open, alan: !g.querySelector('[data-tk-dlg-alan]').hidden, sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight }; });
        ok(`${ekran.width}px #tkGeriAlBtn → dialog#tkDlg açıldı, gerekçe alanı görünür, ekran içinde`, d.open && d.alan && d.sol >= 0 && d.sag <= d.vw && d.ust >= 0 && d.alt <= d.vh, JSON.stringify(d));
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(250);
        const h = await page.evaluate(() => { const e = document.querySelector('[data-tk-dlg-hata]'); return { gizli: e.hidden, t: e.textContent, open: document.getElementById('tkDlg').open }; });
        ok(`${ekran.width}px gerekçe boş → "Gerekçe zorunludur." ve pencere açık`, !h.gizli && h.t === 'Gerekçe zorunludur.' && h.open && (await gorunur(page, '[data-tk-dlg-hata]')), JSON.stringify(h));
        ok(`${ekran.width}px gerekçe boş → geri_al POST'u GİTMEDİ`, istek(durum, 'geri_al').length === 0);
        await page.fill('[data-tk-dlg-gerekce]', 'yanlış çavuş seçildi');
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(400);
        const ga = istek(durum, 'geri_al');
        ok(`${ekran.width}px gerekçeyle → POST geri_al {csrf, sayim_id:2, gerekce}`, ga.length === 1 && anahtarlar(ga[0].govde) === JSON.stringify(['csrf', 'gerekce', 'sayim_id']) && ga[0].govde.sayim_id === 2 && ga[0].govde.gerekce === 'yanlış çavuş seçildi', JSON.stringify(ga.map(x => x.govde)));
        ok(`${ekran.width}px başarıda ters_kart_sayim.php?cavus=2'ye gidildi`, durum.gezinme === 'ters_kart_sayim.php?cavus=2', String(durum.gezinme));
        ok(`${ekran.width}px JS hatası yok (geri al)`, durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();
    }
    {   // Vazgeç + sunucu hatası
        const durum = varsayilan();
        durum.yanit.geri_al = { ok: false, kod: 'cikis_var', hata: 'Çıkış okutulmaya başlandı — geri alınamaz.' };
        const { ctx, page } = await sayfaAc(browser, HTML.sayim_onayli, { width: 390, height: 844 }, durum);
        await page.click('#tkGeriAlBtn'); await page.waitForTimeout(200);
        await page.click('[data-tk-dlg-vazgec]'); await page.waitForTimeout(200);
        ok('Vazgeç → pencere kapandı, POST yok', !(await page.evaluate(() => document.getElementById('tkDlg').open)) && istek(durum, 'geri_al').length === 0);
        await page.click('#tkGeriAlBtn'); await page.waitForTimeout(200);
        const hata2 = await page.evaluate(() => ({ gizli: document.querySelector('[data-tk-dlg-hata]').hidden, ta: document.querySelector('[data-tk-dlg-gerekce]').value }));
        ok('yeniden açılınca önceki hata/gerekçe temizlenmiş', hata2.gizli && hata2.ta === '', JSON.stringify(hata2));
        await page.fill('[data-tk-dlg-gerekce]', 'deneme');
        await page.click('[data-tk-dlg-tamam]'); await page.waitForTimeout(400);
        const s = await sonucOku(page);
        ok('sunucu reddi → #tkSonuc kırmızı + metin, sayfa yerinde', /tk-sonuc-hata/.test(s.sinif) && /geri alınamaz/.test(s.metin) && !durum.gezinme, JSON.stringify(s));
        await ctx.close();
    }

    // ===================================================================
    // 4b) v363 — Manuel sayım paneli (taslak) + onaylı manuel görünüm
    // ===================================================================
    console.log('\n=== 4b) Manuel sayım ===');
    const MANUEL_ONIZ = g => {
        const k = String(g.kalan || '');
        if (k === '') return { ok: true, degerlendirme: { ok: false, genel: [], uyarilar: [], ozet: { havuz_aktif: 19, kalan: null, dagitilan: null, zaten_iceride: 0, manuel_limit: null, haric: 1 } } };
        const n = 19 - (+k);
        return { ok: true, degerlendirme: { ok: n > 0, genel: n > 0 ? [] : ['Dağıtılmış kart yok.'], uyarilar: ['1 kart önceki günden dönmedi (K099) — elde değilse Kayıp işaretleyin.'],
            ozet: { havuz_aktif: 19, kalan: +k, dagitilan: n, zaten_iceride: 0, manuel_limit: n, haric: 1 } } };
    };
    for (const ekran of [EKRANLAR[0], EKRANLAR[2]]) {
        const durum = varsayilan();
        durum.yanit.manuel_onizle = MANUEL_ONIZ;
        durum.yanit.manuel_onayla = { ok: true, sayim_id: 1, ozet: { dagitilan: 14 } };
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: ekran.width, height: ekran.height }, durum);
        ok(`${ekran.width}px yöntem düğmeleri var, açılışta "Kartla Say" seçili, manuel panel gizli`,
            (await gorunur(page, '[data-tk-yontem="kart"]')) && (await gorunur(page, '[data-tk-yontem="manuel"]'))
            && (await page.getAttribute('[data-tk-yontem="kart"]', 'aria-pressed')) === 'true' && !(await gorunur(page, '#tkManuelKalan')) && (await gorunur(page, '#tkOnayBtn')));
        await page.click('[data-tk-yontem="manuel"]');
        await page.waitForTimeout(250);
        const st = await page.evaluate(() => { const i = document.getElementById('tkManuelKalan'); const r = i.getBoundingClientRect();
            return { fs: parseFloat(getComputedStyle(i).fontSize), w: r.width, h: r.height, sol: r.left, sag: r.right, vw: innerWidth, im: i.getAttribute('inputmode'),
                     kart: !!document.querySelector('[data-tk-yontem-panel="kart"]').hidden, odak: document.activeElement === i,
                     basili: document.querySelector('[data-tk-yontem="manuel"]').getAttribute('aria-pressed') }; });
        ok(`${ekran.width}px "Manuel Sayı Gir" → manuel panel görünür, kart paneli gizli, aria-pressed`, (await gorunur(page, '#tkManuelKalan')) && st.kart && st.basili === 'true', JSON.stringify(st));
        ok(`${ekran.width}px "Elde kalan kart" kutusu ekran içinde, ≥16px yazı, inputmode=numeric, odakta`, st.sol >= 0 && st.sag <= st.vw && st.fs >= 16 && st.h >= 44 && st.im === 'numeric' && st.odak, JSON.stringify(st));
        const t = await tasma(page);
        ok(`${ekran.width}px manuel panel: yatay taşma yok`, t.sw <= t.vw + 1, JSON.stringify(t));
        // Kartla sayım okuyucusu manuel modda DURUR — USB okutması POST üretmez
        durum.istekler.length = 0;
        await page.evaluate(() => { const i = document.getElementById('tkScanInput'); i.value = '700000009'; i.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); });
        await page.waitForTimeout(300);
        ok(`${ekran.width}px manuel modda kart okutması POST okut GÖNDERMEZ`, istek(durum, 'okut').length === 0, JSON.stringify(durum.istekler.map(x => x.uc)));
        await page.fill('#tkManuelKalan', '5');
        await page.waitForTimeout(700);
        const canli = await page.textContent('[data-tk-manuel-calisan]');
        ok(`${ekran.width}px kalan 5 yazıldı → "→ 14 kişi çalışacak" (havuz 19 − 5)`, canli.trim() === '14', canli);
        const mo = istek(durum, 'manuel_onizle');
        const so = mo[mo.length - 1];
        ok(`${ekran.width}px POST manuel_onizle {csrf, sayim_id, kalan:"5", giris_saati, neden, beyan}`, !!so && so.govde.kalan === '5' && so.govde.sayim_id === 1
            && anahtarlar(so.govde) === JSON.stringify(['beyan', 'csrf', 'giris_saati', 'kalan', 'neden', 'sayim_id']), JSON.stringify(mo.map(x => x.govde)));
        const oz = await page.evaluate(() => ({ t: document.getElementById('tkManuelOnizleme').textContent, btn: document.getElementById('tkManuelOnayBtn').disabled, bt: document.getElementById('tkManuelOnayBtn').textContent }));
        ok(`${ekran.width}px önizleme: "14 kişi çalışıyor" + uyarı; Onayla AKTİF`, /14 kişi çalışıyor/.test(oz.t) && /dönmedi/.test(oz.t) && !oz.btn && /14 kişi/.test(oz.bt), JSON.stringify(oz));
        await page.fill('#tkManuelKalan', '19');
        await page.waitForTimeout(700);
        const oz2 = await page.evaluate(() => ({ t: document.getElementById('tkManuelOnizleme').textContent, btn: document.getElementById('tkManuelOnayBtn').disabled }));
        ok(`${ekran.width}px kalan 19 (= havuz) → hata + Onayla PASİF`, /Dağıtılmış kart yok/.test(oz2.t) && oz2.btn, JSON.stringify(oz2));
        await page.fill('#tkManuelKalan', '5');
        await page.waitForTimeout(700);
        durum.istekler.length = 0;
        await page.click('#tkManuelOnayBtn');
        await page.waitForTimeout(250);
        const dl = await page.evaluate(() => { const g = document.getElementById('tkDlg'); const r = g.getBoundingClientRect(); return { open: g.open, m: g.querySelector('[data-tk-dlg-metin]').textContent, sag: r.right, vw: innerWidth }; });
        ok(`${ekran.width}px Onayla → onay penceresi (14 kişi, en fazla 14 çıkış), ekran içinde`, dl.open && /14 kişi/.test(dl.m) && /en fazla 14/.test(dl.m) && dl.sag <= dl.vw, JSON.stringify(dl));
        ok(`${ekran.width}px pencere onaylanmadan manuel_onayla GİTMEDİ`, istek(durum, 'manuel_onayla').length === 0);
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(400);
        const mon = istek(durum, 'manuel_onayla');
        ok(`${ekran.width}px POST manuel_onayla {kalan:"5", istek_id(32 hex)} → ?cavus=1&onay=1`, mon.length === 1 && mon[0].govde.kalan === '5' && /^[a-f0-9]{32}$/.test(mon[0].govde.istek_id || '')
            && durum.gezinme === 'ters_kart_sayim.php?cavus=1&onay=1', JSON.stringify(mon.map(x => x.govde)) + ' ' + durum.gezinme);
        ok(`${ekran.width}px JS hatası yok (manuel)`, durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();
    }
    {   // geri dönüş: Kartla Say yeniden okutmayı açar
        const durum = varsayilan();
        durum.yanit.manuel_onizle = MANUEL_ONIZ;
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: 390, height: 844 }, durum);
        await page.click('[data-tk-yontem="manuel"]'); await page.waitForTimeout(150);
        await page.click('[data-tk-yontem="kart"]'); await page.waitForTimeout(150);
        durum.istekler.length = 0;
        await okut(page, '700000008', 350);
        ok('Kartla Say\'a dönünce okutma yeniden POST okut gönderir', istek(durum, 'okut').length === 1 && !(await gorunur(page, '#tkManuelKalan')), JSON.stringify(durum.istekler.map(x => x.uc)));
        await ctx.close();
    }
    for (const ekran of [EKRANLAR[0], EKRANLAR[2]]) {   // onaylı manuel sayım görünümü
        const durum = varsayilan();
        durum.yanit.geri_al = { ok: false, kod: 'geri_alinamaz', hata: 'Çıkış okutulmaya başlandı.' };
        const { ctx, page } = await sayfaAc(browser, HTML.sayim_manuel_onayli, { width: ekran.width, height: ekran.height }, durum);
        const g = await page.evaluate(() => ({ t: document.querySelector('.tk-onayli').textContent, sayac: [...document.querySelectorAll('.tk-sayac')].map(e => e.textContent.replace(/\s+/g, ' ').trim()),
            geri: !!document.getElementById('tkGeriAlBtn'), yontem: document.querySelectorAll('[data-tk-yontem]').length, cip: document.querySelectorAll('.tk-cip-dagitilan').length }));
        ok(`${ekran.width}px onaylı manuel: "Manuel · 6 kişi çalışıyor", sayaçlar 6 / 2 çıkış / 4 okutulmayan`, /Manuel · 6 kişi çalışıyor/.test(g.t) && g.sayac.some(x => /^6 ?Çalışan/.test(x)) && g.sayac.some(x => /^2 ?Çıkış yapan/.test(x)) && g.sayac.some(x => /^4 ?Çıkışı okutulmayan/.test(x)), JSON.stringify(g));
        ok(`${ekran.width}px çıkış başladığı için Geri Al düğmesi YOK, yöntem seçimi YOK, 2 çıkış çipi`, !g.geri && g.yontem === 0 && g.cip === 2, JSON.stringify(g));
        ok(`${ekran.width}px onaylı manuel: okut/onizle isteği GİTMEDİ, JS hatası yok`, durum.istekler.length === 0 && durum.hatalar.length === 0, JSON.stringify(durum.istekler) + durum.hatalar.join(' | '));
        await ctx.close();
    }

    // ===================================================================
    // 5) Hızlı çıkış → v355'ten beri Ortak Çıkış (gunluk_isci_giris_cikis.php?mod=ortak).
    //    Kiosk tarafı scripts/pdks_ortak_cikis_smoke.js'te test edilir.
    // ===================================================================
    {   // NFC — sayım sayfasında da
        const durum = varsayilan();
        const { ctx, page } = await sayfaAc(browser, HTML.sayim, { width: 390, height: 844 }, durum, { nfc: true });
        await page.click('#tkNfcBtn'); await page.waitForTimeout(150);
        durum.istekler.length = 0;
        await page.evaluate(() => window.__ndef.l.reading({ serialNumber: 'aa:bb:cc:dd' }));
        await page.waitForTimeout(350);
        const o = istek(durum, 'okut');
        ok('sayım: NFC okuma → POST okut {kaynak: web_nfc}', o.length === 1 && o[0].govde.kaynak === 'web_nfc' && o[0].govde.ham_uid === 'aa:bb:cc:dd', JSON.stringify(o.map(x => x.govde)));
        await ctx.close();
    }

    // ===================================================================
    // 6) Kart havuzu
    // ===================================================================
    console.log('\n=== 6) Kart havuzu ===');
    for (const ekran of [EKRANLAR[0], EKRANLAR[2]]) {
        const durum = varsayilan();
        durum.yanit.islem = { ok: true };
        const { ctx, page } = await sayfaAc(browser, HTML.havuz, { width: ekran.width, height: ekran.height }, durum);
        const tipDurum = () => page.evaluate(() => [...document.querySelectorAll('[data-tk-tip]')].map(b => ({ id: +b.dataset.tkTip, p: b.getAttribute('aria-pressed'), a: b.classList.contains('aktif'), t: b.textContent.trim() })));
        let td = await tipDurum();
        ok(`${ekran.width}px açılışta ilk tip seçili (aria-pressed=true), diğerleri false`, td.length >= 2 && td[0].p === 'true' && td[0].a && td.slice(1).every(x => x.p === 'false' && !x.a), JSON.stringify(td));
        const ikinci = td[1];
        await page.click(`[data-tk-tip="${ikinci.id}"]`);
        await page.waitForTimeout(100);
        td = await tipDurum();
        ok(`${ekran.width}px "${ikinci.t}" tıklandı → aria-pressed yalnız onda true`, td.filter(x => x.p === 'true').length === 1 && td[1].p === 'true' && td[1].a && td[0].p === 'false', JSON.stringify(td));
        ok(`${ekran.width}px #tkTipDurum seçili tipi yazıyor`, (await page.textContent('#tkTipDurum')).includes(ikinci.t));
        durum.istekler.length = 0;
        durum.yanit.ekle = { ok: true, sonuc: 'eklendi', yeni_kart: true, card_no: 'K050', tip_adi: ikinci.t, ozet: { toplam: 21, aktif: 20, kayip: 0, bozuk: 1, kullanim_disi: 0 } };
        await okut(page, '700000050', 350);
        const e = istek(durum, 'ekle');
        const g = e[0] ? e[0].govde : {};
        ok(`${ekran.width}px okutma → POST ekle, worker_type_id = seçili tip (${ikinci.id})`, e.length === 1 && g.worker_type_id === ikinci.id && g.foreman_id === 1 && g.ham_uid === '700000050' && g.kaynak === 'usb_decimal', JSON.stringify(g));
        ok(`${ekran.width}px ekle gövdesi {csrf, foreman_id, worker_type_id, ham_uid, kaynak}`, anahtarlar(g) === JSON.stringify(['csrf', 'foreman_id', 'ham_uid', 'kaynak', 'worker_type_id']), JSON.stringify(g));
        const ek = await page.evaluate(() => ({ toplam: document.querySelector('[data-tk-sayac="toplam"]').textContent, log: document.querySelector('#tkEkleLog li') ? document.querySelector('#tkEkleLog li').className + ' ' + document.querySelector('#tkEkleLog li').textContent : '', yenile: !document.getElementById('tkYenileBtn').hidden }));
        ok(`${ekran.width}px başarı → sayaç (21), log satırı (yeşil), "Listeyi yenile" görünür`, ek.toplam === '21' && /tk-log-ok/.test(ek.log) && /K050/.test(ek.log) && ek.yenile, JSON.stringify(ek));
        durum.yanit.ekle = { ok: false, kod: 'baska_cavus', hata: 'Kart başka çavuşun havuzunda.' };
        await okut(page, '700000051', 350);
        const s = await sonucOku(page);
        ok(`${ekran.width}px ekle hatası → kırmızı`, /tk-sonuc-hata/.test(s.sinif) && /başka çavuşun/.test(s.metin), JSON.stringify(s));

        // Aktar → dialog + #tkAktarAlan görünür
        const ilkSatir = '.tk-kart-liste .tk-kart-satir:first-child';
        const aktarBtn = page.locator(`${ilkSatir} [data-tk-islem="aktar"]`);
        const kartId = +(await aktarBtn.getAttribute('data-kart'));
        await aktarBtn.click();
        await page.waitForTimeout(250);
        let d = await page.evaluate(() => ({ open: document.getElementById('tkDlg').open, alan: !document.getElementById('tkAktarAlan').hidden, ger: !document.querySelector('[data-tk-dlg-alan]').hidden }));
        ok(`${ekran.width}px "Aktar" → dialog açık, #tkAktarAlan + gerekçe GÖRÜNÜR`, d.open && d.alan && d.ger && (await gorunur(page, '#tkAktarHedef')), JSON.stringify(d));
        const tasmaDlg = await page.evaluate(() => { const g = document.getElementById('tkDlg'); return { sw: g.scrollWidth, cw: g.clientWidth, r: g.getBoundingClientRect().right, vw: innerWidth }; });
        ok(`${ekran.width}px aktar penceresi ekran içinde, yatay taşma yok`, tasmaDlg.r <= tasmaDlg.vw && tasmaDlg.sw <= tasmaDlg.cw + 1, JSON.stringify(tasmaDlg));
        await page.click('[data-tk-dlg-vazgec]');
        await page.waitForTimeout(150);
        ok(`${ekran.width}px Vazgeç → #tkAktarAlan yeniden gizli`, await page.evaluate(() => document.getElementById('tkAktarAlan').hidden && !document.getElementById('tkDlg').open));
        // Kayıp → #tkAktarAlan gizli kalır
        await page.click(`${ilkSatir} [data-tk-islem="kayip"]`);
        await page.waitForTimeout(250);
        d = await page.evaluate(() => ({ open: document.getElementById('tkDlg').open, alan: !document.getElementById('tkAktarAlan').hidden }));
        ok(`${ekran.width}px "Kayıp" → dialog açık, #tkAktarAlan GİZLİ`, d.open && !d.alan && !(await gorunur(page, '#tkAktarHedef')), JSON.stringify(d));
        await page.click('[data-tk-dlg-vazgec]');
        await page.waitForTimeout(150);
        // Aktar tamamla → islem POST
        durum.istekler.length = 0;
        await aktarBtn.click();
        await page.waitForTimeout(200);
        const hedef = await page.$eval('#tkAktarHedef', s => s.options[s.options.length - 1].value);
        await page.selectOption('#tkAktarHedef', hedef);
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(200);
        ok(`${ekran.width}px aktar gerekçesiz → POST yok`, istek(durum, 'islem').length === 0);
        await page.fill('[data-tk-dlg-gerekce]', 'ekip değişti');
        await page.click('[data-tk-dlg-tamam]');
        await page.waitForTimeout(400);
        const is = istek(durum, 'islem');
        ok(`${ekran.width}px aktar → POST islem {islem:aktar, kart_id, hedef_foreman_id:${hedef}, gerekce}`, is.length === 1 && is[0].govde.islem === 'aktar' && is[0].govde.kart_id === kartId && is[0].govde.hedef_foreman_id === +hedef && is[0].govde.gerekce === 'ekip değişti' && is[0].govde.foreman_id === 1, JSON.stringify(is.map(x => x.govde)));
        ok(`${ekran.width}px JS hatası yok (havuz)`, durum.hatalar.length === 0, durum.hatalar.join(' | '));
        await ctx.close();
    }

    // ===================================================================
    // 7) Dokunmatik hedef boyları (390px)
    // ===================================================================
    console.log('\n=== 7) Dokunmatik hedefler (390px) ===');
    {
        const olcumler = [];
        for (const [html, secler] of [[HTML.sayim, ['.tk-sekme', '.tk-cip-x', '.tk-buyuk-btn']], [HTML.havuz, ['.tk-sekme', '.tk-tip-btn']], [HTML.sayim_secim, ['.tk-sekme', '.tk-cavus-kart']], [HTML.sayim_onayli, ['.tk-eylemler .btn']]]) {
            const durum = varsayilan();
            const { ctx, page } = await sayfaAc(browser, html, { width: 390, height: 844 }, durum);
            for (const sec of secler) {
                const r = await page.evaluate(s => [...document.querySelectorAll(s)].filter(e => e.getBoundingClientRect().width > 0).map(e => { const b = e.getBoundingClientRect(); return { h: Math.round(b.height * 10) / 10, w: Math.round(b.width * 10) / 10 }; }), sec);
                olcumler.push({ html, sec, r });
            }
            await ctx.close();
        }
        const esik = { '.tk-cip-x': 30 };
        const goruldu = new Set();
        for (const m of olcumler) {
            const min = esik[m.sec] || 36;
            const enKucukH = m.r.length ? Math.min(...m.r.map(x => x.h)) : null;
            const enKucukW = m.r.length ? Math.min(...m.r.map(x => x.w)) : null;
            bilgi(`${m.html.replace(/^_test_ters_|\.html$/g, '')} ${m.sec}: ${m.r.length} öğe, en küçük ${enKucukW}×${enKucukH}px`);
            if (m.sec === '.tk-cavus-kart' || m.sec === '.tk-eylemler .btn') { ok(`390px ${m.sec} yükseklik ≥ 36px`, m.r.length > 0 && enKucukH >= 36, JSON.stringify(m.r)); continue; }
            const anahtar = m.sec;
            if (goruldu.has(anahtar) && m.sec === '.tk-sekme') continue;
            goruldu.add(anahtar);
            ok(`390px ${m.sec} yükseklik ≥ ${min}px (ölçülen ${enKucukH})`, m.r.length > 0 && enKucukH >= min, JSON.stringify(m.r));
            if (m.sec === '.tk-cip-x') ok(`390px ${m.sec} genişlik ≥ ${min}px (ölçülen ${enKucukW})`, enKucukW >= min, JSON.stringify(m.r));
        }
    }

    // ===================================================================
    // 8) Kurulmamış hub (TK_KURULU=0)
    // ===================================================================
    console.log('\n=== 8) Kurulmamış hub ===');
    for (const ekran of EKRANLAR) {
        const durum = varsayilan();
        const { ctx, page } = await sayfaAc(browser, HTML.kurulmamis, { width: ekran.width, height: ekran.height }, durum);
        const k = await page.evaluate(() => ({ kutu: [...document.querySelectorAll('.tk-kutu')].map(e => e.textContent).join(' '), hub: document.querySelectorAll('.tk-hub-kart').length, cavus: document.querySelectorAll('.tk-cavus-kart').length }));
        ok(`${ekran.width}px "henüz kurulmamış" kutusu görünür`, /henüz kurulmamış/.test(k.kutu) && (await gorunur(page, '.tk-kutu-bilgi')), JSON.stringify(k));
        ok(`${ekran.width}px hub kartları ve çavuş kartları YOK`, k.hub === 0 && k.cavus === 0, JSON.stringify(k));
        ok(`${ekran.width}px migrate.php bağlantısı var`, !!(await page.$('.tk-kutu a[href="migrate.php"]')));
        await ctx.close();
    }
    {   // kurulu hub karşılaştırması
        const durum = varsayilan();
        const { ctx, page } = await sayfaAc(browser, HTML.hub, { width: 390, height: 844 }, durum);
        const n = await page.evaluate(() => ({ hub: document.querySelectorAll('.tk-hub-kart').length, kur: /henüz kurulmamış/.test(document.body.textContent) }));
        ok('kurulu hub: 4 hub kartı var, kurulmamış kutusu YOK', n.hub === 4 && !n.kur, JSON.stringify(n));
        await ctx.close();
    }

    await browser.close();
    sunucu.close();
    console.log(`\n${gecen} geçti, ${hata} HATA`);
    console.log(hata ? `${hata} HATA` : 'Tüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
