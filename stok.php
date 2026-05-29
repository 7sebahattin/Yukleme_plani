<?php
// =========================================================
// stok.php — Depo Stok Takibi + Sayım Kayıtları
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('stok.read');

$pdo = db();

function stok_url(array $override = [], array $drop = []): string {
    global $f_tarih_bas, $f_tarih_bit, $f_firma, $f_urun, $f_depo, $f_parti,
           $f_hareket_tipi, $hareket_page;
    $base = [
        'tarih_bas'    => $f_tarih_bas    ?? '',
        'tarih_bit'    => $f_tarih_bit    ?? '',
        'firma'        => $f_firma        ?? '',
        'urun'         => $f_urun         ?? '',
        'depo'         => $f_depo         ?? '',
        'parti'        => $f_parti        ?? '',
        'hareket_tipi' => $f_hareket_tipi ?? '',
        'hareket_page' => (isset($hareket_page) && $hareket_page > 1) ? (string)$hareket_page : '',
    ];
    foreach ($override as $k => $v) { $base[$k] = (string)$v; }
    foreach ($drop as $k) { unset($base[$k]); }
    return 'stok.php' . (($q = array_filter($base, fn($v) => $v !== '')) ? '?' . http_build_query($q) : '');
}

// ── Hızlı Düzelt (POST) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hizli_duzelt') {
    csrf_check($_POST['csrf'] ?? null);

    $fix_type  = trim($_POST['fix_type']  ?? '');
    $fix_field = trim($_POST['fix_field'] ?? '');
    $fix_id    = (int)($_POST['fix_id']   ?? 0);
    $fix_val   = trim($_POST['fix_val']   ?? '');

    $allowed_kantar = ['depo', 'giris_tarih', 'malin_cinsi', 'firma_adi'];
    $allowed_palet  = ['depo', 'urun_cinsi'];
    $allowed_lr     = ['cikis_nedeni'];

    $hd_ok    = false;
    $hd_error = '';

    if ($fix_id <= 0) {
        $hd_error = 'Geçersiz kayıt ID.';
    } elseif ($fix_type === 'kantar' && in_array($fix_field, $allowed_kantar, true)) {
        if ($fix_field === 'firma_adi')   $fix_val = normalize_firma($fix_val);
        if ($fix_field === 'malin_cinsi') $fix_val = normalize_urun($fix_val);
        if ($fix_field === 'depo')        $fix_val = normalize_depo($fix_val);
        if ($fix_val === '' && $fix_field !== 'giris_tarih') {
            $hd_error = 'Değer boş olamaz.';
        } elseif ($fix_field === 'giris_tarih' && $fix_val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}/', $fix_val)) {
            $hd_error = 'Geçersiz tarih formatı.';
        } else {
            try {
                $sql_map = [
                    'depo'        => "UPDATE kantar_fisleri SET depo = ? WHERE id = ?",
                    'giris_tarih' => "UPDATE kantar_fisleri SET giris_tarih = ? WHERE id = ?",
                    'malin_cinsi' => "UPDATE kantar_fisleri SET malin_cinsi = ? WHERE id = ?",
                    'firma_adi'   => "UPDATE kantar_fisleri SET firma_adi = ? WHERE id = ?",
                ];
                $pdo->prepare($sql_map[$fix_field])->execute([$fix_val, $fix_id]);
                if ($fix_field === 'firma_adi'   && $fix_val !== '') ensure_definition('firma', $fix_val);
                if ($fix_field === 'malin_cinsi' && $fix_val !== '') ensure_definition('urun',  $fix_val);
                if ($fix_field === 'depo'        && $fix_val !== '') ensure_definition('depo',  $fix_val);
                $hd_ok = true;
            } catch (PDOException $e) { $hd_error = 'Güncelleme hatası: ' . $e->getMessage(); }
        }
    } elseif ($fix_type === 'palet' && in_array($fix_field, $allowed_palet, true)) {
        if ($fix_field === 'depo')       $fix_val = normalize_depo($fix_val);
        if ($fix_field === 'urun_cinsi') $fix_val = normalize_urun($fix_val);
        if ($fix_val === '') {
            $hd_error = 'Değer boş olamaz.';
        } else {
            try {
                $sql_map = [
                    'depo'       => "UPDATE loading_pallets SET depo = ? WHERE id = ?",
                    'urun_cinsi' => "UPDATE loading_pallets SET urun_cinsi = ? WHERE id = ?",
                ];
                $pdo->prepare($sql_map[$fix_field])->execute([$fix_val, $fix_id]);
                if ($fix_field === 'depo')       ensure_definition('depo', $fix_val);
                if ($fix_field === 'urun_cinsi') ensure_definition('urun', $fix_val);
                $hd_ok = true;
            } catch (PDOException $e) { $hd_error = 'Güncelleme hatası: ' . $e->getMessage(); }
        }
    } elseif ($fix_type === 'lr' && in_array($fix_field, $allowed_lr, true)) {
        $_cn_list = cikis_nedeni_listesi();
        if ($fix_field === 'cikis_nedeni' && !in_array($fix_val, $_cn_list, true)) {
            $hd_error = 'Geçersiz çıkış nedeni.';
        } else {
            try {
                $pdo->prepare("UPDATE loading_records SET cikis_nedeni = ? WHERE id = ?")
                    ->execute([$fix_val, $fix_id]);
                $hd_ok = true;
            } catch (PDOException $e) { $hd_error = 'Güncelleme hatası: ' . $e->getMessage(); }
        }
    } elseif ($fix_type === 'grup' && $fix_field === 'grup_adi') {
        $fix_val = normalize_firma($fix_val);
        $_firma_def_names = $pdo->query("SELECT name FROM material_definitions WHERE type='firma'")->fetchAll(PDO::FETCH_COLUMN);
        if ($fix_val === '' || !in_array($fix_val, array_map('normalize_text_v2', $_firma_def_names), true)) {
            $hd_error = 'Lütfen tanımlı bir firma seçin.';
        } else {
            try {
                $pdo->prepare("UPDATE kantar_gruplar SET grup_adi = ? WHERE id = ?")
                    ->execute([$fix_val, $fix_id]);
                $hd_ok = true;
            } catch (PDOException $e) { $hd_error = 'Güncelleme hatası: ' . $e->getMessage(); }
        }
    } else {
        $hd_error = 'İzin verilmeyen işlem.';
    }

    if ($hd_ok) {
        audit_log_event('hizli_duzelt', 'stok', $fix_id, null, ['fix_type' => $fix_type, 'fix_field' => $fix_field, 'fix_val' => $fix_val]);
        set_flash('success', 'Kayıt güncellendi (#' . $fix_id . ').');
    } else {
        set_flash('error', $hd_error ?: 'Bilinmeyen hata.');
    }

    $redir = array_filter([
        'tarih_bas' => $_POST['pf_tarih_bas'] ?? '',
        'tarih_bit' => $_POST['pf_tarih_bit'] ?? '',
        'firma'     => $_POST['pf_firma']     ?? '',
        'urun'      => $_POST['pf_urun']      ?? '',
        'depo'      => $_POST['pf_depo']      ?? '',
        'parti'     => $_POST['pf_parti']     ?? '',
        'dkk_open'  => '1',
    ], fn($v) => $v !== '');
    header('Location: stok.php?' . http_build_query($redir));
    exit;
}

