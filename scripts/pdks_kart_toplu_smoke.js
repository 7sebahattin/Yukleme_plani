// =========================================================
// scripts/pdks_kart_toplu_smoke.js — v299 Kart Havuzu "⚡ Seri Kart Tanımla"
// penceresinin GERÇEK TARAYICI testi (masaüstü + tablet + mobil).
//
// Doğrulananlar: seçim yapılmadan tarama kapalı · okutma satır ekler ve
// sınıflar (yeni / tanımlanacak / zaten tanımlı / başka çavuş) · aynı UID
// tekrar → yeni satır YOK, mevcut satır vurgulanır · hatalı satır Kaydet'i
// kapatır, silinince açar · çavuş değişince liste yeniden sınıflanır · NFC
// okuması · 45 satırda gövde KAYAR, alt çubuk (Kaydet) ekranda ve TIKLANABİLİR,
// son satırın sil düğmesine ulaşılır · yatay taşma yok · POST gövdesi (depo
// YOK, istek_id, sıra) · sunucu hata satırları listeyi korur · çift tıklama
// tek POST · başarıda ?ok= yönlendirmesi · Temizle · Esc. Ölçümden önce 400 ms
// beklenir (açılış animasyonu — CLAUDE.md "Modal içinde form").
//
// Sunucu cevapları (ajax=tanim_satir) MOCK'lanır; mock verisi sayfaya gömülü
// fixture'dır ve GERÇEK sunucu fonksiyonlarıyla üretilmiştir (render betiği).
//
//   php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html
//   node scripts/pdks_kart_toplu_smoke.js
//
// Playwright yoksa kendini ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');

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
const SAYFA = path.join(ROOT, '_test_kart_toplu.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_kart_toplu.html yok. Önce: php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html');
    process.exit(1);
}
const FIXTURE = JSON.parse(
    fs.readFileSync(SAYFA, 'utf8').match(/id="__fixture">([\s\S]*?)<\/script>/)[1].replace(/<\\\//g, '</'));

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(80)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    const dosya = u.pathname === '/' ? SAYFA : path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

const CAVUS_A = String(FIXTURE.cavus.A), CAVUS_B = String(FIXTURE.cavus.B);
const KADIN = String(FIXTURE.tip.KADIN);

// ── Mock: ajax=tanim_satir fixture'dan; ajax=tanim_toplu_kaydet senaryoya göre; ?ok= → basit sayfa ──
async function mockKur(page, durum) {
    await page.route('**/isci_kartlari.php**', async route => {
        const u = new URL(route.request().url());
        const ajax = u.searchParams.get('ajax');
        if (ajax === 'tanim_satir') {
            const anahtar = [u.searchParams.get('kaynak'), u.searchParams.get('uid'), u.searchParams.get('cavus'), u.searchParams.get('tip')].join('|');
            const c = FIXTURE.cevap[anahtar];
            if (!c) { await route.fulfill({ status: 404, body: 'fixture yok: ' + anahtar }); return; }
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify(c) });
        } else if (ajax === 'tanim_toplu_kaydet') {
            durum.posts.push(JSON.parse(route.request().postData()));
            if (durum.gecikme) await new Promise(r => setTimeout(r, durum.gecikme));
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify(durum.cevap) });
        } else if (u.searchParams.has('ok')) {
            await route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>ok</title><p>yönlendirildi</p>' });
        } else {
            await route.continue();
        }
    });
}

async function bitmesiniBekle(page) {
    await page.waitForFunction(() => !document.querySelector('#iskSeriListe .isk-seri-s-bekliyor'), null, { timeout: 5000 });
}
async function tara(page, uid) {
    await page.fill('#iskSeriGiris', uid);
    await page.press('#iskSeriGiris', 'Enter');
    await bitmesiniBekle(page);
}
const satirlar = page => page.$$eval('#iskSeriListe .isk-seri-satir', els => els.map(e => ({ cls: e.className, txt: e.textContent.replace(/\s+/g, ' ').trim() })));
const sinifVar = (liste, i, s) => !!liste[i] && liste[i].cls.includes('isk-seri-s-' + s);

