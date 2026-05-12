<?php
// =========================================================
// config/db.php  –  SADECE veritabanı bağlantısı
// Diğer yardımcılar config/helpers.php içindedir.
// =========================================================

declare(strict_types=1);

date_default_timezone_set('Europe/Istanbul');

// --- DB AYARLARI ---
const DB_HOST    = 'localhost';
const DB_NAME    = 'yukleme_plani';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Veritabanı bağlantı hatası: ' . htmlspecialchars($e->getMessage()));
        }

        // Auto-migration: loading_records tablosu varsa type kolonu eksikse ekle
        $table_exists = false;
        try {
            $pdo->query("SELECT 1 FROM `loading_records` LIMIT 0");
            $table_exists = true;
        } catch (PDOException $e) { /* tablo yok, fresh install */ }

        if ($table_exists) {
            $has_col = (bool)$pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'type'")->fetchColumn();
            if (!$has_col) {
                try {
                    $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'yukleme'");
                } catch (PDOException $mig_e) {
                    http_response_code(500);
                    die(
                        '<p><b>Veritabanı güncelleme hatası:</b> ' . htmlspecialchars($mig_e->getMessage()) . '</p>'
                        . '<p>Lütfen veritabanı yönetim panelinden (phpMyAdmin vb.) şu SQL\'i çalıştırın:</p>'
                        . '<pre>ALTER TABLE `loading_records` ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT \'yukleme\';</pre>'
                    );
                }
            }
        }
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
