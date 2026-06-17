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

// ── GET: Bu kayda eklenmiş malzemeleri malzeme bazlı grupla ────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $record_id = (int)($_GET['record_id'] ?? 0);
    if ($record_id <= 0) { echo json_encode(['ok' => false, 'error' => 'Geçersiz kayıt']); exit; }
    $ck = db()->prepare("SELECT id FROM loading_records WHERE id=?");
    $ck->execute([$record_id]);
    if (!$ck->fetch()) { echo json_encode(['ok' => false, 'error' => 'Kayıt bulunamadı']); exit; }
    $st = db()->prepare("
        SELECT pm.id AS pm_id, pm.loading_pallet_id, pm.material_id, pm.quantity,
               m.name AS material_name, lp.palet_no
        FROM pallet_materials pm
        JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
        JOIN material_definitions m ON m.id = pm.material_id
        WHERE lp.loading_record_id = ?
        ORDER BY m.name, CAST(lp.palet_no AS UNSIGNED), lp.id
    ");
    $st->execute([$record_id]);
    $rows = $st->fetchAll();
    $groups = [];
    foreach ($rows as $row) {
        $mid = (int)$row['material_id'];
        if (!isset($groups[$mid])) {
            $groups[$mid] = ['material_id' => $mid, 'material_name' => $row['material_name'],
                             'pallet_count' => 0, 'total_quantity' => 0, 'pallets' => []];
        }
        $groups[$mid]['pallet_count']++;
        $groups[$mid]['total_quantity'] = round($groups[$mid]['total_quantity'] + (float)$row['quantity'], 3);
        $groups[$mid]['pallets'][] = [
            'pm_id' => (int)$row['pm_id'], 'pallet_id' => (int)$row['loading_pallet_id'],
            'pallet_no' => $row['palet_no'], 'quantity' => (float)$row['quantity'],
        ];
    }
    echo json_encode(['ok' => true, 'groups' => array_values($groups)]);
    exit;
}

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

