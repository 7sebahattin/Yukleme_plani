<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/hesap_config.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_hesap('delete');
hesap_migrate();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: hesap_liste.php'); exit; }

$st = db()->prepare("SELECT * FROM account_transactions WHERE id=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    set_flash('error', 'Kayıt bulunamadı.');
    header('Location: hesap_liste.php'); exit;
}
if (!hesap_row_visible($row)) {
    forbidden('Bu kayıt size görünür değil.');
}
// Y4: onaylı / ödeme bekleyen / ödenmiş kayıt yönetici dışında silinemez
if (hesap_icerik_kilitli($row)) {
    set_flash('error', hesap_kilit_mesaji());
    header('Location: hesap_liste.php'); exit;
}
// Yönetici bakiyeye girmiş bir kaydı siliyorsa gerekçe zorunlu (audit'e yazılır)
$gerekce_gerekli = in_array((string)($row['status'] ?? ''), hesap_balance_statuses(), true);
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $gerekce = trim((string)($_POST['gerekce'] ?? ''));
    if ($gerekce_gerekli && $gerekce === '') {
        $hata = 'Onaylanmış kaydı silmek için gerekçe yazın.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hata === '') {

    // Dosyaları diskten sil
    $files = hesap_get_files($id);
    foreach ($files as $f) {
        $path = HESAP_UPLOAD_DIR . $f['file_name'];
        if (file_exists($path)) @unlink($path);
    }

    // DB kaydını sil (account_files cascade ile silinir)
    db()->prepare("DELETE FROM account_transactions WHERE id=?")->execute([$id]);

    // Audit — mali kayıt silme (silinen kayıt özeti)
    audit_log_event('delete', 'hesap', $id, [
        'transaction_date' => $row['transaction_date'],
        'type'             => $row['type'],
        'category'         => $row['category'],
        'amount'           => (float)$row['amount'],
        'currency'         => $row['currency'],
        'person_company'   => $row['person_company'],
        'status'           => $row['status'] ?? null,
        'sahip'            => $row['user_id'] ?? null,
    ] + ($gerekce_gerekli ? ['gerekce' => $gerekce] : []));

    set_flash('success', 'Kayıt silindi.');
    header('Location: hesap_liste.php'); exit;
}

render_header('Kayıt Sil');
hesap_assets();
render_flash();
?>
<div class="hs">
<div class="page-head">
    <h1>Kaydı Sil</h1>
    <a href="hesap_liste.php" class="btn btn-ghost">İptal</a>
</div>

<?php if ($hata !== ''): ?><div class="flash flash-error"><?= h($hata) ?></div><?php endif; ?>

<div class="hs-alert" role="alert">
    <strong>Bu kayıt kalıcı olarak silinecek</strong>
    Fiş fotoğrafları da birlikte silinir. Bu işlem geri alınamaz.
</div>

<section class="hs-step">
    <p class="hs-step-label">Silinecek kayıt</p>
    <dl class="hs-kv">
        <dt>Tarih</dt>    <dd><?= h(date('d.m.Y', strtotime($row['transaction_date']))) ?></dd>
        <dt>Tür</dt>      <dd><?= h(hesap_type_label($row['type'])) ?></dd>
        <dt>Kategori</dt> <dd><?= h($row['category'] ?: '—') ?></dd>
        <?php if ($row['person_company']): ?>
        <dt>Kişi / Firma</dt><dd><?= h($row['person_company']) ?></dd>
        <?php endif; ?>
        <dt>Durum</dt>    <dd><?= hesap_status_badge($row['status'] ?? null, true) ?></dd>
        <dt>Tutar</dt>
        <dd class="<?= $row['type'] === 'gelir' ? 'pos' : 'neg' ?>"><?= fmt_para((float)$row['amount'], $row['currency']) ?></dd>
    </dl>

    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <?php if ($gerekce_gerekli): ?>
        <label class="hs-field" style="margin-bottom:12px">
            <span>Silme gerekçesi <span class="muted" style="font-weight:400">(zorunlu — kayıt onaylanmış, bakiyeyi değiştirir)</span></span>
            <textarea name="gerekce" rows="2" required></textarea>
        </label>
        <?php endif; ?>
        <div class="hs-actions">
            <a href="hesap_liste.php" class="btn">İptal</a>
            <button type="submit" class="btn btn-danger">Evet, Sil</button>
        </div>
    </form>
</section>
</div><!-- /.hs -->
<?php render_footer(); ?>
