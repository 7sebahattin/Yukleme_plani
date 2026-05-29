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
        'desc'  => 'Firma, depo, bölge, ürün, lokasyon',
        'types' => ['firma', 'depo', 'bolge', 'urun', 'lokasyon'],
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
];

$cat_types = $SECTIONS['ticari']['types'];                 // dara gerektirmeyen tipler
$cat_icons = ['firma' => '🏢', 'depo' => '🏭', 'bolge' => '🗺️', 'urun' => '🌿', 'lokasyon' => '📍'];

// type → section eşlemesi
$type_section = [];
foreach ($SECTIONS as $sk => $sv) {
    foreach ($sv['types'] as $t) $type_section[$t] = $sk;
}

// Bir tip dara (birim ağırlık) içerir mi?
function def_has_dara(string $type): bool {
    return !in_array($type, ['firma', 'depo', 'bolge', 'urun', 'lokasyon'], true);
}
// Ekleme formu açıklaması
function def_help(string $type): string {
    return match ($type) {
        'firma', 'depo', 'bolge', 'urun', 'lokasyon' => 'Bu tür için dara gerekmez.',
        'kasa_cinsi' => 'Kasa darası (kg) girilebilir.',
        'palet_tipi' => 'Palet darası (kg) girilebilir.',
        default      => 'Birim ağırlık gerekiyorsa (kg) girilebilir.',
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
                    'firma' => 'firma', 'urun' => 'urun', 'bolge' => 'bolge', default => null,
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
            }
            if ($used > 0) {
                db()->prepare("UPDATE material_definitions SET is_active=0 WHERE id=?")->execute([$id]);
                set_flash('success', 'Kullanımda olduğu için silinmedi, pasifleştirildi.');
            } else {
                db()->prepare("DELETE FROM material_definitions WHERE id=?")->execute([$id]);
                set_flash('success', 'Silindi.');
            }
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }
    header('Location: definitions.php' . ($back !== '' ? '?' . $back : ''));
    exit;
}

// ── GET filtreleri ───────────────────────────────────────
$f_q       = trim((string)($_GET['q'] ?? ''));
$f_section = (string)($_GET['section'] ?? '');
$f_durum   = (string)($_GET['durum'] ?? '');           // '', 'aktif', 'pasif'
$f_dara    = !empty($_GET['dara']);                    // sadece dara içerenler
if ($f_section !== '' && $f_section !== 'operasyon' && !isset($SECTIONS[$f_section])) $f_section = '';
if (!in_array($f_durum, ['aktif', 'pasif'], true)) $f_durum = '';

// Geri dönüş query (POST sonrası bağlamı koru)
$back_qs = http_build_query(array_filter([
    'section' => $f_section,
    'q'       => $f_q,
    'durum'   => $f_durum,
    'dara'    => $f_dara ? '1' : '',
], fn($v) => $v !== '' && $v !== null));

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

// ── Özet istatistik (filtreden bağımsız) ─────────────────
$stat_total = count($all);
$stat_aktif = 0; $stat_ambalaj = 0; $stat_malzeme = 0; $stat_ticari = 0;
foreach ($all as $d) {
    if ($d['is_active']) $stat_aktif++;
    $sec = $type_section[$d['type']] ?? null;
    if ($sec === 'ambalaj') $stat_ambalaj++;
    elseif ($sec === 'malzeme') $stat_malzeme++;
    elseif ($sec === 'ticari') $stat_ticari++;
}
$stat_pasif = $stat_total - $stat_aktif;

// Arama için Türkçe-bağışlayıcı katlama (sadece arama; duplicate guard normalize_text_v2 ile katı kalır).
// MySQL LOWER kullanılmaz — tamamen PHP tarafında.
function def_search_fold(string $s): string {
    $s = strtr($s, [
        'İ'=>'i','I'=>'i','Ş'=>'s','Ğ'=>'g','Ü'=>'u','Ö'=>'o','Ç'=>'c',
        'ı'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c',
        'Â'=>'a','Î'=>'i','Û'=>'u','â'=>'a','î'=>'i','û'=>'u',
    ]);
    return mb_strtolower($s, 'UTF-8');
}

