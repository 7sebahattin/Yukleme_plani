<?php
// =========================================================
// repair_kasa_ids.php — Çıkma paletlerinde eksik kasa_cinsi_id / palet_tipi_id düzelt
// WEB ERİŞİMİ KAPALI — sadece CLI ile çalıştırın:
//   php scripts/repair_kasa_ids.php
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/db.php';

$pdo = db();
$log = [];

// ── Kasa / palet tanımlarını bul veya ekle ──────────────────────────────────
function ensure_material(PDO $pdo, string $type, string $name, float $unit): int {
    $st = $pdo->prepare(
        "SELECT id FROM material_definitions
         WHERE type=:t AND UPPER(TRIM(name))=UPPER(TRIM(:n)) AND is_active=1
         LIMIT 1"
    );
    $st->execute([':t' => $type, ':n' => $name]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare(
        "INSERT INTO material_definitions (type, name, unit_dara_kg, is_active)
         VALUES (:t, :n, :u, 1)"
    )->execute([':t' => $type, ':n' => $name, ':u' => $unit]);
    return (int)$pdo->lastInsertId();
}

function ensure_palet_by_unit(PDO $pdo, string $name, float $unit): int {
    $st = $pdo->prepare(
        "SELECT id FROM material_definitions
         WHERE type='palet_tipi' AND ABS(unit_dara_kg - :u) < 0.01 AND is_active=1
         LIMIT 1"
    );
    $st->execute([':u' => $unit]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $pdo->prepare(
        "INSERT INTO material_definitions (type, name, unit_dara_kg, is_active)
         VALUES ('palet_tipi', :n, :u, 1)"
    )->execute([':n' => $name, ':u' => $unit]);
    return (int)$pdo->lastInsertId();
}

$kasa_defs = [
    'K-65'   => ['unit' => 2.00,  'label' => 'K-65'],
    'C-10'   => ['unit' => 0.48,  'label' => 'C-10'],
    'AYAKLI' => ['unit' => 0.50,  'label' => 'Ayaklı'],
];
$palet_defs = [
    30 => 'Palet 30 kg',
    18 => 'Palet 18 kg',
];

$kasa_ids  = [];
$palet_ids = [];

foreach ($kasa_defs as $key => $def) {
    $id = ensure_material($pdo, 'kasa_cinsi', $def['label'], $def['unit']);
    $kasa_ids[$key] = $id;
    $log[] = "OK Kasa: {$def['label']} (ID=$id, birim={$def['unit']} kg/kasa)";
}
foreach ($palet_defs as $kg => $name) {
    $id = ensure_palet_by_unit($pdo, $name, (float)$kg);
    $palet_ids[$kg] = $id;
    $log[] = "OK Palet: $name (ID=$id, {$kg} kg)";
}

$excel_rows = [
    ['2026-05-03', '1 (30 kg)', 'K-65',   36, 1000.0,  102.0,   898.0,   'MEYSU'],
    ['2026-05-03', '1 (30 kg)', 'K-65',   36,  715.0,  102.0,   613.0,   'MEYSU'],
    ['2026-05-03', '1 (30 kg)', 'K-65',   36, 1000.0,  102.0,   898.0,   'MEYSU'],
    ['2026-05-03', '1 (30 kg)', 'K-65',   21,  607.0,   72.0,   535.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   30,  745.0,   90.0,   655.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   30,  681.0,   90.0,   591.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   20,  448.0,   70.0,   378.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   30,  669.0,   90.0,   579.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   16,  387.0,   62.0,   325.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   21,  476.0,   72.0,   404.0,   'MEYSU'],
    ['2026-05-06', '1 (30 kg)', 'K-65',   30,  738.0,   90.0,   648.0,   'MEYSU'],
    ['2026-05-06', '1 (18 kg)', 'C-10',   59,  574.0,   46.32,  527.68,  'KÜÇÜK'],
    ['2026-05-07', '1 (30 kg)', 'K-65',   36,  826.0,  102.0,   724.0,   'MEYSU'],
    ['2026-05-07', '1 (30 kg)', 'K-65',   24,  564.0,   78.0,   486.0,   'MEYSU'],
    ['2026-05-07', '1 (18 kg)', 'C-10',   57,  594.0,   45.36,  548.64,  'KÜÇÜK'],
    ['2026-05-08', '1 (30 kg)', 'K-65',   29,  620.0,   88.0,   532.0,   'MEYSU'],
    ['2026-05-08', '1 (30 kg)', 'K-65',   34,  724.0,   98.0,   626.0,   'MEYSU'],
    ['2026-05-08', '1 (18 kg)', 'C-10',  100, 1033.0,   66.0,   967.0,   'KÜÇÜK'],
    ['2026-05-08', '1 (18 kg)', 'C-10',   14,  144.0,   24.72,  119.28,  'KÜÇÜK'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   36,  710.0,  102.0,   608.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   36,  803.0,  102.0,   701.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   36,  780.0,  102.0,   678.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   36,  790.0,  102.0,   688.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   36,  787.0,  102.0,   685.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   42,  851.0,  114.0,   737.0,   'MEYSU'],
    ['2026-05-09', '1 (30 kg)', 'K-65',   28,  628.0,   86.0,   542.0,   'MEYSU'],
    ['2026-05-10', '1 (18 kg)', 'C-10',  100,  998.0,   66.0,   932.0,   'KÜÇÜK'],
    ['2026-05-10', '1 (18 kg)', 'C-10',  100,  995.0,   66.0,   929.0,   'KÜÇÜK'],
    ['2026-05-10', '1 (18 kg)', 'C-10',  100,  997.0,   66.0,   931.0,   'KÜÇÜK'],
    ['2026-05-10', '1 (30 kg)', 'K-65',   36,  785.0,  102.0,   683.0,   'MEYSU'],
    ['2026-05-10', '1 (30 kg)', 'K-65',   36,  764.0,  102.0,   662.0,   'MEYSU'],
    ['2026-05-10', '1 (30 kg)', 'K-65',   36,  788.0,  102.0,   686.0,   'MEYSU'],
    ['2026-05-10', '1 (30 kg)', 'K-65',   36,  777.0,  102.0,   675.0,   'MEYSU'],
    ['2026-05-10', '1 (30 kg)', 'K-65',   42,  858.0,  114.0,   744.0,   'MEYSU'],
    ['2026-05-10', '1 (18 kg)', 'C-10',   28,  282.0,   31.44,  250.56,  'KÜÇÜK'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   37,  735.0,  104.0,   631.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   36,  710.0,  102.0,   608.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   30,  666.0,   90.0,   576.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   32,  655.0,   94.0,   561.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   24,  540.0,   78.0,   462.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   19,  391.0,   68.0,   323.0,   'MEYSU'],
    ['2026-05-11', '1 (30 kg)', 'K-65',   36,  905.0,  102.0,   803.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   18,  479.0,   66.0,   413.0,   'KÜÇÜK'],
    ['2026-05-12', '1 (18 kg)', 'C-10',  100, 1033.0,   66.0,   967.0,   'KÜÇÜK'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   36,  707.0,  102.0,   605.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   30,  690.0,   90.0,   600.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   34,  706.0,   98.0,   608.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   24,  593.0,   78.0,   515.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   21,  455.0,   72.0,   383.0,   'MEYSU'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   32,  679.0,   94.0,   585.0,   'MEYSU'],
    ['2026-05-12', '1 (18 kg)', 'C-10',   75,  777.0,   54.0,   723.0,   'KÜÇÜK'],
    ['2026-05-12', '1 (30 kg)', 'K-65',   14,  364.0,   58.0,   306.0,   'KÜÇÜK'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   36,  784.0,  102.0,   682.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   36,  749.0,  102.0,   647.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  635.0,   90.0,   545.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   36,  740.0,  102.0,   638.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   36,  776.0,  102.0,   674.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  626.0,   90.0,   536.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   36,  775.0,  102.0,   673.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   23,  564.0,   76.0,   488.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   17,  367.0,   64.0,   303.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   21,  461.0,   72.0,   389.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',    9,  200.0,   48.0,   152.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  676.0,   90.0,   586.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   23,  586.0,   76.0,   510.0,   'KÜÇÜK'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   13,  317.0,   56.0,   261.0,   'KÜÇÜK'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  638.0,   90.0,   548.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  677.0,   90.0,   587.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  663.0,   90.0,   573.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   34,  743.0,   98.0,   645.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   31,  688.0,   92.0,   596.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   15,  358.0,   60.0,   298.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   21,  475.0,   72.0,   403.0,   'MEYSU'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  698.0,   90.0,   608.0,   'KÜÇÜK'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   30,  690.0,   90.0,   600.0,   'KÜÇÜK'],
    ['2026-05-13', '1 (30 kg)', 'K-65',   18,  367.0,   66.0,   301.0,   'KÜÇÜK'],
    ['2026-05-15', '1 (18 kg)', 'AYAKLI',100, 1064.0,   68.0,   996.0,   'KÜÇÜK'],
    ['2026-05-15', '1 (18 kg)', 'AYAKLI',100, 1076.0,   68.0,  1008.0,   'KÜÇÜK'],
    ['2026-05-15', '1 (18 kg)', 'AYAKLI',100, 1041.0,   68.0,   973.0,   'KÜÇÜK'],
    ['2026-05-15', '1 (18 kg)', 'AYAKLI',100, 1048.0,   68.0,   980.0,   'KÜÇÜK'],
];

// ── Grupla ──────────────────────────────────────────────────────────────────
$groups = [];
foreach ($excel_rows as $row) {
    [$tarih, $palet_desc, $tur, , , , , $cikma_tur] = $row;
    $key = $tarih . '|' . $cikma_tur;
    $groups[$key][] = $row;
}

function get_palet_id(string $desc, array $palet_ids): ?int {
    if (preg_match('/\((\d+)\s*kg\)/i', $desc, $m)) {
        $kg = (int)$m[1];
        return $palet_ids[$kg] ?? null;
    }
    return null;
}

$find_rec = $pdo->prepare(
    "SELECT id FROM loading_records
     WHERE type='cikma' AND tarih=? AND firma=?"
);
$get_pallets = $pdo->prepare(
    "SELECT id, kasa_cinsi_id, palet_tipi_id FROM loading_pallets
     WHERE loading_record_id=? ORDER BY sira_no, id"
);
$upd_pal = $pdo->prepare(
    "UPDATE loading_pallets SET kasa_cinsi_id=?, palet_tipi_id=? WHERE id=?"
);

$updated_records = 0;
$updated_pallets = 0;
$skipped_pallets = 0;

foreach ($groups as $key => $rows) {
    [$tarih, $cikma_tur] = explode('|', $key, 2);

    $find_rec->execute([$tarih, $cikma_tur]);
    $rec_id = $find_rec->fetchColumn();
    if (!$rec_id) {
        $log[] = "UYARI Kayıt bulunamadı: $tarih / $cikma_tur";
        continue;
    }

    $get_pallets->execute([$rec_id]);
    $db_pallets = $get_pallets->fetchAll();

    if (count($db_pallets) !== count($rows)) {
        $log[] = "UYARI Palet sayısı uyuşmuyor (Kayıt #$rec_id): Excel=" . count($rows) . ", DB=" . count($db_pallets);
    }

    $updated_this = 0;
    foreach ($db_pallets as $i => $pal) {
        if (!isset($rows[$i])) break;
        [, $palet_desc, $tur] = $rows[$i];

        $new_kasa_id  = $kasa_ids[strtoupper(trim($tur))] ?? null;
        $new_palet_id = get_palet_id($palet_desc, $palet_ids);

        $needs_update = ($pal['kasa_cinsi_id'] === null && $new_kasa_id !== null)
                     || ($pal['palet_tipi_id'] === null && $new_palet_id !== null);

        if (!$needs_update) {
            $skipped_pallets++;
            continue;
        }

        $final_kasa  = $pal['kasa_cinsi_id'] ?? $new_kasa_id;
        $final_palet = $pal['palet_tipi_id'] ?? $new_palet_id;

        $upd_pal->execute([$final_kasa, $final_palet, $pal['id']]);
        $updated_pallets++;
        $updated_this++;
    }

    if ($updated_this > 0) {
        $updated_records++;
        $log[] = "OK Kayıt #$rec_id ($tarih / $cikma_tur): $updated_this palet güncellendi";
    } else {
        $log[] = "-- Kayıt #$rec_id ($tarih / $cikma_tur): güncelleme gerekmedi";
    }
}

foreach ($log as $line) {
    echo $line . PHP_EOL;
}
echo PHP_EOL;
echo "Güncellenen kayıt: $updated_records | Güncellenen palet: $updated_pallets | Atlanan (zaten dolu): $skipped_pallets" . PHP_EOL;
echo 'Tamamlandı.' . PHP_EOL;
