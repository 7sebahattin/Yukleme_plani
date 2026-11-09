<?php
// =============================================================================
// config/beyansiz_yukleme.php — "Yükleme Bildir" (beyansız yükleme) — v498
// =============================================================================
// Beyan kaydı OLMADAN yapılan satış yüklemesi için HKS TASLAĞI açar. Bu dosya
// yalnız şema + salt okunur sorgular + saf yardımcılar taşır; HKS'e HİÇBİR ŞEY
// göndermez. Taslak yazmanın TEK yolu halkayit/taslak_lib.php hks_taslak_olustur().
//
// • Tablo `beyansiz_yukleme` migrate.php kartından kurulur (GO verildi).
// • Taslak tablosu (hks_taslaklar) ile bu tablo ÇAPRAZ-COLLATION olabilir:
//   SQL'de JOIN/EXISTS/COLLATE YOK — taslak canlılığı PHP'de, parçalı IN ile.
// • Kişisel veri: karşı kişi için YALNIZ id + ad saklanır (TC/cep/doğum YOK).
// • Tüm sorgu işlevleri fail-soft'tur (try/catch → boş sonuç).
// =============================================================================
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

const BSZ_YON_DISI = 'yurt_disi';
const BSZ_YON_ICI  = 'yurt_ici';
const BSZ_TARAMA_LIMIT = 2000;     // sekme başına en çok okunan satır

