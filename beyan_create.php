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
        $hks     = beyan_hks_form_oku($_POST);
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

<form method="post" action="beyan_create.php" class="bf" data-beyan-form>
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

<!-- 1. WhatsApp Ham Metni -->
<div class="beyan-section" data-bf="wa">
    <div class="beyan-section-title" data-ic="mesaj"><span class="bf-emoji" aria-hidden="true">📱</span> WhatsApp Metni<small class="bf-alt">WhatsApp beyan mesaj(lar)ını buraya yapıştırıp "Metni Ayrıştır"a basın. Birden fazla beyan otomatik algılanır.</small></div>
    <div class="bf-wa-satir">
        <div class="form-group" data-ic="metin">
            <label class="form-label">Ham Metin</label>
            <textarea name="raw_text" rows="8" class="form-control bf-ham"
                      placeholder="WhatsApp beyan mesaj(lar)ını buraya yapıştırıp 'Metni Ayrıştır'a basın. Birden fazla beyan otomatik algılanır."><?= h($f['raw_text']) ?></textarea>
        </div>
        <div class="bf-wa-btn">
            <button type="button" class="btn btn-secondary bf-ayristir" data-ic="sihir"
                    data-beyan-parse-btn
                    data-base-url="<?= h(base_url()) ?>">Metni Ayrıştır</button>
        </div>
    </div>
    <div id="beyanParseStatus" hidden></div>
    <!-- Toplu beyan önizleme + "Hepsini Kaydet" (JS doldurur) -->
    <div id="beyanBulkPreview" hidden></div>
    <div class="form-group" data-ic="uyari">
        <label class="form-label">Eşleşmeyen / Dikkat Edilecek Satırlar</label>
        <textarea name="unmatched_text" rows="3" class="form-control"
                  placeholder="Eşleşmeyen veya sonradan kontrol edilecek satırlar..."><?= h($f['unmatched_text']) ?></textarea>
    </div>
</div>

<?php beyan_hks_form_bolumu($f, null); ?>

<!-- 2. Temel Bilgiler -->
<div class="beyan-section" data-bf="temel">
    <div class="beyan-section-title" data-ic="pano"><span class="bf-emoji" aria-hidden="true">📋</span> Temel Bilgiler</div>
    <div class="beyan-form-grid">
        <div class="form-group" data-ic="belge">
            <label class="form-label">Başlık / Beyan Tipi</label>
            <input type="text" name="declaration_title" class="form-control"
                   value="<?= h($f['declaration_title']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="diyez">
            <label class="form-label">Parti No</label>
            <input type="text" name="party_no" class="form-control"
                   value="<?= h($f['party_no']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="tir">
            <label class="form-label">Nakliye Türü</label>
            <input type="text" name="transport_type" class="form-control"
                   value="<?= h($f['transport_type']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="rota">
            <label class="form-label">Hat / Güzergah</label>
            <input type="text" name="line_type" class="form-control"
                   value="<?= h($f['line_type']) ?>" data-uppercase="tr">
        </div>
    </div>
    <div class="form-group" data-ic="bina">
        <label class="form-label">Şirket Adı</label>
        <input type="text" name="company_name" class="form-control"
               value="<?= h($f['company_name']) ?>" data-uppercase="tr">
    </div>
    <div class="form-group" data-ic="konum">
        <label class="form-label">Şirket Adresi</label>
        <textarea name="company_address" rows="2" class="form-control" data-uppercase="tr"><?= h($f['company_address']) ?></textarea>
    </div>
</div>

