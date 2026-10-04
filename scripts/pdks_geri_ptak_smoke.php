<?php
// =========================================================
// scripts/pdks_geri_ptak_smoke.php — v301 STATİK denetim.
// 1) "← Personel Takibi" geri düğmesi HER sayfada `btn-geri-ptak` (turuncu) sınıfı taşır;
//    başka hedefe giden geri düğmeleri mavi kalır (sınıf almaz).
// 2) style.css turuncu kuralı (açık + koyu tema) ve pdks.css masaüstü tarih genişliği kuralı var.
// =========================================================
declare(strict_types=1);
$KOK = dirname(__DIR__);
$pass = 0; $fail = 0;
function okp(string $ad, bool $v, string $d = ''): void {
    global $pass, $fail;
    if ($v) { $pass++; echo "PASS $ad\n"; } else { $fail++; echo "FAIL $ad" . ($d !== '' ? " :: $d" : '') . "\n"; }
}

echo "=== 1. Sayfalar ===\n";
$sayfalar = [];
foreach (glob($KOK . '/*.php') ?: [] as $f) {
    $src = (string)file_get_contents($f);
    if (strpos($src, '← Personel Takibi') !== false) $sayfalar[basename($f)] = $src;
}
okp('en az 10 sayfada "← Personel Takibi" düğmesi var', count($sayfalar) >= 10, (string)count($sayfalar));
foreach ($sayfalar as $ad => $src) {
    preg_match_all('#<a\b[^>]*>\s*← Personel Takibi\s*</a>#u', $src, $m);
    $hepsi = $m[0] !== [] && count(array_filter($m[0], fn($a) => str_contains($a, 'btn-geri-ptak') && str_contains($a, 'href="personel_takip.php"'))) === count($m[0]);
    okp("$ad: her \"← Personel Takibi\" bağlantısı btn-geri-ptak taşır", $hepsi, implode(' | ', $m[0]));
}
// Başka hedefe giden geri düğmesi turuncu OLMAMALI
$yanlis = [];
foreach (glob($KOK . '/*.php') ?: [] as $f) {
    preg_match_all('#<a\b[^>]*btn-geri-ptak[^>]*>#', (string)file_get_contents($f), $mm);
    foreach ($mm[0] as $a) if (!str_contains($a, 'href="personel_takip.php"')) $yanlis[] = basename($f) . ': ' . $a;
}
okp('btn-geri-ptak yalnız personel_takip.php bağlantısında', $yanlis === [], implode(' | ', $yanlis));

echo "\n=== 2. CSS ===\n";
$st = (string)file_get_contents($KOK . '/assets/style.css');
$pc = (string)file_get_contents($KOK . '/assets/pdks.css');
okp('style.css: .btn-geri.btn-geri-ptak turuncu (#c2410c)', (bool)preg_match('/\.btn-geri\.btn-geri-ptak\s*\{[^}]*#c2410c/s', $st));
okp('style.css: koyu temada turuncu geri düğmesi', (bool)preg_match('/html\[data-theme="dark"\]\s+\.btn-geri\.btn-geri-ptak\s*\{/', $st));
okp('style.css: genel .btn-geri hâlâ MAVİ (değişmedi)', (bool)preg_match('/\.btn-geri\s*\{[^}]*#2878f2/s', $st));
okp('pdks.css: masaüstünde filtre çubuğu tarih kutusu sabit genişlik', (bool)preg_match('/@media \(min-width: 768px\)\s*\{\s*\.pdks-filter-bar input\[type="date"\]\s*\{[^}]*max-width:\s*200px/s', $pc));

echo "\nSONUÇ: {$pass} geçti, {$fail} hata\n";
exit($fail === 0 ? 0 : 1);
