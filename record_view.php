<?php
// =========================================================
// record_view.php (v3)
// - Yazdırma modu (?print=1 veya yazdırma): ASYA FRESH şablonu
// - Ekran modu: mobil uyumlu kart + PC tablo
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

$id = (int)($_GET['id'] ?? 0);
$print = !empty($_GET['print']);
if ($id <= 0) { set_flash('error', 'Geçersiz kayıt.'); header('Location: records.php'); exit; }

$st = db()->prepare("SELECT * FROM loading_records WHERE id=:id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) { set_flash('error', 'Kayıt bulunamadı.'); header('Location: records.php'); exit; }

$list_url = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';

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
    return $name . ' (' . fmt_unit_kg($kg) . ' kg)';
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
    // 3) Ek malzemeler: pallet_materials.quantity (kg: unit × adet, total_dara_kg artık 0)
    foreach ($p['materials'] as $m) {
        $kid = (int)$m['def_id'];
        if (!isset($stok_use[$kid])) $stok_use[$kid] = ['adet' => 0, 'kg' => 0];
        $stok_use[$kid]['adet'] += (float)$m['quantity'];
        $stok_use[$kid]['kg']   += (float)$m['material_unit'] * (float)$m['quantity'];
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
    // Malzeme daraları artık net hesabına dahil edilmez
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

// Toplu malzeme ekleme modalı için aktif malzemeleri çek
$bm_materials = get_all_active_materials();

// "Eklenenleri Sil" sekmesi — bu kayda eklenmiş sarf/giydirme malzemeleri (pallet_materials)
$extra_materials = [];
foreach ($pallets as $p) {
    foreach (($p['materials'] ?? []) as $m) {
        $extra_materials[] = [
            'pm_id'    => (int)$m['id'],
            'name'     => $m['material_name'],
            'type'     => $type_labels[$m['material_type']] ?? $m['material_type'],
            'qty'      => (float)$m['quantity'],
            'depo'     => $p['depo'] ?? '',
            'palet_no' => $p['palet_no'] ?? '',
        ];
    }
}

$depo_values = array_unique(array_filter(array_map('trim', array_column($pallets, 'depo'))));
$depo_str = !empty($depo_values) ? implode(', ', $depo_values) : '—';

render_header(h($record['firma'] ?? 'Kayıt'), $print);
?>
<?php if ($print): ?>
<style>@page { size: A4 portrait; margin: 4mm; }</style>
<?php endif; ?>
<?php
if (($record['type'] ?? 'yukleme') === 'cikma') {
    $GLOBALS['_nav_cikma_hint'] = true;
}
?>
<?php if (!$print): ?>
<?php render_flash(); ?>
<?php
$_is_locked   = !empty($record['locked_at']);
$_can_unlock  = function_exists('can') && can('records.unlock');
?>
<?php if ($_is_locked): ?>
<div class="lock-banner">
    <span class="lock-banner-icon">🔒</span>
    <div class="lock-banner-body">
        <strong>Kayıt kilitlidir</strong>
        <span class="lock-banner-detail">
            <?= h(fmt_datetime($record['locked_at'])) ?>
            <?php
            if (!empty($record['locked_by'])) {
                try {
                    $st_lkr = db()->prepare("SELECT display_name, username FROM users WHERE id=?");
                    $st_lkr->execute([(int)$record['locked_by']]);
                    $_lkr = $st_lkr->fetch();
                    if ($_lkr) echo ' · ' . h($_lkr['display_name'] ?: $_lkr['username']);
                } catch (Throwable $_e) {}
            }
            ?>
        </span>
        <?php if (!empty($record['revision_reason'])): ?>
        <span class="lock-banner-reason">Son revizyon: <?= h($record['revision_reason']) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($_can_unlock): ?>
    <button type="button" class="btn btn-sm btn-ghost" id="rvUnlockBtn">Kilidi Aç</button>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="page-head rv-head">
    <a href="<?= h($list_url) ?>" class="btn btn-ghost rv-back">← Liste</a>
    <div class="rv-actions">
        <?php if (!$_is_locked || $_can_unlock): ?>
        <a href="record_edit.php?id=<?= (int)$id ?>" class="btn">✎ Düzenle</a>
        <?php endif; ?>
        <a href="record_view.php?id=<?= (int)$id ?>&print=1" class="btn btn-primary" target="_blank">🖨 Yazdır</a>
        <div class="pc-kebab-wrap">
            <button class="btn pc-kebab" type="button" title="Diğer İşlemler">⋮</button>
            <div class="pc-dropdown" hidden>
                <button type="button" id="kalanOpenBtn">📊 Kalan Palet Hesapla</button>
                <button type="button" id="bmOpenBtn">📦 Malzeme Çıkışı</button>
            </div>
        </div>
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

<div class="view-hdr-card">
    <div class="view-hdr-main">
        <div class="view-hdr-firma"><?= h($record['firma'] ?: '—') ?></div>
        <?php if (($record['type'] ?? 'yukleme') === 'cikma'): ?>
        <div class="view-hdr-parti">
            <?php if (!empty($record['cikis_nedeni'])): ?>
            <span class="cikis-nedeni-badge"><?= h($record['cikis_nedeni']) ?></span>
            <?php else: ?>
            <span class="muted" style="font-size:.85rem">Çıkış Nedeni: —</span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="view-hdr-parti">Parti No: <strong><?= h($record['parti_no'] ?: '—') ?></strong></div>
        <?php endif; ?>
    </div>
    <div class="view-hdr-meta">
        <div><span>Oluşturulma:</span> <?= h(fmt_datetime($record['created_at'])) ?></div>
        <?php if (!empty($record['updated_at']) && $record['updated_at'] !== $record['created_at']): ?>
        <div><span>Son düzenleme:</span> <?= h(fmt_datetime($record['updated_at'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="info-grid">
    <div><span class="lbl">Tarih</span><strong><?= h(fmt_date($record['tarih'])) ?></strong></div>
    <div><span class="lbl">Ürün</span><strong><?= h($record['urun']) ?></strong></div>
    <div><span class="lbl">Depo</span><strong><?= h($depo_str) ?></strong></div>
    <?php if (($record['type'] ?? 'yukleme') === 'cikma' && !empty($record['cikis_nedeni'])): ?>
    <div><span class="lbl">Çıkış Nedeni</span><strong><span class="cikis-nedeni-badge"><?= h($record['cikis_nedeni']) ?></span></strong></div>
    <?php endif; ?>
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
                <td class="num"><?= h(fmt_kg($p['dara_kg'])) ?></td>
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
            <td class="num strong tot-orange"><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></td>
            <td class="num strong tot-orange"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></td>
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
                <div><span>Ürün Cinsi</span><strong><?= h($p['urun_cinsi'] ?: '—') ?></strong></div>
                <div><span>Depo</span><strong><?= h($p['depo'] ?: '—') ?></strong></div>
                <div><span>Kasa Cinsi</span><strong><?= h(kasa_label($p['kasa_cinsi_adi'], $p['kasa_cinsi_kg'])) ?></strong></div>
                <div><span>Palet Tipi</span><strong><?= h(kasa_label($p['palet_tipi_adi'], $p['palet_tipi_kg'])) ?></strong></div>
                <?php if ($p['size']): ?><div class="span-2"><span>Size</span><strong><?= h($p['size']) ?></strong></div><?php endif; ?>
            </div>
            <?php if (!empty($p['materials'])): ?>
                <div class="vc-materials">
                    <div class="vc-mat-title">Malzemeler</div>
                    <?php foreach ($p['materials'] as $m): ?>
                        <div class="vc-mat">
                            <span><?= h(($type_labels[$m['material_type']] ?? '') . ' / ' . $m['material_name']) ?>
                                  × <?= h(fmt_kg($m['quantity'])) ?></span>
                            <?php if (!$print): ?>
                            <button type="button" class="vc-mat-del" data-pmid="<?= (int)$m['id'] ?>" title="Malzemeyi sil">✕</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="vc-totals">
                <div><span>Brüt</span><strong><?= h(fmt_kg($p['brut_kg'])) ?></strong></div>
                <div><span>Dara</span><strong><?= h(fmt_kg($p['dara_kg'])) ?></strong></div>
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
        <div class="tot-orange"><span>Toplam Dara</span><strong><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></strong></div>
        <div class="tot-orange"><span>Toplam Net</span><strong class="strong"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></strong></div>
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
                <?php $brand = strtoupper(trim((string)($record['brand'] ?? ''))); ?>
                <div class="asya-marka-row">
                    <?php foreach (['ASYA', 'URAL', 'URAS'] as $_bv): $_on = ($brand === $_bv); ?>
                    <div class="marka-cell<?= $_on ? ' marka-on' : '' ?>">
                        <span><?= $_on ? '✓ ' : '' ?><?= $_bv ?></span><strong>MARKA</strong>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($brand === ''): ?>
                <div class="marka-uyari">⚠ Marka seçilmedi</div>
                <?php endif; ?>
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
                <div class="asya-etiket">
                    <img id="etiketImg" class="etiket-img" alt="" style="display:none">
                    <div id="etiketPlaceholder" class="etiket-placeholder">ETİKET</div>
                    <div class="etiket-actions no-print">
                        <label class="etiket-upload-btn">
                            📷 Fotoğraf Ekle
                            <input type="file" id="etiketInput" accept="image/*" capture="environment" style="display:none">
                        </label>
                        <button id="etiketClear" class="etiket-clear-btn" style="display:none">✕ Kaldır</button>
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

        <!-- Stok Çıkışları (ayrı sütun) -->
        <table class="asya-stok">
            <thead>
            <tr><th class="th-banner" colspan="2">STOK ÇIKIŞLARI</th></tr>
            <tr>
                <th class="stok-name">MALZEME</th>
                <th class="stok-adet">ADET</th>
            </tr>
            </thead>
            <tbody>
            <?php $stok_shown = 0; foreach ($stok_rows as $sr):
                [$key, $label, $allowed, $match] = $sr;
                $r = stok_satir_topla($stok_use, $defs_by_id, $allowed, $match);
                if ($r['adet'] <= 0) continue;
                $stok_shown++;
                $adet_str = rtrim(rtrim(number_format($r['adet'], 3, ',', '.'), '0'), ',');
            ?>
                <tr>
                    <td class="stok-name"><?= h($label) ?></td>
                    <td class="stok-val"><?= h($adet_str) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($fi = $stok_shown; $fi < $grid_rows; $fi++): ?>
                <tr class="stok-filler"><td></td><td></td></tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <!-- Genel Toplam + Ürün/1..4 (ayrı sütun) -->
        <table class="asya-totals">
            <tr><th class="th-banner" colspan="2">GENEL TOPLAM</th></tr>
            <tr><th>KASA</th><td class="num"><?= (int)$tot['toplam_kasa'] ?></td></tr>
            <tr><th>BRÜT</th><td class="num"><?= h(fmt_kg($tot['toplam_brut'])) ?></td></tr>
            <tr><th>DARA</th><td class="num"><?= h(fmt_kg(round((float)$tot['toplam_dara']))) ?></td></tr>
            <tr><th>NET</th><td class="num strong"><?= h(fmt_kg(round((float)$tot['toplam_net']))) ?></td></tr>

            <?php for ($i = 0; $i < 4; $i++):
                $key = $urun_keys[$i] ?? null;
                $g   = $key ? $urun_groups[$key] : null;
            ?>
                <tr><th class="urun-banner<?= $key ? '' : ' urun-banner-empty' ?>" colspan="2"><?= $key ? h(mb_strtoupper($key, 'UTF-8')) : '' ?></th></tr>
                <tr><th>KASA</th><td class="num"><?= $g ? (int)$g['kasa'] : '' ?></td></tr>
                <tr><th>BRÜT</th><td class="num"><?= $g ? h(fmt_kg($g['brut'])) : '' ?></td></tr>
                <tr><th>DARA</th><td class="num"><?= $g ? h(fmt_kg(round($g['dara']))) : '' ?></td></tr>
                <tr><th>NET</th> <td class="num"><?= $g ? h(fmt_kg(round((float)$g['net']))) : '' ?></td></tr>
            <?php endfor; ?>
        </table>
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
            <td class="num"><?= h(fmt_kg(round($palet_sapka_kose_dara))) ?></td>
            <?php foreach ($kasa_dara_breakdown as $k): ?>
            <td class="num"><?= h(fmt_kg(round((float)$k['kg']))) ?></td>
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

<!-- ============================================================
     TOPLU MALZEME ÇIKIŞI MODAL
     ============================================================ -->
<div id="bmOverlay" class="pm-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="bmTitle">
  <div class="pm-dialog">
    <div class="pm-header">
      <h2 class="pm-title" id="bmTitle">Malzeme Çıkışı Ekle</h2>
      <button type="button" class="pm-close" id="bmClose" aria-label="Kapat">✕</button>
    </div>

    <!-- Tab bar -->
    <div class="bm-tabs">
      <button type="button" class="bm-tab-btn active" data-tab="single">Malzeme Ekle</button>
      <button type="button" class="bm-tab-btn" data-tab="tpl">Şablonlar</button>
      <button type="button" class="bm-tab-btn" data-tab="remove">Eklenenleri Sil<?= !empty($extra_materials) ? ' (' . count($extra_materials) . ')' : '' ?></button>
    </div>

    <div class="pm-body">

      <!-- ── Tek malzeme paneli ── -->
      <div class="bm-panel" id="bmPanelSingle">
        <div class="pm-grid" style="margin-bottom:12px">
          <label class="pm-label pm-span2">
            <span>Malzeme *</span>
            <select id="bmMaterial">
              <option value="">-- malzeme seçiniz --</option>
              <?php
              $bm_by_type = [];
              foreach ($bm_materials as $m) {
                  if (in_array($m['type'], ['kasa_cinsi', 'palet_tipi', 'firma', 'depo', 'urun'])) continue;
                  $bm_by_type[$m['type']][] = $m;
              }
              foreach ($bm_by_type as $btype => $blist):
                  $blabel = $type_labels[$btype] ?? $btype;
              ?>
              <optgroup label="<?= h($blabel) ?>">
                <?php foreach ($blist as $bm): ?>
                <option value="<?= (int)$bm['id'] ?>" data-unit="<?= h($bm['unit_dara_kg']) ?>"><?= h($bm['name']) ?> (<?= h(fmt_unit_kg($bm['unit_dara_kg'])) ?> kg)</option>
                <?php endforeach; ?>
              </optgroup>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="pm-label">
            <span>Adet *</span>
            <input type="text" inputmode="decimal" id="bmQty" value="1" placeholder="Adet" class="num">
          </label>
          <div class="pm-label" style="justify-content:flex-end;padding-top:4px">
            <span id="bmDaraPreview" class="bm-dara-preview"></span>
          </div>
        </div>
      </div>

      <!-- ── Şablonlar paneli ── -->
      <div class="bm-panel" id="bmPanelTpl" hidden>
        <div id="bmTplList" class="bm-tpl-list"></div>
        <button type="button" class="btn btn-sm" id="bmTplNewBtn">+ Yeni Şablon Oluştur</button>

        <div id="bmTplForm" class="bm-create-form" hidden>
          <h4>Yeni Şablon</h4>
          <label class="pm-label" style="margin-bottom:10px">
            <span>Şablon Adı *</span>
            <input type="text" id="bmTplName" placeholder="Ör: İhracat Seti">
          </label>
          <div id="bmTplMatRows" class="bm-tpl-mat-rows"></div>
          <button type="button" class="btn btn-sm btn-ghost" id="bmTplAddRow" style="margin-bottom:12px">+ Satır Ekle</button>
          <div style="display:flex;gap:8px">
            <button type="button" class="btn btn-sm btn-primary" id="bmTplSaveBtn">Kaydet</button>
            <button type="button" class="btn btn-sm btn-ghost"   id="bmTplCancelBtn">Vazgeç</button>
          </div>
          <p id="bmTplStatus" class="bm-status" style="margin-top:6px"></p>
        </div>
      </div>

      <!-- ── Eklenenleri Sil paneli ── -->
      <div class="bm-panel" id="bmPanelRemove" hidden>
        <?php if (empty($extra_materials)): ?>
          <div class="bm-tpl-empty">Bu kayda eklenmiş giydirme/sarf malzeme yok.</div>
        <?php else: ?>
          <div class="bm-rm-head">
            <label class="bm-check-label"><input type="checkbox" id="bmRmAll"> <strong>Tümünü Seç</strong></label>
            <span class="muted">Kasa cinsi ve palet tipi silinmez.</span>
          </div>
          <div class="bm-rm-list">
            <?php foreach ($extra_materials as $em): ?>
            <label class="bm-check-label bm-rm-item">
              <input type="checkbox" class="bm-rm-cb" value="<?= (int)$em['pm_id'] ?>">
              <span class="bm-rm-name"><?= h($em['name']) ?></span>
              <span class="muted"><?= h($em['type']) ?> · Palet <?= h($em['palet_no'] !== '' ? $em['palet_no'] : '—') ?> · <?= h(rtrim(rtrim(number_format($em['qty'], 3, ',', '.'), '0'), ',')) ?> adet<?= $em['depo'] !== '' ? ' · ' . h($em['depo']) : '' ?></span>
            </label>
            <?php endforeach; ?>
          </div>
          <div class="bm-rm-actions">
            <button type="button" class="btn btn-sm btn-danger" id="bmRmSelected">Seçilenleri Sil</button>
            <button type="button" class="btn btn-sm btn-ghost" id="bmRmAllBtn">Tüm Giydirme Malzemelerini Kaldır</button>
          </div>
        <?php endif; ?>
        <p id="bmRmStatus" class="bm-status"></p>
      </div>

      <!-- ── Palet seçimi (her iki modda ortak) ── -->
      <div class="bm-pallets-head">
        <label class="bm-check-label">
          <input type="checkbox" id="bmAll" checked>
          <strong>Tüm Paletler</strong>
          <span class="muted">(<?= count($pallets) ?> palet seçildi)</span>
        </label>
      </div>
      <div class="bm-pallet-list">
        <?php foreach ($pallets as $p): ?>
        <label class="bm-check-label">
          <input type="checkbox" class="bm-pallet-cb" value="<?= (int)$p['id'] ?>" checked>
          <span>Palet <?= h($p['palet_no']) ?></span>
          <span class="muted"><?= (int)$p['kasa_adeti'] ?> kasa · <?= h(fmt_kg($p['brut_kg'])) ?> kg</span>
        </label>
        <?php endforeach; ?>
      </div>

      <p id="bmStatus" class="bm-status"></p>
    </div>

    <div class="pm-footer">
      <button type="button" class="btn btn-ghost" id="bmCancel">İptal</button>
      <button type="button" class="btn btn-primary" id="bmApply">Uygula</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$print): ?>
<script>
/* ── Toplu Malzeme Çıkışı Modal ── */
(function () {
    var CSRF     = document.querySelector('meta[name="csrf-token"]').content;
    var REC_ID   = <?= (int)$id ?>;

    var overlay  = document.getElementById('bmOverlay');
    var openBtn  = document.getElementById('bmOpenBtn');
    var statusEl = document.getElementById('bmStatus');
    var allCb    = document.getElementById('bmAll');
    var applyBtn = document.getElementById('bmApply');

    /* ── PHP malzeme listesi (şablon formu için) ── */
    <?php
    $bm_mat_list = [];
    foreach ($bm_materials as $m) {
        if ($m['type'] === 'kasa_cinsi' || $m['type'] === 'palet_tipi') continue;
        $bm_mat_list[] = [
            'id'   => (int)$m['id'],
            'name' => $m['name'],
            'type' => $type_labels[$m['type']] ?? $m['type'],
            'unit' => (float)$m['unit_dara_kg'],
        ];
    }
    ?>
    var BM_MATS = <?= json_encode($bm_mat_list) ?>;

    /* ── Yardımcılar ── */
    function parseN(v) { return parseFloat(String(v).replace(',', '.')) || 0; }
    function fmtN(n)   {
        var s = n.toLocaleString('tr-TR', {minimumFractionDigits:0, maximumFractionDigits:3});
        return s;
    }
    function esc(s)    { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function getSelectedPalletIds() {
        var ids = [];
        document.querySelectorAll('.bm-pallet-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
        return ids;
    }

    /* ── Tab yönetimi ── */
    var activeTab = 'single';
    document.querySelectorAll('.bm-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeTab = btn.dataset.tab;
            document.querySelectorAll('.bm-tab-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('bmPanelSingle').hidden = (activeTab !== 'single');
            document.getElementById('bmPanelTpl').hidden    = (activeTab !== 'tpl');
            var rmPanel = document.getElementById('bmPanelRemove');
            if (rmPanel) rmPanel.hidden = (activeTab !== 'remove');
            applyBtn.style.display = (activeTab === 'single') ? '' : 'none';
            // Palet seçimi "Eklenenleri Sil" modunda gereksiz → gizle
            var palHead = document.querySelector('.bm-pallets-head');
            var palList = document.querySelector('.bm-pallet-list');
            var showPal = (activeTab !== 'remove');
            if (palHead) palHead.style.display = showPal ? '' : 'none';
            if (palList) palList.style.display = showPal ? '' : 'none';
            if (activeTab === 'tpl') loadTemplates();
        });
    });

    /* ── Açma/Kapama ── */
    function openModal() {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        statusEl.textContent = '';
        // Şablonlar aktifse yükle
        if (activeTab === 'tpl') loadTemplates();
    }
    function closeModal() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }
    if (openBtn) openBtn.addEventListener('click', openModal);
    document.getElementById('bmClose').addEventListener('click', closeModal);
    document.getElementById('bmCancel').addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal();
    });

    /* ── Palet seçimi ── */
    allCb.addEventListener('change', function() {
        document.querySelectorAll('.bm-pallet-cb').forEach(function(cb) { cb.checked = allCb.checked; });
        updateCount();
    });
    function updateCount() {
        var total   = document.querySelectorAll('.bm-pallet-cb').length;
        var checked = document.querySelectorAll('.bm-pallet-cb:checked').length;
        allCb.checked       = (total === checked);
        allCb.indeterminate = (checked > 0 && checked < total);
        allCb.closest('label').querySelector('span.muted').textContent = '(' + checked + ' palet seçildi)';
    }
    document.querySelectorAll('.bm-pallet-cb').forEach(function(cb) { cb.addEventListener('change', updateCount); });

    /* ── Tek malzeme: dara önizlemesi ── */
    var matSel  = document.getElementById('bmMaterial');
    var qtyInp  = document.getElementById('bmQty');
    var preview = document.getElementById('bmDaraPreview');
    function updatePreview() {
        var opt  = matSel.options[matSel.selectedIndex];
        var unit = opt && opt.dataset.unit ? parseN(opt.dataset.unit) : 0;
        var qty  = parseN(qtyInp.value);
        preview.textContent = (unit && qty) ? 'Dara: ' + fmtN(unit * qty) + ' kg' : '';
    }
    matSel.addEventListener('change', updatePreview);
    qtyInp.addEventListener('input',  updatePreview);

    /* ── Tek malzeme: Uygula ── */
    applyBtn.addEventListener('click', function() {
        var mid = parseInt(matSel.value);
        var qty = parseN(qtyInp.value);
        if (!mid)       { alert('Malzeme seçiniz.'); return; }
        if (qty <= 0)   { alert('Geçerli bir adet giriniz.'); return; }
        var ids = getSelectedPalletIds();
        if (!ids.length){ alert('En az bir palet seçiniz.'); return; }
        sendMaterials([{material_id: mid, quantity: qty}], ids, applyBtn);
    });

    /* ── API: malzeme gönder ── */
    function sendMaterials(materials, palletIds, triggerBtn) {
        if (triggerBtn) triggerBtn.disabled = true;
        statusEl.textContent = 'Uygulanıyor…';
        statusEl.style.color = 'var(--muted)';

        fetch('api_bulk_material.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf: CSRF, record_id: REC_ID, materials: materials, pallet_ids: palletIds})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                statusEl.textContent = '✓ ' + data.updated + ' palete eklendi. Sayfa yenileniyor…';
                statusEl.style.color = 'var(--success)';
                setTimeout(function() { location.reload(); }, 900);
            } else {
                if (triggerBtn) triggerBtn.disabled = false;
                statusEl.textContent = 'Hata: ' + (data.error || 'Bilinmeyen hata');
                statusEl.style.color = 'var(--danger)';
            }
        })
        .catch(function() {
            if (triggerBtn) triggerBtn.disabled = false;
            statusEl.textContent = 'Bağlantı hatası.';
            statusEl.style.color = 'var(--danger)';
        });
    }

    /* ════════════════════════════════════
       ŞABLONLAR
    ════════════════════════════════════ */
    var tplListEl   = document.getElementById('bmTplList');
    var tplNewBtn   = document.getElementById('bmTplNewBtn');
    var tplForm     = document.getElementById('bmTplForm');
    var tplNameInp  = document.getElementById('bmTplName');
    var tplRowsCont = document.getElementById('bmTplMatRows');
    var tplAddRow   = document.getElementById('bmTplAddRow');
    var tplSaveBtn  = document.getElementById('bmTplSaveBtn');
    var tplCancelBtn= document.getElementById('bmTplCancelBtn');
    var tplStatus   = document.getElementById('bmTplStatus');

    /* Malzeme select seçeneği oluştur */
    function buildMatSelect(selectedId) {
        var sel = document.createElement('select');
        sel.className = 'tpl-mat-sel';
        var emptyOpt = document.createElement('option');
        emptyOpt.value = ''; emptyOpt.textContent = '-- seçiniz --';
        sel.appendChild(emptyOpt);

        // Gruplanmış
        var groups = {};
        BM_MATS.forEach(function(m) {
            if (!groups[m.type]) groups[m.type] = [];
            groups[m.type].push(m);
        });
        Object.keys(groups).forEach(function(gname) {
            var og = document.createElement('optgroup');
            og.label = gname;
            groups[gname].forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.dataset.unit = m.unit;
                opt.textContent = m.name + ' (' + fmtN(m.unit) + ' kg)';
                if (String(m.id) === String(selectedId)) opt.selected = true;
                og.appendChild(opt);
            });
            sel.appendChild(og);
        });
        return sel;
    }

    /* Şablon satırı ekle */
    function addTplRow(matId, qty) {
        var row = document.createElement('div');
        row.className = 'bm-tpl-mat-row';
        var sel = buildMatSelect(matId || '');
        var qtyInpR = document.createElement('input');
        qtyInpR.type = 'text'; qtyInpR.inputMode = 'decimal';
        qtyInpR.className = 'tpl-mat-qty num'; qtyInpR.value = qty || 1; qtyInpR.placeholder = 'Adet';
        var delBtn = document.createElement('button');
        delBtn.type = 'button'; delBtn.className = 'btn btn-sm btn-ghost tpl-row-del'; delBtn.textContent = '✕';
        delBtn.addEventListener('click', function() { row.remove(); });
        row.appendChild(sel); row.appendChild(qtyInpR); row.appendChild(delBtn);
        tplRowsCont.appendChild(row);
    }

    tplAddRow.addEventListener('click', function() { addTplRow(); });

    /* Formu aç/kapat */
    tplNewBtn.addEventListener('click', function() {
        tplForm.hidden = false;
        tplNewBtn.hidden = true;
        tplNameInp.value = '';
        tplRowsCont.innerHTML = '';
        tplStatus.textContent = '';
        addTplRow(); // Başlangıçta 1 boş satır
        tplNameInp.focus();
    });
    tplCancelBtn.addEventListener('click', function() {
        tplForm.hidden = true;
        tplNewBtn.hidden = false;
    });

    /* Şablon kaydet */
    tplSaveBtn.addEventListener('click', function() {
        var name = tplNameInp.value.trim();
        if (!name) { tplStatus.textContent = 'Şablon adı gerekli.'; tplStatus.style.color = 'var(--danger)'; return; }
        var items = [];
        tplRowsCont.querySelectorAll('.bm-tpl-mat-row').forEach(function(row) {
            var mid = parseInt(row.querySelector('.tpl-mat-sel').value);
            var qty = parseN(row.querySelector('.tpl-mat-qty').value);
            if (mid && qty > 0) items.push({material_id: mid, quantity: qty});
        });
        if (!items.length) { tplStatus.textContent = 'En az bir malzeme ekleyin.'; tplStatus.style.color = 'var(--danger)'; return; }

        tplSaveBtn.disabled = true;
        tplStatus.textContent = 'Kaydediliyor…'; tplStatus.style.color = 'var(--muted)';

        fetch('api_templates.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf: CSRF, action: 'save', name: name, items: items})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            tplSaveBtn.disabled = false;
            if (data.ok) {
                tplForm.hidden = true; tplNewBtn.hidden = false;
                tplStatus.textContent = '';
                loadTemplates();
            } else {
                tplStatus.textContent = 'Hata: ' + (data.error || 'Bilinmeyen hata');
                tplStatus.style.color = 'var(--danger)';
            }
        })
        .catch(function() {
            tplSaveBtn.disabled = false;
            tplStatus.textContent = 'Bağlantı hatası.'; tplStatus.style.color = 'var(--danger)';
        });
    });

    /* Şablon listesini yükle */
    function loadTemplates() {
        tplListEl.innerHTML = '<div class="bm-tpl-empty">Yükleniyor…</div>';
        fetch('api_templates.php')
        .then(function(r) { return r.json(); })
        .then(function(list) { renderTemplates(list); })
        .catch(function() { tplListEl.innerHTML = '<div class="bm-tpl-empty">Yüklenemedi.</div>'; });
    }

    /* Şablon listesini render et */
    function renderTemplates(list) {
        if (!list.length) {
            tplListEl.innerHTML = '<div class="bm-tpl-empty">Henüz şablon yok. "Yeni Şablon Oluştur" ile başlayın.</div>';
            return;
        }
        tplListEl.innerHTML = '';
        list.forEach(function(tpl) {
            var itemsText = tpl.items.map(function(it) {
                return esc(it.material_name) + ' ×' + fmtN(parseN(it.quantity));
            }).join(', ');

            var card = document.createElement('div');
            card.className = 'bm-tpl-card';
            card.innerHTML =
                '<div class="bm-tpl-info">' +
                  '<div class="bm-tpl-name">' + esc(tpl.name) + '</div>' +
                  '<div class="bm-tpl-items">' + (itemsText || '<em>—</em>') + '</div>' +
                '</div>' +
                '<div class="bm-tpl-actions">' +
                  '<button class="btn btn-sm btn-primary" data-apply="' + tpl.id + '">Uygula</button>' +
                  '<button class="btn btn-sm btn-ghost tpl-del-btn" data-del="' + tpl.id + '">Sil</button>' +
                '</div>';

            card.querySelector('[data-apply]').addEventListener('click', function() {
                var ids = getSelectedPalletIds();
                if (!ids.length) { alert('En az bir palet seçiniz.'); return; }
                var materials = tpl.items.map(function(it) {
                    return {material_id: parseInt(it.material_id), quantity: parseN(it.quantity)};
                });
                sendMaterials(materials, ids, this);
            });

            card.querySelector('[data-del]').addEventListener('click', function() {
                if (!confirm('"' + tpl.name + '" şablonu silinsin mi?')) return;
                var btn = this;
                btn.disabled = true;
                fetch('api_templates.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf: CSRF, action: 'delete', id: tpl.id})
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) loadTemplates();
                    else { btn.disabled = false; alert('Hata: ' + data.error); }
                })
                .catch(function() { btn.disabled = false; });
            });

            tplListEl.appendChild(card);
        });
    }

    /* ── Malzeme sil ── */
    document.querySelectorAll('.vc-mat-del').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Bu malzemeyi paletden silmek istediğinizden emin misiniz?')) return;
            btn.disabled = true;
            fetch('api_bulk_material.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({csrf: CSRF, record_id: REC_ID, action: 'delete', pm_id: parseInt(btn.dataset.pmid)})
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.ok) { location.reload(); }
                else { alert('Hata: ' + (data.error || 'Bilinmeyen hata')); btn.disabled = false; }
            })
            .catch(function() { alert('Bağlantı hatası.'); btn.disabled = false; });
        });
    });

    /* ── Eklenenleri Sil (toplu) ── */
    var rmAll    = document.getElementById('bmRmAll');
    var rmStatus = document.getElementById('bmRmStatus');
    if (rmAll) {
        rmAll.addEventListener('change', function() {
            document.querySelectorAll('.bm-rm-cb').forEach(function(cb) { cb.checked = rmAll.checked; });
        });
    }
    function bmRemove(payload, btn, confirmMsg) {
        if (confirmMsg && !confirm(confirmMsg)) return;
        if (btn) btn.disabled = true;
        if (rmStatus) { rmStatus.textContent = 'Siliniyor…'; rmStatus.style.color = 'var(--muted)'; }
        fetch('api_bulk_material.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({csrf: CSRF, record_id: REC_ID}, payload))
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                if (rmStatus) { rmStatus.textContent = '✓ ' + data.deleted + ' malzeme silindi. Yenileniyor…'; rmStatus.style.color = 'var(--success)'; }
                setTimeout(function() { location.reload(); }, 800);
            } else {
                if (btn) btn.disabled = false;
                if (rmStatus) { rmStatus.textContent = 'Hata: ' + (data.error || 'Bilinmeyen hata'); rmStatus.style.color = 'var(--danger)'; }
            }
        })
        .catch(function() {
            if (btn) btn.disabled = false;
            if (rmStatus) { rmStatus.textContent = 'Bağlantı hatası.'; rmStatus.style.color = 'var(--danger)'; }
        });
    }
    var rmSelBtn = document.getElementById('bmRmSelected');
    if (rmSelBtn) rmSelBtn.addEventListener('click', function() {
        var ids = [];
        document.querySelectorAll('.bm-rm-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
        if (!ids.length) { alert('Silinecek malzeme seçiniz.'); return; }
        bmRemove({action: 'delete_selected_materials', pm_ids: ids}, rmSelBtn, ids.length + ' malzeme silinsin mi?');
    });
    var rmAllBtn = document.getElementById('bmRmAllBtn');
    if (rmAllBtn) rmAllBtn.addEventListener('click', function() {
        bmRemove({action: 'delete_all_extra_materials'}, rmAllBtn,
            'Bu kayda eklenmiş TÜM giydirme/sarf malzemeleri kaldırılsın mı? (Kasa/palet bilgileri korunur.)');
    });
})();
</script>
<?php endif; ?>

