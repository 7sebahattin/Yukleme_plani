<?php
// HKS Operasyon — e-Bildirim (adım adım iskelet)
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.write') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';   // çıktıdan önce — guard + $op_* + $op_repo

// ── Düzenleme modu — bildirim_yeni.php?id=123 ──
$op_editable_statuses = ['draft', 'ready', 'checked', 'failed'];
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$notif   = null;
$is_edit = false;
if ($edit_id > 0) {
    $notif = $op_repo->getNotification($edit_id);
    if (!$notif) {
        set_flash('error', 'Bildirim bulunamadı.');
        header('Location: ../bildirim_view.php?id=' . $edit_id); exit;
    }
    if (!in_array($notif['status'], $op_editable_statuses, true)) {
        $msg = match ($notif['status']) {
            'sent'         => 'Gönderilmiş HKS bildirimi düzenlenemez.',
            'send_pending' => 'Gönderim için kilitlenmiş bildirim düzenlenemez.',
            'cancelled'    => 'İptal edilmiş bildirim düzenlenemez.',
            default        => 'Bu bildirim düzenlenemez (durum: ' . $notif['status'] . ').',
        };
        set_flash('error', $msg);
        header('Location: ../bildirim_view.php?id=' . $edit_id); exit;
    }
    $is_edit = true;
}

// ── Taslak kaydet / güncelle (POST) — mevcut repo akışını yeniden kullanır ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    // Düzenleme mi? Gizli id alanından gelir; create için 0.
    $post_id  = (int)($_POST['id'] ?? 0);
    $existing = $post_id > 0 ? $op_repo->getNotification($post_id) : null;
    if ($post_id > 0) {
        if (!$existing) {
            set_flash('error', 'Düzenlenecek bildirim bulunamadı.');
            header('Location: index.php'); exit;
        }
        if (!in_array($existing['status'], $op_editable_statuses, true)) {
            set_flash('error', 'Bu bildirim artık düzenlenemez (durum: ' . $existing['status'] . ').');
            header('Location: ../bildirim_view.php?id=' . $post_id); exit;
        }
    }

    $bildirim_turu = trim($_POST['notification_type'] ?? '');

    $data = [
        'notification_type' => $bildirim_turu,
        'sifat'             => trim($_POST['sifat'] ?? '') ?: null,
        // Yön bildirim türünden türetilir (Satın Alım → giriş, Satış/Sevk → çıkış)
        'direction'         => $bildirim_turu !== '' ? hks_bildirim_turu_direction($bildirim_turu) : null,
        // Bildirimci ünvanı = seçili firma adı (resmi HKS'de read-only gelir)
        'firma'             => trim($_POST['firma'] ?? '') ?: ($op_settings['firma_adi'] ?? ''),
        'urun'              => trim($_POST['urun'] ?? ''),
        'urun_cinsi'        => trim($_POST['urun_cinsi'] ?? '') ?: null,
        'miktar'            => hks_qty($_POST['miktar'] ?? 0),
        'birim'             => trim($_POST['birim'] ?? 'KG') ?: 'KG',
        'depo'              => trim($_POST['depo'] ?? '') ?: (trim((string)($op_settings['default_depo'] ?? '')) ?: trim($_POST['gidecek_yer'] ?? '')) ?: null,
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
        // Adım 1 — bildirimci & karşı taraf ek alanları
        'bildirimci_tc_vkn' => trim($_POST['bildirimci_tc_vkn'] ?? '') ?: ($op_settings['firma_vkn'] ?? null),
        'karsi_sifat'       => trim($_POST['karsi_sifat'] ?? '') ?: null,
        'gsm'               => trim($_POST['gsm'] ?? '') ?: null,
        'dogum_tarihi'      => trim($_POST['dogum_tarihi'] ?? '') ?: null,
        'eposta'            => trim($_POST['eposta'] ?? '') ?: null,
        'yurt_disi'         => (($_POST['yurt_disi'] ?? '') === '1' || ($_POST['yurt_disi'] ?? '') === 'on') ? 1 : 0,
        // Adım 3 — mal & gideceği yer ek alanları
        'malin_niteligi'    => trim($_POST['malin_niteligi'] ?? '') ?: null,
        'malin_turu'        => trim($_POST['malin_turu'] ?? '') ?: null,
        'birim_fiyat'       => trim($_POST['birim_fiyat'] ?? ''),
        'para_birimi'       => trim($_POST['para_birimi'] ?? '') ?: 'TL',
        'analize_gonder'    => (($_POST['analize_gonder'] ?? '') === '1' || ($_POST['analize_gonder'] ?? '') === 'on') ? 1 : 0,
        'gidecek_yer'       => trim($_POST['gidecek_yer'] ?? '') ?: null,
        'ihracat_ulke'      => trim($_POST['ihracat_ulke'] ?? '') ?: null,
        'belge_tipi'        => trim($_POST['belge_tipi'] ?? '') ?: null,
        'gidecek_sahibi_tc' => trim($_POST['gidecek_sahibi_tc'] ?? '') ?: null,
        'gidecek_kayitli_degil' => (($_POST['gidecek_kayitli_degil'] ?? '') === '1' || ($_POST['gidecek_kayitli_degil'] ?? '') === 'on') ? 1 : 0,
        // Sprint MissingFields-01 — koşullu HKS alanları
        'gelen_ulke'        => trim($_POST['gelen_ulke'] ?? '') ?: null,
        'gidecek_yer_il'    => trim($_POST['gidecek_yer_il'] ?? '') ?: null,
        'gidecek_yer_ilce'  => trim($_POST['gidecek_yer_ilce'] ?? '') ?: null,
        'gidecek_yer_belde' => trim($_POST['gidecek_yer_belde'] ?? '') ?: null,
        'created_by'        => $auth_user['id'] ?? null,
    ];

    if ($post_id > 0) {
        // ── GÜNCELLEME — id/local_no/created_* korunur; updateNotification yalnızca
        //    form alanlarını günceller, checked_at/by'ı sıfırlar ve durumu yeniden hesaplar. ──
        $was_checked = ($existing['status'] ?? '') === 'checked';
        $op_repo->updateNotification($post_id, $data);
        $updated = $op_repo->getNotification($post_id);
        audit_log_event('hks_notification_updated', 'hks_notifications', $post_id,
            ['status' => $existing['status'] ?? null],
            ['firma' => $data['firma'], 'urun' => $data['urun'], 'status' => $updated['status'] ?? 'draft']);

        if ($was_checked) {
            set_flash('info', 'Bildirim değiştirildiği için tekrar kontrol edilmelidir. Durum "' . hks_status_label($updated['status'] ?? 'ready') . '" olarak güncellendi.');
        } else {
            set_flash('success', 'Bildirim güncellendi (' . hks_h($updated['local_no'] ?? '') . ').');
        }
        header('Location: ../bildirim_view.php?id=' . $post_id); exit;
    }

    // ── YENİ KAYIT ──
    $new_id = $op_repo->createNotification($data);
    $op_repo->updateNotification($new_id, $data);   // doğrulama → draft/ready
    $created = $op_repo->getNotification($new_id);
    audit_log_event('hks_notification_draft_created', 'hks_notifications', $new_id, null,
        ['firma' => $data['firma'], 'urun' => $data['urun'], 'status' => $created['status'] ?? 'draft']);

    // İsteğe bağlı "kontrol edildi" işaretleme
    if (($_POST['mark_checked'] ?? '') === '1' && (($created['status'] ?? '') === 'ready')) {
        if ($op_repo->markChecked($new_id, $auth_user['id'] ?? null)) {
            audit_log_event('hks_notification_checked', 'hks_notifications', $new_id, null, ['status' => 'checked']);
        }
    }

    set_flash('success', 'Bildirim taslağı kaydedildi (' . hks_h($created['local_no'] ?? '') . ').');
    header('Location: ../bildirim_view.php?id=' . $new_id); exit;
}

