<?php
// =========================================================
// api_bulk_material.php
// Seçili paletlere toplu malzeme ekler, dara/net yeniden hesaplar.
// POST: { csrf, record_id, pallet_ids[], materials:[{material_id,quantity}] }
//   ya da eski format: { ..., material_id, quantity }
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('records.write');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
csrf_check($body['csrf'] ?? null);

$record_id  = (int)($body['record_id'] ?? 0);
$pallet_ids = array_values(array_unique(array_map('intval', (array)($body['pallet_ids'] ?? []))));

// Bir paletin dara/net değerlerini mevcut malzemelere göre yeniden hesaplar (dara = kasa+palet).
function ms_recompute_pallet(PDO $pdo, int $pid): void {
    $st_p = $pdo->prepare("SELECT * FROM loading_pallets WHERE id=?");
    $st_p->execute([$pid]);
    $pallet = $st_p->fetch();
    if (!$pallet) return;
    $st_m = $pdo->prepare("SELECT material_id, quantity FROM pallet_materials WHERE loading_pallet_id=?");
    $st_m->execute([$pid]);
    $mats = array_map(fn($mi) => ['material_id' => $mi['material_id'], 'quantity' => $mi['quantity']], $st_m->fetchAll());
    $c = compute_pallet_row([
        'palet_no' => $pallet['palet_no'], 'kasa_adeti' => $pallet['kasa_adeti'], 'size' => $pallet['size'],
        'brut_kg' => $pallet['brut_kg'], 'kasa_cinsi_id' => $pallet['kasa_cinsi_id'],
        'palet_tipi_id' => $pallet['palet_tipi_id'], 'urun_cinsi' => $pallet['urun_cinsi'],
        'depo' => $pallet['depo'], 'materials' => $mats,
    ]);
    $pdo->prepare("UPDATE loading_pallets SET dara_kg=?, net_kg=? WHERE id=?")
        ->execute([$c['dara_kg'], $c['net_kg'], $pid]);
}

