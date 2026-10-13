<?php
// =========================================================
// ters_kart.php — TERS KART TAKİP ana sayfası (v354)
//
// Mevcut Günlük İşçi giriş-çıkış sisteminden BAĞIMSIZ, paralel çalışan model:
// sabah yalnız çavuşun elinde KALAN kartlar okutulur, dağıtılan kartlar sabit
// giriş saatiyle işe başlamış sayılır; akşam kartlar gerçek saatle çıkar.
// Bu sayfa: ekran bağlantıları + aktif depodaki çavuşların bugünkü durumu.
// Yetki: attendance.daily_scan / worker_cards / daily_reports'tan biri.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$y = pdks_ters_require(['havuz', 'tarama', 'mutabakat']);

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$base = base_url();
$depo = pdks_ters_aktif_depo();
$hazir = pdks_ters_sema_hazir($pdo);
$durumlar = $hazir ? pdks_ters_hub_durumlari($pdo, $depo) : [];

render_header('Ters Kart Takip');
pdks_ters_varliklar($base);
render_flash();
?>
<div class="tk-sayfa" data-tk-sayfa="hub">
<div class="page-head">
    <h1>↩ Ters Kart Takip</h1>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-geri btn-geri-ptak">← Personel Takibi</a>
    </div>
</div>
<p class="muted">Sabah her işçiyi okutmak yerine çavuşun elinde <strong>kalan</strong> kartları okutun; dağıtılan kartlar tanımlı saatle işe başlamış sayılır. Akşam kartlar gerçek saatle çıkış yapar. Normal giriş-çıkış ekranları aynen çalışmaya devam eder.</p>

<?php if (!pdks_ters_kurulum_uyarisi($pdo)): ?>
<div class="tk-hub-grid">
    <?php if ($y['havuz']): ?>
    <a class="tk-hub-kart" href="ters_kart_havuz.php"><span class="tk-hub-ikon" aria-hidden="true">🗂</span><span class="tk-hub-ad">A · Kart Havuzu ve Ayarlar</span><span class="tk-hub-alt">Çavuşa kalıcı kart tanımla, giriş saati, kayıp/bozuk kart</span></a>
    <?php endif; ?>
    <?php if ($y['tarama']): ?>
    <a class="tk-hub-kart tk-hub-ana" href="ters_kart_sayim.php"><span class="tk-hub-ikon" aria-hidden="true">🌅</span><span class="tk-hub-ad">B · Sabah Kalan Kart Sayımı</span><span class="tk-hub-alt">Elde kalan kartları okut, girişleri onayla</span></a>
    <a class="tk-hub-kart" href="ters_kart_cikis.php"><span class="tk-hub-ikon" aria-hidden="true">🚪</span><span class="tk-hub-ad">C · Hızlı Çıkış</span><span class="tk-hub-alt">Tek ekrandan, çavuş seçmeden çıkış</span></a>
    <?php endif; ?>
    <?php if ($y['mutabakat']): ?>
    <a class="tk-hub-kart" href="ters_kart_mutabakat.php"><span class="tk-hub-ikon" aria-hidden="true">📋</span><span class="tk-hub-ad">D · Gün Sonu ve Mutabakat</span><span class="tk-hub-alt">Dağıtılan, dönen, dönmeyen kartlar</span></a>
    <?php endif; ?>
</div>

<h2 class="tk-alt-baslik">Bugün · <?= h(date('d.m.Y')) ?> · <?= h($depo) ?></h2>
<?php if (!$durumlar): ?>
    <div class="tk-kutu tk-kutu-bilgi">Bu depoda ters kart havuzu tanımlanmış çavuş yok.<?= $y['havuz'] ? ' <a href="ters_kart_havuz.php">Kart Havuzu</a> ekranından başlayın.' : '' ?></div>
<?php else: ?>
<div class="tk-cavus-grid">
    <?php foreach ($durumlar as $c): $sd = $c['sayim']['durum'] ?? null; ?>
    <a class="tk-cavus-kart" href="<?= $y['tarama'] ? 'ters_kart_sayim.php?cavus=' . (int)$c['foreman_id'] : 'ters_kart_mutabakat.php' ?>">
        <span class="tk-cavus-ad"><?= h($c['name']) ?></span>
        <span class="tk-cavus-alt">Havuz <?= (int)$c['havuz']['aktif'] ?> aktif<?= $c['havuz']['kayip'] ? ' · ' . (int)$c['havuz']['kayip'] . ' kayıp' : '' ?><?= $c['havuz']['bozuk'] ? ' · ' . (int)$c['havuz']['bozuk'] . ' bozuk' : '' ?></span>
        <?php if ($sd === 'onayli'): ?><span class="tk-rozet tk-rozet-ok">✓ <?= (int)$c['sayim']['dagitilan'] ?> kişi girdi</span>
        <?php elseif ($sd === 'taslak'): ?><span class="tk-rozet tk-rozet-uyari">Sayım sürüyor</span>
        <?php else: ?><span class="tk-rozet">Sayım yok</span><?php endif; ?>
        <?php if ($c['icerde']): ?><span class="tk-rozet tk-rozet-mavi">İçeride <?= (int)$c['icerde'] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php render_footer();
