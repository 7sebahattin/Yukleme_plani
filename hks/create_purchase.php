<?php
// =========================================================
// hks/create_purchase.php — Alış / Giriş Bildirimi
// Kaydedilince hks_reference_stock girişi oluşur.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$repo = new HksRepository(db());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $type        = trim($_POST['type']        ?? 'alis');
    $kunye_no    = trim($_POST['kunye_no']    ?? '');
    $product     = trim($_POST['product_name']?? '');
    $qty         = hks_qty($_POST['quantity'] ?? 0);
    $unit        = trim($_POST['unit']        ?? 'KG');
    $supplier    = trim($_POST['supplier_name']?? '');
    $buyer       = trim($_POST['buyer_name']  ?? '');
    $plate       = hks_plate($_POST['vehicle_plate'] ?? '');
    $driver      = trim($_POST['driver_name'] ?? '');
    $s_date      = trim($_POST['shipment_date']?? '');
    $origin      = trim($_POST['origin_place']?? '');
    $dest        = trim($_POST['destination_place'] ?? '');
    $note        = trim($_POST['note']        ?? '');

    if ($kunye_no === '') $errors[] = 'Künye No zorunludur.';
    if ($product  === '') $errors[] = 'Ürün adı zorunludur.';
    if ($qty <= 0)        $errors[] = 'Miktar 0\'dan büyük olmalıdır.';
    if ($s_date   === '') $errors[] = 'Sevk tarihi zorunludur.';
    if (!in_array($type, ['alis','transfer','iade'], true)) $type = 'alis';

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Header oluştur
            $h_id = $repo->createHeader([
                'type'               => $type,
                'buyer_name'         => $buyer,
                'vehicle_plate'      => $plate,
                'driver_name'        => $driver,
                'shipment_date'      => $s_date,
                'origin_place'       => $origin,
                'destination_place'  => $dest,
                'supplier_name'      => $supplier,
                'note'               => $note,
            ]);

            // Item oluştur
            $repo->createItem([
                'header_id'           => $h_id,
                'reference_kunye_no'  => $kunye_no,
                'product_name'        => $product,
                'quantity'            => $qty,
                'unit'                => $unit,
                'stock_id'            => null,
            ]);

            // Referans stok oluştur
            $repo->createStock([
                'kunye_no'                   => $kunye_no,
                'product_name'               => $product,
                'incoming_quantity'          => $qty,
                'unit'                       => $unit,
                'source_notification_type'   => $type,
                'supplier_name'              => $supplier ?: null,
                'header_id'                  => $h_id,
            ]);

            $repo->addLog($h_id, 'create_purchase',
                "Alış bildirimi oluşturuldu. Künye: $kunye_no, Miktar: $qty $unit");

            $pdo->commit();
            set_flash('success', 'Alış bildirimi ve stok girişi oluşturuldu.');
            header('Location: view.php?id=' . $h_id);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

$today = date('Y-m-d');
render_header('Yeni Alış Bildirimi');
render_flash();
?>

<div class="page-head">
    <div><h1>📥 Alış Bildirimi</h1><p class="muted">Referans künye stok girişi oluşturur</p></div>
    <div class="page-head-actions"><a href="index.php" class="btn btn-ghost">← İptal</a></div>
</div>

<?php if ($errors): ?>
<div class="flash flash-error"><?= implode('<br>', array_map('hks_h', $errors)) ?></div>
<?php endif; ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<section class="card">
    <div class="card-head"><h2>Bildirim Tipi</h2></div>
    <div class="card-body">
        <div class="hks-form-grid">
            <label>Bildirim Tipi
                <select name="type">
                    <option value="alis"     <?= ($_POST['type']??'alis')==='alis'?'selected':'' ?>>Alış</option>
                    <option value="transfer" <?= ($_POST['type']??'')==='transfer'?'selected':'' ?>>Transfer</option>
                    <option value="iade"     <?= ($_POST['type']??'')==='iade'?'selected':'' ?>>İade</option>
                </select>
            </label>
            <label>Sevk Tarihi <span class="req">*</span>
                <input type="date" name="shipment_date" value="<?= hks_h($_POST['shipment_date'] ?? $today) ?>" required>
            </label>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>Referans Künye</h2></div>
    <div class="card-body">
        <div class="hks-form-grid">
            <label>Künye No <span class="req">*</span>
                <input type="text" name="kunye_no" value="<?= hks_h($_POST['kunye_no'] ?? '') ?>"
                       placeholder="HKS'den gelen künye numarası" required autocomplete="off">
            </label>
            <label>Ürün Adı <span class="req">*</span>
                <input type="text" name="product_name" value="<?= hks_h($_POST['product_name'] ?? '') ?>"
                       placeholder="Örn: Kayısı, Elma..." required autocomplete="off">
            </label>
            <label>Miktar <span class="req">*</span>
                <input type="text" name="quantity" value="<?= hks_h($_POST['quantity'] ?? '') ?>"
                       placeholder="0" inputmode="decimal" required autocomplete="off">
            </label>
            <label>Birim
                <select name="unit">
                    <?php foreach (['KG','ADET','TON','KUTU','KASA'] as $u): ?>
                    <option value="<?= $u ?>" <?= ($_POST['unit']??'KG')===$u?'selected':'' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-head"><h2>Taşıma Bilgileri</h2></div>
    <div class="card-body">
        <div class="hks-form-grid">
            <label>Tedarikçi / Kaynak
                <input type="text" name="supplier_name" value="<?= hks_h($_POST['supplier_name'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Alıcı / Hedef
                <input type="text" name="buyer_name" value="<?= hks_h($_POST['buyer_name'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Araç Plakası
                <input type="text" name="vehicle_plate" value="<?= hks_h($_POST['vehicle_plate'] ?? '') ?>"
                       style="text-transform:uppercase" autocomplete="off">
            </label>
            <label>Şoför
                <input type="text" name="driver_name" value="<?= hks_h($_POST['driver_name'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Çıkış Yeri
                <input type="text" name="origin_place" value="<?= hks_h($_POST['origin_place'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Varış Yeri
                <input type="text" name="destination_place" value="<?= hks_h($_POST['destination_place'] ?? '') ?>" autocomplete="off">
            </label>
            <label class="hks-form-full">Açıklama / Not
                <textarea name="note" rows="2"><?= hks_h($_POST['note'] ?? '') ?></textarea>
            </label>
        </div>
    </div>
</section>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
    <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
    <button type="submit" class="btn btn-primary btn-lg" style="flex:1;min-width:160px">
        📥 Alış Bildirimi Kaydet
    </button>
</div>

</form>
<?php render_footer(); ?>
