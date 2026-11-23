'use strict';
// v507 — Kullanıcılar ekranı: sayfa sayfa yetki özeti (yetki yalnız rolden gelir).
// Önce: php scripts/users_ozet_render.php > _test_users.html
// Playwright yoksa kendini ATLAR değil, çıkış 1 verir (roles_modal_smoke.js ile aynı).
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* sonraki */ }
}
if (!chromium) { console.error('Playwright bulunamadı.'); process.exit(1); }
const root = path.dirname(__dirname);
const html = process.env.USERS_HTML_PATH || path.join(root, '_test_users.html');
if (!fs.existsSync(html)) { console.error('HTML yok: php scripts/users_ozet_render.php > _test_users.html'); process.exit(1); }
const results = [];
function ok(name, pass, detail) { results.push({ name, pass: !!pass, detail }); console.log((pass ? 'OK ' : 'FAIL ') + name + (pass || !detail ? '' : ' — ' + detail)); }
(async () => {
    const executablePath = process.env.PW_EXECUTABLE_PATH || ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(executablePath ? { executablePath } : {});
    try {
        for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
            const id = ' [' + viewport.width + ']';
            const page = await browser.newPage({ viewport });
            const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            await page.goto(pathToFileURL(html).href);
            const satir = ad => page.locator('[data-usr-ozet]:visible').filter({ has: page.locator('xpath=self::*[@data-usr-ad="' + ad + '"]') }).first();
            const ozet = async ad => {
                await page.locator('button[data-usr-ozet][data-usr-ad="' + ad + '"]:visible').first().click();
                await page.waitForTimeout(350);
                return page.locator('#usrOzetGovde');
            };
            const satirlar = g => g.locator('.usr-ozet-satir');

            // Eski (işaretleyicisiz) rol: sayfa anahtarları kartlara genişler
            let g = await ozet('Örnek Operatör');
            ok('Yetki düğmesi özet penceresini açar' + id, await page.locator('#usrOzetModal').isVisible() && (await g.textContent()).includes('Örnek Operatör'));
            ok('Eski rol: Beyanlar ve OGL Siparişler açık listelenir' + id, await satirlar(g).filter({ hasText: 'Beyanlar' }).count() >= 1 && await satirlar(g).filter({ hasText: 'OGL Siparişler' }).count() >= 1);
            ok('Eski rol: records.write → Hal Bildirimi tam (uyumluluk) · Hızlı Maliyet kapalı' + id, await satirlar(g).filter({ hasText: 'Hal Bildirimi' }).count() === 1 && (await g.locator('.usr-ozet-kapali').textContent()).includes('Hızlı Maliyet'));
            const ogl = satirlar(g).filter({ hasText: 'OGL Siparişler' }).first();
            ok('Satırda Gör/Ekle/Düzenle/Sil durumu ve düzey rozeti var' + id, await ogl.locator('.usr-ozet-e').count() === 4 && await ogl.locator('.rolx-cip').count() === 1 && await ogl.locator('.usr-ozet-e-var').count() >= 2);
            ok('Yetki kaynağı rol adı bağlantısı roles.php\'ye gider' + id, (await g.locator('.usr-ozet-roller a').first().getAttribute('href')).startsWith('roles.php?duzenle='));
            await page.keyboard.press('Escape');
            await page.locator('[data-usr-ozet-kapat]').first().click().catch(() => {});

            // Yeni düzenleyiciden kaydedilmiş rol: yalnız seçilenler
            await page.evaluate(() => document.getElementById('usrOzetModal').setAttribute('hidden', ''));
            g = await ozet('Örnek Yükleme');
            const yog = satirlar(g).filter({ hasText: 'OGL Siparişler' }).first();
            const txt = (await yog.textContent()) || '';
            ok('Yeni rol: OGL yalnız Gör (Ekle/Düzenle/Sil kapalı)' + id, await yog.locator('.usr-ozet-e-var').count() === 1 && txt.includes('Gör'));
            ok('Yeni rol: kısmi içerik "Görüntüleme" düzeyi' + id, /Görüntüleme/.test(txt));
            ok('Yeni rol: açılmayan sayfa Beyanlar kapalı listede' + id, (await g.locator('.usr-ozet-kapali').textContent()).includes('Beyanlar'));
            ok('Siparişler (ürün) sayfası tam yetkiyle listelenir' + id, /Tam/.test(await satirlar(g).filter({ hasText: /^Siparişler/ }).first().textContent()));

            // Birden çok rol: birleşim
            await page.evaluate(() => document.getElementById('usrOzetModal').setAttribute('hidden', ''));
            g = await ozet('Çok Rollü');
            ok('Çok rollü: iki rol adı birleşik gösterilir' + id, (await g.locator('.usr-ozet-roller a').count()) === 2);
            ok('Çok rollü: birleşimde OGL tam eski rolden gelir (Düzenle açık)' + id, await satirlar(g).filter({ hasText: 'OGL Siparişler' }).first().locator('.usr-ozet-e-var').count() >= 3);

            // Yönetici
            await page.evaluate(() => document.getElementById('usrOzetModal').setAttribute('hidden', ''));
            g = await ozet('Test Yönetici');
            ok('Yönetici: tüm sayfalar notu + her sayfa Tam' + id, (await g.textContent()).includes('Sistem Yöneticisi rolü') && !(await g.locator('.usr-ozet-kapali').count()));

            // Rolsüz yetki
            await page.evaluate(() => document.getElementById('usrOzetModal').setAttribute('hidden', ''));
            g = await ozet('Rolsüz Yetki');
            ok('Yetkisiz rol: "hiçbir sayfayı açmıyor" notu' + id, (await g.textContent()).includes('hiçbir sayfayı açmıyor'));
            await page.evaluate(() => document.getElementById('usrOzetModal').setAttribute('hidden', ''));

            // Düzen
            const taşma = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
            ok('Sayfa yatay taşmaz' + id, !taşma);

            // Yeni kullanıcı penceresinde canlı özet: rol seçince güncellenir
            await page.evaluate(() => window.usrOpenCreate());
            await page.waitForTimeout(350);
            const prev = page.locator('#usrCreateModal .usr-permission-preview');
            ok('Yeni kullanıcı: rol seçilmeden özet "rol seçilmedi" der' + id, (await prev.textContent()).includes('Rol seçilmedi'));
            await page.locator('#usrCreateModal input[name="roles[]"]').nth(1).check();
            ok('Rol seçilince özet güncellenir (satırlar çizilir)' + id, await prev.locator('.usr-ozet-satir').count() > 0);
            await page.locator('#usrCreateModal input[name="roles[]"]').nth(1).uncheck();
            ok('Rol kaldırılınca özet boşalır' + id, (await prev.textContent()).includes('Rol seçilmedi'));
            ok('Tarayıcı JavaScript hatası yok' + id, errors.length === 0, errors.join(';'));
            await page.close();
        }
    } finally { await browser.close(); }
    const failed = results.filter(r => !r.pass);
    console.log(JSON.stringify({ checks: results.length, passed: results.length - failed.length, failed: failed.length }));
    process.exit(failed.length ? 1 : 0);
})().catch(e => { console.error(e.stack); process.exit(1); });
