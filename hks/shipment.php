<?php
// =========================================================
// hks/shipment.php — Hızlı Araç Sevk Operasyon Paneli
// Araç bazlı, arama-ekle-gönder akışı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$repo   = new HksRepository(db());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $type   = trim($_POST['type']              ?? 'satis');
    $buyer  = trim($_POST['buyer_name']        ?? '');
    $plate  = hks_plate($_POST['vehicle_plate']?? '');
    $driver = trim($_POST['driver_name']       ?? '');
    $s_date = trim($_POST['shipment_date']     ?? '');
    $origin = trim($_POST['origin_place']      ?? '');
    $dest   = trim($_POST['destination_place'] ?? '');
    $note   = trim($_POST['note']              ?? '');

    if (!in_array($type, ['satis','ihracat','transfer'], true)) $type = 'satis';
    if ($s_date === '') $errors[] = 'Sevk tarihi zorunludur.';

    $stock_ids   = (array)($_POST['stock_id']   ?? []);
    $quantities  = (array)($_POST['qty']        ?? []);
    $unit_prices = (array)($_POST['unit_price'] ?? []);

    $cart = [];
    foreach ($stock_ids as $i => $sid) {
        $sid = (int)$sid;
        $qty = hks_qty($quantities[$i] ?? 0);
        if ($sid <= 0 || $qty <= 0) continue;
        $stock = $repo->getStock($sid);
        if (!$stock) { $errors[] = "Stok #$sid bulunamadı."; continue; }
        if ((float)$stock['remaining_quantity'] < $qty - 0.001) {
            $errors[] = hks_h($stock['kunye_no']) . ': yetersiz stok (kalan: '
                . number_format((float)$stock['remaining_quantity'], 3, ',', '.') . ' ' . $stock['unit'] . ')';
            continue;
        }
        $cart[] = ['stock' => $stock, 'qty' => $qty, 'unit_price' => hks_qty($unit_prices[$i] ?? 0)];
    }

    if (empty($stock_ids))              $errors[] = 'En az bir künye eklemelisiniz.';
    elseif (empty($cart) && !$errors)   $errors[] = 'Geçerli kalem bulunamadı.';

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
            $total_qty = 0.0;
            foreach ($cart as $c) {
                $repo->createItem([
                    'header_id'          => $h_id,
                    'reference_kunye_no' => $c['stock']['kunye_no'],
                    'product_name'       => $c['stock']['product_name'],
                    'quantity'           => $c['qty'],
                    'unit'               => $c['stock']['unit'],
                    'unit_price'         => $c['unit_price'] > 0 ? $c['unit_price'] : null,
                    'stock_id'           => (int)$c['stock']['id'],
                ]);
                $repo->deductStock((int)$c['stock']['id'], $c['qty']);
                $total_qty += $c['qty'];
            }
            $repo->addLog($h_id, 'create_shipment',
                count($cart) . ' kalem. Toplam: ' . number_format($total_qty, 3, ',', '.'));
            $pdo->commit();
            set_flash('success', count($cart) . ' kalemli sevk bildirimi oluşturuldu.');
            header('Location: view.php?id=' . $h_id);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

$stock_list = $repo->listStock(true);
$today      = date('Y-m-d');

render_header('Hızlı Sevk');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚡ Hızlı Sevk</h1>
        <p class="muted">Araç bazlı künye sevk bildirimi</p>
    </div>
    <div class="page-head-actions">
        <a href="index.php" class="btn btn-ghost">← İptal</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="flash flash-error"><?= implode('<br>', array_map('hks_h', $errors)) ?></div>
<?php endif; ?>

<form method="post" id="sp-form">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<div id="sp-inputs"></div>

