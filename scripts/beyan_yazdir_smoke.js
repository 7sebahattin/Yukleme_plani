'use strict';
// "İşlem Bekleyen Beyanlar" kartı — Yazdır / PDF Gönder (v357). Gerçek PHP render + gerçek tarayıcı.
// Canlı DB / ağ YOK: sayfa bellek içi SQLite fixture'ından (beyan_ui_smoke.php --render-liste) gelir,
// jsPDF + html2canvas yerel dosyalardan sunulur.
//   PW_PATH=/path/to/playwright PW_CHANNEL=msedge node scripts/beyan_yazdir_smoke.js
const fs = require('fs'), os = require('os'), path = require('path');
const {spawnSync} = require('child_process');
let chromium;
try { ({chromium} = require(process.env.PW_PATH || 'playwright')); }
catch (e) { console.log('Playwright yok — atlandı'); process.exit(0); }
const root = path.resolve(__dirname, '..');
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'beyan-yazdir-'));
const css = fs.readFileSync(path.join(root, 'assets/style.css'), 'utf8');
const SCRIPT_RE = /<script type="application\/json" id="bekleyenYazdirVeri">([\s\S]*?)<\/script>/;
let count = 0;
function ok(value, label) { if (!value) throw Error('FAIL: ' + label); count++; console.log('OK ' + label); }

function renderListe() {
  const file = path.join(scratch, 'liste.html');
  const r = spawnSync('php', ['scripts/beyan_ui_smoke.php', '--render-liste', file], {cwd: root, encoding: 'utf8'});
  if (r.status !== 0) throw Error(r.stderr || r.stdout);
  return fs.readFileSync(file, 'utf8').replace(/<link[^>]*>/g, '').replace(/<script\b[^>]*src="(?!assets\/beyan_yazdir)[^"]*"[^>]*><\/script>/g, '')
    .replace('</head>', '<style>' + css + '</style></head>');
}
// Çok sayfalı PDF testi için satırları çoğalt (kullanıcı verisi değil, düzen testi).
function cogalt(html, kat) {
  const m = html.match(SCRIPT_RE);
  const v = JSON.parse(m[1]);
  const satirlar = [];
  for (let i = 0; i < kat; i++) v.satirlar.forEach(s => satirlar.push(s.slice()));
  v.satirlar = satirlar; v.adet = satirlar.length;
  return html.replace(SCRIPT_RE, () => '<script type="application/json" id="bekleyenYazdirVeri">' + JSON.stringify(v) + '</script>');
}

const SERVE = {
  '/assets/beyan_yazdir.js': path.join(root, 'assets/beyan_yazdir.js'),
  '/halkayit/html2canvas.min.js': path.join(root, 'halkayit/html2canvas.min.js'),
  '/halkayit/jspdf.umd.min.js': path.join(root, 'halkayit/jspdf.umd.min.js'),
};
async function kur(context, html) {
  await context.route('**/*', route => {
    const u = new URL(route.request().url());
    if (SERVE[u.pathname]) return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(SERVE[u.pathname])});
    return route.fulfill({contentType: 'text/html', body: html});
  });
}
const pdfSayfa = buf => (buf.toString('latin1').match(/\/Type\s*\/Page\b(?!s)/g) || []).length;

