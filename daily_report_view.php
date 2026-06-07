<?php
// =========================================================
// daily_report_view.php — X/Z Rapor Görüntüleme (snapshot)
// XZ-02B: Mobil kart görünümü eklendi (pc-only / mobile-only)
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
                'record_id'    => $rec_id,
                'tarih'        => $snap['tarih']  ?? '',
                'firma'        => $snap['firma']  ?? '',
                'bolge'        => $snap['bolge']  ?? '',
                'alici'        => $snap['alici']  ?? '',
                'urun'         => $snap['urun']   ?? '',
                'depo'         => $snap['depo']   ?? '',
                'durum'        => $snap['durum']  ?? '',
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

// Türkçe uzun tarih: "07 Haziran 2026 Pazar"
function fmt_date_tr_long(string $date): string {
    $ts = strtotime($date);
    if (!$ts) return '';
    $months = ['','Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    $days   = ['Pazar','Pazartesi','Salı','Çarşamba','Perşembe','Cuma','Cumartesi'];
    return sprintf('%02d %s %s %s', (int)date('j',$ts), $months[(int)date('n',$ts)], date('Y',$ts), $days[(int)date('w',$ts)]);
}
$date_disp_long = '';
if (!empty($report['report_date'])) {
    $date_disp_long = fmt_date_tr_long($report['report_date']);
} elseif (!empty($report['date_from'])) {
    $date_disp_long = fmt_date_tr_long($report['date_from']);
}

$page_title = h($report['title'] ?: ('Rapor #' . $id));

render_header($page_title, $print_mode);
render_flash();
?>

<style>
/* ── Rapor görüntüleme — ortak ── */
.drv-meta { display:flex; flex-wrap:wrap; gap:10px 22px; margin:8px 0 14px; font-size:.85rem; color:var(--text-muted,#666); }
.drv-meta strong { color:var(--text,#222); }
.drv-section { margin-bottom:28px; }
.drv-section-title { font-size:1rem; font-weight:600; border-bottom:2px solid var(--border,#e5e7eb); padding-bottom:6px; margin-bottom:10px; }
.drv-totals-bar { display:flex; flex-wrap:wrap; gap:8px 20px; background:var(--bg-alt,#f8f9fa); border:1px solid var(--border,#e5e7eb); border-radius:8px; padding:10px 14px; margin-bottom:10px; font-size:.88rem; }
.drv-totals-bar .t-item { display:flex; flex-direction:column; }
.drv-totals-bar .t-item span { color:var(--text-muted,#666); font-size:.78rem; }
.drv-totals-bar .t-item strong { font-size:1rem; font-variant-numeric:tabular-nums; }
.drv-empty { color:var(--text-muted,#666); font-style:italic; padding:6px 0; }
.drv-filter-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
.drv-chip { background:var(--bg-alt,#f8f9fa); border:1px solid var(--border,#e5e7eb); border-radius:20px; padding:2px 10px; font-size:.78rem; color:var(--text,#444); }

/* ── Accordion record grubu (desktop) ── */
.drv-record-group { border:1px solid var(--border,#e5e7eb); border-radius:8px; margin-bottom:10px; overflow:hidden; }
.drv-record-head { background:var(--bg-alt,#f8f9fa); padding:8px 14px; display:flex; flex-wrap:wrap; gap:4px 16px; font-size:.85rem; cursor:pointer; user-select:none; }
.drv-record-head strong { font-size:.92rem; }
.drv-record-pallets { overflow:hidden; }

/* ── Mobil kart ── */
.drv-mob-card {
    background:var(--card,#fff); border:1px solid var(--border,#e5e7eb);
    border-radius:10px; margin-bottom:10px; overflow:hidden;
}
.drv-mob-card-head {
    background:var(--bg-alt,#f8f9fa); border-bottom:1px solid var(--border,#e5e7eb);
    padding:9px 12px; display:flex; align-items:baseline; justify-content:space-between;
    gap:8px; flex-wrap:wrap;
}
.drv-mob-card-head strong { font-size:.92rem; }
.drv-mob-card-head .muted { font-size:.78rem; }
.drv-mob-body { padding:8px 12px; }
.drv-mob-row {
    display:flex; justify-content:space-between; align-items:baseline;
    padding:4px 0; border-bottom:1px solid var(--border,#f1f3f5);
    font-size:.82rem; gap:8px;
}
.drv-mob-row:last-child { border-bottom:none; }
.drv-mob-row span { color:var(--text-muted,#666); flex-shrink:0; }
.drv-mob-row strong { text-align:right; word-break:break-word; }
.drv-mob-net { background:var(--bg-alt,#f8f9fa); margin:4px -12px -8px; padding:6px 12px; border-radius:0 0 10px 10px; }
.drv-mob-net span { font-size:.82rem; font-weight:600; }
.drv-mob-net strong { font-size:1rem; color:var(--primary,#2563eb); }
/* Makineye Dökülen — grup kart başlığı */
.drv-mob-group-head {
    background:var(--bg-alt,#f8f9fa); border-bottom:1px solid var(--border,#e5e7eb);
    padding:9px 12px;
}
.drv-mob-group-head .drv-mob-group-title { font-weight:600; font-size:.88rem; margin-bottom:2px; }
.drv-mob-group-head .muted { font-size:.78rem; }
/* Palet detay accordion */
.drv-mob-details summary {
    font-size:.78rem; color:var(--primary,#2563eb); cursor:pointer;
    padding:6px 12px; list-style:none;
}
.drv-mob-details summary::-webkit-details-marker { display:none; }
.drv-mob-palet-list { padding:0 12px 8px; }
.drv-mob-palet-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:4px 0; border-bottom:1px solid var(--border,#f1f3f5);
    font-size:.78rem; gap:6px;
}
.drv-mob-palet-row:last-child { border-bottom:none; }
.drv-mob-palet-no { font-weight:600; flex-shrink:0; }
.drv-mob-palet-meta { color:var(--text-muted,#666); font-size:.75rem; }
.drv-mob-palet-kg { font-variant-numeric:tabular-nums; font-size:.78rem; text-align:right; }

/* Toplam satırı (mobil) */
.drv-mob-total-card {
    background:var(--bg-alt,#f8f9fa); border:1px solid var(--border,#e5e7eb);
    border-radius:8px; padding:10px 12px; margin-top:4px;
    display:flex; flex-wrap:wrap; gap:8px 18px; font-size:.82rem;
}
.drv-mob-total-card .t-item { display:flex; flex-direction:column; }
.drv-mob-total-card .t-item span { font-size:.72rem; color:var(--text-muted,#666); }
.drv-mob-total-card .t-item strong { font-size:.9rem; font-variant-numeric:tabular-nums; }

/* Print-only düz tablo (ekranda gizli) */
.drv-yukleme-print { display:none; }

/* Çıkma nedeni badge */
.drv-cikma-neden {
    display:inline-block; padding:1px 8px; border-radius:12px;
    font-size:.72rem; font-weight:600;
    background:#fef9c3; color:#854d0e;
}

/* Bölüm özet kutuları — ekranda gizli, baskıda görünür */
.drv-print-summary { display: none; }
/* Başlık yanı tarih */
.drv-title-date { display: inline-block; font-size: .85rem; font-weight: 400; color: #666; margin-left: 10px; vertical-align: middle; }

@media print {
    @page { margin: 10mm 8mm; size: A4 landscape; }
    .topbar, .bottomnav, .page-head .btn, .drv-back-btn, .sidebar,
    .drv-mob-details summary, .mobile-only { display:none !important; }
    .pc-only { display:block !important; }
    .drv-prt-hide { display:none !important; }
    body { background:#fff !important; }
    .container { max-width:none !important; padding:0 !important; }
    .drv-record-pallets { display:block !important; max-height:none !important; }
    .drv-record-group { break-inside:avoid; }
    .drv-section { break-inside:avoid; margin-bottom:10px; }
    .drv-yukleme-screen { display:none !important; }
    .drv-yukleme-print  { display:block !important; }
    .drv-section-title { font-size:10.5pt; font-weight:700; border-bottom:1.5px solid #333 !important; padding-bottom:3px; margin-bottom:6px; }
    .drv-meta { font-size:8pt; gap:4px 14px; margin:3px 0 7px; }
    .drv-totals-bar { border:1px solid #ccc !important; font-size:8pt; padding:5px 10px; gap:5px 14px; margin-bottom:6px; }
    .drv-totals-bar .t-item strong { font-size:9.5pt; }
    .drv-filter-chips { margin-bottom:5px; }
    .drv-chip { font-size:7pt; padding:1px 6px; }
    .drv-record-head { font-size:8.5pt; padding:4px 10px; }
    .data-table { font-size:8.5pt; width:100%; border-collapse:collapse; }
    .data-table th { font-size:8.5pt; font-weight:700; padding:2px 5px; }
    .data-table td { padding:2px 5px; line-height:1.2; }
    .data-table tfoot td { font-size:8.5pt; font-weight:700; padding:2px 5px; }
    .table-wrap { overflow:visible !important; }
    /* Yatay baskıda tfoot (sarı TOPLAM satırları) gizle */
    .data-table tfoot { display: none !important; }
    /* Üst özet bar baskıda gizli (bölüm kutularıyla değiştirildi) */
    .drv-totals-bar { display: none !important; }
    /* Bölüm özet kutuları — sadece baskıda görünür */
    .drv-print-summary { display: flex !important; gap: 10px; margin: 7px 0 9px; flex-wrap: nowrap; break-inside: avoid; }
    .drv-ps-item {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        min-width: 90px; padding: 12px 16px; border-radius: 6px; border: 2.5px solid;
        font-variant-numeric: tabular-nums; break-inside: avoid;
    }
    .drv-ps-item span { font-size: 8.5pt; font-weight: 700; margin-bottom: 5px; letter-spacing: .03em; }
    .drv-ps-item strong { font-size: 18pt; font-weight: 900; line-height: 1; }
    .drv-ps-palet { border-color: #7c3aed; color: #7c3aed; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .drv-ps-kasa  { border-color: #6b7280; color: #6b7280; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .drv-ps-brut  { border-color: #2563eb; color: #2563eb; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .drv-ps-dara  { border-color: #d97706; color: #d97706; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .drv-ps-net   { border-color: #059669; color: #059669; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    /* Başlıktaki tarih — sadece baskıda büyük görünür */
    .drv-title-date { font-size: 11pt; font-weight: 600; margin-left: 12px; color: #444; }
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
            <?php if ($date_disp_long !== ''): ?>
            <span class="drv-title-date"><?= h($date_disp_long) ?></span>
            <?php endif; ?>
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
    <div><span>Tip: </span><strong><?= $report['report_type'] === 'Z' ? '🔒 Z (Kapalı)' : 'X (Snapshot)' ?></strong></div>
    <div><span>Oluşturan: </span><strong><?= h($report['user_name'] ?: ('Kullanıcı #' . ($report['created_by'] ?? '?'))) ?></strong></div>
    <div><span>Oluşturma: </span><strong><?= h(fmt_datetime($report['created_at'])) ?></strong></div>
    <?php if (!empty($report['closed_at'])): ?>
    <div><span>Kapanış: </span><strong style="color:#059669"><?= h(fmt_datetime($report['closed_at'])) ?></strong></div>
    <?php endif; ?>
    <div class="drv-prt-hide"><span>Durum: </span><strong><?= h($report['status'] ?? 'final') ?></strong></div>
</div>

<!-- Filtre chip'leri -->
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

<?php
// ── Kantar toplamları önceden hesapla (mobil/PC paylaşılır) ──
$k_brut_total = 0.0; $k_dara_total = 0.0; $k_net_total = 0.0; $k_kasa_total = 0; $k_palet_total = 0;
foreach ($kantar_items as $_ki) {
    $k_brut_total  += (float)($_ki['brut_kg']    ?? 0);
    $k_dara_total  += (float)($_ki['dara_kg']    ?? 0);
    $k_net_total   += (float)($_ki['net_kg']     ?? 0);
    $k_kasa_total  += (int)($_ki['kasa_sayisi']  ?? 0);
    $k_palet_total += (int)($_ki['palet_sayisi'] ?? 0);
}
// ── Yükleme toplamları ──
$m_brut_total  = 0.0; $m_dara_total = 0.0; $m_net_total = 0.0; $m_kasa_total = 0;
$m_palet_total = (int)array_sum(array_column($palet_items, 'palet_sayisi'));
foreach ($palet_items as $_rec) {
    $m_brut_total += $_rec['toplam_brut'];
    $m_dara_total += $_rec['toplam_dara'];
    $m_net_total  += $_rec['toplam_net'];
    $m_kasa_total += $_rec['toplam_kasa'];
}
// ── Çıkma toplamları ──
$c_brut_total = 0.0; $c_dara_total = 0.0; $c_net_total = 0.0;
$c_kasa_total = 0; $c_palet_total = 0;
foreach ($cikma_items as $_ci) {
    $c_brut_total  += (float)($_ci['toplam_brut'] ?? 0);
    $c_dara_total  += (float)($_ci['toplam_dara'] ?? 0);
    $c_net_total   += (float)($_ci['toplam_net']  ?? 0);
    $c_kasa_total  += (int)($_ci['toplam_kasa']   ?? 0);
    $c_palet_total += (int)($_ci['palet_sayisi']  ?? 0);
}
?>

<!-- ══════════════════════════════════════════════════════
     1. KANTAR FİŞLERİ
══════════════════════════════════════════════════════ -->
<div class="drv-section">
    <div class="drv-section-title">Kantar Fişleri (<?= count($kantar_items) ?>)</div>
    <?php if (!empty($kantar_items)): ?>
    <div class="drv-print-summary">
        <?php if ($k_palet_total > 0): ?><div class="drv-ps-item drv-ps-palet"><span>PALET</span><strong><?= $k_palet_total ?></strong></div><?php endif; ?>
        <?php if ($k_kasa_total > 0): ?><div class="drv-ps-item drv-ps-kasa"><span>KASA</span><strong><?= number_format($k_kasa_total, 0, ',', '.') ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($k_brut_total) ?></strong></div>
        <?php if ($k_dara_total > 0): ?><div class="drv-ps-item drv-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($k_dara_total) ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-net"><span>NET KG</span><strong><?= fmt_kg($k_net_total) ?></strong></div>
    </div>
    <?php endif; ?>
    <?php if (empty($kantar_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
    <table class="data-table">
        <thead><tr>
            <th>Fiş No</th>
            <th class="drv-prt-hide">Tarih</th>
            <th>Firma</th>
            <th>Mal Cinsi</th>
            <th>Plaka</th>
            <th>Depo</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Dara KG</th>
            <th class="num">Net KG</th>
        </tr></thead>
        <tbody>
        <?php foreach ($kantar_items as $ki): ?>
        <tr>
            <td><?= h($ki['fis_no'] ?? '—') ?></td>
            <td class="drv-prt-hide"><?= h(isset($ki['giris_tarih']) ? fmt_date($ki['giris_tarih']) : '—') ?></td>
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
            <td class="num"><strong><?= fmt_kg($k_net_total)  ?></strong></td>
        </tr></tfoot>
    </table>
    </div>

    <!-- Mobil: kart listesi -->
    <div class="mobile-only">
    <?php foreach ($kantar_items as $ki): ?>
    <div class="drv-mob-card">
        <div class="drv-mob-card-head">
            <strong>Fiş No: <?= h($ki['fis_no'] ?? '—') ?></strong>
            <span class="muted"><?= h(isset($ki['giris_tarih']) ? fmt_date($ki['giris_tarih']) : '—') ?></span>
        </div>
        <div class="drv-mob-body">
            <?php if (!empty($ki['firma_adi'])): ?>
            <div class="drv-mob-row"><span>Firma</span><strong><?= h($ki['firma_adi']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ki['malin_cinsi'])): ?>
            <div class="drv-mob-row"><span>Mal Cinsi</span><strong><?= h($ki['malin_cinsi']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ki['plaka'])): ?>
            <div class="drv-mob-row"><span>Plaka</span><strong><?= h($ki['plaka']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ki['depo'])): ?>
            <div class="drv-mob-row"><span>Depo</span><strong><?= h($ki['depo']) ?></strong></div>
            <?php endif; ?>
            <?php if ((int)($ki['kasa_sayisi'] ?? 0) > 0): ?>
            <div class="drv-mob-row"><span>Kasa</span><strong><?= (int)$ki['kasa_sayisi'] ?></strong></div>
            <?php endif; ?>
            <div class="drv-mob-row"><span>Brüt KG</span><strong><?= fmt_kg((float)($ki['brut_kg'] ?? 0)) ?></strong></div>
            <div class="drv-mob-row"><span>Dara KG</span><strong><?= fmt_kg((float)($ki['dara_kg'] ?? 0)) ?></strong></div>
            <div class="drv-mob-row drv-mob-net"><span>Net KG</span><strong><?= fmt_kg((float)($ki['net_kg'] ?? 0)) ?></strong></div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="drv-mob-total-card">
        <?php if ($k_kasa_total > 0): ?>
        <div class="t-item"><span>Toplam Kasa</span><strong><?= $k_kasa_total ?></strong></div>
        <?php endif; ?>
        <div class="t-item"><span>Toplam Brüt</span><strong><?= fmt_kg($k_brut_total) ?></strong></div>
        <div class="t-item"><span>Toplam Dara</span><strong><?= fmt_kg($k_dara_total) ?></strong></div>
        <div class="t-item"><span>Toplam Net KG</span><strong><?= fmt_kg($k_net_total) ?></strong></div>
    </div>
    </div>

    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     2. MAKİNEYE DÖKÜLEN (Yükleme Paletleri — kayıt bazında gruplu)
══════════════════════════════════════════════════════ -->
<div class="drv-section">
    <div class="drv-section-title">Makineye Dökülen (<?= count($palet_items) ?> yükleme, <?= array_sum(array_column($palet_items, 'palet_sayisi')) ?> palet)</div>
    <?php if (!empty($palet_items)): ?>
    <div class="drv-print-summary">
        <div class="drv-ps-item drv-ps-palet"><span>PALET</span><strong><?= $m_palet_total ?></strong></div>
        <?php if ($m_kasa_total > 0): ?><div class="drv-ps-item drv-ps-kasa"><span>KASA</span><strong><?= number_format($m_kasa_total, 0, ',', '.') ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
        <?php if ($m_dara_total > 0): ?><div class="drv-ps-item drv-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($m_dara_total) ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-net"><span>NET KG</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
    </div>
    <?php endif; ?>
    <?php if (empty($palet_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>

    <!-- PC: accordion gruplar (ekran görünümü) -->
    <div class="pc-only">
    <div class="drv-yukleme-screen">
    <?php foreach ($palet_items as $rec): ?>
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
                <td class="num"><?= (int)($pp['kasa_adeti'] ?? 0) ?: '—' ?></td>
                <td class="num"><?= fmt_kg((float)($pp['brut_kg'] ?? 0)) ?></td>
                <td class="num"><?= fmt_kg((float)($pp['dara_kg'] ?? 0)) ?></td>
                <td class="num"><strong><?= fmt_kg((float)($pp['net_kg'] ?? 0)) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr>
                <td colspan="2"><strong>Alt Toplam</strong></td>
                <td class="num"><strong><?= $rec['toplam_kasa'] ?: '—' ?></strong></td>
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
        <div class="t-item"><span>Toplam Palet</span><strong><?= $m_palet_total ?></strong></div>
        <div class="t-item"><span>Toplam Kasa</span><strong><?= $m_kasa_total ?: '—' ?></strong></div>
        <div class="t-item"><span>Toplam Brüt</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
        <div class="t-item"><span>Toplam Dara</span><strong><?= fmt_kg($m_dara_total) ?></strong></div>
        <div class="t-item"><span>Toplam Net</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
    </div>
    </div><!-- /drv-yukleme-screen -->

    <!-- Print: kayıt bazında düz tablo (Tarih/Durum yok) -->
    <div class="table-wrap drv-yukleme-print">
    <table class="data-table">
        <thead><tr>
            <th>Firma</th>
            <th>Bölge</th>
            <th>Alıcı</th>
            <th>Depo</th>
            <th>Ürün</th>
            <th class="num">Palet</th>
            <th class="num">Kasa</th>
            <th class="num">Brüt KG</th>
            <th class="num">Dara KG</th>
            <th class="num">Net KG</th>
        </tr></thead>
        <tbody>
        <?php foreach ($palet_items as $rec): ?>
        <tr>
            <td><?= h($rec['firma'] ?? '—') ?></td>
            <td><?= h($rec['bolge'] ?? '') ?: '—' ?></td>
            <td><?= h($rec['alici'] ?? '') ?: '—' ?></td>
            <td><?= h($rec['depo']  ?? '') ?: '—' ?></td>
            <td><?= h($rec['urun']  ?? '—') ?></td>
            <td class="num"><?= $rec['palet_sayisi'] ?></td>
            <td class="num"><?= $rec['toplam_kasa'] ?: '—' ?></td>
            <td class="num"><?= fmt_kg($rec['toplam_brut']) ?></td>
            <td class="num"><?= fmt_kg($rec['toplam_dara']) ?></td>
            <td class="num"><strong><?= fmt_kg($rec['toplam_net']) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="5"><strong>Toplam</strong></td>
            <td class="num"><strong><?= $m_palet_total ?></strong></td>
            <td class="num"><strong><?= $m_kasa_total ?: '—' ?></strong></td>
            <td class="num"><strong><?= fmt_kg($m_brut_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($m_dara_total) ?></strong></td>
            <td class="num"><strong><?= fmt_kg($m_net_total) ?></strong></td>
        </tr></tfoot>
    </table>
    </div><!-- /drv-yukleme-print -->
    </div><!-- /pc-only -->

    <!-- Mobil: kart liste -->
    <div class="mobile-only">
    <?php foreach ($palet_items as $rec): ?>
    <div class="drv-mob-card">
        <div class="drv-mob-group-head">
            <div class="drv-mob-group-title">
                <?= h($rec['firma'] ?? '—') ?>
                <?php if (!empty($rec['urun'])): ?> · <?= h($rec['urun']) ?><?php endif; ?>
            </div>
            <div class="muted">
                <?= h($rec['tarih'] ? fmt_date($rec['tarih']) : '—') ?>
                <?php if (!empty($rec['bolge'])): ?> · <?= h($rec['bolge']) ?><?php endif; ?>
                <?php if (!empty($rec['alici'])): ?> · <?= h($rec['alici']) ?><?php endif; ?>
            </div>
        </div>
        <div class="drv-mob-body">
            <?php if (!empty($rec['depo'])): ?>
            <div class="drv-mob-row"><span>Depo</span><strong><?= h($rec['depo']) ?></strong></div>
            <?php endif; ?>
            <div class="drv-mob-row"><span>Palet</span><strong><?= $rec['palet_sayisi'] ?></strong></div>
            <?php if ($rec['toplam_kasa'] > 0): ?>
            <div class="drv-mob-row"><span>Kasa</span><strong><?= $rec['toplam_kasa'] ?></strong></div>
            <?php endif; ?>
            <div class="drv-mob-row"><span>Brüt KG</span><strong><?= fmt_kg($rec['toplam_brut']) ?></strong></div>
            <div class="drv-mob-row"><span>Dara KG</span><strong><?= fmt_kg($rec['toplam_dara']) ?></strong></div>
            <div class="drv-mob-row drv-mob-net"><span>Net KG</span><strong><?= fmt_kg($rec['toplam_net']) ?></strong></div>
        </div>
        <?php if (!empty($rec['paletler'])): ?>
        <details class="drv-mob-details">
            <summary>▼ <?= count($rec['paletler']) ?> palet detayı</summary>
            <div class="drv-mob-palet-list">
            <?php foreach ($rec['paletler'] as $pp): ?>
            <div class="drv-mob-palet-row">
                <span class="drv-mob-palet-no"><?= h($pp['palet_no'] ?? '—') ?></span>
                <?php if (!empty($pp['depo'])): ?>
                <span class="drv-mob-palet-meta"><?= h($pp['depo']) ?></span>
                <?php endif; ?>
                <span class="drv-mob-palet-kg">
                    <?php if ((int)($pp['kasa_adeti'] ?? 0) > 0): ?>
                    <span class="muted"><?= (int)$pp['kasa_adeti'] ?> kasa · </span>
                    <?php endif; ?>
                    <strong><?= fmt_kg((float)($pp['net_kg'] ?? 0)) ?></strong>
                </span>
            </div>
            <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="drv-mob-total-card">
        <?php if ($m_kasa_total > 0): ?>
        <div class="t-item"><span>Toplam Kasa</span><strong><?= $m_kasa_total ?></strong></div>
        <?php endif; ?>
        <div class="t-item"><span>Toplam Brüt</span><strong><?= fmt_kg($m_brut_total) ?></strong></div>
        <div class="t-item"><span>Toplam Dara</span><strong><?= fmt_kg($m_dara_total) ?></strong></div>
        <div class="t-item"><span>Toplam Net KG</span><strong><?= fmt_kg($m_net_total) ?></strong></div>
    </div>
    </div><!-- /mobile-only -->

    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     3. ÇIKMALAR
══════════════════════════════════════════════════════ -->
<div class="drv-section">
    <div class="drv-section-title">Çıkmalar (<?= count($cikma_items) ?>)</div>
    <?php if (!empty($cikma_items)): ?>
    <div class="drv-print-summary">
        <?php if ($c_palet_total > 0): ?><div class="drv-ps-item drv-ps-palet"><span>PALET</span><strong><?= $c_palet_total ?></strong></div><?php endif; ?>
        <?php if ($c_kasa_total > 0): ?><div class="drv-ps-item drv-ps-kasa"><span>KASA</span><strong><?= number_format($c_kasa_total, 0, ',', '.') ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-brut"><span>BRÜT KG</span><strong><?= fmt_kg($c_brut_total) ?></strong></div>
        <?php if ($c_dara_total > 0): ?><div class="drv-ps-item drv-ps-dara"><span>DARA KG</span><strong><?= fmt_kg($c_dara_total) ?></strong></div><?php endif; ?>
        <div class="drv-ps-item drv-ps-net"><span>NET KG</span><strong><?= fmt_kg($c_net_total) ?></strong></div>
    </div>
    <?php endif; ?>
    <?php if (empty($cikma_items)): ?>
        <p class="drv-empty">İşlem yok</p>
    <?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
    <table class="data-table">
        <thead><tr>
            <th class="drv-prt-hide">Tarih</th>
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
        <?php foreach ($cikma_items as $ci): ?>
        <tr>
            <td class="drv-prt-hide"><?= h(isset($ci['tarih']) ? fmt_date($ci['tarih']) : '—') ?></td>
            <td><?= h($ci['firma'] ?? '—') ?></td>
            <td><?= h($ci['urun']  ?? '—') ?></td>
            <td><?= h($ci['cikis_nedeni'] ?? '—') ?></td>
            <td><?= h($ci['depo'] ?? '—') ?></td>
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
    </div>

    <!-- Mobil: kart listesi -->
    <div class="mobile-only">
    <?php foreach ($cikma_items as $ci): ?>
    <div class="drv-mob-card">
        <div class="drv-mob-card-head">
            <strong><?= h($ci['firma'] ?? '—') ?></strong>
            <span class="muted"><?= h(isset($ci['tarih']) ? fmt_date($ci['tarih']) : '—') ?></span>
        </div>
        <div class="drv-mob-body">
            <?php if (!empty($ci['urun'])): ?>
            <div class="drv-mob-row"><span>Ürün</span><strong><?= h($ci['urun']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ci['depo'])): ?>
            <div class="drv-mob-row"><span>Depo</span><strong><?= h($ci['depo']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ci['cikis_nedeni'])): ?>
            <div class="drv-mob-row">
                <span>Çıkış Nedeni</span>
                <strong><span class="drv-cikma-neden"><?= h($ci['cikis_nedeni']) ?></span></strong>
            </div>
            <?php endif; ?>
            <div class="drv-mob-row"><span>Palet</span><strong><?= (int)($ci['palet_sayisi'] ?? 0) ?></strong></div>
            <?php if ((int)($ci['toplam_kasa'] ?? 0) > 0): ?>
            <div class="drv-mob-row"><span>Kasa</span><strong><?= (int)$ci['toplam_kasa'] ?></strong></div>
            <?php endif; ?>
            <div class="drv-mob-row"><span>Brüt KG</span><strong><?= fmt_kg((float)($ci['toplam_brut'] ?? 0)) ?></strong></div>
            <div class="drv-mob-row"><span>Dara KG</span><strong><?= fmt_kg((float)($ci['toplam_dara'] ?? 0)) ?></strong></div>
            <div class="drv-mob-row drv-mob-net"><span>Net KG</span><strong><?= fmt_kg((float)($ci['toplam_net'] ?? 0)) ?></strong></div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="drv-mob-total-card">
        <div class="t-item"><span>Toplam Palet</span><strong><?= $c_palet_total ?></strong></div>
        <?php if ($c_kasa_total > 0): ?>
        <div class="t-item"><span>Toplam Kasa</span><strong><?= $c_kasa_total ?></strong></div>
        <?php endif; ?>
        <div class="t-item"><span>Toplam Brüt</span><strong><?= fmt_kg($c_brut_total) ?></strong></div>
        <div class="t-item"><span>Toplam Dara</span><strong><?= fmt_kg($c_dara_total) ?></strong></div>
        <div class="t-item"><span>Toplam Net KG</span><strong><?= fmt_kg($c_net_total) ?></strong></div>
    </div>
    </div><!-- /mobile-only -->

    <?php endif; ?>
</div>

<?php if ($print_mode): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
<?php render_footer(); ?>
