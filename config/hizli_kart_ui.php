<?php
// =========================================================
// config/hizli_kart_ui.php — Ortak "Hızlı Cari / Stok Ekle" penceresi işaretlemesi (v611 Faz 2; include-only; yalnız fonksiyon bildirir)
//
// hizli_kart_bilesen_html($secenek)  sayfaya BİR KEZ basılır: #hkModal (.pm-overlay > .pm-dialog.hk-dialog > form; z-index ≥ 600)
//                                    + #hkSecenek JSON (JSON_HEX_*) + assets/hizli_kart.css|js. Kullanılabilir tür yoksa '' (hiçbir şey çizilmez).
//                                    $secenek = hizli_kart_secenekler($pdo, [...]) (config/hizli_kart.php). Listeler JS'te DOM ile kurulur.
// hizli_kart_dugme_html($tur, $o)    "⚡ Hızlı ekle" düğmesi (yalnız oluşturma izni + şema hazırsa; aksi '').
//                                    $o: ad · tip (cari tipi) · kart_tip (urun|malzeme|diger) · ambalaj_kod (kasa|palet) · git (başarıda gidilecek
//                                    yerel adres, `{id}` yer tutuculu; yalnız ^[a-z_]+\.php(\?…)?$) · etiket · sinif.
// JS API (assets/hizli_kart.js): window.HizliKart.ac({tur, ad, tip, kartTip, ambalajKod, sonra: function (kart) {…}}).
// Bu dosya SORGU YAZMAZ; HTML'e giren her dinamik değer h() ile kaçışlanır. Sınıf/kimlik öneki `hk-` / `hk*`.
// Çekirdek dosyaları YÜKLEMEZ: çağıran config/hizli_kart.php + modül çekirdeğini (cari_core / stok_core) yükler.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Seçeneklerde kullanılabilir (izin + şema) türler. @return list<string> */
function hizli_kart_acik_turler(array $secenek): array
{
    $o = [];
    foreach (['cari', 'stok'] as $t) if (!empty($secenek[$t]['hazir']) && !empty($secenek[$t]['izin'])) $o[] = $t;
    return $o;
}

/**
 * "⚡ Hızlı ekle" düğmesi. Oluşturma izni yoksa ya da şema kurulmamışsa '' döner (düğme GİZLİ).
 * @param array{ad?:string,tip?:string,kart_tip?:string,ambalaj_kod?:string,git?:string,etiket?:string,sinif?:string} $o
 */
function hizli_kart_dugme_html(string $tur, array $o = [], ?PDO $pdo = null): string
{
    if (!in_array($tur, ['cari', 'stok'], true) || !function_exists('hizli_kart_kullanilabilir')) return '';
    if ($pdo === null) { try { $pdo = db(); } catch (Throwable $e) { return ''; } }
    try {
        if (!hizli_kart_kullanilabilir($pdo, $tur)) return '';
    } catch (Throwable $e) {
        return '';
    }
    $at = ' data-hk-ac="' . $tur . '"';
    foreach (['ad' => 'data-hk-metin', 'tip' => 'data-hk-tip', 'kart_tip' => 'data-hk-kart-tip', 'ambalaj_kod' => 'data-hk-ambalaj'] as $k => $a) {
        $v = trim((string)($o[$k] ?? ''));
        if ($v !== '') $at .= ' ' . $a . '="' . h(mb_substr($v, 0, 200)) . '"';
    }
    $git = (string)($o['git'] ?? '');
    if ($git !== '' && preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&{}%.-]*)?$/', $git) === 1) $at .= ' data-hk-git="' . h($git) . '"';
    $etiket = (string)($o['etiket'] ?? 'Hızlı ekle');
    $baslik = $tur === 'cari' ? 'Hızlı cari ekle (asgari bilgiyle; eksikler sonra tamamlanır)' : 'Hızlı stok kartı ekle (asgari bilgiyle; eksikler sonra tamamlanır)';
    return '<button type="button" class="btn hk-dugme' . (($o['sinif'] ?? '') !== '' ? ' ' . h((string)$o['sinif']) : '') . '"' . $at
        . ' title="' . h($baslik) . '" aria-haspopup="dialog" aria-controls="hkModal"><span class="hk-dugme-ikon" aria-hidden="true">&#9889;</span><span class="hk-dugme-metin">' . h($etiket) . '</span></button>';
}

