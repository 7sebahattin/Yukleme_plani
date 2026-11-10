// =========================================================
// scripts/pdks_toplu_onay_smoke.js — v507 Toplu Mesai Onayı ekranı: GERÇEK TARAYICI düzen testi
// (masaüstü 1280 + mobil 390): yatay taşma yok, kutular tıklanır, sayaç/buton, <768px 16px input.
//   node scripts/pdks_toplu_onay_smoke.js     (php + Playwright gerekir; yoksa ATLAR)
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const SHOT = process.env.SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(80)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const render = (env) => execFileSync('php', [path.join(__dirname, 'pdks_toplu_onay_render.php')], { cwd: ROOT, env: Object.assign({}, process.env, env), maxBuffer: 32 * 1024 * 1024 }).toString('utf8');
const SAYFALAR = { '/': render({}), '/onizle': render({ TO_ONIZLE: '1' }) };
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (SAYFALAR[u.pathname]) { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(SAYFALAR[u.pathname]); return; }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}`;
    const tarayici = await chromium.launch();
    for (const [ad, w, h] of [['masaüstü', 1280, 800], ['mobil', 390, 800]]) {
        const sayfa = await (await tarayici.newContext({ viewport: { width: w, height: h } })).newPage();
        await sayfa.addStyleTag({ url: KOK + '/assets/pdks.css' }).catch(() => {});
        await sayfa.goto(KOK + '/', { waitUntil: 'load' });
        await sayfa.addStyleTag({ url: KOK + '/assets/pdks.css' });
        await sayfa.waitForTimeout(400);
        const n = await sayfa.locator('.js-tvo-chk').count();
        ok(`${ad}: 5 bekleyen satır listelendi`, n === 5, String(n));
        ok(`${ad}: yatay taşma yok`, await sayfa.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        ok(`${ad}: önizle düğmesi başta pasif`, await sayfa.locator('#tvoOnizleBtn').isDisabled());
        await sayfa.locator('.js-tvo-chk').first().check();
        ok(`${ad}: seçince sayaç 1 + düğme açık`, (await sayfa.locator('#tvoSayac').innerText()).startsWith('1') && !(await sayfa.locator('#tvoOnizleBtn').isDisabled()));
        await sayfa.locator('#tvoTumu').check();
        ok(`${ad}: tümünü seç → 5`, (await sayfa.locator('#tvoSayac').innerText()).startsWith('5'));
        const sel = await sayfa.locator('#tvoSinif').evaluate(e => e.value);
        ok(`${ad}: Tam/Yarım ve FM kararı varsayılan BOŞ`, sel === '' && (await sayfa.locator('#tvoFm').evaluate(e => e.value)) === '');
        if (w < 768) {
            const fs16 = await sayfa.locator('#tvoSinif').evaluate(e => parseFloat(getComputedStyle(e).fontSize));
            ok(`${ad}: select 16px`, fs16 >= 16, String(fs16));
        }
        const kutu = await sayfa.locator('.js-tvo-chk').first().boundingBox();
        ok(`${ad}: onay kutusu ekranda ve ≥ 20px`, !!kutu && kutu.width >= 20 && kutu.x >= 0 && kutu.x + kutu.width <= w);
        if (SHOT) await sayfa.screenshot({ path: path.join(SHOT, `toplu_onay_${ad}.png`), fullPage: true });

        await sayfa.goto(KOK + '/onizle', { waitUntil: 'load' });
        await sayfa.addStyleTag({ url: KOK + '/assets/pdks.css' });
        await sayfa.waitForTimeout(400);
        ok(`${ad}: önizleme kartı + uygula düğmesi`, (await sayfa.locator('#tvoOnizleme').count()) === 1 && (await sayfa.locator('.tvo-uygula-form button').count()) === 1);
        ok(`${ad}: önizleme yatay taşma yok`, await sayfa.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        const uygunsuz = await sayfa.locator('.tvo-onizleme-liste .tvo-s-uygunsuz').count();
        ok(`${ad}: uygunsuz satırlar işaretli (fiyatsız + çıkışsız = 2)`, uygunsuz === 2, String(uygunsuz));
        const gizli = await sayfa.locator('.tvo-uygula-form input[name="period_ids[]"]').count();
        ok(`${ad}: uygula formuna YALNIZ onaylanacaklar (3) taşınır`, gizli === 3, String(gizli));
        if (SHOT) await sayfa.screenshot({ path: path.join(SHOT, `toplu_onay_onizle_${ad}.png`), fullPage: true });
    }
    await tarayici.close();
    sunucu.close();
    console.log(hata === 0 ? '\nTÜM TESTLER GEÇTİ' : `\n${hata} HATA`);
    process.exit(hata === 0 ? 0 : 1);
})();
