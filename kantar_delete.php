<?php
// =========================================================
// kantar_delete.php - Kantar fişi sil
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.delete');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz fiş.');
    header('Location: kantar.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    try {
        $_del_st = db()->prepare("SELECT firma_adi, giris_tarih FROM kantar_fisleri WHERE id = ?");
        $_del_st->execute([$id]);
        $_audit_del = $_del_st->fetch() ?: null;

        db()->prepare("DELETE FROM kantar_fisleri WHERE id = ?")->execute([$id]);
        audit_log_event('delete', 'kantar', $id, $_audit_del ?: null);
        set_flash('success', 'Fiş silindi (#' . $id . ').');
    } catch (Throwable $e) {
        set_flash('error', 'Silme hatası: ' . $e->getMessage());
    }
    header('Location: kantar.php'); exit;
}

/* GET: onay ekranı */
$st = db()->prepare("SELECT id, fis_no, plaka, firma_adi, giris_tarih, created_at FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

render_header('Fişi Sil');
?>
<div class="page-head"><h1>Fişi Sil</h1></div>
<div class="card">
    <p class="warn">Aşağıdaki kantar fişi ve tüm grup kayıtları kalıcı olarak silinecek. Bu işlem geri alınamaz.</p>
    <ul class="info-list">
        <li><strong>ID:</strong> #<?= (int)$fis['id'] ?></li>
        <li><strong>Fiş No:</strong> <?= h($fis['fis_no'] ?: '—') ?></li>
        <li><strong>Plaka:</strong> <?= h($fis['plaka'] ?: '—') ?></li>
        <li><strong>Firma:</strong> <?= h($fis['firma_adi'] ?: '—') ?></li>
        <li><strong>Giriş Tarihi:</strong> <?= h($fis['giris_tarih'] ?: fmt_datetime($fis['created_at'])) ?></li>
    </ul>
    <form method="post" class="form-foot">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <a class="btn btn-ghost" href="kantar_edit.php?id=<?= (int)$id ?>">Vazgeç</a>
        <button class="btn btn-danger btn-lg" type="submit">Evet, Sil</button>
    </form>
</div>
<?php render_footer(); ?>
