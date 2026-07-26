<?php
// =========================================================
// maliyet_alanlar.php — Maliyet sayfasına sonradan alan / hesap ekleme
//   Buradan eklenen her alan tüm maliyet hesaplarında görünür ve
//   formüllerde [kod] olarak kullanılabilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('admin');

$edit_id = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $del = (int)($_POST['id'] ?? 0);
        $st  = db()->prepare("SELECT * FROM cost_field_defs WHERE id=?");
        $st->execute([$del]);
        if ($row = $st->fetch()) {
            db()->prepare("DELETE FROM cost_field_defs WHERE id=?")->execute([$del]);
            audit_log_event('delete', 'maliyet_alan', $del,
                ['code' => $row['code'], 'label' => $row['label'], 'type' => $row['field_type']], null);
            set_flash('success', '“' . $row['label'] . '” alanı silindi. Mevcut hesaplardaki değerleri korunur.');
        }
        header('Location: maliyet_alanlar.php'); exit;
    }

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        $type  = (string)($_POST['field_type'] ?? 'text');
        if (!array_key_exists($type, cost_field_types())) $type = 'text';

        $code = cost_slug((string)($_POST['code'] ?? '') ?: $label);
        $errs = [];
        if ($label === '') $errs[] = 'Alan adı zorunlu.';
        if ($code === '')  $errs[] = 'Kod üretilemedi — farklı bir ad girin.';

        // Sistem değişkeni adıyla çakışma
        if (array_key_exists($code, cost_system_vars())) {
            $errs[] = '“' . $code . '” sistem değişkeni adıyla çakışıyor. Farklı bir kod seçin.';
        }
        // Kod tekilliği
        $st = db()->prepare("SELECT id FROM cost_field_defs WHERE code=? AND id<>?");
        $st->execute([$code, $id]);
        if ($st->fetchColumn()) $errs[] = '“' . $code . '” kodu zaten kullanılıyor.';

        $formula = trim((string)($_POST['formula'] ?? ''));
        if ($type === 'formula') {
            if ($formula === '') $errs[] = 'Formül tipi için formül zorunlu.';
            elseif (($fe = cost_formula_check($formula))) $errs[] = 'Formül hatası: ' . $fe;
        }

        if ($errs) {
            set_flash('error', implode(' ', $errs));
            header('Location: maliyet_alanlar.php' . ($id ? '?edit=' . $id : '')); exit;
        }

        $vals = [
            'code'          => $code,
            'label'         => substr($label, 0, 150),
            'field_type'    => $type,
            'options'       => trim((string)($_POST['options'] ?? '')),
            'default_value' => substr(trim((string)($_POST['default_value'] ?? '')), 0, 255),
            'suffix'        => substr(trim((string)($_POST['suffix'] ?? '')), 0, 24),
            'formula'       => substr($formula, 0, 500),
            'decimals'      => max(0, min(6, (int)($_POST['decimals'] ?? 2))),
            'hint'          => substr(trim((string)($_POST['hint'] ?? '')), 0, 255),
            'sort_order'    => (int)($_POST['sort_order'] ?? 0),
            'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
            'show_in_list'  => !empty($_POST['show_in_list']) ? 1 : 0,
        ];

        try {
            if ($id > 0) {
                $set = [];
                foreach (array_keys($vals) as $k) $set[] = "`$k`=:$k";
                db()->prepare("UPDATE cost_field_defs SET " . implode(',', $set) . " WHERE id=:id")
                    ->execute($vals + ['id' => $id]);
                audit_log_event('update', 'maliyet_alan', $id, null, $vals);
                set_flash('success', 'Alan güncellendi.');
            } else {
                $cols = array_keys($vals);
                db()->prepare("INSERT INTO cost_field_defs (`" . implode('`,`', $cols) . "`)
                               VALUES (:" . implode(',:', $cols) . ")")->execute($vals);
                $new_id = (int)db()->lastInsertId();
                audit_log_event('create', 'maliyet_alan', $new_id, null, $vals);
                set_flash('success', '“' . $label . '” alanı eklendi. Tüm maliyet hesaplarında görünecek.');
            }
        } catch (PDOException $e) {
            error_log('[maliyet_alanlar] ' . $e->getMessage());
            set_flash('error', 'Kaydedilemedi.');
        }
        header('Location: maliyet_alanlar.php'); exit;
    }
}

$fields = cost_fields(false);
$edit   = null;
if ($edit_id > 0) {
    foreach ($fields as $f) if ((int)$f['id'] === $edit_id) $edit = $f;
}
$types = cost_field_types();

render_header('Maliyet — Alan & Formül Tanımları');
render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<div class="page-head">
    <div>
        <h1>➕ Alan &amp; Formül Tanımları</h1>
        <p class="muted">Maliyet sayfasına yeni alan ve hesaplama ekleyin</p>
    </div>
    <div class="page-head-actions">
        <a href="maliyet.php" class="btn">← Maliyet</a>
    </div>
</div>