// Referans verileri (dropdown)
$bildirim_turleri = $op_repo->getReferences('bildirim_turu');
$sifatlar         = $op_repo->getReferences('sifat');
$iller            = $op_repo->getReferences('il');

// Sıfat etiketleri sayısal/boş ise (senkron eşlemesi tutmamışsa) resmi sabit listeyi kullan.
$sifat_use_static = hks_refs_labels_numeric($sifatlar);
$sifat_opts = '<option value="">— Seçin —</option>';
if ($sifat_use_static) {
    foreach (hks_sifat_list() as $s) {
        $sifat_opts .= '<option value="' . hks_h($s['code']) . '">' . hks_h($s['name']) . '</option>';
    }
} else {
    $sifat_opts .= hks_ref_options($sifatlar, '', false);
}
$depolar          = $op_repo->getReferences('depo');
$birimler         = $op_repo->getReferences('urun_birim');
$urun_cinsleri    = $op_repo->getReferences('urun_cins');
$urunler          = $op_repo->getReferences('urun');
$refs_missing     = empty($urunler) || empty($iller);

// Bildirim Türü — sabit resmi liste (Satış / Satın Alım / Sevk Etme)
$bildirim_turu_opts = '<option value="">— Seçin —</option>';
foreach (hks_bildirim_turu_list() as $bt) {
    $bildirim_turu_opts .= '<option value="' . hks_h($bt) . '">' . hks_h($bt) . '</option>';
}

// Karşı taraf (Kimden/Kime) sıfatı — Bildirim Türüne göre değişen liste
$karsi_sifat_map = hks_karsi_taraf_sifat_map();

// Bildirimciye ait bilgiler — seçili firmadan gelir
$bildirimci_vkn   = trim((string)($op_settings['firma_vkn'] ?? ''));
$bildirimci_unvan = trim((string)($op_settings['firma_adi'] ?? ''));

// İşyeri Türü ve Sıralama Türü — resmi sabit listeler
$isyeri_turu_opts = '<option value="">Seçiniz</option>';
foreach (hks_isyeri_turu_list() as $it) {
    $isyeri_turu_opts .= '<option value="' . hks_h($it) . '">' . hks_h($it) . '</option>';
}
$siralama_opts = '';
foreach (hks_siralama_turu_list() as $sl) {
    $siralama_opts .= '<option value="' . hks_h($sl) . '">' . hks_h($sl) . '</option>';
}

// Adım 3 sabit listeleri
$malin_turu_opts = '';
foreach (hks_malin_turu_list() as $mt) {
    $malin_turu_opts .= '<option value="' . hks_h($mt) . '">' . hks_h($mt) . '</option>';
}
$belge_tipi_opts = '<option value="">Seçiniz</option>';
foreach (hks_belge_tipi_list() as $bt2) {
    $belge_tipi_opts .= '<option value="' . hks_h($bt2) . '">' . hks_h($bt2) . '</option>';
}
$gidecek_yer_opts = '<option value="">Seçiniz</option>';
foreach (hks_gidecek_yer_list() as $gy) {
    $gidecek_yer_opts .= '<option value="' . hks_h($gy) . '">' . hks_h($gy) . '</option>';
}
$para_birimi_opts = '';
foreach (hks_para_birimi_list() as $pb) {
    $para_birimi_opts .= '<option value="' . hks_h($pb) . '">' . hks_h($pb) . '</option>';
}
// İhracat ülkeleri — önce HKS 'ulke' referansı, yoksa statik liste
$ulke_refs = $op_repo->getReferences('ulke');
$ulke_opts = '<option value="">Seçiniz</option>';
if ($ulke_refs && !hks_refs_labels_numeric($ulke_refs)) {
    $ulke_opts .= hks_ref_options($ulke_refs, '', false);
} else {
    foreach (hks_ulke_list() as $u) {
        $ulke_opts .= '<option value="' . hks_h($u) . '">' . hks_h($u) . '</option>';
    }
}

