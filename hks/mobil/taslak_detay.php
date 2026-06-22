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

$notif_id = (int)($_GET['id'] ?? 0);
$msg      = trim($_GET['msg'] ?? '');

if ($notif_id === 0) {
    header('Location: bildirimler.php'); exit;
}

$notif = $op_repo->getNotification($notif_id);
if ($notif === null) {
    header('Location: bildirimler.php?err=not_found'); exit;
}

$validation_errors = [];
if (!empty($notif['validation_errors_json'])) {
    $validation_errors = json_decode($notif['validation_errors_json'], true) ?: [];
}

// Decode stored masked DTO for "Kaynak HKS Bilgileri" audit section
$ref_dto = [];
if (!empty($notif['reference_kunye_query_json'])) {
    $decoded = json_decode((string)$notif['reference_kunye_query_json'], true);
    if (is_array($decoded)) $ref_dto = $decoded;
}
function td_dto_get(array $dto, array $names): string {
    foreach ($names as $name) {
        foreach ($dto as $k => $v) {
            if (strtolower($k) === strtolower($name) && $v !== null && $v !== '') {
                return (string)$v;
            }
        }
    }
    return '';
}
$hks_bildirim_turu = td_dto_get($ref_dto, ['BildirimTuru','BildirimTuruAdi']);
$hks_belge_tipi    = td_dto_get($ref_dto, ['BelgeTipi','BelgeTipiAdi']);
$hks_belge_no      = td_dto_get($ref_dto, ['BelgeNo']);
$hks_kunye_no      = td_dto_get($ref_dto, ['KunyeNo','MalinKunyeNo','ReferansKunyeNo']);
$hks_kalan         = td_dto_get($ref_dto, ['KalanMiktar','Kalan']);

$status        = $notif['status'] ?? 'draft';
$local_no      = $notif['local_no'] ?? '';
$kunye_no      = $notif['reference_kunye_no'] ?? '';
$urun_display  = $notif['reference_kunye_urun'] ?: ($notif['urun'] ?? '');
$cins_display  = $notif['reference_kunye_urun_cinsi'] ?: ($notif['urun_cinsi'] ?? '');
$birim_display = $notif['reference_kunye_birim'] ?: ($notif['birim'] ?? '');
$miktar        = (float)($notif['miktar'] ?? 0);
$kalan         = isset($notif['reference_kunye_kalan_miktar']) && $notif['reference_kunye_kalan_miktar'] !== null
                 ? (float)$notif['reference_kunye_kalan_miktar'] : null;
$plaka         = $notif['arac_plaka'] ?? '';
$belge_no      = $notif['belge_no'] ?? '';
$sevk_tarihi   = $notif['sevk_tarihi'] ?? '';
$notif_type    = $notif['notification_type'] ?? '';
$alici_tc      = $notif['alici_tc_vkn'] ?? '';
$alici_ad      = $notif['alici_ad'] ?? '';
$uretici_tc    = $notif['uretici_tc_vkn'] ?? '';
$sifat         = $notif['sifat'] ?? '';
$firma         = $notif['firma'] ?? '';
$created_at    = $notif['created_at'] ?? '';

include __DIR__ . '/_layout.php';
hks_mob_start('Taslak Detay', 'bildirimler');

function td_h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function td_status_label(string $s): string {
    return match($s) {
        'ready'   => 'Hazır',
        'checked' => 'Onaylı',
        'sent'    => 'Gönderildi',
        'failed'  => 'Hata',
        'cancelled' => 'İptal',
        default   => 'Taslak',
    };
}
function td_status_cls(string $s): string {
    return match($s) {
        'ready','checked' => 'td-badge-ready',
        'sent'            => 'td-badge-sent',
        'failed'          => 'td-badge-err',
        default           => 'td-badge-draft',
    };
}
?>

