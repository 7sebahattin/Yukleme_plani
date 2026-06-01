<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('reports.read');

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

// Ana özet sorgusu — seçili ay + devir (ay öncesi bakiye)
$st = db()->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='gelir'           AND transaction_date>='{$ay_bas}' AND transaction_date<'{$ay_son}' THEN amount END),0) AS ay_gelir,
        COALESCE(SUM(CASE WHEN type IN ('gider','nakit') AND transaction_date>='{$ay_bas}' AND transaction_date<'{$ay_son}' THEN amount END),0) AS ay_gider,
        COALESCE(SUM(CASE WHEN type='havale'           AND transaction_date>='{$ay_bas}' AND transaction_date<'{$ay_son}' THEN amount END),0) AS ay_havale,
        COALESCE(SUM(CASE WHEN category='Yemek gideri'     AND transaction_date>='{$ay_bas}' AND transaction_date<'{$ay_son}' THEN amount END),0) AS ay_yemek,
        COALESCE(SUM(CASE WHEN category='Şirket malzemesi' AND transaction_date>='{$ay_bas}' AND transaction_date<'{$ay_son}' THEN amount END),0) AS ay_malzeme,
        COALESCE(SUM(CASE WHEN type='gelir'                AND transaction_date<'{$ay_bas}' THEN amount END),0) AS devir_gelir,
        COALESCE(SUM(CASE WHEN type IN ('gider','nakit','havale') AND transaction_date<'{$ay_bas}' THEN amount END),0) AS devir_gider,
        (SELECT COUNT(*) FROM account_transactions WHERE is_given_to_accountant=0) AS bekleyen,
        (SELECT COUNT(*) FROM account_transactions WHERE has_files=0)              AS fissiz,
        (SELECT COUNT(*) FROM account_transactions)                                AS toplam_kayit
    FROM account_transactions WHERE currency='TRY'
")->fetch();

// Bakiye hesaplama
// Geçen Aydan Devir = seçili ay başından önceki tüm kayıtların net toplamı
// Bu Ay Net         = seçili ayın gelir - gider - havale
// Güncel Bakiye     = Devir + Bu Ay Net
$ay_net = (float)$st['ay_gelir'] - (float)$st['ay_gider'] - (float)$st['ay_havale'];
$devir  = (float)$st['devir_gelir'] - (float)$st['devir_gider'];
$bakiye = $devir + $ay_net;

$son_islemler = db()->query("SELECT * FROM account_transactions ORDER BY transaction_date DESC, id DESC LIMIT 10")->fetchAll();

render_header('Hesap');
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

<!-- Özet Kartlar -->
<div class="hesap-stat-grid">
    <div class="hesap-stat green"><span>Bu Ay Gelir</span><strong><?= fmt_para((float)$st['ay_gelir']) ?></strong></div>
    <div class="hesap-stat red"><span>Bu Ay Gider</span><strong><?= fmt_para((float)$st['ay_gider'] + (float)$st['ay_havale']) ?></strong></div>
    <div class="hesap-stat gray"><span>Geçen Aydan Devir</span><strong><?= fmt_para($devir) ?></strong></div>
    <div class="hesap-stat <?= $bakiye >= 0 ? 'green' : 'red' ?>">
        <span>Güncel Bakiye</span>
        <strong><?= fmt_para($bakiye) ?></strong>
        <small>Devir <?= fmt_para($devir) ?> · Net <?= fmt_para($ay_net) ?></small>
    </div>
    <div class="hesap-stat blue"><span>Bu Ay Havale</span><strong><?= fmt_para((float)$st['ay_havale']) ?></strong></div>
    <div class="hesap-stat orange"><span>Yemek Gideri</span><strong><?= fmt_para((float)$st['ay_yemek']) ?></strong></div>
    <div class="hesap-stat blue"><span>Şirket Malzeme</span><strong><?= fmt_para((float)$st['ay_malzeme']) ?></strong></div>
    <div class="hesap-stat <?= (int)$st['bekleyen'] > 0 ? 'orange' : 'green' ?>"><span>Muhasebe Bekleyen</span><strong><?= (int)$st['bekleyen'] ?> kayıt</strong></div>
    <div class="hesap-stat <?= (int)$st['fissiz'] > 0 ? 'red' : 'green' ?>"><span>Fişsiz Kayıt</span><strong><?= (int)$st['fissiz'] ?> adet</strong></div>
</div>

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
    <a href="hesap_liste.php" class="btn btn-ghost">📋 Tüm Kayıtlar (<?= (int)$st['toplam_kayit'] ?>)</a>
    <a href="hesap_muhasebe.php" class="btn btn-ghost">🗂️ Muhasebe Dökümü</a>
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
            <div class="muted" style="font-size:.72rem"><?= h(date('d.m.Y', strtotime($t['transaction_date']))) ?>
                <?php if (!$t['has_invoice']): ?> <span style="color:var(--danger)">⚠ fiş yok</span><?php endif; ?>
                <?php if (!$t['is_given_to_accountant']): ?> <span style="color:var(--warn)">• bekliyor</span><?php endif; ?>
            </div>
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
