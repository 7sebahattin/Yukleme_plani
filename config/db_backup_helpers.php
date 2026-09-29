<?php
// =========================================================
// config/db_backup_helpers.php — Günlük DB Yedekleme Sistemi
// Sprint DB-Backup-01 · DB-Backup-02 (sağlamlaştırma)
//
// Gereksinimler:
//   require_once __DIR__ . '/config/db.php';    (DB_HOST/NAME/USER/PASS)
//   require_once __DIR__ . '/config/auth.php';
//   require_once __DIR__ . '/config/db_backup_helpers.php';
//
// Public API:
//   db_backup_dir()                                        : string
//   ensure_db_backup_dir()                                 : void
//   create_database_backup(pdo,uid,trigger,skipIfDoneToday): array
//   should_create_daily_backup(pdo, now)                   : bool
//   create_daily_backup_if_needed(pdo, uid, now)           : ?array
//   list_database_backups(pdo, limit, status)              : array
//   last_successful_backup(pdo)                            : ?array
//   cleanup_old_database_backups(pdo, keepDays, minKeep)   : void
//
// Kurallar (bkz. CLAUDE.md "Veritabanı Yedekleri"):
//   - database_backups şeması DONDURULMUŞTUR (status yalnız success/failed).
//     "Çalışıyor / bugün denendi" bilgisi klasördeki .backup.lock ve
//     .auto_state.json dosyalarında tutulur.
//   - Yedek önce <ad>.part'a yazılır, açılıp sonuna kadar okunarak
//     doğrulanır ("-- Dump completed" alt satırı), sonra yeniden adlandırılır.
//     mysqldump'a --compact / --skip-comments EKLEME — alt satır kaybolur,
//     her yedek "eksik" sayılır.
//   - Aynı anda tek yedek: flock (.backup.lock). Otomatik yedek günde en
//     çok 3 kez, denemeler arası en az 30 dk.
// =========================================================
declare(strict_types=1);

const DB_BACKUP_KEEP_DAYS     = 14;        // saklama süresi (gün)
const DB_BACKUP_MIN_KEEP      = 3;         // en yeni N başarılı yedek HER ZAMAN korunur
const DB_BACKUP_AUTO_MAX_TRY  = 3;         // otomatik yedek: günde en çok deneme
const DB_BACKUP_AUTO_WAIT_SN  = 1800;      // otomatik yedek: denemeler arası bekleme
const DB_BACKUP_STALE_SAAT    = 36;        // son başarılı yedek bundan eskiyse uyarı
const DB_BACKUP_MIN_FREE      = 52428800;  // 50 MB — disk ön kontrolü alt sınırı
const DB_BACKUP_INSERT_BAYT   = 1048576;   // PDO döküm: çok satırlı INSERT üst boyutu
const DB_BACKUP_INSERT_SATIR  = 500;       // PDO döküm: çok satırlı INSERT üst satır sayısı

// ── Yedek klasörü (web root içi + .htaccess korumalı) ─────
// Testler DB_BACKUP_DIR_OVERRIDE ile geçici klasör verir.
function db_backup_dir(): string {
    if (defined('DB_BACKUP_DIR_OVERRIDE')) return (string)constant('DB_BACKUP_DIR_OVERRIDE');
    return dirname(__DIR__) . '/storage/backups/db';
}

// Ekranda gösterilecek (göreli) klasör adı — sunucunun mutlak yolu basılmaz.
function _bh_display_dir(): string {
    $dir  = db_backup_dir();
    $root = dirname(__DIR__) . '/';
    return str_starts_with($dir, $root) ? substr($dir, strlen($root)) : ('…/' . basename($dir));
}

// Dosya adından güvenli tam yol (file_path kolonuna GÜVENİLMEZ — yalnız ad).
function _bh_backup_path(string $filename): string {
    return db_backup_dir() . '/' . basename($filename);
}

// .htaccess içeriği — halkayit/.htaccess ile aynı çift sözdizimi:
// Apache 2.4 'Require all denied', 2.2 'Order/Deny' (mod_authz_core yoksa).
function _bh_htaccess_icerik(): string {
    return "# Yedek klasörü — web'den erişim tamamen kapalı (config/db_backup_helpers.php)\n"
        . "Options -Indexes\n"
        . "Require all denied\n"
        . "\n"
        . "# Apache 2.2 geriye dönük uyumluluk\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n";
}

// ── Klasör + .htaccess güvenlik dosyalarını oluştur ────────
function ensure_db_backup_dir(): void {
    $dir = db_backup_dir();
    if (defined('DB_BACKUP_DIR_OVERRIDE')) {
        $dirs = [$dir];
    } else {
        $root = dirname(__DIR__) . '/storage';
        $dirs = [$root, "$root/backups", $dir];
    }
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        if (!is_dir($d)) continue;
        $ht  = $d . '/.htaccess';
        $cur = is_file($ht) ? (string)@file_get_contents($ht) : '';
        if (!str_contains($cur, 'Require all denied')) {
            @file_put_contents($ht, _bh_htaccess_icerik());
        }
    }
    if (is_dir($dir)) @chmod($dir, 0750);
}

