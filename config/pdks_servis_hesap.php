<?php
// =========================================================
// config/pdks_servis_hesap.php — SERVİS HESABI (servisçi cari; v532)
//
// Servis, çavuş hakedişinden/bakiyesinden AYRI hesaplaşılır. Girişi eskisi gibi
// çavuşun mesaisinde (Mesai Detayı › Servis Ücreti, daily_session_services)
// yapılır; ama tutarı çavuş hakedişine YAZILMAZ (bkz. PDKS_SERVIS_HAKEDISTE).
//
// Bu dosya:
//  • günlük servis dökümü (adet × çavuşun o gün geçerli servis fiyatı) — CANLI türetilir,
//    ikinci bir tutar kopyası YOK;
//  • servisçiye ödeme defteri (servis_odemeleri; DEĞİŞMEZ — yalnız gerekçeli iptal, SİLME YOK);
//  • bakiye = servis tutarı − geçerli ödemeler (para birimi başına AYRI).
//
// Çift ödeme koruması: v532 ÖNCESİ kesinleşmiş hakedişlerde SERVIS_* satırı çavuşa
// ödenmiş sayılır; o mesailer burada "çavuş hakedişinde" diye işaretlenir ve
// servisçi bakiyesine KATILMAZ.
//
// Tablo kendi migrate çiftiyle gelir (yalnız migrate.php › «Servis Ödemeleri» kartı,
// açık GO ile); tablo yoksa ödeme bölümü gizli, döküm yine çalışır.
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/pdks_servis.php';

function pdks_servis_odeme_tablolar(): array
{
    return ['servis_odemeleri' => "CREATE TABLE IF NOT EXISTS `servis_odemeleri` (
        `id`                   INT AUTO_INCREMENT PRIMARY KEY,
        `payment_date`         DATE          NOT NULL,
        `amount`               DECIMAL(14,2) NOT NULL,
        `currency`             VARCHAR(10)   NOT NULL DEFAULT 'TRY',
        `payment_method`       VARCHAR(20)   NOT NULL DEFAULT 'OTHER',
        `reference_no`         VARCHAR(100)  NULL DEFAULT NULL,
        `description`          VARCHAR(500)  NULL DEFAULT NULL,
        `depo`                 VARCHAR(150)  NOT NULL DEFAULT '',
        `status`               VARCHAR(20)   NOT NULL DEFAULT 'valid',
        `created_by_user_id`   INT           NULL DEFAULT NULL,
        `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `cancelled_at`         DATETIME      NULL DEFAULT NULL,
        `cancelled_by_user_id` INT           NULL DEFAULT NULL,
        `cancellation_reason`  VARCHAR(500)  NULL DEFAULT NULL,
        INDEX `idx_so_date` (`payment_date`),
        INDEX `idx_so_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
}

function pdks_servis_odeme_migrate(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $rapor = [];
    foreach (pdks_servis_odeme_tablolar() as $ad => $sql) {
        if (pdks_servis_tablo_var($pdo, $ad)) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try { $pdo->exec($sql); $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']; }
        catch (PDOException $e) { error_log('[pdks_servis_odeme_migrate] ' . $e->getMessage()); $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => $e->getMessage()]; }
    }
    return $rapor;
}

function pdks_servis_odeme_hazir(?PDO $pdo = null): bool
{
    return pdks_servis_tablo_var($pdo ?? db(), 'servis_odemeleri');
}

/** Depo koşulu: aktif depo + boş depolu kayıt (Atanmamış veri kuralı). [sql, params] */
function pdks_servis_depo_kosul(?string $depo, string $kolon): array
{
    if ($depo === null || $depo === '') return ['', []];
    return [" AND ($kolon = ? OR $kolon = '')", [$depo]];
}

/**
 * Servis günlük dökümü: mesai (oturum) başına 1 satır. Salt okunur.
 * @return array<int,array{session_id:int,tarih:string,foreman_id:int,cavus:string,depo:string,adet:array,birim:array,tutar_kurus:int,para:string,fiyatsiz:array,hakedis_eski:bool}>
 */
function pdks_servis_hesap_satirlari(?string $bas, ?string $bit, ?string $depo, ?int $foremanId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_tablo_var($pdo, 'daily_session_services')) return [];
    $turler = pdks_servis_turleri();
    [$dk, $dp] = pdks_servis_depo_kosul($depo, 's.depo');
    $sql = "SELECT s.session_id, s.work_date, s.foreman_id, s.depo, f.name AS cavus, s.service_type, SUM(s.quantity) AS n
              FROM daily_session_services s LEFT JOIN foremen f ON f.id = s.foreman_id
             WHERE s.is_voided = 0" . $dk;
    $p = $dp;
    if ($bas) { $sql .= ' AND s.work_date >= ?'; $p[] = $bas; }
    if ($bit) { $sql .= ' AND s.work_date <= ?'; $p[] = $bit; }
    if ($foremanId) { $sql .= ' AND s.foreman_id = ?'; $p[] = $foremanId; }
    $sql .= ' GROUP BY s.session_id, s.work_date, s.foreman_id, s.depo, f.name, s.service_type ORDER BY s.work_date, f.name, s.session_id';
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = (int)$r['session_id']; $t = (string)$r['service_type'];
        if (!isset($turler[$t])) continue;
        $out[$sid] = $out[$sid] ?? [
            'session_id' => $sid, 'tarih' => (string)$r['work_date'], 'foreman_id' => (int)$r['foreman_id'],
            'cavus' => (string)($r['cavus'] ?? ''), 'depo' => (string)$r['depo'],
            'adet' => array_fill_keys(array_keys($turler), 0), 'birim' => array_fill_keys(array_keys($turler), null),
            'tutar_kurus' => 0, 'para' => 'TRY', 'fiyatsiz' => [], 'hakedis_eski' => false,
        ];
        $out[$sid]['adet'][$t] = (int)$r['n'];
    }
    if (!$out) return [];
    // Fiyat: çavuşun O GÜN geçerli servis fiyatı (foreman+tarih başına önbellek).
    $onbellek = [];
    foreach ($out as &$o) {
        $k = $o['foreman_id'] . '|' . $o['tarih'];
        $onbellek[$k] = $onbellek[$k] ?? pdks_servis_ucret_gecerli($o['foreman_id'], $o['tarih'], $pdo);
        $u = $onbellek[$k];
        if ($u) $o['para'] = trim((string)($u['currency'] ?? 'TRY')) ?: 'TRY';
        foreach ($turler as $t => $d) {
            if ($o['adet'][$t] < 1) continue;
            $b = pdks_servis_birim_kurus($u, $t);
            $o['birim'][$t] = $b;
            if ($b === null) $o['fiyatsiz'][] = $d['ad'];
            else $o['tutar_kurus'] += $o['adet'][$t] * $b;
        }
    }
    unset($o);
    // v532 öncesi: KESİN hakedişte SERVIS_* satırı olan mesai çavuşa ödenmiştir.
    if (pdks_servis_tablo_var($pdo, 'foreman_daily_entitlement_lines')) {
        $ids = array_keys($out);
        foreach (array_chunk($ids, 500) as $ch) {
            $stH = $pdo->prepare("SELECT DISTINCT e.session_id FROM foreman_daily_entitlements e
                                    JOIN foreman_daily_entitlement_lines l ON l.entitlement_id = e.id
                                   WHERE e.status = 'final' AND l.worker_type_code_snapshot IN ('SERVIS_BUYUK','SERVIS_KUCUK')
                                     AND e.session_id IN (" . implode(',', array_fill(0, count($ch), '?')) . ")");
            $stH->execute($ch);
            foreach ($stH->fetchAll(PDO::FETCH_COLUMN) as $sid) if (isset($out[(int)$sid])) $out[(int)$sid]['hakedis_eski'] = true;
        }
    }
    return array_values($out);
}

