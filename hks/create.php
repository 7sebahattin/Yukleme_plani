<?php
// =========================================================
// hks/create.php — Yeni HKS Bildirimi Oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';
require_once __DIR__ . '/HksClient.php';

$repo   = new HksRepository(db());
$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $action = $_POST['submit_action'] ?? 'draft';

    // Veri topla & temizle
    $data = [
        'notification_type' => trim($_POST['notification_type'] ?? ''),
        'product_name'      => trim($_POST['product_name'] ?? ''),
        'product_code'      => trim($_POST['product_code'] ?? ''),
        'quantity'          => hks_qty($_POST['quantity'] ?? 0),
        'unit'              => trim($_POST['unit'] ?? 'KG'),
        'package_type'      => trim($_POST['package_type'] ?? ''),
        'supplier_name'     => trim($_POST['supplier_name'] ?? ''),
        'buyer_name'        => trim($_POST['buyer_name'] ?? ''),
        'shipment_date'     => trim($_POST['shipment_date'] ?? ''),
        'vehicle_plate'     => hks_plate(trim($_POST['vehicle_plate'] ?? '')),
        'driver_name'       => trim($_POST['driver_name'] ?? ''),
        'origin_place'      => trim($_POST['origin_place'] ?? ''),
        'destination_place' => trim($_POST['destination_place'] ?? ''),
        'note'              => trim($_POST['note'] ?? ''),
    ];
    $old = $data;

    // Validasyon
    if ($data['notification_type'] === '') $errors[] = 'Bildirim tipi zorunludur.';
    if ($data['product_name'] === '')      $errors[] = 'Ürün adı zorunludur.';
    if ($data['quantity'] <= 0)            $errors[] = 'Miktar 0\'dan büyük olmalıdır.';
    if ($data['package_type'] === '')      $errors[] = 'Ambalaj tipi zorunludur.';
    if ($data['shipment_date'] === '')     $errors[] = 'Sevk tarihi zorunludur.';

    if (empty($errors)) {
        $id = $repo->createNotification($data);

        if ($action === 'send') {
            $client = new HksClient($repo);
            $result = $client->sendNotification($id);
            if ($result['ok']) {
                set_flash('success', 'Bildirim HKS\'ye başarıyla gönderildi.');
            } else {
                set_flash('error', 'Bildirim taslak olarak kaydedildi. Gönderim: ' . ($result['message'] ?? ''));
            }
        } else {
            set_flash('success', 'Bildirim taslak olarak kaydedildi.');
        }

        header('Location: view.php?id=' . $id);
        exit;
    }
}

$notification_types = [
    'Satış Bildirimi',
    'Alış Bildirimi',
    'Transfer Bildirimi',
    'İade Bildirimi',
];

$package_types = [
    'Kasa', 'Palet', 'Çuval', 'Koltu', 'Bidon', 'Diğer',
];

