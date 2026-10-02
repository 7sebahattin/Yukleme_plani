// =========================================================
// scripts/pdks_gecmis_ekle_smoke.js — Günlük Puantaj LİSTESİNDEKİ "Geçmişe
// Dönük Çalışma Ekle" (v291) GERÇEK TARAYICI testi
//
// Mesai Detayı'ndaki "Çalışma Ekle" penceresi pdks_puantaj_dialog_smoke.js'te
// ölçülür. Bu test LİSTE sayfasını (çavuş seçmeli) yönetici olarak gerçekten
// render eder (scripts/pdks_puantaj_dialog_render.php, PUANTAJ_SAYFA=liste):
//   • geçmiş gün  → düğme pencereyi açar; "?ekle=1" ile pencere kendiliğinden açılır
//   • bugün       → düğme, dünün tarihli listesine ("?ekle=1") giden bağlantıdır
//
//   node scripts/pdks_gecmis_ekle_smoke.js     (php + Playwright gerekir; yoksa ATLAR)
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(80)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
function render(tarih, hedef) {
    const html = execFileSync('php', [path.join(__dirname, 'pdks_puantaj_dialog_render.php')], {
        cwd: ROOT, env: Object.assign({}, process.env, { PUANTAJ_SAYFA: 'liste', PUANTAJ_TARIH: tarih }), maxBuffer: 32 * 1024 * 1024,
    });
    fs.writeFileSync(hedef, html);
}
const DUN = path.join(ROOT, '_test_puantaj_liste_dun.html');
const BUGUN = path.join(ROOT, '_test_puantaj_liste_bugun.html');
render('dun', DUN);
render('bugun', BUGUN);

