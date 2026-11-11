<?php
// =========================================================
// frugro_view.php?id= — Frugro sipariş DETAYI. Kullanım ve görünüm OGL sevkiyat sayfasının (siparis_view.php) eşi; içerik Frugro verisi.
//
// Başlık (PO + parti + durum, PKL / Invoice / Evrak ZIP, ⋮ Sil) · 7 adımlı şerit + hızlı düğmeler (sıradaki adım, İptal, ⇄ Durumu
// değiştir / geri al) · iki sütun: solda Uyarılar, Sipariş bilgileri (✎ alan bazında), Paletler, Belgeler, PKL, Invoice; sağda Kayıt + Geçmiş.
// Pencereler pm-overlay + data-sip-ac/kapat (assets/siparis.js): alan ✎, Mail gönderildi (Kime/Bilgi/Kopyala/konu/düzenlenebilir metin/
// mailto/.eml), Evrak takımı (kapanış), Durumu değiştir, İptal, Sil. Hepsi form-POST → frugro_kaydet.php (CSRF + kapı + PRG).
// Salt okunur sayfa: yazma yok. Gösterim siparis.read · yazma frgui_yetki('edit') · sil frgui_yetki('delete').
// Başka depoya ait / olmayan / silinmiş kayıt AYNI 404'ü verir. Belgeler diske yazılmaz (indirmeler taze üretim; frugro_pkl/invoice/eml/zip).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/config/frugro_detay.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$pdo   = db();
$hazir = frg_hazir($pdo);
$frgCss = static function (): void { echo '<link rel="stylesheet" href="assets/frugro_detay.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro_detay.css') . '">' . "\n"; };

if (!$hazir) {
    render_header('Frugro Sipariş');
    siparis_assets();
    echo '<div class="sip-mod"><div class="card sip-kurulmadi" role="status"><h2>Frugro Siparişler henüz kurulmadı</h2><p class="muted">'
        . (is_admin() ? 'Şema Migrasyon Paneli › <a href="migrate.php#frugro">Frugro Siparişler</a> kartı.' : 'Yönetici şema kurulumunu tamamlayınca bu sayfa açılır.')
        . '</p></div></div>';
    render_footer();
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$s  = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) {   // başka depo = olmayan kayıt (varlığı sızdırmaz)
    http_response_code(404);
    render_header('Frugro Sipariş');
    siparis_assets();
    echo '<div class="sip-mod"><div class="empty"><h2>Sipariş bulunamadı</h2><p class="muted">Kayıt bulunamadı ya da silinmiş olabilir.</p><p><a class="btn btn-primary" href="frugro_siparis.php">← Frugro Siparişler</a></p></div></div>';
    render_footer();
    exit;
}

