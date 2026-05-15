<?php
// =========================================================
// kantar_view.php - Kantar fişi görüntüle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz fiş.');
    header('Location: kantar.php'); exit;
}

$st = db()->prepare("SELECT * FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

$t1  = (float)($fis['tartim1'] ?? 0);
$t2  = (float)($fis['tartim2'] ?? 0);
$net = max(0.0, $t1 - $t2);
$giris_disp = $fis['giris_tarih'] ? fmt_datetime($fis['giris_tarih']) : fmt_datetime($fis['created_at']);

render_header('Kantar Fişi #' . $id);
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚖️ Kantar Fişi <?= $fis['fis_no'] ? '· ' . h($fis['fis_no']) : '#' . $id ?></h1>
    </div>
    <div class="page-head-actions">
        <a href="kantar.php" class="btn btn-ghost">← Liste</a>
        <a href="kantar_edit.php?id=<?= $id ?>" class="btn">Düzenle</a>
    </div>
</div>

<!-- ── Fiş Görseli ── -->
<section class="card kv-photo-card">
    <div class="kv-photo-wrap">
        <img id="kvImg" class="kv-photo" alt="Kantar fişi görseli" style="display:none">
        <div id="kvImgPh" class="kv-photo-ph">
            📷<br><span>Bu cihazda bu fiş için görsel yok</span>
        </div>
    </div>
</section>

<!-- ── Fiş Verileri ── -->
<section class="card">
    <div class="card-head"><h2>Fiş Bilgileri</h2></div>
    <div class="card-body">
        <div class="info-grid">
            <?php if ($fis['fis_no']): ?>
            <div><span class="lbl">Fiş No</span><strong><?= h($fis['fis_no']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['plaka']): ?>
            <div><span class="lbl">Plaka</span><strong><?= h($fis['plaka']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['firma_adi']): ?>
            <div><span class="lbl">Firma</span><strong><?= h($fis['firma_adi']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['operator_adi']): ?>
            <div><span class="lbl">Operatör</span><strong><?= h($fis['operator_adi']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['giris_tarih']): ?>
            <div><span class="lbl">Giriş Tarihi</span><strong><?= h(fmt_datetime($fis['giris_tarih'])) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['cikis_tarih']): ?>
            <div><span class="lbl">Çıkış Tarihi</span><strong><?= h(fmt_datetime($fis['cikis_tarih'])) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['malin_cinsi']): ?>
            <div><span class="lbl">Malın Cinsi</span><strong><?= h($fis['malin_cinsi']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['geldigi_yer']): ?>
            <div><span class="lbl">Geldiği Yer</span><strong><?= h($fis['geldigi_yer']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['gittigi_yer']): ?>
            <div><span class="lbl">Gittiği Yer</span><strong><?= h($fis['gittigi_yer']) ?></strong></div>
            <?php endif; ?>
            <?php if ((int)$fis['palet_sayisi']): ?>
            <div><span class="lbl">Palet Sayısı</span><strong><?= (int)$fis['palet_sayisi'] ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['palet_cinsi'] ?? ''): ?>
            <div><span class="lbl">Palet Cinsi</span><strong><?= h($fis['palet_cinsi']) ?></strong></div>
            <?php endif; ?>
            <?php if ((int)$fis['kasa_sayisi']): ?>
            <div><span class="lbl">Kasa Sayısı</span><strong><?= (int)$fis['kasa_sayisi'] ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['kasa_cinsi']): ?>
            <div><span class="lbl">Kasa Cinsi</span><strong><?= h($fis['kasa_cinsi']) ?></strong></div>
            <?php endif; ?>
            <?php if ($fis['aciklama'] ?? ''): ?>
            <div class="span-2"><span class="lbl">Açıklama</span><strong><?= h($fis['aciklama']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ── Tartımlar ── -->
<section class="card">
    <div class="card-head"><h2>Tartımlar</h2></div>
    <div class="card-body">
        <div class="kv-tartim-list">
            <?php if ($t1 > 0): ?>
            <div class="kv-tartim-row">
                <div class="kv-tartim-label">1. Tartım</div>
                <div class="kv-tartim-val"><?= fmt_kg($t1) ?> <span class="kv-tartim-unit">kg</span></div>
                <?php if ($fis['alibi1'] ?? ''): ?>
                <div class="kv-tartim-alibi">Alibi: <?= h($fis['alibi1']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($t2 > 0): ?>
            <div class="kv-tartim-row">
                <div class="kv-tartim-label">2. Tartım</div>
                <div class="kv-tartim-val"><?= fmt_kg($t2) ?> <span class="kv-tartim-unit">kg</span></div>
                <?php if ($fis['alibi2'] ?? ''): ?>
                <div class="kv-tartim-alibi">Alibi: <?= h($fis['alibi2']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($net > 0): ?>
        <div class="kv-net-box">
            <div class="kv-net-label">NET KG</div>
            <div class="kv-net-val"><?= fmt_kg($net) ?></div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var KEY     = 'kantar_img_<?= $id ?>';
    var DB_FOTO = <?= json_encode($fis['foto_data'] ?? null) ?>;
    var img = document.getElementById('kvImg');
    var ph  = document.getElementById('kvImgPh');

    function showPhoto(src) {
        img.src = src;
        img.style.display = 'block';
        if (ph) ph.style.display = 'none';
    }

    /* localStorage önce, sonra DB */
    try {
        var raw = localStorage.getItem(KEY);
        if (raw) {
            var src = (raw.charAt(0) === '{') ? JSON.parse(raw).src : raw;
            if (src && src.indexOf('data:') === 0) { showPhoto(src); return; }
        }
    } catch(e) {}
    if (DB_FOTO && DB_FOTO.indexOf('data:') === 0) showPhoto(DB_FOTO);
})();
</script>

<?php render_footer(); ?>
