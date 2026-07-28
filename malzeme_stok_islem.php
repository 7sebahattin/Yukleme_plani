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

// ── AJAX: Tedarikçi/Firma hızlı ekleme ────────────────────
// Yazılan isim firma tanımlarında yoksa formdaki "+" butonu buraya POST eder;
// isim arka planda tanımlara eklenir (form gönderimi kesilmez).
if (($_GET['ajax'] ?? '') === 'tedarikci_ekle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    csrf_check($in['csrf'] ?? null); // JSON-aware: hata halinde 403+JSON döner
    $name = normalize_firma(trim((string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 200) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz tedarikçi adı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // type='tedarikci' — ihracat müşterileri (type='firma') ile KARIŞTIRILMAZ
    ensure_definition('tedarikci', $name); // TR-duyarsız mükerrer kontrolü içerir
    audit_log_event('create', 'definitions', null, null,
        ['type' => 'tedarikci', 'name' => $name, 'source' => 'malzeme_stok_islem']);
    echo json_encode(['ok' => true, 'name' => $name], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: Seçilen malzeme için güncel stok + son giriş/sevk hareketleri ────
if (($_GET['ajax'] ?? '') === 'malzeme_bilgi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    csrf_check($in['csrf'] ?? null);
    $mtype = trim((string)($in['mat_type'] ?? ''));
    $mname = trim((string)($in['mat_name'] ?? ''));
    $tur   = ($in['tur'] ?? '') === 'sevk' ? 'sevk' : 'giris';
    if (!isset(ms_material_types()[$mtype]) || $mname === '') {
        echo json_encode(['ok' => true, 'stok' => [], 'hareketler' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $bilgi = ms_malzeme_bilgi($pdo, $mtype, $mname, $tur);
    echo json_encode(['ok' => true] + $bilgi, JSON_UNESCAPED_UNICODE);
    exit;
}

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
if (!in_array($mode, ['giris', 'sevk'], true)) $mode = 'giris';

// ── İşlem sonrası dönüş hedefleri ─────────────────────────
function islem_self_url(string $mode, string $return): string {
    $q = ['mode' => $mode];
    if ($return !== '') $q['return'] = $return;
    return 'malzeme_stok_islem.php?' . http_build_query($q);
}
// Hızlı tekrar linki: mode + opsiyonel material_id/depo + return (miktar taşınmaz).
function islem_quick_url(string $mode, array $extra, string $return): string {
    $q = ['mode' => $mode];
    foreach ($extra as $k => $v) {
        if ($v !== '' && $v !== null && $v !== 0) $q[$k] = $v;
    }
    if ($return !== '') $q['return'] = $return;
    return 'malzeme_stok_islem.php?' . http_build_query($q);
}

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
    $mv_unit     = trim($_POST['mv_unit']    ?? 'adet');
    $mv_qty      = parse_stock_quantity(trim($_POST['mv_qty'] ?? ''), $mv_unit);
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
    // Hızlı tekrar için son işlem bilgisi (tek kullanımlık, sadece link üretimi).
    $_SESSION['ms_last_action'] = [
        'mode'          => $mv_type,
        'material_id'   => $mat_id,
        'material_name' => $mv_mat_name,
        'material_type' => $mv_mat_type,
        'depo'          => $mv_depo,
        'firma'         => $mv_firma,
        'belge'         => $mv_belge,
        'movement_id'   => $mv_inserted_id,
        'return'        => $return,
    ];
    // İşlem sayfasında kal: hızlı tekrar kartı gösterilsin (return quick link'te taşınır).
    header('Location: ' . islem_self_url($mv_type, $return));
    exit;
}

// ── Ön-dolum (GET) ────────────────────────────────────────
// material_id verildiyse tanım ID üzerinden ESAS alınır; isim/tür yalnızca
// görünüm için tanımdan çözülür (isimden eşleştirme yapılmaz).
$pf_mat_id    = (int)($_GET['material_id'] ?? 0);
$pf_mat_name  = trim($_GET['mat_name'] ?? '');
$pf_mat_type  = trim($_GET['mat_type'] ?? '');
$pf_depo      = trim($_GET['depo'] ?? '');
$pf_passive   = false;
if ($pf_mat_id > 0) {
    // ID esas: tür/ad daima tanımdan çözülür (URL'deki mat_name/mat_type yok sayılır).
    $st = $pdo->prepare("SELECT type, name, is_active FROM material_definitions WHERE id=? LIMIT 1");
    $st->execute([$pf_mat_id]);
    if ($row = $st->fetch()) {
        $pf_mat_type = (string)$row['type'];
        $pf_mat_name = (string)$row['name'];
        $pf_passive  = !(int)$row['is_active'];
    } else {
        $pf_mat_id = 0; // tanım bulunamadı → ID'yi düşür
    }
}
if (!isset($ms_types[$pf_mat_type])) { $pf_mat_type = ''; $pf_mat_name = ''; }
$prefill = ['mat_type' => $pf_mat_type, 'mat_name' => $pf_mat_name, 'depo' => $pf_depo];

// ── Hızlı tekrar: son işlem bilgisi (tek kullanımlık) ─────
$last_action = null;
if (!empty($_SESSION['ms_last_action'])) {
    $last_action = $_SESSION['ms_last_action'];
    unset($_SESSION['ms_last_action']);
}

// ── Üst "Hareketlere Git" linki — ön-dolum varsa filtreli ─
$hareket_link = ms_hareket_url([
    'mat_id' => $pf_mat_id > 0 ? $pf_mat_id : '',
    'depo'   => $pf_depo,
]);

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
        <a href="<?= h($hareket_link) ?>" class="btn btn-sm btn-ghost">📜 Hareketlere Git</a>
    </div>
</div>

<?php if ($pf_passive): ?>
<div class="flash flash-error" style="margin-bottom:12px">
    ⚠ Bu malzeme <strong>pasif tanımdır</strong>. İşlem yapmadan önce doğru malzemeyi seçtiğinizden emin olun.
</div>
<?php endif; ?>

<?php if ($last_action):
    $la_mid   = (int)($last_action['material_id'] ?? 0);
    $la_depo  = (string)($last_action['depo'] ?? '');
    $la_name  = (string)($last_action['material_name'] ?? '');
    $la_ret   = islem_safe_return($last_action['return'] ?? '');
    $la_firma = (string)($last_action['firma'] ?? '');
    $la_belge = (string)($last_action['belge'] ?? '');
?>
<div class="card ms-quick-card">
    <div class="ms-quick-head">✓ İşlem kaydedildi. Ne yapmak istersiniz?</div>
    <?php if ($la_firma !== '' || $la_belge !== ''): ?>
    <div class="ms-quick-detail">
        <?php if ($la_firma !== ''): ?><span>Firma: <strong><?= h($la_firma) ?></strong></span><?php endif; ?>
        <?php if ($la_belge !== ''): ?><span>Belge: <strong><?= h($la_belge) ?></strong></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="ms-quick-btns">
        <?php if ($la_mid > 0): ?>
        <a href="<?= h(islem_quick_url('giris', ['material_id' => $la_mid, 'depo' => $la_depo], $la_ret)) ?>"
           class="btn btn-sm btn-primary">➕ Aynı malzemeye tekrar giriş<?= $la_name !== '' ? ' (' . h($la_name) . ')' : '' ?></a>
        <?php endif; ?>
        <?php if ($la_depo !== ''): ?>
        <a href="<?= h(islem_quick_url('giris', ['depo' => $la_depo], $la_ret)) ?>"
           class="btn btn-sm btn-secondary">📦 Aynı depoya yeni giriş (<?= h($la_depo) ?>)</a>
        <?php endif; ?>
        <a href="<?= h(ms_hareket_url(['mat_id' => $la_mid > 0 ? $la_mid : '', 'depo' => $la_depo])) ?>"
           class="btn btn-sm btn-ghost">📜 Hareketleri gör</a>
        <a href="<?= h($la_ret !== '' ? $la_ret : 'malzeme_stok.php') ?>"
           class="btn btn-sm btn-ghost">← Stoklara dön</a>
    </div>
</div>
<?php endif; ?>

<!-- ── Sekmeler ───────────────────────────────────────────── -->
<div class="ms-form-wrap">
    <div class="ms-form-tabs">
        <button type="button" class="ms-tab-btn<?= $mode === 'giris' ? ' ms-tab-active' : '' ?>" id="tabGiris" onclick="msTab('giris')">
            ➕ Giriş
        </button>
        <button type="button" class="ms-tab-btn<?= $mode === 'sevk' ? ' ms-tab-active' : '' ?>" id="tabSevk" onclick="msTab('sevk')">
            ↗ Sevk / Çıkış
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
                    <?php $_aktif_depo_ms = (function_exists('active_depot') ? active_depot() : null) ?? ''; ?>
                    <select name="mv_depo" id="girisDepo" class="form-control" required>
                        <option value="">— seçiniz —</option>
                        <?php foreach ($depo_list as $dv): ?>
                        <option value="<?= h($dv) ?>"<?= ($prefill['depo'] !== '' ? $prefill['depo'] : $_aktif_depo_ms) === $dv ? ' selected' : '' ?>><?= h($dv) ?></option>
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
                    <input type="text" name="mv_belge" id="girisBelge" class="form-control" placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Tedarikçi</label>
                    <input type="text" name="mv_firma" id="girisFirma" class="form-control"
                           placeholder="Yazmaya başlayın…" autocomplete="off" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>

            <div class="ms-submit-row">
                <button type="submit" class="btn btn-primary">💾 Girişi Kaydet</button>
            </div>

            <!-- Seçili malzeme için güncel stok + son girişler (Pro-09) -->
            <div id="girisStokInfo" class="ms-stok-info" hidden>
                <div class="ms-stok-satir" id="girisStokSatir"></div>
            </div>
            <div id="girisSonHareket" class="ms-son-hareket" hidden>
                <div class="ms-son-hareket-baslik">🕘 Son Girişler</div>
                <div class="ms-son-hareket-liste" id="girisSonHareketListe"></div>
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
                    <input type="text" name="mv_belge" id="sevkBelge" class="form-control" placeholder="İsteğe bağlı" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Gönderilen Firma</label>
                    <input type="text" name="mv_firma" id="sevkFirma" class="form-control"
                           placeholder="Yazmaya başlayın…" autocomplete="off" data-uppercase="tr">
                </div>
                <div class="form-group">
                    <label class="form-label">Not</label>
                    <input type="text" name="mv_note" class="form-control" placeholder="İsteğe bağlı">
                </div>
            </div>

            <div class="ms-submit-row">
                <button type="submit" class="btn btn-danger">↗ Sevki Kaydet</button>
            </div>

            <!-- Seçili malzeme için güncel stok + son sevkler (Pro-09) -->
            <div id="sevkStokInfo" class="ms-stok-info" hidden>
                <div class="ms-stok-satir" id="sevkStokSatir"></div>
            </div>
            <div id="sevkSonHareket" class="ms-son-hareket" hidden>
                <div class="ms-son-hareket-baslik">🕘 Son Sevkler</div>
                <div class="ms-son-hareket-liste" id="sevkSonHareketListe"></div>
            </div>
        </form>
    </div>

</div>

<script>
var msNamesData  = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;
var msPrefill    = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE) ?>;
var msDepoKey    = 'asyaFresh.materialStock.lastDepo';
var msCurrentTab = <?= json_encode($mode, JSON_UNESCAPED_UNICODE) ?>;

function msUpdateNames(form, selectedName) {
    var typeId  = form === 'giris' ? 'girisMatType' : 'sevkMatType';
    var nameId  = form === 'giris' ? 'girisMatName' : 'sevkMatName';
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
    // Liste yeniden kurulduğu için stok/son hareket panelleri güncel değil —
    // isim seçili kaldıysa (found) yeniden getir, boşaldıysa gizle.
    // (nameSel.innerHTML değişimi 'change' olayını tetiklemez, bu yüzden
    // buradan elle çağrılması gerekir.)
    msLoadMalzemeBilgi(form);
}

function msTab(tab) {
    // Geçişten önce mevcut formun değerlerini al (miktar ve not TAŞINMAZ)
    var fromType  = document.getElementById(msCurrentTab + 'MatType');
    var fromName  = document.getElementById(msCurrentTab + 'MatName');
    var fromDepo  = document.getElementById(msCurrentTab + 'Depo');
    var fromFirma = document.getElementById(msCurrentTab + 'Firma');
    var fromBelge = document.getElementById(msCurrentTab + 'Belge');
    var carry = {
        type:  fromType  ? fromType.value  : '',
        name:  fromName  ? fromName.value  : '',
        depo:  fromDepo  ? fromDepo.value  : '',
        firma: fromFirma ? fromFirma.value : '',
        belge: fromBelge ? fromBelge.value : '',
    };

    var tabs = ['giris', 'sevk'];
    tabs.forEach(function(t) {
        var cap = t.charAt(0).toUpperCase() + t.slice(1);
        var el  = document.getElementById('msForm'  + cap);
        var btn = document.getElementById('tab'     + cap);
        if (el)  el.hidden = (t !== tab);
        if (btn) btn.classList.toggle('ms-tab-active', t === tab);
    });

    // Hedef forma değerleri taşı (yalnızca farklı sekmeye geçişte)
    if (tab !== msCurrentTab) {
        if (carry.type) { msSetSelect(tab + 'MatType', carry.type); msUpdateNames(tab, carry.name || ''); }
        if (carry.depo) { msSetSelect(tab + 'Depo', carry.depo); }
        var toFirma = document.getElementById(tab + 'Firma');
        var toBelge = document.getElementById(tab + 'Belge');
        if (toFirma && carry.firma) toFirma.value = carry.firma;
        if (toBelge && carry.belge) toBelge.value = carry.belge;
    }
    msCurrentTab = tab;
    msLoadMalzemeBilgi(tab);
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

function msReadLastDepo()  { try { return localStorage.getItem(msDepoKey)  || ''; } catch(e) { return ''; } }
function msSaveLastDepo(v) { if (!v) return; try { localStorage.setItem(msDepoKey, v);  } catch(e) {} }

// ── Pro-09: Seçili malzeme için güncel stok + son giriş/sevk hareketleri ──
var msCsrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
// Yarış durumu koruması FORM BAZLI: giris ve sevk panelleri bağımsızdır — ortak
// tek sayaç kullanılsaydı, ikisi art arda tetiklendiğinde (ör. ön-dolumda) ilk
// formun geçerli cevabı "eskimiş" sayılıp atılırdı.
var msBilgiSeq = { giris: 0, sevk: 0 };

function msFmtMiktar(v, unit) {
    var n = parseFloat(v) || 0;
    var opt = (unit === 'adet' || unit === 'paket' || unit === 'top' || unit === 'set' || unit === 'çift')
        ? { maximumFractionDigits: 0 } : { maximumFractionDigits: 2 };
    return n.toLocaleString('tr-TR', opt) + ' ' + (unit || '');
}
function msFmtTarih(iso) {
    if (!iso) return '';
    var p = String(iso).split('-');
    return p.length === 3 ? (p[2] + '.' + p[1] + '.' + p[0]) : iso;
}

function msLoadMalzemeBilgi(form) {
    var typeSel = document.getElementById(form + 'MatType');
    var nameSel = document.getElementById(form + 'MatName');
    var stokBox = document.getElementById(form + 'StokInfo');
    var stokRow = document.getElementById(form + 'StokSatir');
    var hkBox   = document.getElementById(form + 'SonHareket');
    var hkList  = document.getElementById(form + 'SonHareketListe');
    if (!typeSel || !nameSel || !stokBox || !hkBox) return;

    var mtype = typeSel.value, mname = nameSel.value;
    if (!mtype || !mname) {
        stokBox.hidden = true; hkBox.hidden = true;
        return;
    }

    var seq = ++msBilgiSeq[form];
    fetch('malzeme_stok_islem.php?ajax=malzeme_bilgi', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ csrf: msCsrf, mat_type: mtype, mat_name: mname, tur: form })
    }).then(function(r) { return r.json(); }).then(function(j) {
        if (seq !== msBilgiSeq[form] || !j || !j.ok) return;   // eskimiş yanıt veya hata — sessizce geç

        // Güncel stok
        if (j.stok && j.stok.length) {
            stokRow.innerHTML = j.stok.map(function(s) {
                var depoTxt = s.depo ? s.depo : '(depo atanmamış)';
                return '<span class="ms-stok-chip"><b>' + depoTxt + ':</b> ' + msFmtMiktar(s.kalan, s.unit) + '</span>';
            }).join('');
            stokBox.hidden = false;
        } else {
            stokRow.innerHTML = '<span class="ms-stok-chip ms-stok-yok">Bu malzemede kayıtlı stok yok (kalan: 0).</span>';
            stokBox.hidden = false;
        }

        // Son hareketler — kayıt yoksa panel tamamen gizlenir
        if (j.hareketler && j.hareketler.length) {
            hkList.innerHTML = j.hareketler.map(function(h) {
                var parcalar = [msFmtTarih(h.movement_date), h.depo || '—', msFmtMiktar(h.quantity, h.unit)];
                if (h.firma) parcalar.push(h.firma);
                if (h.belge_no) parcalar.push('Belge: ' + h.belge_no);
                return '<div class="ms-son-hareket-satir">' + parcalar.map(function(p) {
                    return '<span>' + p.replace(/[&<>"]/g, function(c) {
                        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
                    }) + '</span>';
                }).join('') + '</div>';
            }).join('');
            hkBox.hidden = false;
        } else {
            hkBox.hidden = true;
        }
    }).catch(function() { /* sessiz geç — form akışını bozma */ });
}

document.addEventListener('DOMContentLoaded', function() {
    var forms = ['giris', 'sevk'];

    // 1) Ön-dolum: tür/ad/depo (URL/material_id öncelikli)
    if (msPrefill && (msPrefill.mat_type || msPrefill.depo)) {
        forms.forEach(function(form) {
            if (msPrefill.mat_type) {
                msSetSelect(form + 'MatType', msPrefill.mat_type);
                msUpdateNames(form, msPrefill.mat_name || '');
            }
            if (msPrefill.depo) msSetSelect(form + 'Depo', msPrefill.depo);
        });
    }

    // 2) Depo hatırlama: URL/prefill depo YOKSA son kullanılan depoyu doldur
    if (!msPrefill || !msPrefill.depo) {
        var lastDepo = msReadLastDepo();
        if (lastDepo) {
            forms.forEach(function(form) {
                var sel = document.getElementById(form + 'Depo');
                if (sel && !sel.value) msSetSelect(form + 'Depo', lastDepo);
            });
        }
    }

    // 3) Depo değişince localStorage'a kaydet
    forms.forEach(function(form) {
        var sel = document.getElementById(form + 'Depo');
        if (sel) sel.addEventListener('change', function() { msSaveLastDepo(sel.value); });
    });

    // 4) Malzeme adı seçilince/değişince güncel stok + son hareketleri getir
    forms.forEach(function(form) {
        var nameSel = document.getElementById(form + 'MatName');
        if (nameSel) nameSel.addEventListener('change', function() { msLoadMalzemeBilgi(form); });
    });

    // 5) Çift gönderim engeli: submit'te kaydet butonunu kilitle
    document.querySelectorAll('.ms-action-card form').forEach(function(frm) {
        frm.addEventListener('submit', function() {
            var btn = frm.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.dataset.label = btn.textContent;
                btn.textContent = '⏳ Kaydediliyor...';
                // Güvenlik: 6 sn sonra tekrar aç (ağ hatasında kilitli kalmasın)
                setTimeout(function() {
                    btn.disabled = false;
                    if (btn.dataset.label) btn.textContent = btn.dataset.label;
                }, 6000);
            }
        });
    });

    // NOT: ön-dolum ile malzeme adı geldiyse msUpdateNames() (adım 1 içinde
    // çağrılır) zaten msLoadMalzemeBilgi()'yi tetikler — ayrıca çağırmaya gerek yok.
});
</script>

<!-- ── Tedarikçi öneri kutusu: yazınca süz, eşleşme yoksa "+ ekle" listede ── -->
<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var suppliers = <?= json_encode(array_values($firma_list), JSON_UNESCAPED_UNICODE) ?>;

    // TR-duyarsız katlama (definitions.php ile aynı kurallar)
    function fold(s) {
        return (s || '').replace(/İ/g,'i').replace(/I/g,'i').replace(/Ş/g,'s').replace(/Ğ/g,'g')
            .replace(/Ü/g,'u').replace(/Ö/g,'o').replace(/Ç/g,'c')
            .replace(/ı/g,'i').replace(/ş/g,'s').replace(/ğ/g,'g')
            .replace(/ü/g,'u').replace(/ö/g,'o').replace(/ç/g,'c').toLowerCase().trim();
    }
    function hasExact(v) {
        var f = fold(v);
        return suppliers.some(function (s) { return fold(s) === f; });
    }

    function attach(input) {
        if (!input) return;
        var wrap = document.createElement('div');
        wrap.className = 'ms-sug-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var list = document.createElement('div');
        list.className = 'ms-sug-list';
        list.hidden = true;
        wrap.appendChild(list);
        var active = -1; // klavye ile seçili satır

        function close() { list.hidden = true; active = -1; }

        function pick(name) { input.value = name; close(); }

        function addSupplier(name, row) {
            row.classList.add('busy');
            row.textContent = '⏳ Ekleniyor…';
            fetch('malzeme_stok_islem.php?ajax=tedarikci_ekle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ csrf: csrf, name: name })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { row.textContent = '⚠ ' + (j.error || 'Eklenemedi'); row.classList.remove('busy'); return; }
                if (!hasExact(j.name)) suppliers.push(j.name);
                pick(j.name);
            }).catch(function () {
                row.textContent = '⚠ Bağlantı hatası'; row.classList.remove('busy');
            });
        }

        function render() {
            var v = input.value.trim();
            var q = fold(v);
            // Süz: yazılan geçen tüm tedarikçiler (boşsa ilk 8 önerilir)
            var matches = suppliers.filter(function (s) {
                return q === '' || fold(s).indexOf(q) !== -1;
            }).slice(0, 8);

            list.innerHTML = '';
            matches.forEach(function (name) {
                var row = document.createElement('div');
                row.className = 'ms-sug-row';
                row.textContent = name;
                // mousedown: input blur'undan ÖNCE çalışır — seçim kaybolmaz
                row.addEventListener('mousedown', function (e) { e.preventDefault(); pick(name); });
                list.appendChild(row);
            });
            // Eşleşme yoksa / birebir aynısı yoksa: "+ ekle" satırı LİSTENİN İÇİNDE
            if (v !== '' && !hasExact(v)) {
                var add = document.createElement('div');
                add.className = 'ms-sug-row ms-sug-add';
                add.textContent = '➕ "' + v.toUpperCase() + '" tedarikçisini ekle';
                add.addEventListener('mousedown', function (e) { e.preventDefault(); addSupplier(v, add); });
                list.appendChild(add);
            }
            list.hidden = list.childNodes.length === 0;
            active = -1;
        }

        function rows() { return list.querySelectorAll('.ms-sug-row'); }

        input.addEventListener('input', render);
        input.addEventListener('focus', render);
        input.addEventListener('blur', function () { setTimeout(close, 150); });
        input.addEventListener('keydown', function (e) {
            if (list.hidden) return;
            var rs = rows();
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, rs.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); }
            else if (e.key === 'Enter') {
                if (active >= 0 && rs[active]) {
                    e.preventDefault();
                    rs[active].dispatchEvent(new Event('mousedown'));
                }
                return;
            }
            else if (e.key === 'Escape') { close(); return; }
            else return;
            rs.forEach(function (r, i) { r.classList.toggle('active', i === active); });
        });
    }

    attach(document.getElementById('girisFirma'));
    attach(document.getElementById('sevkFirma'));
})();
</script>

<?php render_footer(); ?>
