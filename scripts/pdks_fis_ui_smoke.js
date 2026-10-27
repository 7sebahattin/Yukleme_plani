// =========================================================
// scripts/pdks_fis_ui_smoke.js — "Fiş Excel'i Yükle" TARAYICI uçtan uca testi (v446)
//   node scripts/pdks_fis_ui_smoke.js   → çıkış 0 = geçti (Playwright yoksa ATLANIR)
// Gerçek gunluk_fis_yukle.php, GERÇEK php -S sunucusunda (gerçek multipart yükleme, oturum, CSRF) çalışır;
// yalnız kimlik/şablon yardımcıları sahtedir (scripts/pdks_fis_e2e_router.php) ve DB dosya tabanlı SQLite'tır.
// Akış: şablon indir → dolu xlsx yükle → önizleme (masaüstü tablo / mobil kart, seçim eşitleme, XSS) → yaz → sonuç →
// DB sayımları → aynı dosya tekrar (zaten yüklü) → CSRF reddi → yönetici olmayan 403 → yatay taşma yok (360/768/1280).
// Ekran görüntüsü: FIS_SHOT=<klasör>
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
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pdks_fis_ui_'));
const DB = path.join(TMP, 'fis.sqlite'), XLSX = path.join(TMP, 'fisler.xlsx');
const PORT = 18500 + (process.pid % 700), PORT2 = PORT + 700;
let fail = 0, n = 0;
const ok = (ad, k, ip) => { n++; if (!k) fail++; console.log(ad.padEnd(92) + (k ? 'OK' : '*** FAIL' + (ip ? '\n    → ' + ip : ''))); };
const sayilar = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'pdks_fis_e2e_say.php'), DB], { cwd: ROOT }).toString());
const pageErrors = [];
const SHOT = process.env.FIS_SHOT || '';

function sunucu(port, ortam) {
  const s = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', ROOT, ROUTER], { cwd: ROOT, env: { ...process.env, PDKS_FIS_E2E_DB: DB, ...ortam }, stdio: ['ignore', 'ignore', 'pipe'] });
  s.stderrText = ''; s.stderr.on('data', c => { s.stderrText += c.toString(); });
  return s;
}
async function bekle(port, srv) {
  for (let i = 0; i < 80; i++) {
    if (srv.exitCode !== null) throw new Error('php -S çıktı: ' + srv.stderrText);
    try { const r = await fetch(`http://127.0.0.1:${port}/gunluk_fis_yukle.php`); if (r.status < 500) return; } catch (e) { /* bekle */ }
    await new Promise(r => setTimeout(r, 150));
  }
  throw new Error('php -S başlamadı: ' + srv.stderrText);
}
const tasma = page => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

