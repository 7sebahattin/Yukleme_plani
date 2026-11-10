<?php
// =========================================================
// _frugro_mail_kart.php — Frugro "Mailden al" kartı (include-only partial; frugro_siparis.php içine include edilir)
//
// Beklenen değişken: $frgMailVeri = frgm_kart_veri($pdo, (int)$auth_user['id'])  (config/frugro_mail.php; sorgu orada, burada YOK).
// Kapalıysa ($frgMailVeri['goster'] false ya da değişken yoksa) hiçbir şey çizmez. CSS/JS'i kendisi ekler (assets/frugro_mail.*).
// Satır içi olay YOK: data-frgm-* öznitelikleri assets/frugro_mail.js tarafından bağlanır. Sonuç HTML'i sunucuda h() ile kaçırılmıştır.
// =========================================================
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
if (!isset($frgMailVeri) || !is_array($frgMailVeri) || empty($frgMailVeri['goster'])) return;
$frgmV = $frgMailVeri;
$frgmHazir = !empty($frgmV['hazir']) && !empty($frgmV['ayarli']);
$frgmYarim = is_array($frgmV['yarim'] ?? null) ? $frgmV['yarim'] : null;
?>
<link rel="stylesheet" href="assets/frugro_mail.css?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro_mail.css') ?>">
<section class="card frgm" id="frgMail" data-frgm-kap data-frgm-url="<?= h((string)$frgmV['url']) ?>" data-frgm-csrf="<?= h((string)$frgmV['csrf']) ?>" aria-busy="false" aria-labelledby="frgmBas">
  <div class="card-head"><h2 id="frgmBas">📧 Mailden al</h2><span class="frgm-soluk">Son <?= (int)FRGM_GUN ?> gün · Order Log Excel ekleri</span></div>
  <?php if (!$frgmHazir): ?>
    <p class="frgm-uyari" role="note"><?= h((string)($frgmV['uyari'] ?? 'Mail sorgusu kullanılamıyor.')) ?>
      <?php if (!empty($frgmV['ayar_yetkisi'])): ?> <a href="<?= h((string)$frgmV['ayar_url']) ?>">Mail ayarları</a><?php endif; ?></p>
  <?php else: ?>
    <p class="frgm-soluk">Frugro'dan gelen sipariş maillerindeki Excel (Order Log) otomatik okunur; aynı PO tekrar gelirse yeni kayıt açılmaz.
      <?php if (!empty($frgmV['ayar_yetkisi'])): ?><a href="<?= h((string)$frgmV['ayar_url']) ?>">Mail ayarları</a><?php endif; ?></p>
    <div class="frgm-dugmeler"><button type="button" class="btn btn-primary" data-frgm-baslat>📥 Mailden al</button></div>
    <div class="frgm-ilerleme" data-frgm-ilerleme hidden>
      <progress max="1" value="0"></progress>
      <span data-frgm-ilerleme-metin role="status" aria-live="polite"></span>
      <span class="frgm-ilerleme-dugme">
        <button type="button" class="btn btn-sm" data-frgm-devam-et hidden>Devam et</button>
        <button type="button" class="btn btn-sm" data-frgm-vazgec hidden>Vazgeç</button>
      </span>
    </div>
    <?php if ($frgmYarim): ?>
      <div class="frgm-devam" data-frgm-devam="<?= h((string)$frgmYarim['tarama_id']) ?>">
        <span>Yarım kalmış Frugro mail sorgusu var (<?= (int)$frgmYarim['i'] ?> / <?= (int)$frgmYarim['toplam'] ?> ek).</span>
        <button type="button" class="btn btn-sm" data-frgm-devam-et>Devam et</button>
        <button type="button" class="btn btn-sm" data-frgm-vazgec>Vazgeç</button>
      </div>
    <?php endif; ?>
    <p class="frgm-hata" data-frgm-hata role="alert" hidden></p>
    <div data-frgm-sonuc><?= (string)($frgmV['son_html'] ?? '') ?></div>
  <?php endif; ?>
</section>
<script src="assets/frugro_mail.js?v=<?= (int)@filemtime(__DIR__ . '/assets/frugro_mail.js') ?>" defer></script>
