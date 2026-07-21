<?php
// =============================================================================
// HAL KAYIT — Yurt İçi Referans Teşhis Aracı (geçici / admin)
// Amaç: Sevk Etme + yurt içi satış akışı için gereken SALT-OKUNUR HKS
// servislerini (İller / İlçeler / Beldeler / İşyerleri / Kayıtlı Kişi) canlı
// sistemde güvenle test etmek. Bu servisler BİLDİRİM OLUŞTURMAZ, rüsum doğurmaz.
// Doğru etiket/alan adlarını gördükten sonra bu dosya silinecektir.
// Kullanım: giriş yaptıktan sonra /halkayit/tani.php adresini aç.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403);
    die('Bu sayfa yalnızca yöneticiye açıktır.');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HKS Yurt İçi Referans Teşhis</title>
<style>
  :root { --bg:#0f172a; --card:#1e293b; --line:#334155; --tx:#e2e8f0; --mut:#94a3b8; --acc:#f97316; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--tx); padding:16px; }
  h1 { font-size:18px; margin:0 0 4px; }
  p.mut { color:var(--mut); margin:0 0 16px; font-size:13px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px; margin-bottom:14px; }
  .card h2 { font-size:15px; margin:0 0 10px; color:var(--acc); }
  label { display:block; font-size:12px; color:var(--mut); margin:8px 0 3px; }
  input, select { width:100%; padding:9px 10px; border-radius:8px; border:1px solid var(--line); background:#0b1220; color:var(--tx); font-size:15px; }
  .row { display:flex; gap:10px; flex-wrap:wrap; }
  .row > div { flex:1 1 160px; }
  button { margin-top:10px; padding:9px 14px; border:0; border-radius:8px; background:var(--acc); color:#111; font-weight:700; cursor:pointer; font-size:14px; }
  button.sec { background:#475569; color:#fff; }
  pre { background:#0b1220; border:1px solid var(--line); border-radius:8px; padding:12px; overflow:auto; max-height:340px; font-size:12px; white-space:pre-wrap; word-break:break-word; }
  .ok { color:#4ade80; } .err { color:#f87171; }
</style>
</head>
<body>
  <h1>HKS Yurt İçi Referans Teşhis</h1>
  <p class="mut">Salt-okunur servisleri test eder — bildirim OLUŞTURMAZ. Amaç: doğru etiket/alan adlarını doğrulamak. Çıktı JSON'da <b>ham</b> alanı varsa liste boş dönmüş demektir; ham yanıtı bana ilet.</p>

  <div class="card">
    <label>Firma (kimlik doğrulama için)</label>
    <select id="firma"><option value="">— yükleniyor —</option></select>
  </div>

  <div class="card">
    <h2>1 · İller (parametresiz)</h2>
    <button onclick="cagir('iller', {})">İlleri Getir</button>
    <p class="mut">Beklenen: 81 il.</p>
  </div>

  <div class="card">
    <h2>2 · İlçeler</h2>
    <label>İl Id</label>
    <input id="ilId" inputmode="numeric" placeholder="örn. 6 (Ankara)">
    <button onclick="cagir('ilceler', {ilId: val('ilId')})">İlçeleri Getir</button>
  </div>

  <div class="card">
    <h2>3 · Beldeler</h2>
    <label>İlçe Id</label>
    <input id="ilceId" inputmode="numeric" placeholder="örn. 123">
    <button onclick="cagir('beldeler', {ilceId: val('ilceId')})">Beldeleri Getir</button>
  </div>

  <div class="card">
    <h2>4 · İşyerleri (karşı tarafın TC/VKN'sine göre)</h2>
    <div class="row">
      <div>
        <label>Tür</label>
        <select id="tur">
          <option value="depo">Depo</option>
          <option value="sube">Şube</option>
          <option value="halici">Hal İçi İşyeri</option>
        </select>
      </div>
      <div>
        <label>TC / Vergi No</label>
        <input id="isyTc" inputmode="numeric" placeholder="karşı tarafın TC/VKN">
      </div>
    </div>
    <button onclick="cagir('isyerleri', {tur: val('tur'), tcVkn: val('isyTc')})">İşyerlerini Getir</button>
  </div>

  <div class="card">
    <h2>5 · Kayıtlı Kişi Sorgu (karşı taraf doğrulama)</h2>
    <label>TC / Vergi No</label>
    <input id="kkTc" inputmode="numeric" placeholder="doğrulanacak TC/VKN">
    <button onclick="cagir('kayitli_kisi', {tcVkn: val('kkTc')})">Doğrula</button>
  </div>

  <div class="card">
    <h2>Çıktı</h2>
    <pre id="cikti">—</pre>
  </div>

<script>
const $ = s => document.querySelector(s);
const val = id => $('#'+id).value.trim();
const cikti = $('#cikti');

async function post(action, body) {
  const r = await fetch('api.php?action=' + action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify(body)
  });
  const t = await r.text();
  try { return { ok: r.ok, json: JSON.parse(t) }; }
  catch (e) { return { ok: r.ok, json: { _parseHatasi: t.slice(0, 1200) } }; }
}

async function cagir(action, extra) {
  const firmaId = val('firma');
  if (!firmaId) { cikti.textContent = 'Önce firma seç.'; return; }
  cikti.textContent = '⏳ ' + action + ' sorgulanıyor...';
  try {
    const { ok, json } = await post(action, Object.assign({ firmaId }, extra));
    cikti.innerHTML = (ok && !json.hata ? '<span class="ok">✔ ' : '<span class="err">✖ ')
      + action + '</span>\n\n'
      + JSON.stringify(json, null, 2);
  } catch (e) {
    cikti.innerHTML = '<span class="err">✖ Ağ hatası</span>\n' + e.message;
  }
}

(async () => {
  const { json } = await post('firmalar', {});
  const sel = $('#firma');
  sel.innerHTML = '<option value="">— firma seç —</option>';
  (json.firmalar || []).forEach(f => {
    const o = document.createElement('option');
    o.value = f.id; o.textContent = f.ad + ' (' + f.vergiNo + ')';
    sel.appendChild(o);
  });
})();
</script>
</body>
</html>
