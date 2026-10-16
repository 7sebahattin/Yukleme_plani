// =========================================================
// scripts/mail_ui_smoke.js — Mail Merkezi GERÇEK TARAYICI testi (Playwright + Chromium)
//   MAIL_UI_OUT=/tmp/mail-ui php scripts/mail_ui_render.php
//   MAIL_UI_OUT=/tmp/mail-ui node scripts/mail_ui_smoke.js
// Ölçer: yatay taşma yok · mobilde liste YA DA mesaj · tabletten itibaren yan yana · geniş ekranda sol klasör sütunu ·
// sabit "Cevapla" çubuğu alt çubuğun ÜSTÜNDE ve içeriği örtmüyor · dokunma hedefi ≥40px · mobil input 16px ·
// koyu temada kontrast · sandbox'lı iframe içinde SAKLI XSS ÇALIŞMIYOR (savunma derinliği) · konsol hatası yok.
// Playwright yoksa kendini ATLAR (hata değil).
// =========================================================
'use strict';
const fs = require('fs'), os = require('os'), path = require('path');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const OUT = process.env.MAIL_UI_OUT || path.join(os.tmpdir(), 'mail-ui-test');
if (!fs.existsSync(path.join(OUT, 'manifest.json'))) { console.error('Önce: MAIL_UI_OUT=' + OUT + ' php scripts/mail_ui_render.php'); process.exit(1); }
const M = JSON.parse(fs.readFileSync(path.join(OUT, 'manifest.json'), 'utf8'));
const SHOTS = path.join(OUT, 'screens'); fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) { pass++; } else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }

