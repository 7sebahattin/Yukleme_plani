<?php
// HKS Servis Logları
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo = new HksRepository(db());
$logs = $repo->listServiceLogs(200);

render_header('HKS Servis Logları');
render_flash();
?>

<div class="hks-page" style="max-width:1200px;margin:0 auto">
<?php
$hks_active_tab = 'servis_loglari.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🧾 Servis Logları</h1>
        <p class="muted">HKS web servis çağrı geçmişi</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Panele Dön</a>
    </div>
</div>

<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.83rem;color:#1e40af">
    🔐 Şifreler bu logda gizlenmiştir. Hassas veriler hiçbir zaman loglara yazılmaz.
</div>

<?php if (empty($logs)): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted)">
    Henüz servis logu yok. Bağlantı testi veya referans senkronizasyonu yapıldığında loglar burada görünür.
</div>
<?php else: ?>

<div class="table-wrap">
<table style="width:100%;border-collapse:collapse;font-size:.88rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tarih</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Servis</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Method</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Ortam</th>
            <th style="padding:8px 10px;text-align:center;border-bottom:2px solid var(--border)">Durum</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Hata</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Süre</th>
            <th style="padding:8px 10px;text-align:center;border-bottom:2px solid var(--border)">Detay</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($logs as $i => $log): ?>
    <tr>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);white-space:nowrap;color:var(--muted);font-size:.82rem">
            <?= hks_h(date('d.m.Y H:i:s', strtotime($log['created_at']))) ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($log['service_name']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($log['method_name']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_env_label($log['environment']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:center">
            <?= $log['is_success']
                ? '<span style="color:var(--success)">✅</span>'
                : '<span style="color:var(--danger)">❌</span>' ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);font-size:.82rem;color:var(--danger);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= hks_h($log['error_message'] ?? '') ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">
            <?= number_format((int)$log['duration_ms']) ?>ms
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:center">
            <?php if ($log['request_safe_json'] || $log['response_json']): ?>
            <button class="btn btn-ghost btn-sm" onclick="toggleDetail(<?= $i ?>)">↓</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($log['request_safe_json'] || $log['response_json']): ?>
    <tr id="detail-<?= $i ?>" style="display:none">
        <td colspan="8" style="padding:10px 16px;border-bottom:1px solid var(--border);background:var(--bg)">
            <?php if ($log['request_safe_json']): ?>
            <strong style="font-size:.82rem">İstek (şifreler gizli):</strong>
            <pre style="font-size:.78rem;overflow-x:auto;margin:4px 0 10px;background:#fff;padding:8px;border-radius:6px;border:1px solid var(--border)"><?= hks_h($log['request_safe_json']) ?></pre>
            <?php endif; ?>
            <?php if ($log['response_json']): ?>
            <strong style="font-size:.82rem">Yanıt:</strong>
            <pre style="font-size:.78rem;overflow-x:auto;margin:4px 0;background:#fff;padding:8px;border-radius:6px;border:1px solid var(--border)"><?= hks_h($log['response_json']) ?></pre>
            <?php endif; ?>
        </td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<p style="margin-top:10px;color:var(--muted);font-size:.83rem"><?= count($logs) ?> log kaydı.</p>
<?php endif; ?>

</div>

<script>
function toggleDetail(i) {
    var row = document.getElementById('detail-' + i);
    if (!row) return;
    row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>

<?php render_footer(); ?>
