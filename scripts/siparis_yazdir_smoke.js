'use strict';
// Siparişler bölüm başlıkları — Yazdır / PDF Gönder (v382) + WP Resim (3 bölüm: İşlem Bekleyen / Evrak / Kapanan). Gerçek PHP render (siparis_ui_render.php) + gerçek tarayıcı (Playwright + Chromium).
// Canlı DB / ağ YOK: sayfalar bellek içi SQLite fixture'ından gelir; siparis_yazdir.php yanıtı page.route() ile SENTETİK JSON'dan
// (php scripts/siparis_yazdir_smoke.php --json / --json-buyuk) döner; jsPDF + html2canvas yerel halkayit/ dosyalarından sunulur.
// v394: ⋮ menüsü › 💬 WP Paylaş (siparis_paylas.php yanıtı page.route() ile, php scripts/siparis_yazdir_smoke.php --json-wp).
//   node scripts/siparis_yazdir_smoke.js          (Playwright yoksa kendini ATLAR)
const fs = require('fs'), os = require('os'), path = require('path');
const { spawnSync } = require('child_process');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }
const root = path.resolve(__dirname, '..');
const OUT = fs.mkdtempSync(path.join(os.tmpdir(), 'sip-yazdir-'));
let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) pass++; else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }

function php(args, env) {
    const r = spawnSync('php', args, { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: Object.assign({}, process.env, env || {}) });
    if (r.status !== 0) throw new Error('php ' + args.join(' ') + ' başarısız: ' + (r.stderr || r.stdout).slice(0, 800));
    return r.stdout;
}
const SERVE = {
    html2canvas: path.join(root, 'halkayit/html2canvas.min.js'),
    jspdf: path.join(root, 'halkayit/jspdf.umd.min.js'),
};
const pdfSayfa = buf => (buf.toString('latin1').match(/\/Type\s*\/Page\b(?!s)/g) || []).length;
let WP = null;   // --json-wp fixture'ı (hazırlıkta doldurulur)
const JSONY = (obj, status) => ({ status: status || 200, contentType: 'application/json; charset=utf-8', body: JSON.stringify(obj) });

