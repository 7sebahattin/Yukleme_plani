<?php
// =========================================================
// config/siparis_odeme.php — OGL'den gelen ÖDEMELER (genel cari tahsilat defteri; v399)
// siparis_core.php sonunda yüklenir. Şema yalnız migrate.php "Sipariş ödemeleri" kartından (core'a EKLENMEZ, ALTER yok).
// Ödeme siparişe/sevkiyata BAĞLANMAZ: genel cariye girilir; Cari Döküm'de para birimi başına Tahsilat'a düşer.
// Satır SİLİNMEZ: iptal = soft (gerekçe zorunlu). Tutar kuruş (tam sayı). Para birimleri asla toplanmaz.
// Yazma kapısı: admin ya da hesap.pay (muhasebe) — yeni yetki yok.
// =========================================================
declare(strict_types=1);

/** @return array<string,string> tablo adı → CREATE */
function siparis_odeme_tablolar(): array
{
    return ['siparis_odemeler' => "CREATE TABLE IF NOT EXISTS `siparis_odemeler` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `odeme_tarihi` DATE         NOT NULL,
        `tutar_kurus`  BIGINT       NOT NULL,
        `para_birimi`  VARCHAR(3)   NOT NULL,
        `aciklama`     VARCHAR(255) NULL DEFAULT NULL,
        `dekont_no`    VARCHAR(60)  NULL DEFAULT NULL,
        `istek_id`     VARCHAR(40)  NULL DEFAULT NULL,
        `created_by`   INT          NULL DEFAULT NULL,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `iptal_at`     DATETIME     NULL DEFAULT NULL,
        `iptal_by`     INT          NULL DEFAULT NULL,
        `iptal_nedeni` VARCHAR(255) NULL DEFAULT NULL,
        UNIQUE KEY `uq_sod_istek` (`istek_id`),
        KEY `idx_sod_tarih` (`odeme_tarihi`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC"];
}

function siparis_odeme_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        $pdo->query('SELECT id FROM siparis_odemeler LIMIT 0');
        return $var[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> durum: var|olusturuldu|hata */
function siparis_odeme_migrate(PDO $pdo): array
{
    $rapor = [];
    foreach (siparis_odeme_tablolar() as $ad => $ddl) {
        if (siparis_tablo_var($pdo, $ad)) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[siparis_odeme_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

/** Yazma kapısı (TEK yer): admin ya da hesap.pay. */
function siparis_odeme_yetkili(): bool
{
    return (function_exists('is_admin') && is_admin()) || (function_exists('can') && can('hesap.pay'));
}

function siparis_odeme_para_birimleri(): array { return ['EUR', 'USD', 'TRY', 'GBP']; }

/** "1.234,56" / "1234,5" / "1234.56" / "1.234.567" → kuruş; geçersiz/≤0/≥1e10 → null. */
function siparis_odeme_tutar_coz(string $s): ?int
{
    $s = preg_replace('/\s+/', '', $s) ?? '';
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (!preg_match('/^\d+\.\d{1,2}$/', $s)) $s = str_replace('.', '', $s);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
    [$tl, $kr] = array_pad(explode('.', $s), 2, '0');
    if (strlen($tl) > 10) return null;
    $kurus = (int)$tl * 100 + (int)str_pad($kr, 2, '0');
    return ($kurus > 0 && $kurus < 10000000000 * 100) ? $kurus : null;
}

/** @return array{ok:bool,mesaj:string,id:?int} */
function siparis_odeme_ekle(PDO $pdo, array $in, int $uid): array
{
    $h = static fn(string $m): array => ['ok' => false, 'mesaj' => $m, 'id' => null];
    if (!siparis_odeme_hazir($pdo)) return $h('Ödeme tablosu kurulmadı (migrate.php).');
    $kurus = siparis_odeme_tutar_coz((string)($in['tutar'] ?? ''));
    if ($kurus === null) return $h('Tutar geçersiz.');
    $tarih = siparis_tarih_coz((string)($in['tarih'] ?? ''));
    if ($tarih === null) return $h('Ödeme tarihi geçersiz.');
    if ($tarih > date('Y-m-d')) return $h('Ödeme tarihi gelecekte olamaz.');
    $pb = strtoupper(trim((string)($in['para_birimi'] ?? '')));
    if (!in_array($pb, siparis_odeme_para_birimleri(), true)) return $h('Para birimi geçersiz.');
    $ac = trim((string)($in['aciklama'] ?? '')); $dk = trim((string)($in['dekont_no'] ?? ''));
    if (mb_strlen($ac) > 255 || mb_strlen($dk) > 60) return $h('Açıklama en çok 255, dekont no en çok 60 karakter olabilir.');
    $istek = preg_match('/^[A-Za-z0-9_-]{8,40}$/', (string)($in['istek_id'] ?? '')) ? (string)$in['istek_id'] : null;
    try {
        $st = $pdo->prepare('INSERT INTO siparis_odemeler (odeme_tarihi, tutar_kurus, para_birimi, aciklama, dekont_no, istek_id, created_by) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$tarih, $kurus, $pb, $ac !== '' ? $ac : null, $dk !== '' ? $dk : null, $istek, $uid]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return ['ok' => true, 'mesaj' => 'Bu ödeme zaten kaydedilmiş (tekrar gönderim).', 'id' => null];
        error_log('[siparis_odeme_ekle] ' . $e->getMessage());
        return $h('Ödeme kaydedilemedi.');
    }
    $id = (int)$pdo->lastInsertId();
    siparis_audit('siparis_odeme_ekle', $id, null, ['tutar_kurus' => $kurus, 'para_birimi' => $pb, 'tarih' => $tarih], $uid);
    return ['ok' => true, 'mesaj' => 'Ödeme kaydedildi.', 'id' => $id];
}

/** @return array{ok:bool,mesaj:string} */
function siparis_odeme_iptal(PDO $pdo, int $id, string $neden, int $uid): array
{
    $neden = trim($neden);
    if ($neden === '' || mb_strlen($neden) > 255) return ['ok' => false, 'mesaj' => 'İptal gerekçesi zorunlu (en çok 255 karakter).'];
    if (!siparis_odeme_hazir($pdo)) return ['ok' => false, 'mesaj' => 'Ödeme tablosu kurulmadı.'];
    $st = $pdo->prepare('UPDATE siparis_odemeler SET iptal_at = ?, iptal_by = ?, iptal_nedeni = ? WHERE id = ? AND iptal_at IS NULL');
    $st->execute([date('Y-m-d H:i:s'), $uid, $neden, $id]);
    if ($st->rowCount() < 1) return ['ok' => false, 'mesaj' => 'Ödeme bulunamadı ya da zaten iptal.'];
    siparis_audit('siparis_odeme_iptal', $id, null, ['neden' => $neden], $uid);
    return ['ok' => true, 'mesaj' => 'Ödeme iptal edildi.'];
}

/** Dönem (bas/bit boş = sınırsız) içindeki AKTİF ödemeler, tarih sırasıyla. */
function siparis_odeme_oku(PDO $pdo, array $f): array
{
    if (!siparis_odeme_hazir($pdo)) return [];
    $w = ['iptal_at IS NULL']; $p = [];
    if (($f['bas'] ?? '') !== '') { $w[] = 'odeme_tarihi >= ?'; $p[] = (string)$f['bas']; }
    if (($f['bit'] ?? '') !== '') { $w[] = 'odeme_tarihi <= ?'; $p[] = (string)$f['bit']; }
    $st = $pdo->prepare('SELECT id, odeme_tarihi, tutar_kurus, para_birimi, aciklama, dekont_no FROM siparis_odemeler WHERE ' . implode(' AND ', $w) . ' ORDER BY odeme_tarihi, id');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,int> para birimi → toplam kuruş */
function siparis_odeme_toplam(array $odemeler): array
{
    $t = [];
    foreach ($odemeler as $o) { $pb = strtoupper((string)$o['para_birimi']); $t[$pb] = ($t[$pb] ?? 0) + (int)$o['tutar_kurus']; }
    ksort($t);
    return $t;
}
