<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

$q       = trim($_GET['q'] ?? '');
$type_f  = trim($_GET['type'] ?? '');
$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$fis_f   = trim($_GET['fis'] ?? '');   // '' all, '1' var, '0' yok
$muh_f   = trim($_GET['muh'] ?? '');   // '' all, '1' verildi, '0' bekliyor
$sayfa   = max(1, (int)($_GET['sayfa'] ?? 1));
$limit   = 50;
$offset  = ($sayfa - 1) * $limit;

// Hızlı tarih kısayolları
$hizli_tarih = trim($_GET['ht'] ?? '');
if ($hizli_tarih === 'bugun') { $tarih_b = $tarih_s = date('Y-m-d'); }
if ($hizli_tarih === 'bu_ay') { $tarih_b = date('Y-m-01'); $tarih_s = date('Y-m-t'); }
if ($hizli_tarih === 'gecen') { $tarih_b = date('Y-m-01', strtotime('last month')); $tarih_s = date('Y-m-t', strtotime('last month')); }

// WHERE
$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(category LIKE ? OR person_company LIKE ? OR description LIKE ? OR document_no LIKE ?)";
    $params   = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%"]);
}
if ($type_f !== '') { $where[] = "type=?";                 $params[] = $type_f; }
if ($tarih_b !== '') { $where[] = "transaction_date>=?";   $params[] = $tarih_b; }
if ($tarih_s !== '') { $where[] = "transaction_date<=?";   $params[] = $tarih_s; }
if ($fis_f === '1') { $where[] = "has_invoice=1"; }
if ($fis_f === '0') { $where[] = "has_invoice=0"; }
if ($muh_f === '1') { $where[] = "is_given_to_accountant=1"; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }

$wstr = implode(' AND ', $where);

$cnt_st = db()->prepare("SELECT COUNT(*) FROM account_transactions WHERE $wstr");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

$sum_st = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='gelir' THEN amount END),0) AS gelir,
    COALESCE(SUM(CASE WHEN type IN ('gider','havale','nakit') THEN amount END),0) AS gider
    FROM account_transactions WHERE $wstr AND currency='TRY'");
$sum_st->execute($params);
$sums = $sum_st->fetch();

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date DESC, id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();
$toplam_sayfa = (int)ceil($total / $limit);

render_header('Hesap Kayıtları');
render_flash();
?>
<div class="page-head">
    <div><h1>Hesap Kayıtları</h1><p class="muted">Toplam <?= $total ?> kayıt</p></div>
    <a href="hesap_kayit.php" class="btn btn-primary">+ Yeni</a>
</div>

<!-- Arama -->
<form method="get" class="search-row">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Kategori, kişi, açıklama...">
    <?php if ($type_f): ?><input type="hidden" name="type" value="<?= h($type_f) ?>"><?php endif; ?>
    <?php if ($tarih_b): ?><input type="hidden" name="tarih_bas" value="<?= h($tarih_b) ?>"><?php endif; ?>
    <?php if ($tarih_s): ?><input type="hidden" name="tarih_son" value="<?= h($tarih_s) ?>"><?php endif; ?>
    <?php if ($fis_f !== ''): ?><input type="hidden" name="fis" value="<?= h($fis_f) ?>"><?php endif; ?>
    <?php if ($muh_f !== ''): ?><input type="hidden" name="muh" value="<?= h($muh_f) ?>"><?php endif; ?>
    <button class="btn">Ara</button>
    <a href="hesap_liste.php" class="btn btn-ghost">Temizle</a>
</form>

<!-- Hızlı tarih -->
<div class="filter-pills" style="margin-bottom:6px">
    <a href="hesap_liste.php" class="pill<?= !$hizli_tarih && !$tarih_b ? ' active' : '' ?>">Tümü</a>
    <a href="hesap_liste.php?ht=bugun" class="pill">Bugün</a>
    <a href="hesap_liste.php?ht=bu_ay" class="pill">Bu Ay</a>
    <a href="hesap_liste.php?ht=gecen" class="pill">Geçen Ay</a>
</div>

<!-- Tür filtreleri -->
<div class="filter-pills">
    <?php
    $base_params = array_filter(['q'=>$q,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f]);
    ?>
    <a href="hesap_liste.php?<?= http_build_query($base_params) ?>" class="pill<?= $type_f === '' ? ' active' : '' ?>">Tümü</a>
    <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_merge($base_params, ['type'=>$t])) ?>"
       class="pill<?= $type_f === $t ? ' active' : '' ?>"
       style="<?= $type_f === $t ? 'background:' . hesap_type_color($t) . ';color:#fff;border-color:' . hesap_type_color($t) : '' ?>">
        <?= hesap_type_label($t) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Fiş / Muhasebe filtresi -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">
    <select onchange="location.href=updateParam('fis',this.value)" class="btn btn-ghost" style="padding:4px 8px">
        <option value="" <?= $fis_f === '' ? 'selected' : '' ?>>Fiş: Hepsi</option>
        <option value="1" <?= $fis_f === '1' ? 'selected' : '' ?>>Fiş var</option>
        <option value="0" <?= $fis_f === '0' ? 'selected' : '' ?>>Fiş yok ⚠</option>
    </select>
    <select onchange="location.href=updateParam('muh',this.value)" class="btn btn-ghost" style="padding:4px 8px">
        <option value="" <?= $muh_f === '' ? 'selected' : '' ?>>Muhasebe: Hepsi</option>
        <option value="0" <?= $muh_f === '0' ? 'selected' : '' ?>>Bekliyor</option>
        <option value="1" <?= $muh_f === '1' ? 'selected' : '' ?>>Verildi</option>
    </select>
    <a href="hesap_export.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f])) ?>" class="btn btn-ghost">📊 Excel</a>
    <a href="hesap_yazdir.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s])) ?>" class="btn btn-ghost" target="_blank">🖨️ Yazdır</a>
