<?php
// =========================================================
// siparis_belge.php?id= — Belge sayfası: üst veri, bağlı sevkiyatlar, denetim/uyarılar, önizleme.
// &parca=1 → yalnız önizleme parçası (masaüstü önizleme penceresi bunu çeker).
// Dosyanın kendisi siparis_dosya.php'den akar (depo + yetki orada da denetlenir).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);
$parca = ($_GET['parca'] ?? '') === '1';
if (!$hazir) {
    if ($parca) { http_response_code(503); echo '<p class="muted">Sipariş modülü henüz kurulmadı.</p>'; exit; }
    render_header('Belge');
    siparis_assets();
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM siparis_belgeler WHERE id = ? AND deleted_at IS NULL');
$st->execute([$id]);
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b) {
    if ($parca) { http_response_code(404); echo '<p class="muted">Belge bulunamadı.</p>'; exit; }
    sipui_yok_sayfasi('Belge bulunamadı');
    exit;
}
if (!siparis_belge_gorunur($pdo, $b)) forbidden('Bu belge aktif depoda görünmüyor.');
$yaz = can_siparis('write');
$sil = can_siparis('delete');

$ext  = strtolower(pathinfo((string)$b['disk_adi'], PATHINFO_EXTENSION));
$cins = siparis_dosya_uzantilari()[$ext] ?? 'bilinmiyor';
$yol  = siparis_dosya_yolu($b);
$dosyaUrl = 'siparis_dosya.php?id=' . $id;

/** Önizleme parçası (PDF iframe / xlsx tabloları / görsel). */
$onizleHtml = function (bool $pdfIframe) use ($b, $cins, $yol, $dosyaUrl, $id): string {
    if ($yol === null) return '<p class="sip-hata-metin">Dosya depoda bulunamadı.</p>';
    if ($cins === 'pdf') {
        $o = '<div class="sip-pdf-mobil' . ($pdfIframe ? ' sip-pdf-mobil--gizli' : '') . '"><p class="muted">PDF telefonda satır içi gösterilmez; yeni sekmede açın.</p>'
           . '<a class="btn btn-primary btn-lg" href="' . h($dosyaUrl) . '" target="_blank" rel="noopener">PDF\'i aç</a> '
           . '<a class="btn btn-lg" href="' . h($dosyaUrl) . '&amp;dl=1">İndir</a></div>';
        if ($pdfIframe) $o .= '<iframe class="sip-pdf" src="' . h($dosyaUrl) . '" title="' . h((string)$b['orijinal_ad']) . '" loading="lazy"></iframe>';
        return $o;
    }
    if ($cins === 'xlsx') {
        $x = siparis_xlsx_onizleme($yol, 120);
        if (!$x['sutunlar']) return '<p class="muted">Excel içeriği okunamadı. <a href="' . h($dosyaUrl) . '&amp;dl=1">İndirip</a> açın.</p>';
        $o = '';
        if ($x['baslik']) {
            $o .= '<dl class="sip-dl sip-dl--kisa">';
            foreach ($x['baslik'] as $k => $v) $o .= '<div class="sip-dl-satir"><dt>' . h((string)$k) . '</dt><dd>' . h((string)$v) . '</dd></div>';
            $o .= '</dl>';
        }
        $o .= '<div class="table-wrap"><table class="data-table small sip-table"><thead><tr>';
        foreach ($x['sutunlar'] as $c) $o .= '<th>' . h((string)$c) . '</th>';
        $o .= '</tr></thead><tbody>';
        foreach ($x['satirlar'] as $r) { $o .= '<tr>'; foreach ($x['sutunlar'] as $c) $o .= '<td>' . h((string)($r[$c] ?? '')) . '</td>'; $o .= '</tr>'; }
        $o .= '</tbody></table></div>';
        if ($x['satir_sayisi'] > count($x['satirlar'])) $o .= '<p class="muted">İlk ' . count($x['satirlar']) . ' / ' . (int)$x['satir_sayisi'] . ' satır gösteriliyor.</p>';
        return $o;
    }
    if ($cins === 'resim') return '<img class="sip-resim" src="' . h($dosyaUrl) . '" alt="' . h((string)$b['orijinal_ad']) . '">';
    return '<p class="muted">Bu dosya türü için önizleme yok.</p>';
};
if ($parca) {
    siparis_no_store();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $onizleHtml(true);
    exit;
}

