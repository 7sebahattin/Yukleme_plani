// =========================================================
// scripts/ekran_kilit_smoke.js — Masaüstü ekran kilidi, tarayıcı testi (v454)
//   node scripts/ekran_kilit_smoke.js        (Playwright yoksa ATLAR)
// Sayfa scripts/ekran_kilit_render.php ile üretilir; uç (ekran_kilit.php) test sunucusunda taklit edilir.
// Doğrular: masaüstünde süre dolunca kilit + yazılan form durumu kilit açılınca KORUNUR, mobilde/kioskta
// kilit YOK, sunucu kilitliyken sayfa kilitli açılır, hareket sayacı sıfırlar, Esc kapatmaz,
// yanlış şifre → hata, 5 hata → bekleme, 401 → login yönlendirmesi.
// =========================================================
'use strict';
const fs = require('fs'), path = require('path'), http = require('http');
const { execFileSync } = require('child_process');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) {}
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json' };
const sayfa = (...a) => execFileSync('php', [path.join(ROOT, 'scripts', 'ekran_kilit_render.php'), ...a], { encoding: 'utf8' });
const SAYFALAR = { '/normal': sayfa(), '/kilitli': sayfa('kilitli'), '/kiosk': sayfa('kiosk') };
let hata = 0;
function ok(ad, kosul, ipucu) { if (!kosul) hata++; console.log(`${ad.padEnd(72)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`); }

