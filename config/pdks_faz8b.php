<?php
// =========================================================
// config/pdks_faz8b.php — Faz 8B Mesai Değerlendirme + Ücretlendirme
//
// İş kuralı:
//   - Standart vardiya: 08:00–17:00 (9 saat).
//   - Giriş/çıkışta 15 dk tolerans: 08:15 giriş / 16:45 çıkış hâlâ
//     otomatik Tam kabul edilebilir; ayrıca fiili süre 9 saat ve üzeriyse
//     vardiya saati kaymış olsa bile otomatik Tam'dır.
//   - 9 saatten kısa ve tolerans penceresini karşılamayan dönemlerde
//     muhasebe Tam/Yarım kararı verir. ÇIKIŞ asla engellenmez.
//   - Fazla mesai planlı 17:00 bitişinden sonra ölçülür. İlk 15 dk tolerans:
//       17:15'e kadar FM yok,
//       17:16–18:15 = 1 saat,
//       18:16–19:15 = 2 saat, ...
//     Her FM adayı muhasebe onayına düşer.
//   - Çavuş ücretinde Tam, Yarım ve FM ücreti ayrı tanımlanır. FM tipi
//     hourly (saatlik) veya fixed (sabit toplam) olabilir.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_gunluk.php';
require_once __DIR__ . '/pdks_hakedis.php';

defined('PDKS_FAZ8B_AKTIF') || define('PDKS_FAZ8B_AKTIF', true);
defined('PDKS_FAZ8B_NORMAL_DK') || define('PDKS_FAZ8B_NORMAL_DK', 540);
defined('PDKS_FAZ8B_TOLERANS_DK') || define('PDKS_FAZ8B_TOLERANS_DK', 15);
defined('PDKS_FAZ8B_VARDIYA_BASLANGIC') || define('PDKS_FAZ8B_VARDIYA_BASLANGIC', '08:00');
defined('PDKS_FAZ8B_VARDIYA_BITIS') || define('PDKS_FAZ8B_VARDIYA_BITIS', '17:00');

// =========================================================
// ŞEMA / MİGRASYON
// =========================================================

function pdks_faz8b_tablo_var(PDO $pdo, string $tablo): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

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
        "SELECT 1
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1"
    );
    $st->execute([$tablo, $kolon]);
    return $st->fetchColumn() !== false;
}

function pdks_faz8b_kolon_ekle(
    PDO $pdo,
    string $tablo,
    string $kolon,
    string $tanim,
    ?string $after = null
): array {
    if (!pdks_faz8b_tablo_var($pdo, $tablo)) {
        return ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => 'Tablo bulunamadı.'];
    }
    if (pdks_faz8b_kolon_var($pdo, $tablo, $kolon)) {
        return ['adim' => "$tablo.$kolon", 'durum' => 'var', 'mesaj' => 'Kolon zaten mevcut.'];
    }

    $sql = "ALTER TABLE `{$tablo}` ADD COLUMN `{$kolon}` {$tanim}";
    if ($after !== null && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= " AFTER `{$after}`";
    }

    try {
        $pdo->exec($sql);
        return ['adim' => "$tablo.$kolon", 'durum' => 'eklendi', 'mesaj' => 'Kolon eklendi.'];
    } catch (PDOException $e) {
        error_log('[pdks_faz8b_migrate] ' . $tablo . '.' . $kolon . ': ' . $e->getMessage());
        return ['adim' => "$tablo.$kolon", 'durum' => 'hata', 'mesaj' => $e->getMessage(), 'sql' => $sql];
    }
}