// ── Backup tablosunu garantile ────────────────────────────
function ensure_db_backup_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `database_backups` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `backup_date`   DATE NOT NULL,
        `filename`      VARCHAR(255) NOT NULL,
        `file_path`     TEXT NOT NULL,
        `file_size`     BIGINT NULL,
        `method`        VARCHAR(50) NULL,
        `status`        ENUM('success','failed') NOT NULL DEFAULT 'success',
        `error_message` TEXT NULL,
        `created_by`    INT NULL,
        `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `downloaded_at` DATETIME NULL,
        `downloaded_by` INT NULL,
        INDEX `idx_bkp_date`   (`backup_date`),
        INDEX `idx_bkp_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Shell araç kontrolü ───────────────────────────────────
function _bh_fn_ok(string $fn): bool {
    if (!function_exists($fn)) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array($fn, $dis, true);
}

function _bh_find_mysqldump(): string {
    if (!_bh_fn_ok('shell_exec')) return '';
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/opt/mysql/bin/mysqldump'] as $p) {
        if (@is_executable($p)) return $p;
    }
    $found = trim((string)@shell_exec('which mysqldump 2>/dev/null'));
    return (str_contains($found, 'mysqldump') && @is_executable($found)) ? $found : '';
}

// mysqldump yolu kullanılabilir mi? TEK kaynak — hem yedekleme hem ekran rozeti.
function _bh_can_mysqldump(): bool {
    if (defined('DB_BACKUP_DISABLE_MYSQLDUMP') && constant('DB_BACKUP_DISABLE_MYSQLDUMP')) return false;
    if (!_bh_fn_ok('popen') || !function_exists('gzopen')) return false;
    return _bh_find_mysqldump() !== '';
}

// ── Boyut biçimi: B / KB / MB / GB (Türkçe ayraç) ─────────
function _bh_fmt_size(?int $bytes): string {
    if ($bytes === null) return '—';
    if ($bytes < 1024) return number_format($bytes, 0, ',', '.') . ' B';
    $birim = ['KB', 'MB', 'GB', 'TB'];
    $x = $bytes / 1024;
    $i = 0;
    while ($x >= 1024 && $i < count($birim) - 1) { $x /= 1024; $i++; }
    return number_format($x, 1, ',', '.') . ' ' . $birim[$i];
}

// Saat cinsinden yaşı okunur metne çevir ("5 saat" / "3 gün").
function _bh_yas_metni(float $saat): string {
    if ($saat < 1)  return '1 saatten az';
    if ($saat < 48) return (int)floor($saat) . ' saat';
    return (int)floor($saat / 24) . ' gün';
}

// ── Yazma / doğrulama ─────────────────────────────────────
// Her yazmanın dönüşü kontrol edilir: disk dolu ise yarım dosya "başarılı" sayılmaz.
function _bh_write($h, bool $isGz, string $s): void {
    if ($s === '') return;
    $n = $isGz ? gzwrite($h, $s) : fwrite($h, $s);
    if ($n === false || $n < strlen($s)) {
        throw new RuntimeException('Yedek dosyasına yazılamadı (disk dolu olabilir)');
    }
}

// Yedek dosyasını sonuna kadar açıp okur; gzip bütünlüğü + "-- Dump completed" alt satırı.
// Dönüş: ['ok'=>bool, 'error'=>?string, 'bytes'=>int (açılmış boyut)]
function _bh_verify_backup(string $path, ?bool $isGz = null): array {
    $isGz ??= str_contains(basename($path), '.gz');
    $fh = @fopen($path, 'rb');
    if ($fh === false) return ['ok' => false, 'error' => 'Yedek dosyası okunamadı', 'bytes' => 0];

    $bytes = 0;
    $tail  = '';
    $ctx   = null;
    if ($isGz) {
        if (!function_exists('inflate_init')) {
            fclose($fh);
            return _bh_verify_backup_gzopen($path);
        }
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
    }
    try {
        while (!feof($fh)) {
            $raw = fread($fh, 262144);
            if ($raw === false) return ['ok' => false, 'error' => 'Yedek dosyası okunamadı', 'bytes' => $bytes];
            if ($raw === '') continue;
            if ($ctx !== null) {
                $out = @inflate_add($ctx, $raw, ZLIB_SYNC_FLUSH);
                if ($out === false) return ['ok' => false, 'error' => 'Yedek dosyası bozuk (gzip açılamadı)', 'bytes' => $bytes];
                if (inflate_get_status($ctx) === ZLIB_STREAM_END && !feof($fh)) {
                    // Tek gzip üyesi bitti; kalan bayt varsa dosya beklenmedik biçimde devam ediyor.
                    $kalan = fread($fh, 1);
                    if ($kalan !== '' && $kalan !== false) {
                        return ['ok' => false, 'error' => 'Yedek dosyası bozuk (gzip sonrası fazladan veri)', 'bytes' => $bytes];
                    }
                }
            } else {
                $out = $raw;
            }
            $bytes += strlen($out);
            $tail   = substr($tail . $out, -512);
        }
        if ($ctx !== null && inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            return ['ok' => false, 'error' => 'Yedek eksik/kesik (gzip akışı tamamlanmamış)', 'bytes' => $bytes];
        }
    } finally {
        fclose($fh);
    }
    if ($bytes === 0) return ['ok' => false, 'error' => 'Yedek dosyası boş', 'bytes' => 0];
    if (!str_contains($tail, '-- Dump completed')) {
        return ['ok' => false, 'error' => 'Yedek eksik/kesik (footer yok)', 'bytes' => $bytes];
    }
    return ['ok' => true, 'error' => null, 'bytes' => $bytes];
}

