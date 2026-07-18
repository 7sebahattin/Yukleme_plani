<?php
// =========================================================
// definitions.php - Tanım yönetimi (Sprint 22 profesyonel yenileme)
// Bölümler: Ticari · Ambalaj/Kasa/Palet · Yükleme Malzemeleri · Operasyon
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
// Permission: POST delete/toggle → defs.admin, POST create/update → defs.write, GET → defs.read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_da = $_POST['action'] ?? '';
    if (in_array($_da, ['delete', 'toggle'], true)) { require_perm('defs.admin'); }
    else { require_perm('defs.write'); }
    unset($_da);
} else {
    require_perm('defs.read');
}

$type_labels = definition_types();

// ── Bölüm tanımları ──────────────────────────────────────
$SECTIONS = [
    'ticari'  => [
        'label' => 'Ticari Tanımlar', 'icon' => '🏢',
        'desc'  => 'Firma, tedarikçi, depo, bölge, ürün, marka, lokasyon',
        'types' => ['firma', 'tedarikci', 'depo', 'bolge', 'urun', 'marka', 'lokasyon'],
    ],
    'ambalaj' => [
        'label' => 'Ambalaj · Kasa · Palet', 'icon' => '📦',
        'desc'  => 'Kasa ve palet tipleri, birim dara (kg)',
        'types' => ['kasa_cinsi', 'palet_tipi'],
    ],
    'malzeme' => [
        'label' => 'Yükleme Malzemeleri', 'icon' => '🏷️',
        'desc'  => 'Şapka, köşebent, şerit, kraft, taban kağıdı vb.',
        'types' => ['sapka', 'kosebent', 'serit', 'casus', 'kasa_etiketi', 'minti',
                    'kenar_kartonu', 'taban_kagidi', 'sale', 'viyol', 'kose_karton',
                    'kraft_kagit', 'file', 'diger'],
    ],
    'operasyon' => [
        'label' => 'Operasyon', 'icon' => '🚪',
        'desc'  => 'Çıkış nedenleri — çıkma kayıtlarında kullanılır',
        'types' => ['cikis_nedeni'],
    ],
];

$cat_types = $SECTIONS['ticari']['types'];                 // dara gerektirmeyen tipler
$cat_icons = ['firma' => '🏢', 'tedarikci' => '🚛', 'depo' => '🏭', 'bolge' => '🗺️', 'urun' => '🌿', 'marka' => '🏷️', 'lokasyon' => '📍', 'cikis_nedeni' => '🚪'];

// type → section eşlemesi
$type_section = [];
foreach ($SECTIONS as $sk => $sv) {
    foreach ($sv['types'] as $t) $type_section[$t] = $sk;
}

