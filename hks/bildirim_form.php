<?php
// HKS Bildirim Formu (Yeni / Düzenle)
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.write') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

$repo   = new HksRepository(db());
$pdo    = db();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_new = $id === 0;

$notif  = null;
if (!$is_new) {
    $notif = $repo->getNotification($id);
    if (!$notif) { set_flash('error', 'Bildirim bulunamadı.'); header('Location: bildirimler.php'); exit; }
    if (!in_array($notif['status'], ['draft','ready','failed'], true)) {
        set_flash('error', 'Bu bildirim düzenlenemez (durum: ' . $notif['status'] . ').'); header('Location: bildirim_view.php?id=' . $id); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $data = [
        'notification_type' => trim($_POST['notification_type'] ?? ''),
        'sifat'             => trim($_POST['sifat'] ?? '') ?: null,
        'firma'             => trim($_POST['firma'] ?? ''),
        'urun'              => trim($_POST['urun'] ?? ''),
        'urun_cinsi'        => trim($_POST['urun_cinsi'] ?? '') ?: null,
        'miktar'            => hks_qty($_POST['miktar'] ?? 0),
        'birim'             => trim($_POST['birim'] ?? 'KG'),
        'depo'              => trim($_POST['depo'] ?? '') ?: null,
        'il'                => trim($_POST['il'] ?? '') ?: null,
        'ilce'              => trim($_POST['ilce'] ?? '') ?: null,
        'belde'             => trim($_POST['belde'] ?? '') ?: null,
        'uretici_ad'        => trim($_POST['uretici_ad'] ?? '') ?: null,
        'uretici_tc_vkn'    => trim($_POST['uretici_tc_vkn'] ?? '') ?: null,
        'alici_ad'          => trim($_POST['alici_ad'] ?? '') ?: null,
        'alici_tc_vkn'      => trim($_POST['alici_tc_vkn'] ?? '') ?: null,
        'sevk_tarihi'       => trim($_POST['sevk_tarihi'] ?? '') ?: null,
        'arac_plaka'        => hks_plate(trim($_POST['arac_plaka'] ?? '')),
        'belge_no'          => trim($_POST['belge_no'] ?? '') ?: null,
        'reference_kunye_no'=> trim($_POST['reference_kunye_no'] ?? '') ?: null,
        'direction'         => trim($_POST['direction'] ?? '') ?: null,
    ];

    if ($is_new) {
        $data['created_by'] = $auth_user['id'] ?? null;
        $new_id = $repo->createNotification($data);
        // Doğrulama çalışır: zorunlu alanlar tamamsa ready, eksikse draft + hata listesi
        $repo->updateNotification($new_id, $data);
        $created = $repo->getNotification($new_id);
        audit_log_event('hks_notification_draft_created', 'hks_notifications', $new_id, null, ['firma' => $data['firma'], 'urun' => $data['urun'], 'status' => $created['status'] ?? 'draft']);
        audit_log_event('hks_notification_validated', 'hks_notifications', $new_id, null, ['status' => $created['status'] ?? 'draft']);
        set_flash('success', 'Bildirim taslağı oluşturuldu.');
        header('Location: bildirim_view.php?id=' . $new_id); exit;
    } else {
        $repo->updateNotification($id, $data);
        $updated = $repo->getNotification($id);
        $event = $updated['status'] === 'ready' ? 'hks_notification_ready' : 'hks_notification_draft_created';
        audit_log_event($event, 'hks_notifications', $id, null, ['status' => $updated['status']]);
        audit_log_event('hks_notification_validated', 'hks_notifications', $id, null, ['status' => $updated['status']]);
        set_flash('success', 'Bildirim güncellendi.');
        header('Location: bildirim_view.php?id=' . $id); exit;
    }
}

// Referans verileri dropdown için
$bildirim_turleri = $repo->getReferences('bildirim_turu');
$sifatlar         = $repo->getReferences('sifat');
$iller            = $repo->getReferences('il');
$depolar          = $repo->getReferences('depo');
$birimler         = $repo->getReferences('urun_birim');
$urun_cinsleri    = $repo->getReferences('urun_cins');

$v = $notif ?? [];

$hks_page_title = $is_new ? 'Yeni HKS Bildirimi' : 'Bildirim Düzenle';
render_header($hks_page_title);
render_flash();
?>

<div class="hks-page" style="max-width:900px;margin:0 auto">
<?php
$hks_active_tab = 'bildirim_form.php';
include __DIR__ . '/views/_tabs.php';
?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1><?= $is_new ? '➕ Yeni Bildirim' : '✏️ Bildirim Düzenle' ?></h1>
        <?php if (!$is_new): ?>
        <p class="muted"><?= hks_h($v['local_no'] ?? '') ?></p>
        <?php endif; ?>
    </div>
    <div class="page-head-actions">
        <a href="bildirimler.php" class="btn btn-ghost">← Liste</a>
        <?php if (!$is_new): ?>
        <a href="bildirim_view.php?id=<?= $id ?>" class="btn btn-ghost">Görüntüle</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($bildirim_turleri) || empty($depolar) || empty($iller)): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px 16px;margin-bottom:12px;font-size:.88rem">
    ⚠️ Referans verileri senkronize edilmemiş — dropdownlar boş olabilir.
    <a href="referanslar.php">Referansları senkronize edin.</a>
