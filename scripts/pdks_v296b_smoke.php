<?php
// v296-B statik: Çavuş Toplu Döküm gün grubu sınıfları (liste + yazdır) ve CSS bloğu.
//   php scripts/pdks_v296b_smoke.php   (tarayıcı kısmı: node scripts/pdks_v296b_smoke.js)
declare(strict_types=1);
$R = dirname(__DIR__); $fail = 0;
function ok296(string $ad, bool $k): void { global $fail; if (!$k) $fail++; echo ($k ? 'OK  - ' : 'HATA- ') . $ad . "\n"; }
$liste = file_get_contents($R . '/cavus_toplu_dokum.php');
$yaz   = file_get_contents($R . '/cavus_toplu_dokum_yazdir.php');
$css   = file_get_contents($R . '/assets/pdks.css');
foreach (['liste' => $liste, 'yazdır' => $yaz] as $ad => $src) {
    ok296("$ad: tarih değişince ton dönüşümlü", str_contains($src, '$gNo = 1 - $gNo') && str_contains($src, "\$gSon !== \$r['tarih']"));
    ok296("$ad: grup sınıfı ctd-g/ctd-gyeni satıra yazılıyor", str_contains($src, 'ctd-g') && str_contains($src, 'ctd-gyeni'));
}
ok296('liste: satır tıklama sınıfı korunuyor', str_contains($liste, 'pdks-satir-link'));
ok296('CSS: v296-B bloğu tek parça', substr_count($css, '/* v296-B:') === 1);
ok296('CSS: iki ton + hover + koyu tema + yazdırma', str_contains($css, '#ctdTable tbody tr.ctd-g1') && str_contains($css, 'pdks-satir-link:hover') && str_contains($css, 'html[data-theme="dark"] #ctdTable') && str_contains($css, 'print-color-adjust: exact'));
// grup mantığı (sayfadaki döngünün aynısı): ardışık aynı tarih aynı ton
$gSon = null; $gNo = 1; $out = [];
foreach (['01', '01', '02', '02', '02', '03'] as $t) { if ($gSon !== $t) { $gNo = 1 - $gNo; $gSon = $t; } $out[] = $gNo; }
ok296('grup mantığı: 0,0,1,1,1,0', $out === [0, 0, 1, 1, 1, 0]);
echo $fail ? "$fail HATA\n" : "Tüm kontroller geçti.\n";
exit($fail ? 1 : 0);
