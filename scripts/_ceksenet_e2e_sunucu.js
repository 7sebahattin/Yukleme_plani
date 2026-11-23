// =========================================================
// scripts/_ceksenet_e2e_sunucu.js — scripts/ceksenet_ui_smoke.js için KÖPRÜ SUNUCU (test değil; include-only).
// Küçük bir Node http sunucusu: GERÇEK cek_*.php / senet_dekontu.php sayfalarını her istekte scripts/_ceksenet_istek.php alt süreciyle
// (gerçek exit/header/http_response_code, gerçek config/helpers.php kabuğu + gerçek ceksenet_core/ceksenet_ui) çalıştırır; assets/ dosyalarını diskten sunar.
// Durum dosya SQLite'ta KALICIDIR (kart kaydı → liste değişti), oturum kimliği sabit (flash taşınır). Canlı DB'ye HİÇ bağlanmaz.
// =========================================================
'use strict';
const http = require('http'), fs = require('fs'), path = require('path');
const { execFile } = require('child_process');
const ROOT = path.resolve(__dirname, '..');
const COCUKLAR = new Set();   // çalışan PHP alt süreçleri; kapat() hepsini öldürür (artık süreç kalmaz)
const PHP_ZAMAN_ASIMI = 60000;   // tek PHP isteği en çok 60 sn; asılırsa SIGKILL + 500 (köprü kuyruğu sonsuza dek tıkanmaz)
const MIME = { '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.json': 'application/json', '.woff2': 'font/woff2' };

function phpIstek(spec) {
  return new Promise(res => {
    const cocuk = execFile('php', [path.join(__dirname, '_ceksenet_istek.php')], { cwd: ROOT, maxBuffer: 64 * 1024 * 1024, timeout: PHP_ZAMAN_ASIMI, killSignal: 'SIGKILL', env: { ...process.env, CEKSENET_T_SPEC: Buffer.from(JSON.stringify(spec)).toString('base64') } }, (err, out) => {
      const s = String(out || ''), p = s.lastIndexOf('\n@@CEKSENET@@');
      if (p < 0) return res({ kod: 500, govde: s, basliklar: [], hata: (err && err.killed ? 'PHP alt süreç zaman aşımı (' + (PHP_ZAMAN_ASIMI / 1000) + ' sn) — SIGKILL' : 'alt süreç çıktısı çözülemedi') + (err ? ': ' + err.message : '') });
      try { const r = JSON.parse(s.slice(p + 13)); r.govde = s.slice(0, p) + r.govde; res(r); } catch (e) { res({ kod: 500, govde: s, basliklar: [], hata: 'JSON bozuk' }); }
    });
    COCUKLAR.add(cocuk); cocuk.on('close', () => COCUKLAR.delete(cocuk));
  });
}

// multipart/form-data (yalnız alanlar) + urlencoded ayrıştırıcı; `a[]` → dizi, `a[b]` → nesne.
function alanEkle(o, ad, deger) {
  const m = ad.match(/^([^\[]+)\[([^\]]*)\]$/);
  if (!m) { o[ad] = deger; return; }
  if (m[2] === '') (o[m[1]] = Array.isArray(o[m[1]]) ? o[m[1]] : []).push(deger); else (o[m[1]] = o[m[1]] || {})[m[2]] = deger;
}
function govdeCoz(tip, buf) {
  const o = {};
  if (/multipart\/form-data/i.test(tip)) {
    const b = (tip.match(/boundary=("?)([^";]+)\1/i) || [])[2];
    if (!b) return o;
    for (const parca of buf.toString('latin1').split('--' + b).slice(1)) {
      if (parca.startsWith('--')) break;
      const i = parca.indexOf('\r\n\r\n'); if (i < 0) continue;
      const ad = (parca.slice(0, i).match(/name="([^"]*)"/) || [])[1]; if (ad === undefined) continue;
      alanEkle(o, ad, Buffer.from(parca.slice(i + 4).replace(/\r\n$/, ''), 'latin1').toString('utf8'));
    }
  } else {
    for (const [k, v] of new URLSearchParams(buf.toString('utf8'))) alanEkle(o, k, v);
  }
  return o;
}
// Kabuk göreli yolları (../../assets/…, ../../cek_x.php) betiğin konumuna göre hesaplar → kök mutlak yola çevrilir.
const kokYol = h => h.replace(/(href|src|action)="(?:\.\.?\/)+((?:assets\/|cek_|senet_|kasa_|cari_|manifest\.json|index\.php)[^"]*)"/g, '$1="/$2"');

