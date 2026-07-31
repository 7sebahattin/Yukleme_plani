<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('read');
hesap_migrate();

$bugun = date('Y-m-d');

// Ay filtresi (GET: ay=2026-05)
$ay_param = $_GET['ay'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ay_param)) {
    $ay_param = date('Y-m');
}
$ay_bas     = $ay_param . '-01';
$ay_son     = date('Y-m-01', strtotime('+1 month', strtotime($ay_bas)));
$onceki_ay  = date('Y-m', strtotime('-1 month', strtotime($ay_bas)));
$sonraki_ay = date('Y-m', strtotime('+1 month', strtotime($ay_bas)));
$bu_ay_mi   = ($ay_param === date('Y-m'));

$ay_isimleri = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',
                7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
$ay_label = $ay_isimleri[(int)date('n', strtotime($ay_bas))] . ' ' . date('Y', strtotime($ay_bas));

// ── Bakiye — yalnız onaylı/ödenen kayıtlardan, para birimi ayrı ayrı ──
// (B2/B3 düzeltmesi: para birimleri toplanmaz, sayaçlar kullanıcı+depo kapsamında)
$ay_bal    = hesap_balance(null, $ay_bas, $ay_son);   // seçili ay
$devir_bal = hesap_balance(null, null, $ay_bas);      // ay başından öncesi
$tum_bal   = hesap_balance();                          // güncel bakiye (tüm zaman)

$ay_try    = $ay_bal['TRY'];
$devir     = $devir_bal['TRY']['net'];
$ay_net    = $ay_try['net'];
$bakiye    = $tum_bal['TRY']['net'];
$bekleyen_tutar = $tum_bal['TRY']['bekleyen'];
$bakiye_info    = hesap_balance_label($bakiye);

// TRY dışı para birimleri ayrı gösterilir — asla TRY'ye eklenmez
$diger_kurlar = array_filter($tum_bal, fn($k) => $k !== 'TRY', ARRAY_FILTER_USE_KEY);
$diger_kurlar = array_filter($diger_kurlar, fn($v) => abs($v['net']) > 0.005 || $v['adet'] > 0);

// ── Sayaçlar ve kategori toplamları — kullanıcı + depo kapsamlı ──
$scope  = ['1=1'];
$sparams = [];
[$osql, $oparams] = hesap_owner_sql();
if ($osql !== '') { $scope[] = $osql; $sparams = array_merge($sparams, $oparams); }
[$dsql, $dparams] = depo_sql_in('depo');
if ($dsql !== '') { $scope[] = $dsql; $sparams = array_merge($sparams, $dparams); }
$scope_sql = implode(' AND ', $scope);

$bal_ph  = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));
$pend_ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));

$st = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN category='Yemek gideri'     AND status IN ($bal_ph)
                          AND transaction_date>=? AND transaction_date<? THEN amount END),0) AS ay_yemek,
        COALESCE(SUM(CASE WHEN category='Şirket malzemesi' AND status IN ($bal_ph)
                          AND transaction_date>=? AND transaction_date<? THEN amount END),0) AS ay_malzeme,
        SUM(CASE WHEN status IN ($pend_ph) THEN 1 ELSE 0 END)          AS bekleyen,
        SUM(CASE WHEN status='rejected'    THEN 1 ELSE 0 END)          AS reddedilen,
        SUM(CASE WHEN has_files=0 AND has_invoice=0 THEN 1 ELSE 0 END) AS fissiz,
        COUNT(*) AS toplam_kayit
    FROM account_transactions
    WHERE currency='TRY' AND $scope_sql
");
$st->execute(array_merge(
    hesap_balance_statuses(), [$ay_bas, $ay_son],
    hesap_balance_statuses(), [$ay_bas, $ay_son],
    hesap_pending_statuses(),
    $sparams
));
$sayac = $st->fetch();

