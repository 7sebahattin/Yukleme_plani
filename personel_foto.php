<?php
// =========================================================
// personel_foto.php — Personel fotoğrafını diskten servis eder
//
// Dosyanın DB'de var olması yetmez — bir `employees` satırının GERÇEKTEN
// o dosyayı işaret ettiği doğrulanır (hesap_dosya.php'deki B5 kuralının
// aynısı: dosya adını bilmek erişim vermez).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks('employees');

$fn = trim($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $fn)) {
    http_response_code(400);
    exit('Geçersiz dosya adı');
}

$st = db()->prepare("SELECT id, depo FROM employees WHERE photo_file = ? LIMIT 1");
$st->execute([$fn]);
$calisan = $st->fetch();
if (!$calisan) {
    http_response_code(404);
    exit('Fotoğraf bulunamadı');
}
// Aktif depo kapsamı (record_view.php/kantar_view.php İLE AYNI desen) —
// başka deponun personelinin fotoğrafı, dosya adı bilinse bile açılamaz.
$_pfo_depo = trim((string)($calisan['depo'] ?? ''));
if ($_pfo_depo !== '' && function_exists('depot_visible_to_user') && !depot_visible_to_user($_pfo_depo)) {
    http_response_code(403);
    exit('Bu personel başka depoya ait');
}

$path = PDKS_FOTO_DIR . $fn;
if (!is_file($path)) {
    http_response_code(404);
    exit('Fotoğraf bulunamadı');
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);
