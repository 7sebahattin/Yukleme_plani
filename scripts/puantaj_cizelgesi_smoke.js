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
        ok(sol + 'üst çubuk: ay oku ‹ › + month + arama + sekmeler + Excel menüsü + Yazdır', await page.locator('.pc-ok').count() === 2 && await page.locator('input[type=month]').count() === 1 && await page.locator('input[type=search]').count() === 1 && await page.locator('.pc-sekme').count() === 2 && await page.locator('.dl-menu-btn').count() === 1 && await page.locator('a:has-text("Yazdır")').count() === 1);
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
    } finally { await browser.close(); try { srv && srv.close(); } catch (_) { /* */ } }
    console.log('\nGeçen: ' + gecen + '   Kalan: ' + fail);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
