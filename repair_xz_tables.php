<?php
// =========================================================
// repair_xz_tables.php — X/Z Rapor Tabloları Onarım (admin-only, web)
// daily_reports + daily_report_items + eksik kolonları oluşturur.
// Veri silmez, sadece eksik yapıları ekler.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_login();
if (!function_exists('is_admin') || !is_admin()) {
    http_response_code(403);
    render_header('Erişim Reddedildi');
    echo '<div class="empty"><p class="muted">Bu sayfa yalnızca admin kullanıcılara açıktır.</p></div>';
    render_footer();
    exit;
}

// ── Mevcut durum kontrolleri ────────────────────────────
function tbl_exists(PDO $pdo, string $tbl): bool {
    try { $pdo->query("SELECT 1 FROM `{$tbl}` LIMIT 0"); return true; }
    catch (PDOException $_e) { return false; }
}
function col_exists(PDO $pdo, string $tbl, string $col): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `{$tbl}` LIKE '{$col}'")->fetchColumn(); }
    catch (PDOException $_e) { return false; }
}

$pdo = db();

// Durum okuma (POST öncesi ve sonrası için aynı fonksiyon)
function read_status(PDO $pdo): array {
    return [
        'daily_reports'           => tbl_exists($pdo, 'daily_reports'),
        'daily_report_items'      => tbl_exists($pdo, 'daily_report_items'),
        'kf_report_id'            => col_exists($pdo, 'kantar_fisleri',  'report_id'),
        'lr_report_id'            => col_exists($pdo, 'loading_records', 'report_id'),
        'lp_reported_at'          => col_exists($pdo, 'loading_pallets', 'reported_at'),
        'lp_reported_by'          => col_exists($pdo, 'loading_pallets', 'reported_by'),
        'lp_report_id'            => col_exists($pdo, 'loading_pallets', 'report_id'),
    ];
}

$before  = read_status($pdo);
$results = [];  // ['label' => '...', 'state' => 'ok|err|skip', 'msg' => '...']
$ran     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $ran = true;

    // ── daily_reports ──────────────────────────────────
    if ($before['daily_reports']) {
        $results[] = ['label' => 'daily_reports', 'state' => 'skip', 'msg' => 'Zaten mevcut'];
    } else {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `daily_reports` (
                `id`            INT AUTO_INCREMENT PRIMARY KEY,
                `report_type`   VARCHAR(5) NOT NULL,
                `report_date`   DATE NULL,
                `date_from`     DATE NULL,
                `date_to`       DATE NULL,
                `title`         VARCHAR(200) NOT NULL DEFAULT '',
                `note`          TEXT NULL,
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by`    INT NULL,
                `closed_at`     DATETIME NULL,
                `status`        VARCHAR(20) NOT NULL DEFAULT 'final',
                `snapshot_json` LONGTEXT NULL,
                `pdf_path`      VARCHAR(500) NULL,
                INDEX `idx_dr_type`    (`report_type`),
                INDEX `idx_dr_date`    (`report_date`),
                INDEX `idx_dr_created` (`created_at`),
                INDEX `idx_dr_by`      (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $results[] = ['label' => 'daily_reports', 'state' => 'ok', 'msg' => 'Oluşturuldu'];
        } catch (Throwable $e) {
            error_log("[repair_xz] daily_reports CREATE failed: " . $e->getMessage());
            $results[] = ['label' => 'daily_reports', 'state' => 'err', 'msg' => $e->getMessage()];
        }
    }

    // ── daily_report_items ─────────────────────────────
    if ($before['daily_report_items']) {
        $results[] = ['label' => 'daily_report_items', 'state' => 'skip', 'msg' => 'Zaten mevcut'];
    } else {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `daily_report_items` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `report_id`        INT NOT NULL,
                `item_type`        VARCHAR(30) NOT NULL,
                `source_table`     VARCHAR(60) NOT NULL,
                `source_id`        INT NOT NULL,
                `source_detail_id` INT NULL,
                `snapshot_json`    LONGTEXT NULL,
                `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_dri_report` (`report_id`),
                INDEX `idx_dri_type`   (`item_type`),
                INDEX `idx_dri_source` (`source_table`, `source_id`),
                INDEX `idx_dri_detail` (`source_detail_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $results[] = ['label' => 'daily_report_items', 'state' => 'ok', 'msg' => 'Oluşturuldu'];
        } catch (Throwable $e) {
            error_log("[repair_xz] daily_report_items CREATE failed: " . $e->getMessage());
            $results[] = ['label' => 'daily_report_items', 'state' => 'err', 'msg' => $e->getMessage()];
        }
    }

    // ── kantar_fisleri.report_id ───────────────────────
    _repair_col($pdo, $results, 'kantar_fisleri', 'report_id', 'INT NULL',
                $before['kf_report_id'], 'kantar_fisleri.report_id');

    // ── loading_records.report_id ──────────────────────
    _repair_col($pdo, $results, 'loading_records', 'report_id', 'INT NULL',
                $before['lr_report_id'], 'loading_records.report_id');

    // ── loading_pallets: reported_at / reported_by / report_id ─
    // Bunlar birlikte eklenebilir, ama ayrı ayrı kontrol et
    _repair_col($pdo, $results, 'loading_pallets', 'reported_at', 'DATETIME NULL',
                $before['lp_reported_at'], 'loading_pallets.reported_at');
    _repair_col($pdo, $results, 'loading_pallets', 'reported_by', 'INT NULL',
                $before['lp_reported_by'], 'loading_pallets.reported_by');
    _repair_col($pdo, $results, 'loading_pallets', 'report_id', 'INT NULL',
                $before['lp_report_id'], 'loading_pallets.report_id');
}

