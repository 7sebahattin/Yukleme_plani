<?php
// =========================================================
// record_new.php - Yeni kayıt türü seçimi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

render_header('Yeni Kayıt');
?>

<div class="page-head">
    <h1>Yeni Kayıt</h1>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<p class="muted" style="margin-bottom:24px">Ne tür bir kayıt oluşturmak istiyorsunuz?</p>

<div class="home-grid" style="max-width:520px">

    <a href="record_create.php" class="home-card">
        <div class="home-card-icon" style="background:#eaf1ff;font-size:2rem">📋</div>
        <div class="home-card-title">Yeni Yükleme</div>
        <div class="home-card-sub">Yükleme planı kaydı</div>
    </a>

    <a href="cikma_create.php" class="home-card">
        <div class="home-card-icon" style="background:#fdecea;font-size:2rem">🚚</div>
        <div class="home-card-title">Yeni Çıkma</div>
        <div class="home-card-sub">Çıkma kaydı</div>
    </a>

</div>

<?php render_footer(); ?>
