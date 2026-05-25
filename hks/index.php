<?php
// =========================================================
// hks/index.php — HKS Bildirimleri Listesi
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

// Auto-migration: tabloları oluştur
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_notifications` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `notification_type` VARCHAR(50) NOT NULL DEFAULT '',
      `product_name` VARCHAR(200) NOT NULL DEFAULT '',
      `product_code` VARCHAR(50) DEFAULT NULL,
      `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0,
      `unit` VARCHAR(20) NOT NULL DEFAULT 'KG',
      `package_type` VARCHAR(50) NOT NULL DEFAULT '',
      `supplier_name` VARCHAR(200) DEFAULT NULL,
      `buyer_name` VARCHAR(200) DEFAULT NULL,
      `shipment_date` DATE NOT NULL,
      `vehicle_plate` VARCHAR(20) DEFAULT NULL,
      `driver_name` VARCHAR(100) DEFAULT NULL,
      `origin_place` VARCHAR(200) DEFAULT NULL,
      `destination_place` VARCHAR(200) DEFAULT NULL,
      `note` TEXT DEFAULT NULL,
      `status` ENUM('draft','sent','error') NOT NULL DEFAULT 'draft',
      `hks_notification_no` VARCHAR(50) DEFAULT NULL,
      `hks_tag_no` VARCHAR(50) DEFAULT NULL,
      `last_error` TEXT DEFAULT NULL,
      `request_xml` LONGTEXT DEFAULT NULL,
      `response_xml` LONGTEXT DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `sent_at` DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `hks_logs` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `notification_id` INT UNSIGNED DEFAULT NULL,
      `action` VARCHAR(100) NOT NULL DEFAULT '',
      `message` TEXT NOT NULL DEFAULT '',
      `request_payload` LONGTEXT DEFAULT NULL,
      `response_payload` LONGTEXT DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_notification_id` (`notification_id`),
      INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // Migration hatası sessizce yut — tablo zaten varsa sorun yok
}

$repo = new HksRepository(db());
$notifications = $repo->listNotifications(200);

render_header('HKS Bildirimleri');
render_flash();
?>
<div class="page-head">
    <div>
        <h1>🏛 HKS Bildirimleri</h1>
        <p class="muted">Toplam <?= count($notifications) ?> bildirim</p>
    </div>
    <div class="page-head-actions">
        <a href="settings.php" class="btn btn-ghost btn-lg">Ayarlar</a>
        <a href="create.php" class="btn btn-primary btn-lg">+ Yeni Bildirim</a>
    </div>
</div>

<?php if (empty($notifications)): ?>
    <div class="empty">
        <p>Henüz HKS bildirimi yok.</p>
        <a href="create.php" class="btn btn-primary">İlk bildirimi oluştur</a>
    </div>
<?php else: ?>

    <!-- PC: tablo -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Tip</th>
                <th>Ürün</th>
                <th>Tarih</th>
                <th>Alıcı</th>
                <th>Plaka</th>
                <th>Durum</th>
                <th>Bildirim No</th>
                <th class="actions-col">İşlemler</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($notifications as $n): ?>
                <tr>
                    <td><strong>#<?= (int)$n['id'] ?></strong></td>
                    <td><?= hks_h($n['notification_type']) ?></td>
                    <td><?= hks_h($n['product_name']) ?></td>
                    <td><?= hks_h(fmt_date($n['shipment_date'])) ?></td>
                    <td><?= hks_h($n['buyer_name'] ?? '—') ?></td>
                    <td><?= hks_h($n['vehicle_plate'] ?? '—') ?></td>
                    <td>
                        <span class="hks-badge <?= hks_h(hks_status_class($n['status'])) ?>">
                            <?= hks_h(hks_status_label($n['status'])) ?>
                        </span>
                    </td>
                    <td><?= hks_h($n['hks_notification_no'] ?? '—') ?></td>
                    <td class="actions-col">
                        <a class="btn btn-sm" href="view.php?id=<?= (int)$n['id'] ?>">Görüntüle</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobil: kart -->
    <div class="mobile-only">
        <?php foreach ($notifications as $n): ?>
            <div class="hks-card">
                <div class="hks-card-head">
                    <div>
                        <div class="hks-card-title"><?= hks_h($n['product_name']) ?></div>
                        <div class="hks-card-meta">#<?= (int)$n['id'] ?> · <?= hks_h($n['notification_type']) ?> · <?= hks_h(fmt_date($n['shipment_date'])) ?></div>
                    </div>
                    <span class="hks-badge <?= hks_h(hks_status_class($n['status'])) ?>">
                        <?= hks_h(hks_status_label($n['status'])) ?>
                    </span>
                </div>
                <?php if ($n['buyer_name'] || $n['vehicle_plate']): ?>
                <div class="hks-card-meta" style="margin-bottom:6px">
                    <?php if ($n['buyer_name']): ?>
                    <span>Alıcı: <?= hks_h($n['buyer_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($n['vehicle_plate']): ?>
                    <span style="margin-left:8px">Plaka: <?= hks_h($n['vehicle_plate']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="hks-card-foot">
                    <?php if ($n['hks_notification_no']): ?>
                    <span style="font-size:.8rem;color:var(--success)">
                        Bildirim No: <?= hks_h($n['hks_notification_no']) ?>
                    </span>
                    <?php else: ?>
                    <span></span>
                    <?php endif; ?>
                    <a class="btn btn-sm" href="view.php?id=<?= (int)$n['id'] ?>">Görüntüle</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php render_footer(); ?>
