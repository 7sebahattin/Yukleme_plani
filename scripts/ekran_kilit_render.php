<?php
// scripts/ekran_kilit_render.php — ekran_kilit_smoke.js için sayfa üretir (SADECE CLI, DB YOK).
//   php scripts/ekran_kilit_render.php [kilitli] [kiosk]  > sayfa.html
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function base_url(): string { return '/'; }
require_once dirname(__DIR__) . '/config/ekran_kilit.php';
ob_start();
if (in_array('kilitli', $argv, true)) { session_id('ekrankilitrender'); session_start(); $_SESSION['ekran_kilitli'] = 1; }
$kiosk = in_array('kiosk', $argv, true);
$kilitli = in_array('kilitli', $argv, true);
$dlg = ekran_kilit_html(['display_name' => 'Test Kullanıcı', 'username' => 'test']);
ob_end_clean();
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="tok123"><title>Kilit testi</title>
<link rel="stylesheet" href="/assets/style.css"></head>
<body class="<?= $kilitli ? 'ekran-kilitli' : '' ?>">
<main class="container"><h1>İçerik</h1>
<input id="notAlani" type="text" aria-label="not">
<?php if ($kiosk): ?><div class="pdks-kiosk">kiosk</div><?php endif; ?>
</main>
<?= $dlg ?>
<script src="/assets/app.js"></script></body></html>
