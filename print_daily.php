<?php
// =========================================================
// print_daily.php — X/Z Günlük Rapor Yazdırma (Sprint Print-02)
// Yalnızca snapshot verisi kullanılır; canlı sorgu yapılmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_perm('reports.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: daily_report_archive.php'); exit; }

$mode = print_mode();

// ── Raporu yükle ──────────────────────────────────────────
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
if (!$report) { header('Location: daily_report_archive.php'); exit; }

// ── Kalemleri yükle (snapshot'tan — canlı sorgu yapılmaz) ─
$items = [];
try {
    $st = db()->prepare("SELECT * FROM daily_report_items WHERE report_id = ? ORDER BY item_type, id ASC");
    $st->execute([$id]);
    $items = $st->fetchAll();
} catch (PDOException $_e) {}

// item_type'a göre ayır — daily_report_view.php ile aynı mantık
$kantar_items = [];
$cikma_items  = [];
$palet_items  = [];
foreach ($items as $itm) {
    $snap = json_decode($itm['snapshot_json'] ?? '', true) ?: [];
    if ($itm['item_type'] === 'kantar') {
        $kantar_items[] = $snap;
    } elseif ($itm['item_type'] === 'cikma') {
        $cikma_items[] = $snap;
    } elseif ($itm['item_type'] === 'yukleme_palet') {
        $rec_id = (int)($itm['source_detail_id'] ?? 0);
        if (!isset($palet_items[$rec_id])) {
            $palet_items[$rec_id] = [
                'record_id'    => $rec_id,
                'tarih'        => $snap['tarih']  ?? '',
                'firma'        => $snap['firma']  ?? '',
                'bolge'        => $snap['bolge']  ?? '',
                'alici'        => $snap['alici']  ?? '',
                'urun'         => $snap['urun']   ?? '',
                'depo'         => $snap['depo']   ?? '',
                'palet_sayisi' => 0,
                'toplam_kasa'  => 0,
                'toplam_brut'  => 0.0,
                'toplam_dara'  => 0.0,
                'toplam_net'   => 0.0,
                'paletler'     => [],
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

// ── Toplamlar ─────────────────────────────────────────────
$k_brut_total = 0.0; $k_dara_total = 0.0; $k_net_total = 0.0;
$k_kasa_total = 0; $k_palet_total = 0;
foreach ($kantar_items as $ki) {
    $k_brut_total  += (float)($ki['brut_kg']    ?? 0);
    $k_dara_total  += (float)($ki['dara_kg']    ?? 0);
    $k_net_total   += (float)($ki['net_kg']     ?? 0);
    $k_kasa_total  += (int)($ki['kasa_sayisi']  ?? 0);
    $k_palet_total += (int)($ki['palet_sayisi'] ?? 0);
}

$m_brut_total  = 0.0; $m_dara_total = 0.0; $m_net_total = 0.0; $m_kasa_total = 0;
$m_palet_total = (int)array_sum(array_column($palet_items, 'palet_sayisi'));
foreach ($palet_items as $rec) {
    $m_brut_total += $rec['toplam_brut'];
    $m_dara_total += $rec['toplam_dara'];
    $m_net_total  += $rec['toplam_net'];
    $m_kasa_total += $rec['toplam_kasa'];
}

$c_brut_total = 0.0; $c_dara_total = 0.0; $c_net_total = 0.0;
$c_kasa_total = 0; $c_palet_total = 0;
foreach ($cikma_items as $ci) {
    $c_brut_total  += (float)($ci['toplam_brut'] ?? 0);
    $c_dara_total  += (float)($ci['toplam_dara'] ?? 0);
    $c_net_total   += (float)($ci['toplam_net']  ?? 0);
    $c_kasa_total  += (int)($ci['toplam_kasa']   ?? 0);
    $c_palet_total += (int)($ci['palet_sayisi']  ?? 0);
}

// ── Hazır Palet (Soğuk Hava — canlı sorgu) ───────────────
// loading_records.durum='yuklendi', bu raporun makineye dökülen kayıtları hariç
$hazir_palet = ['palet' => 0, 'kasa' => 0, 'brut' => 0.0, 'dara' => 0.0, 'net' => 0.0];
try {
    $hp_snap    = json_decode($report['snapshot_json'] ?? '', true) ?: [];
    $hp_filters = $hp_snap['filters'] ?? [];
    $hw = ["lr.type='yukleme'", "lr.durum='yuklendi'"];
    $hp = [$id];
    $hw[] = "lr.id NOT IN (
        SELECT DISTINCT source_detail_id
        FROM daily_report_items
        WHERE report_id=? AND item_type='yukleme_palet'
              AND source_detail_id IS NOT NULL AND source_detail_id > 0
    )";
    if (!empty($hp_filters['firma'])) { $hw[] = "lr.firma=?"; $hp[] = $hp_filters['firma']; }
    if (!empty($hp_filters['urun']))  { $hw[] = "lr.urun=?";  $hp[] = $hp_filters['urun']; }
    if (!empty($hp_filters['depo']))  { $hw[] = "lp.depo=?";  $hp[] = $hp_filters['depo']; }
    $st = db()->prepare("
        SELECT COUNT(DISTINCT lr.id) AS record_count,
               COUNT(lp.id)          AS palet_count,
               COALESCE(SUM(lp.kasa_adeti),0)       AS kasa_total,
               ROUND(COALESCE(SUM(lp.brut_kg),0),3) AS brut_total,
               ROUND(COALESCE(SUM(lp.dara_kg),0),3) AS dara_total,
               ROUND(COALESCE(SUM(lp.net_kg),0),3)  AS net_total
        FROM loading_records lr
        JOIN loading_pallets lp ON lp.loading_record_id = lr.id
        WHERE " . implode(' AND ', $hw));
    $st->execute($hp);
    $hp_row = $st->fetch();
    if ($hp_row) {
        $hazir_palet = [
            'palet' => (int)($hp_row['palet_count']  ?? 0),
            'kasa'  => (int)($hp_row['kasa_total']   ?? 0),
            'brut'  => (float)($hp_row['brut_total'] ?? 0),
            'dara'  => (float)($hp_row['dara_total'] ?? 0),
            'net'   => (float)($hp_row['net_total']  ?? 0),
        ];
    }
} catch (PDOException $_hp_e) {}

// ── Rapor meta ────────────────────────────────────────────
$date_disp = '';
if ($report['report_date']) {
    $date_disp = fmt_date($report['report_date']);
} elseif ($report['date_from'] && $report['date_to']) {
    $date_disp = $report['date_from'] === $report['date_to']
        ? fmt_date($report['date_from'])
        : fmt_date($report['date_from']) . ' – ' . fmt_date($report['date_to']);
} elseif ($report['date_from']) {
    $date_disp = fmt_date($report['date_from']) . ' →';
}

$is_z       = $report['report_type'] === 'Z';
$type_badge = $is_z ? 'Z (Kapalı)' : 'X (Snapshot)';
$rpt_title  = $report['title'] ?: ('Rapor #' . $id);

// ── Yazdırma kurulumu ─────────────────────────────────────
$orientation = print_orientation($mode, $mode === 'detail' ? 9 : 5);
$page_title  = $type_badge . ' — ' . $rpt_title;
if ($date_disp !== '') $page_title .= ' — ' . $date_disp;

render_print_page_start($page_title, 'daily', $mode, $orientation);
?>
<style>
@page { size: <?= $orientation === 'landscape' ? 'A4 landscape' : 'A4 portrait' ?>; margin: <?= $orientation === 'landscape' ? '10mm 8mm' : '12mm' ?>; }

/* ── print_daily scoped ── */
.pd-header-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 9pt;
    font-weight: 700;
    margin-right: 6px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.pd-badge-z { background: #fef2f2 !important; color: #991b1b !important; border: 1px solid #fca5a5; }
.pd-badge-x { background: #eff6ff !important; color: #1d4ed8 !important; border: 1px solid #93c5fd; }

.pd-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 20px;
    font-size: 8pt;
    color: #555;
    border-bottom: 1px solid #ccc;
    padding-bottom: 5px;
    margin-bottom: 10px;
}
.pd-meta-row strong { color: #111; }

.pd-section { margin-bottom: 14px; }
.pd-section-title {
    font-size: 10.5pt;
    font-weight: 700;
    border-bottom: 1.5px solid #333;
    padding-bottom: 3px;
    margin-bottom: 6px;
    break-after: avoid;
}

/* Özet kutuları */
.pd-summary-row {
    display: flex;
    gap: 8px;
    flex-wrap: nowrap;
    margin: 4px 0 7px;
    break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.pd-ps-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 72px;
    padding: 5px 10px;
    border-radius: 5px;
    border: 2px solid;
    font-variant-numeric: tabular-nums;
    break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.pd-ps-item span   { font-size: 6.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
.pd-ps-item strong { font-size: 13pt; font-weight: 900; line-height: 1; }
.pd-ps-palet { border-color: #7c3aed; color: #7c3aed; }
.pd-ps-kasa  { border-color: #6b7280; color: #6b7280; }
.pd-ps-fis   { border-color: #2563eb; color: #2563eb; }
.pd-ps-brut  { border-color: #2563eb; color: #2563eb; }
.pd-ps-dara  { border-color: #d97706; color: #d97706; }
.pd-ps-net   { border-color: #059669; color: #059669; }

.pd-empty { color: #666; font-style: italic; font-size: 9pt; padding: 4px 0 6px; }

/* Tablo */
table.pd-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
    break-inside: auto;
}
table.pd-table th,
table.pd-table td {
    border: 1px solid #333;
    padding: 2px 4px;
    vertical-align: middle;
}
table.pd-table th {
    background: #eef2f8 !important;
    font-weight: bold;
    text-align: left;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.pd-table td.num,
table.pd-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
table.pd-table tfoot td {
    background: #fff5cc !important;
    font-weight: bold;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
thead { display: table-header-group; }
tfoot { display: table-footer-group; }

/* Detay modu — kayıt grup başlığı satırı */
.pd-group-row td {
    background: #f0f4ff !important;
    font-weight: 700;
    font-size: 7.5pt;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
/* Palet alt satırları */
.pd-palet-row td { font-size: 7.5pt; color: #333; }
.pd-palet-row td:first-child { padding-left: 16px; color: #555; }

/* Hazır palet bölümü */
.pd-hazir-title {
    font-size: 10.5pt;
    font-weight: 700;
    border-bottom: 1.5px solid #dc2626;
    padding-bottom: 3px;
    margin-bottom: 2px;
    color: #dc2626;
    break-after: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.pd-hazir-subtitle {
    font-size: 8pt;
    color: #dc2626;
    margin-bottom: 6px;
    font-style: italic;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.pd-ps-hazir { border-color: #dc2626 !important; color: #dc2626 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
</style>

<div class="print-sheet">

<?php
$badge_class = $is_z ? 'pd-badge-z' : 'pd-badge-x';
$meta_right  = 'Oluşturma: ' . htmlspecialchars(fmt_datetime($report['created_at']), ENT_QUOTES);
if ($is_z && !empty($report['closed_at'])) {
    $meta_right .= ' · Kapanış: ' . htmlspecialchars(fmt_datetime($report['closed_at']), ENT_QUOTES);
}
echo render_print_header_html(
    $rpt_title,
    $type_badge . ($date_disp !== '' ? '  —  ' . $date_disp : ''),
    strip_tags($meta_right)
);
?>

<div class="pd-meta-row">
    <div>Rapor No: <strong>#<?= $id ?></strong></div>
    <div>Tip: <strong><?= h($type_badge) ?></strong></div>
    <div>Oluşturan: <strong><?= h($report['user_name'] ?: ('Kullanıcı #' . ($report['created_by'] ?? '?'))) ?></strong></div>
    <?php if (!empty($report['closed_at'])): ?>
    <div>Kapanış: <strong><?= h(fmt_datetime($report['closed_at'])) ?></strong></div>
    <?php endif; ?>
</div>

<?php if ($mode === 'summary'): ?>
<!-- ══════════════════════════════════════════════════════
     ÖZET ŞABLON — Portrait A4
══════════════════════════════════════════════════════ -->

<!-- 1. Kantar Fişleri -->
<div class="pd-section">
<div class="pd-section-title">1. Kantar Fişleri (<?= count($kantar_items) ?>)</div>
<?php if (empty($kantar_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <?php if ($k_palet_total > 0): ?>
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $k_palet_total ?></strong></div>
    <?php endif; ?>
    <?php if ($k_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($k_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-fis"><span>FİŞ</span><strong><?= count($kantar_items) ?></strong></div>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($k_brut_total) ?></strong></div>
    <?php if ($k_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($k_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($k_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Fiş No</th><th>Firma</th><th>Mal Cinsi</th><th>Depo</th>
        <th class="num">Kasa</th><th class="num">Brüt KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($kantar_items as $ki): ?>
    <tr>
        <td><?= h($ki['fis_no'] ?? '—') ?></td>
        <td><?= h($ki['firma_adi'] ?? '—') ?></td>
        <td><?= h($ki['malin_cinsi'] ?? '—') ?></td>
        <td><?= h($ki['depo'] ?? '—') ?></td>
        <td class="num"><?= (int)($ki['kasa_sayisi'] ?? 0) ?: '—' ?></td>
        <td class="num"><?= fmt_kg((float)($ki['brut_kg'] ?? 0)) ?></td>
        <td class="num"><strong><?= fmt_kg((float)($ki['net_kg'] ?? 0)) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="4"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $k_kasa_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($k_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($k_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<!-- 2. Makineye Dökülen -->
<div class="pd-section">
<div class="pd-section-title">2. Makineye Dökülen (<?= count($palet_items) ?> yükleme, <?= $m_palet_total ?> palet)</div>
<?php if (empty($palet_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $m_palet_total ?></strong></div>
    <?php if ($m_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($m_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
    <?php if ($m_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($m_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Firma</th><th>Alıcı</th><th>Ürün</th><th>Depo</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
        <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($palet_items as $rec): ?>
    <tr>
        <td><?= h($rec['firma'] ?? '—') ?></td>
        <td><?= h($rec['alici'] ?? '') ?: '—' ?></td>
        <td><?= h($rec['urun']  ?? '—') ?></td>
        <td><?= h($rec['depo']  ?? '') ?: '—' ?></td>
        <td class="num"><?= $rec['palet_sayisi'] ?></td>
        <td class="num"><?= $rec['toplam_kasa'] ?: '—' ?></td>
        <td class="num"><?= fmt_kg($rec['toplam_brut']) ?></td>
        <td class="num"><?= fmt_kg($rec['toplam_dara']) ?></td>
        <td class="num"><strong><?= fmt_kg($rec['toplam_net']) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="4"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $m_palet_total ?></strong></td>
        <td class="num"><strong><?= $m_kasa_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_dara_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<!-- Hazır Palet (Soğuk Hava Deposu) -->
<div class="pd-section">
<div class="pd-hazir-title">Soğuk Havada Hazır Palet</div>
<div class="pd-hazir-subtitle">(Bugün makineye dökülen hariç, soğuk havada hazır palet adeti)</div>
<?php if ($hazir_palet['palet'] === 0): ?>
    <p class="pd-empty" style="color:#dc2626;">Hazır palet yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <div class="pd-ps-item pd-ps-hazir"><span>PALET</span><strong><?= $hazir_palet['palet'] ?></strong></div>
    <?php if ($hazir_palet['kasa'] > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($hazir_palet['kasa'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($hazir_palet['brut']) ?></strong></div>
    <?php if ($hazir_palet['dara'] > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($hazir_palet['dara']) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($hazir_palet['net']) ?></strong></div>
</div>
<?php endif; ?>
</div>

<!-- 3. Çıkmalar -->
<div class="pd-section">
<div class="pd-section-title">3. Çıkmalar (<?= count($cikma_items) ?>)</div>
<?php if (empty($cikma_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <?php if ($c_palet_total > 0): ?>
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $c_palet_total ?></strong></div>
    <?php endif; ?>
    <?php if ($c_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($c_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($c_brut_total) ?></strong></div>
    <?php if ($c_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($c_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($c_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Firma</th><th>Ürün</th><th>Çıkış Nedeni</th><th>Depo</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
        <th class="num">Brüt KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($cikma_items as $ci): ?>
    <tr>
        <td><?= h($ci['firma'] ?? '—') ?></td>
        <td><?= h($ci['urun']  ?? '—') ?></td>
        <td><?= h($ci['cikis_nedeni'] ?? '—') ?></td>
        <td><?= h($ci['depo']  ?? '—') ?></td>
        <td class="num"><?= (int)($ci['palet_sayisi'] ?? 0) ?></td>
        <td class="num"><?= (int)($ci['toplam_kasa']  ?? 0) ?: '—' ?></td>
        <td class="num"><?= fmt_kg((float)($ci['toplam_brut'] ?? 0)) ?></td>
        <td class="num"><strong><?= fmt_kg((float)($ci['toplam_net'] ?? 0)) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="4"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $c_palet_total ?></strong></td>
        <td class="num"><strong><?= $c_kasa_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($c_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($c_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<?php else: /* DETAIL MODE — Landscape A4 */ ?>
<!-- ══════════════════════════════════════════════════════
     DETAYLI ŞABLON — Landscape A4
══════════════════════════════════════════════════════ -->

<!-- 1. Kantar Fişleri — tam tablo -->
<div class="pd-section">
<div class="pd-section-title">1. Kantar Fişleri (<?= count($kantar_items) ?>)</div>
<?php if (empty($kantar_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <?php if ($k_palet_total > 0): ?>
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $k_palet_total ?></strong></div>
    <?php endif; ?>
    <?php if ($k_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($k_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-fis"><span>FİŞ</span><strong><?= count($kantar_items) ?></strong></div>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($k_brut_total) ?></strong></div>
    <?php if ($k_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($k_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($k_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Fiş No</th><th>Tarih</th><th>Firma</th><th>Mal Cinsi</th><th>Plaka</th><th>Depo</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
        <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($kantar_items as $ki): ?>
    <tr>
        <td><?= h($ki['fis_no'] ?? '—') ?></td>
        <td><?= h(isset($ki['giris_tarih']) ? fmt_date($ki['giris_tarih']) : '—') ?></td>
        <td><?= h($ki['firma_adi'] ?? '—') ?></td>
        <td><?= h($ki['malin_cinsi'] ?? '—') ?></td>
        <td><?= h($ki['plaka'] ?? '—') ?></td>
        <td><?= h($ki['depo'] ?? '—') ?></td>
        <td class="num"><?= (int)($ki['palet_sayisi'] ?? 0) ?: '—' ?></td>
        <td class="num"><?= (int)($ki['kasa_sayisi']  ?? 0) ?: '—' ?></td>
        <td class="num"><?= fmt_kg((float)($ki['brut_kg'] ?? 0)) ?></td>
        <td class="num"><?= fmt_kg((float)($ki['dara_kg'] ?? 0)) ?></td>
        <td class="num"><strong><?= fmt_kg((float)($ki['net_kg'] ?? 0)) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="6"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $k_palet_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= $k_kasa_total  ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($k_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($k_dara_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($k_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<!-- 2. Makineye Dökülen — palet detaylı gruplu tablo -->
<div class="pd-section">
<div class="pd-section-title">2. Makineye Dökülen (<?= count($palet_items) ?> yükleme, <?= $m_palet_total ?> palet)</div>
<?php if (empty($palet_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $m_palet_total ?></strong></div>
    <?php if ($m_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($m_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
    <?php if ($m_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($m_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Kayıt / Palet No</th><th>Firma</th><th>Alıcı</th><th>Ürün</th><th>Depo</th>
        <th class="num">Kasa</th><th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($palet_items as $rec): ?>
    <tr class="pd-group-row print-avoid-break">
        <td colspan="5">
            <strong><?= h($rec['firma'] ?? '—') ?></strong><?php
            if (!empty($rec['alici'])): ?> — <?= h($rec['alici']) ?><?php endif;
            if (!empty($rec['urun'])): ?> · <?= h($rec['urun']) ?><?php endif;
            if (!empty($rec['depo'])): ?> · Depo: <?= h($rec['depo']) ?><?php endif;
            if (!empty($rec['tarih'])): ?> · <?= h(fmt_date($rec['tarih'])) ?><?php endif;
            ?>
        </td>
        <td class="num"><?= $rec['toplam_kasa'] ?: '—' ?></td>
        <td class="num"><?= fmt_kg($rec['toplam_brut']) ?></td>
        <td class="num"><?= fmt_kg($rec['toplam_dara']) ?></td>
        <td class="num"><strong><?= fmt_kg($rec['toplam_net']) ?></strong></td>
    </tr>
    <?php foreach ($rec['paletler'] as $pp): ?>
    <tr class="pd-palet-row">
        <td><?= h($pp['palet_no'] ?? '—') ?></td>
        <td colspan="3"></td>
        <td><?= h($pp['depo'] ?? '') ?: '—' ?></td>
        <td class="num"><?= (int)($pp['kasa_adeti'] ?? 0) ?: '—' ?></td>
        <td class="num"><?= fmt_kg((float)($pp['brut_kg'] ?? 0)) ?></td>
        <td class="num"><?= fmt_kg((float)($pp['dara_kg'] ?? 0)) ?></td>
        <td class="num"><?= fmt_kg((float)($pp['net_kg'] ?? 0)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="5"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $m_kasa_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_dara_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($m_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<!-- Hazır Palet (Soğuk Hava Deposu) — detay modu -->
<div class="pd-section">
<div class="pd-hazir-title">Soğuk Havada Hazır Palet</div>
<div class="pd-hazir-subtitle">(Bugün makineye dökülen hariç, soğuk havada hazır palet adeti)</div>
<?php if ($hazir_palet['palet'] === 0): ?>
    <p class="pd-empty" style="color:#dc2626;">Hazır palet yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <div class="pd-ps-item pd-ps-hazir"><span>PALET</span><strong><?= $hazir_palet['palet'] ?></strong></div>
    <?php if ($hazir_palet['kasa'] > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($hazir_palet['kasa'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($hazir_palet['brut']) ?></strong></div>
    <?php if ($hazir_palet['dara'] > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($hazir_palet['dara']) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($hazir_palet['net']) ?></strong></div>
</div>
<?php endif; ?>
</div>

<!-- 3. Çıkmalar — tam tablo -->
<div class="pd-section">
<div class="pd-section-title">3. Çıkmalar (<?= count($cikma_items) ?>)</div>
<?php if (empty($cikma_items)): ?>
    <p class="pd-empty">İşlem yok</p>
<?php else: ?>
<div class="pd-summary-row">
    <?php if ($c_palet_total > 0): ?>
    <div class="pd-ps-item pd-ps-palet"><span>PALET</span><strong><?= $c_palet_total ?></strong></div>
    <?php endif; ?>
    <?php if ($c_kasa_total > 0): ?>
    <div class="pd-ps-item pd-ps-kasa"><span>KASA</span><strong><?= number_format($c_kasa_total, 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($c_brut_total) ?></strong></div>
    <?php if ($c_dara_total > 0): ?>
    <div class="pd-ps-item pd-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($c_dara_total) ?></strong></div>
    <?php endif; ?>
    <div class="pd-ps-item pd-ps-net"><span>NET KG</span><strong><?= fmt_kg($c_net_total) ?></strong></div>
</div>
<table class="pd-table">
    <thead><tr>
        <th>Tarih</th><th>Firma</th><th>Ürün</th><th>Çıkış Nedeni</th><th>Depo</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
        <th class="num">Brüt KG</th><th class="num">Dara KG</th><th class="num">Net KG</th>
    </tr></thead>
    <tbody>
    <?php foreach ($cikma_items as $ci): ?>
    <tr>
        <td><?= h(isset($ci['tarih']) ? fmt_date($ci['tarih']) : '—') ?></td>
        <td><?= h($ci['firma'] ?? '—') ?></td>
        <td><?= h($ci['urun']  ?? '—') ?></td>
        <td><?= h($ci['cikis_nedeni'] ?? '—') ?></td>
        <td><?= h($ci['depo']  ?? '—') ?></td>
        <td class="num"><?= (int)($ci['palet_sayisi'] ?? 0) ?></td>
        <td class="num"><?= (int)($ci['toplam_kasa']  ?? 0) ?: '—' ?></td>
        <td class="num"><?= fmt_kg((float)($ci['toplam_brut'] ?? 0)) ?></td>
        <td class="num"><?= fmt_kg((float)($ci['toplam_dara'] ?? 0)) ?></td>
        <td class="num"><strong><?= fmt_kg((float)($ci['toplam_net'] ?? 0)) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="5"><strong>Toplam</strong></td>
        <td class="num"><strong><?= $c_palet_total ?></strong></td>
        <td class="num"><strong><?= $c_kasa_total ?: '—' ?></strong></td>
        <td class="num"><strong><?= fmt_kg($c_brut_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($c_dara_total) ?></strong></td>
        <td class="num"><strong><?= fmt_kg($c_net_total) ?></strong></td>
    </tr></tfoot>
</table>
<?php endif; ?>
</div>

<?php endif; /* end mode */ ?>

</div><!-- /print-sheet -->

<?php render_print_page_end(true); ?>
