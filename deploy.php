<?php
/**
 * GitHub Webhook Deploy - ZIP İndirme Yöntemi
 * Git gerektirmez. GitHub push → ZIP indir → dosyaları güncelle.
 *
 * Sadece "main" branch push'larında tetiklenir.
 * Korunan dosyalar: deploy.php, deploy.log, config/db.php, .htaccess
 */

$logfile = __DIR__ . '/deploy.log';

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
    // Sunucudaki DB sabitlerini çıkart
    $keys = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    foreach ($keys as $k) {
        if (preg_match("/define\s*\(\s*['\"]$k['\"]\s*,\s*['\"]([^'\"]*)['\"/", $old, $m)) {
            $new_content = preg_replace(
                "/define\s*\(\s*['\"]$k['\"]\s*,\s*['\"][^'\"]*['\"]/",
                "define('$k', '" . addslashes($m[1]) . "'",
                $new_content
            );
        }
    }
    file_put_contents($dest_path, $new_content);
    log_msg('  config/db.php güncellendi (DB bilgileri korundu)');
}

// Payload oku
$payload = (string)file_get_contents('php://input');
if ($payload === '') {
    log_msg('ERROR: Boş payload (php://input okunamadı)');
    http_response_code(400);
    exit('Empty payload');
}

$event = json_decode($payload, true);
if (!isset($event['repository']['full_name'])) {
    log_msg('ERROR: Geçersiz JSON payload — ' . substr($payload, 0, 120));
    http_response_code(400);
    exit('Invalid payload');
}

$repo  = $event['repository']['full_name']; // 7sebahattin/Yukleme_plani
$ref   = $event['ref'] ?? '';               // refs/heads/main
$branch = str_replace('refs/heads/', '', $ref);

log_msg("=== Webhook tetiklendi === repo=$repo branch=$branch");

// Sadece main branch'ini deploy et
if ($branch !== 'main') {
    log_msg("SKIP: '$branch' branch'i deploy edilmez (sadece main).");
    http_response_code(200);
    exit(json_encode(['status' => 'skipped', 'branch' => $branch]));
}

// ---- GitHub'dan ZIP indir ----
$zip_url = "https://github.com/{$repo}/archive/refs/heads/main.zip";
log_msg("ZIP indiriliyor: $zip_url");

$zip_content = false;

// Önce curl dene (daha güvenilir)
if (function_exists('curl_init')) {
    $ch = curl_init($zip_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Deploy-Script/2.0',
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

// Sonra file_get_contents dene
if (!$zip_content) {
    $ctx = stream_context_create(['http' => [
        'timeout'    => 60,
        'user_agent' => 'Deploy-Script/2.0',
    ]]);
    $zip_content = @file_get_contents($zip_url, false, $ctx);
}

if (!$zip_content) {
    log_msg('ERROR: ZIP indirilemedi');
    http_response_code(500);
    exit('Download failed');
}

log_msg('ZIP indirildi: ' . strlen($zip_content) . ' bytes');

// ---- ZIP'i geçici dosyaya yaz ----
$tmp = sys_get_temp_dir() . '/deploy_' . time() . '_' . getmypid() . '.zip';
if (file_put_contents($tmp, $zip_content) === false) {
    log_msg('ERROR: Geçici dosya yazılamadı: ' . $tmp);
    http_response_code(500);
    exit('Temp write failed');
}
unset($zip_content);

// ---- ZIP aç ----
if (!class_exists('ZipArchive')) {
    log_msg('ERROR: ZipArchive PHP eklentisi yüklü değil');
    http_response_code(500);
    @unlink($tmp);
    exit('ZipArchive missing');
}

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    log_msg('ERROR: ZIP açılamadı');
    http_response_code(500);
    @unlink($tmp);
    exit('ZIP open failed');
}

// GitHub ZIP içindeki klasör adı: Yukleme_plani-main/
$repo_name = $event['repository']['name'];   // Yukleme_plani
$prefix    = $repo_name . '-main/';          // Yukleme_plani-main/

// Hiçbir zaman üzerine yazılmaması gereken dosyalar
$protected = [
    'deploy.php',
    'deploy.log',
    '.htaccess',
    // config/db.php → smart_merge_db ile ayrıca işlenir (DB bilgileri korunur)
];

$work_dir = __DIR__;
$updated  = 0;
$skipped  = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);

    // Sadece repo klasörü içindeki dosyaları işle
    if (strpos($entry, $prefix) !== 0) continue;

    $rel = substr($entry, strlen($prefix));
    if ($rel === '') continue;

    // Dizin → oluştur, atla
    if (substr($rel, -1) === '/') {
        $dir = $work_dir . '/' . rtrim($rel, '/');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        continue;
    }

    // Korunan dosya mı?
    if (in_array($rel, $protected, true)) {
        // config/db.php için sadece DB ayarlarını koruyarak geri kalan kısmı güncelle
        if ($rel === 'config/db.php') {
            smart_merge_db($work_dir . '/config/db.php', $zip->getFromIndex($i));
            $updated++;
        } else {
            log_msg("  KORUMA: $rel atlandı");
            $skipped++;
        }
        continue;
    }

    // Hedef dizin yoksa oluştur
    $dest     = $work_dir . '/' . $rel;
    $dest_dir = dirname($dest);
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    // Dosyayı yaz
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
http_response_code(200);
echo json_encode(['status' => 'success', 'updated' => $updated, 'skipped' => $skipped]);
