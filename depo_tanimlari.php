<?php
// =========================================================
// depo_tanimlari.php — Depo Tanımları (v574). Yalnız yönetici.
// Depo ekle / düzelt / sil + depoya kullanıcı atama. Kurallar: config/depo_core.php (depo-yonetimi skill'i).
// Şube kavramı YOK. Eski depo adı bir yeni depoya verilemez (depo_eski_adlar defteri).
// Şema yalnız migrate.php › "Depolar" kartından kurulur; tablo yoksa kurulum notu gösterilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo   = db();
$hazir = depo_hazir($pdo);

if ($hazir && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    $id    = (int)($_POST['id'] ?? 0);
    if ($islem === 'kaydet') {
        $g = [
            'ad'           => (string)($_POST['ad'] ?? ''),
            'renk'         => empty($_POST['renk_yok']) ? (string)($_POST['renk'] ?? '') : '',
            'aktif'        => !empty($_POST['aktif']) ? 1 : 0,
            'kullanicilar' => array_map('intval', (array)($_POST['kullanicilar'] ?? [])),
        ];
        if ($id > 0) {
            $r = depo_guncelle($pdo, $id, $g);
            if ($r['ok']) { set_flash('success', 'Depo güncellendi.' . ($r['aktarilan'] > 0 ? ' Ad değişikliği ' . $r['aktarilan'] . ' kayda yansıtıldı.' : '')); header('Location: depo_tanimlari.php'); exit; }
        } else {
            $r = depo_ekle($pdo, $g);
            if ($r['ok']) { set_flash('success', 'Depo eklendi.'); header('Location: depo_tanimlari.php'); exit; }
        }
        set_flash('error', $r['hata']);
        header('Location: depo_tanimlari.php' . ($id > 0 ? '?duzenle=' . $id : '?yeni=1'));
        exit;
    }
    if ($islem === 'sil' && $id > 0) {
        $r = depo_sil($pdo, $id);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Depo silindi.' : $r['hata']);
        header('Location: depo_tanimlari.php' . ($r['ok'] ? '' : '?duzenle=' . $id));
        exit;
    }
    header('Location: depo_tanimlari.php');
    exit;
}

$gecis     = $hazir && depo_gecis_aktif($pdo);
$depolar   = $hazir ? depo_hepsi($pdo) : [];
$kullanim  = $hazir ? depo_kullanim_toplam($pdo) : [];
$atamalar  = $hazir ? depo_kullanici_listesi($pdo) : [];
$kullanicilar = [];
try { $kullanicilar = $pdo->query('SELECT id, username, display_name FROM users WHERE is_active = 1 ORDER BY display_name, username')->fetchAll(); } catch (PDOException $e) {}
$adMap = [];
foreach ($kullanicilar as $u) $adMap[(int)$u['id']] = (string)($u['display_name'] ?: $u['username']);

$duzenleId = (int)($_GET['duzenle'] ?? 0);
$duzenle   = $duzenleId > 0 && $hazir ? depo_getir($pdo, $duzenleId) : null;
$formAcik  = $hazir && ($duzenle !== null || isset($_GET['yeni']));
$secili    = $duzenle ? ($atamalar[(int)$duzenle['id']] ?? []) : [];

render_header('Depo Tanımları');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/depo.css?v=' . @filemtime(__DIR__ . '/assets/depo.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>Depo Tanımları</h1>
    <?php if ($hazir && !$formAcik): ?><div class="page-head-actions"><a href="depo_tanimlari.php?yeni=1" class="btn btn-primary">+ Yeni Depo</a></div><?php endif; ?>
</div>

<?php if (!$hazir): ?>
<div class="card depo-not">
    <strong>Depo tabloları kurulmamış.</strong>
    <p>Yönetici olarak <a href="migrate.php#depolar">Şema Migrasyon › Depolar</a> kartından tabloları oluşturun. O zamana kadar sistem eski depo listesini kullanır.</p>
</div>
<?php else: ?>

<?php if (!$gecis): ?>
<div class="card depo-not depo-not-uyari">
    <strong>Geçiş henüz tamamlanmadı.</strong>
    <p>Buradaki depoları şimdiden tanımlayıp kullanıcılara atayabilirsiniz; ancak sistem, geçiş yapılana kadar <em>eski</em> depo listesini kullanmaya devam eder.
    Hazır olunca <a href="migrate.php#depolar">Şema Migrasyon › Depolar</a> kartından geçişi tamamlayın (eski depolar ve verileri silinmez, gizlenir).</p>
</div>
<?php endif; ?>

