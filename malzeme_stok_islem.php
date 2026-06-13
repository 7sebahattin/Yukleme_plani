<?php
// =========================================================
// malzeme_stok_islem.php — Malzeme Stok İşlemi (Giriş/Sevk/Düzeltme)
// Formlar Pro-03'te malzeme_stok.php'den taşındı. POST davranışı korundu.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/material_stock_helpers.php';
$auth_user = require_login();
require_perm('stok.write');

$pdo = db();

$ms_types = ms_material_types();
$ms_units = ms_stock_units();

// ── return güvenliği — yalnızca yerel .php yolu ────────────
function islem_safe_return(?string $r): string {
    $r = trim((string)$r);
    if ($r === '') return '';
    // şema (http://), protokol-relative (//), mutlak yol (/...) reddedilir
    if (preg_match('~^[A-Za-z0-9_]+\.php(\?[^#\s]*)?$~', $r)) return $r;
    return '';
}
$return = islem_safe_return($_GET['return'] ?? ($_POST['return'] ?? ''));

// ── Mod (sekme) ───────────────────────────────────────────
$mode = (string)($_GET['mode'] ?? 'giris');
if (!in_array($mode, ['giris', 'sevk', 'duzeltme'], true)) $mode = 'giris';

// ── İşlem sonrası dönüş hedefleri ─────────────────────────
$success_url = $return !== '' ? $return : 'malzeme_stok.php';
function islem_self_url(string $mode, string $return): string {
    $q = ['mode' => $mode];
    if ($return !== '') $q['return'] = $return;
    return 'malzeme_stok_islem.php?' . http_build_query($q);
}
$error_url = islem_self_url($mode, $return);

// ── POST: Giriş / Sevk kaydet ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['ms_giris', 'ms_sevk'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

    $mv_type     = ($_POST['action'] === 'ms_sevk') ? 'sevk' : 'giris';
    $mv_date     = trim($_POST['mv_date']    ?? '');
    $mv_mat_type = trim($_POST['mv_mat_type'] ?? '');
    $mv_mat_name = trim($_POST['mv_mat_name'] ?? '');
    $mv_depo     = trim($_POST['mv_depo']    ?? '');
    $mv_qty      = num($_POST['mv_qty']      ?? '0');
    $mv_unit     = trim($_POST['mv_unit']    ?? 'adet');
    $mv_belge    = trim($_POST['mv_belge']   ?? '');
    $mv_firma    = trim($_POST['mv_firma']   ?? '');
    $mv_note     = trim($_POST['mv_note']    ?? '');

    $err = '';
    if (!$mv_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mv_date)) {
        $err = 'Tarih zorunludur (YYYY-AA-GG).';
    } elseif (!isset($ms_types[$mv_mat_type])) {
        $err = 'Malzeme türü seçiniz.';
    } elseif ($mv_mat_name === '') {
        $err = 'Malzeme adı zorunludur.';
    } elseif ($mv_depo === '') {
        $err = 'Depo seçimi zorunludur.';
    } elseif ($mv_qty <= 0) {
        $err = 'Miktar sıfırdan büyük olmalıdır.';
    }

    if ($err !== '') {
        set_flash('error', $err);
        header('Location: ' . islem_self_url($mv_type === 'sevk' ? 'sevk' : 'giris', $return));
        exit;
    }

    $mat_row   = ms_find_material_definition($pdo, $mv_mat_type, $mv_mat_name);
    $mat_id    = $mat_row ? (int)$mat_row['id'] : null;
    $unit_dara = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;
    $total_dara = round($mv_qty * $unit_dara, 3);

    $pdo->prepare(
        "INSERT INTO material_stock_movements
         (movement_date, movement_type, material_id, material_name, material_type,
          depo, quantity, unit, unit_dara_kg, total_dara_kg, belge_no, firma, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $mv_date, $mv_type, $mat_id, $mv_mat_name, $mv_mat_type,
        $mv_depo, $mv_qty, $mv_unit, $unit_dara, $total_dara,
        $mv_belge, $mv_firma, $mv_note ?: null,
    ]);
    $mv_inserted_id = (int)$pdo->lastInsertId();
    audit_log_event('create', 'malzeme_stok', $mv_inserted_id, null, [
        'movement_type' => $mv_type,
        'material_id'   => $mat_id,
        'material_name' => $mv_mat_name,
        'material_type' => $mv_mat_type,
        'depo'          => $mv_depo,
        'quantity'      => $mv_qty,
        'unit'          => $mv_unit,
        'belge_no'      => $mv_belge,
        'firma'         => $mv_firma,
        'note'          => $mv_note,
    ]);

    $lbl = $mv_type === 'giris' ? 'Giriş' : 'Sevk çıkışı';
    set_flash('success', "$lbl kaydedildi: $mv_mat_name · " . fmt_kg($mv_qty) . " $mv_unit");
    header('Location: ' . $success_url);
    exit;
}

