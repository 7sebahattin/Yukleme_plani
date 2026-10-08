<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_gun_nav_smoke.php — v319 Mesai Detayı "Bir gün geri / ileri".
// Aynı çavuş + aynı depo; başka çavuş / başka depo sayılmaz; aradaki boş günler atlanır
// (etikette kaç gün önce/sonra olduğu yazar). Render: pdks_puantaj_dialog_render.php.
//   php scripts/pdks_gun_nav_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function okgn(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }

$cmd = 'PUANTAJ_FAZ8B=1 PUANTAJ_GUN_NAV=1 php ' . escapeshellarg($root . '/scripts/pdks_puantaj_dialog_render.php') . ' 2>&1 >/tmp/pdks_gun_nav_out.html';
$err = (string)shell_exec($cmd);
$html = (string)@file_get_contents('/tmp/pdks_gun_nav_out.html');
@unlink('/tmp/pdks_gun_nav_out.html');
preg_match('/GUN_NAV onceki=(\d+) sonraki=(\d+)/', $err, $m);
okgn('A) render kuruldu (id\'ler döndü)', count($m) === 3, substr($err, 0, 200));
[$_, $onceki, $sonraki] = $m + [0, 0, 0];
okgn('B) nav çubuğu basıldı', str_contains($html, 'class="pdks-gun-nav"'));
okgn('C) geri bağlantısı = aynı çavuş + aynı depo dünkü mesai', str_contains($html, 'href="gunluk_isci_puantaj_detay.php?id=' . $onceki . '" rel="prev"'));
okgn('D) ileri bağlantısı = +3 gün sonraki mesai (başka çavuşun +1 günü ATLANIR)', str_contains($html, 'href="gunluk_isci_puantaj_detay.php?id=' . $sonraki . '" rel="next"'));
okgn('E) etiket: geri "Bir gün geri · gg.aa"', (bool)preg_match('/◀ Bir gün geri · \d\d\.\d\d/u', $html));
okgn('F) etiket: ileri 3 gün sonra yazılır', str_contains($html, '(3 gün sonra)'));
okgn('G) dünkü mesai için "gün önce" notu YOK (tam 1 gün)', !str_contains($html, 'gün önce)'));
$src = (string)file_get_contents($root . '/gunluk_isci_puantaj_detay.php');
okgn('H) sorgu aynı çavuş + aynı depo ile sınırlı', str_contains($src, 'foreman_id = ? AND depo = ? AND work_date $op ?'));
okgn('I) mesai yoksa pasif düğme (aria-disabled)', str_contains($src, 'aria-disabled="true"'));
echo "\nSONUÇ: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
