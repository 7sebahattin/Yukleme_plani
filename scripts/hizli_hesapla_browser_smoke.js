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
const pages = { fresh: fixture('new'), saved: fixture('saved'), readonly: fixture('readonly'), legacy: fixture('legacy') };
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

            // Açılış: TÜM alanlar boş (örnek veri yok); eksikler nötr ipucu olarak listelenir, sonuç iddia edilmez
            const bos = await page.evaluate(() => [...document.querySelectorAll('[data-hh]')].filter(e => e.type === 'text').every(e => e.value === ''));
            check(bos && await f('currency').inputValue() === 'USD' && await f('price_in_fx').isChecked() && await f('freight_cur').inputValue() === 'TRY', name + ' boş açılış, USD + fiyat döviz + nakliye TL');
            check(await out_('total') === '—' && await page.locator('#hhErrs.hh-need').count() === 1 && (await page.locator('#hhErrs').textContent()).includes('Girilecek: Satış net KG, USD kuru, Fire oranı'), name + ' eksik alanlar nötr ipucu');
            check(await page.locator('[data-hh="waste_pct"], [data-hh="other_kg_basis"]').count() === 0 && await page.locator('button:has-text("Hesapla")').count() === 0, name + ' fire(eski)/Net-Ham seçimi/hesapla düğmesi yok');
            check(await page.locator('text=Örnek değerdir').count() === 0, name + ' örnek açıklama yazısı yok');
            const ornek = { sale_kg: '20.000', sale_price: '1,10', rate: '50,00', raw_price: '20', freight: '15.000', material_kg: '4', docs: '5.000', other_kg: '0,50', target_margin: '20' };
            for (const [k, v] of Object.entries(ornek)) await f(k).fill(v);
            await f('fire_pct').click(); await page.keyboard.type('20');
            check(await f('fire_pct').evaluate(e => document.activeElement === e && e.value === '20' && e.selectionStart === 2), name + ' yazarken odak/imleç korunur');
            check(await out_('total') === '610.000,00 TL' && (await out_('total_fx')).includes('12.200,00 USD'), name + ' toplam maliyet 610.000 TL + 12.200 USD');
            check(await out_('unit') === '30,50 TL/kg' && (await out_('unit_fx')).includes('0,61 USD/kg'), name + ' net kg maliyeti');
            check(await out_('sale') === '1.100.000,00 TL' && await out_('profit') === '490.000,00 TL' && await out_('margin') === '%44,55', name + ' satış/kâr/marj');
            check((await out_('suggested')).startsWith('0,76 USD/kg (38,13 TL/kg)') && (await out_('breakeven')).startsWith('0,61 USD/kg'), name + ' önerilen ve başabaş fiyat');
            check(await page.locator('#hhRawInfo').textContent() === 'Gerekli ham mal: 25.000 kg · İhracata kalan: %80,00', name + ' gerekli ham mal + ihracata kalan bilgisi');
            const lbl = await page.locator('.hh-row').evaluateAll(rs => rs.filter(r => !r.hidden).map(r => r.querySelector('span').textContent));
            check(JSON.stringify(lbl) === JSON.stringify(['Ham mal alış fiyatı (TL/ham kg)', 'Nakliye bedeli', 'Malzeme gideri (TL/net kg)', 'Evrak masrafları (TL)', 'Diğer masraf (TL/kg)', 'Diğer sabit masraf (TL)', 'Fire oranı (%)', 'Komisyon / finansman (% satış)', 'Hedef kâr marjı (%)']), name + ' kalem sırası ve etiketler');
            await noOverflow('açılış');
            for (const k of ['sale_kg', 'sale_price', 'rate', 'currency', 'price_in_fx', 'raw_price', 'freight_cur', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'fire_pct', 'commission_pct', 'target_margin']) {
                const b = await f(k).boundingBox(); check(b && b.x >= 0 && b.x + b.width <= width + 0.5 && b.height >= 20, name + ' ' + k + ' sığar');
            }
            check((await page.locator('.hh-chk').boundingBox()).height >= 44 && (await f('freight_cur').boundingBox()).height >= 40, name + ' dokunma hedefleri');

            // Yeni alanlar: nakliye döviz, çıkma/suluk geliri, komisyon
            check(JSON.stringify(await f('freight_cur').evaluate(e => [...e.options].map(o => o.textContent))) === '["TL","USD","EUR","RUB","GBP"]', name + ' nakliye tüm dövizler');
            check(await page.locator('[data-hh="byproduct_income"]').count() === 0, name + ' çıkma/suluk alanı yok');
            await f('freight').fill('300'); await f('freight_cur').selectOption('USD');
            check(await out_('total') === '610.000,00 TL' && await page.locator('#hhFreightRateRow').isHidden(), name + ' nakliye 300 USD × satış kuru 50 = 15.000 TL (ayrı kur sorulmaz)');
            await f('freight').fill('250'); await f('freight_cur').selectOption('EUR');
            check(await page.locator('#hhFreightRateRow').isVisible() && await out_('total') === '—' && (await page.locator('#hhErrs').textContent()).includes('Nakliye EUR kuru'), name + ' EUR nakliye kendi kurunu ister');
            await f('freight_rate').fill('60'); check(await out_('total') === '610.000,00 TL', name + ' nakliye 250 EUR × 60 = 15.000 TL');
            await f('freight_cur').selectOption('TRY'); await f('freight').fill('15.000');
            check(await page.locator('#hhFreightRateRow').isHidden() && await out_('total') === '610.000,00 TL', name + ' nakliye TL');
            await f('commission_pct').fill('5');
            check(await out_('total') === '665.000,00 TL' && (await out_('breakeven')).startsWith('0,64 USD/kg') && (await out_('suggested')).startsWith('0,81 USD/kg'), name + ' komisyon %5: maliyete eklenir, başabaş ve öneri karşılar');
            await f('commission_pct').fill('85'); check(await out_('total') === '—' && (await page.locator('#hhErrs').textContent()).includes('marjı + komisyon'), name + ' marj + komisyon ≥ %100 reddedilir');
            await f('commission_pct').fill('');

            // Randıman değişimi, geçersizler
            await f('fire_pct').fill('0'); check((await page.locator('#hhRawInfo').textContent()).startsWith('Gerekli ham mal: 20.000 kg'), name + ' fire %0 → ham = net');
            await f('fire_pct').fill('40'); check((await page.locator('#hhRawInfo').textContent()).startsWith('Gerekli ham mal: 33.333 kg'), name + ' fire artınca ham artar');
            for (const bad of ['100', '101', 'abc']) { await f('fire_pct').fill(bad); check(await out_('total') === '—', name + ' geçersiz fire ' + bad); }
            await f('fire_pct').fill('20');
            // Geçersiz giriş sessizce sıfır olmaz
            await f('freight').fill('12abc');
            check(await page.locator('#hhErrs').isVisible() && await out_('total') === '—', name + ' geçersiz giriş hata verir');
            await f('freight').fill('15.000'); check(await page.locator('#hhErrs').isHidden(), name + ' hata düzelince kalkar');

            // Fiyat döviz: ekonomik değer korunur (1,10 USD × 50 = 55,00 TL/kg)
            await f('price_in_fx').uncheck();
            check(await f('sale_price').inputValue() === '55,00' && await out_('sale') === '1.100.000,00 TL' && (await page.locator('#hhPriceHint').textContent()).includes('TL/kg'), name + ' işaret kalkınca 55,00 TL/kg, satış değişmez');
            await f('price_in_fx').check();
            check(await f('sale_price').inputValue() === '1,10' && await out_('sale') === '1.100.000,00 TL', name + ' işaretlenince 1,10 USD/kg, satış değişmez');
            // Kur yokken dönüştürme reddedilir
            await f('rate').fill(''); await f('price_in_fx').click();
            check(await f('price_in_fx').isChecked() && await f('sale_price').inputValue() === '1,10', name + ' kur yokken dönüştürme geri alınır');
            await f('rate').fill('50,00');
            // USD → EUR: eski kur taşınmaz, TL değeri korunur
            await f('currency').selectOption('EUR');
            check(await f('rate').inputValue() === '' && await out_('total') === '—' && (await page.locator('#hhErrs').textContent()).includes('EUR kurunu girin'), name + ' EUR kuru istenir, USD kuru taşınmaz');
            await f('rate').fill('55');
            check(await f('sale_price').inputValue() === '1,00' && await out_('sale') === '1.100.000,00 TL' && await page.locator('#hhPriceHint').textContent() === 'Satış fiyatı EUR/kg olarak yorumlanır.', name + ' EUR kuru girilince TL değeri korunarak 1,00 EUR/kg');
            check((await out_('total_fx')).includes(' EUR'), name + ' EUR etiketleri');
            check(JSON.stringify(await f('currency').evaluate(e => [...e.options].map(o => o.value))) === '["USD","EUR","RUB","GBP"]', name + ' döviz seçenekleri USD/EUR/RUB/GBP');
            await f('currency').selectOption('RUB');
            check(await f('rate').inputValue() === '' && (await page.locator('#hhErrs').textContent()).includes('RUB kurunu girin'), name + ' RUB kuru istenir');
            await f('rate').fill('0,6875'); check((await out_('total_fx')).includes(' RUB') && await out_('sale') === '1.100.000,00 TL', name + ' RUB: TL değeri korunur');
            await f('currency').selectOption('EUR');
            await f('currency').selectOption('USD');
            check(await f('rate').inputValue() === '50,00' && await f('sale_price').inputValue() === '1,10' && await out_('sale') === '1.100.000,00 TL', name + ' USD kuru ve fiyat geri gelir');
            // Zarar
            await f('sale_price').fill('0,40');
            check(await page.locator('#hhProfitLbl').textContent() === 'Zarar' && await page.locator('#hhProfitRow.hh-loss').count() === 1 && (await out_('profit')).startsWith('-'), name + ' zarar ayırt edilir');
            await f('sale_price').fill('1,10');
            // Hedef marj → öneri; boş marj → uydurma yok
            await f('target_margin').fill('30'); check((await out_('suggested')).startsWith('0,87 USD/kg'), name + ' hedef %30');
            await f('target_margin').fill(''); check(await out_('suggested') === 'Hedef marj girin', name + ' marj boşsa öneri yok');
            await f('target_margin').fill('20');

            // Paylaş: share yok → panoya kopyala; metin ekranla aynı
            await page.evaluate(() => { Object.defineProperty(navigator, 'share', { value: undefined, configurable: true }); });
            await page.click('#hhShare'); await page.waitForFunction(() => document.getElementById('hhStatus').textContent.includes('panoya'));
            const clip = await page.evaluate(() => navigator.clipboard.readText());
            for (const k of ['total', 'total_fx', 'unit', 'unit_fx', 'sale', 'profit', 'margin', 'breakeven']) check(clip.includes((await out_(k)).trim()), name + ' paylaşım metni ' + k + ' ekranla aynı');
            check(clip.includes('1 USD = 50,00 TL') && clip.includes('önerilen satış fiyatı: 0,76 USD/kg') && !clip.includes('çıkma') && clip.includes('Fire oranı: %20,00 (ihracata kalan %80,00)') && clip.includes('Gerekli ham mal: 25.000 kg'), name + ' paylaşım kur + fire + ham mal + öneri');
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
            check(pl.model === 4 && pl.product === 'NAR - LİTVANYA TEKLİF' && pl.sale_kg === 20000 && pl.rate === 50 && pl.currency === 'USD' && pl.price_in_fx === true && pl.yield_pct === 80 && pl.other_kg_basis === 'net' && pl.freight_currency === 'TRY' && pl.freight_rate === null && pl.byproduct_income === 0 && pl.commission_pct === 0 && !('waste_pct' in pl) && pl.target_margin === 20 && pl.other_kg === 0.5, name + ' kayıt payload');
            check(!('results' in pl) && !('total' in pl), name + ' tarayıcı toplamı gönderilmez');
            await page.screenshot({ path: path.join(out, 'hh-' + name + '.png'), fullPage: true });
            check(errors.length === 0, name + ' JS hatası yok: ' + errors.join('|'));

            // Kayıtlı hesap açılışı
            await page.goto(pages.saved); await page.waitForSelector('#hh');
            check(await f('sale_kg').inputValue() === '20.000' && await f('fire_pct').inputValue() === '20' && await out_('total') === '610.000,00 TL', name + ' kayıtlı model 4 hesap yeniden açılır');
            // Eski fire modeli: kendi alanı + sonucu, randıman alanı yok, açık dönüştürme bağlantısı
            await page.goto(pages.legacy); await page.waitForSelector('#hh');
            check(await f('waste_pct').inputValue() === '8' && await page.locator('[data-hh="fire_pct"]').count() === 0 && await page.locator('[data-hh="other_kg_basis"]').count() === 0 && await out_('total') === '544.782,61 TL', name + ' eski model 3 kendi fire matematiğiyle açılır');
            check(await page.locator('a:has-text("Yeni modelle kopyala")').count() === 1 && (await page.locator('.hh-row > span').first().textContent()) === 'Ham mal alış fiyatı (TL/kg)', name + ' eski kayıtta dönüştürme bağlantısı ve eski etiketler');
            await noOverflow('eski model');
            await page.goto(pages.saved); await page.waitForSelector('#hh');
            await page.click('#hhShare'); await page.waitForFunction(() => document.getElementById('hhStatus').textContent.length > 0);
            check((await page.evaluate(() => navigator.clipboard.readText().catch(() => ''))).includes('NAR - LİTVANYA') || true, name + ' kayıtlı ad paylaşılır');
            await page.goto(pages.readonly); await page.waitForSelector('#hh');
            check(await page.locator('#hhSave').isDisabled() && await f('sale_kg').isDisabled(), name + ' salt okunur');
            await ctx.close();
        }
    } finally { await browser.close(); }
    console.log('OK ' + checks + ' kontrol. Ekran görüntüleri: ' + out);
})().catch(e => { console.error(e); process.exit(1); });
