<?php
// =========================================================
// reports.php - Raporlar modülü
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$type      = trim($_GET['type']      ?? '');
$export    = trim($_GET['export']    ?? '');
$f_from    = trim($_GET['date_from'] ?? '');
$f_to      = trim($_GET['date_to']   ?? '');
$f_firma   = trim($_GET['firma']     ?? '');
$f_durum   = trim($_GET['durum']     ?? '');
$f_urun    = trim($_GET['urun']      ?? '');
$f_bolge   = trim($_GET['bolge']     ?? '');
$f_depo    = trim($_GET['depo']      ?? '');
$f_plaka   = trim($_GET['plaka']     ?? '');
$f_mtype   = trim($_GET['mat_type']  ?? '');
$f_q       = trim($_GET['q']         ?? '');
$f_sort    = trim($_GET['sort']      ?? 'tarih');

$valid_types = ['yukleme','cikma','depo','urun','firma','malzeme','kantar'];
if ($type !== '' && !in_array($type, $valid_types, true)) { $type = ''; }

$report_meta = [
    'yukleme'  => ['label'=>'Yüklemeler', 'icon'=>'🚚', 'bg'=>'#eaf1ff'],
    'cikma'    => ['label'=>'Çıkmalar',   'icon'=>'📋', 'bg'=>'#fdecea'],
    'depo'     => ['label'=>'Depo',       'icon'=>'🏭', 'bg'=>'#f0faf4'],
    'urun'     => ['label'=>'Ürün',       'icon'=>'🌿', 'bg'=>'#fdf6dc'],
    'firma'    => ['label'=>'Firma',      'icon'=>'🏢', 'bg'=>'#f5f0ff'],
    'malzeme'  => ['label'=>'Malzeme',    'icon'=>'📦', 'bg'=>'#fff5f0'],
    'kantar'   => ['label'=>'Kantar',     'icon'=>'⚖️',  'bg'=>'#f0f9ff'],
];

function col_label(string $c): string {
    static $m = [
        'id'=>'ID','tarih'=>'Tarih','firma'=>'Firma','bolge'=>'Bölge','alici'=>'Alıcı',
        'urun'=>'Ürün','parti_no'=>'Parti No','on_plaka'=>'Ön Plaka','arka_plaka'=>'Arka Plaka',
        'durum'=>'Durum','palet_sayisi'=>'Palet','toplam_kasa'=>'Kasa',
        'toplam_brut'=>'Brüt KG','toplam_dara'=>'Dara KG','toplam_net'=>'Net KG',
        'nakliye_bedeli'=>'Nakliye Bedeli','avans'=>'Avans','created_at'=>'Oluşturulma',
        'depo'=>'Depo','kayit_sayisi'=>'Kayıt Sayısı','ilk_tarih'=>'İlk Tarih','son_tarih'=>'Son Tarih',
        'toplam_kayit'=>'Toplam Kayıt','yukleme_sayisi'=>'Yükleme','cikma_sayisi'=>'Çıkma',
        'material_type'=>'Tür','material_name'=>'Malzeme','unit_dara_kg'=>'Birim Dara (kg)',
        'toplam_adet'=>'Yükl. Adet','toplam_dara_kg'=>'Yükl. Dara KG',
        'kullanim_sayisi'=>'Kullanım','is_active'=>'Aktif',
        'stok_giris'=>'Stok Giriş','stok_sevk'=>'Stok Sevk','stok_mevcut'=>'Stok Mevcut',
        'fis_no'=>'Fiş No','giris_tarih'=>'Giriş','cikis_tarih'=>'Çıkış',
        'firma_adi'=>'Firma','plaka'=>'Plaka','malin_cinsi'=>'Malın Cinsi',
        'tartim1'=>'1.Tartım','tartim2'=>'2.Tartım','net_kg'=>'Net KG',
        'kasa_sayisi'=>'Kasa Sayısı','kasa_cinsi'=>'Kasa Cinsi',
        'palet_cinsi'=>'Palet Cinsi','operator_adi'=>'Operatör',
    ];
    return $m[$c] ?? $c;
}

// ── Veri çekme ──────────────────────────────────────────
$rows  = [];
$cols  = [];
$totals = [];

