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

        $foto_raw = trim((string)($_POST['foto_data'] ?? ''));
        $foto_val = (str_starts_with($foto_raw, 'data:image/')) ? $foto_raw : null;

        db()->prepare(
            "INSERT INTO kantar_fisleri
             (fis_no, plaka, firma_adi, giris_tarih, cikis_tarih, operator_adi,
              malin_cinsi, geldigi_yer, gittigi_yer,
              palet_sayisi, palet_cinsi, kasa_cinsi, kasa_sayisi,
              aciklama,
              tartim1, alibi1, tartim2, alibi2, net_kg,
              kasa_dara, palet_dara, foto_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            $foto_val,
        ]);
        $fis_id = (int)db()->lastInsertId();

        // Fiş no boşsa ID'yi kullan; çakışma varsa "-2", "-3" şeklinde benzersiz yap
        if ($fis['fis_no'] === '') {
            $base   = (string)$fis_id;
            $try    = $base;
            $suffix = 1;
            $chk    = db()->prepare("SELECT COUNT(*) FROM kantar_fisleri WHERE fis_no = ? AND id != ?");
            do {
                $chk->execute([$try, $fis_id]);
                if ((int)$chk->fetchColumn() === 0) break;
                $try = $base . '-' . (++$suffix);
            } while (true);
            db()->prepare("UPDATE kantar_fisleri SET fis_no = ? WHERE id = ?")->execute([$try, $fis_id]);
        }

        // Grupları kaydet
        if (!empty($_POST['gruplar']) && is_array($_POST['gruplar'])) {
            $gst = db()->prepare("INSERT INTO kantar_gruplar (fis_id, sira, grup_adi, palet_sayisi, kasa_adedi, kasa_dara_kg, palet_dara_kg) VALUES (?,?,?,?,?,?,?)");
            $sira = 0;
            foreach ($_POST['gruplar'] as $g) {
                $ga = trim((string)($g['grup_adi'] ?? ''));
                if ($ga === '') continue;
                $gst->execute([$fis_id, ++$sira, $ga, (int)($g['palet_sayisi'] ?? 0), (int)($g['kasa_adedi'] ?? 0), num($g['kasa_dara_kg'] ?? '0'), num($g['palet_dara_kg'] ?? '0')]);
            }
        }
        set_flash('success', 'Kantar fişi oluşturuldu (#' . $fis_id . ').');
        header('Location: kantar_view.php?id=' . $fis_id);
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
