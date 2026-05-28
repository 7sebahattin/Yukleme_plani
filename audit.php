<?php
// =========================================================
// audit.php — Sistem veri kalitesi / orphan / duplicate audit
// Geçici — işiniz bitince git rm yapın.
// Web erişimine açık, otomatik silme/merge yapmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// Güvenlik köprüsü: üretim sunucusunda config/helpers.php eski sürümdeyse
// (Sprint 4 öncesi) normalize_text_v2 tanımsız kalabilir.
// db.php → helpers.php zinciri güncel sürümü yüklediyse bu blok çalışmaz;
// yüklendiyse fonksiyon burada tam doğru implementasyonla tanımlanır (v1 alias değil).
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

$pdo = db();

function audit_tbl(string $t): bool {
    try { db()->query("SELECT 1 FROM `$t` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

// ── NMQ: Normalize Migration Queue yardımcıları ─────────────────
// UPDATE/DELETE çalıştırmaz — sadece review + queue yönetimi.

function nmq_uc(string $s): array {
    $f = [];
    if (preg_match('/\x{0307}/u',           $s)) $f[] = 'U+0307';
    if (preg_match('/\x{200B}/u',           $s)) $f[] = 'ZWSP';
    if (preg_match('/[\x{200C}\x{200D}]/u', $s)) $f[] = 'ZWJ';
    if (preg_match('/\x{FEFF}/u',           $s)) $f[] = 'BOM';
    return $f;
}

function nmq_fk(PDO $p, int $id, string $type): array {
    static $mat = ['sapka','kosebent','serit','casus','kasa_etiketi','minti',
                   'kenar_kartonu','taban_kagidi','sale','viyol','kose_karton',
                   'kraft_kagit','file','diger'];
    $r = ['lp_kasa'=>0,'lp_palet'=>0,'pm'=>0,'msm'=>0];
    try {
        if ($type === 'kasa_cinsi' && audit_tbl('loading_pallets')) {
            $st = $p->prepare("SELECT COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id=?");
            $st->execute([$id]); $r['lp_kasa'] = (int)$st->fetchColumn();
        }
        if ($type === 'palet_tipi' && audit_tbl('loading_pallets')) {
            $st = $p->prepare("SELECT COUNT(*) FROM loading_pallets WHERE palet_tipi_id=?");
            $st->execute([$id]); $r['lp_palet'] = (int)$st->fetchColumn();
        }
        if (in_array($type, $mat) && audit_tbl('pallet_materials')) {
            $st = $p->prepare("SELECT COUNT(*) FROM pallet_materials WHERE material_id=?");
            $st->execute([$id]); $r['pm'] = (int)$st->fetchColumn();
        }
        if (audit_tbl('material_stock_movements')) {
            $st = $p->prepare("SELECT COUNT(*) FROM material_stock_movements WHERE material_id=?");
            $st->execute([$id]); $r['msm'] = (int)$st->fetchColumn();
        }
    } catch (PDOException $e) {}
    $r['total'] = $r['lp_kasa'] + $r['lp_palet'] + $r['pm'] + $r['msm'];
    return $r;
}

function nmq_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `normalize_migration_queue` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `target_id`     INT NOT NULL,
        `mat_type`      VARCHAR(40)  NOT NULL DEFAULT '',
        `current_value` VARCHAR(500) NOT NULL DEFAULT '',
        `v2_value`      VARCHAR(500) NOT NULL DEFAULT '',
        `will_change`   TINYINT(1)   NOT NULL DEFAULT 0,
        `is_merge`      TINYINT(1)   NOT NULL DEFAULT 0,
        `is_survivor`   TINYINT(1)   NOT NULL DEFAULT 0,
        `surviving_id`  INT          NULL DEFAULT NULL,
        `dup_id`        INT          NULL DEFAULT NULL,
        `fk_lp_kasa`    INT          NOT NULL DEFAULT 0,
        `fk_lp_palet`   INT          NOT NULL DEFAULT 0,
        `fk_pm`         INT          NOT NULL DEFAULT 0,
        `fk_msm`        INT          NOT NULL DEFAULT 0,
        `fk_total`      INT          NOT NULL DEFAULT 0,
        `u0307`         TINYINT(1)   NOT NULL DEFAULT 0,
        `unicode_flags` VARCHAR(200) NOT NULL DEFAULT '',
        `risk_level`    ENUM('DÜŞÜK','ORTA','YÜKSEK') NOT NULL DEFAULT 'DÜŞÜK',
        `status`        ENUM('pending','approved','excluded') NOT NULL DEFAULT 'pending',
        `reviewed_at`   TIMESTAMP NULL DEFAULT NULL,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_target` (`target_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function nmq_run_refresh(PDO $pdo): int {
    if (!audit_tbl('material_definitions')) return 0;
    nmq_ensure_table($pdo);

    $all = $pdo->query("SELECT id, type, name FROM material_definitions ORDER BY id")->fetchAll();

    // v2 normalize + duplicate gruplarını bul
    $groups = [];
    foreach ($all as $r) {
        $v2 = normalize_text_v2($r['name']);
        $k  = $r['type'] . '||' . mb_strtolower(trim($v2), 'UTF-8');
        $groups[$k]['type']      = $r['type'];
        $groups[$k]['v2']        = $v2;
        $groups[$k]['members'][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    }

    // Merge gruplarında surviving_id seç (en fazla FK → surviving)
    $merge_map = [];
    foreach ($groups as $g) {
        if (count($g['members']) < 2) continue;
        $mwf = [];
        foreach ($g['members'] as $m) {
            $m['fk'] = nmq_fk($pdo, $m['id'], $g['type']);
            $mwf[]   = $m;
        }
        usort($mwf, fn($a, $b) =>
            $b['fk']['total'] !== $a['fk']['total']
                ? $b['fk']['total'] - $a['fk']['total']
                : $a['id'] - $b['id']
        );
        $surv_id = $mwf[0]['id'];
        foreach ($mwf as $pos => $m) {
            $merge_map[$m['id']] = [
                'is_merge'    => 1,
                'is_survivor' => ($pos === 0) ? 1 : 0,
                'surviving_id'=> $surv_id,
                'dup_id'      => ($pos === 0) ? null : $m['id'],
                'fk'          => $m['fk'],
            ];
        }
    }

    // status/reviewed_at korunur — analiz verileri güncellenir
    $ins = $pdo->prepare("
        INSERT INTO normalize_migration_queue
            (target_id, mat_type, current_value, v2_value, will_change,
             is_merge, is_survivor, surviving_id, dup_id,
             fk_lp_kasa, fk_lp_palet, fk_pm, fk_msm, fk_total,
             u0307, unicode_flags, risk_level, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',NOW())
        ON DUPLICATE KEY UPDATE
            mat_type=VALUES(mat_type), current_value=VALUES(current_value),
            v2_value=VALUES(v2_value), will_change=VALUES(will_change),
            is_merge=VALUES(is_merge), is_survivor=VALUES(is_survivor),
            surviving_id=VALUES(surviving_id), dup_id=VALUES(dup_id),
            fk_lp_kasa=VALUES(fk_lp_kasa), fk_lp_palet=VALUES(fk_lp_palet),
            fk_pm=VALUES(fk_pm), fk_msm=VALUES(fk_msm), fk_total=VALUES(fk_total),
            u0307=VALUES(u0307), unicode_flags=VALUES(unicode_flags),
            risk_level=VALUES(risk_level)
    ");

    $n = 0;
    foreach ($all as $r) {
        $id  = (int)$r['id'];
        $v2  = normalize_text_v2($r['name']);
        $mm  = $merge_map[$id] ?? [
            'is_merge'=>0, 'is_survivor'=>0, 'surviving_id'=>null, 'dup_id'=>null,
            'fk' => nmq_fk($pdo, $id, $r['type']),
        ];
        $will_change = ($v2 !== $r['name']) ? 1 : 0;
        // Yalnızca değişecek veya merge olan (ama adı değişmeyen survivor değil) kayıtları al
        if (!$will_change && !$mm['is_merge']) continue;
        if ($mm['is_survivor'] && !$will_change) continue;

        $uf    = array_values(array_unique(array_merge(nmq_uc($r['name']), nmq_uc($v2))));
        $u0307 = (int)in_array('U+0307', $uf);
        $fk    = $mm['fk'];
        $risk  = (!$mm['is_survivor'] && $mm['is_merge'] && $fk['total'] > 0)
                    ? 'YÜKSEK' : ($mm['is_merge'] ? 'ORTA' : 'DÜŞÜK');

        $ins->execute([
            $id, $r['type'], $r['name'], $v2, $will_change,
            $mm['is_merge'], $mm['is_survivor'], $mm['surviving_id'], $mm['dup_id'],
            $fk['lp_kasa'], $fk['lp_palet'], $fk['pm'], $fk['msm'], $fk['total'],
            $u0307, implode(', ', $uf), $risk,
        ]);
        $n++;
    }

    // Artık material_definitions'ta olmayan kayıtları temizle
    if (!empty($all)) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $all));
        $pdo->exec("DELETE FROM normalize_migration_queue WHERE target_id NOT IN ($ids)");
    }
    return $n;
}

// ── NMQ POST İşlemleri ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nmq_act = (string)($_POST['action'] ?? '');

    if ($nmq_act === 'nmq_set_status') {
        // AJAX endpoint — JSON döner
        header('Content-Type: application/json; charset=utf-8');
        try {
            csrf_check($_POST['csrf'] ?? null);
            $qid    = (int)($_POST['queue_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if ($qid <= 0 || !in_array($status, ['pending','approved','excluded'], true)) {
                throw new RuntimeException('Geçersiz parametre.');
            }
            nmq_ensure_table($pdo);
            $pdo->prepare(
                "UPDATE normalize_migration_queue SET status=?, reviewed_at=NOW() WHERE id=?"
            )->execute([$status, $qid]);
            echo json_encode(['ok' => true, 'id' => $qid, 'status' => $status]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($nmq_act === 'nmq_refresh') {
        csrf_check($_POST['csrf'] ?? null);
        try {
            $n = nmq_run_refresh($pdo);
            set_flash('success', "Normalize analizi tamamlandı — $n kayıt inceleme kuyruğuna alındı.");
        } catch (Throwable $e) {
            set_flash('error', 'Analiz hatası: ' . $e->getMessage());
        }
        header('Location: audit.php#nmq-section');
        exit;
    }

    if ($nmq_act === 'nmq_bulk_approve') {
        csrf_check($_POST['csrf'] ?? null);
        try {
            nmq_ensure_table($pdo);
            $scope = (($_POST['scope'] ?? '') === 'all') ? 'all' : 'safe';
            $cond  = ($scope === 'all') ? '' : 'AND fk_total = 0';
            $pdo->exec(
                "UPDATE normalize_migration_queue SET status='approved', reviewed_at=NOW()
                 WHERE status='pending' $cond"
            );
            set_flash('success', 'Toplu onay tamamlandı.');
        } catch (Throwable $e) {
            set_flash('error', $e->getMessage());
        }
        header('Location: audit.php#nmq-section');
        exit;
    }
}

// ── NMQ verilerini yükle ─────────────────────────────────────
$nmq_rows           = [];
$nmq_exists         = audit_tbl('normalize_migration_queue');
$nmq_types          = [];
$nmq_counts         = ['pending' => 0, 'approved' => 0, 'excluded' => 0, 'total' => 0];
$nmq_stat           = ['yuksek_risk'=>0,'merge'=>0,'u307'=>0,'riskli_dup'=>0];
$nmq_pending_rows   = [];
$nmq_high_risk_rows = [];

if ($nmq_exists) {
    $nmq_rows = $pdo->query("
        SELECT q.id, q.target_id, q.mat_type, q.current_value, q.v2_value, q.will_change,
               q.is_merge, q.is_survivor, q.surviving_id, q.dup_id,
               q.fk_lp_kasa, q.fk_lp_palet, q.fk_pm, q.fk_msm, q.fk_total,
               q.u0307, q.unicode_flags, q.risk_level, q.status, q.reviewed_at,
               sv.name AS surviving_name
        FROM normalize_migration_queue q
        LEFT JOIN material_definitions sv ON sv.id = q.surviving_id
        ORDER BY FIELD(q.risk_level,'YÜKSEK','ORTA','DÜŞÜK'), q.mat_type, q.target_id
    ")->fetchAll();
    foreach ($nmq_rows as $r) {
        $nmq_types[$r['mat_type']] = true;
        $nmq_counts[$r['status']]++;
        $nmq_counts['total']++;
    }
    ksort($nmq_types);
    foreach ($nmq_rows as $r) {
        if ($r['risk_level'] === 'YÜKSEK')                                $nmq_stat['yuksek_risk']++;
        if ($r['is_merge'])                                                $nmq_stat['merge']++;
        if ($r['u0307'])                                                   $nmq_stat['u307']++;
        if ($r['is_merge'] && !$r['is_survivor'] && $r['fk_total'] > 0)  $nmq_stat['riskli_dup']++;
        if ($r['status'] === 'pending')                                    $nmq_pending_rows[]   = $r;
        if ($r['risk_level']==='YÜKSEK' && $r['is_merge'] && !$r['is_survivor'])
                                                                           $nmq_high_risk_rows[] = $r;
    }
}

$has_msm = audit_tbl('material_stock_movements');
$has_md  = audit_tbl('material_definitions');
$has_lr  = audit_tbl('loading_records');

// ── Sorgu sonuçları ──────────────────────────────────────────
$results = [];

// 1. Orphan movements
if ($has_msm && $has_lr) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements m
        WHERE m.source_type='loading' AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id=m.source_id)"
    )->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_name,
               m.quantity, m.unit, m.depo, m.source_id, m.created_at
        FROM material_stock_movements m
        WHERE m.source_type='loading' AND m.source_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM loading_records r WHERE r.id=m.source_id)
        ORDER BY m.id DESC LIMIT 20")->fetchAll() : [];
    $results['orphan'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','Malzeme','Miktar','Birim','Depo','source_id','Oluşturma'],
        'keys' => ['id','movement_date','movement_type','material_name','quantity','unit','depo','source_id','created_at'],
        'title' => 'Yetim Stok Hareketleri',
        'risk'  => 'loading_records silinmiş ama material_stock_movements kalmış. Malzeme stok hesabı yüksek görünür.',
        'fix'   => "DELETE FROM material_stock_movements\n  WHERE source_type='loading'\n    AND source_id NOT IN (SELECT id FROM loading_records);",
    ];
} else {
    $results['orphan'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Yetim Stok Hareketleri', 'risk' => '', 'fix' => ''];
}

// 2. Geçersiz material_id
if ($has_msm && $has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements
        WHERE material_id IS NOT NULL
          AND material_id NOT IN (SELECT id FROM material_definitions)"
    )->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT m.id, m.movement_date, m.movement_type, m.material_id,
               m.material_name, m.quantity, m.unit, m.source_type, m.source_id
        FROM material_stock_movements m
        WHERE m.material_id IS NOT NULL
          AND m.material_id NOT IN (SELECT id FROM material_definitions)
        ORDER BY m.id DESC LIMIT 20")->fetchAll() : [];
    $results['invalid_mat'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','material_id','Malzeme','Miktar','Birim','source_type','source_id'],
        'keys' => ['id','movement_date','movement_type','material_id','material_name','quantity','unit','source_type','source_id'],
        'title' => 'Geçersiz material_id',
        'risk'  => 'material_definitions kaydı silinmiş ama harekette referans kalmış. Malzeme bazında stok raporları hatalı.',
        'fix'   => "UPDATE material_stock_movements\n  SET material_id=NULL\n  WHERE material_id NOT IN (SELECT id FROM material_definitions);",
    ];
} else {
    $results['invalid_mat'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Geçersiz material_id', 'risk' => '', 'fix' => ''];
}

// 3. Negatif quantity
if ($has_msm) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM material_stock_movements WHERE quantity < 0")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT id, movement_date, movement_type, material_name,
               quantity, unit, depo, source_type, source_id
        FROM material_stock_movements WHERE quantity < 0
        ORDER BY quantity ASC LIMIT 20")->fetchAll() : [];
    $results['negative'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['ID','Tarih','Tip','Malzeme','Miktar','Birim','Depo','source_type','source_id'],
        'keys' => ['id','movement_date','movement_type','material_name','quantity','unit','depo','source_type','source_id'],
        'title' => 'Negatif Miktar',
        'risk'  => 'Stok hareketi negatif miktar içeriyor. Stok toplamı yanlış hesaplanabilir.',
        'fix'   => "İlgili kayıtları manuel incele. Geçersizse sil, düzeltmeyse\nmovement_type='duzeltme' ile pozitif kayıt gir.",
    ];
} else {
    $results['negative'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Negatif Miktar', 'risk' => '', 'fix' => ''];
}

// 4. Birebir duplicate material_definitions
if ($has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT 1 FROM material_definitions GROUP BY type, name HAVING COUNT(*) > 1
    ) x")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT type, name, COUNT(*) AS sayi,
               GROUP_CONCAT(id ORDER BY id SEPARATOR ', ')          AS idler,
               GROUP_CONCAT(is_active ORDER BY id SEPARATOR ', ')   AS aktifler
        FROM material_definitions
        GROUP BY type, name HAVING sayi > 1
        ORDER BY type, name LIMIT 20")->fetchAll() : [];
    $results['dup_exact'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','İsim','Tekrar','ID\'ler','is_active listesi'],
        'keys' => ['type','name','sayi','idler','aktifler'],
        'title' => 'Birebir Duplicate Tanımlar (type+name)',
        'risk'  => 'Aynı (type, name) çifti birden fazla kayıtta. UNIQUE constraint eklenemez; form listelerinde tekrar görünür.',
        'fix'   => "Küçük ID'yi koru, büyük ID'leri sil (FK kontrolü yap!):\nDELETE FROM material_definitions WHERE id IN (...büyük_idler...);",
    ];
} else {
    $results['dup_exact'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Birebir Duplicate Tanımlar', 'risk' => '', 'fix' => ''];
}

// 5. Normalize sonrası duplicate
if ($has_md) {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM (
        SELECT 1 FROM material_definitions
        GROUP BY type, LOWER(TRIM(name)) HAVING COUNT(*) > 1
    ) x")->fetchColumn();
    $rows = $cnt > 0 ? $pdo->query("
        SELECT type,
               LOWER(TRIM(name))                                         AS norm,
               COUNT(*)                                                  AS sayi,
               GROUP_CONCAT(id ORDER BY id SEPARATOR ', ')               AS idler,
               GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ')            AS isimler
        FROM material_definitions
        GROUP BY type, LOWER(TRIM(name)) HAVING sayi > 1
        ORDER BY type, norm LIMIT 20")->fetchAll() : [];
    $results['dup_norm'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','Normalize İsim','Tekrar','ID\'ler','Orijinal İsimler'],
        'keys' => ['type','norm','sayi','idler','isimler'],
        'title' => 'Normalize Sonrası Duplicate (trim+lowercase)',
        'risk'  => 'Trim/büyük-küçük harf farkı olan ama aynı anlama gelen tanımlar. Form seçimlerinde karışıklık yaratır.',
        'fix'   => "normalize_text() ile isimleri standartlaştır, fazla olanı sil.\nensure_definition() zaten case-insensitive kontrol yapıyor.",
    ];
} else {
    $results['dup_norm'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Normalize Sonrası Duplicate', 'risk' => '', 'fix' => ''];
}

// 6. Tip bazında dağılım
if ($has_md) {
    $rows = $pdo->query("
        SELECT type,
               COUNT(*)                                          AS toplam,
               COUNT(DISTINCT LOWER(TRIM(name)))                 AS uniq,
               COUNT(*) - COUNT(DISTINCT LOWER(TRIM(name)))      AS fark,
               SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END)      AS pasif
        FROM material_definitions
        GROUP BY type ORDER BY fark DESC, type")->fetchAll();
    $cnt = count($rows);
    $results['type_dist'] = [
        'count' => $cnt, 'rows' => $rows,
        'cols' => ['Tip','Toplam','Unique (norm)','Dup Farkı','Pasif Sayı'],
        'keys' => ['type','toplam','uniq','fark','pasif'],
        'title' => 'Tanım Tipleri Dağılımı',
        'risk'  => 'Fark > 0 olan tipler normalize duplicate içeriyor. UNIQUE eklemek için bu farkların sıfırlanması gerekir.',
        'fix'   => "Fark > 0 tipler için yukarıdaki duplicate raporlarına bakın.\nFark = 0 olan tipler için UNIQUE(type,name) güvenli eklenebilir.",
    ];
} else {
    $results['type_dist'] = ['count' => null, 'rows' => [], 'cols' => [], 'keys' => [],
        'title' => 'Tanım Tipleri Dağılımı', 'risk' => '', 'fix' => ''];
}

// ── CSV Export (tüm header'lardan önce) ─────────────────────
$csv_key = trim($_GET['csv'] ?? '');
if ($csv_key !== '') {
    $export_keys = ($csv_key === 'all')
        ? array_keys($results)
        : (isset($results[$csv_key]) ? [$csv_key] : []);

    if (!empty($export_keys)) {
        $fname = 'audit_' . ($csv_key === 'all' ? 'tum' : $csv_key) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF"; // BOM — Excel Türkçe charset
        $out = fopen('php://output', 'w');

        foreach ($export_keys as $k) {
            $sec = $results[$k];
            if (empty($sec['rows'])) continue;
            // Bölüm başlığı
            fputcsv($out, ['=== ' . $sec['title'] . ' ==='], ';');
            fputcsv($out, $sec['cols'], ';');
            foreach ($sec['rows'] as $row) {
                $line = [];
                foreach ($sec['keys'] as $fk) $line[] = $row[$fk] ?? '';
                fputcsv($out, $line, ';');
            }
            fputcsv($out, [], ';'); // boş satır
        }
        fclose($out);
        exit;
    }
}

// ── HTML ─────────────────────────────────────────────────────
render_header('Sistem Audit');

$issue_count = array_sum(array_map(function($s) {
    if ($s['count'] === null) return 0;
    // type_dist: fark toplamı
    if (isset($s['rows'][0]['fark'])) {
        return (int)array_sum(array_column($s['rows'], 'fark'));
    }
    return (int)$s['count'];
}, $results));
?>
<div class="page-head">
    <h1>🔍 Sistem Audit</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:4px">
        Otomatik silme / merge yapılmaz — sadece raporlama.
        &nbsp;·&nbsp; Son çalışma: <strong><?= date('d.m.Y H:i:s') ?></strong>
    </p>
</div>

<?php if ($issue_count > 0): ?>
<div class="flash flash-error">⚠ Toplam <strong><?= $issue_count ?></strong> sorunlu kayıt tespit edildi.</div>
<?php else: ?>
<div class="flash flash-success">✓ Tüm kontrollerde sorun bulunamadı.</div>
<?php endif; ?>

<div style="text-align:right;margin-bottom:12px">
    <a href="?csv=all" class="btn btn-ghost btn-sm">⬇ Tüm Raporu CSV İndir</a>
</div>

<style>
.au-sec{border:1px solid var(--border);border-radius:10px;margin-bottom:12px;overflow:hidden}
.au-sec summary{cursor:pointer;padding:11px 16px;background:var(--card-bg,#fff);display:flex;align-items:center;gap:10px;list-style:none;user-select:none;font-weight:600}
.au-sec summary::-webkit-details-marker{display:none}
.au-sec[open]>summary{border-bottom:1px solid var(--border)}
.au-badge{font-size:.75rem;padding:2px 10px;border-radius:20px;font-weight:700;margin-left:auto;white-space:nowrap}
.b-ok{background:#d1fae5;color:#065f46}.b-warn{background:#fef3c7;color:#92400e}
.b-err{background:#fee2e2;color:#991b1b}.b-info{background:#dbeafe;color:#1e40af}
.au-body{padding:14px 16px}
.au-risk{background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 12px;border-radius:0 6px 6px 0;font-size:.82rem;margin-bottom:8px}
.au-fix{background:#f0f9ff;border-left:3px solid #0284c7;padding:8px 12px;border-radius:0 6px 6px 0;font-size:.82rem;margin-bottom:10px}
.au-fix code{display:block;margin-top:5px;font-size:.78rem;color:#0369a1;white-space:pre-wrap;word-break:break-all}
.au-tbl-wrap{overflow-x:auto;margin-top:8px}
.au-tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.au-tbl th{background:var(--thead-bg,#f1f5f9);padding:6px 10px;text-align:left;white-space:nowrap;border-bottom:2px solid var(--border)}
.au-tbl td{padding:5px 10px;border-bottom:1px solid var(--border);vertical-align:top;word-break:break-word;max-width:260px}
.au-tbl tr:last-child td{border-bottom:none}
.au-tbl tr:hover td{background:var(--hover-bg,#f8fafc)}
.au-empty{color:var(--text-muted);font-size:.85rem;padding:6px 0}
.au-meta{font-size:.8rem;color:var(--text-muted)}
</style>

<?php
$section_order = ['type_dist','dup_exact','dup_norm','orphan','invalid_mat','negative'];
foreach ($section_order as $key):
    if (!isset($results[$key])) continue;
    $sec = $results[$key];
    $cnt = $sec['count'];

    // Badge
    if ($cnt === null) {
        $badge = 'b-info'; $badge_txt = 'Tablo yok';
    } elseif ($key === 'type_dist') {
        $diff = (int)array_sum(array_column($sec['rows'], 'fark'));
        $badge = $diff > 0 ? 'b-warn' : 'b-ok';
        $badge_txt = $cnt . ' tip' . ($diff > 0 ? " · $diff dup" : ' · temiz');
    } elseif ($cnt === 0) {
        $badge = 'b-ok'; $badge_txt = '✓ Temiz';
    } else {
        $badge = ($cnt >= 5) ? 'b-err' : 'b-warn';
        $badge_txt = $cnt . ' kayıt';
    }

    // Auto-open if has issues (except type_dist and always-open is distracting)
    $auto_open = ($cnt !== null && $cnt > 0 && $key !== 'type_dist');
?>
<details class="au-sec" <?= $auto_open ? 'open' : '' ?>>
    <summary>
        <?= h($sec['title']) ?>
        <span class="au-badge <?= $badge ?>"><?= h($badge_txt) ?></span>
    </summary>
    <div class="au-body">
    <?php if ($cnt === null): ?>
        <p class="au-empty">Tablo mevcut değil.</p>
    <?php else: ?>
        <?php if ($sec['risk'] !== ''): ?>
        <div class="au-risk"><strong>⚠ Risk:</strong> <?= h($sec['risk']) ?></div>
        <?php endif; ?>
        <?php if ($sec['fix'] !== ''): ?>
        <div class="au-fix">
            <strong>💡 Önerilen işlem:</strong>
            <code><?= h($sec['fix']) ?></code>
        </div>
        <?php endif; ?>

        <?php if (count($sec['rows']) > 0): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
            <span class="au-meta">
                <?= $cnt > 20
                    ? 'İlk 20 kayıt (toplam: <strong>' . $cnt . '</strong>)'
                    : '<strong>' . $cnt . '</strong> kayıt' ?>
            </span>
            <a href="?csv=<?= urlencode($key) ?>" class="btn btn-sm btn-ghost" style="font-size:.78rem">⬇ CSV</a>
        </div>
        <div class="au-tbl-wrap">
            <table class="au-tbl">
                <thead><tr>
                    <?php foreach ($sec['cols'] as $col): ?>
                    <th><?= h($col) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($sec['rows'] as $row): ?>
                    <tr>
                        <?php foreach ($sec['keys'] as $k): ?>
                        <td><?= h((string)($row[$k] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="au-empty">✓ Bu kontrolde sorun bulunamadı.</p>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>

<?php
// ── Normalize Simülasyonu ──────────────────────────────────
if ($has_md):
    $norm_rows = $pdo->query("
        SELECT id, type, name FROM material_definitions ORDER BY type, name LIMIT 300
    ")->fetchAll();

    // Unicode risk detection helper
    function au_unicode_risk(string $s): array {
        $flags = [];
        if (preg_match('/\x{0307}/u', $s))       $flags[] = 'U+0307 birleştirici nokta';
        if (preg_match('/\x{200B}/u', $s))        $flags[] = 'sıfır genişlikli boşluk';
        if (preg_match('/\x{200C}|\x{200D}/u', $s)) $flags[] = 'ZWNJ/ZWJ';
        if (preg_match('/\x{FEFF}/u', $s))        $flags[] = 'BOM';
        if (preg_match('/[^\x00-\x7F\xC0-\xFF\x{0100}-\x{024F}\x{011E}\x{011F}\x{0130}\x{0131}\x{015E}\x{015F}]/u', $s))
            $flags[] = 'olağandışı unicode';
        return $flags;
    }

    $sim_changed = 0; $sim_risk = 0;
    $sim_rows = [];
    foreach ($norm_rows as $r) {
        $v1 = normalize_text($r['name']);
        $v2 = normalize_text_v2($r['name']);
        $risk = au_unicode_risk($r['name']);
        $risk_v1 = au_unicode_risk($v1);
        $changed_v1 = ($v1 !== $r['name']);
        $changed_v2 = ($v2 !== $r['name']);
        $diff_v1_v2 = ($v1 !== $v2);
        $has_risk = !empty($risk) || !empty($risk_v1);
        if ($diff_v1_v2 || $has_risk) {
            $sim_rows[] = [
                'id'       => $r['id'],
                'type'     => $r['type'],
                'mevcut'   => $r['name'],
                'v1'       => $v1,
                'v2'       => $v2,
                'risk'     => implode(', ', array_merge($risk, $risk_v1)),
                'changed'  => $diff_v1_v2 || $changed_v1 || $changed_v2,
            ];
            if ($diff_v1_v2) $sim_changed++;
            if ($has_risk) $sim_risk++;
        }
    }
    $sim_badge = ($sim_changed > 0 || $sim_risk > 0) ? 'b-warn' : 'b-ok';
    $sim_badge_txt = ($sim_changed > 0 || $sim_risk > 0)
        ? $sim_changed . ' farklı, ' . $sim_risk . ' riskli'
        : '✓ Temiz';
?>
<details class="au-sec" <?= ($sim_changed > 0 || $sim_risk > 0) ? 'open' : '' ?>>
    <summary>
        Normalize Simülasyonu (v1 vs v2)
        <span class="au-badge <?= $sim_badge ?>"><?= h($sim_badge_txt) ?></span>
    </summary>
    <div class="au-body">
        <div class="au-risk"><strong>ℹ Bilgi:</strong>
            <code>normalize_text()</code> (v1) — MB_CASE_TITLE kullanır; Türkçe büyük harf İ için U+0307 combining dot üretebilir.<br>
            <code>normalize_text_v2()</code> (v2) — Türkçe i/ı/İ/I kurallarını doğru işler, combining char üretmez.<br>
            Bu tablo yalnızca v1 ≠ v2 veya unicode riski olan kayıtları gösterir.
        </div>
        <?php if (empty($sim_rows)): ?>
        <p class="au-empty">✓ Mevcut kayıtlarda v1/v2 farkı veya unicode riski bulunamadı.</p>
        <?php else: ?>
        <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:8px">
            <?= count($sim_rows) ?> kayıt gösteriliyor
            (<?= count($norm_rows) ?> toplam içinden, yalnızca farklı/riskli olanlar)
        </div>
        <div class="au-tbl-wrap">
        <table class="au-tbl">
            <thead><tr>
                <th>ID</th><th>Tür</th><th>Mevcut</th>
                <th>v1 (mevcut normalize)</th><th>v2 (yeni normalize)</th><th>Unicode Riski</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sim_rows as $sr): ?>
            <tr>
                <td><?= (int)$sr['id'] ?></td>
                <td><?= h($sr['type']) ?></td>
                <td><?= h($sr['mevcut']) ?></td>
                <td style="<?= $sr['v1'] !== $sr['mevcut'] ? 'background:#fef3c7' : '' ?>">
                    <?= h($sr['v1']) ?>
                </td>
                <td style="<?= $sr['v2'] !== $sr['v1'] ? 'background:#d1fae5' : '' ?>">
                    <?= h($sr['v2']) ?>
                </td>
                <td style="color:#b91c1c;font-size:.75rem"><?= h($sr['risk']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<?php
// ── NMQ: Normalize Migration Review bölümü ───────────────────
$nmq_pending  = $nmq_counts['pending'];
$nmq_approved = $nmq_counts['approved'];
$nmq_excluded = $nmq_counts['excluded'];
$nmq_total    = $nmq_counts['total'];
$nmq_badge_cls = $nmq_pending > 0 ? 'b-warn' : ($nmq_total > 0 ? 'b-ok' : 'b-info');
$nmq_badge_txt = $nmq_total === 0
    ? 'Analiz yapılmadı'
    : ($nmq_pending > 0 ? "$nmq_pending bekliyor" : '✓ Tümü incelendi');
?>
<style>
.nmq-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}
.nmq-filters{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:8px 10px;
             background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;margin-bottom:10px}
.nmq-filters label{display:flex;align-items:center;gap:4px;font-size:.8rem;cursor:pointer;white-space:nowrap}
.nmq-filters select,.nmq-filters input[type=text]{font-size:.8rem;padding:2px 6px;border:1px solid #cbd5e1;border-radius:4px}
.nmq-counter{font-size:.83rem;color:#64748b;margin-bottom:10px}
.nmq-counter strong{color:#1e293b}
.nmq-tbl td{vertical-align:middle}
.nmq-role{font-size:.73rem;font-weight:600;padding:2px 8px;border-radius:10px;white-space:nowrap}
.role-dup{background:#fee2e2;color:#991b1b}
.role-upd{background:#dbeafe;color:#1e40af}
.nmq-fk{font-size:.71rem;display:flex;flex-wrap:wrap;gap:3px}
.nmq-fk-chip{padding:1px 7px;background:#fef3c7;color:#92400e;border-radius:8px;white-space:nowrap}
.nmq-status{font-size:.73rem;font-weight:700;padding:2px 8px;border-radius:10px;white-space:nowrap}
.st-pending{background:#fef9c3;color:#854d0e}
.st-approved{background:#d1fae5;color:#065f46}
.st-excluded{background:#f1f5f9;color:#64748b}
.nmq-btns{display:flex;gap:4px;flex-wrap:nowrap}
.nmq-btn{font-size:.74rem;padding:3px 9px;border-radius:5px;border:1px solid;cursor:pointer;
          font-weight:600;background:#fff;transition:opacity .15s}
.nmq-btn:disabled{opacity:.4;cursor:default}
.nmq-btn-ok{border-color:#10b981;color:#065f46}
.nmq-btn-ok:hover:not(:disabled){background:#d1fae5}
.nmq-btn-ex{border-color:#94a3b8;color:#475569}
.nmq-btn-ex:hover:not(:disabled){background:#f1f5f9}
.nmq-btn-rst{border-color:#e2e8f0;color:#94a3b8;font-size:.68rem}
.nmq-btn-rst:hover:not(:disabled){background:#f8fafc}
tr[data-status=approved]{background:#f0fdf4}
tr[data-status=excluded]{background:#f8fafc;opacity:.65}
.nmq-hidden{display:none}
.u307-flag{font-size:.68rem;background:#fee2e2;color:#991b1b;border-radius:4px;
            padding:1px 5px;margin-left:4px;font-family:monospace}
/* ── Queue durum kartları ── */
.nmq-stat-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.nmq-sc{display:flex;flex-direction:column;align-items:center;justify-content:center;
         min-width:72px;padding:7px 14px;border-radius:8px;border:1px solid;text-align:center}
.nmq-sc-val{font-size:1.3rem;font-weight:700;line-height:1.15}
.nmq-sc-lbl{font-size:.67rem;font-weight:600;margin-top:3px;white-space:nowrap;opacity:.8}
.nsc-total   {background:#f1f5f9;border-color:#cbd5e1;color:#475569}
.nsc-pending {background:#fef9c3;border-color:#fde047;color:#854d0e}
.nsc-pend-ok {background:#f0fdf4;border-color:#86efac;color:#166534}
.nsc-approved{background:#d1fae5;border-color:#6ee7b7;color:#065f46}
.nsc-excluded{background:#f8fafc;border-color:#e2e8f0;color:#64748b}
.nsc-risk    {background:#fee2e2;border-color:#fca5a5;color:#991b1b}
.nsc-risk-ok {background:#f0fdf4;border-color:#86efac;color:#166534}
.nsc-merge   {background:#fff7ed;border-color:#fdba74;color:#9a3412}
.nsc-u307    {background:#faf5ff;border-color:#c4b5fd;color:#5b21b6}
.nsc-u307-ok {background:#f0fdf4;border-color:#86efac;color:#166534}
.nsc-fkdup   {background:#fff1f2;border-color:#fda4af;color:#9f1239}
.nsc-fkdup-ok{background:#f0fdf4;border-color:#86efac;color:#166534}
/* ── Durum banner ── */
.nmq-sb{padding:9px 14px;border-radius:7px;border:1px solid;font-size:.85rem;margin-bottom:12px}
.nmq-sb-warn{background:#fef9c3;border-color:#fde047;color:#854d0e}
.nmq-sb-ok  {background:#d1fae5;border-color:#6ee7b7;color:#065f46}
.nmq-sb-info{background:#f1f5f9;border-color:#e2e8f0;color:#475569}
/* ── Alt tablo akordeonu ── */
.nmq-sub{margin-bottom:12px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}
.nmq-sub summary{padding:7px 12px;font-size:.83rem;font-weight:600;cursor:pointer;
                  background:#f8fafc;list-style:none;display:flex;align-items:center;gap:6px}
.nmq-sub summary::-webkit-details-marker{display:none}
.nmq-sub[open] summary{border-bottom:1px solid #e2e8f0}
.nmq-mini td,.nmq-mini th{font-size:.77rem;padding:5px 8px}
.nmq-mini{margin:0}
</style>

<details class="au-sec" id="nmq-section" <?= $nmq_pending > 0 ? 'open' : '' ?>>
<summary>
    🔬 Normalize Migration Review
    <span class="au-badge <?= $nmq_badge_cls ?>" style="margin-left:auto"><?= h($nmq_badge_txt) ?></span>
</summary>
<div class="au-body">

<?php /* ── Henüz analiz yapılmamış ── */ if (!$nmq_exists || $nmq_total === 0): ?>
<?php if (!$nmq_exists): ?>
<div class="nmq-sb nmq-sb-info">
    ℹ Henüz analiz başlatılmamış. Aşağıdaki <strong>«Analizi Başlat»</strong> butonuna basın.
</div>
<?php else: ?>
<div class="nmq-sb nmq-sb-info">
    ℹ Queue boş — değişiklik gerektiren kayıt bulunamadı veya tüm kayıtlar zaten normalize edilmiş.
    Yeniden taramak için <strong>«Analizi Başlat»</strong> butonuna basın.
</div>
<?php endif; ?>
<p style="color:var(--text-muted);font-size:.82rem;margin-bottom:12px">
    <code>material_definitions</code> tablosunu tarar, normalize_text_v2() farklarını
    ve FK merge ihtiyaçlarını tespit eder. Hiçbir veri değiştirmez.
</p>
<form method="post">
    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="nmq_refresh">
    <button type="submit" class="btn btn-lg">▶ Analizi Başlat</button>
</form>

<?php else: ?>

<?php /* ── Queue durum özeti ── */ ?>

<?php /* Durum banner */ ?>
<?php if ($nmq_pending > 0): ?>
<div class="nmq-sb nmq-sb-warn">
    ⚠ <strong><?= $nmq_pending ?> kayıt hâlâ bekliyor.</strong>
    Her satır için <em>Onayla</em> veya <em>Hariç Tut</em> seçin.
    <strong>Pending = 0 olmadan migration planı üretmeyin.</strong>
</div>
<?php elseif ($nmq_approved > 0): ?>
<div class="nmq-sb nmq-sb-ok">
    ✓ <strong>Review tamamlandı.</strong>
    <?= $nmq_approved ?> onaylı · <?= $nmq_excluded ?> hariç tutuldu.
    Migration planı üretilebilir: <code>php scripts/plan_normalize_migration.php</code>
</div>
<?php else: ?>
<div class="nmq-sb nmq-sb-info">
    ℹ Onaylanmış kayıt yok. Migration gerekmez veya tüm kayıtlar hariç tutuldu.
</div>
<?php endif; ?>

<?php /* Stat kartları */ ?>
<div class="nmq-stat-grid">
    <div class="nmq-sc nsc-total">
        <div class="nmq-sc-val"><?= $nmq_total ?></div>
        <div class="nmq-sc-lbl">Toplam</div>
    </div>
    <div class="nmq-sc <?= $nmq_pending > 0 ? 'nsc-pending' : 'nsc-pend-ok' ?>">
        <div class="nmq-sc-val"><?= $nmq_pending ?></div>
        <div class="nmq-sc-lbl"><?= $nmq_pending > 0 ? '⏳ Bekliyor' : '✓ Bekliyor' ?></div>
    </div>
    <div class="nmq-sc nsc-approved">
        <div class="nmq-sc-val"><?= $nmq_approved ?></div>
        <div class="nmq-sc-lbl">✓ Onaylı</div>
    </div>
    <div class="nmq-sc nsc-excluded">
        <div class="nmq-sc-val"><?= $nmq_excluded ?></div>
        <div class="nmq-sc-lbl">— Hariç</div>
    </div>
    <div class="nmq-sc <?= $nmq_stat['yuksek_risk'] > 0 ? 'nsc-risk' : 'nsc-risk-ok' ?>">
        <div class="nmq-sc-val"><?= $nmq_stat['yuksek_risk'] ?></div>
        <div class="nmq-sc-lbl">Yüksek Risk</div>
    </div>
    <div class="nmq-sc nsc-merge">
        <div class="nmq-sc-val"><?= $nmq_stat['merge'] ?></div>
        <div class="nmq-sc-lbl">Merge</div>
    </div>
    <div class="nmq-sc <?= $nmq_stat['u307'] > 0 ? 'nsc-u307' : 'nsc-u307-ok' ?>">
        <div class="nmq-sc-val"><?= $nmq_stat['u307'] ?></div>
        <div class="nmq-sc-lbl">U+0307</div>
    </div>
    <div class="nmq-sc <?= $nmq_stat['riskli_dup'] > 0 ? 'nsc-fkdup' : 'nsc-fkdup-ok' ?>">
        <div class="nmq-sc-val"><?= $nmq_stat['riskli_dup'] ?></div>
        <div class="nmq-sc-lbl">FK'lı Dup</div>
    </div>
</div>

<?php /* Pending mini tablo */ if (!empty($nmq_pending_rows)): ?>
<details class="nmq-sub" open>
    <summary>⏳ Bekleyen Kayıtlar <span style="background:#fde047;color:#854d0e;border-radius:10px;padding:1px 8px;font-size:.75rem;margin-left:4px"><?= count($nmq_pending_rows) ?></span></summary>
    <div class="au-tbl-wrap">
    <table class="au-tbl nmq-mini">
        <thead><tr>
            <th>ID</th><th>Tür</th><th>Mevcut Değer</th><th>V2 Sonucu</th>
            <th>Risk</th><th>Rol</th><th>FK</th>
        </tr></thead>
        <tbody>
        <?php foreach ($nmq_pending_rows as $r): ?>
        <?php $is_dup = $r['is_merge'] && !$r['is_survivor']; ?>
        <tr>
            <td style="font-family:monospace;font-size:.75rem"><?= (int)$r['target_id'] ?></td>
            <td><span style="font-size:.72rem;background:#f1f5f9;padding:1px 6px;border-radius:5px"><?= h($r['mat_type']) ?></span></td>
            <td><?= h($r['current_value']) ?><?= $r['u0307'] ? '<span class="u307-flag">U+0307</span>' : '' ?></td>
            <td style="color:#0f766e"><?= h($r['v2_value']) ?></td>
            <td>
                <?php $rc = match($r['risk_level']) {
                    'YÜKSEK' => 'background:#fee2e2;color:#991b1b',
                    'ORTA'   => 'background:#fff7ed;color:#9a3412',
                    default  => 'background:#f0fdf4;color:#166534',
                }; ?>
                <span style="font-size:.72rem;font-weight:700;padding:1px 7px;border-radius:9px;<?= $rc ?>"><?= h($r['risk_level']) ?></span>
            </td>
            <td>
                <?php if ($is_dup): ?>
                <span class="nmq-role role-dup">⬇ Dup</span>
                <?php else: ?>
                <span class="nmq-role role-upd">✎ Güncelle</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($r['fk_total'] > 0): ?>
                <span class="nmq-fk-chip"><?= (int)$r['fk_total'] ?> ref</span>
                <?php else: ?><span style="color:#cbd5e1">—</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<?php /* Yüksek riskli merge tablo */ if (!empty($nmq_high_risk_rows)): ?>
<details class="nmq-sub" open>
    <summary>🔴 Yüksek Riskli Merge <span style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:1px 8px;font-size:.75rem;margin-left:4px"><?= count($nmq_high_risk_rows) ?></span></summary>
    <div class="au-tbl-wrap">
    <table class="au-tbl nmq-mini">
        <thead><tr>
            <th>Dup ID</th><th>Silinecek Değer</th>
            <th>Surviving ID</th><th>Kalacak Değer</th>
            <th>FK Dağılımı</th><th>Durum</th>
        </tr></thead>
        <tbody>
        <?php foreach ($nmq_high_risk_rows as $r): ?>
        <tr>
            <td style="font-family:monospace;font-size:.75rem;color:#991b1b"><?= (int)$r['target_id'] ?></td>
            <td><del style="color:#991b1b"><?= h($r['current_value']) ?></del></td>
            <td style="font-family:monospace;font-size:.75rem;color:#065f46"><?= (int)$r['surviving_id'] ?></td>
            <td style="color:#065f46;font-weight:600"><?= h($r['surviving_name'] ?? '—') ?></td>
            <td>
                <div class="nmq-fk">
                <?php
                $fk_parts = [];
                if ($r['fk_lp_kasa']  > 0) $fk_parts[] = 'kasa:'  . $r['fk_lp_kasa'];
                if ($r['fk_lp_palet'] > 0) $fk_parts[] = 'palet:' . $r['fk_lp_palet'];
                if ($r['fk_pm']       > 0) $fk_parts[] = 'malz:'  . $r['fk_pm'];
                if ($r['fk_msm']      > 0) $fk_parts[] = 'stok:'  . $r['fk_msm'];
                foreach ($fk_parts as $fp): ?><span class="nmq-fk-chip"><?= h($fp) ?></span><?php endforeach;
                if (empty($fk_parts)) echo '<span style="color:#cbd5e1">—</span>';
                ?>
                </div>
            </td>
            <td>
                <?php $st_map = ['pending'=>['st-pending','bekliyor'],'approved'=>['st-approved','onaylı'],'excluded'=>['st-excluded','hariç']]; ?>
                <span class="nmq-status <?= $st_map[$r['status']][0] ?? '' ?>"><?= $st_map[$r['status']][1] ?? h($r['status']) ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<?php /* ── Araç çubuğu ── */ ?>
<div class="nmq-toolbar">
    <form method="post" style="display:contents">
        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="nmq_refresh">
        <button type="submit" class="btn btn-sm btn-ghost" title="Analizi yeniden çalıştır">↻ Yenile</button>
    </form>
    <?php if ($nmq_pending > 0): ?>
    <form method="post" style="display:contents">
        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="nmq_bulk_approve">
        <input type="hidden" name="scope"  value="safe">
        <button type="submit" class="btn btn-sm btn-ghost"
                title="fk_total=0 olan tüm bekleyen kayıtları onayla"
                onclick="return confirm('FK etkisi olmayan tüm bekleyen kayıtlar onaylanacak. Devam edilsin mi?')">
            ✓ FK-siz hepsini onayla
        </button>
    </form>
    <form method="post" style="display:contents">
        <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="nmq_bulk_approve">
        <input type="hidden" name="scope"  value="all">
        <button type="submit" class="btn btn-sm btn-ghost" style="color:#dc2626;border-color:#dc2626"
                title="FK etkisi olanlar dahil tüm bekleyenler"
                onclick="return confirm('FK etkisi olanlar dahil TÜM bekleyen kayıtlar onaylanacak. Emin misiniz?')">
            ✓✓ Tümünü onayla
        </button>
    </form>
    <?php endif; ?>
</div>

<?php /* ── Sayaçlar ── */ ?>
<div class="nmq-counter">
    <strong id="nmqCntPending"><?= $nmq_pending ?></strong> bekliyor &nbsp;·&nbsp;
    <strong id="nmqCntApproved"><?= $nmq_approved ?></strong> onaylı &nbsp;·&nbsp;
    <strong id="nmqCntExcluded"><?= $nmq_excluded ?></strong> hariç &nbsp;·&nbsp;
    toplam <?= $nmq_total ?>
</div>

<?php /* ── Filtreler ── */ ?>
<div class="nmq-filters">
    <label>Type:
        <select id="nmqFType" onchange="nmqFilter()">
            <option value="">Tümü</option>
            <?php foreach (array_keys($nmq_types) as $t): ?>
            <option value="<?= h($t) ?>"><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><input type="checkbox" id="nmqFMerge" onchange="nmqFilter()"> Sadece Merge</label>
    <label><input type="checkbox" id="nmqFU307"  onchange="nmqFilter()"> Sadece U+0307</label>
    <label><input type="checkbox" id="nmqFRisk"  onchange="nmqFilter()"> Sadece Yüksek Risk</label>
    <label>Durum:
        <select id="nmqFStatus" onchange="nmqFilter()">
            <option value="">Tümü</option>
            <option value="pending">Bekliyor</option>
            <option value="approved">Onaylı</option>
            <option value="excluded">Hariç</option>
        </select>
    </label>
    <button onclick="nmqResetFilters()" class="btn btn-sm btn-ghost" style="padding:2px 8px;font-size:.75rem">× Sıfırla</button>
    <span id="nmqFilterNote" style="font-size:.75rem;color:#94a3b8;margin-left:4px"></span>
</div>

<?php /* ── Tablo ── */ ?>
<div class="au-tbl-wrap">
<table class="au-tbl nmq-tbl" id="nmqTable">
    <thead><tr>
        <th>ID</th>
        <th>Type</th>
        <th>Mevcut Değer</th>
        <th>V2 Sonucu</th>
        <th>Rol</th>
        <th>Surviving / Dup</th>
        <th>FK Etkisi</th>
        <th>Risk</th>
        <th>Durum</th>
        <th>Karar</th>
    </tr></thead>
    <tbody>
    <?php foreach ($nmq_rows as $r):
        $role_lbl = '';
        $role_cls = '';
        if ($r['is_merge'] && !$r['is_survivor']) {
            $role_lbl = '⬇ Merge-Dup';
            $role_cls = 'role-dup';
        } else {
            $role_lbl = '✎ İsim Güncelle';
            $role_cls = 'role-upd';
        }
        $fk_chips = [];
        if ($r['fk_lp_kasa'] > 0)  $fk_chips[] = $r['fk_lp_kasa'] . ' kasa_cinsi';
        if ($r['fk_lp_palet'] > 0) $fk_chips[] = $r['fk_lp_palet'] . ' palet_tipi';
        if ($r['fk_pm'] > 0)       $fk_chips[] = $r['fk_pm'] . ' malzeme';
        if ($r['fk_msm'] > 0)      $fk_chips[] = $r['fk_msm'] . ' stok-hrkts';
        $risk_cls = ['YÜKSEK'=>'b-err','ORTA'=>'b-warn','DÜŞÜK'=>'b-info'][$r['risk_level']] ?? 'b-info';
        $st_cls   = ['pending'=>'st-pending','approved'=>'st-approved','excluded'=>'st-excluded'][$r['status']] ?? '';
        $st_lbl   = ['pending'=>'⏳ Bekliyor','approved'=>'✓ Onaylı','excluded'=>'✗ Hariç'][$r['status']] ?? $r['status'];
    ?>
    <tr data-qid="<?= (int)$r['id'] ?>"
        data-type="<?= h($r['mat_type']) ?>"
        data-merge="<?= $r['is_merge'] ?>"
        data-u307="<?= $r['u0307'] ?>"
        data-risk="<?= h($r['risk_level']) ?>"
        data-status="<?= h($r['status']) ?>">
        <td style="font-size:.75rem;color:#94a3b8">#<?= (int)$r['target_id'] ?></td>
        <td><code style="font-size:.75rem"><?= h($r['mat_type']) ?></code></td>
        <td>
            <?= h($r['current_value']) ?>
            <?php if ($r['u0307']): ?><span class="u307-flag" title="<?= h($r['unicode_flags']) ?>">U+0307</span><?php endif; ?>
        </td>
        <td><strong><?= h($r['v2_value']) ?></strong></td>
        <td><span class="nmq-role <?= $role_cls ?>"><?= h($role_lbl) ?></span></td>
        <td style="font-size:.75rem;color:#64748b">
            <?php if ($r['is_merge']): ?>
                <?php if (!$r['is_survivor']): ?>
                surv: <strong>#<?= (int)$r['surviving_id'] ?></strong><br>
                dup: <strong>#<?= (int)$r['dup_id'] ?></strong>
                <?php endif; ?>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td>
            <?php if (empty($fk_chips)): ?>
            <span style="color:#94a3b8;font-size:.75rem">—</span>
            <?php else: ?>
            <div class="nmq-fk">
                <?php foreach ($fk_chips as $chip): ?>
                <span class="nmq-fk-chip"><?= h($chip) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </td>
        <td><span class="au-badge <?= $risk_cls ?>"><?= h($r['risk_level']) ?></span></td>
        <td><span class="nmq-status <?= $st_cls ?>" id="nmqSt<?= (int)$r['id'] ?>"><?= h($st_lbl) ?></span></td>
        <td>
            <div class="nmq-btns">
                <button class="nmq-btn nmq-btn-ok"
                        <?= $r['status'] === 'approved' ? 'disabled' : '' ?>
                        onclick="nmqAction(this,<?= (int)$r['id'] ?>,'approved')">✓ Onayla</button>
                <button class="nmq-btn nmq-btn-ex"
                        <?= $r['status'] === 'excluded' ? 'disabled' : '' ?>
                        onclick="nmqAction(this,<?= (int)$r['id'] ?>,'excluded')">✗ Hariç</button>
                <button class="nmq-btn nmq-btn-rst"
                        <?= $r['status'] === 'pending' ? 'disabled' : '' ?>
                        onclick="nmqAction(this,<?= (int)$r['id'] ?>,'pending')" title="Kararı sıfırla">↺</button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<input type="hidden" id="nmqCsrf" value="<?= h(csrf_token()) ?>">

<script>
(function(){
const csrf   = document.getElementById('nmqCsrf').value;
const tbl    = document.getElementById('nmqTable');
const cntP   = document.getElementById('nmqCntPending');
const cntA   = document.getElementById('nmqCntApproved');
const cntE   = document.getElementById('nmqCntExcluded');
const note   = document.getElementById('nmqFilterNote');
const STATUS_LABELS = {pending:'⏳ Bekliyor', approved:'✓ Onaylı', excluded:'✗ Hariç'};
const STATUS_CLS    = {pending:'st-pending', approved:'st-approved', excluded:'st-excluded'};

function nmqFilter() {
    const fType   = document.getElementById('nmqFType').value;
    const fMerge  = document.getElementById('nmqFMerge').checked;
    const fU307   = document.getElementById('nmqFU307').checked;
    const fRisk   = document.getElementById('nmqFRisk').checked;
    const fStatus = document.getElementById('nmqFStatus').value;
    let vis = 0;
    tbl.querySelectorAll('tbody tr').forEach(function(tr) {
        const d = tr.dataset;
        const show = (!fType   || d.type   === fType)
                  && (!fMerge  || d.merge  === '1')
                  && (!fU307   || d.u307   === '1')
                  && (!fRisk   || d.risk   === 'YÜKSEK')
                  && (!fStatus || d.status === fStatus);
        tr.classList.toggle('nmq-hidden', !show);
        if (show) vis++;
    });
    const total = tbl.querySelectorAll('tbody tr').length;
    note.textContent = (vis < total) ? vis + '/' + total + ' satır gösteriliyor' : '';
}
window.nmqFilter = nmqFilter;

window.nmqResetFilters = function() {
    document.getElementById('nmqFType').value   = '';
    document.getElementById('nmqFMerge').checked = false;
    document.getElementById('nmqFU307').checked  = false;
    document.getElementById('nmqFRisk').checked  = false;
    document.getElementById('nmqFStatus').value  = '';
    nmqFilter();
};

window.nmqAction = function(btn, qid, newStatus) {
    btn.disabled = true;
    const row = document.querySelector('tr[data-qid="'+qid+'"]');
    const oldStatus = row ? row.dataset.status : '';
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action:'nmq_set_status',csrf:csrf,queue_id:qid,status:newStatus})
    })
    .then(function(res){ return res.json(); })
    .then(function(data) {
        if (data.ok && row) {
            row.dataset.status = newStatus;
            const badge = document.getElementById('nmqSt' + qid);
            if (badge) {
                badge.textContent  = STATUS_LABELS[newStatus] || newStatus;
                badge.className    = 'nmq-status ' + (STATUS_CLS[newStatus] || '');
            }
            // Buton durumlarını güncelle
            const btns = row.querySelectorAll('.nmq-btn');
            btns[0].disabled = (newStatus === 'approved');
            btns[1].disabled = (newStatus === 'excluded');
            btns[2].disabled = (newStatus === 'pending');
            // Sayaç güncelle
            if (oldStatus && cntP && cntA && cntE) {
                const map = {pending:cntP, approved:cntA, excluded:cntE};
                if (map[oldStatus]) map[oldStatus].textContent = Math.max(0, parseInt(map[oldStatus].textContent||0) - 1);
                if (map[newStatus]) map[newStatus].textContent = parseInt(map[newStatus].textContent||0) + 1;
            }
            // Filtre varsa yeniden uygula
            nmqFilter();
        } else {
            alert(data.error || 'Hata oluştu.');
            btn.disabled = false;
        }
    })
    .catch(function(){ alert('Bağlantı hatası.'); btn.disabled = false; });
};
})();
</script>

<?php endif; ?>
</div>
</details>

<?php render_footer();
