// =========================================================
// scripts/pdks_fis_duzelt_smoke.js — Fiş düzeltme TARAYICI uçtan uca testi (v464, W4)
//   node scripts/pdks_fis_duzelt_smoke.js   → çıkış 0 = geçti (Playwright yoksa ATLANIR)
// Gerçek sayfalar (gunluk_fis_yukle.php «Üstüne yaz», cavus_fis_geri_al.php, cavus_fis_rapor.php) GERÇEK php -S sunucusunda çalışır
// (scripts/pdks_fis_e2e_router.php; yalnız kimlik/şablon yardımcıları sahte), DB dosya tabanlı SQLite
// (scripts/pdks_fis_duzelt_render.php). Akış: etkilenen fişler raporu → üstüne yaz (önizleme ESKİ→YENİ, yazma, DB) →
// geri al: yanlış şifre / yanlış çavuş kodu = HİÇBİR ŞEY, brute-force bekleme, doğru şifre = uygulanır (kiosk/elle + kesin hakedişli korunur) →
// yönetici olmayan 403 → 360/768/1280 yatay taşma yok.
// =========================================================
'use strict';
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');
let pw;
try { pw = require(process.env.PW_PATH || 'playwright'); }
catch (e) {
  try { pw = require('/opt/node22/lib/node_modules/playwright'); }
  catch (e2) { console.log('Playwright bulunamadi — test ATLANDI (hata degil).'); process.exit(0); }
}
const ROOT = path.resolve(__dirname, '..');
const ROUTER = path.join(__dirname, 'pdks_fis_e2e_router.php');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pdks_fis_dz_'));
const DB = path.join(TMP, 'dz.sqlite'), XLSX = path.join(TMP, 'fisler.xlsx');
const PORT = 19600 + (process.pid % 600), PORT2 = PORT + 600;
let fail = 0, n = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(92) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };
const pageErrors = [];
const SHOT = process.env.DZ_SHOT || '';   // ekran görüntüsü klasörü (isteğe bağlı)
let KURULUM = null;
const say = (...sids) => JSON.parse(execFileSync('php', [path.join(__dirname, 'pdks_fis_duzelt_say.php'), DB, ...sids.map(String)], { cwd: ROOT }).toString());

function sunucu(port, ortam) {
  const s = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', ROOT, ROUTER], { cwd: ROOT, env: { ...process.env, PDKS_FIS_E2E_DB: DB, ...ortam }, stdio: ['ignore', 'ignore', 'pipe'] });
  s.stderrText = ''; s.stderr.on('data', c => { s.stderrText += c.toString(); });
  return s;
}
async function bekle(port, srv) {
  for (let i = 0; i < 80; i++) {
    if (srv.exitCode !== null) throw new Error('php -S çıktı: ' + srv.stderrText);
    try { const r = await fetch(`http://127.0.0.1:${port}/cavus_fis_rapor.php`); if (r.status < 500) return; } catch (e) { /* bekle */ }
    await new Promise(r => setTimeout(r, 150));
  }
  throw new Error('php -S başlamadı: ' + srv.stderrText);
}
const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
async function tasmaDene(page, ad) {
  for (const w of [1280, 768, 360]) {
    await page.setViewportSize({ width: w, height: 900 });
    const t = await tasma(page);
    ok(`${ad}: ${w}px yatay taşma yok`, t <= 1, String(t));
  }
  await page.setViewportSize({ width: 1280, height: 900 });
}
const flash = async page => (await page.locator('[data-flash]').allInnerTexts()).join(' | ');

