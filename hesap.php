<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';

$bugun = date('Y-m-d');
$ay_bas = date('Y-m-01');

$st = db()->query("
    SELECT
        COALESCE(SUM(CASE WHEN type='gelir' AND transaction_date=CURDATE() THEN amount END),0) AS bugun_gelir,
        COALESCE(SUM(CASE WHEN type IN ('gider','nakit') AND transaction_date=CURDATE() THEN amount END),0) AS bugun_gider,
        COALESCE(SUM(CASE WHEN type='gelir' AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_gelir,
        COALESCE(SUM(CASE WHEN type IN ('gider','nakit') AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_gider,
        COALESCE(SUM(CASE WHEN type='havale' AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_havale,
        COALESCE(SUM(CASE WHEN category='Nakit harcama' AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_nakit,
        COALESCE(SUM(CASE WHEN category='Yemek gideri' AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_yemek,
        COALESCE(SUM(CASE WHEN category='Şirket malzemesi' AND transaction_date>='{$ay_bas}' THEN amount END),0) AS ay_malzeme,
        (SELECT COUNT(*) FROM account_transactions WHERE is_given_to_accountant=0) AS bekleyen,
        (SELECT COUNT(*) FROM account_transactions WHERE has_files=0) AS fissiz,
        (SELECT COUNT(*) FROM account_transactions) AS toplam_kayit
    FROM account_transactions WHERE currency='TRY'
")->fetch();

$bakiye = (float)$st['ay_gelir'] - (float)$st['ay_gider'] - (float)$st['ay_havale'];

$son_islemler = db()->query("SELECT * FROM account_transactions ORDER BY transaction_date DESC, id DESC LIMIT 10")->fetchAll();

render_header('Hesap');
render_flash();
?>
<div class="page-head">
    <div><h1>💰 Hesap</h1><p class="muted">Gelir · Gider · Fiş Takibi</p></div>
    <a href="hesap_kayit.php" class="btn btn-primary btn-lg">+ Yeni Kayıt</a>
</div>

<!-- Özet Kartlar -->
<div class="hesap-stat-grid">
    <div class="hesap-stat green"><span>Bu Ay Gelir</span><strong><?= fmt_para((float)$st['ay_gelir']) ?></strong></div>
    <div class="hesap-stat red"><span>Bu Ay Gider</span><strong><?= fmt_para((float)$st['ay_gider'] + (float)$st['ay_havale']) ?></strong></div>
    <div class="hesap-stat <?= $bakiye >= 0 ? 'green' : 'red' ?>"><span>Bakiye</span><strong><?= fmt_para($bakiye) ?></strong></div>
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
