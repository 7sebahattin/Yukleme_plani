<?php
// =========================================================
// maliyet_ambalaj.php — Ambalaj fiyat listesi
//   Excel'deki "AMBALAJ FİYAT" sayfasının karşılığı.
//   Maliyet formunda kalem adı yazılınca birim fiyat buradan dolar.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_calc.php';

$auth_user = require_login();
cost_migrate();
require_maliyet('read');

$can_write = can_maliyet('write');
$edit_id   = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_maliyet('write');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $del = (int)($_POST['id'] ?? 0);
        $st  = db()->prepare("SELECT * FROM cost_packaging_prices WHERE id=?");
        $st->execute([$del]);
        if ($row = $st->fetch()) {
            db()->prepare("DELETE FROM cost_packaging_prices WHERE id=?")->execute([$del]);
            audit_log_event('delete', 'maliyet_ambalaj', $del,
                ['firma' => $row['firma'], 'urun' => $row['urun'], 'fiyat' => $row['fiyat']], null);
            set_flash('success', 'Fiyat kaydı silindi.');
        }
        header('Location: maliyet_ambalaj.php'); exit;
    }

    if ($action === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $urun = trim((string)($_POST['urun'] ?? ''));
        if ($urun === '') {
            set_flash('error', 'Ürün / malzeme adı zorunlu.');
            header('Location: maliyet_ambalaj.php'); exit;
        }
        $date = trim((string)($_POST['price_date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

        $vals = [
            'firma'      => substr(trim((string)($_POST['firma'] ?? '')), 0, 150),
            'urun'       => substr($urun, 0, 200),
            'fiyat'      => num($_POST['fiyat'] ?? 0),
            'birim'      => substr(trim((string)($_POST['birim'] ?? 'adet')) ?: 'adet', 0, 24),
            'price_date' => $date ?: null,
            'note'       => substr(trim((string)($_POST['note'] ?? '')), 0, 255),
            'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
        ];

        try {
            if ($id > 0) {
                $set = [];
                foreach (array_keys($vals) as $k) $set[] = "`$k`=:$k";
                db()->prepare("UPDATE cost_packaging_prices SET " . implode(',', $set) . ", updated_at=NOW() WHERE id=:id")
                    ->execute($vals + ['id' => $id]);
                audit_log_event('update', 'maliyet_ambalaj', $id, null, $vals);
                set_flash('success', 'Fiyat güncellendi.');
            } else {
                $cols = array_keys($vals);
                db()->prepare("INSERT INTO cost_packaging_prices (`" . implode('`,`', $cols) . "`, created_by)
                               VALUES (:" . implode(',:', $cols) . ", :cb)")
                    ->execute($vals + ['cb' => (int)$auth_user['id']]);
                audit_log_event('create', 'maliyet_ambalaj', (int)db()->lastInsertId(), null, $vals);
                set_flash('success', 'Fiyat eklendi.');
            }
        } catch (PDOException $e) {
            error_log('[maliyet_ambalaj] ' . $e->getMessage());
            set_flash('error', 'Kaydedilemedi.');
        }
        header('Location: maliyet_ambalaj.php'); exit;
    }
}

$q    = trim((string)($_GET['q'] ?? ''));
$sql  = "SELECT * FROM cost_packaging_prices";
$args = [];
if ($q !== '') {
    $sql .= " WHERE (urun LIKE ? OR firma LIKE ? OR note LIKE ?)";
    $args = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
}
$sql .= " ORDER BY is_active DESC, urun ASC, price_date DESC";
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$edit = null;
if ($edit_id > 0) {
    $st = db()->prepare("SELECT * FROM cost_packaging_prices WHERE id=?");
    $st->execute([$edit_id]);
    $edit = $st->fetch() ?: null;
}

render_header('Ambalaj Fiyatları');
render_flash();
?>
<link rel="stylesheet" href="assets/maliyet.css?v=<?= @filemtime(__DIR__ . '/assets/maliyet.css') ?: time() ?>">

<div class="page-head">
    <div>
        <h1>🏷️ Ambalaj Fiyatları</h1>
        <p class="muted">Maliyet kalemlerinin birim fiyat kaynağı · <?= count($rows) ?> kayıt</p>
    </div>
    <div class="page-head-actions">
        <a href="maliyet.php" class="btn">← Maliyet</a>
    </div>
</div>

<div class="mly-def-layout">
    <?php if ($can_write): ?>
    <div class="mly-def-panel">
        <h2><?= $edit ? '✏️ Fiyatı Düzenle' : '➕ Yeni Fiyat' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <label>Ürün / Malzeme *
                <input type="text" name="urun" required value="<?= h($edit['urun'] ?? '') ?>"
                       placeholder="30X40X18 PLASTİK KASA">
            </label>
            <label>Firma
                <input type="text" name="firma" value="<?= h($edit['firma'] ?? '') ?>" list="mlyFirmaList">
                <datalist id="mlyFirmaList">
                    <?php
                    $firms = db()->query("SELECT DISTINCT firma FROM cost_packaging_prices WHERE firma<>'' ORDER BY firma")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($firms as $fm): ?><option value="<?= h($fm) ?>"><?php endforeach; ?>
                </datalist>
            </label>
            <label>Fiyat (TL)
                <input type="text" inputmode="decimal" name="fiyat"
                       value="<?= $edit ? fmt_edit_num($edit['fiyat'], 4) : '' ?>">
            </label>
            <label>Birim
                <input type="text" name="birim" value="<?= h($edit['birim'] ?? 'adet') ?>">
            </label>
            <label>Fiyat Tarihi
                <input type="date" name="price_date" value="<?= h($edit['price_date'] ?? date('Y-m-d')) ?>">
            </label>
            <label>Not
                <input type="text" name="note" value="<?= h($edit['note'] ?? '') ?>">
            </label>
            <label class="mly-check" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" value="1"
                       <?= !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>>
                Aktif (maliyet formunda önerilsin)
            </label>
            <div class="form-foot inline">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Güncelle' : 'Ekle' ?></button>
                <?php if ($edit): ?><a href="maliyet_ambalaj.php" class="btn">Vazgeç</a><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="mly-def-panel">
        <form method="get" style="margin-bottom:12px;display:flex;gap:8px">
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ürün veya firma ara…"
                   style="flex:1;padding:9px 10px;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--card);color:var(--text);font-size:16px">
            <button type="submit" class="btn">Ara</button>
            <?php if ($q !== ''): ?><a href="maliyet_ambalaj.php" class="btn btn-ghost">✕</a><?php endif; ?>
        </form>

        <?php if (!$rows): ?>
        <p class="muted">Kayıt yok.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="mly-view-table">
                <thead>
                    <tr><th>Ürün</th><th>Firma</th><th class="num">Fiyat</th><th>Birim</th><th>Tarih</th><?php if ($can_write): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr<?= (int)$r['is_active'] === 0 ? ' class="mly-vr-info"' : '' ?>>
                        <td data-label="Ürün"><strong><?= h($r['urun']) ?></strong>
                            <?php if ($r['note']): ?><br><small class="muted"><?= h($r['note']) ?></small><?php endif; ?></td>
                        <td data-label="Firma"><?= h($r['firma'] ?: '—') ?></td>
                        <td class="num" data-label="Fiyat"><?= cost_fmt_money($r['fiyat'], 4) ?> ₺</td>
                        <td data-label="Birim"><?= h($r['birim']) ?></td>
                        <td data-label="Tarih"><?= $r['price_date'] ? h(date('d.m.Y', strtotime((string)$r['price_date']))) : '—' ?></td>
                        <?php if ($can_write): ?>
                        <td class="right">
                            <a href="maliyet_ambalaj.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm">Düzenle</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Silinsin mi?')">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php render_footer(); ?>