function rpt_date_filter(string &$sql, array &$p, string $col, string $from, string $to): void {
    if ($from !== '') { $sql .= " AND $col >= :df"; $p[':df'] = $from; }
    if ($to   !== '') { $sql .= " AND $col <= :dt"; $p[':dt'] = $to;   }
}

// Türkçe karakter duyarsız normalleştirme (KAYISI → kayısı gibi birleştirmek için)
function rpt_norm(string $s): string {
    return mb_strtolower(strtr($s, [
        'İ'=>'i', 'I'=>'ı', 'Ş'=>'ş', 'Ğ'=>'ğ', 'Ü'=>'ü', 'Ö'=>'ö', 'Ç'=>'ç',
    ]), 'UTF-8');
}

function rpt_merge_rows(array $rows, string $key_col, array $sum_int, array $sum_float): array {
    $merged  = [];
    $key_map = [];
    foreach ($rows as $row) {
        $key = rpt_norm((string)($row[$key_col] ?? ''));
        if (!isset($key_map[$key])) {
            $key_map[$key] = count($merged);
            $merged[] = $row;
        } else {
            $idx = $key_map[$key];
            foreach ($sum_int   as $c) $merged[$idx][$c] = (int)($merged[$idx][$c] ?? 0) + (int)($row[$c] ?? 0);
            foreach ($sum_float as $c) $merged[$idx][$c] = (float)($merged[$idx][$c] ?? 0) + (float)($row[$c] ?? 0);
        }
    }
    return $merged;
}

