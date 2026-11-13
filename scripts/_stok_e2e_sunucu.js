// =========================================================
// scripts/_stok_e2e_sunucu.js — scripts/stok_ui_smoke.js için KÖPRÜ SUNUCU (test değil; include-only).
// Küçük bir Node http sunucusu: GERÇEK stok_*.php sayfalarını her istekte scripts/_stok_istek.php alt süreciyle
// (gerçek exit/header/http_response_code, gerçek config/helpers.php kabuğu + gerçek stok_core/stok_ui) çalıştırır; assets/ dosyalarını diskten sunar.
// Durum dosya SQLite'ta KALICIDIR (kart kaydı → liste değişti), oturum kimliği sabit (flash taşınır). Canlı DB'ye HİÇ bağlanmaz.
// =========================================================
'use strict';
const http = require('http'), fs = require('fs'), path = require('path');
const { execFile } = require('child_process');
const ROOT = path.resolve(__dirname, '..');
const MIME = { '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.json': 'application/json', '.woff2': 'font/woff2' };

function phpIstek(spec) {
  return new Promise(res => {
    execFile('php', [path.join(__dirname, '_stok_istek.php')], { cwd: ROOT, maxBuffer: 64 * 1024 * 1024, env: { ...process.env, STOK_T_SPEC: Buffer.from(JSON.stringify(spec)).toString('base64') } }, (err, out) => {
      const s = String(out || ''), p = s.lastIndexOf('\n@@STOK@@');
      if (p < 0) return res({ kod: 500, govde: s, basliklar: [], hata: 'alt süreç çıktısı çözülemedi' + (err ? ': ' + err.message : '') });
      try { const r = JSON.parse(s.slice(p + 9)); r.govde = s.slice(0, p) + r.govde; res(r); } catch (e) { res({ kod: 500, govde: s, basliklar: [], hata: 'JSON bozuk' }); }
    });
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
// Kabuk göreli yolları (../../assets/…, ../../stok_x.php) betiğin konumuna göre hesaplar → kök mutlak yola çevrilir.
const kokYol = h => h.replace(/(href|src|action)="(?:\.\.?\/)+((?:assets\/|stok_|transfer_fisleri|manifest\.json|index\.php)[^"]*)"/g, '$1="/$2"');

/** @param {{db:string, sid?:string, admin?:boolean}} o @returns {Promise<{url:string, kapat:()=>Promise<void>, istekler:any[]}>} */
async function baslat(o) {
  const istekler = [];
  let kuyruk = Promise.resolve();   // PHP istekleri sırayla (SQLite kilidi)
  const srv = http.createServer((rq, rs) => {
    const u = new URL(rq.url, 'http://x'), ad = u.pathname.replace(/^\/+/, '');
    if (/^(assets\/|manifest\.json$)/.test(ad) && !ad.includes('..')) {
      const f = path.join(ROOT, ad);
      if (fs.existsSync(f) && fs.statSync(f).isFile()) { rs.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); return fs.createReadStream(f).pipe(rs); }
      rs.writeHead(404); return rs.end();
    }
    if (!/^(stok_[a-z_]+|transfer_fisleri)\.php$/.test(ad) || !fs.existsSync(path.join(ROOT, ad))) { rs.writeHead(404, { 'Content-Type': 'text/plain' }); return rs.end('yok: ' + ad); }
    const parcalar = [];
    rq.on('data', c => parcalar.push(c));
    rq.on('end', () => {
      const get = {}; for (const [k, v] of u.searchParams) alanEkle(get, k, v);
      const post = rq.method === 'POST' ? govdeCoz(String(rq.headers['content-type'] || ''), Buffer.concat(parcalar)) : {};
      const server = { REQUEST_METHOD: rq.method };
      if (rq.headers['x-requested-with']) server.HTTP_X_REQUESTED_WITH = rq.headers['x-requested-with'];
      if (rq.headers.accept) server.HTTP_ACCEPT = rq.headers.accept;
      const spec = { depolar: o.depolar, dosya: ad, get, post, server, admin: o.admin !== false, sid: o.sid || 'stokE2E', db: o.db, perms: ['reports.export', 'records.read'] };
      kuyruk = kuyruk.then(() => phpIstek(spec)).then(r => {
        istekler.push({ yontem: rq.method, url: rq.url, kod: r.kod, post, hata: r.hata || null, ham: r.govde.length < 4000 ? r.govde : null });
        const bas = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };
        for (const h of r.basliklar || []) { const i = h.indexOf(':'); if (i > 0) bas[h.slice(0, i).trim()] = h.slice(i + 1).trim(); }
        for (const k of Object.keys(bas)) if (/^(set-cookie|cache-control)$/i.test(k) && k !== 'Cache-Control') delete bas[k];
        let g = r.govde;
        if (/html/i.test(bas['Content-Type'])) g = kokYol(g);
        if (r.hata) { rs.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' }); return rs.end('PHP HATA: ' + r.hata + '\n' + g.slice(0, 3000)); }
        rs.writeHead(r.kod || 200, bas); rs.end(g);
      });
    });
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  return { url: 'http://127.0.0.1:' + srv.address().port, istekler, kapat: () => new Promise(r => srv.close(() => r())) };
}
module.exports = { baslat };