// ── Casus malzeme tespiti (büyük/küçük harf + Türkçe karakter bağımsız) ──
function is_casus_material(string $name): bool {
    $n = mb_strtolower(trim($name), 'UTF-8');
    $n = str_replace("\xCC\x87", '', $n);   // Türkçe İ sonrası combining dot
    $n = strtr($n, ['ı'=>'i','ş'=>'s','ç'=>'c','ğ'=>'g','ü'=>'u','ö'=>'o']);
    return str_contains($n, 'casus');
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

// ── Malzeme bazlı tüm paletlerden sil ──────────────────────────────
if (($body['action'] ?? '') === 'delete_material_all_pallets') {
    $material_id = (int)($body['material_id'] ?? 0);
    if ($material_id <= 0 || $record_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Geçersiz parametre']); exit;
    }
    $lr = db()->prepare("SELECT locked_at, durum FROM loading_records WHERE id=?");
    $lr->execute([$record_id]);
    $lrow = $lr->fetch();
    if (!$lrow) { echo json_encode(['ok' => false, 'error' => 'Kayıt bulunamadı']); exit; }
    $locked = !empty($lrow['locked_at']) || (($lrow['durum'] ?? '') === 'yuklendi');
    if ($locked && !can('records.unlock')) {
        echo json_encode(['ok' => false, 'error' => 'Kayıt kilitli; malzeme değişikliği yapılamaz.']); exit;
    }
    $pdo = db();
    $sel = $pdo->prepare("
        SELECT pm.id, pm.loading_pallet_id, m.name AS material_name
        FROM pallet_materials pm
        JOIN loading_pallets lp ON lp.id = pm.loading_pallet_id
        LEFT JOIN material_definitions m ON m.id = pm.material_id
        WHERE lp.loading_record_id = ? AND pm.material_id = ?
    ");
    $sel->execute([$record_id, $material_id]);
    $rows = $sel->fetchAll();
    if (empty($rows)) { echo json_encode(['ok' => true, 'deleted' => 0]); exit; }
    $del_ids   = array_map('intval', array_column($rows, 'id'));
    $pallets_a = array_values(array_unique(array_map('intval', array_column($rows, 'loading_pallet_id'))));
    $mat_name  = $rows[0]['material_name'] ?? '';
    $pdo->beginTransaction();
    try {
        $ph3 = implode(',', array_fill(0, count($del_ids), '?'));
        $pdo->prepare("DELETE FROM pallet_materials WHERE id IN ($ph3)")->execute($del_ids);
        foreach ($pallets_a as $pid) { ms_recompute_pallet($pdo, $pid); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
    }
    sync_malzeme_kullanim($record_id);
    audit_log_event('delete_material_all_pallets', 'records', $record_id, null, [
        'material_id' => $material_id, 'material_name' => $mat_name, 'silinen_satir' => count($del_ids),
    ]);
    echo json_encode(['ok' => true, 'deleted' => count($del_ids)]);
    exit;
}

// Eski (tekli) format → yeni array formatına çevir
if (!empty($body['material_id'])) {
    $materials_input = [['material_id' => (int)$body['material_id'], 'quantity' => parse_decimal($body['quantity'] ?? 1)]];
} else {
    $materials_input = [];
    foreach ((array)($body['materials'] ?? []) as $m) {
        $mid = (int)($m['material_id'] ?? 0);
        $qty = parse_decimal($m['quantity'] ?? 1);
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

// Sadece bu kayda ait geçerli paletleri al
$place2    = implode(',', array_fill(0, count($pallet_ids), '?'));
$st        = db()->prepare("SELECT id FROM loading_pallets WHERE id IN ($place2) AND loading_record_id=?");
$st->execute(array_merge($pallet_ids, [$record_id]));
$valid_ids = array_column($st->fetchAll(), 'id');

if (empty($valid_ids)) {
    echo json_encode(['error' => 'Bu kayda ait geçerli palet bulunamadı']);
    exit;
}

$pdo = db();

// Casus kuralı: kayıttaki ilk paleti bul (sira_no, id sırasıyla)
$st_first = $pdo->prepare("SELECT id FROM loading_pallets WHERE loading_record_id=? ORDER BY sira_no, id LIMIT 1");
$st_first->execute([$record_id]);
$first_pallet_id = (int)($st_first->fetchColumn() ?: 0);

// Malzeme adlarını önbelleğe al (Casus tespiti için)
$mat_names_cache = [];
foreach ($mats_db as $mid_k => $mdef) {
    $mat_names_cache[$mid_k] = $mdef['name'] ?? '';
}

$pdo->beginTransaction();

try {
    foreach ($valid_ids as $pid) {
        foreach ($materials_input as $m) {
            $mid     = $m['material_id'];

            // Casus sadece kayıttaki ilk palete eklenir
            if ($first_pallet_id > 0 && is_casus_material($mat_names_cache[$mid] ?? '') && $pid !== $first_pallet_id) {
                continue;
            }

            // Tek-çarpım kuralı: malzeme DB'ye HAM (girilen) adet olarak yazılır.
            // Kasa-bazlı çarpım (kasa_etiketi/şale/kenar kartonu vb.) görüntü/stok
            // hesabında material_calc_basis ile bir kez yapılır → ekle/düzenle balon yapmaz.
            $qty = $m['quantity'];

            $st_chk = $pdo->prepare(
                "SELECT id, quantity FROM pallet_materials WHERE loading_pallet_id=? AND material_id=?"
            );
            $st_chk->execute([$pid, $mid]);
            $existing = $st_chk->fetch();

            // Sarf/giydirme malzeme darası HİÇBİR yere girmez → total_dara_kg her zaman 0
            if ($existing) {
                $new_qty = round(parse_decimal($existing['quantity']) + $qty, 3);   // DB "90.000" → 90
                $pdo->prepare("UPDATE pallet_materials SET quantity=?, total_dara_kg=0 WHERE id=?")
                    ->execute([$new_qty, (int)$existing['id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO pallet_materials (loading_pallet_id, material_id, quantity, total_dara_kg)
                     VALUES (?,?,?,0)"
                )->execute([$pid, $mid, $qty]);
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
