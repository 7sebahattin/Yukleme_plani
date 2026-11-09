<?php
// scripts/pdks_toplu_hizli_smoke.php — Toplu İşlem kişi sayısı + FM dağıtımı (saf fonksiyon; DB yok)
//   php scripts/pdks_toplu_hizli_smoke.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/pdks_toplu_hizli.php';
$ok = 0; $fail = 0;
function t(string $ad, bool $c): void { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "FAIL: $ad\n"; } }

// Sahip örneği: kadın 17, erkek 10 (+ erkek yarım/şeritçi havuz dışı), 5 kişi → 3 kadın + 2 erkek
$d = pdks_toplu_fm_dagit(['KADIN' => 17, 'ERKEK' => 10], 5);
t('örnek ok', $d['ok'] && $d['dagilim'] === ['KADIN' => 3, 'ERKEK' => 2]);
t('örnek toplam', array_sum($d['dagilim']) === 5);

// Deterministik + toplam korunur + mevcudu aşmaz (kapsamlı tarama)
$kotu = 0;
for ($k = 0; $k <= 40; $k++) for ($e = 0; $e <= 40; $e++) for ($n = 0; $n <= $k + $e; $n += 3) {
    $a = pdks_toplu_fm_dagit(['KADIN' => $k, 'ERKEK' => $e], $n);
    $b = pdks_toplu_fm_dagit(['KADIN' => $k, 'ERKEK' => $e], $n);
    if ($a !== $b || !$a['ok'] || array_sum($a['dagilim']) !== $n || $a['dagilim']['KADIN'] > $k || $a['dagilim']['ERKEK'] > $e) $kotu++;
}
t('tarama: toplam/üst sınır/determinizm', $kotu === 0);

// Beraberlik: 1 kişi, 5 kadın + 5 erkek → ilk sıradaki (KADIN)
t('beraberlik sıra', pdks_toplu_fm_dagit(['KADIN' => 5, 'ERKEK' => 5], 1)['dagilim'] === ['KADIN' => 1, 'ERKEK' => 0]);
// Beraberlikte büyük mevcut: 1 kişi, 2 kadın + 4 erkek → kalanlar 2/6 ve 4/6 → erkek
t('büyük kalan', pdks_toplu_fm_dagit(['KADIN' => 2, 'ERKEK' => 4], 1)['dagilim'] === ['KADIN' => 0, 'ERKEK' => 1]);
// Aşım ve negatif
t('aşım', !pdks_toplu_fm_dagit(['KADIN' => 2, 'ERKEK' => 1], 4)['ok']);
t('negatif', !pdks_toplu_fm_dagit(['KADIN' => 2], -1)['ok']);
t('sıfır', pdks_toplu_fm_dagit(['KADIN' => 2], 0)['dagilim'] === ['KADIN' => 0]);
t('tek kategori', pdks_toplu_fm_dagit(['ERKEK' => 4], 3)['dagilim'] === ['ERKEK' => 3]);

// Saat ayrıştırma
t('dk 3', pdks_toplu_fm_dakika('3') === 180);
t('dk 1,5', pdks_toplu_fm_dakika('1,5') === 90);
t('dk 0 red', pdks_toplu_fm_dakika('0') === null);
t('dk 13 red', pdks_toplu_fm_dakika('13') === null);
t('dk 1.1 red', pdks_toplu_fm_dakika('1.1') === null);
t('dk metin red', pdks_toplu_fm_dakika('abc') === null);

// Grup üretimi — sahip örneği: kadın tam 17, erkek tam 10, şeritçi 1; 5 kişi 3 sa FM
$tipKod = [1 => 'KADIN', 2 => 'ERKEK', 4 => 'SERITCI'];
$g = pdks_toplu_hizli_gruplar(['tam' => [1 => 17, 2 => 10, 4 => 1], 'fm' => ['kisi' => 5, 'saat' => '3']], $tipKod, '2026-11-09');
t('grup hata yok', $g['hata'] === null);
$ozet = [];
foreach ($g['gruplar'] as $x) $ozet[] = $x['worker_type_id'] . ':' . $x['kartsiz_adet'] . ':' . $x['entry_clock'] . '-' . $x['exit_date'] . ' ' . $x['exit_clock'];
t('gruplar', $ozet === ['1:14:08:00-2026-11-09 17:00', '1:3:08:00-2026-11-09 20:00', '2:8:08:00-2026-11-09 17:00', '2:2:08:00-2026-11-09 20:00', '4:1:08:00-2026-11-09 17:00'] , json_encode($ozet) ?: '');
if ($ozet !== ['1:14:08:00-2026-11-09 17:00', '1:3:08:00-2026-11-09 20:00', '2:8:08:00-2026-11-09 17:00', '2:2:08:00-2026-11-09 20:00', '4:1:08:00-2026-11-09 17:00']) echo json_encode($ozet), "\n";
// Toplam kişi korunur
$top = array_sum(array_column($g['gruplar'], 'kartsiz_adet'));
t('toplam kişi 28', $top === 28);
// Gece yarısını aşan FM → ertesi gün
$g2 = pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 1, 'saat' => '8']], $tipKod, '2026-11-09');
t('gece yarısı', $g2['gruplar'][1]['exit_date'] === '2026-11-10' && $g2['gruplar'][1]['exit_clock'] === '01:00');
// Hatalar
t('FM saat yok', pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 1, 'saat' => '']], $tipKod, '2026-11-09')['hata'] !== null);
t('FM aşım', pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 3, 'saat' => '2']], $tipKod, '2026-11-09')['hata'] !== null);
t('yalnız şeritçi FM', pdks_toplu_hizli_gruplar(['tam' => [4 => 3], 'fm' => ['kisi' => 1, 'saat' => '2']], $tipKod, '2026-11-09')['hata'] !== null);
t('bilinmeyen tip yok sayılır', pdks_toplu_hizli_gruplar(['tam' => [99 => 5]], $tipKod, '2026-11-09')['gruplar'] === []);
t('FM yok → tek grup', count(pdks_toplu_hizli_gruplar(['tam' => [1 => 4]], $tipKod, '2026-11-09')['gruplar']) === 1);

// JS aynası + sunucu uçları (statik)
$js = (string)file_get_contents($ROOT . '/_puantaj_toplu.php');
t('JS fmDagit aynası', str_contains($js, 'function fmDagit') && str_contains($js, 'kalan[b] - kalan[a]') && str_contains($js, 'mevcut[b] - mevcut[a]'));
$aj = (string)file_get_contents($ROOT . '/_puantaj_toplu_ajax.php');
t('ajax: csrf + grup sunucuda', str_contains($aj, 'csrf_check(') && str_contains($aj, 'pdks_toplu_hizli_gruplar('));
t('ajax: coklu_grup yalnız sunucu', !str_contains($aj, "\$__tpIn['coklu_grup']"));
$fz = (string)file_get_contents($ROOT . '/config/pdks_toplu_hizli.php');
t('saf fonksiyon: DB/INSERT yok', !preg_match('/\b(INSERT|UPDATE|DELETE|db\(\)|\$pdo)\b/i', $fz));

echo "pdks_toplu_hizli_smoke: $ok ok, $fail fail\n";
exit($fail ? 1 : 0);