/** Satırları para birimi başına özetler (hakedis_eski satırlar bakiyeye girmez). */
function pdks_servis_hesap_ozet(array $satirlar): array
{
    $o = [];
    foreach ($satirlar as $s) {
        $c = $s['para'];
        $o[$c] = $o[$c] ?? ['buyuk' => 0, 'kucuk' => 0, 'tutar_kurus' => 0, 'eski_kurus' => 0];
        if ($s['hakedis_eski']) { $o[$c]['eski_kurus'] += $s['tutar_kurus']; continue; }
        $o[$c]['buyuk'] += $s['adet']['BUYUK'];
        $o[$c]['kucuk'] += $s['adet']['KUCUK'];
        $o[$c]['tutar_kurus'] += $s['tutar_kurus'];
    }
    return $o;
}

/** Ödeme listesi (iptaller dahil, yeniden eskiye). Tablo yoksa []. */
function pdks_servis_odeme_listesi(?string $bas, ?string $bit, ?string $depo, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_odeme_hazir($pdo)) return [];
    [$dk, $p] = pdks_servis_depo_kosul($depo, 'depo');
    $sql = 'SELECT * FROM servis_odemeleri WHERE 1=1' . $dk;
    if ($bas) { $sql .= ' AND payment_date >= ?'; $p[] = $bas; }
    if ($bit) { $sql .= ' AND payment_date <= ?'; $p[] = $bit; }
    $st = $pdo->prepare($sql . ' ORDER BY payment_date DESC, id DESC');
    $st->execute($p);
    return $st->fetchAll();
}

