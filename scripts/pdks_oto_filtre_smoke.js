// =========================================================
// scripts/pdks_oto_filtre_smoke.js — v296 Personel Takibi ortak liste
// davranışı (config/pdks_liste_ui.php) GERÇEK TARAYICI testi:
//   A) Otomatik filtre (form[data-oto-filtre]): Filtrele/Göster düğmesi
//      görünmez, select/month değişince yeni parametreyle gidilir, değer
//      değişmeden gelen change gönderMEZ, arama kutusu 400 ms sonra / Enter'da.
//   B) Tıklanabilir satır (.pdks-satir-link[data-href]): satır tıkı detaya
//      gider, Ctrl-tık mevcut sayfayı DEĞİŞTİRMEZ (yeni sekme), odaktaki
//      satırda Enter açar, satırdaki <a> kendi işini yapar.
//   C) 390 px'te yatay taşma yok, JS hatası yok.
//
//   PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html
//   PUANTAJ_SAYFA=toplu php scripts/pdks_puantaj_dialog_render.php > _test_toplu_dokum.html
//   node scripts/pdks_oto_filtre_smoke.js
// Playwright yoksa kendini ATLAR. OTO_FILTRE_SHOT verilirse 1280 px liste
// ekran görüntüsünü o yola kaydeder.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const LISTE = path.join(ROOT, '_test_puantaj_liste.html');
const TOPLU = path.join(ROOT, '_test_toplu_dokum.html');
for (const [f, k] of [[LISTE, 'PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun php scripts/pdks_puantaj_dialog_render.php > _test_puantaj_liste.html'],
                      [TOPLU, 'PUANTAJ_SAYFA=toplu php scripts/pdks_puantaj_dialog_render.php > _test_toplu_dokum.html']]) {
    if (!fs.existsSync(f)) { console.error(path.basename(f) + ' yok. Önce: ' + k); process.exit(1); }
}
const SHOT = process.env.OTO_FILTRE_SHOT || '';
let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(84)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

const listeHtml = fs.readFileSync(LISTE, 'utf8');
const scriptMatch = listeHtml.match(/<script>\s*\(function \(\) \{\s*'use strict';\s*if \(window\.__pdksListeUi\)[\s\S]*?<\/script>/);
if (!scriptMatch) { console.error('Ortak script (pdks_liste_ui_js) liste çıktısında bulunamadı.'); process.exit(1); }
const ARAMA = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Arama</title></head><body>'
    + '<form method="get" class="pdks-filter-bar" data-oto-filtre><input type="search" name="q" value="">'
    + '<select name="durum"><option value="">Tümü</option><option value="aktif">Aktif</option></select>'
    + '<noscript><button type="submit" class="btn">Filtrele</button></noscript></form>'
    + '<form method="post" id="postForm"><select name="x"><option value="1">1</option><option value="2">2</option></select></form>'
    + scriptMatch[0] + '</body></html>';
const DETAY = '<!doctype html><html><head><meta charset="utf-8"><title>Detay</title></head><body><h1 id="detay">DETAY</h1></body></html>';

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const html = (s) => { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(s); };
    if (u.pathname === '/gunluk_isci_puantaj.php') return html(listeHtml);
    if (u.pathname === '/cavus_toplu_dokum.php') return html(fs.readFileSync(TOPLU, 'utf8'));
    if (u.pathname === '/arama.php') return html(ARAMA);
    if (/_detay\.php$/.test(u.pathname)) return html(DETAY);
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/plain' });
    fs.createReadStream(dosya).pipe(res);
});

