<?php
// HKS Canlı Veri Çekme Merkezi
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo    = new HksRepository(db());
$client  = new HksClient($repo);
$s       = $repo->getSettings();
$env     = $s['environment'] ?? 'test';
$firma_vkn = $s['firma_vkn'] ?? '';
$queries_enabled = $client->hasSettings() && hks_check_soap();

$env_label = $env === 'live' ? '🔴 CANLI' : '🟡 TEST';
$env_color = $env === 'live' ? '#dc2626' : '#d97706';

render_header('HKS Veri Çekme Merkezi');
render_flash();
?>
<div class="hks-page" style="max-width:1100px;margin:0 auto">
<?php
$hks_active_tab = 'veri_cek.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>📡 Veri Çekme Merkezi</h1>
        <p class="muted">HKS canlı servislerinden veri çek, yerel cache ve stok güncelle</p>
    </div>
    <div class="page-head-actions">
        <span style="font-weight:700;color:<?= $env_color ?>;font-size:.9rem;padding:6px 12px;background:<?= $env === 'live' ? '#fef2f2' : '#fffbeb' ?>;border-radius:20px;border:1px solid <?= $env_color ?>"><?= $env_label ?></span>
    </div>
</div>

<?php if (!$queries_enabled): ?>
<div class="hks-warning-box" style="margin-bottom:16px">
    ⚠️ HKS ayarları yapılandırılmamış veya PHP SOAP extension eksik. <a href="ayarlar.php">Ayarlar →</a>
</div>
<?php endif; ?>

<?php if ($firma_vkn === '' && $queries_enabled): ?>
<div class="hks-warning-box" style="margin-bottom:12px">
    ℹ️ <strong>Firma VKN tanımlı değil.</strong> Referans Künye sorgularında VKN'yi elle girebilirsiniz. <a href="ayarlar.php">Ayarlar'dan ekle →</a>
</div>
<?php endif; ?>

<div id="vdc-result" style="display:none;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.88rem"></div>

<!-- ── Bölüm 1: Ürün Referansları ── -->
<p class="hks-section-label">1. Ürün Referansları</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:20px">
    <button class="btn btn-primary" data-action="fetch_urunler" <?= !$queries_enabled ? 'disabled' : '' ?>>📦 Ürünleri Çek</button>
    <button class="btn btn-ghost" data-action="fetch_urun_cinsleri" <?= !$queries_enabled ? 'disabled' : '' ?>>🏷️ Ürün Cinslerini Çek</button>
    <button class="btn btn-ghost" data-action="fetch_urun_birimleri" <?= !$queries_enabled ? 'disabled' : '' ?>>📐 Ürün Birimlerini Çek</button>
    <button class="btn btn-ghost" data-action="fetch_urun_miktar_birimleri" <?= !$queries_enabled ? 'disabled' : '' ?>>⚖️ Miktar Birimlerini Çek</button>
    <button class="btn btn-ghost" data-action="fetch_sifatlar" <?= !$queries_enabled ? 'disabled' : '' ?>>📋 Sıfatları Çek</button>
    <button class="btn btn-ghost" data-action="fetch_belge_tipleri" <?= !$queries_enabled ? 'disabled' : '' ?>>📄 Belge Tiplerini Çek</button>
</div>

<!-- ── Bölüm 2: Kişi Sorgu ── -->
<p class="hks-section-label">2. Kişi / Firma Sorgu</p>
<div class="card" style="padding:16px;margin-bottom:20px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:200px">
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">TC / VKN</label>
            <input type="text" id="kisi_vkn_input" maxlength="11" placeholder="11 haneli TC veya 10 haneli VKN"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <button class="btn btn-primary" data-action="fetch_kisi_sorgu" <?= !$queries_enabled ? 'disabled' : '' ?>>👤 Kişi Sorgula</button>
    </div>
</div>

<!-- ── Bölüm 3: Künye / Stok ── -->
<p class="hks-section-label">3. Referans Künye &amp; Stok</p>
<div class="card" style="padding:16px;margin-bottom:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:12px;align-items:end">
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Malın Sahibi VKN/TC</label>
            <input type="text" id="ref_mal_sahibi_vkn" placeholder="Firma VKN (boş=<?= hks_h($firma_vkn ?: 'tümü') ?>)"
                   value="<?= hks_h($firma_vkn) ?>"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Künye No <small class="muted">(0=tümü)</small></label>
            <input type="text" id="ref_kunye_no_input" value="0" placeholder="0"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" data-action="fetch_referans_kunye" <?= !$queries_enabled ? 'disabled' : '' ?>>🔍 Referans Künyeleri Çek</button>
        <button class="btn btn-ghost" data-action="update_stock_from_ref_kunye" <?= !$queries_enabled ? 'disabled' : '' ?>>📊 Stoku Güncelle</button>
    </div>
</div>

