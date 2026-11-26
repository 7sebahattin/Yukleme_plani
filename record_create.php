<?php
// =========================================================
// record_create.php - Yeni kayıt oluştur
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.loading.create');

$record = [
    'firma' => '', 'bolge' => '', 'parti_no' => '', 'gumruk' => '',
    'nakliye_bedeli' => '', 'avans' => '',
    'sofor_adi' => '', 'fatura_no' => '', 'casus_no' => '',
    'on_plaka' => '', 'arka_plaka' => '', 'nakliye_sirketi' => '', 'telefon' => '', 'ulasim' => '', 'gidecek_ulke' => '',
    'tarih' => date('Y-m-d'), 'alici' => '', 'urun' => '', 'urun_cesidi' => '', 'etiket' => '', 'brand' => '',
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

    $record['firma']       = normalize_firma($record['firma']);
    $record['urun']        = normalize_urun($record['urun']);
    $record['urun_cesidi'] = tr_upper($record['urun_cesidi']);
    // Marka artık serbest metin kutusu. Tanımda yoksa SESSİZCE silme —
    // kullanıcıya söyle ki "+ ekle" ile eklesin veya düzeltsin.
    [$_brand_ok_val, $_brand_valid] = resolve_brand_value($record['brand']);
    if (!$_brand_valid) {
        $errors[] = 'Marka tanımlarda yok: "' . $record['brand'] . '". Listeden seçin veya kutudaki "+ ekle" ile tanımlara ekleyin.';
    }
    $record['brand'] = $_brand_ok_val ?? '';
    foreach (['parti_no', 'bolge', 'gumruk', 'alici', 'sofor_adi', 'fatura_no', 'casus_no', 'on_plaka', 'arka_plaka', 'nakliye_sirketi'] as $_tf) {
        if (isset($record[$_tf]) && $record[$_tf] !== '') $record[$_tf] = tr_upper($record[$_tf]);
    }
    // Geçersiz firma ID → NULL yap
    if (!empty($record['urun_sahibi_id'])) {
        $_us_check = db()->prepare("SELECT id FROM material_definitions WHERE id=:id AND type='firma' AND is_active=1");
        $_us_check->execute([':id' => $record['urun_sahibi_id']]);
        if (!$_us_check->fetchColumn()) {
            $record['urun_sahibi_id'] = null;
        }
    }
    // Kolon uzunluğuna kırp — strict mode'da INSERT hatası, değilse sessiz
    // kırpma olurdu; ikisi de olmasın (Sprint Öneri-01).
    $record = clamp_loading_record_fields($record);

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

            // brand / urun_sahibi_id / ulasim / gidecek_ulke / urun_cesidi kolonları yoksa (migration çalışmadıysa) sorguya ekleme — defansif
            $has_brand        = db_has_column('loading_records', 'brand');
            $has_urun_sahibi  = db_has_column('loading_records', 'urun_sahibi_id');
            $has_ulasim       = db_has_column('loading_records', 'ulasim');
            $has_gidecek_ulke = ensure_column('loading_records', 'gidecek_ulke',
                "ALTER TABLE `loading_records` ADD COLUMN `gidecek_ulke` VARCHAR(100) NOT NULL DEFAULT ''");
            $has_rec_cesit    = ensure_column('loading_records', 'urun_cesidi',
                "ALTER TABLE `loading_records` ADD COLUMN `urun_cesidi` VARCHAR(150) NOT NULL DEFAULT ''");
            $has_pal_cesit    = ensure_column('loading_pallets', 'urun_cesidi',
                "ALTER TABLE `loading_pallets` ADD COLUMN `urun_cesidi` VARCHAR(150) NOT NULL DEFAULT ''");

            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO loading_records
                 (firma, bolge, parti_no, gumruk, nakliye_bedeli, avans, sofor_adi,
                  fatura_no, casus_no, on_plaka, arka_plaka, nakliye_sirketi, telefon,
                  tarih, alici, urun, etiket"
                  . ($has_rec_cesit    ? ", urun_cesidi"     : "")
                  . ($has_ulasim       ? ", ulasim"          : "")
                  . ($has_gidecek_ulke ? ", gidecek_ulke"    : "")
                  . ($has_brand        ? ", brand"           : "")
                  . ($has_urun_sahibi  ? ", urun_sahibi_id"  : "") . ")
                 VALUES
                 (:firma, :bolge, :parti_no, :gumruk, :nakliye_bedeli, :avans, :sofor_adi,
                  :fatura_no, :casus_no, :on_plaka, :arka_plaka, :nakliye_sirketi, :telefon,
                  :tarih, :alici, :urun, :etiket"
                  . ($has_rec_cesit    ? ", :urun_cesidi"     : "")
                  . ($has_ulasim       ? ", :ulasim"          : "")
                  . ($has_gidecek_ulke ? ", :gidecek_ulke"    : "")
                  . ($has_brand        ? ", :brand"           : "")
                  . ($has_urun_sahibi  ? ", :urun_sahibi_id"  : "") . ")"
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
            if ($has_rec_cesit) {
                $ins_params[':urun_cesidi'] = $record['urun_cesidi'] ?? '';
            }
            if ($has_ulasim) {
                $ins_params[':ulasim'] = $record['ulasim'] ?? '';
            }
            if ($has_gidecek_ulke) {
                $ins_params[':gidecek_ulke'] = $record['gidecek_ulke'] ?? '';
            }
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
                  kasa_cinsi_id, palet_tipi_id, urun_cinsi"
                  . ($has_pal_cesit ? ", urun_cesidi" : "")
                  . ", depo, sira_no, islendi)
                 VALUES
                 (:r, :pno, :ka, :sz, :br, :da, :nt, :kc, :pt, :uc"
                  . ($has_pal_cesit ? ", :ucs" : "")
                  . ", :dp, :sn, :is)"
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
                    ':is' => $p['islendi'] ?? 0,
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
            audit_log_event('create', 'records', $rec_id, null, ['firma' => $record['firma'], 'tarih' => $record['tarih'], 'urun' => $record['urun'], 'palet_sayisi' => count($computed)]);
            set_flash('success', 'Kayıt başarıyla oluşturuldu (#' . $rec_id . ').'
                . (!$has_gidecek_ulke ? ' ⚠️ "Gideceği Ülke" kaydedilemedi — veritabanında "gidecek_ulke" kolonu eksik ve otomatik eklenemedi. migrate.php sayfasından elle ekleyin.' : ''));
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
