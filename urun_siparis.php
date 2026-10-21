<?php
// =========================================================
// urun_siparis.php — Ürün Siparişleri: tek satırlık liste + giriş/düzeltme penceresi (v409)
//
// Liste burada render edilir (şoför adı/telefonu HTML'e GİRMEZ); satıra tıklanınca sipariş
// urun_siparis_islem.php'den taze çekilip pencerede açılır. Yazdır / PDF / WP resmi OGL Siparişler ile
// aynı betiği kullanır (assets/siparis_yazdir.js + urun_siparis_yazdir.php).
// Yazan uç: urun_siparis_islem.php. Gösterim: siparis.read · kayıt: siparis.write · silme: siparis.delete.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/urun_siparis.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = usip_hazir($pdo);

render_header('Ürün Siparişleri');
echo '<link rel="stylesheet" href="assets/usip.css?v=' . (int)@filemtime(__DIR__ . '/assets/usip.css') . '">' . "\n";
render_flash();
if (!$hazir) {
    echo '<div class="usip"><div class="card usip-bos"><h2>Ürün siparişleri henüz kurulmadı</h2><p class="muted">'
        . (is_admin() ? 'Şema Migrasyon Paneli › <a href="migrate.php#urun-siparis">Ürün siparişleri</a> kartındaki düğmeyle tablolar oluşturulur.' : 'Yönetici şema kurulumunu tamamlayınca bu sayfa açılır.')
        . '</p></div></div>';
    render_footer();
    exit;
}

$yaz = can_siparis('write');
$sil = can_siparis('delete');
$F = usip_filtre($_GET);
$q = $F['q'];
$L = usip_liste($pdo, $F, max(1, (int)($_GET['sayfa'] ?? 1)));
$ref = usip_referanslar($pdo);
$bugun = date('Y-m-d');

$tarihHtml = function (string $t) use ($bugun): string {
    if ($t === '') return '';
    $sinif = $t < $bugun ? 'usip-tar--gecmis' : ($t === $bugun ? 'usip-tar--bugun' : 'usip-tar--gelecek');
    return '<span class="usip-tar ' . $sinif . '">' . h(date('d.m.Y', strtotime($t))) . '</span>';
};
$kalHtml = function (array $kal): string {
    $o = '';
    foreach ($kal as $k) $o .= '<span class="usip-chip">' . h($k['kalibre']) . ' PLT <b>' . h(usip_tam($k['palet'])) . '</b></span>';
    return $o;
};
$sayfaUrl = function (int $s) use ($q): string {
    $p = [];
    if ($q !== '') $p['q'] = $q;
    if ($s > 1) $p['sayfa'] = $s;
    return 'urun_siparis.php' . ($p ? '?' . http_build_query($p) : '');
};
$yazdirUrl = h('urun_siparis_yazdir.php' . ($q !== '' ? '?' . http_build_query(['q' => $q]) : ''));
$T = $L['toplam'];
?>
<div class="usip" id="usip" data-csrf="<?= h(csrf_token()) ?>" data-yazma="<?= $yaz ? '1' : '0' ?>" data-silme="<?= $sil ? '1' : '0' ?>">
  <div class="page-head">
    <div>
      <h1>🛒 Ürün Siparişleri</h1>
      <div class="muted">Firma bazlı ürün siparişleri; her sipariş tek satırda. Düzeltmek için satıra tıklayın.</div>
    </div>
    <div class="page-head-actions no-print">
      <a class="btn" href="siparisler.php">← OGL Siparişler</a>
      <?php if ($yaz): ?><button type="button" class="btn btn-primary" id="usipYeni">+ Yeni Sipariş</button><?php endif; ?>
    </div>
  </div>

  <div class="card usip-kart">
    <div class="usip-bas">
      <form method="get" action="urun_siparis.php" class="usip-ara no-print" role="search">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Firma, ürün, kalibre, plaka, nakliyeci ara…" aria-label="Sipariş ara">
        <button type="submit" class="btn">Ara</button>
        <?php if ($q !== ''): ?><a class="btn btn-ghost" href="urun_siparis.php">Temizle</a><?php endif; ?>
      </form>
      <span class="usip-say"><?= (int)$L['adet'] ?> sipariş</span>
      <?php if ($L['adet'] > 0): ?>
      <div class="usip-eylem no-print">
        <button type="button" class="btn btn-sm" data-sip-yazdir="yazdir" data-sip-yazdir-url="<?= $yazdirUrl ?>">🖨️ Yazdır</button>
        <button type="button" class="btn btn-sm" data-sip-yazdir="pdf" data-sip-yazdir-url="<?= $yazdirUrl ?>">📤 PDF Gönder</button>
        <button type="button" class="btn btn-sm" data-sip-yazdir="resim" data-sip-yazdir-url="<?= $yazdirUrl ?>">🖼️ WP Resim</button>
      </div>
      <?php endif; ?>
    </div>

