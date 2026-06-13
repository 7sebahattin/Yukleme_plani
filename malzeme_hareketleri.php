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

$ms_types      = ms_material_types();
$ms_units      = ms_stock_units();

// ── URL oluşturucu — filtre + sayfa durumunu korur ────────
function mh_url(array $override = [], array $drop = []): string {
    global $f_tarih_bas, $f_tarih_bit, $f_mat_id, $f_mat_type, $f_mat_name,
           $f_depo, $f_hareket_tipi, $mv_page;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
        'mat_id'       => (isset($f_mat_id) && (int)$f_mat_id > 0) ? (string)(int)$f_mat_id : '',
        'mat_type'     => $f_mat_type     ?? '',
        'mat_name'     => $f_mat_name     ?? '',
        'depo'         => $f_depo         ?? '',
        'hareket_tipi' => $f_hareket_tipi ?? '',
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
$mv_page        = max(1, (int)($_GET['page'] ?? 1)); // redirect'te sayfa da korunsun (clamp render'da)

// ── POST: Ters Kayıt — zıt miktarlı duzeltme hareketi ────
// Admin-only. Orijinal hareketi silmez; iz bırakarak iptal eder.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_reverse') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Ters kayıt işlemi yalnızca yöneticiler tarafından yapılabilir.');
        header('Location: ' . mh_url());
        exit;
    }
    $rev_id     = (int)($_POST['rev_id']     ?? 0);
    $rev_reason = trim($_POST['rev_reason']  ?? '');

    $err = '';
    $orig = null;
    if ($rev_id <= 0) {
        $err = 'Geçersiz kayıt.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $stmt->execute([$rev_id]);
        $orig = $stmt->fetch();
        if (!$orig) {
            $err = 'Kayıt bulunamadı.';
        } elseif ($orig['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı hareketler ters kayıt ile iptal edilemez.';
        } elseif ($rev_reason === '') {
            $err = 'İptal sebebi zorunludur.';
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $orig_qty  = (float)$orig['quantity'];
        $orig_type = $orig['movement_type'];
        // Ters miktar: giriş→negatif düzeltme, sevk/kullanım→pozitif düzeltme, düzeltme→zıt işaret
        $rev_qty = match ($orig_type) {
            'giris'    => -abs($orig_qty),
            'sevk'     => abs($orig_qty),
            'kullanim' => abs($orig_qty),
            default    => -$orig_qty,
        };

        $orig_unit_dara = (float)($orig['unit_dara_kg'] ?? 0);
        $rev_total_dara = round(abs($rev_qty) * $orig_unit_dara, 3);
        $rev_note  = 'TERS KAYIT: #' . $rev_id . ' nolu hareket iptali. Sebep: ' . $rev_reason;
        $rev_belge = 'REV-#' . $rev_id;

        $pdo->prepare(
            "INSERT INTO material_stock_movements
             (movement_date, movement_type, material_id, material_name, material_type,
              depo, quantity, unit, unit_dara_kg, total_dara_kg, source_type, source_id, belge_no, firma, note)
             VALUES (?, 'duzeltme', ?, ?, ?, ?, ?, ?, ?, ?, 'manual_reverse', ?, ?, ?, ?)"
        )->execute([
            date('Y-m-d'),
            $orig['material_id'], $orig['material_name'], $orig['material_type'],
            $orig['depo'], $rev_qty, $orig['unit'], $orig_unit_dara, $rev_total_dara,
            $rev_id, $rev_belge, $orig['firma'] ?? '', $rev_note,
        ]);

        audit_log_event('material_stock_reverse', 'malzeme_stok', $rev_id, [
            'original_type'     => $orig_type,
            'original_qty'      => $orig_qty,
            'original_material' => $orig['material_name'],
            'depo'              => $orig['depo'],
        ], [
            'reverse_qty'  => $rev_qty,
            'reason'       => $rev_reason,
            'new_movement' => 'duzeltme · manual_reverse · ' . $rev_belge,
        ]);

        set_flash('success', '#' . $rev_id . ' nolu hareket ters kayıt ile iptal edildi (' . $rev_belge . ').');
    }
    header('Location: ' . mh_url());
    exit;
}

// ── POST: Hareket Düzenle — admin-only ───────────────────
// Geçmiş hareketi düzenlemek yerine ters kayıt kullanılması önerilir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_update') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Hareket düzenleme işlemi yalnızca yöneticiler tarafından yapılabilir. Bunun yerine ters kayıt kullanın.');
        header('Location: ' . mh_url());
        exit;
    }

    $up_id       = (int)($_POST['up_id']       ?? 0);
    $up_date     = trim($_POST['up_date']      ?? '');
    $up_mat_type = trim($_POST['up_mat_type']  ?? '');
    $up_mat_name = trim($_POST['up_mat_name']  ?? '');
    $up_depo     = trim($_POST['up_depo']      ?? '');
    $up_qty_raw  = num($_POST['up_qty']        ?? '0');
    $up_yon      = trim($_POST['up_yon']       ?? 'arti');
    $up_unit     = trim($_POST['up_unit']      ?? 'adet');
    $up_belge    = trim($_POST['up_belge']     ?? '');
    $up_firma    = trim($_POST['up_firma']     ?? '');
    $up_note     = trim($_POST['up_note']      ?? '');

    $err = '';
    $base = null;
    if ($up_id <= 0) {
        $err = 'Geçersiz kayıt.';
    } else {
        $base = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $base->execute([$up_id]);
        $base = $base->fetch();
        if (!$base) {
            $err = 'Kayıt bulunamadı.';
        } elseif ($base['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı kullanım hareketleri düzenlenemez.';
        }
    }

    // movement_type ve direction (sevk/giriş/kullanım için) korunur; sadece düzeltme yön değiştirebilir
    if ($err === '') {
        if (!$up_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $up_date)) {
            $err = 'Tarih zorunludur (YYYY-AA-GG).';
        } elseif (!isset($ms_types[$up_mat_type])) {
            $err = 'Malzeme türü seçiniz.';
        } elseif ($up_mat_name === '') {
            $err = 'Malzeme adı zorunludur.';
        } elseif ($up_depo === '') {
            $err = 'Depo seçimi zorunludur.';
        } elseif ($up_qty_raw == 0.0) {
            $err = 'Miktar sıfır olamaz.';
        } elseif ($base['movement_type'] !== 'duzeltme' && abs($up_qty_raw) <= 0) {
            $err = 'Miktar sıfırdan büyük olmalıdır.';
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        // Düzeltme: yön seçilebilir (işaretli miktar). Diğer tipler: her zaman pozitif.
        if ($base['movement_type'] === 'duzeltme') {
            $up_qty = $up_yon === 'eksi' ? -abs($up_qty_raw) : abs($up_qty_raw);
        } else {
            $up_qty = abs($up_qty_raw);
        }

        // Malzeme tanımını yeni tür/ad üzerinden yeniden eşle (dara hesabı için)
        $mat_row    = ms_find_material_definition($pdo, $up_mat_type, $up_mat_name);
        $mat_id     = $mat_row ? (int)$mat_row['id'] : null;
        $unit_dara  = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;
        $total_dara = round($up_qty * $unit_dara, 3);

        $old_vals = [
            'movement_type' => $base['movement_type'],
            'movement_date' => $base['movement_date'],
            'material_name' => $base['material_name'],
            'material_type' => $base['material_type'],
            'depo'          => $base['depo'],
            'quantity'      => $base['quantity'],
            'unit'          => $base['unit'],
            'belge_no'      => $base['belge_no'],
            'firma'         => $base['firma'],
            'note'          => $base['note'],
        ];

        $pdo->prepare(
            "UPDATE material_stock_movements
                SET movement_date=?, material_id=?, material_name=?, material_type=?,
                    depo=?, quantity=?, unit=?, unit_dara_kg=?, total_dara_kg=?,
                    belge_no=?, firma=?, note=?
              WHERE id=?"
        )->execute([
            $up_date, $mat_id, $up_mat_name, $up_mat_type,
            $up_depo, $up_qty, $up_unit, $unit_dara, $total_dara,
            $up_belge, $up_firma, $up_note ?: null, $up_id,
        ]);

        audit_log_event('update', 'malzeme_stok', $up_id, $old_vals, [
            'movement_type' => $base['movement_type'],
            'movement_date' => $up_date,
            'material_name' => $up_mat_name,
            'material_type' => $up_mat_type,
            'depo'          => $up_depo,
            'quantity'      => $up_qty,
            'unit'          => $up_unit,
            'belge_no'      => $up_belge,
            'firma'         => $up_firma,
            'note'          => $up_note,
        ]);
        set_flash('success', 'Hareket güncellendi (#' . $up_id . '): ' . $up_mat_name . ' · ' . fmt_kg($up_qty) . ' ' . $up_unit);
    }
    header('Location: ' . mh_url());
    exit;
}

