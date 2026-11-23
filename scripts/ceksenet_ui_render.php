<?php
// =========================================================
// scripts/ceksenet_ui_render.php — Çek-Senet sayfalarını TARAYICI kontrolü için GERÇEK HTML olarak basar
// (geçici dosya SQLite + uydurma örnek veri; canlı DB'ye HİÇ dokunmaz; stub auth = admin). kasa_ui_render.php aynası.
//   php scripts/ceksenet_ui_render.php <cek|senet|tanimlar|cikti> [parametre=deger ...] [--sema|--bos|--kasasiz|--ornek|--yonetici-degil] > _test_ceksenet.html
//   Varsayılan: örnek bordrolarla dolu (fisler). --ornek = kayıtsız · --bos = cari + kasa yok · --kasasiz = kasa yok · --sema = tablolar YOK.
//   Örnekler: cek yon=alinan · cek id=2 · cek yeni=1 yon=verilen · senet · tanimlar sekme=karne · cikti bordro=BRD-2026-000001
//   Çıktıdaki assets/ yolları mutlak file:// olarak yeniden yazılır.
// =========================================================
$e2e = sys_get_temp_dir() . '/ceksenet_render_' . getmypid() . '.sqlite';
putenv('CEKSENET_E2E_DB=' . $e2e);
require_once __DIR__ . '/_ceksenet_test_lib.php';
$args = array_slice($argv, 1);
$bayrak = []; $get = []; $sayfa = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--')) { $bayrak[substr($a, 2)] = true; continue; }
    if (str_contains($a, '=')) { [$k, $v] = explode('=', $a, 2); $get[$k] = $v; continue; }
    if ($sayfa === '') $sayfa = $a;
}
$dosyalar = ['cek' => 'cek_dekontu.php', 'senet' => 'senet_dekontu.php', 'tanimlar' => 'cek_tanimlari.php', 'cikti' => 'cek_cikti.php'];
if (!isset($dosyalar[$sayfa])) { fwrite(STDERR, "Kullanım: php scripts/ceksenet_ui_render.php <cek|senet|tanimlar|cikti> [parametre=deger ...] [--sema|--bos|--kasasiz|--ornek|--yonetici-degil]\n"); exit(2); }
register_shutdown_function(static function () use ($e2e): void { @unlink($e2e); });
$mod = isset($bayrak['sema']) ? 'sema' : (isset($bayrak['bos']) ? 'bos' : (isset($bayrak['kasasiz']) ? 'kasasiz' : (isset($bayrak['ornek']) ? 'ornek' : 'fisler')));
passthru('php ' . escapeshellarg(__DIR__ . '/ceksenet_ui_e2e_setup.php') . ' ' . escapeshellarg($e2e) . ' ' . $mod . ' >&2');
$r = ceksenet_t_istek_cagri(['dosya' => $dosyalar[$sayfa], 'get' => $get, 'admin' => !isset($bayrak['yonetici-degil']), 'db' => $e2e,
    'server' => isset($get['parca']) ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []]);
$html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', (string)$r['govde']);
fwrite(STDERR, $dosyalar[$sayfa] . ' → HTTP ' . $r['kod'] . ', ' . strlen((string)$html) . " bayt" . ($r['hata'] ? ' · HATA: ' . $r['hata'] : '') . "\n");
echo $html;
