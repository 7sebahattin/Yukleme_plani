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
$a = pdks_fis_istek_idleri('İzmir', '01410', 0);
ok('7) 32 küçük hex (fiş + servis)', (bool)preg_match('/^[a-f0-9]{32}$/D', $a['fis']) && (bool)preg_match('/^[a-f0-9]{32}$/D', $a['servis']), j($a));
ok('7) deterministik', pdks_fis_istek_idleri('İzmir', '01410', 0) === $a);
ok('7) servis ≠ fiş', $a['fis'] !== $a['servis']);
ok('7) depo katlı ("İzmir" ≡ "İZMİR" ≡ " izmir ")', pdks_fis_istek_idleri('İZMİR', '01410', 0) === $a && pdks_fis_istek_idleri(' izmir ', '01410', 0) === $a);
ok('7) fiş no boşluk/büyük-küçük duyarsız', pdks_fis_istek_idleri('İzmir', ' 01410', 0) === $a && pdks_fis_istek_idleri('İzmir', 'a1', 0) === pdks_fis_istek_idleri('İzmir', 'A1', 0));
ok('7) revizyon değişince değişir', pdks_fis_istek_idleri('İzmir', '01410', 1)['fis'] !== $a['fis'] && pdks_fis_istek_idleri('İzmir', '01410', 1)['servis'] !== $a['servis']);
ok('7) depo değişince değişir', pdks_fis_istek_idleri('Başka', '01410', 0)['fis'] !== $a['fis']);
ok('7) baştaki sıfır ayırt edilir (01410 ≠ 1410)', pdks_fis_istek_idleri('İzmir', '1410', 0)['fis'] !== $a['fis']);
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

echo "\n=== 10. (çavuş artık yükleme ekranında seçilir — çözümleme yok) ===\n";
$cvs = [
    ['id' => 1, 'code' => 'C005', 'name' => 'Test - ÇAVUŞ', 'is_active' => 1],
    ['id' => 2, 'code' => 'C006', 'name' => 'Ali Veli', 'is_active' => 1],
    ['id' => 3, 'code' => 'C007', 'name' => 'Ayşe Çelik', 'is_active' => 0],
    ['id' => 4, 'code' => 'C008', 'name' => 'Depo - Ali Veli', 'is_active' => 1],
    ['id' => 5, 'code' => 'CS1', 'name' => 'Şeker Bey', 'is_active' => 1],
];

echo "\n=== 11. pdks_fis_fm_notr ===\n";
$orn = fn(bool $var, ?string $mod, ?string $birim, ?string $para = 'TRY') => ['oran' => ['var' => $var, 'fm_modu' => $mod, 'fm_birim' => $birim, 'para' => $para]];
$tp = ['KADIN' => $orn(true, 'hourly', '200'), 'ERKEK' => $orn(true, 'hourly', '200.00'), 'RAMPACI' => $orn(true, 'hourly', '200')];
$n = pdks_fis_fm_notr($tp, ['KADIN', 'ERKEK', 'RAMPACI']);
ok('11) hourly + eşit birim (200 ≡ 200.00) → true', $n['notr'] === true && $n['mod'] === 'hourly', j($n));
$tp2 = ['KADIN' => $orn(true, 'fixed', '300'), 'ERKEK' => $orn(true, 'fixed', '300')];
$n = pdks_fis_fm_notr($tp2, ['KADIN', 'ERKEK']);
ok('11) fixed + eşit birim → true (mod fixed)', $n['notr'] === true && $n['mod'] === 'fixed', j($n));
$tp3 = ['KADIN' => $orn(true, 'hourly', '200'), 'ERKEK' => $orn(true, 'hourly', '250')];
ok('11) farklı birim → false', pdks_fis_fm_notr($tp3, ['KADIN', 'ERKEK'])['notr'] === false);
$tp4 = ['KADIN' => $orn(true, 'hourly', '200'), 'ERKEK' => $orn(true, 'fixed', '200')];
ok('11) farklı mod → false', pdks_fis_fm_notr($tp4, ['KADIN', 'ERKEK'])['notr'] === false);
$tp5 = ['KADIN' => $orn(true, 'hourly', '200', 'TRY'), 'ERKEK' => $orn(true, 'hourly', '200', 'USD')];
ok('11) farklı para birimi → false', pdks_fis_fm_notr($tp5, ['KADIN', 'ERKEK'])['notr'] === false);
$tp6 = ['KADIN' => $orn(true, 'hourly', '200'), 'ERKEK' => $orn(false, null, null)];
ok('11) oran yok (bir tipte) → null (bilinmiyor)', pdks_fis_fm_notr($tp6, ['KADIN', 'ERKEK'])['notr'] === null);
ok('11) tek tip → true (oran yoksa bile)', pdks_fis_fm_notr($tp6, ['ERKEK'])['notr'] === true);
ok('11) mod hourly/fixed dışı → false', pdks_fis_fm_notr(['KADIN' => $orn(true, 'diger', '1'), 'ERKEK' => $orn(true, 'diger', '1')], ['KADIN', 'ERKEK'])['notr'] === false);