const gorunen = page => page.evaluate(() => [...document.querySelectorAll('dialog')]
    .filter(d => { const r = d.getBoundingClientRect(); return getComputedStyle(d).display !== 'none' && r.width > 0 && r.height > 0; }).map(d => d.id));

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    const dunIso = new Date(Date.now() - 86400000);
    const dunYmd = `${dunIso.getFullYear()}-${String(dunIso.getMonth() + 1).padStart(2, '0')}-${String(dunIso.getDate()).padStart(2, '0')}`;

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 }, { ad: 'TABLET', width: 820, height: 1024 }, { ad: 'MOBİL', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const hatalar = [];
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        page.on('pageerror', e => hatalar.push(String(e)));
        page.on('console', m => { if (m.type() === 'error') hatalar.push(m.text()); });

        // ── Geçmiş gün ──
        await page.goto('file://' + DUN);
        await page.waitForTimeout(400);
        const dugme = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Geçmişe Dönük Çalışma Ekle/.test(x.textContent)); if (!b) return null; const r = b.getBoundingClientRect(); return { gorunur: r.width > 0 && r.height > 0, ekranda: r.left >= 0 && r.right <= innerWidth + 0.5 }; });
        ok('geçmiş gün: "Geçmişe Dönük Çalışma Ekle" düğmesi görünür ve ekran içinde', !!dugme && dugme.gorunur && dugme.ekranda, JSON.stringify(dugme));
        ok('geçmiş gün: açılışta hiçbir dialog görünmüyor', (await gorunen(page)).length === 0, (await gorunen(page)).join(','));
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Geçmişe Dönük Çalışma Ekle/.test(x.textContent)).click());
        await page.waitForTimeout(400);
        const acik = await gorunen(page);
        ok('düğmeye basınca yalnız TEK dialog (ekle) açılıyor', acik.length === 1 && acik[0] === 'ekle', acik.join(','));
        const m = await page.evaluate(() => {
            const d = document.getElementById('ekle'); const f = d.querySelector('form'); const r = d.getBoundingClientRect();
            const sel = n => f.querySelector('[name="' + n + '"]');
            const tasiyor = f.scrollHeight > f.clientHeight + 1; f.scrollTop = f.scrollHeight;
            const btn = f.querySelector('button:not([type="button"])'); const b = btn.getBoundingClientRect();
            const ust = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
            return { modal: d.matches(':modal'), sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight,
                     btnGorunur: b.bottom <= innerHeight && (btn === ust || btn.contains(ust)), kaydi: !tasiyor || f.scrollTop > 0, tasma: document.documentElement.scrollWidth > innerWidth,
                     cavusSecenek: sel('foreman_id').tagName === 'SELECT' ? sel('foreman_id').options.length : -1,
                     kartSecenek: sel('worker_card_id').options.length, tipSecenek: sel('worker_type_id').options.length,
                     workDate: sel('work_date').value, entryDate: sel('entry_date').value, exitDate: sel('exit_date').value, eylem: sel('action').value,
                     zorunlu: ['foreman_id', 'worker_card_id', 'worker_type_id', 'entry_clock', 'exit_date', 'exit_clock', 'reason'].every(n => sel(n).required) };
        });
        ok('pencere modal, ekran içinde, form kaydırılıyor, Ekle düğmesi görünür/tıklanabilir', m.modal && m.sol >= 0 && m.sag <= m.vw + 0.5 && m.ust >= 0 && m.alt <= m.vh + 0.5 && m.kaydi && m.btnGorunur && !m.tasma, JSON.stringify(m));
        ok('çavuş SEÇİLİR (liste sayfası): çavuş seçenekleri dolu', m.cavusSecenek >= 2, JSON.stringify(m));
        ok('kart ve işçi tipi seçenekleri dolu (o gün boş kartlar)', m.kartSecenek >= 3 && m.tipSecenek >= 3, JSON.stringify(m));
        ok(`mesai günü = filtredeki geçmiş gün (${dunYmd}); giriş günü aynı; çıkış günü varsayılan aynı`, m.workDate === dunYmd && m.entryDate === dunYmd && m.exitDate === dunYmd, JSON.stringify(m));
        ok('eylem=puantaj_ekle ve zorunlu alanlar işaretli', m.eylem === 'puantaj_ekle' && m.zorunlu, JSON.stringify(m));
        // Boş gönderim tarayıcı doğrulamasında takılır (sunucuya gitmeden)
        const gecerli = await page.evaluate(() => document.querySelector('#ekle form').checkValidity());
        ok('boş form GEÇERSİZ (tarayıcı zorunlu alan doğrulaması)', gecerli === false);
        await page.evaluate(() => document.getElementById('ekle').close());

        // ── ?ekle=1 → kendiliğinden açılır ──
        await page.goto('file://' + DUN + '?ekle=1');
        await page.waitForTimeout(500);
        const oto = await gorunen(page);
        ok('"?ekle=1" ile pencere KENDİLİĞİNDEN açılıyor', oto.length === 1 && oto[0] === 'ekle', oto.join(','));
        await page.evaluate(() => document.getElementById('ekle').close());

        // ── Bugün ──
        await page.goto('file://' + BUGUN);
        await page.waitForTimeout(400);
        const bu = await page.evaluate(() => {
            const a = [...document.querySelectorAll('a')].find(x => /Geçmişe Dönük Çalışma Ekle/.test(x.textContent));
            const r = a ? a.getBoundingClientRect() : null;
            return { href: a ? a.getAttribute('href') : null, gorunur: !!r && r.width > 0 && r.height > 0, dialog: !!document.getElementById('ekle'), dugme: [...document.querySelectorAll('button')].some(x => /Geçmişe Dönük Çalışma Ekle/.test(x.textContent)) };
        });
        ok('bugün: düğme dünün listesine giden bağlantı (?tarih=dün&ekle=1), dialog YOK', bu.gorunur && bu.href === `gunluk_isci_puantaj.php?tarih=${dunYmd}&ekle=1` && !bu.dialog && !bu.dugme, JSON.stringify(bu));
        ok('JS hatası / konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await page.close();
    }

    await browser.close();
    for (const f of [DUN, BUGUN]) { try { fs.unlinkSync(f); } catch (e) { /* yoksay */ } }
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
