<?php
// =========================================================
// kantar_edit.php - Kantar fişi düzenle
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('kantar.write');

if (!function_exists('_parse_kp_rows')) {
    function _parse_kp_rows(mixed $raw, array $kasa_list, array $palet_list): array {
        $rows = []; $kasa_tot = 0.0; $palet_tot = 0.0;
        $first_kasa = ''; $first_palet = '';
        $tot_kasa_say = 0; $tot_palet_say = 0;
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $tip   = in_array($row['tip'] ?? '', ['kasa','palet']) ? $row['tip'] : 'kasa';
                $cinsi = trim((string)($row['cinsi'] ?? ''));
                $sayisi = max(0, (int)($row['sayisi'] ?? 0));
                if ($cinsi === '' || $sayisi <= 0) continue;
                $birim = 0.0;
                $list  = $tip === 'kasa' ? $kasa_list : $palet_list;
                foreach ($list as $kd) { if ($kd['name'] === $cinsi) { $birim = (float)$kd['unit_dara_kg']; break; } }
                $rows[] = ['tip' => $tip, 'cinsi' => $cinsi, 'sayisi' => $sayisi, 'birim_dara_kg' => $birim];
                if ($tip === 'kasa') { $kasa_tot += $sayisi * $birim; $tot_kasa_say += $sayisi; if ($first_kasa === '') $first_kasa = $cinsi; }
                else                 { $palet_tot += $sayisi * $birim; $tot_palet_say += $sayisi; if ($first_palet === '') $first_palet = $cinsi; }
            }
        }
        $kasa_unit  = $tot_kasa_say  > 0 ? round($kasa_tot  / $tot_kasa_say,  3) : 0.0;
        $palet_unit = $tot_palet_say > 0 ? round($palet_tot / $tot_palet_say, 3) : 0.0;
        return [$rows, $kasa_tot, $palet_tot, $first_kasa, $first_palet, $tot_kasa_say, $tot_palet_say, $kasa_unit, $palet_unit];
    }
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Geçersiz fiş.');
    header('Location: kantar.php'); exit;
}

$st = db()->prepare("SELECT * FROM kantar_fisleri WHERE id = ?");
$st->execute([$id]);
$fis = $st->fetch();
if (!$fis) {
    set_flash('error', 'Fiş bulunamadı.');
    header('Location: kantar.php'); exit;
}

