<?php
// HKS Künye / Bildirim Sorgu
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);

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

<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;color:#92400e">
    ⚠️ <strong>Bu ekran iskelet olarak hazırlanmıştır.</strong><br>
    HKS web servis kılavuzundaki gerçek method adları doğrulandıktan sonra aktif edilecek.
    Şu an tahminle canlı sorgu yapılmamaktadır.
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px">

    <!-- Künye Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">🏷️ Künye Sorgu</h3>
        <p style="font-size:.88rem;color:var(--muted)">HKS sisteminden bir künye numarasına ait bilgileri sorgular.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-weight:600;font-size:.85rem;margin-bottom:4px">Künye No</label>
            <input type="text" id="kunye_no" placeholder="Künye numarası giriniz..."
                   style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:7px">
        </div>
        <button class="btn btn-primary" id="btnKunyeSorgu" disabled>
            🔍 Sorgula
        </button>
        <p style="font-size:.78rem;color:var(--muted);margin-top:6px">Servis eşlemesi tamamlanmadı — pasif</p>
    </div>

    <!-- Bildirim Sorgu -->
    <div class="card" style="padding:20px">
        <h3 style="margin-top:0">📋 Bildirim Sorgu</h3>
        <p style="font-size:.88rem;color:var(--muted)">Bildirim numarası veya parametrelerine göre sorgular.</p>
        <div style="margin-bottom:10px">
            <label style="display:block;font-weight:600;font-size:.85rem;margin-bottom:4px">Bildirim No</label>
            <input type="text" id="bildirim_no" placeholder="Bildirim numarası giriniz..."
                   style="width:100%;box-sizing:border-box;padding:9px 11px;border:1px solid var(--border);border-radius:7px">
        </div>
        <button class="btn btn-primary" id="btnBildirimSorgu" disabled>
            🔍 Sorgula
        </button>
        <p style="font-size:.78rem;color:var(--muted);margin-top:6px">Servis eşlemesi tamamlanmadı — pasif</p>
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
// Servis eşlemesi tamamlandığında bu butonlar aktif edilecek
document.getElementById('btnKunyeSorgu').addEventListener('click', function() {
    alert('Künye sorgu servisi henüz eşlenmedi. Kılavuzdan method adı doğrulandıktan sonra aktif edilecek.');
});
document.getElementById('btnBildirimSorgu').addEventListener('click', function() {
    alert('Bildirim sorgu servisi henüz eşlenmedi. Kılavuzdan method adı doğrulandıktan sonra aktif edilecek.');
});
</script>

<?php render_footer(); ?>
