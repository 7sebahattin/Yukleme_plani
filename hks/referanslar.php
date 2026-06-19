<?php
// HKS Referans Senkronizasyonu
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$stats  = $repo->getReferenceStats();

render_header('HKS Referanslar');
render_flash();
?>

<div class="hks-page" style="max-width:1000px;margin:0 auto">
<?php
$hks_active_tab = 'referanslar.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>📚 Referans Verileri</h1>
        <p class="muted">HKS kayıt verileri yerel cache'e senkronize ediliyor</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Panele Dön</a>
    </div>
</div>

<?php if (!hks_check_soap()): ?>
<div style="background:#fef2f2;border:1px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:12px">
    ❌ PHP SOAP extension aktif değil — senkronizasyon yapılamaz.
</div>
<?php elseif (!$client->hasSettings()): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:12px">
    ⚠️ HKS ayarları yapılandırılmamış. <a href="ayarlar.php">Ayarları girin.</a>
</div>
<?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px">
    <button class="btn btn-primary" id="btnSyncAll">🔄 Tümünü Senkronize Et</button>
    <button class="btn btn-ghost" id="btnSyncUrun">🌿 Ürünler</button>
    <button class="btn btn-ghost" id="btnSyncDepo">🏢 Depolar</button>
    <button class="btn btn-ghost" id="btnSyncIl">🗺️ İller</button>
    <button class="btn btn-ghost" id="btnSyncBildirimTuru">📋 Bildirim Türleri</button>
    <button class="btn btn-ghost" id="btnSyncSifat">🏷️ Sıfatlar</button>
    <button class="btn btn-ghost" id="btnSyncIsletme">🏭 İşletme Türleri</button>
    <button class="btn btn-ghost" id="btnSyncHal">🏪 Hal İçi İşyeri</button>
    <button class="btn btn-ghost" id="btnSyncBelde">🌆 Beldeler</button>
</div>
<p class="muted" style="font-size:.8rem;margin:0 0 12px">
    ⚠️ Beldeler ilçe bazlı sorgu gerektirir — boş istek ile denenecek, servis tüm beldeleri dönmeyebilir.
</p>

<div id="sync-result" style="display:none;padding:12px 16px;border-radius:8px;margin-bottom:12px"></div>
<div id="sync-progress" style="display:none;color:var(--muted);font-size:.9rem;margin-bottom:12px">
    ⏳ Senkronizasyon devam ediyor...
</div>

<div class="table-wrap">
<table style="width:100%;border-collapse:collapse;font-size:.9rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:10px;text-align:left;border-bottom:2px solid var(--border)">Referans Tipi</th>
            <th style="padding:10px;text-align:right;border-bottom:2px solid var(--border)">Kayıt Sayısı</th>
            <th style="padding:10px;text-align:left;border-bottom:2px solid var(--border)">Son Senkronizasyon</th>
            <th style="padding:10px;text-align:left;border-bottom:2px solid var(--border)">Durum</th>
            <th style="padding:10px;text-align:left;border-bottom:2px solid var(--border)">İşlem</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach (HKS_REF_TYPES as $ref_type): ?>
    <?php $stat = $stats[$ref_type] ?? null; ?>
    <tr>
        <td style="padding:8px 10px;border-bottom:1px solid var(--border)">
            <strong><?= hks_h(hks_ref_type_label($ref_type)) ?></strong>
            <small class="muted">(<?= hks_h($ref_type) ?>)</small>
        </td>
        <td style="padding:8px 10px;border-bottom:1px solid var(--border);text-align:right">
            <?= $stat ? number_format((int)$stat['cnt']) : '—' ?>
        </td>
        <td style="padding:8px 10px;border-bottom:1px solid var(--border)">
            <?= $stat ? hks_h(date('d.m.Y H:i', strtotime($stat['last_sync']))) : '<span class="muted">Hiç</span>' ?>
        </td>
        <td style="padding:8px 10px;border-bottom:1px solid var(--border)">
            <?php if ($stat && (int)$stat['cnt'] > 0): ?>
                <span style="color:var(--success)">✅ Senkronize</span>
            <?php else: ?>
                <span style="color:var(--muted)">Boş</span>
            <?php endif; ?>
        </td>
        <td style="padding:8px 10px;border-bottom:1px solid var(--border)">
            <button class="btn btn-ghost btn-sm sync-single-btn" data-type="<?= hks_h($ref_type) ?>">Senkronize Et</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div style="margin-top:16px;font-size:.83rem;color:var(--muted)">
    Not: Referans verileri bildirim formunda dropdown olarak kullanılır.<br>
    HKS bağlantısı olmadığında son senkronize verilerle taslak oluşturulabilir.
</div>

</div>

<script>
var csrf = function() { return document.querySelector('meta[name="csrf-token"]').content; };

function showProgress(show) {
    document.getElementById('sync-progress').style.display = show ? 'block' : 'none';
}
function showResult(ok, msg) {
    var el = document.getElementById('sync-result');
    el.style.display = 'block';
    el.style.background = ok ? '#f0fdf4' : '#fef2f2';
    el.style.border = '1px solid ' + (ok ? 'var(--success)' : 'var(--danger)');
    el.style.color = ok ? '#065f46' : '#991b1b';
    el.textContent = (ok ? '✅ ' : '❌ ') + msg;
    if (ok) setTimeout(() => location.reload(), 2000);
}

function doSync(type) {
    showProgress(true);
    document.querySelectorAll('button').forEach(b => b.disabled = true);
    fetch('ajax.php?action=sync_reference', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(csrf()) + '&ref_type=' + encodeURIComponent(type)
    })
    .then(r => r.json())
    .then(data => {
        showProgress(false);
        showResult(data.ok, data.message || (data.ok ? 'Tamamlandı.' : 'Hata.'));
    })
    .catch(() => { showProgress(false); showResult(false, 'İstek gönderilemedi.'); })
    .finally(() => document.querySelectorAll('button').forEach(b => b.disabled = false));
}

document.getElementById('btnSyncAll').addEventListener('click', () => doSync('all'));
document.getElementById('btnSyncUrun').addEventListener('click', () => doSync('urun'));
document.getElementById('btnSyncDepo').addEventListener('click', () => doSync('depo'));
document.getElementById('btnSyncIl').addEventListener('click', () => doSync('il'));
document.getElementById('btnSyncBildirimTuru').addEventListener('click', () => doSync('bildirim_turu'));
document.getElementById('btnSyncSifat').addEventListener('click', () => doSync('sifat'));
document.getElementById('btnSyncIsletme').addEventListener('click', () => doSync('isletme_turu'));
document.getElementById('btnSyncHal').addEventListener('click', () => doSync('hal_ici_isyeri'));
document.getElementById('btnSyncBelde').addEventListener('click', () => doSync('belde'));

document.querySelectorAll('.sync-single-btn').forEach(function(btn) {
    btn.addEventListener('click', function() { doSync(this.dataset.type); });
});
</script>

<?php render_footer(); ?>
