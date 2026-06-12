<?php
// =========================================================
// config/material_stock_helpers.php — Malzeme Stok ortak katmanı
// Stok özeti, hareket listesi ve dropdown verilerinin tek
// doğru kaynağı. (Sprint MalzemeStok-Pro-00 — mantık
// malzeme_stok.php'den davranış değiştirilmeden taşındı.)
// =========================================================
declare(strict_types=1);

// ── Modülün takip ettiği malzeme türleri ──────────────────
// firma/depo/bolge/urun hariç tüm tanım türleri (formlar ve filtreler için).
function ms_material_types(): array {
    $skip = ['firma', 'depo', 'bolge', 'urun'];
    return array_filter(definition_types(), fn($k) => !in_array($k, $skip), ARRAY_FILTER_USE_KEY);
}

// Stok özetinden hariç tutulan türler (lokasyon da özet dışı).
function ms_excluded_types(): array {
    return ['firma', 'depo', 'bolge', 'urun', 'lokasyon'];
}

function ms_summary_types(): array {
    return array_values(array_diff(array_keys(ms_material_types()), ms_excluded_types()));
}

function ms_stock_units(): array {
    return ['adet', 'kg', 'm', 'm²', 'paket', 'rulo', 'top', 'çift', 'set'];
}

// ── Stok kategorileri (Kasa / Palet / Sarf / Diğer) ───────
function ms_cat_labels(): array {
    return ['kasa' => 'Kasa', 'palet' => 'Palet', 'sarf' => 'Sarf', 'diger' => 'Diğer'];
}

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

// ── Malzeme tanımı eşleme — normalize_text_v2 tabanlı ─────
// Tür içinde adı normalize ederek eşleşen tanımı döndürür (LOWER kullanılmaz;
// Türkçe büyük harf standardı normalize_text_v2 üzerinden korunur).
function ms_find_material_definition(PDO $pdo, string $type, string $name): ?array {
    $st = $pdo->prepare("SELECT id, name, unit_dara_kg FROM material_definitions WHERE type=?");
    $st->execute([$type]);
    $needle = normalize_text_v2($name);
    foreach ($st->fetchAll() as $row) {
        if (normalize_text_v2($row['name']) === $needle) return $row;
    }
    return null;
}

