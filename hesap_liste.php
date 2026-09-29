<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/xlsx_export.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

// Kapsam: varsayılan KENDİ kayıtlarım; yalnız yönetici ?personel=<uid>|tum
$kapsam  = hesap_kapsam_coz($_GET['personel'] ?? null, 'kendi');
$kq      = hesap_kapsam_query($kapsam);
if (!$kapsam['kendi']) {
    audit_log_event('view', 'hesap', null, null, ['personel' => $kapsam['uid'] ?? 'tum', 'sayfa' => 'hesap_liste.php']);
}

$q       = trim($_GET['q'] ?? '');
$type_f  = trim($_GET['type'] ?? '');
if (!in_array($type_f, ['gelir', 'gider', 'havale', 'nakit'], true)) $type_f = '';
$tarih_b = trim($_GET['tarih_bas'] ?? '');
$tarih_s = trim($_GET['tarih_son'] ?? '');
if ($tarih_b !== '' && !hesap_tarih_gecerli($tarih_b)) $tarih_b = '';
if ($tarih_s !== '' && !hesap_tarih_gecerli($tarih_s)) $tarih_s = '';
$fis_f   = trim($_GET['fis'] ?? '');   // '' all, '1' var, '0' yok
if (!in_array($fis_f, ['', '0', '1'], true)) $fis_f = '';
$muh_f   = trim($_GET['muh'] ?? '');   // '' all, '1' verildi, '0' bekliyor
if (!in_array($muh_f, ['', '0', '1'], true)) $muh_f = '';
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
    $where[] = "(at.category LIKE ? OR at.person_company LIKE ? OR at.description LIKE ? OR at.document_no LIKE ?)";
    $params   = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%"]);
}
if ($type_f !== '') { $where[] = "at.type=?";              $params[] = $type_f; }
if ($tarih_b !== '') { $where[] = "at.transaction_date>=?"; $params[] = $tarih_b; }
if ($tarih_s !== '') { $where[] = "at.transaction_date<=?"; $params[] = $tarih_s; }
// B4: fiş filtresi rozetle aynı mantığa bağlandı (fotoğraf VEYA manuel fatura işareti)
if ($fis_f === '1') { $where[] = "(at.has_files=1 OR at.has_invoice=1)"; }
if ($fis_f === '0') { $where[] = "(at.has_files=0 AND at.has_invoice=0)"; }
if ($muh_f === '1') { $where[] = "at.is_given_to_accountant=1"; }
if ($muh_f === '0') { $where[] = "at.is_given_to_accountant=0"; }
if ($durum_f !== '') { $where[] = "at.status=?";            $params[] = $durum_f; }

// Kapsam: kişisel hesap — sahiplik (sahipsiz YOK), depo filtresi YOK (bilinçli)
[$ksql, $kparams] = hesap_kapsam_sql($kapsam, 'at.user_id');
$where[] = $ksql;
$params  = array_merge($params, $kparams);

$wstr = implode(' AND ', $where);

$cnt_st = db()->prepare("SELECT COUNT(*) FROM account_transactions at WHERE $wstr");
$cnt_st->execute($params);
$total = (int)$cnt_st->fetchColumn();

// B2: para birimleri ayrı gruplanır, asla toplanmaz.
// O3: toplamlar durumdan bağımsız DEĞİL — "Bakiyeye giren" (onaylı + ödenen) ve
// "Bekleyen" (taslak + gönderildi) ayrı; reddedilenler hiçbir toplama girmez.
$bal_ph  = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));
$pend_ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));
$sum_st = db()->prepare("SELECT at.currency AS currency,
    COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type='gelir' THEN at.amount END),0) AS gelir,
    COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type IN ('gider','havale','nakit') THEN at.amount END),0) AS gider,
    COALESCE(SUM(CASE WHEN at.status IN ($pend_ph) AND at.type='gelir' THEN at.amount END),0) AS p_gelir,
    COALESCE(SUM(CASE WHEN at.status IN ($pend_ph) AND at.type IN ('gider','havale','nakit') THEN at.amount END),0) AS p_gider
    FROM account_transactions at WHERE $wstr GROUP BY at.currency ORDER BY (at.currency='TRY') DESC, at.currency");
