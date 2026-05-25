<?php
// hks/HksRepository.php — Veritabanı erişim katmanı (v2 — künye bazlı mimari)
declare(strict_types=1);

class HksRepository {

    public function __construct(private readonly PDO $pdo) {}

    // ── Settings ─────────────────────────────────────────────
    public function getSettings(): ?array {
        $row = $this->pdo->query("SELECT * FROM hks_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch();
        return $row ?: null;
    }

    public function saveSettings(array $data): void {
        $existing = $this->getSettings();
        if ($existing) {
            $this->pdo->prepare(
                "UPDATE hks_settings SET username=?, password_enc=?, service_password_enc=?,
                 security_word_enc=?, environment=?, genel_wsdl_url=?, bildirim_wsdl_url=?
                 WHERE id=?"
            )->execute([
                $data['username'], $data['password_enc'], $data['service_password_enc'],
                $data['security_word_enc'] ?: null, $data['environment'],
                $data['genel_wsdl_url'], $data['bildirim_wsdl_url'], $existing['id'],
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO hks_settings (username, password_enc, service_password_enc,
                 security_word_enc, environment, genel_wsdl_url, bildirim_wsdl_url, is_active)
                 VALUES (?,?,?,?,?,?,?,1)"
            )->execute([
                $data['username'], $data['password_enc'], $data['service_password_enc'],
                $data['security_word_enc'] ?: null, $data['environment'],
                $data['genel_wsdl_url'], $data['bildirim_wsdl_url'],
            ]);
        }
    }

    // ── Reference Stock ───────────────────────────────────────
    public function listStock(bool $only_available = false): array {
        $sql = "SELECT * FROM hks_reference_stock";
        if ($only_available) $sql .= " WHERE remaining_quantity > 0";
        $sql .= " ORDER BY id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getStock(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM hks_reference_stock WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function getStockByKunyeNo(string $kunye_no): ?array {
        $st = $this->pdo->prepare("SELECT * FROM hks_reference_stock WHERE kunye_no=?");
        $st->execute([$kunye_no]);
        return $st->fetch() ?: null;
    }

    public function createStock(array $data): int {
        $qty = (float)$data['incoming_quantity'];
        $this->pdo->prepare(
            "INSERT INTO hks_reference_stock
             (kunye_no, product_name, incoming_quantity, used_quantity, remaining_quantity,
              unit, source_notification_type, supplier_name, header_id)
             VALUES (?,?,?,0,?,?,?,?,?)"
        )->execute([
            $data['kunye_no'], $data['product_name'], $qty, $qty,
            $data['unit'] ?? 'KG',
            $data['source_notification_type'] ?? 'alis',
            $data['supplier_name'] ?: null,
            $data['header_id'] ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deductStock(int $stock_id, float $qty): bool {
        $row = $this->getStock($stock_id);
        if (!$row || (float)$row['remaining_quantity'] < $qty) return false;
        $this->pdo->prepare(
            "UPDATE hks_reference_stock
             SET used_quantity = used_quantity + ?,
                 remaining_quantity = remaining_quantity - ?
             WHERE id=?"
        )->execute([$qty, $qty, $stock_id]);
        return true;
    }

    public function restoreStock(int $stock_id, float $qty): void {
        $this->pdo->prepare(
            "UPDATE hks_reference_stock
             SET used_quantity = GREATEST(0, used_quantity - ?),
                 remaining_quantity = remaining_quantity + ?
             WHERE id=?"
        )->execute([$qty, $qty, $stock_id]);
    }

    // ── Headers ───────────────────────────────────────────────
    public function listHeaders(int $limit = 200): array {
        return $this->pdo->query(
            "SELECT h.*, COUNT(i.id) AS item_count
             FROM hks_headers h
             LEFT JOIN hks_items i ON i.header_id = h.id
             GROUP BY h.id
             ORDER BY h.id DESC LIMIT $limit"
        )->fetchAll();
    }

    public function getHeader(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM hks_headers WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function createHeader(array $data): int {
        $this->pdo->prepare(
            "INSERT INTO hks_headers
             (type, buyer_name, vehicle_plate, driver_name, shipment_date,
              origin_place, destination_place, supplier_name, note, status)
             VALUES (?,?,?,?,?,?,?,?,?,'draft')"
        )->execute([
            $data['type'], $data['buyer_name'],
            $data['vehicle_plate'] ?: null, $data['driver_name'] ?: null,
            $data['shipment_date'],
            $data['origin_place'] ?: null, $data['destination_place'] ?: null,
            $data['supplier_name'] ?: null, $data['note'] ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateHeaderStatus(int $id, string $status, array $extra = []): void {
        $set = 'status=?';
        $params = [$status];
        foreach (['hks_notification_no','last_error','request_xml','response_xml','sent_at'] as $col) {
            if (array_key_exists($col, $extra)) {
                $set .= ", $col=?";
                $params[] = $extra[$col];
            }
        }
        $params[] = $id;
        $this->pdo->prepare("UPDATE hks_headers SET $set WHERE id=?")->execute($params);
    }

    // ── Items ─────────────────────────────────────────────────
    public function getItemsForHeader(int $header_id): array {
        $st = $this->pdo->prepare(
            "SELECT i.*, s.remaining_quantity AS stock_remaining
             FROM hks_items i
             LEFT JOIN hks_reference_stock s ON s.id = i.stock_id
             WHERE i.header_id=?
             ORDER BY i.id"
        );
        $st->execute([$header_id]);
        return $st->fetchAll();
    }

    public function createItem(array $data): int {
        $this->pdo->prepare(
            "INSERT INTO hks_items
             (header_id, reference_kunye_no, product_name, quantity, unit, unit_price, stock_id)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $data['header_id'], $data['reference_kunye_no'], $data['product_name'],
            $data['quantity'], $data['unit'] ?? 'KG',
            isset($data['unit_price']) ? (float)$data['unit_price'] : null,
            $data['stock_id'] ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteItemsForHeader(int $header_id): array {
        $items = $this->getItemsForHeader($header_id);
        $this->pdo->prepare("DELETE FROM hks_items WHERE header_id=?")->execute([$header_id]);
        return $items;
    }

    // ── Logs ─────────────────────────────────────────────────
    public function addLog(?int $headerId, string $action, string $message, ?string $req = null, ?string $res = null): void {
        $this->pdo->prepare(
            "INSERT INTO hks_logs (header_id, action, message, request_payload, response_payload)
             VALUES (?,?,?,?,?)"
        )->execute([$headerId, $action, $message, $req, $res]);
    }

    public function getLogsForHeader(int $id, int $limit = 20): array {
        $st = $this->pdo->prepare(
            "SELECT * FROM hks_logs WHERE header_id=? ORDER BY id DESC LIMIT $limit"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    }
}
