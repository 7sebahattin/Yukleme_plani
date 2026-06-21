<?php
// HKS Künye / Bildirim / Kişi Sorgu
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo    = new HksRepository(db());
$client  = new HksClient($repo);
$s       = $repo->getSettings();
$env     = $s['environment'] ?? 'test';
$queries_enabled = $client->hasSettings() && hks_check_soap();
$urunler = $repo->getReferences('urun');

$recent_queries = $repo->listQueries(30);

render_header('HKS Sorgu Merkezi');
render_flash();
?>

<div class="hks-page" style="max-width:1100px;margin:0 auto">
<?php
$hks_active_tab = 'kunye_sorgu.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🔍 Sorgu Merkezi</h1>
        <p class="muted">HKS web servislerinden künye, bildirim, kişi sorgulaması</p>
    </div>
</div>

<?php if (!$queries_enabled): ?>
<div class="hks-warning-box" style="margin-bottom:16px">
    ⚠️ Sorgular için HKS ayarları yapılandırılmalı ve PHP SOAP extension aktif olmalıdır.
    <a href="ayarlar.php">Ayarlar →</a>
</div>
<?php else: ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;color:#1e40af">
    📡 Ortam: <strong><?= hks_h(strtoupper($env)) ?></strong> — Sorgular HKS WSDL'e gönderilir.
</div>
<?php endif; ?>

<!-- ── Bölüm 1: Künye / Bildirim ── -->
<p class="hks-section-label">Künye &amp; Bildirim Sorgu</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">

    <!-- Referans Künye Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">🏷️ Referans Künye</h3>
        <p style="font-size:.85rem;color:var(--muted)">BildirimServisReferansKunyeler</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Ürün <span style="color:var(--danger)">*</span></label>
            <?php if ($urunler): ?>
            <select id="ref_urun_id" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
                <?= hks_ref_options($urunler, '') ?>
            </select>
            <?php else: ?>
            <input type="text" id="ref_urun_id" placeholder="Ürün ID (referansları önce senkronize edin)"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
            <?php endif; ?>
        </div>
        <div style="margin-bottom:10px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Künye No <small class="muted">(boş = tümü)</small></label>
            <input type="text" id="ref_kunye_no" placeholder="Künye numarası..."
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <button class="btn btn-primary btn-sm" id="btnRefKunye" <?= !$queries_enabled ? 'disabled' : '' ?>>🔍 Sorgula</button>
        <div id="ref-kunye-result" class="hks-query-result" style="display:none;margin-top:10px"></div>
    </div>

    <!-- Bildirim Sorgu — tek bildirim no için HKS'de method YOK -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">📋 Bildirim Sorgu</h3>
        <p style="font-size:.85rem;color:var(--muted)">BildirimSorguIstek (liste)</p>
        <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:10px 12px;font-size:.85rem;color:#92400e">
            ℹ️ HKS'de <strong>tek bildirim numarası</strong> için ayrı bir sorgu servisi yoktur.
            Bir bildirimi bulmak için aşağıdaki <strong>Bildirim Listeleri</strong> bölümünde
            Künye No (+ tarih aralığı) ile sorgulayın.
        </div>
    </div>

    <!-- Toplu Künye -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">📦 Toplu Künye</h3>
        <p style="font-size:.85rem;color:var(--muted)">TopluKunyeIstek — plaka / belge / bildirim tarihi (tarih aralığı değil)</p>
        <div style="margin-bottom:8px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Araç Plaka</label>
            <input type="text" id="tk_plaka" placeholder="34ABC123" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div style="margin-bottom:8px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Belge No</label>
            <input type="text" id="tk_belge" placeholder="İrsaliye / fatura no" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div style="margin-bottom:10px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Bildirim Tarihi</label>
            <input type="date" id="tk_tarih" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <button class="btn btn-primary btn-sm" id="btnTopluKunye" <?= !$queries_enabled ? 'disabled' : '' ?>>🔍 Sorgula</button>
        <div id="toplu-kunye-result" class="hks-query-result" style="display:none;margin-top:10px"></div>
    </div>

    <!-- Bildirim Etiketi -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">🏷 Bildirim Etiketi</h3>
        <p style="font-size:.85rem;color:var(--muted)">BildirimServisBildirimEtiket — response tipine göre PDF/HTML olabilir.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Bildirim No veya Künye No</label>
            <input type="text" id="etiket_no" placeholder="Bildirim veya künye no..."
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <button class="btn btn-primary btn-sm" id="btnEtiket" <?= !$queries_enabled ? 'disabled' : '' ?>>🔍 Sorgula</button>
        <div id="etiket-result" class="hks-query-result" style="display:none;margin-top:10px"></div>
    </div>

