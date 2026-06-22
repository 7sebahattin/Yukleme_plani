<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (!can('hks.read') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.read).');
}

$op_repo         = new HksRepository(db());
$op_settings     = $op_repo->getSettings();
$op_company_name = $op_settings['firma_adi'] ?? 'AGRONATURAL';
$op_env          = $op_settings['environment'] ?? 'test';

// Son bildirimleri çek (varsa)
$notifications = [];
try {
    $notifications = $op_repo->listNotifications([], 20);
} catch (Throwable) {
    $notifications = [];
}

include __DIR__ . '/_layout.php';
hks_mob_start('Bildirimler', 'bildirimler');

$bell_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';

function mob_status_badge(string $status): string {
    $map = [
        'draft'        => ['label' => 'Taslak',    'cls' => 'mob-badge-draft'],
        'ready'        => ['label' => 'Hazır',      'cls' => 'mob-badge-ready'],
        'checked'      => ['label' => 'Onaylı',     'cls' => 'mob-badge-ready'],
        'send_pending' => ['label' => 'Gönderiliyor','cls'=> 'mob-badge-ready'],
        'sent'         => ['label' => 'Gönderildi', 'cls' => 'mob-badge-sent'],
        'failed'       => ['label' => 'Hata',       'cls' => 'mob-badge-failed'],
        'cancelled'    => ['label' => 'İptal',      'cls' => 'mob-badge-draft'],
    ];
    $d = $map[$status] ?? ['label' => hks_mob_h($status), 'cls' => 'mob-badge-draft'];
    return '<span class="mob-badge ' . $d['cls'] . '">' . $d['label'] . '</span>';
}
?>

<?php if (empty($notifications)): ?>
<div class="mob-list-empty">
    <?= $bell_icon ?>
    <p>Henüz bildirim oluşturulmadı</p>
</div>
<?php else: ?>
<?php foreach ($notifications as $n): ?>
<?php
    $urun_label = $n['reference_kunye_urun'] ?? ($n['urun'] ?? '—');
    $birim_label = $n['reference_kunye_birim'] ?? ($n['birim'] ?? 'KG');
    $miktar_val  = (float)($n['miktar'] ?? 0);
?>
<a class="mob-notif-item" href="taslak_detay.php?id=<?= (int)$n['id'] ?>">
    <div class="mob-notif-meta">
        <?= $n['created_at'] ? hks_mob_h(date('d.m.Y H:i', strtotime($n['created_at']))) : '' ?>
        &nbsp;·&nbsp;<?= hks_mob_h($n['local_no'] ?? '') ?>
        &nbsp;<?= mob_status_badge($n['status'] ?? 'draft') ?>
    </div>
    <div class="mob-notif-title">
        <?= hks_mob_h($urun_label) ?>
        <?php if ($miktar_val > 0): ?>
            — <?= hks_mob_h(number_format($miktar_val, 0, ',', '.')) ?> <?= hks_mob_h($birim_label) ?>
        <?php endif; ?>
    </div>
    <?php if (!empty($n['reference_kunye_no'])): ?>
    <div class="mob-notif-meta" style="margin-top:3px;">Künye: <?= hks_mob_h($n['reference_kunye_no']) ?></div>
    <?php endif; ?>
    <?php if (!empty($n['arac_plaka'])): ?>
    <div class="mob-notif-meta" style="margin-top:2px;"><?= hks_mob_h($n['arac_plaka']) ?></div>
    <?php endif; ?>
</a>
<?php endforeach; ?>

<div style="text-align:center;padding:12px 0;">
    <a href="../operasyon/son_bildirimler.php" style="color:#1565c0;font-size:14px;text-decoration:none;">
        Tüm Bildirimler (Masaüstü) →
    </a>
</div>
<?php endif; ?>

<?php hks_mob_end(); ?>