(async () => {
    // ── hazırlık: sayfalar + JSON fixture'ları ──
    php(['scripts/siparis_ui_render.php'], { SIPARIS_UI_OUT: OUT, SIPARIS_UI_MAIL: 'x' });
    const KUCUK = JSON.parse(php(['scripts/siparis_yazdir_smoke.php', '--json']));
    const BUYUK = JSON.parse(php(['scripts/siparis_yazdir_smoke.php', '--json-buyuk']));
    WP = JSON.parse(php(['scripts/siparis_yazdir_smoke.php', '--json-wp']));
    // Sözleşme: 8 sabit sütun + DEĞİŞKEN sayıda "Kalibre N adet" (kal_*) + Toplam palet + Toplam kasa.
    const SABIT = 'tarih:Yükleme tarihi|parti:Parti no|siparis:Sipariş no|ulke:Alıcı ülke|tel:Şoför Tel|plaka:Plaka|gumruk:Gümrük|urun:Ürün bilgileri';
    const kolSozlesme = j => {
        const k = j.kolonlar, n = k.length;
        const kal = k.slice(8, n - 2);
        return k.slice(0, 8).map(x => x.k + ':' + x.l).join('|') === SABIT && kal.length >= 1 && kal.every(x => /^kal_/.test(x.k) && /^Kalibre \S+ adet$/.test(x.l) && x.t === 'sayi')
            && k[n - 2].k === 'palet' && k[n - 2].l === 'Toplam palet' && k[n - 2].t === 'sayi' && k[n - 1].k === 'kasa' && k[n - 1].l === 'Toplam kasa' && k[n - 1].t === 'sayi'
            && j.satirlar.every(r => r.h.length === n);
    };
    const KI = (j, k) => j.kolonlar.findIndex(x => x.k === k);
    const KALS = KUCUK.kolonlar.filter(x => /^kal_/.test(x.k));
    ok('fixture: sözleşme sütunları (8 sabit + ' + KALS.length + ' kalibre + palet/kasa), satır var; büyük JSON ≥ 300 satır', KUCUK.ok && kolSozlesme(KUCUK) && kolSozlesme(BUYUK) && KUCUK.satirlar.length >= 4 && BUYUK.satirlar.length >= 300,
        KUCUK.kolonlar.map(x => x.k + ':' + x.l).join('|'));
    ok('fixture: boş kalibre hücresi "-", toplam nesnesi kal_* anahtarlarını taşır', KUCUK.satirlar.some(r => KALS.some(c => r.h[KI(KUCUK, c.k)] === '-')) && KALS.every(c => c.k in KUCUK.toplam), JSON.stringify(KUCUK.toplam));

    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    // file:// sayfadaki fetch'i page.route() ile yakalamak TAM Chromium (yeni headless) ister: Playwright'ın
    // varsayılanı chromium-headless-shell file:// fetch'ini route'a uğramadan reddeder ("URL scheme file is not supported").
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : { channel: 'chromium' })
        .catch(() => chromium.launch({}));
    /** Sayfayı açar; siparis_yazdir.php yanıtı $yanit(route) ile; halkayit/*.js yerelden. Her istek $istekler'e yazılır. */
    async function ac(sayfa, w, h, yanit, opts) {
        opts = opts || {};
        const mobil = w < 768;
        const ctx = await browser.newContext(Object.assign({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', acceptDownloads: true }, opts.ctx || {}));
        await ctx.addInitScript(() => { window.__print = 0; window.print = () => { window.__print++; }; window.__alert = []; window.alert = m => { window.__alert.push(String(m)); }; });
        for (const i of [].concat(opts.init || [])) await ctx.addInitScript(i);
        const istekler = [];
        await ctx.route('**/siparis_yazdir.php*', route => { istekler.push(route.request().url()); return yanit(route); });
        await ctx.route('**/siparis_paylas.php*', route => { istekler.push(route.request().url()); return (opts.wp || (r => r.fulfill(JSONY(WP))))(route); });
        await ctx.route('**/halkayit/html2canvas.min.js', route => route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(SERVE.html2canvas) }));
        await ctx.route('**/halkayit/jspdf.umd.min.js', route => route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(SERVE.jspdf) }));
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        await page.waitForTimeout(400);
        return { ctx, page, hatalar, istekler };
    }
    const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const tamam = veri => route => route.fulfill(JSONY(veri));
    // Sayfada birden çok bölümde düğme var: DOM'daki İLK (İşlem Bekleyen) kullanılır.
    const BTN_Y = '[data-sip-yazdir="yazdir"] >> nth=0', BTN_P = '[data-sip-yazdir="pdf"] >> nth=0', BTN_R = '[data-sip-yazdir="resim"] >> nth=0';
    const BOLUMLER = [['#sipAcik', 'acik'], ['#sipEvrak', 'evrak'], ['#sipKapali', 'kapali']];
    /** Her bölüm başlığındaki düğmeler: { id: {n, etiket[], url[], tur[]} } */
    const bolumDugmeleri = (page, ids) => page.evaluate(ids => {
        const o = {};
        for (const id of ids) {
            const b = document.querySelector(id);
            if (!b) { o[id] = null; continue; }
            const d = Array.from(b.querySelectorAll('[data-sip-yazdir]'));
            o[id] = { n: d.length, tur: d.map(x => x.getAttribute('data-sip-yazdir')), etiket: d.map(x => x.textContent.trim()), url: d.map(x => x.getAttribute('data-sip-yazdir-url')) };
        }
        return o;
    }, ids);

    for (const [w, h] of [[1280, 900], [390, 844]]) {
        const mobil = w < 768;
        // ── düğmeler ──
        let { ctx, page, hatalar } = await ac('liste', w, h, tamam(KUCUK));
        const bd = await bolumDugmeleri(page, BOLUMLER.map(x => x[0]));
        const d = await page.evaluate(() => {
            const b = Array.from(document.querySelectorAll('[data-sip-yazdir]'));
            const bas = document.querySelector('#sipAcik');
            return { n: b.length, etiketler: b.slice(0, 3).map(x => x.textContent.trim()), url: b[0].getAttribute('data-sip-yazdir-url'),
                r: b.map(x => { const r = x.getBoundingClientRect(); return { x: r.x, r: r.right, h: r.height, w: r.width }; }), noPrint: !!bas.querySelector('.sip-bolum-eylem.no-print'),
                hepsiNoPrint: b.every(x => !!x.closest('.no-print')) };
        });
        for (const [id, bolum] of BOLUMLER) {
            const x = bd[id];
            ok(`[${w}] düğmeler: ${id} başlığında 3 düğme (Yazdır / PDF / WP Resim), URL bolum=${bolum}`, !!x && x.n === 3 && x.tur.join('|') === 'yazdir|pdf|resim' && /Yazdır/.test(x.etiket[0]) && /PDF/.test(x.etiket[1]) && x.etiket[2] === '🖼️ WP Resim'
                && x.url.every(u => new RegExp('[?&]bolum=' + bolum + '(&|$)').test(u)), JSON.stringify(x));
        }
        ok(`[${w}] düğmeler: toplam 9 (3 bölüm × 3)`, d.n === 9, String(d.n));
        ok(`[${w}] düğmeler: PDF etiketi masaüstü tarayıcıda "PDF İndir" (telefon UA'sında "PDF Gönder": aşağıda)`, d.etiketler[1].includes('PDF İndir'), d.etiketler[1]);
        ok(`[${w}] düğmeler: ekrana sığar, yatay taşma yok`, d.r.every(x => x.x >= 0 && x.r <= w + 1) && (await tasma(page)) <= 0, JSON.stringify(d.r) + ' / ' + (await tasma(page)));
        if (mobil) ok(`[${w}] düğmeler: dokunma hedefi ≥ 44px`, d.r.every(x => x.h >= 43.5), JSON.stringify(d.r));
        ok(`[${w}] düğme URL'i: siparis_yazdir.php, sayfa numaraları YOK`, /^siparis_yazdir\.php/.test(d.url) && !/acik_page|kapali_page|evrak_page/.test(d.url), d.url);
        ok(`[${w}] düğmeler: yazdırmada gizlenen kapta (no-print)`, d.noPrint && d.hepsiNoPrint);
        ok(`[${w}] liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        // ── Yazdır → popup ──
        let istekler;
        ({ ctx, page, istekler, hatalar } = await ac('liste', w, h, tamam(KUCUK)));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        await popup.waitForTimeout(500);
        const baslik = await popup.locator('.syz thead th').allTextContents();
        ok(`[${w}] yazdırma: sütunlar sunucu sırasıyla (8 sabit + kalibre + Toplam palet/kasa)`, baslik.join('|') === KUCUK.kolonlar.map(k => k.l).join('|') && baslik[0] === 'Yükleme tarihi' && baslik[baslik.length - 1] === 'Toplam kasa', baslik.join('|'));
        const IX = { urun: KI(KUCUK, 'urun'), palet: KI(KUCUK, 'palet'), kasa: KI(KUCUK, 'kasa'), kal: KALS.map(c => KI(KUCUK, c.k)), nw: ['tarih', 'siparis', 'tel'].map(k => KI(KUCUK, k)), sayi: KUCUK.kolonlar.map((k, i) => k.t === 'sayi' ? i : -1).filter(i => i >= 0) };
        const tbl = await popup.evaluate(IX => {
            const rows = Array.from(document.querySelectorAll('.syz tbody tr'));
            const sari = rows.filter(r => r.classList.contains('syz-gecmis'));
            const cs = e => getComputedStyle(e);
            const son = rows[rows.length - 1];
            const veri = rows.filter(r => !r.classList.contains('syz-toplam'));
            const ths = Array.from(document.querySelectorAll('.syz thead th'));
            return { n: rows.length, tfoot: document.querySelectorAll('.syz tfoot').length, sonToplam: son.classList.contains('syz-toplam'), sonIlk: son.children[0].textContent,
                sariSay: sari.length, sariBg: sari.length ? cs(sari[0].children[3]).backgroundColor : '', sariIlk: sari.length ? sari[0].children[0].textContent : '', tumSariTik: sari.every(r => /✓$/.test(r.children[0].textContent)),
                duzBg: (() => { const d = rows.find(r => !r.classList.contains('syz-gecmis') && !r.classList.contains('syz-toplam')); return d ? cs(d.children[3]).backgroundColor : ''; })(),
                bugunKalin: (() => { const b = document.querySelector('.syz tr.syz-bugun > td:first-child'); return b ? cs(b).fontWeight : null; })(),
                gunCizgi: document.querySelectorAll('.syz tr.syz-gun').length, tikOlmayan: rows.filter(r => !r.classList.contains('syz-gecmis') && !r.classList.contains('syz-toplam')).every(r => !/✓/.test(r.children[0].textContent)),
                colgroup: document.querySelectorAll('.syz col').length, urunBr: !!document.querySelector('.syz tbody td br'), anahtar: (document.querySelector('.syz-anahtar') || { textContent: '' }).textContent, meta: document.querySelector('.syz-meta').textContent,
                notlar: Array.from(document.querySelectorAll('.syz-not')).map(e => e.textContent), toplamPalet: son.children[IX.palet].textContent, toplamKasa: son.children[IX.kasa].textContent,
                toplamKal: IX.kal.map(i => son.children[i].textContent), kalTire: veri.some(r => IX.kal.some(i => r.children[i].textContent === '-')),
                nowrap: veri.every(r => IX.nw.every(i => cs(r.children[i]).whiteSpace === 'nowrap')), sag: veri.concat([son]).every(r => IX.sayi.every(i => cs(r.children[i]).textAlign === 'right')),
                thOrta: ths.every(t => cs(t).textAlign === 'center'), thKirilir: ths.every(t => cs(t).whiteSpace !== 'nowrap'),
                kalBaslikSatir: IX.kal.length ? Math.round(ths[IX.kal[0]].getBoundingClientRect().height / parseFloat(cs(ths[IX.kal[0]]).lineHeight || 12)) : 0,
                st: document.head.textContent, tasma: document.documentElement.scrollWidth - window.innerWidth, baslikH2: document.querySelector('.syz h2').textContent, title: document.title };
        }, IX);
        ok(`[${w}] yazdırma: satır sayısı = adet + TOPLAM; toplam satırı tbody SONUNDA, tfoot YOK`, tbl.n === KUCUK.adet + 1 && tbl.sonToplam && tbl.sonIlk === 'TOPLAM' && tbl.tfoot === 0, JSON.stringify(tbl));
        ok(`[${w}] yazdırma: geçmiş tarihli satırlar sarı (rgb(255, 243, 176)) + tarihte ✓; diğerlerinde sarı/✓ yok`, tbl.sariSay === KUCUK.gecmis_say && tbl.sariSay >= 3 && tbl.sariBg === 'rgb(255, 243, 176)' && tbl.tumSariTik && tbl.duzBg !== 'rgb(255, 243, 176)' && tbl.tikOlmayan, JSON.stringify(tbl));
        ok(`[${w}] yazdırma: bugünün tarihi kalın, gün değişiminde üst çizgi, çok satırlı hücre <br>`, tbl.bugunKalin === '700' && tbl.gunCizgi >= 2 && (tbl.urunBr || !KUCUK.satirlar.some(r => r.h.some(h => /\n/.test(h)))), JSON.stringify([tbl.bugunKalin, tbl.gunCizgi, tbl.urunBr]));
        ok(`[${w}] yazdırma: tarih/sipariş/tel nowrap (plaka kırılır); sayı sütunları (kalibre/palet/kasa) sağa; başlıklar ortalı ve kırılabilir`, tbl.nowrap && tbl.sag && tbl.thOrta && tbl.thKirilir, JSON.stringify([tbl.nowrap, tbl.sag, tbl.thOrta, tbl.thKirilir]));
        // v427 — parti no / plaka hücresinde her değer AYRI satır ve hiçbiri ortadan kırılmaz (görüntülenen satır sayısı = veri satır sayısı)
        const ppb = w < 1000 ? [] : await popup.evaluate(IXP => {
            const veri = Array.from(document.querySelectorAll('.syz tbody tr')).filter(r => !r.classList.contains('syz-toplam'));
            const say = (td) => { const lh = parseFloat(getComputedStyle(td).lineHeight) || 13; return Math.round((td.getBoundingClientRect().height - 6) / lh); };
            return veri.map((r, i) => IXP.map(x => ({ i, k: x, h: r.children[x].innerHTML, satir: say(r.children[x]) })));
        }, [KI(KUCUK, 'parti'), KI(KUCUK, 'plaka')]);
        const ppHatali = [];
        ppb.forEach((satir, i) => satir.forEach((c, j) => { const beklenen = (KUCUK.satirlar[i].h[c.k] || '').split('\n').length; if (c.h !== '' && c.satir > beklenen) ppHatali.push([i, c.k, c.h, c.satir, beklenen]); }));
        ok(`[${w}] yazdırma: parti no / plaka hücrelerinde değerler ayrı satırda, ortadan kırılmaz (v427; telefon genişliğinde popup ölçülmez — baskı A4 yatay)`, w < 1000 || ppHatali.length === 0 && KUCUK.satirlar.some(r => /\n/.test(r.h[KI(KUCUK, 'plaka')])), JSON.stringify(ppHatali.slice(0, 3)));
        ok(`[${w}] yazdırma: boş kalibre hücresi "-"; TOPLAM satırında her kalibre sütununun toplamı`, tbl.kalTire && JSON.stringify(tbl.toplamKal) === JSON.stringify(KALS.map(c => String(KUCUK.toplam[c.k] == null ? '' : KUCUK.toplam[c.k]))), JSON.stringify([tbl.toplamKal, KUCUK.toplam]));
        ok(`[${w}] yazdırma: toplam palet/kasa satırı + meta + anahtar + kişisel veri notu`, tbl.toplamPalet === KUCUK.toplam.palet && tbl.toplamKasa === KUCUK.toplam.kasa && /Sarı zemin ve ✓ = çıkış tarihi geçmiş/.test(tbl.anahtar) && /sevkiyat/.test(tbl.meta) && /Basım:/.test(tbl.meta)
            && /Geçmiş: /.test(tbl.meta) && tbl.notlar.length === (KUCUK.notlar || []).length && tbl.colgroup === KUCUK.kolonlar.length, JSON.stringify([tbl.toplamPalet, tbl.toplamKasa, tbl.anahtar, tbl.meta, tbl.notlar]));
        ok(`[${w}] yazdırma: @page A4 yatay + print-color-adjust + başlık`, /@page\{size:A4 landscape/.test(tbl.st) && /print-color-adjust:exact/.test(tbl.st) && tbl.baslikH2 === KUCUK.baslik && tbl.title === KUCUK.dosya && /-\d{2}\.\d{2}\.\d{4}$/.test(tbl.title), tbl.baslikH2 + ' / ' + tbl.title);
        ok(`[${w}] yazdırma: popup'ta yatay taşma yok (küçük veri)`, tbl.tasma <= 0, String(tbl.tasma));
        ok(`[${w}] yazdırma: print() kendiliğinden çağrıldı`, (await popup.evaluate(() => window.__print)) >= 1);
        ok(`[${w}] yazdırma: sunucu isteği amac=yazdir, taze fetch (istemcide önbellek yok)`, istekler.length === 1 && /amac=yazdir/.test(istekler[0]) && !/acik_page|kapali_page|evrak_page/.test(istekler[0]), istekler.join(','));
        await popup.close();
        await page.waitForTimeout(100);
        const [popup2] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup2.waitForSelector('#pdfIcerik', { timeout: 8000 });
        ok(`[${w}] yazdırma: her tıkta YENİ istek (2 tık = 2 istek)`, istekler.length === 2);
        await popup2.close();
        ok(`[${w}] yazdırma: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }

    // ── filtreli (tek durum) sayfa: Sonuçlar bölümünde düğme + filtre anahtarları isteğe taşınır ──
    {
        const { ctx, page, istekler } = await ac('liste_tek', 1280, 900, tamam(KUCUK));
        const n = await page.evaluate(() => { const b = Array.from(document.querySelectorAll('[data-sip-yazdir]')); const s = document.querySelector('#sipSonuclar'); return { n: b.length, hepsi: b.length > 0 && b.every(x => s.contains(x)), url: b[0] && b[0].getAttribute('data-sip-yazdir-url'), tur: b.map(x => x.getAttribute('data-sip-yazdir')).join('|') }; });
        ok('filtreli liste (tek durum): 3 düğme "Sonuçlar" başlığında; URL durum filtresini taşır, bolum YOK', n.n === 3 && n.hepsi && n.tur === 'yazdir|pdf|resim' && /durum=arac_bekleyen/.test(n.url) && !/bolum=/.test(n.url), JSON.stringify(n));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        ok('filtreli liste: istek filtre anahtarını + amac taşır', /durum=arac_bekleyen/.test(istekler[0]) && /amac=yazdir/.test(istekler[0]), istekler[0]);
        await ctx.close();
    }
    {   // v403: Evrak ve Kapanan bölümlerinde de 3 düğme; her bölümün düğmesi kendi bolum=…'unu ister
        let { ctx, page, istekler } = await ac('liste', 1280, 900, tamam(KUCUK));
        for (const [id, bolum] of BOLUMLER) {
            const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(id + ' [data-sip-yazdir="yazdir"]')]);
            await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
            await popup.close();
            ok(`bölüm ${id}: Yazdır isteği bolum=${bolum} + amac=yazdir`, new RegExp('[?&]bolum=' + bolum + '(&|$)').test(istekler[istekler.length - 1]) && /amac=yazdir/.test(istekler[istekler.length - 1]), istekler[istekler.length - 1]);
        }
        await ctx.close();
        for (const [sayfa, id, bolum] of [['liste_evrak', '#sipEvrak', 'evrak'], ['liste_kapali', '#sipKapali', 'kapali']]) {
            ({ ctx, page } = await ac(sayfa, 1280, 900, tamam(KUCUK)));
            const x = (await bolumDugmeleri(page, [id]))[id];
            ok(`${sayfa}: ${id} başlığında 3 düğme, bolum=${bolum}`, !!x && x.n === 3 && x.url.every(u => new RegExp('[?&]bolum=' + bolum + '(&|$)').test(u)), JSON.stringify(x));
            await ctx.close();
        }
        ({ ctx, page } = await ac('liste_viewer', 1280, 900, tamam(KUCUK)));
        ok('izleyici (yalnız siparis.read): düğmeler VAR (veri ucu aynı kapıda)', await page.evaluate(() => document.querySelectorAll('#sipAcik [data-sip-yazdir]').length === 3));
        await ctx.close();
    }

    // ── meta / anahtar: geçmiş yoksa anahtar notu yok; Kapanan/Evrak kapsamında "Geçmiş: N" yok ──
    for (const [kapsam, gs, anahtarVar, gecmisVar] of [['kapali', 0, false, false], ['evrak', 2, true, false], ['acik', 0, false, true], ['tek', 1, true, true]]) {
        const k = JSON.parse(JSON.stringify(KUCUK));
        k.kapsam = kapsam; k.gecmis_say = gs;
        const { ctx, page } = await ac('liste', 1280, 900, tamam(k));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        const m = await popup.evaluate(() => ({ anahtar: !!document.querySelector('.syz-anahtar'), meta: document.querySelector('.syz-meta').textContent }));
        ok(`meta (kapsam=${kapsam}, gecmis_say=${gs}): anahtar ${anahtarVar ? 'VAR' : 'YOK'}, "Geçmiş:" ${gecmisVar ? 'VAR' : 'YOK'}`, m.anahtar === anahtarVar && /Geçmiş:/.test(m.meta) === gecmisVar, JSON.stringify(m));
        await ctx.close();
    }

    // ── kullanıcı verisi kaçışlanır (XSS) ──
    {
        const k = JSON.parse(JSON.stringify(KUCUK));
        const iG = KI(k, 'gumruk'), iU = KI(k, 'urun');
        k.satirlar[0].h[iG] = '<img src=x onerror="window.__xss=1">&amp;'; k.satirlar[0].h[iU] = 'a<script>window.__xss=2</script>b\nikinci satır'; k.filtre = '<b>F</b>'; k.notlar.push('<i>not</i>');
        const { ctx, page } = await ac('liste', 1280, 900, tamam(k));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        const x = await popup.evaluate(([iG, iU]) => ({ img: document.querySelectorAll('.syz img').length, xss: window.__xss || 0, scr: document.querySelectorAll('.syz script').length, b: document.querySelectorAll('.syz-meta b').length,
            hucre: document.querySelectorAll('.syz tbody tr')[0].children[iG].textContent, urun: document.querySelectorAll('.syz tbody tr')[0].children[iU].innerHTML }), [iG, iU]);
        ok('kaçış: <img onerror>, <script>, <b> hücre/filtre/notta ham metin; "&amp;" olduğu gibi görünür; \\n → <br>', x.img === 0 && x.xss === 0 && x.scr === 0 && x.hucre === '<img src=x onerror="window.__xss=1">&amp;' && /<br>ikinci satır/.test(x.urun) && !/<script/.test(x.urun), JSON.stringify(x));
        await ctx.close();
    }

    // ── hata yanıtları ──
    for (const [ad, yanit, beklenen] of [
        ['500 JSON', route => route.fulfill(JSONY({ ok: false, hata: 'Liste okunamadı.' }, 500)), /Liste okunamadı/],
        ['oturum süresi (HTML yanıt)', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Giriş yapın</html>' }), /Oturum sona ermiş olabilir/],
        ['boş 404 (sunucu hatası, oturum DEĞİL; v385)', route => route.fulfill({ status: 404, contentType: 'text/html', body: '' }), /HTTP 404/],
        ['boş küme', route => route.fulfill(JSONY({ ok: true, satirlar: [], kolonlar: [], adet: 0 })), /Yazdırılacak sevkiyat yok/],
        ['ağ hatası', route => route.abort('failed'), /./],
    ]) {
        const { ctx, page, hatalar } = await ac('liste', 1280, 900, yanit);
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForFunction(() => /alınamadı/.test(document.body.textContent), null, { timeout: 8000 });
        const m = await popup.evaluate(() => document.body.textContent);
        const al = await page.evaluate(() => window.__alert.join('|'));
        ok(`hata (${ad}): popup'ta hata metni + alert; tablo yok; düğme tekrar açık`, beklenen.test(m) && /alınamadı/.test(al) && !(await popup.$('.syz')) && !(await page.locator(BTN_Y).isDisabled()), m + ' / ' + al);
        ok(`hata (${ad}): print() çağrılmadı`, (await popup.evaluate(() => window.__print || 0)) === 0);
        await ctx.close();
    }

    // ── PDF: masaüstü indirme (çok sayfa) ──
    {
        const { ctx, page, istekler, hatalar } = await ac('liste', 1280, 900, tamam(BUYUK));
        const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 120000 }), page.click(BTN_P)]);
        ok('PDF indir: dosya adı = sunucunun dosya adı (…-gg.aa.yyyy) + .pdf', dl.suggestedFilename() === BUYUK.dosya + '.pdf' && /-\d{2}\.\d{2}\.\d{4}\.pdf$/.test(dl.suggestedFilename()), dl.suggestedFilename());
        const buf = fs.readFileSync(await dl.path());
        ok('PDF indir: gerçek PDF, yatay A4 (841×595)', buf.slice(0, 5).toString() === '%PDF-' && buf.length > 20000 && /\/MediaBox\s*\[\s*0\s+0\s+841\.\d+\s+595\.\d+\s*\]/.test(buf.toString('latin1')), String(buf.length));
        const sy = pdfSayfa(buf);
        ok('PDF indir: 300 satır birden çok sayfaya bölünür (' + sy + ')', sy >= 4);
        const sayfaNo = new RegExp('Sayfa ' + sy + ' / ' + sy);
        ok('PDF: her sayfaya "Sayfa i / n" yazılır (son sayfa: Sayfa ' + sy + ' / ' + sy + ')', sayfaNo.test(buf.toString('latin1')) && /Sayfa 1 \/ /.test(buf.toString('latin1')));
        ok('PDF indir: amac=pdf isteği + düğme normale döndü + geçici öğe temizlendi', /amac=pdf/.test(istekler[0]) && await page.waitForFunction(() => document.querySelector('[data-sip-yazdir="pdf"]').textContent.includes('PDF İndir') && !document.querySelector('[data-sip-yazdir="pdf"]').disabled).then(() => true)
            && (await page.locator('body > div[aria-hidden="true"]').count()) === 0);
        ok('PDF indir: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    // ── büyük veri: yazdırma penceresinde yatay taşma yok ──
    {
        const { ctx, page } = await ac('liste', 390, 844, tamam(BUYUK));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 15000 });
        await popup.waitForTimeout(500);
        const t = await popup.evaluate(() => ({ satir: document.querySelectorAll('.syz tbody tr').length, tasma: document.documentElement.scrollWidth - window.innerWidth, genislik: document.querySelector('.syz table').getBoundingClientRect().width, vw: window.innerWidth }));
        ok('300 satır: 390px popup\'ta tablo sayfa genişliğini aşmaz (table-layout:fixed, yüzdeli sütun)', t.satir === BUYUK.adet + 1 && t.tasma <= 0 && t.genislik <= t.vw, JSON.stringify(t));
        await ctx.close();
    }
    // ── telefon: Web Share ──
    {
        const { ctx, page, hatalar } = await ac('liste', 390, 844, tamam(KUCUK), {
            ctx: { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' },
            init: () => {
                window.__paylasimlar = []; window.__ilkRed = false;
                navigator.canShare = d => !!(d && d.files && d.files.length);
                navigator.share = async d => {
                    if (window.__ilkRed) { window.__ilkRed = false; const e = new Error('izin'); e.name = 'NotAllowedError'; throw e; }
                    const f = d.files[0];
                    window.__paylasimlar.push({ ad: f.name, tur: f.type, boyut: f.size, baslik: d.title, metin: d.text });
                };
            } });
        const pdf = page.locator(BTN_P);
        ok('telefon: düğme "PDF Gönder" der', (await pdf.textContent()).includes('PDF Gönder'));
        await pdf.click();
        await page.waitForFunction(() => window.__paylasimlar.length === 1, null, { timeout: 60000 });
        const p = await page.evaluate(() => window.__paylasimlar[0]);
        ok('telefon: PDF dosyası paylaşım sayfasına verilir (application/pdf, ad + metin)', p.tur === 'application/pdf' && p.ad === KUCUK.dosya + '.pdf' && p.boyut > 5000 && p.metin.indexOf(KUCUK.baslik + ' · ' + KUCUK.adet + ' sevkiyat') === 0, JSON.stringify(p));
        ok('telefon: paylaşımdan sonra düğme normale döner', !(await pdf.isDisabled()) && (await pdf.textContent()).includes('PDF Gönder'));
        await page.evaluate(() => { window.__ilkRed = true; });
        await pdf.click();
        await page.waitForFunction(() => document.querySelector('[data-sip-yazdir="pdf"]').textContent.includes('PDF hazır —'), null, { timeout: 60000 });
        ok('telefon: izin reddinde PDF paylaşılmadı, "PDF hazır — Paylaş" çıktı', (await page.evaluate(() => window.__paylasimlar.length)) === 1);
        await pdf.click();
        await page.waitForFunction(() => window.__paylasimlar.length === 2);
        ok('telefon: hazır PDF ikinci dokunuşla paylaşıldı, düğme sıfırlandı', (await pdf.textContent()).includes('PDF Gönder'));
        ok('telefon: AbortError (paylaşım kapatıldı) sessiz — alert yok', await (async () => {
            await page.evaluate(() => { navigator.share = async () => { const e = new Error('iptal'); e.name = 'AbortError'; throw e; }; });
            await pdf.click(); await page.waitForTimeout(3500);
            return (await page.evaluate(() => window.__alert.length)) === 0 && !(await pdf.isDisabled());
        })());
        ok('telefon: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }

    // ══ v394 — ⋮ menüsü › 💬 WP Paylaş ══
    ok('fixture (WP): 10 alan, tek kalem, toplam "23 / 3.680"', WP.ok && WP.alanlar.length === 10 && WP.kalemler.length === 1 && WP.toplam.palet_koli === '23 / 3.680' && WP.alanlar[6].deger === 'Test Sürücü Bir');
    ok('fixture (WP): Boşaltma adresi verisi (v425)', WP.adres_baslik === 'Boşaltma adresi' && WP.adresler.length === 1 && WP.adresler[0].kod === '1001');
    const PNG_IMZA = [137, 80, 78, 71, 13, 10, 26, 10];
    // html2canvas casusu: kart çizilmeden hemen önce yakalanır (gerçek kütüphane yine çağrılır)
    const WP_SPY = () => {
        window.__kart = []; window.__pano = [];
        let gercek = null;
        Object.defineProperty(window, 'html2canvas', { configurable: true,
            get() { return gercek ? function (el, o) {
                const cs = x => getComputedStyle(x);
                window.__kart.push({ w: el.getBoundingClientRect().width, bg: cs(el).backgroundColor,
                    th: [...el.querySelectorAll('th')].map(x => x.textContent.trim()).filter(Boolean),
                    thW: [...el.querySelectorAll('th')].map(x => +cs(x).fontWeight), tdW: [...el.querySelectorAll('td')].map(x => +cs(x).fontWeight),
                    tdRenk: cs(el.querySelector('td')).color, thRenk: [...el.querySelectorAll('th')].map(x => cs(x).color), alan: [...el.querySelectorAll('.swp-alan tr')].map(t => [t.dataset.k, t.querySelector('td').textContent]),
                    partiW: +cs(el.querySelector('tr[data-k="parti"] td')).fontWeight, k2Sol: parseFloat(cs(el.querySelector('.swp-kalem tbody .swp-k2')).paddingLeft),
                    adres: [...el.querySelectorAll('.swp-adres p')].map(x => [x.textContent, parseFloat(cs(x).fontSize)]), html: el.outerHTML, olcek: o && o.scale });
                return gercek(el, o); } : undefined; },
            set(f) { gercek = f; } });
    };
    const PANO_STUB = () => {
        Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true });
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: {
            write: async items => {
                if (window.__panoRed) { window.__panoRed = false; const e = new Error('izin'); e.name = 'NotAllowedError'; throw e; }
                for (const it of items) for (const t of it.types) {
                    const b = await it.getType(t); const u8 = new Uint8Array(await b.arrayBuffer()); const bm = await createImageBitmap(b);
                    window.__pano.push({ t, size: b.size, sig: [...u8.slice(0, 8)], w: bm.width, h: bm.height });
                }
            }, writeText: async () => {} } });
    };
    async function wpTikla(page, mobil, sira) {
        const kebab = page.locator(mobil ? '.sip-card-kebab .pc-kebab' : 'tr.row-link .pc-kebab').nth(sira || 0);
        await kebab.click();
        const btn = kebab.locator('xpath=..').locator('[data-sip-wp]');
        const id = await btn.getAttribute('data-sip-wp');
        await btn.click();
        return id;
    }
    const wpOpts = (extra) => Object.assign({ init: [WP_SPY, PANO_STUB] }, extra || {});

    // 1) menü: sıra, ekran içi, taşma yok, Kapanan bölümde de var
    for (const [w, h] of [[1280, 900], [390, 844]]) {
        const mobil = w < 768;
        const { ctx, page, hatalar } = await ac('liste', w, h, tamam(KUCUK), wpOpts());
        const kebab = page.locator(mobil ? '.sip-card-kebab .pc-kebab' : 'tr.row-link .pc-kebab').first();
        await kebab.click();
        const m = await kebab.locator('xpath=..').locator('.pc-dropdown').evaluate(d => {
            const r = d.getBoundingClientRect(), it = [...d.children];
            return { metin: it.map(x => x.textContent.trim()), h: it.map(x => x.getBoundingClientRect().height), alt: r.bottom, ust: r.top, sag: r.right, sol: r.left, ih: innerHeight,
                bn: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--bn-h')) || 0 };
        });
        ok(`[${w}] WP menü: öğe sırası Aç / Evrak ZIP / PKL / WP Paylaş`, m.metin.join('|') === '📂 Aç|🗜 Evrak ZIP|📄 PKL|💬 WP Paylaş', m.metin.join('|'));
        ok(`[${w}] WP menü: ekran içinde, WP düğmesi yüksekliği diğer öğelerle ±2px`, m.ust >= 0 && m.alt <= m.ih - (mobil ? m.bn : 0) + 1 && m.sol >= 0 && m.sag <= w + 1 && Math.abs(m.h[3] - m.h[2]) <= 2, JSON.stringify(m));
        ok(`[${w}] WP menü: yatay taşma yok`, (await tasma(page)) <= 0);
        const kapSay = await page.evaluate(() => { const k = document.querySelector('#sipKapali'); const kap = k ? k.parentElement : null; return kap ? kap.querySelectorAll('[data-sip-wp]').length : -1; });
        ok(`[${w}] WP menü: Kapanan bölümde de düğme var`, kapSay > 0, String(kapSay));
        ok(`[${w}] WP menü: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }

    // 2) masaüstü: panoya PNG
    {
        const { ctx, page, istekler, hatalar } = await ac('liste', 1280, 900, tamam(KUCUK), wpOpts());
        const id = await wpTikla(page, false, 0);
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 30000 });
        const p = await page.evaluate(() => window.__pano[0]);
        ok('[1280] pano: image/png, PNG imzası, 960 px genişlik (×2), anlamlı boyut', p.t === 'image/png' && JSON.stringify(p.sig) === JSON.stringify(PNG_IMZA) && p.w === 960 && p.h > 400 && p.size > 5000, JSON.stringify(p));
        const wpIst = istekler.filter(u => /siparis_paylas\.php/.test(u));
        ok('[1280] tek istek: siparis_paylas.php?id=<satırın id\'si> (taze, tek)', wpIst.length === 1 && new RegExp('siparis_paylas\\.php\\?id=' + id + '$').test(wpIst[0]), wpIst.join(','));
        const b = await page.locator('.sip-wp-durum').textContent();
        ok('[1280] bildirim: "Panoya kopyalandı — WhatsApp\'ta yapıştırın", tutucu temizlendi', /Panoya kopyalandı — WhatsApp'ta yapıştırın/.test(b) && await page.evaluate(() => document.querySelector('.swp-tutucu') === null && !document.querySelector('.sip-wp-durum').hidden), b);
        const k = await page.evaluate(() => window.__kart[0]);
        ok('[1280] kart: 480px, ×2, beyaz zemin', k.w === 480 && k.olcek === 2 && k.bg === 'rgb(255, 255, 255)', JSON.stringify([k.w, k.olcek, k.bg]));
        ok('[1280] kart: Boşaltma adresi notu — başlık + "AD NO — tam adres", küçük (≤ 13px) ve okunur (≥ 11px)', k.adres.length === 2 && k.adres[0][0] === 'Boşaltma adresi'
            && k.adres[1][0] === 'LIDL TESTSTADT 1001 — Teststrasse 1 12345 Teststadt' && k.adres.every(x => x[1] >= 11 && x[1] <= 13), JSON.stringify(k.adres));
        ok('[1280] kart: başlıklar sahibin sırasıyla (10 alan + Ürün/Kalibre/PF/Palet-Koli + Toplam)', k.th.join('|') === 'Parti No|Yükleme tarihi|Sipariş No|Navlun No|Nakliyeci|Plakalar|Şoför adı|Şoför telefonu|Gümrük|Alıcı Ülke|Ürün|Kalibre|PF|Palet/Koli|Toplam', k.th.join('|'));
        ok('[1280] kart (v410): başlıklar kalın (≥700) ve turuncu (rgb(180, 83, 9)); veriler 500 (400–550; Parti No v419: 700, toplam satırı hariç)', k.thW.every(x => x >= 700) && k.thRenk.every(x => x === 'rgb(180, 83, 9)')
            && k.tdW.slice(1, -3).every(x => x >= 400 && x <= 550), JSON.stringify([k.thW, k.thRenk, k.tdW]));
        ok('[1280] kart (v419): Parti No değeri kalın (700); Kalibre sütunu sağa kaydırıldı (sol boşluk ≥ 16px)', k.partiW === 700 && k.k2Sol >= 16, JSON.stringify([k.partiW, k.k2Sol]));
        ok('[1280] kart: değerler sunucu yanıtıyla aynı (şoför, telefon, gümrük dahil)', JSON.stringify(k.alan) === JSON.stringify(WP.alanlar.map(a => [a.k, a.deger])), JSON.stringify(k.alan));
        ok('[1280] kart: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        // 3) çift tıklama koruması: menü kapalıyken gizli düğmede iki ardışık click()
        await page.waitForTimeout(200);
        await page.evaluate(() => { window.__pano.length = 0; const b = document.querySelector('[data-sip-wp]'); b.click(); b.click(); });
        await page.waitForFunction(() => window.__pano.length >= 1, null, { timeout: 30000 });
        await page.waitForTimeout(600);
        ok('[1280] çift tıklama: tek kart, tek pano yazımı, tek ek istek', (await page.evaluate(() => window.__pano.length)) === 1 && istekler.filter(u => /siparis_paylas\.php/.test(u)).length === 2);
        // 4) koyu tema
        await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; window.__pano.length = 0; });
        await wpTikla(page, false, 0);
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 30000 });
        const kd = await page.evaluate(() => window.__kart[window.__kart.length - 1]);
        const renk = kd.tdRenk.match(/\d+/g).map(Number);
        ok('[1280] koyu tema: kart yine beyaz zeminli, koyu yazılı; bildirim görünür', kd.bg === 'rgb(255, 255, 255)' && renk.every(x => x < 120) && await page.locator('.sip-wp-durum').isVisible(), JSON.stringify([kd.bg, kd.tdRenk]));
        await ctx.close();
    }

    // 5) XSS: HTML / script değerleri kartta ham metin
    {
        const x = JSON.parse(JSON.stringify(WP));
        x.alanlar[4].deger = '<img src=x onerror="window.__xss=1">'; x.kalemler[0].urun = '<script>window.__xss=2</script>';
        const { ctx, page } = await ac('liste', 1280, 900, tamam(x), wpOpts({ wp: tamam(x) }));
        await wpTikla(page, false, 0);
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 30000 });
        const k = await page.evaluate(() => ({ html: window.__kart[0].html, xss: window.__xss }));
        ok('XSS: kartta &lt;img / &lt;script kaçışlı, hiçbir betik çalışmadı', k.html.includes('&lt;img') && k.html.includes('&lt;script') && !/<img/.test(k.html) && k.xss === undefined, k.html.slice(0, 200));
        await ctx.close();
    }

    // 6) çok kalem / boş kalem
    {
        const x = JSON.parse(JSON.stringify(WP));
        x.kalemler = [10, 12, 14, 16, 18, 20].map(n => ({ urun: 'Testfruit ' + n + ' PC · TESTVAR', kalibre: String(n), palet_koli: '4 / 550' }));
        const { ctx, page } = await ac('liste', 1280, 900, tamam(WP), wpOpts({ wp: tamam(x) }));
        await wpTikla(page, false, 0);
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 30000 });
        const a = await page.evaluate(() => ({ th: window.__kart[0].th.length, h: window.__pano[0].h }));
        await ctx.close();
        const bos = JSON.parse(JSON.stringify(WP)); bos.kalemler = [{ urun: '—', kalibre: '—', palet_koli: '—' }];
        const c2 = await ac('liste', 1280, 900, tamam(WP), wpOpts({ wp: tamam(bos) }));
        await wpTikla(c2.page, false, 0);
        await c2.page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 30000 });
        const b = await c2.page.evaluate(() => ({ html: window.__kart[0].html, h: window.__pano[0].h }));
        ok('çok kalem: başlıklar yine 15 (v395: + Alıcı Ülke, v423: + PF), PNG yüksekliği tek kalemliden büyük; boş kalem "—" satırı', a.th === 15 && a.h > b.h && /<td>—<\/td>/.test(b.html), JSON.stringify([a, b.h]));
        await c2.ctx.close();
    }

    // 7) pano yok → PNG indir
    {
        const { ctx, page } = await ac('liste', 1280, 900, tamam(KUCUK), { init: [WP_SPY, () => { delete window.ClipboardItem; Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined }); }] });
        const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), wpTikla(page, false, 0)]);
        const buf = fs.readFileSync(await dl.path());
        ok('pano yok: PNG iner (WP-<sipariş no>.png, PNG imzası), bildirim "PNG indirildi"', /^WP-TC900101\.10\.png$/.test(dl.suggestedFilename()) && JSON.stringify([...buf.slice(0, 8)]) === JSON.stringify(PNG_IMZA)
            && /PNG indirildi/.test(await page.locator('.sip-wp-durum').textContent()), dl.suggestedFilename());
        await ctx.close();
    }

    // 8) pano reddi → bildirimde "📋 Kopyala" + "⬇️ PNG İndir"
    {
        const { ctx, page } = await ac('liste', 1280, 900, tamam(KUCUK), wpOpts({ init: [WP_SPY, PANO_STUB, () => { window.__panoRed = true; }] }));
        await wpTikla(page, false, 0);
        await page.waitForFunction(() => /panoya kopyalanamadı/.test(document.querySelector('.sip-wp-durum') ? document.querySelector('.sip-wp-durum').textContent : ''), null, { timeout: 30000 });
        const dugmeler = await page.locator('.sip-wp-durum button:not(.sip-wp-durum-kapat)').allTextContents();
        ok('pano reddi: bildirimde Kopyala + PNG İndir düğmeleri', dugmeler.join('|') === '📋 Kopyala|⬇️ PNG İndir', dugmeler.join('|'));
        await page.locator('.sip-wp-durum button', { hasText: 'Kopyala' }).click();
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 10000 });
        ok('pano reddi: "Kopyala" yeniden dener → panoya yazıldı + bildirim', /Panoya kopyalandı/.test(await page.locator('.sip-wp-durum').textContent()));
        await ctx.close();
    }

    // 9) hata yanıtları: bildirimde (alert DEĞİL), sonraki tık yeniden çalışır
    for (const [ad, yanit, beklenen] of [
        ['404 JSON', route => route.fulfill(JSONY({ ok: false, hata: 'Sevkiyat bulunamadı.' }, 404)), /Sevkiyat bulunamadı/],
        ['oturum (HTML 200)', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Giriş</html>' }), /Oturum sona ermiş olabilir/],
        ['500 HTML', route => route.fulfill({ status: 500, contentType: 'text/html', body: '' }), /HTTP 500/],
    ]) {
        const { ctx, page } = await ac('liste', 1280, 900, tamam(KUCUK), wpOpts({ wp: yanit }));
        await wpTikla(page, false, 0);
        await page.waitForFunction(() => document.querySelector('.sip-wp-durum--hata'), null, { timeout: 15000 });
        const m = await page.locator('.sip-wp-durum').textContent();
        ok(`WP hata (${ad}): bildirim hata metni, alert yok, pano yazılmadı`, beklenen.test(m) && (await page.evaluate(() => window.__alert.length === 0 && window.__pano.length === 0)), m);
        await page.evaluate(() => { window.__tekrar = true; });
        await wpTikla(page, false, 0);
        await page.waitForTimeout(500);
        ok(`WP hata (${ad}): sonraki tık yeniden çalışır (meşgul bayrağı sıfırlandı)`, await page.evaluate(() => !document.querySelector('.sip-wp-durum').hidden) );
        await ctx.close();
    }

    // 10) telefon: Web Share
    {
        const { ctx, page, hatalar } = await ac('liste', 390, 844, tamam(KUCUK), wpOpts({
            ctx: { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' },
            init: [WP_SPY, PANO_STUB, () => {
                window.__paylasimlar = []; window.__ilkRed = false;
                navigator.canShare = d => !!(d && d.files && d.files.length);
                navigator.share = async d => {
                    if (window.__ilkRed) { window.__ilkRed = false; const e = new Error('izin'); e.name = 'NotAllowedError'; throw e; }
                    const f = d.files[0];
                    window.__paylasimlar.push({ ad: f.name, tur: f.type, boyut: f.size, baslik: d.title });
                };
            }] }));
        await wpTikla(page, true, 0);
        await page.waitForFunction(() => window.__paylasimlar.length === 1, null, { timeout: 30000 });
        const p = await page.evaluate(() => ({ s: window.__paylasimlar[0], pano: window.__pano.length }));
        ok('telefon: PNG dosyası paylaşıma verilir (image/png, WP-….png, başlık = dosya adı); pano HİÇ kullanılmadı', p.s.tur === 'image/png' && /^WP-.*\.png$/.test(p.s.ad) && p.s.boyut > 5000 && p.s.baslik + '.png' === p.s.ad && p.pano === 0, JSON.stringify(p));
        await page.waitForTimeout(200);
        ok('telefon: paylaşım sonrası bildirim gizli', await page.evaluate(() => { const d = document.querySelector('.sip-wp-durum'); return !d || d.hidden; }));
        await page.evaluate(() => { window.__ilkRed = true; });
        await wpTikla(page, true, 0);
        await page.waitForFunction(() => { const d = document.querySelector('.sip-wp-durum'); return d && !d.hidden && /📤 Paylaş/.test(d.textContent); }, null, { timeout: 30000 });
        const bn = await page.evaluate(() => { const d = document.querySelector('.sip-wp-durum'), r = d.getBoundingClientRect(); return { tasma: document.documentElement.scrollWidth - innerWidth, alt: r.bottom, ih: innerHeight, sag: r.right, sol: r.left,
            btn: [...d.querySelectorAll('button')].map(b => b.getBoundingClientRect().height), sayi: window.__paylasimlar.length }; });
        ok('telefon: izin reddi → "📤 Paylaş" bildirimi, paylaşılmadı; ekran içinde, alt çubuğun üstünde, düğmeler ≥ 44px, yatay taşma yok', bn.sayi === 1 && bn.tasma <= 0 && bn.alt <= bn.ih - 92 + 1 && bn.sol >= 0 && bn.sag <= 391 && bn.btn.every(x => x >= 43.5), JSON.stringify(bn));
        await page.locator('.sip-wp-durum button', { hasText: 'Paylaş' }).click();
        await page.waitForFunction(() => window.__paylasimlar.length === 2);
        await page.waitForTimeout(200);
        ok('telefon: hazır görsel ikinci dokunuşla paylaşıldı, bildirim gizlendi', await page.evaluate(() => document.querySelector('.sip-wp-durum').hidden));
        await page.evaluate(() => { navigator.share = async () => { const e = new Error('iptal'); e.name = 'AbortError'; throw e; }; });
        await wpTikla(page, true, 0);
        await page.waitForTimeout(2500);
        ok('telefon: AbortError sessiz — alert yok, hata bildirimi yok', await page.evaluate(() => window.__alert.length === 0 && !document.querySelector('.sip-wp-durum--hata')));
        ok('telefon: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    // ══ v403 — bölüm başlığı › 🖼️ WP Resim (aynı rapor tablosu → tek PNG) ══
    const PNG_DOSYA = KUCUK.dosya + '.png';
    const PANO_BOS = () => { window.__pano = []; };
    // v410 — resim tutucusu html2canvas'a verilmeden hemen önce ölçülür (tutucu sonra silinir)
    const RESIM_SPY = () => {
        window.__resim = [];
        let gercek = null;
        Object.defineProperty(window, 'html2canvas', { configurable: true,
            get() { return gercek ? function (el, o) {
                const cs = x => getComputedStyle(x);
                if (el.closest('.syz-resim-tutucu')) window.__resim.push({
                    thBg: [...el.querySelectorAll('thead th:not(.syz-ts)')].map(x => cs(x).backgroundColor),
                    thRenk: [...el.querySelectorAll('thead th')].map(x => cs(x).color),
                    tsBg: [...el.querySelectorAll('thead th.syz-ts')].map(x => cs(x).backgroundColor),
                    tdW: [...el.querySelectorAll('tbody tr:not(.syz-toplam) td')].map(x => ({ w: +cs(x).fontWeight, ilk: x.parentNode.classList.contains('syz-bugun') && x === x.parentNode.firstElementChild })),
                    tdRenk: cs(el.querySelector('tbody tr:not(.syz-toplam) td')).color,
                    thMetin: [...el.querySelectorAll('thead th')].map(x => x.textContent.trim()),
                    tdAlign: [...new Set([...el.querySelectorAll('tbody td')].map(x => cs(x).textAlign))],
                    gecmisBg: [...el.querySelectorAll('tr.syz-gecmis>td')].map(x => cs(x).backgroundColor),
                    pp: (() => { const ths = [...el.querySelectorAll('thead th')].map(x => x.textContent.trim()), ix = [ths.indexOf('Parti no'), ths.indexOf('Plaka')];
                        return [...el.querySelectorAll('tbody tr:not(.syz-toplam)')].map(r => ix.map(i => { const td = r.children[i], lh = parseFloat(cs(td).lineHeight) || 19; return { satir: Math.round((td.getBoundingClientRect().height - 10) / lh), beklenen: td.innerHTML === '' ? 1 : td.innerHTML.split('<br>').length, h: td.innerHTML }; })); })(),
                    top: (() => { const t = el.querySelector('tr.syz-toplam>td'); return t ? [cs(t).backgroundColor, cs(t).color, +cs(t).fontWeight] : null; })() });
                return gercek(el, o); } : undefined; },
            set(f) { gercek = f; } });
    };
    // R1) masaüstü: panoya PNG + bildirim; R2) Kapanan bölümün düğmesi kendi bolum'unu ister
    {
        const { ctx, page, istekler, hatalar } = await ac('liste', 1280, 900, tamam(KUCUK), { init: [PANO_BOS, PANO_STUB, RESIM_SPY] });
        const r = page.locator(BTN_R);
        ok('[1280] resim: düğme etiketi "🖼️ WP Resim"', (await r.textContent()).trim() === '🖼️ WP Resim');
        await r.click();
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 60000 });
        const p = await page.evaluate(() => window.__pano[0]);
        ok('[1280] resim: panoya image/png, PNG imzası, ≥ 2700 px genişlik (1400 × 2), anlamlı boyut', p.t === 'image/png' && JSON.stringify(p.sig) === JSON.stringify(PNG_IMZA) && p.w >= 2700 && p.h > 300 && p.size > 5000, JSON.stringify(p));
        const rs = await page.evaluate(() => window.__resim[0]);
        ok('[1280] resim (v410): başlık lacivert zemin + beyaz yazı, sayısal başlık turuncu, veri 500 #1f2937 (bugün tarihi 700), geçmiş sarı, toplam açık mavi 800',
            rs && rs.thBg.length > 0 && rs.thBg.every(x => x === 'rgb(30, 58, 95)') && rs.thRenk.every(x => x === 'rgb(255, 255, 255)')
            && rs.tsBg.length > 0 && rs.tsBg.every(x => x === 'rgb(180, 83, 9)')
            && rs.tdW.every(x => x.ilk ? x.w === 700 : x.w === 500) && rs.tdRenk === 'rgb(31, 41, 55)'
            && rs.gecmisBg.every(x => x === 'rgb(255, 243, 176)')
            && JSON.stringify(rs.top) === JSON.stringify(['rgb(219, 231, 245)', 'rgb(30, 58, 95)', 800]), JSON.stringify(rs));
        ok('[1280] resim (v419): kalibre başlıklarında "adet" yok ("Kalibre 14"), hücre içi veriler ortalı', rs && rs.thMetin.some(x => /^Kalibre \S+$/.test(x)) && rs.thMetin.every(x => !/adet/i.test(x)) && JSON.stringify(rs.tdAlign) === JSON.stringify(['center']), JSON.stringify([rs && rs.thMetin, rs && rs.tdAlign]));
        ok('[1280] resim (v427): parti no / plaka hücrelerinde her değer ayrı satır, ortadan kırılmaz', rs && rs.pp.length > 0 && rs.pp.every(r => r.every(c => c.satir <= c.beklenen)) && rs.pp.some(r => r.some(c => c.beklenen > 1)), JSON.stringify(rs && rs.pp.filter(r => r.some(c => c.satir > c.beklenen)).slice(0, 2)));
        ok('[1280] resim: tek istek amac=resim + bolum=acik', istekler.length === 1 && /amac=resim/.test(istekler[0]) && /bolum=acik/.test(istekler[0]), istekler.join(','));
        await page.waitForFunction(() => { const d = document.querySelector('.sip-yz-durum'); return d && !d.hidden; }, null, { timeout: 5000 });
        const b = await page.evaluate(() => { const d = document.querySelector('.sip-yz-durum'); return { t: d.textContent, z: +getComputedStyle(d).zIndex, wp: !!document.querySelector('.sip-wp-durum'),
            tutucu: document.querySelectorAll('.syz-resim-tutucu, body > div[aria-hidden="true"]').length, btn: document.querySelector('[data-sip-yazdir="resim"]').textContent, dis: document.querySelector('[data-sip-yazdir="resim"]').disabled }; });
        ok('[1280] resim: bildirim "Resim panoya kopyalandı — WhatsApp\'ta yapıştırın" (z ≥ 600, alert yok); tutucu temizlendi; düğme normale döndü', /Resim panoya kopyalandı — WhatsApp'ta yapıştırın/.test(b.t) && b.z >= 600 && !b.wp && b.tutucu === 0 && b.btn === '🖼️ WP Resim' && !b.dis
            && (await page.evaluate(() => window.__alert.length)) === 0, JSON.stringify(b));
        await page.evaluate(() => { window.__pano.length = 0; });
        await page.click('#sipKapali [data-sip-yazdir="resim"]');
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 60000 });
        ok('[1280] resim: Kapanan bölüm düğmesi bolum=kapali + amac=resim ister', /bolum=kapali/.test(istekler[1]) && /amac=resim/.test(istekler[1]), istekler.join(','));
        ok('[1280] resim: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    // R3) büyük veri: kanvas yüksekliği sınırı (16000 px) aşılmaz, ölçek ≥ 1
    {
        const { ctx, page } = await ac('liste', 1280, 900, tamam(BUYUK), { init: [PANO_BOS, PANO_STUB] });
        await page.click(BTN_R);
        await page.waitForFunction(() => window.__pano.length === 1, null, { timeout: 120000 });
        const p = await page.evaluate(() => window.__pano[0]);
        ok('300 satır resim: PNG yüksekliği ≤ 16000 px, genişlik ≥ 1160 px (ölçek ≥ 1)', p.t === 'image/png' && p.h <= 16000 && p.h > 3000 && p.w >= 1160, JSON.stringify(p));
        await ctx.close();
    }
    // R4) pano yok → PNG iner; R5) pano reddi → PNG iner
    for (const [ad, init] of [
        ['pano yok', () => { delete window.ClipboardItem; Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined }); }],
        ['pano reddi', [PANO_BOS, PANO_STUB, () => { window.__panoRed = true; }]],
    ]) {
        const { ctx, page } = await ac('liste', 1280, 900, tamam(KUCUK), { init });
        const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 60000 }), page.click(BTN_R)]);
        const buf = fs.readFileSync(await dl.path());
        await page.waitForFunction(() => { const d = document.querySelector('.sip-yz-durum'); return d && !d.hidden; }, null, { timeout: 5000 });
        ok(`resim (${ad}): PNG iner (${PNG_DOSYA}, PNG imzası), bildirim "indirildi"`, dl.suggestedFilename() === PNG_DOSYA && JSON.stringify([...buf.slice(0, 8)]) === JSON.stringify(PNG_IMZA)
            && /indirildi/.test(await page.locator('.sip-yz-durum').textContent()), dl.suggestedFilename());
        await ctx.close();
    }
    // R6) hata yanıtı: bildirimde (alert DEĞİL), düğme tekrar açık
    {
        const { ctx, page } = await ac('liste', 1280, 900, route => route.fulfill(JSONY({ ok: false, hata: 'Liste okunamadı.' }, 500)), { init: [PANO_BOS, PANO_STUB] });
        await page.click(BTN_R);
        await page.waitForFunction(() => { const d = document.querySelector('.sip-yz-durum'); return d && !d.hidden && d.getAttribute('data-tur') === 'hata'; }, null, { timeout: 15000 });
        ok('resim hata: bildirimde "Liste okunamadı", alert yok, pano yazılmadı, düğme açık', /Liste okunamadı/.test(await page.locator('.sip-yz-durum').textContent())
            && (await page.evaluate(() => window.__alert.length === 0 && window.__pano.length === 0)) && !(await page.locator(BTN_R).isDisabled()) && (await page.locator(BTN_R).textContent()) === '🖼️ WP Resim');
        await ctx.close();
    }
    // R7) telefon: Web Share + izin reddi → "Resim hazır — Paylaş" (yalnız o düğmede) → ikinci dokunuş
    {
        const { ctx, page, hatalar } = await ac('liste', 390, 844, tamam(KUCUK), {
            ctx: { userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' },
            init: [PANO_BOS, PANO_STUB, () => {
                window.__paylasimlar = []; window.__ilkRed = false;
                navigator.canShare = d => !!(d && d.files && d.files.length);
                navigator.share = async d => {
                    if (window.__ilkRed) { window.__ilkRed = false; const e = new Error('izin'); e.name = 'NotAllowedError'; throw e; }
                    const f = d.files[0];
                    window.__paylasimlar.push({ ad: f.name, tur: f.type, boyut: f.size, baslik: d.title });
                };
            }] });
        const r = page.locator(BTN_R);
        await r.click();
        await page.waitForFunction(() => window.__paylasimlar.length === 1, null, { timeout: 60000 });
        const p = await page.evaluate(() => ({ s: window.__paylasimlar[0], pano: window.__pano.length }));
        ok('telefon resim: PNG paylaşıma verilir (image/png, ' + PNG_DOSYA + '); pano kullanılmadı', p.s.tur === 'image/png' && p.s.ad === PNG_DOSYA && p.s.boyut > 5000 && p.pano === 0, JSON.stringify(p));
        await page.waitForFunction(() => !document.querySelector('[data-sip-yazdir="resim"]').disabled);
        ok('telefon resim: paylaşım sonrası düğme normale döndü', (await r.textContent()) === '🖼️ WP Resim');
        await page.evaluate(() => { window.__ilkRed = true; });
        await r.click();
        await page.waitForFunction(() => document.querySelector('[data-sip-yazdir="resim"]').textContent.includes('Resim hazır —'), null, { timeout: 60000 });
        const durum = await page.evaluate(() => ({ n: window.__paylasimlar.length, bu: document.querySelector('[data-sip-yazdir="resim"]').textContent, diger: Array.from(document.querySelectorAll('[data-sip-yazdir="resim"]')).slice(1).map(b => b.textContent),
            pdf: Array.from(document.querySelectorAll('[data-sip-yazdir="pdf"]')).map(b => b.textContent) }));
        ok('telefon resim: izin reddi → yalnız bu düğme "🖼️ Resim hazır — Paylaş"; diğer bölümlerin resim/PDF düğmeleri etkilenmedi; paylaşılmadı',
            durum.n === 1 && durum.bu === '🖼️ Resim hazır — Paylaş' && durum.diger.every(t => t === '🖼️ WP Resim') && durum.pdf.every(t => t === '📤 PDF Gönder'), JSON.stringify(durum));
        const istekOnce = await page.evaluate(() => window.__paylasimlar.length);
        await r.click();
        await page.waitForFunction(() => window.__paylasimlar.length === 2, null, { timeout: 10000 });
        ok('telefon resim: hazır PNG ikinci dokunuşla paylaşıldı (yeniden üretmeden), düğme sıfırlandı', istekOnce === 1 && (await page.evaluate(() => window.__paylasimlar[1].tur)) === 'image/png' && (await r.textContent()) === '🖼️ WP Resim');
        await page.evaluate(() => { navigator.share = async () => { const e = new Error('iptal'); e.name = 'AbortError'; throw e; }; });
        await r.click(); await page.waitForTimeout(3500);
        ok('telefon resim: AbortError sessiz — alert yok, hata bildirimi yok, düğme açık', await page.evaluate(() => window.__alert.length === 0 && !document.querySelector('.sip-yz-durum[data-tur="hata"]:not([hidden])') && !document.querySelector('[data-sip-yazdir="resim"]').disabled));
        ok('telefon resim: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    await browser.close();
    fs.rmSync(OUT, { recursive: true, force: true });
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e.message || e); process.exit(1); });