// inflate_* yoksa yedek yol: gzopen ile oku (gzip sağlama kontrolü daha zayıf).
function _bh_verify_backup_gzopen(string $path): array {
    $gz = @gzopen($path, 'rb');
    if ($gz === false) return ['ok' => false, 'error' => 'Yedek dosyası okunamadı', 'bytes' => 0];
    $bytes = 0;
    $tail  = '';
    while (!gzeof($gz)) {
        $chunk = @gzread($gz, 1048576);
        if ($chunk === false) { gzclose($gz); return ['ok' => false, 'error' => 'Yedek dosyası bozuk', 'bytes' => $bytes]; }
        if ($chunk === '') break;
        $bytes += strlen($chunk);
        $tail   = substr($tail . $chunk, -512);
    }
    gzclose($gz);
    if ($bytes === 0) return ['ok' => false, 'error' => 'Yedek dosyası boş', 'bytes' => 0];
    if (!str_contains($tail, '-- Dump completed')) {
        return ['ok' => false, 'error' => 'Yedek eksik/kesik (footer yok)', 'bytes' => $bytes];
    }
    return ['ok' => true, 'error' => null, 'bytes' => $bytes];
}

// ── Kilit (aynı anda tek yedek) ───────────────────────────
// Dönüş: kaynak = kilit alındı · null = başka yedek çalışıyor ·
//        false = kilit dosyası açılamadı (klasör yazılamıyor — yedek zaten
//        yazma aşamasında anlaşılır bir hatayla düşer).
function _bh_lock_acquire() {
    $f = @fopen(db_backup_dir() . '/.backup.lock', 'c');
    if ($f === false) return false;
    if (!flock($f, LOCK_EX | LOCK_NB)) {
        fclose($f);
        return null;
    }
    return $f;
}

