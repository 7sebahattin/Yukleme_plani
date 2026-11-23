'use strict';
// =========================================================
// scripts/puantaj_giris_e2e_smoke.js — Puantaj Girişi UÇTAN UCA kişi akışları (v556)
//
// Diğer smoke'lar sahte sunucu (route) kullanır; burada GERÇEK PHP kodu çalışır:
//   tarayıcı (Chromium) ⇄ yerel HTTP sunucusu ⇄ scripts/_puantaj_cizelgesi_harness.php (puantaj_cizelgesi.php
//   ve puantaj_giris_kaydet.php, KALICI SQLite dosyası; sahte oturum/yetki). Böylece "ekledim → sayfa yenilendi → ad görünüyor mu?"
//   gibi akışlar gerçek yazma ucuyla denenir. Her adımdan sonra KİŞİ ADI SÜTUNU (ilk sütun, th) ölçülür:
//   görünür, genişlik ≥ 80px, metin beklenen ad.   node scripts/puantaj_giris_e2e_smoke.js
// =========================================================
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { spawnSync } = require('child_process');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* sıradaki */ }
}
if (!chromium) { console.error('Playwright bulunamadı; tarayıcı testi çalıştırılamadı.'); process.exit(1); }
const root = path.dirname(__dirname);
const ay = new Date().toISOString().slice(0, 7);
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pge2e_'));
const dbf = path.join(tmp, 'e2e.sqlite');
const harness = path.join(root, 'scripts/_puantaj_cizelgesi_harness.php');
const audits = [];
let phpSay = 0;

function php(sayfa, get, ek) {
    const opt = Object.assign({ admin: true, ay, depo: 'Depo A', allowed: ['Depo A'], giris: true, http: true, db_dosya: dbf }, ek || {});
    const r = spawnSync('php', [harness, sayfa, JSON.stringify(get || {}), JSON.stringify(opt)], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    phpSay++;
    for (const l of (r.stderr || '').split('\n')) if (l.startsWith('AUDIT: ')) audits.push(l.slice(7));
    return { out: r.stdout || '', err: r.stderr || '', rc: r.status };
}
function multipart(buf, ct) {
    const b = '--' + (ct.match(/boundary=(?:"([^"]+)"|([^;]+))/) || []).slice(1).find(Boolean);
    const alan = {}; let dosya = null;
    const ham = buf.toString('latin1');
    for (const parca of ham.split(b).slice(1)) {
        if (parca.startsWith('--')) break;
        const i = parca.indexOf('\r\n\r\n'); if (i < 0) continue;
        const bas = parca.slice(0, i), govde = parca.slice(i + 4, parca.length - 2);
        const ad = (bas.match(/name="([^"]*)"/) || [])[1], dn = (bas.match(/filename="([^"]*)"/) || [])[1];
        if (dn !== undefined) { const tf = path.join(tmp, 'yuk_' + phpSay + '_' + dn.replace(/[^\w.]/g, '_')); fs.writeFileSync(tf, Buffer.from(govde, 'latin1')); dosya = { ad, name: dn, tmp_name: tf }; }
        else alan[ad] = Buffer.from(govde, 'latin1').toString('utf8');
    }
    return { alan, dosya };
}
const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const yanit = (kod, tip, govde) => { res.statusCode = kod; res.setHeader('Content-Type', tip); res.end(govde); };
    if (u.pathname.startsWith('/assets/')) {
        const f = path.join(root, 'assets', path.basename(u.pathname));
        if (!fs.existsSync(f)) return yanit(404, 'text/plain', 'yok');
        return yanit(200, { '.css': 'text/css', '.js': 'application/javascript' }[path.extname(f)] || 'application/octet-stream', fs.readFileSync(f));
    }
    if (u.pathname === '/puantaj_cizelgesi.php' && req.method === 'GET') {
        const get = Object.fromEntries(u.searchParams);
        const r = php('puantaj_cizelgesi.php', get);
        if (r.rc !== 0) return yanit(500, 'text/plain', 'PHP rc=' + r.rc + '\n' + r.err + r.out.slice(0, 500));
        return yanit(200, 'text/html; charset=utf-8', r.out);
    }
    if (u.pathname === '/puantaj_giris_kaydet.php' && req.method === 'POST') {
        const parcalar = []; req.on('data', c => parcalar.push(c));
        req.on('end', () => {
            const buf = Buffer.concat(parcalar), ct = req.headers['content-type'] || '';
            let post = {}, files;
            if (/application\/json/.test(ct)) { try { post = JSON.parse(buf.toString('utf8')); } catch (_) { post = {}; } }
            else if (/multipart\/form-data/.test(ct)) { const m = multipart(buf, ct); post = m.alan; if (m.dosya) files = { [m.dosya.ad]: { name: m.dosya.name, tmp_name: m.dosya.tmp_name } }; }
            else post = Object.fromEntries(new URLSearchParams(buf.toString('utf8')));
            const r = php('puantaj_giris_kaydet.php', {}, Object.assign({ post }, files ? { files } : {}));
            let j; try { j = JSON.parse(r.out); } catch (_) { j = { ok: false, error: 'PHP çıktısı JSON değil: ' + r.out.slice(0, 200) + r.err.slice(0, 200) }; }
            yanit(j.ok ? 200 : (j.code || 400), 'application/json; charset=utf-8', JSON.stringify(j));
        });
        return;
    }
    yanit(404, 'text/plain', 'yok');
});

