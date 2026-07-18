<?php
// =========================================================
// malzeme_hareketleri.php — Malzeme Stok Hareketleri
// Stok giriş / sevk / kullanım / düzeltme hareketleri.
// (Sprint MalzemeStok-Pro-02 — malzeme_stok.php'den taşındı.)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/material_stock_helpers.php';
$auth_user = require_login();
require_perm('stok.read');

$pdo = db();

$ms_types = ms_material_types();

// ── URL oluşturucu — filtre + sayfa durumunu korur ────────
function mh_url(array $override = [], array $drop = []): string {
    global $f_tarih_bas, $f_tarih_bit, $f_mat_id, $f_mat_type, $f_mat_name,
           $f_depo, $f_hareket_tipi, $f_firma, $mv_page;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
        'mat_id'       => (isset($f_mat_id) && (int)$f_mat_id > 0) ? (string)(int)$f_mat_id : '',
        'mat_type'     => $f_mat_type     ?? '',
        'mat_name'     => $f_mat_name     ?? '',
        'depo'         => $f_depo         ?? '',
        'hareket_tipi' => $f_hareket_tipi ?? '',
        'firma'        => $f_firma        ?? '',
        'page'         => (isset($mv_page) && $mv_page > 1) ? (string)$mv_page : '',
    ];
    foreach ($override as $k => $v) { $base[$k] = (string)$v; }
    foreach ($drop as $k) { unset($base[$k]); }
    return 'malzeme_hareketleri.php' . (($q = array_filter($base, fn($v) => $v !== '')) ? '?' . http_build_query($q) : '');
}

// ── Filtreler ─────────────────────────────────────────────
// POST handler'larından ÖNCE okunur: redirect (mh_url) filtreleri korusun.
// Form action'ları mevcut filtreleri query string olarak taşır.
$f_tarih_bas    = trim($_GET['tarih_bas']    ?? '');
$f_tarih_bit    = trim($_GET['tarih_bit']    ?? '');
$f_mat_id       = (int)($_GET['mat_id']      ?? 0); // ID bazlı filtre — isim değişikliğine dayanıklı
$f_mat_type     = trim($_GET['mat_type']     ?? '');
$f_mat_name     = trim($_GET['mat_name']     ?? '');
$f_depo         = trim($_GET['depo']         ?? '');
$is_csv         = isset($_GET['csv']);
$f_hareket_tipi = trim($_GET['hareket_tipi'] ?? '');
if (!in_array($f_hareket_tipi, ['giris', 'sevk', 'kullanim', 'duzeltme', ''], true)) $f_hareket_tipi = '';
$f_firma        = trim($_GET['firma'] ?? '');
$mv_page        = max(1, (int)($_GET['page'] ?? 1)); // redirect'te sayfa da korunsun (clamp render'da)

