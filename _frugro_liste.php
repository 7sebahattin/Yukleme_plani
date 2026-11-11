<?php
// =========================================================
// _frugro_liste.php — Frugro Siparişler listesi gövdesi (frugro_siparis.php partial; bölüm başına bir kez include edilir)
//
// Masaüstü tablosu + mobil kart satır biçimi TEK yerde; OGL `_siparis_liste.php` ile AYNI işaretleme (sip-* sınıfları, assets/siparis.css). Bu partial
// fonksiyon TANIMLAMAZ ve SORGU ÇALIŞTIRMAZ (veri sayfada hazırlanır). Para birimleri toplanmaz (tfoot yok).
// Beklenen: $sec_rows (frgui_kolonlar() satırları); $sec_palet / $sec_uy / $sec_dg / $sec_adim (frg_*_haritasi çıktıları, isteğe bağlı).
// =========================================================
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
if (!isset($sec_rows) || !is_array($sec_rows)) { http_response_code(404); exit; }
$sec_palet = isset($sec_palet) && is_array($sec_palet) ? $sec_palet : [];
$sec_uy    = isset($sec_uy) && is_array($sec_uy) ? $sec_uy : [];
$sec_dg    = isset($sec_dg) && is_array($sec_dg) ? $sec_dg : [];
$sec_adim  = isset($sec_adim) && is_array($sec_adim) ? $sec_adim : [];
?>
<div class="table-wrap pc-only">
  <table class="data-table sip-table">
    <thead>
      <tr>
        <th>Sipariş</th><th>Müşteri</th><th>Çıkış</th><th>Lojistik</th><th class="num">Palet/Kutu</th><th class="num">Tutar</th>
        <th>Belgeler</th><th>Durum ve ilerleme</th><th class="actions-col"><span class="sip-sr">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
    <?php $__sira = 0; foreach ($sec_rows as $r):
        $__id = (int)$r['id'];
        $__z  = ' sip-grup sip-z' . ($__sira++ % 2);   // Excel gibi dönüşümlü bant: sipariş satırı + ürün alt satırı AYNI renk
        $__oz = $sec_palet[$__id] ?? [];
        $__ur = sipui_kalem_html(frgui_kalemler($r, $__oz));
        $__hs = frg_hesap($r);
        $__pkl = !empty($__oz);
        $__inv = trim((string)($r['fatura_no'] ?? '')) !== '' && (int)(frg_durum_tanimlari()[(string)$r['durum']]['sira'] ?? 0) >= 4;
        $__ml = isset(($sec_adim[$__id] ?? [])['mail_gonderildi']) || in_array((string)$r['durum'], ['mail_gonderildi', 'evrak_gonderildi'], true);
        $__pt = trim((string)($r['parti_no'] ?? '')) !== '' ? [$__id => ['parti' => (string)$r['parti_no'], 'liste' => [(string)$r['parti_no']]]] : [];
        $__g = $sec_uy[$__id] ?? null;
        $__uy = (int)($__g['n'] ?? 0);
    ?>
      <tr class="row-link<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?><?= $__ur !== '' ? ' sip-ust' : '' ?>" data-href="frugro_view.php?id=<?= $__id ?>">
        <td>
          <?= frgui_belge_ad_html($r, $__pkl, $__inv) ?>
          <?= sipui_parti_rozet_html($r, $__pt) ?>
          <a class="sip-ref" href="frugro_view.php?id=<?= $__id ?>"><?= h((string)$r['po_no']) ?></a>
          <?php if (trim((string)($r['son_musteri_po'] ?? '')) !== ''): ?><div class="sip-soluk nowrap sip-turparti" title="Müşteri PO">MP <?= h((string)$r['son_musteri_po']) ?></div><?php endif; ?>
          <?php if ($__uy > 0): $__fark = !empty($__g['c']); ?>
            <span class="sip-uy-kap"><a class="sip-uy-say<?= $__fark ? ' sip-uy-say--catisma' : '' ?>" href="frugro_view.php?id=<?= $__id ?>#uyarilar" data-sip-balon
               aria-label="<?= $__uy ?> <?= $__fark ? 'fark' : 'uyarı' ?>">⚠ <?= $__uy ?> <?= $__fark ? 'fark' : 'uyarı' ?></a><?= frgui_uyari_balon_html($r, $sec_uy) ?></span>
          <?php endif; ?>
          <?= sipui_gorulen_satir($r) ?><?= sipui_degisim_html($r, $sec_dg) ?>
        </td>
        <td>
          <?php if (trim((string)($r['son_musteri'] ?? '')) !== ''): ?><div class="sip-kes" title="<?= h((string)$r['son_musteri']) ?>"><?= h((string)$r['son_musteri']) ?></div><?php else: ?><span class="sip-soluk">—</span><?php endif; ?>
          <?php if (trim((string)($r['giris_limani'] ?? '')) !== ''): ?><div class="sip-soluk sip-kes" title="Giriş limanı"><?= h((string)$r['giris_limani']) ?></div><?php endif; ?>
        </td>
        <td class="nowrap"><?= sipui_tarih_html($r['cikis_tarihi'] ?? null) ?><?= frgui_varis_html($r['varis_tarihi'] ?? null) ?></td>
        <td>
          <?= sipui_arac(['plakalar' => $r['plaka'] ?? '']) ?>
          <?php if (trim((string)($r['nakliyeci'] ?? '')) !== ''): ?><div class="sip-soluk sip-kes"><?= h((string)$r['nakliyeci']) ?></div><?php endif; ?>
          <?php $__tel = trim((string)($r['sofor_telefon'] ?? '')); if ($__tel !== ''): ?><div class="sip-soluk sip-kes sip-sofor-tel" title="Şoför telefonu">📞 <?= h($__tel) ?></div><?php endif; ?>
        </td>
        <td class="num nowrap"><?= h(frgui_tam($__hs['palet'])) ?> / <?= h(frgui_tam($__hs['kutu'])) ?></td>
        <td class="num nowrap frg-tutar"><?= frgui_tutar_html($r) ?></td>
        <td><?= frgui_dok_rozetleri($r, ['pkl' => $__pkl, 'ml' => $__ml]) ?></td>
        <td class="sip-durum-hucre"><?= frgui_durum_badge((string)$r['durum']) ?><?= frgui_ilerleme_html($r) ?></td>
        <td class="actions-col">
          <div class="pc-kebab-wrap">
            <button class="pc-kebab" type="button" title="İşlemler" aria-label="<?= h((string)$r['po_no']) ?> işlemleri">⋮</button>
            <div class="pc-dropdown" hidden>
              <a href="frugro_view.php?id=<?= $__id ?>">📂 Aç</a>
              <a href="frugro_pkl.php?id=<?= $__id ?>">📄 PKL</a>
              <a href="frugro_invoice.php?id=<?= $__id ?>">🧾 Invoice</a>
              <?php if (!empty($__pkl)): ?><a href="frugro_zip.php?id=<?= $__id ?>">🗜 Evrak takımı (ZIP)</a><?php endif; ?>
              <button type="button" data-sip-wp="<?= $__id ?>" data-sip-wp-url="frugro_paylas.php" title="WhatsApp için sipariş kartı (görsel)">💬 WP Paylaş</button>
            </div>
          </div>
        </td>
      </tr>
      <?php if ($__ur !== ''): ?>
      <tr class="row-link sip-urun-tr<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>" data-href="frugro_view.php?id=<?= $__id ?>">
        <td colspan="9"><?= $__ur ?></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card-list mobile-only sip-kartlar">
  <?php $__sira = 0; foreach ($sec_rows as $r):
      $__id = (int)$r['id'];
      $__z  = ' sip-z' . ($__sira++ % 2);
      $__oz = $sec_palet[$__id] ?? [];
      $__hs = frg_hesap($r);
      $__pkl = !empty($__oz);
      $__inv = trim((string)($r['fatura_no'] ?? '')) !== '' && (int)(frg_durum_tanimlari()[(string)$r['durum']]['sira'] ?? 0) >= 4;
      $__ml = isset(($sec_adim[$__id] ?? [])['mail_gonderildi']) || in_array((string)$r['durum'], ['mail_gonderildi', 'evrak_gonderildi'], true);
      $__pt = trim((string)($r['parti_no'] ?? '')) !== '' ? [$__id => ['parti' => (string)$r['parti_no'], 'liste' => [(string)$r['parti_no']]]] : [];
      $__g = $sec_uy[$__id] ?? null;
      $__uy = (int)($__g['n'] ?? 0);
  ?>
    <article class="sip-card<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>">
      <?php $__et = frgui_belge_ad_html($r, $__pkl, $__inv) . sipui_parti_rozet_html($r, $__pt); if ($__et !== ''): ?><div class="sip-card-etiket"><?= $__et ?></div><?php endif; ?>
      <div class="sip-card-ust">
        <a class="sip-ref sip-card-link" href="frugro_view.php?id=<?= $__id ?>"><?= h((string)$r['po_no']) ?><span class="sip-sr"> siparişini aç</span></a>
        <?= frgui_durum_badge((string)$r['durum']) ?>
      </div>
      <div class="sip-card-orta">
        <?php
          $__parca = [];
          if (trim((string)($r['son_musteri'] ?? '')) !== '') $__parca[] = h((string)$r['son_musteri']);
          if (!empty($r['cikis_tarihi'])) $__parca[] = sipui_tarih_html($r['cikis_tarihi']);
          $__arac = sipui_arac(['plakalar' => $r['plaka'] ?? '']);
          if ($__arac !== '—') $__parca[] = $__arac;
          echo $__parca ? implode(' · ', $__parca) : '<span class="sip-soluk">Müşteri / tarih / araç bilgisi bekleniyor</span>';
        ?>
        <?php if (trim((string)($r['son_musteri_po'] ?? '')) !== ''): ?><span class="sip-soluk sip-turparti"> · MP <?= h((string)$r['son_musteri_po']) ?></span><?php endif; ?>
        <?php if (trim((string)($r['giris_limani'] ?? '')) !== ''): ?><div class="sip-soluk">Giriş limanı: <?= h((string)$r['giris_limani']) ?></div><?php endif; ?>
        <?php if (trim((string)($r['nakliyeci'] ?? '')) !== ''): ?><div class="sip-soluk"><?= h((string)$r['nakliyeci']) ?></div><?php endif; ?>
        <?php $__tel = trim((string)($r['sofor_telefon'] ?? '')); if ($__tel !== ''): ?><div class="sip-soluk sip-sofor-tel">Şoför tel: <?= h($__tel) ?></div><?php endif; ?>
      </div>
      <?php $__ur = sipui_kalem_html(frgui_kalemler($r, $__oz)); if ($__ur !== ''): ?><div class="sip-card-urun"><?= $__ur ?></div><?php endif; ?>
      <div class="sip-card-alt">
        <?= frgui_dok_rozetleri($r, ['pkl' => $__pkl, 'ml' => $__ml]) ?>
        <span class="sip-card-miktar"><?= h(frgui_tam($__hs['palet'])) ?> palet · <?= h(frgui_tam($__hs['kutu'])) ?> kutu</span>
        <strong class="frg-tutar"><?= frgui_tutar_html($r) ?></strong>
        <?php if ($__uy > 0): ?><span class="sip-uy-say<?= !empty($__g['c']) ? ' sip-uy-say--catisma' : '' ?>">⚠ <?= $__uy ?></span><?php endif; ?>
      </div>
      <?= frgui_ilerleme_html($r) ?>
      <?= sipui_gorulen_satir($r) ?><?= sipui_degisim_html($r, $sec_dg) ?>
      <div class="pc-kebab-wrap sip-card-kebab">
        <button class="pc-kebab" type="button" title="İşlemler" aria-label="<?= h((string)$r['po_no']) ?> işlemleri">⋮</button>
        <div class="pc-dropdown" hidden>
          <a href="frugro_view.php?id=<?= $__id ?>">📂 Aç</a>
          <a href="frugro_pkl.php?id=<?= $__id ?>">📄 PKL</a>
          <a href="frugro_invoice.php?id=<?= $__id ?>">🧾 Invoice</a>
              <?php if (!empty($__pkl)): ?><a href="frugro_zip.php?id=<?= $__id ?>">🗜 Evrak takımı (ZIP)</a><?php endif; ?>
          <button type="button" data-sip-wp="<?= $__id ?>" data-sip-wp-url="frugro_paylas.php" title="WhatsApp için sipariş kartı (görsel)">💬 WP Paylaş</button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