/** @param {{db:string, sid?:string, admin?:boolean}} o @returns {Promise<{url:string, kapat:()=>Promise<void>, istekler:any[]}>} */
async function baslat(o) {
  const istekler = [];
  // Oturum dosyası (sys_get_temp_dir()/sess_<sid>) koşular arası PAYLAŞILMASIN: sid her başlatmada benzersiz (önceki/eşzamanlı koşunun artık flash'ı ya da kilidi karışmaz).
  const sid = String((o.sid || 'cekE2E')).replace(/[^A-Za-z0-9]/g, '') + process.pid.toString(36) + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  const t0 = Date.now(), izle = !!process.env.E2E_IZLE;
  let kuyruk = Promise.resolve();   // PHP istekleri sırayla (SQLite kilidi)
  const srv = http.createServer((rq, rs) => {
    const u = new URL(rq.url, 'http://x'), ad = u.pathname.replace(/^\/+/, '');
    if (izle) { const gt = Date.now(); console.error('[köprü +' + (gt - t0) + 'ms] ► ' + rq.method + ' ' + rq.url + ' (soket ' + rq.socket.remotePort + ', sokette ' + (rq.socket._cs_n = (rq.socket._cs_n || 0) + 1) + '. istek)'); rs.on('close', () => console.error('[köprü +' + (Date.now() - t0) + 'ms] ◄ kapandı ' + rq.url + ' (' + (Date.now() - gt) + 'ms, finished=' + rs.writableFinished + ')')); }
    if (/^(assets\/|manifest\.json$)/.test(ad) && !ad.includes('..')) {
      const f = path.join(ROOT, ad);
      if (fs.existsSync(f) && fs.statSync(f).isFile()) { rs.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); return fs.createReadStream(f).pipe(rs); }
      rs.writeHead(404); return rs.end();
    }
    if (!/^(cek_(dekontu|tanimlari|kaydet|cikti)|senet_dekontu)\.php$/.test(ad) || !fs.existsSync(path.join(ROOT, ad))) { rs.writeHead(404, { 'Content-Type': 'text/plain' }); return rs.end('yok: ' + ad); }
    const parcalar = [];
    rq.on('data', c => parcalar.push(c));
    rq.on('end', () => {
      const get = {}; for (const [k, v] of u.searchParams) alanEkle(get, k, v);
      const post = rq.method === 'POST' ? govdeCoz(String(rq.headers['content-type'] || ''), Buffer.concat(parcalar)) : {};
      const server = { REQUEST_METHOD: rq.method };
      if (rq.headers['x-requested-with']) server.HTTP_X_REQUESTED_WITH = rq.headers['x-requested-with'];
      if (rq.headers.accept) server.HTTP_ACCEPT = rq.headers.accept;
      const spec = { depolar: o.depolar, dosya: ad, get, post, server, admin: o.admin !== false, sid, db: o.db, perms: ['reports.export', 'records.read'] };
      const gelis = Date.now();
      kuyruk = kuyruk.then(() => { const b = Date.now(); return phpIstek(spec).then(r => { r.sure = Date.now() - b; r.bekleme = b - gelis; return r; }); }).then(r => {
        istekler.push({ yontem: rq.method, url: rq.url, kod: r.kod, post, hata: r.hata || null, sure: r.sure, bekleme: r.bekleme, ham: r.govde.length < 4000 ? r.govde : null });
        if (izle) console.error('[köprü +' + (Date.now() - t0) + 'ms] ' + rq.method + ' ' + rq.url + ' → ' + r.kod + ' (php ' + r.sure + 'ms, kuyrukta ' + r.bekleme + 'ms, ' + Buffer.byteLength(r.govde || '') + ' bayt)' + (r.hata ? ' HATA ' + r.hata : ''));
        const bas = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };
        for (const h of r.basliklar || []) { const i = h.indexOf(':'); if (i > 0) bas[h.slice(0, i).trim()] = h.slice(i + 1).trim(); }
        for (const k of Object.keys(bas)) if (/^(set-cookie|cache-control)$/i.test(k) && k !== 'Cache-Control') delete bas[k];
        let g = r.govde;
        if (/html/i.test(bas['Content-Type'])) g = kokYol(g);
        if (r.hata) { rs.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' }); return rs.end('PHP HATA: ' + r.hata + '\n' + g.slice(0, 3000)); }
        rs.writeHead(r.kod || 200, bas); rs.end(g);
      }).catch(e => { try { rs.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' }); rs.end('KÖPRÜ HATA: ' + (e && e.message)); } catch (x) { /* soket kapalı */ } });   // kuyruk zinciri ASLA reddedilmiş kalmaz (kalsaydı sonraki tüm istekler sonsuza dek beklerdi)
    });
  });
  if (izle) srv.on('connection', so => { const a = Date.now(), pn = so.remotePort; console.error('[köprü +' + (a - t0) + 'ms] ○ soket açıldı ' + pn); so.on('close', () => console.error('[köprü +' + (Date.now() - t0) + 'ms] ● soket kapandı ' + pn + ' (ömür ' + (Date.now() - a) + 'ms, ' + (so._cs_n || 0) + ' istek, bayt okunan ' + so.bytesRead + ')')); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  return { url: 'http://127.0.0.1:' + srv.address().port, istekler, kapat: () => new Promise(r => { for (const c of COCUKLAR) { try { c.kill('SIGKILL'); } catch (e) { /* bitti */ } } try { srv.closeAllConnections(); } catch (e) { /* eski Node */ } srv.close(() => r()); try { fs.unlinkSync(path.join(require('os').tmpdir(), 'sess_' + sid)); } catch (e) { /* yok */ } }) };
}
module.exports = { baslat };
