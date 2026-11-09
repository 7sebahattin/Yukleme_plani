<?php
// Depo Tanımları (Faz 0.1, v505): MEVCUT depo tanımlarının ek bilgisi — tür (kendi/fason/anlaşmalı), şube, kullanabilen şirketler.
// Depo ADI ekleme/yeniden adlandırma/silme eskisi gibi Tanımlar sayfasındadır (definitions.php?type=depo); buradan DEĞİŞMEZ.
// Bu sayfa v439'a kadar oraya yönlendiriyordu; artık kendi ekranı var. Yalnız yönetici. Şema yalnız migrate.php kartından.
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
$form = null;           // hata sonrası girilenler
$duzenle_id = null;

if ($hazir && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if ((string)($_POST['eylem'] ?? '') === 'kaydet') {
        $depo = (int)($_POST['depo_id'] ?? 0);
        $sube = (int)($_POST['sube_id'] ?? 0) ?: null;
        $sirk = isset($_POST['sirketler']) && is_array($_POST['sirketler']) ? array_map('intval', $_POST['sirketler']) : [];
        $r = depo_detay_kaydet($pdo, $depo, (string)($_POST['tur'] ?? ''), $sube, (string)($_POST['aciklama'] ?? ''), $sirk, $uid);
        if ($r['ok']) {
            set_flash('success', 'Depo bilgisi kaydedildi.');
            header('Location: depo_tanimlari.php');
            exit;
        }
        $hatalar = $r['hatalar'];
        $duzenle_id = $depo;
        $form = ['tur' => (string)($_POST['tur'] ?? 'kendi'), 'sube_id' => $sube, 'aciklama' => (string)($_POST['aciklama'] ?? ''), 'sirketler' => $sirk];
    }
}

$depolar = $hazir ? depo_listele($pdo) : [];
if ($hazir && $duzenle_id === null && isset($_GET['duzenle'])) $duzenle_id = (int)$_GET['duzenle'];
$duzenlenen = null;
foreach ($depolar as $d) { if ($d['id'] === $duzenle_id) $duzenlenen = $d; }
if ($duzenlenen !== null && $form !== null) { $duzenlenen = array_merge($duzenlenen, $form); }

$sirketler = $hazir ? sirket_listele($pdo) : [];
$sirketAd = [];
foreach ($sirketler as $s) $sirketAd[(int)$s['id']] = $s;
$subeler = $hazir ? sube_listele($pdo) : [];
$subeAd = [];
foreach ($subeler as $b) $subeAd[(int)$b['id']] = $b;

render_header('Depo Tanımları');
render_flash();
sy_sekmeler('depo');
if (!$hazir) { sy_tablo_yok(); render_footer(); exit; }
?>
<div class="card">
  <div class="sy-baslik">
    <h2>Depolar</h2>
    <a class="btn btn-ghost" href="definitions.php?type=depo">Depo adı ekle / değiştir →</a>
  </div>
  <p class="muted">Depo adları Tanımlar sayfasında tutulur ve değişmez. Burada her depoya <b>tür</b> (kendi / fason / anlaşmalı), bağlı olduğu <b>şube</b> ve onu kullanabilecek <b>şirketler</b> eklenir. Hiç şirket seçilmezse depoyu tüm aktif şirketler kullanabilir.</p>

