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
    foreach (['declaration_title', 'company_name', 'company_address', 'transport_type',
              'line_type', 'party_no', 'product_name', 'product_variety', 'crate_type',
              'exit_depot', 'contact_person', 'buyer_name', 'brand'] as $_tf) {
        if ($f[$_tf] !== '') $f[$_tf] = tr_upper($f[$_tf]);
    }

    // Validasyon
    if ($f['raw_text'] === '' && $f['party_no'] === '' && $f['product_name'] === '') {
        $errors[] = 'En az bir alan dolu olmalıdır: WhatsApp metni, Parti No veya Ürün Adı.';
    }

    $valid_statuses = array_keys(beyan_statuses());
    if (!in_array($f['status'], $valid_statuses, true)) {
        $f['status'] = 'beyan_acildi';
    }

    // Sayı normalize (Türkçe binlik nokta → tam sayı)
    $gross_kg  = $f['gross_kg']  !== '' ? num($f['gross_kg'])  : null;
    $net_kg    = $f['net_kg']    !== '' ? num($f['net_kg'])    : null;
    $pallet_ct = $f['pallet_count'] !== '' ? (int)num($f['pallet_count']) : null;
    $crate_ct  = $f['crate_count']  !== '' ? (int)num($f['crate_count'])  : null;

    // Tarih alanları
    $sample_at  = ($f['sample_taken_at']    !== '' && strtotime($f['sample_taken_at']))    ? $f['sample_taken_at']    : null;
    $result_at  = ($f['analysis_result_at'] !== '' && strtotime($f['analysis_result_at'])) ? $f['analysis_result_at'] : null;

    // Red durumunda analysis_note zorunlu
    if ($f['status'] === 'red' && trim($f['analysis_note']) === '') {
        $errors[] = '"Red" durumu için analiz notu zorunludur.';
    }

    if (empty($errors)) {
        $user_id = (int)($auth_user['id'] ?? 0);

        $st = db()->prepare("INSERT INTO customs_declarations
            (raw_text, unmatched_text, declaration_title, company_name, company_address,
             transport_type, line_type, party_no, pallet_count, product_name, product_variety,
             gross_kg, net_kg, crate_count, crate_type, exit_depot, contact_person,
             buyer_name, brand, status, analysis_note, sample_taken_at, analysis_result_at,
             created_by, updated_by, created_at, updated_at)
            VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, NOW(), NOW())");

        $st->execute([
            $f['raw_text']          ?: null,
            $f['unmatched_text']    ?: null,
            $f['declaration_title'] ?: null,
            $f['company_name']      ?: null,
            $f['company_address']   ?: null,
            $f['transport_type']    ?: null,
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
            $user_id,
            $user_id,
        ]);

        $new_id = (int)db()->lastInsertId();

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
?>

<div class="page-head">
    <div>
        <h1>🧾 Yeni Beyan</h1>
    </div>
    <a href="beyanlar.php" class="btn btn-ghost">← Beyanlar</a>
</div>

<?php if ($errors): ?>
<div class="flash flash-error">
    <?php foreach ($errors as $e): ?>
    <div><?= h($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" action="beyan_create.php" data-beyan-form>
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<!-- 1. WhatsApp Ham Metni -->
<div class="beyan-section">
    <div class="beyan-section-title">📱 WhatsApp Metni</div>
    <div class="form-group">
        <label class="form-label">Ham Metin</label>
        <textarea name="raw_text" rows="8" class="form-control"
                  placeholder="WhatsApp beyan metnini buraya yapıştırın..."
                  style="font-size:14px;font-family:monospace"><?= h($f['raw_text']) ?></textarea>
    </div>
    <div style="margin-top:8px">
        <button type="button" class="btn btn-secondary"
                data-beyan-parse-btn
                data-base-url="<?= h(base_url()) ?>">🔍 Metni Ayrıştır</button>
    </div>
    <div id="beyanParseStatus" hidden></div>
    <div class="form-group" style="margin-top:12px">
        <label class="form-label">Eşleşmeyen / Dikkat Edilecek Satırlar</label>
        <textarea name="unmatched_text" rows="3" class="form-control"
                  placeholder="Eşleşmeyen veya sonradan kontrol edilecek satırlar..."
                  style="font-size:13px;font-family:monospace"><?= h($f['unmatched_text']) ?></textarea>
    </div>
</div>

<!-- 2. Temel Bilgiler -->
<div class="beyan-section">
    <div class="beyan-section-title">📋 Temel Bilgiler</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Başlık / Beyan Tipi</label>
            <input type="text" name="declaration_title" class="form-control"
                   value="<?= h($f['declaration_title']) ?>" placeholder="YENİ BEYAN" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Parti No</label>
            <input type="text" name="party_no" class="form-control"
                   value="<?= h($f['party_no']) ?>" placeholder="46/22" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Nakliye Türü</label>
            <input type="text" name="transport_type" class="form-control"
                   value="<?= h($f['transport_type']) ?>" placeholder="DENİZYOLU" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Hat / Güzergah</label>
            <input type="text" name="line_type" class="form-control"
                   value="<?= h($f['line_type']) ?>" placeholder="YEŞİL HAT" data-uppercase="tr">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">Şirket Adı</label>
        <input type="text" name="company_name" class="form-control"
               value="<?= h($f['company_name']) ?>"
               placeholder="LIMITED LIABILITY COMPANY FRUTELLA..." data-uppercase="tr">
    </div>
    <div class="form-group">
        <label class="form-label">Şirket Adresi</label>
        <textarea name="company_address" rows="2" class="form-control"
                  placeholder="354000, KRASNODAR KRAI, SOCHI..." data-uppercase="tr"><?= h($f['company_address']) ?></textarea>
    </div>
</div>

<!-- 3. Ürün Bilgileri -->
<div class="beyan-section">
    <div class="beyan-section-title">🍎 Ürün Bilgileri</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Ürün Adı</label>
            <input type="text" name="product_name" class="form-control"
                   value="<?= h($f['product_name']) ?>" placeholder="KAYISI" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Ürün Çeşidi</label>
            <input type="text" name="product_variety" class="form-control"
                   value="<?= h($f['product_variety']) ?>" placeholder="MİKADO" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Palet Adedi</label>
            <input type="text" name="pallet_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['pallet_count']) ?>" placeholder="26">
        </div>
        <div class="form-group">
            <label class="form-label">Brüt KG</label>
            <input type="text" name="gross_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['gross_kg']) ?>"
                   placeholder="24.200 veya 24200">
        </div>
        <div class="form-group">
            <label class="form-label">Net KG</label>
            <input type="text" name="net_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['net_kg']) ?>"
                   placeholder="22.400 veya 22400">
        </div>
        <div class="form-group">
            <label class="form-label">Kasa Adedi</label>
            <input type="text" name="crate_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['crate_count']) ?>"
                   placeholder="2.662 veya 2662">
        </div>
        <div class="form-group">
            <label class="form-label">Kasa Cinsi</label>
            <input type="text" name="crate_type" class="form-control"
                   value="<?= h($f['crate_type']) ?>" placeholder="PLASTİK KASA" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 4. Lojistik / Alıcı -->
<div class="beyan-section">
    <div class="beyan-section-title">🚚 Lojistik / Alıcı</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Çıkış Depo</label>
            <input type="text" name="exit_depot" class="form-control"
                   value="<?= h($f['exit_depot']) ?>" placeholder="KARAMAN DEPO" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Alıcı</label>
            <input type="text" name="buyer_name" class="form-control"
                   value="<?= h($f['buyer_name']) ?>" placeholder="SÜLEYMAN MOSKOVA" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">İlgili Kişi</label>
            <input type="text" name="contact_person" class="form-control"
                   value="<?= h($f['contact_person']) ?>" placeholder="MUHAMMED BEY" data-uppercase="tr">
        </div>
        <div class="form-group">
            <label class="form-label">Marka</label>
            <input type="text" name="brand" class="form-control"
                   value="<?= h($f['brand']) ?>" placeholder="URAS" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 5. Durum / Analiz -->
<div class="beyan-section">
    <div class="beyan-section-title">📊 Durum / Analiz</div>
    <div class="beyan-form-grid">
        <div class="form-group">
            <label class="form-label">Durum</label>
            <select name="status" class="form-control">
                <?php foreach ($statuses as $sk => $sv): ?>
                <option value="<?= h($sk) ?>"<?= $f['status'] === $sk ? ' selected' : '' ?>>
                    <?= h($sv['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Numune Alındı Tarihi</label>
            <input type="datetime-local" name="sample_taken_at" class="form-control"
                   value="<?= h($f['sample_taken_at']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">Analiz Sonuç Tarihi</label>
            <input type="datetime-local" name="analysis_result_at" class="form-control"
                   value="<?= h($f['analysis_result_at']) ?>">
        </div>
    </div>
    <div class="form-group">
        <label class="form-label">Analiz Notu <span class="muted">(Red durumunda zorunlu)</span></label>
        <textarea name="analysis_note" rows="3" class="form-control"
                  placeholder="Analiz sonucu veya açıklama..."><?= h($f['analysis_note']) ?></textarea>
    </div>
</div>

<div style="display:flex;gap:10px;justify-content:flex-end;padding:8px 0 24px">
    <a href="beyanlar.php" class="btn btn-ghost">İptal</a>
    <button type="submit" class="btn btn-primary btn-lg">Kaydet</button>
</div>

</form>

<?php render_footer(); ?>
