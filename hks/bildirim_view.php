<?php
// HKS Bildirim Görüntüleme + Kontrol / Gönderim Güvenlik Akışı
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo = new HksRepository(db());
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: bildirimler.php'); exit; }

$n = $repo->getNotification($id);
if (!$n) { set_flash('error', 'Bildirim bulunamadı.'); header('Location: bildirimler.php'); exit; }

$can_write = !function_exists('can') || can('hks.write') || can('records.write');
$can_send  = function_exists('can') && can('hks.send');
$settings  = $repo->getSettings();

// ── POST işlemleri ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'];

    // İptal
    if ($action === 'cancel' && $can_write) {
        if (in_array($n['status'], ['draft','ready','checked','failed'], true)) {
            $repo->cancelNotification($id);
            audit_log_event('hks_notification_cancelled', 'hks_notifications', $id, null, ['status' => 'cancelled']);
            set_flash('success', 'Bildirim iptal edildi.');
        } else {
            set_flash('error', 'Bu durumdaki kayıt iptal edilemez.');
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }

    // Kontrol Edildi olarak işaretle
    if ($action === 'check' && $can_write) {
        if (!in_array($n['status'], ['draft','ready','failed'], true)) {
            set_flash('error', 'Bu kayıt kontrol edilemez (durum: ' . $n['status'] . ').');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }
        $errors = hks_validate_notification($n);
        audit_log_event('hks_notification_validated', 'hks_notifications', $id, null, ['error_count' => count($errors)]);
        if (!empty($errors)) {
            $repo->setValidation($id, $errors);
            set_flash('error', 'Eksik/hatalı alanlar var. Kayıt kontrol edildi olarak işaretlenemedi.');
        } else {
            $repo->markChecked($id, isset($auth_user['id']) ? (int)$auth_user['id'] : null);
            audit_log_event('hks_notification_checked', 'hks_notifications', $id, null, ['checked_by' => $auth_user['username'] ?? '']);
            set_flash('success', 'Bildirim "Kontrol Edildi" olarak işaretlendi.');
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }

    // Canlı gönderim
    if ($action === 'send') {
        // Onay checkbox zorunlu
        if (empty($_POST['onay'])) {
            set_flash('error', 'Gönderim için onay kutusunu işaretlemelisiniz.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }
        // Tüm engelleyici koşullar boş olmalı
        $blockers = hks_send_blockers($n, $settings);
        if (!empty($blockers)) {
            set_flash('error', 'Gönderilemez: ' . implode(' ', $blockers));
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        // Mükerrer içerik uyarısı — kullanıcı zorlamadıysa engelle
        $dup = $repo->findContentDuplicateSent($n, $id, 30);
        if ($dup && empty($_POST['dup_ack'])) {
            audit_log_event('hks_notification_duplicate_blocked', 'hks_notifications', $id, null, ['matched' => $dup['local_no']]);
            set_flash('error', 'Son 30 günde benzer içerikli gönderilmiş kayıt var (' . $dup['local_no'] . '). Mükerrer onayı verilmedi.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        // Transaction + FOR UPDATE ile kilitle (çift tıklama / mükerrer engeli)
        $lock = $repo->lockForSend($id);
        if (!$lock['ok']) {
            if (!empty($lock['duplicate'])) {
                audit_log_event('hks_notification_duplicate_blocked', 'hks_notifications', $id, null, ['reason' => $lock['reason']]);
            }
            set_flash('error', $lock['reason'] ?? 'Gönderim kilidi alınamadı.');
            header('Location: bildirim_view.php?id=' . $id); exit;
        }

        audit_log_event('hks_notification_send_attempt', 'hks_notifications', $id, null, ['local_no' => $n['local_no']]);

        $client = new HksClient($repo);
        $payload = hks_notification_payload_preview($n);
        $result  = $client->saveBildirim($payload);

        if (!empty($result['ok'])) {
            $repo->finalizeSent(
                $id,
                $result['hks_bildirim_no'] ?? null,
                $result['hks_kunye_no'] ?? null,
                isset($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE) : null
            );
            audit_log_event('hks_notification_sent', 'hks_notifications', $id, null, ['hks_bildirim_no' => $result['hks_bildirim_no'] ?? '']);
            set_flash('success', 'Bildirim HKS\'ye gönderildi.');
        } elseif (!empty($result['pending'])) {
            // Servis eşlenmedi — kilidi geri al, hata değil
            $repo->revertToChecked($id);
            set_flash('info', $result['message'] ?? 'HKS servis eşlemesi tamamlanmadı. Gönderim yapılmadı.');
        } else {
            $repo->markFailed($id, $result['message'] ?? 'Bilinmeyen HKS hatası', isset($result['data']) ? json_encode($result['data'], JSON_UNESCAPED_UNICODE) : null);
            audit_log_event('hks_notification_failed', 'hks_notifications', $id, null, ['error' => $result['message'] ?? '']);
            set_flash('error', 'HKS gönderimi başarısız: ' . ($result['message'] ?? ''));
        }
        header('Location: bildirim_view.php?id=' . $id); exit;
    }
}

// ── Görüntüleme verisi ───────────────────────────────────
$validation_errors = !empty($n['validation_errors_json']) ? json_decode($n['validation_errors_json'], true) : [];
$live_send_on = $settings && (int)($settings['live_send_enabled'] ?? 0) === 1;
$blockers     = hks_send_blockers($n, $settings);
$dup_warn     = $repo->findContentDuplicateSent($n, $id, 30);
$creator      = $repo->userName(isset($n['created_by']) ? (int)$n['created_by'] : null);
$checker      = $repo->userName(isset($n['checked_by']) ? (int)$n['checked_by'] : null);
$preview      = hks_notification_payload_preview($n);

render_header('HKS Bildirim #' . $n['local_no']);
render_flash();
?>

<style>
.hks-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.78rem; font-weight:600; }
.hks-badge-draft     { background:#e5e7eb; color:#374151; }
.hks-badge-ready     { background:#dbeafe; color:#1e40af; }
.hks-badge-checked   { background:#ede9fe; color:#5b21b6; }
.hks-badge-pending   { background:#fef9c3; color:#854d0e; }
.hks-badge-sent      { background:#d1fae5; color:#065f46; }
.hks-badge-failed    { background:#fee2e2; color:#991b1b; }
.hks-badge-cancelled { background:#f3f4f6; color:#6b7280; }
.hks-statebox { border-radius:8px; padding:12px 16px; margin-bottom:12px; font-size:.9rem; border:1px solid; }
.hks-sb-draft     { background:#f9fafb; border-color:#d1d5db; color:#374151; }
.hks-sb-ready     { background:#eff6ff; border-color:#93c5fd; color:#1e40af; }
.hks-sb-checked   { background:#f5f3ff; border-color:#c4b5fd; color:#5b21b6; }
.hks-sb-pending   { background:#fefce8; border-color:#fde047; color:#854d0e; }
.hks-sb-sent      { background:#f0fdf4; border-color:var(--success); color:#065f46; }
.hks-sb-failed    { background:#fef2f2; border-color:var(--danger); color:#991b1b; }
.hks-sb-cancelled { background:#f3f4f6; border-color:#d1d5db; color:#6b7280; }
.hks-check-table { width:100%; border-collapse:collapse; font-size:.88rem; }
.hks-check-table td { padding:6px 10px; border-bottom:1px solid var(--border); }
.hks-check-table td:first-child { color:var(--muted); width:42%; }
.hks-check-table td:last-child { font-weight:600; }
.hks-send-panel { background:var(--card); border:2px solid #c4b5fd; border-radius:10px; padding:18px 20px; margin:16px 0; }
.hks-blocker-list { margin:8px 0 0; padding-left:20px; }
.hks-blocker-list li { margin:3px 0; color:#991b1b; font-size:.88rem; }
.hks-onay-row { display:flex; align-items:flex-start; gap:8px; margin:14px 0; font-size:.9rem; }
.hks-onay-row input { margin-top:3px; }
</style>

<div style="max-width:1000px;margin:0 auto">

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🏛 <?= hks_h($n['local_no']) ?></h1>
        <?= hks_status_badge($n['status']) ?>
        <?php if ($n['status'] === 'sent' && $n['hks_bildirim_no']): ?>
        <span style="font-size:.85rem;color:var(--muted)">HKS No: <?= hks_h($n['hks_bildirim_no']) ?></span>
        <?php endif; ?>
    </div>
    <div class="page-head-actions">
        <a href="bildirimler.php" class="btn btn-ghost">← Liste</a>
        <?php if ($can_write && in_array($n['status'], ['draft','ready','failed'], true)): ?>
        <a href="bildirim_form.php?id=<?= $id ?>" class="btn btn-ghost">✏️ Düzenle</a>
        <?php endif; ?>
    </div>
</div>

<!-- Duruma özel renkli kutu -->
<?php
$state_msgs = [
    'draft'        => ['hks-sb-draft',     'Bu kayıt henüz taslak. HKS\'ye gönderilemez. Eksik alanları tamamlayın.'],
    'ready'        => ['hks-sb-ready',     'Zorunlu alanlar tamamlandı. Gönderimden önce "Kontrol Edildi" olarak işaretlenmeli.'],
    'checked'      => ['hks-sb-checked',   'Bu kayıt kontrol edildi. Yetkili kullanıcı (hks.send) HKS\'ye gönderebilir.'],
    'send_pending' => ['hks-sb-pending',   'Bu kayıt gönderim için kilitlendi. Lütfen bekleyin.'],
    'sent'         => ['hks-sb-sent',      'Bu kayıt HKS\'ye gönderildi. Tekrar gönderilemez.'],
    'failed'       => ['hks-sb-failed',    'HKS gönderimi başarısız. Düzeltip yeniden kontrol edin (otomatik tekrar yapılmaz).'],
    'cancelled'    => ['hks-sb-cancelled', 'Bu kayıt iptal edildi. Gönderilemez.'],
];
$sm = $state_msgs[$n['status']] ?? null;
if ($sm): ?>
<div class="hks-statebox <?= $sm[0] ?>"><?= hks_h($sm[1]) ?></div>
<?php endif; ?>

<!-- Doğrulama hataları -->
<?php if (!empty($validation_errors)): ?>
<div class="hks-statebox hks-sb-failed">
    <strong>⚠️ Eksik / Hatalı Alanlar — HKS'ye gönderilemez:</strong>
    <ul style="margin:6px 0 0;padding-left:18px">
        <?php foreach ($validation_errors as $e): ?>
        <li><?= hks_h($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Son hata -->
<?php if ($n['status'] === 'failed' && $n['last_error']): ?>
<div class="hks-statebox hks-sb-failed">
    <strong>Son HKS Hatası:</strong> <?= hks_h($n['last_error']) ?>
</div>
<?php endif; ?>

<!-- Mükerrer uyarısı -->
<?php if ($dup_warn && !in_array($n['status'], ['sent','cancelled'], true)): ?>
<div class="hks-statebox hks-sb-pending">
    🔁 <strong>Mükerrer uyarısı:</strong> Son 30 günde aynı firma/ürün/miktar/belge ile gönderilmiş bir kayıt var
    (<?= hks_h($dup_warn['local_no']) ?>). Göndermeden önce kontrol edin.
</div>
<?php endif; ?>

<!-- Detay kartları -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:16px">

    <div class="card" style="padding:16px">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Temel Bilgiler</div>
        <?php
        $rows = [
            'Bildirim Türü' => $n['notification_type'],
            'Sıfat'         => $n['sifat'] ?? '',
            'Yön'           => $n['direction'],
            'Firma'         => $n['firma'],
            'Ürün'          => $n['urun'],
            'Ürün Cinsi'    => $n['urun_cinsi'],
            'Miktar'        => $n['miktar'] ? number_format((float)$n['miktar'], 3, ',', '.') . ' ' . $n['birim'] : '',
            'Sevk Tarihi'   => $n['sevk_tarihi'] ? date('d.m.Y', strtotime($n['sevk_tarihi'])) : '',
        ];
        foreach ($rows as $k => $v): if (!$v) continue; ?>
        <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:.88rem">
            <span style="color:var(--muted)"><?= hks_h($k) ?>:</span>
            <span style="font-weight:600;text-align:right;max-width:60%"><?= hks_h($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="padding:16px">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Konum ve Araç</div>
        <?php
        $rows2 = [
            'Depo'          => $n['depo'],
            'İl'            => $n['il'],
            'İlçe'          => $n['ilce'],
            'Belde'         => $n['belde'],
            'Araç Plaka'    => $n['arac_plaka'],
            'Belge No'      => $n['belge_no'],
            'Ref. Künye No' => $n['reference_kunye_no'],
        ];
        foreach ($rows2 as $k => $v): if (!$v) continue; ?>
        <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:.88rem">
            <span style="color:var(--muted)"><?= hks_h($k) ?>:</span>
            <span style="font-weight:600;text-align:right;max-width:60%"><?= hks_h($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card" style="padding:16px">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Üretici / Alıcı</div>
        <?php
        $rows3 = [
            'Üretici Adı'    => $n['uretici_ad'],
            'Üretici TC/VKN' => $n['uretici_tc_vkn'],
            'Alıcı Adı'      => $n['alici_ad'],
            'Alıcı TC/VKN'   => $n['alici_tc_vkn'],
        ];
        foreach ($rows3 as $k => $v): if (!$v) continue; ?>
        <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:.88rem">
            <span style="color:var(--muted)"><?= hks_h($k) ?>:</span>
            <span style="font-weight:600;text-align:right;max-width:60%"><?= hks_h($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($n['hks_bildirim_no'] || $n['hks_kunye_no'] || $n['sent_at']): ?>
    <div class="card" style="padding:16px">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">HKS Sonuç</div>
        <?php
        $rows4 = [
            'HKS Bildirim No' => $n['hks_bildirim_no'],
            'HKS Künye No'    => $n['hks_kunye_no'],
            'Gönderim Zamanı' => $n['sent_at'] ? date('d.m.Y H:i', strtotime($n['sent_at'])) : '',
        ];
        foreach ($rows4 as $k => $v): if (!$v) continue; ?>
        <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:.88rem">
            <span style="color:var(--muted)"><?= hks_h($k) ?>:</span>
            <span style="font-weight:600;text-align:right;max-width:60%"><?= hks_h($v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Son Kontrol bloğu (düzenlenebilir durumlar) -->
<?php if ($can_write && in_array($n['status'], ['draft','ready','failed'], true)): ?>
<div class="card" style="padding:18px 20px;margin-bottom:16px">
    <h3 style="margin-top:0">📋 HKS'ye Gönderilmeden Önce Kontrol Edilecek Bilgiler</h3>
    <table class="hks-check-table">
        <?php foreach ($preview as $k => $v): ?>
        <tr><td><?= hks_h($k) ?></td><td><?= $v !== '' ? hks_h($v) : '<span style="color:var(--muted);font-weight:400">—</span>' ?></td></tr>
        <?php endforeach; ?>
        <tr><td>Oluşturan</td><td><?= hks_h($creator ?: '—') ?></td></tr>
        <tr><td>Son Güncelleme</td><td><?= hks_h($n['updated_at'] ? date('d.m.Y H:i', strtotime($n['updated_at'])) : '—') ?></td></tr>
    </table>

    <?php if (!empty($validation_errors)): ?>
    <div style="margin-top:14px;color:#991b1b;font-weight:600">❌ HKS'ye gönderilemez — eksik/hatalı alanları düzeltin.</div>
    <?php else: ?>
    <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-primary">✅ Kontrol Edildi Olarak İşaretle</button>
        <span style="font-size:.82rem;color:var(--muted);margin-left:8px">Doğrulama tekrar çalışır; hata yoksa durum "Kontrol Edildi" olur.</span>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Gönderim paneli (checked durumu) -->
<?php if ($n['status'] === 'checked'): ?>
<div class="hks-send-panel">
    <h3 style="margin-top:0">🚀 HKS'ye Gönderim</h3>
    <?php if (!empty($checker) || $n['checked_at']): ?>
    <p style="font-size:.84rem;color:var(--muted);margin-top:0">
        Kontrol eden: <strong><?= hks_h($checker ?: '—') ?></strong>
        <?= $n['checked_at'] ? ' · ' . hks_h(date('d.m.Y H:i', strtotime($n['checked_at']))) : '' ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($blockers)): ?>
        <div style="color:#991b1b;font-weight:600">Bu kayıt şu an HKS'ye gönderilemez:</div>
        <ul class="hks-blocker-list">
            <?php foreach ($blockers as $b): ?><li><?= hks_h($b) ?></li><?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p style="font-size:.88rem;color:var(--muted)">Aşağıdaki bilgiler HKS'ye gönderilecek (şifreler gösterilmez):</p>
        <table class="hks-check-table">
            <?php foreach ($preview as $k => $v): if ($v === '') continue; ?>
            <tr><td><?= hks_h($k) ?></td><td><?= hks_h($v) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <form method="post" id="sendForm" style="margin-top:14px">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="send">
            <?php if ($dup_warn): ?>
            <label class="hks-onay-row" style="color:#854d0e">
                <input type="checkbox" name="dup_ack" value="1">
                <span>Mükerrer uyarısını gördüm (<?= hks_h($dup_warn['local_no']) ?>), yine de göndermek istiyorum.</span>
            </label>
            <?php endif; ?>
            <label class="hks-onay-row">
                <input type="checkbox" name="onay" value="1" id="onayCheck">
                <span>Bilgileri kontrol ettim, HKS'ye gönderilmesini onaylıyorum.</span>
            </label>
            <button type="submit" class="btn btn-primary" id="sendBtn" disabled>
                🚀 Bu Bilgilerle HKS'ye Gönder
            </button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- İptal işlemi -->
<?php if ($can_write && in_array($n['status'], ['draft','ready','checked','failed'], true)): ?>
<div style="border-top:1px solid var(--border);padding-top:14px">
    <form method="post" style="display:inline">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn-ghost"
                onclick="return confirm('Bu bildirimi iptal etmek istediğinizden emin misiniz?')"
                style="color:var(--danger)">
            ✕ Bildirimi İptal Et
        </button>
    </form>
</div>
<?php endif; ?>

</div>

<script>
// Onay checkbox işaretlenmeden gönder butonu aktif olmaz (asıl güvenlik backend'de)
(function() {
    var chk = document.getElementById('onayCheck');
    var btn = document.getElementById('sendBtn');
    var frm = document.getElementById('sendForm');
    if (chk && btn) {
        chk.addEventListener('change', function() { btn.disabled = !chk.checked; });
    }
    // Çift tıklama engeli — submit sonrası butonu kilitle (asıl mükerrer engeli backend transaction'da)
    if (frm && btn) {
        frm.addEventListener('submit', function() {
            setTimeout(function() { btn.disabled = true; btn.textContent = 'Gönderiliyor...'; }, 0);
        });
    }
})();
</script>

<?php render_footer(); ?>
