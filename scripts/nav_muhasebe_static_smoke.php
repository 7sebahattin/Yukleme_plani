<?php
// =========================================================
// scripts/nav_muhasebe_static_smoke.php — Muhasebe yer tutucu sayfaları STATİK değişmezleri
// nav_muhasebe_gruplari() tek kaynak; her sayfa kapılı, yönlendiren ya da "hazırlanıyor".
// CLI; DB'ye dokunmaz.   php scripts/nav_muhasebe_static_smoke.php
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function oku(string $f): string { global $ROOT; return (string)file_get_contents($ROOT . '/' . $f); }

$helpers = oku('config/helpers.php');
ok('nav_muhasebe_gruplari() kaynakta', (bool)preg_match('/function nav_muhasebe_gruplari\(\): array \{.*?\n\}\n/s', $helpers, $m));
$gruplar = [];
if (!empty($m[0])) { eval($m[0]); $gruplar = nav_muhasebe_gruplari(); }
ok('en az 5 grup', count($gruplar) >= 5);

$altKeys = [];
if (preg_match('/function nav_alt_sayfalar\(\): array \{(.*?)\n\}\n/s', $helpers, $m2)) {
    preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*\['href'/m", $m2[1], $mm);
    $altKeys = $mm[1];
}
ok('nav_alt_sayfalar anahtarları okundu', count($altKeys) > 5);

$gorulen = [];
foreach ($gruplar as $slug => $g) {
    ok("slug tekil: $slug", !isset($gorulen["slug:$slug"]));
    $gorulen["slug:$slug"] = true;
    ok("slug nav_alt anahtarıyla çakışmaz: $slug", !in_array($slug, $altKeys, true));
    foreach ($g[2] as $dosya => $_) {
        $ad = basename($dosya, '.php');
        ok("$dosya: iki grupta değil", !isset($gorulen[$dosya]), $dosya);
        $gorulen[$dosya] = true;
        ok("$dosya: nav_alt anahtarıyla çakışmaz", !in_array($ad, $altKeys, true));
        $yol = $ROOT . '/' . $dosya;
        if (!is_file($yol)) { ok("$dosya: kökte var", false); continue; }
        $s = (string)file_get_contents($yol);
        ok("$dosya: <?php ile başlar", str_starts_with($s, '<?php'));
        ok("$dosya: require_login()", str_contains($s, 'require_login()'));
        ok("$dosya: is_admin() kapısı", (bool)preg_match('/if \(!is_admin\(\)\) \{ forbidden\(/', $s));
        if (preg_match("/header\('Location: ([^']+)'/", $s, $lm)) {
            $hedef = explode('?', $lm[1])[0];
            ok("$dosya: yönlendirme hedefi var ($hedef)", is_file($ROOT . '/' . $hedef));
            ok("$dosya: yönlendirme exit'li", (bool)preg_match('/header\([^;]+\);\s*exit;/', $s));
            ok("$dosya: yer tutucu kartı yok", !str_contains($s, 'render_header'));
        } else {
            ok("$dosya: hazırlanıyor metni", str_contains($s, 'hazırlanıyor'));
            ok("$dosya: render_header/render_footer", str_contains($s, 'render_header(') && str_contains($s, 'render_footer()'));
        }
    }
}
echo "\n$pass geçti, $fail kaldı\n";
exit($fail > 0 ? 1 : 0);
