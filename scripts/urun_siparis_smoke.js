// =========================================================
// scripts/urun_siparis_smoke.js — Ürün Siparişleri GERÇEK TARAYICI testi (Playwright + Chromium)
//   URUN_SIPARIS_OUT=/tmp/usip php scripts/urun_siparis_render.php
//   URUN_SIPARIS_OUT=/tmp/usip node scripts/urun_siparis_smoke.js
// Ekranlar 1280x900 · 390x844 (ölçümden önce 400 ms beklenir). Ölçer: yatay taşma yok · satır/kart tıklanır, "Düzenle" düğmesi yok ·
// pencere açılır/doludur, alt çubuk ekranda ve gövde kayar (CLAUDE.md pm-dialog kuralı) · Yeni/Temizle YOK, Sil yalnız düzeltmede (iki adımlı) ·
// Ambalaj/Lojistik/Fiyat açılır bölümleri (fiyat Ambalaj'da değil) · yazarak ara + "+ ekle" · canlı özet · kaydet/sil yükleri (sahte uç) ·
// yazdır penceresi (siparis_yazdir.js, sahte JSON) · salt okuyucu · koyu tema · konsol hatası yok. Playwright yoksa ATLAR.
// =========================================================
'use strict';
const fs = require('fs'), os = require('os'), path = require('path');
let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }
const OUT = process.env.URUN_SIPARIS_OUT || path.join(os.tmpdir(), 'usip-test');
if (!fs.existsSync(path.join(OUT, 'manifest.json'))) { console.error('Önce: URUN_SIPARIS_OUT=' + OUT + ' php scripts/urun_siparis_render.php'); process.exit(1); }
const M = JSON.parse(fs.readFileSync(path.join(OUT, 'manifest.json'), 'utf8'));
let pass = 0, fail = 0;
function ok(ad, v, detay) { if (v) pass++; else { fail++; console.log('FAIL ' + ad + (detay ? ' :: ' + detay : '')); } }

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    // file:// sayfadaki fetch'i page.route() ile yakalamak TAM Chromium (yeni headless) ister: Playwright'ın
    // varsayılanı chromium-headless-shell file:// fetch'ini route'a uğramadan reddeder ("URL scheme file is not supported").
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : { channel: 'chromium' })
        .catch(() => chromium.launch({}));
    async function ac(sayfa, w, h, koyu) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: mobil, hasTouch: mobil, reducedMotion: 'reduce', colorScheme: koyu ? 'dark' : 'light' });
        const page = await ctx.newPage();
        const hatalar = [], istekler = [];
        page.on('console', m => { if (m.type() === 'error' && !/ERR_FILE|file:\/\/|Failed to load resource|ERR_NAME_NOT_RESOLVED|ERR_INTERNET|ERR_TUNNEL|CORS/.test(m.text())) hatalar.push(m.text()); });
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        // sahte uç (sayfa AÇILMADAN kurulmalı): GET → fixture, POST → gövde kaydedilir
        await page.route(/urun_siparis_islem\.php/, async route => {
            const req = route.request();
            if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, siparis: M.fixture }) });
            istekler.push(JSON.parse(req.postData() || '{}'));
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, id: M.ids[0], mesaj: 'Sipariş kaydedildi.' }) });
        });
        await page.route(/urun_siparis_yazdir\.php/, route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
            ok: true, kapsam: 'urun', baslik: 'Ürün Siparişleri', dosya: 'Urun-Siparisleri-x', basim: '01.01.2027 10:00', depo: 'DEPO1', filtre: '', adet: 1, gecmis_say: 0, kesildi: false, limit: 500, notlar: [],
            kolonlar: [{ k: 'tarih', l: 'Teslimat', t: 'metin', w: 50 }, { k: 'kalibre', l: 'Kalibre / Palet', t: 'metin', w: 50 }],
            satirlar: [{ h: ['01.01.2027', '9 PLT 8\n7 PLT 1'], gecmis: false, bugun: false, gun: '2027-01-01' }], toplam: {} }) }));
        if (koyu) await page.addInitScript(() => { try { localStorage.setItem('asya_tema', 'dark'); } catch (e) {} });
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        if (koyu) await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
        await page.waitForTimeout(400);
        return { ctx, page, hatalar, istekler };
    }
    const gorunur = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); return !!e && !e.hidden && e.checkVisibility && e.checkVisibility(); }, sel);
    const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

    for (const [w, h] of [[1280, 900], [390, 844]]) {
        const tag = w + 'px ';
        const { ctx, page, hatalar, istekler } = await ac('liste', w, h, false);
        ok(tag + 'yatay sayfa taşması yok', (await tasma(page)) <= 0, String(await tasma(page)));
        const masaustu = w >= 1024;
        const tiklanan = masaustu ? '.usip-satir' : '.usip-kt[data-id]';
        ok(tag + 'tablo/kart doğru görünür', masaustu ? await gorunur(page, '.usip-tablo') && !(await gorunur(page, '.usip-kartlar')) : await gorunur(page, '.usip-kartlar') && !(await gorunur(page, '.usip-tablo')));
        ok(tag + '3 sipariş satırı/kartı, "Düzenle" düğmesi yok', (await page.locator(tiklanan).count()) === 3 && (await page.locator('text="Düzenle"').count()) === 0);
        const chip = await page.locator(tiklanan + ' .usip-chip').allTextContents();
        ok(tag + 'kalibre "9 PLT 8" biçimi', chip.length >= 1 && chip.some(t => /^9 PLT 8$/.test(t.replace(/\s+/g, ' ').trim())) , JSON.stringify(chip));
        ok(tag + 'şoför adı/telefonu sayfada yok', !(await page.content()).includes('Test Sürücü'));
        ok(tag + 'üç yazdırma düğmesi', (await page.locator('[data-sip-yazdir]').count()) === 3);
        ok(tag + 'Yeni/Temizle düğmesi yok', (await page.locator('#usipTemizle, #usipYeniForm').count()) === 0 && (await page.locator('.pm-footer button:has-text("Temizle"), .pm-footer button:has-text("Yeni")').count()) === 0);

        // satıra tıkla → düzeltme penceresi
        await page.locator(tiklanan).first().click();
        await page.waitForTimeout(400);
        ok(tag + 'pencere açıldı, başlık "Siparişi Düzenle"', (await gorunur(page, '#usipOvl')) && (await page.textContent('#usipBaslik')) === 'Siparişi Düzenle');
        ok(tag + 'alanlar dolu (firma, ürün, kalibreler, şoför)', (await page.inputValue('#f_firma')) === 'Bereket Tarım A.Ş.' && (await page.inputValue('#f_urun')) === 'Nar'
            && (await page.locator('.usip-kal-satir').count()) === 2 && (await page.inputValue('#l_sofor')) === 'Test Sürücü' && (await page.inputValue('#f_fiyat')) === '28,5');
        ok(tag + 'özet: 9 palet / 450 kasa / 4.500 kg / 128.250,00 TL', (await page.textContent('#s_palet')) === '9' && (await page.textContent('#s_kasa')) === '450' && /^4\.500 kg$/.test(await page.textContent('#s_kg')) && /^128\.250,00 TL$/.test(await page.textContent('#s_tutar')),
            [await page.textContent('#s_palet'), await page.textContent('#s_kasa'), await page.textContent('#s_kg'), await page.textContent('#s_tutar')].join('|'));
        ok(tag + 'Sil görünür (düzeltme), Kaydet var, Yeni/Temizle yok', await gorunur(page, '#usipSil') && await gorunur(page, '#usipKaydet') && (await page.locator('#usipFoot button').allTextContents()).join('|').match(/^Kaydet\|Sil\|Kapat$/) !== null,
            (await page.locator('#usipFoot button').allTextContents()).join('|'));
        // bölüm sırası: Ambalaj → Lojistik → Fiyat; fiyat Ambalaj'da DEĞİL
        const sira = await page.evaluate(() => [...document.querySelectorAll('#usipOvl details.usip-acc')].map(d => d.id));
        ok(tag + 'bölümler Ambalaj, Lojistik, Fiyat sırasında', sira.join(',') === 'accAmb,accLoj,accFiyat', sira.join(','));
        ok(tag + 'birim fiyat Fiyat bölümünde, Ambalaj\'da değil', await page.evaluate(() => !!document.querySelector('#accFiyat #f_fiyat') && !document.querySelector('#accAmb #f_fiyat')));
        // v428 (sahip kararı): açılır bölümler HEP KAPALI başlar (assets/usip.js)
        ok(tag + 'varsayılan: Ambalaj, Lojistik, Fiyat kapalı', await page.evaluate(() => !document.getElementById('accAmb').open && !document.getElementById('accLoj').open && !document.getElementById('accFiyat').open));
        // pm-dialog düzen kuralı: alt çubuk ekranda, gövde kayar, en alttaki alan tıklanır
        const duzen = await page.evaluate(() => {
            const d = document.querySelector('.usip-dialog').getBoundingClientRect(), f = document.querySelector('#usipFoot').getBoundingClientRect(), b = document.querySelector('.usip-dialog .pm-body');
            return { fAlt: f.bottom <= innerHeight + 1, dAlt: d.bottom <= innerHeight + 1, kayar: b.scrollHeight > b.clientHeight || b.clientHeight > 0, yatay: b.scrollWidth - b.clientWidth };
        });
        ok(tag + 'pencere/alt çubuk ekran içinde, gövde yatay taşmaz', duzen.fAlt && duzen.dAlt && duzen.yatay <= 1, JSON.stringify(duzen));
        await page.evaluate(() => { document.getElementById('accLoj').open = true; });
        await page.locator('#l_navlun').scrollIntoViewIfNeeded();
        const tik = await page.evaluate(() => { const e = document.getElementById('l_navlun'), r = e.getBoundingClientRect(), x = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return x === e; });
        ok(tag + 'Lojistik açılınca en alttaki alan görünür+tıklanır', tik);
        // canlı özet + kalibre ekle/çıkar (Ambalaj/Fiyat kapalı başlar — alanları doldurmak için aç)
        await page.evaluate(() => { document.getElementById('accAmb').open = true; document.getElementById('accFiyat').open = true; });
        await page.fill('#f_pkasa', '60');
        ok(tag + 'palet kasa değişince özet güncellenir (540 kasa)', (await page.textContent('#s_kasa')) === '540');
        await page.click('#usipKalEkle');
        ok(tag + '+ Kalibre Ekle yeni satır açar', (await page.locator('.usip-kal-satir').count()) === 3);
        await page.locator('.usip-kal-satir').nth(2).locator('.k-no').fill('10');
        await page.locator('.usip-kal-satir').nth(2).locator('.k-palet').fill('2');
        ok(tag + 'yeni kalibrenin paleti toplama katılır (11 palet)', (await page.textContent('#s_palet')) === '11');
        await page.locator('.usip-kal-satir').nth(2).locator('.rm').click();
        ok(tag + 'kalibre satırı silinir', (await page.locator('.usip-kal-satir').count()) === 2 && (await page.textContent('#s_palet')) === '9');
        // yazarak ara + ekle (firma)
        await page.click('#f_firma'); await page.fill('#f_firma', 'Ber');
        const secenek = await page.locator('#f_firma ~ .usip-list .usip-opt').allTextContents();
        ok(tag + 'firma: yazınca kayıtlı firma önerilir', secenek.some(t => t.includes('Bereket Tarım A.Ş.')), JSON.stringify(secenek));
        await page.fill('#f_firma', 'Yepyeni Firma Ltd.');
        const ekle = await page.locator('#f_firma ~ .usip-list .usip-opt-ekle').allTextContents();
        ok(tag + 'firma: listede yoksa "yeni firma olarak kaydet" satırı', ekle.length === 1 && ekle[0].includes('yeni firma olarak kaydet'), JSON.stringify(ekle));
        await page.locator('#f_firma ~ .usip-list .usip-opt-ekle').dispatchEvent('mousedown');
        ok(tag + 'firma: tam ad kalır, liste kapanır', (await page.inputValue('#f_firma')) === 'Yepyeni Firma Ltd.' && !(await gorunur(page, '#f_firma ~ .usip-list')));
        await page.fill('#f_urun', 'nar');
        const urunSec = await page.locator('#f_urun ~ .usip-list .usip-opt').allTextContents();
        ok(tag + 'ürün: HKS etiketli öneri', urunSec.some(t => /Nar/.test(t)) , JSON.stringify(urunSec));
        await page.fill('#f_urun', 'Nar');
        await page.fill('#f_pcins', '100x');
        ok(tag + 'palet cinsi: Tanımlar listesinden gelir', (await page.locator('#f_pcins ~ .usip-list .usip-opt').allTextContents()).some(t => t.includes('100x120 EPAL Palet')));
        await page.keyboard.press('Escape');
        ok(tag + 'Esc önce öneri listesini kapatır, pencere açık kalır', await gorunur(page, '#usipOvl'));
        // geçersiz alan → hata, uç çağrılmaz (v430: birim ağırlık boş bırakılabilir; doluysa > 0 olmalı)
        await page.fill('#f_birim', '0');
        await page.click('#usipKaydet');
        ok(tag + 'eksik alanla kaydet: hata mesajı, istek gitmez', (await gorunur(page, '#usipHata')) && istekler.length === 0, JSON.stringify(istekler));
        await page.fill('#f_birim', '10,5');
        await page.fill('#f_fiyat', '');
        await page.click('#usipKaydet');
        await page.waitForTimeout(500);
        ok(tag + 'kaydet: tek POST, düzeltme id\'si + hesap girdileri gider', istekler.length === 1 && istekler[0].islem === 'kaydet' && istekler[0].id === M.ids[0] && istekler[0].csrf === 'x'
            && istekler[0].firma === 'Yepyeni Firma Ltd.' && istekler[0].kalibreler.length === 2 && istekler[0].palet_kasa === '60' && istekler[0].birim_fiyat === '', JSON.stringify(istekler[0] || null).slice(0, 300));
        ok(tag + 'konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }

    // ── silme akışı + yeni sipariş + salt okuyucu + yazdırma + koyu tema ──
    {
        const { ctx, page, hatalar, istekler } = await ac('liste', 1280, 900, false);
        await page.locator('.usip-satir').first().click(); await page.waitForTimeout(300);
        await page.click('#usipSil');
        ok('sil: onay çubuğu açılır, normal çubuk gizlenir', (await gorunur(page, '#usipSilOnay')) && !(await gorunur(page, '#usipFoot')));
        await page.click('#usipSilVazgec');
        ok('sil: Vazgeç onayı kapatır, istek gitmedi', (await gorunur(page, '#usipFoot')) && istekler.length === 0);
        await page.click('#usipSil'); await page.click('#usipSilEvet'); await page.waitForTimeout(500);
        ok('sil: Evet → POST {islem:sil,id}', istekler.length === 1 && istekler[0].islem === 'sil' && istekler[0].id === M.ids[0], JSON.stringify(istekler));
        await ctx.close();
    }
    {
        const { ctx, page, hatalar } = await ac('liste', 1280, 900, false);
        await page.click('#usipYeni'); await page.waitForTimeout(300);
        ok('yeni: başlık "Yeni Sipariş", Sil gizli, boş form tek kalibre satırı', (await page.textContent('#usipBaslik')) === 'Yeni Sipariş' && !(await gorunur(page, '#usipSil')) && (await page.locator('.usip-kal-satir').count()) === 1 && (await page.inputValue('#f_firma')) === '');
        ok('yeni: tek kalibre satırının kaldır düğmesi pasif', await page.locator('.usip-kal-satir .rm').isDisabled());
        ok('yeni: pencere kenarı dışına yatay taşma yok', (await tasma(page)) <= 0);
        await page.click('#usipX'); await page.waitForTimeout(100);
        ok('✕ pencereyi kapatır', !(await gorunur(page, '#usipOvl')));
        // yazdırma penceresi
        const [pop] = await Promise.all([page.waitForEvent('popup', { timeout: 5000 }).catch(() => null), page.click('[data-sip-yazdir="yazdir"]')]);
        if (pop) { await pop.waitForLoadState().catch(() => {}); await pop.waitForTimeout(500); const t = await pop.content(); ok('yazdır: pencerede rapor, "9 PLT 8" ve başlık', t.includes('Ürün Siparişleri') && t.includes('9 PLT 8'), t.slice(0, 200)); await pop.close(); }
        else ok('yazdır: pencere açıldı', false, 'popup yok');
        ok('konsol hatası yok (yeni/yazdır)', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    {
        const { ctx, page, hatalar } = await ac('liste_viewer', 1280, 900, false);
        ok('salt okuyucu: Yeni Sipariş düğmesi yok', (await page.locator('#usipYeni').count()) === 0);
        await page.locator('.usip-satir').first().click(); await page.waitForTimeout(300);
        ok('salt okuyucu: pencere açılır ama alanlar kilitli, Kaydet/Sil yok', (await gorunur(page, '#usipOvl')) && await page.locator('#usipAlanlar').evaluate(e => e.disabled) && !(await gorunur(page, '#usipKaydet')) && !(await gorunur(page, '#usipSil')));
        ok('salt okuyucu: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    {
        const { ctx, page } = await ac('liste_bos', 1280, 900, false);
        ok('boş arama: mesaj var, yazdırma düğmesi yok, taşma yok', (await page.textContent('.usip-bos-satir')).includes('Aramaya uyan sipariş yok') && (await page.locator('[data-sip-yazdir]').count()) === 0 && (await tasma(page)) <= 0);
        await ctx.close();
    }
    {
        const { ctx, page, hatalar } = await ac('liste', 1280, 900, true);
        const zemin = await page.evaluate(() => getComputedStyle(document.querySelector('.usip-kart')).backgroundColor);
        await page.locator('.usip-satir').first().click(); await page.waitForTimeout(400);
        const pencere = await page.evaluate(() => getComputedStyle(document.querySelector('.usip-dialog')).backgroundColor);
        const metin = await page.evaluate(() => getComputedStyle(document.querySelector('.usip-f label')).color);
        ok('koyu tema: kart ve pencere açık zeminde kalmaz', zemin !== 'rgb(255, 255, 255)' && pencere !== 'rgb(255, 255, 255)' && metin !== 'rgb(0, 0, 0)', [zemin, pencere, metin].join(' / '));
        ok('koyu tema: konsol hatası yok', hatalar.length === 0, hatalar.join(' | '));
        await ctx.close();
    }
    await browser.close();
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
