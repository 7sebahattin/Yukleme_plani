<?php
// =========================================================
// hks/view.php — HKS Bildirim Detayı (v2 — künye bazlı)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';

$id   = (int)($_GET['id'] ?? 0);
$repo = new HksRepository(db());

$h = $repo->getHeader($id);
if (!$h) {
    http_response_code(404);
    render_header('Bulunamadı');
    echo '<div class="empty"><p>Bildirim bulunamadı.</p><a href="index.php" class="btn">Listeye Dön</a></div>';
    render_footer();
    exit;
}

$items = $repo->getItemsForHeader($id);
$logs  = $repo->getLogsForHeader($id, 20);

render_header('HKS Bildirim #' . $id);
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🏛 HKS Bildirim #<?= (int)$h['id'] ?></h1>
        <p class="muted"><?= hks_h(hks_type_label($h['type'])) ?> · <?= hks_h($h['shipment_date']) ?></p>
    </div>
    <div class="page-head-actions">
        <?php if (in_array($h['status'], ['draft', 'error'], true)): ?>
        <button type="button" class="btn btn-primary btn-lg" id="btnSend" data-id="<?= (int)$h['id'] ?>">
            HKS'ye Gönder
        </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-ghost btn-lg">← Liste</a>
    </div>
</div>

<!-- Durum Kutusu -->
<?php if ($h['status'] === 'error' && $h['last_error']): ?>
<div class="hks-error-box">
    <strong>Gönderim Hatası:</strong> <?= hks_h($h['last_error']) ?>
</div>
<?php endif; ?>

<?php if ($h['status'] === 'sent'): ?>
<div class="hks-success-box">
    <strong>Bildirim Gönderildi</strong>
    <?php if ($h['hks_notification_no']): ?>
    — Bildirim No: <strong><?= hks_h($h['hks_notification_no']) ?></strong>
    <?php endif; ?>
    <?php if ($h['sent_at']): ?>
    <div style="font-size:.82rem;margin-top:4px">Gönderim Zamanı: <?= hks_h(substr($h['sent_at'], 0, 16)) ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-bottom:16px">
    <span class="hks-badge <?= hks_h(hks_status_class($h['status'])) ?>" style="font-size:.9rem;padding:4px 14px">
        <?= hks_h(hks_status_label($h['status'])) ?>
    </span>
</div>

