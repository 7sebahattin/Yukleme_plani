<?php
// =========================================================
// frugro_pkl.php — Frugro Packing List (palet bazlı): düzenleme + PDF/XLSX üretimi
// GET ?id= : görüntüleme (siparis.read + aktif depo). POST (siparis.write, CSRF): kaydet | taslak | uret_pdf | uret_xlsx.
// Mantık config/frugro_pkl.php (frgp_*); bu dosya FONKSİYON TANIMLAMAZ. Ad TEK alt çizgili (sw.js bypass regex'i kapsar).
// Satır içi onclick YOK: data-frp-* (assets/frugro_pkl.js). CSS assets/frugro_pkl.css. PDF/XLSX diske yazılmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_pkl.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();

$pdo = db();
$uid = isset($auth_user['id']) ? (int)$auth_user['id'] : null;
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$yazar = can_siparis('write');
$hatalar = []; $uyarilar = []; $postP = null; $postB = null;

if (!frg_hazir($pdo)) {
    render_header('Frugro PKL');
    echo '<div class="sip-mod"><p class="sip-bilgi" role="note">Frugro tabloları kurulmadı — yönetici migrate.php\'den "Frugro Siparişler" kartını çalıştırmalı.</p></div>';
    render_footer();
    exit;
}
$s = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null) { http_response_code(404); render_header('Frugro PKL'); echo '<div class="sip-mod"><p class="sip-bilgi">Sipariş bulunamadı.</p></div>'; render_footer(); exit; }
if (!frg_gorunur($s)) forbidden('Bu sipariş aktif depoda görünmüyor.');
$self = 'frugro_pkl.php?id=' . $id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_siparis('write');
    csrf_check($_POST['csrf'] ?? null);
    $eylem = (string)($_POST['eylem'] ?? '');
    if ($eylem === 'kaydet') {
        $r = frgp_kaydet($pdo, $s, $_POST, $uid);
        if ($r['ok']) { set_flash('success', $r['mesaj'] . ($r['uyarilar'] ? ' Uyarı: ' . implode(' ', array_slice($r['uyarilar'], 0, 3)) : '')); header('Location: ' . $self); exit; }
        $hatalar = $r['hatalar']; $uyarilar = $r['uyarilar'];
        $postP = is_array($_POST['p'] ?? null) ? $_POST['p'] : []; $postB = is_array($_POST['b'] ?? null) ? $_POST['b'] : [];
    } elseif ($eylem === 'taslak') {
        $r = frgp_taslak_olustur($pdo, $s, ($_POST['uzerine'] ?? '') === '1', $uid);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? $r['mesaj'] : implode(' ', $r['hatalar']));
        header('Location: ' . $self); exit;
    } elseif ($eylem === 'uret_pdf' || $eylem === 'uret_xlsx') {
        $saat = trim((string)($_POST['cikis_saati'] ?? ''));
        $r = frgp_uret($pdo, $s, $eylem === 'uret_pdf' ? 'pdf' : 'xlsx', $saat, $uid);
        if (!$r['ok']) { set_flash('error', implode(' ', $r['hatalar'])); header('Location: ' . $self . ($saat !== '' ? '&saat=' . rawurlencode($saat) : '')); exit; }
        while (ob_get_level() > 0) { ob_end_clean(); }
        session_write_close();
        $ascii = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $r['ad']), '_');
        header('Content-Type: ' . $r['tip']);
        header('Content-Disposition: attachment; filename="' . $ascii . '"');
        header('Content-Length: ' . strlen($r['veri']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $r['veri'];
        exit;
    } else {
        $hatalar[] = 'Bilinmeyen işlem.';
    }
}

