// =========================================================
// scripts/pdks_toplu_v560_smoke.js — v560 Toplu İşlem penceresi: Tarih kutusu · «Sadece fazla mesai» kutuları · Servis ·
// «daha önce ekleme» uyarısı + «Yine de ekle» kapısı — GERÇEK TARAYICI (düzen/etkileşim PHP testlerinde görülmez)
//
//   node scripts/pdks_toplu_v560_smoke.js        (php + Playwright gerekir; yoksa ATLAR)
//
// Detay sayfası scripts/pdks_puantaj_dialog_render.php (PUANTAJ_SERVIS=1 → Servis tabloları kurulu) ile basılır;
// ?ajax=toplu_* uçları page.route ile taklit edilir (sunucu kuralları scripts/pdks_elle_onay_sadece_fm_smoke.php'de).
// =========================================================
'use strict';
const fs = require('fs');
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
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(88)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const render = env => execFileSync('php', [path.join(__dirname, 'pdks_puantaj_dialog_render.php')], { cwd: ROOT, env: Object.assign({}, process.env, env), maxBuffer: 32 * 1024 * 1024 }).toString('utf8');
const DETAY = render({ PUANTAJ_SERVIS: '1' });
const DETAY_SERVISSIZ = render({});

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(DETAY); return; }
    if (u.pathname === '/servissiz') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(DETAY_SERVISSIZ); return; }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});