$sum_st->execute(array_merge(hesap_balance_statuses(), hesap_balance_statuses(),
                             hesap_pending_statuses(), hesap_pending_statuses(), $params));
$sums_by_cur = $sum_st->fetchAll();

// Personel adı yalnız "Tüm personel" kapsamında gösterilir
$st = db()->prepare("SELECT at.*, COALESCE(NULLIF(u.display_name,''), u.username, '') AS personel
                     FROM account_transactions at LEFT JOIN users u ON u.id = at.user_id
                     WHERE $wstr ORDER BY at.transaction_date DESC, at.id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();
$toplam_sayfa = (int)ceil($total / $limit);

$tum_kapsam = $kapsam['tip'] === 'tum';
$baslik = match ($kapsam['tip']) {
    'tum'  => 'Tüm Personel — Kayıtlar',
    'kisi' => $kapsam['ad'] . ' — Kayıtlar',
    default => 'Kayıtlarım',
};

render_header($baslik);
hesap_assets();
render_flash();
?>
<div class="hs">
<div class="page-head">
    <div><h1><?= h($baslik) ?></h1><p class="muted">Toplam <?= $total ?> kayıt</p></div>
    <div class="hs-actions">
        <?php if (hesap_can('write') && $kapsam['kendi']): ?>
        <a href="hesap_kayit.php?hizli=gider" class="btn btn-primary">📷 Harcama Ekle</a>
        <?php endif; ?>
        <?php $_hx_q = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'durum'=>$durum_f]) + $kq; ?>
        <?= export_menu('hesap_export.php?' . http_build_query($_hx_q + ['bicim' => 'csv']), 'hesap_export.php?' . http_build_query($_hx_q + ['bicim' => 'xlsx']), 'Excel İndir', 'btn btn-ghost') ?>
        <a href="hesap_yazdir.php?<?= http_build_query(array_filter(['type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'durum'=>$durum_f]) + $kq) ?>" class="btn btn-ghost" target="_blank">📄 PDF Rapor</a>
    </div>
</div>

<?php if (!$kapsam['kendi']): ?>
<div class="hs-kapsam" role="status">
    <span>👤 <strong><?= h($kapsam['ad']) ?></strong> — yönetici görünümü</span>
    <a href="hesap_liste.php">Kendi kayıtlarıma dön</a>
</div>
<?php endif; ?>

