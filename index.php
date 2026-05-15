<?php
// =========================================================
// index.php - Ana Sayfa / Dashboard
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// Özet sayaçlar
$stats = db()->query("
    SELECT
        (SELECT COUNT(*) FROM loading_records WHERE type='yukleme') AS toplam_kayit,
        (SELECT COUNT(*) FROM loading_records WHERE type='cikma')   AS toplam_cikma,
        (SELECT COUNT(*) FROM material_definitions WHERE is_active=1) AS tanim_sayisi
")->fetch();

render_header('Ana Sayfa');
render_flash();
?>

<div class="home-hero">
    <div class="home-hero-icon">🌿</div>
    <div>
        <h1 class="home-hero-title">Asya Fresh</h1>
        <p class="home-hero-sub">Ne yapmak istersiniz?</p>
    </div>
</div>

<div class="home-grid">

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

    <a href="definitions.php" class="home-card">
        <div class="home-card-icon" style="background:#fcf2dc">⚙️</div>
        <div class="home-card-title">Tanımlar</div>
        <?php if ($stats['tanim_sayisi'] > 0): ?>
        <div class="home-card-badge" style="background:var(--warn)"><?= (int)$stats['tanim_sayisi'] ?></div>
        <?php endif; ?>
    </a>

    <a href="kantar.php" class="home-card">
        <div class="home-card-icon" style="background:#f0faf4">⚖️</div>
        <div class="home-card-title">Kantar</div>
    </a>

    <a href="reports.php" class="home-card">
        <div class="home-card-icon" style="background:#faf0ff">📊</div>
        <div class="home-card-title">Raporlar</div>
    </a>

</div>

<?php render_footer(); ?>
