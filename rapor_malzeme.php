<?php
// =========================================================
// rapor_malzeme.php — Malzeme Kullanım Pivot Raporu
// Yükleme planlarında kullanılan kasa/palet/ek malzemelerin
// firma, ürün sahibi ve tarihe göre dağılımı (pivot tablo).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (isset($_GET['csv'])) { require_perm('reports.export'); }
else { require_perm('reports.read'); }

$pdo = db();

// Yalnızca yükleme kayıtları malzeme "kullanımı" üretir
// (sync_malzeme_kullanim() çıkma kayıtlarını hareket üretmediği için hariç tutar — aynı mantık).
const RM_GROUP_LABELS = ['kasa' => 'Kasa', 'palet' => 'Palet', 'ek' => 'Ek Malzeme'];

function rm_norm(string $s): string {
    return mb_strtolower(strtr($s, [
        'İ' => 'i', 'I' => 'ı', 'Ş' => 'ş', 'Ğ' => 'ğ', 'Ü' => 'ü', 'Ö' => 'ö', 'Ç' => 'ç',
    ]), 'UTF-8');
}

function rm_filters(array $f, bool $us_col_ok): array {
    $where  = ["r.type = 'yukleme'"];
    $params = [];
    if ($f['tarih_bas'] !== '') { $where[] = 'r.tarih >= ?'; $params[] = $f['tarih_bas']; }
    if ($f['tarih_bit'] !== '') { $where[] = 'r.tarih <= ?'; $params[] = $f['tarih_bit']; }
    if ($f['firma']     !== '') { $where[] = 'r.firma = ?';  $params[] = $f['firma']; }
    if ($f['urun']      !== '') { $where[] = 'r.urun = ?';   $params[] = $f['urun']; }
    if ($f['depo']      !== '') { $where[] = 'p.depo = ?';   $params[] = $f['depo']; }
    if ($us_col_ok && $f['urun_sahibi'] !== '') {
        if ($f['urun_sahibi'] === '0') {
            $where[] = 'r.urun_sahibi_id IS NULL';
        } elseif (ctype_digit($f['urun_sahibi'])) {
            $where[] = 'r.urun_sahibi_id = ?';
            $params[] = (int)$f['urun_sahibi'];
        }
    }
    return [implode(' AND ', $where), $params];
}

function rm_accumulate(array &$pivot, array &$record_ids, array $rows, string $group_type): void {
    foreach ($rows as $row) {
        $record_ids[(int)$row['record_id']] = true;
        $qty = (float)$row['qty'];
        if ($qty <= 0) continue;
        $mid  = $row['material_id'] !== null ? (int)$row['material_id'] : null;
        $name = trim((string)($row['material_name'] ?? ''));
        if ($name === '') $name = $mid !== null ? "(Silinmiş Tanım #$mid)" : '(Tanımsız Malzeme)';
        $key = $mid !== null ? "id:$mid" : 'nm:' . $group_type . ':' . rm_norm($name);
        if (!isset($pivot[$key])) {
            $pivot[$key] = ['name' => $name, 'group_type' => $group_type, 'dates' => [], 'total' => 0.0];
        }
        $d = $row['tarih'];
        $pivot[$key]['dates'][$d] = ($pivot[$key]['dates'][$d] ?? 0.0) + $qty;
        $pivot[$key]['total'] += $qty;
    }
}

// ── Filtre değerleri ──────────────────────────────────────
$f_tarih_bas   = trim($_GET['tarih_bas']   ?? date('Y-m-d', strtotime('-29 days')));
$f_tarih_bit   = trim($_GET['tarih_bit']   ?? date('Y-m-d'));
$f_firma       = trim($_GET['firma']       ?? '');
$f_urun_sahibi = trim($_GET['urun_sahibi'] ?? '');
$f_urun        = trim($_GET['urun']        ?? '');
$f_depo        = trim($_GET['depo']        ?? '');
$f_mtype       = trim($_GET['material_type'] ?? '');
if (!in_array($f_mtype, ['kasa', 'palet', 'ek'], true)) $f_mtype = '';
$is_csv = isset($_GET['csv']);

if ($f_tarih_bas !== '' && $f_tarih_bit !== '' && $f_tarih_bas > $f_tarih_bit) {
    [$f_tarih_bas, $f_tarih_bit] = [$f_tarih_bit, $f_tarih_bas];
}
$gun_sayisi      = ($f_tarih_bas !== '' && $f_tarih_bit !== '')
    ? (int)floor((strtotime($f_tarih_bit) - strtotime($f_tarih_bas)) / 86400) + 1
    : 0;
