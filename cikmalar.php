<?php
// =========================================================
// cikmalar.php - Çıkma kayıt listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

$q            = trim((string)($_GET['q'] ?? ''));
$rapor_filter = trim((string)($_GET['rapor_durumu'] ?? ''));
if (!in_array($rapor_filter, ['raporlanmamis', 'raporlandi'], true)) $rapor_filter = '';

// loading_records.reported_at kolon varlığı kontrolü (idempotent fallback)
$lr_rep_col = false;
try {
    $lr_rep_col = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn();
    if (!$lr_rep_col) {
        try {
            db()->exec("ALTER TABLE `loading_records`
                ADD COLUMN `reported_at` DATETIME NULL,
                ADD COLUMN `reported_by` INT NULL");
            $lr_rep_col = true;
        } catch (PDOException $_lrcm) {}
    }
} catch (PDOException $_lrce) {}

$sql = "SELECT r.*,
               (SELECT COUNT(*)                          FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_palet,
               (SELECT COALESCE(SUM(p.kasa_adeti),0)    FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_kasa,
               (SELECT COALESCE(SUM(p.brut_kg),0)       FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_brut,
               (SELECT COALESCE(SUM(p.dara_kg),0)       FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_dara,
               (SELECT COALESCE(SUM(p.net_kg),0)        FROM loading_pallets p WHERE p.loading_record_id = r.id) AS toplam_net,
               COALESCE((SELECT m.name FROM loading_pallets p2
                          LEFT JOIN material_definitions m ON m.id = p2.kasa_cinsi_id
                          WHERE p2.loading_record_id = r.id LIMIT 1), '') AS ilk_kasa_cinsi
        FROM loading_records r
        WHERE r.type = 'cikma' ";
$params = [];
if ($q !== '') {
    $sql .= " AND (r.firma LIKE :q OR r.bolge LIKE :q OR r.urun LIKE :q) ";
    $params[':q'] = '%' . $q . '%';
}
if ($lr_rep_col) {
    if ($rapor_filter === 'raporlanmamis') { $sql .= " AND r.reported_at IS NULL "; }
    if ($rapor_filter === 'raporlandi')    { $sql .= " AND r.reported_at IS NOT NULL "; }
}
// Depo sorumluluğu kısıtı
[$_depo_sql, $_depo_params] = depo_sql_records('r');
if ($_depo_sql !== '') {
    $sql    .= $_depo_sql;
    $params  = array_merge($params, $_depo_params);
}
$sql .= " ORDER BY r.id DESC LIMIT 500";

$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$can_unlock = function_exists('can') && can('records.unlock');
$can_write  = function_exists('can') && can('records.write');

render_header('Çıkmalar');
?>
<?php render_flash(); ?>

<div class="page-head">
    <div>
        <h1 style="color:#9b1c1c">Çıkmalar</h1>
        <p class="muted">Toplam <?= count($rows) ?> kayıt</p>
    </div>
    <a href="cikma_create.php" class="btn btn-primary btn-lg">+ Yeni Çıkma</a>
</div>

<form method="get" class="search-row">
    <?php if ($rapor_filter !== ''): ?>
    <input type="hidden" name="rapor_durumu" value="<?= h($rapor_filter) ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>"
           placeholder="Firma, bölge, ürün..." autocomplete="off">
    <button class="btn">Ara</button>
    <?php if ($q !== '' || $rapor_filter !== ''): ?>
        <a href="cikmalar.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php
$q_part = $q !== '' ? '&q=' . urlencode($q) : '';
?>
<div class="filter-pills">
    <a href="cikmalar.php<?= $q_part ? '?' . ltrim($q_part, '&') : '' ?>"
       class="pill<?= $rapor_filter === '' ? ' active' : '' ?>">Tümü</a>
    <a href="cikmalar.php?rapor_durumu=raporlanmamis<?= $q_part ?>"
       class="pill<?= $rapor_filter === 'raporlanmamis' ? ' active' : '' ?>">📋 Raporlanmadı</a>
    <a href="cikmalar.php?rapor_durumu=raporlandi<?= $q_part ?>"
       class="pill<?= $rapor_filter === 'raporlandi' ? ' active-islendi' : '' ?>">✓ Raporlandı</a>
</div>

<?php if (empty($rows)): ?>
    <div class="empty">
        <p>Henüz çıkma kaydı yok.</p>
        <a href="cikma_create.php" class="btn btn-primary">İlk kaydı oluştur</a>
    </div>
<?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <th>Tarih</th>
                <th>Oluşturma</th>
                <th>Son Düzenleme</th>
                <th>Firma</th>
                <th>Bölge</th>
                <th>Ürün</th>
                <th>Çıkış Nedeni</th>
                <th class="num">Palet</th>
                <th class="num">Kasa</th>
                <th class="num">Brüt</th>
                <th class="num">Dara</th>
                <th class="num">Net</th>
                <th>Rapor</th>
                <th class="actions-col">İşlemler</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $durum  = $r['durum'] ?? '';
                $locked = !empty($r['locked_at']);
            ?>
                <tr class="<?= ($r['reported_at'] ?? null) ? 'tr-islendi' : '' ?>"
                    data-record-id="<?= (int)$r['id'] ?>">
                    <td><?= $r['tarih'] ? h(date('d.m.Y', strtotime($r['tarih']))) : '—' ?></td>
                    <td class="muted"><?= h(fmt_datetime($r['created_at'])) ?></td>
                    <td class="muted"><?= $r['updated_at'] ? h(fmt_datetime($r['updated_at'])) : '—' ?></td>
                    <td>
                        <?= h($r['firma']) ?>
                        <?php if ($locked): ?><span class="badge-locked">🔒</span><?php endif; ?>
                    </td>
                    <td><?= h($r['bolge']) ?></td>
                    <td><?= h($r['urun']) ?></td>
                    <td><?php $cn = trim($r['cikis_nedeni'] ?? ''); if ($cn !== ''): ?><span class="cikis-nedeni-badge"><?= h($cn) ?></span><?php else: ?>—<?php endif; ?></td>
                    <td class="num"><?= (int)$r['toplam_palet'] ?></td>
                    <td class="num"><?= (int)$r['toplam_kasa'] ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_brut']) ?></td>
                    <td class="num"><?= fmt_kg($r['toplam_dara']) ?></td>
                    <td class="num strong"><span class="cikma-net-kg"><?= fmt_kg($r['toplam_net']) ?></span></td>
                    <td>
                        <?php if ($can_write): ?>
                        <form method="post" action="cikma_report_toggle.php" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="<?= ($r['reported_at'] ?? null) ? 'unmark' : 'mark' ?>">
                            <button type="submit" class="btn btn-sm<?= ($r['reported_at'] ?? null) ? ' btn-durum-islendi durum-done' : '' ?>"
                                    onclick="return confirm('<?= ($r['reported_at'] ?? null) ? 'Raporlandı işareti geri alınsın mı?' : 'Çıkma kaydı raporlandı olarak işaretlensin mi?' ?>')">
                                <?= ($r['reported_at'] ?? null) ? '✓ Raporlandı' : 'Raporla' ?>
                            </button>
                        </form>
                        <?php elseif ($r['reported_at'] ?? null): ?>
                        <span class="badge badge-yuklendi" style="font-size:.75rem">✓ Raporlandı</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions-col">
                        <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                        <div class="pc-kebab-wrap">
                            <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                            <div class="pc-dropdown" hidden>
                                <?php if (!$locked || $can_unlock): ?>
                                <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                                <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                                   onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
                                <?php else: ?>
                                <span class="pc-drop-disabled">🔒 Kilitli kayıt</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobil: kart -->
    <div class="card-list mobile-only">
        <?php foreach ($rows as $r):
            $durum  = $r['durum'] ?? '';
            $locked = !empty($r['locked_at']);
        ?>
            <div class="record-card<?= ($r['reported_at'] ?? null) ? ' durum-islendi' : '' ?>"
                 data-record-id="<?= (int)$r['id'] ?>">
                <div class="record-card-head">
                    <div>
                        <strong><?= h($r['firma'] ?: '—') ?></strong>
                        <?php if ($locked): ?><span class="badge-locked">🔒 Kilitli</span><?php endif; ?>
                        <?php if ($r['tarih']): ?>
                        <div class="record-card-firma"><?= h(date('d.m.Y', strtotime($r['tarih']))) ?></div>
                        <?php endif; ?>
                        <div class="muted"><?= h(fmt_datetime($r['created_at'])) ?><?= $r['updated_at'] ? ' · Düz: ' . h(fmt_datetime($r['updated_at'])) : '' ?></div>
                    </div>
                    <div class="pc-kebab-wrap">
                        <button class="pc-kebab" type="button" title="İşlemler">⋮</button>
                        <div class="pc-dropdown" hidden>
                            <?php if (!$locked || $can_unlock): ?>
                            <a href="record_edit.php?id=<?= (int)$r['id'] ?>">✎ Düzenle</a>
                            <a href="record_delete.php?id=<?= (int)$r['id'] ?>" class="pc-drop-danger"
                               onclick="return confirm('Bu kayıt ve tüm palet satırları silinecek. Emin misiniz?');">✕ Sil</a>
                            <?php else: ?>
                            <span class="pc-drop-disabled">🔒 Kilitli kayıt</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="record-card-body">
                    <?php if ($r['bolge']): ?><div><span class="lbl">Bölge:</span> <?= h($r['bolge']) ?></div><?php endif; ?>
                    <?php if ($r['urun']): ?><div><span class="lbl">Ürün:</span> <?= h($r['urun']) ?></div><?php endif; ?>
                    <?php $cn = trim($r['cikis_nedeni'] ?? ''); if ($cn !== ''): ?><div><span class="lbl">Çıkış Nedeni:</span> <span class="cikis-nedeni-badge"><?= h($cn) ?></span></div><?php endif; ?>
                </div>
                <div class="record-card-totals">
                    <div><span>Palet</span><strong><?= (int)$r['toplam_palet'] ?></strong></div>
                    <div><span>Brüt</span><strong><?= fmt_kg($r['toplam_brut']) ?></strong></div>
                    <div><span>Net</span><strong><?= fmt_kg($r['toplam_net']) ?></strong></div>
                </div>
                <div class="record-card-actions">
                    <a class="btn btn-sm" href="record_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
                    <?php if ($can_write): ?>
                    <form method="post" action="cikma_report_toggle.php" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="action" value="<?= ($r['reported_at'] ?? null) ? 'unmark' : 'mark' ?>">
                        <button type="submit" class="btn btn-sm<?= ($r['reported_at'] ?? null) ? ' btn-durum-islendi durum-done' : '' ?>"
                                onclick="return confirm('<?= ($r['reported_at'] ?? null) ? 'Raporlandı işareti geri alınsın mı?' : 'Çıkma kaydı raporlandı olarak işaretlensin mi?' ?>')">
                            <?= ($r['reported_at'] ?? null) ? '✓ Raporlandı' : 'Raporla' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php render_footer(); ?>
