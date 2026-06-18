<?php
// HKS Ana Panel (Dashboard)
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok.');
}

$repo     = new HksRepository(db());
$client   = new HksClient($repo);
$settings = $repo->getSettings();

$counts   = $repo->countNotificationsByStatus();
$refStats = $repo->getReferenceStats();
$recentLogs = $repo->getRecentLogs(5);

$soap_ok  = hks_check_soap();
$has_cfg  = $client->hasSettings();
$live_on  = $client->isLiveSendEnabled();
$test_ok  = $settings && $settings['last_test_ok'];
$test_at  = $settings['last_test_at'] ?? null;

$hks_page_title = 'HKS Paneli';
$hks_active_tab = 'index.php';

render_header('HKS Paneli');
render_flash();
?>

<style>
.hks-page { max-width: 1100px; margin: 0 auto; }
.hks-tabs { display: flex; flex-wrap: wrap; gap: 4px; padding: 12px 0 0; margin-bottom: 16px; border-bottom: 2px solid var(--border); }
.hks-tab { display: flex; align-items: center; gap: 5px; padding: 8px 14px; border-radius: 8px 8px 0 0; text-decoration: none; color: var(--muted); font-size: .88rem; border: 1px solid transparent; border-bottom: none; transition: background .15s; }
.hks-tab:hover { background: var(--bg); color: var(--text); }
.hks-tab.active { background: var(--card); color: var(--primary); font-weight: 600; border-color: var(--border); margin-bottom: -2px; }
.hks-tab-label { display: none; }
@media (min-width: 600px) { .hks-tab-label { display: inline; } }