<?php if ($formAcik): ?>
<div class="card depo-form">
    <h2><?= $duzenle ? 'Depoyu Düzenle' : 'Yeni Depo' ?><?php if ($duzenle): ?> <span class="depo-kod"><?= h($duzenle['kod']) ?></span><?php endif; ?></h2>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="islem" value="kaydet">
        <input type="hidden" name="id" value="<?= $duzenle ? (int)$duzenle['id'] : 0 ?>">

        <label class="form-label" for="depoAd">Depo adı</label>
        <input type="text" id="depoAd" name="ad" required maxlength="100" value="<?= h($duzenle['ad'] ?? '') ?>" placeholder="Örn. MERKEZ DEPO">
        <?php if ($duzenle): ?><p class="depo-not-kucuk">Adı değiştirirseniz bu depoya ait tüm kayıtlar yeni ada çekilir. Kod (<?= h($duzenle['kod']) ?>) hiç değişmez.</p><?php endif; ?>

        <div class="depo-satir">
            <label class="depo-chk"><input type="checkbox" name="aktif" value="1" <?= !$duzenle || (int)$duzenle['aktif'] ? 'checked' : '' ?>> Aktif</label>
            <span class="depo-renk">
                <label for="depoRenk">Renk</label>
                <input type="color" id="depoRenk" name="renk" value="<?= h($duzenle['renk'] ?? '#2563eb') ?>">
                <label class="depo-chk"><input type="checkbox" name="renk_yok" value="1" <?= $duzenle && empty($duzenle['renk']) ? 'checked' : '' ?>> Otomatik</label>
            </span>
        </div>

        <div class="form-label">Bu depoyu kullanacak kullanıcılar</div>
        <p class="depo-not-kucuk">Hiçbir depoya atanmamış kullanıcı <strong>tüm depoları</strong> görür; atanan kullanıcı yalnız atandığı depo(lar)ı görür. Yönetici her zaman tümünü görür.</p>
        <div class="depo-kul-grid">
            <?php foreach ($kullanicilar as $u): ?>
            <label class="depo-chk"><input type="checkbox" name="kullanicilar[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $secili, true) ? 'checked' : '' ?>> <?= h($adMap[(int)$u['id']]) ?></label>
            <?php endforeach; ?>
            <?php if (!$kullanicilar): ?><span class="depo-not-kucuk">Aktif kullanıcı yok.</span><?php endif; ?>
        </div>

        <div class="depo-aksiyon">
            <button type="submit" class="btn btn-primary">Kaydet</button>
            <a href="depo_tanimlari.php" class="btn btn-ghost">Vazgeç</a>
        </div>
    </form>
    <?php if ($duzenle): $n = $kullanim[(string)$duzenle['ad_anahtar']] ?? 0; ?>
    <form method="post" class="depo-sil" onsubmit="return confirm('Bu depo silinsin mi? Bu işlem geri alınamaz.');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="islem" value="sil">
        <input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>">
        <?php if ($n > 0): ?>
            <p class="depo-not-kucuk">Bu depo <?= (int)$n ?> kayıtta kullanılıyor; silinemez. Gizlemek için "Aktif"i kapatın.</p>
        <?php else: ?>
            <button type="submit" class="btn btn-ghost depo-sil-btn">🗑 Depoyu Sil</button>
        <?php endif; ?>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card depo-liste-kart">
<?php if (!$depolar): ?>
    <p class="depo-not-kucuk">Henüz depo tanımlı değil. "+ Yeni Depo" ile ilk depoyu ekleyin.</p>
<?php else: ?>
    <ul class="depo-liste">
    <?php foreach ($depolar as $d):
        $kul = array_map(fn($uid) => $adMap[$uid] ?? ('#' . $uid), $atamalar[(int)$d['id']] ?? []);
        $n = $kullanim[(string)$d['ad_anahtar']] ?? 0;
        $renk = depot_color((string)$d['ad']); ?>
        <li class="depo-oge<?= (int)$d['aktif'] ? '' : ' depo-pasif' ?>">
            <span class="depo-nokta" style="background:<?= h($renk) ?>"></span>
            <div class="depo-bilgi">
                <div class="depo-ad"><?= h($d['ad']) ?> <span class="depo-kod"><?= h($d['kod']) ?></span><?php if (!(int)$d['aktif']): ?> <span class="depo-rozet">Pasif</span><?php endif; ?></div>
                <div class="depo-alt">
                    <?= $kul ? '👥 ' . h(implode(', ', $kul)) : 'Atanmış kullanıcı yok (herkes görür)' ?>
                    <?php if ($n > 0): ?> · <?= (int)$n ?> kayıt<?php endif; ?>
                </div>
            </div>
            <a class="btn btn-sm btn-ghost" href="depo_tanimlari.php?duzenle=<?= (int)$d['id'] ?>">Düzenle</a>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer();
