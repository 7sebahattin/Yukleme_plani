// =========================================================
// scripts/pdks_servis_dialog_smoke.js — Mesai Detayı "🚌 Servis Ücreti"
// penceresi + Servisler listesi GERÇEK TARAYICI testi (v299).
//
//   PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php > _test_servis_dialog.html
//   node scripts/pdks_servis_dialog_smoke.js
//
// Masaüstü / tablet / mobil (+ kısa mobil): açılışta pencere gizli, düğme
// açar, footer ekranda, gövde (kısa ekranda) gerçekten kayar, sayaçlar
// çalışır (+/−, alt sınır 0), Kaydet yalnız adet ≥1'de açık, toplam tutar
// doğru, yatay taşma yok, mobilde girdiler ≥16px, liste + iptal soluk.
// Ölçümden önce 400 ms beklenir (açılış animasyonu). Playwright yoksa ATLAR.
// =========================================================
'use strict';
const fs = require('fs');
const path = require('path');

let chromium = null;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (e) { /* sonrakini dene */ }
}
if (!chromium) { console.log('Playwright bulunamadı — tarayıcı testi ATLANDI (hata değil).'); process.exit(0); }

const ROOT = path.dirname(__dirname);
const SAYFA = path.join(ROOT, '_test_servis_dialog.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_servis_dialog.html yok. Önce: PUANTAJ_SERVIS=1 php scripts/pdks_puantaj_dialog_render.php > _test_servis_dialog.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(72)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});

    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 },
                         { ad: 'TABLET', width: 820, height: 1024 },
                         { ad: 'MOBİL', width: 390, height: 844 },
                         { ad: 'KISA MOBİL', width: 360, height: 440, kisa: true }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const jsHata = [];
        page.on('pageerror', e => jsHata.push(e.message));
        await page.goto('file://' + SAYFA);
        await page.waitForTimeout(400);

        const kapali = await page.evaluate(() => {
            const d = document.getElementById('servis');
            if (!d) return null;
            const r = d.getBoundingClientRect();
            return { acik: d.open, gorunur: getComputedStyle(d).display !== 'none' && r.height > 0 };
        });
        ok('dialog#servis var ve açılışta GİZLİ', kapali && !kapali.acik && !kapali.gorunur, JSON.stringify(kapali));

        const dugme = page.locator('button', { hasText: '🚌 Servis Ücreti' });
        ok('"🚌 Servis Ücreti" düğmesi Kart Hareketleri başlığında', await dugme.count() === 1);
        const ayniSatir = await page.evaluate(() => {
            const b = [...document.querySelectorAll('button')].find(x => x.textContent.includes('🚌 Servis Ücreti'));
            const t = [...document.querySelectorAll('button')].find(x => x.textContent.includes('👥 Toplu İşlem'));
            return !!(b && t && b.parentElement === t.parentElement);
        });
        ok('düğme Çalışma Ekle / Toplu İşlem ile AYNI satırda', ayniSatir);

        await dugme.click();
        await page.waitForTimeout(400);
        const olcum = await page.evaluate(() => {
            const d = document.getElementById('servis');
            const r = d.getBoundingClientRect();
            const foot = d.querySelector('.sv-foot').getBoundingClientRect();
            const body = d.querySelector('.sv-body');
            return { acik: d.open, dTop: r.top, dBottom: r.bottom, dLeft: r.left, dRight: r.right,
                     footBottom: foot.bottom, footTop: foot.top, vh: innerHeight, vw: innerWidth,
                     scrollH: body.scrollHeight, clientH: body.clientHeight,
                     tasma: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                     icTasma: d.scrollWidth - d.clientWidth,
                     acikSayisi: document.querySelectorAll('dialog[open]').length };
        });
        ok('pencere açıldı, tek açık dialog', olcum.acik && olcum.acikSayisi === 1, JSON.stringify(olcum));
        ok('pencere ekranın içinde (yatayda)', olcum.dLeft >= 0 && olcum.dRight <= olcum.vw + 0.5, JSON.stringify(olcum));
        ok('footer (Ekle/Vazgeç) ekranın içinde', olcum.footBottom <= olcum.vh + 0.5 && olcum.footTop >= 0, JSON.stringify(olcum));
        ok('sayfada yatay taşma yok', olcum.tasma <= 0, String(olcum.tasma));
        ok('pencere içinde yatay taşma yok', olcum.icTasma <= 0, String(olcum.icTasma));

        if (ekran.kisa) {
            const kaydi = await page.evaluate(() => {
                const b = document.querySelector('#servis .sv-body');
                b.scrollTop = 0; b.scrollTop = b.scrollHeight;
                return { tasiyor: b.scrollHeight > b.clientHeight + 1, top: b.scrollTop };
            });
            ok('kısa ekranda gövde GERÇEKTEN kayar', kaydi.tasiyor && kaydi.top > 0, JSON.stringify(kaydi));
            const notGorunur = await page.evaluate(() => {
                const t = document.querySelector('#servis textarea[name="note"]');
                const r = t.getBoundingClientRect();
                const el = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(10, r.height / 2));
                return el === t;
            });
            ok('kaydırınca en alttaki Not kutusu görünür ve tıklanabilir', notGorunur);
            await page.evaluate(() => { document.querySelector('#servis .sv-body').scrollTop = 0; });
        }

        const btn = page.locator('#svKaydet');
        ok('başlangıçta Ekle PASİF (adet 0)', await btn.isDisabled());
        const buyuk = page.locator('.sv-sayac[data-sv-tur="BUYUK"]');
        const kucuk = page.locator('.sv-sayac[data-sv-tur="KUCUK"]');
        await buyuk.locator('.sv-eksi').click();
        ok('− sıfırın altına inmez', await page.inputValue('#svBuyuk') === '0');
        await buyuk.locator('.sv-arti').click();
        await buyuk.locator('.sv-arti').click();
        await kucuk.locator('.sv-arti').click();
        ok('+ sayacı artırır (Büyük 2, Küçük 1)', await page.inputValue('#svBuyuk') === '2' && await page.inputValue('#svKucuk') === '1');
        ok('adet ≥1 olunca Ekle AKTİF', !(await btn.isDisabled()));
        const ozet = await page.locator('#svOzet').textContent();
        ok('özet: 3 servis · 3.800,00 TRY (2×1500 + 1×800)', /Toplam 3 servis/.test(ozet) && /3\.800,00 TRY/.test(ozet), ozet);
        await buyuk.locator('.sv-eksi').click();
        await buyuk.locator('.sv-eksi').click();
        await kucuk.locator('.sv-eksi').click();
        ok('hepsi 0 olunca Ekle yine PASİF', await btn.isDisabled());

        const form = await page.evaluate(() => {
            const f = document.getElementById('servisForm');
            return { method: f.method, action: f.querySelector('[name=action]').value, csrf: !!f.querySelector('[name=csrf]'),
                     istek: /^[a-f0-9]{32}$/.test(f.querySelector('[name=istek_id]').value),
                     adlar: ['buyuk', 'kucuk', 'note'].every(n => !!f.querySelector(`[name="${n}"]`)) };
        });
        ok('form POST, action=servis_ekle, CSRF + istek_id + alanlar', form.method === 'post' && form.action === 'servis_ekle' && form.csrf && form.istek && form.adlar, JSON.stringify(form));

        if (ekran.width < 768) {
            const fontlar = await page.evaluate(() => [...document.querySelectorAll('#servis input:not([type=hidden]), #servis textarea')].map(e => parseFloat(getComputedStyle(e).fontSize)));
            ok('mobilde girdiler ≥16px (iOS zoom yok)', fontlar.length >= 3 && fontlar.every(f => f >= 16), JSON.stringify(fontlar));
        }
        const dokunma = await page.evaluate(() => [...document.querySelectorAll('#servis .sv-kontrol .btn')].map(b => b.getBoundingClientRect().height));
        ok('+/− düğmeleri ≥44px', dokunma.length === 4 && dokunma.every(h => h >= 43.5), JSON.stringify(dokunma));

        await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        ok('Esc pencereyi kapatır', !(await page.evaluate(() => document.getElementById('servis').open)));

        // Servisler listesi (görünür olan kopya: masaüstünde tablo, mobilde kartlar)
        const liste = await page.evaluate(() => {
            const h = document.getElementById('servisler');
            const gorunur = [...document.querySelectorAll('.sv-liste, .pdks-cards')].filter(e => e.closest('.table-wrap, .pdks-cards') && e.getBoundingClientRect().height > 0);
            const iptal = [...document.querySelectorAll('.sv-iptal')].filter(e => e.getBoundingClientRect().height > 0);
            const iptalBtn = [...document.querySelectorAll('[data-sv-iptal]')].filter(e => e.getBoundingClientRect().height > 0);
            return { baslik: h ? h.textContent : '', gorunur: gorunur.length, iptal: iptal.length,
                     opak: iptal.length ? parseFloat(getComputedStyle(iptal[0]).opacity) : 1, iptalBtn: iptalBtn.length };
        });
        ok('Servisler başlığı + özet (2 Büyük, 1 Küçük — iptal hariç)', /Servisler/.test(liste.baslik) && /2 Büyük, 1 Küçük/.test(liste.baslik), liste.baslik);
        ok('iptal edilen kayıt soluk görünür', liste.iptal >= 1 && liste.opak < 1, JSON.stringify(liste));
        ok('aktif kayıtlarda İptal düğmesi var (2)', liste.iptalBtn === 2, JSON.stringify(liste));

        await page.locator('[data-sv-iptal]:visible').first().click();
        await page.waitForTimeout(400);
        const ip = await page.evaluate(() => {
            const d = document.getElementById('servisIptal');
            return { acik: d.open, id: document.getElementById('svIptalId').value, et: document.getElementById('svIptalEtiket').textContent,
                     zorunlu: d.querySelector('textarea[name=reason]').required, n: document.querySelectorAll('dialog[open]').length };
        });
        ok('İptal penceresi açılır, kayıt id + etiket dolu, gerekçe zorunlu', ip.acik && /^\d+$/.test(ip.id) && ip.et !== '' && ip.zorunlu && ip.n === 1, JSON.stringify(ip));

        ok('JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close();
    console.log(`\nSONUÇ: ${hata === 0 ? 'TÜMÜ GEÇTİ' : hata + ' HATA'}`);
    process.exit(hata === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });
