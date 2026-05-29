<?php
// =========================================================
// index.php - Ana Sayfa / Dashboard
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('dashboard.read');

// Temel sayaçlar
$stats = db()->query("
    SELECT
        (SELECT COUNT(*) FROM loading_records WHERE type='yukleme') AS toplam_kayit,
        (SELECT COUNT(*) FROM loading_records WHERE type='cikma')   AS toplam_cikma,
        (SELECT COUNT(*) FROM material_definitions WHERE is_active=1) AS tanim_sayisi
")->fetch();

// HKS taslak sayısı
try {
    $hks_taslak = (int)db()->query("SELECT COUNT(*) FROM hks_notifications WHERE status='draft'")->fetchColumn();
} catch (PDOException $e) { $hks_taslak = 0; }

// Hesap modülü özet
try {
    $hesap_bugun    = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM account_transactions
        WHERE transaction_date = CURDATE() AND type IN ('gider','nakit')")->fetchColumn();
    $hesap_bekleyen = (int)db()->query("SELECT COUNT(*) FROM account_transactions
        WHERE is_given_to_accountant = 0")->fetchColumn();
} catch (PDOException $e) {
    $hesap_bugun = 0.0;
    $hesap_bekleyen = 0;
}

// Stok özet (bugün)
try {
    $stok_gelen_bugun = (float)db()->query("SELECT COALESCE(SUM(net_kg),0) FROM kantar_fisleri
        WHERE giris_tarih >= CURDATE()")->fetchColumn();
    $stok_cikan_bugun = (float)db()->query("SELECT COALESCE(SUM(lp.net_kg),0)
        FROM loading_pallets lp
        JOIN loading_records lr ON lp.loading_record_id = lr.id
        WHERE lr.type IN ('yukleme','cikma') AND lr.tarih = CURDATE()")->fetchColumn();
} catch (PDOException $e) {
    $stok_gelen_bugun = 0.0;
    $stok_cikan_bugun = 0.0;
}

// Kullanıcı sayısı — sadece users.admin yetkisiyle hesapla
$kullanici_aktif = 0;
if (can('users.admin')) {
    try { $kullanici_aktif = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(); } catch (PDOException $e) {}
}

// Audit son 24 saat — sadece admin için
$audit_son24h = 0;
if (is_admin()) {
    try { $audit_son24h = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(); } catch (PDOException $e) {}
}

// Açık notlar
$notlar_acik = 0;
try { $notlar_acik = (int)db()->query("SELECT COUNT(*) FROM dev_notes WHERE done=0")->fetchColumn(); } catch (PDOException $e) {}

// Bölüm görünürlükleri
$_ops_show  = can('records.read') || can('kantar.read');
$_stok_show = can('stok.read');
$_rap_show  = can('reports.read') || can('kantar.read');
$_ynt_show  = can('defs.read') || can('users.admin') || is_admin();

render_header('Ana Sayfa');
render_flash();
?>

<div class="home-grid">

<?php if ($_ops_show): ?>
<div class="home-section-title">Operasyon</div>

<?php if (can('records.read')): ?>
    <a href="records.php" class="home-card">
        <div class="home-card-icon" style="background:#eaf1ff">🚚</div>
        <div class="home-card-title">Yüklemeler</div>
        <?php if ($stats['toplam_kayit'] > 0): ?>
        <div class="home-card-badge"><?= (int)$stats['toplam_kayit'] ?></div>
        <?php endif; ?>
    </a>

    <a href="cikmalar.php" class="home-card">
        <div class="home-card-icon" style="background:#fdecea">📋</div>
        <div class="home-card-title">Çıkmalar</div>
        <?php if ($stats['toplam_cikma'] > 0): ?>
        <div class="home-card-badge" style="background:var(--danger)"><?= (int)$stats['toplam_cikma'] ?></div>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if (can('kantar.read')): ?>
    <a href="kantar.php" class="home-card">
        <div class="home-card-icon" style="background:#f0faf4">⚖️</div>
        <div class="home-card-title">Kantar</div>
    </a>
<?php endif; ?>

    <a href="notes.php" class="home-card">
        <div class="home-card-icon" style="background:#faf5ff">📝</div>
        <div class="home-card-title">Notlar</div>
        <?php if ($notlar_acik > 0): ?>
        <div class="home-card-badge" style="background:#7c3aed"><?= $notlar_acik ?></div>
        <?php endif; ?>
    </a>

    <a href="hks/index.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f0fe">🏛</div>
        <div class="home-card-title">Hal Bildirimi</div>
        <?php if ($hks_taslak > 0): ?>
        <div class="home-card-badge" style="background:#6366f1"><?= (int)$hks_taslak ?></div>
        <?php endif; ?>
    </a>

<?php endif; ?>

<?php if ($_stok_show): ?>
<div class="home-section-title">Stok</div>

    <a href="stok.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f5e9">📦</div>
        <div class="home-card-title">Ürün Stok</div>
        <?php if ($stok_gelen_bugun > 0 || $stok_cikan_bugun > 0): ?>
        <div class="home-card-sub">
            <?php if ($stok_gelen_bugun > 0): ?>
            <span style="color:var(--success)">↑<?= fmt_kg($stok_gelen_bugun) ?>kg</span>
            <?php endif; ?>
            <?php if ($stok_cikan_bugun > 0): ?>
            <span style="color:var(--danger)"> ↓<?= fmt_kg($stok_cikan_bugun) ?>kg</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </a>

    <a href="malzeme_stok.php" class="home-card">
        <div class="home-card-icon" style="background:#e3f2fd">🧰</div>
        <div class="home-card-title">Malzeme Stok</div>
    </a>

<?php endif; ?>

<?php if ($_rap_show): ?>
<div class="home-section-title">Raporlama</div>

<?php if (can('reports.read')): ?>
    <a href="reports.php" class="home-card">
        <div class="home-card-icon" style="background:#faf0ff">📊</div>
        <div class="home-card-title">Raporlar</div>
    </a>

    <a href="hesap.php" class="home-card">
        <div class="home-card-icon" style="background:#fff3e0">🏦</div>
        <div class="home-card-title">Hesap</div>
        <?php if ($hesap_bekleyen > 0): ?>
        <div class="home-card-badge" style="background:var(--warn)"><?= $hesap_bekleyen ?></div>
        <?php endif; ?>
        <?php if ($hesap_bugun > 0): ?>
        <div class="home-card-sub">Bugün: <?= number_format($hesap_bugun, 2, ',', '.') ?>₺</div>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if (can('kantar.read')): ?>
    <a href="kantar_raporu.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f5f0">📈</div>
        <div class="home-card-title">Kantar Raporu</div>
    </a>
<?php endif; ?>

<?php endif; ?>

<?php if ($_ynt_show): ?>
<div class="home-section-title">Yönetim</div>

<?php if (can('defs.read')): ?>
    <a href="definitions.php" class="home-card">
        <div class="home-card-icon" style="background:#fcf2dc">⚙️</div>
        <div class="home-card-title">Tanımlar</div>
        <?php if ($stats['tanim_sayisi'] > 0): ?>
        <div class="home-card-badge" style="background:var(--warn)"><?= (int)$stats['tanim_sayisi'] ?></div>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if (can('users.admin')): ?>
    <a href="users.php" class="home-card">
        <div class="home-card-icon" style="background:#e8eaf6">👥</div>
        <div class="home-card-title">Kullanıcılar</div>
        <?php if ($kullanici_aktif > 0): ?>
        <div class="home-card-badge" style="background:#5c6bc0"><?= $kullanici_aktif ?></div>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if (is_admin()): ?>
    <a href="audit.php" class="home-card">
        <div class="home-card-icon" style="background:#fce4ec">🧾</div>
        <div class="home-card-title">İşlem Geçmişi</div>
        <?php if ($audit_son24h > 0): ?>
        <div class="home-card-badge" style="background:#e91e63"><?= $audit_son24h ?></div>
        <?php endif; ?>
        <div class="home-card-sub">Son 24 saat</div>
    </a>
<?php endif; ?>

<?php endif; ?>

</div>

<?php render_footer(); ?>
