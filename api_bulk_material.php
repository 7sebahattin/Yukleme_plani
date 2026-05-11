<?php
// =========================================================
// api_bulk_material.php
// Seçili paletlere toplu malzeme ekler, dara/net yeniden hesaplar.
// POST: { csrf, record_id, material_id, quantity, pallet_ids[] }
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
csrf_check($body['csrf'] ?? null);

$record_id   = (int)($body['record_id']   ?? 0);
$material_id = (int)($body['material_id'] ?? 0);
$quantity    = num($body['quantity']    ?? 0);
$pallet_ids  = array_values(array_unique(array_map('intval', (array)($body['pallet_ids'] ?? []))));

if ($record_id <= 0 || $material_id <= 0 || $quantity <= 0 || empty($pallet_ids)) {
    echo json_encode(['error' => 'Eksik veya geçersiz parametre']);
    exit;
}

// Malzeme aktif mi?
$st = db()->prepare("SELECT id, unit_dara_kg FROM material_definitions WHERE id=? AND is_active=1");
$st->execute([$material_id]);
$mat = $st->fetch();
if (!$mat) {
    echo json_encode(['error' => 'Malzeme bulunamadı']);
    exit;
}

// Sadece bu kayda ait paletleri işle
$place     = implode(',', array_fill(0, count($pallet_ids), '?'));
$st        = db()->prepare("SELECT id FROM loading_pallets WHERE id IN ($place) AND loading_record_id=?");
$st->execute(array_merge($pallet_ids, [$record_id]));
$valid_ids = array_column($st->fetchAll(), 'id');

if (empty($valid_ids)) {
    echo json_encode(['error' => 'Bu kayda ait geçerli palet bulunamadı']);
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    $unit_kg = num($mat['unit_dara_kg']);

    foreach ($valid_ids as $pid) {
        // Malzeme zaten var mı? → üstüne ekle
        $st_chk = $pdo->prepare("SELECT id, quantity FROM pallet_materials WHERE loading_pallet_id=? AND material_id=?");
        $st_chk->execute([$pid, $material_id]);
        $existing = $st_chk->fetch();

        if ($existing) {
            $new_qty  = round(num($existing['quantity']) + $quantity, 3);
            $new_dara = round($unit_kg * $new_qty, 3);
            $pdo->prepare("UPDATE pallet_materials SET quantity=?, total_dara_kg=? WHERE id=?")
                ->execute([$new_qty, $new_dara, (int)$existing['id']]);
        } else {
            $dara = round($unit_kg * $quantity, 3);
            $pdo->prepare("INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg) VALUES (?,?,?,?)")
                ->execute([$pid, $material_id, $quantity, $dara]);
        }

        // Tüm malzemeleri yeniden al ve dara/net hesapla
        $st_p = $pdo->prepare("SELECT * FROM loading_pallets WHERE id=?");
        $st_p->execute([$pid]);
        $pallet = $st_p->fetch();

        $st_m = $pdo->prepare("SELECT material_id, quantity FROM pallet_materials WHERE loading_pallet_id=?");
        $st_m->execute([$pid]);
        $mats = array_map(
            fn($m) => ['material_id' => $m['material_id'], 'quantity' => $m['quantity']],
            $st_m->fetchAll()
        );

        $computed = compute_pallet_row([
            'palet_no'      => $pallet['palet_no'],
            'kasa_adeti'    => $pallet['kasa_adeti'],
            'size'          => $pallet['size'],
            'brut_kg'       => $pallet['brut_kg'],
            'kasa_cinsi_id' => $pallet['kasa_cinsi_id'],
            'palet_tipi_id' => $pallet['palet_tipi_id'],
            'urun_cinsi'    => $pallet['urun_cinsi'],
            'depo'          => $pallet['depo'],
            'materials'     => $mats,
        ]);

        $pdo->prepare("UPDATE loading_pallets SET dara_kg=?, net_kg=? WHERE id=?")
            ->execute([$computed['dara_kg'], $computed['net_kg'], $pid]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'updated' => count($valid_ids)]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
