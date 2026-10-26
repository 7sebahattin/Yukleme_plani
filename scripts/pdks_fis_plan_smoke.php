<?php
// =========================================================
// scripts/pdks_fis_plan_smoke.php — "Fiş Excel'i" SAF (DB'siz) katman testleri (v446).
//
// config/pdks_fis.php: sütun tanımı, sayı/tarih/saat/fiş no ayrıştırıcıları, metin temizliği,
// istek_id, FM dağıtımı (V1–V5), çavuş eşleştirme, plan, zamanlama.
//   php scripts/pdks_fis_plan_smoke.php   → çıkış kodu 0 = tüm testler geçti
// =========================================================
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ROOT = dirname(__DIR__);

function db(): PDO { throw new RuntimeException('Bu test DB kullanmaz.'); }
function active_depot(): ?string { return 'Depo A'; }
function is_admin(): bool { return true; }
function can(string $p): bool { return true; }
function audit_log_event(string $a, string $m, ?int $id = null, ?array $o = null, ?array $n = null, ?int $u = null): void {}

require_once $ROOT . '/config/pdks_fis.php';

$gecen = 0; $fail = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $gecen, $fail;
    $c ? $gecen++ : $fail++;
    printf("%-92s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function j($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); }

echo "\n=== 1. pdks_fis_say ===\n";
$say = function ($x, int $maks = 250) { $h = null; $v = pdks_fis_say($x, $maks, $h); return [$v, $h]; };
foreach ([['"12"', '12', 12], ['" 12 "', ' 12 ', 12], ['"12,0"', '12,0', 12], ['"12.0"', '12.0', 12], ['int 12', 12, 12], ['float 12.0', 12.0, 12], ['"3 kişi"', '3 kişi', 3], ['"5 kisi"', '5 kisi', 5], ['"2 saat"', '2 saat', 2], ['"0"', '0', 0], ['int 0', 0, 0]] as [$ad, $girdi, $beklenen]) {
    [$v, $h] = $say($girdi);
    ok("1) say $ad → $beklenen", $v === $beklenen && $h === null, j([$v, $h]));
}
foreach ([['""', ''], ['"-"', '-'], ['"—"', '—'], ['"yok"', 'yok'], ['null', null], ['"  "', '  ']] as [$ad, $girdi]) {
    [$v, $h] = $say($girdi);
    ok("1) say $ad → boş (null, hata yok)", $v === null && $h === null, j([$v, $h]));
}
foreach ([['"12,5"', '12,5', 'tam sayı'], ['"-3"', '-3', 'negatif'], ['"abc"', 'abc', 'sayı'], ['"1e3"', '1e3', 'sayı'], ['"251"', '251', '250'], ['float 2.5', 2.5, 'tam sayı'], ['true', true, 'sayı']] as [$ad, $girdi, $parca]) {
    [$v, $h] = $say($girdi);
    ok("1) say $ad → hata ($parca)", $v === null && is_string($h) && $h !== '', j([$v, $h]));
}
foreach (['12 + 1', '12+1', '3 kişi : 1 yarım', '2 kişi : 2 yarım'] as $girdi) {
    [$v, $h] = $say($girdi);
    ok("1) say \"$girdi\" → 'ayrı sütunlara' hatası", $v === null && is_string($h) && str_contains($h, 'ayrı sütunlara'), j([$v, $h]));
}

echo "\n=== 2. pdks_fis_tarih ===\n";
$tarih = function ($x) { $h = null; $v = pdks_fis_tarih($x, $h); return [$v, $h]; };
require_once $ROOT . '/config/xlsx_export.php';
xlsx_motor_yukle();
$seri = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable('2026-10-12'));
foreach ([['12.10.2026', '12.10.2026'], ['12/10/2026', '12/10/2026'], ['2026-10-12', '2026-10-12'], ['12.10.26', '12.10.26'], ['12-10-2026', '12-10-2026'],
          ['Excel seri (float)', $seri], ['Excel seri (int)', (int)$seri], ['Excel seri (string)', (string)(int)$seri], ['saatli metin', '12.10.2026 00:00:00'], ['tek haneli', '2.3.2026']] as [$ad, $girdi]) {
    [$v, $h] = $tarih($girdi);
    $beklenen = $ad === 'tek haneli' ? '2026-03-02' : '2026-10-12';
    ok("2) tarih $ad → $beklenen", $v === $beklenen && $h === null, j([$v, $h]));
}
foreach (['31.02.2026', 'bugün', '2019-01-01', '2101-01-01', '12.13.2026', '99999999', 'abc'] as $girdi) {
    [$v, $h] = $tarih($girdi);
    ok("2) tarih \"$girdi\" → hata", $v === null && is_string($h) && $h !== '', j([$v, $h]));
}
[$v, $h] = $tarih('');
ok('2) tarih "" → boş (null, hata yok)', $v === null && $h === null);

