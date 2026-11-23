// =========================================================
// scripts/pdks_toplu_duzen_smoke.js — v504 Toplu İşlem penceresi: satır satır "Kişi sayısı" düzeni +
// çoklu Fazla Mesai ("+ Ekle"/sil) — GERÇEK TARAYICI (düzen hatasını PHP/statik testler göremez).
//   node scripts/pdks_toplu_duzen_smoke.js     (php + Playwright gerekir; yoksa ATLAR)
// Sayfa scripts/pdks_puantaj_dialog_render.php ile basılır; ?ajax=toplu_* istekleri page.route ile taklit edilir.
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
const SHOT = process.env.SHOT_DIR || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(86)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const DETAY = execFileSync('php', [path.join(__dirname, 'pdks_puantaj_dialog_render.php')], { cwd: ROOT, maxBuffer: 32 * 1024 * 1024 }).toString('utf8');
const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(DETAY); return; }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

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
        const istekler = [];
        await page.route('**/*ajax=toplu_*', async route => {
            const req = route.request(); let g = {}; try { g = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push(g);
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: false, satirlar: [], ozet: [], hatalar: ['taklit'] }) });
        });
        await page.goto(KOK); await page.waitForTimeout(400);
        await page.evaluate(() => [...document.querySelectorAll('button')].find(x => /Toplu İşlem/.test(x.textContent)).click());
        await page.waitForTimeout(450);

        // ── Satır satır düzen ──
        const d = await page.evaluate(() => [...document.querySelectorAll('#toplu .tv-hizli-satir')].map(s => {
            const r = s.getBoundingClientRect(), t = s.querySelector('.tv-hizli-tip');
            return { kod: s.getAttribute('data-hizli-satir'), baslik: t ? t.textContent.trim() : '', top: Math.round(r.top), sol: Math.round(r.left), sag: Math.round(r.right),
                     kutular: [...s.querySelectorAll('input')].map(i => i.getAttribute('aria-label')),
                     font: [...s.querySelectorAll('input')].map(i => parseFloat(getComputedStyle(i).fontSize)),
                     tasma: s.scrollWidth > s.clientWidth + 1 };
        }));
        const kodlar = d.map(x => x.kod);
        ok('satırlar sırayla: KADIN, ERKEK, RAMPACI, SERITCI (her tip ayrı satır)', JSON.stringify(kodlar) === JSON.stringify(['KADIN', 'ERKEK', 'RAMPACI', 'SERITCI']), JSON.stringify(kodlar));
        ok('her satır ALT ALTA (ayrı yatay şerit; üst değerleri artan)', d.every((x, i) => i === 0 || x.top > d[i - 1].top), JSON.stringify(d.map(x => x.top)));
        const etiket = x => x.kutular.map(k => k.replace(/^\S+\s/, ''));
        ok('KADIN/ERKEK: Tam · Yarım · Çift yevmiye', JSON.stringify(etiket(d[0])) === '["Tam","Yarım","Çift yevmiye"]' && JSON.stringify(etiket(d[1])) === '["Tam","Yarım","Çift yevmiye"]', JSON.stringify(d.slice(0, 2).map(etiket)));
        ok('RAMPACI/ŞERİTÇİ: Tam · Çift yevmiye (yarım YOK)', JSON.stringify(etiket(d[2])) === '["Tam","Çift yevmiye"]' && JSON.stringify(etiket(d[3])) === '["Tam","Çift yevmiye"]', JSON.stringify(d.slice(2).map(etiket)));
        ok('tip başlıkları büyük harf, görünür', d.every(x => x.baslik === x.baslik.toLocaleUpperCase('tr-TR') && x.baslik.length > 2), JSON.stringify(d.map(x => x.baslik)));
        ok('hızlı kutu input\'ları 16px+ (iOS zoom önleme)', d.every(x => x.font.every(f => f >= 16)), JSON.stringify(d.map(x => x.font)));
        ok('satır içinde yatay taşma yok', d.every(x => !x.tasma));
        ok('satırlar ekran içinde', d.every(x => x.sol >= 0 && x.sag <= ekran.width + 0.5), JSON.stringify(d.map(x => [x.sol, x.sag])));
        if (ekran.k === 'pc') {
            const ayni = await page.evaluate(() => [...document.querySelectorAll('#toplu .tv-hizli-satir')].map(s => { const t = s.querySelector('.tv-hizli-tip').getBoundingClientRect(), k = s.querySelector('input').getBoundingClientRect(); return t.right <= k.left + 1 && Math.abs((t.top + t.bottom) / 2 - (k.top + k.bottom) / 2) < 40; }));
            ok('masaüstü: tip başlığı kutuların SOLUNDA', ayni.every(Boolean), JSON.stringify(ayni));
        }

        // ── Çoklu FM ──
        const fm = () => page.evaluate(() => ({ satir: document.querySelectorAll('#tpFmSatirlar [data-fm-satir]').length,
            sil: [...document.querySelectorAll('#tpFmSatirlar [data-fm-sil]')].map(b => !b.hidden), on: document.getElementById('tpFmOnizleme').textContent,
            ekleGorunur: (() => { const r = document.getElementById('tpFmEkle').getBoundingClientRect(); return r.width > 0 && r.left >= 0 && r.right <= innerWidth + 0.5; })() }));
        let f = await fm();
        ok('başlangıçta 1 FM satırı, silme düğmesi gizli, "+ Ekle" görünür', f.satir === 1 && f.sil.every(x => !x) && f.ekleGorunur, JSON.stringify(f));
        await page.evaluate(() => document.getElementById('tpFmEkle').scrollIntoView());
        const doldur = async (sel, v) => { await page.fill(sel, String(v)); };
        await doldur('[data-hizli-tam][data-hizli-kod="KADIN"]', 45);
        await doldur('[data-hizli-yarim][data-hizli-kod="KADIN"]', 1);
        await doldur('[data-hizli-tam][data-hizli-kod="ERKEK"]', 21);
        await page.click('#tpFmEkle'); await page.click('#tpFmEkle');
        f = await fm();
        ok('"+ Ekle" iki kez → 3 satır; silme düğmeleri görünür', f.satir === 3 && f.sil.every(Boolean), JSON.stringify(f));
        const kutu = i => page.locator('#tpFmSatirlar [data-fm-satir]').nth(i);
        const yaz = async (i, k, s) => { await kutu(i).locator('[data-fm-kisi]').fill(String(k)); await kutu(i).locator('[data-fm-saat]').fill(String(s)); };
        await yaz(0, 40, '1'); await yaz(1, 4, '3'); await yaz(2, 1, '2');
        f = await fm();
        ok('önizleme: 3 satır + toplam 45 kişi / havuz 67', /1\. satır/.test(f.on) && /2\. satır/.test(f.on) && /3\. satır/.test(f.on) && /Toplam FM: 45 kişi \/ havuz 67/.test(f.on), f.on);
        ok('önizleme 1. satır: 27 Kadın Tam · 12 Erkek Tam · 1 Kadın Yarım (PHP testiyle aynı)', /27 Kadın Tam → 18:00 · 12 Erkek Tam → 18:00 · 1 Kadın Yarım → 13:30/.test(f.on), f.on);
        // Aşım: 4. satır → toplam 67'yi aşar
        await page.click('#tpFmEkle'); await yaz(3, 23, '1');
        f = await fm();
        ok('toplam havuzu aşınca net hata (önizleme metni)', /havuzunu \(67 kişi\) aşıyor/.test(f.on), f.on);
        await page.evaluate(() => { const s = document.querySelectorAll('#tpFmSatirlar [data-fm-satir]'); s[3].querySelector('[data-fm-sil]').click(); });
        f = await fm();
        ok('satır silinir (4 → 3), hata kalkar', f.satir === 3 && /Toplam FM: 45/.test(f.on), JSON.stringify(f));
        ok('FM alanında yatay taşma yok', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.fill('#tpReason', 'Test');
        await page.click('#tpOnizleBtn'); await page.waitForTimeout(300);
        const son = istekler[istekler.length - 1] || {};
        ok('önizleme isteği: hizli.fm DİZİ, 3 satır; coklu_grup/audit_ek istemciden GİTMEZ', Array.isArray((son.hizli || {}).fm) && son.hizli.fm.length === 3 && !('coklu_grup' in son) && !('audit_ek' in son), JSON.stringify(son).slice(0, 300));
        if (SHOT) await page.screenshot({ path: path.join(SHOT, `v504_toplu_${ekran.k}.png`), fullPage: false });
        ok('JS/konsol hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close(); sunucu.close();
    console.log(hata ? `\n${hata} kontrol BAŞARISIZ.` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})();
