<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (!can('hks.read') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.read).');
}

$op_repo     = new HksRepository(db());
$op_settings = $op_repo->getSettings();

$notif_id = (int)($_GET['id'] ?? 0);
if ($notif_id === 0) {
    header('Location: bildirimler.php'); exit;
}

$notif = $op_repo->getNotification($notif_id);
if ($notif === null) {
    header('Location: bildirimler.php?err=not_found'); exit;
}

$locked_statuses = ['sent', 'send_pending', 'cancelled'];
if (in_array($notif['status'] ?? '', $locked_statuses, true)) {
    header('Location: taslak_detay.php?id=' . $notif_id . '&err=readonly'); exit;
}

$flash_msg  = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can('hks.write') && !is_admin()) {
        http_response_code(403); die('Erişim yetkiniz yok (hks.write).');
    }
    csrf_check($_POST['csrf'] ?? null);

    if (in_array($notif['status'] ?? '', $locked_statuses, true)) {
        header('Location: taslak_detay.php?id=' . $notif_id . '&err=readonly'); exit;
    }

    $gidecek_yer_post = trim($_POST['gidecek_yer'] ?? '');
    $is_yurt_disi     = mb_strtolower($gidecek_yer_post) === mb_strtolower('Yurt Dışı');
    $kayitsiz_post    = isset($_POST['gidecek_kayitli_degil']) ? 1 : 0;
    $miktar_post      = (float)str_replace(',', '.', $_POST['miktar'] ?? '0');

    $kalan_ref = isset($notif['reference_kunye_kalan_miktar']) && $notif['reference_kunye_kalan_miktar'] !== null
                 ? (float)$notif['reference_kunye_kalan_miktar'] : 0.0;

    if ($kalan_ref > 0 && $miktar_post > $kalan_ref + 1e-9) {
        $flash_msg  = 'Miktar, kalan miktarı (' . number_format($kalan_ref, 2, ',', '.') . ' ' . ($notif['reference_kunye_birim'] ?? '') . ') aşamaz.';
        $flash_type = 'err';
    } else {
        $data = array_merge($notif, [
            'sifat'                 => trim($_POST['sifat'] ?? ''),
            'alici_ad'              => trim($_POST['alici_ad'] ?? ''),
            'alici_tc_vkn'          => trim($_POST['alici_tc_vkn'] ?? ''),
            'karsi_sifat'           => trim($_POST['karsi_sifat'] ?? ''),
            'depo'                  => trim($_POST['depo'] ?? ''),
            'sevk_tarihi'           => trim($_POST['sevk_tarihi'] ?? '') ?: null,
            'gidecek_yer'           => $gidecek_yer_post,
            'gidecek_kayitli_degil' => $kayitsiz_post,
            'ihracat_ulke'          => $is_yurt_disi ? trim($_POST['ihracat_ulke'] ?? '') : '',
            'gidecek_isyeri_id'     => (!$is_yurt_disi && !$kayitsiz_post)
                                       ? (trim($_POST['gidecek_isyeri_id'] ?? '') ?: null) : null,
            'gidecek_yer_il'        => $kayitsiz_post ? trim($_POST['gidecek_yer_il'] ?? '') : '',
            'gidecek_yer_ilce'      => $kayitsiz_post ? trim($_POST['gidecek_yer_ilce'] ?? '') : '',
            'malin_niteligi'        => trim($_POST['malin_niteligi'] ?? ''),
            'gelen_ulke'            => trim($_POST['gelen_ulke'] ?? ''),
            'malin_turu'            => trim($_POST['malin_turu'] ?? ''),
            'miktar'                => $miktar_post,
            'birim_fiyat'           => trim($_POST['birim_fiyat'] ?? '') !== ''
                                       ? (float)str_replace(',', '.', $_POST['birim_fiyat']) : null,
            'para_birimi'           => trim($_POST['para_birimi'] ?? ''),
            'belge_no'              => trim($_POST['belge_no'] ?? ''),
            'belge_tipi'            => trim($_POST['belge_tipi'] ?? ''),
            'arac_plaka'            => strtoupper(trim($_POST['arac_plaka'] ?? '')),
        ]);

        try {
            $op_repo->updateNotification($notif_id, $data);
            audit_log_event('update', 'hks_notifications', $notif_id, null, [
                'source' => 'hks_mobile_taslak_duzenle',
            ]);
            $updated     = $op_repo->getNotification($notif_id);
            $new_status  = $updated['status'] ?? 'draft';
            $redirect_msg = ($new_status === 'ready') ? 'ready' : 'saved_draft';
            header('Location: taslak_detay.php?id=' . $notif_id . '&msg=' . $redirect_msg);
            exit;
        } catch (Throwable $e) {
            error_log('[taslak_duzenle] updateNotification: ' . $e->getMessage());
            $flash_msg  = 'Kayıt güncellenemedi. Lütfen tekrar deneyin.';
            $flash_type = 'err';
        }
    }
}

