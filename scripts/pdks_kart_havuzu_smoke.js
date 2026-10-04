// =========================================================
// scripts/pdks_kart_havuzu_smoke.js — v303 Kart Havuzu (isci_kartlari.php)
// GERÇEK TARAYICI testi (masaüstü + tablet + mobil, açık + koyu tema).
//
// Doğrulananlar: sayaçlar (Toplam · Tanımlı · Tanımsız · Arşiv · Kayıp) fixture
// ile uyumlu · "kime ne tanımlandı" özeti (çavuş satırı, tip çipleri, pasif çavuş
// rozeti) ve bağlantıları · çip/çavuş tıklayınca liste süzülür ve süzgeç çubuğu
// seçili gelir · Çavuş seçmek Tanım'ı "tanımlı"ya çeker, Tanım'ı değiştirmek çavuş+
// tipi temizler · varsayılan liste ARŞİVİ ve kartsız sanal kartı göstermez ·
// tanımlı satırda seçim kutusu YOK ve "Boşta" yazmaz · seçim çubuğu (sayaç, tümünü
// seç, temizle) · silme penceresi (kart no listesi, kart_ids[]/istek_id/csrf/reason
// POST alanları, gerekçe zorunlu, çift tıklama tek POST, süzgeç action URL'sinde) ·
// pencere gövdesi kayar, alt çubuk görünür ve tıklanır · yatay taşma yok ·
// yönetici değilken seçim/silme hiç çizilmez · mevcut Düzenle/Tanım pencereleri açılır.
//
// Sayfa her istekte GERÇEK sunucu kodundan basılır (scripts/pdks_kart_havuzu_render.php,
// bellek içi SQLite); POST'lar yakalanır, sunucuya gitmez.
//
//   node scripts/pdks_kart_havuzu_smoke.js
//
// Playwright yoksa kendini ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');

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
const RENDER = path.join(ROOT, 'scripts', 'pdks_kart_havuzu_render.php');
const SHOT = process.env.KH_SHOT_DIR || '';   // doluysa ekran görüntüleri buraya

function sayfaBas(qs, nonadmin) {
    const env = Object.assign({}, process.env, { KH_QS: qs || '' });
    if (nonadmin) env.KH_NONADMIN = '1'; else delete env.KH_NONADMIN;
    return execFileSync('php', [RENDER], { env, cwd: ROOT, maxBuffer: 32 * 1024 * 1024 }).toString('utf8');
}
const FIXTURE = JSON.parse(sayfaBas('').match(/id="__fixture">([\s\S]*?)<\/script>/)[1].replace(/<\\\//g, '</'));
const BEK = FIXTURE.beklenen;

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(86)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const posts = [];
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/isci_kartlari.php' || u.pathname === '/') {
        if (req.method === 'POST') {
            let govde = '';
            req.on('data', c => { govde += c; });
            req.on('end', () => {
                posts.push({ url: req.url, govde });
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<!doctype html><title>post</title><p>alındı</p>');
            });
            return;
        }
        const nonadmin = u.searchParams.get('__na') === '1';
        u.searchParams.delete('__na');
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(sayfaBas(u.searchParams.toString(), nonadmin));
        return;
    }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'application/octet-stream' });
    fs.createReadStream(dosya).pipe(res);
});

const A = String(FIXTURE.cavus.A), B = String(FIXTURE.cavus.B), P = String(FIXTURE.cavus.P);
const KADIN = String(FIXTURE.tip.KADIN), ERKEK = String(FIXTURE.tip.ERKEK);