// ── Filtre uygula (PHP tarafı, Türkçe uyumlu) ────────────
$q_fold = $f_q !== '' ? def_search_fold($f_q) : '';
$filtered = [];
foreach ($all as $d) {
    $sec = $type_section[$d['type']] ?? 'malzeme';
    if ($f_section !== '' && $f_section !== 'operasyon' && $sec !== $f_section) continue;
    if ($f_durum === 'aktif' && !$d['is_active']) continue;
    if ($f_durum === 'pasif' && $d['is_active']) continue;
    if ($f_dara && !(def_has_dara($d['type']) && (float)$d['unit_dara_kg'] > 0)) continue;
    if ($q_fold !== '') {
        // Türkçe-bağışlayıcı: ad + tip etiketi içinde ara (ı/i, ş/s, büyük/küçük harf duyarsız)
        $hay = def_search_fold($d['name'] . ' ' . ($type_labels[$d['type']] ?? $d['type']));
        if (mb_strpos($hay, $q_fold, 0, 'UTF-8') === false) continue;
    }
    $filtered[] = $d;
}

// Bölüm > tip > kayıtlar şeklinde grupla
$grouped = [];
foreach ($filtered as $d) {
    $sec = $type_section[$d['type']] ?? 'malzeme';
    $grouped[$sec][$d['type']][] = $d;
}

$show_operasyon = ($f_section === '' || $f_section === 'operasyon') && $f_q === '' && !$f_dara && $f_durum === '';

render_header('Tanımlar');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>⚙️ Tanımlar</h1>
        <p class="muted">Firma, depo, ürün, kasa/palet ve malzeme tanımlarını tek yerden yönetin</p>
    </div>
    <a href="index.php" class="btn btn-ghost">← Ana Sayfa</a>
</div>

<!-- ── Özet kartları ── -->
<div class="def2-stats">
    <div class="def2-stat"><span>Toplam Tanım</span><strong><?= $stat_total ?></strong></div>
    <div class="def2-stat def2-stat-ok"><span>Aktif</span><strong><?= $stat_aktif ?></strong></div>
    <div class="def2-stat def2-stat-off"><span>Pasif</span><strong><?= $stat_pasif ?></strong></div>
    <div class="def2-stat"><span>Ticari</span><strong><?= $stat_ticari ?></strong></div>
    <div class="def2-stat"><span>Kasa / Palet</span><strong><?= $stat_ambalaj ?></strong></div>
    <div class="def2-stat"><span>Malzeme</span><strong><?= $stat_malzeme ?></strong></div>
</div>

<!-- ── Filtre & arama ── -->
<form method="get" class="def2-filter">
    <div class="def2-filter-search">
        <input type="search" name="q" value="<?= h($f_q) ?>" placeholder="Tanım ara (firma, kasa, malzeme...)" autocomplete="off">
        <button class="btn btn-primary btn-sm">Ara</button>
    </div>
    <div class="def2-filter-row">
        <select name="section" onchange="this.form.submit()">
            <option value="">Tüm Bölümler</option>
            <?php foreach ($SECTIONS as $sk => $sv): ?>
            <option value="<?= h($sk) ?>" <?= $f_section === $sk ? 'selected' : '' ?>><?= $sv['icon'] ?> <?= h($sv['label']) ?></option>
            <?php endforeach; ?>
            <option value="operasyon" <?= $f_section === 'operasyon' ? 'selected' : '' ?>>🚪 Operasyon</option>
        </select>
        <select name="durum" onchange="this.form.submit()">
            <option value="">Tümü (Aktif+Pasif)</option>
            <option value="aktif" <?= $f_durum === 'aktif' ? 'selected' : '' ?>>Sadece Aktif</option>
            <option value="pasif" <?= $f_durum === 'pasif' ? 'selected' : '' ?>>Sadece Pasif</option>
        </select>
        <label class="def2-check">
            <input type="checkbox" name="dara" value="1" <?= $f_dara ? 'checked' : '' ?> onchange="this.form.submit()">
            Sadece dara içerenler
        </label>
        <?php if ($f_q !== '' || $f_section !== '' || $f_durum !== '' || $f_dara): ?>
        <a href="definitions.php" class="btn btn-ghost btn-sm">Temizle</a>
        <?php endif; ?>
    </div>