// Düzenleme modunda form alanlarına geri basılacak değerler (JS ile uygulanır).
$edit_values = null;
if ($is_edit && $notif) {
    $edit_values = [
        'bildirimci_tc_vkn' => (string)($notif['bildirimci_tc_vkn'] ?? '') ?: (string)($op_settings['firma_vkn'] ?? ''),
        'sifat'             => (string)($notif['sifat'] ?? ''),
        'firma'             => (string)($notif['firma'] ?? '') ?: (string)($op_settings['firma_adi'] ?? ''),
        'notification_type' => (string)($notif['notification_type'] ?? ''),
        'alici_tc_vkn'      => (string)($notif['alici_tc_vkn'] ?? ''),
        'alici_ad'          => (string)($notif['alici_ad'] ?? ''),
        'gsm'               => (string)($notif['gsm'] ?? ''),
        'dogum_tarihi'      => (string)($notif['dogum_tarihi'] ?? ''),
        'eposta'            => (string)($notif['eposta'] ?? ''),
        'karsi_sifat'       => (string)($notif['karsi_sifat'] ?? ''),
        'yurt_disi'         => (int)($notif['yurt_disi'] ?? 0),
        'reference_kunye_no'=> (string)($notif['reference_kunye_no'] ?? ''),
        'malin_niteligi'    => (string)($notif['malin_niteligi'] ?? ''),
        'uretici_tc_vkn'    => (string)($notif['uretici_tc_vkn'] ?? ''),
        'uretici_ad'        => (string)($notif['uretici_ad'] ?? ''),
        'il'                => (string)($notif['il'] ?? ''),
        'ilce'              => (string)($notif['ilce'] ?? ''),
        'belde'             => (string)($notif['belde'] ?? ''),
        'urun'              => (string)($notif['urun'] ?? ''),
        'malin_turu'        => (string)($notif['malin_turu'] ?? ''),
        'urun_cinsi'        => (string)($notif['urun_cinsi'] ?? ''),
        'analize_gonder'    => (int)($notif['analize_gonder'] ?? 0),
        'birim'             => (string)($notif['birim'] ?? 'KG'),
        'miktar'            => ($notif['miktar'] ?? '') !== '' && (float)$notif['miktar'] > 0 ? rtrim(rtrim(number_format((float)$notif['miktar'], 3, '.', ''), '0'), '.') : '',
        'birim_fiyat'       => (float)($notif['birim_fiyat'] ?? 0) > 0 ? rtrim(rtrim(number_format((float)$notif['birim_fiyat'], 2, '.', ''), '0'), '.') : '',
        'para_birimi'       => (string)($notif['para_birimi'] ?? 'TL') ?: 'TL',
        'gidecek_sahibi_tc' => (string)($notif['gidecek_sahibi_tc'] ?? ''),
        'gidecek_kayitli_degil' => (int)($notif['gidecek_kayitli_degil'] ?? 0),
        'gidecek_yer'       => (string)($notif['gidecek_yer'] ?? ''),
        'ihracat_ulke'      => (string)($notif['ihracat_ulke'] ?? ''),
        'gelen_ulke'        => (string)($notif['gelen_ulke'] ?? ''),
        'gidecek_yer_il'    => (string)($notif['gidecek_yer_il'] ?? ''),
        'gidecek_yer_ilce'  => (string)($notif['gidecek_yer_ilce'] ?? ''),
        'gidecek_yer_belde' => (string)($notif['gidecek_yer_belde'] ?? ''),
        'arac_plaka'        => (string)($notif['arac_plaka'] ?? ''),
        'belge_no'          => (string)($notif['belge_no'] ?? ''),
        'belge_tipi'        => (string)($notif['belge_tipi'] ?? ''),
        'sevk_tarihi'       => (string)($notif['sevk_tarihi'] ?? ''),
    ];
}

$op_page_title  = $is_edit ? 'e-Bildirim Düzenle' : 'Yeni e-Bildirim';
$op_active_tab  = 'bildirimci';
$op_active_menu = 'bildirim_yeni.php';
include __DIR__ . '/views/_layout_start.php';
?>

<div class="hks-op-steps" id="opSteps">
    <div class="hks-op-step active" data-s="1"><span class="n">1</span> Bildirim Bilgileri</div>
    <div class="hks-op-step" data-s="2"><span class="n">2</span> Referans Künye</div>
    <div class="hks-op-step" data-s="3"><span class="n">3</span> Mal &amp; Gideceği Yer</div>
</div>

<?php if ($refs_missing): ?>
<div class="hks-op-note warn">
    ⚠️ Referans listeleri eksik — bazı seçenekler boş görünebilir. <strong>HKS Teknik → Referanslar</strong> bölümünden senkronize edebilirsiniz.
</div>
<?php endif; ?>
<?php if ($is_edit): ?>
<div class="hks-op-note info">
    ✏️ <strong><?= hks_h($notif['local_no'] ?? '') ?></strong> düzenleniyor (durum: <?= hks_h(hks_status_label($notif['status'] ?? '')) ?>).
    <?php if (($notif['status'] ?? '') === 'checked'): ?>
    Değişiklik kaydedilirse bildirim <strong>tekrar kontrol edilmek üzere</strong> "Gönderime Hazır"a döner.
    <?php endif; ?>
</div>
<?php else: ?>
<div class="hks-op-note info">
    ℹ️ Bu ekran bildirim <strong>taslağı</strong> oluşturur. HKS'ye canlı gönderim bu sprintte kapalıdır; gönderim ayrı bir adımda yapılır.
</div>
<?php endif; ?>

