<?php
// =========================================================
// frugro_siparis.php — Frugro Siparişler: liste (PO bazlı; alıcının "Order Log" Excel'inden). OGL listesinin (siparisler.php) eşi: üç bölüm kartı
// (⏳ İşlem Bekleyen / 📦 Evrak Takımı Bekleyen / ✅ Kapanan), KPI çipleri, tek satır arama + ▾ Filtre, açılır "Order Log yükle · Mailden al" kartı,
// Yazdır / PDF / WP Resim, ⋮ menü (Aç · PKL · Invoice · WP Paylaş). İÇERİK Frugro'dan.
//
// Gösterim: siparis.read (OGL ile aynı kapı) · Excel yükleme: frugro_yukle.php (siparis.write) · detay: frugro_view.php · yazdır: frugro_yazdir.php ·
// WP Paylaş: frugro_paylas.php. Görünüm assets/siparis.css (.sip-mod) + assets/siparis.js/siparis_yazdir.js/siparis_wp.js AYNEN; Frugro'ya özel yalnız
// assets/frugro.css. Çekirdek config/frugro_core.php, ekran yardımcıları config/frugro_ui.php. Veri burada hazırlanır; _frugro_liste.php sorgu ÇALIŞTIRMAZ.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/config/frugro_mail.php';
require_once __DIR__ . '/config/frugro_pkl.php';       // frgp_dosya_adi (liste satırındaki PKL dosya adı)
require_once __DIR__ . '/config/frugro_invoice.php';   // frgi_dosya_adi (Invoice dosya adı)
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo   = db();
$hazir = frg_hazir($pdo);

render_header('Frugro Siparişler');
siparis_assets();
echo '<link rel="stylesheet" href="assets/frugro.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro.css') . '">' . "\n";
render_flash();
if (!$hazir) {
    echo '<div class="sip-mod frg"><div class="card frg-bos"><h2>Frugro Siparişler henüz kurulmadı</h2><p class="muted">'
        . (is_admin() ? 'Şema Migrasyon Paneli › <a href="migrate.php#frugro">Frugro Siparişler</a> kartındaki düğmeyle tablolar oluşturulur.' : 'Yönetici şema kurulumunu tamamlayınca bu sayfa açılır.')
        . '</p></div></div>';
    render_footer();
    exit;
}

$yaz = frgui_yetki('create');
$F = frgui_filtre($_GET);
$q = $F['q']; $f_durum = $F['durum'];

// ── bölümler (OGL ile aynı: açık / evrak / kapanan; tek durum süzgecinde düz "Sonuçlar") ──
$B_acik = $B_evrak = $B_kapali = $B_tek = null;
if ($F['tek']) $B_tek = frgui_bolum_oku($pdo, $F, 'tek', $F['acik_page']);
else {
    if ($F['bolumAcik'])   $B_acik   = frgui_bolum_oku($pdo, $F, 'acik', $F['acik_page']);
    if ($F['bolumEvrak'])  $B_evrak  = frgui_bolum_oku($pdo, $F, 'evrak', $F['evrak_page']);
    if ($F['bolumKapali']) $B_kapali = frgui_bolum_oku($pdo, $F, 'kapali', $F['kapali_page']);
}
$listeIds = [];
foreach ([$B_acik, $B_evrak, $B_kapali, $B_tek] as $__b) { if ($__b !== null) foreach ($__b['rows'] as $__r) $listeIds[] = (int)$__r['id']; }

// ── Liste zenginleştirme: listelenen TÜM siparişler için tek sorgu başına bir harita (N+1 yok; partial sorgu çalıştırmaz) ──
$paletHarita = frg_palet_ozet_haritasi($pdo, $listeIds);
$uyHarita    = frg_uyari_haritasi($pdo, $listeIds);
$dgHarita    = frg_degisim_haritasi($pdo, $listeIds);
$adimHarita  = frg_adim_zaman_haritasi($pdo, $listeIds, ['mail_gonderildi']);

$kpiSay = frgui_kpi_say($pdo);
$kpiCipler = frgui_kpi_cipler($pdo, $F, $kpiSay);
$kpiAcik = $kpiSay['acik']; $kpiEvrak = $kpiSay['evrak']; $kapaliSay = $kpiSay['kapali'];

