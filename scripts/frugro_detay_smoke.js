'use strict';
// scripts/frugro_detay_smoke.js — Frugro sipariş DETAYI tarayıcı kabulü (Playwright; dosya tabanlı, ağ/uygulama sunucusu YOK).
//   FRUGRO_UI_OUT=/tmp/frg php scripts/frugro_ui_render.php && FRUGRO_UI_OUT=/tmp/frg node scripts/frugro_detay_smoke.js
//   (FRUGRO_QA_DIR=<klasör> verilirse ekran görüntüleri oraya yazılır)
// 1440 / 820 / 390: yatay taşma yok, JS hatası yok, ≥1024 iki sütun / <768 tek sütun; pencereler açılır; UZUN mail penceresinde
// (CLAUDE.md "Modal içinde <form>") footer ekran içinde, gövde kaydırılıyor, en alttaki onay kutusu görünür + tıklanabilir
// (ölçümden önce 400 ms — açılış animasyonu); onaylar gönder düğmesini açar; metin düzenleme .eml/mailto bağlantısına yansır; ✎ doldurur.
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
if (!dir || !fs.existsSync(path.join(dir, 'detay_invoice_hazir.html'))) { console.error('Önce: FRUGRO_UI_OUT=<dir> php scripts/frugro_ui_render.php'); process.exit(1); }
const qa = process.env.FRUGRO_QA_DIR; if (qa) fs.mkdirSync(qa, { recursive: true });
let fail = 0, pass = 0;
const dusenler = [];
function ok(n, v, d) { if (v) { pass++; console.log('OK ' + n); } else { fail++; dusenler.push(n + (d ? ' — ' + d : '')); console.log('FAIL ' + n + (d ? ' — ' + d : '')); } }
const url = f => pathToFileURL(path.join(dir, f + '.html')).href;

