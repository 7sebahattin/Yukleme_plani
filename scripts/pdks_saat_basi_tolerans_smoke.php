<?php
declare(strict_types=1);
// =========================================================
// scripts/pdks_saat_basi_tolerans_smoke.php — v315 saat başı toleransı (sahip kararı).
// Giriş tam saatten ≤15 dk önceyse o saate, çıkış tam saatten ≤15 dk sonraysa o saate
// çekilir; süre/Tam/FM bu ETKİN saatlerle hesaplanır. Saf fonksiyon testi (DB yok).
//   php scripts/pdks_saat_basi_tolerans_smoke.php
// =========================================================
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
$root = dirname(__DIR__);
function db(): PDO { return new PDO('sqlite::memory:'); }
require_once $root . '/config/pdks_gunluk.php';
require_once $root . '/config/pdks_hakedis.php';
require_once $root . '/config/pdks_faz8b.php';
$pass = 0; $fail = 0;
function okst(string $n, bool $v, string $d = ''): void { global $pass, $fail; if ($v) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n" . ($d !== '' ? " :: $d" : '') . "\n"; } }
function kst(string $g, string $c, int $n = 540): array {
    $cg = str_ends_with($c, '+') ? '2026-10-09 ' . rtrim($c, '+') : "2026-10-08 $c";
    return pdks_faz8b_sure_karari("2026-10-08 $g:00", "$cg:00", $n);
}
// [giriş, çıkış, normal, beklenen süre dk, sınıf, FM, etkin giriş, etkin çıkış]
$vakalar = [
    ['07:57', '17:13', 540, 540, 'tam', 0, '08:00', '17:00'],   // sahibin örneği
    ['07:57', '17:16', 540, 556, 'tam', 1, '08:00', '17:16'],   // 17:16 → 9 sa + 1 sa FM
    ['07:57', '18:11', 540, 600, 'tam', 1, '08:00', '18:00'],   // 10 sa 11 dk → 1 sa FM
    ['07:57', '20:00', 540, 720, 'tam', 3, '08:00', '20:00'],   // 12 sa → 3 sa FM
    ['07:45', '17:15', 540, 540, 'tam', 0, '08:00', '17:00'],   // tam 15 dk sınırları yuvarlanır
    ['07:44', '17:16', 540, 572, 'tam', 1, '07:44', '17:16'],   // 16 dk → yuvarlanmaz
    ['08:00', '17:00', 540, 540, 'tam', 0, '08:00', '17:00'],
    ['08:05', '17:30', 540, 565, 'tam', 1, '08:05', '17:30'],   // geç giriş YUVARLANMAZ
    ['08:00', '16:50', 540, 530, null, 0, '08:00', '16:50'], // erken çıkış YUVARLANMAZ
    ['08:57', '18:14', 540, 540, 'tam', 0, '09:00', '18:00'],   // kaymış vardiya
    ['23:50', '09:05+', 540, 540, 'tam', 0, '00:00', '09:00'],  // gün ötesi
    ['08:50', '17:10', 480, 480, 'tam', 0, '09:00', '17:00'],   // 8 saatlik çavuş
    // v316: çıkış yuvarlaması tam günü kısaltmaz
    ['08:06', '17:07', 540, 540, 'tam', 0, '08:06', '17:06'],   // sahibin ekranı: ham 9 sa 01 dk → Tam
    ['08:05', '17:10', 540, 540, 'tam', 0, '08:05', '17:05'],
    ['08:14', '17:15', 540, 540, 'tam', 0, '08:14', '17:14'],
    ['08:20', '17:10', 540, 530, null, 0, '08:20', '17:10'],   // gerçekten kısa → karar
    ['08:06', '17:21', 540, 555, 'tam', 0, '08:06', '17:21'],   // 15+ dk geçe çıkış yuvarlanmaz, FM toleransta
];
foreach ($vakalar as [$g, $c, $n, $dk, $sinif, $fm, $ge, $ce]) {
    $k = kst($g, $c, $n);
    $ad = "$g-$c @{$n}";
    okst("$ad süre=$dk", $k['toplam_dk'] === $dk, (string)$k['toplam_dk']);
    okst("$ad sınıf=" . ($sinif ?? 'karar'), $k['otomatik_sinif'] === $sinif);
    okst("$ad FM=$fm", $k['fazla_mesai_saat'] === $fm, (string)$k['fazla_mesai_saat']);
    okst("$ad etkin {$ge}-{$ce}", substr((string)$k['giris_etkin'], 11, 5) === $ge && substr((string)$k['cikis_etkin'], 11, 5) === $ce);
}
$k = kst('07:57', '17:13');
okst('ham_dk ayrıca döner (556)', $k['ham_dk'] === 556);
okst('süre metni ham notlu', pdks_faz8b_sure_metni($k) === '9s 00dk (ham 9s 16dk)', pdks_faz8b_sure_metni($k));
okst('süre metni ham=etkin ise notsuz', pdks_faz8b_sure_metni(kst('08:00', '17:00')) === '9s 00dk');
okst('çok kısa dönem: çıkış kırpılmaz, 10 dk (negatif yok)', kst('07:50', '08:10')['toplam_dk'] === 10);
okst('çıkışsız → null', pdks_faz8b_sure_karari('2026-10-08 07:57:00', null)['toplam_dk'] === null);
$f = pdks_faz8b_donem_siniflandir(['entry_time' => '2026-10-08 07:57:00', 'exit_time' => '2026-10-08 17:13:00', 'normal_work_minutes_snapshot' => 540]);
okst('sınıflandırıcı da etkin süreyi kullanır (Tam, FM 0)', $f['etkin_sinif'] === 'tam' && $f['fazla_mesai_saat'] === 0 && $f['toplam_dk'] === 540);
echo "\nSONUÇ: $pass PASS, $fail FAIL\n";
exit($fail ? 1 : 0);
