<?php
// =========================================================
// scripts/kasa_ui_render.php — Kasa sayfalarını TARAYICI kontrolü için GERÇEK HTML olarak basar
// (geçici dosya SQLite + uydurma örnek veri; canlı DB'ye HİÇ dokunmaz; stub auth = admin). stok_ui_render.php aynası.
//   php scripts/kasa_ui_render.php <fisler|tanimlar|cikti> [parametre=deger ...] [--sema|--bos|--ornek|--yonetici-degil] > _test_kasa.html
//   Varsayılan: örnek hareketlerle dolu (fisler). --ornek = hareketsiz kasalar · --bos = hiç kasa yok · --sema = tablolar YOK.
//   Örnekler: fisler kasa=1 · fisler kasa=1 id=3 · tanimlar sekme=gider · cikti kasa=1 · cikti kasa=1 bicim=csv
//   Örnek kasa id'leri: 1 Merkez Kasa (nakit TRY, Merkez Depo) · 2 Örnek Banka TL · 3 Döviz Kasası (USD) · 4 Şube Kasası (Şube Depo → aktif depoda görünmez)
//   Çıktıdaki assets/ yolları mutlak file:// olarak yeniden yazılır.
// =========================================================
$e2e = sys_get_temp_dir() . '/kasa_render_' . getmypid() . '.sqlite';
putenv('KASA_E2E_DB=' . $e2e);
require_once __DIR__ . '/_kasa_test_lib.php';
$args = array_slice($argv, 1);
$bayrak = []; $get = []; $sayfa = '';
foreach ($args as $a) {
    if (str_starts_with($a, '--')) { $bayrak[substr($a, 2)] = true; continue; }
    if (str_contains($a, '=')) { [$k, $v] = explode('=', $a, 2); $get[$k] = $v; continue; }
    if ($sayfa === '') $sayfa = $a;
}
$dosyalar = ['fisler' => 'kasa_fisleri.php', 'tanimlar' => 'kasa_tanimlari.php', 'cikti' => 'kasa_cikti.php'];
if (!isset($dosyalar[$sayfa])) { fwrite(STDERR, "Kullanım: php scripts/kasa_ui_render.php <fisler|tanimlar|cikti> [parametre=deger ...] [--sema|--bos|--ornek|--yonetici-degil]\n"); exit(2); }
register_shutdown_function(static function () use ($e2e): void { @unlink($e2e); });
$mod = isset($bayrak['sema']) ? 'sema' : (isset($bayrak['bos']) ? 'bos' : (isset($bayrak['ornek']) ? 'ornek' : 'fisler'));
passthru('php ' . escapeshellarg(__DIR__ . '/kasa_ui_e2e_setup.php') . ' ' . escapeshellarg($e2e) . ' ' . $mod . ' >&2');
$r = kasa_t_istek_cagri(['dosya' => $dosyalar[$sayfa], 'get' => $get, 'admin' => !isset($bayrak['yonetici-degil']), 'db' => $e2e,
    'server' => isset($get['parca']) ? ['HTTP_X_REQUESTED_WITH' => 'fetch'] : []]);
$html = preg_replace('#(href|src)="(?:\.\.?/)?assets/#', '$1="file://' . $ROOT . '/assets/', (string)$r['govde']);
fwrite(STDERR, $dosyalar[$sayfa] . ' → HTTP ' . $r['kod'] . ', ' . strlen((string)$html) . " bayt" . ($r['hata'] ? ' · HATA: ' . $r['hata'] : '') . "\n");
echo $html;
