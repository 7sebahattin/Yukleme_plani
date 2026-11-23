'use strict';
// =========================================================
// scripts/puantaj_giris_cavus_smoke.js — v562 Puantaj Girişi «bağlı çavuş» ARAYÜZÜ (tarayıcı)
//   node scripts/puantaj_giris_cavus_smoke.js     (php + Playwright gerekir)
// Sayfalar GERÇEK PHP ile (scripts/_puantaj_cizelgesi_harness.php, bellek içi SQLite) basılır; yazma ucu (POST) taklit edilir
// (sunucu kuralları scripts/puantaj_giris_cavus_smoke.php'de). Ölçülen: modal alanı, düzenle ön-doldurma, istek yükü,
// Kişiler listesi sütunu + toplu bağlama, çavuş süzgeci, kolon yokken hiçbir çavuş arayüzü YOK, mobil taşma/görünürlük.
// =========================================================
const fs = require('fs'), os = require('os'), path = require('path'), http = require('http');
const { spawnSync } = require('child_process');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* sıradaki */ }
}
if (!chromium) { console.log('Playwright yok: ATLANDI'); process.exit(0); }
const root = path.dirname(__dirname);
const harness = path.join(root, 'scripts/_puantaj_cizelgesi_harness.php');
const ay = new Date().toISOString().slice(0, 7);
const KISILER = [[1, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1, 0, 1], [2, 'Örnek Kişi B', '', 'Depo A', 1, 0, 2], [3, 'Örnek Kişi C', '', 'Depo A', 1, 0, 0]];
const FOREMEN = [[1, 'Çavuş Ayşe', 1], [2, 'Çavuş Bekir', 1], [3, 'Çavuş Eski', 0]];
let sonuc = { ok: 0, hata: 0 };
const ok = (ad, c, ek) => { if (c) { sonuc.ok++; console.log(ad.padEnd(100) + ' OK'); } else { sonuc.hata++; console.log(ad.padEnd(100) + ' *** HATA ' + (ek || '')); } };
let kolonYok = false;
const posts = [];

function sayfa(get) {
    const opt = { admin: true, ay, depo: 'Depo A', allowed: ['Depo A'], giris: true, http: true, giris_kisiler: KISILER, foremen: FOREMEN };
    const r = spawnSync('php', [harness, 'puantaj_cizelgesi.php', JSON.stringify(get), JSON.stringify(opt)], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    let h = r.stdout || '';
    if (kolonYok) h = h.replace(/\x00/g, '');
    return h;
}
const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname.startsWith('/assets/')) {
        const f = path.join(root, 'assets', path.basename(u.pathname));
        if (!fs.existsSync(f)) { res.statusCode = 404; return res.end('yok'); }
        res.setHeader('Content-Type', { '.css': 'text/css', '.js': 'application/javascript' }[path.extname(f)] || 'application/octet-stream');
        return res.end(fs.readFileSync(f));
    }
    if (u.pathname === '/puantaj_giris_kaydet.php' && req.method === 'POST') {
        const parcalar = [];
        req.on('data', d => parcalar.push(d));
        req.on('end', () => {
            const govde = Buffer.concat(parcalar).toString('utf8');
            let o = {}; try { o = JSON.parse(govde); } catch (_) { o = Object.fromEntries(new URLSearchParams(govde)); }
            posts.push(o);
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ ok: true, islem: 'update', etkilenen: 1, atlanan: 0 }));
        });
        return;
    }
    if (u.pathname === '/puantaj_cizelgesi.php') {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        return res.end(sayfa(Object.fromEntries(u.searchParams)));
    }
    res.statusCode = 404; res.end('yok');
});