echo "\n=== 3. pdks_fis_saat ===\n";
$saat = function ($x) { $h = null; $v = pdks_fis_saat($x, $h); return [$v, $h]; };
foreach ([['08:00', '08:00'], ['8:00', '08:00'], ['8', '08:00'], ['08.30', '08:30'], ['0800', '08:00'], [0.3333333, '08:00'], [0.375, '09:00'], ['08:00:00', '08:00'], [8, '08:00'], ['23:59', '23:59'], ['0,3333333', '08:00']] as [$girdi, $beklenen]) {
    [$v, $h] = $saat($girdi);
    ok('3) saat ' . var_export($girdi, true) . " → $beklenen", $v === $beklenen && $h === null, j([$v, $h]));
}
foreach (['25:00', '8,5', 'abc', '08:60', '2400', 24] as $girdi) {
    [$v, $h] = $saat($girdi);
    ok('3) saat ' . var_export($girdi, true) . ' → hata', $v === null && is_string($h) && $h !== '', j([$v, $h]));
}
[$v, $h] = $saat('');
ok('3) saat "" → boş (null, hata yok)', $v === null && $h === null);

echo "\n=== 4. pdks_fis_fisno_norm ===\n";
foreach ([['01410', '01410'], [' 01 410 ', '01410'], ['a/12-3', 'A/12-3'], ['x.y_1', 'X.Y_1']] as [$g, $b]) ok("4) fisno '$g' → '$b'", pdks_fis_fisno_norm($g) === $b, (string)pdks_fis_fisno_norm($g));
foreach (['', '   ', '12;DROP', str_repeat('A', 31), 'Çö1'] as $g) ok('4) fisno ' . var_export($g, true) . ' → null', pdks_fis_fisno_norm($g) === null);
ok('4) fisno 30 karakter kabul', pdks_fis_fisno_norm(str_repeat('A', 30)) === str_repeat('A', 30));

echo "\n=== 5. pdks_fis_metin ===\n";
foreach (['=HYPERLINK("http://x")', '+1', '-2', '@x', '=1+1'] as $g) {
    $m = pdks_fis_metin($g);
    ok("5) metin '$g' → ' önekli", str_starts_with($m, "'") && substr($m, 1) === $g, $m);
}
ok('5) metin denetim karakterleri atılır', pdks_fis_metin("a\x00b\x07c\x1Fd\x7Fe") === 'abcde');
ok('5) metin satır sonu/sekme korunur', pdks_fis_metin("a\nb\tc") === "a\nb\tc");
ok('5) metin kırpılır (maks)', mb_strlen(pdks_fis_metin(str_repeat('ş', 700), 500)) === 500);
ok('5) metin formül kırpmadan sonra da ≤ maks', mb_strlen(pdks_fis_metin('=' . str_repeat('x', 700), 500)) <= 500);
ok('5) metin dizi girdi → ""', pdks_fis_metin(['a']) === '' && pdks_fis_metin(null) === '');
ok('5) metin trim', pdks_fis_metin("  merhaba \n") === 'merhaba');
ok('5) metin sayı → metin', pdks_fis_metin(12) === '12');
ok('5) metin geçersiz UTF-8 → ""', pdks_fis_metin("\xC3\x28") === '');

echo "\n=== 6. pdks_fis_yarim_dk ===\n";
foreach ([[540, 270], [480, 240], [600, 300], [90, 30], [60, 30], [570, 270]] as [$t, $y]) ok("6) yarım($t) = $y", pdks_fis_yarim_dk($t) === $y, (string)pdks_fis_yarim_dk($t));

