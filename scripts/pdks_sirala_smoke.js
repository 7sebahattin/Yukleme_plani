// =========================================================
// scripts/pdks_sirala_smoke.js — Mesai Detayı "Kart Hareketleri" başlık sıralaması
// + Kapanış Notu düzenleme penceresinin GERÇEK TARAYICI testi (v299).
//
//   PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 php scripts/pdks_puantaj_dialog_render.php > _test_sirala.html
//   PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 php scripts/pdks_puantaj_dialog_render.php > _test_kapanis_notu.html
//   node scripts/pdks_sirala_smoke.js
//
// Playwright yoksa kendini ATLAR. `playwright install` ÇALIŞTIRMA.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_sirala.html');
const SAYFA_NOT = path.join(ROOT, '_test_kapanis_notu.html');
for (const f of [SAYFA, SAYFA_NOT]) {
    if (!fs.existsSync(f)) { console.error(path.basename(f) + ' yok. Başlıktaki render komutlarını çalıştırın.'); process.exit(1); }
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(78)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

// Tablodaki (görünür sıradaki) satırların i. hücresinin ham değerleri
const kolonDegerleri = (page, idx) => page.evaluate(i =>
    [...document.querySelectorAll('table[data-pdks-sirala] tbody tr')].map(tr => tr.cells[i].getAttribute('data-sirala-deger')), idx);
const kartSirasi = page => page.evaluate(() =>
    [...document.querySelectorAll('table[data-pdks-sirala] tbody tr')].map(tr => tr.cells[0].getAttribute('data-sirala-deger')));
const ariaSortlar = page => page.evaluate(() =>
    [...document.querySelectorAll('table[data-pdks-sirala] thead th[aria-sort]')].map(th => th.textContent.trim() + ':' + th.getAttribute('aria-sort')));
const bos = v => v === null || v === '' || v === '—';

function sirali(degerler, tip, yon) {
    // boşlar sonda mı + dolular tip/yon'a göre mi?
    const ilkBos = degerler.findIndex(bos);
    if (ilkBos !== -1 && degerler.slice(ilkBos).some(v => !bos(v))) return false;
    const dolu = degerler.filter(v => !bos(v));
    for (let i = 1; i < dolu.length; i++) {
        const c = tip === 'metin'
            ? dolu[i - 1].localeCompare(dolu[i], 'tr', { numeric: true, sensitivity: 'base' })
            : Number(dolu[i - 1]) - Number(dolu[i]);
        if (c * yon > 0) return false;
    }
    return true;
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome']
        .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    // ───────────────────────── MASAÜSTÜ ─────────────────────────
    console.log('\n=== MASAÜSTÜ (1280×900) — başlık sıralaması ===');
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const konsolHata = [];
    page.on('pageerror', e => konsolHata.push(String(e)));
    await page.goto('file://' + SAYFA);
    await page.waitForTimeout(400);

    const basliklar = await page.evaluate(() => [...document.querySelectorAll('table[data-pdks-sirala] thead th')].map(th => ({
        ad: th.textContent.trim(), sirala: th.getAttribute('data-sirala'), buton: !!th.querySelector('button[type="button"]'), idx: th.cellIndex })));
    const sirali_ = basliklar.filter(b => b.sirala);
    ok('sıralanabilir başlıklar: Kart No, Tip, Mesai, Giriş, Çıkış, Süre, Durum, Mesai Tanımı (8)',
       sirali_.length === 8 && ['Kart No', 'Tip', 'Mesai', 'Giriş Saati', 'Çıkış Saati', 'Süre', 'Durum', 'Mesai Tanımı'].every((a, i) => sirali_[i].ad === a),
       JSON.stringify(sirali_.map(b => b.ad)));
    ok('her sıralanabilir başlık GERÇEK <button type="button"> içeriyor', sirali_.every(b => b.buton));
    ok('Manuel Çıkış / İşlem başlıkları sıralanabilir DEĞİL',
       basliklar.filter(b => !b.sirala).length >= 2 && basliklar.filter(b => !b.sirala).every(b => !b.buton),
       JSON.stringify(basliklar.filter(b => !b.sirala)));
    ok('açılışta hiçbir başlıkta aria-sort yok', (await ariaSortlar(page)).length === 0);

    // Varsayılan: en yeni son işlem üstte
    const sonlar = await page.evaluate(() => [...document.querySelectorAll('table[data-pdks-sirala] tbody tr')].map(tr => {
        const c = tr.cells[4].getAttribute('data-sirala-deger'), g = tr.cells[3].getAttribute('data-sirala-deger');
        return Number(c || g);
    }));
    ok(`varsayılan sıra: son işlem (çıkış yoksa giriş) AZALAN, en yeni üstte (${sonlar.length} satır)`,
       sonlar.length >= 10 && sonlar.every((v, i) => i === 0 || sonlar[i - 1] >= v), JSON.stringify(sonlar));
    const varsayilanSira = await kartSirasi(page);
    ok('en erken girişli ama en geç çıkışlı kart (K001) en üstte', varsayilanSira[0] === 'k001', varsayilanSira.join(','));
    const ilkCikis = await kolonDegerleri(page, 4);
    ok('veride hem çıkışlı hem çıkışsız satır var (boş-sonda testi anlamlı)', ilkCikis.some(bos) && ilkCikis.some(v => !bos(v)));

    // Her başlık: artan → azalan → varsayılan
    for (const b of sirali_) {
        const btn = page.locator('table[data-pdks-sirala] thead th').nth(b.idx).locator('button');
        await btn.click();
        let aria = await ariaSortlar(page);
        let d = await kolonDegerleri(page, b.idx);
        ok(`[${b.ad}] 1. tık: ARTAN, tek aria-sort, boşlar sonda`, aria.length === 1 && aria[0].endsWith(':ascending') && sirali(d, b.sirala, 1), aria.join('|') + ' ' + d.join(','));
        await btn.click();
        aria = await ariaSortlar(page); d = await kolonDegerleri(page, b.idx);
        ok(`[${b.ad}] 2. tık: AZALAN, tek aria-sort, boşlar sonda`, aria.length === 1 && aria[0].endsWith(':descending') && sirali(d, b.sirala, -1), aria.join('|') + ' ' + d.join(','));
        await btn.click();
        aria = await ariaSortlar(page);
        const s3 = await kartSirasi(page);
        ok(`[${b.ad}] 3. tık: VARSAYILAN sıra (aria-sort yok)`, aria.length === 0 && JSON.stringify(s3) === JSON.stringify(varsayilanSira), aria.join('|') + ' ' + s3.join(','));
    }

    // Başlık değişince önceki aria-sort temizlenir
    await page.locator('table[data-pdks-sirala] thead th').nth(sirali_[0].idx).locator('button').click();
    await page.locator('table[data-pdks-sirala] thead th').nth(sirali_[1].idx).locator('button').click();
    const ikiBaslik = await ariaSortlar(page);
    ok('başka başlığa geçince yalnız YENİ başlıkta aria-sort (artan)', ikiBaslik.length === 1 && ikiBaslik[0].endsWith(':ascending') && ikiBaslik[0].startsWith('Tip'), ikiBaslik.join('|'));

    // Doğal sıralama: K2 < K10
    await page.locator('table[data-pdks-sirala] thead th').nth(0).locator('button').click();
    const kartlar = await kartSirasi(page);
    ok('Kart No artan: k2 < k10 (doğal/numeric)', kartlar.indexOf('k2') !== -1 && kartlar.indexOf('k2') < kartlar.indexOf('k10'), kartlar.join(','));
    ok('Kart No artan: k001 < k2', kartlar.indexOf('k001') < kartlar.indexOf('k2'), kartlar.join(','));
    // Durum metin sıralaması emoji ile başlamıyor (ham değer harfle başlar)
    const durumHam = await kolonDegerleri(page, 6);
    ok('Durum ham değeri emojisiz (harf/rakamla başlar)', durumHam.every(v => v === '' || /^[\p{L}\p{N}]/u.test(v)), durumHam.join('|'));

    // Klavye: Enter ve Boşluk
    await page.reload(); await page.waitForTimeout(400);
    const girisBtn = page.locator('table[data-pdks-sirala] thead th').nth(3).locator('button');
    await girisBtn.focus();
    await page.keyboard.press('Enter');
    let a = await ariaSortlar(page);
    ok('klavye: Enter → ARTAN', a.length === 1 && a[0].endsWith(':ascending'), a.join('|'));
    await page.keyboard.press('Space');
    a = await ariaSortlar(page);
    ok('klavye: Boşluk → AZALAN', a.length === 1 && a[0].endsWith(':descending'), a.join('|'));
    const durumMetni = await page.evaluate(() => (document.querySelector('.pdks-sirala-durum') || {}).textContent || '');
    ok('aria-live durum metni güncel ("Giriş Saati" + azalan)', /Giriş Saati/.test(durumMetni) && /azalan/.test(durumMetni), durumMetni);
    const liveAttr = await page.evaluate(() => { const e = document.querySelector('.pdks-sirala-durum'); return e ? e.getAttribute('aria-live') : null; });
    ok('durum satırı aria-live="polite"', liveAttr === 'polite', String(liveAttr));
    const okGorunur = await page.evaluate(() => {
        const o = document.querySelector('th[aria-sort] .pdks-sirala-ok'); if (!o) return false;
        const r = o.getBoundingClientRect(), cs = getComputedStyle(o, '::before'), cs2 = getComputedStyle(o, '::after');
        return r.width > 0 && r.height > 0 && (cs.borderBottomWidth !== '0px' || cs2.borderTopWidth !== '0px');
    });
    ok('aktif başlıkta görünür sıralama oku var', okGorunur);

    // Satır taşınınca inline onclick ve dialog'lar çalışıyor
    await page.locator('table[data-pdks-sirala] thead th').nth(0).locator('button').click();  // Kart no artan
    await page.waitForTimeout(100);
    const duzenleSonuc = await page.evaluate(async () => {
        const btn = [...document.querySelectorAll('table[data-pdks-sirala] tbody tr')].pop().querySelector('button[onclick*="pdksPuantajDialogAc(\'edit"]');
        if (!btn) return { var: false };
        const id = /'(edit\d+)'/.exec(btn.getAttribute('onclick'))[1];
        btn.click();
        await new Promise(r => setTimeout(r, 400));
        const d = document.getElementById(id), r = d.getBoundingClientRect();
        const acik = d.open && r.width > 0 && r.height > 0;
        d.close();
        return { var: true, acik, id };
    });
    ok('sıralamadan sonra satırın "Düzenle" düğmesi (inline onclick) dialog\'u açıyor', duzenleSonuc.var && duzenleSonuc.acik, JSON.stringify(duzenleSonuc));
    const dialogBenzersiz = await page.evaluate(() => { const ids = [...document.querySelectorAll('dialog')].map(d => d.id); return new Set(ids).size === ids.length; });
    ok('dialog id\'leri benzersiz / bozulmamış', dialogBenzersiz);
    ok('sayfa hatası yok', konsolHata.length === 0, konsolHata.join('\n'));
    await page.close();

    // ───────────────────────── MOBİL ─────────────────────────
    console.log('\n=== MOBİL (390×844) — Sırala seçici ===');
    const mob = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await mob.goto('file://' + SAYFA);
    await mob.waitForTimeout(400);
    const mDur = await mob.evaluate(() => {
        const sec = document.getElementById('kartSiralaSec'), r = sec.getBoundingClientRect();
        const tablo = document.querySelector('table[data-pdks-sirala]').closest('.table-wrap');
        return { gorunur: r.width > 0 && r.height > 0, font: parseFloat(getComputedStyle(sec).fontSize), tabloGizli: getComputedStyle(tablo).display === 'none',
                 tasma: document.documentElement.scrollWidth - window.innerWidth };
    });
    ok('Sırala seçici görünür, 16px', mDur.gorunur && mDur.font >= 16, JSON.stringify(mDur));
    ok('mobilde masaüstü tablo gizli', mDur.tabloGizli);
    ok('390px: yatay taşma yok', mDur.tasma <= 0, 'taşma: ' + mDur.tasma);
    const kartKartlari = () => mob.evaluate(() => [...document.querySelectorAll('#kartKartlar > [data-sirala-oge]')].map(c => ({
        kart: c.getAttribute('data-sd-kart'), giris: Number(c.getAttribute('data-sd-giris')), cikis: c.getAttribute('data-sd-cikis'), sure: c.getAttribute('data-sd-sure') })));
    const mVars = await kartKartlari();
    ok('mobil kartlar da varsayılan: son işlem azalan (K001 üstte)', mVars[0].kart === 'k001' && mVars.length >= 10, JSON.stringify(mVars.slice(0, 3)));
    await mob.selectOption('#kartSiralaSec', 'giris:asc');
    let mk = await kartKartlari();
    ok('Giriş (eski önce): giriş artan', mk.every((c, i) => i === 0 || mk[i - 1].giris <= c.giris));
    await mob.selectOption('#kartSiralaSec', 'cikis:desc');
    mk = await kartKartlari();
    ok('Çıkış (yeni önce): çıkış azalan, çıkışsızlar sonda',
       sirali(mk.map(c => c.cikis), 'zaman', -1), JSON.stringify(mk.map(c => c.cikis)));
    await mob.selectOption('#kartSiralaSec', 'kart:asc');
    mk = await kartKartlari();
    ok('Kart no (A → Z): k2 < k10', mk.findIndex(c => c.kart === 'k2') < mk.findIndex(c => c.kart === 'k10'), mk.map(c => c.kart).join(','));
    await mob.selectOption('#kartSiralaSec', 'sure:desc');
    mk = await kartKartlari();
    ok('Süre (uzun önce): süre azalan, süresizler sonda', sirali(mk.map(c => c.sure), 'sayi', -1), JSON.stringify(mk.map(c => c.sure)));
    await mob.selectOption('#kartSiralaSec', '');
    const mSon = await kartKartlari();
    ok('"Son işlem" seçilince varsayılan sıraya dönüyor', JSON.stringify(mSon) === JSON.stringify(mVars));
    const mTasma = await mob.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    ok('sıralama sonrası 390px yatay taşma yok', mTasma <= 0, 'taşma: ' + mTasma);
    await mob.close();

    // ───────────────────────── KAPANIŞ NOTU ─────────────────────────
    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 }, { ad: 'MOBİL', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) — Kapanış Notu penceresi ===`);
        const p = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        await p.goto('file://' + SAYFA_NOT);
        await p.waitForTimeout(400);
        const ilk = await p.evaluate(() => {
            const d = document.getElementById('kapanisNotu'); if (!d) return { var: false };
            const r = d.getBoundingClientRect();
            return { var: true, gorunur: getComputedStyle(d).display !== 'none' && r.width > 0 && r.height > 0 };
        });
        ok('Kapanış Notu dialog\'u var ve kapalıyken GİZLİ', ilk.var && !ilk.gorunur, JSON.stringify(ilk));
        const dugme = p.locator('.pdks-not-duzenle:visible').first();
        ok('"✏ Notu Düzenle" düğmesi görünür (admin, kapalı mesai)', await dugme.count() === 1);
        await dugme.click();
        await p.waitForTimeout(400);   // açılış animasyonu bitsin
        const olc = await p.evaluate(() => {
            const d = document.getElementById('kapanisNotu'), r = d.getBoundingClientRect();
            const ta = d.querySelector('textarea[name="kapanis_notu"]');
            const alt = d.querySelector('button[type="submit"]');
            const ar = alt.getBoundingClientRect();
            const ustte = document.elementFromPoint(ar.left + ar.width / 2, ar.top + ar.height / 2);
            const csrf = d.querySelector('input[name="csrf"]'), act = d.querySelector('input[name="action"]');
            return { acik: d.open, footerEkranda: ar.bottom <= window.innerHeight && ar.top >= 0, tiklanir: !!ustte && (ustte === alt || alt.contains(ustte)),
                     deger: ta.value, maxlen: ta.maxLength, csrf: !!csrf && csrf.value.length > 0, action: act && act.value, tasma: document.documentElement.scrollWidth - window.innerWidth,
                     dialogSigar: r.left >= -1 && r.right <= window.innerWidth + 1 && r.bottom <= window.innerHeight + 1,
                     sessionAlani: !!d.querySelector('input[name="id"], input[name="session_id"]') };
        });
        ok('pencere açık, dialog ekrana sığıyor', olc.acik && olc.dialogSigar, JSON.stringify(olc));
        ok('Kaydet düğmesi ekranda ve TIKLANABİLİR (üstünü başka öğe örtmüyor)', olc.footerEkranda && olc.tiklanir, JSON.stringify(olc));
        ok('textarea mevcut notla dolu (ham metin), maxlength=1000', olc.deger.includes('<b>kalın?</b>') && olc.maxlen === 1000, JSON.stringify(olc.deger));
        ok('CSRF + action=kapanis_notu var, mesai id\'si İSTEMCİDEN gönderilmiyor', olc.csrf && olc.action === 'kapanis_notu' && !olc.sessionAlani, JSON.stringify(olc));
        ok('yatay taşma yok', olc.tasma <= 0, 'taşma: ' + olc.tasma);
        await p.keyboard.press('Escape');
        await p.waitForTimeout(100);
        ok('Esc ile kapanıyor', !(await p.evaluate(() => document.getElementById('kapanisNotu').open)));
        const enj = await p.evaluate(() => !!document.querySelector('.pdks-not-metin b'));
        ok('not metni h() ile kaçırılmış (DOM\'da <b> öğesi yok)', !enj);
        await p.close();
    }

    await browser.close();
    console.log(hata ? `\n${hata} HATA` : '\nTÜM KONTROLLER GEÇTİ');
    process.exit(hata ? 1 : 0);
})();
