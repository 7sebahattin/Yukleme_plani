<?php
// HKS Operasyon — Ana Panel (Dashboard)
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write') && !is_admin()) {
    http_response_code(403); die('Bu sayfaya erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';   // çıktıdan önce — firma guard + $op_* değişkenleri

$counts     = $op_repo->countNotificationsByStatus();
$recent     = $op_repo->listNotifications([], 6);
$can_write  = !function_exists('can') || can('hks.write') || can('records.write');

$op_page_title  = 'HKS Operasyon — Panel';
$op_active_tab  = 'bildirimci';
$op_active_menu = '';
include __DIR__ . '/views/_layout_start.php';
?>

<?php if (!hks_check_soap()): ?>
<div class="hks-op-note warn">⚠️ PHP SOAP eklentisi aktif değil — HKS canlı sorguları çalışmayabilir.</div>
<?php elseif (!$op_queries_enabled): ?>
<div class="hks-op-note warn">⚠️ HKS bağlantı bilgileri eksik. Canlı sorgular için <strong>HKS Teknik → Ayarlar</strong> bölümünü tamamlayın.</div>
<?php endif; ?>

<p class="hks-op-section-title">Bildirimci İşlemleri</p>
<div class="hks-op-grid" style="margin-bottom:18px">
    <?php if ($can_write): ?>
    <a href="bildirim_yeni.php" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.6rem">📝</div>
        <div style="font-weight:700;margin-top:6px">e-Bildirim Oluştur</div>
        <div class="muted" style="font-size:.82rem">Adım adım yeni bildirim</div>
    </a>
    <?php endif; ?>
    <a href="bildirim_sorgu.php" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.6rem">🔎</div>
        <div style="font-weight:700;margin-top:6px">Bildirim Sorgulama</div>
        <div class="muted" style="font-size:.82rem">Yaptığım / bana yapılan</div>
    </a>
    <a href="kunye_sorgu.php" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.6rem">🔖</div>
        <div style="font-weight:700;margin-top:6px">Künye Sorgulama</div>
        <div class="muted" style="font-size:.82rem">Referans künye & kişi</div>
    </a>
    <a href="son_bildirimler.php" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.6rem">🗂</div>
        <div style="font-weight:700;margin-top:6px">Son Bildirimler</div>
        <div class="muted" style="font-size:.82rem">En son ne oldu?</div>
    </a>
</div>

<p class="hks-op-section-title">Raporlama</p>
<div class="hks-op-grid" style="margin-bottom:18px">
    <a href="stok_sorgu.php" class="hks-op-card" style="text-decoration:none;color:inherit">
        <div style="font-size:1.6rem">📦</div>
        <div style="font-weight:700;margin-top:6px">Stok Sorgulama</div>
        <div class="muted" style="font-size:.82rem">Künye / kalan miktar</div>
    </a>
</div>

<p class="hks-op-section-title">Durum Özeti</p>
<div class="hks-op-grid">
    <div class="hks-op-card" style="border-left:3px solid var(--primary)">
        <div style="font-size:1.8rem;font-weight:700"><?= (int)$counts['draft'] ?></div>
        <div class="muted" style="font-size:.84rem">Taslak</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid #f59e0b">
        <div style="font-size:1.8rem;font-weight:700"><?= (int)($counts['ready'] + $counts['checked']) ?></div>
        <div class="muted" style="font-size:.84rem">Kontrol / Hazır</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid var(--danger)">
        <div style="font-size:1.8rem;font-weight:700"><?= (int)$counts['failed'] ?></div>
        <div class="muted" style="font-size:.84rem">Hatalı</div>
    </div>
    <div class="hks-op-card" style="border-left:3px solid var(--success)">
        <div style="font-size:1.8rem;font-weight:700"><?= (int)$counts['sent'] ?></div>
        <div class="muted" style="font-size:.84rem">Gönderildi</div>
    </div>
</div>

<?php if ($recent): ?>
<p class="hks-op-section-title" style="margin-top:18px">Son Bildirimler</p>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr><th>Local No</th><th>Tarih</th><th>Ürün</th><th>Miktar</th><th>Durum</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $n): ?>
    <tr>
        <td><a href="<?= $op_root ?>bildirim_view.php?id=<?= (int)$n['id'] ?>" style="font-weight:600"><?= hks_h($n['local_no']) ?></a></td>
        <td style="white-space:nowrap;color:var(--muted)"><?= $n['created_at'] ? hks_h(date('d.m.Y', strtotime($n['created_at']))) : '' ?></td>
        <td><?= hks_h($n['urun']) ?></td>
        <td style="text-align:right;white-space:nowrap"><?= number_format((float)$n['miktar'], 0, ',', '.') ?> <?= hks_h($n['birim']) ?></td>
        <td><?= hks_status_badge($n['status']) ?></td>
        <td><a href="<?= $op_root ?>bildirim_view.php?id=<?= (int)$n['id'] ?>" class="hks-op-btn hks-op-btn-ghost" style="padding:5px 10px;font-size:.8rem">Görüntüle</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
