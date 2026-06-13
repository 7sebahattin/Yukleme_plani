<?php
// =========================================================
// config/db_backup_helpers.php — Günlük DB Yedekleme Sistemi
// Sprint DB-Backup-01
//
// Gereksinimler:
//   require_once __DIR__ . '/config/db.php';    (DB_HOST/NAME/USER/PASS)
//   require_once __DIR__ . '/config/auth.php';
//   require_once __DIR__ . '/config/db_backup_helpers.php';
//
// Public API:
//   db_backup_dir()                     : string
//   ensure_db_backup_dir()              : void
//   create_database_backup(pdo,uid,trigger) : array
//   should_create_daily_backup(pdo)     : bool
//   create_daily_backup_if_needed(pdo,uid) : ?array
//   list_database_backups(pdo, limit)   : array
//   cleanup_old_database_backups(pdo, keepDays) : void
// =========================================================
declare(strict_types=1);

// ── Yedek klasörü (web root içi + .htaccess korumalı) ─────
function db_backup_dir(): string {
    return dirname(__DIR__) . '/storage/backups/db';
}

// ── Klasör + .htaccess güvenlik dosyalarını oluştur ────────
function ensure_db_backup_dir(): void {
    $root = dirname(__DIR__) . '/storage';
    foreach ([$root, "$root/backups", "$root/backups/db"] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        $ht = $d . '/.htaccess';
        if (!file_exists($ht)) {
            file_put_contents($ht, "Deny from all\nOptions -Indexes\n");
        }
    }
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

// ── Ana backup fonksiyonu ─────────────────────────────────
function create_database_backup(PDO $pdo, int $userId, string $trigger = 'auto_login'): array {
    ensure_db_backup_dir();
    $dir      = db_backup_dir();
    $ts       = date('Ymd_His');
    $filename = 'db_backup_' . $ts . '.sql.gz';
    $filepath = $dir . '/' . $filename;
    $method   = 'unknown';
    $err_msg  = null;
    $ok       = false;

    @set_time_limit(180); // backup için 3 dakika

    // ── Yöntem 1: mysqldump ────────────────────────────────
    $mysqldump = _bh_find_mysqldump();
    $use_dump  = $mysqldump !== '' && _bh_fn_ok('popen') && function_exists('gzopen');

    if ($use_dump) {
        // Şifreyi CLI'ye koymamak için geçici options dosyası
        $opts_file = (string)tempnam(sys_get_temp_dir(), 'asya_dbo_');
        file_put_contents($opts_file,
            "[client]\n"
            . 'user='     . DB_USER . "\n"
            . 'password=' . DB_PASS . "\n"
            . 'host='     . DB_HOST . "\n"
        );
        @chmod($opts_file, 0600);

        $cmd = escapeshellarg($mysqldump)
            . ' --defaults-extra-file=' . escapeshellarg($opts_file)
            . ' --single-transaction --routines --skip-lock-tables --hex-blob'
            . ' ' . escapeshellarg(DB_NAME);

        $method = 'mysqldump+gzopen';

        $proc = popen($cmd, 'r');
        if ($proc !== false) {
            $gz = gzopen($filepath, 'wb6');
            if ($gz !== false) {
                while (!feof($proc)) {
                    gzwrite($gz, fread($proc, 65536));
                }
                gzclose($gz);
            }
            $exit_code = pclose($proc);
            @unlink($opts_file);
            if ($exit_code === 0 && file_exists($filepath) && filesize($filepath) > 100) {
                $ok = true;
            } else {
                $err_msg = 'mysqldump başarısız (exit=' . $exit_code . ') veya dosya boş';
                @unlink($filepath);
            }
        } else {
            @unlink($opts_file);
            $err_msg = 'popen ile mysqldump başlatılamadı';
        }
    }

    // ── Yöntem 2: PDO fallback ─────────────────────────────
    if (!$ok) {
        $method = 'pdo_fallback';
        $gz_ok  = function_exists('gzopen');
        if (!$gz_ok) {
            // gzopen yoksa .sql olarak kaydet
            $filename = 'db_backup_' . $ts . '.sql';
            $filepath = $dir . '/' . $filename;
        }

        $tmp_sql = (string)tempnam(sys_get_temp_dir(), 'asya_sql_');
        $err_msg = null;

        try {
            $fh = fopen($tmp_sql, 'w');
            if ($fh === false) throw new RuntimeException('Geçici SQL dosyası açılamadı');

            fwrite($fh, "-- Asya Fresh DB Backup (PDO Fallback)\n");
            fwrite($fh, "-- Tarih     : " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "-- Veritabanı: " . DB_NAME . "\n\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
            fwrite($fh, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($fh, "SET NAMES utf8mb4;\n\n");

            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $safe = str_replace('`', '``', (string)$table);
                $cr   = $pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch(PDO::FETCH_ASSOC);
                // SHOW CREATE TABLE döner: {Tables_in_db, Create Table}
                $create_sql = $cr['Create Table'] ?? array_values($cr)[1] ?? '';

                fwrite($fh, "-- Tablo: `{$safe}`\n");
                fwrite($fh, "DROP TABLE IF EXISTS `{$safe}`;\n");
                fwrite($fh, $create_sql . ";\n\n");

                $stmt     = $pdo->query("SELECT * FROM `{$safe}`");
                $col_line = null;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($col_line === null) {
                        $col_line = '`' . implode('`, `', array_map(
                            fn($c) => str_replace('`', '``', $c),
                            array_keys($row)
                        )) . '`';
                    }
                    $vals = array_map(static function($v) use ($pdo): string {
                        return $v === null ? 'NULL' : $pdo->quote((string)$v);
                    }, array_values($row));
                    fwrite($fh, "INSERT INTO `{$safe}` ({$col_line}) VALUES (" . implode(', ', $vals) . ");\n");
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fh);

            // Geçici SQL → gzip
            if ($gz_ok) {
                $method .= '+gzopen';
                $gz = gzopen($filepath, 'wb6');
                if ($gz === false) throw new RuntimeException('gzopen başarısız');
                $src = fopen($tmp_sql, 'r');
                while (!feof($src)) gzwrite($gz, fread($src, 65536));
                fclose($src);
                gzclose($gz);
            } else {
                // Olduğu gibi kopyala (.sql)
                rename($tmp_sql, $filepath);
                $tmp_sql = '';
            }

            $ok = file_exists($filepath) && filesize($filepath) > 50;
            if (!$ok) $err_msg = 'Dosya oluştu ama boş';
        } catch (Throwable $e) {
            $err_msg = 'PDO dump hatası: ' . substr($e->getMessage(), 0, 150);
            $ok      = false;
        } finally {
            if ($tmp_sql !== '' && file_exists($tmp_sql)) @unlink($tmp_sql);
        }

        if (!$ok) @unlink($filepath);
    }

    $file_size = ($ok && file_exists($filepath)) ? (int)filesize($filepath) : null;
    $status    = $ok ? 'success' : 'failed';

    // ── DB kaydı ──────────────────────────────────────────
    $backup_id = 0;
    $db_err    = null;
    try {
        ensure_db_backup_table($pdo);
        $pdo->prepare(
            "INSERT INTO database_backups
             (backup_date, filename, file_path, file_size, method, status, error_message, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            date('Y-m-d'),
            $filename,
            $filepath,
            $file_size,
            $method,
            $status,
            $err_msg,
            $userId ?: null,
        ]);
        $backup_id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $db_err = 'Kayıt tablosuna yazılamadı: ' . substr($e->getMessage(), 0, 120);
    }

    // ── Audit log ─────────────────────────────────────────
    try {
        if ($ok) {
            audit_log_event('database_backup_created', 'system', $backup_id ?: null, null, [
                'filename' => $filename,
                'size'     => $file_size,
                'method'   => $method,
                'trigger'  => $trigger,
            ]);
        } else {
            audit_log_event('database_backup_failed', 'system', null, null, [
                'method'  => $method,
                'error'   => $err_msg,
                'trigger' => $trigger,
            ]);
        }
    } catch (Throwable $_ae) { /* audit hatası backup'ı engellemesin */ }

    // ── Eski yedekleri temizle (başarılı backuptan sonra) ──
    if ($ok) {
        try { cleanup_old_database_backups($pdo, 14); } catch (Throwable $_ce) {}
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
    ];
}

// ── Günlük backup gerekli mi? ─────────────────────────────
function should_create_daily_backup(PDO $pdo): bool {
    // Saat 17:00 öncesiyse hayır
    if ((int)date('H') < 17) return false;
    // Bugün başarılı backup var mı?
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM database_backups WHERE backup_date = ? AND status = 'success'"
        );
        $st->execute([date('Y-m-d')]);
        return (int)$st->fetchColumn() === 0;
    } catch (PDOException $e) {
        return false; // tablo henüz yok
    }
}

