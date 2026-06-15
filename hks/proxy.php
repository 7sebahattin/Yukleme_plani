<?php
// =========================================================
// hks/proxy.php — Hal Kayıt Sistemi reverse proxy
//
// Kullanım: proxy.php/path/to/page?query=string
// PATH_INFO üzerinden çalışır → Apache AcceptPathInfo default On
//
// Tüm istekler sunucu tarafındaki cURL ile hks.hal.gov.tr'ye
// iletilir. Tarayıcı same-origin görür → cookie sorunu yok.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers.php';  // require_login() burada

const HKS_ORIGIN = 'https://hks.hal.gov.tr';

// Statik varlıklar tarayıcı önbelleğine alınır — proxy hızlanır
const STATIC_CACHE_TTL = 3600; // 1 saat
const STATIC_MIME_TYPES = [
    'text/css', 'text/javascript', 'application/javascript',
    'application/x-javascript', 'image/png', 'image/gif',
    'image/jpeg', 'image/svg+xml', 'image/x-icon',
    'application/x-font-woff', 'font/woff', 'font/woff2',
    'application/font-woff', 'application/vnd.ms-fontobject',
];

// Sunucu tarafı disk önbelleği — oturumlar arası paylaşım, ilk yükten sonra cURL atlanır
const PROXY_DISK_CACHE_DIR = '/tmp/hks_proxy_cache';

// Proxy'nin web köküne göre MUTLAK yolu (ör. /hks/proxy.php).
$proxy_base = $_SERVER['SCRIPT_NAME'] ?? '/hks/proxy.php';

// Session cookie jar — tarayıcıya cookie gönderilmez, sunucuda tutulur
if (session_status() === PHP_SESSION_NONE) session_start();

// PATH_INFO: proxy.php/some/path → $_SERVER['PATH_INFO'] = '/some/path'
$path = $_SERVER['PATH_INFO'] ?? '/';

// Query string — kendi kontrol parametrelerimizi HKS'ye iletme
parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
$fresh = isset($qs['__hksfresh']);
unset($qs['__hksfresh']);
$query = http_build_query($qs);

// Session'dan cookie'leri oku; taze başlangıçta temizle
if ($fresh) {
    unset($_SESSION['hks_proxy_cookies']);
    $cookie_map = [];
} else {
    $cookie_map = $_SESSION['hks_proxy_cookies'] ?? [];
}

// Session kilidini cURL isteğinden ÖNCE serbest bırak.
// Aksi halde eş zamanlı proxy istekleri (CSS, JS, resimler) sıra bekler
// ve sayfa çok yavaş açılır.
session_write_close();

$target_url = HKS_ORIGIN . $path . ($query !== '' ? '?' . $query : '');

// Statik dosya isteğini tespit et (CSS, JS, resimler — önbelleklenebilir)
$is_static_req = (bool) preg_match('/\.(css|js|png|gif|jpg|jpeg|svg|ico|woff2?|eot|ttf)(\?.*)?$/i', $path);

// Statik varlıklar: önce sunucu tarafı disk önbelleğini kontrol et
// Farklı kullanıcı/oturumlarda aynı CSS/JS dosyası cURL ile tekrar çekilmez.
$disk_cache_file = null;
if ($is_static_req) {
    if (!is_dir(PROXY_DISK_CACHE_DIR)) @mkdir(PROXY_DISK_CACHE_DIR, 0700, true);
    $disk_cache_file = PROXY_DISK_CACHE_DIR . '/' . md5($target_url);
    if (file_exists($disk_cache_file)) {
        $age = time() - filemtime($disk_cache_file);
        if ($age < STATIC_CACHE_TTL) {
            $cached = @unserialize((string) file_get_contents($disk_cache_file));
            if (is_array($cached)) {
                // Tarayıcı ETag 304 desteği
                if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && ($cached['etag'] ?? '') !== '' &&
                    $_SERVER['HTTP_IF_NONE_MATCH'] === $cached['etag']) {
                    http_response_code(304);
                    exit;
                }
                http_response_code(200);
                header('Content-Type: ' . $cached['ct']);
                header('Cache-Control: public, max-age=' . max(1, STATIC_CACHE_TTL - $age));
                if (!empty($cached['etag'])) header('ETag: ' . $cached['etag']);
                echo $cached['body'];
                exit;
            }
        }
    }
}

$cookie_hdr = implode('; ', array_map(
    static fn($k, $v) => $k . '=' . $v,
    array_keys($cookie_map),
    array_values($cookie_map)
));

