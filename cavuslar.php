<?php
// =========================================================
// cavuslar.php — Çavuş Listesi (Günlük İşçi, Faz 1)
//
// personel.php ile AYNI liste deseni (arama/filtre/masaüstü tablo + mobil
// kart). Çavuş, kalıcı personelden AYRI bir varlıktır (bkz. config/
// pdks_gunluk.php başlığı) — bu yüzden employees tablosuna DOKUNMAZ, kendi
// foremen tablosunu kullanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('foremen');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
// Faz 9C / H-02: listede "Süre" sütunu yalnız Faz 8B şeması hazırsa gösterilir.
$faz8bHazir = pdks_faz8b_sema_hazir($pdo);

$q       = trim($_GET['q'] ?? '');
$durum_f = trim($_GET['durum'] ?? '');   // '' | 'aktif' | 'pasif'

$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = "(code LIKE ? OR name LIKE ? OR phone LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
}
if ($durum_f === 'aktif') { $where[] = "is_active = 1"; }
elseif ($durum_f === 'pasif') { $where[] = "is_active = 0"; }
$whereSql = implode(' AND ', $where);

$cavuslar = [];
try {
    $st = $pdo->prepare("SELECT * FROM foremen WHERE $whereSql ORDER BY is_active DESC, name ASC LIMIT 300");
    $st->execute($params);
    $cavuslar = $st->fetchAll();
} catch (PDOException $e) {
    set_flash('error', 'Günlük İşçi tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "Günlük İşçi Tablolarını Oluştur" demesi gerekiyor.');
}

render_header('Çavuşlar');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>👷 Çavuşlar</h1>
    <div class="page-head-actions">
        <?php if (pdks_gunluk_can('foremen')): ?>
        <a href="cavus_form.php" class="btn btn-primary">+ Yeni Çavuş</a>
        <?php endif; ?>
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<form method="get" class="pdks-filter-bar">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Kod, ad veya telefon ara…">
    <select name="durum">
        <option value="">Tüm durumlar</option>
        <option value="aktif" <?= $durum_f === 'aktif' ? 'selected' : '' ?>>Aktif</option>
        <option value="pasif" <?= $durum_f === 'pasif' ? 'selected' : '' ?>>Pasif</option>
    </select>
    <button type="submit" class="btn">Filtrele</button>
    <?php if ($q !== '' || $durum_f !== ''): ?>
    <a href="cavuslar.php" class="btn btn-ghost">Temizle</a>
    <?php endif; ?>
</form>

<?php if (empty($cavuslar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">👷</span>
    <p><?= $q === '' && $durum_f === '' ? 'Henüz çavuş kaydı yok.' : 'Bu filtrelerle çavuş bulunamadı.' ?></p>
    <?php if (pdks_gunluk_can('foremen')): ?>
    <a href="cavus_form.php" class="btn btn-primary">+ İlk Çavuşu Ekle</a>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table">
<thead><tr>
    <th>Kod</th>
    <th>Ad Soyad</th>
    <th>Telefon</th>
    <?php if ($faz8bHazir): ?><th>Normal Süre</th><?php endif; ?>
    <th>Durum</th>
    <th class="actions-col">İşlem</th>
</tr></thead>
<tbody>
<?php foreach ($cavuslar as $c): ?>
<tr>
    <td class="pdks-uid"><?= h($c['code']) ?></td>
    <td class="pdks-row-name"><?= h($c['name']) ?></td>
    <td class="muted"><?= h($c['phone'] ?: '—') ?></td>
    <?php if ($faz8bHazir): ?><td class="muted"><?= h(pdks_faz8b_dakika_etiket((int)($c['normal_work_minutes'] ?? 540))) ?></td><?php endif; ?>
    <td><span class="pdks-badge <?= $c['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $c['is_active'] ? 'Aktif' : 'Pasif' ?></span></td>
    <td class="actions-col">
        <a href="cavus_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm">Görüntüle / Düzenle</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only">
<?php foreach ($cavuslar as $c): ?>
<a href="cavus_form.php?id=<?= (int)$c['id'] ?>" class="pdks-card-item" style="text-decoration:none;color:inherit">
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($c['name']) ?></div>
            <div class="pdks-row-sub"><?= h($c['code']) ?><?= $c['phone'] ? ' · ' . h($c['phone']) : '' ?></div>
        </div>
        <span class="pdks-badge <?= $c['is_active'] ? 'pdks-badge-aktif' : 'pdks-badge-pasif' ?>"><?= $c['is_active'] ? 'Aktif' : 'Pasif' ?></span>
    </div>
</a>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php render_footer(); ?>