$status            = $notif['status'] ?? 'draft';
$notification_type = $notif['notification_type'] ?? '';
$has_counterparty  = hks_karsi_taraf_required($notification_type);
$kunye_no          = $notif['reference_kunye_no'] ?? '';
$urun_display      = $notif['reference_kunye_urun'] ?: ($notif['urun'] ?? '');
$cins_display      = $notif['reference_kunye_urun_cinsi'] ?: ($notif['urun_cinsi'] ?? '');
$birim_display     = $notif['reference_kunye_birim'] ?: ($notif['birim'] ?? '');
$kalan_f           = isset($notif['reference_kunye_kalan_miktar']) && $notif['reference_kunye_kalan_miktar'] !== null
                     ? (float)$notif['reference_kunye_kalan_miktar'] : null;

$f = $notif;

$karsi_sifat_map     = hks_karsi_taraf_sifat_map();
$karsi_sifatlar      = $karsi_sifat_map[$notification_type] ?? [];
$gidecek_yer_list    = hks_gidecek_yer_list();
$malin_niteligi_list = hks_malin_niteligi_list();
$malin_turu_list     = hks_malin_turu_list();
$belge_tipi_list     = hks_belge_tipi_list();
$para_birimi_list    = hks_para_birimi_list();
$ulke_list           = hks_ulke_list();
$sifat_list          = hks_sifat_list();

$cur_gidecek_yer  = trim((string)($f['gidecek_yer'] ?? ''));
$cur_is_yurt_disi = mb_strtolower($cur_gidecek_yer) === mb_strtolower('Yurt Dışı');
$cur_kayitsiz     = (int)($f['gidecek_kayitli_degil'] ?? 0) === 1;
$cur_is_ithal     = mb_strtolower(trim((string)($f['malin_niteligi'] ?? ''))) === mb_strtolower('İthal');
$has_belge        = trim((string)($f['belge_no'] ?? '')) !== '';

include __DIR__ . '/_layout.php';
hks_mob_start('Taslak Düzenle', 'bildirimler');

function tde_h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function tde_sel(string $current, string $opt): string {
    return $current === $opt ? ' selected' : '';
}
?>

<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #e8edf5;">
    <a href="taslak_detay.php?id=<?= $notif_id ?>" style="font-size:15px;color:#1565c0;text-decoration:none;padding:4px;flex-shrink:0;">&#8592; Detay</a>
    <span style="font-size:14px;font-weight:700;color:#1a2236;flex:1;"><?= tde_h($notif['local_no'] ?? '') ?></span>
</div>

<?php if ($flash_msg !== ''): ?>
<div class="tde-alert tde-alert-<?= tde_h($flash_type) ?>"><?= tde_h($flash_msg) ?></div>
<?php endif; ?>

<form method="post" action="taslak_duzenle.php?id=<?= $notif_id ?>" id="tde-form" novalidate>
<input type="hidden" name="csrf" value="<?= tde_h(csrf_token()) ?>">

<!-- ── Section 1: Künye (readonly) ── -->
<div class="tde-section-label">Künye Bilgileri</div>
<div class="tde-card">
    <?php if ($kunye_no !== ''): ?>
    <div class="tde-row">
        <span class="tde-lbl">Künye No</span>
        <span class="tde-val" style="color:#1565c0;font-weight:700;"><?= tde_h($kunye_no) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($urun_display !== ''): ?>
    <div class="tde-row">
        <span class="tde-lbl">Ürün</span>
        <span class="tde-val"><?= tde_h($urun_display) ?><?= $cins_display !== '' ? ' / ' . tde_h($cins_display) : '' ?></span>
    </div>
    <?php endif; ?>
    <?php if ($kalan_f !== null): ?>
    <div class="tde-row">
        <span class="tde-lbl">Kalan</span>
        <span class="tde-val" style="color:#059669;font-weight:600;"><?= tde_h(number_format($kalan_f, 2, ',', '.')) ?> <?= tde_h($birim_display) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($notification_type !== ''): ?>
    <div class="tde-row" style="border-bottom:none;">
        <span class="tde-lbl">Bildirim Türü</span>
        <span class="tde-val"><?= tde_h($notification_type) ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Section 2: Bildirim Bilgileri ── -->