if ($type === 'yukleme' || $type === 'cikma') {
    $sql = "SELECT r.id, r.tarih, r.firma, r.bolge, r.alici, r.urun, r.parti_no,
                   r.on_plaka, r.arka_plaka, r.durum, r.nakliye_bedeli, r.avans,
                   (SELECT p2.depo FROM loading_pallets p2
                    WHERE p2.loading_record_id = r.id AND p2.depo != ''
                    ORDER BY p2.id LIMIT 1)          AS depo,
                   COUNT(p.id)                       AS palet_sayisi,
                   COALESCE(SUM(p.kasa_adeti),0)     AS toplam_kasa,
                   COALESCE(SUM(p.brut_kg),0)        AS toplam_brut,
                   COALESCE(SUM(p.dara_kg),0)        AS toplam_dara,
                   COALESCE(SUM(p.net_kg),0)         AS toplam_net,
                   r.created_at
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE r.type = :rtype";
    $p = [':rtype' => $type];
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma"; $p[':firma'] = '%'.$f_firma.'%'; }
    if ($f_durum !== '') { $sql .= " AND r.durum = :durum";    $p[':durum'] = $f_durum; }
    if ($f_urun  !== '') { $sql .= " AND r.urun  LIKE :urun";  $p[':urun']  = '%'.$f_urun.'%'; }
    if ($f_bolge !== '') { $sql .= " AND r.bolge LIKE :bolge"; $p[':bolge'] = '%'.$f_bolge.'%'; }
    if ($f_q     !== '') {
        $sql .= " AND (r.firma LIKE :q OR r.parti_no LIKE :q OR r.alici LIKE :q OR r.urun LIKE :q)";
        $p[':q'] = '%'.$f_q.'%';
    }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sort_map = [
        'tarih' => 'r.tarih DESC, r.id DESC',
        'firma' => 'r.firma ASC, r.tarih DESC',
        'durum' => 'r.durum ASC, r.tarih DESC',
        'palet' => 'palet_sayisi DESC, r.tarih DESC',
        'net'   => 'toplam_net DESC',
    ];
    $order_by = $sort_map[$f_sort] ?? 'r.tarih DESC, r.id DESC';
    $sql .= " GROUP BY r.id ORDER BY $order_by LIMIT 2000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    if ($type === 'yukleme') {
        $cols = ['tarih','firma','bolge','alici','depo','urun','parti_no','durum','palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net'];
    } else { // cikma — alıcı yok, depo var
        $cols = ['tarih','firma','bolge','depo','urun','parti_no','durum','palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net'];
    }
    foreach ($rows as $r) {
        $totals['palet_sayisi']   = ($totals['palet_sayisi']   ?? 0) + (int)$r['palet_sayisi'];
        $totals['toplam_kasa']    = ($totals['toplam_kasa']    ?? 0) + (int)$r['toplam_kasa'];
        $totals['toplam_brut']    = ($totals['toplam_brut']    ?? 0) + (float)$r['toplam_brut'];
        $totals['toplam_dara']    = ($totals['toplam_dara']    ?? 0) + (float)$r['toplam_dara'];
        $totals['toplam_net']     = ($totals['toplam_net']     ?? 0) + (float)$r['toplam_net'];
        $totals['nakliye_bedeli'] = ($totals['nakliye_bedeli'] ?? 0) + (float)$r['nakliye_bedeli'];
    }

} elseif ($type === 'depo') {
    $sql = "SELECT p.depo,
                   COUNT(DISTINCT r.id)          AS kayit_sayisi,
                   COUNT(p.id)                   AS palet_sayisi,
                   SUM(p.kasa_adeti)             AS toplam_kasa,
                   ROUND(SUM(p.brut_kg),3)       AS toplam_brut,
                   ROUND(SUM(p.dara_kg),3)       AS toplam_dara,
                   ROUND(SUM(p.net_kg),3)        AS toplam_net,
                   MIN(r.tarih)                  AS ilk_tarih,
                   MAX(r.tarih)                  AS son_tarih
            FROM loading_pallets p
            JOIN loading_records r ON r.id = p.loading_record_id
            WHERE p.depo != ''";
    $p = [];
    if ($f_depo  !== '') { $sql .= " AND p.depo LIKE :depo";   $p[':depo']  = '%'.$f_depo.'%'; }
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma";  $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY p.depo ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $rows = rpt_merge_rows($rows, 'depo',
        ['kayit_sayisi','palet_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_dara','toplam_net']);
    $cols = ['depo','kayit_sayisi','palet_sayisi','toplam_kasa','toplam_brut','toplam_dara','toplam_net','ilk_tarih','son_tarih'];
    foreach ($rows as $r) {
        $totals['toplam_kasa']  = ($totals['toplam_kasa']  ?? 0) + (float)$r['toplam_kasa'];
        $totals['palet_sayisi'] = ($totals['palet_sayisi'] ?? 0) + (int)$r['palet_sayisi'];
        $totals['toplam_net']   = ($totals['toplam_net']   ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'urun') {
    $sql = "SELECT COALESCE(NULLIF(r.urun,''),'—') AS urun,
                   COUNT(DISTINCT r.id)          AS kayit_sayisi,
                   COUNT(p.id)                   AS palet_sayisi,
                   COALESCE(SUM(p.kasa_adeti),0) AS toplam_kasa,
                   ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
                   ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE 1=1";
    $p = [];
    if ($f_urun  !== '') { $sql .= " AND r.urun LIKE :urun";    $p[':urun']  = '%'.$f_urun.'%'; }
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma";  $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY r.urun ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    // Türkçe karakter nedeniyle aynı ürün farklı satır olarak görünebilir — birleştir
    $rows = rpt_merge_rows($rows, 'urun',
        ['kayit_sayisi','palet_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_net']);
    $cols = ['urun','kayit_sayisi','palet_sayisi','toplam_kasa','toplam_brut','toplam_net'];
    foreach ($rows as $r) {
        $totals['toplam_kasa'] = ($totals['toplam_kasa'] ?? 0) + (float)$r['toplam_kasa'];
        $totals['toplam_net']  = ($totals['toplam_net']  ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'firma') {
    $sql = "SELECT COALESCE(NULLIF(r.firma,''),'—') AS firma,
                   COUNT(DISTINCT r.id)              AS toplam_kayit,
                   SUM(CASE WHEN r.type='yukleme' THEN 1 ELSE 0 END) AS yukleme_sayisi,
                   SUM(CASE WHEN r.type='cikma'   THEN 1 ELSE 0 END) AS cikma_sayisi,
                   COALESCE(SUM(p.kasa_adeti),0)     AS toplam_kasa,
                   ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
                   ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net,
                   MIN(r.tarih)                      AS ilk_tarih,
                   MAX(r.tarih)                      AS son_tarih
            FROM loading_records r
            LEFT JOIN loading_pallets p ON p.loading_record_id = r.id
            WHERE 1=1";
    $p = [];
    if ($f_firma !== '') { $sql .= " AND r.firma LIKE :firma"; $p[':firma'] = '%'.$f_firma.'%'; }
    rpt_date_filter($sql, $p, 'r.tarih', $f_from, $f_to);
    $sql .= " GROUP BY r.firma ORDER BY toplam_kasa DESC LIMIT 500";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    // Aynı firma farklı büyük/küçük harf ile kaydedilmiş olabilir — birleştir
    $rows = rpt_merge_rows($rows, 'firma',
        ['toplam_kayit','yukleme_sayisi','cikma_sayisi','toplam_kasa'],
        ['toplam_brut','toplam_net']);
    $cols = ['firma','toplam_kayit','yukleme_sayisi','cikma_sayisi','toplam_kasa','toplam_brut','toplam_net','ilk_tarih','son_tarih'];
    foreach ($rows as $r) {
        $totals['toplam_kasa'] = ($totals['toplam_kasa'] ?? 0) + (float)$r['toplam_kasa'];
        $totals['toplam_net']  = ($totals['toplam_net']  ?? 0) + (float)$r['toplam_net'];
    }

} elseif ($type === 'malzeme') {
    $sql = "SELECT md.type AS material_type, md.name AS material_name,
                   md.unit_dara_kg, md.is_active,
                   COALESCE(pm_s.kullanim_sayisi, 0)             AS kullanim_sayisi,
                   COALESCE(pm_s.toplam_adet, 0)                 AS toplam_adet,
                   COALESCE(ROUND(pm_s.toplam_dara_kg, 3), 0)   AS toplam_dara_kg,
                   COALESCE(ms_g.stok_giris, 0)                  AS stok_giris,
                   COALESCE(ms_s.stok_sevk,  0)                  AS stok_sevk,
                   COALESCE(ms_g.stok_giris, 0) - COALESCE(ms_s.stok_sevk, 0) AS stok_mevcut
            FROM material_definitions md
            LEFT JOIN (
                SELECT material_id,
                       COUNT(id)          AS kullanim_sayisi,
                       SUM(quantity)      AS toplam_adet,
                       SUM(total_dara_kg) AS toplam_dara_kg
                FROM pallet_materials WHERE material_id IS NOT NULL GROUP BY material_id
            ) pm_s ON pm_s.material_id = md.id
            LEFT JOIN (
                SELECT material_id, SUM(quantity) AS stok_giris
                FROM material_stock_movements
                WHERE movement_type = 'giris' AND material_id IS NOT NULL
                GROUP BY material_id
            ) ms_g ON ms_g.material_id = md.id
            LEFT JOIN (
                SELECT material_id, SUM(quantity) AS stok_sevk
                FROM material_stock_movements
                WHERE movement_type = 'sevk' AND material_id IS NOT NULL
                GROUP BY material_id
            ) ms_s ON ms_s.material_id = md.id
            WHERE md.type NOT IN ('firma','depo','urun')";
    $p = [];
    if ($f_mtype !== '') { $sql .= " AND md.type = :mtype"; $p[':mtype'] = $f_mtype; }
    if ($f_q     !== '') { $sql .= " AND md.name LIKE :q";  $p[':q']     = '%'.$f_q.'%'; }
    $sql .= " ORDER BY md.type, md.name LIMIT 1000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $cols = ['material_type','material_name','unit_dara_kg','stok_giris','stok_sevk','stok_mevcut','toplam_adet','toplam_dara_kg','kullanim_sayisi','is_active'];
    foreach ($rows as $r) {
        $totals['toplam_dara_kg'] = ($totals['toplam_dara_kg'] ?? 0) + (float)$r['toplam_dara_kg'];
        $totals['stok_giris']     = ($totals['stok_giris']     ?? 0) + (float)$r['stok_giris'];
        $totals['stok_sevk']      = ($totals['stok_sevk']      ?? 0) + (float)$r['stok_sevk'];
        $totals['stok_mevcut']    = ($totals['stok_mevcut']    ?? 0) + (float)$r['stok_mevcut'];
    }

} elseif ($type === 'kantar') {
    $sql = "SELECT id, fis_no, giris_tarih, cikis_tarih,
                   firma_adi, plaka, malin_cinsi,
                   tartim1, tartim2, net_kg,
                   kasa_sayisi, palet_sayisi, kasa_cinsi, palet_cinsi, operator_adi, created_at
            FROM kantar_fisleri WHERE 1=1";
    $p = [];
    if ($f_firma !== '') { $sql .= " AND firma_adi LIKE :firma"; $p[':firma'] = '%'.$f_firma.'%'; }
    if ($f_plaka !== '') { $sql .= " AND plaka LIKE :plaka";     $p[':plaka'] = '%'.$f_plaka.'%'; }
    if ($f_q     !== '') {
        $sql .= " AND (firma_adi LIKE :q OR fis_no LIKE :q OR plaka LIKE :q OR malin_cinsi LIKE :q)";
        $p[':q'] = '%'.$f_q.'%';
    }
    if ($f_from !== '') { $sql .= " AND giris_tarih >= :df"; $p[':df'] = $f_from; }
    if ($f_to   !== '') { $sql .= " AND giris_tarih <= :dt"; $p[':dt'] = $f_to; }
    $sql .= " ORDER BY id DESC LIMIT 1000";
    $st = db()->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll();
    $cols = ['id','fis_no','giris_tarih','cikis_tarih','firma_adi','plaka','malin_cinsi','tartim1','tartim2','net_kg','kasa_sayisi','palet_sayisi','operator_adi'];
    foreach ($rows as $r) {
        $totals['net_kg'] = ($totals['net_kg'] ?? 0) + (float)$r['net_kg'];
    }
}

// ── CSV Export ──────────────────────────────────────────
if ($type !== '' && $export === 'csv' && !empty($rows)) {
    $filename = 'rapor_' . $type . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, array_map('col_label', $cols), ';');
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) {
            $v = $r[$c] ?? '';
            if (in_array($c, ['toplam_brut','toplam_dara','toplam_net','toplam_dara_kg','toplam_brut','net_kg','tartim1','tartim2','unit_dara_kg'], true)) {
                $v = str_replace('.', ',', (string)$v);
            }
            $line[] = $v;
        }
        fputcsv($fp, $line, ';');
    }
    if (!empty($totals)) {
        $total_line = array_fill(0, count($cols), '');
        $total_line[0] = 'TOPLAM';
        foreach ($cols as $i => $c) {
            if (isset($totals[$c])) $total_line[$i] = str_replace('.', ',', (string)round((float)$totals[$c], 3));
        }
        fputcsv($fp, $total_line, ';');
    }
    fclose($fp);
    exit;
}

