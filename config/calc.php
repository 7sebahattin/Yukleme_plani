<?php
// =========================================================
// config/calc.php
// Sunucu tarafı dara/net hesaplaması
// JS hesaplamasıyla AYNI mantık - güvenli yeniden hesaplama.
// =========================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Bir palet satırının dara, net ve diğer alanlarını hesaplar.
 *
 * @param array $row Form verisinden gelen ham satır:
 *   - palet_no, kasa_adeti, size, brut_kg
 *   - kasa_cinsi_id, palet_tipi_id
 *   - urun_cinsi, depo
 *   - materials: [['material_id'=>x, 'quantity'=>n], ...]
 * @return array Hesaplanmış satır + materials (her birinde total_dara_kg dolu)
 */
function round_half(float $n): float {
    return round($n * 2) / 2;
}

function compute_pallet_row(array $row): array {
    $kasa_adeti = intval_safe($row['kasa_adeti'] ?? 0);
    $brut       = num($row['brut_kg'] ?? 0);

    $kasa_cinsi_id = isset($row['kasa_cinsi_id']) && $row['kasa_cinsi_id'] !== ''
        ? (int)$row['kasa_cinsi_id'] : null;
    $palet_tipi_id = isset($row['palet_tipi_id']) && $row['palet_tipi_id'] !== ''
        ? (int)$row['palet_tipi_id'] : null;

    // Tek seferde gerekli tüm tanımları çek
    $needed_ids = [];
    if ($kasa_cinsi_id) $needed_ids[] = $kasa_cinsi_id;
    if ($palet_tipi_id) $needed_ids[] = $palet_tipi_id;

    $materials = [];
    if (!empty($row['materials']) && is_array($row['materials'])) {
        foreach ($row['materials'] as $m) {
            $mid = isset($m['material_id']) ? (int)$m['material_id'] : 0;
            $qty = num($m['quantity'] ?? 1);
            if ($mid > 0 && $qty > 0) {
                $materials[] = ['material_id' => $mid, 'quantity' => $qty];
                $needed_ids[] = $mid;
            }
        }
    }

    $defs_by_id = [];
    if (!empty($needed_ids)) {
        $needed_ids = array_values(array_unique($needed_ids));
        $place      = implode(',', array_fill(0, count($needed_ids), '?'));
        $st = db()->prepare("SELECT id, type, name, unit_dara_kg
                             FROM material_definitions
                             WHERE id IN ($place)");
        $st->execute($needed_ids);
        foreach ($st->fetchAll() as $d) {
            $defs_by_id[(int)$d['id']] = $d;
        }
    }

    // Kasa darası
    $kasa_unit = $kasa_cinsi_id && isset($defs_by_id[$kasa_cinsi_id])
        ? num($defs_by_id[$kasa_cinsi_id]['unit_dara_kg']) : 0;
    $kasa_dara_total = $kasa_adeti * $kasa_unit;

    // Palet darası
    $palet_dara_total = $palet_tipi_id && isset($defs_by_id[$palet_tipi_id])
        ? num($defs_by_id[$palet_tipi_id]['unit_dara_kg']) : 0;

    // Diğer malzeme daraları
    $extra_total = 0.0;
    foreach ($materials as &$m) {
        $unit = isset($defs_by_id[$m['material_id']])
            ? num($defs_by_id[$m['material_id']]['unit_dara_kg']) : 0;
        $m['total_dara_kg'] = round($unit * $m['quantity'], 3);
        $extra_total += $m['total_dara_kg'];
    }
    unset($m);

    $dara = round_half($kasa_dara_total + $palet_dara_total + $extra_total);
    $net  = round(max(0, $brut - $dara), 3);

    return [
        'palet_no'      => trim((string)($row['palet_no'] ?? '')),
        'kasa_adeti'    => $kasa_adeti,
        'size'          => trim((string)($row['size'] ?? '')),
        'brut_kg'       => round($brut, 3),
        'dara_kg'       => $dara,
        'net_kg'        => $net,
        'kasa_cinsi_id' => $kasa_cinsi_id,
        'palet_tipi_id' => $palet_tipi_id,
        'urun_cinsi'    => trim((string)($row['urun_cinsi'] ?? '')),
        'depo'          => trim((string)($row['depo'] ?? '')),
        'materials'     => $materials,
    ];
}
