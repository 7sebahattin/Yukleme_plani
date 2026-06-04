<?php
// =========================================================
// daily_report_view.php — X/Z Rapor Görüntüleme (snapshot)
// XZ-02: Sadece snapshot_json'dan render eder, canlı sorgu yok
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: daily_report_archive.php'); exit; }

$print_mode = isset($_GET['print']) && (int)$_GET['print'] === 1;

// Raporu yükle
$report = null;
try {
    $st = db()->prepare("SELECT dr.*, u.display_name AS user_name
        FROM daily_reports dr
        LEFT JOIN users u ON u.id = dr.created_by
        WHERE dr.id = ? LIMIT 1");
    $st->execute([$id]);
    $report = $st->fetch();
} catch (PDOException $_e) {
    try {
        $st = db()->prepare("SELECT dr.*, NULL AS user_name FROM daily_reports dr WHERE dr.id = ? LIMIT 1");
        $st->execute([$id]);
        $report = $st->fetch();
    } catch (PDOException $_e2) {}
}

if (!$report) {
    set_flash('error', 'Rapor bulunamadı.');
    header('Location: daily_report_archive.php'); exit;
}

// Kalemleri yükle
$items = [];
try {
    $st = db()->prepare("SELECT * FROM daily_report_items WHERE report_id = ? ORDER BY item_type, id ASC");
    $st->execute([$id]);
    $items = $st->fetchAll();
} catch (PDOException $_e) {}

// item_type'a göre ayır
$kantar_items  = [];
$cikma_items   = [];
$palet_items   = [];
foreach ($items as $itm) {
    $snap = json_decode($itm['snapshot_json'] ?? '', true) ?: [];
    if ($itm['item_type'] === 'kantar')        { $kantar_items[]  = $snap; }
    elseif ($itm['item_type'] === 'cikma')     { $cikma_items[]   = $snap; }
    elseif ($itm['item_type'] === 'yukleme_palet') {
        $rec_id = (int)($itm['source_detail_id'] ?? 0);
        if (!isset($palet_items[$rec_id])) {
            $palet_items[$rec_id] = [
                'record_id'   => $rec_id,
                'tarih'       => $snap['tarih']  ?? '',
                'firma'       => $snap['firma']  ?? '',
                'bolge'       => $snap['bolge']  ?? '',
                'alici'       => $snap['alici']  ?? '',
                'urun'        => $snap['urun']   ?? '',
                'depo'        => $snap['depo']   ?? '',
                'durum'       => $snap['durum']  ?? '',
                'palet_sayisi' => 0,
                'toplam_kasa' => 0,
                'toplam_brut' => 0.0,
                'toplam_dara' => 0.0,
                'toplam_net'  => 0.0,
                'paletler'    => [],
            ];
        }
        $palet_items[$rec_id]['palet_sayisi']++;
        $palet_items[$rec_id]['toplam_kasa'] += (int)($snap['kasa_adeti'] ?? 0);
        $palet_items[$rec_id]['toplam_brut'] += (float)($snap['brut_kg']  ?? 0);
        $palet_items[$rec_id]['toplam_dara'] += (float)($snap['dara_kg']  ?? 0);
        $palet_items[$rec_id]['toplam_net']  += (float)($snap['net_kg']   ?? 0);
        $palet_items[$rec_id]['paletler'][]   = $snap;
    }
}

// Rapor seviyesi snapshot ve filtreler
$rpt_snap    = json_decode($report['snapshot_json'] ?? '', true) ?: [];
$rpt_filters = $rpt_snap['filters'] ?? [];

// Tarih gösterimi
$date_disp = '';
if ($report['report_date']) {
    $date_disp = fmt_date($report['report_date']);
} elseif ($report['date_from'] && $report['date_to']) {
    $date_disp = $report['date_from'] === $report['date_to']
        ? fmt_date($report['date_from'])
        : fmt_date($report['date_from']) . ' – ' . fmt_date($report['date_to']);
} elseif ($report['date_from']) {
    $date_disp = fmt_date($report['date_from']) . ' →';
} else {
    $date_disp = fmt_datetime($report['created_at']);
}

$page_title = h($report['title'] ?: ('Rapor #' . $id));

render_header($page_title, $print_mode);
render_flash();
?>

<style>
/* ── Rapor görüntüleme ── */
.drv-meta { display:flex; flex-wrap:wrap; gap:12px 24px; margin:8px 0 16px; font-size:.85rem; color:var(--text-muted,#666); }
.drv-meta strong { color:var(--text,#222); }
.drv-section { margin-bottom:28px; }
.drv-section-title { font-size:1rem; font-weight:600; border-bottom:2px solid var(--border,#e5e7eb); padding-bottom:6px; margin-bottom:10px; }
.drv-totals-bar { display:flex; flex-wrap:wrap; gap:8px 20px; background:var(--bg-alt,#f8f9fa); border:1px solid var(--border,#e5e7eb); border-radius:8px; padding:10px 14px; margin-bottom:10px; font-size:.88rem; }
.drv-totals-bar .t-item { display:flex; flex-direction:column; }
.drv-totals-bar .t-item span { color:var(--text-muted,#666); font-size:.78rem; }
.drv-totals-bar .t-item strong { font-size:1rem; }
.drv-empty { color:var(--text-muted,#666); font-style:italic; padding:6px 0; }
.drv-record-group { border:1px solid var(--border,#e5e7eb); border-radius:8px; margin-bottom:10px; overflow:hidden; }
.drv-record-head { background:var(--bg-alt,#f8f9fa); padding:8px 14px; display:flex; flex-wrap:wrap; gap:4px 16px; font-size:.85rem; cursor:pointer; user-select:none; }
.drv-record-head strong { font-size:.92rem; }
.drv-record-pallets { overflow:hidden; }
.drv-filter-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
.drv-chip { background:var(--bg-alt,#f8f9fa); border:1px solid var(--border,#e5e7eb); border-radius:20px; padding:2px 10px; font-size:.78rem; color:var(--text,#444); }

@media print {
    .topbar, .bottomnav, .page-head .btn, .drv-back-btn, .sidebar { display:none !important; }
    body { background:#fff !important; }
    .container { max-width:none !important; padding:0 !important; }
    .drv-record-pallets { display:block !important; max-height:none !important; }
    .drv-record-group { break-inside:avoid; }
    .drv-section { break-inside:avoid; }
}
</style>

<div class="page-head">
    <div>
        <?php if (!$print_mode): ?>
        <a href="daily_report_archive.php" class="btn btn-ghost btn-sm drv-back-btn">← Arşiv</a>
        <?php endif; ?>
        <h1>
            <span class="dr-type-badge dr-type-<?= h(strtolower($report['report_type'])) ?>"><?= h($report['report_type']) ?></span>
            <?= $page_title ?>
        </h1>
        <p class="muted"><?= h($date_disp) ?></p>
    </div>
    <?php if (!$print_mode): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="daily_report_view.php?id=<?= $id ?>&print=1" class="btn btn-sm" target="_blank">🖨 Yazdır</a>
        <a href="daily_report_archive.php" class="btn btn-ghost btn-sm">📁 Arşiv</a>
    </div>
    <?php endif; ?>
</div>

<!-- Meta bilgi -->
<div class="drv-meta">
    <div><span>Rapor No: </span><strong>#<?= $id ?></strong></div>
    <div><span>Tip: </span><strong><?= h($report['report_type']) ?></strong></div>
    <div><span>Oluşturan: </span><strong><?= h($report['user_name'] ?: ('Kullanıcı #' . ($report['created_by'] ?? '?'))) ?></strong></div>
    <div><span>Oluşturma: </span><strong><?= h(fmt_datetime($report['created_at'])) ?></strong></div>
    <div><span>Durum: </span><strong><?= h($report['status'] ?? 'final') ?></strong></div>
</div>

<!-- Filtre bilgisi -->
<?php
$chip_parts = [];
if (!empty($rpt_filters['date_from'])) {
    $chip_parts[] = 'Tarih: ' . fmt_date($rpt_filters['date_from'])
        . ($rpt_filters['date_to'] && $rpt_filters['date_to'] !== $rpt_filters['date_from']
            ? ' – ' . fmt_date($rpt_filters['date_to']) : '');
}
if (!empty($rpt_filters['firma'])) $chip_parts[] = 'Firma: ' . $rpt_filters['firma'];
if (!empty($rpt_filters['urun']))  $chip_parts[] = 'Ürün: '  . $rpt_filters['urun'];
if (!empty($rpt_filters['depo']))  $chip_parts[] = 'Depo: '  . $rpt_filters['depo'];
$pi = $rpt_filters['palet_islendi'] ?? 'hicbiri';
$pi_label = $pi === 'isaretli' ? 'İşaretli paletler' : ($pi === '' ? 'Tüm paletler' : 'İşlenmemiş paletler');
$chip_parts[] = 'Palet: ' . $pi_label;
?>
<?php if (!empty($chip_parts)): ?>
<div class="drv-filter-chips">
    <?php foreach ($chip_parts as $cp): ?>
    <span class="drv-chip"><?= h($cp) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Özet bar -->
<div class="drv-totals-bar">
    <div class="t-item">
        <span>Kantar Fişi</span>
        <strong><?= isset($rpt_snap['kantar_count']) ? (int)$rpt_snap['kantar_count'] : count($kantar_items) ?></strong>
    </div>
    <div class="t-item">
        <span>Kantar Net KG</span>
        <strong><?= isset($rpt_snap['kantar_net_kg']) ? fmt_kg((float)$rpt_snap['kantar_net_kg']) : '—' ?></strong>
    </div>
    <div class="t-item">
        <span>Yükleme Paleti</span>
        <strong><?= isset($rpt_snap['yukleme_palet_count']) ? (int)$rpt_snap['yukleme_palet_count'] : count($items) ?></strong>
    </div>
    <div class="t-item">
        <span>Yük. Net KG</span>
        <strong><?= isset($rpt_snap['yukleme_net_kg']) ? fmt_kg((float)$rpt_snap['yukleme_net_kg']) : '—' ?></strong>
    </div>
    <div class="t-item">
        <span>Çıkma Kaydı</span>
        <strong><?= isset($rpt_snap['cikma_count']) ? (int)$rpt_snap['cikma_count'] : count($cikma_items) ?></strong>
    </div>
    <div class="t-item">
        <span>Çıkma Net KG</span>
        <strong><?= isset($rpt_snap['cikma_net_kg']) ? fmt_kg((float)$rpt_snap['cikma_net_kg']) : '—' ?></strong>
    </div>
</div>

<!-- ══ 1. Kantar Fişleri ══ -->
<div class="drv-section">
    <div class="drv-section-title">Kantar Fişleri (<?= count($kantar_items) ?>)</div>
    <?php if (empty($kantar_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th>Fiş No</th>
            <th>Tarih</th>
            <th>Firma</th>
            <th>Mal Cinsi</th>
            <th>Plaka</th>
            <th>Depo</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Dara KG</th>
            <th class="num">Net KG</th>
        </tr></thead>
        <tbody>
        <?php
        $k_brut_total = 0.0; $k_dara_total = 0.0; $k_net_total = 0.0; $k_kasa_total = 0;
        foreach ($kantar_items as $ki):
            $k_brut_total += (float)($ki['brut_kg'] ?? 0);
            $k_dara_total += (float)($ki['dara_kg'] ?? 0);
            $k_net_total  += (float)($ki['net_kg']  ?? 0);
            $k_kasa_total += (int)($ki['kasa_sayisi'] ?? 0);
        ?>
        <tr>
            <td><?= h($ki['fis_no'] ?? '—') ?></td>
            <td><?= h(isset($ki['giris_tarih']) ? fmt_date($ki['giris_tarih']) : '—') ?></td>
            <td><?= h($ki['firma_adi'] ?? '—') ?></td>
            <td><?= h($ki['malin_cinsi'] ?? '—') ?></td>
            <td><?= h($ki['plaka'] ?? '—') ?></td>
            <td><?= h($ki['depo'] ?? '—') ?></td>
            <td class="num"><?= $k_kasa_total > 0 ? (int)($ki['kasa_sayisi'] ?? 0) : '—' ?></td>
            <td class="num"><?= fmt_kg((float)($ki['brut_kg'] ?? 0)) ?></td>
            <td class="num"><?= fmt_kg((float)($ki['dara_kg'] ?? 0)) ?></td>
            <td class="num"><strong><?= fmt_kg((float)($ki['net_kg'] ?? 0)) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="6"><strong>Toplam</strong></td>
            <td class="num"><strong><?= $k_kasa_total ?></strong></td>
            <td class="num"><strong><?= fmt_kg($k_brut_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($k_dara_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($k_net_total) ?></strong></td>
        </tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══ 2. Makineye Dökülen (Yükleme Paletleri — record bazında gruplu) ══ -->
<div class="drv-section">
    <div class="drv-section-title">Makineye Dökülen (<?= count($palet_items) ?> yükleme, <?= array_sum(array_column($palet_items, 'palet_sayisi')) ?> palet)</div>
    <?php if (empty($palet_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>
    <?php
    $m_brut_total = 0.0; $m_dara_total = 0.0; $m_net_total = 0.0;
    $m_kasa_total = 0;
    foreach ($palet_items as $rec):
        $m_brut_total += $rec['toplam_brut'];
        $m_dara_total += $rec['toplam_dara'];
        $m_net_total  += $rec['toplam_net'];
        $m_kasa_total += $rec['toplam_kasa'];
    ?>
    <div class="drv-record-group">
        <div class="drv-record-head" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'':'none'">
            <strong><?= h($rec['tarih'] ? fmt_date($rec['tarih']) : '—') ?></strong>
            <span><?= h($rec['firma'] ?? '—') ?></span>
            <?php if (!empty($rec['bolge'])): ?><span><?= h($rec['bolge']) ?></span><?php endif; ?>
            <?php if (!empty($rec['alici'])): ?><span><?= h($rec['alici']) ?></span><?php endif; ?>
            <span><?= h($rec['urun'] ?? '—') ?></span>
            <?php if (!empty($rec['depo'])): ?><span class="muted">Depo: <?= h($rec['depo']) ?></span><?php endif; ?>
            <span class="muted"><?= $rec['palet_sayisi'] ?> palet · <?= fmt_kg($rec['toplam_net']) ?> net</span>
        </div>
        <div class="drv-record-pallets">
        <div class="table-wrap">
        <table class="data-table">
            <thead><tr>
                <th>Palet No</th>
                <th>Depo</th>
                <th class="num">Kasa</th>
                <th class="num">Brüt KG</th>
                <th class="num">Dara KG</th>
                <th class="num">Net KG</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rec['paletler'] as $pp): ?>
            <tr>
                <td><?= h($pp['palet_no'] ?? '—') ?></td>
                <td><?= h($pp['depo'] ?? '—') ?></td>
                <td class="num"><?= (int)($pp['kasa_adeti'] ?? 0) ?></td>
                <td class="num"><?= fmt_kg((float)($pp['brut_kg'] ?? 0)) ?></td>
                <td class="num"><?= fmt_kg((float)($pp['dara_kg'] ?? 0)) ?></td>
                <td class="num"><strong><?= fmt_kg((float)($pp['net_kg'] ?? 0)) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="2"><strong>Alt Toplam</strong></td>
                <td class="num"><strong><?= $rec['toplam_kasa'] ?></strong></td>
                <td class="num"><strong><?= fmt_kg($rec['toplam_brut']) ?></strong></td>
                <td class="num"><strong><?= fmt_kg($rec['toplam_dara']) ?></strong></td>
                <td class="num"><strong><?= fmt_kg($rec['toplam_net']) ?></strong></td>
            </tr></tfoot>
        </table>
        </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="drv-totals-bar" style="margin-top:8px;">
        <div class="t-item"><span>Toplam Kasa</span><strong><?= $m_kasa_total ?></strong></div>
        <div class="t-item"><span>Toplam Brüt</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
        <div class="t-item"><span>Toplam Dara</span><strong><?= fmt_kg($m_dara_total) ?></strong></div>
        <div class="t-item"><span>Toplam Net</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
    </div>
    <?php endif; ?>
</div>

<!-- ══ 3. Çıkmalar ══ -->
<div class="drv-section">
    <div class="drv-section-title">Çıkmalar (<?= count($cikma_items) ?>)</div>
    <?php if (empty($cikma_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th>Tarih</th>
            <th>Firma</th>
            <th>Ürün</th>
            <th>Çıkış Nedeni</th>
            <th>Depo</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Dara KG</th>
            <th class="num">Net KG</th>
        </tr></thead>
        <tbody>
        <?php
        $c_brut_total = 0.0; $c_dara_total = 0.0; $c_net_total = 0.0;
        $c_kasa_total = 0; $c_palet_total = 0;
        foreach ($cikma_items as $ci):
            $c_brut_total  += (float)($ci['toplam_brut'] ?? 0);
            $c_dara_total  += (float)($ci['toplam_dara'] ?? 0);
            $c_net_total   += (float)($ci['toplam_net']  ?? 0);
            $c_kasa_total  += (int)($ci['toplam_kasa']   ?? 0);
            $c_palet_total += (int)($ci['palet_sayisi']  ?? 0);
        ?>
        <tr>
            <td><?= h(isset($ci['tarih']) ? fmt_date($ci['tarih']) : '—') ?></td>
            <td><?= h($ci['firma'] ?? '—') ?></td>
            <td><?= h($ci['urun']  ?? '—') ?></td>
            <td><?= h($ci['cikis_nedeni'] ?? '—') ?></td>
            <td><?= h($ci['depo'] ?? '—') ?></td>
            <td class="num"><?= (int)($ci['palet_sayisi'] ?? 0) ?></td>
            <td class="num"><?= (int)($ci['toplam_kasa']  ?? 0) ?></td>
            <td class="num"><?= fmt_kg((float)($ci['toplam_brut'] ?? 0)) ?></td>
            <td class="num"><?= fmt_kg((float)($ci['toplam_dara'] ?? 0)) ?></td>
            <td class="num"><strong><?= fmt_kg((float)($ci['toplam_net'] ?? 0)) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="5"><strong>Toplam</strong></td>
            <td class="num"><strong><?= $c_palet_total ?></strong></td>
            <td class="num"><strong><?= $c_kasa_total ?></strong></td>
            <td class="num"><strong><?= fmt_kg($c_brut_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($c_dara_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($c_net_total) ?></strong></td>
        </tr></tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
