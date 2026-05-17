<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: hesap_liste.php'); exit; }

$st = db()->prepare("SELECT * FROM account_transactions WHERE id=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    set_flash('error', 'Kayıt bulunamadı.');
    header('Location: hesap_liste.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // Dosyaları diskten sil
    $files = hesap_get_files($id);
    foreach ($files as $f) {
        $path = HESAP_UPLOAD_DIR . $f['file_name'];
        if (file_exists($path)) @unlink($path);
    }

    // DB kaydını sil (account_files cascade ile silinir)
    db()->prepare("DELETE FROM account_transactions WHERE id=?")->execute([$id]);
    set_flash('success', 'Kayıt silindi.');
    header('Location: hesap_liste.php'); exit;
}

render_header('Kayıt Sil');
render_flash();
?>
<div class="page-head">
    <h1>Kaydı Sil</h1>
    <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
</div>

<div class="card">
    <div class="card-body">
        <p>Bu kaydı kalıcı olarak silmek istediğinizden emin misiniz?</p>
        <div class="hesap-stat-grid" style="grid-template-columns:repeat(2,1fr);margin:12px 0">
            <div class="hesap-stat"><span>Tarih</span><strong><?= h(date('d.m.Y', strtotime($row['transaction_date']))) ?></strong></div>
            <div class="hesap-stat"><span>Tür</span><strong><?= h(hesap_type_label($row['type'])) ?></strong></div>
            <div class="hesap-stat"><span>Kategori</span><strong><?= h($row['category']) ?></strong></div>
            <div class="hesap-stat <?= in_array($row['type'],['gelir']) ? 'green' : 'red' ?>">
                <span>Tutar</span><strong><?= fmt_para((float)$row['amount'], $row['currency']) ?></strong>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div style="display:flex;gap:12px;margin-top:8px">
                <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
                <button type="submit" class="btn btn-danger">Evet, Sil</button>
            </div>
        </form>
    </div>
</div>
<?php render_footer(); ?>
