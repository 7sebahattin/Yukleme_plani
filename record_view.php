<?php
// =========================================================
// record_view.php (v3)
// - Yazdırma modu (?print=1 veya yazdırma): ASYA FRESH şablonu
// - Ekran modu: mobil uyumlu kart + PC tablo
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = (int)($_GET['id'] ?? 0);
$print = !empty($_GET['print']);
if ($id <= 0) { set_flash('error', 'Geçersiz kayıt.'); header('Location: index.php'); exit; }

$st = db()->prepare("SELECT * FROM loading_records WHERE id=:id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) { set_flash('error', 'Kayıt bulunamadı.'); header('Location: index.php'); exit; }

$st = db()->prepare("
    SELECT p.*,
           kc.name AS kasa_cinsi_adi,
           kc.unit_dara_kg AS kasa_cinsi_kg,
           pt.name AS palet_tipi_adi,
           pt.unit_dara_kg AS palet_tipi_kg
    FROM loading_pallets p
    LEFT JOIN material_definitions kc ON kc.id = p.kasa_cinsi_id
    LEFT JOIN material_definitions pt ON pt.id = p.palet_tipi_id
    WHERE p.loading_record_id = :r
    ORDER BY p.sira_no, p.id
");
$st->execute([':r' => $id]);
$pallets = $st->fetchAll();

$st_pm = db()->prepare("
    SELECT pm.*, m.id AS def_id, m.name AS material_name, m.type AS material_type, m.unit_dara_kg AS material_unit
    FROM pallet_materials pm
    JOIN material_definitions m ON m.id = pm.material_id
    WHERE pm.loading_pallet_id = :p
");
foreach ($pallets as &$p) {
    $st_pm->execute([':p' => $p['id']]);
    $p['materials'] = $st_pm->fetchAll();
}
unset($p);

$tot = record_totals($id);
$type_labels = definition_types();

// Kasa cinsi dağılımı (toplam kasa yanında göstermek için)
$kasa_breakdown = [];
foreach ($pallets as $p) {
    if (!(int)$p['kasa_adeti']) continue;
    $name = trim((string)($p['kasa_cinsi_adi'] ?? '')) ?: '—';
    $kasa_breakdown[$name] = ($kasa_breakdown[$name] ?? 0) + (int)$p['kasa_adeti'];
}

function kasa_label(?string $name, $kg): string {
    if (!$name) return '—';
    return $name . ' (' . fmt_kg($kg) . ' kg)';
}

// ====== Stok çıkışları (yazdırma şablonu için) ======
// Tüm tanımları çek, yazdırmada görünecek malzeme adetlerini hesapla
$all_defs = db()->query("SELECT id, type, name, unit_dara_kg FROM material_definitions
                         ORDER BY type, name")->fetchAll();
$defs_by_id = [];
foreach ($all_defs as $d) $defs_by_id[(int)$d['id']] = $d;

// stok_use[def_id] = ['adet'=>x, 'kg'=>y]
$stok_use = [];

// 1) Kasa cinsi: her palette kasa_adeti kadar kullanılır
foreach ($pallets as $p) {
    if ($p['kasa_cinsi_id']) {
        $kid = (int)$p['kasa_cinsi_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += (int)$p['kasa_adeti'];
        $stok_use[$kid]['kg']   += (int)$p['kasa_adeti'] * (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
    }
    // 2) Palet tipi: her palette 1 adet
    if ($p['palet_tipi_id']) {
        $kid = (int)$p['palet_tipi_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += 1;
        $stok_use[$kid]['kg']   += (float)($defs_by_id[$kid]['unit_dara_kg'] ?? 0);
    }
    // 3) Ek malzemeler: pallet_materials.quantity
    foreach ($p['materials'] as $m) {
        $kid = (int)$m['def_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += (float)$m['quantity'];
        $stok_use[$kid]['kg']   += (float)$m['total_dara_kg'];
    }
}

// Kasa darası alt-toplamı (palet/şapka/köşe için sabit alan; isimde geçen "H-5", "H-9", "H-10" vs için
// kasa cinsi adına göre eşleştirme yapıyoruz)
function tr_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    // Türkçe büyük İ → mb_strtolower ile "i̇" (i + combining dot above U+0307) olur, dot'u temizle
    $s = str_replace("\xCC\x87", '', $s);
    // i ↔ ı, ş→s, ç→c, ğ→g, ü→u, ö→o farkını da yumuşat
    $s = strtr($s, ['ı'=>'i','ş'=>'s','ç'=>'c','ğ'=>'g','ü'=>'u','ö'=>'o']);
    return str_replace([' ', '-', '.', ','], '', $s);
}
function kasa_dara_for_label(string $needle, array $pallets, array $defs_by_id): float {
    $sum = 0.0;
    $needle_norm = tr_norm($needle);
    foreach ($pallets as $p) {
        if (!$p['kasa_cinsi_id']) continue;
        $name = $defs_by_id[(int)$p['kasa_cinsi_id']]['name'] ?? '';
        $name_norm = tr_norm($name);
        if (strpos($name_norm, $needle_norm) !== false) {
            $sum += (int)$p['kasa_adeti'] * (float)$defs_by_id[(int)$p['kasa_cinsi_id']]['unit_dara_kg'];
        }
    }
    return $sum;
}

// Palet+Şapka+Köşebent dara toplamı
$palet_sapka_kose_dara = 0.0;
foreach ($pallets as $p) {
    if ($p['palet_tipi_id']) {
        $palet_sapka_kose_dara += (float)($defs_by_id[(int)$p['palet_tipi_id']]['unit_dara_kg'] ?? 0);
    }
    foreach ($p['materials'] as $m) {
        if (in_array($m['material_type'], ['sapka', 'kosebent'])) {
            $palet_sapka_kose_dara += (float)$m['total_dara_kg'];
        }
    }
}

// Alt blok: palet tipi adını ve adetini dinamik oluştur
$palet_tipi_names = [];
$palet_tipi_total_adet = 0;
foreach ($stok_use as $def_id => $u) {
    $d = $defs_by_id[$def_id] ?? null;
    if ($d && $d['type'] === 'palet_tipi') {
        $palet_tipi_names[] = h(mb_strtoupper($d['name'], 'UTF-8'));
        $palet_tipi_total_adet += (int)$u['adet'];
    }
}
$palet_bottom_label = !empty($palet_tipi_names)
    ? implode(' / ', array_unique($palet_tipi_names)) . '<br><small style="font-size:6pt;font-weight:normal">' . $palet_tipi_total_adet . ' ADET</small>'
    : 'PALET';

// 26 satır için pallets'i doldur (boş satırlar için null bırak)
$grid_rows = 26;
$pallets_grid = [];
for ($i = 0; $i < $grid_rows; $i++) {
    $pallets_grid[$i] = $pallets[$i] ?? null;
}

// ====== Stok çıkışları satırları (yazdırma şablonundaki sıraya göre) ======
$stok_rows = [
    ['ihracat_paleti',  'İHRACAT PALETİ',  ['palet_tipi'], 'ihracat'],
    ['sapka',           'ŞAPKA',           ['sapka'], null],
    ['kosebent',        'KÖŞEBENT',        ['kosebent'], null],
    ['serit',           'ŞERİT',           ['serit'], null],
    ['casus',           'CASUS',           ['casus'], null],
    // Plastik kasalar — kasa cinsi adına göre eşleşir
    ['kasa_h9_yesil',   'PLS KASA H-9 YEŞİL',   ['kasa_cinsi'], 'h-9 yeşil'],
    ['kasa_h9_mavi',    'PLS KASA H-9 MAVİ',    ['kasa_cinsi'], 'h-9 mavi'],
    ['kasa_h5_siyah',   'PLS KASA H-5 SİYAH',   ['kasa_cinsi'], 'h-5 siyah'],
    ['kasa_h5_mavi',    'PLS KASA H-5 MAVİ',    ['kasa_cinsi'], 'h-5 mavi'],
    ['kasa_h85_mavi',   'PLS KASA H-8,5 MAVİ',  ['kasa_cinsi'], 'h-8,5 mavi'],
    ['kasa_h85_yesil',  'PLS KASA H-8,5 YEŞİL', ['kasa_cinsi'], 'h-8,5 yeşil'],
    ['kasa_h10_beyaz',  'PLS KASA H-10 BEYAZ',  ['kasa_cinsi'], 'h-10 beyaz'],
    ['kasa_h95_mavi',   'PLS KASA H-9,5 MAVİ',  ['kasa_cinsi'], 'h-9,5 mavi'],
    ['kasa_h95_yesil',  'PLS KASA H-9,5 YEŞİL', ['kasa_cinsi'], 'h-9,5 yeşil'],
    ['kasa_etiketi',    'KASA ETİKETİ',    ['kasa_etiketi'], null],
    ['minti',           'MİNTİ',           ['minti'], null],
    ['kenar_kartonu',   'KENAR KARTONU',   ['kenar_kartonu'], null],
    ['taban_kagidi',    'TABAN KAĞIDI',    ['taban_kagidi'], null],
    ['sale',            'ŞALE',            ['sale'], null],
    ['viyol',           'VİYOL',           ['viyol'], null],
    ['kose_karton',     'KÖŞE KARTON',     ['kose_karton'], null],
    ['kraf_kagit',      'KRAF KAĞIT',      ['kraft_kagit'], null],
    ['file',            'FİLE',            ['file'], null],
];

// Bir stok satırı için adet/kg hesapla
function stok_satir_topla(array $stok_use, array $defs_by_id, array $allowed_types, ?string $name_match): array {
    $adet = 0.0; $kg = 0.0;
    foreach ($stok_use as $def_id => $u) {
        $d = $defs_by_id[$def_id] ?? null;
        if (!$d) continue;
        if (!in_array($d['type'], $allowed_types)) continue;
        if ($name_match) {
            $nm_norm = tr_norm($d['name']);
            $needle_norm = tr_norm($name_match);
            if (strpos($nm_norm, $needle_norm) === false) continue;
        }
        $adet += $u['adet'];
        $kg   += $u['kg'];
    }
    return ['adet' => $adet, 'kg' => $kg];
}

// Bottom-row özel kasa dara toplamları (artık kullanılmıyor, dinamik yapıya geçildi)
// Eski statik hesaplamalar kaldırıldı

// Dinamik kasa cinsi dara dağılımı — yazdırma alt bloku için
$kasa_dara_breakdown = [];
foreach ($stok_use as $def_id => $u) {
    $d = $defs_by_id[$def_id] ?? null;
    if (!$d || $d['type'] !== 'kasa_cinsi') continue;
    $kasa_dara_breakdown[] = [
        'name' => $d['name'],
        'adet' => (int)$u['adet'],
        'kg'   => (float)$u['kg'],
    ];
}

// Palet+Şapka+Köşebent dara toplamı (sabit sütun, korunuyor)
$urun_groups = [];
foreach ($pallets as $p) {
    $u = trim($p['urun_cinsi'] ?? '');
    if ($u === '') $u = '—';
    if (!isset($urun_groups[$u])) $urun_groups[$u] = ['kasa'=>0,'brut'=>0,'dara'=>0,'net'=>0];
    $urun_groups[$u]['kasa'] += (int)$p['kasa_adeti'];
    $urun_groups[$u]['brut'] += (float)$p['brut_kg'];
    $urun_groups[$u]['dara'] += (float)$p['dara_kg'];
    $urun_groups[$u]['net']  += (float)$p['net_kg'];
}
$urun_keys = array_slice(array_keys($urun_groups), 0, 4);

render_header(h($record['firma'] ?? 'Kayıt'), $print);
?>

<?php if (!$print): ?>
<?php render_flash(); ?>
<div class="page-head">
    <div></div>
    <div class="page-head-actions">
        <button id="kalanOpenBtn" class="btn">Kalan Palet Hesapla</button>
        <a href="index.php" class="btn btn-ghost">← Liste</a>
        <a href="record_edit.php?id=<?= (int)$id ?>" class="btn">Düzenle</a>
        <a href="record_view.php?id=<?= (int)$id ?>&print=1" class="btn btn-primary" target="_blank">Yazdır</a>
    </div>
</div>
<?php else: ?>
<div class="print-actions no-print">
    <button onclick="window.print()" class="btn btn-primary">Yazdır</button>
    <a href="record_view.php?id=<?= (int)$id ?>" class="btn btn-ghost">Geri</a>
</div>
<?php endif; ?>

<?php if (!$print): ?>
<!-- ============================================================
     EKRAN MODU: mobil uyumlu özet (yazdırma değil)
     ============================================================ -->
<section class="print-sheet view-sheet">

<div class="print-header">
    <div class="ph-left">
        <div class="ph-title">YÜKLEME PLANI</div>
        <div class="ph-sub">Kayıt #<?= (int)$record['id'] ?></div>
    </div>
    <div class="ph-right">
        <div><strong>Tarih:</strong> <?= h(fmt_date($record['tarih'])) ?></div>
        <div><strong>Parti No:</strong> <?= h($record['parti_no']) ?></div>
    </div>
</div>

<div class="info-grid">
    <div><span class="lbl">Firma</span><strong><?= h($record['firma']) ?></strong></div>
    <div><span class="lbl">Bölge</span><strong><?= h($record['bolge']) ?></strong></div>
    <div><span class="lbl">Alıcı</span><strong><?= h($record['alici']) ?></strong></div>
    <div><span class="lbl">Ürün</span><strong><?= h($record['urun']) ?></strong></div>
    <div><span class="lbl">Etiket / Marka</span><strong><?= h($record['etiket']) ?></strong></div>
    <div><span class="lbl">Gümrük</span><strong><?= h($record['gumruk']) ?></strong></div>
    <div><span class="lbl">Fatura No</span><strong><?= h($record['fatura_no']) ?></strong></div>
    <div><span class="lbl">Casus No</span><strong><?= h($record['casus_no']) ?></strong></div>
    <div><span class="lbl">Nakliye Şirketi</span><strong><?= h($record['nakliye_sirketi']) ?></strong></div>
    <div><span class="lbl">Şoför</span><strong><?= h($record['sofor_adi']) ?></strong></div>
    <div><span class="lbl">Telefon</span><strong><?= h($record['telefon']) ?></strong></div>
    <div><span class="lbl">Plaka</span><strong><?= h(trim($record['on_plaka'] . ' / ' . $record['arka_plaka'], ' /')) ?></strong></div>
    <div><span class="lbl">Nakliye Bedeli</span><strong><?= h(fmt_money($record['nakliye_bedeli'])) ?></strong></div>
    <div><span class="lbl">Avans</span><strong><?= h(fmt_money($record['avans'])) ?></strong></div>
</div>

<h3 class="section-title">Yükleme Planı</h3>

<!-- PC tablo -->
<div class="view-table-wrap">
    <table class="print-table view-table">
        <thead>
        <tr>
            <th>Palet No</th>
            <th class="num">Kasa</th>
            <th>Size</th>
            <th>Kasa Cinsi</th>
            <th>Palet Tipi</th>
            <th>Ürün Cinsi</th>
            <th>Depo</th>
            <th class="num">Brüt KG</th>
            <th class="num">Dara KG</th>
            <th class="num">Net KG</th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($pallets)): ?>
            <tr><td colspan="10" class="muted center">Palet kaydı yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($pallets as $p): ?>
            <tr>
                <td><?= h($p['palet_no']) ?></td>
                <td class="num"><?= (int)$p['kasa_adeti'] ?></td>
                <td><?= h($p['size']) ?></td>
                <td><?= h(kasa_label($p['kasa_cinsi_adi'], $p['kasa_cinsi_kg'])) ?></td>
                <td><?= h(kasa_label($p['palet_tipi_adi'], $p['palet_tipi_kg'])) ?></td>
                <td><?= h($p['urun_cinsi']) ?></td>
                <td><?= h($p['depo']) ?></td>
                <td class="num"><?= h(fmt_kg($p['brut_kg'])) ?></td>
                <td class="num"><?= h(fmt_kg(round_half((float)$p['dara_kg']))) ?></td>
                <td class="num strong"><?= h(fmt_kg($p['net_kg'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr class="totals-row">
            <td class="right strong">TOPLAM</td>
            <td class="num strong">
                <?= (int)$tot['toplam_kasa'] ?>
                <?php if (!empty($kasa_breakdown)): ?>
                <div style="font-size:.72rem;font-weight:normal;margin-top:3px;line-height:1.5;white-space:nowrap">
                    <?php foreach ($kasa_breakdown as $name => $adet): ?>
                    <div><?= h($name) ?>: <b><?= $adet ?></b></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </td>
            <td colspan="5"></td>
            <td class="num strong"><?= h(fmt_kg($tot['toplam_brut'])) ?></td>
            <td class="num strong"><?= h(fmt_kg(round_half((float)$tot['toplam_dara']))) ?></td>
            <td class="num strong"><?= h(fmt_kg($tot['toplam_net'])) ?></td>
        </tr>
        </tfoot>
    </table>
</div>

<!-- Mobil kart -->
<div class="view-cards mobile-only">
    <?php if (empty($pallets)): ?>
        <p class="muted">Palet kaydı yok.</p>
    <?php endif; ?>
    <?php foreach ($pallets as $p): ?>
        <div class="view-card">
            <div class="vc-head">
                <div class="vc-no">Palet <?= h($p['palet_no']) ?></div>
                <div class="vc-kasa"><?= (int)$p['kasa_adeti'] ?> kasa</div>
            </div>
            <div class="vc-grid">
                <div><span>Size</span><strong><?= h($p['size'] ?: '—') ?></strong></div>
                <div><span>Ürün Cinsi</span><strong><?= h($p['urun_cinsi'] ?: '—') ?></strong></div>
                <div class="span-2"><span>Kasa Cinsi</span><strong><?= h(kasa_label($p['kasa_cinsi_adi'], $p['kasa_cinsi_kg'])) ?></strong></div>
                <div class="span-2"><span>Palet Tipi</span><strong><?= h(kasa_label($p['palet_tipi_adi'], $p['palet_tipi_kg'])) ?></strong></div>
                <div><span>Depo</span><strong><?= h($p['depo'] ?: '—') ?></strong></div>
            </div>
            <?php if (!empty($p['materials'])): ?>
                <div class="vc-materials">
                    <div class="vc-mat-title">Ekstra Dara Kalemleri</div>
                    <?php foreach ($p['materials'] as $m): ?>
                        <div class="vc-mat">
                            <span><?= h(($type_labels[$m['material_type']] ?? '') . ' / ' . $m['material_name']) ?>
                                  (<?= h(fmt_kg($m['material_unit'])) ?> kg) × <?= h(fmt_kg($m['quantity'])) ?></span>
                            <strong><?= h(fmt_kg($m['total_dara_kg'])) ?> kg</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="vc-totals">
                <div><span>Brüt</span><strong><?= h(fmt_kg($p['brut_kg'])) ?></strong></div>
                <div><span>Dara</span><strong><?= h(fmt_kg(round_half((float)$p['dara_kg']))) ?></strong></div>
                <div><span>Net</span><strong class="strong"><?= h(fmt_kg($p['net_kg'])) ?></strong></div>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="totals view-totals-mobile">
        <div>
            <span>Toplam Kasa</span>
            <strong><?= (int)$tot['toplam_kasa'] ?></strong>
            <?php if (!empty($kasa_breakdown)): ?>
            <div style="font-size:.75rem;font-weight:normal;margin-top:2px;line-height:1.5">
                <?php foreach ($kasa_breakdown as $name => $adet): ?>
                <div><?= h($name) ?>: <b><?= $adet ?></b></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div><span>Toplam Brüt</span><strong><?= h(fmt_kg($tot['toplam_brut'])) ?></strong></div>
        <div><span>Toplam Dara</span><strong><?= h(fmt_kg(round_half((float)$tot['toplam_dara']))) ?></strong></div>
        <div><span>Toplam Net</span><strong class="strong"><?= h(fmt_kg($tot['toplam_net'])) ?></strong></div>
    </div>
</div>
</section>

<?php else: ?>
<!-- ============================================================
     YAZDIRMA MODU: ASYA FRESH şablonu
     ============================================================ -->
<div class="asya-sheet">

    <!-- ============= ÜST BLOK: Genel bilgiler + ETİKET + Marka ============= -->
    <div class="asya-top">
        <div class="asya-brand-full">ASYA FRESH</div>
        <div class="asya-top-body">
            <!-- Sol: Genel bilgi alanları -->
            <table class="asya-info">
                <tr><th>FİRMA</th><td colspan="3"><?= h($record['firma']) ?></td></tr>
                <tr><th>BÖLGE</th><td colspan="3"><?= h($record['bolge']) ?></td></tr>
                <tr><th>PARTİ NO</th><td colspan="3"><?= h($record['parti_no']) ?></td></tr>
                <tr><th>GÜMRÜK</th><td colspan="3"><?= h($record['gumruk']) ?></td></tr>
                <tr>
                    <th>NAKLİYE BEDELİ</th>
                    <td><?= h($record['nakliye_bedeli'] > 0 ? fmt_money($record['nakliye_bedeli']) : '') ?></td>
                    <th class="avans-th">AVANS</th>
                    <td class="avans-td"><?= h($record['avans'] > 0 ? fmt_money($record['avans']) : '') ?></td>
                </tr>
                <tr><th>ŞOFÖR ADI</th><td colspan="3"><?= h($record['sofor_adi']) ?></td></tr>
                <tr><th>FATURA NO</th><td colspan="3"><?= h($record['fatura_no']) ?></td></tr>
                <tr><th>CASUS NO</th><td colspan="3"><?= h($record['casus_no']) ?></td></tr>
                <tr><th>ÖN PLAKA NO</th><td colspan="3"><?= h($record['on_plaka']) ?></td></tr>
                <tr><th>ARKA PLAKA NO</th><td colspan="3"><?= h($record['arka_plaka']) ?></td></tr>
                <tr><th>NAKLİYE ŞİRKETİ</th><td colspan="3"><?= h($record['nakliye_sirketi']) ?></td></tr>
                <tr><th>TELEFON</th><td colspan="3"><?= h($record['telefon']) ?></td></tr>
                <tr><th>TARİH</th><td colspan="3"><?= h(fmt_date($record['tarih'])) ?></td></tr>
            </table>

            <!-- Sağ: MARKA + ALICI + ÜRÜN + ETİKET -->
            <div class="asya-right-top">
                <div class="asya-marka-row">
                    <div class="marka-cell"><span>ASYA</span><strong>MARKA</strong></div>
                    <div class="marka-cell"><span>URAL</span><strong>MARKA</strong></div>
                    <div class="marka-cell"><span>URAS</span><strong>MARKA</strong></div>
                </div>
                <div class="cell-pair asya-alici-cell">
                    <span>ALICI</span>
                    <strong><?= h($record['alici']) ?></strong>
                </div>
                <div class="cell-pair asya-urun-cell">
                    <span>ÜRÜN</span>
                    <strong><?= h($record['urun']) ?></strong>
                </div>
                <div class="asya-etiket">
                    <img id="etiketImg" class="etiket-img" alt="" style="display:none">
                    <div id="etiketPlaceholder" class="etiket-placeholder">ETİKET</div>
                    <div class="etiket-actions no-print">
                        <label class="etiket-upload-btn">
                            📷 Fotoğraf Ekle
                            <input type="file" id="etiketInput" accept="image/*" capture="environment" style="display:none">
                        </label>
                        <button id="etiketClear" class="etiket-clear-btn" style="display:none">✕</button>
                    </div>
                </div>
            </div>
        </div><!-- .asya-top-body -->
    </div><!-- .asya-top -->

    <!-- ============= ORTA BLOK: Yükleme planı tablosu + Stok çıkışları ============= -->
    <div class="asya-middle">
        <!-- Sol: 26 satırlık palet tablosu -->
        <table class="asya-pallets">
            <thead>
            <tr>
                <th class="th-banner" colspan="8">YÜKLEME PLANI</th>
            </tr>
            <tr>
                <th class="w-no">PALET<br>NO</th>
                <th class="w-kasa">KASA<br>ADETİ</th>
                <th class="w-size">SIZE</th>
                <th class="w-brut">PALET BRÜT<br>AĞIRLIK</th>
                <th class="w-net">NET KG</th>
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
                    <td class="num"><?= $p ? h(fmt_kg($p['net_kg'])) : '' ?></td>
                    <td class="small"><?= $p ? h($p['kasa_cinsi_adi']) : '' ?></td>
                    <td class="small"><?= $p ? h($p['urun_cinsi']) : '' ?></td>
                    <td class="small"><?= $p ? h($p['depo']) : '' ?></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <!-- Sağ: Stok Çıkışları + Genel Toplam + Ürün toplamları -->
        <div class="asya-right-mid">
            <table class="asya-stok">
                <thead>
                <tr><th class="th-banner" colspan="3">STOK ÇIKIŞLARI</th></tr>
                <tr>
                    <th class="stok-name"></th>
                    <th class="stok-adet">ADET / KG</th>
                    <th class="stok-empty"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stok_rows as $sr):
                    [$key, $label, $allowed, $match] = $sr;
                    $r = stok_satir_topla($stok_use, $defs_by_id, $allowed, $match);
                    $adet_str = $r['adet'] > 0 ? rtrim(rtrim(number_format($r['adet'], 3, ',', '.'), '0'), ',') : '';
                ?>
                    <tr>
                        <td class="stok-name"><?= h($label) ?></td>
                        <td class="stok-val"><?= h($adet_str) ?></td>
                        <td class="stok-val"><?= $r['kg'] > 0 ? h(fmt_kg(round_half($r['kg']))) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Genel Toplam + Ürün/1..4 -->
            <table class="asya-totals">
                <tr><th class="th-banner" colspan="2">GENEL TOPLAM</th></tr>
                <tr><th>KASA</th><td class="num"><?= (int)$tot['toplam_kasa'] ?></td></tr>
                <tr><th>BRÜT</th><td class="num"><?= h(fmt_kg($tot['toplam_brut'])) ?></td></tr>
                <tr><th>DARA</th><td class="num"><?= h(fmt_kg(round_half((float)$tot['toplam_dara']))) ?></td></tr>
                <tr><th>NET</th><td class="num strong"><?= h(fmt_kg($tot['toplam_net'])) ?></td></tr>

                <?php for ($i = 0; $i < 4; $i++):
                    $key = $urun_keys[$i] ?? null;
                    $g = $key ? $urun_groups[$key] : null;
                ?>
                    <tr><th class="urun-banner" colspan="2">ÜRÜN / <?= $i + 1 ?><?= $key ? ' — ' . h($key) : '' ?></th></tr>
                    <tr><th>KASA</th><td class="num"><?= $g ? (int)$g['kasa'] : '' ?></td></tr>
                    <tr><th>BRÜT</th><td class="num"><?= $g ? h(fmt_kg($g['brut'])) : '' ?></td></tr>
                    <tr><th>DARA</th><td class="num"><?= $g ? h(fmt_kg(round_half($g['dara']))) : '' ?></td></tr>
                    <tr><th>NET</th><td class="num"><?= $g ? h(fmt_kg($g['net'])) : '' ?></td></tr>
                <?php endfor; ?>
            </table>
        </div>
    </div>

    <!-- ============= ALT BLOK: Toplam dara satırı ============= -->
    <table class="asya-bottom" style="table-layout:auto">
        <tr>
            <th class="bot-label">TOPLAM</th>
            <th><?= $palet_bottom_label ?></th>
            <?php foreach ($kasa_dara_breakdown as $k): ?>
            <th><?= h(mb_strtoupper($k['name'], 'UTF-8')) ?> KASA<br>
                <small style="font-size:6pt;font-weight:normal"><?= (int)$k['adet'] ?> ADET</small></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td class="bot-label" style="font-weight:700;text-align:center">DARA</td>
            <td class="num"><?= h(fmt_kg(round_half($palet_sapka_kose_dara))) ?></td>
            <?php foreach ($kasa_dara_breakdown as $k): ?>
            <td class="num"><?= h(fmt_kg(round_half((float)$k['kg']))) ?></td>
            <?php endforeach; ?>
        </tr>
    </table>

    <!-- Teslim eden / Teslim alan -->
    <div class="asya-signatures">
        <div><span>TESLİM EDEN</span><div class="line"></div></div>
        <div><span>TESLİM ALAN</span><div class="line"></div></div>
    </div>

</div>
<?php endif; ?>

<?php if (!$print): ?>
<!-- ============================================================
     KALAN PALET HESAPLAMA MODAL
     ============================================================ -->
<script>window.KALAN_RECORD_ID = <?= (int)$id ?>;</script>

<div id="kalanModal" hidden role="dialog" aria-modal="true" aria-labelledby="kalanModalTitle">
  <div class="kalan-panel">

    <div class="kalan-header">
      <h2 id="kalanModalTitle">Kalan Palet Hesaplama</h2>
      <button class="kalan-close" aria-label="Kapat">✕</button>
    </div>

    <!-- 1) Hedef -->
    <div class="kalan-section">
      <p class="kalan-section-title">Hedef</p>
      <div class="kalan-target-row">
        <div class="kalan-field">
          <label for="kalanTargetKg">Hedef Toplam Kilo (kg)</label>
          <input id="kalanTargetKg" class="kalan-input" type="number" step="0.001" min="0" placeholder="örn. 25000">
        </div>
        <div class="kalan-field">
          <label for="kalanTargetPc">Hedef Palet Sayısı</label>
          <input id="kalanTargetPc" class="kalan-input" type="number" min="0" step="1" placeholder="örn. 26">
        </div>
        <div>
          <button id="kalanSaveTarget" class="kalan-btn kalan-btn-ghost">Kaydet</button>
        </div>
      </div>
    </div>

    <!-- 2) Mevcut Durum -->
    <div class="kalan-section">
      <p class="kalan-section-title">Mevcut Durum</p>
      <div id="kalanStatus" class="kalan-stat-grid">
        <div class="kalan-stat"><span>Yükleniyor…</span></div>
      </div>
    </div>

    <!-- 3) Kasa Tipi Ortalamaları -->
    <div class="kalan-section">
      <p class="kalan-section-title">Kasa Tipi Ortalamaları</p>
      <div class="kalan-table-wrap">
        <table class="kalan-table">
          <thead>
            <tr>
              <th>Kasa Adeti</th>
              <th>Ort. kg / Palet</th>
              <th>Kaynak</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="kalanCrateRows">
            <tr><td colspan="4" class="muted center" style="padding:12px">Yükleniyor…</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Yeni kasa ekle formu -->
      <div id="kalanCrateAdd" hidden class="kalan-add-form">
        <div class="kalan-field">
          <label for="kalanNewCount">Kasa Adeti</label>
          <input id="kalanNewCount" class="kalan-input" type="number" min="1" step="1" placeholder="örn. 90">
        </div>
        <div class="kalan-field">
          <label for="kalanNewAvg">Ort. kg / Palet</label>
          <input id="kalanNewAvg" class="kalan-input" type="number" step="0.1" min="1" placeholder="örn. 970">
        </div>
        <button id="kalanConfirmAdd" class="kalan-btn kalan-btn-primary">Ekle</button>
        <button id="kalanCancelAdd" class="kalan-btn kalan-btn-ghost">İptal</button>
      </div>

      <div class="kalan-actions">
        <button id="kalanAddCrate" class="kalan-btn kalan-btn-ghost">+ Kasa Tipi Ekle</button>
        <button id="kalanAutoAll" class="kalan-btn kalan-btn-ghost">⟳ Tümünü Otomatik Hesapla</button>
      </div>
    </div>

    <!-- 4) Hesapla -->
    <div class="kalan-section">
      <p class="kalan-section-title">Kombinasyon Hesapla</p>
      <button id="kalanCalcBtn" class="kalan-btn kalan-btn-primary" style="width:100%">Hesapla</button>
    </div>

    <!-- 5) Sonuçlar -->
    <div id="kalanResults" hidden class="kalan-section">
      <p class="kalan-section-title">Önerilen Plan</p>
      <div id="kalanBestPlan"></div>

      <p class="kalan-section-title" style="margin-top:18px">Tüm Kombinasyonlar</p>
      <div class="kalan-table-wrap">
        <table class="kalan-table kalan-combos-table">
          <thead>
            <tr>
              <th class="combo-cell">Kombinasyon</th>
              <th class="num">Tahmini kg</th>
              <th class="num">Final kg</th>
              <th class="num">Fark</th>
              <th>Durum</th>
            </tr>
          </thead>
          <tbody id="kalanCombosBody"></tbody>
        </table>
      </div>
    </div>

  </div><!-- .kalan-panel -->
</div><!-- #kalanModal -->

<script src="assets/kalan.js"></script>
<?php endif; ?>

<script>
(function () {
    var KEY = 'etiket_img_<?= (int)$id ?>';
    var imgEl  = document.getElementById('etiketImg');
    var ph     = document.getElementById('etiketPlaceholder');
    var inp    = document.getElementById('etiketInput');
    var clrBtn = document.getElementById('etiketClear');

    function show(src) {
        if (!imgEl) return;
        imgEl.src = src;
        imgEl.style.display = 'block';
        if (ph)     ph.style.display     = 'none';
        if (clrBtn) clrBtn.style.display = 'inline-block';
    }
    function clear() {
        if (!imgEl) return;
        imgEl.src = '';
        imgEl.style.display = 'none';
        if (ph)     ph.style.display     = 'flex';
        if (clrBtn) clrBtn.style.display = 'none';
        try { localStorage.removeItem(KEY); } catch(e) {}
    }

    try {
        var saved = localStorage.getItem(KEY);
        if (saved) show(saved);
    } catch(e) {}

    if (inp) {
        inp.addEventListener('change', function () {
            var file = this.files[0];
            if (!file) return;
            var r = new FileReader();
            r.onload = function (e) {
                var src = e.target.result;
                try { localStorage.setItem(KEY, src); } catch(e) {}
                show(src);
            };
            r.readAsDataURL(file);
        });
    }
    if (clrBtn) clrBtn.addEventListener('click', clear);
})();
</script>

<?php render_footer($print); ?>
