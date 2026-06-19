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
$dupCount = $repo->countDuplicateWarnings();
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
<div class="hks-page">
<?php include __DIR__ . '/views/_tabs.php'; ?>

<?php
$s_user  = !empty($settings['username']);
$s_pass  = !empty($settings['password_enc']);
$s_svc   = !empty($settings['service_password_enc']);
$s_all   = $s_user && $s_pass && $s_svc;
$can_cfg = is_admin() || (function_exists('can') && can('hks.settings'));
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏛 HKS Paneli</h1>
        <p class="muted">Hal Kayıt Sistemi</p>
    </div>
    <div class="page-head-actions">
        <?php if ($can_cfg): ?>
        <a href="ayarlar.php" class="btn btn-ghost">⚙️ Ayarlar</a>
        <?php endif; ?>
        <a href="bildirim_form.php" class="btn btn-primary">+ Yeni Bildirim</a>
    </div>
</div>

<!-- Sistem uyarıları -->
<?php if (!$soap_ok): ?>
<div class="hks-error-box">⚠️ PHP SOAP extension aktif değil. Sunucuda <code>extension=soap</code> etkinleştirilmesi gerekiyor.</div>
<?php endif; ?>
<?php if ($live_on): ?>
<div class="hks-warning-box">🔴 <strong>Canlı gönderim açık.</strong> HKS'ye gerçek bildirimler gönderilebilir.</div>
<?php endif; ?>

<!-- 1. HKS Ayar Durumu -->
<p class="hks-section-label">HKS Ayar Durumu</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">

    <!-- Giriş bilgileri kartı -->
    <div class="hks-card <?= $s_all ? 'hks-card-ok' : 'hks-card-warn' ?>">
        <div class="hks-card-title">Giriş Bilgileri</div>
        <div style="font-size:.86rem;line-height:2.2">
            <div><?= $s_user ? '✅' : '⚪' ?> Kullanıcı Adı</div>
            <div><?= $s_pass ? '✅' : '⚪' ?> HKS Şifresi</div>
            <div><?= $s_svc  ? '✅' : '⚪' ?> Web Servis Şifresi</div>
        </div>
        <?php if ($can_cfg): ?>
        <div style="margin-top:12px">
            <?php if (!$s_all): ?>
            <a href="ayarlar.php" class="btn btn-sm" style="background:#f59e0b;color:#fff;border-color:#f59e0b;font-weight:700">Ayarları Tamamla →</a>
            <?php else: ?>
            <a href="ayarlar.php" class="btn btn-ghost btn-sm">⚙️ Düzenle</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bağlantı durumu kartı -->
    <div class="hks-card <?= $has_cfg && $soap_ok ? ($test_ok ? 'hks-card-ok' : 'hks-card-warn') : 'hks-card-err' ?>">
        <div class="hks-card-title">Web Servis Bağlantısı</div>
        <?php if (!$soap_ok): ?>
            <div style="color:#991b1b;font-size:.88rem">❌ SOAP extension yok</div>
        <?php elseif (!$has_cfg): ?>
            <div style="color:#92400e;font-size:.88rem">⚙️ Yapılandırılmamış</div>
        <?php elseif ($test_ok): ?>
            <div style="color:#065f46;font-size:.88rem;font-weight:600">✅ Bağlantı başarılı</div>
        <?php else: ?>
            <div style="color:#92400e;font-size:.88rem">⚠️ Bağlantı testi yapılmadı</div>
        <?php endif; ?>
        <?php if ($test_at): ?>
        <div class="hks-card-sub">Son test: <?= hks_h(date('d.m.Y H:i', strtotime($test_at))) ?></div>
        <?php endif; ?>
        <?php if ($settings): ?>
        <div class="hks-card-sub"><?= hks_env_label($settings['environment'] ?? 'test') ?></div>
        <?php endif; ?>
        <?php if ($can_cfg && $has_cfg): ?>
        <div style="margin-top:12px"><a href="ayarlar.php" class="btn btn-ghost btn-sm">🔌 Bağlantı Testi →</a></div>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Hızlı İşlemler -->
