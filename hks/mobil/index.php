<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (!can('hks.read') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.read).');
}

$op_repo         = new HksRepository(db());
$op_settings     = $op_repo->getSettings();
$op_company_name = $op_settings['firma_adi'] ?? 'AGRONATURAL';
$op_env          = $op_settings['environment'] ?? 'test';

$company_id = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : 0;
if ($company_id === 0) {
    $all_cos    = $op_repo->getAllCompanies();
    $company_id = !empty($all_cos) ? (int)$all_cos[0]['id'] : 0;
}

$producers = $op_repo->listMobileContacts('producer', $company_id);
$customers = $op_repo->listMobileContacts('customer', $company_id);

include __DIR__ . '/_layout.php';
hks_mob_start('Ana Sayfa', 'anasayfa');

// Helper: <option> listesi üret
function mob_contact_options(array $list, string $placeholder): string {
    $out = '<option value="">' . hks_mob_h($placeholder) . '</option>';
    foreach ($list as $c) {
        $out .= '<option value="' . hks_mob_h($c['tc_vkn']) . '">'
              . hks_mob_h($c['display_name']) . ' (' . hks_mob_h($c['tc_vkn']) . ')'
              . '</option>';
    }
    return $out;
}
?>

<div id="mob-tabs" class="mob-tabs">
    <button class="mob-tab-btn active" data-tab="satin-alma" onclick="mobSetTab('satin-alma',this)">Satın Alma</button>
    <button class="mob-tab-btn"        data-tab="satis"      onclick="mobSetTab('satis',this)">Satış</button>
    <button class="mob-tab-btn"        data-tab="sevk-etme"  onclick="mobSetTab('sevk-etme',this)">Sevk Etme</button>
</div>

<!-- ── Satın Alma ─────────────────────────── -->
<div id="pnl-satin-alma" class="mob-card">

    <div class="mob-row" style="align-items:center;margin-bottom:10px;">
        <div class="mob-input-group" style="flex:1;margin-bottom:0;">
            <?php if (empty($producers)): ?>
            <input class="mob-input" type="text" placeholder="Üretici — önce üretici ekleyin" disabled>
            <?php else: ?>
            <select class="mob-input" style="color:#1a2236;">
                <?= mob_contact_options($producers, 'Üretici seçin…') ?>
            </select>
            <?php endif; ?>
        </div>
        <label class="mob-check-row" style="flex-shrink:0;margin-bottom:0;padding:0 4px;">
            <input type="checkbox" disabled>
            <span style="font-size:13px;color:#1a2236;white-space:nowrap;">Toplama Mal</span>
        </label>
    </div>

    <div class="mob-row" style="margin-bottom:10px;">
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="İl"    disabled></div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="İlçe"  disabled></div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Belde" disabled></div>
    </div>

    <div class="mob-input-group">
        <input class="mob-input" type="text" placeholder="Plaka" disabled>
    </div>

    <div class="mob-row" style="margin-top:10px;">
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Ürün"          disabled></div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Üretim Şekli"  disabled></div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Cins"          disabled></div>
    </div>

    <div class="mob-input-group" style="margin-top:10px;">
        <input class="mob-input" type="text" placeholder="Miktar" disabled>
    </div>

    <div class="mob-actions">
        <button class="mob-btn mob-btn-outline disabled" disabled title="Satın alma bildirimi sonraki sprintte eklenecek">Künye Sorgula</button>
        <button class="mob-btn mob-btn-outline disabled" disabled title="Sonraki sprintte">Bildir</button>
    </div>
    <p class="mob-sprint-note">Satın alma bildirimi sonraki sprintte eklenecek.</p>

</div>