<!-- ── Araç & Sevk Bilgileri ──────────────────────────── -->
<section class="card" style="margin-bottom:12px">
    <div class="card-head" style="cursor:pointer" onclick="spToggleHeader()">
        <h2>🚛 Araç & Sevk Bilgileri</h2>
        <span id="sp-hd-caret" style="color:var(--muted);font-size:.82rem">Daralt ▲</span>
    </div>
    <div class="card-body" id="sp-hd-body">
        <div class="hks-form-grid">
            <label>Bildirim Tipi
                <select name="type">
                    <option value="satis"    <?= ($_POST['type']??'satis')==='satis'   ?'selected':'' ?>>📤 Satış</option>
                    <option value="ihracat"  <?= ($_POST['type']??'')==='ihracat'      ?'selected':'' ?>>🌍 İhracat</option>
                    <option value="transfer" <?= ($_POST['type']??'')==='transfer'     ?'selected':'' ?>>🔄 Transfer</option>
                </select>
            </label>
            <label>Sevk Tarihi <span class="req">*</span>
                <input type="date" name="shipment_date"
                       value="<?= hks_h($_POST['shipment_date'] ?? $today) ?>" required>
            </label>
            <label>Araç Plakası
                <input type="text" name="vehicle_plate"
                       value="<?= hks_h($_POST['vehicle_plate'] ?? '') ?>"
                       style="text-transform:uppercase" autocomplete="off" placeholder="34ABC123">
            </label>
            <label>Şoför
                <input type="text" name="driver_name"
                       value="<?= hks_h($_POST['driver_name'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Alıcı
                <input type="text" name="buyer_name"
                       value="<?= hks_h($_POST['buyer_name'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Varış Yeri
                <input type="text" name="destination_place"
                       value="<?= hks_h($_POST['destination_place'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Çıkış Yeri
                <input type="text" name="origin_place"
                       value="<?= hks_h($_POST['origin_place'] ?? '') ?>" autocomplete="off">
            </label>
            <label>Not
                <input type="text" name="note"
                       value="<?= hks_h($_POST['note'] ?? '') ?>" autocomplete="off">
            </label>
        </div>
    </div>
</section>

<?php if (empty($stock_list)): ?>
<div class="empty">
    <p>Stokta aktif künye yok.</p>
    <a href="create_purchase.php" class="btn btn-primary">+ Alış Bildirimi Oluştur</a>
</div>
<?php else: ?>

<!-- ── Künye Arama ────────────────────────────────────── -->
<section class="card" style="margin-bottom:12px">
    <div class="card-head"><h2>🔍 Künye Ara & Ekle</h2></div>
    <div class="card-body">
        <input type="text" id="sp-search"
               placeholder="Ürün adı veya künye no yazın..."
               autocomplete="off"
               style="width:100%;box-sizing:border-box;font-size:16px;padding:11px 14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card);margin-bottom:8px">
        <div id="sp-results"></div>
    </div>
</section>

<!-- ── Yük Listesi ────────────────────────────────────── -->
<section class="card" style="margin-bottom:12px">
    <div class="card-head">
        <h2>📦 Yük Listesi</h2>
        <span id="sp-count" style="color:var(--muted);font-size:.85rem">0 kalem</span>
    </div>
    <div class="card-body">
        <div id="sp-cart"></div>
        <div id="sp-total"></div>
    </div>
</section>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;margin-bottom:90px">
    <a href="index.php" class="btn btn-ghost btn-lg">İptal</a>
    <button type="submit" id="sp-submit" class="btn btn-primary btn-lg" style="flex:1;min-width:160px">
        ✅ Sevk Bildir
    </button>
</div>

<?php endif; ?>
</form>

<!-- Sticky özet bar — kart dolu olunca çıkar -->
<div id="sp-bar"
     style="display:none;position:fixed;bottom:calc(60px + env(safe-area-inset-bottom,0px));left:0;right:0;background:var(--primary);color:#fff;padding:10px 16px;z-index:450;align-items:center;justify-content:space-between;gap:10px;box-shadow:0 -2px 10px rgba(0,0,0,.18)">
    <span id="sp-bar-text" style="font-weight:600;font-size:.9rem"></span>
    <button type="button" onclick="spBarSubmit()"
            style="background:#fff;color:var(--primary);border:none;padding:8px 18px;border-radius:20px;font-weight:700;cursor:pointer;font-size:.88rem;white-space:nowrap">
        Kaydet ✓
    </button>
</div>

<script>
var SP_STOCK = <?= json_encode(array_values($stock_list), JSON_UNESCAPED_UNICODE) ?>;

var spCart   = []; // [{id, kunye_no, product_name, unit, remaining, qty}]

