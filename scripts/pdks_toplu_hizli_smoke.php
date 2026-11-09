<?php
// scripts/pdks_toplu_hizli_smoke.php — Toplu İşlem kişi sayısı + FM dağıtımı (saf fonksiyon; DB yok)
//   php scripts/pdks_toplu_hizli_smoke.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$ROOT = dirname(__DIR__);
require_once $ROOT . '/config/pdks_toplu_hizli.php';
$ok = 0; $fail = 0;
function t(string $ad, bool $c): void { global $ok, $fail; if ($c) { $ok++; } else { $fail++; echo "FAIL: $ad\n"; } }

// Sahip örneği: havuz = K17 E10 EY1 Ş1, 5 kişi → 3 kadın + 2 erkek
$d = pdks_toplu_fm_dagit(['KADIN_TAM' => 17, 'ERKEK_TAM' => 10, 'KADIN_YARIM' => 0, 'ERKEK_YARIM' => 1, 'SERITCI' => 1], 5);
t('örnek ok', $d['ok'] && $d['dagilim'] === ['KADIN_TAM' => 3, 'ERKEK_TAM' => 2, 'KADIN_YARIM' => 0, 'ERKEK_YARIM' => 0, 'SERITCI' => 0]);
t('örnek toplam', array_sum($d['dagilim']) === 5);
// Yarım/şeritçi payı düşerse onlar da alır: 1 kadın, 1 şeritçi, 2 kişi → ikisi de
t('şeritçi de alır', pdks_toplu_fm_dagit(['KADIN_TAM' => 1, 'SERITCI' => 1], 2)['dagilim'] === ['KADIN_TAM' => 1, 'SERITCI' => 1]);

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

// Grup üretimi — K17 E10 EY1 Ş1, 5 kişi × 3 sa
$tipKod = [1 => 'KADIN', 2 => 'ERKEK', 4 => 'SERITCI', 5 => 'RAMPACI'];
$g = pdks_toplu_hizli_gruplar(['tam' => [1 => 17, 2 => 10, 4 => 1], 'yarim' => [2 => 1], 'fm' => ['kisi' => 5, 'saat' => '3']], $tipKod, '2026-11-09');
t('grup hata yok', $g['hata'] === null);
$ozet = [];
foreach ($g['gruplar'] as $x) $ozet[] = $x['worker_type_id'] . ':' . $x['kartsiz_adet'] . ':' . $x['exit_date'] . ' ' . $x['exit_clock'];
$bek = ['1:14:2026-11-09 17:00', '1:3:2026-11-09 20:00', '2:8:2026-11-09 17:00', '2:2:2026-11-09 20:00', '4:1:2026-11-09 17:00', '2:1:2026-11-09 12:30'];
t('gruplar', $ozet === $bek);
if ($ozet !== $bek) echo json_encode($ozet), "\n";
t('toplam kişi 29', array_sum(array_column($g['gruplar'], 'kartsiz_adet')) === 29);
// Yarım FM alırsa çıkış 12:30 + FM
$g3 = pdks_toplu_hizli_gruplar(['yarim' => [1 => 2], 'fm' => ['kisi' => 1, 'saat' => '2']], $tipKod, '2026-11-09');
t('yarım FM çıkışı 14:30', $g3['gruplar'][1]['exit_clock'] === '14:30' && $g3['gruplar'][0]['exit_clock'] === '12:30');
t('yarım yalnız kadın/erkek', pdks_toplu_hizli_gruplar(['yarim' => [4 => 2, 5 => 2]], $tipKod, '2026-11-09')['gruplar'] === []);
t('rampacı tam grup, FM havuzu dışı', count(pdks_toplu_hizli_gruplar(['tam' => [5 => 3]], $tipKod, '2026-11-09')['gruplar']) === 1
    && pdks_toplu_hizli_gruplar(['tam' => [5 => 3], 'fm' => ['kisi' => 1, 'saat' => '2']], $tipKod, '2026-11-09')['hata'] !== null);
// Gece yarısını aşan FM → ertesi gün
$g2 = pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 1, 'saat' => '8']], $tipKod, '2026-11-09');
t('gece yarısı', $g2['gruplar'][1]['exit_date'] === '2026-11-10' && $g2['gruplar'][1]['exit_clock'] === '01:00');
t('FM saat yok', pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 1, 'saat' => '']], $tipKod, '2026-11-09')['hata'] !== null);
t('FM aşım', pdks_toplu_hizli_gruplar(['tam' => [1 => 2], 'fm' => ['kisi' => 3, 'saat' => '2']], $tipKod, '2026-11-09')['hata'] !== null);
t('bilinmeyen tip yok sayılır', pdks_toplu_hizli_gruplar(['tam' => [99 => 5]], $tipKod, '2026-11-09')['gruplar'] === []);
t('FM yok → tek grup', count(pdks_toplu_hizli_gruplar(['tam' => [1 => 4]], $tipKod, '2026-11-09')['gruplar']) === 1);

