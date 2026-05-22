<?php
// =========================================================
// malzeme_stok.php — Ambalaj / Malzeme Stok Takibi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$pdo = db();

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
        $mat_row = $pdo->prepare(
            "SELECT id, unit_dara_kg FROM material_definitions WHERE type=? AND LOWER(name)=LOWER(?) LIMIT 1"
        );
        $mat_row->execute([$mv_mat_type, $mv_mat_name]);
        $mat_row   = $mat_row->fetch();
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

// ── POST: Düzeltme ────────────────────────────────────────
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
$f_tarih_bas = trim($_GET['tarih_bas'] ?? '');
$f_tarih_bit = trim($_GET['tarih_bit'] ?? '');
$f_mat_type  = trim($_GET['mat_type']  ?? '');
$f_mat_name  = trim($_GET['mat_name']  ?? '');
$f_depo      = trim($_GET['depo']      ?? '');
$is_csv      = isset($_GET['csv']);

// ── WHERE builder ─────────────────────────────────────────
$where = []; $params = [];
if ($f_tarih_bas !== '') { $where[] = "movement_date >= ?"; $params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $where[] = "movement_date <= ?"; $params[] = $f_tarih_bit; }
if ($f_mat_type  !== '') { $where[] = "material_type = ?";  $params[] = $f_mat_type; }
if ($f_mat_name  !== '') { $where[] = "material_name LIKE ?"; $params[] = '%' . $f_mat_name . '%'; }
if ($f_depo      !== '') { $where[] = "depo LIKE ?";          $params[] = '%' . $f_depo . '%'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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

// ── Hareket listesi ───────────────────────────────────────
$hareket_rows = [];
try {
    $st = $pdo->prepare("
        SELECT id, movement_date, movement_type, material_type, material_name,
               depo, quantity, unit, source_type, source_id,
               belge_no, firma, note
        FROM material_stock_movements
        $where_sql
        ORDER BY movement_date DESC, id DESC
        LIMIT 500
    ");
    $st->execute($params);
    $hareket_rows = $st->fetchAll();
} catch (PDOException $e) {}

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

$herhangi_filtre = $f_tarih_bas || $f_tarih_bit || $f_mat_type || $f_mat_name || $f_depo;

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

render_header('Malzeme Stok');
render_flash();

// Precompute summary totals for top cards
$toplam_giris = array_sum(array_column($ozet_rows, 'total_giris'));
$toplam_cikis = array_sum(array_column($ozet_rows, 'total_cikis'));
$mat_kalan_count = count(array_filter($ozet_rows, fn($r) => (float)$r['kalan'] > 0));
$mat_dusuk_count = count(array_filter($ozet_rows, fn($r) => (float)$r['kalan'] < 0));
?>

<div class="page-head">
    <h2 class="page-title">🗃️ Malzeme Stok</h2>
    <a href="?<?= h(http_build_query(array_filter([
        'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit,
        'mat_type' => $f_mat_type,  'mat_name'  => $f_mat_name,
        'depo' => $f_depo, 'csv' => '1',
    ], fn($v) => $v !== ''))) ?>" class="btn btn-sm btn-ghost">⬇ CSV</a>
</div>

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
        <div class="stok-kart-label">Negatif Stok</div>
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
                $kalan = (float)$oz['kalan'];
                $kalan_cls = $kalan < 0 ? 'stok-negatif' : ($kalan > 0 ? '' : 'color:var(--muted)');
            ?>
                <tr>
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
                        <?= ($kalan >= 0 ? '' : '') . number_format($kalan, 0, ',', '.') ?>
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

<!-- ── Giriş / Sevk Formu ─────────────────────────────────── -->
<div class="ms-form-wrap" style="margin-top:20px">
    <div class="ms-form-tabs">
        <button type="button" class="ms-tab-btn ms-tab-active" id="tabGiris" onclick="msTab('giris')">
            ➕ Giriş Ekle
        </button>
        <button type="button" class="ms-tab-btn" id="tabSevk" onclick="msTab('sevk')">
            ↗ Sevk Çıkışı
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
</div>

<!-- ── Hareket Tablosu ─────────────────────────────────────── -->
<div class="card" style="margin-top:20px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($hareket_rows) ?> kayıt<?= count($hareket_rows) >= 500 ? ' (ilk 500)' : '' ?></span>
    </div>
    <?php if (empty($hareket_rows)): ?>
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
            <?php foreach ($hareket_rows as $r):
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
                $qty_sign  = $is_giris || $is_duz ? '+' : '−';
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
                    </td>
                    <td>
                        <div style="font-weight:600"><?= h($r['material_name']) ?></div>
                        <div style="font-size:.78rem;color:var(--muted)"><?= h($ms_types[$r['material_type']] ?? $r['material_type']) ?></div>
                    </td>
                    <td class="stok-hide-sm"><?= h($r['depo'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($r['belge_no'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.85rem"><?= h($r['firma'] ?: '—') ?></td>
                    <td style="text-align:right;font-weight:700;color:<?= $qty_color ?>;white-space:nowrap">
                        <?= $qty_sign ?><?= number_format((float)$r['quantity'], 0, ',', '.') ?>
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
    <?php endif; ?>
</div>

<script>
var msNamesData = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;

function msUpdateNames(form) {
    var typeSel  = document.getElementById(form === 'giris' ? 'girisMatType' : 'sevkMatType');
    var nameSel  = document.getElementById(form === 'giris' ? 'girisMatName' : 'sevkMatName');
    if (!typeSel || !nameSel) return;
    var matType  = typeSel.value;
    var names    = msNamesData[matType] || [];
    nameSel.innerHTML = '<option value="">— seçiniz —</option>';
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n;
        opt.textContent = n;
        nameSel.appendChild(opt);
    });
    nameSel.disabled = names.length === 0;
}

function msTab(tab) {
    var girisEl = document.getElementById('msFormGiris');
    var sevkEl  = document.getElementById('msFormSevk');
    var tGiris  = document.getElementById('tabGiris');
    var tSevk   = document.getElementById('tabSevk');
    if (tab === 'giris') {
        girisEl.hidden = false; sevkEl.hidden = true;
        tGiris.classList.add('ms-tab-active'); tSevk.classList.remove('ms-tab-active');
    } else {
        sevkEl.hidden = false; girisEl.hidden = true;
        tSevk.classList.add('ms-tab-active'); tGiris.classList.remove('ms-tab-active');
    }
}
</script>

<?php render_footer(); ?>
