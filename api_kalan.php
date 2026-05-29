<?php
// =========================================================
// api_kalan.php  —  Kalan Palet Hesaplama AJAX API
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc_helper.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.read');

header('Content-Type: application/json; charset=utf-8');

// Tabloları lazily oluştur
db()->exec("CREATE TABLE IF NOT EXISTS calc_targets (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    loading_record_id   INT NOT NULL,
    target_kg           DECIMAL(10,3) DEFAULT 0,
    target_pallet_count INT DEFAULT 0,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_record (loading_record_id)
)");

db()->exec("CREATE TABLE IF NOT EXISTS crate_avg_settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    crate_count INT NOT NULL UNIQUE,
    avg_kg      DECIMAL(10,3) NOT NULL,
    source_type VARCHAR(10) DEFAULT 'manual',
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Write-action guard ─────────────────────────────────────────────────────
// READ : status, crate_settings, auto_avg, calculate  → records.read yeterli
// WRITE: save_target, save_crate, delete_crate         → records.write + CSRF
static $WRITE_ACTIONS = ['save_target', 'save_crate', 'delete_crate'];
if (in_array($action, $WRITE_ACTIONS, true)) {
    if (!can('records.write')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Bu işlem için records.write yetkisi gereklidir.']);
        exit;
    }
    // csrf_check() artık JSON-aware: bu endpoint JSON başlığı set ettiği için JSON 403 döner.
    csrf_check($_POST['csrf'] ?? null);
}
// ──────────────────────────────────────────────────────────────────────────

function ok(array $d = []): void  { echo json_encode(['ok' => true]  + $d); exit; }
function err(string $m):  void  { http_response_code(400); echo json_encode(['ok' => false, 'error' => $m]); exit; }

switch ($action) {

    // ---- Mevcut durum ----
    case 'status':
        $rid = (int)($_GET['record_id'] ?? 0);
        if (!$rid) err('record_id gerekli');

        $st = db()->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(brut_kg),0) AS total_kg
                             FROM loading_pallets WHERE loading_record_id=?");
        $st->execute([$rid]);
        $row = $st->fetch();

        $st2 = db()->prepare("SELECT target_kg, target_pallet_count FROM calc_targets WHERE loading_record_id=?");
        $st2->execute([$rid]);
        $tgt = $st2->fetch() ?: ['target_kg' => 0, 'target_pallet_count' => 0];

        ok([
            'pallet_count'        => (int)$row['cnt'],
            'total_kg'            => (float)$row['total_kg'],
            'target_kg'           => (float)$tgt['target_kg'],
            'target_pallet_count' => (int)$tgt['target_pallet_count'],
        ]);

    // ---- Hedef kaydet ----
    case 'save_target':
        $rid = (int)($_POST['record_id'] ?? 0);
        $tkg = (float)($_POST['target_kg'] ?? 0);
        $tpc = (int)($_POST['target_pallet_count'] ?? 0);
        if (!$rid) err('record_id gerekli');

        db()->prepare("INSERT INTO calc_targets (loading_record_id, target_kg, target_pallet_count)
                       VALUES (?,?,?) ON DUPLICATE KEY UPDATE
                       target_kg=VALUES(target_kg),
                       target_pallet_count=VALUES(target_pallet_count),
                       updated_at=NOW()")
            ->execute([$rid, $tkg, $tpc]);
        ok();

    // ---- Kasa ayarlarını listele ----
    case 'crate_settings':
        $rows = db()->query("SELECT crate_count, avg_kg, source_type
                             FROM crate_avg_settings ORDER BY crate_count ASC")->fetchAll();
        ok(['items' => $rows]);

    // ---- Kasa ayarı kaydet/güncelle ----
    case 'save_crate':
        $cc  = (int)($_POST['crate_count'] ?? 0);
        $akg = (float)str_replace(',', '.', (string)($_POST['avg_kg'] ?? 0));
        $src = in_array($_POST['source_type'] ?? '', ['auto','manual']) ? $_POST['source_type'] : 'manual';
        if ($cc <= 0 || $akg <= 0) err('Geçersiz kasa adedi veya ortalama kg');

        db()->prepare("INSERT INTO crate_avg_settings (crate_count, avg_kg, source_type)
                       VALUES (?,?,?) ON DUPLICATE KEY UPDATE
                       avg_kg=VALUES(avg_kg), source_type=VALUES(source_type), updated_at=NOW()")
            ->execute([$cc, $akg, $src]);
        ok();

    // ---- Kasa ayarı sil ----
    case 'delete_crate':
        $cc = (int)($_POST['crate_count'] ?? 0);
        if (!$cc) err('Geçersiz kasa adedi');
        db()->prepare("DELETE FROM crate_avg_settings WHERE crate_count=?")->execute([$cc]);
        ok();

    // ---- Veritabanından otomatik ortalama hesapla ----
    case 'auto_avg':
        $cc  = (int)($_GET['crate_count'] ?? 0);
        $rid = (int)($_GET['record_id']   ?? 0);
        if (!$cc) err('Kasa adedi gerekli');
        if (!$rid) err('record_id gerekli');

        $st = db()->prepare("SELECT COUNT(*) AS cnt, AVG(brut_kg) AS avg_kg
                             FROM loading_pallets
                             WHERE kasa_adeti=? AND brut_kg > 0 AND loading_record_id=?");
        $st->execute([$cc, $rid]);
        $row = $st->fetch();

        if ((int)$row['cnt'] === 0) {
            ok(['found' => false, 'count' => 0, 'avg_kg' => 0]);
        }
        ok(['found' => true, 'count' => (int)$row['cnt'], 'avg_kg' => round((float)$row['avg_kg'], 1)]);

    // ---- Kombinasyon hesapla ----
    case 'calculate':
        $rid       = (int)($_POST['record_id'] ?? 0);
        $target_kg = (float)str_replace(',', '.', (string)($_POST['target_kg'] ?? 0));
        $target_pc = (int)($_POST['target_pallet_count'] ?? 0);
        $types_raw = json_decode($_POST['crate_types'] ?? '[]', true);

        if (!$target_kg || !$target_pc) err('Hedef bilgileri eksik');
        if (empty($types_raw) || !is_array($types_raw)) err('En az 1 kasa tipi gerekli');

        $st = db()->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(brut_kg),0) AS total_kg
                             FROM loading_pallets WHERE loading_record_id=?");
        $st->execute([$rid]);
        $cur = $st->fetch();

        $current_kg    = (float)$cur['total_kg'];
        $current_count = (int)$cur['cnt'];
        $remaining_kg  = $target_kg - $current_kg;
        $remaining_pc  = $target_pc - $current_count;

        if ($remaining_pc <= 0) err('Hedef palet sayısına zaten ulaşıldı veya aşıldı');

        $types = [];
        foreach ($types_raw as $t) {
            $cc  = (int)($t['crate_count'] ?? 0);
            $akg = (float)str_replace(',', '.', (string)($t['avg_kg'] ?? 0));
            if ($cc > 0 && $akg > 0) $types[] = ['crate_count' => $cc, 'avg_kg' => $akg];
        }
        if (empty($types)) err('Geçerli kasa tipi bulunamadı');

        $combos = kalan_combinations($remaining_pc, $types, $remaining_kg);

        foreach ($combos as &$c) {
            $c['final_kg']   = $current_kg + $c['estimated_kg'];
            $c['final_diff'] = $c['final_kg'] - $target_kg;
            $c['status']     = kalan_status($c['final_diff']);
        }
        unset($c);

        ok([
            'current_kg'     => $current_kg,
            'current_count'  => $current_count,
            'remaining_kg'   => $remaining_kg,
            'remaining_pc'   => $remaining_pc,
            'avg_per_pallet' => $remaining_pc > 0 ? round($remaining_kg / $remaining_pc, 1) : 0,
            'combinations'   => $combos,
        ]);

    default:
        err('Geçersiz action');
}