$duz = $yazar && frgp_duzenlenebilir($s);
$ro = $duz ? '' : ' readonly';
$satirlar = $postP !== null ? array_values($postP) : $s['paletler'];
if ($postP === null) foreach ($satirlar as &$sr) { $sr['brut_kg'] = frgp_kg_alan($sr['brut_kg']); $sr['net_kg'] = frgp_kg_alan($sr['net_kg']); } unset($sr);
$bv = static fn(string $k): string => $postB !== null ? (string)($postB[$k] ?? '') : (string)($s[$k] ?? '');
$tp = frgp_toplam($postP !== null ? array_map(static fn($r) => ['kutu' => (frgp_kutu_coz((string)($r['kutu'] ?? '')) ?: null), 'brut_kg' => (frgp_kg_coz((string)($r['brut_kg'] ?? '')) ?: null), 'net_kg' => (frgp_kg_coz((string)($r['net_kg'] ?? '')) ?: null), 'palet_no' => $r['palet_no'] ?? null], $satirlar) : $s['paletler']);
$saatVarsayilan = (string)($_GET['saat'] ?? '');
$durumAd = frg_durumlar()[$s['durum']] ?? (string)$s['durum'];
$alanlar = [['parti_no', 'Parti no', 'text', 30], ['plaka', 'Plaka (lorry)', 'text', 80], ['nakliyeci', 'Nakliyeci', 'text', 150], ['logger_id', 'ID logger', 'text', 60],
    ['sofor_adi', 'Şoför', 'text', 100], ['sofor_telefon', 'Şoför telefonu', 'tel', 40], ['sicaklik', 'Sıcaklık', 'text', 30], ['bolge', 'Bölge', 'text', 100]];

$alan = static function (string $ad, array $r, string $sinif, string $tip = 'text', string $inputmode = '', int $max = 100) use ($ro): string {
    return '<input type="' . $tip . '" name="p[__I__][' . $ad . ']" class="' . $sinif . '" value="' . h((string)($r[$ad] ?? '')) . '" maxlength="' . $max . '" autocomplete="off"'
        . ($inputmode !== '' ? ' inputmode="' . $inputmode . '"' : '') . ' data-frp-alan="' . $ad . '"' . $ro . '>';
};
$satirHtml = static function (array $r, int $i) use ($alan, $duz): string {
    $h = '<tr data-frp-satir><td class="frp-no" data-frp-sira>' . ($i + 1) . '</td>'
        . '<td>' . $alan('palet_no', $r, 'frp-in frp-palet', 'text', '', 10) . '<input type="hidden" name="p[__I__][konum]" value="' . h((string)($r['konum'] ?? '')) . '"></td>'
        . '<td>' . $alan('ggn_no', $r, 'frp-in frp-ggn', 'text', 'numeric', 20) . '</td>'
        . '<td>' . $alan('ggn_adi', $r, 'frp-in frp-ad', 'text', '', 100) . '</td>'
        . '<td>' . $alan('kalibre', $r, 'frp-in frp-kal', 'text', '', 20) . '</td>'
        . '<td>' . $alan('lot', $r, 'frp-in frp-lot', 'text', '', 30) . '</td>'
        . '<td>' . $alan('kutu', $r, 'frp-in frp-sayi', 'text', 'numeric', 9) . '</td>'
        . '<td>' . $alan('brut_kg', $r, 'frp-in frp-sayi', 'text', 'decimal', 12) . '</td>'
        . '<td class="frp-netkap">' . $alan('net_kg', $r, 'frp-in frp-sayi', 'text', 'decimal', 12) . ($duz ? '<button type="button" class="btn btn-sm frp-oner" data-frp-oner title="Net öner: brüt − (kutu × karton kg + palet diğer kg)">≈</button>' : '') . '</td>'
        . ($duz ? '<td><button type="button" class="btn btn-sm" data-frp-sil aria-label="Satırı sil">✕</button></td>' : '')
        . '</tr>';
    return $h;
};
$tpl = ['palet_no' => '', 'ggn_no' => '', 'ggn_adi' => '', 'kalibre' => (string)(frgp_kalibre_coz($s['boy'] ?? null) ?? ''), 'lot' => (string)$s['po_no'], 'kutu' => '', 'brut_kg' => '', 'net_kg' => '', 'konum' => ''];
$assetV = static fn(string $f): int => (int)@filemtime(__DIR__ . '/assets/' . $f);

