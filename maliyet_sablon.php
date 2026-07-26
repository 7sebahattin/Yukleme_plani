<?php
// =========================================================
// maliyet_sablon.php — Maliyet şablonları
//   Şablon = hazır bölüm + kalem seti. Yeni hesap açılırken yüklenir.
//   Şablonlar mevcut bir maliyet hesabından üretilir (döngüyü kısa tutar).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    // ── Mevcut hesaptan şablon üret ──
    if ($action === 'from_sheet') {
        $sid  = (int)($_POST['sheet_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));

        $st = db()->prepare("SELECT * FROM cost_sheets WHERE id=? AND deleted_at IS NULL");
        $st->execute([$sid]);
        $sheet = $st->fetch();

        if (!$sheet) {
            set_flash('error', 'Kaynak maliyet hesabı bulunamadı.');
        } elseif (!depot_visible_to_user((string)$sheet['depo'])) {
            set_flash('error', 'Bu hesap aktif deponuzda görünmüyor.');
        } else {
            if ($name === '') $name = ($sheet['product'] ?: 'Maliyet') . ' Şablonu';
            try {
                db()->beginTransaction();
                db()->prepare("INSERT INTO cost_templates (name, product, description, is_active, is_default, created_by)
                               VALUES (?,?,?,1,0,?)")
                    ->execute([substr($name, 0, 150), (string)$sheet['product'],
                               'Hesap #' . $sid . ' üzerinden oluşturuldu', (int)$auth_user['id']]);
                $tid = (int)db()->lastInsertId();

                $st = db()->prepare("SELECT * FROM cost_sheet_sections WHERE sheet_id=? ORDER BY sort_order, id");
                $st->execute([$sid]);
                $secs = $st->fetchAll();

                $ins_s = db()->prepare("INSERT INTO cost_template_sections
                    (template_id, code, title, basis_type, basis_value, basis_formula, basis_label, include_in_total, sort_order)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $sec_code = [];
                foreach ($secs as $i => $s) {
                    $code = $s['code'] ?: ('bolum' . ($i + 1));
                    $sec_code[(int)$s['id']] = $code;
                    $ins_s->execute([$tid, $code, $s['title'], $s['basis_type'], $s['basis_value'],
                                     $s['basis_formula'], $s['basis_label'], $s['include_in_total'], $s['sort_order']]);
                }

                $st = db()->prepare("SELECT * FROM cost_sheet_items WHERE sheet_id=? ORDER BY sort_order, id");
                $st->execute([$sid]);
                $its = $st->fetchAll();

                $ins_i = db()->prepare("INSERT INTO cost_template_items
                    (template_id, section_code, sort_order, code, label, calc_type, qty, qty_formula, unit,
                     unit_price, percent, percent_base, formula, is_income, note)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                foreach ($its as $it) {
                    $ins_i->execute([$tid, $sec_code[(int)$it['section_id']] ?? '', $it['sort_order'],
                                     $it['code'], $it['label'], $it['calc_type'], $it['qty'], $it['qty_formula'],
                                     $it['unit'], $it['unit_price'], $it['percent'], $it['percent_base'],
                                     $it['formula'], $it['is_income'], $it['note']]);
                }
                db()->commit();

                audit_log_event('create', 'maliyet_sablon', $tid, null,
                    ['name' => $name, 'kaynak_hesap' => $sid, 'kalem' => count($its)]);
                set_flash('success', '“' . $name . '” şablonu oluşturuldu (' . count($its) . ' kalem).');
            } catch (PDOException $e) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('[maliyet_sablon from_sheet] ' . $e->getMessage());
                set_flash('error', 'Şablon oluşturulamadı.');
            }
        }
        header('Location: maliyet_sablon.php'); exit;
    }

    // ── Şablon güncelle (ad / ürün / durum / varsayılan) ──
    if ($action === 'update') {
        $tid = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("UPDATE cost_templates SET name=?, product=?, description=?, is_active=?, updated_at=NOW() WHERE id=?")
                ->execute([
                    substr(trim((string)($_POST['name'] ?? '')), 0, 150) ?: 'Şablon',
                    substr(trim((string)($_POST['product'] ?? '')), 0, 150),
                    substr(trim((string)($_POST['description'] ?? '')), 0, 255),
                    !empty($_POST['is_active']) ? 1 : 0,
                    $tid,
                ]);
            if (!empty($_POST['is_default'])) {
                db()->exec("UPDATE cost_templates SET is_default=0");
                db()->prepare("UPDATE cost_templates SET is_default=1 WHERE id=?")->execute([$tid]);
            }
            audit_log_event('update', 'maliyet_sablon', $tid, null, ['name' => $_POST['name'] ?? '']);
            set_flash('success', 'Şablon güncellendi.');
        } catch (PDOException $e) {
            error_log('[maliyet_sablon update] ' . $e->getMessage());
            set_flash('error', 'Güncellenemedi.');
        }
        header('Location: maliyet_sablon.php'); exit;
    }

    // ── Sil ──
    if ($action === 'delete') {
        $tid = (int)($_POST['id'] ?? 0);
        $st  = db()->prepare("SELECT name FROM cost_templates WHERE id=?");
        $st->execute([$tid]);
        $nm = (string)($st->fetchColumn() ?: '');
        try {
            db()->prepare("DELETE FROM cost_template_items    WHERE template_id=?")->execute([$tid]);
            db()->prepare("DELETE FROM cost_template_sections WHERE template_id=?")->execute([$tid]);
            db()->prepare("DELETE FROM cost_templates         WHERE id=?")->execute([$tid]);
            audit_log_event('delete', 'maliyet_sablon', $tid, ['name' => $nm], null);
            set_flash('success', '“' . $nm . '” şablonu silindi.');
        } catch (PDOException $e) {
            error_log('[maliyet_sablon delete] ' . $e->getMessage());
            set_flash('error', 'Silinemedi.');
        }
        header('Location: maliyet_sablon.php'); exit;
    }
}

$templates = db()->query("SELECT t.*,
        (SELECT COUNT(*) FROM cost_template_items ti WHERE ti.template_id=t.id) AS item_count,
        (SELECT COUNT(*) FROM cost_template_sections ts WHERE ts.template_id=t.id) AS sec_count
    FROM cost_templates t ORDER BY t.is_default DESC, t.is_active DESC, t.name")->fetchAll();

// Şablon kaynağı olabilecek hesaplar (aktif depo kapsamında)
[$dsql, $dparams] = depo_sql_column('depo', ':uds');
$st = db()->prepare("SELECT id, sheet_no, product, sheet_date FROM cost_sheets
                     WHERE deleted_at IS NULL $dsql
                     ORDER BY id DESC LIMIT 50");
$st->execute($dparams);
$sheets = $st->fetchAll();

$detail_id = (int)($_GET['detay'] ?? 0);
$detail    = null;
if ($detail_id > 0) {
    $st = db()->prepare("SELECT * FROM cost_template_items WHERE template_id=? ORDER BY sort_order, id");
    $st->execute([$detail_id]);
    $detail = $st->fetchAll();
}

$calc_types = cost_calc_types();

render_header('Maliyet Şablonları');
render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<div class="page-head">
    <div>
        <h1>📑 Maliyet Şablonları</h1>
        <p class="muted">Yeni hesap açılırken yüklenen hazır kalem setleri</p>
    </div>
    <div class="page-head-actions">
        <a href="maliyet.php" class="btn">← Maliyet</a>
    </div>
</div>

<div class="mly-help">
    <h3>Şablon nasıl yapılır?</h3>
    <ul>
        <li>Önce normal bir maliyet hesabı oluşturup kalemlerini istediğiniz gibi düzenleyin.</li>
        <li>Sonra aşağıdan o hesabı seçip “Şablon Yap” deyin — bölümler, kalemler, formüller ve
            birim fiyatlar aynen kopyalanır.</li>
        <li>Şablonu düzenlemek isterseniz: şablondan yeni bir hesap açın, değiştirin, tekrar şablon yapın
            ve eskisini pasife alın.</li>
    </ul>
</div>

<div class="mly-def-panel" style="margin-bottom:16px">
    <h2>➕ Hesaptan Şablon Üret</h2>
    <?php if (!$sheets): ?>
    <p class="muted">Önce en az bir maliyet hesabı oluşturmalısınız.</p>
    <?php else: ?>
    <form method="post" style="display:grid;gap:10px;grid-template-columns:1fr;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="from_sheet">
        <label>Kaynak Hesap
            <select name="sheet_id" required>
                <?php foreach ($sheets as $s): ?>
                <option value="<?= (int)$s['id'] ?>">
                    #<?= (int)$s['id'] ?> · <?= h($s['sheet_no'] ?: 'parti yok') ?> · <?= h($s['product'] ?: '—') ?>
                    <?= $s['sheet_date'] ? ' · ' . h(date('d.m.Y', strtotime((string)$s['sheet_date']))) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Şablon Adı
            <input type="text" name="name" placeholder="Kayısı — İhracat Maliyeti">
        </label>
        <div><button type="submit" class="btn btn-primary">Şablon Yap</button></div>
    </form>
    <?php endif; ?>
</div>

<?php if (!$templates): ?>
<div class="empty"><p>Henüz şablon yok.</p></div>
<?php else: ?>
<?php foreach ($templates as $t): ?>
<div class="mly-def-panel" style="margin-bottom:14px">
    <form method="post" style="display:grid;gap:10px;grid-template-columns:1fr">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
            <h2 style="margin:0">
                <?= h($t['name']) ?>
                <?php if ((int)$t['is_default'] === 1): ?><span class="mly-badge mly-badge-kesin">Varsayılan</span><?php endif; ?>
                <?php if ((int)$t['is_active'] === 0): ?><span class="mly-badge mly-badge-taslak">Pasif</span><?php endif; ?>
            </h2>
            <span class="muted"><?= (int)$t['sec_count'] ?> bölüm · <?= (int)$t['item_count'] ?> kalem</span>
        </div>
        <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
            <label>Ad<input type="text" name="name" value="<?= h($t['name']) ?>"></label>
            <label>Ürün<input type="text" name="product" value="<?= h($t['product']) ?>"></label>
            <label>Açıklama<input type="text" name="description" value="<?= h($t['description']) ?>"></label>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center">
            <label class="mly-check" style="display:flex;align-items:center;gap:6px;margin:0">
                <input type="checkbox" name="is_active" value="1" <?= (int)$t['is_active'] === 1 ? 'checked' : '' ?>> Aktif
            </label>
            <label class="mly-check" style="display:flex;align-items:center;gap:6px;margin:0">
                <input type="checkbox" name="is_default" value="1" <?= (int)$t['is_default'] === 1 ? 'checked' : '' ?>> Varsayılan
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Kaydet</button>
            <a href="maliyet_form.php?tpl=<?= (int)$t['id'] ?>" class="btn btn-sm">Bu şablonla hesap aç</a>
            <a href="maliyet_sablon.php?detay=<?= $detail_id === (int)$t['id'] ? 0 : (int)$t['id'] ?>" class="btn btn-sm btn-ghost">
                <?= $detail_id === (int)$t['id'] ? 'Kalemleri gizle' : 'Kalemleri gör' ?>
            </a>
        </div>
    </form>

    <?php if ($detail_id === (int)$t['id'] && $detail !== null): ?>
    <div class="table-wrap" style="margin-top:12px">
        <table class="mly-view-table">
            <thead><tr><th>Bölüm</th><th>Kalem</th><th>Kod</th><th>Tip</th><th class="num">Miktar</th><th class="num">Fiyat</th></tr></thead>
            <tbody>
            <?php foreach ($detail as $d): ?>
                <tr>
                    <td data-label="Bölüm"><small class="muted"><?= h($d['section_code']) ?></small></td>
                    <td data-label="Kalem"><?= h($d['label']) ?></td>
                    <td data-label="Kod"><code>[<?= h($d['code']) ?>]</code></td>
                    <td data-label="Tip"><?= h($calc_types[$d['calc_type']]['label'] ?? $d['calc_type']) ?></td>
                    <td class="num" data-label="Miktar"><?= $d['qty_formula'] ? h($d['qty_formula']) : cost_fmt_qty($d['qty']) ?></td>
                    <td class="num" data-label="Fiyat"><?= $d['calc_type'] === 'percent' ? '%' . cost_fmt_money($d['percent'], 2) : cost_fmt_unit($d['unit_price'], 4) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <form method="post" style="margin-top:10px" onsubmit="return confirm('“<?= h($t['name']) ?>” şablonu silinsin mi?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button type="submit" class="btn btn-sm btn-danger">Şablonu Sil</button>
    </form>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php render_footer(); ?>