<div class="tde-section-label">Bildirim Bilgileri</div>
<div class="tde-card">

    <div class="tde-field">
        <label class="tde-label" for="sifat">Bildirimci Sıfatı <span class="tde-req">*</span></label>
        <select class="mob-input" id="sifat" name="sifat">
            <option value="">— Seçin —</option>
            <?php foreach ($sifat_list as $s): ?>
            <option value="<?= tde_h($s['code']) ?>"<?= tde_sel(trim((string)($f['sifat'] ?? '')), $s['code']) ?>><?= tde_h($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($has_counterparty): ?>
    <div class="tde-field">
        <label class="tde-label" for="alici_ad">Alıcı Adı / Ünvanı <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="alici_ad" name="alici_ad"
               value="<?= tde_h($f['alici_ad'] ?? '') ?>"
               placeholder="Alıcı adı veya ünvanı" autocomplete="off">
    </div>
    <div class="tde-field">
        <label class="tde-label" for="alici_tc_vkn">Alıcı TC / VKN <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="alici_tc_vkn" name="alici_tc_vkn"
               value="<?= tde_h($f['alici_tc_vkn'] ?? '') ?>"
               placeholder="11 veya 10 haneli" inputmode="numeric" maxlength="11">
    </div>
    <?php if (!empty($karsi_sifatlar)): ?>
    <div class="tde-field">
        <label class="tde-label" for="karsi_sifat">Alıcı Sıfatı <span class="tde-req">*</span></label>
        <select class="mob-input" id="karsi_sifat" name="karsi_sifat">
            <option value="">— Seçin —</option>
            <?php foreach ($karsi_sifatlar as $ks): ?>
            <option value="<?= tde_h($ks) ?>"<?= tde_sel(trim((string)($f['karsi_sifat'] ?? '')), $ks) ?>><?= tde_h($ks) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="tde-field">
        <label class="tde-label" for="depo">Depo / Şube <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="depo" name="depo"
               value="<?= tde_h($f['depo'] ?? '') ?>"
               placeholder="Depo veya şube adı" autocomplete="off">
    </div>

    <div class="tde-field">
        <label class="tde-label" for="sevk_tarihi">Sevk Tarihi <span class="tde-req">*</span></label>
        <input class="mob-input" type="date" id="sevk_tarihi" name="sevk_tarihi"
               value="<?= tde_h($f['sevk_tarihi'] ?? '') ?>">
    </div>

    <div class="tde-field">
        <label class="tde-label" for="gidecek_yer">Gideceği Yer <span class="tde-req">*</span></label>
        <select class="mob-input" id="gidecek_yer" name="gidecek_yer" onchange="tdeGidecekYerChange(this.value)">
            <option value="">— Seçin —</option>
            <?php foreach ($gidecek_yer_list as $gy): ?>
            <option value="<?= tde_h($gy) ?>"<?= tde_sel($cur_gidecek_yer, $gy) ?>><?= tde_h($gy) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="tde-field" id="row-ihracat-ulke"<?= $cur_is_yurt_disi ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="ihracat_ulke">İhracat Ülkesi <span class="tde-req">*</span></label>
        <select class="mob-input" id="ihracat_ulke" name="ihracat_ulke">
            <option value="">— Seçin —</option>
            <?php foreach ($ulke_list as $u): ?>
            <option value="<?= tde_h($u) ?>"<?= tde_sel(trim((string)($f['ihracat_ulke'] ?? '')), $u) ?>><?= tde_h($u) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="tde-field" id="row-gidecek-isyeri"<?= (!$cur_is_yurt_disi && !$cur_kayitsiz) ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="gidecek_isyeri_id">Gidecek İşyeri ID <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="gidecek_isyeri_id" name="gidecek_isyeri_id"
               value="<?= tde_h($f['gidecek_isyeri_id'] ?? '') ?>"
               placeholder="HKS işyeri kimlik no" inputmode="numeric">
    </div>

    <div class="tde-field" id="row-kayitsiz-check"<?= $cur_is_yurt_disi ? ' style="display:none;"' : '' ?>>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:#1a2236;cursor:pointer;padding:4px 0;">
            <input type="checkbox" name="gidecek_kayitli_degil" value="1" id="chk-kayitsiz"
                   onchange="tdeKayitsizChange(this.checked)"
                   <?= $cur_kayitsiz ? 'checked' : '' ?>>
            Gidecek yer kayıtlı değil (il/ilçe gireceğim)
        </label>
    </div>

    <div class="tde-field" id="row-gidecek-il"<?= $cur_kayitsiz ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="gidecek_yer_il">Gidecek Yer İli <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="gidecek_yer_il" name="gidecek_yer_il"
               value="<?= tde_h($f['gidecek_yer_il'] ?? '') ?>"
               placeholder="İl adı" autocomplete="off">
    </div>

    <div class="tde-field" id="row-gidecek-ilce"<?= $cur_kayitsiz ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="gidecek_yer_ilce">Gidecek Yer İlçesi <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="gidecek_yer_ilce" name="gidecek_yer_ilce"
               value="<?= tde_h($f['gidecek_yer_ilce'] ?? '') ?>"
               placeholder="İlçe adı" autocomplete="off">
    </div>

    <div class="tde-field">
        <label class="tde-label" for="malin_niteligi">Malın Niteliği <span class="tde-req">*</span></label>
        <select class="mob-input" id="malin_niteligi" name="malin_niteligi" onchange="tdeNitelikChange(this.value)">
            <option value="">— Seçin —</option>
            <?php foreach ($malin_niteligi_list as $mn): ?>
            <option value="<?= tde_h($mn) ?>"<?= tde_sel(trim((string)($f['malin_niteligi'] ?? '')), $mn) ?>><?= tde_h($mn) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="tde-field" id="row-gelen-ulke"<?= $cur_is_ithal ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="gelen_ulke">Gelen Ülke <span class="tde-req">*</span></label>
        <select class="mob-input" id="gelen_ulke" name="gelen_ulke">
            <option value="">— Seçin —</option>
            <?php foreach ($ulke_list as $u): ?>
            <option value="<?= tde_h($u) ?>"<?= tde_sel(trim((string)($f['gelen_ulke'] ?? '')), $u) ?>><?= tde_h($u) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="tde-field" style="border-bottom:none;">
        <label class="tde-label" for="malin_turu">Malın Türü <span class="tde-req">*</span></label>
        <select class="mob-input" id="malin_turu" name="malin_turu">
            <option value="">— Seçin —</option>
            <?php foreach ($malin_turu_list as $mt): ?>
            <option value="<?= tde_h($mt) ?>"<?= tde_sel(trim((string)($f['malin_turu'] ?? '')), $mt) ?>><?= tde_h($mt) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

</div>

<!-- ── Section 3: Mal ve Sevk Bilgileri ── -->
<div class="tde-section-label">Mal ve Sevk Bilgileri</div>
<div class="tde-card">

    <div class="tde-field">
        <label class="tde-label" for="miktar">
            Miktar <span class="tde-req">*</span>
            <?php if ($kalan_f !== null): ?>
            <span style="font-size:11px;color:#059669;font-weight:400;">&nbsp;maks&nbsp;<?= tde_h(number_format($kalan_f, 2, ',', '.')) ?>&nbsp;<?= tde_h($birim_display) ?></span>
            <?php endif; ?>
        </label>
        <input class="mob-input" type="number" id="miktar" name="miktar"
               step="0.001" min="0.001"
               value="<?= tde_h($f['miktar'] > 0 ? $f['miktar'] : '') ?>"
               placeholder="Miktar" inputmode="decimal"
               <?= $kalan_f !== null ? 'max="' . tde_h((string)$kalan_f) . '"' : '' ?>>
    </div>

    <div class="tde-field">
        <label class="tde-label" for="birim_fiyat">Birim Fiyatı <span style="font-weight:400;color:#7b8fa8;">(opsiyonel)</span></label>
        <div style="display:flex;gap:8px;align-items:center;">
            <input class="mob-input" type="number" id="birim_fiyat" name="birim_fiyat"
                   step="0.01" min="0"
                   value="<?= tde_h(isset($f['birim_fiyat']) && (float)$f['birim_fiyat'] > 0 ? $f['birim_fiyat'] : '') ?>"
                   placeholder="0.00" style="flex:1;" inputmode="decimal">
            <select class="mob-input" name="para_birimi" style="width:80px;flex-shrink:0;">
                <?php foreach ($para_birimi_list as $pb): ?>
                <option value="<?= tde_h($pb) ?>"<?= tde_sel(trim((string)($f['para_birimi'] ?? 'TL')), $pb) ?>><?= tde_h($pb) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="tde-field">
        <label class="tde-label" for="belge_no">Belge No <span style="font-weight:400;color:#7b8fa8;">(opsiyonel)</span></label>
        <input class="mob-input" type="text" id="belge_no" name="belge_no"
               value="<?= tde_h($f['belge_no'] ?? '') ?>"
               placeholder="Belge / fatura no" autocomplete="off"
               oninput="tdeBelgeNoChange(this.value)">
    </div>

    <div class="tde-field" id="row-belge-tipi"<?= $has_belge ? '' : ' style="display:none;"' ?>>
        <label class="tde-label" for="belge_tipi">Belge Tipi <span class="tde-req">*</span></label>
        <select class="mob-input" id="belge_tipi" name="belge_tipi">
            <option value="">— Seçin —</option>
            <?php foreach ($belge_tipi_list as $bt): ?>
            <option value="<?= tde_h($bt) ?>"<?= tde_sel(trim((string)($f['belge_tipi'] ?? '')), $bt) ?>><?= tde_h($bt) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="tde-field" style="border-bottom:none;">
        <label class="tde-label" for="arac_plaka">Araç Plaka <span class="tde-req">*</span></label>
        <input class="mob-input" type="text" id="arac_plaka" name="arac_plaka"
               value="<?= tde_h($f['arac_plaka'] ?? '') ?>"
               placeholder="34ABC123" autocomplete="off"
               style="text-transform:uppercase;"
               oninput="this.value=this.value.toUpperCase()">
    </div>

</div>

<div style="display:flex;flex-direction:column;gap:10px;margin:16px 0;">
    <button type="submit" class="mob-btn mob-btn-solid" style="padding:14px;font-size:16px;">
        Kaydet
    </button>
    <a href="taslak_detay.php?id=<?= $notif_id ?>" class="mob-btn mob-btn-outline"
       style="text-align:center;text-decoration:none;padding:12px;">
        İptal
    </a>
</div>

</form>

<script>
function tdeGidecekYerChange(val) {
    var isYurtDisi = val.toLowerCase().indexOf('yurt dış') !== -1;
    var kayitsiz   = document.getElementById('chk-kayitsiz') && document.getElementById('chk-kayitsiz').checked;
    document.getElementById('row-ihracat-ulke').style.display   = isYurtDisi ? '' : 'none';
    document.getElementById('row-gidecek-isyeri').style.display = (!isYurtDisi && !kayitsiz) ? '' : 'none';
    document.getElementById('row-kayitsiz-check').style.display = isYurtDisi ? 'none' : '';
    if (isYurtDisi) {
        document.getElementById('row-gidecek-il').style.display   = 'none';
        document.getElementById('row-gidecek-ilce').style.display  = 'none';
    }
}
function tdeKayitsizChange(checked) {
    var gy         = (document.getElementById('gidecek_yer') || {}).value || '';
    var isYurtDisi = gy.toLowerCase().indexOf('yurt dış') !== -1;
    document.getElementById('row-gidecek-il').style.display    = checked ? '' : 'none';
    document.getElementById('row-gidecek-ilce').style.display  = checked ? '' : 'none';
    document.getElementById('row-gidecek-isyeri').style.display = (!checked && !isYurtDisi) ? '' : 'none';
}
function tdeNitelikChange(val) {
    var isIthal = val.toLowerCase().indexOf('ithal') !== -1;
    document.getElementById('row-gelen-ulke').style.display = isIthal ? '' : 'none';
}
function tdeBelgeNoChange(val) {
    document.getElementById('row-belge-tipi').style.display = val.trim() !== '' ? '' : 'none';
}
</script>

<style>
.tde-section-label {
    font-size:11px; font-weight:700; color:#7b8fa8; text-transform:uppercase;
    letter-spacing:.05em; margin:14px 0 4px; padding:0 2px;
}
.tde-card {
    background:#fff; border-radius:12px; padding:0 16px; margin-bottom:4px;
    box-shadow:0 1px 3px rgba(21,101,192,.07);
}
.tde-field {
    padding:10px 0; border-bottom:1px solid #f3f5f9;
}
.tde-field:last-child { border-bottom:none; }
.tde-label {
    display:block; font-size:12px; color:#7b8fa8; font-weight:600; margin-bottom:5px;
}
.tde-req { color:#dc2626; }
.tde-row {
    display:flex; justify-content:space-between; align-items:flex-start;
    font-size:13px; padding:7px 0; border-bottom:1px solid #f3f5f9;
}
.tde-row:last-child { border-bottom:none; }
.tde-lbl { color:#7b8fa8; flex-shrink:0; margin-right:8px; min-width:90px; }
.tde-val { color:#1a2236; font-weight:500; text-align:right; word-break:break-all; }
.tde-alert {
    border-radius:10px; padding:12px 14px; font-size:14px; margin-bottom:12px;
}
.tde-alert-err { background:#fee2e2; color:#991b1b; }
.tde-alert-ok  { background:#d1fae5; color:#065f46; }
</style>

<?php hks_mob_end(); ?>
