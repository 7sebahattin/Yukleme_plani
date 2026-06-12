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

// ── URL oluşturucu — filtre durumunu korur ────────────────
function ms_url(array $override = [], array $drop = []): string {
    global $f_tarih_bas, $f_tarih_bit, $f_mat_id, $f_mat_type, $f_mat_name, $f_depo,
           $f_hareket_tipi, $hareket_page, $f_ozet_kategori, $f_ozet_tur, $f_ozet_malzeme, $f_ozet_depo;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
        'mat_id'       => (isset($f_mat_id) && (int)$f_mat_id > 0) ? (string)(int)$f_mat_id : '',
        'mat_type'     => $f_mat_type     ?? '',
        'mat_name'     => $f_mat_name     ?? '',
        'depo'         => $f_depo         ?? '',
        'hareket_tipi' => $f_hareket_tipi ?? '',
        'ozet_kategori'=> $f_ozet_kategori ?? '',
        'ozet_tur'     => $f_ozet_tur     ?? '',
        'ozet_malzeme' => $f_ozet_malzeme ?? '',
        'ozet_depo'    => $f_ozet_depo    ?? '',
        'hareket_page' => (isset($hareket_page) && $hareket_page > 1) ? (string)$hareket_page : '',
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

// ── POST: Hareket Düzenle ─────────────────────────────────
// TODO Pro-04: Miktar/malzeme/tarih update yerine "iptal + yeni hareket"
// standardına geçilecek (geçmiş hareketler değişmez kayıt olacak).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_update') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

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
    header('Location: ' . ms_url());
    exit;
}

// ── POST: Sil ─────────────────────────────────────────────
// TODO Pro-04: Hard delete yerine admin-only void/ters kayıt sistemine
// geçilecek (hareketler kalıcı silinmeyecek, iz bırakarak iptal edilecek).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_delete') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');
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
    header('Location: malzeme_stok.php');
    exit;
}

// ── Filtreler ─────────────────────────────────────────────
$f_tarih_bas    = trim($_GET['tarih_bas']    ?? '');
$f_tarih_bit    = trim($_GET['tarih_bit']    ?? '');
$f_mat_id       = (int)($_GET['mat_id']       ?? 0); // ID bazlı hareket filtresi — isim değişikliğine dayanıklı
$f_mat_type     = trim($_GET['mat_type']     ?? '');
$f_mat_name     = trim($_GET['mat_name']     ?? '');
$f_depo         = trim($_GET['depo']         ?? '');
$is_csv         = isset($_GET['csv']);
$f_hareket_tipi = trim($_GET['hareket_tipi'] ?? '');
if (!in_array($f_hareket_tipi, ['giris', 'sevk', 'kullanim', 'duzeltme', ''], true)) $f_hareket_tipi = '';

// Stok Özeti'ne özel filtreler — yalnızca özet tablosunu daraltır, hareket listesini etkilemez
$f_ozet_kategori = trim($_GET['ozet_kategori'] ?? '');
if (!in_array($f_ozet_kategori, ['kasa', 'palet', 'sarf', 'diger', ''], true)) $f_ozet_kategori = '';
$f_ozet_tur     = trim($_GET['ozet_tur']     ?? '');
$f_ozet_malzeme = trim($_GET['ozet_malzeme'] ?? '');
$f_ozet_depo    = trim($_GET['ozet_depo']    ?? '');

// ── Stok özeti — config/material_stock_helpers.php ────────
// KÖK BUGFIX helper içinde korunur: gruplama material_id bazlıdır;
// material_name yalnızca material_id NULL hareketlerde grup anahtarıdır.
$ozet_rows = get_material_stock_summary($pdo, [
    'tarih_bas'     => $f_tarih_bas,
    'tarih_bit'     => $f_tarih_bit,
    'ozet_kategori' => $f_ozet_kategori,
    'ozet_tur'      => $f_ozet_tur,
    'ozet_malzeme'  => $f_ozet_malzeme,
    'ozet_depo'     => $f_ozet_depo,
]);

// Negatif stok satırları (uyarı bandı için ilk 10)
$negatif_ozet = array_slice(get_negative_materials($ozet_rows), 0, 10);

