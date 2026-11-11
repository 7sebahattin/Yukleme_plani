'use strict';
// scripts/frugro_pkl_smoke.js — Frugro PKL ekranı tarayıcı kabulü (Playwright; dosya tabanlı, ağ/uygulama sunucusu YOK).
//   FRUGRO_UI_OUT=/tmp/frg php scripts/frugro_ui_render.php && FRUGRO_UI_OUT=/tmp/frg node scripts/frugro_pkl_smoke.js
//   (FRUGRO_QA_DIR=<klasör> verilirse ekran görüntüleri oraya yazılır)
// 1440 / 820 / 390: yatay sayfa taşması yok, JS hatası yok; GGN adı seçilince no dolar; ↓ YALNIZ alttakileri değiştirir; Brüt hücresine çok satırlı
// yapıştırma satırları doldurur ("812.5" hiçbir şeyi değiştirmez); logger satırı sarı ve numara değişince yer değiştirir; kutu siparişten salt okunur;
// Excelden yapıştır penceresi (hedef sütun / 2 sütun kuralı); Ayarlar #ggn kartı. Ölçümler yazı tipine DAYANIKLI (doğrudan geometri/özellik).
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* sıradaki */ }
}
if (!chromium) { console.error('Playwright bulunamadı.'); process.exit(1); }
const dir = process.env.FRUGRO_UI_OUT;
if (!dir || !fs.existsSync(path.join(dir, 'pkl.html'))) { console.error('Önce: FRUGRO_UI_OUT=<dir> php scripts/frugro_ui_render.php'); process.exit(1); }
const qa = process.env.FRUGRO_QA_DIR; if (qa) fs.mkdirSync(qa, { recursive: true });
let fail = 0, pass = 0;
const dusenler = [];
function ok(n, v, d) { if (v) { pass++; console.log('OK ' + n); } else { fail++; dusenler.push(n + (d ? ' — ' + d : '')); console.log('FAIL ' + n + (d ? ' — ' + d : '')); } }
const url = f => pathToFileURL(path.join(dir, f + '.html')).href;
const SATIR = n => `tr[data-frp-satir]:nth-child(${n})`;

async function yapistir(page, sel, metin) {
    await page.evaluate(([s, m]) => {
        const el = document.querySelector(s); const dt = new DataTransfer(); dt.setData('text', m);
        el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    }, [sel, metin]);
}
const deger = (page, sel) => page.locator(sel).inputValue();