// ── POST: Hareket Düzenle — admin-only ───────────────────
// Tüm alanlar düzenlenebilir (tarih, tür, malzeme, depo, miktar, birim, meta).
// Yükleme kaynaklı hareketler düzenlenemez.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_edit') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Bu işlem sadece admin tarafından yapılabilir.');
        header('Location: ' . mh_url());
        exit;
    }
    $edit_id       = (int)($_POST['edit_id']       ?? 0);
    $edit_date     = trim($_POST['edit_date']       ?? '');
    $edit_type     = trim($_POST['edit_type']       ?? '');
    $edit_mat_type = trim($_POST['edit_mat_type']   ?? '');
    $edit_mat_name = trim($_POST['edit_mat_name']   ?? '');
    $edit_depo     = trim($_POST['edit_depo']       ?? '');
    $edit_unit     = trim($_POST['edit_unit']       ?? 'adet');
    $edit_qty      = parse_stock_quantity(trim($_POST['edit_qty'] ?? '0'), $edit_unit);
    $edit_belge    = trim($_POST['edit_belge']      ?? '');
    $edit_firma    = trim($_POST['edit_firma']      ?? '');
    $edit_note     = trim($_POST['edit_note']       ?? '');

    $ms_units_valid = ms_stock_units();

    $err  = '';
    $base = null;
    if ($edit_id <= 0) {
        $err = 'Geçersiz kayıt.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $edit_date)) {
        $err = 'Geçersiz tarih.';
    } elseif (!in_array($edit_type, ['giris', 'sevk', 'kullanim', 'duzeltme'], true)) {
        $err = 'Geçersiz hareket tipi.';
    } elseif ($edit_mat_name === '') {
        $err = 'Malzeme adı zorunludur.';
    } elseif ($edit_depo === '') {
        $err = 'Depo zorunludur.';
    } elseif ($edit_qty == 0) {
        $err = 'Miktar sıfır olamaz.';
    } elseif (!in_array($edit_unit, $ms_units_valid, true)) {
        $edit_unit = 'adet';
    }

    if ($err === '') {
        $stmt = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $stmt->execute([$edit_id]);
        $base = $stmt->fetch();
        if (!$base) {
            $err = 'Kayıt bulunamadı.';
        } elseif ($base['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı hareketler buradan düzenlenemez. Kaynak yükleme kaydını düzenleyin.';
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $mat_row    = ms_find_material_definition($pdo, $edit_mat_type, $edit_mat_name);
        $mat_id     = $mat_row ? (int)$mat_row['id'] : null;
        $unit_dara  = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;
        $total_dara = round(abs($edit_qty) * $unit_dara, 3);

        $pdo->prepare(
            "UPDATE material_stock_movements
                SET movement_date=?, movement_type=?, material_id=?, material_name=?,
                    material_type=?, depo=?, quantity=?, unit=?, unit_dara_kg=?, total_dara_kg=?,
                    belge_no=?, firma=?, note=?
              WHERE id=?"
        )->execute([
            $edit_date, $edit_type, $mat_id, $edit_mat_name, $edit_mat_type,
            $edit_depo, $edit_qty, $edit_unit, $unit_dara, $total_dara,
            $edit_belge ?: null, $edit_firma ?: null, $edit_note ?: null,
            $edit_id,
        ]);

        audit_log_event('material_stock_edit', 'malzeme_stok', $edit_id, [
            'old_date'     => $base['movement_date'],
            'old_type'     => $base['movement_type'],
            'old_material' => $base['material_name'],
            'old_depo'     => $base['depo'],
            'old_qty'      => $base['quantity'],
            'old_unit'     => $base['unit'],
        ], [
            'new_date'     => $edit_date,
            'new_type'     => $edit_type,
            'new_material' => $edit_mat_name,
            'new_depo'     => $edit_depo,
            'new_qty'      => $edit_qty,
            'new_unit'     => $edit_unit,
        ]);
        set_flash('success', '#' . $edit_id . ' nolu hareket düzenlendi.');
    }
    header('Location: ' . mh_url());
    exit;
}

// ── POST: Hareket Sil — admin-only ───────────────────────
// Yükleme kaynaklı hareketler silinemez.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_delete') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Silme işlemi yalnızca admin tarafından yapılabilir.');
        header('Location: ' . mh_url());
        exit;
    }
    $del_id = (int)($_POST['del_id'] ?? 0);

    $err  = '';
    $base = null;
    if ($del_id <= 0) {
        $err = 'Geçersiz kayıt.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $stmt->execute([$del_id]);
        $base = $stmt->fetch();
        if (!$base) {
            $err = 'Kayıt bulunamadı.';
        } elseif ($base['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı hareketler silinemez. Kaynak yükleme kaydını düzenleyin.';
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $pdo->prepare("DELETE FROM material_stock_movements WHERE id=?")->execute([$del_id]);
        audit_log_event('material_stock_delete', 'malzeme_stok', $del_id, [
            'movement_type' => $base['movement_type'],
            'movement_date' => $base['movement_date'],
            'material_name' => $base['material_name'],
            'material_type' => $base['material_type'],
            'depo'          => $base['depo'],
            'quantity'      => $base['quantity'],
            'unit'          => $base['unit'],
            'source_type'   => $base['source_type'],
        ], null);
        set_flash('success', '#' . $del_id . ' nolu hareket silindi.');
    }
    header('Location: ' . mh_url());
    exit;
}

$mv_filters = [
    'tarih_bas'    => $f_tarih_bas,
    'tarih_bit'    => $f_tarih_bit,
    'mat_id'       => $f_mat_id,
    'mat_type'     => $f_mat_type,
    'mat_name'     => $f_mat_name,
    'depo'         => $f_depo,
    'hareket_tipi' => $f_hareket_tipi,
    'firma'        => $f_firma,
];

// ── POST: Hareket Taşı — admin-only ──────────────────────
// Manuel hareketi başka malzeme tanımına / depoya taşır.
// Orijinal hareket UPDATE edilir (material_id/name/type/depo + note).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_move') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Bu işlem sadece admin tarafından yapılabilir.');
        header('Location: ' . mh_url());
        exit;
    }
    $move_id     = (int)($_POST['move_id']            ?? 0);
    $tgt_mat_id  = (int)($_POST['target_material_id'] ?? 0);
    $new_depo    = trim($_POST['move_depo']            ?? '');
    $move_reason = trim($_POST['move_reason']          ?? '');

    $err       = '';
    $orig_move = null;
    $tgt_def   = null;

    if ($move_id <= 0) {
        $err = 'Geçersiz hareket.';
    } elseif ($tgt_mat_id <= 0) {
        $err = 'Hedef malzeme seçiniz.';
    } elseif ($move_reason === '') {
        $err = 'Taşıma sebebi zorunludur.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $stmt->execute([$move_id]);
        $orig_move = $stmt->fetch();

        if (!$orig_move) {
            $err = 'Hareket bulunamadı.';
        } elseif ($orig_move['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı hareketler taşınamaz. Kaynak yükleme kaydı düzenlenmelidir.';
        } elseif ($orig_move['source_type'] === 'manual_reverse') {
            $err = 'Ters kayıt hareketleri taşınamaz.';
        } else {
            $stmt2 = $pdo->prepare("SELECT id, type, name, is_active FROM material_definitions WHERE id=? LIMIT 1");
            $stmt2->execute([$tgt_mat_id]);
            $tgt_def = $stmt2->fetch();

            if (!$tgt_def) {
                $err = 'Hedef malzeme tanımı bulunamadı.';
            } elseif (!(int)$tgt_def['is_active']) {
                $err = 'Hedef malzeme pasif durumda, aktif bir tanım seçiniz.';
            } elseif ($tgt_def['type'] !== ($orig_move['material_type'] ?? '')) {
                $err = 'Bu hareket farklı malzeme türüne taşınamaz.';
            } else {
                $final_depo = $new_depo !== '' ? $new_depo : ($orig_move['depo'] ?? '');
                if ((int)($orig_move['material_id'] ?? 0) === $tgt_mat_id
                    && $final_depo === ($orig_move['depo'] ?? '')) {
                    $err = 'Kaynak ve hedef aynı, taşıma yapılmadı.';
                }
            }
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $final_depo  = $new_depo !== '' ? $new_depo : ($orig_move['depo'] ?? '');
        $move_suffix = 'TAŞINDI: #' . $move_id . ' hareketi '
            . mb_substr($orig_move['material_name'], 0, 25, 'UTF-8') . '/'
            . mb_substr($orig_move['depo'] ?? '', 0, 15, 'UTF-8') . ' → '
            . mb_substr($tgt_def['name'], 0, 25, 'UTF-8') . '/'
            . mb_substr($final_depo, 0, 15, 'UTF-8') . '. Sebep: ' . $move_reason;
        $old_note = (string)($orig_move['note'] ?? '');
        $new_note = $old_note !== ''
            ? mb_substr($old_note . ' | ' . $move_suffix, 0, 500, 'UTF-8')
            : mb_substr($move_suffix, 0, 500, 'UTF-8');

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE material_stock_movements
                    SET material_id=?, material_name=?, material_type=?, depo=?, note=?
                  WHERE id=?"
            )->execute([
                (int)$tgt_def['id'], $tgt_def['name'], $tgt_def['type'],
                $final_depo, $new_note, $move_id,
            ]);
            audit_log_event('material_stock_move', 'malzeme_stok', $move_id, [
                'old_material_id'   => $orig_move['material_id'],
                'old_material_name' => $orig_move['material_name'],
                'old_material_type' => $orig_move['material_type'],
                'old_depo'          => $orig_move['depo'],
            ], [
                'new_material_id'   => (int)$tgt_def['id'],
                'new_material_name' => $tgt_def['name'],
                'new_material_type' => $tgt_def['type'],
                'new_depo'          => $final_depo,
                'movement_type'     => $orig_move['movement_type'],
                'quantity'          => $orig_move['quantity'],
                'unit'              => $orig_move['unit'],
                'reason'            => $move_reason,
            ]);
            $pdo->commit();
            set_flash('success', '#' . $move_id . ' nolu hareket "' . $tgt_def['name'] . '" / ' . $final_depo . ' üzerine taşındı.');
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('error', 'İşlem hatası: ' . $e->getMessage());
        }
    }
    header('Location: ' . mh_url());
    exit;
}

