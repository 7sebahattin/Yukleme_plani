<?php
// =========================================================
// cikma_create.php - Yeni çıkma kaydı oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';

$record = [
    'firma' => '', 'bolge' => '', 'urun' => '',
    'tarih' => date('Y-m-d'),
];
$pallets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $errors = [];

    foreach (array_keys($record) as $k) {
        $record[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($record['firma'] === '' && $record['tarih'] === '') {
        $errors[] = 'Firma veya tarih alanlarından en az biri doldurulmalı.';
    }

    $raw_pallets = $_POST['pallets'] ?? [];
    if (!is_array($raw_pallets)) $raw_pallets = [];
    $computed = [];
    foreach ($raw_pallets as $rp) {
        if (!is_array($rp)) continue;
        $is_empty = (
            trim((string)($rp['palet_no'] ?? '')) === ''
            && intval_safe($rp['kasa_adeti'] ?? 0) === 0
            && num($rp['brut_kg'] ?? 0) == 0
            && empty($rp['kasa_cinsi_id'])
            && empty($rp['palet_tipi_id'])
            && trim((string)($rp['urun_cinsi'] ?? '')) === ''
        );
        if ($is_empty) continue;
        $computed[] = compute_pallet_row($rp);
    }

    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO loading_records (type, firma, bolge, urun, tarih)
                 VALUES ('cikma', :firma, :bolge, :urun, :tarih)"
            );
            $st->execute([
                ':firma' => $record['firma'],
                ':bolge' => $record['bolge'],
                ':urun'  => $record['urun'],
                ':tarih' => $record['tarih'] ?: null,
            ]);
            $rec_id = (int)$pdo->lastInsertId();

            $st_p = $pdo->prepare(
                "INSERT INTO loading_pallets
                 (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg,
                  kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no)
                 VALUES
                 (:r, :pno, :ka, :sz, :br, :da, :nt, :kc, :pt, :uc, :dp, :sn)"
            );
            $st_m = $pdo->prepare(
                "INSERT INTO pallet_materials
                 (loading_pallet_id, material_id, quantity, total_dara_kg)
                 VALUES (:p, :m, :q, :t)"
            );

            foreach ($computed as $i => $p) {
                $st_p->execute([
                    ':r'  => $rec_id,
                    ':pno'=> $p['palet_no'],
                    ':ka' => $p['kasa_adeti'],
                    ':sz' => $p['size'],
                    ':br' => $p['brut_kg'],
                    ':da' => $p['dara_kg'],
                    ':nt' => $p['net_kg'],
                    ':kc' => $p['kasa_cinsi_id'],
                    ':pt' => $p['palet_tipi_id'],
                    ':uc' => $p['urun_cinsi'],
                    ':dp' => $p['depo'],
                    ':sn' => $i,
                ]);
                $pid = (int)$pdo->lastInsertId();
                foreach ($p['materials'] as $m) {
                    $st_m->execute([
                        ':p' => $pid,
                        ':m' => $m['material_id'],
                        ':q' => $m['quantity'],
                        ':t' => $m['total_dara_kg'],
                    ]);
                }
            }

            $pdo->commit();
            set_flash('success', 'Çıkma kaydı oluşturuldu (#' . $rec_id . ').');
            header('Location: record_view.php?id=' . $rec_id);
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
    }

    $pallets = $computed;
}

$form_action   = 'cikma_create.php';
$title         = 'Yeni Çıkma Kaydı';
$submit_label  = 'Kaydet';
$cancel_url    = 'cikmalar.php';
$form_is_cikma = true;

render_header($title);

if (!empty($errors)) {
    foreach ($errors as $e) echo '<div class="flash flash-error">' . h($e) . '</div>';
}

include __DIR__ . '/_form.php';
render_footer();