// müşteri / liman listeleri (filtre paneli; yalnız aktif depo)
$musteriler = []; $limanlar = [];
try {
    [$__w, $__p] = frg_liste_where([]);
    $__dist = static function (string $kol) use ($pdo, $__w, $__p): array {   // $kol sabit (beyaz liste), kullanıcı girdisi DEĞİL
        $st = $pdo->prepare("SELECT DISTINCT $kol FROM frugro_siparisler WHERE $__w AND $kol IS NOT NULL AND $kol <> '' ORDER BY $kol LIMIT 200");
        $st->execute($__p);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $musteriler = $__dist('son_musteri');
    $limanlar = $__dist('giris_limani');
} catch (Throwable $e) {
    error_log('[frugro_siparis] filtre listeleri: ' . get_class($e));
}

// "Order Log yükle · Mailden al" kartı: mail kartı verisi (sorgu burada; _frugro_mail_kart.php yalnız çizer)
$frgMailVeri = frgm_kart_veri($pdo, (int)$auth_user['id']);
$mailVar = !empty($frgMailVeri['goster']);
$mailSon = null;
if ($mailVar && !empty($frgMailVeri['hazir'])) { try { $mailSon = frgm_son($pdo); } catch (Throwable $e) { $mailSon = null; } }
$yukleAcik = !empty($frgMailVeri['yarim']);

$sayfalama = function (array $b, string $param): string {
    if ($b['sayfa_say'] <= 1) return '';
    $o = '<nav class="pagination sip-sayfalama" aria-label="Sayfalama">';
    $o .= $b['sayfa'] > 1 ? '<a class="btn btn-sm" href="' . h(frgui_url([$param => $b['sayfa'] - 1 > 1 ? $b['sayfa'] - 1 : null])) . '">‹ Önceki</a>' : '<span class="btn btn-sm" aria-disabled="true" style="opacity:.45">‹ Önceki</span>';
    $o .= '<span class="muted">Sayfa ' . (int)$b['sayfa'] . ' / ' . (int)$b['sayfa_say'] . '</span>';
    $o .= $b['sayfa'] < $b['sayfa_say'] ? '<a class="btn btn-sm" href="' . h(frgui_url([$param => $b['sayfa'] + 1])) . '">Sonraki ›</a>' : '<span class="btn btn-sm" aria-disabled="true" style="opacity:.45">Sonraki ›</span>';
    return $o . '</nav>';
};
$yazdirEk = static function (?string $bolumAdi): string {
    return frgui_yazdir_eylem(frgui_url(['acik_page' => null, 'evrak_page' => null, 'kapali_page' => null, 'bolum' => $bolumAdi], 'frugro_yazdir.php'));
};
?>
<div class="sip-mod frg" id="frg" data-csrf="<?= h(csrf_token()) ?>" data-yazma="<?= $yaz ? '1' : '0' ?>">
  <?= frgui_gorulen_meta((int)$auth_user['id']) ?>
  <div class="page-head">
    <div>
      <h1>🍇 Frugro Siparişler</h1>
      <p class="muted"><?= (int)$kpiAcik ?> açık · <?= (int)$kpiEvrak ?> evrak bekleyen · <?= (int)$kapaliSay ?> kapanan sipariş</p>
    </div>
    <div class="page-head-actions no-print">
      <div class="pc-kebab-wrap">
        <button class="btn pc-kebab" type="button" title="Diğer işlemler" aria-label="Diğer işlemler">⋮</button>
        <div class="pc-dropdown" hidden>
          <?php if (frgm_ayar_yetkisi()): ?><a href="frugro_ayarlar.php">⚙ Ayarlar</a><?php endif; ?>
          <a href="siparisler.php">← OGL Siparişler</a>
        </div>
      </div>
    </div>
  </div>

<?php if ($yaz || $mailVar): ?>
  <details class="card sip-yukle" id="yukle" data-sip-yukle data-sip-yukle-uid="<?= (int)$auth_user['id'] ?>"<?= $yukleAcik ? ' open' : '' ?>>
    <summary class="sip-yukle-ozet"><?= frgui_yukle_ozet_html($mailSon, $frgMailVeri['yarim'] ?? null, $mailVar && !empty($frgMailVeri['hazir'])) ?></summary>
    <div class="sip-yukle-govde">
      <?php include __DIR__ . '/_frugro_mail_kart.php'; ?>
      <?php if ($yaz): ?>
      <details class="sip-dosya-ac" id="dosya">
        <summary class="sip-dosya-ozet"><span class="sip-dosya-baslik">📥 Excel'den yükle</span><span class="sip-dosya-ipucu">Frugro Order Log (.xlsx)</span><span class="sip-yukle-ok" aria-hidden="true">▸</span></summary>
        <div class="sip-yukle-alt">
          <button type="button" class="btn btn-primary" id="frgYukleDugme">📥 Excel yükle</button>
          <input type="file" id="frgDosya" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" multiple hidden>
          <span class="muted">Aynı PO tekrar gelirse yeni kayıt açılmaz; fark olay olarak yazılır, alanlar ezilmez.</span>
        </div>
        <div class="card frg-sonuc" id="frgSonuc" role="status" aria-live="polite" hidden></div>
      </details>
      <?php endif; ?>
    </div>
  </details>
<?php endif; ?>

  <div class="sip-kpi-kap" aria-label="Hızlı filtreler"><div class="sip-kpi">
    <?php foreach ($kpiCipler as $c): ?>
      <a class="sip-chip<?= $c['aktif'] ? ' sip-chip--aktif' : '' ?><?= $c['k'] === 'pb' && $c['say'] > 0 ? ' sip-chip--uyari' : '' ?>" href="<?= h($c['url']) ?>"<?= $c['aktif'] ? ' aria-current="true"' : '' ?>>
        <span class="sip-chip-et"><?= h($c['et']) ?></span><span class="sip-chip-say"><?= (int)$c['say'] ?></span>
      </a>
    <?php endforeach; ?>
  </div></div>

  <form method="get" action="frugro_siparis.php" class="rpt-filter-card sip-filtre no-print" role="search">
    <div class="sip-filtre-ana">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="PO no, müşteri, ürün, parti no, plaka, nakliyeci…" aria-label="Ara" maxlength="80">
      <button type="submit" class="btn btn-primary">Ara</button>
      <button type="button" class="btn sip-filtre-ac" data-sip-filtre-toggle aria-expanded="<?= $F['detayFiltre'] ? 'true' : 'false' ?>" aria-controls="sipFiltrePanel">
        ▾ Filtre<?php if ($F['detayOzet']): ?> <span class="sip-chip-say"><?= h(implode(' · ', $F['detayOzet'])) ?></span><?php endif; ?>
      </button>
      <?php if ($F['filtreVar']): ?><a class="btn btn-ghost" href="frugro_siparis.php">Temizle</a><?php endif; ?>
    </div>
    <div class="sip-filtre-panel<?= $F['detayFiltre'] ? ' sip-acik' : '' ?>" id="sipFiltrePanel">
      <label>Durum
        <select name="durum">
          <option value="">Tümü</option>
          <option value="acik"<?= $f_durum === 'acik' ? ' selected' : '' ?>>Açık olanlar (işlem bekleyen)</option>
          <option value="evrak"<?= $f_durum === 'evrak' ? ' selected' : '' ?>>Evrak takımı bekleyen</option>
          <option value="kapali"<?= $f_durum === 'kapali' ? ' selected' : '' ?>>Kapananlar</option>
          <?php foreach (frgui_sanal_durumlar() as $dk => $dv): ?>
            <option value="<?= h($dk) ?>"<?= $f_durum === $dk ? ' selected' : '' ?>><?= h($dv['etiket']) ?></option>
          <?php endforeach; ?>
          <?php foreach (frg_durum_tanimlari() as $dk => $dv): ?>
            <option value="<?= h($dk) ?>"<?= $f_durum === $dk ? ' selected' : '' ?>><?= h($dv['ikon'] . ' ' . $dv['etiket']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Müşteri
        <select name="musteri">
          <option value="">Tümü</option>
          <?php foreach ($musteriler as $m): ?><option value="<?= h($m) ?>"<?= $F['musteri'] === $m ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Giriş limanı
        <select name="liman">
          <option value="">Tümü</option>
          <?php foreach ($limanlar as $m): ?><option value="<?= h($m) ?>"<?= $F['liman'] === $m ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Çıkış (başlangıç)<input type="date" name="bas" value="<?= h($F['bas']) ?>"></label>
      <label>Çıkış (bitiş)<input type="date" name="bit" value="<?= h($F['bit']) ?>"></label>
      <button type="submit" class="btn btn-primary sip-uygula">Uygula</button>
    </div>
  </form>

  <?php
  $bolumCiz = function (array $b, string $baslik, string $id, string $param, string $bos, string $ikon = '', string $not = '') use ($sayfalama, $yazdirEk, $paletHarita, $uyHarita, $dgHarita, $adimHarita): void {
      $yb = ['sipSonuclar' => null, 'sipAcik' => 'acik', 'sipEvrak' => 'evrak', 'sipKapali' => 'kapali'];
      $ek = array_key_exists($id, $yb) && $b['rows'] ? $yazdirEk($yb[$id]) : '';
      $ton = ['sipAcik' => ' sip-bolum-acik', 'sipEvrak' => ' sip-bolum-evrak', 'sipKapali' => ' sip-bolum-kapali'][$id] ?? '';
      echo '<section class="sip-bolum' . $ton . '" aria-labelledby="' . h($id) . '">' . frgui_bolum_bas($baslik, (int)$b['toplam'], $id, $ek, $ikon, $not);
      if (!$b['rows']) {
          echo '<div class="empty sip-bos"><p>' . h($bos) . '</p></div>';
      } else {
          $sec_rows = $b['rows']; $sec_palet = $paletHarita; $sec_uy = $uyHarita; $sec_dg = $dgHarita; $sec_adim = $adimHarita;
          include __DIR__ . '/_frugro_liste.php';
          echo $sayfalama($b, $param);
      }
      echo '</section>';
  };
  if ($B_tek !== null) $bolumCiz($B_tek, 'Sonuçlar', 'sipSonuclar', 'acik_page', 'Bu filtreyle eşleşen sipariş yok.');
  if ($B_acik !== null) $bolumCiz($B_acik, 'İşlem Bekleyen Siparişler', 'sipAcik', 'acik_page',
      $F['filtreVar'] ? 'Bu filtreyle eşleşen açık sipariş yok.' : ($yaz ? 'Henüz açık sipariş yok. Yukarıdan Frugro Order Log Excel\'ini yükleyin ya da mailden alın.' : 'Henüz açık sipariş yok.'),
      '⏳', 'PKL, Invoice, yola çıkış ya da mail bekleyen açık işler');
  if ($B_evrak !== null) $bolumCiz($B_evrak, 'Evrak Takımı Bekleyen Siparişler', 'sipEvrak', 'evrak_page',
      $F['filtreVar'] ? 'Bu filtreyle eşleşen evrak bekleyen sipariş yok.' : 'Evrak takımı bekleyen sipariş yok.',
      '📦', 'PKL maili gönderildi — evrak takımı gönderilince siparişte “📦 Evrak takımı” adımını işaretleyin');
  if ($B_kapali !== null) $bolumCiz($B_kapali, 'Kapanan Siparişler', 'sipKapali', 'kapali_page', 'Kapanan sipariş yok.', '✅', 'Tamamlanan kayıtlar (evrak takımı gönderildi · iptal)');
  ?>
</div>
<?php
siparis_scripts();
echo '<script src="assets/siparis_yazdir.js?v=' . (int)@filemtime(__DIR__ . '/assets/siparis_yazdir.js') . '" defer></script>' . "\n";
echo '<script src="assets/siparis_wp.js?v=' . (int)@filemtime(__DIR__ . '/assets/siparis_wp.js') . '" defer></script>' . "\n";
echo '<script src="assets/frugro.js?v=' . (int)@filemtime(__DIR__ . '/assets/frugro.js') . '" defer></script>' . "\n";
render_footer();