<!-- Bildirim Bilgileri -->
<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Bildirim Bilgileri</h2></div>
    <div class="card-body">
        <div class="hks-detail-grid">
            <div><span style="color:var(--muted);font-size:.8rem">Bildirim Tipi</span><br><strong><?= hks_h(hks_type_label($h['type'])) ?></strong></div>
            <div><span style="color:var(--muted);font-size:.8rem">Sevk Tarihi</span><br><strong><?= hks_h($h['shipment_date']) ?></strong></div>
            <?php if ($h['buyer_name']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Alıcı</span><br><strong><?= hks_h($h['buyer_name']) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['supplier_name']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Tedarikçi</span><br><strong><?= hks_h($h['supplier_name']) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['vehicle_plate']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Araç Plakası</span><br><strong><?= hks_h($h['vehicle_plate']) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['driver_name']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Şoför</span><br><strong><?= hks_h($h['driver_name']) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['origin_place']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Çıkış Yeri</span><br><strong><?= hks_h($h['origin_place']) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['destination_place']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Varış Yeri</span><br><strong><?= hks_h($h['destination_place']) ?></strong></div>
            <?php endif; ?>
            <div><span style="color:var(--muted);font-size:.8rem">Oluşturulma</span><br><strong><?= hks_h(substr($h['created_at'], 0, 16)) ?></strong></div>
            <?php if ($h['updated_at'] && $h['updated_at'] !== $h['created_at']): ?>
            <div><span style="color:var(--muted);font-size:.8rem">Güncelleme</span><br><strong><?= hks_h(substr($h['updated_at'], 0, 16)) ?></strong></div>
            <?php endif; ?>
            <?php if ($h['note']): ?>
            <div style="grid-column:1/-1"><span style="color:var(--muted);font-size:.8rem">Not</span><br><strong><?= hks_h($h['note']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Kalem Listesi -->
<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Referans Künyeler (<?= count($items) ?> kalem)</h2></div>
    <div class="card-body" style="padding:0">

    <?php if (empty($items)): ?>
    <p style="padding:16px;color:var(--muted)">Kalem bulunamadı.</p>
    <?php else: ?>

    <!-- PC -->
    <div class="table-wrap pc-only">
        <table class="data-table">
            <thead>
            <tr>
                <th>Künye No</th>
                <th>Ürün</th>
                <th class="num">Miktar</th>
                <th>Birim</th>
                <?php if ($h['type'] === 'satis' || $h['type'] === 'ihracat'): ?>
                <th class="num">Birim Fiyat</th>
                <?php endif; ?>
                <th class="num">Kalan Stok</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $total_qty = 0.0;
            foreach ($items as $it):
                $total_qty += (float)$it['quantity'];
            ?>
            <tr>
                <td><strong><?= hks_h($it['reference_kunye_no']) ?></strong></td>
                <td><?= hks_h($it['product_name']) ?></td>
                <td class="num"><?= number_format((float)$it['quantity'], 3, ',', '.') ?></td>
                <td><?= hks_h($it['unit']) ?></td>
                <?php if ($h['type'] === 'satis' || $h['type'] === 'ihracat'): ?>
                <td class="num"><?= $it['unit_price'] ? number_format((float)$it['unit_price'], 2, ',', '.') . ' ₺' : '<span class="muted">—</span>' ?></td>
                <?php endif; ?>
                <td class="num" style="color:<?= isset($it['stock_remaining']) && (float)$it['stock_remaining'] > 0 ? 'var(--success)' : 'var(--muted)' ?>">
                    <?= isset($it['stock_remaining']) ? number_format((float)$it['stock_remaining'], 3, ',', '.') : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr style="font-weight:700;background:var(--bg)">
                <td colspan="2">Toplam</td>
                <td class="num"><?= number_format($total_qty, 3, ',', '.') ?></td>
                <td colspan="<?= ($h['type'] === 'satis' || $h['type'] === 'ihracat') ? 3 : 2 ?>"></td>
            </tr>
            </tfoot>
        </table>
    </div>

    <!-- Mobil -->
    <div class="mobile-only" style="padding:10px">
        <?php foreach ($items as $it): ?>
        <div class="hks-card">
            <div class="hks-card-head">
                <div>
                    <div class="hks-card-title"><?= hks_h($it['reference_kunye_no']) ?></div>
                    <div class="hks-card-meta"><?= hks_h($it['product_name']) ?></div>
                </div>
                <strong style="font-size:1rem"><?= number_format((float)$it['quantity'], 3, ',', '.') ?> <?= hks_h($it['unit']) ?></strong>
            </div>
            <?php if (isset($it['stock_remaining'])): ?>
            <div style="font-size:.82rem;color:var(--muted)">
                Kalan stok: <span style="color:<?= (float)$it['stock_remaining'] > 0 ? 'var(--success)' : 'var(--muted)' ?>"><?= number_format((float)$it['stock_remaining'], 3, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            <?php if ($it['unit_price']): ?>
            <div style="font-size:.82rem;color:var(--muted)">Birim Fiyat: <?= number_format((float)$it['unit_price'], 2, ',', '.') ?> ₺</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
    </div>
</div>

<!-- XML Servis Verisi -->
<?php if ($h['request_xml'] || $h['response_xml']): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Servis Verisi</h2></div>
    <div class="card-body">
        <?php if ($h['request_xml']): ?>
        <div>
            <div class="hks-collapsible-head" onclick="toggleCollapsible(this)">
                <span>▶</span> İstek XML
            </div>
            <div class="hks-collapsible-body">
                <pre class="hks-xml-block"><?= hks_h($h['request_xml']) ?></pre>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($h['response_xml']): ?>
        <div style="margin-top:8px">
            <div class="hks-collapsible-head" onclick="toggleCollapsible(this)">
                <span>▶</span> Yanıt XML
            </div>
            <div class="hks-collapsible-body">
                <pre class="hks-xml-block"><?= hks_h($h['response_xml']) ?></pre>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Log Kayıtları -->
<?php if (!empty($logs)): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-head"><h2>Log Kayıtları (Son <?= count($logs) ?>)</h2></div>
    <div class="card-body">
        <?php foreach ($logs as $log): ?>
        <div class="hks-log-row">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <span class="hks-log-action"><?= hks_h($log['action']) ?></span>
                <span class="hks-log-time"><?= hks_h(substr($log['created_at'], 0, 16)) ?></span>
            </div>
            <div style="margin-top:2px"><?= hks_h($log['message']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
function toggleCollapsible(head) {
    var body = head.nextElementSibling;
    var caret = head.querySelector('span');
    var isOpen = body.classList.toggle('open');
    if (caret) caret.textContent = isOpen ? '▼' : '▶';
}

var btnSend = document.getElementById('btnSend');
if (btnSend) {
    btnSend.addEventListener('click', function() {
        var id = this.dataset.id;
        if (!confirm('Bu bildirimi HKS\'ye göndermek istiyor musunuz?')) return;
        btnSend.disabled = true;
        btnSend.textContent = 'Gönderiliyor...';

        var csrf = document.querySelector('meta[name="csrf-token"]');
        var csrfVal = csrf ? csrf.getAttribute('content') : '';

        fetch('api_send.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({csrf: csrfVal, id: parseInt(id, 10)})
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                window.location.reload();
            } else {
                alert('Hata: ' + (data.message || 'Bilinmeyen hata'));
                btnSend.disabled = false;
                btnSend.textContent = 'HKS\'ye Gönder';
            }
        })
        .catch(function() {
            alert('İstek gönderilemedi.');
            btnSend.disabled = false;
            btnSend.textContent = 'HKS\'ye Gönder';
        });
    });
}
</script>

<?php render_footer(); ?>
