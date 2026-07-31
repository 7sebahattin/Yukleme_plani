<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

$q       = trim($_GET['q'] ?? '');
$type_f  = trim($_GET['type'] ?? '');
$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
$fis_f   = trim($_GET['fis'] ?? '');   // '' all, '1' var, '0' yok
$muh_f   = trim($_GET['muh'] ?? '');   // '' all, '1' verildi, '0' bekliyor
$durum_f = trim($_GET['durum'] ?? ''); // '' all, aksi hâlde durum kodu
if ($durum_f !== '' && !hesap_status_valid($durum_f)) $durum_f = '';
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
// B4: fiş filtresi rozetle aynı mantığa bağlandı (fotoğraf VEYA manuel fatura işareti)
if ($fis_f === '1') { $where[] = "(has_files=1 OR has_invoice=1)"; }
if ($fis_f === '0') { $where[] = "(has_files=0 AND has_invoice=0)"; }
if ($muh_f === '1') { $where[] = "is_given_to_accountant=1"; }
if ($muh_f === '0') { $where[] = "is_given_to_accountant=0"; }
if ($durum_f !== '') { $where[] = "status=?";              $params[] = $durum_f; }

// Kapsam: kendi kayıtlarım (+ atanmamış) ve aktif depo
[$osql, $oparams] = hesap_owner_sql();
if ($osql !== '') { $where[] = $osql; $params = array_merge($params, $oparams); }
[$dsql, $dparams] = depo_sql_in('depo');
if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }

$wstr = implode(' AND ', $where);

$cnt_st = db()->prepare("SELECT COUNT(*) FROM account_transactions WHERE $wstr");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

// B2: para birimleri ayrı gruplanır, asla toplanmaz
$sum_st = db()->prepare("SELECT currency,
    COALESCE(SUM(CASE WHEN type='gelir' THEN amount END),0) AS gelir,
    COALESCE(SUM(CASE WHEN type IN ('gider','havale','nakit') THEN amount END),0) AS gider
    FROM account_transactions WHERE $wstr GROUP BY currency ORDER BY (currency='TRY') DESC, currency");
$sum_st->execute($params);
$sums_by_cur = $sum_st->fetchAll();

$st = db()->prepare("SELECT * FROM account_transactions WHERE $wstr ORDER BY transaction_date DESC, id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();
$toplam_sayfa = (int)ceil($total / $limit);

render_header('Hesap Kayıtları');
hesap_assets();
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
    $base_params = array_filter(['q'=>$q,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f,'durum'=>$durum_f]);
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

<!-- Durum filtreleri -->
<div class="filter-pills" style="margin-top:6px">
    <?php $durum_base = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f]); ?>
    <a href="hesap_liste.php?<?= http_build_query($durum_base) ?>" class="pill<?= $durum_f === '' ? ' active' : '' ?>">Tüm Durumlar</a>
    <?php foreach (hesap_statuses() as $kod => $meta): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_merge($durum_base, ['durum'=>$kod])) ?>"
       class="pill<?= $durum_f === $kod ? ' active' : '' ?>"><?= h($meta['label']) ?></a>
    <?php endforeach; ?>
</div>

<!-- Fiş / Muhasebe filtresi -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0">
    <select onchange="location.href=updateParam('fis',this.value)" class="btn btn-ghost" style="padding:4px 8px">
        <option value="" <?= $fis_f === '' ? 'selected' : '' ?>>Fiş: Hepsi</option>
        <option value="1" <?= $fis_f === '1' ? 'selected' : '' ?>>Fiş var</option>
        <option value="0" <?= $fis_f === '0' ? 'selected' : '' ?>>Fiş yok ⚠</option>
    </select>
    <?php // Muhasebe durumu artık yukarıdaki durum filtresinden seçilir (is_given_to_accountant ile senkron) ?>
    <a href="hesap_export.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'durum'=>$durum_f])) ?>" class="btn btn-ghost">📊 Excel</a>
    <a href="hesap_yazdir.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'durum'=>$durum_f])) ?>" class="btn btn-ghost" target="_blank">🖨️ Yazdır</a>
