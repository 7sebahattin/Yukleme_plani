<?php
// =========================================================
// kantar_create.php - Yeni kantar fişi oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$fis = [
    'fis_no' => '', 'plaka' => '', 'firma_adi' => '',
    'giris_tarih' => '', 'cikis_tarih' => '', 'operator_adi' => '',
    'malin_cinsi' => '', 'geldigi_yer' => '', 'gittigi_yer' => '',
    'aciklama' => '',
    'tartim1' => '', 'alibi1' => '',
    'tartim2' => '', 'alibi2' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    foreach (array_keys($fis) as $k) {
        $fis[$k] = trim((string)($_POST[$k] ?? ''));
    }

    try {
        $t1  = num($fis['tartim1']);
        $t2  = num($fis['tartim2']);
        $net = max(0, $t1 - $t2);

        db()->prepare(
            "INSERT INTO kantar_fisleri
             (fis_no, plaka, firma_adi, giris_tarih, cikis_tarih, operator_adi,
              malin_cinsi, geldigi_yer, gittigi_yer, aciklama,
              tartim1, alibi1, tartim2, alibi2, net_kg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
        ]);
        $fis_id = (int)db()->lastInsertId();
        set_flash('success', 'Kantar fişi oluşturuldu (#' . $fis_id . ').');
        header('Location: kantar_edit.php?id=' . $fis_id);
        exit;

    } catch (Throwable $e) {
        $errors[] = 'Kayıt hatası: ' . $e->getMessage();
    }
}

$form_action  = 'kantar_create.php';
$title        = 'Yeni Kantar Fişi';
$submit_label = 'Kaydet';
$is_edit      = false;

render_header($title);

foreach ($errors as $e) {
    echo '<div class="flash flash-error">' . h($e) . '</div>';
}

include __DIR__ . '/_kantar_form.php';
render_footer();