// ---- plan fixture'ları ----
function bg(array $o = []): array {
    $tip = fn(int $id, string $ad, array $ek = []) => $ek + ['id' => $id, 'ad' => $ad, 'tam_dk' => 540, 'fm_bas_dk' => 540, 'cift_dk' => null,
        'oran' => ['var' => true, 'fm_modu' => 'hourly', 'fm_birim' => '200', 'para' => 'TRY']];
    $b = ['tipler' => ['KADIN' => $tip(1, 'Kadın'), 'ERKEK' => $tip(2, 'Erkek'), 'RAMPACI' => $tip(3, 'Rampacı')], 'fm_tip_secenegi' => 'tumu'];
    foreach ($o as $k => $v) {
        if ($k === 'tipler') { foreach ($v as $kod => $ek) $b['tipler'][$kod] = array_replace_recursive($b['tipler'][$kod], $ek); }
        else $b[$k] = $v;
    }
    return $b;
}
function F(array $say, array $o = []): array {
    $s = ['KADIN' => ['tam' => 0, 'yarim' => 0], 'ERKEK' => ['tam' => 0, 'yarim' => 0], 'RAMPACI' => ['tam' => 0, 'yarim' => 0]];
    foreach ($say as $k => [$t, $y]) $s[$k] = ['tam' => $t, 'yarim' => $y];
    $kova = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];
    foreach ($o['fm_kova'] ?? [] as $sa => $n) $kova[$sa] = $n;
    unset($o['fm_kova']);
    return $o + ['satir' => 2, 'fis_no' => 'T1', 'tarih' => date('Y-m-d', strtotime('-3 day')), 'say' => $s, 'fm_kova' => $kova, 'fm_toplam' => null,
                 'fm_kisi' => null, 'fm_tipi' => null, 'servis' => ['buyuk' => 0, 'kucuk' => 0], 'giris' => '08:00', 'rev' => 0];
}
function G(array $plan): array { return array_map(fn($g) => $g['kod'] . ':' . $g['profil'] . ($g['profil'] === 'fm' ? $g['fm_saat'] : '') . '×' . $g['adet'], $plan['gruplar']); }
function hatalarda(array $plan, string $parca): bool { foreach ($plan['hatalar'] as $h) if (str_contains($h, $parca)) return true; return false; }
function uyaridaVar(array $plan, string $parca): bool { foreach ($plan['uyarilar'] as $h) if (str_contains($h, $parca)) return true; return false; }

