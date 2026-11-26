<?php
// =========================================================
// scripts/db_backup_cron.php — Günlük DB yedeği (CLI / cron)
// Sprint DB-Backup-02
//
// Otomatik yedek normalde 17:00 sonrası ilk admin ana sayfa açılışında
// alınır; hiçbir admin girmezse o gün yedek olmaz. Bu betik aynı işi
// sayfa isteğinden bağımsız yapar (opsiyonel — sunucuya kurulması şart değil).
//
//   php scripts/db_backup_cron.php          → bugün başarılı yedek yoksa al
//   php scripts/db_backup_cron.php --force  → her durumda yeni yedek al
//
// Kilit (.backup.lock) sayfa ile paylaşılır; otomatik deneme freni
// (3 deneme / 30 dk) UYGULANMAZ, 17:00 kuralı YOKTUR.
// Çıktı: "OK <dosya> <boyut>" (exit 0) · "BUSY" / "SKIP" (exit 0) · "FAIL <hata>" (exit 1)
//
// Önerilen cPanel Cron (her gün 03:15; aktif edildiğinde config/local.php'de
// define('DB_BACKUP_CRON_ONLY', true); tanımlanarak web tetiklemesi kapatılabilir):
//   15 3 * * * /usr/local/bin/php /home/derspros/asya.scai.tr/scripts/db_backup_cron.php >> /home/derspros/asya.scai.tr/storage/backups/cron.log 2>&1
// Opsiyonel off-site (Cloudflare R2 / S3 — rclone ile şifreli kopya):
//   30 3 * * * /usr/bin/rclone copy /home/derspros/asya.scai.tr/storage/backups/db remote:asyafresh-backups/db --include "*.sql.gz" --max-age 48h
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Yalnız komut satırından çalışır.');
}

// db() bağlantı hatasında die() eder (çıkış kodu 0) — cron bunu başarı
// sanmasın: sonuç yazılmadan süreç biterse FAIL + exit 1.
$bitti = false;
register_shutdown_function(static function () use (&$bitti): void {
    if (!$bitti) {
        echo "\nFAIL betik yarıda kesildi\n";
        exit(1);
    }
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db_backup_helpers.php';

$force = in_array('--force', $argv ?? [], true);

$r = create_database_backup(db(), 0, 'cron', !$force);
$bitti = true;

if (!empty($r['busy'])) {
    echo "BUSY\n";
    exit(0);
}
if (!empty($r['skipped'])) {
    echo "SKIP bugün başarılı yedek zaten var\n";
    exit(0);
}
if ($r['ok']) {
    echo 'OK ' . $r['filename'] . ' ' . _bh_fmt_size($r['size']) . (!empty($r['note']) ? ' (' . $r['note'] . ')' : '') . "\n";
    exit(0);
}
echo 'FAIL ' . ($r['error'] ?? 'bilinmeyen hata') . "\n";
exit(1);