echo "\n=== 7. pdks_fis_istek_idleri ===\n";
$a = pdks_fis_istek_idleri('Biçer', '01410', 0);
ok('7) 32 küçük hex (fiş + servis)', (bool)preg_match('/^[a-f0-9]{32}$/D', $a['fis']) && (bool)preg_match('/^[a-f0-9]{32}$/D', $a['servis']), j($a));
ok('7) deterministik', pdks_fis_istek_idleri('Biçer', '01410', 0) === $a);
ok('7) servis ≠ fiş', $a['fis'] !== $a['servis']);
ok('7) depo katlı ("Biçer" ≡ "BİÇER" ≡ " biçer ")', pdks_fis_istek_idleri('BİÇER', '01410', 0) === $a && pdks_fis_istek_idleri(' biçer ', '01410', 0) === $a);
ok('7) fiş no boşluk/büyük-küçük duyarsız', pdks_fis_istek_idleri('Biçer', ' 01410', 0) === $a && pdks_fis_istek_idleri('Biçer', 'a1', 0) === pdks_fis_istek_idleri('Biçer', 'A1', 0));
ok('7) revizyon değişince değişir', pdks_fis_istek_idleri('Biçer', '01410', 1)['fis'] !== $a['fis'] && pdks_fis_istek_idleri('Biçer', '01410', 1)['servis'] !== $a['servis']);
ok('7) depo değişince değişir', pdks_fis_istek_idleri('Başka', '01410', 0)['fis'] !== $a['fis']);
ok('7) baştaki sıfır ayırt edilir (01410 ≠ 1410)', pdks_fis_istek_idleri('Biçer', '1410', 0)['fis'] !== $a['fis']);
ok('7) pdks_faz8j_istek_id() kabul eder', pdks_faz8j_istek_id($a['fis'])['ok'] === true && pdks_faz8j_istek_id($a['servis'])['istek_id'] === $a['servis']);
$kaynak = file_get_contents($ROOT . '/config/pdks_fis.php');
preg_match('/function pdks_fis_istek_idleri.*?\n\}/s', $kaynak, $mi);
ok('7) istek_id girdisinde tarih/çavuş YOK (imza yalnız depo, fiş no, rev)', (bool)preg_match('/function pdks_fis_istek_idleri\(string \$depo, string \$fisNo, int \$rev\)/', $mi[0] ?? ''));

echo "\n=== 8. pdks_fis_fm_toplam_dagit ===\n";
$d = pdks_fis_fm_toplam_dagit(25, null, 3);
ok('8) (25,null,3) → K=9 {3×7, 2×2}', $d['ok'] && $d['k'] === 9 && $d['saatler'] === [3, 3, 3, 3, 3, 3, 3, 2, 2], j($d));
$d = pdks_fis_fm_toplam_dagit(7, 3, 3);
ok('8) (7,3) → [3,2,2]', $d['ok'] && $d['saatler'] === [3, 2, 2], j($d));
$d = pdks_fis_fm_toplam_dagit(5, 5, 3);
ok('8) (5,5) → 5×1 sa', $d['ok'] && $d['saatler'] === [1, 1, 1, 1, 1], j($d));
$d = pdks_fis_fm_toplam_dagit(2, 5, 3);
ok('8) (2,5) → hata K>H', !$d['ok'] && str_contains($d['hata'], 'en az 1'), j($d));
foreach ([0, -1] as $hh) { $d = pdks_fis_fm_toplam_dagit($hh, null, 3); ok("8) ($hh) → hata", !$d['ok'], j($d)); }
$d = pdks_fis_fm_toplam_dagit(25, null, 3, 2);
ok('8) V5: çift sınırı kmax=2 → K=13 {2×12, 1×1}', $d['ok'] && $d['k'] === 13 && $d['saatler'] === array_merge(array_fill(0, 12, 2), [1]), j($d));
$d = pdks_fis_fm_toplam_dagit(40, 2, 3, 15);
ok('8) H > K·cap → hata (2×15 < 40)', !$d['ok'], j($d));
$d = pdks_fis_fm_toplam_dagit(25, null, 3, 15, 5);
ok('8) K türetilen havuzu aşarsa hata (K=9 > P=5)', !$d['ok'] && str_contains($d['hata'], 'Tam kişi sayısını'), j($d));
$d = pdks_fis_fm_toplam_dagit(10, 6, 3, 15, 5);
ok('8) verilen K havuzu aşarsa hata', !$d['ok'], j($d));
$d = pdks_fis_fm_toplam_dagit(25, null, 3, 0);
ok('8) kmax 0 → hata', !$d['ok'], j($d));
$sd = array_map(fn() => pdks_fis_fm_toplam_dagit(13, null, 3), range(1, 50));
ok('8) deterministik (50 çağrı aynı)', count(array_unique(array_map('json_encode', $sd))) === 1);
ok('8) Σ saat = H korunur (H=1..60 tarama)', (function () { for ($h = 1; $h <= 60; $h++) { $d = pdks_fis_fm_toplam_dagit($h, null, 3); if (!$d['ok'] || array_sum($d['saatler']) !== $h || max($d['saatler']) > 3) return false; } return true; })());

