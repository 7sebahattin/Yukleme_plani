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
            ok(`[${w}] liste: kart yüksekliği makul (≤ 200px)`, kart.kartH <= 200, String(kart.kartH));
        }
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
    await browser.close();
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})();
