<?php
// =========================================================
// hks/stock.php — Referans Künye Stok Listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$repo  = new HksRepository(db());
$stock = $repo->listStock(false);

$total_in  = 0.0;
$total_used = 0.0;
$total_rem  = 0.0;
foreach ($stock as $s) {
    $total_in   += (float)$s['incoming_quantity'];
    $total_used += (float)$s['used_quantity'];
    $total_rem  += (float)$s['remaining_quantity'];
}

render_header('HKS Referans Stok');
render_flash();
?>

<div class="page-head">
    <div><h1>📦 Referans Künye Stoku</h1><p class="muted">Alış bildirimlerinden oluşan künye bakiyeleri</p></div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Bildirimler</a>
        <a href="create_purchase.php" class="btn btn-primary">+ Alış Ekle</a>
    </div>
</div>

<div class="rpt-summary" style="margin-bottom:14px">
    <div class="rpt-sum-item"><span>Toplam Giriş</span><strong><?= number_format($total_in,3,',','.') ?> kg</strong></div>
    <div class="rpt-sum-item"><span>Kullanılan</span><strong><?= number_format($total_used,3,',','.') ?> kg</strong></div>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Kalan</span><strong><?= number_format($total_rem,3,',','.') ?> kg</strong></div>
    <div class="rpt-sum-item"><span>Künye Sayısı</span><strong><?= count($stock) ?></strong></div>
</div>

<?php if (empty($stock)): ?>
<div class="empty">
    <p>Henüz referans künye yok.</p>
    <a href="create_purchase.php" class="btn btn-primary">+ Alış Bildirimi ile Ekle</a>
</div>
<?php else: ?>

<!-- PC tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <th>Künye No</th>
            <th>Ürün</th>
            <th>Tedarikçi</th>
            <th class="num">Giriş</th>
            <th class="num">Kullanılan</th>
            <th class="num">Kalan</th>
            <th>Birim</th>
            <th>Tip</th>
            <th>Tarih</th>
            <th>Bildirim</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($stock as $s):
            $rem = (float)$s['remaining_quantity'];
            $row_class = $rem <= 0 ? ' style="opacity:.55"' : '';
        ?>
        <tr<?= $row_class ?>>
            <td><strong><?= hks_h($s['kunye_no']) ?></strong></td>
            <td><?= hks_h($s['product_name']) ?></td>
            <td><?= hks_h($s['supplier_name'] ?? '—') ?></td>
            <td class="num"><?= number_format((float)$s['incoming_quantity'],3,',','.') ?></td>
            <td class="num muted"><?= number_format((float)$s['used_quantity'],3,',','.') ?></td>
            <td class="num" style="font-weight:600;color:<?= $rem > 0 ? 'var(--success)' : 'var(--muted)' ?>">
                <?= number_format($rem,3,',','.') ?>
            </td>
            <td><?= hks_h($s['unit']) ?></td>
            <td><?= hks_h(hks_type_label($s['source_notification_type'])) ?></td>
            <td><?= hks_h(substr($s['created_at'],0,10)) ?></td>
            <td>
                <?php if ($s['header_id']): ?>
                <a href="view.php?id=<?= (int)$s['header_id'] ?>" class="btn btn-sm btn-ghost">
                    #<?= (int)$s['header_id'] ?>
                </a>
                <?php else: ?>
                <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil kartlar -->
<div class="mobile-only">
    <?php foreach ($stock as $s):
        $rem = (float)$s['remaining_quantity'];
    ?>
    <div class="hks-card" <?= $rem <= 0 ? 'style="opacity:.55"' : '' ?>>
        <div class="hks-card-head">
            <div>
                <div class="hks-card-title"><?= hks_h($s['kunye_no']) ?></div>
                <div class="hks-card-meta"><?= hks_h($s['product_name']) ?><?= $s['supplier_name'] ? ' · ' . hks_h($s['supplier_name']) : '' ?></div>
            </div>
            <span class="hks-badge <?= $rem > 0 ? 'hks-badge-sent' : 'hks-badge-draft' ?>">
                <?= $rem > 0 ? 'Var' : 'Tükendi' ?>
            </span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;font-size:.82rem">
            <div><span style="color:var(--muted)">Giriş</span><br><strong><?= number_format((float)$s['incoming_quantity'],3,',','.') ?></strong></div>
            <div><span style="color:var(--muted)">Kullanılan</span><br><strong><?= number_format((float)$s['used_quantity'],3,',','.') ?></strong></div>
            <div><span style="color:var(--success)">Kalan</span><br><strong><?= number_format($rem,3,',','.') ?></strong></div>
        </div>
        <div class="hks-card-foot">
            <span style="font-size:.78rem;color:var(--muted)"><?= hks_h($s['unit']) ?> · <?= hks_h(substr($s['created_at'],0,10)) ?></span>
            <?php if ($s['header_id']): ?>
            <a href="view.php?id=<?= (int)$s['header_id'] ?>" class="btn btn-sm btn-ghost">#<?= (int)$s['header_id'] ?> →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>
<?php render_footer(); ?>