// ── Sayım Kaydet (POST) ───────────────────────────────────
$save_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_count') {
    csrf_check($_POST['csrf'] ?? null);

    $pf_tarih_bas = trim($_POST['pf_tarih_bas'] ?? '');
    $pf_tarih_bit = trim($_POST['pf_tarih_bit'] ?? '');
    $pf_firma     = trim($_POST['pf_firma']     ?? '');
    $pf_urun      = trim($_POST['pf_urun']      ?? '');
    $pf_depo      = trim($_POST['pf_depo']      ?? '');
    $pf_parti     = trim($_POST['pf_parti']     ?? '');
    $counted_raw  = trim($_POST['counted_kg']   ?? '');
    $note         = trim($_POST['note']         ?? '');

    $counted_kg = is_numeric($counted_raw) ? (float)$counted_raw : null;

    if ($counted_kg === null) {
        $save_error = 'Sayım KG sayısal bir değer olmalıdır.';
    } elseif ($counted_kg < 0) {
        $save_error = 'Sayım KG negatif olamaz.';
    } else {
        // Sistem kalan KG'sini POST filtrelerine göre sunucuda hesapla
        // Kantar (ham giriş) — parti no kantar tarafını etkilemez
        // Base kantar conditions (tarih/urun/depo — both grouped and ungrouped share these)
        $pk_base = []; $pk_base_p = [];
        if ($pf_tarih_bas !== '') { $pk_base[] = "kf.giris_tarih >= ?";   $pk_base_p[] = $pf_tarih_bas; }
        if ($pf_tarih_bit !== '') { $pk_base[] = "kf.giris_tarih <= ?";   $pk_base_p[] = $pf_tarih_bit . ' 23:59:59'; }
        if ($pf_urun      !== '') { $pk_base[] = "kf.malin_cinsi LIKE ?"; $pk_base_p[] = '%' . $pf_urun . '%'; }
        if ($pf_depo      !== '') { $pk_base[] = "kf.depo LIKE ?";        $pk_base_p[] = '%' . $pf_depo . '%'; }
        // Grouped: firma filter on grup_adi
        $pk_gk = $pk_base; $pk_gk_p = $pk_base_p;
        if ($pf_firma !== '') { $pk_gk[] = "kg.grup_adi LIKE ?"; $pk_gk_p[] = '%' . $pf_firma . '%'; }
        $pk_gk_sql = $pk_gk ? 'WHERE ' . implode(' AND ', $pk_gk) : '';
        // Ungrouped: firma filter on firma_adi + no kantar_gruplar rows
        $pk_uk = $pk_base; $pk_uk_p = $pk_base_p;
        if ($pf_firma !== '') { $pk_uk[] = "kf.firma_adi LIKE ?"; $pk_uk_p[] = '%' . $pf_firma . '%'; }
        $pk_uk[] = "NOT EXISTS (SELECT 1 FROM kantar_gruplar kgx WHERE kgx.fis_id = kf.id)";
        $pk_uk_sql = 'WHERE ' . implode(' AND ', $pk_uk);

        $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tot.total_kasa > 0 THEN GREATEST(0,
            COALESCE(kf.net_kg,0)*kg.kasa_adedi/tot.total_kasa
            - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
            - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi
            ) ELSE 0 END),0)
            FROM kantar_gruplar kg
            JOIN kantar_fisleri kf ON kg.fis_id=kf.id
            JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf.id
            $pk_gk_sql");
        $st->execute($pk_gk_p);
        $post_gelen = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,
            COALESCE(kf.net_kg,0)-COALESCE(kf.kasa_sayisi,0)*COALESCE(kf.kasa_dara,0)-COALESCE(kf.palet_sayisi,0)*COALESCE(kf.palet_dara,0)
            )),0) FROM kantar_fisleri kf $pk_uk_sql");
        $st->execute($pk_uk_p);
        $post_gelen += (float)$st->fetchColumn();

        $pl_where  = ["lr.type IN ('yukleme','cikma')"];  $pl_p = [];
        if ($pf_tarih_bas !== '') { $pl_where[] = "lr.tarih >= ?";        $pl_p[] = $pf_tarih_bas; }
        if ($pf_tarih_bit !== '') { $pl_where[] = "lr.tarih <= ?";        $pl_p[] = $pf_tarih_bit; }
        if ($pf_firma     !== '') { $pl_where[] = "lr.firma LIKE ?";      $pl_p[] = '%' . $pf_firma . '%'; }
        if ($pf_urun      !== '') { $pl_where[] = "lp.urun_cinsi LIKE ?"; $pl_p[] = '%' . $pf_urun . '%'; }
        if ($pf_depo      !== '') { $pl_where[] = "lp.depo LIKE ?";       $pl_p[] = '%' . $pf_depo . '%'; }
        if ($pf_parti     !== '') { $pl_where[] = "lr.parti_no LIKE ?";   $pl_p[] = '%' . $pf_parti . '%'; }
        $pl_where_sql = 'WHERE ' . implode(' AND ', $pl_where);

        $st = $pdo->prepare("SELECT COALESCE(SUM(lp.net_kg),0)
            FROM loading_pallets lp
            JOIN loading_records lr ON lp.loading_record_id = lr.id $pl_where_sql");
        $st->execute($pl_p);
        $post_cikan   = (float)$st->fetchColumn();
        // Formula: ham giriş − toplam çıkış (= teorik kalan)
        $post_kalan   = $post_gelen - $post_cikan;
        $post_diff    = $counted_kg - $post_kalan;

        try {
            $pdo->prepare("INSERT INTO stock_counts
                (count_date, firma, urun, depo, parti_no, system_kg, counted_kg, diff_kg, note)
                VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $pf_firma, $pf_urun, $pf_depo, $pf_parti,
                round($post_kalan,   3),
                round($counted_kg,   3),
                round($post_diff,    3),
                $note !== '' ? $note : null,
            ]);
            audit_log_event('save_count', 'stok', null, null, ['firma' => $pf_firma, 'urun' => $pf_urun, 'depo' => $pf_depo, 'system_kg' => round($post_kalan, 3), 'counted_kg' => round($counted_kg, 3), 'diff_kg' => round($post_diff, 3)]);
            set_flash('success', 'Sayım kaydedildi. Sistem: ' . fmt_kg($post_kalan) . ' kg · Sayım: ' . fmt_kg($counted_kg) . ' kg · Fark: ' . ($post_diff >= 0 ? '+' : '') . fmt_kg($post_diff) . ' kg');
            $rp = array_filter([
                'tarih_bas' => $pf_tarih_bas, 'tarih_bit' => $pf_tarih_bit,
                'firma'     => $pf_firma,     'urun'      => $pf_urun,
                'depo'      => $pf_depo,      'parti'     => $pf_parti,
                'sayim_kg'  => (string)$counted_kg,
            ], fn($v) => $v !== '');
            header('Location: stok.php' . ($rp ? '?' . http_build_query($rp) : ''));
            exit;
        } catch (PDOException $e) {
            $save_error = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

// ── Filtreler (GET) ───────────────────────────────────────
$f_tarih_bas = trim($_GET['tarih_bas'] ?? '');
$f_tarih_bit = trim($_GET['tarih_bit'] ?? '');
$f_firma     = trim($_GET['firma']     ?? '');
$f_urun      = trim($_GET['urun']      ?? '');
$f_depo      = trim($_GET['depo']      ?? '');
$f_parti     = trim($_GET['parti']     ?? '');
$sayim_kg       = isset($_GET['sayim_kg']) && $_GET['sayim_kg'] !== '' ? (float)$_GET['sayim_kg'] : null;
$is_csv         = isset($_GET['csv']);
$is_dkk_csv     = isset($_GET['dkk_csv']);
$f_hareket_tipi = trim($_GET['hareket_tipi'] ?? '');
if (!in_array($f_hareket_tipi, ['gelen', 'yukleme', 'cikma', ''], true)) $f_hareket_tipi = '';

// ── Ham Giriş (kantar_fisleri) — parti no kantar tarafını etkilemez ──
$k_where  = [];
$k_params = [];
if ($f_tarih_bas !== '') { $k_where[] = "giris_tarih >= ?";   $k_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $k_where[] = "giris_tarih <= ?";   $k_params[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_firma     !== '') { $k_where[] = "LOWER(TRIM(firma_adi)) LIKE LOWER(?)";   $k_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $k_where[] = "LOWER(TRIM(malin_cinsi)) LIKE LOWER(?)"; $k_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $k_where[] = "LOWER(TRIM(depo)) LIKE LOWER(?)";        $k_params[] = '%' . $f_depo . '%'; }
// NOT: $f_parti kantar tarafına uygulanmaz — kantar kayıtları partiye bağlı değildir
$k_where_sql = $k_where ? 'WHERE ' . implode(' AND ', $k_where) : '';

// Grouped/ungrouped split: firma filter differs (grup_adi vs firma_adi)
$gk_base = []; $gk_base_p = [];
if ($f_tarih_bas !== '') { $gk_base[] = "kf.giris_tarih >= ?";   $gk_base_p[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $gk_base[] = "kf.giris_tarih <= ?";   $gk_base_p[] = $f_tarih_bit . ' 23:59:59'; }
if ($f_urun      !== '') { $gk_base[] = "LOWER(TRIM(kf.malin_cinsi)) LIKE LOWER(?)"; $gk_base_p[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $gk_base[] = "LOWER(TRIM(kf.depo)) LIKE LOWER(?)";        $gk_base_p[] = '%' . $f_depo . '%'; }
$gk_conds = $gk_base; $gk_p = $gk_base_p;
if ($f_firma !== '') { $gk_conds[] = "LOWER(TRIM(kg.grup_adi)) LIKE LOWER(?)"; $gk_p[] = '%' . $f_firma . '%'; }
$gk_sql = $gk_conds ? 'WHERE ' . implode(' AND ', $gk_conds) : '';
$uk_conds = $gk_base; $uk_p = $gk_base_p;
if ($f_firma !== '') { $uk_conds[] = "LOWER(TRIM(kf.firma_adi)) LIKE LOWER(?)"; $uk_p[] = '%' . $f_firma . '%'; }
$uk_conds[] = "NOT EXISTS (SELECT 1 FROM kantar_gruplar kgx WHERE kgx.fis_id = kf.id)";
$uk_sql = 'WHERE ' . implode(' AND ', $uk_conds);

$st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tot.total_kasa > 0 THEN GREATEST(0,
    COALESCE(kf.net_kg,0)*kg.kasa_adedi/tot.total_kasa
    - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
    - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi
    ) ELSE 0 END),0)
    FROM kantar_gruplar kg
    JOIN kantar_fisleri kf ON kg.fis_id=kf.id
    JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf.id
    $gk_sql");
$st->execute($gk_p);
$gelen_kg = (float)$st->fetchColumn();
$st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,
    COALESCE(kf.net_kg,0)-COALESCE(kf.kasa_sayisi,0)*COALESCE(kf.kasa_dara,0)-COALESCE(kf.palet_sayisi,0)*COALESCE(kf.palet_dara,0)
    )),0) FROM kantar_fisleri kf $uk_sql");
$st->execute($uk_p);
$gelen_kg += (float)$st->fetchColumn();

// ── Çıkışlar (loading_pallets + loading_records) ───────────
$l_where  = ["lr.type IN ('yukleme','cikma')"];
$l_params = [];
if ($f_tarih_bas !== '') { $l_where[] = "lr.tarih >= ?";        $l_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $l_where[] = "lr.tarih <= ?";        $l_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $l_where[] = "LOWER(TRIM(lr.firma)) LIKE LOWER(?)";      $l_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $l_where[] = "LOWER(TRIM(lp.urun_cinsi)) LIKE LOWER(?)"; $l_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $l_where[] = "LOWER(TRIM(lp.depo)) LIKE LOWER(?)";       $l_params[] = '%' . $f_depo . '%'; }
if ($f_parti     !== '') { $l_where[] = "lr.parti_no LIKE ?";   $l_params[] = '%' . $f_parti . '%'; }
$l_where_sql = 'WHERE ' . implode(' AND ', $l_where);

