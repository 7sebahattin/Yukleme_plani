// scripts/beyan_hks_urun_e2e.js — HKS Ürün Adı UÇTAN UCA (v349)
// Gerçek beyan_edit.php + api_beyan_urun_esle.php + beyan_view.php'yi php -S ile (dosya SQLite) çalıştırır,
// tarayıcıda ürün adını değiştirip kaydeder ve VERİTABANINDA ne kaldığını denetler. Canlı DB'ye dokunmaz.
//   node scripts/beyan_hks_urun_e2e.js        (Playwright yoksa ATLAR)
// Kök hata (v348): ürün adı KABAK→ACUR değiştirilince kilitli alanın eski değeri (KABAK) kaydediliyor ve
// "ACUR→KABAK" öğreniliyordu; öğrenilen eşleşme sonraki açılışta alanı yine KABAK'a kilitliyordu.
'use strict';
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const os = require('os');
let pw;
try { pw = require(process.env.PW_PATH || 'playwright'); }
catch (_) { try { pw = require('/opt/node22/lib/node_modules/playwright'); } catch (e) { console.log('Playwright yok — atlandı'); process.exit(0); } }

const ROOT = path.resolve(__dirname, '..');
const DB = path.join(os.tmpdir(), 'beyan_hks_urun_e2e_' + process.pid + '.sqlite');
const PORT = 8790 + (process.pid % 100);
const URL = 'http://127.0.0.1:' + PORT;
const env = Object.assign({}, process.env, { E2E_DB: DB });
let hata = 0, sayi = 0;
const ok = (ad, kosul, ipucu) => { sayi++; if (!kosul) hata++; console.log((kosul ? 'OK   ' : 'HATA ') + ad + (kosul || !ipucu ? '' : '  → ' + ipucu)); };
const kur = () => execFileSync('php', [path.join(__dirname, 'beyan_hks_urun_e2e_server.php'), '--kur'], { env, cwd: ROOT });
const sql = (q) => JSON.parse(execFileSync('php', ['-r',
    '$p=new PDO("sqlite:' + DB + '");$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);' +
    '$s=$p->query($argv[1]);echo json_encode($s?$s->fetchAll(PDO::FETCH_ASSOC):[]);', q], { env }).toString());
const beyan = () => sql('SELECT product_name, hks_urun_id, hks_urun_ad FROM customs_declarations WHERE id=1')[0];

