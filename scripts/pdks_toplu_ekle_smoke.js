// =========================================================
// scripts/pdks_toplu_ekle_smoke.js — v294 "Toplu İşlem" + kartsız mesai arayüzü,
// GERÇEK TARAYICI testi (düzen hatalarını PHP/statik testler göremez)
//
//   node scripts/pdks_toplu_ekle_smoke.js        (php + Playwright gerekir; yoksa ATLAR)
//
// Detay ve liste sayfaları scripts/pdks_puantaj_dialog_render.php ile (yönetici, gerçek
// CSS, bellek içi SQLite) basılır ve küçük bir http sunucusundan açılır; ?ajax= uçları
// page.route ile taklit edilir (sunucu kuralları PHP testlerinde sınanır).
// Ekran görüntüleri: SHOT_DIR (varsayılan: sistem geçici klasörü).
// =========================================================
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const SHOT = process.env.SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(84)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
function render(env) {
    return execFileSync('php', [path.join(__dirname, 'pdks_puantaj_dialog_render.php')], {
        cwd: ROOT, env: Object.assign({}, process.env, env), maxBuffer: 32 * 1024 * 1024 });
}
const DETAY = render({}).toString('utf8');
const LISTE = render({ PUANTAJ_SAYFA: 'liste', PUANTAJ_TARIH: 'bugun' }).toString('utf8');

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(DETAY); return; }
    if (u.pathname === '/liste') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(LISTE); return; }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

const gorunen = page => page.evaluate(() => [...document.querySelectorAll('dialog')]
    .filter(d => { const r = d.getBoundingClientRect(); return getComputedStyle(d).display !== 'none' && r.width > 0 && r.height > 0; }).map(d => d.id));