echo "\n=== 12. pdks_fis_plan ===\n";
// Örnek A: Kadın 8; Erkek 12 tam + 1 yarım; kova FM2=5; FM Tipi Erkek
$pA = pdks_fis_plan(F(['KADIN' => [8, 0], 'ERKEK' => [12, 1]], ['fm_kova' => [2 => 5], 'fm_tipi' => ['ERKEK'], 'servis' => ['buyuk' => 0, 'kucuk' => 1]]), bg());
ok('12) Örnek A: ok', $pA['ok'] === true, j($pA['hatalar']));
ok('12) Örnek A: gruplar Kadın tam 8 · Erkek tam 7 · Erkek fm2 5 · Erkek yarım 1', G($pA) === ['KADIN:tam×8', 'ERKEK:tam×7', 'ERKEK:fm2×5', 'ERKEK:yarim×1'], j(G($pA)));
ok('12) Örnek A: kişi_toplam 21', $pA['kisi_toplam'] === 21, (string)$pA['kisi_toplam']);
ok('12) Örnek A: süreler 540 / 540 / 660 / 270', array_column($pA['gruplar'], 'sure_dk') === [540, 540, 660, 270], j(array_column($pA['gruplar'], 'sure_dk')));
ok('12) Örnek A: FM mod=kova, saatler [2×5], toplam 10 sa, 5 kişi', $pA['fm']['mod'] === 'kova' && $pA['fm']['saatler'] === [2, 2, 2, 2, 2] && $pA['fm']['toplam_saat'] === 10 && $pA['fm']['kisi'] === 5, j($pA['fm']));
ok('12) Örnek A: servis hedef 0/1', $pA['servis'] === ['buyuk' => 0, 'kucuk' => 1], j($pA['servis']));
ok('12) Örnek A: grup alanları (tip_id, tip_ad, kod, adet)', $pA['gruplar'][1]['tip_id'] === 2 && $pA['gruplar'][1]['tip_ad'] === 'Erkek' && $pA['gruplar'][1]['kod'] === 'ERKEK');
// Örnek B: Toplam FM 25, Tümü orantılı
$pB = pdks_fis_plan(F(['KADIN' => [8, 0], 'ERKEK' => [12, 0], 'RAMPACI' => [2, 1]], ['fm_toplam' => 25]), bg());
ok('12) Örnek B: ok', $pB['ok'] === true, j($pB['hatalar']));
ok('12) Örnek B: Kadın tam5 fm2×1 fm3×2 · Erkek tam7 fm2×1 fm3×4 · Rampacı tam1 fm3×1 yarım×1',
    G($pB) === ['KADIN:tam×5', 'KADIN:fm2×1', 'KADIN:fm3×2', 'ERKEK:tam×7', 'ERKEK:fm2×1', 'ERKEK:fm3×4', 'RAMPACI:tam×1', 'RAMPACI:fm3×1', 'RAMPACI:yarim×1'], j(G($pB)));
ok('12) Örnek B: kişi_toplam 23, FM toplam 25 sa, 9 kişi, mod=toplam', $pB['kisi_toplam'] === 23 && $pB['fm']['toplam_saat'] === 25 && $pB['fm']['kisi'] === 9 && $pB['fm']['mod'] === 'toplam', j($pB['fm']));
ok('12) Örnek B: tip_dagilim (V1) + nötr=true', $pB['fm']['tip_dagilim'] === ['KADIN' => [3 => 2, 2 => 1], 'ERKEK' => [3 => 4, 2 => 1], 'RAMPACI' => [3 => 1]] && $pB['fm']['notr'] === true, j($pB['fm']));
ok('12) Örnek B: açıklama metni kişi/saat dağılımını yazar', str_contains($pB['fm']['aciklama'], '25') && str_contains($pB['fm']['aciklama'], '9'), $pB['fm']['aciklama']);
// FM yok
$p0 = pdks_fis_plan(F(['KADIN' => [5, 0]]), bg());
ok('12) FM yok: mod=yok, tek grup', $p0['ok'] && $p0['fm']['mod'] === 'yok' && G($p0) === ['KADIN:tam×5'], j([$p0['hatalar'], G($p0)]));
// FM Tipi sayfa seçeneği (fiş boş)
$p = pdks_fis_plan(F(['KADIN' => [4, 0], 'ERKEK' => [6, 0]], ['fm_kova' => [1 => 2]]), bg(['fm_tip_secenegi' => 'erkek']));
ok('12) sayfa seçeneği erkek: FM yalnız Erkek', $p['ok'] && G($p) === ['KADIN:tam×4', 'ERKEK:tam×4', 'ERKEK:fm1×2'], j([$p['hatalar'], G($p)]));
$p = pdks_fis_plan(F(['KADIN' => [4, 0], 'ERKEK' => [6, 2], 'RAMPACI' => [2, 0]], ['fm_kova' => [1 => 3]]), bg(['fm_tip_secenegi' => 'erkek_rampaci']));
ok('12) sayfa seçeneği erkek_rampaci: ağırlıklı (6:2 → 2 Erkek + 1 Rampacı)', $p['ok'] && G($p) === ['KADIN:tam×4', 'ERKEK:tam×4', 'ERKEK:fm1×2', 'ERKEK:yarim×2', 'RAMPACI:tam×1', 'RAMPACI:fm1×1'], j([$p['hatalar'], G($p)]));
$p = pdks_fis_plan(F(['KADIN' => [4, 0], 'ERKEK' => [6, 0]], ['fm_kova' => [1 => 1], 'fm_tipi' => ['KADIN'], 'fm_tip_secenegi' => 'x']), bg(['fm_tip_secenegi' => 'erkek']));
ok('12) fişin FM Tipi sayfa seçeneğini EZER', $p['ok'] && G($p) === ['KADIN:tam×3', 'KADIN:fm1×1', 'ERKEK:tam×6'], j([$p['hatalar'], G($p)]));

