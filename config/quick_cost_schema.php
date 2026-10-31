<?php
// Yalnız açık yönetici POST işlemi için kurucu. Include/GET hiçbir DDL çalıştırmaz.
declare(strict_types=1);
require_once __DIR__ . '/quick_cost_store.php';

/**
 * Yalnız quick_cost_sheets kurulur. Mevcut tablo/kayıtlarda ALTER/DML yoktur.
 * Çağıran sayfa POST + CSRF kapısını bu çağrıdan önce uygulamalıdır.
 * MySQL DDL örtük commit yapabildiğinden çağıranın açık işlemi reddedilir.
 */
function quick_cost_install(PDO $pdo): array
{
    $user = function_exists('current_user') ? current_user() : null;
    if (!$user || (int)($user['id'] ?? 0) <= 0 || !function_exists('is_admin') || !is_admin()) {
        throw new QuickCostAccessException('Yeni maliyet tablosunu yalnız yönetici kurabilir.');
    }
    quick_cost_access(true);
    if ($pdo->inTransaction()) throw new LogicException('Tablo kurulumu açık bir işlem içinde çalıştırılamaz.');
    if (quick_cost_storage_ready($pdo)) return ['created' => false];
    if (!function_exists('audit_log_event')) throw new RuntimeException('Audit altyapısı kullanılamıyor.');

    $path = dirname(__DIR__) . '/docs/sql/quick_cost_sheets.sql';
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Sabit tablo kurulum dosyası okunamadı.');
    // Git'in CRLF/LF dönüşümü dışında dosya sabittir. Yalnız bu teslimdeki
    // gözden geçirilmiş CREATE onaylıdır; SQL dosyası sonradan değişirse kapı kapanır.
    $source = str_replace("\r\n", "\n", $source);
    if (!hash_equals('43c0b1f7571af5c7a92e617751aabac0eb3e0684c629ef5067a7265f2a9bdf9b', hash('sha256', $source))) {
        throw new RuntimeException('Tablo kurulum dosyası kabul edilmiş sürümle aynı değil.');
    }
    $sql = trim((string)preg_replace('/^--[^\n]*(?:\n|$)/m', '', $source));
    if (!preg_match('/\ACREATE TABLE IF NOT EXISTS `quick_cost_sheets`\s*\(.+\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\z/sD', $sql)
        || substr_count($sql, ';') !== 1) {
        throw new RuntimeException('Yalnız tek yeni maliyet tablosunun CREATE komutu çalıştırılabilir.');
    }
    if ($pdo->exec($sql) === false) throw new RuntimeException('Yeni maliyet tablosu kurulamadı.');
    if (!quick_cost_storage_ready($pdo)) throw new RuntimeException('Yeni maliyet tablosu kurulum sonrası doğrulanamadı.');
    // Var olan audit helper kendi hata politikasını korur; DDL geri alma iddiası yoktur.
    audit_log_event('schema_create', 'quick_cost_sheets', null, null, ['table' => 'quick_cost_sheets']);
    return ['created' => true];
}
