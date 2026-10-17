<?php
// =========================================================
// _siparis_liste.php — Sevkiyat listesi gövdesi (siparisler.php partial; bölüm başına bir kez include edilir)
//
// Masaüstü tablosu + mobil kart satır biçimi TEK yerde. Bu partial fonksiyon TANIMLAMAZ.
// Beklenen: $sec_rows (siparis_sevkiyatlar satırları; siparisler.php'nin $cols kolon kümesi)
// =========================================================
if (!isset($sec_rows) || !is_array($sec_rows)) { http_response_code(404); exit; }
?>
<div class="table-wrap pc-only">
  <table class="data-table sip-table">
    <thead>
      <tr>
        <th>Sipariş</th><th>PO grubu</th><th>Yükleme</th><th>Araç</th>
        <th class="num">Palet / Koli</th><th>Belgeler</th><th>Durum ve ilerleme</th><th class="actions-col"><span class="sip-sr">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($sec_rows as $r):
        $__id = (int)$r['id'];
        $__uy = (int)$r['acik_catisma'] + (int)$r['uyari_sayisi'];
        $__poEt = $r['cw_hafta'] !== null ? 'CW' . (int)$r['cw_hafta'] . ' · ' . ((string)$r['alici_adi'] !== '' ? (string)$r['alici_adi'] : 'PO #' . (int)$r['po_belge_id']) : 'PO #' . (int)$r['po_belge_id'];
    ?>
      <tr class="row-link<?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>" data-href="siparis_view.php?id=<?= $__id ?>">
        <td>
          <a class="sip-ref" href="siparis_view.php?id=<?= $__id ?>"><?= h((string)$r['order_ref']) ?></a>
          <?php if ((string)$r['tur_no'] !== ''): ?><div class="sip-soluk nowrap">Tur <?= h((string)$r['tur_no']) ?></div><?php endif; ?>
          <?php if ($__uy > 0): ?>
            <a class="sip-uy-say<?= (int)$r['acik_catisma'] > 0 ? ' sip-uy-say--catisma' : '' ?>" href="siparis_view.php?id=<?= $__id ?>#<?= (int)$r['acik_catisma'] > 0 ? 'catismalar' : 'uyarilar' ?>"
               title="<?= (int)$r['acik_catisma'] ?> açık çatışma, <?= (int)$r['uyari_sayisi'] ?> uyarı">⚠ <?= $__uy ?> <?= (int)$r['acik_catisma'] > 0 ? 'çatışma' : 'uyarı' ?></a>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['po_belge_id']): ?>
            <a class="sip-po" href="<?= h(siparis_url(['po' => (int)$r['po_belge_id'], 'durum' => null, 'acik_page' => null, 'kapali_page' => null])) ?>" title="Aynı PO'daki sevkiyatları göster"><?= h($__poEt) ?></a>
          <?php else: ?><span class="sip-po sip-po--yok" title="Bu sevkiyat için PO yüklenmedi">PO yok</span><?php endif; ?>
        </td>
        <td class="nowrap"><?= h(sipui_tarih($r['yukleme_tarihi'])) ?></td>
        <td>
          <?= sipui_arac($r) ?>
          <?php if ((string)$r['nakliyeci'] !== ''): ?><div class="sip-soluk sip-kes"><?= h((string)$r['nakliyeci']) ?></div>
          <?php elseif ((string)$r['planlanan_nakliyeci'] !== ''): ?><div class="sip-soluk sip-kes" title="Planlanan nakliyeci"><?= h((string)$r['planlanan_nakliyeci']) ?></div><?php endif; ?>
        </td>
        <td class="num nowrap"><?= h(sipui_sayi($r['toplam_palet'])) ?> / <?= h(sipui_sayi($r['toplam_koli'])) ?></td>
        <td><?= sipui_dok_rozetleri($r) ?></td>
        <td class="sip-durum-hucre"><?= siparis_durum_badge((string)$r['durum']) ?><?= sipui_ilerleme_html($r) ?></td>
        <td class="actions-col">
          <div class="pc-kebab-wrap">
            <button class="pc-kebab" type="button" title="İşlemler" aria-label="<?= h((string)$r['order_ref']) ?> işlemleri">⋮</button>
            <div class="pc-dropdown" hidden>
              <a href="siparis_view.php?id=<?= $__id ?>">📂 Aç</a>
              <a href="siparis_zip.php?id=<?= $__id ?>">🗜 Evrak ZIP</a>
              <a href="siparis_pkl.php?id=<?= $__id ?>">📄 PKL</a>
            </div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card-list mobile-only sip-kartlar">
  <?php foreach ($sec_rows as $r):
      $__id = (int)$r['id'];
      $__uy = (int)$r['acik_catisma'] + (int)$r['uyari_sayisi'];
  ?>
    <article class="sip-card<?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>">
      <div class="sip-card-ust">
        <a class="sip-ref sip-card-link" href="siparis_view.php?id=<?= $__id ?>"><?= h((string)$r['order_ref']) ?><span class="sip-sr"> sevkiyatını aç</span></a>
        <?= siparis_durum_badge((string)$r['durum']) ?>
      </div>
      <div class="sip-card-orta">
        <?php
          $__parca = [];
          if ((string)$r['alici_adi'] !== '') $__parca[] = h((string)$r['alici_adi']);
          if ($r['yukleme_tarihi']) $__parca[] = h(sipui_tarih($r['yukleme_tarihi']));
          $__arac = sipui_arac($r);
          if ($__arac !== '—') $__parca[] = $__arac;
          echo $__parca ? implode(' · ', $__parca) : '<span class="sip-soluk">Alıcı / tarih / araç bilgisi bekleniyor</span>';
        ?>
        <?php if ((string)$r['tur_no'] !== ''): ?><span class="sip-soluk"> · Tur <?= h((string)$r['tur_no']) ?></span><?php endif; ?>
      </div>
      <div class="sip-card-alt">
        <?= sipui_dok_rozetleri($r) ?>
        <span class="sip-card-miktar"><?= h(sipui_sayi($r['toplam_palet'])) ?> palet · <?= h(sipui_sayi($r['toplam_koli'])) ?> koli</span>
        <?php if ($__uy > 0): ?><span class="sip-uy-say<?= (int)$r['acik_catisma'] > 0 ? ' sip-uy-say--catisma' : '' ?>">⚠ <?= $__uy ?></span><?php endif; ?>
      </div>
      <?= sipui_ilerleme_html($r) ?>
      <div class="pc-kebab-wrap sip-card-kebab">
        <button class="pc-kebab" type="button" title="İşlemler" aria-label="<?= h((string)$r['order_ref']) ?> işlemleri">⋮</button>
        <div class="pc-dropdown" hidden>
          <a href="siparis_view.php?id=<?= $__id ?>">📂 Aç</a>
          <a href="siparis_zip.php?id=<?= $__id ?>">🗜 Evrak ZIP</a>
          <a href="siparis_pkl.php?id=<?= $__id ?>">📄 PKL</a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