</form>

<!-- ── Yeni tanım ekle ── -->
<section class="card def2-add-card">
    <div class="card-head"><h2>➕ Yeni Tanım Ekle</h2></div>
    <div class="card-body">
        <form method="post" class="def2-add-form" id="defAddForm">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="back"   value="<?= h($back_qs) ?>">
            <div class="def2-add-grid">
                <label>Kategori / Tür
                    <select name="type" id="defAddType" required>
                        <?php foreach ($SECTIONS as $sk => $sv): ?>
                        <optgroup label="<?= h($sv['icon'] . ' ' . $sv['label']) ?>">
                            <?php foreach ($sv['types'] as $t): ?>
                            <option value="<?= h($t) ?>"
                                    data-dara="<?= def_has_dara($t) ? '1' : '0' ?>"
                                    data-help="<?= h(def_help($t)) ?>"><?= h($type_labels[$t] ?? $t) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Tanım Adı
                    <input type="text" name="name" required autocomplete="off" placeholder="Örn: Karaman Cihat">
                </label>
                <label id="defAddDaraWrap">Birim Dara (kg)
                    <input type="text" name="unit_dara_kg" inputmode="decimal" value="0">
                </label>
                <div class="def2-add-submit">
                    <button class="btn btn-primary">+ Ekle</button>
                </div>
            </div>
            <p class="def2-add-help" id="defAddHelp"></p>
        </form>
    </div>
</section>

<?php
// ── Bölümleri sırayla bas ──
$rendered_any = false;
foreach ($SECTIONS as $sk => $sv):
    if ($f_section !== '' && $f_section !== $sk && $f_section !== 'operasyon') continue;
    if ($f_section === 'operasyon') continue;
    $sec_groups = $grouped[$sk] ?? [];
    $sec_count  = 0;
    foreach ($sec_groups as $items) $sec_count += count($items);
    if (empty($sec_groups)) continue;
    $rendered_any = true;
