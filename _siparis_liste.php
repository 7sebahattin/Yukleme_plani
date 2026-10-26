<?php
// =========================================================
// _siparis_liste.php — Sevkiyat listesi gövdesi (siparisler.php partial; bölüm başına bir kez include edilir)
//
// Masaüstü tablosu + mobil kart satır biçimi TEK yerde. Bu partial fonksiyon TANIMLAMAZ.
// Beklenen: $sec_rows (siparis_sevkiyatlar satırları; siparisler.php'nin $cols kolon kümesi)
// v395: Alıcı ülke sütunu (PO grubu ile Yükleme arası) — siparis_yazdir_ulke() siparisler.php'de yüklü (fonksiyon TANIMLANMAZ).
// v394: ⋮ menüsünde 💬 WP Paylaş — kart verisi tıkta siparis_paylas.php'den gelir; bu partial'a ek kolon/veri GİRMEZ.
// =========================================================
if (!isset($sec_rows) || !is_array($sec_rows)) { http_response_code(404); exit; }
// $sec_kalem: sevkiyat id → siparis_kalemler satırları; $sec_parti: sevkiyat id → ['parti','fazla'] (ikisi de isteğe bağlı)
$sec_kalem = isset($sec_kalem) && is_array($sec_kalem) ? $sec_kalem : [];
$sec_parti = isset($sec_parti) && is_array($sec_parti) ? $sec_parti : [];
// $sec_pkl (v396): sevkiyat id → ['ad','belge_id'] — güncel PKL dosya adı, parti no'nun ÜSTÜNDE (isteğe bağlı)
$sec_pkl = isset($sec_pkl) && is_array($sec_pkl) ? $sec_pkl : [];
?>
<div class="table-wrap pc-only">
  <table class="data-table sip-table">
    <thead>
      <tr>
        <th>Sipariş</th><th>PO grubu</th><th>Alıcı ülke</th><th>Yükleme</th><th>Lojistik</th><th>Yurt içi gümrük</th>
        <th class="num">Palet/Kasa</th><th>Belgeler</th><th>Durum ve ilerleme</th><th class="actions-col"><span class="sip-sr">İşlemler</span></th>
      </tr>
    </thead>
    <tbody>
    <?php $__sira = 0; foreach ($sec_rows as $r):
        $__id = (int)$r['id'];
        $__z  = ' sip-grup sip-z' . ($__sira++ % 2);   // Excel gibi dönüşümlü bant: sevkiyat satırı + ürün alt satırı AYNI renk
        $__uy = (int)$r['acik_catisma'] + (int)$r['uyari_sayisi'];
        $__ur = sipui_kalem_html($sec_kalem[$__id] ?? []);   // ürün / palet / PF — satırın altında tam genişlikte alt satır (sütun 1280px'e sığmıyor)
        $__poEt = $r['cw_hafta'] !== null ? 'CW' . (int)$r['cw_hafta'] . ' · ' . ((string)$r['alici_adi'] !== '' ? (string)$r['alici_adi'] : 'PO #' . (int)$r['po_belge_id']) : 'PO #' . (int)$r['po_belge_id'];
    ?>
      <tr class="row-link<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?><?= $__ur !== '' ? ' sip-ust' : '' ?>" data-href="siparis_view.php?id=<?= $__id ?>">
        <td>
          <?= sipui_pkl_ad_html($r, $sec_pkl) ?>
          <?= sipui_parti_rozet_html($r, $sec_parti) ?>
          <a class="sip-ref" href="siparis_view.php?id=<?= $__id ?>"><?= h((string)$r['order_ref']) ?></a>
          <?= sipui_tur_html($r, $sec_parti) ?>
          <?php if ($__uy > 0): ?>
            <span class="sip-uy-kap"><a class="sip-uy-say<?= (int)$r['acik_catisma'] > 0 ? ' sip-uy-say--catisma' : '' ?>" href="siparis_view.php?id=<?= $__id ?>#<?= (int)$r['acik_catisma'] > 0 ? 'catismalar' : 'uyarilar' ?>" data-sip-balon
               aria-label="<?= (int)$r['acik_catisma'] ?> açık çatışma, <?= (int)$r['uyari_sayisi'] ?> uyarı">⚠ <?= $__uy ?> <?= (int)$r['acik_catisma'] > 0 ? 'çatışma' : 'uyarı' ?></a><?= sipui_uyari_balon_html($r, $sec_uy ?? []) ?></span>
          <?php endif; ?>
          <?php if (!empty($r['eksik_okuma'])): ?><a class="sip-eksik" href="siparis_view.php?id=<?= $__id ?>#uyarilar" title="PO eksik okunmuş olabilir — kalemleri kontrol edin">⛔ PO eksik okunmuş</a><?php endif; ?>
          <?= sipui_gorulen_satir($r) ?><?= sipui_degisim_html($r, $sec_dg ?? []) ?>
        </td>
        <td>
          <?php if ($r['po_belge_id']): ?>
            <a class="sip-po" href="<?= h(siparis_url(['po' => (int)$r['po_belge_id'], 'durum' => null, 'acik_page' => null, 'evrak_page' => null, 'kapali_page' => null])) ?>" title="Aynı PO'daki sevkiyatları göster"><?= h($__poEt) ?></a>
          <?php else: ?><span class="sip-po sip-po--yok" title="Bu sevkiyat için PO yüklenmedi">PO yok</span><?php endif; ?>
        </td>
        <td class="sip-ulke-hucre"><?php $__ul = siparis_yazdir_ulke($r['alici_kodu'] ?? null, $r['alici_adi'] ?? null); if ($__ul !== ''): ?><span class="sip-ulke"><?= h($__ul) ?></span><?php endif; ?></td>
        <td class="nowrap"><?= sipui_tarih_html($r['yukleme_tarihi']) ?></td>
        <td>
          <?= sipui_arac($r) ?>
          <?= sipui_navlun_html($r) ?>
          <?php if ((string)$r['nakliyeci'] !== ''): ?><div class="sip-soluk sip-kes"><?= h((string)$r['nakliyeci']) ?></div>
          <?php elseif ((string)$r['planlanan_nakliyeci'] !== ''): ?><div class="sip-soluk sip-kes" title="Planlanan nakliyeci"><?= h((string)$r['planlanan_nakliyeci']) ?></div><?php endif; ?>
          <?php $__tel = trim((string)($r['sofor_telefon'] ?? '')); if ($__tel !== ''): ?><div class="sip-soluk sip-kes sip-sofor-tel" title="Şoför telefonu">📞 <?= h($__tel) ?></div><?php endif; ?>
        </td>
        <td class="sip-gumruk-hucre"><?php $__gm = trim((string)($r['yurtici_gumruk'] ?? '')); if ($__gm !== ''): ?><span class="sip-gumruk" title="<?= h($__gm) ?>"><?= h($__gm) ?></span><?php endif; ?></td>
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
              <button type="button" data-sip-wp="<?= $__id ?>" title="WhatsApp için sevkiyat kartı (görsel)">💬 WP Paylaş</button>
            </div>
          </div>
        </td>
      </tr>
      <?php if ($__ur !== ''): ?>
      <tr class="row-link sip-urun-tr<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>" data-href="siparis_view.php?id=<?= $__id ?>">
        <td colspan="10"><?= $__ur ?></td>
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
      $__uy = (int)$r['acik_catisma'] + (int)$r['uyari_sayisi'];
  ?>
    <article class="sip-card<?= $__z ?><?= $r['durum'] === 'iptal' ? ' sip-iptal' : '' ?>">
      <?php $__et = sipui_pkl_ad_html($r, $sec_pkl) . sipui_parti_rozet_html($r, $sec_parti); if ($__et !== ''): ?><div class="sip-card-etiket"><?= $__et ?></div><?php endif; ?>
      <div class="sip-card-ust">
        <a class="sip-ref sip-card-link" href="siparis_view.php?id=<?= $__id ?>"><?= h((string)$r['order_ref']) ?><span class="sip-sr"> sevkiyatını aç</span></a>
        <?= siparis_durum_badge((string)$r['durum']) ?>
      </div>
      <div class="sip-card-orta">
        <?php
          $__parca = [];
          if ((string)$r['alici_adi'] !== '') $__parca[] = h((string)$r['alici_adi']);
          if ($r['yukleme_tarihi']) $__parca[] = sipui_tarih_html($r['yukleme_tarihi']);
          $__arac = sipui_arac($r);
          if ($__arac !== '—') $__parca[] = $__arac;
          echo $__parca ? implode(' · ', $__parca) : '<span class="sip-soluk">Alıcı / tarih / araç bilgisi bekleniyor</span>';
        ?>
        <?= sipui_tur_html($r, $sec_parti, false) ?>
        <?= sipui_navlun_html($r) ?>
        <?php $__tel = trim((string)($r['sofor_telefon'] ?? '')); if ($__tel !== ''): ?><div class="sip-soluk sip-sofor-tel">Şoför tel: <?= h($__tel) ?></div><?php endif; ?>
        <?php $__ul = siparis_yazdir_ulke($r['alici_kodu'] ?? null, $r['alici_adi'] ?? null); if ($__ul !== ''): ?><div class="sip-ulke sip-ulke--kart">Alıcı ülke: <?= h($__ul) ?></div><?php endif; ?>
        <?php $__gm = trim((string)($r['yurtici_gumruk'] ?? '')); if ($__gm !== ''): ?><div class="sip-gumruk sip-gumruk--kart">Yurt içi gümrük: <?= h($__gm) ?></div><?php endif; ?>
      </div>
      <?php $__ur = sipui_kalem_html($sec_kalem[$__id] ?? []); if ($__ur !== ''): ?><div class="sip-card-urun"><?= $__ur ?></div><?php endif; ?>
      <div class="sip-card-alt">
        <?= sipui_dok_rozetleri($r) ?>
        <span class="sip-card-miktar"><?= h(sipui_sayi($r['toplam_palet'])) ?> palet · <?= h(sipui_sayi($r['toplam_koli'])) ?> kasa</span>
        <?php if ($__uy > 0): ?><span class="sip-uy-say<?= (int)$r['acik_catisma'] > 0 ? ' sip-uy-say--catisma' : '' ?>">⚠ <?= $__uy ?></span><?php endif; ?>
        <?php if (!empty($r['eksik_okuma'])): ?><span class="sip-eksik">⛔ PO eksik okunmuş</span><?php endif; ?>
      </div>
      <?= sipui_ilerleme_html($r) ?>
      <?= sipui_gorulen_satir($r) ?><?= sipui_degisim_html($r, $sec_dg ?? []) ?>
      <div class="pc-kebab-wrap sip-card-kebab">
        <button class="pc-kebab" type="button" title="İşlemler" aria-label="<?= h((string)$r['order_ref']) ?> işlemleri">⋮</button>
        <div class="pc-dropdown" hidden>
          <a href="siparis_view.php?id=<?= $__id ?>">📂 Aç</a>
          <a href="siparis_zip.php?id=<?= $__id ?>">🗜 Evrak ZIP</a>
          <a href="siparis_pkl.php?id=<?= $__id ?>">📄 PKL</a>
          <button type="button" data-sip-wp="<?= $__id ?>" title="WhatsApp için sevkiyat kartı (görsel)">💬 WP Paylaş</button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</div>
