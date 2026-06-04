<?php
// =========================================================
// daily_report_create.php — X/Z Günlük Rapor oluşturma POST handler
// XZ-02: Sadece X Raporu (snapshot, hiçbir kayıt kapatılmaz)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reports.php?type=gunluk'); exit;
}

csrf_check($_POST['csrf'] ?? null);

// Tablo varlık kontrolü — migration db.php'de çalışır, ama yine de doğrula
try {
    db()->query("SELECT 1 FROM `daily_reports` LIMIT 0");
    db()->query("SELECT 1 FROM `daily_report_items` LIMIT 0");
} catch (PDOException $_tbl_e) {
    error_log("[XZ] daily_reports/items tablo erişim hatası: " . $_tbl_e->getMessage());
    $repair_hint = (function_exists('is_admin') && is_admin())
        ? ' <a href="repair_xz_tables.php">Onarım aracını çalıştırın.</a>'
        : ' Sistem yöneticisine bildirin.';
    set_flash('error', 'Rapor arşiv tabloları hazır değil.' . $repair_hint);
    header('Location: reports.php?type=gunluk'); exit;
}

$report_type = trim($_POST['report_type'] ?? '');
if ($report_type === 'Z') {
    set_flash('error', 'Z Raporu henüz aktif değil.');
    header('Location: reports.php?type=gunluk'); exit;
}
if ($report_type !== 'X') {
    set_flash('error', 'Geçersiz rapor tipi.');
    header('Location: reports.php?type=gunluk'); exit;
}

// Filtreler
$f_from          = trim($_POST['date_from']     ?? '');
$f_to            = trim($_POST['date_to']       ?? '');
$f_firma         = trim($_POST['firma']         ?? '');
$f_urun          = trim($_POST['urun']          ?? '');
$f_depo          = trim($_POST['depo']          ?? '');
$f_palet_islendi = trim($_POST['palet_islendi'] ?? 'hicbiri');
if (!in_array($f_palet_islendi, ['hicbiri', 'isaretli', ''], true)) $f_palet_islendi = 'hicbiri';

// Tarih cross-fill
if ($f_from !== '' && $f_to === '') $f_to   = $f_from;
elseif ($f_from === '' && $f_to !== '') $f_from = $f_to;

// ── 1. Kantar verileri (raporlanmamış fişler) ────────────
$gk_rows = [];
try {
    $rep_col = (bool)db()->query("SHOW COLUMNS FROM `kantar_fisleri` LIKE 'reported_at'")->fetchColumn();
    $kw = ["1=1"];
    if ($rep_col) $kw[] = "kf.reported_at IS NULL";
    $kp = [];
    if ($f_firma !== '') { $kw[] = "kf.firma_adi = ?";      $kp[] = $f_firma; }
    if ($f_depo  !== '') { $kw[] = "kf.depo = ?";           $kp[] = $f_depo; }
    if ($f_urun  !== '') { $kw[] = "kf.malin_cinsi LIKE ?"; $kp[] = '%' . $f_urun . '%'; }
    $st = db()->prepare(
        "SELECT kf.* FROM kantar_fisleri kf
         WHERE " . implode(' AND ', $kw) . "
         ORDER BY kf.giris_tarih DESC, kf.id DESC LIMIT 500"
    );
    $st->execute($kp);
    $gk_rows = $st->fetchAll();
} catch (PDOException $_e) {}

