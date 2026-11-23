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
const SAYFA_ADI = { records: 'Yükleme Planı', cikma: 'Paletleme', beyan: 'Beyanlar', kantar: 'Mal Kabul', hks: 'Hal Bildirimi',
    rapor: 'Eski Raporlar', mstok: 'Malzeme Stok', hesap: 'Hesap', siparis: 'OGL Siparişler', ptak: 'Personel Takibi', defs: 'Tanımlar',
    users: 'Kullanıcılar', roles: 'Roller', audit: 'İşlem Geçmişi', backup: 'Veritabanı Yedekleri' };

// Göreli href'i uygulama köküne göre yola çevir (base_url '../' dahil)
function uygulamaYolu(href, sayfaYolu) {
    try {
        const u = new URL(href, 'http://uygulama.test/' + sayfaYolu);
        if (u.host !== 'uygulama.test') return null;   // dış bağlantı
        return u.pathname.replace(/^\/+/, '');
    } catch (e) { return null; }
}

// Ana Sayfa'nın hedeflediği sayfa (P.home_hedef) nav_aktif_anahtar()'ın
// ürettiği anahtarlardan hangisine karşılık gelir — config/helpers.php'deki
// nav_alt_home_anahtar()'ın AYNASI (Sprint Alt-Menü-01 düzeltme turu).
function anaSayfaAnahtari(homeBek) {
    if (homeBek === null) return null;
    if (homeBek === 'index.php') return 'home';
    if (homeBek === 'maliyet.php') return 'rapor';   // nav_aktif_anahtar(): maliyet_* → 'rapor'
    const giris = Object.entries(M.anahtar_sayfa).find(([, v]) => v === homeBek);
    if (giris) return giris[0];
    // v552: muhasebe menüsü sayfası — nav_aktif_anahtar() dosya slug'ını (.php'siz) döndürür
    return (M.muhasebe_sayfalar || []).includes(homeBek) ? homeBek.replace(/\.php$/, '') : null;
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
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : (process.env.QUICK_COST_BROWSER_CHANNEL ? { channel: process.env.QUICK_COST_BROWSER_CHANNEL } : {}));
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
        const homeBek = P.home_hedef;
        // Ana Sayfa index.php'ye gitmiyorsa (dashboard.read yok) hedeflediği
        // sayfada da aktif görünmelidir — yalnız 'home' sayfasında değil.
        const homeBekAktif = homeBek !== null && anaSayfaAnahtari(homeBek) !== null && anaSayfaAnahtari(homeBek) === k.anahtar;   // null === null alakasız sayfada Ana Sayfa'yı aktif saymaz
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
        // Ana Sayfa kendi hedefinde aktifse (dashboard.read yok) o hedef
        // (homeBek) k.bolum'dan FARKLI olabilir (ör. maliyet.php'nin bölümü
        // sidebar'da 'reports.php'dir ama Ana Sayfa'nın hedefi maliyet.php'nin
        // KENDİSİdir) — bu durumda aktif bağlantının homeBek'e işaret etmesi
        // de doğrudur, yalnız k.bolum'a değil.
        const yanlisAktif = aktifYollar.filter(y => !y.startsWith('<') && y !== k.bolum && !(homeBekAktif && y === homeBek));
        ok('A', bag, 'aktif bağlantı başka bir bölüme işaret etmiyor', yanlisAktif.length === 0,
            `aktif: ${yanlisAktif.join(', ')} (beklenen bölüm ${k.bolum}${homeBekAktif ? ` veya Ana Sayfa hedefi ${homeBek}` : ''})`, 'BN-HKS-IKI-AKTIF');

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
        ok('B', bag, `Ana Sayfa hedefi = ${homeBek ?? 'YOK (çizilmez)'}`,
            homeBek === null ? d.home === null : (d.home !== null && uygulamaYolu(d.home, k.yol) === homeBek),
            `ölçülen ${d.home} (dashboard.read ? index.php : first_allowed_page())`);
        ok('B', bag, `Ana Sayfa ${homeBekAktif ? '' : 'YALNIZ index.php\'de '}aktif`, d.homeAktif === homeBekAktif,
            `homeAktif=${d.homeAktif}, beklenen=${homeBekAktif} (hedef ${homeBek} → anahtar ${anaSayfaAnahtari(homeBek)}, sayfa anahtarı ${k.anahtar})`);
        const bekSlot = Math.min(4, P.adaylar.length);
        ok('B', bag, `@390 görünen slot sayısı ${bekSlot}`, d.slotlar.length === bekSlot, `[${d.slotlar.join(', ')}]`);
        ok('B', bag, 'slotlar yalnız izinli adaylar, tekrar yok',
            d.slotlar.every(x => P.adaylar.includes(x)) && new Set(d.slotlar).size === d.slotlar.length, `[${d.slotlar.join(', ')}]`);
        const digerBek390 = P.adaylar.length > 4 || P.sheet_ek;   // v552: anahtarsız (muhasebe) öğe varsa sheet + Diğer ŞART
        ok('B', bag, `"Diğer" ${digerBek390 ? 'VAR' : 'YOK'} (@390, ${P.adaylar.length} aday${P.sheet_ek ? ' + anahtarsız muhasebe öğesi' : ''})`, d.digerGorunur === digerBek390);
        // Tek aktif öğe: aria-current="page" + .is-active; hangisi olacağı sayfanın
        // bölümünden — Ana Sayfa kendi hedefinde her zaman ÖNCELİKLİDİR (index.php'ye
        // gitmese bile, bkz. homeBekAktif), yoksa o bölümün kendi slotu (varsa).
        const bekAktif = homeBekAktif ? 'home' : (k.anahtar && P.adaylar.includes(k.anahtar) ? k.anahtar : null);
        const olcAktif = d.aktifler.length === 1 ? (d.aktifler[0].nav === 'more' ? 'more' : d.aktifler[0].nav) : null;
        const aktifDogru = bekAktif === null ? d.aktifler.length === 0
            : d.aktifler.length === 1 && d.aktifler[0].sinif
              && (olcAktif === bekAktif || (olcAktif === 'more' && d.digerLabel.includes(SAYFA_ADI[bekAktif])));
        ok('B', bag, `tek aktif öğe (aria-current="page") = ${bekAktif ?? 'hiçbiri'}`, aktifDogru && d.isActiveSay === d.aktifler.length,
            `aktif: ${JSON.stringify(d.aktifler)}, .is-active görünen ${d.isActiveSay}, Diğer "${d.digerLabel}"`);
        if (k.sayfa === 'hks') ok('B', bag, 'Hal Kayıt: YALNIZ Bildirim (hks) aktif — Ana Sayfa değil', olcAktif === 'hks' && !d.homeAktif, JSON.stringify(d.aktifler));
        if (k.sayfa === 'maliyet') {
            // dashboard.read'i olan profillerde maliyet.php'nin kendi slotu yoktur
            // (bilerek), Raporlar aktif kalır. dashboard.read'i OLMAYAN bir rolde
            // (ör. yalnız maliyet.read) Ana Sayfa zaten maliyet.php'ye gider — o
            // durumda aktif olması gereken Raporlar DEĞİL, Ana Sayfa'dır
            // (BN-MALIYET-AKTIF-YOK düzeltmesi, bkz. anaSayfaAnahtari).
            if (homeBekAktif) ok('B', bag, 'maliyet_*: dashboard.read yok → Ana Sayfa aktif', olcAktif === 'home', JSON.stringify(d.aktifler));
            else ok('B', bag, 'maliyet_*: Eski Raporlar (rapor) aktif', olcAktif === 'rapor' || (olcAktif === 'more' && d.digerLabel.includes('Eski Raporlar')), JSON.stringify(d.aktifler));
        }
        ok('B', bag, 'ikonlar <img width=42/18 alt=""> assets/nav-icons/*.svg?v=',
            d.imgler.every(([w, h, alt, src]) => w === h && ['42', '18'].includes(w) && alt === '' && /assets\/nav-icons\/[a-z]+\.svg\?v=\d+$/.test(src)),
            JSON.stringify(d.imgler.filter(([w, h, alt, src]) => !(w === h && alt === '' && /nav-icons/.test(src)))));
        await ctx.close();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1b) Sprint Alt-Menü-01 düzeltme turu — dashboard.read'i OLMAYAN roller:
    //     Ana Sayfa kendi hedefinde aktif olmalı VE hedefiyle aynı sayfaya
    //     giden ikinci bir slot ÇİZİLMEMELİ (BN-MALIYET-AKTIF-YOK).
    // ─────────────────────────────────────────────────────────────────────
    for (const [pk, sayfa, aciklama] of [
        ['maliyet_tek', 'maliyet', 'maliyet.php nav_alt_izinler() adayı DEĞİL — Ana Sayfa TEK öğe olmalı'],
        ['hesap_tek', 'hesap', "hesap.php HEM Ana Sayfa hedefi HEM 'hesap' adayı — ikincisi ÇİZİLMEMELİ"],
    ]) {
        const k = M.sayfalar.find(x => x.profil === pk && x.sayfa === sayfa);
        if (!k) { ok('B', `${pk}/${sayfa}`, `${aciklama} — sayfa üretilmedi`, false, 'bottomnav_render.php profillerini kontrol et'); continue; }
        bag_ctx = { profil: pk, sayfa, w: 390 };
        const bag = `${pk}/${sayfa}`;
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const d = await page.evaluate((T) => {
            const bar = document.querySelector(T.barSel);
            const gor = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0;
            return {
                hedefSay: [...bar.querySelectorAll('a[href], button')].filter(gor).length,
                slotlar: [...bar.querySelectorAll(T.slotSel)].filter(gor).map(a => a.getAttribute('data-nav')),
                aktifSay: [...bar.querySelectorAll('[aria-current="page"]')].filter(gor).length,
                homeAktif: bar.querySelector(T.homeSel)?.getAttribute('aria-current') === 'page',
                digerVar: gor(bar.querySelector(T.moreSel)),
            };
        }, TASARIM);
        ok('B', bag, `${aciklama}: çubukta TEK öğe (Ana Sayfa), "Diğer" yok, mükerrer slot yok`,
            d.hedefSay === 1 && d.slotlar.length === 0 && !d.digerVar, JSON.stringify(d));
        ok('B', bag, 'Ana Sayfa TEK aktif öğe', d.homeAktif && d.aktifSay === 1, JSON.stringify(d));
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
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
    // Tam 4 adaylı rolde hesap 4. slottadır: dar ekranda "Diğer" onu taşımalı
    const dortKantar = M.sayfalar.find(s => s.profil === 'dort_sayfa' && s.sayfa === 'hesap');
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
                ok('B', bag, `"Diğer" ${(P.adaylar.length > n || P.sheet_ek) ? 'görünür' : 'yok'} (${P.adaylar.length} aday${P.sheet_ek ? ' + muhasebe' : ''})`, g.diger === (P.adaylar.length > n || P.sheet_ek));
                // Ana Sayfa kendi hedefinde her zaman öncelikli (bkz. anaSayfaAnahtari) —
                // index.php'ye gitmese bile.
                const bek = anaSayfaAnahtari(P.home_hedef) !== null && anaSayfaAnahtari(P.home_hedef) === k.anahtar && P.home_hedef !== null ? 'home'
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
            karolar: ovl ? [...ovl.querySelectorAll('.bn-tile[data-nav]')].map(t => t.getAttribute('data-nav')) : null,
            linkKarolar: ovl ? [...ovl.querySelectorAll('.bn-tile-link')].map(t => ({ href: t.getAttribute('href'), dataNav: t.hasAttribute('data-nav'), go: !!t.querySelector('.go'), pin: !!t.querySelector('.bn-pin-badge') })) : [],
            acikGruplar: ovl ? [...ovl.querySelectorAll('details.bn-grup[open]')].map(d => d.getAttribute('data-sb-grup')) : [],
            sbAcikGruplar: [...document.querySelectorAll('.desktop-sidebar details.sidebar-grup[open]')].map(d => d.getAttribute('data-sb-grup')),
            sabit: ovl ? [...ovl.querySelectorAll('.bn-tile.pinned')].map(t => t.getAttribute('data-nav')) : [],
            dataSabit: bar.getAttribute('data-sabit'),
            navKeys: [...bar.querySelectorAll('[data-nav]'), ...(ovl ? ovl.querySelectorAll('[data-nav]') : [])].map(a => a.getAttribute('data-nav')),
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
        // v558: eski Yükleme Planı / Paletleme / Mal Kabul HİÇBİR rolde çubukta ya da "Diğer"de yok (anahtar + bağlantı)
        const eskiKalan = [...(M.aday_degil || [])].filter(x => d.navKeys.includes(x)).concat(d.tumLink.map(h => uygulamaYolu(h, k.yol)).filter(y => ['records.php', 'cikmalar.php', 'kantar.php'].includes(y)));
        ok('B', bag, 'v558: records/cikma/kantar öğesi/karosu/bağlantısı YOK (hiçbir profilde)', eskiKalan.length === 0, eskiKalan.join(', '));
        const kotu = d.tumLink.map(h => uygulamaYolu(h, k.yol)).filter(y => !P.izinli[y]);
        ok('B', bag, 'çubuk + "Diğer" sayfasındaki HER bağlantı izinli', kotu.length === 0, kotu.join(', '));
        if (d.karolar !== null) {
            // v552: sıra artık SIDEBAR sırasıdır (gruplu) — burada küme eşitliği; sıra/ad eşliği 5j'de sidebar DOM'una karşı denetlenir.
            ok('B', bag, '"Diğer" yalnız izinli sayfaları listeler (hepsi, fazlası yok)',
                JSON.stringify([...d.karolar].sort()) === JSON.stringify([...P.adaylar].sort()), `karolar [${d.karolar}] beklenen [${P.adaylar}]`);
            ok('B', bag, 'anahtarsız karolar (muhasebe vb.): pin\'siz — data-nav yok, .go yok, iğne rozeti yok, yalnız izinli sayfaya gider',
                d.linkKarolar.every(l => !l.dataNav && !l.go && !l.pin && P.izinli[uygulamaYolu(l.href, k.yol)]), JSON.stringify(d.linkKarolar.filter(l => l.dataNav || l.go || l.pin || !P.izinli[uygulamaYolu(l.href, k.yol)])));
            ok('B', bag, 'JS kapalı: sheet\'te en çok 1 grup açık ve sidebar\'daki açık grupla AYNI (sunucu yalnız aktif grubu açar)',
                d.acikGruplar.length <= 1 && JSON.stringify(d.acikGruplar) === JSON.stringify(d.sbAcikGruplar), `sheet [${d.acikGruplar}] sidebar [${d.sbAcikGruplar}]`);
        }
        if (k.senaryo) {
            ok('B', bag, `sabitlenen = [${k.senaryo.sabit.join(', ')}] (çerez p: → karo rozeti + data-sabit)`,
                JSON.stringify(d.sabit) === JSON.stringify(k.senaryo.sabit) && d.dataSabit === k.senaryo.sabit.join(','),
                `karo [${d.sabit}], data-sabit "${d.dataSabit}"`);
            if (k.senaryo.ad === 'cerez_sahte') {
                // Relative legitimate home links may be ../index.php in CLI fixtures.
                // Check forbidden navigation identities, not href substrings.
                const yasak = ['qcost', 'users', 'roles', 'audit', 'backup', 'xyz', '../index'].filter(x => d.navKeys.includes(x));
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
        ok('B', 'operator/home', 'soğuk başlangıç (çerez + kullanım yok): Bildirim, Personel, Eski Raporlar, Beyanlar (v558: records artık aday değil)',
            JSON.stringify(sl) === JSON.stringify(['hks', 'ptak', 'rapor', 'beyan']), `[${sl}]`);
        const kul = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/home', 'Ana Sayfa ziyareti sayılmadı', Array.isArray(kul) && kul.length === 0, JSON.stringify(kul));
        ok('A', 'operator/home', 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5b — ziyaret kaydı + yeniden sıralama (en zayıf slotun YERİNE girer)
    {
        const k = sayfaBul('operator', 'hesap'); bag_ctx = { profil: 'operator', sayfa: 'hesap', w: 390 };
        const { ctx, page } = await ac(k, { w: 390, h: 844 });
        const kul = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/hesap', 'ziyaret cihaza yazıldı (asya_nav_kullanim_<uid>)',
            Array.isArray(kul) && kul.length === 1 && kul[0][0] === 'hesap' && Math.abs(kul[0][1] - Date.now()) < 60000, JSON.stringify(kul));
        const sl = await gorunenSlotlar(page);
        ok('B', 'operator/hesap', 'ilk ziyaret (1 > 0×1,25+0,5) en zayıf slotu (Beyanlar: puan 0, en düşük öncelik) yerinde değiştirdi',
            JSON.stringify(sl) === JSON.stringify(['hks', 'ptak', 'rapor', 'hesap']), `[${sl}]`);
        await page.reload(); await page.waitForTimeout(400);
        const kul2 = await depo(page, 'asya_nav_kullanim_1');
        ok('B', 'operator/hesap', 'aynı bölümde 30 dk içinde yenileme TEK ziyaret', Array.isArray(kul2) && kul2.length === 1, JSON.stringify(kul2));
        await ctx.close();
    }
    // 5c — histerezis: puan > en zayıf × 1,25 + 0,5 olmadıkça yer değişmez
    for (const [ad, sayilar, bek] of [
        ['hesap 2 > beyan 1×1,25+0,5 → girer (beyan\'ın yerine)', { hks: 3, ptak: 3, rapor: 3, beyan: 1, hesap: 2 }, ['hks', 'ptak', 'rapor', 'hesap']],
        ['hesap 1 ≤ beyan 1×1,25+0,5 → girmez', { hks: 3, ptak: 3, rapor: 3, beyan: 1, hesap: 1 }, ['hks', 'ptak', 'rapor', 'beyan']],
        // Eşik tam 1,25 çarpanına duyarlı: 3 > 2×1,25+0,5 (=3) DEĞİL; çarpan 1 olsaydı girerdi
        ['hesap 3 ≤ beyan 2×1,25+0,5 = 3 → girmez (sınır)', { hks: 4, ptak: 4, rapor: 4, beyan: 2, hesap: 3 }, ['hks', 'ptak', 'rapor', 'beyan']],
        // mstok en zayıfın (rapor, eşit puanda en düşük öncelik) yerine, hesap sonraki en zayıfın (ptak) yerine girer;
        // hks + beyan YERİNİ korur.
        ['çok kullanılanlar en zayıfların yerine girer, kalanlar yerini korur', { beyan: 6, mstok: 5, hesap: 4 }, ['hks', 'hesap', 'mstok', 'beyan']],
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
                     karolar: [...o.querySelectorAll('.bn-tile[data-nav] .go')].map(a => a.getAttribute('href')) };
        }, TASARIM);
        await page.click(TASARIM.moreSel); await page.waitForTimeout(400);
        let d = await durum();
        ok('B', bag, 'Diğer tıklanınca açılır (aria-expanded=true, odak Kapat\'ta)', d.acik && d.exp === 'true' && d.odak === 'bnSheetClose', JSON.stringify(d));
        ok('B', bag, 'alt sayfa z-index ≥ 600 ve çubuğun ÜSTÜNDE', +d.z >= 600 && d.cubukUstu, `z=${d.z}, üstte=${d.cubukUstu}`);
        const P = M.profiller.admin;
        const karoYol = d.karolar.map(h => uygulamaYolu(h, k.yol));
        ok('B', bag, 'pin\'lenebilir karolar = izinli adayların sayfaları (sıra sidebar sırası — 5j denetler)',
            JSON.stringify([...karoYol].sort()) === JSON.stringify(P.adaylar.map(x => M.anahtar_sayfa[x]).sort()), `[${karoYol}]`);
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
        await page.click('#bnSheet details.bn-grup[data-sb-grup="yonetim"] > summary'); await page.waitForTimeout(120);   // v552: akordiyon — Yönetim kapalıydı (admin/home'da aktif grup yok)
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
        const k = sayfaBul('dort_sayfa', 'hesap'); bag_ctx = { profil: 'dort_sayfa', sayfa: 'hesap', w: 360 };
        const { ctx, page, hatalar } = await ac(k, { w: 360, h: 740 });
        const bag = 'dort_sayfa/hesap';
        const oku = () => page.evaluate((T) => {
            const m = document.querySelector(T.moreSel);
            const gor = (el) => !!el && getComputedStyle(el).display !== 'none';
            return { diger: gor(m), digerCur: m.getAttribute('aria-current'), ikon: m.querySelector('img.ni').getAttribute('src'),
                     rozet: !m.querySelector('.more-badge').hidden, etiket: m.querySelector('.bn-label').textContent,
                     slotCur: document.querySelector('[data-nav="hesap"]').getAttribute('aria-current'),
                     slotGor: gor(document.querySelector('[data-nav="hesap"]')) };
        }, TASARIM);
        let d = await oku();
        ok('B', bag + '@360', '4. slot gizli, "Diğer" Hesap\'ı taşıyor (ikon + rozet + etiket + aria-current)',
            d.diger && d.digerCur === 'page' && /hesap\.svg/.test(d.ikon) && d.rozet && d.etiket === 'Hesap' && !d.slotGor, JSON.stringify(d));
        await page.setViewportSize({ width: 430, height: 932 }); await page.waitForTimeout(250);
        d = await oku();
        ok('B', bag + '@430', 'genişleyince "Diğer" gizlenir, Hesap slotu aktif', !d.diger && d.slotGor && d.slotCur === 'page' && d.digerCur === null, JSON.stringify(d));
        await page.setViewportSize({ width: 360, height: 740 }); await page.waitForTimeout(250);
        d = await oku();
        ok('B', bag + '@360', 'daralınca yine "Diğer" taşır', d.diger && d.digerCur === 'page' && /hesap\.svg/.test(d.ikon), JSON.stringify(d));
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // 5g — çerez yazımı (gerçek http kökeni: file:// çerez tutmaz)
    {
        const k = sayfaBul('operator', 'hesap'); bag_ctx = { profil: 'operator', sayfa: 'hesap', w: 390 };
        const bag = 'operator/hesap çerez';
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
        ok('B', bag, 'asya_nav = u:<uid>;s:<görünen sıra>;p:<sabitler>', deger === 'u:1;s:hks,ptak,rapor,hesap;p:', `çerez "${deger}"`);
        const gun = c ? (c.expires - Date.now() / 1000) / 86400 : 0;
        ok('B', bag, 'çerez: path=/, SameSite=Lax, ~180 gün', !!c && c.path === '/' && c.sameSite === 'Lax' && gun > 179 && gun < 181,
            c ? `path ${c.path}, sameSite ${c.sameSite}, ${r1(gun)} gün` : 'çerez yok');
        await page.click(TASARIM.moreSel); await page.waitForTimeout(350);
        await page.click('#bnPinBtn');
        await page.click('#bnSheet details.bn-grup[data-sb-grup="stok"] > summary'); await page.waitForTimeout(120);   // Malzeme Stok "Stok" grubunda (Hesap'ın grubu o sayfada zaten açık)
        await page.click('.bn-tile[data-nav="mstok"] .go'); await page.waitForTimeout(80);
        c = (await ctx.cookies(KOK)).find(x => x.name === 'asya_nav');
        const d2 = c ? decodeURIComponent(c.value) : null;
        // mstok sabitlenince en zayıf SABİTSİZ slot (rapor — puan 0, öncelik en düşük) çıkar;
        // ziyaret edilen hesap kalır.
        ok('B', bag, 'sabitlemek çereze anında yansır (p:mstok, mstok önde)', d2 === 'u:1;s:mstok,hks,ptak,hesap;p:mstok', `çerez "${d2}"`);
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

    // 5j — v552: "Diğer" sayfası = sidebar ile AYNI gruplar (ad/sıra/öğe) + AKORDİYON (yalnız aktif grup açık;
    //      başlığa basınca diğerleri kapanır) + taşma/dokunma/koyu tema/azaltılmış hareket + anahtarsız öğeler pin'siz
    console.log('\n=== 5j) "Diğer" sayfası: sidebar eşliği, akordiyon, taşma, koyu tema ===');
    const sidebarGruplari = (page) => page.evaluate(() => [...document.querySelectorAll('.desktop-sidebar details.sidebar-grup')].map(d => ({
        slug: d.getAttribute('data-sb-grup'), ad: d.querySelector('.sidebar-section-ad').textContent.trim(), acik: d.open,
        ogeler: [...d.querySelectorAll('a.sidebar-link')].map(a => ({ etiket: a.querySelector('.sidebar-link-label').textContent.trim(), href: a.getAttribute('href'), aktif: a.classList.contains('active') })),
    })));
    const sheetGruplari = (page) => page.evaluate(() => [...document.querySelectorAll('#bnSheet details.bn-grup')].map(d => ({
        slug: d.getAttribute('data-sb-grup'), ad: d.querySelector('.bn-grup-ad').textContent.trim(), acik: d.open,
        ogeler: [...d.querySelectorAll('.bn-tile')].map(t => {
            const go = t.querySelector('.go');
            return { etiket: (go ? go.querySelector('span:not(.nav-rozet)') : t.querySelector('.bn-tile-ad')).textContent.trim(),
                     href: (go || t).getAttribute('href'), aktif: (go || t).getAttribute('aria-current') === 'page', pinli: !!go };
        }),
    })));
    const acikSlug = (page) => page.evaluate(() => [...document.querySelectorAll('#bnSheet details.bn-grup')].filter(d => d.open).map(d => d.getAttribute('data-sb-grup')));
    const PARITE = [['admin', 'home'], ['admin', 'definitions'], ['admin', 'hesap'], ['admin', 'kantar'], ['operator', 'kantar'], ['operator', 'home'], ['viewer', 'home'],
                    ['muhasebe_sayfa', 'kantar'], ['muhasebe_sayfa', 'cari_kartlari'], ['muhasebe_tek', 'cari_kartlari'],
                    // v555: eski sayfa ↔ yeni karşılığı seçimi (records/cikmalar/kantar ailesi) — sheet ve sidebar aynı girişi seçili gösterir
                    ['admin', 'records'], ['admin', 'cikmalar'], ['admin', 'record_view'], ['admin', 'cikma_view'], ['yeni_fis_hepsi', 'records'], ['yeni_fis_hepsi', 'kantar'],
                    ['yeni_fis_kismi', 'kantar'], ['yeni_fis_kismi', 'records'], ['muhasebe_sayfa', 'cikmalar'], ['yalniz_yeni_fis', 'arac_fis']];
    for (const [pk, sk] of PARITE) {
        const k = sayfaBul(pk, sk);
        if (!k) { ok('B', `${pk}/${sk} Diğer`, 'sayfa üretilmedi', false, 'bottomnav_render.php'); continue; }
        bag_ctx = { profil: pk, sayfa: sk, w: 390 };
        const bag = `${pk}/${sk} Diğer`;
        const P = M.profiller[pk];
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const sb = await sidebarGruplari(page), sh = await sheetGruplari(page);
        // Beklenen = sidebar; tek fark: Ana Sayfa'nın hedefiyle aynı sayfa (dokta Ana Sayfa zaten oraya gider) ve boş kalan grup
        const bek = sb.map(g => ({ ...g, ogeler: g.ogeler.filter(o => uygulamaYolu(o.href, k.yol) !== P.home_hedef) })).filter(g => g.ogeler.length);
        ok('B', bag, 'grup adları + SIRASI sidebar ile birebir aynı', JSON.stringify(sh.map(g => [g.slug, g.ad])) === JSON.stringify(bek.map(g => [g.slug, g.ad])),
            `sheet [${sh.map(g => g.ad)}] sidebar [${bek.map(g => g.ad)}]`);
        ok('B', bag, 'her grubun öğe adları + sırası + bağlantıları sidebar ile birebir aynı',
            JSON.stringify(sh.map(g => g.ogeler.map(o => [o.etiket, o.href]))) === JSON.stringify(bek.map(g => g.ogeler.map(o => [o.etiket, o.href]))),
            JSON.stringify(sh.map((g, i) => [g.ad, g.ogeler.map(o => o.etiket).join('|'), (bek[i] || { ogeler: [] }).ogeler.map(o => o.etiket).join('|')])));
        // Aktif grup: sidebar'daki aktif grup (sheet'te hâlâ çizilen). Ana Sayfa hedefi sheet'te tekrarlanmadığından o öğenin kendisi
        // sheet'te olmayabilir ama grubu sunucu yine açık basar (sidebar ile aynı karar) — bkz. muhasebe_tek/cari_kartlari.
        const aktifSidebar = sb.filter(g => g.ogeler.some(o => o.aktif)).map(g => g.slug).filter(x => bek.some(b => b.slug === x));
        const aktifSheet = sh.filter(g => g.ogeler.some(o => o.aktif)).map(g => g.slug);
        const acik = sh.filter(g => g.acik).map(g => g.slug);
        ok('B', bag, 'AKORDİYON: yalnız aktif sayfanın grubu açık, diğer hepsi kapalı', JSON.stringify(acik) === JSON.stringify(aktifSidebar.slice(0, 1)), `açık [${acik}] aktif grup [${aktifSidebar}]`);
        ok('B', bag, 'sidebar ile aynı grup açık (iki menü aynı kuralla çalışır)', JSON.stringify(acik) === JSON.stringify(sb.filter(g => g.acik).map(g => g.slug).filter(x => bek.some(b => b.slug === x))), `sheet [${acik}] sidebar [${sb.filter(g => g.acik).map(g => g.slug)}]`);
        ok('B', bag, 'aktif sayfa sheet\'te de işaretli (aria-current) — sidebar ile aynı grupta', JSON.stringify(aktifSheet) === JSON.stringify(bek.filter(g => g.ogeler.some(o => o.aktif)).map(g => g.slug)), `sheet [${aktifSheet}] sidebar(süzülmüş) [${bek.filter(g => g.ogeler.some(o => o.aktif)).map(g => g.slug)}]`);
        // Pin'siz öğeler: anahtarsız karo = sade bağlantı (data-nav/.go/iğne yok); pin'li karo yalnız anahtarlı olanlardır
        const sadeler = await page.evaluate(() => [...document.querySelectorAll('#bnSheet .bn-tile-link')].map(t => ({ dn: t.hasAttribute('data-nav'), go: !!t.querySelector('.go'), pin: !!t.querySelector('.bn-pin-badge'), tag: t.tagName })));
        ok('B', bag, 'anahtarsız karolar pin\'siz sade bağlantı (<a>, data-nav/.go/iğne YOK)', sadeler.every(t => !t.dn && !t.go && !t.pin && t.tag === 'A'), JSON.stringify(sadeler.filter(t => t.dn || t.go || t.pin)));
        if (P.sheet_ek) ok('B', bag, 'muhasebe öğesi var → anahtarsız karo(lar) çizildi (aksi halde o sayfalara mobilde ulaşılamaz)', sadeler.length > 0, `${sadeler.length} anahtarsız karo`);
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // Akordiyon davranışı + taşma + dokunma hedefi + yapışkan başlık (admin: en uzun menü, 11 grup) @390 ve @360
    for (const w of [390, 360]) {
        const k = sayfaBul('admin', 'home'); bag_ctx = { profil: 'admin', sayfa: 'home', w };
        const bag = `admin/home Diğer@${w}`;
        const { ctx, page, hatalar } = await ac(k, { w, h: w === 360 ? 740 : 844 });
        await page.click(TASARIM.moreSel); await page.waitForTimeout(450);
        let ac0 = await acikSlug(page);
        ok('B', bag, 'Ana Sayfa\'da aktif grup yok → açılışta HİÇBİR grup açık değil', ac0.length === 0, `[${ac0}]`);
        const slugs = await page.evaluate(() => [...document.querySelectorAll('#bnSheet details.bn-grup')].map(d => d.getAttribute('data-sb-grup')));
        ok('B', bag, `10 grup (Sipariş…Yönetim…Kullanıcı Ayarları; v555: İhracat Programı yok; v566: Admin Konsolu yok) çizildi`, slugs.length === 10 && !slugs.includes('ihracat') && !slugs.includes('admin_konsolu'), `[${slugs}]`);
        let hepsiTamam = true; const ihlal = [];
        for (const sl of slugs) {
            await page.click(`#bnSheet details.bn-grup[data-sb-grup="${sl}"] > summary`); await page.waitForTimeout(160);
            const acik = await acikSlug(page);
            if (acik.length !== 1 || acik[0] !== sl) { hepsiTamam = false; ihlal.push(`${sl}: açık [${acik}]`); }
            const o = await page.evaluate((sl) => {
                const sheet = document.getElementById('bnSheet'), sr = sheet.getBoundingClientRect(), de = document.documentElement;
                const d = document.querySelector(`#bnSheet details.bn-grup[data-sb-grup="${sl}"]`);
                const sum = d.querySelector('summary').getBoundingClientRect();
                const tiles = [...d.querySelectorAll('.bn-tile')];
                const hedefler = [...d.querySelectorAll('.bn-tile .go, a.bn-tile-link')].map(a => a.getBoundingClientRect());
                return {
                    sheetTasma: sheet.scrollWidth - sheet.clientWidth, docTasma: de.scrollWidth - de.clientWidth,
                    disari: tiles.filter(t => { const r = t.getBoundingClientRect(); return r.left < sr.left - 1 || r.right > sr.right + 1; }).length,
                    metinTasma: [...d.querySelectorAll('.bn-tile-ad, .bn-tile .go > span:not(.nav-rozet)')].filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent.trim()),
                    sumH: sum.height, sumW: sum.width, hedefMinH: hedefler.length ? Math.min(...hedefler.map(r => r.height)) : 99, sheetW: sr.width,
                };
            }, sl);
            if (o.sheetTasma > 1 || o.docTasma > 1 || o.disari || o.metinTasma.length || o.sumH < 44 || o.hedefMinH < 44) { hepsiTamam = false; ihlal.push(`${sl}: ${JSON.stringify(o)}`); }
        }
        ok('B', bag, 'her başlığa basınca YALNIZ o grup açılır, diğerleri kapanır (akordiyon); yatay taşma YOK; başlık + karolar ≥44px; metin kırpılmıyor', hepsiTamam, ihlal.join(' || '));
        // aynı başlığa tekrar basınca kapanır
        await page.click(`#bnSheet details.bn-grup[data-sb-grup="${slugs[slugs.length - 1]}"] > summary`); await page.waitForTimeout(160);
        ac0 = await acikSlug(page);
        ok('B', bag, 'açık grubun başlığına tekrar basınca kapanır', ac0.length === 0, `[${ac0}]`);
        // yapışkan başlık: en alta kaydırınca da Kapat erişilebilir
        await page.click(`#bnSheet details.bn-grup[data-sb-grup="${slugs[slugs.length - 1]}"] > summary`); await page.waitForTimeout(160);
        await page.evaluate(() => { const s = document.getElementById('bnSheet'); s.scrollTop = s.scrollHeight; }); await page.waitForTimeout(80);
        const yk = await page.evaluate(() => {
            const b = document.getElementById('bnSheetClose'), r = b.getBoundingClientRect(), s = document.getElementById('bnSheet');
            const ust = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return { top: r.top, ok: ust === b, kaydi: s.scrollTop > 0, yuk: s.scrollHeight > s.clientHeight, foot: document.getElementById('bnSheetFoot').getBoundingClientRect().bottom <= innerHeight + 1 };
        });
        ok('B', bag, 'uzun içerikte en alta kaydırınca Kapat görünür + tıklanabilir (yapışkan başlık), altbilgi ekranda', yk.yuk && yk.kaydi && yk.ok && yk.top >= 0 && yk.foot, JSON.stringify(yk));
        await page.click('#bnSheetClose'); await page.waitForTimeout(100);
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        if (!NO_SHOTS && w === 360) {
            await page.click(TASARIM.moreSel); await page.waitForTimeout(450);
            await page.click('#bnSheet details.bn-grup[data-sb-grup="cari"] > summary'); await page.waitForTimeout(200);
            const f = path.join(SHOTS, '360_admin_home_diger_cari.png'); await page.screenshot({ path: f }); shots.push(f);
        }
        await ctx.close();
    }
    // Aktif grup açılışta görünür bölgeye kaydırılır (admin/home'da değil: son gruptaki bir sayfada olsaydı) — sheet ilk açılışta kaydırma konumu
    {
        const k = sayfaBul('admin', 'definitions'); bag_ctx = { profil: 'admin', sayfa: 'definitions', w: 390 };
        const bag = 'admin/definitions Diğer';
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        await page.click(TASARIM.moreSel); await page.waitForTimeout(500);
        const d = await page.evaluate(() => {
            const g = document.querySelector('#bnSheet details.bn-grup[open]'), r = g.querySelector('.bn-tile.is-current'), sr = document.getElementById('bnSheet').getBoundingClientRect();
            const rr = r ? r.getBoundingClientRect() : null;
            return { slug: g.getAttribute('data-sb-grup'), gorunur: !!rr && rr.top >= sr.top && rr.bottom <= sr.bottom, akt: !!r && r.querySelector('.go').getAttribute('aria-current') === 'page' };
        });
        ok('B', bag, 'Yönetim açık, Tanımlar karosu seçili (aria-current + is-current) ve açılışta görünür bölgede', d.slug === 'yonetim' && d.akt && d.gorunur, JSON.stringify(d));
        // Sabitle modu: anahtarsız karoya dokunmak GİTMEZ, sabitleme değişmez
        await page.click('#bnSheet details.bn-grup[data-sb-grup="cari"] > summary'); await page.waitForTimeout(160);
        await page.click('#bnPinBtn');
        const url0 = page.url();
        await page.click('#bnSheet details.bn-grup[data-sb-grup="cari"] a.bn-tile-link >> nth=0'); await page.waitForTimeout(150);
        const sab = await depo(page, 'asya_nav_sabit_1');
        const msg = await page.textContent('#bnSheetMsg');
        ok('B', bag, 'Sabitle modunda anahtarsız karo sayfayı AÇMAZ, sabitlenmez, nedeni yazılır', page.url() === url0 && (!sab || sab.length === 0) && /sabitlenemez/.test(msg), `url ${page.url()}, sabit ${JSON.stringify(sab)}, mesaj "${msg}"`);
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }
    // Koyu tema + azaltılmış hareket
    {
        const k = sayfaBul('admin', 'definitions'); bag_ctx = { profil: 'admin', sayfa: 'definitions', w: 390 };
        const lum = (c) => { const m = c.match(/[\d.]+/g).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; };
        const oku = (page) => page.evaluate(() => {
            const sum = document.querySelector('#bnSheet details.bn-grup:not([open]) > summary'), cs = getComputedStyle(sum);
            const sheet = getComputedStyle(document.getElementById('bnSheet')), em = document.querySelector('#bnSheet details.bn-grup[open] .bn-tile .go');
            return { bg: cs.backgroundColor, fg: cs.color, kenar: cs.borderLeftColor, sheetBg: sheet.backgroundColor, okSure: getComputedStyle(sum.querySelector('.bn-grup-ok')).transitionDuration };
        });
        let r = await ac(k, { w: 390, h: 844 }, { koyu: true });
        await r.page.click(TASARIM.moreSel); await r.page.waitForTimeout(450);
        let d = await oku(r.page);
        ok('B', 'admin/definitions koyu', 'koyu tema: grup başlığı koyu zemin + açık yazı, sheet koyu (belirteçler temayla değişir)', lum(d.bg) < 0.25 && lum(d.fg) > 0.6 && lum(d.sheetBg) < 0.25, JSON.stringify(d));
        if (!NO_SHOTS) { const f = path.join(SHOTS, '390_admin_definitions_diger_koyu.png'); await r.page.screenshot({ path: f }); shots.push(f); }
        await r.ctx.close();
        r = await ac(k, { w: 390, h: 844 });
        await r.page.click(TASARIM.moreSel); await r.page.waitForTimeout(450);
        d = await oku(r.page);
        ok('B', 'admin/definitions açık', 'açık tema: grup başlığı açık zemin + koyu yazı (koyu kural sızmıyor)', lum(d.bg) > 0.8 && lum(d.fg) < 0.4 && lum(d.sheetBg) > 0.9, JSON.stringify(d));
        if (!NO_SHOTS) { const f = path.join(SHOTS, '390_admin_definitions_diger.png'); await r.page.screenshot({ path: f }); shots.push(f); }
        await r.ctx.close();
        r = await ac(k, { w: 390, h: 844 }, { azHareket: true });
        await r.page.click(TASARIM.moreSel); await r.page.waitForTimeout(300);
        d = await oku(r.page);
        ok('B', 'admin/definitions', 'azaltılmış hareket: ok dönüşü geçişi yok', /^0s/.test(d.okSure), d.okSure);
        await r.ctx.close();
    }

    // 5k — v558: eski Yükleme Planı / Paletleme / Mal Kabul girişleri HER ROL için TÜM menülerden kalktı (sidebar + mobil Diğer + alt çubuk
    //      adayları/dok); yeni karşılığı (muhasebe fiş sayfası) yalnız yetkisi olana görünür. Eski sayfalarda (records/cikmalar/kantar ailesi)
    //      SEÇİLİ görünen giriş yeni karşılığıdır (kullanıcı görebiliyorsa); göremiyorsa hiçbir öğe seçili değildir.
    //      Beklenen değerler manifestin kapı tablosundan (P.izinli) türetilir — uygulamanın nav_eski_esleri()'nden DEĞİL.
    console.log('\n=== 5k) Eski girişler menüde YOK; eski sayfada seçili öğe = yeni karşılığı (sidebar + Diğer + alt çubuk) ===');
    const ESKI = { records: { yeni: 'arac_yukleme_fisleri.php', yeniAd: 'Araç Yükleme Fişleri', eskiAd: 'Yükleme Planı', eskiHref: 'records.php' },
                   cikma:   { yeni: 'palet_fisleri.php',        yeniAd: 'Palet Fişleri',        eskiAd: 'Paletleme',     eskiHref: 'cikmalar.php' },
                   kantar:  { yeni: 'mal_kabul_fisleri.php',    yeniAd: 'Mal Kabul Fişleri',    eskiAd: 'Mal Kabul',     eskiHref: 'kantar.php' } };
    ok('B', 'manifest', 'eski → yeni eşleme (render kapı tablosu) = records→arac_yukleme_fisleri, cikma→palet_fisleri, kantar→mal_kabul_fisleri',
        JSON.stringify(M.eski_yeni) === JSON.stringify({ records: ESKI.records.yeni, cikma: ESKI.cikma.yeni, kantar: ESKI.kantar.yeni }), JSON.stringify(M.eski_yeni));
    ok('B', 'manifest', 'aday olmayan anahtarlar = records, cikma, kantar', JSON.stringify(M.aday_degil) === JSON.stringify(['records', 'cikma', 'kantar']), JSON.stringify(M.aday_degil));
    const K5 = [['admin', 'records'], ['admin', 'cikmalar'], ['admin', 'kantar'], ['admin', 'record_view'], ['admin', 'cikma_view'], ['admin', 'home'],
                ['operator', 'records'], ['operator', 'cikmalar'], ['operator', 'kantar'], ['viewer', 'kantar'], ['dort_sayfa', 'kantar'],
                ['yeni_fis_hepsi', 'records'], ['yeni_fis_hepsi', 'cikmalar'], ['yeni_fis_hepsi', 'kantar'], ['yeni_fis_hepsi', 'home'],
                ['yeni_fis_kismi', 'records'], ['yeni_fis_kismi', 'cikmalar'], ['yeni_fis_kismi', 'kantar'],
                ['muhasebe_sayfa', 'records'], ['muhasebe_sayfa', 'cikmalar'], ['muhasebe_sayfa', 'kantar'],
                ['yalniz_yeni_fis', 'arac_fis'], ['yalniz_yeni_fis', 'mal_kabul_fis']];
    for (const [pk, sk] of K5) {
        const k = sayfaBul(pk, sk);
        if (!k) { ok('B', `${pk}/${sk} eski↔yeni`, 'sayfa üretilmedi', false, 'bottomnav_render.php'); continue; }
        bag_ctx = { profil: pk, sayfa: sk, w: 390 };
        const bag = `${pk}/${sk} eski↔yeni`;
        const P = M.profiller[pk];
        const { ctx, page, hatalar } = await ac(k, { w: 390, h: 844 });
        const sb = await sidebarGruplari(page), sh = await sheetGruplari(page);
        const sbAll = sb.flatMap(g => g.ogeler), shAll = sh.flatMap(g => g.ogeler);
        const bar = await page.evaluate(() => [...document.querySelectorAll('.bn-dock [data-nav], .bottomnav [data-nav]')].map(el => el.getAttribute('data-nav')));
        for (const [ak, E] of Object.entries(ESKI)) {
            const yeniGor = !!P.izinli[E.yeni];
            const sbEski = sbAll.some(o => uygulamaYolu(o.href, k.yol) === E.eskiHref), sbYeni = sbAll.some(o => uygulamaYolu(o.href, k.yol) === E.yeni);
            ok('B', bag, `sidebar: ${E.eskiAd} YOK · ${E.yeniAd} ${yeniGor ? 'VAR' : 'YOK'}`, !sbEski && sbYeni === yeniGor, `eski ${sbEski}, yeni ${sbYeni}`);
            // "Diğer" sayfası: Ana Sayfa'nın hedefiyle aynı sayfa sheet'te tekrarlanmaz (yalniz_yeni_fis: hedef palet_fisleri.php)
            const shEski = shAll.some(o => uygulamaYolu(o.href, k.yol) === E.eskiHref), shYeni = shAll.some(o => uygulamaYolu(o.href, k.yol) === E.yeni);
            ok('B', bag, `Diğer sayfası: ${E.eskiAd} YOK · ${E.yeniAd} ${(yeniGor && E.yeni !== P.home_hedef) ? 'VAR' : 'YOK'}`, !shEski && shYeni === (yeniGor && E.yeni !== P.home_hedef), `eski ${shEski}, yeni ${shYeni}`);
            ok('B', bag, `alt çubuk adayı: ${ak} YOK (hiçbir profilde)`, !P.adaylar.includes(ak), `adaylar [${P.adaylar}]`);
            ok('B', bag, `alt çubukta ${ak} öğesi çizilmedi`, !bar.includes(ak), `[${bar}]`);
        }
        // Seçili öğe: eski sayfada (records/cikma/kantar ailesi) sidebar + Diğer'de SEÇİLİ olan = yeni karşılığı; yeni karşılığı görünmüyorsa HİÇBİR öğe seçili değil.
        // Anahtarsız muhasebe sayfasında kendisi.
        const ak = k.anahtar;
        const seciliBek = ESKI[ak] ? (P.izinli[ESKI[ak].yeni] ? ESKI[ak].yeni : 'YOK')
            : ((M.muhasebe_sayfalar || []).includes(k.bolum) ? k.bolum : null);
        if (seciliBek !== null) {
            const sbSecili = sbAll.filter(o => o.aktif).map(o => uygulamaYolu(o.href, k.yol));
            const bekDizi = seciliBek === 'YOK' ? [] : [seciliBek];
            ok('B', bag, seciliBek === 'YOK' ? 'sidebar: seçili öğe YOK (yeni karşılığı görünmüyor, eski giriş de yok)' : `sidebar: tek seçili öğe = ${seciliBek}`, JSON.stringify(sbSecili) === JSON.stringify(bekDizi), `seçili [${sbSecili}]`);
            if (seciliBek === 'YOK' || seciliBek !== P.home_hedef) {
                const shSecili = shAll.filter(o => o.aktif).map(o => uygulamaYolu(o.href, k.yol));
                ok('B', bag, seciliBek === 'YOK' ? 'Diğer sayfası: seçili öğe YOK' : `Diğer sayfası: tek seçili öğe = ${seciliBek} (sidebar ile aynı)`, JSON.stringify(shSecili) === JSON.stringify(bekDizi), `seçili [${shSecili}]`);
            }
            // Eski sayfada alt çubuk slotlarında hiçbir eski anahtar aktif DEĞİL (slotları yok)
            if (ESKI[ak]) {
                const barAktif = await page.evaluate(() => [...document.querySelectorAll('.bn-dock [aria-current="page"], .bottomnav [aria-current="page"]')].map(el => el.getAttribute('data-nav') || el.id));
                ok('B', bag, 'eski sayfada alt çubuk slotlarında eski anahtar aktif değil (yalnız Ana Sayfa/Diğer/hiçbiri)', !barAktif.includes(ak), `[${barAktif}]`);
            }
        }
        ok('A', bag, 'konsol hatası yok', hatalar.length === 0, hatalar.join(' || '));
        await ctx.close();
    }

    // Kanıt ekran görüntüleri: gerçek çubuk 360/390/430 × açık/koyu + Hal Kayıt
    if (!NO_SHOTS) {
        const k = sayfaBul('operator', 'beyanlar');
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
