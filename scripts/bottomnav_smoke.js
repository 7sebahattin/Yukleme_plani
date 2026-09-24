// =========================================================
// scripts/bottomnav_smoke.js — Mobil alt gezinme çubuğunun (bottomnav)
// GERÇEK TARAYICI ölçümü. Girdi: scripts/bottomnav_render.php çıktısı.
//
//   BOTTOMNAV_OUT=/tmp/bottomnav-test php scripts/bottomnav_render.php
//   BOTTOMNAV_OUT=/tmp/bottomnav-test node scripts/bottomnav_smoke.js
//
// Seçenekler:  --out=KLASÖR (BOTTOMNAV_OUT yerine)   --no-shots (ekran görüntüsü alma)
//              --strict (bilinen hataları da BAŞARISIZ say)
// Ekran görüntüleri: <KLASÖR>/screens/<genişlik>_<profil>_<sayfa>.png
//
// İKİ AYRI GRUP:
//   (A) DEĞİŞMEZLER — HER tasarımda geçerli olmalı (çubuk sabit + ekranda,
//       z-index mimarisi, ≥44px dokunma hedefi, etiket taşmaması, tek aktif
//       öğe, her bağlantının profilin AÇABİLDİĞİ sayfaya gitmesi, güvenli
//       alan, içeriğin çubuğun arkasında kalmaması, depo rengi değişkeni,
//       konsol hatası yok, kırılım noktası görünürlüğü).
//       Yalnız TASARIM.barSel'e dayanır; öğe sınıflarına bakmaz.
//   (B) GÜNCEL TASARIM — etiketler, sıralama, sayı, aktif işaretleme biçimi,
//       ortadaki yükseltilmiş "Bildirim" düğmesi, çubuk yüksekliği.
//       Yeni tasarım onaylanınca YALNIZ aşağıdaki TASARIM nesnesi güncellenir.
//
// BİLİNEN HATALAR (BILINEN): mevcut kodda bulunmuş, bu görevde DÜZELTİLMEYEN
// gerçek hatalar. Başarısız olunca "BİLİNEN HATA" yazılır ve çıkış kodunu
// bozmaz; düzeltilince "XPASS — listeden çıkar" yazılır. --strict ikisini
// de başarısız sayar.
//
// Playwright yoksa kendini ATLAR (beyan_js_smoke.js ile aynı kural).
// =========================================================
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core',
                   '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) {
    console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).');
    process.exit(0);
}

const arg = (ad) => (process.argv.find(a => a.startsWith(`--${ad}=`)) || '').split('=').slice(1).join('=');
const OUT = arg('out') || process.env.BOTTOMNAV_OUT || path.join(os.tmpdir(), 'bottomnav-test');
const SHOTS = path.join(OUT, 'screens');
const NO_SHOTS = process.argv.includes('--no-shots');
const STRICT = process.argv.includes('--strict');
const MANIFEST = path.join(OUT, 'manifest.json');
if (!fs.existsSync(MANIFEST)) {
    console.error(`${MANIFEST} yok. Önce: BOTTOMNAV_OUT=${OUT} php scripts/bottomnav_render.php`);
    process.exit(1);
}
const M = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'));

// ═════════════════════════════════════════════════════════════════════════
// (B) GÜNCEL TASARIM — PROGRAM ajanı yeni tasarım onaylanınca BURAYI günceller
// ═════════════════════════════════════════════════════════════════════════
const TASARIM = {
    // (A) grubu da bunu kullanır: çubuğun KAPSAYICISI (tam genişlik, altta).
    barSel: '.bottomnav',
    // (B) — Varyant A "Kabartma Karo" (Sprint Alt-Menü-01)
    dockSel: '.bn-dock',
    itemSel: '.bn-item',            // tüm dokunma öğeleri (Ana Sayfa, slotlar, Diğer)
    slotSel: '.bn-slot',            // aday sayfalar (gizli olanlar dahil DOM'da)
    homeSel: '.bn-home',
    moreSel: '#bnMore',
    labelSel: '.bn-label',
    sheetOvlSel: '#bnSheetOvl',
    sheetSel: '#bnSheet',
    // Genişlik → görünen slot sayısı (sunucu hep 4 çizer, 390 altında CSS 4.'yü gizler)
    slotEsik: 390,                  // < 390 → 3 slot, ≥ 390 → 4 slot
    // Aktif işaret: aria-current="page" (tek öğe) + .is-active
    aktifSinif: 'is-active',
    // Kapsayıcı yüksekliği (px, güvenli alan 0): dok ~84 + alt boşluk 8 → --bn-h 92px
    yukseklik: [86, 100],
    // En küçük etiket yazı boyu (fitLabels .tight)
    minEtiketPx: 9.5,
    // Koyu temada pasif ikon filtresi
    koyuPasifFiltre: 'saturate(0.55) brightness(0.86)',
};

// ═════════════════════════════════════════════════════════════════════════
// BİLİNEN HATALAR — mevcut kodda bulunan, bu görevde düzeltilmeyen
// ═════════════════════════════════════════════════════════════════════════
// Sprint Alt-Menü-01 ile dördü de düzeldi ve listeden çıkarıldı:
//   BN-HKS-IKI-AKTIF      → nav_aktif_anahtar() (sidebar + çubuk TEK kaynak)
//   BN-MALIYET-AKTIF-YOK  → aynı fonksiyon: maliyet_* → 'rapor'
//   BN-PDKS-ANASAYFA-403  → Ana Sayfa hedefi first_allowed_page(); o da yoksa çubuk basılmaz
//   BN-HKS-IFRAME-ORTUSME → halkayit/index.php padding'i var(--bn-h)
const BILINEN = {};

// Ekranlar: < 768 çubuk GÖRÜNÜR; 768–899 tablet (topbar), ≥ 900 sidebar
const EKRANLAR = [
    { w: 320, h: 568 }, { w: 360, h: 740 }, { w: 390, h: 844 }, { w: 430, h: 932 },
    { w: 767, h: 1024 }, { w: 768, h: 1024 }, { w: 900, h: 800 }, { w: 1024, h: 768 },
];
const MOBIL_MAX = 767;

// ── Sayaçlar ──────────────────────────────────────────────────────────────
const say = { A: { ok: 0, hata: 0 }, B: { ok: 0, hata: 0 }, xfail: 0, xpass: 0 };
const bulgular = {};   // bilinen id → ilk kanıt
function ok(grup, bag, ad, kosul, ipucu, bilinenId) {
    const etiket = `[${grup}] ${bag.padEnd(26)} ${ad}`;
    if (bilinenId && BILINEN[bilinenId] && BILINEN[bilinenId](bag_ctx)) {
        if (kosul) { say.xpass++; console.log(`${etiket.padEnd(96)} XPASS — ${bilinenId} düzelmiş, BILINEN'den çıkar`); }
        else {
            say.xfail++;
            if (!bulgular[bilinenId]) bulgular[bilinenId] = `${bag}: ${ipucu || ''}`;
            console.log(`${etiket.padEnd(96)} BİLİNEN HATA (${bilinenId})\n      → ${ipucu || ''}`);
        }
        return;
    }
    if (kosul) say[grup].ok++; else say[grup].hata++;
    if (!kosul || process.env.BOTTOMNAV_VERBOSE) {
        console.log(`${etiket.padEnd(96)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n      → ' + (ipucu || '')}`);
    }
}
let bag_ctx = {};

const r1 = (n) => Math.round(n * 10) / 10;
// Anahtar → tam ad (aria-label) — "Diğer" aktifken aria-label'da geçer
const SAYFA_ADI = { records: 'Yüklemeler', cikma: 'Çıkmalar', beyan: 'Beyanlar', kantar: 'Kantar', hks: 'Hal Bildirimi',
    rapor: 'Raporlar', mstok: 'Malzeme Stok', hesap: 'Hesap', ptak: 'Personel Takibi', defs: 'Tanımlar',
    users: 'Kullanıcılar', roles: 'Roller', audit: 'İşlem Geçmişi', backup: 'Veritabanı Yedekleri' };

// Göreli href'i uygulama köküne göre yola çevir (base_url '../' dahil)
function uygulamaYolu(href, sayfaYolu) {
    try {
        const u = new URL(href, 'http://uygulama.test/' + sayfaYolu);
        if (u.host !== 'uygulama.test') return null;   // dış bağlantı
        return u.pathname.replace(/^\/+/, '');
    } catch (e) { return null; }
}

