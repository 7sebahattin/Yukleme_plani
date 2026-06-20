<?php
// HKS Ana Giriş — Firma seçim ekranı; seçim sonrası operasyon arayüzüne yönlendirir.
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write') && !is_admin()) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok.');
}

$repo      = new HksRepository(db());
$companies = $repo->getAllCompanies();
$can_cfg   = is_admin() || (function_exists('can') && can('hks.settings'));
$valid_ids = array_column($companies, 'id');

// İzin verilen yönlendirme hedefleri
$dest_map = [
    'operasyon' => 'operasyon/index.php',
    'ayarlar'   => 'ayarlar.php',
    'teknik'    => 'teknik.php',
];

// ── Firma seç (GET) — herhangi bir çıktıdan önce ──────────
if (isset($_GET['sec'])) {
    $sec  = (int)$_GET['sec'];
    $dest = $dest_map[$_GET['to'] ?? ''] ?? 'operasyon/index.php';
    if ($sec > 0 && in_array($sec, $valid_ids, false)) {
        $_SESSION['hks_company_id'] = $sec;
    } else {
        $dest = 'index.php';
    }
    header('Location: ' . $dest); exit;
}
// ── Firma değiştir (GET) ─────────────────────────────────
if (isset($_GET['switch'])) {
    unset($_SESSION['hks_company_id']);
    header('Location: index.php'); exit;
}

// Seçili firma ID'sini doğrula — listede yoksa temizle
$company_id = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : null;
if ($company_id !== null && !in_array($company_id, $valid_ids, false)) {
    unset($_SESSION['hks_company_id']);
    $company_id = null;
}

// ── Firma seçiliyse → yeni operasyon arayüzü ─────────────
if ($company_id !== null) {
    header('Location: operasyon/index.php'); exit;
}

// ── Firma seçim ekranı ───────────────────────────────────
render_header('HKS — Firma Seç');
render_flash();
?>
<div class="hks-page" style="max-width:680px;margin:0 auto">

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏛 Hal Kayıt Sistemi / HKS</h1>
        <p class="muted">Devam etmek için bir firma seçin</p>
    </div>
    <?php if ($can_cfg): ?>
    <div class="page-head-actions">
        <a href="firmalar.php" class="btn btn-ghost btn-sm">🏢 Firmaları Yönet</a>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($companies)): ?>
<div class="hks-warning-box" style="margin-bottom:16px">
    Henüz kayıtlı firma yok.
    <?php if ($can_cfg): ?>
    <a href="firmalar.php" class="btn btn-sm" style="margin-left:8px;background:#f59e0b;color:#fff;border-color:#f59e0b">+ Firma Ekle</a>
    <?php endif; ?>
</div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:24px">
<?php foreach ($companies as $co):
    $co_ok  = !empty($co['username']);
    $co_env = ($co['environment'] ?? 'test') === 'live' ? '🔴 Canlı' : '🟡 Test';
    $co_test = $co['last_test_ok'] ? '✅' : ($co['last_test_at'] ? '⚠️' : '—');
?>
    <a href="index.php?sec=<?= (int)$co['id'] ?>"
       class="hks-company-card <?= $co_ok ? '' : 'hks-company-card-warn' ?>"
       style="display:block;text-decoration:none;color:inherit">
        <div class="hks-company-card-name"><?= hks_h($co['firma_adi']) ?></div>
        <div class="hks-company-card-meta">
            <span><?= $co_env ?></span>
            <span>Bağlantı: <?= $co_test ?></span>
        </div>
        <?php if (!$co_ok): ?>
        <div style="font-size:.78rem;color:#92400e;margin-top:4px">⚙️ Ayarlar eksik</div>
        <?php endif; ?>
        <div class="hks-company-card-action">Giriş Yap →</div>
    </a>
<?php endforeach; ?>
</div>

<?php if ($can_cfg): ?>
<div style="text-align:center;padding:8px 0 24px">
    <a href="firmalar.php" class="btn btn-ghost">+ Yeni Firma Ekle / Firmaları Yönet</a>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
<?php render_footer(); ?>