// ── Admin girişinde çağrılır (null = gerekmedi) ───────────
function create_daily_backup_if_needed(PDO $pdo, int $userId): ?array {
    if (!should_create_daily_backup($pdo)) return null;
    return create_database_backup($pdo, $userId, 'auto_login');
}

// ── Admin sayfası için yedek listesi ─────────────────────
function list_database_backups(PDO $pdo, int $limit = 30): array {
    try {
        ensure_db_backup_table($pdo);
        $st = $pdo->prepare(
            "SELECT b.*,
                    uc.display_name AS created_by_name,
                    ud.display_name AS downloaded_by_name
             FROM database_backups b
             LEFT JOIN users uc ON uc.id = b.created_by
             LEFT JOIN users ud ON ud.id = b.downloaded_by
             ORDER BY b.created_at DESC
             LIMIT " . max(1, $limit)
        );
        $st->execute();
        return $st->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// ── Eski yedekleri temizle ────────────────────────────────
function cleanup_old_database_backups(PDO $pdo, int $keepDays = 14): void {
    try {
        $cutoff = date('Y-m-d', strtotime("-{$keepDays} days"));
        $st = $pdo->prepare("SELECT id, file_path, status FROM database_backups WHERE backup_date < ?");
        $st->execute([$cutoff]);
        $rows = $st->fetchAll();
        if (!$rows) return;

        $deleted_count = 0;
        $ids = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'success' && file_exists((string)$row['file_path'])) {
                @unlink((string)$row['file_path']);
            }
            $ids[] = (int)$row['id'];
            $deleted_count++;
        }

        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM database_backups WHERE id IN ({$ph})")->execute($ids);
        }

        if ($deleted_count > 0) {
            audit_log_event('database_backup_cleanup', 'system', null, null, [
                'deleted_count' => $deleted_count,
                'cutoff_date'   => $cutoff,
                'keep_days'     => $keepDays,
            ]);
        }
    } catch (PDOException $e) {
        // sessizce geç
    }
}