?>
<section class="card def2-section">
    <div class="def2-section-head">
        <div class="def2-section-title">
            <span class="def2-section-icon"><?= $sv['icon'] ?></span>
            <div>
                <h2><?= h($sv['label']) ?> <span class="muted">(<?= $sec_count ?>)</span></h2>
                <p class="muted def2-section-desc"><?= h($sv['desc']) ?></p>
            </div>
        </div>
    </div>

    <?php foreach ($sv['types'] as $t):
        $items = $sec_groups[$t] ?? [];
        if (empty($items)) continue;
        $has_dara = def_has_dara($t);
    ?>
    <div class="def2-typegroup">
        <div class="def2-typegroup-label">
            <?= h($cat_icons[$t] ?? '•') ?> <?= h($type_labels[$t] ?? $t) ?>
            <span class="muted">· <?= count($items) ?></span>
        </div>
        <div class="def2-items">
            <?php foreach ($items as $d):
                $ucnt = def_usage_count($d, $usage_kasa, $usage_palet, $usage_mat, $usage_mov, $usage_cat);
            ?>
            <div class="def2-item<?= !$d['is_active'] ? ' def2-item-off' : '' ?>">
                <form method="post" class="def2-item-form">
                    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="back"   value="<?= h($back_qs) ?>">
                    <?php if (!$has_dara): ?><input type="hidden" name="unit_dara_kg" value="0"><?php endif; ?>

                    <div class="def2-item-main">
                        <input type="text" name="name" value="<?= h($d['name']) ?>"
                               class="def2-item-name" required autocomplete="off">
                        <?php if ($has_dara): ?>
                        <span class="def2-item-dara">
                            <input type="text" name="unit_dara_kg" inputmode="decimal"
                                   value="<?= h($d['unit_dara_kg']) ?>" title="Birim dara (kg)">
                            <small>kg</small>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="def2-item-meta">
                        <?php if ($ucnt > 0): ?>
                        <span class="def2-usage def2-usage-on" title="Geçmiş kayıtlarda kullanılıyor"><?= $ucnt ?> kayıt</span>
                        <?php else: ?>
                        <span class="def2-usage def2-usage-none" title="Hiç kullanılmamış">Kullanılmıyor</span>
                        <?php endif; ?>
                        <label class="def2-switch">
                            <input type="checkbox" name="is_active" <?= $d['is_active'] ? 'checked' : '' ?>>
                            <span class="def2-badge <?= $d['is_active'] ? 'def2-badge-on' : 'def2-badge-off' ?>"><?= $d['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                        </label>
                        <button class="btn btn-sm btn-primary def2-save">Kaydet</button>
                    </div>
                </form>
                <form method="post" class="def2-item-del">
                    <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="back"   value="<?= h($back_qs) ?>">
                    <button class="btn btn-sm btn-ghost def2-del-btn"
                            title="<?= $ucnt > 0 ? 'Kullanımda — silmek yerine pasifleştirilir' : 'Sil' ?>"
                            onclick="return confirm(<?= $ucnt > 0
                                ? "'Bu tanım geçmiş kayıtlarda kullanılıyor. Silinemez, pasif yapılacak. Devam edilsin mi?'"
                                : "'Bu tanım silinsin mi?'" ?>)">✕</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>

<?php if (!$rendered_any && $f_section !== 'operasyon'): ?>
<div class="empty">
    <p><?= $f_q !== '' || $f_durum !== '' || $f_dara ? 'Filtreye uyan tanım bulunamadı.' : 'Henüz tanım yok.' ?></p>
    <?php if ($f_q !== '' || $f_section !== '' || $f_durum !== '' || $f_dara): ?>
    <a href="definitions.php" class="btn btn-ghost">Filtreleri temizle</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($show_operasyon): ?>
<!-- ── Operasyon Tanımları (kod tabanlı, salt okunur) ── -->
<section class="card def2-section">
    <div class="def2-section-head">
        <div class="def2-section-title">
            <span class="def2-section-icon">🚪</span>
            <div>
                <h2>Operasyon Tanımları</h2>
                <p class="muted def2-section-desc">Çıkış nedenleri — sistemde sabittir</p>
            </div>
        </div>
    </div>
    <div class="def2-typegroup">
        <div class="def2-typegroup-label">Çıkış Nedenleri <span class="muted">· <?= count(cikis_nedeni_listesi()) ?></span></div>
        <div class="def2-readonly-chips">
            <?php foreach (cikis_nedeni_listesi() as $cn): ?>
            <span class="def2-ro-chip"><?= h($cn) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="def2-add-help">ℹ️ Çıkış nedenleri uygulama kodunda tanımlıdır (<code>config/helpers.php</code>). Değiştirmek için kod güncellemesi gerekir.</p>
    </div>
</section>
<?php endif; ?>

<script>
(function () {
    var sel  = document.getElementById('defAddType');
    var help = document.getElementById('defAddHelp');
    var dWrap = document.getElementById('defAddDaraWrap');
    if (!sel) return;
    function sync() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt) return;
        help.textContent = opt.getAttribute('data-help') || '';
        var hasDara = opt.getAttribute('data-dara') === '1';
        dWrap.style.display = hasDara ? '' : 'none';
        var inp = dWrap.querySelector('input');
        if (inp && !hasDara) inp.value = '0';
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>

<?php render_footer(); ?>
