'use strict';
// "Açık Sevkiyatlar" — Yazdır / PDF Gönder (v382). Gerçek PHP render (siparis_ui_render.php) + gerçek tarayıcı (Playwright + Chromium).
// Canlı DB / ağ YOK: sayfalar bellek içi SQLite fixture'ından gelir; siparis_yazdir.php yanıtı page.route() ile SENTETİK JSON'dan
// (php scripts/siparis_yazdir_smoke.php --json / --json-buyuk) döner; jsPDF + html2canvas yerel halkayit/ dosyalarından sunulur.
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
const JSONY = (obj, status) => ({ status: status || 200, contentType: 'application/json; charset=utf-8', body: JSON.stringify(obj) });

(async () => {
    // ── hazırlık: sayfalar + JSON fixture'ları ──
    php(['scripts/siparis_ui_render.php'], { SIPARIS_UI_OUT: OUT, SIPARIS_UI_MAIL: 'x' });
    const KUCUK = JSON.parse(php(['scripts/siparis_yazdir_smoke.php', '--json']));
    const BUYUK = JSON.parse(php(['scripts/siparis_yazdir_smoke.php', '--json-buyuk']));
    ok('fixture: küçük JSON 13 sütun, satır var; büyük JSON ≥ 300 satır', KUCUK.ok && KUCUK.kolonlar.length === 13 && KUCUK.satirlar.length >= 4 && BUYUK.satirlar.length >= 300, KUCUK.adet + '/' + BUYUK.adet);

    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    /** Sayfayı açar; siparis_yazdir.php yanıtı $yanit(route) ile; halkayit/*.js yerelden. Her istek $istekler'e yazılır. */
    async function ac(sayfa, w, h, yanit, opts) {
        opts = opts || {};
        const mobil = w < 768;
        const ctx = await browser.newContext(Object.assign({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', acceptDownloads: true }, opts.ctx || {}));
        await ctx.addInitScript(() => { window.__print = 0; window.print = () => { window.__print++; }; window.__alert = []; window.alert = m => { window.__alert.push(String(m)); }; });
        if (opts.init) await ctx.addInitScript(opts.init);
        const istekler = [];
        await ctx.route('**/siparis_yazdir.php*', route => { istekler.push(route.request().url()); return yanit(route); });
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
    await browser.close();
    fs.rmSync(OUT, { recursive: true, force: true });
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e.message || e); process.exit(1); });