<!-- ── Filtre paneli: arama, tarih, tür, durum, fiş — tek kart ── -->
<div class="hs-filter-panel">

    <!-- Arama -->
    <form method="get" class="hs-filter-row">
        <span class="hs-filter-label">Ara</span>
        <div class="hs-search">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Kategori, kişi, açıklama...">
            <button class="btn btn-sm">Ara</button>
            <?php if ($q !== ''): ?><a href="hesap_liste.php?<?= http_build_query($kq) ?>" class="btn btn-ghost btn-sm">✕</a><?php endif; ?>
        </div>
        <?php if ($type_f): ?><input type="hidden" name="type" value="<?= h($type_f) ?>"><?php endif; ?>
        <?php if ($tarih_b): ?><input type="hidden" name="tarih_bas" value="<?= h($tarih_b) ?>"><?php endif; ?>
        <?php if ($tarih_s): ?><input type="hidden" name="tarih_son" value="<?= h($tarih_s) ?>"><?php endif; ?>
        <?php if ($fis_f !== ''): ?><input type="hidden" name="fis" value="<?= h($fis_f) ?>"><?php endif; ?>
        <?php if ($durum_f !== ''): ?><input type="hidden" name="durum" value="<?= h($durum_f) ?>"><?php endif; ?>
        <?php foreach ($kq as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
    </form>

    <!-- Hızlı tarih -->
    <div class="hs-filter-row">
        <span class="hs-filter-label">Tarih</span>
        <div class="hs-filters">
            <a href="hesap_liste.php?<?= http_build_query($kq) ?>" class="hs-filter<?= !$hizli_tarih && !$tarih_b ? ' active' : '' ?>">Tümü</a>
            <a href="hesap_liste.php?<?= http_build_query(['ht' => 'bugun'] + $kq) ?>" class="hs-filter<?= $hizli_tarih === 'bugun' ? ' active' : '' ?>">Bugün</a>
            <a href="hesap_liste.php?<?= http_build_query(['ht' => 'bu_ay'] + $kq) ?>" class="hs-filter<?= $hizli_tarih === 'bu_ay' ? ' active' : '' ?>">Bu Ay</a>
            <a href="hesap_liste.php?<?= http_build_query(['ht' => 'gecen'] + $kq) ?>" class="hs-filter<?= $hizli_tarih === 'gecen' ? ' active' : '' ?>">Geçen Ay</a>
        </div>
    </div>

    <!-- Özel tarih aralığı -->
    <form method="get" class="hs-filter-row">
        <span class="hs-filter-label">Aralık</span>
        <div class="hs-daterange">
            <?php foreach (['q'=>$q,'type'=>$type_f,'fis'=>$fis_f,'durum'=>$durum_f] + array_map('strval', $kq) as $k => $v): if ($v !== ''): ?>
            <input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>">
            <?php endif; endforeach; ?>
            <input type="date" name="tarih_bas" value="<?= h($tarih_b) ?>" aria-label="Başlangıç tarihi">
            <span class="sep">—</span>
            <input type="date" name="tarih_son" value="<?= h($tarih_s) ?>" aria-label="Bitiş tarihi">
            <button class="btn btn-sm btn-primary">Uygula</button>
            <?php if ($tarih_b !== '' || $tarih_s !== ''): ?>
            <a href="hesap_liste.php?<?= http_build_query(array_filter(['q'=>$q,'type'=>$type_f,'fis'=>$fis_f,'durum'=>$durum_f]) + $kq) ?>"
               class="btn btn-ghost btn-sm">✕</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Tür filtreleri -->
    <div class="hs-filter-row">
        <span class="hs-filter-label">Tür</span>
        <?php $base_params = array_filter(['q'=>$q,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'durum'=>$durum_f]) + $kq; ?>
        <div class="hs-filters">
            <a href="hesap_liste.php?<?= http_build_query($base_params) ?>" class="hs-filter<?= $type_f === '' ? ' active' : '' ?>">Tüm Türler</a>
            <?php foreach (['gelir','gider','havale','nakit'] as $t): ?>
            <a href="hesap_liste.php?<?= http_build_query(array_merge($base_params, ['type'=>$t])) ?>"
               class="hs-filter<?= $type_f === $t ? ' active' : '' ?>"><?= hesap_type_label($t) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Durum filtreleri -->
    <div class="hs-filter-row">
        <span class="hs-filter-label">Durum</span>
        <?php $durum_base = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f]) + $kq; ?>
        <div class="hs-filters">
            <a href="hesap_liste.php?<?= http_build_query($durum_base) ?>" class="hs-filter<?= $durum_f === '' ? ' active' : '' ?>">Tüm Durumlar</a>
            <?php foreach (hesap_statuses() as $kod => $meta): ?>
            <a href="hesap_liste.php?<?= http_build_query(array_merge($durum_base, ['durum'=>$kod])) ?>"
               class="hs-filter<?= $durum_f === $kod ? ' active' : '' ?>"><?= h($meta['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Fiş filtresi -->
    <div class="hs-filter-row">
        <span class="hs-filter-label">Fiş</span>
        <select onchange="location.href=updateParam('fis',this.value)" class="hs-select">
            <option value="" <?= $fis_f === '' ? 'selected' : '' ?>>Hepsi</option>
            <option value="1" <?= $fis_f === '1' ? 'selected' : '' ?>>Fiş var</option>
            <option value="0" <?= $fis_f === '0' ? 'selected' : '' ?>>Fiş yok ⚠</option>
        </select>
    </div>

</div>

<!-- Özet — her para birimi kendi satırında (B2); bakiyeye giren ve bekleyen ayrı (O3) -->
<?php if ($total > 0): ?>
<div class="hs-sum">
    <p class="hs-sum-title">Bakiyeye giren <span>(onaylı + ödenen · listelenen filtreye göre)</span></p>
    <?php foreach ($sums_by_cur as $sc):
        $g = (float)$sc['gelir']; $gd = (float)$sc['gider']; $cur = $sc['currency'];
        $pnet = (float)$sc['p_gelir'] - (float)$sc['p_gider']; ?>
    <div class="hs-sum-row">
        <?php if (count($sums_by_cur) > 1): ?><span class="hs-sum-cur"><?= h($cur) ?></span><?php endif; ?>
        <span class="hs-sum-item"><span>Gelir</span><b class="pos"><?= fmt_para($g, $cur) ?></b></span>
        <span class="hs-sum-item"><span>Gider</span><b class="neg"><?= fmt_para($gd, $cur) ?></b></span>
        <span class="hs-sum-item"><span>Net</span><b><?= fmt_para($g - $gd, $cur) ?></b></span>
        <span class="hs-sum-item hs-sum-pend"><span>Bekleyen net</span><b><?= fmt_para($pnet, $cur) ?></b></span>
    </div>
    <?php endforeach; ?>
    <p class="hs-cur-note">Bekleyen = taslak + gönderildi; bakiyeye girmez. Reddedilenler hiçbir toplama girmez.<?php if (count($sums_by_cur) > 1): ?>
    Para birimleri ayrı toplanır — farklı kurlar birbirine eklenmez.<?php endif; ?></p>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">🔍</span>
    <p>Bu filtrelerle kayıt bulunamadı.</p>
    <a href="hesap_liste.php?<?= http_build_query($kq) ?>" class="btn">Filtreleri Temizle</a>
</div>
<?php else: ?>

<!-- PC: Tablo -->
<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Tarih</th>
    <?php if ($tum_kapsam): ?><th>Personel</th><?php endif; ?>
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
<?php foreach ($rows as $r): $kilitli = hesap_icerik_kilitli($r); ?>
<tr>
    <td class="muted"><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
    <?php if ($tum_kapsam): ?><td><?= h($r['personel'] !== '' ? $r['personel'] : 'Kullanıcı #' . (int)$r['user_id']) ?></td><?php endif; ?>
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
        <?php if ($kilitli): ?>
        <span class="hs-kilit" title="<?= h(hesap_kilit_mesaji()) ?>">🔒 Onaylı</span>
        <?php else: ?>
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <?php endif; ?>
        <?php if (hesap_can('delete') && !$kilitli): ?>
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
    $kilitli = hesap_icerik_kilitli($r);
    $gecisler = hesap_available_transitions($r);
?>
<div class="hs-tx" style="flex-direction:column;align-items:stretch;gap:8px">
    <div style="display:flex;gap:12px;align-items:flex-start">
        <span class="hs-tx-dot" style="background:<?= hesap_type_color($r['type']) ?>" aria-hidden="true"></span>
        <span class="hs-tx-main">
            <span class="hs-tx-title"><?= h($r['category'] ?: hesap_type_label($r['type'])) ?></span>
            <span class="hs-tx-meta">
                <?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?>
                <?php if ($tum_kapsam): ?>· <?= h($r['personel'] !== '' ? $r['personel'] : 'Kullanıcı #' . (int)$r['user_id']) ?><?php endif; ?>
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

    <div class="hs-actions">
        <?php if ($kilitli): ?>
        <span class="hs-kilit" title="<?= h(hesap_kilit_mesaji()) ?>">🔒 Onaylı</span>
        <?php else: ?>
        <a href="hesap_kayit.php?id=<?= $r['id'] ?>" class="btn btn-sm">Düzenle</a>
        <?php endif; ?>
        <?php if (hesap_can('delete') && !$kilitli): ?>
        <a href="hesap_sil.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu kayıt silinsin mi?')">Sil</a>
        <?php endif; ?>
        <?php foreach ($gecisler as $hedef => $kural): ?>
        <button type="button" class="btn btn-sm" data-hs-durum="<?= h($hedef) ?>"
                data-hs-id="<?= (int)$r['id'] ?>" data-hs-not="<?= $kural['note'] ? '1' : '0' ?>">
            <?= h($kural['label']) ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Sayfalama — pencereli (B6): ilk / son / geçerli ±2 -->
<?php if ($toplam_sayfa > 1):
    $pg_base = array_filter(['q'=>$q,'type'=>$type_f,'tarih_bas'=>$tarih_b,'tarih_son'=>$tarih_s,'fis'=>$fis_f,'muh'=>$muh_f,'durum'=>$durum_f]) + $kq;
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
