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

// Web isteği → 403. cPanel'de cron'daki `php` çoğu zaman CGI ikilisidir (PHP_SAPI = 'cgi-fcgi'); web isteği olmadığı
// (REQUEST_METHOD yok) sürece o da kabul edilir — eskiden sessizce 403 ile çıkıyor, cron hiç senkron yapmıyordu.
if (PHP_SAPI !== 'cli' && (!str_starts_with(PHP_SAPI, 'cgi') || isset($_SERVER['REQUEST_METHOD']))) {
    http_response_code(403);
    exit('Yalnız komut satırından çalışır.');
}

// db() bağlantı hatasında die() eder (çıkış kodu 0) — cron bunu başarı sanmasın; kalp atışına da yazılır.
$bitti = false;
register_shutdown_function(static function () use (&$bitti): void {
    if ($bitti) return;
    $satir = 'FAIL betik yarıda kesildi (veritabanı bağlantısı / zaman aşımı / PHP hatası)';
    if (function_exists('mail_cron_kalp_yaz')) {
        mail_cron_kalp_yaz(['kod' => 1, 'satirlar' => [$satir]]);
    } else {
        // config/helpers.php daha dahil edilirken DB'ye bağlanır: bağlantı hatası mail kodu YÜKLENMEDEN olur.
        // Sabit metinli (sır içermeyen) yedek kalp atışı — yönetici ekranı "cron çalışıyor ama DB'ye bağlanamıyor" görebilsin.
        $d = defined('MAIL_STORAGE_DIR') ? (string)MAIL_STORAGE_DIR : dirname(__DIR__) . '/storage/mail';
        if (is_dir($d) || @mkdir($d, 0750, true)) {
            $t = $d . '/.cron_kalp.json.' . getmypid() . '.tmp';
            if (@file_put_contents($t, json_encode(['zaman' => time(), 'kod' => 1, 'sapi' => PHP_SAPI, 'php' => PHP_VERSION, 'satirlar' => [$satir]], JSON_UNESCAPED_UNICODE)) !== false) {
                @chmod($t, 0600);
                if (!@rename($t, $d . '/.cron_kalp.json')) @unlink($t);
            }
        }
    }
    echo "\nFAIL betik yarıda kesildi\n"; exit(1);
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
