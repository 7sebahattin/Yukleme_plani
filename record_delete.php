<?php
// =========================================================
// record_delete.php - Kayıt sil (FK CASCADE ile palet/materyaller de silinir)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.delete');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz kayıt.');
    header('Location: records.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    // Type'ı silmeden önce al (redirect için)
    $st_t = db()->prepare("SELECT type FROM loading_records WHERE id=:id");
    $st_t->execute([':id' => $id]);
    $rec_type = $st_t->fetchColumn() ?: 'yukleme';
    $list_url = $rec_type === 'cikma' ? 'cikmalar.php' : 'records.php';

    try {
        // Malzeme stok hareketlerini önce temizle (FK CASCADE kapsamı dışında)
        db()->prepare(
            "DELETE FROM material_stock_movements WHERE source_type='loading' AND source_id=?"
        )->execute([$id]);
        db()->prepare("DELETE FROM loading_records WHERE id=:id")->execute([':id' => $id]);
        set_flash('success', 'Kayıt silindi (#' . $id . ').');
    } catch (Throwable $e) {
        set_flash('error', 'Silme hatası: ' . $e->getMessage());
    }
    header('Location: ' . $list_url); exit;
}

// GET: onay ekranı (JS confirm zaten çıkar; bu sayfa fallback olarak da çalışır)
$st = db()->prepare("SELECT id, type, firma, alici, parti_no, created_at FROM loading_records WHERE id=:id");
$st->execute([':id' => $id]);
$rec = $st->fetch();
if (!$rec) { set_flash('error', 'Kayıt bulunamadı.'); header('Location: records.php'); exit; }

$list_url = ($rec['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';
$tot = record_totals($id);

render_header('Kaydı Sil');
?>
<div class="page-head"><h1>Kaydı Sil</h1></div>
<div class="card">
    <p class="warn">Aşağıdaki kayıt ve <strong><?= (int)$tot['palet_count'] ?></strong> palet satırı kalıcı olarak silinecek. Bu işlem geri alınamaz.</p>
    <ul class="info-list">
        <li><strong>ID:</strong> #<?= (int)$rec['id'] ?></li>
        <li><strong>Firma:</strong> <?= h($rec['firma']) ?></li>
        <li><strong>Alıcı:</strong> <?= h($rec['alici']) ?></li>
        <li><strong>Parti No:</strong> <?= h($rec['parti_no']) ?></li>
        <li><strong>Oluşturulma:</strong> <?= h(fmt_datetime($rec['created_at'])) ?></li>
    </ul>
    <form method="post" class="form-foot">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <a class="btn btn-ghost" href="<?= h($list_url) ?>">Vazgeç</a>
        <button class="btn btn-danger btn-lg" type="submit">Evet, Sil</button>
    </form>
</div>
<?php render_footer(); ?>
