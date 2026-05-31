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
           $f_hareket_tipi, $hareket_page, $f_ozet_kategori, $f_ozet_tur, $f_ozet_malzeme, $f_ozet_depo;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
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

// ── Stok kategorileri (Kasa / Palet / Sarf / Diğer) ───────
$ms_cat_labels = ['kasa' => 'Kasa', 'palet' => 'Palet', 'sarf' => 'Sarf', 'diger' => 'Diğer'];
// Stok özetine dahil edilen tür grupları (firma/depo/bölge/ürün/lokasyon hariç)
$ms_excluded_types = ['firma', 'depo', 'bolge', 'urun', 'lokasyon'];
$ms_summary_types  = array_values(array_diff(array_keys($ms_types), $ms_excluded_types));

// type → kategori eşlemesi
function ms_cat_of(string $type): string {
    static $map = [
        'kasa_cinsi'    => 'kasa',
        'palet_tipi'    => 'palet',
        'sapka'         => 'sarf', 'kosebent' => 'sarf', 'serit' => 'sarf',
        'kraft_kagit'   => 'sarf', 'taban_kagidi' => 'sarf', 'kose_karton' => 'sarf',
        'kenar_kartonu' => 'sarf', 'casus' => 'sarf', 'kasa_etiketi' => 'sarf',
        'minti'         => 'sarf', 'sale' => 'sarf', 'viyol' => 'sarf', 'file' => 'sarf',
    ];
    return $map[$type] ?? 'diger';
}

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

// ── POST: Referans Düzeltme (mevcut harekete bağlı) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_duzeltme') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

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
        } elseif (trim((string)($base['depo'] ?? '')) === '') {
            set_flash('error', 'Depo seçimi zorunludur.');
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
            $ref_inserted_id = (int)$pdo->lastInsertId();
            audit_log_event('create', 'malzeme_stok', $ref_inserted_id, null, [
                'movement_type' => 'duzeltme',
                'ref_id'        => $dz_id,
                'material_name' => $base['material_name'],
                'material_type' => $base['material_type'],
                'depo'          => $base['depo'],
                'quantity'      => $dz_qty,
                'unit'          => $base['unit'],
                'note'          => $dz_note,
            ]);
            set_flash('success', 'Düzeltme kaydedildi: ' . ($dz_qty > 0 ? '+' : '') . fmt_kg($dz_qty) . ' ' . $base['unit']);
        }
    }
    header('Location: malzeme_stok.php');
    exit;
}

// ── POST: Hareket Düzenle ─────────────────────────────────
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
        $_def_st = $pdo->prepare("SELECT id, name, unit_dara_kg FROM material_definitions WHERE type=?");
        $_def_st->execute([$up_mat_type]);
        $_def_needle = normalize_text_v2($up_mat_name);
        $mat_row = null;
        foreach ($_def_st->fetchAll() as $_def_r) {
            if (normalize_text_v2($_def_r['name']) === $_def_needle) { $mat_row = $_def_r; break; }
        }
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

