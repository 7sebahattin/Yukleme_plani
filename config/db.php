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
// NOT: Bu 4 const satırı canlı sunucudaki deploy.php (smart_merge_db) tarafından
// korunur — sözdizimini değiştirmeyin. Yerel/CI ortamında config/local.php içinde
// DB_LOCAL_HOST/NAME/USER/PASS veya ortam değişkeni (ASYA_DB_*) ile ezilebilir.
const DB_HOST    = 'localhost';
const DB_NAME    = 'yukleme_plani';
const DB_USER    = 'root';
const DB_PASS    = '';
const DB_CHARSET = 'utf8mb4';

/** @return array{host:string,name:string,user:string,pass:string,charset:string} */
function db_config_resolve(): array {
    $envPass = getenv('ASYA_DB_PASS');
    return [
        'host'    => defined('DB_LOCAL_HOST')    ? (string)DB_LOCAL_HOST    : ((string)(getenv('ASYA_DB_HOST') ?: DB_HOST)),
        'name'    => defined('DB_LOCAL_NAME')    ? (string)DB_LOCAL_NAME    : ((string)(getenv('ASYA_DB_NAME') ?: DB_NAME)),
        'user'    => defined('DB_LOCAL_USER')    ? (string)DB_LOCAL_USER    : ((string)(getenv('ASYA_DB_USER') ?: DB_USER)),
        'pass'    => defined('DB_LOCAL_PASS')    ? (string)DB_LOCAL_PASS    : ($envPass !== false ? (string)$envPass : DB_PASS),
        'charset' => defined('DB_LOCAL_CHARSET') ? (string)DB_LOCAL_CHARSET : ((string)(getenv('ASYA_DB_CHARSET') ?: DB_CHARSET)),
    ];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg  = db_config_resolve();
        $dsn  = 'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'];
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
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
