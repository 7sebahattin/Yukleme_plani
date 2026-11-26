<?php
// =========================================================
// api_whatsapp_webhook.php — Evolution API v2 → MESSAGES_UPSERT webhook uç noktası
//
// 4 katman (ayrıntı config/whatsapp_core.php):
//   1. Güvenlik & olay: WEBHOOK_SECRET_TOKEN (hash_equals) · messages.upsert · fromMe yok say · grupta gönderen = key.participant
//   2. Idempotency: message_id zaten varsa işlem yok
//   3. Gürültü süzgeci: kısa / rakamsız-anahtar kelimesiz mesaj AI'a GİTMEZ
//   4. AI ayrıştırma → PHP plaka normalizasyonu → log kaydı → (güvenli+açıksa) loading_records taslağı
//
// Oturum/CSRF YOK (sunucudan sunucuya çağrı); kimlik = gizli anahtar. Her koşulda HTTP 200 + JSON döner:
// Evolution başarısız saydığı isteği tekrar tekrar gönderir (retry fırtınası); hatalar error_log'a yazılır.
// Hızlı yanıt: PHP-FPM/LiteSpeed'de yanıt AI çağrısından ÖNCE kapatılır (Evolution zaman aşımına düşmesin).
// =========================================================
declare(strict_types=1);

ini_set('display_errors', '0');
ignore_user_abort(true);
@set_time_limit(90);
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$GLOBALS['__wa_yanit_gitti'] = false;
ob_start();   // db.php bağlantı hatasında HTML basıp 500 verir; tampon yanıta karışmasın


/** Yanıtı bir kez gönder; mümkünse bağlantıyı kapat ve çalışmaya devam et. */
function wa_yanit(array $govde): void
{
    if ($GLOBALS['__wa_yanit_gitti']) return;
    $GLOBALS['__wa_yanit_gitti'] = true;
    $json = (string)json_encode($govde, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
    }
    while (ob_get_level() > 0) @ob_end_clean();   // araya karışan her çıktıyı (uyarı, db.php hata HTML'i) at
    echo $json;
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
}

// Ölümcül hata olsa bile Evolution 500 görmesin
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[whatsapp] webhook ölümcül hata: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
    wa_yanit(['ok' => false, 'hata' => 'dahili']);
});

try {
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/config/whatsapp_core.php';

    // 1) Güvenlik (önce config sabiti, değilse DB'deki panel token'ı)
    $yetkili = whatsapp_webhook_yetkili($_SERVER, $_GET);
    if (!$yetkili) {
        try {
            $pdo = db();
            $dbSecret = whatsapp_tablolar_hazir($pdo) ? (string)(whatsapp_ayarlar_oku($pdo)['webhook_secret'] ?? '') : '';
            if ($dbSecret !== '') $yetkili = whatsapp_webhook_yetkili($_SERVER, $_GET, $dbSecret);
        } catch (Throwable $e) {
            $yetkili = false;
        }
    }
    if (!$yetkili) {
        error_log('[whatsapp] webhook yetkisiz istek: ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        wa_yanit(['ok' => false, 'yok_sayildi' => 'yetkisiz']);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wa_yanit(['ok' => true, 'yok_sayildi' => 'yontem']); exit; }

    $ham = (string)stream_get_contents(fopen('php://input', 'rb'), 4194304);   // en çok 4 MB
    $payload = json_decode($ham, true);
    if (!is_array($payload)) { wa_yanit(['ok' => true, 'yok_sayildi' => 'govde']); exit; }

    // Hızlı yanıt mümkünse önce yanıtla, sonra işle; değilse işle, sonra yanıtla
    $erken = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    if ($erken) wa_yanit(['ok' => true, 'kabul' => true]);

    $sonuc = whatsapp_webhook_isle(db(), $payload);
    wa_yanit(['ok' => $sonuc['ok'], 'sonuclar' => $sonuc['sonuclar']]);
} catch (Throwable $e) {
    error_log('[whatsapp] webhook hata: ' . $e->getMessage());
    wa_yanit(['ok' => false, 'hata' => 'dahili']);
}
