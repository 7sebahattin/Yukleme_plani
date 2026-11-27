<?php
// =========================================================
// api_chrome_whatsapp.php — ASYA WHATSAPP WEB BRIDGE API (Aşama 1)
//
// Bağımsız Chrome Manifest V3 eklentisi API uç noktası.
// Mevcut api_whatsapp_webhook.php veya Evolution/Baileys altyapısına DOKUNMAZ.
//
// İşlemler (?action=... veya JSON gövdesinde action):
//   pair             POST (yetkisiz, hız sınırlı)  — Tek kullanımlık kodu token'a çevirir
//   status           GET/POST (Bearer)             — Bağlantı, kullanıcı ve yetki durumu
//   schema           GET/POST (Bearer)             — Gerçek Yeni Beyan dinamik alan şeması
//   check_duplicates POST (Bearer)                 — Mesaj listesi için mükerrer ön kontrolü
//   parse            POST (Bearer, beyan.create)   — AI ile Yeni Beyan alan eşleştirmesi (kaydetmez)
//   create_draft     POST (Bearer, beyan.create)   — Kullanıcı onayı (confirm=true) sonrası taslak kaydı
//   recent           GET/POST (Bearer)             — Son aktarılan mesajlar ve taslaklar
//   disconnect       POST (Bearer)                 — Mevcut erişim token'ını iptal eder
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/chrome_whatsapp_bridge.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// CORS: yalnız izinli Chrome eklentisi veya Asya origin'i
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowedOrigin = cw_bridge_allowed_origin(is_string($origin) ? $origin : null);
if ($allowedOrigin !== null) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 600');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code($allowedOrigin !== null ? 204 : 403);
    exit;
}

if (!function_exists('cw_api_respond')) {
    function cw_api_respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// HTTPS zorunluluğu (canlıda HTTP reddedilir; localhost/test istisnası helper içinde)
if (!cw_bridge_is_https_request()) {
    cw_api_respond(['ok' => false, 'error' => 'Bu API yalnızca HTTPS üzerinden kullanılabilir.'], 426);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    cw_api_respond(['ok' => false, 'error' => 'Yalnızca GET ve POST desteklenir.'], 405);
}

// İstek gövdesi boyutu kontrolü
$rawBody = '';
$body = [];
if ($method === 'POST') {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > CW_BRIDGE_MAX_BODY_BYTES) {
        cw_api_respond(['ok' => false, 'error' => 'İstek boyutu sınırı aşıldı (en fazla 128 KB).'], 413);
    }
    $rawBody = (string)file_get_contents('php://input', false, null, 0, CW_BRIDGE_MAX_BODY_BYTES + 1);
    if (strlen($rawBody) > CW_BRIDGE_MAX_BODY_BYTES) {
        cw_api_respond(['ok' => false, 'error' => 'İstek boyutu sınırı aşıldı (en fazla 128 KB).'], 413);
    }
    if (trim($rawBody) !== '') {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            cw_api_respond(['ok' => false, 'error' => 'Geçersiz JSON gövdesi.'], 400);
        }
        $body = $decoded;
    }
}

$action = strtolower(trim((string)($_GET['action'] ?? $body['action'] ?? '')));
if ($action === '') {
    cw_api_respond(['ok' => false, 'error' => 'Eksik action parametresi.'], 400);
}

$clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$pdo = db();

