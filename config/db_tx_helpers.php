<?php
// =========================================================
// config/db_tx_helpers.php — Ortak PDO transaction ve şema yardımcıları (B-37)
// Yeni ve mevcut çekirdek modüllerin tek transaction / sürücü yardımcısı.
// =========================================================
declare(strict_types=1);

if (!function_exists('db_is_sqlite')) {
    function db_is_sqlite(PDO $pdo): bool {
        try {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('db_for_update')) {
    /** MySQL/MariaDB için ' FOR UPDATE', SQLite için boş dize döner. */
    function db_for_update(PDO $pdo): string {
        return db_is_sqlite($pdo) ? '' : ' FOR UPDATE';
    }
}

if (!function_exists('db_tx')) {
    /**
     * İç içe çağrı güvenli atomik transaction sarmalayıcısı.
     * Zaten aktif bir transaction içindeyse yeni BEGIN açmaz; dış transaction'a katılır.
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    function db_tx(PDO $pdo, callable $fn) {
        $icerde = $pdo->inTransaction();
        if (!$icerde) {
            $pdo->beginTransaction();
        }
        try {
            $sonuc = $fn($pdo);
            if (!$icerde && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return $sonuc;
        } catch (Throwable $e) {
            if (!$icerde && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('db_table_exists')) {
    /** Verilen tablo mevcut mu? (MySQL + SQLite uyumlu, exception sızdırmaz) */
    function db_table_exists(PDO $pdo, string $table): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        try {
            $pdo->query("SELECT 1 FROM `$table` LIMIT 0");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}
