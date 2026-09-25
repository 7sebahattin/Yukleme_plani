/* =========================================================
   scripts/suggest_list_smoke.js — öneri kutusu (data-suggest / .ms-sug-list)
   tarayıcı testi

   Gerçek assets/style.css + assets/app.js ile yükleme formundaki serbest
   metin öneri kutusunu (Ulaşım, Plaka, Gümrük, Ürün Cinsi…) çalıştırır:
     - odaklanınca/yazınca JS hatası YOK,
     - liste girdiyi örtmez,
     - mobilde alt çubuğun (--bn-h) arkasına düşmez, masaüstünde ekran
       dışına taşmaz.

   Neden var: app.js dört ayrı IIFE'den oluşur. positionList() alt çubuk
   yardımcısını (ekranAlti) çağırıyordu ama yardımcı yalnız İLK IIFE'de
   tanımlıydı → her odaklanmada ReferenceError, liste yanlış yerde. Ne
   bottomnav_smoke ne beyan_js_smoke bu kutuyu açıyordu.

   Çalıştır:  node scripts/suggest_list_smoke.js   (Playwright yoksa ATLAR)
   ========================================================= */
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');

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

const KOK = path.resolve(__dirname, '..');
const url = (p) => 'file://' + path.join(KOK, p);

// Girdi sayfanın içinde verilen dikey konumda durur; body.bn-yok masaüstü
// davranışını (--bn-h: 0) taklit etmek için kullanılır.
function sayfa(girdiTop, bnYok) {
    return `<!doctype html><html lang="tr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="${url('assets/style.css')}">
<style>.test-alan{position:absolute;left:16px;right:16px;top:${girdiTop}px}</style>
</head><body${bnYok ? ' class="bn-yok"' : ''}>
<script type="application/json" id="suggestData">{"urun":["Domates","Biber","Patlıcan","Salatalık","Kabak","Soğan","Havuç","Marul"]}</script>
<form><div class="test-alan"><input type="text" id="g" data-suggest="urun" data-suggest-label="Ürün"></div></form>
<script src="${url('assets/app.js')}"></script>
</body></html>`;
}

let gecen = 0, hata = 0;
function ok(ad, kosul, ayrinti) {
    if (kosul) { gecen++; console.log(`${ad.padEnd(78)} OK`); }
    else { hata++; console.log(`${ad.padEnd(78)} *** HATA\n    → ${ayrinti}`); }
}

(async () => {
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
                        '/opt/pw-browsers/chromium/chrome-linux/chrome']
                       .find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : {});
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'sug-'));

    const senaryolar = [
        // [ad, genişlik, yükseklik, girdi top, bn-yok?, beklenen yön]
        ['mobil 390 — girdi üstte (liste aşağı açılır)', 390, 780, 120, false, 'asagi'],
        ['mobil 390 — girdi altta (liste yukarı, çubuğa düşmez)', 390, 780, 600, false, 'yukari'],
        ['mobil 360 — girdi çubuğun hemen üstünde', 360, 640, 520, false, 'yukari'],
        ['masaüstü 1280 — girdi altta (--bn-h 0)', 1280, 800, 700, true, 'yukari'],
    ];

    for (const [ad, w, h, top, bnYok, yon] of senaryolar) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h } });
        const p = await ctx.newPage();
        const hatalar = [];
        p.on('pageerror', e => hatalar.push(e.message));
        p.on('console', m => { if (m.type() === 'error') hatalar.push(m.text()); });
        const dosya = path.join(tmp, `s_${w}_${top}.html`);
        fs.writeFileSync(dosya, sayfa(top, bnYok));
        await p.goto('file://' + dosya);
        await p.focus('#g');
        await p.keyboard.type('a');
        await p.waitForTimeout(250);
        const r = await p.evaluate(() => {
            const g = document.getElementById('g').getBoundingClientRect();
            const l = document.querySelector('.ms-sug-list');
            const lr = l ? l.getBoundingClientRect() : null;
            const bn = parseFloat(getComputedStyle(document.body).getPropertyValue('--bn-h')) || 0;
            return {
                var: !!l, gorunur: !!l && !l.hidden && lr.height > 0,
                gTop: g.top, gBottom: g.bottom,
                lTop: lr && lr.top, lBottom: lr && lr.bottom,
                alt: innerHeight - bn, bn,
            };
        });
        ok(`${ad}: JS hatası yok`, hatalar.length === 0, hatalar.join(' | '));
        ok(`${ad}: liste görünür`, r.gorunur, JSON.stringify(r));
        if (!r.gorunur) { await ctx.close(); continue; }
        const ortusme = r.lBottom > r.gTop + 1 && r.lTop < r.gBottom - 1;
        ok(`${ad}: liste girdiyi örtmüyor`, !ortusme, JSON.stringify(r));
        ok(`${ad}: liste ${yon === 'asagi' ? 'girdinin altında' : 'girdinin üstünde'}`,
           yon === 'asagi' ? r.lTop >= r.gBottom - 1 : r.lBottom <= r.gTop + 1, JSON.stringify(r));
        ok(`${ad}: liste çubuğun/ekranın altına düşmüyor (alt ≤ ${Math.round(r.alt)})`,
           r.lBottom <= r.alt + 1, JSON.stringify(r));
        ok(`${ad}: liste ekranın üstünden taşmıyor`, r.lTop >= -1, JSON.stringify(r));
        await ctx.close();
    }

    await browser.close();
    fs.rmSync(tmp, { recursive: true, force: true });
    console.log(`\n${gecen} OK, ${hata} HATA`);
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