$yaz   = frgui_yetki('edit');
$sil   = frgui_yetki('delete');
$ayarYk = can_siparis('delete');
$durum = (string)$s['durum'];
$tan   = frg_durum_tanimlari();
$kapali = in_array($durum, frg_kapali_durumlar(), true);
$iptal = $durum === 'iptal';
$sira  = (int)($tan[$durum]['sira'] ?? 1);
$ilr   = frg_ilerleme($s);
$hs    = frg_hesap($s);
$tumGecmis = (string)($_GET['gecmis'] ?? '') === 'tum';
$olayTum = frg_olaylar($pdo, $id, 500);
$olaylar = $tumGecmis ? $olayTum : array_slice($olayTum, 0, 30);
$adimZaman = frg_adim_zamanlari($pdo, $id);
$elle   = frgd_elle_alanlar($olayTum);
$uyarilar = frgd_uyarilar($s, $olayTum);
$pklOz  = frgd_pkl_ozet($s);
$invOz  = frgd_invoice_ozet($pdo, $s);
$pklHazir = $pklOz['var'];                       // mail / yola çıktı ön koşulu (frg_durum_degistir ile aynı: palet satırı var)
$sonPkl = null; foreach ($olayTum as $o) if ($o['tur'] === 'pkl_uretildi') { $sonPkl = (string)$o['created_at']; break; }
$belgeler = frgd_belgeler($s, $pklOz, $invOz, $sonPkl !== null ? ['pkl' => $sonPkl] : []);
$secenek = $yaz ? frgd_durum_secenekleri($s, $adimZaman) : [];
$kullanici = [];
try {
    $uids = array_values(array_unique(array_filter(array_map(static fn($o) => (int)($o['created_by'] ?? 0), $olaylar))));
    if ($uids) {
        $st = $pdo->prepare('SELECT id, COALESCE(display_name, username) AS ad FROM users WHERE id IN (' . implode(',', array_fill(0, count($uids), '?')) . ')');
        $st->execute($uids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $kullanici[(int)$u['id']] = (string)$u['ad'];
    }
} catch (Throwable $e) { /* ad çözülemezse yalnız ad gösterilmez */ }
$imza = (string)(($auth_user['display_name'] ?? '') ?: ($auth_user['username'] ?? ''));
$mailAyar = frgd_mail_ayar_oku($pdo);
$ayarBos = $mailAyar['kime'] === [] && $mailAyar['bilgi'] === [];
$mailAcik = $yaz && !$kapali && $durum !== 'mail_gonderildi' && $pklHazir;
$mailTaslak = $mailAcik ? frgd_mail_taslak($pdo, $s, $imza, 'pkl') : null;
$evrakTaslak = $yaz && $durum === 'mail_gonderildi' ? frgd_mail_taslak($pdo, $s, $imza, 'evrak') : null;
$pklPdfAd = frgp_dosya_adi($s, 'pdf');
$iptalNedeni = $iptal ? frgd_iptal_gerekce($olayTum) : '';
$v = static fn(string $k): string => trim((string)($s[$k] ?? ''));
$kaynakAd = ['mail' => 'Mail', 'yukleme' => 'Excel yükleme', 'elle' => 'Elle'][$v('kaynak')] ?? $v('kaynak');
$ayarNot = static fn(): string => 'Alıcı adresi tanımlı değil — ' . ($ayarYk ? '<a href="frugro_ayarlar.php#pkl-mail">Ayarlar › Mail ayarları</a>' : 'Ayarlar › Mail ayarları (yönetici)') . '. Taslak alıcısız hazırlanır.';

render_header('Frugro ' . (string)$s['po_no']);
siparis_assets();
$frgCss();
render_flash();
?>
<div class="sip-mod sip-view frg-detay">
  <?= frgui_gorulen_meta((int)$auth_user['id'], $s) ?>
  <div class="page-head sip-head">
    <div class="sip-head-sol">
      <a class="btn btn-geri" href="frugro_siparis.php">← Frugro Siparişler</a>
      <h1 class="sip-h1"><?= h((string)$s['po_no']) ?> <?php if ($v('parti_no') !== ''): ?><span class="sip-parti-btn" title="Parti numarası (elle)"><span class="sip-parti-et">Parti</span> <?= h($v('parti_no')) ?></span> <?php endif; ?><?= frgui_durum_badge($durum) ?></h1>
      <p class="sip-alt-satir muted">
        🍇 <?= h($v('son_musteri') !== '' ? $v('son_musteri') : 'Müşteri bilinmiyor') ?>
        <?php if ($v('giris_limani') !== ''): ?> · <?= h($v('giris_limani')) ?><?php endif; ?>
        <?php if ($v('son_musteri_po') !== ''): ?> · Müşteri PO <?= h($v('son_musteri_po')) ?><?php endif; ?>
        <?php if ($v('depo') !== ''): ?> · <span class="sip-depo" title="Depo"><?= h($v('depo')) ?></span><?php endif; ?>
      </p>
    </div>
    <div class="page-head-actions sip-eylemler">
      <a class="btn" href="frugro_pkl.php?id=<?= $id ?>">📄 PKL</a>
      <a class="btn" href="frugro_invoice.php?id=<?= $id ?>">🧾 Invoice</a>
      <?php if ($pklHazir): ?><a class="btn" href="frugro_zip.php?id=<?= $id ?>">🗜 Evrak takımı (ZIP)</a>
      <?php else: ?><button type="button" class="btn" disabled title="Önce Packing List palet satırları">🗜 Evrak takımı (ZIP)</button><?php endif; ?>
      <?php if ($sil): ?>
        <div class="pc-kebab-wrap">
          <button class="btn pc-kebab" type="button" title="Diğer işlemler" aria-label="Diğer işlemler">⋮</button>
          <div class="pc-dropdown" hidden><button type="button" class="pc-drop-danger" data-sip-ac="frgSilModal">🗑 Siparişi sil</button></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Durum adımları -->
  <section class="card sip-durum" id="durum" aria-label="Süreç durumu">
    <?php if ($iptal): ?>
      <div class="sip-iptal-bant" role="status">✖ İptal edildi<?= $iptalNedeni !== '' ? ' — ' . h($iptalNedeni) : '' ?></div>
    <?php endif; ?>
    <ol class="sip-steps<?= $iptal ? ' sip-steps--iptal' : '' ?>">
      <?php foreach ($tan as $dk => $dv): if ($dk === 'iptal' || $dk === 'kapali') continue;
          $bitti = !$iptal && $dv['sira'] < $sira; $suAn = !$iptal && $dv['sira'] === $sira; $sonraki = !$iptal && !$kapali && $dv['sira'] === $sira + 1;
          $zaman = $adimZaman[$dk] ?? ($dk === 'yeni' ? (string)$s['created_at'] : null); ?>
        <li class="sip-step<?= $bitti ? ' sip-step--bitti' : '' ?><?= $suAn ? ' sip-step--su-an' : '' ?><?= $sonraki ? ' sip-step--sonraki' : '' ?>"<?= $suAn ? ' aria-current="step"' : '' ?>>
          <span class="sip-step-nokta" aria-hidden="true"><?= $bitti || ($suAn && $kapali) ? '✓' : (int)$dv['sira'] ?></span>
          <span class="sip-step-et"><?= h($dv['ikon'] . ' ' . $dv['etiket']) ?></span>
          <span class="sip-sr"><?= $bitti ? ' (tamamlandı)' : ($suAn ? ' (şu anki adım)' : ($sonraki ? ' (sıradaki adım)' : '')) ?></span>
          <?php if ($zaman && ($bitti || $suAn)): ?><span class="sip-step-zaman"><?= h(sipui_zaman((string)$zaman)) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="sip-adim-mobil"><?= $iptal ? 'İptal' : 'Adım ' . (int)$ilr['adim'] . '/' . (int)$ilr['toplam'] . ' · ' . h($tan[$durum]['etiket'] ?? $durum) ?></p>
    <?php if ($yaz): ?>
    <div class="sip-hizli">
      <?php if ($durum === 'yeni'): ?>
        <form method="post" action="frugro_kaydet.php" class="sip-satir-form" data-sip-tek><?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hedef" value="hazirlik">
          <button type="submit" class="btn">🧺 Hazırlık başladı</button></form>
      <?php endif; ?>
      <?php if (in_array($durum, ['yeni', 'hazirlik'], true)): ?>
        <a class="btn btn-secondary" href="frugro_pkl.php?id=<?= $id ?>">📄 PKL hazırla</a>
      <?php elseif ($durum === 'pkl_hazir'): ?>
        <a class="btn btn-secondary" href="frugro_invoice.php?id=<?= $id ?>">🧾 Invoice hazırla</a>
      <?php endif; ?>
      <?php if (frg_gecis_izinli($durum, 'yola_cikti')): ?>
        <form method="post" action="frugro_kaydet.php" class="sip-satir-form" data-sip-tek data-sip-onay="Araç yola çıktı olarak işaretlensin mi?"><?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hedef" value="yola_cikti">
          <button type="submit" class="btn">🛣 Yola çıktı ✓</button></form>
      <?php endif; ?>
      <?php if (!$kapali && $durum !== 'mail_gonderildi'): ?>
        <?php if ($mailAcik): ?><button type="button" class="btn btn-secondary" data-sip-ac="frgMailModal">✅ Mail gönderildi</button>
        <?php else: ?><span class="sip-pasif-grup"><button type="button" class="btn" disabled aria-describedby="frgMailNeden">✅ Mail gönderildi</button><span class="sip-pasif-neden" id="frgMailNeden">Önce PKL palet satırlarını girin.</span></span><?php endif; ?>
      <?php endif; ?>
      <?php if ($durum === 'mail_gonderildi'): ?>
        <button type="button" class="btn btn-secondary" data-sip-ac="frgEvrakModal">📦 Evrak takımı gönderildi</button>
      <?php endif; ?>
      <?php if (!$kapali): ?><button type="button" class="btn btn-danger" data-sip-ac="frgIptalModal">✖ İptal</button><?php endif; ?>
      <?php if ($secenek): ?><button type="button" class="btn btn-ghost" data-sip-ac="frgDurumModal">⇄ Durumu değiştir</button><?php endif; ?>
    </div>
    <?php else: ?>
      <p class="sip-bilgi" role="note">Durumu değiştirmek için siparis.write yetkisi gerekir.</p>
    <?php endif; ?>
  </section>

  <div class="sip-grid">
    <div class="sip-col sip-col-ana">
      <!-- Sipariş bilgileri -->
      <section class="card sip-o4" id="bilgi" aria-labelledby="frgBilgiBas">
        <div class="card-head"><h2 id="frgBilgiBas">Sipariş bilgileri</h2><span class="sip-soluk">Kaynak: Order Log · ✎ ile elle düzeltilir</span></div>
        <?php if ($yaz && $kapali): ?><p class="sip-bilgi" role="note">Kapalı siparişte alanlar düzenlenemez; önce “⇄ Durumu değiştir” ile geri alın.</p><?php endif; ?>
        <?php $alanTan = frgui_duzenle_alanlari(); foreach (frgd_alan_gruplari() as $grup => $alanlar): ?>
          <h3 class="sip-grup-bas"><?= h($grup) ?></h3>
          <dl class="sip-dl">
            <?php if ($grup === 'Sipariş'): ?>
              <div class="sip-dl-satir" data-alan="po_no"><dt>PO no</dt><dd><span class="sip-dd-deger"><strong><?= h((string)$s['po_no']) ?></strong></span></dd></div>
            <?php endif; ?>
            <?php foreach ($alanlar as $alan): $gos = frgd_alan_goster($s, $alan); $tel = $alan === 'sofor_telefon' ? frgd_tel_href($gos) : ''; ?>
              <div class="sip-dl-satir" data-alan="<?= h($alan) ?>">
                <dt><?= h($alanTan[$alan][1]) ?></dt>
                <dd>
                  <span class="sip-dd-deger<?= $gos === '' ? ' sip-bos' : '' ?>"><?php if ($tel !== ''): ?><a class="sip-tel" href="tel:<?= h($tel) ?>"><?= h($gos) ?></a><?php elseif ($alan === 'cikis_tarihi' && $gos !== ''): ?><?= sipui_tarih_html((string)$s[$alan]) ?><?php else: ?><?= $gos === '' ? '—' : nl2br(h($gos)) ?><?php endif; ?></span>
                  <?php if (isset($elle[$alan])): ?><span class="sip-kaynak sip-kaynak--elle" title="Elle düzenlendi">Elle</span><?php endif; ?>
                  <?php if ($yaz && !$kapali): ?><a class="sip-edit" href="#bilgi" data-sip-alan="<?= sipui_json_attr(frgd_alan_veri($s, $alan, $id)) ?>" title="<?= h($alanTan[$alan][1]) ?> alanını düzenle">✎<span class="sip-sr"> Düzenle</span></a><?php endif; ?>
                </dd>
              </div>
            <?php endforeach; ?>
            <?php if ($grup === 'Miktar ve fiyat'): ?>
              <div class="sip-dl-satir" data-alan="tutar"><dt>Tutar</dt><dd><span class="sip-dd-deger"><strong><?= frgui_tutar_html($s) ?></strong></span> <span class="sip-soluk">kutu × fiyat<?= $hs['kutu'] ? ' · ' . h(sipui_sayi($hs['kutu'])) . ' kutu' : '' ?></span></dd></div>
            <?php endif; ?>
          </dl>
        <?php endforeach; ?>
      </section>

      <!-- Paletler / kalemler özeti -->
      <section class="card sip-o5" id="paletler" aria-labelledby="frgPalBas">
        <div class="card-head"><h2 id="frgPalBas">Paletler (kalibre bazında)</h2><span class="sip-soluk">Kaynak: Packing List palet satırları</span></div>
        <?php if (!$pklOz['var']): ?>
          <div class="empty sip-bos-kutu"><p>Henüz palet satırı yok. Sipariş: <?= h(sipui_sayi($s['palet'])) ?> palet · <?= h(sipui_sayi($hs['kutu'])) ?> kutu<?= $v('boy') !== '' ? ' · boy ' . h($v('boy')) : '' ?>.</p>
            <a class="btn btn-primary" href="frugro_pkl.php?id=<?= $id ?>">📄 <?= $yaz ? 'Palet satırlarını gir' : 'PKL\'yi görüntüle' ?></a></div>
        <?php else: $tp = $pklOz['toplam']; ?>
          <div class="table-wrap">
            <table class="data-table sip-table frg-kalibre-tablo">
              <thead><tr><th>Kalibre</th><th>Ürün · çeşit · paket</th><th class="num">Palet</th><th class="num">Kutu</th></tr></thead>
              <tbody>
              <?php foreach ($pklOz['kalibreler'] as $k => $kv): ?>
                <tr><td><strong><?= h((string)$k) ?></strong></td><td class="sip-kalem-meta"><?= h(frgp_urun_ad($s['urun'] ?? null)) ?><div class="sip-soluk"><?= h(implode(' · ', array_filter([$v('cesit'), $v('dis_paket')], static fn($x) => $x !== ''))) ?: '—' ?></div></td>
                  <td class="num"><?= h(sipui_sayi($kv['palet'])) ?></td><td class="num"><?= h(sipui_sayi($kv['kutu'])) ?></td></tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot><tr><th>Toplam</th><th class="sip-soluk">Sipariş: <?= h(sipui_sayi($s['palet'])) ?> palet · <?= h(sipui_sayi($hs['kutu'])) ?> kutu</th><th class="num"><?= h(sipui_sayi($tp['palet'])) ?></th><th class="num"><?= h(sipui_sayi($tp['kutu'])) ?></th></tr></tfoot>
            </table>
          </div>
          <?php if ($tp['kutu'] !== $hs['kutu'] && $hs['kutu'] > 0): ?><p class="sip-uyari-satir">⚠ PKL kutu toplamı (<?= h(sipui_sayi($tp['kutu'])) ?>) siparişten (<?= h(sipui_sayi($hs['kutu'])) ?>) farklı.</p><?php endif; ?>
        <?php endif; ?>
      </section>

      <!-- Belgeler -->
      <section class="card sip-o6" id="belgeler" aria-labelledby="frgBelgeBas">
        <div class="card-head">
          <h2 id="frgBelgeBas">Belgeler</h2>
          <div class="sip-zip-eylem">
            <?php if ($pklHazir): ?><a class="btn btn-sm" href="frugro_zip.php?id=<?= $id ?>">🗜 Evrak takımı (ZIP)</a>
            <?php else: ?><button type="button" class="btn btn-sm" disabled title="Önce Packing List palet satırları">🗜 Evrak takımı (ZIP)</button><?php endif; ?>
          </div>
        </div>
        <p class="sip-soluk frg-belge-not">Belgeler sunucuda saklanmaz: PKL ve Invoice her indirmede kayıtlı veriden taze üretilir. ZIP = PKL PDF + Invoice PDF.</p>
        <?php $grupOnce = ''; foreach ($belgeler as $b): if ($b['etiket'] !== $grupOnce): if ($grupOnce !== ''): ?></ul><?php endif; $grupOnce = $b['etiket']; ?>
          <h3 class="sip-grup-bas"><?= h($b['etiket']) ?></h3><ul class="sip-belgeler">
        <?php endif; ?>
          <li class="sip-belge frg-belge<?= $b['hazir'] ? '' : ' sip-belge--eski' ?>">
            <span class="sip-belge-ikon" aria-hidden="true"><?= $b['ikon'] ?></span>
            <div class="sip-belge-bilgi">
              <span class="sip-kes sip-belge-ad" title="<?= h($b['ad']) ?>"><?= h($b['ad']) ?></span>
              <span class="sip-belge-meta"><span class="sip-soluk"><?= h($b['meta']) ?></span></span>
            </div>
            <div class="sip-belge-eylem">
              <?php if ($b['tur'] === 'order_log'): ?><span class="sip-soluk" title="Gelen Excel diske kaydedilmez">saklanmaz</span>
              <?php elseif ($b['hazir']): ?>
                <?php if ($b['url_onizle'] !== ''): ?><a class="btn btn-sm" href="<?= h($b['url_onizle']) ?>" target="_blank" rel="noopener">Önizle</a><?php endif; ?>
                <a class="btn btn-sm" href="<?= h($b['url_indir']) ?>">İndir</a>
              <?php else: ?><span class="sip-soluk">hazır değil</span><?php endif; ?>
            </div>
          </li>
        <?php endforeach; if ($grupOnce !== ''): ?></ul><?php endif; ?>
      </section>

      <!-- PKL -->
      <section class="card sip-o7" id="pkl" aria-labelledby="frgPklBas">
        <div class="card-head"><h2 id="frgPklBas">Packing List (PKL)</h2>
          <span class="sip-pkl-durum sip-pkl-durum--<?= $pklOz['var'] ? ($pklOz['eksik'] ? 'taslak' : 'uretildi') : 'yok' ?><?= $pklOz['eksik'] && $pklOz['var'] ? ' sip-pkl-durum--kismi' : '' ?>"><?= $pklOz['var'] ? ($pklOz['eksik'] ? 'Eksik veri' : ($sonPkl ? 'Üretildi' : 'Hazır')) : 'Yok' ?></span>
        </div>
        <?php if ($pklOz['var']): $tp = $pklOz['toplam']; ?>
          <dl class="sip-dl sip-dl--kisa">
            <div class="sip-dl-satir"><dt>Palet satırı</dt><dd><?= h(sipui_sayi($tp['palet'])) ?> palet · <?= h(sipui_sayi($tp['kutu'])) ?> kutu</dd></div>
            <div class="sip-dl-satir"><dt>Brüt / net kg</dt><dd><?= h(sipui_sayi($tp['brut'])) ?> / <?= h(sipui_sayi($tp['net'])) ?></dd></div>
            <div class="sip-dl-satir"><dt>Son üretim</dt><dd><?= $sonPkl ? h(sipui_zaman($sonPkl)) : '<span class="sip-soluk">henüz üretilmedi (indirme her seferinde tazedir)</span>' ?></dd></div>
            <div class="sip-dl-satir"><dt>Dosya</dt><dd class="sip-kes"><?= h($pklPdfAd) ?></dd></div>
            <?php if ($v('logger_id') !== '' || $v('sicaklik') !== ''): ?><div class="sip-dl-satir"><dt>Logger · sıcaklık</dt><dd><?= h(trim($v('logger_id') . ' · ' . $v('sicaklik'), ' ·')) ?></dd></div><?php endif; ?>
          </dl>
          <?php if ($pklOz['eksik']): ?><p class="sip-uyari-satir">⚠ <?= h(implode(' ', array_slice($pklOz['eksik'], 0, 3))) ?></p><?php endif; ?>
        <?php else: ?>
          <p class="muted">Henüz Packing List hazırlanmadı.</p>
        <?php endif; ?>
        <div class="sip-pkl-eylem">
          <a class="btn btn-primary" href="frugro_pkl.php?id=<?= $id ?>"><?= $yaz ? ($pklOz['var'] ? '📄 PKL düzenle / yeniden oluştur' : '📄 PKL oluştur') : '📄 PKL\'yi görüntüle' ?></a>
          <?php if ($pklHazir && !$pklOz['eksik']): ?><a class="btn" href="frugro_pkl.php?id=<?= $id ?>&amp;indir=pdf">⬇ PDF</a><a class="btn" href="frugro_pkl.php?id=<?= $id ?>&amp;indir=xlsx">⬇ Excel</a><?php endif; ?>
        </div>
      </section>

      <!-- Invoice -->
      <section class="card sip-o7" id="invoice" aria-labelledby="frgInvBas">
        <div class="card-head"><h2 id="frgInvBas">Invoice</h2>
          <span class="sip-pkl-durum sip-inv-durum--<?= $invOz['var'] ? 'hazir' : 'yok' ?>"><?= $invOz['var'] ? 'Hazır' : ($invOz['taslak'] ? 'Taslak' : 'Yok') ?></span>
        </div>
        <?php if ($invOz['var'] || $invOz['taslak']): ?>
          <dl class="sip-dl sip-dl--kisa">
            <div class="sip-dl-satir"><dt>Invoice no</dt><dd><?= h($invOz['no'] !== '' ? $invOz['no'] : '—') ?></dd></div>
            <div class="sip-dl-satir"><dt>Tutar</dt><dd><?= $invOz['tutar'] !== '' ? h($invOz['tutar']) . ' <span class="sip-soluk">' . h($invOz['para_birimi']) . '</span>' : frgui_tutar_html($s) ?></dd></div>
            <?php if ($invOz['var']): ?><div class="sip-dl-satir"><dt>Son üretim</dt><dd><?= h(sipui_zaman($invOz['uretildi_at'])) ?></dd></div>
            <div class="sip-dl-satir"><dt>Dosya</dt><dd class="sip-kes"><?= h($invOz['dosya_adi']) ?></dd></div><?php endif; ?>
          </dl>
        <?php else: ?>
          <p class="muted"><?= $pklHazir ? 'Henüz invoice hazırlanmadı.' : 'Invoice PKL\'deki palet, kutu ve ağırlık toplamlarından kurulur.' ?></p>
        <?php endif; ?>
        <div class="sip-pkl-eylem">
          <?php if ($yaz && !$kapali): ?>
            <?php if ($pklHazir): ?><a class="btn btn-primary" href="frugro_invoice.php?id=<?= $id ?>"><?= $invOz['var'] ? '🧾 Invoice düzenle / yeniden oluştur' : '🧾 Invoice hazırla' ?></a>
            <?php else: ?><span class="sip-pasif-grup"><button type="button" class="btn btn-primary" disabled aria-describedby="frgInvNeden">🧾 Invoice hazırla</button><span class="sip-pasif-neden" id="frgInvNeden">Önce PKL palet satırlarını girin.</span></span><?php endif; ?>
          <?php else: ?><a class="btn" href="frugro_invoice.php?id=<?= $id ?>">🧾 Invoice'u görüntüle</a><?php endif; ?>
          <?php if ($invOz['var']): ?><a class="btn" href="frugro_invoice.php?id=<?= $id ?>&amp;pdf=1&amp;dl=1">⬇ PDF</a><?php endif; ?>
        </div>
      </section>
    </div>

    <div class="sip-col sip-col-yan">
      <?php if ($uyarilar): ?>
      <section class="sip-uyari sip-o2" id="uyarilar" aria-label="Uyarılar">
        <h2>⚠ Uyarılar <span class="sip-say"><?= count($uyarilar) ?></span></h2>
        <ul><?php foreach ($uyarilar as $u): ?><li><?= $u['seviye'] === 'fark' ? '<strong>Fark:</strong> ' : '' ?><?= h($u['mesaj']) ?></li><?php endforeach; ?></ul>
      </section>
      <?php endif; ?>

      <section class="card sip-o8" id="kayit" aria-labelledby="frgKayitBas">
        <div class="card-head"><h2 id="frgKayitBas">Kayıt</h2></div>
        <dl class="sip-dl sip-dl--kisa">
          <div class="sip-dl-satir"><dt>Kaynak</dt><dd><?= h($kaynakAd !== '' ? $kaynakAd : '—') ?></dd></div>
          <div class="sip-dl-satir"><dt>Depo</dt><dd><?= h($v('depo') !== '' ? $v('depo') : '—') ?></dd></div>
          <div class="sip-dl-satir"><dt>Oluşturma</dt><dd><?= h(sipui_zaman($v('created_at'))) ?></dd></div>
          <div class="sip-dl-satir"><dt>Güncelleme</dt><dd><?= h(sipui_zaman($v('updated_at'))) ?></dd></div>
          <div class="sip-dl-satir"><dt>İlerleme</dt><dd><?= $iptal ? 'İptal' : (int)$ilr['adim'] . ' / ' . (int)$ilr['toplam'] ?></dd></div>
        </dl>
      </section>

      <section class="card sip-o9" id="gecmis" aria-labelledby="frgGecmisBas">
        <div class="card-head"><h2 id="frgGecmisBas">Geçmiş</h2></div>
        <?php if (!$olaylar): ?><p class="muted">Kayıtlı olay yok.</p><?php else: ?>
        <ol class="sip-tl">
          <?php foreach ($olaylar as $o): $et = frgd_olay_etiketleri()[(string)$o['tur']] ?? ['•', '']; $ad = $kullanici[(int)($o['created_by'] ?? 0)] ?? ''; ?>
            <li>
              <span class="sip-tl-ikon" aria-hidden="true"><?= h($et[0]) ?></span>
              <div class="sip-tl-govde">
                <div><?= h(frgd_olay_cumle($o)) ?></div>
                <div class="sip-soluk"><?= h(sipui_zaman((string)$o['created_at'])) ?><?= $ad !== '' ? ' · ' . h($ad) : '' ?></div>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if (!$tumGecmis && count($olayTum) > 30): ?><p><a class="btn btn-sm" href="frugro_view.php?id=<?= $id ?>&amp;gecmis=tum#gecmis">Tümünü göster</a></p><?php endif; ?>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <?php if ($yaz): ?>
  <!-- Alan düzenle (✎) -->
  <div class="pm-overlay" id="sipAlanModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgAlanBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek data-sip-alan-form>
        <div class="pm-header"><h2 class="pm-title" id="frgAlanBas"><span data-sip-yazi="etiket">Alan</span> düzenle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="alan"><input type="hidden" name="varlik" value="frugro"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="alan" value="">
          <label><span data-sip-yazi="etiket">Değer</span>
            <input type="text" name="deger" data-sip-girdi="metin" maxlength="255" autocomplete="off">
            <input type="date" name="deger" data-sip-girdi="tarih" disabled hidden>
            <textarea name="deger" data-sip-girdi="notlar" rows="5" maxlength="1000" disabled hidden></textarea>
          </label>
          <p class="muted" data-sip-yazi="ipucu"></p>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
      </form>
    </div>
  </div>

  <?php if ($secenek): ?>
  <!-- Durumu değiştir / geri al -->
  <div class="pm-overlay" id="frgDurumModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgDurumBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek data-frg-durum-form>
        <div class="pm-header"><h2 class="pm-title" id="frgDurumBas">Durumu değiştir</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>">
          <p class="sip-soluk">Şu an: <?= frgui_durum_badge($durum) ?></p>
          <fieldset class="sip-radyo-grup"><legend>Yeni durum</legend>
            <?php foreach ($secenek as $hk => $he): $ger = $hk === 'geri' && frgd_geri_gerekce_gerekli($durum); ?>
              <label class="sip-radyo"><input type="radio" name="hedef" value="<?= h($hk) ?>" required data-frg-gerekce="<?= $ger ? '1' : '0' ?>"> <?= h($he) ?></label>
            <?php endforeach; ?>
          </fieldset>
          <p class="muted">"Mail gönderildi", "Evrak takımı" ve "İptal" için ayrı düğmeleri kullanın (onay isterler). PKL / Invoice hazır adımları belge üretilince kendiliğinden ilerler.</p>
          <label data-frg-gerekce-kap hidden>Gerekçe <span class="muted">(geri almak için zorunlu)</span>
            <textarea name="gerekce" rows="3" maxlength="500"></textarea></label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Durumu kaydet</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$kapali): ?>
  <!-- İptal -->
  <div class="pm-overlay" id="frgIptalModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgIptalBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="frgIptalBas">Siparişi iptal et</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hedef" value="iptal">
          <label>İptal gerekçesi<textarea name="gerekce" rows="3" required minlength="3" maxlength="500"></textarea></label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-danger">İptal et</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($mailTaslak !== null): ?>
  <!-- Mail gönderildi (OGL #sipMailModal eşi) -->
  <div class="pm-overlay" id="frgMailModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgMailBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek data-sip-mail-form>
        <div class="pm-header"><h2 class="pm-title" id="frgMailBas">Mail gönderildi olarak işaretle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hedef" value="mail_gonderildi">
          <p>Uygulama mail göndermez: PKL ve Invoice'u kendi mail programınızdan Frugro'ya gönderin, sonra burada işaretleyin. Sipariş “📦 Evrak takımı bekleyen” adımına geçer.</p>
          <ul class="frg-mail-ekler" aria-label="Taslağa eklenecek dosyalar">
            <li>📎 <strong class="sip-kes"><?= h($pklPdfAd) ?></strong> <a class="btn btn-sm" href="frugro_pkl.php?id=<?= $id ?>&amp;indir=pdf">⬇ İndir</a></li>
            <?php if ($invOz['var']): ?><li>📎 <strong class="sip-kes"><?= h($invOz['dosya_adi']) ?></strong> <a class="btn btn-sm" href="frugro_invoice.php?id=<?= $id ?>&amp;pdf=1&amp;dl=1">⬇ İndir</a></li>
            <?php else: ?><li class="frg-mail-uyari" role="note">⚠ Invoice henüz üretilmedi — taslağa yalnız PKL eklenir. <a href="frugro_invoice.php?id=<?= $id ?>">Invoice hazırla</a></li><?php endif; ?>
          </ul>
          <div class="sip-mail-alici">
            <?php if ($ayarBos): ?><p class="sip-soluk sip-mail-ipucu frg-mail-uyari"><?= $ayarNot() ?></p><?php endif; ?>
            <?php foreach (['Kime' => $mailTaslak['kime'], 'Bilgi' => $mailTaslak['bilgi']] as $etk => $liste): foreach ($liste as $em): ?>
              <div class="sip-mail-satir"><span class="sip-mail-rol"><?= h($etk) ?></span> <code><?= h($em) ?></code> <button type="button" class="btn btn-sm" data-sip-kopyala="<?= h($em) ?>">Kopyala</button></div>
            <?php endforeach; endforeach; ?>
            <div class="sip-mail-konu"><span class="sip-soluk">Konu:</span> <?= h($mailTaslak['konu']) ?> <button type="button" class="btn btn-sm" data-sip-kopyala="<?= h($mailTaslak['konu']) ?>">Kopyala</button></div>
            <label class="sip-mail-metin">Mail metni <span class="sip-soluk">(düzenleyebilirsiniz)</span>
              <textarea rows="6" maxlength="<?= (int)SIPARIS_MAIL_GOVDE_MAX ?>" data-sip-govde data-sip-govde-ilk="<?= h($mailTaslak['govde']) ?>" spellcheck="false"><?= h($mailTaslak['govde']) ?></textarea>
            </label>
            <div class="sip-mail-eylem">
              <a class="btn btn-sm btn-primary" href="frugro_eml.php?id=<?= $id ?>&amp;tur=pkl" data-sip-eml data-sip-govde-eml>📎 Ekli mail taslağını indir</a>
              <a class="btn btn-sm" href="<?= h(siparis_pkl_mailto($mailTaslak)) ?>" data-sip-govde-mailto>✉ Mail programını aç</a>
              <button type="button" class="btn btn-sm btn-ghost" data-sip-govde-sifirla>↺ Metni sıfırla</button>
            </div>
            <p class="sip-soluk sip-mail-ipucu">"Ekli mail taslağı" PKL PDF<?= $invOz['var'] ? ' ve Invoice PDF' : '' ?> ekli, Kime/Bilgi, konu ve metinle hazır bir taslak (.eml) indirir; açınca Outlook'ta gönderilmeye hazır gelir. "Mail programını aç" ek EKLEYEMEZ (tarayıcı kısıtı) — dosyaları elle ekleyin. Excel eklenmez.</p>
          </div>
          <label class="sip-onay-satir"><input type="checkbox" name="onay_pkl" value="1" required data-sip-mail-onay> PKL<?= $invOz['var'] ? ' ve Invoice\'u' : '\'yi' ?> Frugro'ya gönderdim</label>
          <?php if (!$invOz['var']): ?>
            <label class="sip-onay-satir"><input type="checkbox" name="onay_invoice_yok" value="1" required data-sip-mail-onay> Invoice olmadan işaretle</label>
          <?php endif; ?>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-secondary" data-sip-mail-gonder disabled>✅ Mail gönderildi</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($evrakTaslak !== null): ?>
  <!-- Evrak takımı gönderildi (kapanış adımı; OGL #sipEvrakModal eşi) -->
  <div class="pm-overlay" id="frgEvrakModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgEvrakBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek data-sip-evrak-form>
        <div class="pm-header"><h2 class="pm-title" id="frgEvrakBas">Evrak takımı gönderildi olarak işaretle</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hedef" value="evrak_gonderildi">
          <p>Uygulama evrak göndermez: asıl evrak takımını Frugro'ya kendiniz gönderin, sonra burada süreci kapatın.</p>
          <?php if (isset($adimZaman['mail_gonderildi'])): ?><p class="sip-soluk">Mail gönderildi: <?= h(sipui_zaman($adimZaman['mail_gonderildi'])) ?></p><?php endif; ?>
          <?php if ($v('parti_no') !== ''): ?><p class="sip-soluk">Parti: <?= h($v('parti_no')) ?></p><?php endif; ?>
          <div class="sip-mail-alici"><span class="sip-soluk">Evrak takımı mail adresi:</span>
            <?php if ($ayarBos): ?><p class="sip-soluk sip-mail-ipucu frg-mail-uyari"><?= $ayarNot() ?></p><?php endif; ?>
            <?php foreach (['Kime' => $evrakTaslak['kime'], 'Bilgi' => $evrakTaslak['bilgi']] as $etk => $liste): foreach ($liste as $em): ?>
              <div class="sip-mail-satir"><span class="sip-mail-rol"><?= h($etk) ?></span> <code><?= h($em) ?></code> <button type="button" class="btn btn-sm" data-sip-kopyala="<?= h($em) ?>">Kopyala</button></div>
            <?php endforeach; endforeach; ?>
            <div class="sip-mail-konu"><span class="sip-soluk">Konu:</span> <?= h($evrakTaslak['konu']) ?></div>
            <label class="sip-mail-metin">Mail metni <span class="sip-soluk">(düzenleyebilirsiniz)</span>
              <textarea rows="6" maxlength="<?= (int)SIPARIS_MAIL_GOVDE_MAX ?>" data-sip-govde data-sip-govde-ilk="<?= h($evrakTaslak['govde']) ?>" spellcheck="false"><?= h($evrakTaslak['govde']) ?></textarea>
            </label>
            <div class="sip-mail-eylem">
              <a class="btn btn-sm btn-primary" href="frugro_eml.php?id=<?= $id ?>&amp;tur=evrak" data-sip-evrak-eml data-sip-govde-eml>📧 Outlook taslağını indir</a>
              <a class="btn btn-sm" href="<?= h(siparis_pkl_mailto($evrakTaslak)) ?>" data-sip-govde-mailto>✉ Mail programını aç</a>
              <button type="button" class="btn btn-sm btn-ghost" data-sip-govde-sifirla>↺ Metni sıfırla</button>
            </div>
            <p class="sip-soluk sip-mail-ipucu">Taslak ek İÇERMEZ — evrak takımını Outlook'ta kendiniz ekleyin (gerekirse aşağıdaki ZIP).</p>
          </div>
          <div class="sip-mail-eylem"><a class="btn btn-sm" href="frugro_zip.php?id=<?= $id ?>">🗜 Evrak takımı (ZIP)</a></div>
          <label class="sip-onay-satir"><input type="checkbox" name="onay_evrak" value="1" required data-sip-evrak-onay> Evrak takımını Frugro'ya gönderdim</label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-secondary" data-sip-evrak-gonder disabled>📦 Süreci kapat</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($sil): ?>
  <!-- Sil (⋮ → pencere: iki adımlı) -->
  <div class="pm-overlay" id="frgSilModal" hidden role="dialog" aria-modal="true" aria-labelledby="frgSilBas">
    <div class="pm-dialog">
      <form method="post" action="frugro_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="frgSilBas">Siparişi sil</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $id ?>">
          <p><strong><?= h((string)$s['po_no']) ?></strong> listeden kaldırılır (yumuşak silme; aynı PO yeniden gelirse geri açılmaz).</p>
          <label class="sip-onay-satir"><input type="checkbox" name="onay" value="1" required> Bu siparişi silmek istiyorum</label>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-danger">🗑 Siparişi sil</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="assets/frugro_detay.js?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro_detay.js') ?>" defer></script>
<?php
siparis_scripts();
render_footer();
