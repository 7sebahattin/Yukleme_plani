<?php
// =========================================================
// definitions.php - Tanım yönetimi
// 5 bölüm: Firmalar · Depolar · Bölgeler · Ürünler · Malzemeler
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$type_labels = definition_types();
$cat_types   = ['firma', 'depo', 'bolge', 'urun', 'lokasyon'];
$cat_labels  = ['firma' => 'Firmalar', 'depo' => 'Depolar', 'bolge' => 'Bölgeler', 'urun' => 'Ürünler', 'lokasyon' => 'Lokasyonlar'];
$cat_icons   = ['firma' => '🏢', 'depo' => '🏭', 'bolge' => '🗺️', 'urun' => '🌿', 'lokasyon' => '📍'];
$mat_types   = array_filter($type_labels, fn($k) => !in_array($k, $cat_types, true), ARRAY_FILTER_USE_KEY);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action      = $_POST['action'] ?? '';
    $back_filter = (string)($_POST['filter'] ?? '');

    try {
        if ($action === 'create') {
            $type = (string)($_POST['type'] ?? '');
            $name = normalize_text_v2((string)($_POST['name'] ?? ''));
            $unit = num($_POST['unit_dara_kg'] ?? 0);
            if (!isset($type_labels[$type]) || $name === '') throw new RuntimeException('Tür ve isim zorunlu.');
            $existing = db()->prepare("SELECT name FROM material_definitions WHERE type = ?");
            $existing->execute([$type]);
            foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $n) {
                if (normalize_text_v2($n) === $name) {
                    throw new RuntimeException('"' . $name . '" bu türde zaten mevcut.');
                }
            }
            db()->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES (?,?,?,1)")
                ->execute([$type, $name, $unit]);
            set_flash('success', '"' . $name . '" eklendi.');

        } elseif ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $name      = normalize_text_v2((string)($_POST['name'] ?? ''));
            $unit      = num($_POST['unit_dara_kg'] ?? 0);
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $name === '') throw new RuntimeException('Geçersiz veri.');
            $row = db()->prepare("SELECT type FROM material_definitions WHERE id = ?");
            $row->execute([$id]);
            $type = (string)($row->fetchColumn() ?: '');
            if ($type !== '') {
                $others = db()->prepare("SELECT name FROM material_definitions WHERE type = ? AND id != ?");
                $others->execute([$type, $id]);
                foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    if (normalize_text_v2($n) === $name) {
                        throw new RuntimeException('Bu türde aynı isimde başka bir tanım zaten var.');
                    }
                }
            }
            db()->prepare("UPDATE material_definitions SET name=?, unit_dara_kg=?, is_active=? WHERE id=?")
                ->execute([$name, $unit, $is_active, $id]);
            set_flash('success', 'Güncellendi.');

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare("UPDATE material_definitions SET is_active = 1-is_active WHERE id=?")->execute([$id]);

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare("SELECT
                (SELECT COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id=? OR palet_tipi_id=?)
                + (SELECT COUNT(*) FROM pallet_materials WHERE material_id=?) AS used");
            $st->execute([$id, $id, $id]);
            $used = (int)$st->fetchColumn();
            if ($used > 0) {
                db()->prepare("UPDATE material_definitions SET is_active=0 WHERE id=?")->execute([$id]);
                set_flash('success', 'Kullanımda olduğu için pasifleştirildi.');
            } else {
                db()->prepare("DELETE FROM material_definitions WHERE id=?")->execute([$id]);
                set_flash('success', 'Silindi.');
            }
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: definitions.php?type=' . urlencode($back_filter));
    exit;
}

// ── Veri çekme ──────────────────────────────────────
$filter = $_GET['type'] ?? '';
if ($filter !== '' && !isset($type_labels[$filter])) $filter = '';
$is_cat = in_array($filter, $cat_types, true);