// bağlı sevkiyatlar (yalnız görünür olanlar)
$bg = $pdo->prepare('SELECT s.id, s.order_ref, s.depo, bb.gecerli FROM siparis_belge_bag bb JOIN siparis_sevkiyatlar s ON s.id = bb.sevkiyat_id WHERE bb.belge_id = ? AND s.deleted_at IS NULL ORDER BY s.order_ref');
$bg->execute([$id]);
$sevler = array_values(array_filter($bg->fetchAll(PDO::FETCH_ASSOC), 'siparis_gorunur'));
$bagSayisi = count($sevler);
$pj = siparis_json_coz($b['parse_json'] ?? null);
$checks = array_values(array_filter((array)($pj['checks'] ?? []), 'is_array'));
$uyarilar = array_values(array_filter((array)($pj['warnings'] ?? []), 'is_array'));
$gecen = count(array_filter($checks, fn($c) => !empty($c['ok'])));
$yukleyen = '';
if ($b['yukleyen']) {
    $u = $pdo->prepare('SELECT COALESCE(display_name, username) FROM users WHERE id = ?');
    $u->execute([(int)$b['yukleyen']]);
    $yukleyen = (string)($u->fetchColumn() ?: '');
}
$acikSevler = [];
if ($yaz && $bagSayisi === 0) {
    [$ds, $dp] = depo_sql_column('s.depo', ':udm');
    $q = $pdo->prepare("SELECT s.id, s.order_ref, s.alici_adi FROM siparis_sevkiyatlar s WHERE s.deleted_at IS NULL AND " . siparis_kapanmamis_sql() . $ds . ' ORDER BY s.order_ref LIMIT 300');
    $q->execute($dp);
    $acikSevler = $q->fetchAll(PDO::FETCH_ASSOC);
}

