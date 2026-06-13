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

// Tür/birim/kategori sabitleri config/material_stock_helpers.php'den gelir
$ms_types      = ms_material_types();
$ms_units      = ms_stock_units();
$ms_cat_labels = ms_cat_labels();

// ── POST: Giriş / Sevk kaydet ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['ms_giris', 'ms_sevk'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

    $mv_type     = ($_POST['action'] === 'ms_sevk') ? 'sevk' : 'giris';
    $mv_date     = trim($_POST['mv_date']    ?? '');
    $mv_mat_type = trim($_POST['mv_mat_type'] ?? '');
    $mv_mat_name = trim($_POST['mv_mat_name'] ?? '');
    $mv_depo     = trim($_POST['mv_depo']    ?? '');
    $mv_qty      = num($_POST['mv_qty']      ?? '0');
    $mv_unit     = trim($_POST['mv_unit']    ?? 'adet');
    $mv_belge    = trim($_POST['mv_belge']   ?? '');
    $mv_firma    = trim($_POST['mv_firma']   ?? '');
    $mv_note     = trim($_POST['mv_note']    ?? '');

    $err = '';
    if (!$mv_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mv_date)) {
        $err = 'Tarih zorunludur (YYYY-AA-GG).';
    } elseif (!isset($ms_types[$mv_mat_type])) {
        $err = 'Malzeme türü seçiniz.';
    } elseif ($mv_mat_name === '') {
        $err = 'Malzeme adı zorunludur.';
    } elseif ($mv_depo === '') {
        $err = 'Depo seçimi zorunludur.';
    } elseif ($mv_qty <= 0) {
        $err = 'Miktar sıfırdan büyük olmalıdır.';
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $mat_row   = ms_find_material_definition($pdo, $mv_mat_type, $mv_mat_name);
        $mat_id    = $mat_row ? (int)$mat_row['id'] : null;
        $unit_dara = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;
        $total_dara = round($mv_qty * $unit_dara, 3);

        $pdo->prepare(
            "INSERT INTO material_stock_movements
             (movement_date, movement_type, material_id, material_name, material_type,
              depo, quantity, unit, unit_dara_kg, total_dara_kg, belge_no, firma, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $mv_date, $mv_type, $mat_id, $mv_mat_name, $mv_mat_type,
            $mv_depo, $mv_qty, $mv_unit, $unit_dara, $total_dara,
            $mv_belge, $mv_firma, $mv_note ?: null,
        ]);
        $mv_inserted_id = (int)$pdo->lastInsertId();
        audit_log_event('create', 'malzeme_stok', $mv_inserted_id, null, [
            'movement_type' => $mv_type,
            'material_id'   => $mat_id,
            'material_name' => $mv_mat_name,
            'material_type' => $mv_mat_type,
            'depo'          => $mv_depo,
            'quantity'      => $mv_qty,
            'unit'          => $mv_unit,
            'belge_no'      => $mv_belge,
            'firma'         => $mv_firma,
            'note'          => $mv_note,
        ]);

        $lbl = $mv_type === 'giris' ? 'Giriş' : 'Sevk çıkışı';
        set_flash('success', "$lbl kaydedildi: $mv_mat_name · " . fmt_kg($mv_qty) . " $mv_unit");
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── POST: Bağımsız Düzeltme ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_duzeltme_direkt') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

    $dz_date     = trim($_POST['dz_date']     ?? '');
    $dz_mat_type = trim($_POST['dz_mat_type'] ?? '');
    $dz_mat_name = trim($_POST['dz_mat_name'] ?? '');
    $dz_depo     = trim($_POST['dz_depo']     ?? '');
    $dz_qty_raw  = num($_POST['dz_qty']       ?? '0');
    $dz_yon      = trim($_POST['dz_yon']      ?? 'arti');
    $dz_unit     = trim($_POST['dz_unit']     ?? 'adet');
    $dz_belge    = trim($_POST['dz_belge']    ?? '');
    $dz_note     = trim($_POST['dz_note']     ?? '');
    $dz_qty      = $dz_yon === 'eksi' ? -abs($dz_qty_raw) : abs($dz_qty_raw);

    $err = '';
    if (!$dz_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dz_date)) {
        $err = 'Tarih zorunludur.';
    } elseif (!isset($ms_types[$dz_mat_type])) {
        $err = 'Malzeme türü seçiniz.';
    } elseif ($dz_mat_name === '') {
        $err = 'Malzeme adı zorunludur.';
    } elseif ($dz_depo === '') {
        $err = 'Depo seçimi zorunludur.';
    } elseif ($dz_qty_raw == 0.0) {
        $err = 'Miktar sıfır olamaz.';
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $mat_row   = ms_find_material_definition($pdo, $dz_mat_type, $dz_mat_name);
        $mat_id    = $mat_row ? (int)$mat_row['id'] : null;
        $unit_dara = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;

        $pdo->prepare(
            "INSERT INTO material_stock_movements
             (movement_date, movement_type, material_id, material_name, material_type,
              depo, quantity, unit, unit_dara_kg, total_dara_kg, belge_no, note)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $dz_date, 'duzeltme', $mat_id, $dz_mat_name, $dz_mat_type,
            $dz_depo, $dz_qty, $dz_unit, $unit_dara, round($dz_qty * $unit_dara, 3),
            $dz_belge, $dz_note ?: null,
        ]);
        $dz_inserted_id = (int)$pdo->lastInsertId();
        audit_log_event('create', 'malzeme_stok', $dz_inserted_id, null, [
            'movement_type' => 'duzeltme',
            'direction'     => $dz_yon,
            'material_id'   => $mat_id,
            'material_name' => $dz_mat_name,
            'material_type' => $dz_mat_type,
            'depo'          => $dz_depo,
            'quantity'      => $dz_qty,
            'unit'          => $dz_unit,
            'belge_no'      => $dz_belge,
            'note'          => $dz_note,
        ]);
        $lbl = $dz_yon === 'eksi' ? 'Eksi düzeltme' : 'Artı düzeltme';
        set_flash('success', "$lbl kaydedildi: $dz_mat_name · " . ($dz_qty >= 0 ? '+' : '') . fmt_kg($dz_qty) . ' ' . $dz_unit);
    }
    header('Location: malzeme_stok.php');
    exit;
}

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