echo "\n  -- hata / uyarı kuralları --\n";
$p = pdks_fis_plan(F(['ERKEK' => [2, 0]], ['fm_kova' => [2 => 3]]), bg());
ok('12) FM kişi > Tam kişi → hata', !$p['ok'] && hatalarda($p, 'aşıyor'), j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [10, 0]], ['fm_kova' => [2 => 5], 'fm_toplam' => 9]), bg());
ok('12) kova toplamı ≠ Toplam FM Saati → hata', !$p['ok'] && hatalarda($p, 'Toplam FM Saati'), j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [10, 0]], ['fm_kova' => [2 => 5], 'fm_toplam' => 10]), bg());
ok('12) tutarlı Toplam FM Saati yok sayılır (uyarı)', $p['ok'] && $p['fm']['mod'] === 'kova' && uyaridaVar($p, 'yok sayıld'), j([$p['hatalar'], $p['uyarilar']]));
$p = pdks_fis_plan(F(['ERKEK' => [10, 0]], ['fm_kova' => [2 => 5], 'fm_kisi' => 4]), bg());
ok('12) FM Kişi Sayısı kova toplamıyla uyuşmaz → hata', !$p['ok'] && hatalarda($p, 'FM Kişi Sayısı'), j($p['hatalar']));
$fx = ['tipler' => ['KADIN' => ['oran' => ['fm_modu' => 'fixed']], 'ERKEK' => ['oran' => ['fm_modu' => 'fixed']], 'RAMPACI' => ['oran' => ['fm_modu' => 'fixed']]]];
$p = pdks_fis_plan(F(['ERKEK' => [12, 0]], ['fm_toplam' => 7]), bg($fx));
ok('12) (b) fixed ve FM Kişi Sayısı boş → hata', !$p['ok'] && hatalarda($p, 'FM Kişi Sayısı'), j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [12, 0]], ['fm_toplam' => 7, 'fm_kisi' => 3]), bg($fx));
ok('12) (b) fixed + FM Kişi Sayısı → ok, [3,2,2]', $p['ok'] && $p['fm']['saatler'] === [3, 2, 2], j([$p['hatalar'], $p['fm']]));
$fark = ['tipler' => ['ERKEK' => ['oran' => ['fm_birim' => '250']]]];
$p = pdks_fis_plan(F(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['fm_toplam' => 6]), bg($fark));
ok('12) (b) nötr değil (birim farklı) → hata', !$p['ok'] && hatalarda($p, 'dağıtım'), j($p['hatalar']));
$p = pdks_fis_plan(F(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['fm_kova' => [2 => 2]]), bg($fark));
ok('12) (a) nötr değil + FM Tipi boş → hata', !$p['ok'] && hatalarda($p, 'FM Tipi'), j($p['hatalar']));
$p = pdks_fis_plan(F(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['fm_kova' => [2 => 2], 'fm_tipi' => ['ERKEK']]), bg($fark));
ok('12) (a) nötr değil ama FM Tipi tek tip → ok', $p['ok'], j($p['hatalar']));
$yok = ['tipler' => ['KADIN' => ['oran' => ['var' => false, 'fm_modu' => null, 'fm_birim' => null, 'para' => null]]]];
$p = pdks_fis_plan(F(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['fm_toplam' => 4]), bg($yok));
ok('12) (b) oran yok (bilinmiyor) → hata', !$p['ok'], j($p['hatalar']));
$p = pdks_fis_plan(F(['KADIN' => [6, 0], 'ERKEK' => [6, 0]], ['fm_kova' => [2 => 2]]), bg($yok));
ok('12) (a) oran yok (bilinmiyor) → UYARI (hata değil)', $p['ok'] && uyaridaVar($p, 'fiyat'), j([$p['hatalar'], $p['uyarilar']]));
$p = pdks_fis_plan(F([]), bg());
ok('12) hiç işçi yok → hata (boş fiş)', !$p['ok'] && hatalarda($p, 'Boş fiş'), j($p['hatalar']));
$p = pdks_fis_plan(F([], ['servis' => ['buyuk' => 1, 'kucuk' => 0]]), bg());
ok('12) yalnız servis → hata', !$p['ok'] && hatalarda($p, 'servis'), j($p['hatalar']));
$p = pdks_fis_plan(F(['KADIN' => [200, 0], 'ERKEK' => [51, 0]]), bg());
ok('12) 251 kişi (> 250) → hata', !$p['ok'] && hatalarda($p, '250'), j($p['hatalar']));
$p = pdks_fis_plan(F(['KADIN' => [200, 0], 'ERKEK' => [50, 0]]), bg());
ok('12) 250 kişi kabul', $p['ok'], j($p['hatalar']));
$cift = ['tipler' => ['KADIN' => ['cift_dk' => 720], 'ERKEK' => ['cift_dk' => 720], 'RAMPACI' => ['cift_dk' => 720]]];
$p = pdks_fis_plan(F(['ERKEK' => [10, 0]], ['fm_kova' => [6 => 1]]), bg($cift));
ok('12) (a) FM6 çift eşiğine girer → UYARI (engel değil)', $p['ok'] && uyaridaVar($p, 'Çift yevmiye'), j([$p['hatalar'], $p['uyarilar']]));
$p = pdks_fis_plan(F(['ERKEK' => [20, 0]], ['fm_toplam' => 25]), bg($cift));
ok('12) (b) çift sınırı: V5 → 12×2 sa + 1×1 sa (kmax=2)', $p['ok'] && $p['fm']['saatler'] === array_merge(array_fill(0, 12, 2), [1]), j([$p['hatalar'], $p['fm']['saatler'] ?? null]));
$p = pdks_fis_plan(F(['ERKEK' => [10, 0]], ['fm_toplam' => 25]), bg($cift));
ok('12) (b) çift sınırı + havuz yetmez (K=13 > P=10) → hata', !$p['ok'], j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [3, 0]], ['fm_kisi' => 2]), bg());
ok('12) FM Kişi Sayısı var ama Toplam/kova yok → hata', !$p['ok'] && hatalarda($p, 'FM Kişi Sayısı'), j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [3, 0]], ['fm_toplam' => 0]), bg());
ok('12) Toplam FM 0 → FM yok', $p['ok'] && $p['fm']['mod'] === 'yok', j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [3, 0]], ['fm_tipi' => ['KADIN'], 'fm_kova' => [1 => 1]]), bg());
ok('12) FM Tipi izinli tipte Tam kişi yok → hata', !$p['ok'], j($p['hatalar']));
$p = pdks_fis_plan(F(['ERKEK' => [3, 0]]), ['tipler' => ['KADIN' => bg()['tipler']['KADIN']], 'fm_tip_secenegi' => 'tumu']);
ok('12) bağlamda tip yok + adet>0 → hata', !$p['ok'] && hatalarda($p, 'tip'), j($p['hatalar']));