// Görünen satırlar (masaüstü tablo ya da mobil kart — hangisi görünürse) → kart no listesi
const kartNolari = page => page.evaluate(() => {
    const gor = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    const tablo = document.querySelector('.kh-tablo');
    if (tablo && gor(tablo)) return [...tablo.querySelectorAll('tbody tr')].map(tr => tr.querySelector('td.pdks-uid').textContent.trim());
    return [...document.querySelectorAll('.pdks-cards .pdks-card-item')].map(c => c.querySelector('.pdks-card-meta .pdks-uid').textContent.trim());
});
const tasma = page => page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
const sayi = (page, sel) => page.$eval(sel + ' strong', e => parseInt(e.textContent, 10));
async function git(page, KOK, qs, na) {
    await page.goto(KOK + 'isci_kartlari.php' + (qs || na ? '?' + [qs, na ? '__na=1' : ''].filter(Boolean).join('&') : ''));
    await page.waitForTimeout(150);
}
async function gorunurSecKutulari(page) {
    return page.$$eval('input.kh-sec', els => els.filter(e => !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length)).length);
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', k: 'masaustu', width: 1280, height: 900 },
                         { ad: 'TABLET',   k: 'tablet',   width: 820,  height: 1024 },
                         { ad: 'MOBİL',    k: 'mobil',    width: 390,  height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const ctx = await browser.newContext({ viewport: { width: ekran.width, height: ekran.height } });
        const page = await ctx.newPage();
        const mobilGorunum = ekran.width < 1024;   // <1024 → .pc-only gizli, kartlar görünür

        // ── A. Varsayılan sayfa ──
        await git(page, KOK, '');
        const toplam = await sayi(page, '#khSayacToplam'), tanimli = await sayi(page, '#khSayacTanimli'), tanimsiz = await sayi(page, '#khSayacTanimsiz');
        const arsiv = await sayi(page, '#khSayacArsiv'), kayip = await sayi(page, '#khSayacKayip');
        ok(`sayaçlar: Tanımlı ${BEK.tanimli} · Tanımsız ${BEK.tanimsiz} · Toplam = ikisinin toplamı`,
            tanimli === BEK.tanimli && tanimsiz === BEK.tanimsiz && toplam === BEK.tanimli + BEK.tanimsiz, JSON.stringify({ toplam, tanimli, tanimsiz }));
        ok(`küçük sayaçlar: Arşiv ${BEK.arsiv} · Kayıp ${BEK.kayip}`, arsiv === BEK.arsiv && kayip === BEK.kayip, JSON.stringify({ arsiv, kayip }));
        const hrefler = await page.$$eval('.kh-sayac a', as => as.map(a => a.getAttribute('href')));
        ok('sayaç bağlantıları: Toplam=süzgeçsiz · tanim=tanimli · tanim=tanimsiz · durum=disabled · durum=lost',
            hrefler[0] === 'isci_kartlari.php' && hrefler[1] === 'isci_kartlari.php?tanim=tanimli' && hrefler[2] === 'isci_kartlari.php?tanim=tanimsiz'
            && /durum=disabled/.test(hrefler[3]) && /durum=lost/.test(hrefler[4]), JSON.stringify(hrefler));
        ok('Toplam sayaç kartı aktif işaretli (süzgeç yok)', await page.$eval('#khSayacToplam', e => e.classList.contains('kh-aktif')));

        // Özet
        const satirlar = await page.$$eval('#khOzet .kh-cavus', els => els.map(e => ({
            id: e.dataset.cavus, ad: e.querySelector('.kh-cavus-ad').textContent.trim(), toplam: parseInt(e.querySelector('.kh-toplam').textContent, 10),
            pasif: /çavuş pasif/.test(e.textContent), depo: (e.querySelector('.kh-depo') || {}).textContent || '',
            cipler: [...e.querySelectorAll('.kh-cip')].map(c => ({ t: c.firstChild.textContent.trim(), n: parseInt(c.querySelector('b').textContent, 10), href: c.getAttribute('href') })),
            adHref: e.querySelector('.kh-cavus-ad').getAttribute('href') })));
        const sA = satirlar.find(x => x.id === A), sB = satirlar.find(x => x.id === B), sP = satirlar.find(x => x.id === P);
        ok('özet: 3 çavuş satırı (A · B · Pasif)', satirlar.length === 3 && sA && sB && sP, JSON.stringify(satirlar));
        ok('özet A: toplam 6 · Kadın 4 + Erkek 2 · depo yazılı', sA && sA.toplam === 6 && sA.cipler.length === 2
            && sA.cipler.some(c => c.t === 'Kadın' && c.n === 4) && sA.cipler.some(c => c.t === 'Erkek' && c.n === 2) && /Depo A/.test(sA.depo), JSON.stringify(sA));
        ok('özet: pasif çavuşa uyarı rozeti, diğerlerine YOK', sP.pasif && !sA.pasif && !sB.pasif);
        ok('özet bağlantıları: çavuş → tanim=tanimli&cavus=ID · çip → …&ttip=TIPID',
            sA.adHref === `isci_kartlari.php?tanim=tanimli&cavus=${A}` && sA.cipler.find(c => c.t === 'Kadın').href === `isci_kartlari.php?tanim=tanimli&cavus=${A}&ttip=${KADIN}`,
            JSON.stringify([sA.adHref, sA.cipler]));

        // Liste
        const kartlar = await kartNolari(page);
        ok(`varsayılan liste ${BEK.tanimli + BEK.tanimsiz} kart; ARŞİV (X01) ve kartsız sanal kart (S01) YOK`,
            kartlar.length === BEK.tanimli + BEK.tanimsiz && !kartlar.includes('X01') && !kartlar.includes('S01'), kartlar.length + ' / ' + kartlar.slice(0, 5));
        ok('"Tip (eski)" sütunu kalktı', !/Tip \(eski/.test(await page.$eval('.kh-tablo thead', e => e.textContent)));
        const durumlar = await page.evaluate(() => {
            const gor = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            const t = document.querySelector('.kh-tablo');
            const o = {};
            if (t && gor(t)) { t.querySelectorAll('tbody tr').forEach(tr => { o[tr.querySelector('td.pdks-uid').textContent.trim()] = { t: tr.hasAttribute('data-tanimli'), txt: tr.textContent.replace(/\s+/g, ' ') }; }); }
            else { document.querySelectorAll('.pdks-cards .pdks-card-item').forEach(c => { o[c.querySelector('.pdks-uid').textContent.trim()] = { t: c.hasAttribute('data-tanimli'), txt: c.textContent.replace(/\s+/g, ' ') }; }); }
            return o;
        });
        ok('tanımlı kart (T01): "🏷 Tanımlı" yazar, "Boşta" YAZMAZ', /🏷 Tanımlı/.test(durumlar.T01.txt) && !/Boşta/.test(durumlar.T01.txt), durumlar.T01.txt);
        ok('tanımsız kart (U01): "Boşta (kullanılabilir)" yazar', /Boşta \(kullanılabilir\)/.test(durumlar.U01.txt), durumlar.U01.txt);
        ok('tanımlı + kayıp (T08) "Kayıp" yazar; tanımsız kayıp (U05) "Kayıp"', /Kayıp/.test(durumlar.T08.txt) && /Kayıp/.test(durumlar.U05.txt));
        ok('TANIMLI satırlarda seçim kutusu YOK (tablo + kart)', (await page.locator('tr[data-tanimli] .kh-sec, .pdks-card-item[data-tanimli] .kh-sec').count()) === 0);
        ok(`tanımsız satırlarda seçim kutusu VAR (${BEK.tanimsiz})`, (await gorunurSecKutulari(page)) === BEK.tanimsiz);
        ok('yatay taşma yok (varsayılan sayfa)', !(await tasma(page)));
        if (ekran.width < 768) {
            const fs16 = await page.$$eval('#khFiltre input, #khFiltre select', els => els.map(e => parseFloat(getComputedStyle(e).fontSize)));
            ok('mobil: süzgeç alanları ≥ 16px (iOS yakınlaşma yok)', fs16.every(x => x >= 16), JSON.stringify(fs16));
        }
        if (SHOT) await page.screenshot({ path: path.join(SHOT, `kart_havuzu_${ekran.k}_acik.png`), fullPage: true });

        // ── B. Özetten süzme ──
        await page.click(`#khOzet .kh-cavus[data-cavus="${A}"] .kh-cip >> text=Kadın`);
        await page.waitForLoadState();
        const u1 = new URL(page.url());
        ok('Kadın çipine tık → ?tanim=tanimli&cavus=A&ttip=KADIN', u1.searchParams.get('tanim') === 'tanimli' && u1.searchParams.get('cavus') === A && u1.searchParams.get('ttip') === KADIN, page.url());
        const f1 = await page.evaluate(() => ({ t: khFTanim.value, c: khFCavus.value, p: khFTtip.value, not: (document.getElementById('khFiltreNot') || {}).textContent || '' }));
        ok('süzgeç çubuğu güncel: Tanım=tanımlı · Çavuş=A · Tip=Kadın + açıklama satırı', f1.t === 'tanimli' && f1.c === A && f1.p === KADIN && /Çavuş A/.test(f1.not) && /Kadın/.test(f1.not), JSON.stringify(f1));
        const k1 = (await kartNolari(page)).sort();
        ok('liste yalnız A / Kadın kartları (T01 T02 T03 T08)', JSON.stringify(k1) === JSON.stringify(['T01', 'T02', 'T03', 'T08']), JSON.stringify(k1));
        ok('süzgeçli sayfada seçim çubuğu YOK (hepsi tanımlı)', (await page.locator('#khCubuk').count()) === 0);
        ok('sayaçlar süzgeçten bağımsız (tüm havuz)', (await sayi(page, '#khSayacTanimli')) === BEK.tanimli);
        ok('aktif çip işaretli', await page.$eval(`#khOzet .kh-cavus[data-cavus="${A}"] .kh-cip.kh-aktif`, e => /Kadın/.test(e.textContent)));
        if (SHOT && ekran.k === 'masaustu') await page.screenshot({ path: path.join(SHOT, 'kart_havuzu_masaustu_cip_filtreli.png'), fullPage: true });

        await git(page, KOK, '');
        await page.click(`#khOzet .kh-cavus-ad[href*="cavus=${A}"]`);
        await page.waitForLoadState();
        const k2 = await kartNolari(page);
        ok('çavuş adına tık → A\'nın 6 kartı (Kadın+Erkek)', k2.length === 6 && k2.every(n => /^T0[1-5]$|^T08$/.test(n)), JSON.stringify(k2));

        // Süzgeç etkileşimi: Çavuş seçmek Tanım'ı "tanımlı"ya çeker
        await git(page, KOK, '');
        await page.selectOption('#khFCavus', B);
        await page.waitForURL(/cavus=/);
        const u2 = new URL(page.url());
        ok('Çavuş seçimi → tanim=tanimli&cavus=B otomatik gönderilir', u2.searchParams.get('tanim') === 'tanimli' && u2.searchParams.get('cavus') === B, page.url());
        ok('liste yalnız B kartı (T06)', JSON.stringify(await kartNolari(page)) === JSON.stringify(['T06']));
        await page.selectOption('#khFTanim', 'tanimsiz');
        await page.waitForURL(/tanim=tanimsiz/);
        const u3 = new URL(page.url());
        ok('Tanım → Tanımsız: çavuş + tip temizlenir (tanim=tanimsiz, cavus yok)', u3.searchParams.get('tanim') === 'tanimsiz' && !u3.searchParams.get('cavus') && !u3.searchParams.get('ttip'), page.url());
        const k3 = await kartNolari(page);
        ok(`tanımsız liste ${BEK.tanimsiz} kart, hiç T* yok`, k3.length === BEK.tanimsiz && !k3.some(n => /^T/.test(n)), k3.length + '');

        // Durum = arşiv
        await page.selectOption('select[name="durum"]', 'disabled');
        await page.waitForURL(/durum=disabled/);
        const k4 = await kartNolari(page);
        ok('Durum = Devre dışı / arşiv → yalnız X01 görünür', JSON.stringify(k4) === JSON.stringify(['X01']), JSON.stringify(k4));

        // ── C. Seçerek silme ──
        await git(page, KOK, 'tanim=tanimsiz');
        ok('seçim çubuğu var, "0 seçili", Sil + Temizle pasif', (await page.textContent('#khSeciliSayi')) === '0' && await page.isDisabled('#khSilBtn') && await page.isDisabled('#khTemizleBtn'));
        const kutular = page.locator(mobilGorunum ? '.pdks-cards input.kh-sec' : '.kh-tablo input.kh-sec');
        await kutular.nth(0).check(); await kutular.nth(1).check();
        ok('2 kutu işaretlenince "2 seçili" ve Sil açık', (await page.textContent('#khSeciliSayi')) === '2' && !(await page.isDisabled('#khSilBtn')));
        const secNo = await page.$$eval(mobilGorunum ? '.pdks-cards input.kh-sec:checked' : '.kh-tablo input.kh-sec:checked', els => els.map(e => e.dataset.kartNo));
        // Tümünü seç
        await page.locator(mobilGorunum ? '.td-cubuk-tumu input.kh-tumu' : 'th.kh-sec-th input.kh-tumu').check();
        ok(`"Tümünü seç" → ${BEK.tanimsiz} seçili`, (await page.textContent('#khSeciliSayi')) === String(BEK.tanimsiz));
        await page.click('#khTemizleBtn');
        ok('"Seçimi temizle" → 0 seçili, Sil pasif', (await page.textContent('#khSeciliSayi')) === '0' && await page.isDisabled('#khSilBtn'));
        await kutular.nth(0).check(); await kutular.nth(1).check();

        // Pencere (2 kart)
        await page.click('#khSilBtn');
        await page.waitForTimeout(400);
        const liste2 = await page.$$eval('#khSilListe .pdks-uid', els => els.map(e => e.textContent));
        ok('pencere açıldı; seçilen 2 kartın no listesi + gerekçe alanı + açıklama metni',
            !(await page.$eval('#iskSilModal', e => e.hidden)) && JSON.stringify(liste2) === JSON.stringify(secNo)
            && /kalıcı silinir/.test(await page.textContent('#iskSilModal')) && /arşivlenir/.test(await page.textContent('#iskSilModal')), JSON.stringify([liste2, secNo]));
        const alanlar = await page.evaluate(() => {
            const f = document.getElementById('khSilForm'); const d = new FormData(f);
            return { action: d.get('action'), csrf: d.get('csrf'), istek: d.get('istek_id'), ids: d.getAll('kart_ids[]'), formAction: f.getAttribute('action'), method: f.method };
        });
        ok('form alanları: action=tanimsiz_sil · csrf · istek_id (32 hex) · kart_ids[] (2)', alanlar.action === 'tanimsiz_sil' && alanlar.csrf === 'testcsrf' && /^[0-9a-f]{32}$/.test(alanlar.istek) && alanlar.ids.length === 2 && alanlar.method === 'post', JSON.stringify(alanlar));
        ok('form action URL süzgeci taşır (tanim=tanimsiz)', /tanim=tanimsiz/.test(alanlar.formAction), alanlar.formAction);
        await page.click('#khSilOnay');
        await page.waitForTimeout(150);
        ok('gerekçe BOŞKEN gönderilmez (HTML required / sunucuya POST gitmez)', posts.length === 0);
        await page.fill('#khSilNeden', 'Kayıp toplu temizlik');
        await page.dblclick('#khSilOnay');
        await page.waitForTimeout(500);
        ok('gerekçe girilince TEK POST (çift tıklama korumalı)', posts.length === 1, 'POST sayısı ' + posts.length);
        if (posts.length) {
            const g = new URLSearchParams(posts[0].govde);
            ok('POST gövdesi: action · reason · istek_id · kart_ids[] aynı 2 id · csrf', g.get('action') === 'tanimsiz_sil' && g.get('reason') === 'Kayıp toplu temizlik'
                && /^[0-9a-f]{32}$/.test(g.get('istek_id')) && g.getAll('kart_ids[]').length === 2 && g.get('csrf') === 'testcsrf', posts[0].govde);
            ok('POST URL süzgeci korur', /tanim=tanimsiz/.test(posts[0].url), posts[0].url);
        }
        posts.length = 0;

        // Pencere: tüm tanımsız kartlar seçili (uzun liste) → ölçüm
        await git(page, KOK, 'tanim=tanimsiz');
        await page.locator(mobilGorunum ? '.td-cubuk-tumu input.kh-tumu' : 'th.kh-sec-th input.kh-tumu').check();
        await page.click('#khSilBtn');
        await page.waitForTimeout(400);
        const m = await page.evaluate(() => {
            const ovl = document.getElementById('iskSilModal'), dlg = ovl.querySelector('.pm-dialog'), r = dlg.getBoundingClientRect();
            const govde = ovl.querySelector('.kh-sil-govde'), onay = document.getElementById('khSilOnay').getBoundingClientRect();
            const ust = document.elementFromPoint(onay.left + onay.width / 2, onay.top + onay.height / 2);
            return {
                acik: !ovl.hidden, vw: innerWidth, vh: innerHeight, dlg: { sol: r.left, sag: r.right, ust: r.top, alt: r.bottom },
                onayEkranda: onay.top >= 0 && onay.bottom <= innerHeight && onay.left >= 0 && onay.right <= innerWidth,
                onayTiklanir: !!ust && !!ust.closest('#khSilOnay'),
                govdeKayar: govde.scrollHeight > govde.clientHeight + 1, listeKayar: (l => l.scrollHeight > l.clientHeight + 1)(document.getElementById('khSilListe')), govdeOverflow: getComputedStyle(govde).overflowY,
                tasma: document.documentElement.scrollWidth > innerWidth, tasmaDlg: dlg.scrollWidth > dlg.clientWidth + 1,
                n: ovl.querySelectorAll('#khSilListe li').length,
                ta16: parseFloat(getComputedStyle(document.getElementById('khSilNeden')).fontSize),
            };
        });
        ok(`uzun liste (${BEK.tanimsiz} kart): pencere ekran içinde, yatay taşma yok`, m.acik && m.n === BEK.tanimsiz && m.dlg.sol >= -0.5 && m.dlg.sag <= m.vw + 0.5 && m.dlg.alt <= m.vh + 0.5 && !m.tasma && !m.tasmaDlg, JSON.stringify(m));
        ok('alt çubuk (Sil / Arşivle) ekranda ve TIKLANABİLİR; gövde kayar ya da kart listesi kendi içinde kayar', m.onayEkranda && m.onayTiklanir && (m.govdeKayar || m.listeKayar) && m.govdeOverflow === 'auto', JSON.stringify(m));
        if (ekran.width < 768) ok('mobil: gerekçe alanı ≥ 16px', m.ta16 >= 16, String(m.ta16));
        if (SHOT && ekran.k !== 'tablet') await page.screenshot({ path: path.join(SHOT, `kart_havuzu_${ekran.k}_silme_penceresi.png`) });
        await page.keyboard.press('Escape');
        await page.waitForTimeout(200);
        ok('Esc pencereyi kapatır', await page.$eval('#iskSilModal', e => e.hidden));

        // ── D. Yönetici değil ──
        await git(page, KOK, 'tanim=tanimsiz', true);
        ok('yönetici değil: seçim çubuğu / kutular / silme penceresi HİÇ çizilmez',
            (await page.locator('#khCubuk, input.kh-sec, #iskSilModal, input[value="tanimsiz_sil"]').count()) === 0);

        // ── E. Mevcut pencereler bozulmadı ──
        await git(page, KOK, 'tanim=tanimli');
        await page.locator(mobilGorunum ? '.pdks-cards button[data-isk-tanim-kart]' : '.kh-tablo button[data-isk-tanim-kart]').first().click();
        await page.waitForTimeout(300);
        ok('🏷 Tanım penceresi açılır; mevcut tanım yazar', !(await page.$eval('#iskTanimModal', e => e.hidden)) && /Mevcut tanım/.test(await page.textContent('#iskTanimMevcut')));
        await page.click('#iskTanimModal .pm-close'); await page.waitForTimeout(200);
        await page.locator(mobilGorunum ? '.pdks-cards button:text("Düzenle")' : '.kh-tablo button:text("Düzenle")').first().click();
        await page.waitForTimeout(300);
        ok('Düzenle penceresi açılır', !(await page.$eval('#iskKartModal', e => e.hidden)));

        // ── F. Koyu tema ──
        await git(page, KOK, '');
        await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        await page.waitForTimeout(150);
        const renk = await page.evaluate(() => {
            const c = el => getComputedStyle(el);
            const kart = document.querySelector('#khSayacTanimli'), sayiEl = kart.querySelector('.kh-sayac-sayi'), cip = document.querySelector('.kh-cip');
            const lum = s => { const m = s.match(/\d+(\.\d+)?/g).map(Number); const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(m[0]) + .7152 * f(m[1]) + .0722 * f(m[2]); };
            const oran = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); };
            return { sayi: oran(c(sayiEl).color, c(kart).backgroundColor), cip: oran(c(cip).color, c(cip).backgroundColor), ad: oran(c(document.querySelector('.kh-cavus-ad')).color, c(document.body).backgroundColor) };
        });
        ok('koyu tema: sayaç sayısı / çip / çavuş adı kontrastı ≥ 4.5', renk.sayi >= 4.5 && renk.cip >= 4.5 && renk.ad >= 4.5, JSON.stringify(renk));
        ok('koyu tema: yatay taşma yok', !(await tasma(page)));
        if (SHOT) await page.screenshot({ path: path.join(SHOT, `kart_havuzu_${ekran.k}_koyu.png`), fullPage: true });
        await ctx.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata === 0 ? '\nTÜM KONTROLLER GEÇTİ' : `\n${hata} KONTROL BAŞARISIZ`);
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