// ── POST: Kalıcı Sil — admin-only ────────────────────────
// TODO Pro-04B: Hard delete tamamen kaldırılacak; yalnızca ters kayıt kalacak.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_delete') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Hareket silme işlemi yalnızca yöneticiler tarafından yapılabilir. Bunun yerine ters kayıt kullanın.');
        header('Location: ' . mh_url());
        exit;
    }
    $del_id = (int)($_POST['del_id'] ?? 0);
    if ($del_id > 0) {
        $row = $pdo->prepare("SELECT source_type, movement_type, material_name, quantity, unit FROM material_stock_movements WHERE id=? LIMIT 1");
        $row->execute([$del_id]);
        $row = $row->fetch();
        if ($row && $row['source_type'] === 'loading') {
            set_flash('error', 'Yükleme kaynaklı kullanım hareketleri silinemez.');
        } elseif ($row) {
            $pdo->prepare("DELETE FROM material_stock_movements WHERE id=?")->execute([$del_id]);
            audit_log_event('delete', 'malzeme_stok', $del_id, [
                'movement_type' => $row['movement_type'],
                'material_name' => $row['material_name'],
                'quantity'      => $row['quantity'],
                'unit'          => $row['unit'],
            ]);
            set_flash('success', 'Hareket silindi (#' . $del_id . ').');
        }
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

// Taşı modal için: aynı type içinde hedef seçimi (admin-only modal)
$move_defs_by_type = [];
$stmt = $pdo->query(
    "SELECT id, type, name, is_active FROM material_definitions
      WHERE type NOT IN ('firma','depo','bolge','urun','lokasyon')
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
                || $f_mat_type !== '' || $f_mat_name !== '' || $f_depo !== '' || $f_hareket_tipi !== '';
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
                $qty_sign  = $is_giris || ($is_duz && (float)$r['quantity'] >= 0) ? '+' : '−';
                $qty_abs   = abs((float)$r['quantity']);
                $qty_color  = $is_giris ? 'var(--success)' : ($is_duz ? 'var(--warn)' : 'var(--danger)');
                $is_loading = $r['source_type'] === 'loading';
                $can_move   = $ms_is_admin && !in_array($r['source_type'], ['loading', 'manual_reverse'], true);
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
                    <td style="white-space:nowrap">
                        <?php if ($is_loading): ?>
                        <span style="font-size:.7rem;color:var(--muted);white-space:nowrap"
                              title="Bu hareket Yük #<?= (int)$r['source_id'] ?> yükleme kaydından otomatik oluşur. Silmek/düzeltmek için ilgili yükleme kaydını düzenleyin.">🔒 Yük #<?= (int)$r['source_id'] ?></span>
                        <?php elseif ($ms_is_admin): ?>
                        <?php if ($can_move): ?>
                        <button type="button" class="btn btn-sm ms-move-btn"
                                title="Başka malzeme tanımına taşı"
                                style="padding:2px 7px;font-size:.75rem;background:var(--primary-light,#e8f0fe);color:var(--primary,#1a73e8)"
                                onclick="msMoveOpen(
                                    <?= (int)$r['id'] ?>,
                                    <?= json_encode($r['movement_date'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['movement_type'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['material_type'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode((string)($r['material_id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['material_name'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['depo'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode((string)$r['quantity'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['unit'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['belge_no'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['firma'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['note'] ?? '', JSON_UNESCAPED_UNICODE) ?>
                                )">⇄</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm ms-rev-btn"
                                title="Ters Kayıt Oluştur — orijinal hareketi silmeden iptal eder"
                                style="padding:2px 7px;font-size:.75rem;background:var(--warn-light,#fff3cd);color:var(--warn-dark,#856404)"
                                onclick="msRevOpen(
                                    <?= (int)$r['id'] ?>,
                                    <?= json_encode($r['movement_date'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['movement_type'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['material_name'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['depo'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode((string)$r['quantity'], JSON_UNESCAPED_UNICODE) ?>,
                                    <?= json_encode($r['unit'], JSON_UNESCAPED_UNICODE) ?>
                                )">↩</button>
                        <button type="button" class="ms-edit-btn"
                                title="Düzenle (geçmiş hareketi düzenlemek yerine taşı veya ters kayıt kullanılması önerilir)"
                                data-id="<?= (int)$r['id'] ?>"
                                data-date="<?= h($r['movement_date']) ?>"
                                data-mtype="<?= h($r['movement_type']) ?>"
                                data-mattype="<?= h($r['material_type']) ?>"
                                data-matname="<?= h($r['material_name']) ?>"
                                data-depo="<?= h($r['depo'] ?? '') ?>"
                                data-qty="<?= h((string)$r['quantity']) ?>"
                                data-unit="<?= h($r['unit']) ?>"
                                data-belge="<?= h($r['belge_no'] ?? '') ?>"
                                data-firma="<?= h($r['firma'] ?? '') ?>"
                                data-note="<?= h($r['note'] ?? '') ?>"
                                onclick="msOpenEdit(this)">✎</button>
                        <form method="post" action="<?= h(mh_url()) ?>"
                              onsubmit="return confirm('Bu hareketi kalıcı olarak silmek istediğinizden emin misiniz?\n\nBu işlem geri alınamaz. Bunun yerine ters kayıt kullanmanız önerilir.');"
                              style="display:inline">
                            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="ms_delete">
                            <input type="hidden" name="del_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="padding:2px 7px;font-size:.75rem;background:var(--danger-light,#fdecea);color:var(--danger)" title="Kalıcı Sil">✕</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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
<div id="msEditOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msCloseEdit()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msEditTitle">
        <div class="ms-edit-head">
            <h3 id="msEditTitle">Hareketi Düzenle <span id="msEditTypeBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msCloseEdit()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msEditForm">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_update">
            <input type="hidden" name="up_id"  id="msEditId" value="">
            <div class="ms-edit-body">
                <div class="ms-form-grid">
                    <div class="form-group">
                        <label class="form-label">Tarih <span class="req">*</span></label>
                        <input type="date" name="up_date" id="msEditDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                        <select name="up_mat_type" id="msEditMatType" class="form-control" required onchange="msEditUpdateNames()">
                            <option value="">— seçiniz —</option>
                            <?php foreach ($ms_types as $k => $lbl): ?>
                            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                        <select name="up_mat_name" id="msEditMatName" class="form-control" required>
                            <option value="">— önce tür seçin —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Depo <span class="req">*</span></label>
                        <select name="up_depo" id="msEditDepo" class="form-control" required>
                            <option value="">— seçiniz —</option>
                            <?php foreach ($depo_list as $dv): ?>
                            <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Miktar <span class="req">*</span></label>
                        <input type="number" name="up_qty" id="msEditQty" class="form-control" required
                               min="0.001" step="any" placeholder="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Birim</label>
                        <select name="up_unit" id="msEditUnit" class="form-control">
                            <?php foreach ($ms_units as $u): ?>
                            <option value="<?= h($u) ?>"><?= h($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="msEditYonWrap" hidden>
                        <label class="form-label">Yön <span class="req">*</span></label>
                        <select name="up_yon" id="msEditYon" class="form-control">
                            <option value="arti">+ Artı düzeltme (stoka ekle)</option>
                            <option value="eksi">− Eksi düzeltme (stoktan çıkar)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Belge / İrsaliye No</label>
                        <input type="text" name="up_belge" id="msEditBelge" class="form-control" placeholder="İsteğe bağlı">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Firma</label>
                        <input type="text" name="up_firma" id="msEditFirma" class="form-control"
                               list="mh-firma-list" placeholder="İsteğe bağlı" autocomplete="off">
                        <datalist id="mh-firma-list">
                            <?php foreach ($firma_list as $fv): ?>
                            <option value="<?= h($fv) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group ms-form-full">
                        <label class="form-label">Not</label>
                        <input type="text" name="up_note" id="msEditNote" class="form-control" placeholder="İsteğe bağlı">
                    </div>
                </div>
            </div>
            <div class="ms-edit-foot">
                <span style="font-size:.75rem;color:var(--warn-dark,#856404);flex:1;align-self:center">⚠ Geçmiş hareketi düzenlemek yerine ters kayıt kullanılması önerilir.</span>
                <button type="button" class="btn btn-ghost" onclick="msCloseEdit()">Vazgeç</button>
                <button type="submit" class="btn btn-primary">💾 Güncelle</button>
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

<!-- ── Ters Kayıt Modal (admin-only) ─────────────────────── -->
<div id="msRevOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msRevClose()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msRevTitle">
        <div class="ms-edit-head">
            <h3 id="msRevTitle">↩ Ters Kayıt Oluştur <span id="msRevIdBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msRevClose()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msRevForm">
            <input type="hidden" name="csrf"       value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action"     value="ms_reverse">
            <input type="hidden" name="rev_id"     id="msRevId" value="">
            <div class="ms-edit-body">
                <div id="msRevDetails" style="background:var(--bg-alt,#f6f8fa);border:1px solid var(--border);border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:.86rem;line-height:1.7"></div>
                <div class="form-group">
                    <label class="form-label">İptal Sebebi <span class="req">*</span></label>
                    <input type="text" name="rev_reason" id="msRevReason" class="form-control" required
                           placeholder="Örn: Yanlış miktar girildi, yanlış depo seçildi…" maxlength="255"
                           data-uppercase="tr">
                </div>
                <div class="form-group" style="margin-top:10px">
                    <label style="display:flex;align-items:flex-start;gap:8px;font-size:.85rem;cursor:pointer;line-height:1.4">
                        <input type="checkbox" id="msRevConfirm" style="margin-top:2px;flex-shrink:0" required>
                        <span>Orijinal hareket silinmez — zıt miktarlı bir düzeltme kaydı oluşturulur. Bu işlemin geri alınamayacağını anlıyorum.</span>
                    </label>
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msRevClose()">Vazgeç</button>
                <button type="submit" class="btn" style="background:var(--warn,#e6a817);color:#fff">↩ Ters Kayıt Oluştur</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
var msNamesData  = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msMoveDefs   = <?= json_encode($move_defs_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msMoveCtx    = {};

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

// ── Hareket düzenleme modalı ──────────────────────────────
function msEditFillNames(selectedName) {
    var typeSel = document.getElementById('msEditMatType');
    var nameSel = document.getElementById('msEditMatName');
    if (!typeSel || !nameSel) return;
    var names = msNamesData[typeSel.value] || [];
    nameSel.innerHTML = '<option value="">— seçiniz —</option>';
    var found = false;
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n; opt.textContent = n;
        if (n === selectedName) { opt.selected = true; found = true; }
        nameSel.appendChild(opt);
    });
    // Tanımda olmayan ama harekette geçen ad: yine de seçilebilsin
    if (!found && selectedName) {
        var opt = document.createElement('option');
        opt.value = selectedName; opt.textContent = selectedName; opt.selected = true;
        nameSel.appendChild(opt);
    }
    nameSel.disabled = false;
}

function msEditUpdateNames() { msEditFillNames(''); }

function msSetSelect(id, val) {
    var sel = document.getElementById(id);
    if (!sel) return;
    var has = false;
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val) { has = true; break; }
    }
    if (!has && val) {
        var opt = document.createElement('option');
        opt.value = val; opt.textContent = val;
        sel.appendChild(opt);
    }
    sel.value = val;
}

function msOpenEdit(btn) {
    var d = btn.dataset;
    document.getElementById('msEditId').value    = d.id || '';
    document.getElementById('msEditDate').value  = d.date || '';
    msSetSelect('msEditMatType', d.mattype || '');
    msEditFillNames(d.matname || '');
    msSetSelect('msEditDepo', d.depo || '');
    var qty = parseFloat((d.qty || '0').replace(',', '.')) || 0;
    document.getElementById('msEditQty').value   = Math.abs(qty);
    msSetSelect('msEditUnit', d.unit || 'adet');
    document.getElementById('msEditBelge').value = d.belge || '';
    document.getElementById('msEditFirma').value = d.firma || '';
    document.getElementById('msEditNote').value  = d.note || '';

    // Düzeltme tipinde yön seçimi göster; diğer tiplerde gizle (movement_type değişmez)
    var isDuz   = (d.mtype === 'duzeltme');
    var yonWrap = document.getElementById('msEditYonWrap');
    var yonSel  = document.getElementById('msEditYon');
    if (yonWrap) yonWrap.hidden = !isDuz;
    if (yonSel)  yonSel.value = qty < 0 ? 'eksi' : 'arti';

    var typeLbl = {giris:'Giriş', sevk:'Sevk', kullanim:'Kullanım', duzeltme:'Düzeltme'}[d.mtype] || d.mtype;
    document.getElementById('msEditTypeBadge').textContent = '· ' + typeLbl + ' #' + (d.id || '');

    document.getElementById('msEditOverlay').classList.add('open');
}

function msCloseEdit() {
    var ov = document.getElementById('msEditOverlay');
    if (ov) ov.classList.remove('open');
}

// ── Ters Kayıt Modal ──────────────────────────────────────
function msRevOpen(id, date, mtype, matname, depo, qty, unit) {
    document.getElementById('msRevId').value = id;
    document.getElementById('msRevIdBadge').textContent = '· #' + id;
    document.getElementById('msRevReason').value = '';
    var cb = document.getElementById('msRevConfirm');
    if (cb) cb.checked = false;

    var origQty = parseFloat((String(qty)).replace(',', '.')) || 0;
    var revQty;
    if      (mtype === 'giris')    revQty = -Math.abs(origQty);
    else if (mtype === 'sevk')     revQty = Math.abs(origQty);
    else if (mtype === 'kullanim') revQty = Math.abs(origQty);
    else                           revQty = -origQty;

    var typeLbl = {giris:'Giriş', sevk:'Sevk', kullanim:'Kullanım', duzeltme:'Düzeltme'}[mtype] || mtype;
    var origSign = origQty >= 0 ? '+' : '−';
    var revSign  = revQty  >= 0 ? '+' : '−';
    var details  = document.getElementById('msRevDetails');
    if (details) {
        details.innerHTML =
            '<b>Hareket:</b> ' + typeLbl + ' #' + id + ' · ' + matname + ' · ' + (depo || '—') + '<br>' +
            '<b>Tarih:</b> ' + date + '<br>' +
            '<b>Orijinal Miktar:</b> ' + origSign + Math.abs(origQty) + ' ' + unit + '<br>' +
            '<b style="color:var(--warn-dark,#856404)">Oluşturulacak Ters Kayıt:</b> ' +
            'Düzeltme · ' + revSign + Math.abs(revQty) + ' ' + unit + ' (REV-#' + id + ')';
    }

    document.getElementById('msRevOverlay').classList.add('open');
}

function msRevClose() {
    var ov = document.getElementById('msRevOverlay');
    if (ov) ov.classList.remove('open');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { msCloseEdit(); msRevClose(); msMoveClose(); }
});
</script>

<?php render_footer(); ?>