// ── 1. Eşleştirme (Bearer gerektirmez, sıkı hız sınırlı) ──
if ($action === 'pair') {
    if ($method !== 'POST') {
        cw_api_respond(['ok' => false, 'error' => 'pair işlemi için POST gereklidir.'], 405);
    }
    $rl = cw_bridge_rate_limit('pair|' . $clientIp, 10, 300);
    if (!$rl['ok']) {
        header('Retry-After: ' . $rl['retry_after']);
        cw_api_respond(['ok' => false, 'error' => 'Çok fazla eşleştirme denemesi. Lütfen bekleyin.'], 429);
    }
    $code = (string)($body['code'] ?? '');
    $deviceLabel = (string)($body['device_label'] ?? 'Chrome Eklentisi');
    $res = cw_bridge_exchange_pairing_code($pdo, $code, $deviceLabel);
    if (!$res['ok']) {
        cw_api_respond(['ok' => false, 'error' => (string)($res['error'] ?? 'Eşleştirme başarısız.')], 401);
    }
    // Kullanıcı bilgisini ekle
    $u = $pdo->prepare('SELECT id, username, display_name FROM users WHERE id = ? LIMIT 1');
    $u->execute([(int)$res['user_id']]);
    $uRow = $u->fetch(PDO::FETCH_ASSOC) ?: [];
    cw_api_respond([
        'ok'           => true,
        'token'        => $res['token'],
        'token_id'     => $res['token_id'],
        'device_label' => $res['device_label'],
        'user'         => [
            'id'           => (int)$res['user_id'],
            'username'     => (string)($uRow['username'] ?? ''),
            'display_name' => (string)($uRow['display_name'] ?? $uRow['username'] ?? ''),
        ],
    ]);
}

// ── 2. Bearer Token Doğrulama (diğer tüm işlemler) ──
$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authHeader === '' && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    foreach ($hdrs as $hk => $hv) {
        if (strtolower((string)$hk) === 'authorization') {
            $authHeader = (string)$hv;
            break;
        }
    }
}
$bearer = '';
if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($authHeader), $m)) {
    $bearer = $m[1];
}
if ($bearer === '') {
    cw_api_respond(['ok' => false, 'error' => 'Yetkilendirme gerekli (Bearer token eksik).'], 401);
}

$auth = cw_bridge_authenticate_token($pdo, $bearer);
if ($auth === null) {
    cw_api_respond(['ok' => false, 'error' => 'Erişim token\'ı geçersiz veya iptal edilmiş.'], 401);
}

// Kullanıcıyı Asya yetki sistemine bağla (can_beyan, can_beyan_kart, beyan_protect_input)
if (function_exists('auth_reset_cache') && function_exists('auth_cache_store')) {
    auth_reset_cache();
    $store = &auth_cache_store();
    $store['current_user'] = $auth['user'];
}

// Token bazlı hız sınırı
$rlLimit = ($action === 'parse') ? 25 : 90;
$rl = cw_bridge_rate_limit('tok|' . $auth['token_id'] . '|' . $action, $rlLimit, 60);
if (!$rl['ok']) {
    header('Retry-After: ' . $rl['retry_after']);
    cw_api_respond(['ok' => false, 'error' => 'Hız sınırı aşıldı. Lütfen kısa süre sonra tekrar deneyin.'], 429);
}

$canCreate = function_exists('can_beyan') ? can_beyan('create') : true;
$canRead   = function_exists('can_beyan') ? (can_beyan('read') || $canCreate) : true;

if (!$canRead && $action !== 'disconnect') {
    cw_api_respond(['ok' => false, 'error' => 'Beyan modülüne erişim yetkiniz yok.'], 403);
}

// ── 3. İşlem Yönlendirme ──

if ($action === 'status') {
    $aiInfo = ['provider' => (string)AI_PROVIDER, 'model' => wa_etkin_model((string)AI_PROVIDER)];
    try {
        $svc = cw_bridge_resolve_ai_service($pdo);
        $aiInfo = ['provider' => $svc->saglayici(), 'model' => $svc->model()];
    } catch (Throwable $e) {}

    cw_api_respond([
        'ok'             => true,
        'connected'      => true,
        'token_id'       => $auth['token_id'],
        'device_label'   => $auth['device_label'],
        'user'           => [
            'id'           => $auth['user']['id'],
            'username'     => $auth['user']['username'],
            'display_name' => $auth['user']['display_name'],
        ],
        'permissions'    => [
            'beyan_read'   => $canRead,
            'beyan_create' => $canCreate,
        ],
        'ai'             => $aiInfo,
        'schema_version' => CW_BRIDGE_SCHEMA_VERSION,
        'parsers'        => array_values(cw_bridge_parser_registry()),
    ]);
}

