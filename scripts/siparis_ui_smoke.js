// =========================================================
// scripts/siparis_ui_smoke.js — Siparişler GERÇEK TARAYICI testi (Playwright + Chromium)
//   SIPARIS_UI_OUT=/tmp/sip-ui php scripts/siparis_ui_render.php
//   SIPARIS_UI_OUT=/tmp/sip-ui node scripts/siparis_ui_smoke.js
// Ekranlar 1280x900 · 820x1024 · 390x844 (ölçümden önce 400 ms beklenir: açılış animasyonu 220 ms).
// Ölçer: yatay sayfa taşması yok · .pc-only/.mobile-only 1024'te geçer · mobil dokunma hedefi ≥44px ·
// yükleme / durum / mail onay / alan düzenleme pencereleri: alt çubuk ekranda, gövde kayar, son denetim tıklanabilir ·
// yükleme ön denetimi (15 MB üstü dosya engellenir) · PKL satır ızgarası KENDİ içinde kayar, sayfa kaymaz ·
// yapışkan PKL çubuğu alt çubuğun üstünde · koyu tema yüzeyleri (--card) · Esc penceri kapatır · konsol hatası yok.
// v378 "📧 Mailden al": düğmeler yalnız kapıyla · ayarsızda pasif + not · 1280/820/390/360 yatay taşma yok, <768'de 3 sütun, <390'da alt alta ·
// page.route('**/siparis_mail.php') sahte yanıtlarla baslat → devam → bitti: kilit/ilerleme/sonuç, ağ kopunca "Devam et" (aynı tarama_id), 409/HTML yanıt ·
// sonuç tablo/kart + "Daha önce alınmış" aç/kapa · yarım tarama şeridi · ayar kartı · koyu tema.
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
    // file:// sayfadaki fetch'i page.route() ile yakalamak TAM Chromium (yeni headless) ister: Playwright'ın
    // varsayılanı chromium-headless-shell file:// fetch'ini route'a uğramadan reddeder ("URL scheme file is not supported").
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : { channel: 'chromium' })
        .catch(() => chromium.launch({}));
    async function ac(sayfa, w, h, koyu, hazirla, opts) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', colorScheme: koyu ? 'dark' : 'light' });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        if (koyu) await page.addInitScript(() => { try { localStorage.setItem('asya_tema', 'dark'); } catch (e) {} });
        // v420: "Belge yükle" kartı 5 sn sonra kendiliğinden kapanır → testlerde kapalı (0); otomatik kapanmayı sınayan senaryo süreyi opts.otoKapat ile verir
        await page.addInitScript(ms => { window.SIP_OTO_KAPAT_MS = ms; }, (opts && opts.otoKapat) || 0);
        if (hazirla) await hazirla(page);      // page.route() sayfa AÇILMADAN kurulmalı (file:// isteklerinde sonradan kurulan yönlendirme devreye girmez)
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        if (koyu) await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        // v420: kart + içindeki "Dosyadan yükle" bölümü varsayılan KAPALI → içini sınayan senaryolar ikisini de açar
        if (opts && opts.yukleAcik) await page.evaluate(() => { document.querySelectorAll('details[data-sip-yukle], details.sip-dosya-ac').forEach(d => { d.open = true; }); });
        await page.waitForTimeout(400);
        return { ctx, page, hatalar };
    }
    const acA = (sayfa, w, h, koyu, hazirla) => ac(sayfa, w, h, koyu, hazirla, { yukleAcik: true });   // v382: yükleme kartı açık
    const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const gorunur = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && (!e.checkVisibility || e.checkVisibility()); }, sel);   // checkVisibility: kapalı <details> içeriği (content-visibility) görünmez sayılır
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
            ok(`[${w}] liste: kart yüksekliği makul (ürün alt kartı + v396 PKL adı satırı dahil ≤ 340px; v376)`, kart.kartH <= 340, String(kart.kartH));   // ilk kart: PKL adı + 2 parti rozeti
        }
        // v384: Excel gibi dönüşümlü bant — ardışık sevkiyat grupları farklı zemin, grup içi (ana + ürün alt satırı) aynı
        const zb = await page.evaluate(() => {
            const gor = e => { const r = e.getBoundingClientRect(); return getComputedStyle(e).display !== 'none' && r.width > 0 && r.height > 0; };
            const bg = e => getComputedStyle(e).backgroundColor;
            const tb = document.querySelector('.table-wrap.pc-only'), kl = document.querySelector('.sip-kartlar');
            if (tb && gor(tb)) {
                const ana = Array.from(tb.querySelectorAll('tbody > tr.sip-grup:not(.sip-urun-tr)'));
                const r = ana.slice(0, 3).map(t => { const u = t.nextElementSibling && t.nextElementSibling.classList.contains('sip-urun-tr') ? t.nextElementSibling : null;
                    return { a: bg(t.cells[1]), u: u ? bg(u.cells[0]) : null }; });
                return { tur: 'tablo', farkli: r.length >= 2 && r[0].a !== r[1].a && (r.length < 3 || r[0].a === r[2].a), ayni: r.every(x => x.u === null || x.u === x.a), r };
            }
            const k = Array.from(kl.querySelectorAll('.sip-card')).filter(gor).slice(0, 3).map(bg);
            return { tur: 'kart', farkli: k.length >= 2 && k[0] !== k[1] && (k.length < 3 || k[0] === k[2]), ayni: true, r: k };
        });
        ok(`[${w}] liste: dönüşümlü iki renk bant (${zb.tur}; v384)`, zb.farkli && zb.ayni, JSON.stringify(zb));
        // v372: navlun no + ürün / Pal · PF + parti no (görünür, taşmadan)
        const v372 = await page.evaluate(() => {
            const gor = e => { const r = e.getBoundingClientRect(), cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
            const kaplar = Array.from(document.querySelectorAll('.table-wrap.pc-only, .sip-kartlar')).filter(gor);   // açık + kapanan bölüm
            const gors = sel => kaplar.flatMap(k => Array.from(k.querySelectorAll(sel))).filter(gor);
            const nv = gors('.sip-navlun'), ur = gors('.sip-urun'), pt = gors('.sip-turparti, .sip-parti-btn');   // v386: parti no ayrı 3B rozet
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
        // v396: güncel PKL dosya adı parti rozetinin ÜSTÜNDE, kendi hücresinde / kartında kalır (⋮ düğmesine binmez)
        const v396 = await page.evaluate(() => {
            const gor = e => e && e.offsetParent !== null && e.getBoundingClientRect().width > 0;
            const pk = Array.from(document.querySelectorAll('.sip-pkl-ad')).filter(gor);
            if (!pk.length) return { n: 0 };
            const a = pk[0], r = a.getBoundingClientRect(), kap = a.closest('td, .sip-card'), kr = kap.getBoundingClientRect();
            const pr = kap.querySelector('.sip-parti-rozetler'), keb = kap.querySelector('.sip-card-kebab .pc-kebab');
            return { n: pk.length, metin: /^PKL-.*\.xlsx$/.test(a.textContent.replace(/[\u{1F4C4}\s]/gu, '')), icinde: r.left >= kr.left - 1 && r.right <= kr.right + 1,
                ustte: !pr || r.top <= pr.getBoundingClientRect().top + 1, kebab: !keb || r.right <= keb.getBoundingClientRect().left + 1 || r.top >= keb.getBoundingClientRect().bottom - 1,
                href: /siparis_dosya\.php\?id=\d+&dl=1$/.test(a.getAttribute('href') || '') };
        });
        ok(`[${w}] liste: PKL dosya adı görünür, parti no'nun üstünde, hücre/kart içinde (v396)`, v396.n >= 1 && v396.metin && v396.icinde && v396.ustte && v396.kebab && v396.href, JSON.stringify(v396));
        // v382: "Belge yükle" kartı native <details>: varsayılan KAPALI, summary ile açılır/kapanır, tercih localStorage'da
        const YK = 'details[data-sip-yukle]', YS = 'details[data-sip-yukle] > summary.sip-yukle-ozet';
        ok(`[${w}] yükleme kartı: varsayılan kapalı (summary görünür, form ve açık özniteliği yok)`, (await gorunur(page, YS)) && !(await gorunur(page, 'form[data-sip-upload]')) && await page.evaluate(s => !document.querySelector(s).open, YK));
        const ys = await rect(page, YS);
        ok(`[${w}] yükleme kartı: summary dokunma hedefi ≥ 44px, tam genişlik, taşma yok`, ys && ys.h >= 43.5 && (await tasma(page)) <= 0, JSON.stringify(ys));
        await page.click(YS); await page.waitForTimeout(150);
        ok(`[${w}] yükleme kartı: summary tıkı açar (mail sorgu alanı görünür, manuel yükleme alanı hâlâ KAPALI)`, await page.evaluate(s => document.querySelector(s).open, YK) && !(await gorunur(page, 'form[data-sip-upload]')) && (await gorunur(page, 'summary.sip-dosya-ozet')));
        ok(`[${w}] yükleme kartı: açıkken yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
        await page.click('summary.sip-dosya-ozet'); await page.waitForTimeout(150);
        ok(`[${w}] yükleme kartı: "Dosyadan yükle" tıklayınca açılır (form + dosya alanı görünür)`, (await gorunur(page, 'form[data-sip-upload]')) && (await gorunur(page, '[data-sip-drop]')));
        await page.click('summary.sip-dosya-ozet'); await page.waitForTimeout(150);
        ok(`[${w}] yükleme kartı: "Dosyadan yükle" ikinci tıkta kapanır`, !(await gorunur(page, 'form[data-sip-upload]')));
        await page.reload(); await page.waitForTimeout(300);
        ok(`[${w}] yükleme kartı: yeniden yüklemede KAPALI başlar (tercih hatırlanmaz)`, await page.evaluate(s => !document.querySelector(s).open, YK));
        await page.click(YS); await page.waitForTimeout(150);
        await page.click(YS); await page.waitForTimeout(150);
        ok(`[${w}] yükleme kartı: ikinci tık kapatır`, await page.evaluate(s => !document.querySelector(s).open, YK));
        await page.goto(page.url().split('#')[0] + '#yukle'); await page.waitForTimeout(300);
        ok(`[${w}] yükleme kartı: #yukle bağlantısı kartı açar`, await page.evaluate(s => document.querySelector(s).open, YK));
        await page.click('summary.sip-dosya-ozet'); await page.waitForTimeout(150);
        ok(`[${w}] yükleme kartı: ok simgesi + iç içe seçici güvenliği (summary yalnız bir tane doğrudan çocuk)`, await page.evaluate(s => document.querySelectorAll(s).length === 1, YS));
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

    // ═══════════════════ v378: Mailden al ═══════════════════
    const MANIFEST = JSON.parse(fs.readFileSync(path.join(OUT, 'manifest.json'), 'utf8'));
    const varSayfa = ad => MANIFEST.sayfalar.includes(ad);
    const bekle = ms => new Promise(r => setTimeout(r, ms));
    const lumOf = c => { const m = c.match(/\d+(\.\d+)?/g).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
    const JSONY = (obj, status) => ({ status: status || 200, contentType: 'application/json', body: JSON.stringify(obj) });
    const SAYAC0 = { mail: 2, pdf: 2, alindi: 0, zaten: 0, onceden: 0, atlandi: 0, hata: 0 };
    const SONUC_HTML = '<section class="card sip-mail-sonuc-kart" data-sip-mail-sonuc-kart><div class="card-head"><h2>Mail sorgusu — Lojistik · sahte</h2></div><p id="sahteSonuc">tamam</p></section>';
    if (varSayfa('mail_ayarsiz')) {
        // ── ayarsız / yetkisiz ──
        for (const [w, h] of [[1280, 900], [390, 844]]) {
            let { ctx, page, hatalar } = await ac('mail_ayarsiz', w, h);
            const d = await page.evaluate(() => ({ n: document.querySelectorAll('[data-sip-mail]').length, pasif: Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => b.disabled),
                not: (document.querySelector('.sip-mail-not') || {}).textContent || '', link: !!document.querySelector('.sip-mail-not a[href="siparis_ayarlar.php#mail"]') }));
            ok(`[${w}] mail ayarsız: 2 düğme, hepsi pasif`, d.n === 2 && d.pasif, JSON.stringify(d));
            ok(`[${w}] mail ayarsız (yönetici): ayar bağlantısı görünür`, d.link && /Mail sorgu ayarları/.test(d.not), d.not);
            ok(`[${w}] mail ayarsız: yatay taşma yok`, (await tasma(page)) <= 0);
            ok(`[${w}] mail ayarsız: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            await ctx.close();
        }
        let { ctx, page } = await ac('mail_ayarsiz_operator', 390, 844);
        ok('mail ayarsız (yönetici olmayan): bağlantı YOK, "henüz ayarlanmadı (yönetici)"', await page.evaluate(() => { const n = document.querySelector('.sip-mail-not'); return !!n && !n.querySelector('a') && /henüz ayarlanmadı \(yönetici\)/.test(n.textContent); }));
        await ctx.close();
        ({ ctx, page } = await ac('mail_yetkisiz', 1280, 900, false, null, { yukleAcik: true }));
        ok('mail.read yetkisi yoksa "Mailden al" bölümü ve düğmeler HİÇ çizilmez', await page.evaluate(() => !document.querySelector('[data-sip-mail-kap]') && !document.querySelector('[data-sip-mail]') && !document.querySelector('#mail')) && await gorunur(page, 'form[data-sip-upload]'));
        await ctx.close();
        ({ ctx, page } = await ac('mail_ayar_yetkisiz', 1280, 900));
        ok('ayar yetkisi (mail.admin) yoksa "Mail sorgu ayarları" kartı çizilmez', await page.evaluate(() => !document.querySelector('#sipMailAyarBas')));
        await ctx.close();
    }
    if (varSayfa('mail_ayarli')) {
        // ── düzen: 1280/820/390/360 ──
        for (const [w, h] of [[1280, 900], [820, 1024], [390, 844], [360, 740]]) {
            const { ctx, page, hatalar } = await ac('mail_ayarli', w, h, false, null, { yukleAcik: true });
            ok(`[${w}] mail: yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
            const r = await page.evaluate(() => {
                const bs = Array.from(document.querySelectorAll('[data-sip-mail]')), kap = document.querySelector('.sip-mail-dugmeler');
                const rc = bs.map(b => b.getBoundingClientRect()), kr = kap.getBoundingClientRect(), cs = getComputedStyle(kap);
                return { n: bs.length, hepsiAktif: bs.every(b => !b.disabled), tops: rc.map(x => Math.round(x.top)), w: rc.map(x => Math.round(x.width)), h: rc.map(x => x.height), kapW: kr.width,
                    sutun: cs.display === 'grid' ? cs.gridTemplateColumns.split(' ').length : 0, tasar: rc.some(x => x.right > kr.right + 1 || x.left < kr.left - 1) };
            });
            ok(`[${w}] mail: iki düğme etkin ve kapsayıcıdan taşmıyor`, r.n === 2 && r.hepsiAktif && !r.tasar, JSON.stringify(r));
            if (w < 390) ok(`[${w}] mail: <390px düğmeler alt alta, tam genişlik`, r.sutun === 1 && r.tops[0] < r.tops[1] && r.w.every(x => x >= r.kapW - 2), JSON.stringify(r));
            else if (w < 768) ok(`[${w}] mail: <768px 2 eşit sütun, aynı satır`, r.sutun === 2 && r.tops[0] === r.tops[1] && Math.max(...r.w) - Math.min(...r.w) <= 2, JSON.stringify(r));
            else ok(`[${w}] mail: masaüstü/tablet düğmeler aynı satırda`, r.tops[0] === r.tops[1], JSON.stringify(r));
            if (w < 768) ok(`[${w}] mail: dokunma hedefleri ≥ 44px`, r.h.every(x => x >= 43.5), JSON.stringify(r.h));
            ok(`[${w}] mail: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            if (w === 390) await page.screenshot({ path: path.join(SHOTS, '390_mail_ayarli.png'), fullPage: true });
            await ctx.close();
        }
        // ── akış: baslat → devam → bitti (sahte uç) ──
        for (const [w, h] of [[1280, 900], [390, 844]]) {
            const istekler = []; let devamNo = 0;
            const { ctx, page, hatalar } = await acA('mail_ayarli', w, h, false, p => p.route('**/siparis_mail.php', async route => {
                const g = JSON.parse(route.request().postData() || '{}'); istekler.push(g);
                if (g.islem === 'baslat') return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: g.tur, i: 0, toplam: 2, sayac: SAYAC0, uyarilar: [], bitti: false }));
                devamNo++; await bekle(450);
                if (devamNo === 1) return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: 'lojistik', i: 1, toplam: 2, sayac: { ...SAYAC0, alindi: 1 }, uyarilar: [], bitti: false }));
                return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: 'lojistik', i: 2, toplam: 2, sayac: { ...SAYAC0, alindi: 1, zaten: 1 }, uyarilar: [], bitti: true, html: SONUC_HTML }));
            }));
            await page.click('[data-sip-mail="lojistik"]');
            await page.waitForFunction(() => /1 \/ 2 ek/.test((document.querySelector('[data-sip-mail-ilerleme-metin]') || {}).textContent || ''), null, { timeout: 5000 });
            const ara = await page.evaluate(() => ({ hepsiKilitli: Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => b.disabled), busy: document.querySelector('[data-sip-mail-kap]').getAttribute('aria-busy'),
                ilerleme: !document.querySelector('[data-sip-mail-ilerleme]').hidden, metin: document.querySelector('[data-sip-mail-ilerleme-metin]').textContent, bar: document.querySelector('[data-sip-mail-ilerleme] progress').value }));
            ok(`[${w}] mail akış: çalışırken TÜM düğmeler kilitli + aria-busy`, ara.hepsiKilitli && ara.busy === 'true', JSON.stringify(ara));
            ok(`[${w}] mail akış: ilerleme metni güncellenir ("Mailler taranıyor… 1 / 2 ek · 1 alındı")`, ara.ilerleme && /Mailler taranıyor… 1 \/ 2 ek · 1 alındı · 0 zaten vardı/.test(ara.metin) && ara.bar === 1, JSON.stringify(ara));
            await page.waitForSelector('#sahteSonuc', { timeout: 5000 });
            const son = await page.evaluate(() => ({ hepsiAcik: Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => !b.disabled), busy: document.querySelector('[data-sip-mail-kap]').getAttribute('aria-busy'),
                ilerlemeGizli: document.querySelector('[data-sip-mail-ilerleme]').hidden, sonuc: document.querySelector('[data-sip-mail-sonuc]').textContent }));
            ok(`[${w}] mail akış: sonuç HTML'i basıldı, düğmeler açıldı, ilerleme gizlendi`, son.hepsiAcik && son.busy === 'false' && son.ilerlemeGizli && /tamam/.test(son.sonuc), JSON.stringify(son));
            ok(`[${w}] mail akış: istekler baslat(tur+csrf) → devam(tarama_id) → devam`, istekler.length === 3 && istekler[0].islem === 'baslat' && istekler[0].tur === 'lojistik' && istekler[0].csrf && istekler[0].csrf.length > 8
                && istekler[1].islem === 'devam' && istekler[1].tarama_id === 'abcdef0123456789' && istekler[2].tarama_id === 'abcdef0123456789', JSON.stringify(istekler));
            ok(`[${w}] mail akış: yatay taşma yok`, (await tasma(page)) <= 0);
            ok(`[${w}] mail akış: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            await ctx.close();
        }
        // ── v382: tarama sürerken summary tıkı kartı KAPATMAZ ──
        {
            const { ctx, page, hatalar } = await acA('mail_ayarli', 390, 844, false, p => p.route('**/siparis_mail.php', async route => {
                const g = JSON.parse(route.request().postData() || '{}');
                if (g.islem === 'baslat') { await bekle(2000); return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: g.tur, i: 2, toplam: 2, sayac: SAYAC0, uyarilar: [], bitti: true, html: SONUC_HTML })); }
                return route.fulfill(JSONY({ ok: false, mesaj: 'beklenmeyen' }, 400));
            }));
            await page.click('[data-sip-mail="lojistik"]'); await page.waitForTimeout(250);
            await page.click('details[data-sip-yukle] > summary.sip-yukle-ozet'); await page.waitForTimeout(150);
            const m = await page.evaluate(() => { const d = document.querySelector('details[data-sip-yukle]'); const n = d.querySelector('[data-sip-yukle-mesgul]'); return { acik: d.open, not: !n.hidden && getComputedStyle(n).display !== 'none', metin: n.textContent }; });
            ok('yükleme kartı: tarama sürerken summary tıkı kartı kapatmaz, "Tarama sürüyor" notu görünür', m.acik && m.not && /Tarama sürüyor/.test(m.metin), JSON.stringify(m));
            await page.waitForSelector('#sahteSonuc', { timeout: 6000 });
            await page.click('details[data-sip-yukle] > summary.sip-yukle-ozet'); await page.waitForTimeout(150);
            ok('yükleme kartı: tarama bitince summary tıkı kartı kapatır', await page.evaluate(() => !document.querySelector('details[data-sip-yukle]').open));
            ok('yükleme kartı (tarama): konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
            await ctx.close();
        }
        // ── ağ kopması → Devam et (aynı tarama_id) ──
        {
            const istekler = []; let devamNo = 0;
            const { ctx, page } = await acA('mail_ayarli', 390, 844, false, p => p.route('**/siparis_mail.php', async route => {
                const g = JSON.parse(route.request().postData() || '{}'); istekler.push(g);
                if (g.islem === 'baslat') return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: g.tur, i: 0, toplam: 2, sayac: SAYAC0, uyarilar: [], bitti: false }));
                devamNo++;
                if (devamNo === 1) return route.abort('failed');
                return route.fulfill(JSONY({ ok: true, tarama_id: 'abcdef0123456789', tur: 'siparis', i: 2, toplam: 2, sayac: { ...SAYAC0, alindi: 2 }, uyarilar: [], bitti: true, html: SONUC_HTML }));
            }));
            await page.click('[data-sip-mail="siparis"]');
            await page.waitForFunction(() => { const e = document.querySelector('[data-sip-mail-hata]'); return e && !e.hidden && /Bağlantı kesildi/.test(e.textContent); }, null, { timeout: 5000 });
            const k = await page.evaluate(() => { const b = document.querySelector('[data-sip-mail-ilerleme] [data-sip-mail-devam-et]'); const r = b.getBoundingClientRect();
                return { hata: document.querySelector('[data-sip-mail-hata]').textContent, devamGorunur: !b.hidden && r.width > 0 && r.height >= 43.5, dugmelerAcik: Array.from(document.querySelectorAll('[data-sip-mail]')).every(x => !x.disabled) }; });
            ok('mail ağ kopması: "Bağlantı kesildi — kaldığı yerden devam edebilirsiniz." + görünür "Devam et" (≥44px)', k.hata === 'Bağlantı kesildi — kaldığı yerden devam edebilirsiniz.' && k.devamGorunur, JSON.stringify(k));
            await page.click('[data-sip-mail-ilerleme] [data-sip-mail-devam-et]');
            await page.waitForSelector('#sahteSonuc', { timeout: 5000 });
            ok('mail ağ kopması: "Devam et" AYNI tarama_id ile devam isteği yollar ve tamamlar', istekler.length === 3 && istekler[2].islem === 'devam' && istekler[2].tarama_id === 'abcdef0123456789', JSON.stringify(istekler));
            await ctx.close();
        }
        // ── 409 meşgul + HTML yanıt (oturum süresi) ──
        {
            let yanit409 = true;
            const { ctx, page } = await acA('mail_ayarli', 390, 844, false, p => p.route('**/siparis_mail.php', route => yanit409 ? route.fulfill(JSONY({ ok: false, kod: 'mesgul', mesaj: 'Başka bir mail sorgusu şu an çalışıyor (Kullanıcı 2, 40 sn önce). Bitince yeniden deneyin.' }, 409)) : route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Giriş yapın</html>' })));
            await page.click('[data-sip-mail="siparis"]');
            await page.waitForFunction(() => { const e = document.querySelector('[data-sip-mail-hata]'); return e && !e.hidden; }, null, { timeout: 5000 });
            const k = await page.evaluate(() => ({ hata: document.querySelector('[data-sip-mail-hata]').textContent, acik: Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => !b.disabled), ilerlemeGizli: document.querySelector('[data-sip-mail-ilerleme]').hidden }));
            ok('mail 409 meşgul: sunucu mesajı görünür, düğmeler açılır, ilerleme gizli', /Başka bir mail sorgusu şu an çalışıyor/.test(k.hata) && k.acik && k.ilerlemeGizli, JSON.stringify(k));
            yanit409 = false;
            await page.click('[data-sip-mail="siparis"]');
            await page.waitForFunction(() => /beklenmeyen yanıt/.test(document.querySelector('[data-sip-mail-hata]').textContent), null, { timeout: 5000 });
            ok('mail: JSON olmayan yanıt (oturum süresi) → anlaşılır mesaj, düğmeler açık', await page.evaluate(() => Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => !b.disabled)));
            await ctx.close();
        }
    }
    if (varSayfa('mail_son')) {
        for (const [w, h] of [[1280, 900], [390, 844]]) {   // v382: kapalı kartın summary'sinde son sorgu özeti (kartı otomatik AÇMAZ)
            const { ctx, page } = await ac('mail_son', w, h);
            const o = await page.evaluate(() => { const d = document.querySelector('details[data-sip-yukle]'); const sm = d.querySelector(':scope > summary.sip-yukle-ozet');
                return { acik: d.open, metin: sm.textContent.replace(/\s+/g, ' ').trim(), hata: !!sm.querySelector('.sip-yukle-son--hata') }; });
            ok(`[${w}] yükleme kartı özeti: "Son sorgu: Sipariş · gg.aa ss:dd · N alındı" kapalıyken görünür, kartı açmaz`, !o.acik && /Belge yükle · 📧 Mailden al/.test(o.metin) && /Son sorgu: (Sipariş|Lojistik) · \d{2}\.\d{2} \d{2}:\d{2}( · \d+ alındı)?/.test(o.metin), JSON.stringify(o));
            ok(`[${w}] yükleme kartı özeti: taşma yok`, (await tasma(page)) <= 0);
            await ctx.close();
        }
        for (const [w, h] of [[1280, 900], [820, 1024], [390, 844]]) {
            const masaustu = w >= 1024;
            const { ctx, page, hatalar } = await ac('mail_son', w, h, false, null, { yukleAcik: true });
            ok(`[${w}] mail sonuç: yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
            ok(`[${w}] mail sonuç: ${masaustu ? 'tablo görünür, kartlar gizli' : 'kartlar görünür, tablo gizli'}`,
                masaustu ? (await gorunur(page, '.sip-mail-tablo')) && !(await gorunur(page, '.sip-mail-kartlar')) : !(await gorunur(page, '.sip-mail-tablo')) && (await gorunur(page, '.sip-mail-kartlar')));
            const o = await page.evaluate(() => {
                const kartlar = Array.from(document.querySelectorAll('[data-sip-mail-sonuc-kart]'));
                return { baslik: (kartlar[0].querySelector('h2') || {}).textContent, kartSay: kartlar.length, onceki: !!document.querySelector('details.sip-mail-onceki'), oncekiAcik: !!(document.querySelector('details.sip-mail-onceki') || {}).open,
                    cipler: Array.from(kartlar[0].querySelectorAll('.sip-mail-chip')).map(c => c.textContent.replace(/\s+/g, ' ').trim()) };
            });
            ok(`[${w}] mail sonuç: en yeni sorgu üstte ("Mail sorgusu — Sipariş · son 15 gün · …"), önceki sorgu kapalı <details>`, /^Mail sorgusu — Sipariş · son 15 gün · \d{2}\.\d{2}\.\d{4} \d{2}:\d{2} · /.test(o.baslik) && o.onceki && !o.oncekiAcik, JSON.stringify(o));
            ok(`[${w}] mail sonuç: özet çipleri (Bulunan mail / PDF / Alındı …)`, ['Bulunan mail', 'PDF', 'Alındı', 'Zaten vardı', 'Daha önce alınmış', 'Atlandı', 'Hata'].every((e, i) => o.cipler[i] && o.cipler[i].startsWith(e)), JSON.stringify(o.cipler));
            // önceki sorgu (Lojistik): "Daha önce alınmış" satırları gizli → düğme açar
            await page.click('details.sip-mail-onceki > summary'); await page.waitForTimeout(100);
            const gizli = await page.evaluate(() => { const k = document.querySelector('details.sip-mail-onceki [data-sip-mail-sonuc-kart]'); const vis = e => { const r = e.getBoundingClientRect(); return getComputedStyle(e).display !== 'none' && r.height > 0; };
                const sat = Array.from(k.querySelectorAll('.sip-mail-onceden')).filter(e => e.offsetParent !== null || vis(e)); const b = k.querySelector('[data-sip-mail-onceden]');
                return { gorunenOnceden: sat.length, dugme: b ? b.textContent.trim() : null, pressed: b ? b.getAttribute('aria-pressed') : null, toplamOnceden: k.querySelectorAll('.sip-mail-onceden').length }; });
            ok(`[${w}] mail sonuç: "Daha önce alınmış" satırları varsayılan GİZLİ, düğme "…göster"`, gizli.gorunenOnceden === 0 && gizli.toplamOnceden >= 2 && /göster$/.test(gizli.dugme || '') && gizli.pressed === 'false', JSON.stringify(gizli));
            await page.click('details.sip-mail-onceki [data-sip-mail-onceden]'); await page.waitForTimeout(100);
            const acik = await page.evaluate(() => { const k = document.querySelector('details.sip-mail-onceki [data-sip-mail-sonuc-kart]'); const b = k.querySelector('[data-sip-mail-onceden]');
                const gor = Array.from(k.querySelectorAll('.sip-mail-onceden')).filter(e => { const r = e.getBoundingClientRect(); return getComputedStyle(e).display !== 'none' && r.height > 0; });
                return { gor: gor.length, dugme: b.textContent.trim(), pressed: b.getAttribute('aria-pressed') }; });
            ok(`[${w}] mail sonuç: düğme satırları gösterir, metin "…gizle", aria-pressed=true`, acik.gor >= 1 && /gizle$/.test(acik.dugme) && acik.pressed === 'true', JSON.stringify(acik));
            ok(`[${w}] mail sonuç: satırlar açılınca da yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
            ok(`[${w}] mail sonuç: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            if (w === 1280 || w === 390) await page.screenshot({ path: path.join(SHOTS, `${w}_mail_son.png`), fullPage: true });
            await ctx.close();
        }
        {   // koyu tema: çipler + sonuç kartı
            const { ctx, page } = await ac('mail_son', 390, 844, true, null, { yukleAcik: true });
            const r = await page.evaluate(() => { const cs = (s) => { const e = document.querySelector(s); return e ? getComputedStyle(e) : null; };
                const k = cs('[data-sip-mail-sonuc-kart]'), ok_ = cs('.sip-mail-chip--ok'), uy = cs('.sip-mail-chip--uyari'), ht = cs('.sip-mail-chip--hata'), btn = cs('.sip-mail-btn');
                const kok = getComputedStyle(document.documentElement).getPropertyValue('--card').trim(); const tmp = document.createElement('div'); tmp.style.background = kok; document.body.appendChild(tmp); const bg = getComputedStyle(tmp).backgroundColor; tmp.remove();
                return { kartBg: k.backgroundColor, beklenen: bg, okBg: ok_ && ok_.backgroundColor, okFg: ok_ && ok_.color, uyBg: uy && uy.backgroundColor, uyFg: uy && uy.color, htBg: ht && ht.backgroundColor, htFg: ht && ht.color, btnBg: btn.backgroundColor, btnFg: btn.color }; });
            ok('koyu tema mail: sonuç kartı koyu turuncu zemin (v420: --sip-sorgu-kart), açık tema rengi sızmaz', r.kartBg === 'rgb(74, 46, 20)', JSON.stringify(r));
            ok('koyu tema mail: çipler koyu zeminde açık yazı (Alındı)', lumOf(r.okBg) < 0.35 && lumOf(r.okFg) > lumOf(r.okBg) + 0.25, JSON.stringify(r));
            ok('koyu tema mail: Atlandı / Hata çipleri de okunur', (!r.uyBg || lumOf(r.uyFg) > lumOf(r.uyBg) + 0.25) && (!r.htBg || lumOf(r.htFg) > lumOf(r.htBg) + 0.25), JSON.stringify(r));
            ok('koyu tema mail: düğme zemini koyu, yazı açık', lumOf(r.btnFg) > lumOf(r.btnBg) + 0.2, JSON.stringify(r));
            await page.screenshot({ path: path.join(SHOTS, '390_mail_son_koyu.png'), fullPage: true });
            await ctx.close();
        }
    }
    if (varSayfa('mail_yarim')) {
        const tid = MANIFEST.yarim_tid;
        {   // v382: yarım tarama → sunucu kartı AÇIK yazar ve özette "Yarım tarama var" görünür
            const { ctx, page } = await ac('mail_yarim', 390, 844);
            const o = await page.evaluate(() => { const d = document.querySelector('details[data-sip-yukle]'); return { acik: d.open, attr: d.hasAttribute('open'), ozet: d.querySelector(':scope > summary').textContent, serit: !!d.querySelector('.sip-mail-yarim') }; });
            ok('yükleme kartı: yarım kalan mail taramasında sunucu kartı açık yazar, şerit görünür', o.acik && o.attr && o.serit && /Yarım tarama var/.test(o.ozet), JSON.stringify(o));
            await ctx.close();
        }
        for (const [w, h] of [[1280, 900], [390, 844]]) {
            const istekler = [];
            const { ctx, page, hatalar } = await ac('mail_yarim', w, h, false, p => p.route('**/siparis_mail.php', async route => { const g = JSON.parse(route.request().postData() || '{}'); istekler.push(g);
                route.fulfill(JSONY({ ok: true, tarama_id: tid, tur: 'siparis', i: 2, toplam: 2, sayac: { ...SAYAC0, alindi: 2 }, uyarilar: [], bitti: true, html: SONUC_HTML })); }));
            const sr = await page.evaluate(() => { const e = document.querySelector('.sip-mail-yarim'); return e ? { metin: e.textContent.replace(/\s+/g, ' ').trim(), tid: e.getAttribute('data-sip-mail-devam') } : null; });
            ok(`[${w}] yarım tarama şeridi: "Yarım kalan mail sorgusu (Sipariş, 0/2) — Devam et Vazgeç"`, sr && /^Yarım kalan mail sorgusu \(Sipariş, 0\/2\) — Devam et Vazgeç$/.test(sr.metin) && sr.tid === tid, JSON.stringify(sr));
            ok(`[${w}] yarım tarama: yatay taşma yok`, (await tasma(page)) <= 0);
            await page.click('.sip-mail-yarim [data-sip-mail-devam-et]');
            await page.waitForSelector('#sahteSonuc', { timeout: 5000 });
            ok(`[${w}] yarım tarama: "Devam et" şeritteki tarama_id ile devam ister; şerit kaldırılır`, istekler.length === 1 && istekler[0].islem === 'devam' && istekler[0].tarama_id === tid && !(await page.$('.sip-mail-yarim')), JSON.stringify(istekler));
            ok(`[${w}] yarım tarama: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            await ctx.close();
        }
        const istekler = [];
        const { ctx, page } = await ac('mail_yarim', 390, 844, false, p => p.route('**/siparis_mail.php', route => { istekler.push(JSON.parse(route.request().postData() || '{}')); route.fulfill(JSONY({ ok: true, tarama_id: tid, tur: 'siparis', i: 0, toplam: 2, sayac: SAYAC0, uyarilar: ['Sorgu yarıda bırakıldı'], bitti: true, html: SONUC_HTML })); }));
        await page.click('.sip-mail-yarim [data-sip-mail-vazgec]');
        await page.waitForSelector('#sahteSonuc', { timeout: 5000 });
        ok('yarım tarama: "Vazgeç" islem=vazgec + tarama_id yollar', istekler.length === 1 && istekler[0].islem === 'vazgec' && istekler[0].tarama_id === tid, JSON.stringify(istekler));
        await ctx.close();
    }
    if (varSayfa('mail_ayar_sayfa')) {
        for (const [w, h] of [[1280, 900], [390, 844]]) {
            const { ctx, page, hatalar } = await ac('mail_ayar_sayfa', w, h);
            const k = await page.evaluate(() => { const f = document.querySelector('#mail form'); const ta = Array.from(f.querySelectorAll('textarea'));
                return { baslik: document.querySelector('#sipMailAyarBas').textContent, kutu: f.querySelectorAll('input[name="hesaplar[]"]').length, isaretli: f.querySelectorAll('input[name="hesaplar[]"]:checked').length, ta: ta.map(t => t.name), taFont: ta.map(t => parseFloat(getComputedStyle(t).fontSize)),
                    yonlendirme: /Mailde 'X adına Y' görünüyorsa iki adresi de ekleyin\./.test(document.querySelector('#mail').textContent), csrf: !!f.querySelector('input[name=csrf]'), islem: (f.querySelector('input[name=islem]') || {}).value,
                    degerler: ta.map(t => t.value), btn: f.querySelector('button[type=submit]').getBoundingClientRect().height }; });
            ok(`[${w}] mail ayar kartı: başlık, posta kutusu onay kutuları, 2 gönderen alanı, CSRF, islem=mail_ayar`, /Mail sorgu ayarları/.test(k.baslik) && k.kutu >= 1 && k.isaretli >= 1 && k.ta.join() === 'g_siparis,g_lojistik' && k.csrf && k.islem === 'mail_ayar', JSON.stringify(k));
            ok(`[${w}] mail ayar kartı: "X adına Y" yönlendirmesi görünür`, k.yonlendirme);
            ok(`[${w}] mail ayar kartı: kayıtlı adresler alanlara dolu`, k.degerler[0] === 'po@ornek.test' && k.degerler[1] === 'log@ornek.test\nkisi@ornek.test' && k.degerler.length === 2, JSON.stringify(k.degerler));
            if (w < 768) ok(`[${w}] mail ayar kartı: mobil girdi 16px + düğme ≥44px`, k.taFont.every(x => x >= 16) && k.btn >= 43.5, JSON.stringify([k.taFont, k.btn]));
            ok(`[${w}] mail ayar kartı: yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
            ok(`[${w}] mail ayar kartı: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
            await ctx.close();
        }
    }

    // ── v400: Evrak takımı bekleyen bölüm + son adım ──
    for (const [w, h] of EKRANLAR) {
        const mobil = w < 768;
        let { ctx, page, hatalar } = await ac('liste', w, h);
        const b = await page.evaluate(() => {
            const sec = id => { const e = document.getElementById(id); return e ? e.closest('section') : null; };
            const a = sec('sipAcik'), e = sec('sipEvrak'), k = sec('sipKapali');
            const sol = el => getComputedStyle(el).borderLeftColor;
            return { var: !!(a && e && k), sira: !!(a && e && k) && !!(a.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING) && !!(e.compareDocumentPosition(k) & Node.DOCUMENT_POSITION_FOLLOWING),
                renk: a && e && k ? [sol(a), sol(e), sol(k)] : [], genislik: e ? Math.round(e.getBoundingClientRect().width) : 0, tasma: document.documentElement.scrollWidth - window.innerWidth };
        });
        ok(`[${w}] v400 liste: Açık < Evrak < Kapanan sırası`, b.var && b.sira, JSON.stringify(b));
        ok(`[${w}] v400 liste: Evrak kartının sol şerit rengi Açık ve Kapanan'dan farklı`, b.renk.length === 3 && b.renk[1] !== b.renk[0] && b.renk[1] !== b.renk[2], JSON.stringify(b.renk));
        ok(`[${w}] v400 liste: yatay taşma yok (üç bölüm)`, b.tasma <= 0, String(b.tasma));
        ok(`[${w}] v400 liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        // v402 — Evrak kartı açık mavi (diğer iki karttan farklı zemin) + "son bakışınızdan sonra değişti" çerçevesi (görülme kaydı cihazda)
        const taban = t => async pg => { await pg.addInitScript(x => { try { if (!localStorage.getItem('asya_sip_gorulen_1')) localStorage.setItem('asya_sip_gorulen_1', JSON.stringify({ t: x, s: {} })); } catch (e) {} }, t); };
        ({ ctx, page, hatalar } = await ac('liste', w, h, false, taban('2000-01-01 00:00:00')));
        const dg = await page.evaluate(() => {
            const bg = id => { const e = document.getElementById(id); return e ? getComputedStyle(e.closest('section')).backgroundColor : null; };
            const bir = document.querySelector('tr.sip-degisti:not(.sip-urun-tr) > td:first-child') || document.querySelector('.sip-card.sip-degisti');
            return { say: document.querySelectorAll('tr.sip-degisti:not(.sip-urun-tr), .sip-card.sip-degisti').length, bg: [bg('sipAcik'), bg('sipEvrak'), bg('sipKapali')],
                cerceve: bir ? (getComputedStyle(bir).boxShadow !== 'none' || getComputedStyle(bir).borderColor) : null, tasma: document.documentElement.scrollWidth - window.innerWidth };
        });
        ok(`[${w}] v402 liste: taban eski → değişmiş sevkiyatlar çerçeveli`, dg.say >= 3 && !!dg.cerceve, JSON.stringify(dg));
        ok(`[${w}] v402 liste: Evrak kartı zemini Açık ve Kapanan'dan farklı (açık mavi)`, !!dg.bg[1] && dg.bg[1] !== dg.bg[0] && dg.bg[1] !== dg.bg[2] && /^rgb\(2[34]\d, 2[34]\d, 25\d\)$/.test(dg.bg[1]), JSON.stringify(dg.bg));
        ok(`[${w}] v402 liste: çerçeve yatay taşma yaratmaz`, dg.tasma <= 0, String(dg.tasma));
        // v420 — yeni gelen sevkiyatın yanında neon YENİ rozeti; iki sevkiyat arasında belirgin ayraç
        const yn = await page.evaluate(() => {
            const r = document.querySelector('tr.sip-grup .sip-yeni, .sip-card .sip-yeni'); if (!r) return { say: document.querySelectorAll('.sip-yeni').length };
            const cs = getComputedStyle(r), kap = r.closest('tr, .sip-card');
            return { say: document.querySelectorAll('.sip-yeni').length, metin: r.textContent, golge: cs.textShadow !== 'none', sinirli: cs.borderTopColor, referansYaninda: !!kap.querySelector('.sip-ref'), tasma: document.documentElement.scrollWidth - window.innerWidth };
        });
        ok(`[${w}] v420 liste: yeni gelen sevkiyatta neon "YENİ" rozeti (referansın yanında, glow gölgeli, taşma yok)`, yn.say >= 1 && yn.metin === 'YENİ' && yn.golge && yn.referansYaninda && yn.tasma <= 0, JSON.stringify(yn));
        const ay = await page.evaluate(() => {
            const urunler = Array.from(document.querySelectorAll('.sip-table tbody > tr.sip-grup'));
            const son = urunler.filter(t => t.nextElementSibling && !(t.classList.contains('sip-ust'))).map(t => getComputedStyle(t.querySelector('td')).borderBottomWidth);
            const kart = document.querySelector('.sip-kartlar');
            return { genis: son.length ? son.every(x => parseFloat(x) >= 3) : null, gap: kart ? getComputedStyle(kart).rowGap : null };
        });
        ok(`[${w}] v420 liste: sevkiyatlar arasında belirgin çizgi (≥3px) ve kartlar arası boşluk 14px`, (ay.genis === true || ay.genis === null) && ay.gap === '14px', JSON.stringify(ay));
        // v424 — uyarı / çatışma rozetinin üzerinde fare bekleyince baloncuk (yalnız fareli masaüstü; mobilde açılmaz)
        const rozet = page.locator('tr.sip-grup a[data-sip-balon]:visible').first();
        if (!mobil && await rozet.count()) {
            const dahaOnce = await page.evaluate(() => !!document.querySelector('.sip-balon:not([hidden])'));
            await rozet.hover();
            await page.waitForTimeout(700);
            const bl = await page.evaluate(() => {
                const b = document.querySelector('.sip-balon'); if (!b || b.hidden) return null;
                const r = b.getBoundingClientRect(), a = Array.from(document.querySelectorAll('tr.sip-grup a[data-sip-balon]')).find(x => x.offsetParent !== null).getBoundingClientRect();
                return { metin: b.textContent, li: b.querySelectorAll('li').length, baslik: b.querySelectorAll('.sip-balon-baslik').length, ekranIci: r.left >= 0 && r.right <= window.innerWidth && r.top >= 0 && r.bottom <= window.innerHeight,
                    konum: getComputedStyle(b).position, altinda: r.top >= a.bottom - 1 || r.bottom <= a.top + 1, html: b.innerHTML.indexOf('<script') };
            });
            ok(`[${w}] v424 liste: rozet üzerinde fare bekleyince baloncuk açılır (fixed, ekran içinde, rozete binmez, ≥1 satır)`, !dahaOnce && bl && bl.konum === 'fixed' && bl.li >= 1 && bl.baslik >= 1 && bl.ekranIci && bl.altinda && bl.html === -1, JSON.stringify(bl));
            await page.mouse.move(5, 5);
            await page.waitForTimeout(200);
            ok(`[${w}] v424 liste: fare ayrılınca baloncuk kapanır`, await page.evaluate(() => { const b = document.querySelector('.sip-balon'); return !b || b.hidden; }));
        }
        // v426 — "ne değişti": sipariş no'nun solunda kırmızı nokta + sayı; hover'da özet (masaüstü), dokunmatikte title
        const dgv = await page.evaluate(() => {
            const k = Array.from(document.querySelectorAll('.sip-dg-kap')).filter(x => !x.hidden && x.offsetParent !== null);
            if (!k.length) return { say: 0, tum: document.querySelectorAll('.sip-dg-kap').length };
            const n = k[0].querySelector('.sip-dg'), kap = k[0].closest('tr, .sip-card'), ref = kap.querySelector('.sip-ref'), rn = n.getBoundingClientRect(), rr = ref.getBoundingClientRect();
            const cs = getComputedStyle(n), sat = Array.from(k[0].querySelectorAll('[data-satir]'));
            return { say: k.length, metin: n.textContent, aria: n.getAttribute('aria-label'), solda: rn.right <= rr.left + 1 && Math.abs((rn.top + rn.bottom) / 2 - (rr.top + rr.bottom) / 2) < 14, kirmizi: cs.backgroundColor,
                yuvarlak: cs.borderTopLeftRadius, sat: sat.map(x => x.textContent), t: sat.map(x => x.getAttribute('data-t')), id: kap.querySelector('[data-sip-gor-id]').getAttribute('data-sip-gor-id'),
                title: n.title, tasma: document.documentElement.scrollWidth - window.innerWidth, baslik: k[0].querySelector('[data-baslik]').getAttribute('data-baslik') };
        });
        ok(`[${w}] v426 liste: değişimi olan sevkiyatta kırmızı nokta + sayı "3", sipariş no'nun SOLUNDA (aynı satırda), taşma yok`, dgv.say === 1 && dgv.metin === '3' && dgv.solda && dgv.kirmizi === 'rgb(220, 38, 38)' && dgv.tasma <= 0 && /3 güncelleme/.test(dgv.aria), JSON.stringify(dgv));
        ok(`[${w}] v426 liste: özet satırları (en yeni önce) — belge · çatışma · alan değişimi; sayılmayanlar yok`, dgv.sat && dgv.sat.length === 3 && /Yeni belge: Loading List: ll_yeni\.pdf/.test(dgv.sat[0]) && /Plakalar çatışması: 34TST1 ≠ 34TST9/.test(dgv.sat[1]) && dgv.sat[2] === 'Yükleme tarihi: 10.12.2026 → 11.12.2026' && !dgv.sat.join('|').includes('ILK-DOLUM'), JSON.stringify(dgv.sat));
        if (!mobil) {
            ok(`[${w}] v426 liste: masaüstünde native title YOK (çift baloncuk olmasın)`, dgv.title === '');
            const dn = page.locator('.sip-dg-kap:not([hidden]) .sip-dg:visible').first();
            await dn.scrollIntoViewIfNeeded();   // kaydırma olayı baloncuğu kapatır (gerçek kullanıcı da önce kaydırır, sonra bekler)
            await page.waitForTimeout(300);
            await dn.hover();
            await page.waitForTimeout(700);
            const dbl = await page.evaluate(() => { const b = document.querySelector('.sip-balon'); if (!b || b.hidden) return null; return { baslik: b.querySelector('.sip-balon-baslik').textContent, li: Array.from(b.querySelectorAll('li')).map(x => x.textContent), ekranIci: b.getBoundingClientRect().right <= window.innerWidth && b.getBoundingClientRect().bottom <= window.innerHeight }; });
            ok(`[${w}] v426 liste: noktanın üzerinde fare bekleyince özet baloncuğu açılır (başlık "3 güncelleme…", 3 satır, ekran içinde)`, dbl && /^3 güncelleme/.test(dbl.baslik) && dbl.li.length === 3 && dbl.li[2].startsWith('Yükleme tarihi:') && dbl.ekranIci, JSON.stringify(dbl));
            await page.mouse.move(5, 5);
            await page.waitForTimeout(200);
        } else {
            ok(`[${w}] v426 liste: dokunmatikte özet title'da (satırlar alt alta)`, dgv.title.includes('Yükleme tarihi: 10.12.2026 → 11.12.2026') && dgv.title.split('\n').length >= 3, dgv.title);
        }
        // son bakış ortada: yalnız o andan SONRAKİ güncelleme sayılır
        const orta = dgv.t[1] || '';
        const a2 = await ac('liste', w, h, false, async pg => { await pg.addInitScript(x => { try { localStorage.setItem('asya_sip_gorulen_1', JSON.stringify({ t: '2000-01-01 00:00:00', s: { [x.id]: x.t } })); } catch (e) {} }, { id: dgv.id, t: orta }); });
        const dg2 = await a2.page.evaluate(() => { const k = Array.from(document.querySelectorAll('.sip-dg-kap')).filter(x => !x.hidden && x.offsetParent !== null); return { say: k.length, metin: k[0] ? k[0].querySelector('.sip-dg').textContent : null, sat: k[0] ? Array.from(k[0].querySelectorAll('[data-satir]')).map(x => x.textContent) : [] }; });
        ok(`[${w}] v426 liste: görülen zamanından sonrası sayılır (3 → 1 güncelleme, yalnız yeni belge)`, dg2.say === 1 && dg2.metin === '1' && dg2.sat.length === 1 && /Yeni belge/.test(dg2.sat[0]), JSON.stringify(dg2));
        await a2.ctx.close();
        const a3 = await ac('liste', w, h, false, async pg => { await pg.addInitScript(x => { try { localStorage.setItem('asya_sip_gorulen_1', JSON.stringify({ t: '2000-01-01 00:00:00', s: { [x.id]: '2099-01-01 00:00:00' } })); } catch (e) {} }, { id: dgv.id }); });
        ok(`[${w}] v426 liste: sevkiyat açılıp görülmüşse nokta çıkmaz`, await a3.page.evaluate(() => Array.from(document.querySelectorAll('.sip-dg-kap')).every(x => x.hidden)));
        await a3.ctx.close();
        ok(`[${w}] v402 liste: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        ({ ctx, page, hatalar } = await ac('liste', w, h, false, taban('2099-01-01 00:00:00')));
        ok(`[${w}] v402 liste: taban gelecekte → çerçeve yok (ilk ziyarette eski kayıtlar "yeni" sayılmaz)`, await page.evaluate(() => document.querySelectorAll('.sip-degisti').length === 0 && document.querySelectorAll('.sip-yeni').length === 0));

        await ctx.close();

        ({ ctx, page, hatalar } = await ac('liste', w, h));   // kayıt yok → ilk ziyaret: taban = sunucu şimdisi
        ok(`[${w}] v402 liste: ilk ziyaret (kayıt yok) → çerçeve yok, taban yazıldı`, await page.evaluate(() => document.querySelectorAll('.sip-degisti').length === 0 && !!JSON.parse(localStorage.getItem('asya_sip_gorulen_1') || 'null')));
        await ctx.close();

        ({ ctx, page, hatalar } = await ac('detay', w, h, false, taban('2000-01-01 00:00:00')));   // sevkiyat sayfası açıldı → görüldü
        const gid = await page.evaluate(() => { const m = document.querySelector('[data-sip-gor-id]'); const d = JSON.parse(localStorage.getItem('asya_sip_gorulen_1') || 'null'); return { id: m ? m.getAttribute('data-sip-gor-id') : null, yazildi: !!(d && m && d.s[m.getAttribute('data-sip-gor-id')]) }; });
        ok(`[${w}] v402 detay: sevkiyat sayfası açılınca görülme kaydı yazılır`, !!gid.id && gid.yazildi, JSON.stringify(gid));
        await page.goto('file://' + path.join(OUT, 'liste.html'));
        await page.waitForTimeout(300);
        const gl = await page.evaluate(id => {
            const e0 = document.querySelector('[data-sip-gor-id="' + id + '"]'); const e = e0 ? (e0.closest('tr') || e0.closest('.sip-card')) : null;
            const d = JSON.parse(localStorage.getItem('asya_sip_gorulen_1') || 'null') || { s: {} };
            const lu = e0 ? e0.getAttribute('data-sip-gor-upd') : '', du = d.s[id] || '';
            // render sırasında sevkiyat sayfasından SONRA değişmiş olabilir (fixture sıralı) → çerçeve ⇔ liste zamanı görülenden yeni
            return { var: !!e, lu, du, cerceveli: !!(e && e.classList.contains('sip-degisti')), beklenen: lu > du, digerleri: document.querySelectorAll('.sip-degisti').length };
        }, gid.id);
        ok(`[${w}] v402 liste: sevkiyat sayfası açılınca çerçeve görülen zamana göre kalkar (değişme zamanı görülenden yeniyse kalır)`, gl.var && gl.du !== '' && gl.cerceveli === gl.beklenen, JSON.stringify(gl));
        ok(`[${w}] v402 liste: açılmayan diğer sevkiyatların çerçevesi durur`, gl.digerleri >= 1, JSON.stringify(gl));
        await ctx.close();

        // v420 — sorgu alanı turuncu, sorgu düğmeleri renkli; yükleme kartı 5 sn (testte 700 ms) etkinlik olmazsa kendiliğinden kapanır
        ({ ctx, page, hatalar } = await ac('mail_ayarli', w, h, false, null, { yukleAcik: true }));
        const tr = await page.evaluate(() => {
            const rgb = e => (getComputedStyle(e).backgroundColor.match(/\d+/g) || []).map(Number);
            const m = document.querySelector('.sip-yukle .sip-mail'), b1 = document.querySelector('[data-sip-mail="siparis"]'), b2 = document.querySelector('[data-sip-mail="lojistik"]');
            return { mail: m ? rgb(m) : null, b1: b1 ? getComputedStyle(b1).backgroundColor : null, b2: b2 ? getComputedStyle(b2).backgroundColor : null };
        });
        ok(`[${w}] v420 mail sorgu alanı: turuncu zemin (R > G > B, R ≥ 240)`, !!tr.mail && tr.mail[0] >= 240 && tr.mail[0] > tr.mail[1] && tr.mail[1] > tr.mail[2] && tr.mail[0] - tr.mail[2] >= 40, JSON.stringify(tr));
        ok(`[${w}] v420 sorgu düğmeleri renkli (Sipariş mavi, Lojistik yeşil)`, tr.b1 === 'rgb(37, 99, 235)' && tr.b2 === 'rgb(21, 128, 61)', JSON.stringify(tr));
        await ctx.close();
        const YK0 = 'details[data-sip-yukle]', YS0 = 'details[data-sip-yukle] > summary.sip-yukle-ozet';
        ({ ctx, page, hatalar } = await ac('mail_ayarli', w, h, false, null, { otoKapat: 700 }));
        await page.click(YS0); await page.waitForTimeout(250);
        ok(`[${w}] v420 yükleme kartı: açılınca hemen kapanmaz`, await page.evaluate(s => document.querySelector(s).open, YK0));
        await page.click('.sip-mail-baslik h2'); await page.waitForTimeout(500);   // etkinlik süreyi yeniler (açılıştan ≈ 750 ms > 700 ama son etkinlikten 500 ms)
        ok(`[${w}] v420 yükleme kartı: etkinlik süreyi yeniler (hâlâ açık)`, await page.evaluate(s => document.querySelector(s).open, YK0));
        await page.waitForTimeout(1100);
        ok(`[${w}] v420 yükleme kartı: etkinlik yoksa kendiliğinden kapanır`, await page.evaluate(s => !document.querySelector(s).open, YK0));
        await page.click(YS0); await page.waitForTimeout(150);
        await page.click('summary.sip-dosya-ozet'); await page.waitForTimeout(100);
        await (await page.$('form[data-sip-upload] input[type=file]')).setInputFiles([{ name: 'a.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(2048, 1) }]);
        await page.waitForTimeout(1500);
        ok(`[${w}] v420 yükleme kartı: dosya seçiliyken kapanmaz`, await page.evaluate(s => document.querySelector(s).open, YK0));
        ok(`[${w}] v420 yükleme kartı: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        ({ ctx, page, hatalar } = await ac('liste_evrak', w, h));
        ok(`[${w}] v400 durum=evrak_bekleyen: yalnız Evrak bölümü, yatay taşma yok`, await page.evaluate(() => !!document.getElementById('sipEvrak') && !document.getElementById('sipAcik') && !document.getElementById('sipKapali') && document.documentElement.scrollWidth <= window.innerWidth));
        await ctx.close();

        ({ ctx, page, hatalar } = await ac('detay_evrak', w, h));
        ok(`[${w}] v400 detay (evrak bekleyen): yatay taşma yok`, (await tasma(page)) <= 0, String(await tasma(page)));
        if (mobil) {
            const kucuk = await page.evaluate(() => Array.from(document.querySelectorAll('.sip-hizli .btn')).filter(e => e.offsetParent !== null).map(e => ({ t: (e.textContent || '').trim().slice(0, 24), h: e.getBoundingClientRect().height })).filter(x => x.h < 43.5));
            ok(`[${w}] v400 detay: hızlı eylem düğmeleri ≥ 44px`, kucuk.length === 0, JSON.stringify(kucuk));
        }
        await page.click('.sip-hizli [data-sip-ac=sipEvrakModal]');
        await page.waitForTimeout(400);
        const ev0 = await page.evaluate(() => { const m = document.getElementById('sipEvrakModal'); return { acik: !m.hidden, pasif: m.querySelector('[data-sip-evrak-gonder]').disabled }; });
        ok(`[${w}] v400 evrak penceresi: açılır, gönder düğmesi pasif`, ev0.acik && ev0.pasif, JSON.stringify(ev0));
        await page.check('#sipEvrakModal [data-sip-evrak-onay]');
        await page.waitForTimeout(100);
        ok(`[${w}] v400 evrak penceresi: onay kutusu işaretlenince gönder düğmesi etkinleşir`, await page.evaluate(() => !document.querySelector('#sipEvrakModal [data-sip-evrak-gonder]').disabled));
        // v422: penceredeki mail metni düzenlenince mailto body'si ve eml ?govde= parametresi güncellenir; sıfırla ilk haline döner
        const gv = await page.evaluate(() => {
            const k = document.querySelector('#sipEvrakModal .sip-mail-alici'), t = k.querySelector('textarea[data-sip-govde]');
            const mt = k.querySelector('a[data-sip-govde-mailto]'), em = k.querySelector('a[data-sip-govde-eml]');
            const ilkMt = mt.getAttribute('href'), ilkEm = em.getAttribute('href');
            t.value = 'Yeni metin\nikinci satır'; t.dispatchEvent(new Event('input', { bubbles: true }));
            const d1 = { mt: mt.getAttribute('href'), em: em.getAttribute('href') };
            t.value = '  '; t.dispatchEvent(new Event('input', { bubbles: true }));
            const d2 = { mt: mt.getAttribute('href'), em: em.getAttribute('href') };
            t.value = 'Başka'; t.dispatchEvent(new Event('input', { bubbles: true }));
            k.querySelector('[data-sip-govde-sifirla]').click();
            return { ilkMt, ilkEm, d1, d2, sifirlaDeger: t.value === t.getAttribute('data-sip-govde-ilk'), sifirlaEm: em.getAttribute('href'), sifirlaMt: mt.getAttribute('href') };
        });
        ok(`[${w}] v422 evrak metni: düzenleme mailto body'sine yansır (CRLF kodlu), eml ?govde= eklenir`, gv.d1.mt.includes('body=' + encodeURIComponent('Yeni metin\r\nikinci satır')) && gv.d1.mt.startsWith(gv.ilkMt.replace(/([?&])body=[^&]*$/, '')) && gv.d1.em === gv.ilkEm + '&govde=' + encodeURIComponent('Yeni metin\nikinci satır'), JSON.stringify(gv.d1));
        ok(`[${w}] v422 evrak metni: kutu boşaltılırsa varsayılan metne döner, eml parametresi kalkar`, gv.d2.mt === gv.ilkMt && gv.d2.em === gv.ilkEm, JSON.stringify(gv.d2));
        ok(`[${w}] v422 evrak metni: "Metni sıfırla" ilk metni ve bağlantıları geri getirir`, gv.sifirlaDeger && gv.sifirlaEm === gv.ilkEm && gv.sifirlaMt === gv.ilkMt, JSON.stringify(gv));
        await page.uncheck('#sipEvrakModal [data-sip-evrak-onay]');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(100);
        await pencereDene(page, `[${w}] v400 evrak penceresi`, '.sip-hizli [data-sip-ac=sipEvrakModal]', '#sipEvrakModal', h);
        ok(`[${w}] v400 detay: konsol hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();

        ({ ctx, page, hatalar } = await ac('detay_kapali', w, h));
        ok(`[${w}] v400 detay (kapalı): 7. adım ✓ ve "su-an"; Mail/Evrak düğmesi yok`, await page.evaluate(() => {
            const l = Array.from(document.querySelectorAll('.sip-steps li'));
            const son = l[l.length - 1];
            return l.length === 7 && son.classList.contains('sip-step--su-an') && son.querySelector('.sip-step-nokta').textContent.trim() === '✓' && !document.querySelector('[data-sip-ac=sipMailModal], [data-sip-ac=sipEvrakModal]');
        }));
        ok(`[${w}] v400 detay (kapalı): yatay taşma yok, konsol hatası yok`, (await tasma(page)) <= 0 && hatalar.length === 0, hatalar.join(' | '));
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