(async () => {
    const browser = await chromium.launch();
    try {
        for (const vp of [{ width: 1440, height: 900 }, { width: 820, height: 1024 }, { width: 390, height: 844 }]) {
            const id = ' [' + vp.width + ']';
            const page = await browser.newPage({ viewport: vp });
            const errs = [];
            page.on('pageerror', e => errs.push(e.message));
            page.on('dialog', d => d.accept());
            for (const f of ['detay_yeni', 'detay_hazirlik', 'detay_pkl_hazir', 'detay_invoice_hazir', 'detay_yola_cikti', 'detay_mail_gonderildi', 'detay_evrak_gonderildi', 'detay_iptal', 'detay_kapali', 'detay']) {
                errs.length = 0;
                await page.goto(url(f)); await page.waitForTimeout(150);
                const ov = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                ok(f + ': yatay taşma yok, JS hatası yok' + id, ov <= 0 && errs.length === 0, 'taşma ' + ov + ' ' + errs.join(' | '));
            }
            await page.goto(url('detay_invoice_hazir')); await page.waitForTimeout(150);
            const kol = await page.evaluate(() => { const a = document.querySelector('#bilgi').getBoundingClientRect(), b = document.querySelector('#gecmis').getBoundingClientRect(); return { yanyana: b.left >= a.right - 1, alt: b.top >= a.bottom - 1 }; });
            if (vp.width >= 1024) ok('iki sütun: Geçmiş sağda' + id, kol.yanyana);
            if (vp.width < 768) ok('mobil tek sütun: Geçmiş altta' + id, kol.alt && !kol.yanyana);
            ok('adım şeridi 7 adım (masaüstü) / mobil adım metni' + id, (await page.locator('.sip-steps .sip-step').count()) === 7 && (vp.width >= 768 || await page.locator('.sip-adim-mobil').isVisible()));
            if (qa) await page.screenshot({ path: path.join(qa, 'detay_' + vp.width + '.png'), fullPage: true });

            // ✎ alan penceresi
            await page.locator('#bilgi [data-alan="parti_no"] .sip-edit').click(); await page.waitForTimeout(400);
            ok('✎ parti no: pencere açılır, değer dolu, alan adı formda' + id, await page.locator('#sipAlanModal').isVisible()
                && (await page.locator('#sipAlanModal input[data-sip-girdi="metin"]').inputValue()) === 'P-1002'
                && (await page.locator('#sipAlanModal input[name="alan"]').inputValue()) === 'parti_no');
            await page.locator('#sipAlanModal [data-sip-kapat]').first().click();

            // Mail gönderildi penceresi (uzun)
            await page.locator('[data-sip-ac="frgMailModal"]').click(); await page.waitForTimeout(400);
            const m = page.locator('#frgMailModal');
            const ol = await m.evaluate(el => { const d = el.querySelector('.pm-dialog').getBoundingClientRect(), f = el.querySelector('.pm-footer').getBoundingClientRect(), b = el.querySelector('.pm-body');
                return { fTop: f.top, fBot: f.bottom, vh: innerHeight, dBot: d.bottom, sh: b.scrollHeight, ch: b.clientHeight, ow: el.querySelector('.pm-dialog').scrollWidth - el.querySelector('.pm-dialog').clientWidth }; });
            ok('mail penceresi: footer ekran içinde' + id, ol.fBot <= ol.vh + 1 && ol.fTop >= 0, JSON.stringify(ol));
            ok('mail penceresi: yatay taşma yok' + id, ol.ow <= 0, JSON.stringify(ol));
            const gonder = m.locator('[data-sip-mail-gonder]');
            ok('mail: onaysız gönder pasif' + id, await gonder.isDisabled());
            const kutu = m.locator('input[name="onay_pkl"]');
            await kutu.scrollIntoViewIfNeeded();
            // Kaydırılabilirliği DOĞRUDAN ölç: onay kutusu taşan kısmın üstünde kalıp zaten görünürse scrollIntoViewIfNeeded hiç kaydırmaz
            // (CI yazı tipinde içerik gövdeden 28px uzun, kutu görünür → scrollTop 0 kalıp yanlış alarm veriyordu).
            const kay = await m.locator('.pm-body').evaluate(b => { const o = b.scrollTop; b.scrollTop = b.scrollHeight; const k = b.scrollTop; b.scrollTop = o; return Math.max(o, k); });
            ok('mail: gövde kaydırılıyor (uzun içerik) ya da sığıyor' + id, ol.sh <= ol.ch || kay > 0, JSON.stringify({ kay, sh: ol.sh, ch: ol.ch }));
            ok('mail: en alttaki onay kutusu görünür' + id, await kutu.isVisible());
            await kutu.click();
            ok('mail: onayla gönder düğmesi açıldı (tıklanabilir)' + id, await kutu.isChecked() && !(await gonder.isDisabled()));
            const ta = m.locator('textarea[data-sip-govde]');
            await ta.fill('Hi,\nDüzenlendi');
            const eml = await m.locator('a[data-sip-govde-eml]').getAttribute('href');
            const mt = await m.locator('a[data-sip-govde-mailto]').getAttribute('href');
            ok('mail: düzenlenen metin .eml (&govde=) ve mailto (body=) bağlantısına yansır' + id, eml.includes('frugro_eml.php') && eml.includes('&govde=') && mt.includes('body=Hi%2C%0D%0AD%C3%BCzenlendi'), eml + ' ' + mt.slice(0, 120));
            ok('mail: Kime/Bilgi satırları + Kopyala (ayardan, @ornek.test)' + id, (await m.locator('.sip-mail-satir').count()) === 3 && (await m.locator('.sip-mail-satir code').first().textContent()) === 'siparis@ornek.test');
            await m.locator('[data-sip-govde-sifirla]').click();
            ok('mail: Metni sıfırla ilk metne döner, govde parametresi kalkar' + id, !(await m.locator('a[data-sip-govde-eml]').getAttribute('href')).includes('govde='));
            if (qa && vp.width !== 820) { await m.locator('.pm-body').evaluate(b => { b.scrollTop = 0; }); await page.screenshot({ path: path.join(qa, 'mail_modal_' + vp.width + '.png') }); }
            await m.locator('.pm-footer [data-sip-kapat]').click();
            ok('mail: Vazgeç kapatır' + id, await m.isHidden());

            // Durumu değiştir: geri seçilince gerekçe zorunlu
            await page.locator('[data-sip-ac="frgDurumModal"]').click(); await page.waitForTimeout(400);
            const dm = page.locator('#frgDurumModal');
            await dm.locator('input[value="geri"]').check();
            ok('durum: "geri" seçilince gerekçe görünür + zorunlu' + id, await dm.locator('[data-frg-gerekce-kap]').isVisible() && await dm.locator('textarea[name="gerekce"]').evaluate(t => t.required));
            await dm.locator('[data-sip-kapat]').first().click();

            // Sil: ⋮ → pencere
            await page.locator('.pc-kebab').click(); await page.waitForTimeout(150);
            await page.locator('[data-sip-ac="frgSilModal"]').click(); await page.waitForTimeout(400);
            ok('⋮ → Sil penceresi açılır (onay kutusu zorunlu)' + id, await page.locator('#frgSilModal').isVisible() && await page.locator('#frgSilModal input[name="onay"]').evaluate(c => c.required));
            await page.keyboard.press('Escape');

            // Evrak takımı (kapanış) penceresi
            await page.goto(url('detay_mail_gonderildi')); await page.waitForTimeout(150);
            await page.locator('[data-sip-ac="frgEvrakModal"]').click(); await page.waitForTimeout(400);
            const em = page.locator('#frgEvrakModal');
            const eo = await em.evaluate(el => { const f = el.querySelector('.pm-footer').getBoundingClientRect(); return { fBot: f.bottom, vh: innerHeight }; });
            ok('evrak penceresi: footer ekran içinde' + id, eo.fBot <= eo.vh + 1, JSON.stringify(eo));
            const ek = em.locator('input[name="onay_evrak"]'); await ek.scrollIntoViewIfNeeded(); await ek.click();
            ok('evrak: onayla "Süreci kapat" açılır; .eml evrak bağlantısı' + id, !(await em.locator('[data-sip-evrak-gonder]').isDisabled()) && (await em.locator('a[data-sip-govde-eml]').getAttribute('href')).includes('tur=evrak'));

            // Gümrük beyanı kartı (bağlı / öneri / arama) — ölçümler yazı tipinden bağımsız (konum ilişkileri)
            await page.goto(url('detay_mail_gonderildi')); await page.waitForTimeout(150);
            const bk = await page.evaluate(() => { const c = document.querySelector('#beyan'); if (!c) return null; const yan = c.closest('.sip-col-yan'); const kayit = document.querySelector('#kayit');
                return { yanda: !!yan, h2: c.querySelector('h2').textContent, link: !!c.querySelector('a[href^="beyan_view.php"]'), kaldirAction: (c.querySelector('form[data-sip-onay] ')?.getAttribute('action')) || '', kaldirMetin: [...c.querySelectorAll('button')].some(b => b.textContent.trim() === 'Bağı kaldır'),
                    kayitUstunde: !!kayit && !!(c.compareDocumentPosition(kayit) & Node.DOCUMENT_POSITION_FOLLOWING), partiRozet: document.querySelectorAll('.sip-h1 .sip-parti-btn').length, ara: !!c.querySelector('input[name="beyan_q"]') }; });
            ok('beyan kartı: sağ sütunda, "Gümrük beyanı", Kayıt kartından ÖNCE' + id, !!bk && bk.yanda && bk.h2.includes('Gümrük beyanı') && bk.kayitUstunde, JSON.stringify(bk));
            ok('beyan kartı (bağlı): beyan_view bağlantısı + "Bağı kaldır" formu frugro_beyan.php + arama kutusu' + id, !!bk && bk.link && bk.kaldirMetin && bk.kaldirAction === 'frugro_beyan.php' && bk.ara, JSON.stringify(bk));
            ok('başlıkta 2 parti rozeti (sipariş + bağlı beyan)' + id, !!bk && bk.partiRozet === 2, JSON.stringify(bk));
            if (qa) await page.screenshot({ path: path.join(qa, 'beyan_' + vp.width + '.png'), fullPage: true });
            await page.goto(url('detay_yola_cikti')); await page.waitForTimeout(150);
            const bo = await page.evaluate(() => { const c = document.querySelector('#beyan'); return { oneri: [...c.querySelectorAll('h3')].some(h => h.textContent.trim() === 'Öneriler'), bagla: c.querySelectorAll('form[data-sip-tek] button').length, puan: c.querySelectorAll('.sip-puan').length, ov: document.documentElement.scrollWidth - document.documentElement.clientWidth }; });
            ok('beyan kartı (açık sipariş): "Öneriler" h3 + Bağla düğmesi + puan çubuğu, taşma yok' + id, bo.oneri && bo.bagla >= 1 && bo.puan >= 1 && bo.ov <= 0, JSON.stringify(bo));
            if (qa) await page.screenshot({ path: path.join(qa, 'beyan_oneri_' + vp.width + '.png'), fullPage: true });
            await page.goto(url('detay_beyan_ara')); await page.waitForTimeout(150);
            const ba = await page.evaluate(() => { const c = document.querySelector('#beyan'); const k = [...c.querySelectorAll('.sip-beyan-kart')].filter(x => x.textContent.includes('P-2001')); return { n: k.length, bagla: k.some(x => x.querySelector('form[data-sip-tek] button')), q: c.querySelector('input[name="beyan_q"]').value, ov: document.documentElement.scrollWidth - document.documentElement.clientWidth }; });
            ok('beyan arama sonucu kartı: P-2001 + Bağla; kutuda sorgu; taşma yok' + id, ba.n >= 1 && ba.bagla && ba.q === 'P-2001' && ba.ov <= 0, JSON.stringify(ba));
            if (qa) await page.screenshot({ path: path.join(qa, 'beyan_ara_' + vp.width + '.png'), fullPage: true });
            await page.close();
        }
    } finally { await browser.close(); }
    if (dusenler.length) console.log('\nDÜŞENLER (CI günlüğü son satırları gösterir):\n' + dusenler.map(x => '  FAIL ' + x).join('\n'));
    console.log('\n' + pass + ' geçti, ' + fail + ' kaldı');
    process.exit(fail ? 1 : 0);
})();