</div>

<!-- ── Bölüm 2: Kişi Sorgu ── -->
<p class="hks-section-label">Kişi / Firma Sorgu</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">

    <!-- Kayıtlı Kişi Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">👤 Kayıtlı Kişi Sorgu</h3>
        <p style="font-size:.85rem;color:var(--muted)">BildirimServisKayitliKisiSorgu — yalnızca <strong>KayitliKisiMi</strong> + <strong>Sifatlari</strong> döner; <strong>ad/ünvan döndürmez</strong>.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">TC / VKN</label>
            <input type="text" id="kisi_tc_vkn" placeholder="11 haneli TC veya 10 haneli VKN"
                   maxlength="11"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <button class="btn btn-primary btn-sm" id="btnKisiSorgu" <?= !$queries_enabled ? 'disabled' : '' ?>>🔍 Sorgula</button>
        <div id="kisi-result" class="hks-query-result" style="display:none;margin-top:10px"></div>
    </div>

</div>

<!-- ── Bölüm 3: Bildirim Listeleri ── -->
<p class="hks-section-label">Bildirim Listeleri</p>
<div class="card" style="padding:20px;margin-bottom:24px">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:14px;align-items:end">
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Başlangıç Tarihi</label>
            <input type="date" id="bl_baslangic" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem"
                   value="<?= date('Y-m-01') ?>">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Bitiş Tarihi</label>
            <input type="date" id="bl_bitis" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem"
                   value="<?= date('Y-m-d') ?>">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Künye No <small class="muted">(boş = tümü)</small></label>
            <input type="text" id="bl_kunye" placeholder="Boş = tümü"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" id="btnYaptigim" <?= !$queries_enabled ? 'disabled' : '' ?>>📤 Yaptıklarım</button>
            <button class="btn btn-ghost btn-sm" id="btnBanaYapilan" <?= !$queries_enabled ? 'disabled' : '' ?>>📥 Bana Yapılanlar</button>
        </div>
    </div>
    <div id="bl-result" class="hks-query-result" style="display:none"></div>
</div>

