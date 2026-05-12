<?php
// =========================================================
// kantar_create.php - Yeni kantar fişi oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$kasa_list  = get_definitions_by_type('kasa_cinsi');
$palet_list = get_definitions_by_type('palet_tipi');

$fis = [
    'fis_no' => '', 'plaka' => '', 'firma_adi' => '',
    'giris_tarih' => '', 'cikis_tarih' => '', 'operator_adi' => '',
    'malin_cinsi' => '', 'geldigi_yer' => '', 'gittigi_yer' => '',
    'palet_sayisi' => '', 'palet_cinsi' => '', 'kasa_cinsi' => '', 'kasa_sayisi' => '',
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

        $kasa_dara_val  = 0.0;
        $palet_dara_val = 0.0;
        foreach ($kasa_list  as $kd) { if ($kd['name'] === $fis['kasa_cinsi'])  { $kasa_dara_val  = (float)$kd['unit_dara_kg']; break; } }
        foreach ($palet_list as $kd) { if ($kd['name'] === $fis['palet_cinsi']) { $palet_dara_val = (float)$kd['unit_dara_kg']; break; } }

        db()->prepare(
            "INSERT INTO kantar_fisleri
             (fis_no, plaka, firma_adi, giris_tarih, cikis_tarih, operator_adi,
              malin_cinsi, geldigi_yer, gittigi_yer,
              palet_sayisi, palet_cinsi, kasa_cinsi, kasa_sayisi,
              aciklama,
              tartim1, alibi1, tartim2, alibi2, net_kg,
              kasa_dara, palet_dara)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            (int)$fis['palet_sayisi'],
            $fis['palet_cinsi'],
            $fis['kasa_cinsi'],
            (int)$fis['kasa_sayisi'],
            $fis['aciklama'],
            $t1,
            $fis['alibi1'],
            $t2,
            $fis['alibi2'],
            $net,
            $kasa_dara_val,
            $palet_dara_val,
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