echo "\n=== 13. Determinizm / hash ===\n";
$f = F(['KADIN' => [8, 0], 'ERKEK' => [12, 0], 'RAMPACI' => [2, 1]], ['fm_toplam' => 25]);
$h1 = pdks_fis_plan($f, bg())['hash'];
ok('13) aynı fiş+bağlam → aynı hash (16 hex)', $h1 === pdks_fis_plan($f, bg())['hash'] && (bool)preg_match('/^[a-f0-9]{16}$/D', $h1), $h1);
$f2 = $f; $f2['giris'] = '09:00';
ok('13) giriş saati değişince hash değişir', pdks_fis_plan($f2, bg())['hash'] !== $h1);
$f3 = $f; $f3['fm_toplam'] = 24;
ok('13) FM saati değişince hash değişir', pdks_fis_plan($f3, bg())['hash'] !== $h1);
ok('13) fm_tip seçeneği hash\'e girer', pdks_fis_plan($f, bg(['fm_tip_secenegi' => 'erkek']))['hash'] !== $h1);
ok('13) bağlam eşiği (tam_dk) değişince hash değişir', pdks_fis_plan($f, bg(['tipler' => ['ERKEK' => ['tam_dk' => 480, 'fm_bas_dk' => 480]]]))['hash'] !== $h1);
$f4 = $f; $f4['servis'] = ['buyuk' => 1, 'kucuk' => 0];
ok('13) servis hedefi hash\'e girer', pdks_fis_plan($f4, bg())['hash'] !== $h1);
$f5 = $f; $f5['ham'] = 'başka'; $f5['kontrol'] = 'x'; $f5['not'] = 'y'; $f5['satir'] = 99;
ok('13) ham/kontrol/not/satır hash\'e GİRMEZ', pdks_fis_plan($f5, bg())['hash'] === $h1);