var spSearch  = document.getElementById('sp-search');
var spResults = document.getElementById('sp-results');
var spCartEl  = document.getElementById('sp-cart');
var spTotal   = document.getElementById('sp-total');
var spCount   = document.getElementById('sp-count');
var spBar     = document.getElementById('sp-bar');
var spBarText = document.getElementById('sp-bar-text');
var spInputs  = document.getElementById('sp-inputs');

function spFmt(n) {
    var parts = parseFloat(n).toFixed(3).split('.');
    return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + parts[1];
}

function spInCart(id) {
    return spCart.some(function(c){ return c.id === id; });
}

function spAddToCart(id) {
    if (spInCart(id)) return;
    var s = null;
    for (var i = 0; i < SP_STOCK.length; i++) {
        if (SP_STOCK[i].id === id) { s = SP_STOCK[i]; break; }
    }
    if (!s) return;
    var rem = parseFloat(s.remaining_quantity);
    spCart.push({ id: id, kunye_no: s.kunye_no, product_name: s.product_name,
                  unit: s.unit, remaining: rem, qty: rem });
    spRender();
}

function spRemove(id) {
    spCart = spCart.filter(function(c){ return c.id !== id; });
    spRender();
}

function spRender() {
    spRenderResults();
    spRenderCart();
    spUpdateBar();
}

function spRenderResults() {
    var term = (spSearch.value || '').toLowerCase().trim();
    var available = SP_STOCK.filter(function(s){ return !spInCart(s.id); });
    var filtered = term
        ? available.filter(function(s){
            return s.product_name.toLowerCase().indexOf(term) !== -1
                || s.kunye_no.toLowerCase().indexOf(term) !== -1;
          })
        : available;

    spResults.innerHTML = '';

    if (filtered.length === 0) {
        var p = document.createElement('p');
        p.style.cssText = 'color:var(--muted);font-size:.88rem;padding:6px 0';
        p.textContent = term ? 'Sonuç bulunamadı.' : 'Tüm stok yüke eklendi.';
        spResults.appendChild(p);
        return;
    }

    var shown = term ? filtered : filtered.slice(0, 30);
    shown.forEach(function(s) {
        spResults.appendChild(spMakeResultRow(s));
    });

    if (!term && filtered.length > 30) {
        var more = document.createElement('p');
        more.style.cssText = 'color:var(--muted);font-size:.8rem;padding:4px 0';
        more.textContent = '+ ' + (filtered.length - 30) + ' künye daha — aramak için yazın.';
        spResults.appendChild(more);
    }
}

function spMakeResultRow(s) {
    var rem = parseFloat(s.remaining_quantity);
    var div = document.createElement('div');
    div.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);gap:10px;min-height:48px';

    var info = document.createElement('div');
    info.style.flex = '1';

    var name = document.createElement('div');
    name.style.cssText = 'font-weight:600;font-size:.92rem';
    name.textContent = s.product_name;

    var meta = document.createElement('div');
    meta.style.cssText = 'font-size:.78rem;color:var(--muted);margin-top:2px';
    meta.textContent = 'Künye: ' + s.kunye_no + '  ·  Kalan: ' + spFmt(rem) + ' ' + s.unit;

    info.appendChild(name);
    info.appendChild(meta);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = '+ Ekle';
    btn.className = 'btn btn-sm btn-primary';
    btn.style.cssText = 'white-space:nowrap;min-width:64px;min-height:36px';
    (function(sid){ btn.onclick = function(){ spAddToCart(sid); }; })(s.id);

    div.appendChild(info);
    div.appendChild(btn);
    return div;
}