// ── Dropdown verileri ─────────────────────────────────────
$ms_dd             = get_material_dropdown_data($pdo);
$depo_list         = $ms_dd['depo_list'];
$mat_names_by_type = $ms_dd['mat_names_by_type'];
$firma_list        = $ms_dd['firma_list'];
$ms_units          = ms_stock_units();

// Firma filtre seçenekleri: tanımlar ∪ hareketlerde geçmiş serbest metin isimler
$firma_filter_opts = $firma_list;
try {
    foreach ($pdo->query("SELECT DISTINCT firma FROM material_stock_movements WHERE firma IS NOT NULL AND firma != '' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN) as $_fv) {
        if (!in_array($_fv, $firma_filter_opts, true)) $firma_filter_opts[] = $_fv;
    }
    sort($firma_filter_opts);
} catch (PDOException $_e) {}

// Taşı modal için: aynı type içinde hedef seçimi (admin-only modal)
$move_defs_by_type = [];
$stmt = $pdo->query(
    "SELECT id, type, name, is_active FROM material_definitions
      WHERE type NOT IN ('firma','tedarikci','depo','bolge','urun','lokasyon','cikis_nedeni')
      ORDER BY is_active DESC, name"
);
while ($mrow = $stmt->fetch()) {
    $move_defs_by_type[$mrow['type']][] = [
        'id'        => (int)$mrow['id'],
        'name'      => $mrow['name'],
        'is_active' => (int)$mrow['is_active'],
    ];
}

$herhangi_filtre = $f_tarih_bas !== '' || $f_tarih_bit !== '' || $f_mat_id > 0
                || $f_mat_type !== '' || $f_mat_name !== '' || $f_depo !== '' || $f_hareket_tipi !== ''
                || $f_firma !== '';
$ms_can_write = can('stok.write');
$ms_is_admin  = is_admin();

// ── CSV export — Hareketler (csv=1) ────────────────────────
// Filtreye uyan TÜM hareketleri verir (sayfalama yok). Kolon/format korunmuştur.
if ($is_csv) {
    $csv_rows = get_material_movements($pdo, $mv_filters, 100000, 0);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="malzeme_stok_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tarih', 'Hareket', 'Malzeme Türü', 'Malzeme', 'Depo', 'Miktar', 'Birim', 'Kaynak', 'Belge No', 'Firma', 'Not'], ';', '"', '\\');
    foreach ($csv_rows as $r) {
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

// ── Sayfalama (SQL LIMIT/OFFSET) ──────────────────────────
// $mv_page yukarıda $_GET'ten okundu; burada toplam sayfaya göre clamp edilir.
$mv_per_page    = 100;
$mv_total       = count_material_movements($pdo, $mv_filters);
$mv_total_pages = max(1, (int)ceil($mv_total / $mv_per_page));
$mv_page        = min($mv_page, $mv_total_pages);
$mv_offset      = ($mv_page - 1) * $mv_per_page;
$hareket_rows   = get_material_movements($pdo, $mv_filters, $mv_per_page, $mv_offset);

// ── Aksiyon butonları — tablo + mobil kart ortak ──────────
// Admin + non-loading hareketlerde Taşı / Düzenle / Sil döndürür.
$mh_action_buttons = function (array $r) use ($ms_is_admin): string {
    if (!$ms_is_admin) return '';
    $can_act  = !in_array($r['source_type'], ['loading'], true);
    $can_move = !in_array($r['source_type'], ['loading', 'manual_reverse'], true);
    if (!$can_act) return '';
    $id = (int)$r['id'];
    $j  = fn($v) => h(json_encode($v, JSON_UNESCAPED_UNICODE));
    ob_start();
    if ($can_move): ?>
        <button type="button" class="btn btn-sm ms-act-btn ms-move-btn" title="Başka malzeme tanımına taşı"
                onclick="msMoveOpen(<?= $id ?>, <?= $j($r['movement_date']) ?>, <?= $j($r['movement_type']) ?>, <?= $j($r['material_type']) ?>, <?= $j((string)($r['material_id'] ?? '')) ?>, <?= $j($r['material_name']) ?>, <?= $j($r['depo'] ?? '') ?>, <?= $j((string)$r['quantity']) ?>, <?= $j($r['unit']) ?>, <?= $j($r['belge_no'] ?? '') ?>, <?= $j($r['firma'] ?? '') ?>, <?= $j($r['note'] ?? '') ?>)">⇄ Taşı</button>
    <?php endif; ?>
        <button type="button" class="btn btn-sm ms-act-btn ms-edit-btn" title="Hareketi düzenle"
                onclick="msEditOpen(<?= $j(['id'=>$id,'date'=>$r['movement_date'],'type'=>$r['movement_type'],'mat_type'=>$r['material_type'],'mat_id'=>(string)($r['material_id']??''),'mat_name'=>$r['material_name'],'depo'=>$r['depo']??'','qty'=>(string)$r['quantity'],'unit'=>$r['unit'],'belge'=>$r['belge_no']??'','firma'=>$r['firma']??'','note'=>$r['note']??'']) ?>)">✎ Düzenle</button>
        <button type="button" class="btn btn-sm ms-act-btn ms-del-btn" title="Hareketi sil"
                onclick="msDelOpen(<?= $id ?>, <?= $j($r['movement_date']) ?>, <?= $j($r['movement_type']) ?>, <?= $j($r['material_name']) ?>, <?= $j((string)$r['quantity']) ?>, <?= $j($r['unit']) ?>)">🗑 Sil</button>
    <?php
    return ob_get_clean();
};

render_header('Malzeme Hareketleri');
render_flash();
?>

<div class="page-head">
    <div>
        <h2 class="page-title">📜 Malzeme Hareketleri</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Stok giriş, sevk, kullanım ve düzeltme hareketlerini buradan takip edebilirsiniz.
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="malzeme_stok.php" class="btn btn-sm btn-secondary">← Stok Özeti</a>
        <a href="<?= h(mh_url(['csv' => '1', 'page' => ''])) ?>" class="btn btn-sm btn-ghost">⬇ Hareket CSV</a>
    </div>
</div>

<?php if ($ms_is_admin): ?>
<div style="font-size:.78rem;color:var(--muted);background:var(--bg-alt,#f6f8fa);border:1px solid var(--border);border-radius:6px;padding:8px 12px;margin-bottom:10px">
    ℹ️ Manuel hareketleri <b>✎ Düzenle</b> ile değiştirebilir veya <b>🗑 Sil</b> ile kalıcı olarak silebilirsiniz. Yükleme kaynaklı (🔒) hareketler yalnızca ilgili yükleme kaydından değiştirilebilir.
</div>
<?php endif; ?>

<!-- ── Filtre formu ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="malzeme_hareketleri.php" class="stok-filter-form">
        <?php if ($f_mat_id > 0): ?><input type="hidden" name="mat_id" value="<?= (int)$f_mat_id ?>"><?php endif; ?>
        <?php if ($f_hareket_tipi !== ''): ?><input type="hidden" name="hareket_tipi" value="<?= h($f_hareket_tipi) ?>"><?php endif; ?>
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
                <select name="mat_type" class="form-control" <?= $f_mat_id > 0 ? 'disabled' : '' ?>>
                    <option value="">Hepsi</option>
                    <?php foreach ($ms_types as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= $f_mat_type === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Malzeme Adı</label>
                <input type="text" name="mat_name" class="form-control" value="<?= h($f_mat_name) ?>"
                    list="mh-filter-name-list" placeholder="Hepsi" autocomplete="off" <?= $f_mat_id > 0 ? 'disabled' : '' ?>>
                <datalist id="mh-filter-name-list">
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
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Tedarikçi</label>
                <select name="firma" class="form-control">
                    <option value="">Hepsi</option>
                    <?php foreach ($firma_filter_opts as $fv): ?>
                    <option value="<?= h($fv) ?>" <?= $f_firma === $fv ? 'selected' : '' ?>><?= h($fv) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($f_mat_id > 0): ?>
        <div style="font-size:.78rem;color:var(--muted);margin-top:-4px;margin-bottom:8px">
            ℹ️ Belirli bir malzeme (ID #<?= (int)$f_mat_id ?>) için filtrelenmiş — isim değişse bile tüm geçmiş hareketler görünür.
            Tür/ad filtresini kullanmak için <a href="<?= h(mh_url([], ['mat_id'])) ?>">malzeme filtresini kaldırın</a>.
        </div>
        <?php endif; ?>
        <div class="stok-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <?php if ($herhangi_filtre): ?>
            <a href="malzeme_hareketleri.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Hareket Tipi Filtresi ─────────────────────────────── -->
<div class="stok-mv-filter-row">
    <a href="<?= h(mh_url(['hareket_tipi' => '', 'page' => ''])) ?>"
       class="pill<?= $f_hareket_tipi === '' ? ' active' : '' ?>">Tümü</a>
    <a href="<?= h(mh_url(['hareket_tipi' => 'giris', 'page' => ''])) ?>"
       class="pill<?= $f_hareket_tipi === 'giris' ? ' active' : '' ?>">Giriş</a>
    <a href="<?= h(mh_url(['hareket_tipi' => 'sevk', 'page' => ''])) ?>"
       class="pill<?= $f_hareket_tipi === 'sevk' ? ' active' : '' ?>">Sevk</a>
    <a href="<?= h(mh_url(['hareket_tipi' => 'kullanim', 'page' => ''])) ?>"
       class="pill<?= $f_hareket_tipi === 'kullanim' ? ' active' : '' ?>">Kullanım</a>
    <a href="<?= h(mh_url(['hareket_tipi' => 'duzeltme', 'page' => ''])) ?>"
       class="pill<?= $f_hareket_tipi === 'duzeltme' ? ' active' : '' ?>">Düzeltme</a>
</div>

<!-- ── Hareket Tablosu ─────────────────────────────────────── -->
<div class="card" style="margin-top:10px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)">
            <?= $mv_total ?> hareket
            <?php if ($mv_total_pages > 1): ?> · Sayfa <?= $mv_page ?>/<?= $mv_total_pages ?><?php endif; ?>
        </span>
    </div>
    <?php if (empty($hareket_rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">
        <?= $herhangi_filtre ? 'Filtre kriterlerine uygun hareket bulunamadı.' : 'Henüz hareket kaydı yok.' ?>
    </div>
    <?php else: ?>
    <!-- ── Masaüstü tablo (≥768px) ──────────────────────────── -->
    <div class="table-wrap mh-desk">
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
                $qty_sign  = $is_giris || ($is_duz && (float)$r['quantity'] >= 0) ? '+' : '−';
                $qty_abs   = abs((float)$r['quantity']);
                $qty_color  = $is_giris ? 'var(--success)' : ($is_duz ? 'var(--warn)' : 'var(--danger)');
                $is_loading = $r['source_type'] === 'loading';
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmt_date($r['movement_date'])) ?></td>
                    <td>
                        <span class="stok-badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
                        <?php if ($is_kullan && $r['source_id']): ?>
                        <div style="font-size:.7rem;color:var(--muted)">
                            <a href="record_view.php?id=<?= (int)$r['source_id'] ?>" style="color:var(--primary);text-decoration:none">Yük #<?= (int)$r['source_id'] ?> →</a>
                        </div>
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
                    <td class="mh-act-cell" style="white-space:nowrap">
                        <?php if ($is_loading): ?>
                        <span style="font-size:.7rem;color:var(--muted);white-space:nowrap"
                              title="Bu hareket Yük #<?= (int)$r['source_id'] ?> yükleme kaydından otomatik oluşur. Silmek/düzeltmek için ilgili yükleme kaydını düzenleyin.">🔒 Yük #<?= (int)$r['source_id'] ?></span>
                        <?php else: ?>
                        <?= $mh_action_buttons($r) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Mobil kart listesi (<768px) ──────────────────────── -->
    <div class="mh-cards">
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
            $qty_sign  = $is_giris || ($is_duz && (float)$r['quantity'] >= 0) ? '+' : '−';
            $qty_abs   = abs((float)$r['quantity']);
            $qty_color = $is_giris ? 'var(--success)' : ($is_duz ? 'var(--warn)' : 'var(--danger)');
            $is_loading = $r['source_type'] === 'loading';
        ?>
        <div class="mh-card">
            <div class="mh-card-top">
                <span class="stok-badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
                <span class="mh-card-date"><?= h(fmt_date($r['movement_date'])) ?></span>
                <?php if ($is_loading): ?>
                <span class="mh-card-lock" title="Yükleme kaynaklı hareket">🔒 Yük #<?= (int)$r['source_id'] ?></span>
                <?php endif; ?>
            </div>
            <div class="mh-card-main">
                <div class="mh-card-mat"><?= h($r['material_name']) ?></div>
                <div class="mh-card-meta">
                    <?= h($ms_types[$r['material_type']] ?? $r['material_type']) ?>
                    · <?= h($r['depo'] ?: '—') ?>
                </div>
            </div>
            <div class="mh-card-qty" style="color:<?= $qty_color ?>">
                <?= $qty_sign ?><?= number_format($qty_abs, 0, ',', '.') ?>
                <small><?= h($r['unit']) ?></small>
            </div>
            <?php if ($r['belge_no'] || $r['firma'] || ($is_kullan && $r['source_id']) || !empty($r['note'])): ?>
            <div class="mh-card-details">
                <?php if ($r['belge_no']): ?><div><span>Belge:</span> <?= h($r['belge_no']) ?></div><?php endif; ?>
                <?php if ($r['firma']): ?><div><span>Firma:</span> <?= h($r['firma']) ?></div><?php endif; ?>
                <?php if ($is_kullan && $r['source_id']): ?>
                <div><span>Kaynak:</span> <a href="record_view.php?id=<?= (int)$r['source_id'] ?>" style="color:var(--primary);text-decoration:none">Yük #<?= (int)$r['source_id'] ?> →</a></div>
                <?php endif; ?>
                <?php if (!empty($r['note'])): ?><div><span>Not:</span> <?= h($r['note']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($is_loading): ?>
            <div class="mh-card-note">🔒 Yükleme kaynaklı hareket — düzenlemek için ilgili yükleme kaydını açın.</div>
            <?php elseif ($ms_is_admin): ?>
            <div class="mh-card-actions"><?= $mh_action_buttons($r) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($mv_total_pages > 1): ?>
    <div class="stok-pg">
        <span class="stok-pg-info">Toplam <?= $mv_total ?> hareket · Sayfa <?= $mv_page ?> / <?= $mv_total_pages ?></span>
        <div class="stok-pg-btns">
            <?php if ($mv_page > 1): ?>
            <a href="<?= h(mh_url(['page' => $mv_page - 1])) ?>" class="btn btn-sm">← Önceki</a>
            <?php endif; ?>
            <?php
            $prev_ellipsis = false;
            for ($pg = 1; $pg <= $mv_total_pages; $pg++):
                $show = $pg === 1 || $pg === $mv_total_pages || abs($pg - $mv_page) <= 2;
                if (!$show):
                    if (!$prev_ellipsis): $prev_ellipsis = true; ?><span class="stok-pg-ellipsis">…</span><?php endif;
                    continue;
                endif;
                $prev_ellipsis = false;
            ?>
            <a href="<?= h(mh_url(['page' => $pg])) ?>"
               class="btn btn-sm<?= $pg === $mv_page ? ' btn-primary' : '' ?>"><?= $pg ?></a>
            <?php endfor; ?>
            <?php if ($mv_page < $mv_total_pages): ?>
            <a href="<?= h(mh_url(['page' => $mv_page + 1])) ?>" class="btn btn-sm">Sonraki →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($ms_is_admin): ?>
<!-- ── Hareket Düzenle Modal (admin-only) ────────────────── -->
<div id="msEditOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msEditClose()">
    <div class="ms-edit-modal ms-edit-modal-wide" role="dialog" aria-modal="true" aria-labelledby="msEditTitle">
        <div class="ms-edit-head">
            <h3 id="msEditTitle">✎ Hareket Düzenle <span id="msEditIdBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msEditClose()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msEditForm">
            <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"  value="ms_edit">
            <input type="hidden" name="edit_id" id="msEditId" value="">
            <div class="ms-edit-body">
                <div class="ms-form-grid">
                    <div class="form-group">
                        <label class="form-label">Tarih <span class="req">*</span></label>
                        <input type="date" name="edit_date" id="msEditDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Hareket Tipi <span class="req">*</span></label>
                        <select name="edit_type" id="msEditType" class="form-control" required>
                            <option value="giris">Giriş</option>
                            <option value="sevk">Sevk</option>
                            <option value="kullanim">Kullanım</option>
                            <option value="duzeltme">Düzeltme</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                        <select name="edit_mat_type" id="msEditMatType" class="form-control" required onchange="msEditUpdateNames()">
                            <option value="">— seçiniz —</option>
                            <?php foreach ($ms_types as $k => $lbl): ?>
                            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                        <select name="edit_mat_name" id="msEditMatName" class="form-control" required>
                            <option value="">— önce tür seçin —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Depo <span class="req">*</span></label>
                        <select name="edit_depo" id="msEditDepo" class="form-control" required>
                            <option value="">— seçiniz —</option>
                            <?php foreach ($depo_list as $dv): ?>
                            <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Miktar <span class="req">*</span></label>
                        <input type="number" name="edit_qty" id="msEditQty" class="form-control" required step="any" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Birim</label>
                        <select name="edit_unit" id="msEditUnit" class="form-control">
                            <?php foreach ($ms_units as $u): ?>
                            <option value="<?= h($u) ?>"><?= h($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Belge / İrsaliye No</label>
                        <input type="text" name="edit_belge" id="msEditBelge" class="form-control"
                               placeholder="İsteğe bağlı" data-uppercase="tr">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Firma</label>
                        <input type="text" name="edit_firma" id="msEditFirma" class="form-control"
                               list="mh-firma-list" placeholder="İsteğe bağlı" autocomplete="off" data-uppercase="tr">
                        <datalist id="mh-firma-list">
                            <?php foreach ($firma_list as $fv): ?>
                            <option value="<?= h($fv) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group ms-form-full">
                        <label class="form-label">Not</label>
                        <input type="text" name="edit_note" id="msEditNote" class="form-control" placeholder="İsteğe bağlı">
                    </div>
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msEditClose()">Vazgeç</button>
                <button type="submit" class="btn btn-primary">💾 Kaydet</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Hareket Sil Modal (admin-only) ────────────────────── -->
<div id="msDelOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msDelClose()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msDelTitle">
        <div class="ms-edit-head">
            <h3 id="msDelTitle">🗑 Hareketi Sil</h3>
            <button type="button" class="ms-edit-close" onclick="msDelClose()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msDelForm">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_delete">
            <input type="hidden" name="del_id" id="msDelId" value="">
            <div class="ms-edit-body">
                <div id="msDelDetails" style="background:var(--bg-alt,#f6f8fa);border:1px solid var(--border);border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.86rem;line-height:1.7"></div>
                <div style="font-size:.85rem;color:var(--danger,#dc2626);background:#fff5f5;border:1px solid var(--danger,#dc2626);border-radius:5px;padding:8px 12px;margin-bottom:14px">
                    ⚠ Bu hareket kalıcı olarak silinecek. Bu işlem geri alınamaz.
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.4">
                        <input type="checkbox" id="msDelConfirm" style="margin-top:2px;flex-shrink:0" required>
                        <span>Bu hareketi kalıcı olarak silmek istediğimi onaylıyorum.</span>
                    </label>
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msDelClose()">Vazgeç</button>
                <button type="submit" class="btn" style="background:var(--danger,#dc2626);color:#fff">🗑 Kalıcı Sil</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Hareket Taşı Modal (admin-only) ───────────────────── -->
<div id="msMoveOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msMoveClose()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msMoveTitleEl">
        <div class="ms-edit-head">
            <h3 id="msMoveTitleEl">⇄ Hareket Taşı <span id="msMoveIdBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msMoveClose()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msMoveForm">
            <input type="hidden" name="csrf"              value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"            value="ms_move">
            <input type="hidden" name="move_id"           id="msMoveId" value="">
            <div class="ms-edit-body">
                <div id="msMoveSourceInfo" style="background:var(--bg-alt,#f6f8fa);border:1px solid var(--border);border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.85rem;line-height:1.7"></div>
                <div class="ms-form-grid">
                    <div class="form-group ms-form-full">
                        <label class="form-label">Hedef Malzeme <span class="req">*</span></label>
                        <select name="target_material_id" id="msMoveTargetMat" class="form-control" required onchange="msMoveUpdatePreview()">
                            <option value="">— seçiniz —</option>
                        </select>
                        <div style="font-size:.75rem;color:var(--muted);margin-top:3px">Yalnızca aynı malzeme türündeki aktif tanımlar listelenir.</div>
                    </div>
                    <div class="form-group ms-form-full">
                        <label class="form-label">Hedef Depo</label>
                        <select name="move_depo" id="msMoveDepo" class="form-control" onchange="msMoveUpdatePreview()">
                            <option value="">Mevcut depo korunsun</option>
                            <?php foreach ($depo_list as $dv): ?>
                            <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="msMovePreview" style="display:none;padding:10px 14px;border-radius:6px;border:1px solid var(--warn,#e6a817);background:var(--warn-bg,#fffbf0);font-size:.83rem;line-height:1.6;margin-bottom:10px"></div>
                <div class="form-group">
                    <label class="form-label">Taşıma Sebebi <span class="req">*</span></label>
                    <input type="text" name="move_reason" id="msMoveReason" class="form-control" required
                           placeholder="Örn: Yanlış malzeme seçildi, tanım birleştirildi…" maxlength="200"
                           data-uppercase="tr">
                </div>
                <div class="form-group" style="margin-top:10px">
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.4">
                        <input type="checkbox" id="msMoveConfirm" style="margin-top:2px;flex-shrink:0" required>
                        <span>Bu hareketi seçtiğim hedef malzemeye taşımayı onaylıyorum. Stok bakiyesi otomatik güncellenir.</span>
                    </label>
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msMoveClose()">Vazgeç</button>
                <button type="submit" class="btn btn-primary">⇄ Taşı</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
var msMoveDefs   = <?= json_encode($move_defs_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msNamesData  = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msMoveCtx    = {};
var msEditOrigQty = 0;

// Tam sayı birimi mi? (adet/paket/koli/top/set/çift)
var MS_INT_UNITS = ['adet', 'paket', 'koli', 'top', 'set', 'çift'];
function msIsIntUnit(unit) { return MS_INT_UNITS.indexOf(unit) >= 0; }

// DB float değerini input için formatla: adet→"1112", kg→"12.5" (sıfırları at)
function msFormatQtyForInput(value, unit) {
    var v = parseFloat(String(value).replace(',', '.')) || 0;
    if (msIsIntUnit(unit || 'adet')) return String(Math.round(Math.abs(v)));
    var s = Math.abs(v).toFixed(3);
    return parseFloat(s).toString(); // "12.500" → "12.5"
}

// ── Hareket Taşı Modal ────────────────────────────────────
function msMoveOpen(id, date, mtype, mattype, matid, matname, depo, qty, unit, belge, firma, note) {
    msMoveCtx = {id:id, date:date, mtype:mtype, mattype:mattype, matid:String(matid), matname:matname, depo:depo, qty:qty, unit:unit};
    document.getElementById('msMoveId').value = id;
    document.getElementById('msMoveIdBadge').textContent = '· #' + id;
    document.getElementById('msMoveReason').value = '';
    var cb = document.getElementById('msMoveConfirm');
    if (cb) cb.checked = false;

    var typeLbl = {giris:'Giriş', sevk:'Sevk', kullanim:'Kullanım', duzeltme:'Düzeltme'}[mtype] || mtype;
    var origQty = parseFloat(String(qty).replace(',', '.')) || 0;
    var qSign   = (mtype === 'giris' || (mtype === 'duzeltme' && origQty >= 0)) ? '+' : '−';
    var info = document.getElementById('msMoveSourceInfo');
    if (info) {
        info.innerHTML =
            '<b>Hareket #' + id + '</b> · ' + typeLbl + ' · ' + date + '<br>' +
            '<b>Malzeme:</b> ' + matname + ' (ID #' + matid + ')<br>' +
            '<b>Depo:</b> ' + (depo || '—') + '<br>' +
            '<b>Miktar:</b> ' + qSign + Math.abs(origQty) + ' ' + unit +
            (belge ? '<br><b>Belge:</b> ' + belge : '') +
            (firma ? '<br><b>Firma:</b> ' + firma : '') +
            (note  ? '<br><b>Not:</b> ' + note.substring(0, 60) + (note.length > 60 ? '…' : '') : '');
    }

    // Hedef malzeme listesi: aynı type, kaynak hariç
    var tgtSel = document.getElementById('msMoveTargetMat');
    tgtSel.innerHTML = '<option value="">— seçiniz —</option>';
    var defs = msMoveDefs[mattype] || [];
    defs.forEach(function(d) {
        if (String(d.id) === String(matid)) return;
        var opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name + (d.is_active ? '' : ' [Pasif]');
        if (!d.is_active) opt.style.color = 'var(--muted)';
        tgtSel.appendChild(opt);
    });

    // Depo: mevcut seç
    var depoSel = document.getElementById('msMoveDepo');
    if (depoSel) {
        depoSel.value = depo || '';
        if (!depoSel.value && depo) {
            var opt = document.createElement('option');
            opt.value = depo; opt.textContent = depo; opt.selected = true;
            depoSel.insertBefore(opt, depoSel.options[1]);
        }
    }

    var prev = document.getElementById('msMovePreview');
    if (prev) prev.style.display = 'none';
    document.getElementById('msMoveOverlay').classList.add('open');
}

function msMoveUpdatePreview() {
    var tgtSel  = document.getElementById('msMoveTargetMat');
    var depoSel = document.getElementById('msMoveDepo');
    var preview = document.getElementById('msMovePreview');
    if (!tgtSel || !preview) return;
    var tgtId   = tgtSel.value;
    if (!tgtId) { preview.style.display = 'none'; return; }

    var tgtName = tgtSel.options[tgtSel.selectedIndex].textContent;
    var tgtDepo = (depoSel && depoSel.value) ? depoSel.value : (msMoveCtx.depo || '—');
    var srcDepo = msMoveCtx.depo || '—';
    var srcName = msMoveCtx.matname || '—';
    var origQty = parseFloat(String(msMoveCtx.qty).replace(',', '.')) || 0;
    var mtype   = msMoveCtx.mtype;
    var unit    = msMoveCtx.unit;

    var srcEff, tgtEff;
    if (mtype === 'giris') {
        srcEff = '−' + Math.abs(origQty) + ' ' + unit + ' (giriş etkisi kaynaktan kalkar)';
        tgtEff = '+' + Math.abs(origQty) + ' ' + unit + ' (giriş etkisi hedefe geçer)';
    } else if (mtype === 'sevk') {
        srcEff = '+' + Math.abs(origQty) + ' ' + unit + ' (sevk çıkışı kaynaktan kalkar)';
        tgtEff = '−' + Math.abs(origQty) + ' ' + unit + ' (sevk çıkışı hedefe geçer)';
    } else if (mtype === 'kullanim') {
        srcEff = '+' + Math.abs(origQty) + ' ' + unit + ' (kullanım kaynaktan kalkar)';
        tgtEff = '−' + Math.abs(origQty) + ' ' + unit + ' (kullanım hedefe geçer)';
    } else {
        var s = origQty >= 0 ? '+' : '−';
        srcEff = (origQty >= 0 ? '−' : '+') + Math.abs(origQty) + ' ' + unit + ' (düzeltme kaynaktan kalkar)';
        tgtEff = s + Math.abs(origQty) + ' ' + unit + ' (düzeltme hedefe geçer)';
    }
    preview.style.display = 'block';
    preview.innerHTML =
        '<b>Taşıma Etkisi Önizlemesi</b><br>' +
        '📤 <b>Kaynak:</b> ' + srcName + ' / ' + srcDepo + ' → ' + srcEff + '<br>' +
        '📥 <b>Hedef:</b> ' + tgtName  + ' / ' + tgtDepo + ' → ' + tgtEff;
}

function msMoveClose() {
    var ov = document.getElementById('msMoveOverlay');
    if (ov) ov.classList.remove('open');
}

// ── Hareket Düzenle Modal ─────────────────────────────────
function msEditOpen(data) {
    document.getElementById('msEditId').value       = data.id     || '';
    document.getElementById('msEditDate').value     = data.date   || '';
    document.getElementById('msEditBelge').value    = data.belge  || '';
    document.getElementById('msEditFirma').value    = data.firma  || '';
    document.getElementById('msEditNote').value     = data.note   || '';
    document.getElementById('msEditIdBadge').textContent = '· #' + (data.id || '');
    msEditOrigQty = parseFloat(String(data.qty || '0').replace(',', '.')) || 0;

    // Hareket tipi seç
    var typeSel = document.getElementById('msEditType');
    if (typeSel) typeSel.value = data.type || 'giris';

    // Malzeme türü + adı seç
    var matTypeSel = document.getElementById('msEditMatType');
    if (matTypeSel) {
        matTypeSel.value = data.mat_type || '';
        msEditUpdateNames(data.mat_name || '');
    }

    // Depo seç (listede yoksa ekle)
    var depoSel = document.getElementById('msEditDepo');
    if (depoSel && data.depo) {
        var found = false;
        for (var i = 0; i < depoSel.options.length; i++) {
            if (depoSel.options[i].value === data.depo) { found = true; break; }
        }
        if (!found) {
            var opt = document.createElement('option');
            opt.value = data.depo; opt.textContent = data.depo;
            depoSel.appendChild(opt);
        }
        depoSel.value = data.depo;
    }

    // Birim seç
    var unitSel = document.getElementById('msEditUnit');
    if (unitSel && data.unit) unitSel.value = data.unit;

    // Miktarı birime göre formatla (adet → "1112", kg → "12.5", sıfır yok)
    var qtyUnit = (unitSel && unitSel.value) ? unitSel.value : (data.unit || 'adet');
    document.getElementById('msEditQty').value = msFormatQtyForInput(data.qty || '0', qtyUnit);

    document.getElementById('msEditOverlay').classList.add('open');
    setTimeout(function() {
        var df = document.getElementById('msEditDate');
        if (df) df.focus();
    }, 80);
}

function msEditUpdateNames(selectedName) {
    var matTypeSel = document.getElementById('msEditMatType');
    var matNameSel = document.getElementById('msEditMatName');
    if (!matTypeSel || !matNameSel) return;
    var names = msNamesData[matTypeSel.value] || [];
    matNameSel.innerHTML = '<option value="">— seçiniz —</option>';
    var found = false;
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n; opt.textContent = n;
        if (selectedName && n === selectedName) { opt.selected = true; found = true; }
        matNameSel.appendChild(opt);
    });
    if (!found && selectedName) {
        var opt = document.createElement('option');
        opt.value = selectedName; opt.textContent = selectedName; opt.selected = true;
        matNameSel.appendChild(opt);
    }
}

function msEditClose() {
    var ov = document.getElementById('msEditOverlay');
    if (ov) ov.classList.remove('open');
}

// ── Hareket Sil Modal ────────────────────────────────────
function msDelOpen(id, date, mtype, matname, qty, unit) {
    document.getElementById('msDelId').value = id;
    var cb = document.getElementById('msDelConfirm');
    if (cb) cb.checked = false;

    var typeLbl = {giris:'Giriş', sevk:'Sevk', kullanim:'Kullanım', duzeltme:'Düzeltme'}[mtype] || mtype;
    var origQty = parseFloat((String(qty)).replace(',', '.')) || 0;
    var details = document.getElementById('msDelDetails');
    if (details) {
        details.innerHTML =
            '<b>Hareket #' + id + '</b><br>' +
            '<b>Tür:</b> ' + typeLbl + '<br>' +
            '<b>Tarih:</b> ' + date + '<br>' +
            '<b>Malzeme:</b> ' + matname + '<br>' +
            '<b>Miktar:</b> ' + Math.abs(origQty) + ' ' + unit;
    }
    document.getElementById('msDelOverlay').classList.add('open');
}

function msDelClose() {
    var ov = document.getElementById('msDelOverlay');
    if (ov) ov.classList.remove('open');
}

// Büyük miktar değişikliği uyarısı
var msEditFormEl = document.getElementById('msEditForm');
if (msEditFormEl) {
    msEditFormEl.addEventListener('submit', function(e) {
        var newQtyRaw = document.getElementById('msEditQty') ? document.getElementById('msEditQty').value : '';
        var newQty = parseFloat(newQtyRaw.replace(',', '.')) || 0;
        var origQty = Math.abs(msEditOrigQty);
        if (origQty > 0 && newQty > origQty * 10) {
            var msg = '⚠ Yeni miktar (' + newQty + ') eski değerden (' + origQty + ') 10 kat veya daha fazla büyük.\n\nDevam etmek istediğinizden emin misiniz?';
            if (!confirm(msg)) { e.preventDefault(); }
        }
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { msEditClose(); msDelClose(); msMoveClose(); }
});
</script>

<?php render_footer(); ?>