<!-- ── Satış ──────────────────────────────── -->
<div id="pnl-satis" class="mob-card" style="display:none;">
<form action="kunye_sorgula.php" method="get" id="form-satis" style="margin:0;padding:0;">
<input type="hidden" name="mode" value="satis">

    <div class="mob-row" style="margin-bottom:10px;">
        <div class="mob-input-group">
            <?php if (empty($customers)): ?>
            <input class="mob-input" type="text" placeholder="Müşteri — önce müşteri ekleyin" disabled>
            <?php else: ?>
            <select class="mob-input" name="musteri" style="color:#1a2236;">
                <?= mob_contact_options($customers, 'Müşteri (opsiyonel)') ?>
            </select>
            <?php endif; ?>
        </div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Sıfat" disabled></div>
    </div>

    <div class="mob-input-group">
        <input class="mob-input" type="text" placeholder="Gideceği Yer" disabled>
    </div>

    <div class="mob-input-group" style="margin-top:10px;">
        <input class="mob-input" type="text" placeholder="İş Yeri" disabled>
    </div>

    <div class="mob-input-group" style="margin-top:10px;">
        <input class="mob-input" name="plaka" type="text" placeholder="Plaka (opsiyonel)" autocomplete="off" style="text-transform:uppercase;">
    </div>

    <div class="mob-row" style="margin-top:10px;align-items:stretch;">
        <div class="mob-date-group">
            <label>Başlangıç Tarihi</label>
            <input class="mob-input" name="baslangic" type="date" value="<?= date('Y-m-d', strtotime('-14 days')) ?>">
        </div>
        <div class="mob-date-group">
            <label>Bitiş Tarihi</label>
            <input class="mob-input" name="bitis" type="date" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="mob-actions">
        <button class="mob-btn mob-btn-solid" type="submit">Künye Sorgula</button>
        <button class="mob-btn mob-btn-outline disabled" disabled title="Sonraki sprintte">Bildir</button>
    </div>
    <p class="mob-sprint-note">Bildir sonraki sprintte aktif olacak.</p>

</form>
</div>

<!-- ── Sevk Etme ───────────────────────────── -->
<div id="pnl-sevk-etme" class="mob-card" style="display:none;">
<form action="kunye_sorgula.php" method="get" id="form-sevk" style="margin:0;padding:0;">
<input type="hidden" name="mode" value="sevk">

    <div class="mob-row" style="margin-bottom:10px;">
        <div class="mob-input-group">
            <?php if (empty($customers)): ?>
            <input class="mob-input" type="text" placeholder="Müşteri — önce müşteri ekleyin" disabled>
            <?php else: ?>
            <select class="mob-input" name="musteri" style="color:#1a2236;">
                <?= mob_contact_options($customers, 'Müşteri (opsiyonel)') ?>
            </select>
            <?php endif; ?>
        </div>
        <div class="mob-input-group"><input class="mob-input" type="text" placeholder="Sıfat" disabled></div>
    </div>

    <div class="mob-input-group">
        <input class="mob-input" type="text" placeholder="Gideceği Yer" disabled>
    </div>

    <div class="mob-input-group" style="margin-top:10px;">
        <input class="mob-input" type="text" placeholder="İş Yeri" disabled>
    </div>

    <div class="mob-input-group" style="margin-top:10px;">
        <input class="mob-input" name="plaka" type="text" placeholder="Plaka (opsiyonel)" autocomplete="off" style="text-transform:uppercase;">
    </div>

    <div class="mob-row" style="margin-top:10px;align-items:stretch;">
        <div class="mob-date-group">
            <label>Başlangıç Tarihi</label>
            <input class="mob-input" name="baslangic" type="date" value="<?= date('Y-m-d', strtotime('-14 days')) ?>">
        </div>
        <div class="mob-date-group">
            <label>Bitiş Tarihi</label>
            <input class="mob-input" name="bitis" type="date" value="<?= date('Y-m-d') ?>">
        </div>
    </div>

    <div class="mob-actions">
        <button class="mob-btn mob-btn-solid" type="submit">Künye Sorgula</button>
        <button class="mob-btn mob-btn-outline disabled" disabled title="Sonraki sprintte">Bildir</button>
    </div>
    <p class="mob-sprint-note">Bildir sonraki sprintte aktif olacak.</p>

</form>
</div>

<script>
function mobSetTab(tab, btn) {
    document.querySelectorAll('.mob-tab-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    ['satin-alma','satis','sevk-etme'].forEach(function(t) {
        var el = document.getElementById('pnl-' + t);
        if (el) el.style.display = (t === tab) ? '' : 'none';
    });
}
</script>

<?php hks_mob_end(); ?>
