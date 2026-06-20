<?php
// HKS Bildirim Listesi
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo = new HksRepository(db());

$filter_status = $_GET['status'] ?? '';
$filter_firma  = trim($_GET['firma'] ?? '');
$filter_urun   = trim($_GET['urun'] ?? '');

$filters = [];
if ($filter_status) $filters['status'] = $filter_status;
if ($filter_firma)  $filters['firma']  = $filter_firma;
if ($filter_urun)   $filters['urun']   = $filter_urun;

$notifications = $repo->listNotifications($filters, 200);
$counts        = $repo->countNotificationsByStatus();

$can_write = !function_exists('can') || can('hks.write') || can('records.write');

render_header('HKS Bildirimler');
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
</style>
<div class="hks-page" style="max-width:1200px;margin:0 auto">
<?php
$hks_active_tab = 'bildirimler.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>📋 Bildirimler</h1>
        <p class="muted">HKS bildirim taslakları ve gönderim durumları</p>
    </div>
    <div class="page-head-actions">
        <?php if ($can_write): ?>
        <a href="bildirim_form.php" class="btn btn-primary">+ Yeni Bildirim</a>
        <?php endif; ?>
    </div>
</div>

<!-- Durum filtreleri -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    <a href="bildirimler.php" class="btn btn-sm <?= $filter_status === '' ? 'btn-primary' : 'btn-ghost' ?>">
        Tümü (<?= array_sum($counts) ?>)
    </a>
    <?php foreach (['draft'=>'Taslak','ready'=>'Hazır','checked'=>'Kontrol Edildi','sent'=>'Gönderildi','failed'=>'Hatalı','cancelled'=>'İptal'] as $st => $lbl): ?>
    <a href="bildirimler.php?status=<?= hks_h($st) ?>" class="btn btn-sm <?= $filter_status === $st ? 'btn-primary' : 'btn-ghost' ?>">
        <?= hks_h($lbl) ?> (<?= $counts[$st] ?? 0 ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- Arama filtresi -->
<form method="get" action="bildirimler.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <input type="hidden" name="status" value="<?= hks_h($filter_status) ?>">
    <input type="text" name="firma" value="<?= hks_h($filter_firma) ?>" placeholder="Firma ara..." style="padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:.9rem;min-width:180px">
    <input type="text" name="urun" value="<?= hks_h($filter_urun) ?>" placeholder="Ürün ara..." style="padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:.9rem;min-width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filtrele</button>
    <?php if ($filter_firma || $filter_urun): ?>
    <a href="bildirimler.php?status=<?= hks_h($filter_status) ?>" class="btn btn-ghost btn-sm">✕ Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($notifications)): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted)">
    <?= $filter_status || $filter_firma || $filter_urun ? 'Filtreye uygun bildirim yok.' : 'Henüz bildirim oluşturulmamış.' ?>
    <?php if ($can_write): ?>
    <br><a href="bildirim_form.php" class="btn btn-primary" style="margin-top:12px">+ Yeni Bildirim Oluştur</a>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="table-wrap">
<table style="width:100%;border-collapse:collapse;font-size:.88rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Local No</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Tarih</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Firma</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Ürün</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Gidecek Yer</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Miktar</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Birim Fiyat</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Plaka</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Belge No</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Durum</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">HKS No</th>
            <th style="padding:8px 10px;border-bottom:2px solid var(--border)">İşlem</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($notifications as $n): ?>
    <tr>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)">
            <a href="bildirim_view.php?id=<?= (int)$n['id'] ?>" style="font-weight:600"><?= hks_h($n['local_no']) ?></a>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);white-space:nowrap;color:var(--muted);font-size:.82rem">
            <?= $n['created_at'] ? hks_h(date('d.m.Y', strtotime($n['created_at']))) : '' ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($n['firma']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($n['urun']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($n['gidecek_yer'] ?? '') ?: '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">
            <?= number_format((float)$n['miktar'], 0, ',', '.') ?> <?= hks_h($n['birim']) ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">
            <?= (float)($n['birim_fiyat'] ?? 0) > 0 ? hks_h(number_format((float)$n['birim_fiyat'], 2, ',', '.') . ' ' . (trim((string)($n['para_birimi'] ?? 'TL')) ?: 'TL')) : '<span style="color:var(--muted)">—</span>' ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($n['arac_plaka'] ?? '') ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($n['belge_no'] ?? '') ?: '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_status_badge($n['status']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);font-size:.82rem;color:var(--muted)">
            <?= hks_h($n['hks_bildirim_no'] ?? '') ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)">
            <a href="bildirim_view.php?id=<?= (int)$n['id'] ?>" class="btn btn-ghost btn-sm">Görüntüle</a>
            <?php if ($can_write && in_array($n['status'], ['draft','ready','failed'], true)): ?>
            <a href="bildirim_form.php?id=<?= (int)$n['id'] ?>" class="btn btn-ghost btn-sm">Düzenle</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<p style="margin-top:10px;color:var(--muted);font-size:.83rem"><?= count($notifications) ?> kayıt listelendi.</p>
<?php endif; ?>

</div>

<?php render_footer(); ?>
