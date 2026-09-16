<?php
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_hakedis.php';
require_once __DIR__ . '/pdks_faz8b.php';

function pdks_faz8e_nedenler(): array
{
    return ['Kart kayıp', 'Kart bozuk', 'Kart okunamadı', 'Çıkış okutmayı unuttu', 'Diğer'];
}

function pdks_faz8e_manuel_cikis_kaydet(
    int $periodId,
    string $depo,
    string $cikisTarihi,
    string $cikisSaati,
    string $neden,
    string $aciklama,
    int $userId,
    ?PDO $pdo = null
): array {
    if (!pdks_hakedis_can('entitlements_finalize')) {
        return ['ok' => false, 'hata' => 'Manuel çıkış için muhasebe değerlendirme yetkisi gerekir.'];
    }
    if ($periodId < 1 || $userId < 1 || trim($depo) === '') {
        return ['ok' => false, 'hata' => 'Geçersiz dönem veya depo.'];
    }
    if (function_exists('active_depot') && active_depot() !== $depo) {
        return ['ok' => false, 'hata' => 'Mesai dönemi aktif depoya ait değil.'];
    }
    $neden = trim($neden);
    $aciklama = trim($aciklama);
    if (!in_array($neden, pdks_faz8e_nedenler(), true)) {
        return ['ok' => false, 'hata' => 'Geçerli bir neden seçin.'];
    }
    if ($neden === 'Diğer' && $aciklama === '') {
        return ['ok' => false, 'hata' => 'Diğer nedeni için açıklama zorunludur.'];
    }
    if (mb_strlen($aciklama, 'UTF-8') > 1000) {
        return ['ok' => false, 'hata' => 'Açıklama en fazla 1000 karakter olabilir.'];
    }
    $tam = $cikisTarihi . ' ' . $cikisSaati . ':00';
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $tam);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $cikisTarihi)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $cikisSaati)
        || !$dt || $dt->format('Y-m-d H:i:s') !== $tam) {
        return ['ok' => false, 'hata' => 'Geçerli bir çıkış tarihi ve saati girin.'];
    }

    $pdo = $pdo ?? db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'hata' => 'Manuel çıkış ayrı bir işlem olarak kaydedilmelidir.'];
    }
    try {
        $pdo->beginTransaction();
        // Scan çıkışı da aynı kart satırını kilitler; iki yol aynı dönemi kapatamaz.
        $st = $pdo->prepare('SELECT worker_card_id FROM daily_worker_work_periods WHERE id = ?');
        $st->execute([$periodId]);
        $cardId = (int)$st->fetchColumn();
        if (!$cardId) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı.'];
        }
        pdks_gunluk_faz8a_kart_kilitle($pdo, $cardId);
        $st = $pdo->prepare(
            "SELECT p.*, s.work_date, s.depo, w.card_no, w.canonical_uid
               FROM daily_worker_work_periods p
               JOIN daily_work_sessions s ON s.id = p.session_id
               JOIN worker_cards w ON w.id = p.worker_card_id
              WHERE p.id = ?" . $lock
        );
        $st->execute([$periodId]);
        $p = $st->fetch();
        if (!$p || (string)$p['depo'] !== $depo || (string)$p['depo_snapshot'] !== $depo) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Mesai dönemi seçili depoda bulunamadı.'];
        }
        if ($p['exit_time'] !== null || $p['exit_event_id'] !== null
            || !in_array($p['status'], ['open', 'legacy_unresolved'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Bu dönem zaten tamamlanmış.'];
        }
        $entry = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$p['entry_time']);
        if (!$entry || $entry->format('Y-m-d H:i:s') !== $p['entry_time']
            || (string)$p['work_date_snapshot'] !== (string)$p['work_date']
            || $dt < $entry || $dt > $entry->modify('+24 hours') || $dt > new DateTimeImmutable()) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Çıkış, girişten önce, gelecekte veya bu mesai gününün dışında olamaz.'];
        }
        $st = $pdo->prepare("SELECT status FROM foreman_daily_entitlements WHERE session_id = ?" . $lock);
        $st->execute([(int)$p['session_id']]);
        if ($st->fetchColumn() === 'final') {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Bu mesainin hakedişi KESİN. Önce muhasebe/yönetici tarafından yeniden açılmalıdır.'];
        }

        $simdi = date('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO daily_worker_card_events
                (session_id, worker_card_id, event_type, source, canonical_uid_snapshot,
                 worker_type_id_snapshot, worker_type_name_snapshot, work_date_snapshot, depo_snapshot,
                 recorded_by_user_id, server_event_time)
             VALUES (?, ?, 'CIKIS', 'manual', ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $p['session_id'], $cardId, $p['canonical_uid'], $p['worker_type_id_snapshot'],
            $p['worker_type_name_snapshot'], $p['work_date'], $depo, $userId, $tam,
        ]);
        $eventId = (int)$pdo->lastInsertId();
        $upd = $pdo->prepare(
            "UPDATE daily_worker_work_periods
                SET status = 'closed', exit_event_id = ?, exit_time = ?,
                    approved_attendance_class = NULL, approved_by_user_id = NULL, approved_at = NULL,
                    overtime_approved = NULL, overtime_approved_by_user_id = NULL, overtime_approved_at = NULL
              WHERE id = ? AND exit_time IS NULL AND exit_event_id IS NULL
                AND status IN ('open','legacy_unresolved')"
        );
        $upd->execute([$eventId, $tam, $periodId]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'hata' => 'Bu dönem başka bir işlemle tamamlanmış. Sayfayı yenileyin.'];
        }
        // Faz 8B'nin mevcut taslak yeniden hesaplama işareti.
        $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1 WHERE session_id = ? AND status = 'draft'")
            ->execute([(int)$p['session_id']]);
        // Mevcut İşlem Geçmişi tablosuna aynı transaction içinde yaz: denetim kaydı
        // başarısızsa manuel çıkış da geri alınır.
        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, 'manuel_cikis', 'daily_worker_work_periods', $periodId,
            json_encode(['entry_time' => $p['entry_time'], 'exit_time' => null, 'status' => $p['status']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode([
                'source' => 'manual', 'session_id' => (int)$p['session_id'], 'worker_card_id' => $cardId,
                'card_no' => $p['card_no'], 'entry_time' => $p['entry_time'], 'exit_time' => $tam,
                'exit_event_id' => $eventId, 'reason' => $neden, 'note' => $aciklama,
                'corrected_at' => $simdi, 'user_id' => $userId,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $pdo->commit();
        return ['ok' => true, 'period_id' => $periodId, 'exit_time' => $tam, 'event_id' => $eventId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_faz8e_manuel_cikis_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Manuel çıkış kaydedilemedi. Lütfen tekrar deneyin.'];
    }
}
