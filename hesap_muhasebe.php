<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

// Toplu güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toplu_muh'])) {
    csrf_check($_POST['csrf'] ?? null);
    $ids = array_map('intval', $_POST['secili'] ?? []);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("UPDATE account_transactions SET is_given_to_accountant=1 WHERE id IN ($placeholders)")->execute($ids);

        // Audit — toplu durum güncelleme (tek tek payload yazılmaz)
        audit_log_event('bulk_update', 'hesap', null, null, [
            'operation'      => 'muhasebeye_verildi',
            'affected_count' => count($ids),
        ]);

        set_flash('success', count($ids) . ' kayıt muhasebeye verildi olarak işaretlendi.');
    }
    header('Location: hesap_muhasebe.php');
    exit;
}

$tarih_b        = trim($_GET['tarih_bas'] ?? '');
$tarih_s        = trim($_GET['tarih_son'] ?? '');
$sadece_bekleyen = (int)($_GET['bekleyen'] ?? 1);

$where = ['1=1'];
$params = [];
if ($sadece_bekleyen) { $where[] = "is_given_to_accountant=0"; }
if ($tarih_b) { $where[] = "transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s) { $where[] = "transaction_date<=?"; $params[] = $tarih_s; }
$wstr = implode(' AND ', $where);

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date ASC, id ASC");
$st->execute($params);
$rows = $st->fetchAll();

// Kategoriye göre gruplama
$gruplar = [];
foreach ($rows as $r) {
    $gruplar[$r['category'] ?: hesap_type_label($r['type'])][] = $r;
}

render_header('Muhasebe Dökümü');
render_flash();
?>
<div class="page-head">
    <div>
        <h1>🗂️ Muhasebe Dökümü</h1>
        <p class="muted"><?= count($rows) ?> kayıt</p>
    </div>
    <div>
        <a href="hesap_export.php?<?= http_build_query(array_filter(['muh'=>(string)$sadece_bekleyen,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost">📊 Excel</a>
        <a href="hesap_yazdir.php?<?= http_build_query(array_filter(['muh'=>(string)$sadece_bekleyen,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost" target="_blank">🖨️ Yazdır</a>
    </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center">
    <form method="get" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <span>—</span>
        <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <select name="bekleyen" class="btn btn-ghost" style="padding:4px 8px">
            <option value="1" <?= $sadece_bekleyen ? 'selected' : '' ?>>Sadece bekleyenler</option>
            <option value="0" <?= !$sadece_bekleyen ? 'selected' : '' ?>>Tümü</option>
        </select>
        <button class="btn btn-sm">Filtrele</button>
    </form>
    <a href="hesap_liste.php" class="btn btn-ghost">← Tüm Kayıtlar</a>
</div>

<?php if (empty($rows)): ?>
<div class="empty"><p>Bekleyen muhasebe kaydı yok.</p></div>
<?php else: ?>

<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="toplu_muh" value="1">

<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <button type="button" onclick="document.querySelectorAll('.muh-chk').forEach(function(c){c.checked=true})" class="btn btn-sm btn-ghost">Tümünü Seç</button>
    <button type="button" onclick="document.querySelectorAll('.muh-chk').forEach(function(c){c.checked=false})" class="btn btn-sm btn-ghost">Seçimi Kaldır</button>
    <button type="submit" class="btn btn-sm btn-primary">✓ Seçilenleri Muhasebeciye Verdim</button>
</div>

<?php $sira = 0; foreach ($gruplar as $grup_adi => $grup_rows): ?>
<div class="card" style="margin-bottom:16px">
    <div class="card-head">
        <h2><?= h($grup_adi) ?> <span class="muted">(<?= count($grup_rows) ?> kayıt)</span></h2>
        <strong><?= fmt_para((float)array_sum(array_column($grup_rows, 'amount'))) ?></strong>
    </div>
    <div class="table-wrap">
    <table class="data-table">
    <thead><tr>
        <th style="width:30px">
            <input type="checkbox" onchange="this.closest('table').querySelectorAll('.muh-chk').forEach(function(c){c.checked=this.checked}.bind(this))">
        </th>
        <th>#</th>
        <th>Tarih</th>
        <th>Tür</th>
        <th>Kişi/Firma</th>
        <th>Açıklama</th>
        <th>Belge No</th>
        <th class="num">Tutar</th>
        <th>Ödeme</th>
        <th>Fiş</th>
    </tr></thead>
    <tbody>
    <?php foreach ($grup_rows as $r): $sira++; ?>
    <tr>
        <td><input type="checkbox" name="secili[]" value="<?= $r['id'] ?>" class="muh-chk"></td>
        <td class="muted"><?= $sira ?></td>
        <td class="muted"><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
        <td><span class="hesap-type-badge" style="background:<?= hesap_type_color($r['type']) ?>"><?= hesap_type_label($r['type']) ?></span></td>
        <td><?= h($r['person_company']) ?></td>
        <td><?= h($r['description']) ?></td>
        <td class="muted"><?= h($r['document_no']) ?></td>
        <td class="num strong"><?= fmt_para((float)$r['amount'], $r['currency']) ?></td>
        <td class="muted"><?= hesap_payment_label($r['payment_method']) ?></td>
        <td><?= $r['has_invoice'] ? '✓' : '<span style="color:var(--danger)">⚠</span>' ?></td>
    </tr>
    <?php if ($r['notes']): ?>
    <tr style="background:#fafafa">
        <td></td>
        <td colspan="9" style="font-size:.8rem;color:var(--muted);font-style:italic">📝 <?= h($r['notes']) ?></td>
    </tr>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<div style="margin-top:8px">
    <button type="submit" class="btn btn-primary">✓ Seçilenleri Muhasebeciye Verdim</button>
</div>
</form>
<?php endif; ?>
<?php render_footer(); ?>
