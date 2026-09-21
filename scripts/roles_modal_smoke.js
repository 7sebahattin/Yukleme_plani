// =========================================================
// scripts/roles_modal_smoke.js — Roller modalinin GERÇEK TARAYICI testi
//
// Neden gerekli: "Yeni Rol" modalinde 51 yetki kutusu var ve ekrana
// sığmıyor. .pm-dialog max-height:90vh + .pm-body overflow:auto kuralları
// kaynakta DOĞRU görünüyordu, ama araya giren <form> sarmalayıcı flex
// zincirini kırıyordu: gövde hiç kaydırılamıyor, alt yetki grupları ve
// Oluştur/İptal düğmeleri ekranın dışında kalıyordu. PHP/statik testler
// bunu göremez — yalnız düzen (layout) motoru gösterir.
//
//   php scripts/roles_modal_render.php > _test_roles.html
//   node scripts/roles_modal_smoke.js
//
// Playwright yoksa kendini ATLAR (beyan_js_smoke.js ile aynı kural).
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core',
                   '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) {
    console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).');
    process.exit(0);
}

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_roles.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_roles.html yok. Önce: php scripts/roles_modal_render.php > _test_roles.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(62)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET',   width: 820,  height: 1024 },
                         { ad: 'MOBİL',    width: 390,  height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        await page.goto('file://' + SAYFA);
        await page.waitForTimeout(400);   // açılış animasyonu (220ms) bitsin — erken ölçüm yanıltır

        const m = await page.evaluate(() => {
            const dlg  = document.querySelector('#rolCreateModal .pm-dialog');
            const body = document.querySelector('#rolCreateModal .pm-body');
            const foot = document.querySelector('#rolCreateModal .pm-footer');
            const r = el => { const b = el.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, height: b.height }; };
            return {
                vh: window.innerHeight,
                dialog: r(dlg), body: r(body), footer: r(foot),
                bodyScrollHeight: body.scrollHeight,
                bodyClientHeight: body.clientHeight,
            };
        });

        ok('modal ekranın dışına taşmıyor',
            m.dialog.bottom <= m.vh + 1,
            `dialog alt kenarı ${Math.round(m.dialog.bottom)}px, ekran ${m.vh}px`);

        ok('Oluştur/İptal düğmeleri ekran İÇİNDE',
            m.footer.bottom <= m.vh + 1 && m.footer.top >= -1 && m.footer.height > 0,
            `footer ${Math.round(m.footer.top)}–${Math.round(m.footer.bottom)}px, ekran ${m.vh}px`);

        ok('yetki gövdesi KAYDIRILABİLİR',
            m.bodyScrollHeight > m.bodyClientHeight + 4,
            `scrollHeight ${m.bodyScrollHeight} ≤ clientHeight ${m.bodyClientHeight} — gövde kısıtlanmamış`);

        // Gerçekten en alta kaydırıp son kutuyu tıklayabiliyor muyuz?
        await page.evaluate(() => {
            const b = document.querySelector('#rolCreateModal .pm-body');
            b.scrollTop = b.scrollHeight;
        });
        await page.waitForTimeout(80);

        const son = '#rolCreateModal input[value="users.admin"]';
        const gorunur = await page.evaluate((sel) => {
            const el = document.querySelector(sel);
            if (!el) return { bulundu: false };
            const b = el.getBoundingClientRect();
            const ust = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
            return {
                bulundu: true,
                ekranda: b.top >= 0 && b.bottom <= window.innerHeight,
                tiklanabilir: ust === el || el.contains(ust),
            };
        }, son);

        ok('son yetki kutusu (Kullanıcı ve rol yönetimi) görünür',
            gorunur.bulundu && gorunur.ekranda,
            'kaydırma sonrası hâlâ ekran dışında');
        ok('son yetki kutusu tıklanabilir (üstünü kapatan yok)',
            gorunur.tiklanabilir === true);

        if (gorunur.bulundu && gorunur.ekranda) {
            await page.check(son);
            ok('son yetki kutusu işaretlenebildi', await page.isChecked(son));
        } else {
            ok('son yetki kutusu işaretlenebildi', false, 'kutuya erişilemedi');
        }

        // Grup kısayolu ve varsayılan
        ok('varsayılan: "Ana sayfayı görüntüle" işaretli',
            await page.isChecked('#rolCreateModal input[value="dashboard.read"]'));

        await page.click('#rolCreateModal .rol-perm-group .rol-perm-toggle[data-mode="all"] >> nth=0');
        ok('grup "Tümü" kısayolu çalışıyor',
            await page.isChecked('#rolCreateModal input[value="records.unlock"]'));

        // Yatay taşma olmamalı (mobil kuralı)
        const yatay = await page.evaluate(() =>
            document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
        ok('yatay kaydırma (taşma) YOK', !yatay);

        await page.close();
    }

    await browser.close();
    console.log('\n' + (hata === 0 ? 'TÜMÜ GEÇTİ' : `${hata} TEST BAŞARISIZ`));
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error('Test çalıştırılamadı:', e.message); process.exit(1); });