// ── Son işlemler ──
$si_st = db()->prepare("SELECT * FROM account_transactions WHERE $scope_sql
                        ORDER BY transaction_date DESC, id DESC LIMIT 10");
$si_st->execute($sparams);
$son_islemler = $si_st->fetchAll();

// Reddedilen kayıtlar — personelin düzeltmesi gerekenler
$red_st = db()->prepare("SELECT * FROM account_transactions
                         WHERE status='rejected' AND $scope_sql
                         ORDER BY reviewed_at DESC, id DESC LIMIT 5");
$red_st->execute($sparams);
$reddedilenler = $red_st->fetchAll();

render_header('Hesap');
hesap_assets();
render_flash();
?>
<div class="page-head">
    <div><h1>💰 Hesap</h1><p class="muted">Gelir · Gider · Fiş Takibi</p></div>
    <a href="hesap_kayit.php" class="btn btn-primary btn-lg">+ Yeni Kayıt</a>
</div>

<!-- Ay Seçici -->
<div class="hesap-ay-nav">
    <a href="hesap.php?ay=<?= h($onceki_ay) ?>" class="btn btn-ghost hesap-ay-arrow">‹</a>
    <span class="hesap-ay-label"><?= h($ay_label) ?><?= $bu_ay_mi ? ' <span class="hesap-ay-badge">Bu Ay</span>' : '' ?></span>
    <a href="hesap.php?ay=<?= h($sonraki_ay) ?>" class="btn btn-ghost hesap-ay-arrow<?= $bu_ay_mi ? ' disabled' : '' ?>"
       <?= $bu_ay_mi ? 'aria-disabled="true" tabindex="-1"' : '' ?>>›</a>
</div>

<!-- Özet Kartlar — bakiye yalnız onaylı/ödenen kayıtlardan hesaplanır -->
<div class="hesap-stat-grid">
    <div class="hesap-stat green"><span>Bu Ay Gelir</span><strong><?= fmt_para($ay_try['gelir']) ?></strong></div>
    <div class="hesap-stat red"><span>Bu Ay Gider</span><strong><?= fmt_para($ay_try['gider']) ?></strong></div>
    <div class="hesap-stat gray"><span>Geçen Aydan Devir</span><strong><?= fmt_para($devir) ?></strong></div>
    <div class="hesap-stat <?= $bakiye_info['yon'] === 'borc' ? 'red' : 'green' ?>">
        <span>Güncel Bakiye — <?= h($bakiye_info['label']) ?></span>
        <strong><?= fmt_para($bakiye_info['tutar']) ?></strong>
        <small>Devir <?= fmt_para($devir) ?> · Bu ay net <?= fmt_para($ay_net) ?>
            <?php if (abs($bekleyen_tutar) > 0.005): ?><br>Onay bekleyen <?= fmt_para(abs($bekleyen_tutar)) ?> (bakiyeye dahil değil)<?php endif; ?>
        </small>
    </div>
    <div class="hesap-stat orange"><span>Yemek Gideri</span><strong><?= fmt_para((float)$sayac['ay_yemek']) ?></strong></div>
    <div class="hesap-stat <?= (int)$sayac['bekleyen'] > 0 ? 'orange' : 'green' ?>"><span>Onay Bekleyen</span><strong><?= (int)$sayac['bekleyen'] ?> kayıt</strong></div>
</div>

<?php if (!empty($diger_kurlar)): ?>
<div class="hesap-stat-grid" style="margin-top:8px">
    <?php foreach ($diger_kurlar as $cur => $b): $bi = hesap_balance_label($b['net']); ?>
    <div class="hesap-stat <?= $bi['yon'] === 'borc' ? 'red' : 'green' ?>">
        <span><?= h($cur) ?> Bakiye — <?= h($bi['label']) ?></span>
        <strong><?= fmt_para($bi['tutar'], $cur) ?></strong>
        <small><?= (int)$b['adet'] ?> kayıt · TRY toplamına eklenmez</small>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($reddedilenler)): ?>
<div class="flash flash-error" style="margin-top:10px">
    <strong><?= count($reddedilenler) ?> fiş reddedildi</strong> — düzeltip yeniden gönderin.
    <ul style="margin:6px 0 0;padding-left:18px;font-size:.85rem">
        <?php foreach ($reddedilenler as $rr): ?>
        <li>
            <a href="hesap_kayit.php?id=<?= (int)$rr['id'] ?>"><?= h($rr['category'] ?: hesap_type_label($rr['type'])) ?>
                · <?= fmt_para((float)$rr['amount'], $rr['currency']) ?></a>
            <?php if (trim((string)$rr['review_note']) !== ''): ?> — <em><?= h($rr['review_note']) ?></em><?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Hızlı Butonlar -->
<div class="hesap-quick-grid">
    <a href="hesap_kayit.php?hizli=yemek"   class="hesap-quick-btn orange">🍽️<span>Yemek Fişi</span></a>
    <a href="hesap_kayit.php?hizli=gider"   class="hesap-quick-btn red">💸<span>Harcama</span></a>
    <a href="hesap_kayit.php?hizli=gelir"   class="hesap-quick-btn green">💵<span>Gelen Para</span></a>
    <a href="hesap_kayit.php?hizli=havale"  class="hesap-quick-btn blue">🏦<span>Havale</span></a>
    <a href="hesap_kayit.php?hizli=malzeme" class="hesap-quick-btn blue">📦<span>Malzeme</span></a>
    <a href="hesap_kayit.php?hizli=nakit"   class="hesap-quick-btn orange">💴<span>Nakit Ödeme</span></a>
</div>

<!-- Modül Linkleri -->
<div class="hesap-nav-links">
    <a href="hesap_liste.php" class="btn btn-ghost">📋 Tüm Kayıtlar (<?= (int)$sayac['toplam_kayit'] ?>)</a>
    <?php if (hesap_can('approve')): ?>
    <a href="hesap_muhasebe.php" class="btn btn-ghost">🗂️ Muhasebe Onay Kuyruğu</a>
    <?php endif; ?>
    <a href="hesap_muhasebe_fis_pdf.php" class="btn btn-ghost" target="_blank">📸 Fiş Foto PDF</a>
    <a href="hesap_export.php" class="btn btn-ghost">📊 Excel Export</a>
    <a href="hesap_yazdir.php" class="btn btn-ghost" target="_blank">🖨️ Yazdır</a>
</div>

<!-- Son İşlemler -->
<?php if (empty($son_islemler)): ?>
<div class="empty"><p>Henüz kayıt yok.</p><a href="hesap_kayit.php" class="btn btn-primary">İlk kaydı oluştur</a></div>
<?php else: ?>
<h2 style="margin:20px 0 8px;font-size:1rem">Son İşlemler</h2>
<div class="hesap-list">
<?php foreach ($son_islemler as $t): ?>
<div class="hesap-row">
    <div class="hesap-row-left">
        <span class="hesap-type-dot" style="background:<?= hesap_type_color($t['type']) ?>"></span>
        <div>
            <div class="hesap-row-cat"><?= h($t['category'] ?: hesap_type_label($t['type'])) ?></div>
            <?php if ($t['person_company']): ?><div class="muted" style="font-size:.78rem"><?= h($t['person_company']) ?></div><?php endif; ?>
            <?php $fd = hesap_fis_durumu($t); ?>
            <div class="muted" style="font-size:.72rem"><?= h(date('d.m.Y', strtotime($t['transaction_date']))) ?>
                <?php if (!$fd['var']): ?> <span style="color:var(--danger)">⚠ <?= h($fd['kisa']) ?></span><?php endif; ?>
            </div>
            <div style="margin-top:3px"><?= hesap_status_badge($t['status'] ?? null, true) ?></div>
        </div>
    </div>
    <div class="hesap-row-right">
        <span class="hesap-amount <?= in_array($t['type'],['gelir']) ? 'positive' : 'negative' ?>">
            <?= (in_array($t['type'],['gelir']) ? '+' : '-') . fmt_para((float)$t['amount'], $t['currency']) ?>
        </span>
        <a href="hesap_kayit.php?id=<?= (int)$t['id'] ?>" class="btn btn-sm">Düzenle</a>
    </div>
</div>
<?php endforeach; ?>
</div>
<div style="text-align:center;margin-top:12px">
    <a href="hesap_liste.php" class="btn btn-ghost">Tümünü Gör →</a>
</div>
<?php endif; ?>
<?php render_footer(); ?>