<div class="td-header">
    <a href="bildirimler.php" class="td-back">&#8592; Bildirimler</a>
    <span class="td-local-no"><?= td_h($local_no) ?></span>
    <span class="td-badge <?= td_status_cls($status) ?>"><?= td_status_label($status) ?></span>
</div>

<?php if ($msg === 'created'): ?>
<div class="td-alert td-alert-ok">Taslak oluşturuldu. Gönderim için düzenleme ekranından eksik alanları tamamlayın.</div>
<?php elseif ($msg === 'duplicate'): ?>
<div class="td-alert td-alert-info">Bu künye için zaten bir taslak mevcut.</div>
<?php endif; ?>

<?php if (!empty($validation_errors)): ?>
<div class="td-missing-box">
    <div class="td-missing-title">Eksik / Hatalı Alanlar (<?= count($validation_errors) ?>)</div>
    <?php foreach ($validation_errors as $err): ?>
    <div class="td-missing-item">&#8226; <?= td_h($err) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Main info card -->
<div class="td-card">

    <?php if ($notif_type !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Bildirim Türü</span>
        <span class="td-val"><?= td_h($notif_type) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($kunye_no !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Künye No</span>
        <span class="td-val td-kunye-no"><?= td_h($kunye_no) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($urun_display !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Ürün</span>
        <span class="td-val"><?= td_h($urun_display) ?><?= $cins_display !== '' ? ' / ' . td_h($cins_display) : '' ?></span>
    </div>
    <?php endif; ?>

    <?php if ($miktar > 0): ?>
    <div class="td-card-row">
        <span class="td-label">Miktar</span>
        <span class="td-val"><?= td_h(number_format($miktar, 2, ',', '.')) ?> <?= td_h($birim_display) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($kalan !== null): ?>
    <div class="td-card-row">
        <span class="td-label">Kalan</span>
        <span class="td-val"><?= td_h(number_format($kalan, 2, ',', '.')) ?> <?= td_h($birim_display) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($plaka !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Plaka</span>
        <span class="td-val"><?= td_h($plaka) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($sevk_tarihi !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Sevk Tarihi</span>
        <span class="td-val"><?= td_h(date('d.m.Y', strtotime($sevk_tarihi))) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($belge_no !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Belge No</span>
        <span class="td-val"><?= td_h($belge_no) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($alici_tc !== '' || $alici_ad !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Alıcı</span>
        <span class="td-val">
            <?= $alici_ad !== '' ? td_h($alici_ad) . '<br>' : '' ?>
            <span style="font-size:12px;color:#7b8fa8;"><?= td_h($alici_tc) ?></span>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($uretici_tc !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Üretici TC/VKN</span>
        <span class="td-val"><?= td_h($uretici_tc) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($sifat !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Sıfat</span>
        <span class="td-val"><?= td_h($sifat) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($firma !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Firma</span>
        <span class="td-val"><?= td_h($firma) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($created_at !== ''): ?>
    <div class="td-card-row">
        <span class="td-label">Oluşturulma</span>
        <span class="td-val"><?= td_h(date('d.m.Y H:i', strtotime($created_at))) ?></span>
    </div>
    <?php endif; ?>

</div>

<!-- Kaynak HKS Bilgileri — denetim amaçlı, collapsible -->
<?php if (!empty($ref_dto)): ?>
<details class="td-hks-src">
    <summary class="td-hks-src-toggle">Kaynak HKS Bilgileri</summary>
    <div class="td-card" style="margin-top:8px;">
        <?php if ($hks_kunye_no !== ''): ?>
        <div class="td-card-row">
            <span class="td-label">Künye No</span>
            <span class="td-val td-kunye-no"><?= td_h($hks_kunye_no) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hks_bildirim_turu !== ''): ?>
        <div class="td-card-row">
            <span class="td-label">HKS Bildirim Türü</span>
            <span class="td-val"><?= td_h($hks_bildirim_turu) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hks_belge_tipi !== ''): ?>
        <div class="td-card-row">
            <span class="td-label">HKS Belge Tipi</span>
            <span class="td-val"><?= td_h($hks_belge_tipi) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hks_belge_no !== ''): ?>
        <div class="td-card-row">
            <span class="td-label">HKS Belge No</span>
            <span class="td-val"><?= td_h($hks_belge_no) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($hks_kalan !== ''): ?>
        <div class="td-card-row">
            <span class="td-label">Kalan Miktar</span>
            <span class="td-val"><?= td_h($hks_kalan) ?></span>
        </div>
        <?php endif; ?>
    </div>
</details>
<?php endif; ?>

<!-- Actions -->
<div style="display:flex;flex-direction:column;gap:10px;margin:16px 0;">
    <a href="../operasyon/bildirim_form.php?id=<?= $notif_id ?>"
       class="mob-btn mob-btn-outline"
       style="text-align:center;text-decoration:none;padding:12px;">
        Düzenle (Masaüstü Formu)
    </a>
    <div class="mob-btn mob-btn-outline disabled" style="text-align:center;opacity:.5;cursor:not-allowed;padding:12px;">
        Gönderim — Sonraki Sprintte
    </div>
</div>

<p class="mob-sprint-note">Gönderim (BildirimKaydet) bu sprintte aktif değil. Eksik alanları düzenleme ekranından tamamlayın.</p>

<style>
.td-header {
    display:flex; align-items:center; gap:10px; margin-bottom:14px;
    padding-bottom:10px; border-bottom:1px solid #e8edf5;
}
.td-back {
    font-size:15px; color:#1565c0; text-decoration:none; padding:4px; flex-shrink:0;
}
.td-local-no { font-size:14px; font-weight:700; color:#1a2236; flex:1; }
.td-badge {
    font-size:11px; font-weight:700; padding:3px 10px; border-radius:8px; white-space:nowrap;
}
.td-badge-draft  { background:#e5e7eb; color:#374151; }
.td-badge-ready  { background:#d1fae5; color:#065f46; }
.td-badge-sent   { background:#dbeafe; color:#1e40af; }
.td-badge-err    { background:#fee2e2; color:#991b1b; }
.td-alert {
    border-radius:10px; padding:12px 14px; font-size:14px; margin-bottom:12px;
}
.td-alert-ok   { background:#d1fae5; color:#065f46; }
.td-alert-info { background:#e0f2fe; color:#075985; }
.td-missing-box {
    background:#fef9c3; border:1px solid #fde68a; border-radius:10px;
    padding:12px 14px; margin-bottom:12px;
}
.td-missing-title { font-size:12px; font-weight:700; color:#92400e; margin-bottom:6px; }
.td-missing-item  { font-size:13px; color:#78350f; line-height:1.6; }
.td-card {
    background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:12px;
    box-shadow:0 1px 3px rgba(21,101,192,.07);
}
.td-card-row {
    display:flex; justify-content:space-between; align-items:flex-start;
    font-size:13px; padding:6px 0; border-bottom:1px solid #f3f5f9;
}
.td-card-row:last-child { border-bottom:none; }
.td-label { color:#7b8fa8; flex-shrink:0; margin-right:8px; padding-top:1px; min-width:100px; }
.td-val   { color:#1a2236; font-weight:500; text-align:right; word-break:break-all; }
.td-kunye-no { color:#1565c0; font-weight:700; }
.td-hks-src { margin-bottom:12px; }
.td-hks-src-toggle {
    font-size:13px; color:#1565c0; cursor:pointer; padding:6px 0;
    list-style:none; display:flex; align-items:center; gap:6px;
}
.td-hks-src-toggle::-webkit-details-marker { display:none; }
.td-hks-src-toggle::before { content:'▶'; font-size:10px; transition:transform .2s; }
details[open].td-hks-src > .td-hks-src-toggle::before { transform:rotate(90deg); }
</style>

<?php hks_mob_end(); ?>