(async () => {
  let browser, srv, srv2;
  try {
    KURULUM = JSON.parse(execFileSync('php', [path.join(__dirname, 'pdks_fis_duzelt_render.php'), DB, XLSX], { cwd: ROOT, stdio: 'pipe' }).toString());
    const SID = KURULUM.sid, SIFRE = KURULUM.sifre;
    srv = sunucu(PORT, {}); srv2 = sunucu(PORT2, { PDKS_FIS_E2E_ADMIN: '0' });
    await Promise.all([bekle(PORT, srv), bekle(PORT2, srv2)]);
    const B = `http://127.0.0.1:${PORT}`;
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
    const yeniCtx = async () => {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await ctx.newPage();
      page.on('pageerror', e => pageErrors.push(e.message));
      page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource:')) pageErrors.push(m.text()); });
      page.on('dialog', d => d.accept());
      return { ctx, page };
    };
    const { ctx, page } = await yeniCtx();

    const s0 = say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1);
    ok('0) fixture: 28 aktif dönem · 1 servis · D1 Erkek 13 · D4 Erkek 2 + Kadın 1 (fiş dışı)', s0.aktif === 28 && s0.servis_aktif === 1 && s0.tipler[SID.D1].Erkek === 13 && s0.tipler[SID.D4].Kadın === 1, JSON.stringify(s0));

    console.log('\n=== 1. Etkilenen fişler raporu ===');
    await page.goto(`${B}/cavus_fis_rapor.php`);
    ok('1a) başlık «Etkilenen Fişler»', (await page.locator('h1').innerText()).includes('Etkilenen Fişler'));
    const satirlar = async () => page.locator('table.fd-tablo tbody tr').count();
    ok('1b) varsayılan süzgeç (Yarım > 0, geri alınmayanlar): 3 fiş (D-1, D-2, D-B1)', await satirlar() === 3, String(await satirlar()));
    ok('1c) ✱ olası yanlış yorum: Erkek Yarım olan D-1 ve D-B1 (2 satır)', await page.locator('table.fd-tablo .fy-rozet:has-text("✱")').count() === 2);
    const d2 = page.locator('table.fd-tablo tbody tr', { hasText: 'D-2' });
    ok('1d) D-2 yalnız Kadın Yarım: tip «Kadın 1», ✱ YOK', (await d2.innerText()).includes('Kadın 1') && await d2.locator('.fy-rozet:has-text("✱")').count() === 0);
    await page.selectOption('select[name="cavus"]', { label: 'C001 · Çavuş A' });
    await page.waitForURL(/cavus=1/);
    ok('1e) çavuş süzgeci kendiliğinden gönderilir (data-oto-filtre): 2 fiş (D-1, D-2)', await satirlar() === 2, String(await satirlar()));
    await page.locator('input[name="yarim"]').uncheck();
    await page.waitForFunction(() => document.querySelectorAll('table.fd-tablo tbody tr').length === 4);
    ok('1f) «Yalnız Yarım > 0» kapalı: D-3, D-4 de listelenir (4 satır)', await satirlar() === 4);
    ok('1g) rapor sayfasında POST formu / yazma düğmesi YOK', await page.locator('form[method="post"]').count() === 0);
    await page.goto(`${B}/cavus_fis_rapor.php`);
    await tasmaDene(page, '1h) rapor');

    console.log('\n=== 2. Üstüne yaz (Fiş Excel\'i Yükle) ===');
    await page.goto(`${B}/gunluk_fis_yukle.php`);
    ok('2a) yükleme formunda «Düzeltme» kutusu: ÜSTÜNE YAZ onay kutusu, gerekçe başta GİZLİ', await page.locator('#fyUstune').count() === 1 && !(await page.locator('#fyUstuneGerekceKap').isVisible()));
    ok('2b) üst bağlantılar: Etkilenen fişler + Fiş yüklemelerini geri al', await page.locator('a.btn:has-text("Etkilenen fişler")').count() === 1 && await page.locator('a.btn:has-text("Fiş yüklemelerini geri al")').count() === 1);
    await page.selectOption('#fyCavus', { label: 'C001 · Çavuş A' });
    await page.setInputFiles('#fyDosya', XLSX);
    await page.locator('#fyUstune').check();
    ok('2c) kutu işaretlenince gerekçe alanı görünür ve ZORUNLU', await page.locator('#fyUstuneGerekceKap').isVisible() && await page.locator('#fyUstuneGerekce').evaluate(e => e.required));
    await page.locator('#fyUstuneGerekce').fill('Eski 12+1 kuralı düzeltildi');
    await page.locator('#fyYukleForm button[type="submit"]').click();
    await page.waitForURL(/onizle=/);
    const satir = page.locator('table.fy-tablo tbody tr').first();
    ok('2d) önizleme rozeti «Üstüne yazılacak», satır varsayılan SEÇİLİ DEĞİL', (await satir.locator('.fy-rozet').first().innerText()).includes('Üstüne yazılacak') && !(await satir.locator('input[name="secilen[]"]').isChecked()));
    const eski = await page.locator('.fd-eski').first().innerText(), yeni = await page.locator('.fd-yeni').first().innerText();
    ok('2e) ESKİ: Erkek 13 kişi (12 Tam, 1 Yarım) · YENİ: Erkek 12 kişi + Şeritçi 1 kişi', eski.includes('Erkek: 13 kişi (12 Tam, 1 Yarım)') && yeni.includes('Erkek: 12 kişi (12 Tam)') && yeni.includes('Şeritçi: 1 kişi (1 Tam)'), eski + ' // ' + yeni);
    ok('2f) YENİ revizyon 1 görünür; eski servis iptal notu', yeni.includes('rev 1') && (await page.locator('.fd-servis').first().innerText()).includes('Eski fiş servisi iptal: 0B/1K'));
    ok('2g) «Üstüne yaz AÇIK» notu + gerekçe', (await page.locator('.fd-ustune-not').innerText()).includes('Eski 12+1 kuralı düzeltildi'));
    const sOnce = say(SID.D1);
    ok('2h) önizleme YAN ETKİSİZ (iptal 0)', sOnce.iptal === 0 && sOnce.aktif === 28);
    await tasmaDene(page, '2i) önizleme (ESKİ→YENİ)');
    if (SHOT) { await page.screenshot({ path: path.join(SHOT, 'ustune_1280.png'), fullPage: true }); await page.setViewportSize({ width: 390, height: 900 }); await page.screenshot({ path: path.join(SHOT, 'ustune_390.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 }); }
    await page.locator('input[name="secilen[]"]').first().check();
    await page.locator('#fyYazBtn').click();
    await page.waitForURL(/sonuc=/);
    const sonucMetin = await page.locator('#fisYukle').innerText();
    ok('2j) sonuç: Yazıldı + «Üstüne yazıldı: 13 eski kayıt · 1 eski servis iptal edildi»', sonucMetin.includes('Yazıldı') && /Üstüne yazıldı: 13 eski kayıt · 1 eski servis iptal edildi/.test(sonucMetin), sonucMetin.slice(0, 300));
    const s1 = say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1);
    ok('2k) DB: D1 aktif Erkek 12 + Şeritçi 1; 13 eski dönem İPTAL (silinmedi: toplam +13)', JSON.stringify(s1.tipler[SID.D1]) === JSON.stringify({ 'Erkek': 12, 'Şeritçi': 1 }) && s1.iptal === 13 && s1.donem_toplam === s0.donem_toplam + 13, JSON.stringify(s1.tipler[SID.D1]) + ' ' + s1.iptal);
    ok('2l) DB: servis eski iptal + yeni 1 (aktif 1, toplam 2); diğer çavuş/mesailer değişmedi', s1.servis_aktif === 1 && s1.servis_toplam === 2 && s1.tipler[SID.B1].Erkek === 3 && s1.tipler[SID.D2].Kadın === 6, JSON.stringify(s1));
    ok('2m) DB: ikinci fis_yukle izi (toplam 6), mesai/kart sayısı artmadı', s1.fis_yukle === s0.fis_yukle + 1 && s1.mesai === s0.mesai);
    await tasmaDene(page, '2n) sonuç');
    await page.goto(`${B}/gunluk_fis_yukle.php`);
    await page.selectOption('#fyCavus', { label: 'C001 · Çavuş A' });
    await page.setInputFiles('#fyDosya', XLSX);
    await page.locator('#fyUstune').check();
    ok('2o) gerekçesiz Önizle HTML ile engellenir', await page.locator('#fyUstuneGerekce').evaluate(e => e.validity.valueMissing));

    console.log('\n=== 3. Rapor (üstüne yazdıktan sonra) ===');
    await page.goto(`${B}/cavus_fis_rapor.php?f=1&yarim=1&aktif=1&cavus=1&bas=${KURULUM.gun6}&bit=${new Date(Date.now() - 86400000).toISOString().slice(0, 10)}`);
    ok('3a) eski D-1 (rev 0) «geri alındı» olduğundan gizli; yeni rev 1 Yarım içermez → yalnız D-2', await satirlar() === 1 && (await page.locator('table.fd-tablo tbody').innerText()).includes('D-2'));
    await page.goto(`${B}/cavus_fis_rapor.php?f=1&yarim=1&cavus=1&bas=${KURULUM.gun6}&bit=${new Date(Date.now() - 86400000).toISOString().slice(0, 10)}`);
    ok('3b) «geri alınanları gizle» kapalıyken D-1 rev 0 «Geri alındı» olarak görünür', (await page.locator('table.fd-tablo tbody tr', { hasText: 'D-1' }).innerText()).includes('Geri alındı'));

    console.log('\n=== 4. Fiş yüklemelerini geri al ===');
    await page.goto(`${B}/cavus_fis_geri_al.php?cavus=${KURULUM.cavus.A}`);
    ok('4a) başlık + çavuş önseçili + gerekçe zorunlu', (await page.locator('h1').innerText()).includes('Geri Al') && (await page.locator('#fdCavus').inputValue()) === String(KURULUM.cavus.A) && await page.locator('#fdGerekce').evaluate(e => e.required));
    await tasmaDene(page, '4b) form');
    await page.locator('#fdGerekce').fill('Test geri alma');
    await page.locator('#fdForm button[type="submit"]').click();
    await page.waitForURL(/onizle=/);
    const ozet = await page.locator('.fy-ozet').first().innerText();
    ok('4c) döküm: 3 mesai · 21 dönem iptal edilecek · 1 servis', /3\s*mesai/.test(ozet) && /21\s*dönem iptal edilecek/.test(ozet) && /1\s*servis iptal edilecek/.test(ozet), ozet);
    ok('4d) kesinleşmiş hakedişli D-3 «atlanacak» tablosunda neden yazar', (await page.locator('h2:has-text("Atlanacak")').count()) === 1 && (await page.locator('table').last().innerText()).includes('Kesinleşmiş hakediş'));
    ok('4e) fiş dışı kayıt bilgisi (D-4: 1) görünür ve «dokunulmaz» notu var', (await page.locator('.fd-tablo').first().innerText()).includes('Fiş dışı') && (await page.locator('body').innerText()).includes('dokunulmaz'));
    ok('4f) onay formu: çavuş kodu + şifre alanı (type=password, autocomplete=current-password)', await page.locator('#fdKod').count() === 1 && (await page.locator('#fdSifre').getAttribute('type')) === 'password' && (await page.locator('#fdSifre').getAttribute('autocomplete')) === 'current-password');
    await tasmaDene(page, '4g) döküm + onay');
    if (SHOT) { await page.screenshot({ path: path.join(SHOT, 'geri_al_1280.png'), fullPage: true }); await page.setViewportSize({ width: 390, height: 900 }); await page.screenshot({ path: path.join(SHOT, 'geri_al_390.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 }); }
    const onizleUrl = page.url();
    const kod = 'C001';
    const sDon = say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1);
    // yanlış şifre → hiçbir şey
    await page.locator('#fdKod').fill(kod); await page.locator('#fdSifre').fill('yanlis-sifre');
    await page.locator('#fdUygula').click();
    await page.waitForLoadState('load');
    ok('4h) yanlış şifre: hata mesajı «Şifre hatalı (4 deneme hakkı kaldı)» + HİÇBİR ŞEY yapılmadı', (await flash(page)).includes('Şifre hatalı') && (await flash(page)).includes('4 deneme') && JSON.stringify(say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1)) === JSON.stringify(sDon), await flash(page));
    ok('4i) hata sonrası sayfa aynı döküme döner (token korunur) ve şifre alanı BOŞ', page.url() === onizleUrl && (await page.locator('#fdSifre').inputValue()) === '');
    // yanlış çavuş kodu (doğru şifre) → hiçbir şey, şifre sayacı ilerlemez
    await page.locator('#fdKod').fill('YANLIS'); await page.locator('#fdSifre').fill(SIFRE);
    await page.locator('#fdUygula').click();
    await page.waitForLoadState('load');
    ok('4j) yanlış çavuş kodu: «çavuş kodunu aynen yazın» + HİÇBİR ŞEY yapılmadı', (await flash(page)).includes('çavuş kodunu') && JSON.stringify(say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1)) === JSON.stringify(sDon), await flash(page));
    // brute-force: 4 yanlış daha → 5. denemede bekleme
    for (let i = 0; i < 4; i++) {
      await page.locator('#fdKod').fill(kod); await page.locator('#fdSifre').fill('yanlis' + i);
      await page.locator('#fdUygula').click(); await page.waitForLoadState('load');
    }
    ok('4k) 5. yanlış denemede bekleme mesajı (60 sn)', (await flash(page)).includes('Çok fazla hatalı deneme') && (await flash(page)).includes('60'), await flash(page));
    await page.locator('#fdKod').fill(kod); await page.locator('#fdSifre').fill(SIFRE);
    await page.locator('#fdUygula').click(); await page.waitForLoadState('load');
    ok('4l) bekleme sürerken DOĞRU şifre de REDDEDİLİR, hiçbir şey yapılmadı', (await flash(page)).includes('Çok fazla hatalı deneme') && JSON.stringify(say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1)) === JSON.stringify(sDon), await flash(page));
    // yeni oturum (sayaç sıfır) → aynı token (hks_kv yedeği) ile doğru şifre → uygulanır
    const c2 = await yeniCtx();
    await c2.page.goto(onizleUrl);
    ok('4m) yeni oturumda token hks_kv yedeğinden açılır (döküm görünür)', await c2.page.locator('#fdKod').count() === 1, c2.page.url());
    await c2.page.locator('#fdKod').fill(kod); await c2.page.locator('#fdSifre').fill(SIFRE);
    await c2.page.locator('#fdUygula').click();
    await c2.page.waitForURL(/sonuc=/);
    const sm = await c2.page.locator('#fisGeriAl').innerText();
    ok('4n) sonuç: 3 mesai işlendi · 21 dönem iptal · 1 servis iptal · 1 atlandı', /3\s*mesai işlendi/.test(sm) && /21\s*dönem iptal edildi/.test(sm) && /1\s*servis iptal edildi/.test(sm) && /1\s*atlandı/.test(sm), sm.slice(0, 400));
    const sSon = say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1);
    ok('4o) DB: D1/D2 aktif dönem 0 (iptal, SİLİNMEDİ: toplam dönem sayısı aynı)', Object.keys(sSon.tipler[SID.D1]).length === 0 && Object.keys(sSon.tipler[SID.D2]).length === 0 && sSon.donem_toplam === sDon.donem_toplam && sSon.kart === sDon.kart && sSon.mesai === sDon.mesai, JSON.stringify(sSon));
    ok('4p) DB: KESİN hakedişli D3 (Erkek 3) DOKUNULMADI · fiş dışı Kadın 1 (D4) KALDI · başka çavuş (B1 Erkek 3) KALDI', sSon.tipler[SID.D3].Erkek === 3 && sSon.tipler[SID.D4].Kadın === 1 && Object.keys(sSon.tipler[SID.D4]).length === 1 && sSon.tipler[SID.B1].Erkek === 3, JSON.stringify(sSon.tipler));
    ok('4q) DB: servis aktif 0 (iptal, silinmedi); audit fis_sifirla = 3 mesai', sSon.servis_aktif === 0 && sSon.servis_toplam === sDon.servis_toplam && sSon.fis_sifirla === 3, JSON.stringify(sSon));
    ok('4r) sonuç tablosunda atlanan D-3 «Atlandı» + Mesai Detayı bağlantıları', (await c2.page.locator('.fy-rozet:has-text("Atlandı")').count()) === 1 && (await c2.page.locator('a:has-text("Mesai Detayı")').count()) === 4);
    await tasmaDene(c2.page, '4s) sonuç');
    // aynı token tekrar → süresi/izi yok (token silindi)
    await c2.page.goto(onizleUrl);
    ok('4t) işlem tamamlandıktan sonra aynı onay bağlantısı geçersiz (token silindi) → forma döner', await c2.page.locator('#fdKod').count() === 0 && (await flash(c2.page)).length > 0, c2.page.url());
    await c2.ctx.close();

    console.log('\n=== 5. Yönetici olmayan ===');
    for (const yol of ['cavus_fis_geri_al.php', 'cavus_fis_rapor.php']) {
      const r = await fetch(`http://127.0.0.1:${PORT2}/${yol}`);
      const t = await r.text();
      ok(`5) ${yol}: yönetici değilse 403 (içerik sızmaz)`, r.status === 403 && !t.includes('D-1') && !t.includes('Çavuş A'), String(r.status));
    }
    const rp = await fetch(`http://127.0.0.1:${PORT2}/cavus_fis_geri_al.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'eylem=uygula&token=' + 'a'.repeat(32) });
    ok('5) yönetici değilse POST da 403', rp.status === 403);
    const rc = await fetch(`${B}/cavus_fis_geri_al.php`, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'eylem=uygula&token=' + 'a'.repeat(32) });
    ok('5) CSRF olmadan admin POST reddedilir (403, hiçbir şey yapılmaz)', rc.status === 403 && JSON.stringify(say(SID.D1, SID.D2, SID.D3, SID.D4, SID.B1).tipler) === JSON.stringify(sSon.tipler));

    ok('6) sayfa JS hatası yok', pageErrors.length === 0, pageErrors.join(' | '));
  } catch (e) {
    fail++; console.log('*** TEST ÇÖKTÜ: ' + (e && e.stack || e));
  } finally {
    try { if (browser) await browser.close(); } catch (e) { /* yoksay */ }
    for (const s of [srv, srv2]) { try { if (s) s.kill(); } catch (e) { /* yoksay */ } }
    try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* yoksay */ }
  }
  console.log(`\n--- Sonuç: ${n - fail} geçti, ${fail} kaldı ---`);
  process.exit(fail ? 1 : 0);
})();
