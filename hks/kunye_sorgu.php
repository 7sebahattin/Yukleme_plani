<?php
// HKS Künye / Bildirim Sorgu
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

$recent_queries = $repo->listQueries(20);

render_header('HKS Künye / Bildirim Sorgu');
render_flash();
?>

<div class="hks-page" style="max-width:1000px;margin:0 auto">
<?php
$hks_active_tab = 'kunye_sorgu.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🔍 Künye / Bildirim Sorgu</h1>
        <p class="muted">HKS web servisinden künye veya bildirim sorgulama</p>
    </div>
</div>

<?php if (!$queries_enabled): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;color:#92400e">
    ⚠️ Sorgular için HKS ayarları yapılandırılmalı ve PHP SOAP extension aktif olmalıdır.
    <a href="ayarlar.php">Ayarlar →</a>
</div>
<?php else: ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;color:#1e40af">
    📡 Ortam: <strong><?= hks_h(strtoupper($env)) ?></strong> —
    Sorgular HKS WSDL'e gönderilir. Method adı WSDL'de bulunamazsa mevcut metodlar listelenir.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px">

    <!-- Künye Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">🏷️ Künye Sorgu</h3>
        <p style="font-size:.88rem;color:var(--muted)">HKS sisteminden künye numarasına ait bilgileri sorgular.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-weight:600;font-size:.85rem;margin-bottom:4px">Künye No</label>
            <input type="text" id="kunye_no" placeholder="Künye numarası giriniz..."
                   style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:7px;font-size:.95rem">
        </div>
        <button class="btn btn-primary" id="btnKunyeSorgu" <?= !$queries_enabled ? 'disabled' : '' ?>>
            🔍 Sorgula
        </button>
        <span id="kunye-spinner" style="display:none;margin-left:8px;font-size:.82rem;color:var(--muted)">Sorgulanıyor...</span>
        <div id="kunye-result" style="display:none;margin-top:12px;font-size:.85rem;padding:10px 12px;border-radius:6px"></div>
    </div>

    <!-- Bildirim Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">📋 Bildirim Sorgu</h3>
        <p style="font-size:.88rem;color:var(--muted)">Bildirim numarası ile HKS'den bildirim sorgular.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-weight:600;font-size:.85rem;margin-bottom:4px">Bildirim No</label>
            <input type="text" id="bildirim_no" placeholder="Bildirim numarası giriniz..."
                   style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:7px;font-size:.95rem">
        </div>
        <button class="btn btn-primary" id="btnBildirimSorgu" <?= !$queries_enabled ? 'disabled' : '' ?>>
            🔍 Sorgula
        </button>
        <span id="bildirim-spinner" style="display:none;margin-left:8px;font-size:.82rem;color:var(--muted)">Sorgulanıyor...</span>
        <div id="bildirim-result" style="display:none;margin-top:12px;font-size:.85rem;padding:10px 12px;border-radius:6px"></div>
    </div>

</div>

<!-- Son Sorgular -->
<?php if ($recent_queries): ?>
<div>
    <h3 style="margin-bottom:10px">Son Sorgular</h3>
    <div class="table-wrap">
    <table style="width:100%;border-collapse:collapse;font-size:.88rem">
        <thead>
            <tr style="background:var(--bg)">
                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tarih</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tür</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Sorgu Değeri</th>
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
            <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($q['query_value']) ?></td>
            <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($q['result_status']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div style="color:var(--muted);font-size:.88rem">Henüz sorgu yapılmamış.</div>
<?php endif; ?>

</div>

<script>
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function hksQuery(action, payload, spinnerEl, resultEl, btn) {
    btn.disabled = true;
    spinnerEl.style.display = 'inline';
    resultEl.style.display  = 'none';

    fetch('ajax.php?action=' + action, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrfToken) + '&' +
              Object.entries(payload).map(([k,v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&')
    })
    .then(r => r.json())
    .then(data => {
        resultEl.style.display = 'block';
        resultEl.style.background = data.ok ? '#f0fdf4' : '#fef2f2';
        resultEl.style.border = '1px solid ' + (data.ok ? 'var(--success)' : 'var(--danger)');
        resultEl.style.color  = data.ok ? '#065f46' : '#991b1b';
        if (data.ok) {
            var d = data.data;
            resultEl.innerHTML = '✅ ' + (data.method_used ? '<small>Method: ' + data.method_used + '</small><br>' : '') +
                '<pre style="margin:6px 0 0;font-size:.82rem;white-space:pre-wrap;overflow-x:auto">' +
                JSON.stringify(d, null, 2) + '</pre>';
        } else {
            var msg = '❌ ' + (data.message || 'Hata');
            if (data.methods_available && data.methods_available.length) {
                msg += '<br><small style="opacity:.8">Mevcut WSDL metodları:<br>' +
                    data.methods_available.slice(0, 30).join('<br>') + '</small>';
                if (data.hint) msg += '<br><em style="opacity:.7">' + data.hint + '</em>';
            }
            resultEl.innerHTML = msg;
        }
    })
    .catch(() => {
        resultEl.style.display = 'block';
        resultEl.style.background = '#fef2f2';
        resultEl.style.border = '1px solid var(--danger)';
        resultEl.textContent = 'İstek gönderilemedi.';
    })
    .finally(() => { btn.disabled = false; spinnerEl.style.display = 'none'; });
}

document.getElementById('btnKunyeSorgu').addEventListener('click', function() {
    var no = document.getElementById('kunye_no').value.trim();
    if (!no) { alert('Künye numarası girin.'); return; }
    hksQuery('query_kunye', {kunye_no: no},
        document.getElementById('kunye-spinner'),
        document.getElementById('kunye-result'), this);
});

document.getElementById('btnBildirimSorgu').addEventListener('click', function() {
    var no = document.getElementById('bildirim_no').value.trim();
    if (!no) { alert('Bildirim numarası girin.'); return; }
    hksQuery('query_bildirim', {bildirim_no: no},
        document.getElementById('bildirim-spinner'),
        document.getElementById('bildirim-result'), this);
});
</script>

<?php render_footer(); ?>