const satir = (tip, no, hata, kartsiz) => ({ tip, worker_type_id: 1, kart_id: kartsiz ? null : 1, kart_no: kartsiz ? 'KARTSIZ' : no, kartsiz: !!kartsiz,
    giris: '2026-01-01 00:00:00', cikis: '2026-01-01 00:05:00', durum: hata ? 'hata' : 'ok', hata: hata || null });

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', k: 'pc', width: 1280, height: 900 }, { ad: 'TABLET', k: 'tab', width: 820, height: 1024 }, { ad: 'MOBİL', k: 'mob', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const jsHata = [];
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        page.on('pageerror', e => jsHata.push(String(e)));
        page.on('console', m => { if (m.type() === 'error' && !/404/.test(m.text())) jsHata.push(m.text()); });

        let onizleYanit = { ok: true, satirlar: [satir('KADIN', 'F001'), satir('KADIN', 'KARTSIZ', null, true)], ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 1, kartsiz: 1, toplam: 2 }], hatalar: [], yeni_mesai: false };
        const istekler = [];
        await page.route('**/*ajax=toplu_*', async route => {
            const req = route.request(); const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {}; try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde, ct: req.headers()['content-type'] });
            let yanit = onizleYanit;
            if (uc === 'toplu_ekle') yanit = { ok: true, eklenen: 2, toplu_id: 'TP20260101abcdef12', session_id: 3, redirect: '/?kaydedildi=1' };
            await new Promise(r => setTimeout(r, uc === 'toplu_ekle' ? 150 : 0));
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });

        await page.goto(KOK);
        await page.waitForTimeout(400);
        ok('açılışta hiçbir dialog görünmüyor', (await gorunen(page)).length === 0, (await gorunen(page)).join(','));
        ok('yatay taşma yok (sayfa)', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

        // ── Kartsız rozeti ──
        const rozet = await page.evaluate(() => [...document.querySelectorAll('.pdks-badge-kartsiz')].filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.right <= innerWidth + 0.5; }).length);
        ok('"Kartsız" rozeti görünür (≥ 2 kartsız satır, masaüstü tablo / mobil kart)', rozet >= 2, 'görünen rozet: ' + rozet);
        const elleVar = await page.evaluate(() => [...document.querySelectorAll('.pdks-badge-elle')].some(e => e.getBoundingClientRect().width > 0));
        ok('"✍ Elle eklendi" rozeti de duruyor', elleVar);

        // ── Kartsız dönemin Düzenle penceresi: kart seçilemez ──
        const ks = await page.evaluate(() => {
            const d = [...document.querySelectorAll('dialog[id^="edit"]')].find(x => x.querySelector('.pdks-badge-kartsiz'));
            const n = [...document.querySelectorAll('dialog[id^="edit"]')].find(x => !x.querySelector('.pdks-badge-kartsiz'));
            return { id: d && d.id, kartSelect: !!(d && d.querySelector('select[name="worker_card_id"]')), gizli: d && d.querySelector('input[type=hidden][name="worker_card_id"]') ? d.querySelector('input[type=hidden][name="worker_card_id"]').value : null,
                     normalSelect: !!(n && n.querySelector('select[name="worker_card_id"]')) };
        });
        ok('kartsız dönemin Düzenle penceresinde kart <select> YOK, kart sabit + gizli alan', ks.id && !ks.kartSelect && /^\d+$/.test(ks.gizli || ''), JSON.stringify(ks));
        ok('normal dönemin Düzenle penceresinde kart <select> var', ks.normalSelect);
        await page.evaluate(i => pdksPuantajDialogAc(i), ks.id);
        await page.waitForTimeout(350);
        ok('kartsız Düzenle penceresi açılıyor (tek dialog)', (await gorunen(page)).join() === ks.id);
        await page.evaluate(i => document.getElementById(i).close(), ks.id);

        // ── Tekil ekle: kartsız seçeneği + çıkış zorunluluğu ──
        await page.evaluate(() => pdksPuantajDialogAc('ekle'));
        await page.waitForTimeout(350);
        const ek = await page.evaluate(() => {
            const f = document.querySelector('#ekle form'); const sel = f.elements['worker_card_id'];
            const durum = () => ({ ed: f.elements['exit_date'].required, ec: f.elements['exit_clock'].required, ipucu: (() => { const p = f.querySelector('.ekle-cikis-ipucu'); return p.hidden ? '' : p.textContent; })() });
            const opts = [...sel.options].map(o => o.value);
            const bos = durum(); sel.value = 'kartsiz'; sel.dispatchEvent(new Event('change', { bubbles: true })); const ks = durum();
            sel.value = sel.options[2].value; sel.dispatchEvent(new Event('change', { bubbles: true })); const kart = durum();
            sel.value = 'kartsiz'; sel.dispatchEvent(new Event('change', { bubbles: true }));
            return { opts: opts.slice(0, 3), bos, ks, kart };
        });
        ok('kart listesi: "— Boş kart seçin —", sonra "kartsiz" seçeneği', ek.opts[0] === '' && ek.opts[1] === 'kartsiz', JSON.stringify(ek.opts));
        ok('bugün + kart seçili: çıkış alanları ZORUNLU DEĞİL, ipucu "içeride yazılır"', !ek.kart.ed && !ek.kart.ec && /içeride yazılır/.test(ek.kart.ipucu), JSON.stringify(ek.kart));
        ok('kartsız seçilince çıkış tarih+saat ZORUNLU, ipucu kartsız uyarısı', ek.ks.ed && ek.ks.ec && /Kartsız/.test(ek.ks.ipucu), JSON.stringify(ek.ks));
        if (ekran.k === 'mob' && SHOT) {
            await page.waitForTimeout(100);
            await page.screenshot({ path: path.join(SHOT, 'v294_ekle_kartsiz_mob.png') });
        }
        await page.evaluate(() => document.getElementById('ekle').close());

        // ── Toplu İşlem penceresi ──
        const dugme = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find(x => /Toplu İşlem/.test(x.textContent)); if (!b) return null; const r = b.getBoundingClientRect(); return { g: r.width > 0, e: r.left >= 0 && r.right <= innerWidth + 0.5 }; });
        ok('"👥 Toplu İşlem" düğmesi görünür ve ekran içinde', !!dugme && dugme.g && dugme.e, JSON.stringify(dugme));
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Toplu İşlem/.test(x.textContent)).click());
        await page.waitForTimeout(400);
        const acik = await gorunen(page);
        ok('düğmeye basınca yalnız TEK dialog (toplu) açılıyor', acik.length === 1 && acik[0] === 'toplu', acik.join(','));
        const geo = () => page.evaluate(() => {
            const d = document.getElementById('toplu'); const r = d.getBoundingClientRect(); const f = d.querySelector('form');
            return { modal: d.matches(':modal'), sol: r.left, sag: r.right, ust: r.top, alt: r.bottom, vw: innerWidth, vh: innerHeight,
                     formTasma: f.scrollWidth > f.clientWidth + 1, sayfaTasma: document.documentElement.scrollWidth > innerWidth, kaydirilir: f.scrollHeight > f.clientHeight + 1 };
        });
        let g = await geo();
        ok('toplu: modal, ekran içinde (yatay + dikey), yatay taşma yok', g.modal && g.sol >= 0 && g.sag <= g.vw + 0.5 && g.ust >= 0 && g.alt <= g.vh + 0.5 && !g.formTasma && !g.sayfaTasma, JSON.stringify(g));
        // v297: kart listesi VARSAYILAN GİZLİ; "Kartlı giriş ekle" anahtarı açınca görünür.
        const gizli = await page.evaluate(() => [...document.querySelectorAll('#toplu .tp-grup')].map(g => {
            const l = g.querySelector('.tp-kartli'); const t = g.querySelector('[data-tp="kartli"]');
            return { kapali: !t.checked, gizli: l.hidden && getComputedStyle(l).display === 'none', arac: !!g.querySelector('.tp-kart-arac'), tOlcu: (() => { const r = g.querySelector('.tv-toggle-kutu').getBoundingClientRect(); return r.width > 0 && r.right <= innerWidth + 0.5; })() };
        }));
        ok('kart listesi + "İlk N" satırı AÇILIŞTA gizli; "Kartlı giriş ekle" anahtarı görünür ve kapalı', gizli.length === 2 && gizli.every(x => x.kapali && x.gizli && x.arac && x.tOlcu), JSON.stringify(gizli));
        await page.evaluate(() => document.querySelectorAll('#toplu .tp-grup').forEach(g => { const t = g.querySelector('[data-tp="kartli"]'); t.click(); }));
        await page.waitForTimeout(100);
        const ilk = await page.evaluate(() => ({ kaydet: document.getElementById('tpKaydetBtn').disabled, gruplar: document.querySelectorAll('#toplu .tp-grup').length,
            kutular: [...document.querySelectorAll('#toplu .tp-grup')].map(x => x.querySelectorAll('.tp-kartlar input').length),
            acik: [...document.querySelectorAll('#toplu .tp-kartli')].every(l => !l.hidden && l.getBoundingClientRect().height > 0),
            kutuKaydirilir: [...document.querySelectorAll('#toplu .tp-kartlar')].every(x => x.scrollHeight > x.clientHeight + 1 && x.clientHeight <= 160) }));
        ok('Kaydet başlangıçta PASİF; iki grup (KADIN/ERKEK); anahtar açınca liste görünür, kutular sınırlı yükseklikte kaydırılıyor', ilk.kaydet && ilk.gruplar === 2 && ilk.kutular.every(n => n >= 38) && ilk.acik && ilk.kutuKaydirilir, JSON.stringify(ilk));
        if (SHOT && (ekran.k === 'pc' || ekran.k === 'mob')) {
            await page.evaluate(k => { document.querySelectorAll('#toplu .tp-grup')[0].scrollIntoView({ block: k === 'pc' ? 'center' : 'start' }); document.querySelectorAll('#toplu .tp-grup')[0].querySelector('.tp-kartlar input').click(); }, ekran.k);
            await page.waitForTimeout(120);
            await page.screenshot({ path: path.join(SHOT, 'v297_toplu_' + ekran.k + '.png') });
            await page.evaluate(() => document.querySelectorAll('#toplu .tp-grup')[0].querySelector('.tp-kartlar input').click());
        }
        const sutun = await page.evaluate(() => getComputedStyle(document.querySelector('#toplu .tp-kartlar')).gridTemplateColumns.split(' ').length);
        ok('kart sütunu: masaüstü 5, tablet 4, mobil 3', sutun === ({ pc: 5, tab: 4, mob: 3 })[ekran.k], String(sutun));

        // "İlk N boş kartı seç", sayaç, çapraz kilit
        const sec = await page.evaluate(() => {
            const gs = [...document.querySelectorAll('#toplu .tp-grup')]; const [a, b] = gs;
            const n = (g, f) => g.querySelector('[data-f="' + f + '"]');
            const say = g => g.querySelectorAll('.tp-kartlar input:checked').length;
            n(a, 'ilkn').value = '5'; a.querySelector('[data-tp="sec"]').click();
            const a5 = say(a), sayac1 = a.querySelector('.tp-sayac').textContent;
            const ilkKutu = a.querySelector('.tp-kartlar input:checked'); const baska = b.querySelector('.tp-kartlar input[value="' + ilkKutu.value + '"]');
            const kilit = baska.disabled;
            n(b, 'ilkn').value = '30'; b.querySelector('[data-tp="sec"]').click();
            const b30 = say(b); const ortak = [...b.querySelectorAll('.tp-kartlar input:checked')].filter(c => a.querySelector('.tp-kartlar input[value="' + c.value + '"]:checked')).length;
            n(b, 'kartsiz_adet').value = '2'; n(b, 'kartsiz_adet').dispatchEvent(new Event('input', { bubbles: true }));
            const sayac2 = b.querySelector('.tp-sayac').textContent; const top = document.getElementById('tpToplam').textContent;
            // A'daki işareti kaldır → B'de serbest kalır
            ilkKutu.checked = false; ilkKutu.dispatchEvent(new Event('change', { bubbles: true }));
            const serbest = !baska.disabled;
            ilkKutu.checked = true; ilkKutu.dispatchEvent(new Event('change', { bubbles: true }));
            return { a5, sayac1, kilit, b30, ortak, sayac2, top, serbest };
        });
        ok('"İlk 5 boş kartı seç" 5 kart işaretler; sayaç "5 kartlı + 0 kartsız = 5 kişi"', sec.a5 === 5 && sec.sayac1 === '5 kartlı + 0 kartsız = 5 kişi', JSON.stringify(sec));
        ok('A grubunda işaretli kart B grubunda PASİF (ve işaret kalkınca serbest)', sec.kilit && sec.serbest, JSON.stringify(sec));
        ok('B grubu "ilk 30" A\'nın kartlarını atlar (ortak kart yok); sayaç + genel toplam doğru', sec.b30 === 30 && sec.ortak === 0 && sec.sayac2 === '30 kartlı + 2 kartsız = 32 kişi' && sec.top === '37', JSON.stringify(sec));

        // v297: anahtarı KAPATINCA o grubun seçili kartları temizlenir, sayaç düşer, liste gizlenir; geri açınca boş gelir
        const kapat = await page.evaluate(() => {
            const gs = [...document.querySelectorAll('#toplu .tp-grup')]; const [a, b] = gs;
            const say = g => g.querySelectorAll('.tp-kartlar input:checked').length;
            const onceB = say(b), onceA = say(a);
            const ta = a.querySelector('[data-tp="kartli"]'); ta.click();
            const r = { onceA, onceB, sonraA: say(a), sonraB: say(b), gizli: a.querySelector('.tp-kartli').hidden, sayac: a.querySelector('.tp-sayac').textContent, top: document.getElementById('tpToplam').textContent,
                        serbest: !b.querySelector('.tp-kartlar input[value="' + (a.querySelector('.tp-kartlar input').value) + '"]').disabled };
            ta.click();   // geri aç → boş gelir
            r.acikBos = !a.querySelector('.tp-kartli').hidden && say(a) === 0;
            a.querySelector('[data-f="ilkn"]').value = '5'; a.querySelector('[data-tp="sec"]').click();   // A'yı eski hâline getir (5 kart)
            const kutu = a.querySelector('.tp-kartlar input:checked');
            return Object.assign(r, { yeniden: say(a), kutu: !!kutu });
        });
        ok('anahtar KAPANINCA grubun kartları temizlenir (sayaç/toplam düşer, liste gizlenir, diğer grup etkilenmez, kart serbest kalır)',
            kapat.onceA === 5 && kapat.sonraA === 0 && kapat.sonraB === kapat.onceB && kapat.gizli && /^0 kartlı/.test(kapat.sayac) && kapat.top === String(kapat.onceB + 2) && kapat.serbest && kapat.acikBos && kapat.yeniden === 5, JSON.stringify(kapat));

        // Önizleme kapısı — alanları doldur
        await page.evaluate(() => {
            const d = document.getElementById('toplu'); const [a, b] = [...d.querySelectorAll('.tp-grup')];
            const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); };
            set(a.querySelector('[data-f="entry_clock"]'), '00:00'); set(a.querySelector('[data-f="exit_clock"]'), '00:05');
            set(b.querySelector('[data-f="entry_clock"]'), '00:00'); set(b.querySelector('[data-f="exit_clock"]'), '00:05');
            set(document.getElementById('tpReason'), 'Test sebebi');
            b.querySelector('[data-tp="temizle"]').click(); set(b.querySelector('[data-f="kartsiz_adet"]'), '0');
        });
        ok('önizleme olmadan Kaydet PASİF', await page.evaluate(() => document.getElementById('tpKaydetBtn').disabled));
        // Hatalı önizleme
        onizleYanit = { ok: false, satirlar: [satir('KADIN', 'F001'), satir('KADIN', 'F002', 'Kart o gün başka dönemde kullanılmış.'), satir('KADIN', 'KARTSIZ', null, true)],
                        ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 2, kartsiz: 1, toplam: 3 }], hatalar: ['KADIN: giriş saati mesai günü dışında.'], yeni_mesai: false };
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        const hp = await page.evaluate(() => ({ kaydet: document.getElementById('tpKaydetBtn').disabled, hataSatir: document.querySelectorAll('#tpOnizle .tp-satir-hata').length,
            renk: (() => { const e = document.querySelector('#tpOnizle .tp-satir-hata td'); return e ? getComputedStyle(e).backgroundColor : ''; })(),
            genel: !!document.querySelector('#tpOnizle .tp-genel-hata'), satirlar: document.querySelectorAll('#tpOnizle tbody tr').length }));
        ok('hatalı önizleme: hata satırı KIRMIZI, genel hata üstte, Kaydet PASİF', hp.kaydet && hp.hataSatir === 1 && /rgb\(2[0-9]{2}, 2[0-9]{2}, 2[0-9]{2}\)|rgb\(253, 236, 236\)/.test(hp.renk) && hp.genel && hp.satirlar === 3, JSON.stringify(hp));
        // Temiz önizleme
        onizleYanit = { ok: true, satirlar: [satir('KADIN', 'F001'), satir('KADIN', 'KARTSIZ', null, true)], ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 1, kartsiz: 1, toplam: 2 }], hatalar: [], yeni_mesai: false };
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        const tp = await page.evaluate(() => ({ kaydet: document.getElementById('tpKaydetBtn').disabled, satirlar: document.querySelectorAll('#tpOnizle tbody tr').length, ozet: document.querySelector('#tpOnizle .tp-ozet') && document.querySelector('#tpOnizle .tp-ozet').textContent }));
        ok('temiz önizleme: Kaydet AÇILIYOR; tablo + tip özeti görünüyor', !tp.kaydet && tp.satirlar === 2 && /KADIN: 1 kartlı \+ 1 kartsız = 2/.test(tp.ozet || ''), JSON.stringify(tp));
        const ist = istekler.filter(i => i.uc === 'toplu_onizle').pop();
        ok('önizleme isteği JSON + csrf + grup verisi (kart_ids, kartsiz_adet, çıkış)', ist && /application\/json/.test(ist.ct) && ist.govde.csrf === 'testcsrf' && ist.govde.gruplar.length === 1
            && ist.govde.gruplar[0].kart_ids.length === 5 && ist.govde.gruplar[0].exit_clock === '00:05' && ist.govde.reason === 'Test sebebi', JSON.stringify(ist && ist.govde));
        ok('önizleme isteği istek_id taşır (32 hex, sunucuda üretilmiş)', ist && /^[a-f0-9]{32}$/.test(ist.govde.istek_id || ''), JSON.stringify(ist && ist.govde.istek_id));
        // Engellemeyen uyarı: sarı kutu tablonun üstünde, Kaydet AÇIK kalır
        const temizYanit = onizleYanit;
        onizleYanit = Object.assign({}, temizYanit, { uyarilar: ['Bu mesaide aynı saatlerde 1 kartsız KADIN kaydı zaten var — tekrar eklemediğinizden emin olun.'] });
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        const uy = await page.evaluate(() => { const u = document.querySelector('#tpOnizle .tp-uyari'); const t = document.querySelector('#tpOnizle table');
            return { var: !!u, metin: u ? u.textContent : '', renk: u ? getComputedStyle(u).backgroundColor : '', ustte: !!(u && t && (u.compareDocumentPosition(t) & Node.DOCUMENT_POSITION_FOLLOWING)),
                     gorunur: !!(u && u.getBoundingClientRect().width > 0), kaydet: document.getElementById('tpKaydetBtn').disabled }; });
        const rgb = (uy.renk.match(/\d+/g) || []).map(Number);
        ok('uyarı (uyarilar) SARI kutuda, tablonun üstünde; Kaydet PASİF DEĞİL', uy.var && uy.gorunur && uy.ustte && !uy.kaydet && /kartsız KADIN/.test(uy.metin)
            && rgb.length >= 3 && rgb[0] >= 230 && rgb[1] >= 200 && rgb[2] <= rgb[1] - 10, JSON.stringify(uy));
        onizleYanit = temizYanit;
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        // Ekran görüntüleri (önizlemeli)
        await page.evaluate(() => document.getElementById('tpOnizle').scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(150);
        if (SHOT && ekran.k === 'pc') await page.screenshot({ path: path.join(SHOT, 'v297_toplu_onizle_pc.png') });
        if (SHOT && ekran.k === 'mob') await page.screenshot({ path: path.join(SHOT, 'v297_toplu_onizle_mob.png') });
        // Girdi değişince Kaydet tekrar pasif
        await page.evaluate(() => { const e = document.getElementById('tpNote'); e.value = 'x'; e.dispatchEvent(new Event('input', { bubbles: true })); });
        ok('önizlemeden sonra herhangi bir girdi değişince Kaydet tekrar PASİF', await page.evaluate(() => document.getElementById('tpKaydetBtn').disabled));
        await page.evaluate(() => { document.getElementById('tpNote').value = ''; document.getElementById('tpNote').dispatchEvent(new Event('input', { bubbles: true })); });
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        await page.evaluate(() => { const c = document.querySelector('#toplu .tp-grup .tp-kartlar input:not(:checked):not(:disabled)'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); });
        ok('kart işareti değişince de Kaydet PASİF', await page.evaluate(() => document.getElementById('tpKaydetBtn').disabled));
        await page.evaluate(() => { const c = document.querySelector('#toplu .tp-grup .tp-kartlar input:checked:last-of-type'); });

        // Çok satırlı önizleme (>30): özet + yalnız hatalılar + "tümünü göster"
        const cok = Array.from({ length: 40 }, (_, i) => satir('KADIN', 'F' + String(i + 1).padStart(3, '0'), i === 7 ? 'Çakışma' : null));
        onizleYanit = { ok: false, satirlar: cok, ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 40, kartsiz: 0, toplam: 40 }], hatalar: [], yeni_mesai: false };
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        const c1 = await page.evaluate(() => ({ satir: document.querySelectorAll('#tpOnizle tbody tr').length, dugme: (document.querySelector('#tpOnizle .tp-hepsi') || {}).textContent }));
        await page.evaluate(() => document.querySelector('#tpOnizle .tp-hepsi').click());
        const c2 = await page.evaluate(() => document.querySelectorAll('#tpOnizle tbody tr').length);
        ok('>30 satır: yalnız hatalı satır + "Tümünü göster (40 satır)"; tıklayınca 40 satır', c1.satir === 1 && /Tümünü göster \(40/.test(c1.dugme || '') && c2 === 40, JSON.stringify([c1, c2]));
        g = await geo();
        ok('uzun önizlemede form kaydırılıyor, yatay taşma yok', g.kaydirilir && !g.formTasma && !g.sayfaTasma, JSON.stringify(g));
        // Alt çubuk: en alta kaydır → Önizle/Kaydet/Vazgeç görünür ve tıklanabilir
        const alt = await page.evaluate(() => {
            const f = document.querySelector('#toplu form'); f.scrollTop = f.scrollHeight;
            return ['tpOnizleBtn', 'tpKaydetBtn'].map(id => { const b = document.getElementById(id); const r = b.getBoundingClientRect(); const u = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                return r.top >= 0 && r.bottom <= innerHeight && (u === b || b.contains(u)); }).concat([(() => { const b = document.querySelector('#toplu .tp-aksiyon .btn-ghost'); const r = b.getBoundingClientRect(); const u = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return r.bottom <= innerHeight && (u === b || b.contains(u)); })()]);
        });
        ok('en altta Önizle / Kaydet / Vazgeç görünür ve tıklanabilir (alt çubuk)', alt.every(Boolean), JSON.stringify(alt));

        // >250 engeli
        const asim = await page.evaluate(() => {
            const a = document.querySelector('#toplu .tp-grup'); const k = a.querySelector('[data-f="kartsiz_adet"]');
            k.value = '251'; k.dispatchEvent(new Event('input', { bubbles: true }));
            return { gor: !document.getElementById('tpAsim').hidden, onizle: document.getElementById('tpOnizleBtn').disabled, top: document.getElementById('tpToplam').textContent };
        });
        ok('toplam > 250: uyarı görünür, Önizle PASİF (istemci engeli)', asim.gor && asim.onizle, JSON.stringify(asim));
        const onceki = istekler.length;
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(150);
        ok('>250 iken sunucuya istek GİTMEDİ', istekler.length === onceki);
        await page.evaluate(() => { const k = document.querySelector('#toplu .tp-grup [data-f="kartsiz_adet"]'); k.value = '0'; k.dispatchEvent(new Event('input', { bubbles: true })); });

        // Kaydet: çift dokunma korumalı, başarıda yönlendirme
        onizleYanit = { ok: true, satirlar: [satir('KADIN', 'F001'), satir('KADIN', 'KARTSIZ', null, true)], ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 1, kartsiz: 1, toplam: 2 }], hatalar: [], yeni_mesai: false };
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(300);
        ok('temiz önizleme sonrası Kaydet tekrar AÇIK', await page.evaluate(() => !document.getElementById('tpKaydetBtn').disabled));
        await page.evaluate(() => { const b = document.getElementById('tpKaydetBtn'); b.click(); b.click(); });
        await page.waitForTimeout(700);
        const ekleIst = istekler.filter(i => i.uc === 'toplu_ekle');
        ok('Kaydet → TEK ?ajax=toplu_ekle isteği (çift dokunma korumalı), başarıda yönlendirme', ekleIst.length === 1 && /kaydedildi=1/.test(page.url()), JSON.stringify([ekleIst.length, page.url()]));
        const idler = [...new Set(istekler.map(i => i.govde.istek_id))];
        ok('tüm önizleme/Kaydet istekleri AYNI istek_id\'yi taşır (tekrar gönderim anahtarı)', idler.length === 1 && /^[a-f0-9]{32}$/.test(idler[0] || '') && ekleIst[0].govde.istek_id === idler[0], JSON.stringify(idler));

        // ── Geri Al penceresi ──
        await page.goto(KOK); await page.waitForTimeout(300);
        const gb = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-tp-geri]')].find(x => { const r = x.getBoundingClientRect(); return r.width > 0; }); if (!b) return null; b.click(); return b.getAttribute('data-tp-geri'); });
        await page.waitForTimeout(350);
        const ga = await page.evaluate(() => { const d = document.getElementById('tpGeriAl'); const r = d.getBoundingClientRect(); const f = d.querySelector('form');
            return { acik: d.open, modal: d.matches(':modal'), id: document.getElementById('tpGeriAlId').value, zorunlu: f.elements['reason'].required, gecerli: f.checkValidity(), ekranda: r.left >= 0 && r.right <= innerWidth + 0.5 && r.bottom <= innerHeight + 0.5 }; });
        ok('"↩ Geri Al" penceresi açılıyor: toplu kimliği yazılı, sebep ZORUNLU (boşken form geçersiz)', gb && ga.acik && ga.modal && ga.id === gb && ga.zorunlu && !ga.gecerli && ga.ekranda, JSON.stringify([gb, ga]));
        ok('Toplu İşlemler bölümü görünür (satır: kartlı/kartsız, sebep)', await page.evaluate(() => [...document.querySelectorAll('h2')].some(h => /Toplu İşlemler/.test(h.textContent) && h.getBoundingClientRect().width > 0)));
        ok('İşlem Geçmişi toplu işlem satırını içeriyor', await page.evaluate(() => /Toplu çalışma eklendi/.test(document.body.textContent)));
        await page.evaluate(() => document.getElementById('tpGeriAl').close());
        if (SHOT && ekran.k === 'pc') {
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.screenshot({ path: path.join(SHOT, 'v294_detay_pc.png'), fullPage: true });
        }
        ok('JS hatası / konsol hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }

    // ── Liste sayfası (bugün): düğme doğrudan pencere açar; toplu pencerede çavuş seçilir ──
    console.log('\n=== LİSTE SAYFASI (bugün) ===');
    {
        const jsHata = [];
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        page.on('pageerror', e => jsHata.push(String(e)));
        let govde = null;
        await page.route('**/*ajax=toplu_onizle*', async route => { govde = JSON.parse(route.request().postData() || '{}'); await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: false, satirlar: [], ozet: [], hatalar: ['x'] }) }); });
        await page.goto(KOK + 'liste'); await page.waitForTimeout(400);
        const l = await page.evaluate(() => ({ ekleDugme: [...document.querySelectorAll('button')].some(b => /Çalışma Ekle/.test(b.textContent) && b.getBoundingClientRect().width > 0),
            link: [...document.querySelectorAll('a')].some(a => /Çalışma Ekle/.test(a.textContent)), topluDugme: [...document.querySelectorAll('button')].some(b => /Toplu İşlem/.test(b.textContent)),
            cavusSel: !!document.getElementById('tpCavus'), ekleDlg: !!document.getElementById('ekle'), url: document.getElementById('toplu').getAttribute('data-url-ekle') }));
        ok('liste/bugün: "Çalışma Ekle" DÜĞME (dünün bağlantısı değil) + "Toplu İşlem" düğmesi, dialog\'lar sayfada', l.ekleDugme && !l.link && l.topluDugme && l.cavusSel && l.ekleDlg, JSON.stringify(l));
        await page.evaluate(() => [...document.querySelectorAll('button')].find(b => /Çalışma Ekle/.test(b.textContent)).click());
        await page.waitForTimeout(350);
        ok('liste/bugün: düğme pencereyi doğrudan açıyor (ekle)', (await gorunen(page)).join() === 'ekle');
        await page.evaluate(() => document.getElementById('ekle').close());
        await page.evaluate(() => [...document.querySelectorAll('button')].find(b => /Toplu İşlem/.test(b.textContent)).click());
        await page.waitForTimeout(350);
        ok('liste/bugün: Toplu İşlem tek dialog olarak açılıyor', (await gorunen(page)).join() === 'toplu');
        await page.evaluate(() => { document.getElementById('tpOnizleBtn').click(); });
        await page.waitForTimeout(200);
        const gmsg = await page.evaluate(() => document.getElementById('tpMesaj').textContent);
        ok('çavuş seçilmeden / sebep boşken önizleme istemcide reddedilir (sunucuya gitmez)', /Çavuş seçin/.test(gmsg) && /neden/i.test(gmsg) && govde === null, gmsg);
        await page.evaluate(() => { const s = document.getElementById('tpCavus'); s.value = s.options[1].value; s.dispatchEvent(new Event('change', { bubbles: true }));
            const a = document.querySelector('#toplu .tp-grup'); a.querySelector('[data-tp="kartli"]').click(); a.querySelector('[data-tp="sec"]').click();
            const set = (el, v) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); };
            set(a.querySelector('[data-f="entry_clock"]'), '00:00'); set(document.getElementById('tpReason'), 'Sebep'); });
        await page.evaluate(() => document.getElementById('tpOnizleBtn').click());
        await page.waitForTimeout(250);
        ok('liste: önizleme isteği seçilen çavuşu (foreman_id) taşıyor; bugün çıkışsız grup kabul', govde && govde.foreman_id > 0 && govde.gruplar[0].exit_date === '' && govde.gruplar[0].kart_ids.length === 10, JSON.stringify(govde));
        ok('liste: JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
