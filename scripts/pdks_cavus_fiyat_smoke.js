// =========================================================
// scripts/pdks_cavus_fiyat_smoke.js — cavus_fiyatlari.php (v297-A yeni
// görünüm) GERÇEK TARAYICI testi:
//   • 1280 ve 390 genişlikte yatay taşma yok, JS hatası yok
//   • tüm formlar ORİJİNAL alan adlarıyla duruyor (form=oran / cavus_yontem /
//     cavus_ucret, csrf, foreman_id, ...)
//   • "Kaç kişi-gün = 1 hakediş" kutusu YALNIZ Yöntem B seçiliyken görünür
//   • düğmeler gerçek düğme gibi görünür (arka plan ya da kenarlık var)
//   • mobilde girdiler 16px, tek kolon
//   • ekran görüntüleri (CAVUS_FIYAT_SHOT_DIR verilirse)
//
//   CAVUS_FIYAT_SENARYO=dolu php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat.html
//   CAVUS_FIYAT_SENARYO=bos  php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat_bos.html
//   node scripts/pdks_cavus_fiyat_smoke.js
// Playwright yoksa kendini ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const DOLU = path.join(ROOT, '_test_cavus_fiyat.html');
const BOS = path.join(ROOT, '_test_cavus_fiyat_bos.html');
for (const f of [DOLU, BOS]) {
    if (!fs.existsSync(f)) {
        console.error(path.basename(f) + ' yok. Önce: CAVUS_FIYAT_SENARYO=dolu|bos php scripts/pdks_cavus_fiyat_render.php > ' + path.basename(f));
        process.exit(1);
    }
}
const SHOT = process.env.CAVUS_FIYAT_SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(84)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    let dosya;
    if (u.pathname === '/' || u.pathname === '/dolu') dosya = DOLU;
    else if (u.pathname === '/bos') dosya = BOS;
    else dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

