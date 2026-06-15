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

// Session cookie jar — tarayıcıya cookie gönderilmez, sunucuda tutulur
if (session_status() === PHP_SESSION_NONE) session_start();

// PATH_INFO: proxy.php/some/path → $_SERVER['PATH_INFO'] = '/some/path'
$path = $_SERVER['PATH_INFO'] ?? '/';

// Query string — kendi kontrol parametrelerimizi (__hksfresh) HKS'ye iletme
parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);
$fresh = isset($qs['__hksfresh']);
unset($qs['__hksfresh']);
$query = http_build_query($qs);

// Taze başlangıç: index.php sayfayı her açtığında bayat/bozuk HKS oturumunu
// temizle. Aksi halde önceki başarısız denemenin cookie'si "tarayıcınızı
// güncelleyin" gibi bozuk sayfaları getirmeye devam edebilir.
if ($fresh) {
    unset($_SESSION['hks_proxy_cookies']);
}

$target_url = HKS_ORIGIN . $path . ($query !== '' ? '?' . $query : '');

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
$err   = curl_error($ch);
$info  = curl_getinfo($ch);
curl_close($ch);

// ── DEBUG modu: proxy.php?__hksdebug=1 ──────────────────────────
// HKS'ye ne gönderdiğimizi ve HKS'nin ne döndürdüğünü düz metin gösterir.
// Sadece giriş yapmış kullanıcı erişebilir (helpers.php require_login).
if (isset($_GET['__hksdebug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $dbg_hdr_size = (int)($info['header_size'] ?? 0);
    $dbg_resp_hdr = $raw !== false ? substr((string)$raw, 0, $dbg_hdr_size) : '';
    $dbg_body     = $raw !== false ? substr((string)$raw, $dbg_hdr_size) : '';
    echo "=== HEDEF URL ===\n{$target_url}\n\n";
    echo "=== GÖNDERİLEN İSTEK BAŞLIKLARI ===\n";
    echo implode("\n", $req_headers) . "\n\n";
    echo "=== cURL HATASI ===\nerrno={$errno}  msg={$err}\n\n";
    echo "=== HTTP DURUM ===\n" . (int)($info['http_code'] ?? 0) . "\n\n";
    echo "=== HKS YANIT BAŞLIKLARI ===\n{$dbg_resp_hdr}\n";
    echo "=== YANIT GÖVDESİ (ilk 3000 karakter) ===\n";
    echo substr($dbg_body, 0, 3000) . "\n";
    exit;
}

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