(async () => {
    await new Promise((r) => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}`;
    let browser;
    try {
        browser = await chromium.launch({ executablePath: process.env.PW_CHROME || (fs.existsSync('/opt/pw-browsers/chromium-1194/chrome-linux/chrome') ? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' : undefined) });
    } catch (e) {
        console.log('Chromium başlatılamadı — tarayıcı testi ATLANDI: ' + e.message.split('\n')[0]);
        sunucu.close(); process.exit(0);
    }
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const jsHata = [];
    ctx.on('page', (p) => {
        p.on('pageerror', (e) => jsHata.push(e.message));
        p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) jsHata.push(m.text()); });
    });
    const page = await ctx.newPage();
    const yol = () => { const u = new URL(page.url()); return u.pathname + u.search; };
    const bekle = (ms) => new Promise((r) => setTimeout(r, ms));

    // ── A) Günlük Puantaj listesi ───────────────────────────
    console.log('\n=== Günlük Puantaj listesi (1280) ===');
    const LURL = KOK + '/gunluk_isci_puantaj.php?tarih=' + new Date().toISOString().slice(0, 10);
    await page.goto(LURL);
    ok('Filtrele düğmesi görünmüyor (JS açıkken <noscript>)',
        await page.locator('form[data-oto-filtre] button[type=submit], form[data-oto-filtre] input[type=submit]').count() === 0
        && !(await page.getByRole('button', { name: 'Filtrele' }).isVisible().catch(() => false)));
    ok('Excel menüsü formda ve bağlantıları güncel filtreyi taşıyor',
        /tarih=\d{4}-\d{2}-\d{2}&csv=1$/.test(await page.locator('form[data-oto-filtre] .dl-menu-list a').first().getAttribute('href') || ''));
    const anaTablo = page.locator('.pc-only table:has(tr.pdks-satir-link)');
    ok('Mesai tablosunda İşlem sütunu ve Detay düğmesi kaldırıldı',
        await anaTablo.locator('thead th:has-text("İşlem")').count() === 0
        && await anaTablo.locator('a.btn:has-text("Detay")').count() === 0
        && await anaTablo.locator('thead th').count() === await anaTablo.locator('tbody tr').first().locator('td').count());
    const satir = page.locator('.pc-only tr.pdks-satir-link').first();
    ok('Satır data-href + tabindex=0 + birincil hücrede gerçek <a>',
        (await satir.getAttribute('data-href')) === 'gunluk_isci_puantaj_detay.php?id=1'
        && (await satir.getAttribute('tabindex')) === '0'
        && (await satir.locator('td a.pdks-satir-ana').getAttribute('href')) === 'gunluk_isci_puantaj_detay.php?id=1');
    ok('Satırda imleç pointer', (await satir.locator('td').nth(2).evaluate((el) => getComputedStyle(el).cursor)) === 'pointer');
    const bgOnce = await satir.locator('td').nth(2).evaluate((el) => getComputedStyle(el).backgroundColor);
    await satir.locator('td').nth(2).hover();
    const bgSonra = await satir.locator('td').nth(2).evaluate((el) => getComputedStyle(el).backgroundColor);
    ok('Hover vurgusu (arka plan değişiyor)', bgOnce !== bgSonra, `${bgOnce} → ${bgSonra}`);
    if (SHOT) { await page.mouse.move(5, 5); await page.screenshot({ path: SHOT, fullPage: true }); console.log('    ekran görüntüsü: ' + SHOT); }

    // Değer değişmeden gelen change gönderMEZ (döngü yok)
    await page.locator('select[name=durum]').dispatchEvent('change');
    await bekle(500);
    ok('Değer değişmeden change → gönderim YOK', yol().startsWith('/gunluk_isci_puantaj.php'));

    // Select değişince yeni parametreyle gidilir
    await Promise.all([page.waitForURL(/cavus=1/, { timeout: 4000 }).catch(() => {}), page.selectOption('select[name=cavus]', '1')]);
    const u1 = new URL(page.url());
    ok('Çavuş seçilince ?cavus=1 ile gidildi, tarih korundu', u1.searchParams.get('cavus') === '1' && !!u1.searchParams.get('tarih'), page.url());
    ok('Devre dışı depo select gönderilmedi', !u1.searchParams.has('depo'));

    // Satır tıkı (link olmayan hücre) → detay
    await page.goto(LURL);
    await Promise.all([page.waitForURL(/_detay\.php/, { timeout: 4000 }).catch(() => {}), page.locator('.pc-only tr.pdks-satir-link td').nth(3).click()]);
    ok('Satır tıkı detaya gitti', yol() === '/gunluk_isci_puantaj_detay.php?id=1', yol());

    // Ctrl-tık → mevcut sayfa değişmez, yeni sekme açılır
    await page.goto(LURL);
    const yeniP = ctx.waitForEvent('page', { timeout: 4000 }).catch(() => null);
    await page.locator('.pc-only tr.pdks-satir-link td').nth(3).click({ modifiers: ['Control'] });
    const yeni = await yeniP;
    await bekle(300);
    ok('Ctrl-tık mevcut sayfayı DEĞİŞTİRMEDİ', yol().startsWith('/gunluk_isci_puantaj.php?'), yol());
    if (yeni) await yeni.waitForLoadState().catch(() => {});
    ok('Ctrl-tık detayı yeni sekmede açtı', !!yeni && /gunluk_isci_puantaj_detay\.php\?id=1$/.test(yeni.url()), yeni ? yeni.url() : 'yeni sekme yok');
    if (yeni) await yeni.close();

    // Orta tık → mevcut sayfa değişmez
    const yeniP2 = ctx.waitForEvent('page', { timeout: 4000 }).catch(() => null);
    await page.locator('.pc-only tr.pdks-satir-link td').nth(3).click({ button: 'middle' });
    const yeni2 = await yeniP2;
    await bekle(300);
    ok('Orta tık mevcut sayfayı değiştirmedi, yeni sekme açtı', yol().startsWith('/gunluk_isci_puantaj.php?') && !!yeni2);
    if (yeni2) await yeni2.close();

    // Klavye: odaktaki satırda Enter
    await page.locator('.pc-only tr.pdks-satir-link').first().focus();
    await Promise.all([page.waitForURL(/_detay\.php/, { timeout: 4000 }).catch(() => {}), page.keyboard.press('Enter')]);
    ok('Odaktaki satırda Enter detaya gitti', yol() === '/gunluk_isci_puantaj_detay.php?id=1', yol());

    // İç bağlantı kendi işini yapar
    await page.goto(LURL);
    await Promise.all([page.waitForURL(/_detay\.php/, { timeout: 4000 }).catch(() => {}), page.locator('.pc-only tr.pdks-satir-link a.pdks-satir-ana').first().click()]);
    ok('Satırdaki <a> tıkı detaya gitti', yol() === '/gunluk_isci_puantaj_detay.php?id=1', yol());

    // ── Toplu Döküm ─────────────────────────────────────────
    console.log('\n=== Çavuş Toplu Döküm (1280) ===');
    const ay = new Date().toISOString().slice(0, 7);
    const TURL = KOK + '/cavus_toplu_dokum.php?ay=' + ay;
    await page.goto(TURL);
    ok('Göster düğmesi görünmüyor', await page.locator('form[data-oto-filtre] button[type=submit]').count() === 0);
    ok('Eski satır script\'i (ctd-click-row) yok, satır ortak sınıfla', await page.locator('.ctd-click-row').count() === 0 && await page.locator('tr.pdks-satir-link').count() >= 1);
    await Promise.all([page.waitForURL(/_detay\.php/, { timeout: 4000 }).catch(() => {}), page.locator('tr.pdks-satir-link td').nth(2).click()]);
    ok('Toplu döküm satır tıkı kart dökümüne gitti', /^\/cavus_toplu_dokum_detay\.php\?session_id=1&ay=/.test(yol()), yol());
    await page.goto(TURL);
    await Promise.all([page.waitForURL(/ay=2020-01/, { timeout: 4000 }).catch(() => {}), page.fill('input[name=ay]', '2020-01')]);
    ok('Ay seçilince ?ay=2020-01 ile gidildi', new URL(page.url()).searchParams.get('ay') === '2020-01', page.url());
    await page.goto(TURL);
    await Promise.all([page.waitForURL(/cavus=1/, { timeout: 4000 }).catch(() => {}), page.selectOption('select[name=cavus]', '1')]);
    ok('Toplu döküm: çavuş seçilince ?cavus=1, ay korundu', new URL(page.url()).searchParams.get('cavus') === '1' && new URL(page.url()).searchParams.get('ay') === ay, page.url());

    // ── Arama kutusu (debounce) + POST formu dokunulmaz ─────
    console.log('\n=== Arama kutusu / POST formu ===');
    await page.goto(KOK + '/arama.php');
    await page.locator('input[name=q]').pressSequentially('ab', { delay: 60 });
    await bekle(150);
    ok('Yazarken hemen gönderilmedi (debounce)', yol() === '/arama.php', yol());
    await page.waitForURL(/q=ab/, { timeout: 3000 }).catch(() => {});
    ok('400 ms sonra ?q=ab ile gidildi', new URL(page.url()).searchParams.get('q') === 'ab', page.url());
    await page.goto(KOK + '/arama.php');
    await page.locator('input[name=q]').fill('xy');
    await Promise.all([page.waitForURL(/q=xy/, { timeout: 3000 }).catch(() => {}), page.locator('input[name=q]').press('Enter')]);
    ok('Enter hemen gönderdi (?q=xy)', new URL(page.url()).searchParams.get('q') === 'xy', page.url());
    await page.goto(KOK + '/arama.php');
    await page.selectOption('#postForm select', '2');
    await bekle(500);
    ok('POST formu otomatik gönderilmedi', yol() === '/arama.php', yol());

    // ── C) 390 px: yatay taşma yok ──────────────────────────
    console.log('\n=== MOBİL 390 ===');
    for (const [ad, url] of [['Günlük Puantaj', LURL], ['Toplu Döküm', TURL]]) {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(url);
        const tasma = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        ok(`${ad}: 390 px'te yatay taşma yok`, tasma <= 0, `${tasma}px`);
    }
    await page.goto(LURL);
    ok('Mobil kart hâlâ tek parça bağlantı (gunluk_isci_puantaj_detay)', await page.locator('.mobile-only a.pdks-card-item[href="gunluk_isci_puantaj_detay.php?id=1"]').count() === 1);

    ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));

    await browser.close();
    sunucu.close();
    console.log(`\nSONUÇ: ${hata === 0 ? 'TÜMÜ GEÇTİ' : hata + ' HATA'}`);
    process.exit(hata === 0 ? 0 : 1);
})().catch((e) => { console.error(e); sunucu.close(); process.exit(1); });