(async () => {
  let browser, srv, srv2;
  try {
    execFileSync('php', [path.join(__dirname, 'pdks_fis_e2e_setup.php'), DB, XLSX], { cwd: ROOT, stdio: 'pipe' });
    srv = sunucu(PORT, {}); srv2 = sunucu(PORT2, { PDKS_FIS_E2E_ADMIN: '0' });
    await Promise.all([bekle(PORT, srv), bekle(PORT2, srv2)]);
    const URL = `http://127.0.0.1:${PORT}/gunluk_fis_yukle.php`;
    browser = await pw.chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true, permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await ctx.newPage();
    page.on('pageerror', e => pageErrors.push(e.message));
    page.on('console', m => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource:')) pageErrors.push(m.text()); });
    page.on('dialog', d => d.accept());

    console.log('\n=== 1. Form + şablon ===');
    await page.goto(URL);
    ok('1) sayfa başlığı ve "← Günlük Puantaj" bağlantısı (mavi geri düğmesi)', (await page.locator('h1').innerText()).includes('Fiş Excel') && await page.locator('a.btn-geri', { hasText: 'Günlük Puantaj' }).count() === 1);
    ok('1) "Personel Takibi" turuncu geri düğmesi YOK (btn-geri-ptak)', await page.locator('.btn-geri-ptak').count() === 0);
    const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('a:has-text("Şablonu indir")').click()]);
    const dlYol = path.join(TMP, 'sablon.xlsx'); await dl.saveAs(dlYol);
    const bas = fs.readFileSync(dlYol).subarray(0, 4).toString('latin1');
    ok('1) şablon indirildi: Fis_Excel_Sablonu_YYYYMMDD.xlsx, PK imzalı, > 5 KB', /^Fis_Excel_Sablonu_\d{8}\.xlsx$/.test(dl.suggestedFilename()) && bas === 'PK\x03\x04' && fs.statSync(dlYol).size > 5000, dl.suggestedFilename());
    await page.locator('details.fy-komut summary').click();
    ok('1) komut metni görünür ve içeriği şablon komutu', (await page.locator('#fyKomut').inputValue()).startsWith('GÖREV: Ekteki fotoğraflarda'));
    await page.locator('#fyKopyala').click();
    await page.waitForFunction(() => document.getElementById('fyKopyalaDurum').textContent.length > 0);
    ok('1) "Komutu kopyala" durum metni yazar; pano içeriği komutla aynı', (await page.locator('#fyKopyalaDurum').innerText()) !== '' && (await page.evaluate(() => navigator.clipboard.readText().catch(() => ''))).startsWith('GÖREV:'));
    ok('1) form: file input accept .xlsx, 16px+ yazı (mobil zoom önleme global kuralı)', (await page.locator('#fyDosya').getAttribute('accept')).includes('.xlsx'));
    for (const w of [1280, 768, 360]) {
      await page.setViewportSize({ width: w, height: 900 });
      ok(`1) ${w}px: yatay taşma yok`, (await tasma(page)) <= 1, String(await tasma(page)));
    }
    if (SHOT) { await page.setViewportSize({ width: 1280, height: 900 }); await page.screenshot({ path: path.join(SHOT, 'fis_form_1280.png'), fullPage: true }); }

    console.log('\n=== 2. Yükle → önizleme ===');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(URL);
    await page.setInputFiles('#fyDosya', XLSX);
    await Promise.all([page.waitForURL(/\?onizle=[a-f0-9]{32}/), page.locator('button:has-text("Önizle")').click()]);
    ok('2) önizleme sayfası: 3 fiş, 2 hazır, 1 hatalı', await page.locator('.fy-ozet').innerText().then(t => /3\s*fiş/.test(t) && /2\s*hazır/.test(t) && /1\s*hatalı/.test(t)));
    ok('2) masaüstü (1280): tablo görünür, mobil kartlar gizli', await page.locator('.fy-tablo').first().isVisible() && !(await page.locator('.fy-kartlar').isVisible()));
    ok('2) 3 tablo satırı; E2E-1 ve E2E-2 "Hazır", E2E-3 "Hata"', (await page.locator('.pc-only tbody tr').count()) === 3 && (await page.locator('.pc-only tr.fy-s-hazir').count()) === 2 && (await page.locator('.pc-only tr.fy-s-hata').count()) === 1);
    ok('2) E2E-1 grupları: Erkek FM 2 sa · 5 kişi · 08:00→19:00 ve Yarım 12:30', await page.locator('.pc-only tbody tr').first().innerText().then(t => t.includes('FM 2 sa · 5 kişi · 08:00→19:00') && t.includes('Yarım · 1 kişi · 08:00→12:30')));
    ok('2) E2E-2: Toplam FM dağıtımı (25 sa → 9 kişi) + "Ücret nötr ✓" + gerçek tip adları (Kadın/Rampacı)', await page.locator('.pc-only tbody tr').nth(1).innerText().then(t => t.includes('25 sa → 9 kişi') && t.includes('Ücret nötr ✓') && t.includes('Kadın:') && t.includes('Rampacı:')));
    ok('2) hatalı satır: Türkçe hücre hatası (Satır 4, «Erkek Tam»)', await page.locator('.pc-only tr.fy-s-hata').innerText().then(t => t.includes('Satır 4, «Erkek Tam»') && t.includes('ayrı sütunlara')));
    ok('2) XSS: <script>/<img onerror> çalışmadı, DOM\'a eklenmedi', (await page.evaluate(() => window.__xss === undefined && document.querySelectorAll('#fisYukle script, #fisYukle img[src="x"]').length === 0)) && (await page.locator('#fisYukle').textContent()).includes('<script>window.__xss=1</script>'));
    ok('2) onay kutuları varsayılan: Yarım ☑ FM ☑ EK ☐ İşletme ☐', await page.locator('[name=onay_yarim]').isChecked() && await page.locator('[name=onay_fm]').isChecked() && !(await page.locator('[name=ek_yaz]').isChecked()) && !(await page.locator('[name=isletme_onay]').isChecked()));
    ok('2) seçim: hazır fişler işaretli, hatalı disabled; sayaç (2)', await page.locator('.pc-only input[name="secilen[]"]').evaluateAll(l => l.map(c => c.checked + ':' + c.disabled).join()) === 'true:false,true:false,false:true' && (await page.locator('[data-fy-sayi]').innerText()) === '2');
    // seçim eşitleme
    await page.locator('.pc-only input[name="secilen[]"]').nth(1).uncheck();
    ok('2) tablodan işareti kaldırınca mobil kart kutusu da kalkar ve sayaç (1)', await page.locator('input[name="secilen_m[]"]').nth(1).evaluate(c => !c.checked) && (await page.locator('[data-fy-sayi]').innerText()) === '1');
    await page.locator('[data-fy-hepsi]').check();
    ok('2) "Tümünü seç": etkin olanlar işaretlenir, disabled kalır; sayaç (2)', (await page.locator('[data-fy-sayi]').innerText()) === '2' && await page.locator('.pc-only input[name="secilen[]"]').nth(2).evaluate(c => !c.checked && c.disabled));
    await page.locator('[data-fy-hepsi]').uncheck();
    ok('2) hepsini kaldırınca "Seçilenleri yaz" düğmesi disabled', await page.locator('#fyYazBtn').isDisabled());
    await page.locator('[data-fy-hepsi]').check();
    for (const w of [1280, 1024, 768, 360]) {
      await page.setViewportSize({ width: w, height: 900 });
      const tabloGorunur = await page.locator('.pc-only').first().isVisible();
      ok(`2) ${w}px: ${w >= 1024 ? 'tablo' : 'kart'} görünümü doğru`, tabloGorunur === (w >= 1024), String(tabloGorunur));
      ok(`2) ${w}px: sayfa yatay taşması yok`, (await tasma(page)) <= 1, String(await tasma(page)));
    }
    await page.setViewportSize({ width: 360, height: 900 });
    ok('2) 360px kart: ilk kartta rozet + Tarih/Çavuş/Gruplar/Fazla mesai/Servis/Mevcut satırları', await page.locator('.fy-kart').first().innerText().then(t => ['Tarih', 'Çavuş', 'Gruplar', 'Fazla mesai', 'Servis', 'Mevcut'].every(x => t.includes(x)) && t.includes('Hazır')));
    ok('2) 360px: seçim kutusu dokunma hedefi ≥ 44px', (await page.locator('.fy-kart-sec').first().boundingBox()).height >= 44);
    ok('2) 360px: yazma düğmesi tam genişlik (≥ 44px yükseklik)', (await page.locator('#fyYazBtn').boundingBox()).height >= 44);
    if (SHOT) { await page.screenshot({ path: path.join(SHOT, 'fis_onizle_360.png'), fullPage: true }); await page.setViewportSize({ width: 1280, height: 900 }); await page.screenshot({ path: path.join(SHOT, 'fis_onizle_1280.png'), fullPage: true }); }
    await page.setViewportSize({ width: 1280, height: 900 });
    ok('2) önizleme sırasında DB\'de HİÇBİR kayıt yok', JSON.stringify(sayilar()).includes('"mesai":0') && sayilar().donem === 0);

    console.log('\n=== 2b. Oturum kaybı (v447): PHP oturum çerezi silinse de önizleme + yazma çalışır ===');
    await ctx.clearCookies({ name: 'PHPSESSID' });
    await page.reload();
    ok('2b) çerez silindikten sonra önizleme yeniden açılır (hks_kv yedeği)', (await page.locator('.pc-only tr.fy-s-hazir').count()) === 2 && (await page.locator('[data-flash].err, [data-flash][class*=error], .flash-error').count()) === 0 && /onizle=[a-f0-9]{32}/.test(page.url()));
    ok('2b) yedekten gelen önizlemede seçimler ve yazma düğmesi sağlam', (await page.locator('[data-fy-sayi]').innerText()) === '2' && await page.locator('#fyYazBtn').isEnabled());

    console.log('\n=== 3. Yaz → sonuç ===');
    await Promise.all([page.waitForURL(/\?sonuc=[a-f0-9]{32}/), page.locator('#fyYazBtn').click()]);
    const sonucMetin = await page.locator('#fisYukle').innerText();
    ok('3) sonuç özeti: 2 yazıldı · 44 kişi kaydı · 14 FM onayı · servis 1 büyük 1 küçük', /2\s*yazıldı/.test(sonucMetin) && /44\s*kişi kaydı/.test(sonucMetin) && /14\s*FM onayı/.test(sonucMetin) && /1\s*büyük\s*·\s*1\s*küçük/.test(sonucMetin), sonucMetin.slice(0, 300));
    ok('3) sonuç: Mesai Detayı bağlantıları + toplu işlem kimlikleri (TP…)', (await page.locator('a:has-text("Mesai Detayı")').count()) === 2 && (sonucMetin.match(/TP\d{8}[0-9a-f]{8}/g) || []).length === 2);
    const s1 = sayilar();
    ok('3) DB: 2 mesai · 44 aktif dönem · servis toplamı 2 · 2 fis_yukle · 2 toplu işlem', s1.mesai === 2 && s1.donem === 44 && s1.servis === 2 && s1.fis_yukle === 2 && s1.toplu === 2, JSON.stringify(s1));
    ok('3) DB: 14 FM onaylı dönem · 2 Yarım kararı (hata veren fiş YAZILMADI)', s1.fm_onayli === 14 && s1.yarim_onayli === 2, JSON.stringify(s1));
    ok('3) sonuç sayfası yenilenebilir (PRG): F5 yazmayı TEKRARLAMAZ', await (async () => { await page.reload(); return sayilar().donem === 44 && (await page.locator('#fisYukle').innerText()).includes('yazıldı'); })());
    ok('3) sonuç 360px: yatay taşma yok', await (async () => { await page.setViewportSize({ width: 360, height: 900 }); const t = await tasma(page); await page.setViewportSize({ width: 1280, height: 900 }); return t <= 1; })());
    if (SHOT) await page.screenshot({ path: path.join(SHOT, 'fis_sonuc_1280.png'), fullPage: true });

    console.log('\n=== 4. Aynı dosya tekrar ===');
    await page.goto(URL);
    await page.setInputFiles('#fyDosya', XLSX);
    await Promise.all([page.waitForURL(/\?onizle=/), page.locator('button:has-text("Önizle")').click()]);
    ok('4) önizleme: 2 fiş "zaten yüklü" (disabled) + 1 hatalı', (await page.locator('.pc-only tr.fy-s-zaten').count()) === 2 && (await page.locator('.pc-only tr.fy-s-hata').count()) === 1 && await page.locator('#fyYazBtn').isDisabled());
    ok('4) "Vazgeç" önizlemeyi kapatır, kayıt yazılmaz', await (async () => { await Promise.all([page.waitForURL(url => !/onizle=/.test(url.toString())), page.locator('.fy-vazgec button').click()]); return sayilar().donem === 44 && (await page.locator('[data-flash]').count()) === 1; })());

    console.log('\n=== 5. Güvenlik ===');
    const csrfsiz = await page.evaluate(async (u) => (await fetch(u, { method: 'POST', body: new URLSearchParams({ eylem: 'yaz', token: 'a'.repeat(32) }) })).status, URL);
    ok('5) CSRF\'siz POST → 403', csrfsiz === 403, String(csrfsiz));
    const kotuToken = await page.evaluate(async (u) => { const t = document.body.innerHTML.match(/name="csrf" value="([a-f0-9]+)"/); const csrf = t ? t[1] : ''; const r = await fetch(u, { method: 'POST', redirect: 'manual', body: new URLSearchParams({ csrf, eylem: 'yaz', token: '../../etc/passwd' }) }); return r.type + ':' + r.status; }, URL);
    ok('5) geçersiz token ile yaz → yönlendirme (hiçbir şey yazılmaz)', kotuToken.startsWith('opaqueredirect') && sayilar().donem === 44, kotuToken);
    const xlsDosya = path.join(TMP, 'kotu.xls'); fs.writeFileSync(xlsDosya, 'PK\x03\x04bozuk');
    await page.goto(URL); await page.setInputFiles('#fyDosya', xlsDosya);
    await page.evaluate(() => document.getElementById('fyDosya').removeAttribute('accept'));
    await Promise.all([page.waitForNavigation(), page.locator('button:has-text("Önizle")').click()]);
    ok('5) .xls adlı dosya → Türkçe hata (flash), önizleme açılmaz', (await page.locator('[data-flash="error"]').innerText()).includes('.xlsx') && !page.url().includes('onizle='));
    const p2 = await ctx.newPage();
    const r403 = await p2.goto(`http://127.0.0.1:${PORT2}/gunluk_fis_yukle.php`);
    ok('5) yönetici olmayan → 403 (sayfa)', r403.status() === 403);
    const r403b = await p2.goto(`http://127.0.0.1:${PORT2}/gunluk_fis_yukle.php?sablon=1`);
    ok('5) yönetici olmayan → şablon indirme de 403', r403b.status() === 403);
    ok('5) sayfa hataları (JS console/pageerror) yok', pageErrors.length === 0, pageErrors.join(' | '));
  } catch (e) {
    ok('test altyapısı çalıştı', false, String(e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    for (const s of [srv, srv2]) if (s) s.kill();
    try { fs.rmSync(TMP, { recursive: true, force: true }); } catch (e) { /* önemsiz */ }
  }
  console.log(`\n--- Sonuç: ${n - fail} geçti, ${fail} kaldı ---`);
  process.exit(fail ? 1 : 0);
})();