<div class="mly-help">
    <h3>Nasıl çalışır?</h3>
    <ul>
        <li><strong>Sayı</strong> tipi bir alan eklerseniz — ör. <code>Sigorta Bedeli</code> — her maliyet sayfasının
            “Genel Bilgiler” bölümünde bir kutu olarak çıkar ve formüllerde <code>[sigorta_bedeli]</code> ile kullanılır.</li>
        <li><strong>Formül</strong> tipi alan girdi almaz, hesaplar. Örn. <code>[genel_toplam]/[net_kg]*1,18</code>.
            Sonucu hem formda hem yazdırma çıktısında görünür.</li>
        <li><strong>Liste kolonu</strong> işaretlenirse alan maliyet listesinde ayrı bir sütun olur.</li>
        <li>Kalem satırlarındaki her kalemin kendi kodu vardır; formüllerde <code>[kod]</code> tutarı,
            <code>[kod.miktar]</code> miktarı, <code>[kod.fiyat]</code> birim fiyatı verir.</li>
    </ul>
    <h3 style="margin-top:12px">Kullanılabilir sistem değişkenleri</h3>
    <div class="mly-var-chips">
        <?php foreach (cost_system_vars() as $code => $label): ?>
        <span class="mly-var-chip" title="<?= h($label) ?>">[<?= h($code) ?>]</span>
        <?php endforeach; ?>
    </div>
    <h3 style="margin-top:12px">Fonksiyonlar</h3>
    <div class="mly-var-chips">
        <?php foreach (['yuvarla(x; n)', 'min(a; b)', 'maks(a; b)', 'mutlak(x)', 'tavan(x)', 'taban(x)',
                        'topla(a; b; …)', 'ort(a; b)', 'eger(kosul; a; b)'] as $fn): ?>
        <span class="mly-var-chip"><?= h($fn) ?></span>
        <?php endforeach; ?>
    </div>
</div>

<div class="mly-def-layout">
    <div class="mly-def-panel">
        <h2><?= $edit ? '✏️ Alanı Düzenle' : '➕ Yeni Alan' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

            <label>Alan Adı *
                <input type="text" name="label" required value="<?= h($edit['label'] ?? '') ?>"
                       placeholder="Örn: Sigorta Bedeli">
            </label>
            <label>Kod <small>(boş bırakılırsa addan üretilir)</small>
                <input type="text" name="code" value="<?= h($edit['code'] ?? '') ?>" placeholder="sigorta_bedeli">
            </label>
            <label>Tip
                <select name="field_type" id="fldType">
                    <?php foreach ($types as $k => $lbl): ?>
                    <option value="<?= h($k) ?>" <?= ($edit['field_type'] ?? 'text') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label id="fldFormulaWrap">Formül <small>(yalnız “Formül” tipi için)</small>
                <input type="text" name="formula" value="<?= h($edit['formula'] ?? '') ?>"
                       placeholder="[genel_toplam]/[net_kg]">
            </label>
            <label id="fldOptionsWrap">Seçenekler <small>(her satıra bir seçenek)</small>
                <textarea name="options" rows="3"><?= h($edit['options'] ?? '') ?></textarea>
            </label>
            <label>Varsayılan Değer
                <input type="text" name="default_value" value="<?= h($edit['default_value'] ?? '') ?>">
            </label>
            <label>Birim / Son Ek
                <input type="text" name="suffix" value="<?= h($edit['suffix'] ?? '') ?>" placeholder="TL, %, kg">
            </label>
            <label>Ondalık Basamak
                <input type="number" name="decimals" min="0" max="6" value="<?= (int)($edit['decimals'] ?? 2) ?>">
            </label>
            <label>Açıklama / İpucu
                <input type="text" name="hint" value="<?= h($edit['hint'] ?? '') ?>">
            </label>
            <label>Sıra
                <input type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>">
            </label>
            <label class="mly-check" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" value="1" <?= !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                Aktif
            </label>
            <label class="mly-check" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="show_in_list" value="1" <?= (int)($edit['show_in_list'] ?? 0) === 1 ? 'checked' : '' ?>>
                Maliyet listesinde sütun olarak göster
            </label>

            <div class="form-foot inline">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Güncelle' : 'Ekle' ?></button>
                <?php if ($edit): ?><a href="maliyet_alanlar.php" class="btn">Vazgeç</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="mly-def-panel">
        <h2>Tanımlı Alanlar (<?= count($fields) ?>)</h2>
        <?php if (!$fields): ?>
        <p class="muted">Henüz alan tanımlanmamış. Soldaki formdan ilk alanınızı ekleyin.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="mly-view-table">
                <thead>
                    <tr><th>Alan</th><th>Kod</th><th>Tip</th><th>Formül / Varsayılan</th><th>Durum</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($fields as $f): ?>
                    <tr>
                        <td data-label="Alan"><strong><?= h($f['label']) ?></strong>
                            <?php if ($f['hint']): ?><br><small class="muted"><?= h($f['hint']) ?></small><?php endif; ?></td>
                        <td data-label="Kod"><code>[<?= h($f['code']) ?>]</code></td>
                        <td data-label="Tip"><?= h($types[$f['field_type']] ?? $f['field_type']) ?></td>
                        <td data-label="Formül"><small><?= h($f['formula'] ?: $f['default_value'] ?: '—') ?></small></td>
                        <td data-label="Durum">
                            <?= (int)$f['is_active'] === 1 ? '<span class="mly-badge mly-badge-kesin">Aktif</span>'
                                                          : '<span class="mly-badge mly-badge-taslak">Pasif</span>' ?>
                            <?= (int)$f['show_in_list'] === 1 ? ' <span class="muted">· listede</span>' : '' ?>
                        </td>
                        <td class="right">
                            <a href="maliyet_alanlar.php?edit=<?= (int)$f['id'] ?>" class="btn btn-sm">Düzenle</a>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('“<?= h($f['label']) ?>” alanı silinsin mi?')">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var sel = document.getElementById('fldType');
    if (!sel) return;
    function sync() {
        document.getElementById('fldFormulaWrap').style.display = sel.value === 'formula' ? '' : 'none';
        document.getElementById('fldOptionsWrap').style.display = sel.value === 'select'  ? '' : 'none';
    }
    sel.addEventListener('change', sync);
    sync();
})();
</script>
<?php render_footer(); ?>
