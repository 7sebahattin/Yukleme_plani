<?php
// =========================================================
// malzeme_stok.php — Ambalaj / Malzeme Stok Takibi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('stok.read');

$pdo = db();

// ── URL oluşturucu — filtre durumunu korur ────────────────
function ms_url(array $override = [], array $drop = []): string {
    global $f_tarih_bas, $f_tarih_bit, $f_mat_type, $f_mat_name, $f_depo,
           $f_hareket_tipi, $hareket_page;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
        'mat_type'     => $f_mat_type     ?? '',
        'mat_name'     => $f_mat_name     ?? '',
        'depo'         => $f_depo         ?? '',
        'hareket_tipi' => $f_hareket_tipi ?? '',
        'hareket_page' => (isset($hareket_page) && $hareket_page > 1) ? (string)$hareket_page : '',
    ];
    foreach ($override as $k => $v) { $base[$k] = (string)$v; }
    foreach ($drop as $k) { unset($base[$k]); }
    return 'malzeme_stok.php' . (($q = array_filter($base, fn($v) => $v !== '')) ? '?' . http_build_query($q) : '');
}

// ── Audit özet ───────────────────────────────────────────
function ms_audit_counts(PDO $pdo): array {
    $r = ['orphan' => 0, 'dup_exact' => 0, 'dup_norm' => 0, 'total' => 0];
    try {
        if (audit_tbl_ms('material_stock_movements') && audit_tbl_ms('loading_records')) {
            $r['orphan'] = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements m
                WHERE m.source_type='loading' AND m.source_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id=m.source_id)"
            )->fetchColumn();
        }
        if (audit_tbl_ms('material_definitions')) {
            $r['dup_exact'] = (int)$pdo->query("SELECT COUNT(*) FROM (
                SELECT 1 FROM material_definitions GROUP BY type, name HAVING COUNT(*) > 1
            ) x")->fetchColumn();
            // normalize_text_v2 tabanlı duplicate kontrolü — LOWER kullanmıyor
            $defs = $pdo->query("SELECT type, name FROM material_definitions")->fetchAll();
            $norm_groups = [];
            foreach ($defs as $_d) {
                $key = $_d['type'] . '::' . normalize_text_v2($_d['name']);
                $norm_groups[$key] = ($norm_groups[$key] ?? 0) + 1;
            }
            $r['dup_norm'] = count(array_filter($norm_groups, fn($c) => $c > 1));
        }
        $r['total'] = $r['orphan'] + $r['dup_exact'] + $r['dup_norm'];
    } catch (PDOException $e) {}
    return $r;
}
function audit_tbl_ms(string $t): bool {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

// Material types tracked in this module (all except firma/depo/bolge/urun)
function ms_material_types(): array {
    $skip = ['firma', 'depo', 'bolge', 'urun'];
    return array_filter(definition_types(), fn($k) => !in_array($k, $skip), ARRAY_FILTER_USE_KEY);
}

$ms_types = ms_material_types();
$ms_units = ['adet', 'kg', 'm', 'm²', 'paket', 'rulo', 'top', 'çift', 'set'];

// ── POST: Giriş / Sevk kaydet ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['ms_giris', 'ms_sevk'], true)) {
    csrf_check($_POST['csrf'] ?? null);

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
    } elseif ($mv_qty <= 0) {
        $err = 'Miktar sıfırdan büyük olmalıdır.';
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $_def_st = $pdo->prepare("SELECT id, name, unit_dara_kg FROM material_definitions WHERE type=?");
        $_def_st->execute([$mv_mat_type]);
        $_def_needle = normalize_text_v2($mv_mat_name);
        $mat_row = null;
        foreach ($_def_st->fetchAll() as $_def_r) {
            if (normalize_text_v2($_def_r['name']) === $_def_needle) { $mat_row = $_def_r; break; }
        }
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

        $lbl = $mv_type === 'giris' ? 'Giriş' : 'Sevk çıkışı';
        set_flash('success', "$lbl kaydedildi: $mv_mat_name · " . fmt_kg($mv_qty) . " $mv_unit");
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── POST: Bağımsız Düzeltme ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_duzeltme_direkt') {
    csrf_check($_POST['csrf'] ?? null);

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
    } elseif ($dz_qty_raw == 0.0) {
        $err = 'Miktar sıfır olamaz.';
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $_def_st = $pdo->prepare("SELECT id, name, unit_dara_kg FROM material_definitions WHERE type=?");
        $_def_st->execute([$dz_mat_type]);
        $_def_needle = normalize_text_v2($dz_mat_name);
        $mat_row = null;
        foreach ($_def_st->fetchAll() as $_def_r) {
            if (normalize_text_v2($_def_r['name']) === $_def_needle) { $mat_row = $_def_r; break; }
        }
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
            $dz_belge ?: null, $dz_note ?: null,
        ]);
        $lbl = $dz_yon === 'eksi' ? 'Eksi düzeltme' : 'Artı düzeltme';
        set_flash('success', "$lbl kaydedildi: $dz_mat_name · " . ($dz_qty >= 0 ? '+' : '') . fmt_kg($dz_qty) . ' ' . $dz_unit);
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── POST: Referans Düzeltme (mevcut harekete bağlı) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_duzeltme') {
    csrf_check($_POST['csrf'] ?? null);

    $dz_id   = (int)($_POST['dz_id']  ?? 0);
    $dz_qty  = num($_POST['dz_qty']   ?? '0');
    $dz_note = trim($_POST['dz_note'] ?? '');

    if ($dz_id <= 0) {
        set_flash('error', 'Geçersiz kayıt.');
    } elseif ($dz_qty == 0.0) {
        set_flash('error', 'Düzeltme miktarı sıfır olamaz.');
    } else {
        $base = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $base->execute([$dz_id]);
        $base = $base->fetch();
        if (!$base) {
            set_flash('error', 'Kayıt bulunamadı.');
        } else {
            $pdo->prepare(
                "INSERT INTO material_stock_movements
                 (movement_date, movement_type, material_id, material_name, material_type,
                  depo, quantity, unit, source_type, source_id, nota)
                 VALUES (CURDATE(),'duzeltme',?,?,?,?,?,?,'duzeltme',?,?)"
            )->execute([
                $base['material_id'], $base['material_name'], $base['material_type'],
                $base['depo'], $dz_qty, $base['unit'], $dz_id, $dz_note ?: null,
            ]);
            set_flash('success', 'Düzeltme kaydedildi: ' . ($dz_qty > 0 ? '+' : '') . fmt_kg($dz_qty) . ' ' . $base['unit']);
        }
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── POST: Sil ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_delete') {
    csrf_check($_POST['csrf'] ?? null);
    $del_id = (int)($_POST['del_id'] ?? 0);
    if ($del_id > 0) {
        $row = $pdo->prepare("SELECT source_type FROM material_stock_movements WHERE id=? LIMIT 1");
        $row->execute([$del_id]);
        $row = $row->fetch();
        if ($row && $row['source_type'] === 'loading') {
            set_flash('error', 'Yükleme kaynaklı kullanım hareketleri silinemez.');
        } elseif ($row) {
            $pdo->prepare("DELETE FROM material_stock_movements WHERE id=?")->execute([$del_id]);
            set_flash('success', 'Hareket silindi (#' . $del_id . ').');
        }
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── Filtreler ─────────────────────────────────────────────
$f_tarih_bas    = trim($_GET['tarih_bas']    ?? '');
$f_tarih_bit    = trim($_GET['tarih_bit']    ?? '');
$f_mat_type     = trim($_GET['mat_type']     ?? '');
$f_mat_name     = trim($_GET['mat_name']     ?? '');
$f_depo         = trim($_GET['depo']         ?? '');
$is_csv         = isset($_GET['csv']);
$f_hareket_tipi = trim($_GET['hareket_tipi'] ?? '');
if (!in_array($f_hareket_tipi, ['giris', 'sevk', 'kullanim', 'duzeltme', ''], true)) $f_hareket_tipi = '';

// ── WHERE builder — özet sorgusu (hareket tipi hariç) ─────
$where = []; $params = [];
if ($f_tarih_bas !== '') { $where[] = "movement_date >= ?"; $params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $where[] = "movement_date <= ?"; $params[] = $f_tarih_bit; }
if ($f_mat_type  !== '') { $where[] = "material_type = ?";  $params[] = $f_mat_type; }
if ($f_mat_name  !== '') { $where[] = "material_name LIKE ?"; $params[] = '%' . $f_mat_name . '%'; }
if ($f_depo      !== '') { $where[] = "depo LIKE ?";          $params[] = '%' . $f_depo . '%'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// WHERE builder — hareket listesi (+ hareket tipi filtresi)
$where_mv = $where; $params_mv = $params;
if ($f_hareket_tipi !== '') { $where_mv[] = "movement_type = ?"; $params_mv[] = $f_hareket_tipi; }
$where_mv_sql = $where_mv ? 'WHERE ' . implode(' AND ', $where_mv) : '';

// ── Stok özeti ────────────────────────────────────────────
$ozet_rows = [];
try {
    $st = $pdo->prepare("
        SELECT material_type, material_name, depo, unit,
            SUM(CASE WHEN movement_type='giris' THEN quantity ELSE 0 END) AS total_giris,
            SUM(CASE WHEN movement_type IN ('sevk','kullanim') THEN quantity ELSE 0 END) AS total_cikis,
            SUM(CASE WHEN movement_type='duzeltme' THEN quantity ELSE 0 END) AS total_duzeltme,
            SUM(CASE WHEN movement_type='giris' THEN quantity
                     WHEN movement_type IN ('sevk','kullanim') THEN -quantity
                     WHEN movement_type='duzeltme' THEN quantity
                     ELSE 0 END) AS kalan
        FROM material_stock_movements
        $where_sql
        GROUP BY material_type, material_name, depo, unit
        ORDER BY material_type, material_name, depo
    ");
    $st->execute($params);
    $ozet_rows = $st->fetchAll();
} catch (PDOException $e) {}

// Negatif stok satırları (uyarı için)
$negatif_ozet = array_slice(
    array_values(array_filter($ozet_rows, fn($r) => (float)$r['kalan'] < 0)),
    0, 10
);

// ── Hareket listesi ───────────────────────────────────────
$hareket_rows = [];
try {
    $st = $pdo->prepare("
        SELECT id, movement_date, movement_type, material_type, material_name,
               depo, quantity, unit, source_type, source_id,
               belge_no, firma, note
        FROM material_stock_movements
        $where_mv_sql
        ORDER BY movement_date DESC, id DESC
        LIMIT 2000
    ");
    $st->execute($params_mv);
    $hareket_rows = $st->fetchAll();
} catch (PDOException $e) {}

$hareket_total = count($hareket_rows);

// ── Dropdown listeleri ────────────────────────────────────
$depo_list = [];
try {
    $depo_list = $pdo->query(
        "SELECT name FROM material_definitions WHERE type='depo' AND is_active=1 ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$mat_names_by_type = [];
try {
    foreach ($pdo->query("SELECT type, name FROM material_definitions WHERE is_active=1 ORDER BY type, name")->fetchAll() as $d) {
        if (isset($ms_types[$d['type']])) {
            $mat_names_by_type[$d['type']][] = $d['name'];
        }
    }
} catch (PDOException $e) {}

// Also collect names from existing movements (for datalist completeness)
try {
    $existing = $pdo->query("SELECT DISTINCT material_type, material_name FROM material_stock_movements WHERE material_name != '' ORDER BY material_type, material_name")->fetchAll();
    foreach ($existing as $e) {
        if (!isset($mat_names_by_type[$e['material_type']])) $mat_names_by_type[$e['material_type']] = [];
        if (!in_array($e['material_name'], $mat_names_by_type[$e['material_type']], true)) {
            $mat_names_by_type[$e['material_type']][] = $e['material_name'];
        }
    }
} catch (PDOException $e2) {}

$firma_list = [];
try {
    $firma_list = $pdo->query("SELECT name FROM material_definitions WHERE type='firma' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

$herhangi_filtre = $f_tarih_bas !== '' || $f_tarih_bit !== '' || $f_mat_type !== '' || $f_mat_name !== '' || $f_depo !== '' || $f_hareket_tipi !== '';

// ── CSV export ────────────────────────────────────────────
if ($is_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="malzeme_stok_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tarih', 'Hareket', 'Malzeme Türü', 'Malzeme', 'Depo', 'Miktar', 'Birim', 'Kaynak', 'Belge No', 'Firma', 'Not'], ';', '"', '\\');
    foreach ($hareket_rows as $r) {
        $type_lbl = match ($r['movement_type']) {
            'giris'    => 'Giriş',
            'sevk'     => 'Sevk',
            'kullanim' => 'Kullanım',
            'duzeltme' => 'Düzeltme',
            default    => $r['movement_type'],
        };
        fputcsv($out, [
            $r['movement_date'], $type_lbl,
            $ms_types[$r['material_type']] ?? $r['material_type'],
            $r['material_name'], $r['depo'],
            number_format((float)$r['quantity'], 3, ',', '.'),
            $r['unit'],
            $r['source_type'] !== '' ? $r['source_type'] . ($r['source_id'] ? '#' . $r['source_id'] : '') : '',
            $r['belge_no'], $r['firma'], $r['note'] ?? '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── Sayfalama ─────────────────────────────────────────────
$hareket_per_page    = 100;
$hareket_page        = max(1, (int)($_GET['hareket_page'] ?? 1));
$hareket_total_pages = max(1, (int)ceil($hareket_total / $hareket_per_page));
$hareket_page        = min($hareket_page, $hareket_total_pages);
$hareket_page_rows   = array_slice($hareket_rows, ($hareket_page - 1) * $hareket_per_page, $hareket_per_page);

// ── Audit detay sorguları ──────────────────────────────────
$_ms_audit_details = [];
$_ms_audit_check_defs = [
    ['label'  => 'Malzeme tanımı eşleşmemiş',
     'detail' => 'material_id boş; dara hesabı çalışmıyor.',
     'cnt_sql' => "SELECT COUNT(*) FROM material_stock_movements WHERE material_id IS NULL",
     'row_sql' => "SELECT id, movement_date, material_name, movement_type, depo, quantity, unit FROM material_stock_movements WHERE material_id IS NULL ORDER BY id DESC LIMIT 5"],
    ['label'  => 'Depo boş hareket',
     'detail' => 'Depo filtresi bu kayıtları atlar.',
     'cnt_sql' => "SELECT COUNT(*) FROM material_stock_movements WHERE (depo = '' OR depo IS NULL) AND movement_type NOT IN ('kullanim')",
     'row_sql' => "SELECT id, movement_date, material_name, movement_type, depo, quantity, unit FROM material_stock_movements WHERE (depo = '' OR depo IS NULL) AND movement_type NOT IN ('kullanim') ORDER BY id DESC LIMIT 5"],
    ['label'  => 'Miktar sıfır veya negatif',
     'detail' => 'Stok hesabını bozabilir.',
     'cnt_sql' => "SELECT COUNT(*) FROM material_stock_movements WHERE quantity <= 0",
     'row_sql' => "SELECT id, movement_date, material_name, movement_type, depo, quantity, unit FROM material_stock_movements WHERE quantity <= 0 ORDER BY id DESC LIMIT 5"],
];
foreach ($_ms_audit_check_defs as $_acd) {
    try {
        $_cnt  = (int)$pdo->query($_acd['cnt_sql'])->fetchColumn();
        $_rows = $_cnt > 0 ? $pdo->query($_acd['row_sql'])->fetchAll() : [];
    } catch (PDOException $_ace) { $_cnt = 0; $_rows = []; }
    $_ms_audit_details[] = ['label' => $_acd['label'], 'detail' => $_acd['detail'], 'cnt' => $_cnt, 'rows' => $_rows];
}

render_header('Malzeme Stok');
render_flash();

// Precompute summary totals for top cards
$toplam_giris    = array_sum(array_column($ozet_rows, 'total_giris'));
$toplam_cikis    = array_sum(array_column($ozet_rows, 'total_cikis'));
$mat_kalan_count = count(array_filter($ozet_rows, fn($r) => (float)$r['kalan'] > 0));
$mat_dusuk_count = count($negatif_ozet);
?>

<div class="page-head">
    <h2 class="page-title">🗃️ Malzeme Stok</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="malzeme_stok_import.php" class="btn btn-sm btn-secondary">📥 Excel Aktar</a>
        <a href="<?= ms_url(['csv' => '1', 'hareket_page' => '']) ?>" class="btn btn-sm btn-ghost">⬇ CSV</a>
    </div>
</div>

<?php if (!empty($negatif_ozet)): ?>
<!-- ── Negatif Stok Uyarısı ───────────────────────────────── -->
<div class="ms-neg-uyari">
    <div class="ms-neg-uyari-head">⚠ <?= count($negatif_ozet) ?> malzeme/depoda negatif stok — sevk veya kullanım miktarı girişten fazla olabilir.</div>
    <table class="ms-neg-table">
        <thead><tr><th>Malzeme</th><th>Depo</th><th>Birim</th><th class="num">Kalan</th></tr></thead>
        <tbody>
        <?php foreach ($negatif_ozet as $nr): ?>
        <tr>
            <td><?= h($nr['material_name']) ?></td>
            <td><?= h($nr['depo'] ?: '—') ?></td>
            <td style="color:var(--muted);font-size:.8rem"><?= h($nr['unit']) ?></td>
            <td class="num" style="color:var(--danger);font-weight:700"><?= number_format((float)$nr['kalan'], 0, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Filtre formu ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="malzeme_stok.php" class="stok-filter-form">
        <div class="stok-filter-row">
            <div class="form-group stok-fg">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="tarih_bas" class="form-control" value="<?= h($f_tarih_bas) ?>">
            </div>
            <div class="form-group stok-fg">
                <label class="form-label">Bitiş</label>
                <input type="date" name="tarih_bit" class="form-control" value="<?= h($f_tarih_bit) ?>">
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Malzeme Türü</label>
                <select name="mat_type" class="form-control">
                    <option value="">Hepsi</option>
                    <?php foreach ($ms_types as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $f_mat_type === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Malzeme Adı</label>
                <input type="text" name="mat_name" class="form-control" value="<?= h($f_mat_name) ?>"
                    list="ms-filter-name-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="ms-filter-name-list">
                    <?php foreach (array_merge(...array_values($mat_names_by_type ?: [[]])) as $mn): ?>
                    <option value="<?= h($mn) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Depo</label>
                <select name="depo" class="form-control">
                    <option value="">Hepsi</option>
                    <?php foreach ($depo_list as $dv): ?>
                    <option value="<?= h($dv) ?>" <?= $f_depo === $dv ? 'selected' : '' ?>><?= h($dv) ?></option>
                    <?php endforeach; ?>
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

<!-- ── Özet kartları ─────────────────────────────────────── -->
<div class="stok-ozet-grid stok-ozet-grid-4">
    <div class="stok-kart stok-kart-gelen">
        <div class="stok-kart-label">Toplam Giriş</div>
        <div class="stok-kart-val"><?= number_format($toplam_giris, 0, ',', '.') ?></div>
        <div class="stok-kart-sub">tüm birimler toplamı</div>
    </div>
    <div class="stok-kart stok-kart-yukleme">
        <div class="stok-kart-label">Toplam Çıkış</div>
        <div class="stok-kart-val"><?= number_format($toplam_cikis, 0, ',', '.') ?></div>
        <div class="stok-kart-sub">sevk + kullanım</div>
    </div>
    <div class="stok-kart stok-kart-kalan">
        <div class="stok-kart-label">Stokta Malzeme</div>
        <div class="stok-kart-val"><?= $mat_kalan_count ?></div>
        <div class="stok-kart-sub">farklı malzeme/depo</div>
    </div>
    <div class="stok-kart <?= $mat_dusuk_count > 0 ? 'stok-kart-fire' : 'stok-kart-sayim' ?>">
        <div class="stok-kart-label">Negatif Stok<?= $mat_dusuk_count > 0 ? ' ⚠' : '' ?></div>
        <div class="stok-kart-val <?= $mat_dusuk_count > 0 ? 'stok-negatif' : '' ?>"><?= $mat_dusuk_count ?></div>
        <div class="stok-kart-sub">çıkış > giriş</div>
    </div>
</div>

<!-- ── Stok Özeti Tablosu ─────────────────────────────────── -->
<?php if (!empty($ozet_rows)): ?>
<div class="card" style="margin-top:16px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Stok Özeti</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($ozet_rows) ?> malzeme/depo</span>
    </div>
    <div class="table-wrap">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th>Tür</th>
                    <th>Malzeme</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th class="stok-hide-sm">Birim</th>
                    <th style="text-align:right">Giriş</th>
                    <th style="text-align:right">Çıkış</th>
                    <th style="text-align:right">Kalan</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ozet_rows as $oz):
                $kalan     = (float)$oz['kalan'];
                $is_neg    = $kalan < 0;
                $kalan_cls = $is_neg ? 'stok-negatif' : ($kalan > 0 ? '' : 'color:var(--muted)');
            ?>
                <tr class="<?= $is_neg ? 'ms-row-negatif' : '' ?>">
                    <td style="font-size:.8rem;color:var(--muted)"><?= h($ms_types[$oz['material_type']] ?? $oz['material_type']) ?></td>
                    <td style="font-weight:600"><?= h($oz['material_name']) ?></td>
                    <td class="stok-hide-sm"><?= h($oz['depo'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.82rem;color:var(--muted)"><?= h($oz['unit']) ?></td>
                    <td style="text-align:right;color:var(--success)">+<?= number_format((float)$oz['total_giris'], 0, ',', '.') ?></td>
                    <td style="text-align:right;color:var(--danger)">
                        <?php if ((float)$oz['total_cikis'] > 0): ?>
                        −<?= number_format((float)$oz['total_cikis'], 0, ',', '.') ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;<?= $kalan_cls ?>">
                        <?= number_format($kalan, 0, ',', '.') ?>
                        <small style="font-weight:400;color:var(--muted)"> <?= h($oz['unit']) ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card" style="margin-top:16px;padding:28px;text-align:center;color:var(--muted)">
    Henüz malzeme stok hareketi yok.<?= $herhangi_filtre ? ' Filtre kriterlerine uygun hareket bulunamadı.' : '' ?>
</div>
<?php endif; ?>

<!-- ── Giriş / Sevk / Düzeltme Formu ─────────────────────── -->
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
    <div id="msFormGiris" class="card ms-form-card">
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
                    <label class="form-label">Depo</label>
                    <select name="mv_depo" class="form-control">
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
    <div id="msFormSevk" class="card ms-form-card" hidden>
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
                    <label class="form-label">Depo</label>
                    <select name="mv_depo" class="form-control">
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
    <div id="msFormDuzeltme" class="card ms-form-card" hidden>
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
                    <label class="form-label">Depo</label>
                    <select name="dz_depo" class="form-control">
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

<!-- ── Hareket Tipi Filtresi ─────────────────────────────── -->
<div class="stok-mv-filter-row">
    <a href="<?= ms_url(['hareket_tipi' => '', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === '' ? ' active' : '' ?>">Tümü</a>
    <a href="<?= ms_url(['hareket_tipi' => 'giris', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'giris' ? ' active' : '' ?>">Giriş</a>
    <a href="<?= ms_url(['hareket_tipi' => 'sevk', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'sevk' ? ' active' : '' ?>">Sevk</a>
    <a href="<?= ms_url(['hareket_tipi' => 'kullanim', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'kullanim' ? ' active' : '' ?>">Kullanım</a>
    <a href="<?= ms_url(['hareket_tipi' => 'duzeltme', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'duzeltme' ? ' active' : '' ?>">Düzeltme</a>
</div>

<!-- ── Hareket Tablosu ─────────────────────────────────────── -->
<div class="card" style="margin-top:10px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)">
            <?= $hareket_total ?> hareket<?= $hareket_total >= 2000 ? ' (max 2000)' : '' ?>
            <?php if ($hareket_total_pages > 1): ?> · Sayfa <?= $hareket_page ?>/<?= $hareket_total_pages ?><?php endif; ?>
        </span>
    </div>
    <?php if (empty($hareket_page_rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">Henüz hareket kaydı yok.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Hareket</th>
                    <th>Malzeme</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th class="stok-hide-sm">Belge No</th>
                    <th class="stok-hide-sm">Firma</th>
                    <th style="text-align:right">Miktar</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hareket_page_rows as $r):
                $is_giris  = $r['movement_type'] === 'giris';
                $is_kullan = $r['movement_type'] === 'kullanim';
                $is_duz    = $r['movement_type'] === 'duzeltme';
                $badge_cls = match ($r['movement_type']) {
                    'giris'    => 'badge-gelen',
                    'sevk'     => 'badge-cikan',
                    'kullanim' => 'badge-fire',
                    'duzeltme' => 'badge-duzeltme',
                    default    => '',
                };
                $badge_lbl = match ($r['movement_type']) {
                    'giris'    => 'Giriş',
                    'sevk'     => 'Sevk',
                    'kullanim' => 'Kullanım',
                    'duzeltme' => 'Düzeltme',
                    default    => $r['movement_type'],
                };
                $qty_sign  = $is_giris || ($is_duz && (float)$r['quantity'] >= 0) ? '+' : '−';
                $qty_abs   = abs((float)$r['quantity']);
                $qty_color = $is_giris ? 'var(--success)' : ($is_duz ? 'var(--warn)' : 'var(--danger)');
                $can_delete = $r['source_type'] !== 'loading';
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmt_date($r['movement_date'])) ?></td>
                    <td>
                        <span class="stok-badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
                        <?php if ($is_kullan && $r['source_id']): ?>
                        <div style="font-size:.7rem;color:var(--muted)">Yük #<?= (int)$r['source_id'] ?></div>
                        <?php endif; ?>
                        <?php if (!empty($r['note'])): ?>
                        <div style="font-size:.7rem;color:var(--muted)"><?= h(mb_substr($r['note'], 0, 30)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600"><?= h($r['material_name']) ?></div>
                        <div style="font-size:.78rem;color:var(--muted)"><?= h($ms_types[$r['material_type']] ?? $r['material_type']) ?></div>
                    </td>
                    <td class="stok-hide-sm"><?= h($r['depo'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($r['belge_no'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.85rem"><?= h($r['firma'] ?: '—') ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= $qty_color ?>;white-space:nowrap">
                        <?= $qty_sign ?><?= number_format($qty_abs, 0, ',', '.') ?>
                        <small style="font-weight:400;color:var(--muted)"> <?= h($r['unit']) ?></small>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($can_delete): ?>
                        <form method="post" action="malzeme_stok.php"
                              onsubmit="return confirm('Bu hareketi silmek istediğinizden emin misiniz?');"
                              style="display:inline">
                            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="ms_delete">
                            <input type="hidden" name="del_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="padding:2px 7px;font-size:.75rem;background:var(--danger-light,#fdecea);color:var(--danger)" title="Sil">✕</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($hareket_total_pages > 1): ?>
    <div class="stok-pg">
        <span class="stok-pg-info">Toplam <?= $hareket_total ?> hareket · Sayfa <?= $hareket_page ?> / <?= $hareket_total_pages ?></span>
        <div class="stok-pg-btns">
            <?php if ($hareket_page > 1): ?>
            <a href="<?= ms_url(['hareket_page' => $hareket_page - 1]) ?>" class="btn btn-sm">← Önceki</a>
            <?php endif; ?>
            <?php
            $prev_ellipsis = false;
            for ($pg = 1; $pg <= $hareket_total_pages; $pg++):
                $show = $pg === 1 || $pg === $hareket_total_pages || abs($pg - $hareket_page) <= 2;
                if (!$show):
                    if (!$prev_ellipsis): $prev_ellipsis = true; ?><span class="stok-pg-ellipsis">…</span><?php endif;
                    continue;
                endif;
                $prev_ellipsis = false;
            ?>
            <a href="<?= ms_url(['hareket_page' => $pg]) ?>"
               class="btn btn-sm<?= $pg === $hareket_page ? ' btn-primary' : '' ?>"><?= $pg ?></a>
            <?php endfor; ?>
            <?php if ($hareket_page < $hareket_total_pages): ?>
            <a href="<?= ms_url(['hareket_page' => $hareket_page + 1]) ?>" class="btn btn-sm">Sonraki →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$_ac     = ms_audit_counts($pdo);
$_ac_txt = $_ac['total'] > 0 ? '⚠ ' . $_ac['total'] . ' sorun' : '✓ Temiz';
$_ms_audit_has_detail = array_filter($_ms_audit_details, fn($d) => $d['cnt'] > 0);
?>
<details class="card" style="margin-top:18px;border:1px solid var(--border);border-radius:10px;overflow:hidden">
    <summary style="cursor:pointer;padding:11px 16px;background:var(--card-bg,#fff);display:flex;align-items:center;gap:10px;list-style:none;user-select:none;font-weight:600">
        🔍 Sistem Audit
        <span style="font-size:.75rem;padding:2px 10px;border-radius:20px;font-weight:700;margin-left:auto;
            background:<?= $_ac['total'] > 0 ? '#fee2e2' : '#d1fae5' ?>;
            color:<?= $_ac['total'] > 0 ? '#991b1b' : '#065f46' ?>">
            <?= h($_ac_txt) ?>
        </span>
    </summary>
    <div style="padding:14px 16px">
        <!-- Sayı kartları -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px">
            <div style="background:var(--card-bg,#f8fafc);border:1px solid var(--border);border-radius:8px;padding:10px 14px">
                <div style="font-size:.75rem;color:var(--text-muted)">Yetim stok hareketi</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $_ac['orphan'] > 0 ? '#dc2626' : '#16a34a' ?>">
                    <?= $_ac['orphan'] ?>
                </div>
                <?php if ($_ac['orphan'] > 0): ?>
                <div style="font-size:.72rem;color:#92400e">Kayıt silinmiş yüklemeye bağlı hareket</div>
                <?php endif; ?>
            </div>
            <div style="background:var(--card-bg,#f8fafc);border:1px solid var(--border);border-radius:8px;padding:10px 14px">
                <div style="font-size:.75rem;color:var(--text-muted)">Birebir duplicate tanım</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $_ac['dup_exact'] > 0 ? '#dc2626' : '#16a34a' ?>">
                    <?= $_ac['dup_exact'] ?>
                </div>
            </div>
            <div style="background:var(--card-bg,#f8fafc);border:1px solid var(--border);border-radius:8px;padding:10px 14px">
                <div style="font-size:.75rem;color:var(--text-muted)">Normalize duplicate tanım</div>
                <div style="font-size:1.4rem;font-weight:700;color:<?= $_ac['dup_norm'] > 0 ? '#dc2626' : '#16a34a' ?>">
                    <?= $_ac['dup_norm'] ?>
                </div>
                <div style="font-size:.72rem;color:var(--muted)">normalize_text_v2 tabanlı</div>
            </div>
        </div>

        <?php if (!empty($_ms_audit_has_detail)): ?>
        <!-- Detay tabloları -->
        <div style="border-top:1px solid var(--border);padding-top:12px;margin-top:2px">
            <?php foreach ($_ms_audit_details as $_acd): ?>
            <?php if ($_acd['cnt'] === 0) continue; ?>
            <div style="margin-bottom:14px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                    <span class="dkk-badge dkk-badge-orta" style="font-size:.72rem"><?= $_acd['cnt'] ?></span>
                    <strong style="font-size:.88rem"><?= h($_acd['label']) ?></strong>
                    <span style="font-size:.78rem;color:var(--muted)"><?= h($_acd['detail']) ?></span>
                </div>
                <?php if (!empty($_acd['rows'])): ?>
                <div class="table-wrap">
                    <table class="data-table" style="font-size:.8rem">
                        <thead><tr><th>ID</th><th>Tarih</th><th>Malzeme</th><th>Hareket</th><th>Depo</th><th>Miktar</th></tr></thead>
                        <tbody>
                        <?php foreach ($_acd['rows'] as $_arow): ?>
                        <tr>
                            <td><?= (int)$_arow['id'] ?></td>
                            <td><?= h($_arow['movement_date'] ?? '—') ?></td>
                            <td><?= h($_arow['material_name'] ?? '—') ?></td>
                            <td><?= h($_arow['movement_type'] ?? '—') ?></td>
                            <td><?= h($_arow['depo'] ?: '—') ?></td>
                            <td><?= number_format((int)round((float)($_arow['quantity'] ?? 0)), 0, '', '.') ?> <?= h($_arow['unit'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($_ac['total'] > 0 && empty($_ms_audit_has_detail)): ?>
        <p style="font-size:.83rem;color:#b45309;background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 6px 6px 0;margin-bottom:10px">
            ⚠ Sorunlu kayıtlar tespit edildi. Detay ve CSV için Detaylı Audit sayfasını açın.
        </p>
        <?php endif; ?>
        <a href="audit.php" class="btn btn-ghost btn-sm">→ Detaylı Audit &amp; CSV</a>
    </div>
</details>

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
</script>

<?php render_footer(); ?>
