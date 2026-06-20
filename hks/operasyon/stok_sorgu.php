<?php
// HKS Operasyon — Stok Sorgulama
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';

$f_urun  = trim($_GET['urun'] ?? '');
$f_kunye = trim($_GET['kunye'] ?? '');
$f_depo  = trim($_GET['depo'] ?? '');
$filters = [];
if ($f_urun)  $filters['urun']  = $f_urun;
if ($f_kunye) $filters['kunye'] = $f_kunye;
if ($f_depo)  $filters['depo']  = $f_depo;

$stocks       = $op_repo->listStock($filters);
$stock_queries = array_filter($op_repo->listQueries(20), fn($q) => in_array($q['query_type'], ['kunye','referans_kunye','toplu_kunye'], true));

$op_page_title  = 'HKS Stok Sorgulama';
$op_active_tab  = 'raporlama';
$op_active_menu = 'stok_sorgu.php';
include __DIR__ . '/views/_layout_start.php';
?>

<div class="hks-op-note info">
    ℹ️ Bu liste, HKS'ye gönderilmiş bildirimler ve HKS sorgularından oluşan <strong>yerel stok</strong> takibidir. HKS resmi stoğunun yerine geçmez.
</div>

<form method="get" action="stok_sorgu.php" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
    <input type="text" name="urun"  value="<?= hks_h($f_urun) ?>"  placeholder="Ürün ara..."  style="padding:8px 11px;border:1px solid var(--border);border-radius:7px;min-width:160px">
    <input type="text" name="kunye" value="<?= hks_h($f_kunye) ?>" placeholder="Künye No..."  style="padding:8px 11px;border:1px solid var(--border);border-radius:7px;min-width:150px">
    <input type="text" name="depo"  value="<?= hks_h($f_depo) ?>"  placeholder="Depo / işyeri..." style="padding:8px 11px;border:1px solid var(--border);border-radius:7px;min-width:150px">
    <button type="submit" class="hks-op-btn hks-op-btn-ghost">Filtrele</button>
    <?php if ($f_urun || $f_kunye || $f_depo): ?>
    <a href="stok_sorgu.php" class="hks-op-btn hks-op-btn-ghost">✕ Temizle</a>
    <?php endif; ?>
</form>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    <a href="kunye_sorgu.php" class="hks-op-btn hks-op-btn-ghost">🔎 HKS'den Künye/Stok Sorgula</a>
    <?php if (is_admin()): ?>
    <button type="button" id="btnRebuild" class="hks-op-btn hks-op-btn-ghost">🔄 Yerel Stoku Yeniden Hesapla</button>
    <?php endif; ?>
</div>
<div id="rebuildResult" class="hks-op-result" style="display:none"></div>

<p class="hks-op-section-title">Stok Listesi</p>
<?php if (empty($stocks)): ?>
<div class="hks-op-note empty">
    <?php if ($f_urun || $f_kunye || $f_depo): ?>
    Bu kriterlere uygun stok kaydı bulunamadı.
    <?php else: ?>
    Henüz HKS'den stok/künye verisi çekilmedi.<br>
    <small>HKS'ye gönderilen bildirimler ve künye sorguları burada stok olarak görünür.</small>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr>
        <th>Künye No</th><th>Ürün</th><th>Depo</th><th>Ref. Künye</th>
        <th style="text-align:right">Giriş</th><th style="text-align:right">Çıkış</th><th style="text-align:right">Kalan</th>
        <th>Birim</th><th>Güncelleme</th><th>Durum</th>
    </tr></thead>
    <tbody>
    <?php foreach ($stocks as $s):
        $kalan = (float)$s['kalan_miktar'];
        $kunye_ok = !empty($s['hks_kunye_no']);
    ?>
    <tr<?= $kalan < 0 ? ' style="background:#fef2f2"' : (!$kunye_ok ? ' style="background:#fffbeb"' : '') ?>>
        <td><?= $s['hks_kunye_no'] ? hks_h($s['hks_kunye_no']) : '<span class="muted">—</span>' ?></td>
        <td><?= hks_h($s['urun']) ?></td>
        <td><?= hks_h($s['depo'] ?? '') ?></td>
        <td><?= $s['reference_kunye_no'] ? hks_h($s['reference_kunye_no']) : '<span class="muted">—</span>' ?></td>
        <td style="text-align:right"><?= number_format((float)$s['giris_miktar'], 3, ',', '.') ?></td>
        <td style="text-align:right"><?= number_format((float)$s['cikis_miktar'], 3, ',', '.') ?></td>
        <td style="text-align:right;font-weight:700;<?= $kalan < 0 ? 'color:var(--danger)' : ($kalan == 0 ? 'color:var(--muted)' : 'color:var(--success)') ?>"><?= number_format($kalan, 3, ',', '.') ?></td>
        <td><?= hks_h($s['birim'] ?? 'KG') ?></td>
        <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= hks_h(date('d.m.Y H:i', strtotime($s['updated_at']))) ?></td>
        <td style="font-size:.8rem">
            <?php if ($kalan < 0): ?><span style="color:var(--danger)">⚠️ Eksi</span>
            <?php elseif (!$kunye_ok): ?><span style="color:#92400e">⚠️ Künye Yok</span>
            <?php elseif ($kalan == 0): ?><span class="muted">Tüketildi</span>
            <?php else: ?><span style="color:var(--success)">✅ Mevcut</span><?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="muted" style="font-size:.82rem;margin-top:8px"><?= count($stocks) ?> satır.</p>
<?php endif; ?>

<?php if ($stock_queries): ?>
<p class="hks-op-section-title" style="margin-top:18px">Son HKS Künye/Stok Sorguları</p>
<div class="table-wrap">
<table class="hks-op-table">
    <thead><tr><th>Tarih</th><th>Tür</th><th>Değer</th><th>Sonuç</th></tr></thead>
    <tbody>
    <?php foreach ($stock_queries as $q): ?>
    <tr>
        <td style="white-space:nowrap;color:var(--muted);font-size:.8rem"><?= hks_h(date('d.m.Y H:i', strtotime($q['created_at']))) ?></td>
        <td><?= hks_h($q['query_type']) ?></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= hks_h($q['query_value']) ?></td>
        <td><span style="color:<?= $q['result_status'] === 'ok' ? 'var(--success)' : 'var(--danger)' ?>"><?= hks_h($q['result_status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<script>
<?php if (is_admin()): ?>
document.getElementById('btnRebuild').addEventListener('click', function(){
    if (!confirm('Yerel stok tablosu yeniden hesaplanacak. Devam edilsin mi?')) return;
    var btn = this, res = document.getElementById('rebuildResult');
    btn.disabled = true;
    fetch('../ajax.php?action=rebuild_stock', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'csrf='+encodeURIComponent(document.querySelector('meta[name="csrf-token"]').content)
    }).then(function(r){return r.json();}).then(function(d){
        res.style.display='block'; res.className='hks-op-result '+(d.ok?'ok':'err');
        res.textContent = (d.ok?'✅ ':'❌ ')+(d.message||'');
        if (d.ok) setTimeout(function(){ location.reload(); }, 1200);
    }).catch(function(){ alert('İstek gönderilemedi.'); })
    .finally(function(){ btn.disabled=false; });
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