<p class="hks-section-label" style="margin-top:20px">Hızlı İşlemler</p>
<div class="hks-quick-actions">
    <a href="bildirim_form.php" class="hks-simple-card">
        <span class="hks-simple-card-icon">➕</span>
        <span class="hks-simple-card-label">Yeni Bildirim</span>
    </a>
    <a href="bildirimler.php" class="hks-simple-card">
        <span class="hks-simple-card-icon">📋</span>
        <span class="hks-simple-card-label">Bildirimler</span>
    </a>
    <a href="kunye_sorgu.php" class="hks-simple-card">
        <span class="hks-simple-card-icon">🔍</span>
        <span class="hks-simple-card-label">Künye Sorgula</span>
    </a>
    <a href="stok.php" class="hks-simple-card">
        <span class="hks-simple-card-icon">📦</span>
        <span class="hks-simple-card-label">Stok Kontrol</span>
    </a>
</div>

<!-- 3. Son Durum -->
<p class="hks-section-label" style="margin-top:4px">Son Durum</p>
<div class="hks-stat-row">
    <div class="hks-stat hks-card-info">
        <a href="bildirimler.php?status=draft">
            <div class="hks-stat-value"><?= $counts['draft'] ?></div>
            <div class="hks-stat-label">Taslak</div>
        </a>
    </div>
    <div class="hks-stat <?= $counts['ready'] > 0 ? 'hks-card-warn' : 'hks-card-info' ?>">
        <a href="bildirimler.php?status=ready">
            <div class="hks-stat-value"><?= $counts['ready'] ?></div>
            <div class="hks-stat-label">Kontrol Bekleyen</div>
        </a>
    </div>
    <div class="hks-stat <?= $counts['failed'] > 0 ? 'hks-card-err' : 'hks-card-info' ?>">
        <a href="bildirimler.php?status=failed">
            <div class="hks-stat-value"><?= $counts['failed'] ?></div>
            <div class="hks-stat-label">Hatalı</div>
        </a>
    </div>
    <div class="hks-stat hks-card-ok">
        <a href="bildirimler.php?status=sent">
            <div class="hks-stat-value"><?= $counts['sent'] ?></div>
            <div class="hks-stat-label">Gönderildi</div>
        </a>
    </div>
</div>

<!-- 4. Gelişmiş / Teknik -->
<details class="hks-advanced" style="margin-top:8px">
    <summary>Gelişmiş / Teknik <small class="muted" style="font-weight:400;margin-left:6px">— servis logları, referanslar</small></summary>
    <div class="hks-advanced-body">

        <?php if ($dupCount > 0): ?>
        <div class="hks-warning-box" style="margin-bottom:12px">
            ⚠️ <strong><?= $dupCount ?> mükerrer uyarısı</strong> — içeriği gönderilmiş bir kayıtla eşleşen taslak/hazır kayıt var.
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px">
            <a href="referanslar.php" class="btn btn-ghost btn-sm">📚 Referans Senkronizasyon
                <small class="muted">(<?= count($refStats) ?>/<?= count(HKS_REF_TYPES) ?> senkronize)</small>
            </a>
            <a href="servis_loglari.php" class="btn btn-ghost btn-sm">🧾 Servis Logları</a>
        </div>

        <?php if ($recentLogs): ?>
        <p style="font-size:.82rem;font-weight:600;margin-bottom:6px">Son 5 Servis Logu</p>
        <div class="table-wrap">
        <table class="hks-table">
            <thead><tr><th>Tarih</th><th>Servis</th><th>Method</th><th>Durum</th><th>ms</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
            <tr>
                <td style="white-space:nowrap;color:var(--muted);font-size:.78rem"><?= hks_h(date('d.m H:i', strtotime($log['created_at']))) ?></td>
                <td style="font-size:.82rem"><?= hks_h($log['service_name']) ?></td>
                <td style="font-size:.82rem"><?= hks_h($log['method_name']) ?></td>
                <td><?= $log['is_success'] ? '<span class="hks-badge hks-badge-sent">✓</span>' : '<span class="hks-badge hks-badge-failed">✗</span>' ?></td>
                <td style="font-size:.82rem"><?= hks_h($log['duration_ms']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <p style="font-size:.85rem;color:var(--muted)">Servis logu yok.</p>
        <?php endif; ?>
    </div>
</details>

</div><!-- /.hks-page -->

<?php render_footer(); ?>