<!-- ETİKET CROP OVERLAY — her iki modda da DOM'da olmalı (JS erişimi için) -->
<div id="etiketCropOverlay" style="display:none">
  <div class="crop-img-area">
    <div class="crop-img-wrap" id="cropWrap">
      <img id="cropSrc" class="crop-src-img" alt="">
      <div id="cropBox" class="crop-box">
        <div class="crop-handle" data-c="tl"></div>
        <div class="crop-handle" data-c="tr"></div>
        <div class="crop-handle" data-c="bl"></div>
        <div class="crop-handle" data-c="br"></div>
      </div>
    </div>
  </div>
  <div class="crop-footer">
    <button id="cropConfirm" class="crop-btn crop-btn-ok">✓ Onayla</button>
    <button id="cropCancel"  class="crop-btn crop-btn-no">İptal</button>
  </div>
</div>

<script>
(function () {
    /* ─── Etiket alanı ─── */
    var KEY    = 'etiket_<?= (int)$id ?>';
    var imgEl  = document.getElementById('etiketImg');
    var ph     = document.getElementById('etiketPlaceholder');
    var inp    = document.getElementById('etiketInput');
    var clrBtn = document.getElementById('etiketClear');

    function showPhoto(src) {
        if (!imgEl) return;
        imgEl.src = src; imgEl.style.display = 'block';
        if (ph)     ph.style.display     = 'none';
        if (clrBtn) clrBtn.style.display = 'inline-block';
    }
    function clearPhoto() {
        if (imgEl) { imgEl.src = ''; imgEl.style.display = 'none'; }
        if (ph)     ph.style.display     = 'flex';
        if (clrBtn) clrBtn.style.display = 'none';
        try { localStorage.removeItem(KEY); } catch(e) {}
    }

    /* Kayıtlı fotoğrafı yükle (geriye dönük uyum: eski key + plain data-URL) */
    try {
        var raw = localStorage.getItem(KEY)
               || localStorage.getItem('etiket_img_<?= (int)$id ?>');
        if (raw) {
            var src = (raw.charAt(0) === '{') ? JSON.parse(raw).src : raw;
            if (src && src.indexOf('data:') === 0) showPhoto(src);
        }
    } catch(e) {}

    if (clrBtn) clrBtn.addEventListener('click', clearPhoto);

    /* ─── Crop overlay ─── */
    var overlay  = document.getElementById('etiketCropOverlay');
    var cropSrc  = document.getElementById('cropSrc');
    var cropBox  = document.getElementById('cropBox');
    var btnOk    = document.getElementById('cropConfirm');
    var btnNo    = document.getElementById('cropCancel');
    var cs       = { x:0, y:0, w:0, h:0 }; /* px relative to cropSrc */
    var MIN      = 30;

    function renderBox() {
        if (!cropBox) return;
        cropBox.style.left   = cs.x + 'px';
        cropBox.style.top    = cs.y + 'px';
        cropBox.style.width  = cs.w + 'px';
        cropBox.style.height = cs.h + 'px';
    }
    function initBox() {
        var pad = 24;
        var w = cropSrc.offsetWidth, h = cropSrc.offsetHeight;
        cs = { x: pad, y: pad, w: w - pad*2, h: h - pad*2 };
        renderBox();
    }

    function openCrop(dataUrl) {
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        cropSrc.onload = function () {
            requestAnimationFrame(initBox);
        };
        cropSrc.src = dataUrl;
    }
    function closeCrop() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    /* Sürükleme */
    var active = null, sx, sy, sc;
    function xy(e) {
        var t = e.touches ? e.touches[0] : e;
        return { x: t.clientX, y: t.clientY };
    }
    function imgRect() { return cropSrc.getBoundingClientRect(); }

    function pointerDown(e) {
        var c = (e.target.dataset || {}).c;
        active = c || null;
        var p = xy(e);
        sx = p.x; sy = p.y;
        sc = { x: cs.x, y: cs.y, w: cs.w, h: cs.h };
        e.preventDefault();
    }
    function pointerMove(e) {
        if (!active && active !== '') return;
        var p = xy(e), r = imgRect();
        var dx = p.x - sx, dy = p.y - sy;
        var c = Object.assign({}, sc);
        var iw = cropSrc.offsetWidth, ih = cropSrc.offsetHeight;

        if (!active) { /* no handle – shouldn't happen but guard */ return; }

        if (active.indexOf('l') >= 0) {
            var nx = Math.max(0, Math.min(c.x + c.w - MIN, c.x + dx));
            c.w = c.x + c.w - nx; c.x = nx;
        }
        if (active.indexOf('r') >= 0) {
            c.w = Math.max(MIN, Math.min(iw - c.x, c.w + dx));
        }
        if (active.indexOf('t') >= 0) {
            var ny = Math.max(0, Math.min(c.y + c.h - MIN, c.y + dy));
            c.h = c.y + c.h - ny; c.y = ny;
        }
        if (active.indexOf('b') >= 0) {
            c.h = Math.max(MIN, Math.min(ih - c.y, c.h + dy));
        }
        cs = c; renderBox();
        e.preventDefault();
    }
    function pointerUp() { active = null; }

    if (cropBox) {
        cropBox.addEventListener('mousedown',  pointerDown);
        cropBox.addEventListener('touchstart', pointerDown, { passive: false });
    }
    document.addEventListener('mousemove',  pointerMove);
    document.addEventListener('mouseup',    pointerUp);
    document.addEventListener('touchmove',  pointerMove, { passive: false });
    document.addEventListener('touchend',   pointerUp);

    /* Onayla → canvas crop */
    if (btnOk) btnOk.addEventListener('click', function () {
        var iw = cropSrc.offsetWidth,  ih = cropSrc.offsetHeight;
        var nw = cropSrc.naturalWidth, nh = cropSrc.naturalHeight;
        var sx = cs.x / iw * nw, sy = cs.y / ih * nh;
        var sw = cs.w / iw * nw, sh = cs.h / ih * nh;
        var cv = document.createElement('canvas');
        cv.width = Math.round(sw); cv.height = Math.round(sh);
        cv.getContext('2d').drawImage(cropSrc, sx, sy, sw, sh, 0, 0, cv.width, cv.height);
        var out = cv.toDataURL('image/jpeg', 0.92);
        try { localStorage.setItem(KEY, out); } catch(e) {}
        showPhoto(out);
        closeCrop();
    });
    if (btnNo) btnNo.addEventListener('click', closeCrop);

    /* Dosya seçilince crop aç */
    if (inp) inp.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        var rd = new FileReader();
        rd.onload = function (ev) { openCrop(ev.target.result); };
        rd.readAsDataURL(file);
        this.value = ''; /* tekrar aynı dosya seçilebilsin */
    });
})();
</script>

<?php if (!$print && $_can_unlock && $_is_locked): ?>
<script>
(function () {
    var btn = document.getElementById('rvUnlockBtn');
    if (!btn) return;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    btn.addEventListener('click', function () {
        var reason = prompt('Revizyon nedeni (zorunlu):');
        if (reason === null) return;
        reason = reason.trim();
        if (!reason) { alert('Revizyon nedeni boş bırakılamaz.'); return; }
        btn.disabled = true;
        fetch('record_durum.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=<?= (int)$id ?>&durum=islendi&revision_reason=' + encodeURIComponent(reason) + '&csrf=' + encodeURIComponent(csrf)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) { btn.disabled = false; alert(d.msg || 'Hata'); return; }
            location.reload();
        })
        .catch(function () { btn.disabled = false; alert('Bağlantı hatası.'); });
    });
})();
</script>
<?php endif; ?>

<?php render_footer($print); ?>