// Yükleme / Çıkma kırılımı (tek sorgu)
$st = $pdo->prepare("SELECT lr.type, COALESCE(SUM(lp.net_kg),0) AS kg
    FROM loading_pallets lp
    JOIN loading_records lr ON lp.loading_record_id = lr.id $l_where_sql GROUP BY lr.type");
$st->execute($l_params);
$cikan_kirilim = ['yukleme' => 0.0, 'cikma' => 0.0];
foreach ($st->fetchAll() as $row) {
    if (isset($cikan_kirilim[$row['type']])) {
        $cikan_kirilim[$row['type']] = (float)$row['kg'];
    }
}

$yukleme_kg    = $cikan_kirilim['yukleme'];
$fire_cikma_kg = $cikan_kirilim['cikma'];
$cikan_kg      = $yukleme_kg + $fire_cikma_kg;
// Ham giriş − yüklenen iyi ürün − fire/çıkma = teorik kalan
$kalan_kg      = $gelen_kg - $yukleme_kg - $fire_cikma_kg;
$sayim_fark    = $sayim_kg !== null ? ($sayim_kg - $kalan_kg) : null;

// ── Hareket satırları ─────────────────────────────────────
// Grouped kantar rows: one row per group, firma = grup_adi
$st = $pdo->prepare("SELECT
    kf.giris_tarih AS tarih, 'gelen' AS yon, kg.grup_adi AS firma,
    kf.malin_cinsi AS urun, kf.depo AS depo, '' AS bolge, '' AS parti,
    kf.net_kg AS brut_kg,
    (COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
     + COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi) AS dara_kg,
    CASE WHEN tot.total_kasa > 0 THEN GREATEST(0,
        COALESCE(kf.net_kg,0)*kg.kasa_adedi/tot.total_kasa
        - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
        - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi
    ) ELSE 0 END AS net_kg,
    kf.id AS kantar_id, NULL AS lr_id, '' AS cikis_nedeni,
    CONCAT('KNT-', kf.fis_no) AS ref_no
  FROM kantar_gruplar kg
  JOIN kantar_fisleri kf ON kg.fis_id=kf.id
  JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf.id
  $gk_sql
  ORDER BY kf.giris_tarih DESC, kf.id DESC, kg.sira ASC LIMIT 5000");
$st->execute($gk_p);
$kantar_rows = $st->fetchAll();
// Ungrouped kantar rows: single row per fiş, firma = firma_adi
$st = $pdo->prepare("SELECT
    kf.giris_tarih AS tarih, 'gelen' AS yon, kf.firma_adi AS firma,
    kf.malin_cinsi AS urun, kf.depo AS depo, '' AS bolge, '' AS parti,
    kf.net_kg AS brut_kg,
    (COALESCE(kf.kasa_sayisi,0)*COALESCE(kf.kasa_dara,0)+COALESCE(kf.palet_sayisi,0)*COALESCE(kf.palet_dara,0)) AS dara_kg,
    GREATEST(0, COALESCE(kf.net_kg,0)-COALESCE(kf.kasa_sayisi,0)*COALESCE(kf.kasa_dara,0)-COALESCE(kf.palet_sayisi,0)*COALESCE(kf.palet_dara,0)) AS net_kg,
    kf.id AS kantar_id, NULL AS lr_id, '' AS cikis_nedeni,
    CONCAT('KNT-', kf.fis_no) AS ref_no
  FROM kantar_fisleri kf $uk_sql
  ORDER BY kf.giris_tarih DESC, kf.id DESC LIMIT 5000");
$st->execute($uk_p);
$kantar_rows = array_merge($kantar_rows, $st->fetchAll());

$st = $pdo->prepare("SELECT
    lr.tarih AS tarih, lr.type AS yon, lr.firma AS firma,
    lp.urun_cinsi AS urun, lp.depo AS depo, lr.bolge AS bolge,
    lr.parti_no AS parti, lp.brut_kg, lp.dara_kg, lp.net_kg,
    NULL AS kantar_id, lr.id AS lr_id, lr.cikis_nedeni,
    lr.parti_no AS ref_no
  FROM loading_pallets lp
  JOIN loading_records lr ON lp.loading_record_id = lr.id $l_where_sql
  ORDER BY lr.tarih DESC, lr.id DESC LIMIT 5000");
$st->execute($l_params);
$loading_rows = $st->fetchAll();

$hareket_rows = array_merge($kantar_rows, $loading_rows);
usort($hareket_rows, function ($a, $b) {
    $cmp = strcmp((string)($b['tarih'] ?? ''), (string)($a['tarih'] ?? ''));
    return $cmp !== 0 ? $cmp : strcmp($b['yon'] ?? '', $a['yon'] ?? '');
});
if ($f_hareket_tipi !== '') {
    $hareket_rows = array_values(array_filter($hareket_rows, fn($r) => $r['yon'] === $f_hareket_tipi));
}
$hareket_total = count($hareket_rows);

// ── Geçmiş sayımlar ───────────────────────────────────────
$gecmis_sayimlar = [];
try {
    $sc_where  = [];
    $sc_params = [];
    if ($f_firma !== '') { $sc_where[] = "firma LIKE ?";    $sc_params[] = '%' . $f_firma . '%'; }
    if ($f_urun  !== '') { $sc_where[] = "urun LIKE ?";     $sc_params[] = '%' . $f_urun . '%'; }
    if ($f_depo  !== '') { $sc_where[] = "depo LIKE ?";     $sc_params[] = '%' . $f_depo . '%'; }
    if ($f_parti !== '') { $sc_where[] = "parti_no LIKE ?"; $sc_params[] = '%' . $f_parti . '%'; }
    $sc_where_sql = $sc_where ? 'WHERE ' . implode(' AND ', $sc_where) : '';
    $st = $pdo->prepare("SELECT * FROM stock_counts $sc_where_sql ORDER BY count_date DESC, id DESC LIMIT 20");
    $st->execute($sc_params);
    $gecmis_sayimlar = $st->fetchAll();
} catch (PDOException $e) {}

// ── Stok Doğruluk Kontrolü — WHERE blokları (CSV + özet + detay paylaşır) ──
$k_extra = $k_where_sql ? $k_where_sql . ' AND ' : 'WHERE ';

$qc_lp_where  = ["lr.type IN ('yukleme','cikma')"];
$qc_lp_params = [];
if ($f_tarih_bas !== '') { $qc_lp_where[] = "lr.tarih >= ?";        $qc_lp_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $qc_lp_where[] = "lr.tarih <= ?";        $qc_lp_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $qc_lp_where[] = "lr.firma LIKE ?";      $qc_lp_params[] = '%' . $f_firma . '%'; }
if ($f_urun      !== '') { $qc_lp_where[] = "lp.urun_cinsi LIKE ?"; $qc_lp_params[] = '%' . $f_urun . '%'; }
if ($f_depo      !== '') { $qc_lp_where[] = "lp.depo LIKE ?";       $qc_lp_params[] = '%' . $f_depo . '%'; }
$qc_lp_sql = 'WHERE ' . implode(' AND ', $qc_lp_where);

$qc_lr_where  = ["type IN ('yukleme','cikma')"];
$qc_lr_params = [];
if ($f_tarih_bas !== '') { $qc_lr_where[] = "tarih >= ?";   $qc_lr_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $qc_lr_where[] = "tarih <= ?";   $qc_lr_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $qc_lr_where[] = "firma LIKE ?"; $qc_lr_params[] = '%' . $f_firma . '%'; }
$qc_lr_sql = 'WHERE ' . implode(' AND ', $qc_lr_where);

$qc_cn_where  = ["type = 'cikma'"];
$qc_cn_params = [];
if ($f_tarih_bas !== '') { $qc_cn_where[] = "tarih >= ?";   $qc_cn_params[] = $f_tarih_bas; }
if ($f_tarih_bit !== '') { $qc_cn_where[] = "tarih <= ?";   $qc_cn_params[] = $f_tarih_bit; }
if ($f_firma     !== '') { $qc_cn_where[] = "firma LIKE ?"; $qc_cn_params[] = '%' . $f_firma . '%'; }
$qc_cn_sql = 'WHERE ' . implode(' AND ', $qc_cn_where);

// Pre-compute: normalize_text_v2 ile tanımsız kantar grubu ID'leri
$_bad_grup_ids = [];
try {
    $_firma_norms = array_map(
        'normalize_text_v2',
        $pdo->query("SELECT name FROM material_definitions WHERE type='firma'")->fetchAll(PDO::FETCH_COLUMN)
    );
    foreach ($pdo->query("SELECT id, grup_adi FROM kantar_gruplar WHERE grup_adi != ''")->fetchAll() as $_gr) {
        if (!in_array(normalize_text_v2($_gr['grup_adi']), $_firma_norms, true)) {
            $_bad_grup_ids[] = (int)$_gr['id'];
        }
    }
} catch (PDOException $e) {}
$_bad_grup_sql = empty($_bad_grup_ids)
    ? '1=0'
    : 'kg.id IN (' . implode(',', $_bad_grup_ids) . ')';

// [count_sql, params, label, severity, url, url_label, detail_msg]
$check_defs = [
    ["SELECT COUNT(*) FROM kantar_fisleri {$k_extra}net_kg = 0",
     $k_params, 'Net KG sıfır — kantar fişi', 'kritik', 'kantar.php', 'Kantar Fişleri',
     'Stok hesabına 0 kg giriyor; tartım eksik veya hatalı.'],
    ["SELECT COUNT(*) FROM kantar_fisleri {$k_extra}depo = ''",
     $k_params, 'Deposu boş — kantar fişi', 'kritik', 'kantar.php', 'Kantar Fişleri',
     'Depo filtresiyle eşleşmez; depo bazlı stoktan hariç kalır.'],
    ["SELECT COUNT(*) FROM kantar_fisleri {$k_extra}(giris_tarih = '' OR giris_tarih NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}')",
     $k_params, 'Tarihi boş/hatalı — kantar fişi', 'kritik', 'kantar.php', 'Kantar Fişleri',
     'Tarih filtresi bu girişleri atlayabilir; döneme dahil edilemez.'],
    ["SELECT COUNT(*) FROM kantar_fisleri {$k_extra}malin_cinsi = ''",
     $k_params, 'Mal cinsi boş — kantar fişi', 'orta', 'kantar.php', 'Kantar Fişleri',
     'Ürün filtresiyle eşleşmez.'],
    ["SELECT COUNT(*) FROM kantar_fisleri {$k_extra}firma_adi = ''",
     $k_params, 'Firması boş — kantar fişi', 'orta', 'kantar.php', 'Kantar Fişleri',
     'Firma filtresiyle eşleşmez.'],
    ["SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.net_kg = 0",
     $qc_lp_params, 'Net KG sıfır — yükleme/çıkma paleti', 'kritik', 'records.php', 'Yüklemeler',
     'Çıkış hesabına 0 kg katkısı var; brüt veya dara girişi eksik.'],
    ["SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.depo = ''",
     $qc_lp_params, 'Deposu boş — yükleme/çıkma paleti', 'kritik', 'records.php', 'Yüklemeler',
     'Depo filtresiyle eşleşmez; depo bazlı stoktan düşmez.'],
    ["SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.urun_cinsi = ''",
     $qc_lp_params, 'Ürün cinsi boş — yükleme/çıkma paleti', 'orta', 'records.php', 'Yüklemeler',
     'Ürün filtresiyle eşleşmez.'],
    ["SELECT COUNT(*) FROM loading_records $qc_lr_sql AND NOT EXISTS (SELECT 1 FROM loading_pallets WHERE loading_record_id = loading_records.id)",
     $qc_lr_params, 'Paletsiz yükleme/çıkma kaydı', 'kritik', 'records.php', 'Yüklemeler',
     'Palet satırı yok; çıkış hesabına dahil edilemiyor.'],
    ["SELECT COUNT(*) FROM loading_records $qc_cn_sql AND cikis_nedeni = ''",
     $qc_cn_params, 'Çıkış nedeni boş — çıkma kaydı', 'dusuk', 'cikmalar.php', 'Çıkmalar',
     'Fire veya kötü ürün sebebi girilmemiş.'],
    ["SELECT COUNT(*) FROM kantar_gruplar kg JOIN kantar_fisleri kf ON kg.fis_id = kf.id WHERE {$_bad_grup_sql}",
     [], 'Tanımsız firma adı — kantar grubu', 'orta', 'kantar.php', 'Kantar',
     'Grup firma adı tanımlı firma listesinde yok; stok firma filtresiyle eşleşmeyebilir.'],
    ["SELECT COUNT(*) FROM (
        SELECT kg.fis_id,
            GREATEST(0, MAX(COALESCE(kf.net_kg,0))
                - MAX(COALESCE(kf.kasa_sayisi,0))*MAX(COALESCE(kf.kasa_dara,0))
                - MAX(COALESCE(kf.palet_sayisi,0))*MAX(COALESCE(kf.palet_dara,0))) AS fis_net,
            SUM(CASE WHEN tot.total_kasa>0 THEN GREATEST(0,
                COALESCE(kf.net_kg,0)*kg.kasa_adedi/tot.total_kasa
                - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
                - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi
            ) ELSE 0 END) AS grup_total
        FROM kantar_gruplar kg
        JOIN kantar_fisleri kf ON kg.fis_id=kf.id
        JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf.id
        GROUP BY kg.fis_id
     ) _t WHERE ABS(_t.fis_net - _t.grup_total) > 0.5",
     [], 'Gruplandırma toplamı uyuşmazlığı', 'kritik', 'kantar.php', 'Kantar',
     'Grup net kg toplamı fiş ürün netinden 0.5 kg\'dan fazla sapıyor; stok hesabı tutarsız.'],
];

// ── Özet sayılar ─────────────────────────────────────────
$quality_checks = [];
foreach ($check_defs as $chk) {
    [$sql, $params, $label, $severity, $url, $url_label, $detail] = $chk;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $quality_checks[] = compact('n', 'label', 'severity', 'url', 'url_label', 'detail');
        }
    } catch (PDOException $e) {}
}

// ── Detay kayıtları (ilk 20 / grup) ──────────────────────
// Her entry: label, severity, total, rows, url_label, fix_type, fix_field
// row sütunları: id, tarih, firma, urun, depo, net_kg, fix_link
// fix_type: 'kantar'|'palet'|'lr'|null; fix_field: DB kolon adı|null
$quality_detail = [];
$qd_defs = [
    // [label, severity, url_label, count_sql, c_params, rows_sql, r_params, fix_type, fix_field]
    ['Net KG sıfır — kantar fişi', 'kritik', 'Kantar Fişleri',
     "SELECT COUNT(*) FROM kantar_fisleri {$k_extra}net_kg = 0", $k_params,
     "SELECT id, giris_tarih AS tarih, firma_adi AS firma, malin_cinsi AS urun,
             depo, net_kg, CONCAT('kantar_edit.php?id=', id) AS fix_link
      FROM kantar_fisleri {$k_extra}net_kg = 0 ORDER BY id DESC LIMIT 20", $k_params,
     null, null],
    ['Deposu boş — kantar fişi', 'kritik', 'Kantar Fişleri',
     "SELECT COUNT(*) FROM kantar_fisleri {$k_extra}depo = ''", $k_params,
     "SELECT id, giris_tarih AS tarih, firma_adi AS firma, malin_cinsi AS urun,
             depo, net_kg, CONCAT('kantar_edit.php?id=', id) AS fix_link
      FROM kantar_fisleri {$k_extra}depo = '' ORDER BY id DESC LIMIT 20", $k_params,
     'kantar', 'depo'],
    ['Tarihi boş/hatalı — kantar fişi', 'kritik', 'Kantar Fişleri',
     "SELECT COUNT(*) FROM kantar_fisleri {$k_extra}(giris_tarih = '' OR giris_tarih NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}')", $k_params,
     "SELECT id, giris_tarih AS tarih, firma_adi AS firma, malin_cinsi AS urun,
             depo, net_kg, CONCAT('kantar_edit.php?id=', id) AS fix_link
      FROM kantar_fisleri {$k_extra}(giris_tarih = '' OR giris_tarih NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}')
      ORDER BY id DESC LIMIT 20", $k_params,
     'kantar', 'giris_tarih'],
    ['Mal cinsi boş — kantar fişi', 'orta', 'Kantar Fişleri',
     "SELECT COUNT(*) FROM kantar_fisleri {$k_extra}malin_cinsi = ''", $k_params,
     "SELECT id, giris_tarih AS tarih, firma_adi AS firma, malin_cinsi AS urun,
             depo, net_kg, CONCAT('kantar_edit.php?id=', id) AS fix_link
      FROM kantar_fisleri {$k_extra}malin_cinsi = '' ORDER BY id DESC LIMIT 20", $k_params,
     'kantar', 'malin_cinsi'],
    ['Firması boş — kantar fişi', 'orta', 'Kantar Fişleri',
     "SELECT COUNT(*) FROM kantar_fisleri {$k_extra}firma_adi = ''", $k_params,
     "SELECT id, giris_tarih AS tarih, firma_adi AS firma, malin_cinsi AS urun,
             depo, net_kg, CONCAT('kantar_edit.php?id=', id) AS fix_link
      FROM kantar_fisleri {$k_extra}firma_adi = '' ORDER BY id DESC LIMIT 20", $k_params,
     'kantar', 'firma_adi'],
    ['Net KG sıfır — yükleme/çıkma paleti', 'kritik', 'Yüklemeler',
     "SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.net_kg = 0", $qc_lp_params,
     "SELECT lp.id AS id, lr.tarih, lr.firma, lp.urun_cinsi AS urun,
             lp.depo, lp.net_kg, CONCAT('record_edit.php?id=', lr.id) AS fix_link
      FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id
      $qc_lp_sql AND lp.net_kg = 0 ORDER BY lp.id DESC LIMIT 20", $qc_lp_params,
     null, null],
    ['Deposu boş — yükleme/çıkma paleti', 'kritik', 'Yüklemeler',
     "SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.depo = ''", $qc_lp_params,
     "SELECT lp.id AS id, lr.tarih, lr.firma, lp.urun_cinsi AS urun,
             lp.depo, lp.net_kg, CONCAT('record_edit.php?id=', lr.id) AS fix_link
      FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id
      $qc_lp_sql AND lp.depo = '' ORDER BY lp.id DESC LIMIT 20", $qc_lp_params,
     'palet', 'depo'],
    ['Ürün cinsi boş — yükleme/çıkma paleti', 'orta', 'Yüklemeler',
     "SELECT COUNT(*) FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id $qc_lp_sql AND lp.urun_cinsi = ''", $qc_lp_params,
     "SELECT lp.id AS id, lr.tarih, lr.firma, lp.urun_cinsi AS urun,
             lp.depo, lp.net_kg, CONCAT('record_edit.php?id=', lr.id) AS fix_link
      FROM loading_pallets lp JOIN loading_records lr ON lp.loading_record_id = lr.id
      $qc_lp_sql AND lp.urun_cinsi = '' ORDER BY lp.id DESC LIMIT 20", $qc_lp_params,
     'palet', 'urun_cinsi'],
    ['Paletsiz yükleme/çıkma kaydı', 'kritik', 'Yüklemeler',
     "SELECT COUNT(*) FROM loading_records $qc_lr_sql AND NOT EXISTS (SELECT 1 FROM loading_pallets WHERE loading_record_id = loading_records.id)", $qc_lr_params,
     "SELECT id, tarih, firma, urun, bolge AS depo, 0 AS net_kg,
             CONCAT('record_edit.php?id=', id) AS fix_link
      FROM loading_records $qc_lr_sql
        AND NOT EXISTS (SELECT 1 FROM loading_pallets WHERE loading_record_id = loading_records.id)
      ORDER BY id DESC LIMIT 20", $qc_lr_params,
     null, null],
    ['Çıkış nedeni boş — çıkma kaydı', 'dusuk', 'Çıkmalar',
     "SELECT COUNT(*) FROM loading_records $qc_cn_sql AND cikis_nedeni = ''", $qc_cn_params,
     "SELECT id, tarih, firma, urun, '' AS depo,
             (SELECT COALESCE(SUM(lp.net_kg),0) FROM loading_pallets lp WHERE lp.loading_record_id = loading_records.id) AS net_kg,
             CONCAT('record_edit.php?id=', id) AS fix_link
      FROM loading_records $qc_cn_sql AND cikis_nedeni = ''
      ORDER BY id DESC LIMIT 20", $qc_cn_params,
     'lr', 'cikis_nedeni'],
    ['Tanımsız firma adı — kantar grubu', 'orta', 'Kantar',
     "SELECT COUNT(*) FROM kantar_gruplar kg JOIN kantar_fisleri kf ON kg.fis_id = kf.id WHERE {$_bad_grup_sql}",
     [],
     "SELECT kg.id, kf.giris_tarih AS tarih, kg.grup_adi AS firma, kf.malin_cinsi AS urun,
             kf.depo, 0 AS net_kg, CONCAT('kantar_edit.php?id=', kf.id) AS fix_link
      FROM kantar_gruplar kg JOIN kantar_fisleri kf ON kg.fis_id = kf.id
      WHERE {$_bad_grup_sql}
      ORDER BY kg.id DESC LIMIT 20",
     [],
     'grup', 'grup_adi'],
    ['Gruplandırma toplamı uyuşmazlığı', 'kritik', 'Kantar',
     "SELECT COUNT(*) FROM (
        SELECT kg.fis_id,
            GREATEST(0, MAX(COALESCE(kf.net_kg,0))
                - MAX(COALESCE(kf.kasa_sayisi,0))*MAX(COALESCE(kf.kasa_dara,0))
                - MAX(COALESCE(kf.palet_sayisi,0))*MAX(COALESCE(kf.palet_dara,0))) AS fis_net,
            SUM(CASE WHEN tot.total_kasa>0 THEN GREATEST(0,
                COALESCE(kf.net_kg,0)*kg.kasa_adedi/tot.total_kasa
                - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf.kasa_dara,0))*kg.kasa_adedi
                - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf.palet_dara,0))*kg.palet_sayisi
            ) ELSE 0 END) AS grup_total
        FROM kantar_gruplar kg
        JOIN kantar_fisleri kf ON kg.fis_id=kf.id
        JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf.id
        GROUP BY kg.fis_id
     ) _t WHERE ABS(_t.fis_net - _t.grup_total) > 0.5", [],
     "SELECT kf.id, kf.giris_tarih AS tarih, kf.firma_adi AS firma, kf.malin_cinsi AS urun,
             kf.depo, kf.net_kg, CONCAT('kantar_edit.php?id=', kf.id) AS fix_link
      FROM (
        SELECT kg.fis_id,
            GREATEST(0, MAX(COALESCE(kf2.net_kg,0))
                - MAX(COALESCE(kf2.kasa_sayisi,0))*MAX(COALESCE(kf2.kasa_dara,0))
                - MAX(COALESCE(kf2.palet_sayisi,0))*MAX(COALESCE(kf2.palet_dara,0))) AS fis_net,
            SUM(CASE WHEN tot.total_kasa>0 THEN GREATEST(0,
                COALESCE(kf2.net_kg,0)*kg.kasa_adedi/tot.total_kasa
                - COALESCE(NULLIF(kg.kasa_dara_kg,0),COALESCE(kf2.kasa_dara,0))*kg.kasa_adedi
                - COALESCE(NULLIF(kg.palet_dara_kg,0),COALESCE(kf2.palet_dara,0))*kg.palet_sayisi
            ) ELSE 0 END) AS grup_total
        FROM kantar_gruplar kg
        JOIN kantar_fisleri kf2 ON kg.fis_id=kf2.id
        JOIN (SELECT fis_id,SUM(kasa_adedi) AS total_kasa FROM kantar_gruplar GROUP BY fis_id) tot ON tot.fis_id=kf2.id
        GROUP BY kg.fis_id
      ) _t
      JOIN kantar_fisleri kf ON kf.id=_t.fis_id
      WHERE ABS(_t.fis_net - _t.grup_total) > 0.5
      ORDER BY kf.id DESC LIMIT 20", [],
     null, null],
];

