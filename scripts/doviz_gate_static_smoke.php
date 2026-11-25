<?php
// =========================================================
// scripts/doviz_gate_static_smoke.php — Döviz & Günlük Kurlar modülü statik güvenlik & mimari değişmezleri
//   php scripts/doviz_gate_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $n\n"; }
    else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; }
}
function oku(string $f): string { global $ROOT; return is_file($ROOT . '/' . $f) ? (string)file_get_contents($ROOT . '/' . $f) : ''; }

$core   = oku('config/doviz_core.php');
$ui     = oku('config/doviz_ui.php');
$kaydet = oku('doviz_kaydet.php');
$api    = oku('api_doviz_kur.php');
$gk     = oku('gunluk_kurlar.php');
$dt     = oku('doviz_tanimlari.php');
$js     = oku('assets/doviz.js');
$otoJs  = oku('assets/doviz_oto.js');
$css    = oku('assets/doviz.css');
$sw     = oku('sw.js');

echo "=== 1. Dosya varlığı & doğrudan erişim koruması ===\n";
foreach (['config/doviz_core.php', 'config/doviz_ui.php', 'doviz_kaydet.php', 'api_doviz_kur.php', 'gunluk_kurlar.php', 'doviz_tanimlari.php', 'assets/doviz.js', 'assets/doviz_oto.js', 'assets/doviz.css'] as $f) {
    ok("dosya mevcut: $f", is_file($ROOT . '/' . $f));
}
ok('doviz_core.php doğrudan erişim koruması (SCRIPT_FILENAME)', str_contains($core, 'SCRIPT_FILENAME') && str_contains($core, 'http_response_code(404)'));
ok('doviz_ui.php doğrudan erişim koruması (SCRIPT_FILENAME)', str_contains($ui, 'SCRIPT_FILENAME') && str_contains($ui, 'http_response_code(404)'));

echo "=== 2. Güvenlik kapıları (CSRF, POST-only, XXE, Yetki) ===\n";
ok('doviz_core.php LIBXML_NONET kullanır (XXE koruması)', str_contains($core, 'LIBXML_NONET') && str_contains($core, 'libxml_use_internal_errors'));
ok('doviz_kaydet.php yalnız POST (405 + Allow)', str_contains($kaydet, '405') && str_contains($kaydet, 'Allow: POST'));
ok('doviz_kaydet.php require_login + csrf_check + doviz_sayfa_kapisi + doviz_kapi', str_contains($kaydet, 'require_login()') && str_contains($kaydet, 'csrf_check(') && str_contains($kaydet, 'doviz_sayfa_kapisi(') && str_contains($kaydet, 'doviz_kapi('));
ok('api_doviz_kur.php require_login + JSON başlığı + doviz_hazir', str_contains($api, 'require_login()') && str_contains($api, 'application/json') && str_contains($api, 'doviz_hazir('));

echo "=== 3. Sayfa değişmezleri (GET-only, UI sorgusuz, adblock kuralı) ===\n";
ok('gunluk_kurlar.php GET-only ve muhasebe_sayfa_can', !str_contains($gk, '$_POST') && str_contains($gk, "muhasebe_sayfa_can('gunluk_kurlar')"));
ok('doviz_tanimlari.php GET-only ve muhasebe_sayfa_can', !str_contains($dt, '$_POST') && str_contains($dt, "muhasebe_sayfa_can('doviz_tanimlari')"));
ok('doviz_ui.php içinde DB sorgusu yok (prepare/query/exec yok)', !preg_match('/->(prepare|query|exec)\s*\(/', $ui));
$jsKod = preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $js) ?? $js;
ok('doviz.js içinde localStorage ve innerHTML yok', !str_contains($jsKod, 'localStorage') && !str_contains($jsKod, '.innerHTML'));
$otoKod = preg_replace('/\/\*.*?\*\/|\/\/[^\n]*/s', '', $otoJs) ?? $otoJs;
ok('doviz_oto.js içinde localStorage ve innerHTML yok', !str_contains($otoKod, 'localStorage') && !str_contains($otoKod, '.innerHTML'));
ok('doviz.css içinde dark tema desteği var', str_contains($css, 'data-theme="dark"'));
ok('doviz.css mobil 16px girdi ve 768px kırılımı var', str_contains($css, '16px') && str_contains($css, '768px'));
ok('sw.js doviz_kaydet ve api_doviz_kur önbelleğe alınmaz', str_contains($sw, 'doviz_kaydet') && str_contains($sw, 'api_doviz_kur'));

echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