echo "\n=== 14. pdks_fis_zamanla ===\n";
$pz = pdks_fis_zamanla(pdks_fis_plan(F(['KADIN' => [3, 0], 'ERKEK' => [4, 1]], ['fm_kova' => [2 => 2], 'fm_tipi' => ['ERKEK']]), bg()), '2026-10-20', '08:00');
ok('14) giriş 08:00: tam 17:00 · fm2 19:00 · yarım 12:30', $pz['ok'] && array_column($pz['gruplar'], 'exit_clock') === ['17:00', '17:00', '19:00', '12:30'] && array_unique(array_column($pz['gruplar'], 'exit_date')) === ['2026-10-20'], j(array_column($pz['gruplar'], 'exit_clock')));
ok('14) entry_clock her gruba yazılır', array_unique(array_column($pz['gruplar'], 'entry_clock')) === ['08:00']);
$pz = pdks_fis_zamanla(pdks_fis_plan(F(['KADIN' => [3, 0]]), bg()), '2026-10-20', '22:00');
ok('14) giriş 22:00: tam çıkış ertesi gün 07:00', $pz['ok'] && $pz['gruplar'][0]['exit_date'] === '2026-10-21' && $pz['gruplar'][0]['exit_clock'] === '07:00', j($pz['gruplar'][0]));
$pz = pdks_fis_zamanla(pdks_fis_plan(F(['KADIN' => [3, 0]]), bg()), '2026-10-20', '08:20');
ok('14) giriş 08:20: tam çıkış 17:20', $pz['gruplar'][0]['exit_clock'] === '17:20');
$pz = pdks_fis_zamanla(pdks_fis_plan(F(['KADIN' => [3, 0]]), bg(['tipler' => ['KADIN' => ['tam_dk' => 1500, 'fm_bas_dk' => 1500]]])), '2026-10-20', '08:00');
ok('14) süre > 24 sa → plan hatası', !$pz['ok'] && hatalarda($pz, '24'), j($pz['hatalar']));
$pz = pdks_fis_zamanla(pdks_fis_plan(F(['KADIN' => [3, 0]]), bg()), '2026-10-20', '25:00');
ok('14) geçersiz giriş saati → plan hatası', !$pz['ok'], j($pz['hatalar']));
ok('14) zamanla girdi planı DEĞİŞTİRMEZ (saf)', (function () { $p = pdks_fis_plan(F(['KADIN' => [3, 0]]), bg()); $kopya = $p; pdks_fis_zamanla($p, '2026-10-20', '08:00'); return $p === $kopya; })());