function _bh_lock_release($f): void {
    if (is_resource($f)) {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

// ── Otomatik deneme durumu (.auto_state.json) ─────────────
// {date, attempts, last_ts, last_crash?, crash_at?} — şema değişikliği
// gerektirmeden "bugün kaç kez denendi / süreç öldü mü" bilgisini tutar.
function _bh_state_path(): string {
    return db_backup_dir() . '/.auto_state.json';
}

function _bh_state_read(): array {
    $f = @fopen(_bh_state_path(), 'r');
    if ($f === false) return [];
    flock($f, LOCK_SH);
    $raw = stream_get_contents($f);
    flock($f, LOCK_UN);
    fclose($f);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

function _bh_state_write(array $state): void {
    $f = @fopen(_bh_state_path(), 'c');
    if ($f === false) return;
    if (flock($f, LOCK_EX)) {
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string)json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($f);
        flock($f, LOCK_UN);
    }
    fclose($f);
}

// Oku-değiştir-yaz TEK kilit altında (iki süreç aynı anda sayaç artırırsa kaybolmasın).
function _bh_state_update(callable $fn): void {
    $f = @fopen(_bh_state_path(), 'c+');
    if ($f === false) return;
    if (flock($f, LOCK_EX)) {
        $d   = json_decode((string)stream_get_contents($f), true);
        $new = $fn(is_array($d) ? $d : []);
        ftruncate($f, 0);
        rewind($f);
        fwrite($f, (string)json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($f);
        flock($f, LOCK_UN);
    }
    fclose($f);
}

// ── mysqldump seçenek dosyası değeri ──────────────────────
// Option dosyasında '#' satır ortasında yorum başlatır, '\' kaçıştır —
// değer çift tırnağa alınır (tırnak içindeki '#' yorum sayılmaz). mysys yalnız
// \\ \n \r \t \b \s kaçışlarını çözer; '\"' ÇÖZÜLMEZ (ters bölü kalır) —
// dıştaki tırnaklar sadece baştan/sondan soyulduğu için içteki '"' kaçışsız yazılır.
function _bh_cnf_value(string $v): string {
    return '"' . addcslashes($v, "\\\n\r") . '"';
}

// mysqldump'ı çalıştırıp $part'a gzip olarak yazar. Hata → RuntimeException
// ('mysqldump exit=N: <stderr>'). Geçici dosyalar $tmp'e eklenir (çöküşte de silinsin).
function _bh_run_mysqldump(string $mysqldump, string $part, array &$tmp): void {
    $opts_file = (string)tempnam(sys_get_temp_dir(), 'asya_dbo_');
    $err_file  = (string)tempnam(sys_get_temp_dir(), 'asya_dbe_');
    $tmp[] = $opts_file;
    $tmp[] = $err_file;
    @chmod($opts_file, 0600);
    @chmod($err_file, 0600);

    // Şifreyi komut satırına koymamak için geçici options dosyası
    $cnf = "[client]\n"
        . 'user='     . _bh_cnf_value((string)DB_USER) . "\n"
        . 'password=' . _bh_cnf_value((string)DB_PASS) . "\n"
        . 'host='     . _bh_cnf_value((string)DB_HOST) . "\n";
    if (defined('DB_PORT')) {
        $cnf .= 'port=' . (int)constant('DB_PORT') . "\n";
    }
    if (@file_put_contents($opts_file, $cnf) === false) {
        throw new RuntimeException('mysqldump seçenek dosyası yazılamadı');
    }

    $cmd = escapeshellarg($mysqldump)
        . ' --defaults-extra-file=' . escapeshellarg($opts_file)
        . ' --single-transaction --skip-lock-tables --routines --triggers --hex-blob'
        . ' --no-tablespaces --default-character-set=utf8mb4'
        . ' ' . escapeshellarg((string)DB_NAME)
        . ' 2>' . escapeshellarg($err_file);

    $stderr = static function () use ($err_file): string {
        $s = trim((string)@file_get_contents($err_file, false, null, 0, 2000));
        return mb_substr($s, 0, 300);
    };

    $proc = popen($cmd, 'r');
    if ($proc === false) {
        throw new RuntimeException('popen ile mysqldump başlatılamadı');
    }
    $gz = gzopen($part, 'wb6');
    if ($gz === false) {
        pclose($proc);
        throw new RuntimeException('Yedek dosyası açılamadı (gzopen)');
    }
    $yazma_hatasi = null;
    try {
        while (!feof($proc)) {
            $chunk = fread($proc, 65536);
            if ($chunk === false) break;
            _bh_write($gz, true, $chunk);
        }
    } catch (RuntimeException $e) {
        $yazma_hatasi = $e->getMessage();
    }
    $kapandi   = gzclose($gz);
    $exit_code = pclose($proc);

    if ($yazma_hatasi !== null) {
        throw new RuntimeException('mysqldump exit=' . $exit_code . ': ' . $yazma_hatasi);
    }
    if (!$kapandi) {
        throw new RuntimeException('mysqldump exit=' . $exit_code . ': yedek dosyası kapatılamadı');
    }
    if ($exit_code !== 0) {
        $e = $stderr();
        throw new RuntimeException('mysqldump exit=' . $exit_code . ': ' . ($e !== '' ? $e : 'hata çıktısı yok'));
    }
    $v = _bh_verify_backup($part, true);
    if (!$v['ok']) {
        $e = $stderr();
        throw new RuntimeException('mysqldump exit=' . $exit_code . ': ' . $v['error'] . ($e !== '' ? ' — ' . $e : ''));
    }
}

// ── PDO fallback: döküm bağlantısı ────────────────────────
// MySQL'de AYRI bağlantı: unbuffered (tablo belleğe inmez) + stringify.
// Açılamazsa ana bağlantı geçici olarak unbuffered yapılır, sonra eski hâline döner.
// Dönüş: [PDO $conn, ?callable $geriAl]
function _bh_mysql_buffered_attr(): int {
    return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
        ? (int)constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')
        : (int)constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
}

function _bh_dump_connection(PDO $pdo): array {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return [$pdo, null];
    }
    $attr = _bh_mysql_buffered_attr();
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset='
            . (defined('DB_CHARSET') ? (string)constant('DB_CHARSET') : 'utf8mb4');
        $c = new PDO($dsn, (string)DB_USER, (string)DB_PASS, [
            PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            $attr                       => false,
        ]);
        return [$c, null];
    } catch (Throwable $e) {
        $eski_buf = $pdo->getAttribute($attr);
        $eski_str = $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
        $pdo->setAttribute($attr, false);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        return [$pdo, static function () use ($pdo, $attr, $eski_buf, $eski_str): void {
            $pdo->setAttribute($attr, $eski_buf);
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $eski_str);
        }];
    }
}