// Taklit uç durumu
let sunucuDurum = { kilitli: false, hata: 0, istekler: [], oturumYok: false };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (SAYFALAR[u.pathname]) { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(SAYFALAR[u.pathname]); return; }
    if (u.pathname === '/ekran_kilit.php' && req.method === 'POST') {
        let g = ''; req.on('data', c => g += c); req.on('end', () => {
            const j = JSON.parse(g || '{}'); sunucuDurum.istekler.push(j.islem);
            const yanit = (kod, o) => { res.writeHead(kod, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
            if (sunucuDurum.oturumYok) return yanit(401, { ok: false, error: 'Oturum sona ermiş.' });
            if (j.csrf !== 'tok123') return yanit(403, { ok: false, error: 'csrf' });
            if (j.islem === 'kilitle') { sunucuDurum.kilitli = true; return yanit(200, { ok: true }); }
            if (j.islem === 'ac') {
                if (sunucuDurum.bekle) return yanit(429, { ok: false, error: 'Çok fazla hatalı deneme. 2 sn sonra tekrar deneyin.', bekle: 2 });
                if (j.sifre === 'dogru') { sunucuDurum.kilitli = false; sunucuDurum.hata = 0; return yanit(200, { ok: true }); }
                if (++sunucuDurum.hata >= 5) { sunucuDurum.bekle = true; return yanit(429, { ok: false, error: 'Çok fazla hatalı deneme. 2 sn sonra tekrar deneyin.', bekle: 2 }); }
                return yanit(422, { ok: false, error: 'Şifre hatalı.' });
            }
            yanit(400, { ok: false });
        });
        return;
    }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(u.pathname === '/login.php' ? 200 : 404); res.end(u.pathname === '/login.php' ? 'login' : ''); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/plain' });
    fs.createReadStream(dosya).pipe(res);
});

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const kok = 'http://127.0.0.1:' + sunucu.address().port;
    const tarayici = await chromium.launch({ args: ['--no-sandbox'] });
    const bekle = ms => new Promise(r => setTimeout(r, ms));
    async function ac(yol, secenek, kisaSure) {
        const ctx = await tarayici.newContext(secenek);
        const p = await ctx.newPage();
        if (kisaSure) await p.addInitScript(ms => { window.ASYA_KILIT_MS = ms; }, kisaSure);
        sunucuDurum = { kilitli: false, hata: 0, istekler: [], oturumYok: false };
        await p.goto(kok + yol);
        return { ctx, p };
    }
    const MASAUSTU = { viewport: { width: 1280, height: 800 } };
    const MOBIL = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
    const acikMi = p => p.evaluate(() => document.getElementById('ekranKilit').open);

    // 1) Masaüstü: süre dolunca kilit, form durumu korunur
    let { ctx, p } = await ac('/normal', MASAUSTU, 800);
    await p.fill('#notAlani', 'yarım kalan not');
    await bekle(1000);
    await p.evaluate(() => window.__ekranKilitKontrol());
    ok('1a masaüstü: süre dolunca kilit penceresi açılır', await acikMi(p));
    ok('1b body.ekran-kilitli + içerik gizli', await p.evaluate(() => document.body.classList.contains('ekran-kilitli') && getComputedStyle(document.querySelector('main')).visibility === 'hidden'));
    ok('1c sunucuya "kilitle" gitti', sunucuDurum.istekler.includes('kilitle'));
    ok('1d şifre alanı odakta', await p.evaluate(() => document.activeElement && document.activeElement.id === 'ekranKilitSifre'));
    await p.keyboard.press('Escape');
    ok('1e Esc pencereyi kapatmaz', await acikMi(p));

    // 2) Yanlış şifre
    await p.fill('#ekranKilitSifre', 'yanlis'); await p.keyboard.press('Enter'); await bekle(250);
    ok('2a yanlış şifre → hata, hâlâ kilitli', await acikMi(p) && (await p.textContent('#ekranKilitHata')).includes('hatalı'));
    // boş gönderim
    await p.fill('#ekranKilitSifre', ''); await p.click('#ekranKilitBtn'); await bekle(150);
    ok('2b boş şifre → "Şifrenizi girin"', (await p.textContent('#ekranKilitHata')).includes('Şifrenizi'));
    // 5 hata → bekleme
    for (let i = 0; i < 4; i++) { await p.fill('#ekranKilitSifre', 'y' + i); await p.click('#ekranKilitBtn'); await bekle(200); }
    ok('2c 5 hata → bekleme mesajı + düğme pasif', (await p.textContent('#ekranKilitHata')).includes('sn sonra') && await p.evaluate(() => document.getElementById('ekranKilitBtn').disabled));
    await bekle(2300);
    ok('2d bekleme bitince düğme tekrar aktif', await p.evaluate(() => !document.getElementById('ekranKilitBtn').disabled));
    sunucuDurum.bekle = false;

    // 3) Doğru şifre → kaldığı yerden devam
    await p.fill('#ekranKilitSifre', 'dogru'); await p.keyboard.press('Enter'); await bekle(300);
    ok('3a doğru şifre → pencere kapanır', !(await acikMi(p)));
    ok('3b yazılan form durumu KORUNDU (sayfa yenilenmedi)', (await p.inputValue('#notAlani')) === 'yarım kalan not');
    ok('3c içerik tekrar görünür', await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'visible' && !document.body.classList.contains('ekran-kilitli')));
    await p.evaluate(() => window.__ekranKilitKontrol());
    ok('3d açtıktan hemen sonra tekrar kilitlenmez (sayaç sıfırlandı)', !(await acikMi(p)));
    await ctx.close();

    // 4) Hareket sayacı sıfırlar
    ({ ctx, p } = await ac('/normal', MASAUSTU, 1200));
    await bekle(800); await p.mouse.move(100, 100); await p.mouse.move(140, 120);
    await bekle(700); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('4a hareket varsa (1,5 sn geçse de) kilitlenmez', !(await acikMi(p)));
    await bekle(1300); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('4b hareketsiz kalınca kilitlenir', await acikMi(p));
    await ctx.close();

    // 5) 401 → login
    ({ ctx, p } = await ac('/normal', MASAUSTU, 500));
    await bekle(700); await p.evaluate(() => window.__ekranKilitKontrol());
    sunucuDurum.oturumYok = true;
    await p.fill('#ekranKilitSifre', 'dogru'); await p.keyboard.press('Enter'); await bekle(500);
    ok('5 oturum bitmişse (401) login sayfasına yönlenir', p.url().includes('/login.php?next='), p.url());
    await ctx.close();

    // 6) Mobil: kilit YOK
    ({ ctx, p } = await ac('/normal', MOBIL, 300));
    await bekle(500); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('6a mobil: süre dolsa da kilit açılmaz', !(await acikMi(p)));
    ok('6b mobil: sunucuya kilitle gitmez', !sunucuDurum.istekler.includes('kilitle'));
    await ctx.close();

    // 7) Sunucu kilitliyken yükleme
    ({ ctx, p } = await ac('/kilitli', MASAUSTU));
    ok('7a masaüstü: sunucu kilitliyse sayfa kilitli açılır', await acikMi(p));
    ok('7b içerik gizli', await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'hidden'));
    await ctx.close();
    ({ ctx, p } = await ac('/kilitli', MOBIL));
    ok('7c mobil: sunucu bayrağı olsa da pencere açılmaz, içerik görünür', !(await acikMi(p)) && await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'visible'));
    await ctx.close();

    // 8) Kiosk muaf
    ({ ctx, p } = await ac('/kiosk', MASAUSTU, 300));
    await bekle(500); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('8 kiosk ekranında otomatik kilit YOK', !(await acikMi(p)));
    await ctx.close();

    // 9) Başka sekmede kilit/açma yayılır
    ctx = await tarayici.newContext(MASAUSTU);
    const a = await ctx.newPage(), b = await ctx.newPage();
    await a.addInitScript(() => { window.ASYA_KILIT_MS = 600; }); await b.addInitScript(() => { window.ASYA_KILIT_MS = 600; });
    await a.goto(kok + '/normal'); await b.goto(kok + '/normal');
    await bekle(900); await a.evaluate(() => window.__ekranKilitKontrol()); await bekle(300);
    ok('9a bir sekme kilitlenince diğer sekme de kilitlenir', await acikMi(a) && await acikMi(b));
    await a.fill('#ekranKilitSifre', 'dogru'); await a.keyboard.press('Enter'); await bekle(400);
    ok('9b bir sekmede açılınca diğeri de açılır', !(await acikMi(a)) && !(await acikMi(b)));
    await ctx.close();

    await tarayici.close(); sunucu.close();
    console.log(hata ? `\n${hata} TEST BAŞARISIZ` : '\nTümü geçti');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
