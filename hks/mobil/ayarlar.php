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

$is_hks_admin = is_admin() || (function_exists('can') && can('hks.settings'));

include __DIR__ . '/_layout.php';
hks_mob_start('Ayarlar', 'ayarlar');

// SVG icon helpers
function si(string $d): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

$items = [
    [
        'label'  => 'Bildirimci Tanımları',
        'icon'   => si('<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/>'),
        'href'   => '../firmalar.php',
        'admin'  => false,
    ],
    [
        'label'  => 'Firma Tanımları',
        'icon'   => si('<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>'),
        'href'   => '../index.php',
        'admin'  => false,
    ],
    [
        'label'  => 'Son Yapılan Bildirimler',
        'icon'   => si('<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'),
        'href'   => '../operasyon/son_bildirimler.php',
        'admin'  => false,
    ],
    [
        'label'  => 'Ürünleri Güncelle',
        'icon'   => si('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'),
        'href'   => '../veri_cek.php?type=urun',
        'admin'  => true,
    ],
    [
        'label'  => 'Sıfatları Güncelle',
        'icon'   => si('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'),
        'href'   => '../veri_cek.php?type=sifat',
        'admin'  => true,
    ],
    [
        'label'  => 'İl, İlçe ve Beldeleri Güncelle',
        'icon'   => si('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'),
        'href'   => '../veri_cek.php?type=il',
        'admin'  => true,
    ],
    [
        'label'  => 'Hakkında',
        'icon'   => si('<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'),
        'href'   => '../teknik.php',
        'admin'  => false,
    ],
];

$chevron = si('<polyline points="9 18 15 12 9 6"/>');
?>

<div class="mob-settings-list">
<?php foreach ($items as $item):
    if ($item['admin'] && !$is_hks_admin) continue;
?>
    <a class="mob-settings-item" href="<?= hks_mob_h($item['href']) ?>">
        <span class="mob-settings-icon"><?= $item['icon'] ?></span>
        <span class="mob-settings-label"><?= hks_mob_h($item['label']) ?></span>
        <span class="mob-settings-chevron"><?= $chevron ?></span>
    </a>
<?php endforeach; ?>
</div>

<div class="mob-version">
    Sürüm 1.0 · HKS Mobil<br>
    <span style="font-size:11px;"><?= hks_mob_h($op_company_name) ?></span>
</div>

<?php hks_mob_end(); ?>
