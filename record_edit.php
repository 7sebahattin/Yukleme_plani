<?php
// =========================================================
// record_edit.php - Mevcut kayıt düzenle
// Strateji: paletleri sil-tekrar oluştur (basit ve güvenli)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz kayıt.');
    header('Location: records.php'); exit;
}

$errors  = [];
$pallets = [];

// POST işlem
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // Kilitli kayıt kontrolü (POST)
    $_lck = db()->prepare("SELECT durum, locked_at FROM loading_records WHERE id=:id");
    $_lck->execute([':id' => $id]);
    $_lck_row = $_lck->fetch();
    if ($_lck_row && (!empty($_lck_row['locked_at']) || $_lck_row['durum'] === 'yuklendi')) {
        if (!can('records.unlock')) {
            forbidden('Bu kayıt kilitlidir. Düzenlemek için kilit açma yetkisi gereklidir. (records.unlock)');
        }
    }

    // Kayıt tipini önceden öğren (UPDATE SQL'i buna göre seçmek için)
    $type_row = db()->prepare("SELECT type FROM loading_records WHERE id=:id");
    $type_row->execute([':id' => $id]);
    $edit_type     = $type_row->fetchColumn() ?: 'yukleme';
    $edit_is_cikma = $edit_type === 'cikma';

    $record = [
        'firma'           => trim((string)($_POST['firma'] ?? '')),
        'bolge'           => trim((string)($_POST['bolge'] ?? '')),
        'tarih'           => trim((string)($_POST['tarih'] ?? '')) ?: null,
        'urun'            => trim((string)($_POST['urun'] ?? '')),
    ];
    if ($edit_is_cikma) {
        $record['cikis_nedeni'] = trim((string)($_POST['cikis_nedeni'] ?? ''));
    }
    if (!$edit_is_cikma) {
        $record += [
            'parti_no'        => trim((string)($_POST['parti_no'] ?? '')),
            'gumruk'          => trim((string)($_POST['gumruk'] ?? '')),
            'nakliye_bedeli'  => num($_POST['nakliye_bedeli'] ?? 0),
            'avans'           => num($_POST['avans'] ?? 0),
            'sofor_adi'       => trim((string)($_POST['sofor_adi'] ?? '')),
            'fatura_no'       => trim((string)($_POST['fatura_no'] ?? '')),
            'casus_no'        => trim((string)($_POST['casus_no'] ?? '')),
            'on_plaka'        => trim((string)($_POST['on_plaka'] ?? '')),
            'arka_plaka'      => trim((string)($_POST['arka_plaka'] ?? '')),
            'nakliye_sirketi' => trim((string)($_POST['nakliye_sirketi'] ?? '')),
            'telefon'         => trim((string)($_POST['telefon'] ?? '')),
            'alici'           => trim((string)($_POST['alici'] ?? '')),
            'etiket'          => trim((string)($_POST['etiket'] ?? '')),
            'brand'           => trim((string)($_POST['brand'] ?? '')),
            'urun_sahibi_id'  => !empty($_POST['urun_sahibi_id']) ? (int)$_POST['urun_sahibi_id'] : null,
        ];
    }

    $raw = $_POST['pallets'] ?? [];
    if (!is_array($raw)) $raw = [];
    $computed = [];
    foreach ($raw as $rp) {
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

    // Normalize
    $record['firma'] = normalize_firma($record['firma']);
    $record['urun']  = normalize_urun($record['urun']);
    foreach (['bolge', 'parti_no', 'gumruk', 'alici', 'sofor_adi', 'fatura_no', 'casus_no', 'on_plaka', 'arka_plaka', 'nakliye_sirketi'] as $_tf) {
        if (isset($record[$_tf]) && $record[$_tf] !== '') $record[$_tf] = tr_upper($record[$_tf]);
    }
    if (!$edit_is_cikma) {
        $record['brand'] = in_array(strtoupper($record['brand']), ['ASYA', 'URAL', 'URAS', 'AGRO'], true) ? strtoupper($record['brand']) : null;
        // Geçersiz firma ID → NULL yap (form akışını bozma)
        if (!empty($record['urun_sahibi_id'])) {
            $_us_check = db()->prepare("SELECT id FROM material_definitions WHERE id=:id AND type='firma' AND is_active=1");
            $_us_check->execute([':id' => $record['urun_sahibi_id']]);
            if (!$_us_check->fetchColumn()) {
                $record['urun_sahibi_id'] = null;
            }
        }
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

    // Validate
    if (empty($record['tarih'])) $errors[] = 'Tarih zorunludur.';
    if ($record['firma'] === '')  $errors[] = 'Firma zorunludur.';
    if ($record['urun']  === '')  $errors[] = 'Ürün zorunludur.';
    if ($edit_is_cikma) {
        $_cn_izin = cikis_nedeni_listesi();
        if (($record['cikis_nedeni'] ?? '') === '' || !in_array($record['cikis_nedeni'], $_cn_izin, true)) {
            $errors[] = 'Çıkış nedeni seçilmelidir.';
        }
    }
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

            // Audit için eski değerleri kaydet
            $_old_row = $pdo->prepare("SELECT firma, bolge, tarih, urun, alici, parti_no FROM loading_records WHERE id=:id");
            $_old_row->execute([':id' => $id]);
            $_audit_old = $_old_row->fetch() ?: null;

            $pdo->beginTransaction();

            // brand / urun_sahibi_id kolonları yoksa (migration çalışmadıysa) çıkar — defansif
            $has_brand       = !$edit_is_cikma && db_has_column('loading_records', 'brand');
            $has_urun_sahibi = !$edit_is_cikma && db_has_column('loading_records', 'urun_sahibi_id');
            if (!$has_brand)       { unset($record['brand']); }
            if (!$has_urun_sahibi) { unset($record['urun_sahibi_id']); }

            if ($edit_is_cikma) {
                $st = $pdo->prepare(
                    "UPDATE loading_records SET firma=:firma, bolge=:bolge, tarih=:tarih, urun=:urun,
                        cikis_nedeni=:cikis_nedeni
                     WHERE id=:id"
                );
                $st->execute([
                    ':firma'        => $record['firma'],
                    ':bolge'        => $record['bolge'],
                    ':tarih'        => $record['tarih'],
                    ':urun'         => $record['urun'],
                    ':cikis_nedeni' => $record['cikis_nedeni'],
                    ':id'           => $id,
                ]);
            } else {
                $st = $pdo->prepare(
                    "UPDATE loading_records SET
                        firma=:firma, bolge=:bolge, parti_no=:parti_no, gumruk=:gumruk,
                        nakliye_bedeli=:nakliye_bedeli, avans=:avans, sofor_adi=:sofor_adi,
                        fatura_no=:fatura_no, casus_no=:casus_no,
                        on_plaka=:on_plaka, arka_plaka=:arka_plaka,
                        nakliye_sirketi=:nakliye_sirketi, telefon=:telefon,
                        tarih=:tarih, alici=:alici, urun=:urun, etiket=:etiket"
                        . ($has_brand       ? ", brand=:brand"                   : "")
                        . ($has_urun_sahibi ? ", urun_sahibi_id=:urun_sahibi_id" : "") . "
                     WHERE id=:id"
                );
                $upd_params = [
                    ':firma'           => $record['firma'],
                    ':bolge'           => $record['bolge'],
                    ':parti_no'        => $record['parti_no'],
                    ':gumruk'          => $record['gumruk'],
                    ':nakliye_bedeli'  => $record['nakliye_bedeli'],
                    ':avans'           => $record['avans'],
                    ':sofor_adi'       => $record['sofor_adi'],
                    ':fatura_no'       => $record['fatura_no'],
                    ':casus_no'        => $record['casus_no'],
                    ':on_plaka'        => $record['on_plaka'],
                    ':arka_plaka'      => $record['arka_plaka'],
                    ':nakliye_sirketi' => $record['nakliye_sirketi'],
                    ':telefon'         => $record['telefon'],
                    ':tarih'           => $record['tarih'],
                    ':alici'           => $record['alici'],
                    ':urun'            => $record['urun'],
                    ':etiket'          => $record['etiket'],
                    ':id'              => $id,
                ];
                if ($has_brand) {
                    $upd_params[':brand'] = $record['brand'];
                }
                if ($has_urun_sahibi) {
                    $upd_params[':urun_sahibi_id'] = $record['urun_sahibi_id'] ?: null;
                }
                $st->execute($upd_params);
            }

            // Paletleri sıfırdan yaz
            $pdo->prepare("DELETE FROM loading_pallets WHERE loading_record_id=:r")
                ->execute([':r' => $id]); // pallet_materials cascade ile silinir

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
                    ':r'  => $id,
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
            sync_malzeme_kullanim($id);
            audit_log_event('update', 'records', $id,
                $_audit_old ?: null,
                ['firma' => $record['firma'], 'tarih' => $record['tarih'], 'urun' => $record['urun']]
            );
            set_flash('success', 'Kayıt güncellendi.');
            header('Location: record_view.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Güncelleme hatası: ' . $e->getMessage();
        }
    }

    // POST hata durumu: gönderilen değerleri form için hazırla
    $record['id']   = $id;
    $record['type'] = $edit_type;
    $pallets = $computed;
}

