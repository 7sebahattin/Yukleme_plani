<?php
// =============================================================================
// HAL KAYIT — OPcache Teşhis & Sıfırlama (yalnız admin, POST + CSRF)
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
if (!is_admin()) {
    http_response_code(403);
    die('Bu sayfa yalnızca yöneticiye açıktır.');
}

$soap_path = __DIR__ . '/hks_soap.php';
$src = @file_get_contents($soap_path);
$guncel = ($src !== false)
    && strpos($src, 'function hks_stok_ozet') !== false
    && strpos($src, 'BildirimServisBildirimSorgu') !== false;

$resetMesaj = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    if (function_exists('opcache_reset')) {
        $ok = @opcache_reset();
        $resetMesaj = $ok ? 'OK — OPcache sıfırlandı.' : 'OPcache kapalı ya da zaten boş.';
    } else {
        $resetMesaj = 'Bu sunucuda OPcache etkin değil.';
    }
}

$csrf = csrf_token();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hal Kayıt — OPcache Teşhis</title>
<style>
  body { font: 15px/1.6 system-ui, sans-serif; max-width: 620px; margin: 28px auto; padding: 0 16px; background: #0f172a; color: #e2e8f0; }
  .card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 18px; margin-bottom: 14px; }
  button { padding: 10px 18px; border: 0; border-radius: 8px; background: #f97316; color: #111; font-weight: 700; cursor: pointer; }
  .ok { color: #4ade80; font-weight: 600; }
</style>
</head>
<body>
  <div class="card">
    <h1 style="margin-top:0;font-size:18px">Hal Kayıt — OPcache Teşhis</h1>
    <p><strong>Dosya:</strong> <?= h(basename($soap_path)) ?></p>
    <p><strong>Değişiklik zamanı:</strong> <?= h(@filemtime($soap_path) ? date('Y-m-d H:i:s', (int)filemtime($soap_path)) : '?') ?></p>
    <p><strong>Stok kodu durumu:</strong> <?= $guncel ? 'GÜNCEL (BildirimServisBildirimSorgu)' : 'ESKİ (deploy edilmemiş)' ?></p>
    <?php if ($resetMesaj !== null): ?>
      <p class="ok"><?= h($resetMesaj) ?></p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button type="submit">OPcache Sıfırla</button>
    </form>
  </div>
</body>
</html>