$kasa_list  = get_definitions_by_type('kasa_cinsi');
$palet_list = get_definitions_by_type('palet_tipi');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $fields = [
        'fis_no', 'plaka', 'firma_adi', 'giris_tarih', 'cikis_tarih', 'operator_adi',
        'malin_cinsi', 'geldigi_yer', 'gittigi_yer',
        'aciklama', 'depo', 'parti_no',
        'tartim1', 'alibi1', 'tartim2', 'alibi2',
    ];
    foreach ($fields as $k) {
        $fis[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // ── Normalleştir ──────────────────────────────────────
    $fis['firma_adi']   = normalize_firma($fis['firma_adi']);
    $fis['malin_cinsi'] = normalize_urun($fis['malin_cinsi']);
    $fis['depo']        = normalize_depo($fis['depo']);

    // ── Zorunlu alan kontrolü ─────────────────────────────
    if ($fis['giris_tarih'] === '') $errors[] = 'Giriş tarihi zorunludur.';
    if ($fis['firma_adi']   === '') $errors[] = 'Firma adı zorunludur.';
    if ($fis['malin_cinsi'] === '') $errors[] = 'Mal cinsi zorunludur.';
    if ($fis['depo']        === '') $errors[] = 'Depo zorunludur.';
    $_t1c = num($fis['tartim1']);
    $_t2c = num($fis['tartim2']);
    if (max(0.0, $_t1c - $_t2c) <= 0) {
        $errors[] = 'Net KG sıfırdan büyük olmalıdır (1. tartım > 2. tartım).';
    }

    if (empty($errors)) {
        ensure_definition('firma', $fis['firma_adi']);
        ensure_definition('urun',  $fis['malin_cinsi']);
        ensure_definition('depo',  $fis['depo']);
    }

    if (empty($errors)) try {
        $t1  = num($fis['tartim1']);
        $t2  = num($fis['tartim2']);
        $net = max(0, $t1 - $t2);

        [$kp_rows, $kasa_tot_dara, $palet_tot_dara, $first_kasa, $first_palet, $tot_kasa_say, $tot_palet_say, $kasa_dara_val, $palet_dara_val] = _parse_kp_rows($_POST['kp_rows'] ?? [], $kasa_list, $palet_list);

        $foto_raw = trim((string)($_POST['foto_data'] ?? ''));
        $foto_sql = '';
        $foto_val = null;
        if ($foto_raw === '__clear__') {
            $foto_sql = ', foto_data=NULL';
        } elseif (str_starts_with($foto_raw, 'data:image/')) {
            $foto_sql = ', foto_data=?';
            $foto_val = $foto_raw;
        }

        $params = [
            $fis['fis_no'],
            strtoupper($fis['plaka']),
            $fis['firma_adi'],
            $fis['giris_tarih'],
            $fis['cikis_tarih'],
            $fis['operator_adi'],
            $fis['malin_cinsi'],
            $fis['geldigi_yer'],
            $fis['gittigi_yer'],
            $tot_palet_say,
            $first_palet,
            $first_kasa,
            $tot_kasa_say,
            $fis['aciklama'],
            $fis['depo'],
            $fis['parti_no'],
            $t1,
            $fis['alibi1'],
            $t2,
            $fis['alibi2'],
            $net,
            $kasa_dara_val,
            $palet_dara_val,
            $kasa_tot_dara,
            $palet_tot_dara,
        ];
        if ($foto_val !== null) $params[] = $foto_val;
        $params[] = $id;

        db()->prepare(
            "UPDATE kantar_fisleri SET
             fis_no=?, plaka=?, firma_adi=?, giris_tarih=?, cikis_tarih=?, operator_adi=?,
             malin_cinsi=?, geldigi_yer=?, gittigi_yer=?,
             palet_sayisi=?, palet_cinsi=?, kasa_cinsi=?, kasa_sayisi=?,
             aciklama=?, depo=?, parti_no=?,
             tartim1=?, alibi1=?, tartim2=?, alibi2=?, net_kg=?,
             kasa_dara=?, palet_dara=?, kasa_dara_total=?, palet_dara_total=?
             {$foto_sql}
             WHERE id=?"
        )->execute($params);

        // Kasa/palet satırları: sil + yeniden ekle
        db()->prepare("DELETE FROM kantar_kasa_palet_satir WHERE fis_id=?")->execute([$id]);
        if (!empty($kp_rows)) {
            $kp_st = db()->prepare("INSERT INTO kantar_kasa_palet_satir (fis_id, tip, cinsi, sayisi, birim_dara_kg) VALUES (?,?,?,?,?)");
            foreach ($kp_rows as $row) {
                $kp_st->execute([$id, $row['tip'], $row['cinsi'], $row['sayisi'], $row['birim_dara_kg']]);
            }
        }

        // Grupları güncelle (mevcut sil → yeniden ekle)
        db()->prepare("DELETE FROM kantar_gruplar WHERE fis_id = ?")->execute([$id]);
        if (!empty($_POST['gruplar']) && is_array($_POST['gruplar'])) {
            $gst = db()->prepare("INSERT INTO kantar_gruplar (fis_id, sira, grup_adi, palet_sayisi, kasa_adedi, kasa_dara_kg, palet_dara_kg, brut_kg) VALUES (?,?,?,?,?,?,?,?)");
            $sira = 0;
            foreach ($_POST['gruplar'] as $g) {
                $ga = normalize_firma(trim((string)($g['grup_adi'] ?? '')));
                if ($ga === '') continue;
                ensure_definition('firma', $ga);
                $gst->execute([$id, ++$sira, $ga, (int)($g['palet_sayisi'] ?? 0), (int)($g['kasa_adedi'] ?? 0), num($g['kasa_dara_kg'] ?? '0'), num($g['palet_dara_kg'] ?? '0'), num($g['brut_kg'] ?? '0')]);
            }
        }
        audit_log_event('update', 'kantar', $id, null, ['firma' => $fis['firma_adi'], 'tarih' => $fis['giris_tarih'], 'net_kg' => $net]);
        set_flash('success', 'Fiş güncellendi.');
        header('Location: kantar_view.php?id=' . $id);
        exit;

    } catch (Throwable $e) {
        $errors[] = 'Güncelleme hatası: ' . $e->getMessage();
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
