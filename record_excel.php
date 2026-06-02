<?php
// =========================================================
// record_excel.php — Tek yükleme kaydını Excel (.xls) indir
// "YÜKLEME PLANI" referans şablonuna benzer kompakt düzen.
// HTML-tablo tabanlı .xls (composer/PhpSpreadsheet YOK).
// Yazdır ekranıyla (record_view.php) AYNI veri/hesap mantığı.
// Dara = yalnız kasa + palet (sarf hariç).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
require_perm('records.read');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Geçersiz kayıt.'); }

$st = db()->prepare("SELECT * FROM loading_records WHERE id = :id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) { http_response_code(404); die('Kayıt bulunamadı.'); }

// Paletler + kasa/palet tanımları (record_view.php ile aynı)
$st = db()->prepare("
    SELECT p.*,
           kc.name AS kasa_cinsi_adi, pt.name AS palet_tipi_adi
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = :r
    ORDER BY p.sira_no, p.id
");
$st->execute([':r' => $id]);
$pallets = $st->fetchAll();

$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name, m.type AS material_type
    FROM pallet_materials pm
    JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = :p
");
foreach ($pallets as &$p) {
    $st_pm->execute([':p' => $p['id']]);
    $p['materials'] = $st_pm->fetchAll();
}
unset($p);

$tot         = record_totals($id);          // kasa+palet bazlı toplamlar
$type_labels = definition_types();

$defs_by_id = [];
foreach (db()->query("SELECT id, type, name FROM material_definitions")->fetchAll() as $d) {
    $defs_by_id[(int)$d['id']] = $d;
}

// ── Stok çıkışları (kasa + palet + sarf, ADET) — record_view ile aynı ──
$stok_use = [];
foreach ($pallets as $p) {
    if ($p['kasa_cinsi_id']) {
        $kid = (int)$p['kasa_cinsi_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + (int)$p['kasa_adeti'];
    }
    if ($p['palet_tipi_id']) {
        $kid = (int)$p['palet_tipi_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + 1;
    }
    foreach ($p['materials'] as $m) {
        $kid = (int)$m['def_id'];
        $stok_use[$kid] = ($stok_use[$kid] ?? 0) + (float)$m['quantity'];
    }
}
$malzeme_list = [];
foreach ($stok_use as $def_id => $adet) {
    if ($adet <= 0) continue;
    $d = $defs_by_id[$def_id] ?? null;
    if (!$d) continue;
    $malzeme_list[] = ['name' => mb_strtoupper($d['name'], 'UTF-8'), 'type' => $d['type'], 'adet' => $adet];
}
usort($malzeme_list, fn($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

// ── Ürün grupları (sağ totals altı) — record_view ile aynı ──
$urun_groups = [];
foreach ($pallets as $p) {
    $u = trim($p['urun_cinsi'] ?? '') ?: '—';
    if (!isset($urun_groups[$u])) $urun_groups[$u] = ['kasa' => 0, 'brut' => 0, 'dara' => 0, 'net' => 0];
    $urun_groups[$u]['kasa'] += (int)$p['kasa_adeti'];
    $urun_groups[$u]['brut'] += (float)$p['brut_kg'];
    $urun_groups[$u]['dara'] += (float)$p['dara_kg'];
    $urun_groups[$u]['net']  += (float)$p['net_kg'];
}
$urun_keys = array_slice(array_keys($urun_groups), 0, 4);

// ── Alt SİZE özeti (palet.size kırılımı) ──
$size_groups = [];
foreach ($pallets as $p) {
    $sz = trim($p['size'] ?? '');
    if ($sz === '') continue;
    if (!isset($size_groups[$sz])) $size_groups[$sz] = ['kasa' => 0, 'brut' => 0, 'net' => 0];
    $size_groups[$sz]['kasa'] += (int)$p['kasa_adeti'];
    $size_groups[$sz]['brut'] += (float)$p['brut_kg'];
    $size_groups[$sz]['net']  += (float)$p['net_kg'];
}

// ── Sağ blok satırları (TOPLAM + ürün grupları) ──
$right_rows = [];
$mk_block = function (string $label, array $g) {
    return [
        ['label' => $label, 'metric' => 'BRÜT KG',    'val' => round((float)$g['brut'])],
        ['label' => '',      'metric' => 'DARA KG',    'val' => round((float)$g['dara'])],
        ['label' => '',      'metric' => 'NET KG',     'val' => round((float)$g['net'])],
        ['label' => '',      'metric' => 'KASA ADETİ', 'val' => (int)$g['kasa']],
    ];
};
$right_rows = array_merge($right_rows, $mk_block('TOPLAM', [
    'brut' => $tot['toplam_brut'], 'dara' => $tot['toplam_dara'],
    'net'  => $tot['toplam_net'],  'kasa' => $tot['toplam_kasa'],
]));
foreach ($urun_keys as $uk) {
    $right_rows = array_merge($right_rows, $mk_block(mb_strtoupper($uk, 'UTF-8'), $urun_groups[$uk]));
}

// ── Yardımcılar ──
function xls_n($v): string { $n = (float)$v; return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.'); }
$brand = strtoupper(trim((string)($record['brand'] ?? '')));
$brand_title = ($brand === 'URAL') ? 'URAL FRESH'
            : (($brand === 'URAS') ? 'URAS FRESH'
            : (($brand === 'AGRO') ? 'AGRO FRESH'
            : (($brand === 'ASYA') ? 'ASYA FRESH'
            : (trim((string)($record['firma'] ?? '')) ?: 'ASYA FRESH'))));

$n_body = max(count($pallets), count($malzeme_list), count($right_rows));

// ── Çıktı başlıkları ──
$fname = 'yukleme_plani_' . $id . '_' . date('Ymd') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache');
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta charset="utf-8">
<style>
    table { border-collapse: collapse; font-family: Calibri, Arial, sans-serif; font-size: 10pt; }
    td, th { border: 0.5pt solid #000; padding: 2px 5px; vertical-align: middle; mso-number-format: "\@"; }
    .brand { font-size: 16pt; font-weight: bold; text-align: center; border: 1pt solid #000; }
    .lbl   { font-weight: bold; background: #f2f2f2; white-space: nowrap; }
    .val   { font-weight: bold; }
    .hd    { font-weight: bold; text-align: center; background: #e8e8e8; }
    .num   { text-align: right; mso-number-format: "0.#"; }
    .numi  { text-align: right; mso-number-format: "\#\,\#\#0"; }
    .glabel{ font-weight: bold; text-align: center; vertical-align: middle; }
    .gmetric { font-weight: bold; }
    .center { text-align: center; }
    .nb { border: none; }
</style></head>
<body>

<!-- ÜST BİLGİ -->
<table>
    <tr><td class="brand" colspan="9"><?= h($brand_title) ?></td></tr>
    <?php
        $plk = trim((string)($record['on_plaka'] ?? ''));
        $dorse = trim((string)($record['arka_plaka'] ?? ''));
        $info_left = [
            ['ŞOFÖR ADI',     $record['sofor_adi'] ?? ''],
            ['ÇEKİCİ PLAKA',  $plk],
            ['DORSE PLAKA',   $dorse],
            ['ŞOFÖR GSM',     $record['telefon'] ?? ''],
            ['NAKLİYE FİRMA', $record['nakliye_sirketi'] ?? ''],
            ['TAKİP NO',      $record['casus_no'] ?? ''],
            ['KONTEYNER NO',  ''],
        ];
        $info_right = [
            ['TARİH',    fmt_date($record['tarih'])],
            ['PARTİ NO', $record['parti_no'] ?? ''],
            ['BÖLGE',    $record['bolge'] ?? ''],
            ['GÜMRÜK',   $record['gumruk'] ?? ''],
            ['ULAŞIM',   ''],
            ['ALICI',    $record['alici'] ?? ''],
            ['ÜLKE',     ''],
        ];
        $urun_disp = mb_strtoupper(trim((string)($record['urun'] ?? '')), 'UTF-8');
        for ($i = 0; $i < 7; $i++):
            $L = $info_left[$i]; $R = $info_right[$i];
    ?>
    <tr>
        <td class="lbl" colspan="2"><?= h($L[0]) ?></td>
        <td class="val" colspan="3"><?= h($L[1]) ?></td>
        <td class="lbl"><?= h($R[0]) ?></td>
        <td class="val" colspan="2"><?= h($R[1]) ?></td>
        <?php if ($i === 0): ?>
        <td class="brand" rowspan="6" style="font-size:13pt"><?= h($urun_disp) ?></td>
        <?php endif; ?>
    </tr>
    <?php endfor; ?>
</table>

<!-- PALET TABLOSU + MALZEME + GENEL TOPLAM (yan yana) -->
<table>
    <tr>
        <th class="hd">PALET NO</th>
        <th class="hd">KASA ADETİ</th>
        <th class="hd">SİZE</th>
        <th class="hd">BRÜT AĞIRLIK</th>
        <th class="hd">DARA</th>
        <th class="hd">KASA CİNSİ</th>
        <th class="hd">CİNSİ</th>
        <th class="hd">NET KG</th>
        <th class="hd nb"></th>
        <th class="hd">MALZEME</th>
        <th class="hd">MİKTAR</th>
        <th class="hd nb"></th>
        <th class="hd" colspan="2">GENEL TOPLAM</th>
        <th class="hd">DEĞER</th>
    </tr>
    <?php for ($i = 0; $i < $n_body; $i++):
        $p  = $pallets[$i]      ?? null;
        $ml = $malzeme_list[$i] ?? null;
        $rr = $right_rows[$i]   ?? null;
    ?>
    <tr>
        <?php if ($p): ?>
        <td class="center"><?= h($p['palet_no'] ?: (string)($i + 1)) ?></td>
        <td class="numi"><?= (int)$p['kasa_adeti'] ?></td>
        <td class="center"><?= h($p['size'] ?? '') ?></td>
        <td class="num"><?= xls_n($p['brut_kg']) ?></td>
        <td class="num"><?= xls_n($p['dara_kg']) ?></td>
        <td><?= h($p['kasa_cinsi_adi'] ?? '') ?></td>
        <td><?= h(mb_strtoupper(trim((string)($p['urun_cinsi'] ?? '')), 'UTF-8')) ?></td>
        <td class="num"><?= xls_n($p['net_kg']) ?></td>
        <?php else: ?>
        <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        <?php endif; ?>

        <td class="nb"></td>

        <?php if ($ml): ?>
        <td><?= h($ml['name']) ?></td>
        <td class="numi"><?= xls_n($ml['adet']) ?></td>
        <?php else: ?>
        <td></td><td></td>
        <?php endif; ?>

        <td class="nb"></td>

        <?php if ($rr): ?>
        <td class="glabel"><?= h($rr['label']) ?></td>
        <td class="gmetric"><?= h($rr['metric']) ?></td>
        <td class="numi"><?= (int)$rr['val'] ?></td>
        <?php else: ?>
        <td></td><td></td><td></td>
        <?php endif; ?>
    </tr>
    <?php endfor; ?>
</table>

<!-- ALT SİZE ÖZETİ -->
<table>
    <tr>
        <th class="hd">SİZE</th>
        <th class="hd">KASA</th>
        <th class="hd">BRÜT</th>
        <th class="hd">NET</th>
    </tr>
    <?php if (!empty($size_groups)): foreach ($size_groups as $sz => $g): ?>
    <tr>
        <td class="center"><?= h($sz) ?></td>
        <td class="numi"><?= (int)$g['kasa'] ?></td>
        <td class="numi"><?= (int)round((float)$g['brut']) ?></td>
        <td class="numi"><?= (int)round((float)$g['net']) ?></td>
    </tr>
    <?php endforeach; else: ?>
    <tr><td class="center">—</td><td class="numi">0</td><td class="numi">0</td><td class="numi">0</td></tr>
    <?php endif; ?>
</table>

</body>
</html>
<?php
exit;