// ── POST: Bağımsız Düzeltme ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_duzeltme_direkt') {
    csrf_check($_POST['csrf'] ?? null);
    require_perm('stok.write');

    $dz_date     = trim($_POST['dz_date']     ?? '');
    $dz_mat_type = trim($_POST['dz_mat_type'] ?? '');
    $dz_mat_name = trim($_POST['dz_mat_name'] ?? '');
    $dz_depo     = trim($_POST['dz_depo']     ?? '');
    $dz_qty_raw  = num($_POST['dz_qty']       ?? '0');
    $dz_yon      = trim($_POST['dz_yon']      ?? 'arti');
    $dz_unit     = trim($_POST['dz_unit']     ?? 'adet');
    $dz_belge    = trim($_POST['dz_belge']    ?? '');
    $dz_note     = trim($_POST['dz_note']     ?? '');
    $dz_qty      = $dz_yon === 'eksi' ? -abs($dz_qty_raw) : abs($dz_qty_raw);

    $err = '';
    if (!$dz_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dz_date)) {
        $err = 'Tarih zorunludur.';
    } elseif (!isset($ms_types[$dz_mat_type])) {
        $err = 'Malzeme türü seçiniz.';
    } elseif ($dz_mat_name === '') {
        $err = 'Malzeme adı zorunludur.';
    } elseif ($dz_depo === '') {
        $err = 'Depo seçimi zorunludur.';
    } elseif ($dz_qty_raw == 0.0) {
        $err = 'Miktar sıfır olamaz.';
    }

    if ($err !== '') {
        set_flash('error', $err);
        header('Location: ' . islem_self_url('duzeltme', $return));
        exit;
    }

    $mat_row   = ms_find_material_definition($pdo, $dz_mat_type, $dz_mat_name);
    $mat_id    = $mat_row ? (int)$mat_row['id'] : null;
    $unit_dara = $mat_row ? (float)$mat_row['unit_dara_kg'] : 0.0;

    $pdo->prepare(
        "INSERT INTO material_stock_movements
         (movement_date, movement_type, material_id, material_name, material_type,
          depo, quantity, unit, unit_dara_kg, total_dara_kg, belge_no, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([
        $dz_date, 'duzeltme', $mat_id, $dz_mat_name, $dz_mat_type,
        $dz_depo, $dz_qty, $dz_unit, $unit_dara, round($dz_qty * $unit_dara, 3),
        $dz_belge, $dz_note ?: null,
    ]);
    $dz_inserted_id = (int)$pdo->lastInsertId();
    audit_log_event('create', 'malzeme_stok', $dz_inserted_id, null, [
        'movement_type' => 'duzeltme',
        'direction'     => $dz_yon,
        'material_id'   => $mat_id,
        'material_name' => $dz_mat_name,
        'material_type' => $dz_mat_type,
        'depo'          => $dz_depo,
        'quantity'      => $dz_qty,
        'unit'          => $dz_unit,
        'belge_no'      => $dz_belge,
        'note'          => $dz_note,
    ]);
    $lbl = $dz_yon === 'eksi' ? 'Eksi düzeltme' : 'Artı düzeltme';
    set_flash('success', "$lbl kaydedildi: $dz_mat_name · " . ($dz_qty >= 0 ? '+' : '') . fmt_kg($dz_qty) . ' ' . $dz_unit);
    header('Location: ' . $success_url);
    exit;
}

// ── Ön-dolum (GET) ────────────────────────────────────────
// material_id verildiyse tanım ID üzerinden ESAS alınır; isim/tür yalnızca
// görünüm için tanımdan çözülür (isimden eşleştirme yapılmaz).
$pf_mat_id   = (int)($_GET['material_id'] ?? 0);
$pf_mat_name = trim($_GET['mat_name'] ?? '');
$pf_mat_type = trim($_GET['mat_type'] ?? '');
$pf_depo     = trim($_GET['depo'] ?? '');
if ($pf_mat_id > 0) {
    $st = $pdo->prepare("SELECT type, name FROM material_definitions WHERE id=? LIMIT 1");
    $st->execute([$pf_mat_id]);
    if ($row = $st->fetch()) { $pf_mat_type = (string)$row['type']; $pf_mat_name = (string)$row['name']; }
}
if (!isset($ms_types[$pf_mat_type])) { $pf_mat_type = ''; $pf_mat_name = ''; }
$prefill = ['mat_type' => $pf_mat_type, 'mat_name' => $pf_mat_name, 'depo' => $pf_depo];

// ── Dropdown verileri ─────────────────────────────────────
$ms_dd             = get_material_dropdown_data($pdo);
$depo_list         = $ms_dd['depo_list'];
$mat_names_by_type = $ms_dd['mat_names_by_type'];
$firma_list        = $ms_dd['firma_list'];

render_header('Malzeme Stok İşlemi');
render_flash();
?>

<div class="page-head">
    <div>
        <h2 class="page-title">➕ Malzeme Stok İşlemi</h2>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">
            Stok giriş, sevk ve düzeltme işlemlerini buradan yapabilirsiniz.
        </p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <a href="<?= h($return !== '' ? $return : 'malzeme_stok.php') ?>" class="btn btn-sm btn-secondary">← Stoklara Dön</a>
        <a href="malzeme_hareketleri.php" class="btn btn-sm btn-ghost">📜 Hareketlere Git</a>
    </div>
</div>

<!-- ── Sekmeler ───────────────────────────────────────────── -->
<div class="ms-form-wrap">
    <div class="ms-form-tabs">
        <button type="button" class="ms-tab-btn<?= $mode === 'giris' ? ' ms-tab-active' : '' ?>" id="tabGiris" onclick="msTab('giris')">
            ➕ Giriş
        </button>
        <button type="button" class="ms-tab-btn<?= $mode === 'sevk' ? ' ms-tab-active' : '' ?>" id="tabSevk" onclick="msTab('sevk')">
            ↗ Sevk / Çıkış
        </button>
        <button type="button" class="ms-tab-btn<?= $mode === 'duzeltme' ? ' ms-tab-active' : '' ?>" id="tabDuzeltme" onclick="msTab('duzeltme')">
            ± Düzeltme
        </button>
    </div>

    <!-- Giriş formu -->
    <div id="msFormGiris" class="card ms-form-card ms-action-card"<?= $mode === 'giris' ? '' : ' hidden' ?>>
        <form method="post" action="<?= h(islem_self_url('giris', $return)) ?>" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_giris">
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= h($return) ?>"><?php endif; ?>
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="mv_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="mv_mat_type" class="form-control" id="girisMatType" required onchange="msUpdateNames('giris')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="mv_mat_name" id="girisMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="mv_depo" id="girisDepo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="mv_qty" class="form-control" required min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="mv_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / İrsaliye No</label>
                    <input type="text" name="mv_belge" class="form-control" placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Tedarikçi / Firma</label>
                    <input type="text" name="mv_firma" class="form-control"
                           list="ms-firma-list" placeholder="İsteğe bağlı" autocomplete="off" data-uppercase="tr">
                    <datalist id="ms-firma-list">
                        <?php foreach ($firma_list as $fv): ?>
                        <option value="<?= h($fv) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-primary">💾 Girişi Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Sevk formu -->
    <div id="msFormSevk" class="card ms-form-card ms-action-card"<?= $mode === 'sevk' ? '' : ' hidden' ?>>
        <form method="post" action="<?= h(islem_self_url('sevk', $return)) ?>" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_sevk">
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= h($return) ?>"><?php endif; ?>
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="mv_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="mv_mat_type" class="form-control" id="sevkMatType" required onchange="msUpdateNames('sevk')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="mv_mat_name" id="sevkMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="mv_depo" id="sevkDepo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="mv_qty" class="form-control" required min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="mv_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / İrsaliye No</label>
                    <input type="text" name="mv_belge" class="form-control" placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Gönderilen Firma</label>
                    <input type="text" name="mv_firma" class="form-control"
                           list="ms-firma-list" placeholder="İsteğe bağlı" autocomplete="off" data-uppercase="tr">
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-danger">↗ Sevki Kaydet</button>
            </div>
        </form>
    </div>

    <!-- Düzeltme formu -->
    <div id="msFormDuzeltme" class="card ms-form-card ms-action-card"<?= $mode === 'duzeltme' ? '' : ' hidden' ?>>
        <p style="font-size:.84rem;color:var(--muted);margin-bottom:12px">
            Stok sayım farkını veya hatalı girişi düzeltmek için kullanın.
            <strong>Artı düzeltme</strong> stoka ekler, <strong>eksi düzeltme</strong> stoktan çıkarır.
        </p>
        <form method="post" action="<?= h(islem_self_url('duzeltme', $return)) ?>" autocomplete="off">
            <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="ms_duzeltme_direkt">
            <?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= h($return) ?>"><?php endif; ?>
            <div class="ms-form-grid">
                <div class="form-group">
                    <label class="form-label">Tarih <span class="req">*</span></label>
                    <input type="date" name="dz_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Türü <span class="req">*</span></label>
                    <select name="dz_mat_type" class="form-control" id="duzeltmeMatType" required onchange="msUpdateNames('duzeltme')">
                        <option value="">— seçiniz —</option>
                        <?php foreach ($ms_types as $k => $lbl): ?>
                        <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Malzeme Adı <span class="req">*</span></label>
                    <select name="dz_mat_name" id="duzeltmeMatName" class="form-control" required>
                        <option value="">— önce tür seçin —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Depo <span class="req">*</span></label>
                    <select name="dz_depo" id="duzeltmeDepo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"><?= h($dv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Miktar <span class="req">*</span></label>
                    <input type="number" name="dz_qty" class="form-control" required min="0.001" step="any" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Birim</label>
                    <select name="dz_unit" class="form-control">
                        <?php foreach ($ms_units as $u): ?>
                        <option value="<?= h($u) ?>"><?= h($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Yön <span class="req">*</span></label>
                    <select name="dz_yon" class="form-control" required>
                        <option value="arti">+ Artı düzeltme (stoka ekle)</option>
                        <option value="eksi">− Eksi düzeltme (stoktan çıkar)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Belge / Açıklama</label>
                    <input type="text" name="dz_belge" class="form-control" placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group ms-form-full">
                    <label class="form-label">Not</label>
                    <input type="text" name="dz_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>
            <div style="margin-top:12px">
                <button type="submit" class="btn btn-secondary">± Düzeltmeyi Kaydet</button>
            </div>
        </form>
    </div>
</div>

<script>
var msNamesData = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msPrefill   = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE) ?>;

function msUpdateNames(form, selectedName) {
    var typeId  = form === 'giris' ? 'girisMatType' : (form === 'sevk' ? 'sevkMatType' : 'duzeltmeMatType');
    var nameId  = form === 'giris' ? 'girisMatName' : (form === 'sevk' ? 'sevkMatName' : 'duzeltmeMatName');
    var typeSel = document.getElementById(typeId);
    var nameSel = document.getElementById(nameId);
    if (!typeSel || !nameSel) return;
    var names = msNamesData[typeSel.value] || [];
    nameSel.innerHTML = '<option value="">— seçiniz —</option>';
    var found = false;
    names.forEach(function(n) {
        var opt = document.createElement('option');
        opt.value = n; opt.textContent = n;
        if (selectedName && n === selectedName) { opt.selected = true; found = true; }
        nameSel.appendChild(opt);
    });
    // Tanımda olmayan ama istenen ad: yine de seçilebilsin
    if (!found && selectedName) {
        var opt = document.createElement('option');
        opt.value = selectedName; opt.textContent = selectedName; opt.selected = true;
        nameSel.appendChild(opt);
    }
    nameSel.disabled = names.length === 0 && !selectedName;
}

function msTab(tab) {
    var tabs = ['giris', 'sevk', 'duzeltme'];
    tabs.forEach(function(t) {
        var cap = t.charAt(0).toUpperCase() + t.slice(1);
        var el  = document.getElementById('msForm'  + cap);
        var btn = document.getElementById('tab'     + cap);
        if (el)  el.hidden = (t !== tab);
        if (btn) btn.classList.toggle('ms-tab-active', t === tab);
    });
}

function msSetSelect(id, val) {
    var sel = document.getElementById(id);
    if (!sel || !val) return;
    var has = false;
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val) { has = true; break; }
    }
    if (!has) {
        var opt = document.createElement('option');
        opt.value = val; opt.textContent = val;
        sel.appendChild(opt);
    }
    sel.value = val;
}

// ── Ön-dolum: her formda tür/ad/depo doldur ──
document.addEventListener('DOMContentLoaded', function() {
    if (!msPrefill || (!msPrefill.mat_type && !msPrefill.depo)) return;
    ['giris', 'sevk', 'duzeltme'].forEach(function(form) {
        var cap = form.charAt(0).toUpperCase() + form.slice(1);
        if (msPrefill.mat_type) {
            msSetSelect(form + 'MatType', msPrefill.mat_type);
            msUpdateNames(form, msPrefill.mat_name || '');
        }
        if (msPrefill.depo) msSetSelect(form + 'Depo', msPrefill.depo);
    });
});
</script>

<?php render_footer(); ?>