if ($action === 'schema') {
    $schema = cw_bridge_beyan_schema($pdo, true);
    cw_api_respond([
        'ok'     => true,
        'schema' => $schema,
    ]);
}

if ($action === 'disconnect') {
    if ($method !== 'POST') {
        cw_api_respond(['ok' => false, 'error' => 'disconnect işlemi için POST gereklidir.'], 405);
    }
    cw_bridge_revoke_token($pdo, (int)$auth['token_id'], (int)$auth['user']['id']);
    cw_api_respond(['ok' => true, 'disconnected' => true]);
}

if ($action === 'check_duplicates') {
    if ($method !== 'POST') {
        cw_api_respond(['ok' => false, 'error' => 'check_duplicates için POST gereklidir.'], 405);
    }
    $chatName = trim((string)($body['chat_name'] ?? ''));
    $messages = is_array($body['messages'] ?? null) ? array_slice($body['messages'], 0, 100) : [];
    $results = [];
    foreach ($messages as $idx => $m) {
        if (!is_array($m)) continue;
        $sid = trim((string)($m['source_message_id'] ?? ''));
        $sender = trim((string)($m['sender_name'] ?? ''));
        $ts = trim((string)($m['message_timestamp'] ?? ''));
        $txt = trim((string)($m['raw_text'] ?? ''));
        if ($txt === '') continue;
        $h = cw_bridge_message_hashes($chatName, $sender, $ts, $txt);
        $dup = cw_bridge_find_duplicate($pdo, $chatName, $sid, $h['normalized_hash'], $h['text_hash']);
        if ($dup !== null) {
            $results[(string)($m['client_id'] ?? $idx)] = [
                'duplicate'         => true,
                'bridge_message_id' => (int)$dup['id'],
                'status'            => (string)$dup['status'],
                'declaration_id'    => $dup['declaration_id'] !== null ? (int)$dup['declaration_id'] : null,
            ];
        }
    }
    cw_api_respond(['ok' => true, 'duplicates' => $results]);
}

if ($action === 'parse') {
    if ($method !== 'POST') {
        cw_api_respond(['ok' => false, 'error' => 'parse işlemi için POST gereklidir.'], 405);
    }
    if (!$canCreate) {
        cw_api_respond(['ok' => false, 'error' => 'Yeni beyan oluşturma yetkiniz yok.'], 403);
    }
    $res = cw_bridge_process_message($pdo, $body, $auth['user'], (int)$auth['token_id']);
    $status = (int)($res['http_status'] ?? ($res['ok'] ? 200 : 400));
    unset($res['http_status']);
    cw_api_respond($res, $status);
}

if ($action === 'create_draft') {
    if ($method !== 'POST') {
        cw_api_respond(['ok' => false, 'error' => 'create_draft işlemi için POST gereklidir.'], 405);
    }
    if (!$canCreate) {
        cw_api_respond(['ok' => false, 'error' => 'Yeni beyan oluşturma yetkiniz yok.'], 403);
    }
    $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
    $bridgeMsgId = isset($body['bridge_message_id']) ? (int)$body['bridge_message_id'] : null;
    $confirmed = ($body['confirm'] ?? false) === true;

    $res = cw_bridge_create_beyan_draft($pdo, $fields, $auth['user'], $bridgeMsgId, $confirmed);
    $status = (int)($res['http_status'] ?? ($res['ok'] ? 201 : 422));
    unset($res['http_status']);
    cw_api_respond($res, $status);
}

if ($action === 'recent') {
    cw_bridge_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, chat_name, chat_type, sender_name, message_timestamp, reference_key, status, ai_provider, ai_confidence, declaration_id, error_message, created_at
        FROM cw_bridge_messages WHERE user_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([(int)$auth['user']['id']]);
    cw_api_respond(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

cw_api_respond(['ok' => false, 'error' => 'Bilinmeyen action: ' . $action], 400);