let fail = 0, gecen = 0;
function ok(ad, c, d) { c ? gecen++ : fail++; console.log((c ? 'OK ' : 'FAIL ') + ad + (c || !d ? '' : ' — ' + d)); }

// Ad sütunu ölçümü: İLK sütun (sınıftan bağımsız) görünür mü; gövde satırlarındaki adlar
const OLC = () => {
    const wrap = document.getElementById('pcGridWrap');
    const t = document.querySelector('table.pc-grid');
    if (!t) return { tablo: false, liste: !!document.querySelector('.pc-liste') && getComputedStyle(document.querySelector('.pc-liste')).display !== 'none' };
    const bas = t.querySelector('thead tr').firstElementChild, bw = bas.getBoundingClientRect().width;
    const ad = [...t.querySelectorAll('tbody tr')].map(tr => { const th = tr.firstElementChild; const r = th.getBoundingClientRect(); const m = th.querySelector('.pc-isim-yazi'); return { th: th.tagName, w: r.width, txt: m ? (m.firstChild ? m.firstChild.textContent : m.textContent).trim() : '', pasif: tr.classList.contains('pg-pasif') }; });
    const tf = [...t.querySelectorAll('tfoot tr')].map(tr => tr.firstElementChild.getBoundingClientRect().width);
    return { tablo: true, uyari: !!document.getElementById('pcIsimUyari'), kose: { tag: bas.tagName, w: bw, txt: bas.textContent.replace(/\s+/g, ' ').trim() }, ad, tf, sc: wrap.scrollLeft, tasar: wrap.scrollWidth > wrap.clientWidth };
};
async function adlar(pg, etiket, beklenen, opts) {
    await pg.waitForTimeout(350);
    const o = await pg.evaluate(OLC);
    const hepsi = o.tablo && o.kose.tag === 'TH' && o.kose.w >= 80 && o.ad.every(a => a.th === 'TH' && a.w >= 80) && o.tf.every(w => w >= 80) && !o.uyari;
    const adlar = o.tablo ? o.ad.map(a => a.txt) : [];
    const beklenenTamam = !beklenen || (beklenen.length === adlar.length && beklenen.every(b => adlar.includes(b)));
    ok(etiket + ' → ad sütunu GÖRÜNÜR (köşe + ' + (o.tablo ? o.ad.length : 0) + ' satır + alt toplamlar ≥ 80px, bekçi uyarısı yok)' + (beklenen ? ', adlar = ' + JSON.stringify(beklenen) : ''), hepsi && beklenenTamam, JSON.stringify(o));
    return o;
}
const kisiEkle = async (pg, ad, gorev) => {
    await pg.locator('[data-pg-kisi-ekle]').first().click();
    await pg.waitForSelector('#pgPen:not([hidden])');
    await pg.fill('#pgAd', ad); await pg.fill('#pgGorev', gorev || '');
    await pg.click('#pgPenTamam');
};
const bekleYenile = async (pg, fn) => { await Promise.all([pg.waitForNavigation({ waitUntil: 'load' }), fn()]); await pg.waitForTimeout(250); };

