<?php
// =========================================================
// malzeme_stok.php — Ambalaj / Malzeme Stok Takibi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/material_stock_helpers.php';
$auth_user = require_login();
require_perm('stok.read');

$pdo = db();

// ── URL oluşturucu — tek filtre çubuğu durumunu korur (Pro-01) ──
// Yalnızca güncel stok filtreleri: q / kategori / tur / depo / durum (+ csv).
// Tarih, hareket tipi ve eski ozet_* parametreleri kaldırıldı.
function ms_url(array $override = [], array $drop = []): string {
    global $f_q, $f_kategori, $f_tur, $f_depo, $f_durum;
    $base = [
        'q'        => $f_q        ?? '',
        'kategori' => $f_kategori ?? '',
        'tur'      => $f_tur      ?? '',
        'depo'     => $f_depo     ?? '',
        'durum'    => $f_durum    ?? '',
    ];
    foreach ($override as $k => $v) { $base[$k] = (string)$v; }
    foreach ($drop as $k) { unset($base[$k]); }
    return 'malzeme_stok.php' . (($q = array_filter($base, fn($v) => $v !== '')) ? '?' . http_build_query($q) : '');
}

// NOT (Pro-06): Teknik teşhis/audit panelleri ve onları besleyen sorgular
// (ms_audit_counts, audit_tbl_ms, veri kalite + sistem audit) bu sayfadan
// kaldırıldı. Tümü admin-only malzeme_stok_tehis.php sayfasında toplandı.
// Bu sayfa artık yalnızca günlük stok ekranı olarak çalışır.

// Tür/kategori sabitleri config/material_stock_helpers.php'den gelir
// (birim listesi yalnızca formlarda gerekli — o da malzeme_stok_islem.php'de)
$ms_types      = ms_material_types();
$ms_cat_labels = ms_cat_labels();

// NOT (Pro-03): Stok giriş / sevk / direkt düzeltme POST handler'ları ve
// formları malzeme_stok_islem.php'ye taşındı. Bu sayfa artık stok işlemi
// POST'u kabul etmez — yalnızca okuma/listeleme yapar.

// NOT (Pro-00): 'ms_duzeltme' (referanslı düzeltme) handler'ı kaldırıldı —
// hiçbir form bu action'ı göndermiyordu ve INSERT şemada olmayan `nota`
// kolonuna yazdığı için çalıştırılamazdı. Harekete bağlı düzeltme/iptal
// akışı Pro-04'te admin-only ekran olarak yeniden tasarlanacak.

// NOT (Pro-02): Hareket düzenle (ms_update) ve sil (ms_delete) POST
// handler'ları, hareket listesiyle birlikte malzeme_hareketleri.php'ye
// taşındı. Davranış (loading koruması, yetki, audit) aynen korundu.

// ── Filtreler — tek filtre çubuğu (Pro-01) ────────────────
// Tarih ve hareket tipi filtreleri kaldırıldı: "Kalan" daima GÜNCEL stok.
$f_q        = trim($_GET['q']    ?? '');
$f_kategori = trim($_GET['kategori'] ?? '');
if (!in_array($f_kategori, ['kasa', 'palet', 'sarf', 'diger', ''], true)) $f_kategori = '';
$f_tur      = trim($_GET['tur']  ?? '');
$f_depo     = trim($_GET['depo'] ?? '');
$f_durum    = trim($_GET['durum'] ?? '');
if (!in_array($f_durum, ['stokta', 'negatif', 'sifir', ''], true)) $f_durum = '';
$is_csv     = isset($_GET['csv']);

// ── Stok özeti — config/material_stock_helpers.php ────────
// Tarih GÖNDERİLMEZ → "Kalan" güncel stoktur. Tam set bir kez hesaplanır;
// kartlar/negatif bandı tam setten, tablo/CSV filtreli alt kümeden beslenir.
// KÖK BUGFIX helper içinde korunur (material_id bazlı GROUP BY).
$all_rows  = get_material_stock_summary($pdo, []);
$ozet_rows = ms_filter_summary_rows($all_rows, [
    'q'        => $f_q,
    'kategori' => $f_kategori,
    'tur'      => $f_tur,
    'depo'     => $f_depo,
    'durum'    => $f_durum,
]);

