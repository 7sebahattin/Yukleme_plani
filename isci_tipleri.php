<?php
// =========================================================
// isci_tipleri.php — İşçi Tipi/Kategori Master (Günlük İşçi, Faz 1)
//
// Kasıtlı olarak KÜÇÜK: gender ENUM değil, serbest kod/ad çifti (kullanıcının
// açık talimatı) — ileride Paketleme/Yükleme/Forklift/Usta/Gece Vardiyası gibi
// tipler KOD DEĞİŞİKLİĞİ gerektirmeden buradan eklenebilsin diye. Sidebar'a
// AYRI bir madde olarak eklenmedi (permission fragmentasyonunu artırmamak
// için attendance.worker_cards'a bağlı) — isci_kartlari.php'nin baş
// kısmındaki ikincil bağlantıdan açılır (pdks_nfc_test.php'nin
// personel_kartlar.php'den açılma deseniyle aynı).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('worker_cards');
pdks_gunluk_migrate();

$pdo = db();
$hata = ''; $basari = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_gunluk('worker_cards');
    $action = trim($_POST['action'] ?? '');

    if ($action === 'ekle') {
        $sonuc = pdks_gunluk_tip_olustur($_POST['code'] ?? '', $_POST['name'] ?? '', $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_tipleri.php?ok=' . urlencode('İşçi tipi eklendi.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'Eklenemedi.';
    } elseif ($action === 'aktiflik') {
        $id = (int)($_POST['id'] ?? 0);
        $aktif = ($_POST['aktif'] ?? '') === '1';
        $sonuc = pdks_gunluk_tip_aktiflik($id, $aktif, $pdo);
        if ($sonuc['ok']) {
            header('Location: isci_tipleri.php?ok=' . urlencode('Durum güncellendi.'));
            exit;
        }
        $hata = $sonuc['hata'] ?? 'İşlem yapılamadı.';
    }
}
if ($hata === '' && isset($_GET['ok'])) $basari = trim($_GET['ok']);

$tipler = pdks_gunluk_tip_listele(false, $pdo);

render_header('İşçi Tipleri');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>🏷 İşçi Tipleri</h1>
    <div class="page-head-actions">
        <a href="isci_kartlari.php" class="btn btn-ghost">← İşçi Kartları</a>
    </div>
</div>

<?php if ($basari !== ''): ?><div class="flash flash-success"><?= h($basari) ?></div><?php endif; ?>
<?php if ($hata !== ''): ?><div class="flash flash-error"><?= h($hata) ?></div><?php endif; ?>

<div class="card" style="padding:16px 18px;margin-bottom:20px">
    <h2 style="margin-top:0;font-size:1rem">Yeni Tip Ekle</h2>
    <p class="muted" style="margin-top:-6px;font-size:.85rem">
        Cinsiyet sabit değildir — ileride "Paketleme", "Forklift", "Usta", "Gece Vardiyası" gibi
        serbest kategoriler de eklenebilir.
    </p>
    <form method="post" class="pdks-form-grid" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="ekle">
        <label>
            <span class="form-label">Kod *</span>
            <input type="text" name="code" required maxlength="30" placeholder="ör. FORKLIFT" style="text-transform:uppercase">
        </label>
        <label>
            <span class="form-label">Ad *</span>
            <input type="text" name="name" required maxlength="80" placeholder="ör. Forklift Operatörü">
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary">+ Ekle</button></div>
    </form>
</div>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr><th>Kod</th><th>Ad</th><th>Durum</th><th class="actions-col">İşlem</th></tr></thead>
<tbody>
<?php foreach ($tipler as $t): ?>
<tr>
    <td class="pdks-uid"><?= h($t['code']) ?></td>
    <td class="pdks-row-name"><?= h($t['name']) ?></td>
    <td><span class="pdks-badge <?= $t['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $t['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
    <td class="actions-col">
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="aktiflik">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="aktif" value="<?= $t['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm"><?= $t['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($tipler as $t): ?>
<div class="pdks-card-item">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($t['name']) ?></div>
            <div class="pdks-row-sub"><?= h($t['code']) ?></div>
        </div>
        <span class="pdks-badge <?= $t['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $t['is_active'] ? 'Aktif' : 'Pasif' ?></span>
    </div>
    <div class="pdks-card-actions">
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="aktiflik">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <input type="hidden" name="aktif" value="<?= $t['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm"><?= $t['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php render_footer(); ?>