// ── Hareket listesi ───────────────────────────────────────
$hareket_rows = get_material_movements($pdo, [
    'tarih_bas'    => $f_tarih_bas,
    'tarih_bit'    => $f_tarih_bit,
    'mat_id'       => $f_mat_id,
    'mat_type'     => $f_mat_type,
    'mat_name'     => $f_mat_name,
    'depo'         => $f_depo,
    'hareket_tipi' => $f_hareket_tipi,
], 2000);

$hareket_total = count($hareket_rows);

// ── Dropdown listeleri ────────────────────────────────────
$ms_dd             = get_material_dropdown_data($pdo);
$depo_list         = $ms_dd['depo_list'];
$mat_names_by_type = $ms_dd['mat_names_by_type'];
$firma_list        = $ms_dd['firma_list'];

$herhangi_filtre = $f_tarih_bas !== '' || $f_tarih_bit !== '' || $f_mat_id > 0 || $f_mat_type !== '' || $f_mat_name !== '' || $f_depo !== '' || $f_hareket_tipi !== '';
$ozet_filtre_aktif = $f_ozet_kategori !== '' || $f_ozet_tur !== '' || $f_ozet_malzeme !== '' || $f_ozet_depo !== '';
$ms_can_write = can('stok.write');

// ── CSV export — Stok Özeti (csv=ozet) ─────────────────────
// Yeni özet mantığına uyumlu; ozet_* filtrelerine göre tüm satırları verir (sayfalama yok).
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

// ── CSV export — Hareketler (csv=1) ────────────────────────
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
    .ms-neg-uyari,.stok-ozet-grid,
    .ms-form-wrap,.ms-ozet-filter { display:none !important; }
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

// Precompute summary totals for top cards
$ms_totals       = get_material_stock_totals($pdo, $ozet_rows);
$toplam_giris    = $ms_totals['toplam_giris'];
$toplam_cikis    = $ms_totals['toplam_cikis'];
$mat_kalan_count = $ms_totals['stokta_count'];
$mat_dusuk_count = count($negatif_ozet); // mevcut davranış: kart, uyarı bandındaki (max 10) satır sayısını gösterir
?>

<div class="page-head">
    <h2 class="page-title">🗃️ Malzeme Stok</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="malzeme_stok_import.php" class="btn btn-sm btn-secondary">📥 Excel Aktar</a>
        <a href="<?= ms_url(['csv' => 'ozet', 'hareket_page' => '']) ?>" class="btn btn-sm btn-ghost">⬇ Özet CSV</a>
        <a href="<?= ms_url(['csv' => '1', 'hareket_page' => '']) ?>" class="btn btn-sm btn-ghost">⬇ Hareket CSV</a>
        <button type="button" class="btn btn-sm btn-ghost" onclick="window.print()">🖨 Yazdır</button>
        <?php if (is_admin()): ?>
        <a href="malzeme_stok_tehis.php" class="btn btn-sm btn-ghost" title="Veri kalite ve sistem audit kontrolleri (admin)">🔬 Teknik Teşhis</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($negatif_ozet)): ?>