(async () => {
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const base = 'http://127.0.0.1:' + srv.address().port;
    const executablePath = process.env.PW_EXECUTABLE_PATH || ['/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(executablePath ? { executablePath } : {});
    try {
        const ctx = await browser.newContext({ viewport: { width: 1665, height: 900 } });
        const pg = await ctx.newPage();
        const hatalar = []; pg.on('pageerror', e => hatalar.push(e.message)); pg.on('console', m => { if (m.type() === 'error' && !/favicon/.test(m.location().url || '') && !/Failed to load resource/.test(m.text())) hatalar.push('console: ' + m.text()); });
        pg.on('response', r => { if ((r.status() >= 500 || r.status() === 404 || r.status() === 403) && !/favicon/.test(r.url())) hatalar.push('HTTP ' + r.status() + ' ' + r.url()); });
        const git = q => pg.goto(base + '/puantaj_cizelgesi.php?ay=' + ay + '&sekme=giris' + (q || ''));

        // ── 0) İlk açılış: tablo kurulu, kişi YOK ──
        php('puantaj_cizelgesi.php', { ay, sekme: 'giris' });   // şema + tohum (kişi yok)
        await git();
        ok('0) boş durum: "Henüz kişi eklemediniz" + Kişi ekle düğmesi, ızgara yok', /Henüz kişi eklemediniz/.test(await pg.locator('.pg-bos').innerText()) && (await pg.locator('table.pc-grid').count()) === 0);

        // ── 1) Kişi ekle: doğrulama ──
        await pg.locator('[data-pg-kisi-ekle]').first().click(); await pg.waitForSelector('#pgPen:not([hidden])');
        await pg.fill('#pgAd', '   '); await pg.click('#pgPenTamam'); await pg.waitForTimeout(150);
        ok('1a) boş / yalnız boşluk ad → "Ad zorunludur." (istemci), pencere açık kalır, kişi eklenmedi', /Ad zorunludur/.test(await pg.locator('#pgPenHata').innerText()) && await pg.locator('#pgPen').isVisible());
        await pg.fill('#pgAd', 'x'.repeat(121));
        ok('1b) 121 karakter yazılınca kutu 120 ile sınırlar (maxlength) — sessiz kesme, ama kayıt oluşmaz (Esc ile vazgeçilecek)', (await pg.inputValue('#pgAd')).length === 120);
        const sunucuRed = await pg.evaluate(async () => { const s = document.querySelector('.pc-page[data-pg-url]'); const g = new URLSearchParams({ csrf: s.getAttribute('data-pg-csrf'), action: 'kisi_ekle', ad: 'x'.repeat(121), gorev: '' }); const r = await fetch(s.getAttribute('data-pg-url'), { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' }, body: g.toString() }); return { st: r.status, j: await r.json() }; });
        ok('1c) sunucu da 121 karakter adı reddeder (ok:false, "120 karakter" mesajı) — istemci atlansa bile', sunucuRed.j.ok === false && /120 karakter/.test(sunucuRed.j.error || ''), JSON.stringify(sunucuRed));
        await pg.keyboard.press('Escape'); await pg.waitForTimeout(100);

        // ── 2) Ad ekle → sayfa yenilenince GÖRÜNÜR (görünüp kaybolma testi) ──
        await bekleYenile(pg, () => kisiEkle(pg, 'Örnek Kişi A', 'Paketçi'));
        await adlar(pg, '2a) "Örnek Kişi A" eklendi + sayfa yenilendi', ['Örnek Kişi A']);
        for (const [ad, gr] of [['İsmail Şöför ÇĞÜÖ', 'Şoför'], ['😀 Emoji Kişi', ''], ['<b>x</b> & "q" \'t\'', '<i>gorev</i>'], ['Ö'.repeat(120), '']]) {
            await bekleYenile(pg, () => kisiEkle(pg, ad, gr));
        }
        const hepsi = ['Örnek Kişi A', 'İsmail Şöför ÇĞÜÖ', '😀 Emoji Kişi', '<b>x</b> & "q" \'t\'', 'Ö'.repeat(120)];
        const o2 = await adlar(pg, '2b) Türkçe / emoji / HTML özel karakter / 120 karakter adlar eklendi', hepsi);
        ok('2c) HTML özel karakterli ad ETİKET olarak çıkmaz (b/i öğesi yok), metin olarak görünür', (await pg.locator('table.pc-grid th.pc-isim b, table.pc-grid th.pc-isim i').count()) === 0 && o2.ad.some(a => a.txt === '<b>x</b> & "q" \'t\''));
        const uzun = await pg.evaluate(() => { const th = [...document.querySelectorAll('tbody th')].find(t => /^Ö{20}/.test(t.textContent.trim())); const m = th.querySelector('.pc-isim-yazi'); const r = th.getBoundingClientRect(); return { w: r.width, sc: th.scrollWidth, tasma: document.documentElement.scrollWidth <= innerWidth + 1, kes: getComputedStyle(m).textOverflow }; });
        ok('2d) 120 karakterlik ad sütunu şişirmez (≤ 260px) ve sayfa yatay taşmaz', uzun.w <= 262 && uzun.tasma, JSON.stringify(uzun));
        await bekleYenile(pg, () => kisiEkle(pg, 'Örnek Kişi A', 'Aynı ad'));
        const o3 = await adlar(pg, '2e) aynı ad tekrar eklenebilir (engel yok → iki satır)', null);
        ok('2f) "Örnek Kişi A" iki satır', o3.ad.filter(a => a.txt === 'Örnek Kişi A').length === 2);
        ok('2g) oturum boyunca JS hatası yok', hatalar.length === 0, hatalar.join('|'));

        // ── 3) Düzenle ──
        const satirNo = async (ad, nth) => pg.evaluate(([a, n]) => { const tr = [...document.querySelectorAll('tr[data-pg-satir]')].filter(t => (t.querySelector('.pc-isim-yazi').firstChild || {}).textContent.trim() === a)[n || 0]; return tr ? tr.getAttribute('data-pg-satir') : null; }, [ad, nth || 0]);
        const idA = await satirNo('Örnek Kişi A', 0);
        await pg.locator('tr[data-pg-satir="' + idA + '"] .pg-uc').click(); await pg.waitForSelector('#pgIslem:not([hidden])');
        await pg.locator('#pgIslem [data-pg-islem="duzenle"]').click(); await pg.waitForSelector('#pgPen:not([hidden])');
        ok('3a) Düzenle penceresi mevcut ad + görev ile dolu açılır', (await pg.inputValue('#pgAd')) === 'Örnek Kişi A' && (await pg.inputValue('#pgGorev')) === 'Paketçi');
        await pg.fill('#pgAd', 'Örnek Kişi A2'); await pg.fill('#pgGorev', 'Çavuş');
        await bekleYenile(pg, () => pg.click('#pgPenTamam'));
        await adlar(pg, '3b) ad düzenlendi + yenilendi', null);
        ok('3c) yeni ad + görev ızgarada', /Örnek Kişi A2/.test(await pg.locator('tr[data-pg-satir="' + idA + '"] th').innerText()) && /Çavuş/.test(await pg.locator('tr[data-pg-satir="' + idA + '"] th small').innerText()));

        // ── 4) Hücre işaretleme: ad sütunu kaybolmaz ──
        const hucre = pg.locator('tr[data-pg-satir="' + idA + '"] td[data-tarih]').nth(7);
        await hucre.click(); await pg.waitForSelector('#pgMenu:not([hidden])');
        await pg.locator('#pgMenu [data-pg-kod="X"]').click(); await pg.waitForTimeout(500);
        ok('4a) hücre X işaretlendi (sunucu yazdı; hücre güncellendi)', /X/.test(await hucre.innerText()));
        await adlar(pg, '4b) hücre işaretlemeden sonra', null);
        await pg.reload(); await adlar(pg, '4c) işaretlemeden sonra sayfa yenileme', null);
        ok('4d) X kaydı kalıcı (yenilemeden sonra hücre hâlâ X)', /X/.test(await pg.locator('tr[data-pg-satir="' + idA + '"] td[data-tarih]').nth(7).innerText()));

        // ── 5) Pasife al / Pasifleri göster / Aktif yap ──
        await pg.locator('tr[data-pg-satir="' + idA + '"] .pg-uc').click(); await pg.waitForSelector('#pgIslem:not([hidden])');
        await pg.locator('#pgIslem [data-pg-islem="kaldir"]').click(); await pg.waitForSelector('#pgPen:not([hidden])');
        ok('5a) Kaldır: sayfa içi onay (tarayıcı penceresi değil), mesaj "pasife alınacak"', /pasife alınacak/.test(await pg.locator('#pgPenMesaj').innerText()));
        await bekleYenile(pg, () => pg.click('#pgPenTamam'));
        const o5 = await adlar(pg, '5b) pasife alındı', null);
        ok('5c) pasif kişi listeden çıktı (varsayılan)', o5.ad.every(a => !/Örnek Kişi A2/.test(a.txt)));
        await bekleYenile(pg, () => pg.check('input[name="pasif"]'));
        const o5b = await adlar(pg, '5d) "Pasifleri de göster"', null);
        ok('5e) pasif kişi "(pasif)" etiketiyle görünür, adı yerinde', o5b.ad.some(a => /Örnek Kişi A2/.test(a.txt) && a.pasif) && /\(pasif\)/.test(await pg.locator('tr[data-pg-satir="' + idA + '"] th').innerText()));
        await pg.locator('tr[data-pg-satir="' + idA + '"] .pg-uc').click(); await pg.waitForSelector('#pgIslem:not([hidden])');
        await bekleYenile(pg, () => pg.locator('#pgIslem [data-pg-islem="geri"]').click());
        const o5c = await adlar(pg, '5f) Geri al (aktif yap)', null);
        ok('5g) kişi tekrar aktif (pasif etiketi yok)', o5c.ad.some(a => /Örnek Kişi A2/.test(a.txt) && !a.pasif));

        // ── 6) Toplu atama: kişi kutuları + gün seçimi + işlem çubuğu ──
        await git(); await pg.waitForTimeout(300);
        const idB = await satirNo('İsmail Şöför ÇĞÜÖ');
        await pg.locator('tr[data-pg-satir="' + idA + '"] input[data-pg-sec]').check();
        await pg.locator('tr[data-pg-satir="' + idB + '"] input[data-pg-sec]').check();
        await pg.locator('[data-pg-gunsec="haftaici"]').click(); await pg.waitForTimeout(150);
        const ozet = (await pg.locator('#pgCubukOzet').innerText()).trim();
        ok('6a) çubuk "2 kişi × N gün"', /^2 kişi × \d+ gün$/.test(ozet), ozet);
        await adlar(pg, '6b) kişi + gün seçiliyken (çubuk açık)', null);
        await pg.locator('#pgCubuk [data-pg-sec-kod="Y"]').click(); await pg.click('#pgUygula'); await pg.waitForSelector('#pgCubukOnay:not([hidden])');
        await pg.click('#pgOnayla'); await pg.waitForTimeout(700);
        const sonuc = (await pg.locator('#pgCubukSonuc').innerText()).trim();
        ok('6c) toplu uygulama sonucu bildirildi', /yaz|işaret|gün/i.test(sonuc), sonuc);
        const yCount = await pg.evaluate(id => [...document.querySelectorAll('tr[data-pg-satir="' + id + '"] td[data-kod="Y"]')].length, idB);
        ok('6d) seçili kişide hafta içi günler Y (sunucuya yazıldı, gün sayısı > 0)', yCount > 0, String(yCount));
        await adlar(pg, '6e) toplu atamadan sonra', null);
        await pg.reload(); await adlar(pg, '6f) toplu atama sonrası yenileme', null);
        const yKal = await pg.evaluate(id => document.querySelectorAll('tr[data-pg-satir="' + id + '"] td[data-kod="Y"]').length, idB);
        ok('6g) toplu atama kalıcı (yenilemeden sonra aynı Y sayısı)', yKal === yCount, yKal + ' vs ' + yCount);

        // ── 7) Kişiler görünümü ──
        await pg.goto(base + '/puantaj_cizelgesi.php?sekme=giris&gorunum=kisiler');
        await pg.waitForTimeout(300);
        const satirlar = await pg.evaluate(() => [...document.querySelectorAll('tr[data-pk-satir]')].map(t => ({ ad: t.querySelector('.pk-isim').textContent.trim(), w: t.querySelector('.pk-isim').getBoundingClientRect().width })));
        ok('7a) Kişiler: tüm adlar görünür (6 kişi, her ad hücresi ≥ 80px, metin dolu)', satirlar.length === 6 && satirlar.every(s => s.ad && s.w >= 80), JSON.stringify(satirlar.map(s => [s.ad.slice(0, 12), s.w])));
        ok('7b) sayaç: Toplam 6 · Aktif 6 · Pasif 0', (await pg.locator('[data-pk-say="toplam"]').innerText()) === '6' && (await pg.locator('[data-pk-say="aktif"]').innerText()) === '6');
        await bekleYenile(pg, async () => { await pg.fill('input[name="q"]', 'ismail'); await pg.keyboard.press('Enter'); });
        const ar = await pg.evaluate(() => [...document.querySelectorAll('tr[data-pk-satir] .pk-isim')].map(t => t.textContent.trim()));
        ok('7c) arama "ismail" → büyük/küçük harf + Türkçe İ duyarsız: yalnız İsmail satırı', ar.length === 1 && /İsmail/.test(ar[0]), JSON.stringify(ar));
        await pg.goto(base + '/puantaj_cizelgesi.php?sekme=giris&gorunum=kisiler&q=');
        // toplu pasif
        const kidler = await pg.evaluate(() => [...document.querySelectorAll('tr[data-pk-satir]')].slice(0, 2).map(t => t.getAttribute('data-pk-satir')));
        for (const k of kidler) await pg.locator('tr[data-pk-satir="' + k + '"] input[data-pk-sec]').check();
        ok('7d) 2 kişi seçince toplu çubuk "2 kişi seçili"', /^2 kişi seçili$/.test((await pg.locator('#pkCubukOzet').innerText()).trim()));
        await bekleYenile(pg, () => pg.click('[data-pk-toplu="pasif"]'));
        ok('7e) toplu pasif: Pasif sayacı 2', (await pg.locator('[data-pk-say="pasif"]').innerText()) === '2');
        await bekleYenile(pg, async () => { await pg.selectOption('select[name="durum"]', 'pasif'); });
        const pasifSatir = await pg.locator('tr[data-pk-satir]').count();
        ok('7f) durum süzgeci "Pasif" → 2 satır, "Gösterilen 2" çipi', pasifSatir === 2 && /Gösterilen\s*2/.test(await pg.locator('.pk-sayac').innerText()));
        for (const k of kidler) await pg.locator('tr[data-pk-satir="' + k + '"] input[data-pk-sec]').check();
        await bekleYenile(pg, () => pg.click('[data-pk-toplu="aktif"]'));
        ok('7g) toplu aktif: Pasif sayacı 0', (await pg.locator('[data-pk-say="pasif"]').innerText()) === '0');
        // Excel önizleme → ekle
        await pg.goto(base + '/puantaj_cizelgesi.php?sekme=giris&gorunum=kisiler'); await pg.waitForTimeout(250);
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').click(); await pg.waitForSelector('#pkExcel:not([hidden])');
        await pg.setInputFiles('#pkDosya', { name: 'kisiler.csv', mimeType: 'text/csv', buffer: Buffer.from('Ad Soyad;Görev\nExcel Kişi Bir;Paketçi\nExcel Kişi İki;\nÖrnek Kişi A2;Çavuş\n', 'utf8') });
        await pg.waitForSelector('#pkExcelOnizleme:not([hidden])', { timeout: 8000 });
        const onz = (await pg.locator('#pkExcelOzet').innerText()).replace(/\s+/g, ' ');
        ok('7h) Excel önizleme (KAYDETMEZ): Geçerli 2 · Mükerrer 1', /Geçerli 2/.test(onz) && /Mükerrer 1/.test(onz), onz);
        const once = await pg.evaluate(() => document.querySelectorAll('tr[data-pk-satir]').length);
        await pg.click('#pkExcelEkle'); await pg.waitForSelector('#pkExcelSonuc:not([hidden])', { timeout: 8000 });
        ok('7i) Excel ekle sonucu "2 kişi eklendi"', /2 kişi eklendi/.test(await pg.locator('#pkExcelSonuc').innerText()) && once === 6);
        await bekleYenile(pg, () => pg.click('#pkExcelVazgec'));
        ok('7j) Kapat → liste yenilendi, 8 kişi', (await pg.locator('tr[data-pk-satir]').count()) === 8);
        // Kişiler görünümünden düzenle + kalıcı sil
        const idX = await pg.evaluate(() => [...document.querySelectorAll('tr[data-pk-satir]')].find(t => t.querySelector('.pk-isim').textContent.trim() === 'Excel Kişi Bir').getAttribute('data-pk-satir'));
        await pg.locator('tr[data-pk-satir="' + idX + '"] [data-pk-islem="sil"]').click(); await pg.waitForSelector('#pgPen:not([hidden])');
        ok('7k) Sil: onay penceresi "kalıcı olarak silinecek" (tarayıcı penceresi yok)', /kalıcı olarak silinecek/.test(await pg.locator('#pgPenMesaj').innerText()));
        await bekleYenile(pg, () => pg.click('#pgPenTamam'));
        ok('7l) kalıcı silindi: 7 kişi, "Excel Kişi Bir" yok', (await pg.locator('tr[data-pk-satir]').count()) === 7 && (await pg.locator('text=Excel Kişi Bir').count()) === 0);
        // toplu sil
        const idler2 = await pg.evaluate(() => [...document.querySelectorAll('tr[data-pk-satir]')].filter(t => /^Excel Kişi/.test(t.querySelector('.pk-isim').textContent.trim())).map(t => t.getAttribute('data-pk-satir')));
        for (const k of idler2) await pg.locator('tr[data-pk-satir="' + k + '"] input[data-pk-sec]').check();
        await pg.click('[data-pk-toplu="sil"]'); await pg.waitForSelector('#pgPen:not([hidden])');
        await bekleYenile(pg, () => pg.click('#pgPenTamam'));
        ok('7m) toplu sil → 6 kişi', (await pg.locator('tr[data-pk-satir]').count()) === 6);

        // ── 8) Çıkış testleri: Puantaj görünümüne dönüş ──
        await git(); await adlar(pg, '8a) Kişiler → Puantaj dönüşü', null);
        await bekleYenile(pg, () => pg.locator('.pc-sekme', { hasText: 'Personel' }).click());
        await adlar(pg, '8b) Personel sekmesi', null);
        await bekleYenile(pg, () => pg.locator('.pc-sekme', { hasText: 'Günlük İşçi' }).click());
        await adlar(pg, '8c) Günlük İşçi sekmesi', null);
        await git();

        // ── 9) Tarih okları, yeniden boyutlandırma, kaydırma sırasında ad sütunu ──
        await bekleYenile(pg, () => pg.locator('.pc-ok').first().click());
        await adlar(pg, '9a) önceki ay (‹)', null);
        await bekleYenile(pg, () => pg.locator('.pc-ok').nth(1).click());
        await adlar(pg, '9b) sonraki ay (›) → geri', null);
        for (const [w, h] of [[1024, 768], [1280, 800], [1500, 900], [1700, 900], [2560, 1200], [900, 700], [1665, 900]]) {
            await pg.setViewportSize({ width: w, height: h }); await pg.waitForTimeout(350);
            await adlar(pg, '9c) yeniden boyutlandırma ' + w + 'px', null);
            await pg.evaluate(() => { document.getElementById('pcGridWrap').scrollLeft = 99999; }); await pg.waitForTimeout(80);
            const k = await pg.evaluate(() => { const w = document.getElementById('pcGridWrap'); const th = document.querySelector('tbody tr').firstElementChild; const e = document.elementFromPoint(th.getBoundingClientRect().left + 12, th.getBoundingClientRect().top + 8); return { sol: Math.abs(th.getBoundingClientRect().left - w.getBoundingClientRect().left), ust: !!e && th.contains(e) }; });
            ok('9d) ' + w + 'px sona kaydırınca ad sütunu yapışık ve üstte', k.sol <= 2 && k.ust, JSON.stringify(k));
        }
        ok('10) tüm akış boyunca JS hatası yok', hatalar.length === 0, hatalar.join('|'));

        // ── 11) Denetim kaydı: kişi adı YOK ──
        const adSizi = audits.filter(a => /Örnek Kişi|İsmail|Emoji Kişi|Excel Kişi|&lt;b/.test(a));
        ok('11) audit kayıtlarında (' + audits.length + ') kişi adı yok', audits.length > 10 && adSizi.length === 0, adSizi.slice(0, 2).join('|'));
        await ctx.close();
    } finally { await browser.close(); srv.close(); }
    console.log('\nGeçen: ' + gecen + '   Kalan: ' + fail + '   (PHP çağrısı: ' + phpSay + ')');
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); try { srv.close(); } catch (_) { /* */ } process.exit(2); });