$method = $_SERVER['REQUEST_METHOD'];

$post_body = '';
if ($method === 'POST') {
    $post_body = (string) file_get_contents('php://input');
    if ($post_body === '' && !empty($_POST)) {
        $post_body = http_build_query($_POST);
    }
}

// Kullanıcının gerçek tarayıcı bilgileri — HKS sunucu tarafı UA tespiti için zorunlu
$browser_ua   = $_SERVER['HTTP_USER_AGENT']      ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
$browser_acc  = $_SERVER['HTTP_ACCEPT']          ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
$browser_lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'tr-TR,tr;q=0.9,en;q=0.8';

$req_headers = [
    'Host: hks.hal.gov.tr',
    'User-Agent: '      . $browser_ua,
    'Accept: '          . $browser_acc,
    'Accept-Language: ' . $browser_lang,
    'Upgrade-Insecure-Requests: 1',
];

// ASP.NET UpdatePanel / AJAX istekleri için gerekli başlıkları ilet.
// "Künye Sorgula" gibi butonlar partial-page postback (UpdatePanel) kullanır;
// bu başlıklar olmadan HKS isteği normal postback sanır ve tam sayfa döndürür.
foreach ([
    'X-Requested-With' => 'HTTP_X_REQUESTED_WITH',
    'X-MicrosoftAjax'  => 'HTTP_X_MICROSOFTAJAX',
] as $hdr_name => $srv_key) {
    if (!empty($_SERVER[$srv_key])) {
        $req_headers[] = $hdr_name . ': ' . $_SERVER[$srv_key];
    }
}

// Statik dosyalar için koşullu istek başlıklarını ilet (304 optimizasyonu)
if ($is_static_req) {
    if (!empty($_SERVER['HTTP_IF_NONE_MATCH'])) {
        $req_headers[] = 'If-None-Match: ' . $_SERVER['HTTP_IF_NONE_MATCH'];
    }
    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $req_headers[] = 'If-Modified-Since: ' . $_SERVER['HTTP_IF_MODIFIED_SINCE'];
    }
} else {
    $req_headers[] = 'Cache-Control: no-cache';
    $req_headers[] = 'Pragma: no-cache';
}

if ($cookie_hdr !== '') {
    $req_headers[] = 'Cookie: ' . $cookie_hdr;
}
if ($method === 'POST') {
    $req_ct = $_SERVER['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded';
    $req_headers[] = 'Content-Type: ' . $req_ct;
    $req_headers[] = 'Content-Length: ' . strlen($post_body);
    $req_headers[] = 'Origin: ' . HKS_ORIGIN;
    $req_headers[] = 'Referer: ' . HKS_ORIGIN . $path;
}

$ch = curl_init($target_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => $req_headers,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_POSTFIELDS     => $method === 'POST' ? $post_body : null,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => '',   // cURL gzip/deflate'i otomatik açar
    CURLOPT_TCP_KEEPALIVE  => 1,
]);

$raw   = curl_exec($ch);
$errno = curl_errno($ch);
$info  = curl_getinfo($ch);
curl_close($ch);

if ($errno || $raw === false) {
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font-family:sans-serif;padding:20px;color:#c00">HKS sitesine bağlanılamadı. Lütfen sayfayı yenileyin.</p>';
    exit;
}

$hdr_size  = (int)$info['header_size'];
$resp_hdrs = substr($raw, 0, $hdr_size);
$body      = substr($raw, $hdr_size);
$http_code = (int)$info['http_code'];

// Content-Type
$content_type = 'text/html; charset=utf-8';
foreach (explode("\r\n", $resp_hdrs) as $h) {
    if (stripos($h, 'content-type:') === 0) {
        $content_type = trim(substr($h, 13));
        break;
    }
}

// ETag / Last-Modified — statik dosyalar için ilet (tarayıcı önbelleği)
$etag          = '';
$last_modified = '';
foreach (explode("\r\n", $resp_hdrs) as $h) {
    if (stripos($h, 'etag:') === 0)          $etag          = trim(substr($h, 5));
    if (stripos($h, 'last-modified:') === 0)  $last_modified = trim(substr($h, 14));
}

// Set-Cookie → server-side session'a kaydet, tarayıcıya iletme
$new_cookies = [];
foreach (explode("\r\n", $resp_hdrs) as $h) {
    if (stripos($h, 'set-cookie:') !== 0) continue;
    $cs = trim(substr($h, 11));
    $nv = explode('=', explode(';', $cs)[0], 2);
    if (count($nv) === 2) {
        $new_cookies[trim($nv[0])] = trim($nv[1]);
    }
}