// ── PDO fallback: döküm ───────────────────────────────────
// Satır satır okur, çok satırlı INSERT ile doğrudan $h'ye (gz/düz) yazar.
// VIEW'lar atlanır (yorum satırı + sayaç). Dönüş: [tables, rows, views_skipped]
function _bh_pdo_dump(PDO $src, $h, bool $isGz): array {
    [$conn, $geri_al] = _bh_dump_connection($src);
    $is_mysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $tx       = false;
    $sonuc    = ['tables' => 0, 'rows' => 0, 'views_skipped' => 0];

    try {
        if ($is_mysql && !$conn->inTransaction()) {
            // Tutarlı anlık görüntü: tablolar sırayla okunurken yazılan satırlar karışmasın
            $conn->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            $conn->exec("START TRANSACTION WITH CONSISTENT SNAPSHOT");
            $tx = true;
        }

        _bh_write($h, $isGz,
            "-- Asya Fresh DB Backup (PDO Fallback)\n"
            . "-- Tarih     : " . date('Y-m-d H:i:s') . "\n"
            . "-- Veritabanı: " . DB_NAME . "\n\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
            . "SET NAMES utf8mb4;\n\n"
        );

        $st   = $conn->query("SHOW FULL TABLES");
        $list = $st->fetchAll(PDO::FETCH_NUM);
        $st->closeCursor();

        foreach ($list as $t) {
            $table = (string)$t[0];
            $type  = strtoupper((string)($t[1] ?? 'BASE TABLE'));
            $safe  = str_replace('`', '``', $table);
            if ($type !== 'BASE TABLE') {
                _bh_write($h, $isGz, "-- VIEW atlandı: `{$safe}`\n\n");
                $sonuc['views_skipped']++;
                continue;
            }

            $cst = $conn->query("SHOW CREATE TABLE `{$safe}`");
            $cr  = $cst->fetch(PDO::FETCH_NUM);
            $cst->closeCursor();
            $create_sql = (string)($cr[1] ?? '');
            if ($create_sql === '') throw new RuntimeException("SHOW CREATE TABLE boş: {$table}");

            _bh_write($h, $isGz,
                "-- Tablo: `{$safe}`\n"
                . "DROP TABLE IF EXISTS `{$safe}`;\n"
                . $create_sql . ";\n\n"
            );
            $sonuc['tables']++;

            $rst      = $conn->query("SELECT * FROM `{$safe}`");
            $prefix   = null;
            $buf      = [];
            $buf_len  = 0;
            $flush    = static function () use (&$buf, &$buf_len, &$prefix, $h, $isGz): void {
                if (!$buf) return;
                _bh_write($h, $isGz, $prefix . implode(",\n", $buf) . ";\n");
                $buf     = [];
                $buf_len = 0;
            };
            while (($row = $rst->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($prefix === null) {
                    $cols = '`' . implode('`, `', array_map(
                        static fn($c) => str_replace('`', '``', (string)$c),
                        array_keys($row)
                    )) . '`';
                    $prefix = "INSERT INTO `{$safe}` ({$cols}) VALUES\n";
                }
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $conn->quote((string)$v);
                }
                $tuple = '(' . implode(', ', $vals) . ')';
                $len   = strlen($tuple);
                if ($buf && ($buf_len + $len > DB_BACKUP_INSERT_BAYT || count($buf) >= DB_BACKUP_INSERT_SATIR)) {
                    $flush();
                }
                $buf[]    = $tuple;
                $buf_len += $len;
                if ($len > DB_BACKUP_INSERT_BAYT) $flush(); // tek dev satır kendi ifadesi
                $sonuc['rows']++;
            }
            $rst->closeCursor();
            $flush();
            _bh_write($h, $isGz, "\n");
        }

        if ($tx) {
            $conn->exec("COMMIT");
            $tx = false;
        }

        _bh_write($h, $isGz,
            "SET FOREIGN_KEY_CHECKS=1;\n"
            . "-- Dump completed on " . date('Y-m-d H:i:s') . " (pdo_fallback)\n"
        );
    } finally {
        if ($tx) {
            try { $conn->exec("ROLLBACK"); } catch (Throwable $_rb) {}
        }
        if ($geri_al !== null) {
            try { $geri_al(); } catch (Throwable $_ga) {}
        }
    }
    return $sonuc;
}

// Bugün (PHP saatiyle) başarılı yedek var mı?
function _bh_today_success(PDO $pdo, ?int $now = null): bool {
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM database_backups WHERE backup_date = ? AND status = 'success'"
        );
        $st->execute([date('Y-m-d', $now ?? time())]);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ── Ana backup fonksiyonu ─────────────────────────────────
