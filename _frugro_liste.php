<?php
// =========================================================
// _frugro_liste.php — Frugro Siparişler listesi (tablo ≥1024px + mobil kartlar). Include-only; frugro_siparis.php çağırır.
// Girdi: $satirlar (frg_liste çıktısı), $bugun ('Y-m-d'). Para birimleri toplanmaz (alt toplam yalnız kutu/palet).
// =========================================================
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

$__urunBoy = static function (array $r): string {
    $p = array_filter([trim((string)($r['urun'] ?? '')), trim((string)($r['cesit'] ?? '')), trim((string)($r['boy'] ?? ''))], static fn($x) => $x !== '');
    return implode(' · ', $p);
};
$__topKutu = 0; $__topPalet = 0;
foreach ($satirlar as $__r) { $__h = frg_hesap($__r); $__topKutu += $__h['kutu']; $__topPalet += $__h['palet']; }
?>
<div class="pc-only">
  <div class="table-wrap">
    <table class="frg-tablo">
      <thead><tr>
        <th>PO no</th><th>Çıkış</th><th>Varış</th><th>Liman</th><th>Müşteri</th><th>Ürün / boy</th>
        <th class="num">Kutu</th><th class="num">Palet</th><th class="num">Tutar</th><th>Durum</th><th>Belgeler</th>
      </tr></thead>
      <tbody>
      <?php foreach ($satirlar as $r): $hs = frg_hesap($r); ?>
        <tr class="frg-satir" data-href="frugro_view.php?id=<?= (int)$r['id'] ?>" tabindex="0" role="link" aria-label="<?= h($r['po_no'] . ' siparişini aç') ?>">
          <td class="frg-po"><a href="frugro_view.php?id=<?= (int)$r['id'] ?>"><?= h($r['po_no']) ?></a></td>
          <td><?= frgui_tarih_html($r['cikis_tarihi'] ?? null, $bugun) ?></td>
          <td><?= frgui_tarih_html($r['varis_tarihi'] ?? null, $bugun) ?></td>
          <td><?= h((string)($r['giris_limani'] ?? '')) ?></td>
          <td><?= h((string)($r['son_musteri'] ?? '')) ?></td>
          <td><?= h($__urunBoy($r)) ?></td>
          <td class="num"><?= h(frgui_tam($hs['kutu'])) ?></td>
          <td class="num"><?= h(frgui_tam($hs['palet'])) ?></td>
          <td class="num frg-tutar"><?= frgui_tutar_html($r) ?></td>
          <td><?= frgui_durum_rozet((string)$r['durum']) ?></td>
          <td class="frg-belgeler"><?= frgui_belge_rozetleri($r) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="6">Toplam · <?= count($satirlar) ?> sipariş</td>
        <td class="num"><?= h(frgui_tam($__topKutu)) ?></td><td class="num"><?= h(frgui_tam($__topPalet)) ?></td><td colspan="3"></td>
      </tr></tfoot>
    </table>
  </div>
</div>

<div class="mobile-only frg-kartlar">
  <?php foreach ($satirlar as $r): $hs = frg_hesap($r); ?>
    <a class="frg-kt" href="frugro_view.php?id=<?= (int)$r['id'] ?>" aria-label="<?= h($r['po_no'] . ' siparişini aç') ?>">
      <div class="frg-kt-ust"><strong><?= h($r['po_no']) ?></strong><?= frgui_durum_rozet((string)$r['durum']) ?></div>
      <div class="frg-kt-satir"><span class="frg-soluk">Çıkış</span> <?= frgui_tarih_html($r['cikis_tarihi'] ?? null, $bugun) ?>
        <span class="frg-soluk frg-ayrac">·</span> <span class="frg-soluk">Varış</span> <?= frgui_tarih_html($r['varis_tarihi'] ?? null, $bugun) ?></div>
      <?php if (trim((string)($r['son_musteri'] ?? '')) !== '' || trim((string)($r['giris_limani'] ?? '')) !== ''): ?>
      <div class="frg-kt-satir"><?= h(implode(' · ', array_filter([(string)($r['son_musteri'] ?? ''), (string)($r['giris_limani'] ?? '')], static fn($x) => trim($x) !== ''))) ?></div>
      <?php endif; ?>
      <div class="frg-kt-satir frg-kt-urun"><?= h($__urunBoy($r)) ?></div>
      <div class="frg-kt-alt">
        <span><?= h(frgui_tam($hs['kutu'])) ?> kutu · <?= h(frgui_tam($hs['palet'])) ?> palet</span>
        <strong><?= frgui_tutar_html($r) ?></strong>
      </div>
      <div class="frg-belgeler"><?= frgui_belge_rozetleri($r) ?></div>
    </a>
  <?php endforeach; ?>
  <div class="frg-kt frg-kt-top">Toplam · <?= count($satirlar) ?> sipariş · <?= h(frgui_tam($__topKutu)) ?> kutu · <?= h(frgui_tam($__topPalet)) ?> palet</div>
</div>
