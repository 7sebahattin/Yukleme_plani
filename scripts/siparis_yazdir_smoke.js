'use strict';
// "Açık Sevkiyatlar" — Yazdır / PDF Gönder (v382). Gerçek PHP render (siparis_ui_render.php) + gerçek tarayıcı (Playwright + Chromium).
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
    ok('fixture: küçük JSON 13 sütun, satır var; büyük JSON ≥ 300 satır', KUCUK.ok && KUCUK.kolonlar.length === 13 && KUCUK.satirlar.length >= 4 && BUYUK.satirlar.length >= 300, KUCUK.adet + '/' + BUYUK.adet);

    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
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
    const BTN_Y = '[data-sip-yazdir="yazdir"]', BTN_P = '[data-sip-yazdir="pdf"]';

    for (const [w, h] of [[1280, 900], [390, 844]]) {
        const mobil = w < 768;
        // ── düğmeler ──
        let { ctx, page, hatalar } = await ac('liste', w, h, tamam(KUCUK));
        const d = await page.evaluate(() => {
            const b = Array.from(document.querySelectorAll('[data-sip-yazdir]'));
            const bas = document.querySelector('#sipAcik'), kap = document.querySelector('#sipKapali');
            return { n: b.length, hepsiAcikBasligi: b.every(x => bas.contains(x)), kapaliyaGirmis: !!kap && b.some(x => kap.contains(x)), kapaliBasKapsam: !!kap && !!kap.parentElement.querySelector('[data-sip-yazdir]'),
                etiketler: b.map(x => x.textContent.trim()), url: b[0].getAttribute('data-sip-yazdir-url'),
                r: b.map(x => { const r = x.getBoundingClientRect(); return { x: r.x, r: r.right, h: r.height, w: r.width }; }), noPrint: !!bas.querySelector('.sip-bolum-eylem.no-print') };
        });
        ok(`[${w}] düğmeler: Açık başlığında 2 düğme (🖨️ Yazdır + PDF), Kapanan bölümde YOK`, d.n === 2 && d.hepsiAcikBasligi && !d.kapaliBasKapsam && /Yazdır/.test(d.etiketler[0]) && /PDF/.test(d.etiketler[1]), JSON.stringify(d));
        ok(`[${w}] düğmeler: PDF etiketi masaüstü tarayıcıda "PDF İndir" (telefon UA'sında "PDF Gönder": aşağıda)`, d.etiketler[1].includes('PDF İndir'), d.etiketler[1]);
        ok(`[${w}] düğmeler: ekrana sığar, yatay taşma yok`, d.r.every(x => x.x >= 0 && x.r <= w + 1) && (await tasma(page)) <= 0, JSON.stringify(d.r) + ' / ' + (await tasma(page)));
        if (mobil) ok(`[${w}] düğmeler: dokunma hedefi ≥ 44px`, d.r.every(x => x.h >= 43.5), JSON.stringify(d.r));
        ok(`[${w}] düğme URL'i: siparis_yazdir.php, sayfa numaraları YOK`, /^siparis_yazdir\.php/.test(d.url) && !/acik_page|kapali_page/.test(d.url), d.url);
        ok(`[${w}] düğmeler: yazdırmada gizlenen kapta (no-print)`, d.noPrint);
        ok(`[${w}] liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        // ── Yazdır → popup ──
        let istekler;
        ({ ctx, page, istekler, hatalar } = await ac('liste', w, h, tamam(KUCUK)));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        await popup.waitForTimeout(500);
        const baslik = await popup.locator('.syz thead th').allTextContents();
        ok(`[${w}] yazdırma: 13 sütun SAHİBİN sırasıyla`, baslik.join('|') === 'ÇIKIŞ TARİHİ|PARTİ NO|NAVLUN NO|SİPARİŞ NO|ŞOFÖR ADI|TEL|PLAKA|LOJİSTİK FİRMA|ÜLKE|ÜRÜN BİLGİSİ|PALET SAYISI|KASA SAYISI|GRAMAJ', baslik.join('|'));
        const tbl = await popup.evaluate(() => {
            const rows = Array.from(document.querySelectorAll('.syz tbody tr'));
            const sari = rows.filter(r => r.classList.contains('syz-gecmis'));
            const cs = e => getComputedStyle(e);
            return { n: rows.length, tfoot: document.querySelectorAll('.syz tfoot').length, sonToplam: rows[rows.length - 1].classList.contains('syz-toplam'), sonIlk: rows[rows.length - 1].children[0].textContent,
                sariSay: sari.length, sariBg: sari.length ? cs(sari[0].children[3]).backgroundColor : '', sariIlk: sari.length ? sari[0].children[0].textContent : '', tumSariTik: sari.every(r => /✓$/.test(r.children[0].textContent)),
                duzBg: (() => { const d = rows.find(r => !r.classList.contains('syz-gecmis') && !r.classList.contains('syz-toplam')); return d ? cs(d.children[3]).backgroundColor : ''; })(),
                bugunKalin: (() => { const b = document.querySelector('.syz tr.syz-bugun > td:first-child'); return b ? cs(b).fontWeight : null; })(),
                gunCizgi: document.querySelectorAll('.syz tr.syz-gun').length, tikOlmayan: rows.filter(r => !r.classList.contains('syz-gecmis') && !r.classList.contains('syz-toplam')).every(r => !/✓/.test(r.children[0].textContent)),
                colgroup: document.querySelectorAll('.syz col').length, urunBr: !!document.querySelector('.syz tbody td br'), anahtar: document.querySelector('.syz-anahtar').textContent, meta: document.querySelector('.syz-meta').textContent,
                notlar: Array.from(document.querySelectorAll('.syz-not')).map(e => e.textContent), toplamPalet: rows[rows.length - 1].children[10].textContent, toplamKasa: rows[rows.length - 1].children[11].textContent,
                st: document.head.textContent, tasma: document.documentElement.scrollWidth - window.innerWidth, baslikH2: document.querySelector('.syz h2').textContent, title: document.title,
                parti: rows[0].children[1].textContent };
        });
        ok(`[${w}] yazdırma: satır sayısı = adet + TOPLAM; toplam satırı tbody SONUNDA, tfoot YOK`, tbl.n === KUCUK.adet + 1 && tbl.sonToplam && tbl.sonIlk === 'TOPLAM' && tbl.tfoot === 0, JSON.stringify(tbl));
        ok(`[${w}] yazdırma: geçmiş tarihli satırlar sarı (rgb(255, 243, 176)) + tarihte ✓; diğerlerinde sarı/✓ yok`, tbl.sariSay === KUCUK.gecmis_say && tbl.sariSay >= 3 && tbl.sariBg === 'rgb(255, 243, 176)' && tbl.tumSariTik && tbl.duzBg !== 'rgb(255, 243, 176)' && tbl.tikOlmayan, JSON.stringify(tbl));
        ok(`[${w}] yazdırma: bugünün tarihi kalın, gün değişiminde üst çizgi, çok satırlı ürün hücresi <br>`, tbl.bugunKalin === '700' && tbl.gunCizgi >= 2 && tbl.urunBr, JSON.stringify([tbl.bugunKalin, tbl.gunCizgi, tbl.urunBr]));
        ok(`[${w}] yazdırma: toplam palet/kasa satırı + meta + anahtar + kişisel veri notu`, tbl.toplamPalet === KUCUK.toplam.palet && tbl.toplamKasa === KUCUK.toplam.kasa && /Sarı zemin ve ✓ = çıkış tarihi geçmiş/.test(tbl.anahtar) && /sevkiyat/.test(tbl.meta) && /Basım:/.test(tbl.meta)
            && tbl.notlar.some(n => /Kişisel veri içerir/.test(n)) && tbl.colgroup === 13, JSON.stringify([tbl.toplamPalet, tbl.toplamKasa, tbl.anahtar, tbl.meta, tbl.notlar]));
        ok(`[${w}] yazdırma: @page A4 yatay + print-color-adjust + başlık`, /@page\{size:A4 landscape/.test(tbl.st) && /print-color-adjust:exact/.test(tbl.st) && tbl.baslikH2 === 'Açık Sevkiyatlar' && /^Acik-Sevkiyatlar-\d{2}\.\d{2}\.\d{4}$/.test(tbl.title), tbl.baslikH2 + ' / ' + tbl.title);
        ok(`[${w}] yazdırma: popup'ta yatay taşma yok (küçük veri)`, tbl.tasma <= 0, String(tbl.tasma));
        ok(`[${w}] yazdırma: print() kendiliğinden çağrıldı`, (await popup.evaluate(() => window.__print)) >= 1);
        ok(`[${w}] yazdırma: sunucu isteği amac=yazdir, taze fetch (istemcide önbellek yok)`, istekler.length === 1 && /amac=yazdir/.test(istekler[0]) && !/acik_page|kapali_page/.test(istekler[0]), istekler.join(','));
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
        const n = await page.evaluate(() => { const b = Array.from(document.querySelectorAll('[data-sip-yazdir]')); const s = document.querySelector('#sipSonuclar'); return { n: b.length, hepsi: b.length > 0 && b.every(x => s.contains(x)), url: b[0] && b[0].getAttribute('data-sip-yazdir-url') }; });
        ok('filtreli liste (tek durum): düğmeler "Sonuçlar" başlığında; URL durum filtresini taşır', n.n === 2 && n.hepsi && /durum=arac_bekleyen/.test(n.url), JSON.stringify(n));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        ok('filtreli liste: istek filtre anahtarını + amac taşır', /durum=arac_bekleyen/.test(istekler[0]) && /amac=yazdir/.test(istekler[0]), istekler[0]);
        await ctx.close();
    }
    {   // kapanan filtre: düğme YOK; izleyici: VAR
        let { ctx, page } = await ac('liste_kapali', 1280, 900, tamam(KUCUK));
        ok('durum=kapali listesinde yazdırma düğmesi YOK', await page.evaluate(() => document.querySelectorAll('[data-sip-yazdir]').length === 0 && !!document.querySelector('#sipKapali')));
        await ctx.close();
        ({ ctx, page } = await ac('liste_viewer', 1280, 900, tamam(KUCUK)));
        ok('izleyici (yalnız siparis.read): düğmeler VAR (veri ucu aynı kapıda)', await page.evaluate(() => document.querySelectorAll('[data-sip-yazdir]').length === 2));
        await ctx.close();
    }

    // ── kullanıcı verisi kaçışlanır (XSS) ──
    {
        const k = JSON.parse(JSON.stringify(KUCUK));
        k.satirlar[0].h[7] = '<img src=x onerror="window.__xss=1">&amp;'; k.satirlar[0].h[9] = 'a<script>window.__xss=2</script>b\nikinci satır'; k.filtre = '<b>F</b>'; k.notlar.push('<i>not</i>');
        const { ctx, page } = await ac('liste', 1280, 900, tamam(k));
        const [popup] = await Promise.all([ctx.waitForEvent('page'), page.click(BTN_Y)]);
        await popup.waitForSelector('#pdfIcerik', { timeout: 8000 });
        const x = await popup.evaluate(() => ({ img: document.querySelectorAll('.syz img').length, xss: window.__xss || 0, scr: document.querySelectorAll('.syz script').length, b: document.querySelectorAll('.syz-meta b').length,
            hucre: document.querySelectorAll('.syz tbody tr')[0].children[7].textContent, urun: document.querySelectorAll('.syz tbody tr')[0].children[9].innerHTML }));
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
        ok('PDF indir: dosya adı Acik-Sevkiyatlar-gg.aa.yyyy.pdf', /^Acik-Sevkiyatlar-\d{2}\.\d{2}\.\d{4}\.pdf$/.test(dl.suggestedFilename()), dl.suggestedFilename());
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
        ok('telefon: PDF dosyası paylaşım sayfasına verilir (application/pdf, ad + metin)', p.tur === 'application/pdf' && /^Acik-Sevkiyatlar-.*\.pdf$/.test(p.ad) && p.boyut > 5000 && /Açık Sevkiyatlar · \d+ sevkiyat/.test(p.metin), JSON.stringify(p));
        ok('telefon: paylaşımdan sonra düğme normale döner', !(await pdf.isDisabled()) && (await pdf.textContent()).includes('PDF Gönder'));
        await page.evaluate(() => { window.__ilkRed = true; });
        await pdf.click();
        await page.waitForFunction(() => document.querySelector('[data-sip-yazdir="pdf"]').textContent.includes('PDF hazır'), null, { timeout: 60000 });
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
    ok('fixture (WP): 9 alan, tek kalem, toplam "23 / 3.680"', WP.ok && WP.alanlar.length === 9 && WP.kalemler.length === 1 && WP.toplam.palet_koli === '23 / 3.680' && WP.alanlar[6].deger === 'Test Sürücü Bir');
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
                    tdRenk: cs(el.querySelector('td')).color, alan: [...el.querySelectorAll('.swp-alan tr')].map(t => [t.dataset.k, t.querySelector('td').textContent]),
                    html: el.outerHTML, olcek: o && o.scale });
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
        ok('[1280] pano: image/png, PNG imzası, 880 px genişlik (×2), anlamlı boyut', p.t === 'image/png' && JSON.stringify(p.sig) === JSON.stringify(PNG_IMZA) && p.w === 880 && p.h > 400 && p.size > 5000, JSON.stringify(p));
        const wpIst = istekler.filter(u => /siparis_paylas\.php/.test(u));
        ok('[1280] tek istek: siparis_paylas.php?id=<satırın id\'si> (taze, tek)', wpIst.length === 1 && new RegExp('siparis_paylas\\.php\\?id=' + id + '$').test(wpIst[0]), wpIst.join(','));
        const b = await page.locator('.sip-wp-durum').textContent();
        ok('[1280] bildirim: "Panoya kopyalandı — WhatsApp\'ta yapıştırın", tutucu temizlendi', /Panoya kopyalandı — WhatsApp'ta yapıştırın/.test(b) && await page.evaluate(() => document.querySelector('.swp-tutucu') === null && !document.querySelector('.sip-wp-durum').hidden), b);
        const k = await page.evaluate(() => window.__kart[0]);
        ok('[1280] kart: 440px, ×2, beyaz zemin', k.w === 440 && k.olcek === 2 && k.bg === 'rgb(255, 255, 255)', JSON.stringify([k.w, k.olcek, k.bg]));
        ok('[1280] kart: başlıklar sahibin sırasıyla (9 alan + Ürün/Kalibre/Palet-Koli + Toplam)', k.th.join('|') === 'Parti No|Yükleme tarihi|Sipariş No|Navlun No|Nakliyeci|Plakalar|Şoför adı|Şoför telefonu|Yurt içi gümrük|Ürün|Kalibre|Palet/Koli|Toplam', k.th.join('|'));
        ok('[1280] kart: başlıklar kalın (≥700), veriler ince (≤400)', k.thW.every(x => x >= 700) && k.tdW.every(x => x <= 400), JSON.stringify([k.thW, k.tdW]));
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
        ok('çok kalem: başlıklar yine 13, PNG yüksekliği tek kalemliden büyük; boş kalem "—" satırı', a.th === 13 && a.h > b.h && /<td>—<\/td>/.test(b.html), JSON.stringify([a, b.h]));
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
    await browser.close();
    fs.rmSync(OUT, { recursive: true, force: true });
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e.message || e); process.exit(1); });