<!-- 3. Ürün Bilgileri -->
<div class="beyan-section" data-bf="urun">
    <div class="beyan-section-title" data-ic="paket"><span class="bf-emoji" aria-hidden="true">🍎</span> Ürün Bilgileri</div>
    <div class="beyan-form-grid">
        <div class="form-group" data-ic="elma">
            <label class="form-label">Ürün Adı</label>
            <input type="text" name="product_name" class="form-control"
                   value="<?= h($f['product_name']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="etiket">
            <label class="form-label">Ürün Çeşidi</label>
            <input type="text" name="product_variety" class="form-control"
                   value="<?= h($f['product_variety']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="katman">
            <label class="form-label">Palet Adedi</label>
            <input type="text" name="pallet_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['pallet_count']) ?>">
        </div>
        <div class="form-group" data-ic="agirlik">
            <label class="form-label">Brüt KG</label>
            <input type="text" name="gross_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['gross_kg']) ?>">
        </div>
        <div class="form-group" data-ic="terazi">
            <label class="form-label">Net KG</label>
            <input type="text" name="net_kg" class="form-control"
                   inputmode="decimal" value="<?= h($f['net_kg']) ?>">
        </div>
        <div class="form-group" data-ic="kutu">
            <label class="form-label">Kasa Adedi</label>
            <input type="text" name="crate_count" class="form-control"
                   inputmode="numeric" value="<?= h($f['crate_count']) ?>">
        </div>
        <div class="form-group" data-ic="kasa">
            <label class="form-label">Kasa Cinsi</label>
            <input type="text" name="crate_type" class="form-control"
                   value="<?= h($f['crate_type']) ?>" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 4. Lojistik / Alıcı -->
<div class="beyan-section" data-bf="lojistik">
    <div class="beyan-section-title" data-ic="tir"><span class="bf-emoji" aria-hidden="true">🚚</span> Lojistik / Alıcı</div>
    <div class="beyan-form-grid">
        <div class="form-group" data-ic="depo">
            <label class="form-label">Çıkış Depo</label>
            <input type="text" name="exit_depot" class="form-control"
                   value="<?= h($f['exit_depot']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="kisi">
            <label class="form-label">Alıcı</label>
            <input type="text" name="buyer_name" class="form-control"
                   value="<?= h($f['buyer_name']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="rehber">
            <label class="form-label">İlgili Kişi</label>
            <input type="text" name="contact_person" class="form-control"
                   value="<?= h($f['contact_person']) ?>" data-uppercase="tr">
        </div>
        <div class="form-group" data-ic="rozet">
            <label class="form-label">Marka</label>
            <input type="text" name="brand" class="form-control"
                   value="<?= h($f['brand']) ?>" data-uppercase="tr">
        </div>
    </div>
</div>

<!-- 5. Durum / Analiz -->


<div class="beyan-section" data-bf="durum">
    <div class="beyan-section-title" data-ic="grafik"><span class="bf-emoji" aria-hidden="true">📊</span> Durum / Analiz</div>
    <div class="beyan-form-grid">
        <div class="form-group" data-ic="liste">
            <label class="form-label">Durum</label>
            <select name="status" class="form-control">
                <?php foreach ($statuses as $sk => $sv): ?>
                <option value="<?= h($sk) ?>"<?= $f['status'] === $sk ? ' selected' : '' ?>>
                    <?= h($sv['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Yeni beyanda henüz numune/analiz aşaması yok — pasif + gizli
             (kullanıcı isteği). Alanlar Düzenle formunda aynen aktif kalır;
             burada `disabled` olduğu için POST'a da girmezler. -->
        <div class="form-group" data-ic="tup" hidden>
            <label class="form-label">Numune Alındı Tarihi</label>
            <input type="datetime-local" name="sample_taken_at" class="form-control" disabled
                   value="<?= h($f['sample_taken_at']) ?>">
        </div>
        <div class="form-group" data-ic="takvim" hidden>
            <label class="form-label">Analiz Sonuç Tarihi</label>
            <input type="datetime-local" name="analysis_result_at" class="form-control" disabled
                   value="<?= h($f['analysis_result_at']) ?>">
        </div>
    </div>
    <div class="form-group" data-ic="kalem">
        <label class="form-label">Analiz Notu <span class="muted">(Red durumunda zorunlu)</span></label>
        <textarea name="analysis_note" rows="3" class="form-control"
                  placeholder="Analiz sonucu veya açıklama..."><?= h($f['analysis_note']) ?></textarea>
    </div>
</div>

<div id="beyanSingleActions" class="bf-eylem">
    <a href="beyanlar.php" class="btn btn-ghost bf-iptal" data-ic="carpi">İptal</a>
    <button type="submit" class="btn btn-primary btn-lg bf-kaydet" data-ic="kaydet">Kaydet</button>
</div>

</form>

<?php render_footer(); ?>