</div>

<!-- Tarih aralığı seçici -->
<style>
.hesap-date-range {
    display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 10px 12px; margin-bottom: 8px;
}
.hesap-date-field { display: flex; flex-direction: column; gap: 3px; }
.hesap-date-field > span {
    font-size: .7rem; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: .03em;
}
.hesap-date-field input[type=date] {
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    padding: 7px 10px; font-size: .9rem; background: #fff; color: var(--text);
    min-width: 150px;
}
.hesap-date-field input[type=date]:focus { border-color: var(--primary); outline: none; }
.hesap-date-sep { padding-bottom: 9px; color: var(--muted); }
@media (max-width: 520px) {
    .hesap-date-field, .hesap-date-field input[type=date] { width: 100%; }
    .hesap-date-range { gap: 8px; }
}
</style>
<form method="get" class="hesap-date-range">
    <?php foreach (['q'=>$q,'type'=>$type_f,'fis'=>$fis_f,'muh'=>$muh_f] as $k => $v): if ($v !== ''): ?>
    <input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>">
    <?php endif; endforeach; ?>
    <label class="hesap-date-field">
        <span>Başlangıç</span>
        <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>">
    </label>
    <span class="hesap-date-sep">—</span>
    <label class="hesap-date-field">
        <span>Bitiş</span>
        <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>">
    </label>
    <button class="btn btn-primary btn-sm">Uygula</button>
    <?php if ($tarih_b !== '' || $tarih_s !== ''): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'fis'=>$fis_f,'muh'=>$muh_f])) ?>"
       class="btn btn-ghost btn-sm">✕ Tarihi Temizle</a>
    <?php endif; ?>
</form>

<!-- Özet — her para birimi kendi satırında (B2) -->
<?php if ($total > 0): foreach ($sums_by_cur as $sc):
    $g = (float)$sc['gelir']; $gd = (float)$sc['gider']; $cur = $sc['currency']; ?>
<div class="hesap-stat-grid" style="grid-template-columns:repeat(3,1fr);margin:12px 0">
    <div class="hesap-stat green"><span>Toplam Gelir<?= $cur !== 'TRY' ? ' (' . h($cur) . ')' : '' ?></span><strong><?= fmt_para($g, $cur) ?></strong></div>
    <div class="hesap-stat red"><span>Toplam Gider<?= $cur !== 'TRY' ? ' (' . h($cur) . ')' : '' ?></span><strong><?= fmt_para($gd, $cur) ?></strong></div>
    <div class="hesap-stat <?= ($g - $gd) >= 0 ? 'green' : 'red' ?>">
        <span>Net<?= $cur !== 'TRY' ? ' (' . h($cur) . ')' : '' ?></span><strong><?= fmt_para($g - $gd, $cur) ?></strong>
    </div>
