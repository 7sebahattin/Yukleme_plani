<?php
// =========================================================
// beyan_create.php — Yeni beyan oluşturma (Sprint Beyan-01)
// Bu sprintte otomatik parse yok; raw_text manuel girilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('write')) forbidden();

$errors = [];
$f = [
    'raw_text'          => '',
    'unmatched_text'    => '',
    'declaration_title' => '',
    'company_name'      => '',
    'company_address'   => '',
    'transport_type'    => '',
    'vehicle_plate'     => '',
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
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // Büyük harf — seçili metin alanları
    foreach (['declaration_title', 'company_name', 'company_address', 'transport_type', 'vehicle_plate',
              'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type',
              'exit_depot', 'contact_person', 'buyer_name', 'brand'] as $_tf) {
        if ($f[$_tf] !== '') $f[$_tf] = tr_upper($f[$_tf]);
    }
    foreach (beyan_suggest_fields() as $_tf => $_meta) {
        $f[$_tf] = beyan_suggest_normalize($_tf, $f[$_tf]);
    }

    $checked = beyan_veri_dogrula($f);
    $errors = $checked['errors'];
    $gross_kg = $checked['values']['gross_kg'];
    $net_kg = $checked['values']['net_kg'];
    $pallet_ct = $checked['values']['pallet_count'];
    $crate_ct = $checked['values']['crate_count'];
    $sample_at = $checked['values']['sample_taken_at'];
    $result_at = $checked['values']['analysis_result_at'];
    if (in_array($f['status'], ['temiz', 'red'], true) && $result_at === null) $result_at = date('Y-m-d H:i:s');
    try { $hks = beyan_hks_form_oku($_POST); }
    catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

    if (empty($errors)) {
        $user_id = (int)($auth_user['id'] ?? 0);
        // Plaka boşluksuz + büyük harf — HKS böyle bekler (tek doğruluk kaynağı
        // sunucu; istemcideki data-nospace yalnız kolaylıktır).
        $f['vehicle_plate'] = beyan_plaka_normalize($f['vehicle_plate']);

        $st = db()->prepare("INSERT INTO customs_declarations
            (raw_text, unmatched_text, declaration_title, company_name, company_address,
             transport_type, vehicle_plate, line_type, party_no, pallet_count, product_name, product_variety,
             gross_kg, net_kg, crate_count, crate_type, exit_depot, contact_person,
             buyer_name, brand, status, analysis_note, sample_taken_at, analysis_result_at,
             hks_firma_id, hks_urun_id, hks_urun_ad, hks_ulke_id, hks_ulke_ad,
             created_by, updated_by, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             ?, ?, NOW(), NOW())");

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
            $user_id,
            $user_id,
        ]);

        $new_id = (int)db()->lastInsertId();


        // Eşleştirme seçimlerini ÖĞREN — sonraki beyanlarda alan hazır gelsin.
        // Öneri üretir, karar vermez: kullanıcı formda her zaman değiştirebilir.
        if ($hks['urun_id']) hks_eslesme_yaz('urun', $f['product_name'], (string)$hks['urun_id'], (string)$hks['urun_ad']);
        beyan_hks_ulke_ogren($f, (string)$hks['ulke_id'], (string)$hks['ulke_ad']);
        if ($hks['firma_id']) bb_son_firma_yaz((string)$hks['firma_id']);

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
