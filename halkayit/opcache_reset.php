<?php
// =============================================================================
// HAL KAYIT — OPcache Teşhis & Sıfırlama (tek seferlik yardımcı)
// Canlıda PHP dosya değişikliği etki etmiyorsa (opcache eski derlenmiş kodu
// sunuyorsa) bu sayfa opcache'i sıfırlar. Yeni bir dosya olduğu için kendisi
// opcache'ten etkilenmez. Yalnızca admin erişebilir.
// Kullanım: giriş yaptıktan sonra /halkayit/opcache_reset.php adresini aç.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403);
    die('Bu sayfa yalnızca yöneticiye açıktır.');
}

header('Content-Type: text/plain; charset=utf-8');

// 1) Diskteki hks_soap.php gerçekten güncel mi? (dosya okuması opcache'ten bağımsız)
$soap_path = __DIR__ . '/hks_soap.php';
$src = @file_get_contents($soap_path);
$guncel = ($src !== false)
    && strpos($src, 'function hks_stok_ozet') !== false
    && strpos($src, 'BildirimServisBildirimSorgu') !== false;

echo "== Hal Kayıt — OPcache Teşhis ==\n\n";
echo "hks_soap.php yolu   : {$soap_path}\n";
echo "Dosya değişiklik zm : " . (@filemtime($soap_path) ? date('Y-m-d H:i:s', filemtime($soap_path)) : '?') . "\n";
echo "Diskteki stok kodu  : " . ($guncel ? "GUNCEL (BildirimServisBildirimSorgu)" : "ESKI (ReferansKunyeler) - deploy edilmemis!") . "\n\n";

// 2) OPcache sıfırla
if (function_exists('opcache_reset')) {
    $ok = @opcache_reset();
    echo "opcache_reset()     : " . ($ok ? "OK — önbellek sıfırlandı ✔" : "false (opcache kapalı ya da zaten boş)") . "\n";
} else {
    echo "opcache_reset()     : YOK — bu sunucuda OPcache etkin değil.\n";
}

echo "\nSonuç:\n";
if (!$guncel) {
    echo "  • Diskteki dosya ESKİ. Deploy hks_soap.php'yi güncellememiş.\n";
    echo "    En son 'main' dalını sunucuya çekin / dosyayı yeniden yükleyin.\n";
} else {
    echo "  • Dosya güncel. OPcache sıfırlandıysa Stok Sorgulama artık çalışır.\n";
    echo "    Panele dönüp 'Stok Sorgulama' → 'Yenile' deneyin.\n";
}
