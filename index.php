<?php
// =========================================================
// index.php - Ana Sayfa / Dashboard
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('dashboard.read');

// ── DB-Backup-01: Admin girişinde günlük otomatik yedek ───
$db_backup_result = null;
if (is_admin()) {
    require_once __DIR__ . '/config/db_backup_helpers.php';
    try {
        $db_backup_result = create_daily_backup_if_needed(db(), (int)($auth_user['id'] ?? 0));
    } catch (Throwable $_bkp_err) {
        // backup hatası ana sayfayı çökertmemeli
    }
}

// Temel sayaçlar — aktif depo kapsamında
[$_ds_ix, $_ds_ix_v] = depo_sql_records_in('loading_records');
$_ds_ix_and = $_ds_ix !== '' ? " AND $_ds_ix" : '';
$_st_ix = db()->prepare("
    SELECT
        (SELECT COUNT(*) FROM loading_records WHERE type='yukleme'{$_ds_ix_and}) AS toplam_kayit,
        (SELECT COUNT(*) FROM loading_records WHERE type='cikma'{$_ds_ix_and})   AS toplam_cikma,
        (SELECT COUNT(*) FROM material_definitions WHERE is_active=1) AS tanim_sayisi
");
$_st_ix->execute(array_merge($_ds_ix_v, $_ds_ix_v));
$stats = $_st_ix->fetch();

// Hal Kayıt bekleyen taslak sayısı (yeni halkayit paneli — hks_taslaklar tablosu)
try {
    $hks_taslak = (int)db()->query("SELECT COUNT(*) FROM hks_taslaklar")->fetchColumn();
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

// Stok özet artık index'te kullanılmıyor (kartlar reports.php'ye taşındı)

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

// Bölüm görünürlükleri
$_ops_show  = can('records.read') || can('kantar.read') || can('reports.read');
$_ynt_show  = can('defs.read') || can('users.admin') || is_admin();

render_header('Ana Sayfa');
render_flash();
// Backup bildirimi (admin, HTML link içerdiği için render_flash() dışında)
if ($db_backup_result !== null): ?>
<div class="flash flash-<?= $db_backup_result['ok'] ? 'success' : 'error' ?>" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>
        <?php if ($db_backup_result['ok']): ?>
            🗄️ Bugünün veritabanı yedeği oluşturuldu (<?= h($db_backup_result['method']) ?>).
        <?php elseif (!empty($db_backup_result['file_ok']) && empty($db_backup_result['db_ok'])): ?>
            ⚠️ Yedek dosyası oluşturuldu fakat kayıt tablosuna yazılamadı.
        <?php else: ?>
            ⚠️ Otomatik veritabanı yedeği oluşturulamadı.
        <?php endif; ?>
    </span>
    <span style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($db_backup_result['ok'] && $db_backup_result['id'] > 0): ?>
        <a href="admin_db_backups.php?action=download&id=<?= (int)$db_backup_result['id'] ?>"
           style="font-weight:700;color:inherit;text-decoration:underline">⬇ İndir</a>
        <?php endif; ?>
        <a href="admin_db_backups.php" style="color:inherit;text-decoration:underline">Tüm yedekler</a>
    </span>
</div>
<?php endif; ?>

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

    <?php if (!function_exists('can') || can('beyan.read') || (function_exists('is_admin') && is_admin())): ?>
    <a href="beyanlar.php" class="home-card">
        <div class="home-card-icon" style="background:#ede9fe">🧾</div>
        <div class="home-card-title">Beyanlar</div>
    </a>
    <?php endif; ?>

    <?php if (can('records.write')): ?>
    <a href="halkayit/index.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f0fe">🏛</div>
        <div class="home-card-title">Hal Bildirimi</div>
        <?php if ($hks_taslak > 0): ?>
        <div class="home-card-badge" style="background:#6366f1"><?= (int)$hks_taslak ?></div>
        <?php endif; ?>
    </a>
    <?php endif; ?>

<?php if (can('reports.read')): ?>
    <a href="reports.php" class="home-card">
        <div class="home-card-icon" style="background:#faf0ff">📊</div>
        <div class="home-card-title">Raporlar</div>
    </a>
<?php endif; ?>

<?php if (can('stok.read')): ?>
    <a href="malzeme_stok.php" class="home-card">
        <div class="home-card-icon" style="background:#fdf2f8">📦</div>
        <div class="home-card-title">Malzeme Stok</div>
    </a>
<?php endif; ?>

<?php if (can('reports.read')): ?>
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

<?php if (can('maliyet.read') || is_admin()): ?>
    <a href="maliyet.php" class="home-card">
        <div class="home-card-icon" style="background:#e0f2f1">🧮</div>
        <div class="home-card-title">Maliyet</div>
        <div class="home-card-sub">Parti bazlı maliyet hesabı</div>
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
    <a href="admin_db_backups.php" class="home-card">
        <div class="home-card-icon" style="background:#e8f5e9">🗄️</div>
        <div class="home-card-title">Veritabanı Yedekleri</div>
        <div class="home-card-sub">Günlük otomatik</div>
    </a>
    <!-- Şema Migrasyon / Depo Taşıma / Tedarikçi Eşleştirme: karttan kaldırıldı
         (tek seferlik kurulum araçları) — dosyalar silinmedi, gerekirse
         doğrudan URL ile (migrate.php, depo_tasima.php, firma_eslestirme.php)
         admin erişebilir. -->
<?php endif; ?>

<?php endif; ?>

</div>

<?php render_footer(); ?>