// ── Kart sayıları — tam setten (filtreden bağımsız genel stok sağlığı) ──
$card_toplam  = count($all_rows);
$card_stokta  = count(array_filter($all_rows, fn($r) => (float)$r['kalan'] > 0));
$card_negatif = count(array_filter($all_rows, fn($r) => (float)$r['kalan'] < 0));
$card_sifir   = count(array_filter($all_rows, fn($r) => (float)$r['kalan'] == 0.0));

// ── Dropdown listeleri (filtre çubuğu için: depo + ad datalist) ──
$ms_dd             = get_material_dropdown_data($pdo);
$depo_list         = $ms_dd['depo_list'];
$mat_names_by_type = $ms_dd['mat_names_by_type'];

$herhangi_filtre = $f_q !== '' || $f_kategori !== '' || $f_tur !== '' || $f_depo !== '' || $f_durum !== '';
$ms_can_write    = can('stok.write');

// ── CSV export — Stok Özeti (csv=ozet) ─────────────────────
// Tek filtre çubuğuyla aynı filtreli satırları verir (sayfalama yok).
if ($is_csv && ($_GET['csv'] ?? '') === 'ozet') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="malzeme_stok_ozet_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Kategori', 'Tür', 'Malzeme', 'Depo', 'Giriş', 'Sevk', 'Kullanım', 'Düzeltme', 'Kalan', 'Birim'], ';', '"', '\\');
    foreach ($ozet_rows as $r) {
        fputcsv($out, [
            $ms_cat_labels[$r['category']] ?? $r['category'],
            $ms_types[$r['material_type']] ?? $r['material_type'],
            $r['material_name'],
            $r['depo'] !== '' ? $r['depo'] : 'Depo Boş',
            number_format($r['total_giris'], 3, ',', '.'),
            number_format($r['total_sevk'], 3, ',', '.'),
            number_format($r['total_kullanim'], 3, ',', '.'),
            number_format($r['total_duzeltme'], 3, ',', '.'),
            number_format($r['kalan'], 3, ',', '.'),
            $r['unit'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// NOT (Pro-02): Hareket CSV (csv=1) export'u malzeme_hareketleri.php'ye taşındı.
// Bu sayfada yalnızca Stok Özeti CSV (csv=ozet) kaldı.

// NOT (Pro-06): Veri kalite + sistem audit kontrolleri (kasa/palet tipsiz,
// depo boş, miktar≤0, orphan, duplicate, sync eksik, pasif kullanım, negatif
// kasa/palet) admin-only malzeme_stok_tehis.php'ye taşındı. Bu sayfa artık
// teknik teşhis sorgularını ÇALIŞTIRMAZ — sayfa açılışı hafifledi.

// ── Satır bağlamı — tablo + mobil kart ortak (Pro-09) ─────
// Durum rozeti, çıkış/düzeltme ve hareketler/giriş linklerini tek yerde üretir;
// böylece masaüstü tablo ile mobil kart AYNI değerleri gösterir.
// material_id varsa ID bazlı link, yoksa name+type fallback (GROUP BY ile uyumlu).
$ms_row_ctx = function (array $oz): array {
    $kalan  = (float)$oz['kalan'];
    $is_neg = $kalan < 0;
    if ($is_neg)        { $durum_lbl = 'Negatif'; $durum_cls = 'badge-fire';    $durum_key = 'negatif'; }
    elseif ($kalan > 0) { $durum_lbl = 'Stokta';  $durum_cls = 'badge-gelen';   $durum_key = 'stokta';  }
    else                { $durum_lbl = 'Sıfır';   $durum_cls = 'badge-duzeltme';$durum_key = 'sifir';   }

    $_oz_mid    = $oz['material_id'] ?? null;
    $_oz_return = 'return=' . rawurlencode(ms_url()); // işlem sonrası filtreli sayfaya dön
    if ($_oz_mid !== null && (int)$_oz_mid > 0) {
        $oz_link  = ms_hareket_url(['mat_id' => (int)$_oz_mid, 'depo' => $oz['depo']]);
        $oz_giris = 'malzeme_stok_islem.php?' . http_build_query(['mode' => 'giris', 'material_id' => (int)$_oz_mid, 'depo' => $oz['depo']]) . '&' . $_oz_return;
    } else {
        $oz_link  = ms_hareket_url(['mat_type' => $oz['material_type'], 'mat_name' => $oz['material_name'], 'depo' => $oz['depo']]);
        $oz_giris = 'malzeme_stok_islem.php?' . http_build_query(['mode' => 'giris', 'mat_name' => $oz['material_name'], 'mat_type' => $oz['material_type'], 'depo' => $oz['depo']]) . '&' . $_oz_return;
    }
    return [
        'kalan'     => $kalan,
        'is_neg'    => $is_neg,
        'cikis'     => (float)$oz['total_cikis'],   // sevk + kullanım
        'duz'       => (float)$oz['total_duzeltme'],
        'durum_lbl' => $durum_lbl,
        'durum_cls' => $durum_cls,
        'durum_key' => $durum_key,
        'oz_link'   => $oz_link,
        'oz_giris'  => $oz_giris,
    ];
};

render_header('Malzeme Stok');
render_flash();
?>
<style>
@media print {
    .topbar,.bottomnav,.sidebar,.page-head,
    .ms-neg-uyari,.stok-ozet-grid,.ms-info-note,.ms-stock-cards { display:none !important; }
    .ms-stock-desk { display:block !important; }   /* yazdırmada daima tablo */
    .card:not(#ms-ozet-card) { display:none !important; }
    #ms-ozet-card { border:none !important; box-shadow:none !important; border-radius:0 !important; }
    .stok-table-head { border-bottom:2px solid #000 !important; padding:0 0 6px !important; background:none !important; }
    .ms-print-header { display:block !important; }
    .stok-hide-sm { display:table-cell !important; }
    .stok-table th:last-child,.stok-table td:last-child { display:none !important; }
    .stok-table { font-size:10pt; width:100%; border-collapse:collapse; }
    .stok-table th,.stok-table td { border:1px solid #bbb; padding:3px 5px; }
    .stok-table thead tr { background:#f0f0f0 !important; }
    .ms-cat-badge { border:1px solid #999 !important; background:#eee !important; color:#000 !important; }
    .container { padding:0 !important; margin:0 !important; max-width:none !important; }
    body { margin:0; padding:8px; }
    @page { margin:1.5cm; }
}
</style>
<?php
?>

<div class="page-head">
    <h2 class="page-title">🗃️ Malzeme Stok</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($ms_can_write): ?>
        <a href="malzeme_stok_islem.php?mode=giris" class="btn btn-sm btn-primary">➕ Stok İşlemi Yap</a>
        <?php endif; ?>
        <a href="malzeme_hareketleri.php" class="btn btn-sm btn-secondary">📜 Hareketler</a>
        <?php if ($ms_can_write): ?>
        <a href="malzeme_stok_import.php" class="btn btn-sm btn-ghost">📥 Excel Aktar</a>
        <?php endif; ?>
        <a href="<?= ms_url(['csv' => 'ozet']) ?>" class="btn btn-sm btn-ghost">⬇ Özet CSV</a>
        <a href="<?= h(str_replace('malzeme_stok.php', 'malzeme_stok_rapor.php', ms_url())) ?>" class="btn btn-sm btn-ghost">🖨️ Rapor / Yazdır</a>
        <?php if (is_admin()): ?>
        <a href="malzeme_stok_tehis.php" class="btn btn-sm btn-ghost" title="Veri kalite ve sistem audit kontrolleri (admin)">🔬 Teknik Teşhis</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($card_negatif > 0 && $f_durum !== 'negatif'): ?>
<!-- ── Negatif Stok Uyarısı (kısa) ─────────────────────────── -->
<div class="ms-neg-uyari" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div class="ms-neg-uyari-head" style="margin:0">⚠ Negatif stokta <?= $card_negatif ?> malzeme/depo var — çıkış girişten fazla olabilir.</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a href="<?= ms_url(['durum' => 'negatif']) ?>" class="btn btn-sm btn-danger" style="white-space:nowrap">Negatifleri Göster</a>
        <a href="malzeme_stok_rapor.php?durum=negatif" class="btn btn-sm btn-ghost" style="white-space:nowrap">🖨️ Negatif Raporu</a>
    </div>
</div>
<?php endif; ?>

<!-- ── Güncel stok bilgisi ─────────────────────────────────── -->
<p class="ms-info-note" style="font-size:.82rem;color:var(--muted);margin:0 0 12px">
    ℹ️ Bu ekran <strong>güncel stok durumunu</strong> gösterir. Tarih bazlı giriş/çıkış incelemek için
    <a href="malzeme_hareketleri.php">Hareketler</a> sayfasını kullanın.
</p>

<!-- ── Tek Filtre Çubuğu (Pro-01) ─────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="malzeme_stok.php" class="stok-filter-form">
        <div class="stok-filter-row">
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Ara</label>
                <input type="text" name="q" class="form-control" value="<?= h($f_q) ?>"
                    list="ms-filter-name-list" placeholder="Malzeme adı…" autocomplete="off">
                <datalist id="ms-filter-name-list">
                    <?php foreach (array_merge(...array_values($mat_names_by_type ?: [[]])) as $mn): ?>
                    <option value="<?= h($mn) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Kategori</label>
                <select name="kategori" class="form-control">
                    <option value="">Tümü</option>
                    <?php foreach ($ms_cat_labels as $ck => $cl): ?>
                    <option value="<?= h($ck) ?>" <?= $f_kategori === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Tür</label>
                <select name="tur" class="form-control">
                    <option value="">Tümü</option>
                    <?php foreach ($ms_types as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $f_tur === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">Tümü</option>
                    <?php foreach ($depo_list as $dv): ?>
                    <option value="<?= h($dv) ?>" <?= $f_depo === $dv ? 'selected' : '' ?>><?= h($dv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Durum</label>
                <select name="durum" class="form-control">
                    <option value="">Tümü</option>
                    <option value="stokta"  <?= $f_durum === 'stokta'  ? 'selected' : '' ?>>Stokta</option>
                    <option value="negatif" <?= $f_durum === 'negatif' ? 'selected' : '' ?>>Negatif</option>
                    <option value="sifir"   <?= $f_durum === 'sifir'   ? 'selected' : '' ?>>Sıfır</option>
                </select>
            </div>
        </div>
        <div class="stok-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <?php if ($herhangi_filtre): ?>
            <a href="malzeme_stok.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Özet kartları (Pro-01) — kalan bazlı, tıklanabilir filtre ── -->
<div class="stok-ozet-grid stok-ozet-grid-4">
    <a href="<?= ms_url([], ['durum']) ?>" class="stok-kart stok-kart-sayim" style="text-decoration:none;color:inherit">
        <div class="stok-kart-label">Toplam Malzeme</div>
        <div class="stok-kart-val"><?= $card_toplam ?></div>
        <div class="stok-kart-sub">farklı malzeme/depo</div>
    </a>
    <a href="<?= ms_url(['durum' => 'stokta']) ?>" class="stok-kart stok-kart-kalan" style="text-decoration:none;color:inherit">
        <div class="stok-kart-label">Stokta Olan</div>
        <div class="stok-kart-val"><?= $card_stokta ?></div>
        <div class="stok-kart-sub">kalan &gt; 0</div>
    </a>
    <a href="<?= ms_url(['durum' => 'negatif']) ?>" class="stok-kart <?= $card_negatif > 0 ? 'stok-kart-fire' : 'stok-kart-sayim' ?>" style="text-decoration:none;color:inherit">
        <div class="stok-kart-label">Negatif Stok<?= $card_negatif > 0 ? ' ⚠' : '' ?></div>
        <div class="stok-kart-val <?= $card_negatif > 0 ? 'stok-negatif' : '' ?>"><?= $card_negatif ?></div>
        <div class="stok-kart-sub">kalan &lt; 0</div>
    </a>
    <a href="<?= ms_url(['durum' => 'sifir']) ?>" class="stok-kart stok-kart-sayim" style="text-decoration:none;color:inherit">
        <div class="stok-kart-label">Sıfır Stok</div>
        <div class="stok-kart-val"><?= $card_sifir ?></div>
        <div class="stok-kart-sub">kalan = 0</div>
    </a>
</div>

<!-- ── Stok Özeti Tablosu ─────────────────────────────────── -->
<div class="card" id="ms-ozet-card" style="margin-top:16px;padding:0">
    <!-- Yazdırma başlığı — yalnızca @media print'te görünür -->
    <div class="ms-print-header" style="display:none;padding:0 14px 8px">
        <h2 style="margin:0 0 2px;font-size:14pt">Malzeme Stok Özeti (Güncel)</h2>
        <div style="font-size:9pt;color:#555">
            Tarih: <?= date('d.m.Y H:i') ?>
            <?php if ($f_q        !== ''): ?>&nbsp;·&nbsp; Ara: <?= h($f_q) ?><?php endif; ?>
            <?php if ($f_kategori !== ''): ?>&nbsp;·&nbsp; Kategori: <?= h($ms_cat_labels[$f_kategori] ?? $f_kategori) ?><?php endif; ?>
            <?php if ($f_tur      !== ''): ?>&nbsp;·&nbsp; Tür: <?= h($ms_types[$f_tur] ?? $f_tur) ?><?php endif; ?>
            <?php if ($f_depo     !== ''): ?>&nbsp;·&nbsp; Depo: <?= h($f_depo) ?><?php endif; ?>
            <?php if ($f_durum    !== ''): ?>&nbsp;·&nbsp; Durum: <?= h(['stokta'=>'Stokta','negatif'=>'Negatif','sifir'=>'Sıfır'][$f_durum] ?? $f_durum) ?><?php endif; ?>
            &nbsp;·&nbsp; <?= count($ozet_rows) ?> satır
        </div>
    </div>
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Güncel Stok</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($ozet_rows) ?> malzeme/depo<?= $herhangi_filtre ? ' (filtreli)' : '' ?></span>
    </div>
    <?php if (!empty($ozet_rows)): ?>
    <!-- ── Masaüstü tablo (≥768px) ──────────────────────────── -->
    <div class="table-wrap ms-stock-desk">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th class="stok-hide-sm">Tür</th>
                    <th>Malzeme</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th style="text-align:right">Giriş</th>
                    <th style="text-align:right;color:var(--danger)">Çıkış</th>
                    <th style="text-align:right;color:var(--warn)" class="stok-hide-sm">Düzeltme</th>
                    <th style="text-align:right">Kalan</th>
                    <th>Durum</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ozet_rows as $oz):
                $c         = $ms_row_ctx($oz);
                $kalan     = $c['kalan'];
                $is_neg    = $c['is_neg'];
                $kalan_cls = $is_neg ? 'stok-negatif' : ($kalan > 0 ? '' : 'color:var(--muted)');
                $duz       = $c['duz'];
                $cikis     = $c['cikis'];
            ?>
                <tr class="<?= $is_neg ? 'ms-row-negatif' : '' ?>">
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($ms_types[$oz['material_type']] ?? $oz['material_type']) ?></td>
                    <td style="font-weight:600">
                        <span class="ms-cat-badge ms-cat-<?= h($oz['category']) ?>" style="margin-right:5px"><?= h($ms_cat_labels[$oz['category']] ?? $oz['category']) ?></span>
                        <?= h($oz['material_name']) ?>
                        <?php if ((int)$oz['is_active'] === 0): ?><span style="font-size:.68rem;color:var(--muted)" title="Pasif tanım"> (pasif)</span><?php endif; ?>
                    </td>
                    <td class="stok-hide-sm"><?= $oz['depo'] !== '' ? h($oz['depo']) : '<span style="color:var(--muted)">Depo Boş</span>' ?></td>
                    <td style="text-align:right;color:var(--success)"><?= (float)$oz['total_giris'] > 0 ? '+' . number_format((float)$oz['total_giris'], 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= $cikis > 0 ? '−' . number_format($cikis, 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;color:var(--warn)" class="stok-hide-sm"><?= $duz != 0.0 ? ($duz > 0 ? '+' : '−') . number_format(abs($duz), 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;font-weight:700;<?= $kalan_cls ?>">
                        <?= number_format($kalan, 0, ',', '.') ?>
                        <small style="font-weight:400;color:var(--muted)"><?= h($oz['unit']) ?></small>
                    </td>
                    <td><span class="stok-badge <?= $c['durum_cls'] ?>"><?= $c['durum_lbl'] ?></span></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a href="<?= h($c['oz_link']) ?>" class="ms-edit-btn" title="Bu malzeme/deponun hareketlerini gör">🔍</a>
                        <?php if ($ms_can_write): ?>
                        <a href="<?= h($c['oz_giris']) ?>" class="ms-edit-btn" title="Bu malzemeye stok girişi ekle">➕</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Mobil kart listesi (<768px) ──────────────────────── -->
    <div class="ms-stock-cards">
        <?php foreach ($ozet_rows as $oz):
            $c       = $ms_row_ctx($oz);
            $kalan   = $c['kalan'];
            $is_neg  = $c['is_neg'];
            $duz     = $c['duz'];
            $cikis   = $c['cikis'];
            $giris   = (float)$oz['total_giris'];
            $kalan_color = $is_neg ? 'var(--danger)' : ($kalan > 0 ? 'var(--success)' : 'var(--muted)');
        ?>
        <div class="ms-scard ms-scard-<?= $c['durum_key'] ?>">
            <div class="ms-scard-top">
                <div class="ms-scard-name">
                    <?= h($oz['material_name']) ?>
                    <?php if ((int)$oz['is_active'] === 0): ?><span class="ms-scard-passive">PASİF</span><?php endif; ?>
                </div>
                <span class="stok-badge <?= $c['durum_cls'] ?>"><?= mb_strtoupper($c['durum_lbl'], 'UTF-8') ?></span>
            </div>
            <div class="ms-scard-meta">
                <span class="ms-cat-badge ms-cat-<?= h($oz['category']) ?>"><?= h($ms_cat_labels[$oz['category']] ?? $oz['category']) ?></span>
                <?= h($ms_types[$oz['material_type']] ?? $oz['material_type']) ?>
                · <?= $oz['depo'] !== '' ? h($oz['depo']) : 'Depo Boş' ?>
            </div>
            <div class="ms-scard-kalan" style="color:<?= $kalan_color ?>">
                <?= number_format($kalan, 0, ',', '.') ?>
                <small><?= h($oz['unit']) ?></small>
            </div>
            <div class="ms-scard-grid">
                <div><span>Giriş</span><b style="color:var(--success)"><?= $giris > 0 ? '+' . number_format($giris, 0, ',', '.') : '—' ?></b></div>
                <div><span>Çıkış</span><b style="color:var(--danger)"><?= $cikis > 0 ? '−' . number_format($cikis, 0, ',', '.') : '—' ?></b></div>
                <div><span>Düzeltme</span><b style="color:var(--warn)"><?= $duz != 0.0 ? ($duz > 0 ? '+' : '−') . number_format(abs($duz), 0, ',', '.') : '—' ?></b></div>
            </div>
            <div class="ms-scard-actions">
                <a href="<?= h($c['oz_link']) ?>" class="btn btn-sm btn-ghost">🔍 Hareketler</a>
                <?php if ($ms_can_write): ?>
                <a href="<?= h($c['oz_giris']) ?>" class="btn btn-sm btn-primary">➕ Giriş Ekle</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="padding:28px;text-align:center;color:var(--muted)">
        <?= $herhangi_filtre ? 'Filtre kriterlerine uygun malzeme bulunamadı.' : 'Henüz malzeme stok kaydı yok.' ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Alt eylem linkleri (formlar Pro-03'te, hareketler Pro-02'de ayrıldı) ── -->
<div style="margin-top:18px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
    <?php if ($ms_can_write): ?>
    <a href="malzeme_stok_islem.php?mode=giris" class="btn btn-primary">➕ Stok İşlemi Yap</a>
    <?php endif; ?>
    <a href="malzeme_hareketleri.php" class="btn btn-secondary">📜 Tüm Stok Hareketlerini Gör →</a>
</div>

<!-- Stok giriş/sevk/düzeltme formları Pro-03'te malzeme_stok_islem.php'ye taşındı. -->
<!-- Hareket listesi + düzenleme modalı Pro-02'de malzeme_hareketleri.php'ye taşındı. -->
<!-- Veri Kalite + Sistem Audit panelleri Pro-06'da malzeme_stok_tehis.php'ye taşındı. -->

<?php render_footer(); ?>
