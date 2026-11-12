<?php
// =========================================================
// frugro_ayarlar.php — Frugro ayarları: (1) "Mailden al" (posta kutuları + gönderen adresleri; hks_kv frugro_mail_ayar)
//                                         (2) Mail ayarları #pkl-mail (PKL / evrak takımı taslağının Kime, Bilgi (CC) ve metin şablonları;
//                                             hks_kv frugro_pkl_mail_ayar — frgd_mail_ayar_kaydet)
//                                         (3) Banka / fatura ayarları #fatura (Frugro Invoice: banka / IBAN / VAT; hks_kv frugro_invoice_ayar
//                                             — frgi_ayar_kaydet; POST islem=fatura_ayar; taşındı: eski frugro_invoice.php › islem=ayar)
// Kapı: (1) siparis.delete + Mail Merkezi yönetimi (frgm_ayar_yetkisi(); OGL mail ayarıyla AYNI) · (2) siparis.delete (OGL v422 eşi)
//       · (3) siparis.delete (bölüm yalnız bunu görür; POST da burada 403).
// Sayfa ikisinden birine yetkisi olana açılır; her bölüm kendi kapısıyla çizilir ve kaydedilir.
// Adresler / IBAN / VAT repoya GİRMEZ (DB'de). Audit yalnız SAYILARI / alan ADLARINI yazar. POST: CSRF + kapı burada, yazma kütüphanede.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/frugro_invoice.php';
require_once __DIR__ . '/config/mail_core.php';
require_once __DIR__ . '/config/mail_view.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_mail.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_mail.php';
require_once __DIR__ . '/config/frugro_detay.php';
require_once __DIR__ . '/config/frugro_pkl.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
$mailYk = frgm_ayar_yetkisi();
$pklYk = can_siparis('delete');
if (!$mailYk && !$pklYk) forbidden('Frugro ayarları için sipariş silme yetkisi gerekir.');
$pdo = db();
$uid = (int)$auth_user['id'];
$hazir = $mailYk && frgm_hazir($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'pkl_mail') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('delete');
    $r = frgd_mail_ayar_kaydet($pdo, ['kime' => (string)($_POST['kime'] ?? ''), 'bilgi' => (string)($_POST['bilgi'] ?? ''),
        'govde' => (string)($_POST['govde'] ?? ''), 'evrak_govde' => (string)($_POST['evrak_govde'] ?? '')], $uid);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Frugro mail ayarları kaydedildi.' : (string)($r['hata'] ?? 'Ayarlar kaydedilemedi.'));
    header('Location: frugro_ayarlar.php#pkl-mail');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'fatura_ayar') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('delete');                                   // Banka / fatura ayarı: yalnız siparis.delete
    $girdi = [];
    foreach (array_keys(frgi_ayar_alanlari()) as $k) if (array_key_exists($k, $_POST)) $girdi[$k] = $_POST[$k];
    $r = frgi_ayar_kaydet($pdo, $girdi, $uid);
    $kaydedilen = count((array)($r['degisen'] ?? []));
    $hatalarA = (array)($r['hatalar'] ?? []);
    if ($hatalarA) {                                             // hatalı alanların GİRİLEN değeri formda kalsın (geçerli alanlar kaydedildi)
        $_SESSION['frugro_invoice_ayar_form'] = ['deger' => array_map(static fn($v) => mb_substr((string)(is_scalar($v) ? $v : ''), 0, 250, 'UTF-8'), $girdi), 'hata' => $hatalarA];
        set_flash('error', ($kaydedilen > 0 ? $kaydedilen . ' alan kaydedildi. ' : '') . 'Düzeltilmesi gereken: ' . implode(' ', array_values($hatalarA)));
    } else {
        unset($_SESSION['frugro_invoice_ayar_form']);
        set_flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok'])
            ? ($kaydedilen === 0 ? 'Fatura ayarında değişiklik yok.' : 'Fatura ayarı kaydedildi (' . $kaydedilen . ' alan). Bir daha girmeniz gerekmez.')
            : (string)($r['hata'] ?? 'Ayar kaydedilemedi.'));
    }
    header('Location: frugro_ayarlar.php#fatura');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'ggn') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('delete');                                   // GGN adı + no listesi: yalnız siparis.delete
    $r = frgp_ggn_kaydet($pdo, is_array($_POST['ggn'] ?? null) ? $_POST['ggn'] : [], $uid);
    set_flash(!empty($r['ok']) ? 'success' : 'error', !empty($r['ok']) ? 'GGN listesi kaydedildi.' : (string)($r['hata'] ?? 'GGN listesi kaydedilemedi.'));
    header('Location: frugro_ayarlar.php#ggn');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis('delete');
    if (!$mailYk) forbidden('Mailden al ayarı için Mail Merkezi yönetim yetkisi gerekir.');
    if (!$hazir) { set_flash('error', 'Frugro mail sorgusu kurulu değil (Frugro şeması, Mail Merkezi, MAIL_MASTER_KEY ya da hks_kv eksik).'); header('Location: frugro_ayarlar.php'); exit; }
    $r = frgm_ayar_kaydet($pdo, ['hesaplar' => (array)($_POST['hesaplar'] ?? []), 'gonderen' => (string)($_POST['gonderen'] ?? '')], $uid);
    set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Frugro mail ayarları kaydedildi.' : (string)($r['hata'] ?? 'Ayarlar kaydedilemedi.'));
    header('Location: frugro_ayarlar.php');
    exit;
}