if ($is_cat) {
    $st = db()->prepare("SELECT * FROM material_definitions WHERE type = ? ORDER BY name");
    $st->execute([$filter]);
} else {
    $pls = implode(',', array_fill(0, count($cat_types), '?'));
    $st  = db()->prepare("SELECT * FROM material_definitions WHERE type NOT IN ($pls) ORDER BY type, name");
    $st->execute($cat_types);
}
$defs    = $st->fetchAll();
$grouped = [];
foreach ($defs as $d) $grouped[$d['type']][] = $d;

// Kullanım sayısı (kategori bölümleri için)
$usage_by_name = [];
if ($is_cat) {
    $uq = match($filter) {
        'firma'       => "SELECT firma, COUNT(*) FROM loading_records WHERE firma != '' GROUP BY firma",
        'depo'        => "SELECT depo, COUNT(DISTINCT loading_record_id) FROM loading_pallets WHERE depo != '' GROUP BY depo",
        'urun'        => "SELECT urun, COUNT(*) FROM loading_records WHERE urun != '' GROUP BY urun",
        'bolge'       => "SELECT bolge, COUNT(*) FROM loading_records WHERE bolge != '' GROUP BY bolge",
        'lokasyon'    => "SELECT name, COUNT(*) FROM (SELECT geldigi_yer AS name FROM kantar_fisleri WHERE geldigi_yer!='' UNION ALL SELECT gittigi_yer FROM kantar_fisleri WHERE gittigi_yer!='') t GROUP BY name",
        default       => null,
    };
    if ($uq) $usage_by_name = db()->query($uq)->fetchAll(PDO::FETCH_KEY_PAIR);
}

render_header('Tanımlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚙️ Tanımlar</h1>
        <p class="muted">Firmalar, depolar, bölgeler, ürünler ve malzeme kalemleri</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<!-- ── Bölüm sekmeleri ── -->
<div class="def-tabs">
    <a class="def-tab<?= !$is_cat ? ' active' : '' ?>" href="definitions.php">📦 Malzemeler</a>
    <?php foreach ($cat_labels as $ct => $cl): ?>
    <a class="def-tab<?= $filter === $ct ? ' active' : '' ?>"
       href="definitions.php?type=<?= h($ct) ?>"><?= h($cat_icons[$ct] ?? '') ?> <?= h($cl) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($is_cat): ?>
<!-- ════════════════════════════════════════════════
     KATEGORİ BÖLÜMÜ: Firma / Depo / Bölge / Ürün
     ════════════════════════════════════════════════ -->

<section class="card">
    <div class="card-head">
        <h2><?= h($cat_icons[$filter] ?? '') ?> Yeni <?= h(rtrim($cat_labels[$filter] ?? 'Tanım', 'ler')) ?> Ekle</h2>
    </div>
    <div class="card-body">
        <form method="post" class="def-add-form">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="type"   value="<?= h($filter) ?>">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <input type="hidden" name="unit_dara_kg" value="0">
            <div class="def-add-row">
                <input type="text" name="name" placeholder="<?= match($filter) {
                    'firma'       => 'Firma adı (örn: Karaman Cihat)',
                    'depo'        => 'Depo adı (örn: Merkez Depo)',
                    'bolge'       => 'Bölge adı (örn: İç Anadolu)',
                    'urun'        => 'Ürün adı (örn: Kayısı)',
                    'lokasyon'    => 'Lokasyon adı (örn: Malatya Bahçesi, Soğuk Hava Deposu)',
                    default       => 'Ad...'
                } ?>" required autocomplete="off">
                <button class="btn btn-primary">+ Ekle</button>
            </div>
        </form>
    </div>
</section>

<?php if (empty($defs)): ?>
<div class="empty">
    <p>Henüz <?= h(strtolower($cat_labels[$filter] ?? 'tanım')) ?> eklenmemiş.</p>
    <p class="muted">Yukarıdaki formu kullanarak ekleyebilirsiniz.</p>
