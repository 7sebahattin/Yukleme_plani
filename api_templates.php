<?php
// =========================================================
// api_templates.php
// Malzeme şablon CRUD
// GET              → şablon listesi (items dahil)
// POST action=save → yeni şablon oluştur
// POST action=delete → şablon sil
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/calc.php';   // parse_decimal() — num() '.'yi binlik sanar (0.5→5 bug)
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('defs.read');

header('Content-Type: application/json; charset=utf-8');

// Tablo yoksa oluştur
db()->exec("CREATE TABLE IF NOT EXISTS material_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db()->exec("CREATE TABLE IF NOT EXISTS material_template_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    material_id INT NOT NULL,
    quantity    DECIMAL(10,3) NOT NULL DEFAULT 1,
    CONSTRAINT fk_mti_tpl FOREIGN KEY (template_id)
        REFERENCES material_templates(id) ON DELETE CASCADE,
    CONSTRAINT fk_mti_mat FOREIGN KEY (material_id)
        REFERENCES material_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* ── GET: liste ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $templates = db()->query("SELECT id, name FROM material_templates ORDER BY name")->fetchAll();
    foreach ($templates as &$t) {
        $st = db()->prepare(
            "SELECT ti.material_id, ti.quantity, m.name AS material_name, m.unit_dara_kg
               FROM material_template_items ti
               JOIN material_definitions m ON m.id = ti.material_id
              WHERE ti.template_id = ?
              ORDER BY ti.id"
        );
        $st->execute([$t['id']]);
        $t['items'] = $st->fetchAll();
    }
    echo json_encode($templates);
    exit;
}

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    csrf_check($body['csrf'] ?? null);
    require_perm('records.write');   // şablon oluştur/güncelle/sil — viewer yapamaz
    $action = $body['action'] ?? '';

    /* Kaydet */
    if ($action === 'save') {
        $name  = trim($body['name'] ?? '');
        $items = [];
        foreach ((array)($body['items'] ?? []) as $it) {
            $mid = (int)($it['material_id'] ?? 0);
            $qty = parse_decimal($it['quantity'] ?? 1);
            if ($mid > 0 && $qty > 0) $items[] = ['material_id' => $mid, 'quantity' => $qty];
        }

        if (!$name)        { echo json_encode(['error' => 'Şablon adı gerekli']); exit; }
        if (empty($items)) { echo json_encode(['error' => 'En az bir malzeme ekleyin']); exit; }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO material_templates (name) VALUES (?)")->execute([$name]);
            $tid = (int)$pdo->lastInsertId();
            $st  = $pdo->prepare(
                "INSERT INTO material_template_items (template_id, material_id, quantity) VALUES (?,?,?)"
            );
            foreach ($items as $item) {
                $st->execute([$tid, $item['material_id'], $item['quantity']]);
            }
            $pdo->commit();
            audit_log_event('create', 'material_templates', $tid, null, ['name' => $name, 'items' => count($items)]);
            echo json_encode(['ok' => true, 'id' => $tid]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /* Güncelle */
    if ($action === 'update') {
        $id    = (int)($body['id'] ?? 0);
        $name  = trim($body['name'] ?? '');
        $items = [];
        foreach ((array)($body['items'] ?? []) as $it) {
            $mid = (int)($it['material_id'] ?? 0);
            $qty = parse_decimal($it['quantity'] ?? 1);
            if ($mid > 0 && $qty > 0) $items[] = ['material_id' => $mid, 'quantity' => $qty];
        }
        if ($id <= 0)      { echo json_encode(['error' => 'Geçersiz ID']); exit; }
        if (!$name)        { echo json_encode(['error' => 'Şablon adı gerekli']); exit; }
        if (empty($items)) { echo json_encode(['error' => 'En az bir malzeme ekleyin']); exit; }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE material_templates SET name=? WHERE id=?")->execute([$name, $id]);
            $pdo->prepare("DELETE FROM material_template_items WHERE template_id=?")->execute([$id]);
            $st = $pdo->prepare(
                "INSERT INTO material_template_items (template_id, material_id, quantity) VALUES (?,?,?)"
            );
            foreach ($items as $item) {
                $st->execute([$id, $item['material_id'], $item['quantity']]);
            }
            $pdo->commit();
            audit_log_event('update', 'material_templates', $id, null, ['name' => $name, 'items' => count($items)]);
            echo json_encode(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    /* Sil */
    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['error' => 'Geçersiz ID']); exit; }
        $_tname = db()->prepare("SELECT name FROM material_templates WHERE id=?");
        $_tname->execute([$id]);
        $_tn = $_tname->fetchColumn();
        db()->prepare("DELETE FROM material_templates WHERE id=?")->execute([$id]);
        audit_log_event('delete', 'material_templates', $id, $_tn !== false ? ['name' => $_tn] : null, null);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Geçersiz işlem']);
}
