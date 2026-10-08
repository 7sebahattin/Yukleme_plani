<?php
// =========================================================
// config/pdks_servis.php — SERVİS ÜCRETİ (v299)
//
// Mesai Detayı'nda bir mesaiye BÜYÜK / KÜÇÜK servis adedi eklenir; fiyat
// çavuş bazında, tarihli (foreman_service_rates — foreman_daily_rates
// emsali). Hakediş motoru (pdks_faz8b_hakedis_hesapla) "Çavuş Ücreti"
// bloğunun ALTINDA tür başına 1 satır ekler:
//   worker_type_id NULL + work_period_id NULL +
//   worker_type_code_snapshot = 'SERVIS_BUYUK' | 'SERVIS_KUCUK'
// ⚠ Çavuş Ücreti satırı da worker_type_id/work_period_id NULL taşır ama
//   kodu ''dir — "Çavuş Ücreti satırı" dedektörleri (A: baska_final_var_mi,
//   B: b_aday_kalemler) bu yüzden `worker_type_code_snapshot = ''` ister.
//   O filtreyi kaldırma: servis satırı Yöntem A'da günlük ücreti engeller,
//   Yöntem B'de günü havuzdan düşürürdü.
//
// Kendi tabloları + kendi migrate/sema_hazir çifti; pdks_faz8b_sema_hazir()'e
// ve diğer genel hazır-mı kontrollerine BİLEREK EKLENMEZ (Çavuş Ücreti
// emsali). Tablo yoksa özellik GİZLİ, hesap servisi yok sayar. Kurulum
// yalnız migrate.php kartı; sayfalarda migrate ÇAĞRILMAZ.
//
// Yazma (ekle/iptal) Faz 8J kapılarını kullanır: yalnız admin, aktif depo =
// mesai deposu, mesai kilidi (tx'in İLK sorgusu), kesin hakediş reddi,
// taslak hakedişe needs_recalculation, istek_id çift gönderim koruması.
// Düzenleme YOK; yanlış kayıt gerekçeyle iptal edilir (soft).
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_faz8b.php';

defined('PDKS_SERVIS_MAX_ADET') || define('PDKS_SERVIS_MAX_ADET', 99);
defined('PDKS_SERVIS_TEKRAR_HATA') || define('PDKS_SERVIS_TEKRAR_HATA', 'Bu servis kaydı zaten gönderildi (tekrar gönderim); sayfayı yenileyin.');
defined('PDKS_SERVIS_FINAL_HATA') || define('PDKS_SERVIS_FINAL_HATA', 'Bu mesainin kesinleşmiş hakedişi bulunmaktadır. Önce hakedişi yeniden açın.');

/** Servis türleri — TEK kaynak (kod, satır adı, fiyat kolonu). */
function pdks_servis_turleri(): array
{
    return [
        'BUYUK' => ['ad' => 'Büyük', 'kod' => 'SERVIS_BUYUK', 'satir' => 'Servis — Büyük', 'kolon' => 'big_rate'],
        'KUCUK' => ['ad' => 'Küçük', 'kod' => 'SERVIS_KUCUK', 'satir' => 'Servis — Küçük', 'kolon' => 'small_rate'],
    ];
}

/** Hakediş satırı servis satırı mı? (gösterim: "Kişi" yerine "Adet") */
function pdks_servis_satiri_mi(array $satir): bool
{
    return in_array((string)($satir['worker_type_code_snapshot'] ?? ''), ['SERVIS_BUYUK', 'SERVIS_KUCUK'], true);
}

// =========================================================
// ŞEMA
// =========================================================

