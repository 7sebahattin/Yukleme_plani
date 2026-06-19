<?php
declare(strict_types=1);
// HKS veritabanı erişim katmanı — tüm HKS tabloları burada yönetilir

class HksRepository {

    public function __construct(private readonly PDO $pdo) {}

    // ── Firmalar / Ayarlar ───────────────────────────────────

    private function sessionCompanyId(): ?int {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['hks_company_id'])) {
            return (int)$_SESSION['hks_company_id'];
        }
        return null;
    }

    // Tüm firmaları listele (seçim ekranı ve yönetim için)
    public function getAllCompanies(): array {
        try {
            return $this->pdo->query(
                "SELECT id, firma_adi, environment, username, last_test_at, last_test_ok, updated_at
                 FROM hks_settings ORDER BY id ASC"
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    // Seçili firma (session) veya belirtilen ID'nin ayarlarını döndür
    public function getSettings(?int $company_id = null): ?array {
        $id = $company_id ?? $this->sessionCompanyId();
        try {
            if ($id !== null) {
                $st = $this->pdo->prepare("SELECT * FROM hks_settings WHERE id=? LIMIT 1");
                $st->execute([$id]);
                return $st->fetch() ?: null;
            }
            // Hiç firma seçilmemişse ilk satırı döndür (backward compat)
            $row = $this->pdo->query("SELECT * FROM hks_settings ORDER BY id ASC LIMIT 1")->fetch();
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    // Yeni firma oluştur; ID'yi döndürür
    public function addCompany(string $firma_adi): int {
        $this->ensureSettingsColumns();
        $name = trim($firma_adi) !== '' ? trim($firma_adi) : 'Yeni Firma';
        $this->pdo->prepare(
            "INSERT INTO hks_settings (firma_adi, environment, username, password_enc, service_password_enc)
             VALUES (?, 'test', '', '', '')"
        )->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    // Firma adını güncelle
    public function renameCompany(int $id, string $firma_adi): void {
        $this->pdo->prepare("UPDATE hks_settings SET firma_adi=? WHERE id=?")
                  ->execute([trim($firma_adi) ?: 'Firma', $id]);
    }

    // Firmayı sil
    public function deleteCompany(int $id): void {
        $this->pdo->prepare("DELETE FROM hks_settings WHERE id=?")->execute([$id]);
    }

    private function ensureSettingsColumns(): void {
        static $done = false;
        if ($done) return;
        $done = true;
        foreach ([
            "ALTER TABLE `hks_settings` ADD COLUMN `firma_adi`         VARCHAR(200) NOT NULL DEFAULT 'Firma 1' AFTER `id`",
            "ALTER TABLE `hks_settings` ADD COLUMN `sender_name`       VARCHAR(200) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_depo`      VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_il`        VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `default_ilce`      VARCHAR(100) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `timeout_seconds`   INT NOT NULL DEFAULT 30",
            "ALTER TABLE `hks_settings` ADD COLUMN `live_send_enabled` TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE `hks_settings` ADD COLUMN `genel_wsdl_url`    VARCHAR(500) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_settings` ADD COLUMN `bildirim_wsdl_url` VARCHAR(500) NOT NULL DEFAULT ''",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_at`      DATETIME NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_ok`      TINYINT(1) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `last_test_message` VARCHAR(500) NULL",
            "ALTER TABLE `hks_settings` ADD COLUMN `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ] as $_sql) {
            try { $this->pdo->exec($_sql); } catch (PDOException) { /* already exists */ }
        }
    }

    public function saveSettings(array $data, ?int $company_id = null): void {
        $this->ensureSettingsColumns();
        $id = $company_id ?? $this->sessionCompanyId();
        // Hangi satırı güncelleyeceğimizi belirle
        if ($id !== null) {
            try {
                $st = $this->pdo->prepare("SELECT id FROM hks_settings WHERE id=? LIMIT 1");
                $st->execute([$id]);
                $existing_id = $st->fetchColumn() ?: null;
            } catch (Throwable) { $existing_id = null; }
        } else {
            $first = $this->getSettings();
            $existing_id = $first ? $first['id'] : null;
            $id = $existing_id;
        }

        if ($existing_id) {
            $this->pdo->prepare(
                "UPDATE hks_settings SET
                    firma_adi=?,
                    environment=?, username=?, password_enc=?,
                    service_password_enc=?, security_word_enc=?,
                    sender_name=?, default_depo=?, default_il=?, default_ilce=?,
                    timeout_seconds=?, live_send_enabled=?,
                    genel_wsdl_url=?, bildirim_wsdl_url=?,
                    updated_at=NOW()
                 WHERE id=?"
            )->execute([
                $data['firma_adi'] ?? 'Firma 1',
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
                $existing_id,
            ]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO hks_settings
                    (firma_adi, environment, username, password_enc, service_password_enc,
                     security_word_enc, sender_name, default_depo, default_il, default_ilce,
                     timeout_seconds, live_send_enabled, genel_wsdl_url, bildirim_wsdl_url)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $data['firma_adi'] ?? 'Firma 1',
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
            $new_id = (int)$this->pdo->lastInsertId();
            if (session_status() === PHP_SESSION_ACTIVE && $company_id === null) {
                $_SESSION['hks_company_id'] = $new_id;
            }
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
            $out = ['draft'=>0,'ready'=>0,'checked'=>0,'send_pending'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
            foreach ($rows as $r) {
                if (isset($out[$r['status']])) $out[$r['status']] = (int)$r['cnt'];
            }
            return $out;
        } catch (Throwable) {
            return ['draft'=>0,'ready'=>0,'checked'=>0,'send_pending'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0];
        }
    }

    // Mükerrer uyarısı olan kayıt sayısı — gönderilmemiş ama içeriği bir 'sent' ile eşleşen
    public function countDuplicateWarnings(): int {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM hks_notifications a
                 WHERE a.status IN ('draft','ready','checked')
                 AND EXISTS (
                     SELECT 1 FROM hks_notifications b
                     WHERE b.status='sent' AND b.id<>a.id
                       AND b.firma=a.firma AND b.urun=a.urun AND b.miktar=a.miktar
                       AND COALESCE(b.belge_no,'')=COALESCE(a.belge_no,'')
                 )"
            )->fetchColumn();
        } catch (Throwable) {
            return 0;
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
                (local_no, source_type, source_id, direction, notification_type, sifat,
                 firma, urun, urun_cinsi, miktar, birim, depo, il, ilce, belde,
                 uretici_ad, uretici_tc_vkn, alici_ad, alici_tc_vkn,
                 sevk_tarihi, arac_plaka, belge_no, reference_kunye_no,
                 status, created_by, created_at, updated_at)
             VALUES
                (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,NOW(),NOW())"
        )->execute([
            $this->nextLocalNo(),
            $data['source_type'] ?? null,
            $data['source_id'] ?? null,
            $data['direction'] ?? null,
            $data['notification_type'] ?? null,
            $data['sifat'] ?? null,
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
                notification_type=?, sifat=?, direction=?, firma=?, urun=?, urun_cinsi=?,
                miktar=?, birim=?, depo=?, il=?, ilce=?, belde=?,
                uretici_ad=?, uretici_tc_vkn=?, alici_ad=?, alici_tc_vkn=?,
                sevk_tarihi=?, arac_plaka=?, belge_no=?, reference_kunye_no=?,
                validation_errors_json=?, status=?, updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','failed','checked')"
        )->execute([
            $data['notification_type'] ?? null,
            $data['sifat'] ?? null,
            $data['direction'] ?? null,
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
        // Sent ve send_pending hariç tüm durumlar iptal edilebilir
        $this->pdo->prepare(
            "UPDATE hks_notifications SET status='cancelled', updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','checked','failed')"
        )->execute([$id]);
    }

    // ── HKS-Safety-01: Kontrol akışı ─────────────────────────

    // Kaydı "kontrol edildi" yap — yalnızca düzenlenebilir durumdan ve hata yokken
    public function markChecked(int $id, ?int $userId): bool {
        $st = $this->pdo->prepare(
            "UPDATE hks_notifications
                SET status='checked', validation_errors_json=NULL,
                    checked_at=NOW(), checked_by=?, updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','failed')"
        );
        $st->execute([$userId, $id]);
        return $st->rowCount() > 0;
    }

    // Doğrulama hatalarını kaydet, durumu draft/ready olarak ayarla
    public function setValidation(int $id, array $errors): void {
        $status = empty($errors) ? 'ready' : 'draft';
        $this->pdo->prepare(
            "UPDATE hks_notifications
                SET validation_errors_json=?, status=?, updated_at=NOW()
             WHERE id=? AND status IN ('draft','ready','failed','checked')"
        )->execute([
            $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
            $status, $id,
        ]);
    }

    // Aynı kaynak (source_type+source_id) için gönderilmiş kayıt var mı?
    public function findSameSourceSent(?string $sourceType, ?int $sourceId, int $excludeId = 0): ?array {
        if (!$sourceType || !$sourceId) return null;
        $st = $this->pdo->prepare(
            "SELECT id, local_no FROM hks_notifications
             WHERE source_type=? AND source_id=? AND status='sent' AND id<>? LIMIT 1"
        );
        $st->execute([$sourceType, $sourceId, $excludeId]);
        return $st->fetch() ?: null;
    }

    // İçerik bazlı mükerrer: aynı belge_no+firma+urun+miktar+sevk_tarihi son N günde gönderilmiş mi?
    public function findContentDuplicateSent(array $n, int $excludeId = 0, int $days = 30): ?array {
        $st = $this->pdo->prepare(
            "SELECT id, local_no, sent_at FROM hks_notifications
             WHERE status='sent' AND id<>?
               AND firma=? AND urun=? AND miktar=?
               AND COALESCE(belge_no,'')=? AND COALESCE(sevk_tarihi,'0000-00-00')=?
               AND sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 1"
        );
        $st->execute([
            $excludeId,
            $n['firma'] ?? '',
            $n['urun'] ?? '',
            (float)($n['miktar'] ?? 0),
            (string)($n['belge_no'] ?? ''),
            (string)($n['sevk_tarihi'] ?? '0000-00-00'),
            $days,
        ]);
        return $st->fetch() ?: null;
    }

    // Gönderim için kaydı kilitle — transaction + FOR UPDATE + mükerrer engeli
    // Başarılıysa status=send_pending yapılır. Dönen: ['ok'=>bool,'reason'=>?,'notification'=>?]
    public function lockForSend(int $id): array {
        try {
            $this->pdo->beginTransaction();
            $st = $this->pdo->prepare("SELECT * FROM hks_notifications WHERE id=? FOR UPDATE");
            $st->execute([$id]);
            $n = $st->fetch();

            if (!$n) {
                $this->pdo->rollBack();
                return ['ok' => false, 'reason' => 'Kayıt bulunamadı.'];
            }
            if ($n['status'] !== 'checked') {
                $this->pdo->rollBack();
                return ['ok' => false, 'reason' => 'Kayıt "Kontrol Edildi" durumunda değil (mevcut: ' . $n['status'] . ').', 'notification' => $n];
            }
            if (!empty($n['hks_bildirim_no']) || !empty($n['hks_kunye_no'])) {
                $this->pdo->rollBack();
                return ['ok' => false, 'reason' => 'Bu kayıt zaten HKS numarası almış. Tekrar gönderilemez.', 'notification' => $n, 'duplicate' => true];
            }
            $sameSource = $this->findSameSourceSent($n['source_type'] ?? null, isset($n['source_id']) ? (int)$n['source_id'] : null, $id);
            if ($sameSource) {
                $this->pdo->rollBack();
                return ['ok' => false, 'reason' => 'Aynı kaynak kayıt daha önce gönderilmiş (' . $sameSource['local_no'] . ').', 'notification' => $n, 'duplicate' => true];
            }

            $this->pdo->prepare(
                "UPDATE hks_notifications
                    SET status='send_pending', send_attempt_count=send_attempt_count+1, updated_at=NOW()
                 WHERE id=?"
            )->execute([$id]);
            $this->pdo->commit();
            return ['ok' => true, 'notification' => $n];

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'reason' => 'Kilitleme hatası: ' . $e->getMessage()];
        }
    }

    // Gönderim başarılı — sent yap
    public function finalizeSent(int $id, ?string $hksBildirimNo, ?string $hksKunyeNo, ?string $responseJson): void {
        $this->pdo->prepare(
            "UPDATE hks_notifications
                SET status='sent', hks_bildirim_no=?, hks_kunye_no=?,
                    response_json=?, last_error=NULL, sent_at=NOW(), updated_at=NOW()
             WHERE id=? AND status='send_pending'"
        )->execute([$hksBildirimNo, $hksKunyeNo, $responseJson, $id]);
    }

    // Gönderim hatası — failed yap, HKS no boş kalır, otomatik tekrar YOK
    public function markFailed(int $id, string $error, ?string $responseJson): void {
        $this->pdo->prepare(
            "UPDATE hks_notifications
                SET status='failed', last_error=?, response_json=?, updated_at=NOW()
             WHERE id=? AND status='send_pending'"
        )->execute([$error, $responseJson, $id]);
    }

    // Gönderim yapılamadı (servis eşlenmedi vb.) — kilidi geri checked'e al
    public function revertToChecked(int $id): void {
        $this->pdo->prepare(
            "UPDATE hks_notifications SET status='checked', updated_at=NOW()
             WHERE id=? AND status='send_pending'"
        )->execute([$id]);
    }

    public function userName(?int $id): string {
        if (!$id) return '';
        try {
            $st = $this->pdo->prepare("SELECT username FROM users WHERE id=?");
            $st->execute([$id]);
            return (string)($st->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
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