const ALANLAR = {
    oran: ['csrf', 'foreman_id', 'form', 'worker_type_id', 'daily_rate', 'half_day_rate', 'overtime_mode', 'overtime_rate', 'currency', 'valid_from'],
    cavus_yontem: ['csrf', 'foreman_id', 'form', 'cavus_yontem', 'cavus_birim'],
    cavus_ucret: ['csrf', 'foreman_id', 'form', 'cavus_daily_rate', 'cavus_currency', 'cavus_valid_from'],
};

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const senaryo of ['dolu', 'bos']) {
        for (const ekran of [{ ad: 'MASAÜSTÜ', kisa: 'pc', width: 1280, height: 900 }, { ad: 'MOBİL', kisa: 'mob', width: 390, height: 844 }]) {
            for (const tema of ['light', 'dark']) {
                if (tema === 'dark' && senaryo === 'bos') continue;
                console.log(`\n=== ${senaryo.toUpperCase()} · ${ekran.ad} (${ekran.width}) · ${tema} ===`);
                const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
                const jsHata = [];
                page.on('pageerror', e => jsHata.push(String(e)));
                page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) jsHata.push(m.text()); });
                await page.goto(KOK + senaryo, { waitUntil: 'load' });
                if (tema === 'dark') await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
                await page.waitForTimeout(150);

                const tasma = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                ok('yatay taşma yok', tasma <= 0, 'scrollWidth fazlası: ' + tasma);
                const tasanlar = await page.evaluate(() => {
                    const w = document.documentElement.clientWidth; const out = [];
                    document.querySelectorAll('.cf2 *').forEach(el => {
                        const r = el.getBoundingClientRect();
                        if (r.width > 0 && (r.right > w + 1 || r.left < -1) && !el.closest('.table-wrap')) out.push(el.tagName + '.' + el.className);
                    });
                    return out.slice(0, 5);
                });
                ok('.cf2 içinde ekrandan taşan öğe yok', tasanlar.length === 0, tasanlar.join(', '));

                // Formlar + orijinal alan adları
                for (const [ayirici, adlar] of Object.entries(ALANLAR)) {
                    const varMi = await page.evaluate(([ay, ad]) => {
                        const gizli = document.querySelector(`form input[type="hidden"][name="form"][value="${ay}"]`);
                        if (!gizli) return { form: false, eksik: ad };
                        const f = gizli.form;
                        return { form: true, method: (f.getAttribute('method') || '').toLowerCase(), eksik: ad.filter(n => !f.querySelector(`[name="${n}"]`)) };
                    }, [ayirici, adlar]);
                    ok(`form=${ayirici} var, POST, tüm alanlar orijinal adlarıyla`, varMi.form && varMi.method === 'post' && varMi.eksik.length === 0, JSON.stringify(varMi));
                }
                // v299: saat + Çift Yevmiye alanları (yalnız kolonlar kuruluyken)
                const SAAT_ALAN = ['full_day_saat', 'half_day_saat', 'overtime_start_saat', 'double_day_saat', 'double_day_rate'];
                const saatDurum = await page.evaluate((adlar) => {
                    const f = document.querySelector('form input[type="hidden"][name="form"][value="oran"]').form;
                    const kur = document.getElementById('cfSaatKurulum');
                    return { var: adlar.filter(n => f.querySelector(`[name="${n}"]`)).length,
                             tam: (f.querySelector('[name="full_day_saat"]') || {}).value || null,
                             kurulum: !!(kur && kur.getBoundingClientRect().height > 0),
                             gruplar: Array.from(f.querySelectorAll('[data-cf-grup]')).map(g => g.getAttribute('data-cf-grup')) };
                }, SAAT_ALAN);
                if (senaryo === 'dolu') {
                    ok('v299: 5 saat/Çift alanı formda', saatDurum.var === 5, JSON.stringify(saatDurum));
                    ok('v299: Tam saati çavuşun normal süresiyle (9) önceden dolu', saatDurum.tam === '9', String(saatDurum.tam));
                    ok('v299: gruplar Tam / Yarım / FM / Çift sırasıyla', saatDurum.gruplar.join(',') === 'tam,yarim,fm,cift', saatDurum.gruplar.join(','));
                    const gecmis = await page.locator('#cfFiyatGecmisi').innerText();
                    ok('v299: fiyat geçmişinde saatler + çift ücret görünür', /Tam 9 saat/.test(gecmis) && /FM 9s 30dk/.test(gecmis) && /2\.000,00 TRY · 12 saat/.test(gecmis), gecmis.slice(0, 300));
                } else {
                    ok('v299: kolonlar yokken saat alanları YOK, kurulum notu görünür', saatDurum.var === 0 && saatDurum.kurulum, JSON.stringify(saatDurum));
                }

                const secForm = await page.evaluate(() => {
                    const s = document.querySelector('form[method="get"] select[name="cavus"]');
                    return !!(s && s.form.hasAttribute('data-oto-filtre'));
                });
                ok('çavuş seçimi GET formu (data-oto-filtre) duruyor', secForm);
                ok('"← Personel Takibi" geri düğmesi var', await page.locator('a.btn[href="personel_takip.php"]').count() === 1);

                // Birim kutusu yalnız B'de görünür
                const birimGorunur = () => page.evaluate(() => {
                    const k = document.getElementById('cfBirim');
                    if (!k) return null;
                    const r = k.getBoundingClientRect();
                    return r.width > 0 && r.height > 0 && getComputedStyle(k).visibility !== 'hidden';
                });
                const bSecili = await page.evaluate(() => document.querySelector('input[name="cavus_yontem"][value="B"]').checked);
                ok(`başlangıç: Yöntem ${senaryo === 'dolu' ? 'B' : 'A'} seçili`, bSecili === (senaryo === 'dolu'));
                ok('birim kutusu yalnız B seçiliyken görünür (başlangıç)', (await birimGorunur()) === bSecili);
                await page.locator('input[name="cavus_yontem"][value="A"]').check();
                ok('A seçilince birim kutusu GİZLİ', (await birimGorunur()) === false);
                await page.locator('input[name="cavus_yontem"][value="B"]').check();
                ok('B seçilince birim kutusu GÖRÜNÜR', (await birimGorunur()) === true);
                if (senaryo === 'dolu') {
                    ok('birim kutusu çavuşun birimini (30) taşıyor', await page.locator('#cfBirim').inputValue() === '30');
                    const hap = await page.locator('#cfYontemHap').textContent();
                    ok('yöntem hapı "Yöntem B — 30 kişi-gün = 1 hakediş"', hap.trim() === 'Yöntem B — 30 kişi-gün = 1 hakediş', hap);
                    ok('B birim ücreti etiketi gerçek birimi gösterir (30 kişi-gün)', (await page.content()).includes('Hakediş Birim Ücreti (30 kişi-gün)'));
                    ok('sayfada "25 kişi-gün" geçmiyor (birim 30)', !(await page.content()).includes('25 kişi-gün'));
                    await page.locator('#cfBirim').fill('40');
                    ok('birim yazılınca B açıklaması güncellenir', (await page.locator('[data-cf-birim-yaz]').first().textContent()) === '40');
                    // eski hâline döndür (ekran görüntüsü için)
                    await page.locator('#cfBirim').fill('30');
                } else {
                    ok('boş durumda amber "henüz bir fiyat tanımlanmadı" notu', await page.locator('.cf2-uyari-hap', { hasText: 'Bu çavuş için henüz bir fiyat tanımlanmadı.' }).count() === 1);
                    await page.locator('input[name="cavus_yontem"][value="A"]').check();
                }

                // Düğmeler gerçek düğme gibi
                const dugmeler = await page.evaluate(() => Array.from(document.querySelectorAll('.cf2 button, .cf2 a.btn')).map(b => {
                    const cs = getComputedStyle(b);
                    const bg = cs.backgroundColor; const bw = parseFloat(cs.borderTopWidth);
                    const bgVar = (bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') || /gradient/.test(cs.backgroundImage);
                    const r = b.getBoundingClientRect();
                    return { t: b.textContent.trim().slice(0, 30), gercek: bgVar && (bw > 0 || bg !== getComputedStyle(document.body).backgroundColor), h: r.height };
                }).filter(d => d.h > 0));
                const sahte = dugmeler.filter(d => !d.gercek || d.h < 38);
                ok(`tüm görünür düğmeler dolu/çerçeveli ve ≥38px (${dugmeler.length} düğme)`, dugmeler.length >= 3 && sahte.length === 0, JSON.stringify(sahte));

                if (ekran.kisa === 'mob') {
                    const fontlar = await page.evaluate(() => Array.from(document.querySelectorAll('.cf2 input:not([type=hidden]):not([type=radio]), .cf2 select'))
                        .filter(e => e.getBoundingClientRect().width > 0).map(e => parseFloat(getComputedStyle(e).fontSize)));
                    ok('mobilde tüm girdiler ≥16px (iOS zoom yok)', fontlar.length > 0 && fontlar.every(f => f >= 16), JSON.stringify(fontlar));
                    const kolon = await page.evaluate(() => getComputedStyle(document.querySelector('.cf2-izgara')).gridTemplateColumns.split(' ').length);
                    ok('mobilde alan ızgarası tek kolon', kolon === 1, String(kolon));
                    const btnTam = await page.evaluate(() => {
                        const b = document.querySelector('#cfYeniDonem button[type=submit]');
                        const f = b.closest('.cf2-eylem').getBoundingClientRect();
                        return Math.abs(b.getBoundingClientRect().width - f.width) < 2;
                    });
                    ok('mobilde "+ Fiyat Dönemi Ekle" tam genişlik', btnTam);
                } else {
                    const kolon = await page.evaluate(() => getComputedStyle(document.querySelector('.cf2-izgara')).gridTemplateColumns.split(' ').length);
                    ok('masaüstünde alan ızgarası iki kolon', kolon === 2, String(kolon));
                }
                ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));

                if (SHOT && senaryo === 'dolu' && tema === 'light') {
                    const ad = ekran.kisa === 'pc' ? 'v297_fiyat_pc.png' : 'v297_fiyat_mob.png';
                    await page.screenshot({ path: path.join(SHOT, ad), fullPage: true });
                    console.log('  ekran görüntüsü: ' + path.join(SHOT, ad));
                }
                if (SHOT && senaryo === 'dolu' && tema === 'dark' && ekran.kisa === 'pc') {
                    await page.screenshot({ path: path.join(SHOT, 'v297_fiyat_pc_dark.png'), fullPage: true });
                }
                await page.close();
            }
        }
    }
    await browser.close();
    sunucu.close();
    console.log(`\nSONUÇ: ${hata === 0 ? 'TÜMÜ GEÇTİ' : hata + ' HATA'}`);
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
