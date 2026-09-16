<?php
// =========================================================
// config/pdks_faz8b.php — Faz 8B Mesai Değerlendirme + Ücretlendirme
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_hakedis.php';

defined('PDKS_FAZ8B_AKTIF') || define('PDKS_FAZ8B_AKTIF', true);
defined('PDKS_FAZ8B_NORMAL_DK') || define('PDKS_FAZ8B_NORMAL_DK', 540);      // 9 saat
defined('PDKS_FAZ8B_TOLERANS_DK') || define('PDKS_FAZ8B_TOLERANS_DK', 15);   // giriş/çıkış + FM yuvarlama toleransı

function pdks_faz8b_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info(`{$tablo}`)")->fetchAll() as $c) {
            if (($c['name'] ?? null) === $kolon) return true;
        }
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $st->execute([$tablo, $kolon]);
    return $st->fetchColumn() !== false;
}

function pdks_faz8b_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $adimlar = [
        ['foreman_worker_rates', 'half_day_rate',
            "ALTER TABLE `foreman_worker_rates` ADD COLUMN `half_day_rate` DECIMAL(12,2) NULL DEFAULT NULL AFTER `daily_rate`"],
        ['foreman_worker_rates', 'overtime_mode',
            "ALTER TABLE `foreman_worker_rates` ADD COLUMN `overtime_mode` VARCHAR(10) NULL DEFAULT NULL AFTER `half_day_rate`"],
        ['foreman_worker_rates', 'overtime_rate',
            "ALTER TABLE `foreman_worker_rates` ADD COLUMN `overtime_rate` DECIMAL(12,2) NULL DEFAULT NULL AFTER `overtime_mode`"],

        ['daily_worker_work_periods', 'approved_by_user_id',
            "ALTER TABLE `daily_worker_work_periods` ADD COLUMN `approved_by_user_id` INT NULL DEFAULT NULL AFTER `approved_attendance_class`"],
        ['daily_worker_work_periods', 'approved_at',
            "ALTER TABLE `daily_worker_work_periods` ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL AFTER `approved_by_user_id`"],
        ['daily_worker_work_periods', 'overtime_approved',
            "ALTER TABLE `daily_worker_work_periods` ADD COLUMN `overtime_approved` TINYINT(1) NULL DEFAULT NULL AFTER `approved_at`"],
        ['daily_worker_work_periods', 'overtime_approved_by_user_id',
            "ALTER TABLE `daily_worker_work_periods` ADD COLUMN `overtime_approved_by_user_id` INT NULL DEFAULT NULL AFTER `overtime_approved`"],
        ['daily_worker_work_periods', 'overtime_approved_at',
            "ALTER TABLE `daily_worker_work_periods` ADD COLUMN `overtime_approved_at` DATETIME NULL DEFAULT NULL AFTER `overtime_approved_by_user_id`"],

        ['foreman_daily_entitlements', 'needs_recalculation',
            "ALTER TABLE `foreman_daily_entitlements` ADD COLUMN `needs_recalculation` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_amount`"],

        ['foreman_daily_entitlement_lines', 'work_period_id',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `work_period_id` INT NULL DEFAULT NULL AFTER `entitlement_id`"],
        ['foreman_daily_entitlement_lines', 'attendance_class_snapshot',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `attendance_class_snapshot` VARCHAR(10) NOT NULL DEFAULT 'tam' AFTER `worker_type_name_snapshot`"],
        ['foreman_daily_entitlement_lines', 'overtime_hours',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `overtime_hours` INT NOT NULL DEFAULT 0 AFTER `unit_rate`"],
        ['foreman_daily_entitlement_lines', 'overtime_mode_snapshot',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `overtime_mode_snapshot` VARCHAR(10) NULL DEFAULT NULL AFTER `overtime_hours`"],
        ['foreman_daily_entitlement_lines', 'overtime_unit_rate',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `overtime_unit_rate` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `overtime_mode_snapshot`"],
        ['foreman_daily_entitlement_lines', 'overtime_total',
            "ALTER TABLE `foreman_daily_entitlement_lines` ADD COLUMN `overtime_total` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `overtime_unit_rate`"],
    ];

    $rapor = [];
    foreach ($adimlar as [$tablo, $kolon, $sql]) {
        if (!pdks_hakedis_tablo_var($pdo, $tablo) && !pdks_gunluk_tablo_var($pdo, $tablo)) {
            $rapor[] = ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => 'Tablo bulunamadı.'];
            continue;
        }
        if (pdks_faz8b_kolon_var($pdo, $tablo, $kolon)) {
            $rapor[] = ['adim' => "$tablo.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = ['adim' => "$tablo.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
        } catch (PDOException $e) {
            error_log('[pdks_faz8b_migrate] ' . $tablo . '.' . $kolon . ': ' . $e->getMessage());
            $rapor[] = ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => $e->getMessage(), 'sql' => $sql];
        }
    }
    return $rapor;
}

function pdks_faz8b_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    $gerekli = [
        'foreman_worker_rates' => ['half_day_rate', 'overtime_mode', 'overtime_rate'],
        'daily_worker_work_periods' => ['approved_attendance_class', 'approved_by_user_id', 'approved_at', 'overtime_approved', 'overtime_approved_by_user_id', 'overtime_approved_at'],
        'foreman_daily_entitlements' => ['needs_recalculation'],
        'foreman_daily_entitlement_lines' => ['work_period_id', 'attendance_class_snapshot', 'overtime_hours', 'overtime_mode_snapshot', 'overtime_unit_rate', 'overtime_total'],
    ];
    foreach ($gerekli as $tablo => $kolonlar) {
        foreach ($kolonlar as $kolon) {
            if (!pdks_faz8b_kolon_var($pdo, $tablo, $kolon)) return false;
        }
    }
    return true;
}

function pdks_faz8b_sayfa_kapisi(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    if (pdks_faz8b_sema_hazir($pdo)) return;
    $mesaj = 'Faz 8B şeması henüz hazır değil. Yönetici faz8b_migrate.php sayfasından migrasyonu çalıştırmalıdır.';
    if (function_exists('set_flash')) set_flash('error', $mesaj);
    if (function_exists('render_header')) render_header('Faz 8B');
    if (function_exists('render_flash')) render_flash();
    else echo function_exists('h') ? '<div class="flash flash-error">' . h($mesaj) . '</div>' : $mesaj;
    if (function_exists('render_footer')) render_footer();
    exit;
}

/**
 * Süre kuralı:
 * - normal mesai 9 saat;
 * - 15 dk tolerans nedeniyle 8s45dk ve üzeri otomatik Tam;
 * - 9 saatin üzerindeki ilk 15 dk FM sayılmaz;
 * - 16..75 dk = 1 saat, 76..135 dk = 2 saat ...
 */
function pdks_faz8b_sure_karari(?string $giris, ?string $cikis): array
{
    if (!$giris || !$cikis) {
        return [
            'toplam_dk' => null,
            'otomatik_sinif' => null,
            'sinif_onayi_gerekli' => true,
            'fazla_dk' => 0,
            'fazla_mesai_saat' => 0,
            'fazla_mesai_onayi_gerekli' => false,
        ];
    }
    $g = strtotime($giris);
    $c = strtotime($cikis);
    if ($g === false || $c === false || $c < $g) {
        return [
            'toplam_dk' => null,
            'otomatik_sinif' => null,
            'sinif_onayi_gerekli' => true,
            'fazla_dk' => 0,
            'fazla_mesai_saat' => 0,
            'fazla_mesai_onayi_gerekli' => false,
        ];
    }

    $toplamDk = intdiv($c - $g, 60);
    $tamAltSinir = PDKS_FAZ8B_NORMAL_DK - PDKS_FAZ8B_TOLERANS_DK;
    $otomatikSinif = $toplamDk >= $tamAltSinir ? 'tam' : null;
    $fazlaDk = max(0, $toplamDk - PDKS_FAZ8B_NORMAL_DK);
    $fazlaSaat = 0;
    if ($fazlaDk > PDKS_FAZ8B_TOLERANS_DK) {
        $ucretDk = $fazlaDk - PDKS_FAZ8B_TOLERANS_DK;
        $fazlaSaat = intdiv($ucretDk + 59, 60);
    }

    return [
        'toplam_dk' => $toplamDk,
        'otomatik_sinif' => $otomatikSinif,
        'sinif_onayi_gerekli' => $otomatikSinif === null,
        'fazla_dk' => $fazlaDk,
        'fazla_mesai_saat' => $fazlaSaat,
        'fazla_mesai_onayi_gerekli' => $fazlaSaat > 0,
    ];
}

function pdks_faz8b_donem_finans_durumu(array $donem): array
{
    $sure = pdks_faz8b_sure_karari($donem['entry_time'] ?? null, $donem['exit_time'] ?? null);
    $onayliSinif = trim((string)($donem['approved_attendance_class'] ?? ''));
    $sinif = $sure['otomatik_sinif'];
    $sinifKaynak = 'otomatik';
    if ($sinif === null) {
        $sinif = in_array($onayliSinif, ['tam', 'yarim'], true) ? $onayliSinif : null;
        $sinifKaynak = $sinif ? 'muhasebe' : 'bekliyor';
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    $fmOnay = $donem['overtime_approved'] ?? null;
    if ($fmOnay !== null && $fmOnay !== '') $fmOnay = (int)$fmOnay;
    else $fmOnay = null;

    $fmDurum = 'yok';
    if ($fmSaat > 0) {
        $fmDurum = $fmOnay === null ? 'bekliyor' : ($fmOnay === 1 ? 'onayli' : 'reddedildi');
    }

    $hazir = $sinif !== null && ($fmSaat === 0 || $fmOnay !== null);
    return $sure + [
        'etkin_sinif' => $sinif,
        'sinif_kaynak' => $sinifKaynak,
        'fazla_mesai_durum' => $fmDurum,
        'finans_hazir' => $hazir,
    ];
}

function pdks_faz8b_oturum_donemleri(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT p.*, w.card_no
           FROM daily_worker_work_periods p
           JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE p.session_id = ?
          ORDER BY p.entry_time ASC, p.id ASC"
    );
    $st->execute([$sessionId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) $r['faz8b'] = pdks_faz8b_donem_finans_durumu($r);
    unset($r);
    return $rows;
}

function pdks_faz8b_oturum_ozeti(int $sessionId, ?PDO $pdo = null): array
{
    $rows = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
    $bekleyenSinif = 0; $bekleyenFm = 0; $hazir = 0;
    foreach ($rows as $r) {
        if ($r['faz8b']['etkin_sinif'] === null) $bekleyenSinif++;
        if ($r['faz8b']['fazla_mesai_durum'] === 'bekliyor') $bekleyenFm++;
        if ($r['faz8b']['finans_hazir']) $hazir++;
    }
    return [
        'toplam' => count($rows),
        'hazir' => $hazir,
        'bekleyen_sinif' => $bekleyenSinif,
        'bekleyen_fazla_mesai' => $bekleyenFm,
        'tam_hazir' => count($rows) > 0 && $hazir === count($rows),
    ];
}

function pdks_faz8b_degerlendirme_kaydet(
    int $periodId,
    ?string $attendanceDecision,
    ?string $overtimeDecision,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Faz 8B şeması hazır değil.'];

    $st = $pdo->prepare("SELECT * FROM daily_worker_work_periods WHERE id = ?");
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı.'];

    $stFinal = $pdo->prepare("SELECT id FROM foreman_daily_entitlements WHERE session_id = ? AND status = 'final' LIMIT 1");
    $stFinal->execute([(int)$p['session_id']]);
    if ($stFinal->fetchColumn()) {
        return ['ok' => false, 'hata' => 'Bu oturumun hakedişi KESİN. Önce yönetici kontrollü olarak hakedişi yeniden açmalıdır.'];
    }

    $sure = pdks_faz8b_sure_karari($p['entry_time'] ?? null, $p['exit_time'] ?? null);
    $attendanceDecision = $attendanceDecision !== null ? trim($attendanceDecision) : null;
    $overtimeDecision = $overtimeDecision !== null ? trim($overtimeDecision) : null;
    $simdi = date('Y-m-d H:i:s');

    if ($sure['sinif_onayi_gerekli']) {
        if (!in_array($attendanceDecision, ['tam', 'yarim'], true)) {
            return ['ok' => false, 'hata' => '9 saat altı / çıkışı belirsiz mesai için muhasebe Tam veya Yarım kararı vermelidir.'];
        }
        $sinif = $attendanceDecision;
        $sinifUser = $userId;
        $sinifAt = $simdi;
    } else {
        $sinif = null;      // otomatik Tam; insan onayıyla karıştırma
        $sinifUser = null;
        $sinifAt = null;
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    if ($fmSaat > 0) {
        if (!in_array($overtimeDecision, ['onayla', 'reddet'], true)) {
            return ['ok' => false, 'hata' => 'Fazla mesai adayı için muhasebe Onayla veya Reddet kararı vermelidir.'];
        }
        $fmOnay = $overtimeDecision === 'onayla' ? 1 : 0;
        $fmUser = $userId;
        $fmAt = $simdi;
    } else {
        $fmOnay = null;
        $fmUser = null;
        $fmAt = null;
    }

    $before = [
        'approved_attendance_class' => $p['approved_attendance_class'] ?? null,
        'overtime_approved' => $p['overtime_approved'] ?? null,
    ];

    $upd = $pdo->prepare(
        "UPDATE daily_worker_work_periods
            SET approved_attendance_class=?, approved_by_user_id=?, approved_at=?,
                overtime_approved=?, overtime_approved_by_user_id=?, overtime_approved_at=?
          WHERE id=?"
    );
    $upd->execute([$sinif, $sinifUser, $sinifAt, $fmOnay, $fmUser, $fmAt, $periodId]);

    $pdo->prepare(
        "UPDATE foreman_daily_entitlements SET needs_recalculation=1
          WHERE session_id=? AND status='draft'"
    )->execute([(int)$p['session_id']]);

    if (function_exists('audit_log_event')) {
        audit_log_event('update', 'daily_worker_work_periods', $periodId, $before, [
            'approved_attendance_class' => $sinif,
            'overtime_approved' => $fmOnay,
            'fazla_mesai_saat_adayi' => $fmSaat,
        ]);
    }

    return ['ok' => true];
}

function pdks_faz8b_oran_ekle(
    int $foremanId,
    int $workerTypeId,
    string $tamUcretHam,
    string $yarimUcretHam,
    string $fazlaMesaiModu,
    string $fazlaMesaiUcretHam,
    string $validFrom,
    ?string $currency,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    $currency = trim((string)$currency) ?: 'TRY';
    $fazlaMesaiModu = trim($fazlaMesaiModu);
    if (!in_array($fazlaMesaiModu, ['hourly', 'fixed'], true)) {
        return ['ok' => false, 'hata' => 'Fazla mesai tipi Saatlik veya Sabit Toplam olmalıdır.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Geçerlilik başlangıç tarihi geçersiz.'];
    }

    $tamKurus = pdks_hakedis_girdi_kurus($tamUcretHam);
    $yarimKurus = pdks_hakedis_girdi_kurus($yarimUcretHam);
    $fmKurus = pdks_hakedis_girdi_kurus($fazlaMesaiUcretHam);
    if ($tamKurus === null || $tamKurus <= 0) return ['ok' => false, 'hata' => 'Tam Mesai ücreti geçersiz.'];
    if ($yarimKurus === null || $yarimKurus <= 0) return ['ok' => false, 'hata' => 'Yarım Mesai ücreti geçersiz.'];
    if ($fmKurus === null || $fmKurus <= 0) return ['ok' => false, 'hata' => 'Fazla Mesai ücreti geçersiz.'];

    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id=?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];
    $stT = $pdo->prepare("SELECT id FROM worker_types WHERE id=?");
    $stT->execute([$workerTypeId]);
    if (!$stT->fetchColumn()) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];

    $stMevcut = $pdo->prepare(
        "SELECT * FROM foreman_worker_rates
          WHERE foreman_id=? AND worker_type_id=? AND is_active=1
          ORDER BY valid_from DESC, id DESC LIMIT 1"
    );
    $stMevcut->execute([$foremanId, $workerTypeId]);
    $mevcut = $stMevcut->fetch();
    if ($mevcut && strtotime((string)$mevcut['valid_from']) >= strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Yeni başlangıç tarihi mevcut en son fiyat döneminden sonra olmalıdır.'];
    }

    $pdo->beginTransaction();
    try {
        if ($mevcut && (($mevcut['valid_to'] ?? null) === null || strtotime((string)$mevcut['valid_to']) >= strtotime($validFrom))) {
            $bitis = date('Y-m-d', strtotime($validFrom . ' -1 day'));
            $pdo->prepare("UPDATE foreman_worker_rates SET valid_to=? WHERE id=?")->execute([$bitis, (int)$mevcut['id']]);
        }
        $ins = $pdo->prepare(
            "INSERT INTO foreman_worker_rates
                (foreman_id, worker_type_id, daily_rate, half_day_rate, overtime_mode, overtime_rate,
                 currency, valid_from, valid_to, is_active, created_by_user_id)
             VALUES (?,?,?,?,?,?,?, ?,NULL,1,?)"
        );
        $ins->execute([
            $foremanId, $workerTypeId,
            pdks_hakedis_kurus_tl($tamKurus), pdks_hakedis_kurus_tl($yarimKurus),
            $fazlaMesaiModu, pdks_hakedis_kurus_tl($fmKurus),
            $currency, $validFrom, $userId,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Fiyat dönemi kaydedilemedi: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_worker_rates', $id, null, [
            'foreman_id' => $foremanId,
            'worker_type_id' => $workerTypeId,
            'daily_rate' => pdks_hakedis_kurus_tl($tamKurus),
            'half_day_rate' => pdks_hakedis_kurus_tl($yarimKurus),
            'overtime_mode' => $fazlaMesaiModu,
            'overtime_rate' => pdks_hakedis_kurus_tl($fmKurus),
            'currency' => $currency,
            'valid_from' => $validFrom,
        ]);
    }
    return ['ok' => true, 'id' => $id];
}

function pdks_faz8b_hakedis_hesapla(int $sessionId, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) return ['ok' => false, 'kod' => 'faz8b_sema_yok', 'hata' => 'Faz 8B şeması hazır değil.'];

    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id=?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    $stE = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE session_id=?");
    $stE->execute([$sessionId]);
    $mevcut = $stE->fetch();
    if ($mevcut && ($mevcut['status'] ?? '') === 'final') {
        return ['ok' => false, 'kod' => 'zaten_kesinlesmis', 'hata' => 'Bu mesainin hakedişi zaten KESİNLEŞMİŞ.'];
    }

    $donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
    if (!$donemler) return ['ok' => false, 'kod' => 'kart_yok', 'hata' => 'Bu mesaide hesaplanacak işçi dönemi yok.'];

    $satirlar = [];
    $eksikler = [];
    $paraBirimleri = [];
    $toplamKurus = 0;
    $stKod = $pdo->prepare("SELECT code FROM worker_types WHERE id=?");

    foreach ($donemler as $d) {
        $f = $d['faz8b'];
        if (!$f['finans_hazir']) {
            $eksikler[] = ($d['card_no'] ?? ('#' . $d['id'])) . ' — muhasebe değerlendirmesi bekliyor';
            continue;
        }

        $tipId = $d['worker_type_id_snapshot'] !== null ? (int)$d['worker_type_id_snapshot'] : null;
        if (!$tipId) {
            $eksikler[] = ($d['card_no'] ?? ('#' . $d['id'])) . ' — işçi tipi eksik';
            continue;
        }
        $oran = pdks_hakedis_oran_gecerli((int)$oturum['foreman_id'], $tipId, (string)$oturum['work_date'], $pdo);
        if (!$oran) {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçerli fiyat yok';
            continue;
        }

        $sinif = (string)$f['etkin_sinif'];
        $baseRaw = $sinif === 'yarim' ? ($oran['half_day_rate'] ?? null) : ($oran['daily_rate'] ?? null);
        if ($baseRaw === null || $baseRaw === '') {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — ' . ($sinif === 'yarim' ? 'Yarım' : 'Tam') . ' Mesai fiyatı yok';
            continue;
        }
        try { $baseKurus = pdks_hakedis_tl_kurus((string)$baseRaw); }
        catch (Throwable $e) { $eksikler[] = 'Geçersiz fiyat'; continue; }

        $fmSaat = (int)$f['fazla_mesai_saat'];
        $fmOnayli = $fmSaat > 0 && ($d['overtime_approved'] ?? null) !== null && (int)$d['overtime_approved'] === 1;
        $fmMode = null; $fmBirimKurus = 0; $fmToplamKurus = 0;
        if ($fmOnayli) {
            $fmMode = (string)($oran['overtime_mode'] ?? '');
            $fmRaw = $oran['overtime_rate'] ?? null;
            if (!in_array($fmMode, ['hourly', 'fixed'], true) || $fmRaw === null || $fmRaw === '') {
                $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — Fazla Mesai fiyatı/tipi yok';
                continue;
            }
            try { $fmBirimKurus = pdks_hakedis_tl_kurus((string)$fmRaw); }
            catch (Throwable $e) { $eksikler[] = 'Geçersiz fazla mesai fiyatı'; continue; }
            $fmToplamKurus = $fmMode === 'fixed' ? $fmBirimKurus : ($fmBirimKurus * $fmSaat);
        }

        $para = trim((string)($oran['currency'] ?? 'TRY')) ?: 'TRY';
        $paraBirimleri[$para] = true;
        $stKod->execute([$tipId]);
        $kod = (string)($stKod->fetchColumn() ?: '');
        $lineKurus = $baseKurus + $fmToplamKurus;
        $toplamKurus += $lineKurus;
        $satirlar[] = [
            'work_period_id' => (int)$d['id'],
            'worker_type_id' => $tipId,
            'worker_type_code_snapshot' => $kod,
            'worker_type_name_snapshot' => (string)$d['worker_type_name_snapshot'],
            'attendance_class_snapshot' => $sinif,
            'worker_count' => 1,
            'unit_rate' => pdks_hakedis_kurus_tl($baseKurus),
            'overtime_hours' => $fmOnayli ? $fmSaat : 0,
            'overtime_mode_snapshot' => $fmOnayli ? $fmMode : null,
            'overtime_unit_rate' => pdks_hakedis_kurus_tl($fmBirimKurus),
            'overtime_total' => pdks_hakedis_kurus_tl($fmToplamKurus),
            'line_total' => pdks_hakedis_kurus_tl($lineKurus),
        ];
    }

    if ($eksikler) {
        return ['ok' => false, 'kod' => 'faz8b_degerlendirme_gerekli', 'hata' => 'Hakediş hesaplanamadı: ' . implode('; ', array_slice($eksikler, 0, 4)), 'eksikler' => $eksikler];
    }
    if (count($paraBirimleri) > 1) {
        return ['ok' => false, 'kod' => 'karisik_para_birimi', 'hata' => 'Bu mesai için farklı para birimleri karışıyor.'];
    }
    $paraBirimi = (string)array_key_first($paraBirimleri);
    $simdi = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($mevcut) {
            $entId = (int)$mevcut['id'];
            $pdo->prepare("DELETE FROM foreman_daily_entitlement_lines WHERE entitlement_id=?")->execute([$entId]);
            $pdo->prepare(
                "UPDATE foreman_daily_entitlements
                    SET status='draft', currency=?, total_amount=?, needs_recalculation=0,
                        calculated_at=?, calculated_by_user_id=?, updated_at=?
                  WHERE id=?"
            )->execute([$paraBirimi, pdks_hakedis_kurus_tl($toplamKurus), $simdi, $userId, $simdi, $entId]);
        } else {
            $insE = $pdo->prepare(
                "INSERT INTO foreman_daily_entitlements
                    (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot, work_date, depo,
                     status, currency, total_amount, needs_recalculation, calculated_at, calculated_by_user_id)
                 VALUES (?,?,?,?,?,?,'draft',?,?,0,?,?)"
            );
            $insE->execute([
                $sessionId, (int)$oturum['foreman_id'], (string)($oturum['foreman_name_snapshot'] ?? ''),
                (string)($oturum['foreman_code_snapshot'] ?? ''), (string)$oturum['work_date'],
                (string)($oturum['depo'] ?? ''), $paraBirimi, pdks_hakedis_kurus_tl($toplamKurus), $simdi, $userId,
            ]);
            $entId = (int)$pdo->lastInsertId();
        }

        $insL = $pdo->prepare(
            "INSERT INTO foreman_daily_entitlement_lines
                (entitlement_id, work_period_id, worker_type_id, worker_type_code_snapshot, worker_type_name_snapshot,
                 attendance_class_snapshot, worker_count, unit_rate, overtime_hours, overtime_mode_snapshot,
                 overtime_unit_rate, overtime_total, line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($satirlar as $sl) {
            $insL->execute([
                $entId, $sl['work_period_id'], $sl['worker_type_id'], $sl['worker_type_code_snapshot'], $sl['worker_type_name_snapshot'],
                $sl['attendance_class_snapshot'], $sl['worker_count'], $sl['unit_rate'], $sl['overtime_hours'], $sl['overtime_mode_snapshot'],
                $sl['overtime_unit_rate'], $sl['overtime_total'], $sl['line_total'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'kod' => 'yazim_hatasi', 'hata' => 'Hakediş yazılamadı: ' . $e->getMessage()];
    }

    if (function_exists('audit_log_event')) {
        audit_log_event('calculate', 'foreman_daily_entitlements', $entId, null, [
            'session_id' => $sessionId,
            'faz' => '8B',
            'total_amount' => pdks_hakedis_kurus_tl($toplamKurus),
        ]);
    }

    return ['ok' => true, 'entitlement_id' => $entId, 'status' => 'draft', 'total_amount' => pdks_hakedis_kurus_tl($toplamKurus), 'lines' => $satirlar];
}

function pdks_faz8b_hakedis_finalize(int $sessionId, int $userId, bool $eksikCikisOnayi, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id=?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];
    if ((string)$oturum['status'] !== 'closed') {
        return ['ok' => false, 'kod' => 'oturum_acik', 'hata' => 'Mesai AÇIK — hakediş yalnız KAPALI mesai için kesinleştirilebilir.'];
    }

    $ozet = pdks_gunluk_oturum_ozet($sessionId, $pdo);
    $eksikToplam = (int)($ozet['eksik_toplam'] ?? 0);
    if ($eksikToplam > 0 && !$eksikCikisOnayi) {
        return ['ok' => false, 'kod' => 'eksik_cikis_onay_gerekli', 'hata' => 'Eksik çıkış var. Devam etmek için açık onay gerekir.'];
    }

    $hesap = pdks_faz8b_hakedis_hesapla($sessionId, $userId, $pdo);
    if (!$hesap['ok']) return $hesap;

    $simdi = date('Y-m-d H:i:s');
    $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET status='final', finalized_at=?, finalized_by_user_id=?, missing_exit_ack=?, needs_recalculation=0, updated_at=?
          WHERE id=?"
    )->execute([$simdi, $userId, $eksikToplam > 0 ? 1 : 0, $simdi, (int)$hesap['entitlement_id']]);

    if (function_exists('audit_log_event')) {
        audit_log_event('finalize', 'foreman_daily_entitlements', (int)$hesap['entitlement_id'], null, [
            'session_id' => $sessionId,
            'faz' => '8B',
            'total_amount' => $hesap['total_amount'],
        ]);
    }
    return ['ok' => true, 'entitlement_id' => (int)$hesap['entitlement_id'], 'status' => 'final', 'total_amount' => $hesap['total_amount']];
}
