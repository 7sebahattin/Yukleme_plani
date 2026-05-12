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

/* ── Fişi yükle ── */
$st = db()->prepare("SELECT * FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

/* ── Grupları yükle ── */
$st_g = db()->prepare("SELECT * FROM kantar_gruplar WHERE fis_id = ? ORDER BY sira ASC");
$st_g->execute([$id]);
$gruplar = $st_g->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $fields = [
        'fis_no', 'plaka', 'firma_adi', 'giris_tarih', 'cikis_tarih', 'operator_adi',
        'malin_cinsi', 'geldigi_yer', 'gittigi_yer', 'aciklama',
        'tartim1', 'alibi1', 'tartim2', 'alibi2',
        'toplam_palet', 'kasa_dara', 'palet_dara',
    ];
    foreach ($fields as $k) {
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

            $pdo->prepare(
                "UPDATE kantar_fisleri SET
                 fis_no=?, plaka=?, firma_adi=?, giris_tarih=?, cikis_tarih=?, operator_adi=?,
                 malin_cinsi=?, geldigi_yer=?, gittigi_yer=?, aciklama=?,
                 tartim1=?, alibi1=?, tartim2=?, alibi2=?, net_kg=?,
                 toplam_palet=?, kasa_dara=?, palet_dara=?
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
                (int)$fis['toplam_palet'],
                num($fis['kasa_dara']),
                num($fis['palet_dara']),
                $id,
            ]);

            /* Grupları sil ve yeniden ekle */
            $pdo->prepare("DELETE FROM kantar_gruplar WHERE fis_id = ?")->execute([$id]);
            if (!empty($gruplar)) {
                $st_g = $pdo->prepare(
                    "INSERT INTO kantar_gruplar (fis_id, sira, grup_adi, palet_sayisi, kasa_adedi)
                     VALUES (?, ?, ?, ?, ?)"
                );
                foreach ($gruplar as $i => $g) {
                    $st_g->execute([$id, $i, $g['grup_adi'], $g['palet_sayisi'], $g['kasa_adedi']]);
                }
            }

            $pdo->commit();
            set_flash('success', 'Fiş güncellendi.');
            header('Location: kantar_edit.php?id=' . $id);
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Güncelleme hatası: ' . $e->getMessage();
        }
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