const bugunIso = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const gunKaydir = (iso, n) => { const d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const satirOk = { tip: 'KADIN', worker_type_id: 1, kart_id: null, kart_no: 'KARTSIZ', kartsiz: true, giris: '2026-01-01 17:00:00', cikis: '2026-01-01 18:00:00', durum: 'ok', hata: null };

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', k: 'pc', width: 1280, height: 900 }, { ad: 'MOBİL', k: 'mob', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const jsHata = [];
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        page.on('pageerror', e => jsHata.push(String(e)));
        page.on('console', m => { if (m.type() === 'error' && !/404/.test(m.text())) jsHata.push(m.text()); });

        let onceki = { adet: 3, tipler: { 'Kadın': 2, 'Erkek': 1 }, son: '2026-11-20 14:32:10' };
        const istekler = [];
        await page.route('**/*ajax=toplu_*', async route => {
            const req = route.request(); const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {}; try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde });
            const yanit = uc === 'toplu_ekle'
                ? { ok: true, eklenen: 1, toplu_id: 'TP20260101abcdef12', session_id: 3, redirect: '/?kaydedildi=1' }
                : { ok: true, satirlar: [satirOk], ozet: [{ worker_type_id: 1, tip: 'KADIN', kartli: 0, kartsiz: 1, toplam: 1 }], hatalar: [], uyarilar: [], yeni_mesai: false, onceki_ekleme: onceki, servis: { buyuk: 1, kucuk: 2 } };
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(yanit) });
        });

        await page.goto(KOK);
        await page.waitForTimeout(400);
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Toplu İşlem/.test(x.textContent)).click());
        await page.waitForTimeout(400);

        // ── Tarih kutusu ──
        const t0 = await page.evaluate(() => { const e = document.getElementById('tpTarih'); const r = e.getBoundingClientRect(); return { var: !!e, deger: e.value, max: e.max, tip: e.type, gor: r.width > 0 && r.height >= 40 && r.right <= innerWidth + 0.5, font: parseFloat(getComputedStyle(e).fontSize) }; });
        ok('Tarih kutusu: date, görünür, ≥ 40px yüksek, ekran içinde, 16px+ (iOS zoom önleme)', t0.var && t0.tip === 'date' && t0.gor && t0.font >= 16, JSON.stringify(t0));
        ok('Tarih kutusu: max = bugün (gelecek seçilemez)', t0.max === bugunIso(), t0.max);
        const dun = gunKaydir(t0.deger, -3);
        await page.fill('#tpTarih', dun);
        await page.dispatchEvent('#tpTarih', 'change');
        await page.waitForTimeout(100);
        const t1 = await page.evaluate(() => ({ et: document.getElementById('tpGunEt').textContent, giris: [...document.querySelectorAll('#toplu .tp-giris-gun')].map(x => x.textContent), cikis: [...document.querySelectorAll('#toplu [data-f="exit_date"]')].map(x => x.value), bugunNot: document.getElementById('tpBugunNot').hidden, tarihNot: document.getElementById('tpTarihNot').hidden }));
        const trDun = dun.slice(8, 10) + '.' + dun.slice(5, 7) + '.' + dun.slice(0, 4);
        ok('Tarih değişince: başlık metni, "Giriş günü", çıkış günü kutuları yeni güne çekilir', t1.et === trDun && t1.giris.every(x => x === trDun) && t1.cikis.every(x => x === dun), JSON.stringify(t1));
        ok('Geçmiş güne geçince "bugün" notu gizlenir, kart listesi notu görünür', t1.bugunNot === true && t1.tarihNot === false, JSON.stringify(t1));

        // ── Sadece FM + Servis ──
        const s0 = await page.evaluate(() => {
            const kisi = [...document.querySelectorAll('#tpSfm [data-sfm-kisi]')], saat = [...document.querySelectorAll('#tpSfm [data-sfm-saat]')];
            const r = e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right <= innerWidth + 0.5 && b.left >= 0; };
            return { n: kisi.length, hepsi: kisi.concat(saat).every(r), font: Math.min(...kisi.concat(saat).map(e => parseFloat(getComputedStyle(e).fontSize))), sv: !!document.getElementById('tpSvBuyuk') && r(document.getElementById('tpSvBuyuk')) && r(document.getElementById('tpSvKucuk')),
                     tasma: document.documentElement.scrollWidth > innerWidth, formTasma: (f => f.scrollWidth > f.clientWidth + 1)(document.querySelector('#toplu form')) };
        });
        ok('«Sadece fazla mesai»: Kadın/Erkek/Şeritçi için kişi + saat kutuları görünür, ekran içinde, 16px+', s0.n === 3 && s0.hepsi && s0.font >= 16, JSON.stringify(s0));
        ok('Servis (Büyük/Küçük) kutuları görünür; sayfada/formda yatay taşma yok', s0.sv && !s0.tasma && !s0.formTasma, JSON.stringify(s0));

        await page.fill('[data-sfm-kisi]', '2');   // ilk satır = Kadın
        await page.fill('[data-sfm-saat]', '1');
        await page.fill('#tpSvBuyuk', '1');
        await page.fill('#tpSvKucuk', '2');
        await page.fill('#tpReason', 'Test sebebi');
        await page.waitForTimeout(100);
        const top = await page.evaluate(() => document.getElementById('tpToplam').textContent);
        ok('Toplam kişi sayacı Sadece FM kişilerini de sayar', top.trim() === '2', top);

        // Geçersiz saat → istemci hatası
        await page.fill('[data-sfm-saat]', '1.1');
        await page.click('#tpOnizleBtn');
        await page.waitForTimeout(150);
        const m1 = await page.evaluate(() => ({ g: !document.getElementById('tpMesaj').hidden, t: document.getElementById('tpMesaj').textContent }));
        ok('Sadece FM saati 0,25 adımına uymuyorsa istemci hata verir, istek GİTMEZ', m1.g && /sadece fazla mesai: saat geçersiz/.test(m1.t) && istekler.length === 0, JSON.stringify([m1, istekler.length]));
        await page.fill('[data-sfm-saat]', '1');

        // ── Önizleme: gönderilen yük ──
        await page.click('#tpOnizleBtn');
        await page.waitForTimeout(300);
        const y = istekler[istekler.length - 1] || {};
        const g = y.govde || {};
        const tid = Object.keys((g.hizli || {}).sadece_fm || {})[0];
        ok('Önizleme yükü: work_date = seçilen tarih', g.work_date === dun, g.work_date);
        ok('Önizleme yükü: hizli.sadece_fm = {tip: {kisi:2, saat:"1"}}', !!tid && g.hizli.sadece_fm[tid].kisi === 2 && g.hizli.sadece_fm[tid].saat === '1', JSON.stringify(g.hizli));
        ok('Önizleme yükü: servis {buyuk:1, kucuk:2}', g.servis && g.servis.buyuk === 1 && g.servis.kucuk === 2, JSON.stringify(g.servis));

        // ── Daha önce ekleme uyarısı + «Yine de ekle» kapısı ──
        const u = await page.evaluate(() => { const k = document.querySelector('#tpOnizle .tp-tekrar'); const c = document.getElementById('tpTekrarOnay'); const r = k && k.getBoundingClientRect();
            return { var: !!k, metin: k ? k.textContent : '', cb: !!c, kaydet: document.getElementById('tpKaydetBtn').disabled, ekran: !!r && r.right <= innerWidth + 0.5, sv: /Servis: 1 Büyük \+ 2 Küçük/.test(document.getElementById('tpOnizle').textContent) }; });
        ok('Önizleme: sarı uyarı «bu güne daha önce 3 elle kayıt eklenmiş (Kadın 2, Erkek 1)» + son ekleme zamanı', u.var && /daha önce 3 elle kayıt/.test(u.metin) && /Kadın 2, Erkek 1/.test(u.metin) && /20\.11\.2026 14:32/.test(u.metin), u.metin);
        ok('«Yine de ekle» kutusu var ve işaretlenmeden Kaydet KAPALI', u.cb && u.kaydet === true && u.ekran, JSON.stringify(u));
        ok('Önizleme servis özetini gösterir', u.sv);
        await page.click('#tpTekrarOnay');
        await page.waitForTimeout(80);
        ok('Kutu işaretlenince Kaydet AÇILIR (önizleme geçersiz olmaz)', (await page.evaluate(() => document.getElementById('tpKaydetBtn').disabled)) === false);
        await page.click('#tpTekrarOnay');
        ok('Kutu geri kaldırılınca Kaydet yine kapanır', (await page.evaluate(() => document.getElementById('tpKaydetBtn').disabled)) === true);
        await page.click('#tpTekrarOnay');
        const onceKayit = istekler.length;
        await page.click('#tpKaydetBtn');
        await page.waitForTimeout(300);
        const ekle = istekler.slice(onceKayit).find(x => x.uc === 'toplu_ekle');
        ok('Kaydet isteği onceki_onay=true + aynı tarih/servis/sadece_fm taşır', !!ekle && ekle.govde.onceki_onay === true && ekle.govde.work_date === dun && ekle.govde.servis.buyuk === 1 && !!ekle.govde.hizli.sadece_fm, JSON.stringify(ekle && ekle.govde).slice(0, 300));

        // ── Önceki ekleme YOK → uyarı ve kutu çıkmaz ──
        await page.goto(KOK); await page.waitForTimeout(300);
        onceki = { adet: 0, tipler: {}, son: null };
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Toplu İşlem/.test(x.textContent)).click());
        await page.waitForTimeout(350);
        await page.fill('[data-sfm-kisi]', '1'); await page.fill('[data-sfm-saat]', '1'); await page.fill('#tpReason', 'x');
        await page.click('#tpOnizleBtn'); await page.waitForTimeout(300);
        const v = await page.evaluate(() => ({ uyari: !!document.querySelector('#tpOnizle .tp-tekrar'), kaydet: document.getElementById('tpKaydetBtn').disabled }));
        ok('Önceki ekleme yoksa uyarı/kutu YOK ve Kaydet doğrudan açılır', !v.uyari && v.kaydet === false, JSON.stringify(v));

        // ── Servis tabloları kurulu değilken servis alanı yok ──
        await page.goto(KOK + 'servissiz'); await page.waitForTimeout(300);
        ok('Servis tabloları kurulu değilse servis bölümü GİZLİ (sessiz)', (await page.evaluate(() => !document.getElementById('tpServis'))) === true);
        ok('JS/konsol hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} kontrol BAŞARISIZ.` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