// ── Tarayıcı içi ölçüm (tek evaluate) ─────────────────────────────────────
function olc(barSel) {
    const bar = document.querySelector(barSel);
    const vw = window.innerWidth, vh = window.innerHeight;
    const de = document.documentElement;
    const out = { vw, vh, cw: de.clientWidth, docScrollW: de.scrollWidth, docClientW: de.clientWidth, var: !!bar };
    if (!bar) return out;
    const cs = getComputedStyle(bar);
    const rb = bar.getBoundingClientRect();
    out.display = cs.display; out.position = cs.position; out.zIndex = cs.zIndex;
    out.visibility = cs.visibility;
    out.rect = { top: rb.top, bottom: rb.bottom, left: rb.left, right: rb.right, height: rb.height, width: rb.width };
    out.barScrollW = bar.scrollWidth; out.barClientW = bar.clientWidth;
    out.gorunur = cs.display !== 'none' && cs.visibility !== 'hidden' && rb.height > 0;
    out.isNav = bar.tagName === 'NAV' || bar.getAttribute('role') === 'navigation';
    out.ariaLabel = bar.getAttribute('aria-label') || '';

    // Görsel üst kenar: çubuk + taşan alt öğeler (yükseltilmiş daire vb.)
    let gorselUst = rb.top;
    bar.querySelectorAll('*').forEach(el => {
        const r = el.getBoundingClientRect();
        if (r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden') gorselUst = Math.min(gorselUst, r.top);
    });
    out.gorselUst = gorselUst;

    // Etkileşimli öğeler
    const aktifMi = (el) => el.getAttribute('aria-current') === 'page' || el.getAttribute('aria-current') === 'true'
        || /(^|\s)(active|is-active|aktif)(\s|$)/.test(el.className || '');
    // gorunur: gizli (Diğer'e düşmüş) aday bağlantılar da DOM'dadır — izin
    // değişmezi HEPSİNE uygulanır, "bölüm bağlantısı aktif" yalnız görünenlere.
    out.linkler = [...bar.querySelectorAll('a[href]')].map(a => {
        const r = a.getBoundingClientRect();
        return { href: a.getAttribute('href'), metin: a.textContent.trim().replace(/\s+/g, ' '),
                 gorunur: getComputedStyle(a).display !== 'none' && r.width > 0 && r.height > 0 };
    });
    out.hedefler = [...bar.querySelectorAll('a[href], button, [role="button"], [role="tab"], [tabindex]:not([tabindex="-1"])')]
        .filter(el => {
            const s = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
        })
        .map(el => {
            const r = el.getBoundingClientRect();
            const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
            const ust = document.elementFromPoint(Math.min(Math.max(cx, 0), vw - 1), Math.min(Math.max(cy, 0), vh - 1));
            // Metin taşıyan yaprak öğeler kırpılıyor mu?
            const kirpik = [];
            el.querySelectorAll('*').forEach(c => {
                if (c.children.length === 0 && c.textContent.trim() !== '') {
                    const cr = c.getBoundingClientRect();
                    if (c.scrollWidth > c.clientWidth + 1 && getComputedStyle(c).display !== 'inline')
                        kirpik.push(`"${c.textContent.trim()}" scrollWidth ${c.scrollWidth} > clientWidth ${c.clientWidth}`);
                    if (cr.left < r.left - 1 || cr.right > r.right + 1)
                        kirpik.push(`"${c.textContent.trim()}" ${Math.round(cr.left)}–${Math.round(cr.right)}px, öğe ${Math.round(r.left)}–${Math.round(r.right)}px`);
                    if (cr.left < -1 || cr.right > vw + 1)
                        kirpik.push(`"${c.textContent.trim()}" ekran dışı (${Math.round(cr.left)}–${Math.round(cr.right)}px, ekran ${vw})`);
                }
            });
            return {
                tag: el.tagName.toLowerCase(), href: el.getAttribute('href'),
                metin: el.textContent.trim().replace(/\s+/g, ' '),
                ad: (el.getAttribute('aria-label') || el.textContent || '').trim(),
                w: r.width, h: r.height, top: r.top, bottom: r.bottom, left: r.left, right: r.right,
                ortaUstte: ust === el || el.contains(ust),
                aktif: aktifMi(el), sinif: el.className || '', ariaCurrent: el.getAttribute('aria-current'),
                kirpik,
            };
        });

    // Depo rengi özellikleri (çubuk + ::before/::after)
    const renkler = [];
    for (const ps of [null, '::before', '::after']) {
        const s = getComputedStyle(bar, ps);
        renkler.push(s.borderTopColor, s.borderBottomColor, s.backgroundColor, s.boxShadow, s.backgroundImage, s.outlineColor);
    }
    out.renkler = renkler.join(' | ');
    out.borderTop = `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`;
    out.bodyAccent = getComputedStyle(document.body).getPropertyValue('--depot-accent').trim();
    out.paddingBottom = cs.paddingBottom;

    // Topbar / sidebar
    const tb = document.querySelector('.topbar'), sb = document.querySelector('.desktop-sidebar');
    out.topbarGorunur = !!tb && getComputedStyle(tb).display !== 'none';
    out.sidebarGorunur = !!sb && getComputedStyle(sb).display !== 'none';

    // .container alt boşluğu
    const ct = document.querySelector('.container');
    out.containerPB = ct ? getComputedStyle(ct).paddingBottom : null;
    out.bnH = getComputedStyle(document.body).getPropertyValue('--bn-h').trim();
    out.etiketPx = [...bar.querySelectorAll('.bn-label')].filter(l => l.getClientRects().length)
        .map(l => parseFloat(getComputedStyle(l).fontSize));
    return out;
}

// CSS kural taraması: çubuk (veya alt öğesi / .container) env(safe-area-inset-bottom) kullanıyor mu?
function guvenliAlanKurallari(barSel) {
    const sonuc = { bar: [], container: [], erisimHatasi: 0 };
    const tara = (kurallar) => {
        for (const k of kurallar) {
            if (k.cssRules && !k.selectorText) { tara(k.cssRules); continue; }
            if (!k.selectorText || !k.style) continue;
            const metin = k.style.cssText;
            if (!/safe-area-inset-bottom/.test(metin)) continue;
            const sel = k.selectorText;
            if (sel.includes(barSel)) sonuc.bar.push(`${sel} { ${metin} }`);
            if (/(^|[\s,])\.container(\s*[,{]|$)/.test(sel) || sel.trim() === '.container') sonuc.container.push(`${sel} { ${metin} }`);
        }
    };
    for (const ss of document.styleSheets) {
        try { tara(ss.cssRules); } catch (e) { sonuc.erisimHatasi++; }
    }
    return sonuc;
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    if (!NO_SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
    const shots = [];

    async function ac(kayit, ekran, secenek = {}) {
        const ctx = await browser.newContext({
            viewport: { width: ekran.w, height: ekran.h },
            deviceScaleFactor: 1, colorScheme: secenek.koyu ? 'dark' : 'light',
            javaScriptEnabled: secenek.js !== false,
            reducedMotion: secenek.azHareket ? 'reduce' : 'no-preference',
            // Mobil genişlikte gerçek telefon gibi: meta viewport + kaplama (overlay) kaydırma çubuğu.
            // (Aksi hâlde masaüstü kaydırma çubuğu + html{scrollbar-gutter:stable} 15px yer kaplar.)
            isMobile: ekran.w <= MOBIL_MAX, hasTouch: ekran.w <= MOBIL_MAX,
        });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error') hatalar.push('console: ' + m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        if (secenek.guvenliAlan) {
            const cdp = await ctx.newCDPSession(page);
            await cdp.send('Emulation.setSafeAreaInsetsOverride', { insets: { top: 0, left: 0, right: 0, bottom: secenek.guvenliAlan } });
        }
        if (secenek.tohum) await page.addInitScript(secenek.tohum.fn, secenek.tohum.arg);
        await page.goto('file://' + path.join(OUT, kayit.dosya));
        await page.waitForTimeout(400);   // animasyon/geçişler bitsin — erken ölçüm yanıltır
        return { ctx, page, hatalar };
    }

    const profilIzinli = (p) => M.profiller[p].izinli;

    // ─────────────────────────────────────────────────────────────────────
    // 1) HER SAYFA @390 — bağlantı değişmezi, aktif öğe, konsol, depo rengi, (B) etiketler
    // ─────────────────────────────────────────────────────────────────────
    console.log(`\n=== 1) Her profil × sayfa @390px (${M.sayfalar.length} sayfa) ===`);
    for (const k of M.sayfalar) {
        bag_ctx = { profil: k.profil, sayfa: k.sayfa, w: 390 };
        const bag = `${k.profil}/${k.sayfa}`;
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const m = await page.evaluate(olc, TASARIM.barSel);

        const P = M.profiller[k.profil];
        if (!P.cubuk_beklenen) {
            // Ana Sayfa hedefi (dashboard.read / first_allowed_page()) de izinli aday
            // sayfa da yok: çubuk HİÇ basılmaz (tek bağlantısı 403 olurdu) ve alt
            // boşluk kalkar.
            const yok = await page.evaluate(() => ({
                bnYok: document.body.classList.contains('bn-yok'),
                pb: parseFloat(getComputedStyle(document.querySelector('.container')).paddingBottom),
            }));
            ok('A', bag, 'gidilecek izinli sayfa yok → çubuk BASILMADI', !m.var, `${TASARIM.barSel} var`);
            ok('A', bag, 'çubuksuz: body.bn-yok + alt boşluk kalktı (≤ 16px)', yok.bnYok && yok.pb <= 16,
                `bn-yok=${yok.bnYok}, .container padding-bottom ${yok.pb}px`);
            ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
            await ctx.close();
            continue;
        }
        ok('A', bag, 'çubuk DOM\'da var', m.var, `${TASARIM.barSel} bulunamadı`);
        if (!m.var) { await ctx.close(); continue; }

        // A — bağlantı değişmezi: her bağlantı profilin açabildiği sayfaya
        const izinli = profilIzinli(k.profil);
        const kotu = m.linkler.map(l => ({ ...l, yol: uygulamaYolu(l.href, k.yol) }))
            .filter(l => l.yol === null || !(l.yol in izinli) || !izinli[l.yol]);
        const kotuAciklama = kotu.map(l => {
            const g = M.gates[l.yol];
            return `"${l.metin}" → ${l.yol ?? l.href} ${g ? `(kapı ${g.ref}: ${g.kosul})` : '(kapı tablosunda YOK — bottomnav_render.php $GATES\'e ekle)'}`;
        }).join('; ');
        ok('A', bag, 'her bağlantı profilin AÇABİLDİĞİ sayfaya gider', kotu.length === 0,
            kotuAciklama + ` — profil yetkileri: ${M.profiller[k.profil].perms.join(',')}; first_allowed_page()=${M.profiller[k.profil].first_allowed_page}`,
            'BN-PDKS-ANASAYFA-403');
        ok('A', bag, 'en az bir gezinme bağlantısı var', m.linkler.length >= 1, `${m.linkler.length} bağlantı`);
        ok('A', bag, 'nav işaretlemesi (nav/role=navigation + aria-label)', m.isNav && m.ariaLabel !== '',
            `tag/role nav=${m.isNav}, aria-label="${m.ariaLabel}"`);
        const adsiz = m.hedefler.filter(h => h.ad === '');
        ok('A', bag, 'her dokunma hedefinin erişilebilir adı var', adsiz.length === 0, `${adsiz.length} adsız hedef`);

        // A — aktif öğe
        const aktifler = m.hedefler.filter(h => h.aktif);
        const aktifYollar = aktifler.map(h => h.href ? uygulamaYolu(h.href, k.yol) : `<${h.tag}>`);
        ok('A', bag, 'en fazla BİR öğe aktif', aktifler.length <= 1,
            `${aktifler.length} aktif öğe: ${aktifYollar.join(', ')}`, 'BN-HKS-IKI-AKTIF');
        const bolumLinki = m.linkler.some(l => l.gorunur && uygulamaYolu(l.href, k.yol) === k.bolum);
        if (bolumLinki) {
            ok('A', bag, `bölüm bağlantısı (${k.bolum}) aktif`,
                aktifler.length >= 1 && aktifYollar.includes(k.bolum),
                `çubukta ${k.bolum} bağlantısı var ama aktif: [${aktifYollar.join(', ') || 'hiçbiri'}] — bölüm eşlemesi ${M.aktif_ref}`,
                'BN-MALIYET-AKTIF-YOK');
        }
        const yanlisAktif = aktifYollar.filter(y => !y.startsWith('<') && y !== k.bolum);
        ok('A', bag, 'aktif bağlantı başka bir bölüme işaret etmiyor', yanlisAktif.length === 0,
            `aktif: ${yanlisAktif.join(', ')} (beklenen bölüm ${k.bolum})`, 'BN-HKS-IKI-AKTIF');

        // A — konsol
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));

        // A — depo rengi
        if (k.depo_rengi) {
            ok('A', bag, 'body --depot-accent = depo rengi', m.bodyAccent.toLowerCase() === k.depo_rengi.toLowerCase(),
                `body "${m.bodyAccent}", beklenen ${k.depo_rengi}`);
            const hex = k.depo_rengi.replace('#', '');
            const rgb = `rgb(${parseInt(hex.slice(0, 2), 16)}, ${parseInt(hex.slice(2, 4), 16)}, ${parseInt(hex.slice(4, 6), 16)})`;
            ok('A', bag, 'çubuk depo rengini gösteriyor', m.renkler.includes(rgb),
                `${rgb} çubukta yok — renkler: ${m.renkler}`);
            // Değişkeni değiştir → çubuk takip etmeli (sabit renk değil, DEĞİŞKEN)
            await page.evaluate(() => {
                document.body.style.setProperty('--depot-accent', '#123456');
                document.body.style.setProperty('--depot-accent-rgb', '18,52,86');
            });
            await page.waitForTimeout(350);   // border-top-color geçişi .2s
            const m2 = await page.evaluate(olc, TASARIM.barSel);
            ok('A', bag, 'çubuk rengi --depot-accent DEĞİŞKENİNİ izliyor', m2.renkler.includes('rgb(18, 52, 86)'),
                `değişken #123456 yapıldı, çubuk renkleri: ${m2.renkler}`);
        }

        // B — güncel tasarım (@390: 4 slot görünür)
        const d = await page.evaluate((T) => {
            const bar = document.querySelector(T.barSel);
            const gor = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0;
            const home = bar.querySelector(T.homeSel);
            const more = bar.querySelector(T.moreSel);
            const cur = [...bar.querySelectorAll('[aria-current="page"]')].filter(gor);
            return {
                home: home ? home.getAttribute('href') : null,
                homeAktif: !!home && (home.getAttribute('aria-current') === 'page' || home.classList.contains(T.aktifSinif)),
                slotlar: [...bar.querySelectorAll(T.slotSel)].filter(gor).map(a => a.getAttribute('data-nav')),
                digerGorunur: gor(more),
                digerLabel: more ? more.getAttribute('aria-label') : null,
                digerIkon: more ? more.querySelector('img.ni').getAttribute('src') : null,
                aktifler: cur.map(el => ({ nav: el.getAttribute('data-nav') || (el.id === 'bnMore' ? 'more' : '?'),
                                          href: el.getAttribute('href'), sinif: el.classList.contains(T.aktifSinif) })),
                isActiveSay: [...bar.querySelectorAll('.' + T.aktifSinif)].filter(gor).length,
                imgler: [...bar.querySelectorAll('img')].map(i => [i.getAttribute('width'), i.getAttribute('height'), i.getAttribute('alt'), i.getAttribute('src')]),
            };
        }, TASARIM);
        const homeBek = P.home_hedef;
        ok('B', bag, `Ana Sayfa hedefi = ${homeBek ?? 'YOK (çizilmez)'}`,
            homeBek === null ? d.home === null : (d.home !== null && uygulamaYolu(d.home, k.yol) === homeBek),
            `ölçülen ${d.home} (dashboard.read ? index.php : first_allowed_page())`);
        ok('B', bag, 'Ana Sayfa YALNIZ index.php\'de aktif', d.homeAktif === (k.sayfa === 'home'), `homeAktif=${d.homeAktif}`);
        const bekSlot = Math.min(4, P.adaylar.length);
        ok('B', bag, `@390 görünen slot sayısı ${bekSlot}`, d.slotlar.length === bekSlot, `[${d.slotlar.join(', ')}]`);
        ok('B', bag, 'slotlar yalnız izinli adaylar, tekrar yok',
            d.slotlar.every(x => P.adaylar.includes(x)) && new Set(d.slotlar).size === d.slotlar.length, `[${d.slotlar.join(', ')}]`);
        ok('B', bag, `"Diğer" ${P.adaylar.length > 4 ? 'VAR' : 'YOK'} (@390, ${P.adaylar.length} aday)`, d.digerGorunur === (P.adaylar.length > 4));
        // Tek aktif öğe: aria-current="page" + .is-active; hangisi olacağı sayfanın bölümünden
        const bekAktif = k.anahtar === 'home' ? (homeBek === 'index.php' ? 'home' : null)
            : (k.anahtar && P.adaylar.includes(k.anahtar) ? k.anahtar : null);
        const olcAktif = d.aktifler.length === 1 ? (d.aktifler[0].nav === 'more' ? 'more' : d.aktifler[0].nav) : null;
        const aktifDogru = bekAktif === null ? d.aktifler.length === 0
            : d.aktifler.length === 1 && d.aktifler[0].sinif
              && (olcAktif === bekAktif || (olcAktif === 'more' && d.digerLabel.includes(SAYFA_ADI[bekAktif])));
        ok('B', bag, `tek aktif öğe (aria-current="page") = ${bekAktif ?? 'hiçbiri'}`, aktifDogru && d.isActiveSay === d.aktifler.length,
            `aktif: ${JSON.stringify(d.aktifler)}, .is-active görünen ${d.isActiveSay}, Diğer "${d.digerLabel}"`);
        if (k.sayfa === 'hks') ok('B', bag, 'Hal Kayıt: YALNIZ Bildirim (hks) aktif — Ana Sayfa değil', olcAktif === 'hks' && !d.homeAktif, JSON.stringify(d.aktifler));
        if (k.sayfa === 'maliyet') ok('B', bag, 'maliyet_*: Raporlar (rapor) aktif', olcAktif === 'rapor' || (olcAktif === 'more' && d.digerLabel.includes('Raporlar')), JSON.stringify(d.aktifler));
        ok('B', bag, 'ikonlar <img width=42/18 alt=""> assets/nav-icons/*.svg?v=',
            d.imgler.every(([w, h, alt, src]) => w === h && ['42', '18'].includes(w) && alt === '' && /assets\/nav-icons\/[a-z]+\.svg\?v=\d+$/.test(src)),
            JSON.stringify(d.imgler.filter(([w, h, alt, src]) => !(w === h && alt === '' && /nav-icons/.test(src)))));
        await ctx.close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2) DÜZEN — temsilci sayfa (+ Hal Kayıt) × tüm genişlikler
    // ─────────────────────────────────────────────────────────────────────
    console.log('\n=== 2) Düzen ölçümleri × genişlik ===');
    const temsilci = [];
    for (const p of Object.keys(M.profiller)) {
        const sayfalar = M.sayfalar.filter(s => s.profil === p);
        const t = sayfalar.find(s => s.sayfa === 'home') || sayfalar[0];
        if (t) temsilci.push(t);
        const hks = sayfalar.find(s => s.sayfa === 'hks');
        if (hks) temsilci.push(hks);
    }
    // Tam 4 adaylı rolde kantar 4. slottadır: dar ekranda "Diğer" onu taşımalı
    const dortKantar = M.sayfalar.find(s => s.profil === 'dort_sayfa' && s.sayfa === 'kantar');
    if (dortKantar) temsilci.push(dortKantar);
    const olcumTablosu = [];
    for (const k of temsilci) {
        for (const ekran of EKRANLAR) {
            bag_ctx = { profil: k.profil, sayfa: k.sayfa, w: ekran.w };
            const bag = `${k.profil}/${k.sayfa}@${ekran.w}`;
            const { ctx, page, hatalar } = await ac(k, ekran);
            let m = await page.evaluate(olc, TASARIM.barSel);
            const mobil = ekran.w <= MOBIL_MAX;
            const P = M.profiller[k.profil];
            if (!P.cubuk_beklenen) {
                ok('A', bag, 'çubuk basılmadı (izinli hedef yok)', !m.var, 'çubuk var');
                ok('A', bag, 'yatay taşma yok', m.docScrollW <= m.docClientW + 1, `scrollWidth ${m.docScrollW} > clientWidth ${m.docClientW}`);
                ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
                await ctx.close();
                continue;
            }

            // A — kırılım noktası görünürlüğü
            if (!mobil) {
                ok('A', bag, 'çubuk ≥768px\'te GİZLİ', !m.gorunur, `display=${m.display}, yükseklik ${r1(m.rect.height)}px`);
                if (ekran.w < 900) ok('A', bag, '768–899: topbar görünür (gezinme kaybolmuyor)', m.topbarGorunur, 'topbar gizli');
                else ok('A', bag, '≥900: sidebar görünür (gezinme kaybolmuyor)', m.sidebarGorunur, 'sidebar gizli');
                ok('A', bag, 'yatay taşma yok', m.docScrollW <= m.docClientW + 1, `scrollWidth ${m.docScrollW} > clientWidth ${m.docClientW}`);
            } else {
                ok('A', bag, 'çubuk <768px\'te GÖRÜNÜR', m.gorunur, `display=${m.display}`);
                ok('A', bag, 'position: fixed', m.position === 'fixed', `position=${m.position}`);
                ok('A', bag, 'çubuk ekranın ALTINA yapışık', Math.abs(m.rect.bottom - m.vh) <= 1,
                    `alt kenar ${r1(m.rect.bottom)}px, ekran ${m.vh}px`);
                ok('A', bag, 'çubuk tam genişlik ve ekranda', m.rect.left >= -1 && m.rect.right <= m.vw + 1 && m.rect.width >= m.cw - 1 && m.gorselUst >= 0,
                    `sol ${r1(m.rect.left)}, sağ ${r1(m.rect.right)}, genişlik ${r1(m.rect.width)}/${m.cw} (innerWidth ${m.vw}), görsel üst ${r1(m.gorselUst)}`);
                ok('A', bag, 'z-index 500 (CLAUDE.md katman tablosu)', m.zIndex === '500', `z-index=${m.zIndex}`);
                ok('A', bag, 'yatay taşma yok (sayfa)', m.docScrollW <= m.docClientW + 1, `scrollWidth ${m.docScrollW} > clientWidth ${m.docClientW}`);
                ok('A', bag, 'yatay taşma yok (çubuk)', m.barScrollW <= m.barClientW + 1, `çubuk scrollWidth ${m.barScrollW} > clientWidth ${m.barClientW}`);
                const kucuk = m.hedefler.filter(h => h.w < 44 || h.h < 44);
                ok('A', bag, 'her dokunma hedefi ≥ 44×44px', kucuk.length === 0,
                    kucuk.map(h => `"${h.metin}" ${r1(h.w)}×${r1(h.h)}`).join('; '));
                const kapali = m.hedefler.filter(h => !h.ortaUstte);
                ok('A', bag, 'hedeflerin ortası tıklanabilir (üstü kapalı değil)', kapali.length === 0, kapali.map(h => `"${h.metin}"`).join(', '));
                const kirpik = m.hedefler.flatMap(h => h.kirpik);
                ok('A', bag, 'etiketler kırpılmıyor / taşmıyor', kirpik.length === 0, kirpik.join('; '));
                const disari = m.hedefler.filter(h => h.left < -1 || h.right > m.vw + 1 || h.bottom > m.vh + 1);
                ok('A', bag, 'hedefler ekran içinde', disari.length === 0, disari.map(h => `"${h.metin}" ${r1(h.left)}–${r1(h.right)}`).join('; '));

                // A — modal (≥600) çubuğun ÜSTÜNDE
                const modal = await page.evaluate((barSel) => {
                    const o = document.createElement('div');
                    o.className = 'pm-overlay'; o.id = '__testModal';
                    o.innerHTML = '<div class="pm-dialog"><div class="pm-body">test</div></div>';
                    document.body.appendChild(o);
                    const b = document.querySelector(barSel).getBoundingClientRect();
                    const x = b.left + b.width / 2, y = b.bottom - 4;
                    const ust = document.elementFromPoint(x, y);
                    const sonuc = { z: getComputedStyle(o).zIndex, ustte: o.contains(ust), ust: ust ? (ust.className || ust.tagName) : null };
                    o.remove();
                    return sonuc;
                }, TASARIM.barSel);
                ok('A', bag, 'modal (.pm-overlay, z≥600) çubuğu örtüyor', modal.ustte && +modal.z >= 600,
                    `overlay z=${modal.z}, çubuk noktasındaki öğe: ${modal.ust}`);

                // A — içerik çubuğun arkasında kalmıyor (en alta kaydır)
                await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
                await page.waitForTimeout(80);
                const icerik = await page.evaluate((barSel) => {
                    const son = document.getElementById('son-oge');
                    const bar = document.querySelector(barSel);
                    let ust = bar.getBoundingClientRect().top;
                    bar.querySelectorAll('*').forEach(el => { const r = el.getBoundingClientRect(); if (r.width && r.height) ust = Math.min(ust, r.top); });
                    const r = son.getBoundingClientRect();
                    return { sonAlt: r.bottom, barUst: bar.getBoundingClientRect().top, gorselUst: ust, barAlt: bar.getBoundingClientRect().bottom, vh: innerHeight };
                }, TASARIM.barSel);
                ok('A', bag, 'son içerik çubuğun (görsel) üstünde bitiyor', icerik.sonAlt <= icerik.gorselUst + 1,
                    `#son-oge alt ${r1(icerik.sonAlt)}px > çubuk görsel üst ${r1(icerik.gorselUst)}px (çubuk kutusu üst ${r1(icerik.barUst)}px) → ${r1(icerik.sonAlt - icerik.gorselUst)}px örtülü`,
                    'BN-HKS-IFRAME-ORTUSME');
                const barPos = await page.evaluate((s) => document.querySelector(s).getBoundingClientRect().bottom, TASARIM.barSel);
                ok('A', bag, 'kaydırmadan sonra çubuk hâlâ altta', Math.abs(barPos - m.vh) <= 1, `alt ${r1(barPos)} / ${m.vh}`);

                // B — güncel yükseklik + --bn-h sözleşmesi
                ok('B', bag, `çubuk yüksekliği ${TASARIM.yukseklik[0]}–${TASARIM.yukseklik[1]}px`,
                    m.rect.height >= TASARIM.yukseklik[0] && m.rect.height <= TASARIM.yukseklik[1], `ölçülen ${r1(m.rect.height)}px`);
                ok('B', bag, '--bn-h = ölçülen çubuk kutusu (±1.5px) ve görsel üst kutunun içinde',
                    Math.abs(parseFloat(m.bnH) - m.rect.height) <= 1.5 && m.gorselUst >= m.rect.top - 1,
                    `--bn-h "${m.bnH}", kutu ${r1(m.rect.height)}px, görsel üst ${r1(m.vh - m.gorselUst)}px`);
                // B — slot sayısı / Diğer / tek aktif (genişliğe göre)
                const g = await page.evaluate((T) => {
                    const bar = document.querySelector(T.barSel);
                    const gor = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0;
                    const more = bar.querySelector(T.moreSel);
                    return {
                        slotlar: [...bar.querySelectorAll(T.slotSel)].filter(gor).map(a => a.getAttribute('data-nav')),
                        diger: gor(more), digerLabel: more ? more.getAttribute('aria-label') : '',
                        cur: [...bar.querySelectorAll('[aria-current="page"]')].filter(gor).map(el => el.getAttribute('data-nav') || el.id),
                    };
                }, TASARIM);
                const n = ekran.w < TASARIM.slotEsik ? 3 : 4;
                ok('B', bag, `görünen slot = ${Math.min(n, P.adaylar.length)} (${ekran.w < 390 ? '<390 → 3' : '≥390 → 4'})`,
                    g.slotlar.length === Math.min(n, P.adaylar.length), `[${g.slotlar.join(', ')}]`);
                ok('B', bag, `"Diğer" ${P.adaylar.length > n ? 'görünür' : 'yok'} (${P.adaylar.length} aday)`, g.diger === (P.adaylar.length > n));
                const bek = k.anahtar === 'home' ? (P.home_hedef === 'index.php' ? 'home' : null)
                    : (k.anahtar && P.adaylar.includes(k.anahtar) ? k.anahtar : null);
                const tamam = bek === null ? g.cur.length === 0
                    : g.cur.length === 1 && (g.cur[0] === bek || (g.cur[0] === 'bnMore' && !g.slotlar.includes(bek) && g.digerLabel.includes(SAYFA_ADI[bek])));
                ok('B', bag, `tek aria-current = ${bek ?? 'hiçbiri'} (slotta değilse "Diğer" onu taşır)`, tamam,
                    `aria-current: [${g.cur.join(', ')}], Diğer "${g.digerLabel}", slotlar [${g.slotlar.join(', ')}]`);
                ok('B', bag, `etiket yazısı ≥ ${TASARIM.minEtiketPx}px`, m.etiketPx.length > 0 && Math.min(...m.etiketPx) >= TASARIM.minEtiketPx,
                    `boyutlar: ${m.etiketPx.join(', ')}`);
                // B — --bn-h'a bağlı öğeler çubuğun arkasında kalmıyor
                const bag2 = await page.evaluate((barSel) => {
                    const bar = document.querySelector(barSel);
                    let ust = bar.getBoundingClientRect().top;
                    bar.querySelectorAll('*').forEach(el => { const r = el.getBoundingClientRect(); if (r.width && r.height) ust = Math.min(ust, r.top); });
                    const bb = document.createElement('div');
                    bb.className = 'bb-bar'; bb.textContent = 'Toplu bildirim test';
                    document.body.appendChild(bb);
                    const bbAlt = bb.getBoundingClientRect().bottom;
                    bb.remove();
                    const kayit = document.createElement('div');
                    kayit.setAttribute('data-record-id', '1');
                    document.body.appendChild(kayit);
                    const smb = parseFloat(getComputedStyle(kayit).scrollMarginBottom);
                    kayit.remove();
                    return { ust, bbAlt, smb, barH: bar.getBoundingClientRect().height };
                }, TASARIM.barSel);
                ok('B', bag, '.bb-bar (toplu bildirim şeridi) çubuğun üstünde', bag2.bbAlt <= bag2.ust + 1,
                    `.bb-bar alt ${r1(bag2.bbAlt)}px > çubuk görsel üst ${r1(bag2.ust)}px`);
                ok('B', bag, '[data-record-id] scroll-margin-bottom ≥ çubuk', bag2.smb >= bag2.barH,
                    `scroll-margin-bottom ${bag2.smb}px < çubuk ${r1(bag2.barH)}px`);
                olcumTablosu.push({ bag, yukseklik: r1(m.rect.height), gorselUst: r1(m.vh - m.gorselUst),
                    hedefler: m.hedefler.map(h => `${h.metin}:${r1(h.w)}×${r1(h.h)}`).join(' '),
                    icerikPayi: r1(icerik.gorselUst - icerik.sonAlt) });
            }
            ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));

            if (!NO_SHOTS) {
                const f = path.join(SHOTS, `${ekran.w}_${k.profil}_${k.sayfa}.png`);
                await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
                await page.screenshot({ path: f });
                shots.push(f);
            }
            await ctx.close();
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3) GÜVENLİ ALAN — CSS kuralı + iOS ev göstergesi benzetimi (inset 34px)
    // ─────────────────────────────────────────────────────────────────────
    console.log('\n=== 3) Güvenli alan (safe-area-inset-bottom) ===');
    for (const k of temsilci) {
        bag_ctx = { profil: k.profil, sayfa: k.sayfa, w: 390 };
        const bag = `${k.profil}/${k.sayfa}@390+34`;
        if (!M.profiller[k.profil].cubuk_beklenen) continue;   // çubuk yok (bkz. 1. bölüm)
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 }, { guvenliAlan: 34 });
        const kural = await page.evaluate(guvenliAlanKurallari, TASARIM.barSel);
        ok('A', bag, 'çubuk kuralında env(safe-area-inset-bottom) var', kural.bar.length > 0,
            `bulunan kural yok (erişilemeyen stil sayfası: ${kural.erisimHatasi})`);
        ok('A', bag, '.container kuralında env(safe-area-inset-bottom) var', kural.container.length > 0, 'bulunan kural yok');
        const m = await page.evaluate(olc, TASARIM.barSel);
        const altHedef = Math.max(...m.hedefler.map(h => h.bottom));
        ok('A', bag, 'dokunma hedefleri ev göstergesi bölgesinin (alt 34px) DIŞINDA', altHedef <= m.vh - 34 + 1,
            `en alttaki hedef ${r1(altHedef)}px, sınır ${m.vh - 34}px (çubuk padding-bottom ${m.paddingBottom})`);
        await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
        await page.waitForTimeout(80);
        const ic = await page.evaluate((barSel) => {
            const son = document.getElementById('son-oge');
            const bar = document.querySelector(barSel);
            let ust = bar.getBoundingClientRect().top;
            bar.querySelectorAll('*').forEach(el => { const r = el.getBoundingClientRect(); if (r.width && r.height) ust = Math.min(ust, r.top); });
            return { sonAlt: son.getBoundingClientRect().bottom, gorselUst: ust };
        }, TASARIM.barSel);
        ok('A', bag, 'güvenli alan varken de son içerik çubuğun üstünde', ic.sonAlt <= ic.gorselUst + 1,
            `#son-oge alt ${r1(ic.sonAlt)}px > çubuk görsel üst ${r1(ic.gorselUst)}px → ${r1(ic.sonAlt - ic.gorselUst)}px örtülü`,
            'BN-HKS-IFRAME-ORTUSME');
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        if (!NO_SHOTS && k.profil === 'admin') {
            const f = path.join(SHOTS, `390_${k.profil}_${k.sayfa}_guvenli-alan-34.png`);
            await page.screenshot({ path: f }); shots.push(f);
        }
        await ctx.close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4) SUNUCU SIRASI (JS KAPALI) — soğuk başlangıç + 'asya_nav' çerezi
    // ─────────────────────────────────────────────────────────────────────
    console.log('\n=== 4) Sunucu sırası (JS kapalı): soğuk başlangıç + asya_nav çerezi ===');
    const sunucuOlc = (T) => {
        const bar = document.querySelector(T.barSel);
        const ovl = document.querySelector(T.sheetOvlSel);
        return {
            slotlar: [...bar.querySelectorAll(T.slotSel)].filter(a => !a.hidden).map(a => a.getAttribute('data-nav')),
            gizli: [...bar.querySelectorAll(T.slotSel)].filter(a => a.hidden).map(a => a.getAttribute('data-nav')),
            tumLink: [...bar.querySelectorAll('a[href]'), ...(ovl ? ovl.querySelectorAll('a[href]') : [])].map(a => a.getAttribute('href')),
            karolar: ovl ? [...ovl.querySelectorAll('.bn-tile')].map(t => t.getAttribute('data-nav')) : null,
            sabit: ovl ? [...ovl.querySelectorAll('.bn-tile.pinned')].map(t => t.getAttribute('data-nav')) : [],
            dataSabit: bar.getAttribute('data-sabit'),
            html: bar.outerHTML + (ovl ? ovl.outerHTML : ''),
        };
    };
    const ilkSayfalar = Object.keys(M.profiller).map(p => M.sayfalar.find(s => s.profil === p && !s.senaryo)).filter(Boolean);
    for (const k of [...ilkSayfalar, ...M.sayfalar.filter(s => s.senaryo)]) {
        const P = M.profiller[k.profil];
        if (!P.cubuk_beklenen) continue;
        bag_ctx = { profil: k.profil, sayfa: k.sayfa, w: 390 };
        const bag = `${k.profil}/${k.sayfa}`;
        const { ctx, page } = await ac(k, { w: 390, h: 844 }, { js: false });
        const d = await page.evaluate(sunucuOlc, TASARIM);
        const bekSlot = k.senaryo ? k.senaryo.slotlar : P.soguk_slotlar;
        ok('B', bag, `sunucu slot sırası = [${bekSlot.join(', ')}]${k.senaryo ? ` (çerez "${k.senaryo.cerez.slice(0, 40)}…")` : ' (soğuk başlangıç)'}`,
            JSON.stringify(d.slotlar) === JSON.stringify(bekSlot), `ölçülen [${d.slotlar.join(', ')}]`);
        ok('B', bag, 'gizli adaylar + slotlar = izinli adayların TAMAMI',
            JSON.stringify([...d.slotlar, ...d.gizli].sort()) === JSON.stringify([...P.adaylar].sort()),
            `slot [${d.slotlar}] gizli [${d.gizli}] beklenen [${P.adaylar}]`);
        const kotu = d.tumLink.map(h => uygulamaYolu(h, k.yol)).filter(y => !P.izinli[y]);
        ok('B', bag, 'çubuk + "Diğer" sayfasındaki HER bağlantı izinli', kotu.length === 0, kotu.join(', '));
        if (d.karolar !== null) {
            ok('B', bag, '"Diğer" yalnız izinli sayfaları listeler (hepsi, fazlası yok)',
                JSON.stringify(d.karolar) === JSON.stringify(P.adaylar), `karolar [${d.karolar}] beklenen [${P.adaylar}]`);
        }
        if (k.senaryo) {
            ok('B', bag, `sabitlenen = [${k.senaryo.sabit.join(', ')}] (çerez p: → karo rozeti + data-sabit)`,
                JSON.stringify(d.sabit) === JSON.stringify(k.senaryo.sabit) && d.dataSabit === k.senaryo.sabit.join(','),
                `karo [${d.sabit}], data-sabit "${d.dataSabit}"`);
            if (k.senaryo.ad === 'cerez_sahte') {
                const yasak = ['users.php', 'roles.php', 'audit.php', 'admin_db_backups.php', 'xyz', '../index'].filter(x => d.html.includes(x));
                ok('B', bag, 'sahte çerezdeki yasak/bilinmeyen anahtarlar HİÇ çizilmedi', yasak.length === 0, yasak.join(', '));
            }
        }
        await ctx.close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5) DAVRANIŞ (JS) — sıralama, histerezis, Diğer sayfası, sabitleme, çerez
    // ─────────────────────────────────────────────────────────────────────
    console.log('\n=== 5) Davranış: sıralama / Diğer / Sabitle / çerez / tema / hareket ===');
    const sayfaBul = (p, s) => M.sayfalar.find(x => x.profil === p && x.sayfa === s);
    const gorunenSlotlar = (page) => page.evaluate((T) => [...document.querySelectorAll(T.barSel + ' ' + T.slotSel)]
        .filter(a => getComputedStyle(a).display !== 'none').map(a => a.getAttribute('data-nav')), TASARIM);
    const depo = (page, anahtar) => page.evaluate((k) => { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return 'HATA'; } }, anahtar);
    const tohum = (kayitlar) => ({
        fn: (arg) => { try { if (!localStorage.getItem(arg.k)) localStorage.setItem(arg.k, JSON.stringify(arg.v)); } catch (e) {} },
        arg: { k: 'asya_nav_kullanim_1', v: kayitlar },
    });
    const ziyaret = (sayilar) => {
        const simdi = Date.now(), out = [];
        for (const [k, n] of Object.entries(sayilar)) for (let i = 0; i < n; i++) out.push([k, simdi - (i + 1) * 60 * 1000]);   // dakikalar önce: sönüm ihmal edilebilir
        return out.sort((a, b) => a[1] - b[1]);
    };

    // 5a — soğuk başlangıç: Ana Sayfa ziyareti SAYILMAZ, sıra değişmez
    {
        const k = sayfaBul('operator', 'home'); bag_ctx = { profil: 'operator', sayfa: 'home', w: 390 };
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const sl = await gorunenSlotlar(page);
        ok('B', 'operator/home', 'soğuk başlangıç (çerez + kullanım yok): Yüklemeler, Bildirim, Personel, Raporlar',
            JSON.stringify(sl) === JSON.stringify(['records', 'hks', 'ptak', 'rapor']), `[${sl}]`);
        const kul = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/home', 'Ana Sayfa ziyareti sayılmadı', Array.isArray(kul) && kul.length === 0, JSON.stringify(kul));
        ok('A', 'operator/home', 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5b — ziyaret kaydı + yeniden sıralama (en zayıf slotun YERİNE girer)
    {
        const k = sayfaBul('operator', 'kantar'); bag_ctx = { profil: 'operator', sayfa: 'kantar', w: 390 };
        const { ctx, page } = await ac(k, { w: 390, h: 844 });
        const kul = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/kantar', 'ziyaret cihaza yazıldı (asya_nav_kullanim_<uid>)',
            Array.isArray(kul) && kul.length === 1 && kul[0][0] === 'kantar' && Math.abs(kul[0][1] - Date.now()) < 60000, JSON.stringify(kul));
        const sl = await gorunenSlotlar(page);
        ok('B', 'operator/kantar', 'ilk ziyaret (1 > 0×1,25+0,5) en zayıf slotu (Raporlar) yerinde değiştirdi',
            JSON.stringify(sl) === JSON.stringify(['records', 'hks', 'ptak', 'kantar']), `[${sl}]`);
        await page.reload(); await page.waitForTimeout(400);
        const kul2 = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/kantar', 'aynı bölümde 30 dk içinde yenileme TEK ziyaret', Array.isArray(kul2) && kul2.length === 1, JSON.stringify(kul2));
        await ctx.close();
    }
    // 5c — histerezis: puan > en zayıf × 1,25 + 0,5 olmadıkça yer değişmez
    for (const [ad, sayilar, bek] of [
        ['kantar 2 > rapor 1×1,25+0,5 → girer (rapor\'un yerine)', { records: 3, hks: 3, ptak: 3, rapor: 1, kantar: 2 }, ['records', 'hks', 'ptak', 'kantar']],
        ['kantar 1 ≤ rapor 1×1,25+0,5 → girmez', { records: 3, hks: 3, ptak: 3, rapor: 1, kantar: 1 }, ['records', 'hks', 'ptak', 'rapor']],
        // Eşik tam 1,25 çarpanına duyarlı: 3 > 2×1,25+0,5 (=3) DEĞİL; çarpan 1 olsaydı girerdi
        ['kantar 3 ≤ rapor 2×1,25+0,5 = 3 → girmez (sınır)', { records: 4, hks: 4, ptak: 4, rapor: 2, kantar: 3 }, ['records', 'hks', 'ptak', 'rapor']],
        // beyan en zayıfın (rapor, eşit puanda en düşük öncelik) yerine, mstok
        // sonraki en zayıfın (ptak) yerine girer; records/hks YERİNİ korur.
        ['çok kullanılanlar en zayıfların yerine girer, kalanlar yerini korur', { beyan: 6, mstok: 5, records: 1 }, ['records', 'hks', 'mstok', 'beyan']],
    ]) {
        const k = sayfaBul('operator', 'home'); bag_ctx = { profil: 'operator', sayfa: 'home', w: 390 };
        const { ctx, page } = await ac(k, { w: 390, h: 844 }, { tohum: tohum(ziyaret(sayilar)) });
        const sl = await gorunenSlotlar(page);
        ok('B', 'operator/home', `histerezis: ${ad}`, JSON.stringify(sl) === JSON.stringify(bek), `[${sl}] beklenen [${bek}]`);
        await ctx.close();
    }
    // 5d — "Diğer" sayfası: aç/kapat (Esc, ✕, arka plan), odak dönüşü, odak tuzağı, yalnız izinli sayfalar
    {
        const k = sayfaBul('admin', 'home'); bag_ctx = { profil: 'admin', sayfa: 'home', w: 390 };
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const bag = 'admin/home Diğer';
        const durum = () => page.evaluate((T) => {
            const o = document.querySelector(T.sheetOvlSel), m = document.querySelector(T.moreSel);
            const bar = document.querySelector(T.barSel).getBoundingClientRect();
            const ust = document.elementFromPoint(bar.left + bar.width / 2, bar.bottom - 20);
            return { acik: !o.hidden && getComputedStyle(o).display !== 'none', exp: m.getAttribute('aria-expanded'),
                     odak: document.activeElement ? (document.activeElement.id || document.activeElement.className) : null,
                     odakIcinde: o.contains(document.activeElement), z: getComputedStyle(o).zIndex, cubukUstu: o.contains(ust),
                     karolar: [...o.querySelectorAll('.bn-tile .go')].map(a => a.getAttribute('href')) };
        }, TASARIM);
        await page.click(TASARIM.moreSel); await page.waitForTimeout(400);
        let d = await durum();
        ok('B', bag, 'Diğer tıklanınca açılır (aria-expanded=true, odak Kapat\'ta)', d.acik && d.exp === 'true' && d.odak === 'bnSheetClose', JSON.stringify(d));
        ok('B', bag, 'alt sayfa z-index ≥ 600 ve çubuğun ÜSTÜNDE', +d.z >= 600 && d.cubukUstu, `z=${d.z}, üstte=${d.cubukUstu}`);
        const P = M.profiller.admin;
        const karoYol = d.karolar.map(h => uygulamaYolu(h, k.yol));
        ok('B', bag, 'karolar = izinli adayların sayfaları (sidebar sırası)',
            JSON.stringify(karoYol) === JSON.stringify(P.adaylar.map(x => M.anahtar_sayfa[x])), `[${karoYol}]`);
        for (let i = 0; i < 25; i++) await page.keyboard.press('Tab');
        d = await durum();
        ok('B', bag, 'Tab ile odak alt sayfanın içinde kalır', d.odakIcinde, `odak: ${d.odak}`);
        await page.keyboard.press('Escape'); await page.waitForTimeout(100);
        d = await durum();
        ok('B', bag, 'Esc kapatır, odak Diğer düğmesine döner', !d.acik && d.exp === 'false' && d.odak === 'bnMore', JSON.stringify({ acik: d.acik, exp: d.exp, odak: d.odak }));
        await page.click(TASARIM.moreSel); await page.waitForTimeout(350);
        await page.click('#bnSheetClose'); await page.waitForTimeout(100);
        d = await durum();
        ok('B', bag, '✕ kapatır, odak Diğer\'e döner', !d.acik && d.odak === 'bnMore', JSON.stringify({ acik: d.acik, odak: d.odak }));
        await page.click(TASARIM.moreSel); await page.waitForTimeout(350);
        await page.mouse.click(10, 10); await page.waitForTimeout(100);
        d = await durum();
        ok('B', bag, 'arka plana dokunmak kapatır', !d.acik, JSON.stringify({ acik: d.acik }));
        if (!NO_SHOTS) {
            await page.click(TASARIM.moreSel); await page.waitForTimeout(450);
            const f = path.join(SHOTS, '390_admin_home_diger.png'); await page.screenshot({ path: f }); shots.push(f);
            await page.keyboard.press('Escape');
        }
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5e — Sabitle: en çok 4, sabitlenen önce gelir ve SIRALANMAZ, anında uygulanır, kalıcı
    {
        const k = sayfaBul('admin', 'home'); bag_ctx = { profil: 'admin', sayfa: 'home', w: 390 };
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const bag = 'admin/home Sabitle';
        await page.click(TASARIM.moreSel); await page.waitForTimeout(350);
        await page.click('#bnPinBtn');
        const pb = await page.evaluate(() => { const b = document.getElementById('bnPinBtn'); return [b.getAttribute('aria-pressed'), b.textContent.trim()]; });
        ok('B', bag, 'Sabitle modu açılır (aria-pressed=true, "Bitti")', pb[0] === 'true' && pb[1] === 'Bitti', JSON.stringify(pb));
        const url0 = page.url();
        for (const x of ['backup', 'audit', 'roles', 'users']) {
            await page.click(`.bn-tile[data-nav="${x}"] .go`); await page.waitForTimeout(60);
        }
        ok('B', bag, 'sabitle modunda karoya dokunmak sayfayı AÇMAZ', page.url() === url0, page.url());
        let sl = await gorunenSlotlar(page);
        ok('B', bag, '4 sabit anında slotlara girdi (seçilme sırasıyla)', JSON.stringify(sl) === JSON.stringify(['backup', 'audit', 'roles', 'users']), `[${sl}]`);
        ok('B', bag, 'sabitler cihaza yazıldı (asya_nav_sabit_<uid>)',
            JSON.stringify(await depo(page, 'asya_nav_sabit_1')) === JSON.stringify(['backup', 'audit', 'roles', 'users']));
        await page.click('.bn-tile[data-nav="defs"] .go'); await page.waitForTimeout(60);
        const msg = await page.textContent('#bnSheetMsg');
        const pinli = await page.$$eval('.bn-tile.pinned', els => els.map(e => e.getAttribute('data-nav')));
        ok('B', bag, '5. sabit reddedilir ve nedeni yazılır', !pinli.includes('defs') && /En çok 4/.test(msg), `mesaj "${msg}", sabit [${pinli}]`);
        await page.click('.bn-tile[data-nav="backup"] .go'); await page.waitForTimeout(60);
        sl = await gorunenSlotlar(page);
        const sab = await depo(page, 'asya_nav_sabit_1');
        ok('B', bag, 'sabitleme kaldırılır', JSON.stringify(sab) === JSON.stringify(['audit', 'roles', 'users']) && sl.slice(0, 3).join() === 'audit,roles,users', `sabit ${JSON.stringify(sab)}, slot [${sl}]`);
        await page.click('#bnPinBtn');
        await page.keyboard.press('Escape');
        await page.reload(); await page.waitForTimeout(400);
        sl = await gorunenSlotlar(page);
        ok('B', bag, 'yeniden yüklemede sabitler önde (kullanımdan bağımsız)', sl.slice(0, 3).join() === 'audit,roles,users', `[${sl}]`);
        await page.setViewportSize({ width: 360, height: 740 }); await page.waitForTimeout(200);
        sl = await gorunenSlotlar(page);
        ok('B', bag, '360px: yalnız ilk 3 slot görünür', sl.join() === 'audit,roles,users', `[${sl}]`);
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5f — dar ekran: 4. slottaki güncel sayfayı "Diğer" taşır; genişleyince slota döner
    {
        const k = sayfaBul('dort_sayfa', 'kantar'); bag_ctx = { profil: 'dort_sayfa', sayfa: 'kantar', w: 360 };
        const { ctx, page, hatalar } = await ac(k, { w: 360, h: 740 });
        const bag = 'dort_sayfa/kantar';
        const oku = () => page.evaluate((T) => {
            const m = document.querySelector(T.moreSel);
            const gor = (el) => !!el && getComputedStyle(el).display !== 'none';
            return { diger: gor(m), digerCur: m.getAttribute('aria-current'), ikon: m.querySelector('img.ni').getAttribute('src'),
                     rozet: !m.querySelector('.more-badge').hidden, etiket: m.querySelector('.bn-label').textContent,
                     kantarCur: document.querySelector('[data-nav="kantar"]').getAttribute('aria-current'),
                     kantarGor: gor(document.querySelector('[data-nav="kantar"]')) };
        }, TASARIM);
        let d = await oku();
        ok('B', bag + '@360', '4. slot gizli, "Diğer" Kantar\'ı taşıyor (ikon + rozet + etiket + aria-current)',
            d.diger && d.digerCur === 'page' && /kantar\.svg/.test(d.ikon) && d.rozet && d.etiket === 'Kantar' && !d.kantarGor, JSON.stringify(d));
        await page.setViewportSize({ width: 430, height: 932 }); await page.waitForTimeout(250);
        d = await oku();
        ok('B', bag + '@430', 'genişleyince "Diğer" gizlenir, Kantar slotu aktif', !d.diger && d.kantarGor && d.kantarCur === 'page' && d.digerCur === null, JSON.stringify(d));
        await page.setViewportSize({ width: 360, height: 740 }); await page.waitForTimeout(250);
        d = await oku();
        ok('B', bag + '@360', 'daralınca yine "Diğer" taşır', d.diger && d.digerCur === 'page' && /kantar\.svg/.test(d.ikon), JSON.stringify(d));
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5g — çerez yazımı (gerçek http kökeni: file:// çerez tutmaz)
    {
        const k = sayfaBul('operator', 'kantar'); bag_ctx = { profil: 'operator', sayfa: 'kantar', w: 390 };
        const bag = 'operator/kantar çerez';
        const KOK = 'http://uygulama.test/';
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block' });
        const tipler = { '.svg': 'image/svg+xml', '.css': 'text/css', '.js': 'text/javascript', '.json': 'application/json', '.jpg': 'image/jpeg', '.png': 'image/png' };
        await ctx.route(KOK + '**', async (route) => {
            const u = new URL(route.request().url());
            const yol = decodeURIComponent(u.pathname.replace(/^\/+/, ''));
            if (yol === k.yol) {
                const html = fs.readFileSync(path.join(OUT, k.dosya), 'utf8').split('file://' + M.kok + '/').join(KOK);
                return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
            }
            const dosya = path.join(M.kok, yol);
            if (yol && !yol.includes('..') && fs.existsSync(dosya) && fs.statSync(dosya).isFile()) {
                return route.fulfill({ status: 200, contentType: tipler[path.extname(dosya)] || 'application/octet-stream', body: fs.readFileSync(dosya) });
            }
            return route.fulfill({ status: 204, body: '' });
        });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('console', m => { if (m.type() === 'error') hatalar.push('console: ' + m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        await page.goto(KOK + k.yol); await page.waitForTimeout(400);
        let c = (await ctx.cookies(KOK)).find(x => x.name === 'asya_nav');
        const deger = c ? decodeURIComponent(c.value) : null;
        ok('B', bag, 'asya_nav = u:<uid>;s:<görünen sıra>;p:<sabitler>', deger === 'u:1;s:records,hks,ptak,kantar;p:', `çerez "${deger}"`);
        const gun = c ? (c.expires - Date.now() / 1000) / 86400 : 0;
        ok('B', bag, 'çerez: path=/, SameSite=Lax, ~180 gün', !!c && c.path === '/' && c.sameSite === 'Lax' && gun > 179 && gun < 181,
            c ? `path ${c.path}, sameSite ${c.sameSite}, ${r1(gun)} gün` : 'çerez yok');
        await page.click(TASARIM.moreSel); await page.waitForTimeout(350);
        await page.click('#bnPinBtn');
        await page.click('.bn-tile[data-nav="hesap"] .go'); await page.waitForTimeout(80);
        c = (await ctx.cookies(KOK)).find(x => x.name === 'asya_nav');
        const d2 = c ? decodeURIComponent(c.value) : null;
        // hesap sabitlenince en zayıf SABİTSİZ slot (ptak — puan 0, öncelik en düşük) çıkar;
        // ziyaret edilen kantar kalır.
        ok('B', bag, 'sabitlemek çereze anında yansır (p:hesap, hesap önde)', d2 === 'u:1;s:hesap,records,hks,kantar;p:hesap', `çerez "${d2}"`);
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5h — koyu tema pasif ikon filtresi; 5i — azaltılmış hareket + basma efekti
    {
        const k = sayfaBul('admin', 'home'); bag_ctx = { profil: 'admin', sayfa: 'home', w: 390 };
        const filtre = (page) => page.evaluate((T) => getComputedStyle(document.querySelector(T.barSel + ' .bn-slot:not(.is-active) .ni')).filter, TASARIM);
        let r = await ac(k, { w: 390, h: 844 }, { koyu: true });
        const fk = await filtre(r.page);
        ok('B', 'admin/home koyu', `koyu temada pasif ikon filter: ${TASARIM.koyuPasifFiltre}`, fk === TASARIM.koyuPasifFiltre, fk);
        await r.ctx.close();
        r = await ac(k, { w: 390, h: 844 });
        const fa = await filtre(r.page);
        ok('B', 'admin/home açık', 'açık temada pasif ikon filtresi farklı (koyu kuralı sızmıyor)', fa !== TASARIM.koyuPasifFiltre && fa !== 'none', fa);
        const tr = await r.page.evaluate(() => getComputedStyle(document.querySelector('.bn-home .ni-wrap')).transform);
        ok('B', 'admin/home', 'aktif karo yükselir + büyür (translateY(-10) scale(1.14))', /matrix\(1\.14, 0, 0, 1\.14, 0, -10\)/.test(tr), tr);
        const el = await r.page.$('.bn-slot:not(.is-active)');
        await el.dispatchEvent('pointerdown'); await r.page.waitForTimeout(200);
        const bas = await r.page.evaluate(() => getComputedStyle(document.querySelector('.bn-slot.is-press .ni-wrap')).transform);
        ok('B', 'admin/home', 'basınca karo 0,9\'a küçülür', /matrix\(0\.9, 0, 0, 0\.9, 0, 2\)/.test(bas), bas);
        await r.ctx.close();
        r = await ac(k, { w: 390, h: 844 }, { azHareket: true });
        const trR = await r.page.evaluate(() => getComputedStyle(document.querySelector('.bn-home .ni-wrap')).transform);
        const anim = await r.page.evaluate(() => getComputedStyle(document.querySelector('.bn-home .ni-wrap')).transitionDuration);
        ok('B', 'admin/home azaltılmış hareket', 'prefers-reduced-motion: yükselme/geçiş yok', trR === 'none' && /^0s/.test(anim), `transform ${trR}, geçiş ${anim}`);
        await r.ctx.close();
    }

    // Kanıt ekran görüntüleri: gerçek çubuk 360/390/430 × açık/koyu + Hal Kayıt
    if (!NO_SHOTS) {
        const k = sayfaBul('operator', 'kantar');
        for (const w of [360, 390, 430]) for (const koyu of [false, true]) {
            const { ctx, page } = await ac(k, { w, h: 800 }, { koyu });
            const f = path.join(SHOTS, `cubuk_${w}_${koyu ? 'koyu' : 'acik'}.png`);
            await page.screenshot({ path: f }); shots.push(f);
            await ctx.close();
        }
        const h = sayfaBul('operator', 'hks');
        const { ctx, page } = await ac(h, { w: 390, h: 844 });
        const f = path.join(SHOTS, 'cubuk_390_hks.png'); await page.screenshot({ path: f }); shots.push(f);
        await ctx.close();
    }

    // Koyu tema — yalnız görsel kayıt (ölçüm değil)
    if (!NO_SHOTS) {
        const k = M.sayfalar.find(s => s.profil === 'admin' && s.sayfa === 'home');
        if (k) {
            const { ctx, page } = await ac(k, { w: 390, h: 844 }, { koyu: true });
            const f = path.join(SHOTS, '390_admin_home_koyu.png');
            await page.screenshot({ path: f }); shots.push(f);
            await ctx.close();
        }
    }

    await browser.close();

    // ── Özet ──────────────────────────────────────────────────────────────
    console.log('\n=== Ölçüm tablosu (mobil) ===');
    for (const r of olcumTablosu) {
        console.log(`${r.bag.padEnd(30)} yükseklik ${String(r.yukseklik).padStart(5)}px  görsel ${String(r.gorselUst).padStart(5)}px  içerik payı ${String(r.icerikPayi).padStart(6)}px  ${r.hedefler}`);
    }
    if (Object.keys(bulgular).length) {
        console.log('\n=== Bilinen hatalar (kanıt) ===');
        for (const [id, k] of Object.entries(bulgular)) console.log(`${id}: ${k}`);
    }
    if (shots.length) console.log(`\n${shots.length} ekran görüntüsü → ${SHOTS}`);
    const hata = say.A.hata + say.B.hata + (STRICT ? say.xfail + say.xpass : 0);
    console.log(`\n(A) değişmezler: ${say.A.ok} OK, ${say.A.hata} HATA | (B) güncel tasarım: ${say.B.ok} OK, ${say.B.hata} HATA | bilinen hata: ${say.xfail} | xpass: ${say.xpass}`);
    console.log(hata === 0 ? 'TÜMÜ GEÇTİ' : `${hata} TEST BAŞARISIZ`);
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error('Test çalıştırılamadı:', e.stack || e.message); process.exit(1); });