// ── Dropdown listeleri ────────────────────────────────────
$ms_dd             = get_material_dropdown_data($pdo);
$depo_list         = $ms_dd['depo_list'];
$mat_names_by_type = $ms_dd['mat_names_by_type'];
$firma_list        = $ms_dd['firma_list'];

$herhangi_filtre = $f_q !== '' || $f_kategori !== '' || $f_tur !== '' || $f_depo !== '' || $f_durum !== '';

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

render_header('Malzeme Stok');
render_flash();
?>
<style>
@media print {
    .topbar,.bottomnav,.sidebar,.page-head,
    .ms-neg-uyari,.stok-ozet-grid,.ms-info-note,
    .ms-form-wrap { display:none !important; }
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
        <a href="malzeme_hareketleri.php" class="btn btn-sm btn-primary">📜 Hareketler</a>
        <a href="malzeme_stok_import.php" class="btn btn-sm btn-secondary">📥 Excel Aktar</a>
        <a href="<?= ms_url(['csv' => 'ozet']) ?>" class="btn btn-sm btn-ghost">⬇ Özet CSV</a>
        <button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">🖨 Yazdır</button>
        <?php if (is_admin()): ?>
        <a href="malzeme_stok_tehis.php" class="btn btn-sm btn-ghost" title="Veri kalite ve sistem audit kontrolleri (admin)">🔬 Teknik Teşhis</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($card_negatif > 0 && $f_durum !== 'negatif'): ?>
<!-- ── Negatif Stok Uyarısı (kısa) ─────────────────────────── -->
<div class="ms-neg-uyari" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div class="ms-neg-uyari-head" style="margin:0">⚠ Negatif stokta <?= $card_negatif ?> malzeme/depo var — çıkış girişten fazla olabilir.</div>
    <a href="<?= ms_url(['durum' => 'negatif']) ?>" class="btn btn-sm btn-danger" style="white-space:nowrap">Negatifleri Göster</a>
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
    <div class="table-wrap">
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
                $kalan     = (float)$oz['kalan'];
                $is_neg    = $kalan < 0;
                $kalan_cls = $is_neg ? 'stok-negatif' : ($kalan > 0 ? '' : 'color:var(--muted)');
                $duz       = (float)$oz['total_duzeltme'];
                $cikis     = (float)$oz['total_cikis']; // sevk + kullanım
                // Durum rozeti
                if ($is_neg)        { $durum_lbl = 'Negatif'; $durum_cls = 'badge-fire';   }
                elseif ($kalan > 0) { $durum_lbl = 'Stokta';  $durum_cls = 'badge-gelen';  }
                else                { $durum_lbl = 'Sıfır';   $durum_cls = 'badge-duzeltme';}
                // İsim değişikliğine dayanıklı: tanımlı malzemeler için ID, tanımsız için name+type.
                $_oz_mid = $oz['material_id'] ?? null;
                if ($_oz_mid !== null && (int)$_oz_mid > 0) {
                    $oz_link = ms_hareket_url(['mat_id' => (int)$_oz_mid, 'depo' => $oz['depo']]);
                } else {
                    $oz_link = ms_hareket_url(['mat_type' => $oz['material_type'], 'mat_name' => $oz['material_name'], 'depo' => $oz['depo']]);
                }
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
                    <td><span class="stok-badge <?= $durum_cls ?>"><?= $durum_lbl ?></span></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a href="<?= h($oz_link) ?>" class="ms-edit-btn" title="Bu malzeme/deponun hareketlerini gör">🔍</a>
                        <a href="malzeme_stok.php#stok-giris" class="ms-edit-btn" title="Stok girişi ekle">➕</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:28px;text-align:center;color:var(--muted)">
        <?= $herhangi_filtre ? 'Filtre kriterlerine uygun malzeme bulunamadı.' : 'Henüz malzeme stok kaydı yok.' ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Giriş / Sevk / Düzeltme Formu ─────────────────────── -->
<div id="stok-giris" style="scroll-margin-top:16px"></div>
<div class="ms-form-wrap" style="margin-top:20px">
    <div class="ms-form-tabs">
        <button type="button" class="ms-tab-btn ms-tab-active" id="tabGiris" onclick="msTab('giris')">
            ➕ Giriş Ekle
        </button>
        <button type="button" class="ms-tab-btn" id="tabSevk" onclick="msTab('sevk')">
            ↗ Sevk Çıkışı
        </button>
        <button type="button" class="ms-tab-btn" id="tabDuzeltme" onclick="msTab('duzeltme')">
            ± Düzeltme
        </button>
    </div>

    <!-- Giriş formu -->
    <div id="msFormGiris" class="card ms-form-card ms-action-card">
        <form method="post" action="malzeme_stok.php" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_giris">
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="mv_date" class="form-control" required
                           value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="mv_mat_type" class="form-control" id="girisMatType" required onchange="msUpdateNames('giris')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="mv_mat_name" id="girisMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="mv_depo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="mv_qty" class="form-control" required
                           min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="mv_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / İrsaliye No</label>
                    <input type="text" name="mv_belge" class="form-control" placeholder="İsteğe bağlı">
                </div>
                <div class="form-group">
                    <label class="form-label">Tedarikçi / Firma</label>
                    <input type="text" name="mv_firma" class="form-control"
                           list="ms-firma-list" placeholder="İsteğe bağlı" autocomplete="off">
                    <datalist id="ms-firma-list">
                        <?php foreach ($firma_list as $fv): ?>
                        <option value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-primary">💾 Girişi Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Sevk formu -->
    <div id="msFormSevk" class="card ms-form-card ms-action-card" hidden>
        <form method="post" action="malzeme_stok.php" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_sevk">
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="mv_date" class="form-control" required
                           value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="mv_mat_type" class="form-control" id="sevkMatType" required onchange="msUpdateNames('sevk')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="mv_mat_name" id="sevkMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="mv_depo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="mv_qty" class="form-control" required
                           min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="mv_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / İrsaliye No</label>
                    <input type="text" name="mv_belge" class="form-control" placeholder="İsteğe bağlı">
                </div>
                <div class="form-group">
                    <label class="form-label">Gönderilen Firma</label>
                    <input type="text" name="mv_firma" class="form-control"
                           list="ms-firma-list2" placeholder="İsteğe bağlı" autocomplete="off">
                    <datalist id="ms-firma-list2">
                        <?php foreach ($firma_list as $fv): ?>
                        <option value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-danger">↗ Sevki Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Düzeltme formu -->
    <div id="msFormDuzeltme" class="card ms-form-card ms-action-card" hidden>
        <p style="font-size:.84rem;color:var(--muted);margin-bottom:12px">
            Stok sayım farkını veya hatalı girişi düzeltmek için kullanın.
            <strong>Artı düzeltme</strong> stoka ekler, <strong>eksi düzeltme</strong> stoktan çıkarır.
        </p>
        <form method="post" action="malzeme_stok.php" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_duzeltme_direkt">
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="dz_date" class="form-control" required
                           value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="dz_mat_type" class="form-control" id="duzeltmeMatType" required onchange="msUpdateNames('duzeltme')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="dz_mat_name" id="duzeltmeMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="dz_depo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="dz_qty" class="form-control" required
                           min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="dz_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Yön <span class="req">*</span></label>
                    <select name="dz_yon" class="form-control" required>
                        <option value="arti">+ Artı düzeltme (stoka ekle)</option>
                        <option value="eksi">− Eksi düzeltme (stoktan çıkar)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / Açıklama</label>
                    <input type="text" name="dz_belge" class="form-control" placeholder="İsteğe bağlı">
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="dz_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-secondary">± Düzeltmeyi Kaydet</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Hareket geçmişi linki (liste Pro-02'de malzeme_hareketleri.php'ye taşındı) ── -->
<div style="margin-top:18px;text-align:center">
    <a href="malzeme_hareketleri.php" class="btn btn-secondary">📜 Tüm Stok Hareketlerini Gör →</a>
</div>

<!-- Hareket listesi + düzenleme modalı Pro-02'de malzeme_hareketleri.php'ye taşındı. -->
<!-- Veri Kalite + Sistem Audit panelleri Pro-06'da malzeme_stok_tehis.php'ye taşındı. -->

<script>
var msNamesData = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;

function msUpdateNames(form) {
    var typeId  = form === 'giris' ? 'girisMatType' : (form === 'sevk' ? 'sevkMatType' : 'duzeltmeMatType');
    var nameId  = form === 'giris' ? 'girisMatName' : (form === 'sevk' ? 'sevkMatName' : 'duzeltmeMatName');
    var typeSel = document.getElementById(typeId);
    var nameSel = document.getElementById(nameId);
    if (!typeSel || !nameSel) return;
    var names = msNamesData[typeSel.value] || [];
    nameSel.innerHTML = '<option value="">— seçiniz —</option>';
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n; opt.textContent = n;
        nameSel.appendChild(opt);
    });
    nameSel.disabled = names.length === 0;
}

function msTab(tab) {
    var tabs = ['giris', 'sevk', 'duzeltme'];
    tabs.forEach(function(t) {
        var cap = t.charAt(0).toUpperCase() + t.slice(1);
        var el  = document.getElementById('msForm'  + cap);
        var btn = document.getElementById('tab'     + cap);
        if (el)  el.hidden = (t !== tab);
        if (btn) btn.classList.toggle('ms-tab-active', t === tab);
    });
}
// Hareket düzenleme modalı JS'i Pro-02'de malzeme_hareketleri.php'ye taşındı.
</script>

<?php render_footer(); ?>