// Yeni cookie varsa session'ı en kısa süreliğine aç, merge et, kapat
if ($new_cookies) {
    session_start();
    $_SESSION['hks_proxy_cookies'] = array_merge(
        $_SESSION['hks_proxy_cookies'] ?? [],
        $new_cookies
    );
    session_write_close();
}

// Yönlendirme → Location başlığını mutlak proxy yoluyla yeniden yaz
if (in_array($http_code, [301, 302, 303, 307, 308])) {
    foreach (explode("\r\n", $resp_hdrs) as $h) {
        if (stripos($h, 'location:') !== 0) continue;
        $loc = trim(substr($h, 9));
        if (str_starts_with($loc, HKS_ORIGIN)) {
            $loc = substr($loc, strlen(HKS_ORIGIN));
        }
        if (str_starts_with($loc, '/')) {
            header('Location: ' . $proxy_base . $loc, true, $http_code);
        } else {
            header('Location: ' . $loc, true, $http_code);
        }
        exit;
    }
}

// 304 Not Modified — tarayıcı önbelleği geçerli, içerik yok
if ($http_code === 304) {
    http_response_code(304);
    exit;
}

// HTML rewriting
$base_ct  = strtolower(explode(';', $content_type)[0]);
$is_html  = in_array($base_ct, ['text/html', 'application/xhtml+xml'], true);
$is_css   = $base_ct === 'text/css';
$is_static = in_array($base_ct, STATIC_MIME_TYPES, true) || $is_static_req;

if ($is_html) {
    // Tam URL → mutlak proxy yoluna (HTML özelliklerinde VE inline JS string'lerinde çalışır)
    $body = str_replace(
        [HKS_ORIGIN . '/', 'http://hks.hal.gov.tr/'],
        [$proxy_base . '/', $proxy_base . '/'],
        $body
    );
    // Mutlak yolları HTML özelliklerinde yeniden yaz
    $body = preg_replace_callback(
        '/(\b(?:href|src|action|data-src)\s*=\s*["\'])(\/(?!\/))/i',
        static fn($m) => $m[1] . $proxy_base . '/',
        $body
    );
    // CSS url() içindeki mutlak yollar
    $body = preg_replace_callback(
        '/(\burl\(\s*["\']?)(\/(?!\/))/i',
        static fn($m) => $m[1] . $proxy_base . '/',
        $body
    );
    // JavaScript location atamaları — mutlak yolları proxy'e yönlendir
    // Örn: location.href='/Pages/...' veya window.location='/Pages/...'
    $body = preg_replace_callback(
        '/(\blocation(?:\.href)?\s*=\s*["\'])(\/(?!\/))/i',
        static fn($m) => $m[1] . $proxy_base . '/',
        $body
    );
    // iframe'i engelleyebilecek X-Frame-Options meta tag'larını kaldır
    $body = preg_replace(
        '/<meta[^>]+http-equiv\s*=\s*["\']X-Frame-Options["\'][^>]*>/i',
        '',
        $body
    );
}

if ($is_css) {
    $body = str_replace(
        [HKS_ORIGIN . '/', 'http://hks.hal.gov.tr/'],
        [$proxy_base . '/', $proxy_base . '/'],
        $body
    );
    $body = preg_replace_callback(
        '/(\burl\(\s*["\']?)(\/(?!\/))/i',
        static fn($m) => $m[1] . $proxy_base . '/',
        $body
    );
}

// Statik varlığı disk önbelleğine kaydet (sonraki isteklerde cURL atlanır)
if ($disk_cache_file !== null && $is_static && $http_code === 200 && $body !== '') {
    @file_put_contents($disk_cache_file, serialize([
        'ct'   => $content_type,
        'etag' => $etag,
        'body' => $body,
    ]));
}

// Yanıtı gönder
http_response_code($http_code);
header('Content-Type: ' . $content_type);

// Statik dosyalar: tarayıcı önbelleğine al → proxy tekrar çağrılmaz
if ($is_static) {
    header('Cache-Control: public, max-age=' . STATIC_CACHE_TTL);
    if ($etag)          header('ETag: '          . $etag);
    if ($last_modified) header('Last-Modified: ' . $last_modified);
} else {
    header('Cache-Control: no-store, no-cache');
}

echo $body;
