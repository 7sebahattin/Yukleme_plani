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
function uret(sayfa, get) {
    let html = execFileSync('php', [path.join(root, 'scripts/_puantaj_cizelgesi_harness.php'), sayfa, JSON.stringify(get), JSON.stringify({ admin: true, ay, css: true, depo: '' })],
        { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] });
    html = html.split('href="assets/').join('href="' + pathToFileURL(root).href + '/assets/');   // yazdırma sayfası göreli assets/ yolu kullanır
    const f = path.join(tmp, sayfa.replace('.php', '') + '_' + (get.sekme || 'personel') + '.html');
    fs.writeFileSync(f, html);
    return pathToFileURL(f).href;
}
const sayfalar = {
    personel: uret('puantaj_cizelgesi.php', { ay }),
    gunluk: uret('puantaj_cizelgesi.php', { ay, sekme: 'gunluk' }),
    yazdir: uret('puantaj_cizelgesi_yazdir.php', { ay }),
};
let fail = 0, gecen = 0;
function ok(ad, c, d) { c ? gecen++ : fail++; console.log((c ? 'OK ' : 'FAIL ') + ad + (c || !d ? '' : ' — ' + d)); }
const opak = c => !/^rgba\(\s*0,\s*0,\s*0,\s*0\s*\)$|^transparent$/.test(c);
const lum = c => { const m = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };

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
    } finally { await browser.close(); }
    console.log('\nGeçen: ' + gecen + '   Kalan: ' + fail);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
