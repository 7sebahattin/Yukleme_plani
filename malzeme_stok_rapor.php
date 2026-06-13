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

// ── Filtreler — malzeme_stok.php ile aynı (tarih YOK: güncel stok) ──
$f_q        = trim($_GET['q'] ?? '');
$f_kategori = trim($_GET['kategori'] ?? '');
if (!in_array($f_kategori, ['kasa', 'palet', 'sarf', 'diger', ''], true)) $f_kategori = '';
$f_tur      = trim($_GET['tur'] ?? '');
$f_depo     = trim($_GET['depo'] ?? '');
$f_durum    = trim($_GET['durum'] ?? '');
if (!in_array($f_durum, ['stokta', 'negatif', 'sifir', ''], true)) $f_durum = '';

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

// ── Toplamlar — filtreli set üzerinden (ek SQL yok) ───────
$c_toplam  = count($rows);
$c_stokta  = count(array_filter($rows, fn($r) => (float)$r['kalan'] > 0));
$c_negatif = count(array_filter($rows, fn($r) => (float)$r['kalan'] < 0));
$c_sifir   = count(array_filter($rows, fn($r) => (float)$r['kalan'] == 0.0));

// summary → 6 kolon (portrait) · detail → 10 kolon (landscape)
$col_count   = $mode === 'detail' ? 10 : 6;
$orientation = print_orientation($mode, $col_count);

// ── Filtre özet metni (başlık altı) ───────────────────────
$filtre_parts = [];
if ($f_q        !== '') $filtre_parts[] = 'Ara: ' . $f_q;
if ($f_kategori !== '') $filtre_parts[] = 'Kategori: ' . ($ms_cat_labels[$f_kategori] ?? $f_kategori);
if ($f_tur      !== '') $filtre_parts[] = 'Tür: ' . ($ms_types[$f_tur] ?? $f_tur);
if ($f_depo     !== '') $filtre_parts[] = 'Depo: ' . $f_depo;
if ($f_durum    !== '') $filtre_parts[] = 'Durum: ' . (['stokta' => 'Stokta', 'negatif' => 'Negatif', 'sifir' => 'Sıfır'][$f_durum] ?? $f_durum);
$filtre_text = $filtre_parts ? implode(' · ', $filtre_parts) : 'Tüm güncel stok';