function pdks_faz8b_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $spec = [
        ['foreman_worker_rates', 'half_day_rate', 'DECIMAL(12,2) NULL DEFAULT NULL', 'daily_rate'],
        ['foreman_worker_rates', 'overtime_mode', 'VARCHAR(10) NULL DEFAULT NULL', 'half_day_rate'],
        ['foreman_worker_rates', 'overtime_rate', 'DECIMAL(12,2) NULL DEFAULT NULL', 'overtime_mode'],

        ['daily_worker_work_periods', 'approved_by_user_id', 'INT NULL DEFAULT NULL', 'approved_attendance_class'],
        ['daily_worker_work_periods', 'approved_at', 'DATETIME NULL DEFAULT NULL', 'approved_by_user_id'],
        ['daily_worker_work_periods', 'overtime_approved', 'TINYINT(1) NULL DEFAULT NULL', 'approved_at'],
        ['daily_worker_work_periods', 'overtime_approved_by_user_id', 'INT NULL DEFAULT NULL', 'overtime_approved'],
        ['daily_worker_work_periods', 'overtime_approved_at', 'DATETIME NULL DEFAULT NULL', 'overtime_approved_by_user_id'],

        ['foreman_daily_entitlements', 'needs_recalculation', 'TINYINT(1) NOT NULL DEFAULT 0', 'total_amount'],

        ['foreman_daily_entitlement_lines', 'work_period_id', 'INT NULL DEFAULT NULL', 'entitlement_id'],
        ['foreman_daily_entitlement_lines', 'attendance_class_snapshot', "VARCHAR(10) NOT NULL DEFAULT 'tam'", 'worker_type_name_snapshot'],
        ['foreman_daily_entitlement_lines', 'overtime_hours', 'INT NOT NULL DEFAULT 0', 'unit_rate'],
        ['foreman_daily_entitlement_lines', 'overtime_mode_snapshot', 'VARCHAR(10) NULL DEFAULT NULL', 'overtime_hours'],
        ['foreman_daily_entitlement_lines', 'overtime_unit_rate', 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'overtime_mode_snapshot'],
        ['foreman_daily_entitlement_lines', 'overtime_total', 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'overtime_unit_rate'],
    ];

    $rapor = [];
    foreach ($spec as [$tablo, $kolon, $tanim, $after]) {
        $rapor[] = pdks_faz8b_kolon_ekle($pdo, $tablo, $kolon, $tanim, $after);
    }
    return $rapor;
}

function pdks_faz8b_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    $gerekli = [
        'foreman_worker_rates' => ['half_day_rate', 'overtime_mode', 'overtime_rate'],
        'daily_worker_work_periods' => [
            'approved_attendance_class', 'approved_by_user_id', 'approved_at',
            'overtime_approved', 'overtime_approved_by_user_id', 'overtime_approved_at',
        ],
        'foreman_daily_entitlements' => ['needs_recalculation'],
        'foreman_daily_entitlement_lines' => [
            'work_period_id', 'attendance_class_snapshot', 'overtime_hours',
            'overtime_mode_snapshot', 'overtime_unit_rate', 'overtime_total',
        ],
    ];

    foreach ($gerekli as $tablo => $kolonlar) {
        if (!pdks_faz8b_tablo_var($pdo, $tablo)) return false;
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
    elseif (function_exists('h')) echo '<div class="flash flash-error">' . h($mesaj) . '</div>';
    else echo $mesaj;
    if (function_exists('render_footer')) render_footer();
    exit;
}

// =========================================================
// SÜRE / TOLERANS POLİTİKASI
// =========================================================

/**
 * Standart gün 08:00–17:00'dır.
 *
 * Otomatik Tam için iki güvenli yol vardır:
 *  1) fiili süre >= 9 saat, veya
 *  2) vardiya sınırları tolerans içinde karşılanmıştır:
 *     giriş en geç 08:15 ve çıkış en erken 16:45.
 *
 * Bu ikinci kural 08:15–16:45 gibi, iki uçta da 15'er dakikalık toleransı
 * açıkça karşılar. 9 saatten kısa ama bu pencereyi karşılamayan dönemler
 * muhasebe kararına düşer.
 *
 * Fazla mesai yalnız PLANLI bitiş 17:00 sonrasından hesaplanır. İlk 15 dk
 * toleranstır. Sonrasında başlayan her saat yukarı yuvarlanır:
 *   17:16–18:15 => 1 saat
 *   18:16–19:15 => 2 saat
 */
