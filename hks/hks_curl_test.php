<?php
// HKS cURL Bağlantı Testi — tek sayfalık, bağımsız script
// Kullanım: tarayıcıdan aç veya: php hks/hks_curl_test.php
// Bu dosyayı test sonrası silin veya web'den erişilmez yapın.
declare(strict_types=1);

// IP tabanlı test adresleri (95.0.51.130) erişilemediği için kaldırıldı —
// tüm uygulama artık canlı domain üzerinden çalışıyor.
$targets = [
    'Canlı — GenelService'    => 'https://hks.hal.gov.tr/WebServices/GenelService.svc?wsdl',
    'Canlı — UrunService'     => 'https://hks.hal.gov.tr/WebServices/UrunService.svc?wsdl',
    'Canlı — BildirimService' => 'https://hks.hal.gov.tr/WebServices/BildirimService.svc?wsdl',
];

$timeout = 12;

function curl_probe(string $url, int $timeout): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'bytes' => 0, 'ms' => 0, 'error' => 'cURL extension yok'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'HKS-cURL-Test/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/xml,application/xml,*/*'],
    ]);
    $t0   = microtime(true);
    $body = curl_exec($ch);
    $ms   = (int)round((microtime(true) - $t0) * 1000);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $bytes   = $body !== false ? strlen($body) : 0;
    $snippet = '';
    if ($body && $bytes > 0) {
        $snippet = substr(strip_tags((string)$body), 0, 120);
        $snippet = preg_replace('/\s+/', ' ', trim($snippet));
    }

    return [
        'ok'      => $code >= 200 && $code < 400 && $bytes > 50,
        'code'    => $code,
        'bytes'   => $bytes,
        'ms'      => $ms,
        'error'   => $err,
        'snippet' => $snippet,
    ];
}

// CLI çalışması
if (PHP_SAPI === 'cli') {
    echo "HKS cURL Bağlantı Testi\n";
    echo str_repeat('=', 60) . "\n";
    foreach ($targets as $label => $url) {
        echo "\n[$label]\n$url\n";
        $r = curl_probe($url, $timeout);
        printf("  HTTP: %d | Boyut: %d bayt | Süre: %dms\n", $r['code'], $r['bytes'], $r['ms']);
        if ($r['error'])   echo "  Hata: {$r['error']}\n";
        if ($r['snippet']) echo "  İçerik: {$r['snippet']}\n";
        echo "  Sonuç: " . ($r['ok'] ? "✓ BAŞARILI" : "✗ BAŞARISIZ") . "\n";
    }
    echo "\n" . str_repeat('=', 60) . "\n";
    exit(0);
}

// Web çalışması — basit auth kontrolü (bu scripti açık bırakmayın)
session_start();
$auth_ok = false;
if (isset($_SESSION['user_id']) || isset($_SESSION['hks_company_id'])) {
    $auth_ok = true;
}
// Alternatif: basit GET şifresi — ?key=test1234
if (($_GET['key'] ?? '') === 'test1234') {
    $auth_ok = true;
}
if (!$auth_ok) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Erişim için önce uygulamaya giriş yapın veya ?key=test1234 ekleyin.";
    exit;
}

$results = [];
foreach ($targets as $label => $url) {
    $results[$label] = ['url' => $url] + curl_probe($url, $timeout);
}

$server_ip = @file_get_contents('https://api.ipify.org') ?: 'bilinmiyor';
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HKS cURL Bağlantı Testi</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#f1f5f9;margin:0;padding:20px;font-size:.9rem}
h1{font-size:1.2rem;margin:0 0 4px}
.sub{color:#64748b;font-size:.82rem;margin-bottom:20px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;margin-bottom:12px}
.label{font-weight:700;font-size:.95rem;margin-bottom:4px}
.url{font-size:.75rem;color:#64748b;word-break:break-all;margin-bottom:8px;font-family:monospace}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:4px}
.badge{padding:3px 9px;border-radius:20px;font-size:.78rem;font-weight:700}
.ok{background:#dcfce7;color:#15803d}
.fail{background:#fee2e2;color:#b91c1c}
.warn{background:#fef9c3;color:#854d0e}
.meta{font-size:.8rem;color:#475569}
.snippet{font-size:.75rem;font-family:monospace;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:6px 10px;margin-top:6px;word-break:break-all;white-space:pre-wrap}
.sep{border:none;border-top:2px solid #e2e8f0;margin:16px 0}
.server-ip{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:.83rem;margin-bottom:16px}
</style>
</head>
<body>
<h1>🔌 HKS cURL Bağlantı Testi</h1>
<p class="sub">Sunucunun HKS adreslerine ulaşıp ulaşamadığını gösterir. Bu dosyayı test sonrası silin.</p>

<div class="server-ip">
    🌐 <strong>Sunucu dış IP'si:</strong> <?= htmlspecialchars($server_ip) ?>
    <small style="color:#64748b"> — HKS whitelist için bu IP'yi Bakanlık'a bildirin</small>
</div>

<?php
$live_group = false;
foreach ($results as $label => $r):
    if (!$live_group) { echo '<hr class="sep"><p style="font-weight:700;color:#1e293b;margin:0 0 8px">🔴 Canlı Ortam (hks.hal.gov.tr)</p>'; $live_group = true; }
    $badge_cls = $r['ok'] ? 'ok' : ($r['code'] > 0 ? 'warn' : 'fail');
    $badge_txt = $r['ok'] ? '✓ BAŞARILI' : ($r['code'] > 0 ? "HTTP {$r['code']}" : '✗ BAĞLANAMADI');
?>
<div class="card">
    <div class="label"><?= htmlspecialchars($label) ?></div>
    <div class="url"><?= htmlspecialchars($r['url']) ?></div>
    <div class="row">
        <span class="badge <?= $badge_cls ?>"><?= $badge_txt ?></span>
        <span class="meta">HTTP <?= $r['code'] ?> · <?= number_format($r['bytes']) ?> bayt · <?= $r['ms'] ?> ms</span>
    </div>
    <?php if ($r['error']): ?>
    <div class="meta" style="color:#dc2626">⚠ <?= htmlspecialchars($r['error']) ?></div>
    <?php endif; ?>
    <?php if ($r['snippet']): ?>
    <div class="snippet"><?= htmlspecialchars($r['snippet']) ?></div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<p style="font-size:.75rem;color:#94a3b8;margin-top:16px">
    Tarih: <?= date('d.m.Y H:i:s') ?> · Timeout: <?= $timeout ?>s · PHP <?= PHP_VERSION ?>
</p>
</body>
</html>
