<?php
// =========================================================
// hks/view.php — HKS Bildirim Detayı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/HksRepository.php';
require_once __DIR__ . '/HksClient.php';

$id   = (int)($_GET['id'] ?? 0);
$repo = new HksRepository(db());

$n = $repo->getNotification($id);
if (!$n) {
    http_response_code(404);
    render_header('Bulunamadı');
    echo '<div class="empty"><p>Bildirim bulunamadı.</p><a href="index.php" class="btn">Listeye Dön</a></div>';
    render_footer();
    exit;
}

$logs = $repo->getLogsForNotification($id, 20);

render_header('HKS Bildirim #' . $id);
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🏛 HKS Bildirim #<?= (int)$n['id'] ?></h1>
        <p class="muted"><?= hks_h($n['notification_type']) ?> · <?= hks_h(fmt_date($n['shipment_date'])) ?></p>
    </div>
    <div class="page-head-actions">
        <?php if ($n['status'] === 'draft'): ?>
            <a href="edit.php?id=<?= (int)$n['id'] ?>" class="btn btn-ghost btn-lg">Düzenle</a>
        <?php endif; ?>
        <?php if (in_array($n['status'], ['draft', 'error'], true)): ?>
            <button type="button" class="btn btn-primary btn-lg" id="btnSend"
                    data-id="<?= (int)$n['id'] ?>">
                HKS'ye Gönder
            </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-ghost btn-lg">← Liste</a>
    </div>
</div>

<!-- Durum Kutusu -->
<?php if ($n['status'] === 'error' && $n['last_error']): ?>
    <div class="hks-error-box">
        <strong>Gönderim Hatası:</strong> <?= hks_h($n['last_error']) ?>
    </div>
<?php endif; ?>

<?php if ($n['status'] === 'sent'): ?>
    <div class="hks-success-box">
        <strong>Bildirim Gönderildi</strong>
        <?php if ($n['hks_notification_no']): ?>
        — Bildirim No: <strong><?= hks_h($n['hks_notification_no']) ?></strong>
        <?php endif; ?>
        <?php if ($n['hks_tag_no']): ?>
        &nbsp;|&nbsp; Künye No: <strong><?= hks_h($n['hks_tag_no']) ?></strong>
        <?php endif; ?>
        <?php if ($n['sent_at']): ?>
        <div style="font-size:.82rem;margin-top:4px">Gönderim Zamanı: <?= hks_h(fmt_datetime($n['sent_at'])) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Durum Badge -->
<div style="margin-bottom:16px">
    <span class="hks-badge <?= hks_h(hks_status_class($n['status'])) ?>" style="font-size:.9rem;padding:4px 14px">
        <?= hks_h(hks_status_label($n['status'])) ?>
    </span>
</div>

<!-- Detay Grid -->
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-bottom:14px">Bildirim Bilgileri</h2>
    <div class="hks-detail-grid">
        <div class="hks-detail-item">
            <label>Bildirim Tipi</label>
            <strong><?= hks_h($n['notification_type']) ?></strong>
        </div>
        <div class="hks-detail-item">
            <label>Ürün Adı</label>
            <strong><?= hks_h($n['product_name']) ?></strong>
        </div>
        <?php if ($n['product_code']): ?>
        <div class="hks-detail-item">
            <label>Ürün Kodu</label>
            <strong><?= hks_h($n['product_code']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="hks-detail-item">
            <label>Miktar</label>
            <strong><?= hks_h(number_format((float)$n['quantity'], 3, ',', '.')) ?> <?= hks_h($n['unit']) ?></strong>
        </div>
        <div class="hks-detail-item">
            <label>Ambalaj Tipi</label>
            <strong><?= hks_h($n['package_type']) ?></strong>
        </div>
        <?php if ($n['supplier_name']): ?>
        <div class="hks-detail-item">
            <label>Tedarikçi</label>
            <strong><?= hks_h($n['supplier_name']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($n['buyer_name']): ?>
        <div class="hks-detail-item">
            <label>Alıcı</label>
            <strong><?= hks_h($n['buyer_name']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="hks-detail-item">
            <label>Sevk Tarihi</label>
            <strong><?= hks_h(fmt_date($n['shipment_date'])) ?></strong>
        </div>
        <?php if ($n['vehicle_plate']): ?>
        <div class="hks-detail-item">
            <label>Araç Plakası</label>
            <strong><?= hks_h($n['vehicle_plate']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($n['driver_name']): ?>
        <div class="hks-detail-item">
            <label>Şoför</label>
            <strong><?= hks_h($n['driver_name']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($n['origin_place']): ?>
        <div class="hks-detail-item">
            <label>Çıkış Yeri</label>
            <strong><?= hks_h($n['origin_place']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($n['destination_place']): ?>
        <div class="hks-detail-item">
            <label>Varış Yeri</label>
            <strong><?= hks_h($n['destination_place']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($n['note']): ?>
        <div class="hks-detail-item" style="grid-column:1/-1">
            <label>Not</label>
            <strong><?= hks_h($n['note']) ?></strong>
        </div>
        <?php endif; ?>
        <div class="hks-detail-item">
            <label>Oluşturulma</label>
            <strong><?= hks_h(fmt_datetime($n['created_at'])) ?></strong>
        </div>
        <div class="hks-detail-item">
            <label>Son Güncelleme</label>
            <strong><?= hks_h(fmt_datetime($n['updated_at'])) ?></strong>
        </div>
    </div>
</div>

<!-- XML Bölümleri -->
<?php if ($n['request_xml'] || $n['response_xml']): ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-bottom:10px">Servis Verisi</h2>

    <?php if ($n['request_xml']): ?>
    <div>
        <div class="hks-collapsible-head" onclick="this.nextElementSibling.classList.toggle('open')">
            <span id="caret-req">▶</span> İstek XML
        </div>
        <div class="hks-collapsible-body">
            <pre class="hks-xml-block"><?= hks_h($n['request_xml']) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($n['response_xml']): ?>
    <div style="margin-top:8px">
        <div class="hks-collapsible-head" onclick="this.nextElementSibling.classList.toggle('open')">
            <span>▶</span> Yanıt XML
        </div>
        <div class="hks-collapsible-body">
            <pre class="hks-xml-block"><?= hks_h($n['response_xml']) ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Log Kayıtları -->
<?php if (!empty($logs)): ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-bottom:10px">Log Kayıtları (Son <?= count($logs) ?>)</h2>
    <?php foreach ($logs as $log): ?>
        <div class="hks-log-row">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                <span class="hks-log-action"><?= hks_h($log['action']) ?></span>
                <span class="hks-log-time"><?= hks_h(fmt_datetime($log['created_at'])) ?></span>
            </div>
            <div style="margin-top:2px"><?= hks_h($log['message']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
// Collapsible toggle — caret güncelle
document.querySelectorAll('.hks-collapsible-head').forEach(function(head) {
    head.addEventListener('click', function() {
        var body = this.nextElementSibling;
        var caret = this.querySelector('span');
        if (body.classList.contains('open')) {
            if (caret) caret.textContent = '▶';
        } else {
            if (caret) caret.textContent = '▼';
        }
    });
});

// Gönder butonu
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