// JS aynası + sunucu uçları (statik)
$js = (string)file_get_contents($ROOT . '/_puantaj_toplu.php');
t('JS fmDagit aynası', str_contains($js, 'function fmDagit') && str_contains($js, 'kalan[b] - kalan[a]') && str_contains($js, 'mevcut[b] - mevcut[a]') && str_contains($js, "'ERKEK_YARIM', 'SERITCI'"));
$aj = (string)file_get_contents($ROOT . '/_puantaj_toplu_ajax.php');
t('ajax: csrf + grup sunucuda', str_contains($aj, 'csrf_check(') && str_contains($aj, 'pdks_toplu_hizli_isle('));
t('ajax: coklu_grup yalnız sunucu', !str_contains($aj, 'coklu_grup'));
$fz = (string)file_get_contents($ROOT . '/config/pdks_toplu_hizli.php');
t('saf fonksiyon: DB/INSERT yok', !preg_match('/\b(INSERT|UPDATE|DELETE|db\(\)|\$pdo)\b/i', $fz));

// Çift yevmiye: Tam'a EK, FM havuzuna girmez, 08:00-17:00, yarım yok
$gc = pdks_toplu_hizli_gruplar(['tam' => [1 => 4], 'cift' => [1 => 2, 2 => 1, 4 => 1, 5 => 1], 'fm' => ['kisi' => 2, 'saat' => '1']], $tipKod, '2026-11-09');
$cg = array_values(array_filter($gc['gruplar'], fn($x) => !empty($x['cift'])));
t('cift: 4 grup, 08:00-17:00', count($cg) === 4 && $cg[0]['kartsiz_adet'] === 2 && $cg[0]['exit_clock'] === '17:00' && $cg[0]['entry_clock'] === '08:00');
t('cift: FM havuzuna girmez', array_sum(array_column(array_filter($gc['gruplar'], fn($x) => empty($x['cift']) && $x['exit_clock'] === '18:00'), 'kartsiz_adet')) === 2 && $gc['hata'] === null);
t('cift: Tamdan dusmez', array_sum(array_column(array_filter($gc['gruplar'], fn($x) => empty($x['cift'])), 'kartsiz_adet')) === 4);
t('cift: yalniz cift ile FM hata', pdks_toplu_hizli_gruplar(['cift' => [1 => 3], 'fm' => ['kisi' => 1, 'saat' => '1']], $tipKod, '2026-11-09')['hata'] !== null);
t('cift: JS kutusu + hz.cift + FM dislama', str_contains($js, 'data-hizli-cift') && str_contains($js, 'cift: {}') && str_contains($js, "c.hasAttribute('data-hizli-cift')) return;"));
$kodFn = static fn(int $i): ?string => $tipKod[$i] ?? null;
$is = pdks_toplu_hizli_isle(['tam' => [1 => 3], 'cift' => [2 => 1]], '2026-11-09', [['worker_type_id' => 1, 'kartsiz_adet' => 0]], $kodFn);
t('isle: ek anahtarlar sunucuda kurulur', $is['hata'] === null && ($is['ek']['coklu_grup'] ?? false) === true && isset($is['ek']['audit_ek']['hizli_giris']) && count($is['gruplar']) === 3);
t('isle: hizli yoksa istemci grupları aynen, ek boş', pdks_toplu_hizli_isle(null, '2026-11-09', [['x' => 1]], $kodFn) === ['hata' => null, 'gruplar' => [['x' => 1]], 'ek' => []]);
t('isle: hata iletilir', pdks_toplu_hizli_isle(['tam' => [1 => 1], 'fm' => ['kisi' => 5, 'saat' => '1']], '2026-11-09', [], $kodFn)['hata'] !== null);
$f8 = (string)file_get_contents($ROOT . '/config/pdks_faz8j.php');
t('çift bayrağı: kolon PDKS_CIFT_KOLON adıyla + fail-closed (satir_yaz)', str_contains($f8, "', ' . PDKS_CIFT_KOLON") && str_contains($f8, 'Çift yevmiye alanı kurulmamış'));
t('çift bayrağı: yalnız coklu_grup (kod yolu)', str_contains($f8, "\$cift = \$coklu && !empty(\$g['cift'])"));
t('INSERT dönem hâlâ TEK yerde', substr_count($f8, 'INSERT INTO daily_worker_work_periods') === 1);
echo "pdks_toplu_hizli_smoke: $ok ok, $fail fail\n";
exit($fail ? 1 : 0);
