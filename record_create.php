<?php
// =========================================================
// record_create.php - Yeni kayıt oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

$record = [
    'firma' => '', 'bolge' => '', 'parti_no' => '', 'gumruk' => '',
    'nakliye_bedeli' => '', 'avans' => '',
    'sofor_adi' => '', 'fatura_no' => '', 'casus_no' => '',
    'on_plaka' => '', 'arka_plaka' => '', 'nakliye_sirketi' => '', 'telefon' => '',
    'tarih' => date('Y-m-d'), 'alici' => '', 'urun' => '', 'etiket' => '', 'brand' => '',
];
$pallets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $errors = [];

    // Başlık
    foreach (array_keys($record) as $k) {
        $record[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // urun_sahibi_id is INT — handle separately from the string-based foreach above
    $record['urun_sahibi_id'] = !empty($_POST['urun_sahibi_id']) ? (int)$_POST['urun_sahibi_id'] : null;

    $record['firma'] = normalize_firma($record['firma']);
    $record['urun']  = normalize_urun($record['urun']);
    $record['brand'] = in_array(strtoupper($record['brand']), ['ASYA', 'URAL', 'URAS', 'AGRO'], true) ? strtoupper($record['brand']) : '';
    // Geçersiz firma ID → NULL yap
    if (!empty($record['urun_sahibi_id'])) {
        $_us_check = db()->prepare("SELECT id FROM material_definitions WHERE id=:id AND type='firma' AND is_active=1");
        $_us_check->execute([':id' => $record['urun_sahibi_id']]);
        if (!$_us_check->fetchColumn()) {
            $record['urun_sahibi_id'] = null;
        }
    }
    if ($record['tarih'] === '') $errors[] = 'Tarih zorunludur.';
    if ($record['firma'] === '') $errors[] = 'Firma zorunludur.';
    if ($record['urun']  === '') $errors[] = 'Ürün zorunludur.';

    // Paletler
    $raw_pallets = $_POST['pallets'] ?? [];
    if (!is_array($raw_pallets)) $raw_pallets = [];
    $computed = [];
    foreach ($raw_pallets as $rp) {
        if (!is_array($rp)) continue;
        // Tamamen boş satırları atla
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

            // brand / urun_sahibi_id kolonları yoksa (migration çalışmadıysa) sorguya ekleme — defansif
            $has_brand        = db_has_column('loading_records', 'brand');
            $has_urun_sahibi  = db_has_column('loading_records', 'urun_sahibi_id');
            $st = $pdo->prepare(
                "INSERT INTO loading_records
                 (firma, bolge, parti_no, gumruk, nakliye_bedeli, avans, sofor_adi,
                  fatura_no, casus_no, on_plaka, arka_plaka, nakliye_sirketi, telefon,
                  tarih, alici, urun, etiket"
                  . ($has_brand       ? ", brand"          : "")
                  . ($has_urun_sahibi ? ", urun_sahibi_id" : "") . ")
                 VALUES
                 (:firma, :bolge, :parti_no, :gumruk, :nakliye_bedeli, :avans, :sofor_adi,
                  :fatura_no, :casus_no, :on_plaka, :arka_plaka, :nakliye_sirketi, :telefon,
                  :tarih, :alici, :urun, :etiket"
                  . ($has_brand       ? ", :brand"          : "")
                  . ($has_urun_sahibi ? ", :urun_sahibi_id" : "") . ")"
            );
            $ins_params = [
                ':firma' => $record['firma'],
                ':bolge' => $record['bolge'],
                ':parti_no' => $record['parti_no'],
                ':gumruk' => $record['gumruk'],
                ':nakliye_bedeli' => num($record['nakliye_bedeli']),
                ':avans' => num($record['avans']),
                ':sofor_adi' => $record['sofor_adi'],
                ':fatura_no' => $record['fatura_no'],
                ':casus_no' => $record['casus_no'],
                ':on_plaka' => $record['on_plaka'],
                ':arka_plaka' => $record['arka_plaka'],
                ':nakliye_sirketi' => $record['nakliye_sirketi'],
                ':telefon' => $record['telefon'],
                ':tarih' => $record['tarih'] ?: null,
                ':alici' => $record['alici'],
                ':urun' => $record['urun'],
                ':etiket' => $record['etiket'],
            ];
            if ($has_brand) {
                $ins_params[':brand'] = $record['brand'] !== '' ? $record['brand'] : null;
            }
            if ($has_urun_sahibi) {
                $ins_params[':urun_sahibi_id'] = $record['urun_sahibi_id'] ?: null;
            }
            $st->execute($ins_params);
            $rec_id = (int)$pdo->lastInsertId();

            $st_p = $pdo->prepare(
                "INSERT INTO loading_pallets
                 (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg,
                  kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no, islendi)
                 VALUES
                 (:r, :pno, :ka, :sz, :br, :da, :nt, :kc, :pt, :uc, :dp, :sn, :is)"
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
                    ':is' => $p['islendi'] ?? 0,
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
            audit_log_event('create', 'records', $rec_id, null, ['firma' => $record['firma'], 'tarih' => $record['tarih'], 'urun' => $record['urun'], 'palet_sayisi' => count($computed)]);
            set_flash('success', 'Kayıt başarıyla oluşturuldu (#' . $rec_id . ').');
            header('Location: record_view.php?id=' . $rec_id);
            exit;

        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Kayıt hatası: ' . $e->getMessage();
        }
    }

    // Hata durumunda formu girilen değerlerle tekrar çiz
    $pallets = $computed;
}

$form_action  = 'record_create.php';
$title        = 'Yeni Yükleme Kaydı';
$submit_label = 'Kaydet';
$cancel_url   = 'records.php';

render_header($title);

if (!empty($errors)) {
    foreach ($errors as $e) echo '<div class="flash flash-error">' . h($e) . '</div>';
}

include __DIR__ . '/_form.php';
render_footer();