render_header('Frugro PKL · ' . $s['po_no']);
echo '<link rel="stylesheet" href="assets/frugro_pkl.css?v=' . $assetV('frugro_pkl.css') . '">' . "\n";
render_flash();
?>
<div class="sip-mod frp-sayfa">
  <div class="page-head">
    <div><h1>📦 Packing List · <?= h($s['po_no']) ?></h1>
      <p class="muted"><?= h((string)($s['son_musteri'] ?? '')) ?> · <?= h(frgp_urun_ad($s['urun'] ?? null)) ?> · çıkış <?= h(frgp_tarih_goster($s['cikis_tarihi'] ?? null) ?: '—') ?> · durum <strong><?= h($durumAd) ?></strong></p></div>
    <div class="page-head-actions"><a class="btn btn-lg" href="siparisler.php">← Siparişler</a></div>
  </div>
  <?php if (!$yazar): ?><p class="sip-bilgi" role="note">Salt okunur — düzenleme için sipariş yazma yetkisi gerekir.</p>
  <?php elseif (!frgp_duzenlenebilir($s)): ?><p class="sip-bilgi" role="note">Bu durumda (<?= h($durumAd) ?>) PKL kilitli: net/brüt tüm evraklarda aynı kalmalı. Dosyayı yine üretebilirsiniz.</p><?php endif; ?>
  <?php if ($hatalar): ?><div class="flash flash-error" role="alert"><strong>Kaydedilmedi:</strong><ul class="frp-hata"><?php foreach ($hatalar as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($uyarilar && !$hatalar): ?><div class="sip-bilgi" role="note"><?php foreach ($uyarilar as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>

  <form method="post" action="<?= h($self) ?>" id="frpForm" data-frp-form data-frp-duz="<?= $duz ? '1' : '0' ?>">
    <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="eylem" value="kaydet">
    <section class="card frp-baslik">
      <h2>Başlık</h2>
      <div class="frp-grid">
        <?php foreach ($alanlar as [$k, $et, $tip, $uz]): ?>
          <label><?= h($et) ?><input type="<?= $tip ?>" name="b[<?= $k ?>]" value="<?= h($bv($k)) ?>" maxlength="<?= $uz ?>" autocomplete="off"<?= $k === 'sofor_telefon' ? ' inputmode="tel"' : '' ?><?= $ro ?>></label>
        <?php endforeach; ?>
        <label>Karton kg <small>(virgül: 0,312)</small><input type="text" name="b[karton_kg]" value="<?= h($postB !== null ? (string)($postB['karton_kg'] ?? '') : frgp_kucuk_goster($s['karton_kg'] ?? null)) ?>" inputmode="decimal" maxlength="7" autocomplete="off" data-frp-karton<?= $ro ?>></label>
        <label>Palet diğer kg <small>(örn. 25)</small><input type="text" name="b[palet_diger_kg]" value="<?= h($postB !== null ? (string)($postB['palet_diger_kg'] ?? '') : frgp_kucuk_goster($s['palet_diger_kg'] ?? null)) ?>" inputmode="decimal" maxlength="7" autocomplete="off" data-frp-pdiger<?= $ro ?>></label>
      </div>
    </section>

    <section class="card frp-paletler">
      <div class="frp-ust">
        <h2>Palet satırları</h2>
        <?php if ($duz): ?><div class="frp-araclar">
          <button type="button" class="btn" data-frp-ekle>➕ Satır</button>
          <button type="button" class="btn" data-frp-ac="frpYapistirDlg">📋 Excelden yapıştır</button>
          <button type="button" class="btn" data-frp-tum-oner>≈ Boş netleri öner</button>
        </div><?php endif; ?>
      </div>
      <div class="table-wrap"><table class="data-table frp-tablo" id="frpTablo">
        <thead><tr><th>#</th><th>Palet no</th><th>GGN no</th><th>GGN adı</th><th>Kalibre</th><th>Lot (PO)</th><th class="num">Kutu</th><th class="num">Brüt kg</th><th class="num">Net kg</th><?php if ($duz): ?><th></th><?php endif; ?></tr></thead>
        <tbody data-frp-govde>
          <?php foreach ($satirlar as $i => $r) echo str_replace('__I__', (string)$i, $satirHtml($r, $i)); ?>
        </tbody>
        <tfoot><tr class="frp-toplam"><td colspan="6"><strong>Toplam</strong> · <span data-frp-n><?= (int)$tp['palet'] ?></span> palet</td>
          <td class="num"><strong data-frp-tkutu><?= h(frgp_kg_goster($tp['kutu'])) ?></strong></td>
          <td class="num"><strong data-frp-tbrut><?= h(frgp_kg_goster($tp['brut'])) ?></strong></td>
          <td class="num"><strong data-frp-tnet><?= h(frgp_kg_goster($tp['net'])) ?></strong></td><?php if ($duz): ?><td></td><?php endif; ?></tr></tfoot>
      </table></div>
      <p class="muted frp-not">Kg: ondalık için VİRGÜL (634,5); nokta binliktir (1.234 = 1234). Net elle girilir; "≈" yalnız öneridir. Toplamlar kayıtta sunucuda yeniden hesaplanır.</p>
      <?php if ($duz): ?><div class="frp-kaydet"><button type="submit" class="btn btn-primary btn-lg">Kaydet</button> <span class="muted" data-frp-kirli hidden>Kaydedilmemiş değişiklik var.</span></div><?php endif; ?>
    </section>
    <template id="frpSatirSablon"><?= $satirHtml($tpl, 0) ?></template>
  </form>

  <section class="card frp-uret">
    <h2>Dosya üret</h2>
    <form method="post" action="<?= h($self) ?>" data-frp-uret>
      <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>">
      <label>Çıkış saati <small>(SS:DD, kayda girmez)</small><input type="text" name="cikis_saati" value="<?= h($saatVarsayilan) ?>" placeholder="18:00" inputmode="numeric" maxlength="5" autocomplete="off"></label>
      <div class="frp-uret-btn">
        <?php if ($yazar): ?><button type="submit" name="eylem" value="uret_pdf" class="btn btn-primary" data-frp-uretbtn>📄 PDF indir</button>
        <button type="submit" name="eylem" value="uret_xlsx" class="btn" data-frp-uretbtn>📊 Excel indir</button><?php endif; ?>
      </div>
      <p class="muted">Dosya her seferinde kayıtlı veriden taze üretilir: <code><?= h(frgp_dosya_adi($s, 'pdf')) ?></code>. Önce kaydedin.</p>
    </form>
    <?php if ($yazar && frgp_duzenlenebilir($s)): ?>
    <form method="post" action="<?= h($self) ?>" class="frp-taslak" data-frp-taslak>
      <?= sipui_csrf() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="eylem" value="taslak">
      <button type="submit" class="btn">Siparişten boş palet satırları oluştur</button>
      <?php if ($s['paletler']): ?><label class="frp-onay"><input type="checkbox" name="uzerine" value="1"> mevcut satırların üzerine yaz</label><?php endif; ?>
    </form><?php endif; ?>
  </section>

  <?php if ($duz): ?>
  <dialog id="frpYapistirDlg" class="pm-dialog frp-dialog" aria-labelledby="frpYapistirBaslik">
    <form method="dialog" data-frp-yapistir-form>
      <div class="pm-header"><h2 class="pm-title" id="frpYapistirBaslik">Excelden yapıştır</h2><button type="button" class="pm-close" data-frp-kapat aria-label="Kapat">✕</button></div>
      <div class="pm-body">
        <p class="muted">Excel'de hücreleri kopyalayıp aşağıya yapıştırın. Sütunlar: <strong>brüt</strong> · <strong>brüt | net</strong> · ya da <strong>palet no | GGN no | GGN adı | kutu | brüt | net</strong>. Tek hata varsa hiçbir satır uygulanmaz.</p>
        <textarea rows="10" data-frp-yapistir-metin aria-label="Yapıştırılacak veri" autocomplete="off"></textarea>
        <ul class="frp-hata" data-frp-yapistir-hata hidden></ul>
      </div>
      <div class="pm-footer"><button type="button" class="btn" data-frp-kapat>Vazgeç</button><button type="button" class="btn btn-primary" data-frp-yapistir-uygula>Uygula</button></div>
    </form>
  </dialog>
  <?php endif; ?>

  <section class="card frp-olay">
    <h2>Geçmiş</h2>
    <?php $ol = frg_olaylar($pdo, $id, 15); if (!$ol): ?><p class="muted">Kayıt yok.</p><?php endif; ?>
    <ul><?php foreach ($ol as $o): ?><li><span class="muted"><?= h(sipui_zaman((string)$o['created_at'])) ?></span> · <?= h((string)$o['tur']) ?> — <?= h((string)$o['metin']) ?></li><?php endforeach; ?></ul>
  </section>
</div>
<script src="assets/frugro_pkl.js?v=<?= $assetV('frugro_pkl.js') ?>" defer></script>
<?php render_footer();