function pdks_faz8b_sure_karari(?string $giris, ?string $cikis): array
{
    $bos = [
        'toplam_dk' => null,
        'otomatik_sinif' => null,
        'sinif_onayi_gerekli' => true,
        'plan_sonrasi_dk' => 0,
        'fazla_mesai_saat' => 0,
        'fazla_mesai_onayi_gerekli' => false,
        'giris_toleransinda' => false,
        'cikis_toleransinda' => false,
    ];
    if (!$giris || !$cikis) return $bos;

    $g = strtotime($giris);
    $c = strtotime($cikis);
    if ($g === false || $c === false || $c < $g) return $bos;

    $toplamDk = intdiv($c - $g, 60);
    $gun = date('Y-m-d', $g);
    $planBas = strtotime($gun . ' ' . PDKS_FAZ8B_VARDIYA_BASLANGIC . ':00');
    $planBit = strtotime($gun . ' ' . PDKS_FAZ8B_VARDIYA_BITIS . ':00');
    if ($planBas === false || $planBit === false) return $bos;

    $tolSn = PDKS_FAZ8B_TOLERANS_DK * 60;
    $girisToleransinda = $g <= ($planBas + $tolSn);
    $cikisToleransinda = $c >= ($planBit - $tolSn);
    $vardiyaPenceresiTam = $girisToleransinda && $cikisToleransinda;
    $otomatikTam = $toplamDk >= PDKS_FAZ8B_NORMAL_DK || $vardiyaPenceresiTam;

    $planSonrasiDk = $c > $planBit ? intdiv($c - $planBit, 60) : 0;
    $fmSaat = 0;
    if ($planSonrasiDk > PDKS_FAZ8B_TOLERANS_DK) {
        // 15 dk toleransı çıkar; kalan her başlayan saat yukarı yuvarlanır.
        $ucretDk = $planSonrasiDk - PDKS_FAZ8B_TOLERANS_DK;
        $fmSaat = intdiv($ucretDk + 59, 60);
    }

    return [
        'toplam_dk' => $toplamDk,
        'otomatik_sinif' => $otomatikTam ? 'tam' : null,
        'sinif_onayi_gerekli' => !$otomatikTam,
        'plan_sonrasi_dk' => $planSonrasiDk,
        'fazla_mesai_saat' => $fmSaat,
        'fazla_mesai_onayi_gerekli' => $fmSaat > 0,
        'giris_toleransinda' => $girisToleransinda,
        'cikis_toleransinda' => $cikisToleransinda,
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
        $sinifKaynak = $sinif !== null ? 'muhasebe' : 'bekliyor';
    }

    $fmSaat = (int)$sure['fazla_mesai_saat'];
    $fmOnay = $donem['overtime_approved'] ?? null;
    if ($fmOnay === '' || $fmOnay === null) $fmOnay = null;
    else $fmOnay = (int)$fmOnay;

    $fmDurum = 'yok';
    if ($fmSaat > 0) {
        $fmDurum = $fmOnay === null ? 'bekliyor' : ($fmOnay === 1 ? 'onayli' : 'reddedildi');
    }

    return $sure + [
        'etkin_sinif' => $sinif,
        'sinif_kaynak' => $sinifKaynak,
        'fazla_mesai_durum' => $fmDurum,
        'finans_hazir' => $sinif !== null && ($fmSaat === 0 || $fmOnay !== null),
    ];
}