</div>
<?php endforeach; if (count($sums_by_cur) > 1): ?>
<p class="hs-cur-note">Para birimleri ayrı toplanır — farklı kurlar birbirine eklenmez.</p>
<?php endif; endif; ?>

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
    <th>Durum</th>
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
    <td><?= hesap_status_badge($r['status'] ?? null, true) ?>
        <?php if (($r['status'] ?? '') === 'rejected' && trim((string)$r['review_note']) !== ''): ?>
        <div class="hs-note"><?= h($r['review_note']) ?></div>
        <?php endif; ?>
    </td>
    <td class="actions-col">
        <?php if (!hesap_is_locked($r)): ?>
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <?php endif; ?>
        <?php if (hesap_can('delete')): ?>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
        <?php endif; ?>
        <?php foreach (hesap_available_transitions($r) as $hedef => $kural): ?>
        <button type="button" class="btn btn-sm"
                onclick="hesapDurum(<?= (int)$r['id'] ?>,'<?= h($hedef) ?>',<?= $kural['note'] ? 'true' : 'false' ?>)">
            <?= h($kural['label']) ?>
        </button>
        <?php endforeach; ?>
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
            </div>
            <div style="margin-top:4px"><?= hesap_status_badge($r['status'] ?? null, true) ?></div>
        </div>
        <span class="hesap-amount <?= in_array($r['type'], ['gelir']) ? 'positive' : 'negative' ?>" style="font-size:1.1rem;font-weight:700">
            <?= (in_array($r['type'], ['gelir']) ? '+' : '-') . fmt_para((float)$r['amount'], $r['currency']) ?>
        </span>
    </div>
    <?php if ($r['description']): ?>
    <div class="record-card-body"><div class="muted"><?= h($r['description']) ?></div></div>
    <?php endif; ?>
    <?php if (($r['status'] ?? '') === 'rejected' && trim((string)$r['review_note']) !== ''): ?>
    <div class="hs-note">Red gerekçesi: <?= h($r['review_note']) ?></div>
    <?php endif; ?>
    <div class="record-card-actions hs-actions">
        <?php if (!hesap_is_locked($r)): ?>
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <?php endif; ?>
        <?php if (hesap_can('delete')): ?>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
        <?php endif; ?>
        <?php foreach (hesap_available_transitions($r) as $hedef => $kural): ?>
        <button type="button" class="btn btn-sm"
                onclick="hesapDurum(<?= (int)$r['id'] ?>,'<?= h($hedef) ?>',<?= $kural['note'] ? 'true' : 'false' ?>)">
            <?= h($kural['label']) ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Sayfalama — pencereli (B6): ilk / son / geçerli ±2 -->
<?php if ($toplam_sayfa > 1):
    $pg_base = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f,'durum'=>$durum_f]);
    $pg_url  = fn(int $p) => 'hesap_liste.php?' . http_build_query(array_merge($pg_base, ['sayfa'=>$p]));
    $pencere = [];
    foreach ([1, $toplam_sayfa] as $p) { $pencere[$p] = true; }
    for ($p = $sayfa - 2; $p <= $sayfa + 2; $p++) { if ($p >= 1 && $p <= $toplam_sayfa) $pencere[$p] = true; }
    $sayfalar = array_keys($pencere); sort($sayfalar);
?>
<div style="display:flex;justify-content:center;gap:8px;margin:16px 0;flex-wrap:wrap;align-items:center">
    <?php if ($sayfa > 1): ?><a href="<?= h($pg_url($sayfa - 1)) ?>" class="btn btn-sm">‹</a><?php endif; ?>
    <?php $onceki = 0; foreach ($sayfalar as $p): ?>
        <?php if ($onceki && $p > $onceki + 1): ?><span class="muted">…</span><?php endif; ?>
        <a href="<?= h($pg_url($p)) ?>" class="btn btn-sm <?= $p === $sayfa ? 'btn-primary' : '' ?>"><?= $p ?></a>
        <?php $onceki = $p; ?>
    <?php endforeach; ?>
    <?php if ($sayfa < $toplam_sayfa): ?><a href="<?= h($pg_url($sayfa + 1)) ?>" class="btn btn-sm">›</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
var hesapCsrf = <?= json_encode(csrf_token()) ?>;

// Durum geçişi — hesap_durum.php doğrular, yetkiyi ve geçiş kuralını sunucu uygular
function hesapDurum(id, hedef, gerekceZorunlu) {
    var not = '';
    if (gerekceZorunlu) {
        not = prompt('Gerekçe (zorunlu):') || '';
        if (!not.trim()) { alert('Gerekçe girilmeden işlem yapılamaz.'); return; }
    }
    var body = new URLSearchParams({ id: id, durum: hedef, not: not, csrf: hesapCsrf });
    fetch('hesap_durum.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.ok) location.reload();
        else alert(d.msg || 'İşlem başarısız.');
    })
    .catch(function () { alert('Bağlantı hatası.'); });
}

function updateParam(key, val) {
    var params = new URLSearchParams(location.search);
    if (val) params.set(key, val); else params.delete(key);
    params.delete('sayfa');
    return location.pathname + '?' + params.toString();
}
</script>
<?php render_footer(); ?>