/** Pencere + seçenek JSON'u + varlıklar. Kullanılabilir tür yoksa ''. Sayfa başına bir kez basılır (ikinci çağrı ''). */
function hizli_kart_bilesen_html(array $secenek): string
{
    static $basildi = false;
    $turler = hizli_kart_acik_turler($secenek);
    if ($basildi || !$turler) return '';
    $basildi = true;
    $kok = dirname(__DIR__);
    $veri = [];
    foreach ($turler as $t) $veri[$t] = $secenek[$t];
    $json = (string)json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $cari = in_array('cari', $turler, true);
    $stok = in_array('stok', $turler, true);
    ob_start(); ?>
<link rel="stylesheet" href="assets/hizli_kart.css?v=<?= (int)@filemtime($kok . '/assets/hizli_kart.css') ?>">
<div class="pm-overlay hk-overlay" id="hkModal" hidden data-hk-api="api_hizli_kart.php">
  <div class="pm-dialog hk-dialog" role="dialog" aria-modal="true" aria-labelledby="hkBaslik" aria-describedby="hkAciklama">
    <div class="pm-header">
      <h2 class="pm-title" id="hkBaslik">Hızlı ekle</h2>
      <button type="button" class="pm-close" data-hk-kapat aria-label="Kapat">&#10005;</button></div>
    <form id="hkForm" novalidate autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="pm-body">
        <p class="hk-aciklama" id="hkAciklama">Asgari bilgiyle kart açılır ve <b>hızlı eklendi</b> olarak işaretlenir; eksikleri kart ekranından tamamlayın.</p>
        <div class="hk-hata-genel" role="alert" hidden></div>
<?php if ($cari): ?>
        <fieldset class="hk-bolum" data-hk-bolum="cari" hidden disabled>
          <legend class="hk-sr">Cari bilgileri</legend>
          <div class="hk-izgara">
            <label class="pm-label hk-alan hk-tam" data-hk-alan="unvan"><span>Unvan <b class="hk-zorunlu" aria-hidden="true">*</b></span>
              <input type="text" name="unvan" maxlength="200" required autocomplete="off" data-hk-ilk>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="tip"><span>Cari tipi <b class="hk-zorunlu" aria-hidden="true">*</b></span>
              <select name="tip" required data-hk-liste="cari.tipler"></select>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="telefon" data-hk-vurgu><span>Telefon</span>
              <input type="tel" name="telefon" maxlength="40" inputmode="tel" autocomplete="off">
              <span class="hk-ipucu hk-vurgu-ipucu" data-hk-vurgu-ipucu hidden>HKS için önerilir</span>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="vergi_no"><span>Vergi no (VKN)</span>
              <input type="text" name="vergi_no" maxlength="10" inputmode="numeric" autocomplete="off">
              <span class="hk-ipucu">10 hane</span>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="tckn" data-hk-vurgu><span>TCKN</span>
              <input type="text" name="tckn" maxlength="11" inputmode="numeric" autocomplete="off">
              <span class="hk-ipucu">11 hane · T.C. kimlik no</span>
              <span class="hk-ipucu hk-vurgu-ipucu" data-hk-vurgu-ipucu hidden>HKS için önerilir</span>
              <span class="hk-alan-hata" hidden></span></label>
          </div>
          <p class="hk-vurgu-not" data-hk-mustahsil-not hidden>Müstahsil: Hal bildirimi için TCKN ve telefon önerilir (zorunlu değil). Doğum tarihi Cari Kartları'ndan eklenir.</p>
        </fieldset>
<?php endif; ?>
<?php if ($stok): ?>
        <fieldset class="hk-bolum" data-hk-bolum="stok" hidden disabled>
          <legend class="hk-sr">Stok kartı bilgileri</legend>
          <div class="hk-izgara">
            <label class="pm-label hk-alan hk-tam" data-hk-alan="ad"><span>Ad <b class="hk-zorunlu" aria-hidden="true">*</b></span>
              <input type="text" name="ad" maxlength="200" required autocomplete="off" data-hk-ilk>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="tip"><span>Kart tipi <b class="hk-zorunlu" aria-hidden="true">*</b></span>
              <select name="tip" required data-hk-liste="stok.kart_tipleri"></select>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="ana_birim"><span>Ana birim <b class="hk-zorunlu" aria-hidden="true">*</b></span>
              <select name="ana_birim" required data-hk-liste="stok.birimler"></select>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="ambalaj_tip_id"><span>Ambalaj tipi</span>
              <select name="ambalaj_tip_id" data-hk-liste="stok.ambalaj_tipleri"></select>
              <span class="hk-alan-hata" hidden></span></label>
            <label class="pm-label hk-alan" data-hk-alan="birim_dara_kg"><span>Birim dara (kg) <b class="hk-zorunlu" data-hk-dara-zorunlu hidden aria-hidden="true">*</b></span>
              <input type="text" name="birim_dara_kg" inputmode="decimal" maxlength="12" autocomplete="off" placeholder="ör. 1,8">
              <span class="hk-ipucu">Bir kasa / palet darası; ambalaj tipi seçiliyse zorunlu</span>
              <span class="hk-alan-hata" hidden></span></label>
            <div class="pm-label hk-alan hk-tam" data-hk-alan="hks_urun" data-hk-hks-alan>
              <label for="hkHksAra">HKS ürün <small class="hk-ipucu">yalnız Ürün tipi</small></label>
              <div class="hk-hks">
                <input type="text" id="hkHksAra" name="hks_urun_ad" maxlength="160" autocomplete="off" spellcheck="false" placeholder="Yazın: HKS ürün adı"
                  role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="hkHksListe">
                <input type="hidden" name="hks_urun_id" value="">
                <ul class="hk-hks-liste" id="hkHksListe" role="listbox" aria-label="HKS ürünleri" hidden></ul>
              </div>
              <span class="hk-ipucu" data-hk-hks-ipucu>Hal Bildirimi ürün kataloğundan seçin</span>
              <span class="hk-alan-hata" hidden></span></div>
            <label class="pm-label hk-alan hk-tam" data-hk-alan="grup_id"><span>Stok grubu</span>
              <select name="grup_id" data-hk-liste="stok.gruplar"></select>
              <span class="hk-alan-hata" hidden></span></label>
          </div>
        </fieldset>
<?php endif; ?>
      </div>
      <div class="pm-footer"><span class="hk-klavye">Enter kaydeder · Esc kapatır</span>
        <button type="button" class="btn" data-hk-kapat>İptal</button>
        <button type="submit" class="btn btn-primary" data-hk-kaydet>Kaydet</button></div>
    </form>
  </div>
</div>
<script type="application/json" id="hkSecenek"><?= $json ?></script>
<script src="assets/hizli_kart.js?v=<?= (int)@filemtime($kok . '/assets/hizli_kart.js') ?>" defer></script>
<?php
    return (string)ob_get_clean();
}