echo "\n=== 11b. pdks_fis_normalize ===\n";
function H(array $kv): array { $o = []; foreach ($kv as $k => $v) $o[$k] = ['v' => $v, 't' => is_string($v) ? 's' : (is_null($v) ? 'e' : 'n')]; return $o; }
function nor(array $satirlar, array $cv = null, string $isl = 'Depo A'): array { global $cvs; return pdks_fis_normalize(['ok' => true, 'uyarilar' => [], 'satirlar' => $satirlar, 'bilinmeyen' => []], $cv ?? $cvs[0], $isl); }
$gec = date('d.m.Y', strtotime('-3 day')); $gecIso = date('Y-m-d', strtotime('-3 day'));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => '01410', 'tarih' => $gec, 'kadin_tam' => '8', 'erkek_tam' => 12.0, 'erkek_yarim' => '1', 'fm2' => '5', 'fm_tipi' => 'Erkek', 'servis_kucuk' => 1, 'giris' => '8:30', 'rev' => '1', 'not' => 'Rampa', 'ham' => 'Bayan 8 | Erkek 12 + 1'])]]);
$f = $r['fisler'][0] ?? [];
ok('11b) geçerli satır: hata yok', ($f['hatalar'] ?? ['x']) === [] && $r['genel_hatalar'] === [], j([$f['hatalar'] ?? null, $r['genel_hatalar']]));
ok('11b) alanlar: fiş no/tarih/çavuş/sayılar/FM/servis/giriş/rev', ($f['fis_no'] ?? '') === '01410' && $f['tarih'] === $gecIso && $f['cavus']['id'] === 1 && $f['say']['KADIN'] === ['tam' => 8, 'yarim' => 0] && $f['say']['ERKEK'] === ['tam' => 12, 'yarim' => 1]
    && $f['fm_kova'][2] === 5 && $f['fm_tipi'] === ['ERKEK'] && $f['servis'] === ['buyuk' => 0, 'kucuk' => 1] && $f['giris'] === '08:30' && $f['rev'] === 1 && $f['not'] === 'Rampa', j($f));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => '12 + 1'])]]);