(async () => {
    kur();
    const srv = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', ROOT, path.join(__dirname, 'beyan_hks_urun_e2e_server.php')], { env, cwd: ROOT, stdio: 'ignore' });
    await new Promise(r => setTimeout(r, 700));
    const browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : (require('fs').existsSync('/opt/pw-browsers/chromium') ? { executablePath: '/opt/pw-browsers/chromium' } : {}));
    try {
        const sec = (page) => page.evaluate(() => { const s = document.querySelector('select[name=hks_urun_id]');
            return { dis: s.disabled, val: s.value, kilit: s.dataset.hksKilit === '1', serbest: s.dataset.hksSerbest === '1' }; });
        const adYaz = async (page, ad) => { const i = page.locator('[name=product_name]'); await i.click(); await i.press('Control+A'); await i.type(ad, { delay: 20 }); };
        const kaydet = async (page) => { await Promise.all([page.waitForURL(/beyan_view\.php/), page.click('button[type=submit]:has-text("Güncelle")')]); };

        // A) Kullanıcının senaryosu: KABAK kilitli → ACUR yazıp Tab → Acur kilitli → Güncelle → Acur kaydedilir, öğrenme YOK
        let page = await browser.newPage();
        const errs = []; page.on('pageerror', e => errs.push(e.message));
        await page.goto(URL + '/beyan_edit.php?id=1');
        let s = await sec(page);
        ok('A1 açılışta KABAK kilitli', s.dis && s.kilit && s.val === '30', JSON.stringify(s));
        await adYaz(page, 'ACUR'); await page.keyboard.press('Tab'); await page.waitForTimeout(500);
        s = await sec(page);
        ok('A2 ACUR + Tab → Acur kilitli', s.dis && s.kilit && s.val === '31', JSON.stringify(s));
        await kaydet(page);
        ok('A3 kayıt: ürün ACUR, HKS ürünü Acur', JSON.stringify(beyan()) === JSON.stringify({ product_name: 'ACUR', hks_urun_id: '31', hks_urun_ad: 'Acur' }), JSON.stringify(beyan()));
        ok('A4 önizleme HKS kartında Acur', (await page.locator('.bk-hucre').allInnerTexts()).join('|').includes('Acur'));
        ok('A5 otomatik ürün eşleşmesi ÖĞRENİLMEDİ', sql("SELECT COUNT(*) n FROM hks_eslesme WHERE tip='urun'")[0].n == 0);
        ok('A6 tarayıcı hatası yok', errs.length === 0, errs.join('; '));

        // B) Yarış: ad değişti ve sonuç gelmeden Güncelle (alan hâlâ eski kilitte) → sunucu ürün adından çözer
        kur();
        await page.route('**/api_beyan_urun_esle.php*', async r => { await new Promise(x => setTimeout(x, 3000)); r.continue().catch(() => {}); });
        await page.goto(URL + '/beyan_edit.php?id=1');
        await adYaz(page, 'ACUR'); await page.keyboard.press('Tab');
        await kaydet(page);
        ok('B1 yanıt beklenmeden kayıt → yine Acur (eski KABAK kaydedilmez)', beyan().hks_urun_id === '31', JSON.stringify(beyan()));
        await page.unroute('**/api_beyan_urun_esle.php*');

        // C) Eşleştirme ucu hata verir (oturum düşmüş vb.) → alan AÇILIR, eski değer kalmaz
        kur();
        await page.route('**/api_beyan_urun_esle.php*', r => r.fulfill({ status: 200, contentType: 'text/html', body: '<html>giriş</html>' }));
        await page.goto(URL + '/beyan_edit.php?id=1');
        await adYaz(page, 'YABANCI'); await page.keyboard.press('Tab'); await page.waitForTimeout(300);
        s = await sec(page);
        ok('C1 uç hata → alan açık + boş', !s.dis && s.serbest && s.val === '', JSON.stringify(s));
        await page.unroute('**/api_beyan_urun_esle.php*');

        // D) Eşleşmeyen ad → alan açık (turuncu); kullanıcı Domates seçer → kaydedilir ve YALNIZ bu öğrenilir
        kur();
        await page.goto(URL + '/beyan_edit.php?id=1');
        await adYaz(page, 'YABANCI'); await page.keyboard.press('Tab'); await page.waitForTimeout(500);
        s = await sec(page);
        ok('D1 eşleşmeyen ad → açık, boş, turuncu', !s.dis && s.serbest && s.val === '', JSON.stringify(s));
        const cerceve = await page.evaluate(() => getComputedStyle(document.querySelector('select[name=hks_urun_id]')).borderTopColor);
        ok('D2 açık alanın çerçevesi turuncu', cerceve === 'rgb(245, 158, 11)', cerceve);
        await page.selectOption('select[name=hks_urun_id]', '33');
        await kaydet(page);
        ok('D3 elle seçim kaydedildi', beyan().hks_urun_id === '33', JSON.stringify(beyan()));
        ok('D4 elle seçim öğrenildi (yabancı→Domates)', sql("SELECT hks_id FROM hks_eslesme WHERE tip='urun'").map(r => r.hks_id).join() === '33');
        await page.goto(URL + '/beyan_edit.php?id=1');
        s = await sec(page);
        ok('D5 yeniden açınca alan açık + Domates (kilitlenmez)', !s.dis && s.serbest && s.val === '33', JSON.stringify(s));

        // E) Zehirli öğrenilmiş satır (acur→KABAK) kilidi ETKİLEMEZ — katalog tam adı kazanır
        kur();
        sql("INSERT INTO hks_eslesme (tip,kaynak_norm,hks_id,hks_ad,kullanim,updated_at) VALUES ('urun','acur','30','Kabak',3,'2026-10-13')");
        sql("UPDATE customs_declarations SET product_name='ACUR' WHERE id=1");
        await page.goto(URL + '/beyan_edit.php?id=1');
        s = await sec(page);
        ok('E1 zehirli öğrenme olsa da ACUR → Acur kilitli', s.dis && s.kilit && s.val === '31', JSON.stringify(s));
        await kaydet(page);
        ok('E2 kayıt Acur', beyan().hks_urun_id === '31', JSON.stringify(beyan()));

        // F) Ürün adı silinirse HKS ürünü de temizlenir
        kur();
        await page.goto(URL + '/beyan_edit.php?id=1');
        await page.locator('[name=product_name]').fill(''); await page.keyboard.press('Tab'); await page.waitForTimeout(300);
        await page.locator('[name=product_name]').fill('KAPYA'); await page.keyboard.press('Tab'); await page.waitForTimeout(500);
        s = await sec(page);
        ok('F1 KAPYA → benzer adla Biber Salçalık (Kapya) kilitli', s.dis && s.kilit && s.val === '32', JSON.stringify(s));
        await page.close();

        // G) JavaScript KAPALI: alan sunucu çizimiyle kilitli kalır; ad ACUR yapılıp gönderilirse sunucu Acur yazar
        kur();
        const ctx = await browser.newContext({ javaScriptEnabled: false });
        page = await ctx.newPage();
        await page.goto(URL + '/beyan_edit.php?id=1');
        await page.locator('[name=product_name]').fill('ACUR');
        await Promise.all([page.waitForURL(/beyan_view\.php/), page.click('button[type=submit]:has-text("Güncelle")')]);
        ok('G1 JS kapalı + eski kilit → sunucu Acur yazdı', beyan().hks_urun_id === '31', JSON.stringify(beyan()));
        await ctx.close();
    } finally {
        await browser.close();
        srv.kill();
        try { require('fs').unlinkSync(DB); } catch (_) {}
    }
    console.log(`\n${sayi} kontrol, ${hata} hata`);
    process.exit(hata ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
