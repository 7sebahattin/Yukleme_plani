<?php
// =========================================================
// frugro_siparis.php — Frugro Siparişler: liste (PO bazlı; alıcının "Order Log" Excel'inden)
//
// Gösterim: siparis.read (OGL ile aynı kapı) · Excel yükleme: frugro_yukle.php (siparis.write) · detay: frugro_view.php.
// Liste burada render edilir; çekirdek config/frugro_core.php, ekran yardımcıları config/frugro_ui.php.
// Mail Merkezi'nden gelen Order Log kartı: _frugro_mail_kart.php (varsa) include edilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/config/frugro_mail.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo   = db();
$hazir = frg_hazir($pdo);

render_header('Frugro Siparişler');
echo '<link rel="stylesheet" href="assets/frugro.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro.css') . '">' . "\n";
render_flash();
if (!$hazir) {
    echo '<div class="frg"><div class="card frg-bos"><h2>Frugro Siparişler henüz kurulmadı</h2><p class="muted">'
        . (is_admin() ? 'Şema Migrasyon Paneli › <a href="migrate.php#frugro">Frugro Siparişler</a> kartındaki düğmeyle tablolar oluşturulur.' : 'Yönetici şema kurulumunu tamamlayınca bu sayfa açılır.')
        . '</p></div></div>';
    render_footer();
    exit;
}

$yaz   = frgui_yetki('create');
$q     = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$durum = (string)($_GET['durum'] ?? '');
if ($durum !== '' && !isset(frg_durumlar()[$durum])) $durum = '';
$bugun = date('Y-m-d');

// KPI sayaçları aramaya uyan TÜM durumlardan; tablo ayrıca durum filtresine göre süzülür
$tum = frg_liste($pdo, ['q' => $q, 'limit' => 500]);
$say = array_fill_keys(array_keys(frg_durumlar()), 0);
foreach ($tum as $r) { if (isset($say[$r['durum']])) $say[$r['durum']]++; }
$satirlar = $durum === '' ? $tum : array_values(array_filter($tum, static fn($r) => $r['durum'] === $durum));

$url = static function (array $p): string {
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return 'frugro_siparis.php' . ($p ? '?' . http_build_query($p) : '');
};
?>
<div class="frg" id="frg" data-csrf="<?= h(csrf_token()) ?>" data-yazma="<?= $yaz ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <h1>🍇 Frugro Siparişler</h1>
      <div class="muted">Frugro (İngiltere) Order Log siparişleri; her PO tek kayıt. Satıra tıklayınca detay açılır.</div>
    </div>
    <div class="page-head-actions no-print">
      <a class="btn" href="siparisler.php">← OGL Siparişler</a>
      <?php if (frgm_ayar_yetkisi()): ?><a class="btn" href="frugro_ayarlar.php">⚙ Ayarlar</a><?php endif; ?>
      <?php if ($yaz): ?>
      <button type="button" class="btn btn-primary" id="frgYukleDugme">📥 Excel yükle</button>
      <input type="file" id="frgDosya" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple hidden>
      <?php endif; ?>
    </div>
  </div>

<?php if ($yaz): ?>
  <div class="card frg-sonuc" id="frgSonuc" role="status" aria-live="polite" hidden></div>
<?php endif; ?>

<?php $frgMailVeri = frgm_kart_veri($pdo, (int)$auth_user['id']); include __DIR__ . '/_frugro_mail_kart.php'; ?>

  <div class="frg-kpi no-print" role="group" aria-label="Durum filtreleri">
    <a class="frg-kpi-cip<?= $durum === '' ? ' on' : '' ?>" href="<?= h($url(['q' => $q])) ?>"><b><?= count($tum) ?></b> Tümü</a>
    <?php foreach (frg_durumlar() as $kod => $et): ?>
    <a class="frg-kpi-cip frg-kpi-cip--<?= h($kod) ?><?= $durum === $kod ? ' on' : '' ?>" href="<?= h($url(['q' => $q, 'durum' => $kod])) ?>"><b><?= (int)$say[$kod] ?></b> <?= h($et) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card frg-kart">
    <div class="frg-bas">
      <form method="get" action="frugro_siparis.php" class="frg-ara no-print" role="search">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="PO no, müşteri, ürün ara…" aria-label="Sipariş ara" maxlength="80">
        <select name="durum" aria-label="Durum"><option value="">Tüm durumlar</option>
          <?php foreach (frg_durumlar() as $kod => $et): ?><option value="<?= h($kod) ?>"<?= $durum === $kod ? ' selected' : '' ?>><?= h($et) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn">Ara</button>
        <?php if ($q !== '' || $durum !== ''): ?><a class="btn btn-ghost" href="frugro_siparis.php">Temizle</a><?php endif; ?>
      </form>
      <span class="frg-say"><?= count($satirlar) ?> sipariş</span>
    </div>
<?php if (!$satirlar): ?>
    <div class="frg-bos-satir"><?= ($q !== '' || $durum !== '') ? 'Filtreye uyan sipariş yok.' : 'Henüz sipariş yok.' . ($yaz ? ' “📥 Excel yükle” ile Order Log dosyasını yükleyin.' : '') ?></div>
<?php else: include __DIR__ . '/_frugro_liste.php'; endif; ?>
  </div>
</div>
<script src="assets/frugro.js?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro.js') ?>" defer></script>
<?php render_footer();
