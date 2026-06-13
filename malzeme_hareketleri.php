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

// ── POST: Hareket Meta Düzenle — admin-only (Pro-04C) ────
// Sadece belge_no, firma, note güncellenir.
// Stok etkileyen alanlar (tarih, malzeme, miktar, depo) değiştirilemez.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_update') {
    csrf_check($_POST['csrf'] ?? null);
    if (!is_admin()) {
        set_flash('error', 'Bu işlem sadece admin tarafından yapılabilir.');
        header('Location: ' . mh_url());
        exit;
    }
    $up_id    = (int)($_POST['up_id']   ?? 0);
    $up_belge = trim($_POST['up_belge'] ?? '');
    $up_firma = trim($_POST['up_firma'] ?? '');
    $up_note  = trim($_POST['up_note']  ?? '');

    $err  = '';
    $base = null;
    if ($up_id <= 0) {
        $err = 'Geçersiz kayıt.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM material_stock_movements WHERE id=? LIMIT 1");
        $stmt->execute([$up_id]);
        $base = $stmt->fetch();
        if (!$base) {
            $err = 'Kayıt bulunamadı.';
        } elseif ($base['source_type'] === 'loading') {
            $err = 'Yükleme kaynaklı hareketler buradan düzenlenemez. Kaynak yükleme kaydı düzenlenmelidir.';
        }
    }

    if ($err !== '') {
        set_flash('error', $err);
    } else {
        $pdo->prepare(
            "UPDATE material_stock_movements SET belge_no=?, firma=?, note=? WHERE id=?"
        )->execute([$up_belge ?: null, $up_firma ?: null, $up_note ?: null, $up_id]);

        audit_log_event('material_stock_meta_update', 'malzeme_stok', $up_id, [
            'old_belge_no' => $base['belge_no'],
            'old_firma'    => $base['firma'],
            'old_note'     => $base['note'],
        ], [
            'new_belge_no' => $up_belge,
            'new_firma'    => $up_firma,
            'new_note'     => $up_note,
        ]);
        set_flash('success', 'Hareket meta bilgileri güncellendi (#' . $up_id . ').');
    }
    header('Location: ' . mh_url());
    exit;
}

// ── POST: ms_delete — DEVRE DIŞI (Pro-04B) ───────────────
// Kalıcı silme kaldırıldı. Yanlış hareketler için Ters Kayıt veya Taşı kullanılır.
// TODO Pro-04C: ms_update da sadece meta alanlara (belge/not) kısıtlanacak.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_delete') {
    csrf_check($_POST['csrf'] ?? null);
    set_flash('error', 'Kalıcı silme devre dışıdır. Yanlış hareketler için Ters Kayıt veya Taşı işlemini kullanın.');
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