</div>

<!-- Tarih aralığı seçici -->
<div class="filter-pills">
    <form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <?php foreach (['q'=>$q,'type'=>$type_f,'fis'=>$fis_f,'muh'=>$muh_f] as $k => $v): if ($v !== ''): ?>
        <input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>">
        <?php endif; endforeach; ?>
        <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <span>—</span>
        <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>" class="btn btn-ghost" style="padding:4px 8px">
        <button class="btn btn-sm">Uygula</button>
    </form>
</div>

<!-- Özet -->
<?php if ($total > 0): ?>
<div class="hesap-stat-grid" style="grid-template-columns:repeat(3,1fr);margin:12px 0">
    <div class="hesap-stat green"><span>Toplam Gelir</span><strong><?= fmt_para((float)$sums['gelir']) ?></strong></div>
    <div class="hesap-stat red"><span>Toplam Gider</span><strong><?= fmt_para((float)$sums['gider']) ?></strong></div>
    <div class="hesap-stat <?= ((float)$sums['gelir'] - (float)$sums['gider']) >= 0 ? 'green' : 'red' ?>">
        <span>Net</span><strong><?= fmt_para((float)$sums['gelir'] - (float)$sums['gider']) ?></strong>
    </div>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="empty">
    <p>Kayıt bulunamadı.</p>
    <a href="hesap_kayit.php" class="btn btn-primary">Yeni Kayıt Ekle</a>
</div>
<?php else: ?>

<!-- PC: Tablo -->
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Tarih</th>
    <th>Tür</th>
    <th>Kategori</th>
    <th>Açıklama / Kişi</th>
    <th class="num">Tutar</th>
    <th>Ödeme</th>
    <th>Fiş</th>
    <th>Muh.</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
    <td><span class="hesap-type-badge" style="background:<?= hesap_type_color($r['type']) ?>"><?= hesap_type_label($r['type']) ?></span></td>
    <td><?= h($r['category']) ?></td>
    <td><?= h($r['person_company'] ?: $r['description']) ?></td>
    <td class="num strong <?= in_array($r['type'], ['gelir']) ? 'text-green' : 'text-red' ?>"><?= fmt_para((float)$r['amount'], $r['currency']) ?></td>
    <td class="muted"><?= hesap_payment_label($r['payment_method']) ?></td>
    <?php $fd = hesap_fis_durumu($r); ?>
    <td title="<?= h($fd['label']) ?>"><?= $fd['var'] ? '✓' : '<span style="color:var(--danger)">⚠</span>' ?></td>
    <td><?= $r['is_given_to_accountant'] ? '✓' : '<span style="color:var(--warn)">•</span>' ?></td>
    <td class="actions-col">
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Mobil: Kartlar -->
<div class="card-list mobile-only">
<?php foreach ($rows as $r): ?>
<div class="record-card">
    <div class="record-card-head">
        <div>
            <strong><?= h($r['category'] ?: hesap_type_label($r['type'])) ?></strong>
            <?php if ($r['person_company']): ?>
            <div class="record-card-firma"><?= h($r['person_company']) ?></div>
            <?php endif; ?>
            <?php $fd = hesap_fis_durumu($r); ?>
            <div class="muted"><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?>
                <?php if (!$fd['var']): ?> · <span style="color:var(--danger)"><?= h($fd['kisa']) ?></span><?php endif; ?>
                <?php if (!$r['is_given_to_accountant']): ?> · <span style="color:var(--warn)">Bekliyor</span><?php endif; ?>
            </div>
        </div>
        <span class="hesap-amount <?= in_array($r['type'], ['gelir']) ? 'positive' : 'negative' ?>" style="font-size:1.1rem;font-weight:700">
            <?= (in_array($r['type'], ['gelir']) ? '+' : '-') . fmt_para((float)$r['amount'], $r['currency']) ?>
        </span>
    </div>
    <?php if ($r['description']): ?>
    <div class="record-card-body"><div class="muted"><?= h($r['description']) ?></div></div>
    <?php endif; ?>
    <div class="record-card-actions">
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Sayfalama -->
<?php if ($toplam_sayfa > 1): ?>
<div style="display:flex;justify-content:center;gap:8px;margin:16px 0;flex-wrap:wrap">
    <?php for ($p = 1; $p <= $toplam_sayfa; $p++): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f,'sayfa'=>$p])) ?>"
       class="btn btn-sm <?= $p === $sayfa ? 'btn-primary' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function updateParam(key, val) {
    var params = new URLSearchParams(location.search);
    if (val) params.set(key, val); else params.delete(key);
    params.delete('sayfa');
    return location.pathname + '?' + params.toString();
}
</script>
<?php render_footer(); ?>
