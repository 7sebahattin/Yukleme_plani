<?php
// =========================================================
// definitions.php - Malzeme/Tanım yönetimi
// Ekle / Düzenle / Aktif-Pasif / Sil
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$type_labels = definition_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $type = (string)($_POST['type'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $unit = num($_POST['unit_dara_kg'] ?? 0);
            if (!isset($type_labels[$type]) || $name === '') {
                throw new RuntimeException('Tür ve isim zorunlu.');
            }
            $st = db()->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active)
                                 VALUES (:t, :n, :u, 1)");
            $st->execute([':t' => $type, ':n' => $name, ':u' => $unit]);
            set_flash('success', 'Tanım eklendi.');

        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $unit = num($_POST['unit_dara_kg'] ?? 0);
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $name === '') throw new RuntimeException('Geçersiz veri.');
            $st = db()->prepare("UPDATE material_definitions
                                 SET name=:n, unit_dara_kg=:u, is_active=:a
                                 WHERE id=:id");
            $st->execute([':n' => $name, ':u' => $unit, ':a' => $is_active, ':id' => $id]);
            set_flash('success', 'Tanım güncellendi.');

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("UPDATE material_definitions
                           SET is_active = 1 - is_active
                           WHERE id=:id")->execute([':id' => $id]);

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // Güvenli silme: kullanılıyorsa sadece pasifleştir
            $st = db()->prepare("SELECT
                (SELECT COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id=:i OR palet_tipi_id=:i)
                + (SELECT COUNT(*) FROM pallet_materials WHERE material_id=:i) AS used");
            $st->execute([':i' => $id]);
            $used = (int)$st->fetchColumn();
            if ($used > 0) {
                db()->prepare("UPDATE material_definitions SET is_active=0 WHERE id=:i")
                    ->execute([':i' => $id]);
                set_flash('success', 'Tanım kullanımda olduğu için silinmedi, pasifleştirildi.');
            } else {
                db()->prepare("DELETE FROM material_definitions WHERE id=:i")
                    ->execute([':i' => $id]);
                set_flash('success', 'Tanım silindi.');
            }
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: definitions.php' . (!empty($_POST['filter']) ? '?type=' . urlencode($_POST['filter']) : ''));
    exit;
}

$filter = $_GET['type'] ?? '';
$where = '';
$params = [];
if ($filter !== '' && isset($type_labels[$filter])) {
    $where = "WHERE type = :t";
    $params[':t'] = $filter;
}
$st = db()->prepare("SELECT * FROM material_definitions $where ORDER BY type, name");
$st->execute($params);
$defs = $st->fetchAll();

$grouped = [];
foreach ($defs as $d) $grouped[$d['type']][] = $d;

render_header('Tanımlar');
render_flash();
?>
<div class="page-head">
    <div>
        <h1>Tanımlar / Malzemeler</h1>
        <p class="muted">Yükleme planında seçilebilecek kalemler ve birim dara kg değerleri.</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Liste</a>
</div>

<!-- Tür filtresi -->
<div class="filter-pills">
    <a class="pill <?= $filter === '' ? 'active' : '' ?>" href="definitions.php">Tümü</a>
    <?php foreach ($type_labels as $tk => $tl): ?>
        <a class="pill <?= $filter === $tk ? 'active' : '' ?>"
           href="definitions.php?type=<?= h($tk) ?>"><?= h($tl) ?></a>
    <?php endforeach; ?>
</div>

<!-- Yeni ekleme -->
<section class="card">
    <h2>Yeni Tanım Ekle</h2>
    <form method="post" class="grid">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="filter" value="<?= h($filter) ?>">
        <label>Tür
            <select name="type" required>
                <?php foreach ($type_labels as $tk => $tl): ?>
                    <option value="<?= h($tk) ?>" <?= $filter === $tk ? 'selected' : '' ?>><?= h($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Adı
            <input type="text" name="name" required>
        </label>
        <label>Birim Dara (kg)
            <input type="text" name="unit_dara_kg" inputmode="decimal" value="0">
        </label>
        <div class="form-foot inline">
            <button class="btn btn-primary">Ekle</button>
        </div>
    </form>
</section>

<!-- Liste -->
<?php foreach ($grouped as $type => $items): ?>
    <section class="card">
        <h2><?= h($type_labels[$type] ?? $type) ?> <span class="muted">(<?= count($items) ?>)</span></h2>

        <div class="table-wrap pc-only">
            <table class="data-table small">
                <thead>
                <tr><th>Adı</th><th class="num">Birim Dara (kg)</th><th>Durum</th><th class="actions-col">İşlemler</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $d): ?>
                    <tr>
                        <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <td><input type="text" name="name" value="<?= h($d['name']) ?>" required></td>
                        <td class="num"><input type="text" name="unit_dara_kg" inputmode="decimal" class="num"
                                               value="<?= h($d['unit_dara_kg']) ?>"></td>
                        <td><label class="switch"><input type="checkbox" name="is_active"
                                                          <?= $d['is_active'] ? 'checked' : '' ?>>
                            <span><?= $d['is_active'] ? 'Aktif' : 'Pasif' ?></span></label></td>
                        <td class="actions-col">
                            <button class="btn btn-sm btn-primary">Kaydet</button>
                        </form>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <input type="hidden" name="filter" value="<?= h($filter) ?>">
                            <button class="btn btn-sm btn-danger"
                                    onclick="return confirm('Silinsin mi? Kullanımdaysa pasif yapılır.');">Sil</button>
                        </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobil -->
        <div class="card-list mobile-only">
            <?php foreach ($items as $d): ?>
                <div class="def-card">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <label>Adı
                            <input type="text" name="name" value="<?= h($d['name']) ?>" required>
                        </label>
                        <label>Birim Dara (kg)
                            <input type="text" name="unit_dara_kg" inputmode="decimal"
                                   value="<?= h($d['unit_dara_kg']) ?>">
                        </label>
                        <label class="switch">
                            <input type="checkbox" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>>
                            <span>Aktif</span>
                        </label>
                        <div class="form-foot inline">
                            <button class="btn btn-primary btn-sm">Kaydet</button>
                        </div>
                    </form>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                        <input type="hidden" name="filter" value="<?= h($filter) ?>">
                        <button class="btn btn-danger btn-sm"
                                onclick="return confirm('Silinsin mi?');">Sil</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php if (empty($grouped)): ?>
    <div class="empty"><p>Tanım yok.</p></div>
<?php endif; ?>

<?php render_footer(); ?>
