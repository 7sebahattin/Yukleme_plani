<?php
// =========================================================
// ters_kart_mutabakat.php — Ters Kart Takip · D) GÜN SONU VE MUTABAKAT (v354)
//
// SALT OKUNUR. Çavuş bazında: havuz · kalan · dağıtılan · giriş · çıkış ·
// içeride · eksik çıkış · dönmeyen kartlar. "İçerideki çalışan", "çıkışı
// yapılmayan işçi" ve "fiziksel olarak dönmeyen kart" ayrı gösterilir; eksik
// çıkış kendiliğinden tamamlanmış SAYILMAZ — düzeltme mevcut yetkili araçlarla
// (Mesaiyi Kapat / Mesai Detayı) yapılır.
// Yetki: attendance.daily_scan veya attendance.daily_reports.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$y = pdks_ters_require(['mutabakat']);

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$base = base_url();
$depo = pdks_ters_aktif_depo();
$tarih = (string)($_GET['tarih'] ?? date('Y-m-d'));
$d = DateTimeImmutable::createFromFormat('!Y-m-d', $tarih);
if (!$d || $d->format('Y-m-d') !== $tarih || $tarih > date('Y-m-d')) $tarih = date('Y-m-d');
$hazir = pdks_ters_sema_hazir($pdo);
$mt = $hazir ? pdks_ters_mutabakat($pdo, $tarih, $depo) : ['satirlar' => [], 'toplam' => []];

render_header('Mutabakat — Ters Kart Takip');
pdks_ters_varliklar($base);
render_flash();
?>
<div class="tk-sayfa" data-tk-sayfa="mutabakat">
<div class="page-head">
    <h1>📋 Gün Sonu ve Mutabakat</h1>
    <div class="page-head-actions">
        <a href="ters_kart.php" class="btn btn-geri">← Ters Kart Takip</a>
    </div>
</div>
<?php pdks_ters_sekmeler('mutabakat', $y); ?>

<?php if (!pdks_ters_kurulum_uyarisi($pdo)): ?>
<form method="get" class="tk-filtre pdks-filter-bar" data-oto-filtre>
    <label class="tk-etiket">Tarih <input type="date" name="tarih" value="<?= h($tarih) ?>" max="<?= h(date('Y-m-d')) ?>"></label>
    <noscript><button type="submit" class="btn">Göster</button></noscript>
    <span class="muted">Depo: <strong><?= h($depo) ?></strong></span>
</form>

<?php if (!$mt['satirlar']): ?>
    <div class="tk-kutu tk-kutu-bilgi">Bu tarih ve depo için ters kart havuzu ya da sayım yok.</div>
<?php else: $t = $mt['toplam']; ?>
<div class="tk-sayaclar">
    <div class="tk-sayac"><span class="tk-sayac-n"><?= (int)$t['dagitilan'] ?></span><span class="tk-sayac-et">Dağıtılan kart</span></div>
    <div class="tk-sayac tk-sayac-yesil"><span class="tk-sayac-n"><?= (int)$t['cikis'] ?></span><span class="tk-sayac-et">Geri alınan (çıkış)</span></div>
    <div class="tk-sayac tk-sayac-mavi"><span class="tk-sayac-n"><?= (int)$t['iceride'] ?></span><span class="tk-sayac-et">Hâlâ içeride</span></div>
    <div class="tk-sayac tk-sayac-turuncu"><span class="tk-sayac-n"><?= (int)$t['eksik_cikis'] ?></span><span class="tk-sayac-et">Eksik çıkış (mesai kapalı)</span></div>
</div>

<div class="tk-mt-liste">
<?php foreach ($mt['satirlar'] as $r):
    $s = $r['sayim'];
    $uyari = $s && $s['durum'] === 'onayli' && ($r['iceride'] + $r['eksik_cikis']) > 0;