<form method="post" id="opForm" autocomplete="off" action="bildirim_yeni.php<?= $is_edit ? '?id=' . (int)$edit_id : '' ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= (int)$edit_id ?>"><?php endif; ?>
    <input type="hidden" name="mark_checked" id="markChecked" value="0">

    <!-- ADIM 1 — Bildirimci / Genel / Kimden-Kime -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="1">
        <legend>Adım 1 — Bildirim Bilgileri</legend>

        <!-- Bildirimciye Ait Bilgiler (seçili firmadan gelir) -->
        <p class="hks-op-section-title">Bildirimciye Ait Bilgiler</p>
        <?php if ($bildirimci_vkn === ''): ?>
        <div class="hks-op-note warn">⚠️ Seçili firmanın <strong>TC/VKN</strong> bilgisi tanımlı değil. <strong>HKS Teknik → Ayarlar</strong> bölümünden ekleyin.</div>
        <?php endif; ?>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>T.C. Kimlik / Vergi No</label>
                <input type="text" name="bildirimci_tc_vkn" value="<?= hks_h($bildirimci_vkn) ?>" readonly style="background:var(--bg)">
            </div>
            <div class="hks-op-field">
                <label>Sıfat <span style="color:var(--danger)">*</span></label>
                <select name="sifat"><?= $sifat_opts ?></select>
            </div>
            <div class="hks-op-field">
                <label>Adı Soyadı / Ünvanı <span style="color:var(--danger)">*</span></label>
                <input type="text" name="firma" value="<?= hks_h($bildirimci_unvan) ?>" readonly style="background:var(--bg)">
            </div>
        </div>

        <!-- Bildirim Genel Bilgileri -->
        <p class="hks-op-section-title" style="margin-top:8px">Bildirim Genel Bilgileri</p>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Bildirim Türü <span style="color:var(--danger)">*</span></label>
                <select name="notification_type" id="bildirimTuru"><?= $bildirim_turu_opts ?></select>
            </div>
        </div>

        <!-- Kimden veya Kime Bilgileri (karşı taraf) -->
        <p class="hks-op-section-title" style="margin-top:8px">Kimden veya Kime Bilgileri</p>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:.88rem">
            <input type="checkbox" name="yurt_disi" value="1" style="width:auto"> Yurt Dışı
        </label>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>T.C. Kimlik / Vergi No <span style="color:var(--danger)">*</span></label>
                <input type="text" name="alici_tc_vkn" maxlength="11" placeholder="Karşı taraf TC/VKN">
            </div>
            <div class="hks-op-field">
                <label>Adı Soyadı / Ünvanı <span style="color:var(--danger)">*</span></label>
                <input type="text" name="alici_ad" placeholder="Karşı taraf ad / ünvan">
            </div>
            <div class="hks-op-field">
                <label>GSM Numarası</label>
                <input type="text" name="gsm" inputmode="tel" placeholder="5xx xxx xx xx">
            </div>
            <div class="hks-op-field">
                <label>Doğum Tarihi</label>
                <input type="date" name="dogum_tarihi">
            </div>
            <div class="hks-op-field">
                <label>E-postası</label>
                <input type="email" name="eposta" placeholder="ornek@firma.com">
            </div>
            <div class="hks-op-field">
                <label>Sıfatı</label>
                <select name="karsi_sifat" id="karsiSifat"><option value="">Seçiniz</option></select>
            </div>
        </div>
    </fieldset>

    <!-- ADIM 2 — Referans Künye -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="2" style="display:none">
        <legend>Adım 2 — Referans Künye</legend>
        <div class="hks-op-note info">ℹ️ Daha önce yapılmış bildirim işlemine ilişkin künye varsa yazınız.</div>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Künye No</label>
                <input type="text" name="reference_kunye_no" id="refKunyeNo" placeholder="Künye numarası">
            </div>
            <div class="hks-op-field">
                <label>Referans Künyede kullanılan Ürün</label>
                <?php if ($urunler): ?>
                <select id="refUrunId"><option value="">Seçiniz</option><?= hks_ref_options($urunler, '', false) ?></select>
                <?php else: ?>
                <input type="text" id="refUrunId" placeholder="Ürün">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>İşyeri Türü</label>
                <select id="refIsyeriTuru"><?= $isyeri_turu_opts ?></select>
            </div>
            <div class="hks-op-field">
                <label>Sıralama Türü</label>
                <select id="refSiralama"><?= $siralama_opts ?></select>
            </div>
        </div>
        <button type="button" class="hks-op-btn" id="btnRefKunye" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Künye Sorgula</button>
        <?php if (!$op_queries_enabled): ?><small class="muted" style="margin-left:8px">HKS bağlantısı yapılandırılmadığı için sorgu kapalı.</small><?php endif; ?>
        <div id="refKunyeResult" class="hks-op-result" style="display:none"></div>
        <div id="refKunyeTable" style="margin-top:12px"></div>
        <div id="refKunyeSelected" class="hks-op-note" style="display:none;margin-top:10px"></div>
    </fieldset>

    <!-- ADIM 3 — Mal / Miktar-Fiyat / Gideceği Yer -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="3" style="display:none">
        <legend>Adım 3 — Mal &amp; Gideceği Yer</legend>

        <!-- Mala İlişkin Bilgiler -->
        <p class="hks-op-section-title">Mala İlişkin Bilgiler</p>
        <div class="hks-op-field">
            <label>Malın Niteliği</label>
            <div style="display:flex;gap:18px;flex-wrap:wrap;padding-top:4px">
            <?php foreach (hks_malin_niteligi_list() as $i => $mn): ?>
                <label style="display:flex;align-items:center;gap:6px;font-weight:400">
                    <input type="radio" name="malin_niteligi" value="<?= hks_h($mn) ?>" class="malinNiteligiRadio" style="width:auto" <?= (!$is_edit && $i === 0) ? 'checked' : '' ?>> <?= hks_h($mn) ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>
        <!-- Gelen Ülke — yalnız Malın Niteliği "İthal" iken görünür/zorunlu (GelenUlkeId) -->
        <div class="hks-op-row" id="gelenUlkeWrap" style="display:none">
            <div class="hks-op-field">
                <label>Malın Geldiği (İthal) Ülke <span style="color:var(--danger)">*</span></label>
                <select name="gelen_ulke" id="gelenUlke"><?= $ulke_opts ?></select>
            </div>
        </div>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Üretici T.C. Kimlik / Vergi No</label>
                <input type="text" name="uretici_tc_vkn" maxlength="11" placeholder="Üretici TC/VKN">
            </div>
            <div class="hks-op-field">
                <label>Üretici Adı Soyadı / Ticari Unvanı</label>
                <input type="text" name="uretici_ad" placeholder="Üretici ad / ünvan">
            </div>
        </div>
        <p class="muted" style="font-size:.8rem;margin:6px 0 4px">Üretildiği veya Girdiği Gümrük Kapısının Bulunduğu Yer</p>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>İl <span style="color:var(--danger)">*</span></label>
                <?php if ($iller): ?>
                <select name="il"><option value="">Seçiniz</option><?= hks_ref_options($iller, '', false) ?></select>
                <?php else: ?>
                <input type="text" name="il" placeholder="İl">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>İlçe <span style="color:var(--danger)">*</span></label>
                <input type="text" name="ilce" placeholder="İlçe">
            </div>
            <div class="hks-op-field">
                <label>Belde</label>
                <input type="text" name="belde" placeholder="Belde">
            </div>
            <div class="hks-op-field">
                <label>Malın Adı <span style="color:var(--danger)">*</span></label>
                <?php if ($urunler): ?>
                <select name="urun" id="malAdi"><option value="">Seçiniz</option><?= hks_ref_options($urunler, '', false) ?></select>
                <?php else: ?>
                <input type="text" name="urun" id="malAdi" placeholder="Malın adı">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Malın Türü</label>
                <select name="malin_turu"><?= $malin_turu_opts ?></select>
            </div>
            <div class="hks-op-field">
                <label>Malın Cinsi</label>
                <?php if ($urun_cinsleri): ?>
                <select name="urun_cinsi"><option value="">Seçiniz</option><?= hks_ref_options($urun_cinsleri, '', false) ?></select>
                <?php else: ?>
                <input type="text" name="urun_cinsi" placeholder="Malın cinsi">
                <?php endif; ?>
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-top:6px;font-size:.88rem">
            <input type="checkbox" name="analize_gonder" value="1" style="width:auto"> Analize Gönder
        </label>

        <!-- Miktar ve Fiyat -->
        <p class="hks-op-section-title" style="margin-top:10px">Miktar ve Fiyat</p>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Kalan Miktar</label>
                <input type="text" id="kalanMiktar" readonly placeholder="—" style="background:var(--bg)">
            </div>
            <div class="hks-op-field">
                <label>Mal Miktarı <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:6px">
                    <?php if ($birimler): ?>
                    <select name="birim" style="flex:0 0 90px"><?= hks_ref_options($birimler, 'KG', false) ?></select>
                    <?php else: ?>
                    <select name="birim" style="flex:0 0 90px"><option value="KG">Kg</option><option value="TON">Ton</option><option value="ADET">Adet</option></select>
                    <?php endif; ?>
                    <input type="text" name="miktar" inputmode="decimal" placeholder="0" style="flex:1">
                </div>
            </div>
            <div class="hks-op-field">
                <label>Birim Fiyatı</label>
                <div style="display:flex;gap:6px">
                    <input type="text" name="birim_fiyat" inputmode="decimal" placeholder="0" style="flex:1">
                    <select name="para_birimi" style="flex:0 0 80px"><?= $para_birimi_opts ?></select>
                </div>
            </div>
        </div>

        <!-- Malın Gideceği / Tüketime Sunulduğu Yere Ait Bilgiler -->
        <p class="hks-op-section-title" style="margin-top:10px">Malın Gideceği / Tüketime Sunulduğu Yere Ait Bilgiler</p>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Gideceği Yer Sahibi T.C. Kimlik / Vergi No</label>
                <input type="text" name="gidecek_sahibi_tc" maxlength="11" placeholder="Gideceği yer sahibi TC/VKN">
            </div>
            <div class="hks-op-field" style="align-self:end">
                <label style="display:flex;align-items:center;gap:8px;font-weight:400">
                    <input type="checkbox" name="gidecek_kayitli_degil" value="1" style="width:auto"> Gidecek Yer Kayıtlı Değil
                </label>
            </div>
            <div class="hks-op-field">
                <label>Gideceği Yer</label>
                <select name="gidecek_yer" id="gidecekYer"><?= $gidecek_yer_opts ?></select>
            </div>
            <div class="hks-op-field" id="ihracatUlkeWrap" style="display:none">
                <label>İhracat Yapılan Ülkeler</label>
                <select name="ihracat_ulke" id="ihracatUlke"><?= $ulke_opts ?></select>
            </div>
            <div class="hks-op-field">
                <label>Araç Plaka <span style="color:var(--danger)">*</span></label>
                <input type="text" name="arac_plaka" placeholder="34ABC123">
            </div>
            <div class="hks-op-field">
                <label>Belge No / Tipi</label>
                <div style="display:flex;gap:6px">
                    <input type="text" name="belge_no" placeholder="Belge no" style="flex:1">
                    <select name="belge_tipi" style="flex:0 0 130px"><?= $belge_tipi_opts ?></select>
                </div>
            </div>
            <div class="hks-op-field">
                <label>Sevk Tarihi <span style="color:var(--danger)">*</span></label>
                <input type="date" name="sevk_tarihi" value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <!-- Kayıtlı olmayan yurt içi gidecek yer — il/ilçe/belde (GidecekYerIlId/IlceId/BeldeId) -->
        <div class="hks-op-row" id="gidecekYerKonumWrap" style="display:none">
            <div class="hks-op-note info" style="grid-column:1/-1;margin-bottom:4px">
                ℹ️ Gidecek yer kayıtlı olmadığı için malın gideceği <strong>il / ilçe / belde</strong> bilgisi gereklidir.
            </div>
            <div class="hks-op-field">
                <label>Gidecek Yer İl <span style="color:var(--danger)">*</span></label>
                <?php if ($iller): ?>
                <select name="gidecek_yer_il" id="gidecekYerIl"><option value="">Seçiniz</option><?= hks_ref_options($iller, '', false) ?></select>
                <?php else: ?>
                <input type="text" name="gidecek_yer_il" id="gidecekYerIl" placeholder="Gidecek yer ili">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Gidecek Yer İlçe <span style="color:var(--danger)">*</span></label>
                <input type="text" name="gidecek_yer_ilce" id="gidecekYerIlce" placeholder="Gidecek yer ilçesi">
            </div>
            <div class="hks-op-field">
                <label>Gidecek Yer Belde</label>
                <input type="text" name="gidecek_yer_belde" id="gidecekYerBelde" placeholder="Gidecek yer beldesi">
            </div>
        </div>

        <!-- Kontrol & Kaydet -->
        <p class="hks-op-section-title" style="margin-top:10px">Kontrol &amp; Taslak Kaydet</p>
        <div class="hks-op-note info">
            Bilgileri kontrol edin. Zorunlu (*) alanlar eksikse kayıt <strong>taslak</strong> olarak kalır; tümü tamamsa <strong>gönderime hazır</strong> olur.
        </div>
        <div id="opSummary" style="font-size:.88rem"></div>
        <?php if (!$is_edit): ?>
        <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-size:.9rem">
            <input type="checkbox" id="cbChecked" style="width:auto"> Eksiksizse "Kontrol Edildi" olarak işaretle
        </label>
        <?php endif; ?>
    </fieldset>

    <!-- Navigasyon -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
        <button type="button" class="hks-op-btn hks-op-btn-ghost" id="btnPrev" style="display:none">← Geri</button>
        <button type="button" class="hks-op-btn" id="btnNext">İleri →</button>
        <button type="submit" class="hks-op-btn" id="btnSave" style="display:none"><?= $is_edit ? '💾 Değişiklikleri Kaydet' : '💾 Taslak Kaydet' ?></button>
        <a href="<?= $is_edit ? '../bildirim_view.php?id=' . (int)$edit_id : 'index.php' ?>" class="hks-op-btn hks-op-btn-ghost">Vazgeç</a>
    </div>
