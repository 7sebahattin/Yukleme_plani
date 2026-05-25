<?php
// =========================================================
// hks/create_sales.php — Satış / İhracat Bildirimi
// Kullanıcı referans künyeleri checkbox ile seçer.
// Seçilen künyelerden stok düşülür.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$repo = new HksRepository(db());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $type    = trim($_POST['type']       ?? 'satis');
    $buyer   = trim($_POST['buyer_name'] ?? '');
    $plate   = hks_plate($_POST['vehicle_plate'] ?? '');
    $driver  = trim($_POST['driver_name']?? '');
    $s_date  = trim($_POST['shipment_date'] ?? '');
    $origin  = trim($_POST['origin_place']  ?? '');
    $dest    = trim($_POST['destination_place'] ?? '');
    $note    = trim($_POST['note'] ?? '');

    // Seçili künye satırları: items[stock_id] = quantity
    $raw_items = (array)($_POST['items'] ?? []);
    $sel_items = [];
    foreach ($raw_items as $stock_id => $qty_str) {
        $qty = hks_qty($qty_str);
        if ($qty > 0) $sel_items[(int)$stock_id] = $qty;
    }

    if (!in_array($type, ['satis','ihracat','transfer','iade'], true)) $type = 'satis';
    if ($buyer  === '') $errors[] = 'Alıcı zorunludur.';
    if ($s_date === '') $errors[] = 'Sevk tarihi zorunludur.';
    if (empty($sel_items)) $errors[] = 'En az bir referans künye seçip miktar girin.';

    // Stok yeterliliği kontrolü
    if (empty($errors)) {
        foreach ($sel_items as $stock_id => $qty) {
            $stk = $repo->getStock($stock_id);
            if (!$stk) { $errors[] = "Stok #$stock_id bulunamadı."; break; }
            if ((float)$stk['remaining_quantity'] < $qty) {
                $errors[] = "Künye {$stk['kunye_no']}: kalan miktar ({$stk['remaining_quantity']} {$stk['unit']}) yetersiz (talep: $qty).";
            }
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $h_id = $repo->createHeader([
                'type'              => $type,
                'buyer_name'        => $buyer,
                'vehicle_plate'     => $plate,
                'driver_name'       => $driver,
                'shipment_date'     => $s_date,
                'origin_place'      => $origin,
                'destination_place' => $dest,
                'supplier_name'     => null,
                'note'              => $note,
            ]);

            foreach ($sel_items as $stock_id => $qty) {
                $stk = $repo->getStock($stock_id);
                $repo->createItem([
                    'header_id'          => $h_id,
                    'reference_kunye_no' => $stk['kunye_no'],
                    'product_name'       => $stk['product_name'],
                    'quantity'           => $qty,
                    'unit'               => $stk['unit'],
                    'unit_price'         => isset($_POST['unit_price'][$stock_id]) ? hks_qty($_POST['unit_price'][$stock_id]) : null,
                    'stock_id'           => $stock_id,
                ]);
                $repo->deductStock($stock_id, $qty);
            }

            $repo->addLog($h_id, 'create_sales',
                count($sel_items) . ' künye ile satış bildirimi oluşturuldu.');

            $pdo->commit();
            set_flash('success', 'Satış bildirimi oluşturuldu ve stoklar güncellendi.');
            header('Location: view.php?id=' . $h_id);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

$available_stock = $repo->listStock(true); // only remaining > 0
$today = date('Y-m-d');

render_header('Satış Bildirimi');
render_flash();
?>

<div class="page-head">
    <div><h1>📤 Satış Bildirimi</h1><p class="muted">Referans künyeleri seçip miktarı girin</p></div>
    <div class="page-head-actions"><a href="index.php" class="btn btn-ghost">← İptal</a></div>
</div>

<?php if ($errors): ?>
<div class="flash flash-error"><?= implode('<br>', array_map('hks_h', $errors)) ?></div>
<?php endif; ?>

<form method="post" id="salesForm">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<section class="card">
    <div class="card-head"><h2>Bildirim Bilgileri</h2></div>
    <div class="card-body">
        <div class="hks-form-grid">
            <label>Bildirim Tipi
                <select name="type">
                    <option value="satis"    <?= ($_POST['type']??'satis')==='satis'?'selected':'' ?>>Satış</option>
                    <option value="ihracat"  <?= ($_POST['type']??'')==='ihracat'?'selected':'' ?>>İhracat</option>
                    <option value="transfer" <?= ($_POST['type']??'')==='transfer'?'selected':'' ?>>Transfer</option>
                    <option value="iade"     <?= ($_POST['type']??'')==='iade'?'selected':'' ?>>İade</option>
                </select>
            </label>
            <label>Sevk Tarihi <span class="req">*</span>
                <input type="date" name="shipment_date" value="<?= hks_h($_POST['shipment_date'] ?? $today) ?>" required>
            </label>
            <label>Alıcı <span class="req">*</span>
                <input type="text" name="buyer_name" value="<?= hks_h($_POST['buyer_name'] ?? '') ?>"
                       placeholder="Alıcı adı / firma" required autocomplete="off">
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

<!-- Referans Künye Seçimi -->
<section class="card">
    <div class="card-head">
        <h2>Referans Künye Seçimi</h2>
        <small class="muted">Göndermek istediğiniz künyeler için miktar girin</small>
    </div>
    <div class="card-body" style="padding:0">

    <?php if (empty($available_stock)): ?>
        <div style="padding:20px;text-align:center;color:var(--muted)">
            Kullanılabilir referans künye yok.
            <a href="create_purchase.php" style="display:block;margin-top:8px">+ Alış Bildirimi ile künye ekleyin</a>
        </div>
    <?php else: ?>

        <!-- PC tablo -->
        <div class="table-wrap pc-only" style="border:none">
            <table class="data-table">
                <thead>
                <tr>
                    <th style="width:32px"></th>
                    <th>Künye No</th>
                    <th>Ürün</th>
                    <th class="num">Toplam</th>
                    <th class="num">Kullanılan</th>
                    <th class="num" style="color:var(--success)">Kalan</th>
                    <th>Birim</th>
                    <th style="width:130px">Gönder (Miktar)</th>
                    <th style="width:110px">Birim Fiyat</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($available_stock as $s):
                    $prev_qty = $_POST['items'][$s['id']] ?? '';
                ?>
                <tr class="hks-stock-row" id="stock-row-<?= (int)$s['id'] ?>">
                    <td style="text-align:center">
                        <input type="checkbox" class="hks-stock-chk"
                               data-id="<?= (int)$s['id'] ?>"
                               data-max="<?= (float)$s['remaining_quantity'] ?>"
                               <?= $prev_qty !== '' ? 'checked' : '' ?>>
                    </td>
                    <td><strong><?= hks_h($s['kunye_no']) ?></strong></td>
                    <td><?= hks_h($s['product_name']) ?></td>
                    <td class="num"><?= number_format((float)$s['incoming_quantity'], 3, ',', '.') ?></td>
                    <td class="num muted"><?= number_format((float)$s['used_quantity'], 3, ',', '.') ?></td>
                    <td class="num" style="color:var(--success);font-weight:600"><?= number_format((float)$s['remaining_quantity'], 3, ',', '.') ?></td>
                    <td><?= hks_h($s['unit']) ?></td>
                    <td>
                        <input type="text" name="items[<?= (int)$s['id'] ?>]"
                               class="hks-qty-input"
                               value="<?= hks_h($prev_qty) ?>"
                               placeholder="0"
                               inputmode="decimal"
                               data-id="<?= (int)$s['id'] ?>"
                               style="width:100%;text-align:right;<?= $prev_qty===''?'opacity:.4':'' ?>"
                               <?= $prev_qty === '' ? 'disabled' : '' ?>>
                    </td>
                    <td>
                        <input type="text" name="unit_price[<?= (int)$s['id'] ?>]"
                               value="<?= hks_h($_POST['unit_price'][$s['id']] ?? '') ?>"
                               placeholder="—"
                               inputmode="decimal"
                               style="width:100%;text-align:right;<?= $prev_qty===''?'opacity:.4':'' ?>"
                               <?= $prev_qty === '' ? 'disabled' : '' ?>>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobil kartlar -->
        <div class="mobile-only" style="padding:12px;display:flex;flex-direction:column;gap:10px">
        <?php foreach ($available_stock as $s):
            $prev_qty = $_POST['items'][$s['id']] ?? '';
        ?>
        <div class="hks-card" style="margin-bottom:0">
            <div class="hks-card-head">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1">
                    <input type="checkbox" class="hks-stock-chk"
                           data-id="<?= (int)$s['id'] ?>"
                           data-max="<?= (float)$s['remaining_quantity'] ?>"
                           style="width:20px;height:20px;flex-shrink:0"
                           <?= $prev_qty !== '' ? 'checked' : '' ?>>
                    <div>
                        <div class="hks-card-title"><?= hks_h($s['kunye_no']) ?></div>
                        <div class="hks-card-meta"><?= hks_h($s['product_name']) ?></div>
                    </div>
                </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;font-size:.8rem;margin-bottom:8px">
                <div><span style="color:var(--muted)">Toplam</span><br><strong><?= number_format((float)$s['incoming_quantity'],3,',','.') ?></strong></div>
                <div><span style="color:var(--muted)">Kullanılan</span><br><strong><?= number_format((float)$s['used_quantity'],3,',','.') ?></strong></div>
                <div><span style="color:var(--success)">Kalan</span><br><strong><?= number_format((float)$s['remaining_quantity'],3,',','.') ?></strong></div>
            </div>
            <div id="stock-row-<?= (int)$s['id'] ?>" style="display:<?= $prev_qty !== '' ? 'grid' : 'none' ?>;grid-template-columns:1fr 1fr;gap:8px">
                <label style="font-size:.85rem">Gönder (Miktar)
                    <input type="text" name="items[<?= (int)$s['id'] ?>]"
                           class="hks-qty-input"
                           value="<?= hks_h($prev_qty) ?>"
                           placeholder="0"
                           inputmode="decimal"
                           data-id="<?= (int)$s['id'] ?>"
                           <?= $prev_qty === '' ? 'disabled' : '' ?>>
                </label>
                <label style="font-size:.85rem">Birim Fiyat
                    <input type="text" name="unit_price[<?= (int)$s['id'] ?>]"
                           value="<?= hks_h($_POST['unit_price'][$s['id']] ?? '') ?>"
                           placeholder="—"
                           inputmode="decimal"
                           <?= $prev_qty === '' ? 'disabled' : '' ?>>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

    <?php endif; ?>
    </div>
</section>

<!-- Özet -->
<div id="salesSummary" style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;margin-bottom:14px;display:none">
    <strong>Seçili: </strong><span id="summaryText">—</span>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
    <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
    <button type="submit" class="btn btn-primary btn-lg" style="flex:1;min-width:180px">
        📤 Satış Bildirimi Kaydet
    </button>
</div>

</form>

<script>
(function () {
    var chks = document.querySelectorAll('.hks-stock-chk');

    chks.forEach(function (chk) {
        chk.addEventListener('change', function () {
            var id   = chk.dataset.id;
            var max  = parseFloat(chk.dataset.max) || 0;
            // PC: row'daki input'lar
            var inputs = document.querySelectorAll('[name="items[' + id + ']"], [name="unit_price[' + id + ']"]');
            // Mobil: gizli/gösterilen kutu
            var mobileBox = document.getElementById('stock-row-' + id);

            if (chk.checked) {
                inputs.forEach(function (inp) {
                    inp.disabled = false;
                    inp.style.opacity = '1';
                    if (inp.name === 'items[' + id + ']' && !inp.value) {
                        inp.value = max.toFixed(3).replace('.', ',');
                    }
                });
                if (mobileBox) mobileBox.style.display = 'grid';
            } else {
                inputs.forEach(function (inp) {
                    inp.disabled = true;
                    inp.style.opacity = '.4';
                    inp.value = '';
                });
                if (mobileBox) mobileBox.style.display = 'none';
            }
            updateSummary();
        });
    });

    function updateSummary() {
        var selected = [];
        chks.forEach(function (chk) {
            if (!chk.checked) return;
            var id  = chk.dataset.id;
            var inp = document.querySelector('[name="items[' + id + ']"]');
            var qty = inp ? parseFloat(String(inp.value).replace(',', '.')) || 0 : 0;
            if (qty > 0) selected.push(qty);
        });
        var summaryEl = document.getElementById('salesSummary');
        var textEl    = document.getElementById('summaryText');
        if (selected.length) {
            summaryEl.style.display = 'block';
            textEl.textContent = selected.length + ' künye, toplam ' +
                selected.reduce(function (a, b) { return a + b; }, 0).toFixed(3).replace('.', ',') + ' ' +
                (document.querySelector('select[name="unit"]') || { value: 'KG' }).value;
        } else {
            summaryEl.style.display = 'none';
        }
    }

    // Miktar değişince özet güncelle
    document.querySelectorAll('.hks-qty-input').forEach(function (inp) {
        inp.addEventListener('input', updateSummary);
    });
})();
</script>

<?php render_footer(); ?>
