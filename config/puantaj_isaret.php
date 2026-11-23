<?php
// =========================================================
// config/puantaj_isaret.php — Aylık Puantaj: elle gün işareti (v553)
//
// Personel sekmesinde bir günü elle D / İ / Ü / R / T olarak işaretlemek için tablo
// `puantaj_isaretler` (employee_id + tarih tekil). Şema YALNIZ migrate.php "Puantaj İşaretleri"
// kartından kurulur (pi_migrate); sayfalar/yazma ucu DDL ATMAZ, tablo yoksa pi_hazir() false döner
// ve işaretleme arayüzü gizlenir (çizelge eskisi gibi çalışır).
// Yazan TEK uç: puantaj_isaret_kaydet.php (kapılar + audit orada). Saf yardımcılar (pi_kodlar,
// pi_tarih_dogrula, pi_aciklama_temizle, pi_girdi_coz) DB/oturum bilmez → smoke testi.
// =========================================================
declare(strict_types=1);

/** Elle işaret kodları. Harf + renk birlikte (siyah-beyaz yazdırmada harfle okunur). grup: özet sütunu eşlemesi. */
function pi_kodlar(): array
{
    return [
        'D' => ['ad' => 'Devamsız',       'sinif' => 'd',  'grup' => 'devamsiz'],
        'İ' => ['ad' => 'Yıllık izin',    'sinif' => 'iz', 'grup' => 'izin'],
        'Ü' => ['ad' => 'Ücretsiz izin',  'sinif' => 'uc', 'grup' => 'izin'],
        'R' => ['ad' => 'Raporlu',        'sinif' => 'r',  'grup' => 'rapor'],
        'T' => ['ad' => 'Resmi tatil',    'sinif' => 't',  'grup' => 'tatil'],
    ];
}

/** Kod beyaz listede mi? ('' geçerli sayılmaz — temizleme çağıran tarafta ayrıca ele alınır.) */
function pi_kod_gecerli($kod): bool
{
    return is_string($kod) && $kod !== '' && isset(pi_kodlar()[$kod]);
}

/** Tarih YYYY-MM-DD, gerçek takvim günü ve bugünden en çok 2 yıl uzakta. Geçerliyse tarihi, değilse null döner. */
function pi_tarih_dogrula(string $t, ?string $bugun = null): ?string
{
    $t = trim($t);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    $bugun = $bugun ?? date('Y-m-d');
    $b = new DateTimeImmutable($bugun);
    if ($t < $b->modify('-2 years')->format('Y-m-d') || $t > $b->modify('+2 years')->format('Y-m-d')) return null;
    return $t;
}

/** Serbest açıklama: kontrol karakterleri atılır, kırpılır, en çok 200 karakter; boş → null. */
function pi_aciklama_temizle($a): ?string
{
    if (!is_string($a)) return null;
    $a = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $a) ?? '';
    $a = trim(preg_replace('/\s+/u', ' ', $a) ?? '');
    if ($a === '') return null;
    return mb_substr($a, 0, 200, 'UTF-8');
}

/**
 * İstek gövdesi: JSON (Content-Type application/json) ya da form alanları. Dönüş: dizi (geçersiz JSON → boş dizi).
 */
function pi_girdi_coz(string $ham, string $contentType, array $post): array
{
    if (str_contains(strtolower($contentType), 'application/json')) {
        $d = json_decode($ham, true);
        return is_array($d) && !array_is_list($d) ? $d : [];
    }
    return $post;
}

// ── Şema (yalnız migrate.php kartından çağrılır) ────────────

