<?php
declare(strict_types=1);

// Tarihli normal süreler mevcut audit_log üzerinde eklemeli olay günlüğüdür.
// Hiçbir olay değiştirilmez/silinmez; çakışan günlerde son eklenen id kazanır.
function pdks_cavus_sure_hazir(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id, user_id, action, module, record_id, old_values, new_values, ip, user_agent, created_at FROM audit_log LIMIT 0');
        $pdo->query('SELECT id, normal_work_minutes FROM foremen LIMIT 0');
        $pdo->query('SELECT id, foreman_id, work_date, normal_work_minutes_snapshot FROM daily_work_sessions LIMIT 0');
        $pdo->query('SELECT session_id, foreman_id, work_date, status, needs_recalculation FROM foreman_daily_entitlements LIMIT 0');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function pdks_cavus_sure_tarih_gecerli(string $tarih): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $tarih, $m) === 1
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Finans hesabının okuduğu süre tanımlarının iyimser sürüm numarası. */
function pdks_cavus_sure_revizyon(int $foremanId, PDO $pdo): int
{
    try {
        $st = $pdo->prepare("SELECT MAX(id) FROM audit_log WHERE module = 'foremen' AND action = 'normal_sure_donemi' AND record_id = ?");
        if (!$st || !$st->execute([$foremanId])) return 0;
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** En yeni kayıt önce. Kullanıcı tablosu yoksa kimlik yine gösterilebilir. */
function pdks_cavus_sure_gecmis(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if ($foremanId < 1) return [];
    try {
        $pdo->query('SELECT id, action, module, record_id, old_values, new_values, user_id, created_at FROM audit_log LIMIT 0');
    } catch (PDOException $e) {
        return [];
    }
    $st = $pdo->prepare("SELECT id, old_values, new_values, user_id, created_at FROM audit_log WHERE module = 'foremen' AND action = 'normal_sure_donemi' AND record_id = ? ORDER BY id DESC");
    $st->execute([$foremanId]);
    $sonuc = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = json_decode((string)$r['new_values'], true);
        if (!is_array($v) || !isset($v['normal_work_minutes'], $v['valid_from'])) continue;
        $eski = json_decode((string)$r['old_values'], true);
        $sonuc[] = [
            'id' => (int)$r['id'], 'normal_work_minutes' => (int)$v['normal_work_minutes'],
            'valid_from' => (string)$v['valid_from'], 'valid_to' => $v['valid_to'] ?? null,
            'gerekce' => (string)($v['gerekce'] ?? ''),
            'baseline_minutes' => (int)($v['baseline_minutes'] ?? $eski['baseline_minutes'] ?? 540),
            'created_at' => (string)$r['created_at'], 'user_id' => $r['user_id'],
        ];
    }
    $kullaniciIds = array_values(array_unique(array_filter(array_map(static fn(array $r): int => (int)$r['user_id'], $sonuc))));
    if ($kullaniciIds) {
        $kullanicilar = [];
        $yerler = implode(',', array_fill(0, count($kullaniciIds), '?'));
        try {
            $stU = $pdo->prepare("SELECT id, COALESCE(NULLIF(display_name, ''), username) AS user_name FROM users WHERE id IN (" . $yerler . ')');
            $stU->execute($kullaniciIds);
            $kullanicilar = $stU->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            // Test/eksik kullanıcı şemasında günlüğün kimlik bilgisi yeterlidir.
        }
        foreach ($sonuc as &$r) $r['user_name'] = $kullanicilar[(int)$r['user_id']] ?? null;
        unset($r);
    }
    return $sonuc;
}

/** Saf çözümleyici: iki tarih de dahildir; süresiz dönemde bitiş null. */
function pdks_cavus_sure_listeden_gecerli(array $gecmis, string $tarih): ?array
{
    foreach ($gecmis as $r) {
        if ($r['valid_from'] <= $tarih && ($r['valid_to'] === null || $r['valid_to'] >= $tarih)) return $r;
    }
    return null;
}

function pdks_cavus_sure_gecerli(int $foremanId, string $tarih, ?PDO $pdo = null): ?array
{
    if (!pdks_cavus_sure_tarih_gecerli($tarih)) return null;
    return pdks_cavus_sure_listeden_gecerli(pdks_cavus_sure_gecmis($foremanId, $pdo), $tarih);
}

function pdks_cavus_sure_dk(int $foremanId, string $tarih, ?PDO $pdo = null): int
{
    $pdo = $pdo ?? db();
    $gecmis = pdks_cavus_sure_gecmis($foremanId, $pdo);
    $donem = pdks_cavus_sure_tarih_gecerli($tarih) ? pdks_cavus_sure_listeden_gecerli($gecmis, $tarih) : null;
    if ($donem !== null) return (int)$donem['normal_work_minutes'];
    // İlk kayıt öncesindeki canlı değer sabittir: sınırlı/gelecek dönem sızmaz.
    if ($gecmis) return (int)$gecmis[count($gecmis) - 1]['baseline_minutes'];
    try {
        $st = $pdo->prepare('SELECT normal_work_minutes FROM foremen WHERE id = ?');
        $st->execute([$foremanId]);
        $v = (int)$st->fetchColumn();
        return $v >= 60 && $v <= 1440 ? $v : 540;
    } catch (PDOException $e) {
        return 540;
    }
}

/** Olay, seçili oturumlar ve taslak işareti tek atomik işlemde kaydedilir. */
function pdks_cavus_sure_kaydet(int $foremanId, int $dakika, string $baslangic, ?string $bitis, string $gerekce, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $gerekce = trim($gerekce);
    $bitis = $bitis === '' ? null : $bitis;
    if (function_exists('can')) {
        $yetkili = function_exists('pdks_gunluk_can') ? pdks_gunluk_can('foremen')
            : ((function_exists('is_admin') && is_admin()) || can('attendance.foremen'));
        if (!$yetkili) return ['ok' => false, 'hata' => 'Çavuş düzenleme yetkiniz yok.'];
    }
    if ($dakika < 60 || $dakika > 1440) return ['ok' => false, 'hata' => 'Normal günlük çalışma süresi 1-24 saat aralığında olmalıdır.'];
    if (!pdks_cavus_sure_tarih_gecerli($baslangic) || ($bitis !== null && !pdks_cavus_sure_tarih_gecerli($bitis))) return ['ok' => false, 'hata' => 'Geçerli bir başlangıç ve bitiş tarihi girin.'];
    if ($bitis !== null && $bitis < $baslangic) return ['ok' => false, 'hata' => 'Bitiş tarihi başlangıçtan önce olamaz.'];
    $uzunluk = function_exists('mb_strlen') ? mb_strlen($gerekce, 'UTF-8') : preg_match_all('/./us', $gerekce);
    if ($gerekce === '' || $uzunluk === false || $uzunluk > 500) return ['ok' => false, 'hata' => 'Gerekçe zorunludur ve en fazla 500 karakter olabilir.'];
    if (!pdks_cavus_sure_hazir($pdo)) return ['ok' => false, 'hata' => 'Normal süre dönem kaydı için mevcut PDKS ve denetim şeması hazır değil.'];

    $kendiIslemi = !$pdo->inTransaction();
    $savepoint = 'cavus_sure_' . bin2hex(random_bytes(4));
    $basladi = false;
    try {
        if ($kendiIslemi) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT ' . $savepoint);
        $basladi = true;
        $kilit = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $st = $pdo->prepare('SELECT id, normal_work_minutes FROM foremen WHERE id = ?' . $kilit);
        $st->execute([$foremanId]);
        $cavus = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cavus) throw new RuntimeException('Çavuş bulunamadı.');
        $gecmis = pdks_cavus_sure_gecmis($foremanId, $pdo);
        $baz = $gecmis ? (int)$gecmis[count($gecmis) - 1]['baseline_minutes'] : (int)$cavus['normal_work_minutes'];
        if ($baz < 60 || $baz > 1440) $baz = 540;

        $kosul = 'foreman_id = ? AND work_date >= ?' . ($bitis !== null ? ' AND work_date <= ?' : '');
        $param = [$foremanId, $baslangic];
        if ($bitis !== null) $param[] = $bitis;
        // Kesinleşmiş gün, süre aynı olsa da fiyat eşiği değişebileceği için korunur.
        $st = $pdo->prepare('SELECT id, status FROM foreman_daily_entitlements WHERE ' . $kosul . $kilit);
        $st->execute($param);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            if ($h['status'] === 'final') throw new RuntimeException('Seçilen dönemde kesinleşmiş hakediş var. Önce ilgili hakedişi yeniden açın; sonra süre değişikliğini kaydedin.');
        }
        $st = $pdo->prepare('SELECT id, normal_work_minutes_snapshot FROM daily_work_sessions WHERE ' . $kosul . $kilit);
        $st->execute($param);
        $oncekiSnapshotlar = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $oturumSayisi = count($oncekiSnapshotlar);
        $eski = ['baseline_minutes' => $baz, 'gecerli_donem' => pdks_cavus_sure_listeden_gecerli($gecmis, $baslangic), 'session_snapshots' => $oncekiSnapshotlar];
        $yeni = ['normal_work_minutes' => $dakika, 'valid_from' => $baslangic, 'valid_to' => $bitis, 'gerekce' => $gerekce, 'baseline_minutes' => $baz];
        $pdo->prepare('UPDATE daily_work_sessions SET normal_work_minutes_snapshot = ? WHERE ' . $kosul)->execute(array_merge([$dakika], $param));
        // Önceki süreye verilen muhasebe kararları yeni eşik için geçerli
        // sayılamaz. Mevcut onay kolonları yeniden değerlendirmeye açılır.
        $onayAlanlari = [];
        foreach (['approved_attendance_class', 'approved_by_user_id', 'approved_at', 'overtime_approved', 'overtime_approved_hours', 'overtime_approved_by_user_id', 'overtime_approved_at'] as $alan) {
            try {
                $pdo->query('SELECT ' . $alan . ' FROM daily_worker_work_periods LIMIT 0');
                $onayAlanlari[] = $alan . ' = NULL';
            } catch (PDOException $e) {
                // Eski/eksik opsiyonel onay kolonları yeni şema gerektirmez.
            }
        }
        $yenidenDegerlendirilen = 0;
        if ($onayAlanlari) {
            $st = $pdo->prepare('UPDATE daily_worker_work_periods SET ' . implode(', ', $onayAlanlari) . ' WHERE session_id IN (SELECT id FROM daily_work_sessions WHERE ' . $kosul . ')');
            $st->execute($param);
            $yenidenDegerlendirilen = $st->rowCount();
        }
        $st = $pdo->prepare("UPDATE foreman_daily_entitlements SET needs_recalculation = 1 WHERE " . $kosul . " AND status = 'draft'");
        $st->execute($param);
        $isaretlenen = $st->rowCount();
        $yeni += ['session_ids' => array_map('intval', array_keys($oncekiSnapshotlar)), 'oturum_sayisi' => $oturumSayisi,
            'isaretlenen' => $isaretlenen, 'yeniden_degerlendirilen' => $yenidenDegerlendirilen,
            'cleared_approval_columns' => array_map(static fn(string $s): string => str_replace(' = NULL', '', $s), $onayAlanlari)];
        $st = $pdo->prepare("INSERT INTO audit_log (user_id, action, module, record_id, old_values, new_values, ip, user_agent) VALUES (?, 'normal_sure_donemi', 'foremen', ?, ?, ?, ?, ?)");
        $st->execute([$userId > 0 ? $userId : null, $foremanId, json_encode($eski, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($yeni, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        $id = (int)$pdo->lastInsertId();
        if ($kendiIslemi) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return ['ok' => true, 'id' => $id, 'oturum_sayisi' => $oturumSayisi, 'isaretlenen' => $isaretlenen, 'yeniden_degerlendirilen' => $yenidenDegerlendirilen];
    } catch (Throwable $e) {
        if ($basladi && $pdo->inTransaction()) {
            if ($kendiIslemi) $pdo->rollBack();
            else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        }
        if ($e instanceof RuntimeException && !($e instanceof PDOException)) return ['ok' => false, 'hata' => $e->getMessage()];
        error_log('[pdks_cavus_sure_kaydet] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Süre dönemi kaydedilemedi. Değişiklikler geri alındı.'];
    }
}