// GET — kayıt yükle (POST hata durumunda atlanır)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = db()->prepare("SELECT * FROM loading_records WHERE id=:id");
    $st->execute([':id' => $id]);
    $record = $st->fetch();
    if (!$record) {
        set_flash('error', 'Kayıt bulunamadı.');
        header('Location: records.php'); exit;
    }

    // Kilitli kayıt kontrolü (GET)
    if (!empty($record['locked_at']) || ($record['durum'] ?? '') === 'yuklendi') {
        if (!can('records.unlock')) {
            forbidden('Bu kayıt kilitlidir. Düzenlemek için kilit açma yetkisi gereklidir. (records.unlock)');
        }
    }

    $st = db()->prepare("SELECT * FROM loading_pallets WHERE loading_record_id=:r ORDER BY sira_no, id");
    $st->execute([':r' => $id]);
    $pallets = $st->fetchAll();

    $st_pm = db()->prepare("SELECT * FROM pallet_materials WHERE loading_pallet_id=:p");
    foreach ($pallets as &$p) {
        $st_pm->execute([':p' => $p['id']]);
        $p['materials'] = $st_pm->fetchAll();
    }
    unset($p);
}

$form_action  = 'record_edit.php';
$title        = 'Kayıt Düzenle';
$submit_label = 'Güncelle';
$cancel_url    = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';
$form_is_cikma = ($record['type'] ?? 'yukleme') === 'cikma';

render_header($title);
render_flash();
foreach ($errors as $errMsg) {
    echo '<div class="flash flash-error">' . h($errMsg) . '</div>';
}
include __DIR__ . '/_form.php';
render_footer();
