<?php
// =========================================================
// config/wms_stok_core.php — ERP-11b Stok Ayrımı (Seçenek A: Ürün vs Malzeme) + WMS (Lot/SKT/Raf/FIFO)
//
// Mimari:
//   - Mevcut stok_kartlar tablosuna ALTER yapmaz; ekleyici profil + WMS tablolarıyla genişletir:
//       * stok_prof_wms (stok_id PK, stok_grubu: urun|malzeme|hizmet, min/maks, dara_kg, FIFO/ort, TDHP 150/152/153)
//       * wms_raflar    (depo içi raf/lokasyon kartları)
//       * wms_lotlar    (lot/parti/SKT takibi, FIFO maliyet & kalan miktar)
//   - Aynı zamanda ERP Faz 2 (erp_operasyon) ortak yetki/HTTP kapı yardımcılarını barındırır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_tx_helpers.php';
require_once __DIR__ . '/muhasebe_roller.php';

// ── ERP Faz 2 Ortak Yetki ve HTTP Yardımcıları ─────────────────────────────

function erp_op_can(string $eylem = 'read'): bool
{
    return muhasebe_modul_can('erp_operasyon', $eylem);
}

function erp_op_kapi(string $eylem = 'read'): void
{
    if (!erp_op_can($eylem)) {
        if (function_exists('forbidden')) forbidden();
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function erp_op_no_store(): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

function erp_op_json_istek(): bool
{
    $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $acc = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $xhr || str_contains($acc, 'application/json');
}

function erp_op_geri_guvenli(string $geri, string $varsayilan = 'wms_stok_yonetim.php'): string
{
    $geri = trim($geri);
    if ($geri === '') return $varsayilan;
    $yol = explode('?', $geri, 2)[0];
    if (!preg_match('/^(wms_stok_yonetim|yukleme_v2|satinalma_yonetim|uretim_bom)\.php$/', $yol)) {
        return $varsayilan;
    }
    return $geri;
}

function erp_op_cevap(array $sonuc, string $geri, string $basariMesaj, bool $json): never
{
    $ok  = !empty($sonuc['ok']);
    $kod = (int)($sonuc['kod'] ?? ($ok ? 200 : 422));
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        if ($ok && empty($sonuc['mesaj']) && $basariMesaj !== '') {
            $sonuc['mesaj'] = $basariMesaj;
        }
        echo json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (function_exists('set_flash')) {
        set_flash($ok ? 'success' : 'error', $ok ? ($sonuc['mesaj'] ?? $basariMesaj) : ($sonuc['hata'] ?? 'İşlem başarısız.'));
    }
    header('Location: ' . erp_op_geri_guvenli($geri));
    exit;
}

function erp_op_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function erp_op_sayi($v): float
{
    if (is_int($v) || is_float($v)) return round((float)$v, 4);
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? round((float)$s, 4) : 0.0;
}

// ── Şema ve Migrasyon (Yalnız migrate.php #wms-stok) ───────────────────────

/** @return list<string> */
function wms_stok_tablolar(): array
{
    return ['stok_prof_wms', 'wms_raflar', 'wms_lotlar'];
}

/** @return list<string> */
function wms_stok_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (wms_stok_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function wms_stok_hazir(PDO $pdo): bool
{
    return wms_stok_eksik_tablolar($pdo) === [];
}

function wms_stok_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = wms_stok_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Stok/WMS tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#wms-stok kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_op_sema_uyari')) {
        render_header('Ürün & Malzeme Stok / WMS');
        echo erp_op_sema_uyari($eksik, 'wms-stok', 'Stok Ayrımı (Seçenek A) & WMS (Lot/SKT/Raf)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('WMS şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function wms_stok_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'stok_prof_wms' => "CREATE TABLE IF NOT EXISTS `stok_prof_wms` (
            `stok_id` INT PRIMARY KEY,
            `stok_grubu` VARCHAR(20) NOT NULL DEFAULT 'urun',
            `degerleme_yontemi` VARCHAR(20) NOT NULL DEFAULT 'agirlikli_ort',
            `min_stok` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `maks_stok` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `birim_dara_kg` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `kalibre` VARCHAR(60) NOT NULL DEFAULT '',
            `barkod` VARCHAR(80) NOT NULL DEFAULT '',
            `tdhp_hesap_kod` VARCHAR(30) NOT NULL DEFAULT '152',
            `guncelleyen_id` INT NULL,
            `guncelleme_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'wms_raflar' => "CREATE TABLE IF NOT EXISTS `wms_raflar` (
            `id` $ai,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `raf_kodu` VARCHAR(40) NOT NULL,
            `ad` VARCHAR(140) NOT NULL DEFAULT '',
            `kapasite_kg` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `aktif` TINYINT NOT NULL DEFAULT 1,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (`depo`, `raf_kodu`)
        )$eng",
        'wms_lotlar' => "CREATE TABLE IF NOT EXISTS `wms_lotlar` (
            `id` $ai,
            `stok_id` INT NOT NULL,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `raf_id` INT NULL,
            `lot_no` VARCHAR(60) NOT NULL,
            `uretim_tarih` DATE NOT NULL,
            `skt_tarih` DATE NULL,
            `giris_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `kalan_miktar` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `birim_maliyet` DECIMAL(16,4) NOT NULL DEFAULT 0.0000,
            `para_birimi` VARCHAR(10) NOT NULL DEFAULT 'TRY',
            `kaynak_modul` VARCHAR(40) NULL,
            `kaynak_id` INT NULL,
            `durum` VARCHAR(20) NOT NULL DEFAULT 'aktif',
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
    ];

    $rapor = [];
    foreach ($ddl as $t => $sql) {
        $var = db_table_exists($pdo, $t);
        $pdo->exec($sql);
        $rapor[] = [
            'tablo' => $t,
            'durum' => $var ? 'var' : 'olusturuldu',
            'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.',
        ];
    }

    // İndeksler (idempotent)
    $indeksler = [
        ['wms_lotlar', 'idx_wms_lot_fifo', 'CREATE INDEX idx_wms_lot_fifo ON `wms_lotlar` (`stok_id`, `durum`, `uretim_tarih`, `id`)'],
        ['wms_lotlar', 'idx_wms_lot_skt',  'CREATE INDEX idx_wms_lot_skt  ON `wms_lotlar` (`skt_tarih`, `durum`)'],
        ['stok_prof_wms', 'idx_prof_grup', 'CREATE INDEX idx_prof_grup    ON `stok_prof_wms` (`stok_grubu`)'],
    ];
    foreach ($indeksler as [$tbl, $ix, $sql]) {
        try {
            if ($sqlite) {
                $pdo->exec(str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql));
            } else {
                $st = $pdo->prepare('SHOW INDEX FROM `' . $tbl . '` WHERE Key_name = ?');
                $st->execute([$ix]);
                if (!$st->fetch()) $pdo->exec($sql);
            }
        } catch (PDOException $e) {
            // İndeks zaten varsa sessizce geç
        }
    }

    return $rapor;
}

/** @return array<string,string> */
function wms_stok_gruplari(): array
{
    return [
        'urun'    => 'Ürün / Mamul Stoğu (152/153)',
        'malzeme' => 'Malzeme / Ambalaj Stoğu (150)',
        'hizmet'  => 'Hizmet / Masraf',
    ];
}

// ── 1) Seçenek A: Stok Profil & Grup (Ürün vs Malzeme) ─────────────────────

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function wms_stok_profil_kaydet(PDO $pdo, array $g, int $uid): array
{
    $stokId = (int)($g['stok_id'] ?? 0);
    $grup   = (string)($g['stok_grubu'] ?? 'urun');
    $yontem = (string)($g['degerleme_yontemi'] ?? 'agirlikli_ort');
    $min    = max(0.0, erp_op_sayi($g['min_stok'] ?? 0));
    $maks   = max(0.0, erp_op_sayi($g['maks_stok'] ?? 0));
    $dara   = max(0.0, erp_op_sayi($g['birim_dara_kg'] ?? 0));
    $kal    = mb_substr(trim((string)($g['kalibre'] ?? '')), 0, 60, 'UTF-8');
    $barkod = mb_substr(trim((string)($g['barkod'] ?? '')), 0, 80, 'UTF-8');
    $tdhp   = trim((string)($g['tdhp_hesap_kod'] ?? ''));

    if ($stokId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir stok kartı seçin.', 'kod' => 422];
    if (!isset(wms_stok_gruplari()[$grup])) return ['ok' => false, 'hata' => 'Geçersiz stok grubu.', 'kod' => 422];
    if (!in_array($yontem, ['agirlikli_ort', 'fifo'], true)) $yontem = 'agirlikli_ort';
    if ($maks > 0.0 && $maks < $min) return ['ok' => false, 'hata' => 'Maksimum stok minimum stoktan küçük olamaz.', 'kod' => 422];
    if ($tdhp === '') {
        $tdhp = $grup === 'malzeme' ? '150' : ($grup === 'urun' ? '152' : '770');
    }

    if (db_table_exists($pdo, 'stok_kartlar')) {
        $st = $pdo->prepare('SELECT id FROM `stok_kartlar` WHERE id = ?');
        $st->execute([$stokId]);
        if (!$st->fetchColumn()) return ['ok' => false, 'hata' => 'Stok kartı bulunamadı.', 'kod' => 404];
    }

    return db_tx($pdo, static function (PDO $p) use ($stokId, $grup, $yontem, $min, $maks, $dara, $kal, $barkod, $tdhp, $uid): array {
        $st = $p->prepare('SELECT * FROM `stok_prof_wms` WHERE stok_id = ?' . db_for_update($p));
        $st->execute([$stokId]);
        $eski = $st->fetch(PDO::FETCH_ASSOC);
        if ($eski) {
            $p->prepare(
                'UPDATE `stok_prof_wms` SET stok_grubu=?, degerleme_yontemi=?, min_stok=?, maks_stok=?, birim_dara_kg=?, kalibre=?, barkod=?, tdhp_hesap_kod=?, guncelleyen_id=?, guncelleme_at=? WHERE stok_id=?'
            )->execute([$grup, $yontem, $min, $maks, $dara, $kal, $barkod, $tdhp, $uid ?: null, date('Y-m-d H:i:s'), $stokId]);
        } else {
            $p->prepare(
                'INSERT INTO `stok_prof_wms` (stok_id, stok_grubu, degerleme_yontemi, min_stok, maks_stok, birim_dara_kg, kalibre, barkod, tdhp_hesap_kod, guncelleyen_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$stokId, $grup, $yontem, $min, $maks, $dara, $kal, $barkod, $tdhp, $uid ?: null]);
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('update', 'wms_stok_profil', $stokId, $eski ?: null, ['stok_grubu' => $grup, 'degerleme_yontemi' => $yontem, 'min_stok' => $min]);
        }
        return ['ok' => true, 'mesaj' => 'Stok WMS profili kaydedildi.'];
    });
}

/**
 * Seçenek A: Ürün Stoğu ('urun') veya Malzeme/Ambalaj Stoğu ('malzeme') ayrıştırılmış listesi + WMS kalan miktarı.
 * @return list<array<string,mixed>>
 */
function wms_stok_listesi(PDO $pdo, ?string $grup = null, string $ara = ''): array
{
    $kartVar = db_table_exists($pdo, 'stok_kartlar');
    $rows = [];
    if ($kartVar) {
        $sql = "SELECT k.id, k.kod, k.ad, k.tip, k.ana_birim, k.aktif,
                       COALESCE(p.stok_grubu, CASE WHEN k.tip IN ('malzeme','ambalaj','hammadde') THEN 'malzeme' ELSE 'urun' END) AS stok_grubu,
                       COALESCE(p.degerleme_yontemi, 'agirlikli_ort') AS degerleme_yontemi,
                       COALESCE(p.min_stok, COALESCE(k.kritik_seviye, 0)) AS min_stok,
                       COALESCE(p.maks_stok, 0) AS maks_stok,
                       COALESCE(p.birim_dara_kg, 0) AS birim_dara_kg,
                       COALESCE(p.kalibre, '') AS kalibre,
                       COALESCE(NULLIF(p.barkod, ''), k.barkod, '') AS barkod,
                       COALESCE(p.tdhp_hesap_kod, CASE WHEN k.tip IN ('malzeme','ambalaj','hammadde') THEN '150' ELSE '152' END) AS tdhp_hesap_kod,
                       COALESCE((SELECT SUM(l.kalan_miktar) FROM `wms_lotlar` l WHERE l.stok_id = k.id AND l.durum = 'aktif'), 0) AS wms_kalan
                FROM `stok_kartlar` k
                LEFT JOIN `stok_prof_wms` p ON p.stok_id = k.id
                WHERE k.aktif = 1
                ORDER BY k.kod ASC LIMIT 400";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT p.stok_id AS id, ('STK-' || p.stok_id) AS kod, ('Stok #' || p.stok_id) AS ad, p.stok_grubu AS tip, 'kg' AS ana_birim, 1 AS aktif,
                       p.stok_grubu, p.degerleme_yontemi, p.min_stok, p.maks_stok, p.birim_dara_kg, p.kalibre, p.barkod, p.tdhp_hesap_kod,
                       COALESCE((SELECT SUM(l.kalan_miktar) FROM `wms_lotlar` l WHERE l.stok_id = p.stok_id AND l.durum = 'aktif'), 0) AS wms_kalan
                FROM `stok_prof_wms` p ORDER BY p.stok_id ASC LIMIT 400";
        if (!db_is_sqlite($pdo)) {
            $sql = str_replace(["('STK-' || p.stok_id)", "('Stok #' || p.stok_id)"], ["CONCAT('STK-', p.stok_id)", "CONCAT('Stok #', p.stok_id)"], $sql);
        }
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    $q = mb_strtolower(trim($ara), 'UTF-8');
    $out = [];
    foreach ($rows as $r) {
        if ($grup !== null && $grup !== '' && (string)$r['stok_grubu'] !== $grup) continue;
        if ($q !== '') {
            $hay = mb_strtolower((string)$r['kod'] . ' ' . (string)$r['ad'] . ' ' . (string)$r['barkod'], 'UTF-8');
            if (!str_contains($hay, $q)) continue;
        }
        $r['wms_kalan'] = round((float)$r['wms_kalan'], 3);
        $r['min_stok']  = round((float)$r['min_stok'], 3);
        $r['maks_stok'] = round((float)$r['maks_stok'], 3);
        $out[] = $r;
    }
    return $out;
}

// ── 2) Depo İçi Raf / Lokasyon (WMS) ───────────────────────────────────────

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function wms_raf_kaydet(PDO $pdo, array $g, int $uid): array
{
    $depo = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    $kod  = mb_strtoupper(mb_substr(trim((string)($g['raf_kodu'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad   = mb_substr(trim((string)($g['ad'] ?? '')), 0, 140, 'UTF-8');
    $kap  = max(0.0, erp_op_sayi($g['kapasite_kg'] ?? 0));

    if ($kod === '' || !preg_match('/^[A-Z0-9._-]{2,40}$/', $kod)) {
        return ['ok' => false, 'hata' => 'Raf kodu 2–40 karakter (harf, rakam, -, _) olmalıdır (örn. A-01-R2).', 'kod' => 422];
    }
    if ($depo === '') $depo = 'MERKEZ';

    return db_tx($pdo, static function (PDO $p) use ($depo, $kod, $ad, $kap, $uid): array {
        $st = $p->prepare('SELECT id FROM `wms_raflar` WHERE depo = ? AND raf_kodu = ?' . db_for_update($p));
        $st->execute([$depo, $kod]);
        $exId = $st->fetchColumn();
        if ($exId) {
            $p->prepare('UPDATE `wms_raflar` SET ad=?, kapasite_kg=?, aktif=1 WHERE id=?')->execute([$ad, $kap, (int)$exId]);
            $id = (int)$exId;
        } else {
            $p->prepare('INSERT INTO `wms_raflar` (depo, raf_kodu, ad, kapasite_kg, aktif) VALUES (?, ?, ?, ?, 1)')->execute([$depo, $kod, $ad, $kap]);
            $id = (int)$p->lastInsertId();
        }
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'wms_raf', $id, null, ['depo' => $depo, 'raf_kodu' => $kod, 'uid' => $uid]);
        }
        return ['ok' => true, 'id' => $id, 'mesaj' => "Raf lokasyonu kaydedildi ($depo / $kod)."];
    });
}

/** @return list<array<string,mixed>> */
function wms_raf_listesi(PDO $pdo): array
{
    return $pdo->query(
        "SELECT r.*, COALESCE((SELECT SUM(l.kalan_miktar) FROM `wms_lotlar` l WHERE l.raf_id = r.id AND l.durum = 'aktif'), 0) AS dolu_miktar
         FROM `wms_raflar` r ORDER BY r.depo ASC, r.raf_kodu ASC LIMIT 300"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ── 3) Lot / Parti / SKT Girişi & FIFO Tüketim Motoru ──────────────────────

/** @return array{ok:bool,id?:int,lot_no?:string,hata?:string,mesaj?:string,kod?:int} */
function wms_lot_giris(PDO $pdo, array $g, int $uid): array
{
    $stokId = (int)($g['stok_id'] ?? 0);
    $depo   = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    if ($depo === '') $depo = 'MERKEZ';
    $rafId  = isset($g['raf_id']) && (int)$g['raf_id'] > 0 ? (int)$g['raf_id'] : null;
    $lotNo  = mb_strtoupper(mb_substr(trim((string)($g['lot_no'] ?? '')), 0, 60, 'UTF-8'), 'UTF-8');
    $urTar  = trim((string)($g['uretim_tarih'] ?? date('Y-m-d')));
    $sktTar = trim((string)($g['skt_tarih'] ?? ''));
    $miktar = round(erp_op_sayi($g['miktar'] ?? 0), 3);
    $maliyet = max(0.0, round(erp_op_sayi($g['birim_maliyet'] ?? 0), 4));
    $pb     = strtoupper(trim((string)($g['para_birimi'] ?? 'TRY')));
    if (!preg_match('/^[A-Z]{3}$/', $pb)) $pb = 'TRY';
    $kayMod = isset($g['kaynak_modul']) && trim((string)$g['kaynak_modul']) !== '' ? mb_substr(trim((string)$g['kaynak_modul']), 0, 40, 'UTF-8') : null;
    $kayId  = isset($g['kaynak_id']) && (int)$g['kaynak_id'] > 0 ? (int)$g['kaynak_id'] : null;

    if ($stokId <= 0) return ['ok' => false, 'hata' => 'Geçerli bir stok seçin.', 'kod' => 422];
    if ($miktar <= 0.0) return ['ok' => false, 'hata' => 'Lot giriş miktarı sıfırdan büyük olmalıdır.', 'kod' => 422];
    if (!erp_op_tarih_gecerli($urTar)) return ['ok' => false, 'hata' => 'Geçersiz üretim/giriş tarihi.', 'kod' => 422];
    if ($sktTar !== '' && !erp_op_tarih_gecerli($sktTar)) return ['ok' => false, 'hata' => 'Geçersiz SKT tarihi.', 'kod' => 422];
    if ($sktTar !== '' && $sktTar < $urTar) return ['ok' => false, 'hata' => 'SKT tarihi üretim tarihinden önce olamaz.', 'kod' => 422];
    if ($lotNo === '') $lotNo = 'LOT-' . date('Ymd', strtotime($urTar)) . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);

    return db_tx($pdo, static function (PDO $p) use ($stokId, $depo, $rafId, $lotNo, $urTar, $sktTar, $miktar, $maliyet, $pb, $kayMod, $kayId, $uid): array {
        $p->prepare(
            "INSERT INTO `wms_lotlar`
             (stok_id, depo, raf_id, lot_no, uretim_tarih, skt_tarih, giris_miktar, kalan_miktar, birim_maliyet, para_birimi, kaynak_modul, kaynak_id, durum, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif', ?)"
        )->execute([$stokId, $depo, $rafId, $lotNo, $urTar, $sktTar !== '' ? $sktTar : null, $miktar, $miktar, $maliyet, $pb, $kayMod, $kayId, $uid ?: null]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'wms_lot', $id, null, ['stok_id' => $stokId, 'lot_no' => $lotNo, 'miktar' => $miktar]);
        }
        return ['ok' => true, 'id' => $id, 'lot_no' => $lotNo, 'mesaj' => "Lot girişi kaydedildi ($lotNo · $miktar)."];
    });
}

/**
 * FIFO (First-In First-Out / FEFO: SKT varsa önce yakın SKT) lot tüketim motoru.
 * İş Emri sarfı ve Yeni Araç Yükleme sevkiyat düşümü bu fonksiyonu çağırır.
 * @return array{ok:bool,tuketilen:float,toplam_maliyet:float,lotlar:list<array{lot_id:int,lot_no:string,miktar:float,birim_maliyet:float}>,hata?:string,kod?:int}
 */
function wms_lot_tuket_fifo(PDO $pdo, int $stokId, float $miktar, ?string $depo = null, bool $kismiIzin = false): array
{
    $hedef = round($miktar, 3);
    if ($stokId <= 0 || $hedef <= 0.0) {
        return ['ok' => false, 'tuketilen' => 0.0, 'toplam_maliyet' => 0.0, 'lotlar' => [], 'hata' => 'Geçersiz stok veya miktar.', 'kod' => 422];
    }

    return db_tx($pdo, static function (PDO $p) use ($stokId, $hedef, $depo, $kismiIzin): array {
        $w = ["stok_id = ?", "durum = 'aktif'", "kalan_miktar > 0"];
        $params = [$stokId];
        if ($depo !== null && trim($depo) !== '') {
            $w[] = 'depo = ?';
            $params[] = mb_strtoupper(trim($depo), 'UTF-8');
        }
        // FEFO/FIFO: SKT tanımlı olanlar erken SKT sırasıyla, sonra üretim tarihine göre en eski ilk çıkar
        $sql = 'SELECT * FROM `wms_lotlar` WHERE ' . implode(' AND ', $w)
             . ' ORDER BY CASE WHEN skt_tarih IS NULL THEN 1 ELSE 0 END ASC, skt_tarih ASC, uretim_tarih ASC, id ASC'
             . db_for_update($p);
        $st = $p->prepare($sql);
        $st->execute($params);
        $adaylar = $st->fetchAll(PDO::FETCH_ASSOC);

        $toplamMevcut = 0.0;
        foreach ($adaylar as $l) $toplamMevcut = round($toplamMevcut + (float)$l['kalan_miktar'], 3);
        if (!$kismiIzin && $toplamMevcut + 0.0005 < $hedef) {
            return [
                'ok' => false, 'tuketilen' => 0.0, 'toplam_maliyet' => 0.0, 'lotlar' => [],
                'hata' => sprintf('Yetersiz WMS lot stoğu (İstenen: %.3f, Mevcut: %.3f).', $hedef, $toplamMevcut),
                'kod'  => 409,
            ];
        }

        $kalan = $hedef;
        $tuketilen = 0.0;
        $topMaliyet = 0.0;
        $detay = [];
        foreach ($adaylar as $l) {
            if ($kalan <= 0.0005) break;
            $mevcut = round((float)$l['kalan_miktar'], 3);
            $al = min($mevcut, $kalan);
            $yeniKalan = round($mevcut - $al, 3);
            $yeniDurum = $yeniKalan <= 0.0005 ? 'tukendi' : 'aktif';
            $p->prepare('UPDATE `wms_lotlar` SET kalan_miktar = ?, durum = ? WHERE id = ?')
                ->execute([$yeniKalan, $yeniDurum, (int)$l['id']]);
            $bm = (float)$l['birim_maliyet'];
            $tuketilen = round($tuketilen + $al, 3);
            $topMaliyet = round($topMaliyet + ($al * $bm), 4);
            $kalan = round($kalan - $al, 3);
            $detay[] = [
                'lot_id'        => (int)$l['id'],
                'lot_no'        => (string)$l['lot_no'],
                'miktar'        => $al,
                'birim_maliyet' => $bm,
            ];
        }

        return [
            'ok'             => true,
            'tuketilen'      => $tuketilen,
            'toplam_maliyet' => round($topMaliyet, 2),
            'lotlar'         => $detay,
        ];
    });
}

/** @return list<array<string,mixed>> */
function wms_lot_listesi(PDO $pdo, array $filtre = []): array
{
    $w = ['1=1']; $p = [];
    if (!empty($filtre['durum'])) { $w[] = 'l.durum = ?'; $p[] = (string)$filtre['durum']; }
    if (!empty($filtre['stok_id'])) { $w[] = 'l.stok_id = ?'; $p[] = (int)$filtre['stok_id']; }
    $kartVar = db_table_exists($pdo, 'stok_kartlar');
    $kJoin = $kartVar ? 'LEFT JOIN `stok_kartlar` k ON k.id = l.stok_id' : '';
    $kSel  = $kartVar ? "COALESCE(k.kod, '') AS stok_kod, COALESCE(k.ad, '') AS stok_ad" : "'' AS stok_kod, '' AS stok_ad";
    $st = $pdo->prepare(
        "SELECT l.*, $kSel, COALESCE(r.raf_kodu, '') AS raf_kodu
         FROM `wms_lotlar` l
         $kJoin
         LEFT JOIN `wms_raflar` r ON r.id = l.raf_id
         WHERE " . implode(' AND ', $w) . "
         ORDER BY CASE WHEN l.durum='aktif' THEN 0 ELSE 1 END ASC, l.skt_tarih ASC, l.uretim_tarih DESC, l.id DESC LIMIT 300"
    );
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Min-Stok ve Yaklaşan/Geçmiş SKT alarm motoru.
 * @return array{min_stok:list<array<string,mixed>>,skt_uyari:list<array<string,mixed>>}
 */
function wms_alarmlar(PDO $pdo, int $sktGun = 30): array
{
    $minAlarmlar = [];
    foreach (wms_stok_listesi($pdo) as $s) {
        if ((float)$s['min_stok'] > 0 && (float)$s['wms_kalan'] < (float)$s['min_stok']) {
            $s['eksik_miktar'] = round((float)$s['min_stok'] - (float)$s['wms_kalan'], 3);
            $minAlarmlar[] = $s;
        }
    }

    $esikTarih = date('Y-m-d', strtotime('+' . max(1, $sktGun) . ' days'));
    $kartVar = db_table_exists($pdo, 'stok_kartlar');
    $kJoin = $kartVar ? 'LEFT JOIN `stok_kartlar` k ON k.id = l.stok_id' : '';
    $kSel  = $kartVar ? "COALESCE(k.kod, '') AS stok_kod, COALESCE(k.ad, '') AS stok_ad" : "'' AS stok_kod, '' AS stok_ad";
    $st = $pdo->prepare(
        "SELECT l.*, $kSel
         FROM `wms_lotlar` l $kJoin
         WHERE l.durum = 'aktif' AND l.kalan_miktar > 0 AND l.skt_tarih IS NOT NULL AND l.skt_tarih <= ?
         ORDER BY l.skt_tarih ASC LIMIT 200"
    );
    $st->execute([$esikTarih]);
    $sktAlarmlar = $st->fetchAll(PDO::FETCH_ASSOC);

    return ['min_stok' => $minAlarmlar, 'skt_uyari' => $sktAlarmlar];
}
