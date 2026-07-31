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
                        ORDER BY transaction_date DESC, id DESC LIMIT 6");
$si_st->execute($sparams);
$son_islemler = $si_st->fetchAll();

// Kart listesindeki fiş küçük resimleri — kayıt başına ilk görsel (tek sorgu)
$thumbs = [];
$si_ids = array_map(fn($r) => (int)$r['id'], $son_islemler);
if (!empty($si_ids)) {
    $ph = implode(',', array_fill(0, count($si_ids), '?'));
    $th_st = db()->prepare("SELECT transaction_id, file_name FROM account_files
                            WHERE transaction_id IN ($ph) ORDER BY id");
    $th_st->execute($si_ids);
    foreach ($th_st->fetchAll() as $tf) {
        $tid = (int)$tf['transaction_id'];
        if (!isset($thumbs[$tid]) && hesap_is_image($tf['file_name'])) {
            $thumbs[$tid] = $tf['file_name'];
        }
    }
}

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
<div class="hs">

<div class="page-head">
    <div>
        <h1>💰 Hesap</h1>
        <p class="muted">Masraf ve fiş takibi</p>
    </div>
</div>

<!-- ── Ay gezgini ── -->
<nav class="hs-month" aria-label="Ay seçimi">
    <a href="hesap.php?ay=<?= h($onceki_ay) ?>" class="hs-month-arrow" aria-label="Önceki ay">‹</a>
    <span class="hs-month-label">
        <?= h($ay_label) ?><?= $bu_ay_mi ? '<span class="hs-month-tag">Bu Ay</span>' : '' ?>
    </span>
    <a href="hesap.php?ay=<?= h($sonraki_ay) ?>"
       class="hs-month-arrow<?= $bu_ay_mi ? ' disabled' : '' ?>"
       <?= $bu_ay_mi ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Sonraki ay">›</a>
</nav>

<div class="hs-dash-top">

    <!-- ── Tek bakiye kartı ── -->
    <section class="hs-balance hs-balance--<?= h($bakiye_info['yon']) ?>" aria-label="Güncel bakiye">
        <p class="hs-balance-label"><?= h($bakiye_info['label']) ?></p>
        <p class="hs-balance-amount"><?= fmt_para($bakiye_info['tutar']) ?></p>
        <p class="hs-balance-sub">
            Onaylanan ve ödenen kayıtlardan hesaplanır.
            <?php if (abs($devir) > 0.005): ?>Devir <?= fmt_para($devir) ?> ·<?php endif; ?>
            Bu ay net <?= fmt_para($ay_net) ?>
        </p>
        <?php if (abs($bekleyen_tutar) > 0.005 || (int)$sayac['bekleyen'] > 0): ?>
        <a href="hesap_liste.php?durum=submitted" class="hs-balance-pending">
            ⏳ <?= (int)$sayac['bekleyen'] ?> kayıt onay bekliyor
            <?php if (abs($bekleyen_tutar) > 0.005): ?>· <?= fmt_para(abs($bekleyen_tutar)) ?><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (!empty($diger_kurlar)): ?>
        <div class="hs-balance-cur">
            <?php foreach ($diger_kurlar as $cur => $b): $bi = hesap_balance_label($b['net']); ?>
            <span class="hs-cur-chip">
                <?= h($cur) ?>
                <b class="<?= $bi['yon'] === 'borc' ? 'neg' : 'pos' ?>"><?= fmt_para($bi['tutar'], $cur) ?></b>
            </span>
            <?php endforeach; ?>
        </div>
        <p class="hs-cur-note">Para birimleri ayrı tutulur — TRY toplamına eklenmez.</p>
        <?php endif; ?>
    </section>

    <!-- ── Birincil eylem + ikincil menü ── -->
    <div class="hs-actions-col">
        <?php if (hesap_can('write')): ?>
        <a href="hesap_kayit.php?hizli=gider" class="hs-cta">
            <span class="hs-cta-icon" aria-hidden="true">📷</span> Harcama Ekle
        </a>
        <?php endif; ?>
        <div class="hs-secondary">
            <?php if (hesap_can('write')): ?>
            <button type="button" class="btn" data-hs-sheet="hsQuickSheet">⚡ Diğer Kayıt Türü</button>
            <?php endif; ?>
            <button type="button" class="btn" data-hs-sheet="hsMoreSheet">⋯ Daha Fazla</button>
        </div>
    </div>
</div>

<!-- ── Reddedilenler ── -->
<?php if (!empty($reddedilenler)): ?>
<div class="hs-alert" role="alert">
    <strong><?= count($reddedilenler) ?> fiş reddedildi — düzeltip yeniden gönderin</strong>
    <ul>
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

<!-- ── Ay özeti (katlanabilir) ── -->
<details class="hs-fold">
    <summary><?= h($ay_label) ?> özeti</summary>
    <div class="hs-fold-body">
        <dl class="hs-kv">
            <dt>Gelir</dt>            <dd class="pos"><?= fmt_para($ay_try['gelir']) ?></dd>
            <dt>Gider</dt>            <dd class="neg"><?= fmt_para($ay_try['gider']) ?></dd>
            <dt>Bu ay net</dt>        <dd><?= fmt_para($ay_net) ?></dd>
            <dt>Geçen aydan devir</dt><dd><?= fmt_para($devir) ?></dd>
            <dt>Yemek gideri</dt>     <dd><?= fmt_para((float)$sayac['ay_yemek']) ?></dd>
            <dt>Şirket malzemesi</dt> <dd><?= fmt_para((float)$sayac['ay_malzeme']) ?></dd>
            <dt>Fişi eksik kayıt</dt> <dd><?= (int)$sayac['fissiz'] ?></dd>
            <dt>Toplam kayıt</dt>     <dd><?= (int)$sayac['toplam_kayit'] ?></dd>
        </dl>
    </div>
</details>

<!-- ── Son işlemler ── -->
<?php if (empty($son_islemler)): ?>
<div class="hs-empty">
    <span class="hs-empty-icon" aria-hidden="true">🧾</span>
    <p>Henüz kayıt yok. İlk fişinizi ekleyin.</p>
    <?php if (hesap_can('write')): ?>
    <a href="hesap_kayit.php?hizli=gider" class="btn btn-primary">📷 Harcama Ekle</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="hs-section-head">
    <h2>Son İşlemler</h2>
    <a href="hesap_liste.php">Tümü →</a>
</div>
<div class="hs-tx-list">
<?php foreach ($son_islemler as $t):
    $gelir_mi = $t['type'] === 'gelir';
    $fd  = hesap_fis_durumu($t);
    $thb = $thumbs[(int)$t['id']] ?? null;
?>
<a class="hs-tx" href="hesap_kayit.php?id=<?= (int)$t['id'] ?>">
    <span class="hs-tx-dot" style="background:<?= hesap_type_color($t['type']) ?>" aria-hidden="true"></span>
    <span class="hs-tx-main">
        <span class="hs-tx-title"><?= h($t['category'] ?: hesap_type_label($t['type'])) ?></span>
        <span class="hs-tx-meta">
            <?= h(date('d.m.Y', strtotime($t['transaction_date']))) ?>
            <?php if ($t['person_company']): ?>· <?= h($t['person_company']) ?><?php endif; ?>
            <?php if (!$fd['var']): ?>· <span class="hs-tx-warn">⚠ Fiş yok</span><?php endif; ?>
        </span>
        <span class="hs-tx-meta"><?= hesap_status_badge($t['status'] ?? null, true) ?></span>
    </span>
    <span class="hs-tx-side">
        <span class="hs-tx-amount <?= $gelir_mi ? 'pos' : 'neg' ?>">
            <?= ($gelir_mi ? '+' : '−') . fmt_para((float)$t['amount'], $t['currency']) ?>
        </span>
        <?php if ($thb): ?>
        <img class="hs-tx-thumb" src="hesap_dosya.php?f=<?= urlencode($thb) ?>" alt="Fiş" loading="lazy">
        <?php endif; ?>
    </span>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Alt sayfa: kayıt türleri ── -->
<?php if (hesap_can('write')): ?>
<div class="hs-sheet-ovl" id="hsQuickSheet" hidden role="dialog" aria-modal="true" aria-label="Kayıt türü seç">
    <div class="hs-sheet">
        <div class="hs-sheet-grip" aria-hidden="true"></div>
        <p class="hs-sheet-title">Ne kaydetmek istiyorsunuz?</p>
        <div class="hs-sheet-list">
            <?php foreach ([
                ['gider',   '🧾', 'Harcama',      'Günlük harcama — fişli'],
                ['yemek',   '🍽️', 'Yemek Fişi',   'Yemek gideri'],
                ['malzeme', '📦', 'Şirket Malzemesi', 'Malzeme alımı'],
                ['gelir',   '💵', 'Gelen Para',   'Size ulaşan ödeme'],
                ['havale',  '🏦', 'Havale',       'Banka transferi'],
                ['nakit',   '💴', 'Nakit Ödeme',  'Elden ödeme'],
            ] as [$slug, $ikon, $ad, $aciklama]): ?>
            <a class="hs-sheet-item" href="hesap_kayit.php?hizli=<?= h($slug) ?>">
                <span class="hs-sheet-icon" aria-hidden="true"><?= $ikon ?></span>
                <span><?= h($ad) ?><span class="hs-sheet-sub"><?= h($aciklama) ?></span></span>
            </a>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-block" data-hs-sheet-close>Kapat</button>
    </div>
</div>
<?php endif; ?>

<!-- ── Alt sayfa: diğer sayfalar ── -->
<div class="hs-sheet-ovl" id="hsMoreSheet" hidden role="dialog" aria-modal="true" aria-label="Diğer işlemler">
    <div class="hs-sheet">
        <div class="hs-sheet-grip" aria-hidden="true"></div>
        <p class="hs-sheet-title">Daha fazla</p>
        <div class="hs-sheet-list">
            <a class="hs-sheet-item" href="hesap_liste.php">
                <span class="hs-sheet-icon" aria-hidden="true">📋</span>
                <span>Tüm Kayıtlar<span class="hs-sheet-sub"><?= (int)$sayac['toplam_kayit'] ?> kayıt</span></span>
            </a>
            <?php if (hesap_can('approve')): ?>
            <a class="hs-sheet-item" href="hesap_muhasebe.php">
                <span class="hs-sheet-icon" aria-hidden="true">🗂️</span>
                <span>Muhasebe Onay Kuyruğu<span class="hs-sheet-sub"><?= (int)$sayac['bekleyen'] ?> kayıt bekliyor</span></span>
            </a>
            <?php endif; ?>
            <a class="hs-sheet-item" href="hesap_muhasebe_fis_pdf.php" target="_blank" rel="noopener">
                <span class="hs-sheet-icon" aria-hidden="true">📸</span>
                <span>Fiş Fotoğraf Dökümü<span class="hs-sheet-sub">Yazdırılabilir sayfa</span></span>
            </a>
            <?php if (can('reports.export')): ?>
            <a class="hs-sheet-item" href="hesap_export.php">
                <span class="hs-sheet-icon" aria-hidden="true">📊</span>
                <span>Excel'e Aktar<span class="hs-sheet-sub">Filtreli tablo</span></span>
            </a>
            <?php endif; ?>
            <a class="hs-sheet-item" href="hesap_yazdir.php?<?= http_build_query(['tarih_bas'=>$ay_bas,'tarih_son'=>date('Y-m-d', strtotime($ay_son . ' -1 day'))]) ?>"
               target="_blank" rel="noopener">
                <span class="hs-sheet-icon" aria-hidden="true">📄</span>
                <span>PDF Dönem Raporu<span class="hs-sheet-sub"><?= h($ay_label) ?> · logo, özet, fiş görselleri</span></span>
            </a>
        </div>
        <button type="button" class="btn btn-block" data-hs-sheet-close>Kapat</button>
    </div>
</div>

</div><!-- /.hs -->
<?php hesap_scripts(); ?>
<?php render_footer(); ?>
