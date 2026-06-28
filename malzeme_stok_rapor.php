<?php
// =========================================================
// malzeme_stok_rapor.php — Malzeme Stok Raporu (Pro-10)
// Güncel stok özeti/detay — sade, yazdırılabilir A4 çıktı.
// Filtreler malzeme_stok.php ile birebir aynı (q/kategori/tur/depo/durum).
// Stok hesabı get_material_stock_summary()'den gelir (material_id GROUP BY).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/material_stock_helpers.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_perm('stok.read');

$pdo = db();

$ms_types      = ms_material_types();
$ms_cat_labels = ms_cat_labels();

// ── Dropdown listeleri (filtre formu için) ─────────────────
$ms_dd     = get_material_dropdown_data($pdo);
$depo_list = $ms_dd['depo_list'];

// ── Filtreler — malzeme_stok.php ile aynı (tarih YOK: güncel stok) ──
$f_q               = trim($_GET['q'] ?? '');
$f_kategori        = trim($_GET['kategori'] ?? '');
if (!in_array($f_kategori, ['kasa', 'palet', 'sarf', 'diger', ''], true)) $f_kategori = '';
$f_tur             = trim($_GET['tur'] ?? '');
$f_depo            = trim($_GET['depo'] ?? '');
$f_durum           = trim($_GET['durum'] ?? '');
if (!in_array($f_durum, ['stokta', 'negatif', 'sifir', ''], true)) $f_durum = '';
$f_hide_empty_depo = (($_GET['hide_empty_depo'] ?? '') === '1');

$mode = print_mode(); // summary | detail (varsayılan summary)

// ── Stok özeti — ana sayfayla AYNI veri yolu ──────────────
$all_rows = get_material_stock_summary($pdo, []);
$rows = ms_filter_summary_rows($all_rows, [
    'q'        => $f_q,
    'kategori' => $f_kategori,
    'tur'      => $f_tur,
    'depo'     => $f_depo,
    'durum'    => $f_durum,
]);
if ($f_hide_empty_depo) {
    $rows = array_values(array_filter($rows, fn($r) => $r['depo'] !== ''));
}

$c_toplam = count($rows);

// summary → 5 kolon (portrait) · detail → 8 kolon (landscape)
$col_count   = $mode === 'detail' ? 8 : 5;
$orientation = print_orientation($mode, $col_count);

// ── Filtre özet metni (başlık altı) ───────────────────────
$filtre_parts = [];
if ($f_q               !== '') $filtre_parts[] = 'Ara: ' . $f_q;
if ($f_kategori        !== '') $filtre_parts[] = 'Kategori: ' . ($ms_cat_labels[$f_kategori] ?? $f_kategori);
if ($f_tur             !== '') $filtre_parts[] = 'Tür: ' . ($ms_types[$f_tur] ?? $f_tur);
if ($f_depo            !== '') $filtre_parts[] = 'Depo: ' . $f_depo;
if ($f_durum           !== '') $filtre_parts[] = 'Durum: ' . (['stokta' => 'Stokta', 'negatif' => 'Negatif', 'sifir' => 'Sıfır'][$f_durum] ?? $f_durum);
if ($f_hide_empty_depo)        $filtre_parts[] = 'Boş depo gizli';
$filtre_text = $filtre_parts ? implode(' · ', $filtre_parts) : 'Tüm güncel stok';

// ── URL üreticiler ────────────────────────────────────────
function rapor_url(array $override = []): string {
    global $f_q, $f_kategori, $f_tur, $f_depo, $f_durum, $f_hide_empty_depo, $mode;
    $base = [
        'mode'             => $mode,
        'q'                => $f_q,
        'kategori'         => $f_kategori,
        'tur'              => $f_tur,
        'depo'             => $f_depo,
        'durum'            => $f_durum,
        'hide_empty_depo'  => $f_hide_empty_depo ? '1' : '',
    ];
    foreach ($override as $k => $v) { $base[$k] = (string)$v; }
    return 'malzeme_stok_rapor.php' . (($q = array_filter($base, fn($v) => $v !== '')) ? '?' . http_build_query($q) : '');
}
$stok_q   = array_filter(['q' => $f_q, 'kategori' => $f_kategori, 'tur' => $f_tur, 'depo' => $f_depo, 'durum' => $f_durum], fn($v) => $v !== '');
$stok_url = 'malzeme_stok.php' . ($stok_q ? '?' . http_build_query($stok_q) : '');

