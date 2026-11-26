<?php
// =========================================================
// config/db.php  –  SADECE veritabanı bağlantısı
// Diğer yardımcılar config/helpers.php içindedir.
// =========================================================

declare(strict_types=1);

date_default_timezone_set('Europe/Istanbul');

// B-32: Üretim web isteklerinde ham PHP hata/iz çıktısı gizlenir; global yakalayıcı yapılandırılır.
if (PHP_SAPI !== 'cli') {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    set_exception_handler(static function (Throwable $e): void {
        $log = json_encode([
            'ts'    => date('c'),
            'level' => 'ERROR',
            'type'  => get_class($e),
            'msg'   => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
            'uri'   => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log('[uncaught] ' . ($log !== false ? $log : $e->getMessage()));
        if (!headers_sent()) {
            http_response_code(500);
            $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
            $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains($accept, 'application/json');
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Sunucu hatası oluştu.'], JSON_UNESCAPED_UNICODE);
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Sunucu Hatası</title></head>'
           . '<body style="font-family:system-ui,sans-serif;padding:2rem;color:#1c2230"><h1>Beklenmeyen bir hata oluştu</h1>'
           . '<p>İşlem tamamlanamadı. Lütfen sayfayı yenileyip tekrar deneyin.</p></body></html>';
    });
}

// Yerel ortam sabitleri (HKS_CRED_KEY vb.) — .gitignore'da
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

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
            error_log('[db_connect] ' . $e->getMessage());
            http_response_code(500);
            die('Veritabanı bağlantı hatası. Lütfen daha sonra tekrar deneyin.');
        }
        // B-01: Çalışma zamanı otomatik şema/DDL/UPDATE migrasyonları kaldırıldı.
        // Tüm şema güncellemeleri yalnızca admin tarafından migrate.php › Çekirdek Şema kartından çalıştırılır.
    }
    return $pdo;
}

require_once __DIR__ . '/helpers.php';