async function olc(page) {
    return page.evaluate(() => {
        const ovl = document.getElementById('iskSeriModal');
        const dlgEl = ovl.querySelector('.pm-dialog');
        const dlg = dlgEl.getBoundingClientRect();
        const govde = ovl.querySelector('.isk-seri-body');
        const kaydet = document.getElementById('iskSeriKaydet').getBoundingClientRect();
        const ust = document.elementFromPoint(kaydet.left + kaydet.width / 2, kaydet.top + kaydet.height / 2);
        return {
            acik: !ovl.hidden, vw: innerWidth, vh: innerHeight,
            dlg: { sol: dlg.left, sag: dlg.right, ust: dlg.top, alt: dlg.bottom },
            kaydetEkranda: kaydet.top >= 0 && kaydet.bottom <= innerHeight && kaydet.left >= 0 && kaydet.right <= innerWidth,
            kaydetTiklanir: !!ust && !!ust.closest('#iskSeriKaydet'),
            govdeKayar: govde.scrollHeight > govde.clientHeight + 1,
            govdeOverflow: getComputedStyle(govde).overflowY,
            tasma: document.documentElement.scrollWidth > innerWidth,
            tasmaDlg: dlgEl.scrollWidth > dlgEl.clientWidth + 1,
            tasmaGovde: govde.scrollWidth > govde.clientWidth + 1,
        };
    });
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET',   width: 820,  height: 1024 },
                         { ad: 'MOBİL',    width: 390,  height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const ctx = await browser.newContext({ viewport: { width: ekran.width, height: ekran.height } });
        // Web NFC taklidi (sürekli dinleme): okuma olayını test tetikler.
        await ctx.addInitScript(() => {
            window.NDEFReader = class { constructor() { window.__ndef = this; this.l = {}; } addEventListener(t, f) { this.l[t] = f; } scan() { return Promise.resolve(); } };
        });
        const page = await ctx.newPage();
        const durum = { posts: [], cevap: { ok: true, mesaj: 'x' }, gecikme: 0 };
        await mockKur(page, durum);
        await page.goto(KOK);
        await page.waitForTimeout(300);

        // ── A. Açılış ──
        ok('"⚡ Seri Kart Tanımla" düğmesi sayfada görünür', await page.isVisible('#iskSeriAc'));
        ok('v300: "Yeni Kart Tanımla" bölümü / kart_ekle formu YOK; hazır-değil uyarısı çizilmez',
            !/Yeni Kart Tanımla|KARTI HAVUZA EKLE/.test(await page.content()) && (await page.locator('input[name="action"][value="kart_ekle"]').count()) === 0
            && (await page.locator('#iskSeriYok').count()) === 0);
        await page.click('#iskSeriAc');
        await page.waitForTimeout(400);
        let m = await olc(page);
        ok('pencere açıldı; ekran içinde; yatay taşma yok', m.acik && m.dlg.sol >= -0.5 && m.dlg.sag <= m.vw + 0.5 && m.dlg.alt <= m.vh + 0.5 && !m.tasma && !m.tasmaDlg && !m.tasmaGovde, JSON.stringify(m));
        ok('seçim yokken tarama kutusu KAPALI, Kaydet KAPALI', await page.isDisabled('#iskSeriGiris') && await page.isDisabled('#iskSeriKaydet'));
        ok('depo bilgisi aktif depo (istemciden seçilmez)', /Depo A/.test(await page.textContent('#iskSeriModal .isk-seri-body')));
        if (ekran.width < 768) {
            const fs16 = await page.evaluate(() => ['iskSeriGiris', 'iskSeriCavus', 'iskSeriTip'].map(id => parseFloat(getComputedStyle(document.getElementById(id)).fontSize)));
            ok('mobil: girdi/seçim yazı boyutu ≥ 16px (iOS yakınlaşma yok)', fs16.every(x => x >= 16), JSON.stringify(fs16));
        }

        // ── B. Seçim → tarama açılır ──
        await page.selectOption('#iskSeriCavus', CAVUS_A);
        await page.selectOption('#iskSeriTip', KADIN);
        await page.waitForTimeout(50);
        ok('çavuş + tip seçilince tarama kutusu AÇILIR', !(await page.isDisabled('#iskSeriGiris')));

        // ── C. Okutma → satır + sınıf ──
        await tara(page, '100000001');   // K001: havuzda, tanımsız
        await tara(page, '200000001');   // havuzda yok
        await tara(page, '100000002');   // K002: zaten A / Kadın
        let s = await satirlar(page);
        ok('3 okutma → 3 satır; tanımlanacak · yeni · zaten tanımlı sınıfları', s.length === 3 && sinifVar(s, 0, 'tanimlanacak') && sinifVar(s, 1, 'yeni') && sinifVar(s, 2, 'ayni'), JSON.stringify(s));
        ok('satır 1 kart no (K001), satır 2 "otomatik eklenecek"', /K001/.test(s[0].txt) && /otomatik eklenecek/.test(s[1].txt), JSON.stringify(s));
        const sayac = await page.textContent('#iskSeriSayac');
        ok('sayaç: 3 kart · 1 yeni · 1 tanımlanacak · 1 zaten tanımlı', /3 kart/.test(sayac) && /1 yeni/.test(sayac) && /1 tanımlanacak/.test(sayac) && /1 zaten tanımlı/.test(sayac), sayac);
        ok('hatasız listede Kaydet AÇIK', !(await page.isDisabled('#iskSeriKaydet')));

        // ── D. Aynı UID tekrar → yeni satır yok, vurgu ──
        await page.fill('#iskSeriGiris', '200000001');
        await page.press('#iskSeriGiris', 'Enter');
        await page.waitForTimeout(80);
        s = await satirlar(page);
        ok('aynı UID tekrar → hâlâ 3 satır, mevcut satır VURGULU', s.length === 3 && s[1].cls.includes('isk-seri-vurgu'), JSON.stringify(s));
        ok('"Zaten listede" bilgisi gösterilir', /Zaten listede/.test(await page.textContent('#iskSeriDurum')));

        // ── E. Başka çavuşa tanımlı → engel ──
        await tara(page, '100000003');   // K003: B / Erkek
        s = await satirlar(page);
        ok('başka çavuşa tanımlı kart → kırmızı satır + "Tanımı Kaldır" yönlendirmesi', sinifVar(s, 3, 'baska_cavus') && /Çavuş B/.test(s[3].txt) && /Tanımı Kaldır/.test(s[3].txt), JSON.stringify(s[3]));
        ok('hatalı satır varken Kaydet KAPALI', await page.isDisabled('#iskSeriKaydet'));
        await page.click('#iskSeriListe .isk-seri-satir:nth-child(4) [data-sil]');
        s = await satirlar(page);
        ok('hatalı satır silinince 3 satır, Kaydet yeniden AÇIK', s.length === 3 && !(await page.isDisabled('#iskSeriKaydet')));

        // ── F. Çavuş değişince yeniden sınıflanır ──
        await page.selectOption('#iskSeriCavus', CAVUS_B);
        await bitmesiniBekle(page);
        s = await satirlar(page);
        ok("çavuş B'ye geçince K002 (A'ya tanımlı) → engel; Kaydet KAPALI", sinifVar(s, 2, 'baska_cavus') && await page.isDisabled('#iskSeriKaydet'), JSON.stringify(s));
        await page.selectOption('#iskSeriCavus', CAVUS_A);
        await bitmesiniBekle(page);
        s = await satirlar(page);
        ok("çavuş A'ya dönünce liste tekrar temiz (K002 zaten tanımlı), Kaydet AÇIK", sinifVar(s, 2, 'ayni') && !(await page.isDisabled('#iskSeriKaydet')), JSON.stringify(s));

        // ── G. NFC ──
        ok('NFC düğmesi (destek varken) görünür', await page.isVisible('#iskSeriNfc'));
        await page.click('#iskSeriNfc');
        await page.waitForTimeout(50);
        await page.evaluate(() => window.__ndef.l.reading({ serialNumber: '04:a1:b2:c3' }));
        await bitmesiniBekle(page);
        await page.evaluate(() => window.__ndef.l.reading({ serialNumber: '04:a1:b2:c3' }));   // aynı kart tekrar
        await page.waitForTimeout(100);
        s = await satirlar(page);
        ok('NFC okuması satır ekler (yeni); aynı kart tekrar → ek satır YOK', s.length === 4 && sinifVar(s, 3, 'yeni'), JSON.stringify(s));

        // ── H. Uzun liste: gövde KAYAR, alt çubuk ekranda + tıklanabilir ──
        for (let i = 1; i <= 40; i++) await tara(page, String(500000000 + i));
        s = await satirlar(page);
        ok('44 satır (3 + NFC + 40)', s.length === 44, String(s.length));
        await page.waitForTimeout(400);
        m = await olc(page);
        ok('gövde KAYAR (overflow-y auto + içerik taşıyor)', m.govdeKayar && /auto|scroll/.test(m.govdeOverflow), JSON.stringify(m));
        ok('uzun listede dialog ekran içinde; alt çubuk: Kaydet ekranda ve TIKLANABİLİR', m.dlg.ust >= -0.5 && m.dlg.alt <= m.vh + 0.5 && m.kaydetEkranda && m.kaydetTiklanir, JSON.stringify(m));
        ok('uzun listede yatay taşma yok (sayfa + dialog + gövde)', !m.tasma && !m.tasmaDlg && !m.tasmaGovde, JSON.stringify(m));
        const son = await page.evaluate(() => {
            const g = document.querySelector('#iskSeriModal .isk-seri-body');
            g.scrollTop = g.scrollHeight;
            const b = [...document.querySelectorAll('#iskSeriListe [data-sil]')].pop();
            const r = b.getBoundingClientRect(), f = document.getElementById('iskSeriKaydet').getBoundingClientRect();
            const ust = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return { tik: !!ust && ust.closest('[data-sil]') === b, altaSigar: r.bottom <= f.top + 1, r: [r.top, r.bottom], f: [f.top, f.bottom] };
        });
        ok('en alttaki satırın sil düğmesi görünür ve TIKLANABİLİR (alt çubuğun altında kalmaz)', son.tik && son.altaSigar, JSON.stringify(son));

        // ── I. Kaydet: sunucu hata satırları → liste KORUNUR ──
        durum.cevap = { ok: false, kod: 'hatali_satir', hata: 'Listede hatalı satırlar var — hiçbir kart yazılmadı.',
                        satirlar: [{ idx: 0, sinif: 'baska_cavus', hata: 'Sunucu hatası: başka biri tanımladı', card_no: 'K001', tanim: null }] };
        await page.click('#iskSeriKaydet');
        await page.waitForSelector('#iskSeriMesaj:not([hidden])');
        s = await satirlar(page);
        ok('sunucu hatası: bildirim görünür, liste KORUNDU (44 satır), 1. satır kırmızı', s.length === 44 && sinifVar(s, 0, 'baska_cavus') && /Sunucu hatası/.test(s[0].txt) && /hiçbir kart yazılmadı/.test(await page.textContent('#iskSeriMesaj')), JSON.stringify(s[0]));
        const p1 = durum.posts[0];
        ok('POST gövdesi: csrf, çavuş, tip, 32 haneli istek_id, 44 satır — DEPO YOK', durum.posts.length === 1 && p1.csrf === 'testcsrf' && String(p1.cavus) === CAVUS_A && String(p1.tip) === KADIN
            && /^[a-f0-9]{32}$/.test(p1.istek_id) && p1.satirlar.length === 44 && !('depo' in p1), JSON.stringify(p1).slice(0, 300));
        ok('satırlar okutma SIRASIYLA gider (ilk 100000001, ikinci 200000001; NFC kaynak web_nfc)', p1.satirlar[0].uid === '100000001' && p1.satirlar[1].uid === '200000001' && p1.satirlar[3].kaynak === 'web_nfc' && p1.satirlar[3].uid === '04:a1:b2:c3', JSON.stringify(p1.satirlar.slice(0, 4)));
        ok('hatalı satır varken Kaydet yine KAPALI', await page.isDisabled('#iskSeriKaydet'));
        await page.evaluate(() => document.querySelector('#iskSeriListe .isk-seri-satir [data-sil]').click());
        s = await satirlar(page);
        ok('hatalı satır silinince 43 satır, Kaydet AÇIK', s.length === 43 && !(await page.isDisabled('#iskSeriKaydet')));

        // ── J. Kaydet: çift tıklama TEK POST; başarıda ?ok= ──
        durum.cevap = { ok: true, mesaj: '41 kart tanımlandı: 41 yeni havuza eklendi, 0 zaten tanımlıydı.' };
        durum.gecikme = 300;
        await page.dblclick('#iskSeriKaydet');
        await page.waitForURL(/isci_kartlari\.php\?ok=/, { timeout: 5000 });
        ok('başarıda ?ok= yönlendirmesi (flash mesajı)', /ok=41%20kart%20tan%C4%B1mland%C4%B1/.test(page.url()), page.url());
        ok('çift tıklama TEK POST (toplam 2: ilk hatalı deneme + bu)', durum.posts.length === 2, String(durum.posts.length));
        ok('liste değişince istek_id YENİLENDİ (hata sonrası silme → yeni anahtar)', durum.posts[1].istek_id !== durum.posts[0].istek_id && durum.posts[1].satirlar.length === 43);
        await ctx.close();

        // ── K. Temizle + Esc (taze sayfa) ──
        const ctx2 = await browser.newContext({ viewport: { width: ekran.width, height: ekran.height } });
        const page2 = await ctx2.newPage();
        await mockKur(page2, { posts: [], cevap: {}, gecikme: 0 });
        await page2.goto(KOK);
        await page2.waitForTimeout(300);
        await page2.click('#iskSeriAc');
        await page2.waitForTimeout(400);
        await page2.selectOption('#iskSeriCavus', CAVUS_A);
        await page2.selectOption('#iskSeriTip', KADIN);
        await tara(page2, '100000001');
        await page2.click('#iskSeriTemizle');
        ok('Listeyi Temizle → 0 satır, "Henüz kart okutulmadı", Kaydet KAPALI', (await satirlar(page2)).length === 0 && await page2.isVisible('#iskSeriBos') && await page2.isDisabled('#iskSeriKaydet'));
        await page2.keyboard.press('Escape');
        await page2.waitForTimeout(100);
        ok('Esc pencereyi kapatır', await page2.evaluate(() => document.getElementById('iskSeriModal').hidden));
        await ctx2.close();
    }

    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTüm kontroller geçti.');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