$uzun_aralik_uyarisi = $gun_sayisi > 90;

$us_col_ok = db_has_column('loading_records', 'urun_sahibi_id');

// ── Filtre seçenek listeleri ──────────────────────────────
$filter_firma_list = $pdo->query(
    "SELECT DISTINCT firma FROM loading_records WHERE type='yukleme' AND firma != '' ORDER BY firma LIMIT 300"
)->fetchAll(PDO::FETCH_COLUMN);
$filter_urun_list = $pdo->query(
    "SELECT DISTINCT urun FROM loading_records WHERE type='yukleme' AND urun != '' ORDER BY urun LIMIT 200"
)->fetchAll(PDO::FETCH_COLUMN);
$filter_depo_list = $pdo->query(
    "SELECT DISTINCT depo FROM loading_pallets WHERE depo != '' ORDER BY depo LIMIT 200"
)->fetchAll(PDO::FETCH_COLUMN);
$filter_urun_sahibi_list = $us_col_ok
    ? $pdo->query("SELECT id, name FROM material_definitions WHERE type='firma' AND is_active=1 ORDER BY name LIMIT 200")->fetchAll()
    : [];

// ── Veri çekme: A) Kasa  B) Palet  C) Ek malzeme ──────────
$f = [
    'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit, 'firma' => $f_firma,
    'urun' => $f_urun, 'depo' => $f_depo, 'urun_sahibi' => $f_urun_sahibi,
];
[$where_sql, $params] = rm_filters($f, $us_col_ok);

$pivot      = [];
$record_ids = [];

