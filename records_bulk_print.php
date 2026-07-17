<?php
// =========================================================
// records_bulk_print.php
// Filtreli yükleme kayıtlarını tek PDF sayfasında yazdır
// Her kayıt ayrı A4 sayfası — sayfa aralarında page-break
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

// ── Filtre parametreleri (reports.php yukleme ile aynı mantık) ──
$f_from           = trim($_GET['date_from']     ?? '');
$f_to             = trim($_GET['date_to']       ?? '');
$f_firma          = trim($_GET['firma']         ?? '');
$f_urun           = trim($_GET['urun']          ?? '');
$f_bolge          = trim($_GET['bolge']         ?? '');
$f_depo           = trim($_GET['depo']          ?? '');
$f_durum          = trim($_GET['durum']         ?? '');
$f_q              = trim($_GET['q']             ?? '');
$f_palet_islendi  = trim($_GET['palet_islendi'] ?? '');
$f_urun_sahibi    = trim($_GET['urun_sahibi']   ?? '');
$f_casus          = trim($_GET['casus']         ?? '');
if (!in_array($f_casus, ['dolu','bos'], true)) $f_casus = '';
$f_sort           = trim($_GET['sort']          ?? 'tarih');

// ── Kayıtları çek (max 50) ──
$w = ["r.type='yukleme'"]; $p = [];
if ($f_from  !== '') { $w[] = "r.tarih >= ?"; $p[] = $f_from; }
if ($f_to    !== '') { $w[] = "r.tarih <= ?"; $p[] = $f_to; }
if ($f_firma !== '') { $w[] = "r.firma = ?";  $p[] = $f_firma; }
if ($f_urun  !== '') { $w[] = "r.urun = ?";   $p[] = $f_urun; }
if ($f_bolge !== '') { $w[] = "r.bolge = ?";  $p[] = $f_bolge; }
if ($f_durum !== '') { $w[] = "r.durum = ?";  $p[] = $f_durum; }
if ($f_depo  !== '') {
    $w[] = "EXISTS (SELECT 1 FROM loading_pallets _p2 WHERE _p2.loading_record_id=r.id AND _p2.depo=?)";
    $p[] = $f_depo;
}
// Aktif depo kapsamı — toplu yazdırma seçili depoya daraltılır
[$_ds_bp, $_ds_bp_v] = depo_sql_records_in('r');
if ($_ds_bp !== '') { $w[] = $_ds_bp; $p = array_merge($p, $_ds_bp_v); }
if ($f_q !== '') {
    $w[] = "(r.firma LIKE ? OR r.parti_no LIKE ? OR r.alici LIKE ? OR r.urun LIKE ?)";
    $p[] = '%'.$f_q.'%'; $p[] = '%'.$f_q.'%'; $p[] = '%'.$f_q.'%'; $p[] = '%'.$f_q.'%';
}
if ($f_casus === 'dolu') { $w[] = "r.casus_no != '' AND r.casus_no IS NOT NULL"; }
if ($f_casus === 'bos')  { $w[] = "(r.casus_no IS NULL OR r.casus_no = '')"; }
if ($f_palet_islendi === 'isaretli') {
    $w[] = "EXISTS (SELECT 1 FROM loading_pallets _pi WHERE _pi.loading_record_id=r.id AND _pi.islendi=1)";
} elseif ($f_palet_islendi === 'hicbiri') {
    $w[] = "EXISTS (SELECT 1 FROM loading_pallets _pi WHERE _pi.loading_record_id=r.id AND (_pi.islendi IS NULL OR _pi.islendi=0))";
}
if ($f_urun_sahibi !== '') {
    $_bp_us_col = false;
    try { $_bp_us_col = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'urun_sahibi_id'")->fetchColumn(); }
    catch (Throwable $_) {}
    if ($_bp_us_col) {
        if ($f_urun_sahibi === '0') {
            $w[] = "r.urun_sahibi_id IS NULL";
        } elseif (ctype_digit($f_urun_sahibi) && (int)$f_urun_sahibi > 0) {
            $w[] = "r.urun_sahibi_id = ?";
            $p[] = (int)$f_urun_sahibi;
        }
    }
}
$sort_map = [
    'tarih'    => 'r.tarih DESC, r.id DESC',
    'firma'    => 'r.firma ASC, r.tarih DESC',
    'urun'     => 'r.urun ASC, r.tarih DESC',
    'durum'    => 'r.durum ASC, r.tarih DESC',
];
$order_by = $sort_map[$f_sort] ?? 'r.tarih DESC, r.id DESC';

$st = db()->prepare("SELECT r.id FROM loading_records r
    WHERE " . implode(' AND ', $w) . "
    ORDER BY $order_by LIMIT 50");
$st->execute($p);
$record_ids = $st->fetchAll(PDO::FETCH_COLUMN);

if (empty($record_ids)) {
    set_flash('info', 'Yazdırılacak kayıt bulunamadı.');
    header('Location: reports.php?type=yukleme');
    exit;
}

// ── Malzeme tanımları (tüm kayıtlar için tek seferlik) ──
$all_defs = db()->query("SELECT id, type, name, unit_dara_kg
    FROM material_definitions ORDER BY type, name")->fetchAll();
$defs_by_id = [];
foreach ($all_defs as $d) $defs_by_id[(int)$d['id']] = $d;

// ── Palet sorgusu (hazır statement) ──
$st_pallets = db()->prepare("
    SELECT p.*,
           kc.name AS kasa_cinsi_adi,
           kc.unit_dara_kg AS kasa_cinsi_kg,
           pt.name AS palet_tipi_adi,
           pt.unit_dara_kg AS palet_tipi_kg
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = ?
    ORDER BY p.sira_no, p.id
");
$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name,
           m.type AS material_type, m.unit_dara_kg AS material_unit
    FROM pallet_materials pm
    JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = ?
");

// ── Yardımcı fonksiyonlar (record_view.php ile aynı) ──
if (!function_exists('_bp_tr_norm')) {
    function _bp_tr_norm(string $s): string {
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace("\xCC\x87", '', $s);
        $s = strtr($s, ['ı'=>'i','ş'=>'s','ç'=>'c','ğ'=>'g','ü'=>'u','ö'=>'o']);
        return str_replace([' ', '-', '.', ','], '', $s);
    }
    function _bp_stok_satir_topla(array $stok_use, array $defs_by_id, array $allowed_types, ?string $name_match): array {
        $adet = 0.0; $kg = 0.0;
        foreach ($stok_use as $def_id => $u) {
            $d = $defs_by_id[$def_id] ?? null;
            if (!$d) continue;
            if (!in_array($d['type'], $allowed_types)) continue;
            if ($name_match) {
                if (strpos(_bp_tr_norm($d['name']), _bp_tr_norm($name_match)) === false) continue;
            }
            $adet += $u['adet'];
            $kg   += $u['kg'];
        }
        return ['adet' => $adet, 'kg' => $kg];
    }
}

// ── Stok satır tanımları (record_view.php ile aynı) ──
$stok_rows = [
    ['ihracat_paleti', 'İHRACAT PALETİ',  ['palet_tipi'], 'ihracat'],
    ['sapka',          'ŞAPKA',            ['sapka'],      null],
    ['kosebent',       'KÖŞEBENT',         ['kosebent'],   null],
    ['serit',          'ŞERİT',            ['serit'],      null],
    ['casus',          'CASUS',            ['casus'],      null],
    ['kasa_h9_yesil',  'PLS KASA H-9 YEŞİL',   ['kasa_cinsi'], 'h-9 yeşil'],
    ['kasa_h9_mavi',   'PLS KASA H-9 MAVİ',    ['kasa_cinsi'], 'h-9 mavi'],
    ['kasa_h5_siyah',  'PLS KASA H-5 SİYAH',   ['kasa_cinsi'], 'h-5 siyah'],
    ['kasa_h5_mavi',   'PLS KASA H-5 MAVİ',    ['kasa_cinsi'], 'h-5 mavi'],
    ['kasa_h85_mavi',  'PLS KASA H-8,5 MAVİ',  ['kasa_cinsi'], 'h-8,5 mavi'],
    ['kasa_h85_yesil', 'PLS KASA H-8,5 YEŞİL', ['kasa_cinsi'], 'h-8,5 yeşil'],
    ['kasa_h10_beyaz', 'PLS KASA H-10 BEYAZ',  ['kasa_cinsi'], 'h-10 beyaz'],
    ['kasa_h95_mavi',  'PLS KASA H-9,5 MAVİ',  ['kasa_cinsi'], 'h-9,5 mavi'],
    ['kasa_h95_yesil', 'PLS KASA H-9,5 YEŞİL', ['kasa_cinsi'], 'h-9,5 yeşil'],
    ['kasa_etiketi',   'KASA ETİKETİ',    ['kasa_etiketi'], null],
    ['minti',          'MİNTİ',           ['minti'],        null],
    ['kenar_kartonu',  'KENAR KARTONU',   ['kenar_kartonu'], null],
    ['taban_kagidi',   'TABAN KAĞIDI',    ['taban_kagidi'],  null],
    ['sale',           'ŞALE',            ['sale'],          null],
    ['viyol',          'VİYOL',           ['viyol'],         null],
    ['kose_karton',    'KÖŞE KARTON',     ['kose_karton'],   null],
    ['kraf_kagit',     'KRAF KAĞIT',      ['kraft_kagit'],   null],
    ['file',           'FİLE',            ['file'],          null],
];

$grid_rows  = 26;
$total_recs = count($record_ids);
$filter_label = implode(' · ', array_filter([
    $f_firma ?: null,
    $f_urun  ?: null,
    ($f_from && $f_to && $f_from === $f_to) ? $f_from : ($f_from || $f_to ? ($f_from ?: '?') . ' – ' . ($f_to ?: '?') : null),
]));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yükleme Raporları (<?= $total_recs ?> kayıt)<?= $filter_label ? ' — '.$filter_label : '' ?></title>
<link rel="stylesheet" href="assets/style.css">
<style>
@page { size: A4 portrait; margin: 3mm; }
html, body { background: #fff !important; margin: 0; padding: 0; }
.bp-no-print { margin: 8px 12px; }
@media print {
    .bp-no-print { display: none !important; }
    .bp-page-break { page-break-after: always; break-after: page; }
}
</style>
</head>
<body>

<div class="bp-no-print">
    <button onclick="window.print()" class="btn btn-primary">🖨 Yazdır / PDF İndir</button>
    <a href="javascript:history.back()" class="btn btn-ghost">← Geri</a>
    <span style="margin-left:10px;color:#666;font-size:.85rem"><?= $total_recs ?> kayıt<?= $filter_label ? ' — '.$filter_label : '' ?></span>
</div>

<?php foreach ($record_ids as $_idx => $_rid):
    // ── Kayıt verisi yükle ──
    $st_r = db()->prepare("SELECT * FROM loading_records WHERE id=?");
    $st_r->execute([$_rid]);
    $record = $st_r->fetch();
    if (!$record) continue;
    $_bp_b = strtoupper(trim((string)($record['brand'] ?? '')));
    $_bp_brand_names = ['ASYA' => 'ASYA FRESH', 'URAL' => 'URAL', 'URAS' => 'URAS ENERGY', 'AGRO' => 'AGRONATURAL'];
    $_bp_brand_label = $_bp_b !== '' ? ($_bp_brand_names[$_bp_b] ?? $_bp_b) : 'ASYA FRESH';

    // Paletler
    $st_pallets->execute([$_rid]);
    $pallets = $st_pallets->fetchAll();
    foreach ($pallets as &$_pp) {
        $st_pm->execute([$_pp['id']]);
        $_pp['materials'] = $st_pm->fetchAll();
    }
    unset($_pp);

    $tot = record_totals((int)$_rid);

    // stok_use
    $stok_use = [];
    foreach ($pallets as $_pp) {
        if ($_pp['kasa_cinsi_id']) {
            $kid = (int)$_pp['kasa_cinsi_id'];
            if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet'=>0,'kg'=>0];
            $stok_use[$kid]['adet'] += (int)$_pp['kasa_adeti'];
            $stok_use[$kid]['kg']   += (int)$_pp['kasa_adeti'] * (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
        }
        if ($_pp['palet_tipi_id']) {
            $kid = (int)$_pp['palet_tipi_id'];
            if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet'=>0,'kg'=>0];
            $stok_use[$kid]['adet'] += 1;
            $stok_use[$kid]['kg']   += (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
        }
        foreach ($_pp['materials'] as $_m) {
            $kid   = (int)$_m['def_id'];
            $basis = material_calc_basis($_m['material_type'], $_m['material_name']);
            $eff   = ($basis === 'kasa') ? (float)$_m['quantity'] * (int)$_pp['kasa_adeti'] : (float)$_m['quantity'];
            if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet'=>0,'kg'=>0];
            $stok_use[$kid]['adet'] += $eff;
            $stok_use[$kid]['kg']   += (float)$_m['material_unit'] * $eff;
        }
    }

    // kasa_dara_breakdown
    $kasa_dara_breakdown = [];
    foreach ($stok_use as $_did => $_u) {
        $_d = $defs_by_id[$_did] ?? null;
        if (!$_d || $_d['type'] !== 'kasa_cinsi') continue;
        $kasa_dara_breakdown[] = [
            'name'    => $_d['name'],
            'adet'    => (int)$_u['adet'],
            'kg'      => (float)$_u['kg'],
            'unit_kg' => (float)($_d['unit_dara_kg'] ?? 0),
        ];
    }

    // palet dara toplamı
    $palet_sapka_kose_dara = 0.0;
    foreach ($pallets as $_pp) {
        if ($_pp['palet_tipi_id'])
            $palet_sapka_kose_dara += (float)($defs_by_id[(int)$_pp['palet_tipi_id']]['unit_dara_kg'] ?? 0);
    }

    // palet bottom label
    $palet_tipi_names = []; $palet_tipi_total_adet = 0;
    foreach ($stok_use as $_did => $_u) {
        $_d = $defs_by_id[$_did] ?? null;
        if ($_d && $_d['type'] === 'palet_tipi') {
            $_unit_kg  = (float)($_d['unit_dara_kg'] ?? 0);
            $_name_str = mb_strtoupper($_d['name'], 'UTF-8');
            if ($_unit_kg > 0) $_name_str .= ' (' . fmt_unit_kg($_unit_kg) . ' KG)';
            $palet_tipi_names[]    = h($_name_str);
            $palet_tipi_total_adet += (int)$_u['adet'];
        }
    }
    $palet_bottom_label = !empty($palet_tipi_names)
        ? implode(' / ', array_unique($palet_tipi_names)) . '<br><small style="font-size:6pt;font-weight:normal">' . $palet_tipi_total_adet . ' ADET</small>'
        : 'PALET';

    // urun_groups
    $urun_groups = [];
    foreach ($pallets as $_pp) {
        $_u = trim($_pp['urun_cinsi'] ?? ''); if ($_u === '') $_u = '—';
        if (!isset($urun_groups[$_u])) $urun_groups[$_u] = ['kasa'=>0,'brut'=>0,'dara'=>0,'net'=>0];
        $urun_groups[$_u]['kasa'] += (int)$_pp['kasa_adeti'];
        $urun_groups[$_u]['brut'] += (float)$_pp['brut_kg'];
        $urun_groups[$_u]['dara'] += (float)$_pp['dara_kg'];
        $urun_groups[$_u]['net']  += (float)$_pp['net_kg'];
    }
    $urun_keys = array_slice(array_keys($urun_groups), 0, 4);

    // pallets_grid (26 satır)
    $pallets_grid = [];
    for ($i = 0; $i < $grid_rows; $i++) $pallets_grid[$i] = $pallets[$i] ?? null;

    // Son kayıtta page-break olmaz
    $_last = ($_idx === $total_recs - 1);
?>
<!-- ── KAYIT #<?= h((string)$_rid) ?> ── -->
<div class="asya-sheet<?= $_last ? '' : ' bp-page-break' ?>">

    <div class="asya-top">
        <div class="asya-brand-full"><?= h($_bp_brand_label) ?></div>
        <div class="asya-top-body">
            <table class="asya-info">
                <tr><th>FİRMA</th><td colspan="3"><?= h($record['firma']) ?></td></tr>
                <tr><th>BÖLGE</th><td colspan="3"><?= h($record['bolge']) ?></td></tr>
                <tr><th>PARTİ NO</th><td class="parti-no-val" colspan="3"><?= h($record['parti_no']) ?></td></tr>
                <tr><th>GÜMRÜK</th><td colspan="3"><?= h($record['gumruk']) ?></td></tr>
                <tr><th>ŞOFÖR ADI</th><td colspan="3"><?= h($record['sofor_adi']) ?></td></tr>
                <tr><th>CASUS NO</th><td colspan="3" class="ai-casus"><?= h($record['casus_no']) ?></td></tr>
                <tr><th>ÖN PLAKA NO</th><td colspan="3" class="ai-emph"><?= h($record['on_plaka']) ?></td></tr>
                <tr><th>ARKA PLAKA NO</th><td colspan="3" class="ai-emph"><?= h($record['arka_plaka']) ?></td></tr>
                <tr><th>NAKLİYE ŞİRKETİ</th><td colspan="3"><?= h($record['nakliye_sirketi']) ?></td></tr>
                <tr><th>ULAŞIM</th><td colspan="3"><?= h($record['ulasim'] ?? '') ?></td></tr>
                <tr><th>TELEFON</th><td colspan="3"><?= h($record['telefon']) ?></td></tr>
                <tr><th>TARİH</th><td colspan="3" class="ai-emph"><?= h(fmt_date($record['tarih'])) ?></td></tr>
            </table>
            <div class="asya-right-top">
                <div class="asya-alici-urun-row">
                    <div class="cell-pair asya-alici-cell">
                        <span>ALICI</span>
                        <strong><?= h($record['alici']) ?></strong>
                    </div>
                    <div class="cell-pair asya-urun-cell">
                        <span>ÜRÜN</span>
                        <strong><?= h($record['urun']) ?></strong>
                    </div>
                </div>
                <?php $_etiket_foto = trim((string)($record['etiket_foto'] ?? '')); ?>
                <div class="asya-etiket">
                    <img class="etiket-img" alt=""
                         <?= $_etiket_foto !== '' ? 'src="' . h($_etiket_foto) . '"' : 'style="display:none"' ?>>
                    <div class="etiket-placeholder"<?= $_etiket_foto !== '' ? ' style="display:none"' : '' ?>>ETİKET</div>
                </div>
            </div>
        </div>
    </div>

    <div class="asya-middle">
        <table class="asya-pallets">
            <thead>
            <tr><th class="th-banner" colspan="9">YÜKLEME PLANI</th></tr>
            <tr>
                <th class="w-no">PALET<br>NO</th>
                <th class="w-kasa">KASA<br>ADETİ</th>
                <th class="w-size">SIZE</th>
                <th class="w-brut">BRÜT<br>KG</th>
                <th class="w-dara">DARA<br>KG</th>
                <th class="w-net">NET<br>KG</th>
                <th class="w-kc">KASA CİNSİ</th>
                <th class="w-uc">ÜRÜN CİNSİ</th>
                <th class="w-depo">DEPO</th>
            </tr>
            </thead>
            <tbody>
            <?php for ($i = 0; $i < $grid_rows; $i++): $p = $pallets_grid[$i]; ?>
                <tr>
                    <td class="row-no"><?= $i + 1 ?></td>
                    <td class="num"><?= $p ? (int)$p['kasa_adeti'] : '' ?></td>
                    <td><?= $p ? h($p['size']) : '' ?></td>
                    <td class="num"><?= $p ? h(fmt_kg($p['brut_kg'])) : '' ?></td>
                    <td class="num"><?= $p ? h(fmt_kg($p['dara_kg'])) : '' ?></td>
                    <td class="num"><?= $p ? h(fmt_kg($p['net_kg'])) : '' ?></td>
                    <td class="small"><?= $p ? h($p['kasa_cinsi_adi']) : '' ?></td>
                    <td class="small"><?= $p ? h($p['urun_cinsi']) : '' ?></td>
                    <td class="small"><?= $p ? h($p['depo']) : '' ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <!-- Sağ sütun: Stok Çıkışları üstte + Genel Toplam altta -->
        <div class="asya-right-col">

        <table class="asya-stok" style="height:auto;align-self:start">
            <thead>
            <tr><th class="th-banner" colspan="2">STOK ÇIKIŞLARI</th></tr>
            <tr><th class="stok-name">MALZEME</th><th class="stok-adet">ADET</th></tr>
            </thead>
            <tbody>
            <?php $stok_shown = 0; foreach ($stok_rows as $sr):
                [$key, $label, $allowed, $match] = $sr;
                $r = _bp_stok_satir_topla($stok_use, $defs_by_id, $allowed, $match);
                if ($r['adet'] <= 0) continue;
                $stok_shown++;
                $adet_str = rtrim(rtrim(number_format($r['adet'], 3, ',', '.'), '0'), ',');
            ?>
                <tr>
                    <td class="stok-name"><?= h($label) ?></td>
                    <td class="stok-val"><?= h($adet_str) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Genel Toplam + Ürün/1..4 -->
        <table class="asya-totals">
            <tr><th class="th-banner" colspan="2">GENEL TOPLAM</th></tr>
            <tr><th>KASA</th><td class="num"><?= (int)$tot['toplam_kasa'] ?></td></tr>
            <tr><th>BRÜT</th><td class="num"><?= h(fmt_kg($tot['toplam_brut'])) ?></td></tr>
            <tr><th>DARA</th><td class="num"><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></td></tr>
            <tr><th>NET</th><td class="num strong"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></td></tr>
            <?php foreach ($urun_keys as $key):
                $g = $urun_groups[$key] ?? null;
            ?>
                <tr><th class="urun-banner" colspan="2"><?= h(mb_strtoupper($key, 'UTF-8')) ?></th></tr>
                <tr><th>KASA</th><td class="num"><?= $g ? (int)$g['kasa'] : '' ?></td></tr>
                <tr><th>BRÜT</th><td class="num"><?= $g ? h(fmt_kg($g['brut'])) : '' ?></td></tr>
                <tr><th>DARA</th><td class="num"><?= $g ? h(fmt_kg(round($g['dara']))) : '' ?></td></tr>
                <tr><th>NET</th> <td class="num"><?= $g ? h(fmt_kg(round((float)$g['net']))) : '' ?></td></tr>
            <?php endforeach; ?>
        </table>

        <?php
            $_note_val = trim((string)($record['etiket'] ?? ''));
            if ($_note_val !== ''):
        ?>
        <div class="print-note-box">
            <div class="print-note-title">NOT</div>
            <div class="print-note-text"><?= nl2br(h($_note_val)) ?></div>
        </div>
        <?php endif; ?>

        </div><!-- /.asya-right-col -->
    </div>

    <table class="asya-bottom" style="table-layout:auto">
        <tr>
            <th class="bot-label">TOPLAM</th>
            <th><?= $palet_bottom_label ?></th>
            <?php foreach ($kasa_dara_breakdown as $k): ?>
            <th><?= h(mb_strtoupper($k['name'], 'UTF-8')) ?> KASA<?= $k['unit_kg'] > 0 ? ' (' . h(fmt_unit_kg($k['unit_kg'])) . ' KG)' : '' ?><br>
                <small style="font-size:6pt;font-weight:normal"><?= (int)$k['adet'] ?> ADET</small></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td class="bot-label" style="font-weight:700;text-align:center">DARA</td>
            <td class="num"><?= h(fmt_kg(round($palet_sapka_kose_dara))) ?></td>
            <?php foreach ($kasa_dara_breakdown as $k): ?>
            <td class="num"><?= h(fmt_kg(round((float)$k['kg']))) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>

    <div class="asya-signatures">
        <div><span>TESLİM EDEN</span><div class="line"></div></div>
        <div><span>TESLİM ALAN</span><div class="line"></div></div>
    </div>

</div><!-- /asya-sheet -->
<?php endforeach; ?>

<script>window.addEventListener('load', function(){ window.print(); });</script>
</body>
</html>
