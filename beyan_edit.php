<?php
// =========================================================
// beyan_edit.php — Beyan düzenleme (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$beyan_action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (isset($_POST['hks_bag_islem']) ? 'notify' : (!empty($_POST['status_only']) ? 'status' : 'edit')) : 'edit';
if (!can_beyan($beyan_action)) forbidden();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('error', 'Geçersiz beyan.'); header('Location: beyanlar.php'); exit; }

$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ? AND deleted_at IS NULL");
$st->execute([$id]);
$beyan = $st->fetch();
if (!$beyan) { set_flash('error', 'Beyan bulunamadı veya silinmiş.'); header('Location: beyanlar.php'); exit; }

$errors = [];
$edit_token = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['edit_token'] ?? '') : beyan_edit_token($beyan);

// Formu DB değerleri ile önceden doldur
$f = [
    'raw_text'           => (string)($beyan['raw_text']           ?? ''),
    'unmatched_text'     => (string)($beyan['unmatched_text']     ?? ''),
    'declaration_title'  => (string)($beyan['declaration_title']  ?? ''),
    'company_name'       => (string)($beyan['company_name']       ?? ''),
    'company_address'    => (string)($beyan['company_address']    ?? ''),
    'transport_type'     => (string)($beyan['transport_type']     ?? ''),
    'vehicle_plate'      => (string)($beyan['vehicle_plate']      ?? ''),
    'hks_firma_id'       => (string)($beyan['hks_firma_id']       ?? ''),
    'hks_urun_id'        => (string)($beyan['hks_urun_id']        ?? ''),
    'hks_ulke_id'        => (string)($beyan['hks_ulke_id']        ?? ''),
    'line_type'          => (string)($beyan['line_type']          ?? ''),
    'party_no'           => (string)($beyan['party_no']           ?? ''),
    'pallet_count'       => $beyan['pallet_count'] !== null ? (string)(int)$beyan['pallet_count'] : '',
    'product_name'       => (string)($beyan['product_name']       ?? ''),
    'product_variety'    => (string)($beyan['product_variety']    ?? ''),
    'gross_kg'           => beyan_edit_kg($beyan['gross_kg']),
    'net_kg'             => beyan_edit_kg($beyan['net_kg']),
    'crate_count'        => $beyan['crate_count'] !== null ? (string)(int)$beyan['crate_count'] : '',
    'crate_type'         => (string)($beyan['crate_type']         ?? ''),
    'exit_depot'         => (string)($beyan['exit_depot']         ?? ''),
    'contact_person'     => (string)($beyan['contact_person']     ?? ''),
    'buyer_name'         => (string)($beyan['buyer_name']         ?? ''),
    'brand'              => (string)($beyan['brand']              ?? ''),
    'status'             => (string)($beyan['status']             ?? 'beyan_acildi'),
    'sample_taken_at'    => $beyan['sample_taken_at']    ? date('Y-m-d\TH:i', strtotime($beyan['sample_taken_at']))    : '',
    'analysis_result_at' => $beyan['analysis_result_at'] ? date('Y-m-d\TH:i', strtotime($beyan['analysis_result_at'])) : '',
    'analysis_note'      => (string)($beyan['analysis_note']      ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $old_status = $beyan['status'];
    $_POST = beyan_protect_input($_POST, $f);

    // Hal Bildirim bağı düzeltmesi — ayrı form (sayfa altındaki "Hal Bildirim
    // Durumu" kutusu). Beyan alanlarına DOKUNMAZ. Kapı "Bildirim Yap" ile
    // aynıdır: beyan.write + records.write.
    if (isset($_POST['hks_bag_islem'])) {
        if (!can_beyan('hks.write') || !(can('records.write') || is_admin())) forbidden();
        $islem = (string)$_POST['hks_bag_islem'];
        $once  = beyan_hks_aktif($id);
        $hata  = beyan_hks_bag_duzelt($id, $islem, (int)($auth_user['id'] ?? 0));
        if ($hata !== null) {
            set_flash('error', $hata);
        } else {
            $sonra = beyan_hks_aktif($id);
            audit_log_event('beyan_hks_bag_duzelt', 'declarations', $id,
                ['hks_durum' => $once['durum'] ?? null],
                ['hks_durum' => $sonra['durum'] ?? null, 'islem' => $islem,
                 'gonderim_id' => $sonra['gonderim_id'] ?? null]);
            set_flash('success', $islem === 'tekrar_aktif'
                ? 'Bildirim tekrar aktif edildi — beyan yeniden "Bildirim Yap"a açık.'
                : 'Beyan HKS\'e gönderildi olarak işaretlendi.');
        }
        header('Location: beyan_edit.php?id=' . $id); exit;
    }

    // Yalnızca durum geçişi (liste ekranındaki hızlı butonlar) — diğer alanlara dokunma
    if (!empty($_POST['status_only'])) {
        if (!can_beyan('analysis.write')) foreach (beyan_permission_fields()['analysis'] as $field) unset($_POST[$field]);
        $new_status     = (string)($_POST['status'] ?? '');
        $valid_statuses = array_keys(beyan_statuses());
        $return_url = ($_POST['return_to'] ?? '') === 'view' ? 'beyan_view.php?id=' . $id : 'beyanlar.php';
        if (!in_array($new_status, $valid_statuses, true)) {
            set_flash('error', 'Geçersiz durum geçişi.');
            header('Location: ' . $return_url); exit;
        }
        try {
            $change = beyan_durum_kaydet(db(), $id, $new_status, $_POST, (int)($auth_user['id'] ?? 0));
            audit_log_event('beyan_status_change', 'declarations', $id,
                $change['old'], $change['new']);
            set_flash('success', 'Durum güncellendi: ' . (beyan_statuses()[$new_status]['label'] ?? $new_status));
        } catch (InvalidArgumentException $e) {
            set_flash('error', $e->getMessage());
        }
        header('Location: ' . $return_url); exit;
    }

    foreach (array_keys($f) as $k) {
        // Formdan kaldırılan eski başlığı sıradan düzenlemede silme.
        if ($k === 'declaration_title' && !array_key_exists($k, $_POST)) continue;
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
    try {
        if (can_beyan('hks.write')) $hks = beyan_hks_form_oku($_POST, $beyan);
        else {
            $hks = [];
            foreach (['firma_id','urun_id','urun_ad','ulke_id','ulke_ad'] as $key) $hks[$key] = $beyan['hks_' . $key] ?? null;
        }
    }
    catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

    // Preserve exact stored values, including timestamp seconds, in protected sections.
    $f = beyan_protect_input($f, $beyan);
    if (!can_beyan('analysis.write')) {
        $sample_at = $beyan['sample_taken_at'];
        $result_at = $beyan['analysis_result_at'];
    }

    // temiz/red geçişinde analysis_result_at yoksa otomatik set
    if (can_beyan('analysis.write') && in_array($f['status'], ['temiz', 'red'], true) && $result_at === null) {
        $result_at = date('Y-m-d H:i:s');
        $f['analysis_result_at'] = date('Y-m-d\TH:i');
    }

    if (empty($errors)) {
        try { $beyan = beyan_duzenleme_kilitle(db(), $id, $edit_token); }
        catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
    }

    if (empty($errors)) {
        $user_id = (int)($auth_user['id'] ?? 0);
        // Plaka boşluksuz + büyük harf — HKS böyle bekler (tek doğruluk kaynağı
        // sunucu; istemcideki data-nospace yalnız kolaylıktır).
        $f['vehicle_plate'] = beyan_plaka_normalize($f['vehicle_plate']);

        $st = db()->prepare("UPDATE customs_declarations SET
            raw_text = ?, unmatched_text = ?, declaration_title = ?,
            company_name = ?, company_address = ?,
            transport_type = ?, vehicle_plate = ?, line_type = ?, party_no = ?,
            pallet_count = ?, product_name = ?, product_variety = ?,
            gross_kg = ?, net_kg = ?, crate_count = ?, crate_type = ?,
            exit_depot = ?, contact_person = ?, buyer_name = ?, brand = ?,
            status = ?, analysis_note = ?, sample_taken_at = ?, analysis_result_at = ?,
            hks_firma_id = ?, hks_urun_id = ?, hks_urun_ad = ?, hks_ulke_id = ?, hks_ulke_ad = ?,
            updated_by = ?, updated_at = NOW()
            WHERE id = ?");

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
            $id,
        ]);
        db()->commit();

        // Durum değişti ise ayrı audit
        if ($old_status !== $f['status']) {
            audit_log_event('beyan_status_change', 'declarations', $id,
                ['status' => $old_status],
                ['status' => $f['status'], 'analysis_note' => $f['analysis_note']]
            );
        } else {

            // Eşleştirme seçimlerini ÖĞREN — sonraki beyanlarda alan hazır gelsin.
            // Öneri üretir, karar vermez: kullanıcı formda her zaman değiştirebilir.
            if (can_beyan('hks.write') && $hks['urun_id']) hks_eslesme_yaz('urun', $f['product_name'], (string)$hks['urun_id'], (string)$hks['urun_ad']);
            if (can_beyan('hks.write')) beyan_hks_ulke_ogren($f, (string)$hks['ulke_id'], (string)$hks['ulke_ad']);
            if (can_beyan('hks.write') && $hks['firma_id']) bb_son_firma_yaz((string)$hks['firma_id']);

            audit_log_event('beyan_update', 'declarations', $id, null, [
                'party_no'     => $f['party_no'],
                'product_name' => $f['product_name'],
                'status'       => $f['status'],
            ]);
        }

        set_flash('success', 'Beyan güncellendi.');
        header('Location: beyan_view.php?id=' . $id);
        exit;
    }
}

$statuses    = beyan_statuses();
$cur_status  = $beyan['status'];
// Hal Bildirim Durumu kutusu — elle bağ düzeltmesi (beyan_hks_bag_duzelt).
$hks_bag_yetki = can_beyan('notify') && can_beyan('hks.write') && (can('records.write') || is_admin());
$hks_bag_aktif = beyan_hks_aktif($id);
$hks_bag_son   = beyan_hks_gecmis($id)[0] ?? null;
// Yalnız "akıştaki sıradaki durum" ipucu için — kapı DEĞİL. Durum listesi
// beyan_view.php'deki şeritle aynı: tüm durumlar seçilebilir.
$next_states = beyan_next_statuses($cur_status);

render_header('Beyan Düzenle');
render_flash();
require __DIR__ . '/_beyan_form.php';
?>
<details class="beyan-workspace-bag" hidden>
    <summary>Hal Bildirim Durumu / Bağlantı düzeltme</summary>
<?php if ($hks_bag_yetki): ?>
<!-- Hal Bildirim Durumu — ANA FORMUN DIŞINDA kendi formu (iç içe form geçersiz).
     Taslağı Hal Kayıt'ta düzenlenip gönderilmiş ama bağı kopmuş beyanlar
     "gönderildi" işaretlenir; taslağı silinmiş beyan tekrar aktif edilir. -->
<div class="beyan-section">
    <div class="beyan-section-title">🏛 Hal Bildirim Durumu</div>
    <p style="margin:0 0 10px">
        Şu an:
        <?php if ($hks_bag_aktif): ?>
        <span class="beyan-badge"><?= h(beyan_hks_durum_etiket($hks_bag_aktif['durum'])) ?></span>
        <?php elseif ($hks_bag_son): ?>
        <span class="beyan-badge"><?= h(beyan_hks_durum_etiket($hks_bag_son['durum'])) ?></span>
        <span class="muted">— bildirime açık</span>
        <?php else: ?>
        <span class="muted">Bildirim yapılmadı — bildirime açık</span>
        <?php endif; ?>
    </p>
    <form method="post" action="beyan_edit.php?id=<?= $id ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <?php if (!$hks_bag_aktif): ?>
        <button type="submit" name="hks_bag_islem" value="gonderildi_isaretle" class="btn"
                onclick="return confirm('Bu beyan HKS\'e gönderilmiş sayılacak ve yeniden bildirime kapanacak. Devam edilsin mi?')">✅ Gönderildi Olarak İşaretle</button>
        <span class="form-hint">Bildirim HKS'e yapıldığı hâlde beyan "uygun" görünüyorsa (ör. taslak Hal Kayıt'ta düzenlenip gönderildi).</span>
        <?php elseif ($hks_bag_aktif['durum'] === 'silindi'): ?>
        <button type="submit" name="hks_bag_islem" value="tekrar_aktif" class="btn"
                onclick="return confirm('Beyan yeniden bildirime açılacak. HKS\'e gönderilmediğinden emin misiniz?')">↺ Tekrar Aktif Et</button>
        <span class="form-hint">Taslak Hal Kayıt'ta silindiği için bildirim yapılmış sayılıyor. HKS'e gönderilmediyse beyanı yeniden bildirime açar.</span>
        <?php elseif ($hks_bag_aktif['durum'] === 'taslak'): ?>
        <span class="form-hint">Bekleyen taslak Hal Kayıt panelinden gönderilir ya da silinir.</span>
        <?php else: ?>
        <span class="form-hint">Gönderilmiş bildirim geri alınamaz.</span>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

</details>
<?php render_footer(); ?>
