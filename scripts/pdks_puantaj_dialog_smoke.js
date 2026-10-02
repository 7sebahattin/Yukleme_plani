// =========================================================
// scripts/pdks_puantaj_dialog_smoke.js — Puantaj Detayı Düzenle/İptal
// <dialog>'larının GERÇEK TARAYICI testi
//
// Neden gerekli: gunluk_isci_puantaj_detay.php her satır için iki native
// <dialog class="pm-dialog"> basar. style.css'teki `.pm-dialog{display:flex}`
// tarayıcının `dialog:not([open]){display:none}` varsayılanını EZİYORDU:
// KAPALI dialog'ların hepsi sayfa açılır açılmaz tablonun altında üst üste
// görünüyor, en üstteki (SON satırın İptal formu) gerçekten gönderilebiliyordu.
// PHP/statik testler bunu göremez — yalnız düzen (layout) motoru gösterir.
//
//   php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
//   node scripts/pdks_puantaj_dialog_smoke.js
//
// Playwright yoksa kendini ATLAR (roles_modal_smoke.js ile aynı kural).
// =========================================================
'use strict';
const fs = require('fs');
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

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_puantaj_dialog.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_puantaj_dialog.html yok. Önce: php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(72)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

// Görünür (kutusu olan) dialog'ların id listesi
function gorunenler(page) {
    return page.evaluate(() => [...document.querySelectorAll('dialog')]
        .filter(d => { const r = d.getBoundingClientRect(); return getComputedStyle(d).display !== 'none' && r.width > 0 && r.height > 0; })
        .map(d => d.id));
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET',   width: 820,  height: 1024 },
                         { ad: 'MOBİL',    width: 390,  height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        await page.goto('file://' + SAYFA);
        await page.waitForTimeout(400);   // açılış animasyonu bitsin — erken ölçüm yanıltır

        const toplam = await page.evaluate(() => document.querySelectorAll('dialog').length);
        ok('sayfada satır başına Düzenle+İptal dialog\'u var (≥ 4)', toplam >= 4, `dialog sayısı: ${toplam}`);

        // 1) Açılışta HİÇBİR dialog görünmemeli (asıl hata)
        const ilk = await gorunenler(page);
        ok('açılışta hiçbir dialog görünmüyor (kapalı dialog gizli)', ilk.length === 0,
           `görünen kapalı dialog'lar: ${ilk.join(', ')}`);

        // Hedef: ortadaki bir satır (son satır DEĞİL — eski hatada en üstte duran oydu)
        const ids = await page.evaluate(() => [...document.querySelectorAll('dialog[id^="edit"]')].map(d => d.id.slice(4)));
        const hedef = ids[Math.floor(ids.length / 2) - 1] || ids[0];

        for (const tur of ['edit', 'void']) {
            const id = tur + hedef;
            await page.evaluate(i => pdksPuantajDialogAc(i), id);
            await page.waitForTimeout(400);
            const acik = await gorunenler(page);
            ok(`${tur}: yalnız TEK dialog görünüyor ve o tıklanan satırınki (${id})`,
               acik.length === 1 && acik[0] === id, `görünenler: ${acik.join(', ')}`);

            const m = await page.evaluate(i => {
                const d = document.getElementById(i);
                const r = d.getBoundingClientRect();
                const btn = d.querySelector('form button:not([type="button"])');
                const b = btn.getBoundingClientRect();
                const ust = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
                return { modal: d.matches(':modal'), sol: r.left, sag: r.right, ust: r.top, alt: r.bottom,
                         vw: innerWidth, vh: innerHeight, btnAlt: b.bottom, tiklanir: btn === ust || btn.contains(ust),
                         tasma: document.documentElement.scrollWidth > innerWidth };
            }, id);
            ok(`${tur}: dialog modal (üst katman) olarak açık`, m.modal);
            ok(`${tur}: dialog ekran içinde (yatay ve dikey)`,
               m.sol >= 0 && m.sag <= m.vw + 0.5 && m.ust >= 0 && m.alt <= m.vh + 0.5, JSON.stringify(m));
            ok(`${tur}: gönder düğmesi ekranda ve tıklanabilir`, m.btnAlt <= m.vh && m.tiklanir, JSON.stringify(m));
            ok(`${tur}: yatay taşma yok`, !m.tasma);

            // Vazgeç ile kapat → yine hiçbir şey görünmemeli
            await page.evaluate(i => document.getElementById(i).close(), id);
            await page.waitForTimeout(100);
            const sonra = await gorunenler(page);
            ok(`${tur}: kapatınca hiçbir dialog görünmüyor`, sonra.length === 0, `görünenler: ${sonra.join(', ')}`);
        }

        // v291 — "➕ Çalışma Ekle" düğmesi + penceresi (geçmişe dönük ekleme)
        const ekleDugme = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Çalışma Ekle/.test(x.textContent)); if (!b) return null; const r = b.getBoundingClientRect(); return { gorunur: r.width > 0 && r.height > 0, ekranda: r.left >= 0 && r.right <= innerWidth + 0.5 }; });
        ok('"➕ Çalışma Ekle" düğmesi görünür ve ekran içinde', !!ekleDugme && ekleDugme.gorunur && ekleDugme.ekranda, JSON.stringify(ekleDugme));
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Çalışma Ekle/.test(x.textContent)).click());
        await page.waitForTimeout(400);
        const ekleAcik = await gorunenler(page);
        ok('ekle: düğmeye basınca yalnız TEK dialog görünüyor (ekle)', ekleAcik.length === 1 && ekleAcik[0] === 'ekle', `görünenler: ${ekleAcik.join(', ')}`);
        const em = await page.evaluate(() => {
            const d = document.getElementById('ekle'); const r = d.getBoundingClientRect();
            const f = d.querySelector('form'); const ad = n => !!f.querySelector('[name="' + n + '"]');
            // Uzun form: gövde kaydırılır (roles_modal_smoke ile aynı kural) — en alta kaydırıp ölç.
            const tasiyor = f.scrollHeight > f.clientHeight + 1;
            f.scrollTop = f.scrollHeight;
            const kaydi = !tasiyor || f.scrollTop > 0;
            const btn = d.querySelector('form button:not([type="button"])'); const b = btn.getBoundingClientRect();
            const ust = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
            const gizli = n => { const e = f.querySelector('[name="' + n + '"]'); return e ? e.value : null; };
            return { modal: d.matches(':modal'), sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight, btnAlt: b.bottom,
                     tiklanir: btn === ust || btn.contains(ust), tasma: document.documentElement.scrollWidth > innerWidth, kaydi,
                     alanlar: ['csrf', 'action', 'work_date', 'entry_date', 'foreman_id', 'worker_card_id', 'worker_type_id', 'entry_clock', 'exit_date', 'exit_clock', 'reason', 'note'].every(ad),
                     eylem: gizli('action'), kartSecenek: f.querySelectorAll('[name="worker_card_id"] option').length, tipSecenek: f.querySelectorAll('[name="worker_type_id"] option').length };
        });
        ok('ekle: modal, ekran içinde, form kaydırılabiliyor, en altta gönder düğmesi görünür ve tıklanabilir, taşma yok', em.modal && em.kaydi && em.sol >= 0 && em.sag <= em.vw + 0.5 && em.ust >= 0 && em.alt <= em.vh + 0.5 && em.btnAlt <= em.vh && em.tiklanir && !em.tasma, JSON.stringify(em));
        ok('ekle: tüm form alanları var, eylem=puantaj_ekle, kart ve tip seçenekleri dolu', em.alanlar && em.eylem === 'puantaj_ekle' && em.kartSecenek >= 2 && em.tipSecenek >= 2, JSON.stringify(em));
        await page.evaluate(() => document.getElementById('ekle').close());
        await page.waitForTimeout(100);
        ok('ekle: kapatınca hiçbir dialog görünmüyor', (await gorunenler(page)).length === 0);
        const rozet = await page.evaluate(() => [...document.querySelectorAll('.pdks-badge-elle')].filter(e => e.getBoundingClientRect().width > 0).map(e => e.textContent.trim()));
        ok('"✍ Elle eklendi" rozeti elle eklenen satırda görünüyor (yalnız o satır)', rozet.length === 1 && /Elle eklendi/.test(rozet[0]), JSON.stringify(rozet));

        // İptal dialog'unda ✕ kapatma düğmesi (Düzenle ile tutarlı)
        const xVar = await page.evaluate(i => !!document.querySelector('#void' + i + ' .pm-close'), hedef);
        ok('İptal dialog\'unda ✕ kapatma düğmesi var', xVar);
        await page.close();
    }

    await browser.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
