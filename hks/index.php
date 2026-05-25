<?php
// =========================================================
// hks/index.php — HKS Bildirimleri Listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

// Auto-migration
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_settings` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(100) NOT NULL DEFAULT '',
      `password_enc` TEXT NOT NULL DEFAULT '',
      `service_password_enc` TEXT NOT NULL DEFAULT '',
      `security_word_enc` TEXT DEFAULT NULL,
      `environment` ENUM('test','live') NOT NULL DEFAULT 'test',
      `genel_wsdl_url` VARCHAR(500) NOT NULL DEFAULT '',
      `bildirim_wsdl_url` VARCHAR(500) NOT NULL DEFAULT '',
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_reference_stock` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `kunye_no` VARCHAR(100) NOT NULL DEFAULT '',
      `product_name` VARCHAR(200) NOT NULL DEFAULT '',
      `incoming_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
      `used_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
      `remaining_quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
      `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
      `source_notification_type` VARCHAR(50) NOT NULL DEFAULT '',
      `supplier_name` VARCHAR(200) DEFAULT NULL,
      `header_id` INT UNSIGNED DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_kunye_no` (`kunye_no`),
      INDEX `idx_remaining` (`remaining_quantity`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_headers` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `type` ENUM('alis','satis','ihracat','transfer','iade') NOT NULL DEFAULT 'alis',
      `buyer_name` VARCHAR(200) NOT NULL DEFAULT '',
      `vehicle_plate` VARCHAR(20) DEFAULT NULL,
      `driver_name` VARCHAR(100) DEFAULT NULL,
      `shipment_date` DATE NOT NULL,
      `origin_place` VARCHAR(200) DEFAULT NULL,
      `destination_place` VARCHAR(200) DEFAULT NULL,
      `supplier_name` VARCHAR(200) DEFAULT NULL,
      `note` TEXT DEFAULT NULL,
      `status` ENUM('draft','sent','error') NOT NULL DEFAULT 'draft',
      `hks_notification_no` VARCHAR(50) DEFAULT NULL,
      `last_error` TEXT DEFAULT NULL,
      `request_xml` LONGTEXT DEFAULT NULL,
      `response_xml` LONGTEXT DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `sent_at` DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_items` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `header_id` INT UNSIGNED NOT NULL,
      `reference_kunye_no` VARCHAR(100) NOT NULL DEFAULT '',
      `product_name` VARCHAR(200) NOT NULL DEFAULT '',
      `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
      `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
      `unit_price` DECIMAL(12,4) DEFAULT NULL,
      `stock_id` INT UNSIGNED DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_header_id` (`header_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_logs` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `header_id` INT UNSIGNED DEFAULT NULL,
      `action` VARCHAR(100) NOT NULL DEFAULT '',
      `message` TEXT NOT NULL DEFAULT '',
      `request_payload` LONGTEXT DEFAULT NULL,
      `response_payload` LONGTEXT DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_header_id` (`header_id`),
      INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$repo    = new HksRepository(db());
$headers = $repo->listHeaders(300);

// Özet sayaçlar
$cnt_draft = 0; $cnt_sent = 0; $cnt_err = 0;
foreach ($headers as $h) {
    match ($h['status']) {
        'draft' => $cnt_draft++,
        'sent'  => $cnt_sent++,
        'error' => $cnt_err++,
        default => null,
    };
}

render_header('HKS Bildirimleri');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🏛 HKS Bildirimleri</h1>
        <p class="muted">Toplam <?= count($headers) ?> bildirim</p>
    </div>
    <div class="page-head-actions">
        <a href="stock.php" class="btn btn-ghost">📦 Stok</a>
        <a href="settings.php" class="btn btn-ghost">Ayarlar</a>
        <a href="create_purchase.php" class="btn btn-ghost btn-lg">+ Alış</a>
        <a href="create_sales.php" class="btn btn-primary btn-lg">+ Satış</a>
    </div>
</div>

<?php if ($cnt_draft || $cnt_sent || $cnt_err): ?>
<div class="rpt-summary" style="margin-bottom:14px">
    <div class="rpt-sum-item"><span>Taslak</span><strong><?= $cnt_draft ?></strong></div>
    <div class="rpt-sum-item rpt-sum-highlight"><span>Gönderildi</span><strong><?= $cnt_sent ?></strong></div>
    <?php if ($cnt_err): ?>
    <div class="rpt-sum-item" style="border-color:var(--danger)"><span>Hata</span><strong style="color:var(--danger)"><?= $cnt_err ?></strong></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($headers)): ?>
<div class="empty">
    <p>Henüz HKS bildirimi yok.</p>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:8px">
        <a href="create_purchase.php" class="btn btn-ghost">+ Alış Bildirimi</a>
        <a href="create_sales.php" class="btn btn-primary">+ Satış Bildirimi</a>
    </div>
</div>
<?php else: ?>

<!-- PC tablo -->
<div class="table-wrap pc-only">
    <table class="data-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Tip</th>
            <th>Tarih</th>
            <th>Alıcı / Tedarikçi</th>
            <th>Plaka</th>
            <th>Kalem</th>
            <th>Durum</th>
            <th>Bildirim No</th>
            <th class="actions-col">İşlemler</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($headers as $h): ?>
        <tr>
            <td><?= (int)$h['id'] ?></td>
            <td><?= hks_h(hks_type_label($h['type'])) ?></td>
            <td><?= hks_h($h['shipment_date']) ?></td>
            <td><?= hks_h($h['buyer_name'] ?: $h['supplier_name'] ?: '—') ?></td>
            <td><?= hks_h($h['vehicle_plate'] ?? '—') ?></td>
            <td class="num"><?= (int)$h['item_count'] ?></td>
            <td><span class="hks-badge <?= hks_h(hks_status_class($h['status'])) ?>"><?= hks_h(hks_status_label($h['status'])) ?></span></td>
            <td><?= $h['hks_notification_no'] ? hks_h($h['hks_notification_no']) : '<span class="muted">—</span>' ?></td>
            <td class="actions-col"><a href="view.php?id=<?= (int)$h['id'] ?>" class="btn btn-sm btn-ghost">Görüntüle</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Mobil kartlar -->
<div class="mobile-only">
    <?php foreach ($headers as $h): ?>
    <div class="hks-card">
        <div class="hks-card-head">
            <div>
                <div class="hks-card-title"><?= hks_h(hks_type_label($h['type'])) ?> — <?= hks_h($h['buyer_name'] ?: $h['supplier_name'] ?: '—') ?></div>
                <div class="hks-card-meta"><?= hks_h($h['shipment_date']) ?> · <?= hks_h($h['vehicle_plate'] ?? '') ?> · <?= (int)$h['item_count'] ?> kalem</div>
            </div>
            <span class="hks-badge <?= hks_h(hks_status_class($h['status'])) ?>"><?= hks_h(hks_status_label($h['status'])) ?></span>
        </div>
        <?php if ($h['hks_notification_no']): ?>
        <div style="font-size:.82rem;color:var(--success)">Bildirim No: <?= hks_h($h['hks_notification_no']) ?></div>
        <?php endif; ?>
        <div class="hks-card-foot">
            <span style="font-size:.8rem;color:var(--muted)">#<?= (int)$h['id'] ?></span>
            <a href="view.php?id=<?= (int)$h['id'] ?>" class="btn btn-sm btn-ghost">Görüntüle →</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
</content>
</invoke>