<?php
// hks/HksRepository.php — Veritabanı erişim katmanı
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
            $st = $this->pdo->prepare(
                "UPDATE hks_settings SET username=?, password_enc=?, service_password_enc=?,
                 security_word_enc=?, environment=?, genel_wsdl_url=?, bildirim_wsdl_url=?
                 WHERE id=?"
            );
            $st->execute([
                $data['username'], $data['password_enc'], $data['service_password_enc'],
                $data['security_word_enc'] ?: null, $data['environment'],
                $data['genel_wsdl_url'], $data['bildirim_wsdl_url'], $existing['id'],
            ]);
        } else {
            $st = $this->pdo->prepare(
                "INSERT INTO hks_settings (username, password_enc, service_password_enc,
                 security_word_enc, environment, genel_wsdl_url, bildirim_wsdl_url, is_active)
                 VALUES (?,?,?,?,?,?,?,1)"
            );
            $st->execute([
                $data['username'], $data['password_enc'], $data['service_password_enc'],
                $data['security_word_enc'] ?: null, $data['environment'],
                $data['genel_wsdl_url'], $data['bildirim_wsdl_url'],
            ]);
        }
    }

    // ── Notifications ─────────────────────────────────────────
    public function listNotifications(int $limit = 200): array {
        return $this->pdo->query(
            "SELECT * FROM hks_notifications ORDER BY id DESC LIMIT $limit"
        )->fetchAll();
    }

    public function getNotification(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM hks_notifications WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function createNotification(array $data): int {
        $st = $this->pdo->prepare(
            "INSERT INTO hks_notifications
             (notification_type, product_name, product_code, quantity, unit, package_type,
              supplier_name, buyer_name, shipment_date, vehicle_plate, driver_name,
              origin_place, destination_place, note, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')"
        );
        $st->execute([
            $data['notification_type'], $data['product_name'], $data['product_code'] ?: null,
            $data['quantity'], $data['unit'], $data['package_type'],
            $data['supplier_name'] ?: null, $data['buyer_name'] ?: null,
            $data['shipment_date'], $data['vehicle_plate'] ?: null,
            $data['driver_name'] ?: null, $data['origin_place'] ?: null,
            $data['destination_place'] ?: null, $data['note'] ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateNotification(int $id, array $data): void {
        $st = $this->pdo->prepare(
            "UPDATE hks_notifications SET
             notification_type=?, product_name=?, product_code=?, quantity=?, unit=?,
             package_type=?, supplier_name=?, buyer_name=?, shipment_date=?,
             vehicle_plate=?, driver_name=?, origin_place=?, destination_place=?, note=?
             WHERE id=?"
        );
        $st->execute([
            $data['notification_type'], $data['product_name'], $data['product_code'] ?: null,
            $data['quantity'], $data['unit'], $data['package_type'],
            $data['supplier_name'] ?: null, $data['buyer_name'] ?: null,
            $data['shipment_date'], $data['vehicle_plate'] ?: null,
            $data['driver_name'] ?: null, $data['origin_place'] ?: null,
            $data['destination_place'] ?: null, $data['note'] ?: null,
            $id,
        ]);
    }

    public function updateStatus(int $id, string $status, array $extra = []): void {
        $set = 'status=?';
        $params = [$status];
        foreach (['hks_notification_no','hks_tag_no','last_error','request_xml','response_xml','sent_at'] as $col) {
            if (array_key_exists($col, $extra)) {
                $set .= ", $col=?";
                $params[] = $extra[$col];
            }
        }
        $params[] = $id;
        $this->pdo->prepare("UPDATE hks_notifications SET $set WHERE id=?")->execute($params);
    }

    // ── Logs ─────────────────────────────────────────────────
    public function addLog(int|null $notificationId, string $action, string $message, ?string $req = null, ?string $res = null): void {
        $this->pdo->prepare(
            "INSERT INTO hks_logs (notification_id, action, message, request_payload, response_payload)
             VALUES (?,?,?,?,?)"
        )->execute([$notificationId, $action, $message, $req, $res]);
    }

    public function getLogsForNotification(int $id, int $limit = 20): array {
        $st = $this->pdo->prepare("SELECT * FROM hks_logs WHERE notification_id=? ORDER BY id DESC LIMIT $limit");
        $st->execute([$id]);
        return $st->fetchAll();
    }
}
