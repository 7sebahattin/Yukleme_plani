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
    // (A) grubu da bunu kullanır: çubuğun KAPSAYICISI.
    barSel: '.bottomnav',
    // (B) — öğe seçicisi ve etiket seçicisi
    itemSel: '.bottomnav-item',
    labelSel: '.bottomnav-label',
    // Profil → soldan sağa görünen etiketler (config/helpers.php render_footer)
    etiketler: {
        admin:       ['Ana Sayfa', 'Yüklemeler', 'Bildirim', 'Personel', 'Raporlar'],
        operator:    ['Ana Sayfa', 'Yüklemeler', 'Bildirim', 'Personel', 'Raporlar'],
        muhasebe:    ['Ana Sayfa', 'Yüklemeler', 'Personel', 'Raporlar'],
        viewer:      ['Ana Sayfa', 'Yüklemeler', 'Raporlar'],
        ozel_gunluk: ['Ana Sayfa', 'Personel'],
        ozel_pdks:   ['Ana Sayfa'],
    },
    // Aktif işaret biçimi: <a class="bottomnav-item active">; aria-current KULLANILMIYOR.
    aktifSinif: 'active',
    ariaCurrentKullanilir: false,
    // Sayfa → aktif olması beklenen hedef(ler) (null = hiçbiri). Güncel davranışın
    // kaydıdır; 'hks' iki aktif öğe içerir (BİLİNEN HATA BN-HKS-IKI-AKTIF).
    aktifHedef: {
        home: ['index.php'], records: ['records.php'], record_view: ['records.php'],
        cikma_view: [], cikmalar: [], beyanlar: [], kantar: [],
        hks: ['index.php', 'halkayit/index.php'],
        ptak: ['personel_takip.php'], ptak_alt: ['personel_takip.php'], personel: ['personel_takip.php'],
        reports: ['reports.php'], maliyet: [], hesap: [], definitions: [],
    },
    // Ortadaki yükseltilmiş düğme (records.write olan profillerde)
    yukseltilmis: { sel: '.bottomnav-raised', hedef: 'halkayit/index.php', etiket: 'Bildirim' },
    // Çubuk yüksekliği (px, güvenli alan 0 iken) — ölçülen: ~62px
    yukseklik: [56, 72],
};

// ═════════════════════════════════════════════════════════════════════════
// BİLİNEN HATALAR — mevcut kodda bulunan, bu görevde düzeltilmeyen
// ═════════════════════════════════════════════════════════════════════════
const BILINEN = {
    // render_footer: $is_home = in_array($cur, ['index.php','']) — halkayit/index.php'nin
    // basename'i de 'index.php' → Ana Sayfa + Bildirim birlikte aktif. Sidebar
    // '&& !$in_hks' ile korur, bottomnav korumaz.
    'BN-HKS-IKI-AKTIF': (c) => c.sayfa === 'hks',
    // Sidebar maliyet_* sayfalarında Raporlar'ı vurgular ($a_rep = ... || $a_mal);
    // bottomnav'daki $is_reports yalnız reports.php → maliyet'te hiçbir öğe aktif değil.
    'BN-MALIYET-AKTIF-YOK': (c) => c.sayfa === 'maliyet',
    // Yalnız kalıcı PDKS izni olan rol: çubuktaki TEK bağlantı index.php'dir;
    // dashboard.read yok, first_allowed_page() null → index.php 403 basar.
    'BN-PDKS-ANASAYFA-403': (c) => c.profil === 'ozel_pdks',
    // halkayit/index.php kendi <style>'ında iframe için 58px + güvenli alan bırakır;
    // çubuk kutusu ~63.6px, yükseltilmiş daire ~68.6px → iframe'in altı çubuğun arkasında.
    'BN-HKS-IFRAME-ORTUSME': (c) => c.sayfa === 'hks',
};

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
    out.linkler = [...bar.querySelectorAll('a[href]')].map(a => ({ href: a.getAttribute('href'), metin: a.textContent.trim().replace(/\s+/g, ' ') }));
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
        const bolumLinki = m.linkler.some(l => uygulamaYolu(l.href, k.yol) === k.bolum);
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

        // B — güncel tasarım
        const etiketler = await page.$$eval(`${TASARIM.barSel} ${TASARIM.itemSel} ${TASARIM.labelSel}`, els => els.map(e => e.textContent.trim()));
        const bek = TASARIM.etiketler[k.profil];
        ok('B', bag, `etiketler/sıra = [${bek.join(', ')}]`, JSON.stringify(etiketler) === JSON.stringify(bek),
            `ölçülen [${etiketler.join(', ')}]`);
        const bAktif = await page.$$eval(`${TASARIM.barSel} ${TASARIM.itemSel}.${TASARIM.aktifSinif}`, els => els.map(e => e.getAttribute('href')));
        const bAktifYol = bAktif.map(h => uygulamaYolu(h, k.yol)).sort();
        const bekAktif = [...(TASARIM.aktifHedef[k.sayfa] || [])].filter(y => m.linkler.some(l => uygulamaYolu(l.href, k.yol) === y)).sort();
        ok('B', bag, `.${TASARIM.aktifSinif} öğeleri = [${bekAktif.join(', ')}]`, JSON.stringify(bAktifYol) === JSON.stringify(bekAktif),
            `ölçülen [${bAktifYol.join(', ')}]`);
        const ariaSay = m.hedefler.filter(h => h.ariaCurrent).length;
        ok('B', bag, `aria-current ${TASARIM.ariaCurrentKullanilir ? 'kullanılıyor' : 'kullanılmıyor'}`,
            TASARIM.ariaCurrentKullanilir ? ariaSay > 0 : ariaSay === 0, `${ariaSay} öğede aria-current`);
        const yuk = await page.$$eval(`${TASARIM.barSel} ${TASARIM.yukseltilmis.sel}`, els => els.map(e => [e.getAttribute('href'), e.textContent.trim()]));
        const yukBek = profilIzinli(k.profil)[TASARIM.yukseltilmis.hedef];
        ok('B', bag, `yükseltilmiş orta düğme ${yukBek ? 'VAR' : 'YOK'}`,
            yukBek ? (yuk.length === 1 && uygulamaYolu(yuk[0][0], k.yol) === TASARIM.yukseltilmis.hedef && yuk[0][1].includes(TASARIM.yukseltilmis.etiket)) : yuk.length === 0,
            `ölçülen ${JSON.stringify(yuk)}`);

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
    const olcumTablosu = [];
    for (const k of temsilci) {
        for (const ekran of EKRANLAR) {
            bag_ctx = { profil: k.profil, sayfa: k.sayfa, w: ekran.w };
            const bag = `${k.profil}/${k.sayfa}@${ekran.w}`;
            const { ctx, page, hatalar } = await ac(k, ekran);
            let m = await page.evaluate(olc, TASARIM.barSel);
            const mobil = ekran.w <= MOBIL_MAX;

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

                // B — güncel yükseklik
                ok('B', bag, `çubuk yüksekliği ${TASARIM.yukseklik[0]}–${TASARIM.yukseklik[1]}px`,
                    m.rect.height >= TASARIM.yukseklik[0] && m.rect.height <= TASARIM.yukseklik[1], `ölçülen ${r1(m.rect.height)}px`);
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