// ── Stok özeti ─────────────────────────────────────────────
// Ana kaynak: material_definitions. Hareketler material_id ile eşlenir (LEFT JOIN
// mantığı PHP tarafında). Böylece tanımlı ama hareketsiz malzeme 0, girişi olmayan
// ama kullanımı olan malzeme negatif görünür. Tarih filtresi hareket toplamını sınırlar.
$ozet_rows = [];
try {
    // 1) Hareket toplamları — material_id + depo + birim kırılımında (tarih bazlı)
    $agg_where = []; $agg_params = [];
    if ($f_tarih_bas !== '') { $agg_where[] = "movement_date >= ?"; $agg_params[] = $f_tarih_bas; }
    if ($f_tarih_bit !== '') { $agg_where[] = "movement_date <= ?"; $agg_params[] = $f_tarih_bit; }
    $agg_where_sql = $agg_where ? 'WHERE ' . implode(' AND ', $agg_where) : '';

    $agg_st = $pdo->prepare("
        SELECT material_id, material_type, material_name, depo, unit,
            SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS total_giris,
            SUM(CASE WHEN movement_type='sevk'     THEN quantity ELSE 0 END) AS total_sevk,
            SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS total_kullanim,
            SUM(CASE WHEN movement_type='duzeltme' THEN quantity ELSE 0 END) AS total_duzeltme,
            SUM(CASE WHEN movement_type='giris' THEN quantity
                     WHEN movement_type IN ('sevk','kullanim') THEN -quantity
                     WHEN movement_type='duzeltme' THEN quantity
                     ELSE 0 END) AS kalan
        FROM material_stock_movements
        $agg_where_sql
        GROUP BY material_id, material_type, material_name, depo, unit
    ");
    $agg_st->execute($agg_params);
    $agg_rows = $agg_st->fetchAll();

    // material_id → hareket toplamları (bir malzemenin birden çok depo/birim satırı olabilir)
    $agg_by_id = [];
    foreach ($agg_rows as $a) {
        $mid = ($a['material_id'] === null || $a['material_id'] === '') ? null : (int)$a['material_id'];
        if ($mid !== null) $agg_by_id[$mid][] = $a;
    }

    // 2) Dahil edilecek tanımlar (stok türleri)
    $def_rows = [];
    if (!empty($ms_summary_types)) {
        $place  = implode(',', array_fill(0, count($ms_summary_types), '?'));
        $def_st = $pdo->prepare("SELECT id, type, name, is_active FROM material_definitions WHERE type IN ($place) ORDER BY type, name");
        $def_st->execute($ms_summary_types);
        $def_rows = $def_st->fetchAll();
    }

    // satır kurucu (kart/negatif tablo ile uyumlu anahtarlar)
    $mk_row = function (string $cat, string $type, string $name, $depo, $unit, array $a, int $is_active): array {
        $giris = (float)($a['total_giris'] ?? 0);
        $sevk  = (float)($a['total_sevk'] ?? 0);
        $kull  = (float)($a['total_kullanim'] ?? 0);
        return [
            'category'       => $cat,
            'material_type'  => $type,
            'material_name'  => $name,
            'depo'           => (string)($depo ?? ''),
            'unit'           => (string)($unit ?: 'adet'),
            'total_giris'    => $giris,
            'total_sevk'     => $sevk,
            'total_kullanim' => $kull,
            'total_cikis'    => $sevk + $kull,
            'total_duzeltme' => (float)($a['total_duzeltme'] ?? 0),
            'kalan'          => (float)($a['kalan'] ?? 0),
            'is_active'      => $is_active,
        ];
    };

    // 3) Tanım merkezli satırlar
    $seen_ids = [];
    foreach ($def_rows as $d) {
        $did = (int)$d['id'];
        $cat = ms_cat_of($d['type']);
        $act = (int)$d['is_active'];
        if (!empty($agg_by_id[$did])) {
            foreach ($agg_by_id[$did] as $a) {
                $ozet_rows[] = $mk_row($cat, $d['type'], $d['name'], $a['depo'], $a['unit'], $a, $act);
            }
            $seen_ids[$did] = true;
        } elseif ($act === 1) {
            // hareketsiz aktif tanım → kalan 0; pasif + hareketsiz → atla
            $ozet_rows[] = $mk_row($cat, $d['type'], $d['name'], '', 'adet', [], $act);
        }
    }

    // 4) Tanıma bağlanmayan hareketler (material_id NULL ya da dışlanan/silinen tanım)
    foreach ($agg_rows as $a) {
        $mid = ($a['material_id'] === null || $a['material_id'] === '') ? null : (int)$a['material_id'];
        if ($mid !== null && isset($seen_ids[$mid])) continue;            // tanım altında gösterildi
        $atype = (string)($a['material_type'] ?? '');
        if (in_array($atype, $ms_excluded_types, true)) continue;         // firma/depo/ürün vb. atla
        $ozet_rows[] = $mk_row(ms_cat_of($atype), $atype, (string)$a['material_name'], $a['depo'], $a['unit'], $a, 1);
    }

    // 5) ozet_* filtreleri (PHP tarafı)
    if ($f_ozet_kategori !== '' || $f_ozet_tur !== '' || $f_ozet_malzeme !== '' || $f_ozet_depo !== '') {
        $ozet_rows = array_values(array_filter($ozet_rows, function ($r) use ($f_ozet_kategori, $f_ozet_tur, $f_ozet_malzeme, $f_ozet_depo) {
            if ($f_ozet_kategori !== '' && $r['category'] !== $f_ozet_kategori) return false;
            if ($f_ozet_tur      !== '' && $r['material_type'] !== $f_ozet_tur) return false;
            if ($f_ozet_malzeme  !== '' && mb_stripos($r['material_name'], $f_ozet_malzeme) === false) return false;
            if ($f_ozet_depo     !== '' && $r['depo'] !== $f_ozet_depo) return false;
            return true;
        }));
    }

    // 6) Sırala: kategori, tür, malzeme, depo
    usort($ozet_rows, fn($x, $y) =>
        [$x['category'], $x['material_type'], $x['material_name'], $x['depo']]
        <=> [$y['category'], $y['material_type'], $y['material_name'], $y['depo']]
    );
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

// ── Sprint 33D: Veri Kalite Kontrolü ──────────────────────
// Yalnızca tespit/raporlama; otomatik düzeltme veya backfill yok.
function ms_kalite_check(PDO $pdo, string $key, string $label, string $desc, string $cnt_sql, string $row_sql): array {
    try {
        $cnt  = (int)$pdo->query($cnt_sql)->fetchColumn();
        $rows = $cnt > 0 ? $pdo->query($row_sql)->fetchAll() : [];
    } catch (PDOException $e) { $cnt = 0; $rows = []; }
    return ['key' => $key, 'label' => $label, 'desc' => $desc, 'cnt' => $cnt, 'rows' => $rows];
}

$ms_kalite = [];

// 1) Kasa adedi var ama kasa tipi boş → stoktan düşemez
$ms_kalite[] = ms_kalite_check($pdo, 'kasa_tipsiz',
    'Kasa tipi seçilmemiş paletler',
    'Kasa adedi girilmiş ama kasa tipi seçilmemiş — bu kasalar stoktan düşemez.',
    "SELECT COUNT(*) FROM loading_pallets WHERE kasa_adeti > 0 AND (kasa_cinsi_id IS NULL OR kasa_cinsi_id = 0)",
    "SELECT lp.id AS pallet_id, lp.loading_record_id, lp.kasa_adeti, lr.tarih, lr.firma, lr.type
       FROM loading_pallets lp JOIN loading_records lr ON lr.id = lp.loading_record_id
      WHERE lp.kasa_adeti > 0 AND (lp.kasa_cinsi_id IS NULL OR lp.kasa_cinsi_id = 0)
      ORDER BY lp.id DESC LIMIT 10");

// 2) Palet tipi boş
$ms_kalite[] = ms_kalite_check($pdo, 'palet_tipsiz',
    'Palet tipi seçilmemiş paletler',
    'Palet tipi seçilmemiş — palet stoğu bu satırlardan düşemez.',
    "SELECT COUNT(*) FROM loading_pallets WHERE (palet_tipi_id IS NULL OR palet_tipi_id = 0)",
    "SELECT lp.id AS pallet_id, lp.loading_record_id, lp.kasa_adeti, lr.tarih, lr.firma, lr.type
       FROM loading_pallets lp JOIN loading_records lr ON lr.id = lp.loading_record_id
      WHERE (lp.palet_tipi_id IS NULL OR lp.palet_tipi_id = 0)
      ORDER BY lp.id DESC LIMIT 10");

// 3) Deposu boş hareketler
$ms_kalite[] = ms_kalite_check($pdo, 'depo_bos',
    'Deposu boş malzeme hareketleri',
    'Depo bazlı stok bu hareketlerde eksik görünebilir.',
    "SELECT COUNT(*) FROM material_stock_movements WHERE depo IS NULL OR depo = ''",
    "SELECT id, movement_date, material_name, movement_type, quantity, unit, source_type, source_id
       FROM material_stock_movements WHERE depo IS NULL OR depo = ''
      ORDER BY id DESC LIMIT 10");

// 4) Sync dışı yükleme kayıtları (paleti var, loading hareketi yok)
$ms_kalite[] = ms_kalite_check($pdo, 'sync_disi',
    'Stok hareketi oluşmamış kayıtlar',
    'Paletleri var ama malzeme stok hareketleri henüz oluşmamış olabilir (kayıt yeniden kaydedilince oluşur).',
    "SELECT COUNT(*) FROM loading_records lr
      WHERE EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)
        AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)",
    "SELECT lr.id, lr.tarih, lr.firma, lr.type, lr.durum,
            (SELECT COUNT(*) FROM loading_pallets lp WHERE lp.loading_record_id = lr.id) AS palet_sayisi
       FROM loading_records lr
      WHERE EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)
        AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)
      ORDER BY lr.id DESC LIMIT 10");

// 5) Pasif ama kullanımda olan tanımlar
$ms_kalite[] = ms_kalite_check($pdo, 'pasif_kullanim',
    'Pasif ama kullanımda olan tanımlar',
    'Pasif tanımlar geçmiş stok hareketlerinde kullanılıyor.',
    "SELECT COUNT(*) FROM material_definitions md
      WHERE md.is_active = 0 AND EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.material_id = md.id)",
    "SELECT md.id, md.type, md.name,
            (SELECT COUNT(*) FROM material_stock_movements m WHERE m.material_id = md.id) AS hareket_sayisi
       FROM material_definitions md
      WHERE md.is_active = 0 AND EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.material_id = md.id)
      ORDER BY md.type, md.name LIMIT 10");

// 6) Negatif stoktaki kasa/paletler (özet satırlarından — PHP)
$ms_neg_kasa_palet = array_values(array_filter($ozet_rows, fn($r) =>
    in_array($r['category'], ['kasa', 'palet'], true) && (float)$r['kalan'] < 0));
$ms_kalite[] = [
    'key'   => 'neg_kasa_palet',
    'label' => 'Negatif stoktaki kasa/paletler',
    'desc'  => 'Kasa/palet stok girişleri eksik olabilir (kullanım girişten fazla).',
    'cnt'   => count($ms_neg_kasa_palet),
    'rows'  => array_slice($ms_neg_kasa_palet, 0, 10),
];

$ms_kalite_total = array_sum(array_column($ms_kalite, 'cnt'));

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
        <a href="<?= ms_url(['csv' => 'ozet', 'hareket_page' => '']) ?>" class="btn btn-sm btn-ghost">⬇ Özet CSV</a>
        <a href="<?= ms_url(['csv' => '1', 'hareket_page' => '']) ?>" class="btn btn-sm btn-ghost">⬇ Hareket CSV</a>
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
<div class="card" style="margin-top:16px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Stok Özeti</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($ozet_rows) ?> malzeme/depo</span>
    </div>
    <!-- Stok Özeti'ne özel filtre (kategori / tür / malzeme / depo) -->
    <form method="get" action="malzeme_stok.php" class="ms-ozet-filter">
        <?php if ($f_tarih_bas    !== ''): ?><input type="hidden" name="tarih_bas"    value="<?= h($f_tarih_bas) ?>"><?php endif; ?>
        <?php if ($f_tarih_bit    !== ''): ?><input type="hidden" name="tarih_bit"    value="<?= h($f_tarih_bit) ?>"><?php endif; ?>
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
                $oz_link   = ms_url([
                    'mat_type'     => $oz['material_type'],
                    'mat_name'     => $oz['material_name'],
                    'depo'         => $oz['depo'],
                    'hareket_tipi' => '',
                    'hareket_page' => '',
                ]) . '#ms-hareketler';
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

<!-- ── Veri Kalite Kontrolü ──────────────────────────────── -->
<?php
$ms_kalite_aktif = array_values(array_filter($ms_kalite, fn($k) => $k['cnt'] > 0));
$ms_tip_lbl = fn($t) => match ($t) {
    'giris' => 'Giriş', 'sevk' => 'Sevk', 'kullanim' => 'Kullanım', 'duzeltme' => 'Düzeltme', default => $t,
};
$ms_kayit_tip = fn($t) => ($t ?? 'yukleme') === 'cikma' ? 'Çıkma' : 'Yükleme';
?>
<details class="card ms-kalite-card" style="margin-top:18px;border:1px solid var(--border);border-radius:10px;overflow:hidden" <?= $ms_kalite_total > 0 ? 'open' : '' ?>>
    <summary class="ms-kalite-summary">
        🧪 Veri Kalite Kontrolü
        <span class="ms-kalite-pill" style="background:<?= $ms_kalite_total > 0 ? '#fef3c7' : '#d1fae5' ?>;color:<?= $ms_kalite_total > 0 ? '#92400e' : '#065f46' ?>">
            <?= $ms_kalite_total > 0 ? '⚠ ' . $ms_kalite_total . ' kayıt' : '✓ Temiz' ?>
        </span>
    </summary>
    <div style="padding:14px 16px">
        <?php if ($ms_kalite_total === 0): ?>
        <p style="color:var(--success);font-size:.9rem;margin:0">✓ Malzeme stok veri kalitesi temiz görünüyor.</p>
        <?php else: ?>
        <?php foreach ($ms_kalite_aktif as $chk): ?>
        <div class="ms-kalite-block">
            <div class="ms-kalite-head">
                <span class="dkk-badge dkk-badge-orta" style="font-size:.72rem"><?= (int)$chk['cnt'] ?></span>
                <strong style="font-size:.88rem"><?= h($chk['label']) ?></strong>
                <span style="font-size:.78rem;color:var(--muted)"><?= h($chk['desc']) ?></span>
            </div>
            <div class="table-wrap">
                <table class="data-table" style="font-size:.8rem">
                    <?php switch ($chk['key']):
                        case 'kasa_tipsiz':
                        case 'palet_tipsiz': ?>
                        <thead><tr><th>Kayıt</th><th>Palet</th><th>Kasa Adedi</th><th>Tarih</th><th>Firma</th><th>Tür</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($chk['rows'] as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['loading_record_id'] ?></td>
                            <td><?= (int)$r['pallet_id'] ?></td>
                            <td><?= number_format((float)$r['kasa_adeti'], 0, ',', '.') ?></td>
                            <td><?= h(fmt_date($r['tarih'])) ?></td>
                            <td><?= h($r['firma'] ?: '—') ?></td>
                            <td><?= h($ms_kayit_tip($r['type'])) ?></td>
                            <td><a href="record_edit.php?id=<?= (int)$r['loading_record_id'] ?>" class="btn btn-sm btn-ghost">✎ Düzenle</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php break; case 'depo_bos': ?>
                        <thead><tr><th>ID</th><th>Tarih</th><th>Malzeme</th><th>Hareket</th><th>Miktar</th><th>Kaynak</th></tr></thead>
                        <tbody>
                        <?php foreach ($chk['rows'] as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= h(fmt_date($r['movement_date'])) ?></td>
                            <td><?= h($r['material_name'] ?: '—') ?></td>
                            <td><?= h($ms_tip_lbl($r['movement_type'])) ?></td>
                            <td><?= number_format((float)$r['quantity'], 0, ',', '.') ?> <?= h($r['unit']) ?></td>
                            <td><?= $r['source_type'] !== '' ? h($r['source_type'] . ($r['source_id'] ? '#' . $r['source_id'] : '')) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php break; case 'sync_disi': ?>
                        <thead><tr><th>Kayıt</th><th>Tarih</th><th>Firma</th><th>Tür</th><th>Durum</th><th>Palet</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($chk['rows'] as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td><?= h(fmt_date($r['tarih'])) ?></td>
                            <td><?= h($r['firma'] ?: '—') ?></td>
                            <td><?= h($ms_kayit_tip($r['type'])) ?></td>
                            <td><?= h($r['durum'] ?: '—') ?></td>
                            <td><?= (int)$r['palet_sayisi'] ?></td>
                            <td><a href="record_edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-ghost">✎ Düzenle</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php break; case 'pasif_kullanim': ?>
                        <thead><tr><th>ID</th><th>Tür</th><th>Malzeme</th><th>Hareket</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($chk['rows'] as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= h($ms_types[$r['type']] ?? $r['type']) ?></td>
                            <td><?= h($r['name']) ?></td>
                            <td><?= (int)$r['hareket_sayisi'] ?> hareket</td>
                            <td><a href="definitions.php" class="btn btn-sm btn-ghost">→ Tanımlar</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php break; case 'neg_kasa_palet': ?>
                        <thead><tr><th>Kategori</th><th>Malzeme</th><th>Depo</th><th class="num">Kalan</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($chk['rows'] as $r):
                            $lnk = ms_url(['mat_type' => $r['material_type'], 'mat_name' => $r['material_name'], 'depo' => $r['depo'], 'hareket_tipi' => '', 'hareket_page' => '']) . '#ms-hareketler';
                        ?>
                        <tr>
                            <td><span class="ms-cat-badge ms-cat-<?= h($r['category']) ?>"><?= h($ms_cat_labels[$r['category']] ?? $r['category']) ?></span></td>
                            <td><?= h($r['material_name']) ?></td>
                            <td><?= $r['depo'] !== '' ? h($r['depo']) : '<span style="color:var(--muted)">Depo Boş</span>' ?></td>
                            <td class="num" style="color:var(--danger);font-weight:700"><?= number_format((float)$r['kalan'], 0, ',', '.') ?></td>
                            <td><a href="<?= h($lnk) ?>" class="btn btn-sm btn-ghost">🔍 Hareketler</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    <?php break; endswitch; ?>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</details>

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