/**
 * Servisçi bakiyesi — TÜM ZAMANLAR, para birimi başına: servis tutarı − geçerli ödeme.
 * @return array<string,array{servis_kurus:int,odeme_kurus:int,bakiye_kurus:int}>
 */
function pdks_servis_bakiye(?string $depo, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $b = [];
    foreach (pdks_servis_hesap_ozet(pdks_servis_hesap_satirlari(null, null, $depo, null, $pdo)) as $c => $o) {
        $b[$c] = ['servis_kurus' => $o['tutar_kurus'], 'odeme_kurus' => 0, 'bakiye_kurus' => $o['tutar_kurus']];
    }
    foreach (pdks_servis_odeme_listesi(null, null, $depo, $pdo) as $od) {
        if ((string)$od['status'] !== 'valid') continue;
        $c = (string)$od['currency'];
        $b[$c] = $b[$c] ?? ['servis_kurus' => 0, 'odeme_kurus' => 0, 'bakiye_kurus' => 0];
        $k = pdks_hakedis_tl_kurus((string)$od['amount']);
        $b[$c]['odeme_kurus'] += $k;
        $b[$c]['bakiye_kurus'] -= $k;
    }
    return $b;
}

/** Ödeme ekler — DEĞİŞMEZ (UPDATE yolu yok; yalnız pdks_servis_odeme_iptal). */
function pdks_servis_odeme_ekle(string $tarih, string $tutarHam, string $currency, string $yontem, string $ref, string $aciklama, ?string $depo, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    if (!pdks_servis_odeme_hazir($pdo)) return ['ok' => false, 'hata' => 'Servis ödemeleri tablosu kurulmamış (migrate.php › Servis Ödemeleri).'];
    $yontem = strtoupper(trim($yontem));
    if (!in_array($yontem, ['BANK', 'CASH', 'OTHER'], true)) return ['ok' => false, 'hata' => 'Geçersiz ödeme yöntemi.'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) return ['ok' => false, 'hata' => 'Ödeme tarihi geçersiz.'];
    if ($tarih > date('Y-m-d')) return ['ok' => false, 'hata' => 'Ödeme tarihi gelecekte olamaz.'];
    $kurus = function_exists('pdks_hakedis_girdi_kurus') ? pdks_hakedis_girdi_kurus($tutarHam) : null;
    if ($kurus === null || $kurus <= 0) return ['ok' => false, 'hata' => 'Ödeme tutarı geçersiz. Örnek: 20000 veya 20000,50'];
    $currency = trim($currency) ?: 'TRY';
    $ref = mb_substr(trim($ref), 0, 100); $aciklama = mb_substr(trim($aciklama), 0, 500);
    $pdo->prepare("INSERT INTO servis_odemeleri (payment_date, amount, currency, payment_method, reference_no, description, depo, status, created_by_user_id)
                   VALUES (?,?,?,?,?,?,?, 'valid', ?)")
        ->execute([$tarih, pdks_hakedis_kurus_tl($kurus), $currency, $yontem, $ref !== '' ? $ref : null, $aciklama !== '' ? $aciklama : null, (string)$depo, $userId]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('audit_log_event')) {
        audit_log_event('create', 'servis_odemeleri', $id, null, ['payment_date' => $tarih, 'amount' => pdks_hakedis_kurus_tl($kurus), 'currency' => $currency, 'method' => $yontem]);
    }
    return ['ok' => true, 'id' => $id];
}

/** Ödeme iptali — gerekçe zorunlu, satır SİLİNMEZ. */
function pdks_servis_odeme_iptal(int $id, string $sebep, int $userId, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $sebep = trim($sebep);
    if ($sebep === '') return ['ok' => false, 'hata' => 'İptal gerekçesi zorunludur.'];
    if (!pdks_servis_odeme_hazir($pdo)) return ['ok' => false, 'hata' => 'Servis ödemeleri tablosu kurulmamış.'];
    $st = $pdo->prepare('SELECT * FROM servis_odemeleri WHERE id = ?');
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) return ['ok' => false, 'hata' => 'Ödeme kaydı bulunamadı.'];
    if ((string)$o['status'] === 'cancelled') return ['ok' => false, 'hata' => 'Bu ödeme zaten iptal edilmiş.'];
    $pdo->prepare("UPDATE servis_odemeleri SET status='cancelled', cancelled_at=?, cancelled_by_user_id=?, cancellation_reason=? WHERE id=? AND status='valid'")
        ->execute([date('Y-m-d H:i:s'), $userId, mb_substr($sebep, 0, 500), $id]);
    if (function_exists('audit_log_event')) audit_log_event('cancel', 'servis_odemeleri', $id, $o, ['sebep' => $sebep]);
    return ['ok' => true];
}