(async () => {
    const browser = await chromium.launch();
    try {
        for (const vp of [{ width: 1440, height: 900 }, { width: 820, height: 1024 }, { width: 390, height: 844 }]) {
            const id = ' [' + vp.width + ']';
            const page = await browser.newPage({ viewport: vp });
            const errs = [];
            page.on('pageerror', e => errs.push(e.message));
            page.on('dialog', d => d.accept());
            await page.goto(url('pkl')); await page.waitForTimeout(200);
            const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            ok('pkl: yatay sayfa taşması yok, JS hatası yok' + id, ov <= 0 && errs.length === 0, 'taşma ' + ov + ' ' + errs.join(' | '));
            const n = await page.locator('tr[data-frp-satir]').count();
            ok('26 palet satırı; palet no sütunu 1..N salt okunur (hidden girdi 1..26)' + id, n === 26
                && (await deger(page, SATIR(26) + ' input[data-frp-alan="palet_no"]')) === '26' && (await page.locator(SATIR(7) + ' [data-frp-sira]').textContent()) === '7');
            ok('GGN adı <select> (—, Uretici A, Uretici B); GGN no salt okunur' + id, (await page.locator(SATIR(1) + ' select[data-frp-alan="ggn_adi"] option').count()) === 3
                && (await page.locator(SATIR(1) + ' input[data-frp-alan="ggn_no"]').getAttribute('readonly')) !== null);
            ok('kutu siparişten salt okunur (data-frp-kp>0)' + id, parseInt(await page.locator('[data-frp-form]').getAttribute('data-frp-kp'), 10) > 0
                && (await page.locator(SATIR(1) + ' input[data-frp-alan="kutu"]').getAttribute('readonly')) !== null);

            // GGN adı seçilince no otomatik
            await page.selectOption(SATIR(5) + ' select[data-frp-alan="ggn_adi"]', 'Uretici B');
            ok('select seçilince GGN no otomatik dolar' + id, (await deger(page, SATIR(5) + ' input[data-frp-alan="ggn_no"]')) === '4069453419551');
            await page.selectOption(SATIR(5) + ' select[data-frp-alan="ggn_adi"]', '');
            ok('"—" seçilince GGN no boşalır' + id, (await deger(page, SATIR(5) + ' input[data-frp-alan="ggn_no"]')) === '');

            // ↓ yalnız alttakiler
            const once = [await deger(page, SATIR(2) + ' select[data-frp-alan="ggn_adi"]'), await deger(page, SATIR(3) + ' select[data-frp-alan="ggn_adi"]')];
            await page.selectOption(SATIR(4) + ' select[data-frp-alan="ggn_adi"]', 'Uretici B');
            await page.locator(SATIR(4) + ' [data-frp-asagi]').evaluate(el => el.click());
            const alt = await page.evaluate(() => Array.from(document.querySelectorAll('tr[data-frp-satir]')).map(tr => tr.querySelector('select[data-frp-alan="ggn_adi"]').value + '|' + tr.querySelector('input[data-frp-alan="ggn_no"]').value));
            ok('↓: 5..26. satırların hepsi Uretici B + no; 1..3 değişmedi' + id, alt.slice(4).every(x => x === 'Uretici B|4069453419551') && alt[3] === 'Uretici B|4069453419551'
                && alt[1].startsWith(once[0]) && alt[2].startsWith(once[1]) && alt[0] === 'Uretici A|4063651214000', JSON.stringify(alt.slice(0, 6)));
            ok('↓ düzenlemeyi "kirli" yapar (üret düğmeleri kapanır)' + id, await page.locator('[data-frp-uretbtn]').first().isDisabled());

            // hücreye çok satırlı yapıştırma
            await yapistir(page, SATIR(3) + ' input[data-frp-alan="brut_kg"]', '634\n631,5');
            ok('Brüt hücresine "634\\n631,5": 3. ve 4. satır dolar, 5. değişmez' + id, (await deger(page, SATIR(3) + ' input[data-frp-alan="brut_kg"]')) === '634'
                && (await deger(page, SATIR(4) + ' input[data-frp-alan="brut_kg"]')) === '631,5' && (await deger(page, SATIR(5) + ' input[data-frp-alan="brut_kg"]')) === '785');
            const oncekiB = await deger(page, SATIR(6) + ' input[data-frp-alan="brut_kg"]');
            await yapistir(page, SATIR(6) + ' input[data-frp-alan="brut_kg"]', '812.5');
            ok('"812.5" (nokta-ondalık) hiçbir hücreyi değiştirmez + hata mesajı' + id, (await deger(page, SATIR(6) + ' input[data-frp-alan="brut_kg"]')) === oncekiB && (await page.locator('[data-frp-durum].frp-durum--hata').count()) === 1);
            const sat = await page.locator('tr[data-frp-satir]').count();
            await yapistir(page, SATIR(25) + ' input[data-frp-alan="net_kg"]', '500\n501\n502\n503');
            ok('sonda fazla satır: satır EKLENMEZ, yalnız 2 hücre yazılır, uyarı görünür' + id, (await page.locator('tr[data-frp-satir]').count()) === sat
                && (await deger(page, SATIR(26) + ' input[data-frp-alan="net_kg"]')) === '501' && (await page.locator('[data-frp-durum].frp-durum--uyari').count()) === 1);
            await yapistir(page, SATIR(8) + ' input[data-frp-alan="net_kg"]', '600\t500');
            ok('Net hücresine 2 sütun reddedilir' + id, (await page.locator('[data-frp-durum].frp-durum--hata').count()) === 1 && (await deger(page, SATIR(8) + ' input[data-frp-alan="net_kg"]')) === '688');

            // logger satırı
            const bg = sel => page.locator(sel).evaluate(el => getComputedStyle(el).backgroundColor);
            const lgSayi = await page.locator('tr.frp-logger').count();
            ok('logger palet 2: yalnız 2. satır sarı (#fff3a0)' + id, lgSayi === 1 && (await page.locator(SATIR(2)).getAttribute('class')).includes('frp-logger') && (await bg(SATIR(2) + ' td:nth-child(2)')) === 'rgb(255, 243, 160)');
            await page.fill('[data-frp-logger]', '5');
            ok('logger palet 5: sarı 5. satıra geçer, 2. satır normal' + id, (await page.locator(SATIR(5)).getAttribute('class')).includes('frp-logger') && !((await page.locator(SATIR(2)).getAttribute('class')) || '').includes('frp-logger') && (await page.locator('tr.frp-logger').count()) === 1);
            await page.fill('[data-frp-logger]', '');
            ok('logger boş: hiç sarı satır yok' + id, (await page.locator('tr.frp-logger').count()) === 0);
            await page.fill('[data-frp-logger]', '2');

            // dosya üret formu: antet onay kutusu varsayılan seçili
            ok('antet onay kutusu var ve varsayılan SEÇİLİ' + id, await page.locator('input[name="antet"]').isChecked() && (await page.locator('.frp-onay').first().textContent()).includes('AsyaFresh logosu'));
            if (qa) await page.screenshot({ path: path.join(qa, 'pkl_' + vp.width + '.png') });

            // Excelden yapıştır penceresi
            await page.locator('[data-frp-ac="frpYapistirDlg"]').click(); await page.waitForTimeout(400);
            const d = await page.locator('#frpYapistirDlg').evaluate(el => { const f = el.querySelector('.pm-footer').getBoundingClientRect(), r = el.getBoundingClientRect(); return { open: el.open, fBot: f.bottom, dBot: r.bottom, vh: innerHeight, vw: innerWidth, dr: r.right }; });
            ok('yapıştır penceresi açık; footer ekran içinde; yatay taşma yok' + id, d.open && d.fBot <= d.vh + 1 && d.dr <= d.vw + 1, JSON.stringify(d));
            ok('pencerede Hedef sütun + Başlangıç satırı + önizleme; 6 sütun metni yok' + id, (await page.locator('[data-frp-yap-hedef] option').count()) === 2 && (await page.locator('[data-frp-yap-satir] option').count()) === 26
                && !(await page.locator('#frpYapistirDlg').textContent()).includes('GGN no | GGN adı'));
            await page.fill('[data-frp-yapistir-metin]', '634\t559\n631,5\t556');
            await page.selectOption('[data-frp-yap-hedef]', 'net_kg');
            ok('hedef Net + 2 sütun: önizleme hata verir, Uygula pasif' + id, await page.locator('[data-frp-yapistir-uygula]').isDisabled() && (await page.locator('[data-frp-yap-onizleme]').textContent()).includes('Hedef Net'));
            await page.selectOption('[data-frp-yap-hedef]', 'brut_kg');
            await page.selectOption('[data-frp-yap-satir]', '9');
            ok('hedef Brüt + 2 sütun: Uygula açık' + id, !(await page.locator('[data-frp-yapistir-uygula]').isDisabled()));
            if (qa && vp.width === 1440) await page.screenshot({ path: path.join(qa, 'yapistir_1440.png') });
            await page.locator('[data-frp-yapistir-uygula]').click(); await page.waitForTimeout(200);
            ok('Uygula: 10. ve 11. satır brüt+net dolar (başlangıç = 10. satır); pencere kapanır' + id, (await deger(page, SATIR(10) + ' input[data-frp-alan="brut_kg"]')) === '634' && (await deger(page, SATIR(11) + ' input[data-frp-alan="net_kg"]')) === '556'
                && !(await page.locator('#frpYapistirDlg').evaluate(el => el.open)));
            ok('toplamlar canlı güncellendi, JS hatası yok' + id, (await page.locator('[data-frp-n]').textContent()) === '26' && errs.length === 0, errs.join(' | '));
            await page.close();

            // Ayarlar #ggn kartı
            const a = await browser.newPage({ viewport: vp });
            const aerrs = []; a.on('pageerror', e => aerrs.push(e.message));
            await a.goto(url('ayarlar') + '#ggn'); await a.waitForTimeout(150);
            const ao = await a.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            const kart = await a.locator('#ggn').evaluate(el => { const r = el.getBoundingClientRect(); const ins = Array.from(el.querySelectorAll('input[name^="ggn["]')); return { sag: r.right, vw: innerWidth, n: ins.length, ilk: ins[0] && ins[0].value, ikinci: ins[1] && ins[1].value }; });
            ok('ayarlar #ggn: kart var, 2 kayıt + 5 boş satır (14 girdi), yatay taşma yok' + id, kart.n === 14 && kart.ilk === 'Uretici A' && kart.ikinci === '4063651214000' && ao <= 0 && kart.sag <= kart.vw + 1 && aerrs.length === 0, JSON.stringify(kart) + ao);
            ok('ayarlar: islem=ggn gizli alanı + CSRF; #fatura ve #pkl-mail bölümleri yerinde' + id, (await a.locator('#ggn input[name="islem"][value="ggn"]').count()) === 1 && (await a.locator('#ggn input[name="csrf"]').count()) === 1 && (await a.locator('#fatura').count()) === 1 && (await a.locator('#pkl-mail').count()) === 1);
            if (qa && vp.width === 1440) await a.locator('#ggn').screenshot({ path: path.join(qa, 'ayarlar_ggn_1440.png') });
            await a.close();
        }
    } finally { await browser.close(); }
    if (dusenler.length) console.log('\nDÜŞENLER (CI günlüğü son satırları gösterir):\n' + dusenler.map(x => '  FAIL ' + x).join('\n'));
    console.log('\n' + pass + ' geçti, ' + fail + ' kaldı');
    process.exit(fail ? 1 : 0);
})();
