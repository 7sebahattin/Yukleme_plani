<?php
// =========================================================
// kantar.php - Kantar Fişleri listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$rows = [];
try {
    $rows = db()->query(
        "SELECT id, fis_no, giris_tarih, plaka, firma_adi, malin_cinsi,
                palet_sayisi, kasa_cinsi, kasa_sayisi, tartim1, tartim2,
                depo, parti_no, created_at
         FROM kantar_fisleri
         ORDER BY CAST(fis_no AS UNSIGNED) DESC, id DESC
         LIMIT 500"
    )->fetchAll();
} catch (PDOException $e) {}

render_header('Kantar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚖️ Kantar Fişleri</h1>
        <?php if (!empty($rows)): ?>
        <p class="muted">Toplam <?= count($rows) ?> fiş</p>
        <?php endif; ?>
    </div>
    <a href="kantar_create.php" class="btn btn-primary btn-lg">+ Yeni Kantar Fişi</a>
</div>

<?php if (empty($rows)): ?>
<div class="empty">
    <p>Henüz kantar fişi yok.</p>
    <a href="kantar_create.php" class="btn btn-primary">İlk fişi oluştur</a>
</div>
<?php else: ?>

<!-- PC: tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Fiş No</th>
            <th>Giriş Tarihi</th>
            <th>Plaka</th>
            <th>Firma</th>
            <th>Malın Cinsi</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th>Depo</th>
            <th>Parti</th>
            <th class="num">Net KG</th>
            <th class="actions-col">İşlemler</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $net        = (float)$r['tartim1'] - (float)$r['tartim2'];
            $giris_disp = $r['giris_tarih'] ? fmt_datetime($r['giris_tarih']) : fmt_datetime($r['created_at']);
        ?>
            <tr>
                <td><strong>#<?= h($r['fis_no'] ?: (string)$r['id']) ?></strong></td>
                <td><?= h($r['fis_no'] ?: '—') ?></td>
                <td><?= h($giris_disp) ?></td>
                <td><?= h($r['plaka'] ?: '—') ?></td>
                <td><?= h($r['firma_adi'] ?: '—') ?></td>
                <td><?= h($r['malin_cinsi'] ?? '—') ?></td>
                <td class="num"><?= $r['palet_sayisi'] ? (int)$r['palet_sayisi'] : '—' ?></td>
                <td class="num"><?= $r['kasa_sayisi']  ? (int)$r['kasa_sayisi']  : '—' ?></td>
                <td><?= h($r['depo'] ?: '—') ?></td>
                <td style="font-size:.82rem;color:var(--muted)"><?= h($r['parti_no'] ?: '—') ?></td>
                <td class="num strong"><?= $net > 0 ? fmt_kg($net) . ' kg' : '—' ?></td>
                <td class="actions-col">
                    <a class="btn btn-sm" href="kantar_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                    <a class="btn btn-sm" href="kantar_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil: kart -->
<div class="card-list mobile-only">
    <?php foreach ($rows as $r):
        $net        = (float)$r['tartim1'] - (float)$r['tartim2'];
        $giris_disp = $r['giris_tarih'] ? fmt_datetime($r['giris_tarih']) : fmt_datetime($r['created_at']);
    ?>
    <div class="record-card">
        <div class="record-card-head">
            <div>
                <strong>#<?= h($r['fis_no'] ?: (string)$r['id']) ?></strong>
                <div class="muted"><?= h($giris_disp) ?></div>
            </div>
        </div>
        <div class="record-card-body">
            <?php if ($r['plaka']): ?><div><span class="lbl">Plaka:</span> <?= h($r['plaka']) ?></div><?php endif; ?>
            <?php if ($r['firma_adi']): ?><div><span class="lbl">Firma:</span> <?= h($r['firma_adi']) ?></div><?php endif; ?>
            <?php if ($r['kasa_cinsi']): ?><div><span class="lbl">Kasa Cinsi:</span> <?= h($r['kasa_cinsi']) ?></div><?php endif; ?>
        </div>
        <div class="record-card-totals">
            <?php if ($r['palet_sayisi']): ?><div><span>Palet</span><strong><?= (int)$r['palet_sayisi'] ?></strong></div><?php endif; ?>
            <?php if ($r['kasa_sayisi']): ?><div><span>Kasa</span><strong><?= (int)$r['kasa_sayisi'] ?></strong></div><?php endif; ?>
            <div><span>Net KG</span><strong class="strong"><?= $net > 0 ? fmt_kg($net) : '—' ?></strong></div>
        </div>
        <div class="record-card-actions">
            <a class="btn btn-sm" href="kantar_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
            <a class="btn btn-sm" href="kantar_edit.php?id=<?= (int)$r['id'] ?>">Düzenle</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
