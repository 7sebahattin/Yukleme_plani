<?php
// =========================================================
// repair_kasa_ids.php — Çıkma paletlerinde eksik kasa_cinsi_id / palet_tipi_id düzelt
//
// Ne yapar:
//  1) K-65, C-10, AYAKLI kasa tanımları yoksa material_definitions'a ekler
//  2) 30 kg / 18 kg palet tanımı yoksa ekler
//  3) loading_pallets'ta NULL olan kasa_cinsi_id / palet_tipi_id'yi günceller
//     (sadece NULL olanlara dokunur — kullanıcı elle düzeltmiş olabilir)
//
// Çalıştırmak: ?run=1
// =========================================================
declare(strict_types=1);

if (($_GET['run'] ?? '') !== '1') {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Kasa ID Onarımı</title></head><body>';
    echo '<h2>Çıkma Kasa ID Onarım Scripti</h2>';
    echo '<p>Bu script, excel importundan kaynaklanan eksik <code>kasa_cinsi_id</code> ve <code>palet_tipi_id</code> değerlerini düzeltir.</p>';
    echo '<ul>
        <li><strong>K-65</strong> kasa cinsi → 2,00 kg/kasa dara</li>
        <li><strong>C-10</strong> kasa cinsi → 0,48 kg/kasa dara</li>
        <li><strong>AYAKLI</strong> kasa cinsi → 0,50 kg/kasa dara</li>
        <li><strong>Palet 30 kg</strong> ve <strong>Palet 18 kg</strong> tanımları</li>
    </ul>';
    echo '<p>Sadece <code>kasa_cinsi_id = NULL</code> ve <code>palet_tipi_id = NULL</code> olan paletlere dokunur.</p>';
    echo '<p><a href="?run=1" style="background:#1a56db;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none">▶ Çalıştır</a></p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/config/db.php';
header('Content-Type: text/html; charset=utf-8');

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
    // Palet tipi birim ağırlığına göre eşleştir (isim fark etmez)
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

// Kasa birimleri Excel verisinden türetildi:
// K-65:   (dara - palet_dara) / kasa_adeti = (102 - 30) / 36 = 2.00 kg/kasa
// C-10:   (dara - palet_dara) / kasa_adeti = (46.32 - 18) / 59 ≈ 0.48 kg/kasa
// AYAKLI: (dara - palet_dara) / kasa_adeti = (68 - 18) / 100 = 0.50 kg/kasa
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
    $log[] = "✅ Kasa: {$def['label']} (ID=$id, birim={$def['unit']} kg/kasa)";
}
foreach ($palet_defs as $kg => $name) {
    $id = ensure_palet_by_unit($pdo, $name, (float)$kg);
    $palet_ids[$kg] = $id;
    $log[] = "✅ Palet: $name (ID=$id, {$kg} kg)";
}

// ── Excel verisi (import_cikmalar.php ile aynı) ──────────────────────────────
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

// ── Palet tipi ID'sini palet_desc'ten çıkar ──────────────────────────────────
function get_palet_id(string $desc, array $palet_ids): ?int {
    if (preg_match('/\((\d+)\s*kg\)/i', $desc, $m)) {
        $kg = (int)$m[1];
        return $palet_ids[$kg] ?? null;
    }
    return null;
}

// ── Her grup için kayıt bul ve paletleri güncelle ───────────────────────────
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
        $log[] = "⚠️ Kayıt bulunamadı: $tarih / $cikma_tur";
        continue;
    }

    $get_pallets->execute([$rec_id]);
    $db_pallets = $get_pallets->fetchAll();

    if (count($db_pallets) !== count($rows)) {
        $log[] = "⚠️ Palet sayısı uyuşmuyor (Kayıt #$rec_id): Excel=" . count($rows) . ", DB=" . count($db_pallets);
        // Yine de eşleşebilenleri güncelle
    }

    $updated_this = 0;
    foreach ($db_pallets as $i => $pal) {
        if (!isset($rows[$i])) break;
        [, $palet_desc, $tur] = $rows[$i];

        $new_kasa_id  = $kasa_ids[strtoupper(trim($tur))] ?? null;
        $new_palet_id = get_palet_id($palet_desc, $palet_ids);

        // Sadece NULL olanları güncelle (elle girilmiş değerlere dokunma)
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
        $log[] = "✅ Kayıt #$rec_id ($tarih / $cikma_tur): $updated_this palet güncellendi";
    } else {
        $log[] = "— Kayıt #$rec_id ($tarih / $cikma_tur): güncelleme gerekmedi";
    }
}

// ── Çıktı ───────────────────────────────────────────────────────────────────
echo '<!doctype html><html><head><meta charset="utf-8"><title>Kasa ID Onarımı</title>';
echo '<style>body{font-family:sans-serif;max-width:800px;margin:20px auto;padding:0 16px}
li{margin:4px 0}
.ok{color:#166534} .warn{color:#92400e} .dash{color:#6b7280}
.summary{background:#f0f4ff;border-radius:8px;padding:12px 16px;margin-bottom:16px}
</style></head><body>';
echo '<h2>🔧 Çıkma Kasa ID Onarımı — Sonuç</h2>';
echo '<div class="summary">';
echo "<strong>Material definitions:</strong> K-65, C-10, Ayaklı, Palet 30 kg, Palet 18 kg &nbsp;|&nbsp; ";
echo "<strong>Güncellenen kayıt:</strong> $updated_records &nbsp;|&nbsp; ";
echo "<strong>Güncellenen palet:</strong> $updated_pallets &nbsp;|&nbsp; ";
echo "<strong>Atlanan (zaten dolu):</strong> $skipped_pallets";
echo '</div>';
echo '<ul>';
foreach ($log as $line) {
    if (str_starts_with($line, '✅')) $cls = 'ok';
    elseif (str_starts_with($line, '⚠')) $cls = 'warn';
    else $cls = 'dash';
    echo '<li class="'.$cls.'">'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</li>';
}
echo '</ul>';
echo '<p><strong>Tamamlandı.</strong></p>';
echo '<p>Artık çıkma kayıtlarını düzenlediğinizde net KG doğru hesaplanacaktır.</p>';
echo '<p><a href="cikmalar.php">→ Çıkmalar listesine git</a></p>';
echo '</body></html>';