function pdks_servis_tablolar(): array
{
    $t = [];
    $t['foreman_service_rates'] = "CREATE TABLE IF NOT EXISTS `foreman_service_rates` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `foreman_id`         INT           NOT NULL,
        `big_rate`           DECIMAL(12,2) NULL DEFAULT NULL,
        `small_rate`         DECIMAL(12,2) NULL DEFAULT NULL,
        `currency`           VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `valid_from`         DATE          NOT NULL,
        `valid_to`           DATE          NULL DEFAULT NULL,
        `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
        `created_by_user_id` INT           NULL DEFAULT NULL,
        `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         DATETIME      NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_fsr_foreman_from` (`foreman_id`, `valid_from`),
        CONSTRAINT `fk_fsr_foreman` FOREIGN KEY (`foreman_id`)
            REFERENCES `foremen`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $t['daily_session_services'] = "CREATE TABLE IF NOT EXISTS `daily_session_services` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `session_id`         INT           NOT NULL,
        `foreman_id`         INT           NOT NULL,
        `work_date`          DATE          NOT NULL,
        `depo`               VARCHAR(150)  NOT NULL DEFAULT '',
        `service_type`       VARCHAR(10)   NOT NULL,
        `quantity`           INT           NOT NULL,
        `note`               VARCHAR(500)  NULL DEFAULT NULL,
        `batch_id`           VARCHAR(32)   NULL DEFAULT NULL,
        `istek_id`           VARCHAR(64)   NULL DEFAULT NULL,
        `created_by_user_id` INT           NULL DEFAULT NULL,
        `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_voided`          TINYINT(1)    NOT NULL DEFAULT 0,
        `voided_at`          DATETIME      NULL DEFAULT NULL,
        `voided_by_user_id`  INT           NULL DEFAULT NULL,
        `void_reason`        VARCHAR(500)  NULL DEFAULT NULL,
        UNIQUE KEY `uq_dss_istek` (`istek_id`),
        INDEX `idx_dss_session` (`session_id`, `is_voided`),
        INDEX `idx_dss_foreman_date` (`foreman_id`, `work_date`),
        CONSTRAINT `fk_dss_session` FOREIGN KEY (`session_id`)
            REFERENCES `daily_work_sessions`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return $t;
}

function pdks_servis_tablo_var(PDO $pdo, string $tablo): bool
{
    try { $pdo->query("SELECT 1 FROM `{$tablo}` LIMIT 0"); return true; }
    catch (PDOException $e) { return false; }
}

function pdks_servis_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $eksik = array_values(array_filter(['foremen', 'daily_work_sessions'], fn($o) => !pdks_servis_tablo_var($pdo, $o)));
    $rapor = [];
    foreach (pdks_servis_tablolar() as $ad => $sql) {
        if ($eksik) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'atlandi', 'mesaj' => 'Önkoşul tablo eksik: ' . implode(', ', $eksik) . '.'];
            continue;
        }
        if (pdks_servis_tablo_var($pdo, $ad)) {
            $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.'];
            continue;
        }
        try {
            $pdo->exec($sql);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[pdks_servis_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()];
        }
    }
    return $rapor;
}

function pdks_servis_sema_hazir(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? db();
    return pdks_servis_tablo_var($pdo, 'foreman_service_rates')
        && pdks_servis_tablo_var($pdo, 'daily_session_services');
}

// =========================================================
// FİYAT (çavuş bazında, tarihli — foreman_daily_rates emsali)
// =========================================================

/** Boş girdi = null (o tür fiyatsız); dolu ama geçersiz = false. */
function pdks_servis_fiyat_girdi(string $ham)
{
    if (trim($ham) === '') return null;
    $k = pdks_hakedis_girdi_kurus($ham);
    return ($k === null || $k <= 0) ? false : $k;
}

function pdks_servis_ucret_ekle(
    int $foremanId, string $buyukHam, string $kucukHam, string $validFrom, ?string $currency,
    int $userId, ?PDO $pdo = null
): array {
    $pdo = $pdo ?? db();
    if (!pdks_servis_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Servis Ücreti tabloları kurulmamış (migrate.php).'];
    $currency = trim((string)$currency) ?: 'TRY';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || !strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Geçerlilik başlangıç tarihi geçersiz.'];
    }
    $buyuk = pdks_servis_fiyat_girdi($buyukHam);
    $kucuk = pdks_servis_fiyat_girdi($kucukHam);
    if ($buyuk === false) return ['ok' => false, 'hata' => 'Büyük servis fiyatı geçersiz.'];
    if ($kucuk === false) return ['ok' => false, 'hata' => 'Küçük servis fiyatı geçersiz.'];
    if ($buyuk === null && $kucuk === null) return ['ok' => false, 'hata' => 'En az bir servis fiyatı (Büyük ya da Küçük) girilmelidir.'];

    $stC = $pdo->prepare("SELECT id FROM foremen WHERE id = ?");
    $stC->execute([$foremanId]);
    if (!$stC->fetchColumn()) return ['ok' => false, 'hata' => 'Çavuş bulunamadı.'];

    $stM = $pdo->prepare("SELECT * FROM foreman_service_rates WHERE foreman_id = ? AND is_active = 1 ORDER BY valid_from DESC, id DESC LIMIT 1");
    $stM->execute([$foremanId]);
    $mevcut = $stM->fetch();
    if ($mevcut && strtotime((string)$mevcut['valid_from']) >= strtotime($validFrom)) {
        return ['ok' => false, 'hata' => 'Yeni başlangıç tarihi mevcut en son servis fiyatı döneminden sonra olmalıdır.'];
    }

    $pdo->beginTransaction();
    try {
        if ($mevcut && (($mevcut['valid_to'] ?? null) === null || strtotime((string)$mevcut['valid_to']) >= strtotime($validFrom))) {
            $pdo->prepare("UPDATE foreman_service_rates SET valid_to = ? WHERE id = ?")
                ->execute([date('Y-m-d', strtotime($validFrom . ' -1 day')), (int)$mevcut['id']]);
        }
        $pdo->prepare(
            "INSERT INTO foreman_service_rates
                (foreman_id, big_rate, small_rate, currency, valid_from, valid_to, is_active, created_by_user_id)
             VALUES (?,?,?,?,?,NULL,1,?)"
        )->execute([
            $foremanId,
            $buyuk !== null ? pdks_hakedis_kurus_tl($buyuk) : null,
            $kucuk !== null ? pdks_hakedis_kurus_tl($kucuk) : null,
            $currency, $validFrom, $userId,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'hata' => 'Servis fiyatı kaydedilemedi: ' . $e->getMessage()];
    }

    // v300: bu fiyat dönemine düşen servisi olan TASLAK hakedişler yeniden hesaplama
    // ister (fiyat yokken servis kaydedilebildiği için). Kesinleşmiş hakedişe dokunulmaz.
    $isaretlenen = pdks_servis_taslaklari_isaretle($foremanId, $validFrom, $pdo);

    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'foreman_service_rates', $id, null, [
            'foreman_id' => $foremanId,
            'big_rate' => $buyuk !== null ? pdks_hakedis_kurus_tl($buyuk) : null,
            'small_rate' => $kucuk !== null ? pdks_hakedis_kurus_tl($kucuk) : null,
            'currency' => $currency, 'valid_from' => $validFrom,
        ]);
    }
    return ['ok' => true, 'id' => $id, 'isaretlenen' => $isaretlenen];
}

function pdks_servis_ucret_gecerli(int $foremanId, string $tarih, ?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_tablo_var($pdo, 'foreman_service_rates')) return null;
    $st = $pdo->prepare(
        "SELECT * FROM foreman_service_rates
          WHERE foreman_id = ? AND is_active = 1
            AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
          ORDER BY valid_from DESC, id DESC LIMIT 1"
    );
    $st->execute([$foremanId, $tarih, $tarih]);
    return $st->fetch() ?: null;
}

function pdks_servis_ucret_gecmisi(int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_tablo_var($pdo, 'foreman_service_rates')) return [];
    $st = $pdo->prepare("SELECT * FROM foreman_service_rates WHERE foreman_id = ? ORDER BY valid_from DESC, id DESC");
    $st->execute([$foremanId]);
    return $st->fetchAll();
}

/**
 * v300: çavuşun, `$tarihtenBeri` (dahil) tarihli iptal edilmemiş servisi olan mesailerinin
 * TASLAK hakedişlerini needs_recalculation=1 yapar. Hakediş tablosu/kolonu ya da faz8j
 * yoksa sessizce 0 döner (özellik opsiyonel). Kesin hakedişe DOKUNMAZ.
 */
function pdks_servis_taslaklari_isaretle(int $foremanId, string $tarihtenBeri, PDO $pdo): int
{
    if (!pdks_servis_tablo_var($pdo, 'daily_session_services')) return 0;
    require_once __DIR__ . '/pdks_faz8j.php';
    $st = $pdo->prepare("SELECT DISTINCT session_id FROM daily_session_services
                          WHERE foreman_id = ? AND is_voided = 0 AND work_date >= ?");
    $st->execute([$foremanId, $tarihtenBeri]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        try { pdks_faz8j_yeniden_hesap_isaretle($pdo, (int)$sid); $n++; } catch (Throwable $e) { /* hesap ekranı zaten durumu gösterir */ }
    }
    return $n;
}

/**
 * v300: çavuşun fiyatı (geçerli dönemi) OLMAYAN iptal edilmemiş servislerinin en eski
 * mesai tarihi (Y-m-d) ya da null. Fiyat formunda "geçerlilik başlangıcı" önerisi içindir.
 */
function pdks_servis_fiyatsiz_en_eski_tarih(int $foremanId, ?PDO $pdo = null): ?string
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_tablo_var($pdo, 'daily_session_services')) return null;
    $st = $pdo->prepare("SELECT work_date, service_type FROM daily_session_services
                          WHERE foreman_id = ? AND is_voided = 0 GROUP BY work_date, service_type ORDER BY work_date ASC");
    $st->execute([$foremanId]);
    foreach ($st->fetchAll() as $r) {
        $u = pdks_servis_ucret_gecerli($foremanId, (string)$r['work_date'], $pdo);
        $kolonTur = ((string)$r['service_type'] === 'BUYUK') ? 'BUYUK' : 'KUCUK';
        if (pdks_servis_birim_kurus($u, $kolonTur) === null) return (string)$r['work_date'];
    }
    return null;
}

/** Geçerli fiyat satırında bu türün fiyatı (kuruş) — yoksa null. */
function pdks_servis_birim_kurus(?array $ucret, string $tur): ?int
{
    $kolon = pdks_servis_turleri()[$tur]['kolon'] ?? null;
    if (!$ucret || $kolon === null) return null;
    $ham = $ucret[$kolon] ?? null;
    if ($ham === null || $ham === '') return null;
    try { $k = pdks_hakedis_tl_kurus((string)$ham); } catch (Throwable $e) { return null; }
    return $k > 0 ? $k : null;
}

// =========================================================
// OKUMA
// =========================================================

/** Mesainin iptal edilmemiş servis adetleri: ['BUYUK' => n, 'KUCUK' => n]. */
function pdks_servis_toplamlar(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $t = array_fill_keys(array_keys(pdks_servis_turleri()), 0);
    if (!pdks_servis_tablo_var($pdo, 'daily_session_services')) return $t;
    $st = $pdo->prepare("SELECT service_type, COALESCE(SUM(quantity),0) AS n FROM daily_session_services
                          WHERE session_id = ? AND is_voided = 0 GROUP BY service_type");
    $st->execute([$sessionId]);
    foreach ($st->fetchAll() as $r) {
        if (isset($t[(string)$r['service_type']])) $t[(string)$r['service_type']] = (int)$r['n'];
    }
    return $t;
}

/** v313: birden çok mesainin iptal edilmemiş servis adetleri toplamı (tek sorgu). */
function pdks_servis_toplamlar_toplu(array $sessionIds, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $t = array_fill_keys(array_keys(pdks_servis_turleri()), 0);
    $sessionIds = array_values(array_unique(array_filter(array_map('intval', $sessionIds), static fn($i) => $i > 0)));
    if (!$sessionIds || !pdks_servis_tablo_var($pdo, 'daily_session_services')) return $t;
    $st = $pdo->prepare("SELECT service_type, COALESCE(SUM(quantity),0) AS n FROM daily_session_services
                          WHERE is_voided = 0 AND session_id IN (" . implode(',', array_fill(0, count($sessionIds), '?')) . ") GROUP BY service_type");
    $st->execute($sessionIds);
    foreach ($st->fetchAll() as $r) {
        if (isset($t[(string)$r['service_type']])) $t[(string)$r['service_type']] = (int)$r['n'];
    }
    return $t;
}

/** Mesai Detayı listesi (iptaller dahil, yeniden eskiye). */
function pdks_servis_listele(int $sessionId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_tablo_var($pdo, 'daily_session_services')) return [];
    $st = $pdo->prepare("SELECT * FROM daily_session_services WHERE session_id = ? ORDER BY created_at DESC, id DESC");
    $st->execute([$sessionId]);
    $rows = $st->fetchAll();
    $turler = pdks_servis_turleri();
    foreach ($rows as &$r) {
        $r['tur_ad'] = $turler[(string)$r['service_type']]['ad'] ?? (string)$r['service_type'];
        $r['ekleyen'] = pdks_gunluk_kullanici_adi($r['created_by_user_id'] !== null ? (int)$r['created_by_user_id'] : null, $pdo);
        $r['iptal_eden'] = (int)$r['is_voided'] === 1
            ? pdks_gunluk_kullanici_adi($r['voided_by_user_id'] !== null ? (int)$r['voided_by_user_id'] : null, $pdo) : '';
    }
    unset($r);
    return $rows;
}

/**
 * Hakediş motoru için servis satırları (pdks_faz8b_hakedis_hesapla çağırır).
 * Tablo yoksa / servis yoksa boş. Fiyat yoksa eksik → hesap DURUR (fail-closed).
 * @return array{satirlar:array, eksikler:array, para:?string, toplam_kurus:int}
 */
function pdks_servis_hakedis_satirlari(array $oturum, PDO $pdo): array
{
    $sonuc = ['satirlar' => [], 'eksikler' => [], 'para' => null, 'toplam_kurus' => 0];
    if (!pdks_servis_sema_hazir($pdo)) return $sonuc;
    $adet = pdks_servis_toplamlar((int)$oturum['id'], $pdo);
    if (array_sum($adet) < 1) return $sonuc;
    $ucret = pdks_servis_ucret_gecerli((int)$oturum['foreman_id'], (string)$oturum['work_date'], $pdo);
    foreach (pdks_servis_turleri() as $tur => $tanim) {
        $n = (int)($adet[$tur] ?? 0);
        if ($n < 1) continue;
        $birim = pdks_servis_birim_kurus($ucret, $tur);
        if ($birim === null) {
            $sonuc['eksikler'][] = $tanim['satir'] . ' — geçerli fiyat yok';  // v300: Çavuş Ücretleri → Servis Ücreti'nden tanımlanınca hesaplanır
            continue;
        }
        $sonuc['para'] = trim((string)($ucret['currency'] ?? 'TRY')) ?: 'TRY';
        $satirKurus = $n * $birim;
        $sonuc['toplam_kurus'] += $satirKurus;
        $sonuc['satirlar'][] = [
            'work_period_id' => null,
            'worker_type_id' => null,
            'worker_type_code_snapshot' => $tanim['kod'],
            'worker_type_name_snapshot' => $tanim['satir'],
            'attendance_class_snapshot' => 'tam',
            'worker_count' => $n,
            'unit_rate' => pdks_hakedis_kurus_tl($birim),
            'overtime_hours' => 0,
            'overtime_mode_snapshot' => null,
            'overtime_unit_rate' => '0.00',
            'overtime_total' => '0.00',
            'line_total' => pdks_hakedis_kurus_tl($satirKurus),
        ];
    }
    return $sonuc;
}

function pdks_servis_istek_kayitli(PDO $pdo, string $istekId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM daily_session_services WHERE istek_id = ? LIMIT 1");
    $st->execute([$istekId]);
    if ($st->fetchColumn()) return true;
    $st = $pdo->prepare("SELECT 1 FROM audit_log WHERE action = 'servis_ekle' AND new_values LIKE ? LIMIT 1");
    $st->execute(['%"istek_id":"' . $istekId . '"%']);
    return (bool)$st->fetchColumn();
}

// =========================================================
// YAZMA (yalnız admin — Faz 8J kapıları)
// =========================================================

/**
 * Mesaiye servis ekler (Büyük + Küçük tek gönderimde; tür başına 1 satır,
 * ortak batch_id; istek_id YALNIZ ilk satırda — UNIQUE çift gönderimi keser).
 * @return array{ok:bool, hata?:string, tekrar?:bool, batch_id?:string, buyuk?:int, kucuk?:int, fiyatsiz?:string[]}
 */
function pdks_servis_ekle(int $sessionId, int $buyuk, int $kucuk, string $note, string $istekId, int $user, ?PDO $pdo = null): array
{
    require_once __DIR__ . '/pdks_faz8j.php';
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    if (!pdks_servis_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Servis Ücreti tabloları kurulmamış (migrate.php).'];
    $ist = pdks_faz8j_istek_id($istekId);
    if (!$ist['ok'] || $ist['istek_id'] === null) return ['ok' => false, 'hata' => 'Geçersiz ya da eksik istek anahtarı; pencereyi kapatıp yeniden açın.'];
    $istekId = $ist['istek_id'];
    if ($buyuk < 0 || $kucuk < 0 || $buyuk > PDKS_SERVIS_MAX_ADET || $kucuk > PDKS_SERVIS_MAX_ADET) {
        return ['ok' => false, 'hata' => 'Servis adedi 0–' . PDKS_SERVIS_MAX_ADET . ' arasında olmalıdır.'];
    }
    if ($buyuk + $kucuk < 1) return ['ok' => false, 'hata' => 'En az bir servis (Büyük ya da Küçük) girilmelidir.'];
    $note = trim($note);
    if (mb_strlen($note) > 500) return ['ok' => false, 'hata' => 'Not en fazla 500 karakter olabilir.'];

    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?');
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'hata' => 'Mesai bulunamadı.'];
    if ($e = pdks_faz8j_aktif_depo_kontrol((string)$oturum['depo'])) return ['ok' => false, 'hata' => $e];
    if ((string)$oturum['work_date'] > date('Y-m-d')) return ['ok' => false, 'hata' => 'Gelecek tarihli mesaiye servis eklenemez.'];

    // v300 (sahip kararı): fiyat tanımsızken de giriş YAPILIR — servis fiilen kalkıyor,
    // fiyat sonradan girilir. Hakediş fiyat yokken eksik sayar ve DURUR (fail-closed,
    // işçi fiyatı olmayan mesai ile aynı kural); fiyat tanımlanınca hesaplanır.
    $ucret = pdks_servis_ucret_gecerli((int)$oturum['foreman_id'], (string)$oturum['work_date'], $pdo);
    $adetler = ['BUYUK' => $buyuk, 'KUCUK' => $kucuk];
    $fiyatsiz = [];
    foreach (pdks_servis_turleri() as $tur => $tanim) {
        if ($adetler[$tur] > 0 && pdks_servis_birim_kurus($ucret, $tur) === null) $fiyatsiz[] = $tanim['ad'];
    }
    if (pdks_servis_istek_kayitli($pdo, $istekId)) return ['ok' => false, 'hata' => PDKS_SERVIS_TEKRAR_HATA, 'tekrar' => true];

    try {
        $pdo->beginTransaction();
        pdks_faz8j_mesai_kilitle($pdo, $sessionId);   // İLK sorgu — bkz. pdks_faz8j_oturum_kilitle docblock
        if (pdks_servis_istek_kayitli($pdo, $istekId)) throw new RuntimeException(PDKS_SERVIS_TEKRAR_HATA);
        if (pdks_faz8j_entitlement($pdo, $sessionId) === 'final') throw new RuntimeException(PDKS_SERVIS_FINAL_HATA);
        $batchId = 'SV' . date('Ymd') . bin2hex(random_bytes(4));
        $ins = $pdo->prepare('INSERT INTO daily_session_services
            (session_id, foreman_id, work_date, depo, service_type, quantity, note, batch_id, istek_id, created_by_user_id, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $ids = []; $ilk = true; $simdi = date('Y-m-d H:i:s');
        foreach ($adetler as $tur => $n) {
            if ($n < 1) continue;
            $ins->execute([$sessionId, (int)$oturum['foreman_id'], (string)$oturum['work_date'], (string)$oturum['depo'],
                $tur, $n, $note !== '' ? $note : null, $batchId, $ilk ? $istekId : null, $user, $simdi]);
            $ids[] = (int)$pdo->lastInsertId();
            $ilk = false;
        }
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sessionId);
        pdks_faz8j_audit($pdo, $user, 'servis_ekle', $sessionId, [], [
            'session_id' => $sessionId, 'batch_id' => $batchId, 'istek_id' => $istekId,
            'buyuk' => $buyuk, 'kucuk' => $kucuk, 'note' => $note, 'servis_ids' => $ids,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'batch_id' => $batchId, 'buyuk' => $buyuk, 'kucuk' => $kucuk, 'fiyatsiz' => $fiyatsiz];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof RuntimeException && !$x instanceof PDOException) {
            return ['ok' => false, 'hata' => $x->getMessage()] + ($x->getMessage() === PDKS_SERVIS_TEKRAR_HATA ? ['tekrar' => true] : []);
        }
        if ($x instanceof PDOException && (string)$x->getCode() === '23000') {
            return ['ok' => false, 'hata' => PDKS_SERVIS_TEKRAR_HATA, 'tekrar' => true];
        }
        return ['ok' => false, 'hata' => pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'Servis kaydı sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}

/**
 * v300: servis_ekle sonrası kullanıcıya gösterilecek özet mesaj. Metin burada durur çünkü
 * Mesai Detayı sayfası para/fiyat sözcüğü taşımaz (pdks_gunluk_faz3_static_smoke).
 * @param array $sonuc pdks_servis_ekle() başarı sonucu
 */
function pdks_servis_ekle_mesaji(array $sonuc): string
{
    $parca = trim(((int)$sonuc['buyuk'] > 0 ? (int)$sonuc['buyuk'] . ' Büyük ' : '') . ((int)$sonuc['kucuk'] > 0 ? (int)$sonuc['kucuk'] . ' Küçük' : ''));
    $mesaj = 'Servis eklendi (' . $parca . '). ';
    if (!empty($sonuc['fiyatsiz'])) {
        return $mesaj . '⚠ ' . implode(' ve ', $sonuc['fiyatsiz']) . ' servis fiyatı tanımlı değil — kayıt tutuldu; fiyat Çavuş Ücretleri\'nden tanımlanınca '
            . 'hakedişte hesaplanır (o zamana kadar bu mesainin hakedişi hesaplanamaz).';
    }
    return $mesaj . 'Taslak hakediş yeniden hesaplanmalıdır.';
}

/** Servis kaydını iptal eder (soft, gerekçe zorunlu). Kayıt BU mesaiye ait olmalı. */
function pdks_servis_iptal(int $servisId, int $sessionId, string $reason, int $user, ?PDO $pdo = null): array
{
    require_once __DIR__ . '/pdks_faz8j.php';
    $pdo = $pdo ?? db();
    if ($e = pdks_faz8j_yetki()) return ['ok' => false, 'hata' => $e];
    if (!pdks_servis_sema_hazir($pdo)) return ['ok' => false, 'hata' => 'Servis Ücreti tabloları kurulmamış (migrate.php).'];
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) return ['ok' => false, 'hata' => 'İptal nedeni zorunludur ve en fazla 500 karakter olabilir.'];
    $st = $pdo->prepare('SELECT * FROM daily_work_sessions WHERE id = ?');
    $st->execute([$sessionId]);
    $oturum = $st->fetch();
    if (!$oturum) return ['ok' => false, 'hata' => 'Mesai bulunamadı.'];
    if ($e = pdks_faz8j_aktif_depo_kontrol((string)$oturum['depo'])) return ['ok' => false, 'hata' => $e];

    try {
        $pdo->beginTransaction();
        pdks_faz8j_mesai_kilitle($pdo, $sessionId);   // İLK sorgu
        $sr = $pdo->prepare('SELECT * FROM daily_session_services WHERE id = ? AND session_id = ?');
        $sr->execute([$servisId, $sessionId]);
        $kayit = $sr->fetch();
        if (!$kayit) throw new RuntimeException('Servis kaydı bu mesaiye ait değil ya da bulunamadı.');
        if ((int)$kayit['is_voided'] === 1) throw new RuntimeException('Bu servis kaydı zaten iptal edilmiş.');
        if (pdks_faz8j_entitlement($pdo, $sessionId) === 'final') throw new RuntimeException(PDKS_SERVIS_FINAL_HATA);
        $simdi = date('Y-m-d H:i:s');
        $up = $pdo->prepare('UPDATE daily_session_services SET is_voided = 1, voided_at = ?, voided_by_user_id = ?, void_reason = ? WHERE id = ? AND is_voided = 0');
        $up->execute([$simdi, $user, $reason, $servisId]);
        if ($up->rowCount() !== 1) throw new RuntimeException(PDKS_FAZ8J_ESZAMANLI_HATA);
        pdks_faz8j_yeniden_hesap_isaretle($pdo, $sessionId);
        pdks_faz8j_audit($pdo, $user, 'servis_iptal', $sessionId, [
            'servis_id' => $servisId, 'service_type' => (string)$kayit['service_type'], 'quantity' => (int)$kayit['quantity'], 'is_voided' => 0,
        ], [
            'session_id' => $sessionId, 'servis_id' => $servisId, 'service_type' => (string)$kayit['service_type'],
            'quantity' => (int)$kayit['quantity'], 'batch_id' => (string)($kayit['batch_id'] ?? ''), 'reason' => $reason,
        ], 'daily_work_sessions');
        $pdo->commit();
        return ['ok' => true, 'servis_id' => $servisId];
    } catch (Throwable $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($x instanceof RuntimeException && !$x instanceof PDOException) return ['ok' => false, 'hata' => $x->getMessage()];
        return ['ok' => false, 'hata' => pdks_faz8j_eszamanli_hata($x) ? PDKS_FAZ8J_ESZAMANLI_HATA : 'İptal sırasında teknik bir hata oluştu. Lütfen tekrar deneyin.'];
    }
}