// Dönüş anahtarları (sabit): ok, file_ok, db_ok, id, filename, file_path,
// size, method, error, db_error — EK: busy (başka yedek çalışıyor),
// skipped (skipIfDoneToday ve bugün yedek zaten var), note (başarılı ama
// dikkat edilecek not: mysqldump düştü / view atlandı).
function create_database_backup(PDO $pdo, int $userId, string $trigger = 'auto_login', bool $skipIfDoneToday = false): array {
    ensure_db_backup_dir();
    $dir = db_backup_dir();

    $bos = [
        'ok' => false, 'file_ok' => false, 'db_ok' => false, 'id' => 0,
        'filename' => '', 'file_path' => '', 'size' => null, 'method' => '',
        'error' => null, 'db_error' => null, 'busy' => false, 'skipped' => false, 'note' => null,
    ];

    // Kilit: alınamazsa DB'ye failed satırı / audit YAZILMAZ — hata değil, meşgul.
    $lock = _bh_lock_acquire();
    if ($lock === null) {
        return ['busy' => true, 'error' => 'Başka bir yedekleme şu anda çalışıyor.'] + $bos;
    }

    // Çöküş izi: fatal (bellek/zaman aşımı) olursa finally çalışmaz — geçici
    // dosyaları sil, .auto_state.json'a sebebi yaz. DB'ye yazılmaz.
    $running = true;
    $tmp     = [];
    register_shutdown_function(static function () use (&$running, &$tmp): void {
        if (!$running) return;
        foreach ($tmp as $f) { if ($f !== '' && is_file($f)) @unlink($f); }
        $son = error_get_last();
        $msg = mb_substr((string)($son['message'] ?? 'bilinmiyor'), 0, 300);
        _bh_state_update(static function (array $s) use ($msg): array {
            $s['last_crash'] = $msg;
            $s['crash_at']   = date('Y-m-d H:i:s');
            return $s;
        });
    });

    try {
        if ($skipIfDoneToday && _bh_today_success($pdo)) {
            return ['skipped' => true] + $bos;
        }

        @set_time_limit(300);
        @ignore_user_abort(true);

        $gz_ok    = function_exists('gzopen');
        $ts       = date('Ymd_His');
        $filename = 'db_backup_' . $ts . '_' . bin2hex(random_bytes(8)) . ($gz_ok ? '.sql.gz' : '.sql');
        $filepath = _bh_backup_path($filename);
        $part     = $filepath . '.part';
        $tmp[]    = $part;
        $method   = 'unknown';
        $err_msg  = null;
        $dump_err = null;
        $notlar   = [];
        $ok       = false;

        // ── Disk ön kontrolü ──────────────────────────────────
        $gerekli = DB_BACKUP_MIN_FREE;
        try {
            $son = last_successful_backup($pdo);
            if ($son && (int)$son['file_size'] > 0) {
                $gerekli = max($gerekli, (int)ceil(1.5 * (int)$son['file_size']));
            }
        } catch (Throwable $_ls) {}
        $bos_alan = @disk_free_space($dir);
        if ($bos_alan !== false && $bos_alan < $gerekli) {
            $err_msg = 'Disk alanı yetersiz: ' . _bh_fmt_size((int)$bos_alan) . ' boş, en az '
                . _bh_fmt_size($gerekli) . ' gerekli';
        } else {
            // ── Yöntem 1: mysqldump ────────────────────────────
            if (_bh_can_mysqldump()) {
                $method = 'mysqldump+gzopen';
                try {
                    _bh_run_mysqldump(_bh_find_mysqldump(), $part, $tmp);
                    $ok = true;
                } catch (Throwable $e) {
                    $dump_err = mb_substr($e->getMessage(), 0, 400);
                    @unlink($part);
                }
            }

            // ── Yöntem 2: PDO fallback ─────────────────────────
            if (!$ok) {
                $method = 'pdo_fallback' . ($gz_ok ? '+gzopen' : '');
                try {
                    $h = $gz_ok ? gzopen($part, 'wb6') : fopen($part, 'wb');
                    if ($h === false) throw new RuntimeException('Yedek dosyası açılamadı');
                    $kapali = false;
                    try {
                        $info   = _bh_pdo_dump($pdo, $h, $gz_ok);
                        $kapali = true;
                        if (!($gz_ok ? gzclose($h) : fclose($h))) {
                            throw new RuntimeException('Yedek dosyası kapatılamadı');
                        }
                    } finally {
                        if (!$kapali) { $gz_ok ? @gzclose($h) : @fclose($h); }
                    }
                    $v = _bh_verify_backup($part, $gz_ok);
                    if (!$v['ok']) throw new RuntimeException((string)$v['error']);
                    $ok = true;
                    if ($dump_err !== null) $notlar[] = $dump_err;
                    if ($info['views_skipped'] > 0) $notlar[] = $info['views_skipped'] . ' view atlandı (PDO yedeği view içermez)';
                } catch (Throwable $e) {
                    $pdo_err = 'PDO dump hatası: ' . mb_substr($e->getMessage(), 0, 200);
                    $err_msg = $dump_err !== null ? ($dump_err . ' | ' . $pdo_err) : $pdo_err;
                    $ok      = false;
                    @unlink($part);
                }
            }

            // ── .part → son ad ─────────────────────────────────
            if ($ok) {
                if (@rename($part, $filepath)) {
                    @chmod($filepath, 0600);
                } else {
                    $ok      = false;
                    $err_msg = 'Yedek dosyası yeniden adlandırılamadı';
                    @unlink($part);
                }
            }
        }

        $note      = $notlar ? 'Not: ' . implode(' · ', $notlar) : null;
        $file_size = ($ok && is_file($filepath)) ? (int)filesize($filepath) : null;
        $status    = $ok ? 'success' : 'failed';

        // ── DB kaydı (döküm bittikten SONRA — unbuffered akış kapandı) ──
        $backup_id = 0;
        $db_err    = null;
        try {
            ensure_db_backup_table($pdo);
            $pdo->prepare(
                "INSERT INTO database_backups
                 (backup_date, filename, file_path, file_size, method, status, error_message, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                date('Y-m-d'),
                $filename,
                $filepath,
                $file_size,
                $method,
                $status,
                $ok ? $note : $err_msg,
                $userId ?: null,
                date('Y-m-d H:i:s'),
            ]);
            $backup_id = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            $db_err = 'Kayıt tablosuna yazılamadı: ' . mb_substr($e->getMessage(), 0, 120);
        }

        // ── Audit log ─────────────────────────────────────────
        try {
            if ($ok) {
                audit_log_event('database_backup_created', 'system', $backup_id ?: null, null, [
                    'filename' => $filename,
                    'size'     => $file_size,
                    'method'   => $method,
                    'trigger'  => $trigger,
                    'note'     => $note,
                ]);
            } else {
                audit_log_event('database_backup_failed', 'system', null, null, [
                    'method'  => $method,
                    'error'   => $err_msg,
                    'trigger' => $trigger,
                ]);
            }
        } catch (Throwable $_ae) { /* audit hatası backup'ı engellemesin */ }

        // ── Eski yedekleri temizle (başarılı backuptan sonra, kilit içinde) ──
        if ($ok) {
            try { cleanup_old_database_backups($pdo, DB_BACKUP_KEEP_DAYS, DB_BACKUP_MIN_KEEP); } catch (Throwable $_ce) {}
        }

        return [
            'ok'        => $ok && $backup_id > 0,
            'file_ok'   => $ok,
            'db_ok'     => $backup_id > 0,
            'id'        => $backup_id,
            'filename'  => $filename,
            'file_path' => $filepath,
            'size'      => $file_size,
            'method'    => $method,
            'error'     => $err_msg ?? $db_err,
            'db_error'  => $db_err,
            'busy'      => false,
            'skipped'   => false,
            'note'      => $note,
        ];
    } finally {
        foreach ($tmp as $f) { if ($f !== '' && is_file($f)) @unlink($f); }
        $running = false;
        if ($lock !== false) _bh_lock_release($lock);
    }
}

