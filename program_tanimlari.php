<?php
// Program Tanımları — Şirketler (Faz 0.1, v505): şirket ekle / düzenle / pasife al. Yalnız yönetici.
// İçeriği kullanıcı girer (sayı sınırı yok, kodda sabit şirket yok). Şirket SİLİNMEZ, pasife alınır.
// Şema yalnız migrate.php "Şirket · Şube · Depo" kartından kurulur; tablo yoksa bu sayfa yalnız yönlendirir.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
require_once __DIR__ . '/config/sirket_yapisi.php';
require_once __DIR__ . '/config/sirket_yapisi_ui.php';

$pdo   = db();
$hazir = sirket_yapisi_hazir($pdo);
$uid   = (int)($auth_user['id'] ?? 0) ?: null;
$hatalar = [];
$form = null;          // doğrulama hatasında kullanıcının girdiği değerler
$duzenle_id = null;

if ($hazir && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $eylem = (string)($_POST['eylem'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if ($eylem === 'kaydet') {
        $r = sirket_kaydet($pdo, $_POST, $id, $uid);
        if ($r['ok']) {
            set_flash('success', $id === null ? 'Şirket eklendi.' : 'Şirket güncellendi.');
            header('Location: program_tanimlari.php');
            exit;
        }
        $hatalar = $r['hatalar'];
        $form = $_POST;
        $duzenle_id = $id;
    } elseif ($eylem === 'aktif' && $id !== null) {
        $yap = (string)($_POST['aktif'] ?? '') === '1';
        set_flash(sirket_aktif_yap($pdo, $id, $yap, $uid) ? 'success' : 'error', $yap ? 'Şirket aktif edildi.' : 'Şirket pasife alındı.');
        header('Location: program_tanimlari.php');
        exit;
    }
}

$yeni = $hazir && (isset($_GET['yeni']) || ($form !== null && $duzenle_id === null));
if ($hazir && $form === null && isset($_GET['duzenle'])) {
    $duzenle_id = (int)$_GET['duzenle'];
    $form = sirket_getir($pdo, $duzenle_id);
    if ($form === null) { $duzenle_id = null; }
}
$formda = $hazir && ($yeni || $duzenle_id !== null);
$sirketler = $hazir ? sirket_listele($pdo) : [];
$f = static fn(string $k) => $form[$k] ?? '';

render_header('Program Tanımları');
render_flash();
sy_sekmeler('sirket');
if (!$hazir) { sy_tablo_yok(); render_footer(); exit; }
?>
<div class="card">
  <div class="sy-baslik">
    <h2>Şirketler</h2>
    <?php if (!$formda): ?><a class="btn btn-primary" href="program_tanimlari.php?yeni=1">＋ Şirket Ekle</a><?php endif; ?>
  </div>
  <p class="muted">Ayrı vergi numarası = ayrı şirket. Şirket kodu belge numaralarında kullanılacak (ör. kod + belge türü + yıl + sıra); sonradan değiştirmek geçmiş numaraları değiştirmez. Şirketler silinmez, pasife alınır.</p>

<?php if ($formda): ?>
  <?php sy_hatalar($hatalar); ?>
  <form method="post" class="sy-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="eylem" value="kaydet">
    <?php if ($duzenle_id !== null): ?><input type="hidden" name="id" value="<?= (int)$duzenle_id ?>"><?php endif; ?>
    <h3 style="margin:8px 0 0"><?= $duzenle_id !== null ? 'Şirketi Düzenle' : 'Yeni Şirket' ?></h3>
    <div class="sy-grid">
      <?php sy_alan('kod', 'Şirket kodu', $f('kod'), ['req' => 1, 'max' => 12, 'upper' => 1, 'ph' => 'kısa kod', 'hint' => '2–12 karakter; yalnız A–Z harfleri ve rakam.']); ?>
      <?php sy_alan('unvan', 'Unvan', $f('unvan'), ['req' => 1, 'max' => 200, 'wide' => 1]); ?>
      <?php sy_alan('kisa_ad', 'Kısa ad', $f('kisa_ad'), ['max' => 60, 'hint' => 'Ekranlarda gösterilecek ad.']); ?>
      <?php sy_alan('vergi_no', 'Vergi no (VKN / TCKN)', $f('vergi_no'), ['max' => 20, 'inputmode' => 'numeric', 'hint' => '10 ya da 11 hane.']); ?>
      <?php sy_alan('vergi_dairesi', 'Vergi dairesi', $f('vergi_dairesi'), ['max' => 100]); ?>
      <?php sy_alan('adres', 'Adres', $f('adres'), ['max' => 255, 'wide' => 1]); ?>
      <?php sy_alan('sehir', 'Şehir', $f('sehir'), ['max' => 80]); ?>
      <?php sy_alan('ulke', 'Ülke', $f('ulke'), ['max' => 80]); ?>
      <?php sy_alan('telefon', 'Telefon', $f('telefon'), ['type' => 'tel', 'max' => 40]); ?>
      <?php sy_alan('eposta', 'E-posta', $f('eposta'), ['type' => 'email', 'max' => 150]); ?>
      <?php sy_alan('e_belge_etiketi', 'e-Belge etiketi (GİB)', $f('e_belge_etiketi'), ['max' => 150, 'hint' => 'e-Fatura / e-İrsaliye posta kutusu etiketi.']); ?>
      <?php sy_alan('logo_firma_no', 'Logo firma no', $f('logo_firma_no'), ['max' => 10, 'inputmode' => 'numeric', 'hint' => 'Logo entegrasyonu için (sonra).']); ?>
      <?php sy_alan('renk', 'Renk', ($f('renk') !== '' ? $f('renk') : '#2563eb'), ['type' => 'color', 'hint' => 'Ekranda şirketi ayırt etmek için.']); ?>
      <?php sy_alan('notlar', 'Notlar', $f('notlar'), ['max' => 500, 'wide' => 1]); ?>
    </div>
    <div class="sy-eylem">
      <button type="submit" class="btn btn-primary">Kaydet</button>
      <a class="btn btn-ghost" href="program_tanimlari.php">Vazgeç</a>
    </div>
  </form>
<?php endif; ?>

<?php if (!$sirketler): ?>
  <p class="muted" style="margin-top:14px">Henüz şirket tanımlanmadı. <b>＋ Şirket Ekle</b> ile ilk şirketi girin; istediğiniz kadar ekleyebilirsiniz.</p>
<?php else: ?>
  <div class="table-wrap" style="margin-top:14px">
    <table class="table">
      <thead><tr><th>Kod</th><th>Unvan</th><th class="sy-gizle">Vergi no</th><th class="sy-gizle">Şehir</th><th class="sy-gizle">Logo no</th><th>Durum</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sirketler as $s): ?>
        <tr class="<?= (int)$s['aktif'] === 1 ? '' : 'sy-pasif' ?>">
          <td><?= sy_kod_rozet($s['renk'] ?? null, (string)$s['kod']) ?></td>
          <td><?= h($s['unvan']) ?><?php if (!empty($s['kisa_ad'])): ?><div class="muted"><?= h($s['kisa_ad']) ?></div><?php endif; ?></td>
          <td class="sy-gizle"><?= h($s['vergi_no'] ?? '') ?></td>
          <td class="sy-gizle"><?= h($s['sehir'] ?? '') ?></td>
          <td class="sy-gizle"><?= h($s['logo_firma_no'] ?? '') ?></td>
          <td><span class="sy-rozet <?= (int)$s['aktif'] === 1 ? '' : 'sy-rozet-pasif' ?>"><?= (int)$s['aktif'] === 1 ? 'Aktif' : 'Pasif' ?></span></td>
          <td>
            <div class="sy-eylem">
              <a class="btn btn-sm btn-ghost" href="program_tanimlari.php?duzenle=<?= (int)$s['id'] ?>">Düzenle</a>
              <a class="btn btn-sm btn-ghost" href="sube_tanimlari.php?sirket=<?= (int)$s['id'] ?>">Şubeler</a>
              <form method="post" onsubmit="return confirm('<?= (int)$s['aktif'] === 1 ? 'Şirket pasife alınsın mı? Yeni kayıt alamaz, geçmiş kayıtları görünür kalır.' : 'Şirket yeniden aktif edilsin mi?' ?>')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="eylem" value="aktif">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="aktif" value="<?= (int)$s['aktif'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm btn-ghost"><?= (int)$s['aktif'] === 1 ? 'Pasife al' : 'Aktif et' ?></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php render_footer();
