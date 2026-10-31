// =========================================================
// scripts/ekran_kilit_smoke.js — Masaüstü ekran kilidi (PIN modeli), tarayıcı testi (v455)
//   node scripts/ekran_kilit_smoke.js        (Playwright yoksa ATLAR)
// Sayfalar scripts/ekran_kilit_render.php ile üretilir; uç (ekran_kilit.php) test sunucusunda taklit edilir.
// Doğrular: kilit (süre/PIN/bekleme/401/kilitle hatası+yeniden deneme), nabız, PIN-belirle (zorunlu pencere),
// PIN-değiştir (mevcut PIN ↔ hesap şifresi), mobil + kiosk muafiyeti, alan nitelikleri, sekmeler arası senkron.
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
const SAYFALAR = {
    '/normal': sayfa('degistir'), '/kilitli': sayfa('kilitli'), '/kiosk': sayfa('kiosk'),
    '/yok': sayfa('yok'), '/yok-kiosk': sayfa('yok', 'kiosk'), '/pasif': sayfa('pasif'),
};
let hata = 0;
function ok(ad, kosul, ipucu) { if (!kosul) hata++; console.log(`${ad.padEnd(74)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`); }

// Taklit uç
const yeniDurum = () => ({ kilitli: false, hata: 0, bekle: false, istekler: [], govdeler: [], basliklar: [], oturumYok: false, cikis: false, kilitleKod: 200, kurKod: 200 });
let S = yeniDurum();
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (SAYFALAR[u.pathname]) { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(SAYFALAR[u.pathname]); return; }
    if (u.pathname === '/ekran_kilit.php' && req.method === 'POST') {
        let g = ''; req.on('data', c => g += c); req.on('end', () => {
            const j = JSON.parse(g || '{}');
            S.istekler.push(j.islem); S.govdeler.push(j); S.basliklar.push(req.headers);
            const yanit = (kod, o) => { res.writeHead(kod, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
            if (S.cikis) return yanit(401, { ok: false, error: 'Çok fazla hatalı deneme. Güvenliğiniz için oturum kapatıldı.', kod: 'cikis', cikis: true, login: true });
            if (S.oturumYok) return yanit(401, { ok: false, error: 'Oturum sona ermiş. Lütfen tekrar giriş yapın.', kod: 'oturum', login: true });
            if (j.islem === 'nabiz') return yanit(200, { ok: true, kilitli: S.kilitli, pin: 'var' });
            if (j.islem === 'kilitle') {
                if (S.kilitleKod !== 200) return yanit(S.kilitleKod, { ok: false, error: 'x' });
                S.kilitli = true; return yanit(200, { ok: true, kilitli: true });
            }
            if (j.islem === 'ac') {
                if (S.bekle) return yanit(429, { ok: false, error: 'Çok fazla hatalı deneme. 2 sn sonra tekrar deneyin.', kod: 'bekle', bekle: 2, engelli: true });
                if (j.pin === '1234') { S.kilitli = false; S.hata = 0; return yanit(200, { ok: true }); }
                if (++S.hata >= 5) { S.bekle = true; return yanit(429, { ok: false, error: 'Çok fazla hatalı deneme. 2 sn sonra tekrar deneyin.', kod: 'bekle', bekle: 2, engelli: true }); }
                return yanit(422, { ok: false, error: 'PIN hatalı.', kod: 'yanlis' });
            }
            if (j.islem === 'kur') {
                if (S.kurKod !== 200) return yanit(S.kurKod, { ok: false, error: 'x', kod: S.kurKod === 409 ? 'var' : 'pasif' });
                return yanit(200, { ok: true });
            }
            if (j.islem === 'degistir') {
                if (j.mevcut === '1234' || j.sifre === 'hesapsifre') return yanit(200, { ok: true });
                return yanit(422, { ok: false, error: 'PIN hatalı.', kod: 'yanlis' });
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
    async function ac(yol, secenek, kilitMs, nabizMs, onHazirlik) {
        const ctx = await tarayici.newContext(secenek);
        const p = await ctx.newPage();
        await p.addInitScript(([k, n]) => { if (k) window.ASYA_KILIT_MS = k; if (n) window.ASYA_NABIZ_MS = n; }, [kilitMs || 0, nabizMs || 0]);
        S = yeniDurum();
        if (onHazirlik) onHazirlik();
        await p.goto(kok + yol);
        return { ctx, p };
    }
    const MASAUSTU = { viewport: { width: 1280, height: 800 } };
    const MOBIL = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
    const acikMi = (p, id = 'ekranKilit') => p.evaluate(i => { const d = document.getElementById(i); return !!d && d.open; }, id);
    const gorunur = (p, sel) => p.evaluate(s => { const e = document.querySelector(s); return !!e && !e.hidden && e.getBoundingClientRect().width > 0 && getComputedStyle(e).visibility !== 'hidden'; }, sel);
    const kilitle = async p => { await p.evaluate(() => window.__ekranKilitKontrol()); await bekle(250); };

    // 1) Masaüstü: süre dolunca kilit
    let { ctx, p } = await ac('/normal', MASAUSTU, 800);
    await p.fill('#notAlani', 'yarım kalan not');
    await bekle(1000); await kilitle(p);
    ok('1a masaüstü: süre dolunca kilit penceresi açılır', await acikMi(p));
    ok('1b body.ekran-kilitli + içerik gizli', await p.evaluate(() => document.body.classList.contains('ekran-kilitli') && getComputedStyle(document.querySelector('main')).visibility === 'hidden'));
    const ki = S.istekler.indexOf('kilitle');
    ok('1c "kilitle" isteği gitti, csrf alanı YOK', ki >= 0 && !('csrf' in S.govdeler[ki]), JSON.stringify(S.govdeler[ki]));
    ok('1d doğru başlıklar (JSON + X-Requested-With)', ki >= 0 && /application\/json/.test(S.basliklar[ki]['content-type'] || '') && S.basliklar[ki]['x-requested-with'] === 'XMLHttpRequest');
    ok('1e kilit ekranında input[type=password] YOK', (await p.locator('#ekranKilit input[type=password]').count()) === 0);
    ok('1f PIN alanı odakta', await p.evaluate(() => document.activeElement && document.activeElement.id === 'ekranKilitPin'));
    await p.keyboard.press('Escape');
    ok('1g Esc pencereyi kapatmaz', await acikMi(p));
    const nit = await p.evaluate(() => { const e = document.getElementById('ekranKilitPin'); const c = getComputedStyle(e); return { t: e.type, ac: e.getAttribute('autocomplete'), im: e.getAttribute('inputmode'), ml: e.maxLength, ts: c.webkitTextSecurity || c.textSecurity }; });
    ok('1h alan nitelikleri (text/off/numeric/8/disc)', nit.t === 'text' && nit.ac === 'off' && nit.im === 'numeric' && nit.ml === 8 && nit.ts === 'disc', JSON.stringify(nit));
    await p.type('#ekranKilitPin', 'ab12cd34');
    ok('1i harf yazılamaz (yalnız rakam kalır)', (await p.inputValue('#ekranKilitPin')) === '1234');
    await p.fill('#ekranKilitPin', '');

    // 2) Yanlış / eksik PIN, bekleme
    const sayi0 = S.istekler.filter(x => x === 'ac').length;
    await p.fill('#ekranKilitPin', '12'); await p.click('#ekranKilitBtn'); await bekle(150);
    ok('2a geçersiz biçim → mesaj, istek GİTMEZ', (await p.textContent('#ekranKilitHata')).includes('4–8') && S.istekler.filter(x => x === 'ac').length === sayi0);
    await p.fill('#ekranKilitPin', '9999'); await p.keyboard.press('Enter'); await bekle(250);
    ok('2b yanlış PIN → "PIN hatalı.", hâlâ kilitli', await acikMi(p) && (await p.textContent('#ekranKilitHata')).includes('PIN hatalı'));
    for (let i = 0; i < 4; i++) { await p.fill('#ekranKilitPin', '000' + i); await p.click('#ekranKilitBtn'); await bekle(200); }
    ok('2c 5 hata → bekleme mesajı + düğme/alan pasif', (await p.textContent('#ekranKilitHata')).includes('sn sonra') && await p.evaluate(() => document.getElementById('ekranKilitBtn').disabled && document.getElementById('ekranKilitPin').disabled));
    await bekle(2400);
    ok('2d bekleme bitince düğme + alan tekrar aktif', await p.evaluate(() => !document.getElementById('ekranKilitBtn').disabled && !document.getElementById('ekranKilitPin').disabled));
    S.bekle = false;

    // 3) Doğru PIN
    await p.fill('#ekranKilitPin', '1234'); await p.keyboard.press('Enter'); await bekle(300);
    ok('3a doğru PIN → pencere kapanır', !(await acikMi(p)));
    ok('3b yazılan form durumu KORUNDU (sayfa yenilenmedi)', (await p.inputValue('#notAlani')) === 'yarım kalan not');
    ok('3c içerik tekrar görünür', await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'visible' && !document.body.classList.contains('ekran-kilitli')));
    await p.evaluate(() => window.__ekranKilitKontrol());
    ok('3d açtıktan hemen sonra tekrar kilitlenmez', !(await acikMi(p)));
    await ctx.close();

    // 4) Hareket sayacı
    ({ ctx, p } = await ac('/normal', MASAUSTU, 1200));
    await bekle(800); await p.mouse.move(100, 100); await p.mouse.move(140, 120);
    await bekle(700); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('4a hareket varsa kilitlenmez', !(await acikMi(p)));
    await bekle(1300); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('4b hareketsiz kalınca kilitlenir', await acikMi(p));
    await ctx.close();

    // 5) 401 → login (mesaj parametreleri)
    ({ ctx, p } = await ac('/normal', MASAUSTU, 500));
    await bekle(700); await kilitle(p);
    S.oturumYok = true;
    await p.fill('#ekranKilitPin', '1234'); await p.keyboard.press('Enter'); await bekle(500);
    ok('5a oturum bitmişse (401) login?next&mesaj=kilit_oturum', /\/login\.php\?next=.*&mesaj=kilit_oturum/.test(p.url()), p.url());
    await ctx.close();
    ({ ctx, p } = await ac('/normal', MASAUSTU, 500));
    await bekle(700); await kilitle(p);
    S.cikis = true;
    await p.fill('#ekranKilitPin', '1111'); await p.keyboard.press('Enter'); await bekle(500);
    ok('5b 10. hata (401 cikis) → mesaj=pin_cikis', /\/login\.php\?next=.*&mesaj=pin_cikis/.test(p.url()), p.url());
    await ctx.close();

    // 6) kilitle başarısız → pencere açık + not + yeniden dener
    ({ ctx, p } = await ac('/normal', MASAUSTU, 500, 0, () => { S.kilitleKod = 403; }));
    await bekle(700); await kilitle(p);
    ok('6a kilitle 403 → pencere AÇIK kalır', await acikMi(p));
    ok('6b "Kilit sunucuya kaydedilemedi" notu', (await p.textContent('#ekranKilitHata')).includes('Kilit sunucuya kaydedilemedi'));
    const once = S.istekler.filter(x => x === 'kilitle').length;
    S.kilitleKod = 500;
    await p.evaluate(() => window.__ekranKilitKontrol()); await bekle(250);
    ok('6c sonraki kontrolde yeniden dener', S.istekler.filter(x => x === 'kilitle').length > once);
    S.kilitleKod = 200;
    await p.evaluate(() => window.__ekranKilitKontrol()); await bekle(250);
    ok('6d başarılı kayıtla not kalkar', await p.evaluate(() => document.getElementById('ekranKilitHata').hidden) && S.kilitli);
    await ctx.close();

    // 7) Nabız
    ({ ctx, p } = await ac('/normal', MASAUSTU, 0, 300));
    await bekle(900);
    const nbz = S.istekler.indexOf('nabiz');
    ok('7a masaüstü: nabız gider (csrf yok)', nbz >= 0 && !('csrf' in S.govdeler[nbz]));
    S.kilitli = true; await bekle(700);
    ok('7b nabız kilitli:true döndürünce pencere açılır', await acikMi(p));
    const n0 = S.istekler.filter(x => x === 'nabiz').length; await bekle(700);
    ok('7c kilitliyken de nabız sürer', S.istekler.filter(x => x === 'nabiz').length > n0);
    S.oturumYok = true; await bekle(700);
    ok('7d nabızda 401 → login', p.url().includes('/login.php?next='), p.url());
    await ctx.close();
    ({ ctx, p } = await ac('/kiosk', MASAUSTU, 0, 300)); await bekle(900);
    ok('7e kiosk: nabız YOK', !S.istekler.includes('nabiz')); await ctx.close();
    ({ ctx, p } = await ac('/normal', MOBIL, 0, 300)); await bekle(900);
    ok('7f mobil: nabız YOK', !S.istekler.includes('nabiz')); await ctx.close();

    // 8) Mobil + kiosk: kilit/PIN-belirle YOK
    ({ ctx, p } = await ac('/normal', MOBIL, 300));
    await bekle(500); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('8a mobil: kilit açılmaz, "kilitle" gitmez', !(await acikMi(p)) && !S.istekler.includes('kilitle'));
    ok('8b mobil: sidebar PIN düğmesi görünmez', !(await gorunur(p, '#btnPinDegistir')));
    await ctx.close();
    ({ ctx, p } = await ac('/yok', MOBIL));
    ok('8c mobil: PIN yokken PIN-belirle açılmaz', !(await acikMi(p, 'ekranPinKur')));
    await ctx.close();
    ({ ctx, p } = await ac('/kiosk', MASAUSTU, 300));
    await bekle(500); await p.evaluate(() => window.__ekranKilitKontrol());
    ok('8d kiosk: otomatik kilit YOK', !(await acikMi(p)));
    await ctx.close();
    ({ ctx, p } = await ac('/yok-kiosk', MASAUSTU));
    ok('8e kiosk: PIN-belirle YOK', !(await acikMi(p, 'ekranPinKur')));
    await ctx.close();
    ({ ctx, p } = await ac('/pasif', MASAUSTU, 300));
    await bekle(500);
    ok('8f pasif: hiçbir kilit/PIN penceresi yok', (await p.locator('dialog.ekran-kilit').count()) === 0);
    await ctx.close();

    // 9) Sunucu kilitli başlangıç
    ({ ctx, p } = await ac('/kilitli', MASAUSTU));
    ok('9a masaüstü: sunucu kilitliyse sayfa kilitli açılır', await acikMi(p));
    ok('9b içerik gizli', await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'hidden'));
    await ctx.close();
    ({ ctx, p } = await ac('/kilitli', MOBIL));
    ok('9c mobil: bayrak olsa da pencere açılmaz, içerik görünür', !(await acikMi(p)) && await p.evaluate(() => getComputedStyle(document.querySelector('main')).visibility === 'visible'));
    await ctx.close();

    // 10) PIN belirle (zorunlu)
    ({ ctx, p } = await ac('/yok', MASAUSTU));
    ok('10a masaüstü: PIN yoksa pencere otomatik açılır', await acikMi(p, 'ekranPinKur'));
    await p.keyboard.press('Escape');
    ok('10b Esc kapatmaz', await acikMi(p, 'ekranPinKur'));
    ok('10c kaçış yalnız çıkış linki', (await p.locator('#ekranPinKur a.ekran-kilit-cikis').count()) === 1 && (await p.locator('#ekranPinKur button[type=button]').count()) === 0);
    await p.fill('#ekranPinKurPin', '12'); await p.fill('#ekranPinKurPin2', '12'); await p.click('#ekranPinKurBtn'); await bekle(150);
    ok('10d geçersiz PIN → mesaj, istek gitmez', (await p.textContent('#ekranPinKurHata')).includes('4–8') && !S.istekler.includes('kur'));
    await p.fill('#ekranPinKurPin', '1234'); await p.fill('#ekranPinKurPin2', '1235'); await p.click('#ekranPinKurBtn'); await bekle(150);
    ok('10e uyuşmazlık → mesaj, istek gitmez', (await p.textContent('#ekranPinKurHata')).includes('eşleşmiyor') && !S.istekler.includes('kur'));
    await p.fill('#ekranPinKurPin2', '1234'); await p.click('#ekranPinKurBtn'); await bekle(300);
    const kg = S.govdeler[S.istekler.indexOf('kur')] || {};
    ok('10f doğru → kur isteği (pin,pin2; csrf yok), pencere kapanır, sayfa yenilenmez', kg.pin === '1234' && kg.pin2 === '1234' && !('csrf' in kg) && !(await acikMi(p, 'ekranPinKur')));
    await ctx.close();
    ({ ctx, p } = await ac('/yok', MASAUSTU, 0, 0, () => { S.kurKod = 409; }));
    await p.fill('#ekranPinKurPin', '1234'); await p.fill('#ekranPinKurPin2', '1234'); await p.click('#ekranPinKurBtn'); await bekle(300);
    ok('10g 409 (var) → pencere kapanır', !(await acikMi(p, 'ekranPinKur')));
    await ctx.close();
    ({ ctx, p } = await ac('/yok', MASAUSTU, 0, 0, () => { S.kurKod = 503; }));
    await p.fill('#ekranPinKurPin', '1234'); await p.fill('#ekranPinKurPin2', '1234'); await p.click('#ekranPinKurBtn'); await bekle(300);
    ok('10h 503 (pasif) → pencere kapanır', !(await acikMi(p, 'ekranPinKur')));
    await ctx.close();

    // 11) PIN değiştir
    ({ ctx, p } = await ac('/normal', MASAUSTU));
    ok('11a masaüstü: sidebar PIN düğmesi görünür', await gorunur(p, '#btnPinDegistir'));
    await p.click('#btnPinDegistir');
    ok('11b düğme PIN-değiştir penceresini açar', await acikMi(p, 'ekranPinDegistir'));
    ok('11c başta mevcut PIN görünür, şifre alanı gizli', await gorunur(p, '#ekranPinDegistirMevcut') && !(await gorunur(p, '#ekranPinDegistirSifre')));
    const pd = await p.evaluate(() => { const e = document.getElementById('ekranPinDegistirMevcut'); const c = getComputedStyle(e); return { t: e.type, ac: e.getAttribute('autocomplete'), ts: c.webkitTextSecurity || c.textSecurity, sa: document.getElementById('ekranPinDegistirSifre').getAttribute('autocomplete') }; });
    ok('11d alan nitelikleri (text/off/disc; şifre alanı new-password)', pd.t === 'text' && pd.ac === 'off' && pd.ts === 'disc' && pd.sa === 'new-password', JSON.stringify(pd));
    await p.fill('#ekranPinDegistirMevcut', '1234'); await p.fill('#ekranPinDegistirYeni', '5678'); await p.fill('#ekranPinDegistirYeni2', '5678');
    await p.click('#ekranPinDegistirBtn'); await bekle(300);
    let dg = S.govdeler[S.istekler.indexOf('degistir')] || {};
    ok('11e mevcut PIN yolu: gövdede mevcut+pin+pin2, sifre YOK, csrf YOK', dg.mevcut === '1234' && dg.pin === '5678' && dg.pin2 === '5678' && !('sifre' in dg) && !('csrf' in dg), JSON.stringify(dg));
    ok('11f başarıda pencere kapanır', !(await acikMi(p, 'ekranPinDegistir')));
    // unuttum yolu
    await p.click('#btnPinDegistir');
    ok('11g yeniden açılışta alanlar temiz', (await p.inputValue('#ekranPinDegistirMevcut')) === '' && (await p.inputValue('#ekranPinDegistirYeni')) === '');
    await p.fill('#ekranPinDegistirMevcut', '4321');
    await p.click('#ekranPinUnuttum');
    ok('11h unuttum → şifre alanı görünür, mevcut PIN gizli ve temizlenir', await gorunur(p, '#ekranPinDegistirSifre') && !(await gorunur(p, '#ekranPinDegistirMevcut')) && (await p.inputValue('#ekranPinDegistirMevcut')) === '');
    S.istekler.length = 0; S.govdeler.length = 0;
    await p.fill('#ekranPinDegistirSifre', 'hesapsifre'); await p.fill('#ekranPinDegistirYeni', '2468'); await p.fill('#ekranPinDegistirYeni2', '2468');
    await p.click('#ekranPinDegistirBtn'); await bekle(300);
    dg = S.govdeler[S.istekler.indexOf('degistir')] || {};
    ok('11i şifre yolu: gövdede yalnız sifre (mevcut YOK)', dg.sifre === 'hesapsifre' && !('mevcut' in dg) && dg.pin === '2468', JSON.stringify(dg));
    await p.click('#btnPinDegistir'); await p.click('#ekranPinUnuttum'); await p.click('#ekranPinUnuttum');
    ok('11j "PIN ile doğrula" → mevcut PIN alanı geri gelir', await gorunur(p, '#ekranPinDegistirMevcut') && !(await gorunur(p, '#ekranPinDegistirSifre')));
    await p.fill('#ekranPinDegistirMevcut', '0000'); await p.fill('#ekranPinDegistirYeni', '2468'); await p.fill('#ekranPinDegistirYeni2', '2468');
    await p.click('#ekranPinDegistirBtn'); await bekle(250);
    ok('11k yanlış mevcut PIN → hata, pencere açık', await acikMi(p, 'ekranPinDegistir') && (await p.textContent('#ekranPinDegistirHata')).includes('hatalı'));
    await p.click('#ekranPinDegistirIptal');
    ok('11l İptal kapatır', !(await acikMi(p, 'ekranPinDegistir')));
    await p.click('#btnPinDegistir'); await p.keyboard.press('Escape');
    ok('11m Esc kapatır', !(await acikMi(p, 'ekranPinDegistir')));
    await ctx.close();

    // 11n) kilit açılınca PIN-değiştir kapanır
    ({ ctx, p } = await ac('/normal', MASAUSTU, 0, 0));
    await p.click('#btnPinDegistir');
    await p.evaluate(() => document.dispatchEvent(new CustomEvent('asya:nabiz', { detail: { kilitli: true } })));
    await bekle(150);
    ok('11n kilit penceresi açılınca üstte kilit var', await acikMi(p));
    await p.fill('#ekranKilitPin', '1234'); await p.keyboard.press('Enter'); await bekle(300);
    ok('11o kilit açılınca PIN-değiştir penceresi de kapanır', !(await acikMi(p)) && !(await acikMi(p, 'ekranPinDegistir')));
    await ctx.close();

    // 12) Sekmeler arası
    ctx = await tarayici.newContext(MASAUSTU);
    S = yeniDurum();
    const a = await ctx.newPage(), b = await ctx.newPage();
    await a.addInitScript(() => { window.ASYA_KILIT_MS = 600; }); await b.addInitScript(() => { window.ASYA_KILIT_MS = 600; });
    await a.goto(kok + '/normal'); await b.goto(kok + '/normal');
    await bekle(900); await a.evaluate(() => window.__ekranKilitKontrol()); await bekle(300);
    ok('12a bir sekme kilitlenince diğeri de kilitlenir', await acikMi(a) && await acikMi(b));
    await a.fill('#ekranKilitPin', '1234'); await a.keyboard.press('Enter'); await bekle(400);
    ok('12b bir sekmede açılınca diğeri de açılır', !(await acikMi(a)) && !(await acikMi(b)));
    await ctx.close();

    await tarayici.close(); sunucu.close();
    console.log(hata ? `\n${hata} TEST BAŞARISIZ` : '\nTümü geçti');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