?>
<article class="tk-mt-kart<?= $uyari ? ' tk-mt-uyari' : '' ?>">
    <header class="tk-baslik-satir">
        <h3><?= h($r['foreman_name']) ?></h3>
        <?php if (!$s): ?><span class="tk-rozet">Sayım yok</span>
        <?php elseif ($s['durum'] === 'taslak'): ?><span class="tk-rozet tk-rozet-uyari">Sayım onaylanmadı</span>
        <?php else: ?><span class="tk-rozet tk-rozet-ok">Giriş <?= h((string)$s['giris_saati']) ?> · onay <?= h(date('H:i', strtotime((string)$s['onay_zamani']))) ?></span><?php endif; ?>
    </header>
    <dl class="tk-mt-tablo">
        <div><dt>Aktif kart havuzu</dt><dd><?= (int)$r['havuz']['aktif'] ?><?= $r['havuz']['toplam'] !== $r['havuz']['aktif'] ? ' <small class="muted">(+' . ($r['havuz']['toplam'] - $r['havuz']['aktif']) . ' kayıp/bozuk/pasif)</small>' : '' ?></dd></div>
        <div><dt>Sabah dağıtılmamış (elde kalan)</dt><dd><?= (int)$r['kalan'] ?></dd></div>
        <div><dt>Dağıtılmış kart</dt><dd><strong><?= (int)$r['dagitilan'] ?></strong><?= ($s['beyan'] ?? null) !== null ? ' <small class="muted">(çavuş beyanı ' . (int)$s['beyan'] . ')</small>' : '' ?></dd></div>
        <div><dt>Giriş kaydı</dt><dd><?= (int)$r['giris'] ?><?= $r['iptal'] ? ' <small class="muted">(' . (int)$r['iptal'] . ' iptal)</small>' : '' ?></dd></div>
        <div><dt>Çıkış kaydı = geri alınan kart</dt><dd><?= (int)$r['cikis'] ?></dd></div>
        <div class="<?= $r['iceride'] ? 'tk-mt-mavi' : '' ?>"><dt>Hâlâ içeride (mesai açık)</dt><dd><?= (int)$r['iceride'] ?></dd></div>
        <div class="<?= $r['eksik_cikis'] ? 'tk-mt-turuncu' : '' ?>"><dt>Eksik çıkış (mesai kapatıldı)</dt><dd><?= (int)$r['eksik_cikis'] ?></dd></div>
        <?php if ($r['mesai_toplam']): ?>
        <div><dt>Mesainin tüm girişleri <small class="muted">(normal okutma dahil)</small></dt><dd><?= (int)$r['mesai_toplam']['giris'] ?> giriş · <?= (int)$r['mesai_toplam']['cikis'] ?> çıkış</dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($r['donmeyen_kartlar']): ?>
    <details>
        <summary>Henüz dönmeyen kartlar (<?= count($r['donmeyen_kartlar']) ?>)</summary>
        <div class="tk-cip-liste">
            <?php foreach ($r['donmeyen_kartlar'] as $k): ?>
            <span class="tk-cip <?= $k['durum'] === 'İçeride' ? 'tk-cip-zaten_iceride' : 'tk-cip-haric' ?>" title="<?= h($k['durum']) ?>"><?= h($k['card_no']) ?> <small><?= h($k['tip']) ?></small></span>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>
    <div class="tk-eylemler">
        <?php if ($r['mesai']): ?><a class="btn btn-sm" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['mesai']['id'] ?>">Mesai Detayı<?= $r['mesai']['status'] === 'closed' ? ' (kapalı)' : '' ?></a><?php endif; ?>
        <?php if ($tarih === date('Y-m-d') && $y['tarama']): ?><a class="btn btn-sm" href="ters_kart_sayim.php?cavus=<?= (int)$r['foreman_id'] ?>">Sayım</a><?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
</div>
<p class="muted tk-kucuk">Eksik çıkışlar kendiliğinden tamamlanmaz. Çıkışı unutulan kartlar için yönetici Mesai Detayı'ndaki düzeltme araçlarını kullanır; kartı kaybolanlar Kart Havuzu'ndan "Kayıp" işaretlenir.</p>
<?php endif; ?>
<?php endif; ?>
</div>
<?php
if (function_exists('pdks_liste_ui_js')) pdks_liste_ui_js();
render_footer();