// ── Şema ────────────────────────────────────────────────────────────────────
function beyansiz_yukleme_ddl(PDO $pdo): string {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return "CREATE TABLE IF NOT EXISTS beyansiz_yukleme (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parti_no VARCHAR(60) NULL,
            hks_firma_id VARCHAR(40) NOT NULL,
            hks_firma_ad VARCHAR(200) NULL,
            yon VARCHAR(12) NOT NULL DEFAULT 'yurt_disi',
            ulke_id VARCHAR(40) NULL,
            ulke_ad VARCHAR(150) NULL,
            karsi_kisi_id INT NULL,
            karsi_kisi_ad VARCHAR(200) NULL,
            urun_id VARCHAR(40) NOT NULL,
            urun_ad VARCHAR(150) NOT NULL,
            kg DECIMAL(12,3) NOT NULL,
            plaka VARCHAR(30) NOT NULL,
            taslak_id VARCHAR(40) NULL,
            gonderim_id VARCHAR(40) NULL,
            durum VARCHAR(20) NOT NULL DEFAULT 'taslak',
            hata_metni TEXT NULL,
            istek_kodu VARCHAR(40) NULL UNIQUE,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        )";
    }
    return "CREATE TABLE IF NOT EXISTS `beyansiz_yukleme` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `parti_no` VARCHAR(60) NULL,
        `hks_firma_id` VARCHAR(40) NOT NULL,
        `hks_firma_ad` VARCHAR(200) NULL,
        `yon` VARCHAR(12) NOT NULL DEFAULT 'yurt_disi',
        `ulke_id` VARCHAR(40) NULL,
        `ulke_ad` VARCHAR(150) NULL,
        `karsi_kisi_id` INT NULL,
        `karsi_kisi_ad` VARCHAR(200) NULL,
        `urun_id` VARCHAR(40) NOT NULL,
        `urun_ad` VARCHAR(150) NOT NULL,
        `kg` DECIMAL(12,3) NOT NULL,
        `plaka` VARCHAR(30) NOT NULL,
        `taslak_id` VARCHAR(40) NULL,
        `gonderim_id` VARCHAR(40) NULL,
        `durum` VARCHAR(20) NOT NULL DEFAULT 'taslak',
        `hata_metni` TEXT NULL,
        `istek_kodu` VARCHAR(40) NULL,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL,
        UNIQUE KEY `uq_bsz_istek` (`istek_kodu`),
        KEY `ix_bsz_taslak` (`taslak_id`),
        KEY `ix_bsz_durum` (`durum`),
        KEY `ix_bsz_created` (`created_at`),
        KEY `ix_bsz_plaka` (`plaka`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/** Dönüş: [['tablo' => 'beyansiz_yukleme', 'durum' => 'olusturuldu|mevcut|hata', 'mesaj' => '...']] */
function beyansiz_yukleme_migrate(PDO $pdo): array {
    $var = false;
    try { $pdo->query('SELECT 1 FROM beyansiz_yukleme LIMIT 1'); $var = true; } catch (Throwable $e) {}
    if ($var) return [['tablo' => 'beyansiz_yukleme', 'durum' => 'mevcut', 'mesaj' => 'Tablo zaten kurulu.']];
    try {
        $pdo->exec(beyansiz_yukleme_ddl($pdo));
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach (['taslak_id', 'durum', 'created_at', 'plaka'] as $k) {
                $pdo->exec("CREATE INDEX IF NOT EXISTS ix_bsz_$k ON beyansiz_yukleme($k)");
            }
        }
        return [['tablo' => 'beyansiz_yukleme', 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.']];
    } catch (Throwable $e) {
        error_log('[beyansiz] migrate: ' . get_class($e));
        return [['tablo' => 'beyansiz_yukleme', 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı: ' . $e->getMessage()]];
    }
}

// ── Küçük yardımcılar ───────────────────────────────────────────────────────
function bsz_hks_on_ek(): string {
    return defined('HKS_TABLO_ON') ? (string)HKS_TABLO_ON : 'hks_';
}

function bsz_like_kacis(string $q): string {
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
}

// ── Taslak canlılığı (PHP'de; çapraz-collation: JOIN/EXISTS/COLLATE YOK) ────
/** Verilen taslak id'lerinden hâlâ var olanları döner. Hata → hepsi canlı varsayılır. */
function bsz_canli_taslak_idleri(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($x) => $x !== '')));
    if (!$ids) return [];
    $canli = [];
    try {
        $tablo = '`' . bsz_hks_on_ek() . 'taslaklar`';
        foreach (array_chunk($ids, 200) as $parca) {
            $in = implode(',', array_fill(0, count($parca), '?'));
            $st = db()->prepare("SELECT id FROM $tablo WHERE id IN ($in)");
            $st->execute($parca);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $canli[(string)$id] = true;
        }
    } catch (Throwable $e) {
        return $ids;   // fail-soft: bekleyen satır kaybolmasın
    }
    return array_keys($canli);
}

/**
 * Süzülmüş satırları iki bölüme ayırır:
 *   bekleyen = durum 'taslak' VE taslak canlı
 *   yapilan  = 'gonderildi' | 'silindi' | taslak ölü ("Durum belirsiz")
 * Her satıra `goster` eklenir: bekliyor | gonderildi | silindi | belirsiz.
 */
function bsz_bolumle(string $q = ''): array {
    $bos = ['bekleyen' => [], 'yapilan' => []];
    try {
        $kosul = '';
        $par = [];
        $q = trim($q);
        if ($q !== '') {
            $like = bsz_like_kacis($q);
            $alanlar = ['parti_no', 'hks_firma_ad', 'ulke_ad', 'karsi_kisi_ad', 'urun_ad', 'plaka'];
            $kosul = ' WHERE (' . implode(' OR ', array_map(fn($a) => "$a LIKE ? ESCAPE '!'", $alanlar)) . ')';
            $par = array_fill(0, count($alanlar), $like);
        }
        // v501: 'kaldirildi' (silinmiş) kayıt hiçbir listede/sayaçta görünmez.
        $kosul = ($kosul === '' ? ' WHERE ' : $kosul . ' AND ') . "durum <> 'kaldirildi'";
        $st = db()->prepare('SELECT * FROM beyansiz_yukleme' . $kosul .
            ' ORDER BY created_at DESC, id DESC LIMIT ' . BSZ_TARAMA_LIMIT);
        $st->execute($par);
        $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $bos;
    }
    $taslakIdler = [];
    foreach ($satirlar as $r) if ((string)$r['durum'] === 'taslak' && (string)$r['taslak_id'] !== '') $taslakIdler[] = (string)$r['taslak_id'];
    $canli = array_flip(bsz_canli_taslak_idleri($taslakIdler));
    $out = $bos;
    foreach ($satirlar as $r) {
        $d = (string)$r['durum'];
        if ($d === 'taslak') {
            $tid = (string)($r['taslak_id'] ?? '');
            if ($tid !== '' && isset($canli[$tid])) { $r['goster'] = 'bekliyor'; $out['bekleyen'][] = $r; }
            else { $r['goster'] = 'belirsiz'; $out['yapilan'][] = $r; }
        } else {
            $r['goster'] = $d === 'gonderildi' ? 'gonderildi' : 'silindi';
            $out['yapilan'][] = $r;
        }
    }
    return $out;
}

function bsz_sayfala(array $liste, int $page, int $per): array {
    $per = max(1, $per);
    $toplam = count($liste);
    $sayfa = max(1, min($page, max(1, (int)ceil($toplam / $per))));
    return ['satirlar' => array_slice($liste, ($sayfa - 1) * $per, $per), 'toplam' => $toplam, 'sayfa' => $sayfa, 'per' => $per];
}

function bsz_bekleyen(string $q = '', int $page = 1, int $per = 50): array {
    return bsz_sayfala(bsz_bolumle($q)['bekleyen'], $page, $per);
}

function bsz_yapilan(string $q = '', int $page = 1, int $per = 50): array {
    return bsz_sayfala(bsz_bolumle($q)['yapilan'], $page, $per);
}

/** Sekme sayaçları (aramasız): ['bekleyen' => n, 'yapilan' => n]. Hata → 0/0. */
function bsz_sayilar(): array {
    $b = bsz_bolumle('');
    return ['bekleyen' => count($b['bekleyen']), 'yapilan' => count($b['yapilan'])];
}

// ── Silme (v501) ────────────────────────────────────────────────────────────
/**
 * Beyansız yükleme kaydını kaldırır — silinebilen: (a) "Bildirim Bekleyenler"deki kayıt
 * (durum 'taslak' + Hal Kayıt taslağı hâlâ var) ve (b) taslağı silinmiş kayıt (durum 'silindi').
 * GÖNDERİLMİŞ ('gonderildi') ve durumu belirsiz kayıt ASLA silinmez (sahip kararı).
 * Soft: durum='kaldirildi' (satır + audit kalır); bekleyen taslak da silinir.
 * Dönüş: ['ok'=>bool, 'kod'=>http, 'hata'=>?, 'taslak_silindi'=>bool, 'eski_durum'=>string]
 */
function bsz_kaldir(int $id): array {
    $pdo = db();
    try {
        $st = $pdo->prepare('SELECT id, durum, taslak_id FROM beyansiz_yukleme WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'kod' => 500, 'hata' => 'Kayıt okunamadı.'];
    }
    if (!$r) return ['ok' => false, 'kod' => 404, 'hata' => 'Kayıt bulunamadı.'];
    $durum = (string)$r['durum'];
    $tid = (string)($r['taslak_id'] ?? '');
    $bekliyor = $durum === 'taslak' && $tid !== '' && in_array($tid, bsz_canli_taslak_idleri([$tid]), true);
    // v504 — kart yetkisi: Bildirim Bekleyenler / Bildirim Yapılanlar kartının Sil izni
    if (function_exists('can_bsz_kart') && !can_bsz_kart($durum === 'silindi' ? 'yap' : 'bek', 'delete')) {
        return ['ok' => false, 'kod' => 403, 'hata' => 'Bu kartta silme yetkiniz yok.'];
    }
    if ($durum === 'silindi') {
        // Taslağı zaten silinmiş kayıt: Hal Kayıt'a dokunulmaz, yalnız satır kaldırılır.
        try {
            $u = $pdo->prepare("UPDATE beyansiz_yukleme SET durum = 'kaldirildi', updated_at = NOW() WHERE id = ? AND durum = 'silindi'");
            $u->execute([$id]);
            if ($u->rowCount() < 1) return ['ok' => false, 'kod' => 409, 'hata' => 'Kayıt durumu değişti. Listeyi yenileyin.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'kod' => 500, 'hata' => 'Kayıt silinemedi.'];
        }
        return ['ok' => true, 'kod' => 200, 'taslak_silindi' => false, 'eski_durum' => $durum];
    }
    if (!$bekliyor) {
        return ['ok' => false, 'kod' => 409, 'hata' => 'Yalnız bekleyen ya da taslağı silinmiş kayıt silinebilir; gönderilmiş/durumu belirsiz kayıt silinemez. Listeyi yenileyin.'];
    }
    try {
        $d = $pdo->prepare('DELETE FROM `' . bsz_hks_on_ek() . 'taslaklar` WHERE id = ?');
        $d->execute([$tid]);
        // Canlı görünen taslak 0 satır sildiyse aynı anda gönderiliyor/silinmiş olabilir → dokunma.
        if ($d->rowCount() < 1) return ['ok' => false, 'kod' => 409, 'hata' => 'Taslak şu an işlemde (gönderiliyor olabilir). Listeyi yenileyip tekrar deneyin.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'kod' => 500, 'hata' => 'Hal Kayıt taslağı silinemedi.'];
    }
    try {
        $u = $pdo->prepare("UPDATE beyansiz_yukleme SET durum = 'kaldirildi', updated_at = NOW() WHERE id = ? AND durum = 'taslak'");
        $u->execute([$id]);
    } catch (Throwable $e) {
        return ['ok' => false, 'kod' => 500, 'hata' => 'Kayıt silinemedi.'];
    }
    return ['ok' => true, 'kod' => 200, 'taslak_silindi' => true, 'eski_durum' => $durum];
}

// ── Seçenekler (yalnız id + ad; TC/cep/doğum DÖNMEZ) ────────────────────────
function bsz_kisi_secenekleri(): array {
    try {
        $st = db()->query("SELECT id, ad FROM `" . bsz_hks_on_ek() . "kisiler` WHERE ad <> '' AND LENGTH(tc) = 10 ORDER BY ad LIMIT 2000");
        return array_map(fn($r) => ['id' => (int)$r['id'], 'ad' => (string)$r['ad']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

function bsz_firma_secenekleri(): array {
    try {
        $st = db()->query("SELECT id, ad FROM `" . bsz_hks_on_ek() . "firmalar` ORDER BY ad");
        return array_map(fn($r) => ['id' => (string)$r['id'], 'ad' => (string)$r['ad']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/** Bugün aynı plakaya (silinmemiş) yapılmış beyansız kayıtlar. Hata → []. */
function bsz_ayni_plaka_bugun(string $plaka): array {
    $plaka = trim($plaka);
    if ($plaka === '') return [];
    try {
        $st = db()->prepare("SELECT created_at, urun_ad, kg, hks_firma_ad FROM beyansiz_yukleme
                             WHERE plaka = ? AND created_at >= ? AND durum NOT IN ('silindi','kaldirildi')
                             ORDER BY created_at DESC LIMIT 10");
        $st->execute([$plaka, date('Y-m-d') . ' 00:00:00']);
        return array_map(fn($r) => [
            'saat'  => substr((string)$r['created_at'], 11, 5),
            'urun'  => (string)$r['urun_ad'],
            'kg'    => (float)$r['kg'],
            'firma' => (string)$r['hks_firma_ad'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

// ── Taslak ortak alanı (SAF; api_beyan_bildirim.php bb_taslak_kur ile PARİTE) ─
/**
 * Yurt dışı (ihracat) satış. Alan kümesi bb_taslak_kur ile AYNIDIR; yalnız
 * kaynak.tip 'beyansiz' (kayitId) farklıdır.
 * $p: sifatId, turId, urunId, urunAd, plaka, isletmeTuruId, ulkeId, ulkeAd, kg, kayitId, partiNo
 */
function bsz_ortak_yurtdisi(array $p): array {
    return [
        'sifatId'        => (string)$p['sifatId'],
        'bildirimTuruId' => (string)$p['turId'],
        'urunId'         => (string)$p['urunId'],
        'urunAd'         => (string)$p['urunAd'],
        'plaka'          => (string)$p['plaka'],
        'belgeNo'        => '',
        'belgeTipiId'    => 0,
        'fiyatBekliyor'  => true,
        'isletmeTuruId'  => (string)$p['isletmeTuruId'],
        'ulkeId'         => (string)$p['ulkeId'],
        'ulkeAd'         => (string)$p['ulkeAd'],
        'planKg'         => (float)$p['kg'],
        'planSorgu'      => [
            'urunId'        => (string)$p['urunId'],
            'aySayisi'      => 12,
            'isletmeTuruId' => 0,
            'sirala'        => 'azalan',
        ],
        'kaynak' => ['tip' => 'beyansiz', 'kayitId' => (int)$p['kayitId'], 'partiNo' => (string)($p['partiNo'] ?? '')],
    ];
}

/**
 * Yurt içi satış: EKSİK taslak (tamamlanacak) — Hal Kayıt'ta Düzenle ile
 * tamamlanır. $p: turId, turAd, urunId, urunAd, plaka, kg, kayitId, partiNo,
 * ikinciTc, ikinciAd, ikinciCep, ikinciDogumTarihi (GG.AA.YYYY), ikinciSifatId (boş olabilir).
 * isletmeTuruId (gidilen yer türü) YOK — fiyatı giren kişi Hal Kayıt'ta seçer; sifatId / gidecekIsyeri / il alanları bilerek YOK
 * (Hal Kayıt'ta Düzenle ile tamamlanır — canlı HKS sorgusu burada yapılmaz).
 */
function bsz_ortak_yurtici(array $p): array {
    $o = [
        'yurtIci'        => true,
        'tamamlanacak'   => true,
        'fiyatBekliyor'  => true,
        'fiyatGonder'    => true,
        'kayitZorunlu'   => false,
        'bildirimTuruId' => (string)$p['turId'],
        'turAd'          => (string)($p['turAd'] ?? ''),
        'urunId'         => (string)$p['urunId'],
        'urunAd'         => (string)$p['urunAd'],
        'plaka'          => (string)$p['plaka'],
        'belgeNo'        => '',
        'belgeTipiId'    => 0,
        'planKg'         => (float)$p['kg'],
        'planSorgu'      => [
            'urunId'        => (string)$p['urunId'],
            'aySayisi'      => 12,
            'isletmeTuruId' => 0,
            'sirala'        => 'azalan',
        ],
        'ikinciTc'          => (string)($p['ikinciTc'] ?? ''),
        'ikinciAd'          => (string)($p['ikinciAd'] ?? ''),
        'ikinciCep'         => (string)($p['ikinciCep'] ?? ''),
        'ikinciDogumTarihi' => (string)($p['ikinciDogumTarihi'] ?? ''),
        'kaynak' => ['tip' => 'beyansiz', 'kayitId' => (int)$p['kayitId'], 'partiNo' => (string)($p['partiNo'] ?? '')],
    ];
    if (!empty($p['ikinciSifatId'])) $o['ikinciSifatId'] = (string)$p['ikinciSifatId'];
    return $o;
}