// Bir tip dara (birim ağırlık) içerir mi? (Sprint 36: yalnız kasa/palet)
function def_has_dara(string $type): bool {
    return in_array($type, ['kasa_cinsi', 'palet_tipi'], true);
}
// Depo renk seçicisinden gelen değeri doğrular. color_reset=1 gönderilmişse
// (kullanıcı "Otomatik" butonuna bastıysa) her zaman null — isimden türetilen
// otomatik palet rengi devreye girer.
function def_color_value(array $post): ?string {
    if (($post['color_reset'] ?? '') === '1') return null;
    $v = trim((string)($post['color'] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
}

// Ekleme formu açıklaması
function def_help(string $type): string {
    return match ($type) {
        'kasa_cinsi' => 'Kasa darası (kg) girilebilir.',
        'palet_tipi' => 'Palet darası (kg) girilebilir.',
        default      => 'Bu tür için dara gerekmez.',
    };
}

// ── POST: create / update / toggle / delete ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = $_POST['action'] ?? '';
    $back   = (string)($_POST['back'] ?? '');

    try {
        if ($action === 'create') {
            $type = (string)($_POST['type'] ?? '');
            $name = tr_upper((string)($_POST['name'] ?? ''));
            // Sprint 36: dara yalnız kasa/palet; sarf tiplerinde her zaman 0
            $unit = def_has_dara($type) ? num($_POST['unit_dara_kg'] ?? 0) : 0;
            if (!isset($type_labels[$type]) || $name === '') throw new RuntimeException('Tür ve isim zorunlu.');
            $existing = db()->prepare("SELECT name FROM material_definitions WHERE type = ?");
            $existing->execute([$type]);
            foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $n) {
                if (tr_upper($n) === $name) {
                    throw new RuntimeException('"' . $name . '" bu türde zaten mevcut.');
                }
            }
            $color_col = ($type === 'depo') && db_has_column('material_definitions', 'color');
            $color_val = $color_col ? def_color_value($_POST) : null;
            if ($color_col) {
                db()->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active, color) VALUES (?,?,?,1,?)")
                    ->execute([$type, $name, $unit, $color_val]);
            } else {
                db()->prepare("INSERT INTO material_definitions (type, name, unit_dara_kg, is_active) VALUES (?,?,?,1)")
                    ->execute([$type, $name, $unit]);
            }
            $new_def_id = (int)db()->lastInsertId();
            audit_log_event('create', 'definitions', $new_def_id, null, ['type' => $type, 'name' => $name]);
            set_flash('success', '"' . $name . '" eklendi.');
            $back = 'sel=' . $new_def_id; // yeni kayıt listede seçili gelsin

        } elseif ($action === 'update') {
            $id        = (int)($_POST['id'] ?? 0);
            $name      = tr_upper((string)($_POST['name'] ?? ''));
            $is_active = !empty($_POST['is_active']) ? 1 : 0;
            if ($id <= 0 || $name === '') throw new RuntimeException('Geçersiz veri.');
            $row = db()->prepare("SELECT type, name FROM material_definitions WHERE id = ?");
            $row->execute([$id]);
            $row_data = $row->fetch();
            $type     = (string)($row_data['type'] ?? '');
            $old_name = (string)($row_data['name'] ?? '');
            // Sprint 36: dara yalnız kasa/palet; sarf tiplerinde her zaman 0
            $unit = def_has_dara($type) ? num($_POST['unit_dara_kg'] ?? 0) : 0;
            if ($type !== '') {
                $others = db()->prepare("SELECT name FROM material_definitions WHERE type = ? AND id != ?");
                $others->execute([$type, $id]);
                foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    if (tr_upper($n) === $name) {
                        throw new RuntimeException('Bu türde aynı isimde başka bir tanım zaten var.');
                    }
                }
            }
            $color_col = ($type === 'depo') && db_has_column('material_definitions', 'color');
            if ($color_col) {
                $color_val = def_color_value($_POST);
                db()->prepare("UPDATE material_definitions SET name=?, unit_dara_kg=?, is_active=?, color=? WHERE id=?")
                    ->execute([$name, $unit, $is_active, $color_val, $id]);
            } else {
                db()->prepare("UPDATE material_definitions SET name=?, unit_dara_kg=?, is_active=? WHERE id=?")
                    ->execute([$name, $unit, $is_active, $id]);
            }
            audit_log_event('update', 'definitions', $id, null, ['name' => $name, 'type' => $type]);
            // Depo adı değişti/eşitlenmeli → tüm depo kolonlarını canonical yazıma çek
            $_synced = 0;
            if ($type === 'depo') {
                $_synced = sync_depot_name_in_data($name, $old_name !== $name ? $old_name : '');
                if ($_synced > 0) {
                    audit_log_event('depot_rename_sync', 'definitions', $id,
                        ['old' => $old_name], ['new' => $name, 'satir' => $_synced]);
                }
            }
            // Çıkış nedeni adı değişti → çıkma kayıtlarındaki eski değeri de güncelle
            // (veri serbest metin tutulur; sync olmadan eski kayıtlar filtrelerden düşer)
            if ($type === 'cikis_nedeni' && $old_name !== '' && $old_name !== $name) {
                try {
                    $st_cn = db()->prepare("UPDATE loading_records SET cikis_nedeni = ? WHERE type='cikma' AND cikis_nedeni = ?");
                    $st_cn->execute([$name, $old_name]);
                    $_synced = $st_cn->rowCount();
                    if ($_synced > 0) {
                        audit_log_event('cikis_nedeni_rename_sync', 'definitions', $id,
                            ['old' => $old_name], ['new' => $name, 'satir' => $_synced]);
                    }
                } catch (Throwable $e) {}
            }
            set_flash('success', 'Güncellendi.' . ($_synced > 0 ? ' ' . $_synced . ' kayıttaki depo adı eşitlendi.' : ''));

        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $_tog_row = db()->prepare("SELECT is_active FROM material_definitions WHERE id=?");
            $_tog_row->execute([$id]);
            $_old_active = (int)($_tog_row->fetchColumn() ?? 1);
            db()->prepare("UPDATE material_definitions SET is_active = 1-is_active WHERE id=?")->execute([$id]);
            audit_log_event('toggle', 'definitions', $id, ['is_active' => $_old_active], ['is_active' => 1 - $_old_active]);
            set_flash('success', 'Durum değiştirildi.');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $defRow = db()->prepare("SELECT type, name FROM material_definitions WHERE id = ?");
            $defRow->execute([$id]);
            $dd = $defRow->fetch();
            $used = 0;
            if ($dd) {
                // ID bazlı kullanım (kasa/palet/malzeme)
                $st = db()->prepare("SELECT
                    (SELECT COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id=? OR palet_tipi_id=?)
                    + (SELECT COUNT(*) FROM pallet_materials WHERE material_id=?) AS used");
                $st->execute([$id, $id, $id]);
                $used = (int)$st->fetchColumn();
                // malzeme stok hareketi kullanımı
                try {
                    $m = db()->prepare("SELECT COUNT(*) FROM material_stock_movements WHERE material_id=?");
                    $m->execute([$id]);
                    $used += (int)$m->fetchColumn();
                } catch (Throwable $e) {}
                // isim bazlı kullanım (ticari tipler)
                $catCol = match ($dd['type']) {
                    'firma' => 'firma', 'urun' => 'urun', 'bolge' => 'bolge', 'marka' => 'brand', default => null,
                };
                if ($catCol) {
                    $c = db()->prepare("SELECT COUNT(*) FROM loading_records WHERE `$catCol` = ?");
                    $c->execute([$dd['name']]);
                    $used += (int)$c->fetchColumn();
                }
                if ($dd['type'] === 'depo') {
                    $c = db()->prepare("SELECT COUNT(*) FROM loading_pallets WHERE depo = ?");
                    $c->execute([$dd['name']]);
                    $used += (int)$c->fetchColumn();
                }
                if ($dd['type'] === 'tedarikci') {
                    try {
                        $c = db()->prepare("SELECT COUNT(*) FROM material_stock_movements WHERE firma = ?");
                        $c->execute([$dd['name']]);
                        $used += (int)$c->fetchColumn();
                    } catch (Throwable $e) {}
                }
                if ($dd['type'] === 'cikis_nedeni') {
                    try {
                        $c = db()->prepare("SELECT COUNT(*) FROM loading_records WHERE type='cikma' AND cikis_nedeni = ?");
                        $c->execute([$dd['name']]);
                        $used += (int)$c->fetchColumn();
                    } catch (Throwable $e) {}
                }
            }
            if ($used > 0) {
                db()->prepare("UPDATE material_definitions SET is_active=0 WHERE id=?")->execute([$id]);
                audit_log_event('deactivate', 'definitions', $id, $dd ?: null, ['reason' => 'in_use']);
                set_flash('success', 'Kullanımda olduğu için silinmedi, pasifleştirildi.');
            } else {
                db()->prepare("DELETE FROM material_definitions WHERE id=?")->execute([$id]);
                audit_log_event('delete', 'definitions', $id, $dd ?: null);
                set_flash('success', 'Silindi.');
            }
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: definitions.php' . ($back !== '' ? '?' . $back : ''));
    exit;
}

// ── GET parametreleri ────────────────────────────────────
// sel  = kayıt sonrası panele yüklenecek tanım id'si
// type = dış bağlantı ön seçimi (örn. definitions.php?type=depo)
$g_sel  = (int)($_GET['sel'] ?? 0);
$g_type = (string)($_GET['type'] ?? '');
if ($g_type !== '' && !isset($type_labels[$g_type])) $g_type = '';

// ── Tüm tanımları çek ────────────────────────────────────
$all = db()->query("SELECT * FROM material_definitions ORDER BY type, name")->fetchAll();

// ── Kullanım haritaları (toplu sorgu, N+1 yok) ───────────
$usage_kasa = db()->query("SELECT kasa_cinsi_id, COUNT(*) FROM loading_pallets WHERE kasa_cinsi_id IS NOT NULL AND kasa_cinsi_id>0 GROUP BY kasa_cinsi_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_palet = db()->query("SELECT palet_tipi_id, COUNT(*) FROM loading_pallets WHERE palet_tipi_id IS NOT NULL AND palet_tipi_id>0 GROUP BY palet_tipi_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_mat = db()->query("SELECT material_id, COUNT(*) FROM pallet_materials WHERE material_id IS NOT NULL GROUP BY material_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_mov = [];
try {
    $usage_mov = db()->query("SELECT material_id, COUNT(*) FROM material_stock_movements WHERE material_id IS NOT NULL GROUP BY material_id")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) {}

// İsim bazlı (ticari) kullanım
$usage_cat = [];
$usage_cat['firma']    = db()->query("SELECT firma, COUNT(*) FROM loading_records WHERE firma != '' GROUP BY firma")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_cat['urun']     = db()->query("SELECT urun, COUNT(*) FROM loading_records WHERE urun != '' GROUP BY urun")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_cat['bolge']    = db()->query("SELECT bolge, COUNT(*) FROM loading_records WHERE bolge != '' GROUP BY bolge")->fetchAll(PDO::FETCH_KEY_PAIR);
$usage_cat['depo']     = db()->query("SELECT depo, COUNT(DISTINCT loading_record_id) FROM loading_pallets WHERE depo != '' GROUP BY depo")->fetchAll(PDO::FETCH_KEY_PAIR);
try {
    $usage_cat['marka'] = db()->query("SELECT brand, COUNT(*) FROM loading_records WHERE brand IS NOT NULL AND brand != '' GROUP BY brand")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $usage_cat['marka'] = []; }
try {
    $usage_cat['tedarikci'] = db()->query("SELECT firma, COUNT(*) FROM material_stock_movements WHERE firma != '' GROUP BY firma")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $usage_cat['tedarikci'] = []; }
try {
    $usage_cat['cikis_nedeni'] = db()->query("SELECT cikis_nedeni, COUNT(*) FROM loading_records WHERE type='cikma' AND cikis_nedeni != '' GROUP BY cikis_nedeni")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $usage_cat['cikis_nedeni'] = []; }
try {
    $usage_cat['lokasyon'] = db()->query("SELECT name, COUNT(*) FROM (SELECT geldigi_yer AS name FROM kantar_fisleri WHERE geldigi_yer!='' UNION ALL SELECT gittigi_yer FROM kantar_fisleri WHERE gittigi_yer!='') t GROUP BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $e) { $usage_cat['lokasyon'] = []; }

// Bir tanımın kullanım sayısı
function def_usage_count(array $d, array $kasa, array $palet, array $mat, array $mov, array $cat): int {
    $t  = $d['type'];
    $id = (int)$d['id'];
    if ($t === 'kasa_cinsi')  return (int)($kasa[$id]  ?? 0);
    if ($t === 'palet_tipi')  return (int)($palet[$id] ?? 0);
    if (isset($cat[$t]))      return (int)($cat[$t][$d['name']] ?? 0);
    return (int)($mat[$id] ?? 0) + (int)($mov[$id] ?? 0);
}

// Arama için Türkçe-bağışlayıcı katlama (JS tarafındaki fold ile aynı kurallar)
function def_search_fold(string $s): string {
    $s = strtr($s, [
        'İ'=>'i','I'=>'i','Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c',
        'ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c',
        'Â'=>'a','Î'=>'i','Û'=>'u','â'=>'a','î'=>'i','û'=>'u',
    ]);
    return mb_strtolower($s, 'UTF-8');
}

// Bölüm > tip > kayıtlar şeklinde grupla (filtre yok — arama tamamen istemci tarafında)
$grouped = [];
foreach ($all as $d) {
    $sec = $type_section[$d['type']] ?? 'malzeme';
    $grouped[$sec][$d['type']][] = $d;
}

// sel → hangi bölüm açık başlasın
$sel_section = '';
if ($g_sel > 0) {
    foreach ($all as $d) {
        if ((int)$d['id'] === $g_sel) { $sel_section = $type_section[$d['type']] ?? 'malzeme'; break; }
    }
}
if ($sel_section === '' && $g_type !== '') $sel_section = $type_section[$g_type] ?? '';

render_header('Tanımlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚙️ Tanımlar</h1>
        <p class="muted">Soldan ekle/düzenle · sağda listeden seç</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<div class="def3-layout">

    <!-- ── SOL: Sabit Ekle / Düzenle Paneli ── -->
    <aside class="def3-panel card">
        <div class="def3-panel-head">
            <h2 id="d3Title">➕ Yeni Tanım</h2>
            <button type="button" id="d3NewBtn" class="btn btn-sm btn-ghost" hidden>+ Yeni</button>
        </div>

        <form method="post" id="d3Form" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create" id="d3Action">
            <input type="hidden" name="id"     value=""       id="d3Id" disabled>
            <input type="hidden" name="back"   value=""       id="d3Back">
            <input type="hidden" name="color_reset" value="0" id="d3ColorReset">

            <label class="def3-field">Kategori / Tür
                <select name="type" id="d3Type" required>
                    <?php foreach ($SECTIONS as $sk => $sv): ?>
                    <optgroup label="<?= h($sv['icon'] . ' ' . $sv['label']) ?>">
                        <?php foreach ($sv['types'] as $t): ?>
                        <option value="<?= h($t) ?>"
                                data-dara="<?= def_has_dara($t) ? '1' : '0' ?>"
                                <?= $g_type === $t ? 'selected' : '' ?>><?= h($type_labels[$t] ?? $t) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="def3-field">Tanım Adı
                <input type="text" name="name" id="d3Name" required
                       placeholder="Örn: KARAMAN" data-uppercase="tr">
            </label>

            <label class="def3-field" id="d3DaraWrap" hidden>Birim Dara (kg)
                <input type="text" name="unit_dara_kg" id="d3Dara" inputmode="decimal" value="0">
            </label>

            <label class="def3-field def3-color-field" id="d3ColorWrap" hidden>
                Depo Rengi
                <span class="def3-color-row">
                    <input type="color" name="color" id="d3Color" value="#2563eb">
                    <button type="button" class="btn btn-sm btn-ghost" id="d3ColorAuto">Otomatik</button>
                </span>
                <small class="muted">Sidebar, üst bar ve mobil menüde bu depo aktifken kullanılır.</small>
            </label>

            <label class="def3-active" id="d3ActiveWrap" hidden>
                <input type="checkbox" name="is_active" id="d3Active" checked> Aktif
            </label>

            <div class="def3-panel-meta" id="d3Meta" hidden></div>

            <div class="def3-panel-actions">
                <button class="btn btn-primary" id="d3Submit">+ Ekle</button>
                <button type="button" class="btn btn-ghost def3-del" id="d3DelBtn" hidden>🗑 Sil</button>
            </div>
        </form>

        <form method="post" id="d3DelForm" hidden>
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id"     value="" id="d3DelId">
            <input type="hidden" name="back"   value="">
        </form>
    </aside>

    <!-- ── SAĞ: Başlıklı liste (akordiyon) ── -->
    <div class="def3-list">
        <div class="def3-search card">
            <input type="search" id="d3Search" placeholder="🔍 Tüm tanımlarda ara..." autocomplete="off">
            <label class="def3-showoff"><input type="checkbox" id="d3ShowOff" checked> Pasifler</label>
        </div>

        <!-- ── Bölüm sekmeleri ── -->
        <?php $active_sec = $sel_section !== '' ? $sel_section : 'ticari'; ?>
        <div class="def3-tabs card">
            <?php foreach ($SECTIONS as $sk => $sv):
                $sec_groups = $grouped[$sk] ?? [];
                $sec_count  = 0;
                foreach ($sec_groups as $items) $sec_count += count($items);
            ?>
            <button type="button" class="def3-tab<?= $active_sec === $sk ? ' active' : '' ?>" data-sec="<?= h($sk) ?>">
                <?= $sv['icon'] ?> <?= h($sv['label']) ?> <span class="def3-tab-count"><?= $sec_count ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($SECTIONS as $sk => $sv):
            $sec_groups = $grouped[$sk] ?? [];
        ?>
        <section class="def3-sec card<?= $active_sec === $sk ? ' active' : '' ?>" data-sec="<?= h($sk) ?>">
            <div class="def3-sec-body">
                <?php foreach ($sv['types'] as $t):
                    $items = $sec_groups[$t] ?? [];
                    $has_dara = def_has_dara($t);
                ?>
                <div class="def3-tgroup" data-type="<?= h($t) ?>">
                    <div class="def3-tgroup-head">
                        <span><?= h($cat_icons[$t] ?? '•') ?> <?= h($type_labels[$t] ?? $t) ?>
                            <small class="muted">· <?= count($items) ?></small></span>
                        <button type="button" class="def3-tgroup-add" data-type="<?= h($t) ?>"
                                title="Bu türde yeni ekle">+ Ekle</button>
                    </div>
                    <?php if (empty($items)): ?>
                    <div class="def3-empty-type muted">Henüz tanım yok</div>
                    <?php else: foreach ($items as $d):
                        $ucnt = def_usage_count($d, $usage_kasa, $usage_palet, $usage_mat, $usage_mov, $usage_cat);
                    ?>
                    <button type="button"
                            class="def3-row<?= !$d['is_active'] ? ' off' : '' ?>"
                            data-id="<?= (int)$d['id'] ?>"
                            data-name="<?= h($d['name']) ?>"
                            data-type="<?= h($d['type']) ?>"
                            data-typelabel="<?= h($type_labels[$d['type']] ?? $d['type']) ?>"
                            data-hasdara="<?= $has_dara ? '1' : '0' ?>"
                            data-dara="<?= h($d['unit_dara_kg']) ?>"
                            data-active="<?= $d['is_active'] ? '1' : '0' ?>"
                            data-usage="<?= $ucnt ?>"
                            <?php if ($t === 'depo'): ?>data-color="<?= h(depot_color($d['name'])) ?>" data-colorset="<?= !empty($d['color']) ? '1' : '0' ?>"<?php endif; ?>
                            data-q="<?= h(def_search_fold($d['name'] . ' ' . ($type_labels[$d['type']] ?? $d['type']))) ?>">
                        <span class="def3-row-name">
                            <?php if ($t === 'depo'): ?>
                            <span class="def3-color-dot" style="background:<?= h(depot_color($d['name'])) ?>"></span>
                            <?php endif; ?>
                            <?= h($d['name']) ?>
                        </span>
                        <span class="def3-row-meta">
                            <?php if ($has_dara && (float)$d['unit_dara_kg'] > 0): ?>
                            <span class="def3-chip def3-chip-dara"><?= h($d['unit_dara_kg']) ?> kg</span>
                            <?php endif; ?>
                            <?php if ($ucnt > 0): ?>
                            <span class="def3-chip"><?= $ucnt ?> kayıt</span>
                            <?php endif; ?>
                            <span class="def3-dot <?= $d['is_active'] ? 'on' : '' ?>"
                                  title="<?= $d['is_active'] ? 'Aktif' : 'Pasif' ?>"></span>
                        </span>
                    </button>
                    <?php endforeach; endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <div class="def3-noresult muted" id="d3NoResult" hidden>Aramaya uyan tanım bulunamadı.</div>
    </div>
</div>

<script>
(function () {
    var form    = document.getElementById('d3Form');
    var title   = document.getElementById('d3Title');
    var fAction = document.getElementById('d3Action');
    var fId     = document.getElementById('d3Id');
    var fBack   = document.getElementById('d3Back');
    var fType   = document.getElementById('d3Type');
    var fName   = document.getElementById('d3Name');
    var daraWrap= document.getElementById('d3DaraWrap');
    var fDara   = document.getElementById('d3Dara');
    var colorWrap = document.getElementById('d3ColorWrap');
    var fColor    = document.getElementById('d3Color');
    var fColorReset = document.getElementById('d3ColorReset');
    var colorAutoBtn = document.getElementById('d3ColorAuto');
    var actWrap = document.getElementById('d3ActiveWrap');
    var fActive = document.getElementById('d3Active');
    var meta    = document.getElementById('d3Meta');
    var submit  = document.getElementById('d3Submit');
    var newBtn  = document.getElementById('d3NewBtn');
    var delBtn  = document.getElementById('d3DelBtn');
    var delForm = document.getElementById('d3DelForm');
    var delId   = document.getElementById('d3DelId');
    var panel   = document.querySelector('.def3-panel');
    var selRow  = null;

    // ── Panel modları ──────────────────────────────
    function syncDaraByType() {
        var opt = fType.options[fType.selectedIndex];
        var has = opt && opt.getAttribute('data-dara') === '1';
        daraWrap.hidden = !has;
        if (!has) fDara.value = '0';
        var isDepo = fType.value === 'depo';
        colorWrap.hidden = !isDepo;
        if (!isDepo) fColorReset.value = '0';
    }

    var fColorLoadedValue = fColor.value; // panel açıldığındaki renk — submit'te karşılaştırma için

    function setColorAuto() {
        fColorReset.value = '1';
        fColor.classList.add('is-auto');
    }
    function setColorManual() {
        fColorReset.value = '0';
        fColor.classList.remove('is-auto');
    }

    function newMode(presetType) {
        if (selRow) { selRow.classList.remove('sel'); selRow = null; }
        title.textContent = '➕ Yeni Tanım';
        fAction.value = 'create';
        fId.disabled  = true; fId.value = '';
        fBack.value   = '';
        fType.disabled = false;
        if (presetType) fType.value = presetType;
        fName.value = '';
        fDara.value = '0';
        fColor.value = '#2563eb';
        setColorAuto(); // yeni depo varsayılan olarak otomatik renk alır
        fColorLoadedValue = fColor.value;
        actWrap.hidden = true; fActive.checked = true;
        meta.hidden = true;
        submit.textContent = '+ Ekle';
        newBtn.hidden = true;
        delBtn.hidden = true;
        syncDaraByType();
        fName.focus();
    }

    function editMode(row) {
        if (selRow) selRow.classList.remove('sel');
        selRow = row; row.classList.add('sel');

        var usage = parseInt(row.dataset.usage || '0', 10);
        title.textContent = '✏️ ' + row.dataset.typelabel + ' Düzenle';
        fAction.value = 'update';
        fId.disabled  = false; fId.value = row.dataset.id;
        fBack.value   = 'sel=' + row.dataset.id;
        fType.value   = row.dataset.type;
        fType.disabled = true;                 // tür güncellemede değişmez
        fName.value   = row.dataset.name;
        daraWrap.hidden = row.dataset.hasdara !== '1';
        fDara.value   = row.dataset.dara || '0';
        colorWrap.hidden = row.dataset.type !== 'depo';
        if (row.dataset.type === 'depo') {
            fColor.value = row.dataset.color || '#2563eb';
            if (row.dataset.colorset === '1') setColorManual(); else setColorAuto();
            fColorLoadedValue = fColor.value;
        }
        actWrap.hidden = false;
        fActive.checked = row.dataset.active === '1';
        meta.hidden = false;
        meta.textContent = usage > 0
            ? '📊 ' + usage + ' kayıtta kullanılıyor — silinirse pasifleştirilir.'
            : 'Hiç kullanılmamış — güvenle silinebilir.';
        submit.textContent = '💾 Kaydet';
        newBtn.hidden = false;
        delBtn.hidden = false;
        delBtn.dataset.usage = usage;

        // Mobilde panel yukarıda — seçince görünür yap
        if (window.innerWidth < 900) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        fName.focus();
    }

    fType.addEventListener('change', syncDaraByType);
    newBtn.addEventListener('click', function () { newMode(); });
    // Mobil tarayıcılarda <input type=color> bazen yalnız 'change' tetikler,
    // masaüstünde 'input' de tetiklenir — ikisini de dinle (kaçırma riski olmasın).
    fColor.addEventListener('input', setColorManual);
    fColor.addEventListener('change', setColorManual);
    // Son güvenlik ağı: olaylar hiç tetiklenmese bile, gönderim anında
    // panel açılışındaki renkle karşılaştır — farklıysa manuel işaretle.
    form.addEventListener('submit', function () {
        if (!colorWrap.hidden && fColor.value !== fColorLoadedValue) setColorManual();
    });
    colorAutoBtn.addEventListener('click', function () {
        setColorAuto();
        // Görsel önizleme: mevcut isme göre PHP ile aynı algoritmayla türetilen renk yoksa
        // sunucudan gelen efektif renk zaten inputta duruyor; sadece "otomatik" işaretlenir.
    });

    delBtn.addEventListener('click', function () {
        if (!fId.value) return;
        var usage = parseInt(delBtn.dataset.usage || '0', 10);
        var msg = usage > 0
            ? 'Bu tanım ' + usage + ' kayıtta kullanılıyor. Silinemez, pasif yapılacak. Devam edilsin mi?'
            : '"' + fName.value + '" silinsin mi?';
        if (!confirm(msg)) return;
        delId.value = fId.value;
        delForm.submit();
    });

    // ── Liste: satır seçimi + tür-içi hızlı ekle ──
    document.querySelectorAll('.def3-row').forEach(function (row) {
        row.addEventListener('click', function () { editMode(row); });
    });
    document.querySelectorAll('.def3-tgroup-add').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            newMode(btn.dataset.type);
            if (window.innerWidth < 900) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // ── Sekmeler: tıklanan başlığın içeriği gösterilir ──
    function activateTab(sec) {
        document.querySelectorAll('.def3-tab').forEach(function (t) {
            t.classList.toggle('active', t.dataset.sec === sec);
        });
        document.querySelectorAll('.def3-sec').forEach(function (s) {
            s.classList.toggle('active', s.dataset.sec === sec);
        });
    }
    document.querySelectorAll('.def3-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            if (search.value.trim() !== '') { search.value = ''; applyFilter(); }
            activateTab(tab.dataset.sec);
        });
    });

    // ── Arama (istemci tarafı, anında) ─────────────
    var search   = document.getElementById('d3Search');
    var showOff  = document.getElementById('d3ShowOff');
    var noResult = document.getElementById('d3NoResult');

    function fold(s) {
        return (s || '').replace(/İ/g,'i').replace(/I/g,'i').replace(/Ş/g,'s').replace(/Ğ/g,'g')
            .replace(/Ü/g,'u').replace(/Ö/g,'o').replace(/Ç/g,'c')
            .replace(/ı/g,'i').replace(/ş/g,'s').replace(/ğ/g,'g')
            .replace(/ü/g,'u').replace(/ö/g,'o').replace(/ç/g,'c').toLowerCase();
    }

    function applyFilter() {
        var q = fold(search.value.trim());
        var offOk = showOff.checked;
        var anyVisible = false;

        document.querySelectorAll('.def3-sec').forEach(function (sec) {
            var secHas = false;
            sec.querySelectorAll('.def3-tgroup').forEach(function (tg) {
                var tgHas = false;
                tg.querySelectorAll('.def3-row').forEach(function (row) {
                    var show = (offOk || row.dataset.active === '1')
                        && (q === '' || (row.dataset.q || '').indexOf(q) !== -1);
                    row.hidden = !show;
                    if (show) tgHas = true;
                });
                var emptyNote = tg.querySelector('.def3-empty-type');
                if (emptyNote) emptyNote.hidden = q !== '';
                tg.hidden = q !== '' && !tgHas;
                if (tgHas) secHas = true;
            });
            // Arama varken: sekme seçiminden bağımsız, eşleşen TÜM bölümler görünür
            sec.classList.toggle('search-show', q !== '' && secHas);
            sec.classList.toggle('search-hide', q !== '' && !secHas);
            if (secHas) anyVisible = true;
        });
        noResult.hidden = !(q !== '' && !anyVisible);
    }
    search.addEventListener('input', applyFilter);
    showOff.addEventListener('change', applyFilter);

    // ── Açılış durumu: sel / type paramı ───────────
    syncDaraByType();
    var selId = <?= (int)$g_sel ?>;
    if (selId > 0) {
        var row = document.querySelector('.def3-row[data-id="' + selId + '"]');
        if (row) {
            editMode(row);
            row.scrollIntoView({ block: 'center' });
        }
    }
})();
</script>

<?php render_footer(); ?>
