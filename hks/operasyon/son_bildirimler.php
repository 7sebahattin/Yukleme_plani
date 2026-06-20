<?php
// HKS Operasyon — Son Bildirimler
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';

$local   = $op_repo->listNotifications([], 40);
$queries = $op_repo->listQueries(20);

// Kaynak etiketi: yerel kayıtta HKS no varsa "HKS Gönderim", yoksa "Yerel Taslak"
function op_kaynak_label(array $n): string {
    if (!empty($n['hks_bildirim_no']) || ($n['status'] ?? '') === 'sent') {
        return '<span class="hks-badge hks-badge-sent">HKS Gönderim</span>';
    }
    return '<span class="hks-badge hks-badge-draft">Yerel Taslak</span>';
}

$op_page_title  = 'HKS Son Bildirimler';
$op_active_tab  = 'bildirimci';
$op_active_menu = 'son_bildirimler.php';
include __DIR__ . '/views/_layout_start.php';
?>
<style>
.hks-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.74rem; font-weight:600; }
.hks-badge-draft { background:#e5e7eb; color:#374151; }
.hks-badge-sent  { background:#d1fae5; color:#065f46; }
.hks-badge-query { background:#dbeafe; color:#1e40af; }
</style>

<div class="hks-op-note info">
    ℹ️ Bu liste sistemin en son durumunu gösterir: yerel taslaklar, HKS'ye gönderilmiş kayıtlar ve son HKS sorguları. Kaynak her satırda belirtilir.
</div>

<p class="hks-op-section-title">Yerel Bildirimler &amp; Gönderimler</p>
<?php if (empty($local)): ?>
<div class="hks-op-note empty">Henüz bildirim oluşturulmadı.</div>
<?php else: ?>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr>
        <th>Tarih</th><th>Local No</th><th>HKS No</th><th>Ürün</th><th>Gidecek Yer</th>
        <th style="text-align:right">Miktar</th><th style="text-align:right">Birim Fiyat</th>
        <th>Plaka</th><th>Belge No</th><th>Durum</th><th>Kaynak</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($local as $n): ?>
    <tr>
        <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= $n['created_at'] ? hks_h(date('d.m.Y H:i', strtotime($n['created_at']))) : '' ?></td>
        <td style="font-weight:600"><?= hks_h($n['local_no']) ?></td>
        <td style="font-size:.8rem;color:var(--muted)"><?= $n['hks_bildirim_no'] ? hks_h($n['hks_bildirim_no']) : '—' ?></td>
        <td><?= hks_h($n['urun']) ?></td>
        <td><?= hks_h($n['gidecek_yer'] ?? '') ?: '<span class="muted">—</span>' ?></td>
        <td style="text-align:right;white-space:nowrap"><?= number_format((float)$n['miktar'], 0, ',', '.') ?> <?= hks_h($n['birim']) ?></td>
        <td style="text-align:right;white-space:nowrap"><?= (float)($n['birim_fiyat'] ?? 0) > 0 ? hks_h(number_format((float)$n['birim_fiyat'], 2, ',', '.') . ' ' . (trim((string)($n['para_birimi'] ?? 'TL')) ?: 'TL')) : '<span class="muted">—</span>' ?></td>
        <td><?= hks_h($n['arac_plaka'] ?? '') ?: '<span class="muted">—</span>' ?></td>
        <td><?= hks_h($n['belge_no'] ?? '') ?: '<span class="muted">—</span>' ?></td>
        <td><?= hks_status_badge($n['status']) ?></td>
        <td><?= op_kaynak_label($n) ?></td>
        <td><a href="../bildirim_view.php?id=<?= (int)$n['id'] ?>" class="hks-op-btn hks-op-btn-ghost" style="padding:4px 9px;font-size:.78rem">Detay</a></td>
    </tr>
    <?php if (!empty($n['last_error']) && $n['status'] === 'failed'): ?>
    <tr><td colspan="12" style="background:#fef2f2;color:#991b1b;font-size:.8rem">⚠️ Hata: <?= hks_h(mb_substr((string)$n['last_error'], 0, 200)) ?></td></tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<p class="hks-op-section-title" style="margin-top:18px">Son HKS Sorguları</p>
<?php if (empty($queries)): ?>
<div class="hks-op-note empty">Henüz HKS sorgusu yapılmadı.</div>
<?php else: ?>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr><th>Tarih</th><th>Tür</th><th>Değer</th><th>Sonuç</th><th>Kaynak</th></tr></thead>
    <tbody>
    <?php foreach ($queries as $q): ?>
    <tr>
        <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= hks_h(date('d.m.Y H:i', strtotime($q['created_at']))) ?></td>
        <td><?= hks_h($q['query_type']) ?></td>
        <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= hks_h($q['query_value']) ?></td>
        <td><span style="color:<?= $q['result_status'] === 'ok' ? 'var(--success)' : 'var(--danger)' ?>"><?= hks_h($q['result_status']) ?></span></td>
        <td><span class="hks-badge hks-badge-query">HKS Sorgu</span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