// ── Günlük backup gerekli mi? ─────────────────────────────
// null döndüren (false) durumlar: 17:00 öncesi · bugün başarılı var ·
// bugün 3 deneme yapıldı · son denemeden 30 dk geçmedi · bugün ≥3 failed satır.
function should_create_daily_backup(PDO $pdo, ?int $now = null): bool {
    $now ??= time();
    // Saat 17:00 öncesiyse hayır
    if ((int)date('H', $now) < 17) return false;
    $bugun = date('Y-m-d', $now);
    try {
        $st = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS ok_sayi,
                SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) AS fail_sayi
             FROM database_backups WHERE backup_date = ?"
        );
        $st->execute([$bugun]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($r['ok_sayi'] ?? 0) > 0) return false;
        if ((int)($r['fail_sayi'] ?? 0) >= DB_BACKUP_AUTO_MAX_TRY) return false;
    } catch (PDOException $e) {
        return false; // tablo henüz yok
    }
    $s = _bh_state_read();
    if (($s['date'] ?? '') === $bugun && (int)($s['attempts'] ?? 0) >= DB_BACKUP_AUTO_MAX_TRY) return false;
    if ($now - (int)($s['last_ts'] ?? 0) < DB_BACKUP_AUTO_WAIT_SN) return false;
    return true;
}

// ── Admin girişinde çağrılır (null = gerekmedi) ───────────
// Fren denemeden ÖNCE sayılır: süreç ölse bile sayaç artmış olur.
function create_daily_backup_if_needed(PDO $pdo, int $userId, ?int $now = null): ?array {
    $now ??= time();
    if (!should_create_daily_backup($pdo, $now)) return null;
    $bugun = date('Y-m-d', $now);
    _bh_state_update(static function (array $s) use ($bugun, $now): array {
        $s['attempts'] = (($s['date'] ?? '') === $bugun) ? (int)($s['attempts'] ?? 0) + 1 : 1;
        $s['date']     = $bugun;
        $s['last_ts']  = $now;
        return $s;
    });
    $r = create_database_backup($pdo, $userId, 'auto_login', true);
    if (!empty($r['skipped'])) return null;
    return $r;
}

