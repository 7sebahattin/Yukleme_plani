<?php
// =========================================================
// record_edit.php - Mevcut kayıt düzenle
// Strateji: paletleri sil-tekrar oluştur (basit ve güvenli)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz kayıt.');
    header('Location: records.php'); exit;
}

// POST işlem
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $record = [
        'firma'           => trim((string)($_POST['firma'] ?? '')),
        'bolge'           => trim((string)($_POST['bolge'] ?? '')),
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
        'tarih'           => trim((string)($_POST['tarih'] ?? '')) ?: null,
        'alici'           => trim((string)($_POST['alici'] ?? '')),
        'urun'            => trim((string)($_POST['urun'] ?? '')),
        'etiket'          => trim((string)($_POST['etiket'] ?? '')),
    ];

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

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $st = $pdo->prepare(
            "UPDATE loading_records SET
                firma=:firma, bolge=:bolge, parti_no=:parti_no, gumruk=:gumruk,
                nakliye_bedeli=:nakliye_bedeli, avans=:avans, sofor_adi=:sofor_adi,
                fatura_no=:fatura_no, casus_no=:casus_no,
                on_plaka=:on_plaka, arka_plaka=:arka_plaka,
                nakliye_sirketi=:nakliye_sirketi, telefon=:telefon,
                tarih=:tarih, alici=:alici, urun=:urun, etiket=:etiket
             WHERE id=:id"
        );
        $st->execute(array_merge($record, [':id' => $id]));

        // Paletleri sıfırdan yaz
        $pdo->prepare("DELETE FROM loading_pallets WHERE loading_record_id=:r")
            ->execute([':r' => $id]); // pallet_materials cascade ile silinir

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
        set_flash('success', 'Kayıt güncellendi.');
        header('Location: record_view.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        set_flash('error', 'Güncelleme hatası: ' . $e->getMessage());
    }
}

// GET — kayıt yükle
$st = db()->prepare("SELECT * FROM loading_records WHERE id=:id");
$st->execute([':id' => $id]);
$record = $st->fetch();
if (!$record) {
    set_flash('error', 'Kayıt bulunamadı.');
    header('Location: records.php'); exit;
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

$form_action  = 'record_edit.php';
$title        = 'Kayıt Düzenle #' . $id;
$submit_label = 'Güncelle';
$cancel_url    = ($record['type'] ?? 'yukleme') === 'cikma' ? 'cikmalar.php' : 'records.php';
$form_is_cikma = ($record['type'] ?? 'yukleme') === 'cikma';

render_header($title);
render_flash();
include __DIR__ . '/_form.php';
render_footer();
