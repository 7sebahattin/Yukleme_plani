<?php
// =========================================================
// beyan_create.php — Yeni beyan oluşturma (Sprint Beyan-01)
// Bu sprintte otomatik parse yok; raw_text manuel girilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('create')) forbidden();

$errors = [];
$f = [
    'raw_text'          => '',
    'unmatched_text'    => '',
    'declaration_title' => '',
    'company_name'      => '',
    'company_address'   => '',
    'transport_type'    => '',
    'vehicle_plate'     => '',
    'tractor_plate'     => '',
    'driver_name'       => '',
    'driver_phone'      => '',
    'sender_company_name' => '',
    'hks_firma_id'      => '',
    'hks_urun_id'       => '',
    'hks_ulke_id'       => '',
    'line_type'         => '',
    'party_no'          => '',
    'pallet_count'      => '',
    'product_name'      => '',
    'product_variety'   => '',
    'gross_kg'          => '',
    'net_kg'            => '',
    'crate_count'       => '',
    'crate_type'        => '',
    'exit_depot'        => '',
    'contact_person'    => '',
    'buyer_name'        => '',
    'brand'             => '',
    'status'            => 'beyan_acildi',
    'sample_taken_at'   => '',
    'analysis_result_at'=> '',
    'analysis_note'     => '',
    'note'              => '',
    'sample_gross_kg'   => '',
    'sample_net_kg'     => '',
    'sample_crate_type' => '',
    'sample_crate_count'=> '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $_POST = beyan_protect_input($_POST);

    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // Büyük harf — seçili metin alanları
    foreach (['declaration_title', 'company_name', 'company_address', 'transport_type', 'vehicle_plate',
              'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type', 'sample_crate_type',
              'exit_depot', 'contact_person', 'buyer_name', 'brand', 'driver_name'] as $_tf) {
        if ($f[$_tf] !== '') $f[$_tf] = tr_upper($f[$_tf]);
    }
    foreach (beyan_suggest_fields() as $_tf => $_meta) {
        $f[$_tf] = beyan_suggest_normalize($_tf, $f[$_tf]);
    }
    $f['driver_phone'] = beyan_telefon_normalize($f['driver_phone']);

    $checked = beyan_veri_dogrula($f);
    $errors = $checked['errors'];
    if (mb_strlen($f['note'], 'UTF-8') > 2000) $errors[] = 'Not: en fazla 2000 karakter olabilir.';
    $gross_kg = $checked['values']['gross_kg'];
    $net_kg = $checked['values']['net_kg'];
    $pallet_ct = $checked['values']['pallet_count'];
    $crate_ct = $checked['values']['crate_count'];
    $sample_at = $checked['values']['sample_taken_at'];
    $result_at = $checked['values']['analysis_result_at'];
    if (in_array($f['status'], ['temiz', 'red'], true) && $result_at === null) $result_at = date('Y-m-d H:i:s');
    if (array_key_exists('sender_company_name', $_POST)) {   // Gönderici Firma: HKS adı eşleşirse id çözülür, değilse serbest ad
        $g = beyan_gonderici_coz((string)$_POST['sender_company_name']);
        $_POST['hks_firma_id'] = $g['id'];
        $f['sender_company_name'] = $g['ad'];
    }
    [$_POST, $hks_urun_elle] = beyan_hks_urun_post_coz($_POST, $f['product_name']);
    try { $hks = beyan_hks_form_oku($_POST); }
    catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

    if (empty($errors)) {
        $user_id = (int)($auth_user['id'] ?? 0);
        // Plaka boşluksuz + büyük harf — HKS böyle bekler (tek doğruluk kaynağı
        // sunucu; istemcideki data-nospace yalnız kolaylıktır).
        $f['vehicle_plate'] = beyan_plaka_normalize($f['vehicle_plate']);
        $f['tractor_plate'] = mb_substr(beyan_plaka_normalize($f['tractor_plate']), 0, 20, 'UTF-8');

        $st = db()->prepare("INSERT INTO customs_declarations
            (raw_text, unmatched_text, declaration_title, company_name, company_address,
             transport_type, vehicle_plate, line_type, party_no, pallet_count, product_name, product_variety,
             gross_kg, net_kg, crate_count, crate_type, exit_depot, contact_person,
             buyer_name, brand, status, analysis_note, sample_taken_at, analysis_result_at,
             hks_firma_id, hks_urun_id, hks_urun_ad, hks_ulke_id, hks_ulke_ad, note,
             sample_gross_kg, sample_net_kg, sample_crate_type, sample_crate_count,
             tractor_plate, sender_company_name, driver_name, driver_phone,
             created_by, updated_by, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

        $st->execute([
            $f['raw_text']          ?: null,
            $f['unmatched_text']    ?: null,
            $f['declaration_title'] ?: null,
            $f['company_name']      ?: null,
            $f['company_address']   ?: null,
            $f['transport_type']    ?: null,
            $f['vehicle_plate']     ?: null,
            $f['line_type']         ?: null,
            $f['party_no']          ?: null,
            $pallet_ct,
            $f['product_name']      ?: null,
            $f['product_variety']   ?: null,
            $gross_kg,
            $net_kg,
            $crate_ct,
            $f['crate_type']        ?: null,
            $f['exit_depot']        ?: null,
            $f['contact_person']    ?: null,
            $f['buyer_name']        ?: null,
            $f['brand']             ?: null,
            $f['status'],
            $f['analysis_note']     ?: null,
            $sample_at,
            $result_at,
            $hks['firma_id'],
            $hks['urun_id'],
            $hks['urun_ad'],
            $hks['ulke_id'],
            $hks['ulke_ad'],
            $f['note'] ?: null,
            $checked['values']['sample_gross_kg'] ?? null,
            $checked['values']['sample_net_kg'] ?? null,
            $f['sample_crate_type'] ?: null,
            $checked['values']['sample_crate_count'] ?? null,
            $f['tractor_plate'] ?: null,
            $f['sender_company_name'] ?: null,
            $f['driver_name'] ?: null,
            $f['driver_phone'] ?: null,
            $user_id,
            $user_id,
        ]);

        $new_id = (int)db()->lastInsertId();


        // Eşleştirme seçimlerini ÖĞREN — sonraki beyanlarda alan hazır gelsin.
        // Öneri üretir, karar vermez: kullanıcı formda her zaman değiştirebilir.
        if (can_beyan('hks.write') && $hks['urun_id'] && !empty($hks_urun_elle)) hks_eslesme_yaz('urun', $f['product_name'], (string)$hks['urun_id'], (string)$hks['urun_ad']);
        if (can_beyan('hks.write')) beyan_hks_ulke_ogren($f, (string)$hks['ulke_id'], (string)$hks['ulke_ad']);
        if (can_beyan('hks.write') && $hks['firma_id']) bb_son_firma_yaz((string)$hks['firma_id']);

        audit_log_event('beyan_create', 'declarations', $new_id, null, [
            'party_no'     => $f['party_no'],
            'product_name' => $f['product_name'],
            'status'       => $f['status'],
        ]);

        set_flash('success', 'Beyan başarıyla oluşturuldu.');
        header('Location: beyan_view.php?id=' . $new_id);
        exit;
    }
}

$statuses = beyan_statuses();
render_header('Yeni Beyan');
render_flash();
require __DIR__ . '/_beyan_form.php';
render_footer();
