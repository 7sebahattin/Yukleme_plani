<?php
// =========================================================
// kantar.php - Kantar Fişleri listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.read');

$f_raporlandi = trim($_GET['raporlandi'] ?? '');

$kf_where = "1=1";
if ($f_raporlandi === 'raporlanmayan') {
    $kf_where = "reported_at IS NULL";
} elseif ($f_raporlandi === 'raporlanan') {
    $kf_where = "reported_at IS NOT NULL";
}

$rows = [];
try {
    $rows = db()->query(
        "SELECT id, fis_no, giris_tarih, plaka, firma_adi, malin_cinsi,
                palet_sayisi, kasa_cinsi, kasa_sayisi, tartim1, tartim2,
                depo, created_at, reported_at
         FROM kantar_fisleri
         WHERE {$kf_where}
         ORDER BY CAST(fis_no AS UNSIGNED) DESC, id DESC
         LIMIT 500"
    )->fetchAll();
} catch (PDOException $e) {}

$can_write = can('kantar.write');

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
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="kantar_raporu.php" class="btn btn-ghost btn-sm">📊 Rapor</a>
        <a href="kantar_create.php" class="btn btn-primary btn-lg">+ Yeni Kantar Fişi</a>
    </div>
</div>

<!-- Filtre çubuğu -->
<div class="filter-bar" style="margin-bottom:12px">
    <?php
    $r_opts = ['' => 'Tümü', 'raporlanmayan' => 'Raporlanmayan', 'raporlanan' => 'Raporlanan'];
    foreach ($r_opts as $val => $lbl):
        $active = ($f_raporlandi === $val) ? 'btn-primary' : 'btn-ghost';
    ?>
    <a href="kantar.php?raporlandi=<?= urlencode($val) ?>" class="btn btn-sm <?= $active ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
<div class="empty">
    <p>Bu filtreye göre kantar fişi bulunamadı.</p>
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
            <th class="num">Net KG</th>
            <th>Raporlandı</th>
            <th class="actions-col">İşlemler</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $net        = (float)$r['tartim1'] - (float)$r['tartim2'];
            $giris_disp = $r['giris_tarih'] ? fmt_datetime($r['giris_tarih']) : fmt_datetime($r['created_at']);
            $is_rep     = !empty($r['reported_at']);
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
                <td class="num strong"><?= $net > 0 ? fmt_kg($net) . ' kg' : '—' ?></td>
                <td>
                    <span class="kantar-badge <?= $is_rep ? 'kantar-badge-yes' : 'kantar-badge-no' ?>">
                        <?= $is_rep ? '✓ Raporlandı' : 'Raporlanmadı' ?>
                    </span>
                    <?php if ($can_write): ?>
                    <form method="post" action="kantar_report_toggle.php" style="display:inline;margin-left:4px">
                        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="action" value="<?= $is_rep ? 'unmark' : 'mark' ?>">
                        <input type="hidden" name="back"   value="kantar.php?raporlandi=<?= urlencode($f_raporlandi) ?>">
                        <button type="submit" class="btn btn-xs btn-ghost">
                            <?= $is_rep ? 'Geri Al' : 'İşaretle' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
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
        $is_rep     = !empty($r['reported_at']);
    ?>
    <div class="record-card">
        <div class="record-card-head">
            <div>
                <strong>#<?= h($r['fis_no'] ?: (string)$r['id']) ?></strong>
                <div class="muted"><?= h($giris_disp) ?></div>
            </div>
            <span class="kantar-badge <?= $is_rep ? 'kantar-badge-yes' : 'kantar-badge-no' ?>">
                <?= $is_rep ? '✓ Raporlandı' : 'Raporlanmadı' ?>
            </span>
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
            <?php if ($can_write): ?>
            <form method="post" action="kantar_report_toggle.php" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="<?= $is_rep ? 'unmark' : 'mark' ?>">
                <input type="hidden" name="back"   value="kantar.php?raporlandi=<?= urlencode($f_raporlandi) ?>">
                <button type="submit" class="btn btn-sm btn-ghost">
                    <?= $is_rep ? 'Geri Al' : '✓ Raporlandı' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