.hks-dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap: 14px; margin-top: 16px; }
.hks-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
.hks-card-title { font-size: .78rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
.hks-card-value { font-size: 2rem; font-weight: 700; color: var(--text); line-height: 1.1; }
.hks-card-sub { font-size: .82rem; color: var(--muted); margin-top: 4px; }
.hks-card-ok   { border-left: 3px solid var(--success); }
.hks-card-warn { border-left: 3px solid #f59e0b; }
.hks-card-err  { border-left: 3px solid var(--danger); }
.hks-card-info { border-left: 3px solid var(--primary); }

.hks-status { display: inline-flex; align-items: center; gap: 5px; font-size: .85rem; padding: 3px 9px; border-radius: 20px; font-weight: 600; }
.hks-status-ok   { background: #d1fae5; color: #065f46; }
.hks-status-warn { background: #fef3c7; color: #92400e; }
.hks-status-err  { background: #fee2e2; color: #991b1b; }

.hks-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .78rem; font-weight: 600; }
.hks-badge-draft     { background: #e5e7eb; color: #374151; }
.hks-badge-ready     { background: #dbeafe; color: #1e40af; }
.hks-badge-sent      { background: #d1fae5; color: #065f46; }
.hks-badge-failed    { background: #fee2e2; color: #991b1b; }
.hks-badge-cancelled { background: #f3f4f6; color: #6b7280; }

.hks-section-title { font-size: 1rem; font-weight: 700; color: var(--text); margin: 24px 0 10px; }
.hks-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.hks-table th { background: var(--bg); text-align: left; padding: 8px 10px; font-weight: 600; border-bottom: 2px solid var(--border); }
.hks-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
.hks-table tr:hover td { background: var(--bg); }
.table-wrap { overflow-x: auto; }

.hks-warning-box { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #92400e; margin-bottom: 12px; }
.hks-error-box   { background: #fef2f2; border: 1px solid var(--danger); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #991b1b; margin-bottom: 12px; }
.hks-success-box { background: #f0fdf4; border: 1px solid var(--success); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #065f46; margin-bottom: 12px; }
.hks-info-box    { background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #1e40af; margin-bottom: 12px; }

.hks-form  .form-group { margin-bottom: 14px; }
.hks-form  .form-group label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
.hks-form  .form-group input, .hks-form .form-group select, .hks-form .form-group textarea {
    width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid var(--border);
    border-radius: 7px; font-size: .95rem; background: #fff;
}
@media (max-width: 767px) { .hks-form .form-group input, .hks-form .form-group select, .hks-form .form-group textarea { font-size: 16px; } }
.hks-form .form-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }

.hks-log-row td:first-child { white-space: nowrap; color: var(--muted); font-size: .8rem; }
</style>

<div class="hks-page">
<?php include __DIR__ . '/views/_tabs.php'; ?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏛 HKS Paneli</h1>
        <p class="muted">Hal Kayıt Sistemi Web Servis Yönetimi</p>
    </div>
    <div class="page-head-actions">
        <?php if (is_admin() || can('hks.settings')): ?>
        <a href="ayarlar.php" class="btn btn-ghost">⚙️ Ayarlar</a>
        <?php endif; ?>
        <a href="bildirim_form.php" class="btn btn-primary">+ Yeni Bildirim</a>
    </div>
</div>

<!-- Sistem durumu uyarıları -->
<?php if (!$soap_ok): ?>
<div class="hks-error-box">⚠️ PHP SOAP extension aktif değil. Sunucuda <code>extension=soap</code> etkinleştirilmesi gerekiyor.</div>
<?php endif; ?>
<?php if (!$has_cfg): ?>
<div class="hks-warning-box">⚙️ HKS ayarları henüz yapılandırılmamış. <a href="ayarlar.php">Ayarlar sayfasını</a> açarak kullanıcı adı ve şifre girin.</div>
<?php elseif (!hks_has_secure_key()): ?>
<div class="hks-warning-box">🔑 <strong>HKS_CRED_KEY tanımlanmamış.</strong> Şifreler DB adından türetilen anahtarla saklanıyor. Güvenlik için <code>config/local.php</code> içinde <code>define('HKS_CRED_KEY', '...');</code> tanımlayın.</div>
<?php endif; ?>
<?php if ($live_on): ?>
<div class="hks-warning-box">🔴 <strong>Canlı gönderim açık.</strong> HKS'ye gerçek bildirimler gönderilebilir.</div>
<?php endif; ?>

<!-- Dashboard kartları -->
<div class="hks-dashboard">

    <!-- Web Servis Durumu -->
    <div class="hks-card <?= $has_cfg && $soap_ok ? ($test_ok ? 'hks-card-ok' : 'hks-card-warn') : 'hks-card-err' ?>">
        <div class="hks-card-title">Web Servis Durumu</div>
        <?php if (!$soap_ok): ?>
            <span class="hks-status hks-status-err">❌ SOAP Yok</span>
        <?php elseif (!$has_cfg): ?>
            <span class="hks-status hks-status-warn">⚙️ Yapılandırılmamış</span>
        <?php elseif ($test_ok): ?>
            <span class="hks-status hks-status-ok">✅ Bağlantı OK</span>
        <?php else: ?>
            <span class="hks-status hks-status-warn">⚠️ Test Yapılmadı</span>
        <?php endif; ?>
        <?php if ($test_at): ?>
        <div class="hks-card-sub">Son test: <?= hks_h(date('d.m.Y H:i', strtotime($test_at))) ?></div>
        <?php endif; ?>
        <?php if ($settings): ?>
        <div class="hks-card-sub"><?= hks_env_label($settings['environment'] ?? 'test') ?></div>
        <?php endif; ?>
    </div>

    <!-- Bekleyen Taslaklar -->
    <div class="hks-card hks-card-info">
        <div class="hks-card-title">Bekleyen Taslaklar</div>
        <div class="hks-card-value"><?= $counts['draft'] ?></div>
        <div class="hks-card-sub"><a href="bildirimler.php?status=draft">Listele →</a></div>
    </div>

    <!-- Gönderime Hazır -->
    <div class="hks-card <?= $counts['ready'] > 0 ? 'hks-card-ok' : 'hks-card-info' ?>">
        <div class="hks-card-title">Gönderime Hazır</div>
        <div class="hks-card-value"><?= $counts['ready'] ?></div>
        <div class="hks-card-sub"><a href="bildirimler.php?status=ready">Listele →</a></div>
    </div>

    <!-- Gönderildi -->
    <div class="hks-card hks-card-ok">
        <div class="hks-card-title">Gönderildi</div>
        <div class="hks-card-value"><?= $counts['sent'] ?></div>
        <div class="hks-card-sub"><a href="bildirimler.php?status=sent">Listele →</a></div>
    </div>

    <!-- Hatalı -->
    <div class="hks-card <?= $counts['failed'] > 0 ? 'hks-card-err' : 'hks-card-info' ?>">
        <div class="hks-card-title">Hatalı Bildirimler</div>
        <div class="hks-card-value"><?= $counts['failed'] ?></div>
        <?php if ($counts['failed'] > 0): ?>
        <div class="hks-card-sub"><a href="bildirimler.php?status=failed">İncele →</a></div>
        <?php endif; ?>
    </div>

    <!-- Referans Durumu -->
    <div class="hks-card hks-card-info">
        <div class="hks-card-title">Referans Cache</div>
        <div class="hks-card-value"><?= count($refStats) ?> / <?= count(HKS_REF_TYPES) ?></div>
        <div class="hks-card-sub">Senkronize tip <?= count($refStats) ?>, toplam <?= count(HKS_REF_TYPES) ?><br><a href="referanslar.php">Senkronize Et →</a></div>
    </div>

</div>

<!-- Son Servis Logları -->
<?php if ($recentLogs): ?>
<div class="hks-section-title">Son Servis Logları</div>
<div class="table-wrap">
<table class="hks-table">
    <thead>
        <tr>
            <th>Tarih</th>
            <th>Servis</th>
            <th>Method</th>
            <th>Durum</th>
            <th>Süre</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($recentLogs as $log): ?>
    <tr class="hks-log-row">
        <td><?= hks_h(date('d.m.Y H:i', strtotime($log['created_at']))) ?></td>
        <td><?= hks_h($log['service_name']) ?></td>
        <td><?= hks_h($log['method_name']) ?></td>
        <td><?= $log['is_success'] ? '<span class="hks-badge hks-badge-sent">✓</span>' : '<span class="hks-badge hks-badge-failed">✗</span>' ?></td>
        <td><?= hks_h($log['duration_ms']) ?>ms</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p style="text-align:right;margin-top:6px"><a href="servis_loglari.php" style="font-size:.85rem">Tüm loglar →</a></p>
<?php endif; ?>

</div><!-- /.hks-page -->

<?php render_footer(); ?>