<!-- Son Sorgular -->
<p class="hks-section-label">Son Sorgular</p>
<?php if ($recent_queries): ?>
<div class="table-wrap">
<table style="width:100%;border-collapse:collapse;font-size:.86rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tarih</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tür</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Değer</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Sonuç</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($recent_queries as $q): ?>
    <tr>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);white-space:nowrap;color:var(--muted);font-size:.82rem">
            <?= hks_h(date('d.m.Y H:i', strtotime($q['created_at']))) ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($q['query_type']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= hks_h($q['query_value']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)">
            <span style="color:<?= $q['result_status'] === 'ok' ? 'var(--success)' : 'var(--danger)' ?>"><?= hks_h($q['result_status']) ?></span>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else: ?>
<div style="color:var(--muted);font-size:.88rem;padding:12px 0">Henüz sorgu yapılmamış.</div>
<?php endif; ?>

</div>

<style>
.hks-query-result { font-size:.85rem; padding:10px 12px; border-radius:6px; }
.hks-query-result.ok  { background:#f0fdf4; border:1px solid var(--success); color:#065f46; }
.hks-query-result.err { background:#fef2f2; border:1px solid var(--danger);  color:#991b1b; }
</style>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function hksQuery(action, payload, resultEl, btn) {
    btn.disabled = true;
    btn.textContent = '⏳';
    resultEl.style.display = 'none';
    resultEl.className = 'hks-query-result';

    fetch('ajax.php?action=' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrfToken) + '&' +
              Object.entries(payload).map(([k,v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&')
    })
    .then(r => r.json())
    .then(data => {
        resultEl.style.display = 'block';
        resultEl.classList.add(data.ok ? 'ok' : 'err');
        if (data.ok) {
            var d = data.data ?? data;
            resultEl.innerHTML = '✅ ' +
                '<details style="margin-top:4px"><summary style="cursor:pointer;font-size:.82rem">Yanıtı Göster (' + (Array.isArray(d) ? d.length + ' kayıt' : 'detay') + ')</summary>' +
                '<pre style="margin:6px 0 0;font-size:.78rem;white-space:pre-wrap;overflow-x:auto;max-height:300px">' +
                JSON.stringify(d, null, 2) + '</pre></details>';
        } else {
            resultEl.innerHTML = '❌ ' + (data.message || 'Hata');
            if (data.methods_available && data.methods_available.length) {
                resultEl.innerHTML += '<br><small>Mevcut metodlar: ' + data.methods_available.slice(0,20).join(', ') + '</small>';
            }
        }
    })
    .catch(() => {
        resultEl.style.display = 'block';
        resultEl.classList.add('err');
        resultEl.textContent = 'İstek gönderilemedi.';
    })
    .finally(() => { var orig = btn.dataset.orig || btn.textContent; btn.textContent = btn.dataset.label || '🔍 Sorgula'; btn.disabled = false; });
}

// Referans Künye
document.getElementById('btnRefKunye').dataset.label = '🔍 Sorgula';
document.getElementById('btnRefKunye').addEventListener('click', function() {
    var urunId = document.getElementById('ref_urun_id').value.trim();
    if (!urunId) { alert('Ürün seçin — BildirimServisReferansKunyeler UrunId zorunlu kılıyor.'); return; }
    hksQuery('query_referans_kunye', {
        kunye_no: document.getElementById('ref_kunye_no').value.trim(),
        urun_id:  urunId,
    }, document.getElementById('ref-kunye-result'), this);
});

// Bildirim Sorgu
// Bildirim Sorgu (tek no) kaldırıldı — HKS'de ayrı method yok.

// Toplu Künye — TopluKunyeIstek: plaka / belge / bildirim tarihi
document.getElementById('btnTopluKunye').dataset.label = '🔍 Sorgula';
document.getElementById('btnTopluKunye').addEventListener('click', function() {
    var plaka=document.getElementById('tk_plaka').value.trim();
    var belge=document.getElementById('tk_belge').value.trim();
    var tarih=document.getElementById('tk_tarih').value;
    if (!plaka && !belge && !tarih) { alert('Araç plakası, belge no veya bildirim tarihinden en az biri gerekli.'); return; }
    hksQuery('query_toplu_kunye', {
        arac_plaka: plaka, belge_no: belge, bildirim_tarihi: tarih
    }, document.getElementById('toplu-kunye-result'), this);
});

// Bildirim Etiketi
document.getElementById('btnEtiket').dataset.label = '🔍 Sorgula';
document.getElementById('btnEtiket').addEventListener('click', function() {
    var no = document.getElementById('etiket_no').value.trim();
    if (!no) { alert('Bildirim veya künye no girin.'); return; }
    hksQuery('query_bildirim_etiket', {bildirim_no: no},
        document.getElementById('etiket-result'), this);
});

// Kişi Sorgu
document.getElementById('btnKisiSorgu').dataset.label = '🔍 Sorgula';
document.getElementById('btnKisiSorgu').addEventListener('click', function() {
    var tc = document.getElementById('kisi_tc_vkn').value.trim();
    if (!tc) { alert('TC veya VKN girin.'); return; }
    hksQuery('query_kisi_sorgu', {tc_vkn: tc},
        document.getElementById('kisi-result'), this);
});

// Bildirim Listeleri
document.getElementById('btnYaptigim').dataset.label = '📤 Yaptıklarım';
document.getElementById('btnYaptigim').addEventListener('click', function() {
    hksQuery('query_yaptigim_bildirimler', {
        baslangic: document.getElementById('bl_baslangic').value,
        bitis:     document.getElementById('bl_bitis').value,
        kunye_no:  document.getElementById('bl_kunye').value.trim(),
    }, document.getElementById('bl-result'), this);
});

document.getElementById('btnBanaYapilan').dataset.label = '📥 Bana Yapılanlar';
document.getElementById('btnBanaYapilan').addEventListener('click', function() {
    hksQuery('query_bana_yapilan_bildirimler', {
        baslangic: document.getElementById('bl_baslangic').value,
        bitis:     document.getElementById('bl_bitis').value,
        kunye_no:  document.getElementById('bl_kunye').value.trim(),
    }, document.getElementById('bl-result'), this);
});
</script>

<?php render_footer(); ?>
