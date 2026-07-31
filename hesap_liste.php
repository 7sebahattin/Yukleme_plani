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
<div class="hs">
<div class="page-head">
    <div><h1>Hesap Kayıtları</h1><p class="muted">Toplam <?= $total ?> kayıt</p></div>
    <?php if (hesap_can('write')): ?>
    <a href="hesap_kayit.php?hizli=gider" class="btn btn-primary">📷 Harcama Ekle</a>
    <?php endif; ?>
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
<div class="hs-filters">
    <a href="hesap_liste.php" class="hs-filter<?= !$hizli_tarih && !$tarih_b ? ' active' : '' ?>">Tümü</a>
    <a href="hesap_liste.php?ht=bugun" class="hs-filter<?= $hizli_tarih === 'bugun' ? ' active' : '' ?>">Bugün</a>
    <a href="hesap_liste.php?ht=bu_ay" class="hs-filter<?= $hizli_tarih === 'bu_ay' ? ' active' : '' ?>">Bu Ay</a>
    <a href="hesap_liste.php?ht=gecen" class="hs-filter<?= $hizli_tarih === 'gecen' ? ' active' : '' ?>">Geçen Ay</a>
</div>

<!-- Tür filtreleri -->
<div class="hs-filters">
    <?php
    $base_params = array_filter(['q'=>$q,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f,'durum'=>$durum_f]);
    ?>
    <a href="hesap_liste.php?<?= http_build_query($base_params) ?>" class="hs-filter<?= $type_f === '' ? ' active' : '' ?>">Tüm Türler</a>
    <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_merge($base_params, ['type'=>$t])) ?>"
       class="hs-filter<?= $type_f === $t ? ' active' : '' ?>"><?= hesap_type_label($t) ?></a>
    <?php endforeach; ?>
</div>

<!-- Durum filtreleri -->
<div class="hs-filters">
    <?php $durum_base = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f]); ?>
    <a href="hesap_liste.php?<?= http_build_query($durum_base) ?>" class="hs-filter<?= $durum_f === '' ? ' active' : '' ?>">Tüm Durumlar</a>
    <?php foreach (hesap_statuses() as $kod => $meta): ?>
    <a href="hesap_liste.php?<?= http_build_query(array_merge($durum_base, ['durum'=>$kod])) ?>"
       class="hs-filter<?= $durum_f === $kod ? ' active' : '' ?>"><?= h($meta['label']) ?></a>
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
<?php if ($total > 0): ?>
<div class="hs-sum">
    <?php foreach ($sums_by_cur as $sc):
        $g = (float)$sc['gelir']; $gd = (float)$sc['gider']; $cur = $sc['currency']; ?>
    <div class="hs-sum-row">
        <?php if (count($sums_by_cur) > 1): ?><span class="hs-sum-cur"><?= h($cur) ?></span><?php endif; ?>
        <span class="hs-sum-item"><span>Gelir</span><b class="pos"><?= fmt_para($g, $cur) ?></b></span>
        <span class="hs-sum-item"><span>Gider</span><b class="neg"><?= fmt_para($gd, $cur) ?></b></span>
        <span class="hs-sum-item"><span>Net</span><b><?= fmt_para($g - $gd, $cur) ?></b></span>
    </div>
    <?php endforeach; ?>
    <?php if (count($sums_by_cur) > 1): ?>
    <p class="hs-cur-note">Para birimleri ayrı toplanır — farklı kurlar birbirine eklenmez.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">🔍</span>
    <p>Bu filtrelerle kayıt bulunamadı.</p>
    <a href="hesap_liste.php" class="btn">Filtreleri Temizle</a>
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
        <button type="button" class="btn btn-sm" data-hs-durum="<?= h($hedef) ?>"
                data-hs-id="<?= (int)$r['id'] ?>" data-hs-not="<?= $kural['note'] ? '1' : '0' ?>">
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
<div class="hs-tx-list mobile-only">
<?php foreach ($rows as $r):
    $gelir_mi = $r['type'] === 'gelir';
    $fd = hesap_fis_durumu($r);
    $kilitli = hesap_is_locked($r);
    $gecisler = hesap_available_transitions($r);
?>
<div class="hs-tx" style="flex-direction:column;align-items:stretch;gap:8px">
    <div style="display:flex;gap:12px;align-items:flex-start">
        <span class="hs-tx-dot" style="background:<?= hesap_type_color($r['type']) ?>" aria-hidden="true"></span>
        <span class="hs-tx-main">
            <span class="hs-tx-title"><?= h($r['category'] ?: hesap_type_label($r['type'])) ?></span>
            <span class="hs-tx-meta">
                <?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?>
                <?php if ($r['person_company']): ?>· <?= h($r['person_company']) ?><?php endif; ?>
                <?php if (!$fd['var']): ?>· <span class="hs-tx-warn">⚠ <?= h($fd['kisa']) ?></span><?php endif; ?>
            </span>
            <span class="hs-tx-meta"><?= hesap_status_badge($r['status'] ?? null, true) ?></span>
        </span>
        <span class="hs-tx-side">
            <span class="hs-tx-amount <?= $gelir_mi ? 'pos' : 'neg' ?>">
                <?= ($gelir_mi ? '+' : '−') . fmt_para((float)$r['amount'], $r['currency']) ?>
            </span>
        </span>
    </div>

    <?php if ($r['description']): ?>
    <div class="muted" style="font-size:.82rem"><?= h($r['description']) ?></div>
    <?php endif; ?>
    <?php if (($r['status'] ?? '') === 'rejected' && trim((string)$r['review_note']) !== ''): ?>
    <div class="hs-note">Red gerekçesi: <?= h($r['review_note']) ?></div>
    <?php endif; ?>

    <?php if (!$kilitli || hesap_can('delete') || !empty($gecisler)): ?>
    <div class="hs-actions">
        <?php if (!$kilitli): ?>
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <?php endif; ?>
        <?php if (hesap_can('delete')): ?>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
        <?php endif; ?>
        <?php foreach ($gecisler as $hedef => $kural): ?>
        <button type="button" class="btn btn-sm" data-hs-durum="<?= h($hedef) ?>"
                data-hs-id="<?= (int)$r['id'] ?>" data-hs-not="<?= $kural['note'] ? '1' : '0' ?>">
            <?= h($kural['label']) ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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

</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<script>
// Durum geçişi hesap.js'te (HesapUI.durum) — burada yalnız filtre yardımcısı kaldı
function updateParam(key, val) {
    var params = new URLSearchParams(location.search);
    if (val) params.set(key, val); else params.delete(key);
    params.delete('sayfa');
    return location.pathname + '?' + params.toString();
}
</script>
<?php render_footer(); ?>