echo "\n=== 9. pdks_fis_fm_tip_dagit (V1–V4) ===\n";
$r = pdks_fis_fm_tip_dagit([3, 3, 3, 3, 3, 3, 3, 2, 2], ['KADIN' => 8, 'ERKEK' => 12, 'RAMPACI' => 2]);
ok('9) V1 kota K3/E5/R1', $r['kota'] === ['KADIN' => 3, 'ERKEK' => 5, 'RAMPACI' => 1], j($r['kota']));
ok('9) V1 Kadın {3:2, 2:1} · Erkek {3:4, 2:1} · Rampacı {3:1}', $r['dagilim'] === ['KADIN' => [3 => 2, 2 => 1], 'ERKEK' => [3 => 4, 2 => 1], 'RAMPACI' => [3 => 1]], j($r['dagilim']));
ok('9) V1 saat toplamları 8/14/3', $r['saat'] === ['KADIN' => 8, 'ERKEK' => 14, 'RAMPACI' => 3], j($r['saat']));
$r = pdks_fis_fm_tip_dagit([3, 3, 3, 3, 3, 3, 3, 2, 2], ['ERKEK' => 12]);
ok('9) V2 yalnız Erkek {3:7, 2:2}', $r['dagilim'] === ['ERKEK' => [3 => 7, 2 => 2]], j($r['dagilim']));
$r = pdks_fis_fm_tip_dagit([3, 2, 2], ['ERKEK' => 10, 'RAMPACI' => 3]);
ok('9) V3 Erkek {3:1, 2:1} · Rampacı {2:1}', $r['dagilim'] === ['ERKEK' => [3 => 1, 2 => 1], 'RAMPACI' => [2 => 1]], j($r['dagilim']));
$r = pdks_fis_fm_tip_dagit([2, 2, 2, 2, 2], ['KADIN' => 6, 'ERKEK' => 4]);
ok('9) V4 Kadın {2:3} · Erkek {2:2}', $r['dagilim'] === ['KADIN' => [2 => 3], 'ERKEK' => [2 => 2]], j($r['dagilim']));
$r = pdks_fis_fm_tip_dagit([1], ['KADIN' => 1, 'ERKEK' => 1, 'RAMPACI' => 1]);
ok('9) eşit kalanda sabit tip sırası (Kadın önce)', $r['kota'] === ['KADIN' => 1, 'ERKEK' => 0, 'RAMPACI' => 0], j($r['kota']));
$toplamlar = true; $kotaAsimi = false;
foreach ([[[3, 2, 1, 1], ['KADIN' => 2, 'ERKEK' => 5, 'RAMPACI' => 1]], [[2, 2, 2], ['KADIN' => 3, 'ERKEK' => 3]], [[1, 1, 1, 1, 1, 1, 1], ['ERKEK' => 7]]] as [$sa, $hv]) {
    $r = pdks_fis_fm_tip_dagit($sa, $hv);
    if (array_sum($r['saat']) !== array_sum($sa)) $toplamlar = false;
    foreach ($r['kota'] as $t => $n) if ($n > $hv[$t]) $kotaAsimi = true;
}
ok('9) Σ saat korunur', $toplamlar);
ok('9) kota ≤ havuz', !$kotaAsimi);
$ilk = json_encode(pdks_fis_fm_tip_dagit([3, 3, 2, 2, 1], ['KADIN' => 4, 'ERKEK' => 5, 'RAMPACI' => 3]));
$ayni = true; for ($i = 0; $i < 100; $i++) if (json_encode(pdks_fis_fm_tip_dagit([3, 3, 2, 2, 1], ['KADIN' => 4, 'ERKEK' => 5, 'RAMPACI' => 3])) !== $ilk) $ayni = false;
ok('9) aynı girdi 100 kez AYNI sonuç', $ayni);
$r = pdks_fis_fm_tip_dagit([1, 3, 2], ['ERKEK' => 3]);
ok('9) girdi sırasından bağımsız (azalan sıralanır)', $r['dagilim'] === ['ERKEK' => [3 => 1, 2 => 1, 1 => 1]], j($r['dagilim']));

echo "\n=== 15. Statik (kaynak taraması) ===\n";
$kaynak = file_get_contents($ROOT . '/config/pdks_fis.php');
ok('15) rastgelelik / hesaplamalı formül okuması YOK (random_int|mt_rand|rand(|shuffle|array_rand|getCalculatedValue)', !preg_match('/\b(random_int|mt_rand|shuffle|array_rand|getCalculatedValue)\b|[^a-z_]rand\(/', preg_replace('#//[^\n]*|/\*.*?\*/|^\s*\*[^\n]*#sm', '', $kaynak)));
ok('15) dönem/olay/servis INSERT ve dönem UPDATE\'i YOK (ikinci yazma yolu yok)', !preg_match('/INSERT\s+INTO\s+(daily_worker_card_events|daily_worker_work_periods|daily_session_services|worker_cards)|UPDATE\s+daily_worker_work_periods/i', $kaynak));
ok('15) declare(strict_types=1)', str_contains($kaynak, 'declare(strict_types=1);'));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