// ── Toplu giydirme/sarf malzeme silme (seçili veya tümü) ─────────
if (in_array($body['action'] ?? '', ['delete_selected_materials', 'delete_all_extra_materials'], true)) {
    if ($record_id <= 0) { echo json_encode(['ok' => false, 'error' => 'Geçersiz kayıt']); exit; }

    // Kilit kontrolü — yüklendi/kilitli kayıtta yalnız records.unlock olanlar değiştirebilir
    $lr = db()->prepare("SELECT locked_at, durum FROM loading_records WHERE id=?");
    $lr->execute([$record_id]);
    $lrow = $lr->fetch();
    if (!$lrow) { echo json_encode(['ok' => false, 'error' => 'Kayıt bulunamadı']); exit; }
    $locked = !empty($lrow['locked_at']) || (($lrow['durum'] ?? '') === 'yuklendi');
    if ($locked && !can('records.unlock')) {
        echo json_encode(['ok' => false, 'error' => 'Kayıt kilitli; malzeme değişikliği yapılamaz.']); exit;
    }

    $pdo = db();
    // Yalnızca BU kayda ait paletlerin pallet_materials satırları (kasa/palet asla burada değil)
    if (($body['action']) === 'delete_selected_materials') {
        $pm_ids = array_values(array_unique(array_map('intval', (array)($body['pm_ids'] ?? []))));
        if (empty($pm_ids)) { echo json_encode(['ok' => false, 'error' => 'Silinecek malzeme seçilmedi']); exit; }
        $ph  = implode(',', array_fill(0, count($pm_ids), '?'));
        $sel = $pdo->prepare(
            "SELECT pm.id, pm.loading_pallet_id, pm.material_id, m.name AS material_name
             FROM pallet_materials pm
             JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
             LEFT JOIN material_definitions m ON m.id = pm.material_id
             WHERE lp.loading_record_id = ? AND pm.id IN ($ph)"
        );
        $sel->execute(array_merge([$record_id], $pm_ids));
    } else {
        $sel = $pdo->prepare(
            "SELECT pm.id, pm.loading_pallet_id, pm.material_id, m.name AS material_name
             FROM pallet_materials pm
             JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
             LEFT JOIN material_definitions m ON m.id = pm.material_id
             WHERE lp.loading_record_id = ?"
        );
        $sel->execute([$record_id]);
    }
    $rows = $sel->fetchAll();
    if (empty($rows)) { echo json_encode(['ok' => true, 'deleted' => 0]); exit; }

    $del_ids   = array_map('intval', array_column($rows, 'id'));
    $pallets_a = array_values(array_unique(array_map('intval', array_column($rows, 'loading_pallet_id'))));
    $names     = array_values(array_unique(array_filter(array_column($rows, 'material_name'))));

    $pdo->beginTransaction();
    try {
        $ph2 = implode(',', array_fill(0, count($del_ids), '?'));
        $pdo->prepare("DELETE FROM pallet_materials WHERE id IN ($ph2)")->execute($del_ids);
        foreach ($pallets_a as $pid) { ms_recompute_pallet($pdo, $pid); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }

    sync_malzeme_kullanim($record_id);   // stok hareketlerini güncelle
    audit_log_event('bulk_delete_materials', 'records', $record_id, null, [
        'silinen_satir' => count($del_ids),
        'malzemeler'    => implode(', ', array_slice($names, 0, 15)),
    ]);
    echo json_encode(['ok' => true, 'deleted' => count($del_ids)]);
    exit;
}

// ── Malzeme sil ──────────────────────────────────────────────────
if (($body['action'] ?? '') === 'delete') {
    $pm_id = (int)($body['pm_id'] ?? 0);
    if ($pm_id <= 0 || $record_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz parametre']); exit;
    }
    $st = db()->prepare(
        "SELECT pm.id, pm.loading_pallet_id FROM pallet_materials pm
         JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
         WHERE pm.id = ? AND lp.loading_record_id = ?"
    );
    $st->execute([$pm_id, $record_id]);
    $pm_row = $st->fetch();
    if (!$pm_row) {
        echo json_encode(['ok' => false, 'error' => 'Malzeme bulunamadı']); exit;
    }
    $pid = (int)$pm_row['loading_pallet_id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM pallet_materials WHERE id = ?")->execute([$pm_id]);
        $st_p = $pdo->prepare("SELECT * FROM loading_pallets WHERE id=?");
        $st_p->execute([$pid]);
        $pallet = $st_p->fetch();
        $st_m = $pdo->prepare("SELECT material_id, quantity FROM pallet_materials WHERE loading_pallet_id=?");
        $st_m->execute([$pid]);
        $mats = array_map(
            fn($mi) => ['material_id' => $mi['material_id'], 'quantity' => $mi['quantity']],
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
        $pdo->commit();
        sync_malzeme_kullanim($record_id);   // palet malzemesi silindi → stok hareketlerini güncelle
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Eski (tekli) format → yeni array formatına çevir
if (!empty($body['material_id'])) {
    $materials_input = [['material_id' => (int)$body['material_id'], 'quantity' => num($body['quantity'] ?? 1)]];
} else {
    $materials_input = [];
    foreach ((array)($body['materials'] ?? []) as $m) {
        $mid = (int)($m['material_id'] ?? 0);
        $qty = num($m['quantity'] ?? 1);
        if ($mid > 0 && $qty > 0) $materials_input[] = ['material_id' => $mid, 'quantity' => $qty];
    }
}

if ($record_id <= 0 || empty($materials_input) || empty($pallet_ids)) {
    echo json_encode(['error' => 'Eksik veya geçersiz parametre']);
    exit;
}

// Tüm malzemelerin aktif olduğunu doğrula
$mat_ids = array_values(array_unique(array_column($materials_input, 'material_id')));
$place   = implode(',', array_fill(0, count($mat_ids), '?'));
$st      = db()->prepare("SELECT id, unit_dara_kg, type FROM material_definitions WHERE id IN ($place) AND is_active=1");
$st->execute($mat_ids);
$mats_db = array_column($st->fetchAll(), null, 'id');

foreach ($materials_input as $m) {
    if (!isset($mats_db[$m['material_id']])) {
        echo json_encode(['error' => 'Malzeme bulunamadı: #' . $m['material_id']]);
        exit;
    }
}

// Sadece bu kayda ait paletleri işle (kasa adeti ile — etiket çarpımı için)
$place2    = implode(',', array_fill(0, count($pallet_ids), '?'));
$st        = db()->prepare("SELECT id, kasa_adeti FROM loading_pallets WHERE id IN ($place2) AND loading_record_id=?");
$st->execute(array_merge($pallet_ids, [$record_id]));
$valid_rows = $st->fetchAll();
$valid_ids  = array_column($valid_rows, 'id');
$kasa_by_pallet = array_column($valid_rows, 'kasa_adeti', 'id');

if (empty($valid_ids)) {
    echo json_encode(['error' => 'Bu kayda ait geçerli palet bulunamadı']);
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    foreach ($valid_ids as $pid) {
        foreach ($materials_input as $m) {
            $mid     = $m['material_id'];
            $unit_kg = num($mats_db[$mid]['unit_dara_kg']);
            // Etiket (kasa_etiketi) palete eklenince kasa adetiyle çarpılır:
            // 1 etiket × 90 kasa = 90 etiket. Diğer malzemeler girildiği gibi.
            $qty = $m['quantity'];
            if (($mats_db[$mid]['type'] ?? '') === 'kasa_etiketi') {
                $ka  = (int)($kasa_by_pallet[$pid] ?? 0);
                if ($ka > 0) $qty = round($m['quantity'] * $ka, 3);
            }

            $st_chk = $pdo->prepare(
                "SELECT id, quantity FROM pallet_materials WHERE loading_pallet_id=? AND material_id=?"
            );
            $st_chk->execute([$pid, $mid]);
            $existing = $st_chk->fetch();

            if ($existing) {
                $new_qty  = round(num($existing['quantity']) + $qty, 3);
                $new_dara = round($unit_kg * $new_qty, 3);
                $pdo->prepare("UPDATE pallet_materials SET quantity=?, total_dara_kg=? WHERE id=?")
                    ->execute([$new_qty, $new_dara, (int)$existing['id']]);
            } else {
                $dara = round($unit_kg * $qty, 3);
                $pdo->prepare(
                    "INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg)
                     VALUES (?,?,?,?)"
                )->execute([$pid, $mid, $qty, $dara]);
            }
        }

        // Dara/net yeniden hesapla
        $st_p = $pdo->prepare("SELECT * FROM loading_pallets WHERE id=?");
        $st_p->execute([$pid]);
        $pallet = $st_p->fetch();

        $st_m = $pdo->prepare("SELECT material_id, quantity FROM pallet_materials WHERE loading_pallet_id=?");
        $st_m->execute([$pid]);
        $mats = array_map(
            fn($mi) => ['material_id' => $mi['material_id'], 'quantity' => $mi['quantity']],
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
    sync_malzeme_kullanim($record_id);   // palet malzemesi eklendi → stok hareketlerini güncelle
    echo json_encode(['ok' => true, 'updated' => count($valid_ids)]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
