<?php
// =========================================================
// cikma_create.php - Yeni çıkma kaydı oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

$record = [
    'firma' => '', 'bolge' => '', 'urun' => '',
    'tarih' => date('Y-m-d'), 'cikis_nedeni' => '',
];
$pallets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $errors = [];

    foreach (array_keys($record) as $k) {
        $record[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $record['firma'] = normalize_firma($record['firma']);
    $record['urun']  = normalize_urun($record['urun']);
    $record = clamp_loading_record_fields($record);   // kolon uzunluğu güvencesi
    if ($record['tarih']        === '') $errors[] = 'Tarih zorunludur.';
    if ($record['firma']        === '') $errors[] = 'Firma zorunludur.';
    if ($record['urun']         === '') $errors[] = 'Ürün zorunludur.';
    $_cn_izin = cikis_nedeni_listesi();
    if ($record['cikis_nedeni'] === '' || !in_array($record['cikis_nedeni'], $_cn_izin, true)) {
        $errors[] = 'Çıkış nedeni seçilmelidir.';
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

    // Palet ürün alanı: boşsa kayıt ürününden doldur, doluysa normalize et
    foreach ($computed as &$p) {
        if ($p['urun_cinsi'] === '') {
            $p['urun_cinsi'] = $record['urun'];
        } else {
            $p['urun_cinsi'] = normalize_urun($p['urun_cinsi']);
        }
    }
    unset($p);

    $errors = array_merge($errors, validate_pallet_rows($computed));
    if (empty($errors)) {
        ensure_definition('firma', $record['firma']);
        ensure_definition('urun',  $record['urun']);
        foreach ($computed as $p) {
            if (!empty($p['depo']))       ensure_definition('depo', $p['depo']);
            if (!empty($p['urun_cinsi'])) ensure_definition('urun', $p['urun_cinsi']);
        }
    }

    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO loading_records (type, firma, bolge, urun, tarih, cikis_nedeni)
                 VALUES ('cikma', :firma, :bolge, :urun, :tarih, :cikis_nedeni)"
            );
            $st->execute([
                ':firma'        => $record['firma'],
                ':bolge'        => $record['bolge'],
                ':urun'         => $record['urun'],
                ':tarih'        => $record['tarih'] ?: null,
                ':cikis_nedeni' => $record['cikis_nedeni'],
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
            sync_malzeme_kullanim($rec_id);
            audit_log_event('create', 'cikmalar', $rec_id, null, ['firma' => $record['firma'], 'tarih' => $record['tarih'], 'urun' => $record['urun'], 'cikis_nedeni' => $record['cikis_nedeni'], 'palet_sayisi' => count($computed)]);
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