/** CREATE TABLE IF NOT EXISTS (ALTER yok; yabancı anahtar yok). SQLite çeviricisiyle uyumlu yazım. @return array<string,string> */
function pi_tablolar(): array
{
    return [
        'puantaj_isaretler' => "CREATE TABLE IF NOT EXISTS `puantaj_isaretler` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT           NOT NULL,
  `tarih`       DATE          NOT NULL,
  `kod`         VARCHAR(4)    NOT NULL,
  `aciklama`    VARCHAR(200)  NULL DEFAULT NULL,
  `user_id`     INT           NULL DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      NULL DEFAULT NULL,
  UNIQUE KEY `uq_puantaj_isaret` (`employee_id`, `tarih`),
  KEY `idx_puantaj_isaret_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC",
    ];
}

/** Tablo var mı? (Yalnız true önbelleğe alınır; hata → false, sayfa çökmez.) */
function pi_hazir(PDO $pdo): bool
{
    static $var = [];
    $k = spl_object_id($pdo);
    if (isset($var[$k])) return true;
    try {
        foreach (array_keys(pi_tablolar()) as $t) $pdo->query("SELECT 1 FROM `$t` LIMIT 0");
        return $var[$k] = true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Yalnız CREATE TABLE IF NOT EXISTS. Yalnız migrate.php kartından çağrılır.
 * TEST KANCASI: $GLOBALS['_pi_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void.
 * @return list<array{tablo:string,durum:string,mesaj:string}>
 */
function pi_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_pi_ddl_calistir'] ?? null;
    foreach (pi_tablolar() as $ad => $ddl) {
        $var = false;
        try { $pdo->query("SELECT 1 FROM `$ad` LIMIT 0"); $var = true; } catch (Throwable $e) { $var = false; }
        if ($var) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (Throwable $e) {
            error_log('[pi_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    return $rapor;
}

// ── Okuma / yazma ───────────────────────────────────────────

/**
 * Ayın işaretleri: [employee_id => [tarih => ['kod'=>..,'aciklama'=>..]]]. Tablo yoksa/okunamazsa boş dizi.
 * Çalışan/depo süzgeci çağıranın satır kümesindedir (tabloyu kuran fonksiyon yalnız kendi satırlarını okur).
 */
function pi_oku(PDO $pdo, string $ay): array
{
    if (!pi_hazir($pdo)) return [];
    $ay = pc_ay_dogrula($ay);
    try {
        $st = $pdo->prepare('SELECT employee_id, tarih, kod, aciklama FROM puantaj_isaretler WHERE tarih >= ? AND tarih < ? ORDER BY employee_id ASC, tarih ASC');
        $st->execute([$ay . '-01', pc_ay_gez($ay, 1) . '-01']);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $tarih = substr((string)$r['tarih'], 0, 10);
            if (!pi_kod_gecerli((string)$r['kod'])) continue;
            $out[(int)$r['employee_id']][$tarih] = ['kod' => (string)$r['kod'], 'aciklama' => (string)($r['aciklama'] ?? '')];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[puantaj_isaret] oku: ' . $e->getMessage());
        return [];
    }
}

/** Çalışan satırı (id, full_name, depo, status) ya da null. */
function pi_calisan_bul(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        $st = $pdo->prepare('SELECT id, full_name, depo, status FROM employees WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Bir günün işaretini yazar. $kod '' = temizle. $aciklama null = mevcut açıklamayı koru ('' = sil).
 * Doğrulama (kod/tarih) burada da YAPILIR; kapılar (oturum/yetki/depo/CSRF) çağıranın işi.
 * Dönüş: ['ok'=>bool,'islem'=>'create'|'update'|'delete'|'degismedi'|'yok','hata'=>?string,
 *         'audit'=>?[action,id,eski,yeni]]  — audit boş değilse çağıran audit_log_event'e basar.
 */
function pi_uygula(PDO $pdo, int $empId, string $tarih, string $kod, ?string $aciklama, ?int $userId, ?string $bugun = null): array
{
    $t = pi_tarih_dogrula($tarih, $bugun);
    if ($t === null) return ['ok' => false, 'islem' => '', 'hata' => 'Geçersiz tarih.', 'audit' => null];
    if ($kod !== '' && !pi_kod_gecerli($kod)) return ['ok' => false, 'islem' => '', 'hata' => 'Geçersiz işaret kodu.', 'audit' => null];
    $koru = $aciklama === null;   // null = mevcut açıklamayı koru; '' (ya da temizlenince boş kalan) = sil
    if (!$koru) $aciklama = pi_aciklama_temizle($aciklama);

    $oku = static function () use ($pdo, $empId, $t): ?array {
        $st = $pdo->prepare('SELECT id, kod, aciklama FROM puantaj_isaretler WHERE employee_id = ? AND tarih = ?');
        $st->execute([$empId, $t]);
        $r = $st->fetch();
        return $r ?: null;
    };
    $simdi = date('Y-m-d H:i:s');
    try {
        $mevcut = $oku();
        $eski = $mevcut ? ['employee_id' => $empId, 'tarih' => $t, 'kod' => (string)$mevcut['kod'], 'aciklama' => $mevcut['aciklama'] !== null ? (string)$mevcut['aciklama'] : null] : null;

        if ($kod === '') {
            if (!$mevcut) return ['ok' => true, 'islem' => 'yok', 'hata' => null, 'audit' => null];
            $pdo->prepare('DELETE FROM puantaj_isaretler WHERE id = ?')->execute([(int)$mevcut['id']]);
            return ['ok' => true, 'islem' => 'delete', 'hata' => null, 'audit' => ['delete', (int)$mevcut['id'], $eski, null]];
        }

        $yeniAcik = $koru ? ($eski['aciklama'] ?? null) : $aciklama;
        $yeni = ['employee_id' => $empId, 'tarih' => $t, 'kod' => $kod, 'aciklama' => $yeniAcik];
        if ($mevcut) {
            if ($eski === $yeni) return ['ok' => true, 'islem' => 'degismedi', 'hata' => null, 'audit' => null];
            $pdo->prepare('UPDATE puantaj_isaretler SET kod = ?, aciklama = ?, user_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$kod, $yeniAcik, $userId, $simdi, (int)$mevcut['id']]);
            return ['ok' => true, 'islem' => 'update', 'hata' => null, 'audit' => ['update', (int)$mevcut['id'], $eski, $yeni]];
        }
        try {
            $pdo->prepare('INSERT INTO puantaj_isaretler (employee_id, tarih, kod, aciklama, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$empId, $t, $kod, $yeniAcik, $userId, $simdi, $simdi]);
            return ['ok' => true, 'islem' => 'create', 'hata' => null, 'audit' => ['create', (int)$pdo->lastInsertId(), null, $yeni]];
        } catch (PDOException $e) {
            // Aynı anda başka istek aynı güne yazdı (UNIQUE): mevcut satırı güncelle.
            $m2 = $oku();
            if (!$m2) throw $e;
            $pdo->prepare('UPDATE puantaj_isaretler SET kod = ?, aciklama = ?, user_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$kod, $yeniAcik, $userId, $simdi, (int)$m2['id']]);
            return ['ok' => true, 'islem' => 'update', 'hata' => null, 'audit' => ['update', (int)$m2['id'], ['employee_id' => $empId, 'tarih' => $t, 'kod' => (string)$m2['kod'], 'aciklama' => $m2['aciklama'] !== null ? (string)$m2['aciklama'] : null], $yeni]];
        }
    } catch (Throwable $e) {
        error_log('[puantaj_isaret] uygula: ' . $e->getMessage());
        return ['ok' => false, 'islem' => '', 'hata' => 'İşaret kaydedilemedi.', 'audit' => null];
    }
}
