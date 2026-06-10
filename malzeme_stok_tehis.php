<?php
// =========================================================
// malzeme_stok_tehis.php — Malzeme Stok Teşhis Raporu
// Sprint MalzemeStok-01A
// SADECE OKUMA — Hiçbir veri değiştirilmez.
// Yalnızca admin erişimine açık.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

// ── Güvenlik köprüsü: normalize_text_v2 yoksa yerel tanım ──
if (!function_exists('normalize_text_v2')) {
    function normalize_text_v2(string $v): string {
        $v = trim($v);
        if ($v === '') return '';
        $v = preg_replace('/\s+/u', ' ', $v);
        $v = str_replace(['I', 'İ'], ['ı', 'i'], $v);
        $v = mb_strtolower($v, 'UTF-8');
        $words = explode(' ', $v);
        $words = array_map(function(string $w): string {
            if ($w === '') return '';
            $first = mb_substr($w, 0, 1, 'UTF-8');
            $rest  = mb_substr($w, 1, null, 'UTF-8');
            if ($first === 'i') return 'İ' . $rest;
            if ($first === 'ı') return 'I' . $rest;
            return mb_strtoupper($first, 'UTF-8') . $rest;
        }, $words);
        return implode(' ', $words);
    }
}

// ── Normalize malzeme anahtarı ──────────────────────────────
// Amacı: "C-5 Siyah 30x40x14" = "C-5 SIYAH 30X40X14" = "C-5 SİYAH 30X40X14"
function nmk(string $name): string {
    $s = trim($name);
    // Görünmez karakterleri temizle
    $s = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
    // Çoklu boşluk → tek
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    // Türkçe büyük harf (İ→I dönüşüm standartlaştırması)
    $s = str_replace(['ı','i','İ','I','ş','Ş','ğ','Ğ','ü','Ü','ö','Ö','ç','Ç'],
                     ['I','I','I','I','S','S','G','G','U','U','O','O','C','C'], $s);
    $s = mb_strtoupper($s, 'UTF-8');
    // x / X ölçü normalize (boşluklu yazım da dahil)
    $s = preg_replace('/\s*[xX]\s*/', 'X', $s);
    // Tire çevresindeki boşlukları kaldır
    $s = preg_replace('/\s*-\s*/', '-', $s);
    return $s;
}

// ── Tablo var mı yardımcı ───────────────────────────────────
function tbl_exists(string $t): bool {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

$pdo = db();
$has_msm   = tbl_exists('material_stock_movements');
$has_pm    = tbl_exists('pallet_materials');
$has_lp    = tbl_exists('loading_pallets');
$has_lr    = tbl_exists('loading_records');
$has_md    = tbl_exists('material_definitions');

// ============================================================
// VERİ TOPLAMA — SADECE SELECT
// ============================================================

// ── A) material_definitions tam listesi ─────────────────────
$all_defs = $has_md
    ? $pdo->query("SELECT id, type, name, is_active, created_at FROM material_definitions ORDER BY name, type")->fetchAll()
    : [];

// Normalize anahtarlarla grupla
$norm_groups = []; // [norm_key => [type => [rows]]]
foreach ($all_defs as $d) {
    $nk = nmk($d['name']);
    $norm_groups[$nk][$d['type']][] = $d;
}

// Duplicate gruplar: aynı normalize key + type altında 2+ id
$duplicate_groups = [];
foreach ($norm_groups as $nk => $by_type) {
    foreach ($by_type as $type => $rows) {
        if (count($rows) > 1) {
            $duplicate_groups[] = [
                'norm_key' => $nk,
                'type'     => $type,
                'rows'     => $rows,
            ];
        }
    }
}

// Kritik malzemeler listesi
$kritik_names = ['C-5 Siyah', 'Yunan Kasa', 'C-10 Mavi', 'Şale Kasa', 'C-10 Siyah', 'C-5 Siyah 30x40x14', 'Yunan Kasa 30x40x18'];
$kritik_defs  = [];
foreach ($all_defs as $d) {
    foreach ($kritik_names as $kn) {
        if (mb_stripos($d['name'], $kn) !== false) {
            $kritik_defs[$d['id']] = $d;
        }
    }
}

// ── Stok hareketlerini material_id bazında özetle ────────────
$msm_by_mid = []; // material_id → [giris, kullanim, sevk, duzeltme, depolar]
if ($has_msm) {
    $msm_agg = $pdo->query("
        SELECT
            material_id,
            SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS giris,
            SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS kullanim,
            SUM(CASE WHEN movement_type='sevk'     THEN quantity ELSE 0 END) AS sevk,
            SUM(CASE WHEN movement_type='duzeltme' THEN quantity ELSE 0 END) AS duzeltme,
            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(depo,''), '[Boş]') ORDER BY depo SEPARATOR ', ') AS depolar
        FROM material_stock_movements
        GROUP BY material_id
    ")->fetchAll();
    foreach ($msm_agg as $row) {
        $mid = $row['material_id'] ?? 'NULL';
        $msm_by_mid[(string)$mid] = $row;
    }
}

// Duplicate gruplara stok bilgisi ekle
foreach ($duplicate_groups as &$dg) {
    foreach ($dg['rows'] as &$r) {
        $mid = (string)$r['id'];
        $r['msm'] = $msm_by_mid[$mid] ?? null;
    }
    unset($r);
}
unset($dg);

