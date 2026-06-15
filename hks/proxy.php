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

// PATH_INFO: proxy.php/some/path → $_SERVER['PATH_INFO'] = '/some/path'
$path  = $_SERVER['PATH_INFO'] ?? '/';
$query = $_SERVER['QUERY_STRING'] ?? '';
$target_url = HKS_ORIGIN . $path . ($query !== '' ? '?' . $query : '');

// Session cookie jar — tarayıcıya cookie gönderilmez, sunucuda tutulur
if (session_status() === PHP_SESSION_NONE) session_start();
$cookie_map = $_SESSION['hks_proxy_cookies'] ?? [];
$cookie_hdr = implode('; ', array_map(
    static fn($k, $v) => $k . '=' . $v,
    array_keys($cookie_map),
    array_values($cookie_map)
));

$method = $_SERVER['REQUEST_METHOD'];

// POST body — php://input x-www-form-urlencoded için her zaman okunabilir
$post_body = '';
if ($method === 'POST') {
    $post_body = (string) file_get_contents('php://input');
    if ($post_body === '' && !empty($_POST)) {
        $post_body = http_build_query($_POST);
    }
}

// Request headers
$req_headers = [
    'Host: hks.hal.gov.tr',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
    'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Cache-Control: no-cache',
    'Pragma: no-cache',
];
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
    CURLOPT_FOLLOWLOCATION => false,         // Yönlendirmeleri kendimiz işleriz
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => $req_headers,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_POSTFIELDS     => $method === 'POST' ? $post_body : null,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_ENCODING       => '',            // cURL gzip/deflate'i otomatik açar
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

// Set-Cookie → server-side session'a kaydet, tarayıcıya iletme
foreach (explode("\r\n", $resp_hdrs) as $h) {
    if (stripos($h, 'set-cookie:') !== 0) continue;
    $cs  = trim(substr($h, 11));
    $nv  = explode('=', explode(';', $cs)[0], 2);
    if (count($nv) === 2) {
        $cookie_map[trim($nv[0])] = trim($nv[1]);
    }
}
$_SESSION['hks_proxy_cookies'] = $cookie_map;

// Yönlendirme → Location başlığını proxy üzerinden yeniden yaz
if (in_array($http_code, [301, 302, 303, 307, 308])) {
    foreach (explode("\r\n", $resp_hdrs) as $h) {
        if (stripos($h, 'location:') !== 0) continue;
        $loc = trim(substr($h, 9));
        // Tam URL ise origin'i at
        if (str_starts_with($loc, HKS_ORIGIN)) {
            $loc = substr($loc, strlen(HKS_ORIGIN));
        }
        if (str_starts_with($loc, '/')) {
            // Mutlak yol → proxy PATH_INFO üzerinden
            header('Location: proxy.php' . $loc, true, $http_code);
        } else {
            header('Location: ' . $loc, true, $http_code);
        }
        exit;
    }
}

// HTML rewriting — yalnızca metin içeriklerde uygulanır
$is_html = str_contains($content_type, 'text/html') ||
           str_contains($content_type, 'application/xhtml');
$is_css  = str_contains($content_type, 'text/css');

if ($is_html) {
    // 1. Tam URL (origin dahil) → proxy.php'ye yönlendir
    $body = str_replace(
        [HKS_ORIGIN . '/', 'http://hks.hal.gov.tr/'],
        ['proxy.php/', 'proxy.php/'],
        $body
    );
    // 2. Mutlak yolları HTML özelliklerinde yeniden yaz
    //    href="/path" src="/path" action="/path"  →  proxy.php/path
    //    Protokol-bağıl //cdn... adreslerine dokunma
    $body = preg_replace_callback(
        '/(\b(?:href|src|action|data-src)\s*=\s*["\'])(\/(?!\/))/i',
        static fn($m) => $m[1] . 'proxy.php/',
        $body
    );
    // 3. CSS url() içindeki mutlak yollar
    $body = preg_replace_callback(
        '/(\burl\(\s*["\']?)(\/(?!\/))/i',
        static fn($m) => $m[1] . 'proxy.php/',
        $body
    );
    // 4. X-Frame-Options ve CSP frame-ancestors başlıkları zaten iletilmedi —
    //    iframe'i engelleyebilecek meta tag'larını da kaldır
    $body = preg_replace(
        '/<meta[^>]+http-equiv\s*=\s*["\']X-Frame-Options["\'][^>]*>/i',
        '',
        $body
    );
}

if ($is_css) {
    $body = str_replace(
        [HKS_ORIGIN . '/', 'http://hks.hal.gov.tr/'],
        ['proxy.php/', 'proxy.php/'],
        $body
    );
    $body = preg_replace_callback(
        '/(\burl\(\s*["\']?)(\/(?!\/))/i',
        static fn($m) => $m[1] . 'proxy.php/',
        $body
    );
}

// Yanıtı gönder — upstream'den X-Frame-Options / CSP iletilmez
http_response_code($http_code);
header('Content-Type: ' . $content_type);
echo $body;