</div>
<?php else: ?>
<section class="card">
    <div class="card-head">
        <h2><?= h($cat_icons[$filter] ?? '') ?> <?= h($cat_labels[$filter] ?? '') ?>
            <span class="muted">(<?= count($defs) ?>)</span>
        </h2>
    </div>
    <div class="def-cat-list">
        <?php foreach ($defs as $d): ?>
        <?php $ucnt = $usage_by_name[$d['name']] ?? 0; ?>
        <div class="def-cat-row<?= !$d['is_active'] ? ' def-row-inactive' : '' ?>">

            <form method="post" class="def-cat-form">
                <input type="hidden" name="csrf"        value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action"      value="update">
                <input type="hidden" name="id"          value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="filter"      value="<?= h($filter) ?>">
                <input type="hidden" name="unit_dara_kg" value="0">
                <span class="def-cat-icon"><?= h($cat_icons[$filter] ?? '●') ?></span>
                <input type="text" name="name" value="<?= h($d['name']) ?>"
                       class="def-cat-name" required autocomplete="off">
                <?php if ($ucnt > 0): ?>
                <span class="def-usage-chip"><?= $ucnt ?> kayıt</span>
                <?php endif; ?>
                <label class="switch def-row-switch">
                    <input type="checkbox" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>>
                    <span><?= $d['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                </label>
                <button class="btn btn-sm btn-primary">Kaydet</button>
            </form>

            <form method="post">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="filter" value="<?= h($filter) ?>">
                <button class="btn btn-sm btn-ghost def-del-btn"
                        onclick="return confirm('Silinsin mi? Kullanımdaysa pasif yapılır.')">✕</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php else: ?>
<!-- ════════════════════════════════════════════════
     MALZEME BÖLÜMÜ: kasa_cinsi · palet_tipi · etc.
     ════════════════════════════════════════════════ -->

<section class="card">
    <div class="card-head"><h2>📦 Yeni Malzeme / Dara Kalemi Ekle</h2></div>
    <div class="card-body">
        <form method="post" class="grid">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="filter" value="">
            <label>Tür
                <select name="type" required>
                    <?php foreach ($mat_types as $tk => $tl): ?>
                    <option value="<?= h($tk) ?>"><?= h($tl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Adı
                <input type="text" name="name" required autocomplete="off">
            </label>
            <label>Birim Dara (kg)
                <input type="text" name="unit_dara_kg" inputmode="decimal" value="0">
            </label>
            <div class="form-foot inline">
                <button class="btn btn-primary">Ekle</button>
            </div>
        </form>
    </div>
</section>

<?php if (empty($grouped)): ?>
<div class="empty"><p>Malzeme tanımı yok.</p></div>
<?php else: ?>

<?php foreach ($grouped as $gtype => $items): ?>
<section class="card">
    <div class="card-head">
        <h2><?= h($type_labels[$gtype] ?? $gtype) ?>
            <span class="muted">(<?= count($items) ?>)</span>
        </h2>
    </div>

    <div class="table-wrap pc-only">
        <table class="data-table small">
            <thead>
            <tr>
                <th>Adı</th>
                <th class="num">Birim Dara (kg)</th>
                <th>Durum</th>
                <th class="actions-col">İşlemler</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $d): ?>
            <tr>
                <form method="post">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="filter" value="">
                <td><input type="text" name="name" value="<?= h($d['name']) ?>" required></td>
                <td class="num">
                    <input type="text" name="unit_dara_kg" inputmode="decimal" class="num"
                           value="<?= h($d['unit_dara_kg']) ?>">
                </td>
                <td>
                    <label class="switch">
                        <input type="checkbox" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>>
                        <span><?= $d['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                    </label>
                </td>
                <td class="actions-col">
                    <button class="btn btn-sm btn-primary">Kaydet</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="filter" value="">
                    <button class="btn btn-sm btn-danger"
                            onclick="return confirm('Silinsin mi? Kullanımdaysa pasif yapılır.')">Sil</button>
                </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-list mobile-only">
        <?php foreach ($items as $d): ?>
        <div class="def-card">
            <form method="post">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="filter" value="">
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
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                <input type="hidden" name="filter" value="">
                <button class="btn btn-danger btn-sm"
                        onclick="return confirm('Silinsin mi?')">Sil</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php render_footer(); ?>
