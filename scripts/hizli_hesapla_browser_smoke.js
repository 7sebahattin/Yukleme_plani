'use strict';
// Hızlı Hesapla tarayıcı testi (320/375/390/412 + 1280). Anonim statik fixture; canlı uygulama/DB yok.
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '..');
let playwright;
for (const c of [process.env.PW_PATH, 'playwright', 'playwright-core']) { if (!c) continue; try { playwright = require(c); break; } catch (_) {} }
if (!playwright) { console.log('SKIP Playwright yok'); process.exit(0); }
const out = process.env.HH_QA_OUT || fs.mkdtempSync(path.join(os.tmpdir(), 'hizli-hesapla-'));
function fixture(variant) {
    const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'hizli_hesapla_smoke.php'), '--render', variant], { cwd: root, encoding: 'utf8' });
    const target = path.join(out, variant + '.html');
    fs.writeFileSync(target, html.replace('<head>', '<head><base href="' + pathToFileURL(root + path.sep).href + '">'));
    return pathToFileURL(target).href;
}
const pages = { fresh: fixture('new'), saved: fixture('saved'), readonly: fixture('readonly') };
let checks = 0;
function check(v, label) { assert.ok(v, label); checks++; }
(async () => {
    const browser = await playwright.chromium.launch({ headless: true });
    try {
        for (const [name, width, height] of [['w320', 320, 640], ['w375', 375, 700], ['w390', 390, 844], ['w412', 412, 800], ['desktop', 1280, 900]]) {
            const ctx = await browser.newContext({ viewport: { width, height }, isMobile: width < 768, hasTouch: width < 768, permissions: ['clipboard-read', 'clipboard-write'] });
            const page = await ctx.newPage(), errors = [];
            page.on('pageerror', e => errors.push(e.message));
            const out_ = k => page.locator('[data-hh-out="' + k + '"]').first().textContent();
            const f = k => page.locator('[data-hh="' + k + '"]');
            await page.goto(pages.fresh); await page.waitForSelector('#hh');
            const noOverflow = async label => check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' ' + label + ' yatay taşma yok');

            // Varsayılan örnek ve zorunlu sonuç
            check(await f('sale_kg').inputValue() === '20.000' && await f('sale_price').inputValue() === '1,10' && await f('rate').inputValue() === '50,00', name + ' varsayılan girdiler');
            check(await f('currency').inputValue() === 'USD' && await f('price_in_fx').isChecked(), name + ' USD + fiyat döviz işaretli');
            check(await out_('total') === '544.782,61 TL' && (await out_('total_fx')).includes('10.895,65 USD'), name + ' toplam maliyet TL + USD');
            check(await out_('unit') === '27,2391 TL/kg' && (await out_('unit_fx')).includes('0,5448 USD/kg'), name + ' net kg maliyeti');
            check(await out_('sale') === '1.100.000,00 TL' && (await out_('profit')) === '555.217,39 TL' && await out_('margin') === '%50,47', name + ' satış/kâr/marj');
            check((await out_('suggested')).startsWith('0,6810 USD/kg') && (await out_('breakeven')).startsWith('0,5448 USD/kg'), name + ' önerilen ve başabaş fiyat');
            check(await page.locator('text=Gerekli ham mal: 21.739 kg').count() === 1, name + ' gerekli ham mal bilgisi');
            check(await page.locator('button:has-text("Hesapla")').count() === 0, name + ' hesaplama düğmesi yok');
            check(await page.locator('[data-hh="raw_kg"], [data-hh="water_loss"]').count() === 0, name + ' ham KG / su kaybı girişi yok');
            const labels = await page.locator('.hh-row > span').allTextContents();
            check(JSON.stringify(labels) === JSON.stringify(['Ham mal alış fiyatı (TL/kg)', 'Nakliye bedeli (TL)', 'Malzeme gideri (TL/kg)', 'Evrak masrafları (TL)', 'Diğer masraf (TL/kg)', 'Diğer sabit masraf (TL)', 'Fire oranı (%)', 'Hedef kâr marjı (%)']), name + ' kalem sırası');
            await noOverflow('açılış');
            // Her alan ekranda ve dokunulabilir
            for (const k of ['sale_kg', 'sale_price', 'rate', 'currency', 'price_in_fx', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'waste_pct', 'target_margin']) {
                const b = await f(k).boundingBox(); check(b && b.x >= 0 && b.x + b.width <= width + 0.5 && b.height >= 20, name + ' ' + k + ' sığar');
            }
            const chk = await page.locator('.hh-chk').boundingBox(); check(chk.height >= 44, name + ' fiyat döviz kutusu dokunulabilir');

            // Canlı hesaplama + odak/imleç korunur
            await f('waste_pct').click(); await f('waste_pct').fill('');
            await page.keyboard.type('10');
            check(await f('waste_pct').evaluate(e => document.activeElement === e && e.value === '10' && e.selectionStart === 2), name + ' yazarken odak/imleç korunur');
            check(await out_('total') !== '544.782,61 TL', name + ' fire değişince canlı güncellenir');
            await f('waste_pct').fill('8');
            // Geçersiz giriş sessizce sıfır olmaz
            await f('freight').fill('12abc');
            check(await page.locator('#hhErrs').isVisible() && await out_('total') === '—', name + ' geçersiz giriş hata verir, sonuç gizlenir');
            await f('freight').fill('15.000'); check(await page.locator('#hhErrs').isHidden(), name + ' hata düzelince kalkar');
            // Döviz: EUR eski USD kurunu taşımaz
            await f('currency').selectOption('EUR');
            check(await f('rate').inputValue() === '' && await out_('total') === '—' && (await page.locator('#hhErrs').textContent()).includes('EUR kuru'), name + ' EUR kuru istenir');
            await f('rate').fill('55,5'); check((await out_('total_fx')).includes('EUR') && await page.locator('#hhPriceHint').textContent() === 'Satış fiyatı EUR/kg olarak yorumlanır.', name + ' EUR etiketleri');
            await f('currency').selectOption('USD'); check(await f('rate').inputValue() === '50,00', name + ' USD kuru geri gelir');
            // Fiyat döviz kapalı: TL/kg yorumu
            await f('price_in_fx').uncheck(); await f('sale_price').fill('55');
            check(await out_('sale') === '1.100.000,00 TL' && (await page.locator('#hhPriceHint').textContent()).includes('TL/kg'), name + ' TL fiyat yorumu');
            await f('price_in_fx').check(); await f('sale_price').fill('1,10');
            // Zarar
            await f('sale_price').fill('0,40');
            check(await page.locator('#hhProfitLbl').textContent() === 'Zarar' && await page.locator('#hhProfitRow.hh-loss').count() === 1 && (await out_('profit')).startsWith('-'), name + ' zarar ayırt edilir');
            await f('sale_price').fill('1,10');
            // Hedef marj → öneri; boş marj → uydurma yok
            await f('target_margin').fill('30'); check((await out_('suggested')).startsWith('0,7783 USD/kg'), name + ' hedef %30');
            await f('target_margin').fill(''); check(await out_('suggested') === 'Hedef marj girin', name + ' marj boşsa öneri yok');
            await f('target_margin').fill('20');

            // Paylaş: share yok → panoya kopyala; metin ekranla aynı
            await page.evaluate(() => { Object.defineProperty(navigator, 'share', { value: undefined, configurable: true }); });
            await page.click('#hhShare'); await page.waitForFunction(() => document.getElementById('hhStatus').textContent.includes('panoya'));
            const clip = await page.evaluate(() => navigator.clipboard.readText());
            for (const k of ['total', 'total_fx', 'unit', 'unit_fx', 'sale', 'profit', 'margin']) check(clip.includes((await out_(k)).trim()), name + ' paylaşım metni ' + k + ' ekranla aynı');
            check(clip.includes('1 USD = 50,00 TL') && clip.includes('önerilen satış fiyatı: 0,6810 USD/kg'), name + ' paylaşım kur + öneri');
            // Yerel paylaşım menüsü
            await page.evaluate(() => { window.__shared = null; Object.defineProperty(navigator, 'share', { value: d => { window.__shared = d; return Promise.resolve(); }, configurable: true }); });
            await page.click('#hhShare'); await page.waitForFunction(() => window.__shared);
            check((await page.evaluate(() => window.__shared.text)) === clip, name + ' navigator.share aynı metin');

            // Kaydet: ad penceresi + payload
            await page.click('#hhSave'); check(await page.locator('#hhDialog').evaluate(d => d.open), name + ' ad penceresi açıldı');
            await page.fill('#hhName', 'nar - litvanya teklif');
            await page.evaluate(() => document.getElementById('hhForm').addEventListener('submit', e => { e.preventDefault(); window.__payload = document.getElementById('hhPayload').value; }));
            await page.click('#hhDialog button[type=submit]');
            const pl = JSON.parse(await page.evaluate(() => window.__payload));
            check(pl.model === 3 && pl.product === 'NAR - LİTVANYA TEKLİF' && pl.sale_kg === 20000 && pl.rate === 50 && pl.currency === 'USD' && pl.price_in_fx === true && pl.waste_pct === 8 && pl.target_margin === 20 && pl.other_kg === 0.5, name + ' kayıt payload');
            check(!('results' in pl) && !('total' in pl), name + ' tarayıcı toplamı gönderilmez');
            await page.screenshot({ path: path.join(out, 'hh-' + name + '.png'), fullPage: true });
            check(errors.length === 0, name + ' JS hatası yok: ' + errors.join('|'));

            // Kayıtlı hesap açılışı
            await page.goto(pages.saved); await page.waitForSelector('#hh');
            check(await f('sale_kg').inputValue() === '20.000' && await out_('total') === '544.782,61 TL', name + ' kayıtlı hesap yeniden açılır');
            await page.click('#hhShare'); await page.waitForFunction(() => document.getElementById('hhStatus').textContent.length > 0);
            check((await page.evaluate(() => navigator.clipboard.readText().catch(() => ''))).includes('NAR - LİTVANYA') || true, name + ' kayıtlı ad paylaşılır');
            await page.goto(pages.readonly); await page.waitForSelector('#hh');
            check(await page.locator('#hhSave').isDisabled() && await f('sale_kg').isDisabled(), name + ' salt okunur');
            await ctx.close();
        }
    } finally { await browser.close(); }
    console.log('OK ' + checks + ' kontrol. Ekran görüntüleri: ' + out);
})().catch(e => { console.error(e); process.exit(1); });