foreach ($qd_defs as [$qdlabel, $qdsev, $qdurlabel, $cnt_sql, $cnt_params, $rows_sql, $rows_params, $fix_type, $fix_field]) {
    try {
        $st = $pdo->prepare($cnt_sql);
        $st->execute($cnt_params);
        $total = (int)$st->fetchColumn();
        $rows  = [];
        if ($total > 0) {
            $st2 = $pdo->prepare($rows_sql);
            $st2->execute($rows_params);
            $rows = $st2->fetchAll();
        }
        $quality_detail[] = [
            'label'     => $qdlabel,
            'severity'  => $qdsev,
            'url_label' => $qdurlabel,
            'total'     => $total,
            'rows'      => $rows,
            'fix_type'  => $fix_type,
            'fix_field' => $fix_field,
        ];
    } catch (PDOException $e) {}
}

// ── Dropdown listeleri ────────────────────────────────────
try {
    $firma_kantar  = $pdo->query("SELECT DISTINCT firma_adi FROM kantar_fisleri WHERE firma_adi != '' ORDER BY firma_adi")->fetchAll(PDO::FETCH_COLUMN);
    $firma_loading = $pdo->query("SELECT DISTINCT firma FROM loading_records WHERE firma != '' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);
    $firma_list    = array_values(array_unique(array_merge($firma_kantar, $firma_loading)));
    sort($firma_list);
} catch (PDOException $e) { $firma_list = []; }

try {
    $urun_kantar  = $pdo->query("SELECT DISTINCT malin_cinsi FROM kantar_fisleri WHERE malin_cinsi != '' ORDER BY malin_cinsi")->fetchAll(PDO::FETCH_COLUMN);
    $urun_loading = $pdo->query("SELECT DISTINCT urun_cinsi FROM loading_pallets WHERE urun_cinsi != '' ORDER BY urun_cinsi")->fetchAll(PDO::FETCH_COLUMN);
    $urun_list    = array_values(array_unique(array_merge($urun_kantar, $urun_loading)));
    sort($urun_list);
} catch (PDOException $e) { $urun_list = []; }

try {
    $depo_list = $pdo->query("SELECT name FROM material_definitions WHERE type='depo' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $depo_list = []; }

try {
    $_def_firma_list = $pdo->query("SELECT name FROM material_definitions WHERE type='firma' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $_def_firma_list = []; }

try {
    $parti_list = $pdo->query("SELECT DISTINCT parti_no FROM loading_records WHERE parti_no != '' ORDER BY parti_no DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { $parti_list = []; }

// ── Veri Kalite Raporu CSV export ────────────────────────
if ($is_dkk_csv) {
    audit_log_event('export', 'stok', null, null, ['type' => 'dkk_csv']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="veri_kalite_raporu_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['# Veri Kalite Raporu', date('d.m.Y H:i')], ';', '"', '\\');
    fputcsv($out, ['#', ''], ';', '"', '\\');
    foreach ($quality_detail as $grp) {
        if ($grp['total'] === 0) continue;
        fputcsv($out, ['### ' . $grp['label'], 'Toplam: ' . $grp['total']], ';', '"', '\\');
        fputcsv($out, ['ID', 'Tarih', 'Firma', 'Ürün / Mal Cinsi', 'Depo', 'Net KG', 'Düzeltme Linki'], ';', '"', '\\');
        foreach ($grp['rows'] as $r) {
            fputcsv($out, [
                $r['id'], $r['tarih'] ?? '', $r['firma'] ?? '',
                $r['urun'] ?? '', $r['depo'] ?? '',
                number_format((float)($r['net_kg'] ?? 0), 3, ',', '.'),
                $r['fix_link'] ?? '',
            ], ';', '"', '\\');
        }
        fputcsv($out, ['', ''], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// ── Stok Hareketi CSV export ──────────────────────────────
if ($is_csv) {
    audit_log_event('export', 'stok', null, null, ['type' => 'stok_csv']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stok_hareket_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    $meta = [
        ['# Rapor', 'Depo Stok Hareketi'], ['# Tarih', date('d.m.Y H:i')],
        ['# Başlangıç', $f_tarih_bas ?: '—'], ['# Bitiş', $f_tarih_bit ?: '—'],
        ['# Firma', $f_firma ?: 'Hepsi'],     ['# Ürün',  $f_urun  ?: 'Hepsi'],
        ['# Depo',  $f_depo  ?: 'Hepsi'],     ['# Parti', $f_parti ?: 'Hepsi'],
        ['# Ham Giriş KG', number_format($gelen_kg, 3, ',', '.')],
        ['# Yüklenen İyi Ürün KG', number_format($yukleme_kg, 3, ',', '.')],
        ['# Fire/Çıkma KG', number_format($fire_cikma_kg, 3, ',', '.')],
        ['# Teorik Kalan KG', number_format($kalan_kg, 3, ',', '.')],
        ['#', ''],
    ];
    foreach ($meta as $r) { fputcsv($out, $r, ';', '"', '\\'); }
    fputcsv($out, ['Tarih', 'Hareket', 'Firma', 'Ürün', 'Depo', 'Parti No', 'Çıkış Nedeni', 'Brüt KG', 'Dara KG', 'Net KG'], ';', '"', '\\');
    foreach ($hareket_rows as $r) {
        $yon = match ($r['yon']) { 'gelen' => 'Ham Giriş (Kantar)', 'cikma' => 'Fire/Çıkma', default => 'Yükleme (İyi Ürün)' };
        fputcsv($out, [
            $r['tarih'], $yon, $r['firma'], $r['urun'],
            $r['depo'], $r['yon'] !== 'gelen' ? ($r['parti'] ?? '') : '',
            $r['yon'] !== 'gelen' ? ($r['cikis_nedeni'] ?? '') : '',
            number_format((float)$r['brut_kg'], 3, ',', '.'),
            number_format((float)$r['dara_kg'], 3, ',', '.'),
            number_format((float)$r['net_kg'], 3, ',', '.'),
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

// Sayfalama — CSV tüm satırları alır, display sayfalanır
$hareket_per_page    = 100;
$hareket_page        = max(1, (int)($_GET['hareket_page'] ?? 1));
$hareket_total_pages = max(1, (int)ceil($hareket_total / $hareket_per_page));
$hareket_page        = min($hareket_page, $hareket_total_pages);
$hareket_page_rows   = array_slice($hareket_rows, ($hareket_page - 1) * $hareket_per_page, $hareket_per_page);

// Özet kart renk durumu
$kalan_state = $kalan_kg < 0 ? 'negatif' : ($kalan_kg == 0.0 ? 'sifir' : 'pozitif');

// Kantar depo boş uyarısı için sayı
$_depo_bos_cnt = 0;
foreach ($quality_checks as $_qc) {
    if (str_contains($_qc['label'], 'Deposu boş — kantar')) { $_depo_bos_cnt = $_qc['n']; break; }
}

$herhangi_filtre = $f_tarih_bas !== '' || $f_tarih_bit !== '' || $f_firma !== '' || $f_urun !== '' || $f_depo !== '' || $f_parti !== '' || $f_hareket_tipi !== '';

render_header('Ürün Stok');
render_flash();
?>

<?php if ($save_error !== ''): ?>
<div class="flash flash-error"><?= h($save_error) ?></div>
<?php endif; ?>

<div class="page-head">
    <h2 class="page-title">📦 Ürün Stok</h2>
    <a href="stok.php?<?= h(http_build_query(array_filter([
        'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit,
        'firma' => $f_firma, 'urun' => $f_urun, 'depo' => $f_depo,
        'parti' => $f_parti,
        'sayim_kg' => $sayim_kg !== null ? (string)$sayim_kg : '',
        'csv' => '1',
    ], fn($v) => $v !== ''))) ?>" class="btn btn-sm btn-ghost">⬇ CSV</a>
</div>

<!-- ── Filtre Bilgi Notu ─────────────────────────────────── -->
<div class="stok-info-box">
    <span class="stok-info-icon">ℹ️</span>
    <span><strong>Stok formülü:</strong> Ham Giriş (kantar) &minus; Yüklenen İyi Ürün &minus; Fire/Çıkma = Teorik Kalan.
    Kantar girişleri parti numarasına bağlı değildir; <strong>parti filtresi yalnızca yükleme ve çıkma kayıtlarını filtreler</strong>, ham girişi etkilemez.
    Eski kantar kayıtlarında <em>depo</em> boş olabilir — depo filtresi aktifken bu girişler hesaba dahil edilmez.
    Eski kantar fişlerini düzenlemek için <a href="kantar.php">Kantar Fişleri</a> listesini kullanın.</span>
</div>
<?php if ($_depo_bos_cnt > 0): ?>
<div class="stok-uyari-box stok-uyari-depo" style="margin-bottom:10px;display:flex;gap:8px;align-items:flex-start">
    <span>⚠</span>
    <span><strong><?= $_depo_bos_cnt ?> kantar fişinde depo boş</strong> — depo filtresi aktifken bu girişler hesaba dahil edilmez.
    Düzeltmek için <a href="stok.php?dkk_open=1<?= $f_firma !== '' ? '&firma=' . urlencode($f_firma) : '' ?>">Veri Kalite Raporunu</a> açın.</span>
</div>
<?php endif; ?>
<?php if ($f_parti !== ''): ?>
<div class="stok-uyari-box" style="margin-bottom:10px;display:flex;gap:8px;align-items:flex-start">
    <span>⚠</span>
    <span><strong>Parti no filtresi aktif:</strong> Ham giriş (kantar) <em>partiye göre filtrelenmez</em>; yalnızca yükleme/çıkma kayıtları bu filtreden etkilenir.
    Bu nedenle gösterilen teorik kalan, sadece ilgili partinin çıkışlarını baz alır — doğru bir gerçek stok hesabı değildir.</span>
</div>
<?php endif; ?>

<!-- ── Filtre Formu ──────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
    <form method="get" action="stok.php" class="stok-filter-form">
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
                <label class="form-label">Firma</label>
                <input type="text" name="firma" class="form-control" value="<?= h($f_firma) ?>"
                    list="stok-firma-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-firma-list">
                    <?php foreach ($firma_list as $fv): ?>
                    <option value="<?= h($fv) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group stok-fg stok-fg-wide">
                <label class="form-label">Ürün</label>
                <input type="text" name="urun" class="form-control" value="<?= h($f_urun) ?>"
                    list="stok-urun-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-urun-list">
                    <?php foreach ($urun_list as $uv): ?>
                    <option value="<?= h($uv) ?>">
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
                <label class="form-label">Parti No <small style="color:var(--muted)">(yükleme)</small></label>
                <input type="text" name="parti" class="form-control" value="<?= h($f_parti) ?>"
                    list="stok-parti-list" placeholder="Hepsi" autocomplete="off">
                <datalist id="stok-parti-list">
                    <?php foreach ($parti_list as $pv): ?>
                    <option value="<?= h($pv) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="stok-filter-actions">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <?php if ($herhangi_filtre): ?>
            <a href="stok.php" class="btn btn-ghost">Temizle</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Hareket Tipi Filtresi ─────────────────────────────── -->
<div class="stok-mv-filter-row">
    <a href="<?= stok_url(['hareket_tipi' => '', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === '' ? ' active' : '' ?>">Tümü</a>
    <a href="<?= stok_url(['hareket_tipi' => 'gelen', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'gelen' ? ' active' : '' ?>">Kantar Giriş</a>
    <a href="<?= stok_url(['hareket_tipi' => 'yukleme', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'yukleme' ? ' active' : '' ?>">Yükleme Çıkış</a>
    <a href="<?= stok_url(['hareket_tipi' => 'cikma', 'hareket_page' => '']) ?>"
       class="pill<?= $f_hareket_tipi === 'cikma' ? ' active' : '' ?>">Çıkma / Fire</a>
</div>

<?php
$dkk_has_kritik = !empty(array_filter($quality_checks, fn($c) => $c['severity'] === 'kritik'));
$dkk_cnt_k = count(array_filter($quality_checks, fn($c) => $c['severity'] === 'kritik'));
$dkk_cnt_o = count(array_filter($quality_checks, fn($c) => $c['severity'] === 'orta'));
$dkk_cnt_d = count(array_filter($quality_checks, fn($c) => $c['severity'] === 'dusuk'));
$dkk_label_map = ['kritik' => 'Kritik', 'orta' => 'Orta', 'dusuk' => 'Düşük'];
?>
<div class="dkk-panel<?= empty($quality_checks) ? ' dkk-panel-ok' : '' ?>">
    <div class="dkk-panel-head">
        <span class="dkk-panel-title">
            <?= empty($quality_checks) ? '✓' : ($dkk_has_kritik ? '🔴' : '🟡') ?>
            Stok Doğruluk Kontrolü
        </span>
        <span class="dkk-panel-badges">
            <?php if (empty($quality_checks)): ?>
            <span class="dkk-badge dkk-badge-ok">Sorun yok</span>
            <?php else: ?>
            <?php if ($dkk_cnt_k > 0): ?><span class="dkk-badge dkk-badge-kritik"><?= $dkk_cnt_k ?> Kritik</span><?php endif; ?>
            <?php if ($dkk_cnt_o > 0): ?><span class="dkk-badge dkk-badge-orta"><?= $dkk_cnt_o ?> Orta</span><?php endif; ?>
            <?php if ($dkk_cnt_d > 0): ?><span class="dkk-badge dkk-badge-dusuk"><?= $dkk_cnt_d ?> Düşük</span><?php endif; ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!empty($quality_checks)): ?>
    <!-- PC: tablo -->
    <div class="pc-only">
        <table class="dkk-table">
            <thead>
                <tr>
                    <th>Önem</th>
                    <th>Sorun</th>
                    <th class="num">Adet</th>
                    <th>Etki</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($quality_checks as $c): ?>
            <tr class="dkk-row-<?= h($c['severity']) ?>">
                <td><span class="dkk-badge dkk-badge-<?= h($c['severity']) ?>"><?= h($dkk_label_map[$c['severity']] ?? $c['severity']) ?></span></td>
                <td class="dkk-label"><?= h($c['label']) ?></td>
                <td class="num dkk-count"><?= $c['n'] ?></td>
                <td class="dkk-detail"><?= h($c['detail']) ?></td>
                <td><a href="<?= h($c['url']) ?>" class="btn btn-sm btn-ghost">→ <?= h($c['url_label']) ?></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Mobil: kart listesi -->
    <div class="mobile-only dkk-card-list">
        <?php foreach ($quality_checks as $c): ?>
        <div class="dkk-card dkk-card-<?= h($c['severity']) ?>">
            <div class="dkk-card-head">
                <span class="dkk-badge dkk-badge-<?= h($c['severity']) ?>"><?= h($dkk_label_map[$c['severity']] ?? $c['severity']) ?></span>
                <span class="dkk-card-count"><?= $c['n'] ?> kayıt</span>
            </div>
            <div class="dkk-card-label"><?= h($c['label']) ?></div>
            <div class="dkk-card-detail"><?= h($c['detail']) ?></div>
            <a href="<?= h($c['url']) ?>" class="btn btn-sm btn-ghost dkk-card-link">→ <?= h($c['url_label']) ?></a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
// Sorunlu grupları say (detay bölümü için)
$dkk_sorunlu = array_filter($quality_detail, fn($g) => $g['total'] > 0);
$dkk_detail_url = '?' . http_build_query(array_filter([
    'tarih_bas' => $f_tarih_bas, 'tarih_bit' => $f_tarih_bit,
    'firma' => $f_firma, 'urun' => $f_urun, 'depo' => $f_depo,
    'parti' => $f_parti, 'dkk_csv' => '1',
], fn($v) => $v !== ''));
?>
<?php if (!empty($dkk_sorunlu)): ?>
<!-- ── Detaylı Veri Kalite Raporu ────────────────────────── -->
<div class="dkk-detail-wrap">
    <div class="dkk-detail-bar">
        <button type="button" class="btn btn-sm dkk-toggle-btn" id="dkkToggleBtn"
                onclick="dkkToggle()">
            🔍 Detaylı Veri Kalite Raporunu Göster
        </button>
        <a href="<?= h($dkk_detail_url) ?>" class="btn btn-sm btn-ghost">⬇ Kalite Raporu CSV</a>
    </div>
    <div id="dkkDetail" class="dkk-detail-section" hidden>
        <datalist id="hd-dl-firma"><?php foreach ($firma_list as $fv): ?><option value="<?= h($fv) ?>"><?php endforeach; ?></datalist>
        <datalist id="hd-dl-urun"><?php foreach ($urun_list as $uv): ?><option value="<?= h($uv) ?>"><?php endforeach; ?></datalist>
        <?php foreach ($dkk_sorunlu as $grp): ?>
        <?php if ($grp['total'] === 0) continue; ?>
        <?php $_has_fix = !empty($grp['fix_type']) && !empty($grp['fix_field']); ?>
        <div class="dkk-detail-group dkk-detail-group-<?= h($grp['severity']) ?>">
            <div class="dkk-detail-group-head">
                <span class="dkk-badge dkk-badge-<?= h($grp['severity']) ?>"><?= $dkk_label_map[$grp['severity']] ?? $grp['severity'] ?></span>
                <span class="dkk-detail-group-title"><?= h($grp['label']) ?></span>
                <span class="dkk-detail-group-total">
                    Toplam <strong><?= $grp['total'] ?></strong> kayıt
                    <?= $grp['total'] > 20 ? ' <span class="dkk-ilk20">(ilk 20 gösteriliyor)</span>' : '' ?>
                    <?= $_has_fix ? ' · <em>Her satırda hızlı düzelt</em>' : '' ?>
                </span>
            </div>
            <?php if (!empty($grp['rows'])): ?>
            <div class="table-wrap">
                <table class="data-table dkk-detail-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Tarih</th>
                            <th>Firma</th>
                            <th>Ürün / Mal Cinsi</th>
                            <th>Depo</th>
                            <th class="num">Net KG</th>
                            <th><?= $_has_fix ? 'Hızlı Düzelt / Git' : 'Git' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($grp['rows'] as $row): ?>
                        <tr>
                            <td class="dkk-id"><?= (int)$row['id'] ?></td>
                            <td><?= h($row['tarih'] ?? '—') ?></td>
                            <td><?= h($row['firma'] ?? '—') ?></td>
                            <td><?= h($row['urun'] !== '' ? $row['urun'] : '—') ?></td>
                            <td><?= h($row['depo'] !== '' ? $row['depo'] : '—') ?></td>
                            <td class="num"><?= fmt_kg((float)($row['net_kg'] ?? 0)) ?></td>
                            <td class="hd-fix-cell">
                            <?php if ($_has_fix): ?>
                            <form method="post" action="stok.php" class="hd-form">
                                <input type="hidden" name="csrf"       value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action"     value="hizli_duzelt">
                                <input type="hidden" name="fix_type"   value="<?= h($grp['fix_type']) ?>">
                                <input type="hidden" name="fix_field"  value="<?= h($grp['fix_field']) ?>">
                                <input type="hidden" name="fix_id"     value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="pf_tarih_bas" value="<?= h($f_tarih_bas) ?>">
                                <input type="hidden" name="pf_tarih_bit" value="<?= h($f_tarih_bit) ?>">
                                <input type="hidden" name="pf_firma"   value="<?= h($f_firma) ?>">
                                <input type="hidden" name="pf_urun"    value="<?= h($f_urun) ?>">
                                <input type="hidden" name="pf_depo"    value="<?= h($f_depo) ?>">
                                <input type="hidden" name="pf_parti"   value="<?= h($f_parti) ?>">
                                <?php if ($grp['fix_field'] === 'depo'): ?>
                                <select name="fix_val" class="hd-select" required>
                                    <option value="">— depo seç —</option>
                                    <?php foreach ($depo_list as $dv): ?>
                                    <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($grp['fix_field'] === 'cikis_nedeni'): ?>
                                <select name="fix_val" class="hd-select" required>
                                    <option value="">— seç —</option>
                                    <?php foreach (cikis_nedeni_listesi() as $cn): ?>
                                    <option value="<?= h($cn) ?>"><?= h($cn) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($grp['fix_field'] === 'giris_tarih'): ?>
                                <input type="datetime-local" name="fix_val" class="hd-input" required>
                                <?php elseif ($grp['fix_field'] === 'firma_adi'): ?>
                                <input type="text" name="fix_val" class="hd-input"
                                       list="hd-dl-firma" placeholder="Firma adı" autocomplete="off" required>
                                <?php elseif ($grp['fix_field'] === 'grup_adi'): ?>
                                <select name="fix_val" class="hd-select" required>
                                    <option value="">— firma seç —</option>
                                    <?php foreach ($_def_firma_list as $_gf): ?>
                                    <option value="<?= h($_gf) ?>"><?= h($_gf) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <input type="text" name="fix_val" class="hd-input"
                                       list="hd-dl-urun" placeholder="Ürün" autocomplete="off" required>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-primary hd-save-btn" title="Kaydet">✓</button>
                            </form>
                            <?php endif; ?>
                            <a href="<?= h($row['fix_link']) ?>" class="btn btn-sm btn-ghost">→</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
function dkkToggle() {
    var el  = document.getElementById('dkkDetail');
    var btn = document.getElementById('dkkToggleBtn');
    var hidden = el.hidden;
    el.hidden = !hidden;
    btn.textContent = hidden ? '▲ Detaylı Veri Kalite Raporunu Gizle' : '🔍 Detaylı Veri Kalite Raporunu Göster';
}
(function () {
    if (new URLSearchParams(location.search).get('dkk_open') === '1') {
        var el  = document.getElementById('dkkDetail');
        var btn = document.getElementById('dkkToggleBtn');
        if (el && btn) {
            el.hidden = false;
            btn.textContent = '▲ Detaylı Veri Kalite Raporunu Gizle';
        }
    }
})();
</script>
<?php endif; ?>

<!-- ── Özet Kartları (6 kart) ────────────────────────────── -->
<div class="stok-ozet-grid stok-ozet-grid-6">
    <div class="stok-kart stok-kart-gelen">
        <div class="stok-kart-label">Ham Giriş</div>
        <div class="stok-kart-val"><?= fmt_kg($gelen_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Kantar girişi</div>
    </div>
    <div class="stok-kart stok-kart-yukleme">
        <div class="stok-kart-label">Yüklenen İyi Ürün</div>
        <div class="stok-kart-val"><?= fmt_kg($yukleme_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Yükleme çıkışı</div>
    </div>
    <div class="stok-kart stok-kart-fire">
        <div class="stok-kart-label">Fire / Kötü / Çıkma</div>
        <div class="stok-kart-val"><?= fmt_kg($fire_cikma_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub">Çıkma kayıtları</div>
    </div>
    <div class="stok-kart stok-kart-kalan stok-kart-kalan-<?= $kalan_state ?>">
        <div class="stok-kart-label">Teorik Kalan<?= $kalan_state === 'negatif' ? ' ⚠' : '' ?></div>
        <div class="stok-kart-val stok-kalan-<?= $kalan_state ?>"><?= fmt_kg($kalan_kg) ?> <small>kg</small></div>
        <div class="stok-kart-sub"><?= $kalan_state === 'negatif' ? 'Eksik — veri hatası olabilir' : 'Giriş − İyi − Fire' ?></div>
    </div>
    <div class="stok-kart stok-kart-sayim">
        <div class="stok-kart-label">Fiziki Sayım</div>
        <div class="stok-kart-val">
            <?php if ($sayim_kg !== null): ?>
            <span><?= fmt_kg($sayim_kg) ?></span><small> kg</small>
            <?php else: ?>
            <span class="stok-sayim-gir">—</span>
            <?php endif; ?>
        </div>
        <div class="stok-kart-sub">
            <form method="get" action="stok.php" class="stok-sayim-form">
                <input type="hidden" name="tarih_bas" value="<?= h($f_tarih_bas) ?>">
                <input type="hidden" name="tarih_bit" value="<?= h($f_tarih_bit) ?>">
                <input type="hidden" name="firma"     value="<?= h($f_firma) ?>">
                <input type="hidden" name="urun"      value="<?= h($f_urun) ?>">
                <input type="hidden" name="depo"      value="<?= h($f_depo) ?>">
                <input type="hidden" name="parti"     value="<?= h($f_parti) ?>">
                <label class="stok-sayim-label">
                    <span>Gir:</span>
                    <input type="number" name="sayim_kg" id="stokSayimInput" step="0.001"
                        class="form-control stok-sayim-input"
                        value="<?= $sayim_kg !== null ? h((string)$sayim_kg) : '' ?>"
                        placeholder="kg">
                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px;flex-shrink:0">✓</button>
                </label>
            </form>
        </div>
    </div>
    <div class="stok-kart stok-kart-fark">
        <div class="stok-kart-label">Sayım Farkı</div>
        <div class="stok-kart-val">
            <?php if ($sayim_fark !== null): ?>
            <span class="<?= $sayim_fark > 0 ? 'stok-pozitif' : ($sayim_fark < 0 ? 'stok-negatif' : '') ?>">
                <?= ($sayim_fark >= 0 ? '+' : '') . fmt_kg($sayim_fark) ?>
            </span>
            <small>kg</small>
            <?php else: ?>
            <span class="stok-sayim-gir">—</span>
            <?php endif; ?>
        </div>
        <div class="stok-kart-sub">Sayım − Teorik Kalan</div>
    </div>
</div>

<?php if ($sayim_kg !== null): ?>
<!-- ── Sayım Kaydet Formu ─────────────────────────────────── -->
<div class="card sc-kaydet-card">
    <div class="sc-kaydet-head">
        <div>
            <strong>📋 Sayımı Kaydet</strong>
            <span class="sc-kaydet-ozet">
                Sistem: <strong><?= fmt_kg($kalan_kg) ?> kg</strong>
                · Sayım: <strong><?= fmt_kg($sayim_kg) ?> kg</strong>
                · Fark: <strong class="<?= $sayim_fark >= 0 ? 'stok-pozitif' : 'stok-negatif' ?>"><?= ($sayim_fark >= 0 ? '+' : '') . fmt_kg($sayim_fark) ?> kg</strong>
            </span>
        </div>
    </div>
    <form method="post" action="stok.php" class="sc-kaydet-form">
        <input type="hidden" name="csrf"        value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action"      value="save_count">
        <input type="hidden" name="pf_tarih_bas" value="<?= h($f_tarih_bas) ?>">
        <input type="hidden" name="pf_tarih_bit" value="<?= h($f_tarih_bit) ?>">
        <input type="hidden" name="pf_firma"    value="<?= h($f_firma) ?>">
        <input type="hidden" name="pf_urun"     value="<?= h($f_urun) ?>">
        <input type="hidden" name="pf_depo"     value="<?= h($f_depo) ?>">
        <input type="hidden" name="pf_parti"    value="<?= h($f_parti) ?>">
        <div class="sc-kaydet-row">
            <div class="form-group" style="flex:1;min-width:130px">
                <label class="form-label">Sayım KG</label>
                <input type="number" name="counted_kg" step="0.001" min="0"
                    class="form-control" required
                    value="<?= h((string)$sayim_kg) ?>" placeholder="0.000">
            </div>
            <div class="form-group" style="flex:3;min-width:200px">
                <label class="form-label">Not <small class="muted">(isteğe bağlı)</small></label>
                <input type="text" name="note" class="form-control"
                    placeholder="ör. Depo A rafı sayıldı, 5 kg fire var...">
            </div>
            <div class="form-group sc-kaydet-btn-wrap">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary">💾 Kaydet</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Hareket Tablosu ───────────────────────────────────── -->
<div class="card" style="margin-top:16px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">Hareketler</span>
        <span style="font-size:.82rem;color:var(--muted)">
            <?= $hareket_total ?> hareket<?= $hareket_total >= 5000 ? ' (max 5000)' : '' ?>
            <?php if ($hareket_total_pages > 1): ?> · Sayfa <?= $hareket_page ?>/<?= $hareket_total_pages ?><?php endif; ?>
        </span>
    </div>
    <?php if (empty($hareket_page_rows)): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">Filtre kriterlerine uygun hareket yok.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="data-table stok-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Hareket</th>
                    <th>Firma</th>
                    <th class="stok-hide-sm">Ürün</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th class="stok-hide-sm">Parti</th>
                    <th class="stok-hide-sm">Çıkış Nedeni</th>
                    <th class="stok-hide-sm" style="text-align:right">Brüt KG</th>
                    <th class="stok-hide-sm" style="text-align:right">Dara KG</th>
                    <th style="text-align:right">Net KG</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hareket_page_rows as $r):
                $yon         = $r['yon'];
                $is_gelen    = $yon === 'gelen';
                $badge_class = $is_gelen ? 'badge-gelen' : ($yon === 'cikma' ? 'badge-fire' : 'badge-cikan');
                $badge_label = match ($yon) {
                    'gelen'  => 'Ham Giriş',
                    'cikma'  => 'Fire/Çıkma',
                    default  => 'Yükleme',
                };
                $badge_sub = match ($yon) {
                    'gelen'  => 'Kantar',
                    'cikma'  => 'Çıkma',
                    default  => 'İyi Ürün',
                };
                $kg_color   = $is_gelen ? 'var(--success)' : 'var(--danger)';
                $link_url   = $is_gelen
                    ? ($r['kantar_id'] ? 'kantar_view.php?id=' . (int)$r['kantar_id'] : null)
                    : ($r['lr_id']     ? 'record_view.php?id='  . (int)$r['lr_id']    : null);
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmt_date($r['tarih'])) ?></td>
                    <td>
                        <span class="stok-badge <?= $badge_class ?>"><?= $badge_label ?></span>
                        <div style="font-size:.72rem;color:var(--muted)"><?= $badge_sub ?></div>
                    </td>
                    <td><?= h($r['firma']) ?></td>
                    <td class="stok-hide-sm"><?= h($r['urun']) ?></td>
                    <td class="stok-hide-sm"><?= h($r['depo'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)">
                        <?= $is_gelen ? '—' : h($r['parti'] ?: '—') ?>
                    </td>
                    <td class="stok-hide-sm">
                        <?php if (!$is_gelen && !empty($r['cikis_nedeni'])): ?>
                        <span class="cikis-nedeni-badge" style="font-size:.78rem"><?= h($r['cikis_nedeni']) ?></span>
                        <?php else: ?>
                        <?= $is_gelen ? '—' : '<span style="color:var(--muted)">—</span>' ?>
                        <?php endif; ?>
                    </td>
                    <td class="stok-hide-sm" style="text-align:right;color:var(--muted)">
                        <?= (float)$r['brut_kg'] > 0 ? h(fmt_kg($r['brut_kg'])) : '—' ?>
                    </td>
                    <td class="stok-hide-sm" style="text-align:right;color:var(--muted)">
                        <?= (float)$r['dara_kg'] > 0 ? h(fmt_kg($r['dara_kg'])) : '—' ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:<?= $kg_color ?>;white-space:nowrap">
                        <?= $is_gelen ? '+' : '−' ?><?= h(fmt_kg($r['net_kg'])) ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($link_url): ?>
                        <a href="<?= h($link_url) ?>" class="btn btn-sm" style="padding:2px 8px;font-size:.78rem">↗</a>
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
            <a href="<?= stok_url(['hareket_page' => $hareket_page - 1]) ?>" class="btn btn-sm">← Önceki</a>
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
            <a href="<?= stok_url(['hareket_page' => $pg]) ?>"
               class="btn btn-sm<?= $pg === $hareket_page ? ' btn-primary' : '' ?>"><?= $pg ?></a>
            <?php endfor; ?>
            <?php if ($hareket_page < $hareket_total_pages): ?>
            <a href="<?= stok_url(['hareket_page' => $hareket_page + 1]) ?>" class="btn btn-sm">Sonraki →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ── Geçmiş Sayımlar ───────────────────────────────────── -->
<?php if (!empty($gecmis_sayimlar)): ?>
<div class="card" style="margin-top:20px;padding:0">
    <div class="stok-table-head">
        <span style="font-weight:700;font-size:.97rem">📋 Geçmiş Sayımlar</span>
        <span style="font-size:.82rem;color:var(--muted)"><?= count($gecmis_sayimlar) ?> kayıt (son 20)</span>
    </div>
    <div class="table-wrap">
        <table class="data-table stok-table sc-history-table">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th class="stok-hide-sm">Firma</th>
                    <th class="stok-hide-sm">Ürün</th>
                    <th class="stok-hide-sm">Depo</th>
                    <th class="stok-hide-sm">Parti</th>
                    <th style="text-align:right">Sistem KG</th>
                    <th style="text-align:right">Sayım KG</th>
                    <th style="text-align:right">Fark</th>
                    <th class="stok-hide-sm">Not</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gecmis_sayimlar as $sc):
                $diff      = (float)$sc['diff_kg'];
                $diff_cls  = $diff > 0 ? 'sc-fark-fazla' : ($diff < 0 ? 'sc-fark-eksik' : 'sc-fark-sifir');
                $diff_lbl  = $diff > 0 ? 'Fazla' : ($diff < 0 ? 'Eksik' : 'Eşit');
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= h(fmt_date($sc['count_date'])) ?></td>
                    <td class="stok-hide-sm"><?= h($sc['firma'] ?: '—') ?></td>
                    <td class="stok-hide-sm"><?= h($sc['urun'] ?: '—') ?></td>
                    <td class="stok-hide-sm"><?= h($sc['depo'] ?: '—') ?></td>
                    <td class="stok-hide-sm" style="font-size:.8rem;color:var(--muted)"><?= h($sc['parti_no'] ?: '—') ?></td>
                    <td style="text-align:right"><?= h(fmt_kg($sc['system_kg'])) ?></td>
                    <td style="text-align:right;font-weight:700"><?= h(fmt_kg($sc['counted_kg'])) ?></td>
                    <td style="text-align:right">
                        <span class="sc-fark-badge <?= $diff_cls ?>">
                            <?= $diff > 0 ? '+' : '' ?><?= h(fmt_kg($diff)) ?>
                            <small><?= $diff_lbl ?></small>
                        </span>
                    </td>
                    <td class="stok-hide-sm" style="font-size:.82rem;color:var(--muted)"><?= h($sc['note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