<!-- ── Negatif Stok Uyarısı ───────────────────────────────── -->
<div class="ms-neg-uyari">
    <div class="ms-neg-uyari-head">⚠ <?= count($negatif_ozet) ?> malzeme/depoda negatif stok — sevk veya kullanım miktarı girişten fazla olabilir.</div>
    <table class="ms-neg-table">
        <thead><tr><th>Malzeme</th><th>Depo</th><th>Birim</th><th class="num">Kalan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($negatif_ozet as $nr):
            $nr_link = ms_url([
                'mat_type'     => $nr['material_type'],
                'mat_name'     => $nr['material_name'],
                'depo'         => $nr['depo'],
                'hareket_tipi' => '',
                'hareket_page' => '',
            ]) . '#ms-hareketler';
        ?>
        <tr>
            <td><?= h($nr['material_name']) ?></td>
            <td><?= h($nr['depo'] ?: '—') ?></td>
            <td style="color:var(--muted);font-size:.8rem"><?= h($nr['unit']) ?></td>
            <td class="num" style="color:var(--danger);font-weight:700"><?= number_format((float)$nr['kalan'], 0, ',', '.') ?></td>
            <td class="num"><a href="<?= h($nr_link) ?>" class="btn btn-sm btn-ghost" style="white-space:nowrap">🔍 Hareketler</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Filtre formu ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="malzeme_stok.php" class="stok-filter-form">
        <?php if ($f_ozet_kategori !== ''): ?><input type="hidden" name="ozet_kategori" value="<?= h($f_ozet_kategori) ?>"><?php endif; ?>
        <?php if ($f_ozet_tur     !== ''): ?><input type="hidden" name="ozet_tur"     value="<?= h($f_ozet_tur) ?>"><?php endif; ?>
        <?php if ($f_ozet_malzeme !== ''): ?><input type="hidden" name="ozet_malzeme" value="<?= h($f_ozet_malzeme) ?>"><?php endif; ?>
        <?php if ($f_ozet_depo    !== ''): ?><input type="hidden" name="ozet_depo"    value="<?= h($f_ozet_depo) ?>"><?php endif; ?>
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
<div class="card" id="ms-ozet-card" style="margin-top:16px;padding:0">
    <!-- Yazdırma başlığı — yalnızca @media print'te görünür -->
    <div class="ms-print-header" style="display:none;padding:0 14px 8px">
        <h2 style="margin:0 0 2px;font-size:14pt">Malzeme Stok Özeti</h2>
        <div style="font-size:9pt;color:#555">
            Tarih: <?= date('d.m.Y H:i') ?>
            <?php if ($f_tarih_bas !== '' || $f_tarih_bit !== ''): ?>
            &nbsp;·&nbsp; Dönem: <?= $f_tarih_bas ? h($f_tarih_bas) : '...' ?> – <?= $f_tarih_bit ? h($f_tarih_bit) : '...' ?>
            <?php endif; ?>
            <?php if ($f_ozet_kategori !== ''): ?>&nbsp;·&nbsp; Kategori: <?= h($ms_cat_labels[$f_ozet_kategori] ?? $f_ozet_kategori) ?><?php endif; ?>
            <?php if ($f_ozet_tur !== ''): ?>&nbsp;·&nbsp; Tür: <?= h($ms_types[$f_ozet_tur] ?? $f_ozet_tur) ?><?php endif; ?>
            <?php if ($f_ozet_malzeme !== ''): ?>&nbsp;·&nbsp; Malzeme: <?= h($f_ozet_malzeme) ?><?php endif; ?>
            <?php if ($f_ozet_depo !== ''): ?>&nbsp;·&nbsp; Depo: <?= h($f_ozet_depo) ?><?php endif; ?>
            &nbsp;·&nbsp; <?= count($ozet_rows) ?> satır
        </div>
    </div>
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Stok Özeti</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($ozet_rows) ?> malzeme/depo</span>
    </div>
    <!-- Stok Özeti'ne özel filtre (kategori / tür / malzeme / depo) -->
    <form method="get" action="malzeme_stok.php" class="ms-ozet-filter">
        <?php if ($f_tarih_bas    !== ''): ?><input type="hidden" name="tarih_bas"    value="<?= h($f_tarih_bas) ?>"><?php endif; ?>
        <?php if ($f_tarih_bit    !== ''): ?><input type="hidden" name="tarih_bit"    value="<?= h($f_tarih_bit) ?>"><?php endif; ?>
        <?php if ($f_mat_id       > 0  ): ?><input type="hidden" name="mat_id"       value="<?= (int)$f_mat_id ?>"><?php endif; ?>
        <?php if ($f_mat_type     !== ''): ?><input type="hidden" name="mat_type"     value="<?= h($f_mat_type) ?>"><?php endif; ?>
        <?php if ($f_mat_name     !== ''): ?><input type="hidden" name="mat_name"     value="<?= h($f_mat_name) ?>"><?php endif; ?>
        <?php if ($f_depo         !== ''): ?><input type="hidden" name="depo"         value="<?= h($f_depo) ?>"><?php endif; ?>
        <?php if ($f_hareket_tipi !== ''): ?><input type="hidden" name="hareket_tipi" value="<?= h($f_hareket_tipi) ?>"><?php endif; ?>
        <div class="ms-ozet-fg">
            <label>Kategori</label>
            <select name="ozet_kategori" class="form-control">
                <option value="">Tümü</option>
                <?php foreach ($ms_cat_labels as $ck => $cl): ?>
                <option value="<?= h($ck) ?>" <?= $f_ozet_kategori === $ck ? 'selected' : '' ?>><?= h($cl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ms-ozet-fg">
            <label>Tür</label>
            <select name="ozet_tur" class="form-control">
                <option value="">Hepsi</option>
                <?php foreach ($ms_types as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= $f_ozet_tur === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ms-ozet-fg">
            <label>Malzeme</label>
            <input type="text" name="ozet_malzeme" class="form-control" value="<?= h($f_ozet_malzeme) ?>"
                   list="ms-ozet-name-list" placeholder="Hepsi" autocomplete="off">
            <datalist id="ms-ozet-name-list">
                <?php foreach (array_merge(...array_values($mat_names_by_type ?: [[]])) as $mn): ?>
                <option value="<?= h($mn) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="ms-ozet-fg">
            <label>Depo</label>
            <select name="ozet_depo" class="form-control">
                <option value="">Hepsi</option>
                <?php foreach ($depo_list as $dv): ?>
                <option value="<?= h($dv) ?>" <?= $f_ozet_depo === $dv ? 'selected' : '' ?>><?= h($dv) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ms-ozet-actions">
            <button type="submit" class="btn btn-sm btn-primary">Süz</button>
            <?php if ($ozet_filtre_aktif): ?>
            <a href="<?= ms_url([], ['ozet_kategori', 'ozet_tur', 'ozet_malzeme', 'ozet_depo']) ?>" class="btn btn-sm btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if (!empty($ozet_rows)): ?>
    <div class="table-wrap">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th class="stok-hide-sm">Kategori</th>
                    <th class="stok-hide-sm">Tür</th>
                    <th>Malzeme</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th style="text-align:right">Giriş</th>
                    <th style="text-align:right;color:var(--danger)">Sevk</th>
                    <th style="text-align:right;color:var(--danger)">Kullanım</th>
                    <th style="text-align:right;color:var(--warn)">Düzeltme</th>
                    <th style="text-align:right">Kalan</th>
                    <th class="stok-hide-sm">Birim</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ozet_rows as $oz):
                $kalan     = (float)$oz['kalan'];
                $is_neg    = $kalan < 0;
                $kalan_cls = $is_neg ? 'stok-negatif' : ($kalan > 0 ? '' : 'color:var(--muted)');
                $duz       = (float)$oz['total_duzeltme'];
                // İsim değişikliğine dayanıklı: tanımlı malzemeler için ID ile filtrele,
                // material_id NULL (tanımsız) hareketler için name+type'a düş.
                $_oz_mid = $oz['material_id'] ?? null;
                if ($_oz_mid !== null && (int)$_oz_mid > 0) {
                    $oz_link = ms_url([
                        'mat_id'       => (int)$_oz_mid,
                        'mat_type'     => '',
                        'mat_name'     => '',
                        'depo'         => $oz['depo'],
                        'hareket_tipi' => '',
                        'hareket_page' => '',
                    ]) . '#ms-hareketler';
                } else {
                    $oz_link = ms_url([
                        'mat_id'       => '',
                        'mat_type'     => $oz['material_type'],
                        'mat_name'     => $oz['material_name'],
                        'depo'         => $oz['depo'],
                        'hareket_tipi' => '',
                        'hareket_page' => '',
                    ]) . '#ms-hareketler';
                }
            ?>
                <tr class="<?= $is_neg ? 'ms-row-negatif' : '' ?>">
                    <td class="stok-hide-sm"><span class="ms-cat-badge ms-cat-<?= h($oz['category']) ?>"><?= h($ms_cat_labels[$oz['category']] ?? $oz['category']) ?></span></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($ms_types[$oz['material_type']] ?? $oz['material_type']) ?></td>
                    <td style="font-weight:600">
                        <?= h($oz['material_name']) ?>
                        <?php if ((int)$oz['is_active'] === 0): ?><span style="font-size:.68rem;color:var(--muted)" title="Pasif tanım"> (pasif)</span><?php endif; ?>
                    </td>
                    <td class="stok-hide-sm"><?= $oz['depo'] !== '' ? h($oz['depo']) : '<span style="color:var(--muted)">Depo Boş</span>' ?></td>
                    <td style="text-align:right;color:var(--success)"><?= (float)$oz['total_giris'] > 0 ? '+' . number_format((float)$oz['total_giris'], 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= (float)$oz['total_sevk'] > 0 ? '−' . number_format((float)$oz['total_sevk'], 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;color:var(--danger)"><?= (float)$oz['total_kullanim'] > 0 ? '−' . number_format((float)$oz['total_kullanim'], 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;color:var(--warn)"><?= $duz != 0.0 ? ($duz > 0 ? '+' : '−') . number_format(abs($duz), 0, ',', '.') : '—' ?></td>
                    <td style="text-align:right;font-weight:700;<?= $kalan_cls ?>">
                        <?= number_format($kalan, 0, ',', '.') ?>
                    </td>
                    <td class="stok-hide-sm" style="font-size:.82rem;color:var(--muted)"><?= h($oz['unit']) ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a href="<?= h($oz_link) ?>" class="ms-edit-btn" title="Bu malzeme/deponun hareketlerini gör">🔍</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:28px;text-align:center;color:var(--muted)">
        Henüz malzeme stok hareketi yok.<?= ($herhangi_filtre || $ozet_filtre_aktif) ? ' Filtre kriterlerine uygun kayıt bulunamadı.' : '' ?>
    </div>
    <?php endif; ?>
</div>

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

<!-- ── Hareket Tipi Filtresi ─────────────────────────────── -->
<div id="ms-hareketler" style="scroll-margin-top:16px"></div>
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
                        <?php if ($ms_can_write && $can_delete): ?>
                        <button type="button" class="ms-edit-btn" title="Düzenle"
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
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                        <form method="post" action="malzeme_stok.php"
                              onsubmit="return confirm('Bu hareketi silmek istediğinizden emin misiniz?');"
                              style="display:inline">
                            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="ms_delete">
                            <input type="hidden" name="del_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="padding:2px 7px;font-size:.75rem;background:var(--danger-light,#fdecea);color:var(--danger)" title="Sil">✕</button>
                        </form>
                        <?php elseif ($r['source_type'] === 'loading'): ?>
                        <span style="font-size:.7rem;color:var(--muted);white-space:nowrap"
                              title="Bu hareket Yük #<?= (int)$r['source_id'] ?> yükleme kaydından otomatik oluşur. Silmek/düzeltmek için ilgili yükleme kaydını düzenleyin.">🔒 Yük #<?= (int)$r['source_id'] ?></span>
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

<!-- Veri Kalite Kontrolü paneli Pro-06'da malzeme_stok_tehis.php'ye taşındı. -->

<?php if ($ms_can_write): ?>
<!-- ── Hareket Düzenle Modal ─────────────────────────────── -->
<div id="msEditOverlay" class="ms-edit-overlay" onclick="if(event.target===this)msCloseEdit()">
    <div class="ms-edit-modal" role="dialog" aria-modal="true" aria-labelledby="msEditTitle">
        <div class="ms-edit-head">
            <h3 id="msEditTitle">Hareketi Düzenle <span id="msEditTypeBadge" style="font-size:.78rem;color:var(--muted)"></span></h3>
            <button type="button" class="ms-edit-close" onclick="msCloseEdit()" aria-label="Kapat">×</button>
        </div>
        <form method="post" action="malzeme_stok.php" autocomplete="off" id="msEditForm">
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
                               list="ms-firma-list" placeholder="İsteğe bağlı" autocomplete="off">
                    </div>
                    <div class="form-group ms-form-full">
                        <label class="form-label">Not</label>
                        <input type="text" name="up_note" id="msEditNote" class="form-control" placeholder="İsteğe bağlı">
                    </div>
                </div>
            </div>
            <div class="ms-edit-foot">
                <button type="button" class="btn btn-ghost" onclick="msCloseEdit()">Vazgeç</button>
                <button type="submit" class="btn btn-primary">💾 Güncelle</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Sistem Audit paneli Pro-06'da malzeme_stok_tehis.php'ye taşındı. -->

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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') msCloseEdit();
});
</script>

<?php render_footer(); ?>
