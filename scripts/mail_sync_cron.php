<?php
// =========================================================
// scripts/mail_sync_cron.php — Mail Merkezi IMAP senkronu (CLI / cron)
//
// cPanel → Cron Jobs (kullanıcıdan SSH istenmez), örnek her 5 dakikada:
//   */5 * * * * php /home/<hesap>/<site-klasoru>/scripts/mail_sync_cron.php >> /dev/null 2>&1
//
// • YALNIZ komut satırından çalışır (HTTP'den 403; scripts/ zaten .htaccess ile kapalı).
// • Aynı anda tek örnek (flock); ikinci çalıştırma BUSY döner.
// • Bir hesap bozulursa diğerleri çalışır. Çıkış kodu 1 yalnız hiçbir hesap başarılı değilse
//   (ya da tablo/anahtar eksikse); satırlar: OK / FAIL / BUSY — şifre/gövde ASLA yazılmaz.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Yalnız komut satırından çalışır.');
}

// db() bağlantı hatasında die() eder (çıkış kodu 0) — cron bunu başarı sanmasın.
$bitti = false;
register_shutdown_function(static function () use (&$bitti): void {
    if (!$bitti) { echo "\nFAIL betik yarıda kesildi\n"; exit(1); }
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/mail_core.php';
require_once __DIR__ . '/../config/mail_imap.php';
require_once __DIR__ . '/../config/mail_mime.php';
require_once __DIR__ . '/../config/mail_sync.php';
require_once __DIR__ . '/../config/mail_view.php';
require_once __DIR__ . '/../config/mail_translate.php';
require_once __DIR__ . '/../config/mail_smtp.php';
require_once __DIR__ . '/../config/mail_outbox.php';

set_time_limit(280);
$r = mail_cron_calistir(db());
$bitti = true;
echo implode("\n", $r['satirlar']) . "\n";
exit($r['kod']);
