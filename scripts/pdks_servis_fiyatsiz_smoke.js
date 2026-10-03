// =========================================================
// scripts/pdks_servis_fiyatsiz_smoke.js — v300: servis fiyatı tanımlı DEĞİLKEN
// "🚌 Servis Ücreti" penceresi GİRİŞE AÇIK olmalı (sahip kararı: servis kalkıyor,
// fiyat sonradan girilir). GERÇEK TARAYICI testi.
//
//   PUANTAJ_SERVIS=nofiyat php scripts/pdks_puantaj_dialog_render.php > _test_servis_fiyatsiz.html
//   node scripts/pdks_servis_fiyatsiz_smoke.js
//
// Kapsam: kırmızı uyarı (.sv-uyari) YOK, mavi bilgi kutusu (.sv-bilgi-kutu) var ve
// "geçerlilik başlangıcı" yönlendirmesini içerir; sayaçlar açık (+/− disabled DEĞİL,
// girdi readonly DEĞİL, sv-kapali yok); + ile adet artar, Servisi Ekle açılır, özet
// "Toplam N servis" der (tutar YOK); yatay taşma yok. Playwright yoksa ATLAR.
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

const SAYFA = path.join(path.dirname(__dirname), '_test_servis_fiyatsiz.html');
if (!fs.existsSync(SAYFA)) {
    console.error('_test_servis_fiyatsiz.html yok. Önce: PUANTAJ_SERVIS=nofiyat php scripts/pdks_puantaj_dialog_render.php > _test_servis_fiyatsiz.html');
    process.exit(1);
}

let hata = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) hata++;
    console.log(`${ad.padEnd(76)} ${kosul ? 'OK' : '*** HATA'}${kosul ? '' : '\n    → ' + (ipucu || '')}`);
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    for (const ekran of [{ ad: 'MASAÜSTÜ', width: 1280, height: 900 }, { ad: 'MOBİL', width: 390, height: 844 }]) {
        console.log(`\n=== ${ekran.ad} (${ekran.width}×${ekran.height}) ===`);
        const page = await browser.newPage({ viewport: { width: ekran.width, height: ekran.height } });
        const jsHata = [];
        page.on('pageerror', e => jsHata.push(e.message));
        await page.goto('file://' + SAYFA);
        await page.waitForTimeout(400);

        const dugme = page.locator('button', { hasText: '🚌 Servis Ücreti' });
        ok('"🚌 Servis Ücreti" düğmesi fiyat YOKKEN de görünür', await dugme.count() === 1);
        await dugme.click();
        await page.waitForTimeout(400);

        const d = await page.evaluate(() => {
            const dlg = document.getElementById('servis');
            const bilgi = dlg.querySelector('.sv-bilgi-kutu');
            const sayaclar = [...dlg.querySelectorAll('.sv-sayac')].map(s => ({
                kapali: s.classList.contains('sv-kapali'),
                eksiOff: s.querySelector('.sv-eksi').disabled,
                artiOff: s.querySelector('.sv-arti').disabled,
                readonly: s.querySelector('input').readOnly,
                fiyat: s.querySelector('.sv-fiyat').textContent.trim(),
            }));
            return {
                acik: dlg.open,
                uyari: !!dlg.querySelector('.sv-uyari'),
                bilgi: bilgi ? bilgi.textContent : null,
                sayaclar,
                kaydetKapali: dlg.querySelector('#svKaydet').disabled,
                tasma: document.documentElement.scrollWidth > window.innerWidth + 1,
            };
        });
        ok('pencere açıldı', d.acik);
        ok('KIRMIZI engel uyarısı (.sv-uyari) YOK', d.uyari === false);
        ok('mavi bilgi kutusu var: "yine de kaydedilir"', !!d.bilgi && /yine de kaydedilir/.test(d.bilgi), String(d.bilgi));
        ok('bilgi kutusu geçerlilik başlangıcı yönlendirmesi içerir', !!d.bilgi && /geçerlilik başlangıcını/.test(d.bilgi), String(d.bilgi));
        ok('iki sayaç da AÇIK (kapalı/disabled/readonly değil)', d.sayaclar.length === 2 && d.sayaclar.every(s => !s.kapali && !s.eksiOff && !s.artiOff && !s.readonly), JSON.stringify(d.sayaclar));
        ok('fiyat etiketi "fiyat henüz yok"', d.sayaclar.every(s => /fiyat henüz yok/.test(s.fiyat)), JSON.stringify(d.sayaclar));
        ok('adet 0 iken "Servisi Ekle" kapalı', d.kaydetKapali === true);

        await page.locator('.sv-sayac[data-sv-tur="BUYUK"] .sv-arti').click();
        await page.locator('.sv-sayac[data-sv-tur="BUYUK"] .sv-arti').click();
        await page.locator('.sv-sayac[data-sv-tur="KUCUK"] .sv-arti').click();
        const son = await page.evaluate(() => ({
            ozet: document.getElementById('svOzet').textContent,
            kaydetKapali: document.getElementById('svKaydet').disabled,
            buyuk: document.querySelector('input[name="buyuk"]').value,
            kucuk: document.querySelector('input[name="kucuk"]').value,
        }));
        ok('+ düğmeleri adedi artırır (2 Büyük, 1 Küçük)', son.buyuk === '2' && son.kucuk === '1', JSON.stringify(son));
        ok('özet "Toplam 3 servis" der ve TUTAR göstermez', /Toplam 3 servis/.test(son.ozet) && !/TRY|₺|\d,\d\d/.test(son.ozet), son.ozet);
        ok('adet ≥ 1 olunca "Servisi Ekle" AÇILIR', son.kaydetKapali === false);
        ok('yatay taşma yok', d.tasma === false);
        ok('sayfa JS hatası yok', jsHata.length === 0, jsHata.join(' | '));
        await page.close();
    }
    await browser.close();
    console.log(hata === 0 ? '\nSONUÇ: TÜMÜ GEÇTİ' : `\nSONUÇ: ${hata} HATA`);
    process.exit(hata === 0 ? 0 : 1);
})();