render_header('Yeni HKS Bildirimi');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🏛 Yeni HKS Bildirimi</h1>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← Listeye Dön</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="hks-error-box">
        <strong>Lütfen aşağıdaki hataları düzeltin:</strong>
        <ul style="margin:6px 0 0 16px;padding:0">
            <?php foreach ($errors as $err): ?>
                <li><?= hks_h($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="create.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <!-- Bildirim Tipi & Ürün -->
    <div class="form-section">
        <h2 class="form-section-title">Bildirim Bilgileri</h2>

        <div class="form-group">
            <label for="notification_type">Bildirim Tipi <span class="req">*</span></label>
            <select id="notification_type" name="notification_type" required>
                <option value="">— Seçin —</option>
                <?php foreach ($notification_types as $nt): ?>
                    <option value="<?= hks_h($nt) ?>"
                        <?= (($old['notification_type'] ?? '') === $nt) ? 'selected' : '' ?>>
                        <?= hks_h($nt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="product_name">Ürün Adı <span class="req">*</span></label>
            <input type="text" id="product_name" name="product_name"
                   value="<?= hks_h($old['product_name'] ?? '') ?>"
                   placeholder="Ürün adını girin" required>
        </div>

        <div class="form-group">
            <label for="product_code">Ürün Kodu</label>
            <input type="text" id="product_code" name="product_code"
                   value="<?= hks_h($old['product_code'] ?? '') ?>"
                   placeholder="Opsiyonel">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="quantity">Miktar <span class="req">*</span></label>
                <input type="number" id="quantity" name="quantity" step="0.001" min="0.001"
                       value="<?= hks_h((string)($old['quantity'] ?? '')) ?>"
                       placeholder="0.000" required>
            </div>
            <div class="form-group">
                <label for="unit">Birim <span class="req">*</span></label>
                <select id="unit" name="unit">
                    <?php foreach (['KG', 'ADET', 'TON'] as $u): ?>
                        <option value="<?= hks_h($u) ?>"
                            <?= (($old['unit'] ?? 'KG') === $u) ? 'selected' : '' ?>>
                            <?= hks_h($u) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label for="package_type">Ambalaj Tipi <span class="req">*</span></label>
            <select id="package_type" name="package_type" required>
                <option value="">— Seçin —</option>
                <?php foreach ($package_types as $pt): ?>
                    <option value="<?= hks_h($pt) ?>"
                        <?= (($old['package_type'] ?? '') === $pt) ? 'selected' : '' ?>>
                        <?= hks_h($pt) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Tedarik Zinciri -->
    <div class="form-section">
        <h2 class="form-section-title">Tedarik Zinciri</h2>

        <div class="form-group">
            <label for="supplier_name">Tedarikçi / Satıcı</label>
            <input type="text" id="supplier_name" name="supplier_name"
                   value="<?= hks_h($old['supplier_name'] ?? '') ?>"
                   placeholder="Tedarikçi adı">
        </div>

        <div class="form-group">
            <label for="buyer_name">Alıcı</label>
            <input type="text" id="buyer_name" name="buyer_name"
                   value="<?= hks_h($old['buyer_name'] ?? '') ?>"
                   placeholder="Alıcı adı">
        </div>
    </div>

    <!-- Sevkiyat Bilgileri -->
    <div class="form-section">
        <h2 class="form-section-title">Sevkiyat Bilgileri</h2>

        <div class="form-group">
            <label for="shipment_date">Sevk Tarihi <span class="req">*</span></label>
            <input type="date" id="shipment_date" name="shipment_date"
                   value="<?= hks_h($old['shipment_date'] ?? date('Y-m-d')) ?>" required>
        </div>

        <div class="form-group">
            <label for="vehicle_plate">Araç Plakası</label>
            <input type="text" id="vehicle_plate" name="vehicle_plate"
                   value="<?= hks_h($old['vehicle_plate'] ?? '') ?>"
                   placeholder="34ABC123" style="text-transform:uppercase">
        </div>

        <div class="form-group">
            <label for="driver_name">Şoför Adı</label>
            <input type="text" id="driver_name" name="driver_name"
                   value="<?= hks_h($old['driver_name'] ?? '') ?>"
                   placeholder="Şoför adı soyadı">
        </div>

        <div class="form-group">
            <label for="origin_place">Çıkış Yeri</label>
            <input type="text" id="origin_place" name="origin_place"
                   value="<?= hks_h($old['origin_place'] ?? '') ?>"
                   placeholder="Yükleme noktası">
        </div>

        <div class="form-group">
            <label for="destination_place">Varış Yeri</label>
            <input type="text" id="destination_place" name="destination_place"
                   value="<?= hks_h($old['destination_place'] ?? '') ?>"
                   placeholder="Teslimat noktası">
        </div>

        <div class="form-group">
            <label for="note">Not</label>
            <textarea id="note" name="note" rows="3"
                      placeholder="Opsiyonel not..."><?= hks_h($old['note'] ?? '') ?></textarea>
        </div>
    </div>

    <!-- Butonlar -->
    <div class="form-actions">
        <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
        <button type="submit" name="submit_action" value="draft" class="btn btn-lg">
            Taslak Kaydet
        </button>
        <button type="submit" name="submit_action" value="send" class="btn btn-primary btn-lg">
            HKS'ye Gönder
        </button>
    </div>
</form>

<?php render_footer(); ?>