$ayar = $hazir ? frgm_ayar_oku($pdo) : ['hesaplar' => [], 'gonderen' => []];
$hesaplar = $hazir ? siparis_mail_hesaplar($pdo) : [];
$pklAyar = frgd_mail_ayar_oku($pdo);
$kvHazir = siparis_evrak_mail_kv_hazir($pdo);
$ggnListe = $pklYk ? frgp_ggn_liste($pdo) : [];

// Banka / fatura ayarı (yalnız siparis.delete): form değerleri + hatalı alan mesajları (PRG sonrası tek kullanımlık oturum)
$faHam = $pklYk ? frgi_ayar_ham($pdo) : [];
$faForm = $_SESSION['frugro_invoice_ayar_form'] ?? null; unset($_SESSION['frugro_invoice_ayar_form']);
$faHata = is_array($faForm) ? (array)($faForm['hata'] ?? []) : [];
if ($pklYk && is_array($faForm)) foreach ($faHata as $k => $_m) if (isset($faForm['deger'][$k])) $faHam[$k] = (string)$faForm['deger'][$k];
$faEtkin = $pklYk ? frgi_ayar_etkin($pdo) : [];
$faEksik = [];                                                    // para birimi → eksik alan etiketleri (GBP/EUR/USD/RUB)
foreach (array_keys(frgi_para_birimleri()) as $pbK) $faEksik[$pbK] = $pklYk ? frgi_ayar_eksikler($faEtkin, $pbK) : [];
$faEksikVar = (bool)array_filter($faEksik);

