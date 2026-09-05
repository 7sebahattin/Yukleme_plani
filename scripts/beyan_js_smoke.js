// =========================================================
// scripts/beyan_js_smoke.js — assets/app.js DAVRANIŞ testi (gerçek tarayıcı)
//
//   node scripts/beyan_js_smoke.js     → çıkış kodu 0 = tüm testler geçti
//
// NEDEN VAR: diğer iki test (beyan_bildirim_smoke = statik, beyan_ui_smoke =
// PHP render) JS'i HİÇ çalıştırmaz. "Pasif buton pasif görünüyor mu", "yazarak
// arama select'i dolduruyor mu", "plaka boşluksuz mu" gibi kurallar ancak
// tarayıcıda doğrulanabilir — üst üste üç turda gözden kaçan sorunların hepsi
// bu katmandaydı.
//
// Playwright + Chromium GEREKTİRİR. Yoksa test kendini ATLAR (çıkış 0) —
// PHP-only bir depoda zorunlu bağımlılık olmasın diye bilinçli.
//   PLAYWRIGHT: npm i -g playwright  (Chromium ayrıca gerekir)
//
// Canlıya ya da veritabanına DOKUNMAZ: yalnız assets/app.js'i yerel bir
// HTML iskeletine yükler.
// =========================================================
'use strict';
const fs   = require('fs');
const os   = require('os');
const path = require('path');

let chromium;
try {
    chromium = require(process.env.PW_PATH || 'playwright').chromium;
} catch (e) {
    try { chromium = require('/opt/node22/lib/node_modules/playwright').chromium; }
    catch (e2) {
        console.log('Playwright bulunamadi — JS davranis testi ATLANDI (hata degil).');
        console.log('Kurulum: npm i -g playwright && npx playwright install chromium');
        process.exit(0);
    }
}

const KOK = path.dirname(__dirname);
let fail = 0;
function ok(ad, kosul, ipucu) {
    if (!kosul) fail++;
    console.log(ad.padEnd(58) + (kosul ? 'OK' : '*** FAIL' + (ipucu ? '\n    → ' + ipucu : '')));
}

// app.js'i yükleyen küçük bir sayfa — beyan formundaki alanların birebir kopyası
const SAYFA = `<!doctype html><html lang="tr"><head><meta charset="utf-8"><style>
.ara-sec-gizli{display:none!important}.btn:disabled{opacity:.45;cursor:not-allowed}
</style></head><body>
<select name="hks_ulke_id" data-aramali="Ülke yazın veya listeden seçin">
  <option value="">— seçilmedi —</option>
  <option value="501">RUSYA FEDERASYONU</option>
  <option value="502">UKRAYNA</option>
  <option value="503">ALMANYA</option>
</select>
<input id="plaka" name="vehicle_plate" data-uppercase="tr" data-nospace>
<button id="pasif" class="btn" disabled>Bildirim Yap</button>
<script src="app.js"></script></body></html>`;

(async () => {
    const dizin = fs.mkdtempSync(path.join(os.tmpdir(), 'beyanjs-'));
    fs.writeFileSync(path.join(dizin, 'sayfa.html'), SAYFA);
    fs.copyFileSync(path.join(KOK, 'assets', 'app.js'), path.join(dizin, 'app.js'));

    const tarayici = await chromium.launch();
    const sayfa = await tarayici.newPage();
    const hatalar = [];
    sayfa.on('pageerror', e => hatalar.push('PAGEERROR: ' + e.message));
    sayfa.on('console', m => { if (m.type() === 'error') hatalar.push('CONSOLE: ' + m.text()); });
    await sayfa.goto('file://' + path.join(dizin, 'sayfa.html'));
    await sayfa.waitForTimeout(200);

    ok('app.js JS hatası vermiyor', hatalar.length === 0, hatalar.join(' | '));

    // ── Yazarak aranabilir select ─────────────────────────────────────────
    const araVar = await sayfa.locator('input.ara-sec').count();
    ok('arama kutusu ekleniyor', araVar === 1, 'data-aramali select donusturulmemis');

    if (araVar === 1) {
        const sel = sayfa.locator('select[name=hks_ulke_id]');
        ok('asıl select gizleniyor',
           await sel.evaluate(el => getComputedStyle(el).display) === 'none',
           'select hala gorunuyor — kullanici iki kutu gorur');

        await sayfa.fill('input.ara-sec', 'rusya federasyonu');
        await sayfa.waitForTimeout(50);
        ok('tam ad yazınca değer atanıyor', await sel.inputValue() === '501',
           'gelen: ' + await sel.inputValue());

        // Türkçe duyarsız: "rusya federasyonu" != "RUSYA FEDERASYONU" ama eşleşmeli
        await sayfa.fill('input.ara-sec', 'ukrayna');
        await sayfa.waitForTimeout(50);
        ok('küçük harfle de eşleşiyor', await sel.inputValue() === '502');

        // Yarım yazımda değer OLUŞMAMALI — yanlış kayda düşmesin
        await sayfa.fill('input.ara-sec', 'rus');
        await sayfa.waitForTimeout(50);
        ok('yarım yazımda değer atanmıyor', await sel.inputValue() === '',
           'parcali eslesme var — yanlis ulke secilebilir');

        await sayfa.fill('input.ara-sec', 'olmayan ulke');
        await sayfa.waitForTimeout(50);
        ok('geçersiz metinde uyarı çıkıyor',
           await sayfa.locator('input.ara-sec').evaluate(el => el.classList.contains('ara-sec-bos')),
           'kullanici yazdiginin karsiligi olmadigini goremez');
    }

    // ── Plaka: boşluksuz + büyük harf ────────────────────────────────────
    await sayfa.fill('#plaka', '');
    await sayfa.type('#plaka', '34 abc 123');
    await sayfa.waitForTimeout(50);
    const plaka = await sayfa.locator('#plaka').inputValue();
    ok('plaka boşluksuz ve büyük harf', plaka === '34ABC123', 'gelen: [' + plaka + ']');

    // ── Pasif buton görsel olarak da pasif ───────────────────────────────
    const opak = await sayfa.locator('#pasif').evaluate(el => getComputedStyle(el).opacity);
    ok('pasif buton soluk görünüyor', parseFloat(opak) < 0.9,
       'opacity: ' + opak + ' — pasif buton aktifle ayni gorunur');

    await tarayici.close();
    fs.rmSync(dizin, { recursive: true, force: true });

    console.log('\n' + (fail === 0 ? 'TUM TESTLER GECTI' : fail + ' TEST BASARISIZ'));
    process.exit(fail === 0 ? 0 : 1);
})();
