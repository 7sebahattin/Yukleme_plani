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

include __DIR__ . '/_layout.php';
hks_mob_start('Müşteri', 'musteriler');

$people_icon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
?>

<div class="mob-list-empty">
    <?= $people_icon ?>
    <p>Müşteri listesi boş</p>
</div>

<div class="mob-bottom-actions">
    <button class="mob-import-btn disabled" disabled title="Sonraki sprintte">İçeri aktar</button>
</div>

<button class="mob-fab" onclick="alert('Müşteri ekleme sonraki sprintte aktif olacak.')" title="Müşteri ekle (sonraki sprint)">+</button>

<?php hks_mob_end(); ?>