</form>

<script>
(function() {
    var cur = 1, max = 3;
    var panes = document.querySelectorAll('.op-pane');
    var steps = document.querySelectorAll('#opSteps .hks-op-step');
    var btnPrev = document.getElementById('btnPrev');
    var btnNext = document.getElementById('btnNext');
    var btnSave = document.getElementById('btnSave');
    var form    = document.getElementById('opForm');

    function show(n) {
        cur = Math.max(1, Math.min(max, n));
        panes.forEach(function(p){ p.style.display = (+p.dataset.pane === cur) ? '' : 'none'; });
        steps.forEach(function(s){ s.classList.toggle('active', +s.dataset.s <= cur); });
        btnPrev.style.display = cur > 1 ? '' : 'none';
        btnNext.style.display = cur < max ? '' : 'none';
        btnSave.style.display = cur === max ? '' : 'none';
        if (cur === max) buildSummary();
        window.scrollTo({top:0, behavior:'smooth'});
    }
    function val(name){ var el = form.querySelector('[name="'+name+'"]'); return el ? el.value.trim() : ''; }
    function buildSummary() {
        var rows = [
            ['Bildirimci', val('firma')], ['Bildirimci TC/VKN', val('bildirimci_tc_vkn')], ['Sıfat', val('sifat')],
            ['Bildirim Türü', val('notification_type')],
            ['Karşı Taraf', val('alici_ad')], ['Karşı Taraf TC/VKN', val('alici_tc_vkn')], ['Karşı Taraf Sıfatı', val('karsi_sifat')],
            ['Referans Künye', val('reference_kunye_no')],
            ['Mal', val('urun')], ['Miktar', val('miktar') + ' ' + val('birim')],
            ['Üretici', val('uretici_ad')], ['Depo/Şube', val('depo')],
            ['İl / İlçe', (val('il') + ' / ' + val('ilce')).replace(/^ \/ | \/ $/,'')],
            ['Araç Plaka', val('arac_plaka')], ['Sevk Tarihi', val('sevk_tarihi')]
        ];
        var html = '<table class="hks-op-table"><tbody>';
        var missing = [];
        var req = {'Bildirimci':1,'Sıfat':1,'Bildirim Türü':1,'Karşı Taraf':1,'Karşı Taraf TC/VKN':1,'Mal':1,'Araç Plaka':1,'Sevk Tarihi':1};
        rows.forEach(function(r){
            var empty = !r[1] || r[1] === ' ';
            if (req[r[0]] && empty) missing.push(r[0]);
            html += '<tr><th style="width:42%">'+r[0]+'</th><td>'+(empty?'<span class="muted">—</span>':r[1].replace(/</g,'&lt;'))+'</td></tr>';
        });
        html += '</tbody></table>';
        if (missing.length) {
            html = '<div class="hks-op-note warn">Eksik zorunlu alan: '+missing.join(', ')+'</div>' + html;
        }
        document.getElementById('opSummary').innerHTML = html;
    }
    btnNext.addEventListener('click', function(){ show(cur+1); });
    btnPrev.addEventListener('click', function(){ show(cur-1); });
    var cbChecked = document.getElementById('cbChecked');
    if (cbChecked) {
        cbChecked.addEventListener('change', function(){
            document.getElementById('markChecked').value = this.checked ? '1' : '0';
        });
    }

    // ── Referans Künye sorgu + sonuç tablosu (resmi HKS Adım 2 yapısı) ──
    var refRows = [];
    function gf(obj, names){
        for (var i=0;i<names.length;i++){ for (var k in obj){ if (k.toLowerCase()===names[i].toLowerCase() && obj[k]!=null && obj[k]!=='') return obj[k]; } }
        return '';
    }
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    function fmtNum(v){ var n=parseFloat(String(v).replace(',','.')); return isNaN(n)?esc(v):n.toLocaleString('tr-TR'); }
    function renderRefTable(){
        var box = document.getElementById('refKunyeTable');
        var siralama = document.getElementById('refSiralama').value;
        var isyeri   = document.getElementById('refIsyeriTuru').value;
        var rows = refRows.slice();
        if (isyeri){
            rows = rows.filter(function(o){ var v=gf(o,['IsyeriTuru','GidecekYerTuru','IsYeriTuru']); return !v || String(v).toLowerCase().indexOf(isyeri.toLowerCase())!==-1; });
        }
        rows.sort(function(a,b){
            var da=new Date(gf(a,['BildirimTarihi','Tarih','BildirimTarihiSaati','OlusturmaTarihi'])||0);
            var db=new Date(gf(b,['BildirimTarihi','Tarih','BildirimTarihiSaati','OlusturmaTarihi'])||0);
            return siralama==='Tarihe Göre Artan' ? da-db : db-da;
        });
        if (!rows.length){ box.innerHTML='<div class="hks-op-note empty">Aradığınız kriterlere uygun sonuç bulunamamıştır.</div>'; return; }
        var html='<div class="table-wrap"><table class="hks-op-table"><thead><tr>'+
            '<th>Seç</th><th>Künye No</th><th>Bildirim Tarihi</th><th>Yöntem</th>'+
            '<th style="text-align:right">Miktar</th><th style="text-align:right">Kalan</th><th style="text-align:right">Birim Fiyat</th>'+
            '<th>Malın Adı</th><th>Cinsi</th><th>Türü</th><th>Malın Sahibi</th><th>Bildirimci</th>'+
            '<th>Plaka / Belge</th><th>Gidecek Yer Türü</th><th>Gidecek Yer İl/İlçe</th></tr></thead><tbody>';
        rows.forEach(function(o,i){
            var kn=gf(o,['KunyeNo','ReferansKunyeNo','kunyeNo']);
            var birim=gf(o,['BirimAdi','Birim','birim'])||'KG';
            var il=gf(o,['GidecekYerIl','GidecekIl','Il']), ilce=gf(o,['GidecekYerIlce','GidecekIlce','Ilce']);
            var plaka=gf(o,['AracPlaka','Plaka']), belge=gf(o,['BelgeNo']);
            html+='<tr>'+
                '<td><button type="button" class="hks-op-btn" style="padding:4px 10px;font-size:.78rem" data-pick="'+i+'">Seç</button></td>'+
                '<td style="font-weight:600">'+esc(kn)+'</td>'+
                '<td style="white-space:nowrap">'+esc(gf(o,['BildirimTarihi','Tarih','BildirimTarihiSaati']))+'</td>'+
                '<td>'+esc(gf(o,['BildirimYontemi','BildirimYontem'])||'E-Bildirim')+'</td>'+
                '<td style="text-align:right;white-space:nowrap">'+fmtNum(gf(o,['Miktar','MalMiktar','MalinMiktari']))+' '+esc(birim)+'</td>'+
                '<td style="text-align:right;white-space:nowrap">'+fmtNum(gf(o,['KalanMiktar','Kalan']))+' '+esc(birim)+'</td>'+
                '<td style="text-align:right;white-space:nowrap">'+esc(gf(o,['BirimFiyat','MalinBirimFiyati','Fiyat']))+'</td>'+
                '<td>'+esc(gf(o,['UrunAdi','MalinAdi','Urun']))+'</td>'+
                '<td>'+esc(gf(o,['UrunCinsi','MalinCinsi','Cins']))+'</td>'+
                '<td>'+esc(gf(o,['UrunTuru','MalinTuru','Tur']))+'</td>'+
                '<td>'+esc(gf(o,['MalinSahibi','MalSahibi','Sahibi']))+'</td>'+
                '<td>'+esc(gf(o,['Bildirimci','BildirimciAdi','BildirimciUnvan']))+'</td>'+
                '<td>'+esc(plaka)+(belge?(' / '+esc(belge)):'')+'</td>'+
                '<td>'+esc(gf(o,['GidecekYerTuru','GidecekYerTipi']))+'</td>'+
                '<td>'+esc((il+' / '+ilce).replace(/^ \/ | \/ $/,''))+'</td></tr>';
        });
        html+='</tbody></table></div>';
        box.innerHTML=html;
        box.querySelectorAll('[data-pick]').forEach(function(btn){
            btn.addEventListener('click', function(){
                var o=rows[+btn.dataset.pick];
                var kn=gf(o,['KunyeNo','ReferansKunyeNo','kunyeNo']);
                document.getElementById('refKunyeNo').value=kn;
                // Malın Adı'nı Adım 3'e taşı (input ya da select)
                var urunAdi=gf(o,['UrunAdi','MalinAdi','Urun']);
                var malEl=document.getElementById('malAdi');
                if (malEl && urunAdi) {
                    if (malEl.tagName==='SELECT') {
                        for (var oi=0; oi<malEl.options.length; oi++){ if (malEl.options[oi].text.toLowerCase()===String(urunAdi).toLowerCase()){ malEl.selectedIndex=oi; break; } }
                    } else if (!malEl.value.trim()) { malEl.value=urunAdi; }
                }
                // Kalan miktarı göster
                var kalan=gf(o,['KalanMiktar','Kalan']);
                var birim=gf(o,['BirimAdi','Birim','birim'])||'KG';
                var kEl=document.getElementById('kalanMiktar');
                if (kEl) kEl.value = kalan!=='' ? (fmtNum(kalan)+' '+birim) : '';
                var sel=document.getElementById('refKunyeSelected');
                sel.style.display='block'; sel.className='hks-op-note info';
                sel.innerHTML='✅ Referans künye seçildi: <strong>'+esc(kn)+'</strong>';
            });
        });
    }
    ['refSiralama','refIsyeriTuru'].forEach(function(id){
        var el=document.getElementById(id);
        if (el) el.addEventListener('change', function(){ if (refRows.length) renderRefTable(); });
    });

    // Künye Sorgula (AJAX) — mevcut query_referans_kunye aksiyonu
    var btnRef = document.getElementById('btnRefKunye');
    if (btnRef) {
        btnRef.addEventListener('click', function(){
            var urunEl = document.getElementById('refUrunId');
            var urunId = urunEl ? urunEl.value.trim() : '';
            var res = document.getElementById('refKunyeResult');
            document.getElementById('refKunyeTable').innerHTML = '';
            if (!urunId) { alert('Önce "Referans Künyede kullanılan Ürün" seçin.'); return; }
            btnRef.disabled = true; btnRef.textContent = '⏳ Sorgulanıyor...';
            res.style.display = 'none'; res.className = 'hks-op-result';
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            fetch('../ajax.php?action=query_referans_kunye', {
                method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'csrf='+encodeURIComponent(csrf)+'&urun_id='+encodeURIComponent(urunId)+'&kunye_no='+encodeURIComponent(document.getElementById('refKunyeNo').value.trim())
            }).then(function(r){return r.json();}).then(function(d){
                res.style.display='block'; res.classList.add(d.ok?'ok':'err');
                if (d.ok) {
                    refRows = Array.isArray(d.data) ? d.data : (d.data ? [d.data] : []);
                    res.innerHTML = '✅ '+refRows.length+' kayıt bulundu.';
                    renderRefTable();
                } else {
                    refRows = [];
                    res.innerHTML = '❌ '+(d.message || 'Künye bilgisi okunamadı.');
                }
            }).catch(function(){ res.style.display='block'; res.classList.add('err'); res.textContent='İstek gönderilemedi.'; })
            .finally(function(){ btnRef.disabled=false; btnRef.textContent='🔎 Künye Sorgula'; });
        });
    }
    // Karşı taraf "Sıfatı" — Bildirim Türüne göre değişir
    var karsiSifatMap = <?= json_encode($karsi_sifat_map, JSON_UNESCAPED_UNICODE) ?>;
    var turuEl  = document.getElementById('bildirimTuru');
    var sifatEl = document.getElementById('karsiSifat');
    function refreshKarsiSifat() {
        var list = karsiSifatMap[turuEl.value] || [];
        var prev = sifatEl.value;
        var html = '<option value="">Seçiniz</option>';
        list.forEach(function(s){ html += '<option value="'+s+'">'+s+'</option>'; });
        sifatEl.innerHTML = html;
        if (list.indexOf(prev) !== -1) sifatEl.value = prev;
    }
    if (turuEl && sifatEl) {
        turuEl.addEventListener('change', refreshKarsiSifat);
        refreshKarsiSifat();
    }

    // Gideceği Yer = Yurt Dışı → İhracat Yapılan Ülkeler göster
    var gidecekYerEl = document.getElementById('gidecekYer');
    var ihracatWrap  = document.getElementById('ihracatUlkeWrap');
    function refreshIhracat(){
        if (!gidecekYerEl || !ihracatWrap) return;
        ihracatWrap.style.display = (gidecekYerEl.value === 'Yurt Dışı') ? '' : 'none';
    }
    if (gidecekYerEl) { gidecekYerEl.addEventListener('change', refreshIhracat); refreshIhracat(); }

    // Malın Niteliği = İthal → Gelen Ülke göster/zorunlu (GelenUlkeId)
    var gelenUlkeWrap = document.getElementById('gelenUlkeWrap');
    function malinNiteligiVal(){
        var r = form.querySelector('input[name="malin_niteligi"]:checked');
        return r ? r.value : '';
    }
    function refreshGelenUlke(){
        if (!gelenUlkeWrap) return;
        gelenUlkeWrap.style.display = (malinNiteligiVal() === 'İthal') ? '' : 'none';
    }
    form.querySelectorAll('.malinNiteligiRadio').forEach(function(r){
        r.addEventListener('change', refreshGelenUlke);
    });
    refreshGelenUlke();

    // Gidecek yer kayıtlı değil + yurt içi → il/ilçe/belde göster (GidecekYerIl/Ilce/BeldeId)
    var gidecekKonumWrap = document.getElementById('gidecekYerKonumWrap');
    var gidecekKayitliEl = form.querySelector('input[name="gidecek_kayitli_degil"]');
    function refreshGidecekKonum(){
        if (!gidecekKonumWrap) return;
        var kayitsiz = gidecekKayitliEl && gidecekKayitliEl.checked;
        var yurtIci  = gidecekYerEl && gidecekYerEl.value !== 'Yurt Dışı';
        gidecekKonumWrap.style.display = (kayitsiz && yurtIci) ? '' : 'none';
    }
    if (gidecekKayitliEl) gidecekKayitliEl.addEventListener('change', refreshGidecekKonum);
    if (gidecekYerEl)     gidecekYerEl.addEventListener('change', refreshGidecekKonum);
    refreshGidecekKonum();

    // ── Düzenleme modu — kayıtlı değerleri forma geri bas ──
    var EDIT = <?= $is_edit && $edit_values !== null ? json_encode($edit_values, JSON_UNESCAPED_UNICODE) : 'null' ?>;
    if (EDIT) {
        // Select alanlarda kayıtlı değer option olarak yoksa geçici "(kayıtlı değer)"
        // option'ı eklenir — referans kodları değişse bile veri boş görünmez/kaybolmaz.
        function setVal(name, v){
            if (v == null || v === '') return;
            v = String(v);
            var el = form.querySelector('[name="'+name+'"]');
            if (!el) return;
            if (el.tagName === 'SELECT') {
                el.value = v;
                if (el.value !== v) {
                    var opt = document.createElement('option');
                    opt.value = v;
                    opt.textContent = v + ' (kayıtlı değer)';
                    opt.setAttribute('data-prefill', '1');
                    el.appendChild(opt);
                    el.value = v;
                }
            } else {
                el.value = v;
            }
        }
        // 1) Bildirim türü önce → bağımlı "karşı sıfat" listesi yeniden kurulur, sonra değeri ata
        setVal('notification_type', EDIT.notification_type);
        if (typeof refreshKarsiSifat === 'function') refreshKarsiSifat();
        setVal('karsi_sifat', EDIT.karsi_sifat);
        // 2) Basit metin/select alanları
        ['bildirimci_tc_vkn','sifat','firma','alici_tc_vkn','alici_ad','gsm','dogum_tarihi','eposta',
         'reference_kunye_no','uretici_tc_vkn','uretici_ad','il','ilce','belde','urun','malin_turu',
         'urun_cinsi','birim','miktar','birim_fiyat','para_birimi','gidecek_sahibi_tc',
         'arac_plaka','belge_no','belge_tipi','sevk_tarihi'].forEach(function(k){ setVal(k, EDIT[k]); });
        // 3) Gideceği yer → ihracat ülkesi görünürlüğü, sonra değer
        setVal('gidecek_yer', EDIT.gidecek_yer);
        if (typeof refreshIhracat === 'function') refreshIhracat();
        setVal('ihracat_ulke', EDIT.ihracat_ulke);
        // 4) Malın niteliği — radio (gelen ülke görünürlüğünü tetikler)
        if (EDIT.malin_niteligi) {
            var r = form.querySelector('input[name="malin_niteligi"][value="'+String(EDIT.malin_niteligi).replace(/"/g,'\\"')+'"]');
            if (r) r.checked = true;
        }
        // 5) Onay kutuları
        [['yurt_disi'], ['analize_gonder'], ['gidecek_kayitli_degil']].forEach(function(p){
            var el = form.querySelector('input[name="'+p[0]+'"]');
            if (el) el.checked = (EDIT[p[0]] == 1);
        });
        // 6) Koşullu alanları görünür yap, sonra değerlerini bas (Sprint MissingFields-01)
        if (typeof refreshGelenUlke === 'function') refreshGelenUlke();
        if (typeof refreshGidecekKonum === 'function') refreshGidecekKonum();
        ['gelen_ulke','gidecek_yer_il','gidecek_yer_ilce','gidecek_yer_belde'].forEach(function(k){ setVal(k, EDIT[k]); });
    }

    show(1);
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