// ── Stok özeti ─────────────────────────────────────────────
// Ana kaynak: material_definitions. Hareketler material_id ile eşlenir (LEFT JOIN
// mantığı PHP tarafında). Tanımlı ama hareketsiz malzeme 0, girişi olmayan ama
// kullanımı olan malzeme negatif görünür. Tarih filtresi hareket toplamını sınırlar.
//
// $filters: tarih_bas, tarih_bit (SQL) · ozet_kategori, ozet_tur,
//           ozet_malzeme, ozet_depo (PHP tarafı süzme)
//
// KÖK BUGFIX KORUNUR: gruplama material_id bazlıdır. material_name yalnızca
// material_id NULL hareketlerde grup anahtarıdır; tanım ismi değişince aynı
// material_id'nin hareketleri iki satıra BÖLÜNMEZ. GROUP BY içine material_name
// koşulsuz olarak tekrar EKLENMEMELİDİR.
function get_material_stock_summary(PDO $pdo, array $filters = []): array {
    $f_tarih_bas     = (string)($filters['tarih_bas']     ?? '');
    $f_tarih_bit     = (string)($filters['tarih_bit']     ?? '');
    $f_ozet_kategori = (string)($filters['ozet_kategori'] ?? '');
    $f_ozet_tur      = (string)($filters['ozet_tur']      ?? '');
    $f_ozet_malzeme  = (string)($filters['ozet_malzeme']  ?? '');
    $f_ozet_depo     = (string)($filters['ozet_depo']     ?? '');

    $ms_summary_types  = ms_summary_types();
    $ms_excluded_types = ms_excluded_types();

    $ozet_rows = [];
    try {
        // 1) Hareket toplamları — material_id + depo + birim kırılımında (tarih bazlı)
        $agg_where = []; $agg_params = [];
        if ($f_tarih_bas !== '') { $agg_where[] = "movement_date >= ?"; $agg_params[] = $f_tarih_bas; }
        if ($f_tarih_bit !== '') { $agg_where[] = "movement_date <= ?"; $agg_params[] = $f_tarih_bit; }
        $agg_where_sql = $agg_where ? 'WHERE ' . implode(' AND ', $agg_where) : '';

        $agg_st = $pdo->prepare("
            SELECT material_id,
                MAX(material_type) AS material_type,
                MAX(material_name) AS material_name,
                depo, unit,
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
            GROUP BY material_id, depo, unit,
                (CASE WHEN material_id IS NULL THEN material_name ELSE NULL END),
                (CASE WHEN material_id IS NULL THEN material_type ELSE NULL END)
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
        $mk_row = function (string $cat, string $type, string $name, $depo, $unit, array $a, int $is_active, ?int $mid): array {
            $giris = (float)($a['total_giris'] ?? 0);
            $sevk  = (float)($a['total_sevk'] ?? 0);
            $kull  = (float)($a['total_kullanim'] ?? 0);
            return [
                'category'       => $cat,
                'material_id'    => $mid,
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
                    $ozet_rows[] = $mk_row($cat, $d['type'], $d['name'], $a['depo'], $a['unit'], $a, $act, $did);
                }
                $seen_ids[$did] = true;
            } elseif ($act === 1) {
                // hareketsiz aktif tanım → kalan 0; pasif + hareketsiz → atla
                $ozet_rows[] = $mk_row($cat, $d['type'], $d['name'], '', 'adet', [], $act, $did);
            }
        }

        // 4) Tanıma bağlanmayan hareketler (material_id NULL ya da dışlanan/silinen tanım)
        foreach ($agg_rows as $a) {
            $mid = ($a['material_id'] === null || $a['material_id'] === '') ? null : (int)$a['material_id'];
            if ($mid !== null && isset($seen_ids[$mid])) continue;            // tanım altında gösterildi
            $atype = (string)($a['material_type'] ?? '');
            if (in_array($atype, $ms_excluded_types, true)) continue;         // firma/depo/ürün vb. atla
            $ozet_rows[] = $mk_row(ms_cat_of($atype), $atype, (string)$a['material_name'], $a['depo'], $a['unit'], $a, 1, $mid);
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

    return $ozet_rows;
}

// ── Üst kart toplamları ───────────────────────────────────
// TODO Pro-UX: Birim bazlı toplamlar ayrılmalı — şu an adet/kg/m² aynı
// toplamda birleşiyor (mevcut davranış bu sprintte bilinçli korunuyor).
function get_material_stock_totals(PDO $pdo, array $summaryRows = []): array {
    return [
        'toplam_giris'  => array_sum(array_column($summaryRows, 'total_giris')),
        'toplam_cikis'  => array_sum(array_column($summaryRows, 'total_cikis')),
        'stokta_count'  => count(array_filter($summaryRows, fn($r) => (float)$r['kalan'] > 0)),
        'negatif_count' => count(array_filter($summaryRows, fn($r) => (float)$r['kalan'] < 0)),
    ];
}

// ── Negatif kalan satırları ───────────────────────────────
function get_negative_materials(array $summaryRows): array {
    return array_values(array_filter($summaryRows, fn($r) => (float)$r['kalan'] < 0));
}

// ── Hareket listesi ───────────────────────────────────────
// $filters: tarih_bas, tarih_bit, mat_id, mat_type, mat_name, depo, hareket_tipi
// mat_id verildiyse mat_type/mat_name yok sayılır (ID bazlı filtre isim
// değişikliğine dayanıklıdır).
// TODO Pro-02: SQL tabanlı LIMIT/OFFSET pagination yapılacak — şu an üst
// sınır kadar satır çekilip sayfalama PHP tarafında dilimleniyor.
function get_material_movements(PDO $pdo, array $filters = [], int $limit = 2000): array {
    $f_tarih_bas    = (string)($filters['tarih_bas']    ?? '');
    $f_tarih_bit    = (string)($filters['tarih_bit']    ?? '');
    $f_mat_id       = (int)   ($filters['mat_id']       ?? 0);
    $f_mat_type     = (string)($filters['mat_type']     ?? '');
    $f_mat_name     = (string)($filters['mat_name']     ?? '');
    $f_depo         = (string)($filters['depo']         ?? '');
    $f_hareket_tipi = (string)($filters['hareket_tipi'] ?? '');

    $where = []; $params = [];
    if ($f_tarih_bas !== '') { $where[] = "movement_date >= ?"; $params[] = $f_tarih_bas; }
    if ($f_tarih_bit !== '') { $where[] = "movement_date <= ?"; $params[] = $f_tarih_bit; }
    if ($f_mat_id > 0) {
        $where[] = "material_id = ?"; $params[] = $f_mat_id;
    } else {
        if ($f_mat_type !== '') { $where[] = "material_type = ?";   $params[] = $f_mat_type; }
        if ($f_mat_name !== '') { $where[] = "material_name LIKE ?"; $params[] = '%' . $f_mat_name . '%'; }
    }
    if ($f_depo         !== '') { $where[] = "depo LIKE ?";       $params[] = '%' . $f_depo . '%'; }
    if ($f_hareket_tipi !== '') { $where[] = "movement_type = ?"; $params[] = $f_hareket_tipi; }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $rows = [];
    try {
        $st = $pdo->prepare("
            SELECT id, movement_date, movement_type, material_type, material_name,
                   depo, quantity, unit, source_type, source_id,
                   belge_no, firma, note
            FROM material_stock_movements
            $where_sql
            ORDER BY movement_date DESC, id DESC
            LIMIT " . max(1, $limit) . "
        ");
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {}
    return $rows;
}

// ── Dropdown verileri ─────────────────────────────────────
// depo_list: aktif depo tanımları · firma_list: aktif firma tanımları ·
// mat_names_by_type: aktif tanım adları + hareketlerde geçen adlar (datalist
// eksiksiz olsun diye — tanımı silinmiş/eşleşmemiş adlar da seçilebilir kalır).
function get_material_dropdown_data(PDO $pdo): array {
    $ms_types = ms_material_types();

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

    return [
        'depo_list'         => $depo_list,
        'mat_names_by_type' => $mat_names_by_type,
        'firma_list'        => $firma_list,
    ];
}