(async () => {
  const browser = await chromium.launch(process.env.PW_CHANNEL ? {channel: process.env.PW_CHANNEL} : {});
  try {
    const html = renderListe();

    // ── Masaüstü ──────────────────────────────────────────────────────────────
    {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, acceptDownloads: true});
      await kur(ctx, html);
      const page = await ctx.newPage();
      const errors = []; page.on('pageerror', e => errors.push(e.message));
      await page.goto('https://beyan.test/beyanlar.php');
      const yz = page.locator('#bekleyenYazdirBtn'), pdf = page.locator('#bekleyenPdfBtn');
      ok(await yz.isVisible() && await pdf.isVisible(), 'masaüstü: Yazdır ve PDF düğmeleri kart başlığında görünür');
      ok((await pdf.textContent()).includes('PDF İndir'), 'masaüstü: PDF düğmesi "PDF İndir" der (telefonda "PDF Gönder")');
      const kutuBas = await page.locator('.beyan-blok-acik .beyan-blok-head').boundingBox();
      const yzKutu = await yz.boundingBox();
      ok(yzKutu.y >= kutuBas.y && yzKutu.y + yzKutu.height <= kutuBas.y + kutuBas.height + 4, 'düğmeler İşlem Bekleyen kartın başlık satırının içinde');
      ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'masaüstü: yatay taşma yok');

      // Yazdır → yeni pencere + rapor
      const [popup] = await Promise.all([ctx.waitForEvent('page'), yz.click()]);
      await popup.waitForLoadState();
      await popup.evaluate(() => { window.print = () => { window.__yazdirildi = true; }; });
      const basliklar = await popup.locator('#byzTablo thead th').allTextContents();
      for (const b of ['Tarih', 'Parti No', 'Ürün / Çeşit', 'Palet', 'Kasa', 'Brüt KG', 'Net KG', 'Alıcı', 'Marka', 'Çıkış Depo', 'HKS Firma', 'Şoför Adı', 'Şoför Telefonu', 'Çekici Plaka', 'Dorse Plaka']) {
        ok(basliklar.includes(b), 'yazdırma raporunda sütun var: ' + b);
      }
      ok(!basliklar.includes('Plaka') && basliklar.slice(-4).join('|') === 'Şoför Adı|Şoför Telefonu|Çekici Plaka|Dorse Plaka', 'tek "Plaka" sütunu yok; şoför telefonunun yanında Çekici + Dorse Plaka');
      ok(!basliklar.includes('Ülke') && !basliklar.includes('Durum'), 'Ülke ve Durum sütunları yazdırmada yok');
      // Aynı dorse plakası → Parti No hücreleri aynı renk; plakasız satır renksiz.
      const renkler = await popup.locator('#byzTablo tbody tr').evaluateAll((trs, pIx) => trs.map(tr => {
        const td = tr.children[pIx]; return {renk: td.style.background || '', dorse: tr.lastElementChild.textContent};
      }), basliklar.indexOf('Parti No'));
      const grupluRenk = renkler.filter(r => r.dorse === '34ABC123').map(r => r.renk);
      ok(grupluRenk.length >= 2 && grupluRenk.every(r => r && r === grupluRenk[0]), 'aynı dorse plakalı Parti No hücreleri aynı renkte (' + grupluRenk.length + ' satır)');
      ok(renkler.filter(r => r.dorse === '—').every(r => !r.renk), 'plakasız satırın Parti No hücresi renksiz');
      const govde = await popup.locator('#pdfIcerik').textContent();
      ok(govde.includes('YAZDIR SOFOR') && govde.includes('+90 555 000 00 00') && govde.includes('55CEK55') && govde.includes('34ABC123'), 'rapor şoför adı + telefonu + çekici/dorse plakayı basar');
      ok(govde.includes('TOPLAM') && govde.includes('İşlem Bekleyen Beyanlar'), 'rapor başlık + TOPLAM satırı taşır');
      const css0 = await popup.evaluate(() => document.head.textContent);
      ok(/@page\{size:A4 landscape/.test(css0), 'yazdırma sayfası yatay A4');
      ok(/Bekleyen Beyanlar \d{4}-\d{2}-\d{2}/.test(await popup.title()), 'pencere başlığı = PDF dosya adı');
      ok(await popup.locator('.byz-btn').isVisible(), 'pencerede Yazdır düğmesi var (yazdırmada gizlenir)');
      await popup.close();

      // PDF İndir (masaüstü) → dosya iner
      const [dl] = await Promise.all([page.waitForEvent('download', {timeout: 30000}), pdf.click()]);
      ok(/^Bekleyen Beyanlar \d{4}-\d{2}-\d{2}\.pdf$/.test(dl.suggestedFilename()), 'indirilen dosya adı: ' + dl.suggestedFilename());
      const buf = fs.readFileSync(await dl.path());
      ok(buf.slice(0, 5).toString() === '%PDF-' && buf.length > 5000, 'indirilen dosya gerçek PDF (' + buf.length + ' bayt)');
      ok(/\/MediaBox\s*\[\s*0\s+0\s+841\.\d+\s+595\.\d+\s*\]/.test(buf.toString('latin1')), 'PDF yatay A4 (841×595)');
      await page.waitForFunction(() => document.querySelector('#bekleyenPdfBtn').textContent.includes('PDF İndir') && !document.querySelector('#bekleyenPdfBtn').disabled);
      ok(await page.locator('body > div[aria-hidden="true"]').count() === 0, 'PDF sonrası geçici yakalama öğesi temizlendi');
      ok(errors.length === 0, 'masaüstü: sayfa hatası yok ' + errors.join('|'));
      await ctx.close();
    }

    // ── İki farklı dorse grubu → iki farklı renk ─────────────────────────────
    {
      const m = html.match(SCRIPT_RE), v = JSON.parse(m[1]);
      v.satir_grup = v.satirlar.map((_, i) => i < 2 ? 0 : (i < 4 ? 1 : null));
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}});
      await kur(ctx, html.replace(SCRIPT_RE, () => '<script type="application/json" id="bekleyenYazdirVeri">' + JSON.stringify(v) + '</script>'));
      const page = await ctx.newPage();
      await page.goto('https://beyan.test/beyanlar.php');
      const [popup] = await Promise.all([ctx.waitForEvent('page'), page.locator('#bekleyenYazdirBtn').click()]);
      await popup.waitForLoadState();
      const r = await popup.locator('#byzTablo td.byz-grup').evaluateAll(tds => tds.map(td => td.style.background));
      ok(r.length === 4 && r[0] === r[1] && r[2] === r[3] && r[0] !== r[2], 'iki dorse grubu iki farklı renk alır');
      ok(await popup.evaluate(() => /print-color-adjust:exact/.test(document.head.textContent)), 'yazıcıda arka plan rengi korunur (print-color-adjust)');
      await ctx.close();
    }

    // ── Çok sayfalı PDF: satır ortadan bölünmez, 2. sayfadan itibaren başlık tekrarlanır ──
    {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, acceptDownloads: true});
      await kur(ctx, cogalt(html, 14));   // ~84 satır
      const page = await ctx.newPage();
      await page.goto('https://beyan.test/beyanlar.php');
      const [dl] = await Promise.all([page.waitForEvent('download', {timeout: 60000}), page.locator('#bekleyenPdfBtn').click()]);
      const buf = fs.readFileSync(await dl.path());
      ok(pdfSayfa(buf) >= 2, 'çok satırlı rapor birden çok PDF sayfasına bölünür (' + pdfSayfa(buf) + ' sayfa)');
      await ctx.close();
    }

    // ── Telefon: Web Share API ile paylaşım (WhatsApp paylaşım listesi) ───────
    {
      const ctx = await browser.newContext({
        viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true,
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
      });
      await ctx.addInitScript(() => {
        window.__paylasimlar = []; window.__ilkRed = false;
        navigator.canShare = d => !!(d && d.files && d.files.length);
        navigator.share = async d => {
          if (window.__ilkRed) { window.__ilkRed = false; const e = new Error('izin'); e.name = 'NotAllowedError'; throw e; }
          const f = d.files[0];
          window.__paylasimlar.push({ad: f.name, tur: f.type, boyut: f.size, baslik: d.title, metin: d.text});
        };
      });
      await kur(ctx, html);
      const page = await ctx.newPage();
      const errors = []; page.on('pageerror', e => errors.push(e.message));
      await page.goto('https://beyan.test/beyanlar.php');
      const pdf = page.locator('#bekleyenPdfBtn'), yz = page.locator('#bekleyenYazdirBtn');
      ok((await pdf.textContent()).includes('PDF Gönder'), 'telefon: düğme "PDF Gönder" der');
      ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'telefon: yatay taşma yok');
      const bk = await page.locator('.beyan-blok-acik .beyan-blok-head').boundingBox();
      const k1 = await yz.boundingBox(), k2 = await pdf.boundingBox();
      ok(k1.x >= 0 && k2.x + k2.width <= 390 && Math.abs(k1.y - k2.y) < 4 && k1.height >= 36, 'telefon: iki düğme yan yana, ekrana sığar, dokunması kolay');
      ok(k1.y >= bk.y && k2.y + k2.height <= bk.y + bk.height + 4, 'telefon: düğmeler kart başlığının içinde');

      await pdf.click();
      await page.waitForFunction(() => window.__paylasimlar.length === 1, null, {timeout: 30000});
      const p = await page.evaluate(() => window.__paylasimlar[0]);
      ok(p.tur === 'application/pdf' && /^Bekleyen Beyanlar .*\.pdf$/.test(p.ad) && p.boyut > 5000, 'telefon: PDF dosyası paylaşım sayfasına verilir (' + p.ad + ')');
      ok(/İşlem Bekleyen Beyanlar/.test(p.metin) && /beyan/.test(p.metin), 'telefon: paylaşım metni özeti taşır');
      ok(!(await pdf.isDisabled()) && (await pdf.textContent()).includes('PDF Gönder'), 'telefon: paylaşımdan sonra düğme normale döner');

      // iOS: dokunma izni zaman aşımı → PDF hazır, tek dokunuşla paylaş
      await page.evaluate(() => { window.__ilkRed = true; });
      await pdf.click();
      await page.waitForFunction(() => document.querySelector('#bekleyenPdfBtn').textContent.includes('PDF hazır'), null, {timeout: 30000});
      ok(await page.evaluate(() => window.__paylasimlar.length) === 1, 'telefon: izin reddinde PDF paylaşılmadı, "PDF hazır — Paylaş" çıktı');
      await pdf.click();
      await page.waitForFunction(() => window.__paylasimlar.length === 2);
      ok((await pdf.textContent()).includes('PDF Gönder'), 'telefon: hazır PDF ikinci dokunuşla paylaşıldı ve düğme sıfırlandı');
      ok(errors.length === 0, 'telefon: sayfa hatası yok ' + errors.join('|'));
      await ctx.close();
    }
    console.log('\n' + count + ' kontrol geçti');
  } finally {
    await browser.close();
  }
})().catch(e => { console.error(e.message || e); process.exit(1); });
