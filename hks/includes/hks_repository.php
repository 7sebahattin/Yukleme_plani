<?php
declare(strict_types=1);
// HKS veritabanı erişim katmanı — tüm HKS tabloları burada yönetilir

class HksRepository {

    public function __construct(private readonly PDO $pdo) {}

    // ── Ayarlar ──────────────────────────────────────────────

    public function getSettings(): ?array {
        try {
            $row = $this->pdo->query(
                "SELECT * FROM hks_settings ORDER BY id DESC LIMIT 1"
            )->fetch();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function saveSettings(array $data): void {
        $existing = $this->getSettings();
        if ($existing) {
            $this->pdo->prepare(
                "UPDATE hks_settings SET
                    environment=?, username=?, password_enc=?,
                    service_password_enc=?, security_word_enc=?,
                    sender_name=?, default_depo=?, default_il=?, default_ilce=?,
                    timeout_seconds=?, live_send_enabled=?,
                    genel_wsdl_url=?, bildirim_wsdl_url=?,
                    updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $data['environment'],
                $data['username'],
                $data['password_enc'],
                $data['service_password_enc'],
                $data['security_word_enc'] ?? null,
                $data['sender_name'] ?? null,
                $data['default_depo'] ?? null,
                $data['default_il'] ?? null,
                $data['default_ilce'] ?? null,
                (int)($data['timeout_seconds'] ?? 30),
                (int)($data['live_send_enabled'] ?? 0),
                $data['genel_wsdl_url'] ?? '',
                $data['bildirim_wsdl_url'] ?? '',
                $existing['id'],
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO hks_settings
                    (environment, username, password_enc, service_password_enc,
                     security_word_enc, sender_name, default_depo, default_il, default_ilce,
                     timeout_seconds, live_send_enabled, genel_wsdl_url, bildirim_wsdl_url)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $data['environment'],
                $data['username'],
                $data['password_enc'],
                $data['service_password_enc'],
                $data['security_word_enc'] ?? null,
                $data['sender_name'] ?? null,
                $data['default_depo'] ?? null,
                $data['default_il'] ?? null,
                $data['default_ilce'] ?? null,
                (int)($data['timeout_seconds'] ?? 30),
                (int)($data['live_send_enabled'] ?? 0),
                $data['genel_wsdl_url'] ?? '',
                $data['bildirim_wsdl_url'] ?? '',
            ]);
        }
    }

    public function updateTestResult(bool $ok, string $message): void {
        $existing = $this->getSettings();
        if (!$existing) return;
        $this->pdo->prepare(
            "UPDATE hks_settings SET last_test_at=NOW(), last_test_ok=?, last_test_message=? WHERE id=?"
        )->execute([(int)$ok, $message, $existing['id']]);
    }

    // ── Referans Cache ───────────────────────────────────────

    public function getReferenceStats(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT ref_type, COUNT(*) AS cnt, MAX(synced_at) AS last_sync
                 FROM hks_reference_cache GROUP BY ref_type"
            )->fetchAll();
            $stats = [];
            foreach ($rows as $r) {
                $stats[$r['ref_type']] = ['cnt' => $r['cnt'], 'last_sync' => $r['last_sync']];
            }
            return $stats;
        } catch (Throwable) {
            return [];
        }
    }

    public function getReferences(string $ref_type, ?string $parent_code = null): array {
        try {
            if ($parent_code !== null) {
                $st = $this->pdo->prepare(
                    "SELECT * FROM hks_reference_cache WHERE ref_type=? AND ref_parent_code=? AND is_active=1 ORDER BY ref_name"
                );
                $st->execute([$ref_type, $parent_code]);
            } else {
                $st = $this->pdo->prepare(
                    "SELECT * FROM hks_reference_cache WHERE ref_type=? AND is_active=1 ORDER BY ref_name"
                );
                $st->execute([$ref_type]);
            }
            return $st->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function upsertReference(string $ref_type, string $ref_code, string $ref_name, ?string $parent_code, ?string $raw_json): void {
        $this->pdo->prepare(
            "INSERT INTO hks_reference_cache (ref_type, ref_code, ref_name, ref_parent_code, raw_json, synced_at, is_active)
             VALUES (?,?,?,?,?,NOW(),1)
             ON DUPLICATE KEY UPDATE ref_name=VALUES(ref_name), ref_parent_code=VALUES(ref_parent_code),
                raw_json=VALUES(raw_json), synced_at=NOW(), is_active=1"
        )->execute([$ref_type, $ref_code, $ref_name, $parent_code, $raw_json]);
    }

    public function deactivateReferenceType(string $ref_type): void {
        $this->pdo->prepare(
            "UPDATE hks_reference_cache SET is_active=0 WHERE ref_type=?"
        )->execute([$ref_type]);
    }

    // ── Bildirimler ──────────────────────────────────────────

    public function listNotifications(array $filters = [], int $limit = 200, int $offset = 0): array {
        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['firma'])) {
            $where[] = 'firma LIKE ?';
            $params[] = '%' . $filters['firma'] . '%';
        }
        if (!empty($filters['urun'])) {
            $where[] = 'urun LIKE ?';
            $params[] = '%' . $filters['urun'] . '%';
        }
        $sql = "SELECT * FROM hks_notifications";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function countNotificationsByStatus(): array {
        try {
            $rows = $this->pdo->query(
                "SELECT status, COUNT(*) AS cnt FROM hks_notifications GROUP BY status"
            )->fetchAll();
            $out = ['draft'=>0,'ready'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
            foreach ($rows as $r) {
                if (isset($out[$r['status']])) $out[$r['status']] = (int)$r['cnt'];
            }
            return $out;
        } catch (Throwable) {
            return ['draft'=>0,'ready'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
        }
    }

    public function getNotification(int $id): ?array {
        $st = $this->pdo->prepare("SELECT * FROM hks_notifications WHERE id=?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function createNotification(array $data): int {
        $this->pdo->prepare(
            "INSERT INTO hks_notifications
                (local_no, source_type, source_id, direction, notification_type,
                 firma, urun, urun_cinsi, miktar, birim, depo, il, ilce, belde,
                 uretici_ad, uretici_tc_vkn, alici_ad, alici_tc_vkn,
                 sevk_tarihi, arac_plaka, belge_no, reference_kunye_no,
                 status, created_by, created_at, updated_at)
             VALUES
                (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,NOW(),NOW())"
        )->execute([
            $this->nextLocalNo(),
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            $data['direction'] ?? null,
            $data['notification_type'] ?? null,
            $data['firma'] ?? '',
            $data['urun'] ?? '',
            $data['urun_cinsi'] ?? null,
            (float)($data['miktar'] ?? 0),
            $data['birim'] ?? 'KG',
            $data['depo'] ?? null,
            $data['il'] ?? null,
            $data['ilce'] ?? null,
            $data['belde'] ?? null,
            $data['uretici_ad'] ?? null,
            $data['uretici_tc_vkn'] ?? null,
            $data['alici_ad'] ?? null,
            $data['alici_tc_vkn'] ?? null,
            $data['sevk_tarihi'] ?? null,
            $data['arac_plaka'] ?? null,
            $data['belge_no'] ?? null,
            $data['reference_kunye_no'] ?? null,
            $data['created_by'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateNotification(int $id, array $data): void {
        $errors = hks_validate_notification($data);
        $status = empty($errors) ? 'ready' : 'draft';

        $this->pdo->prepare(
            "UPDATE hks_notifications SET
                notification_type=?, firma=?, urun=?, urun_cinsi=?,
                miktar=?, birim=?, depo=?, il=?, ilce=?, belde=?,
                uretici_ad=?, uretici_tc_vkn=?, alici_ad=?, alici_tc_vkn=?,
                sevk_tarihi=?, arac_plaka=?, belge_no=?, reference_kunye_no=?,
                validation_errors_json=?, status=?, updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','failed')"
        )->execute([
            $data['notification_type'] ?? null,
            $data['firma'] ?? '',
            $data['urun'] ?? '',
            $data['urun_cinsi'] ?? null,
            (float)($data['miktar'] ?? 0),
            $data['birim'] ?? 'KG',
            $data['depo'] ?? null,
            $data['il'] ?? null,
            $data['ilce'] ?? null,
            $data['belde'] ?? null,
            $data['uretici_ad'] ?? null,
            $data['uretici_tc_vkn'] ?? null,
            $data['alici_ad'] ?? null,
            $data['alici_tc_vkn'] ?? null,
            $data['sevk_tarihi'] ?? null,
            $data['arac_plaka'] ?? null,
            $data['belge_no'] ?? null,
            $data['reference_kunye_no'] ?? null,
            $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
            $status,
            $id,
        ]);
    }

    public function updateNotificationStatus(int $id, string $status, array $extra = []): void {
        $set = 'status=?, updated_at=NOW()';
        $params = [$status];
        $allowed = ['hks_bildirim_no','hks_kunye_no','request_json','response_json','last_error','sent_at'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $extra)) {
                $set .= ", {$col}=?";
                $params[] = $extra[$col];
            }
        }
        $params[] = $id;
        $this->pdo->prepare("UPDATE hks_notifications SET {$set} WHERE id=?")->execute($params);
    }

    public function cancelNotification(int $id): void {
        $this->pdo->prepare(
            "UPDATE hks_notifications SET status='cancelled', updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','failed')"
        )->execute([$id]);
    }

    private function nextLocalNo(): string {
        try {
            $max = $this->pdo->query(
                "SELECT MAX(CAST(SUBSTRING(local_no,5) AS UNSIGNED)) FROM hks_notifications WHERE local_no LIKE 'HKS-%'"
            )->fetchColumn();
            return 'HKS-' . str_pad((string)(((int)$max) + 1), 6, '0', STR_PAD_LEFT);
        } catch (Throwable) {
            return 'HKS-' . date('YmdHis');
        }
    }

    // ── Bildirim Kalemleri ───────────────────────────────────

    public function getNotificationItems(int $notification_id): array {
        $st = $this->pdo->prepare(
            "SELECT * FROM hks_notification_items WHERE notification_id=? ORDER BY id"
        );
        $st->execute([$notification_id]);
        return $st->fetchAll();
    }

    // ── Stok ─────────────────────────────────────────────────

    public function listStock(array $filters = []): array {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['urun'])) {
            $where[] = 'urun LIKE ?';
            $params[] = '%' . $filters['urun'] . '%';
        }
        if (!empty($filters['depo'])) {
            $where[] = 'depo LIKE ?';
            $params[] = '%' . $filters['depo'] . '%';
        }
        if (!empty($filters['kunye'])) {
            $where[] = '(hks_kunye_no LIKE ? OR reference_kunye_no LIKE ?)';
            $params[] = '%' . $filters['kunye'] . '%';
            $params[] = '%' . $filters['kunye'] . '%';
        }
        $sql = "SELECT s.*, n.firma, n.status AS notif_status
                FROM hks_stock s
                LEFT JOIN hks_notifications n ON n.id = s.source_notification_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY s.updated_at DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function rebuildStock(): void {
        $this->pdo->exec("DELETE FROM hks_stock");
        $rows = $this->pdo->query(
            "SELECT * FROM hks_notifications WHERE status='sent' ORDER BY id"
        )->fetchAll();
        foreach ($rows as $n) {
            $key = md5(($n['depo'] ?? '') . '|' . ($n['urun'] ?? '') . '|' . ($n['hks_kunye_no'] ?? $n['reference_kunye_no'] ?? ''));
            $giris = ($n['direction'] ?? '') === 'cikis' ? 0 : (float)$n['miktar'];
            $cikis = ($n['direction'] ?? '') === 'cikis' ? (float)$n['miktar'] : 0;
            $this->pdo->prepare(
                "INSERT INTO hks_stock
                    (stock_key, urun, depo, hks_kunye_no, reference_kunye_no,
                     giris_miktar, cikis_miktar, kalan_miktar, birim, source_notification_id, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    giris_miktar=giris_miktar+VALUES(giris_miktar),
                    cikis_miktar=cikis_miktar+VALUES(cikis_miktar),
                    kalan_miktar=giris_miktar-cikis_miktar,
                    updated_at=NOW()"
            )->execute([
                $key,
                $n['urun'],
                $n['depo'] ?? '',
                $n['hks_kunye_no'] ?? null,
                $n['reference_kunye_no'] ?? null,
                $giris,
                $cikis,
                $giris - $cikis,
                $n['birim'] ?? 'KG',
                $n['id'],
            ]);
        }
    }

    // ── Sorgular ─────────────────────────────────────────────

    public function listQueries(int $limit = 100): array {
        try {
            return $this->pdo->query(
                "SELECT * FROM hks_queries ORDER BY id DESC LIMIT {$limit}"
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function saveQuery(string $query_type, string $query_value, string $result_status, ?string $result_json, ?int $created_by): int {
        $this->pdo->prepare(
            "INSERT INTO hks_queries (query_type, query_value, result_status, result_json, created_by, created_at)
             VALUES (?,?,?,?,?,NOW())"
        )->execute([$query_type, $query_value, $result_status, $result_json, $created_by]);
        return (int)$this->pdo->lastInsertId();
    }

    // ── Servis Logları ────────────────────────────────────────

    public function listServiceLogs(int $limit = 200): array {
        try {
            return $this->pdo->query(
                "SELECT * FROM hks_service_logs ORDER BY id DESC LIMIT {$limit}"
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function addServiceLog(
        string $service_name,
        string $method_name,
        string $environment,
        array  $request_data,
        ?array $response_data,
        bool   $is_success,
        ?string $error_code,
        ?string $error_message,
        int    $duration_ms,
        ?int   $created_by
    ): void {
        $safe_request  = hks_mask_json((string)json_encode($request_data, JSON_UNESCAPED_UNICODE));
        $response_json = $response_data ? (string)json_encode($response_data, JSON_UNESCAPED_UNICODE) : null;
        $this->pdo->prepare(
            "INSERT INTO hks_service_logs
                (service_name, method_name, environment, request_safe_json, response_json,
                 is_success, error_code, error_message, duration_ms, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $service_name, $method_name, $environment,
            $safe_request, $response_json,
            (int)$is_success, $error_code, $error_message,
            $duration_ms, $created_by,
        ]);
    }

    public function getRecentLogs(int $limit = 5): array {
        try {
            return $this->pdo->query(
                "SELECT * FROM hks_service_logs ORDER BY id DESC LIMIT {$limit}"
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }
}