render_print_page_start('Malzeme Stok Raporu', 'stok', $mode, $orientation);
?>
<style>
/* Ekran görünümü — yazdırmada print_base.css devralır (.no-print gizli) */
@media screen {
    .rapor-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
    .rapor-btn {
        display:inline-flex; align-items:center; gap:4px; padding:7px 13px;
        border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b;
        font-size:.85rem; font-weight:600; text-decoration:none; cursor:pointer;
    }
    .rapor-btn:hover { background:#f1f5f9; }
    .rapor-btn-primary { background:#1a73e8; border-color:#1a73e8; color:#fff; }
    .rapor-btn-active  { background:#eef2ff; border-color:#6366f1; color:#4338ca; }

    /* Filtre formu */
    .rapor-filter-card {
        border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc;
        padding:12px 14px; margin-bottom:14px;
    }
    .rapor-filter-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .rapor-fg { display:flex; flex-direction:column; gap:3px; min-width:130px; flex:1; }
    .rapor-fg label { font-size:.75rem; font-weight:600; color:#475569; }
    .rapor-fg select, .rapor-fg input[type=text] {
        border:1px solid #cbd5e1; border-radius:5px; padding:6px 8px;
        font-size:.83rem; background:#fff; color:#1e293b;
    }
    .rapor-fg-check { display:flex; align-items:center; gap:6px; font-size:.83rem; color:#374151; font-weight:500; padding-bottom:2px; }
    .rapor-fg-check input[type=checkbox] { width:16px; height:16px; accent-color:#6366f1; cursor:pointer; }
    .rapor-filter-actions { display:flex; gap:6px; align-items:flex-end; padding-bottom:1px; }

    .print-header { display:flex; justify-content:space-between; align-items:flex-start;
        border-bottom:2px solid #111; padding-bottom:8px; margin-bottom:12px; gap:12px; }
    .print-header-title { font-size:1.25rem; font-weight:800; }
    .print-header-sub   { font-size:.8rem; color:#555; margin-top:3px; }
    .print-header-meta  { text-align:right; font-size:.8rem; color:#555; }

    table.print-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    table.print-table th, table.print-table td { border:1px solid #cbd5e1; padding:5px 7px; text-align:left; }
    table.print-table th { background:#eef2f8; font-weight:700; }
    table.print-table td.num, table.print-table th.num { text-align:right; white-space:nowrap; }
    .rapor-table-wrap { overflow-x:auto; }

    /* Kalan sütunu — belirgin arka plan */
    table.print-table th.kalan-col { background:#dbeafe !important; color:#1e40af; border-left:2px solid #93c5fd; }
    table.print-table td.kalan-col { background:#eff6ff !important; border-left:2px solid #93c5fd; font-weight:700; }
    table.print-table td.kalan-col.kalan-neg { background:#fef2f2 !important; border-left:2px solid #fca5a5; }
    table.print-table td.kalan-col.kalan-sifir { background:#f9fafb !important; border-left:2px solid #d1d5db; color:#6b7280; }
}
/* Renk sınıfları (ekran + yazdırma) */
.kalan-neg  { color:#dc2626; font-weight:800; }
@media print {
    .kalan-neg { font-weight:bold; }
    tr.row-neg td { background:#fff5f5 !important; }
    table.print-table th.kalan-col { background:#dbeafe !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.print-table td.kalan-col { background:#eff6ff !important; border-left:2px solid #93c5fd; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.print-table td.kalan-col.kalan-neg { background:#fef2f2 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.print-table td.kalan-col.kalan-sifir { background:#f9fafb !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<div class="print-sheet">
    <!-- ── Ekran aksiyon çubuğu (yazdırmada gizli) ─────────── -->
    <div class="rapor-actions no-print">
        <button type="button" class="rapor-btn rapor-btn-primary" onclick="window.print()">🖨️ Yazdır</button>
        <a href="<?= h(rapor_url(['mode' => 'summary'])) ?>" class="rapor-btn<?= $mode === 'summary' ? ' rapor-btn-active' : '' ?>">📄 Özet</a>
        <a href="<?= h(rapor_url(['mode' => 'detail'])) ?>" class="rapor-btn<?= $mode === 'detail' ? ' rapor-btn-active' : '' ?>">📊 Detay</a>
        <a href="<?= h($stok_url) ?>" class="rapor-btn">← Ana Stoka Dön</a>
    </div>

    <!-- ── Filtre formu (sadece ekranda) ──────────────────────── -->
    <div class="rapor-filter-card no-print">
        <form method="get" action="malzeme_stok_rapor.php" class="rapor-filter-form">
            <input type="hidden" name="mode" value="<?= h($mode) ?>">
            <div class="rapor-fg">
                <label>Ara</label>
                <input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Malzeme adı…">
            </div>
            <div class="rapor-fg">
                <label>Kategori</label>
                <select name="kategori">
                    <option value="">Tümü</option>
                    <?php foreach ($ms_cat_labels as $ck => $cl): ?>
                    <option value="<?= h($ck) ?>" <?= $f_kategori === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rapor-fg">
                <label>Tür</label>
                <select name="tur">
                    <option value="">Tümü</option>
                    <?php foreach ($ms_types as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $f_tur === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rapor-fg">
                <label>Depo</label>
                <select name="depo">
                    <option value="">Tümü</option>
                    <?php foreach ($depo_list as $dv): ?>
                    <option value="<?= h($dv) ?>" <?= $f_depo === $dv ? 'selected' : '' ?>><?= h($dv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rapor-fg">
                <label>Durum</label>
                <select name="durum">
                    <option value="">Tümü</option>
                    <option value="stokta"  <?= $f_durum === 'stokta'  ? 'selected' : '' ?>>Stokta</option>
                    <option value="negatif" <?= $f_durum === 'negatif' ? 'selected' : '' ?>>Negatif</option>
                    <option value="sifir"   <?= $f_durum === 'sifir'   ? 'selected' : '' ?>>Sıfır</option>
                </select>
            </div>
            <label class="rapor-fg-check">
                <input type="checkbox" name="hide_empty_depo" value="1" <?= $f_hide_empty_depo ? 'checked' : '' ?>>
                Boş depoyu gizle
            </label>
            <div class="rapor-filter-actions">
                <button type="submit" style="padding:6px 14px;border:1px solid #1a73e8;border-radius:5px;background:#1a73e8;color:#fff;font-size:.83rem;font-weight:600;cursor:pointer">Filtrele</button>
                <a href="<?= h('malzeme_stok_rapor.php?mode=' . urlencode($mode)) ?>" style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:#374151;font-size:.83rem;font-weight:500;text-decoration:none">Temizle</a>
            </div>
        </form>
    </div>

    <?= render_print_header_html(
        'Malzeme Stok Raporu' . ($mode === 'detail' ? ' (Detay)' : ' (Özet)'),
        $filtre_text,
        'Rapor Tarihi: ' . date('d.m.Y H:i') . ' · ' . $c_toplam . ' satır'
    ) ?>

    <?php if (empty($rows)): ?>
    <p style="padding:24px;text-align:center;color:#64748b">Filtre kriterlerine uygun malzeme bulunamadı.</p>
    <?php else: ?>
    <div class="rapor-table-wrap">
        <table class="print-table">
            <thead>
                <tr>
                    <?php if ($mode === 'detail'): ?>
                    <th>Tür</th>
                    <th>Malzeme</th>
                    <th>Depo</th>
                    <th class="num">Giriş</th>
                    <th class="num">Sevk</th>
                    <th class="num">Kullanım</th>
                    <th class="num kalan-col">Kalan</th>
                    <th>Birim</th>
                    <?php else: ?>
                    <th>Malzeme</th>
                    <th>Tür</th>
                    <th>Depo</th>
                    <th class="num kalan-col">Kalan</th>
                    <th>Birim</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $kalan  = (float)$r['kalan'];
                $is_neg = $kalan < 0;
                $is_zero = $kalan == 0.0;
                $kalan_td_cls = 'num kalan-col' . ($is_neg ? ' kalan-neg' : ($is_zero ? ' kalan-sifir' : ''));
                $tur_lbl   = $ms_types[$r['material_type']] ?? $r['material_type'];
                $depo_lbl  = $r['depo'] !== '' ? $r['depo'] : 'Depo Boş';
                $kalan_str = number_format($kalan, 0, ',', '.');
            ?>
                <tr class="<?= $is_neg ? 'row-neg' : '' ?>">
                    <?php if ($mode === 'detail'):
                        $giris = (float)$r['total_giris'];
                        $sevk  = (float)$r['total_sevk'];
                        $kull  = (float)$r['total_kullanim'];
                    ?>
                    <td style="color:#555;font-size:.92em"><?= h($tur_lbl) ?></td>
                    <td><strong><?= h($r['material_name']) ?></strong><?= (int)$r['is_active'] === 0 ? ' <span style="font-size:.7em;color:#888">(pasif)</span>' : '' ?></td>
                    <td><?= h($depo_lbl) ?></td>
                    <td class="num"><?= $giris > 0 ? number_format($giris, 0, ',', '.') : '—' ?></td>
                    <td class="num"><?= $sevk  > 0 ? number_format($sevk, 0, ',', '.') : '—' ?></td>
                    <td class="num"><?= $kull  > 0 ? number_format($kull, 0, ',', '.') : '—' ?></td>
                    <td class="<?= $kalan_td_cls ?>"><?= $kalan_str ?></td>
                    <td><?= h($r['unit']) ?></td>
                    <?php else: ?>
                    <td><strong><?= h($r['material_name']) ?></strong><?= (int)$r['is_active'] === 0 ? ' <span style="font-size:.7em;color:#888">(pasif)</span>' : '' ?></td>
                    <td style="color:#555;font-size:.92em"><?= h($tur_lbl) ?></td>
                    <td><?= h($depo_lbl) ?></td>
                    <td class="<?= $kalan_td_cls ?>"><?= $kalan_str ?></td>
                    <td><?= h($r['unit']) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
