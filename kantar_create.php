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
    'toplam_palet' => '', 'kasa_dara' => '', 'palet_dara' => '',
];
$gruplar = [];
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    foreach (array_keys($fis) as $k) {
        $fis[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $raw_gruplar = $_POST['gruplar'] ?? [];
    if (!is_array($raw_gruplar)) $raw_gruplar = [];
    $gruplar = [];
    foreach ($raw_gruplar as $g) {
        if (!is_array($g)) continue;
        $grup_adi     = trim((string)($g['grup_adi'] ?? ''));
        $palet_sayisi = (int)($g['palet_sayisi'] ?? 0);
        $kasa_adedi   = (int)($g['kasa_adedi'] ?? 0);
        if ($palet_sayisi > 0 || $kasa_adedi > 0 || $grup_adi !== '') {
            $gruplar[] = compact('grup_adi', 'palet_sayisi', 'kasa_adedi');
        }
    }

    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $t1  = num($fis['tartim1']);
            $t2  = num($fis['tartim2']);
            $net = max(0, $t1 - $t2);

            $st = $pdo->prepare(
                "INSERT INTO kantar_fisleri
                 (fis_no, plaka, firma_adi, giris_tarih, cikis_tarih, operator_adi,
                  malin_cinsi, geldigi_yer, gittigi_yer, aciklama,
                  tartim1, alibi1, tartim2, alibi2, net_kg,
                  toplam_palet, kasa_dara, palet_dara)
                 VALUES
                 (:fis_no, :plaka, :firma_adi, :giris_tarih, :cikis_tarih, :operator_adi,
                  :malin_cinsi, :geldigi_yer, :gittigi_yer, :aciklama,
                  :tartim1, :alibi1, :tartim2, :alibi2, :net_kg,
                  :toplam_palet, :kasa_dara, :palet_dara)"
            );
            $st->execute([
                ':fis_no'       => $fis['fis_no'],
                ':plaka'        => strtoupper($fis['plaka']),
                ':firma_adi'    => $fis['firma_adi'],
                ':giris_tarih'  => $fis['giris_tarih'],
                ':cikis_tarih'  => $fis['cikis_tarih'],
                ':operator_adi' => $fis['operator_adi'],
                ':malin_cinsi'  => $fis['malin_cinsi'],
                ':geldigi_yer'  => $fis['geldigi_yer'],
                ':gittigi_yer'  => $fis['gittigi_yer'],
                ':aciklama'     => $fis['aciklama'],
                ':tartim1'      => $t1,
                ':alibi1'       => $fis['alibi1'],
                ':tartim2'      => $t2,
                ':alibi2'       => $fis['alibi2'],
                ':net_kg'       => $net,
                ':toplam_palet' => (int)$fis['toplam_palet'],
                ':kasa_dara'    => num($fis['kasa_dara']),
                ':palet_dara'   => num($fis['palet_dara']),
            ]);
            $fis_id = (int)$pdo->lastInsertId();

            if (!empty($gruplar)) {
                $st_g = $pdo->prepare(
                    "INSERT INTO kantar_gruplar (fis_id, sira, grup_adi, palet_sayisi, kasa_adedi)
                     VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($gruplar as $i => $g) {
                    $st_g->execute([$fis_id, $i, $g['grup_adi'], $g['palet_sayisi'], $g['kasa_adedi']]);
                }
            }

            $pdo->commit();
            set_flash('success', 'Kantar fişi oluşturuldu (#' . $fis_id . ').');
            header('Location: kantar_edit.php?id=' . $fis_id);
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
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
