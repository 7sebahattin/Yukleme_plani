'use strict';
// Hızlı Maliyet v2 tarayıcı testi. Anonim statik fixture (quick_cost_page_smoke.php --render v2*); canlı uygulama/DB yok.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '..');
let playwright;
for (const c of [process.env.PW_PATH, 'playwright', 'playwright-core']) { if (!c) continue; try { playwright = require(c); break; } catch (_) {} }
if (!playwright) { console.log('SKIP Playwright yok'); process.exit(0); }
const out = process.env.QUICK_COST_QA_OUT || fs.mkdtempSync(path.join(os.tmpdir(), 'quick-cost-v2-'));
function fixture(variant) {
    const html = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'quick_cost_page_smoke.php'), '--render', variant], { cwd: root, encoding: 'utf8' });
    const target = path.join(out, variant + '.html');
    fs.writeFileSync(target, html.replace('<head>', '<head><base href="' + pathToFileURL(root + path.sep).href + '">'));
    return pathToFileURL(target).href;
}
const pages = { saved: fixture('v2'), fresh: fixture('v2new'), readonly: fixture('v2readonly') };
let checks = 0;
function check(v, label) { assert.ok(v, label); checks++; }
(async () => {
    const browser = await playwright.chromium.launch({ headless: true });
    try {
        for (const [name, width, height] of [['phone', 390, 844], ['small', 320, 700], ['tablet', 800, 1000], ['desktop', 1280, 900]]) {
            const ctx = await browser.newContext({ viewport: { width, height }, isMobile: width < 768, hasTouch: width < 768 });
            const page = await ctx.newPage(), errors = [];
            page.on('pageerror', e => errors.push(e.message));
            page.on('dialog', d => d.accept());
            const outText = key => page.locator('[data-qc2-out="' + key + '"]').first().textContent();
            const field = p => page.locator('[data-qc2="' + p + '"]');
            const rowEl = code => page.locator('[data-qc2-row="' + code + '"]');
            async function load(url) { await page.goto(url); await page.waitForSelector('#qc-form[data-qc-ready="1"]'); }
            async function noOverflow(label) { check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' ' + label + ' no horizontal overflow'); }
            async function blur() { await page.locator('#qc2-product').focus(); }

            await load(pages.saved);
            check(await outText('total') === '713.000,00', name + ' total 713.000,00');
            check(await outText('unit_cost') === '35,6500', name + ' export unit 35,65');
            check(await outText('yield_pct') === '%80,00', name + ' yield %80');
            check(await outText('export_kg') === '20.000', name + ' export kg');
            check((await outText('method_note')).includes('İhtiyatlı'), name + ' prudent method labelled');
            check(await page.locator('[data-qc2-set="method"][data-value="quick"]').getAttribute('aria-pressed') === 'true', name + ' quick view default');
            check(await page.locator('[data-qc2-detail]:visible').count() === 0, name + ' quick view hides detailed-only blocks');
            check(await rowEl('harvest').isHidden() && await rowEl('raw_purchase').isVisible(), name + ' quick view shows essential rows only');
            check(await page.locator('.qc-page .btn, .qc-workspace .btn, .qc2-seg button, .qc-row-settings summary').evaluateAll(n => n.filter(x => x.offsetParent).every(x => x.getBoundingClientRect().height >= 43.5)), name + ' touch targets >= 44px');
            check(await page.locator('input[inputmode="decimal"]').evaluateAll(n => n.filter(x => x.offsetParent).every(x => parseFloat(getComputedStyle(x).fontSize) >= 16)), name + ' numeric inputs 16px (no iOS zoom)');
            await noOverflow('quick');

            // Detaylı görünüme geçiş veri kaybetmez
            await page.locator('[data-qc2-set="method"][data-value="detailed"]').click();
            check(await rowEl('harvest').isVisible() && await field('quantities.domestic_kg').inputValue() === '3.000', name + ' detailed shows distribution with data');
            check(await outText('unclassified_kg') === '0 KG', name + ' distribution balanced');
            await field('quantities.waste_kg').fill('1.500'); await blur();
            check(await outText('unclassified_kg') === '500 KG', name + ' unclassified updates');
            check(await page.locator('.qc2-warnings li[data-code="unclassified"]').count() === 1, name + ' unclassified warning');
            await field('quantities.waste_kg').fill('2.001'); await blur();
            check(await outText('total') === '—' && !(await page.locator('#qc-validation').isHidden()) && await page.locator('#qc-save').isDisabled(), name + ' over-distribution blocks save');
            await field('quantities.waste_kg').fill('2.000'); await blur();
            await page.locator('[data-qc2-set="method"][data-value="quick"]').click();
            await page.locator('[data-qc2-set="method"][data-value="detailed"]').click();
            check(await field('quantities.waste_kg').inputValue() === '2.000' && await outText('total') === '713.000,00', name + ' quick/detailed roundtrip keeps values');
            await noOverflow('detailed');

            // Yan ürün kredisi yalnız açıkça seçilince
            await field('credit.domestic_sold_kg').fill('3.000'); await field('credit.domestic_price').fill('8'); await blur();
            check(await outText('unit_cost') === '35,6500' && await outText('credit_total') === '24.000,00 TL', name + ' credit not applied until chosen');
            await page.locator('[data-qc2-bool="credit.apply"]').check();
            check(await outText('unit_cost') === '34,4500' && await outText('export_cost') === '689.000,00 TL' && await outText('total') === '713.000,00', name + ' explicit credit gives 689.000 / 34,45');
            check((await outText('method_note')).includes('yönetimsel'), name + ' credit method labelled as management estimate');
            await page.locator('[data-qc2-bool="credit.apply"]').uncheck();

            // v473: kaldırılan kalemler ve yeni adlar
            const bodyText = await page.locator('#qc-form').innerText();
            check(await page.locator('[data-qc2-row="sorting_labor"], [data-qc2-row="grading"], [data-qc2-row="loading"], [data-qc2-row="cold_storage"], [data-qc2-row="fason_service"], .qc2-fason').count() === 0, name + ' removed rows/section are gone');
            check(await rowEl('overhead').locator('[data-qc2-title]').textContent() === 'İşletme gideri' && await rowEl('packing_labor').count() === 1 && await page.locator('[data-qc2-row="factory_cost"]').count() === 0, name + ' İşletme gideri + Paketleme işçiliği present, Fabrika gideri gone');
            check(/Çıkma KG/.test(bodyText) && /Suluk KG/.test(bodyText) && /Çöp KG/.test(bodyText) && !/İç piyasa|Sanayi \/ meyve|Kullanılamayan|Ayıklama|Boylama|Fason işleme/.test(bodyText), name + ' Çıkma/Suluk/Çöp names, no old names');
            check(await page.locator('[data-qc2-set="operation_type"]').count() === 0 && !/Fason|Üretim/.test(bodyText.replace(/Fason işleme/g, '')), name + ' Fason/Üretim control removed');
            check(!/andıman/.test(bodyText) && /Verimlilik/.test(bodyText), name + ' Randıman renamed to Verimlilik');
            check(await rowEl('packing_labor').locator('[data-qc2-item="basis_unit"]').inputValue() === 'raw_kg|kg', name + ' packing labor defaults to raw KG base');
            const totalBox = await page.locator('.qc2-total-card').boundingBox(), saleBox = await page.locator('.qc2-sale').boundingBox(), heroBox = await page.locator('.qc2-hero').boundingBox();
            check(totalBox && saleBox && totalBox.y >= saleBox.y + saleBox.height - 1 && totalBox.y > heroBox.y + heroBox.height && await page.locator('.qc2-hero [data-qc2-out="total"]').count() === 0, name + ' total card sits below Satış karşılaştırması');

            // Malzeme: Palet detayı düğmesi (Ayar'ın altında) + panel
            const matBtn = rowEl('material').locator('[data-qc2-mat-toggle]'), panel = page.locator('[data-qc2-matpanel]');
            check(await matBtn.isVisible() && await panel.isHidden() && await page.locator('[data-qc2-mat-toggle]').count() === 1, name + ' Palet detayı button only on Malzeme, panel closed');
            if (width >= 1024) {
                check(await page.evaluate(() => { const r = document.querySelector('[data-qc2-row="material"]'), a = r.querySelector('[data-qc2-settings] summary').getBoundingClientRect(), b = r.querySelector('[data-qc2-mat-toggle]').getBoundingClientRect(); return b.top >= a.bottom - 1 && Math.abs(b.right - a.right) < 8; }), name + ' Palet detayı sits right under Ayar');
            }
            await matBtn.click();
            check(await panel.isVisible() && await matBtn.getAttribute('aria-expanded') === 'true' && await panel.locator('.qc2-mat-line').count() === 9, name + ' panel opens with nine material lines');
            check(await panel.locator('.qc2-mat-name').allTextContents().then(t => t.join('|') === 'PALET|ŞAPKA|KÖŞEBENT|KASA|VİYOL|KENAR KARTONU|TABAN KAĞIDI|ŞERİT|FİLE'), name + ' material line names/order');
            await field('material_detail.pallets').fill('26'); await blur();
            check(!(await page.locator('[data-qc2-bool="material_detail.use"]').isChecked()) && await outText('total') === '713.000,00', name + ' pallet count alone does not switch material to detail');
            const mline = c => page.locator('[data-qc2-mline="' + c + '"]');
            await mline('mat_pallet').locator('[data-qc2-mat="qty"]').fill('1'); await mline('mat_pallet').locator('[data-qc2-mat="price"]').fill('300'); await blur();
            check(await page.locator('[data-qc2-bool="material_detail.use"]').isChecked() && await rowEl('material').locator('[data-qc2-amount]').textContent() === '7.800,00 TL' && await outText('total') === '640.800,00', name + ' first real entry auto-enables detail: 26 × 300 = 7.800');
            check(await rowEl('material').locator('[data-qc2-item="unit_price"]').isDisabled() && /Palet detayından/.test(await rowEl('material').locator('[data-qc2-hint]').textContent()), name + ' main material price is locked while detail drives it');
            await mline('mat_corner').locator('[data-qc2-mat="qty"]').fill('4'); await mline('mat_corner').locator('[data-qc2-mat="price"]').fill('12,5'); await blur();
            check(await mline('mat_corner').locator('[data-qc2-mat-out]').textContent() === '50,00 TL' && await outText('mat.per_pallet') === '350,00 TL' && await outText('mat.total') === '9.100,00 TL' && await outText('total') === '642.100,00', name + ' per-pallet cost 350 and total 9.100 update live');
            await page.locator('[data-qc2-mat-add]').click();
            const custom = page.locator('[data-qc2-mline^="mat_custom_"]');
            check(await custom.count() === 1 && await custom.locator('[data-qc2-mat="label"]').evaluate(n => n === document.activeElement), name + ' custom material line added and focused');
            check(await outText('total') !== '—' && await outText('mat.per_pallet') === '350,00 TL' && await custom.locator('[data-qc2-mat-out]').textContent() === '0,00 TL', name + ' a blank new material line does not break the totals');
            check(await page.locator('.qc2-mat-line output').evaluateAll(n => n.every(o => { const l = o.closest('.qc2-mat-line').getBoundingClientRect(), r = o.getBoundingClientRect(); return r.left >= l.left && r.right <= l.right + 1; })), name + ' line outputs stay inside their line (no stray table-grid placement)');
            await custom.locator('[data-qc2-mat="label"]').fill('etiket'); await custom.locator('[data-qc2-mat="qty"]').fill('0,5'); await custom.locator('[data-qc2-mat="price"]').fill('0,333'); await blur();
            check(await custom.locator('[data-qc2-mat="label"]').inputValue() === 'ETİKET' && await outText('mat.per_pallet') === '350,17 TL' && await outText('mat.total') === '9.104,42 TL', name + ' custom line (uppercased) rounds per cent: 350,17 × 26 = 9.104,42');
            await noOverflow('material panel open');
            const dpayload = await (async () => { await page.evaluate(() => document.getElementById('qc-form').addEventListener('submit', e => e.preventDefault())); await page.locator('#qc-save').click(); return JSON.parse(await page.locator('#qc-payload').inputValue()); })();
            check(dpayload.material_detail.use === true && dpayload.material_detail.pallets === 26 && dpayload.material_detail.lines.length === 10 && dpayload.material_detail.lines[9].label === 'ETİKET' && !('fason_includes' in dpayload), name + ' payload carries material_detail, no fason_includes');
            await custom.locator('[data-qc2-mat-remove]').click();
            check(await page.locator('[data-qc2-mline^="mat_custom_"]').count() === 0 && await outText('mat.per_pallet') === '350,00 TL', name + ' custom line removed');
            await page.locator('[data-qc2-bool="material_detail.use"]').uncheck();
            check(await outText('total') === '713.000,00' && !(await rowEl('material').locator('[data-qc2-item="unit_price"]').isDisabled()), name + ' unchecking returns to the row price (4 TL/kg)');
            await matBtn.click();
            check(await panel.isHidden() && await matBtn.getAttribute('aria-expanded') === 'false', name + ' panel closes');

            // Taban değişimi: eski miktar taşınmaz
            await rowEl('overhead').locator('summary').click();
            await rowEl('overhead').locator('[data-qc2-item="basis_unit"]').selectOption('manual|pallet');
            check(await rowEl('overhead').locator('[data-qc2-item="qty"]').inputValue() === '0' && await rowEl('overhead').locator('.qc-unit-note').count() === 1, name + ' basis change resets quantity with note');
            await rowEl('overhead').locator('[data-qc2-item="qty"]').fill('26');
            await rowEl('overhead').locator('[data-qc2-item="unit_price"]').fill('380'); await blur();
            check(await rowEl('overhead').locator('[data-qc2-amount]').textContent() === '9.880,00 TL' && await outText('total') === '722.880,00', name + ' pallet row 26 × 380');
            await rowEl('overhead').locator('[data-qc2-item="basis_unit"]').selectOption('manual|worker_day');
            check(await rowEl('overhead').locator('[data-qc2-qty2-wrap]').isVisible() && await rowEl('overhead').locator('[data-qc2-item="unit_price"]').inputValue() === '0', name + ' worker × day shows second factor, price reset');
            await rowEl('overhead').locator('[data-qc2-item="basis_unit"]').selectOption('fixed|fixed');
            await noOverflow('settings open');

            // Verimlilik ters hesabı
            await page.locator('[data-qc2-set="yield_mode"][data-value="target"]').click();
            check(await field('target_yield').inputValue() === '80' && await outText('required_raw') === '25.000 KG', name + ' target mode prefilled from actual yield');
            await field('credit.domestic_sold_kg').fill('0');
            await field('quantities.processed_kg').fill(''); await field('quantities.domestic_kg').fill('0'); await field('quantities.waste_kg').fill('0');
            await field('target_yield').fill('75,5'); await blur();
            check(await outText('required_raw') === '26.490,0662 KG', name + ' reverse yield 20.000 ÷ %75,5');
            await page.locator('[data-qc2-set="yield_mode"][data-value="actual"]').click();
            check(await field('quantities.raw_kg').inputValue() === '26.490,0662', name + ' back to actual keeps derived raw');

            // Satış karşılaştırması
            await page.locator('.qc2-sale > summary').click();
            await field('sale.price').fill('1,1'); await field('sale.currency').selectOption('USD');
            check(await page.locator('.qc2-rate').isVisible(), name + ' rate visible for USD');
            await field('sale.rate').fill('34,25'); await field('sale.target_margin').fill('20'); await blur();
            check((await outText('sale.revenue')).startsWith('753.500,00 TL') && (await outText('sale.margin')).startsWith('%'), name + ' USD sale comparison');
            await field('sale.currency').selectOption('TL');
            check(await page.locator('.qc2-rate').isHidden(), name + ' rate hidden for TL');
            await noOverflow('sale');

            // Kaydet gövdesi kanonik ve v2
            await page.evaluate(() => document.getElementById('qc-form').addEventListener('submit', e => e.preventDefault()));
            await page.locator('#qc-save').click();
            const payload = JSON.parse(await page.locator('#qc-payload').inputValue());
            check(payload.model === 2 && payload.method === 'detailed' && payload.total === undefined && payload.items.every(i => i.amount === undefined) && typeof payload.quantities.raw_kg === 'number', name + ' canonical v2 payload');
            if (width < 768) {
                const bar = await page.locator('.qc-save-bar').boundingBox(), nav = await page.locator('.bottomnav').boundingBox();
                check(bar.y + bar.height <= nav.y + 1, name + ' save bar sits above bottom navigation');
            }
            if (width >= 1024) check(await page.locator('.qc2-table-head').isVisible(), name + ' desktop table header');
            check(errors.length === 0, name + ' no page errors: ' + errors.join(' | '));

            // İnceleme bulgusu 1: gizlenen geçersiz miktar alanı formu kilitlememeli.
            await load(pages.saved);
            await page.locator('[data-qc2-set="method"][data-value="detailed"]').click();
            await rowEl('overhead').locator('summary').click();
            await rowEl('overhead').locator('[data-qc2-item="basis_unit"]').selectOption('manual|pallet');
            await rowEl('overhead').locator('[data-qc2-item="qty"]').fill('-1'); await blur();
            check(await page.locator('#qc-save').isDisabled(), name + ' invalid manual quantity blocks save');
            await rowEl('overhead').locator('[data-qc2-item="basis_unit"]').selectOption('fixed|fixed');
            check(!(await page.locator('#qc-save').isDisabled()) && await page.evaluate(() => document.getElementById('qc-form').checkValidity()), name + ' switching to fixed clears stale validity of hidden quantity');
            // İnceleme bulgusu 2: hedef yöntemine geçişte yuvarlanan verimlilik sessizce ham KG'yi değiştirmemeli.
            check(await page.locator('[data-qc2-yield-note]').isHidden(), name + ' no yield note initially');
            await page.locator('[data-qc2-set="yield_mode"][data-value="target"]').click();
            check(await page.locator('[data-qc2-yield-note]').isHidden(), name + ' exact %80 switch needs no rounding note');
            await page.locator('[data-qc2-set="yield_mode"][data-value="actual"]').click();
            await field('quantities.raw_kg').fill('30.000'); await field('quantities.processed_kg').fill('30.000');
            await field('quantities.domestic_kg').fill('10.000'); await field('quantities.waste_kg').fill('0'); await blur();
            check(await outText('yield_pct') === '%66,67', name + ' 2/3 yield shown');
            await page.locator('[data-qc2-set="yield_mode"][data-value="target"]').click();
            const yNote = page.locator('[data-qc2-yield-note]');
            check(await yNote.isVisible() && /yuvarlandı/.test(await yNote.textContent()) && /30\.000/.test(await yNote.textContent()), name + ' rounding change is announced, not silent');
            await page.locator('[data-qc2-set="yield_mode"][data-value="actual"]').click();
            check(await yNote.isHidden(), name + ' note clears when back to actual');

            await load(pages.fresh);
            check(await outText('total') === '0,00' && await page.locator('#qc-save').isDisabled() === false, name + ' new form starts at zero');
            await page.evaluate(() => document.getElementById('qc-form').addEventListener('submit', e => e.preventDefault()));
            await page.locator('#qc-save').click();
            check(await page.locator('#qc-payload').inputValue() === '', name + ' empty form (no product) is not submitted');
            await field('product').fill('domates'); await page.locator('#qc-save').click();
            check(await page.locator('#qc-payload').inputValue() === '' && (await page.locator('#qc-validation').textContent()).includes('ihracatlık net KG'), name + ' zero export kg is not submitted');
            await load(pages.readonly);
            check(await page.locator('#qc-save').count() === 0 && await field('quantities.raw_kg').isDisabled() && await outText('total') === '713.000,00', name + ' read-only');
            await page.locator('[data-qc2-set="method"][data-value="detailed"]').click();
            check(await rowEl('harvest').isVisible(), name + ' read-only can still switch view');
            if (name === 'phone' || name === 'desktop') {
                await load(pages.saved);
                await page.screenshot({ path: path.join(out, name + '.png'), fullPage: true });
            }
            await ctx.close();
        }
    } finally { await browser.close(); }
    console.log(JSON.stringify({ ok: true, checks, output: out }));
})().catch(e => { console.error(e); process.exit(1); });