function _repair_col(PDO $pdo, array &$out, string $tbl, string $col, string $def,
                     bool $already_exists, string $label): void {
    if ($already_exists) {
        $out[] = ['label' => $label, 'state' => 'skip', 'msg' => 'Zaten mevcut'];
        return;
    }
    // Tablo yoksa eklenemez
    if (!tbl_exists($pdo, $tbl)) {
        $out[] = ['label' => $label, 'state' => 'skip', 'msg' => "Tablo {$tbl} yok — atlandı"];
        return;
    }
    try {
        $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$def}");
        $out[] = ['label' => $label, 'state' => 'ok', 'msg' => 'Eklendi'];
    } catch (Throwable $e) {
        error_log("[repair_xz] {$label} ALTER failed: " . $e->getMessage());
        $out[] = ['label' => $label, 'state' => 'err', 'msg' => $e->getMessage()];
    }
}

$after = $ran ? read_status($pdo) : $before;
$all_ok = $ran && $after['daily_reports'] && $after['daily_report_items'];

render_header('X/Z Rapor Tabloları Onarım');
?>

<style>
.rx-card  { max-width: 700px; }
.rx-row   { display: flex; align-items: baseline; gap: 10px; padding: 8px 0;
            border-bottom: 1px solid var(--border,#e5e7eb); font-size: .9rem; }
.rx-row:last-child { border-bottom: none; }
.rx-label { flex: 1; font-family: monospace; font-size: .85rem; }
.rx-badge { padding: 2px 10px; border-radius: 12px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.rx-ok    { background: #dcfce7; color: #166534; }
.rx-err   { background: #fee2e2; color: #991b1b; }
.rx-skip  { background: #f1f5f9; color: #475569; }
.rx-warn  { background: #fef9c3; color: #854d0e; }
.rx-msg   { font-size: .8rem; color: var(--text-muted,#666); max-width: 260px; word-break: break-word; }
.rx-alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: .88rem; line-height: 1.5; }
.rx-alert-warn { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.rx-alert-ok   { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.rx-alert-err  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>

<div class="page-head">
    <h1>🔧 X/Z Rapor Tabloları Onarım</h1>
</div>

<div class="card rx-card">

    <?php if ($ran && $all_ok): ?>
    <div class="rx-alert rx-alert-ok">
        ✓ Onarım tamamlandı. <code>daily_reports</code> ve <code>daily_report_items</code> tabloları hazır.
        X Raporu Al artık çalışmalı.
    </div>
    <?php elseif ($ran && !$all_ok): ?>
    <div class="rx-alert rx-alert-err">
        Tablolardan biri hâlâ oluşturulamadı. DB kullanıcısının <code>CREATE TABLE</code> yetkisi olmayabilir.
        phpMyAdmin → SQL sekmesini kullanın (aşağıdaki SQL bloğuna bakın).
    </div>
    <?php else: ?>
    <div class="rx-alert rx-alert-warn">
        ⚠ Bu işlem eksik rapor arşiv tablolarını ve kolonlarını oluşturur. <strong>Veri silmez.</strong>
        Mevcut tablolara dokunmaz.
    </div>
    <?php endif; ?>

    <?php if (!$ran): ?>
    <!-- Onarım öncesi durum -->
    <h3 style="margin:0 0 8px;font-size:.9rem;font-weight:600;color:var(--text-muted,#666);text-transform:uppercase;letter-spacing:.04em">Mevcut Durum</h3>
    <div style="margin-bottom:20px">
        <?php
        $status_items = [
            'daily_reports'       => 'daily_reports (tablo)',
            'daily_report_items'  => 'daily_report_items (tablo)',
            'kf_report_id'        => 'kantar_fisleri.report_id',
            'lr_report_id'        => 'loading_records.report_id',
            'lp_reported_at'      => 'loading_pallets.reported_at',
            'lp_reported_by'      => 'loading_pallets.reported_by',
            'lp_report_id'        => 'loading_pallets.report_id',
        ];
        foreach ($status_items as $key => $lbl): ?>
        <div class="rx-row">
            <span class="rx-label"><?= h($lbl) ?></span>
            <?php if ($before[$key]): ?>
            <span class="rx-badge rx-ok">VAR</span>
            <?php else: ?>
            <span class="rx-badge rx-warn">EKSİK</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button type="submit" class="btn btn-primary">🔧 Onarımı Çalıştır</button>
    </form>

    <?php else: ?>
    <!-- Onarım sonrası sonuçlar -->
    <h3 style="margin:0 0 8px;font-size:.9rem;font-weight:600;color:var(--text-muted,#666);text-transform:uppercase;letter-spacing:.04em">Onarım Sonuçları</h3>
    <div style="margin-bottom:20px">
        <?php foreach ($results as $r): ?>
        <div class="rx-row">
            <span class="rx-label"><?= h($r['label']) ?></span>
            <span class="rx-badge rx-<?= h($r['state']) ?>"><?= $r['state'] === 'ok' ? 'OLUŞTURULDU / EKLENDİ' : ($r['state'] === 'skip' ? 'VAR' : 'HATA') ?></span>
            <?php if ($r['state'] === 'err'): ?>
            <span class="rx-msg"><?= h($r['msg']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Son durum -->
    <h3 style="margin:0 0 8px;font-size:.9rem;font-weight:600;color:var(--text-muted,#666);text-transform:uppercase;letter-spacing:.04em">Son Durum</h3>
    <div style="margin-bottom:20px">
        <?php
        $final_items = [
            'daily_reports'      => 'daily_reports (tablo)',
            'daily_report_items' => 'daily_report_items (tablo)',
            'kf_report_id'       => 'kantar_fisleri.report_id',
            'lr_report_id'       => 'loading_records.report_id',
            'lp_reported_at'     => 'loading_pallets.reported_at',
            'lp_reported_by'     => 'loading_pallets.reported_by',
            'lp_report_id'       => 'loading_pallets.report_id',
        ];
        foreach ($final_items as $key => $lbl): ?>
        <div class="rx-row">
            <span class="rx-label"><?= h($lbl) ?></span>
            <span class="rx-badge <?= $after[$key] ? 'rx-ok' : 'rx-err' ?>"><?= $after[$key] ? 'VAR' : 'EKSİK' ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$all_ok): ?>
    <!-- Manuel SQL fallback -->
    <details style="margin-bottom:16px">
        <summary style="cursor:pointer;font-size:.88rem;font-weight:600;padding:8px 0">
            Manuel SQL (phpMyAdmin için)
        </summary>
        <pre style="background:#f8f9fa;border:1px solid var(--border,#e5e7eb);border-radius:6px;padding:12px;font-size:.75rem;overflow-x:auto;margin-top:8px;user-select:all">CREATE TABLE IF NOT EXISTS `daily_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `report_type` VARCHAR(5) NOT NULL,
  `report_date` DATE NULL, `date_from` DATE NULL, `date_to` DATE NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `note` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT NULL, `closed_at` DATETIME NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'final',
  `snapshot_json` LONGTEXT NULL, `pdf_path` VARCHAR(500) NULL,
  INDEX `idx_dr_type` (`report_type`), INDEX `idx_dr_date` (`report_date`),
  INDEX `idx_dr_created` (`created_at`), INDEX `idx_dr_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_report_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `report_id` INT NOT NULL,
  `item_type` VARCHAR(30) NOT NULL,
  `source_table` VARCHAR(60) NOT NULL,
  `source_id` INT NOT NULL,
  `source_detail_id` INT NULL,
  `snapshot_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_dri_report` (`report_id`), INDEX `idx_dri_type` (`item_type`),
  INDEX `idx_dri_source` (`source_table`, `source_id`),
  INDEX `idx_dri_detail` (`source_detail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
    </details>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="daily_report_archive.php" class="btn btn-primary btn-sm">📁 Arşive Git</a>
        <a href="reports.php?type=gunluk" class="btn btn-sm">📅 Günlük Rapor'a Dön</a>
        <?php if (!$all_ok): ?>
        <form method="post" style="display:contents">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button type="submit" class="btn btn-ghost btn-sm">↺ Tekrar Dene</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php render_footer(); ?>
