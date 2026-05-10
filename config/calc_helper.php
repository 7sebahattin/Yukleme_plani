<?php
// =========================================================
// config/calc_helper.php
// Kalan palet kombinasyon hesaplama
// =========================================================
declare(strict_types=1);

/**
 * Kalan palet sayısını verilen kasa tipleriyle dolduracak tüm kombinasyonları üretir,
 * hedefe en yakın 10 tanesini döndürür.
 *
 * @param int   $n_pallets   Kalan palet sayısı
 * @param array $types       [['crate_count'=>90, 'avg_kg'=>970.0], ...]
 * @param float $remaining_kg Kalan kilo hedefi
 * @return array             En iyi 10 sonuç
 */
function kalan_combinations(int $n_pallets, array $types, float $remaining_kg): array
{
    if ($n_pallets <= 0 || empty($types)) return [];

    $avg_map = [];
    foreach ($types as $t) {
        $avg_map[(int)$t['crate_count']] = (float)$t['avg_kg'];
    }

    $all = [];
    _kalan_gen($n_pallets, $types, 0, [], $remaining_kg, $avg_map, $all);

    usort($all, fn($a, $b) => abs($a['diff']) <=> abs($b['diff']));

    $seen   = [];
    $result = [];
    foreach ($all as $r) {
        $parts = array_values(array_filter($r['parts'], fn($p) => $p['count'] > 0));
        if (empty($parts)) continue;
        $key = implode('|', array_map(fn($p) => "{$p['crate_count']}:{$p['count']}", $parts));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $r['parts'] = $parts;
        $result[]   = $r;
        if (count($result) >= 10) break;
    }
    return $result;
}

function _kalan_gen(int $n, array $types, int $idx, array $cur, float $rem_kg, array $avg_map, array &$out): void
{
    $last = count($types) - 1;
    if ($idx === $last) {
        $parts = array_merge($cur, [['crate_count' => (int)$types[$idx]['crate_count'], 'count' => $n]]);
        $est   = 0.0;
        foreach ($parts as $p) {
            $est += $p['count'] * ($avg_map[(int)$p['crate_count']] ?? 0.0);
        }
        $out[] = ['parts' => $parts, 'estimated_kg' => $est, 'diff' => $est - $rem_kg];
        return;
    }
    for ($i = 0; $i <= $n; $i++) {
        _kalan_gen(
            $n - $i,
            $types,
            $idx + 1,
            array_merge($cur, [['crate_count' => (int)$types[$idx]['crate_count'], 'count' => $i]]),
            $rem_kg,
            $avg_map,
            $out
        );
    }
}

function kalan_status(float $diff): array
{
    $a = abs($diff);
    if ($a <= 50)  return ['text' => 'Çok iyi',          'cls' => 'kalan-great'];
    if ($a <= 150) return ['text' => 'Uygun',            'cls' => 'kalan-good'];
    if ($a <= 300) return ['text' => 'Kabul edilebilir', 'cls' => 'kalan-ok'];
    return             ['text' => 'Dikkat',          'cls' => 'kalan-warn'];
}