// ── Admin sayfası için yedek listesi ─────────────────────
function list_database_backups(PDO $pdo, int $limit = 30, ?string $status = null): array {
    try {
        ensure_db_backup_table($pdo);
        $where  = '';
        $params = [];
        if ($status !== null) {
            $where    = 'WHERE b.status = ?';
            $params[] = $status;
        }
        $st = $pdo->prepare(
            "SELECT b.*,
                    uc.display_name AS created_by_name,
                    ud.display_name AS downloaded_by_name
             FROM database_backups b
             LEFT JOIN users uc ON uc.id = b.created_by
             LEFT JOIN users ud ON ud.id = b.downloaded_by
             {$where}
             ORDER BY b.created_at DESC, b.id DESC
             LIMIT " . max(1, $limit)
        );
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// ── Son başarılı yedek (yoksa null) ───────────────────────
function last_successful_backup(PDO $pdo): ?array {
    try {
        $st = $pdo->query(
            "SELECT * FROM database_backups WHERE status = 'success'
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// Son başarılı yedeğin yaşı (saat). Yedek yoksa null.
function _bh_last_age_hours(?array $last, ?int $now = null): ?float {
    if (!$last || empty($last['created_at'])) return null;
    $ts = strtotime((string)$last['created_at']);
    if (!$ts) return null;
    return max(0, (($now ?? time()) - $ts) / 3600);
}

// ── Eski yedekleri temizle ────────────────────────────────
// 1) En yeni $minKeep başarılı yedek HER ZAMAN korunur.
// 2) Cutoff'tan eski diğer başarılılar: dosya silinebildiyse (ya da zaten
//    yoksa) satır silinir; silinemezse satır KALIR (yetim dosya olmasın).
// 3) Cutoff'tan eski başarısız satırlar silinir.
// 4) Yetim tarama: 6 saatten eski .part + DB'de karşılığı olmayan eski yedekler.
function cleanup_old_database_backups(PDO $pdo, int $keepDays = DB_BACKUP_KEEP_DAYS, int $minKeep = DB_BACKUP_MIN_KEEP): void {
    try {
        $cutoff        = date('Y-m-d', strtotime("-{$keepDays} days"));
        $deleted_rows  = 0;
        $deleted_files = [];
        $unlink_errors = [];
        $orphans       = 0;

        $rows = $pdo->query(
            "SELECT id, filename, backup_date FROM database_backups
             WHERE status = 'success' ORDER BY created_at DESC, id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $del = $pdo->prepare("DELETE FROM database_backups WHERE id = ?");
        foreach ($rows as $i => $row) {
            if ($i < $minKeep) continue;                         // korunan en yeniler
            if ((string)$row['backup_date'] >= $cutoff) continue; // henüz süresi dolmadı
            $p = _bh_backup_path((string)$row['filename']);
            $vardi = is_file($p);
            if (!$vardi || @unlink($p)) {
                $del->execute([(int)$row['id']]);
                $deleted_rows++;
                if ($vardi) $deleted_files[] = basename($p);
            } else {
                $unlink_errors[] = basename($p);
            }
        }

        $fst = $pdo->prepare("DELETE FROM database_backups WHERE status = 'failed' AND backup_date < ?");
        $fst->execute([$cutoff]);
        $failed_rows = $fst->rowCount();

        // Yetim dosyalar
        $bilinen = array_flip(array_map('strval',
            $pdo->query("SELECT filename FROM database_backups")->fetchAll(PDO::FETCH_COLUMN)));
        $simdi = time();
        foreach (glob(db_backup_dir() . '/db_backup_*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $ad    = basename($f);
            $mtime = (int)@filemtime($f);
            $sil   = false;
            if (str_ends_with($ad, '.part')) {
                $sil = $mtime < $simdi - 6 * 3600;
            } elseif ((str_ends_with($ad, '.sql') || str_ends_with($ad, '.sql.gz')) && !isset($bilinen[$ad])) {
                $sil = $mtime < $simdi - $keepDays * 86400;
            }
            if ($sil) {
                if (@unlink($f)) { $orphans++; $deleted_files[] = $ad; }
                else             { $unlink_errors[] = $ad; }
            }
        }

        if ($deleted_rows > 0 || $failed_rows > 0 || $orphans > 0 || $unlink_errors) {
            audit_log_event('database_backup_cleanup', 'system', null, null, [
                'deleted_rows'  => $deleted_rows,
                'failed_rows'   => $failed_rows,
                'deleted_files' => array_slice($deleted_files, 0, 20),
                'orphan_files'  => $orphans,
                'unlink_errors' => array_slice($unlink_errors, 0, 20),
                'kept_min'      => $minKeep,
                'cutoff_date'   => $cutoff,
                'keep_days'     => $keepDays,
            ]);
        }
    } catch (PDOException $e) {
        // sessizce geç
    }
}
