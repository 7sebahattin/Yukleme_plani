<?php
/**
 * GitHub Webhook Deploy Script
 * GitHub push → otomatik cPanel'e deploy
 * 
 * Kurulum:
 * 1. Bu dosyayı public_html/yukleme_plani/ klasörüne koy: deploy.php
 * 2. GitHub Repo → Settings → Webhooks → Add webhook
 *    - Payload URL: https://derspros.com.tr/yukleme_plani/deploy.php
 *    - Content type: application/json
 *    - Secret: (boş bırak ya da rasgele string)
 *    - Let me select individual events → Push events seç
 * 3. Push et, otomatik deploy olur
 */

// Webhook'tan gelen JSON'u al
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// Log dosyası
$logfile = __DIR__ . '/deploy.log';

function log_msg($msg) {
    global $logfile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$timestamp] $msg\n", FILE_APPEND);
}

log_msg("=== Webhook tetiklendi ===");

// GitHub'dan event kontrol et
if (!isset($event['repository']['name'])) {
    log_msg("ERROR: Geçersiz GitHub payload");
    http_response_code(400);
    die('Invalid payload');
}

$repo = $event['repository']['name'];
log_msg("Repo: $repo");

// İş dizini (bu script'in bulunduğu yer)
$work_dir = __DIR__;
log_msg("Work dir: $work_dir");

// Git pull çalıştır
log_msg("Git pull başlıyor...");

// Git binary'nin PATH'i (genelde /usr/bin/git)
$output = [];
$return_code = 0;

// Commit bilgisi
if (isset($event['head_commit']['message'])) {
    log_msg("Commit: " . $event['head_commit']['message']);
}
if (isset($event['head_commit']['author']['name'])) {
    log_msg("Author: " . $event['head_commit']['author']['name']);
}

// Eğer .git klasörü varsa git pull yap
if (is_dir($work_dir . '/.git')) {
    log_msg(".git klasörü bulundu, git pull çalıştırılıyor...");
    
    // cd work_dir && git pull
    exec("cd " . escapeshellarg($work_dir) . " && git pull origin main 2>&1", $output, $return_code);
    
    log_msg("Git pull çıkış kodu: $return_code");
    foreach ($output as $line) {
        log_msg("  > $line");
    }
    
    if ($return_code === 0) {
        log_msg("✓ Deploy başarılı!");
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Deployed successfully']);
    } else {
        log_msg("✗ Deploy başarısız (return code: $return_code)");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Deploy failed', 'code' => $return_code]);
    }
} else {
    log_msg("✗ ERROR: .git klasörü bulunamadı");
    log_msg("Kurulum talimatları: cPanel Terminal'de çalıştır:");
    log_msg("  cd /home/derspros/public_html");
    log_msg("  git clone https://github.com/7sebahattin/Yukleme_plani.git yukleme_plani");
    
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '.git folder not found']);
}

log_msg("=== Webhook işlemi tamamlandı ===\n");
?>
