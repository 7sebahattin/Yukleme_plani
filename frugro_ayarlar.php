<?php
// =========================================================
// frugro_ayarlar.php — Frugro ayarları: (1) "Mailden al" (posta kutuları + gönderen adresleri; hks_kv frugro_mail_ayar)
//                                         (2) Mail ayarları #pkl-mail (PKL / evrak takımı taslağının Kime, Bilgi (CC) ve metin şablonları;
//                                             hks_kv frugro_pkl_mail_ayar — frgd_mail_ayar_kaydet)
// Kapı: (1) siparis.delete + Mail Merkezi yönetimi (frgm_ayar_yetkisi(); OGL mail ayarıyla AYNI) · (2) siparis.delete (OGL v422 eşi).
// Sayfa ikisinden birine yetkisi olana açılır; her bölüm kendi kapısıyla çizilir ve kaydedilir.
// Adresler repoya GİRMEZ (DB'de). Audit yalnız SAYILARI yazar. POST: CSRF + kapı burada, yazma kütüphanede.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
require_once __DIR__ . '/config/mail_view.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_mail.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_mail.php';
require_once __DIR__ . '/config/frugro_detay.php';
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

render_header('Frugro ayarları');
siparis_assets();
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
</div>
<?php
siparis_scripts();
render_footer();
