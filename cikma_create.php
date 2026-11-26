<?php
// =========================================================
// cikma_create.php - Yeni çıkma kaydı oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.pallet.create');

$record = [
    'firma' => '', 'bolge' => '', 'urun' => '', 'urun_cesidi' => '',
    'tarih' => date('Y-m-d'), 'cikis_nedeni' => '',
];
$pallets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $errors = [];

    foreach (array_keys($record) as $k) {
        $record[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $record['firma']       = normalize_firma($record['firma']);
    $record['urun']        = normalize_urun($record['urun']);
    $record['urun_cesidi'] = tr_upper($record['urun_cesidi']);
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
            && trim((string)($rp['urun_cesidi'] ?? '')) === ''
        );
        if ($is_empty) continue;
        $computed[] = compute_pallet_row($rp);
    }

    // Palet ürün ve cinsi alanları: boşsa kayıt değerinden doldur, doluysa normalize et
    foreach ($computed as &$p) {
        if ($p['urun_cinsi'] === '') {
            $p['urun_cinsi'] = $record['urun'];
        } else {
            $p['urun_cinsi'] = normalize_urun($p['urun_cinsi']);
        }
        if ($p['urun_cesidi'] === '' && $record['urun_cesidi'] !== '') {
            $p['urun_cesidi'] = $record['urun_cesidi'];
        } elseif ($p['urun_cesidi'] !== '') {
            $p['urun_cesidi'] = mb_substr(tr_upper($p['urun_cesidi']), 0, 150, 'UTF-8');
        }
    }
    unset($p);

    $errors = array_merge($errors, validate_pallet_rows($computed));
    role_require_pallet_depots($computed);
    if (empty($errors)) {
        ensure_definition('firma', $record['firma']);
        ensure_definition('urun',  $record['urun']);
        if ($record['urun_cesidi'] !== '') ensure_definition('urun_cesidi', $record['urun_cesidi']);
        foreach ($computed as $p) {
            if (!empty($p['depo']))        ensure_definition('depo', $p['depo']);
            if (!empty($p['urun_cinsi']))  ensure_definition('urun', $p['urun_cinsi']);
            if (!empty($p['urun_cesidi'])) ensure_definition('urun_cesidi', $p['urun_cesidi']);
        }
    }

    if (empty($errors)) {
        try {
            $pdo = db();
            $has_rec_cesit = ensure_column('loading_records', 'urun_cesidi',
                "ALTER TABLE `loading_records` ADD COLUMN `urun_cesidi` VARCHAR(150) NOT NULL DEFAULT ''");
            $has_pal_cesit = ensure_column('loading_pallets', 'urun_cesidi',
                "ALTER TABLE `loading_pallets` ADD COLUMN `urun_cesidi` VARCHAR(150) NOT NULL DEFAULT ''");

            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO loading_records (type, firma, bolge, urun"
                . ($has_rec_cesit ? ", urun_cesidi" : "")
                . ", tarih, cikis_nedeni)
                 VALUES ('cikma', :firma, :bolge, :urun"
                . ($has_rec_cesit ? ", :urun_cesidi" : "")
                . ", :tarih, :cikis_nedeni)"
            );
            $ins_params = [
                ':firma'        => $record['firma'],
                ':bolge'        => $record['bolge'],
                ':urun'         => $record['urun'],
                ':tarih'        => $record['tarih'] ?: null,
                ':cikis_nedeni' => $record['cikis_nedeni'],
            ];
            if ($has_rec_cesit) {
                $ins_params[':urun_cesidi'] = $record['urun_cesidi'] ?? '';
            }
            $st->execute($ins_params);
            $rec_id = (int)$pdo->lastInsertId();

            $st_p = $pdo->prepare(
                "INSERT INTO loading_pallets
                 (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg,
                  kasa_cinsi_id, palet_tipi_id, urun_cinsi"
                  . ($has_pal_cesit ? ", urun_cesidi" : "")
                  . ", depo, sira_no)
                 VALUES
                 (:r, :pno, :ka, :sz, :br, :da, :nt, :kc, :pt, :uc"
                  . ($has_pal_cesit ? ", :ucs" : "")
                  . ", :dp, :sn)"
            );
            $st_m = $pdo->prepare(
                "INSERT INTO pallet_materials
                 (loading_pallet_id, material_id, quantity, total_dara_kg)
                 VALUES (:p, :m, :q, :t)"
            );

            foreach ($computed as $i => $p) {
                $p_params = [
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
                ];
                if ($has_pal_cesit) {
                    $p_params[':ucs'] = $p['urun_cesidi'] ?? '';
                }
                $st_p->execute($p_params);
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