// ── Aksiyon butonları — tablo + mobil kart ortak (Pro-07) ─
// Admin + non-loading hareketlerde Taşı / Ters Kayıt / Bilgi Düzenle döndürür.
// Aynı modal JS fonksiyonlarını çağırır (msMoveOpen / msRevOpen / msOpenEdit).
$mh_action_buttons = function (array $r) use ($ms_is_admin): string {
    if (!$ms_is_admin) return '';
    $can_move = !in_array($r['source_type'], ['loading', 'manual_reverse'], true);
    $id = (int)$r['id'];
    // json_encode + h(): çift tırnak &quot; olur, onclick="" attribute içinde güvenli kalır.
    $j  = fn($v) => h(json_encode($v, JSON_UNESCAPED_UNICODE));
    ob_start();
    if ($can_move): ?>
        <button type="button" class="btn btn-sm ms-act-btn ms-move-btn" title="Başka malzeme tanımına taşı"
                onclick="msMoveOpen(<?= $id ?>, <?= $j($r['movement_date']) ?>, <?= $j($r['movement_type']) ?>, <?= $j($r['material_type']) ?>, <?= $j((string)($r['material_id'] ?? '')) ?>, <?= $j($r['material_name']) ?>, <?= $j($r['depo'] ?? '') ?>, <?= $j((string)$r['quantity']) ?>, <?= $j($r['unit']) ?>, <?= $j($r['belge_no'] ?? '') ?>, <?= $j($r['firma'] ?? '') ?>, <?= $j($r['note'] ?? '') ?>)">⇄ Taşı</button>
    <?php endif; ?>
        <button type="button" class="btn btn-sm ms-act-btn ms-rev-btn" title="Ters Kayıt Oluştur — orijinal hareketi silmeden iptal eder"
                onclick="msRevOpen(<?= $id ?>, <?= $j($r['movement_date']) ?>, <?= $j($r['movement_type']) ?>, <?= $j($r['material_name']) ?>, <?= $j($r['depo'] ?? '') ?>, <?= $j((string)$r['quantity']) ?>, <?= $j($r['unit']) ?>)">↩ Ters Kayıt</button>
        <button type="button" class="btn btn-sm ms-act-btn ms-edit-btn" title="Bilgi Düzenle (belge no / firma / not)"
                data-id="<?= $id ?>" data-date="<?= h($r['movement_date']) ?>" data-mtype="<?= h($r['movement_type']) ?>"
                data-mattype="<?= h($r['material_type']) ?>" data-matname="<?= h($r['material_name']) ?>"
                data-depo="<?= h($r['depo'] ?? '') ?>" data-qty="<?= h((string)$r['quantity']) ?>" data-unit="<?= h($r['unit']) ?>"
                data-belge="<?= h($r['belge_no'] ?? '') ?>" data-firma="<?= h($r['firma'] ?? '') ?>" data-note="<?= h($r['note'] ?? '') ?>"
                onclick="msOpenEdit(this)">✎ Bilgi</button>
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
    ℹ️ Kalıcı silme devre dışıdır. Yanlış hareketler için <b>⇄ Taşı</b> veya <b>↩ Ters Kayıt</b> kullanılır.
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
<!-- ── Hareket Bilgi Düzenle Modal (admin-only, Pro-04C) ── -->
<!-- Sadece meta alanlar: belge_no, firma, note. Stok alanları readonly. -->
<div id="msEditOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msCloseEdit()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msEditTitle">
        <div class="ms-edit-head">
            <h3 id="msEditTitle">✎ Hareket Bilgi Düzenle <span id="msEditTypeBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msCloseEdit()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="<?= h(mh_url()) ?>" autocomplete="off" id="msEditForm">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_update">
            <input type="hidden" name="up_id"  id="msEditId" value="">
            <div class="ms-edit-body">
                <div id="msEditInfoPanel" style="background:var(--bg-alt,#f6f8fa);border:1px solid var(--border);border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:.85rem;line-height:1.7"></div>
                <div style="font-size:.8rem;color:var(--warn-dark,#856404);background:var(--warn-bg,#fffbf0);border:1px solid var(--warn,#e6a817);border-radius:5px;padding:7px 12px;margin-bottom:14px">
                    ⚠ Bu ekranda stok miktarı, malzeme, tarih veya depo değiştirilemez. Yanlış hareket için <b>⇄ Taşı</b> veya <b>↩ Ters Kayıt</b> kullanın.
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / İrsaliye No</label>
                    <input type="text" name="up_belge" id="msEditBelge" class="form-control"
                           placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Firma</label>
                    <input type="text" name="up_firma" id="msEditFirma" class="form-control"
                           list="mh-firma-list" placeholder="İsteğe bağlı" autocomplete="off" data-uppercase="tr">
                    <datalist id="mh-firma-list">
                        <?php foreach ($firma_list as $fv): ?>
                        <option value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Not</label>
                    <input type="text" name="up_note" id="msEditNote" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msCloseEdit()">Vazgeç</button>
                <button type="submit" class="btn btn-primary">💾 Meta Güncelle</button>
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

// ── Hareket Bilgi Düzenle Modal (meta-only, Pro-04C) ─────
function msOpenEdit(btn) {
    var d = btn.dataset;
    document.getElementById('msEditId').value    = d.id    || '';
    document.getElementById('msEditBelge').value = d.belge || '';
    document.getElementById('msEditFirma').value = d.firma || '';
    document.getElementById('msEditNote').value  = d.note  || '';

    var typeLbl = {giris:'Giriş', sevk:'Sevk', kullanim:'Kullanım', duzeltme:'Düzeltme'}[d.mtype] || d.mtype;
    var qty     = parseFloat((d.qty || '0').replace(',', '.')) || 0;
    var qSign   = (d.mtype === 'giris' || (d.mtype === 'duzeltme' && qty >= 0)) ? '+' : '−';
    var matTypeLbl = (d.mattype || '').replace(/_/g, ' ');
    var panel = document.getElementById('msEditInfoPanel');
    if (panel) {
        panel.innerHTML =
            '<b>Hareket #' + d.id + '</b> · ' + typeLbl + ' · ' + (d.date || '') + '<br>' +
            '<b>Malzeme:</b> ' + (d.matname || '—') + '<br>' +
            '<b>Tür:</b> '     + matTypeLbl + '<br>' +
            '<b>Depo:</b> '    + (d.depo || '—') + '<br>' +
            '<b>Miktar:</b> '  + qSign + Math.abs(qty) + ' ' + (d.unit || '');
    }
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
