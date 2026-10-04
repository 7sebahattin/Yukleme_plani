// =========================================================
// scripts/pdks_toplu_duzelt_smoke.js — v302 Kart Hareketleri: SEÇEREK toplu düzenle /
// toplu iptal arayüzünün GERÇEK TARAYICI testi (düzen hatalarını PHP/statik testler göremez)
//
//   node scripts/pdks_toplu_duzelt_smoke.js     (php + Playwright gerekir; Playwright yoksa ATLAR)
//
// Detay sayfası PUANTAJ_TOPLU_DUZELT=1 ile scripts/pdks_puantaj_dialog_render.php'den
// (yönetici, gerçek CSS, bellek içi SQLite) basılır ve küçük bir http sunucusundan açılır;
// ?ajax=toplu_duzelt / toplu_iptal uçları page.route ile taklit edilir (sunucu kuralları
// scripts/pdks_toplu_duzelt_smoke.php'de sınanır). `playwright install` ÇALIŞTIRMA.
// Ekran görüntüleri: SHOT_DIR (verilmezse alınmaz).
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
    console.log(`${ad.padEnd(92)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}
const DETAY = execFileSync('php', [path.join(__dirname, 'pdks_puantaj_dialog_render.php')], {
    cwd: ROOT, env: Object.assign({}, process.env, { PUANTAJ_TOPLU_DUZELT: '1' }), maxBuffer: 32 * 1024 * 1024 }).toString('utf8');

const TIPLER = { '.css': 'text/css', '.js': 'application/javascript', '.svg': 'image/svg+xml', '.png': 'image/png' };
const sunucu = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(DETAY); return; }
    const dosya = path.join(ROOT, path.normalize(u.pathname).replace(/^(\.\.[/\\])+/, ''));
    if (!dosya.startsWith(ROOT) || !fs.existsSync(dosya) || fs.statSync(dosya).isDirectory()) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': TIPLER[path.extname(dosya)] || 'text/html; charset=utf-8' });
    fs.createReadStream(dosya).pipe(res);
});

const bugun = (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
const yarin = (() => { const p = bugun.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + 1)).toISOString().slice(0, 10); })();
const SECIM = ['K001', 'Z001', 'K002'];   // kapalı · gece vardiyası (Karışık, +1 gün) · açık/çıkışsız (+ kartsız ayrıca)

const gorunenDialoglar = page => page.evaluate(() => [...document.querySelectorAll('dialog')]
    .filter(d => { const r = d.getBoundingClientRect(); return getComputedStyle(d).display !== 'none' && r.width > 0 && r.height > 0; }).map(d => d.id));
// Görünen görünümdeki (masaüstü tablo / mobil kart) kutuya GERÇEK tıklama
async function kutuTikla(page, kart) {
    const h = await page.evaluateHandle(k => [...document.querySelectorAll('input.td-sec')]
        .find(c => c.getAttribute('data-td-kart') === k && c.getClientRects().length && c.offsetWidth > 0), kart);
    const e = h.asElement();
    if (!e) throw new Error('görünür kutu yok: ' + kart);
    await e.scrollIntoViewIfNeeded();
    await e.click();
}
async function kartsizKart(page) {
    return page.evaluate(() => (document.querySelector('input.td-sec[data-td-kartsiz="1"]') || {}).getAttribute
        ? document.querySelector('input.td-sec[data-td-kartsiz="1"]').getAttribute('data-td-kart') : null);
}
const durum = page => page.evaluate(() => {
    const vals = {}; document.querySelectorAll('input.td-sec').forEach(c => { (vals[c.value] = vals[c.value] || []).push(c.checked); });
    const tutarli = Object.values(vals).every(a => a.length === 2 && a[0] === a[1]);
    const secili = Object.keys(vals).filter(v => vals[v][0]);
    const vurgu = [...document.querySelectorAll('input.td-sec:checked')].every(c => c.closest('tr, .pdks-card-item').classList.contains('td-secili'));
    const vurguYok = [...document.querySelectorAll('input.td-sec:not(:checked)')].every(c => !c.closest('tr, .pdks-card-item').classList.contains('td-secili'));
    const tumu = [...document.querySelectorAll('input.td-tumu')].map(t => ({ c: t.checked, i: t.indeterminate }));
    return { toplam: Object.keys(vals).length, secili, tutarli, vurgu, vurguYok, tumu,
             sayi: document.getElementById('tdSeciliSayi').textContent,
             duz: document.getElementById('tdDuzenleBtn').disabled, ipt: document.getElementById('tdIptalBtn').disabled, tem: document.getElementById('tdTemizleBtn').disabled };
});
// Pencere düzeni: ekran içinde, alt çubuk görünür, gövde kayar, en alttaki alan görünür + tıklanabilir, yatay taşma yok
async function duzenOlc(page, dlgId, sonAlanSec) {
    return page.evaluate(([id, sel]) => {
        const d = document.getElementById(id), r = d.getBoundingClientRect();
        const g = d.querySelector('.td-govde'), f = d.querySelector('.td-foot'), fr = f.getBoundingClientRect(), form = d.querySelector('form');
        const kaydirilir = g.scrollHeight > g.clientHeight + 1;
        g.scrollTop = g.scrollHeight;
        const kaydi = g.scrollTop > 0;
        const alan = d.querySelector(sel), ar = alan.getBoundingClientRect(), gr = g.getBoundingClientRect();
        const ust = document.elementFromPoint(ar.left + ar.width / 2, ar.top + Math.min(ar.height / 2, 10));
        const footBtn = f.querySelector('.btn'), br = footBtn.getBoundingClientRect();
        const btnUst = document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2);
        return { modal: d.matches(':modal'), icinde: r.left >= -0.5 && r.right <= innerWidth + 0.5 && r.top >= -0.5 && r.bottom <= innerHeight + 0.5,
                 footEkranda: fr.top >= 0 && fr.bottom <= innerHeight + 0.5 && fr.height > 0, footDialogda: fr.bottom <= r.bottom + 0.5,
                 kaydirilir, kaydi, alanGorunur: ar.height > 0 && ar.top >= gr.top - 0.5 && ar.bottom <= gr.bottom + 0.5,
                 alanTiklanir: !!ust && (ust === alan || alan.contains(ust)), footTiklanir: !!btnUst && (btnUst === footBtn || footBtn.contains(btnUst)),
                 formTasma: form.scrollWidth > form.clientWidth + 1, govdeTasma: g.scrollWidth > g.clientWidth + 1, sayfaTasma: document.documentElement.scrollWidth > innerWidth };
    }, [dlgId, sonAlanSec]);
}

(async () => {
    await new Promise(r => sunucu.listen(0, '127.0.0.1', r));
    const KOK = `http://127.0.0.1:${sunucu.address().port}/`;
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', k: 'pc', width: 1280, height: 900 }, { ad: 'TABLET', k: 'tab', width: 820, height: 1024 }, { ad: 'MOBİL', k: 'mob', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const jsHata = [];
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        // Koyu tema (kullanıcının ekran görüntüsündeki gibi) masaüstü + mobilde; tablet AÇIK tema.
        // data-theme her gezinmede (redirect dahil) yeniden yazılır.
        const koyu = ekran.k !== 'tab';
        if (koyu) await page.addInitScript(() => document.addEventListener('readystatechange', () => document.documentElement.setAttribute('data-theme', 'dark'), { once: true }));
        page.on('pageerror', e => jsHata.push(String(e)));
        page.on('console', m => { if (m.type() === 'error' && !/404/.test(m.text())) jsHata.push(m.text()); });
        const istekler = [];
        let yanitlar = { toplu_duzelt: [], toplu_iptal: [] };
        await page.route(/ajax=toplu_(duzelt|iptal)/, async route => {
            const req = route.request(); const uc = new URL(req.url()).searchParams.get('ajax');
            let govde = {}; try { govde = JSON.parse(req.postData() || '{}'); } catch (e) {}
            istekler.push({ uc, govde, ct: req.headers()['content-type'], yontem: req.method(), id: new URL(req.url()).searchParams.get('id') });
            const y = yanitlar[uc].shift() || { ok: false, hata: 'beklenmeyen istek' };
            await route.fulfill({ status: y.__kod || 200, contentType: 'application/json', body: JSON.stringify(y) });
        });

        await page.goto(KOK);
        await page.waitForTimeout(400);
        ok('açılışta hiçbir dialog görünmüyor', (await gorunenDialoglar(page)).length === 0, (await gorunenDialoglar(page)).join(','));
        ok('yatay taşma yok (sayfa)', await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));

        // ── Seçim çubuğu + kutular ──
        let d = await durum(page);
        const cubuk = await page.evaluate(() => { const c = document.getElementById('tdCubuk'); const r = c.getBoundingClientRect();
            return { g: r.width > 0 && r.height > 0, e: r.left >= 0 && r.right <= innerWidth + 0.5, tumuGorunur: getComputedStyle(c.querySelector('.td-cubuk-tumu')).display !== 'none' }; });
        ok('seçim çubuğu görünür ve ekran içinde', cubuk.g && cubuk.e, JSON.stringify(cubuk));
        ok('çubuktaki "Tümünü seç" yalnız <1024px\'te (masaüstünde başlıkta)', cubuk.tumuGorunur === (ekran.k !== 'pc'), JSON.stringify(cubuk));
        ok(`her dönem için 2 kutu (tablo + kart), ${d.toplam} dönem; açılışta 0 seçili, düğmeler PASİF`, d.toplam >= 10 && d.tutarli && d.secili.length === 0 && d.sayi === '0' && d.duz && d.ipt && d.tem, JSON.stringify(d));
        const thBilgi = await page.evaluate(() => { const th = document.querySelector('table[data-pdks-sirala] thead th'); const t = th.querySelector('input.td-tumu');
            return { metin: th.textContent.trim(), tumu: !!t, id: t ? t.id : null, sirala: th.getAttribute('data-sirala'), ilkHucreKart: document.querySelector('table[data-pdks-sirala] tbody tr').cells[0].querySelector('input.td-sec') !== null }; });
        ok('masaüstü: "tümünü seç" Kart No başlığında (class, id YOK), başlık metni "Kart No", seçim kutusu ilk hücrede', thBilgi.tumu && !thBilgi.id && thBilgi.metin === 'Kart No' && thBilgi.ilkHucreKart, JSON.stringify(thBilgi));
        const kartsiz = await kartsizKart(page);
        ok('veride kartsız dönem var (data-td-kartsiz="1")', !!kartsiz, String(kartsiz));
        const secilecek = SECIM.concat([kartsiz]);

        for (const k of secilecek) await kutuTikla(page, k);
        d = await durum(page);
        ok('4 kutu tıklanınca: sayaç 4, düğmeler AÇIK, tablo ⇄ kart eşit, satırlar vurgulu', d.sayi === '4' && d.secili.length === 4 && d.tutarli && d.vurgu && d.vurguYok && !d.duz && !d.ipt && !d.tem, JSON.stringify(d));
        ok('kısmi seçimde "tümünü seç" belirsiz (indeterminate)', d.tumu.every(t => !t.c && t.i), JSON.stringify(d.tumu));

        // Tümünü seç / kaldır (görünen kutu)
        const tumuTikla = () => page.evaluate(() => [...document.querySelectorAll('input.td-tumu')].find(t => t.getClientRects().length && t.offsetWidth > 0).click());
        await tumuTikla();
        d = await durum(page);
        ok('"Tümünü seç": hepsi seçili, sayaç = dönem sayısı, iki görünüm eşit', d.secili.length === d.toplam && d.sayi === String(d.toplam) && d.tutarli && d.vurgu && d.tumu.every(t => t.c && !t.i), JSON.stringify(d));
        await tumuTikla();
        d = await durum(page);
        ok('"Tümünü seç" tekrar: hiçbiri seçili değil, düğmeler PASİF', d.secili.length === 0 && d.sayi === '0' && d.duz && d.ipt && d.vurguYok, JSON.stringify(d));

        // ── Düzen ölçümü: TÜM dönemler seçili → uzun liste, gövde kaymalı ──
        await tumuTikla();
        await page.click('#tdDuzenleBtn');
        await page.waitForTimeout(400);
        ok('"Seçilenleri Düzenle": yalnız TEK dialog (tdDuzenle) açık', (await gorunenDialoglar(page)).join() === 'tdDuzenle', (await gorunenDialoglar(page)).join(','));
        let g = await duzenOlc(page, 'tdDuzenle', '#tdNot');
        ok('düzenle (tüm satırlar): modal, ekran içinde, alt çubuk ekranda + tıklanabilir', g.modal && g.icinde && g.footEkranda && g.footDialogda && g.footTiklanir, JSON.stringify(g));
        ok('düzenle: gövde GERÇEKTEN kayıyor; en alttaki alan (Açıklama) görünür + tıklanabilir', g.kaydirilir && g.kaydi && g.alanGorunur && g.alanTiklanir, JSON.stringify(g));
        ok('düzenle: yatay taşma yok (form / gövde / sayfa)', !g.formTasma && !g.govdeTasma && !g.sayfaTasma, JSON.stringify(g));
        const mob = await page.evaluate(() => {
            const s = document.querySelector('#tdListe .td-satir'), cs = getComputedStyle(s);
            const yazilar = [...document.querySelectorAll('#tdDuzenle input[type=time], #tdDuzenle select, #tdDuzenle textarea')].map(e => parseFloat(getComputedStyle(e).fontSize));
            const bas = getComputedStyle(document.querySelector('#tdDuzenle .td-liste-bas')).display;
            return { sutun: cs.gridTemplateColumns.split(' ').length, minFont: Math.min.apply(null, yazilar), bas };
        });
        const tema = await page.evaluate(() => { const d = document.getElementById('tdDuzenle'); const t = getComputedStyle(d.querySelector('#tdListe input[type=time]')).backgroundColor;
            return { dark: document.documentElement.getAttribute('data-theme') === 'dark', saat: t, secim: getComputedStyle(d.querySelector('#tdListe select')).backgroundColor, dlg: getComputedStyle(d).backgroundColor }; });
        ok(`tema (${koyu ? 'koyu' : 'açık'}): saat kutusu zemini select ile aynı (global kural type=time'ı kapsamıyor)`, tema.dark === koyu && tema.saat === tema.secim && (koyu ? tema.dlg !== 'rgb(255, 255, 255)' : tema.saat === 'rgb(255, 255, 255)'), JSON.stringify(tema));
        if (ekran.k === 'mob') ok('mobil: liste satırı kart gibi (2 sütun, başlık satırı gizli), girdiler ≥16px', mob.sutun === 2 && mob.bas === 'none' && mob.minFont >= 16, JSON.stringify(mob));
        else ok('geniş ekran: liste satırı tek satır (5 sütun) + başlık satırı', mob.sutun === 5 && mob.bas !== 'none', JSON.stringify(mob));
        if (SHOT) await page.screenshot({ path: path.join(SHOT, 'v302_duzenle_tum_' + ekran.k + '.png') });
        await page.evaluate(() => document.getElementById('tdDuzenle').close());
        await page.click('#tdTemizleBtn');
        d = await durum(page);
        ok('"Seçimi temizle": 0 seçili', d.secili.length === 0 && d.sayi === '0', JSON.stringify(d));

        // ── 4 satırla işlev ──
        for (const k of secilecek) await kutuTikla(page, k);
        await page.click('#tdDuzenleBtn');
        await page.waitForTimeout(400);
        const ac1 = await page.evaluate(() => {
            const dl = document.getElementById('tdDuzenle');
            const satirlar = [...dl.querySelectorAll('#tdListe .td-satir')].map(s => {
                const tip = s.querySelector('.td-f-tip');
                return { pid: s.getAttribute('data-pid'), kart: s.querySelector('.td-kart-no').textContent, tip: tip.value, tipMetin: tip.options[tip.selectedIndex].text,
                         giris: s.querySelector('.td-f-giris').value, cikis: s.querySelector('.td-f-cikis').value, gun: !s.querySelector('.td-gun').hidden,
                         kartsiz: !!s.querySelector('.pdks-badge-kartsiz'), degisti: s.classList.contains('td-degisti') };
            });
            const kutu = {}; document.querySelectorAll('input.td-sec[data-td-gorunum="pc"]').forEach(c => { kutu[c.getAttribute('data-td-kart')] = { pid: c.value, tip: c.getAttribute('data-td-tip'), giris: c.getAttribute('data-td-giris'), cikis: c.getAttribute('data-td-cikis') }; });
            const tipler = [...document.querySelectorAll('#tdHepTip option')].map(o => ({ v: o.value, t: o.text }));
            return { satirlar, kutu, tipler, ozet: document.getElementById('tdOzet').textContent, sayi: document.getElementById('tdDuzenleSayi').textContent };
        });
        const S = Object.fromEntries(ac1.satirlar.map(s => [s.kart, s]));
        ok('pencerede seçilen 4 satır listeleniyor (başlıkta "(4)")', ac1.satirlar.length === 4 && secilecek.every(k => S[k]) && ac1.sayi === '(4)', JSON.stringify(ac1.satirlar.map(s => s.kart)));
        ok('satırlar mevcut değerlerle ÖNCEDEN dolu (tip, giriş, çıkış = sunucu verisi)', ac1.satirlar.every(s => s.tip === ac1.kutu[s.kart].tip && s.giris === ac1.kutu[s.kart].giris && s.cikis === ac1.kutu[s.kart].cikis && s.pid === ac1.kutu[s.kart].pid), JSON.stringify(ac1));
        ok('K001 07:00–17:30; K002 08:15 çıkışsız (boş)', S.K001.giris === '07:00' && S.K001.cikis === '17:30' && S.K002.giris === '08:15' && S.K002.cikis === '', JSON.stringify([S.K001, S.K002]));
        ok('gece vardiyası Z001 (22:00 → 02:00): "+1 gün" görünür; diğerlerinde yok', S.Z001.gun && !S.K001.gun && !S.K002.gun && !S[kartsiz].gun, JSON.stringify(ac1.satirlar));
        ok('Karışık satır: mevcut tip "(değiştirmeden bırak)" seçili', /Karışık \(değiştirmeden bırak\)/.test(S.Z001.tipMetin), S.Z001.tipMetin);
        ok('kartsız satırda "Kartsız" rozeti', S[kartsiz].kartsiz && !S.K001.kartsiz);
        ok('açılışta hiçbir satır "değişti" değil, özet "0 / 4"', ac1.satirlar.every(s => !s.degisti) && /^0 \/ 4/.test(ac1.ozet), ac1.ozet);
        const erkek = (ac1.tipler.find(t => /Erkek/i.test(t.t)) || {}).v;
        ok('"Hepsine uygula" tip listesi: "— Değiştirme —" + Kadın/Erkek/Rampacı (Karışık YOK)', ac1.tipler[0].v === '' && ac1.tipler.length === 4 && !!erkek && !ac1.tipler.some(t => /Karışık/.test(t.t)), JSON.stringify(ac1.tipler));

        // Hepsine uygula
        await page.selectOption('#tdHepTip', erkek);
        await page.fill('#tdHepGiris', '06:00');
        await page.fill('#tdHepCikis', '05:30');
        await page.click('#tdHepUygula');
        const hep = await page.evaluate(() => ({ satirlar: [...document.querySelectorAll('#tdListe .td-satir')].map(s => ({ tip: s.querySelector('.td-f-tip').value, g: s.querySelector('.td-f-giris').value, c: s.querySelector('.td-f-cikis').value,
            gun: !s.querySelector('.td-gun').hidden, degisti: s.classList.contains('td-degisti'), isaret: !s.querySelector('.td-isaret').hidden, eski: !s.querySelector('.td-eski').hidden ? s.querySelector('.td-eski').textContent : '' })),
            ozet: document.getElementById('tdOzet').textContent, sonuc: document.getElementById('tdHepSonuc').textContent }));
        ok('"Uygula": tüm satırlar Erkek / 06:00 / 05:30', hep.satirlar.every(s => s.tip === erkek && s.g === '06:00' && s.c === '05:30'), JSON.stringify(hep));
        ok('çıkış < giriş → tüm satırlarda "+1 gün"; hepsi "değişti" (işaret + "Önce:" metni), özet 4 / 4', hep.satirlar.every(s => s.gun && s.degisti && s.isaret && /^Önce:/.test(s.eski)) && /^4 \/ 4/.test(hep.ozet) && /4 satıra/.test(hep.sonuc), JSON.stringify(hep));
        // Satır bazında: K001 çıkışı 16:00 → +1 gün kalkar
        const k1 = `#tdListe .td-satir[data-pid="${S.K001.pid}"]`;
        await page.fill(k1 + ' .td-f-cikis', '16:00');
        ok('satırda çıkış 16:00 (> 06:00) → "+1 gün" kalkıyor', await page.evaluate(s => document.querySelector(s + ' .td-gun').hidden, k1));
        // K002 (açık) çıkışını boşalt → +1 gün yok
        const k2 = `#tdListe .td-satir[data-pid="${S.K002.pid}"]`;
        await page.fill(k2 + ' .td-f-cikis', '');
        ok('çıkış boşaltılınca "+1 gün" yok', await page.evaluate(s => document.querySelector(s + ' .td-gun').hidden, k2));

        // Neden boş → istemci uyarısı, istek YOK
        await page.click('#tdKaydet');
        await page.waitForTimeout(100);
        const bos = await page.evaluate(() => ({ m: document.getElementById('tdMesaj').hidden ? '' : document.getElementById('tdMesaj').textContent }));
        ok('neden boşken Kaydet: "Düzeltme nedeni zorunludur." ve istek GÖNDERİLMEZ', /nedeni zorunludur/.test(bos.m) && istekler.length === 0, JSON.stringify(bos) + ' istek: ' + istekler.length);

        // Sahte HATA yanıtı → satır satır gösterim
        await page.fill('#tdNeden', 'Saatler yanlış okundu');
        await page.fill('#tdNot', 'Toplu test');
        yanitlar.toplu_duzelt.push({ ok: false, hata: '1 satır hatalı — hiçbir kayıt yazılmadı.', hatalar: [{ period_id: +S.K002.pid, card_no: 'K002', hata: 'Bugün açık dönemde çıkış boş bırakılamaz <b>x</b>' }, { period_id: 0, card_no: 'X9', hata: 'Genel satır hatası' }] });
        await page.click('#tdKaydet');
        await page.waitForTimeout(250);
        const h1 = await page.evaluate(([p2, p1]) => {
            const s2 = document.querySelector(`#tdListe .td-satir[data-pid="${p2}"]`), s1 = document.querySelector(`#tdListe .td-satir[data-pid="${p1}"]`);
            const m = s2.querySelector('.td-satir-mesaj');
            return { hata2: s2.classList.contains('td-satir-hata'), mesaj2: m.hidden ? '' : m.textContent, html2: m.innerHTML, hata1: s1.classList.contains('td-satir-hata'),
                     genel: document.getElementById('tdMesaj').hidden ? '' : document.getElementById('tdMesaj').textContent, acik: document.getElementById('tdDuzenle').open,
                     btn: document.getElementById('tdKaydet').disabled, btnMetin: document.getElementById('tdKaydet').textContent };
        }, [S.K002.pid, S.K001.pid]);
        ok('hata yanıtı: K002 satırı kırmızı + mesajı; diğer satır temiz', h1.hata2 && /açık dönemde çıkış/.test(h1.mesaj2) && !h1.hata1, JSON.stringify(h1));
        ok('hata metni textContent ile (HTML çalışmaz)', !/<b>/.test(h1.html2.replace(/&lt;b&gt;/g, '')) && /<b>x<\/b>/.test(h1.mesaj2), h1.html2);
        ok('genel hata + period_id\'siz satır hatası genel kutuda; pencere açık, Kaydet yeniden etkin', /hiçbir kayıt yazılmadı/.test(h1.genel) && /X9: Genel satır hatası/.test(h1.genel) && h1.acik && !h1.btn && h1.btnMetin === 'Kaydet', JSON.stringify(h1));
        const r1 = istekler[0] || { govde: {} };
        const gv = r1.govde;
        const satirBul = pid => (gv.satirlar || []).find(x => x.period_id === +pid) || {};
        ok('istek: POST, JSON, ?ajax=toplu_duzelt, mesai id URL\'de; gövdede session_id YOK', r1.uc === 'toplu_duzelt' && r1.yontem === 'POST' && /application\/json/.test(r1.ct || '') && /^\d+$/.test(r1.id || '') && !('session_id' in gv), JSON.stringify(r1).slice(0, 300));
        ok('payload: csrf + 32 hex istek_id + reason + note + 4 satır (period_id sırası seçimle aynı küme)', gv.csrf === 'testcsrf' && /^[0-9a-f]{32}$/.test(gv.istek_id || '') && gv.reason === 'Saatler yanlış okundu' && gv.note === 'Toplu test'
            && (gv.satirlar || []).length === 4 && secilecek.every(k => satirBul(S[k].pid).period_id), JSON.stringify(gv));
        ok('payload satırı: worker_type_id = Erkek, entry_clock 06:00, alanlar tam (period_id, worker_type_id, entry_clock, exit_date, exit_clock)',
            (gv.satirlar || []).every(x => x.worker_type_id === +erkek && x.entry_clock === '06:00' && Object.keys(x).sort().join() === 'entry_clock,exit_clock,exit_date,period_id,worker_type_id'), JSON.stringify(gv.satirlar));
        ok('payload: çıkış 05:30 → exit_date = YARIN; K001 16:00 → BUGÜN; K002 boş → exit_date ""', satirBul(S.Z001.pid).exit_date === yarin && satirBul(S.Z001.pid).exit_clock === '05:30'
            && satirBul(S.K001.pid).exit_date === bugun && satirBul(S.K001.pid).exit_clock === '16:00' && satirBul(S.K002.pid).exit_date === '' && satirBul(S.K002.pid).exit_clock === '', JSON.stringify(gv.satirlar));

        // Düzeltip yeniden Kaydet → AYNI istek_id, başarı → redirect
        await page.fill(k2 + ' .td-f-cikis', '09:00');
        yanitlar.toplu_duzelt.push({ ok: true, guncellenen: 4, atlanan: 0, toplu_id: 'TD20260101abcdef12', redirect: '/?kaydedildi=1' });
        await Promise.all([page.waitForURL(/kaydedildi=1/, { timeout: 5000 }).catch(() => null), page.click('#tdKaydet')]);
        const r2 = istekler[1] || { govde: {} };
        ok('ikinci Kaydet: hata satırı temizlenmiş, AYNI istek_id (pencere açıkken), K002 exit_date = bugün', r2.govde.istek_id === gv.istek_id && ((r2.govde.satirlar || []).find(x => x.period_id === +S.K002.pid) || {}).exit_date === bugun, JSON.stringify(r2.govde).slice(0, 200));
        ok('başarıda yanıttaki redirect adresine gidiliyor', /kaydedildi=1/.test(page.url()), page.url());
        await page.waitForTimeout(400);

        // Yeni açılış → yeni istek_id
        for (const k of secilecek) await kutuTikla(page, k);
        await page.click('#tdDuzenleBtn');
        await page.waitForTimeout(300);
        await page.fill('#tdNeden', 'x'); await page.fill(`#tdListe .td-satir[data-pid="${S.K001.pid}"] .td-f-giris`, '07:05');
        yanitlar.toplu_duzelt.push({ ok: false, hata: 'Tekrar gönderim.', tekrar: true, hatalar: [] });
        await page.click('#tdKaydet'); await page.waitForTimeout(200);
        const r3 = istekler[2] || { govde: {} };
        ok('pencere yeniden açılınca YENİ istek_id üretiliyor', /^[0-9a-f]{32}$/.test(r3.govde.istek_id || '') && r3.govde.istek_id !== gv.istek_id, JSON.stringify([r3.govde.istek_id, gv.istek_id]));
        // Değişiklik yoksa istek yok
        await page.fill(`#tdListe .td-satir[data-pid="${S.K001.pid}"] .td-f-giris`, '07:00');
        const onceSay = istekler.length;
        await page.click('#tdKaydet'); await page.waitForTimeout(150);
        ok('hiçbir satır değişmemişse "Hiçbir satırda değişiklik yok." ve istek YOK', istekler.length === onceSay && /değişiklik yok/.test(await page.textContent('#tdMesaj')));
        await page.evaluate(() => document.getElementById('tdDuzenle').close());

        // ── Seçilenleri İptal Et ──
        await page.click('#tdIptalBtn');
        await page.waitForTimeout(400);
        ok('"Seçilenleri İptal Et": yalnız TEK dialog (tdIptal) açık', (await gorunenDialoglar(page)).join() === 'tdIptal', (await gorunenDialoglar(page)).join(','));
        const ip = await page.evaluate(() => ({ ogeler: [...document.querySelectorAll('#tdIptalListe .td-iptal-oge')].map(li => ({ pid: li.getAttribute('data-pid'), t: li.textContent })), sayi: document.getElementById('tdIptalSayi').textContent }));
        ok('iptal penceresi seçilen 4 kartı listeliyor (gece vardiyası "+1 gün" notlu)', ip.ogeler.length === 4 && ip.sayi === '(4)' && ip.ogeler.some(o => /Z001/.test(o.t) && /\+1 gün/.test(o.t)), JSON.stringify(ip));
        g = await duzenOlc(page, 'tdIptal', '#tdIptalNeden');
        ok('iptal: modal, ekran içinde, alt çubuk ekranda + tıklanabilir, neden kutusu görünür + tıklanabilir, yatay taşma yok',
            g.modal && g.icinde && g.footEkranda && g.footTiklanir && g.alanGorunur && g.alanTiklanir && !g.formTasma && !g.govdeTasma && !g.sayfaTasma, JSON.stringify(g));
        const onceIptal = istekler.length;
        await page.click('#tdIptalKaydet'); await page.waitForTimeout(120);
        ok('iptal nedeni boşken istek YOK + uyarı', istekler.length === onceIptal && /İptal nedeni zorunludur/.test(await page.textContent('#tdIptalMesaj')));
        await page.fill('#tdIptalNeden', 'Yanlış mesaiye okutulmuş');
        yanitlar.toplu_iptal.push({ ok: false, hata: 'İptal yapılamadı.', hatalar: [{ period_id: +S.K001.pid, card_no: 'K001', hata: 'Kesinleşmiş hakediş var.' }] });
        await page.click('#tdIptalKaydet'); await page.waitForTimeout(250);
        const ri = istekler[istekler.length - 1];
        ok('iptal payload: ajax=toplu_iptal, csrf, 32 hex istek_id, reason, period_ids (4, tam sayı)', ri.uc === 'toplu_iptal' && ri.govde.csrf === 'testcsrf' && /^[0-9a-f]{32}$/.test(ri.govde.istek_id || '') && ri.govde.reason === 'Yanlış mesaiye okutulmuş'
            && Array.isArray(ri.govde.period_ids) && ri.govde.period_ids.length === 4 && ri.govde.period_ids.every(Number.isInteger) && secilecek.every(k => ri.govde.period_ids.includes(+S[k].pid)) && Object.keys(ri.govde).sort().join() === 'csrf,istek_id,period_ids,reason', JSON.stringify(ri.govde));
        const ih = await page.evaluate(p => { const li = document.querySelector(`#tdIptalListe .td-iptal-oge[data-pid="${p}"]`); const m = li.querySelector('.td-satir-mesaj');
            return { hata: li.classList.contains('td-satir-hata'), m: m.hidden ? '' : m.textContent, genel: document.getElementById('tdIptalMesaj').textContent, digerTemiz: document.querySelectorAll('#tdIptalListe .td-satir-hata').length === 1 }; }, S.K001.pid);
        ok('iptal hata yanıtı: K001 kalemi kırmızı + mesaj, genel mesaj', ih.hata && /Kesinleşmiş/.test(ih.m) && /İptal yapılamadı/.test(ih.genel) && ih.digerTemiz, JSON.stringify(ih));
        yanitlar.toplu_iptal.push({ ok: true, iptal_edilen: 4, atlanan: 0, redirect: '/?iptal=1' });
        await Promise.all([page.waitForURL(/iptal=1/, { timeout: 5000 }).catch(() => null), page.click('#tdIptalKaydet')]);
        ok('iptal başarı → redirect; ikinci denemede AYNI istek_id', /iptal=1/.test(page.url()) && istekler[istekler.length - 1].govde.istek_id === ri.govde.istek_id, page.url());
        await page.waitForTimeout(400);

        // Sıralama sonrası da seçim/eşitleme çalışır (masaüstü başlık / mobil seçici)
        if (ekran.k === 'pc') await page.locator('table[data-pdks-sirala] thead th').nth(0).locator('button').click();
        else await page.selectOption('#kartSiralaSec', 'kart:asc');
        await kutuTikla(page, 'K002');
        d = await durum(page);
        ok('sıralamadan sonra seçim + eşitleme çalışıyor; başlıktaki kutu sıralamayı tetiklemiyor', d.secili.length === 1 && d.tutarli && d.vurgu, JSON.stringify(d));
        if (ekran.k === 'pc') {
            const once = await page.evaluate(() => document.querySelector('table[data-pdks-sirala] thead th').getAttribute('aria-sort'));
            await page.click('table[data-pdks-sirala] thead th input.td-tumu');
            const sonra = await page.evaluate(() => document.querySelector('table[data-pdks-sirala] thead th').getAttribute('aria-sort'));
            d = await durum(page);
            ok('başlıktaki "tümünü seç" sıralamayı DEĞİŞTİRMEZ ve hepsini seçer', once === sonra && d.secili.length === d.toplam, JSON.stringify({ once, sonra, n: d.secili.length }));
        }
        if (SHOT) {
            await page.click('#tdDuzenleBtn'); await page.waitForTimeout(400);
            await page.screenshot({ path: path.join(SHOT, 'v302_duzenle_' + ekran.k + '.png') });
            await page.evaluate(() => document.getElementById('tdDuzenle').close());
            await page.screenshot({ path: path.join(SHOT, 'v302_liste_' + ekran.k + '.png'), fullPage: false });
        }
        ok('JS hatası / konsol hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close();
    sunucu.close();
    console.log(hata ? `\n${hata} HATA` : '\nTÜMÜ OK');
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); sunucu.close(); process.exit(1); });