// ── B) material_id NULL hareketler ──────────────────────────
$null_mid_rows = [];
$null_mid_count = 0;
$null_mid_qty   = 0;
if ($has_msm) {
    $null_mid_count = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements WHERE material_id IS NULL")->fetchColumn();
    $null_mid_rows  = $pdo->query("
        SELECT id, movement_date, movement_type, material_name, material_type,
               depo, quantity, unit, source_type, source_id, note
        FROM material_stock_movements
        WHERE material_id IS NULL
        ORDER BY id DESC
        LIMIT 100
    ")->fetchAll();
    foreach ($null_mid_rows as $r) $null_mid_qty += (float)$r['quantity'];
}

// ── C) Depo uyuşmazlığı: aynı material_id, farklı depo ──────
$depo_rows = [];
if ($has_msm) {
    $depo_rows = $pdo->query("
        SELECT
            msm.material_id,
            COALESCE(md.name, msm.material_name, '—') AS mat_name,
            COALESCE(NULLIF(msm.depo,''), '[Boş]') AS depo,
            SUM(CASE WHEN msm.movement_type='giris'    THEN msm.quantity ELSE 0 END) AS giris,
            SUM(CASE WHEN msm.movement_type='kullanim' THEN msm.quantity ELSE 0 END) AS kullanim,
            SUM(CASE WHEN msm.movement_type='sevk'     THEN msm.quantity ELSE 0 END) AS sevk,
            SUM(CASE WHEN msm.movement_type='duzeltme' THEN msm.quantity ELSE 0 END) AS duzeltme
        FROM material_stock_movements msm
        LEFT JOIN material_definitions md ON md.id = msm.material_id
        WHERE msm.material_id IS NOT NULL
        GROUP BY msm.material_id, msm.depo
        ORDER BY mat_name, msm.depo
    ")->fetchAll();
}

// Hangi material_id'lerde hem Boş hem dolu depo var?
$depo_mixed_mids = [];
$depo_by_mid = [];
foreach ($depo_rows as $r) {
    $depo_by_mid[$r['material_id']][] = $r;
}
foreach ($depo_by_mid as $mid => $rows) {
    $depolar = array_column($rows, 'depo');
    $has_bos  = in_array('[Boş]', $depolar);
    $has_dolu = count(array_filter($depolar, fn($d) => $d !== '[Boş]')) > 0;
    if ($has_bos && $has_dolu) {
        $depo_mixed_mids[] = $mid;
    }
}
$depo_bos_count = count(array_filter($depo_rows, fn($r) => $r['depo'] === '[Boş]' && $r['giris'] > 0));

// ── D) Giriş başka ID, kullanım başka ID ────────────────────
// Aynı normalize_key + type için farklı ID'lere dağılmış giriş/kullanım
$split_risks = [];
if ($has_msm && $has_md) {
    $msm_totals = $pdo->query("
        SELECT material_id,
               SUM(CASE WHEN movement_type='giris'    THEN quantity ELSE 0 END) AS giris,
               SUM(CASE WHEN movement_type='kullanim' THEN quantity ELSE 0 END) AS kullanim,
               GROUP_CONCAT(DISTINCT COALESCE(NULLIF(depo,''),'[Boş]') SEPARATOR ', ') AS depolar
        FROM material_stock_movements
        WHERE material_id IS NOT NULL
        GROUP BY material_id
    ")->fetchAll();
    $mid_totals = [];
    foreach ($msm_totals as $r) $mid_totals[(int)$r['material_id']] = $r;

    // Duplicate gruplar zaten var — her biri potansiyel split risk
    foreach ($duplicate_groups as $dg) {
        $group_giris    = 0;
        $group_kullanim = 0;
        $ids_with_giris    = [];
        $ids_with_kullanim = [];
        foreach ($dg['rows'] as $row) {
            $mid = (int)$row['id'];
            $t   = $mid_totals[$mid] ?? null;
            if (!$t) continue;
            if ((float)$t['giris'] > 0) {
                $ids_with_giris[] = ['id' => $mid, 'name' => $row['name'], 'giris' => (float)$t['giris'], 'depolar' => $t['depolar']];
                $group_giris += (float)$t['giris'];
            }
            if ((float)$t['kullanim'] > 0) {
                $ids_with_kullanim[] = ['id' => $mid, 'name' => $row['name'], 'kullanim' => (float)$t['kullanim'], 'depolar' => $t['depolar']];
                $group_kullanim += (float)$t['kullanim'];
            }
        }
        // En az bir id'de giriş, başka id'de kullanım varsa split risk
        $giris_ids    = array_column($ids_with_giris, 'id');
        $kullanim_ids = array_column($ids_with_kullanim, 'id');
        $intersect    = array_intersect($giris_ids, $kullanim_ids);
        $is_split     = (count($giris_ids) > 0 && count($kullanim_ids) > 0 && count($intersect) < count($kullanim_ids));
        if ($is_split || (count($ids_with_giris) > 0 && count($ids_with_kullanim) > 0)) {
            $risk = 'ORTA';
            if ($is_split) $risk = 'YÜKSEK';
            $net = $group_giris - $group_kullanim;
            if ($net < 0) $risk = 'KRİTİK';
            $split_risks[] = [
                'norm_key'      => $dg['norm_key'],
                'type'          => $dg['type'],
                'rows'          => $dg['rows'],
                'ids_giris'     => $ids_with_giris,
                'ids_kullanim'  => $ids_with_kullanim,
                'group_giris'   => $group_giris,
                'group_kullanim'=> $group_kullanim,
                'net'           => $net,
                'risk'          => $risk,
            ];
        }
    }
}

// ── E) Orphan hareketler ─────────────────────────────────────
$orphan_count = 0;
$orphan_rows  = [];
if ($has_msm && $has_lr) {
    $orphan_count = (int)$pdo->query("
        SELECT COUNT(*)
        FROM material_stock_movements m
        WHERE m.source_type='loading'
          AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id = m.source_id)
    ")->fetchColumn();
    $orphan_rows = $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_name, m.material_type,
               m.depo, m.quantity, m.unit, m.source_id, m.note
        FROM material_stock_movements m
        WHERE m.source_type='loading'
          AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id = m.source_id)
        ORDER BY m.id DESC
        LIMIT 50
    ")->fetchAll();
}

// ── F) Sync eksik yükleme kayıtları ─────────────────────────
$sync_eksik_count = 0;
$sync_eksik_rows  = [];
if ($has_lr && $has_lp && $has_msm) {
    $sync_eksik_count = (int)$pdo->query("
        SELECT COUNT(DISTINCT lr.id)
        FROM loading_records lr
        WHERE COALESCE(lr.type,'yukleme') = 'yukleme'
          AND EXISTS (SELECT 1 FROM loading_pallets lp WHERE lp.loading_record_id = lr.id)
          AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)
    ")->fetchColumn();
    $sync_eksik_rows = $pdo->query("
        SELECT lr.id, lr.tarih, lr.firma, lr.urun, lr.durum,
               COUNT(lp.id) AS palet_sayisi,
               COALESCE(SUM(lp.kasa_adeti),0) AS kasa_toplam
        FROM loading_records lr
        LEFT JOIN loading_pallets lp ON lp.loading_record_id = lr.id
        WHERE COALESCE(lr.type,'yukleme') = 'yukleme'
          AND EXISTS (SELECT 1 FROM loading_pallets lp2 WHERE lp2.loading_record_id = lr.id)
          AND NOT EXISTS (SELECT 1 FROM material_stock_movements m WHERE m.source_type='loading' AND m.source_id = lr.id)
        GROUP BY lr.id, lr.tarih, lr.firma, lr.urun, lr.durum
        ORDER BY lr.tarih DESC, lr.id DESC
        LIMIT 50
    ")->fetchAll();
}

// ── H) Negatif stok malzemeleri — hareket detayı ────────────
$neg_malzemeler = [];
if ($has_msm && $has_md) {
    $neg_ids_q = $pdo->query("
        SELECT msm.material_id,
               COALESCE(MIN(md.name), MAX(msm.material_name)) AS mat_name,
               SUM(CASE WHEN msm.movement_type='giris'    THEN msm.quantity ELSE 0 END) AS giris,
               SUM(CASE WHEN msm.movement_type='kullanim' THEN msm.quantity ELSE 0 END) AS kullanim,
               SUM(CASE WHEN msm.movement_type='sevk'     THEN msm.quantity ELSE 0 END) AS sevk,
               SUM(CASE WHEN msm.movement_type='duzeltme' THEN msm.quantity ELSE 0 END) AS duzeltme,
               GROUP_CONCAT(DISTINCT COALESCE(NULLIF(msm.depo,''),'[Boş]') SEPARATOR ', ') AS depolar
        FROM material_stock_movements msm
        LEFT JOIN material_definitions md ON md.id = msm.material_id
        WHERE msm.material_id IS NOT NULL
        GROUP BY msm.material_id
        HAVING SUM(CASE WHEN msm.movement_type='giris'    THEN msm.quantity ELSE 0 END)
             - SUM(CASE WHEN msm.movement_type='kullanim' THEN msm.quantity ELSE 0 END)
             - SUM(CASE WHEN msm.movement_type='sevk'     THEN msm.quantity ELSE 0 END)
             + SUM(CASE WHEN msm.movement_type='duzeltme' THEN msm.quantity ELSE 0 END) < 0
        ORDER BY (
            SUM(CASE WHEN msm.movement_type='giris'    THEN msm.quantity ELSE 0 END)
          - SUM(CASE WHEN msm.movement_type='kullanim' THEN msm.quantity ELSE 0 END)
          - SUM(CASE WHEN msm.movement_type='sevk'     THEN msm.quantity ELSE 0 END)
          + SUM(CASE WHEN msm.movement_type='duzeltme' THEN msm.quantity ELSE 0 END)
        ) ASC
    ")->fetchAll();

    foreach ($neg_ids_q as $nq) {
        $mid = (int)$nq['material_id'];
        $net = (float)$nq['giris'] - (float)$nq['kullanim'] - (float)$nq['sevk'] + (float)$nq['duzeltme'];

        // Tüm hareketler + yükleme kaydı join
        $sth = $pdo->prepare("
            SELECT m.id, m.movement_date, m.movement_type, m.material_name, m.depo,
                   m.quantity, m.unit, m.source_type, m.source_id, m.note,
                   lr.id AS lr_id, lr.firma, lr.parti_no, lr.urun,
                   lr.tarih AS lr_tarih, lr.alici, lr.durum, lr.type AS lr_type
            FROM material_stock_movements m
            LEFT JOIN loading_records lr
                   ON m.source_type = 'loading' AND lr.id = m.source_id
            WHERE m.material_id = ?
            ORDER BY m.movement_date DESC, m.id DESC
            LIMIT 500
        ");
        $sth->execute([$mid]);
        $hareketler = $sth->fetchAll();

        $giris_h    = array_values(array_filter($hareketler, fn($r) => $r['movement_type'] === 'giris'));
        $kullanim_h = array_values(array_filter($hareketler, fn($r) => $r['movement_type'] === 'kullanim'));

        // Benzer isimli tanımlar (nmk token eşleşmesi)
        $norm_tokens = array_filter(explode(' ', nmk((string)$nq['mat_name'])), fn($t) => strlen($t) >= 2);
        $benzer = [];
        foreach ($all_defs as $d) {
            if ((int)$d['id'] === $mid) continue;
            $dn_tokens = array_filter(explode(' ', nmk($d['name'])), fn($t) => strlen($t) >= 2);
            if (count(array_intersect($norm_tokens, $dn_tokens)) > 0) {
                $dmid = (string)$d['id'];
                $dm   = $msm_by_mid[$dmid] ?? null;
                $dg   = (float)($dm['giris']    ?? 0);
                $dk   = (float)($dm['kullanim'] ?? 0);
                $ds   = (float)($dm['sevk']     ?? 0);
                $dd   = (float)($dm['duzeltme'] ?? 0);
                $benzer[] = [
                    'id'        => $d['id'],
                    'name'      => $d['name'],
                    'type'      => $d['type'],
                    'is_active' => $d['is_active'],
                    'giris'     => $dg,
                    'kullanim'  => $dk,
                    'net'       => $dg - $dk - $ds + $dd,
                    'depolar'   => $dm['depolar'] ?? '—',
                ];
            }
        }

        // Öneri
        $oneriler = [];
        if ((float)$nq['giris'] == 0) {
            $oneriler[] = ['sev' => 'crit', 'msg' => 'Bu malzeme için hiç stok girişi yapılmamış. Başlangıç stok girişi veya düzeltme hareketi eklenmeli.'];
        } else {
            $oneriler[] = ['sev' => 'warn', 'msg' => 'Giriş (' . number_format((float)$nq['giris'], 0) . ') kullanımı (' . number_format((float)$nq['kullanim'], 0) . ') karşılamıyor. Ek stok girişi veya düzeltme hareketi değerlendirilmeli.'];
        }
        $benzer_girisli = array_filter($benzer, fn($b) => $b['giris'] > 0);
        if (!empty($benzer_girisli)) {
            $isimler = implode(', ', array_map(fn($b) => '"' . h($b['name']) . '"', $benzer_girisli));
            $oneriler[] = ['sev' => 'warn', 'msg' => 'Benzer isimde stok girişi olan tanım var: ' . $isimler . '. Muhtemel yanlış malzeme eşleşmesi — merge/transfer değerlendirilmeli.'];
        }

        $neg_malzemeler[] = [
            'mid'        => $mid,
            'mat_name'   => $nq['mat_name'],
            'giris'      => (float)$nq['giris'],
            'kullanim'   => (float)$nq['kullanim'],
            'sevk'       => (float)$nq['sevk'],
            'net'        => $net,
            'depolar'    => $nq['depolar'],
            'hareketler' => $hareketler,
            'giris_h'    => $giris_h,
            'kullanim_h' => $kullanim_h,
            'benzer'     => $benzer,
            'oneriler'   => $oneriler,
        ];
    }
}

// ── H2) Düzeltme planı — kategori, benzer_pozitif, kullanım kaynağı ──
$def_by_id = [];
foreach ($all_defs as $d) $def_by_id[(int)$d['id']] = $d;
$has_mti = tbl_exists('material_template_items');

$dp_toplam_eksik = 0.0;
foreach ($neg_malzemeler as &$nm) {
    $mid = (int)$nm['mid'];
    $nm['eksik_miktar'] = abs($nm['net']);
    $dp_toplam_eksik   += $nm['eksik_miktar'];
    $nm['mat_type']     = $def_by_id[$mid]['type'] ?? '';

    // Benzer içinden net > 0 olanlar
    $nm['benzer_pozitif'] = array_values(array_filter($nm['benzer'], fn($b) => $b['net'] > 0));
    $bp_same_count = count(array_filter($nm['benzer_pozitif'], fn($b) => $b['type'] === $nm['mat_type']));
    $bp_count = count($nm['benzer_pozitif']);

    // Kategori
    if ($bp_count === 0) {
        $nm['kategori']          = 'A';
        $nm['kategori_metin']    = 'Eksik Stok Girişi';
        $nm['kategori_renk']     = 'crit';
        $nm['kategori_aciklama'] = $nm['giris'] > 0
            ? 'Giriş (' . number_format($nm['giris'], 0) . ') kullanımı (' . number_format($nm['kullanim'], 0) . ') karşılamıyor. Ek stok girişi veya düzeltme hareketi yapılmalı.'
            : 'Bu malzeme için hiç stok girişi yapılmamış. Başlangıç stok girişi veya düzeltme hareketi eklenmeli.';
    } elseif ($bp_count >= 3 || $bp_same_count >= 2) {
        $nm['kategori']          = 'D';
        $nm['kategori_metin']    = 'Manuel Karar';
        $nm['kategori_renk']     = 'purple';
        $nm['kategori_aciklama'] = $bp_count . ' benzer pozitif tanım var' . ($bp_same_count > 0 ? ', ' . $bp_same_count . ' tanesi aynı tip' : '') . '. Hangi tanımın bu kullanımla eşleştiğini manuel belirleyip merge/transfer kararı verilmeli. Otomatik birleştirme yapılmamalı.';
    } else {
        $nm['kategori']          = 'B';
        $nm['kategori_metin']    = 'Benzer Tanım / Eşleştirme';
        $nm['kategori_renk']     = 'warn';
        $nm['kategori_aciklama'] = 'Benzer isimde ' . $bp_count . ' pozitif stoklu tanım var' . ($bp_same_count > 0 ? ' (' . $bp_same_count . ' aynı tip)' : '') . '. Stok girişi yanlış tanıma yapılmış olabilir — merge/transfer/mapping kararı gerekir.';
    }

    // Kullanım kaynağı
    $kaynaklar = [];
    if ($has_lp) {
        $sth = $pdo->prepare("SELECT COUNT(*) AS cnt, MIN(loading_record_id) AS ornek_id FROM loading_pallets WHERE kasa_cinsi_id = ?");
        $sth->execute([$mid]); $r = $sth->fetch();
        if ($r && (int)$r['cnt'] > 0)
            $kaynaklar[] = ['tip' => 'Kasa Cinsi', 'alan' => 'loading_pallets.kasa_cinsi_id', 'sayi' => (int)$r['cnt'], 'ornek_id' => $r['ornek_id'], 'aciklama' => 'Yükleme planı kasa cinsi seçiminden geliyor.'];

        $sth = $pdo->prepare("SELECT COUNT(*) AS cnt, MIN(loading_record_id) AS ornek_id FROM loading_pallets WHERE palet_tipi_id = ?");
        $sth->execute([$mid]); $r = $sth->fetch();
        if ($r && (int)$r['cnt'] > 0)
            $kaynaklar[] = ['tip' => 'Palet Tipi', 'alan' => 'loading_pallets.palet_tipi_id', 'sayi' => (int)$r['cnt'], 'ornek_id' => $r['ornek_id'], 'aciklama' => 'Yükleme planı palet tipi seçiminden geliyor.'];
    }
    if ($has_pm) {
        $sth = $pdo->prepare("SELECT COUNT(*) AS cnt, MIN(lp.loading_record_id) AS ornek_id FROM pallet_materials pm LEFT JOIN loading_pallets lp ON lp.id = pm.pallet_id WHERE pm.material_id = ?");
        $sth->execute([$mid]); $r = $sth->fetch();
        if ($r && (int)$r['cnt'] > 0)
            $kaynaklar[] = ['tip' => 'Ek Malzeme', 'alan' => 'pallet_materials.material_id', 'sayi' => (int)$r['cnt'], 'ornek_id' => $r['ornek_id'], 'aciklama' => 'Palet ek malzeme listesinden geliyor.'];
    }
    if ($has_mti) {
        $sth = $pdo->prepare("SELECT COUNT(*) AS cnt FROM material_template_items WHERE material_id = ?");
        $sth->execute([$mid]); $r = $sth->fetch();
        if ($r && (int)$r['cnt'] > 0)
            $kaynaklar[] = ['tip' => 'Şablon', 'alan' => 'material_template_items.material_id', 'sayi' => (int)$r['cnt'], 'ornek_id' => null, 'aciklama' => 'Malzeme şablonunda tanımlanmış.'];
    }
    if (empty($kaynaklar))
        $kaynaklar[] = ['tip' => 'Bilinmiyor', 'alan' => '—', 'sayi' => 0, 'ornek_id' => null, 'aciklama' => 'Kaynak tespit edilemedi. Hareket notlarını kontrol edin.'];
    $nm['kaynaklar'] = $kaynaklar;
}
unset($nm);

$dp_kategori_a = count(array_filter($neg_malzemeler, fn($n) => ($n['kategori'] ?? '') === 'A'));
$dp_kategori_b = count(array_filter($neg_malzemeler, fn($n) => ($n['kategori'] ?? '') === 'B'));
$dp_kategori_d = count(array_filter($neg_malzemeler, fn($n) => ($n['kategori'] ?? '') === 'D'));

// ── G) Pasif tanım hâlâ kullanımda mı? ──────────────────────
$pasif_kullanim_rows = [];
if ($has_md && $has_lp) {
    $pasif_kullanim_rows = $pdo->query("
        SELECT md.id, md.type, md.name, md.is_active,
               COUNT(DISTINCT lp.id) AS palet_kullanim
        FROM material_definitions md
        LEFT JOIN loading_pallets lp ON lp.kasa_cinsi_id = md.id OR lp.palet_tipi_id = md.id
        WHERE md.is_active = 0
          AND lp.id IS NOT NULL
        GROUP BY md.id, md.type, md.name, md.is_active
        ORDER BY palet_kullanim DESC
    ")->fetchAll();
}

// ── Özet sayılar ─────────────────────────────────────────────
$summary = [
    'duplicate_grup'    => count($duplicate_groups),
    'null_mid'          => $null_mid_count,
    'depo_bos_giris'    => $depo_bos_count,
    'split_risk'        => count($split_risks),
    'orphan'            => $orphan_count,
    'sync_eksik'        => $sync_eksik_count,
    'pasif_kullanim'    => count($pasif_kullanim_rows),
];
$toplam_sorun = $summary['duplicate_grup'] + ($summary['null_mid'] > 0 ? 1 : 0)
              + ($summary['split_risk'])    + ($summary['orphan'] > 0 ? 1 : 0)
              + ($summary['sync_eksik'] > 0 ? 1 : 0);

// ============================================================
// HTML
// ============================================================
render_header('Malzeme Stok Teşhis');
?>

<style>
.tehis-warn { background:#fff7ed;border:1.5px solid #fb923c;border-radius:8px;padding:10px 16px;margin-bottom:18px;font-size:.9rem;color:#c2410c;font-weight:600; }
.tehis-summary { display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:22px; }
.tehis-kart { border-radius:10px;padding:12px 14px;text-align:center;border:1.5px solid; }
.tehis-kart-num { font-size:1.8rem;font-weight:800;line-height:1; }
.tehis-kart-lbl { font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-top:4px; }
.tehis-kart.ok   { background:#f0fdf4;border-color:#86efac;color:#166534; }
.tehis-kart.warn { background:#fffbeb;border-color:#fde68a;color:#92400e; }
.tehis-kart.crit { background:#fef2f2;border-color:#fca5a5;color:#991b1b; }

.tehis-section { border:1px solid var(--border);border-radius:10px;margin-bottom:18px;overflow:hidden; }
.tehis-section-head { display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#f8fafc;border-bottom:1px solid var(--border);cursor:pointer;user-select:none; }
.tehis-section-head h3 { margin:0;font-size:.95rem;display:flex;align-items:center;gap:8px; }
.tehis-section-body { padding:14px; }
.tehis-badge { display:inline-block;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:700; }
.badge-crit  { background:#fee2e2;color:#991b1b; }
.badge-warn  { background:#fef3c7;color:#92400e; }
.badge-ok    { background:#dcfce7;color:#166534; }
.badge-info  { background:#dbeafe;color:#1e40af; }

.tehis-table { width:100%;border-collapse:collapse;font-size:.82rem;margin-top:8px; }
.tehis-table th { background:#f3f4f6;padding:5px 8px;text-align:left;font-size:.75rem;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap; }
.tehis-table td { padding:5px 8px;border-top:1px solid #f0f0f0;vertical-align:top;word-break:break-word; }
.tehis-table tr:hover td { background:#f9fafb; }
.tehis-table .num { text-align:right; }
.tehis-table .neg { color:#dc2626;font-weight:700; }
.tehis-table .pos { color:#16a34a; }
.tehis-norm { font-family:monospace;font-size:.78rem;background:#f3f4f6;padding:1px 4px;border-radius:3px; }
.crit-row td { background:#fff1f2 !important; }
.warn-row td { background:#fffbeb !important; }
.tehis-sub { font-size:.8rem;color:var(--muted);margin:6px 0 10px; }

@media(max-width:767px){
    .tehis-table { font-size:.75rem; }
    .tehis-table th,.tehis-table td { padding:4px 5px; }
    .tehis-summary { grid-template-columns: repeat(3,1fr); }
}
</style>

<div class="page-head">
    <div>
        <h1>🔬 Malzeme Stok Teşhis Raporu</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:4px">
            Sprint MalzemeStok-01A &nbsp;·&nbsp; Çalışma: <strong><?= date('d.m.Y H:i:s') ?></strong>
        </p>
    </div>
</div>

<div class="tehis-warn">
    ⚠ Bu sayfa yalnızca okuma amaçlıdır. Hiçbir veri değiştirilmez.
    Yalnızca admin kullanıcılara açıktır.
</div>

<!-- Özet kartları -->
<div class="tehis-summary">
    <div class="tehis-kart <?= $summary['duplicate_grup'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['duplicate_grup'] ?></div>
        <div class="tehis-kart-lbl">Duplicate Grup</div>
    </div>
    <div class="tehis-kart <?= $summary['null_mid'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['null_mid'] ?></div>
        <div class="tehis-kart-lbl">material_id NULL</div>
    </div>
    <div class="tehis-kart <?= $summary['depo_bos_giris'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['depo_bos_giris'] ?></div>
        <div class="tehis-kart-lbl">Depo Boş Giriş</div>
    </div>
    <div class="tehis-kart <?= $summary['split_risk'] > 0 ? 'crit' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['split_risk'] ?></div>
        <div class="tehis-kart-lbl">Giriş/Kullanım Split</div>
    </div>
    <div class="tehis-kart <?= $summary['orphan'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['orphan'] ?></div>
        <div class="tehis-kart-lbl">Orphan Hareket</div>
    </div>
    <div class="tehis-kart <?= $summary['sync_eksik'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['sync_eksik'] ?></div>
        <div class="tehis-kart-lbl">Sync Eksik Kayıt</div>
    </div>
    <div class="tehis-kart <?= $summary['pasif_kullanim'] > 0 ? 'warn' : 'ok' ?>">
        <div class="tehis-kart-num"><?= $summary['pasif_kullanim'] ?></div>
        <div class="tehis-kart-lbl">Pasif Def. Kullanımda</div>
    </div>
</div>

<?php
// ── Section toggle JS yardımcısı ─────────────────────────────
function tehis_section_open(string $id, string $title, string $badge_html, bool $open = true): void {
    $chev = $open ? '▾' : '▸';
    echo '<div class="tehis-section">';
    echo '<div class="tehis-section-head" onclick="this.nextElementSibling.hidden=!this.nextElementSibling.hidden;this.querySelector(\'.th-chev\').textContent=this.nextElementSibling.hidden?\'▸\':\'▾\'">';
    echo '<h3>' . $title . ' ' . $badge_html . '</h3>';
    echo '<span class="th-chev">' . $chev . '</span>';
    echo '</div>';
    echo '<div class="tehis-section-body"' . ($open ? '' : ' hidden') . '>';
}
function tehis_section_close(): void { echo '</div></div>'; }
?>

<!-- ──────────────────────────────────────────────────────────
     KRİTİK TAKİP: şüpheli malzemeler
     ────────────────────────────────────────────────────────── -->
<?php tehis_section_open('kritik', '⚡ Kritik Takip Malzemeleri', '<span class="tehis-badge badge-crit">'.count($kritik_defs).' tanım</span>'); ?>
<p class="tehis-sub">Sorun bildirilen malzemelerin tüm material_definitions kayıtları ve stok hareketleri.</p>
<?php if (empty($kritik_defs)): ?>
    <p style="color:#16a34a">✓ Kritik listede eşleşen tanım bulunamadı.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Type</th><th>İsim</th><th>Aktif</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Sevk</th><th class="num">Net Kalan</th>
        <th>Depolar</th>
    </tr>
    </thead>
    <tbody>
    <?php
    // Normalize key bazında grupla
    $kritik_by_norm = [];
    foreach ($kritik_defs as $d) {
        $kritik_by_norm[nmk($d['name']) . '::' . $d['type']][] = $d;
    }
    foreach ($kritik_by_norm as $grp_rows):
        $is_dup = count($grp_rows) > 1;
        foreach ($grp_rows as $d):
            $mid = (string)$d['id'];
            $msm = $msm_by_mid[$mid] ?? null;
            $giris    = (float)($msm['giris']    ?? 0);
            $kull     = (float)($msm['kullanim'] ?? 0);
            $sevk     = (float)($msm['sevk']     ?? 0);
            $duz      = (float)($msm['duzeltme'] ?? 0);
            $net      = $giris - $kull - $sevk + $duz;
            $row_cls  = $is_dup ? 'crit-row' : ($net < 0 ? 'warn-row' : '');
    ?>
    <tr class="<?= $row_cls ?>">
        <td><strong><?= (int)$d['id'] ?></strong><?= $is_dup ? ' <span class="tehis-badge badge-crit">DUP</span>' : '' ?></td>
        <td><?= h($d['type']) ?></td>
        <td><?= h($d['name']) ?></td>
        <td><?= $d['is_active'] ? '<span class="tehis-badge badge-ok">Aktif</span>' : '<span class="tehis-badge badge-warn">Pasif</span>' ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris, 0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull, 0) : '—' ?></td>
        <td class="num"><?= $sevk > 0 ? number_format($sevk, 0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : ($net > 0 ? 'pos' : '') ?>"><?= number_format($net, 0) ?></td>
        <td style="font-size:.75rem"><?= h($msm['depolar'] ?? '—') ?></td>
    </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 1: Duplicate material_definitions
     ────────────────────────────────────────────────────────── -->
<?php
$dup_badge = $summary['duplicate_grup'] > 0
    ? '<span class="tehis-badge badge-crit">'.$summary['duplicate_grup'].' grup</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b1', '1 · Duplicate Tanımlar', $dup_badge, $summary['duplicate_grup'] > 0);
?>
<p class="tehis-sub">Aynı normalize isim + type altında 2+ farklı ID bulunduran malzeme tanımları. Bu gruplar giriş/kullanım karışmasının temel kaynağıdır.</p>
<?php if (empty($duplicate_groups)): ?>
    <p style="color:#16a34a">✓ Aynı tip ve normalize isimde tekrar eden tanım bulunamadı.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>Normalize Anahtar</th><th>Type</th><th>ID</th><th>Gerçek İsim</th><th>Aktif</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Net</th><th>Depolar</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($duplicate_groups as $dg):
        $first = true;
        foreach ($dg['rows'] as $r):
            $msm   = $r['msm'];
            $giris = (float)($msm['giris']    ?? 0);
            $kull  = (float)($msm['kullanim'] ?? 0);
            $sevk  = (float)($msm['sevk']     ?? 0);
            $duz   = (float)($msm['duzeltme'] ?? 0);
            $net   = $giris - $kull - $sevk + $duz;
    ?>
    <tr class="crit-row">
        <td><?= $first ? '<span class="tehis-norm">'.h($dg['norm_key']).'</span>' : '' ?></td>
        <td><?= $first ? h($dg['type']) : '' ?></td>
        <td><strong><?= (int)$r['id'] ?></strong></td>
        <td><?= h($r['name']) ?></td>
        <td><?= $r['is_active'] ? '<span class="tehis-badge badge-ok">Aktif</span>' : '<span class="tehis-badge badge-warn">Pasif</span>' ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris,0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull,0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : '' ?>"><?= number_format($net,0) ?></td>
        <td style="font-size:.75rem"><?= h($msm['depolar'] ?? '—') ?></td>
    </tr>
    <?php $first = false; endforeach; endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 2: material_id NULL hareketler
     ────────────────────────────────────────────────────────── -->
<?php
$null_badge = $summary['null_mid'] > 0
    ? '<span class="tehis-badge badge-crit">'.$summary['null_mid'].' hareket</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b2', '2 · material_id NULL Hareketler', $null_badge, $summary['null_mid'] > 0);
?>
<p class="tehis-sub">Bu hareketler hiçbir malzeme tanımına bağlanamamış. Stok ekranında "tanımsız" satır olarak görünür.</p>
<?php if ($null_mid_count === 0): ?>
    <p style="color:#16a34a">✓ material_id NULL hareket bulunamadı.</p>
<?php else: ?>
<p><strong><?= $null_mid_count ?></strong> hareket, toplam miktar: <strong><?= number_format($null_mid_qty, 0) ?></strong></p>
<?php
// Malzeme adı dağılımı
$null_names = array_count_values(array_filter(array_column($null_mid_rows, 'material_name')));
arsort($null_names);
if ($null_names):
?>
<p style="font-size:.82rem;margin-bottom:6px">
    Geçen malzeme isimleri: <?= h(implode(', ', array_map(fn($n,$c) => "$n ($c)", array_keys($null_names), $null_names))) ?>
</p>
<?php endif; ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Tür</th><th>Malzeme Adı</th><th>Malzeme Tipi</th>
        <th class="num">Miktar</th><th>Birim</th><th>Depo</th><th>Kaynak Tip</th><th>Kaynak ID</th><th>Not</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($null_mid_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['movement_date'] ?? '') ?></td>
        <td><?= h($r['movement_type']) ?></td>
        <td><?= h($r['material_name'] ?? '—') ?></td>
        <td><?= h($r['material_type'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$r['quantity'], 2) ?></td>
        <td><?= h($r['unit'] ?? '') ?></td>
        <td><?= h($r['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
        <td><?= h($r['source_type'] ?? '') ?></td>
        <td><?= $r['source_id'] ? (int)$r['source_id'] : '—' ?></td>
        <td style="font-size:.75rem"><?= h(mb_substr($r['note'] ?? '', 0, 60)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($null_mid_count > 100): ?>
    <tr><td colspan="11" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 100 satır gösteriliyor (toplam <?= $null_mid_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 3: Depo Boş / Depo Uyuşmazlığı
     ────────────────────────────────────────────────────────── -->
<?php
$depo_badge = count($depo_mixed_mids) > 0
    ? '<span class="tehis-badge badge-warn">'.count($depo_mixed_mids).' malzeme karışık</span>'
    : ($depo_bos_count > 0 ? '<span class="tehis-badge badge-warn">'.$depo_bos_count.' boş giriş</span>' : '<span class="tehis-badge badge-ok">Temiz</span>');
tehis_section_open('b3', '3 · Depo Boş / Depo Uyuşmazlığı', $depo_badge, count($depo_mixed_mids) > 0);
?>
<p class="tehis-sub">Aynı material_id için hem "Depo Boş" hem de dolu depo hareketi olan malzemeler işaretlendi. Giriş ve kullanım farklı depo anahtarına düşüyorsa negatif stok oluşur.</p>

<?php if (empty($depo_mixed_mids) && $depo_bos_count === 0): ?>
    <p style="color:#16a34a">✓ Depo uyuşmazlığı bulunamadı.</p>
<?php else: ?>
<?php if (!empty($depo_mixed_mids)): ?>
<p style="color:#b45309;font-weight:600;font-size:.85rem;margin-bottom:8px">
    ⚠ <?= count($depo_mixed_mids) ?> malzeme için aynı ID'de hem "Boş Depo" hem de dolu depo hareketi var.
</p>
<?php endif; ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>material_id</th><th>Malzeme</th><th>Depo</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Sevk</th><th class="num">Net</th><th>Durum</th>
    </tr>
    </thead>
    <tbody>
    <?php
    $prev_mid = null;
    foreach ($depo_rows as $r):
        $mid     = $r['material_id'];
        $is_bos  = $r['depo'] === '[Boş]';
        $is_mix  = in_array($mid, $depo_mixed_mids);
        $giris   = (float)$r['giris'];
        $kull    = (float)$r['kullanim'];
        $sevk    = (float)$r['sevk'];
        $duz     = (float)$r['duzeltme'];
        $net     = $giris - $kull - $sevk + $duz;
        $row_cls = $is_mix ? ($is_bos ? 'crit-row' : 'warn-row') : '';
        // Sadece karışık ya da boş girişleri göster
        if (!$is_mix && !($is_bos && $giris > 0)) continue;
    ?>
    <tr class="<?= $row_cls ?>">
        <td><?= (int)$mid ?></td>
        <td><?= h($r['mat_name']) ?></td>
        <td><?= $is_bos ? '<em class="muted">[Boş Depo]</em>' : h($r['depo']) ?></td>
        <td class="num <?= $giris > 0 ? 'pos' : '' ?>"><?= $giris > 0 ? number_format($giris,0) : '—' ?></td>
        <td class="num"><?= $kull > 0 ? number_format($kull,0) : '—' ?></td>
        <td class="num"><?= $sevk > 0 ? number_format($sevk,0) : '—' ?></td>
        <td class="num <?= $net < 0 ? 'neg' : '' ?>"><?= number_format($net,0) ?></td>
        <td style="font-size:.75rem">
            <?php if ($is_mix && $is_bos): ?>
            <span class="tehis-badge badge-crit">Karışık-Boş</span>
            <?php elseif ($is_mix): ?>
            <span class="tehis-badge badge-warn">Karışık-Dolu</span>
            <?php elseif ($is_bos && $giris > 0): ?>
            <span class="tehis-badge badge-warn">Boş Depo Girişi</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 4: Giriş başka ID, kullanım başka ID
     ────────────────────────────────────────────────────────── -->
<?php
$split_badge = count($split_risks) > 0
    ? '<span class="tehis-badge badge-crit">'.count($split_risks).' grup</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b4', '4 · Giriş / Kullanım Farklı ID Split Riski', $split_badge, count($split_risks) > 0);
?>
<p class="tehis-sub">Aynı normalize malzeme adı altında: bir ID'de giriş, başka ID'de kullanım var. Bu durum negatif stok ve çift satır görünümünün doğrudan kaynağıdır.</p>
<?php if (empty($split_risks)): ?>
    <p style="color:#16a34a">✓ Giriş/kullanım split riski bulunamadı.</p>
<?php else: ?>
<?php foreach ($split_risks as $sr): ?>
<div style="border:1.5px solid <?= $sr['risk']==='KRİTİK'?'#dc2626':($sr['risk']==='YÜKSEK'?'#ea580c':'#f59e0b') ?>;border-radius:8px;padding:12px;margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span class="tehis-norm"><?= h($sr['norm_key']) ?></span>
        <span style="color:var(--muted);font-size:.8rem"><?= h($sr['type']) ?></span>
        <span class="tehis-badge <?= $sr['risk']==='KRİTİK'?'badge-crit':($sr['risk']==='YÜKSEK'?'badge-crit':'badge-warn') ?>"><?= h($sr['risk']) ?></span>
        <span style="font-size:.8rem">Toplam Giriş: <strong><?= number_format($sr['group_giris'],0) ?></strong>
        &nbsp;|&nbsp; Toplam Kullanım: <strong><?= number_format($sr['group_kullanim'],0) ?></strong>
        &nbsp;|&nbsp; Net: <strong class="<?= $sr['net']<0?'neg':'' ?>"><?= number_format($sr['net'],0) ?></strong></span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem">
        <div>
            <strong style="color:#16a34a">Giriş yapılan ID'ler:</strong>
            <?php if (empty($sr['ids_giris'])): ?>
            <p class="muted">Giriş yok</p>
            <?php else: ?>
            <table class="tehis-table" style="margin-top:4px">
                <tr><th>ID</th><th>İsim</th><th class="num">Giriş</th><th>Depolar</th></tr>
                <?php foreach ($sr['ids_giris'] as $ig): ?>
                <tr><td><?= (int)$ig['id'] ?></td><td><?= h($ig['name']) ?></td>
                    <td class="num pos"><?= number_format($ig['giris'],0) ?></td>
                    <td style="font-size:.73rem"><?= h($ig['depolar']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
        <div>
            <strong style="color:#dc2626">Kullanım yapılan ID'ler:</strong>
            <?php if (empty($sr['ids_kullanim'])): ?>
            <p class="muted">Kullanım yok</p>
            <?php else: ?>
            <table class="tehis-table" style="margin-top:4px">
                <tr><th>ID</th><th>İsim</th><th class="num">Kullanım</th><th>Depolar</th></tr>
                <?php foreach ($sr['ids_kullanim'] as $ik): ?>
                <tr><td><?= (int)$ik['id'] ?></td><td><?= h($ik['name']) ?></td>
                    <td class="num neg"><?= number_format($ik['kullanim'],0) ?></td>
                    <td style="font-size:.73rem"><?= h($ik['depolar']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 5: Orphan hareketler
     ────────────────────────────────────────────────────────── -->
<?php
$orphan_badge = $summary['orphan'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['orphan'].' hareket</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b5', '5 · Orphan Hareketler', $orphan_badge, $summary['orphan'] > 0);
?>
<p class="tehis-sub">source_type='loading' ama ilgili loading_records kaydı artık yok. Silinmiş kayıtlardan kalan stok hareketleri.</p>
<?php if ($orphan_count === 0): ?>
    <p style="color:#16a34a">✓ Orphan hareket bulunamadı.</p>
<?php else: ?>
<p><strong><?= $orphan_count ?></strong> orphan hareket bulundu.</p>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Tür</th><th>Malzeme</th>
        <th class="num">Miktar</th><th>Birim</th><th>Depo</th><th>Kaynak ID (silinmiş)</th><th>Not</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orphan_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['movement_date'] ?? '') ?></td>
        <td><?= h($r['movement_type']) ?></td>
        <td><?= h($r['material_name'] ?? '—') ?></td>
        <td class="num"><?= number_format((float)$r['quantity'], 0) ?></td>
        <td><?= h($r['unit'] ?? '') ?></td>
        <td><?= h($r['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
        <td style="color:#dc2626"><strong><?= (int)$r['source_id'] ?></strong></td>
        <td style="font-size:.75rem"><?= h(mb_substr($r['note'] ?? '', 0, 60)) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($orphan_count > 50): ?>
    <tr><td colspan="9" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 50 satır gösteriliyor (toplam <?= $orphan_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 6: Sync eksik yükleme kayıtları
     ────────────────────────────────────────────────────────── -->
<?php
$sync_badge = $summary['sync_eksik'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['sync_eksik'].' kayıt</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b6', '6 · Sync Eksik Yükleme Kayıtları', $sync_badge, $summary['sync_eksik'] > 0);
?>
<p class="tehis-sub">Yükleme kaydı + paleti var ama material_stock_movements'ta bu kayda ait hiç hareket yazılmamış. Bu kayıtların kasa/palet kullanımı stoka yansımıyor.</p>
<?php if ($sync_eksik_count === 0): ?>
    <p style="color:#16a34a">✓ Sync eksik kayıt bulunamadı.</p>
<?php else: ?>
<p><strong><?= $sync_eksik_count ?></strong> kayıt sync edilmemiş.</p>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr>
        <th>ID</th><th>Tarih</th><th>Firma</th><th>Ürün</th><th>Durum</th>
        <th class="num">Palet</th><th class="num">Kasa</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($sync_eksik_rows as $r): ?>
    <tr class="warn-row">
        <td><a href="record_view.php?id=<?= (int)$r['id'] ?>" target="_blank"><?= (int)$r['id'] ?></a></td>
        <td><?= h($r['tarih'] ?? '') ?></td>
        <td><?= h($r['firma'] ?? '—') ?></td>
        <td><?= h($r['urun'] ?? '—') ?></td>
        <td><?= h($r['durum'] ?? '') ?: '<em class="muted">—</em>' ?></td>
        <td class="num"><?= (int)$r['palet_sayisi'] ?></td>
        <td class="num"><?= (int)$r['kasa_toplam'] ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if ($sync_eksik_count > 50): ?>
    <tr><td colspan="7" style="text-align:center;color:var(--muted);font-size:.8rem">… ilk 50 satır gösteriliyor (toplam <?= $sync_eksik_count ?>)</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 7: Pasif tanım hâlâ kullanımda
     ────────────────────────────────────────────────────────── -->
<?php
$pasif_badge = $summary['pasif_kullanim'] > 0
    ? '<span class="tehis-badge badge-warn">'.$summary['pasif_kullanim'].' tanım</span>'
    : '<span class="tehis-badge badge-ok">Temiz</span>';
tehis_section_open('b7', '7 · Pasif Tanımlar Yükleme Planında Kullanımda', $pasif_badge, false);
?>
<p class="tehis-sub">is_active=0 olarak işaretlenmiş ama hâlâ loading_pallets.kasa_cinsi_id veya palet_tipi_id ile bağlantılı tanımlar.</p>
<?php if (empty($pasif_kullanim_rows)): ?>
    <p style="color:#16a34a">✓ Pasif tanım yükleme planında kullanılmıyor.</p>
<?php else: ?>
<div class="table-wrap">
<table class="tehis-table">
    <thead>
    <tr><th>ID</th><th>Type</th><th>İsim</th><th class="num">Palet Kullanım</th></tr>
    </thead>
    <tbody>
    <?php foreach ($pasif_kullanim_rows as $r): ?>
    <tr class="warn-row">
        <td><?= (int)$r['id'] ?></td>
        <td><?= h($r['type']) ?></td>
        <td><?= h($r['name']) ?></td>
        <td class="num"><?= (int)$r['palet_kullanim'] ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php tehis_section_close(); ?>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 8: Negatif Stok Hareket Detayı
     ────────────────────────────────────────────────────────── -->
<?php
$neg_badge = count($neg_malzemeler) > 0
    ? '<span class="tehis-badge badge-crit">'.count($neg_malzemeler).' malzeme</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b8', '8 · Negatif Stok Hareket Detayı', $neg_badge, count($neg_malzemeler) > 0);
?>
<p class="tehis-sub">
    Net stoğu negatife düşmüş malzemelerin tüm hareketleri, kaynak yükleme kayıtları, giriş durumu ve benzer isimli tanımlar.
    Sadece okuma — veri değiştirilmez.
</p>
<?php if (empty($neg_malzemeler)): ?>
    <p style="color:#16a34a">✓ Net stoğu negatife düşmüş malzeme bulunamadı.</p>
<?php else: ?>
<?php foreach ($neg_malzemeler as $nm):
    $nm_id = 'neg_' . (int)$nm['mid'];
?>
<details class="neg-detail" open>
    <summary class="neg-summary">
        <span class="neg-mat-name"><?= h($nm['mat_name']) ?></span>
        <span style="margin-left:10px;color:#dc2626;font-weight:800"><?= number_format($nm['net'], 0) ?></span>
        <span style="margin-left:10px;font-size:.78rem;color:var(--muted)">
            Giriş: <?= $nm['giris'] > 0 ? '<span style="color:#16a34a">'.number_format($nm['giris'],0).'</span>' : '<em>yok</em>' ?>
            &nbsp;/ Kullanım: <?= number_format($nm['kullanim'],0) ?>
            &nbsp;/ ID: <?= (int)$nm['mid'] ?>
            &nbsp;/ Depo: <?= h($nm['depolar']) ?>
        </span>
    </summary>
    <div class="neg-body">

        <!-- Öneriler -->
        <?php foreach ($nm['oneriler'] as $on): ?>
        <div class="neg-oneri neg-oneri-<?= $on['sev'] ?>">
            <?= $on['sev'] === 'crit' ? '🔴' : '🟡' ?>
            <?= h($on['msg']) ?>
        </div>
        <?php endforeach; ?>

        <!-- Kullanım kaynakları -->
        <details class="neg-sub" open>
            <summary><strong>Kullanım Kaynakları</strong>
                <span class="tehis-badge badge-crit" style="margin-left:6px"><?= count($nm['kullanim_h']) ?> hareket</span>
            </summary>
            <div class="table-wrap" style="margin-top:8px">
            <?php if (empty($nm['kullanim_h'])): ?>
                <p style="color:#16a34a;padding:6px">✓ Kullanım hareketi bulunamadı.</p>
            <?php else: ?>
            <table class="tehis-table">
                <thead><tr>
                    <th>Hk. ID</th><th>Tarih</th><th>Depo</th>
                    <th class="num">Miktar</th><th>Birim</th>
                    <th>Kaynak Tip</th><th>Yükleme ID</th><th>Firma</th>
                    <th>Parti No</th><th>Ürün</th><th>Yük. Tarih</th>
                    <th>Alıcı</th><th>Durum</th><th>Not</th>
                </tr></thead>
                <tbody>
                <?php foreach ($nm['kullanim_h'] as $h_row): ?>
                <tr class="crit-row">
                    <td><?= (int)$h_row['id'] ?></td>
                    <td style="white-space:nowrap"><?= h(substr($h_row['movement_date'] ?? '', 0, 16)) ?></td>
                    <td><?= h($h_row['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
                    <td class="num neg"><?= number_format((float)$h_row['quantity'], 0) ?></td>
                    <td><?= h($h_row['unit'] ?? '') ?></td>
                    <td><?= h($h_row['source_type'] ?? '—') ?></td>
                    <?php if ($h_row['lr_id']): ?>
                    <td><a href="record_view.php?id=<?= (int)$h_row['lr_id'] ?>" target="_blank"><?= (int)$h_row['lr_id'] ?></a></td>
                    <td><?= h($h_row['firma'] ?? '—') ?></td>
                    <td><?= h($h_row['parti_no'] ?? '—') ?></td>
                    <td><?= h($h_row['urun'] ?? '—') ?></td>
                    <td style="white-space:nowrap"><?= h($h_row['lr_tarih'] ?? '—') ?></td>
                    <td><?= h($h_row['alici'] ?? '—') ?></td>
                    <td><?= h($h_row['durum'] ?? '—') ?></td>
                    <?php else: ?>
                    <td colspan="7" style="color:#dc2626;font-style:italic">
                        <?= $h_row['source_id'] ? 'Bağlantısız kullanım (kaynak ID: '.(int)$h_row['source_id'].')' : 'Kaynak yok' ?>
                    </td>
                    <?php endif; ?>
                    <td style="font-size:.73rem;max-width:160px;word-break:break-word"><?= h(mb_substr($h_row['note'] ?? '', 0, 80)) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($nm['hareketler']) >= 500): ?>
                <tr><td colspan="14" style="text-align:center;color:var(--muted);font-size:.78rem">… ilk 500 hareket gösterildi</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>
        </details>

        <!-- Giriş hareketleri -->
        <details class="neg-sub">
            <summary><strong>Giriş Hareketleri</strong>
                <?php if (empty($nm['giris_h'])): ?>
                <span class="tehis-badge badge-crit" style="margin-left:6px">Giriş yok</span>
                <?php else: ?>
                <span class="tehis-badge badge-ok" style="margin-left:6px"><?= count($nm['giris_h']) ?> hareket</span>
                <?php endif; ?>
            </summary>
            <div style="margin-top:8px">
            <?php if (empty($nm['giris_h'])): ?>
                <p style="color:#dc2626;font-weight:600;padding:6px">⛔ Bu malzeme için kayıtlı stok girişi yok.</p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="tehis-table">
                <thead><tr>
                    <th>Hk. ID</th><th>Tarih</th><th>Depo</th>
                    <th class="num">Miktar</th><th>Birim</th>
                    <th>Kaynak Tip</th><th>Kaynak ID</th><th>Not</th>
                </tr></thead>
                <tbody>
                <?php foreach ($nm['giris_h'] as $h_row): ?>
                <tr>
                    <td><?= (int)$h_row['id'] ?></td>
                    <td style="white-space:nowrap"><?= h(substr($h_row['movement_date'] ?? '', 0, 16)) ?></td>
                    <td><?= h($h_row['depo'] ?? '') ?: '<em class="muted">Boş</em>' ?></td>
                    <td class="num pos"><?= number_format((float)$h_row['quantity'], 0) ?></td>
                    <td><?= h($h_row['unit'] ?? '') ?></td>
                    <td><?= h($h_row['source_type'] ?? '—') ?></td>
                    <td><?= $h_row['source_id'] ? (int)$h_row['source_id'] : '—' ?></td>
                    <td style="font-size:.73rem"><?= h(mb_substr($h_row['note'] ?? '', 0, 80)) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            </div>
        </details>

        <!-- Benzer isimli tanımlar -->
        <details class="neg-sub">
            <summary><strong>Benzer İsimli Tanımlar</strong>
                <?php if (empty($nm['benzer'])): ?>
                <span class="tehis-badge badge-info" style="margin-left:6px">Bulunamadı</span>
                <?php else: ?>
                <span class="tehis-badge badge-warn" style="margin-left:6px"><?= count($nm['benzer']) ?> tanım</span>
                <?php endif; ?>
            </summary>
            <div style="margin-top:8px">
            <?php if (empty($nm['benzer'])): ?>
                <p style="color:var(--muted);padding:6px;font-size:.85rem">Normalize isimde ortak token bulunan başka tanım yok.</p>
            <?php else: ?>
            <p class="tehis-sub">Normalize isimde ortak token paylaşan material_definitions kayıtları. Otomatik birleştirme yapılmaz — yalnızca gösterim.</p>
            <div class="table-wrap">
            <table class="tehis-table">
                <thead><tr>
                    <th>ID</th><th>İsim</th><th>Type</th><th>Aktif</th>
                    <th class="num">Giriş</th><th class="num">Kullanım</th><th class="num">Net</th><th>Depolar</th>
                </tr></thead>
                <tbody>
                <?php foreach ($nm['benzer'] as $b): ?>
                <tr class="<?= $b['giris'] > 0 ? 'warn-row' : '' ?>">
                    <td><?= (int)$b['id'] ?></td>
                    <td><?= h($b['name']) ?></td>
                    <td><?= h($b['type']) ?></td>
                    <td><?= $b['is_active'] ? '<span class="tehis-badge badge-ok">Aktif</span>' : '<span class="tehis-badge badge-warn">Pasif</span>' ?></td>
                    <td class="num <?= $b['giris'] > 0 ? 'pos' : '' ?>"><?= $b['giris'] > 0 ? number_format($b['giris'],0) : '—' ?></td>
                    <td class="num"><?= $b['kullanim'] > 0 ? number_format($b['kullanim'],0) : '—' ?></td>
                    <td class="num <?= $b['net'] < 0 ? 'neg' : ($b['net'] > 0 ? 'pos' : '') ?>"><?= number_format($b['net'],0) ?></td>
                    <td style="font-size:.75rem"><?= h($b['depolar']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            </div>
        </details>

    </div><!-- .neg-body -->
</details>
<?php endforeach; ?>
<?php endif; ?>
<?php tehis_section_close(); ?>

<style>
.neg-detail { border:1.5px solid #fca5a5;border-radius:8px;margin-bottom:14px;overflow:hidden; }
.neg-summary { display:flex;align-items:center;flex-wrap:wrap;gap:4px;padding:10px 14px;background:#fef2f2;cursor:pointer;user-select:none;list-style:none; }
.neg-summary::-webkit-details-marker { display:none; }
.neg-mat-name { font-weight:700;font-size:.95rem; }
.neg-body { padding:14px;border-top:1px solid #fca5a5;display:flex;flex-direction:column;gap:10px; }
.neg-sub { border:1px solid var(--border);border-radius:6px;overflow:hidden; }
.neg-sub > summary { padding:8px 12px;background:#f8fafc;cursor:pointer;user-select:none;list-style:none;font-size:.85rem; }
.neg-sub > summary::-webkit-details-marker { display:none; }
.neg-sub > div { padding:8px 12px 12px; }
.neg-oneri { border-radius:6px;padding:8px 12px;font-size:.84rem;font-weight:600; }
.neg-oneri-crit { background:#fee2e2;color:#991b1b;border:1px solid #fca5a5; }
.neg-oneri-warn { background:#fffbeb;color:#92400e;border:1px solid #fde68a; }
</style>

<!-- ──────────────────────────────────────────────────────────
     BÖLÜM 9: Negatif Stok Düzeltme Planı
     ────────────────────────────────────────────────────────── -->
<?php
$dp_badge = count($neg_malzemeler) > 0
    ? '<span class="tehis-badge badge-crit">'.count($neg_malzemeler).' malzeme &nbsp;·&nbsp; '.number_format($dp_toplam_eksik,0).' adet eksik</span>'
    : '<span class="tehis-badge badge-ok">Yok</span>';
tehis_section_open('b9', '9 · Negatif Stok Düzeltme Planı', $dp_badge, count($neg_malzemeler) > 0);
?>
<p class="tehis-sub">
    Negatif net stoku olan her malzeme için kategori, önerilen düzeltme miktarı, benzer pozitif tanımlar ve kullanım kaynağı.
    Sadece okuma — otomatik hareket oluşturulmaz.
</p>
<?php if (empty($neg_malzemeler)): ?>
    <p style="color:#16a34a">✓ Negatif stok bulunamadı.</p>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:18px">
    <div class="tehis-kart crit"><div class="tehis-kart-num"><?= count($neg_malzemeler) ?></div><div class="tehis-kart-lbl">Negatif Malzeme</div></div>
    <div class="tehis-kart crit"><div class="tehis-kart-num"><?= number_format($dp_toplam_eksik,0) ?></div><div class="tehis-kart-lbl">Toplam Eksik Adet</div></div>
    <div class="tehis-kart crit"><div class="tehis-kart-num"><?= $dp_kategori_a ?></div><div class="tehis-kart-lbl">Kat A – Eksik Giriş</div></div>
    <div class="tehis-kart warn"><div class="tehis-kart-num"><?= $dp_kategori_b ?></div><div class="tehis-kart-lbl">Kat B – Eşleştirme</div></div>
    <div class="tehis-kart" style="background:#faf5ff;border-color:#c4b5fd;color:#6d28d9"><div class="tehis-kart-num"><?= $dp_kategori_d ?></div><div class="tehis-kart-lbl">Kat D – Manuel Karar</div></div>
</div>

<!-- Özet tablo -->
<div class="table-wrap" style="margin-bottom:22px">
<table class="tehis-table">
    <thead><tr>
        <th>Malzeme</th><th>Type</th><th>ID</th>
        <th class="num">Net Kalan</th><th class="num">Öner. Düzeltme</th>
        <th class="num">Giriş</th><th class="num">Kullanım</th>
        <th class="num">Kull.Hk.</th><th class="num">Giriş Hk.</th>
        <th>Benzer Pozitif</th><th>Kull. Kaynağı</th><th>Kategori</th>
    </tr></thead>
    <tbody>
    <?php foreach ($neg_malzemeler as $nm):
        $tr_cls  = $nm['kategori'] === 'A' ? 'crit-row' : ($nm['kategori'] === 'D' ? 'dp-row-purple' : 'warn-row');
        $bdg_cls = $nm['kategori'] === 'A' ? 'badge-crit' : ($nm['kategori'] === 'D' ? 'badge-purple' : 'badge-warn');
    ?>
    <tr class="<?= $tr_cls ?>">
        <td><strong><?= h($nm['mat_name']) ?></strong></td>
        <td style="font-size:.77rem"><?= h($nm['mat_type']) ?></td>
        <td><?= (int)$nm['mid'] ?></td>
        <td class="num neg"><strong><?= number_format($nm['net'],0) ?></strong></td>
        <td class="num" style="color:#7c3aed;font-weight:700"><?= number_format($nm['eksik_miktar'],0) ?></td>
        <td class="num <?= $nm['giris'] > 0 ? 'pos' : '' ?>"><?= $nm['giris'] > 0 ? number_format($nm['giris'],0) : '—' ?></td>
        <td class="num"><?= number_format($nm['kullanim'],0) ?></td>
        <td class="num"><?= count($nm['kullanim_h']) ?><?= count($nm['hareketler']) >= 500 ? '<sup title="500 limit">+</sup>' : '' ?></td>
        <td class="num <?= empty($nm['giris_h']) ? 'neg' : '' ?>"><?= count($nm['giris_h']) ?: '0' ?></td>
        <td>
            <?php if (!empty($nm['benzer_pozitif'])): ?>
                <?php foreach (array_slice($nm['benzer_pozitif'],0,2) as $b): ?>
                <div style="font-size:.72rem"><?= h($b['name']) ?> <span class="pos">(+<?= number_format($b['net'],0) ?>)</span></div>
                <?php endforeach; ?>
                <?php if (count($nm['benzer_pozitif'])>2): ?><div style="font-size:.7rem;color:var(--muted)">+<?= count($nm['benzer_pozitif'])-2 ?> daha</div><?php endif; ?>
            <?php else: ?><em style="font-size:.77rem;color:var(--muted)">Yok</em><?php endif; ?>
        </td>
        <td>
            <?php foreach ($nm['kaynaklar'] as $k): ?>
                <?php if ($k['tip'] !== 'Bilinmiyor'): ?><span class="tehis-badge badge-info" style="display:inline-block;margin-bottom:2px"><?= h($k['tip']) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </td>
        <td><span class="tehis-badge <?= $bdg_cls ?>"><?= h($nm['kategori']) ?> · <?= h($nm['kategori_metin']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Per-malzeme detay -->
<h4 style="margin-bottom:12px;font-size:.88rem;color:var(--muted);font-weight:600">Malzeme Bazlı Düzeltme Detayı</h4>
<?php foreach ($neg_malzemeler as $nm):
    $dp_border = $nm['kategori'] === 'A' ? '#dc2626' : ($nm['kategori'] === 'D' ? '#7c3aed' : '#d97706');
    $dp_bg     = $nm['kategori'] === 'A' ? '#fef2f2' : ($nm['kategori'] === 'D' ? '#faf5ff' : '#fffbeb');
    $bdg_cls   = $nm['kategori'] === 'A' ? 'badge-crit' : ($nm['kategori'] === 'D' ? 'badge-purple' : 'badge-warn');
?>
<details class="dp-detail" style="border-color:<?= $dp_border ?>">
    <summary class="dp-summary" style="background:<?= $dp_bg ?>">
        <span style="font-weight:800"><?= h($nm['mat_name']) ?></span>
        <span class="neg" style="margin-left:8px;font-weight:800"><?= number_format($nm['net'],0) ?></span>
        <span class="tehis-badge <?= $bdg_cls ?>" style="margin-left:8px"><?= h($nm['kategori']) ?> · <?= h($nm['kategori_metin']) ?></span>
        <span style="margin-left:10px;font-size:.78rem;color:#7c3aed;font-weight:700">Önerilen: <?= number_format($nm['eksik_miktar'],0) ?> adet</span>
        <span style="margin-left:auto;font-size:.73rem;color:var(--muted)">ID <?= (int)$nm['mid'] ?> · <?= h($nm['mat_type']) ?> · <?= h($nm['depolar']) ?></span>
    </summary>
    <div class="dp-body">
        <div class="neg-oneri neg-oneri-<?= $nm['kategori_renk'] ?>" style="margin-bottom:12px">
            <?= $nm['kategori'] === 'A' ? '🔴' : ($nm['kategori'] === 'D' ? '🟣' : '🟡') ?>
            <strong>Kategori <?= h($nm['kategori']) ?> — <?= h($nm['kategori_metin']) ?>:</strong>
            <?= h($nm['kategori_aciklama']) ?>
        </div>
        <div class="dp-grid">
            <div>
                <strong style="font-size:.84rem">Kullanım Kaynağı</strong>
                <table class="tehis-table" style="margin-top:6px">
                    <thead><tr><th>Kaynak</th><th>DB Alanı</th><th class="num">Palet Sayısı</th><th>Örnek Yük.</th></tr></thead>
                    <tbody>
                    <?php foreach ($nm['kaynaklar'] as $k): ?>
                    <tr>
                        <td><?= h($k['tip']) ?></td>
                        <td style="font-size:.72rem;font-family:monospace"><?= h($k['alan']) ?></td>
                        <td class="num"><?= $k['sayi'] > 0 ? $k['sayi'] : '—' ?></td>
                        <td><?php if ($k['ornek_id']): ?><a href="record_view.php?id=<?= (int)$k['ornek_id'] ?>" target="_blank">#<?= (int)$k['ornek_id'] ?></a><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:.74rem;color:var(--muted);margin-top:4px"><?= h($nm['kaynaklar'][0]['aciklama'] ?? '') ?></p>
            </div>
            <div>
                <strong style="font-size:.84rem">Benzer Pozitif Tanımlar</strong>
                <?php if (empty($nm['benzer_pozitif'])): ?>
                <p style="font-size:.81rem;color:var(--muted);margin-top:6px">Benzer isimde pozitif stoklu tanım bulunamadı.</p>
                <?php else: ?>
                <table class="tehis-table" style="margin-top:6px">
                    <thead><tr><th>ID</th><th>İsim</th><th>Type</th><th class="num">Net</th><th>Depo</th></tr></thead>
                    <tbody>
                    <?php foreach ($nm['benzer_pozitif'] as $b): ?>
                    <tr class="warn-row">
                        <td><?= (int)$b['id'] ?></td>
                        <td><?= h($b['name']) ?></td>
                        <td style="font-size:.74rem"><?= h($b['type']) ?></td>
                        <td class="num pos"><?= number_format($b['net'],0) ?></td>
                        <td style="font-size:.72rem"><?= h($b['depolar']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</details>
<?php endforeach; ?>

<?php endif; ?>
<?php tehis_section_close(); ?>

<style>
.dp-detail { border:1.5px solid;border-radius:8px;margin-bottom:12px;overflow:hidden; }
.dp-summary { display:flex;align-items:center;flex-wrap:wrap;gap:6px;padding:10px 14px;cursor:pointer;user-select:none;list-style:none; }
.dp-summary::-webkit-details-marker { display:none; }
.dp-body { padding:14px;border-top:1px solid rgba(0,0,0,.08); }
.dp-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.badge-purple { background:#f3e8ff;color:#6d28d9; }
.dp-row-purple td { background:#faf5ff !important; }
.neg-oneri-purple { background:#faf5ff;color:#6d28d9;border:1px solid #c4b5fd; }
@media(max-width:767px){ .dp-grid { grid-template-columns:1fr; } }
</style>

<!-- Altbilgi -->
<div style="margin-top:20px;padding:10px 14px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;font-size:.78rem;color:var(--muted)">
    <strong>Teşhis raporu:</strong> <?= date('d.m.Y H:i:s') ?> &nbsp;·&nbsp;
    Sadece SELECT sorguları kullanıldı. Hiçbir veri değiştirilmedi. &nbsp;·&nbsp;
    <a href="audit.php">← Sistem Audit</a>
</div>

<?php render_footer(); ?>
