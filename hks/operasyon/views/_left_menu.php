<?php
declare(strict_types=1);
// HKS Operasyon sol menüsü — aktif sekmeye göre değişir
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}
$op_dir  = $op_dir  ?? '';
$op_root = $op_root ?? '../';
$op_active_tab  = $op_active_tab  ?? 'bildirimci';
$op_active_menu = $op_active_menu ?? '';

// Menü yapısı: [grup başlığı => [dosya => [etiket, ikon, prefix, soon?]]]
$op_menus = [
    'bildirimci' => [
        'Bildirimci İşlemleri' => [
            'bildirim_yeni.php'    => ['e-Bildirim', '📝', $op_dir],
            'bildirim_sorgu.php'   => ['Bildirim Sorgulama / İptal', '🔎', $op_dir],
            'bildirim_etiket.php'  => ['Bildirim Etiket Basım', '🏷', $op_dir, true],
            'kunye_sorgu.php'      => ['Künye Sorgulama', '🔖', $op_dir],
            'son_bildirimler.php'  => ['Son Bildirimler', '🗂', $op_dir],
        ],
    ],
    'raporlama' => [
        'Raporlama İşlemleri' => [
            'stok_sorgu.php'       => ['Stok Sorgulama', '📦', $op_dir],
            'satis_bordrosu.php'   => ['Satış Bordrosu', '🧾', $op_dir, true],
            'rusum_sorgu.php'      => ['Rüsum Sorgulama', '💱', $op_dir, true],
            'coklu_kunye.php'      => ['Bildirim Çoklu Künye Basım', '🏷', $op_dir, true],
        ],
    ],
    'teknik' => [
        'HKS Teknik / Yönetim' => [
            'ayarlar.php'          => ['Ayarlar', '⚙️', $op_root],
            'baglanti_test.php'    => ['Bağlantı Testi', '🔌', $op_root],
            'referanslar.php'      => ['Referanslar', '📚', $op_root],
            'veri_cek.php'         => ['Veri Çek', '📡', $op_root],
            'servis_loglari.php'   => ['Servis Logları', '🧾', $op_root],
            'teshis.php'           => ['Teşhis', '🔬', $op_root],
        ],
    ],
];

$groups = $op_menus[$op_active_tab] ?? $op_menus['bildirimci'];
?>
<nav class="hks-op-menu" aria-label="HKS Sol Menü">
<?php foreach ($groups as $group_label => $items): ?>
    <div class="hks-op-menu-group"><?= hks_h($group_label) ?></div>
    <?php foreach ($items as $file => $meta):
        [$label, $icon, $prefix] = [$meta[0], $meta[1], $meta[2]];
        $soon   = !empty($meta[3]);
        $active = ($op_active_menu === $file) ? ' active' : '';
        if ($soon): ?>
        <a class="disabled" title="Bu işlem sonraki sprintte eklenecek">
            <span><?= $icon ?></span> <span><?= hks_h($label) ?></span>
            <span class="soon">yakında</span>
        </a>
    <?php else: ?>
        <a href="<?= $prefix . hks_h($file) ?>" class="<?= trim($active) ?>">
            <span><?= $icon ?></span> <span><?= hks_h($label) ?></span>
        </a>
    <?php endif; endforeach; ?>
<?php endforeach; ?>
</nav>
