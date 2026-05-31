<?php
// =========================================================
// scripts/fix_brand_column.php — Sprint 34 ACİL onarım
// loading_records.brand kolonunu garanti eder (idempotent).
// Erişim: CLI  →  php scripts/fix_brand_column.php
//         Web  →  yalnızca giriş yapmış admin
// ⚠ Tek seferlik; çalıştıktan sonra silinmesi önerilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$cli = (PHP_SAPI === 'cli');
if (!$cli) {
    require_login();
    if (!function_exists('is_admin') || !is_admin()) {
        http_response_code(403);
        die('Bu işlem yalnızca admin kullanıcılar içindir.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = db();
$log = [];

try {
    $has = $pdo->query("SHOW COLUMNS FROM `loading_records` LIKE 'brand'")->fetchColumn();
    if ($has) {
        $log[] = 'ℹ brand kolonu zaten mevcut — işlem gerekmiyor.';
    } else {
        $pdo->exec("ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL");
        $log[] = '✅ brand kolonu eklendi: VARCHAR(20) NULL';
    }
} catch (PDOException $e) {
    $log[] = '❌ HATA: ' . $e->getMessage();
    $log[] = '';
    $log[] = 'Yetki (ALTER) sorunuysa phpMyAdmin → SQL sekmesinde şunu çalıştırın:';
    $log[] = 'ALTER TABLE `loading_records` ADD COLUMN `brand` VARCHAR(20) NULL;';
}

$log[] = '';
$log[] = '⚠ Bu script tek seferliktir; çalıştıktan sonra sunucudan silmeniz önerilir.';

echo implode("\n", $log) . "\n";
