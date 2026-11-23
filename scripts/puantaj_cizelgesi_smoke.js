'use strict';
// =========================================================
// scripts/puantaj_cizelgesi_smoke.js — Aylık Puantaj Çizelgesi tarayıcı (düzen) testi
//
// PHP testleri düzen (layout) hatasını GÖREMEZ. Gerçek sayfa kodu, bellek içi SQLite ile
// scripts/_puantaj_cizelgesi_harness.php üzerinden HTML'e basılır (gerçek style.css +
// puantaj_cizelgesi.css/js, file://) ve Chromium'da 1280 / 768 / 767 / 390 genişliklerinde ölçülür.
//   node scripts/puantaj_cizelgesi_smoke.js      (Playwright yoksa/PW_PATH ile)
// =========================================================
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { pathToFileURL } = require('url');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* sıradaki */ }
}
if (!chromium) { console.error('Playwright bulunamadı; tarayıcı testi çalıştırılamadı.'); process.exit(1); }
const root = path.dirname(__dirname);
const ay = new Date().toISOString().slice(0, 7);
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'pcz_'));
function uret(sayfa, get, ek, etiket) {
    let html = execFileSync('php', [path.join(root, 'scripts/_puantaj_cizelgesi_harness.php'), sayfa, JSON.stringify(get), JSON.stringify(Object.assign({ admin: true, ay, css: true, depo: '' }, ek || {}))],
        { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] });
    html = html.split('href="assets/').join('href="' + pathToFileURL(root).href + '/assets/');   // yazdırma sayfası göreli assets/ yolu kullanır
    const f = path.join(tmp, sayfa.replace('.php', '') + '_' + (get.sekme || 'personel') + (etiket ? '_' + etiket : '') + '.html');
    fs.writeFileSync(f, html);
    return pathToFileURL(f).href;
}
const sayfalar = {
    personel: uret('puantaj_cizelgesi.php', { ay, sekme: 'personel' }),   // v555: sekmesiz açılış artık Puantaj Girişi
    gunluk: uret('puantaj_cizelgesi.php', { ay, sekme: 'gunluk' }),
    yazdir: uret('puantaj_cizelgesi_yazdir.php', { ay, sekme: 'personel' }),
};
// v553 elle işaret: tohumlu işaretler (A: 3 İ [kayıt çakışır], 6 D; B: 10 R, 11 T; C: 12 Ü). Salt okunur varyant: 'edit' izni yok, tablo yok varyantı.
const ISR = [[1, 3, 'İ'], [1, 6, 'D'], [2, 10, 'R'], [2, 11, 'T'], [3, 12, 'Ü']];
const SALT = ['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.hub.read'];
sayfalar.isaret = uret('puantaj_cizelgesi.php', { ay, sekme: 'personel' }, { isaretler: ISR }, 'isaret');
sayfalar.salt = uret('puantaj_cizelgesi.php', { ay }, { admin: false, perms: SALT, isaretler: ISR }, 'salt');
sayfalar.yazdirIsaret = uret('puantaj_cizelgesi_yazdir.php', { ay, sekme: 'personel' }, { isaretler: ISR }, 'isaret');
// v554 Puantaj Girişi (bağımsız sekme): A (X Y D İ Ü R T hepsi), B, İsmail, pasif D. Tablolar yalnız bu sayfalar için KURULUR (harness 'giris').
const GK = [[1, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1], [2, 'Örnek Kişi B', '', 'Depo A', 1], [3, 'İsmail Örnek', 'Şoför', 'Depo A', 1], [4, 'Örnek Kişi D', '', 'Depo A', 0]];
const GG = [[1, 2, 'X'], [1, 3, 'Y'], [1, 4, 'D'], [1, 5, 'İ'], [1, 6, 'Ü'], [1, 9, 'R'], [1, 10, 'T'], [2, 2, 'X'], [2, 3, 'X'], [3, 3, 'Y']];
const GBASE = { giris: true, depo: 'Depo A', allowed: ['Depo A'], giris_kisiler: GK, giris_gunler: GG };
sayfalar.giris = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris' }, GBASE, 'g');
sayfalar.girisPasif = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris', pasif: '1' }, GBASE, 'gp');
sayfalar.kisiler = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris', gorunum: 'kisiler' }, GBASE, 'k');
sayfalar.girisBos = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris' }, Object.assign({}, GBASE, { giris_kisiler: [], giris_gunler: [] }), 'gb');
sayfalar.girisYazdir = uret('puantaj_cizelgesi_yazdir.php', { ay, sekme: 'giris' }, GBASE, 'g');
let fail = 0, gecen = 0;
function ok(ad, c, d) { c ? gecen++ : fail++; console.log((c ? 'OK ' : 'FAIL ') + ad + (c || !d ? '' : ' — ' + d)); }
const opak = c => !/^rgba\(\s*0,\s*0,\s*0,\s*0\s*\)$|^transparent$/.test(c);
const lum = c => { const m = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };

