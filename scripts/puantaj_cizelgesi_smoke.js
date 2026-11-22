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
    personel: uret('puantaj_cizelgesi.php', { ay }),
    gunluk: uret('puantaj_cizelgesi.php', { ay, sekme: 'gunluk' }),
    yazdir: uret('puantaj_cizelgesi_yazdir.php', { ay }),
};
// v553 elle işaret: tohumlu işaretler (A: 3 İ [kayıt çakışır], 6 D; B: 10 R, 11 T; C: 12 Ü). Salt okunur varyant: 'edit' izni yok, tablo yok varyantı.
const ISR = [[1, 3, 'İ'], [1, 6, 'D'], [2, 10, 'R'], [2, 11, 'T'], [3, 12, 'Ü']];
const SALT = ['role.permissions.detailed', 'attendance.monthly_timesheet.read', 'attendance.employees.read', 'attendance.hub.read'];
sayfalar.isaret = uret('puantaj_cizelgesi.php', { ay }, { isaretler: ISR }, 'isaret');
sayfalar.salt = uret('puantaj_cizelgesi.php', { ay }, { admin: false, perms: SALT, isaretler: ISR }, 'salt');
sayfalar.yazdirIsaret = uret('puantaj_cizelgesi_yazdir.php', { ay }, { isaretler: ISR }, 'isaret');
// v554 Puantaj Girişi (bağımsız sekme): A (X Y D İ Ü R T hepsi), B, İsmail, pasif D. Tablolar yalnız bu sayfalar için KURULUR (harness 'giris').
const GK = [[1, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1], [2, 'Örnek Kişi B', '', 'Depo A', 1], [3, 'İsmail Örnek', 'Şoför', 'Depo A', 1], [4, 'Örnek Kişi D', '', 'Depo A', 0]];
const GG = [[1, 2, 'X'], [1, 3, 'Y'], [1, 4, 'D'], [1, 5, 'İ'], [1, 6, 'Ü'], [1, 9, 'R'], [1, 10, 'T'], [2, 2, 'X'], [2, 3, 'X'], [3, 3, 'Y']];
const GBASE = { giris: true, depo: 'Depo A', allowed: ['Depo A'], giris_kisiler: GK, giris_gunler: GG };
sayfalar.giris = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris' }, GBASE, 'g');
sayfalar.girisPasif = uret('puantaj_cizelgesi.php', { ay, sekme: 'giris', pasif: '1' }, GBASE, 'gp');
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
            const w = document.querySelector('.pc-grid-wrap'); const td = document.querySelector('tbody th.pc-ad');
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
        const koyu = await page.evaluate(() => { const td = document.querySelector('tbody th.pc-ad'); const c = document.querySelector('tbody td.pc-b:not(.pc-hs)'); const g = getComputedStyle(td); const gc = getComputedStyle(c);
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
                const kv = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
                girisIstek.push(kv);
                if (mod === 'iptal') return route.abort();
                if (mod && mod.status) return route.fulfill({ status: mod.status, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(mod.json) });
                let j = { ok: true, islem: kv.action };
                if (kv.action === 'gun_isaretle') j.degisim = [{ k: +kv.kisi_id, t: kv.tarih, c: kv.kod }];
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
        ok(gs + 'sekme çubuğu: Personel · Günlük İşçi · Puantaj Girişi (aktif)', (await page.locator('.pc-sekme').allInnerTexts()).join('|') === 'Personel|Günlük İşçi|Puantaj Girişi' && (await page.locator('.pc-sekme.is-aktif').innerText()) === 'Puantaj Girişi');
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

        // gün sütunu başlığı → tüm görünen kişilere tek kod (onaylı)
        girisIstek = [];
        await girisRota(page, 'ok');
        const gunBtn = page.locator('thead [data-pg-gun]').nth(14);
        const gunT = await gunBtn.getAttribute('data-pg-gun');
        await gunBtn.click(); await page.waitForTimeout(150);
        ok(gs + 'gün numarasına tıklayınca menü açılır; başlıkta "tüm görünen kişiler"; Temizle açık', /tüm görünen kişiler/.test(await page.locator('#pgMenuBaslik').innerText()) && !(await page.locator('#pgMenu [data-pg-kod=""]').isDisabled()));
        await page.locator('#pgMenu [data-pg-kod="R"]').click(); await page.waitForTimeout(150);
        const onay = await page.evaluate(() => ({ gor: getComputedStyle(document.getElementById('pgPen')).display !== 'none', baslik: document.getElementById('pgPenBaslik').textContent, mesaj: document.getElementById('pgPenMesaj').textContent, alan: document.getElementById('pgPenAlan').hidden, tamam: document.getElementById('pgPenTamam').textContent }));
        ok(gs + 'kod seçilince ONAY penceresi: "3 aktif kişiye" + kod adı + "üzerine yazılır"; alan kutuları gizli; istek henüz GİTMEDİ', onay.gor && /3 aktif kişiye/.test(onay.mesaj) && /R — Raporlu/.test(onay.mesaj) && /üzerine yazılır/.test(onay.mesaj) && onay.alan && onay.tamam === 'Uygula' && girisIstek.length === 0, JSON.stringify(onay));
        await page.locator('#pgPenTamam').click(); await page.waitForTimeout(300);
        const sut = await page.evaluate((t) => [1, 2, 3].map(k => document.querySelector('tr[data-pg-satir="' + k + '"] td[data-tarih="' + t + '"]').getAttribute('data-kod')).join('') + '|' + document.querySelector('tr[data-pg-satir="1"] td[data-oz="rapor"]').textContent + '|' + document.querySelector('td[data-gz-oz="rapor"]').textContent, gunT);
        ok(gs + 'Uygula: tek POST {action=gun_sutun, tarih, kod=R, q} ; üç kişinin o günü R; Rapor özetleri (kişi 1 → 2, genel → 4) yerinde güncellendi', girisIstek.length === 1 && girisIstek[0].action === 'gun_sutun' && girisIstek[0].tarih === gunT && girisIstek[0].kod === 'R' && girisIstek[0].q === '' && sut === 'RRR|2|4', JSON.stringify([girisIstek, sut]));
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
        const ps = await page.evaluate(() => { const tr = document.querySelector('tr[data-pg-satir="4"]'); return { var: !!tr, cls: tr && tr.className, dz: tr ? tr.querySelectorAll('[data-pg]').length : -1, etiket: tr ? tr.querySelector('.pg-pasif-etiket').textContent : '', op: tr ? getComputedStyle(tr.querySelector('.pc-ad-ad')).opacity : '' }; });
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
        }   // v554 blok sonu
    } finally { await browser.close(); try { srv && srv.close(); } catch (_) { /* */ } }
    console.log('\nGeçen: ' + gecen + '   Kalan: ' + fail);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