if ($f_mtype === '' || $f_mtype === 'kasa') {
    $st = $pdo->prepare("
        SELECT r.id AS record_id, r.tarih, p.kasa_cinsi_id AS material_id,
               COALESCE(kc.name, '') AS material_name, p.kasa_adeti AS qty
        FROM loading_pallets p
        JOIN loading_records r ON r.id = p.loading_record_id
        LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
        WHERE $where_sql AND p.kasa_cinsi_id IS NOT NULL AND p.kasa_adeti > 0
    ");
    $st->execute($params);
    rm_accumulate($pivot, $record_ids, $st->fetchAll(), 'kasa');
}

if ($f_mtype === '' || $f_mtype === 'palet') {
    $st = $pdo->prepare("
        SELECT r.id AS record_id, r.tarih, p.palet_tipi_id AS material_id,
               COALESCE(pt.name, '') AS material_name, 1 AS qty
        FROM loading_pallets p
        JOIN loading_records r ON r.id = p.loading_record_id
        LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
        WHERE $where_sql AND p.palet_tipi_id IS NOT NULL
    ");
    $st->execute($params);
    rm_accumulate($pivot, $record_ids, $st->fetchAll(), 'palet');
}

if ($f_mtype === '' || $f_mtype === 'ek') {
    $st = $pdo->prepare("
        SELECT r.id AS record_id, r.tarih, pm.material_id,
               COALESCE(md.name, '') AS mat_name, COALESCE(md.type, '') AS mat_type,
               pm.quantity, p.kasa_adeti
        FROM pallet_materials pm
        JOIN loading_pallets p ON p.id = pm.loading_pallet_id
        JOIN loading_records r ON r.id = p.loading_record_id
        LEFT JOIN material_definitions md ON md.id = pm.material_id
        WHERE $where_sql AND pm.quantity > 0
    ");
    $st->execute($params);
    $ek_rows = [];
    foreach ($st->fetchAll() as $row) {
        $basis = material_calc_basis((string)$row['mat_type'], (string)$row['mat_name']);
        $eff   = $basis === 'kasa'
            ? round((float)$row['quantity'] * (int)$row['kasa_adeti'], 3)
            : (float)$row['quantity'];
        if ($eff <= 0) continue;
        $ek_rows[] = [
            'record_id' => $row['record_id'], 'tarih' => $row['tarih'],
            'material_id' => $row['material_id'], 'material_name' => $row['mat_name'], 'qty' => $eff,
        ];
    }
    rm_accumulate($pivot, $record_ids, $ek_rows, 'ek');
}

$group_order = ['kasa' => 0, 'palet' => 1, 'ek' => 2];
uasort($pivot, function ($a, $b) use ($group_order) {
    $ga = $group_order[$a['group_type']] ?? 9;
    $gb = $group_order[$b['group_type']] ?? 9;
    if ($ga !== $gb) return $ga <=> $gb;
    return $b['total'] <=> $a['total'];
});

// ── Tarih kolonları ────────────────────────────────────────
$date_cols = [];
if ($f_tarih_bas !== '' && $f_tarih_bit !== '') {
    $cursor = strtotime($f_tarih_bas);
    $end_ts = strtotime($f_tarih_bit);
    while ($cursor !== false && $cursor <= $end_ts) {
        $date_cols[] = date('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
    }
} else {
    $set = [];
    foreach ($pivot as $row) foreach (array_keys($row['dates']) as $d) $set[$d] = true;
    $date_cols = array_keys($set);
    sort($date_cols);
}
// Verisi olmayan (hiçbir malzemede sıfırdan büyük kayıt içermeyen) tarihleri gizle
$date_cols = array_values(array_filter($date_cols, function(string $d) use ($pivot): bool {
    foreach ($pivot as $row) {
        if (isset($row['dates'][$d]) && (float)$row['dates'][$d] > 0) return true;
    }
    return false;
}));

// ── Tarih başına parti numaraları ──────────────────────────
$parti_by_date = [];
if (!empty($record_ids)) {
    $ids = array_map('intval', array_keys($record_ids));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT tarih, parti_no FROM loading_records WHERE id IN ($in) ORDER BY tarih ASC, id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $d  = $row['tarih'];
        $pn = trim((string)$row['parti_no']);
        if ($pn === '') continue;
        if (!isset($parti_by_date[$d])) $parti_by_date[$d] = [];
        if (!in_array($pn, $parti_by_date[$d], true)) $parti_by_date[$d][] = $pn;
    }
}

// ── Özet kartları ────────────────────────────────────────
$sum_cesidi = count($pivot);
$sum_kayit  = count($record_ids);
$sum_kasa = $sum_palet = $sum_ek = 0.0;
foreach ($pivot as $row) {
    if ($row['group_type'] === 'kasa') $sum_kasa += $row['total'];
    elseif ($row['group_type'] === 'palet') $sum_palet += $row['total'];
    else $sum_ek += $row['total'];
}

// ── Aktif filtre özeti ─────────────────────────────────────
$filter_parts = [];
if ($f_firma !== '') $filter_parts[] = 'Firma: ' . $f_firma;
if ($f_urun_sahibi !== '') {
    if ($f_urun_sahibi === '0') {
        $filter_parts[] = 'Ürün Sahibi: Asya Fresh (Bizim)';
    } else {
        foreach ($filter_urun_sahibi_list as $us) {
            if ((string)(int)$us['id'] === $f_urun_sahibi) { $filter_parts[] = 'Ürün Sahibi: ' . $us['name']; break; }
        }
    }
}
if ($f_urun  !== '') $filter_parts[] = 'Ürün: ' . $f_urun;
if ($f_depo  !== '') $filter_parts[] = 'Depo: ' . $f_depo;
if ($f_mtype !== '') $filter_parts[] = 'Tür: ' . (RM_GROUP_LABELS[$f_mtype] ?? $f_mtype);
$filter_parts[] = 'Tarih: ' . ($f_tarih_bas !== '' ? fmt_date($f_tarih_bas) : '…') . ' - ' . ($f_tarih_bit !== '' ? fmt_date($f_tarih_bit) : '…');
$filter_summary = implode(' · ', $filter_parts);

// ── CSV export ──────────────────────────────────────────────
if ($is_csv) {
    audit_log_event('export', 'reports', null, null, [
        'report'    => 'malzeme_kullanim',
        'row_count' => count($pivot),
        'filters'   => array_filter([
            'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit, 'firma' => $f_firma,
            'urun_sahibi' => $f_urun_sahibi, 'urun' => $f_urun, 'depo' => $f_depo, 'material_type' => $f_mtype,
        ], fn($v) => $v !== ''),
    ]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="malzeme_kullanim_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $header = ['Malzeme', 'Tür', 'Toplam'];
    foreach ($date_cols as $d) $header[] = date('d.m.Y', strtotime($d));
    fputcsv($out, $header, ';', '"', '\\');
    foreach ($pivot as $row) {
        $line = [$row['name'], RM_GROUP_LABELS[$row['group_type']] ?? $row['group_type'], (int)round($row['total'])];
        foreach ($date_cols as $d) $line[] = (int)round($row['dates'][$d] ?? 0);
        fputcsv($out, $line, ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── Render ──────────────────────────────────────────────────
render_header('Malzeme Kullanım Raporu');
render_flash();

$persist_params = array_filter([
    'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit, 'firma' => $f_firma,
    'urun_sahibi' => $f_urun_sahibi, 'urun' => $f_urun, 'depo' => $f_depo, 'material_type' => $f_mtype,
], fn($v) => $v !== '');
$csv_url = 'rapor_malzeme.php?' . http_build_query(array_merge($persist_params, ['csv' => '1']));
?>

<!-- Baskı başlığı — sadece yazdırmada -->
<div class="mr-print-header">
    <div class="mr-ph-row">
        <div>
            <div class="mr-ph-title">🧮 MALZEME KULLANIM RAPORU</div>
            <div class="mr-ph-sub"><?= h($filter_summary) ?></div>
        </div>
        <div class="mr-ph-right">
            <div><?= date('d.m.Y H:i') ?></div>
            <div><?= $sum_cesidi ?> malzeme çeşidi · <?= $sum_kayit ?> yükleme kaydı</div>
        </div>
    </div>
</div>

<div class="page-head rpt-head">
    <div class="rpt-title">
        <a href="reports.php" class="btn btn-ghost btn-sm rpt-no-print">← Raporlar</a>
        <h1>🧮 Malzeme Kullanım Raporu</h1>
        <p class="muted">Yükleme planlarında kullanılan kasa, palet ve ek malzemelerin firma, ürün sahibi ve tarihe göre dağılımını gösterir.</p>
    </div>
    <div class="rpt-actions rpt-no-print">
        <?php if (!empty($pivot)): ?>
        <button onclick="window.print()" class="btn btn-sm">🖨 Yazdır</button>
        <?php endif; ?>
        <a href="<?= h($csv_url) ?>" class="btn btn-sm btn-primary">⬇ Excel/CSV İndir</a>
    </div>
</div>

<p class="muted rpt-no-print" style="margin:-6px 0 14px"><?= h($filter_summary) ?></p>

<?php if ($uzun_aralik_uyarisi): ?>
<div class="kr-limit-uyari rpt-no-print">⚠ Uzun tarih aralıklarında tablo geniş olabilir (<?= $gun_sayisi ?> gün).</div>
<?php endif; ?>

<!-- Filtre -->
<div class="rpt-filter-card rpt-no-print">
<form method="get" class="rpt-filter-form">
    <div class="rpt-filter-group">
        <label>Başlangıç<input type="date" name="tarih_bas" value="<?= h($f_tarih_bas) ?>"></label>
        <label>Bitiş<input type="date" name="tarih_bit" value="<?= h($f_tarih_bit) ?>"></label>
    </div>
    <div class="rpt-filter-group">
        <label>Firma
            <select name="firma">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_firma_list as $_f): ?>
                <option value="<?= h($_f) ?>" <?= $f_firma === $_f ? 'selected' : '' ?>><?= h($_f) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($us_col_ok): ?>
        <label>Ürün Sahibi
            <select name="urun_sahibi">
                <option value="">-- Tümü --</option>
                <option value="0" <?= $f_urun_sahibi === '0' ? 'selected' : '' ?>>Asya Fresh (Bizim)</option>
                <?php foreach ($filter_urun_sahibi_list as $_us): ?>
                <option value="<?= (int)$_us['id'] ?>" <?= $f_urun_sahibi === (string)(int)$_us['id'] ? 'selected' : '' ?>><?= h($_us['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>
    <div class="rpt-filter-group">
        <label>Ürün
            <select name="urun">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_urun_list as $_u): ?>
                <option value="<?= h($_u) ?>" <?= $f_urun === $_u ? 'selected' : '' ?>><?= h($_u) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Depo
            <select name="depo">
                <option value="">-- Tümü --</option>
                <?php foreach ($filter_depo_list as $_d): ?>
                <option value="<?= h($_d) ?>" <?= $f_depo === $_d ? 'selected' : '' ?>><?= h($_d) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Malzeme Türü
            <select name="material_type">
                <option value="">-- Tümü --</option>
                <?php foreach (RM_GROUP_LABELS as $_k => $_l): ?>
                <option value="<?= h($_k) ?>" <?= $f_mtype === $_k ? 'selected' : '' ?>><?= h($_l) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="rpt-filter-actions">
        <button class="btn btn-primary btn-sm">Filtrele</button>
        <a href="rapor_malzeme.php" class="btn btn-ghost btn-sm">Temizle</a>
    </div>
</form>
</div>

<?php if (empty($pivot)): ?>
<div class="empty rpt-no-print"><p>Bu filtreye uygun malzeme kullanımı bulunamadı.</p></div>
<?php else: ?>

<!-- Özet kartları -->
<div class="rpt-summary rpt-no-print">
    <div class="rpt-sum-item"><span>Malzeme Çeşidi</span><strong><?= $sum_cesidi ?></strong></div>
    <div class="rpt-sum-item"><span>Yükleme Kaydı</span><strong><?= $sum_kayit ?></strong></div>
    <div class="rpt-sum-item"><span>Toplam Kasa</span><strong><?= fmt_kg($sum_kasa) ?></strong></div>
    <div class="rpt-sum-item"><span>Toplam Palet</span><strong><?= fmt_kg($sum_palet) ?></strong></div>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Toplam Ek Malzeme</span><strong><?= fmt_kg($sum_ek) ?></strong></div>
</div>

<div class="card mr-pivot-card">
    <div class="table-wrap">
        <table class="data-table mr-table">
            <thead>
                <tr>
                    <th>Malzeme</th>
                    <th>Tür</th>
                    <th class="num mr-total-col">Toplam</th>
                    <?php foreach ($date_cols as $d): ?>
                    <th class="num"><?= date('d.m', strtotime($d)) ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr class="mr-parti-row">
                    <th colspan="3" class="mr-parti-label">Parti No</th>
                    <?php foreach ($date_cols as $d): ?>
                    <th class="num mr-parti-cell"><?= h(implode(' / ', $parti_by_date[$d] ?? [])) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pivot as $row): ?>
                <tr>
                    <td><?= h($row['name']) ?></td>
                    <td><?= h(RM_GROUP_LABELS[$row['group_type']] ?? $row['group_type']) ?></td>
                    <td class="num mr-total-col"><strong><?= fmt_kg($row['total']) ?></strong></td>
                    <?php foreach ($date_cols as $d): ?>
                    <td class="num"><?= isset($row['dates'][$d]) ? fmt_kg($row['dates'][$d]) : '-' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<style>
/* ── Malzeme Kullanım Raporu ───────────────────────────── */
.mr-print-header { display: none; }
.mr-pivot-card .table-wrap { overflow-x: auto; }
.mr-table th, .mr-table td { white-space: nowrap; }
.mr-table th:first-child, .mr-table td:first-child { white-space: normal; min-width: 140px; }

/* Parti no satırı */
.mr-parti-row th { background: #f8fafc !important; font-weight: 600; }
.mr-parti-label { text-align: left; color: #64748b; font-size: .75rem; }
.mr-parti-cell { white-space: normal !important; font-size: .7rem; color: #475569; max-width: 110px; line-height: 1.3; }

/* Toplam kolonu vurgu */
.mr-table th.mr-total-col {
    background: #e0e7ff !important;
    color: #3730a3;
    border-left: 2px solid #6366f1;
}
.mr-table td.mr-total-col {
    background: #eef2ff !important;
    color: #3730a3;
    border-left: 2px solid #6366f1;
}

@media (max-width: 767px) {
    .mr-table { font-size: .8rem; }
}

@page { size: A4 landscape; margin: 8mm; }

@media print {
    .mr-print-header { display: block !important; margin-bottom: 5mm; }
    .mr-ph-row {
        display: flex; justify-content: space-between; align-items: flex-start;
        border-bottom: 2pt solid #000; padding-bottom: 3mm; margin-bottom: 3mm;
    }
    .mr-ph-title { font-size: 12pt; font-weight: 800; }
    .mr-ph-sub   { font-size: 8pt; color: #555; margin-top: 2px; }
    .mr-ph-right { text-align: right; font-size: 8pt; line-height: 1.6; }

    .mr-table { font-size: 6.5pt !important; }
    .mr-table th { font-size: 6pt !important; padding: 3px 4px !important; background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .mr-parti-cell { font-size: 5.5pt !important; white-space: normal !important; }
    .mr-table td { padding: 3px 4px !important; }
    .mr-table th.mr-total-col { background: #e0e7ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .mr-table td.mr-total-col { background: #eef2ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .mr-pivot-card { border: 1pt solid #ccc !important; box-shadow: none !important; }
}
</style>

<?php render_footer(); ?>
