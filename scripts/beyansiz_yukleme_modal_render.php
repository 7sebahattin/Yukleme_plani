<?php
// scripts/beyansiz_yukleme_modal_render.php — "Yükleme Bildir" penceresini minimal sayfada basar
// (Playwright testi beyansiz_yukleme_modal_smoke.js için). DB/oturum YOK; stub'lar.
//   php scripts/beyansiz_yukleme_modal_render.php [admin] > _test_bsz_modal.html
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ROOT = dirname(__DIR__);
$ADMIN = in_array('admin', $argv, true);
function can_yukleme_bildir(): bool { return true; }
function csrf_token(): string { return 'tok-test'; }
function is_admin(): bool { global $ADMIN; return $ADMIN; }
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Yükleme Bildir testi</title>
<link rel="stylesheet" href="assets/style.css">
</head><body>
<main class="container">
<div class="page-head"><h1>Beyanlar</h1>
<button type="button" class="btn btn-lg bsz-ac-btn" id="bszAc" data-bsz-ac>🚚 Yükleme Bildir</button></div>
<?php include $ROOT . '/_yukleme_bildir_modal.php'; ?>
</main>
<script src="assets/app.js"></script>
</body></html>
