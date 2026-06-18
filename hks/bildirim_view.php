<?php
// HKS Bildirim Görüntüleme
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

$can_write  = !function_exists('can') || can('hks.write') || can('records.write');
$can_send   = function_exists('can') && can('hks.send');
$settings   = $repo->getSettings();
$live_ok    = $settings && (int)($settings['live_send_enabled'] ?? 0) === 1;

// POST: İptal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_check($_POST['csrf'] ?? null);
    if ($_POST['action'] === 'cancel' && $can_write) {
        $repo->cancelNotification($id);
        audit_log_event('hks_notification_cancelled', 'hks_notifications', $id, null, ['status' => 'cancelled']);
        set_flash('success', 'Bildirim iptal edildi.');
        header('Location: bildirim_view.php?id=' . $id); exit;
    }
    if ($_POST['action'] === 'send' && $can_send && $live_ok) {
        // Canlı gönderim — HKS servis eşlemesi tamamlandığında aktifleşecek
        set_flash('info', 'HKS Bildirim Kaydet servisi henüz eşlenmedi. Canlı gönderim bu sprintte aktif değil.');
        header('Location: bildirim_view.php?id=' . $id); exit;
    }
}

$validation_errors = $n['validation_errors_json'] ? json_decode($n['validation_errors_json'], true) : [];

render_header('HKS Bildirim #' . $n['local_no']);
render_flash();
?>

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
        <?php if ($can_send && $live_ok && $n['status'] === 'ready'): ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="send">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('HKS\'ye gerçek bildirim gönderilecek. Onaylıyor musunuz?')">
                🚀 HKS\'ye Gönder
            </button>
        </form>
        <?php elseif ($n['status'] === 'ready'): ?>
        <span class="btn btn-ghost" style="opacity:.5" title="Canlı gönderim kapalı">🔒 Gönderim Kapalı</span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($validation_errors)): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:12px">
    <strong>⚠️ Eksik / Hatalı Alanlar:</strong>
    <ul style="margin:6px 0 0;padding-left:18px;font-size:.88rem">
        <?php foreach ($validation_errors as $e): ?>
        <li><?= hks_h($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($n['status'] === 'ready' && !$live_ok): ?>
<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:12px;font-size:.88rem">
    ℹ️ Canlı HKS gönderimi kapalı. Bu kayıt taslak olarak saklandı. Ayarlardan etkinleştirilebilir.
</div>
<?php endif; ?>

<?php if ($n['last_error']): ?>
<div style="background:#fef2f2;border:1px solid var(--danger);border-radius:8px;padding:12px 16px;margin-bottom:12px;font-size:.88rem">
    <strong>Son Hata:</strong> <?= hks_h($n['last_error']) ?>
</div>
<?php endif; ?>

<!-- Bildirim Detayları -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:16px">

    <div class="card" style="padding:16px">
        <div style="font-size:.78rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px">Temel Bilgiler</div>
        <?php
        $rows = [
            'Bildirim Türü' => $n['notification_type'],
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
            'Üretici Adı'  => $n['uretici_ad'],
            'Üretici TC/VKN' => $n['uretici_tc_vkn'],
            'Alıcı Adı'    => $n['alici_ad'],
            'Alıcı TC/VKN' => $n['alici_tc_vkn'],
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

<!-- İptal işlemi -->
<?php if ($can_write && in_array($n['status'], ['draft','ready','failed'], true)): ?>
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

<?php render_footer(); ?>
