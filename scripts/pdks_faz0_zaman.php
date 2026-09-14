<?php
// =========================================================
// scripts/pdks_faz0_zaman.php — FAZ 0 · PHP ↔ MySQL saat dilimi ÖLÇÜMÜ
//
// SADECE CLI. SALT OKUNUR: yalnız SELECT çalıştırır, hiçbir şey yazmaz,
// hiçbir şema değiştirmez, hiçbir ayar değiştirmez.
//
//   php scripts/pdks_faz0_zaman.php
//
// ⚠ config/db.php BİLEREK include EDİLMEZ.
//    Sebep: config/db.php → helpers.php → dosya sonundaki IIFE → db() zinciri
//    açılışta ALTER/CREATE TABLE çalıştırır. "Salt okunur ölçüm" iddiasıyla
//    çelişirdi. Bu yüzden kimlik bilgileri db.php'den REGEX ile OKUNUR,
//    dosya hiç çalıştırılmaz ve bağlantı burada ayrıca açılır.
//
// Bu betiğe erişimi olmayan (SSH'sız) kurulumlarda AYNI ölçüm phpMyAdmin'den
// tek SQL ile yapılabilir — bkz. docs/PDKS_NFC_FAZ0_DOGRULAMA.md §1.
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$dbFile = dirname(__DIR__) . '/config/db.php';
$src    = @file_get_contents($dbFile);
if ($src === false) { fwrite(STDERR, "config/db.php okunamadı: $dbFile\n"); exit(2); }

function sabit_oku(string $src, string $ad, string $vars = ''): string {
    if (preg_match("/const\s+{$ad}\s*=\s*'([^']*)'/", $src, $m)) return $m[1];
    return $vars;
}
$host = sabit_oku($src, 'DB_HOST', 'localhost');
$name = sabit_oku($src, 'DB_NAME');
$user = sabit_oku($src, 'DB_USER');
$pass = sabit_oku($src, 'DB_PASS');
$chr  = sabit_oku($src, 'DB_CHARSET', 'utf8mb4');

// PHP tarafı — uygulamanın kullandığı ayarın aynısı (config/db.php:9)
date_default_timezone_set('Europe/Istanbul');
$phpSimdi = new DateTimeImmutable('now');
$phpUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));

echo "\n=========== PHP TARAFI ===========\n";
printf("%-34s %s\n", 'PHP sürümü',                PHP_VERSION);
printf("%-34s %s\n", 'date_default_timezone_get', date_default_timezone_get());
printf("%-34s %s\n", 'PHP şimdi (Europe/Istanbul)', $phpSimdi->format('Y-m-d H:i:s'));
printf("%-34s %s\n", 'PHP şimdi (UTC)',           $phpUtc->format('Y-m-d H:i:s'));
printf("%-34s %s\n", 'PHP UTC offset',            $phpSimdi->format('P'));
printf("%-34s %s\n", 'Yaz saati (DST) etkin mi',  $phpSimdi->format('I') === '1' ? 'EVET' : 'hayır');
printf("%-34s %s\n", 'İşletim sistemi TZ (env)',  getenv('TZ') !== false ? getenv('TZ') : '(ayarlı değil)');
if (is_readable('/etc/timezone')) printf("%-34s %s\n", '/etc/timezone', trim((string)file_get_contents('/etc/timezone')));
printf("%-34s %s\n", 'bcmath / gmp',              (extension_loaded('bcmath') ? 'bcmath ' : '') . (extension_loaded('gmp') ? 'gmp' : '') ?: 'İKİSİ DE YOK');

try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$chr}", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "\nMySQL'e bağlanılamadı: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Bu ortamda canlı DB yoksa normaldir — ölçümü sunucuda çalıştırın\n");
    fwrite(STDERR, "veya phpMyAdmin'den docs/PDKS_NFC_FAZ0_DOGRULAMA.md §1'deki SQL'i kullanın.\n\n");
    exit(3);
}

$r = $pdo->query("SELECT
    NOW()                 AS mysql_now,
    UTC_TIMESTAMP()       AS mysql_utc,
    CURDATE()             AS mysql_bugun,
    @@session.time_zone   AS oturum_tz,
    @@global.time_zone    AS global_tz,
    @@system_time_zone    AS sistem_tz,
    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS now_utc_fark_sn,
    VERSION()             AS surum")->fetch();

echo "\n=========== MySQL TARAFI ===========\n";
printf("%-34s %s\n", 'VERSION()',            $r['surum']);
printf("%-34s %s\n", 'NOW()',                $r['mysql_now']);
printf("%-34s %s\n", 'UTC_TIMESTAMP()',      $r['mysql_utc']);
printf("%-34s %s\n", 'CURDATE()',            $r['mysql_bugun']);
printf("%-34s %s\n", '@@session.time_zone',  $r['oturum_tz']);
printf("%-34s %s\n", '@@global.time_zone',   $r['global_tz']);
printf("%-34s %s\n", '@@system_time_zone',   $r['sistem_tz']);
printf("%-34s %s sn (%+d saat)\n", 'NOW() − UTC_TIMESTAMP()', $r['now_utc_fark_sn'], intdiv((int)$r['now_utc_fark_sn'], 3600));

// ── Asıl soru: PHP Europe/Istanbul ile MySQL NOW() AYNI gerçek anı mı gösteriyor?
$phpTs   = $phpSimdi->getTimestamp();
$mysqlTs = (new DateTimeImmutable($r['mysql_now'], new DateTimeZone('Europe/Istanbul')))->getTimestamp();
$sapma   = $mysqlTs - $phpTs;

echo "\n=========== KARŞILAŞTIRMA ===========\n";
printf("%-34s %s\n", 'PHP  Europe/Istanbul',  $phpSimdi->format('Y-m-d H:i:s'));
printf("%-34s %s\n", 'MySQL NOW()',           $r['mysql_now']);
printf("%-34s %+d saniye\n", 'SAPMA (MySQL − PHP)', $sapma);

echo "\n=========== SONUÇ ===========\n";
if (abs($sapma) <= 5) {
    echo "✓ AYNI ANI GÖSTERİYORLAR (|sapma| ≤ 5 sn — ölçüm gecikmesi payı).\n";
    echo "  KARAR: Seçenek A — Faz 1 MySQL NOW() kullanmaya DEVAM etsin.\n";
    echo "  Uygulamanın geri kalanıyla (kantar, yükleme, hesap, audit) tam tutarlı olur.\n";
    $cikis = 0;
} else {
    printf("✗ SAPMA VAR: %+d saniye (≈ %+.1f saat).\n", $sapma, $sapma / 3600);
    echo "  MySQL NOW() ile PHP Europe/Istanbul AYNI anı göstermiyor.\n";
    echo "  KARAR: PDKS zaman damgaları NOW() ile yazılmamalı — bkz.\n";
    echo "  docs/PDKS_NFC_FAZ0_DOGRULAMA.md §1.4 (Seçenek C).\n";
    echo "  ⚠ Bu sapma PDKS'e ÖZGÜ DEĞİLDİR: mevcut tüm modüller de etkilenir.\n";
    echo "    Global düzeltme (SET time_zone) AYRI bir iş olarak ele alınmalıdır.\n";
    $cikis = 1;
}
echo "\n";
exit($cikis);
