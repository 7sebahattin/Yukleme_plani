<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { http_response_code(403); exit('Yetkisiz'); }

header('Content-Type: text/plain; charset=utf-8');

// 1. Dosya disk üzerinde yeni mi?
$marker = strpos(file_get_contents(__DIR__ . '/hesap_kayit.php'), 'HESAP_TOKEN_REMOVED_V2') !== false;
echo "MARKER_ON_DISK: " . ($marker ? "YENİ KOD ✓" : "ESKİ KOD ✗") . "\n";

// 2. Opcache durumu
if (function_exists('opcache_get_status')) {
    $s = opcache_get_status(false);
    echo "OPCACHE_ENABLED: " . ($s['opcache_enabled'] ? 'evet' : 'hayır') . "\n";
    echo "CACHED_SCRIPTS: " . ($s['opcache_statistics']['num_cached_scripts'] ?? '?') . "\n";
} else {
    echo "OPCACHE: yüklü değil\n";
}

// 3. Opcache temizle
if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo "OPCACHE_RESET: " . ($ok ? "temizlendi ✓" : "başarısız ✗") . "\n";
} else {
    echo "OPCACHE_RESET: fonksiyon yok\n";
}

// 4. Session flash'ta eski mesaj var mı?
if (session_status() === PHP_SESSION_NONE) session_start();
$flash = $_SESSION['flash'] ?? null;
echo "SESSION_FLASH: " . ($flash ? json_encode($flash) : "yok") . "\n";
if (isset($_SESSION['flash'])) {
    unset($_SESSION['flash']);
    echo "SESSION_FLASH_CLEARED: evet\n";
}

// 5. hesap_kayit.php'de token izi var mı?
$src = file_get_contents(__DIR__ . '/hesap_kayit.php');
$checks = ['form_token', 'hesap_form_token', 'used_hesap', 'zaten işleme', 'idempotency'];
foreach ($checks as $c) {
    echo "KOD_IZI '$c': " . (strpos($src, $c) !== false ? "VAR ✗" : "yok ✓") . "\n";
}

echo "\nTAMMAM — Bu sayfayı sil: unlink veya sunucudan kaldır.\n";