ok('11b) "12 + 1" → hücre hatası Satır n, «Başlık»', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_starts_with($x, 'Satır 2, «Erkek Tam»:') && str_contains($x, 'ayrı sütunlara')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 3, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => date('d.m.Y'), 'erkek_tam' => 1])]]);
ok('11b) bugünün tarihi → hata', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'bugün') && str_starts_with($x, 'Satır 3, «Tarih»')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 3, 'hucreler' => H(['fis_no' => 'ORNEK-1', 'tarih' => $gec, 'erkek_tam' => 1])]]);
ok('11b) ORNEK- fiş no → hata', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'Örnek')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1])], ['satir' => 3, 'hucreler' => H(['fis_no' => 'a1', 'tarih' => $gec, 'erkek_tam' => 1])]]);
ok('11b) aynı fiş no iki satırda → İKİ satıra da hata', count(array_filter($r['fisler'], fn($f) => (bool)array_filter($f['hatalar'], fn($x) => str_contains($x, 'aynı Fiş No')))) === 2, j(array_column($r['fisler'], 'hatalar')));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1])], ['satir' => 3, 'hucreler' => H(['fis_no' => 'A2', 'tarih' => $gec, 'erkek_tam' => 1])]]);
ok('11b) aynı çavuş+gün iki fiş → uyarı + cakisma_dosya (hata değil)', $r['fisler'][0]['hatalar'] === [] && !empty($r['fisler'][0]['cakisma_dosya']) && !empty($r['fisler'][1]['cakisma_dosya']) && $r['fisler'][0]['cakisma_sira'] === 0 && $r['fisler'][1]['cakisma_sira'] === 1 && $r['fisler'][1]['uyarilar'] !== [], j(array_column($r['fisler'], 'uyarilar')));
$r = nor([]);
ok('11b) 0 fiş → genel hata', $r['genel_hatalar'] !== [], j($r));
$cok = []; for ($i = 1; $i <= 151; $i++) $cok[] = ['satir' => $i + 1, 'hucreler' => H(['fis_no' => 'N' . $i, 'tarih' => $gec, 'erkek_tam' => 1])];
$r = nor($cok);
ok('11b) 151 fiş → genel hata', $r['genel_hatalar'] !== [] && str_contains($r['genel_hatalar'][0], '150'), j($r['genel_hatalar']));
$r = nor([['satir' => 2, 'hucreler' => H(['tarih' => $gec, 'erkek_tam' => 1])]]);
ok('11b) Fiş No boş (başka alan dolu) → hata', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'Fiş No')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 2, 'hucreler' => ['fis_no' => ['v' => 1411, 't' => 'n']] + H(['tarih' => $gec, 'erkek_tam' => 1])]]);
ok('11b) sayı olarak gelen Fiş No → uyarı (baştaki sıfır)', $r['fisler'][0]['fis_no'] === '1411' && !empty($r['fisler'][0]['fis_no_ham_sayi']) && (bool)array_filter($r['fisler'][0]['uyarilar'], fn($x) => str_contains($x, 'sıfır')), j($r['fisler'][0]['uyarilar']));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1])]], $cvs[2]);
ok('11b) pasif çavuş seçimi → uyarı (hata değil)', $r['fisler'][0]['hatalar'] === [] && (bool)array_filter($r['fisler'][0]['uyarilar'], fn($x) => str_contains($x, 'pasif')) && $r['fisler'][0]['cavus']['id'] === 3, j($r['fisler'][0]));
$r = pdks_fis_normalize(['ok' => true, 'uyarilar' => [], 'satirlar' => [['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1])]], 'bilinmeyen' => []], ['id' => 0], 'Depo A');
ok('11b) çavuş seçilmemiş → genel hata, fiş yok', $r['fisler'] === [] && $r['genel_hatalar'] === ['Çavuş seçilmedi.'], j($r));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1])]], $cvs[1], 'Depo B');
ok('11b) fişler seçilen çavuş + işletmeyi taşır (dosyadan DEĞİL)', $r['fisler'][0]['cavus'] === ['id' => 2, 'kod' => 'C006', 'ad' => 'Ali Veli', 'yontem' => 'secim'] && $r['fisler'][0]['isletme'] === 'Depo B', j($r['fisler'][0]));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1, 'fm_tipi' => 'Kuş'])]]);
ok('11b) bilinmeyen FM Tipi → hata', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'FM Tipi')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1, 'servis_buyuk' => 100])]]);
ok('11b) servis > 99 → hata', (bool)array_filter($r['fisler'][0]['hatalar'], fn($x) => str_contains($x, 'Servis Büyük')), j($r['fisler'][0]['hatalar']));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec, 'erkek_tam' => 1, 'not' => '=HYPERLINK("http://x")', 'ham' => '+1'])]], null, '@x');
ok('11b) serbest metin alanları enjeksiyona karşı \' önekli', str_starts_with($r['fisler'][0]['not'], "'=") && str_starts_with($r['fisler'][0]['ham'], "'+") && str_starts_with($r['fisler'][0]['isletme'], "'@"), j([$r['fisler'][0]['not'], $r['fisler'][0]['ham']]));
$r = nor([['satir' => 2, 'hucreler' => H(['fis_no' => 'A1', 'tarih' => $gec])]]);
ok('11b) boş sayılar → say 0 (hata değil, plan karar verir)', $r['fisler'][0]['say']['ERKEK'] === ['tam' => 0, 'yarim' => 0] && $r['fisler'][0]['fm_toplam'] === null && $r['fisler'][0]['giris'] === '08:00' && $r['fisler'][0]['rev'] === 0, j($r['fisler'][0]));

echo "\n=== 15. Statik (kaynak taraması) ===\n";
$kaynak = file_get_contents($ROOT . '/config/pdks_fis.php');
ok('15) rastgelelik / hesaplamalı formül okuması YOK (random_int|mt_rand|rand(|shuffle|array_rand|getCalculatedValue)', !preg_match('/\b(random_int|mt_rand|shuffle|array_rand|getCalculatedValue)\b|[^a-z_]rand\(/', preg_replace('#//[^\n]*|/\*.*?\*/|^\s*\*[^\n]*#sm', '', $kaynak)));
ok('15) dönem/olay/servis INSERT ve dönem UPDATE\'i YOK (ikinci yazma yolu yok)', !preg_match('/INSERT\s+INTO\s+(daily_worker_card_events|daily_worker_work_periods|daily_session_services|worker_cards)|UPDATE\s+daily_worker_work_periods/i', $kaynak));
ok('15) declare(strict_types=1)', str_contains($kaynak, 'declare(strict_types=1);'));

echo "\n--- Sonuç: $gecen geçti, $fail kaldı ---\n";
exit($fail > 0 ? 1 : 0);