<!-- ── Bölüm 4: Bildirim Listeleri ── -->
<p class="hks-section-label">4. Bildirim Listeleri</p>
<div class="card" style="padding:16px;margin-bottom:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:12px;align-items:end">
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Başlangıç</label>
            <input type="date" id="bl_bas" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Bitiş</label>
            <input type="date" id="bl_bit" value="<?= date('Y-m-d') ?>"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Künye Türü</label>
            <select id="bl_kunye_turu" style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
                <option value="1">Referans Künye (1)</option>
                <option value="2">Nihai Tüketim (2)</option>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:4px">Künye No <small class="muted">(0=tümü)</small></label>
            <input type="text" id="bl_kunye_no" value="0" placeholder="0"
                   style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:.9rem">
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-primary" data-action="fetch_yaptigim_bildirimler" <?= !$queries_enabled ? 'disabled' : '' ?>>📤 Yaptıklarım</button>
        <button class="btn btn-ghost" data-action="fetch_bana_yapilan_bildirimler" <?= !$queries_enabled ? 'disabled' : '' ?>>📥 Bana Yapılanlar</button>
        <button class="btn btn-ghost" data-action="update_stock_from_bildirimler" <?= !$queries_enabled ? 'disabled' : '' ?>>📊 Stoku Güncelle (Bildirimlerden)</button>
    </div>
    <div id="bl-result-panel" style="display:none;margin-top:12px"></div>
</div>

</div><!-- /.hks-page -->

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var firmaVkn  = <?= json_encode($firma_vkn) ?>;

function showVdcResult(ok, msg, detail, partial) {
    var el = document.getElementById('vdc-result');
    el.style.display = 'block';
    if (partial) {
        el.style.background = '#fffbeb'; el.style.border = '1px solid #f59e0b'; el.style.color = '#92400e';
        el.innerHTML = '⚠️ ' + escHtml(msg);
    } else if (ok) {
        el.style.background = '#f0fdf4'; el.style.border = '1px solid #22c55e'; el.style.color = '#065f46';
        el.innerHTML = '✅ ' + escHtml(msg);
    } else {
        el.style.background = '#fef2f2'; el.style.border = '1px solid #ef4444'; el.style.color = '#991b1b';
        el.innerHTML = '❌ ' + escHtml(msg);
    }
    if (detail) {
        el.innerHTML += '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:.8rem">Teknik Detay</summary><pre style="font-size:.75rem;margin:4px 0;white-space:pre-wrap;overflow-x:auto;max-height:300px">' + escHtml(JSON.stringify(detail, null, 2)) + '</pre></details>';
    }
    el.scrollIntoView({behavior:'smooth', block:'nearest'});
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function doFetch(action, extra, resultEl) {
    var buttons = document.querySelectorAll('button[data-action]');
    buttons.forEach(b => b.disabled = true);

    var body = 'csrf=' + encodeURIComponent(csrfToken) + '&action=' + encodeURIComponent(action);
    if (extra) {
        Object.entries(extra).forEach(([k,v]) => { body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v); });
    }

    fetch('ajax.php?action=' + encodeURIComponent(action), {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    })
    .then(r => r.json())
    .then(data => {
        var vdcMsg = data.ok
            ? (data.message || 'Tamamlandı.')
            : ((data.error_code ? ('HKS hata kodu: ' + data.error_code + ' — ') : '') + (data.user_message || data.message || 'HKS hata mesajı döndürmedi. Teknik detay servis loglarında görülebilir.'));
        showVdcResult(data.ok, vdcMsg, data.detail || data.diag_hints || null, data.partial);
    })
    .catch(() => showVdcResult(false, 'İstek gönderilemedi.'))
    .finally(() => buttons.forEach(b => b.disabled = false));
}

document.querySelectorAll('button[data-action]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var action = this.dataset.action;
        var extra  = {};

        if (action === 'fetch_kisi_sorgu') {
            var tc = document.getElementById('kisi_vkn_input').value.trim();
            if (!tc) { alert('TC veya VKN girin.'); return; }
            extra.tc_vkn = tc;
        }
        if (action === 'fetch_referans_kunye' || action === 'update_stock_from_ref_kunye') {
            extra.mal_sahibi_vkn = document.getElementById('ref_mal_sahibi_vkn').value.trim() || firmaVkn;
            extra.kunye_no       = document.getElementById('ref_kunye_no_input').value.trim() || '0';
        }
        if (['fetch_yaptigim_bildirimler','fetch_bana_yapilan_bildirimler','update_stock_from_bildirimler'].includes(action)) {
            extra.baslangic  = document.getElementById('bl_bas').value;
            extra.bitis      = document.getElementById('bl_bit').value;
            extra.kunye_turu = document.getElementById('bl_kunye_turu').value;
            extra.kunye_no   = document.getElementById('bl_kunye_no').value.trim() || '0';
        }

        doFetch(action, extra);
    });
});
</script>

<?php render_footer(); ?>