// ── URL üreticiler ────────────────────────────────────────
function rapor_url(array $override = []): string {
    global $f_q, $f_kategori, $f_tur, $f_depo, $f_durum, $mode;
    $base = ['mode' => $mode, 'q' => $f_q, 'kategori' => $f_kategori, 'tur' => $f_tur, 'depo' => $f_depo, 'durum' => $f_durum];
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
    .rapor-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
    .rapor-btn {
        display:inline-flex; align-items:center; gap:4px; padding:7px 13px;
        border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b;
        font-size:.85rem; font-weight:600; text-decoration:none; cursor:pointer;
    }
    .rapor-btn:hover { background:#f1f5f9; }
    .rapor-btn-primary { background:#1a73e8; border-color:#1a73e8; color:#fff; }
    .rapor-btn-active  { background:#eef2ff; border-color:#6366f1; color:#4338ca; }

    .print-header { display:flex; justify-content:space-between; align-items:flex-start;
        border-bottom:2px solid #111; padding-bottom:8px; margin-bottom:12px; gap:12px; }
    .print-header-title { font-size:1.25rem; font-weight:800; }
    .print-header-sub   { font-size:.8rem; color:#555; margin-top:3px; }
    .print-header-meta  { text-align:right; font-size:.8rem; color:#555; }

    .print-summary-row { display:flex; gap:10px; flex-wrap:wrap; margin:0 0 14px; }
    .print-summary-box { flex:1; min-width:90px; border:1.5px solid #d1d5db; border-radius:6px; padding:8px 10px; text-align:center; }
    .print-summary-box .psb-label { font-size:.66rem; text-transform:uppercase; letter-spacing:.4px; color:#64748b; }
    .print-summary-box .psb-value { font-size:1.3rem; font-weight:800; color:#111; }
    .psb-neg .psb-value { color:#dc2626; }

    table.print-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    table.print-table th, table.print-table td { border:1px solid #cbd5e1; padding:5px 7px; text-align:left; }
    table.print-table th { background:#eef2f8; font-weight:700; }
    table.print-table td.num, table.print-table th.num { text-align:right; white-space:nowrap; }
    .rapor-table-wrap { overflow-x:auto; }
}
/* Durum etiketi renkleri (ekran + yazdırma) */
.dz-negatif { color:#dc2626; font-weight:700; }
.dz-sifir   { color:#6b7280; }
.dz-stokta  { color:#16a34a; font-weight:700; }
.kalan-neg  { color:#dc2626; font-weight:800; }
@media print {
    .dz-negatif, .kalan-neg { font-weight:bold; }
    tr.row-neg td { background:#fff5f5 !important; }
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

    <?= render_print_header_html(
        'Malzeme Stok Raporu' . ($mode === 'detail' ? ' (Detay)' : ' (Özet)'),
        $filtre_text,
        'Rapor Tarihi: ' . date('d.m.Y H:i') . ' · ' . $c_toplam . ' satır'
    ) ?>

    <!-- ── Özet kutuları (birim karıştırmayan sayı kartları) ── -->
    <div class="print-summary-row">
        <div class="print-summary-box"><div class="psb-label">Toplam</div><div class="psb-value"><?= $c_toplam ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Stokta</div><div class="psb-value"><?= $c_stokta ?></div></div>
        <div class="print-summary-box psb-neg"><div class="psb-label">Negatif</div><div class="psb-value"><?= $c_negatif ?></div></div>
        <div class="print-summary-box"><div class="psb-label">Sıfır</div><div class="psb-value"><?= $c_sifir ?></div></div>
    </div>

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
                    <th class="num">Düzeltme</th>
                    <th class="num">Kalan</th>
                    <th>Birim</th>
                    <th>Durum</th>
                    <?php else: ?>
                    <th>Malzeme</th>
                    <th>Tür</th>
                    <th>Depo</th>
                    <th class="num">Kalan</th>
                    <th>Birim</th>
                    <th>Durum</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $kalan  = (float)$r['kalan'];
                $is_neg = $kalan < 0;
                if ($is_neg)        { $dz_lbl = 'NEGATİF'; $dz_cls = 'dz-negatif'; }
                elseif ($kalan > 0) { $dz_lbl = 'STOKTA';  $dz_cls = 'dz-stokta';  }
                else                { $dz_lbl = 'SIFIR';   $dz_cls = 'dz-sifir';   }
                $tur_lbl  = $ms_types[$r['material_type']] ?? $r['material_type'];
                $depo_lbl = $r['depo'] !== '' ? $r['depo'] : 'Depo Boş';
                $kalan_str = number_format($kalan, 0, ',', '.');
            ?>
                <tr class="<?= $is_neg ? 'row-neg' : '' ?>">
                    <?php if ($mode === 'detail'):
                        $giris = (float)$r['total_giris'];
                        $sevk  = (float)$r['total_sevk'];
                        $kull  = (float)$r['total_kullanim'];
                        $duz   = (float)$r['total_duzeltme'];
                    ?>
                    <td style="color:#555;font-size:.92em"><?= h($tur_lbl) ?></td>
                    <td><strong><?= h($r['material_name']) ?></strong><?= (int)$r['is_active'] === 0 ? ' <span style="font-size:.7em;color:#888">(pasif)</span>' : '' ?></td>
                    <td><?= h($depo_lbl) ?></td>
                    <td class="num"><?= $giris > 0 ? number_format($giris, 0, ',', '.') : '—' ?></td>
                    <td class="num"><?= $sevk  > 0 ? number_format($sevk, 0, ',', '.') : '—' ?></td>
                    <td class="num"><?= $kull  > 0 ? number_format($kull, 0, ',', '.') : '—' ?></td>
                    <td class="num"><?= $duz != 0.0 ? ($duz > 0 ? '+' : '−') . number_format(abs($duz), 0, ',', '.') : '—' ?></td>
                    <td class="num <?= $is_neg ? 'kalan-neg' : '' ?>"><?= $kalan_str ?></td>
                    <td><?= h($r['unit']) ?></td>
                    <td class="<?= $dz_cls ?>"><?= $dz_lbl ?></td>
                    <?php else: ?>
                    <td><strong><?= h($r['material_name']) ?></strong><?= (int)$r['is_active'] === 0 ? ' <span style="font-size:.7em;color:#888">(pasif)</span>' : '' ?></td>
                    <td style="color:#555;font-size:.92em"><?= h($tur_lbl) ?></td>
                    <td><?= h($depo_lbl) ?></td>
                    <td class="num <?= $is_neg ? 'kalan-neg' : '' ?>"><?= $kalan_str ?></td>
                    <td><?= h($r['unit']) ?></td>
                    <td class="<?= $dz_cls ?>"><?= $dz_lbl ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