function spRenderCart() {
    spCartEl.innerHTML = '';
    if (spCart.length === 0) {
        spCartEl.innerHTML = '<p style="color:var(--muted);font-size:.88rem">Yukarıdan künye ekleyin.</p>';
        spTotal.innerHTML = '';
        spCount.textContent = '0 kalem';
        return;
    }

    spCart.forEach(function(c) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 0;border-bottom:1px solid var(--border)';

        var info = document.createElement('div');
        info.style.cssText = 'flex:1;min-width:0;overflow:hidden';

        var nameEl = document.createElement('div');
        nameEl.style.cssText = 'font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
        nameEl.textContent = c.product_name;

        var metaEl = document.createElement('div');
        metaEl.style.cssText = 'font-size:.75rem;color:var(--muted)';
        metaEl.textContent = 'Künye: ' + c.kunye_no + ' · max ' + spFmt(c.remaining);

        info.appendChild(nameEl);
        info.appendChild(metaEl);

        var inp = document.createElement('input');
        inp.type = 'number';
        inp.value = c.qty.toFixed(3);
        inp.min = '0.001';
        inp.max = c.remaining.toFixed(3);
        inp.step = '0.001';
        inp.setAttribute('data-cart-id', c.id);
        inp.style.cssText = 'width:90px;text-align:right;font-size:16px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card)';
        (function(item){ inp.addEventListener('input', function(){
            item.qty = parseFloat(this.value) || 0;
            spUpdateTotal();
            spUpdateBar();
        }); })(c);

        var unitEl = document.createElement('span');
        unitEl.style.cssText = 'font-size:.82rem;color:var(--muted);white-space:nowrap';
        unitEl.textContent = c.unit;

        var del = document.createElement('button');
        del.type = 'button';
        del.innerHTML = '&times;';
        del.style.cssText = 'background:none;border:1px solid var(--border);width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:1.2rem;color:var(--muted);flex-shrink:0;display:flex;align-items:center;justify-content:center;line-height:1';
        (function(sid){ del.onclick = function(){ spRemove(sid); }; })(c.id);

        row.appendChild(info);
        row.appendChild(inp);
        row.appendChild(unitEl);
        row.appendChild(del);
        spCartEl.appendChild(row);
    });

    spCount.textContent = spCart.length + ' kalem';
    spUpdateTotal();
}

function spGetTotal() {
    var t = 0;
    spCart.forEach(function(c){
        var inp = spCartEl.querySelector('input[data-cart-id="' + c.id + '"]');
        t += parseFloat(inp ? inp.value : c.qty) || 0;
    });
    return t;
}

function spGetUnits() {
    var seen = {};
    spCart.forEach(function(c){ seen[c.unit] = true; });
    return Object.keys(seen);
}

function spUpdateTotal() {
    if (spCart.length === 0) { spTotal.innerHTML = ''; return; }
    var units = spGetUnits();
    spTotal.style.cssText = 'padding-top:10px;font-weight:700;text-align:right;font-size:.95rem';
    spTotal.textContent = units.length === 1
        ? 'Toplam: ' + spFmt(spGetTotal()) + ' ' + units[0]
        : spCart.length + ' kalem';
}

function spUpdateBar() {
    if (spCart.length === 0) { spBar.style.display = 'none'; return; }
    var units = spGetUnits();
    spBar.style.display = 'flex';
    spBarText.textContent = spCart.length + ' kalem'
        + (units.length === 1 ? ' · ' + Math.round(spGetTotal()).toLocaleString('tr-TR') + ' ' + units[0] : '');
}

function spBuildInputs() {
    spInputs.innerHTML = '';
    var hasItems = false;
    spCart.forEach(function(c) {
        var inp = spCartEl.querySelector('input[data-cart-id="' + c.id + '"]');
        var qty = inp ? (parseFloat(inp.value) || 0) : c.qty;
        if (qty <= 0) return;
        spInputs.insertAdjacentHTML('beforeend',
            '<input type="hidden" name="stock_id[]" value="' + c.id + '">' +
            '<input type="hidden" name="qty[]" value="' + qty + '">' +
            '<input type="hidden" name="unit_price[]" value="">');
        hasItems = true;
    });
    return hasItems;
}

function spBarSubmit() {
    if (!spBuildInputs()) { alert('En az bir künye eklemelisiniz.'); return; }
    document.getElementById('sp-form').submit();
}

function spToggleHeader() {
    var body  = document.getElementById('sp-hd-body');
    var caret = document.getElementById('sp-hd-caret');
    var open  = body.style.display !== 'none';
    body.style.display  = open ? 'none' : '';
    caret.textContent   = open ? 'Genişlet ▼' : 'Daralt ▲';
}

// Form submit handler (native submit button)
document.getElementById('sp-form').addEventListener('submit', function(e) {
    if (!spBuildInputs()) {
        e.preventDefault();
        alert('En az bir künye eklemelisiniz.');
    }
});

// Init
if (spSearch) spSearch.addEventListener('input', spRender);
spRenderResults();
spRenderCart();
</script>

<?php render_footer(); ?>