render_header('Belge ' . (string)$b['orijinal_ad']);
siparis_assets();
render_flash();
?>
<div class="sip-mod sip-belge-sayfa">
  <div class="page-head">
    <div class="sip-head-sol">
      <a class="btn btn-geri" href="<?= $sevler ? 'siparis_view.php?id=' . (int)$sevler[0]['id'] . '#belgeler' : 'siparisler.php' ?>">← <?= $sevler ? h((string)$sevler[0]['order_ref']) : 'Siparişler' ?></a>
      <h1 class="sip-h1 sip-kes" title="<?= h((string)$b['orijinal_ad']) ?>"><?= h((string)$b['orijinal_ad']) ?></h1>
      <p class="sip-alt-satir muted"><?= sipui_tur_rozeti((string)$b['tur']) ?> <?= (string)$b['durum'] === 'hata' ? '<span class="sip-hata-metin">Hata</span>' : '' ?> <?= h((string)($b['ozet'] ?? '')) ?></p>
    </div>
    <div class="page-head-actions">
      <a class="btn btn-primary" href="<?= h($dosyaUrl) ?>&amp;dl=1">⬇ İndir</a>
      <?php if ($yaz): ?>
        <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="yeniden_isle"><input type="hidden" name="belge_id" value="<?= $id ?>"><input type="hidden" name="donus_belge" value="<?= $id ?>"><button type="submit" class="btn">↻ Yeniden işle</button></form>
      <?php endif; ?>
      <?php if ($sil): ?>
        <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-onay="Bu belge silinsin mi? Uygulanmış değerler geri alınmaz."><?= sipui_csrf() ?><input type="hidden" name="islem" value="belge_sil"><input type="hidden" name="belge_id" value="<?= $id ?>"><?php if ($sevler): ?><input type="hidden" name="donus_sid" value="<?= (int)$sevler[0]['id'] ?>"><?php endif; ?><button type="submit" class="btn btn-danger">Sil</button></form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((string)$b['durum'] === 'hata'): ?><div class="flash flash-error" role="alert">Bu belge işlenemedi: <?= h((string)($b['hata_mesaj'] ?? 'bilinmeyen hata')) ?></div><?php endif; ?>

  <div class="sip-belge-izgara">
    <section class="card" aria-labelledby="sipBMeta">
      <div class="card-head"><h2 id="sipBMeta">Belge bilgileri</h2></div>
      <dl class="sip-dl sip-dl--kisa">
        <div class="sip-dl-satir"><dt>Tür</dt><dd><?= sipui_tur_rozeti((string)$b['tur']) ?></dd></div>
        <div class="sip-dl-satir"><dt>Dosya adı</dt><dd class="sip-kes"><?= h((string)$b['orijinal_ad']) ?></dd></div>
        <div class="sip-dl-satir"><dt>Boyut</dt><dd><?= h(sipui_boyut((int)$b['boyut'])) ?></dd></div>
        <div class="sip-dl-satir"><dt>Yüklenme</dt><dd><?= h(sipui_zaman((string)$b['created_at'])) ?><?= $yukleyen !== '' ? ' · ' . h($yukleyen) : '' ?><?= $b['kaynak'] === 'uretim' ? ' · sistemde üretildi' : '' ?><?= $b['kaynak'] === 'mail' ? ' · <span class="sip-mail-rozet">📧 mailden alındı</span>' : '' ?></dd></div>
        <div class="sip-dl-satir"><dt>Belge tarihi</dt><dd><?= h(sipui_zaman((string)$b['belge_tarihi'])) ?></dd></div>
        <?php if ($b['cw_hafta'] !== null): ?><div class="sip-dl-satir"><dt>Hafta</dt><dd>CW<?= (int)$b['cw_hafta'] ?>/<?= (int)$b['cw_yil'] ?></dd></div><?php endif; ?>
        <div class="sip-dl-satir"><dt>Ayrıştırıcı</dt><dd><?= h((string)($b['parser_surum'] ?? '—')) ?><?= siparis_belge_surum_eski($b) ? ' <span class="sip-soluk">(eski — ↻ Yeniden işle)</span>' : '' ?></dd></div>
        <div class="sip-dl-satir"><dt>Bağlı sevkiyatlar</dt><dd>
          <?php if (!$sevler): ?><span class="sip-soluk">Hiçbirine bağlı değil</span>
          <?php else: foreach ($sevler as $i => $s): ?><?= $i ? ', ' : '' ?><a href="siparis_view.php?id=<?= (int)$s['id'] ?>"><?= h((string)$s['order_ref']) ?></a><?= (int)$s['gecerli'] === 0 ? ' <span class="sip-eski-rozet">eski sürüm</span>' : '' ?><?php endforeach; endif; ?></dd></div>
      </dl>
      <?php if ($yaz && $bagSayisi === 0): ?>
        <form method="post" action="siparis_kaydet.php" class="sip-bagla-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="belge_bagla"><input type="hidden" name="belge_id" value="<?= $id ?>"><input type="hidden" name="donus_belge" value="<?= $id ?>">
          <label>Sevkiyata bağla
            <select name="sevkiyat_id" required><option value="">Seçin…</option><?php foreach ($acikSevler as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h((string)$s['order_ref'] . ($s['alici_adi'] ? ' · ' . $s['alici_adi'] : '')) ?></option><?php endforeach; ?></select></label>
          <button type="submit" class="btn btn-primary">Bağla</button></form>
      <?php endif; ?>
    </section>

    <section class="card" aria-labelledby="sipBKontrol">
      <div class="card-head"><h2 id="sipBKontrol">Denetimler ve uyarılar</h2><?php if ($checks): ?><span class="sip-soluk"><?= $gecen ?> / <?= count($checks) ?> denetim geçti</span><?php endif; ?></div>
      <?php if (!$uyarilar && !$checks): ?><p class="muted">Bu belge için kayıtlı denetim ya da uyarı yok.</p><?php endif; ?>
      <?php if ($uyarilar): ?>
        <div class="table-wrap"><table class="data-table small sip-table"><thead><tr><th>Seviye</th><th>Kod</th><th>Açıklama</th></tr></thead><tbody>
          <?php foreach ($uyarilar as $w): $kod = (string)($w['code'] ?? ''); $sv = (string)($w['level'] ?? 'info'); ?>
            <tr><td><span class="sip-sev sip-sev--<?= h($sv) ?>"><?= h(['error' => 'Hata', 'warn' => 'Uyarı', 'info' => 'Bilgi'][$sv] ?? $sv) ?></span></td><td><code><?= h($kod) ?></code></td>
              <td><?= h(sipui_uyari_kodu_etiketi($kod) ?? (string)($w['message'] ?? '')) ?><?php if (sipui_uyari_kodu_etiketi($kod) !== null && !empty($w['message'])): ?><div class="sip-soluk"><?= h((string)$w['message']) ?></div><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
      <?php if ($checks): ?>
        <details class="sip-gelismis"><summary>Tutarlılık denetimleri (<?= count($checks) ?>)</summary>
          <ul class="sip-check-liste"><?php foreach ($checks as $c): ?><li class="<?= !empty($c['ok']) ? 'sip-check--ok' : 'sip-check--hata' ?>"><?= !empty($c['ok']) ? '✓' : '✖' ?> <?= h((string)($c['id'] ?? '')) ?> <span class="sip-soluk"><?= h((string)($c['scope'] ?? '')) ?></span><?php if (empty($c['ok'])): ?> — beklenen <?= h((string)json_encode($c['expected'] ?? null)) ?>, bulunan <?= h((string)json_encode($c['actual'] ?? null)) ?><?php endif; ?></li><?php endforeach; ?></ul>
        </details>
      <?php endif; ?>
    </section>
  </div>

  <section class="card sip-onizle-kart" aria-labelledby="sipBOnizle">
    <div class="card-head"><h2 id="sipBOnizle">Önizleme</h2></div>
    <?= $onizleHtml(true) ?>
  </section>
</div>
<?php
siparis_scripts();
render_footer();
