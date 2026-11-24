#!/usr/bin/env node
// =========================================================
// scripts/ajanda_takvim_smoke.js — Ajanda takvimi (v576) TARAYICI testi (düzen/hover PHP testleriyle ölçülemez)
//   node scripts/ajanda_takvim_smoke.js        → çıkış kodu 0 = hepsi geçti
// Sayfayı GERÇEK PHP ile çizer (scripts/kullanici_ayar_render.php ajanda|ajanda_gun; bellek içi SQLite) ve Playwright ile ölçer:
// takvim sayfanın ÜSTÜNDE ve büyük · gün üzerine gelince/klavye odağında baloncuk (not içeriğiyle) görünür ve ekran içinde kalır ·
// boş günde baloncuk yok · mobilde yatay taşma yok, baloncuk gizli, çip yerine nokta · koyu temada baloncuk açık zeminli DEĞİL ·
// ?gun= ile seçili gün paneli + formun tarihi o gün.
// =========================================================
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'ajanda_takvim_'));
let pass = 0, fail = 0;
const ok = (ad, v, d = '') => { if (v) { pass++; console.log('PASS ' + ad); } else { fail++; console.log('FAIL ' + ad + (d ? ' :: ' + d : '')); } };

function ciz(profil) {
    let html = execFileSync('php', [path.join(__dirname, 'kullanici_ayar_render.php'), profil], { cwd: ROOT, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    html = html.replace(/"(?:\.\.\/)+/g, '"').replace('<head>', '<head><base href="file://' + ROOT + '/">');
    const f = path.join(TMP, profil + '.html');
    fs.writeFileSync(f, html);
    return 'file://' + f;
}

(async () => {
    const urlAjanda = ciz('ajanda');
    const urlGun = ciz('ajanda_gun');
    const browser = await chromium.launch();
    try {
        // ---------- Masaüstü ----------
        const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
        const p = await ctx.newPage();
        const hatalar = [];
        p.on('pageerror', e => hatalar.push(e.message));
        p.on('console', m => { if (m.type() === 'error') hatalar.push(m.text()); });
        await p.goto(urlAjanda);
        await p.waitForTimeout(450);

        const yer = await p.evaluate(() => {
            const k = document.querySelector('.ka-takvim-kart').getBoundingClientRect();
            const g = document.querySelector('.ka-gun').getBoundingClientRect();
            const form = document.querySelector('form.ka-yeni-form').getBoundingClientRect();
            return { top: Math.round(k.top + scrollY), h: Math.round(k.height), w: Math.round(k.width), hucreH: Math.round(g.height), formTop: Math.round(form.top + scrollY), hafta: document.querySelectorAll('.ka-takvim > .ka-hafta-satir').length - 0 };
        });
        ok('takvim sayfanın ÜSTÜNDE (ilk kart) ve büyük', yer.top < 160 && yer.h > 600 && yer.w > 800, JSON.stringify(yer));
        ok('gün hücreleri geniş (≥ 100px yüksek)', yer.hucreH >= 100, String(yer.hucreH));
        ok('yeni kayıt formu takvimin ALTINDA', yer.formTop > yer.top + yer.h, JSON.stringify(yer));
        ok('ay başlığı + önceki/sonraki + 7 gün başlığı', await p.evaluate(() => !!document.querySelector('.ka-ay-baslik') && document.querySelectorAll('.ka-gezin-dugme').length === 2 && document.querySelectorAll('.ka-hafta-baslik span').length === 7));

        // Bugün hücresi: işaret + baloncuk
        const bugun = p.locator('.ka-gun.bugun');
        ok('bugün hücresi tek ve işaretli', (await bugun.count()) === 1);
        await p.mouse.move(2, 2);
        ok('fare dışarıdayken baloncuk gizli', await p.evaluate(() => getComputedStyle(document.querySelector('.ka-gun.bugun .ka-balon')).visibility === 'hidden'));
        await bugun.hover(); await p.waitForTimeout(450);
        const b1 = await p.evaluate(() => { const e = document.querySelector('.ka-gun.bugun .ka-balon'); const r = e.getBoundingClientRect(); return { v: getComputedStyle(e).visibility, o: getComputedStyle(e).opacity, l: r.left, r: r.right, t: r.top, b: r.bottom, vw: innerWidth, vh: innerHeight, metin: e.textContent }; });
        ok('bugüne gelince baloncuk görünür', b1.v === 'visible' && b1.o === '1');
        ok('baloncukta kayıt başlığı + SAAT + NOT içeriği var', b1.metin.includes('OGL hesabını sisteme bağla') && b1.metin.includes('14:00') && b1.metin.includes('iskonto'), b1.metin.slice(0, 120));
        ok('baloncuk ekran içinde', b1.l >= 0 && b1.r <= b1.vw && b1.t >= 0 && b1.b <= b1.vh, JSON.stringify(b1));

        // Sağ kolon: baloncuk ekran dışına taşmamalı
        const sag = p.locator('.ka-gun.dolu.hsonu').first();
        if (await sag.count()) {
            await sag.hover(); await p.waitForTimeout(450);
            const b2 = await p.evaluate(() => { const e = document.querySelector('.ka-gun.dolu.hsonu .ka-balon'); const r = e.getBoundingClientRect(); return { v: getComputedStyle(e).visibility, l: r.left, r: r.right, vw: innerWidth }; });
            ok('hafta sonu (sağ kolon) baloncuğu görünür ve sağdan TAŞMAZ', b2.v === 'visible' && b2.r <= b2.vw && b2.l >= 0, JSON.stringify(b2));
        } else ok('hafta sonu dolu gün örneği var', false, 'örnek veri değişti');

        // Boş gün: baloncuk yok
        const bos = p.locator('.ka-gun:not(.dolu)').nth(12);
        await bos.hover(); await p.waitForTimeout(300);
        ok('boş güne gelince baloncuk ÇIKMAZ', await p.evaluate(() => ![...document.querySelectorAll('.ka-balon')].some(e => getComputedStyle(e).visibility === 'visible')));

        // Klavye odağı
        await p.mouse.move(2, 2);
        let odak = false;
        for (let i = 0; i < 80 && !odak; i++) { await p.keyboard.press('Tab'); odak = await p.evaluate(() => document.activeElement?.classList.contains('ka-gun') && document.activeElement.classList.contains('dolu')); }
        await p.waitForTimeout(350);
        ok('klavye odağı dolu güne gelince baloncuk görünür', odak && await p.evaluate(() => getComputedStyle(document.activeElement.querySelector('.ka-balon')).visibility === 'visible'));
        ok('dolu gün hücresi aria-describedby ile baloncuğa bağlı', await p.evaluate(() => { const a = document.querySelector('.ka-gun.dolu'); const id = a.getAttribute('aria-describedby'); return !!id && !!document.getElementById(id) && document.getElementById(id).getAttribute('role') === 'tooltip'; }));
        ok('masaüstünde çipler görünür, noktalar gizli', await p.evaluate(() => getComputedStyle(document.querySelector('.ka-gun.dolu .ka-cipler')).display !== 'none' && getComputedStyle(document.querySelector('.ka-gun.dolu .ka-noktalar')).display === 'none'));
        ok('yatay taşma yok (masaüstü)', await p.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));

        // Koyu tema
        await p.evaluate(() => window.asyaTemaSec('koyu')); await p.waitForTimeout(700);
        await p.locator('.ka-gun.bugun').hover(); await p.waitForTimeout(450);
        const koyu = await p.evaluate(() => { const e = document.querySelector('.ka-gun.bugun .ka-balon'); return { bg: getComputedStyle(e).backgroundColor, renk: getComputedStyle(e).color, v: getComputedStyle(e).visibility, hucre: getComputedStyle(document.querySelector('.ka-gun:not(.hsonu):not(.disari):not(.bugun)')).backgroundColor }; });
        const lum = c => { const m = c.match(/\d+/g).map(Number); return (0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2]) / 255; };
        ok('koyu tema: baloncuk koyu zeminli + okunur yazı', koyu.v === 'visible' && lum(koyu.bg) < 0.35 && lum(koyu.renk) > 0.6, JSON.stringify(koyu));
        ok('koyu tema: gün hücreleri koyu', lum(koyu.hucre) < 0.35, koyu.hucre);
        ok('konsol/sayfa hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();

        // ---------- Seçili gün (JS'siz sunucu çizimi) ----------
        const gctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
        const gp = await gctx.newPage();
        await gp.goto(urlGun); await gp.waitForTimeout(350);
        const g = await gp.evaluate(() => ({ panel: !!document.getElementById('ka-secili'), secili: document.querySelectorAll('.ka-gun.secili').length, bugun: document.querySelector('.ka-gun.bugun')?.classList.contains('secili'), tarih: document.querySelector('form.ka-yeni-form input[name="tarih"]').value, adet: document.querySelectorAll('#ka-secili .ka-kayit').length, kapat: !!document.querySelector('#ka-secili a[href^="ajanda.php?ay="]'), secimGun: document.querySelector('.ka-gun.secili')?.getAttribute('href') }));
        ok('?gun= → gün paneli çizilir, o gün işaretli', g.panel && g.secili === 1 && g.bugun === true, JSON.stringify(g));
        ok('seçili günün TÜM kayıtları panelde (tamamlanan dahil)', g.adet === 4, String(g.adet));
        ok('yeni kayıt formunun tarihi seçili gün', /^\d{4}-\d{2}-\d{2}$/.test(g.tarih) && g.secimGun.includes('gun=' + g.tarih), JSON.stringify(g));
        await gctx.close();

        // ---------- Mobil (dokunmatik) ----------
        for (const w of [390, 360]) {
            const mctx = await browser.newContext({ viewport: { width: w, height: 844 }, isMobile: true, hasTouch: true });
            const mp = await mctx.newPage();
            await mp.goto(urlGun); await mp.waitForTimeout(450);
            const m = await mp.evaluate(() => {
                const dolu = document.querySelector('.ka-gun.dolu');
                const r = dolu.getBoundingClientRect();
                return {
                    tasma: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1, sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
                    balon: getComputedStyle(dolu.querySelector('.ka-balon')).display, cip: getComputedStyle(dolu.querySelector('.ka-cipler')).display,
                    nokta: getComputedStyle(dolu.querySelector('.ka-noktalar')).display, say: getComputedStyle(dolu.querySelector('.ka-gun-say')).display,
                    hw: Math.round(r.width), hh: Math.round(r.height),
                    sutun: getComputedStyle(document.querySelector('.ka-hafta-satir')).gridTemplateColumns.split(' ').length,
                    form: parseFloat(getComputedStyle(document.querySelector('form.ka-yeni-form input[name="baslik"]')).fontSize),
                };
            });
            ok(`mobil @${w}: yatay taşma yok`, !m.tasma, JSON.stringify(m));
            ok(`mobil @${w}: 7 sütun, hücre dokunma hedefi ≥ 44px`, m.sutun === 7 && m.hw >= 44 && m.hh >= 44, JSON.stringify(m));
            ok(`mobil @${w}: baloncuk + çip gizli, nokta + sayı görünür`, m.balon === 'none' && m.cip === 'none' && m.nokta === 'flex' && m.say !== 'none', JSON.stringify(m));
            ok(`mobil @${w}: form alanları ≥ 16px (iOS zoom yok)`, m.form >= 16, String(m.form));
            await mctx.close();
        }
    } finally {
        await browser.close();
        fs.rmSync(TMP, { recursive: true, force: true });
    }
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
