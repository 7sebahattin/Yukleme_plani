<?php
// HKS Stok Kontrol
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo = new HksRepository(db());

// Stok hesaplama — sadece sent bildirimleri dikkate alır
$filter_urun  = trim($_GET['urun'] ?? '');
$filter_depo  = trim($_GET['depo'] ?? '');
$filter_kunye = trim($_GET['kunye'] ?? '');

$filters = [];
if ($filter_urun)  $filters['urun']  = $filter_urun;
if ($filter_depo)  $filters['depo']  = $filter_depo;
if ($filter_kunye) $filters['kunye'] = $filter_kunye;

$stocks = $repo->listStock($filters);

render_header('HKS Stok Kontrol');
render_flash();
?>

<div class="hks-page" style="max-width:1200px;margin:0 auto">
<?php
$hks_active_tab = 'stok.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>📦 Stok Kontrol</h1>
        <p class="muted">HKS bildirimlere dayalı yerel stok takibi (gönderilmiş bildirimler)</p>
    </div>
    <div class="page-head-actions">
        <?php if (is_admin()): ?>
        <button type="button" id="btnRebuildStock" class="btn btn-ghost">🔄 Stoku Yeniden Hesapla</button>
        <?php endif; ?>
    </div>
</div>

<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.83rem;color:#1e40af">
    ℹ️ Bu stok HKS resmi stok yerine geçmez. Yalnızca yerel operasyon takibi için kullanılır.
    Sadece HKS'ye gönderilmiş bildirimler stoka yansır.
</div>

<!-- Filtreler -->
<form method="get" action="stok.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <input type="text" name="urun" value="<?= hks_h($filter_urun) ?>" placeholder="Ürün ara..."
           style="padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:.9rem;min-width:180px">
    <input type="text" name="depo" value="<?= hks_h($filter_depo) ?>" placeholder="Depo ara..."
           style="padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:.9rem;min-width:150px">
    <input type="text" name="kunye" value="<?= hks_h($filter_kunye) ?>" placeholder="Künye No..."
           style="padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:.9rem;min-width:160px">
    <button type="submit" class="btn btn-ghost btn-sm">Filtrele</button>
    <?php if ($filter_urun || $filter_depo || $filter_kunye): ?>
    <a href="stok.php" class="btn btn-ghost btn-sm">✕ Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($stocks)): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted)">
    <?php if ($filter_urun || $filter_depo || $filter_kunye): ?>
    Filtreye uygun stok kaydı bulunamadı.
    <?php else: ?>
    Henüz stok hareketi yok.<br>
    <small>HKS'ye gönderilmiş bildirimler stok tablosuna yansır.</small>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="table-wrap">
<table style="width:100%;border-collapse:collapse;font-size:.88rem">
    <thead>
        <tr style="background:var(--bg)">
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Ürün</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Depo</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Künye No</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Ref. Künye</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Giriş</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Çıkış</th>
            <th style="padding:8px 10px;text-align:right;border-bottom:2px solid var(--border)">Kalan</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Birim</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Son Güncelleme</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:2px solid var(--border)">Durum</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($stocks as $stk):
        $kalan = (float)$stk['kalan_miktar'];
        $kunye_ok = !empty($stk['hks_kunye_no']);
        $row_style = '';
        if ($kalan < 0) $row_style = 'background:#fef2f2';
        elseif (!$kunye_ok) $row_style = 'background:#fffbeb';
    ?>
    <tr <?= $row_style ? "style=\"{$row_style}\"" : '' ?>>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($stk['urun']) ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($stk['depo'] ?? '') ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)">
            <?= $stk['hks_kunye_no'] ? hks_h($stk['hks_kunye_no']) : '<span class="muted">—</span>' ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)">
            <?= $stk['reference_kunye_no'] ? hks_h($stk['reference_kunye_no']) : '<span class="muted">—</span>' ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right">
            <?= number_format((float)$stk['giris_miktar'], 3, ',', '.') ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right">
            <?= number_format((float)$stk['cikis_miktar'], 3, ',', '.') ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);text-align:right;font-weight:700;<?= $kalan < 0 ? 'color:var(--danger)' : ($kalan == 0 ? 'color:var(--muted)' : 'color:var(--success)') ?>">
            <?= number_format($kalan, 3, ',', '.') ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border)"><?= hks_h($stk['birim'] ?? 'KG') ?></td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);color:var(--muted);font-size:.82rem;white-space:nowrap">
            <?= hks_h(date('d.m.Y H:i', strtotime($stk['updated_at']))) ?>
        </td>
        <td style="padding:7px 10px;border-bottom:1px solid var(--border);font-size:.82rem">
            <?php if ($kalan < 0): ?>
            <span style="color:var(--danger)">⚠️ Eksi Stok</span>
            <?php elseif (!$kunye_ok): ?>
            <span style="color:#92400e">⚠️ Künye Yok</span>
            <?php elseif ($kalan == 0): ?>
            <span style="color:var(--muted)">Tüketildi</span>
            <?php else: ?>
            <span style="color:var(--success)">✅ Mevcut</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<p style="margin-top:10px;color:var(--muted);font-size:.83rem"><?= count($stocks) ?> satır.</p>
<?php endif; ?>

</div>

<div id="rebuild-result" style="display:none;padding:12px 16px;border-radius:8px;margin:12px auto;max-width:1200px"></div>

<script>
<?php if (is_admin()): ?>
document.getElementById('btnRebuildStock').addEventListener('click', function() {
    if (!confirm('Stok tablosu yeniden hesaplanacak. Devam?')) return;
    this.disabled = true;
    fetch('ajax.php?action=rebuild_stock', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
    })
    .then(r => r.json())
    .then(data => {
        var el = document.getElementById('rebuild-result');
        el.style.display = 'block';
        el.style.background = data.ok ? '#f0fdf4' : '#fef2f2';
        el.style.border = '1px solid ' + (data.ok ? 'var(--success)' : 'var(--danger)');
        el.style.color = data.ok ? '#065f46' : '#991b1b';
        el.textContent = (data.ok ? '✅ ' : '❌ ') + (data.message || '');
        if (data.ok) setTimeout(() => location.reload(), 1500);
    })
    .catch(() => alert('İstek gönderilemedi.'))
    .finally(() => document.getElementById('btnRebuildStock').disabled = false);
});
<?php endif; ?>
</script>

<?php render_footer(); ?>
