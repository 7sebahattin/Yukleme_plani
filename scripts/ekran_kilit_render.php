<?php
// scripts/ekran_kilit_render.php — ekran_kilit_smoke.js için sayfa üretir (SADECE CLI, DB YOK).
//   php scripts/ekran_kilit_render.php [kilitli] [kiosk] [yok] [degistir] [pasif]  > sayfa.html
//   (argümansız = PIN'i olan, kilitsiz sayfa; kilitli/yok/pasif durum, degistir = sidebar PIN düğmeli)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function base_url(): string { return '/'; }
require_once dirname(__DIR__) . '/config/ekran_kilit.php';

$a = array_slice($argv, 1);
$kiosk = in_array('kiosk', $a, true);
$kilitli = in_array('kilitli', $a, true);
$pinDurum = in_array('pasif', $a, true) ? 'pasif' : (in_array('yok', $a, true) ? 'yok' : 'var');
$sidebar = in_array('degistir', $a, true);

ob_start();
$dlg = ekran_kilit_html(['display_name' => 'Test Kullanıcı', 'username' => 'test'], $pinDurum, $kilitli);
$dugme = $sidebar ? ekran_pin_sidebar_dugme_html($pinDurum) : '';
ob_end_clean();
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="tok123"><title>Kilit testi</title>
<link rel="stylesheet" href="/assets/style.css"></head>
<body class="<?= $kilitli ? 'ekran-kilitli' : '' ?>">
<?php if ($sidebar): ?>
<aside class="desktop-sidebar"><div class="sidebar-user"><div class="sidebar-user-info"><span class="sidebar-user-name">Test</span></div>
<?= $dugme ?>
<a href="/logout.php" class="sidebar-user-logout" title="Çıkış Yap" aria-label="Çıkış Yap">&times;</a></div></aside>
<?php endif; ?>
<main class="container"><h1>İçerik</h1>
<input id="notAlani" type="text" aria-label="not">
<?php if ($kiosk): ?><div class="pdks-kiosk">kiosk</div><?php endif; ?>
</main>
<?= $dlg ?>
<script src="/assets/app.js"></script></body></html>
