<?php
// =========================================================
// import_cikmalar.php - Excel çıkma verilerini DB'ye aktar
// Tek seferlik kullanım. Sonradan silmeyi unutmayın.
// Çalıştırmak için: ?run=1
// =========================================================
declare(strict_types=1);

if (($_GET['run'] ?? '') !== '1') {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>İçe Aktar</title></head><body>';
    echo '<h2>Çıkma Verisi İçe Aktarma</h2>';
    echo '<p>Bu script Excel\'den alınan 80 palet satırını veritabanına ekler.</p>';
    echo '<p>Gruplama: aynı tarih + aynı çıkma türü → tek kayıt, birden fazla palet.</p>';
    echo '<p><strong>Çakışma koruması:</strong> Aynı tarih+firma+type\'ı olan kayıt varsa atlanır.</p>';
    echo '<p><a href="?run=1" style="background:#1a56db;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none">Çalıştır</a></p>';
    echo '</body></html>';
    exit;
}

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');

// ── Ham Excel verisi (tarih, palet_desc, tur, kasa, brut, dara, net, cikma_tur) ──
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

$pdo = db();
$log = [];
$skipped = 0;
$created_records = 0;
$created_pallets = 0;

// ── material_definitions önbelleği ──
$kasa_map  = [];  // 'K-65' → id
$palet_map = [];  // 30 → id (unit_dara_kg)

$kasa_rows = $pdo->query(
    "SELECT id, name FROM material_definitions WHERE type = 'kasa_cinsi' AND is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($kasa_rows as $r) {
    $kasa_map[strtoupper(trim($r['name']))] = (int)$r['id'];
}

$palet_rows = $pdo->query(
    "SELECT id, name, unit_dara_kg FROM material_definitions WHERE type = 'palet_tipi' AND is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($palet_rows as $r) {
    $kg = (int)round((float)$r['unit_dara_kg']);
    if (!isset($palet_map[$kg])) $palet_map[$kg] = (int)$r['id'];
}

// ── Satırları (tarih, cikma_tur) gruplarına ayır ──
$groups = [];
foreach ($excel_rows as $row) {
    [$tarih, $palet_desc, $tur, $kasa, $brut, $dara, $net, $cikma_tur] = $row;
    $key = $tarih . '|' . $cikma_tur;
    $groups[$key][] = $row;
}

// ── Mevcut kayıt çakışması kontrolü için ──
$chk_st = $pdo->prepare(
    "SELECT COUNT(*) FROM loading_records
     WHERE type = 'cikma' AND tarih = ? AND firma = ?"
);

$ins_rec = $pdo->prepare(
    "INSERT INTO loading_records (type, tarih, firma, bolge, urun, is_islendi, is_yuklendi)
     VALUES ('cikma', ?, ?, '', '', 0, 0)"
);

$ins_pal = $pdo->prepare(
    "INSERT INTO loading_pallets
     (loading_record_id, palet_no, kasa_adeti, size, brut_kg, dara_kg, net_kg,
      kasa_cinsi_id, palet_tipi_id, urun_cinsi, depo, sira_no)
     VALUES (?, ?, ?, '', ?, ?, ?, ?, ?, '', '', ?)"
);

foreach ($groups as $key => $rows) {
    [$tarih, $cikma_tur] = explode('|', $key, 2);

    // Çakışma kontrolü
    $chk_st->execute([$tarih, $cikma_tur]);
    if ((int)$chk_st->fetchColumn() > 0) {
        $log[] = "⚠️ Atlandı (zaten var): $tarih / $cikma_tur (" . count($rows) . " palet)";
        $skipped++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $ins_rec->execute([$tarih, $cikma_tur]);
        $rec_id = (int)$pdo->lastInsertId();
        $created_records++;

        $sira = 0;
        foreach ($rows as $row) {
            [$tarih2, $palet_desc, $tur, $kasa, $brut, $dara, $net, $cikma_tur2] = $row;

            // Kasa cinsi ID
            $kasa_id = $kasa_map[strtoupper(trim($tur))] ?? null;

            // Palet tipi ID — "1 (30 kg)" → 30 kg
            $palet_id = null;
            if (preg_match('/\((\d+)\s*kg\)/i', $palet_desc, $m)) {
                $palet_kg = (int)$m[1];
                $palet_id = $palet_map[$palet_kg] ?? null;
            }

            $ins_pal->execute([
                $rec_id,
                $sira + 1,   // palet_no
                $kasa,
                $brut,
                $dara,
                $net,
                $kasa_id,
                $palet_id,
                $sira,       // sira_no
            ]);
            $created_pallets++;
            $sira++;
        }

        $pdo->commit();
        $log[] = "✅ Eklendi: $tarih / $cikma_tur → {$sira} palet (Kayıt #$rec_id)";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $log[] = "❌ Hata ($tarih / $cikma_tur): " . $e->getMessage();
    }
}

echo '<!doctype html><html><head><meta charset="utf-8"><title>İçe Aktarma</title>';
echo '<style>body{font-family:sans-serif;max-width:800px;margin:20px auto;padding:0 16px}
li{margin:4px 0}
.ok{color:#166534} .warn{color:#92400e} .err{color:#991b1b}
.summary{background:#f0f4ff;border-radius:8px;padding:12px 16px;margin-bottom:16px}
</style></head><body>';
echo '<h2>⚖️ Çıkma Verisi İçe Aktarma — Sonuç</h2>';
echo '<div class="summary">';
echo "<strong>Oluşturulan kayıt:</strong> $created_records &nbsp;|&nbsp; ";
echo "<strong>Oluşturulan palet:</strong> $created_pallets &nbsp;|&nbsp; ";
echo "<strong>Atlanan grup:</strong> $skipped";
echo '</div>';
echo '<ul>';
foreach ($log as $line) {
    $cls = str_starts_with($line, '✅') ? 'ok' : (str_starts_with($line, '⚠') ? 'warn' : 'err');
    echo '<li class="'.$cls.'">'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</li>';
}
echo '</ul>';
echo '<p><strong>Tamamlandı.</strong> Bu dosyayı (import_cikmalar.php) sunucudan silin.</p>';
echo '<p><a href="cikmalar.php">→ Çıkmalar listesine git</a></p>';
echo '</body></html>';
