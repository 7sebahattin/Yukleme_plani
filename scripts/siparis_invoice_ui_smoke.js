// =========================================================
// scripts/siparis_invoice_ui_smoke.js — OGL Invoice (v442) GERÇEK TARAYICI testi (Playwright + Chromium)
//   SIPARIS_UI_OUT=/tmp/sip php scripts/siparis_ui_render.php && SIPARIS_UI_OUT=/tmp/sip node scripts/siparis_invoice_ui_smoke.js
// Ekranlar 1280x900 · 820x1024 · 390x844. Ölçer: yatay taşma yok · mobil 16px girdi + ≥44px düğme · canlı tutar önizlemesi (sunucu hesabıyla AYNI:
// 14.440 kg × 1,37 − %4 = 18.991,49) · e-fatura fark uyarısı · engel varken Oluştur pasif · PDF önizleme iframe'i yalnız masaüstünde ve tembel ·
// #invoice kartı · koyu tema · konsol hatası yok. Playwright yoksa kendini ATLAR. Ekran görüntüleri: <OUT>/screens/inv-*.png
// =========================================================
'use strict';
const fs = require('fs'), os = require('os'), path = require('path');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }
const OUT = process.env.SIPARIS_UI_OUT || path.join(os.tmpdir(), 'sip-ui-test');
if (!fs.existsSync(path.join(OUT, 'inv_bos.html'))) { console.error('Önce: SIPARIS_UI_OUT=' + OUT + ' php scripts/siparis_ui_render.php'); process.exit(1); }
const SHOTS = path.join(OUT, 'screens'); fs.mkdirSync(SHOTS, { recursive: true });
let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) pass++; else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    async function ac(sayfa, w, h, koyu) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', colorScheme: koyu ? 'dark' : 'light' });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        if (koyu) await page.addInitScript(() => { try { localStorage.setItem('asya_tema', 'dark'); } catch (e) {} });
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        if (koyu) await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        await page.waitForTimeout(400);
        return { ctx, page, hatalar };
    }
    const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const gorunur = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && (!e.checkVisibility || e.checkVisibility()); }, sel);
    const metin = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); return e ? e.textContent.trim() : null; }, sel);

    for (const [w, h] of [[1280, 900], [820, 1024], [390, 844]]) {
        const mobil = w < 768, tag = w + 'px';
        for (const sayfa of ['inv_bos', 'inv_hazir', 'inv_engel', 'inv_viewer', 'detay_invoice', 'detay_invoice_viewer', 'detay_invoice_pklsiz', 'inv_ayar']) {
            const { ctx, page, hatalar } = await ac(sayfa, w, h, false);
            ok(`${sayfa}@${tag}: yatay taşma yok`, (await tasma(page)) <= 1, String(await tasma(page)));
            ok(`${sayfa}@${tag}: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            await page.screenshot({ path: path.join(SHOTS, `inv-${sayfa}-${w}.png`), fullPage: true });
            await ctx.close();
        }
        // — form: canlı hesap, fark uyarısı, mobil ölçüler —
        {
            const { ctx, page } = await ac('inv_bos', w, h, false);
            ok(`form@${tag}: 7 alan görünür`, (await Promise.all(['no', 'tarih', 'fiyat', 'iskonto', 'net_kg', 'brut_kg', 'efatura_toplam'].map(n => gorunur(page, `input[name="${n}"]`)))).every(Boolean));
            await page.fill('input[name="fiyat"]', '1,37'); await page.fill('input[name="iskonto"]', '4');
            ok(`canlı hesap@${tag}: tutar 19.782,80 · iskonto 791,31 · toplam 18.991,49`, (await metin(page, '[data-inv-tutar]')) === '19.782,80' && (await metin(page, '[data-inv-iskonto]')) === '791,31' && (await metin(page, '[data-inv-toplam]')) === '18.991,49',
                [await metin(page, '[data-inv-tutar]'), await metin(page, '[data-inv-iskonto]'), await metin(page, '[data-inv-toplam]')].join(' / '));
            await page.fill('input[name="efatura_toplam"]', '18991,49');
            ok(`e-fatura uyumlu@${tag}`, /uyumlu/.test(await metin(page, '[data-inv-fark]') || '') && await gorunur(page, '[data-inv-fark]'));
            await page.fill('input[name="efatura_toplam"]', '19000,00');
            ok(`e-fatura farklı@${tag}: uyarı + fark`, /fark/.test(await metin(page, '[data-inv-fark]') || ''), await metin(page, '[data-inv-fark]'));
            await page.fill('input[name="net_kg"]', '10.000');
            ok(`net override@${tag}: 10.000 × 1,37 − %4 = 13.152,00`, (await metin(page, '[data-inv-tutar]')) === '13.700,00' && (await metin(page, '[data-inv-toplam]')) === '13.152,00', [await metin(page, '[data-inv-tutar]'), await metin(page, '[data-inv-toplam]')].join(' / '));
            await page.fill('input[name="net_kg"]', '');
            ok(`net boş@${tag}: PKL net'e döner`, (await metin(page, '[data-inv-tutar]')) === '19.782,80');
            await page.fill('input[name="fiyat"]', '1,2.5');
            ok(`geçersiz binlik grubu "1,2.5"@${tag}: sonuç "—" (PHP ayrıştırıcıyla aynı)`, (await metin(page, '[data-inv-toplam]')) === '—');
            await page.fill('input[name="net_kg"]', '1.2.3');
            ok(`net "1.2.3"@${tag}: geçersiz → "—"`, (await metin(page, '[data-inv-toplam]')) === '—');
            await page.fill('input[name="net_kg"]', '');
            await page.fill('input[name="fiyat"]', '1,37');
            await page.fill('input[name="fiyat"]', 'abc');
            ok(`geçersiz fiyat@${tag}: sonuç "—"`, (await metin(page, '[data-inv-toplam]')) === '—');
            await page.fill('input[name="fiyat"]', '1,37');
            ok(`eksik izni kutusu görünür + required@${tag}`, await page.evaluate(() => { const c = document.querySelector('input[name="eksik_izin"]'); return !!c && c.required && c.getBoundingClientRect().width > 0; }));
            if (mobil) {
                const kucukFont = await page.evaluate(() => [...document.querySelectorAll('.sip-inv-form input[type=text], .sip-inv-form input[type=date]')].filter(i => parseFloat(getComputedStyle(i).fontSize) < 16).length);
                ok('mobil: girdiler ≥16px (iOS zoom yok)', kucukFont === 0, String(kucukFont));
                const kisa = await page.evaluate(() => [...document.querySelectorAll('.sip-inv-form .btn')].filter(b => b.getBoundingClientRect().height < 40 && b.getBoundingClientRect().width > 0).map(b => b.textContent.trim() + ':' + Math.round(b.getBoundingClientRect().height)));
                ok('mobil: düğmeler dokunulabilir (≥40px)', kisa.length === 0, kisa.join(','));
            }
            await ctx.close();
        }
        // — engel: Oluştur pasif —
        {
            const { ctx, page } = await ac('inv_engel', w, h, false);
            ok(`engel@${tag}: Oluştur pasif + sebep görünür`, await page.evaluate(() => { const b = document.querySelector('[aria-describedby="sipInvNeden"]'); const n = document.getElementById('sipInvNeden'); return !!b && b.disabled && !!n && n.getBoundingClientRect().width > 0; }));
            await ctx.close();
        }
        // — PDF önizleme: yalnız masaüstü, tembel —
        {
            const { ctx, page } = await ac('inv_hazir', w, h, false);
            const gor = await gorunur(page, 'details[data-sip-inv-onizle]');
            ok(`önizleme@${tag}: ${mobil ? 'mobilde gizli (yeni sekme bağlantısı kalır)' : 'masaüstünde görünür'}`, gor === !mobil);
            ok(`yeni sekmede aç + indir bağlantıları@${tag}`, await gorunur(page, 'a[target="_blank"][href^="siparis_dosya.php"]') && await gorunur(page, 'a[href*="dl=1"]'));
            if (!mobil) {
                ok('iframe tembel: açılmadan src yok', await page.evaluate(() => !document.querySelector('.sip-inv-iframe').getAttribute('src')));
                await page.click('details[data-sip-inv-onizle] > summary'); await page.waitForTimeout(300);
                ok('açılınca iframe src = siparis_dosya.php?id=…', await page.evaluate(() => /^siparis_dosya\.php\?id=\d+$/.test(document.querySelector('.sip-inv-iframe').getAttribute('src') || '')));
            }
            await ctx.close();
        }
        // — detay kartı —
        {
            const { ctx, page } = await ac('detay_invoice', w, h, false);
            ok(`#invoice kartı görünür + Hazır rozeti@${tag}`, await gorunur(page, '#invoice') && /Hazır/.test(await metin(page, '#invoice .sip-pkl-durum') || ''));
            await ctx.close();
            const p2 = await ac('detay_invoice_pklsiz', w, h, false);
            ok(`PKL'siz: düğme pasif + sebep@${tag}`, await p2.page.evaluate(() => { const b = document.querySelector('#invoice [aria-describedby="sipInvNeden"]'); return !!b && b.disabled; }));
            await p2.ctx.close();
        }
    }
    // — koyu tema —
    for (const sayfa of ['inv_bos', 'inv_hazir', 'detay_invoice', 'inv_ayar']) {
        const { ctx, page, hatalar } = await ac(sayfa, 390, 844, true);
        ok(`${sayfa} koyu tema: taşma yok, konsol temiz`, (await tasma(page)) <= 1 && hatalar.length === 0, hatalar.join('|'));
        const bg = await page.evaluate(() => getComputedStyle(document.querySelector('.card')).backgroundColor);
        ok(`${sayfa} koyu tema: kart yüzeyi koyu`, (() => { const m = bg.match(/\d+/g); return m && (+m[0] + +m[1] + +m[2]) / 3 < 110; })(), bg);
        await page.screenshot({ path: path.join(SHOTS, `inv-${sayfa}-koyu-390.png`), fullPage: true });
        await ctx.close();
    }
    await browser.close();
    console.log(`\n${pass} geçti, ${fail} başarısız`);
    process.exit(fail ? 1 : 0);
})();