(async () => {
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${srv.address().port}/puantaj_cizelgesi.php`;
    const b = await chromium.launch();
    for (const [ad, w, h] of [['MASAÜSTÜ', 1280, 900], ['MOBİL', 390, 844]]) {
        console.log(`\n=== ${ad} (${w}×${h}) ===`);
        const ctx = await b.newContext({ viewport: { width: w, height: h } });
        const p = await ctx.newPage();
        const jsHata = [];
        p.on('pageerror', e => jsHata.push(String(e)));
        p.on('console', m => { if (m.type() === 'error') jsHata.push(m.text()); });

        // ── Puantaj çizelgesi: satırda çavuş etiketi, modal alanı, düzenle ön-doldurma + yük ──
        await p.goto(KOK + '?sekme=giris&ay=' + ay); await p.waitForTimeout(500);
        const et = await p.evaluate(() => [...document.querySelectorAll('tr[data-pg-satir]')].map(tr => ({ id: tr.getAttribute('data-pg-satir'), c: (tr.querySelector('small.pg-cavus') || {}).textContent || '' })));
        ok('Puantaj satırlarında «↳ Çavuş» etiketi (A→Ayşe, B→Bekir, C yok)', et.length === 3 && et[0].c.includes('Çavuş Ayşe') && et[1].c.includes('Çavuş Bekir') && et[2].c === '', JSON.stringify(et));
        const sf = await p.evaluate(() => { const s = document.querySelector('select[name=cavus]'); return s ? [...s.options].map(o => o.textContent.trim()) : null; });
        ok('Üstte çavuş süzgeci: Tüm çavuşlar · Çavuşsuz · Ayşe · Bekir · Eski (pasif)', sf && sf[0] === 'Tüm çavuşlar' && sf[1] === 'Çavuşsuz' && sf.includes('Çavuş Eski (pasif)') && sf.length === 5, JSON.stringify(sf));
        // Kişi ekle → alan görünür, seçenekler
        await p.evaluate(() => document.querySelector('.pg-ekle').click()); await p.waitForTimeout(450);
        const m1 = await p.evaluate(() => { const s = document.getElementById('pgCavus'); const r = s.getBoundingClientRect(); const pen = document.getElementById('pgPen').getBoundingClientRect();
            return { var: !!s, gor: r.width > 100 && r.height >= 40 && r.left >= 0 && r.right <= innerWidth + .5 && r.bottom <= innerHeight + .5, font: parseFloat(getComputedStyle(s).fontSize), sec: [...s.options].map(o => o.textContent.trim() + (o.disabled ? '|d' : '')), deger: s.value, penIc: r.top >= pen.top && r.bottom <= pen.bottom + 1 }; });
        ok('Modal: «Bağlı çavuş» seçim kutusu var, görünür, ekran içinde' + (w < 768 ? ', 16px+ (iOS zoom)' : ''), m1.var && m1.gor && (w >= 768 || m1.font >= 16), JSON.stringify(m1));
        ok('Seçenekler: «— Çavuş seçilmedi —» + Ayşe + Bekir + Eski (pasif, DEVRE DIŞI); varsayılan boş', m1.sec.join('/') === '— Çavuş seçilmedi —/Çavuş Ayşe/Çavuş Bekir/Çavuş Eski (pasif)|d' && m1.deger === '', m1.sec.join('/'));
        if (process.env.PCS_SHOT) await p.screenshot({ path: path.join(process.env.PCS_SHOT, `modal_${w}.png`) });
        await p.fill('#pgAd', 'Örnek Kişi N'); await p.selectOption('#pgCavus', { label: 'Çavuş Bekir' });
        posts.length = 0;
        await p.click('#pgPenTamam'); await p.waitForTimeout(500);
        ok('Kaydet isteği: action=kisi_ekle + cavus_id=2', posts.length >= 1 && posts[0].action === 'kisi_ekle' && String(posts[0].cavus_id) === '2' && posts[0].ad === 'Örnek Kişi N', JSON.stringify(posts[0]));
        await p.goto(KOK + '?sekme=giris&ay=' + ay); await p.waitForTimeout(400);
        // Düzenle: ön-doldurma (A → Ayşe)
        await p.evaluate(() => document.querySelector('button.pg-uc[data-pg-kisi="1"]').click()); await p.waitForTimeout(250);
        await p.evaluate(() => document.querySelector('[data-pg-islem="duzenle"]').click()); await p.waitForTimeout(450);
        const d1 = await p.evaluate(() => ({ ad: document.getElementById('pgAd').value, gorev: document.getElementById('pgGorev').value, cavus: document.getElementById('pgCavus').value }));
        ok('Düzenle: görev ve çavuş (Ayşe=1) ÖN-DOLU', d1.ad === 'Örnek Kişi A' && d1.gorev === 'Paketçi' && d1.cavus === '1', JSON.stringify(d1));
        await p.selectOption('#pgCavus', ''); posts.length = 0;
        await p.click('#pgPenTamam'); await p.waitForTimeout(500);
        ok('Düzenle → «çavuş seçilmedi»: kisi_duzenle + cavus_id="" (bağ kaldırma)', posts.length >= 1 && posts[0].action === 'kisi_duzenle' && String(posts[0].cavus_id) === '' && String(posts[0].gorev) === 'Paketçi', JSON.stringify(posts[0]));
        // C (çavuşsuz) düzenle → boş
        await p.goto(KOK + '?sekme=giris&ay=' + ay); await p.waitForTimeout(400);
        await p.evaluate(() => document.querySelector('button.pg-uc[data-pg-kisi="3"]').click()); await p.waitForTimeout(250);
        await p.evaluate(() => document.querySelector('[data-pg-islem="duzenle"]').click()); await p.waitForTimeout(450);
        ok('Çavuşsuz kişide düzenle: seçim boş', (await p.evaluate(() => document.getElementById('pgCavus').value)) === '');

        if (process.env.PCS_SHOT) { await p.goto(KOK + '?sekme=giris&ay=' + ay); await p.waitForTimeout(400); await p.screenshot({ path: path.join(process.env.PCS_SHOT, `cizelge_${w}.png`) }); }
        // ── Çavuş süzgeci ──
        await p.goto(KOK + '?sekme=giris&ay=' + ay + '&cavus=2'); await p.waitForTimeout(400);
        const sz = await p.evaluate(() => [...document.querySelectorAll('tr[data-pg-satir] .pc-isim-yazi')].map(x => x.textContent.trim()));
        ok('Süzgeç ?cavus=2 → yalnız Çavuş Bekir\'in kişisi', sz.join() === 'Örnek Kişi B', sz.join());
        await p.goto(KOK + '?sekme=giris&ay=' + ay + '&cavus=yok'); await p.waitForTimeout(400);
        ok('Süzgeç ?cavus=yok → çavuşsuzlar (C)', (await p.evaluate(() => [...document.querySelectorAll('tr[data-pg-satir] .pc-isim-yazi')].map(x => x.textContent.trim()).join())) === 'Örnek Kişi C');

        // ── Kişiler görünümü ──
        await p.goto(KOK + '?sekme=giris&gorunum=kisiler'); await p.waitForTimeout(500);
        const k1 = await p.evaluate(() => ({ bas: [...document.querySelectorAll('#pkTablo thead th')].map(t => t.textContent.trim()), c: [...document.querySelectorAll('#pkTablo tbody tr')].map(tr => ((tr.querySelector('td.pk-cavus') || {}).textContent || '').replace(/\s+/g, ' ').trim()), tasma: document.documentElement.scrollWidth > innerWidth }));
        ok('Kişiler: «Bağlı çavuş» sütunu; satırlarda Ayşe / Bekir / —', k1.bas.includes('Bağlı çavuş') && k1.c[0].includes('Çavuş Ayşe') && k1.c[1].includes('Çavuş Bekir') && k1.c[2] === '—', JSON.stringify(k1));
        ok('Kişiler: sayfada yatay taşma yok', !k1.tasma);
        // toplu bağlama: A ve C seç → çubukta çavuş kutusu
        await p.evaluate(() => { document.querySelector('input[data-pk-sec="1"]').click(); document.querySelector('input[data-pk-sec="3"]').click(); }); await p.waitForTimeout(250);
        const t1 = await p.evaluate(() => { const c = document.getElementById('pkCubuk'); const s = document.getElementById('pkCavusSec'); const r = s.getBoundingClientRect(); const bt = document.querySelector('[data-pk-cavus-uygula]').getBoundingClientRect();
            return { acik: !c.hidden, sel: [...s.options].map(o => o.textContent.trim()), gor: r.width > 80 && r.right <= innerWidth + .5 && bt.right <= innerWidth + .5 && bt.bottom <= innerHeight + .5, font: parseFloat(getComputedStyle(s).fontSize) }; });
        ok('Toplu çubuk açık: çavuş seçimi (— Bağı kaldır — + çavuşlar) + «Çavuşa bağla» ekran içinde' + (w < 768 ? ', 16px+' : ''), t1.acik && t1.sel[0].startsWith('— Bağı kaldır') && t1.gor && (w >= 768 || t1.font >= 16), JSON.stringify(t1));
        if (process.env.PCS_SHOT) await p.screenshot({ path: path.join(process.env.PCS_SHOT, `kisiler_${w}.png`) });
        await p.selectOption('#pkCavusSec', { label: 'Çavuş Ayşe' }); posts.length = 0;
        await p.click('[data-pk-cavus-uygula]'); await p.waitForTimeout(500);
        ok('«Çavuşa bağla» isteği: action=kisi_cavus, kisi_ids=[1,3], cavus_id=1', posts.length >= 1 && posts[0].action === 'kisi_cavus' && JSON.stringify((posts[0].kisi_ids || []).map(Number)) === '[1,3]' && String(posts[0].cavus_id) === '1', JSON.stringify(posts[0]));
        // Kişiler düzenle düğmesi çavuşu taşır
        await p.goto(KOK + '?sekme=giris&gorunum=kisiler'); await p.waitForTimeout(400);
        await p.evaluate(() => document.querySelector('tr[data-pk-satir="2"] [data-pk-islem="duzenle"]').click()); await p.waitForTimeout(450);
        ok('Kişiler › Düzenle: çavuş ön-dolu (Bekir=2)', (await p.evaluate(() => document.getElementById('pgCavus').value)) === '2');
        // Excel penceresi: Çavuş sütunu başlığı + not
        await p.goto(KOK + '?sekme=giris&gorunum=kisiler'); await p.waitForTimeout(400);
        await p.evaluate(() => document.querySelector('[data-pk-excel-ac]').click()); await p.waitForTimeout(350);
        const x1 = await p.evaluate(() => ({ th: [...document.querySelectorAll('.pk-excel-tablo thead th')].map(t => t.textContent.trim()), not: document.querySelector('#pkExcelAdim1 .pk-excel-not').textContent }));
        ok('Excel penceresi: önizleme tablosunda «Çavuş» sütunu; yönerge çavuşu anlatır', x1.th.join() === 'Sıra,Ad Soyad,Görev,Çavuş,Durum' && /Çavuş/.test(x1.not), JSON.stringify(x1));
        ok('JS/konsol hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await ctx.close();
    }

    // ── Kolon kurulu değilken (eski şema) hiçbir çavuş arayüzü yok ──
    console.log('\n=== KOLON YOK (migrate edilmemiş) ===');
    const eski = spawnSync('php', [harness, 'puantaj_cizelgesi.php', JSON.stringify({ sekme: 'giris', ay }), JSON.stringify({ admin: true, ay, depo: 'Depo A', allowed: ['Depo A'], giris: true, http: true, giris_kisiler: [[1, 'Örnek Kişi A', 'Paketçi', 'Depo A', 1]], eski_sema: true })], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
    ok('eski şema: sayfa çıktı verdi, Fatal/Warning yok', eski.status === 0 && !/Fatal error|Warning:|Notice:|Deprecated:/.test(eski.stdout + eski.stderr), (eski.stdout || '').slice(0, 200) + eski.stderr);
    ok('eski şema: çavuş süzgeci / modal alanı / etiket HİÇ yok', !/name="cavus"/.test(eski.stdout) && !/id="pgCavus"/.test(eski.stdout) && !/pg-cavus"/.test(eski.stdout) && /Örnek Kişi A/.test(eski.stdout));
    await b.close(); srv.close();
    console.log(`\n${sonuc.hata === 0 ? 'Tüm kontroller geçti.' : sonuc.hata + ' HATA'}  (geçen ${sonuc.ok})`);
    process.exit(sonuc.hata === 0 ? 0 : 1);
})();