<?php if (!$L['satirlar']): ?>
    <div class="usip-bos-satir"><?= $q !== '' ? 'Aramaya uyan sipariş yok.' : 'Henüz sipariş yok.' . ($yaz ? ' “+ Yeni Sipariş” ile ilkini girin.' : '') ?></div>
<?php else: ?>
    <div class="pc-only">
      <div class="table-wrap">
        <table class="usip-tablo">
          <thead><tr>
            <th>Teslimat</th><th>Firma</th><th>Ürün</th><th>Kalibre / Palet</th>
            <th class="num">Palet</th><th class="num">Kasa</th><th class="num">Miktar (kg)</th>
            <th class="num">Fiyat (TL/kg)</th><th class="num">Tutar (TL)</th>
          </tr></thead>
          <tbody>
          <?php foreach ($L['satirlar'] as $r): ?>
            <tr class="usip-satir" data-id="<?= (int)$r['id'] ?>" tabindex="0" role="button" aria-label="<?= h($r['firma'] . ' siparişini aç') ?>">
              <td><?= $tarihHtml((string)$r['teslimat_tarihi']) ?></td>
              <td class="usip-firma"><?= h($r['firma']) ?></td>
              <td><?= h($r['urun']) ?></td>
              <td><?= $kalHtml($r['kalibreler']) ?></td>
              <td class="num"><?= h(usip_tam($r['toplam_palet'])) ?></td>
              <td class="num"><?= h(usip_tam($r['toplam_kasa'])) ?></td>
              <td class="num"><?= h(usip_tam($r['toplam_kg'])) ?></td>
              <td class="num"><?= $r['birim_fiyat'] !== null ? h(usip_para($r['birim_fiyat'])) : '—' ?></td>
              <td class="num usip-tutar"><?= $r['birim_fiyat'] !== null ? h(usip_para($r['tutar'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr>
            <td colspan="4">Toplam · <?= (int)$L['adet'] ?> sipariş</td>
            <td class="num"><?= h(usip_tam($T['palet'])) ?></td><td class="num"><?= h(usip_tam($T['kasa'])) ?></td>
            <td class="num"><?= h(usip_tam($T['kg'])) ?></td><td></td><td class="num"><?= h(usip_para($T['tutar'])) ?></td>
          </tr></tfoot>
        </table>
      </div>
    </div>

    <div class="mobile-only usip-kartlar">
      <?php foreach ($L['satirlar'] as $r): ?>
        <div class="usip-kt" data-id="<?= (int)$r['id'] ?>" tabindex="0" role="button" aria-label="<?= h($r['firma'] . ' siparişini aç') ?>">
          <div class="usip-kt-ust"><strong><?= h($r['firma']) ?></strong><?= $tarihHtml((string)$r['teslimat_tarihi']) ?></div>
          <div class="usip-kt-urun"><?= h($r['urun']) ?></div>
          <div class="usip-kt-kal"><?= $kalHtml($r['kalibreler']) ?></div>
          <div class="usip-kt-alt">
            <span><?= h(usip_tam($r['toplam_palet'])) ?> palet · <?= h(usip_tam($r['toplam_kasa'])) ?> kasa · <?= h(usip_tam($r['toplam_kg'])) ?> kg</span>
            <strong><?= $r['birim_fiyat'] !== null ? h(usip_para($r['tutar'])) . ' TL' : 'Fiyat yok' ?></strong>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="usip-kt usip-kt-top">Toplam · <?= (int)$L['adet'] ?> sipariş · <?= h(usip_tam($T['palet'])) ?> palet · <?= h(usip_tam($T['kg'])) ?> kg · <strong><?= h(usip_para($T['tutar'])) ?> TL</strong></div>
    </div>

    <?php if ($L['sayfa_say'] > 1): ?>
    <nav class="usip-sayfa no-print" aria-label="Sayfalar">
      <?php if ($L['sayfa'] > 1): ?><a class="btn btn-sm" href="<?= h($sayfaUrl($L['sayfa'] - 1)) ?>">‹ Önceki</a><?php endif; ?>
      <span class="muted">Sayfa <?= (int)$L['sayfa'] ?> / <?= (int)$L['sayfa_say'] ?></span>
      <?php if ($L['sayfa'] < $L['sayfa_say']): ?><a class="btn btn-sm" href="<?= h($sayfaUrl($L['sayfa'] + 1)) ?>">Sonraki ›</a><?php endif; ?>
    </nav>
    <?php endif; ?>
<?php endif; ?>
  </div>

  <script type="application/json" id="usipRef"><?= json_encode($ref, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>

  <div class="pm-overlay" id="usipOvl" hidden>
    <div class="pm-dialog usip-dialog" role="dialog" aria-modal="true" aria-labelledby="usipBaslik">
      <div class="pm-header">
        <h2 class="pm-title" id="usipBaslik">Yeni Sipariş</h2>
        <button type="button" class="pm-close" id="usipX" aria-label="Kapat">✕</button>
      </div>
      <div class="pm-body">
        <div class="usip-hata" id="usipHata" role="alert" hidden></div>

        <fieldset class="usip-fs" id="usipAlanlar">
          <p class="usip-bt">Sipariş</p>
          <div class="usip-grid">
            <div class="usip-f usip-tam"><label for="f_firma">Firma</label>
              <div class="usip-combo"><input id="f_firma" maxlength="150" placeholder="Firma ara veya tam adını yazın" autocomplete="off" role="combobox" aria-expanded="false"><div class="usip-list" hidden></div></div>
              <div class="usip-not" id="n_firma"></div></div>
            <div class="usip-f"><label for="f_urun">Ürün</label>
              <div class="usip-combo"><input id="f_urun" maxlength="150" placeholder="Ürün ara (HKS) veya ekle" autocomplete="off" role="combobox" aria-expanded="false"><div class="usip-list" hidden></div></div>
              <div class="usip-not" id="n_urun"></div></div>
            <div class="usip-f"><label for="f_tarih">Teslimat Tarihi</label><input id="f_tarih" type="date"></div>
          </div>

          <p class="usip-bt usip-bt-kal"><span>Kalibre ve Palet</span><button type="button" class="btn btn-sm" id="usipKalEkle">+ Kalibre Ekle</button></p>
          <div class="usip-kal" id="usipKal"></div>

          <details class="usip-acc" id="accAmb" open>
            <summary><span class="t">Ambalaj</span><span class="o" id="o_amb"></span><span class="cv"></span></summary>
            <div class="usip-acc-b"><div class="usip-grid">
              <div class="usip-f"><label for="f_pcins">Palet Cinsi</label>
                <div class="usip-combo"><input id="f_pcins" maxlength="100" placeholder="Seçin (örn. 100x120 EPAL)" autocomplete="off" role="combobox" aria-expanded="false"><div class="usip-list" hidden></div></div></div>
              <div class="usip-f"><label for="f_kcins">Kasa Cinsi</label>
                <div class="usip-combo"><input id="f_kcins" maxlength="100" placeholder="Seçin (örn. 30x40x11 plastik)" autocomplete="off" role="combobox" aria-expanded="false"><div class="usip-list" hidden></div></div></div>
              <div class="usip-f"><label for="f_pkasa">Palet Kasa <span class="u">(adet / palet)</span></label><input id="f_pkasa" inputmode="numeric" placeholder="50"></div>
              <div class="usip-f"><label for="f_birim">Birim Ağırlık <span class="u">(kg / kasa)</span></label><input id="f_birim" inputmode="decimal" placeholder="10,0"></div>
              <div class="usip-f"><label for="f_kasa">Kasa <span class="u">(adet, hesaplanır)</span></label><input id="f_kasa" readonly tabindex="-1"></div>
            </div></div>
          </details>

          <details class="usip-acc" id="accLoj">
            <summary><span class="t">Lojistik</span><span class="o" id="o_loj"></span><span class="cv"></span></summary>
            <div class="usip-acc-b"><div class="usip-grid">
              <div class="usip-f usip-tam"><label for="l_nakliyeci">Nakliyeci</label><input id="l_nakliyeci" maxlength="150" placeholder="Nakliye firması" autocomplete="off"></div>
              <div class="usip-f"><label for="l_cekici">Çekici Plaka</label><input id="l_cekici" data-plaka maxlength="20" placeholder="Boşluksuz, büyük harf" autocomplete="off"></div>
              <div class="usip-f"><label for="l_dorse">Dorse Plaka</label><input id="l_dorse" data-plaka maxlength="20" placeholder="Boşluksuz, büyük harf" autocomplete="off"></div>
              <div class="usip-f"><label for="l_sofor">Şoför Adı</label><input id="l_sofor" maxlength="150" autocomplete="off"></div>
              <div class="usip-f"><label for="l_tel">Şoför Telefonu</label><input id="l_tel" type="tel" inputmode="tel" maxlength="40" autocomplete="off"></div>
              <div class="usip-f"><label for="l_gumruk">Yurt İçi Gümrük</label><input id="l_gumruk" maxlength="150" autocomplete="off"></div>
              <div class="usip-f"><label for="l_navlun">Navlun No</label><input id="l_navlun" maxlength="60" autocomplete="off"></div>
            </div></div>
          </details>

          <details class="usip-acc" id="accFiyat" open>
            <summary><span class="t">Fiyat</span><span class="o" id="o_fiyat"></span><span class="cv"></span></summary>
            <div class="usip-acc-b"><div class="usip-grid">
              <div class="usip-f"><label for="f_fiyat">Birim Fiyat <span class="u">(TL / kg)</span></label><input id="f_fiyat" inputmode="decimal" placeholder="28,50"></div>
              <div class="usip-f"><label for="f_tutar">Tutar <span class="u">(TL, hesaplanır)</span></label><input id="f_tutar" readonly tabindex="-1"></div>
            </div></div>
          </details>
        </fieldset>

        <div class="usip-ozet" aria-live="polite">
          <div><small>Toplam Palet</small><strong id="s_palet">0</strong></div>
          <div><small>Toplam Kasa</small><strong id="s_kasa">0</strong></div>
          <div><small>Toplam Miktar</small><strong id="s_kg">0 kg</strong></div>
          <div class="usip-ozet-t"><small>Tutar</small><strong id="s_tutar">0,00 TL</strong></div>
        </div>

        <div class="usip-f"><label for="f_aciklama">Açıklama</label><textarea id="f_aciklama" maxlength="1000" placeholder="İsteğe bağlı not"></textarea></div>
      </div>

      <div class="pm-footer usip-foot" id="usipFoot">
        <button type="button" class="btn btn-primary" id="usipKaydet">Kaydet</button>
        <span class="usip-sp"></span>
        <button type="button" class="btn usip-sil" id="usipSil" hidden>Sil</button>
        <button type="button" class="btn btn-ghost" id="usipKapat">Kapat</button>
      </div>
      <div class="pm-footer usip-foot usip-sil-onay" id="usipSilOnay" hidden>
        <span class="usip-sil-m">Bu sipariş silinsin mi?</span>
        <span class="usip-sp"></span>
        <button type="button" class="btn" id="usipSilVazgec">Vazgeç</button>
        <button type="button" class="btn usip-sil usip-sil-dolu" id="usipSilEvet">Evet, sil</button>
      </div>
    </div>
  </div>
</div>
<script src="assets/usip.js?v=<?= (int)@filemtime(__DIR__ . '/assets/usip.js') ?>" defer></script>
<script src="assets/siparis_yazdir.js?v=<?= (int)@filemtime(__DIR__ . '/assets/siparis_yazdir.js') ?>" defer></script>
<?php render_footer(); ?>
