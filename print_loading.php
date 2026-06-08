<?php
// =========================================================
// print_loading.php
// Yükleme Planı Yazdırma — Özet & Detaylı şablonlar
// Sprint Print-01
//
// URL:
//   print_loading.php?id=X&mode=summary   (varsayılan, Portrait)
//   print_loading.php?id=X&mode=detail    (Landscape, tüm paletler)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';

$auth_user = require_login();
require_perm('records.read');

// ── Parametreler ─────────────────────────────────────────
$id   = (int)($_GET['id'] ?? 0);
$mode = print_mode(); // 'summary' | 'detail'

if ($id <= 0) { header('Location: records.php'); exit; }

// ── Ana kayıt ────────────────────────────────────────────
$st = db()->prepare("SELECT * FROM loading_records WHERE id=:id");
$st->execute([':id' => $id]);
$record = $st->fetch();

if (!$record || ($record['type'] ?? 'yukleme') !== 'yukleme') {
    header('Location: records.php'); exit;
}

// ── Paletler ─────────────────────────────────────────────
$st = db()->prepare("
    SELECT p.*,
           kc.name          AS kasa_cinsi_adi,
           kc.unit_dara_kg  AS kasa_cinsi_kg,
           pt.name          AS palet_tipi_adi,
           pt.unit_dara_kg  AS palet_tipi_kg
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = :r
    ORDER BY p.sira_no, p.id
");
$st->execute([':r' => $id]);
$pallets = $st->fetchAll();

// ── Ek malzemeler ─────────────────────────────────────────
$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name,
           m.type AS material_type, m.unit_dara_kg AS material_unit
    FROM pallet_materials pm
    JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = :p
");
foreach ($pallets as &$p) {
    $st_pm->execute([':p' => $p['id']]);
    $p['materials'] = $st_pm->fetchAll();
}
unset($p);

// ── Toplamlar (helpers.php record_totals) ────────────────
$tot = record_totals($id);

// ── Malzeme tanımları ─────────────────────────────────────
$all_defs    = db()->query("SELECT id, type, name, unit_dara_kg FROM material_definitions ORDER BY type, name")->fetchAll();
$defs_by_id  = [];
foreach ($all_defs as $d) $defs_by_id[(int)$d['id']] = $d;

// ── Yardımcı: Türkçe-güvenli normalizasyon ───────────────
function lp_tr_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace("\xCC\x87", '', $s);
    $s = strtr($s, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o']);
    return str_replace([' ', '-', '.', ','], '', $s);
}

// ── Stok kullanım hesabı (record_view.php ile birebir aynı mantık) ──
$stok_use = [];

foreach ($pallets as $p) {
    // 1) Kasa cinsi: kasa_adeti kadar kullanım
    if ($p['kasa_cinsi_id']) {
        $kid = (int)$p['kasa_cinsi_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += (int)$p['kasa_adeti'];
        $stok_use[$kid]['kg']   += (int)$p['kasa_adeti'] * (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
    }
    // 2) Palet tipi: her palet 1 adet
    if ($p['palet_tipi_id']) {
        $kid = (int)$p['palet_tipi_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += 1;
        $stok_use[$kid]['kg']   += (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
    }
    // 3) Ek malzemeler
    foreach ($p['materials'] as $m) {
        $kid   = (int)$m['def_id'];
        $basis = material_calc_basis($m['material_type'], $m['material_name']);
        $eff   = ($basis === 'kasa')
            ? (float)$m['quantity'] * (int)$p['kasa_adeti']
            : (float)$m['quantity'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += $eff;
        $stok_use[$kid]['kg']   += (float)$m['material_unit'] * $eff;
    }
}

// ── Stok satırı hesapla ──────────────────────────────────
function lp_stok_satir_topla(array $stok_use, array $defs_by_id, array $allowed_types, ?string $name_match): array {
    $adet = 0.0; $kg = 0.0;
    foreach ($stok_use as $def_id => $u) {
        $d = $defs_by_id[$def_id] ?? null;
        if (!$d) continue;
        if (!in_array($d['type'], $allowed_types)) continue;
        if ($name_match !== null) {
            if (strpos(lp_tr_norm($d['name']), lp_tr_norm($name_match)) === false) continue;
        }
        $adet += $u['adet'];
        $kg   += $u['kg'];
    }
    return ['adet' => $adet, 'kg' => $kg];
}

// ── Stok satır tanımları (record_view.php ile aynı) ──────
$stok_rows = [
    ['İHRACAT PALETİ',     ['palet_tipi'],    null],
    ['ŞAPKA',              ['sapka'],         null],
    ['KÖŞEBENT',           ['kosebent'],      null],
    ['ŞERİT',              ['serit'],         null],
    ['CASUS',              ['casus'],         null],
    ['PLS KASA H-9 YEŞİL', ['kasa_cinsi'],    'h-9 yeşil'],
    ['PLS KASA H-9 MAVİ',  ['kasa_cinsi'],    'h-9 mavi'],
    ['PLS KASA H-5 SİYAH', ['kasa_cinsi'],    'h-5 siyah'],
    ['PLS KASA H-5 MAVİ',  ['kasa_cinsi'],    'h-5 mavi'],
    ['PLS KASA H-8,5 MAVİ',['kasa_cinsi'],    'h-8,5 mavi'],
    ['PLS KASA H-8,5 YEŞİL',['kasa_cinsi'],   'h-8,5 yeşil'],
    ['PLS KASA H-10 BEYAZ',['kasa_cinsi'],    'h-10 beyaz'],
    ['PLS KASA H-9,5 MAVİ',['kasa_cinsi'],    'h-9,5 mavi'],
    ['PLS KASA H-9,5 YEŞİL',['kasa_cinsi'],   'h-9,5 yeşil'],
    ['KASA ETİKETİ',       ['kasa_etiketi'],  null],
    ['MİNTİ',              ['minti'],         null],
    ['KENAR KARTONU',      ['kenar_kartonu'], null],
    ['TABAN KAĞIDI',       ['taban_kagidi'],  null],
    ['ŞALE',               ['sale'],          null],
    ['VİYOL',              ['viyol'],         null],
    ['KÖŞE KARTON',        ['kose_karton'],   null],
    ['KRAF KAĞIT',         ['kraft_kagit'],   null],
    ['FİLE',               ['file'],          null],
];

// ── Kasa cinsi dağılımı ───────────────────────────────────
$kasa_breakdown = [];
foreach ($pallets as $p) {
    if (!(int)$p['kasa_adeti']) continue;
    $name = trim((string)($p['kasa_cinsi_adi'] ?? '')) ?: '—';
    $kasa_breakdown[$name] = ($kasa_breakdown[$name] ?? 0) + (int)$p['kasa_adeti'];
}

// ── Kasa cinsi dara dağılımı ─────────────────────────────
$kasa_dara_breakdown = [];
foreach ($stok_use as $def_id => $u) {
    $d = $defs_by_id[$def_id] ?? null;
    if (!$d || $d['type'] !== 'kasa_cinsi') continue;
    $kasa_dara_breakdown[] = [
        'name'    => $d['name'],
        'adet'    => (int)$u['adet'],
        'kg'      => (float)$u['kg'],
        'unit_kg' => (float)($d['unit_dara_kg'] ?? 0),
    ];
}

// ── Palet tipi dağılımı ───────────────────────────────────
$palet_tipi_breakdown = [];
foreach ($pallets as $p) {
    if (empty($p['palet_tipi_id'])) continue;
    $name = trim((string)($p['palet_tipi_adi'] ?? '')) ?: '—';
    $palet_tipi_breakdown[$name] = ($palet_tipi_breakdown[$name] ?? 0) + 1;
}

// ── Palet+şapka+köşebent dara (alt tablo için) ───────────
$palet_sapka_kose_dara = 0.0;
foreach ($pallets as $p) {
    if ($p['palet_tipi_id']) {
        $palet_sapka_kose_dara += (float)($defs_by_id[(int)$p['palet_tipi_id']]['unit_dara_kg'] ?? 0);
    }
}

// ── Ürün cinsi grupları ───────────────────────────────────
$urun_groups = [];
foreach ($pallets as $p) {
    $u = trim($p['urun_cinsi'] ?? '');
    if ($u === '') $u = '—';
    if (!isset($urun_groups[$u])) $urun_groups[$u] = ['kasa' => 0, 'brut' => 0.0, 'dara' => 0.0, 'net' => 0.0];
    $urun_groups[$u]['kasa'] += (int)$p['kasa_adeti'];
    $urun_groups[$u]['brut'] += (float)$p['brut_kg'];
    $urun_groups[$u]['dara'] += (float)$p['dara_kg'];
    $urun_groups[$u]['net']  += (float)$p['net_kg'];
}

// ── Depo grupları ─────────────────────────────────────────
$depo_groups = [];
foreach ($pallets as $p) {
    $dep = trim($p['depo'] ?? '');
    if ($dep === '') $dep = '—';
    if (!isset($depo_groups[$dep])) $depo_groups[$dep] = ['kasa' => 0, 'net' => 0.0];
    $depo_groups[$dep]['kasa'] += (int)$p['kasa_adeti'];
    $depo_groups[$dep]['net']  += (float)$p['net_kg'];
}

// ── Brand etiketi ─────────────────────────────────────────
$_b = strtoupper(trim((string)($record['brand'] ?? '')));
$_brand_names = ['ASYA' => 'ASYA FRESH', 'URAL' => 'URAL', 'URAS' => 'URAS ENERGY', 'AGRO' => 'AGRONATURAL'];
$brand_label = $_brand_names[$_b] ?? 'ASYA FRESH';

// ── Not alanı ─────────────────────────────────────────────
$note_val = trim((string)($record['etiket'] ?? ''));

// ── Sayfa yönü ────────────────────────────────────────────
$orientation = print_orientation($mode, $mode === 'detail' ? 9 : 5);
$body_class  = print_body_class('loading', $mode, $orientation);

// ── Sayfa başlığı ─────────────────────────────────────────
$page_title = sprintf('Yükleme Planı %s — %s', $mode === 'detail' ? 'Detay' : 'Özet', h($record['firma'] ?? ''));

// ── Stok aktif satırlar (boş olmayanlar) ─────────────────
$stok_aktif = [];
foreach ($stok_rows as [$label, $allowed, $match]) {
    $r = lp_stok_satir_topla($stok_use, $defs_by_id, $allowed, $match);
    if ($r['adet'] <= 0) continue;
    $adet_str = rtrim(rtrim(number_format($r['adet'], 3, ',', '.'), '0'), ',');
    $stok_aktif[] = ['label' => $label, 'adet' => $adet_str];
}

// ──────────────────────────────────────────────────────────
// HTML ÇIKTISI
// ──────────────────────────────────────────────────────────
render_print_page_start($page_title, 'loading', $mode, $orientation);
?>
<style>
<?php if ($orientation === 'landscape'): ?>
@page { size: A4 landscape; margin: 8mm; }
<?php else: ?>
@page { size: A4 portrait; margin: 12mm; }
<?php endif; ?>

/* ── Ortak ────────────────────────────────────────── */
.lp-brand    { font-size: 11pt; font-weight: bold; letter-spacing: 1px; margin-bottom: 4px; }
.lp-title    { font-size: 9pt; color: #555; margin-bottom: 8px; font-weight: normal; }
.lp-header   { display: flex; justify-content: space-between; align-items: flex-start;
                border-bottom: 2px solid #000; padding-bottom: 5px; margin-bottom: 8px; }
.lp-header-left  { flex: 1; }
.lp-header-right { text-align: right; font-size: 7.5pt; color: #444; }
.lp-header-right div { margin-bottom: 1px; }

/* ── Info tablo ───────────────────────────────────── */
.lp-info { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 7.5pt; }
.lp-info th { background: #f0f0f0 !important; -webkit-print-color-adjust: exact;
              print-color-adjust: exact; font-weight: bold; text-align: left;
              padding: 2px 5px; border: 1px solid #ccc; white-space: nowrap; width: 90px; }
.lp-info td { padding: 2px 5px; border: 1px solid #ccc; }

/* ── Özet kutular ─────────────────────────────────── */
.lp-boxes { display: flex; gap: 6px; margin-bottom: 10px; }
.lp-box { flex: 1; border: 1.5px solid #888; border-radius: 3px;
           padding: 5px 4px; text-align: center;
           -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.lp-box-label { font-size: 6pt; text-transform: uppercase; letter-spacing: .5px; color: #555; }
.lp-box-val   { font-size: 13pt; font-weight: bold; color: #000; line-height: 1.2; }
.lp-box-unit  { font-size: 6pt; color: #666; }
.lp-box.net   { border-color: #1a56db; background: #eff6ff !important; }
.lp-box.net .lp-box-val { color: #1a56db; }

/* ── İkili sütun düzeni (summary alt bölüm) ──────── */
.lp-two-col { display: flex; gap: 10px; margin-bottom: 8px; }
.lp-two-col > * { flex: 1; }

/* ── Genel section başlıkları ─────────────────────── */
.lp-section-title { font-size: 7pt; font-weight: bold; text-transform: uppercase;
                    letter-spacing: .5px; border-bottom: 1px solid #000;
                    padding-bottom: 2px; margin: 8px 0 4px; }

/* ── Tablolar ─────────────────────────────────────── */
.lp-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-bottom: 8px; }
.lp-table th { background: #eef2f8 !important; -webkit-print-color-adjust: exact;
               print-color-adjust: exact; font-weight: bold; text-align: left;
               padding: 2px 4px; border: 1px solid #aaa; }
.lp-table td { padding: 2px 4px; border: 1px solid #ccc; vertical-align: middle; }
.lp-table .num { text-align: right; }
.lp-table tfoot td { background: #fff5cc !important; font-weight: bold;
                      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.lp-table .row-odd  { background: #fafafa !important; }

/* ── Not kutusu ───────────────────────────────────── */
.lp-note { border: 1px solid #bbb; border-radius: 3px; padding: 5px 7px;
            margin-bottom: 8px; font-size: 7.5pt; }
.lp-note-title { font-weight: bold; font-size: 7pt; text-transform: uppercase;
                  letter-spacing: .5px; margin-bottom: 3px; }

/* ── İmzalar ──────────────────────────────────────── */
.lp-sigs { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-top: 12px; }
.lp-sig  { border-top: 1px solid #555; padding-top: 4px; font-size: 7.5pt;
            text-align: center; }

/* ── Detail: üst bilgi grid ──────────────────────── */
.lp-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; margin-bottom: 8px; }

/* ── Detail: stok + kasa dara yan yana ───────────── */
.lp-bottom-row { display: flex; gap: 10px; margin-bottom: 8px; }
.lp-bottom-row > * { flex: 1; }

@media print {
    .lp-box.net { background: #eff6ff !important; }
}
</style>

<?php /* ============================================================
       ÖZET ŞABLON (mode=summary, Portrait)
       ============================================================ */ ?>
<?php if ($mode === 'summary'): ?>
<div class="print-sheet">

  <!-- Başlık -->
  <div class="lp-header">
    <div class="lp-header-left">
      <div class="lp-brand"><?= h($brand_label) ?></div>
      <div class="lp-title">YÜKLEME PLANI — ÖZET</div>
    </div>
    <div class="lp-header-right">
      <div><strong><?= h(fmt_date($record['tarih'])) ?></strong></div>
      <?php if (!empty($record['parti_no'])): ?>
      <div>Parti: <?= h($record['parti_no']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Üst bilgi tablosu: 2 kolon -->
  <table class="lp-info">
    <tr>
      <th>FİRMA</th><td><?= h($record['firma'] ?? '—') ?></td>
      <th>ALICI</th><td><?= h($record['alici'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>BÖLGE</th><td><?= h($record['bolge'] ?? '—') ?></td>
      <th>ÜRÜN</th><td><?= h($record['urun'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>TARİH</th><td><?= h(fmt_date($record['tarih'])) ?></td>
      <th>MARKA</th><td><?= h($brand_label) ?></td>
    </tr>
    <tr>
      <th>PARTİ NO</th><td><?= h($record['parti_no'] ?? '—') ?></td>
      <th>GÜMRÜK</th><td><?= h($record['gumruk'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>NAKLİYE ŞİRKETİ</th><td><?= h($record['nakliye_sirketi'] ?? '—') ?></td>
      <th>TELEFON</th><td><?= h($record['telefon'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>ŞOFÖR</th><td><?= h($record['sofor_adi'] ?? '—') ?></td>
      <th>FATURA NO</th><td><?= h($record['fatura_no'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>ÖN PLAKA</th><td><?= h($record['on_plaka'] ?? '—') ?></td>
      <th>ARKA PLAKA</th><td><?= h($record['arka_plaka'] ?? '—') ?></td>
    </tr>
  </table>

  <!-- Özet kutular: PALET / KASA / BRÜT / DARA / NET -->
  <div class="lp-boxes">
    <div class="lp-box">
      <div class="lp-box-label">Palet</div>
      <div class="lp-box-val"><?= (int)$tot['palet_count'] ?></div>
      <div class="lp-box-unit">adet</div>
    </div>
    <div class="lp-box">
      <div class="lp-box-label">Kasa</div>
      <div class="lp-box-val"><?= (int)$tot['toplam_kasa'] ?></div>
      <div class="lp-box-unit">adet</div>
    </div>
    <div class="lp-box">
      <div class="lp-box-label">Brüt KG</div>
      <div class="lp-box-val"><?= h(fmt_kg($tot['toplam_brut'])) ?></div>
      <div class="lp-box-unit">kg</div>
    </div>
    <div class="lp-box">
      <div class="lp-box-label">Dara KG</div>
      <div class="lp-box-val"><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></div>
      <div class="lp-box-unit">kg</div>
    </div>
    <div class="lp-box net">
      <div class="lp-box-label">Net KG</div>
      <div class="lp-box-val"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></div>
      <div class="lp-box-unit">kg</div>
    </div>
  </div>

  <!-- Kasa cinsi + Ürün/Depo özeti yan yana -->
  <div class="lp-two-col">

    <!-- Kasa cinsi toplamları -->
    <?php if (!empty($kasa_breakdown)): ?>
    <div>
      <div class="lp-section-title">Kasa Cinsi</div>
      <table class="lp-table">
        <thead><tr><th>Kasa Cinsi</th><th class="num">Adet</th></tr></thead>
        <tbody>
        <?php foreach ($kasa_breakdown as $kn => $ka): ?>
          <tr><td><?= h(mb_strtoupper($kn, 'UTF-8')) ?></td><td class="num"><?= (int)$ka ?></td></tr>
        <?php endforeach; ?>
        <tr style="font-weight:bold;background:#f5f5f5 !important"><td>TOPLAM</td><td class="num"><?= (int)$tot['toplam_kasa'] ?></td></tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Ürün cinsi grupları -->
    <?php if (count($urun_groups) > 1 || (count($urun_groups) === 1 && key($urun_groups) !== '—')): ?>
    <div>
      <div class="lp-section-title">Ürün Cinsi</div>
      <table class="lp-table">
        <thead><tr><th>Ürün</th><th class="num">Kasa</th><th class="num">Net KG</th></tr></thead>
        <tbody>
        <?php foreach ($urun_groups as $uk => $ug): ?>
          <tr>
            <td><?= h(mb_strtoupper($uk, 'UTF-8')) ?></td>
            <td class="num"><?= (int)$ug['kasa'] ?></td>
            <td class="num"><?= h(fmt_kg(round((float)$ug['net']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php elseif (count($depo_groups) > 1): ?>
    <!-- Depo özeti (tek ürün cinsi varsa) -->
    <div>
      <div class="lp-section-title">Depo</div>
      <table class="lp-table">
        <thead><tr><th>Depo</th><th class="num">Kasa</th><th class="num">Net KG</th></tr></thead>
        <tbody>
        <?php foreach ($depo_groups as $dk => $dg): ?>
          <tr>
            <td><?= h($dk) ?></td>
            <td class="num"><?= (int)$dg['kasa'] ?></td>
            <td class="num"><?= h(fmt_kg(round((float)$dg['net']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div></div>
    <?php endif; ?>

  </div><!-- .lp-two-col -->

  <!-- Stok çıkışları -->
  <?php if (!empty($stok_aktif)): ?>
  <div class="lp-section-title">Stok Çıkışları</div>
  <table class="lp-table">
    <thead><tr><th>Malzeme</th><th class="num">Adet</th></tr></thead>
    <tbody>
    <?php foreach ($stok_aktif as $sr): ?>
      <tr><td><?= h($sr['label']) ?></td><td class="num"><?= h($sr['adet']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Not (dolu ise) -->
  <?php if ($note_val !== ''): ?>
  <div class="lp-note">
    <div class="lp-note-title">NOT</div>
    <div><?= nl2br(h($note_val)) ?></div>
  </div>
  <?php endif; ?>

  <!-- İmzalar -->
  <div class="lp-sigs">
    <div class="lp-sig">TESLİM EDEN</div>
    <div class="lp-sig">TESLİM ALAN</div>
  </div>

</div><!-- .print-sheet -->

<?php /* ============================================================
       DETAYLI ŞABLON (mode=detail, Landscape)
       ============================================================ */ ?>
<?php else: ?>
<div class="print-sheet">

  <!-- Başlık -->
  <div class="lp-header">
    <div class="lp-header-left">
      <div class="lp-brand"><?= h($brand_label) ?></div>
      <div class="lp-title">YÜKLEME PLANI — DETAY &nbsp;|&nbsp; <?= h($record['firma'] ?? '') ?> &nbsp;|&nbsp; <?= h(fmt_date($record['tarih'])) ?></div>
    </div>
    <div class="lp-header-right">
      <?php if (!empty($record['parti_no'])): ?>
      <div>Parti: <strong><?= h($record['parti_no']) ?></strong></div>
      <?php endif; ?>
      <div>Palet: <strong><?= (int)$tot['palet_count'] ?></strong> &nbsp; Kasa: <strong><?= (int)$tot['toplam_kasa'] ?></strong></div>
      <div>Net: <strong><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?> kg</strong></div>
    </div>
  </div>

  <!-- Üst bilgi grid: 2 sütun kompakt -->
  <div class="lp-info-grid" style="margin-bottom:6px">
    <table class="lp-info" style="margin-bottom:0">
      <tr><th>FİRMA</th><td><?= h($record['firma'] ?? '—') ?></td></tr>
      <tr><th>BÖLGE</th><td><?= h($record['bolge'] ?? '—') ?></td></tr>
      <tr><th>PARTİ NO</th><td><?= h($record['parti_no'] ?? '—') ?></td></tr>
      <tr><th>GÜMRÜK</th><td><?= h($record['gumruk'] ?? '—') ?></td></tr>
      <tr><th>FATURA NO</th><td><?= h($record['fatura_no'] ?? '—') ?></td></tr>
    </table>
    <table class="lp-info" style="margin-bottom:0">
      <tr><th>ALICI</th><td><?= h($record['alici'] ?? '—') ?></td></tr>
      <tr><th>ÜRÜN</th><td><?= h($record['urun'] ?? '—') ?></td></tr>
      <tr><th>ŞOFÖR</th><td><?= h($record['sofor_adi'] ?? '—') ?></td></tr>
      <tr><th>ÖN PLAKA</th><td><?= h($record['on_plaka'] ?? '—') ?></td></tr>
      <tr><th>ARKA PLAKA</th><td><?= h($record['arka_plaka'] ?? '—') ?></td></tr>
    </table>
  </div>

  <!-- Palet detay tablosu — TÜM PALETLER (26 sınırı YOK) -->
  <table class="lp-table" style="font-size:7pt">
    <thead>
      <tr>
        <th style="width:28px">#</th>
        <th style="width:28px">Palet<br>No</th>
        <th class="num" style="width:38px">Kasa<br>Adeti</th>
        <th style="width:36px">Size</th>
        <th class="num" style="width:52px">Brüt KG</th>
        <th class="num" style="width:52px">Dara KG</th>
        <th class="num" style="width:52px">Net KG</th>
        <th>Kasa Cinsi</th>
        <th>Ürün Cinsi</th>
        <th>Depo</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($pallets)): ?>
      <tr><td colspan="10" style="text-align:center;color:#999">Palet kaydı yok.</td></tr>
    <?php endif; ?>
    <?php foreach ($pallets as $i => $p): ?>
      <tr class="<?= ($i % 2 === 1) ? 'row-odd' : '' ?> print-avoid-break">
        <td style="color:#999;text-align:center"><?= $i + 1 ?></td>
        <td><?= h($p['palet_no'] ?? '') ?></td>
        <td class="num"><?= (int)$p['kasa_adeti'] ?></td>
        <td><?= h($p['size'] ?? '') ?></td>
        <td class="num"><?= h(fmt_kg($p['brut_kg'])) ?></td>
        <td class="num"><?= h(fmt_kg($p['dara_kg'])) ?></td>
        <td class="num" style="font-weight:600"><?= h(fmt_kg($p['net_kg'])) ?></td>
        <td><?= h($p['kasa_cinsi_adi'] ?? '—') ?></td>
        <td><?= h($p['urun_cinsi'] ?? '—') ?></td>
        <td><?= h($p['depo'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2" style="text-align:right;font-weight:bold">TOPLAM</td>
        <td class="num"><?= (int)$tot['toplam_kasa'] ?></td>
        <td></td>
        <td class="num"><?= h(fmt_kg($tot['toplam_brut'])) ?></td>
        <td class="num"><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></td>
        <td class="num" style="font-weight:bold"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></td>
        <td colspan="3"></td>
      </tr>
    </tfoot>
  </table>

  <!-- Alt bölüm: Stok çıkışları + Kasa dara dağılımı + Özet yan yana -->
  <div class="lp-bottom-row">

    <!-- Stok çıkışları -->
    <?php if (!empty($stok_aktif)): ?>
    <div style="flex:1.2">
      <div class="lp-section-title">Stok Çıkışları</div>
      <table class="lp-table" style="font-size:7pt">
        <thead><tr><th>Malzeme</th><th class="num">Adet</th></tr></thead>
        <tbody>
        <?php foreach ($stok_aktif as $sr): ?>
          <tr><td><?= h($sr['label']) ?></td><td class="num"><?= h($sr['adet']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Kasa cinsi dara dağılımı -->
    <?php if (!empty($kasa_dara_breakdown)): ?>
    <div style="flex:1.2">
      <div class="lp-section-title">Kasa Cinsi Dara</div>
      <table class="lp-table" style="font-size:7pt">
        <thead><tr><th>Kasa Cinsi</th><th class="num">Adet</th><th class="num">Toplam KG</th></tr></thead>
        <tbody>
        <?php foreach ($kasa_dara_breakdown as $k): ?>
          <tr>
            <td><?= h(mb_strtoupper($k['name'], 'UTF-8')) ?><?= $k['unit_kg'] > 0 ? ' (' . h(fmt_unit_kg($k['unit_kg'])) . ' kg)' : '' ?></td>
            <td class="num"><?= (int)$k['adet'] ?></td>
            <td class="num"><?= h(fmt_kg(round((float)$k['kg']))) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($palet_sapka_kose_dara > 0): ?>
          <tr><td>PALET / ŞAPKA / KÖŞEBENT</td><td class="num">—</td><td class="num"><?= h(fmt_kg(round($palet_sapka_kose_dara))) ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Ürün cinsi özet -->
    <?php if (!empty($urun_groups)): ?>
    <div style="flex:1.2">
      <div class="lp-section-title">Ürün / Depo Özeti</div>
      <table class="lp-table" style="font-size:7pt">
        <thead><tr><th>Ürün Cinsi</th><th class="num">Kasa</th><th class="num">Net KG</th></tr></thead>
        <tbody>
        <?php foreach ($urun_groups as $uk => $ug): ?>
          <tr>
            <td><?= h(mb_strtoupper($uk, 'UTF-8')) ?></td>
            <td class="num"><?= (int)$ug['kasa'] ?></td>
            <td class="num"><?= h(fmt_kg(round((float)$ug['net']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div><!-- .lp-bottom-row -->

  <!-- Not (dolu ise) -->
  <?php if ($note_val !== ''): ?>
  <div class="lp-note print-avoid-break">
    <div class="lp-note-title">NOT</div>
    <div><?= nl2br(h($note_val)) ?></div>
  </div>
  <?php endif; ?>

  <!-- İmzalar -->
  <div class="lp-sigs">
    <div class="lp-sig">TESLİM EDEN</div>
    <div class="lp-sig">TESLİM ALAN</div>
  </div>

</div><!-- .print-sheet -->
<?php endif; /* summary / detail */ ?>

<?php render_print_page_end(true); ?>