<?php if ($duzenlenen !== null): $d = $duzenlenen; ?>
  <?php sy_hatalar($hatalar); ?>
  <form method="post" class="sy-form" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="eylem" value="kaydet">
    <input type="hidden" name="depo_id" value="<?= (int)$d['id'] ?>">
    <h3 style="margin:8px 0 0">Depo: <?= h($d['ad']) ?></h3>
    <div class="sy-grid">
      <label class="sy-alan sy-genis"><span class="sy-etiket">Tür</span>
        <span class="sy-secim">
        <?php foreach (SIRKET_DEPO_TURLERI as $k => $ad): ?>
          <label><input type="radio" name="tur" value="<?= h($k) ?>"<?= $d['tur'] === $k ? ' checked' : '' ?>> <?= h($ad) ?></label>
        <?php endforeach; ?>
        </span>
      </label>
      <label class="sy-alan" for="sy_sube_id"><span class="sy-etiket">Bağlı şube</span>
        <select id="sy_sube_id" name="sube_id">
          <option value="">— Şubesi yok (ör. başkasının tesisi) —</option>
          <?php foreach ($subeler as $b): if ((int)$b['aktif'] !== 1 && (int)$d['sube_id'] !== (int)$b['id']) continue; ?>
            <option value="<?= (int)$b['id'] ?>"<?= (int)$d['sube_id'] === (int)$b['id'] ? ' selected' : '' ?>><?= h(($b['sirket_kod'] ?? '?') . ' / ' . $b['kod'] . ' — ' . $b['ad']) ?><?= (int)$b['aktif'] === 1 ? '' : ' (pasif)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php sy_alan('aciklama', 'Açıklama', $d['aciklama'], ['max' => 255, 'wide' => 1, 'hint' => 'Fason/anlaşmalı depoda tesis sahibi, iletişim vb. serbest not.']); ?>
      <div class="sy-alan sy-genis"><span class="sy-etiket">Kullanabilen şirketler</span>
        <?php if (!$sirketler): ?>
          <span class="muted">Henüz şirket yok; <a href="program_tanimlari.php?yeni=1">şirket ekleyin</a>.</span>
        <?php else: ?>
        <span class="sy-secim">
          <?php foreach ($sirketler as $s): if ((int)$s['aktif'] !== 1 && !in_array((int)$s['id'], $d['sirketler'], true)) continue; ?>
            <label><input type="checkbox" name="sirketler[]" value="<?= (int)$s['id'] ?>"<?= in_array((int)$s['id'], $d['sirketler'], true) ? ' checked' : '' ?>> <?= h($s['kod'] . ' — ' . ($s['kisa_ad'] ?: $s['unvan'])) ?></label>
          <?php endforeach; ?>
        </span>
        <span class="sy-ipucu">Hiçbiri seçili değilse depo kısıtsızdır (tüm aktif şirketler kullanır).</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="sy-eylem">
      <button type="submit" class="btn btn-primary">Kaydet</button>
      <a class="btn btn-ghost" href="depo_tanimlari.php">Vazgeç</a>
    </div>
  </form>
<?php elseif ($duzenle_id !== null): ?>
  <p class="flash flash-error">Depo bulunamadı.</p>
<?php endif; ?>

<?php if (!$depolar): ?>
  <p class="muted" style="margin-top:14px">Henüz depo tanımı yok. <a href="definitions.php?type=depo">Tanımlar → Depo</a> sayfasından ekleyin.</p>
<?php else: ?>
  <div class="table-wrap" style="margin-top:14px">
    <table class="table">
      <thead><tr><th>Depo</th><th>Tür</th><th class="sy-gizle">Şube</th><th>Kullanabilen şirketler</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($depolar as $d): ?>
        <tr class="<?= $d['aktif'] === 1 ? '' : 'sy-pasif' ?>">
          <td><b><?= h($d['ad']) ?></b><?= $d['aktif'] === 1 ? '' : ' <span class="sy-rozet sy-rozet-pasif">Pasif</span>' ?></td>
          <td><?php if ($d['tur_tanimli']): ?><?= h(SIRKET_DEPO_TURLERI[$d['tur']] ?? $d['tur']) ?><?php else: ?><span class="sy-rozet sy-rozet-gri">Girilmedi</span><?php endif; ?></td>
          <td class="sy-gizle"><?php if ($d['sube_id'] !== null && isset($subeAd[$d['sube_id']])): $b = $subeAd[$d['sube_id']]; ?><?= h(($b['sirket_kod'] ?? '?') . ' / ' . $b['kod']) ?><?php else: ?>—<?php endif; ?></td>
          <td>
            <?php if (!$d['sirketler']): ?><span class="muted">Kısıtsız (tüm aktif şirketler)</span>
            <?php else: foreach ($d['sirketler'] as $sid): ?>
              <span style="white-space:nowrap;margin-right:8px"><?= isset($sirketAd[$sid]) ? sy_kod_rozet($sirketAd[$sid]['renk'] ?? null, (string)$sirketAd[$sid]['kod']) : '?' ?></span>
            <?php endforeach; endif; ?>
          </td>
          <td><a class="btn btn-sm btn-ghost" href="depo_tanimlari.php?duzenle=<?= (int)$d['id'] ?>">Düzenle</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</div>
<?php render_footer();