function pdks_faz8b_oturum_donemleri(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $st = $pdo->prepare(
        "SELECT p.*, w.card_no
           FROM daily_worker_work_periods p
           JOIN worker_cards w ON w.id = p.worker_card_id
          WHERE p.session_id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo, 'p') . "
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
    $bekleyenSinif = 0;
    $bekleyenFm = 0;
    $hazir = 0;

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

// =========================================================
// MUHASEBE DEĞERLENDİRMESİ
// =========================================================

function pdks_faz8b_degerlendirme_kaydet(
    int $periodId,
    ?string $attendanceDecision,
    ?string $overtimeDecision,
    int $userId,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) {
        return ['ok' => false, 'hata' => 'Faz 8B şeması hazır değil.'];
    }

    $st = $pdo->prepare("SELECT * FROM daily_worker_work_periods WHERE id = ? AND " . pdks_gunluk_faz8j_etkin_kosul($pdo));
    $st->execute([$periodId]);
    $p = $st->fetch();
    if (!$p) return ['ok' => false, 'hata' => 'Mesai dönemi bulunamadı.'];

    $stFinal = $pdo->prepare(
        "SELECT id FROM foreman_daily_entitlements
          WHERE session_id = ? AND status = 'final' LIMIT 1"
    );
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
            return ['ok' => false, 'hata' => 'Kısa / çıkışı belirsiz mesai için muhasebe Tam veya Yarım kararı vermelidir.'];
        }
        $sinif = $attendanceDecision;
        $sinifUser = $userId;
        $sinifAt = $simdi;
    } else {
        // Otomatik Tam. İnsan onayı ile karıştırmamak için approved_* boş kalır.
        $sinif = null;
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
            SET approved_attendance_class = ?, approved_by_user_id = ?, approved_at = ?,
                overtime_approved = ?, overtime_approved_by_user_id = ?, overtime_approved_at = ?
          WHERE id = ?"
    );
    $upd->execute([$sinif, $sinifUser, $sinifAt, $fmOnay, $fmUser, $fmAt, $periodId]);

    // Daha önce hesaplanmış taslak artık finansal olarak bayattır.
    $pdo->prepare(
        "UPDATE foreman_daily_entitlements
            SET needs_recalculation = 1
          WHERE session_id = ? AND status = 'draft'"
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

// =========================================================
// ÇAVUŞ FİYATLARI — TAM / YARIM / FAZLA MESAİ
// =========================================================

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

    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    $stT = $pdo->prepare("SELECT id FROM worker_types WHERE id = ?");
    $stT->execute([$workerTypeId]);
    if (!$stT->fetchColumn()) return ['ok' => false, 'hata' => 'İşçi tipi bulunamadı.'];

    $stMevcut = $pdo->prepare(
        "SELECT * FROM foreman_worker_rates
          WHERE foreman_id = ? AND worker_type_id = ? AND is_active = 1
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
            $pdo->prepare("UPDATE foreman_worker_rates SET valid_to = ? WHERE id = ?")
                ->execute([$bitis, (int)$mevcut['id']]);
        }

        $ins = $pdo->prepare(
            "INSERT INTO foreman_worker_rates
                (foreman_id, worker_type_id, daily_rate, half_day_rate,
                 overtime_mode, overtime_rate, currency, valid_from, valid_to,
                 is_active, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,NULL,1,?)"
        );
        $ins->execute([
            $foremanId,
            $workerTypeId,
            pdks_hakedis_kurus_tl($tamKurus),
            pdks_hakedis_kurus_tl($yarimKurus),
            $fazlaMesaiModu,
            pdks_hakedis_kurus_tl($fmKurus),
            $currency,
            $validFrom,
            $userId,
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

// =========================================================
// HAKEDİŞ — FAZ 8B OTORİTER HESAP
// =========================================================

function pdks_faz8b_hakedis_hesapla(int $sessionId, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_faz8b_sema_hazir($pdo)) {
        return ['ok' => false, 'kod' => 'faz8b_sema_yok', 'hata' => 'Faz 8B şeması hazır değil.'];
    }

    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'];

    $stE = $pdo->prepare("SELECT * FROM foreman_daily_entitlements WHERE session_id = ?");
    $stE->execute([$sessionId]);
    $mevcut = $stE->fetch();
    if ($mevcut && ($mevcut['status'] ?? '') === 'final') {
        return ['ok' => false, 'kod' => 'zaten_kesinlesmis', 'hata' => 'Bu mesainin hakedişi zaten KESİNLEŞMİŞ.'];
    }

    $donemler = pdks_faz8b_oturum_donemleri($sessionId, $pdo);
    if (!$donemler) {
        return ['ok' => false, 'kod' => 'kart_yok', 'hata' => 'Bu mesaide hesaplanacak işçi dönemi yok.'];
    }

    $satirlar = [];
    $eksikler = [];
    $paraBirimleri = [];
    $toplamKurus = 0;
    $stKod = $pdo->prepare("SELECT code FROM worker_types WHERE id = ?");

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

        $oran = pdks_hakedis_oran_gecerli(
            (int)$oturum['foreman_id'],
            $tipId,
            (string)$oturum['work_date'],
            $pdo
        );
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
        try {
            $baseKurus = pdks_hakedis_tl_kurus((string)$baseRaw);
        } catch (Throwable $e) {
            $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçersiz temel fiyat';
            continue;
        }

        $fmSaat = (int)$f['fazla_mesai_saat'];
        $fmOnayli = $fmSaat > 0
            && ($d['overtime_approved'] ?? null) !== null
            && (int)$d['overtime_approved'] === 1;
        $fmMode = null;
        $fmBirimKurus = 0;
        $fmToplamKurus = 0;

        if ($fmOnayli) {
            $fmMode = trim((string)($oran['overtime_mode'] ?? ''));
            $fmRaw = $oran['overtime_rate'] ?? null;
            if (!in_array($fmMode, ['hourly', 'fixed'], true) || $fmRaw === null || $fmRaw === '') {
                $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — Fazla Mesai fiyatı/tipi yok';
                continue;
            }
            try {
                $fmBirimKurus = pdks_hakedis_tl_kurus((string)$fmRaw);
            } catch (Throwable $e) {
                $eksikler[] = (string)$d['worker_type_name_snapshot'] . ' — geçersiz Fazla Mesai fiyatı';
                continue;
            }
            $fmToplamKurus = $fmMode === 'fixed'
                ? $fmBirimKurus
                : ($fmBirimKurus * $fmSaat);
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

    // Validate-first: hiçbir finansal satır değiştirilmeden önce tüm kararlar
    // ve fiyatlar tamam olmalıdır.
    if ($eksikler) {
        return [
            'ok' => false,
            'kod' => 'faz8b_degerlendirme_gerekli',
            'hata' => 'Hakediş hesaplanamadı: ' . implode('; ', array_slice($eksikler, 0, 4)),
            'eksikler' => $eksikler,
        ];
    }
    if (count($paraBirimleri) > 1) {
        return ['ok' => false, 'kod' => 'karisik_para_birimi', 'hata' => 'Bu mesai için farklı para birimleri karışıyor.'];
    }
    if (!$satirlar) {
        return ['ok' => false, 'kod' => 'satir_yok', 'hata' => 'Hakediş için finansal satır oluşmadı.'];
    }

    $paraBirimi = (string)array_key_first($paraBirimleri);
    $simdi = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        if ($mevcut) {
            $entId = (int)$mevcut['id'];
            $pdo->prepare("DELETE FROM foreman_daily_entitlement_lines WHERE entitlement_id = ?")
                ->execute([$entId]);
            $pdo->prepare(
                "UPDATE foreman_daily_entitlements
                    SET status = 'draft', currency = ?, total_amount = ?, needs_recalculation = 0,
                        calculated_at = ?, calculated_by_user_id = ?, updated_at = ?
                  WHERE id = ?"
            )->execute([
                $paraBirimi,
                pdks_hakedis_kurus_tl($toplamKurus),
                $simdi,
                $userId,
                $simdi,
                $entId,
            ]);
        } else {
            $insE = $pdo->prepare(
                "INSERT INTO foreman_daily_entitlements
                    (session_id, foreman_id, foreman_name_snapshot, foreman_code_snapshot,
                     work_date, depo, status, currency, total_amount, needs_recalculation,
                     calculated_at, calculated_by_user_id)
                 VALUES (?,?,?,?,?,?,'draft',?,?,0,?,?)"
            );
            $insE->execute([
                $sessionId,
                (int)$oturum['foreman_id'],
                (string)($oturum['foreman_name_snapshot'] ?? ''),
                (string)($oturum['foreman_code_snapshot'] ?? ''),
                (string)$oturum['work_date'],
                (string)($oturum['depo'] ?? ''),
                $paraBirimi,
                pdks_hakedis_kurus_tl($toplamKurus),
                $simdi,
                $userId,
            ]);
            $entId = (int)$pdo->lastInsertId();
        }

        $insL = $pdo->prepare(
            "INSERT INTO foreman_daily_entitlement_lines
                (entitlement_id, work_period_id, worker_type_id,
                 worker_type_code_snapshot, worker_type_name_snapshot,
                 attendance_class_snapshot, worker_count, unit_rate,
                 overtime_hours, overtime_mode_snapshot, overtime_unit_rate,
                 overtime_total, line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        foreach ($satirlar as $sl) {
            $insL->execute([
                $entId,
                $sl['work_period_id'],
                $sl['worker_type_id'],
                $sl['worker_type_code_snapshot'],
                $sl['worker_type_name_snapshot'],
                $sl['attendance_class_snapshot'],
                $sl['worker_count'],
                $sl['unit_rate'],
                $sl['overtime_hours'],
                $sl['overtime_mode_snapshot'],
                $sl['overtime_unit_rate'],
                $sl['overtime_total'],
                $sl['line_total'],
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

    return [
        'ok' => true,
        'entitlement_id' => $entId,
        'status' => 'draft',
        'total_amount' => pdks_hakedis_kurus_tl($toplamKurus),
        'lines' => $satirlar,
    ];
}

function pdks_faz8b_hakedis_finalize(
    int $sessionId,
    int $userId,
    bool $eksikCikisOnayi,
    ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();

    $st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
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
            SET status = 'final', finalized_at = ?, finalized_by_user_id = ?,
                missing_exit_ack = ?, needs_recalculation = 0, updated_at = ?
          WHERE id = ?"
    )->execute([
        $simdi,
        $userId,
        $eksikToplam > 0 ? 1 : 0,
        $simdi,
        (int)$hesap['entitlement_id'],
    ]);

    if (function_exists('audit_log_event')) {
        audit_log_event('finalize', 'foreman_daily_entitlements', (int)$hesap['entitlement_id'], null, [
            'session_id' => $sessionId,
            'faz' => '8B',
            'total_amount' => $hesap['total_amount'],
        ]);
    }

    return [
        'ok' => true,
        'entitlement_id' => (int)$hesap['entitlement_id'],
        'status' => 'final',
        'total_amount' => $hesap['total_amount'],
    ];
}