</div>
<?php endif; ?>

<form method="post" action="bildirim_form.php<?= $is_new ? '' : '?id=' . $id ?>" class="hks-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div class="form-section" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:14px">
        <div class="form-section-title" style="font-weight:700;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:14px">Bildirim Bilgileri</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
            <div class="form-group">
                <label for="notification_type">Bildirim Türü <span style="color:var(--danger)">*</span></label>
                <?php if ($bildirim_turleri): ?>
                <select id="notification_type" name="notification_type" required>
                    <?= hks_ref_options($bildirim_turleri, $v['notification_type'] ?? '') ?>
                </select>
                <?php else: ?>
                <input type="text" id="notification_type" name="notification_type"
                       value="<?= hks_h($v['notification_type'] ?? '') ?>" placeholder="Bildirim türü kodu">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="sifat">Sıfat <span style="color:var(--danger)">*</span></label>
                <?php if ($sifatlar): ?>
                <select id="sifat" name="sifat">
                    <?= hks_ref_options($sifatlar, $v['sifat'] ?? '') ?>
                </select>
                <?php else: ?>
                <input type="text" id="sifat" name="sifat" value="<?= hks_h($v['sifat'] ?? '') ?>" placeholder="Sıfat kodu">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="direction">Yön</label>
                <select id="direction" name="direction">
                    <option value="">— Seçin —</option>
                    <option value="giris" <?= ($v['direction'] ?? '') === 'giris' ? 'selected' : '' ?>>Giriş</option>
                    <option value="cikis" <?= ($v['direction'] ?? '') === 'cikis' ? 'selected' : '' ?>>Çıkış (referans künye zorunlu)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="firma">Firma <span style="color:var(--danger)">*</span></label>
                <input type="text" id="firma" name="firma" required
                       value="<?= hks_h($v['firma'] ?? '') ?>" placeholder="Firma adı">
            </div>

            <div class="form-group">
                <label for="urun">Ürün <span style="color:var(--danger)">*</span></label>
                <input type="text" id="urun" name="urun" required
                       value="<?= hks_h($v['urun'] ?? '') ?>" placeholder="Ürün adı veya kodu">
            </div>

            <div class="form-group">
                <label for="urun_cinsi">Ürün Cinsi <span style="color:var(--danger)">*</span></label>
                <?php if ($urun_cinsleri): ?>
                <select id="urun_cinsi" name="urun_cinsi">
                    <?= hks_ref_options($urun_cinsleri, $v['urun_cinsi'] ?? '') ?>
                </select>
                <?php else: ?>
                <input type="text" id="urun_cinsi" name="urun_cinsi" value="<?= hks_h($v['urun_cinsi'] ?? '') ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="miktar">Miktar <span style="color:var(--danger)">*</span></label>
                <input type="text" id="miktar" name="miktar" required inputmode="decimal"
                       value="<?= $v['miktar'] ? number_format((float)$v['miktar'], 3, '.', '') : '' ?>"
                       placeholder="0.000">
            </div>

            <div class="form-group">
                <label for="birim">Birim <span style="color:var(--danger)">*</span></label>
                <?php if ($birimler): ?>
                <select id="birim" name="birim">
                    <?= hks_ref_options($birimler, $v['birim'] ?? 'KG', false) ?>
                </select>
                <?php else: ?>
                <input type="text" id="birim" name="birim" value="<?= hks_h($v['birim'] ?? 'KG') ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="sevk_tarihi">Sevk Tarihi <span style="color:var(--danger)">*</span></label>
                <input type="date" id="sevk_tarihi" name="sevk_tarihi" required
                       value="<?= hks_h($v['sevk_tarihi'] ?? date('Y-m-d')) ?>">
            </div>
        </div>
    </div>

    <div class="form-section" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:14px">
        <div class="form-section-title" style="font-weight:700;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:14px">Konum ve Depo</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">

            <div class="form-group">
                <label for="depo">Depo <span style="color:var(--danger)">*</span></label>
                <?php if ($depolar): ?>
                <select id="depo" name="depo">
                    <?= hks_ref_options($depolar, $v['depo'] ?? '') ?>
                </select>
                <?php else: ?>
                <input type="text" id="depo" name="depo" value="<?= hks_h($v['depo'] ?? '') ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="il">İl <span style="color:var(--danger)">*</span></label>
                <?php if ($iller): ?>
                <select id="il" name="il">
                    <?= hks_ref_options($iller, $v['il'] ?? '') ?>
                </select>
                <?php else: ?>
                <input type="text" id="il" name="il" value="<?= hks_h($v['il'] ?? '') ?>">
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="ilce">İlçe <span style="color:var(--danger)">*</span></label>
                <input type="text" id="ilce" name="ilce" value="<?= hks_h($v['ilce'] ?? '') ?>" placeholder="İlçe kodu">
            </div>

            <div class="form-group">
                <label for="belde">Belde</label>
                <input type="text" id="belde" name="belde" value="<?= hks_h($v['belde'] ?? '') ?>" placeholder="Belde kodu">
            </div>
        </div>
    </div>

    <div class="form-section" style="background:var(--card);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:14px">
        <div class="form-section-title" style="font-weight:700;border-bottom:1px solid var(--border);padding-bottom:8px;margin-bottom:14px">Üretici / Alıcı / Araç</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">

            <div class="form-group">
                <label for="uretici_ad">Üretici Adı</label>
                <input type="text" id="uretici_ad" name="uretici_ad" value="<?= hks_h($v['uretici_ad'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="uretici_tc_vkn">Üretici TC / VKN</label>
                <input type="text" id="uretici_tc_vkn" name="uretici_tc_vkn" value="<?= hks_h($v['uretici_tc_vkn'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="alici_ad">Alıcı Adı <span style="color:var(--danger)">*</span></label>
                <input type="text" id="alici_ad" name="alici_ad" value="<?= hks_h($v['alici_ad'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="alici_tc_vkn">Alıcı TC / VKN <span style="color:var(--danger)">*</span></label>
                <input type="text" id="alici_tc_vkn" name="alici_tc_vkn" value="<?= hks_h($v['alici_tc_vkn'] ?? '') ?>"
                       inputmode="numeric" placeholder="11 hane TC veya 10 hane VKN">
            </div>

            <div class="form-group">
                <label for="arac_plaka">Araç Plaka <span style="color:var(--danger)">*</span></label>
                <input type="text" id="arac_plaka" name="arac_plaka" required
                       value="<?= hks_h($v['arac_plaka'] ?? '') ?>" placeholder="34ABC12"
                       style="text-transform:uppercase" data-uppercase="tr">
            </div>

            <div class="form-group">
                <label for="belge_no">Belge No</label>
                <input type="text" id="belge_no" name="belge_no" value="<?= hks_h($v['belge_no'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="reference_kunye_no">Referans Künye No</label>
                <input type="text" id="reference_kunye_no" name="reference_kunye_no"
                       value="<?= hks_h($v['reference_kunye_no'] ?? '') ?>"
                       placeholder="Önceki giriş künye numarası">
            </div>
        </div>
    </div>

    <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap;padding:8px 0">
        <a href="bildirimler.php" class="btn btn-ghost btn-lg">İptal</a>
        <button type="submit" class="btn btn-primary btn-lg">
            <?= $is_new ? 'Taslak Oluştur' : 'Güncelle' ?>
        </button>
    </div>
</form>

<p style="margin-top:12px;font-size:.82rem;color:var(--muted)">
    * Tüm zorunlu alanlar doldurulursa bildirim "Gönderime Hazır" durumuna geçer. Eksik alanda taslak olarak kalır.
</p>

</div>

<?php render_footer(); ?>