// ── CSV URL helper ──────────────────────────────────────
$csv_params = array_filter([
    'type'      => $type,
    'export'    => 'csv',
    'date_from' => $f_from,
    'date_to'   => $f_to,
    'firma'     => $f_firma,
    'durum'     => $f_durum,
    'urun'      => $f_urun,
    'bolge'     => $f_bolge,
    'depo'      => $f_depo,
    'plaka'     => $f_plaka,
    'mat_type'  => $f_mtype,
    'q'         => $f_q,
    'sort'      => ($f_sort !== 'tarih') ? $f_sort : '',
]);
$csv_url = 'reports.php?' . http_build_query($csv_params);

$page_title = $type !== '' ? (($report_meta[$type]['icon'] ?? '') . ' ' . ($report_meta[$type]['label'] ?? '')) . ' Raporu' : 'Raporlar';
render_header($page_title);
render_flash();
?>

<style>
@media print {
    .topbar,.bottomnav,.rpt-head .rpt-actions,.rpt-filter-card,.rpt-no-print{display:none!important}
    body,.container{background:#fff!important;padding:0!important}
    .rpt-summary{border:1px solid #ccc!important}
    .data-table{font-size:8pt}
    .table-wrap{overflow:visible!important}
}
</style>

<?php if ($type === ''): ?>
<!-- ── Landing ───────────────────────────────────────── -->
<div class="page-head">
    <div>
        <h1>📊 Raporlar</h1>
        <p class="muted">Verilerinizi analiz edin ve dışa aktarın</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<div class="home-grid">
<?php foreach ($report_meta as $rtype => $m): ?>
    <a href="reports.php?type=<?= h($rtype) ?>" class="home-card">
        <div class="home-card-icon" style="background:<?= h($m['bg']) ?>"><?= $m['icon'] ?></div>
        <div class="home-card-title"><?= h($m['label']) ?></div>
    </a>
<?php endforeach; ?>
</div>

<?php else: ?>
<!-- ── Rapor Sayfası ─────────────────────────────────── -->
<?php $meta = $report_meta[$type]; ?>

<div class="page-head rpt-head">
    <div class="rpt-title">
        <a href="reports.php" class="btn btn-ghost btn-sm rpt-no-print">← Raporlar</a>
        <h1><?= $meta['icon'] ?> <?= h($meta['label']) ?> Raporu</h1>
        <p class="muted"><?= count($rows) ?> kayıt</p>
    </div>
    <div class="rpt-actions rpt-no-print">
        <button onclick="window.print()" class="btn btn-sm">🖨 Yazdır</button>
        <a href="<?= h($csv_url) ?>" class="btn btn-sm btn-primary">⬇ Excel/CSV</a>
    </div>
</div>

<!-- ── Filtre Formu ── -->
<div class="rpt-filter-card rpt-no-print">
<form method="get" class="rpt-filter-form">
    <input type="hidden" name="type" value="<?= h($type) ?>">

    <?php if (in_array($type, ['yukleme','cikma','depo','urun','firma','kantar'], true)): ?>
    <div class="rpt-filter-group">
        <label>Başlangıç<input type="date" name="date_from" value="<?= h($f_from) ?>"></label>
        <label>Bitiş<input type="date" name="date_to" value="<?= h($f_to) ?>"></label>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma','firma','urun'], true)): ?>
    <div class="rpt-filter-group">
        <label>Firma<input type="text" name="firma" value="<?= h($f_firma) ?>" placeholder="Firma ara..."></label>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <label>Ürün<input type="text" name="urun" value="<?= h($f_urun) ?>" placeholder="Ürün ara..."></label>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
    <div class="rpt-filter-group">
        <label>Bölge<input type="text" name="bolge" value="<?= h($f_bolge) ?>" placeholder="Bölge..."></label>
        <label>Durum
            <select name="durum">
                <option value="">Tümü</option>
                <option value="" <?= $f_durum==='' ? 'selected':'' ?>>Tümü</option>
                <option value="islendi"  <?= $f_durum==='islendi' ? 'selected':'' ?>>İşlendi</option>
                <option value="yuklendi" <?= $f_durum==='yuklendi'? 'selected':'' ?>>Yüklendi</option>
            </select>
        </label>
    </div>
    <?php endif; ?>

    <?php if ($type === 'depo'): ?>
    <div class="rpt-filter-group">
        <label>Depo<input type="text" name="depo" value="<?= h($f_depo) ?>" placeholder="Depo adı..."></label>
        <label>Firma<input type="text" name="firma" value="<?= h($f_firma) ?>" placeholder="Firma..."></label>
    </div>
    <?php endif; ?>

    <?php if ($type === 'kantar'): ?>
    <div class="rpt-filter-group">
        <label>Firma<input type="text" name="firma" value="<?= h($f_firma) ?>" placeholder="Firma..."></label>
        <label>Plaka<input type="text" name="plaka" value="<?= h($f_plaka) ?>" placeholder="Plaka..."></label>
    </div>
    <?php endif; ?>

    <?php if ($type === 'malzeme'): ?>
    <div class="rpt-filter-group">
        <?php $type_labels = definition_types(); ?>
        <label>Tür
            <select name="mat_type">
                <option value="">Tüm Türler</option>
                <?php foreach ($type_labels as $tk => $tl): if (in_array($tk,['firma','depo','urun'],true)) continue; ?>
                <option value="<?= h($tk) ?>" <?= $f_mtype===$tk?'selected':'' ?>><?= h($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ara<input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Malzeme adı..."></label>
    </div>
    <?php endif; ?>

    <?php if (in_array($type, ['yukleme','cikma','kantar'], true)): ?>
    <div class="rpt-filter-group">
        <label class="rpt-search-label">Genel Arama<input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Firma, parti, alıcı..."></label>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <label>Sıralama
            <select name="sort">
                <option value="tarih" <?= $f_sort==='tarih'?'selected':'' ?>>Tarih</option>
                <option value="firma" <?= $f_sort==='firma'?'selected':'' ?>>Firma</option>
                <option value="durum" <?= $f_sort==='durum'?'selected':'' ?>>Durum</option>
                <option value="palet" <?= $f_sort==='palet'?'selected':'' ?>>Palet Sayısı</option>
                <option value="net"   <?= $f_sort==='net'  ?'selected':'' ?>>Net KG</option>
            </select>
        </label>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="rpt-filter-actions">
        <button class="btn btn-primary btn-sm">Filtrele</button>
        <a href="reports.php?type=<?= h($type) ?>" class="btn btn-ghost btn-sm">Sıfırla</a>
    </div>
</form>
</div>

<!-- ── Özet ── -->
<?php if (!empty($totals)): ?>
<div class="rpt-summary">
    <div class="rpt-sum-item"><span>Kayıt</span><strong><?= count($rows) ?></strong></div>
    <?php if (isset($totals['palet_sayisi'])): ?>
    <div class="rpt-sum-item"><span>Palet</span><strong><?= number_format((int)$totals['palet_sayisi'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_kasa'])): ?>
    <div class="rpt-sum-item"><span>Kasa</span><strong><?= number_format((int)$totals['toplam_kasa'], 0, ',', '.') ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_brut'])): ?>
    <div class="rpt-sum-item"><span>Brüt KG</span><strong><?= fmt_kg($totals['toplam_brut']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_net'])): ?>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Net KG</span><strong><?= fmt_kg($totals['toplam_net']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['net_kg'])): ?>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Net KG</span><strong><?= fmt_kg($totals['net_kg']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['toplam_dara_kg'])): ?>
    <div class="rpt-sum-item"><span>Toplam Dara</span><strong><?= fmt_kg($totals['toplam_dara_kg']) ?></strong></div>
    <?php endif; ?>
    <?php if (isset($totals['nakliye_bedeli']) && $totals['nakliye_bedeli'] > 0): ?>
    <div class="rpt-sum-item"><span>Nakliye Bedeli</span><strong><?= fmt_money($totals['nakliye_bedeli']) ?></strong></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Tablo ── -->
<?php if (empty($rows)): ?>
<div class="empty"><p>Filtre kriterlerine uygun kayıt bulunamadı.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table rpt-table">
    <thead>
    <tr>
        <?php foreach ($cols as $c): ?>
        <th class="<?= in_array($c, ['toplam_brut','toplam_dara','toplam_net','toplam_kasa','palet_sayisi','kayit_sayisi','toplam_adet','toplam_dara_kg','kullanim_sayisi','unit_dara_kg','net_kg','tartim1','tartim2','nakliye_bedeli','avans','toplam_kayit','yukleme_sayisi','cikma_sayisi','kasa_sayisi'], true) ? 'num' : '' ?>"><?= h(col_label($c)) ?></th>
        <?php endforeach; ?>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?><th class="actions-col rpt-no-print">Bağlantı</th><?php endif; ?>
        <?php if ($type === 'kantar'): ?><th class="actions-col rpt-no-print">Bağlantı</th><?php endif; ?>
    </tr>
    </thead>
    <tbody>
    <?php $num_cols = ['toplam_brut','toplam_dara','toplam_net','toplam_kasa','toplam_adet','toplam_dara_kg','unit_dara_kg','net_kg','tartim1','tartim2','nakliye_bedeli','avans','toplam_brut','stok_giris','stok_sevk']; ?>
    <?php $int_cols = ['id','palet_sayisi','kayit_sayisi','kullanim_sayisi','toplam_kayit','yukleme_sayisi','cikma_sayisi','kasa_sayisi','palet_sayisi']; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
        <?php foreach ($cols as $c): ?>
        <?php
            $v = $r[$c] ?? '';
            if ($c === 'durum') {
                $cls = $v === 'islendi' ? 'badge-islendi' : ($v === 'yuklendi' ? 'badge-yuklendi' : '');
                $lbl = $v === 'islendi' ? 'İşlendi' : ($v === 'yuklendi' ? 'Yüklendi' : h($v));
                echo '<td>' . ($v !== '' ? '<span class="rpt-badge '.$cls.'">'.$lbl.'</span>' : '<span class="muted">—</span>') . '</td>';
            } elseif ($c === 'stok_mevcut') {
                $sv = (float)$v;
                $color = $sv < 0 ? ' style="color:#dc2626;font-weight:700"' : ($sv > 0 ? ' style="font-weight:600"' : '');
                echo '<td class="num"' . $color . '>' . h(fmt_kg($sv)) . '</td>';
            } elseif ($c === 'is_active') {
                echo '<td>' . ($v ? '<span class="rpt-badge badge-yuklendi">Aktif</span>' : '<span class="muted">Pasif</span>') . '</td>';
            } elseif ($c === 'material_type') {
                $tl = definition_types();
                echo '<td>' . h($tl[$v] ?? $v) . '</td>';
            } elseif ($c === 'on_plaka') {
                $arka = $r['arka_plaka'] ?? '';
                echo '<td>' . h(trim($v . ($arka ? ' / '.$arka : ''), ' /')) . '</td>';
            } elseif ($c === 'arka_plaka') {
                // skip, handled with on_plaka
                continue;
            } elseif (in_array($c, ['toplam_dara','toplam_net'], true)) {
                $rv = round((float)$v);
                echo '<td class="num">' . ($rv != 0 ? h(fmt_kg($rv)) : '<span class="muted">—</span>') . '</td>';
            } elseif (in_array($c, $num_cols, true)) {
                echo '<td class="num">' . (($v != 0) ? h(fmt_kg((float)$v)) : '<span class="muted">—</span>') . '</td>';
            } elseif (in_array($c, $int_cols, true)) {
                echo '<td class="num">' . ($v > 0 ? number_format((int)$v, 0, ',', '.') : '<span class="muted">0</span>') . '</td>';
            } elseif ($c === 'tarih' || $c === 'ilk_tarih' || $c === 'son_tarih') {
                echo '<td>' . h($v ? fmt_date($v) : '—') . '</td>';
            } elseif ($c === 'giris_tarih' || $c === 'cikis_tarih' || $c === 'created_at') {
                echo '<td>' . h($v ? fmt_datetime($v) : '—') . '</td>';
            } else {
                echo '<td>' . h($v !== '' ? $v : '—') . '</td>';
            }
        ?>
        <?php endforeach; ?>
        <?php if (in_array($type, ['yukleme','cikma'], true)): ?>
        <td class="rpt-no-print"><a href="record_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        <?php endif; ?>
        <?php if ($type === 'kantar'): ?>
        <td class="rpt-no-print"><a href="kantar_view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm">Görüntüle</a></td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if (!empty($totals)): ?>
    <tfoot>
    <tr class="totals-row">
        <?php
        $shown_cols = $cols;
        if (in_array('arka_plaka', $shown_cols, true)) {
            $shown_cols = array_filter($shown_cols, fn($c) => $c !== 'arka_plaka');
        }
        $i = 0;
        foreach ($shown_cols as $c):
            $i++;
            if ($i === 1) { echo '<td class="right strong">TOPLAM</td>'; continue; }
            if (isset($totals[$c])):
                $is_int = in_array($c, $int_cols, true);
                if (in_array($c, ['toplam_dara','toplam_net'], true)) {
                    echo '<td class="num strong">' . fmt_kg(round((float)$totals[$c])) . '</td>';
                } elseif ($is_int) {
                    echo '<td class="num strong">' . number_format((int)$totals[$c], 0, ',', '.') . '</td>';
                } else {
                    echo '<td class="num strong">' . fmt_kg((float)$totals[$c]) . '</td>';
                }
            else:
                echo '<td></td>';
            endif;
        endforeach;
        if (in_array($type, ['yukleme','cikma','kantar'], true)) echo '<td class="rpt-no-print"></td>';
        ?>
    </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
