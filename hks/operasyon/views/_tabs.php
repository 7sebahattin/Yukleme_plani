<?php
declare(strict_types=1);
// HKS Operasyon üst sekmeleri (Bildirimci / Raporlama / Teknik)
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}
$op_dir  = $op_dir  ?? '';
$op_root = $op_root ?? '../';
$op_active_tab = $op_active_tab ?? 'bildirimci';
$op_can_teknik = is_admin() || (function_exists('can') && can('hks.settings'));
?>
<nav class="hks-op-tabs" aria-label="HKS İşlem Sekmeleri">
    <a href="<?= $op_dir ?>bildirim_yeni.php" class="hks-op-tab<?= $op_active_tab === 'bildirimci' ? ' active' : '' ?>">📤 Bildirimci İşlemleri</a>
    <a href="<?= $op_dir ?>stok_sorgu.php"    class="hks-op-tab<?= $op_active_tab === 'raporlama' ? ' active' : '' ?>">📊 Raporlama İşlemleri</a>
    <?php if ($op_can_teknik): ?>
    <a href="<?= $op_root ?>teknik.php"        class="hks-op-tab<?= $op_active_tab === 'teknik' ? ' active' : '' ?>">🛠 HKS Teknik</a>
    <?php endif; ?>
</nav>