// ── 2. Çıkma verileri (raporlanmamış kayıtlar) ──────────
$ck_rows = [];
try {
    $cikma_rep_col = (bool)db()->query("SHOW COLUMNS FROM `loading_records` LIKE 'reported_at'")->fetchColumn();
    $cw = ["r.type='cikma'"]; $cp = [];
    if ($cikma_rep_col) $cw[] = "r.reported_at IS NULL";
    if ($f_firma !== '') { $cw[] = "r.firma = ?"; $cp[] = $f_firma; }
    if ($f_urun  !== '') { $cw[] = "r.urun = ?";  $cp[] = $f_urun; }
    if ($f_depo  !== '') {
        $cw[] = "EXISTS (SELECT 1 FROM loading_pallets _p2 WHERE _p2.loading_record_id=r.id AND _p2.depo=?)";
        $cp[] = $f_depo;
    }
    if ($f_from !== '') { $cw[] = "r.tarih >= ?"; $cp[] = $f_from; }
    if ($f_to   !== '') { $cw[] = "r.tarih <= ?"; $cp[] = $f_to; }
    $st = db()->prepare("
        SELECT r.id, r.tarih, r.firma, r.urun, r.cikis_nedeni,
               (SELECT _p.depo FROM loading_pallets _p
                WHERE _p.loading_record_id=r.id AND _p.depo!='' ORDER BY _p.id LIMIT 1) AS depo,
               COUNT(p.id)                        AS palet_sayisi,
               COALESCE(SUM(p.kasa_adeti),0)       AS toplam_kasa,
               ROUND(COALESCE(SUM(p.brut_kg),0),3) AS toplam_brut,
               ROUND(COALESCE(SUM(p.dara_kg),0),3) AS toplam_dara,
               ROUND(COALESCE(SUM(p.net_kg),0),3)  AS toplam_net
        FROM loading_records r
        LEFT JOIN loading_pallets p ON p.loading_record_id=r.id
        WHERE " . implode(' AND ', $cw) . "
        GROUP BY r.id ORDER BY r.tarih DESC, r.id DESC LIMIT 500");
    $st->execute($cp);
    $ck_rows = $st->fetchAll();
} catch (PDOException $_e) {}

// ── 3. Yükleme paletleri (palet bazında, makineye dökülen) ─
$mk_pallets = [];
try {
    if ($f_palet_islendi === 'hicbiri') {
        $isle_cond = "(lp.islendi IS NULL OR lp.islendi=0)";
    } elseif ($f_palet_islendi === 'isaretli') {
        $isle_cond = "lp.islendi=1";
    } else {
        $isle_cond = "1=1";
    }
    $pw = ["lr.type='yukleme'", $isle_cond]; $pp = [];
    if ($f_from !== '') { $pw[] = "lr.tarih >= ?"; $pp[] = $f_from; }
    if ($f_to   !== '') { $pw[] = "lr.tarih <= ?"; $pp[] = $f_to; }
    if ($f_firma !== '') { $pw[] = "lr.firma = ?"; $pp[] = $f_firma; }
    if ($f_urun  !== '') { $pw[] = "lr.urun = ?";  $pp[] = $f_urun; }
    if ($f_depo  !== '') { $pw[] = "lp.depo = ?";  $pp[] = $f_depo; }
    $st = db()->prepare("
        SELECT lp.id AS palet_id, lp.loading_record_id,
               lp.palet_no, lp.kasa_adeti, lp.brut_kg, lp.dara_kg, lp.net_kg,
               lp.islendi, lp.depo,
               lr.id AS record_id, lr.tarih, lr.firma, lr.bolge, lr.alici,
               lr.urun, lr.parti_no, lr.durum
        FROM loading_pallets lp
        JOIN loading_records lr ON lr.id = lp.loading_record_id
        WHERE " . implode(' AND ', $pw) . "
        ORDER BY lr.tarih DESC, lr.id DESC, lp.sira_no ASC
        LIMIT 2000");
    $st->execute($pp);
    $mk_pallets = $st->fetchAll();
} catch (PDOException $_e) {}

// ── Özet (snapshot için) ─────────────────────────────────
$ozet_kantar_net = 0.0;
foreach ($gk_rows as $_kf) { $_kc = kantar_calc($_kf); $ozet_kantar_net += $_kc['net']; }

$snapshot = [
    'kantar_count'        => count($gk_rows),
    'cikma_count'         => count($ck_rows),
    'yukleme_palet_count' => count($mk_pallets),
    'yukleme_record_count'=> count(array_unique(array_column($mk_pallets, 'record_id'))),
    'kantar_net_kg'       => round($ozet_kantar_net, 3),
    'cikma_net_kg'        => round((float)array_sum(array_column($ck_rows, 'toplam_net')), 3),
    'yukleme_net_kg'      => round((float)array_sum(array_column($mk_pallets, 'net_kg')), 3),
    'cikma_toplam_kasa'   => (int)array_sum(array_column($ck_rows, 'toplam_kasa')),
    'yukleme_toplam_kasa' => (int)array_sum(array_column($mk_pallets, 'kasa_adeti')),
    'filters' => [
        'date_from'     => $f_from,
        'date_to'       => $f_to,
        'firma'         => $f_firma,
        'urun'          => $f_urun,
        'depo'          => $f_depo,
        'palet_islendi' => $f_palet_islendi,
    ],
];

$date_disp = ($f_from !== '')
    ? ($f_from === $f_to
        ? date('d.m.Y', strtotime($f_from))
        : date('d.m.Y', strtotime($f_from)) . ' - ' . date('d.m.Y', strtotime($f_to)))
    : date('d.m.Y');
$title    = 'X Raporu ' . $date_disp;
$user_id  = (int)($auth_user['id'] ?? 0) ?: null;
$now      = date('Y-m-d H:i:s');
$rep_date = ($f_from !== '' && $f_from === $f_to) ? $f_from : null;

// ── Transaction: daily_reports + daily_report_items ──────
$pdo = db();
$report_id = 0;
try {
    $pdo->beginTransaction();

    $pdo->prepare(
        "INSERT INTO daily_reports
         (report_type, report_date, date_from, date_to, title, created_at, created_by, status, snapshot_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'final', ?)"
    )->execute([
        'X',
        $rep_date,
        $f_from ?: null,
        $f_to   ?: null,
        $title,
        $now,
        $user_id,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    ]);
    $report_id = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare(
        "INSERT INTO daily_report_items
         (report_id, item_type, source_table, source_id, source_detail_id, snapshot_json, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    // Kantar kalemleri
    foreach ($gk_rows as $kf) {
        $kc   = kantar_calc($kf);
        $snap = json_encode([
            'fis_no'       => $kf['fis_no']       ?? '',
            'giris_tarih'  => $kf['giris_tarih']  ?? '',
            'firma_adi'    => $kf['firma_adi']     ?? '',
            'malin_cinsi'  => $kf['malin_cinsi']  ?? '',
            'plaka'        => $kf['plaka']         ?? '',
            'brut_kg'      => round($kc['brut'], 3),
            'dara_kg'      => round($kc['dara'], 3),
            'net_kg'       => round($kc['net'],  3),
            'kasa_sayisi'  => (int)($kf['kasa_sayisi']  ?? 0),
            'palet_sayisi' => (int)($kf['palet_sayisi'] ?? 0),
            'depo'         => $kf['depo']          ?? '',
        ], JSON_UNESCAPED_UNICODE);
        $ins->execute([$report_id, 'kantar', 'kantar_fisleri', (int)$kf['id'], null, $snap, $now]);
    }

    // Çıkma kalemleri
    foreach ($ck_rows as $ck) {
        $snap = json_encode([
            'tarih'        => $ck['tarih']        ?? '',
            'firma'        => $ck['firma']        ?? '',
            'urun'         => $ck['urun']         ?? '',
            'cikis_nedeni' => $ck['cikis_nedeni'] ?? '',
            'depo'         => $ck['depo']         ?? '',
            'palet_sayisi' => (int)($ck['palet_sayisi'] ?? 0),
            'toplam_kasa'  => (int)($ck['toplam_kasa']  ?? 0),
            'toplam_brut'  => round((float)($ck['toplam_brut'] ?? 0), 3),
            'toplam_dara'  => round((float)($ck['toplam_dara'] ?? 0), 3),
            'toplam_net'   => round((float)($ck['toplam_net']  ?? 0), 3),
        ], JSON_UNESCAPED_UNICODE);
        $ins->execute([$report_id, 'cikma', 'loading_records', (int)$ck['id'], null, $snap, $now]);
    }

    // Yükleme palet kalemleri
    foreach ($mk_pallets as $mp) {
        $snap = json_encode([
            'loading_record_id' => (int)$mp['record_id'],
            'tarih'             => $mp['tarih']    ?? '',
            'firma'             => $mp['firma']    ?? '',
            'bolge'             => $mp['bolge']    ?? '',
            'alici'             => $mp['alici']    ?? '',
            'urun'              => $mp['urun']     ?? '',
            'depo'              => $mp['depo']     ?? '',
            'durum'             => $mp['durum']    ?? '',
            'palet_no'          => $mp['palet_no'] ?? '',
            'kasa_adeti'        => (int)($mp['kasa_adeti'] ?? 0),
            'brut_kg'           => round((float)($mp['brut_kg'] ?? 0), 3),
            'dara_kg'           => round((float)($mp['dara_kg'] ?? 0), 3),
            'net_kg'            => round((float)($mp['net_kg']  ?? 0), 3),
        ], JSON_UNESCAPED_UNICODE);
        $ins->execute([$report_id, 'yukleme_palet', 'loading_pallets', (int)$mp['palet_id'], (int)$mp['record_id'], $snap, $now]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', 'Rapor oluşturulamadı: ' . htmlspecialchars($e->getMessage()));
    header('Location: reports.php?type=gunluk'); exit;
}

audit_log_event('daily_report_x_create', 'daily_reports', $report_id, null, [
    'report_type'         => 'X',
    'date_from'           => $f_from,
    'date_to'             => $f_to,
    'kantar_count'        => count($gk_rows),
    'cikma_count'         => count($ck_rows),
    'yukleme_palet_count' => count($mk_pallets),
]);

set_flash('success', 'X Raporu oluşturuldu. Hiçbir kayıt kapatılmadı.');
header('Location: daily_report_view.php?id=' . $report_id);
exit;