const EKRANLAR = [[360, 740], [390, 844], [767, 900], [768, 900], [1024, 800], [1280, 800], [1440, 900]];
const MOBIL = (w) => w < 768;

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    async function ac(sayfa, w, h, secenek = {}) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: secenek.koyu ? 'dark' : 'light',
            isMobile: MOBIL(w), hasTouch: MOBIL(w), reducedMotion: 'reduce' });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/mail\.php|Failed to fetch|ERR_FILE|CORS|file:\/\/|ERR_TUNNEL|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        if (secenek.init) await page.addInitScript(secenek.init);
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        await page.waitForTimeout(450);   // açılış animasyonları + iframe yüklemesi (CLAUDE.md: ölçümden önce bekle)
        return { ctx, page, hatalar };
    }
    const rect = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
        return { x: r.x, y: r.y, w: r.width, h: r.height, r: r.right, b: r.bottom, gorunur: cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0, pos: cs.position, fs: cs.fontSize }; }, sel);

    for (const [w, h] of EKRANLAR) {
        const mobil = MOBIL(w);
        // ── Liste sayfası ──
        let { ctx, page, hatalar } = await ac('liste', w, h);
        const tasma = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        ok(`[${w}] liste: yatay taşma yok`, tasma <= 0, 'taşma=' + tasma);
        const liste = await rect(page, '.mail-liste'), oku = await rect(page, '.mail-okuyucu');
        if (mobil) {
            ok(`[${w}] liste: mobilde liste görünür, mesaj paneli GİZLİ`, liste && liste.gorunur && !(oku && oku.gorunur));
            const ogeler = await page.$$eval('.mail-oge', es => es.map(e => e.getBoundingClientRect().height));
            ok(`[${w}] liste: satır dokunma hedefi ≥ 44px`, ogeler.length > 0 && ogeler.every(x => x >= 44), Math.min(...ogeler) + '');
            const chip = await page.$$eval('.mail-chip', es => es.map(e => e.getBoundingClientRect().height));
            ok(`[${w}] liste: çip dokunma hedefi ≥ 40px`, chip.every(x => x >= 39.5), Math.min(...chip) + '');
            const ara = await rect(page, '.mail-ara input');
            ok(`[${w}] liste: arama kutusu 16px (iOS zoom önleme)`, ara && parseFloat(ara.fs) >= 16, ara && ara.fs);
            const bn = await rect(page, '.bottomnav');
            ok(`[${w}] liste: alt çubuk var, içerik arkasında kalmıyor`, bn && bn.gorunur);
        } else {
            ok(`[${w}] liste: iki panel de görünür`, liste && oku && liste.gorunur && oku.gorunur);
            ok(`[${w}] liste: yan yana (liste solda)`, liste.r <= oku.x + 1, `liste.r=${liste.r} oku.x=${oku.x}`);
        }
        if (w >= 1180) {
            const hs = await rect(page, '.mail-hesaplar'), fl = await rect(page, '.mail-filtreler');
            ok(`[${w}] liste: hesap + klasör sütunu LİSTENİN SOLUNDA`, hs && fl && hs.r <= liste.x + 1 && fl.r <= liste.x + 1, `hs.r=${hs && hs.r} fl.r=${fl && fl.r} liste.x=${liste.x}`);
            ok(`[${w}] liste: klasör sütunu dikey dizili`, fl && fl.h > 120);
        }
        ok(`[${w}] liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await page.screenshot({ path: path.join(SHOTS, `${w}_liste.png`), fullPage: false });
        await ctx.close();

        // ── Detay (HTML, uzak görsel engelli) ──
        ({ ctx, page, hatalar } = await ac('detay_html', w, h));
        const t2 = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        ok(`[${w}] detay: yatay taşma yok`, t2 <= 0, 'taşma=' + t2);
        const l2 = await rect(page, '.mail-liste'), o2 = await rect(page, '.mail-okuyucu'), fr = await rect(page, 'iframe.mail-govde'), cv = await rect(page, '.mail-cevapbar');
        if (mobil) {
            ok(`[${w}] detay: mobilde liste GİZLİ, mesaj görünür`, !(l2 && l2.gorunur) && o2 && o2.gorunur);
            const geri = await rect(page, '.mail-geri');
            ok(`[${w}] detay: "← Liste" düğmesi görünür ve ≥40px`, geri && geri.gorunur && geri.h >= 39.5, geri && geri.h + '');
            const bn = await rect(page, '.bottomnav .bn-dock') || await rect(page, '.bottomnav');
            ok(`[${w}] detay: Cevapla çubuğu sabit ve ekranda`, cv && cv.pos === 'fixed' && cv.y >= 0 && cv.b <= h + 1, JSON.stringify(cv));
            ok(`[${w}] detay: Cevapla çubuğu ALT ÇUBUĞUN ÜSTÜNDE (örtüşme yok)`, cv && bn && cv.b <= bn.y + 2, `cv.b=${cv && cv.b} bn.y=${bn && bn.y}`);
            const btn = await rect(page, '.mail-cevapla');
            ok(`[${w}] detay: Cevapla düğmesi ≥44px yükseklik`, btn && btn.h >= 43.5, btn && btn.h + '');
            // En alta kaydır: son içerik Cevapla çubuğunun arkasında kalmasın
            await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
            await page.waitForTimeout(250);
            const sonIcerik = await page.evaluate(() => { const c = [...document.querySelector('.mail-okuyucu').children].filter(e => !e.classList.contains('mail-cevapbar')); return c[c.length - 1].getBoundingClientRect().bottom; });
            const cv2 = await rect(page, '.mail-cevapbar');
            ok(`[${w}] detay: sayfa sonunda SON İÇERİK Cevapla çubuğunun ARKASINDA kalmıyor`, sonIcerik <= cv2.y + 1, `panel.b=${sonIcerik} cv.y=${cv2.y}`);
        } else {
            ok(`[${w}] detay: liste + mesaj yan yana`, l2 && o2 && l2.gorunur && o2.gorunur && l2.r <= o2.x + 1);
            ok(`[${w}] detay: Cevapla çubuğu akışta (sabit DEĞİL)`, cv && cv.pos !== 'fixed');
            const geri = await rect(page, '.mail-geri');
            ok(`[${w}] detay: "← Liste" masaüstünde gizli`, !(geri && geri.gorunur));
        }
        ok(`[${w}] detay: iframe sandbox = yalnız allow-popups(+escape) — script/same-origin YOK`,
            await page.$eval('iframe.mail-govde', f => f.getAttribute('sandbox')) === 'allow-popups allow-popups-to-escape-sandbox');
        ok(`[${w}] detay: iframe taşmıyor`, fr && fr.r <= w + 1 && fr.w > 150, JSON.stringify(fr));
        ok(`[${w}] detay: uzak görsel engelli uyarısı`, (await page.textContent('.mail-okuyucu')).includes('Uzak görseller engellendi'));
        const frame = page.frames().find(f => f !== page.mainFrame());
        const img = frame ? await frame.evaluate(() => { const i = document.querySelector('img'); return i ? { src: i.getAttribute('src'), engel: i.getAttribute('data-blocked-src'), gorunur: getComputedStyle(i).display !== 'none' } : null; }) : null;
        ok(`[${w}] detay: iframe içinde uzak görselin src'si YOK ve görünmez`, img && img.src === null && img.engel && !img.gorunur, JSON.stringify(img));
        const t3 = await page.$eval('.mail-okuyucu', e => e.scrollWidth - e.clientWidth);
        ok(`[${w}] detay: okuyucu içi yatay taşma yok (uzun adres/konu)`, t3 <= 1, 't3=' + t3);
        ok(`[${w}] detay: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await page.screenshot({ path: path.join(SHOTS, `${w}_detay.png`), fullPage: false });
        await ctx.close();
    }

    // ── Saklı XSS (temizleyici atlanmış varsayımıyla): iframe sandbox + CSP koruyor mu? ──
    {
        const { ctx, page, hatalar } = await ac('detay_xss', 1280, 900);
        await page.waitForTimeout(400);
        const ust = await page.evaluate(() => window.__pwned);
        ok('XSS: ÜST sayfada window.__pwned tanımsız', ust === undefined, String(ust));
        const frame = page.frames().find(f => f !== page.mainFrame());
        ok('XSS: iframe var', !!frame);
        if (frame) {
            ok('XSS: iframe içinde <script> ÇALIŞMADI (__pwned tanımsız)', await frame.evaluate(() => window.__pwned) === undefined);
            ok('XSS: iç içe iframe/srcdoc betiği üst sayfayı etkilemedi', await page.evaluate(() => window.__pwned) === undefined);
            const link = await frame.$('#jslink');
            if (link) {
                const yeniSayfa = ctx.waitForEvent('page', { timeout: 800 }).catch(() => null);
                await link.click({ force: true }).catch(() => {});
                const yp = await yeniSayfa;
                ok('XSS: javascript: bağlantısı tıklanınca bir şey ÇALIŞMADI', (await frame.evaluate(() => window.__pwned)) === undefined && (await page.evaluate(() => window.__pwned)) === undefined && !yp);
            }
            const form = await frame.$('#f');
            if (form) {
                let gonderildi = false;
                page.on('request', r => { if (/evil\.example/.test(r.url())) gonderildi = true; });
                await form.click({ force: true }).catch(() => {});
                await page.waitForTimeout(300);
                ok('XSS: iframe içindeki form GÖNDERİLEMEDİ (sandbox + CSP form-action none)', !gonderildi);
            }
            const csp = await frame.evaluate(() => (document.querySelector('meta[http-equiv="Content-Security-Policy"]') || {}).content || '');
            ok('XSS: iframe belgesinde CSP default-src none', /default-src 'none'/.test(csp) && /form-action 'none'/.test(csp));
        }
        ok('XSS: üst sayfada satır içi zararlı <script> yok', (await page.content()).indexOf('window.__pwned=1</script>') === -1 || (await page.$$eval('script', s => s.filter(x => x.textContent.includes('__pwned')).length)) === 0);
        await ctx.close();
    }

    // ── Diğer sayfalar ──
    for (const sayfa of ['liste_filtre', 'liste_arama', 'detay_tr', 'detay_tr_bekleyen', 'detay_uzun', 'liste_hesapsiz', 'detay_html_img']) {
        for (const [w, h] of [[360, 740], [768, 900], [1440, 900]]) {
            const { ctx, page, hatalar } = await ac(sayfa, w, h);
            const t = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            ok(`${sayfa}@${w}: yatay taşma yok`, t <= 0, 'taşma=' + t);
            ok(`${sayfa}@${w}: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            if (sayfa === 'detay_tr') {
                const txt = await page.textContent('.mail-metin');
                ok(`${sayfa}@${w}: Türkçe çeviri gösteriliyor, HTML KAÇIRILMIŞ (<b> etiket olarak değil metin)`, txt.includes('sipariş onaylandı') && txt.includes('<b>satır</b>') && (await page.$$('.mail-metin b')).length === 0);
            }
            if (sayfa === 'detay_tr_bekleyen') ok(`${sayfa}@${w}: çeviri yokken bilgi + Orijinal'e yönlendirme`, (await page.textContent('.mail-bilgi')).includes('Çeviri bekleniyor'));
            if (sayfa === 'detay_html_img') {
                const frame = page.frames().find(f => f !== page.mainFrame());
                const src = frame ? await frame.evaluate(() => (document.querySelector('img') || {}).getAttribute && document.querySelector('img').getAttribute('src')) : null;
                ok(`${sayfa}@${w}: "Görselleri göster" sonrası src yazıldı`, src === 'https://track.example.net/p.gif', String(src));
            }
            if (sayfa === 'detay_uzun') {
                const k = await page.evaluate(() => { const e = document.querySelector('.mail-okuyucu-konu'); return e.scrollWidth - e.clientWidth; });
                ok(`${sayfa}@${w}: çok uzun konu satırı taşmıyor`, k <= 1);
                ok(`${sayfa}@${w}: kesik gövde uyarısı`, (await page.textContent('.mail-okuyucu')).includes('yalnız bir kısmı alındı'));
            }
            if (sayfa === 'liste_hesapsiz') ok(`${sayfa}@${w}: hesap atanmamış mesajı`, (await page.textContent('.mail')).includes('atanmış bir mail hesabı yok'));
            await ctx.close();
        }
    }

    // ── M5: cevap yazma + onay ekranları ──
    for (const sayfa of ['cevap_yaz', 'onay_translated', 'onay_yetkisiz', 'onay_unknown', 'onay_failed', 'onay_sent', 'onay_draft']) {
        for (const [w, h] of [[360, 740], [390, 844], [768, 900], [1024, 800], [1440, 900]]) {
            const mobil = MOBIL(w);
            const { ctx, page, hatalar } = await ac(sayfa, w, h);
            const t = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            ok(`${sayfa}@${w}: yatay taşma yok`, t <= 0, 'taşma=' + t);
            ok(`${sayfa}@${w}: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            ok(`${sayfa}@${w}: bu ekranda sabit "Cevapla" çubuğu YOK (düzenleme/onay akışını örtmez)`, (await page.$('.mail-cevapbar')) === null);
            ok(`${sayfa}@${w}: zararlı görünümlü metin ÇALIŞMADI (window.__p tanımsız)`, await page.evaluate(() => window.__p) === undefined);
            const icT = await page.$$eval('.mail-okuyucu *', es => es.filter(e => e.scrollWidth - e.clientWidth > 2 && getComputedStyle(e).overflowX === 'visible' && e.getBoundingClientRect().right > window.innerWidth + 1).length);
            ok(`${sayfa}@${w}: hiçbir öğe ekran dışına taşmıyor (uzun e-posta/kelime)`, icT === 0, 'taşan=' + icT);
            if (sayfa === 'cevap_yaz') {
                const ta = await rect(page, '#mail-tr');
                ok(`${sayfa}@${w}: metin alanı görünür ve ≥ 120px, mobilde 16px`, ta && ta.gorunur && ta.h >= 120 && (!mobil || parseFloat(ta.fs) >= 16), ta && ta.h + '/' + ta.fs);
                const dil = await rect(page, '#mail-dil');
                ok(`${sayfa}@${w}: dil seçimi mobilde 16px`, dil && (!mobil || parseFloat(dil.fs) >= 16));
                const b = await rect(page, 'form.mail-yaz button[type=submit]');
                ok(`${sayfa}@${w}: "Çeviriyi Önizle" ≥ 40px`, b && b.h >= 39.5, b && b.h + '');
                ok(`${sayfa}@${w}: "onaylamadan gönderilmez" uyarısı görünür`, (await page.textContent('.mail-okuyucu')).includes('onaylamadan hiçbir şey gönderilmez'));
            }
            if (sayfa === 'onay_translated' || sayfa === 'onay_yetkisiz' || sayfa === 'onay_draft') {
                const k = await page.$$eval('.mail-onay-kutu', es => es.map(e => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, r: r.right, b: r.bottom, w: r.width }; }));
                ok(`${sayfa}@${w}: iki kutu var (Türkçe orijinal + gönderilecek çeviri)`, k.length === 2);
                if (k.length === 2) {
                    // Kapsayıcı sorgusu: okuyucu paneli ≥620px ise yan yana, değilse ALT ALTA (eskiden ≥768'de 127-229px'lik dar kutular çıkıyordu)
                    const kapW = await page.$eval('.mail-onay-kap', e => e.getBoundingClientRect().width);
                    const yanyana = kapW >= 620;
                    if (!yanyana) ok(`${sayfa}@${w}: dar panelde (${Math.round(kapW)}px) kutular ALT ALTA`, k[1].y >= k[0].b - 1, JSON.stringify(k));
                    else ok(`${sayfa}@${w}: geniş panelde (${Math.round(kapW)}px) kutular YAN YANA`, k[1].x >= k[0].r - 1 && Math.abs(k[0].y - k[1].y) < 4, JSON.stringify(k));
                    ok(`${sayfa}@${w}: iki kutu da ≥ 240px genişlikte (dar kutu yok)`, k.every(x => x.w >= 240), k.map(x => Math.round(x.w)).join(','));
                    ok(`${sayfa}@${w}: kutular panelin dışına taşmıyor`, k.every(x => x.r <= w + 0.5));
                }
                ok(`${sayfa}@${w}: başlıklar TÜRKÇE ORİJİNAL CEVAP / GÖNDERİLECEK ÇEVİRİ`, (await page.textContent('.mail-onay-kutular')).includes('TÜRKÇE ORİJİNAL CEVAP') && (await page.textContent('.mail-onay-kutular')).includes('GÖNDERİLECEK ÇEVİRİ'));
                const txt = await page.textContent('.mail-onay-kutular');
                ok(`${sayfa}@${w}: <img/<script> metni olarak gösterilir, öğe olarak değil`, txt.includes('<script>window.__p=2</script>') && (await page.$$('.mail-onay-kutular img, .mail-onay-kutular script')).length === 0);
            }
            if (sayfa === 'onay_translated') {
                const b = await rect(page, '.mail-onayla');
                ok(`${sayfa}@${w}: "✅ Onayla ve Gönder" görünür, ≥ 44px`, b && b.gorunur && b.h >= 43.5, b && b.h + '');
                await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
                await page.waitForTimeout(200);
                const b2 = await rect(page, '.mail-onayla'); const dock = await rect(page, '.bottomnav .bn-dock') || await rect(page, '.bottomnav');
                ok(`${sayfa}@${w}: sayfa sonunda onay düğmesi alt çubuğun ARKASINDA kalmıyor`, !mobil || (b2 && dock && b2.b <= dock.y + 2), `btn.b=${b2 && b2.b} dock.y=${dock && dock.y}`);
                ok(`${sayfa}@${w}: gizli hash alanı + tek-gönderim kancası`, (await page.$eval('form.mail-onay-form input[name=hash]', e => e.value)).length === 64 && (await page.$('form[data-tek-gonderim]')) !== null);
                ok(`${sayfa}@${w}: üçüncü taraf çeviri notu görünür`, (await page.textContent('.mail-meta')).includes('üçüncü taraf servis'));
                // Çift tık: ikinci gönderim engellenir (JS kolaylığı) — gerçek koruma sunucudadır (outbox testleri)
                const gonderimler = await page.evaluate(() => new Promise(res => { const f = document.querySelector('form.mail-onay-form'); let n = 0;
                    window.addEventListener('submit', e => { if (!e.defaultPrevented) n++; e.preventDefault(); });   // mail.js (document) önce çalışır; yalnız ENGELLENMEMİŞ gönderimler sayılır
                    const btn = f.querySelector('button'); btn.click(); btn.click(); setTimeout(() => res({ n, kilitli: btn.disabled }), 80); }));
                ok(`${sayfa}@${w}: çift tıkta tek gönderim + düğme kilitlenir`, gonderimler.n === 1 && gonderimler.kilitli, JSON.stringify(gonderimler));
            }
            if (sayfa === 'onay_yetkisiz') {
                ok(`${sayfa}@${w}: mail.send yoksa Onayla düğmesi YOK`, (await page.$('.mail-onayla')) === null && (await page.textContent('.mail-okuyucu')).includes('mail.send'));
            }
            if (sayfa === 'onay_unknown') {
                const txt = await page.textContent('.mail-okuyucu');
                ok(`${sayfa}@${w}: belirsiz uyarısı + iki çözüm düğmesi (≥40px), Onayla YOK`, txt.includes('OTOMATİK TEKRAR GÖNDERMEZ') && (await page.$$('button[value="cevap_belirsiz_gonderildi"], button[value="cevap_belirsiz_gonderilmedi"]')).length === 2 && (await page.$('.mail-onayla')) === null);
                const hs = await page.$$eval('.mail-okuyucu .mail-satir-form button', es => es.map(e => e.getBoundingClientRect().height));
                ok(`${sayfa}@${w}: çözüm düğmeleri ≥ 40px`, hs.every(x => x >= 39.5), hs.join(','));
            }
            if (sayfa === 'onay_failed') ok(`${sayfa}@${w}: "Tekrar dene" var, Onayla YOK`, (await page.$('button[type=submit]:text("Tekrar dene")')) !== null && (await page.$('.mail-onayla')) === null);
            if (sayfa === 'onay_sent') ok(`${sayfa}@${w}: gönderilmiş kayıtta HİÇBİR gönderim/onay düğmesi yok`, (await page.$$('button[value="cevap_onayla"], button[value="cevap_tekrar"], button[value="cevap_gonder_onayli"], .mail-onayla')).length === 0);
            if (w === 390 || w === 1440) await page.screenshot({ path: path.join(SHOTS, `${w}_${sayfa}.png`) });
            await ctx.close();
        }
    }

    // ── Dalga 2 ──
    // (1) `o=` (onay paneli) hiçbir liste/klasör/sayfalama/sekme bağlantısına yapışmaz
    for (const [w, h] of [[390, 844], [1280, 800]]) {
        const { ctx, page, hatalar } = await ac('onay_gelen', w, h);
        const hrefler = await page.$$eval('.mail-chip, .mail-oge, .mail-sayfalar a, .mail-sekmeler a', es => es.map(e => e.getAttribute('href') || ''));
        ok(`[${w}] onay+gelen: ${hrefler.length} bağlantının HİÇBİRİ o= taşımıyor (panelde sıkışma yok)`, hrefler.length > 8 && hrefler.every(u => !/[?&]o=/.test(u)), hrefler.filter(u => /[?&]o=/.test(u)).join(' | '));
        ok(`[${w}] onay+gelen: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    // (5)(17) .mail-bilgi blok kutu; sekmeler düz bağlantı + aria-current (role=tab yok)
    for (const [w, h] of [[360, 740], [1440, 900]]) {
        let { ctx, page } = await ac('detay_tr_bekleyen', w, h);
        const bilgi = await page.$eval('.mail-bilgi', e => { const cs = getComputedStyle(e); return { d: cs.display, t: e.getBoundingClientRect().right - e.parentElement.getBoundingClientRect().right }; });
        ok(`[${w}] bilgi kutusu display:block (cümle parçaları sütunlara kırılmıyor)`, bilgi.d === 'block', bilgi.d);
        ok(`[${w}] sekmeler: role=tab YOK, tek aria-current="page"`, (await page.$$('[role="tab"], [role="tablist"]')).length === 0 && (await page.$$('.mail-sekmeler a[aria-current="page"]')).length === 1);
        await ctx.close();
        ({ ctx, page } = await ac('detay_html', w, h));
        const eylem = await page.$eval('.mail-bilgi .mail-bilgi-eylem a.btn', e => { const r = e.getBoundingClientRect(), p = e.closest('.mail-bilgi').getBoundingClientRect(); return { alt: r.top >= p.top + 10 && r.bottom <= p.bottom + 1 && r.right <= p.right + 1, h: r.height }; });
        ok(`[${w}] "Görselleri göster" düğmesi bilgi kutusunun İÇİNDE, ayrı satırda`, eylem.alt, JSON.stringify(eylem));
        await ctx.close();
    }
    // (9) mail.reply yoksa boş yapışkan Cevapla şeridi YOK ve gereksiz alt boşluk YOK
    for (const [w, h] of [[390, 844], [1280, 800]]) {
        const { ctx, page } = await ac('detay_noreply', w, h);
        ok(`[${w}] reply yok: .mail-cevapbar çizilmez`, (await page.$('.mail-cevapbar')) === null);
        const pb = await page.$eval('.mail-okuyucu', e => parseFloat(getComputedStyle(e).paddingBottom));
        ok(`[${w}] reply yok: okuyucuda 76px'lik alt boşluk yok`, pb < 40, pb + '');
        ok(`[${w}] reply yok: yetki notu düz metin olarak görünür`, (await page.textContent('.mail-okuyucu')).includes('mail.reply yetkisi gerekir'));
        await ctx.close();
    }
    // (6) Cevap Bekleyen: toplu işaretleme
    for (const [w, h] of [[390, 844], [1280, 800]]) {
        const mobil = MOBIL(w);
        const { ctx, page, hatalar } = await ac('cevap_bekleyen', w, h);
        const t = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        ok(`[${w}] toplu: yatay taşma yok`, t <= 0, 'taşma=' + t);
        const n = (await page.$$('input[name="ids[]"]')).length;
        ok(`[${w}] toplu: her satırda onay kutusu (${n})`, n >= 5 && n === (await page.$$('.mail-oge-sar')).length);
        const sec = await page.$$eval('.mail-sec', es => es.map(e => { const r = e.getBoundingClientRect(); return [r.width, r.height]; }));
        ok(`[${w}] toplu: onay kutusu dokunma alanı ≥ 44px`, sec.every(([a, b]) => a >= 43.5 && b >= 43.5), JSON.stringify(sec[0]));
        if (mobil) {
            const bh = await page.$$eval('.mail-toplu .btn', es => es.map(e => e.getBoundingClientRect().height));
            ok(`[${w}] toplu: düğmeler ≥ 44px`, bh.length === 2 && bh.every(x => x >= 43.5), bh.join(','));
        }
        await page.check('input[data-toplu-tumu]');
        ok(`[${w}] toplu: "Tümünü seç" hepsini işaretler`, (await page.$$('input[name="ids[]"]:checked')).length === n);
        await page.uncheck('input[name="ids[]"] >> nth=0');
        ok(`[${w}] toplu: bir kutu kalkınca "Tümü" tam değil (indeterminate)`, await page.$eval('input[data-toplu-tumu]', e => !e.checked && e.indeterminate));
        await page.uncheck('input[name="ids[]"]:checked >> nth=0').catch(() => {});
        for (const k of await page.$$('input[name="ids[]"]:checked')) await k.uncheck();
        let msg = null;
        page.once('dialog', async d => { msg = d.type() + ':' + d.message(); await d.dismiss(); });
        await page.click('button[data-gerek-secim]');
        ok(`[${w}] toplu: hiç seçim yokken uyarı (alert), gönderim yok`, msg !== null && msg.startsWith('alert:') && msg.includes('Önce işaretlenecek'), String(msg));
        await page.check('input[name="ids[]"] >> nth=1');
        msg = null;
        page.once('dialog', async d => { msg = d.type() + ':' + d.message(); await d.dismiss(); });
        const url0 = page.url();
        await page.click('button[data-gerek-secim]');
        ok(`[${w}] toplu: seçim varken onay sorusu (confirm), vazgeçilince gönderilmez`, msg !== null && msg.startsWith('confirm:') && page.url() === url0, String(msg));
        ok(`[${w}] toplu: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        if (w === 390 || w === 1280) await page.screenshot({ path: path.join(SHOTS, `${w}_cevap_bekleyen.png`) });
        await ctx.close();
    }
    // (3) Cevap taslağı yedeği: yenilemede geri gelir; onay paneli açılınca temizlenir; depolama kapalıysa sayfa yine çalışır
    {
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light', reducedMotion: 'reduce' });
        const page = await ctx.newPage(); const hatalar = [];
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        await page.goto('file://' + path.join(OUT, 'cevap_yaz.html'));
        await page.waitForTimeout(300);
        await page.fill('#mail-tr', 'Taslak metni — ürün hazır, çarşamba yüklenir.');
        await page.selectOption('#mail-dil', 'ru');
        await page.waitForTimeout(700);   // 400ms'lik gecikmeli kayıt
        await page.reload(); await page.waitForTimeout(300);
        ok('taslak yedeği: sayfa yenilenince yazılan metin GERİ GELİR', (await page.inputValue('#mail-tr')) === 'Taslak metni — ürün hazır, çarşamba yüklenir.', await page.inputValue('#mail-tr'));
        ok('taslak yedeği: hedef dil de geri gelir + "geri yüklendi" bilgisi', (await page.inputValue('#mail-dil')) === 'ru' && (await page.textContent('[role="status"]')).includes('geri yüklendi'));
        await page.click('[role="status"] button');
        ok('taslak yedeği: "Taslağı sil" metni temizler', (await page.inputValue('#mail-tr')) === '' && (await page.$('[role="status"]')) === null);
        await page.fill('#mail-tr', 'Tekrar yazıldı'); await page.waitForTimeout(700);
        await page.goto('file://' + path.join(OUT, 'onay_translated.html')); await page.waitForTimeout(300);   // aynı mesaja ait onay paneli = taslak sunucuda kayıtlı
        await page.goto('file://' + path.join(OUT, 'cevap_yaz.html')); await page.waitForTimeout(300);
        ok('taslak yedeği: onay paneli açılınca yedek TEMİZLENİR (boş form)', (await page.inputValue('#mail-tr')) === '');
        ok('taslak yedeği: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
        // depolama erişimi atıyor (gizli pencere/engelli): form yine çalışır, hata yok
        const c2 = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' });
        const p2 = await c2.newPage(); const h2 = [];
        p2.on('pageerror', e => h2.push('pageerror: ' + e.message));
        await p2.addInitScript(() => { Object.defineProperty(window, 'localStorage', { get() { throw new Error('SecurityError'); } }); });
        await p2.goto('file://' + path.join(OUT, 'cevap_yaz.html')); await p2.waitForTimeout(300);
        await p2.fill('#mail-tr', 'yazılabilir'); await p2.waitForTimeout(600);
        ok('taslak yedeği: localStorage ERİŞİMİ HATA VERİYORSA form çalışır, pageerror yok', (await p2.inputValue('#mail-tr')) === 'yazılabilir' && h2.length === 0, h2.join(' | '));
        await c2.close();
    }
    // (16) Okundu işaretlenince çip sayaçları (Tümü / hesap / Okunmamış klasörü) bir azalır, satır noktası kalkar
    {
        const sahteFetch = () => { window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, mesaj: '' }) }); };
        const { ctx, page, hatalar } = await ac('detay_html', 1280, 900, { init: sahteFetch });
        const oku = () => page.evaluate(() => { const n = (s) => { const e = document.querySelector(s); return e ? parseInt(e.textContent, 10) : 0; };
            return { tumu: n('.mail-chip[data-hesap="0"] .mail-rozet'), hesap: n('.mail-chip[data-hesap="1"] .mail-rozet'), klasor: n('.mail-chip[data-filtre="okunmamis"] .mail-say'),
                nokta: document.querySelectorAll('.mail-oge[aria-current="true"] .mail-nokta').length }; });
        const once = await oku();
        await page.waitForTimeout(1700);
        const sonra = await oku();
        ok('okundu: "Tümü" rozeti 1 azaldı', once.tumu >= 1 && sonra.tumu === once.tumu - 1, JSON.stringify([once, sonra]));
        ok('okundu: hesap çipi rozeti 1 azaldı', once.hesap >= 1 && sonra.hesap === once.hesap - 1, JSON.stringify([once, sonra]));
        ok('okundu: "Okunmamış" klasör sayacı 1 azaldı', once.klasor >= 1 && sonra.klasor === once.klasor - 1, JSON.stringify([once, sonra]));
        ok('okundu: seçili satırın okunmamış noktası kalktı, düğme "Okunmadı yap"', once.nokta >= 0 && sonra.nokta === 0 && (await page.textContent('.mail-okuyucu-arac button[value="okunmadi"]')).includes('Okunmadı yap'));
        ok('okundu: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    // (8) Tablet (768–899): yapışkan liste yapışkan üst barın ALTINDA kalır
    for (const w of [768, 800, 899]) {
        const { ctx, page } = await ac('detay_uzun', w, 900);
        await page.evaluate(() => window.scrollTo({ top: 300, behavior: 'instant' }));
        await page.waitForTimeout(250);
        const r = await page.evaluate(() => ({ tb: document.querySelector('.topbar').getBoundingClientRect().bottom, lt: document.querySelector('.mail-liste').getBoundingClientRect().top, kaydi: window.scrollY }));
        ok(`[${w}] tablet: yapışkan liste üst barın altında (liste.top ${Math.round(r.lt)} ≥ topbar.bottom ${Math.round(r.tb)})`, r.kaydi > 100 && r.lt >= r.tb - 0.5, JSON.stringify(r));
        await ctx.close();
    }
    {
        const { ctx, page } = await ac('detay_uzun', 1280, 900);
        const top = await page.$eval('.mail-liste', e => getComputedStyle(e).top);
        ok('[1280] masaüstü: üst bar yok → liste top 8px (tablet kuralı sızmadı)', top === '8px', top);
        await ctx.close();
    }
    // (7) Tablet üst menüsünde Mail bağlantısı
    for (const w of [768, 899]) {
        const { ctx, page } = await ac('liste', w, 900);
        const a = await page.$('.topnav a[href$="mail.php"]');
        ok(`[${w}] tablet: üst menüde Mail bağlantısı var, görünür ve tıklanabilir`, !!a && await a.isVisible() === true);
        await ctx.close();
    }

    // ── Koyu tema kontrast ──
    {
        const lum = (c) => { const [r, g, b] = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
        const oran = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
        for (const koyu of [false, true]) {
            const { ctx, page } = await ac('detay_tr', 1280, 900, { koyu });
            if (koyu) await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
            await page.waitForTimeout(150);
            for (const sel of ['.mail-kim', '.mail-konu', '.mail-meta dd', '.mail-metin', '.mail-oz', '.mail-chip']) {
                const r = await page.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; let bg = 'rgba(0, 0, 0, 0)', n = e;
                    while (n && /rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { bg = getComputedStyle(n).backgroundColor; n = n.parentElement; }
                    return { fg: getComputedStyle(e).color, bg }; }, sel);
                if (!r) continue;
                const o = oran(r.fg, r.bg);
                ok(`kontrast ${koyu ? 'KOYU' : 'açık'} ${sel} ≥ 3:1`, o >= 3, o.toFixed(2) + ' ' + r.fg + ' / ' + r.bg);
            }
            await page.screenshot({ path: path.join(SHOTS, `1280_detay_tr_${koyu ? 'koyu' : 'acik'}.png`) });
            await ctx.close();
            // Dalga 2 yüzeyleri: toplu çubuk, bilgi kutusu, onay kutuları, soluk metin (.mail-ince) — açık VE koyu temada okunur
            for (const [sayfa, seciciler, kul] of [['cevap_bekleyen', ['.mail-toplu', '.mail-toplu-tumu', '.mail-toplu .btn'], ['perms']], ['detay_tr_bekleyen', ['.mail-bilgi', '.mail-sekmeler a'], []],
                ['onay_translated', ['.mail-onay-kutu h3', '.mail-onay-metin', '.mail-onay-kutu--cikis .mail-onay-metin'], []], ['hesaplar', ['.mail-ince'], []]]) {
                const { ctx: c2, page: p2 } = await ac(sayfa, 1280, 900, { koyu });
                if (koyu) await p2.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
                await p2.waitForTimeout(150);
                for (const sel of seciciler) {
                    const r = await p2.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; let bg = 'rgba(0, 0, 0, 0)', n = e;
                        while (n && /rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { bg = getComputedStyle(n).backgroundColor; n = n.parentElement; }
                        return { fg: getComputedStyle(e).color, bg }; }, sel);
                    ok(`kontrast ${koyu ? 'KOYU' : 'açık'} ${sayfa} ${sel} bulundu`, !!r);
                    if (!r) continue;
                    const o = oran(r.fg, r.bg);
                    ok(`kontrast ${koyu ? 'KOYU' : 'açık'} ${sayfa} ${sel} ≥ 3:1`, o >= 3, o.toFixed(2) + ' ' + r.fg + ' / ' + r.bg);
                }
                await c2.close();
            }
        }
    }

    // ── Mail Hesapları (yönetici): hesap satırı düğmeleri hiçbir genişlikte kesilmez; cron komutu kutuya sığar ──
    if (M.sayfalar.includes('hesaplar')) for (const [w, h] of EKRANLAR) {
        const { ctx, page, hatalar } = await ac('hesaplar', w, h);
        const r = await page.evaluate(() => {
            const tw = document.querySelector('.mail-hesap-tablo')?.closest('.table-wrap');
            const btn = [...document.querySelectorAll('.mail-hesap-tablo .btn')].map(e => { const b = e.getBoundingClientRect(); return { r: b.right, l: b.left, h: b.height }; });
            const kom = document.querySelector('.mail-komut'); const kart = kom?.parentElement.getBoundingClientRect(); const kr = kom?.getBoundingClientRect();
            return { tasma: document.documentElement.scrollWidth - innerWidth, ic: tw ? tw.scrollWidth - tw.clientWidth : -1, twR: tw?.getBoundingClientRect().right, btn,
                komutSigar: kom ? kr.right <= kart.right + 0.5 && kom.scrollWidth <= kom.clientWidth + 1 : null };
        });
        ok(`[${w}] hesaplar: yatay taşma yok (sayfa + tablo kutusu)`, r.tasma <= 0 && r.ic <= 0, JSON.stringify({ t: r.tasma, ic: r.ic }));
        ok(`[${w}] hesaplar: hesap düğmeleri kutunun İÇİNDE (kesilmiyor)`, r.btn.length >= 3 && r.btn.every(b => b.r <= r.twR + 0.5 && b.l >= 0), JSON.stringify(r.btn.slice(0, 3)));
        if (MOBIL(w)) ok(`[${w}] hesaplar: mobilde düğmeler ≥ 44px dokunma hedefi`, r.btn.every(b => b.h >= 43.5), Math.min(...r.btn.map(b => b.h)) + '');
        ok(`[${w}] hesaplar: cron komutu kartın içinde kırılarak görünür`, r.komutSigar === true, String(r.komutSigar));
        ok(`[${w}] hesaplar: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        if (w === 390 || w === 1280) await page.screenshot({ path: path.join(SHOTS, `${w}_hesaplar.png`), fullPage: false });
        await ctx.close();
    }

    await browser.close();
    console.log(`\n${pass} geçti, ${fail} kaldı  (ekran görüntüleri: ${SHOTS})`);
    process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