render_header('Frugro ayarları');
siparis_assets();
echo '<link rel="stylesheet" href="assets/frugro_invoice.css?v=' . (int)@filemtime(__DIR__ . '/assets/frugro_invoice.css') . '">' . "\n";
render_flash();
?>
<div class="sip-mod">
  <div class="page-head">
    <div><a class="btn btn-geri" href="frugro_siparis.php">← Frugro Siparişler</a><h1 class="sip-h1">Frugro ayarları</h1><p class="muted">"Mailden al" posta kutuları · PKL / evrak takımı mail alıcıları ve metinleri</p></div>
  </div>
  <?php if ($mailYk): ?>
  <section class="card" id="mail" aria-labelledby="frgAyarBas">
    <div class="card-head"><h2 id="frgAyarBas">📧 Mailden al</h2><span class="sip-soluk">Son <?= (int)FRGM_GUN ?> gün</span></div>
    <?php if (!$hazir): ?>
      <p class="sip-bilgi" role="note">Mail sorgusu kurulu değil: Frugro tabloları (migrate.php › Frugro Siparişler), Mail Merkezi tabloları, MAIL_MASTER_KEY ve Hal Kayıt ayar tablosu (hks_kv) gerekir.</p>
    <?php else: ?>
      <p class="muted">Frugro Siparişler sayfasındaki <strong>Mailden al</strong> düğmesi yalnız burada işaretlenen posta kutularını ve yalnız aşağıdaki gönderen adreslerinden gelen mailleri tarar. Yalnız <code>.xlsx</code>/<code>.xlsm</code> ekler okunur ve içinde <em>Order Log</em> sayfası ile PO numarası bulunmalıdır; aynı PO ikinci kez gelirse (ör. "Geri Gönderim") yeni kayıt açılmaz. Adres alan adına göre değil, <strong>tam adrese</strong> göre eşleşir.</p>
      <p class="sip-bilgi" role="note">Mailde 'X adına Y' görünüyorsa iki adresi de ekleyin.</p>
      <form method="post" action="frugro_ayarlar.php" class="sip-form sip-mail-ayar" data-sip-tek>
        <?= sipui_csrf() ?>
        <fieldset class="sip-mail-hesaplar"><legend>Taranacak posta kutuları</legend>
          <?php if (!$hesaplar): ?><p class="sip-soluk">Aktif posta kutusu yok (Mail Merkezi › Hesaplar).</p><?php endif; ?>
          <?php foreach ($hesaplar as $mh): ?>
            <label class="sip-onay-satir"><input type="checkbox" name="hesaplar[]" value="<?= (int)$mh['id'] ?>"<?= in_array((int)$mh['id'], $ayar['hesaplar'], true) ? ' checked' : '' ?>> <?= h($mh['label']) ?> <span class="sip-soluk">(<?= h($mh['email']) ?>)</span></label>
          <?php endforeach; ?>
        </fieldset>
        <label>Frugro sipariş mailleri gönderenleri
          <textarea name="gonderen" rows="4" maxlength="1500" autocomplete="off" spellcheck="false"><?= h(implode("\n", $ayar['gonderen'])) ?></textarea>
        </label>
        <p class="sip-soluk">Satır başına bir adres; en çok <?= (int)FRGM_GONDEREN_MAX ?>. Boş bırakılırsa düğme pasif kalır.</p>
        <div class="sip-yukle-alt"><button type="submit" class="btn btn-primary">Kaydet</button></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($pklYk): ?>
  <section class="card" id="pkl-mail" aria-labelledby="frgPklMailBas">
    <div class="card-head"><h2 id="frgPklMailBas">✉ Mail ayarları (PKL ve evrak takımı)</h2></div>
    <p class="muted">Sipariş sayfasındaki <strong>Mail gönderildi</strong> ve <strong>Evrak takımı</strong> pencerelerinin taslağı bu adreslerle hazırlanır (Order Log'da e-posta yoktur). Boş bırakılırsa taslak alıcısız hazırlanır. Metin şablonunda <code>{ad}</code> gönderenin adıyla dolar; boş = varsayılan metin.</p>
    <?php if (!$kvHazir): ?>
      <p class="sip-bilgi" role="note">Ayar tablosu (hks_kv) yok; Hal Kayıt modülü kurulunca bu ayar kaydedilebilir.</p>
    <?php else: ?>
    <form method="post" action="frugro_ayarlar.php" class="sip-form" data-sip-tek>
      <?= sipui_csrf() ?><input type="hidden" name="islem" value="pkl_mail">
      <label>Kime
        <textarea name="kime" rows="3" maxlength="2600" autocomplete="off" spellcheck="false"><?= h(implode("\n", $pklAyar['kime'])) ?></textarea></label>
      <label>Bilgi (CC)
        <textarea name="bilgi" rows="3" maxlength="2600" autocomplete="off" spellcheck="false"><?= h(implode("\n", $pklAyar['bilgi'])) ?></textarea></label>
      <p class="sip-soluk">Satır başına bir adres; her listede en çok <?= (int)SIPARIS_EVRAK_MAIL_MAX ?>. Geçersiz adres kaydı engeller.</p>
      <label>PKL mail metni
        <textarea name="govde" rows="6" maxlength="<?= (int)SIPARIS_MAIL_GOVDE_MAX ?>" placeholder="<?= h(SIPARIS_PKL_GOVDE_VARSAYILAN) ?>" spellcheck="false"><?= h($pklAyar['govde']) ?></textarea></label>
      <label>Evrak takımı mail metni
        <textarea name="evrak_govde" rows="5" maxlength="<?= (int)SIPARIS_MAIL_GOVDE_MAX ?>" placeholder="<?= h(SIPARIS_EVRAK_GOVDE_VARSAYILAN) ?>" spellcheck="false"><?= h($pklAyar['evrak_govde']) ?></textarea></label>
      <div class="sip-yukle-alt"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($pklYk): ?>
  <section class="card" id="ggn" aria-labelledby="frgGgnBas">
    <div class="card-head"><h2 id="frgGgnBas">🌱 GGN listesi (üretici)</h2></div>
    <p class="muted">Packing List satırlarında <strong>GGN adı</strong> bu listeden seçilir, GGN no otomatik gelir. Adlar ve numaralar yalnız burada tanımlanır; aynı ad iki kez girilemez. Satırı silmek için adı ve numarayı boşaltın (mevcut PKL satırlarındaki kayıtlı değerler korunur).</p>
    <?php if (!$kvHazir): ?>
      <p class="sip-bilgi" role="note">Ayar tablosu (hks_kv) yok; Hal Kayıt modülü kurulunca bu ayar kaydedilebilir.</p>
    <?php else: ?>
    <form method="post" action="frugro_ayarlar.php#ggn" class="sip-form" data-sip-tek>
      <?= sipui_csrf() ?><input type="hidden" name="islem" value="ggn">
      <div class="table-wrap"><table class="data-table frgp-ggn-tablo">
        <thead><tr><th>#</th><th>GGN adı</th><th>GGN no</th></tr></thead>
        <tbody>
        <?php $gSat = array_merge($ggnListe, array_fill(0, 5, ['ad' => '', 'ggn' => ''])); foreach ($gSat as $gi => $g): ?>
          <tr><td class="muted"><?= $gi + 1 ?></td>
            <td><input type="text" name="ggn[<?= $gi ?>][ad]" value="<?= h($g['ad']) ?>" maxlength="100" autocomplete="off" aria-label="GGN adı <?= $gi + 1 ?>"></td>
            <td><input type="text" name="ggn[<?= $gi ?>][no]" value="<?= h($g['ggn']) ?>" maxlength="20" inputmode="numeric" autocomplete="off" spellcheck="false" aria-label="GGN no <?= $gi + 1 ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="sip-soluk">En çok <?= (int)FRGP_GGN_MAX ?> kayıt; GGN no yalnız harf, rakam ve tire. Daha fazla satır için "+ Satır ekle" (JS kapalıysa en çok 5 boş satır).</p>
      <p class="sip-soluk" data-frga-not hidden>En çok <?= (int)FRGP_GGN_MAX ?> GGN kaydına ulaşıldı; yeni satır eklenemez.</p>
      <div class="sip-yukle-alt"><button type="button" class="btn" data-frga-ekle data-frga-max="<?= (int)FRGP_GGN_MAX ?>">+ Satır ekle</button> <button type="submit" class="btn btn-primary">Kaydet</button></div>
      <template id="frga-satir"><tr><td class="muted" data-frga-no></td>
        <td><input type="text" name="ggn[0][ad]" value="" maxlength="100" autocomplete="off" data-frga-ad></td>
        <td><input type="text" name="ggn[0][no]" value="" maxlength="20" inputmode="numeric" autocomplete="off" spellcheck="false" data-frga-no-giris></td></tr></template>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($pklYk): ?>
  <section class="card frgi-sayfa" id="fatura" aria-labelledby="frgFaturaBas">
    <div class="card-head"><h2 id="frgFaturaBas">🏦 Banka / fatura ayarları</h2></div>
    <p class="muted">Bir kez girilir, TÜM Frugro faturalarına basılır. Boş bırakılan alan, varsa OGL invoice ayarından (banka adı / adres / şube / SWIFT / IBAN-EUR) alınır. Her para birimi (GBP, EUR, USD, RUB) kendi IBAN alanını kullanır. Hatalı alan olursa diğerleri yine kaydedilir.</p>
    <?php if (!$kvHazir): ?>
      <p class="sip-bilgi" role="note">Ayar tablosu (hks_kv) yok; Hal Kayıt modülü kurulunca bu ayar kaydedilebilir.</p>
    <?php else: ?>
      <?php if ($faEksikVar): ?>
        <p class="sip-ok-uyari" role="note">Eksik — <?php $faSat = []; foreach ($faEksik as $pbK => $ek) $faSat[] = h($pbK) . ' faturası: ' . ($ek ? h(implode(', ', $ek)) : 'tamam'); echo implode(' · ', $faSat); ?></p>
      <?php else: ?>
        <p class="muted" role="status">✓ Kayıtlı — tüm Frugro faturalarında kullanılır, yeniden girmeniz gerekmez.</p>
      <?php endif; ?>
      <form method="post" action="frugro_ayarlar.php#fatura" class="sip-form frgi-ayar-form">
        <?= sipui_csrf() ?><input type="hidden" name="islem" value="fatura_ayar">
        <div class="sip-izgara">
          <?php foreach (frgi_ayar_alanlari() as $k => [$et, $n]): ?>
            <label><?= h($et) ?>
              <input type="text" name="<?= h($k) ?>" value="<?= h((string)($faHam[$k] ?? '')) ?>" maxlength="<?= (int)$n + 8 ?>" autocomplete="off"<?= isset($faHata[$k]) ? ' aria-invalid="true" class="frgi-hatali"' : '' ?><?= in_array($k, ['iban_gbp', 'iban_eur', 'iban_usd', 'iban_rub', 'swift'], true) ? ' autocapitalize="characters" spellcheck="false"' : '' ?>>
              <?php if (isset($faHata[$k])): ?><small class="frgi-alan-hata" role="alert"><?= h((string)$faHata[$k]) ?></small><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="sip-yukle-alt"><button type="submit" class="btn btn-primary">Ayarları kaydet</button></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php
siparis_scripts();
echo '<script src="assets/frugro_ayar.js?v=' . (int)@filemtime(__DIR__ . '/assets/frugro_ayar.js') . '" defer></script>' . "\n";
render_footer();
