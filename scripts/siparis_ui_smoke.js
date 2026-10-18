// =========================================================
// scripts/siparis_ui_smoke.js — Siparişler GERÇEK TARAYICI testi (Playwright + Chromium)
//   SIPARIS_UI_OUT=/tmp/sip-ui php scripts/siparis_ui_render.php
//   SIPARIS_UI_OUT=/tmp/sip-ui node scripts/siparis_ui_smoke.js
// Ekranlar 1280x900 · 820x1024 · 390x844 (ölçümden önce 400 ms beklenir: açılış animasyonu 220 ms).
// Ölçer: yatay sayfa taşması yok · .pc-only/.mobile-only 1024'te geçer · mobil dokunma hedefi ≥44px ·
// yükleme / durum / mail onay / alan düzenleme pencereleri: alt çubuk ekranda, gövde kayar, son denetim tıklanabilir ·
// yükleme ön denetimi (15 MB üstü dosya engellenir) · PKL satır ızgarası KENDİ içinde kayar, sayfa kaymaz ·
// yapışkan PKL çubuğu alt çubuğun üstünde · koyu tema yüzeyleri (--card) · Esc penceri kapatır · konsol hatası yok.
// Playwright yoksa kendini ATLAR (hata değil). Ekran görüntüleri: <OUT>/screens/.
// =========================================================
'use strict';
const fs = require('fs'), os = require('os'), path = require('path');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const OUT = process.env.SIPARIS_UI_OUT || path.join(os.tmpdir(), 'sip-ui-test');
if (!fs.existsSync(path.join(OUT, 'manifest.json'))) { console.error('Önce: SIPARIS_UI_OUT=' + OUT + ' php scripts/siparis_ui_render.php'); process.exit(1); }
const SHOTS = path.join(OUT, 'screens'); fs.mkdirSync(SHOTS, { recursive: true });
const EKRANLAR = [[1280, 900], [820, 1024], [390, 844]];
let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) pass++; else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    async function ac(sayfa, w, h, koyu) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', colorScheme: koyu ? 'dark' : 'light' });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        if (koyu) await page.addInitScript(() => { try { localStorage.setItem('asya_tema', 'dark'); } catch (e) {} });
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        if (koyu) await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        await page.waitForTimeout(400);
        return { ctx, page, hatalar };
    }
    const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const gorunur = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
    const rect = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, r: r.right, b: r.bottom }; }, sel);

    /** Pencereyi açar; alt çubuk ekranda, gövde kayar, son denetim tıklanabilir. */
    async function pencereDene(page, ad, tetik, modalSel, h) {
        await page.click(tetik);
        await page.waitForTimeout(400);
        const m = await page.evaluate(({ sel, vh }) => {
            const o = document.querySelector(sel); const d = o.querySelector('.pm-dialog'), b = o.querySelector('.pm-body'), f = o.querySelector('.pm-footer');
            if (o.hidden) return { acik: false };
            const fr = f.getBoundingClientRect(), dr = d.getBoundingClientRect(), cs = getComputedStyle(b);
            const son = Array.from(f.querySelectorAll('button:not([hidden]), a')).filter(x => !x.disabled && x.offsetParent !== null).pop();
            const sr = son ? son.getBoundingClientRect() : null;
            const hit = sr ? document.elementFromPoint(sr.x + sr.width / 2, sr.y + sr.height / 2) : null;
            return { acik: true, footerAltta: fr.bottom <= vh + 1, footerUstte: fr.top >= 0, dialogSigiyor: dr.bottom <= vh + 1 && dr.top >= -1,
                govdeKayar: cs.overflowY === 'auto' || cs.overflowY === 'scroll', tiklanir: !!(hit && (hit === son || son.contains(hit))), z: +getComputedStyle(o).zIndex };
        }, { sel: modalSel, vh: h });
        ok(`${ad}: pencere açıldı`, m.acik);
        if (!m.acik) return;
        ok(`${ad}: alt çubuk ekran içinde`, m.footerAltta && m.footerUstte && m.dialogSigiyor, JSON.stringify(m));
        ok(`${ad}: gövde kaydırılabilir (overflow-y)`, m.govdeKayar);
        ok(`${ad}: son denetim tıklanabilir`, m.tiklanir);
        ok(`${ad}: z-index ≥ 600`, m.z >= 600, String(m.z));
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        ok(`${ad}: Esc kapatır`, await page.evaluate(sel => document.querySelector(sel).hidden, modalSel));
    }

    for (const [w, h] of EKRANLAR) {
        const mobil = w < 768, masaustu = w >= 1024;
        // ── liste ──
        let { ctx, page, hatalar } = await ac('liste', w, h);
        ok(`[${w}] liste: yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
        ok(`[${w}] liste: ${masaustu ? 'tablo görünür, kartlar gizli' : 'kartlar görünür, tablo gizli'}`,
            masaustu ? (await gorunur(page, '.table-wrap.pc-only')) && !(await gorunur(page, '.sip-kartlar')) : !(await gorunur(page, '.table-wrap.pc-only')) && (await gorunur(page, '.sip-kartlar')));
        ok(`[${w}] liste: KPI çipleri kendi şeridinde kayar (sayfa taşmaz)`, await page.evaluate(() => { const k = document.querySelector('.sip-kpi-kap'); return k.scrollWidth >= k.clientWidth && getComputedStyle(k).overflowX === 'auto'; }));
        if (mobil) {
            const kucuk = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-mod .btn, .sip-chip, .sip-card-kebab .pc-kebab')).filter(e => e.offsetParent !== null).map(e => ({ t: (e.textContent || '').trim().slice(0, 20), h: e.getBoundingClientRect().height })).filter(x => x.h < 43.5));
            ok(`[${w}] liste: dokunma hedefleri ≥ 44px`, kucuk.length === 0, JSON.stringify(kucuk.slice(0, 5)));
            const fs16 = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-mod input:not([type=hidden]):not([type=file]):not([type=checkbox]), .sip-mod select')).filter(e => e.offsetParent !== null).every(e => parseFloat(getComputedStyle(e).fontSize) >= 16));
            ok(`[${w}] liste: mobil girdiler 16px`, fs16);
            ok(`[${w}] liste: filtre paneli kapalı → "Filtre" ile açılır`, !(await gorunur(page, '#sipFiltrePanel')) && (await (async () => { await page.click('[data-sip-filtre-toggle]'); await page.waitForTimeout(100); return gorunur(page, '#sipFiltrePanel'); })()));
            const kart = await page.evaluate(() => { const a = document.querySelector('.sip-card-link'); const r = document.querySelector('.sip-card').getBoundingClientRect(); const l = a.getBoundingClientRect(); return { kartH: r.height }; });
            ok(`[${w}] liste: kart yüksekliği makul (ürün alt kartı dahil ≤ 300px; v376)`, kart.kartH <= 300, String(kart.kartH));
        }
        // v372: navlun no + ürün / Pal · PF + parti no (görünür, taşmadan)
        const v372 = await page.evaluate(() => {
            const gor = e => { const r = e.getBoundingClientRect(), cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
            const kaplar = Array.from(document.querySelectorAll('.table-wrap.pc-only, .sip-kartlar')).filter(gor);   // açık + kapanan bölüm
            const gors = sel => kaplar.flatMap(k => Array.from(k.querySelectorAll(sel))).filter(gor);
            const nv = gors('.sip-navlun'), ur = gors('.sip-urun'), pt = gors('.sip-turparti');
            const sag = gors('.sip-navlun, .sip-urun, .sip-urun-fazla').map(e => e.getBoundingClientRect().right);
            const wrap = kaplar.find(k => k.classList.contains('table-wrap'));
            return { nv: nv.length, nvMetin: nv.some(e => /^Navlun \d{6,}/.test(e.textContent.trim())), ur: ur.length, meta: gors('.sip-urun-meta').some(e => /Pal\. \d+ · PF \d+/.test(e.textContent)),
                parti: pt.some(e => /Parti [A-Z0-9]/.test(e.textContent)), fazla: gors('.sip-urun-fazla').some(e => /\+\d+ daha/.test(e.textContent)),
                navlunSoluk: nv.length > 0 && getComputedStyle(nv[0]).fontWeight === '300' && parseFloat(getComputedStyle(nv[0]).fontSize) < 14,
                sigar: sag.every(x => x <= window.innerWidth + 1), tabloKayiyor: wrap ? Math.max(...kaplar.filter(k => k.classList.contains('table-wrap')).map(k => k.scrollWidth - k.clientWidth)) : 0 };
        });
        ok(`[${w}] liste: navlun no görünür ve ince/küçük`, v372.nv >= 2 && v372.nvMetin && v372.navlunSoluk, JSON.stringify(v372));
        ok(`[${w}] liste: ürün satırları + "Pal. N · PF N" + "+N daha" görünür`, v372.ur >= 5 && v372.meta && v372.fazla, JSON.stringify(v372));
        ok(`[${w}] liste: bağlı beyanın parti no'su görünür`, v372.parti, JSON.stringify(v372));
        ok(`[${w}] liste: yeni hücreler ekrana sığar`, v372.sigar);
        if (w === 1280) ok(`[${w}] liste: tablo kendi içinde yatay kaymıyor (yeni alt satır sütun eklemez)`, v372.tabloKayiyor <= 1, String(v372.tabloKayiyor));
        // yükleme ön denetimi
        const dosyaGirdi = await page.$('form[data-sip-upload] input[type=file]');
        await dosyaGirdi.setInputFiles([{ name: 'buyuk.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(16 * 1024 * 1024, 1) }]);
        await page.waitForTimeout(100);
        ok(`[${w}] yükleme: 15 MB üstü dosya anında uyarılır`, await page.evaluate(() => { const e = document.querySelector('[data-sip-yukle-hata]'); return !e.hidden && /çok/i.test(e.textContent) && !!document.querySelector('.sip-dosya-hatali'); }));
        const urlOnce = page.url();
        await page.click('form[data-sip-upload] [data-sip-gonder]');
        await page.waitForTimeout(150);
        ok(`[${w}] yükleme: ön denetim hatasında form GÖNDERİLMEZ`, page.url() === urlOnce);
        await dosyaGirdi.setInputFiles(Array.from({ length: 11 }, (_, i) => ({ name: `k${i}.pdf`, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4') })));
        await page.waitForTimeout(100);
        ok(`[${w}] yükleme: 11 dosya engellenir`, await page.evaluate(() => /çok 10/i.test(document.querySelector('[data-sip-yukle-hata]').textContent)));
        ok(`[${w}] liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await page.screenshot({ path: path.join(SHOTS, `${w}_liste.png`), fullPage: true });
        await ctx.close();

        // ── liste (izleyici) ──
        ({ ctx, page } = await ac('liste_viewer', w, h));
        ok(`[${w}] izleyici listesi: yükleme formu yok, pasif düğmenin nedeni görünür`, !(await gorunur(page, 'form[data-sip-upload]')) && await page.evaluate(() => /Salt okunur/.test(document.querySelector('#sipSaltOkunur').textContent)));
        await ctx.close();

        // ── detay ──
        ({ ctx, page, hatalar } = await ac('detay', w, h));
        ok(`[${w}] detay: yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
        ok(`[${w}] detay: ${mobil ? 'kompakt adım satırı' : 'adım şeridi'} görünür`, mobil ? (await gorunur(page, '.sip-adim-mobil')) && !(await gorunur(page, '.sip-steps')) : (await gorunur(page, '.sip-steps')) && !(await gorunur(page, '.sip-adim-mobil')));
        if (w >= 1280) {
            const ana = await rect(page, '.sip-col-ana'), yan = await rect(page, '.sip-col-yan');
            ok(`[${w}] detay: iki sütun (ana solda, yan sağda)`, ana && yan && ana.r <= yan.x + 1 && ana.w > yan.w, JSON.stringify([ana, yan]));
        } else {
            const ana = await rect(page, '.sip-col-ana > section'), yan = await rect(page, '#catismalar');
            ok(`[${w}] detay: tek sütun (çatışma panelleri alt alta)`, yan && ana && yan.x <= ana.x + 2 && yan.w >= ana.w * 0.9);
        }
        if (mobil) {
            const sira = await page.evaluate(() => ['durum', 'uyarilar', 'catismalar', 'bilgi', 'kalemler', 'belgeler', 'pkl', 'beyan', 'gecmis'].map(id => { const e = document.getElementById(id); return e ? e.getBoundingClientRect().top + scrollY : -1; }).filter(y => y >= 0));
            ok(`[${w}] detay: mobil panel sırası (durum → uyarı → çatışma → bilgi → kalem → belge → PKL → beyan → geçmiş)`, sira.every((y, i) => i === 0 || y >= sira[i - 1]), JSON.stringify(sira));
            const kucuk = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-eylemler .btn, .sip-hizli .btn, .sip-edit, .sip-belge-eylem .btn, .sip-cat-eylem .btn')).filter(e => e.offsetParent !== null).map(e => ({ t: (e.textContent || '').trim().slice(0, 16), h: Math.round(e.getBoundingClientRect().height), w: Math.round(e.getBoundingClientRect().width) })).filter(x => x.h < 43.5 || x.w < 43.5));
            ok(`[${w}] detay: eylem düğmeleri ≥ 44px`, kucuk.length === 0, JSON.stringify(kucuk.slice(0, 5)));
        }
        ok(`[${w}] detay: kalem tablosu/kartları görünür (${masaustu ? 'tablo' : 'kart'})`, masaustu ? await gorunur(page, '.sip-kalem-tablo') : await gorunur(page, '.sip-kalem-kart'));
        ok(`[${w}] detay: ZIP "Seçilenleri" pasif → belge seçince etkin, adres belge[] içerir`, await (async () => {
            const once = await page.$eval('[data-sip-zip-secili]', e => e.disabled);
            await page.check('input[data-sip-zip] >> nth=0');
            const sonra = await page.$eval('[data-sip-zip-secili]', e => ({ d: e.disabled, h: e.getAttribute('data-href') }));
            await page.uncheck('input[data-sip-zip] >> nth=0');
            return once && !sonra.d && /belge\[\]=\d+/.test(sonra.h);
        })());
        // düzenleme penceresi (plaka: kilitli alan)
        await page.click('.sip-dl-satir[data-alan="plakalar"] [data-sip-alan]');
        await page.waitForTimeout(400);
        const alan = await page.evaluate(() => { const m = document.getElementById('sipAlanModal'); const f = m.querySelector('form'); return { acik: !m.hidden, alan: f.elements['alan'].value, deger: m.querySelector('[data-sip-girdi=metin]').value, tarihGizli: m.querySelector('[data-sip-girdi=tarih]').hidden, kilitBtn: !m.querySelector('[data-sip-kilit-btn]').hidden, odak: document.activeElement && document.activeElement.name }; });
        ok(`[${w}] alan düzenleme: plaka alanı doğru dolu, kilitli → "Kilidi kaldır" görünür`, alan.acik && alan.alan === 'plakalar' && /34TST777/.test(alan.deger) && alan.tarihGizli && alan.kilitBtn, JSON.stringify(alan));
        await page.keyboard.press('Escape');
        await page.click('.sip-dl-satir[data-alan="yukleme_tarihi"] [data-sip-alan]');
        await page.waitForTimeout(300);
        ok(`[${w}] alan düzenleme: tarih alanı date girdisi, kilit düğmesi gizli`, await page.evaluate(() => { const m = document.getElementById('sipAlanModal'); return !m.querySelector('[data-sip-girdi=tarih]').hidden && m.querySelector('[data-sip-girdi=metin]').hidden && m.querySelector('[data-sip-kilit-btn]').hidden && m.querySelector('[data-sip-girdi=tarih]').value === '2026-12-03'; }));
        await page.keyboard.press('Escape');
        // pencereler
        await pencereDene(page, `[${w}] yükleme penceresi`, '.sip-eylemler [data-sip-ac=sipYukleModal]', '#sipYukleModal', h);
        await pencereDene(page, `[${w}] durum penceresi`, '.sip-hizli [data-sip-ac=sipDurumModal]', '#sipDurumModal', h);
        await pencereDene(page, `[${w}] mail penceresi`, '.sip-hizli [data-sip-ac=sipMailModal]', '#sipMailModal', h);
        await page.click('.sip-hizli [data-sip-ac=sipMailModal]'); await page.waitForTimeout(300);
        const mail1 = await page.$eval('[data-sip-mail-gonder]', e => e.disabled);
        for (const c of await page.$$('[data-sip-mail-onay]')) await c.check();
        const mail2 = await page.$eval('[data-sip-mail-gonder]', e => e.disabled);
        ok(`[${w}] mail penceresi: iki onay kutusu işaretlenene kadar düğme PASİF`, mail1 === true && mail2 === false);
        await page.keyboard.press('Escape');
        await page.click('.sip-hizli [data-sip-ac=sipDurumModal]'); await page.waitForTimeout(250);
        await page.check('#sipDurumModal input[name=hedef][value=iptal]');
        ok(`[${w}] durum penceresi: iptal seçilince gerekçe zorunlu görünür`, await page.evaluate(() => { const k = document.querySelector('[data-sip-gerekce-kap]'); return !k.hidden && document.querySelector('[data-sip-gerekce]').required; }));
        await page.keyboard.press('Escape');
        if (masaustu) {
            await page.click('.sip-belge-eylem [data-sip-onizle] >> nth=0');
            await page.waitForTimeout(300);
            ok(`[${w}] önizleme penceresi masaüstünde açılır (başlık dolu)`, await page.evaluate(() => !document.getElementById('sipOnizleModal').hidden && document.getElementById('sipOnizleBaslik').textContent.length > 2));
            await page.keyboard.press('Escape');
        } else {
            ok(`[${w}] önizle: dar ekranda belge sayfasına bağlantı (pencere değil)`, await page.evaluate(() => { const a = document.querySelector('a[data-sip-onizle]'); return /siparis_belge\.php\?id=\d+/.test(a.getAttribute('href')); }));
        }
        ok(`[${w}] detay: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await page.screenshot({ path: path.join(SHOTS, `${w}_detay.png`), fullPage: true });
        await ctx.close();

        // ── detay izleyici: yazma kontrolleri yok ──
        ({ ctx, page } = await ac('detay_viewer', w, h));
        ok(`[${w}] izleyici detay: düzenleme/yükleme/durum kontrolleri yok`, await page.evaluate(() => !document.querySelector('[data-sip-alan]') && !document.querySelector('[data-sip-ac=sipYukleModal]') && !document.querySelector('.sip-hizli') && !document.querySelector('form[action="siparis_kaydet.php"]')));
        ok(`[${w}] izleyici detay: yatay taşma yok`, (await tasma(page)) <= 0);
        await ctx.close();

        // ── PKL editörü ──
        ({ ctx, page, hatalar } = await ac('pkl', w, h));
        ok(`[${w}] PKL: yatay SAYFA taşması yok`, (await tasma(page)) <= 0, String(await tasma(page)));
        await page.click('.sip-satir-detay summary');
        await page.waitForTimeout(300);
        const ized = await page.evaluate(() => { const g = document.querySelector('.sip-satir-izgara'); return { kay: g.scrollWidth > g.clientWidth + 5, ov: getComputedStyle(g).overflowX, sayfa: document.documentElement.scrollWidth - innerWidth }; });
        ok(`[${w}] PKL: satır ızgarası KENDİ içinde yatay kayar, sayfa kaymaz`, ized.kay && (ized.ov === 'auto' || ized.ov === 'scroll') && ized.sayfa <= 0, JSON.stringify(ized));
        const stick = await page.evaluate(() => { const th = document.querySelector('.sip-satir-tablo .sip-sticky'); return getComputedStyle(th).position; });
        ok(`[${w}] PKL: ilk sütun (Palet ID) yapışkan`, stick === 'sticky');
        if (mobil) {
            const fs16 = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-pkl-form input:not([type=hidden]):not([type=checkbox]), .sip-pkl-form select')).filter(e => e.offsetParent !== null).every(e => parseFloat(getComputedStyle(e).fontSize) >= 16));
            ok(`[${w}] PKL: mobil girdiler 16px (iOS zoom yok)`, fs16);
            await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(100);
            const bar = await rect(page, '.sip-bar'), bn = await rect(page, '.bn-dock');
            ok(`[${w}] PKL: yapışkan çubuk sayfa başında da görünür ve alt çubuğun ÜSTÜNDE`, bar && bn && bar.b <= bn.y + 1 && bar.y >= 0, JSON.stringify([bar, bn]));
            for (let i = 0; i < 8; i++) {      // mobil emülasyonda kaydırma kademeli ilerler: sona varana dek tekrarla
                await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' })); await page.waitForTimeout(250);
                if (await page.evaluate(() => window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 1)) break;
            }
            const son = await page.evaluate(() => { const b = document.querySelector('.sip-bar').getBoundingClientRect(); const bn = document.querySelector('.bn-dock').getBoundingClientRect(); const o = document.querySelector('#onizle').getBoundingClientRect(); return { barAlt: b.bottom, bnUst: bn.top, onizleAlt: o.bottom, barUst: b.top }; });
            ok(`[${w}] PKL: en altta çubuk içeriği örtmüyor (önizleme kartı çubuğun üstünde)`, son.onizleAlt <= son.barUst + 1 && son.barAlt <= son.bnUst + 1, JSON.stringify(son));
            const kucuk = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-bar .btn')).map(e => e.getBoundingClientRect().height).filter(x => x < 43.5));
            ok(`[${w}] PKL: çubuk düğmeleri ≥ 44px`, kucuk.length === 0);
        } else {
            const pos = await page.evaluate(() => getComputedStyle(document.querySelector('.sip-bar')).position);
            ok(`[${w}] PKL: masaüstünde çubuk akışta (static)`, pos === 'static', pos);
        }
        // M → N otomatik
        await page.fill('#sipN', ''); await page.fill('#sipM', '800');
        ok(`[${w}] PKL: brüt 800 yazınca net 710 (brüt − 90)`, await page.$eval('#sipN', e => e.value) === '710');
        await page.fill('#sipN', '700'); await page.fill('#sipM', '900');
        ok(`[${w}] PKL: elle yazılmış net ezilmez`, await page.$eval('#sipN', e => e.value) === '700');
        ok(`[${w}] PKL: Σ palet sayacı (20 / etkin 20)`, await page.$eval('[data-sip-sayac-toplam]', e => e.textContent) === '20' && !(await page.$eval('[data-sip-sayac]', e => e.classList.contains('sip-sayac--hata'))));
        await page.fill('[data-sip-blok-palet]', '19');
        ok(`[${w}] PKL: palet toplamı farklıysa sayaç uyarır`, await page.$eval('[data-sip-sayac]', e => e.classList.contains('sip-sayac--hata')));
        await pencereDene(page, `[${w}] profil penceresi`, '[data-sip-ac=sipProfilModal]', '#sipProfilModal', h);
        ok(`[${w}] PKL: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await page.screenshot({ path: path.join(SHOTS, `${w}_pkl.png`), fullPage: true });
        await ctx.close();

        // ── PKL taslağı: Excel benzeri ızgara + Palet ID (v372) ──
        ({ ctx, page, hatalar } = await ac('pkl_taslak', w, h));
        {
            const T = `[${w}] PKL ızgara`;
            const yapistir = (sel, metin) => page.evaluate(({ sel, metin }) => { const el = document.querySelector(sel); el.focus(); const dt = new DataTransfer(); dt.setData('text/plain', metin); const ev = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }); el.dispatchEvent(ev); return ev.defaultPrevented; }, { sel, metin });
            const hv = (sut, i) => page.$eval(`tr[data-sip-satir="${i}"] [data-sip-sutun="${sut}"]`, e => e.value);
            const hsel = (sut, i) => `tr[data-sip-satir="${i}"] [data-sip-sutun="${sut}"]`;
            const durumMetin = () => page.$eval('[data-sip-yapistir-durum]', e => ({ t: e.textContent, c: e.className, gizli: e.hidden }));
            const toplamlar = () => page.evaluate(() => { const g = s => document.querySelector(s).textContent.replace(/\D/g, ''); return { b: +g('[data-sip-t-brut]'), n: +g('[data-sip-t-net]'), d: +g('[data-sip-t-dolu]') }; });
            const aktif = () => page.evaluate(() => { const e = document.activeElement; return { sut: e && e.getAttribute && e.getAttribute('data-sip-sutun'), satir: e && e.closest && e.closest('tr') ? e.closest('tr').getAttribute('data-sip-satir') : null, sel: e && e.value !== undefined ? [e.selectionStart, e.selectionEnd, e.value.length] : null }; });
            const N = await page.$$eval('[data-sip-izgara] tbody tr', l => l.length);
            const mM = Array(N).fill(800), mN = Array(N).fill(710);       // beklenen etkin değerler (ortak 800 / net 710)
            const beklenen = () => ({ b: mM.reduce((x, y) => x + y, 0), n: mN.reduce((x, y) => x + y, 0), d: N });
            await page.evaluate(() => { window.__sub = 0; document.getElementById('sipPklForm').addEventListener('submit', e => { window.__sub++; e.preventDefault(); }); });
            ok(`${T}: yatay SAYFA taşması yok (başlangıç)`, (await tasma(page)) <= 0);
            await page.click('.sip-satir-detay summary'); await page.waitForTimeout(300);
            ok(`${T}: araç çubuğu (yapıştır düğmesi + toplamlar) detay kapalıyken de görünür`, await page.evaluate(() => { const d = document.querySelector('.sip-satir-detay'); const b = document.querySelector('[data-sip-ac=sipYapistirModal]'); return d.open && b.offsetParent !== null && document.querySelector('[data-sip-toplam]').offsetParent !== null; }));
            // ortak değer → satır ipucu + toplamlar
            await page.fill('#sipM', '800');
            let t = await toplamlar();
            ok(`${T}: ortak Brüt 800 → ipucu (placeholder) 800, Σ Brüt/Net = ortak × satır sayısı, dolu N/N`, (await page.$eval(hsel('M', 7), e => e.placeholder)) === '800' && JSON.stringify(t) === JSON.stringify(beklenen()), JSON.stringify([t, beklenen()]));
            // tek sütun yapıştırma (CRLF, baştaki boşluk, binlik, sondaki boş satır) — M0'dan aşağı
            const onl = await yapistir(hsel('M', 0), '812\r\n 795\r\n1.234\r\n');
            ok(`${T}: tek sütun yapıştırma satırlara dağılır (812 / 795 / 1234), Net'e dokunmaz`, onl && (await hv('M', 0)) === '812' && (await hv('M', 1)) === '795' && (await hv('M', 2)) === '1234' && (await hv('M', 3)) === '' && (await hv('N', 0)) === '');
            mM[0] = 812; mM[1] = 795; mM[2] = 1234;
            t = await toplamlar();
            ok(`${T}: toplamlar canlı güncellenir (Σ Brüt, Σ Net değişmez)`, JSON.stringify(t) === JSON.stringify(beklenen()), JSON.stringify([t, beklenen()]));
            let d = await durumMetin();
            ok(`${T}: durum mesajı "3 satır dolduruldu" + kalan satırlara dokunulmadı uyarısı`, !d.gizli && /3 satır dolduruldu/.test(d.t) && new RegExp('kalan ' + (N - 3) + ' satıra dokunulmadı').test(d.t) && /uyari/.test(d.c), d.t);
            // iki sütun (Brüt | Net), sekmeli — M3'ten
            await yapistir(hsel('M', 3), '900\t810\r\n880\t790\r\n');
            ok(`${T}: iki sütun (Brüt\\tNet) yapıştırma Brüt ve Net'e dağılır`, (await hv('M', 3)) === '900' && (await hv('N', 3)) === '810' && (await hv('M', 4)) === '880' && (await hv('N', 4)) === '790');
            mM[3] = 900; mN[3] = 810; mM[4] = 880; mN[4] = 790;
            t = await toplamlar();
            ok(`${T}: iki sütun sonrası toplamlar`, JSON.stringify(t) === JSON.stringify(beklenen()), JSON.stringify([t, beklenen()]));
            // ondalık virgül korunur
            await yapistir(hsel('M', 5), '812,5');
            ok(`${T}: ondalık virgül korunur ("812,5"), tek hücre yapıştırma da doğrulanır`, (await hv('M', 5)) === '812,5');
            await yapistir(hsel('M', 5), '800');
            // reddedilen yapıştırmalar: hiçbir hücre değişmez, nedeni yazılır
            const once = [await hv('M', 6), await hv('M', 7)];
            await yapistir(hsel('M', 6), 'abc\n12');
            d = await durumMetin();
            ok(`${T}: sayısal olmayan hücre REDDEDİLİR (hiçbir hücre değişmez, satır + değer + neden yazılır)`, /hiçbir hücre değişmedi/.test(d.t) && /Satır 1/.test(d.t) && /abc/.test(d.t) && /sayı değil/.test(d.t) && /hata/.test(d.c) && (await hv('M', 6)) === once[0] && (await hv('M', 7)) === once[1], d.t);
            await yapistir(hsel('M', 6), '812.5');
            d = await durumMetin();
            ok(`${T}: belirsiz nokta ondalığı ("812.5" → 8125 olurdu) sessizce geçmez`, /nokta belirsiz/.test(d.t) && (await hv('M', 6)) === once[0], d.t);
            await yapistir(hsel('M', 6), '1\t2\t3');
            ok(`${T}: 3 sütun reddedilir`, /En çok 2 sütun/.test((await durumMetin()).t));
            await yapistir(hsel('N', 6), '1\t2');
            ok(`${T}: iki sütun Net hücresinden başlatılamaz`, /yalnız Brüt hücresinden/.test((await durumMetin()).t) && (await hv('N', 6)) === '');
            await yapistir(hsel('M', 6), '5\n-3');
            ok(`${T}: negatif değer reddedilir`, /negatif/.test((await durumMetin()).t));
            // çok satır: fazlalık yok sayılır ve söylenir
            await yapistir(hsel('M', 0), Array.from({ length: 30 }, (_, i) => String(700 + i)).join('\n'));
            d = await durumMetin();
            ok(`${T}: fazla satır yok sayılır ve SAYISI yazılır (30 yapıştırıldı, ${N} satır)`, (await hv('M', N - 1)) === String(700 + N - 1) && new RegExp((30 - N) + ' satır fazla').test(d.t) && /uyari/.test(d.c), d.t);
            for (let i = 0; i < N; i++) mM[i] = 700 + i;
            // klavye: Enter aşağı (forma gönderme YOK), Shift+Enter yukarı, ↑/↓, son satır
            await page.focus(hsel('M', 0));
            await page.keyboard.press('Enter');
            let a = await aktif();
            ok(`${T}: Enter bir alt satırın AYNI sütununa geçer, içeriği seçer`, a.sut === 'M' && a.satir === '1' && a.sel[0] === 0 && a.sel[1] === a.sel[2] && a.sel[2] > 0, JSON.stringify(a));
            await page.keyboard.press('Shift+Enter');
            a = await aktif();
            ok(`${T}: Shift+Enter bir üst satıra döner`, a.satir === '0' && a.sut === 'M');
            await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
            a = await aktif();
            ok(`${T}: ↓ ↓ iki satır aşağı`, a.satir === '2');
            await page.keyboard.press('ArrowUp');
            ok(`${T}: ↑ bir satır yukarı`, (await aktif()).satir === '1');
            await page.focus(hsel('N', 3));
            await page.keyboard.press('Enter');
            a = await aktif();
            ok(`${T}: Net sütununda da Enter aşağı (N, 4. satır)`, a.sut === 'N' && a.satir === '4');
            await page.focus(hsel('P', 0)); await page.keyboard.press('Enter');
            a = await aktif();
            ok(`${T}: diğer sütunlarda (Paketleme) da Enter aşağı`, a.sut === 'P' && a.satir === '1');
            await page.focus(hsel('M', N - 1)); await page.keyboard.press('Enter');
            a = await aktif();
            ok(`${T}: son satırda Enter odağı bırakır (klavye kapanır), satır atlamaz`, a.sut === null && (await page.evaluate(() => window.__sub)) === 0, JSON.stringify(a));
            ok(`${T}: Enter ızgarada formu GÖNDERMEZ (gönderim sayacı 0)`, (await page.evaluate(() => window.__sub)) === 0);
            ok(`${T}: kg alanlarında klavye ipuçları (inputmode=decimal, enterkeyhint=next; son satır done)`, await page.evaluate(() => { const i = document.querySelector('tr[data-sip-satir="0"] [data-sip-sutun="M"]'), s = document.querySelector('tr[data-sip-satir="' + (document.querySelectorAll('[data-sip-izgara] tbody tr').length - 1) + '"] [data-sip-sutun="N"]'); return i.inputMode === 'decimal' && i.getAttribute('enterkeyhint') === 'next' && s.getAttribute('enterkeyhint') === 'done'; }));
            // Palet ID: ilk satır düzenlenir, sonrakiler +1; boşluk / çakışma uyarısı canlı
            const uyariSay = () => page.evaluate(() => document.querySelectorAll('.sip-palet-uyari-satir').length);
            const uyariMetin = kod => page.evaluate(k => { const e = document.querySelector(`.sip-palet-uyari-satir[data-kod="${k}"]`); return e ? e.textContent : null; }, kod);
            ok(`${T}: Palet ID varsayılan = son + 1 (48), uyarı yok`, (await page.$eval('[data-sip-palet-bas]', e => e.value)) === '48' && (await uyariSay()) === 0);
            await page.fill('[data-sip-palet-bas]', '500');
            const no = await page.$$eval('[data-sip-palet-no]', l => l.map(e => e.textContent));
            ok(`${T}: ilk Palet ID 500 → sonraki satırlar 501…519 kendiliğinden`, no.length === N - 1 && no[0] === '501' && no[N - 2] === String(500 + N - 1), JSON.stringify(no.slice(0, 3)));
            ok(`${T}: başlıktaki palet aralığı da güncellenir`, await page.$$eval('[data-sip-palet-aralik-yazi]', (l, son) => l.length > 0 && l.every(e => e.textContent === '500–' + son), 500 + N - 1));
            ok(`${T}: boşluk uyarısı canlı ("son palet no'su 47; 48–499 arası 452 numara boşta kalıyor.")`, (await uyariMetin('palet_bosluk')) === "⚠ Önceki siparişin son palet no'su 47; 48–499 arası 452 numara boşta kalıyor.", String(await uyariMetin('palet_bosluk')));
            await page.press('[data-sip-palet-bas]', 'Enter');
            a = await aktif();
            ok(`${T}: Palet ID'de Enter ilk satırın Brüt hücresine geçer, form GÖNDERİLMEZ`, a.sut === 'M' && a.satir === '0' && (await page.evaluate(() => window.__sub)) === 0, JSON.stringify(a));
            await page.fill('[data-sip-palet-bas]', '30');
            const cak = await uyariMetin('palet_cakisma');
            ok(`${T}: çakışma uyarısı çakışan PKL'yi adlandırır (belge no + sipariş + aralık), daha güçlü stil`, !!cak && /AsyaDoc002 \(TC900001\.10\) 28–47/.test(cak) && /reddeder/.test(cak) && (await page.evaluate(() => !!document.querySelector('.sip-palet-uyari-satir--guclu'))), String(cak));
            await page.fill('[data-sip-palet-bas]', '20');
            ok(`${T}: iki PKL ile çakışınca ikisi de adlandırılır`, /AsyaDoc001/.test(await uyariMetin('palet_cakisma')) && /AsyaDoc002/.test(await uyariMetin('palet_cakisma')));
            await page.fill('[data-sip-palet-bas]', '48');
            ok(`${T}: öneriye (48) dönünce uyarılar kalkar`, (await uyariSay()) === 0);
            await page.fill('[data-sip-palet-bas]', '');
            await page.dispatchEvent('[data-sip-palet-bas]', 'change');
            ok(`${T}: boş bırakılıp çıkılırsa varsayılana (48) döner`, (await page.$eval('[data-sip-palet-bas]', e => e.value)) === '48');
            // yapıştırma penceresi
            await pencereDene(page, `${T} yapıştırma penceresi`, '[data-sip-ac=sipYapistirModal]', '#sipYapistirModal', h);
            await page.locator('[data-sip-ac=sipYapistirModal]').click(); await page.waitForTimeout(350);
            ok(`${T}: pencere açılışta boş, Uygula pasif, satır seçeneği = satır sayısı`, await page.evaluate(n => { const m = document.getElementById('sipYapistirModal'); return !m.hidden && m.querySelector('[data-sip-yap-metin]').value === '' && m.querySelector('[data-sip-yap-uygula]').disabled && m.querySelector('[data-sip-yap-satir]').options.length === n; }, N));
            await page.fill('[data-sip-yap-metin]', 'x\t1');
            const onz0 = await page.$eval('[data-sip-yap-onizleme]', e => e.textContent);
            ok(`${T}: pencere: hatalı metin Uygulanamaz + neden, Uygula pasif`, /Uygulanamaz/.test(onz0) && /sayı değil/.test(onz0) && await page.$eval('[data-sip-yap-uygula]', e => e.disabled), onz0);
            await page.fill('[data-sip-yap-metin]', '700\t620\n710\t630\n');
            await page.selectOption('[data-sip-yap-satir]', '3');
            const onz1 = await page.$eval('[data-sip-yap-onizleme]', e => e.textContent);
            ok(`${T}: pencere önizleme: "2 satır · Brüt | Net · ilk Brüt 700, Net 620" + az satır uyarısı`, /2 satır · Brüt \| Net · ilk Brüt 700, Net 620/.test(onz1) && new RegExp('kalan ' + (N - 5) + ' satıra dokunulmayacak').test(onz1) && !(await page.$eval('[data-sip-yap-uygula]', e => e.disabled)), onz1);
            await page.fill('[data-sip-yap-metin]', Array.from({ length: 30 }, () => '5').join('\n'));
            await page.selectOption('[data-sip-yap-satir]', '0');
            ok(`${T}: pencere önizleme: fazla satır uyarısı`, new RegExp('fazla ' + (30 - N) + ' satır yok sayılacak').test(await page.$eval('[data-sip-yap-onizleme]', e => e.textContent)));
            await page.fill('[data-sip-yap-metin]', '700\t620\n710\t630\n');
            await page.selectOption('[data-sip-yap-satir]', '3');
            await page.click('[data-sip-yap-uygula]'); await page.waitForTimeout(150);
            mM[3] = 700; mN[3] = 620; mM[4] = 710; mN[4] = 630;
            ok(`${T}: Uygula ızgarayı doldurur (4. ve 5. satır Brüt/Net), pencere kapanır, hiçbir şey gönderilmez`, (await hv('M', 3)) === '700' && (await hv('N', 3)) === '620' && (await hv('M', 4)) === '710' && (await hv('N', 4)) === '630'
                && (await page.evaluate(() => document.getElementById('sipYapistirModal').hidden)) && (await page.evaluate(() => window.__sub)) === 0);
            d = await durumMetin();
            ok(`${T}: Uygula sonrası durum mesajı (2 satır dolduruldu, kaydetmek için…)`, /2 satır dolduruldu \(Brüt \+ Net\)/.test(d.t) && /Taslağı kaydet/.test(d.t));
            t = await toplamlar();
            ok(`${T}: Uygula sonrası toplamlar canlı güncellendi`, JSON.stringify(t) === JSON.stringify({ b: beklenen().b, n: mN.reduce((x, y) => x + y, 0), d: N }), JSON.stringify([t, beklenen()]));
            await page.screenshot({ path: path.join(SHOTS, `${w}_pkl_taslak.png`), fullPage: true });
            if (process.env.SIPARIS_PKL_SHOTS) { fs.mkdirSync(process.env.SIPARIS_PKL_SHOTS, { recursive: true }); await page.screenshot({ path: path.join(process.env.SIPARIS_PKL_SHOTS, `${w}_pkl_taslak.png`), fullPage: true }); }
            ok(`${T}: yatay SAYFA taşması yok (tüm etkileşimlerden sonra)`, (await tasma(page)) <= 0, String(await tasma(page)));
            if (mobil) {
                const fs16 = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-pkl-form input:not([type=hidden]):not([type=checkbox]), .sip-pkl-form select, #sipYapistirModal textarea, #sipYapistirModal select')).filter(e => e.offsetParent !== null).every(e => parseFloat(getComputedStyle(e).fontSize) >= 16));
                ok(`${T}: mobil girdiler (yeni alanlar dahil) 16px`, fs16);
            }
            ok(`${T}: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        }
        await ctx.close();

        // ── belge sayfası ──
        ({ ctx, page, hatalar } = await ac('belge_pdf', w, h));
        ok(`[${w}] belge: yatay taşma yok`, (await tasma(page)) <= 0);
        ok(`[${w}] belge: PDF ${masaustu ? 'iframe' : '"PDF\'i aç" düğmesi'} görünür`, masaustu ? await gorunur(page, 'iframe.sip-pdf') : (await gorunur(page, '.sip-pdf-mobil .btn')) && !(await gorunur(page, 'iframe.sip-pdf')));
        await ctx.close();
        ({ ctx, page } = await ac('belge_xlsx', w, h));
        ok(`[${w}] belge xlsx: geniş tablo KENDİ kutusunda kayar, sayfa taşmaz`, (await tasma(page)) <= 0 && await page.evaluate(() => { const t = document.querySelector('.sip-onizle-kart .table-wrap'); return t.scrollWidth > t.clientWidth; }));
        await ctx.close();

        // ── ayarlar ──
        ({ ctx, page } = await ac('ayarlar', w, h));
        ok(`[${w}] ayarlar: yatay taşma yok`, (await tasma(page)) <= 0);
        await ctx.close();
    }

    // ── koyu tema (yalnız yüzeyler; tek ekran) ──
    for (const [sayfa, sel] of [['liste', '.sip-card'], ['detay', '.sip-mod .card']]) {
        const { ctx, page } = await ac(sayfa, 390, 844, true);
        const r = await page.evaluate(s => { const e = Array.from(document.querySelectorAll(s)).find(x => x.offsetParent !== null); const cs = getComputedStyle(e); const kok = getComputedStyle(document.documentElement).getPropertyValue('--card').trim();
            const tmp = document.createElement('div'); tmp.style.background = kok; document.body.appendChild(tmp); const bg = getComputedStyle(tmp).backgroundColor; tmp.remove();
            const rozet = document.querySelector('.sip-badge'); return { bg: cs.backgroundColor, beklenen: bg, rozetBg: rozet ? getComputedStyle(rozet).backgroundColor : '', rozetFg: rozet ? getComputedStyle(rozet).color : '' }; }, sel);
        ok(`koyu tema ${sayfa}: ${sel} arka planı --card değeri`, r.bg === r.beklenen, JSON.stringify(r));
        const lum = c => { const m = c.match(/\d+/g).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
        ok(`koyu tema ${sayfa}: durum rozeti koyu zeminde açık yazı`, !r.rozetBg || lum(r.rozetFg) > lum(r.rozetBg), JSON.stringify(r));
        await page.screenshot({ path: path.join(SHOTS, `390_${sayfa}_koyu.png`) });
        await ctx.close();
    }
    {   // koyu tema: PKL ızgara araç çubuğu, çakışma uyarısı, yapıştırma penceresi
        const { ctx, page } = await ac('pkl_taslak', 390, 844, true);
        await page.click('.sip-satir-detay summary'); await page.waitForTimeout(250);
        await page.fill('[data-sip-palet-bas]', '30');
        await page.locator('[data-sip-ac=sipYapistirModal]').click(); await page.waitForTimeout(350);
        await page.fill('[data-sip-yap-metin]', '700\t620\n');
        const lum = c => { const m = c.match(/\d+/g).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
        const r = await page.evaluate(() => { const cs = x => getComputedStyle(document.querySelector(x));
            return { uyBg: cs('.sip-palet-uyari-satir--guclu').backgroundColor, uyFg: cs('.sip-palet-uyari-satir--guclu').color, topBg: cs('.sip-toplam').backgroundColor, topFg: cs('.sip-toplam').color, taBg: cs('[data-sip-yap-metin]').backgroundColor, taFg: cs('[data-sip-yap-metin]').color, dlgBg: cs('#sipYapistirModal .pm-dialog').backgroundColor }; });
        ok('koyu tema PKL: çakışma uyarısı açık yazı / koyu zemin', lum(r.uyFg) > lum(r.uyBg) + 0.25, JSON.stringify(r));
        ok('koyu tema PKL: toplam hapı koyu zemin, açık yazı', lum(r.topBg) < 0.35 && lum(r.topFg) > lum(r.topBg) + 0.25, JSON.stringify(r));
        ok('koyu tema PKL: yapıştırma kutusu koyu zemin / açık yazı, pencere zemini koyu', lum(r.taBg) < 0.35 && lum(r.taFg) > lum(r.taBg) + 0.25 && lum(r.dlgBg) < 0.35, JSON.stringify(r));
        await page.screenshot({ path: path.join(SHOTS, '390_pkl_taslak_koyu.png') });
        if (process.env.SIPARIS_PKL_SHOTS) await page.screenshot({ path: path.join(process.env.SIPARIS_PKL_SHOTS, '390_pkl_taslak_koyu.png') });
        await ctx.close();
    }
    await browser.close();
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})();
