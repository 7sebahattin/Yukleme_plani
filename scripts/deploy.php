<?php
/**
 * GitHub Webhook Deploy - ZIP İndirme Yöntemi
 * WEB ERİŞİMİ KAPALI — scripts/.htaccess ile korunmaktadır.
 *
 * Bu dosyanın web üzerinden erişilebilir olması ciddi güvenlik riski oluşturur.
 * Webhook kurmak için sunucunuzda SSH ile çalıştırın veya
 * ayrı bir güvenli endpoint oluşturun (HMAC imza doğrulaması ile).
 *
 * Git gerektirmez. GitHub push → ZIP indir → dosyaları güncelle.
 * Sadece "main" branch push'larında tetiklenir.
 * Korunan dosyalar: deploy.php, deploy.log, config/db.php, .htaccess
 */

$work_dir = dirname(__DIR__);
$logfile  = $work_dir . '/deploy.log';

function log_msg(string $msg): void {
    global $logfile;
    file_put_contents($logfile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * config/db.php'yi günceller ama DB kimlik bilgilerini (HOST/NAME/USER/PASS) korur.
 */
function smart_merge_db(string $dest_path, string $new_content): void {
    if (!file_exists($dest_path)) {
        file_put_contents($dest_path, $new_content);
        log_msg('  config/db.php oluşturuldu (yeni)');
        return;
    }
    $old = file_get_contents($dest_path);
    $keys = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    foreach ($keys as $k) {
        if (preg_match('/const\s+' . $k . '\s*=\s*\'([^\']*)\';/', $old, $m)) {
            $new_content = preg_replace(
                '/const\s+' . $k . '\s*=\s*\'[^\']*\';/',
                "const $k = '" . addslashes($m[1]) . "';",
                $new_content
            );
        }
    }
    file_put_contents($dest_path, $new_content);
    log_msg('  config/db.php güncellendi (DB bilgileri korundu)');
}

// CLI'den mi çalışıyor kontrol et
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$repo   = $argv[1] ?? '7sebahattin/Yukleme_plani';
$branch = $argv[2] ?? 'main';

log_msg("=== Manuel deploy başlatıldı === repo=$repo branch=$branch");

$zip_url = "https://github.com/{$repo}/archive/refs/heads/{$branch}.zip";
log_msg("ZIP indiriliyor: $zip_url");

$zip_content = false;
if (function_exists('curl_init')) {
    $ch = curl_init($zip_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Deploy-Script/3.0',
        CURLOPT_TIMEOUT        => 60,
    ]);
    $zip_content = curl_exec($ch);
    $curl_err    = curl_error($ch);
    curl_close($ch);
    if (!$zip_content) {
        log_msg("curl ERROR: $curl_err");
        $zip_content = false;
    }
}

if (!$zip_content) {
    log_msg('ERROR: ZIP indirilemedi');
    exit(1);
}

log_msg('ZIP indirildi: ' . strlen($zip_content) . ' bytes');

$tmp = sys_get_temp_dir() . '/deploy_' . time() . '.zip';
if (file_put_contents($tmp, $zip_content) === false) {
    log_msg('ERROR: Geçici dosya yazılamadı');
    exit(1);
}
unset($zip_content);

if (!class_exists('ZipArchive')) {
    log_msg('ERROR: ZipArchive PHP eklentisi yüklü değil');
    @unlink($tmp);
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    log_msg('ERROR: ZIP açılamadı');
    @unlink($tmp);
    exit(1);
}

$repo_name = basename($repo);
$prefix    = $repo_name . '-' . $branch . '/';
$protected = ['deploy.php', 'deploy.log', '.htaccess', 'scripts/.htaccess'];
$updated   = 0;
$skipped   = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    if (strpos($entry, $prefix) !== 0) continue;
    $rel = substr($entry, strlen($prefix));
    if ($rel === '') continue;
    if (substr($rel, -1) === '/') {
        $dir = $work_dir . '/' . rtrim($rel, '/');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        continue;
    }
    if (in_array($rel, $protected, true)) {
        if ($rel === 'config/db.php') {
            smart_merge_db($work_dir . '/config/db.php', $zip->getFromIndex($i));
            $updated++;
        } else {
            log_msg("  KORUMA: $rel atlandı");
            $skipped++;
        }
        continue;
    }
    $dest     = $work_dir . '/' . $rel;
    $dest_dir = dirname($dest);
    if (!is_dir($dest_dir)) mkdir($dest_dir, 0755, true);
    $content = $zip->getFromIndex($i);
    if (file_put_contents($dest, $content) !== false) {
        $updated++;
    } else {
        log_msg("  HATA: $rel yazılamadı");
    }
}

$zip->close();
@unlink($tmp);

log_msg("✓ Deploy tamamlandı: $updated dosya güncellendi, $skipped dosya atlandı");
echo "Deploy tamamlandı: $updated güncellendi, $skipped atlandı\n";
