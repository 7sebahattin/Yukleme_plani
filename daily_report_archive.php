<?php
// =========================================================
// daily_report_archive.php — Günlük Rapor Arşivi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

$f_type = trim($_GET['tip']    ?? '');
$f_from = trim($_GET['f_from'] ?? '');
$f_to   = trim($_GET['f_to']   ?? '');
if (!in_array($f_type, ['X', 'Z'], true)) $f_type = '';

$w = ["1=1"]; $p = [];
if ($f_type !== '') { $w[] = "dr.report_type = ?";  $p[] = $f_type; }
if ($f_from !== '') { $w[] = "dr.created_at >= ?";  $p[] = $f_from . ' 00:00:00'; }
if ($f_to   !== '') { $w[] = "dr.created_at <= ?";  $p[] = $f_to   . ' 23:59:59'; }

$reports = [];
$fetch_err = null;
try {
    $st = db()->prepare("
        SELECT dr.*, u.display_name AS user_name
        FROM daily_reports dr
        LEFT JOIN users u ON u.id = dr.created_by
        WHERE " . implode(' AND ', $w) . "
        ORDER BY dr.id DESC LIMIT 500");
    $st->execute($p);
    $reports = $st->fetchAll();
} catch (PDOException $_e) {
    // users tablosu veya display_name yoksa sade sorgu
    try {
        $st = db()->prepare("
            SELECT dr.*, NULL AS user_name
            FROM daily_reports dr
            WHERE " . implode(' AND ', $w) . "
            ORDER BY dr.id DESC LIMIT 500");
        $st->execute($p);
        $reports = $st->fetchAll();
    } catch (PDOException $_e2) {
        $fetch_err = $_e2->getMessage();
    }
}

render_header('Rapor Arşivi');
render_flash();
?>

<style>
.dr-archive-wrap { margin-top: 4px; }
@media print {
    .topbar, .bottomnav, .rpt-filter-card, .page-head .btn { display: none !important; }
    body { background: #fff !important; }
    .container { max-width: none !important; padding: 0 !important; }
}
</style>

<div class="page-head">
    <div>
        <a href="reports.php" class="btn btn-ghost btn-sm">← Raporlar</a>
        <h1>📁 Rapor Arşivi</h1>
        <p class="muted">Toplam <?= count($reports) ?> rapor</p>
    </div>
    <a href="reports.php?type=gunluk" class="btn btn-sm">📅 Günlük Rapor</a>
</div>

<div class="rpt-filter-card">
<form method="get" class="rpt-filter-form">
    <div class="rpt-filter-group">
        <label>Başlangıç<input type="date" name="f_from" value="<?= h($f_from) ?>"></label>
        <label>Bitiş<input type="date"     name="f_to"   value="<?= h($f_to) ?>"></label>
        <label>Tip
            <select name="tip">
                <option value="">Tümü</option>
                <option value="X" <?= $f_type === 'X' ? 'selected' : '' ?>>X Raporu</option>
                <option value="Z" <?= $f_type === 'Z' ? 'selected' : '' ?>>Z Raporu</option>
            </select>
        </label>
    </div>
    <div class="rpt-filter-actions">
        <button class="btn btn-primary btn-sm">Filtrele</button>
        <a href="daily_report_archive.php" class="btn btn-ghost btn-sm">Sıfırla</a>
    </div>
</form>
</div>

<?php if ($fetch_err !== null): ?>
<div class="empty">
    <p class="muted">Rapor arşiv tabloları henüz oluşturulmadı.</p>
    <?php if (function_exists('is_admin') && is_admin()): ?>
    <p class="muted" style="font-size:.85rem">Onarım aracını çalıştırarak tabloları oluşturabilirsiniz.</p>
    <a href="repair_xz_tables.php" class="btn btn-primary btn-sm">🔧 Onarım Aracını Aç</a>
    <?php else: ?>
    <p class="muted" style="font-size:.85rem">Sayfayı yenileyip tekrar deneyin. Sorun devam ederse sistem yöneticisine bildirin.</p>
    <?php endif; ?>
</div>
<?php elseif (empty($reports)): ?>
<div class="empty"><p>Henüz arşivlenmiş rapor yok.</p>
    <a href="reports.php?type=gunluk" class="btn btn-primary btn-sm">Günlük Rapor'a git</a>
</div>
<?php else: ?>

<!-- PC: tablo -->
<div class="table-wrap pc-only dr-archive-wrap">
<table class="data-table">
    <thead><tr>
        <th>Tarih / Aralık</th>
        <th>Rapor No</th>
        <th>Tip</th>
        <th>Oluşturan</th>
        <th>Oluşturma Saati</th>
        <th class="num">Kantar</th>
        <th class="num">Palet</th>
        <th class="num">Çıkma</th>
        <th class="num">Kantar Net KG</th>
        <th class="num">Yük. Net KG</th>
        <th class="num">Çıkma Net KG</th>
        <th class="actions-col">İşlemler</th>
    </tr></thead>
    <tbody>
    <?php foreach ($reports as $r):
        $snap = json_decode($r['snapshot_json'] ?? '', true) ?: [];
        $date_disp = '';
        if ($r['report_date']) {
            $date_disp = fmt_date($r['report_date']);
        } elseif ($r['date_from'] && $r['date_to']) {
            $date_disp = $r['date_from'] === $r['date_to']
                ? fmt_date($r['date_from'])
                : fmt_date($r['date_from']) . ' – ' . fmt_date($r['date_to']);
        } elseif ($r['date_from']) {
            $date_disp = fmt_date($r['date_from']) . ' →';
        } else {
            $date_disp = fmt_datetime($r['created_at']);
        }
    ?>
    <tr>
        <td><?= h($date_disp) ?></td>
        <td><strong>#<?= (int)$r['id'] ?></strong></td>
        <td><span class="dr-type-badge dr-type-<?= h(strtolower($r['report_type'])) ?>"><?= $r['report_type'] === 'Z' ? '🔒 Z' : h($r['report_type']) ?></span></td>
        <td><?= h($r['user_name'] ?: ('Kullanıcı #' . ($r['created_by'] ?? '?'))) ?></td>
        <td class="muted"><?= h(fmt_datetime($r['created_at'])) ?></td>
        <td class="num"><?= isset($snap['kantar_count'])        ? (int)$snap['kantar_count']        : '—' ?></td>
        <td class="num"><?= isset($snap['yukleme_palet_count']) ? (int)$snap['yukleme_palet_count'] : '—' ?></td>
        <td class="num"><?= isset($snap['cikma_count'])         ? (int)$snap['cikma_count']         : '—' ?></td>
        <td class="num"><?= isset($snap['kantar_net_kg'])   ? fmt_kg((float)$snap['kantar_net_kg'])   : '—' ?></td>
        <td class="num"><?= isset($snap['yukleme_net_kg'])  ? fmt_kg((float)$snap['yukleme_net_kg'])  : '—' ?></td>
        <td class="num"><?= isset($snap['cikma_net_kg'])    ? fmt_kg((float)$snap['cikma_net_kg'])    : '—' ?></td>
        <td class="actions-col">
            <a href="daily_report_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a>
            <a href="daily_report_view.php?id=<?= (int)$r['id'] ?>&print=1" class="btn btn-sm" target="_blank">🖨</a>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Mobil: kart -->
<div class="card-list mobile-only">
<?php foreach ($reports as $r):
    $snap = json_decode($r['snapshot_json'] ?? '', true) ?: [];
    $date_disp = '';
    if ($r['report_date']) {
        $date_disp = fmt_date($r['report_date']);
    } elseif ($r['date_from'] && $r['date_to']) {
        $date_disp = $r['date_from'] === $r['date_to']
            ? fmt_date($r['date_from'])
            : fmt_date($r['date_from']) . ' – ' . fmt_date($r['date_to']);
    } else {
        $date_disp = fmt_datetime($r['created_at']);
    }
?>
<div class="record-card">
    <div class="record-card-head">
        <div>
            <strong>#<?= (int)$r['id'] ?> &nbsp;<span class="dr-type-badge dr-type-<?= h(strtolower($r['report_type'])) ?>"><?= $r['report_type'] === 'Z' ? '🔒 Z' : h($r['report_type']) ?></span></strong>
            <div class="muted"><?= h($date_disp) ?> · <?= h(fmt_datetime($r['created_at'])) ?></div>
        </div>
    </div>
    <div class="record-card-body">
        <div><span class="lbl">Oluşturan:</span> <?= h($r['user_name'] ?: ('Kullanıcı #' . ($r['created_by'] ?? '?'))) ?></div>
    </div>
    <div class="record-card-totals">
        <?php if (isset($snap['kantar_count'])): ?>
        <div><span>Kantar</span><strong><?= (int)$snap['kantar_count'] ?></strong></div>
        <?php endif; ?>
        <?php if (isset($snap['yukleme_palet_count'])): ?>
        <div><span>Palet</span><strong><?= (int)$snap['yukleme_palet_count'] ?></strong></div>
        <?php endif; ?>
        <?php if (isset($snap['cikma_count'])): ?>
        <div><span>Çıkma</span><strong><?= (int)$snap['cikma_count'] ?></strong></div>
        <?php endif; ?>
    </div>
    <div class="record-card-actions">
        <a class="btn btn-sm" href="daily_report_view.php?id=<?= (int)$r['id'] ?>">Görüntüle</a>
        <a class="btn btn-sm" href="daily_report_view.php?id=<?= (int)$r['id'] ?>&print=1" target="_blank">🖨 Yazdır</a>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
