<?php
// Şube Tanımları (Faz 0.1, v505): şirkete bağlı şube ekle / düzenle / pasife al. Yalnız yönetici.
// İçeriği kullanıcı girer (şirket başına istediği kadar şube). Şube SİLİNMEZ, pasife alınır; şirketi sonradan değişmez.
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
$suz   = (int)($_GET['sirket'] ?? $_POST['suz'] ?? 0) ?: null;   // şirket süzgeci
$hatalar = [];
$form = null;
$duzenle_id = null;
$geri = 'sube_tanimlari.php' . ($suz ? '?sirket=' . $suz : '');

if ($hazir && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $eylem = (string)($_POST['eylem'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if ($eylem === 'kaydet') {
        $r = sube_kaydet($pdo, $_POST, $id, $uid);
        if ($r['ok']) {
            set_flash('success', $id === null ? 'Şube eklendi.' : 'Şube güncellendi.');
            header('Location: ' . $geri);
            exit;
        }
        $hatalar = $r['hatalar'];
        $form = $_POST;
        $duzenle_id = $id;
    } elseif ($eylem === 'aktif' && $id !== null) {
        $yap = (string)($_POST['aktif'] ?? '') === '1';
        set_flash(sube_aktif_yap($pdo, $id, $yap, $uid) ? 'success' : 'error', $yap ? 'Şube aktif edildi.' : 'Şube pasife alındı.');
        header('Location: ' . $geri);
        exit;
    }
}

$yeni = $hazir && (isset($_GET['yeni']) || ($form !== null && $duzenle_id === null));
$duzenlenen = null;
if ($hazir && $form === null && isset($_GET['duzenle'])) {
    $duzenle_id = (int)$_GET['duzenle'];
    $duzenlenen = sube_getir($pdo, $duzenle_id);
    if ($duzenlenen === null) { $duzenle_id = null; } else { $form = $duzenlenen; }
}
$formda = $hazir && ($yeni || $duzenle_id !== null);
$sirketler = $hazir ? sirket_listele($pdo) : [];
$aktifSirketler = array_values(array_filter($sirketler, fn($s) => (int)$s['aktif'] === 1));
$subeler = $hazir ? sube_listele($pdo, $suz) : [];
$f = static fn(string $k) => $form[$k] ?? '';
// Yeni şubede şirket: formdaki değer → süzgeç → tek aktif şirket
$secili_sirket = (int)($form['sirket_id'] ?? 0) ?: ($suz ?: (count($aktifSirketler) === 1 ? (int)$aktifSirketler[0]['id'] : 0));

render_header('Şube Tanımları');
render_flash();
sy_sekmeler('sube');
if (!$hazir) { sy_tablo_yok(); render_footer(); exit; }
?>
<div class="card">
  <div class="sy-baslik">
    <h2>Şubeler</h2>
    <?php if (!$formda && $aktifSirketler): ?><a class="btn btn-primary" href="sube_tanimlari.php?yeni=1<?= $suz ? '&amp;sirket=' . $suz : '' ?>">＋ Şube Ekle</a><?php endif; ?>
  </div>
  <p class="muted">Şube, şirketin bir işyeridir (merkez, bölge ofisi, kasa/fatura serisi sahibi). Her şirkete istediğiniz kadar şube ekleyebilirsiniz. Şube kodu şirket içinde tekildir. Şubeler silinmez, pasife alınır.</p>

<?php if (!$aktifSirketler): ?>
  <p class="flash flash-error">Şube eklemek için önce bir şirket tanımlayın. <a href="program_tanimlari.php?yeni=1">＋ Şirket ekle</a></p>
<?php endif; ?>

<?php if ($sirketler): ?>
  <form method="get" class="sy-eylem" style="margin:8px 0">
    <label class="sy-etiket" for="sy_suz">Şirket:</label>
    <select id="sy_suz" name="sirket" onchange="this.form.submit()">
      <option value="">Tümü</option>
      <?php foreach ($sirketler as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= $suz === (int)$s['id'] ? ' selected' : '' ?>><?= h($s['kod'] . ' — ' . $s['unvan']) ?><?= (int)$s['aktif'] === 1 ? '' : ' (pasif)' ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm btn-ghost" type="submit">Göster</button></noscript>
  </form>
<?php endif; ?>

<?php if ($formda && $aktifSirketler || ($formda && $duzenle_id !== null)): ?>
  <?php sy_hatalar($hatalar); ?>
  <form method="post" class="sy-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="eylem" value="kaydet">
    <input type="hidden" name="suz" value="<?= (int)($suz ?? 0) ?>">
    <?php if ($duzenle_id !== null): ?><input type="hidden" name="id" value="<?= (int)$duzenle_id ?>"><?php endif; ?>
    <h3 style="margin:8px 0 0"><?= $duzenle_id !== null ? 'Şubeyi Düzenle' : 'Yeni Şube' ?></h3>
    <div class="sy-grid">
      <?php if ($duzenle_id === null): ?>
      <label class="sy-alan" for="sy_sirket_id"><span class="sy-etiket">Şirket <b title="Zorunlu">*</b></span>
        <select id="sy_sirket_id" name="sirket_id" required>
          <option value="">Seçin…</option>
          <?php foreach ($aktifSirketler as $s): ?>
            <option value="<?= (int)$s['id'] ?>"<?= $secili_sirket === (int)$s['id'] ? ' selected' : '' ?>><?= h($s['kod'] . ' — ' . $s['unvan']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <?php sy_alan('kod', 'Şube kodu', $f('kod'), ['req' => 1, 'max' => 12, 'upper' => 1, 'ph' => 'kısa kod', 'hint' => 'Şirket içinde tekil; A–Z ve rakam.']); ?>
      <?php sy_alan('ad', 'Şube adı', $f('ad'), ['req' => 1, 'max' => 120]); ?>
      <?php sy_alan('adres', 'Adres', $f('adres'), ['max' => 255, 'wide' => 1]); ?>
      <?php sy_alan('sehir', 'Şehir', $f('sehir'), ['max' => 80]); ?>
      <?php sy_alan('telefon', 'Telefon', $f('telefon'), ['type' => 'tel', 'max' => 40]); ?>
      <?php sy_alan('logo_isyeri_no', 'Logo işyeri no', $f('logo_isyeri_no'), ['max' => 10, 'inputmode' => 'numeric', 'hint' => 'Logo entegrasyonu için (sonra).']); ?>
    </div>
    <div class="sy-eylem">
      <button type="submit" class="btn btn-primary">Kaydet</button>
      <a class="btn btn-ghost" href="<?= h($geri) ?>">Vazgeç</a>
    </div>
  </form>
<?php endif; ?>

<?php if (!$subeler): ?>
  <p class="muted" style="margin-top:14px">Bu görünümde şube yok. <?= $aktifSirketler ? '<b>＋ Şube Ekle</b> ile girebilirsiniz.' : '' ?></p>
<?php else: ?>
  <div class="table-wrap" style="margin-top:14px">
    <table class="table">
      <thead><tr><th>Şirket</th><th>Şube kodu</th><th>Ad</th><th class="sy-gizle">Şehir</th><th class="sy-gizle">Logo işyeri</th><th>Durum</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($subeler as $b): $pasif = (int)$b['aktif'] !== 1 || (int)($b['sirket_aktif'] ?? 1) !== 1; ?>
        <tr class="<?= $pasif ? 'sy-pasif' : '' ?>">
          <td><?= h((string)($b['sirket_kod'] ?? '?')) ?></td>
          <td><b><?= h($b['kod']) ?></b></td>
          <td><?= h($b['ad']) ?></td>
          <td class="sy-gizle"><?= h($b['sehir'] ?? '') ?></td>
          <td class="sy-gizle"><?= h($b['logo_isyeri_no'] ?? '') ?></td>
          <td><span class="sy-rozet <?= (int)$b['aktif'] === 1 ? '' : 'sy-rozet-pasif' ?>"><?= (int)$b['aktif'] === 1 ? 'Aktif' : 'Pasif' ?></span></td>
          <td>
            <div class="sy-eylem">
              <a class="btn btn-sm btn-ghost" href="sube_tanimlari.php?duzenle=<?= (int)$b['id'] ?><?= $suz ? '&amp;sirket=' . $suz : '' ?>">Düzenle</a>
              <form method="post" onsubmit="return confirm('<?= (int)$b['aktif'] === 1 ? 'Şube pasife alınsın mı?' : 'Şube yeniden aktif edilsin mi?' ?>')">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="eylem" value="aktif">
                <input type="hidden" name="suz" value="<?= (int)($suz ?? 0) ?>">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="aktif" value="<?= (int)$b['aktif'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="btn btn-sm btn-ghost"><?= (int)$b['aktif'] === 1 ? 'Pasife al' : 'Aktif et' ?></button>
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
