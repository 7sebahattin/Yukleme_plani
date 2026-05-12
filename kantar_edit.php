<?php
// =========================================================
// kantar_edit.php - Kantar fişi düzenle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz fiş.');
    header('Location: kantar.php'); exit;
}

$st = db()->prepare("SELECT * FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $fields = [
        'fis_no', 'plaka', 'firma_adi', 'giris_tarih', 'cikis_tarih', 'operator_adi',
        'malin_cinsi', 'geldigi_yer', 'gittigi_yer', 'aciklama',
        'tartim1', 'alibi1', 'tartim2', 'alibi2',
    ];
    foreach ($fields as $k) {
        $fis[$k] = trim((string)($_POST[$k] ?? ''));
    }

    try {
        $t1  = num($fis['tartim1']);
        $t2  = num($fis['tartim2']);
        $net = max(0, $t1 - $t2);

        db()->prepare(
            "UPDATE kantar_fisleri SET
             fis_no=?, plaka=?, firma_adi=?, giris_tarih=?, cikis_tarih=?, operator_adi=?,
             malin_cinsi=?, geldigi_yer=?, gittigi_yer=?, aciklama=?,
             tartim1=?, alibi1=?, tartim2=?, alibi2=?, net_kg=?
             WHERE id=?"
        )->execute([
            $fis['fis_no'],
            strtoupper($fis['plaka']),
            $fis['firma_adi'],
            $fis['giris_tarih'],
            $fis['cikis_tarih'],
            $fis['operator_adi'],
            $fis['malin_cinsi'],
            $fis['geldigi_yer'],
            $fis['gittigi_yer'],
            $fis['aciklama'],
            $t1,
            $fis['alibi1'],
            $t2,
            $fis['alibi2'],
            $net,
            $id,
        ]);

        set_flash('success', 'Fiş güncellendi.');
        header('Location: kantar_edit.php?id=' . $id);
        exit;

    } catch (Throwable $e) {
        $errors[] = 'Güncelleme hatası: ' . $e->getMessage();
    }
}

$form_action  = 'kantar_edit.php?id=' . $id;
$title        = 'Kantar Fişi Düzenle';
$submit_label = 'Güncelle';
$is_edit      = true;

render_header($title);
render_flash();

foreach ($errors as $e) {
    echo '<div class="flash flash-error">' . h($e) . '</div>';
}

include __DIR__ . '/_kantar_form.php';
render_footer();
