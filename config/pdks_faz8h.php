<?php
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_faz8b.php';

/** Yalnız aynı gün/aktif depodaki kapalı oturumu, aynı kimlikle yeniden açar. */
function pdks_gunluk_oturum_yeniden_ac(int $sessionId, string $sebep, int $userId, ?PDO $pdo = null): array
{
    if (!function_exists('is_admin') || !is_admin()
        || $userId < 1
        || (function_exists('current_user') && (int)(current_user()['id'] ?? 0) !== $userId)) {
        return ['ok' => false, 'kod' => 'yetkisiz', 'hata' => 'Mesaiyi yalnızca sistem yöneticisi yeniden açabilir.'];
    }
    $sebep = trim($sebep);
    if ($sebep === '' || mb_strlen($sebep, 'UTF-8') > 500) {
        return ['ok' => false, 'kod' => 'gerekce_gecersiz', 'hata' => 'Yeniden açma gerekçesi zorunludur (en fazla 500 karakter).'];
    }
    $depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
    if ($sessionId < 1 || $depo === '') {
        return ['ok' => false, 'kod' => 'gecersiz_istek', 'hata' => 'Geçerli bir mesai ve aktif depo seçin.'];
    }

    $pdo = $pdo ?? db();
    if ($pdo->inTransaction()) {
        return ['ok' => false, 'kod' => 'islem_devam_ediyor', 'hata' => 'Mesai yeniden açma ayrı bir işlem olmalıdır.'];
    }
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?' . $lock);
        $st->execute([$sessionId]);
        $oturum = $st->fetch();
        if (!$oturum) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
        }
        if ((string)$oturum['depo'] !== $depo) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'yanlis_depo', 'hata' => 'Bu mesai aktif depoya ait değil.'];
        }
        $bugun = date('Y-m-d');
        if ((string)$oturum['work_date'] !== $bugun) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'tarih_gecmis', 'hata' => 'Yalnız bugünün mesaisi yeniden açılabilir.'];
        }
        if ($oturum['status'] !== 'closed') {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'zaten_acik', 'hata' => 'Bu mesai zaten açık.'];
        }

        $st = $pdo->prepare('SELECT id, status FROM foreman_daily_entitlements WHERE session_id = ?' . $lock);
        $st->execute([$sessionId]);
        $ent = $st->fetch();
        if ($ent && $ent['status'] === 'final') {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'kesin_hakedis',
                'hata' => 'Bu mesai için kesinleşmiş hakediş bulunmaktadır. Önce hakedişi admin tarafından yeniden açın.',
                'entitlement_id' => (int)$ent['id']];
        }
        if ($ent && $ent['status'] !== 'draft') {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'hakedis_durumu', 'hata' => 'Hakediş durumu doğrulanamadı.'];
        }

        // v275: eksik çıkışla kapanan mesaideki kart artık başka mesaide yeniden
        // giriş yapabiliyor. O kart ŞU AN başka AÇIK mesaide içerideyse bu mesai
        // yeniden açılamaz — açılsaydı kartın iki canlı dönemi olurdu.
        $etkin = pdks_gunluk_faz8j_etkin_kosul($pdo, 'p');
        $etkin2 = pdks_gunluk_faz8j_etkin_kosul($pdo, 'p2');
        $st = $pdo->prepare(
            "SELECT w.card_no, s2.foreman_name_snapshot AS cavus
               FROM daily_worker_work_periods p
               JOIN worker_cards w ON w.id = p.worker_card_id
               JOIN daily_worker_work_periods p2 ON p2.worker_card_id = p.worker_card_id AND p2.id <> p.id
               JOIN daily_work_sessions s2 ON s2.id = p2.session_id
              WHERE p.session_id = ? AND p.status = 'open' AND $etkin
                AND p2.status = 'open' AND $etkin2 AND s2.status = 'open'
              LIMIT 1"
        );
        $st->execute([$sessionId]);
        $cakisan = $st->fetch();
        if ($cakisan) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'kart_baska_mesaide',
                'hata' => 'Bu mesaide çıkışı olmayan ' . $cakisan['card_no'] . ' kartı şu an '
                        . $cakisan['cavus'] . ' mesaisinde içeride. Mesai yeniden açılamaz; önce o kartın çıkışını alın.'];
        }

        $simdi = date('Y-m-d H:i:s');
        $upd = $pdo->prepare(
            "UPDATE daily_work_sessions
                SET status = 'open', closed_at = NULL, closed_by_user_id = NULL,
                    notes = NULL, updated_at = ?
              WHERE id = ? AND status = 'closed' AND work_date = ? AND depo = ?"
        );
        $upd->execute([$simdi, $sessionId, $bugun, $depo]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'kod' => 'durum_degisti', 'hata' => 'Mesai durumu değişti. Sayfayı yenileyin.'];
        }
        if ($ent) {
            $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1 WHERE id = ? AND status = 'draft'")
                ->execute([(int)$ent['id']]);
        }
        // Çavuş Ücreti (Faz 8B eki): kardeş oturumun ankor olma ihtimali değişmiş olabilir.
        pdks_faz8b_cavus_ucret_kardes_isaretle($sessionId, $pdo);

        // Faz 8E ile aynı mevcut audit_log tablosu; kayıt başarısızsa oturum da geri alınır.
        $old = [
            'session_id' => $sessionId, 'foreman_id' => (int)$oturum['foreman_id'],
            'foreman_name' => $oturum['foreman_name_snapshot'], 'status' => $oturum['status'],
            'closed_at' => $oturum['closed_at'], 'closed_by_user_id' => $oturum['closed_by_user_id'],
            'notes' => $oturum['notes'],
        ];
        $new = [
            'session_id' => $sessionId, 'foreman_id' => (int)$oturum['foreman_id'],
            'status' => 'open', 'closed_at' => null, 'closed_by_user_id' => null,
            'reason' => $sebep, 'reopened_at' => $simdi, 'user_id' => $userId,
        ];
        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, 'daily_session_reopen', 'daily_work_sessions', $sessionId,
            json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $_SERVER['REMOTE_ADDR'] ?? null, substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $pdo->commit();
        return ['ok' => true, 'session_id' => $sessionId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[pdks_gunluk_oturum_yeniden_ac] ' . $e->getMessage());
        return ['ok' => false, 'kod' => 'yazma_hatasi', 'hata' => 'Mesai yeniden açılamadı. Lütfen tekrar deneyin.'];
    }
}