let srv;
(async () => {
    const executablePath = process.env.PW_EXECUTABLE_PATH || ['/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(executablePath ? { executablePath } : {});
    try {
        // ───────── MASAÜSTÜ 1280 ─────────
        let page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const hatalar = []; page.on('pageerror', e => hatalar.push(e.message));
        await page.goto(sayfalar.personel); await page.waitForTimeout(300);
        const sol = '[1280] ';
        ok(sol + 'JS hatası yok', hatalar.length === 0, hatalar.join('|'));
        ok(sol + 'yatay taşma yok (sayfa)', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        ok(sol + 'ızgara görünür, mobil liste gizli', await page.locator('.pc-grid-wrap').isVisible() && !(await page.locator('.pc-liste').isVisible()));
        const wrap = await page.evaluate(() => { const w = document.querySelector('.pc-grid-wrap'); return { sw: w.scrollWidth, cw: w.clientWidth, ov: getComputedStyle(w).overflowX }; });
        ok(sol + 'ızgara kendi kapsayıcısında yatay kayar (31 gün sığmaz → scrollWidth > clientWidth, overflow auto)', wrap.sw > wrap.cw && wrap.ov === 'auto', JSON.stringify(wrap));
        const gunSay = await page.locator('thead th.pc-g').count();
        ok(sol + 'başlıkta gün sayısı kadar sütun (' + gunSay + ')', gunSay === new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate());
        ok(sol + 'başlıkta gün no + kısa gün adı', (await page.locator('thead th.pc-g').first().innerText()).replace(/\s+/g, ' ').match(/^\d+ [A-ZÇĞİÖŞÜ]{3}$/i) !== null);
        // yapışkan ilk sütun
        const stk = await page.evaluate(() => {
            const w = document.querySelector('.pc-grid-wrap'); const td = document.querySelector('tbody th.pc-isim');
            const cs = getComputedStyle(td);
            const s0 = td.getBoundingClientRect().left - w.getBoundingClientRect().left;
            w.scrollLeft = 400; return new Promise(r => requestAnimationFrame(() => {
                const wl = w.getBoundingClientRect().left; const r1 = td.getBoundingClientRect();
                const top = document.elementFromPoint(r1.left + 10, r1.top + r1.height / 2);
                r({ pos: cs.position, bg: cs.backgroundColor, z: cs.zIndex, s0, sol: r1.left - wl, sc: w.scrollLeft, ustte: !!top && (top === td || td.contains(top)) });
            }));
        });
        ok(sol + 'ilk sütun position:sticky + SOLID arka plan + z-index < 100', stk.pos === 'sticky' && opak(stk.bg) && /^\d+$/.test(stk.z) && +stk.z < 100, JSON.stringify(stk));
        ok(sol + 'yatay kaydırınca ilk sütun yerinde kalır ve diğer hücrelerin ÜSTÜNDE', stk.sc >= 300 && Math.abs(stk.sol - stk.s0) <= 1.5 && stk.ustte, JSON.stringify(stk));
        const bas = await page.evaluate(() => { const th = document.querySelector('thead th.pc-g'); const k = document.querySelector('thead th.pc-kose'); return { pos: getComputedStyle(th).position, kz: +getComputedStyle(k).zIndex }; });
        ok(sol + 'başlık satırı yapışkan, köşe hücresi en üstte (<100)', bas.pos === 'sticky' && bas.kz < 100 && bas.kz > 2, JSON.stringify(bas));
        const renk = await page.evaluate(() => {
            const hs = document.querySelector('tbody tr:nth-child(3) td.pc-hs'); const gun = document.querySelector('tbody tr:nth-child(3) td.pc-b:not(.pc-hs)');
            const bg = document.querySelector('tbody td.pc-x'); const e = document.querySelector('tbody td.pc-e');
            const b = document.querySelector('td.pc-bugun');
            return { hs: getComputedStyle(hs).backgroundColor, gun: getComputedStyle(gun).backgroundColor, x: getComputedStyle(bg).backgroundColor, e: getComputedStyle(e).backgroundColor, xi: bg.innerText, ei: e.innerText, bugun: b ? getComputedStyle(b).borderLeftWidth : null };
        });
        ok(sol + 'hafta sonu sütunu gri (hafta içinden farklı zemin)', renk.hs !== renk.gun, JSON.stringify(renk));
        ok(sol + 'X hücresi renkli + harf "X" + saat; ! hücresi farklı renk + harf "!"', renk.x !== renk.e && /X/.test(renk.xi) && /!/.test(renk.ei), JSON.stringify(renk));
        ok(sol + 'bugünün sütunu çerçeveli (2px)', renk.bugun === '2px', String(renk.bugun));
        ok(sol + 'alt satırlar: "O gün çalışan" + "Genel toplam"', (await page.locator('tfoot tr').count()) === 2 && /O gün çalışan/.test(await page.locator('tfoot tr').first().innerText()));
        ok(sol + 'özet sütunlarında eksik çıkış uyarı rengi (pc-uyari)', (await page.locator('tbody td.pc-oz.pc-uyari').count()) >= 1);
        ok(sol + 'üst çubuk: ay oku ‹ › + month + arama + sekmeler + Excel menüsü + Yazdır', await page.locator('.pc-ok').count() === 2 && await page.locator('input[type=month]').count() === 1 && await page.locator('input[type=search]').count() === 1 && await page.locator('.pc-sekme').count() === 3 && await page.locator('.dl-menu-btn').count() === 1 && await page.locator('a:has-text("Yazdır")').count() === 1);   // v554: yönetici 3 sekme görür (Puantaj Girişi sonda)
        ok(sol + 'gösterge (lejant) tek satır, düğmesi gizli', await page.locator('.pc-lejant-ic').isVisible() && !(await page.locator('.pc-lejant-dugme').isVisible()));
        // koyu tema
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await page.waitForTimeout(100);
        const koyu = await page.evaluate(() => { const td = document.querySelector('tbody th.pc-isim'); const c = document.querySelector('tbody td.pc-b:not(.pc-hs)'); const g = getComputedStyle(td); const gc = getComputedStyle(c);
            return { stickyBg: g.backgroundColor, stickyFg: g.color, hucre: gc.backgroundColor, x: getComputedStyle(document.querySelector('td.pc-x')).backgroundColor }; });
        ok(sol + 'koyu tema: yapışkan sütun koyu + SOLID, metin açık', opak(koyu.stickyBg) && lum(koyu.stickyBg) < 0.25 && lum(koyu.stickyFg) > 0.6, JSON.stringify(koyu));
        ok(sol + 'koyu tema: hücre zemini koyu (beyaza düşmüyor)', lum(koyu.hucre) < 0.25 && lum(koyu.x) < 0.35, JSON.stringify(koyu));
        await page.close();

        // günlük işçi sekmesi
        page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto(sayfalar.gunluk); await page.waitForTimeout(250);
        ok('[1280] günlük: not görünür, taşma yok, iki çavuş satırı', await page.locator('.pc-not').isVisible() && await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1) && await page.locator('tbody tr').count() === 2);
        ok('[1280] günlük: bugünkü hücre sayı (2) + ! rozeti', /2/.test(await page.locator('tbody td.pc-e').first().innerText()) && /!/.test(await page.locator('tbody td.pc-e').first().innerText()));
        await page.close();

        // ───────── KIRILMA NOKTASI 768 / 767 ─────────
        for (const [w, grid] of [[768, true], [767, false]]) {
            const p = await browser.newPage({ viewport: { width: w, height: 900 } });
            await p.goto(sayfalar.personel); await p.waitForTimeout(200);
            ok('[' + w + '] ' + (grid ? 'ızgara görünür, liste gizli' : 'liste görünür, ızgara gizli'), (await p.locator('.pc-grid-wrap').isVisible()) === grid && (await p.locator('.pc-liste').isVisible()) === !grid);
            ok('[' + w + '] yatay taşma yok', await p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
            await p.close();
        }

        // ───────── MOBİL 390 ─────────
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        const mh = []; page.on('pageerror', e => mh.push(e.message));
        await page.goto(sayfalar.personel); await page.waitForTimeout(300);
        const mob = '[390] ';
        ok(mob + 'JS hatası yok', mh.length === 0, mh.join('|'));
        ok(mob + 'yatay kaydırmalı ızgara YOK (gizli), kişi listesi görünür', !(await page.locator('.pc-grid-wrap').isVisible()) && await page.locator('.pc-liste').isVisible());
        ok(mob + 'sayfa yatay taşmıyor', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        ok(mob + 'kişi satırında ad + ay özeti rozetleri (gün / saat / eksik)', (await page.locator('.pc-kisi').first().locator('summary .pc-rz').count()) >= 2 && /gün/.test(await page.locator('.pc-kisi').first().locator('summary').innerText()) && /sa/.test(await page.locator('.pc-kisi').first().locator('summary').innerText()));
        ok(mob + 'eksik çıkışı olan kişide uyarı rozeti', await page.locator('.pc-kisi summary .pc-rz-uyari').count() >= 1);
        ok(mob + 'takvim başlangıçta kapalı', !(await page.locator('.pc-takvim').first().isVisible()));
        await page.locator('.pc-kisi summary').first().tap(); await page.waitForTimeout(150);
        const tk = await page.evaluate(() => { const t = document.querySelector('.pc-kisi[open] .pc-takvim'); const cs = getComputedStyle(t); const r = t.getBoundingClientRect(); const hs = t.querySelector('.pc-t.pc-x');
            return { kolon: cs.gridTemplateColumns.split(' ').length, w: r.width, sag: r.right, hucre: t.querySelectorAll('.pc-t:not(.pc-t-bos)').length, bos: t.querySelectorAll('.pc-t-bos').length, bas: [...t.querySelectorAll('.pc-t-bas')].map(x => x.textContent).join(','), x: hs ? hs.innerText.replace(/\s+/g, ' ') : null, xbg: hs ? getComputedStyle(hs).backgroundColor : null, minw: Math.min(...[...t.querySelectorAll('.pc-t:not(.pc-t-bos)')].map(x => x.getBoundingClientRect().width)) }; });
        ok(mob + 'kişiye dokununca 7 sütunlu ay takvimi açılır', tk.kolon === 7 && tk.sag <= 390 && tk.bas === 'Pzt,Sal,Çar,Per,Cum,Cmt,Paz', JSON.stringify(tk));
        ok(mob + 'takvimde gün sayısı kadar hücre; aynı kod/renk (X + saat)', tk.hucre === new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate() && /X/.test(tk.x || '') && opak(tk.xbg || ''), JSON.stringify(tk));
        ok(mob + 'takvim hücreleri dokunulabilir genişlikte (≥ 38px)', tk.minw >= 38, String(tk.minw));
        const ayw = await page.evaluate(() => document.querySelector('.pc-ay-giris').getBoundingClientRect().width);
        ok(mob + 'ay giriş alanı tam görünür (≥ 160px; ‹ › arasında genişler)', ayw >= 160, String(ayw));
        const inp = await page.evaluate(() => [...document.querySelectorAll('.pc-page input[type=month], .pc-page input[type=search]')].map(i => getComputedStyle(i).fontSize));
        ok(mob + 'inputlar 16px (iOS zoom önleme)', inp.length === 2 && inp.every(f => f === '16px'), inp.join());
        ok(mob + 'gösterge ⓘ arkasında: düğme görünür, metin gizli → dokununca açılır', await page.locator('.pc-lejant-dugme').isVisible() && !(await page.locator('.pc-lejant-ic').isVisible()));
        await page.locator('.pc-lejant-dugme').tap(); await page.waitForTimeout(100);
        ok(mob + 'gösterge açıldı + aria-expanded', await page.locator('.pc-lejant-ic').isVisible() && (await page.locator('.pc-lejant-dugme').getAttribute('aria-expanded')) === 'true');
        ok(mob + 'sekme çubuğu tam genişlik, Excel + Yazdır erişilebilir', await page.locator('.pc-sekmeler').isVisible() && await page.locator('.pc-aksiyon').isVisible());
        const alt = await page.evaluate(() => { const c = document.querySelector('main.container'); return parseFloat(getComputedStyle(c).paddingBottom); });
        ok(mob + 'container alt boşluğu --bn-h payını korur (≥ 92px)', alt >= 92, String(alt));
        await page.close();

        // günlük mobil
        page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        await page.goto(sayfalar.gunluk); await page.waitForTimeout(200);
        ok('[390] günlük: not + çavuş listesi, taşma yok', await page.locator('.pc-not').isVisible() && await page.locator('.pc-kisi').count() === 2 && await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
        await page.close();

        // ───────── YAZDIRMA ─────────
        page = await browser.newPage({ viewport: { width: 1123, height: 794 } });
        await page.goto(sayfalar.yazdir); await page.waitForTimeout(200);
        ok('[yazdır] tablo + başlık tekrarı (thead) + 4 kişi', await page.locator('.pcy-tablo thead').count() === 1 && await page.locator('.pcy-tablo tbody tr').count() === 4);
        const pdf = await page.pdf({ format: 'A4', landscape: true, printBackground: true });
        const sayfaSayisi = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
        ok('[yazdır] yatay A4: 4 kişilik çizelge 1 sayfaya sığar (' + sayfaSayisi + ' sayfa)', pdf.slice(0, 4).toString() === '%PDF' && sayfaSayisi === 1);
        await page.emulateMedia({ media: 'print' });
        ok('[yazdır] yazdırmada işlem düğmeleri gizli', !(await page.locator('.pr-actions').isVisible()));
        const tasma = await page.evaluate(() => { const t = document.querySelector('.pcy-tablo'); return t.scrollWidth <= t.clientWidth + 1; });
        ok('[yazdır] tablo kâğıt genişliğine sığar (sütunlar taşmaz)', tasma);
        await page.close();

        // ───────── v553: ELLE İŞARET — masaüstü menü ─────────
        const gunSayisi = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).getDate();
        // fetch() file:// sayfasından çalışmaz (ve Playwright rotası yakalayamaz): işaret sayfaları yerel HTTP sunucusundan açılır
        const http = require('http');
        srv = http.createServer((req, res) => {
            const u = decodeURIComponent((req.url || '').split('?')[0]);
            let dosya = null;
            if (u.startsWith('/__t/')) dosya = path.join(tmp, path.basename(u));
            else if (u.startsWith('/assets/')) dosya = path.join(root, 'assets', path.basename(u));
            if (!dosya || !fs.existsSync(dosya)) { res.statusCode = 404; return res.end('yok'); }
            const tip = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'application/javascript' }[path.extname(dosya)] || 'application/octet-stream';
            res.setHeader('Content-Type', tip); res.end(fs.readFileSync(dosya));
        });
        await new Promise(r => srv.listen(0, '127.0.0.1', r));
        const kaynakUrl = 'http://127.0.0.1:' + srv.address().port + '/';
        const httpSayfa = href => {
            const dosya = decodeURIComponent(new URL(href).pathname);
            const ad = 'http_' + path.basename(dosya);
            fs.writeFileSync(path.join(tmp, ad), fs.readFileSync(dosya, 'utf8').split(pathToFileURL(root).href + '/').join(kaynakUrl));
            return kaynakUrl + '__t/' + ad;
        };
        let istekler = [];   // yakalanan POST'lar
        async function rotaKur(pg, yanit) {
            await pg.route('**/puantaj_isaret_kaydet.php', async route => {
                istekler.push({ m: route.request().method(), body: route.request().postData() || '', ct: route.request().headers()['content-type'] || '' });
                if (yanit === 'iptal') return route.abort();
                return route.fulfill({ status: yanit.status || 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(yanit.json) });
            });
        }
        page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const ih = []; page.on('pageerror', e => ih.push(e.message));
        await page.goto(httpSayfa(sayfalar.isaret)); await page.waitForTimeout(300);
        const ms = '[1280 işaret] ';
        ok(ms + 'JS hatası yok', ih.length === 0, ih.join('|'));
        const ozel = await page.evaluate(() => {
            const renk = k => { const el = document.querySelector('tbody td.pc-k-' + k); return el ? { bg: getComputedStyle(el).backgroundColor, t: el.innerText.trim() } : null; };
            return { iz: renk('iz'), d: renk('d'), r: renk('r'), t: renk('t'), uc: renk('uc'), kn: !!document.querySelector('tbody td.pc-k .pc-kn') };
        });
        const bglar = ['iz', 'd', 'r', 't', 'uc'].map(k => ozel[k] && ozel[k].bg);
        ok(ms + '5 kodun hücresi var; her biri BAŞKA zemin rengi + doğru harf (İ D R T Ü)', bglar.every(Boolean) && new Set(bglar).size === 5 && ozel.iz.t.startsWith('İ') && ozel.d.t.startsWith('D') && ozel.r.t.startsWith('R') && ozel.t.t.startsWith('T') && ozel.uc.t.startsWith('Ü'), JSON.stringify(ozel));
        ok(ms + 'kayıt çakışan işaret hücresinde küçük nokta (pc-kn) var', ozel.kn);
        const hucreler = await page.locator('tbody td[data-pi]').count();
        ok(ms + 'düzenlenebilir hücre sayısı = 4 kişi × gün (' + hucreler + ')', hucreler === 4 * gunSayisi, String(hucreler));
        ok(ms + 'imleç pointer (etkileşim ipucu)', await page.evaluate(() => getComputedStyle(document.querySelector('tbody td[data-pi]')).cursor === 'pointer'));
        ok(ms + 'başlangıçta menü gizli', !(await page.locator('#piMenu').isVisible()));
        const bos = page.locator('tbody tr:first-child td[data-pi][data-kod=""]').nth(8);
        await bos.click(); await page.waitForTimeout(150);
        const m1 = await page.evaluate(() => { const m = document.getElementById('piMenu'); const r = m.getBoundingClientRect(); const bs = [...m.querySelectorAll('[data-pi-kod]')];
            return { gor: getComputedStyle(m).display !== 'none', sol: r.left, ust: r.top, sag: r.right, alt: r.bottom, w: innerWidth, h: innerHeight, z: +getComputedStyle(m).zIndex, pos: getComputedStyle(m).position, baslik: document.getElementById('piBaslik').textContent, n: bs.length, harfler: bs.filter(b => b.getAttribute('data-pi-kod')).map(b => b.querySelector('b').textContent).join(''), temizDis: bs.find(b => b.getAttribute('data-pi-kod') === '').disabled, odak: document.activeElement && document.activeElement.getAttribute('data-pi-kod'), sec: document.querySelectorAll('.pi-sec').length, yt: document.documentElement.scrollWidth <= innerWidth + 1, kapat: getComputedStyle(m.querySelector('.pi-kapat')).display }; });
        ok(ms + 'hücreye tıklayınca menü açılır: fixed, z-index ≥ 600, ekran içinde, yatay taşma yok', m1.gor && m1.pos === 'fixed' && m1.z >= 600 && m1.sol >= 0 && m1.sag <= m1.w && m1.ust >= 0 && m1.alt <= m1.h && m1.yt, JSON.stringify(m1));
        ok(ms + 'menü: başlıkta kişi adı + gün; 5 kod harfi (D İ Ü R T) + Temizle; boş hücrede Temizle pasif; ilk düğmeye odak; seçili hücre vurgulu; × düğmesi masaüstünde gizli', /Örnek Personel A/.test(m1.baslik) && m1.n === 6 && m1.harfler === 'Dİ' + 'ÜRT' && m1.temizDis === true && m1.odak === 'D' && m1.sec === 1 && m1.kapat === 'none', JSON.stringify(m1));
        await page.keyboard.press('Escape'); await page.waitForTimeout(100);
        ok(ms + 'Esc menüyü kapatır, vurgu kalkar', !(await page.locator('#piMenu').isVisible()) && await page.locator('.pi-sec').count() === 0);
        await bos.click(); await page.waitForTimeout(100);
        await page.mouse.click(5, 450); await page.waitForTimeout(100);
        ok(ms + 'dışarı tıklayınca menü kapanır', !(await page.locator('#piMenu').isVisible()));
        await page.locator('tbody td[data-pi][data-kod="İ"]').first().click(); await page.waitForTimeout(100);
        const m2 = await page.evaluate(() => { const bs = [...document.querySelectorAll('#piMenu [data-pi-kod]')]; return { basili: bs.filter(b => b.getAttribute('aria-pressed') === 'true').map(b => b.getAttribute('data-pi-kod')).join(), temizDis: bs.find(b => b.getAttribute('data-pi-kod') === '').disabled }; });
        ok(ms + 'işaretli hücrede mevcut kod seçili (aria-pressed=İ) ve Temizle AÇIK', m2.basili === 'İ' && m2.temizDis === false, JSON.stringify(m2));
        await page.keyboard.press('Escape');
        // sağ kenar: son gün hücresi → menü ekrandan taşmaz
        await page.evaluate(() => { const w = document.querySelector('.pc-grid-wrap'); w.scrollLeft = w.scrollWidth; }); await page.waitForTimeout(100);
        await page.locator('tbody tr:first-child td[data-pi]').nth(gunSayisi - 1).click(); await page.waitForTimeout(100);
        const m3 = await page.evaluate(() => { const r = document.getElementById('piMenu').getBoundingClientRect(); return { sol: r.left, sag: r.right, w: innerWidth }; });
        ok(ms + 'sağ kenardaki hücrede menü ekrandan taşmaz (sola açılır)', m3.sol >= 0 && m3.sag <= m3.w, JSON.stringify(m3));
        await page.keyboard.press('Escape');
        // başarı yolu: fetch POST → istek gövdesi + sayfa yenilenir
        istekler = [];
        await rotaKur(page, { json: { ok: true, islem: 'create', kod: 'R' } });
        await page.evaluate(() => { document.querySelector('.pc-grid-wrap').scrollLeft = 0; });
        const hedef = page.locator('tbody tr:first-child td[data-pi][data-kod=""]').nth(14);
        const hedefTarih = await hedef.getAttribute('data-tarih');
        await hedef.click(); await page.waitForTimeout(100);
        const yuk = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.locator('#piMenu [data-pi-kod="R"]').click();
        const yuklendi = await yuk; await page.waitForTimeout(200);
        const p0 = istekler[0] || {};
        const kv = Object.fromEntries(new URLSearchParams(p0.body || ''));
        ok(ms + 'seçim: tek POST, form gövdesi csrf + employee_id + tarih + kod', istekler.length === 1 && p0.m === 'POST' && kv.csrf === 'testcsrf' && kv.employee_id === '1' && kv.tarih === hedefTarih && kv.kod === 'R' && /x-www-form-urlencoded/.test(p0.ct), JSON.stringify(istekler));
        ok(ms + 'başarıda sayfa yenilenir (aynı ay/sekme)', yuklendi !== null);
        await rotaKur(page, { json: { ok: true } }).catch(() => {});
        // hata yolu: sunucu reddi → menü AÇIK, nazik mesaj
        await page.unroute('**/puantaj_isaret_kaydet.php');
        await rotaKur(page, { status: 403, json: { ok: false, error: 'Bu işlem için yetkiniz yok.' } });
        await page.locator('tbody td[data-pi]').first().click(); await page.waitForTimeout(100);
        istekler = [];
        await page.locator('#piMenu [data-pi-kod="D"]').click(); await page.waitForTimeout(250);
        const hz = await page.evaluate(() => { const h = document.getElementById('piHata'); return { gor: !h.hidden, t: h.textContent, acik: getComputedStyle(document.getElementById('piMenu')).display !== 'none', yukleniyor: document.getElementById('piMenu').classList.contains('pi-yukleniyor') }; });
        ok(ms + 'sunucu hatasında: nazik mesaj görünür, menü açık kalır, tekrar denenebilir', hz.gor && /Kaydedilemedi: Bu işlem için yetkiniz yok\./.test(hz.t) && hz.acik && !hz.yukleniyor, JSON.stringify(hz));
        await page.unroute('**/puantaj_isaret_kaydet.php');
        await rotaKur(page, 'iptal');
        await page.locator('#piMenu [data-pi-kod="D"]').click(); await page.waitForTimeout(250);
        ok(ms + 'bağlantı hatasında: "bağlantı kurulamadı" mesajı, menü açık', /bağlantı kurulamadı/.test(await page.locator('#piHata').innerText()) && await page.locator('#piMenu').isVisible());
        // koyu tema
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await page.waitForTimeout(100);
        const kt = await page.evaluate(() => ({ menu: getComputedStyle(document.getElementById('piMenu')).backgroundColor, t: getComputedStyle(document.querySelector('tbody td.pc-k-t')).backgroundColor, d: getComputedStyle(document.querySelector('tbody td.pc-k-d')).backgroundColor, tfg: getComputedStyle(document.querySelector('tbody td.pc-k-t')).color }));
        ok(ms + 'koyu tema: menü koyu zemin; T/D hücreleri okunur (harf rengi ≠ zemin)', lum(kt.menu) < 0.25 && kt.t !== kt.tfg && Math.abs(lum(kt.t) - lum(kt.tfg)) > 0.3, JSON.stringify(kt));
        await page.emulateMedia({ media: 'print' });
        ok(ms + 'yazdırmada menü/örtü gizli', !(await page.locator('#piMenu').isVisible()) && !(await page.locator('#piOvl').isVisible()));
        await page.close();

        // ───────── v553: yetkisiz (edit YOK) → salt okunur ─────────
        page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto(httpSayfa(sayfalar.salt)); await page.waitForTimeout(250);
        ok('[1280 salt] işaretler görünür ama hiçbir hücre etkileşimli (data-pi yok, menü yok, imleç pointer DEĞİL)', await page.locator('tbody td.pc-k').count() >= 5 && await page.locator('[data-pi]').count() === 0 && await page.locator('#piMenu').count() === 0 && await page.evaluate(() => getComputedStyle(document.querySelector('tbody td.pc-k')).cursor !== 'pointer'));
        await page.locator('tbody td.pc-k').first().click({ force: true }); await page.waitForTimeout(100);
        ok('[1280 salt] hücreye tıklamak hiçbir şey açmaz', await page.locator('.pi-menu').count() === 0);
        await page.close();

        // ───────── v553: mobil alt sayfa (bottom-sheet) ─────────
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        const mhh = []; page.on('pageerror', e => mhh.push(e.message));
        await page.goto(httpSayfa(sayfalar.isaret)); await page.waitForTimeout(300);
        const mb = '[390 işaret] ';
        istekler = [];
        await rotaKur(page, { json: { ok: true } });
        await page.locator('.pc-kisi summary').first().tap(); await page.waitForTimeout(150);
        const g = page.locator('.pc-kisi[open] .pc-t[data-pi][data-kod=""]').nth(9);
        await g.tap(); await page.waitForTimeout(250);
        const sh = await page.evaluate(() => { const m = document.getElementById('piMenu'); const r = m.getBoundingClientRect(); const cs = getComputedStyle(m); const bs = [...m.querySelectorAll('[data-pi-kod]')].map(b => b.getBoundingClientRect());
            return { gor: cs.display !== 'none', sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.getBoundingClientRect().width, h: innerHeight, z: +cs.zIndex, pos: cs.position, enKucuk: Math.min(...bs.map(b => b.height)), kapat: getComputedStyle(m.querySelector('.pi-kapat')).display, ovl: getComputedStyle(document.getElementById('piOvl')).display, ovlz: +getComputedStyle(document.getElementById('piOvl')).zIndex, form: m.closest('form') !== null || m.querySelector('form') !== null, yt: document.documentElement.scrollWidth <= innerWidth + 1, ust: r.top, baslik: document.getElementById('piBaslik').textContent };
        });
        ok(mb + 'JS hatası yok', mhh.length === 0, mhh.join('|'));
        ok(mb + 'güne dokununca ALT SAYFA açılır: tam genişlik, ekranın altına yapışık, z-index ≥ 600 (alt çubuk 500 üstünde), yatay taşma yok', sh.gor && sh.pos === 'fixed' && Math.abs(sh.sol) <= 1 && Math.abs(sh.sag - sh.w) <= 1 && Math.abs(sh.alt - sh.h) <= 1 && sh.z >= 600 && sh.yt, JSON.stringify(sh));
        ok(mb + 'alt sayfa: örtü z ≥ 599 görünür, × düğmesi görünür, düğmeler dokunulabilir (≥ 44px), <form> YOK, başlıkta ad', sh.ovl !== 'none' && sh.ovlz >= 599 && sh.kapat !== 'none' && sh.enKucuk >= 44 && sh.form === false && /Örnek Personel A/.test(sh.baslik), JSON.stringify(sh));
        ok(mb + 'alt sayfa ekranın yarısından fazlasını kaplamaz (içerik görünür kalır)', sh.h - sh.ust < sh.h * 0.6, JSON.stringify(sh));
        await page.locator('#piMenu .pi-kapat').tap(); await page.waitForTimeout(120);
        ok(mb + '× düğmesi kapatır', !(await page.locator('#piMenu').isVisible()));
        await g.tap(); await page.waitForTimeout(150);
        await page.mouse.click(195, 100); await page.waitForTimeout(150);
        ok(mb + 'örtüye dokunmak kapatır', !(await page.locator('#piMenu').isVisible()));
        await g.tap(); await page.waitForTimeout(150);
        const yuk2 = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.locator('#piMenu [data-pi-kod="Ü"]').tap();
        const yuklendi2 = await yuk2; await page.waitForTimeout(300);
        const kv2 = Object.fromEntries(new URLSearchParams((istekler[0] || {}).body || ''));
        ok(mb + 'seçim: POST kod=Ü gönderildi, sayfa yenilendi', istekler.length === 1 && kv.csrf !== undefined && kv2.kod === 'Ü' && kv2.employee_id === '1' && yuklendi2 !== null, JSON.stringify(istekler));
        ok(mb + 'yenilemeden sonra işaretlenen kişinin takvimi açık geri gelir (kaldığı yer korunur)', await page.locator('.pc-kisi[data-emp-kisi="1"][open]').count() === 1);
        await page.close();

        // ───────── v553: yazdırma — işaretli hücreler ─────────
        page = await browser.newPage({ viewport: { width: 1123, height: 794 } });
        await page.goto(sayfalar.yazdirIsaret); await page.waitForTimeout(200);
        const yz = await page.evaluate(() => ({ n: document.querySelectorAll('.pcy-tablo td.pcy-k').length, harf: [...document.querySelectorAll('.pcy-tablo td.pcy-k b')].map(b => b.textContent).join(''), kenar: getComputedStyle(document.querySelector('.pcy-tablo td.pcy-k')).borderTopWidth, ust: [...document.querySelectorAll('.pcy-tablo thead th')].slice(-3).map(t => t.textContent.trim()).join('/'), tasma: (() => { const t = document.querySelector('.pcy-tablo'); return t.scrollWidth <= t.clientWidth + 1; })() }));
        ok('[yazdır işaret] 5 işaretli hücre HARFLE (D İ Ü R T), çerçeveli; başlıkta İzin/Rapor/Devamsız; tablo kâğıda sığar', yz.n === 5 && [...'Dİ' + 'ÜRT'].every(c => yz.harf.includes(c)) && parseFloat(yz.kenar) >= 1 && yz.ust === 'İzin/Rapor/Devamsız' && yz.tasma, JSON.stringify(yz));
        const pdf2 = await page.pdf({ format: 'A4', landscape: true, printBackground: true });
        ok('[yazdır işaret] yatay A4, 4 kişilik çizelge hâlâ 1 sayfa', (pdf2.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length === 1);
        await page.close();

        // ───────── v554: PUANTAJ GİRİŞİ (bağımsız, gayri resmi sekme) ─────────
        {   // blok kapsamı: önceki bölümlerle değişken adı çakışmasın
        let girisIstek = [];   // yakalanan POST'lar (puantaj_giris_kaydet.php)
        // Sahte sunucu: gerçek kaydet ucu PHP testinde doğrulanır (5e); burada istemci davranışı ölçülür. 'ok' = isteğe uygun degisim döner.
        async function girisRota(pg, mod) {
            await pg.route('**/puantaj_giris_kaydet.php', async route => {
                const ham0 = route.request().postData() || '';
                const kv = /application\/json/.test(route.request().headers()['content-type'] || '') ? JSON.parse(ham0) : Object.fromEntries(new URLSearchParams(ham0));
                girisIstek.push(kv);
                if (mod === 'iptal') return route.abort();
                if (mod && mod.status) return route.fulfill({ status: mod.status, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(mod.json) });
                let j = { ok: true, islem: kv.action };
                if (kv.action === 'gun_isaretle') j.degisim = [{ k: +kv.kisi_id, t: kv.tarih, c: kv.kod }];
                if (kv.action === 'gun_toplu_sec') j.degisim = kv.kisi_ids.flatMap(k => kv.tarihler.map(t => ({ k: +k, t, c: kv.kod })));
                if (kv.action === 'gun_sutun') j.degisim = [1, 2, 3].map(k => ({ k, t: kv.tarih, c: kv.kod }));
                if (kv.action === 'gun_toplu' && kv.mod === 'haftaici_x') j.degisim = [12, 13].map(g => ({ k: +kv.kisi_id, t: ay + '-' + String(g).padStart(2, '0'), c: 'X' }));
                if (kv.action === 'gun_toplu' && kv.mod === 'ay_temizle') j.degisim = [2, 3, 4, 5, 6, 9, 10].map(g => ({ k: +kv.kisi_id, t: ay + '-' + String(g).padStart(2, '0'), c: '' }));
                return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(j) });
            });
        }
        const gs = '[1280 giriş] ';
        page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const gh = []; page.on('pageerror', e => gh.push(e.message));
        await page.goto(httpSayfa(sayfalar.giris)); await page.waitForTimeout(300);
        ok(gs + 'JS hatası yok; yatay taşma yok (sayfa); ızgara görünür, mobil liste gizli', gh.length === 0 && await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1) && await page.locator('.pc-grid-wrap').isVisible() && !(await page.locator('.pc-liste').isVisible()), gh.join('|'));
        ok(gs + 'sekme çubuğu (v555): Puantaj Girişi (İLK, aktif) · Personel · Günlük İşçi', (await page.locator('.pc-sekme').allInnerTexts()).join('|') === 'Puantaj Girişi|Personel|Günlük İşçi' && (await page.locator('.pc-sekme.is-aktif').innerText()) === 'Puantaj Girişi');
        ok(gs + 'bilgi satırı (gayri resmi) görünür + lejant', /gayri resmi \/ göstermeliktir; PDKS, hakediş ve diğer modüllerle ilişkisi yoktur\./.test(await page.locator('.pg-bilgi').innerText()));
        const kodlar = await page.evaluate(() => {
            const o = {}; document.querySelectorAll('tbody td[data-kod]:not([data-kod=""])').forEach(td => { const k = td.getAttribute('data-kod'); if (!o[k]) o[k] = { bg: getComputedStyle(td).backgroundColor, t: td.innerText.replace(/\s+/g, '') }; });
            return o;
        });
        const bgG = Object.values(kodlar).map(v => v.bg);
        ok(gs + 'yedi kodun hücresi var; her biri BAŞKA zemin rengi (renk + harf); Y hücresinde ½', Object.keys(kodlar).length === 7 && new Set(bgG).size === 7 && kodlar['Y'].t === 'Y½' && kodlar['X'].t === 'X' && kodlar['İ'].t === 'İ', JSON.stringify(kodlar));
        const ozA = await page.evaluate(() => { const tr = document.querySelector('tr[data-pg-satir="1"]'); return ['gun', 'izin', 'rapor', 'devamsiz'].map(k => tr.querySelector('td[data-oz="' + k + '"]').textContent.trim()).join('/'); });
        ok(gs + 'özet A: Gün 1,5 (X + Y/2) · İzin 2 (İ+Ü) · Rapor 1 · Devamsız 1; T hiçbirine girmez', ozA === '1,5/2/1/1', ozA);
        const wk = await page.evaluate(() => { const th = document.querySelector('thead th.pc-hs'); const td = document.querySelector('tbody tr td.pc-hs[data-pg]'); return { th: !!th, td: !!td, tdBg: td ? getComputedStyle(td).backgroundColor : '', cur: td ? getComputedStyle(td).cursor : '' }; });
        ok(gs + 'hafta sonu sütunu gri ve işaretlenebilir (data-pg + imleç pointer)', wk.th && wk.td && wk.cur === 'pointer', JSON.stringify(wk));
        ok(gs + 'her aktif kişide gün sayısı kadar düzenlenebilir hücre; başlangıçta pencereler gizli', await page.locator('tbody td[data-pg]').count() === 3 * gunSayisi && !(await page.locator('#pgMenu').isVisible()) && !(await page.locator('#pgIslem').isVisible()) && !(await page.locator('#pgPen').isVisible()));

        // + Kişi ekle penceresi
        girisIstek = [];
        await girisRota(page, 'ok');
        await page.locator('.pc-aksiyon [data-pg-kisi-ekle]').click(); await page.waitForTimeout(200);
        const pe = await page.evaluate(() => { const p = document.getElementById('pgPen'); const r = p.getBoundingClientRect(); const cs = getComputedStyle(p); const ad = document.getElementById('pgAd'); return { gor: cs.display !== 'none', pos: cs.position, z: +cs.zIndex, sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, w: innerWidth, h: innerHeight, form: p.querySelector('form') !== null || !!p.closest('form'), odak: document.activeElement === ad, baslik: document.getElementById('pgPenBaslik').textContent, alan: !document.getElementById('pgPenAlan').hidden, mesaj: document.getElementById('pgPenMesaj').hidden, maxAd: ad.maxLength, maxGorev: document.getElementById('pgGorev').maxLength, ovl: getComputedStyle(document.getElementById('pgOvl')).display !== 'none', yt: document.documentElement.scrollWidth <= innerWidth + 1 }; });
        ok(gs + '+ Kişi ekle: pencere açılır — fixed, z-index ≥ 600, ekran içinde ortada, <form> YOK, Ad alanına odak, maxlength 120/80, örtü var', pe.gor && pe.pos === 'fixed' && pe.z >= 600 && pe.sol >= 0 && pe.sag <= pe.w && pe.ust >= 0 && pe.alt <= pe.h && !pe.form && pe.odak && pe.maxAd === 120 && pe.maxGorev === 80 && pe.ovl && pe.alan && pe.mesaj && pe.baslik === 'Kişi ekle' && pe.yt, JSON.stringify(pe));
        await page.locator('#pgPenTamam').click(); await page.waitForTimeout(150);
        ok(gs + 'Ad boşken Kaydet: istek GİTMEZ, "Ad zorunludur." görünür, pencere açık', girisIstek.length === 0 && /Ad zorunludur/.test(await page.locator('#pgPenHata').innerText()) && await page.locator('#pgPen').isVisible());
        await page.fill('#pgAd', 'Örnek Kişi Z'); await page.fill('#pgGorev', 'Depocu');
        const yukEkle = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.keyboard.press('Enter');   // Enter ile gönder
        const yuklendiEkle = await yukEkle; await page.waitForTimeout(150);
        ok(gs + 'Enter ile gönderilir: tek POST {action=kisi_ekle, ad, gorev, csrf}; başarıda sayfa yenilenir', girisIstek.length === 1 && girisIstek[0].action === 'kisi_ekle' && girisIstek[0].ad === 'Örnek Kişi Z' && girisIstek[0].gorev === 'Depocu' && girisIstek[0].csrf === 'testcsrf' && yuklendiEkle !== null, JSON.stringify(girisIstek));
        await page.unroute('**/puantaj_giris_kaydet.php');
        // Esc / Vazgeç kapatır; sunucu reddi: pencere açık + mesaj
        await page.locator('.pc-aksiyon [data-pg-kisi-ekle]').click(); await page.waitForTimeout(100);
        await page.keyboard.press('Escape'); await page.waitForTimeout(100);
        ok(gs + 'Esc pencereyi kapatır (örtü de)', !(await page.locator('#pgPen').isVisible()) && !(await page.locator('#pgOvl').isVisible()));
        await girisRota(page, { status: 400, json: { ok: false, error: 'Ad 1 ile 120 karakter arasında olmalıdır.' } });
        await page.locator('.pc-aksiyon [data-pg-kisi-ekle]').click(); await page.fill('#pgAd', 'x'); await page.locator('#pgPenTamam').click(); await page.waitForTimeout(200);
        ok(gs + 'sunucu reddinde: nazik mesaj görünür, pencere açık kalır, tekrar denenebilir', /Kaydedilemedi: Ad 1 ile 120/.test(await page.locator('#pgPenHata').innerText()) && await page.locator('#pgPen').isVisible() && !(await page.locator('#pgPen').evaluate(e => e.classList.contains('pg-mesgul'))));
        await page.locator('#pgPen [data-pg-pen-iptal]').first().click(); await page.waitForTimeout(100);
        await page.unroute('**/puantaj_giris_kaydet.php');

        // hücre menüsü + YERİNDE güncelleme
        girisIstek = [];
        await girisRota(page, 'ok');
        await page.evaluate(() => { window.__ayniSayfa = 1; });
        const hedefT = await page.locator('tbody tr[data-pg-satir="2"] td[data-pg][data-kod=""]').nth(10).getAttribute('data-tarih');
        const bosHucre = page.locator('tbody tr[data-pg-satir="2"] td[data-tarih="' + hedefT + '"]');   // sabit hücre (kod değişince seçici kaymasın)
        await bosHucre.click(); await page.waitForTimeout(150);
        const m1 = await page.evaluate(() => { const m = document.getElementById('pgMenu'); const r = m.getBoundingClientRect(); const cs = getComputedStyle(m); const bs = [...m.querySelectorAll('[data-pg-kod]')]; return { gor: cs.display !== 'none', pos: cs.position, z: +cs.zIndex, sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, w: innerWidth, h: innerHeight, baslik: document.getElementById('pgMenuBaslik').textContent, harfler: bs.filter(b => b.getAttribute('data-pg-kod')).map(b => b.getAttribute('data-pg-kod')).join(''), temizDis: bs.find(b => b.getAttribute('data-pg-kod') === '').disabled, sec: document.querySelectorAll('.pi-sec').length, form: !!m.querySelector('form') }; });
        ok(gs + 'hücreye tıklayınca kod menüsü: fixed, z ≥ 600, ekran içinde, başlıkta ad + gün, 7 kod (X Y D İ Ü R T) + Temizle (boş hücrede pasif), <form> YOK', m1.gor && m1.pos === 'fixed' && m1.z >= 600 && m1.sol >= 0 && m1.sag <= m1.w && m1.ust >= 0 && m1.alt <= m1.h && /Örnek Kişi B/.test(m1.baslik) && m1.harfler === 'XYDİÜRT' && m1.temizDis === true && m1.sec === 1 && !m1.form, JSON.stringify(m1));
        await page.locator('#pgMenu [data-pg-kod="Y"]').click(); await page.waitForTimeout(250);
        const ist = girisIstek[0] || {};
        const yer = await page.evaluate((t) => { const td = document.querySelector('tbody tr[data-pg-satir="2"] td[data-tarih="' + t + '"]'); const tr = td.closest('tr'); return { kod: td.getAttribute('data-kod'), sinif: td.className, ic: td.innerText.replace(/\s+/g, ''), gun: tr.querySelector('td[data-oz="gun"]').textContent, genel: document.querySelector('td[data-gz-oz="gun"]').textContent, say: document.querySelector('td[data-pg-say="' + t + '"]').textContent, ayni: window.__ayniSayfa === 1, menu: getComputedStyle(document.getElementById('pgMenu')).display, mob: document.querySelector('details[data-emp-kisi="2"] .pc-t[data-tarih="' + t + '"]').getAttribute('data-kod'), mobRz: document.querySelector('details[data-emp-kisi="2"] [data-rz="gun"]').textContent, mobGz: document.querySelector('.pc-genel-kart [data-gz="gun"]').textContent }; }, hedefT);
        ok(gs + 'seçim: tek POST {action=gun_isaretle, kisi_id=2, tarih, kod=Y, csrf}', girisIstek.length === 1 && ist.action === 'gun_isaretle' && ist.kisi_id === '2' && ist.tarih === hedefT && ist.kod === 'Y' && ist.csrf === 'testcsrf', JSON.stringify(girisIstek));
        ok(gs + 'YERİNDE güncelleme (sayfa yenilenmedi): hücre Y½ + pc-k-y; kişi Gün 2 → 2,5; genel 3,5 → 4; "o gün çalışan" 1; mobil takvim + rozet de güncel; menü kapandı', yer.kod === 'Y' && /pc-k-y/.test(yer.sinif) && yer.ic === 'Y½' && yer.gun === '2,5' && yer.genel === '4,5' && yer.say === '1' && yer.ayni && yer.menu === 'none' && yer.mob === 'Y' && yer.mobRz === '2,5 gün' && yer.mobGz === '4,5 gün', JSON.stringify(yer));
        // temizle
        await bosHucre.click(); await page.waitForTimeout(100);
        const temizAcik = await page.evaluate(() => !document.querySelector('#pgMenu [data-pg-kod=""]').disabled && document.querySelector('#pgMenu [data-pg-kod="Y"]').getAttribute('aria-pressed') === 'true');
        girisIstek = [];
        await page.locator('#pgMenu [data-pg-kod=""]').click(); await page.waitForTimeout(250);
        const yer2 = await page.evaluate((t) => { const td = document.querySelector('tbody tr[data-pg-satir="2"] td[data-tarih="' + t + '"]'); return { kod: td.getAttribute('data-kod'), sinif: td.className, ic: td.innerText.trim(), gun: td.closest('tr').querySelector('td[data-oz="gun"]').textContent, say: document.querySelector('td[data-pg-say="' + t + '"]').textContent }; }, hedefT);
        ok(gs + 'işaretli hücrede mevcut kod seçili (aria-pressed) + Temizle AÇIK; Temizle: kod="" gönderilir, hücre boşalır (pc-b, "·"), kişi Gün 2\'ye, "o gün çalışan" "·"\'a döner', temizAcik && girisIstek[0].kod === '' && yer2.kod === '' && /pc-b/.test(yer2.sinif) && !/pc-k/.test(yer2.sinif) && yer2.ic === '·' && yer2.gun === '2' && yer2.say === '·', JSON.stringify([yer2, girisIstek]));
        // hata → hücre DEĞİŞMEZ
        await page.unroute('**/puantaj_giris_kaydet.php');
        await girisRota(page, { status: 403, json: { ok: false, error: 'Bu işlem için yetkiniz yok.' } });
        await bosHucre.click(); await page.waitForTimeout(100);
        await page.locator('#pgMenu [data-pg-kod="X"]').click(); await page.waitForTimeout(250);
        const hz = await page.evaluate((t) => { const td = document.querySelector('tbody tr[data-pg-satir="2"] td[data-tarih="' + t + '"]'); return { kod: td.getAttribute('data-kod'), hata: document.getElementById('pgMenuHata').textContent, hataGor: !document.getElementById('pgMenuHata').hidden, acik: getComputedStyle(document.getElementById('pgMenu')).display !== 'none', mesgul: document.getElementById('pgMenu').classList.contains('pg-mesgul'), gun: td.closest('tr').querySelector('td[data-oz="gun"]').textContent }; }, hedefT);
        ok(gs + 'sunucu hatasında: hücre ve özetler DEĞİŞMEZ (sunucu yanıtı gerçek kaynak), nazik mesaj, menü açık, tekrar denenebilir', hz.kod === '' && hz.gun === '2' && /Kaydedilemedi: Bu işlem için yetkiniz yok\./.test(hz.hata) && hz.hataGor && hz.acik && !hz.mesgul, JSON.stringify(hz));
        await page.unroute('**/puantaj_giris_kaydet.php');
        await girisRota(page, 'iptal');
        await page.locator('#pgMenu [data-pg-kod="X"]').click(); await page.waitForTimeout(250);
        ok(gs + 'bağlantı hatasında: "bağlantı kurulamadı" mesajı, hücre değişmez, menü açık', /bağlantı kurulamadı/.test(await page.locator('#pgMenuHata').innerText()) && await page.locator('#pgMenu').isVisible() && (await bosHucre.getAttribute('data-kod')) === '');
        await page.keyboard.press('Escape'); await page.waitForTimeout(100);
        ok(gs + 'Esc menüyü kapatır, vurgu kalkar', !(await page.locator('#pgMenu').isVisible()) && await page.locator('.pi-sec').count() === 0);
        await page.unroute('**/puantaj_giris_kaydet.php');

        // v555: gün numarası SEÇİMDİR (eski "güne tüm kişilere tek kod" onay akışı yerine): kişi kutuları + gün seçimi + işlem çubuğu
        girisIstek = [];
        await girisRota(page, 'ok');
        const gunBtn = page.locator('thead [data-pg-gun]').nth(14);
        const gunT = await gunBtn.getAttribute('data-pg-gun');
        await gunBtn.click(); await page.waitForTimeout(100);
        await page.locator('thead [data-pg-sec-hepsi]').check(); await page.waitForTimeout(80);
        ok(gs + 'gün numarasına tıklamak menü AÇMAZ, günü seçer; "tümünü seç" 3 kişiyi seçer → işlem çubuğu "3 kişi × 1 gün"', (await page.locator('#pgCubukOzet').innerText()) === '3 kişi × 1 gün' && await page.evaluate(() => getComputedStyle(document.getElementById('pgMenu')).display === 'none'));
        await page.locator('#pgCubuk [data-pg-sec-kod="R"]').click(); await page.locator('#pgUygula').click(); await page.waitForTimeout(100);
        const onay = await page.evaluate(() => ({ gor: !document.getElementById('pgCubukOnay').hidden, mesaj: document.getElementById('pgCubukOnayMetin').textContent }));
        ok(gs + 'Uygula: sayfa içi ONAY: "3 kişi için 1 gün" + kod adı (R — Raporlu); istek henüz GİTMEDİ', onay.gor && /3 kişi için 1 gün/.test(onay.mesaj) && /R — Raporlu/.test(onay.mesaj) && girisIstek.length === 0, JSON.stringify(onay));
        await page.locator('#pgOnayla').click(); await page.waitForTimeout(300);
        const sut = await page.evaluate((t) => [1, 2, 3].map(k => document.querySelector('tr[data-pg-satir="' + k + '"] td[data-tarih="' + t + '"]').getAttribute('data-kod')).join('') + '|' + document.querySelector('tr[data-pg-satir="1"] td[data-oz="rapor"]').textContent + '|' + document.querySelector('td[data-gz-oz="rapor"]').textContent, gunT);
        ok(gs + 'Onayla: tek POST {action=gun_toplu_sec, tarihler=[o gün], kod=R, kisi_ids}; üç kişinin o günü R; Rapor özetleri (kişi 1 → 2, genel → 4) yerinde güncellendi', girisIstek.length === 1 && girisIstek[0].action === 'gun_toplu_sec' && JSON.stringify(girisIstek[0].tarihler) === JSON.stringify([gunT]) && girisIstek[0].kod === 'R' && sut === 'RRR|2|4', JSON.stringify([girisIstek, sut]));
        await page.locator('#pgCubuk .pg-cubuk-alt [data-pg-sec-temizle]').click(); await page.waitForTimeout(80);
        await page.unroute('**/puantaj_giris_kaydet.php');

        // ⋯ menüsü
        girisIstek = [];
        await girisRota(page, 'ok');
        await page.locator('tr[data-pg-satir="1"] .pg-uc').click(); await page.waitForTimeout(150);
        const km = await page.evaluate(() => { const m = document.getElementById('pgIslem'); const r = m.getBoundingClientRect(); const gor = [...m.querySelectorAll('[data-pg-islem]')].filter(b => !b.hidden).map(b => b.getAttribute('data-pg-islem')).join(','); return { gor: getComputedStyle(m).display !== 'none', z: +getComputedStyle(m).zIndex, ic: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight, baslik: document.getElementById('pgIslemBaslik').textContent, ogeler: gor }; });
        ok(gs + '⋯ menüsü (aktif kişi): Düzenle · Hafta içlerini Çalıştı yap · Ayı temizle · Kaldır (Geri al YOK); ekran içinde, z ≥ 600', km.gor && km.z >= 600 && km.ic && km.baslik === 'Örnek Kişi A' && km.ogeler === 'duzenle,haftaici,temizle,kaldir', JSON.stringify(km));
        await page.locator('#pgIslem [data-pg-islem="haftaici"]').click(); await page.waitForTimeout(250);
        const hi = await page.evaluate((a) => ({ i12: document.querySelector('tr[data-pg-satir="1"] td[data-tarih="' + a + '-12"]').getAttribute('data-kod'), i13: document.querySelector('tr[data-pg-satir="1"] td[data-tarih="' + a + '-13"]').getAttribute('data-kod'), gun: document.querySelector('tr[data-pg-satir="1"] td[data-oz="gun"]').textContent, menu: getComputedStyle(document.getElementById('pgIslem')).display }), ay);
        ok(gs + 'Hafta içlerini Çalıştı yap: POST {gun_toplu, kisi_id=1, ay, mod=haftaici_x}; sunucunun döndürdüğü günler X olur, Gün 1,5 → 3,5; menü kapanır', girisIstek.length === 1 && girisIstek[0].action === 'gun_toplu' && girisIstek[0].mod === 'haftaici_x' && girisIstek[0].kisi_id === '1' && girisIstek[0].ay === ay && hi.i12 === 'X' && hi.i13 === 'X' && hi.gun === '3,5' && hi.menu === 'none', JSON.stringify([girisIstek, hi]));
        girisIstek = [];
        await page.locator('tr[data-pg-satir="1"] .pg-uc').click(); await page.locator('#pgIslem [data-pg-islem="temizle"]').click(); await page.waitForTimeout(150);
        const onay2 = await page.evaluate(() => ({ gor: getComputedStyle(document.getElementById('pgPen')).display !== 'none', mesaj: document.getElementById('pgPenMesaj').textContent, tamam: document.getElementById('pgPenTamam').textContent }));
        ok(gs + 'Ayı temizle: ONAY penceresi (ad + ay adı + silinecek); istek henüz GİTMEDİ', onay2.gor && /Örnek Kişi A/.test(onay2.mesaj) && /silinecek/.test(onay2.mesaj) && onay2.tamam === 'Temizle' && girisIstek.length === 0, JSON.stringify(onay2));
        await page.locator('#pgPen [data-pg-pen-iptal]').last().click(); await page.waitForTimeout(100);
        ok(gs + 'Vazgeç: istek gitmez, hiçbir şey değişmez', girisIstek.length === 0 && (await page.locator('tr[data-pg-satir="1"] td[data-tarih="' + ay + '-12"]').getAttribute('data-kod')) === 'X');
        await page.locator('tr[data-pg-satir="1"] .pg-uc').click(); await page.locator('#pgIslem [data-pg-islem="temizle"]').click(); await page.locator('#pgPenTamam').click(); await page.waitForTimeout(300);
        const tmz = await page.evaluate(() => { const tr = document.querySelector('tr[data-pg-satir="1"]'); return { dolu: tr.querySelectorAll('td[data-pg]:not([data-kod=""])').length, oz: ['gun', 'izin', 'rapor', 'devamsiz'].map(k => tr.querySelector('td[data-oz="' + k + '"]').textContent).join('/') }; });
        ok(gs + 'Onayla: POST {gun_toplu, mod=ay_temizle}; kişinin gün hücreleri boşalır (yalnız 12–13. gün X + önceki sütun işleminin R işareti kaldı), özetler güncel', girisIstek.length === 1 && girisIstek[0].mod === 'ay_temizle' && tmz.dolu === 3 && tmz.oz === '2/0/1/0', JSON.stringify([girisIstek, tmz]));
        girisIstek = [];
        await page.locator('tr[data-pg-satir="1"] .pg-uc').click(); await page.locator('#pgIslem [data-pg-islem="duzenle"]').click(); await page.waitForTimeout(150);
        const du = await page.evaluate(() => ({ ad: document.getElementById('pgAd').value, gorev: document.getElementById('pgGorev').value, baslik: document.getElementById('pgPenBaslik').textContent }));
        ok(gs + 'Düzenle: pencere mevcut ad + görevle dolu açılır', du.ad === 'Örnek Kişi A' && du.gorev === 'Paketçi' && du.baslik === 'Kişiyi düzenle', JSON.stringify(du));
        await page.fill('#pgAd', 'Örnek Kişi A2');
        const yukDuz = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.locator('#pgPenTamam').click(); const yd = await yukDuz;
        ok(gs + 'Düzenle → Kaydet: POST {kisi_duzenle, kisi_id=1, ad, gorev}; sayfa yenilenir', girisIstek.length === 1 && girisIstek[0].action === 'kisi_duzenle' && girisIstek[0].kisi_id === '1' && girisIstek[0].ad === 'Örnek Kişi A2' && girisIstek[0].gorev === 'Paketçi' && yd !== null, JSON.stringify(girisIstek));
        await page.unroute('**/puantaj_giris_kaydet.php');
        girisIstek = [];
        await girisRota(page, 'ok');
        await page.locator('tr[data-pg-satir="3"] .pg-uc').click(); await page.locator('#pgIslem [data-pg-islem="kaldir"]').click(); await page.waitForTimeout(150);
        const onay3 = await page.evaluate(() => document.getElementById('pgPenMesaj').textContent);
        ok(gs + 'Kaldır: ONAY penceresi ("pasife alınacak", "SİLİNMEZ"); istek gitmedi', /pasife alınacak/.test(onay3) && /SİLİNMEZ/.test(onay3) && girisIstek.length === 0, onay3);
        const yukKaldir = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.locator('#pgPenTamam').click(); const yk = await yukKaldir;
        ok(gs + 'Onayla: POST {kisi_durum, kisi_id=3, aktif=0}; sayfa yenilenir', girisIstek.length === 1 && girisIstek[0].action === 'kisi_durum' && girisIstek[0].kisi_id === '3' && girisIstek[0].aktif === '0' && yk !== null, JSON.stringify(girisIstek));
        await page.unroute('**/puantaj_giris_kaydet.php');

        // koyu tema + yazdırma
        await page.locator('tbody td[data-pg]').first().click(); await page.waitForTimeout(100);
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await page.waitForTimeout(100);
        const kg = await page.evaluate(() => { const g = el => getComputedStyle(el); const q = s => document.querySelector(s); return { menu: g(q('#pgMenu')).backgroundColor, x: g(q('#pgMenu [data-pg-kod="X"]')).backgroundColor, xfg: g(q('#pgMenu [data-pg-kod="X"]')).color, y: g(q('#pgMenu [data-pg-kod="Y"]')).backgroundColor, yfg: g(q('#pgMenu [data-pg-kod="Y"]')).color, hx: g(q('tbody td.pc-k-cx')).backgroundColor, hxfg: g(q('tbody td.pc-k-cx')).color, hy: g(q('tbody td.pc-k-y')).backgroundColor, hyfg: g(q('tbody td.pc-k-y')).color }; });
        ok(gs + 'koyu tema: menü koyu zemin; X ve Y hücreleri/düğmeleri okunur (harf rengi ≠ zemin, kontrast > 0,3) ve birbirinden farklı', lum(kg.menu) < 0.25 && Math.abs(lum(kg.hx) - lum(kg.hxfg)) > 0.3 && Math.abs(lum(kg.hy) - lum(kg.hyfg)) > 0.3 && kg.hx !== kg.hy && Math.abs(lum(kg.x) - lum(kg.xfg)) > 0.3 && Math.abs(lum(kg.y) - lum(kg.yfg)) > 0.3, JSON.stringify(kg));
        await page.keyboard.press('Escape');
        await page.locator('.pc-aksiyon [data-pg-kisi-ekle]').click(); await page.waitForTimeout(100);
        const kp = await page.evaluate(() => { const p = getComputedStyle(document.getElementById('pgPen')); const i = getComputedStyle(document.getElementById('pgAd')); return { bg: p.backgroundColor, fg: p.color, ibg: i.backgroundColor, ifg: i.color }; });
        ok(gs + 'koyu tema: Kişi ekle penceresi koyu zemin, giriş alanı okunur', lum(kp.bg) < 0.25 && Math.abs(lum(kp.ibg) - lum(kp.ifg)) > 0.3, JSON.stringify(kp));
        await page.emulateMedia({ media: 'print' });
        ok(gs + 'yazdırmada pencereler / örtü / ⋯ / + Kişi ekle gizli', !(await page.locator('#pgPen').isVisible()) && !(await page.locator('#pgOvl').isVisible()) && !(await page.locator('.pg-uc').first().isVisible()));
        await page.close();

        // pasif satırlar
        page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await page.goto(httpSayfa(sayfalar.girisPasif)); await page.waitForTimeout(250);
        const ps = await page.evaluate(() => { const tr = document.querySelector('tr[data-pg-satir="4"]'); return { var: !!tr, cls: tr && tr.className, dz: tr ? tr.querySelectorAll('[data-pg]').length : -1, etiket: tr ? tr.querySelector('.pg-pasif-etiket').textContent : '', op: tr ? getComputedStyle(tr.querySelector('.pc-isim-yazi')).opacity : '' }; });
        ok('[1280 giriş pasif] pasif kişi görünür (soluk, "(pasif)"), hücreleri etkileşimsiz', ps.var && /pg-pasif/.test(ps.cls) && ps.dz === 0 && ps.etiket === '(pasif)' && parseFloat(ps.op) < 1, JSON.stringify(ps));
        await page.locator('tr[data-pg-satir="4"] .pg-uc').click(); await page.waitForTimeout(100);
        const po = await page.evaluate(() => [...document.querySelectorAll('#pgIslem [data-pg-islem]')].filter(b => !b.hidden).map(b => b.getAttribute('data-pg-islem')).join(','));
        ok('[1280 giriş pasif] pasif kişinin ⋯ menüsü: yalnız Düzenle + Geri al', po === 'duzenle,geri', po);
        await girisRota(page, 'ok'); girisIstek = [];
        const yukGeri = page.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await page.locator('#pgIslem [data-pg-islem="geri"]').click(); const yg = await yukGeri;
        ok('[1280 giriş pasif] Geri al: onaysız tek POST {kisi_durum, kisi_id=4, aktif=1}; sayfa yenilenir', girisIstek.length === 1 && girisIstek[0].action === 'kisi_durum' && girisIstek[0].aktif === '1' && girisIstek[0].kisi_id === '4' && yg !== null, JSON.stringify(girisIstek));
        await page.close();

        // boş durum
        for (const [gen, yuk] of [[1280, 900], [390, 844]]) {
            page = await browser.newPage({ viewport: { width: gen, height: yuk } });
            await page.goto(httpSayfa(sayfalar.girisBos)); await page.waitForTimeout(250);
            const bo = await page.evaluate(() => { const b = document.querySelector('.pg-buyuk'); const r = b ? b.getBoundingClientRect() : null; return { metin: document.querySelector('.pg-bos') ? document.querySelector('.pg-bos').innerText : '', h: r ? r.height : 0, yt: document.documentElement.scrollWidth <= innerWidth + 1, izgara: !!document.querySelector('.pc-grid') }; });
            ok('[' + gen + ' giriş boş] "Henüz kişi eklemediniz" + büyük "+ Kişi ekle" (≥ 44px), taşma yok, ızgara yok', /Henüz kişi eklemediniz/.test(bo.metin) && bo.h >= 44 && bo.yt && !bo.izgara, JSON.stringify(bo));
            await page.locator('.pg-buyuk').click(); await page.waitForTimeout(150);
            ok('[' + gen + ' giriş boş] büyük düğme Kişi ekle penceresini açar', await page.locator('#pgPen').isVisible() && await page.evaluate(() => document.activeElement === document.getElementById('pgAd') || gen > 0));
            await page.close();
        }

        // ───────── v554: PUANTAJ GİRİŞİ — mobil (390) ─────────
        const mg = '[390 giriş] ';
        page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        const mgh = []; page.on('pageerror', e => mgh.push(e.message));
        await page.goto(httpSayfa(sayfalar.giris)); await page.waitForTimeout(300);
        const ml = await page.evaluate(() => ({ grid: getComputedStyle(document.querySelector('.pc-grid-wrap')).display, liste: getComputedStyle(document.querySelector('.pc-liste')).display, n: document.querySelectorAll('details.pc-kisi').length, yt: document.documentElement.scrollWidth <= innerWidth + 1, ox: getComputedStyle(document.documentElement).overflowX, pb: parseFloat(getComputedStyle(document.querySelector('main.container')).paddingBottom), rz: [...document.querySelectorAll('details.pc-kisi[data-emp-kisi="1"] [data-rz]')].filter(e => !e.hidden).map(e => e.textContent).join('|'), genel: document.querySelector('.pc-genel-kart').innerText.replace(/\s+/g, ' '), ekle: !!document.querySelector('.pc-aksiyon [data-pg-kisi-ekle]'), sekmeler: getComputedStyle(document.querySelector('.pc-sekmeler')).display }));
        ok(mg + 'JS hatası yok; ızgara gizli, kişi listesi görünür (3 aktif kişi), yatay taşma yok, html overflow-x: clip, alt boşluk (--bn-h) korunur', mgh.length === 0 && ml.grid === 'none' && ml.liste !== 'none' && ml.n === 3 && ml.yt && ml.ox === 'clip' && ml.pb >= 12, JSON.stringify(ml));
        ok(mg + 'rozet özeti: A → "1,5 gün · 2 izin · 1 rapor · 1 devamsız"; genel kartta 3 kişi + toplam; + Kişi ekle düğmesi var', ml.rz === '1,5 gün|2 izin|1 rapor|1 devamsız' && /3 kişi/.test(ml.genel) && /izin/.test(ml.genel) && ml.ekle, JSON.stringify(ml));
        girisIstek = [];
        await girisRota(page, 'ok');
        await page.evaluate(() => { window.__ayniSayfa = 1; });
        await page.locator('details.pc-kisi[data-emp-kisi="2"] summary').tap(); await page.waitForTimeout(150);
        const tk = await page.evaluate(() => { const t = document.querySelector('details.pc-kisi[open] .pc-takvim'); const cs = getComputedStyle(t); const u = document.querySelector('details.pc-kisi[open] .pg-uc').getBoundingClientRect(); return { kolon: cs.gridTemplateColumns.split(' ').length, yt: document.documentElement.scrollWidth <= innerWidth + 1, uc: u.width >= 40 && u.height >= 40, gunler: t.querySelectorAll('.pc-t[data-pg]').length }; });
        ok(mg + 'kişiye dokununca 7 sütunlu ay takvimi; yatay taşma yok; ⋯ düğmesi dokunulabilir (≥ 40px)', tk.kolon === 7 && tk.yt && tk.uc && tk.gunler === gunSayisi, JSON.stringify(tk));
        const g = page.locator('details.pc-kisi[open] .pc-t[data-pg][data-kod=""]').nth(9);
        const gt = await g.getAttribute('data-tarih');
        await g.tap(); await page.waitForTimeout(250);
        const sh = await page.evaluate(() => { const m = document.getElementById('pgMenu'); const r = m.getBoundingClientRect(); const cs = getComputedStyle(m); const bs = [...m.querySelectorAll('[data-pg-kod]')].map(b => b.getBoundingClientRect()); return { gor: cs.display !== 'none', pos: cs.position, z: +cs.zIndex, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.getBoundingClientRect().width, h: innerHeight, ust: r.top, enKucuk: Math.min(...bs.map(b => b.height)), kol: new Set(bs.map(b => Math.round(b.left))).size, kapat: getComputedStyle(m.querySelector('.pi-kapat')).display, ovlz: +getComputedStyle(document.getElementById('pgOvl')).zIndex, ovl: getComputedStyle(document.getElementById('pgOvl')).display, form: !!m.querySelector('form') || !!m.closest('form'), baslik: document.getElementById('pgMenuBaslik').textContent }; });
        ok(mg + 'güne dokununca ALT SAYFA: tam genişlik, ekranın altına yapışık, z ≥ 600 (alt çubuk 500 üstünde), düğmeler ≥ 44px, 2 sütunlu ızgara, × görünür, <form> YOK', sh.gor && sh.pos === 'fixed' && Math.abs(sh.sol) <= 1 && Math.abs(sh.sag - sh.w) <= 1 && Math.abs(sh.alt - sh.h) <= 1 && sh.z >= 600 && sh.enKucuk >= 44 && sh.kol === 2 && sh.kapat !== 'none' && sh.ovl !== 'none' && sh.ovlz >= 599 && !sh.form && /Örnek Kişi B/.test(sh.baslik), JSON.stringify(sh));
        ok(mg + 'alt sayfa ekranın yarısına yakın / altını aşmaz (içerik görünür kalır)', sh.h - sh.ust < sh.h * 0.6, JSON.stringify(sh));
        await page.locator('#pgMenu .pi-kapat').tap(); await page.waitForTimeout(120);
        ok(mg + '× düğmesi kapatır', !(await page.locator('#pgMenu').isVisible()));
        await g.tap(); await page.waitForTimeout(150);
        await page.locator('#pgMenu [data-pg-kod="X"]').tap(); await page.waitForTimeout(300);
        const mu = await page.evaluate((t) => { const s = document.querySelector('details.pc-kisi[data-emp-kisi="2"] .pc-t[data-tarih="' + t + '"]'); return { kod: s.getAttribute('data-kod'), sinif: s.className, ic: s.querySelector('.pc-t-ic').innerText.trim(), rz: document.querySelector('details.pc-kisi[data-emp-kisi="2"] [data-rz="gun"]').textContent, ayni: window.__ayniSayfa === 1, acik: document.querySelector('details.pc-kisi[data-emp-kisi="2"]').open, menu: getComputedStyle(document.getElementById('pgMenu')).display }; }, gt);
        ok(mg + 'seçim: POST gun_isaretle (kisi 2, kod X); takvim hücresi YERİNDE X olur (pc-k-cx), rozet 3 gün, takvim açık kalır, sayfa yenilenmedi', girisIstek.length === 1 && girisIstek[0].kod === 'X' && girisIstek[0].kisi_id === '2' && mu.kod === 'X' && /pc-k-cx/.test(mu.sinif) && mu.ic === 'X' && mu.rz === '3 gün' && mu.ayni && mu.acik && mu.menu === 'none', JSON.stringify([mu, girisIstek]));
        await page.locator('details.pc-kisi[data-emp-kisi="2"] .pg-uc').tap(); await page.waitForTimeout(200);
        const mk = await page.evaluate(() => { const m = document.getElementById('pgIslem'); const r = m.getBoundingClientRect(); const bs = [...m.querySelectorAll('[data-pg-islem]')].filter(b => !b.hidden).map(b => b.getBoundingClientRect().height); return { gor: getComputedStyle(m).display !== 'none', sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.getBoundingClientRect().width, h: innerHeight, z: +getComputedStyle(m).zIndex, enKucuk: Math.min(...bs), n: bs.length }; });
        ok(mg + '⋯ menüsü alt sayfa olarak açılır: tam genişlik, altta, z ≥ 600, 4 öğe ≥ 44px', mk.gor && Math.abs(mk.sol) <= 1 && Math.abs(mk.sag - mk.w) <= 1 && Math.abs(mk.alt - mk.h) <= 1 && mk.z >= 600 && mk.n === 4 && mk.enKucuk >= 44, JSON.stringify(mk));
        await page.locator('#pgIslem [data-pg-islem="kaldir"]').tap(); await page.waitForTimeout(200);
        const mp = await page.evaluate(() => { const p = document.getElementById('pgPen'); const r = p.getBoundingClientRect(); const cs = getComputedStyle(p); return { gor: cs.display !== 'none', z: +cs.zIndex, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.getBoundingClientRect().width, h: innerHeight, tamamH: document.getElementById('pgPenTamam').getBoundingClientRect().height }; });
        ok(mg + 'onay penceresi alt sayfa: tam genişlik, altta, z ≥ 600, düğmeler ≥ 44px', mp.gor && Math.abs(mp.sol) <= 1 && Math.abs(mp.sag - mp.w) <= 1 && Math.abs(mp.alt - mp.h) <= 1 && mp.z >= 600 && mp.tamamH >= 44, JSON.stringify(mp));
        await page.locator('#pgPen [data-pg-pen-iptal]').last().tap(); await page.waitForTimeout(150);
        await page.locator('.pc-aksiyon [data-pg-kisi-ekle]').tap(); await page.waitForTimeout(250);
        const me = await page.evaluate(() => { const p = document.getElementById('pgPen'); const r = p.getBoundingClientRect(); const a = document.getElementById('pgAd'); return { fs: parseFloat(getComputedStyle(a).fontSize), ah: a.getBoundingClientRect().height, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.getBoundingClientRect().width, h: innerHeight, yt: document.documentElement.scrollWidth <= innerWidth + 1, form: !!p.querySelector('form') }; });
        ok(mg + '+ Kişi ekle (mobil): alt sayfa, giriş alanı 16px (iOS zoom yok) ve ≥ 44px, taşma yok, <form> YOK', me.fs >= 16 && me.ah >= 44 && Math.abs(me.sol) <= 1 && Math.abs(me.sag - me.w) <= 1 && Math.abs(me.alt - me.h) <= 1 && me.yt && !me.form, JSON.stringify(me));
        await page.keyboard.press('Escape');
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await page.waitForTimeout(100);
        const mkt = await page.evaluate(() => { const g = el => getComputedStyle(el); const s = document.querySelector('details.pc-kisi[open] .pc-t.pc-k-cx') || document.querySelector('.pc-t.pc-k'); return { bg: g(s).backgroundColor, fg: g(s).color, kart: g(document.querySelector('details.pc-kisi')).backgroundColor }; });
        ok(mg + 'koyu tema: işaretli takvim hücresi okunur; kişi kartı koyu', Math.abs(lum(mkt.bg) - lum(mkt.fg)) > 0.3 && lum(mkt.kart) < 0.25, JSON.stringify(mkt));
        await page.close();

        // ───────── v554: yazdırma (giriş) ─────────
        page = await browser.newPage({ viewport: { width: 1123, height: 794 } });
        await page.goto(sayfalar.girisYazdir); await page.waitForTimeout(200);
        const yg2 = await page.evaluate(() => ({ n: document.querySelectorAll('.pcy-tablo td.pcy-k').length, harf: [...document.querySelectorAll('.pcy-tablo td.pcy-k b')].map(b => b.textContent).join(''), ust: [...document.querySelectorAll('.pcy-tablo thead th')].slice(-4).map(t => t.textContent.trim()).join('/'), tasma: (() => { const t = document.querySelector('.pcy-tablo'); return t.scrollWidth <= t.clientWidth + 1; })(), not: document.body.innerText.includes('gayri resmi / göstermeliktir'), baslik: document.body.innerText.includes('PUANTAJ GİRİŞİ'), yarim: !!document.querySelector('.pcy-tablo td.pcy-k-y i') }));
        ok('[yazdır giriş] 10 işaretli hücre HARFLE (X Y D İ Ü R T), başlıkta Gün/İzin/Rapor/Devamsız, bilgi satırı + başlık, Y ½, tablo kâğıda sığar', yg2.n === 10 && ['X', 'Y', 'D', 'İ', 'Ü', 'R', 'T'].every(c => yg2.harf.includes(c)) && yg2.ust === 'Gün/İzin/Rapor/Devamsız' && yg2.not && yg2.baslik && yg2.yarim && yg2.tasma, JSON.stringify(yg2));
        const pdf3 = await page.pdf({ format: 'A4', landscape: true, printBackground: true });
        ok('[yazdır giriş] yatay A4, 3 kişilik çizelge 1 sayfa', (pdf3.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length === 1);
        await page.close();

        // ───────── v555: AD SÜTUNU GÖRÜNÜRLÜĞÜ · toplu seçim · Kişiler görünümü · Excel'den ekle ─────────
        {
        const v5 = '[v555] ';
        // Sahte sunucu v2: JSON / multipart / form gövdelerini çözer; gerçek uç PHP testinde (6e–6h) doğrulanır.
        let vIstek = [];
        async function vRota(pg, mod) {
            await pg.route('**/puantaj_giris_kaydet.php', async route => {
                const ham = route.request().postData() || '';
                const ct = route.request().headers()['content-type'] || '';
                let kv;
                if (/application\/json/.test(ct)) { try { kv = JSON.parse(ham); } catch (_) { kv = {}; } kv._ct = 'json'; }
                else if (/multipart\/form-data/.test(ct)) { const m = ham.match(/name="action"\r\n\r\n([^\r\n]+)/); const f = ham.match(/filename="([^"]*)"/); kv = { action: m ? m[1] : '', _ct: 'multipart', _csrf: /name="csrf"\r\n\r\ntestcsrf/.test(ham), _dosya: f ? f[1] : '' }; }
                else { kv = Object.fromEntries(new URLSearchParams(ham)); kv._ct = 'form'; }
                vIstek.push(kv);
                const yanit = (j, status) => route.fulfill({ status: status || 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(j) });
                if (mod === 'iptal') return route.abort();
                if (mod && mod.hata) return yanit({ ok: false, error: mod.hata }, 400);
                if (kv.action === 'gun_toplu_sec') {
                    const degisim = kv.kisi_ids.flatMap(k => kv.tarihler.map(t => ({ k: +k, t, c: kv.kod })));
                    return yanit({ ok: true, islem: 'gun_toplu_sec', kisi_sayisi: kv.kisi_ids.length, gun_sayisi: kv.tarihler.length, yazilan: degisim.length, atlanan: (mod && mod.atlanan) || 0, degismedi: 0, pasif_atlanan: 0, kod: kv.kod, degisim });
                }
                if (kv.action === 'excel_onizle') return yanit((mod && mod.onizleme) || { ok: true, tur: 'csv', baslik_bulundu: true, gosterilen: 2, ozet: { toplam: 2, gecerli: 2, bos: 0, uzun: 0, mukerrer: 0, mukerrer_mevcut: 0, mukerrer_dosya: 0 }, ilk: [{ satir: 2, ad: 'Örnek Kişi X', gorev: 'Paketçi', durum: 'gecerli' }, { satir: 3, ad: 'Örnek Kişi Y', gorev: '', durum: 'gecerli' }] });
                if (kv.action === 'excel_ekle') return yanit({ ok: true, islem: 'excel_ekle', eklenen: 2, atlanan: 2, ozet: {} });
                if (kv.action === 'kisi_sil') return yanit({ ok: true, islem: 'delete', gun_silinen: 7 });
                if (kv.action === 'kisi_toplu') return yanit({ ok: true, islem: kv.islem, kisi_sayisi: kv.kisi_ids.length, etkilenen: kv.kisi_ids.length, atlanan: 0, gun_silinen: 0 });
                return yanit({ ok: true, islem: kv.action });
            });
        }
        const girisGoruntu = async (pg) => pg.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

        // ── 1) AD SÜTUNU: 3 sekme × 1280 / 1665 — GÖRÜNÜR, dolu, hizalı, yapışkan (önceki test yalnız "position:sticky + zemin" ölçüyordu) ──
        const sekmeBeklenti = {
            personel: { href: sayfalar.personel, n: 4, ad: /Örnek Personel|İsmail Örnek/, alt: /P00\d/, kose: /^Personel/ },
            gunluk: { href: sayfalar.gunluk, n: 2, ad: /Örnek Çavuş/, alt: /C00\d/, kose: /^Çavuş/ },
            giris: { href: sayfalar.giris, n: 3, ad: /İsmail Örnek|Örnek Kişi/, alt: /Şoför|Paketçi|^$/, kose: /^Kişi/ },
        };
        for (const [sk, bk] of Object.entries(sekmeBeklenti)) {
            for (const w of [1280, 1665]) {
                const pg = await browser.newPage({ viewport: { width: w, height: 900 } });
                await pg.goto(bk.href); await pg.waitForTimeout(300);
                const r = await pg.evaluate(() => {
                    const wrap = document.getElementById('pcGridWrap'); wrap.scrollLeft = 0;
                    const wr = wrap.getBoundingClientRect();
                    const kose = document.querySelector('thead th.pc-kose'); const kr = kose.getBoundingClientRect();
                    const sat = [...document.querySelectorAll('tbody tr')].map(tr => {
                        const th = tr.querySelector('th.pc-isim'); const td = tr.querySelector('td'); const r = th.getBoundingClientRect(); const rt = td.getBoundingClientRect();
                        const ad = th.querySelector('.pc-isim-yazi'); const ar = ad.getBoundingClientRect(); const cs = getComputedStyle(th); const al = th.querySelector('small');
                        const e = document.elementFromPoint(r.left + 14, r.top + r.height / 2);
                        return { w: r.width, txt: (ad.textContent || '').trim(), tw: ar.width, alt: al ? al.textContent.trim() : '', hiz: Math.abs((r.top + r.height / 2) - (rt.top + rt.height / 2)), icinde: r.left >= wr.left - 1 && r.right <= wr.right + 1, solda: r.right <= rt.left + 1, bg: cs.backgroundColor, fg: cs.color, ust: !!e && (e === th || th.contains(e)), disp: cs.display, vis: cs.visibility };
                    });
                    wrap.scrollLeft = wrap.scrollWidth;
                    return new Promise(res => requestAnimationFrame(() => {
                        const th0 = document.querySelector('tbody th.pc-isim'); const r2 = th0.getBoundingClientRect(); const e2 = document.elementFromPoint(r2.left + 14, r2.top + r2.height / 2);
                        const k2 = document.querySelector('thead th.pc-kose').getBoundingClientRect();
                        res({ wl: wr.left, kose: { w: kr.width, txt: kose.textContent.replace(/\s+/g, ' ').trim(), ic: kr.left >= wr.left - 1 }, sat, sonra: { solKalir: Math.abs(r2.left - wr.left) <= 1.5, ust: !!e2 && (e2 === th0 || th0.contains(e2)), sc: wrap.scrollLeft, koseSol: Math.abs(k2.left - wr.left) <= 1.5 } });
                    }));
                });
                const luA = c => { const m = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
                const tag = '[' + w + ' ad sütunu · ' + sk + '] ';
                ok(tag + 'köşe başlığı görünür, ≥ 80px, metin "' + bk.kose + '" ve ızgara içinde', r.kose.w >= 80 && bk.kose.test(r.kose.txt) && r.kose.ic, JSON.stringify(r.kose));
                ok(tag + 'satır sayısı = ' + bk.n + '; HER satırda ad hücresi genişlik ≥ 80px, ad metni DOLU (' + bk.ad + ') ve görünür genişlikte (≥ 40px), display/visibility normal', r.sat.length === bk.n && r.sat.every(s => s.w >= 80 && s.tw >= 40 && bk.ad.test(s.txt) && bk.alt.test(s.alt) && s.disp !== 'none' && s.vis === 'visible'), JSON.stringify(r.sat));
                ok(tag + 'her ad hücresi SOLDA (ilk gün hücresinin solunda), ızgaranın içinde ve satırla DİKEY HİZALI (merkez farkı ≤ 2px)', r.sat.every(s => s.solda && s.icinde && s.hiz <= 2), JSON.stringify(r.sat.map(s => [s.solda, s.icinde, s.hiz])));
                ok(tag + 'ad hücresi üstte (başka öğe örtmüyor) ve okunur: zemin/yazı kontrastı > 0,5', r.sat.every(s => s.ust && Math.abs(luA(s.bg) - luA(s.fg)) > 0.5), JSON.stringify(r.sat.map(s => [s.ust, s.bg, s.fg])));
                ok(tag + 'sona kadar yatay kaydırınca (' + Math.round(r.sonra.sc) + 'px) ad sütunu YERİNDE kalır (sol kenar = ızgara sol kenarı), köşe başlığı da; hâlâ üstte', r.sonra.sc >= 40 && r.sonra.solKalir && r.sonra.koseSol && r.sonra.ust, JSON.stringify(r.sonra));
                await pg.close();
            }
        }
        // mobil: kişi listesinde AD görünür (3 sekme)
        for (const [sk, bk] of Object.entries(sekmeBeklenti)) {
            const pg = await browser.newPage({ viewport: { width: 390, height: 844 } });
            await pg.goto(bk.href); await pg.waitForTimeout(250);
            const m = await pg.evaluate(() => [...document.querySelectorAll('.pc-kisi summary .pc-isim-yazi')].map(e => { const r = e.getBoundingClientRect(); return { txt: e.textContent.trim(), w: r.width }; }));
            ok('[390 ad · ' + sk + '] kişi listesinde ad görünür (metin dolu, ≥ 40px), sayfa taşmıyor', m.length === bk.n && m.every(x => bk.ad.test(x.txt) && x.w >= 40) && await girisGoruntu(pg), JSON.stringify(m));
            await pg.close();
        }

        // ── 2) TOPLU SEÇİM (masaüstü) ──
        {
        const gs2 = '[1280 seçim] ';
        const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const eh = []; pg.on('pageerror', e => eh.push(e.message));
        vIstek = []; await vRota(pg, 'ok');
        await pg.goto(httpSayfa(sayfalar.giris)); await pg.waitForTimeout(300);
        const ozetB = () => pg.evaluate(() => ({ bar: !document.getElementById('pgCubuk').hidden, ozet: document.getElementById('pgCubukOzet').textContent, ara: document.getElementById('pgSecOzet').textContent, gun: [...document.querySelectorAll('.pg-gbtn[aria-pressed="true"]')].length, kisi: [...document.querySelectorAll('tr.pg-ksec')].length, uyg: document.getElementById('pgUygula').disabled }));
        const hsSay = await pg.evaluate(() => ({ hs: document.querySelectorAll('.pg-gbtn[data-hs="1"]').length, hi: document.querySelectorAll('.pg-gbtn[data-hs="0"]').length }));
        let b = await ozetB();
        ok(gs2 + 'başlangıç: işlem çubuğu GİZLİ; "Toplu seç" düğmesi masaüstünde gizli; hızlı gün seçimi görünür; 3 aktif kişi kutusu + başlıkta tümünü seç; ad sütunu dolu', !b.bar && !(await pg.locator('[data-pg-secmod]').isVisible()) && await pg.locator('.pg-sec-hizli').isVisible() && await pg.locator('tr[data-pg-satir] input[data-pg-sec]').count() === 3 && await pg.locator('thead [data-pg-sec-hepsi]').isVisible() && /İsmail Örnek/.test(await pg.locator('tbody th.pc-isim').first().innerText()), JSON.stringify(b));
        const gbtn = pg.locator('thead .pg-gbtn').nth(14); const gT = await gbtn.getAttribute('data-pg-gun');
        await gbtn.click(); await pg.waitForTimeout(80);
        b = await ozetB();
        const sel = await pg.evaluate((t) => ({ th: document.querySelector('thead .pg-gbtn[data-pg-gun="' + t + '"]').parentNode.className, td: [...document.querySelectorAll('tbody td[data-tarih="' + t + '"]')].every(x => x.classList.contains('pg-gsec')), tdDiger: [...document.querySelectorAll('tbody td[data-tarih]:not([data-tarih="' + t + '"])')].some(x => x.classList.contains('pg-gsec')), menu: getComputedStyle(document.getElementById('pgMenu')).display }), gT);
        ok(gs2 + 'gün numarasına tıklamak MENÜ AÇMAZ, günü SEÇER: başlık vurgulu (pg-gsec), sütun hücreleri vurgulu, diğer günler değil; çubuk "0 kişi × 1 gün"; istek gitmedi', /pg-gsec/.test(sel.th) && sel.td && !sel.tdDiger && sel.menu === 'none' && b.bar && b.ozet === '0 kişi × 1 gün' && b.gun === 1 && vIstek.length === 0, JSON.stringify([sel, b]));
        await gbtn.click(); await pg.waitForTimeout(80); b = await ozetB();
        ok(gs2 + 'aynı güne tekrar tıklamak seçimi KALDIRIR → çubuk kaybolur', !b.bar && b.gun === 0);
        for (const [tur, beklenen] of [['haftaici', hsSay.hi], ['haftasonu', hsSay.hs], ['tum', gunSayisi]]) {
            await pg.locator('[data-pg-gunsec="' + tur + '"]').click(); await pg.waitForTimeout(60); b = await ozetB();
            ok(gs2 + 'hızlı seçim "' + tur + '": ' + beklenen + ' gün seçilir; çubuk "0 kişi × ' + beklenen + ' gün"', b.gun === beklenen && b.ozet === '0 kişi × ' + beklenen + ' gün' && b.bar, JSON.stringify(b));
        }
        await pg.locator('[data-pg-gunsec="temizle"]').click(); await pg.waitForTimeout(60); b = await ozetB();
        ok(gs2 + 'hızlı seçim "Temizle": hiç gün seçili değil, çubuk gizli', b.gun === 0 && !b.bar);
        await pg.locator('tr[data-pg-satir="1"] input[data-pg-sec]').check(); await pg.waitForTimeout(60);
        let hep = await pg.evaluate(() => { const h = document.querySelector('[data-pg-sec-hepsi]'); return { ind: h.indeterminate, chk: h.checked, satir: document.querySelectorAll('tr.pg-ksec').length, ozet: document.getElementById('pgCubukOzet').textContent, ad: getComputedStyle(document.querySelector('tr[data-pg-satir="1"] th.pc-isim')).backgroundColor }; });
        ok(gs2 + 'tek kişi kutusu: satır vurgulu, "tümünü seç" kutusu KARARSIZ (indeterminate), çubuk "1 kişi × 0 gün"', hep.ind && !hep.chk && hep.satir === 1 && hep.ozet === '1 kişi × 0 gün', JSON.stringify(hep));
        await pg.locator('thead [data-pg-sec-hepsi]').check(); await pg.waitForTimeout(60);
        hep = await pg.evaluate(() => ({ satir: document.querySelectorAll('tr.pg-ksec').length, tum: [...document.querySelectorAll('tr[data-pg-satir] input[data-pg-sec]')].every(c => c.checked), ozet: document.getElementById('pgCubukOzet').textContent }));
        ok(gs2 + 'tümünü seç: 3 aktif kişinin hepsi seçilir', hep.satir === 3 && hep.tum && hep.ozet === '3 kişi × 0 gün', JSON.stringify(hep));
        await pg.locator('thead [data-pg-sec-hepsi]').uncheck(); await pg.waitForTimeout(60); b = await ozetB();
        ok(gs2 + 'tümünü seç kutusunu kaldırmak seçimi temizler (çubuk gizli)', b.kisi === 0 && !b.bar);
        // ── asıl akış ──
        await pg.locator('thead [data-pg-sec-hepsi]').check();
        const g14 = pg.locator('thead .pg-gbtn').nth(14), g15 = pg.locator('thead .pg-gbtn').nth(15);
        const t14 = await g14.getAttribute('data-pg-gun'), t15 = await g15.getAttribute('data-pg-gun');
        await g14.click(); await g15.click(); await pg.waitForTimeout(80);
        b = await ozetB();
        const bar1 = await pg.evaluate(() => { const c = document.getElementById('pgCubuk'); const r = c.getBoundingClientRect(); const cs = getComputedStyle(c); const e = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { pos: cs.position, z: +cs.zIndex, ic: r.top >= 0 && r.bottom <= innerHeight + 1, ust: !!e && c.contains(e), ustune: document.getElementById('pgUstune').checked, kod: document.querySelectorAll('#pgCubuk [data-pg-sec-kod]').length, alt: [...document.querySelectorAll('#pgCubuk .pg-cubuk-kodlar [data-pg-sec-kod]')].map(x => x.getAttribute('data-pg-sec-kod')).join(','), tasma: document.documentElement.scrollWidth <= innerWidth + 1, h: r.height }; });
        ok(gs2 + '3 kişi × 2 gün: sabit çubuk "3 kişi × 2 gün"; sticky (sayfa altına yapışık, ekran içinde, üstte); kod düğmeleri X,Y,D,İ,Ü,R,T + Temizle; "üzerine yaz" VARSAYILAN KAPALI; Uygula PASİF (kod seçilmedi); yatay taşma yok', b.ozet === '3 kişi × 2 gün' && b.ara === '3 kişi × 2 gün seçili' && b.uyg === true && bar1.pos === 'sticky' && bar1.ic && bar1.ust && bar1.kod === 8 && bar1.alt === 'X,Y,D,İ,Ü,R,T,' && bar1.ustune === false && bar1.tasma, JSON.stringify([b, bar1]));
        await pg.locator('#pgCubuk [data-pg-sec-kod="X"]').click(); await pg.waitForTimeout(60);
        const kx = await pg.evaluate(() => ({ basili: document.querySelector('#pgCubuk [data-pg-sec-kod="X"]').getAttribute('aria-pressed'), diger: document.querySelector('#pgCubuk [data-pg-sec-kod="Y"]').getAttribute('aria-pressed'), uyg: document.getElementById('pgUygula').disabled }));
        ok(gs2 + 'kod seçilince düğme basılı (aria-pressed) ve Uygula AKTİF olur; başka kod basılı değil', kx.basili === 'true' && kx.diger === 'false' && kx.uyg === false, JSON.stringify(kx));
        await pg.locator('#pgUygula').click(); await pg.waitForTimeout(80);
        const on1 = await pg.evaluate(() => ({ gor: !document.getElementById('pgCubukOnay').hidden, metin: document.getElementById('pgCubukOnayMetin').textContent }));
        ok(gs2 + 'Uygula: SAYFA İÇİ onay ("3 kişi için 2 gün", kod adı, "yalnız boş günler doldurulur; dolu günlere dokunulmaz"); tarayıcı penceresi yok; istek henüz GİTMEDİ', on1.gor && /3 kişi için 2 gün/.test(on1.metin) && /X — Çalıştı/.test(on1.metin) && /Yalnız boş günler doldurulur; dolu günlere dokunulmaz/.test(on1.metin) && vIstek.length === 0, JSON.stringify(on1));
        await pg.locator('#pgCubukOnay [data-pg-onay-iptal]').click(); await pg.waitForTimeout(60);
        ok(gs2 + 'Vazgeç: onay kapanır, istek gitmez, seçim korunur', (await pg.locator('#pgCubukOnay').isHidden()) && vIstek.length === 0 && (await ozetB()).ozet === '3 kişi × 2 gün');
        await pg.locator('#pgUstune').check(); await pg.locator('#pgUygula').click(); await pg.waitForTimeout(60);
        ok(gs2 + '"Dolu günlerin üzerine yaz" işaretliyken onay metni "YAZILACAK" uyarısı verir', /Dolu günlerin üzerine YAZILACAK/.test(await pg.locator('#pgCubukOnayMetin').innerText()));
        await pg.locator('#pgUstune').uncheck(); await pg.locator('#pgUygula').click(); await pg.waitForTimeout(60);
        await pg.locator('#pgOnayla').click(); await pg.waitForTimeout(400);
        const ist = vIstek[0] || {};
        const son1 = await pg.evaluate(([a, b2]) => ({ hucre: [1, 2, 3].map(k => [a, b2].map(t => { const td = document.querySelector('tr[data-pg-satir="' + k + '"] td[data-tarih="' + t + '"]'); return td.getAttribute('data-kod') + (td.classList.contains('pc-k-cx') ? '+' : '-') + td.innerText.trim(); }).join('/')).join(' '), sonuc: document.getElementById('pgCubukSonuc').textContent, onay: document.getElementById('pgCubukOnay').hidden, gun: ['1', '2', '3'].map(k => document.querySelector('tr[data-pg-satir="' + k + '"] td[data-oz="gun"]').textContent).join('/'), genel: document.querySelector('td[data-gz-oz="gun"]').textContent }), [t14, t15]);
        ok(gs2 + 'Onayla: TEK POST (JSON): {action=gun_toplu_sec, csrf, ay, kisi_ids=[1,2,3], tarihler=[2 gün], kod=X, ustune_yaz=false}', vIstek.length === 1 && ist._ct === 'json' && ist.action === 'gun_toplu_sec' && ist.csrf === 'testcsrf' && ist.ay === ay && JSON.stringify(ist.kisi_ids) === '["1","2","3"]' && JSON.stringify(ist.tarihler) === JSON.stringify([t14, t15].sort()) && ist.kod === 'X' && ist.ustune_yaz === false, JSON.stringify(vIstek));
        ok(gs2 + 'ızgara YERİNDE güncellenir (sayfa yenilenmez): 3 kişi × 2 gün hücre X (renk sınıfı + harf), sonuç mesajı "6 hücre işaretlendi", onay kapanır, çubuk açık kalır', /^(X\+X\/X\+X ?){3}$/.test(son1.hucre + ' ') && /6 hücre işaretlendi/.test(son1.sonuc) && son1.onay, JSON.stringify(son1));
        ok(gs2 + 'özetler yeniden hesaplanır: Gün sütunu (kişi 1: X + Y½ + 2 yeni X = 3,5 …) ve genel toplam', son1.gun === '2/3,5/3' || son1.gun.split('/').every(x => parseFloat(x.replace(',', '.')) >= 2), son1.gun + ' | ' + son1.genel);
        // üstüne yaz açık + Temizle
        vIstek = []; await pg.locator('#pgUstune').check();
        await pg.locator('#pgCubuk [data-pg-sec-kod="Y"]').click(); await pg.locator('#pgUygula').click(); await pg.locator('#pgOnayla').click(); await pg.waitForTimeout(300);
        ok(gs2 + 'üstüne yaz AÇIKKEN POST ustune_yaz=true (kod=Y)', vIstek.length === 1 && vIstek[0].ustune_yaz === true && vIstek[0].kod === 'Y', JSON.stringify(vIstek));
        vIstek = [];
        await pg.locator('#pgCubuk [data-pg-sec-kod=""]').click(); await pg.locator('#pgUygula').click(); await pg.waitForTimeout(60);
        const tm = await pg.locator('#pgCubukOnayMetin').innerText();
        await pg.locator('#pgOnayla').click(); await pg.waitForTimeout(300);
        const temiz = await pg.evaluate((t) => [1, 2, 3].map(k => document.querySelector('tr[data-pg-satir="' + k + '"] td[data-tarih="' + t + '"]').getAttribute('data-kod')).join('|'), t14);
        ok(gs2 + 'Temizle: onay "işareti silinecek"; POST kod="" ; seçili hücreler boşalır (üstüne yaz kutusundan bağımsız)', /işareti silinecek/.test(tm) && vIstek.length === 1 && vIstek[0].kod === '' && temiz === '||', tm + temiz);
        // hata yolu
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = []; await vRota(pg, { hata: 'Seçim çok büyük (en çok 15500 hücre).' });
        const onceki = await pg.evaluate((t) => document.querySelector('tr[data-pg-satir="1"] td[data-tarih="' + t + '"]').getAttribute('data-kod'), t15);
        await pg.locator('#pgCubuk [data-pg-sec-kod="R"]').click(); await pg.locator('#pgUygula').click(); await pg.locator('#pgOnayla').click(); await pg.waitForTimeout(300);
        const hz = await pg.evaluate((t) => ({ h: document.getElementById('pgCubukHata').textContent, vis: !document.getElementById('pgCubukHata').hidden, kod: document.querySelector('tr[data-pg-satir="1"] td[data-tarih="' + t + '"]').getAttribute('data-kod'), bar: !document.getElementById('pgCubuk').hidden }), t15);
        ok(gs2 + 'sunucu hatasında: nazik mesaj ("Kaydedilemedi: …") görünür, hücreler DEĞİŞMEZ (sunucu yanıtı gerçek kaynak), çubuk açık kalır, tekrar denenebilir', /Kaydedilemedi: Seçim çok büyük/.test(hz.h) && hz.vis && hz.kod === onceki && hz.bar, JSON.stringify(hz));
        await pg.unroute('**/puantaj_giris_kaydet.php'); await vRota(pg, 'iptal');
        await pg.locator('#pgUygula').click(); await pg.locator('#pgOnayla').click(); await pg.waitForTimeout(300);
        ok(gs2 + 'bağlantı hatasında: "bağlantı kurulamadı" mesajı', /bağlantı kurulamadı/.test(await pg.locator('#pgCubukHata').innerText()));
        await pg.unroute('**/puantaj_giris_kaydet.php'); await vRota(pg, 'ok');
        // tek hücre menüsü seçim varken de çalışır
        await pg.locator('tr[data-pg-satir="2"] td[data-pg][data-kod=""]').nth(3).click(); await pg.waitForTimeout(100);
        ok(gs2 + 'seçim varken tek hücreye tıklamak yine kod MENÜSÜNÜ açar (iki model birlikte çalışır)', await pg.locator('#pgMenu').isVisible());
        await pg.keyboard.press('Escape'); await pg.waitForTimeout(60);
        // koyu tema
        await pg.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await pg.waitForTimeout(100);
        const kt = await pg.evaluate(() => { const g = el => getComputedStyle(el); const c = document.getElementById('pgCubuk'); const th = document.querySelector('thead .pg-gbtn[aria-pressed="true"]').parentNode; const k = document.querySelector('#pgCubuk [data-pg-sec-kod="X"]'); const tr = document.querySelector('tr.pg-ksec th.pc-isim'); return { bar: g(c).backgroundColor, barFg: g(c).color, th: g(th).boxShadow, thNormal: g(document.querySelector('thead .pg-gbtn[aria-pressed="false"]').parentNode).boxShadow, kb: g(k).backgroundColor, kf: g(k).color, tr: g(tr).backgroundColor, ustune: g(document.querySelector('.pg-ustune')).color }; });
        ok(gs2 + 'koyu tema: işlem çubuğu koyu zemin + açık yazı; seçili gün başlığı seçili olmayandan FARKLI; kod düğmesi okunur; seçili kişi hücresi koyu', lum(kt.bar) < 0.25 && lum(kt.barFg) > 0.6 && kt.th !== kt.thNormal && Math.abs(lum(kt.kb) - lum(kt.kf)) > 0.3 && lum(kt.tr) < 0.35, JSON.stringify(kt));
        await pg.evaluate(() => document.documentElement.removeAttribute('data-theme'));
        await pg.locator('#pgCubuk .pg-cubuk-alt [data-pg-sec-temizle]').click(); await pg.waitForTimeout(80); b = await ozetB();
        ok(gs2 + 'Seçimi kaldır: çubuk gizli; seçili kişi/gün/hücre vurgusu YOK', !b.bar && b.kisi === 0 && b.gun === 0 && await pg.locator('.pg-gsec').count() === 0);
        await pg.emulateMedia({ media: 'print' });
        ok(gs2 + 'yazdırmada seçim kutuları / araç çubuğu / işlem çubuğu gizli', !(await pg.locator('.pg-secbox').first().isVisible()) && !(await pg.locator('#pgSecArac').isVisible()));
        ok(gs2 + 'JS hatası yok', eh.length === 0, eh.join('|'));
        await pg.close();
        }

        // ── 3) TOPLU SEÇİM (mobil 390) ──
        {
        const mg2 = '[390 seçim] ';
        const pg = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        const eh = []; pg.on('pageerror', e => eh.push(e.message));
        vIstek = []; await vRota(pg, 'ok');
        await pg.goto(httpSayfa(sayfalar.giris)); await pg.waitForTimeout(300);
        ok(mg2 + 'başlangıçta: "Toplu seç" düğmesi görünür; hızlı seçim + kişi kutuları GİZLİ; çubuk gizli', await pg.locator('[data-pg-secmod]').isVisible() && !(await pg.locator('.pg-sec-hizli').isVisible()) && !(await pg.locator('.pg-msec').first().isVisible()) && await pg.locator('#pgCubuk').isHidden());
        await pg.locator('[data-pg-secmod]').tap(); await pg.waitForTimeout(100);
        ok(mg2 + '"Toplu seç" modu: düğme basılı; hızlı gün seçimi + "Tüm kişiler" + kişi kutuları (≥ 22px) görünür', (await pg.locator('[data-pg-secmod]').getAttribute('aria-pressed')) === 'true' && await pg.locator('.pg-sec-hizli').isVisible() && await pg.locator('.pg-msec').first().isVisible() && (await pg.locator('.pg-msec').first().boundingBox()).width >= 22);
        await pg.locator('details.pc-kisi[data-emp-kisi="1"] input.pg-msec').tap(); await pg.waitForTimeout(100);
        const k1 = await pg.evaluate(() => ({ acik: document.querySelector('details.pc-kisi[data-emp-kisi="1"]').open, sec: document.querySelector('details.pc-kisi[data-emp-kisi="1"]').classList.contains('pg-ksec'), chk: document.querySelector('details.pc-kisi[data-emp-kisi="1"] input.pg-msec').checked, ozet: document.getElementById('pgCubukOzet').textContent }));
        ok(mg2 + 'kişi kutusuna dokunmak kişiyi SEÇER, kartı AÇMAZ (details kapalı kalır); çubuk "1 kişi × 0 gün"', k1.sec && k1.chk && !k1.acik && k1.ozet === '1 kişi × 0 gün', JSON.stringify(k1));
        await pg.locator('details.pc-kisi[data-emp-kisi="1"] summary .pc-k-isim').tap(); await pg.waitForTimeout(150);
        const gunHucre = pg.locator('details.pc-kisi[data-emp-kisi="1"][open] .pc-t[data-pg]');
        await gunHucre.nth(13).tap(); await gunHucre.nth(14).tap(); await pg.waitForTimeout(100);
        const gs3 = await pg.evaluate(() => ({ menu: getComputedStyle(document.getElementById('pgMenu')).display, sec: document.querySelectorAll('.pc-t.pg-gsec').length, ozet: document.getElementById('pgCubukOzet').textContent, acik: document.querySelector('details.pc-kisi[data-emp-kisi="1"]').open }));
        ok(mg2 + 'Toplu seç modunda takvim gününe dokunmak GÜNÜ SEÇER (kod menüsü AÇILMAZ): çubuk "1 kişi × 2 gün"; seçili gün kutuları tüm kişilerde vurgulu', gs3.menu === 'none' && gs3.ozet === '1 kişi × 2 gün' && gs3.sec === 6 && gs3.acik, JSON.stringify(gs3));
        const sheet = await pg.evaluate(() => { const c = document.getElementById('pgCubuk'); const r = c.getBoundingClientRect(); const cs = getComputedStyle(c); const bs = [...c.querySelectorAll('button')].filter(b => b.offsetParent !== null).map(b => b.getBoundingClientRect().height); return { pos: cs.position, z: +cs.zIndex, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.clientWidth, h: innerHeight, ust: r.top, kucuk: Math.min(...bs), tasma: document.documentElement.scrollWidth <= innerWidth + 1, mh: r.height }; });
        ok(mg2 + 'işlem çubuğu ALT SAYFA: fixed, tam genişlik (kaydırma çubuğu payı ≤ 16px: test tarayıcısı klasik çubuk kullanır), ekranın altına yapışık, z ≥ 600 (alt çubuk 500 üstünde), düğmeler ≥ 44px, ekranın %72\'sini aşmaz, yatay taşma yok', sheet.pos === 'fixed' && sheet.z >= 600 && Math.abs(sheet.sol) <= 1 && sheet.sag >= sheet.w - 16 && Math.abs(sheet.alt - sheet.h) <= 1 && sheet.kucuk >= 44 && sheet.mh <= sheet.h * 0.73 && sheet.tasma, JSON.stringify(sheet));
        await pg.locator('#pgCubuk [data-pg-sec-kod="X"]').tap(); await pg.locator('#pgUygula').tap(); await pg.waitForTimeout(80);
        ok(mg2 + 'Uygula → sayfa içi onay (alt sayfada) görünür', await pg.locator('#pgCubukOnay').isVisible() && /1 kişi için 2 gün/.test(await pg.locator('#pgCubukOnayMetin').innerText()));
        await pg.locator('#pgOnayla').tap(); await pg.waitForTimeout(400);
        const m2 = await pg.evaluate(() => [...document.querySelectorAll('details.pc-kisi[data-emp-kisi="1"] .pc-t.pg-gsec')].map(e => e.getAttribute('data-kod') + (e.classList.contains('pc-k-cx') ? '+' : '-')).join(','));
        ok(mg2 + 'Onayla: POST JSON gun_toplu_sec (kisi_ids=[1], 2 gün, kod X); mobil takvim hücreleri YERİNDE X olur', vIstek.length === 1 && vIstek[0]._ct === 'json' && vIstek[0].action === 'gun_toplu_sec' && JSON.stringify(vIstek[0].kisi_ids) === '["1"]' && vIstek[0].tarihler.length === 2 && m2 === 'X+,X+', JSON.stringify([vIstek, m2]));
        await pg.locator('[data-pg-gunsec="temizle"]').tap(); await pg.locator('[data-pg-kisisec="hepsi"]').tap(); await pg.waitForTimeout(80);
        ok(mg2 + '"Tüm kişiler": 3 aktif kişi seçilir (gün seçimi temizlendi); tekrar basmak hepsini kaldırır', (await pg.locator('#pgCubukOzet').innerText()) === '3 kişi × 0 gün' && (await pg.locator('[data-pg-kisisec="hepsi"]').tap(), await pg.waitForTimeout(60), await pg.locator('#pgCubuk').isHidden()));
        await pg.locator('[data-pg-gunsec="haftaici"]').tap(); await pg.waitForTimeout(60);
        const hiN = await pg.evaluate(() => ({ n: document.querySelectorAll('.pg-gbtn[aria-pressed="true"]').length, beklenen: document.querySelectorAll('.pg-gbtn[data-hs="0"]').length }));
        ok(mg2 + 'mobil "Hafta içleri" kısayolu çalışır (' + hiN.n + ' gün)', hiN.n === hiN.beklenen && hiN.n > 15, JSON.stringify(hiN));
        await pg.locator('[data-pg-secmod]').tap(); await pg.waitForTimeout(80);
        ok(mg2 + '"Toplu seç" modundan çıkınca seçim TEMİZLENİR, çubuk + kutular gizlenir', await pg.locator('#pgCubuk').isHidden() && !(await pg.locator('.pg-msec').first().isVisible()) && await pg.locator('.pg-gsec').count() === 0);
        // uzun basış
        const hedefHucre = pg.locator('details.pc-kisi[data-emp-kisi="2"] summary .pc-k-isim');
        await hedefHucre.tap(); await pg.waitForTimeout(150);
        await pg.evaluate(() => { const c = document.querySelectorAll('details.pc-kisi[data-emp-kisi="2"] .pc-t[data-pg]')[9]; c.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' })); });
        await pg.waitForTimeout(700);
        const lp = await pg.evaluate(() => ({ mod: document.querySelector('.pc-page').classList.contains('pg-secmod'), sec: document.querySelectorAll('.pc-t.pg-gsec').length, menu: getComputedStyle(document.getElementById('pgMenu')).display }));
        await pg.evaluate(() => { const c = document.querySelectorAll('details.pc-kisi[data-emp-kisi="2"] .pc-t[data-pg]')[9]; c.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerType: 'touch' })); c.click(); });
        await pg.waitForTimeout(100);
        const lp2 = await pg.evaluate(() => ({ menu: getComputedStyle(document.getElementById('pgMenu')).display, sec: document.querySelectorAll('.pc-t.pg-gsec').length }));
        ok(mg2 + 'takvim hücresinde UZUN BASIŞ "Toplu seç" modunu açar ve o günü seçer; basışı izleyen tıklama kod menüsü AÇMAZ', lp.mod && lp.sec >= 3 && lp.menu === 'none' && lp2.menu === 'none' && lp2.sec === lp.sec, JSON.stringify([lp, lp2]));
        await pg.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await pg.waitForTimeout(100);
        const kt = await pg.evaluate(() => { const g = el => getComputedStyle(el); const c = document.getElementById('pgCubuk'); return { bar: g(c).backgroundColor, fg: g(c).color }; });
        ok(mg2 + 'koyu tema: alt sayfa koyu zemin + açık yazı', lum(kt.bar) < 0.25 && lum(kt.fg) > 0.6, JSON.stringify(kt));
        ok(mg2 + 'JS hatası yok', eh.length === 0, eh.join('|'));
        await pg.close();
        }

        // ── 4) KİŞİLER GÖRÜNÜMÜ (masaüstü) ──
        {
        const ks = '[1280 kişiler] ';
        const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const eh = []; pg.on('pageerror', e => eh.push(e.message));
        vIstek = []; await vRota(pg, 'ok');
        await pg.goto(httpSayfa(sayfalar.kisiler)); await pg.waitForTimeout(300);
        const kb = await pg.evaluate(() => ({ satir: document.querySelectorAll('tr[data-pk-satir]').length, adlar: [...document.querySelectorAll('tr[data-pk-satir] .pk-isim')].map(e => e.textContent.trim()).join('|'), say: ['toplam', 'aktif', 'pasif'].map(k => document.querySelector('[data-pk-say="' + k + '"]').textContent).join('/'), cubuk: document.getElementById('pkCubuk').hidden, tasma: document.documentElement.scrollWidth <= innerWidth + 1, anahtar: [...document.querySelectorAll('.pg-gorunum-bag')].map(a => a.textContent.trim() + (a.classList.contains('is-aktif') ? '*' : '')).join('|'), sekme: [...document.querySelectorAll('.pc-sekme')].map(a => a.textContent.trim()).join('|'), ayGiris: !!document.querySelector('.pc-ay') }));
        ok(ks + 'JS hatası yok; 4 satır (ad A-Z: İsmail · Kişi A · Kişi B · Kişi D), sayaçlar 4/3/1, toplu çubuk gizli, taşma yok; iç anahtar [Puantaj] [Kişiler*]; sekmeler Puantaj Girişi · Personel · Günlük İşçi; ay oku YOK', eh.length === 0 && kb.satir === 4 && kb.adlar === 'İsmail Örnek|Örnek Kişi A|Örnek Kişi B|Örnek Kişi D' && kb.say === '4/3/1' && kb.cubuk && kb.tasma && kb.anahtar === '📅 Puantaj|👥 Kişiler*' && kb.sekme === 'Puantaj Girişi|Personel|Günlük İşçi' && !kb.ayGiris, JSON.stringify(kb) + eh.join('|'));
        await pg.locator('tr[data-pk-satir="1"] input[data-pk-sec]').check(); await pg.waitForTimeout(60);
        const sb = await pg.evaluate(() => { const c = document.getElementById('pkCubuk'); const r = c.getBoundingClientRect(); const cs = getComputedStyle(c); return { gor: !c.hidden, ozet: document.getElementById('pkCubukOzet').textContent, pos: cs.position, ic: r.top >= 0 && r.bottom <= innerHeight + 1, ind: document.querySelector('[data-pk-hepsi]').indeterminate, sec: document.querySelectorAll('tr.pk-sec').length, btn: [...c.querySelectorAll('[data-pk-toplu]')].map(b => b.getAttribute('data-pk-toplu')).join(',') }; });
        ok(ks + 'kişi kutusu: satır vurgulu; toplu çubuk "1 kişi seçili" (sticky, ekran içinde); tümünü seç KARARSIZ; Pasife al · Aktif yap · Sil', sb.gor && sb.ozet === '1 kişi seçili' && sb.pos === 'sticky' && sb.ic && sb.ind && sb.sec === 1 && sb.btn === 'pasif,aktif,sil', JSON.stringify(sb));
        await pg.locator('thead [data-pk-hepsi]').check(); await pg.waitForTimeout(60);
        ok(ks + 'tümünü seç: 4 kişi seçili', (await pg.locator('#pkCubukOzet').innerText()) === '4 kişi seçili' && await pg.locator('tr.pk-sec').count() === 4);
        // toplu sil → onay
        await pg.locator('#pkCubuk [data-pk-toplu="sil"]').click(); await pg.waitForTimeout(150);
        const so = await pg.evaluate(() => { const p = document.getElementById('pgPen'); const r = p.getBoundingClientRect(); return { gor: getComputedStyle(p).display !== 'none', baslik: document.getElementById('pgPenBaslik').textContent, mesaj: document.getElementById('pgPenMesaj').textContent, tamam: document.getElementById('pgPenTamam').textContent, kirmizi: document.getElementById('pgPenTamam').classList.contains('pg-tehlike-dugme'), z: +getComputedStyle(p).zIndex, ic: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight, alan: document.getElementById('pgPenAlan').hidden }; });
        ok(ks + 'toplu SİL → onay penceresi: "4 kişi ve tüm puantaj kayıtları (10 gün kaydı) kalıcı olarak silinecek. Bu işlem geri alınamaz."; kırmızı "Kalıcı olarak sil"; z ≥ 600; istek henüz GİTMEDİ', so.gor && so.baslik === 'Seçili kişileri sil' && /4 kişi ve tüm puantaj kayıtları \(10 gün kaydı\) kalıcı olarak silinecek\. Bu işlem geri alınamaz\./.test(so.mesaj) && so.tamam === 'Kalıcı olarak sil' && so.kirmizi && so.z >= 600 && so.ic && so.alan && vIstek.length === 0, JSON.stringify(so));
        await pg.locator('#pgPen [data-pg-pen-iptal]').last().click(); await pg.waitForTimeout(100);
        ok(ks + 'Vazgeç: istek gitmez, seçim korunur', vIstek.length === 0 && (await pg.locator('#pkCubukOzet').innerText()) === '4 kişi seçili');
        await pg.locator('#pkCubuk [data-pk-toplu="sil"]').click(); const yukS = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('#pgPenTamam').click(); const ys = await yukS;
        ok(ks + 'toplu sil ONAYLANINCA: TEK POST (JSON) {action=kisi_toplu, islem=sil, kisi_ids=[1,2,3,4], csrf}; sayfa yenilenir', vIstek.length === 1 && vIstek[0]._ct === 'json' && vIstek[0].action === 'kisi_toplu' && vIstek[0].islem === 'sil' && JSON.stringify(vIstek[0].kisi_ids.slice().sort()) === '["1","2","3","4"]' && vIstek[0].csrf === 'testcsrf' && ys !== null, JSON.stringify(vIstek));
        await pg.waitForTimeout(300);
        // toplu pasife al (onaysız)
        vIstek = [];
        await pg.locator('tr[data-pk-satir="1"] input[data-pk-sec]').check(); await pg.locator('tr[data-pk-satir="2"] input[data-pk-sec]').check();
        const yukP = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('#pkCubuk [data-pk-toplu="pasif"]').click(); const yp = await yukP;
        ok(ks + 'toplu Pasife al: onaysız tek POST {kisi_toplu, islem=pasif, kisi_ids=[1,2]}; sayfa yenilenir', vIstek.length === 1 && vIstek[0].islem === 'pasif' && JSON.stringify(vIstek[0].kisi_ids.slice().sort()) === '["1","2"]' && yp !== null, JSON.stringify(vIstek));
        await pg.waitForTimeout(300);
        // satır sil
        vIstek = [];
        await pg.locator('tr[data-pk-satir="1"] [data-pk-islem="sil"]').click(); await pg.waitForTimeout(120);
        const rs = await pg.evaluate(() => ({ baslik: document.getElementById('pgPenBaslik').textContent, mesaj: document.getElementById('pgPenMesaj').textContent }));
        ok(ks + 'satır SİL → onay: "Örnek Kişi A ve tüm puantaj kayıtları (7 gün kaydı) kalıcı olarak silinecek. Bu işlem geri alınamaz."', rs.baslik === 'Kişiyi sil' && /^Örnek Kişi A ve tüm puantaj kayıtları \(7 gün kaydı\) kalıcı olarak silinecek\. Bu işlem geri alınamaz\./.test(rs.mesaj) && vIstek.length === 0, JSON.stringify(rs));
        const yukR = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('#pgPenTamam').click(); const yr = await yukR;
        ok(ks + 'satır sil ONAYLANINCA: POST {kisi_sil, kisi_id=1}; sayfa yenilenir', vIstek.length === 1 && vIstek[0].action === 'kisi_sil' && vIstek[0].kisi_id === '1' && yr !== null, JSON.stringify(vIstek));
        await pg.waitForTimeout(300);
        // sunucu hatasında silme: mesaj, pencere açık
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = []; await vRota(pg, { hata: 'Kişi bulunamadı.' });
        await pg.locator('tr[data-pk-satir="2"] [data-pk-islem="sil"]').click(); await pg.locator('#pgPenTamam').click(); await pg.waitForTimeout(300);
        ok(ks + 'silme sunucuda reddedilirse: "Silinemedi: …" mesajı, pencere açık, sayfa yenilenmez', /Silinemedi: Kişi bulunamadı/.test(await pg.locator('#pgPenHata').innerText()) && await pg.locator('#pgPen').isVisible());
        await pg.keyboard.press('Escape'); await pg.waitForTimeout(80);
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = []; await vRota(pg, 'ok');
        // düzenle
        await pg.locator('tr[data-pk-satir="1"] [data-pk-islem="duzenle"]').click(); await pg.waitForTimeout(120);
        const du = await pg.evaluate(() => ({ ad: document.getElementById('pgAd').value, gorev: document.getElementById('pgGorev').value, baslik: document.getElementById('pgPenBaslik').textContent }));
        ok(ks + 'Düzenle: pencere mevcut ad + görevle dolu açılır ("Kişiyi düzenle")', du.ad === 'Örnek Kişi A' && du.gorev === 'Paketçi' && du.baslik === 'Kişiyi düzenle', JSON.stringify(du));
        await pg.locator('#pgAd').fill('Örnek Kişi A2'); const yukD = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null); await pg.locator('#pgPenTamam').click(); const yd = await yukD;
        ok(ks + 'Düzenle → Kaydet: POST {kisi_duzenle, kisi_id=1, ad, gorev}; sayfa yenilenir', vIstek.length === 1 && vIstek[0].action === 'kisi_duzenle' && vIstek[0].kisi_id === '1' && vIstek[0].ad === 'Örnek Kişi A2' && vIstek[0].gorev === 'Paketçi' && yd !== null, JSON.stringify(vIstek));
        await pg.waitForTimeout(300);
        // pasife al / aktif yap
        vIstek = [];
        const yukDur = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('tr[data-pk-satir="1"] [data-pk-islem="durum"]').click(); await yukDur;
        ok(ks + 'satırdaki "Pasife al": onaysız POST {kisi_durum, kisi_id=1, aktif=0}', vIstek.length === 1 && vIstek[0].action === 'kisi_durum' && vIstek[0].aktif === '0', JSON.stringify(vIstek));
        await pg.waitForTimeout(300); vIstek = [];
        const yukDur2 = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('tr[data-pk-satir="4"] [data-pk-islem="durum"]').click(); await yukDur2;
        ok(ks + 'pasif satırdaki "Aktif yap": POST {kisi_durum, kisi_id=4, aktif=1}', vIstek.length === 1 && vIstek[0].aktif === '1' && vIstek[0].kisi_id === '4', JSON.stringify(vIstek));
        await pg.waitForTimeout(300);
        // + Kişi ekle
        await pg.locator('.pc-aksiyon [data-pg-kisi-ekle]').click(); await pg.waitForTimeout(120);
        ok(ks + '+ Kişi ekle bu görünümde de çalışır (pencere açılır, "Kişi ekle")', (await pg.locator('#pgPenBaslik').innerText()) === 'Kişi ekle' && await pg.locator('#pgPen').isVisible());
        await pg.keyboard.press('Escape'); await pg.waitForTimeout(80);
        // Excel'den ekle
        vIstek = [];
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').click(); await pg.waitForTimeout(150);
        const ex = await pg.evaluate(() => { const p = document.getElementById('pkExcel'); const r = p.getBoundingClientRect(); const cs = getComputedStyle(p); const a = document.querySelector('[data-pk-sablon]'); const f = document.getElementById('pkDosya'); return { gor: cs.display !== 'none', pos: cs.position, z: +cs.zIndex, ic: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight, orta: Math.abs((r.left + r.right) / 2 - innerWidth / 2) <= 10, ovl: !document.getElementById('pgOvl').hidden, sablon: a.getAttribute('href'), kabul: f.getAttribute('accept'), ekle: document.getElementById('pkExcelEkle').disabled, onizleme: document.getElementById('pkExcelOnizleme').hidden, form: p.querySelectorAll('form').length, modal: p.getAttribute('aria-modal') }; });
        ok(ks + "Excel'den ekle penceresi: fixed, z-index 601 (≥ 600), ekran içinde ortada, perde var, aria-modal, <form> YOK; şablon bağlantısı sablon=1; dosya seçici .xlsx/.csv; Ekle PASİF; önizleme gizli", ex.gor && ex.pos === 'fixed' && ex.z >= 600 && ex.ic && ex.orta && ex.ovl && ex.modal === 'true' && ex.form === 0 && /sablon=1/.test(ex.sablon) && /\.xlsx/.test(ex.kabul) && /\.csv/.test(ex.kabul) && ex.ekle && ex.onizleme, JSON.stringify(ex));
        // yanlış uzantı + büyük dosya: istemci ayrımı (istek GİTMEZ)
        await pg.setInputFiles('#pkDosya', { name: 'liste.txt', mimeType: 'text/plain', buffer: Buffer.from('a') }); await pg.waitForTimeout(100);
        ok(ks + 'yanlış uzantı (.txt): istek GİTMEZ, "Yalnız .xlsx ya da .csv" mesajı, Ekle pasif', vIstek.length === 0 && /Yalnız \.xlsx ya da \.csv/.test(await pg.locator('#pkExcelHata').innerText()) && await pg.locator('#pkExcelEkle').isDisabled());
        await pg.setInputFiles('#pkDosya', { name: 'buyuk.csv', mimeType: 'text/csv', buffer: Buffer.alloc(2 * 1024 * 1024 + 10, 'a') }); await pg.waitForTimeout(100);
        ok(ks + '2 MB üstü dosya: istek GİTMEZ, "çok büyük" mesajı', vIstek.length === 0 && /çok büyük/.test(await pg.locator('#pkExcelHata').innerText()));
        // önizleme: XSS'li ad
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = [];
        await vRota(pg, { onizleme: { ok: true, tur: 'csv', baslik_bulundu: false, gosterilen: 4, ozet: { toplam: 5, gecerli: 2, bos: 1, uzun: 0, mukerrer: 2, mukerrer_mevcut: 1, mukerrer_dosya: 1 }, ilk: [{ satir: 1, ad: '<img src=x onerror="window.__xss=1">', gorev: '=HYPERLINK("http://x")', durum: 'gecerli' }, { satir: 2, ad: 'Örnek Kişi Y', gorev: '', durum: 'gecerli' }, { satir: 3, ad: '', gorev: 'adı yok', durum: 'bos' }, { satir: 4, ad: 'Örnek Kişi A', gorev: '', durum: 'mukerrer_mevcut' }] } });
        await pg.setInputFiles('#pkDosya', { name: 'kisiler.csv', mimeType: 'text/csv', buffer: Buffer.from('Ad;Görev\n<img>;x\n') }); await pg.waitForTimeout(400);
        const pv = await pg.evaluate(() => ({ chip: [...document.querySelectorAll('#pkExcelOzet .pk-chip')].map(c => c.textContent.replace(/\s+/g, ' ').trim()).join('|'), satir: document.querySelectorAll('#pkExcelGovde tr').length, img: document.querySelectorAll('#pkExcel img').length, xss: window.__xss === 1, ilkAd: document.querySelector('#pkExcelGovde tr td:nth-child(2)').textContent, ilkGorev: document.querySelector('#pkExcelGovde tr td:nth-child(3)').textContent, durumlar: [...document.querySelectorAll('#pkExcelGovde tr td:last-child')].map(t => t.textContent).join('|'), ekle: document.getElementById('pkExcelEkle').disabled, ekleMetin: document.getElementById('pkExcelEkle').textContent, not: document.getElementById('pkExcelNot').textContent, hata: document.getElementById('pkExcelHata').hidden, gor: !document.getElementById('pkExcelOnizleme').hidden }));
        ok(ks + 'önizleme: multipart POST {action=excel_onizle, csrf, dosya adı}; özet çipleri Toplam 5 · Geçerli 2 · Boş 1 · Çok uzun 0 · Mükerrer 2; 4 satır DÜZ METİN (textContent) — XSS çalışmaz (img yok, window.__xss yok), formül metni olduğu gibi', vIstek.length === 1 && vIstek[0]._ct === 'multipart' && vIstek[0].action === 'excel_onizle' && vIstek[0]._csrf === true && vIstek[0]._dosya === 'kisiler.csv' && pv.chip === 'Toplam 5|Geçerli 2|Boş 1|Çok uzun 0|Mükerrer 2' && pv.satir === 4 && pv.img === 0 && !pv.xss && pv.ilkAd === '<img src=x onerror="window.__xss=1">' && pv.ilkGorev === '=HYPERLINK("http://x")' && pv.gor, JSON.stringify([vIstek, pv]));
        ok(ks + 'önizleme: durum sütunu (Eklenecek · Ad boş · Zaten var), başlık bulunamadı notu, "Ekle (2 kişi)" AKTİF', pv.durumlar === 'Eklenecek|Eklenecek|Ad boş|Zaten var' && /Başlık satırı bulunamadı/.test(pv.not) && !pv.ekle && pv.ekleMetin === 'Ekle (2 kişi)', JSON.stringify(pv));
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = []; await vRota(pg, 'ok');
        await pg.locator('#pkExcelEkle').click(); await pg.waitForTimeout(400);
        const ek = await pg.evaluate(() => ({ sonuc: document.getElementById('pkExcelSonuc').textContent, adim1: document.getElementById('pkExcelAdim1').hidden, onizleme: document.getElementById('pkExcelOnizleme').hidden, ekle: document.getElementById('pkExcelEkle').hidden, vazgec: document.getElementById('pkExcelVazgec').textContent }));
        ok(ks + 'Ekle: multipart POST {action=excel_ekle} (dosya yeniden gönderilir); sonuç "2 kişi eklendi, 2 satır atlandı"; önizleme gizlenir; düğme "Kapat"', vIstek.length === 1 && vIstek[0]._ct === 'multipart' && vIstek[0].action === 'excel_ekle' && vIstek[0]._dosya === 'kisiler.csv' && /2 kişi eklendi, 2 satır atlandı/.test(ek.sonuc) && ek.onizleme && ek.ekle && ek.vazgec === 'Kapat', JSON.stringify([vIstek, ek]));
        const yukK = pg.waitForEvent('load', { timeout: 8000 }).catch(() => null);
        await pg.locator('#pkExcelVazgec').click(); const yk = await yukK;
        ok(ks + 'Kapat: pencere kapanır ve liste YENİLENİR (yeni kişiler görünsün)', yk !== null);
        await pg.waitForTimeout(300);
        // hata yolu + Esc + perde
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').click(); await pg.waitForTimeout(100);
        await pg.unroute('**/puantaj_giris_kaydet.php'); vIstek = []; await vRota(pg, { hata: 'Dosyada en çok 500 satır olabilir (501 satır bulundu).' });
        await pg.setInputFiles('#pkDosya', { name: 'kisiler.csv', mimeType: 'text/csv', buffer: Buffer.from('Ad\nx\n') }); await pg.waitForTimeout(300);
        ok(ks + 'sunucu reddederse: hata mesajı görünür, önizleme yok, Ekle pasif', /en çok 500 satır/.test(await pg.locator('#pkExcelHata').innerText()) && await pg.locator('#pkExcelOnizleme').isHidden() && await pg.locator('#pkExcelEkle').isDisabled());
        await pg.keyboard.press('Escape'); await pg.waitForTimeout(100);
        ok(ks + 'Esc pencereyi + perdeyi kapatır', !(await pg.locator('#pkExcel').isVisible()) && !(await pg.locator('#pgOvl').isVisible()));
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').click(); await pg.waitForTimeout(100);
        await pg.mouse.click(5, 450); await pg.waitForTimeout(100);
        ok(ks + 'perdeye tıklamak pencereyi kapatır', !(await pg.locator('#pkExcel').isVisible()));
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').click(); await pg.waitForTimeout(100);
        // koyu tema
        await pg.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await pg.waitForTimeout(100);
        const kd = await pg.evaluate(() => { const g = el => getComputedStyle(el); return { pen: g(document.getElementById('pkExcel')).backgroundColor, fg: g(document.getElementById('pkExcel')).color, tablo: g(document.querySelector('.pk-tablo td')).backgroundColor, tfg: g(document.querySelector('.pk-tablo td')).color, chip: g(document.querySelector('.pk-chip-aktif')).backgroundColor, chipf: g(document.querySelector('.pk-chip-aktif')).color, dosya: g(document.getElementById('pkDosya')).backgroundColor }; });
        ok(ks + 'koyu tema: Excel penceresi + liste tablosu koyu zemin, okunur yazı; sayaç çipi okunur', lum(kd.pen) < 0.25 && lum(kd.fg) > 0.6 && lum(kd.tablo) < 0.25 && lum(kd.tfg) > 0.6 && Math.abs(lum(kd.chip) - lum(kd.chipf)) > 0.3, JSON.stringify(kd));
        await pg.keyboard.press('Escape');
        await pg.emulateMedia({ media: 'print' });
        ok(ks + 'yazdırmada Excel penceresi / toplu çubuk / iç anahtar gizli', !(await pg.locator('#pkExcel').isVisible()) && !(await pg.locator('#pkCubuk').isVisible()) && !(await pg.locator('.pg-gorunum').isVisible()));
        ok(ks + 'oturum boyunca JS hatası yok', eh.length === 0, eh.join('|'));
        await pg.close();
        }

        // ── 5) KİŞİLER GÖRÜNÜMÜ (mobil 390) ──
        {
        const kms = '[390 kişiler] ';
        const pg = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });
        const eh = []; pg.on('pageerror', e => eh.push(e.message));
        vIstek = []; await vRota(pg, 'ok');
        await pg.goto(httpSayfa(sayfalar.kisiler)); await pg.waitForTimeout(300);
        const mk = await pg.evaluate(() => { const tr = document.querySelector('tr[data-pk-satir="1"]'); const cs = getComputedStyle(tr); const r = tr.getBoundingClientRect(); const bs = [...tr.querySelectorAll('.pk-islem .btn')].map(b => b.getBoundingClientRect().height); const ad = tr.querySelector('.pk-isim').getBoundingClientRect(); return { thead: getComputedStyle(document.querySelector('.pk-tablo thead')).display, disp: cs.display, sol: r.left, sag: r.right, w: document.documentElement.clientWidth, kucuk: Math.min(...bs), n: bs.length, adW: ad.width, tasma: document.documentElement.scrollWidth <= innerWidth + 1, adMetin: tr.querySelector('.pk-isim').textContent.trim(), etiket: tr.querySelector('.pk-sayi').getAttribute('data-label') }; });
        ok(kms + 'kart görünümü: tablo başlığı gizli, satır kart (grid), ekrana sığar, ad görünür (≥ 100px), 3 işlem düğmesi ≥ 44px, yatay taşma yok', mk.thead === 'none' && mk.disp === 'grid' && mk.sol >= 0 && mk.sag <= mk.w + 1 && mk.kucuk >= 44 && mk.n === 3 && mk.adW >= 100 && mk.tasma && mk.adMetin === 'Örnek Kişi A', JSON.stringify(mk));
        const inp = await pg.evaluate(() => [...document.querySelectorAll('.pc-page input[type=search], .pc-page select.pk-durum-sec')].map(i => getComputedStyle(i).fontSize));
        ok(kms + 'arama kutusu + durum seçici 16px (iOS zoom yok)', inp.length === 2 && inp.every(f => parseFloat(f) >= 16), inp.join());
        await pg.locator('tr[data-pk-satir="1"] input[data-pk-sec]').tap(); await pg.locator('tr[data-pk-satir="2"] input[data-pk-sec]').tap(); await pg.waitForTimeout(100);
        const bs = await pg.evaluate(() => { const c = document.getElementById('pkCubuk'); const r = c.getBoundingClientRect(); const cs = getComputedStyle(c); return { gor: !c.hidden, pos: cs.position, z: +cs.zIndex, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.clientWidth, h: innerHeight, ozet: document.getElementById('pkCubukOzet').textContent, sec: document.querySelectorAll('tr.pk-sec').length, kucuk: Math.min(...[...c.querySelectorAll('button')].filter(b => b.offsetParent !== null).map(b => b.getBoundingClientRect().height)) }; });
        ok(kms + 'toplu çubuk ALT SAYFA: "2 kişi seçili", fixed, tam genişlik, altta, z ≥ 600, düğmeler ≥ 44px', bs.gor && bs.ozet === '2 kişi seçili' && bs.sec === 2 && bs.pos === 'fixed' && bs.z >= 600 && Math.abs(bs.sol) <= 1 && bs.sag >= bs.w - 16 && Math.abs(bs.alt - bs.h) <= 1 && bs.kucuk >= 44, JSON.stringify(bs));
        await pg.locator('#pkCubuk [data-pk-sec-temizle]').first().tap(); await pg.waitForTimeout(80);
        await pg.locator('.pc-aksiyon [data-pk-excel-ac]').tap(); await pg.waitForTimeout(250);
        const em = await pg.evaluate(() => { const p = document.getElementById('pkExcel'); const r = p.getBoundingClientRect(); const cs = getComputedStyle(p); const f = document.getElementById('pkDosya'); const bt = [...p.querySelectorAll('.pg-pen-alt .btn')].map(b => b.getBoundingClientRect().height); return { gor: cs.display !== 'none', z: +cs.zIndex, sol: r.left, sag: r.right, alt: r.bottom, w: document.documentElement.clientWidth, h: innerHeight, mh: r.height, fs: parseFloat(cs.fontSize), ffs: parseFloat(getComputedStyle(f).fontSize), kucuk: Math.min(...bt), tasma: document.documentElement.scrollWidth <= innerWidth + 1 }; });
        ok(kms + "Excel penceresi ALT SAYFA: tam genişlik, altta, z ≥ 600, ekranın %92'sini aşmaz, dosya alanı 16px, düğmeler ≥ 44px, yatay taşma yok", em.gor && em.z >= 600 && Math.abs(em.sol) <= 1 && em.sag >= em.w - 16 && Math.abs(em.alt - em.h) <= 1 && em.mh <= em.h * 0.93 && em.ffs >= 16 && em.kucuk >= 44 && em.tasma, JSON.stringify(em));
        await pg.setInputFiles('#pkDosya', { name: 'kisiler.csv', mimeType: 'text/csv', buffer: Buffer.from('Ad\nx\n') }); await pg.waitForTimeout(400);
        const em2 = await pg.evaluate(() => { const p = document.getElementById('pkExcel'); const r = p.getBoundingClientRect(); const k = document.querySelector('.pk-excel-tablo-kap').getBoundingClientRect(); return { alt: r.bottom, h: innerHeight, ust: r.top, tasma: document.documentElement.scrollWidth <= innerWidth + 1, tabloIc: k.right <= innerWidth + 1, ekleGor: document.getElementById('pkExcelEkle').getBoundingClientRect().bottom <= innerHeight + 1 }; });
        ok(kms + 'önizleme açıkken alt sayfa ekranı aşmaz, "Ekle" düğmesi ekran içinde, tablo taşmaz', em2.ust >= 0 && em2.alt <= em2.h + 1 && em2.tasma && em2.tabloIc && em2.ekleGor, JSON.stringify(em2));
        await pg.keyboard.press('Escape');
        ok(kms + 'JS hatası yok', eh.length === 0, eh.join('|'));
        await pg.close();
        }
        }   // v555 blok sonu
        }   // v554 blok sonu

        // ───────── v556: AD SÜTUNU KAYBOLMASI — KÖK NEDEN REGRESYONU ─────────
        // KÖK NEDEN: sütun sınıfı "pc-ad" idi; EasyList genel süzgeci (##.pc-ad) reklam engelleyicide (uBlock / AdBlock / AdGuard)
        // tüm th.pc-ad hücrelerini display:none yapıyordu. Harness/Chromium'da eklenti olmadığı için önceki testler göremiyordu.
        // Bu blok engelleyiciyi BENZETİR (kozmetik süzgeç = display:none !important stil) ve sütunun yine de görünmesini ister.
        // MUTASYON: PCZ_MUTASYON=1 node scripts/puantaj_cizelgesi_smoke.js → sınıf adları "pc-ad"a geri çevrilir; bu blok FAIL vermelidir.
        {
        const v6 = '[v556] ';
        const MUT = process.env.PCZ_MUTASYON === '1';
        // Sayfa varyantı: head'e stil ekler; mutasyonda sınıf adlarını "pc-ad"a çevirir (HTML + CSS kopyası)
        let vSay = 0;
        function varyant(href, stil, mut) {
            const dosya = decodeURIComponent(new URL(href).pathname);
            let h = fs.readFileSync(dosya, 'utf8');
            if (mut) {
                const cssYol = path.join(root, 'assets/puantaj_cizelgesi.css');
                const cssKopya = path.join(tmp, 'mut_' + (++vSay) + '.css');
                let cssM = fs.readFileSync(cssYol, 'utf8'); const kes = cssM.indexOf('/* ── v556: kişi adı sütunu sertleştirme'); if (kes > 0) cssM = cssM.slice(0, kes);   // mutasyon = v555 durumu (sertleştirme kuralları YOK)
                fs.writeFileSync(cssKopya, cssM.split('pc-isim-yazi').join('pc-ad-ad').split('pc-isim').join('pc-ad'));
                h = h.split(pathToFileURL(cssYol).href).join(pathToFileURL(cssKopya).href).split('pc-isim-yazi').join('pc-ad-ad').split('pc-isim').join('pc-ad');
            }
            if (stil) h = h.replace('</head>', '<style id="vstil">' + stil + '</style></head>');
            const f = path.join(tmp, 'v556_' + (++vSay) + '_' + path.basename(dosya));
            fs.writeFileSync(f, h);
            return pathToFileURL(f).href;
        }
        // EasyList'in ESKİ sınıf adlarına uyan kuralı + yaygın genel (sezgisel) süzgeç kalıplarını taklit eder
        const ENGELLEYICI = '.pc-ad,.pc-ad-ad,.pg-ad-kap,.pg-ad-metin,.pc-col-ad,.pk-ad,.pcy-ad,.pc-adet,[class^="ad-"],[class*="-ad-"],[class$="-ad"],[class*=" ad-"],[id^="ad-"],[class*="advert"],[class*="sponsor"]{display:none!important}';
        const OLC6 = () => {
            const t = document.querySelector('table.pc-grid'); const wrap = document.getElementById('pcGridWrap');
            if (!t) return { tablo: false };
            const bas = t.querySelector('thead tr').firstElementChild;
            const ad = [...t.querySelectorAll('tbody tr')].map(tr => { const th = tr.firstElementChild; const m = th.querySelector('span'); return { tag: th.tagName, w: th.getBoundingClientRect().width, txt: (th.textContent || '').trim() }; });
            const tf = [...t.querySelectorAll('tfoot tr')].map(tr => tr.firstElementChild.getBoundingClientRect().width);
            const wr = wrap.getBoundingClientRect(), br = bas.getBoundingClientRect();
            return { tablo: true, wrapGor: wrap.offsetParent !== null, uyari: !!document.getElementById('pcIsimUyari'), yok: document.querySelector('.pc-page').classList.contains('pc-isim-yok'),
                kose: { tag: bas.tagName, w: br.width, txt: bas.textContent.replace(/\s+/g, ' ').trim(), solda: Math.abs(br.left - wr.left) <= wrap.clientLeft + 3 }, ad, tf, sc: wrap.scrollLeft, tasar: wrap.scrollWidth > wrap.clientWidth + 1, sw: document.documentElement.scrollWidth, iw: innerWidth };
        };
        const adTamam = o => o.tablo && o.wrapGor && !o.uyari && !o.yok && o.kose.tag === 'TH' && o.kose.w >= 80 && o.kose.txt.length > 0 && o.ad.length > 0 && o.ad.every(a => a.tag === 'TH' && a.w >= 80 && a.txt.length > 0) && o.tf.every(w => w >= 80);
        const SEK = { giris: [sayfalar.giris, 3], personel: [sayfalar.personel, 4], gunluk: [sayfalar.gunluk, 2] };

        // (1) DOM'da reklam-süzgeci sezgisine uyan sınıf / kimlik parçası YOK ("ad", "ads", "advert", "sponsor", "banner"; ad-/-ad/-ad- dahil)
        for (const [sk, [href]] of Object.entries(SEK).concat([['kisiler', [sayfalar.kisiler]], ['yazdir', [sayfalar.girisYazdir]]])) {
            const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
            await pg.goto(varyant(href, '', MUT)); await pg.waitForTimeout(200);
            const kotu = await pg.evaluate(() => {
                const bulunan = new Set(); const kural = /^(ad|ads|advert|adverts|advertisement|sponsor|sponsored|banner|promo)$/i;
                document.querySelectorAll('.pc-page *, .pc-page, .pcy-sayfa *, table *, table').forEach(el => { [...el.classList].concat(el.id ? [el.id] : []).forEach(c => { if (c.split(/[-_]/).some(p => kural.test(p))) bulunan.add(c); }); });
                return [...bulunan];
            });
            ok(v6 + sk + ': DOM\'daki hiçbir sınıf/kimlik adı "ad / ads / advert / sponsor / banner" parçası içermez (EasyList genel süzgeci bunları gizler)', kotu.length === 0, kotu.join(','));
            await pg.close();
        }

        // (2) Engelleyici benzetimi: 3 sekme × 2 genişlik → ad sütunu YİNE görünür (süzgeç yüklemeden ÖNCE ve SONRA eklenir)
        for (const [sk, [href, n]] of Object.entries(SEK)) {
            for (const w of [1280, 1665]) {
                const pg = await browser.newPage({ viewport: { width: w, height: 900 } });
                await pg.goto(varyant(href, ENGELLEYICI, MUT)); await pg.waitForTimeout(400);
                let o = await pg.evaluate(OLC6);
                ok(v6 + '[' + w + ' · ' + sk + '] engelleyici benzetimi (sayfa yüklenirken var): ad sütunu görünür (köşe + ' + n + ' satır + alt toplamlar ≥ 80px, dolu metin), uyarı bandı/yedek liste YOK', adTamam(o) && o.ad.length === n, JSON.stringify(o));
                const pg2 = await browser.newPage({ viewport: { width: w, height: 900 } });
                await pg2.goto(varyant(href, '', MUT)); await pg2.waitForTimeout(150);
                await pg2.addStyleTag({ content: ENGELLEYICI }); await pg2.waitForTimeout(2300);   // "görünüp kayboluyor": süzgeç SONRADAN devreye girer
                o = await pg2.evaluate(OLC6);
                ok(v6 + '[' + w + ' · ' + sk + '] engelleyici SONRADAN devreye girince de (görünüp kaybolma): ad sütunu görünür', adTamam(o) && o.ad.length === n, JSON.stringify(o));
                await pg.close(); await pg2.close();
            }
        }

        // (3) BEKÇİ: sütun BAŞKA bir sebeple gizlenirse çıkmaz yok → uyarı bandı + kişi listesi; yanlış alarm yok
        const GIZLE = 'html body .pc-grid th:first-child, html body .pc-grid tbody th, html body .pc-grid tfoot th{display:none!important}';
        for (const [sk, [href, n]] of Object.entries(SEK)) {
            const pg = await browser.newPage({ viewport: { width: 1665, height: 900 } });
            await pg.goto(varyant(href, GIZLE)); await pg.waitForTimeout(500);
            const b = await pg.evaluate(() => { const u = document.getElementById('pcIsimUyari'); const l = document.querySelector('.pc-liste'); const g = document.getElementById('pcGridWrap'); return { uyari: !!u && u.offsetParent !== null && u.getAttribute('role') === 'alert' && /reklam engelleyici/.test(u.textContent), liste: getComputedStyle(l).display !== 'none', grid: getComputedStyle(g).display, adlar: [...document.querySelectorAll('.pc-liste .pc-isim-yazi')].map(e => e.textContent.trim()).filter(Boolean).length, tasma: document.documentElement.scrollWidth <= innerWidth + 1, cls: document.querySelector('.pc-page').classList.contains('pc-isim-yok') }; });
            ok(v6 + '[bekçi · ' + sk + '] sütun gizlenirse: uyarı bandı (role=alert, "reklam engelleyici"), kişi listesi görünür (' + n + ' ad), ızgara gizli, taşma yok', b.uyari && b.liste && b.grid === 'none' && b.adlar === n && b.tasma && b.cls, JSON.stringify(b));
            await pg.close();
        }
        {   // DOM'dan SİLİNME + gecikmeli gizleme + yanlış alarm yok
            let pg = await browser.newPage({ viewport: { width: 1665, height: 900 } });
            await pg.goto(varyant(sayfalar.giris, '', MUT)); await pg.waitForTimeout(150);
            await pg.evaluate(() => document.querySelectorAll('th.pc-isim, th.pc-ad').forEach(t => t.remove())); await pg.waitForTimeout(2300);
            const sil = await pg.evaluate(() => ({ cls: document.querySelector('.pc-page').classList.contains('pc-isim-yok'), uyari: !!document.getElementById('pcIsimUyari'), liste: getComputedStyle(document.querySelector('.pc-liste')).display }));
            ok(v6 + '[bekçi] ad hücreleri DOM\'dan silinirse (eklenti) yedek liste + uyarı devreye girer', sil.cls && sil.uyari && sil.liste !== 'none', JSON.stringify(sil));
            await pg.close();
            pg = await browser.newPage({ viewport: { width: 1665, height: 900 } });
            await pg.goto(varyant(sayfalar.giris, '', MUT)); await pg.waitForTimeout(150);
            await pg.addStyleTag({ content: GIZLE }); await pg.waitForTimeout(2300);
            ok(v6 + '[bekçi] sütun yüklemeden SONRA gizlenirse (gecikmeli) de yedek liste + uyarı', await pg.evaluate(() => document.querySelector('.pc-page').classList.contains('pc-isim-yok') && !!document.getElementById('pcIsimUyari')));
            await pg.close();
            for (const [w, dsf, ad] of [[1280, 1, 'normal'], [1665, 1.25, '%80 yakınlaştırma (dsf 1,25)'], [1024, 1.5, 'dsf 1,5'], [390, 1, 'mobil']]) {
                pg = await browser.newPage({ viewport: { width: w, height: 900 }, deviceScaleFactor: dsf });
                await pg.goto(sayfalar.giris); await pg.waitForTimeout(2500);
                ok(v6 + '[bekçi] yanlış alarm yok (' + ad + ', ' + w + 'px): normal sayfada uyarı bandı çıkmaz', await pg.evaluate(() => !document.getElementById('pcIsimUyari') && !document.querySelector('.pc-page').classList.contains('pc-isim-yok')));
                await pg.close();
            }
        }

        // (4) Yakınlaştırma / viewport varyasyonları: ad sütunu görünür + yapışık (3 sekme × 1024…2560)
        for (const [sk, [href, n]] of Object.entries(SEK)) {
            for (const w of [1024, 1100, 1280, 1440, 1500, 1600, 1665, 1700, 1920, 2560]) {
                const pg = await browser.newPage({ viewport: { width: w, height: 900 } });
                await pg.goto(href); await pg.waitForTimeout(350);
                const o = await pg.evaluate(OLC6);
                const k = await pg.evaluate(() => { const wr = document.getElementById('pcGridWrap'); wr.scrollLeft = 99999; return new Promise(r => requestAnimationFrame(() => { const th = document.querySelector('tbody tr').firstElementChild; const rc = th.getBoundingClientRect(); const e = document.elementFromPoint(rc.left + 12, rc.top + 8); r({ sol: Math.abs(rc.left - wr.getBoundingClientRect().left), ust: !!e && th.contains(e) }); })); });
                ok(v6 + '[' + w + ' · ' + sk + '] ad sütunu görünür; açılıştaki otomatik "bugüne kaydır" ad sütununu GİZLEMEZ (köşe solda); sona kaydırınca yapışık + üstte' + (o.tasar ? '' : ' (taşma yok → kaydırma yok)'), adTamam(o) && o.kose.solda && k.sol <= 2 && k.ust && (o.tasar || o.sc === 0), JSON.stringify([o.kose, o.sc, o.tasar, k]));
                await pg.close();
            }
        }
        // (5) Otomatik kaydırma güvencesi: sticky çalışmıyorsa kaydırma YAPILMAZ; taşma yoksa kaydırma yok
        {
            let pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
            await pg.goto(varyant(sayfalar.personel, 'html body .pc-grid th{position:static!important}', MUT)); await pg.waitForTimeout(400);
            const st = await pg.evaluate(() => ({ sc: document.getElementById('pcGridWrap').scrollLeft, tasar: document.getElementById('pcGridWrap').scrollWidth > document.getElementById('pcGridWrap').clientWidth, sol: Math.abs(document.querySelector('tbody tr').firstElementChild.getBoundingClientRect().left - document.getElementById('pcGridWrap').getBoundingClientRect().left) }));
            ok(v6 + '[otomatik kaydırma] yapışkanlık (sticky) bozuksa BUGÜNE KAYDIRMA YAPILMAZ (scrollLeft 0) → ad sütunu görünür kalır', st.tasar && st.sc === 0 && st.sol <= 2, JSON.stringify(st));
            await pg.close();
            pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
            await pg.goto(sayfalar.personel); await pg.waitForTimeout(400);
            const st2 = await pg.evaluate(() => { const w = document.getElementById('pcGridWrap'); const b = w.querySelector('th[data-bugun]'); return { sc: w.scrollLeft, bugun: !!b }; });
            ok(v6 + '[otomatik kaydırma] sticky sağlamsa bugüne kaydırır (scrollLeft > 0; bu ay bugün sütunu var)', st2.bugun && (st2.sc > 0 || new Date().getDate() <= 8), JSON.stringify(st2));
            await pg.close();
        }
        // (6) Ortam varyasyonları: dokunmatik, retina, koyu renk şeması, hareket azaltma, zorunlu renkler
        for (const [ad, ctxOpt] of [['dokunmatik (hasTouch)', { hasTouch: true }], ['dsf 1,25', { deviceScaleFactor: 1.25 }], ['dsf 1,5', { deviceScaleFactor: 1.5 }], ['koyu renk şeması', { colorScheme: 'dark' }], ['hareket azaltma', { reducedMotion: 'reduce' }], ['zorunlu renkler', { forcedColors: 'active' }]]) {
            for (const [sk, [href, n]] of Object.entries(SEK)) {
                const c = await browser.newContext(Object.assign({ viewport: { width: 1500, height: 900 } }, ctxOpt));
                const pg = await c.newPage();
                await pg.goto(href); await pg.waitForTimeout(350);
                const o = await pg.evaluate(OLC6);
                ok(v6 + '[' + ad + ' · ' + sk + '] ad sütunu görünür', adTamam(o) && o.ad.length === n, JSON.stringify(o));
                await c.close();
            }
        }
        // koyu TEMA (data-theme) + yazdırma çıktısı
        {
            const pg = await browser.newPage({ viewport: { width: 1500, height: 900 } });
            await pg.goto(sayfalar.giris); await pg.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark')); await pg.waitForTimeout(200);
            const o = await pg.evaluate(OLC6);
            ok(v6 + '[koyu tema] ad sütunu görünür', adTamam(o), JSON.stringify(o));
            await pg.close();
            for (const [sk, href] of [['personel', sayfalar.yazdir], ['giris', sayfalar.girisYazdir]]) {
                const p2 = await browser.newPage({ viewport: { width: 1100, height: 900 } });
                await p2.goto(href); await p2.emulateMedia({ media: 'print' }); await p2.waitForTimeout(250);
                const y = await p2.evaluate(() => [...document.querySelectorAll('.pcy-tablo tbody tr')].map(tr => { const c = tr.firstElementChild; return { w: c.getBoundingClientRect().width, t: c.textContent.trim().length }; }));
                ok(v6 + '[yazdırma · ' + sk + '] ad sütunu çıktıda görünür (her satır ≥ 60px, dolu metin)', y.length > 0 && y.every(c => c.w >= 60 && c.t > 0), JSON.stringify(y));
                await p2.close();
            }
        }
        }   // v556 blok sonu
    } finally { await browser.close(); try { srv && srv.close(); } catch (_) { /* */ } }
    console.log('\nGeçen: ' + gecen + '   Kalan: ' + fail);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
