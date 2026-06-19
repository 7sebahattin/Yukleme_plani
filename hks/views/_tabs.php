<?php
// HKS modülü üst sekme navigasyonu
// $hks_active_tab değişkeni ile aktif sekme belirtilmeli
declare(strict_types=1);
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}
$_hks_tab_base = '../hks/';
$_hks_cur = basename($_SERVER['PHP_SELF'] ?? '');
$_hks_tabs = [
    'index.php'          => ['icon' => '🏛', 'label' => 'Panel'],
    'ayarlar.php'        => ['icon' => '⚙️',  'label' => 'Ayarlar'],
    'referanslar.php'    => ['icon' => '📚', 'label' => 'Referanslar'],
    'bildirimler.php'    => ['icon' => '📋', 'label' => 'Bildirimler'],
    'bildirim_form.php'  => ['icon' => '➕', 'label' => 'Yeni Bildirim'],
    'stok.php'           => ['icon' => '📦', 'label' => 'Stok Kontrol'],
    'kunye_sorgu.php'    => ['icon' => '🔍', 'label' => 'Künye / Sorgu'],
    'veri_cek.php'       => ['icon' => '📡', 'label' => 'Veri Çek'],
    'servis_loglari.php' => ['icon' => '🧾', 'label' => 'Servis Logları'],
    'teshis.php'         => ['icon' => '🔬', 'label' => 'Teşhis'],
];
$_hks_admin_only = ['ayarlar.php', 'referanslar.php', 'teshis.php'];

// Seçili firma adı
$_hks_company_name = '';
if (isset($_SESSION['hks_company_id'])) {
    $_hks_co = (new HksRepository(db()))->getSettings((int)$_SESSION['hks_company_id']);
    $_hks_company_name = $_hks_co['firma_adi'] ?? '';
}
?>
<?php if ($_hks_company_name !== ''): ?>
<div class="hks-company-bar">
    <span class="hks-company-bar-name">🏢 <?= hks_h($_hks_company_name) ?></span>
    <a href="index.php?switch=1" class="hks-company-bar-switch">Firma Değiştir ⇄</a>
</div>
<?php endif; ?>
<nav class="hks-tabs" aria-label="HKS Sekmeler">
<?php foreach ($_hks_tabs as $_hks_file => $_hks_tab):
    if (in_array($_hks_file, $_hks_admin_only, true) && !is_admin() && !(function_exists('can') && can('hks.settings'))) continue;
    $active = ($_hks_cur === $_hks_file || ($hks_active_tab ?? '') === $_hks_file) ? ' active' : '';
?>
    <a href="<?= hks_h($_hks_file) ?>" class="hks-tab<?= $active ?>" title="<?= hks_h($_hks_tab['label']) ?>">
        <span><?= $_hks_tab['icon'] ?></span>
        <span class="hks-tab-label"><?= hks_h($_hks_tab['label']) ?></span>
    </a>
<?php endforeach; ?>
</nav>
