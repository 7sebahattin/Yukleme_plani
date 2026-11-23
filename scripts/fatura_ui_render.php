<?php
// =========================================================
// scripts/fatura_ui_render.php — Tek fatura sayfasını GERÇEK kabukla HTML olarak basar (göz kontrolü / ekran görüntüsü için; test değil).
//   php scripts/fatura_ui_render.php <sayfa.php> [anahtar=deger …] [--sema|--bos|--yonetici-degil] > _test_fatura.html
//   ör. php scripts/fatura_ui_render.php satis_faturalari.php yeni=1 · php scripts/fatura_ui_render.php fatura_cikti.php id=3
// Geçici dosya SQLite + örnek veri (fatura_ui_e2e_setup ornek); canlı DB'ye dokunmaz.
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
$args = array_slice($argv, 1);
$dosya = (string)(array_shift($args) ?? 'satis_faturalari.php');
$mod = in_array('--sema', $args, true) ? 'sema' : (in_array('--bos', $args, true) ? 'bos' : 'ornek');
$admin = !in_array('--yonetici-degil', $args, true);
$get = [];
foreach ($args as $a) if (str_contains($a, '=') && !str_starts_with($a, '--')) { [$k, $v] = explode('=', $a, 2); $get[$k] = $v; }
$db = sys_get_temp_dir() . '/fatura_render_' . getmypid() . '.sqlite';
exec('php ' . escapeshellarg(__DIR__ . '/fatura_ui_e2e_setup.php') . ' ' . escapeshellarg($db) . ' ' . $mod, $o, $k);
if ($k !== 0) { fwrite(STDERR, implode("\n", $o) . "\n"); exit(1); }
putenv('FATURA_E2E_DB=' . $db);
require_once __DIR__ . '/_fatura_test_lib.php';
$r = fatura_t_istek_cagri(['dosya' => $dosya, 'get' => $get, 'admin' => $admin, 'db' => $db]);
if ($r['hata']) fwrite(STDERR, 'HATA: ' . $r['hata'] . "\n");
echo $r['govde'];
@unlink($db);
